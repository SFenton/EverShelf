#!/usr/bin/env php
<?php
declare(strict_types=1);

putenv('SHOPPING_MODE=internal');
putenv('INGREDIENT_ONTOLOGY_CONTROLLER_ENABLED=false');
define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/index.php';

$mode = $argv[1] ?? '';
if ($mode === '--concurrent-writer') {
    $db = new PDO('sqlite:' . (string)$argv[2]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    $GLOBALS['INVENTORY_ADD_INPUT'] = [
        'idempotency_key' => 'scan-concurrent-1',
        'product_id' => (int)$argv[3],
        'quantity' => 1,
        'location' => 'frigo',
        'expiry_date' => '2026-09-01',
        'expiry_user_set' => true,
    ];
    addToInventory($db);
    exit(0);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$call = static function (PDO $db, array $input): array {
    $GLOBALS['INVENTORY_ADD_INPUT'] = $input;
    http_response_code(200);
    ob_start();
    addToInventory($db);
    $body = (string)ob_get_clean();
    $response = json_decode($body, true);
    if (!is_array($response)) {
        throw new RuntimeException(
            'Inventory response was not JSON: ' . $body
        );
    }
    return [
        'status' => http_response_code(),
        'body' => $response,
    ];
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys = ON');
initializeDB($db);
migrateDB($db);
$db->exec("
    INSERT INTO products (
        barcode, name, unit, default_quantity, shopping_name
    )
    VALUES ('test-scan-1', 'Idempotent scan fixture', 'pz', 1, 'Fixture')
");
$productId = (int)$db->lastInsertId();
$payload = [
    'idempotency_key' => 'scan-test-1',
    'product_id' => $productId,
    'quantity' => 1,
    'location' => 'frigo',
    'expiry_date' => '2026-08-31',
    'expiry_user_set' => true,
];

$first = $call($db, $payload);
$second = $call($db, $payload);
$assert(
    $first['status'] === 200
    && $first['body']['success'] === true
    && $first['body']['replayed'] === false
    && (int)$first['body']['inventory_id'] > 0
    && $second['status'] === 200
    && $second['body']['success'] === true
    && $second['body']['replayed'] === true
    && $second['body']['inventory_id']
        === $first['body']['inventory_id'],
    'An identical scan retry must replay the committed success'
);
$assert(
    (float)$db->query("
        SELECT quantity FROM inventory
        WHERE product_id = {$productId}
    ")->fetchColumn() === 1.0
    && (int)$db->query("
        SELECT COUNT(*) FROM transactions
        WHERE product_id = {$productId} AND type = 'in'
    ")->fetchColumn() === 1
    && (int)$db->query("
        SELECT COUNT(*) FROM api_idempotency_receipts
        WHERE action = 'inventory_add'
          AND idempotency_key = 'scan-test-1'
    ")->fetchColumn() === 1,
    'A replay must not duplicate inventory, ledger, or receipt rows'
);

$conflict = $call($db, array_merge($payload, ['quantity' => 2]));
$assert(
    $conflict['status'] === 409
    && $conflict['body']['error'] === 'idempotency_key_reused'
    && (float)$db->query("
        SELECT quantity FROM inventory
        WHERE product_id = {$productId}
    ")->fetchColumn() === 1.0,
    'Reusing a key with a different payload must fail without mutation'
);

$invalid = $call($db, array_merge($payload, [
    'idempotency_key' => 'invalid key with spaces',
]));
$assert(
    $invalid['status'] === 400
    && $invalid['body']['error'] === 'invalid_idempotency_key',
    'Malformed idempotency keys must be rejected'
);

$concurrentDirectory = sys_get_temp_dir() . '/evershelf-scan-idempotency-'
    . getmypid() . '-' . bin2hex(random_bytes(4));
if (!mkdir($concurrentDirectory, 0770, true)) {
    throw new RuntimeException('Could not create concurrent scan test directory');
}
$concurrentPath = $concurrentDirectory . '/concurrent.db';
try {
    $concurrentDb = new PDO('sqlite:' . $concurrentPath);
    $concurrentDb->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );
    $concurrentDb->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );
    $concurrentDb->exec('PRAGMA foreign_keys = ON');
    initializeDB($concurrentDb);
    migrateDB($concurrentDb);
    $concurrentDb->exec("
        INSERT INTO products (
            barcode, name, unit, default_quantity, shopping_name
        )
        VALUES (
            'test-scan-concurrent', 'Concurrent scan fixture',
            'pz', 1, 'Fixture'
        )
    ");
    $concurrentProductId = (int)$concurrentDb->lastInsertId();
    $concurrentDb = null;

    $processes = [];
    for ($index = 0; $index < 2; $index++) {
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                __FILE__,
                '--concurrent-writer',
                $concurrentPath,
                (string)$concurrentProductId,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__)
        );
        if (!is_resource($process)) {
            throw new RuntimeException(
                'Could not start concurrent scan writer'
            );
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $concurrentResponses = [];
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException(
                'Concurrent scan writer failed: ' . $stderr
            );
        }
        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Concurrent scan response was invalid: ' . $stdout
            );
        }
        $concurrentResponses[] = $decoded;
    }
    $concurrentDb = new PDO('sqlite:' . $concurrentPath);
    $concurrentDb->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );
    $replayedValues = array_column(
        $concurrentResponses,
        'replayed'
    );
    sort($replayedValues);
    $assert(
        $replayedValues === [false, true]
        && (float)$concurrentDb->query("
            SELECT quantity FROM inventory
            WHERE product_id = {$concurrentProductId}
        ")->fetchColumn() === 1.0
        && (int)$concurrentDb->query("
            SELECT COUNT(*) FROM transactions
            WHERE product_id = {$concurrentProductId} AND type = 'in'
        ")->fetchColumn() === 1,
        'Concurrent identical scans must serialize into one write and one replay'
    );
    $concurrentDb = null;
} finally {
    foreach (glob($concurrentDirectory . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($concurrentDirectory);
}

unset($GLOBALS['INVENTORY_ADD_INPUT']);
echo "Scan idempotency tests passed: {$assertions} assertions\n";
