#!/usr/bin/env php
<?php
declare(strict_types=1);

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        continue;
    }
    $parts = explode('=', substr($argument, 2), 2);
    $options[$parts[0]] = $parts[1] ?? true;
}

$needle = trim((string)($options['needle'] ?? ''));
$databasePath = trim((string)($options['db'] ?? ''));
$heartbeatPath = trim((string)($options['heartbeat'] ?? ''));
$statusPath = trim((string)($options['status-file'] ?? ''));
$checkActivationState = isset($options['activation-state']);
$maximumAge = max(
    30,
    min(3600, (int)($options['max-age'] ?? 900))
);
if (
    $needle === ''
    || $databasePath === ''
    || !str_starts_with($databasePath, '/')
) {
    fwrite(STDERR, "Worker health arguments are invalid\n");
    exit(2);
}

$processFound = false;
foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
    if ($path === '/proc/' . getmypid() . '/cmdline') {
        continue;
    }
    $command = @file_get_contents($path);
    if (
        is_string($command)
        && str_contains(str_replace("\0", ' ', $command), $needle)
    ) {
        $processFound = true;
        break;
    }
}
if (!$processFound) {
    fwrite(STDERR, "Expected worker process is not running\n");
    exit(1);
}

if (!is_file($databasePath) || !is_readable($databasePath)) {
    fwrite(STDERR, "Worker database is unavailable\n");
    exit(1);
}
try {
    $db = new PDO('sqlite:' . $databasePath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout=1000');
    $db->query('PRAGMA user_version')->fetchColumn();
    if ($checkActivationState) {
        $table = $db->query("
            SELECT 1
            FROM sqlite_master
            WHERE type = 'table'
              AND name = 'ontology_activation_state'
        ")->fetchColumn();
        if ($table) {
            $activation = $db->query("
                SELECT failure_count, last_error
                FROM ontology_activation_state
                WHERE id = 1
            ")->fetch(PDO::FETCH_ASSOC) ?: [];
            if (
                (int)($activation['failure_count'] ?? 0) > 0
                && trim((string)($activation['last_error'] ?? ''))
                    !== ''
            ) {
                fwrite(
                    STDERR,
                    "Ontology activation has an unresolved failure\n"
                );
                exit(1);
            }
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Worker database probe failed\n");
    exit(1);
}

if ($heartbeatPath !== '') {
    $heartbeat = is_file($heartbeatPath)
        ? trim((string)@file_get_contents($heartbeatPath))
        : '';
    if (!ctype_digit($heartbeat)) {
        fwrite(STDERR, "Worker heartbeat is unavailable\n");
        exit(1);
    }
    $age = time() - (int)$heartbeat;
    if ($age < -60 || $age > $maximumAge) {
        fwrite(STDERR, "Worker heartbeat is stale\n");
        exit(1);
    }
}
if ($statusPath !== '') {
    $status = is_file($statusPath)
        ? trim((string)@file_get_contents($statusPath))
        : '';
    if (
        !preg_match(
            '/^(?<code>[0-9]{1,3}) (?<timestamp>[0-9]{1,12})$/D',
            $status,
            $matches
        )
        || (int)$matches['code'] !== 0
    ) {
        fwrite(STDERR, "Worker last cycle failed\n");
        exit(1);
    }
}

exit(0);
