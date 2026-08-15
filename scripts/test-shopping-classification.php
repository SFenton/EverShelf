#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/index.php';

$assertions = 0;
function shoppingTestAssert(bool $condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function shoppingTestSave(PDO $db, array $input): array {
    $GLOBALS['PRODUCT_API_JSON_INPUT'] = $input;
    ob_start();
    try {
        saveProduct($db);
        $response = json_decode((string)ob_get_clean(), true);
    } finally {
        unset($GLOBALS['PRODUCT_API_JSON_INPUT']);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
    if (!is_array($response ?? null)) {
        throw new RuntimeException('Product save returned invalid JSON');
    }
    return $response;
}

function shoppingTestInsertDeterministicProduct(
    PDO $db,
    string $name,
    string $brand = 'Test Brand',
    string $category = 'Test Category',
    int $preparedFood = 0
): int {
    $shoppingName = computeShoppingName(
        $name,
        $category,
        $brand,
        false
    );
    $db->prepare("
        INSERT INTO products (
            name, brand, category, shopping_name, prepared_food
        )
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $name,
        $brand,
        $category,
        $shoppingName,
        $preparedFood,
    ]);
    $id = (int)$db->lastInsertId();
    $db->exec('BEGIN IMMEDIATE');
    try {
        shoppingClassificationRecordProductIntent(
            $db,
            $id,
            'deterministic'
        );
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->exec('ROLLBACK');
        }
        throw $error;
    }
    return $id;
}

function shoppingTestProduct(PDO $db, int $id): array {
    $stmt = $db->prepare("
        SELECT *
        FROM products
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function shoppingTestQueue(PDO $db, int $id): array {
    $stmt = $db->prepare("
        SELECT *
        FROM shopping_classification_queue
        WHERE product_id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function shoppingTestCleanupCache(string $path): void {
    $paths = [$path, $path . '.lock'];
    foreach ([
        $path . '.key-*.lock',
        $path . '.write.*.lock',
    ] as $pattern) {
        $matches = glob($pattern);
        if (is_array($matches)) {
            $paths = array_merge($paths, $matches);
        }
    }
    foreach (array_unique($paths) as $candidate) {
        if (is_file($candidate)) {
            unlink($candidate);
        }
    }
}

$dbPath = __DIR__ . '/../data/.shopping-classification-queue-test-'
    . getmypid() . '.sqlite';
$cachePath = __DIR__ . '/../data/.shopping-classification-queue-cache-'
    . getmypid() . '.json';
$cleanup = [
    $dbPath,
    $dbPath . '-wal',
    $dbPath . '-shm',
    dirname($dbPath) . '/.' . basename($dbPath) . '.recipe-score.lock',
];

try {
    foreach ($cleanup as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    shoppingTestCleanupCache($cachePath);
    $GLOBALS['SHOPPING_CLASSIFICATION_CACHE_PATH'] = $cachePath;
    $GLOBALS['SHOPPING_CLASSIFICATION_MODEL'] = 'gemini-3.7-flash';

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    initializeDB($db);
    migrateDB($db);
    shoppingClassificationSchemaMigrate($db);
    shoppingClassificationSchemaMigrate($db);

    $productColumns = array_column(
        $db->query("PRAGMA table_info(products)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    shoppingTestAssert(
        in_array('shopping_name_provenance', $productColumns, true)
        && in_array('shopping_name_fingerprint', $productColumns, true)
        && (bool)$db->query("
            SELECT 1
            FROM sqlite_master
            WHERE type = 'table'
              AND name = 'shopping_classification_queue'
        ")->fetchColumn(),
        'Helper-owned shopping classification migration must be idempotent'
    );

    $sourCreamFixture = [
        'product_name' => 'Daisy Sour Cream 16 oz Tub',
        'brand' => 'Daisy',
        'category' => 'Dairy',
        'model_output' => 'Panna Acidula!!!',
        'expected' => 'Panna acida',
    ];
    $genericAliases = shoppingClassificationKnownGenericAliases();
    $retailAliasKeys = array_filter(
        array_keys($genericAliases),
        static fn(string $alias): bool =>
            str_contains($alias, 'daisy')
            || str_contains($alias, 'oz')
            || str_contains($alias, 'tub')
    );
    shoppingTestAssert(
        shoppingClassificationSanitizeResult(
            $sourCreamFixture['model_output']
        ) === $sourCreamFixture['expected']
        && shoppingClassificationSanitizeResult('Sour Cream')
            === $sourCreamFixture['expected']
        && computeShoppingName(
            $sourCreamFixture['product_name'],
            $sourCreamFixture['category'],
            $sourCreamFixture['brand'],
            false
        ) === $sourCreamFixture['expected']
        && shoppingClassificationSanitizeResult(
            'Daisy Sour Cream'
        ) === 'Daisy Sour Cream'
        && $retailAliasKeys === [],
        'Generic sour-cream synonyms must normalize to Panna acida without storing retail brand or package text as aliases'
    );

    $transportCalls = 0;
    $GLOBALS['SHOPPING_CLASSIFICATION_TRANSPORT'] =
        static function () use (&$transportCalls): array {
            $transportCalls++;
            throw new RuntimeException(
                'synchronous_transport_call_forbidden'
            );
        };
    $saveStarted = microtime(true);
    $autoSave = shoppingTestSave($db, [
        'name' => 'Async Nebula Reserve',
        'brand' => 'Test Brand',
        'category' => 'Test Category',
    ]);
    $saveElapsed = microtime(true) - $saveStarted;
    $autoProduct = shoppingTestProduct(
        $db,
        (int)$autoSave['id']
    );
    $autoQueue = shoppingTestQueue($db, (int)$autoSave['id']);
    shoppingTestAssert(
        !empty($autoSave['success'])
        && $transportCalls === 0
        && $saveElapsed < 2.0
        && $autoProduct['shopping_name'] === 'Async'
        && $autoProduct['shopping_name_provenance']
            === 'deterministic'
        && strlen($autoProduct['shopping_name_fingerprint']) === 64
        && $autoQueue['status'] === 'pending'
        && hash_equals(
            $autoProduct['shopping_name_fingerprint'],
            $autoQueue['owner_fingerprint']
        ),
        'Product save must return deterministic data immediately and enqueue transactionally without Copilot'
    );

    $explicitSave = shoppingTestSave($db, [
        'id' => (int)$autoSave['id'],
        'name' => 'Async Nebula Reserve',
        'brand' => 'Test Brand',
        'category' => 'Test Category',
        'shopping_name' => 'Scelta utente',
    ]);
    $explicitProduct = shoppingTestProduct(
        $db,
        (int)$explicitSave['id']
    );
    $explicitQueue = shoppingTestQueue(
        $db,
        (int)$explicitSave['id']
    );
    shoppingTestAssert(
        $transportCalls === 0
        && $explicitProduct['shopping_name'] === 'Scelta utente'
        && $explicitProduct['shopping_name_provenance'] === 'explicit'
        && $explicitQueue['status'] === 'cancelled'
        && $explicitQueue['last_error'] === 'explicit_shopping_name'
        && hash_equals(
            $explicitProduct['shopping_name_fingerprint'],
            $explicitQueue['owner_fingerprint']
        ),
        'Explicit shopping name must supersede and fence pending work'
    );

    shoppingTestSave($db, [
        'id' => (int)$autoSave['id'],
        'name' => 'Async Nebula Reserve Updated',
        'brand' => 'Test Brand',
        'category' => 'Test Category',
    ]);
    $preservedExplicitProduct = shoppingTestProduct(
        $db,
        (int)$autoSave['id']
    );
    $preservedExplicitQueue = shoppingTestQueue(
        $db,
        (int)$autoSave['id']
    );
    shoppingTestAssert(
        $preservedExplicitProduct['shopping_name'] === 'Scelta utente'
        && $preservedExplicitProduct['shopping_name_provenance']
            === 'explicit'
        && $preservedExplicitQueue['status'] === 'cancelled',
        'Omitting shopping_name on an update must preserve an existing explicit value'
    );

    $preparedSave = shoppingTestSave($db, [
        'name' => 'Prepared Nebula Meal',
        'brand' => 'Test Brand',
        'category' => 'Prepared food',
        'prepared_food' => true,
    ]);
    $preparedProduct = shoppingTestProduct(
        $db,
        (int)$preparedSave['id']
    );
    $preparedQueue = shoppingTestQueue(
        $db,
        (int)$preparedSave['id']
    );
    shoppingTestAssert(
        $transportCalls === 0
        && (int)$preparedProduct['prepared_food'] === 1
        && $preparedProduct['shopping_name_provenance']
            === 'deterministic'
        && $preparedQueue === [],
        'Prepared products must stay deterministic and never enqueue'
    );

    $dedupeId = shoppingTestInsertDeterministicProduct(
        $db,
        'Queue Dedupe Reserve'
    );
    $firstDedupeQueue = shoppingTestQueue($db, $dedupeId);
    $db->exec('BEGIN IMMEDIATE');
    shoppingClassificationRecordProductIntent(
        $db,
        $dedupeId,
        'deterministic'
    );
    $db->exec('COMMIT');
    $secondDedupeQueue = shoppingTestQueue($db, $dedupeId);
    shoppingTestAssert(
        (int)$db->query("
            SELECT COUNT(*)
            FROM shopping_classification_queue
            WHERE product_id = {$dedupeId}
        ")->fetchColumn() === 1
        && (int)$secondDedupeQueue['lease_generation']
            > (int)$firstDedupeQueue['lease_generation']
        && $secondDedupeQueue['status'] === 'pending',
        'Repeated intent for one product must deduplicate and advance its fence'
    );

    $firstClaim = shoppingClassificationClaimOne($db);
    shoppingTestAssert(
        (int)($firstClaim['product_id'] ?? 0) === $dedupeId
        && $firstClaim['status'] === 'leased'
        && (int)$firstClaim['attempts'] === 1,
        'Queue claim must durably lease one product'
    );
    $db->prepare("
        UPDATE shopping_classification_queue
        SET lease_expires_at = datetime('now', '-1 second')
        WHERE product_id = ?
    ")->execute([$dedupeId]);
    $recoveredClaim = shoppingClassificationClaimOne($db);
    shoppingTestAssert(
        (int)($recoveredClaim['product_id'] ?? 0) === $dedupeId
        && (int)$recoveredClaim['attempts'] === 2
        && (int)$recoveredClaim['lease_generation']
            > (int)$firstClaim['lease_generation']
        && $recoveredClaim['lease_token'] !== $firstClaim['lease_token'],
        'Expired lease must be crash-recoverable with a new token and generation'
    );
    shoppingClassificationCancelClaim(
        $db,
        $recoveredClaim,
        'test_complete'
    );

    $driftId = shoppingTestInsertDeterministicProduct(
        $db,
        'Fingerprint Drift Reserve'
    );
    $driftClaim = shoppingClassificationClaimOne($db);
    $db->prepare("
        UPDATE products
        SET name = 'Fingerprint Drift Changed'
        WHERE id = ?
    ")->execute([$driftId]);
    $driftApply = shoppingClassificationApplyClaim(
        $db,
        $driftClaim,
        'Should not apply'
    );
    shoppingTestAssert(
        (int)$driftClaim['product_id'] === $driftId
        && $driftApply['status'] === 'stale'
        && shoppingTestProduct($db, $driftId)['shopping_name']
            !== 'Should not apply'
        && shoppingTestQueue($db, $driftId)['status'] === 'cancelled',
        'Apply must recompute the current owner fingerprint and reject unfenced product drift'
    );

    $staleId = shoppingTestInsertDeterministicProduct(
        $db,
        'Stale Result Reserve'
    );
    $staleTransportCalls = 0;
    $GLOBALS['SHOPPING_CLASSIFICATION_TRANSPORT'] =
        static function (
            array $request,
            float $deadline
        ) use (
            $db,
            $staleId,
            &$staleTransportCalls
        ): array {
            $staleTransportCalls++;
            shoppingTestAssert(
                ($request['model'] ?? '') === 'gemini-3.7-flash'
                && ($request['schema']['additionalProperties'] ?? null)
                    === false
                && $deadline - microtime(true) <= 15.0,
                'Worker request must use strict authorized Gemini with a bounded deadline'
            );
            $db->exec('BEGIN IMMEDIATE');
            $db->prepare("
                UPDATE products
                SET shopping_name = 'Manual override'
                WHERE id = ?
            ")->execute([$staleId]);
            shoppingClassificationRecordProductIntent(
                $db,
                $staleId,
                'explicit'
            );
            $db->exec('COMMIT');
            return [
                'source' => 'copilot_socket',
                'envelope' => ['shopping_name' => 'Stale model value'],
            ];
        };
    $staleRun = shoppingClassificationProcessQueue($db, 1);
    $staleProduct = shoppingTestProduct($db, $staleId);
    $staleQueue = shoppingTestQueue($db, $staleId);
    shoppingTestAssert(
        $staleTransportCalls === 1
        && $staleRun['stale'] === 1
        && $staleProduct['shopping_name'] === 'Manual override'
        && $staleProduct['shopping_name_provenance'] === 'explicit'
        && $staleQueue['status'] === 'cancelled',
        'Result application must reject an explicit update that supersedes an in-flight lease'
    );

    $successId = shoppingTestInsertDeterministicProduct(
        $db,
        $sourCreamFixture['product_name'],
        $sourCreamFixture['brand'],
        $sourCreamFixture['category']
    );
    $successCalls = 0;
    $GLOBALS['SHOPPING_CLASSIFICATION_TRANSPORT'] =
        static function (
            array $request,
            float $deadline
        ) use (&$successCalls, $sourCreamFixture): array {
            $successCalls++;
            shoppingTestAssert(
                ($request['protocol_version'] ?? '')
                    === 'evershelf-ontology-copilot-v1'
                && ($request['role'] ?? '') === 'proposer'
                && ($request['model'] ?? '') === 'gemini-3.7-flash'
                && ($request['schema']['required'] ?? [])
                    === ['shopping_name']
                && str_contains(
                    (string)$request['prompt'],
                    '"sour cream":"Panna acida"'
                )
                && str_contains(
                    (string)$request['prompt'],
                    'non trasformare marca, formato o testo della confezione'
                )
                && $deadline - microtime(true) > 0
                && $deadline - microtime(true) <= 15.0,
                'Background worker must use the strict Copilot Gemini envelope'
            );
            return [
                'source' => 'copilot_socket',
                'envelope' => [
                    'shopping_name' =>
                        $sourCreamFixture['model_output'],
                ],
            ];
        };
    $successRun = shoppingClassificationProcessQueue($db, 1);
    $successProduct = shoppingTestProduct($db, $successId);
    $successQueue = shoppingTestQueue($db, $successId);
    shoppingTestAssert(
        $successCalls === 1
        && $successRun['applied'] === 1
        && $successRun['model_calls'] === 1
        && $successProduct['shopping_name']
            === $sourCreamFixture['expected']
        && $successProduct['shopping_name_provenance'] === 'copilot'
        && strlen($successProduct['shopping_name_fingerprint']) === 64
        && $successQueue['status'] === 'succeeded',
        'Successful worker output must persist with Copilot provenance'
    );

    $cacheReuseId = shoppingTestInsertDeterministicProduct(
        $db,
        $sourCreamFixture['product_name'],
        $sourCreamFixture['brand'],
        $sourCreamFixture['category']
    );
    $GLOBALS['SHOPPING_CLASSIFICATION_TRANSPORT'] =
        static function () use (&$successCalls): array {
            $successCalls++;
            throw new RuntimeException('cache_reuse_failed');
        };
    $cacheRun = shoppingClassificationProcessQueue($db, 1);
    $cacheProduct = shoppingTestProduct($db, $cacheReuseId);
    shoppingTestAssert(
        $successCalls === 1
        && $cacheRun['applied'] === 1
        && $cacheRun['cached'] === 1
        && $cacheRun['model_calls'] === 0
        && $cacheProduct['shopping_name']
            === $sourCreamFixture['expected']
        && $cacheProduct['shopping_name_provenance'] === 'copilot',
        'Durable successful cache must be reused without a second model call'
    );

    $failureIds = [];
    for ($i = 1; $i <= 3; $i++) {
        $failureIds[] = shoppingTestInsertDeterministicProduct(
            $db,
            "Circuit Failure {$i} Reserve"
        );
    }
    $failureCalls = 0;
    $GLOBALS['SHOPPING_CLASSIFICATION_TRANSPORT'] =
        static function () use (&$failureCalls): array {
            $failureCalls++;
            throw new RuntimeException(
                'controller_copilot_socket_timeout'
            );
        };
    $circuitRun = shoppingClassificationProcessQueue($db, 5);
    shoppingTestAssert(
        $failureCalls === SHOPPING_CLASSIFICATION_QUEUE_CIRCUIT_LIMIT
        && $circuitRun['claimed']
            === SHOPPING_CLASSIFICATION_QUEUE_CIRCUIT_LIMIT
        && $circuitRun['retried']
            === SHOPPING_CLASSIFICATION_QUEUE_CIRCUIT_LIMIT
        && $circuitRun['circuit_open'] === true,
        'Worker must stop at the hard consecutive model-failure circuit limit'
    );

    $negativeId = $failureIds[0];
    $negativeBefore = shoppingTestQueue($db, $negativeId);
    $db->prepare("
        UPDATE shopping_classification_queue
        SET status = 'retry',
            next_retry_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
    ")->execute([$negativeId]);
    $negativeRun = shoppingClassificationProcessQueue($db, 1);
    $negativeAfter = shoppingTestQueue($db, $negativeId);
    shoppingTestAssert(
        $failureCalls === SHOPPING_CLASSIFICATION_QUEUE_CIRCUIT_LIMIT
        && $negativeRun['cached'] === 1
        && $negativeRun['model_calls'] === 0
        && $negativeAfter['status'] === 'retry'
        && (int)$negativeAfter['attempts']
            === (int)$negativeBefore['attempts'] + 1
        && strtotime((string)$negativeAfter['next_retry_at'])
            >= time() + SHOPPING_CLASSIFICATION_FAILURE_TTL_SECONDS - 5,
        'Negative cache must suppress transport and defer retry until its TTL'
    );

    $terminalId = shoppingTestInsertDeterministicProduct(
        $db,
        'Terminal Failure Reserve'
    );
    $db->prepare("
        UPDATE shopping_classification_queue
        SET attempts = ?,
            status = 'pending',
            next_retry_at = NULL
        WHERE product_id = ?
    ")->execute([
        SHOPPING_CLASSIFICATION_QUEUE_MAX_ATTEMPTS - 1,
        $terminalId,
    ]);
    $terminalRun = shoppingClassificationProcessQueue($db, 1);
    $terminalQueue = shoppingTestQueue($db, $terminalId);
    shoppingTestAssert(
        $terminalRun['failed'] === 1
        && (int)$terminalQueue['attempts']
            === SHOPPING_CLASSIFICATION_QUEUE_MAX_ATTEMPTS
        && $terminalQueue['status'] === 'failed'
        && $terminalQueue['next_retry_at'] === null,
        'Queue must stop permanently at the hard attempt limit'
    );

    $classifierSource = file_get_contents(
        __DIR__ . '/../api/lib/shopping_classification.php'
    );
    $indexSource = file_get_contents(__DIR__ . '/../api/index.php');
    $workerSource = file_get_contents(
        __DIR__ . '/process-shopping-classification-queue.php'
    );
    $saveStart = strpos(
        (string)$indexSource,
        'function productSaveShoppingName('
    );
    $saveEnd = strpos(
        (string)$indexSource,
        'function saveProduct(',
        (int)$saveStart
    );
    $saveSource = (
        is_int($saveStart)
        && is_int($saveEnd)
        && $saveEnd > $saveStart
    )
        ? substr(
            (string)$indexSource,
            $saveStart,
            $saveEnd - $saveStart
        )
        : '';
    shoppingTestAssert(
        is_string($classifierSource)
        && is_string($workerSource)
        && $saveSource !== ''
        && !str_contains($classifierSource, 'GEMINI_API_KEY')
        && !str_contains($classifierSource, 'callGeminiWithFallback')
        && !str_contains($workerSource, 'GEMINI_API_KEY')
        && !str_contains($workerSource, 'callGeminiWithFallback')
        && !str_contains($saveSource, 'shoppingClassificationTransport')
        && !str_contains($saveSource, 'evershelfCopilotSocketCall')
        && str_contains($saveSource, 'false'),
        'Shopping save and worker paths must never use the direct Gemini key API or synchronous Copilot'
    );

    $cacheDocument = shoppingClassificationLoadCache();
    shoppingTestAssert(
        ($cacheDocument['version'] ?? 0)
            === SHOPPING_CLASSIFICATION_CACHE_VERSION
        && glob($cachePath . '.write.*.lock') === [],
        'Worker cache must remain valid version-2 JSON without temp artifacts'
    );

    echo "Shopping classification queue tests passed: {$assertions} assertions\n";
} finally {
    unset(
        $GLOBALS['PRODUCT_API_JSON_INPUT'],
        $GLOBALS['PRODUCT_SAVE_TEST_HOOK'],
        $GLOBALS['SHOPPING_CLASSIFICATION_CACHE_PATH'],
        $GLOBALS['SHOPPING_CLASSIFICATION_MODEL'],
        $GLOBALS['SHOPPING_CLASSIFICATION_TRANSPORT']
    );
    shoppingTestCleanupCache($cachePath);
    foreach ($cleanup as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
