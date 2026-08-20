#!/usr/bin/env php
<?php
declare(strict_types=1);

putenv('INGREDIENT_ONTOLOGY_CONTROLLER_ENABLED=false');
define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

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
    throw new InvalidArgumentException('--db is required');
}
$databasePath = recipeCliAssertDatabaseInputSafe($databasePath, false);
$db = new PDO('sqlite:' . $databasePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA busy_timeout=10000');
ingredientOntologyV3RegisterGuardFunctions($db);
ingredientOntologyV3SchemaMigrate($db);
recipeSchemaMigrate($db);

$assertions = 0;
$assert = static function (
    bool $condition,
    string $message
) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$capture = static function (callable $operation): array {
    ob_start();
    $operation();
    $decoded = json_decode((string)ob_get_clean(), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('flow endpoint returned invalid JSON');
    }
    return $decoded;
};
$saveProduct = static function (
    PDO $db,
    array $payload
) use ($capture): array {
    $GLOBALS['PRODUCT_API_JSON_INPUT'] = $payload;
    try {
        return $capture(static fn() => saveProduct($db));
    } finally {
        unset($GLOBALS['PRODUCT_API_JSON_INPUT']);
    }
};
$addInventory = static function (
    PDO $db,
    array $payload
) use ($capture): array {
    $GLOBALS['INVENTORY_ADD_INPUT'] = $payload;
    try {
        return $capture(static fn() => addToInventory($db));
    } finally {
        unset($GLOBALS['INVENTORY_ADD_INPUT']);
    }
};
$resolve = static function (
    PDO $db,
    string $barcode
) use ($capture): array {
    $_GET['barcode'] = $barcode;
    try {
        return $capture(static fn() => resolveBarcode($db));
    } finally {
        unset($_GET['barcode']);
    }
};

$suffix = substr((string)time(), -6);
$localBarcode = '9100000' . $suffix;
$externalBarcode = '9200000' . $suffix;
$missingBarcode = '9300000' . $suffix;
$productIds = [];

$localSave = $saveProduct($db, [
    'barcode' => $localBarcode,
    'name' => 'Scanner Local Fixture',
    'brand' => 'EverShelf Test',
    'category' => 'test',
    'unit' => 'pz',
    'default_quantity' => 1,
]);
$localId = (int)($localSave['id'] ?? 0);
$productIds[] = $localId;
$localResolve = $resolve($db, $localBarcode);
$assert(
    $localId > 0
    && !empty($localResolve['found'])
    && (string)$localResolve['source'] === 'local'
    && (int)$localResolve['product']['id'] === $localId,
    'Known barcode must resolve from the local product database'
);

barcodeCacheSet($db, $externalBarcode, [
    'found' => true,
    'source' => 'fixture_external',
    'product' => [
        'name' => 'Scanner External Fixture',
        'brand' => 'EverShelf Test',
        'category' => 'test',
        'image_url' => '',
        'quantity_info' => '1 pz',
    ],
], true);
$externalResolve = $resolve($db, $externalBarcode);
$assert(
    !empty($externalResolve['found'])
    && (string)$externalResolve['source']
        === 'fixture_external'
    && (string)$externalResolve['product']['name']
        === 'Scanner External Fixture',
    'Unknown local barcode must hydrate from an external connector result'
);
$externalSave = $saveProduct($db, [
    'barcode' => $externalBarcode,
    'name' => (string)$externalResolve['product']['name'],
    'brand' => (string)$externalResolve['product']['brand'],
    'category' => (string)$externalResolve['product']['category'],
    'unit' => 'pz',
    'default_quantity' => 1,
]);
$externalId = (int)($externalSave['id'] ?? 0);
$productIds[] = $externalId;

$httpCalls = 0;
$GLOBALS['BARCODE_HTTP_TRANSPORT'] =
    static function (
        array $requests
    ) use (&$httpCalls): array {
        $httpCalls += count($requests);
        return array_fill_keys(array_keys($requests), null);
    };
try {
    $missingResolve = $resolve($db, $missingBarcode);
} finally {
    unset($GLOBALS['BARCODE_HTTP_TRANSPORT']);
}
$assert(
    empty($missingResolve['found'])
    && (string)$missingResolve['source'] === 'none'
    && $httpCalls >= 5,
    'Unmatched barcode must exhaust connectors and fail closed'
);

$manualSave = $saveProduct($db, [
    'name' => 'Manual No Barcode Fixture',
    'brand' => 'EverShelf Test',
    'category' => 'test',
    'unit' => 'pz',
    'default_quantity' => 1,
]);
$manualId = (int)($manualSave['id'] ?? 0);
$productIds[] = $manualId;
$preparedSave = $saveProduct($db, [
    'name' => 'Prepared Food Fixture',
    'brand' => 'EverShelf Test',
    'category' => 'prepared meal',
    'unit' => 'pz',
    'default_quantity' => 1,
    'prepared_food' => true,
]);
$preparedId = (int)($preparedSave['id'] ?? 0);
$productIds[] = $preparedId;
$assert(
    $manualId > 0
    && $preparedId > 0
    && (int)$db->query("
        SELECT prepared_food FROM products
        WHERE id = {$preparedId}
    ")->fetchColumn() === 1,
    'Manual no-barcode and prepared-food products must commit'
);
$assert(
    (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_products
        WHERE product_id IN (" . implode(',', $productIds) . ")
    ")->fetchColumn() === count($productIds),
    'Product saves must queue sparse source publication before inventory arrives'
);

$adds = [];
foreach ([
    [$localId, 'dispensa', 1],
    [$externalId, 'frigo', 2],
    [$manualId, 'freezer', 1],
    [$preparedId, 'frigo', 1],
] as [$productId, $location, $quantity]) {
    $started = hrtime(true);
    $response = $addInventory($db, [
        'product_id' => $productId,
        'quantity' => $quantity,
        'location' => $location,
        'expiry_date' => '2030-01-01',
        'expiry_user_set' => true,
        'idempotency_key' =>
            "inventory-flow-{$suffix}-{$productId}",
    ]);
    $adds[] = [
        'product_id' => $productId,
        'elapsed_ms' => round(
            (hrtime(true) - $started) / 1000000,
            3
        ),
        'response' => $response,
    ];
    $assert(
        !empty($response['success'])
        && (int)$response['inventory_id'] > 0,
        "Inventory add failed for product {$productId}"
    );
}
$assert(
    (int)$db->query("
        SELECT prepared_food
        FROM inventory
        WHERE product_id = {$preparedId}
          AND quantity > 0
        LIMIT 1
    ")->fetchColumn() === 1,
    'Prepared-food inventory must retain its prepared state'
);

$score = ingredientOntologyV3IncrementalRebuild($db, true);
$assert(
    !empty($score['rebuilt'])
    && (int)$score['pending_product_count'] === 0
    && count($score['product_ids']) === count($productIds),
    'Scanner additions must publish and consume every pending product'
);

$useAll = $capture(static function () use ($db, $manualId): void {
    useFromInventoryCore(
        $db,
        $manualId,
        0,
        true,
        '__all__',
        'inventory flow matrix cleanup'
    );
});
$assert(
    !empty($useAll['success']),
    'Use-all must remove the manual fixture through the normal flow'
);
$useScore = ingredientOntologyV3IncrementalRebuild($db, true);
$assert(
    !empty($useScore['rebuilt'])
    && (int)$useScore['pending_product_count'] === 0,
    'Use-all must publish its inventory delta'
);

foreach ($productIds as $productId) {
    $GLOBALS['PRODUCT_API_JSON_INPUT'] = ['id' => $productId];
    try {
        $deleted = $capture(static fn() => deleteProduct($db));
    } finally {
        unset($GLOBALS['PRODUCT_API_JSON_INPUT']);
    }
    $assert(
        !empty($deleted['success']),
        "Fixture product {$productId} did not delete cleanly"
    );
}
$cleanupScore = ingredientOntologyV3IncrementalRebuild($db, true);
$assert(
    !empty($cleanupScore['rebuilt'])
    && (int)$cleanupScore['pending_product_count'] === 0
    && (int)$db->query("
        SELECT COUNT(*) FROM products
        WHERE id IN (" . implode(',', $productIds) . ")
    ")->fetchColumn() === 0,
    'Scanner flow cleanup must publish deleted product dependencies'
);

echo json_encode([
    'success' => true,
    'assertions' => $assertions,
    'barcodes' => [
        'local' => $localResolve,
        'external' => $externalResolve,
        'missing' => $missingResolve,
    ],
    'adds' => $adds,
    'initial_score' => $score,
    'use_score' => $useScore,
    'cleanup_score' => $cleanupScore,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
