#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        continue;
    }
    $parts = explode('=', substr($argument, 2), 2);
    $options[$parts[0]] = $parts[1] ?? true;
}

$loop = isset($options['loop']);
$pollSeconds = max(
    5,
    min(
        300,
        (int)($options['poll-seconds'] ?? env(
            'CANONICAL_QUEUE_SAFETY_POLL_SECONDS',
            '30'
        ))
    )
);
$limit = max(
    1,
    min(
        50,
        (int)($options['limit'] ?? env(
            'CANONICAL_QUEUE_WORKER_LIMIT',
            '5'
        ))
    )
);
$maxAttempts = max(
    1,
    min(
        20,
        (int)($options['max-attempts'] ?? env(
            'CANONICAL_QUEUE_MAX_ATTEMPTS',
            '3'
        ))
    )
);
$maximumBatches = max(
    1,
    min(
        1000,
        (int)($options['max-batches'] ?? env(
            'CANONICAL_QUEUE_WORKER_MAX_BATCHES',
            '100'
        ))
    )
);
$databasePath = trim((string)($options['db'] ?? ''));
$socketPath = trim((string)(
    $options['socket'] ?? canonicalIngredientWakeSocketPath()
));
if (
    $socketPath === ''
    || strlen($socketPath) > 220
    || str_contains($socketPath, "\0")
) {
    throw new InvalidArgumentException(
        'canonical queue wake socket path is invalid'
    );
}
$directory = dirname($socketPath);
if (
    !is_dir($directory)
    && !mkdir($directory, 0775, true)
    && !is_dir($directory)
) {
    throw new RuntimeException(
        'canonical queue wake socket directory is unavailable'
    );
}

$workerLock = fopen(
    $directory . '/.canonical-queue-worker.lock',
    'c+'
);
if (
    $workerLock === false
    || !flock($workerLock, LOCK_EX | LOCK_NB)
) {
    if (is_resource($workerLock)) {
        fclose($workerLock);
    }
    echo json_encode([
        'success' => true,
        'skipped' => true,
        'reason' => 'canonical_worker_already_running',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if (file_exists($socketPath) || is_link($socketPath)) {
    @unlink($socketPath);
}
$errno = 0;
$error = '';
$wakeSocket = @stream_socket_server(
    'udg://' . $socketPath,
    $errno,
    $error,
    STREAM_SERVER_BIND
);
if (is_resource($wakeSocket)) {
    stream_set_blocking($wakeSocket, false);
}

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

if ($databasePath === '') {
    $db = getDB();
} else {
    $databasePath = recipeCliAssertDatabaseInputSafe(
        $databasePath,
        isset($options['allow-active-db'])
    );
    $db = new PDO('sqlite:' . $databasePath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA busy_timeout=10000');
    migrateDB($db);
}
$cycle = 0;
$lockUnavailableStreak = 0;
try {
    do {
        $cycle++;
        $result = [];
        try {
            $result = canonicalIngredientDrainQueue(
                $db,
                $limit,
                $maxAttempts,
                $maximumBatches
            );
            if (
                !$loop
                || (int)($result['processed'] ?? 0) > 0
                || (int)($result['failed'] ?? 0) > 0
            ) {
                echo json_encode([
                    'success' => true,
                    'cycle' => $cycle,
                    'poll_seconds' => $pollSeconds,
                ] + $result, JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            }
        } catch (Throwable $workerError) {
            EverLog::exception(
                $workerError,
                'canonical_queue_worker'
            );
            echo json_encode([
                'success' => false,
                'cycle' => $cycle,
                'error' => mb_substr(
                    $workerError->getMessage(),
                    0,
                    500,
                    'UTF-8'
                ),
            ], JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }
        if (!$loop || !$running) {
            break;
        }
        if (
            (string)($result['skipped'] ?? '')
            === 'lock_unavailable'
        ) {
            $lockUnavailableStreak++;
        } else {
            $lockUnavailableStreak = 0;
        }
        if (
            (int)($result['processed'] ?? 0) > 0
            && (int)($result['due'] ?? 0) > 0
        ) {
            continue;
        }
        $sleepSeconds = canonicalIngredientWorkerSleepDelay(
            $db,
            $pollSeconds,
            $maxAttempts,
            $result,
            $lockUnavailableStreak
        );
        if (!is_resource($wakeSocket)) {
            sleep($sleepSeconds);
            continue;
        }
        $read = [$wakeSocket];
        $write = null;
        $except = null;
        $ready = @stream_select(
            $read,
            $write,
            $except,
            $sleepSeconds
        );
        if ($ready === false || $ready === 0) {
            continue;
        }
        while (@stream_socket_recvfrom($wakeSocket, 1024) !== false) {
        }
    } while ($running);
} finally {
    if (is_resource($wakeSocket)) {
        fclose($wakeSocket);
    }
    if (file_exists($socketPath) || is_link($socketPath)) {
        @unlink($socketPath);
    }
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
}
