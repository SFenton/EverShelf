#!/usr/bin/env php
<?php
/**
 * Enrich canonical ingredients with USDA FoodData Central IDs.
 *
 * Usage:
 *   USDA_FDC_API_KEY=... php scripts/enrich-usda-links.php [--all] [--refresh] [--limit=N]
 *
 * Defaults to only terms missing a USDA link. Results are cached in
 * data/usda_fdc_lookup_cache.json and uncached requests are throttled by
 * USDA_FDC_MIN_REQUEST_INTERVAL_MS. A 429 response opens a temporary circuit
 * breaker for the rest of the run.
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
$before = canonicalIngredientUsdaStats($db, true);
if ($refresh) {
    $rows = $db->query("SELECT id, external_ids_json FROM canonical_ingredients WHERE external_ids_json LIKE '%\"usda_fdc\"%'")
        ->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->prepare("UPDATE canonical_ingredients SET external_ids_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    foreach ($rows as $row) {
        $ids = json_decode((string)$row['external_ids_json'], true);
        if (!is_array($ids)) {
            $ids = [];
        }
        unset($ids['usda_fdc']);
        $stmt->execute([empty($ids) ? null : json_encode($ids, JSON_UNESCAPED_UNICODE), (int)$row['id']]);
    }
}
$result = canonicalIngredientEnrichUsdaTable($db, !$all, $limit);
$after = canonicalIngredientUsdaStats($db, true);

echo json_encode([
    'success' => true,
    'mode' => $all ? 'all' : 'missing_only',
    'refresh' => $refresh,
    'limit' => $limit,
    'enabled' => canonicalIngredientUsdaEnabled(),
    'before' => $before,
    'result' => $result,
    'after' => $after,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
