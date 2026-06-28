#!/usr/bin/env php
<?php
/**
 * Enrich canonical ingredients with FoodOn IDs through EBI OLS.
 *
 * Usage:
 *   php scripts/enrich-foodon-links.php [--all] [--refresh] [--limit=N]
 *
 * Defaults to only terms missing a FoodOn link. Results are cached in
 * data/foodon_lookup_cache.json and uncached requests are throttled by
 * FOODON_MIN_REQUEST_INTERVAL_MS.
 */
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

$all = in_array('--all', $argv, true);
$refresh = in_array('--refresh', $argv, true);
$limit = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int)substr($arg, 8));
    }
}

$db = getDB();
$before = canonicalIngredientFoodOnStats($db, true);
if ($refresh) {
    $rows = $db->query("SELECT id, external_ids_json FROM canonical_ingredients WHERE external_ids_json LIKE '%\"foodon\"%'")
        ->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->prepare("UPDATE canonical_ingredients SET external_ids_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    foreach ($rows as $row) {
        $ids = json_decode((string)$row['external_ids_json'], true);
        if (!is_array($ids)) {
            $ids = [];
        }
        unset($ids['foodon']);
        $stmt->execute([empty($ids) ? null : json_encode($ids, JSON_UNESCAPED_UNICODE), (int)$row['id']]);
    }
}
$result = canonicalIngredientEnrichFoodOnTable($db, !$all, $limit);
$after = canonicalIngredientFoodOnStats($db, true);

echo json_encode([
    'success' => true,
    'mode' => $all ? 'all' : 'missing_only',
    'refresh' => $refresh,
    'limit' => $limit,
    'before' => $before,
    'result' => $result,
    'after' => $after,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
