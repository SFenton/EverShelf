#!/usr/bin/env php
<?php
declare(strict_types=1);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (!is_dir($path) || is_link($path)) {
        unlink($path);
        return;
    }
    $entries = scandir($path);
    if ($entries === false) {
        throw new RuntimeException("Could not inspect {$path}");
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $removeTree($path . '/' . $entry);
    }
    rmdir($path);
};

$copyTree = static function (
    string $source,
    string $destination
) use (&$copyTree): void {
    if (!mkdir($destination, 0770, true) && !is_dir($destination)) {
        throw new RuntimeException("Could not create {$destination}");
    }
    $entries = scandir($source);
    if ($entries === false) {
        throw new RuntimeException("Could not inspect {$source}");
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $from = $source . '/' . $entry;
        $to = $destination . '/' . $entry;
        if (is_dir($from)) {
            $copyTree($from, $to);
        } elseif (!copy($from, $to)) {
            throw new RuntimeException("Could not copy {$from}");
        }
    }
};

$request = static function (string $url, array $headers = []): array {
    $headerLines = array_merge(['Connection: close'], $headers);
    $context = stream_context_create([
        'http' => [
            'ignore_errors' => true,
            'timeout' => 2,
            'header' => implode("\r\n", $headerLines),
        ],
    ]);
    $http_response_header = [];
    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    if ($body === false && $responseHeaders === []) {
        throw new RuntimeException("Request failed: {$url}");
    }
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $match)) {
            $status = (int)$match[1];
            break;
        }
    }
    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(
            "Response was not JSON for {$url}: " . (string)$body
        );
    }
    return [
        'status' => $status,
        'body' => $decoded,
        'headers' => $responseHeaders,
    ];
};

$allocatePort = static function (): int {
    $socket = stream_socket_server(
        'tcp://127.0.0.1:0',
        $socketError,
        $socketErrorMessage
    );
    if ($socket === false) {
        throw new RuntimeException(
            "Could not allocate test port: {$socketErrorMessage}"
        );
    }
    $socketName = stream_socket_get_name($socket, false);
    fclose($socket);
    if ($socketName === false
        || !preg_match('/:(\d+)$/', $socketName, $match)) {
        throw new RuntimeException('Could not determine test port');
    }
    return (int)$match[1];
};

$root = sys_get_temp_dir()
    . '/evershelf-startup-health-'
    . getmypid()
    . '-'
    . bin2hex(random_bytes(4));
$process = null;
$pipes = [];

try {
    if (!mkdir($root, 0770, true)) {
        throw new RuntimeException("Could not create {$root}");
    }
    $copyTree(__DIR__ . '/../api', $root . '/api');
    file_put_contents(
        $root . '/.build-revision',
        '0123456789abcdef0123456789abcdef01234567'
    );
    if (!mkdir($root . '/data/backups', 0770, true)) {
        throw new RuntimeException('Could not create fixture data directory');
    }
    file_put_contents(
        $root . '/.env',
        "API_TOKEN=startup-health-test-token\n"
        . "GEMINI_API_KEY=\n"
        . "SHOPPING_MODE=internal\n"
    );

    $indexPath = $root . '/api/index.php';
    $indexSource = file_get_contents($indexPath);
    if ($indexSource === false) {
        throw new RuntimeException('Could not read copied API router');
    }
    $quickCheckLine =
        '                $integ = $pdo->query("PRAGMA quick_check")->fetchColumn();';
    $instrumentedLine =
        "                file_put_contents(__DIR__ . '/../data/quick-check-ran', '1');\n"
        . $quickCheckLine;
    $instrumentedSource = str_replace(
        $quickCheckLine,
        $instrumentedLine,
        $indexSource,
        $replacementCount
    );
    $assert(
        $replacementCount === 1,
        'The health test must instrument exactly one full integrity query'
    );
    file_put_contents($indexPath, $instrumentedSource);

    $dbPath = $root . '/data/evershelf.db';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('CREATE TABLE inventory (quantity REAL NOT NULL)');
    $db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY)');
    $db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY)');
    $db->exec('INSERT INTO inventory (quantity) VALUES (1)');
    $db = null;

    $ready = false;
    $serverErrors = [];
    $baseUrl = '';
    for ($attempt = 1; $attempt <= 3 && !$ready; $attempt++) {
        $port = $allocatePort();
        $attemptPipes = [];
        $attemptProcess = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $attemptPipes,
            $root
        );
        if (!is_resource($attemptProcess)) {
            $serverErrors[] = "attempt {$attempt}: proc_open failed";
            continue;
        }
        fclose($attemptPipes[0]);
        stream_set_blocking($attemptPipes[1], false);
        stream_set_blocking($attemptPipes[2], false);

        $attemptBaseUrl = "http://127.0.0.1:{$port}/api/index.php";
        $readyDeadline = microtime(true) + 5;
        do {
            try {
                $ping = $request($attemptBaseUrl . '?action=ping');
                $ready = $ping['status'] === 200
                    && ($ping['body']['ok'] ?? false) === true;
            } catch (Throwable) {
                $ready = false;
            }
            if (!$ready) {
                usleep(50000);
            }
        } while (!$ready && microtime(true) < $readyDeadline);

        if ($ready) {
            $process = $attemptProcess;
            $pipes = $attemptPipes;
            $baseUrl = $attemptBaseUrl;
            break;
        }

        $serverErrors[] = "attempt {$attempt}: "
            . trim(stream_get_contents($attemptPipes[2]));
        proc_terminate($attemptProcess);
        foreach ($attemptPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($attemptProcess);
    }
    if (!$ready) {
        throw new RuntimeException(
            'PHP test server did not become ready: '
            . implode(' | ', $serverErrors)
        );
    }

    $auth = ['X-API-Token: startup-health-test-token'];
    $healthUrl = $baseUrl . '?action=health_check';
    $markerPath = $root . '/data/quick-check-ran';
    $startupCriticalKeys = [
        'php_version',
        'ext_pdo_sqlite',
        'ext_curl',
        'ext_json',
        'ext_mbstring',
        'data_dir',
        'data_rate_limits',
        'data_write_test',
        'db_connect',
        'db_tables',
        'db_writable',
    ];

    $public = $request($healthUrl . '&scope=startup');
    $assert(
        $public['status'] === 200
        && ($public['body']['public'] ?? false) === true
        && ($public['body']['api_token_required'] ?? false) === true
        && !array_key_exists('scope', $public['body'])
        && !array_key_exists('checks', $public['body']),
        'Unauthenticated health must preserve the minimal public contract'
    );
    $assert(
        array_reduce(
            $public['headers'],
            static fn(bool $found, string $header): bool =>
                $found
                || (stripos($header, 'Cache-Control:') === 0
                    && stripos($header, 'no-store') !== false),
            false
        ),
        'Health responses must not be cached across authentication states'
    );
    $publicInvalid = $request($healthUrl . '&scope=typo');
    $assert(
        $publicInvalid['status'] === 200
        && ($publicInvalid['body']['public'] ?? false) === true
        && !array_key_exists('error', $publicInvalid['body']),
        'Scope validation must not change unauthenticated public health'
    );

    @unlink($markerPath);
    $startup = $request($healthUrl . '&scope=startup', $auth);
    $assert(
        $startup['status'] === 200
        && ($startup['body']['ok'] ?? false) === true
        && ($startup['body']['scope'] ?? null) === 'startup'
        && ($startup['body']['build_revision'] ?? null)
            === '0123456789abcdef0123456789abcdef01234567'
        && ($startup['body']['skipped_checks'] ?? null)
            === ['db_integrity']
        && !array_key_exists(
            'db_integrity',
            $startup['body']['checks'] ?? []
        )
        && !file_exists($markerPath),
        'Startup scope must omit and never execute database integrity'
    );
    $startupChecks = $startup['body']['checks'] ?? [];
    $assert(
        array_reduce(
            $startupCriticalKeys,
            static fn(bool $valid, string $key): bool =>
                $valid
                && isset($startupChecks[$key])
                && is_array($startupChecks[$key])
                && is_bool($startupChecks[$key]['ok'] ?? null)
                && (($startupChecks[$key]['optional'] ?? false) !== true),
            true
        ),
        'Startup scope must emit every immutable critical check'
    );
    $computedStartupOk = array_reduce(
        $startupCriticalKeys,
        static fn(bool $ok, string $key): bool =>
            $ok && (($startupChecks[$key]['ok'] ?? false) === true),
        true
    );
    $assert(
        ($startup['body']['ok'] ?? null) === $computedStartupOk,
        'Startup aggregate must equal the critical check results'
    );
    $assert(
        !isset($startupChecks['db_tables']['missing'])
        || (is_array($startupChecks['db_tables']['missing'])
            && array_reduce(
                $startupChecks['db_tables']['missing'],
                static fn(bool $valid, mixed $item): bool =>
                    $valid && is_string($item),
                true
            )),
        'Database missing-table details must be an array of strings'
    );

    $rateLimitPath = $root . '/data/rate_limits';
    $removeTree($rateLimitPath);
    if (file_put_contents($rateLimitPath, 'blocked') === false) {
        throw new RuntimeException(
            'Could not create blocked rate-limit fixture'
        );
    }
    $blockedRateLimits = $request(
        $healthUrl . '&scope=startup',
        $auth
    );
    $assert(
        ($blockedRateLimits['body']['ok'] ?? true) === false
        && (
            $blockedRateLimits['body']['checks']['data_rate_limits']['ok']
            ?? true
        ) === false
        && (
            $blockedRateLimits['body']['checks']['data_rate_limits']['optional']
            ?? false
        ) !== true,
        'Unavailable rate-limit storage must fail startup aggregation'
    );
    unlink($rateLimitPath);
    if (!mkdir($rateLimitPath, 0770, true)) {
        throw new RuntimeException(
            'Could not restore rate-limit fixture directory'
        );
    }

    $originalDbMode = fileperms($dbPath);
    if ($originalDbMode === false || !chmod($dbPath, 0440)) {
        throw new RuntimeException('Could not make fixture database read-only');
    }
    clearstatcache(true, $dbPath);
    $readOnlyStartup = $request($healthUrl . '&scope=startup', $auth);
    $assert(
        ($readOnlyStartup['body']['ok'] ?? true) === false
        && ($readOnlyStartup['body']['checks']['db_writable']['ok'] ?? true)
            === false,
        'A read-only database must fail startup aggregation'
    );
    if (!chmod($dbPath, $originalDbMode & 0777)) {
        throw new RuntimeException('Could not restore fixture database mode');
    }
    clearstatcache(true, $dbPath);

    @unlink($markerPath);
    $full = $request($healthUrl . '&scope=full', $auth);
    $assert(
        $full['status'] === 200
        && ($full['body']['ok'] ?? false) === true
        && ($full['body']['scope'] ?? null) === 'full'
        && ($full['body']['skipped_checks'] ?? null) === []
        && ($full['body']['checks']['db_integrity']['ok'] ?? false) === true
        && file_exists($markerPath),
        'Full scope must retain and execute database integrity'
    );

    @unlink($markerPath);
    $default = $request($healthUrl, $auth);
    $assert(
        $default['status'] === 200
        && ($default['body']['scope'] ?? null) === 'full'
        && ($default['body']['checks']['db_integrity']['ok'] ?? false)
            === true
        && file_exists($markerPath),
        'No scope must preserve full diagnostic behavior'
    );

    @unlink($markerPath);
    $invalid = $request($healthUrl . '&scope=typo', $auth);
    $assert(
        $invalid['status'] === 400
        && ($invalid['body']['ok'] ?? true) === false
        && ($invalid['body']['error'] ?? null) === 'invalid_scope'
        && !file_exists($markerPath),
        'Invalid authenticated scopes must fail without running diagnostics'
    );
    $invalidShape = $request(
        $healthUrl . '&scope%5B%5D=startup',
        $auth
    );
    $assert(
        $invalidShape['status'] === 400
        && ($invalidShape['body']['error'] ?? null) === 'invalid_scope',
        'Non-string authenticated scopes must be rejected cleanly'
    );

    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }
    $freshStartup = $request($healthUrl . '&scope=startup', $auth);
    $assert(
        ($freshStartup['body']['fresh'] ?? false) === true
        && ($freshStartup['body']['checks']['db_writable']['ok'] ?? false)
            === true
        && ($freshStartup['body']['checks']['db_writable']['fresh'] ?? false)
            === true
        && !array_key_exists(
            'db_integrity',
            $freshStartup['body']['checks'] ?? []
        ),
        'Fresh startup scope must not claim database integrity ran'
    );
    $freshFull = $request($healthUrl . '&scope=full', $auth);
    $assert(
        ($freshFull['body']['fresh'] ?? false) === true
        && ($freshFull['body']['checks']['db_integrity']['fresh'] ?? false)
            === true,
        'Fresh full scope must preserve the integrity placeholder'
    );

    if (!mkdir($dbPath, 0770)) {
        throw new RuntimeException('Could not create connection failure fixture');
    }
    $failedStartup = $request($healthUrl . '&scope=startup', $auth);
    $assert(
        ($failedStartup['body']['ok'] ?? true) === false
        && ($failedStartup['body']['checks']['db_connect']['ok'] ?? true)
            === false
        && ($failedStartup['body']['checks']['db_writable']['ok'] ?? true)
            === false
        && !array_key_exists(
            'db_integrity',
            $failedStartup['body']['checks'] ?? []
        ),
        'Connection-failed startup scope must not claim integrity was checked'
    );
    $failedFull = $request($healthUrl . '&scope=full', $auth);
    $assert(
        ($failedFull['body']['ok'] ?? true) === false
        && ($failedFull['body']['checks']['db_integrity']['ok'] ?? true)
            === false,
        'Connection-failed full scope must preserve integrity failure'
    );
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($process)) {
        proc_close($process);
    }
    $removeTree($root);
}

echo "Startup health tests passed: {$assertions} assertions\n";
