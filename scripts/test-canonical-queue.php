#!/usr/bin/env php
<?php
declare(strict_types=1);

$mode = (string)($argv[1] ?? '');
if ($mode === '--hold-lock') {
    $db = new PDO('sqlite:' . (string)$argv[2]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('BEGIN IMMEDIATE');
    echo "locked\n";
    flush();
    usleep(max(1000, (int)($argv[3] ?? 250000)));
    $db->exec('ROLLBACK');
    exit(0);
}
if ($mode === '--writer-loop') {
    $db = new PDO('sqlite:' . (string)$argv[2]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout=5000');
    $iterations = max(1, (int)($argv[3] ?? 100));
    $holdUs = max(100, (int)($argv[4] ?? 2000));
    $pauseUs = max(100, (int)($argv[5] ?? 10000));
    echo "ready\n";
    flush();
    for ($index = 0; $index < $iterations; $index++) {
        $db->exec('BEGIN IMMEDIATE');
        usleep($holdUs);
        $db->exec('COMMIT');
        usleep($pauseUs);
    }
    exit(0);
}
if ($mode === '--cache-store') {
    define('CRON_MODE', true);
    define('RECIPE_BACKEND_TEST_MODE', true);
    require_once __DIR__ . '/../api/bootstrap.php';
    $GLOBALS['CANONICAL_FOODON_TEST_CACHE_PATH'] =
        (string)$argv[2];
    $GLOBALS['CANONICAL_USDA_TEST_CACHE_PATH'] =
        (string)$argv[3];
    canonicalIngredientFoodOnCacheStore(
        (string)$argv[4],
        ['ts' => time(), 'found' => false, 'writer' => (string)$argv[5]]
    );
    exit(0);
}

$tempParent = dirname(__DIR__) . '/data/tmp';
$createdTempParent = false;
$root = $tempParent . '/canonical-queue-test-'
    . getmypid() . '-' . bin2hex(random_bytes(4));
if (!is_dir($tempParent)) {
    if (!mkdir($tempParent, 0770, true)) {
        throw new RuntimeException(
            'Canonical test temp root is unavailable'
        );
    }
    $createdTempParent = true;
}
if (!mkdir($root, 0770, true)) {
    throw new RuntimeException('Canonical test directory is unavailable');
}

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
putenv('LOG_DIR=' . $root . '/logs');
putenv('ONTOLOGY_AUTONOMOUS_ENABLED=false');
putenv('INGREDIENT_ONTOLOGY_CONTROLLER_ENABLED=false');
putenv('USDA_FDC_ENABLED=true');
putenv('USDA_FDC_API_KEY=test-only');
putenv('USDA_FDC_LOOKUP_ON_SAVE=true');
putenv('FOODON_ENABLED=true');
putenv('FOODON_LOOKUP_ON_SAVE=true');
putenv('SHOPPING_MODE=internal');
putenv('BRING_EMAIL=');
putenv('BRING_PASSWORD=');
putenv('HA_ENABLED=false');
putenv('CANONICAL_QUEUE_MAX_ATTEMPTS=3');
putenv('CANONICAL_QUEUE_BUSY_TIMEOUT_MS=20');
putenv('FOODON_HIERARCHY_FAILURE_CACHE_TTL_SECONDS=900');
putenv('CANONICAL_QUEUE_STALE_DUE_SECONDS=300');
require_once __DIR__ . '/../api/index.php';

$GLOBALS['CANONICAL_FOODON_TEST_CACHE_PATH'] =
    $root . '/foodon.json';
$GLOBALS['CANONICAL_USDA_TEST_CACHE_PATH'] =
    $root . '/usda.json';
$GLOBALS['CANONICAL_QUEUE_TEST_RETRY_BUDGET_MS'] = [
    'apply' => 2500,
    'release' => 2500,
    'claim' => 2500,
];
$GLOBALS['CANONICAL_QUEUE_TEST_BUSY_DELAY_US'] = 10000;
$GLOBALS['CANONICAL_QUEUE_TEST_WAKE'] = static fn(): bool => true;

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

putenv('CANONICAL_QUEUE_CRASH_LEASE_SECONDS');
putenv('CANONICAL_QUEUE_LEASE_SECONDS');
$assert(
    canonicalIngredientCrashLeaseSeconds() === 120,
    'Absent crash-lease configuration must default to 120 seconds'
);
putenv('CANONICAL_QUEUE_LEASE_SECONDS=600');
$assert(
    canonicalIngredientCrashLeaseSeconds() === 120,
    'The deprecated sample value 600 must upgrade to 120 seconds'
);
putenv('CANONICAL_QUEUE_LEASE_SECONDS=75');
$assert(
    canonicalIngredientCrashLeaseSeconds() === 75,
    'A non-default legacy crash lease must remain compatible'
);
putenv('CANONICAL_QUEUE_CRASH_LEASE_SECONDS=600');
$assert(
    canonicalIngredientCrashLeaseSeconds() === 600,
    'A deliberate 600-second lease must use the new key'
);
putenv('CANONICAL_QUEUE_CRASH_LEASE_SECONDS');
putenv('CANONICAL_QUEUE_LEASE_SECONDS');
$assert(
    canonicalIngredientApplyReserveSeconds(120) === 30,
    'The default 120-second lease must reserve 30 seconds for apply'
);
$defaultProviderDeadline =
    canonicalIngredientProviderDeadlineFromLease(
        gmdate('Y-m-d H:i:s', time() + 120),
        120
    );
$defaultProviderRemaining =
    canonicalIngredientProviderRemainingSeconds(
        $defaultProviderDeadline
    );
$assert(
    $defaultProviderRemaining !== null
    && $defaultProviderRemaining >= 88
    && $defaultProviderRemaining <= 90,
    'The default provider phase must reserve about 90 of 120 seconds'
);
putenv('CANONICAL_QUEUE_CRASH_LEASE_SECONDS=30');

$open = static function (string $path): PDO {
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=1000');
    initializeDB($db);
    migrateDB($db);
    return $db;
};

$insertProduct = static function (
    PDO $db,
    string $name
): int {
    $stmt = $db->prepare("
        INSERT INTO products (name, brand, category, prepared_food)
        VALUES (?, '', '', 0)
    ");
    $stmt->execute([$name]);
    return (int)$db->lastInsertId();
};

$prepared = static function (
    int $productId,
    string $slug
): array {
    $name = canonicalIngredientTitle(str_replace('-', ' ', $slug));
    return [
        'product_id' => $productId,
        'mapped' => 1,
        'mappings' => [[
            'slug' => $slug,
            'name' => $name,
            'role' => 'primary',
            'confidence' => 0.9,
            'source' => 'canonical_test',
            'evidence' => 'canonical queue test',
            'category' => '',
            'parent_slug' => null,
            'external_ids' => [],
        ]],
        'tags' => [[
            'facet' => 'canonical-primary',
            'value' => $slug,
            'source' => 'canonical',
            'confidence' => 0.99,
            'evidence' => 'canonical queue test',
        ]],
        'decision' => 'test',
        'decision_detail' => [],
        '_apply_canonical' => true,
        '_product_exists' => true,
    ];
};

$row = static function (PDO $db, int $productId): array {
    $stmt = $db->prepare("
        SELECT * FROM canonical_processing_queue WHERE product_id = ?
    ");
    $stmt->execute([$productId]);
    $value = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stmt->closeCursor();
    return $value;
};

$mappingSlugs = static function (PDO $db, int $productId): array {
    $stmt = $db->prepare("
        SELECT ingredient.slug
        FROM product_ingredients mapping
        JOIN canonical_ingredients ingredient
          ON ingredient.id = mapping.ingredient_id
        WHERE mapping.product_id = ?
          AND mapping.source != 'manual'
        ORDER BY ingredient.slug
    ");
    $stmt->execute([$productId]);
    $values = array_map(
        'strval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );
    $stmt->closeCursor();
    return $values;
};

$jobCount = static function (PDO $db, int $productId): int {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM recipe_jobs
        WHERE idempotency_key = ?
    ");
    $stmt->execute(['taxonomy_ready:product:' . $productId]);
    $count = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    return $count;
};

$forceDue = static function (PDO $db, int $productId): void {
    $db->prepare("
        UPDATE canonical_processing_queue
        SET next_retry_at = datetime('now', '-1 second')
        WHERE product_id = ?
    ")->execute([$productId]);
};

$startChild = static function (array $command): array {
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        $command,
        $descriptor,
        $pipes,
        dirname(__DIR__),
        ['TMPDIR' => dirname(__DIR__) . '/data/tmp']
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Canonical test child failed to start');
    }
    fclose($pipes[0]);
    return [$process, $pipes];
};

$finishChild = static function ($process, array $pipes): void {
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException(
            'Canonical test child failed: ' . trim((string)$stderr)
        );
    }
};

$databasePath = $root . '/canonical.sqlite';
$statusPath = $root . '/status.sqlite';
$benchmarkPath = $root . '/benchmark.sqlite';

try {
    $db = $open($databasePath);

    $columns = array_column(
        $db->query(
            'PRAGMA table_info(canonical_processing_queue)'
        )->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    $assert(
        in_array('next_retry_at', $columns, true)
        && in_array('last_error_kind', $columns, true),
        'Canonical queue migration must add retry scheduling columns'
    );

    $clampProduct = $insertProduct($db, 'Lease clamp proof');
    canonicalIngredientEnqueueProduct(
        $db,
        $clampProduct,
        'lease_clamp'
    );
    $clampToken = str_repeat('d', 64);
    $db->prepare("
        UPDATE canonical_processing_queue
        SET status = 'in_progress',
            attempts = 1,
            lease_token = ?,
            lease_generation = 1,
            lease_expires_at = datetime('now', '+600 seconds'),
            started_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
    ")->execute([$clampToken, $clampProduct]);
    $clampResult =
        canonicalIngredientProcessQueueBatch($db, 1, 3);
    $clampedRow = $row($db, $clampProduct);
    $clampedRemaining =
        strtotime((string)$clampedRow['lease_expires_at']) - time();
    $assert(
        $clampResult['clamped_leases'] === 1
        && $clampedRow['status'] === 'in_progress'
        && $clampedRow['lease_token'] === $clampToken
        && $clampedRemaining >= 28
        && $clampedRemaining <= 31,
        'Inherited ten-minute claims must retain their token but clamp to the configured crash lease'
    );
    $db->prepare(
        'DELETE FROM canonical_processing_queue WHERE product_id = ?'
    )->execute([$clampProduct]);
    $db->prepare('DELETE FROM products WHERE id = ?')
       ->execute([$clampProduct]);

    $exhaustedProduct = $insertProduct(
        $db,
        'Exhausted pending proof'
    );
    canonicalIngredientEnqueueProduct(
        $db,
        $exhaustedProduct,
        'exhausted_pending'
    );
    $db->prepare("
        UPDATE canonical_processing_queue
        SET status = 'pending',
            attempts = 3,
            next_retry_at = CURRENT_TIMESTAMP,
            last_error = '',
            last_error_kind = ''
        WHERE product_id = ?
    ")->execute([$exhaustedProduct]);
    $normalized =
        canonicalIngredientProcessQueueBatch($db, 1, 3);
    $normalizedRow = $row($db, $exhaustedProduct);
    $assert(
        $normalized['normalized_exhausted'] === 1
        && $normalizedRow['status'] === 'failed'
        && $normalizedRow['next_retry_at'] === null
        && $normalizedRow['last_error_kind']
            === 'attempts_exhausted',
        'Rows already at the execution cap must normalize to a diagnosed terminal failure'
    );

    $overrideProduct = $insertProduct(
        $db,
        'Override normalization proof'
    );
    canonicalIngredientEnqueueProduct(
        $db,
        $overrideProduct,
        'override_normalization'
    );
    $db->prepare("
        UPDATE canonical_processing_queue
        SET status = 'pending',
            attempts = 2,
            next_retry_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
    ")->execute([$overrideProduct]);
    $overrideResult =
        canonicalIngredientProcessQueueBatch($db, 1, 1);
    $overrideRow = $row($db, $overrideProduct);
    $assert(
        $overrideResult['normalized_exhausted'] === 0
        && $overrideRow['status'] === 'pending'
        && (int)$overrideRow['attempts'] === 2,
        'A per-call attempt override must not terminalize the global backlog'
    );
    $db->prepare(
        'DELETE FROM canonical_processing_queue WHERE product_id = ?'
    )->execute([$overrideProduct]);
    $db->prepare('DELETE FROM products WHERE id = ?')
       ->execute([$overrideProduct]);

    file_put_contents(
        canonicalIngredientFoodOnCachePath(),
        json_encode([
            'resident-proof' => [
                'ts' => time(),
                'found' => false,
            ],
        ], JSON_THROW_ON_ERROR)
    );
    unset(
        $GLOBALS['CANONICAL_QUEUE_TEST_CACHE_READS'],
        $GLOBALS['CANONICAL_QUEUE_TEST_CACHE_HITS']
    );
    canonicalIngredientFoodOnCacheLoad();
    canonicalIngredientFoodOnCacheLoad();
    $assert(
        (int)($GLOBALS['CANONICAL_QUEUE_TEST_CACHE_READS'][
            canonicalIngredientFoodOnCachePath()
        ] ?? 0) === 1
        && (int)($GLOBALS['CANONICAL_QUEUE_TEST_CACHE_HITS'][
            canonicalIngredientFoodOnCachePath()
        ] ?? 0) >= 1,
        'Unchanged resident cache loads must avoid repeated disk parsing'
    );

    $unstableBaseline = canonicalIngredientFoodOnCachePath()
        . '.unstable-baseline';
    file_put_contents(
        $unstableBaseline,
        json_encode([
            'unstable-proof' => ['writer' => 'baseline'],
        ], JSON_THROW_ON_ERROR)
    );
    rename(
        $unstableBaseline,
        canonicalIngredientFoodOnCachePath()
    );
    $readsBeforeUnstable = (int)($GLOBALS[
        'CANONICAL_QUEUE_TEST_CACHE_READS'
    ][canonicalIngredientFoodOnCachePath()] ?? 0);
    $GLOBALS['CANONICAL_QUEUE_TEST_CACHE_AFTER_READ'] =
        static function (string $path, int $attempt): void {
            $writer = $attempt === 0 ? 'middle' : 'final';
            $tmp = $path . '.unstable-' . $attempt;
            file_put_contents(
                $tmp,
                json_encode([
                    'unstable-proof' => ['writer' => $writer],
                ], JSON_THROW_ON_ERROR)
            );
            rename($tmp, $path);
        };
    canonicalIngredientFoodOnCacheLoad();
    unset($GLOBALS['CANONICAL_QUEUE_TEST_CACHE_AFTER_READ']);
    $stableAfterRenames =
        canonicalIngredientFoodOnCacheLoad();
    $readsAfterUnstable = (int)($GLOBALS[
        'CANONICAL_QUEUE_TEST_CACHE_READS'
    ][canonicalIngredientFoodOnCachePath()] ?? 0);
    $assert(
        ($stableAfterRenames['unstable-proof']['writer'] ?? '')
            === 'final'
        && ($readsAfterUnstable - $readsBeforeUnstable) >= 3,
        'Two concurrent cache replacements must force a fresh stable snapshot'
    );

    canonicalIngredientFoodOnCacheStore(
        'direct-store',
        ['ts' => time(), 'found' => false]
    );
    $assert(
        isset(canonicalIngredientFoodOnCacheLoad()['direct-store']),
        'FoodOn store must update same-process cache reads'
    );
    canonicalIngredientUsdaCacheStore(
        'direct-store',
        ['ts' => time(), 'found' => false]
    );
    $assert(
        isset(canonicalIngredientUsdaCacheLoad()['direct-store']),
        'USDA store must update same-process cache reads'
    );

    $foodCalls = 0;
    $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'] =
        static function () use (&$foodCalls): array {
            $foodCalls++;
            return [
                'id' => 'FOODON:1',
                'short_form' => 'FOODON_1',
                'iri' => 'http://purl.obolibrary.org/obo/FOODON_1',
                'label' => 'Cache proof',
                'query' => 'Cache proof',
                'source' => 'test',
                'match_score' => 100,
                'hierarchy' => [],
            ];
        };
    canonicalIngredientFoodOnLookup('Cache proof', 'cache-proof');
    canonicalIngredientFoodOnLookup('Cache proof', 'cache-proof');
    unset($GLOBALS['CANONICAL_FOODON_TEST_LOOKUP']);
    $assert(
        $foodCalls === 1,
        'A durable FoodOn hit must prevent a duplicate provider call'
    );

    $negativeCalls = 0;
    $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'] =
        static function () use (&$negativeCalls): ?array {
            $negativeCalls++;
            return null;
        };
    canonicalIngredientFoodOnLookup(
        'Negative cache proof',
        'negative-cache-proof'
    );
    canonicalIngredientFoodOnLookup(
        'Negative cache proof',
        'negative-cache-proof'
    );
    unset($GLOBALS['CANONICAL_FOODON_TEST_LOOKUP']);
    $assert(
        $negativeCalls === 1,
        'Negative FoodOn cache entries must suppress duplicate calls'
    );
    $GLOBALS['CANONICAL_FOODON_TEST_SEARCH'] =
        static fn(): array => [
            'label' => 'Hierarchy write proof',
            '_match_score' => 100,
            '_foodon_identity' => [
                'id' => 'FOODON:3',
                'short_form' => 'FOODON_3',
                'iri' =>
                    'http://purl.obolibrary.org/obo/FOODON_3',
            ],
        ];
    $GLOBALS['CANONICAL_FOODON_TEST_PARENT_TERMS'] =
        static fn(): ?array => null;
    $hierarchyWrite = canonicalIngredientFoodOnLookup(
        'Hierarchy write proof',
        'hierarchy-write-proof'
    );
    unset(
        $GLOBALS['CANONICAL_FOODON_TEST_SEARCH'],
        $GLOBALS['CANONICAL_FOODON_TEST_PARENT_TERMS']
    );
    $hierarchyWriteKey = FOODON_LOOKUP_CACHE_VERSION
        . ':hierarchy-write-proof';
    $assert(
        $hierarchyWrite === null
        && (canonicalIngredientFoodOnCacheLoad()[
            $hierarchyWriteKey
        ]['reason'] ?? '') === 'hierarchy_failed'
        && !isset(canonicalIngredientFoodOnCacheLoad()[
            $hierarchyWriteKey
        ]['foodon']),
        'The production hierarchy-failure path must persist no partial FoodOn record'
    );

    $expiredHierarchyKey = FOODON_LOOKUP_CACHE_VERSION
        . ':hierarchy-expired-proof';
    $definitiveMissKey = FOODON_LOOKUP_CACHE_VERSION
        . ':definitive-miss-proof';
    canonicalIngredientFoodOnCacheStore($expiredHierarchyKey, [
        'ts' => time() - 901,
        'found' => false,
        'query' => 'Hierarchy expired proof',
        'reason' => 'hierarchy_failed',
    ]);
    canonicalIngredientFoodOnCacheStore($definitiveMissKey, [
        'ts' => time() - 901,
        'found' => false,
        'query' => 'Definitive miss proof',
    ]);
    $hierarchyRetryCalls = 0;
    $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'] =
        static function () use (&$hierarchyRetryCalls): array {
            $hierarchyRetryCalls++;
            return [
                'id' => 'FOODON:4',
                'short_form' => 'FOODON_4',
                'iri' =>
                    'http://purl.obolibrary.org/obo/FOODON_4',
                'label' => 'Hierarchy retry proof',
                'query' => 'Hierarchy retry proof',
                'source' => 'test',
                'match_score' => 100,
                'hierarchy' => [],
            ];
        };
    $expiredHierarchy = canonicalIngredientFoodOnLookup(
        'Hierarchy expired proof',
        'hierarchy-expired-proof'
    );
    $definitiveMiss = canonicalIngredientFoodOnLookup(
        'Definitive miss proof',
        'definitive-miss-proof'
    );
    unset($GLOBALS['CANONICAL_FOODON_TEST_LOOKUP']);
    $assert(
        is_array($expiredHierarchy)
        && $definitiveMiss === null
        && $hierarchyRetryCalls === 1,
        'Expired hierarchy failures must retry while equally old definitive misses remain cached'
    );

    $positivePrecedenceKey = FOODON_LOOKUP_CACHE_VERSION
        . ':positive-precedence-proof';
    canonicalIngredientFoodOnCacheStore(
        $positivePrecedenceKey,
        [
            'ts' => time(),
            'found' => true,
            'foodon' => [
                'id' => 'FOODON:positive',
                'hierarchy' => [],
            ],
        ]
    );
    canonicalIngredientFoodOnCacheStore(
        $positivePrecedenceKey,
        [
            'ts' => time(),
            'found' => false,
            'reason' => 'hierarchy_failed',
        ]
    );
    $positivePrecedence =
        canonicalIngredientFoodOnCacheLoad()[
            $positivePrecedenceKey
        ] ?? [];
    $assert(
        !empty($positivePrecedence['found'])
        && ($positivePrecedence['foodon']['id'] ?? '')
            === 'FOODON:positive',
        'A transient hierarchy failure must not replace a fresh positive cache entry'
    );

    $usdaCalls = 0;
    $GLOBALS['CANONICAL_USDA_TEST_LOOKUP'] =
        static function () use (&$usdaCalls): array {
            $usdaCalls++;
            return [
                'fdc_id' => 1,
                'description' => 'USDA cache proof',
                'data_type' => 'Foundation',
                'food_category' => 'Test',
                'query' => 'USDA cache proof',
                'source' => 'test',
                'match_score' => 100,
            ];
        };
    canonicalIngredientUsdaLookup(
        'USDA cache proof',
        'usda-cache-proof'
    );
    canonicalIngredientUsdaLookup(
        'USDA cache proof',
        'usda-cache-proof'
    );
    unset($GLOBALS['CANONICAL_USDA_TEST_LOOKUP']);
    $assert(
        $usdaCalls === 1,
        'A durable USDA hit must prevent a duplicate provider call'
    );

    $bestEffortFoodCalls = 0;
    $bestEffortUsdaCalls = 0;
    $GLOBALS['CANONICAL_QUEUE_TEST_CACHE_STORE'] =
        static function (): never {
            throw new RuntimeException(
                'injected cache publication failure'
            );
        };
    $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'] =
        static function () use (&$bestEffortFoodCalls): array {
            $bestEffortFoodCalls++;
            return [
                'id' => 'FOODON:5',
                'short_form' => 'FOODON_5',
                'iri' => 'http://purl.obolibrary.org/obo/FOODON_5',
                'label' => 'Best effort FoodOn',
                'query' => 'Best effort FoodOn',
                'source' => 'test',
                'match_score' => 100,
                'hierarchy' => [],
            ];
        };
    $GLOBALS['CANONICAL_USDA_TEST_LOOKUP'] =
        static function () use (&$bestEffortUsdaCalls): array {
            $bestEffortUsdaCalls++;
            return [
                'fdc_id' => 5,
                'description' => 'Best effort USDA',
                'data_type' => 'Foundation',
                'food_category' => 'Test',
                'query' => 'Best effort USDA',
                'source' => 'test',
                'match_score' => 100,
            ];
        };
    $bestEffortFood = canonicalIngredientFoodOnLookup(
        'Best effort FoodOn',
        'best-effort-foodon'
    );
    canonicalIngredientFoodOnLookup(
        'Best effort FoodOn',
        'best-effort-foodon'
    );
    $bestEffortUsda = canonicalIngredientUsdaLookup(
        'Best effort USDA',
        'best-effort-usda'
    );
    canonicalIngredientUsdaLookup(
        'Best effort USDA',
        'best-effort-usda'
    );
    unset(
        $GLOBALS['CANONICAL_QUEUE_TEST_CACHE_STORE'],
        $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'],
        $GLOBALS['CANONICAL_USDA_TEST_LOOKUP']
    );
    $bestEffortLog = '';
    foreach (glob($root . '/logs/*.log') ?: [] as $logPath) {
        $bestEffortLog .= (string)file_get_contents($logPath);
    }
    $assert(
        is_array($bestEffortFood)
        && is_array($bestEffortUsda)
        && $bestEffortFoodCalls === 1
        && $bestEffortUsdaCalls === 1
        && str_contains(
            $bestEffortLog,
            'canonical provider cache publication failed'
        ),
        'Cache publication failure must retain valid provider results in resident state'
    );

    $heldCacheLock = fopen(
        canonicalIngredientFoodOnCachePath() . '.lock',
        'c+'
    );
    if (
        $heldCacheLock === false
        || !flock($heldCacheLock, LOCK_EX | LOCK_NB)
    ) {
        throw new RuntimeException(
            'Held provider-cache lock fixture could not acquire lock'
        );
    }
    $heldCacheCalls = 0;
    $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'] =
        static function () use (&$heldCacheCalls): array {
            $heldCacheCalls++;
            return [
                'id' => 'FOODON:held-lock',
                'short_form' => 'FOODON_HELD_LOCK',
                'iri' =>
                    'http://purl.obolibrary.org/obo/FOODON_HELD_LOCK',
                'label' => 'Held cache lock',
                'query' => 'Held cache lock',
                'source' => 'test',
                'match_score' => 100,
                'hierarchy' => [],
            ];
        };
    $heldCacheStarted = microtime(true);
    $heldCacheResult = canonicalIngredientFoodOnLookup(
        'Held cache lock',
        'held-cache-lock'
    );
    canonicalIngredientFoodOnLookup(
        'Held cache lock',
        'held-cache-lock'
    );
    $heldCacheElapsed = microtime(true) - $heldCacheStarted;
    unset($GLOBALS['CANONICAL_FOODON_TEST_LOOKUP']);
    flock($heldCacheLock, LOCK_UN);
    fclose($heldCacheLock);
    $assert(
        is_array($heldCacheResult)
        && $heldCacheCalls === 1
        && $heldCacheElapsed < 0.5,
        'A held cache lock must fall back to resident state without blocking provider work'
    );

    $fallbackRaceKey = FOODON_LOOKUP_CACHE_VERSION
        . ':fallback-race-proof';
    $fallbackRaceLock = fopen(
        canonicalIngredientFoodOnCachePath() . '.lock',
        'c+'
    );
    if (
        $fallbackRaceLock === false
        || !flock($fallbackRaceLock, LOCK_EX | LOCK_NB)
    ) {
        throw new RuntimeException(
            'Fallback cache-race lock fixture could not acquire lock'
        );
    }
    $GLOBALS['CANONICAL_FOODON_TEST_SEARCH'] =
        static fn(): array => [
            'label' => 'Fallback race proof',
            '_match_score' => 100,
            '_foodon_identity' => [
                'id' => 'FOODON:fallback-negative',
                'short_form' => 'FOODON_FALLBACK_NEGATIVE',
                'iri' =>
                    'http://purl.obolibrary.org/obo/FOODON_FALLBACK_NEGATIVE',
            ],
        ];
    $GLOBALS['CANONICAL_FOODON_TEST_PARENT_TERMS'] =
        static fn(): ?array => null;
    $GLOBALS[
        'CANONICAL_QUEUE_TEST_CACHE_FALLBACK_AFTER_LOAD'
    ] = static function (
        string $path,
        string $key,
        int $attempt
    ) use ($fallbackRaceKey): void {
        if ($attempt !== 0 || $key !== $fallbackRaceKey) {
            return;
        }
        $cache = canonicalIngredientJsonCacheRead($path);
        $cache[$key] = [
            'ts' => time(),
            'found' => true,
            'foodon' => [
                'id' => 'FOODON:fallback-positive',
                'hierarchy' => [],
            ],
        ];
        $tmp = $path . '.fallback-race';
        file_put_contents(
            $tmp,
            json_encode($cache, JSON_THROW_ON_ERROR)
        );
        rename($tmp, $path);
    };
    $fallbackRaceMiss = canonicalIngredientFoodOnLookup(
        'Fallback race proof',
        'fallback-race-proof'
    );
    unset(
        $GLOBALS['CANONICAL_FOODON_TEST_SEARCH'],
        $GLOBALS['CANONICAL_FOODON_TEST_PARENT_TERMS'],
        $GLOBALS[
            'CANONICAL_QUEUE_TEST_CACHE_FALLBACK_AFTER_LOAD'
        ]
    );
    flock($fallbackRaceLock, LOCK_UN);
    fclose($fallbackRaceLock);
    $fallbackRaceHit = canonicalIngredientFoodOnLookup(
        'Fallback race proof',
        'fallback-race-proof'
    );
    $assert(
        $fallbackRaceMiss === null
        && ($fallbackRaceHit['id'] ?? '')
            === 'FOODON:fallback-positive',
        'Resident fallback must observe a positive published during a cache-lock race'
    );

    @unlink(canonicalIngredientFoodOnCachePath());
    $missingFallbackKey = FOODON_LOOKUP_CACHE_VERSION
        . ':missing-fallback-race-proof';
    $GLOBALS[
        'CANONICAL_QUEUE_TEST_CACHE_FALLBACK_BEFORE_PUBLISH'
    ] = static function (
        string $path,
        string $key,
        int $attempt
    ) use ($missingFallbackKey): void {
        if ($attempt !== 0 || $key !== $missingFallbackKey) {
            return;
        }
        file_put_contents(
            $path,
            json_encode([
                $key => [
                    'ts' => time(),
                    'found' => true,
                    'foodon' => [
                        'id' => 'FOODON:missing-positive',
                        'hierarchy' => [],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );
    };
    canonicalIngredientJsonCachePublishResidentFallback(
        canonicalIngredientFoodOnCachePath(),
        $missingFallbackKey,
        [
            'ts' => time(),
            'found' => false,
            'reason' => 'hierarchy_failed',
        ]
    );
    unset(
        $GLOBALS[
            'CANONICAL_QUEUE_TEST_CACHE_FALLBACK_BEFORE_PUBLISH'
        ]
    );
    $missingFallback =
        canonicalIngredientFoodOnCacheLoad()[
            $missingFallbackKey
        ] ?? [];
    $assert(
        !empty($missingFallback['found'])
        && ($missingFallback['foodon']['id'] ?? '')
            === 'FOODON:missing-positive',
        'An absent-file fallback must not bind stale resident data to a concurrently created cache'
    );

    [$cacheOne, $cacheOnePipes] = $startChild([
        PHP_BINARY,
        __FILE__,
        '--cache-store',
        canonicalIngredientFoodOnCachePath(),
        canonicalIngredientUsdaCachePath(),
        'concurrent-one',
        'one',
    ]);
    [$cacheTwo, $cacheTwoPipes] = $startChild([
        PHP_BINARY,
        __FILE__,
        '--cache-store',
        canonicalIngredientFoodOnCachePath(),
        canonicalIngredientUsdaCachePath(),
        'concurrent-two',
        'two',
    ]);
    $readsBeforeChild = (int)($GLOBALS[
        'CANONICAL_QUEUE_TEST_CACHE_READS'
    ][canonicalIngredientFoodOnCachePath()] ?? 0);
    $finishChild($cacheOne, $cacheOnePipes);
    $finishChild($cacheTwo, $cacheTwoPipes);
    $concurrentCache = canonicalIngredientFoodOnCacheLoad();
    $assert(
        isset(
            $concurrentCache['concurrent-one'],
            $concurrentCache['concurrent-two']
        )
        && (int)($GLOBALS['CANONICAL_QUEUE_TEST_CACHE_READS'][
            canonicalIngredientFoodOnCachePath()
        ] ?? 0) > $readsBeforeChild,
        'Concurrent atomic cache publication must be observed and preserve both keys'
    );

    $realBuildProduct = $insertProduct($db, 'Real canonical build');
    canonicalIngredientEnqueueProduct(
        $db,
        $realBuildProduct,
        'real_build'
    );
    $realFoodCalls = 0;
    $realUsdaCalls = 0;
    $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'] =
        static function () use (&$realFoodCalls): array {
            $realFoodCalls++;
            return [
                'id' => 'FOODON:2',
                'short_form' => 'FOODON_2',
                'iri' => 'http://purl.obolibrary.org/obo/FOODON_2',
                'label' => 'Real canonical build',
                'query' => 'Real canonical build',
                'source' => 'test',
                'match_score' => 100,
                'hierarchy' => [],
            ];
        };
    $GLOBALS['CANONICAL_USDA_TEST_LOOKUP'] =
        static function () use (&$realUsdaCalls): array {
            $realUsdaCalls++;
            return [
                'fdc_id' => 2,
                'description' => 'Real canonical build',
                'data_type' => 'Foundation',
                'food_category' => 'Test',
                'query' => 'Real canonical build',
                'source' => 'test',
                'match_score' => 100,
            ];
        };
    $realBuild = canonicalIngredientProcessQueueBatch($db, 1, 3);
    unset(
        $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'],
        $GLOBALS['CANONICAL_USDA_TEST_LOOKUP']
    );
    $assert(
        $realBuild['succeeded'] === 1
        && $realFoodCalls === 1
        && $realUsdaCalls === 1
        && $row($db, $realBuildProduct)['status'] === 'done'
        && $mappingSlugs($db, $realBuildProduct)
            === ['canonical-build']
        && $jobCount($db, $realBuildProduct) === 1,
        'The real queue path must compute outside and atomically apply once'
    );

    $budgetProduct = $insertProduct($db, 'Provider budget proof');
    canonicalIngredientEnqueueProduct(
        $db,
        $budgetProduct,
        'provider_budget'
    );
    $budgetFoodCalls = 0;
    $budgetUsdaCalls = 0;
    $GLOBALS['CANONICAL_QUEUE_TEST_PROVIDER_DEADLINE_SECONDS'] =
        0.02;
    $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'] =
        static function () use (&$budgetFoodCalls): array {
            $budgetFoodCalls++;
            usleep(50000);
            return [
                'id' => 'FOODON:6',
                'short_form' => 'FOODON_6',
                'iri' => 'http://purl.obolibrary.org/obo/FOODON_6',
                'label' => 'Provider budget proof',
                'query' => 'Provider budget proof',
                'source' => 'test',
                'match_score' => 100,
                'hierarchy' => [],
            ];
        };
    $GLOBALS['CANONICAL_USDA_TEST_LOOKUP'] =
        static function () use (&$budgetUsdaCalls): array {
            $budgetUsdaCalls++;
            return [
                'fdc_id' => 6,
                'description' => 'Provider budget proof',
                'data_type' => 'Foundation',
                'food_category' => 'Test',
                'query' => 'Provider budget proof',
                'source' => 'test',
                'match_score' => 100,
            ];
        };
    $budgetFailure =
        canonicalIngredientProcessQueueBatch($db, 1, 3);
    $budgetFailureRow = $row($db, $budgetProduct);
    $assert(
        $budgetFailure['retried'] === 1
        && $budgetFailureRow['status'] === 'pending'
        && $budgetFailureRow['last_error_kind']
            === 'provider_budget_exhausted'
        && $budgetFailureRow['lease_token'] === null
        && $mappingSlugs($db, $budgetProduct) === []
        && $jobCount($db, $budgetProduct) === 0
        && $budgetFoodCalls === 1
        && $budgetUsdaCalls === 0,
        'Provider budget exhaustion must explicitly requeue before apply'
    );
    $forceDue($db, $budgetProduct);
    $GLOBALS['CANONICAL_QUEUE_TEST_PROVIDER_DEADLINE_SECONDS'] =
        2.0;
    $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'] =
        static function () use (&$budgetFoodCalls): array {
            $budgetFoodCalls++;
            return [
                'id' => 'FOODON:6',
                'short_form' => 'FOODON_6',
                'iri' => 'http://purl.obolibrary.org/obo/FOODON_6',
                'label' => 'Provider budget proof',
                'query' => 'Provider budget proof',
                'source' => 'test',
                'match_score' => 100,
                'hierarchy' => [],
            ];
        };
    $budgetSuccess =
        canonicalIngredientProcessQueueBatch($db, 1, 3);
    unset(
        $GLOBALS[
            'CANONICAL_QUEUE_TEST_PROVIDER_DEADLINE_SECONDS'
        ],
        $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'],
        $GLOBALS['CANONICAL_USDA_TEST_LOOKUP']
    );
    $assert(
        $budgetSuccess['succeeded'] === 1
        && $row($db, $budgetProduct)['status'] === 'done'
        && $mappingSlugs($db, $budgetProduct)
            === ['provider-budget-proof']
        && $jobCount($db, $budgetProduct) === 1
        && $budgetFoodCalls === 2
        && $budgetUsdaCalls === 1,
        'A later provider-budget attempt must complete without stale partial work'
    );

    $claimSetupProduct = $insertProduct(
        $db,
        'Claim setup failure'
    );
    canonicalIngredientEnqueueProduct(
        $db,
        $claimSetupProduct,
        'claim_setup_failure'
    );
    $GLOBALS['ONTOLOGY_CONTROLLER_TEST_HOOK'] =
        static function (string $stage): void {
            if ($stage === 'canonical_claimed') {
                throw new RuntimeException(
                    'injected post-claim setup failure'
                );
            }
        };
    $claimSetupResult =
        canonicalIngredientProcessQueueBatch($db, 1, 3);
    unset($GLOBALS['ONTOLOGY_CONTROLLER_TEST_HOOK']);
    $claimSetupRow = $row($db, $claimSetupProduct);
    $assert(
        $claimSetupResult['retried'] === 1
        && $claimSetupRow['status'] === 'pending'
        && $claimSetupRow['last_error_kind']
            === 'claim_setup_failure'
        && $claimSetupRow['lease_token'] === null,
        'Post-claim setup failures must release immediately instead of waiting for lease recovery'
    );
    $db->prepare(
        'DELETE FROM canonical_processing_queue WHERE product_id = ?'
    )->execute([$claimSetupProduct]);
    $db->prepare('DELETE FROM products WHERE id = ?')
       ->execute([$claimSetupProduct]);

    $incidentProduct = $insertProduct($db, 'Incident failure');
    canonicalIngredientEnqueueProduct(
        $db,
        $incidentProduct,
        'incident_test'
    );
    $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
        static function (): never {
            throw new RuntimeException('primary incident failure');
        };
    $releaseBusy = 0;
    $GLOBALS['CANONICAL_QUEUE_TEST_HOOK'] =
        static function (
            string $stage
        ) use (&$releaseBusy): void {
            if (
                $stage === 'before_failure_release'
                && $releaseBusy++ < 2
            ) {
                throw new PDOException('database is locked');
            }
        };
    $incident = canonicalIngredientProcessQueueBatch($db, 1, 3);
    unset(
        $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'],
        $GLOBALS['CANONICAL_QUEUE_TEST_HOOK']
    );
    $incidentRow = $row($db, $incidentProduct);
    $logText = '';
    foreach (glob($root . '/logs/*.log') ?: [] as $logPath) {
        $logText .= (string)file_get_contents($logPath);
    }
    $assert(
        $incident['retried'] === 1
        && $incident['release_failed'] === 0
        && $incidentRow['status'] === 'pending'
        && $incidentRow['lease_token'] === null
        && $incidentRow['lease_expires_at'] === null
        && $incidentRow['next_retry_at'] !== null
        && $incidentRow['last_error'] === 'primary incident failure',
        'Failure-state contention must requeue without leaking the exception'
    );
    $assert(
        str_contains($logText, 'primary incident failure')
        && str_contains($logText, 'canonical sqlite busy retry')
        && str_contains($logText, '"stage":"release"'),
        'Primary and release-contention diagnostics must both be retained'
    );
    $db->prepare(
        'DELETE FROM canonical_processing_queue WHERE product_id = ?'
    )->execute([$incidentProduct]);
    $db->prepare('DELETE FROM products WHERE id = ?')
       ->execute([$incidentProduct]);

    $contentionProduct = $insertProduct($db, 'Contention proof');
    canonicalIngredientEnqueueProduct(
        $db,
        $contentionProduct,
        'contention_test'
    );
    $providerComputations = 0;
    $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
        static function (
            PDO $db,
            int $productId
        ) use (&$providerComputations, $prepared): array {
            $providerComputations++;
            return $prepared($productId, 'contention-proof');
        };
    $child = null;
    $childPipes = [];
    $lockStarted = false;
    $GLOBALS['CANONICAL_QUEUE_TEST_HOOK'] =
        static function (
            string $stage
        ) use (
            &$child,
            &$childPipes,
            &$lockStarted,
            $databasePath,
            $startChild
        ): void {
            if ($stage !== 'before_apply_transaction' || $lockStarted) {
                return;
            }
            $lockStarted = true;
            [$child, $childPipes] = $startChild([
                PHP_BINARY,
                __FILE__,
                '--hold-lock',
                $databasePath,
                '350000',
            ]);
            $line = fgets($childPipes[1]);
            if (trim((string)$line) !== 'locked') {
                throw new RuntimeException(
                    'Contention child did not acquire its writer lock'
                );
            }
        };
    $contentionStarted = microtime(true);
    $contention = canonicalIngredientProcessQueueBatch($db, 1, 3);
    $contentionMs =
        (microtime(true) - $contentionStarted) * 1000;
    unset(
        $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'],
        $GLOBALS['CANONICAL_QUEUE_TEST_HOOK']
    );
    if (is_resource($child)) {
        $finishChild($child, $childPipes);
    }
    $contentionDurations = [$contentionMs];
    $assert(
        $contention['succeeded'] === 1
        && $providerComputations === 1
        && $contentionMs < 30000
        && $mappingSlugs($db, $contentionProduct)
            === ['contention-proof'],
        'Real WAL contention must retry only atomic apply under 30 seconds'
    );
    for ($contentionIndex = 1; $contentionIndex < 5; $contentionIndex++) {
        $loopProduct = $insertProduct(
            $db,
            'Contention proof ' . $contentionIndex
        );
        canonicalIngredientEnqueueProduct(
            $db,
            $loopProduct,
            'contention_test'
        );
        $loopSlug = 'contention-proof-' . $contentionIndex;
        $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
            static function (
                PDO $db,
                int $productId
            ) use (
                &$providerComputations,
                $prepared,
                $loopSlug
            ): array {
                $providerComputations++;
                return $prepared($productId, $loopSlug);
            };
        $loopChild = null;
        $loopChildPipes = [];
        $loopLockStarted = false;
        $holdUs = 100000 + ($contentionIndex * 50000);
        $GLOBALS['CANONICAL_QUEUE_TEST_HOOK'] =
            static function (
                string $stage
            ) use (
                &$loopChild,
                &$loopChildPipes,
                &$loopLockStarted,
                $databasePath,
                $startChild,
                $holdUs
            ): void {
                if (
                    $stage !== 'before_apply_transaction'
                    || $loopLockStarted
                ) {
                    return;
                }
                $loopLockStarted = true;
                [$loopChild, $loopChildPipes] = $startChild([
                    PHP_BINARY,
                    __FILE__,
                    '--hold-lock',
                    $databasePath,
                    (string)$holdUs,
                ]);
                if (
                    trim((string)fgets($loopChildPipes[1]))
                    !== 'locked'
                ) {
                    throw new RuntimeException(
                        'Repeated contention child did not lock'
                    );
                }
            };
        $loopStarted = microtime(true);
        $loopResult =
            canonicalIngredientProcessQueueBatch($db, 1, 3);
        $contentionDurations[] =
            (microtime(true) - $loopStarted) * 1000;
        unset(
            $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'],
            $GLOBALS['CANONICAL_QUEUE_TEST_HOOK']
        );
        if (is_resource($loopChild)) {
            $finishChild($loopChild, $loopChildPipes);
        }
        $assert(
            $loopResult['succeeded'] === 1
            && $mappingSlugs($db, $loopProduct) === [$loopSlug],
            'Repeated WAL contention must preserve the prepared result'
        );
    }
    sort($contentionDurations, SORT_NUMERIC);
    $contentionP95 = (float)$contentionDurations[
        (int)floor((count($contentionDurations) - 1) * 0.95)
    ];
    $contentionMax = max($contentionDurations);
    $assert(
        $providerComputations === count($contentionDurations)
        && $contentionP95 <= 30000
        && $contentionMax <= 90000,
        'Handled contention must meet p95 and hard recovery bounds with one computation per item'
    );

    foreach ([
        'after_canonical_writes',
        'after_queue_done',
        'after_taxonomy_enqueue',
    ] as $faultStage) {
        $faultProduct = $insertProduct(
            $db,
            'Atomic fault ' . $faultStage
        );
        canonicalIngredientEnqueueProduct(
            $db,
            $faultProduct,
            'atomic_fault'
        );
        $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
            static fn(
                PDO $db,
                int $productId
            ): array => $prepared(
                $productId,
                canonicalIngredientSlug($faultStage)
            );
        $faultInjected = false;
        $GLOBALS['CANONICAL_QUEUE_TEST_HOOK'] =
            static function (
                string $stage
            ) use ($faultStage, &$faultInjected): void {
                if ($stage === $faultStage && !$faultInjected) {
                    $faultInjected = true;
                    throw new RuntimeException(
                        'atomic fault at ' . $faultStage
                    );
                }
            };
        $fault = canonicalIngredientProcessQueueBatch($db, 1, 3);
        unset(
            $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'],
            $GLOBALS['CANONICAL_QUEUE_TEST_HOOK']
        );
        $faultRow = $row($db, $faultProduct);
        $assert(
            $fault['retried'] === 1
            && $faultRow['status'] === 'pending'
            && $mappingSlugs($db, $faultProduct) === []
            && $jobCount($db, $faultProduct) === 0,
            'Fault at ' . $faultStage
                . ' must roll back data, queue completion, and taxonomy job'
        );
        $forceDue($db, $faultProduct);
        $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
            static fn(
                PDO $db,
                int $productId
            ): array => $prepared(
                $productId,
                canonicalIngredientSlug($faultStage)
            );
        $recovered =
            canonicalIngredientProcessQueueBatch($db, 1, 3);
        unset($GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR']);
        $assert(
            $recovered['succeeded'] === 1
            && $row($db, $faultProduct)['status'] === 'done'
            && $jobCount($db, $faultProduct) === 1,
            'Atomic fault recovery must eventually publish one complete result'
        );
    }

    $directProduct = $insertProduct($db, 'Direct stale A');
    $directMutation = false;
    $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'] =
        static fn(string $name): array => [
            'id' => 'FOODON:7',
            'short_form' => 'FOODON_7',
            'iri' => 'http://purl.obolibrary.org/obo/FOODON_7',
            'label' => $name,
            'query' => $name,
            'source' => 'test',
            'match_score' => 100,
            'hierarchy' => [],
        ];
    $GLOBALS['CANONICAL_USDA_TEST_LOOKUP'] =
        static fn(string $name): array => [
            'fdc_id' => 7,
            'description' => $name,
            'data_type' => 'Foundation',
            'food_category' => 'Test',
            'query' => $name,
            'source' => 'test',
            'match_score' => 100,
        ];
    $GLOBALS['CANONICAL_QUEUE_TEST_HOOK'] =
        static function (
            string $stage
        ) use (
            &$directMutation,
            $databasePath,
            $directProduct
        ): void {
            if (
                $stage !== 'before_direct_apply_transaction'
                || $directMutation
            ) {
                return;
            }
            $directMutation = true;
            $other = new PDO('sqlite:' . $databasePath);
            $other->setAttribute(
                PDO::ATTR_ERRMODE,
                PDO::ERRMODE_EXCEPTION
            );
            $other->exec('PRAGMA busy_timeout=1000');
            $other->prepare("
                UPDATE products
                SET name = 'Direct stale B',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$directProduct]);
        };
    $directResult = canonicalIngredientSyncProduct(
        $db,
        $directProduct,
        null,
        ['allow_ai' => false]
    );
    unset(
        $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'],
        $GLOBALS['CANONICAL_USDA_TEST_LOOKUP'],
        $GLOBALS['CANONICAL_QUEUE_TEST_HOOK']
    );
    $assert(
        empty($directResult['superseded'])
        && $mappingSlugs($db, $directProduct)
            === ['direct-stale-b']
        && !in_array(
            'direct-stale-a',
            $mappingSlugs($db, $directProduct),
            true
        ),
        'Direct canonical sync must rebuild once instead of writing a stale product snapshot'
    );

    $fingerprintProduct = $insertProduct(
        $db,
        'Fingerprint branch A'
    );
    canonicalIngredientEnqueueProduct(
        $db,
        $fingerprintProduct,
        'fingerprint_branch'
    );
    $fingerprintWakeCount = 0;
    $GLOBALS['CANONICAL_QUEUE_TEST_WAKE'] =
        static function () use (&$fingerprintWakeCount): bool {
            $fingerprintWakeCount++;
            return true;
        };
    $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
        static function (
            PDO $db,
            int $productId
        ) use ($databasePath, $prepared): array {
            $other = new PDO('sqlite:' . $databasePath);
            $other->setAttribute(
                PDO::ATTR_ERRMODE,
                PDO::ERRMODE_EXCEPTION
            );
            $other->exec('PRAGMA busy_timeout=1000');
            $other->prepare("
                UPDATE products
                SET name = 'Fingerprint branch B',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$productId]);
            return $prepared($productId, 'fingerprint-branch-a');
        };
    $fingerprintResult =
        canonicalIngredientProcessQueueBatch($db, 1, 3);
    unset($GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR']);
    $fingerprintRow = $row($db, $fingerprintProduct);
    $fingerprintProductStmt = $db->prepare(
        'SELECT * FROM products WHERE id = ?'
    );
    $fingerprintProductStmt->execute([$fingerprintProduct]);
    $currentFingerprintProduct =
        $fingerprintProductStmt->fetch(PDO::FETCH_ASSOC);
    $fingerprintProductStmt->closeCursor();
    $assert(
        $fingerprintResult['superseded'] === 1
        && $fingerprintRow['status'] === 'pending'
        && (int)$fingerprintRow['attempts'] === 0
        && (int)$fingerprintRow['request_generation'] === 2
        && hash_equals(
            canonicalIngredientProductFingerprint(
                $currentFingerprintProduct
            ),
            (string)$fingerprintRow['request_fingerprint']
        )
        && $fingerprintRow['lease_token'] === null
        && $fingerprintRow['lease_expires_at'] === null
        && $fingerprintRow['next_retry_at'] === null
        && $mappingSlugs($db, $fingerprintProduct) === []
        && $jobCount($db, $fingerprintProduct) === 0
        && $fingerprintWakeCount >= 1,
        'Fingerprint mismatch without enqueue must advance and immediately requeue safely'
    );
    $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
        static fn(
            PDO $db,
            int $productId
        ): array => $prepared($productId, 'fingerprint-branch-b');
    canonicalIngredientProcessQueueBatch($db, 1, 3);
    unset($GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR']);
    $GLOBALS['CANONICAL_QUEUE_TEST_WAKE'] =
        static fn(): bool => true;

    $db->exec('DELETE FROM canonical_processing_queue');
    $staleProduct = $insertProduct($db, 'Stale generation one');
    canonicalIngredientEnqueueProduct(
        $db,
        $staleProduct,
        'stale_generation_one'
    );
    $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
        static function (
            PDO $db,
            int $productId
        ) use ($databasePath, $prepared): array {
            $other = new PDO('sqlite:' . $databasePath);
            $other->setAttribute(
                PDO::ATTR_ERRMODE,
                PDO::ERRMODE_EXCEPTION
            );
            $other->exec('PRAGMA busy_timeout=1000');
            $other->exec('BEGIN IMMEDIATE');
            $other->prepare("
                UPDATE products
                SET name = 'Stale generation two',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$productId]);
            canonicalIngredientEnqueueProduct(
                $other,
                $productId,
                'stale_generation_two'
            );
            $other->exec('COMMIT');
            return $prepared($productId, 'stale-generation-one');
        };
    $stale = canonicalIngredientProcessQueueBatch($db, 1, 3);
    unset($GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR']);
    $assert(
        $stale['superseded'] === 1
        && (int)$row(
            $db,
            $staleProduct
        )['request_generation'] === 2
        && $mappingSlugs($db, $staleProduct) === [],
        'An older request generation must write no canonical data'
    );
    $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
        static fn(
            PDO $db,
            int $productId
        ): array => $prepared($productId, 'stale-generation-two');
    canonicalIngredientProcessQueueBatch($db, 1, 3);
    unset($GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR']);
    $assert(
        $mappingSlugs($db, $staleProduct)
            === ['stale-generation-two'],
        'The newer request generation must be the only accepted mapping'
    );

    $expiredProduct = $insertProduct($db, 'Expired old lease');
    canonicalIngredientEnqueueProduct(
        $db,
        $expiredProduct,
        'expired_old_lease'
    );
    $expiredQueue = $row($db, $expiredProduct);
    $oldToken = str_repeat('a', 64);
    $db->prepare("
        UPDATE canonical_processing_queue
        SET status = 'in_progress',
            attempts = 1,
            lease_token = ?,
            lease_generation = 1,
            lease_expires_at = datetime('now', '-1 second'),
            started_at = datetime('now', '-31 seconds')
        WHERE product_id = ?
    ")->execute([$oldToken, $expiredProduct]);
    $oldClaim = [
        'queue_id' => (int)$expiredQueue['id'],
        'product_id' => $expiredProduct,
        'attempts' => 1,
        'request_generation' =>
            (int)$expiredQueue['request_generation'],
        'request_fingerprint' =>
            (string)$expiredQueue['request_fingerprint'],
        'lease_token' => $oldToken,
        'lease_generation' => 1,
    ];
    $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
        static fn(
            PDO $db,
            int $productId
        ): array => $prepared($productId, 'reclaimed-generation');
    $reclaimed = canonicalIngredientProcessQueueBatch($db, 1, 3);
    unset($GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR']);
    $late = canonicalIngredientApplyQueueResult(
        $db,
        $oldClaim,
        $prepared($expiredProduct, 'expired-old-result')
    );
    $assert(
        $reclaimed['reclaimed'] === 1
        && $reclaimed['succeeded'] === 1
        && $late['status'] === 'superseded'
        && $mappingSlugs($db, $expiredProduct)
            === ['reclaimed-generation'],
        'Crash recovery must reject a late completion from the expired lease'
    );

    $db->exec('DELETE FROM canonical_processing_queue');
    $dueProduct = $insertProduct($db, 'Due retry');
    canonicalIngredientEnqueueProduct($db, $dueProduct, 'due_retry');
    $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
        static function (): never {
            throw new RuntimeException('due retry failure');
        };
    $firstFailure = canonicalIngredientProcessQueueBatch($db, 1, 3);
    $firstRow = $row($db, $dueProduct);
    $immediate = canonicalIngredientProcessQueueBatch($db, 1, 3);
    $firstDelay = strtotime((string)$firstRow['next_retry_at'])
        - time();
    $assert(
        $firstFailure['retried'] === 1
        && $firstDelay >= 1
        && $firstDelay <= 3
        && $immediate['processed'] === 0,
        'Attempt one must requeue for two seconds without a hot loop'
    );
    $forceDue($db, $dueProduct);
    canonicalIngredientProcessQueueBatch($db, 1, 3);
    $secondRow = $row($db, $dueProduct);
    $secondDelay = strtotime((string)$secondRow['next_retry_at'])
        - time();
    $assert(
        $secondDelay >= 7 && $secondDelay <= 9,
        'Attempt two must use the bounded eight-second retry'
    );
    $forceDue($db, $dueProduct);
    $terminal = canonicalIngredientProcessQueueBatch($db, 1, 3);
    unset($GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR']);
    $terminalRow = $row($db, $dueProduct);
    $assert(
        $terminal['failed'] === 1
        && $terminalRow['status'] === 'failed'
        && (int)$terminalRow['attempts'] === 3
        && $terminalRow['next_retry_at'] === null,
        'Default maxAttempts=3 must schedule 2s, 8s, then terminal'
    );
    $db->prepare(
        'DELETE FROM canonical_processing_queue WHERE product_id = ?'
    )->execute([$dueProduct]);

    $thirtyProduct = $insertProduct($db, 'Thirty second retry');
    canonicalIngredientEnqueueProduct(
        $db,
        $thirtyProduct,
        'thirty_second_retry'
    );
    $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
        static function (): never {
            throw new RuntimeException('thirty second retry failure');
        };
    canonicalIngredientProcessQueueBatch($db, 1, 4);
    $forceDue($db, $thirtyProduct);
    canonicalIngredientProcessQueueBatch($db, 1, 4);
    $forceDue($db, $thirtyProduct);
    $thirdFailure =
        canonicalIngredientProcessQueueBatch($db, 1, 4);
    $thirdRow = $row($db, $thirtyProduct);
    $thirdDelay = strtotime((string)$thirdRow['next_retry_at'])
        - time();
    $assert(
        $thirdFailure['retried'] === 1
        && $thirdRow['status'] === 'pending'
        && (int)$thirdRow['attempts'] === 3
        && $thirdDelay >= 29
        && $thirdDelay <= 31,
        'maxAttempts=4 must observe a 30-second third backoff'
    );
    $forceDue($db, $thirtyProduct);
    $fourthFailure =
        canonicalIngredientProcessQueueBatch($db, 1, 4);
    unset($GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR']);
    $fourthRow = $row($db, $thirtyProduct);
    $assert(
        $fourthFailure['failed'] === 1
        && $fourthRow['status'] === 'failed'
        && (int)$fourthRow['attempts'] === 4
        && $fourthRow['next_retry_at'] === null,
        'maxAttempts=4 must become terminal on the fourth execution'
    );

    $db->exec('DELETE FROM canonical_processing_queue');
    $wakeProduct = $insertProduct($db, 'Adaptive wake');
    canonicalIngredientEnqueueProduct($db, $wakeProduct, 'adaptive');
    $db->prepare("
        UPDATE canonical_processing_queue
        SET status = 'pending',
            attempts = 1,
            next_retry_at = datetime('now', '+9 seconds')
        WHERE product_id = ?
    ")->execute([$wakeProduct]);
    $retryWake =
        canonicalIngredientQueueNextWakeDelay($db, 30);
    $db->prepare("
        UPDATE canonical_processing_queue
        SET status = 'in_progress',
            next_retry_at = NULL,
            lease_token = ?,
            lease_generation = lease_generation + 1,
            lease_expires_at = datetime('now', '+4 seconds'),
            started_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
    ")->execute([str_repeat('b', 64), $wakeProduct]);
    $leaseWake =
        canonicalIngredientQueueNextWakeDelay($db, 30);
    $assert(
        $retryWake >= 8 && $retryWake <= 9
        && $leaseWake >= 3 && $leaseWake <= 4,
        'Worker wake must adapt to retry due time and lease expiry'
    );
    $GLOBALS['CANONICAL_QUEUE_TEST_HOOK'] =
        static function (string $stage): void {
            if ($stage === 'before_next_wake_query') {
                throw new RuntimeException(
                    'injected adaptive wake failure'
                );
            }
        };
    $wakeFallback = canonicalIngredientWorkerSleepDelay(
        $db,
        30,
        3,
        [],
        0
    );
    unset($GLOBALS['CANONICAL_QUEUE_TEST_HOOK']);
    $assert(
        $wakeFallback === 30,
        'Adaptive wake query failure must fall back without terminating the worker path'
    );
    $assert(
        canonicalIngredientWorkerSleepDelay(
            $db,
            30,
            3,
            ['skipped' => 'lock_unavailable'],
            1
        ) === 5
        && canonicalIngredientWorkerSleepDelay(
            $db,
            30,
            3,
            ['skipped' => 'lock_unavailable'],
            2
        ) === 30,
        'Lock-unavailable worker delay must self-heal quickly once then use the safety poll'
    );

    $missingLockPath = $root . '/missing-canonical.lock';
    $GLOBALS['CANONICAL_QUEUE_TEST_LOCK_PATH'] =
        $missingLockPath;
    $missingTableDb = new PDO('sqlite::memory:');
    $missingTableStatus =
        evershelfProcessingStatusCanonicalQueue(
            $missingTableDb
        );
    $assert(
        canonicalIngredientQueueLockAvailable()
        && !file_exists($missingLockPath)
        && array_key_exists(
            'lock_available',
            $missingTableStatus
        )
        && $missingTableStatus['lock_available'] === true,
        'Lock health probing must not create files and missing-table status must retain its shape'
    );

    $lockPath = $root . '/unwritable-canonical.lock';
    file_put_contents($lockPath, '');
    chmod($lockPath, 0444);
    $GLOBALS['CANONICAL_QUEUE_TEST_LOCK_PATH'] = $lockPath;
    unset($GLOBALS['CANONICAL_QUEUE_LOCK_WARNING_LAST_AT']);
    $beforeLockWarnings = '';
    foreach (glob($root . '/logs/*.log') ?: [] as $logPath) {
        $beforeLockWarnings .= (string)file_get_contents($logPath);
    }
    $warningCountBefore = substr_count(
        $beforeLockWarnings,
        'canonical queue lock unavailable'
    );
    $lockResult = canonicalIngredientProcessQueue($db, 1, 3);
    $secondLockResult = canonicalIngredientProcessQueue($db, 1, 3);
    chmod($lockPath, 0660);
    $lockLogText = '';
    foreach (glob($root . '/logs/*.log') ?: [] as $logPath) {
        $lockLogText .= (string)file_get_contents($logPath);
    }
    $assert(
        ($lockResult['skipped'] ?? '') === 'lock_unavailable'
        && ($secondLockResult['skipped'] ?? '')
            === 'lock_unavailable'
        && str_contains(
            $lockLogText,
            'canonical queue lock unavailable'
        )
        && substr_count(
            $lockLogText,
            'canonical queue lock unavailable'
        ) === $warningCountBefore + 1,
        'An unavailable canonical queue lock must skip instead of overlapping'
    );
    $heldLock = fopen($lockPath, 'c+');
    if (
        $heldLock === false
        || !flock($heldLock, LOCK_EX | LOCK_NB)
    ) {
        throw new RuntimeException(
            'Canonical held-lock fixture could not acquire lock'
        );
    }
    $heldContender = canonicalIngredientQueueLock();
    $assert(
        canonicalIngredientQueueLockAvailable()
        && $heldContender === false,
        'A writable legitimately held canonical lock must remain healthy but busy'
    );
    if (is_resource($heldContender)) {
        canonicalIngredientQueueUnlock($heldContender);
    }
    flock($heldLock, LOCK_UN);
    fclose($heldLock);
    unset($GLOBALS['CANONICAL_QUEUE_TEST_LOCK_PATH']);
    $entrypointSource = (string)file_get_contents(
        __DIR__ . '/../docker/entrypoint.sh'
    );
    $assert(
        str_contains(
            $entrypointSource,
            '/var/www/html/data/canonical_queue.lock'
        )
        && str_contains(
            $entrypointSource,
            '/var/www/html/data/.canonical-queue-worker.lock'
        )
        && str_contains(
            $entrypointSource,
            '/var/www/html/data/foodon_lookup_cache.json.lock'
        )
        && str_contains(
            $entrypointSource,
            '/var/www/html/data/usda_fdc_lookup_cache.json.lock'
        )
        && str_contains($entrypointSource, 'chown www-data:www-data'),
        'Container startup must repair dedicated queue and provider-cache lock ownership'
    );

    $statusDb = $open($statusPath);
    $statusLockPath = $root . '/status-canonical.lock';
    file_put_contents($statusLockPath, '');
    chmod($statusLockPath, 0660);
    $GLOBALS['CANONICAL_QUEUE_TEST_LOCK_PATH'] = $statusLockPath;
    $statusProduct = $insertProduct($statusDb, 'Status retry');
    canonicalIngredientEnqueueProduct(
        $statusDb,
        $statusProduct,
        'status_retry'
    );
    $statusDb->prepare("
        UPDATE canonical_processing_queue
        SET attempts = 1,
            next_retry_at = datetime('now', '+60 seconds'),
            last_error_kind = 'sqlite_busy',
            last_error = 'private sqlite detail'
        WHERE product_id = ?
    ")->execute([$statusProduct]);
    $futureStatus = evershelfProcessingStatus($statusDb);
    $assert(
        $futureStatus['canonical_queue']['retry_count'] === 1
        && $futureStatus['canonical_queue']['retry_due_count'] === 0
        && $futureStatus['canonical_queue']['active_count'] === 0
        && $futureStatus['canonical_queue']['lock_available'] === true
        && $futureStatus['problem'] === false
        && $futureStatus['canonical_queue']['last_error']
            === EVERSHELF_PROCESSING_STATUS_PUBLIC_ERROR,
        'A normal future canonical retry must be visible but not degraded'
    );
    $statusDb->prepare("
        UPDATE canonical_processing_queue
        SET next_retry_at = datetime('now', '-1 second')
        WHERE product_id = ?
    ")->execute([$statusProduct]);
    $dueStatus = evershelfProcessingStatus($statusDb);
    $assert(
        $dueStatus['phase'] === 'canonical'
        && $dueStatus['active'] === true
        && $dueStatus['pending']['canonical_due'] === 1
        && $dueStatus['canonical_queue']['oldest_due_at'] !== null
        && $dueStatus['canonical_queue']['oldest_due_age_seconds']
            !== null
        && $dueStatus['problem'] === false,
        'Fresh due canonical work must be active with due-specific age and no degradation'
    );
    $statusDb->prepare("
        UPDATE canonical_processing_queue
        SET requested_at = datetime('now', '-301 seconds')
        WHERE product_id = ?
    ")->execute([$statusProduct]);
    $freshStatusProduct = $insertProduct(
        $statusDb,
        'Fresh status due'
    );
    canonicalIngredientEnqueueProduct(
        $statusDb,
        $freshStatusProduct,
        'fresh_status_due'
    );
    $staleDueStatus = evershelfProcessingStatus($statusDb);
    $assert(
        $staleDueStatus['canonical_queue']['stale_due_count'] === 1
        && $staleDueStatus['canonical_queue']['retry_due_count'] === 2
        && $staleDueStatus['canonical_queue']['oldest_due_age_seconds']
            >= 300
        && $staleDueStatus['problem'] === true,
        'Only due canonical rows beyond the stale threshold must be counted as stale'
    );
    $statusDb->prepare(
        'DELETE FROM canonical_processing_queue WHERE product_id = ?'
    )->execute([$freshStatusProduct]);
    $statusDb->prepare('DELETE FROM products WHERE id = ?')
             ->execute([$freshStatusProduct]);
    chmod($statusLockPath, 0444);
    $statusDb->prepare("
        UPDATE canonical_processing_queue
        SET requested_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
    ")->execute([$statusProduct]);
    $blockedDueStatus = evershelfProcessingStatus($statusDb);
    $assert(
        $blockedDueStatus['canonical_queue']['retry_due_count'] === 1
        && $blockedDueStatus['canonical_queue']['lock_available']
            === false
        && $blockedDueStatus['problem'] === true,
        'Due canonical work blocked by an unavailable lock must raise a problem'
    );
    chmod($statusLockPath, 0660);
    $statusDb->prepare("
        UPDATE canonical_processing_queue
        SET status = 'in_progress',
            next_retry_at = NULL,
            lease_token = ?,
            lease_generation = lease_generation + 1,
            lease_expires_at = datetime('now', '-1 second'),
            started_at = datetime('now', '-10 seconds')
        WHERE product_id = ?
    ")->execute([str_repeat('c', 64), $statusProduct]);
    $overdueStatus = evershelfProcessingStatus($statusDb);
    $assert(
        $overdueStatus['canonical_queue']['overdue_lease_count'] === 1
        && $overdueStatus['problem'] === true,
        'An overdue canonical lease must raise an actionable problem'
    );
    $statusDb->prepare("
        UPDATE canonical_processing_queue
        SET status = 'failed',
            attempts = 3,
            lease_token = NULL,
            lease_expires_at = NULL,
            started_at = NULL,
            last_error_kind = 'work_failure',
            last_error = 'terminal canonical failure',
            updated_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
    ")->execute([$statusProduct]);
    $failedStatus = evershelfProcessingStatus($statusDb);
    $assert(
        $failedStatus['canonical_queue']['failed_24h_count'] === 1
        && $failedStatus['canonical_queue']['active_count'] === 0
        && $failedStatus['problem'] === true,
        'A recent terminal canonical failure must raise a problem without active work'
    );
    $statusDb->prepare("
        UPDATE canonical_processing_queue
        SET updated_at = datetime('now', '-25 hours')
        WHERE product_id = ?
    ")->execute([$statusProduct]);
    $historicalFailureStatus =
        evershelfProcessingStatus($statusDb);
    $assert(
        $historicalFailureStatus[
            'canonical_queue'
        ]['failed_24h_count'] === 0
        && $historicalFailureStatus[
            'canonical_queue'
        ]['exhausted_count'] === 1
        && $historicalFailureStatus[
            'canonical_queue'
        ]['exhausted_pending_count'] === 0
        && $historicalFailureStatus[
            'canonical_queue'
        ]['open_count'] === 0
        && $historicalFailureStatus['problem'] === false,
        'Historical terminal failures must remain diagnostic without permanently degrading health'
    );
    $statusDb->prepare("
        UPDATE canonical_processing_queue
        SET status = 'pending',
            next_retry_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
    ")->execute([$statusProduct]);
    $unnormalizedFailureStatus =
        evershelfProcessingStatus($statusDb);
    $assert(
        $unnormalizedFailureStatus[
            'canonical_queue'
        ]['exhausted_pending_count'] === 1
        && $unnormalizedFailureStatus[
            'canonical_queue'
        ]['open_count'] === 1
        && $unnormalizedFailureStatus['problem'] === true,
        'An unnormalized pending row at the attempt cap must remain fail-visible'
    );
    unset($GLOBALS['CANONICAL_QUEUE_TEST_LOCK_PATH']);

    $benchmarkDb = $open($benchmarkPath);
    $percentile95 = static function (array $values): float {
        sort($values, SORT_NUMERIC);
        return (float)$values[
            max(0, (int)ceil(count($values) * 0.95) - 1)
        ];
    };
    $apiRound = static function (
        PDO $db,
        int $index,
        string $prefix
    ): array {
        $GLOBALS['PRODUCT_API_JSON_INPUT'] = [
            'name' => $prefix . ' product ' . $index,
            'barcode' => $prefix . '-' . $index,
        ];
        $started = hrtime(true);
        ob_start();
        saveProduct($db);
        $saved = json_decode((string)ob_get_clean(), true);
        $productMs = (hrtime(true) - $started) / 1000000;
        unset($GLOBALS['PRODUCT_API_JSON_INPUT']);
        $GLOBALS['INVENTORY_ADD_INPUT'] = [
            'product_id' => (int)($saved['id'] ?? 0),
            'quantity' => 1,
            'location' => 'dispensa',
        ];
        $started = hrtime(true);
        ob_start();
        addToInventory($db);
        $inventory = json_decode((string)ob_get_clean(), true);
        $inventoryMs = (hrtime(true) - $started) / 1000000;
        unset($GLOBALS['INVENTORY_ADD_INPUT']);
        if (
            empty($saved['success'])
            || empty($inventory['success'])
        ) {
            throw new RuntimeException(
                'Foreground benchmark request failed'
            );
        }
        return [$productMs, $inventoryMs];
    };
    for ($index = 0; $index < 4; $index++) {
        $apiRound($benchmarkDb, $index, 'warmup');
    }
    $baselineProduct = [];
    $baselineInventory = [];
    for ($index = 0; $index < 20; $index++) {
        [$productMs, $inventoryMs] =
            $apiRound($benchmarkDb, $index, 'baseline');
        $baselineProduct[] = $productMs;
        $baselineInventory[] = $inventoryMs;
    }
    [$writer, $writerPipes] = $startChild([
        PHP_BINARY,
        __FILE__,
        '--writer-loop',
        $benchmarkPath,
        '120',
        '2000',
        '10000',
    ]);
    if (trim((string)fgets($writerPipes[1])) !== 'ready') {
        throw new RuntimeException(
            'Foreground contention writer did not start'
        );
    }
    $contendedProduct = [];
    $contendedInventory = [];
    for ($index = 0; $index < 20; $index++) {
        [$productMs, $inventoryMs] =
            $apiRound($benchmarkDb, $index, 'contended');
        $contendedProduct[] = $productMs;
        $contendedInventory[] = $inventoryMs;
    }
    $finishChild($writer, $writerPipes);
    $baselineProductP95 = $percentile95($baselineProduct);
    $baselineInventoryP95 = $percentile95($baselineInventory);
    $contendedProductP95 = $percentile95($contendedProduct);
    $contendedInventoryP95 = $percentile95($contendedInventory);
    $productAllowance = max(10.0, $baselineProductP95 * 0.2);
    $inventoryAllowance = max(
        10.0,
        $baselineInventoryP95 * 0.2
    );
    $assert(
        $contendedProductP95
            <= $baselineProductP95 + $productAllowance,
        'Product-save contention regression must stay within 10 ms or 20%'
    );
    $assert(
        $contendedInventoryP95
            <= $baselineInventoryP95 + $inventoryAllowance,
        'Inventory-add contention regression must stay within 10 ms or 20%'
    );

    $metrics = [
        'contention_recovery_ms' => [
            'p95' => round($contentionP95, 3),
            'max' => round($contentionMax, 3),
        ],
        'provider_computations' => $providerComputations,
        'foodon_provider_calls' => $foodCalls,
        'usda_provider_calls' => $usdaCalls,
        'provider_budget_calls' => [
            'foodon' => $budgetFoodCalls,
            'usda' => $budgetUsdaCalls,
        ],
        'cache_state' => [
            'disk_reads' => (int)($GLOBALS[
                'CANONICAL_QUEUE_TEST_CACHE_READS'
            ][canonicalIngredientFoodOnCachePath()] ?? 0),
            'resident_hits' => (int)($GLOBALS[
                'CANONICAL_QUEUE_TEST_CACHE_HITS'
            ][canonicalIngredientFoodOnCachePath()] ?? 0),
        ],
        'product_save_p95_ms' => [
            'baseline' => round($baselineProductP95, 3),
            'contended' => round($contendedProductP95, 3),
        ],
        'inventory_add_p95_ms' => [
            'baseline' => round($baselineInventoryP95, 3),
            'contended' => round($contendedInventoryP95, 3),
        ],
    ];
} finally {
    unset(
        $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'],
        $GLOBALS['CANONICAL_QUEUE_TEST_HOOK'],
        $GLOBALS['CANONICAL_QUEUE_TEST_LOCK_PATH'],
        $GLOBALS['CANONICAL_QUEUE_LOCK_WARNING_LAST_AT'],
        $GLOBALS['CANONICAL_FOODON_TEST_LOOKUP'],
        $GLOBALS['CANONICAL_FOODON_TEST_SEARCH'],
        $GLOBALS['CANONICAL_FOODON_TEST_PARENT_TERMS'],
        $GLOBALS['CANONICAL_USDA_TEST_LOOKUP'],
        $GLOBALS['CANONICAL_QUEUE_TEST_PROVIDER_DEADLINE_SECONDS'],
        $GLOBALS['CANONICAL_QUEUE_TEST_CACHE_STORE'],
        $GLOBALS['CANONICAL_QUEUE_TEST_CACHE_READS'],
        $GLOBALS['CANONICAL_QUEUE_TEST_CACHE_HITS'],
        $GLOBALS['CANONICAL_FOODON_TEST_CACHE_PATH'],
        $GLOBALS['CANONICAL_USDA_TEST_CACHE_PATH'],
        $GLOBALS['CANONICAL_QUEUE_TEST_WAKE']
    );
    $paths = glob($root . '/*') ?: [];
    foreach ($paths as $path) {
        if (is_dir($path)) {
            foreach (glob($path . '/*') ?: [] as $nested) {
                if (is_file($nested)) {
                    unlink($nested);
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($root)) {
        rmdir($root);
    }
    if (
        $createdTempParent
        && is_dir($tempParent)
        && (scandir($tempParent) ?: []) === ['.', '..']
    ) {
        rmdir($tempParent);
    }
}

echo 'Canonical queue tests passed: '
    . $assertions
    . ' assertions; '
    . json_encode(
        $metrics ?? [],
        JSON_UNESCAPED_SLASHES
    )
    . PHP_EOL;
