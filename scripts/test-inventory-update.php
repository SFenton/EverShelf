#!/usr/bin/env php
<?php
declare(strict_types=1);

putenv('SHOPPING_MODE=internal');
putenv('INGREDIENT_ONTOLOGY_CONTROLLER_ENABLED=false');
define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/index.php';

$assertions = 0;
function inventoryUpdateTestAssert(
    bool $condition,
    string $message
): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/evershelf-inventory-update-'
    . getmypid() . '-' . bin2hex(random_bytes(4));
if (!mkdir($directory, 0770, true)) {
    throw new RuntimeException(
        'Could not create inventory update test directory'
    );
}
$databasePath = $directory . '/inventory.db';

try {
    $db = new PDO('sqlite:' . $databasePath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    initializeDB($db);
    migrateDB($db);

    $db->prepare("
        INSERT INTO products (
            barcode, name, unit, default_quantity, shopping_name
        )
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        'atomic-decrement-1',
        'Atomic Flour',
        'kg',
        1,
        'Flour',
    ]);
    $productId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO inventory (
            product_id, location, quantity, expiry_date
        )
        VALUES (?, 'dispensa', 10, '2027-01-01')
    ")->execute([$productId]);
    $inventoryId = (int)$db->lastInsertId();

    $staleQuantity = (float)$db->query("
        SELECT quantity FROM inventory WHERE id = {$inventoryId}
    ")->fetchColumn();
    $concurrent = new PDO('sqlite:' . $databasePath);
    $concurrent->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );
    $concurrent->exec('PRAGMA busy_timeout = 5000');
    $concurrent->prepare("
        UPDATE inventory SET quantity = 15 WHERE id = ?
    ")->execute([$inventoryId]);
    $concurrent = null;

    $result = decrementInventoryEntry(
        $db,
        $inventoryId,
        1,
        'KG'
    );
    inventoryUpdateTestAssert(
        $staleQuantity === 10.0
        && $result['success'] === true
        && (float)$result['used'] === 1.0
        && (float)$result['quantity'] === 14.0
        && (float)$db->query("
            SELECT quantity FROM inventory WHERE id = {$inventoryId}
        ")->fetchColumn() === 14.0,
        'Atomic decrement must apply to the latest committed quantity'
    );
    $transaction = $db->query("
        SELECT type, quantity, inventory_id, notes
        FROM transactions
        WHERE inventory_id = {$inventoryId}
        ORDER BY id DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    inventoryUpdateTestAssert(
        $transaction
        && $transaction['type'] === 'out'
        && (float)$transaction['quantity'] === 1.0
        && (int)$transaction['inventory_id'] === $inventoryId
        && $transaction['notes'] === '[Inventory decrement]',
        'Atomic decrement must record the actual consumed quantity'
    );

    $transactionCount = (int)$db->query("
        SELECT COUNT(*) FROM transactions
        WHERE inventory_id = {$inventoryId}
    ")->fetchColumn();
    $mismatch = decrementInventoryEntry(
        $db,
        $inventoryId,
        1,
        'g'
    );
    inventoryUpdateTestAssert(
        $mismatch['success'] === false
        && $mismatch['status'] === 409
        && $mismatch['error'] === 'inventory_unit_mismatch'
        && (float)$db->query("
            SELECT quantity FROM inventory WHERE id = {$inventoryId}
        ")->fetchColumn() === 14.0
        && (int)$db->query("
            SELECT COUNT(*) FROM transactions
            WHERE inventory_id = {$inventoryId}
        ")->fetchColumn() === $transactionCount,
        'A mismatched unit must fail without inventory or ledger mutation'
    );

    $db->prepare("
        INSERT INTO products (
            barcode, name, unit, default_quantity, shopping_name
        )
        VALUES (?, ?, '', 1, ?)
    ")->execute([
        'atomic-decrement-2',
        'Unitless Fixture',
        'Fixture',
    ]);
    $unitlessProductId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO inventory (product_id, location, quantity)
        VALUES (?, 'dispensa', 2)
    ")->execute([$unitlessProductId]);
    $unitlessInventoryId = (int)$db->lastInsertId();
    $unitlessMismatch = decrementInventoryEntry(
        $db,
        $unitlessInventoryId,
        0.5,
        'kg'
    );
    inventoryUpdateTestAssert(
        $unitlessMismatch['success'] === false
        && $unitlessMismatch['status'] === 409
        && (float)$db->query("
            SELECT quantity FROM inventory
            WHERE id = {$unitlessInventoryId}
        ")->fetchColumn() === 2.0,
        'A supplied unit must not match an inventory item with no stored unit'
    );
    $unitlessResult = decrementInventoryEntry(
        $db,
        $unitlessInventoryId,
        0.5
    );
    inventoryUpdateTestAssert(
        $unitlessResult['success'] === true
        && (float)$unitlessResult['quantity'] === 1.5,
        'Omitting the optional unit must allow an atomic decrement'
    );

    $depleted = decrementInventoryEntry(
        $db,
        $inventoryId,
        100,
        'kg'
    );
    inventoryUpdateTestAssert(
        $depleted['success'] === true
        && (float)$depleted['used'] === 14.0
        && (float)$depleted['quantity'] === 0.0
        && (float)$db->query("
            SELECT quantity FROM inventory WHERE id = {$inventoryId}
        ")->fetchColumn() === 0.0,
        'An oversized decrement must consume only the available stock'
    );

    $missing = decrementInventoryEntry($db, 999999, 1);
    inventoryUpdateTestAssert(
        $missing['success'] === false
        && $missing['status'] === 404
        && $missing['error'] === 'inventory_not_found',
        'A missing inventory row must fail without creating stock'
    );

    echo 'Inventory update tests passed: '
        . $assertions
        . " assertions\n";
} finally {
    $db = null;
    foreach (glob($directory . '/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}
