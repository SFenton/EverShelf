#!/usr/bin/env php
<?php
/**
 * Rebuild the materialized inventory-to-recipe score revision.
 *
 * Usage:
 *   php scripts/rebuild-recipe-scores.php [--force] [--batch=N] [--json]
 *       [--db=copy.sqlite]
 */
declare(strict_types=1);

define('CRON_MODE', true);

require_once __DIR__ . '/../api/bootstrap.php';

if (
    in_array('--help', $argv, true)
    || in_array('-h', $argv, true)
) {
    echo "Usage: php scripts/rebuild-recipe-scores.php "
        . "[--force] [--batch=N] [--json] [--db=copy.sqlite]\n";
    exit(0);
}

$force = in_array('--force', $argv, true);
$json = in_array('--json', $argv, true);
$batchSize = 250;
$databasePath = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--batch=')) {
        $batchSize = max(1, min(1000, (int)substr($arg, 8)));
    } elseif (str_starts_with($arg, '--db=')) {
        $databasePath = trim(substr($arg, 5));
    }
}

if ($databasePath === null || $databasePath === '') {
    $db = getDB();
} else {
    if (!str_starts_with($databasePath, '/')) {
        $databasePath = getcwd() . '/' . $databasePath;
    }
    $directory = realpath(dirname($databasePath));
    if ($directory === false) {
        throw new InvalidArgumentException('database directory does not exist');
    }
    $databasePath = $directory . '/' . basename($databasePath);
    if (!is_file($databasePath)) {
        throw new InvalidArgumentException('database copy does not exist');
    }
    if (
        realpath($databasePath) === realpath(DB_PATH)
        && !in_array('--allow-active-db', $argv, true)
    ) {
        throw new RuntimeException(
            'explicit --db refuses the active database without --allow-active-db'
        );
    }
    $db = new PDO('sqlite:' . $databasePath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 10000');
    recipeSchemaMigrate($db);
}
$result = ingredientOntologyV3ScheduledRebuild(
    $db,
    $force,
    $batchSize
);

if ($json) {
    $failed = in_array(
        (string)($result['reason'] ?? ''),
        [
            'failed',
            'validation_failed',
            'previous_failure',
            'ontology_stale',
        ],
        true
    );
    echo json_encode(
        ['success' => !$failed] + $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    ) . PHP_EOL;
    exit($failed ? 2 : 0);
}

if (empty($result['rebuilt'])) {
    echo 'Recipe scores unchanged: ' . ($result['reason'] ?? 'unknown') . PHP_EOL;
    exit(in_array(
        (string)($result['reason'] ?? ''),
        [
            'failed',
            'validation_failed',
            'previous_failure',
            'ontology_stale',
        ],
        true
    ) ? 2 : 0);
}

echo 'Recipe score revision #' . $result['revision_id']
    . ' built for ' . $result['recipe_count'] . ' recipes'
    . ($result['activated'] ? ' and activated' : ' (newer inventory revision pending)')
    . PHP_EOL;
