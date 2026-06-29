#!/usr/bin/env php
<?php
/**
 * Backfill canonical ingredient mappings for existing products.
 *
 * Usage:
 *   php scripts/backfill-canonical-ingredients.php [--all] [--dry-run]
 *
 * Defaults to products with active inventory only. Use --all for the full catalog.
 */
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);
$activeOnly = !in_array('--all', $argv, true);

$db = getDB();
$where = $activeOnly
    ? "WHERE EXISTS (SELECT 1 FROM inventory i WHERE i.product_id = p.id AND i.quantity > 0)"
    : "";
$rows = $db->query("SELECT p.* FROM products p $where ORDER BY p.name ASC")->fetchAll(PDO::FETCH_ASSOC);

$processed = 0;
$withPrimary = 0;
$totalMappings = 0;
$lowConfidence = [];
$unmatched = [];

foreach ($rows as $product) {
    $processed++;
    if ($dryRun) {
        $dryRunMappings = canonicalIngredientInferProduct($product, $db);
        $result = ['mappings' => $dryRunMappings, 'mapped' => count($dryRunMappings)];
    } else {
        $result = canonicalIngredientSyncProduct($db, (int)$product['id'], $product);
    }

    $mappings = $result['mappings'] ?? [];
    $totalMappings += count($mappings);
    $primary = null;
    foreach ($mappings as $mapping) {
        if (($mapping['role'] ?? '') === 'primary') {
            $primary = $mapping;
            break;
        }
    }
    if ($primary) {
        $withPrimary++;
        if ((float)$primary['confidence'] < 0.70 && count($lowConfidence) < 20) {
            $lowConfidence[] = [
                'product_id' => (int)$product['id'],
                'product' => $product['name'],
                'primary' => $primary['name'],
                'confidence' => round((float)$primary['confidence'], 3),
                'source' => $primary['source'],
            ];
        }
    } elseif (count($unmatched) < 30) {
        $unmatched[] = [
            'product_id' => (int)$product['id'],
            'product' => $product['name'],
            'brand' => $product['brand'] ?? '',
            'category' => $product['category'] ?? '',
        ];
    }
}

$assessment = $dryRun ? null : canonicalIngredientAssess($db, $activeOnly);

echo json_encode([
    'success' => true,
    'dry_run' => $dryRun,
    'scope' => $activeOnly ? 'active_inventory_products' : 'all_products',
    'processed' => $processed,
    'products_with_primary' => $withPrimary,
    'coverage_pct' => $processed > 0 ? round(($withPrimary / $processed) * 100, 1) : 0,
    'mappings_total' => $totalMappings,
    'low_confidence' => $lowConfidence,
    'unmatched' => $unmatched,
    'assessment' => $assessment,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
