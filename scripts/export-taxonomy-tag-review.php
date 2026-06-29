#!/usr/bin/env php
<?php
/**
 * Export taxonomy/tag review batches as JSON.
 *
 * Usage:
 *   php scripts/export-taxonomy-tag-review.php [--batch-size=10] [--out=/path/file.json]
 */
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

$batchSize = 10;
$out = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--batch-size=')) {
        $batchSize = max(1, (int)substr($arg, 13));
    } elseif (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
    }
}

$db = getDB();
$products = $db->query("
    SELECT p.id, p.name, p.brand, p.category
    FROM products p
    WHERE EXISTS (SELECT 1 FROM inventory i WHERE i.product_id = p.id AND i.quantity > 0)
    ORDER BY p.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$rows = [];
foreach ($products as $product) {
    $taxonomy = canonicalIngredientRowsForProduct($db, (int)$product['id']);
    $tags = canonicalProductTagRowsForProduct($db, (int)$product['id']);
    $chain = array_values(array_map(fn($row) => $row['name'], array_filter(
        $taxonomy,
        fn($row) => in_array($row['role'] ?? '', ['primary', 'broader'], true)
    )));
    $contains = array_values(array_map(fn($row) => $row['name'], array_filter(
        $taxonomy,
        fn($row) => ($row['role'] ?? '') === 'contains'
    )));

    $rows[] = [
        'product_id' => (int)$product['id'],
        'product' => $product['name'],
        'brand' => $product['brand'] ?? '',
        'current_taxonomy' => $chain,
        'contains' => $contains,
        'proposed_taxonomy' => $chain,
        'tags' => array_map(fn($tag) => [
            'facet' => $tag['facet'],
            'value' => $tag['value'],
            'confidence' => $tag['confidence'],
            'source' => $tag['source'],
        ], $tags),
    ];
}

$batches = array_chunk($rows, $batchSize);
$payload = [
    'generated_at' => date('c'),
    'batch_size' => $batchSize,
    'total_products' => count($rows),
    'total_batches' => count($batches),
    'batches' => $batches,
];

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
if ($out !== '') {
    $dir = dirname($out);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($out, $json);
}
echo $json;
