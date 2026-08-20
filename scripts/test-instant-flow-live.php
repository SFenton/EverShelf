#!/usr/bin/env php
<?php
declare(strict_types=1);

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
$runs = max(1, min(30, (int)($options['runs'] ?? 10)));
$startIndex = max(0, (int)($options['start-index'] ?? 0));
$jsonOut = trim((string)($options['json-out'] ?? ''));

foreach ([
    'GEMINI_API_KEY',
    'GOOGLE_API_KEY',
    'GOOGLE_GENERATIVE_AI_API_KEY',
] as $key) {
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
    if (trim((string)getenv($key)) !== '') {
        throw new RuntimeException(
            'key-based Gemini environment must be empty'
        );
    }
}

$db = new PDO(
    'sqlite:' . $databasePath,
    null,
    null,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA busy_timeout=10000');
ingredientOntologyV3SchemaMigrate($db);
recipeSchemaMigrate($db);

$active = recipeScoreActiveRevision($db);
if (
    $active === null
    || $active['ontology_version_id'] === null
    || (string)$active['scoring_model'] !== 'faceted-ontology-v3'
) {
    throw new RuntimeException(
        'live harness requires an active ontology v3 score revision'
    );
}
$versionId = (int)$active['ontology_version_id'];
$version = ingredientOntologyV3Version($db, $versionId);
if ($version === null || $version['status'] !== 'ready') {
    throw new RuntimeException('active ontology version is unavailable');
}
$contentHashBefore = ingredientOntologyV3ContentHash($db, $versionId);

$products = [];
$productStmt = $db->prepare("
    SELECT *
    FROM products
    WHERE id = ?
");
foreach ([
    'russet' => 201,
    'onion' => 202,
] as $key => $productId) {
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($product === null) {
        throw new RuntimeException(
            "required harness product {$productId} is unavailable"
        );
    }
    $products[$key] = $product;
    $db->prepare("
        UPDATE products
        SET last_location = NULL, last_location_at = NULL
        WHERE id = ?
    ")->execute([$productId]);
    $admission = ingredientOntologyV3IdentityAnnexRefreshProduct(
        $db,
        $productId,
        $versionId
    );
    if (empty($admission['accepted'])) {
        throw new RuntimeException(
            "product {$productId} did not receive deterministic identity"
        );
    }
}

$GLOBALS['LOCATION_AI_ENABLED'] = true;
$lockDirectory = dirname($databasePath)
    . '/.instant-flow-location-locks';
if (
    !is_dir($lockDirectory)
    && !mkdir($lockDirectory, 0770, true)
    && !is_dir($lockDirectory)
) {
    throw new RuntimeException('location harness lock directory failed');
}
$GLOBALS['LOCATION_SUGGESTION_LOCK_DIR'] = $lockDirectory;

$transportCalls = 0;
$lastTransport = null;
$GLOBALS['LOCATION_SUGGESTION_TRANSPORT'] =
    static function (
        array $request,
        float $deadline
    ) use (&$transportCalls, &$lastTransport): array {
        $transportCalls++;
        $result = evershelfCopilotSocketCall($request, $deadline);
        $lastTransport = [
            'request' => $request,
            'result' => $result,
        ];
        return $result;
    };

$callLocation = static function (
    PDO $db,
    array $input
): array {
    $GLOBALS['LOCATION_SUGGESTION_INPUT'] = $input;
    ob_start();
    suggestLocation($db);
    $response = json_decode((string)ob_get_clean(), true);
    if (!is_array($response)) {
        throw new RuntimeException(
            'location harness received invalid JSON'
        );
    }
    return $response;
};
$callProductSave = static function (
    PDO $db,
    array $product
): array {
    $payload = [
        'id' => (int)$product['id'],
        'barcode' => $product['barcode'] ?? null,
        'name' => (string)$product['name'],
        'brand' => (string)($product['brand'] ?? ''),
        'category' => (string)($product['category'] ?? ''),
        'image_url' => (string)($product['image_url'] ?? ''),
        'unit' => (string)($product['unit'] ?? 'pz'),
        'default_quantity' =>
            (float)($product['default_quantity'] ?? 1),
        'notes' => (string)($product['notes'] ?? ''),
        'package_unit' =>
            (string)($product['package_unit'] ?? ''),
    ];
    if (!empty($product['prepared_food'])) {
        $payload['prepared_food'] = true;
    }
    $GLOBALS['PRODUCT_API_JSON_INPUT'] = $payload;
    ob_start();
    saveProduct($db);
    $response = json_decode((string)ob_get_clean(), true);
    if (
        !is_array($response)
        || empty($response['success'])
        || (int)($response['id'] ?? 0) !== (int)$product['id']
        || !preg_match(
            '/^[a-f0-9]{64}$/D',
            (string)($response['product_fingerprint'] ?? '')
        )
    ) {
        throw new RuntimeException(
            'product commit did not return its current fingerprint'
        );
    }
    return $response;
};

$percentile = static function (array $values, float $p): float {
    if (!$values) {
        return 0.0;
    }
    sort($values, SORT_NUMERIC);
    $index = (int)ceil($p * count($values)) - 1;
    return (float)$values[max(0, min(count($values) - 1, $index))];
};

$rows = [];
$locationLatencies = [];
$productSaveLatencies = [];
$scoreLatencies = [];
$prefixes = [
    'russet' => ['R', 'Ru', 'Russ', 'Russet Potato'],
    'onion' => ['R', 'Red', 'Red Oni'],
];
for ($run = 0; $run < $runs; $run++) {
    $runIndex = $startIndex + $run;
    $row = [
        'run' => $runIndex,
        'products' => [],
    ];
    foreach ($products as $key => $product) {
        $productId = (int)$product['id'];
        $saveStarted = hrtime(true);
        $save = $callProductSave($db, $product);
        $saveElapsedMs = (hrtime(true) - $saveStarted) / 1000000;
        $productSaveLatencies[] = $saveElapsedMs;
        $fingerprint = (string)$save['product_fingerprint'];
        $savedProductStmt = $db->prepare("
            SELECT shopping_name, shopping_name_provenance
            FROM products
            WHERE id = ?
        ");
        $savedProductStmt->execute([$productId]);
        $savedProduct =
            $savedProductStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (
            (string)($savedProduct['shopping_name'] ?? '')
                !== (string)($product['shopping_name'] ?? '')
            || (string)(
                $savedProduct['shopping_name_provenance'] ?? ''
            ) !== (string)(
                $product['shopping_name_provenance'] ?? ''
            )
        ) {
            throw new RuntimeException(
                'scanner-shaped product save changed shopping provenance'
            );
        }
        $beforePrefixes = $transportCalls;
        foreach ($prefixes[$key] as $prefix) {
            $prefixResult = $callLocation($db, [
                'mode' => 'manual',
                'name' => $prefix,
                'category' => (string)$product['category'],
            ]);
            if (
                (string)($prefixResult['reason'] ?? '')
                    !== 'committed_product_required'
                || (string)($prefixResult['location'] ?? '')
                    !== 'unknown'
            ) {
                throw new RuntimeException(
                    'partial input did not fail closed before commit'
                );
            }
        }
        if ($transportCalls !== $beforePrefixes) {
            throw new RuntimeException(
                'partial input invoked Copilot transport'
            );
        }

        $db->exec('DELETE FROM location_suggestion_cache');
        $lastTransport = null;
        $started = hrtime(true);
        $location = $callLocation($db, [
            'mode' => 'manual',
            'name' => (string)$product['name'],
            'category' => (string)$product['category'],
            'product_id' => $productId,
            'product_fingerprint' => $fingerprint,
        ]);
        $elapsedMs = (hrtime(true) - $started) / 1000000;
        $locationLatencies[] = $elapsedMs;
        if (
            (string)($location['source'] ?? '') !== 'copilot_socket'
            || empty($location['available'])
            || !is_array($lastTransport)
            || (string)(
                $lastTransport['result']['source'] ?? ''
            ) !== 'copilot_socket'
        ) {
            throw new RuntimeException(
                'committed location request did not use Copilot socket'
            );
        }
        $request = (array)$lastTransport['request'];
        $expectedRequestHash = hash(
            'sha256',
            ingredientOntologyControllerStableJson($request)
        );
        if (
            !hash_equals(
                $expectedRequestHash,
                (string)(
                    $lastTransport['result']['request_hash'] ?? ''
                )
            )
            || (string)($request['priority'] ?? '') !== 'interactive'
        ) {
            throw new RuntimeException(
                'Copilot socket request attestation failed'
            );
        }
        $row['products'][$key] = [
            'product_id' => $productId,
            'identity_entity_id' => (
                ingredientOntologyV3IdentityAnnexMapping(
                    $db,
                    $versionId,
                    $productId,
                    $fingerprint
                )['entity_id'] ?? null
            ),
            'location' => $location['location'] ?? null,
            'location_elapsed_ms' => round($elapsedMs, 3),
            'product_save_elapsed_ms' => round(
                $saveElapsedMs,
                3
            ),
            'shopping_name_provenance' =>
                $savedProduct['shopping_name_provenance'] ?? null,
            'request_hash' => $expectedRequestHash,
        ];
    }

    recipeScoreMarkProductDirty(
        $db,
        (int)$products['russet']['id'],
        'live_harness_russet'
    );
    recipeScoreMarkProductDirty(
        $db,
        (int)$products['onion']['id'],
        'live_harness_onion'
    );
    $GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_OVERLAY_PUBLICATION'] =
        static function (
            PDO $db,
            int $revisionId,
            int $parentId,
            array $recipeIds
        ): void {
            $active = recipeScoreActiveRevision($db);
            $state = recipeScoreState($db);
            if (
                $active === null
                || (int)$active['id'] !== $revisionId
                || (int)$active['parent_score_revision_id']
                    !== $parentId
                || (int)(
                    $state['active_score_projection_revision_id']
                        ?? 0
                ) !== $revisionId
                || !$recipeIds
            ) {
                throw new RuntimeException(
                    'sparse score revision was not readable after publication'
                );
            }
        };
    $score = ingredientOntologyV3IncrementalRebuild(
        $db,
        true,
        500
    );
    unset($GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_OVERLAY_PUBLICATION']);
    if (
        empty($score['rebuilt'])
        || !empty($score['cleanup_warning'])
    ) {
        throw new RuntimeException(
            'incremental score publication failed: '
            . (string)(
                $score['error']
                    ?? $score['reason']
                    ?? $score['cleanup_warning']
                    ?? ''
            )
        );
    }
    $visibleMs = (float)($score['visible_ms'] ?? PHP_FLOAT_MAX);
    $scoreLatencies[] = $visibleMs;
    $row['score'] = [
        'revision_id' => (int)$score['revision_id'],
        'visible_ms' => round($visibleMs, 3),
        'elapsed_ms' => (float)$score['elapsed_ms'],
        'affected_recipe_count' =>
            (int)$score['affected_recipe_count'],
    ];
    $rows[] = $row;
}

$contentHashAfter = ingredientOntologyV3ContentHash($db, $versionId);
if (!hash_equals($contentHashBefore, $contentHashAfter)) {
    throw new RuntimeException(
        'live harness changed the sealed ontology content hash'
    );
}
$report = [
    'success' => true,
    'database' => basename($databasePath),
    'runs' => $runs,
    'start_index' => $startIndex,
    'provider' => 'copilot_socket',
    'model' => LOCATION_SUGGESTION_MODEL,
    'key_environment_empty' => true,
    'transport_calls' => $transportCalls,
    'partial_ai_calls' => 0,
    'location_latency_ms' => [
        'p50' => round($percentile($locationLatencies, 0.50), 3),
        'p95' => round($percentile($locationLatencies, 0.95), 3),
        'max' => round(max($locationLatencies), 3),
    ],
    'product_save_latency_ms' => [
        'p50' => round($percentile($productSaveLatencies, 0.50), 3),
        'p95' => round($percentile($productSaveLatencies, 0.95), 3),
        'max' => round(max($productSaveLatencies), 3),
    ],
    'score_visibility_ms' => [
        'p50' => round($percentile($scoreLatencies, 0.50), 3),
        'p95' => round($percentile($scoreLatencies, 0.95), 3),
        'max' => round(max($scoreLatencies), 3),
    ],
    'ontology_content_hash' => $contentHashAfter,
    'rows' => $rows,
];
$encoded = json_encode(
    $report,
    JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
if ($jsonOut !== '') {
    $directory = dirname($jsonOut);
    if (
        !is_dir($directory)
        && !mkdir($directory, 0770, true)
        && !is_dir($directory)
    ) {
        throw new RuntimeException(
            'live harness report directory is unavailable'
        );
    }
    if (file_put_contents($jsonOut, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('live harness report write failed');
    }
}
echo $encoded;
