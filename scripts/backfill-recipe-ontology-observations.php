#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

function recipeOntologyObservationBackfillUsage(): string {
    return implode(PHP_EOL, [
        'Usage: php scripts/backfill-recipe-ontology-observations.php [mode] [options]',
        'Modes (choose one; default is --status):',
        '  --status             Report missing recipe ontology occurrences',
        '  --dry-run            List the bounded recipes that would be observed',
        '  --write              Observe and queue the bounded missing recipes',
        'Options:',
        '  --limit=N            Recipes per run, 1-500 (default: 100)',
        '  --allow-active-db    Required with --write',
        '  --json               Emit machine-readable JSON',
        '  --help               Show this help',
    ]) . PHP_EOL;
}

$mode = 'status';
$modeSet = false;
$limit = 100;
$allowActive = false;
$json = false;

foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--status', '--dry-run', '--write'], true)) {
        if ($modeSet) {
            fwrite(STDERR, "Choose exactly one mode.\n");
            exit(2);
        }
        $mode = substr($argument, 2);
        $modeSet = true;
    } elseif (str_starts_with($argument, '--limit=')) {
        $limit = (int)substr($argument, 8);
    } elseif ($argument === '--allow-active-db') {
        $allowActive = true;
    } elseif ($argument === '--json') {
        $json = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        echo recipeOntologyObservationBackfillUsage();
        exit(0);
    } else {
        fwrite(STDERR, 'Unknown option: ' . $argument . PHP_EOL);
        fwrite(STDERR, recipeOntologyObservationBackfillUsage());
        exit(2);
    }
}

if ($limit < 1 || $limit > 500) {
    fwrite(STDERR, "limit must be between 1 and 500.\n");
    exit(2);
}
if ($mode === 'write' && !$allowActive) {
    fwrite(
        STDERR,
        "--write requires --allow-active-db.\n"
    );
    exit(2);
}

$db = getDB();
$before = evershelfProcessingStatusRecipeOntologyCoverage($db);
$recipeIds = evershelfProcessingStatusMissingRecipeIds($db, $limit);
$result = [
    'mode' => $mode,
    'limit' => $limit,
    'before' => $before,
    'selected_recipe_count' => count($recipeIds),
    'selected_recipe_ids' => $recipeIds,
];
$exitCode = 0;

if (
    $mode === 'write'
    && (
        !function_exists('ingredientOntologyControllerEnabled')
        || !ingredientOntologyControllerEnabled()
    )
) {
    $result['skipped'] = true;
    $result['reason'] = 'ontology_controller_disabled';
    $result['processed_recipe_count'] = 0;
    $result['processed_recipe_ids'] = [];
    $result['occurrence_count'] = 0;
    $result['created_subject_count'] = 0;
    $result['queued_job_count'] = 0;
    $result['failure_count'] = 0;
    $result['failures'] = [];
    $result['after'] = $before;
} elseif ($mode === 'write') {
    $processed = [];
    $failures = [];
    $occurrenceCount = 0;
    $createdSubjectCount = 0;
    $queuedJobCount = 0;
    foreach ($recipeIds as $recipeId) {
        $transactionStarted = false;
        try {
            dbBeginImmediateWithRetry($db);
            $transactionStarted = true;
            $observation =
                ingredientOntologyControllerObserveRecipeSafely(
                    $db,
                    $recipeId
                );
            if (
                empty($observation['observed'])
                || !empty($observation['disabled'])
                || !empty($observation['degraded'])
            ) {
                throw new RuntimeException(
                    (string)($observation['error']
                        ?? 'recipe ontology observation unavailable')
                );
            }
            $db->exec('COMMIT');
            $transactionStarted = false;
            $processed[] = $recipeId;
            $occurrenceCount +=
                (int)($observation['occurrence_count'] ?? 0);
            $createdSubjectCount +=
                (int)($observation['created_subject_count'] ?? 0);
            $queuedJobCount +=
                (int)($observation['queued_job_count'] ?? 0);
        } catch (Throwable $error) {
            if ($transactionStarted) {
                try {
                    $db->exec('ROLLBACK');
                } catch (Throwable $ignored) {
                }
            }
            $failures[] = [
                'recipe_id' => $recipeId,
                'error' => mb_substr(
                    trim($error->getMessage()) ?: get_class($error),
                    0,
                    300,
                    'UTF-8'
                ),
            ];
        }
    }
    if ($processed) {
        ingredientOntologyControllerWake();
    }
    $result['processed_recipe_count'] = count($processed);
    $result['processed_recipe_ids'] = $processed;
    $result['occurrence_count'] = $occurrenceCount;
    $result['created_subject_count'] = $createdSubjectCount;
    $result['queued_job_count'] = $queuedJobCount;
    $result['failure_count'] = count($failures);
    $result['failures'] = $failures;
    $result['after'] =
        evershelfProcessingStatusRecipeOntologyCoverage($db);
    if ($failures) {
        $exitCode = 1;
    }
}

if ($json) {
    echo json_encode(
        ['success' => $exitCode === 0] + $result,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit($exitCode);
}

echo 'Mode: ' . $mode . PHP_EOL;
echo 'Source coverage: '
    . $before['covered_row_count'] . '/'
    . $before['source_row_count'] . ' ('
    . $before['coverage_percent'] . '%)' . PHP_EOL;
echo 'Missing source rows: ' . $before['missing_row_count'] . PHP_EOL;
echo 'Recipes missing occurrences: '
    . $before['missing_recipe_count'] . PHP_EOL;
echo 'Selected recipes: ' . count($recipeIds) . PHP_EOL;
if ($mode === 'write') {
    echo 'Processed recipes: '
        . $result['processed_recipe_count'] . PHP_EOL;
    echo 'Observed occurrences: '
        . $result['occurrence_count'] . PHP_EOL;
    echo 'Created subjects: '
        . $result['created_subject_count'] . PHP_EOL;
    echo 'Queued jobs: ' . $result['queued_job_count'] . PHP_EOL;
    echo 'Failures: ' . $result['failure_count'] . PHP_EOL;
    echo 'Remaining recipes: '
        . $result['after']['missing_recipe_count'] . PHP_EOL;
}
exit($exitCode);
