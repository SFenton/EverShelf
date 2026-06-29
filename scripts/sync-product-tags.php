#!/usr/bin/env php
<?php
/**
 * Sync canonical taxonomy and product tags for existing products.
 *
 * Usage:
 *   php scripts/sync-product-tags.php [--all] [--limit=N]
 */
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

$all = in_array('--all', $argv, true);
$limit = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int)substr($arg, 8));
    }
}

$db = getDB();
$where = $all ? '' : "WHERE EXISTS (SELECT 1 FROM inventory i WHERE i.product_id = p.id AND i.quantity > 0)";
$sql = "SELECT p.* FROM products p $where ORDER BY p.name ASC";
if ($limit > 0) {
    $sql .= " LIMIT " . (int)$limit;
}
$products = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$processed = 0;
$tags = 0;
foreach ($products as $product) {
    $result = canonicalIngredientSyncProduct($db, (int)$product['id'], $product);
    $processed++;
    $tags += count($result['tags'] ?? []);
}

$tagRows = $db->query("
    SELECT facet, COUNT(*) AS count
    FROM product_tags
    GROUP BY facet
    ORDER BY facet ASC
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'scope' => $all ? 'all_products' : 'active_inventory_products',
    'processed' => $processed,
    'tags_written' => $tags,
    'facet_counts' => $tagRows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
