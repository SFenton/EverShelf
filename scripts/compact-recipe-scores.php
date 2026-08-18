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
    ingredientOntologyV3RegisterGuardFunctions($db);
    ingredientOntologyV3SchemaMigrate($db);
    recipeSchemaMigrate($db);
}
$result = ingredientOntologyV3CompactActiveScores(
    $db,
    isset($options['force'])
);
echo json_encode(
    ['success' => true] + $result,
    JSON_UNESCAPED_SLASHES
) . PHP_EOL;
