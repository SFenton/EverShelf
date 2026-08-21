#!/usr/bin/env php
<?php
/**
 * Seed full-corpus Cookidoo crawls for eligible stocked taxonomy terms.
 *
 * Usage:
 *   php scripts/backfill-cookidoo-crawls.php [--locale=en-US] [--tmv=TM6]
 *       [--dry-run] [--force] [--json]
 */
declare(strict_types=1);

define('CRON_MODE', true);

require_once __DIR__ . '/../api/bootstrap.php';

function recipeCookidooBackfillUsage(): string {
    return implode(PHP_EOL, [
        'Usage: php scripts/backfill-cookidoo-crawls.php [options]',
        '  --locale=LOCALE  Cookidoo locale (default: COOKIDOO_DISCOVERY_LOCALE)',
        '  --tmv=TMV        TM31, TM5, TM6, or TM7 (default: TM6)',
        '  --dry-run        Report planned crawl jobs without DB/job writes',
        '  --force          Requeue stale or terminal discovery jobs',
        '  --json           Emit machine-readable JSON',
        '  --help           Show this help',
        '',
        'Requires Cookidoo connector and detail hydration to be enabled.',
    ]) . PHP_EOL;
}

$options = [
    'locale' => recipeCookidooDiscoveryLocale(),
    'tmv' => 'TM6',
    'dry_run' => false,
    'force' => false,
];
$json = false;
$args = array_slice($argv, 1);
for ($index = 0; $index < count($args); $index++) {
    $arg = $args[$index];
    if ($arg === '--dry-run') {
        $options['dry_run'] = true;
    } elseif ($arg === '--force') {
        $options['force'] = true;
    } elseif ($arg === '--json') {
        $json = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo recipeCookidooBackfillUsage();
        exit(0);
    } elseif (str_starts_with($arg, '--locale=')) {
        $options['locale'] = substr($arg, 9);
    } elseif ($arg === '--locale' && isset($args[$index + 1])) {
        $options['locale'] = $args[++$index];
    } elseif (str_starts_with($arg, '--tmv=')) {
        $options['tmv'] = substr($arg, 6);
    } elseif ($arg === '--tmv' && isset($args[$index + 1])) {
        $options['tmv'] = $args[++$index];
    } else {
        fwrite(STDERR, 'Unknown or incomplete option: ' . $arg . PHP_EOL);
        fwrite(STDERR, recipeCookidooBackfillUsage());
        exit(2);
    }
}

if (!recipeCookidooDetailHydrationPolicyAllows()) {
    $result = [
        'dry_run' => (bool)$options['dry_run'],
        'eligible_products' => 0,
        'terms' => 0,
        'planned' => 0,
        'queued' => 0,
        'would_queue' => 0,
        'skipped' => 0,
        'jobs' => [],
        'reason' => RECIPE_COOKIDOO_DETAIL_POLICY_REASON,
        'policy_version' => RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
    ];
    if (!$options['dry_run']) {
        if ($json) {
            echo json_encode(
                [
                    'success' => false,
                    'error' => RECIPE_COOKIDOO_DETAIL_POLICY_REASON,
                ] + $result,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
            ) . PHP_EOL;
        } else {
            fwrite(
                STDERR,
                RECIPE_COOKIDOO_DETAIL_POLICY_REASON . PHP_EOL
            );
        }
        exit(3);
    }
} else {
    try {
        $result = recipeCookidooSeedTaxonomyCrawls(getDB(), $options);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(2);
    }
}

if ($json) {
    echo json_encode(
        ['success' => true] + $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit(0);
}

echo ($result['dry_run'] ? 'Dry run' : 'Cookidoo crawl backfill')
    . ': products=' . $result['eligible_products']
    . ', terms=' . $result['terms']
    . ', roots=' . $result['planned']
    . ', queued=' . $result['queued']
    . ', would_queue=' . $result['would_queue']
    . ', skipped=' . $result['skipped']
    . PHP_EOL;
if (!empty($result['reason'])) {
    echo 'Reason: ' . $result['reason'] . PHP_EOL;
}
