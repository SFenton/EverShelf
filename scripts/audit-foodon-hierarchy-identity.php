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
    fwrite(
        STDERR,
        "Usage: php scripts/audit-foodon-hierarchy-identity.php "
            . "--db=copy.sqlite [--limit=100] [--write]\n"
    );
    exit(2);
}
$databasePath = recipeCliAssertDatabaseInputSafe($databasePath, false);
$write = isset($options['write']);
$limit = max(1, min(1000, (int)($options['limit'] ?? 100)));
$db = new PDO('sqlite:' . $databasePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
if ($write) {
    databaseEnsureMigrated(
        $db,
        $databasePath . '.migration.lock'
    );
} else {
    $db->exec('PRAGMA query_only=ON');
}
$result = ingredientOntologyV3FoodOnHierarchyIdentityAudit(
    $db,
    $limit,
    $write
);
echo ingredientOntologyV3Json($result) . PHP_EOL;
exit((int)$result['unsafe_mapping_count'] > 0 && !$write ? 1 : 0);
