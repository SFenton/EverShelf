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
if (isset($options['help'])) {
    echo "Usage: php scripts/process-ontology-activation.php "
        . "--write [--force] [--allow-network] [--max-cycles=N] "
        . "[--maximum-chunks=N] "
        . "[--db=/absolute/evershelf.db] [--json]\n";
    exit(0);
}
if (!isset($options['write'])) {
    throw new InvalidArgumentException(
        'ontology activation processing requires --write'
    );
}

$databasePath = trim((string)($options['db'] ?? ''));
if ($databasePath === '') {
    $db = getDB();
} else {
    $databasePath = recipeCliAssertDatabaseInputSafe(
        $databasePath,
        isset($options['allow-active-db'])
    );
    $db = ingredientOntologyActivationOpenDatabase($databasePath);
}
ingredientOntologyActivationConfigureDatabase($db);
ingredientOntologyActivationAssertActiveDatabase($db);

$databaseDirectory =
    dirname(ingredientOntologyActivationDatabasePath($db));
$lockPath = $databaseDirectory . '/.ontology-activation.lock';
$lock = fopen($lockPath, 'c+');
if ($lock === false) {
    throw new RuntimeException(
        'ontology activation process lock could not be opened'
    );
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    echo json_encode([
        'success' => true,
        'skipped' => true,
        'outcome' => 'locked',
        'reason' => 'ontology_activation_locked',
        'lock_scope' => 'ontology_activation_process',
        'retryable' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$backgroundLockPath = $databaseDirectory . '/.background-writer.lock';
$backgroundLock = fopen($backgroundLockPath, 'c+');
if ($backgroundLock === false) {
    flock($lock, LOCK_UN);
    fclose($lock);
    throw new RuntimeException(
        'ontology activation background writer lock could not be opened'
    );
}
$liveReservation = static fn(
    string $phase,
    callable $operation
): mixed => ingredientOntologyActivationWithNonBlockingFileLock(
    $backgroundLock,
    $phase,
    $operation
);

if (
    !ingredientOntologyActivationEnabled()
    && !isset($options['force'])
) {
    $active = recipeScoreActiveRevision($db);
    $v3Active = $active !== null
        && $active['ontology_version_id'] !== null;
    $maintenance = [
        'work_cleanup' =>
            ingredientOntologyActivationCleanupWorkFiles($db),
    ];
    try {
        $maintenance['cdc_pruned'] = $liveReservation(
            'cdc_prune',
            static fn(): int =>
                ingredientOntologyActivationPruneCdc($db)
        );
    } catch (
        IngredientOntologyActivationReservationUnavailable $error
    ) {
        $maintenance['cdc_pruned'] = 0;
        $maintenance['outcome'] = 'locked';
        $maintenance['lock_scope'] = 'background_writer';
        $maintenance['lock_phase'] = $error->phase();
        $maintenance['retryable'] = true;
    }
    $result = [
        'success' => !$v3Active,
        'skipped' => true,
        'outcome' => 'disabled',
        'reason' => $v3Active
            ? 'ontology_activation_required_but_disabled'
            : 'ontology_activation_disabled',
        'maintenance' => $maintenance,
    ];
    flock($lock, LOCK_UN);
    fclose($backgroundLock);
    fclose($lock);
    echo json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit($v3Active ? 2 : 0);
}

$maximumCycles = max(
    1,
    min(100, (int)($options['max-cycles'] ?? 1))
);
$resultFailed = static function (array $result): bool {
    if (in_array(
        (string)($result['action'] ?? ''),
        ['failed', 'non_converging_expected_outcome'],
        true
    )) {
        return true;
    }
    foreach (
        ['import', 'score_import', 'ontology_import']
        as $field
    ) {
        if (
            is_array($result[$field] ?? null)
            && (string)($result[$field]['status'] ?? '') === 'failed'
        ) {
            return true;
        }
    }
    return false;
};
$results = [];
$state = $db->query("
    SELECT failure_count, next_attempt_at
    FROM ontology_activation_state
    WHERE id = 1
")->fetch(PDO::FETCH_ASSOC) ?: [];
if (
    !isset($options['force'])
    && $state['next_attempt_at'] !== null
    && strtotime((string)$state['next_attempt_at']) > time()
) {
    flock($lock, LOCK_UN);
    fclose($backgroundLock);
    fclose($lock);
    echo json_encode([
        'success' => true,
        'skipped' => true,
        'outcome' => 'backoff',
        'reason' => 'ontology_activation_backoff',
        'next_attempt_at' => $state['next_attempt_at'],
        'retryable' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
$servingProductPending = (int)$db->query("
    SELECT COUNT(*) FROM recipe_score_pending_products
")->fetchColumn();
$servingRecipePending = (int)$db->query("
    SELECT COUNT(*)
    FROM recipe_score_pending_recipes
    WHERE lane = 'serving'
")->fetchColumn();
if ($servingProductPending + $servingRecipePending > 0) {
    flock($lock, LOCK_UN);
    fclose($backgroundLock);
    fclose($lock);
    echo json_encode([
        'success' => true,
        'skipped' => true,
        'outcome' => 'incremental_score_pending',
        'reason' => 'serving_score_pending',
        'pending_product_count' => $servingProductPending,
        'pending_recipe_count' => $servingRecipePending,
        'retryable' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
$coordinationLockPath =
    $databaseDirectory . '/.recipe-score-coordination.lock';
$coordinationLock = fopen($coordinationLockPath, 'c+');
if ($coordinationLock === false) {
    flock($lock, LOCK_UN);
    fclose($backgroundLock);
    fclose($lock);
    throw new RuntimeException(
        'ontology activation score coordination lock could not be opened'
    );
}
if (!flock($coordinationLock, LOCK_EX | LOCK_NB)) {
    flock($lock, LOCK_UN);
    fclose($coordinationLock);
    fclose($backgroundLock);
    fclose($lock);
    echo json_encode([
        'success' => true,
        'skipped' => true,
        'outcome' => 'locked',
        'reason' => 'score_coordination_locked',
        'lock_scope' => 'score_coordination',
        'retryable' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
try {
    for ($cycle = 0; $cycle < $maximumCycles; $cycle++) {
        $result = ingredientOntologyActivationRunOnce(
            $db,
            [
                'allow_network' => isset($options['allow-network']),
                'maximum_loops' => max(
                    1,
                    min(1000, (int)($options['maximum-loops'] ?? 100))
                ),
                'maximum_chunks' => max(
                    1,
                    min(10000, (int)($options['maximum-chunks'] ?? 64))
                ),
                'maximum_import_ms' => max(
                    100,
                    min(
                        10000,
                        (int)($options['maximum-import-ms'] ?? 1500)
                    )
                ),
                'intent_limit' => max(
                    1,
                    min(50, (int)($options['intent-limit'] ?? 50))
                ),
                'batch_size' => max(
                    1,
                    min(1000, (int)($options['batch'] ?? 250))
                ),
                'live_reservation' => $liveReservation,
                'yield_after_live_reservation' => true,
            ]
        );
        $results[] = ['cycle' => $cycle + 1] + $result;
        if ($resultFailed($result)) {
            $exitCode = 2;
            break;
        }
        if (($result['action'] ?? '') === 'none') {
            break;
        }
    }
} catch (
    IngredientOntologyActivationExpectedOutcome $expected
) {
    $retryAfter = $expected->outcomeKind() === 'rebase_required'
        ? 30
        : 60;
    $recorded = true;
    $record = null;
    try {
        $record = $liveReservation(
            'record_expected_outcome',
            static function () use (
                $db,
                $expected,
                $retryAfter
            ): array {
                return ingredientOntologyActivationRecordOutcome(
                    $db,
                    $expected->outcomeKind(),
                    $expected->details() + [
                        'message' => $expected->getMessage(),
                    ],
                    true,
                    $retryAfter
                );
            }
        );
    } catch (
        IngredientOntologyActivationReservationUnavailable $lockError
    ) {
        $recorded = false;
        $failureLockPhase = $lockError->phase();
    }
    $escalated = !empty($record['escalated']);
    $results[] = [
        'cycle' => count($results) + 1,
        'action' => $escalated
            ? 'non_converging_expected_outcome'
            : $expected->outcomeKind(),
        'expected' => !$escalated,
        'reason' => $expected->getMessage(),
        'details' => $expected->details(),
        'next_attempt_seconds' => (int)(
            $record['next_attempt_seconds'] ?? $retryAfter
        ),
        'outcome_recorded' => $recorded,
        'escalated' => $escalated,
    ] + (
        $recorded
            ? []
            : [
                'status_outcome' => 'locked',
                'lock_scope' => 'background_writer',
                'lock_phase' => $failureLockPhase,
            ]
    );
    if ($escalated) {
        $exitCode = 2;
    }
} catch (
    IngredientOntologyActivationReservationUnavailable $error
) {
    $results[] = [
        'cycle' => count($results) + 1,
        'action' => 'none',
        'skipped' => true,
        'outcome' => 'locked',
        'reason' => 'background_writer_locked',
        'lock_scope' => 'background_writer',
        'lock_phase' => $error->phase(),
        'retryable' => true,
    ];
} catch (Throwable $error) {
    $failures = max(1, (int)($state['failure_count'] ?? 0) + 1);
    $delay = min(3600, 60 * (2 ** min(6, $failures - 1)));
    $failureRecorded = true;
    try {
        $liveReservation(
            'record_failure',
            static function () use (
                $db,
                $failures,
                $error,
                $delay
            ): void {
                $db->prepare("
                    UPDATE ontology_activation_state
                    SET failure_count = ?,
                        last_error = ?,
                        last_outcome_kind = 'integrity_failure',
                        last_outcome_json = ?,
                        last_outcome_at = CURRENT_TIMESTAMP,
                        next_attempt_at = datetime('now', ?),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = 1
                ")->execute([
                    $failures,
                    mb_substr(
                        $error->getMessage(),
                        0,
                        1000,
                        'UTF-8'
                    ),
                    ingredientOntologyActivationStableJson([
                        'error' => mb_substr(
                            $error->getMessage(),
                            0,
                            1000,
                            'UTF-8'
                        ),
                        'failure_count' => $failures,
                    ]),
                    '+' . $delay . ' seconds',
                ]);
            }
        );
    } catch (
        IngredientOntologyActivationReservationUnavailable $lockError
    ) {
        $failureRecorded = false;
        $failureLockPhase = $lockError->phase();
    }
    $results[] = [
        'cycle' => count($results) + 1,
        'action' => 'failed',
        'error' => $error->getMessage(),
        'next_attempt_seconds' => $delay,
        'failure_recorded' => $failureRecorded,
    ] + (
        $failureRecorded
            ? []
            : [
                'status_outcome' => 'locked',
                'lock_scope' => 'background_writer',
                'lock_phase' => $failureLockPhase,
            ]
    );
    $exitCode = 2;
} finally {
    flock($coordinationLock, LOCK_UN);
    fclose($coordinationLock);
    flock($lock, LOCK_UN);
    fclose($backgroundLock);
    fclose($lock);
}

echo json_encode(
    [
        'success' => !isset($exitCode),
        'cycles' => count($results),
        'results' => $results,
    ],
    JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($exitCode ?? 0);
