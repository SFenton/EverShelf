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
ingredientOntologyActivationAssertActiveDatabase($db);

if (
    !ingredientOntologyActivationEnabled()
    && !isset($options['force'])
) {
    $active = recipeScoreActiveRevision($db);
    $v3Active = $active !== null
        && $active['ontology_version_id'] !== null;
    $result = [
        'success' => !$v3Active,
        'skipped' => true,
        'reason' => $v3Active
            ? 'ontology_activation_required_but_disabled'
            : 'ontology_activation_disabled',
        'maintenance' => [
            'cdc_pruned' => ingredientOntologyActivationPruneCdc($db),
            'work_cleanup' =>
                ingredientOntologyActivationCleanupWorkFiles($db),
        ],
    ];
    echo json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit($v3Active ? 2 : 0);
}

$lockPath = dirname(ingredientOntologyActivationDatabasePath($db))
    . '/.ontology-activation.lock';
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
        'reason' => 'ontology_activation_locked',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$maximumCycles = max(
    1,
    min(100, (int)($options['max-cycles'] ?? 1))
);
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
    fclose($lock);
    echo json_encode([
        'success' => true,
        'skipped' => true,
        'reason' => 'ontology_activation_backoff',
        'next_attempt_at' => $state['next_attempt_at'],
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
                    min(10000, (int)($options['maximum-chunks'] ?? 500))
                ),
                'intent_limit' => max(
                    1,
                    min(50, (int)($options['intent-limit'] ?? 50))
                ),
                'batch_size' => max(
                    1,
                    min(1000, (int)($options['batch'] ?? 250))
                ),
            ]
        );
        $results[] = ['cycle' => $cycle + 1] + $result;
        if (($result['action'] ?? '') === 'none') {
            break;
        }
    }
    $db->exec("
        UPDATE ontology_activation_state
        SET failure_count = 0,
            last_error = '',
            next_attempt_at = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ");
} catch (Throwable $error) {
    $failures = max(1, (int)($state['failure_count'] ?? 0) + 1);
    $delay = min(3600, 60 * (2 ** min(6, $failures - 1)));
    $db->prepare("
        UPDATE ontology_activation_state
        SET failure_count = ?,
            last_error = ?,
            next_attempt_at = datetime('now', ?),
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ")->execute([
        $failures,
        mb_substr($error->getMessage(), 0, 1000, 'UTF-8'),
        '+' . $delay . ' seconds',
    ]);
    $results[] = [
        'cycle' => count($results) + 1,
        'action' => 'failed',
        'error' => $error->getMessage(),
        'next_attempt_seconds' => $delay,
    ];
    $exitCode = 2;
} finally {
    flock($lock, LOCK_UN);
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
