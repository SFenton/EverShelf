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
$databaseFile = (string)(
    $db->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file']
        ?? ''
);
if ($databaseFile === '') {
    throw new RuntimeException(
        'Incremental score worker requires a file-backed database'
    );
}
$backgroundLockPath = trim(
    (string)($options['background-lock'] ?? '')
);
if ($backgroundLockPath === '') {
    $backgroundLockPath =
        dirname($databaseFile) . '/.background-writer.lock';
} elseif (!str_starts_with($backgroundLockPath, '/')) {
    throw new InvalidArgumentException(
        '--background-lock must be an absolute path'
    );
}
$coordinationLockPath = trim(
    (string)($options['coordination-lock'] ?? '')
);
if ($coordinationLockPath === '') {
    $coordinationLockPath =
        dirname($databaseFile) . '/.recipe-score-coordination.lock';
} elseif (!str_starts_with($coordinationLockPath, '/')) {
    throw new InvalidArgumentException(
        '--coordination-lock must be an absolute path'
    );
}
$heartbeatPath = trim((string)($options['heartbeat'] ?? ''));
if ($heartbeatPath === '') {
    $heartbeatPath =
        dirname($databaseFile) . '/.recipe-score-worker.heartbeat';
}
$statusPath = trim((string)($options['status-file'] ?? ''));
if ($statusPath === '') {
    $statusPath =
        dirname($databaseFile) . '/.recipe-score-worker.status';
}
foreach ([$heartbeatPath, $statusPath] as $statePath) {
    if (!str_starts_with($statePath, '/')) {
        throw new InvalidArgumentException(
            'Worker state paths must be absolute'
        );
    }
}
$writeState = static function (
    string $path,
    string $value
): void {
    $temporary = $path . '.tmp.' . getmypid();
    if (
        file_put_contents(
            $temporary,
            $value . PHP_EOL,
            LOCK_EX
        ) === false
        || !rename($temporary, $path)
    ) {
        @unlink($temporary);
        throw new RuntimeException(
            'Incremental score worker state could not be written'
        );
    }
};
$writeState($heartbeatPath, (string)time());
$writeState($statusPath, '0 ' . time());

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
    $writeState($heartbeatPath, (string)time());
    $servingProductPendingCount = (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_pending_products
    ")->fetchColumn();
    $servingRecipePendingCount = (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_recipes
        WHERE lane = 'serving'
    ")->fetchColumn();
    $servingPending =
        $servingProductPendingCount + $servingRecipePendingCount > 0
        && $servingProductPendingCount
            <= ingredientOntologyV3IncrementalProductLimit()
        && $servingRecipePendingCount
            <= ingredientOntologyV3IncrementalProductLimit();
    $coordinationLock = null;
    $coordinationReady = $servingPending;
    if (!$servingPending) {
        $coordinationLock = fopen($coordinationLockPath, 'c+');
        $coordinationReady = is_resource($coordinationLock)
            && flock($coordinationLock, LOCK_EX | LOCK_NB);
    }
    if (!$servingPending && $coordinationLock === false) {
        $result = [
            'rebuilt' => false,
            'reason' => 'worker_exception',
            'error' => 'score coordination lock could not be opened',
        ];
    } elseif (!$coordinationReady) {
        fclose($coordinationLock);
        $coordinationLock = null;
        $result = [
            'rebuilt' => false,
            'reason' => 'score_coordination_locked',
            'skipped' => true,
            'retryable' => true,
        ];
    } else {
        try {
            $backgroundLock = fopen($backgroundLockPath, 'c+');
            if ($backgroundLock === false) {
                $result = [
                    'rebuilt' => false,
                    'reason' => 'worker_exception',
                    'error' =>
                        'background writer lock could not be opened',
                ];
            } elseif (!flock(
                $backgroundLock,
                LOCK_EX | LOCK_NB
            )) {
                fclose($backgroundLock);
                $backgroundLock = null;
                $result = [
                    'rebuilt' => false,
                    'reason' => 'background_writer_locked',
                    'skipped' => true,
                    'retryable' => true,
                ];
            } else {
                try {
                    $result = ingredientOntologyV3IncrementalRebuild(
                        $db,
                        $force,
                        requireServing: $servingPending
                    );
                    if (
                        (string)($result['reason'] ?? '')
                            === 'compaction_required'
                    ) {
                        $compaction =
                            ingredientOntologyV3CompactActiveScores(
                                $db,
                                true
                            );
                        $result['compaction'] = $compaction;
                        if (!empty($compaction['compacted'])) {
                            $result['reason'] = 'compacted';
                        }
                    }
                } catch (Throwable $error) {
                    $rollbackError = null;
                    try {
                        databaseRollbackDanglingTransaction($db);
                    } catch (Throwable $cleanupError) {
                        $rollbackError = $cleanupError;
                    }
                    $reason = $rollbackError !== null
                        ? 'worker_exception'
                        : (
                            databaseIsLockError($error)
                                ? 'locked'
                                : 'worker_exception'
                        );
                    $message = $error->getMessage();
                    if ($rollbackError !== null) {
                        $message .= '; connection rollback failed: '
                            . $rollbackError->getMessage();
                    }
                    $result = [
                        'rebuilt' => false,
                        'reason' => $reason,
                        'error' => mb_substr(
                            $message,
                            0,
                            1000,
                            'UTF-8'
                        ),
                    ];
                } finally {
                    flock($backgroundLock, LOCK_UN);
                    fclose($backgroundLock);
                }
            }
        } finally {
            if (is_resource($coordinationLock)) {
                flock($coordinationLock, LOCK_UN);
                fclose($coordinationLock);
            }
        }
    }
    $reason = (string)($result['reason'] ?? '');
    $failed = in_array(
        $reason,
        [
            'active_revision_missing',
            'compaction_required',
            'failed',
            'full_rebuild_required',
            'worker_exception',
        ],
        true
    );
    if ($reason === 'full_rebuild_required') {
        $result['recovery'] = [
            'strategy' => 'copied_score_refresh',
            'worker' => 'ontology-activation-worker',
            'retryable' => true,
        ];
    } elseif (in_array(
        $reason,
        [
            'background_writer_locked',
            'score_coordination_locked',
            'locked',
        ],
        true
    )) {
        $result['skipped'] = true;
        $result['retryable'] = true;
    }
    $writeState($heartbeatPath, (string)time());
    $writeState(
        $statusPath,
        ($failed ? '2 ' : '0 ') . time()
    );
    if (
        !$loop
        || !empty($result['rebuilt'])
        || $failed
        || in_array(
            $reason,
            [
                'worker_exception',
                'full_rebuild_required',
                'failed',
            ],
            true
        )
        || (
            (string)($result['reason'] ?? '') === 'locked'
            && isset($result['error'])
        )
    ) {
        $payload = ['success' => !$failed, 'cycle' => $cycle] + $result;
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
        if ($failed) {
            exit(2);
        }
        break;
    }
    if ($maxCycles > 0 && $cycle >= $maxCycles) {
        if ($failed) {
            exit(2);
        }
        break;
    }
    $delayMs = match ($reason) {
        'coalescing' => max(
            $sleepMs,
            min(5000, (int)($result['retry_after_ms'] ?? $sleepMs))
        ),
        'identity_migration_pending' => max(
            $sleepMs,
            min(5000, (int)($result['retry_after_ms'] ?? 50))
        ),
        'background_writer_locked' => max($sleepMs, 250),
        'score_coordination_locked' => max($sleepMs, 250),
        'locked' => max($sleepMs, 250),
        'full_rebuild_required',
        'active_revision_missing',
        'worker_exception',
        'failed' => max($sleepMs, 30000),
        default => $sleepMs,
    };
    usleep($delayMs * 1000);
} while ($running);
