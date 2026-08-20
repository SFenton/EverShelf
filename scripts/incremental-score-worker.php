#!/usr/bin/env php
<?php
declare(strict_types=1);

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
$json = isset($options['json']);
$force = isset($options['force']);
$sleepMs = max(
    50,
    min(5000, (int)($options['sleep-ms'] ?? 200))
);
$maxCycles = max(
    0,
    min(1000000, (int)($options['max-cycles'] ?? 0))
);
$databasePath = trim((string)($options['db'] ?? ''));
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
    databaseEnsureMigrated(
        $db,
        $databasePath . '.migration.lock'
    );
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

$cycle = 0;
do {
    $cycle++;
    try {
        $result = ingredientOntologyV3IncrementalRebuild(
            $db,
            $force
        );
    } catch (Throwable $error) {
        $result = [
            'rebuilt' => false,
            'reason' => 'worker_exception',
            'error' => mb_substr(
                $error->getMessage(),
                0,
                1000,
                'UTF-8'
            ),
        ];
    }
    if (
        (string)($result['reason'] ?? '')
            === 'compaction_required'
    ) {
        $compaction = ingredientOntologyV3CompactActiveScores(
            $db,
            true
        );
        $result['compaction'] = $compaction;
        if (!empty($compaction['compacted'])) {
            $result['reason'] = 'compacted';
        }
    }
    if (
        !$loop
        || !empty($result['rebuilt'])
        || in_array(
            (string)($result['reason'] ?? ''),
            [
                'worker_exception',
                'full_rebuild_required',
                'failed',
            ],
            true
        )
    ) {
        $payload = ['success' => true, 'cycle' => $cycle] + $result;
        echo $json
            ? json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRETTY_PRINT
            ) . PHP_EOL
            : '[' . date('Y-m-d H:i:s') . '] '
                . json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                ) . PHP_EOL;
    }
    if (!$loop || !$running) {
        if ((string)($result['reason'] ?? '') === 'worker_exception') {
            exit(2);
        }
        break;
    }
    if ($maxCycles > 0 && $cycle >= $maxCycles) {
        break;
    }
    $reason = (string)($result['reason'] ?? '');
    $delayMs = match ($reason) {
        'coalescing' => max(
            $sleepMs,
            min(5000, (int)($result['retry_after_ms'] ?? $sleepMs))
        ),
        'locked' => max($sleepMs, 1000),
        'full_rebuild_required',
        'active_revision_missing',
        'worker_exception',
        'failed' => max($sleepMs, 30000),
        default => $sleepMs,
    };
    usleep($delayMs * 1000);
} while ($running);
