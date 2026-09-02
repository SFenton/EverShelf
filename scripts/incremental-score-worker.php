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
$benchmarkMetrics = isset($options['benchmark-metrics']);
$benchmarkFixtureToken = trim(
    (string)($options['benchmark-fixture-token'] ?? '')
);
$GLOBALS['INGREDIENT_ONTOLOGY_V3_CORPUS_FULL_SCAN_COUNT'] = 0;
$GLOBALS['INGREDIENT_ONTOLOGY_V3_CORPUS_OPERATION_COUNTS'] = [];
if ($benchmarkMetrics) {
    $GLOBALS['INGREDIENT_ONTOLOGY_V3_TRACK_FULL_CORPUS_SCANS'] =
        true;
}
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
    if ($benchmarkFixtureToken !== '') {
        throw new InvalidArgumentException(
            'benchmark fixtures require an explicit disposable database'
        );
    }
    $db = getDB();
} else {
    $databasePath = recipeCliAssertDatabaseInputSafe(
        $databasePath,
        isset($options['allow-active-db'])
    );
    if ($benchmarkFixtureToken !== '') {
        ingredientOntologyV3IncrementalBenchmarkFixtureToken(
            $benchmarkFixtureToken
        );
        $basename = basename($databasePath);
        if (
            $basename === 'evershelf.db'
            || !preg_match(
                '/(?:benchmark|disposable|scratch|test|corpus-annex)/i',
                $basename
            )
        ) {
            throw new InvalidArgumentException(
                'benchmark fixtures require a disposable database name'
            );
        }
        $GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_BENCHMARK_FIXTURES_ENABLED'
        ] = true;
        putenv(
            'INGREDIENT_ONTOLOGY_V3_BENCHMARK_FIXTURE_TOKEN='
                . $benchmarkFixtureToken
        );
    }
    $db = new PDO('sqlite:' . $databasePath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec(
        'PRAGMA busy_timeout=' . databaseConfiguredBusyTimeoutMs()
    );
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

$rssBytes = static function (string $field): int {
    $status = @file_get_contents('/proc/self/status');
    if (
        is_string($status)
        && preg_match(
            '/^' . preg_quote($field, '/') . ':\s+([0-9]+)\s+kB$/m',
            $status,
            $match
        )
    ) {
        return (int)$match[1] * 1024;
    }
    return $field === 'VmHWM'
        ? memory_get_peak_usage(true)
        : memory_get_usage(true);
};
$initialWorkerRssBytes = $rssBytes('VmRSS');

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
    $servingBypass =
        $servingPending && $cycle % 8 !== 0;
    $coordinationLock = null;
    $coordinationReady = $servingBypass;
    if (!$servingBypass) {
        $coordinationLock = fopen($coordinationLockPath, 'c+');
        $coordinationReady = is_resource($coordinationLock)
            && flock($coordinationLock, LOCK_EX | LOCK_NB);
    }
    if (!$servingBypass && $coordinationLock === false) {
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
                    $reconciliationBackfill = function_exists(
                        'ingredientOntologyV3CorpusAnnexReconciliationBackfill'
                    )
                        ? ingredientOntologyV3CorpusAnnexReconciliationBackfill(
                            $db,
                            5000
                        )
                        : ['complete' => true, 'processed' => 0];
                    $result = empty($reconciliationBackfill['complete'])
                        ? [
                            'rebuilt' => false,
                            'reason' =>
                                'reconciliation_backfill_pending',
                            'retryable' => true,
                        ]
                        : ingredientOntologyV3IncrementalRebuild(
                            $db,
                            $force,
                            requireServing: $servingBypass
                        );
                    if (
                        (int)($reconciliationBackfill['processed'] ?? 0)
                            > 0
                        || empty($reconciliationBackfill['complete'])
                    ) {
                        $result['reconciliation_backfill'] =
                            $reconciliationBackfill;
                    }
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
                    if (
                        function_exists(
                            'ingredientOntologyV3CorpusProjectionV2Compact'
                        )
                        && !in_array(
                            (string)($result['reason'] ?? ''),
                            [
                                'corpus_annex_repair_required',
                                'score_projection_repair_required',
                                'semantic_generation_transition_required',
                                'reconciliation_backfill_pending',
                                'worker_exception',
                                'failed',
                            ],
                            true
                        )
                    ) {
                        $projectionCompaction =
                            ingredientOntologyV3CorpusProjectionV2Compact(
                                $db
                            );
                        if (!empty(
                            $projectionCompaction['compacted']
                        )) {
                            $result['projection_compaction'] =
                                $projectionCompaction;
                            if (empty($result['rebuilt'])) {
                                $result['reason'] =
                                    'projection_compacted';
                            }
                        } elseif (
                            (string)(
                                $projectionCompaction['reason'] ?? ''
                            ) === 'failed'
                        ) {
                            $result['projection_compaction_warning'] =
                                (string)(
                                    $projectionCompaction['error']
                                        ?? 'projection compaction failed'
                                );
                        }
                    }
                    if (function_exists(
                        'ingredientOntologyV3CorpusAnnexCleanupNonReady'
                    )) {
                        $annexCleanup =
                            ingredientOntologyV3CorpusAnnexCleanupNonReady(
                                $db
                            );
                        if ($annexCleanup['deleted_revision_ids']) {
                            $result['projection_cleanup'] =
                                $annexCleanup;
                        }
                    }
                    if (function_exists(
                        'ingredientOntologyV3CorpusAnnexReconciliationGc'
                    )) {
                        $reconciliationGc =
                            ingredientOntologyV3CorpusAnnexReconciliationGc(
                                $db
                            );
                        if (
                            (int)$reconciliationGc[
                                'deleted_event_count'
                            ] > 0
                            || (int)$reconciliationGc[
                                'deleted_scope_count'
                            ] > 0
                        ) {
                            $result['reconciliation_gc'] =
                                $reconciliationGc;
                        }
                    }
                    if (function_exists(
                        'ingredientOntologyV3CorpusProjectionV2RefreshStatus'
                    )) {
                        $result['projection_status'] =
                            ingredientOntologyV3CorpusProjectionV2RefreshStatus(
                                $db,
                                (string)($result['error'] ?? '')
                            );
                    }
                    if (function_exists(
                        'evershelfProcessingStatusRefreshMaterialized'
                    )
                        && (
                            (string)($result['reason'] ?? '')
                                === 'no_pending_changes'
                            || (
                                empty($result['projection_status'][
                                    'pending_suffix'
                                ])
                                && (int)($result[
                                    'pending_product_count'
                                ] ?? 0) === 0
                                && (int)($result[
                                    'pending_recipe_count'
                                ] ?? 0) === 0
                                && (int)($result[
                                    'pending_identity_recipe_count'
                                ] ?? 0) === 0
                            )
                        )
                    ) {
                        $materializedStatus =
                            evershelfProcessingStatusRefreshMaterialized(
                                $db
                            );
                        if (!empty($materializedStatus['refreshed'])) {
                            $result['processing_status_refresh'] =
                                $materializedStatus;
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
                    if (function_exists(
                        'ingredientOntologyV3CorpusProjectionV2RefreshStatus'
                    )) {
                        try {
                            $result['projection_status'] =
                                ingredientOntologyV3CorpusProjectionV2RefreshStatus(
                                    $db,
                                    (string)$result['error']
                                );
                        } catch (Throwable $statusError) {
                            $result['projection_status_error'] =
                                mb_substr(
                                    $statusError->getMessage(),
                                    0,
                                    300,
                                    'UTF-8'
                                );
                        }
                    }
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
            'corpus_annex_repair_required',
            'score_projection_repair_required',
            'semantic_generation_transition_required',
            'failed',
            'worker_exception',
        ],
        true
    );
    if ($reason === 'corpus_annex_repair_required') {
        $result['recovery'] = [
            'strategy' => 'staged_candidate_generation',
            'worker' => 'ontology-activation-worker',
            'retryable' => true,
        ];
    } elseif ($reason === 'score_projection_repair_required') {
        $result['recovery'] = [
            'strategy' => 'copied_score_refresh',
            'worker' => 'ontology-activation-worker',
            'retryable' => true,
        ];
    } elseif (
        $reason === 'semantic_generation_transition_required'
    ) {
        $result['recovery'] = [
            'strategy' => 'staged_candidate_generation',
            'worker' => 'ontology-activation-worker',
            'retryable' => true,
        ];
    } elseif (in_array(
        $reason,
        [
            'background_writer_locked',
            'score_coordination_locked',
            'locked',
            'superseded_snapshot',
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
                'corpus_annex_repair_required',
                'score_projection_repair_required',
                'semantic_generation_transition_required',
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
        if ($benchmarkMetrics) {
            $payload['benchmark_metrics'] = [
                'full_corpus_scans' => (int)($GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_CORPUS_FULL_SCAN_COUNT'
                ] ?? 0),
                'corpus_operation_counts' => (array)($GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_CORPUS_OPERATION_COUNTS'
                ] ?? []),
                'initial_rss_bytes' => $initialWorkerRssBytes,
                'peak_rss_bytes' => $rssBytes('VmHWM'),
                'peak_php_memory_bytes' =>
                    memory_get_peak_usage(true),
            ];
        }
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
        'score_date_refresh_required' => max(
            $sleepMs,
            min(
                30000,
                (int)($result['retry_after_ms'] ?? 30000)
            )
        ),
        'background_writer_locked' => max($sleepMs, 250),
        'score_coordination_locked' => max($sleepMs, 250),
        'locked' => max($sleepMs, 250),
        'corpus_annex_repair_required',
        'score_projection_repair_required',
        'semantic_generation_transition_required',
        'active_revision_missing',
        'worker_exception',
        'failed' => max($sleepMs, 30000),
        default => $sleepMs,
    };
    usleep($delayMs * 1000);
} while ($running);
