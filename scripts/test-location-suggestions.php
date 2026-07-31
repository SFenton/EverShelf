<?php

require_once __DIR__ . '/../api/lib/location_suggestions.php';

function env(string $key, string $default = ''): string {
    return $default;
}

function assertSameValue($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("
    CREATE TABLE products (
        id INTEGER PRIMARY KEY,
        barcode TEXT,
        name TEXT NOT NULL,
        last_location TEXT,
        last_location_at DATETIME,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        quantity REAL NOT NULL,
        location TEXT NOT NULL,
        notes TEXT DEFAULT '',
        undone INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE inventory (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        location TEXT NOT NULL,
        quantity REAL NOT NULL,
        added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE location_suggestion_cache (
        cache_key TEXT PRIMARY KEY,
        location TEXT NOT NULL,
        confidence REAL NOT NULL DEFAULT 0,
        reason TEXT DEFAULT '',
        model TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE product_location_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        location TEXT NOT NULL,
        source TEXT NOT NULL,
        transaction_id INTEGER UNIQUE,
        occurred_at DATETIME NOT NULL,
        undone INTEGER NOT NULL DEFAULT 0
    );
");

$insertProduct = $db->prepare("
    INSERT INTO products (id, barcode, name, last_location, last_location_at)
    VALUES (?, ?, ?, ?, ?)
");
$insertProduct->execute([1, '111', 'Milk', 'frigo', '2026-01-01 10:00:00']);
$insertProduct->execute([2, '222', 'MILK', 'freezer', '2026-02-01 10:00:00']);
$insertProduct->execute([3, '333', 'Café Beans', 'dispensa', '2026-03-01 10:00:00']);
$insertProduct->execute([4, '444', 'Soup', 'cabinet', '2025-01-01 10:00:00']);
$insertProduct->execute([5, '555', 'Bread', null, null]);
$insertProduct->execute([6, '666', 'Preserved', 'spice_rack', '2024-01-01 10:00:00']);
$insertProduct->execute([7, '777', 'Moved item', null, null]);

assertSameValue('freezer', manualNameLocationHistory($db, ' milk ')['location'], 'Latest exact name wins');
assertSameValue(2, manualNameLocationHistory($db, 'milk')['product_id'], 'Name tie-break uses latest product');
assertSameValue('dispensa', manualNameLocationHistory($db, 'CAFÉ BEANS')['location'], 'Unicode case folding works');
assertSameValue(null, manualNameLocationHistory($db, 'Milks'), 'Name lookup remains exact');

$db->exec("INSERT INTO transactions (product_id, type, quantity, location, undone, created_at) VALUES (4, 'in', 1, 'dispensa', 0, '2026-01-01 10:00:00')");
$firstTransactionId = (int)$db->lastInsertId();
recordProductLocation($db, 4, 'dispensa', 'inventory_add', '2026-01-01 10:00:00', $firstTransactionId);
$db->exec("INSERT INTO transactions (product_id, type, quantity, location, undone, created_at) VALUES (4, 'in', 1, 'frigo', 1, '2026-02-01 10:00:00')");
$undoneTransactionId = (int)$db->lastInsertId();
recordProductLocation($db, 4, 'frigo', 'inventory_add', '2026-02-01 10:00:00', $undoneTransactionId);
undoProductLocationTransaction($db, $undoneTransactionId);
assertSameValue('dispensa', refreshProductLastLocation($db, 4)['location'], 'Undone inbound is ignored');

$db->exec("
    INSERT INTO inventory (product_id, location, quantity, added_at)
    VALUES (5, 'frigo', 1, '2026-04-01 10:00:00')
");
assertSameValue('frigo', refreshProductLastLocation($db, 5)['location'], 'Inventory backfills when ledger is empty');
assertSameValue('spice_rack', refreshProductLastLocation($db, 6)['location'], 'Missing evidence preserves durable history');
recordProductLocation($db, 7, 'dispensa', 'inventory_add', '2026-01-01 10:00:00', 7001);
recordProductLocation($db, 7, 'frigo', 'inventory_move', '2026-03-01 10:00:00');
assertSameValue('frigo', refreshProductLastLocation($db, 7)['location'], 'A later move stays ahead of an older add');
recordProductLocation($db, 7, 'freezer', 'inventory_add', '2026-04-01 10:00:00', 7002);
undoProductLocationTransaction($db, 7002);
assertSameValue('frigo', refreshProductLastLocation($db, 7)['location'], 'Undo restores the prior location event');

$parsed = parseLocationSuggestionModelText('{"location":"frigo","confidence":0.9,"reason":"Fresh dairy"}');
assertSameValue('frigo', $parsed['location'], 'Valid model location is accepted');
$lowConfidence = parseLocationSuggestionModelText('{"location":"frigo","confidence":0.2}');
assertSameValue('unknown', $lowConfidence['location'], 'Low-confidence answer becomes unknown');
assertSameValue(null, parseLocationSuggestionModelText('{"location":"altro","confidence":1}'), 'AI cannot choose altro');
assertSameValue(null, parseLocationSuggestionModelText('{"location":"frigo"}'), 'Missing confidence triggers fallback');

$cacheKey = locationSuggestionCacheKey('Milk', 'Dairy', ['gemini-3.6-flash']);
cacheLocationSuggestion($db, $cacheKey, [
    'location' => 'frigo',
    'confidence' => 0.95,
    'reason' => 'Fresh dairy',
    'model' => 'gemini-3.6-flash',
]);
$cached = cachedLocationSuggestion($db, $cacheKey);
assertSameValue('frigo', $cached['location'], 'Cached location round-trips');
assertSameValue('gemini_cache', $cached['source'], 'Cache source is explicit');
assertSameValue(1, LOCATION_SUGGESTION_MAX_ATTEMPTS, 'Interactive location AI never retries the same model');
assertSameValue(5, LOCATION_SUGGESTION_TIMEOUT_SECONDS, 'Interactive location AI timeout stays bounded');

echo "Location suggestion tests passed.\n";
