#!/usr/bin/env php
<?php
/**
 * Plan or enqueue bounded direct-ID Cookidoo metadata-v2 refresh jobs.
 *
 * This command never executes bridge work itself.
 */
declare(strict_types=1);

define('CRON_MODE', true);

require_once __DIR__ . '/../api/bootstrap.php';

function recipeCookidooMetadataBackfillUsage(): string {
    return implode(PHP_EOL, [
        'Usage: php scripts/backfill-cookidoo-metadata-v2.php [mode] [options]',
        'Modes (choose one; default is --status):',
        '  --status             Show version coverage, checkpoint, and job counts',
        '  --dry-run            Plan bounded metadata batches without writing',
        '  --enqueue            Enqueue bounded direct-ID metadata batches',
        'Options:',
        '  --locale=LOCALE      One exact regional/script Cookidoo locale (default: discovery locale)',
        '  --batch-size=N       IDs per bridge job, 1-20 (default: configured value)',
        '  --max-recipes=N      Recipes considered this run, 1-200 (default: 200)',
        '  --json               Emit machine-readable JSON',
        '  --help               Show this help',
        '',
        'Provider metadata enqueue requires the default-off connector, detail,',
        'bulk-backfill, and metadata-v3 bridge capability gates.',
        'Existing cached catalog rows remain readable while stale.',
    ]) . PHP_EOL;
}

$mode = 'status';
$modeWasSet = false;
$locale = recipeCookidooDiscoveryLocale();
$batchSize = recipeCookidooMetadataBackfillBatchSize();
$maxRecipes = 200;
$json = false;

foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, ['--status', '--dry-run', '--enqueue'], true)) {
        if ($modeWasSet) {
            fwrite(STDERR, "Choose exactly one mode.\n");
            exit(2);
        }
        $mode = substr($arg, 2);
        $modeWasSet = true;
    } elseif ($arg === '--json') {
        $json = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo recipeCookidooMetadataBackfillUsage();
        exit(0);
    } elseif (str_starts_with($arg, '--locale=')) {
        $locale = substr($arg, 9);
    } elseif (str_starts_with($arg, '--batch-size=')) {
        $batchSize = (int)substr($arg, 13);
    } elseif (str_starts_with($arg, '--max-recipes=')) {
        $maxRecipes = (int)substr($arg, 14);
    } else {
        fwrite(STDERR, 'Unknown option: ' . $arg . PHP_EOL);
        fwrite(STDERR, recipeCookidooMetadataBackfillUsage());
        exit(2);
    }
}

if ($batchSize < 1 || $batchSize > 20) {
    fwrite(STDERR, "batch-size must be between 1 and 20.\n");
    exit(2);
}
if ($maxRecipes < 1 || $maxRecipes > 200) {
    fwrite(STDERR, "max-recipes must be between 1 and 200.\n");
    exit(2);
}

try {
    $db = getDB();
    $status = recipeCookidooMetadataBackfillStatus($db, $locale);
    if ($mode === 'status') {
        $result = ['mode' => $mode, 'status' => $status];
    } elseif ($mode === 'dry-run') {
        $result = [
            'mode' => $mode,
            'status' => $status,
            'plan' => recipeCookidooMetadataBackfillPlan(
                $db,
                $locale,
                $batchSize,
                $maxRecipes
            ),
        ];
    } else {
        $result = [
            'mode' => $mode,
            'enqueue' => recipeCookidooEnqueueMetadataBackfill(
                $db,
                $locale,
                $batchSize,
                $maxRecipes
            ),
            'status' => recipeCookidooMetadataBackfillStatus($db, $locale),
        ];
    }
} catch (InvalidArgumentException|RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
}

if ($json) {
    echo json_encode(
        ['success' => true] + $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit(0);
}

echo 'Mode: ' . $mode . PHP_EOL;
echo 'Locale: ' . $status['locale'] . PHP_EOL;
echo 'Refreshable locale: ' . ($status['refreshable'] ? 'yes' : 'no');
if (!$status['refreshable']) {
    echo ' (' . $status['unrefreshable_reason'] . ')';
}
echo PHP_EOL;
echo 'Enabled: ' . ($status['enabled'] ? 'yes' : 'no') . PHP_EOL;
echo 'Versions: metadata=' . $status['metadata_version']
    . ', topology_schema=' . $status['metadata_schema_version']
    . ', mapping=' . $status['mapping_version']
    . ', failure_schema=' . $status['failure_schema_version'] . PHP_EOL;
echo 'Origins: total=' . $status['origins']['total']
    . ', current=' . $status['origins']['current']
    . ', failed=' . $status['origins']['failed']
    . ', probe_due=' . $status['origins']['probe_due']
    . ', remaining=' . $status['origins']['remaining']
    . ', invalid_locale=' . $status['origins']['invalid_locale']
    . ', unrefreshable=' . $status['origins']['unrefreshable'] . PHP_EOL;
echo 'Source metrics: ingredients='
    . $status['source_metrics']['ingredient_count']
    . ', groups=' . $status['source_metrics']['group_count']
    . ', null_quantities='
    . $status['source_metrics']['null_quantity_count']
    . ', ranges=' . $status['source_metrics']['range_quantity_count']
    . ', distinct_units='
    . $status['source_metrics']['distinct_unit_count'] . PHP_EOL;
echo 'Topology metrics: labeled_groups='
    . $status['source_metrics']['topology']['labeled_group_count']
    . ', max_group_title_length='
    . $status['source_metrics']['topology']['group_title_length_max']
    . ', ingredient_refs='
    . $status['source_metrics']['topology']['ingredient_ref_count']
    . ', default_titles='
    . $status['source_metrics']['topology']['default_title_count']
    . ', unit_refs='
    . $status['source_metrics']['topology']['unit_ref_count']
    . ', optional_true='
    . $status['source_metrics']['topology']['optional_true_count']
    . ', optional_false='
    . $status['source_metrics']['topology']['optional_false_count']
    . ', optional_null='
    . $status['source_metrics']['topology']['optional_null_count']
    . ', shopping_category_refs='
    . $status['source_metrics']['topology']['shopping_category_ref_count']
    . PHP_EOL;
echo 'Unit strings: '
    . ($status['source_metrics']['distinct_unit_strings']
        ? implode(', ', $status['source_metrics']['distinct_unit_strings'])
        : '(none)')
    . ($status['source_metrics']['distinct_unit_strings_truncated']
        ? ' (truncated)'
        : '')
    . PHP_EOL;
echo 'Recent jobs: response_bytes='
    . $status['recent_job_observability']['response_bytes']
    . ', average_latency_ms='
    . $status['recent_job_observability']['average_latency_ms']
    . ', revision_invariant_failures='
    . $status['recent_job_observability']['revision_invariant_failures']
    . PHP_EOL;
echo 'Recent topology keys: group_title='
    . $status['recent_job_observability']['topology'][
        'group_title_key_count'
    ]
    . ', group_title_nonempty='
    . $status['recent_job_observability']['topology'][
        'group_title_nonempty_count'
    ]
    . ', max_group_title_length='
    . $status['recent_job_observability']['topology'][
        'group_title_length_max'
    ]
    . ', unit_ref='
    . $status['recent_job_observability']['topology'][
        'unit_ref_nonempty_count'
    ]
    . ', default_title='
    . $status['recent_job_observability']['topology'][
        'default_title_nonempty_count'
    ]
    . PHP_EOL;
echo 'Failure kinds: '
    . ($status['failures']['kind_counts']
        ? json_encode(
            $status['failures']['kind_counts'],
            JSON_UNESCAPED_SLASHES
        )
        : '{}')
    . PHP_EOL;
echo 'Checkpoint: ' . $status['cursor'] . PHP_EOL;
if ($mode === 'dry-run') {
    echo 'Would enqueue: ' . $result['plan']['batch_count']
        . ' jobs / ' . $result['plan']['recipe_count'] . ' recipes' . PHP_EOL;
    echo 'Policy: ' . $result['plan']['unrefreshable_reason'] . PHP_EOL;
} elseif ($mode === 'enqueue') {
    echo 'Enqueued/reused: ' . $result['enqueue']['queued_jobs']
        . ' jobs / ' . $result['enqueue']['recipe_count'] . ' recipes' . PHP_EOL;
}
