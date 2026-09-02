#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/index.php';

$GLOBALS['RECIPE_COOKIDOO_CONFIG'] = [
    'COOKIDOO_CONNECTOR_ENABLED' => 'true',
    'COOKIDOO_BRIDGE_URL' => 'http://cookidoo-bridge:8081',
    'COOKIDOO_BRIDGE_TOKEN' => 'unit-test-token',
    'COOKIDOO_BRIDGE_TIMEOUT_SECONDS' => '5',
    'COOKIDOO_RESULT_LIMIT' => '20',
    'COOKIDOO_METADATA_REFRESH_DAYS' => '14',
    'COOKIDOO_DETAIL_HYDRATION_ENABLED' => 'true',
    'COOKIDOO_METADATA_BACKFILL_ENABLED' => 'false',
    'COOKIDOO_METADATA_BACKFILL_BATCH_SIZE' => '20',
    'COOKIDOO_QUEUE_CADENCE_MINUTES' => '5',
    'COOKIDOO_PERIODIC_REFRESH_ENABLED' => 'true',
    'COOKIDOO_INGEST_LANGUAGE_POLICY' => 'observe',
];
$GLOBALS['RECIPE_SCORE_PREVIEW_REVISION_ID'] = '';

$recipeTestAssertions = 0;
function recipeTestAssert(bool $condition, string $message): void {
    global $recipeTestAssertions;
    $recipeTestAssertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function recipeTestCount(PDO $db, string $sql, array $params = []): int {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function recipeTestCookidooBridgeRecipe(array $recipe): array {
    $ingredients = $recipe['ingredients'] ?? [];
    $groups = [];
    $metrics = [
        'group_count' => 0,
        'group_title_key_count' => 0,
        'group_title_nonempty_count' => 0,
        'group_title_length_total' => 0,
        'group_title_length_max' => 0,
        'ingredient_count' => count($ingredients),
        'ingredient_ref_key_count' => 0,
        'ingredient_ref_nonempty_count' => 0,
        'default_title_key_count' => 0,
        'default_title_nonempty_count' => 0,
        'unit_ref_key_count' => 0,
        'unit_ref_nonempty_count' => 0,
        'optional_key_count' => 0,
        'optional_true_count' => 0,
        'optional_false_count' => 0,
        'optional_null_count' => 0,
        'shopping_category_ref_key_count' => 0,
        'shopping_category_ref_nonempty_count' => 0,
    ];
    foreach ($ingredients as $ingredient) {
        $groupIndex = (int)($ingredient['source_group_index'] ?? 0);
        if (!isset($groups[$groupIndex])) {
            $groups[$groupIndex] = true;
            if (array_key_exists('source_group_title', $ingredient)) {
                $metrics['group_title_key_count']++;
            }
            $groupTitle = trim((string)(
                $ingredient['source_group_title'] ?? ''
            ));
            if ($groupTitle !== '') {
                $length = mb_strlen($groupTitle, 'UTF-8');
                $metrics['group_title_nonempty_count']++;
                $metrics['group_title_length_total'] += $length;
                $metrics['group_title_length_max'] = max(
                    $metrics['group_title_length_max'],
                    $length
                );
            }
        }
        foreach ([
            'source_ingredient_ref' => 'ingredient_ref',
            'source_default_title' => 'default_title',
            'source_unit_ref' => 'unit_ref',
            'source_shopping_category_ref' => 'shopping_category_ref',
        ] as $field => $metricPrefix) {
            if (array_key_exists($field, $ingredient)) {
                $metrics[$metricPrefix . '_key_count']++;
            }
            if (trim((string)($ingredient[$field] ?? '')) !== '') {
                $metrics[$metricPrefix . '_nonempty_count']++;
            }
        }
        if (array_key_exists('source_optional', $ingredient)) {
            $metrics['optional_key_count']++;
        }
        if (($ingredient['source_optional'] ?? null) === true) {
            $metrics['optional_true_count']++;
        } elseif (($ingredient['source_optional'] ?? null) === false) {
            $metrics['optional_false_count']++;
        } else {
            $metrics['optional_null_count']++;
        }
    }
    $metrics['group_count'] = count($groups);
    $recipe['metadata_schema_version'] =
        RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION;
    $recipe['topology_metrics'] = $metrics;
    return $recipe;
}

recipeTestAssert(
    recipeCookidooQueueCadenceDue(100 * 60)
    && !recipeCookidooQueueCadenceDue(101 * 60),
    'Cookidoo cadence must use minute-granularity scheduling'
);
recipeTestAssert(
    in_array(
        'recipe_catalog_recommendations',
        evershelfDemoReadOnlyActions(),
        true
    )
    && in_array('recipe_catalog_detail', evershelfDemoReadOnlyActions(), true)
    && !in_array('recipe_catalog_grocery_add', evershelfDemoReadOnlyActions(), true),
    'Demo mode must allow recipe reads while blocking grocery mutations'
);
foreach ([
    'all-purpose flour' => 'All-Purpose Flour',
    'all purpose flour' => 'All-Purpose Flour',
    'cornmeal' => 'Cornmeal',
    'cornmeal, finely ground' => 'Cornmeal',
    'chicken thighs, boneless and skinless' => 'Chicken Thighs',
    'chicken thighs, skin on and bone in' => 'Chicken Thighs',
    'chicken thighs, air-chilled, boneless, skinless' => 'Chicken Thighs',
    'cake flour' => 'Cake Flour',
    'extra virgin olive oil' => 'Extra Virgin Olive Oil',
    'vegetable oil' => 'Vegetable Oil',
    'chapati (atta) flour' => 'Chapati Flour',
    'crème fraîche' => 'Crème Fraîche',
] as $sourceText => $expectedDisplayName) {
    recipeTestAssert(
        recipeIngredientDisplayName($sourceText) === $expectedDisplayName,
        'Ingredient display normalization failed for: ' . $sourceText
    );
}
recipeTestAssert(
    recipeIngredientDisplayName('pepper (Aleppo)') === 'Pepper (Aleppo)'
    && recipeIngredientDisplayName('beans, rice and tomatoes')
        === 'Beans, Rice And Tomatoes'
    && recipeIngredientDisplayName('...', 'fallback ingredient') === '...',
    'Ingredient display normalization must preserve meaningful punctuation and fallback text'
);
foreach ([
    ['1 can tomatoes', ['text' => '1 can', 'unit' => 'can'], 'Tomatoes'],
    ['2 tins chopped tomatoes', ['text' => '2 tins', 'unit' => 'tins'], 'Chopped Tomatoes'],
    ['500 g flour', ['text' => '500 g', 'unit' => 'g'], 'Flour'],
    ['2 - 3 tins tomatoes', ['text' => '2 - 3 tins', 'unit' => 'tins'], 'Tomatoes'],
    ['1/2 cup sugar', ['text' => '1/2 cup', 'unit' => 'cup'], 'Sugar'],
] as [$sourceText, $amount, $expectedDisplayName]) {
    recipeTestAssert(
        recipeIngredientDisplayName(
            $sourceText,
            '',
            true,
            $amount
        ) === $expectedDisplayName,
        'Ingredient amount-prefix normalization failed for: ' . $sourceText
    );
}
foreach ([
    '1 can tomatoes' => 'Tomatoes',
    '2 tins chopped tomatoes' => 'Chopped Tomatoes',
    '500 g flour' => 'Flour',
    '1-2 jars passata' => 'Passata',
    '1/2 cup milk' => 'Milk',
    '2 bottles stock' => 'Stock',
    '1 package pasta' => 'Pasta',
    '1 pack yeast' => 'Yeast',
    '3 bunches herbs' => 'Herbs',
    '2 tablespoons oil' => 'Oil',
    '3 tsp sugar' => 'Sugar',
    '2 kg potatoes' => 'Potatoes',
    '250 ml stock' => 'Stock',
    '1 l broth' => 'Broth',
    '4 oz cheese' => 'Cheese',
    '2 lb potatoes' => 'Potatoes',
    '3 pieces fruit' => 'Fruit',
] as $sourceText => $expectedDisplayName) {
    recipeTestAssert(
        recipeIngredientDisplayName($sourceText) === $expectedDisplayName,
        'Legacy ingredient amount-prefix normalization failed for: '
            . $sourceText
    );
}
recipeTestAssert(
    recipeIngredientDisplayName('7 Up') === '7 Up'
    && recipeIngredientDisplayName('1000 Island dressing')
        === '1000 Island Dressing'
    && recipeIngredientDisplayName('7 spice blend', '', true)
        === '7 Spice Blend'
    && recipeIngredientDisplayName('100 grand chocolate', '', true)
        === '100 Grand Chocolate'
    && recipeIngredientDisplayName(
        '1 large egg',
        '',
        true,
        ['text' => '1 large', 'unit' => 'large']
    ) === '1 Large Egg',
    'Ingredient amount-prefix normalization must not strip an unsafe word after a number'
);
foreach ([
    ['1 can tomatoes', 'tomatoes'],
    ['500 g flour', 'flour'],
    ['2 - 3 cans tomatoes', 'tomatoes'],
    ['1/2 cup flour', 'flour'],
    ['1 1/2 cups flour', 'flour'],
    ['7 Up', '7 Up'],
    ['1000 Island dressing', '1000 Island dressing'],
    ['7 spice blend', '7 spice blend'],
    ['1 large egg', '1 large egg'],
    ['7', '7'],
] as [$sourceText, $expectedIdentity]) {
    recipeTestAssert(
        recipeIngredientIdentityCandidate($sourceText) === $expectedIdentity,
        'Ingredient identity cleanup failed for: ' . $sourceText
    );
}
recipeTestAssert(
    recipeIngredientIdentityCandidate(
        '1 can tomatoes',
        ['text' => '2 cans', 'unit' => 'cans']
    ) === '1 can tomatoes',
    'Ingredient identity cleanup must honor parsed amount mismatches'
);
recipeTestAssert(
    mb_strlen(
        recipeIngredientBoundedSourceText(str_repeat('é', 250)),
        'UTF-8'
    ) === 200,
    'Ingredient source text must remain bounded by characters'
);
$unsafeDescriptorMapping = [
    'mapping_source' => 'taxonomy_rule',
    'canonical_ingredient_id' => 99,
    'taxonomy_node_id' => 100,
    'source_text' => 'chicken thighs, boneless and skinless',
    'normalized_name' => 'chicken thighs boneless and skinless',
    'display_name' => 'Chicken Thighs',
];
$unsafeAirChilledMapping = $unsafeDescriptorMapping;
$unsafeAirChilledMapping['source_text'] =
    'chicken thighs, air-chilled, boneless, skinless';
$unsafeAirChilledMapping['normalized_name'] =
    'chicken thighs air chilled boneless skinless';
recipeTestAssert(
    recipeDetailShoppingName($unsafeDescriptorMapping) === 'Chicken Thighs'
    && recipeDetailCanonicalShoppingKey($unsafeDescriptorMapping)
        === 'name:chicken thighs boneless and skinless'
    && recipeDetailCanonicalShoppingKey($unsafeAirChilledMapping)
        === 'name:chicken thighs air chilled boneless skinless',
    'Unsafe mappings must use display names for groceries while deduping by '
        . 'normalized source identity'
);
recipeTestAssert(
    recipeCookidooMetadataBackfillEnabled() === false,
    'Cookidoo direct-ID metadata backfill must be disabled by default'
);
recipeTestAssert(
    recipeCookidooNormalizeOptionalSeconds(
        2682000,
        'total_time_seconds'
    ) === 2682000
    && recipeCookidooNormalizeOptionalSeconds(
        RECIPE_COOKIDOO_MAX_RECIPE_SECONDS,
        'total_time_seconds'
    ) === 31622400,
    'PHP metadata normalization must accept factual durations through 366 days'
);
recipeTestAssert(
    recipeCatalogNormalizeOptionalSeconds(
        2682000,
        'total_time_seconds'
    ) === 2682000
    && recipeCatalogNormalizeOptionalSeconds(
        RECIPE_MAX_FACTUAL_DURATION_SECONDS,
        'total_time_seconds'
    ) === 31622400,
    'Generic recipe imports must share the bounded 366-day duration ceiling'
);
$derivedCookidooGeneral = recipeCookidooNormalizeGeneral([
    'active_time_seconds' => 600,
    'total_time_seconds' => 1800,
    'devices' => ['TM6', 'Oven'],
    'optional_devices' => ['Slow cooker', 'tm6'],
]);
recipeTestAssert(
    $derivedCookidooGeneral['prep_time_seconds'] === null
    && $derivedCookidooGeneral['cook_time_seconds'] === null
    && $derivedCookidooGeneral['active_time_seconds'] === 600
    && $derivedCookidooGeneral['inactive_time_seconds'] === 1200
    && $derivedCookidooGeneral['total_time_seconds'] === 1800
    && $derivedCookidooGeneral['devices'] === ['TM6', 'Oven']
    && $derivedCookidooGeneral['optional_devices'] === ['Slow cooker'],
    'Cookidoo normalization must derive only inactive/rest time from active '
        . 'and total facts while keeping devices separate'
);
recipeTestAssert(
    recipeTimeParseDurationSeconds('PT1H30M', 'en') === 5400
    && recipeTimeParseDurationSeconds('15 minuti', 'it') === 900
    && recipeTimeParseDurationSeconds(
        '1 Stunde und 5 Minuten',
        'de'
    ) === 3900
    && recipeTimeParseDurationSeconds('P1DT', 'en') === null
    && recipeTimeParseDurationSeconds('about 20 minutes', 'en') === null
    && recipeTimeParseDurationSeconds('20-25 min', 'en') === null,
    'Local time fallback must parse only bounded deterministic duration fields'
);
$timeProposal = recipeTimeBuildModelProposal(
    'prep_time',
    'a short while',
    'en',
    'manual',
    ['model' => 'review-only']
);
$resolvedTimeProposalRejected = false;
try {
    recipeTimeBuildModelProposal(
        'cook_time',
        '20 minutes',
        'en',
        'manual',
        ['model' => 'review-only']
    );
} catch (InvalidArgumentException $e) {
    $resolvedTimeProposalRejected = true;
}
recipeTestAssert(
    ($timeProposal['manifest']['staging_only'] ?? false) === true
    && ($timeProposal['manifest']['field'] ?? '') === 'prep_time'
    && $resolvedTimeProposalRejected,
    'Time model fallback must remain an inert proposal-only interface for '
        . 'unresolved non-Cookidoo fields'
);
$openApi = file_get_contents(__DIR__ . '/../docs/openapi.yaml');
recipeTestAssert(
    is_string($openApi)
    && preg_match_all(
        '/^\s+prep_time_seconds:\s*$/m',
        $openApi
    ) === 2
    && preg_match_all(
        '/^\s+cook_time_seconds:\s*$/m',
        $openApi
    ) === 2
    && preg_match_all(
        '/^\s+inactive_time_seconds:\s*$/m',
        $openApi
    ) === 2
    && preg_match_all('/^\s+devices:\s*$/m', $openApi) >= 2
    && preg_match_all(
        '/^\s+optional_devices:\s*$/m',
        $openApi
    ) === 2,
    'OpenAPI must expose additive bounded recipe time and device facts'
);
$durationOverflowRejected = false;
try {
    recipeCookidooNormalizeOptionalSeconds(
        RECIPE_COOKIDOO_MAX_RECIPE_SECONDS + 1,
        'total_time_seconds'
    );
} catch (InvalidArgumentException $e) {
    $durationOverflowRejected = true;
}
recipeTestAssert(
    $durationOverflowRejected,
    'PHP metadata normalization must reject durations above 366 days'
);
$pilotScheduleAt = recipeCookidooMetadataBackfillScheduleAt(
    'pilot-schedule-test',
    1,
    1000000
);
$pilotScheduleDelay = strtotime((string)$pilotScheduleAt . ' UTC')
    - 1000000;
recipeTestAssert(
    $pilotScheduleDelay >= 100
    && $pilotScheduleDelay <= 140
    && recipeCookidooMetadataBackfillScheduleAt(
        'pilot-schedule-test',
        0,
        1000000
    ) === null,
    'Metadata pilot jobs must be paced about two minutes apart with bounded jitter'
);
recipeTestAssert(
    recipeCookidooDiscoveryLocaleMatches('en', 'en-GB')
    && recipeCookidooDiscoveryLocaleMatches('en-US', 'en-GB')
    && recipeCookidooDiscoveryLocaleMatches('zh-Hans', 'zh-Hans')
    && recipeCookidooDiscoveryLocaleMatches('zh-Hans', 'zh-Hant')
    && !recipeCookidooDiscoveryLocaleMatches('de-DE', 'en-GB'),
    'Discovery locale fallback must retain the requested base language'
);

$dbPath = __DIR__ . '/../data/.recipe-backend-test-' . getmypid() . '.sqlite';
$upgradePath = __DIR__ . '/../data/.recipe-backend-upgrade-test-' . getmypid() . '.sqlite';
$disabledPath = __DIR__ . '/../data/.recipe-backend-disabled-test-' . getmypid() . '.sqlite';
$cleanupPaths = [
    $dbPath, $dbPath . '-wal', $dbPath . '-shm',
    dirname($dbPath) . '/.' . basename($dbPath) . '.recipe-score.lock',
    $upgradePath, $upgradePath . '-wal', $upgradePath . '-shm',
    dirname($upgradePath) . '/.' . basename($upgradePath)
        . '.recipe-score.lock',
    $disabledPath, $disabledPath . '-wal', $disabledPath . '-shm',
    dirname($disabledPath) . '/.' . basename($disabledPath)
        . '.recipe-score.lock',
];
foreach ($cleanupPaths as $path) {
    if (file_exists($path)) {
        unlink($path);
    }
}

try {
    $upgradeDb = new PDO('sqlite:' . $upgradePath);
    $upgradeDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $upgradeDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $upgradeDb->exec("
        CREATE TABLE recipe_catalog (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            primary_connector TEXT NOT NULL DEFAULT 'manual',
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            image_url TEXT NOT NULL DEFAULT '',
            language TEXT NOT NULL DEFAULT 'und',
            servings INTEGER DEFAULT NULL,
            prep_time TEXT DEFAULT NULL,
            cook_time TEXT DEFAULT NULL,
            total_time TEXT DEFAULT NULL,
            cuisine TEXT NOT NULL DEFAULT '',
            category TEXT NOT NULL DEFAULT '',
            keywords_json TEXT NOT NULL DEFAULT '[]',
            instructions_json TEXT NOT NULL DEFAULT '[]',
            nutrition_json TEXT NOT NULL DEFAULT '{}',
            storage_policy TEXT NOT NULL DEFAULT 'persistent',
            rights_basis TEXT NOT NULL DEFAULT 'user_or_generated',
            cache_expires_at DATETIME DEFAULT NULL,
            stale_at DATETIME DEFAULT NULL,
            source_payload_json TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            retrieved_at DATETIME DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL
        );
        INSERT INTO recipe_catalog (title) VALUES
            ('Existing metadata-v1 row'),
            ('Ontology source migration target');
        CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            brand TEXT NOT NULL DEFAULT '',
            category TEXT NOT NULL DEFAULT '',
            prepared_food INTEGER NOT NULL DEFAULT 0
        );
        INSERT INTO products (name) VALUES ('Legacy migration product');
        CREATE TABLE product_ingredients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            ingredient_id INTEGER NOT NULL,
            role TEXT NOT NULL DEFAULT 'primary',
            confidence REAL NOT NULL DEFAULT 0,
            source TEXT NOT NULL DEFAULT '',
            evidence TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE taxonomy_aliases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tree_id INTEGER NOT NULL,
            node_id INTEGER NOT NULL,
            alias TEXT NOT NULL,
            normalized_alias TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT '',
            active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE canonical_ingredients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL,
            name TEXT NOT NULL,
            parent_slug TEXT DEFAULT NULL,
            category TEXT NOT NULL DEFAULT '',
            source TEXT NOT NULL DEFAULT '',
            external_ids_json TEXT NOT NULL DEFAULT '{}'
        );
        CREATE TABLE recipe_origins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER NOT NULL,
            connector TEXT NOT NULL,
            external_id TEXT DEFAULT NULL,
            canonical_url TEXT DEFAULT NULL,
            locale TEXT DEFAULT NULL,
            attribution TEXT DEFAULT NULL,
            license TEXT DEFAULT NULL,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            availability TEXT NOT NULL DEFAULT 'available'
        );
        INSERT INTO recipe_origins (
            recipe_id, connector, external_id, locale
        )
        VALUES (1, 'cookidoo', 'legacy-direct-id', 'en-GB');
        CREATE TABLE recipe_source_ingredients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER NOT NULL,
            position INTEGER NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            UNIQUE(recipe_id, position)
        );
        INSERT INTO recipe_source_ingredients (recipe_id, position, name)
        VALUES (1, 0, 'Legacy ingredient');
        CREATE TABLE recipe_score_state (
            id INTEGER PRIMARY KEY CHECK(id = 1),
            inventory_revision INTEGER NOT NULL DEFAULT 1,
            catalog_revision INTEGER NOT NULL DEFAULT 1,
            cursor_revision INTEGER NOT NULL DEFAULT 1,
            active_score_revision_id INTEGER DEFAULT NULL,
            dirty_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_built_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        INSERT INTO recipe_score_state (id) VALUES (1);
        CREATE TABLE recipe_score_revisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            inventory_revision INTEGER NOT NULL,
            catalog_revision INTEGER NOT NULL DEFAULT 1,
            inventory_fingerprint TEXT NOT NULL,
            score_date DATE NOT NULL,
            catalog_max_id INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'building',
            recipe_count INTEGER NOT NULL DEFAULT 0,
            last_error TEXT NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL
        );
        CREATE TABLE recipe_grocery_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            idempotency_key TEXT NOT NULL UNIQUE,
            recipe_id INTEGER NOT NULL,
            request_fingerprint TEXT DEFAULT NULL,
            selection_hash TEXT NOT NULL,
            outcomes_json TEXT NOT NULL DEFAULT '[]',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        INSERT INTO recipe_grocery_requests (
            idempotency_key, recipe_id, selection_hash, outcomes_json
        )
        VALUES ('legacy-upgrade-key', 1, 'legacy-selection-hash', '[]');
        CREATE TABLE recipe_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            idempotency_key TEXT NOT NULL UNIQUE,
            job_type TEXT NOT NULL,
            priority INTEGER NOT NULL DEFAULT 0,
            scope TEXT DEFAULT NULL,
            connector TEXT DEFAULT NULL,
            ingredient_id INTEGER DEFAULT NULL,
            product_id INTEGER DEFAULT NULL,
            query TEXT DEFAULT NULL,
            payload_json TEXT NOT NULL DEFAULT '{}',
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 3,
            next_retry_at DATETIME DEFAULT NULL,
            last_error TEXT NOT NULL DEFAULT '',
            last_result_json TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME DEFAULT NULL,
            finished_at DATETIME DEFAULT NULL
        );
    ");
    $legacyPolicyCases = [
        'legacy-policy-disabled' => [
            'query' => 'legacy disabled policy',
            'policy' => 'metadata-v2-detail-disabled',
            'status' => 'done',
        ],
        'legacy-policy-opt-in' => [
            'query' => 'legacy opt in policy',
            'policy' => 'metadata-v2-allowlisted-opt-in',
            'status' => 'done',
        ],
        'legacy-policy-current' => [
            'query' => 'current v3 policy',
            'policy' => RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
            'status' => 'done',
            'current' => true,
        ],
        'legacy-policy-future' => [
            'query' => 'unknown future policy',
            'policy' => 'metadata-v9-future',
            'status' => 'done',
        ],
        'legacy-policy-absent' => [
            'query' => 'historical absent policy',
            'policy' => null,
            'status' => 'done',
        ],
        'legacy-policy-absent-invalid' => [
            'query' => null,
            'policy' => null,
            'status' => 'done',
            'payload' => [],
        ],
        'legacy-policy-malformed' => [
            'query' => 'malformed policy payload',
            'policy' => null,
            'status' => 'done',
            'payload_json' => '{',
        ],
        'legacy-policy-in-progress' => [
            'query' => 'legacy active policy',
            'policy' => 'metadata-v2-detail-disabled',
            'status' => 'in_progress',
        ],
    ];
    $legacyPolicyIds = [];
    $legacyJobInsert = $upgradeDb->prepare("
        INSERT INTO recipe_jobs (
            idempotency_key, job_type, priority, scope, connector,
            query, payload_json, status, attempts, max_attempts,
            updated_at, started_at
        )
        VALUES (
            ?, 'connector_discovery', 0, ?, 'cookidoo',
            ?, ?, ?, ?, 3,
            CASE WHEN ? = 1
                THEN CURRENT_TIMESTAMP
                ELSE datetime('now', '-30 days')
            END,
            CASE WHEN ? = 'in_progress'
                THEN datetime('now', '-1 hour')
                ELSE NULL
            END
        )
    ");
    foreach ($legacyPolicyCases as $caseKey => $case) {
        $query = (string)($case['query'] ?? '');
        $identityQuery = $query !== '' ? $query : $caseKey;
        $request = recipeCookidooNormalizeDiscoveryInput([
            'query' => $identityQuery,
            'locale' => 'en-GB',
            'limit' => 1,
        ]);
        $payload = $case['payload'] ?? $request;
        if ($case['policy'] !== null) {
            $payload[RECIPE_COOKIDOO_POLICY_FIELD] =
                $case['policy'];
        }
        $payloadJson = $case['payload_json']
            ?? recipeCatalogJsonEncode($payload);
        $idempotencyKey = recipeCookidooDiscoveryIdempotencyKey(
            $request
        );
        if (isset($legacyPolicyIds[$idempotencyKey])) {
            $idempotencyKey .= ':' . $caseKey;
        }
        $legacyJobInsert->execute([
            $idempotencyKey,
            recipeCookidooSearchId($request),
            $query,
            $payloadJson,
            $case['status'],
            $case['status'] === 'in_progress' ? 1 : 0,
            !empty($case['current']) ? 1 : 0,
            $case['status'],
        ]);
        $legacyPolicyIds[$caseKey] =
            (int)$upgradeDb->lastInsertId();
        if (!empty($case['current'])) {
            $upgradeDb->prepare("
                UPDATE recipe_jobs
                SET updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$legacyPolicyIds[$caseKey]]);
        }
    }
    $cursorBeforeRecipeMigration = (int)$upgradeDb->query("
        SELECT cursor_revision
        FROM recipe_score_state
        WHERE id = 1
    ")->fetchColumn();
    $GLOBALS['RECIPE_SCHEMA_TEST_HOOK'] =
        static function (
            string $stage,
            array $context
        ): void {
            if (
                $stage === 'after_marker_insert'
                && ($context['migration_key'] ?? '')
                    === 'recipe_stale_while_revalidate_v1'
            ) {
                throw new RuntimeException(
                    'synthetic stale cursor migration fault'
                );
            }
        };
    $staleCursorMigrationFault = null;
    $upgradeDb->exec('BEGIN IMMEDIATE');
    try {
        recipeSchemaMigrate($upgradeDb);
    } catch (Throwable $error) {
        $staleCursorMigrationFault = $error->getMessage();
    }
    $rawMigrationTransactionRetained =
        databaseTransactionIsActive($upgradeDb);
    $upgradeDb->exec('COMMIT');
    unset($GLOBALS['RECIPE_SCHEMA_TEST_HOOK']);
    recipeTestAssert(
        $staleCursorMigrationFault
            === 'synthetic stale cursor migration fault'
        && $rawMigrationTransactionRetained
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*)
             FROM recipe_schema_migrations
             WHERE migration_key =
                'recipe_stale_while_revalidate_v1'"
        ) === 0
        && (int)$upgradeDb->query("
            SELECT cursor_revision
            FROM recipe_score_state
            WHERE id = 1
        ")->fetchColumn() === $cursorBeforeRecipeMigration,
        'Stale cursor marker and bump must roll back together inside a raw '
            . 'caller transaction: '
            . recipeCatalogJsonEncode([
                'fault' => $staleCursorMigrationFault,
                'transaction_retained' =>
                    $rawMigrationTransactionRetained,
                'marker_count' => recipeTestCount(
                    $upgradeDb,
                    "SELECT COUNT(*)
                     FROM recipe_schema_migrations
                     WHERE migration_key =
                        'recipe_stale_while_revalidate_v1'"
                ),
                'cursor_before' => $cursorBeforeRecipeMigration,
                'cursor_after' => (int)$upgradeDb->query("
                    SELECT cursor_revision
                    FROM recipe_score_state
                    WHERE id = 1
                ")->fetchColumn(),
            ])
    );
    recipeSchemaMigrate($upgradeDb);
    $cursorAfterRecipeMigration = (int)$upgradeDb->query("
        SELECT cursor_revision
        FROM recipe_score_state
        WHERE id = 1
    ")->fetchColumn();
    $legacyEpochsAfterMigration = $upgradeDb->query("
        SELECT id, request_epoch, request_generation, status,
               last_error
        FROM recipe_jobs
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $legacyEpochSnapshot = [];
    foreach ($legacyEpochsAfterMigration as $legacyEpochRow) {
        $legacyEpochSnapshot[(int)$legacyEpochRow['id']] = [
            'request_epoch' =>
                (int)$legacyEpochRow['request_epoch'],
            'request_generation' =>
                (int)$legacyEpochRow['request_generation'],
            'status' => (string)$legacyEpochRow['status'],
            'last_error' => (string)$legacyEpochRow['last_error'],
        ];
    }
    $upgradeDb->exec("
        INSERT INTO recipe_ingredients (
            recipe_id, position, raw_text, normalized_name,
            is_required, is_optional, is_staple,
            source_is_required, source_is_optional, requiredness_source
        )
        VALUES
            (1, 0, 'salt pork', 'salt pork', 0, 0, 1,
             NULL, NULL, 'legacy_backfill'),
            (1, 1, 'salt', 'salt', 0, 0, 1,
             NULL, NULL, 'legacy_backfill'),
            (1, 2, 'optional garnish', 'optional garnish', 0, 1, 0,
             NULL, NULL, 'legacy_backfill')
    ");
    recipeSchemaMigrate($upgradeDb);
    $legacyPolicyRows = $upgradeDb->query("
        SELECT id, payload_json, request_epoch,
               request_generation, status, last_error
        FROM recipe_jobs
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $legacyPolicyById = [];
    foreach ($legacyPolicyRows as $legacyPolicyRow) {
        $decodedPayload = json_decode(
            (string)$legacyPolicyRow['payload_json'],
            true
        );
        $legacyPolicyById[(int)$legacyPolicyRow['id']] = [
            'policy' => is_array($decodedPayload)
                ? ($decodedPayload[
                    RECIPE_COOKIDOO_POLICY_FIELD
                ] ?? null)
                : null,
            'payload_json' =>
                (string)$legacyPolicyRow['payload_json'],
            'request_epoch' =>
                (int)$legacyPolicyRow['request_epoch'],
            'request_generation' =>
                (int)$legacyPolicyRow['request_generation'],
            'status' => (string)$legacyPolicyRow['status'],
            'last_error' => (string)$legacyPolicyRow['last_error'],
        ];
    }
    $legacyJobColumns = array_column(
        $upgradeDb->query("PRAGMA table_info(recipe_jobs)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    $legacyWorkerColumns = array_column(
        $upgradeDb->query("PRAGMA table_info(recipe_worker_leases)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    $maxLegacyEpoch = (int)$upgradeDb->query("
        SELECT COALESCE(MAX(request_epoch), 0)
        FROM recipe_jobs
    ")->fetchColumn();
    $nextLegacyEpoch = (int)$upgradeDb->query("
        SELECT next_epoch
        FROM recipe_job_request_epoch
        WHERE id = 1
    ")->fetchColumn();
    recipeTestAssert(
        $cursorAfterRecipeMigration
            === $cursorBeforeRecipeMigration + 1
        && (int)$upgradeDb->query("
            SELECT cursor_revision
            FROM recipe_score_state
            WHERE id = 1
        ")->fetchColumn() === $cursorAfterRecipeMigration
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*)
             FROM recipe_schema_migrations
             WHERE migration_key IN (
                 'recipe_stale_while_revalidate_v1',
                 'cookidoo_discovery_policy_v3_v1'
             )"
        ) === 2
        && !array_diff(
            [
                'request_epoch', 'request_generation',
                'request_hash', 'lease_token',
                'lease_generation', 'lease_expires_at',
            ],
            $legacyJobColumns
        )
        && !array_diff(
            [
                'lease_token', 'lease_generation',
                'lease_expires_at',
            ],
            $legacyWorkerColumns
        )
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*)
             FROM sqlite_master
             WHERE type = 'index'
               AND name = 'idx_recipe_jobs_lease'"
        ) === 1
        && $nextLegacyEpoch > $maxLegacyEpoch,
        'Legacy job migration must install fenced columns, indexes, epochs, worker leases, and one-shot markers'
    );
    recipeTestAssert(
        $legacyPolicyById[
            $legacyPolicyIds['legacy-policy-disabled']
        ]['policy'] === RECIPE_COOKIDOO_DETAIL_POLICY_VERSION
        && $legacyPolicyById[
            $legacyPolicyIds['legacy-policy-opt-in']
        ]['policy'] === RECIPE_COOKIDOO_DETAIL_POLICY_VERSION
        && $legacyPolicyById[
            $legacyPolicyIds['legacy-policy-absent']
        ]['policy'] === RECIPE_COOKIDOO_DETAIL_POLICY_VERSION
        && $legacyPolicyById[
            $legacyPolicyIds['legacy-policy-current']
        ]['policy'] === RECIPE_COOKIDOO_DETAIL_POLICY_VERSION
        && $legacyPolicyById[
            $legacyPolicyIds['legacy-policy-future']
        ]['policy'] === 'metadata-v9-future'
        && $legacyPolicyById[
            $legacyPolicyIds['legacy-policy-absent-invalid']
        ]['policy'] === null
        && $legacyPolicyById[
            $legacyPolicyIds['legacy-policy-malformed']
        ]['payload_json'] === '{'
        && $legacyPolicyById[
            $legacyPolicyIds['legacy-policy-disabled']
        ]['request_generation'] === 2
        && $legacyPolicyById[
            $legacyPolicyIds['legacy-policy-current']
        ]['request_generation'] === 1
        && $legacyPolicyById[
            $legacyPolicyIds['legacy-policy-future']
        ]['request_generation'] === 1,
        'Cookidoo policy migration must update only known or historically absent discovery policies'
    );
    $legacyInProgressId =
        $legacyPolicyIds['legacy-policy-in-progress'];
    recipeTestAssert(
        $legacyPolicyById[$legacyInProgressId]['status'] === 'retry'
        && $legacyPolicyById[$legacyInProgressId]['last_error']
            === 'legacy lease reclaimed during migration'
        && $legacyPolicyById[$legacyInProgressId]['request_epoch']
            === $legacyEpochSnapshot[$legacyInProgressId][
                'request_epoch'
            ]
        && $legacyPolicyById[$legacyInProgressId][
            'request_generation'
        ] === $legacyEpochSnapshot[$legacyInProgressId][
            'request_generation'
        ],
        'Legacy in-progress reclaim and request epochs must remain stable across repeated migration'
    );
    foreach ($legacyEpochSnapshot as $legacyJobId => $snapshot) {
        recipeTestAssert(
            $legacyPolicyById[$legacyJobId]['request_epoch']
                === $snapshot['request_epoch']
            && $legacyPolicyById[$legacyJobId][
                'request_generation'
            ] === $snapshot['request_generation'],
            'Repeated recipe migration changed request order for job '
                . $legacyJobId
        );
    }
    $periodicMigrated = recipeCookidooEnqueuePeriodicRefreshes(
        $upgradeDb,
        10
    );
    $expectedMigratedPeriodicIds = [
        $legacyPolicyIds['legacy-policy-disabled'],
        $legacyPolicyIds['legacy-policy-opt-in'],
        $legacyPolicyIds['legacy-policy-absent'],
    ];
    sort($expectedMigratedPeriodicIds);
    $actualMigratedPeriodicIds = $periodicMigrated['jobs'];
    sort($actualMigratedPeriodicIds);
    recipeTestAssert(
        $periodicMigrated['queued'] === 3
        && $actualMigratedPeriodicIds
            === $expectedMigratedPeriodicIds,
        'Periodic refresh selection must include migrated policy rows while '
            . 'excluding current and unknown policies: '
            . recipeCatalogJsonEncode([
                'periodic' => $periodicMigrated,
                'expected' => $expectedMigratedPeriodicIds,
                'actual' => $actualMigratedPeriodicIds,
            ])
    );
    $requirednessRows = $upgradeDb->query("
        SELECT normalized_name, is_required, is_optional, is_staple,
               source_is_required, source_is_optional, requiredness_source
        FROM recipe_ingredients
        ORDER BY position
    ")->fetchAll(PDO::FETCH_ASSOC);
    recipeTestAssert(
        (int)$requirednessRows[0]['source_is_required'] === 1
        && (int)$requirednessRows[0]['source_is_optional'] === 0
        && $requirednessRows[0]['requiredness_source']
            === 'legacy_staple_recovery'
        && (int)$requirednessRows[1]['source_is_required'] === 1
        && (int)$requirednessRows[2]['source_is_required'] === 0
        && (int)$requirednessRows[2]['source_is_optional'] === 1
        && (int)$requirednessRows[0]['is_required'] === 0
        && (int)$requirednessRows[0]['is_staple'] === 1,
        'Requiredness migration must recover source intent without rewriting '
            . 'legacy required/staple flags'
    );
    $upgradeCatalogColumns = array_column(
        $upgradeDb->query("PRAGMA table_info(recipe_catalog)")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    foreach ([
        'yield_quantity', 'yield_unit', 'prep_time_seconds',
        'cook_time_seconds', 'active_time_seconds',
        'inactive_time_seconds', 'total_time_seconds', 'difficulty',
        'primary_category', 'devices_json', 'optional_devices_json',
        'equipment_json',
        'instruction_groups_json',
    ] as $column) {
        recipeTestAssert(
            in_array($column, $upgradeCatalogColumns, true),
            'Existing metadata-v1 catalog rows must migrate additively: ' . $column
        );
    }
    recipeTestAssert(
        recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM recipe_catalog
             WHERE title = 'Existing metadata-v1 row'
               AND yield_quantity IS NULL
               AND yield_unit IS NULL
               AND prep_time_seconds IS NULL
               AND cook_time_seconds IS NULL
               AND active_time_seconds IS NULL
               AND inactive_time_seconds IS NULL
               AND total_time_seconds IS NULL
               AND devices_json = '[]'
               AND optional_devices_json = '[]'"
        ) === 1,
        'Existing metadata-v1 rows must remain valid with nullable metadata-v2 fields'
    );
    $upgradeOriginColumns = array_column(
        $upgradeDb->query("PRAGMA table_info(recipe_origins)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    recipeTestAssert(
        !array_diff(
            [
                'metadata_version',
                'metadata_schema_version',
                'metadata_failure_version',
                'metadata_failure_kind',
                'metadata_failure_at',
                'metadata_failure_count',
                'metadata_next_probe_at',
                'metadata_failure_schema_version',
                'last_applied_request_epoch',
            ],
            $upgradeOriginColumns
        )
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM recipe_origins
             WHERE external_id = 'legacy-direct-id'
               AND metadata_version IS NULL
               AND metadata_schema_version IS NULL"
        ) === 1,
        'Existing origins must gain nullable metadata outcome markers'
    );
    $upgradeSourceColumns = array_column(
        $upgradeDb->query("PRAGMA table_info(recipe_source_ingredients)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    foreach ([
        'normalized_name', 'source_quantity', 'source_quantity_max',
        'source_unit', 'source_amount_text', 'canonical_ingredient_id',
        'taxonomy_node_id', 'mapping_confidence', 'mapping_source',
        'source_group_index', 'source_group_position', 'mapping_version',
        'source_group_title', 'source_ingredient_ref',
        'source_default_title', 'source_unit_ref', 'source_optional',
        'source_shopping_category_ref',
        'created_at', 'updated_at',
    ] as $column) {
        recipeTestAssert(
            in_array($column, $upgradeSourceColumns, true),
            'Pre-v2 source ingredients must gain guarded column: ' . $column
        );
    }
    recipeTestAssert(
        $upgradeDb->query("
            SELECT name
            FROM recipe_source_ingredients
            WHERE recipe_id = 1 AND position = 0
        ")->fetchColumn() === 'Legacy ingredient'
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM recipe_source_ingredients
             WHERE recipe_id = 1
               AND source_group_index IS NULL
               AND source_group_position IS NULL
               AND source_group_title IS NULL
               AND source_ingredient_ref IS NULL
               AND source_default_title IS NULL
               AND source_unit_ref IS NULL
               AND source_optional IS NULL
               AND source_shopping_category_ref IS NULL
               AND mapping_version = 'legacy-v1'"
        ) === 1
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'index'
               AND name = 'idx_recipe_origins_metadata_version'"
        ) === 1
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'index'
               AND name = 'idx_recipe_origins_metadata_failure'"
        ) === 1,
        'Pre-v2 source rows must survive additive migration with indexed origin outcomes'
    );
    recipeTestAssert(
        recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'index'
               AND name = 'idx_recipe_origins_metadata_probe'"
        ) === 1
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'index'
               AND name = 'idx_recipe_origins_metadata_candidates'"
        ) === 1
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'index'
               AND name = 'idx_recipe_source_ingredients_grouped'"
        ) === 1
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'index'
               AND name = 'idx_recipe_source_ingredients_mapping_version'"
        ) === 1
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'index'
               AND name = 'idx_recipe_origins_metadata_schema'"
        ) === 1
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'index'
               AND name = 'idx_recipe_source_ingredients_provider_ref'"
        ) === 1
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'index'
               AND name = 'idx_recipe_source_ingredients_unit_ref'"
        ) === 1,
        'Grouped source mappings and reconsideration probes must be indexed'
    );
    $invalidTopologyColumnRejected = false;
    try {
        $upgradeDb->exec("
            INSERT INTO recipe_source_ingredients (
                recipe_id, position, name, source_optional
            )
            VALUES (1, 1, 'Invalid optional', 2)
        ");
    } catch (PDOException $e) {
        $invalidTopologyColumnRejected = true;
    }
    recipeTestAssert(
        $invalidTopologyColumnRejected,
        'Source topology migrations must retain bounded nullable constraints'
    );
    $candidatePlan = $upgradeDb->query("
        EXPLAIN QUERY PLAN
        SELECT id
        FROM recipe_origins
        WHERE connector = 'cookidoo'
          AND lower(locale) = lower('en-GB')
          AND id > 0
        ORDER BY id
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
    recipeTestAssert(
        str_contains(
            implode(' ', array_column($candidatePlan, 'detail')),
            'idx_recipe_origins_metadata_candidates'
        ),
        'Metadata reconsideration candidates must use the bounded locale/index path'
    );
    $groupReadPlan = $upgradeDb->query("
        EXPLAIN QUERY PLAN
        SELECT id
        FROM recipe_source_ingredients
        WHERE recipe_id = 1
        ORDER BY source_group_index, source_group_position, position
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
    recipeTestAssert(
        str_contains(
            implode(' ', array_column($groupReadPlan, 'detail')),
            'idx_recipe_source_ingredients_grouped'
        ),
        'One-recipe grouped ingredient reads must use the grouped order index'
    );
    $upgradeScoreStateColumns = array_column(
        $upgradeDb->query("PRAGMA table_info(recipe_score_state)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    recipeTestAssert(
        !array_diff(
            [
                'cursor_revision',
                'ontology_source_revision',
                'ontology_source_hash',
                'ontology_source_trigger_version',
                'ontology_source_trigger_hash',
            ],
            $upgradeScoreStateColumns
        ),
        'Cursor-present score state schemas must gain ontology source columns'
    );
    $upgradeDb->exec("
        UPDATE recipe_score_state
        SET ontology_source_hash = '"
            . str_repeat('a', 64) . "'
        WHERE id = 1
    ");
    $sourceRevisionBeforeProduct = (int)$upgradeDb->query("
        SELECT ontology_source_revision
        FROM recipe_score_state
        WHERE id = 1
    ")->fetchColumn();
    $upgradeDb->exec("
        UPDATE products
        SET name = 'Migrated product identity'
        WHERE id = 1
    ");
    $sourceRevisionAfterProduct = (int)$upgradeDb->query("
        SELECT ontology_source_revision
        FROM recipe_score_state
        WHERE id = 1
    ")->fetchColumn();
    $upgradeDb->exec("
        UPDATE recipe_score_state
        SET ontology_source_hash = '"
            . str_repeat('b', 64) . "'
        WHERE id = 1
    ");
    $sourceRevisionBeforeOriginMove = $sourceRevisionAfterProduct;
    $upgradeDb->exec("
        UPDATE recipe_origins
        SET recipe_id = 2
        WHERE id = 1
    ");
    $sourceRevisionAfterOriginMove = (int)$upgradeDb->query("
        SELECT ontology_source_revision
        FROM recipe_score_state
        WHERE id = 1
    ")->fetchColumn();
    recipeTestAssert(
        $sourceRevisionAfterProduct > $sourceRevisionBeforeProduct
        && $sourceRevisionAfterOriginMove
            > $sourceRevisionBeforeOriginMove
        && (string)$upgradeDb->query("
            SELECT ontology_source_hash
            FROM recipe_score_state
            WHERE id = 1
        ")->fetchColumn() === '',
        'Migrated source triggers must compile and track product/origin owners'
    );
    foreach ([
        'recipe_ontology_source_products_update' => [
            'name', 'brand', 'category', 'prepared_food',
        ],
        'recipe_ontology_source_catalog_update' => [
            'primary_connector', 'language', 'deleted_at',
        ],
        'recipe_ontology_source_origins_update' => [
            'recipe_id', 'connector', 'external_id', 'locale',
            'content_language', 'metadata_version',
            'metadata_schema_version',
        ],
        'recipe_ontology_source_ingredients_update' => [
            'recipe_id', 'position', 'raw_text', 'normalized_name',
            'source_is_required', 'source_is_optional',
            'requiredness_source', 'canonical_ingredient_id',
            'taxonomy_node_id', 'mapping_confidence', 'mapping_source',
        ],
        'recipe_ontology_source_rows_update' => [
            'recipe_id', 'position', 'name', 'normalized_name',
            'source_optional', 'source_ingredient_ref',
            'source_default_title', 'canonical_ingredient_id',
            'taxonomy_node_id', 'mapping_confidence', 'mapping_source',
        ],
    ] as $triggerName => $expectedColumns) {
        $triggerSql = (string)$upgradeDb->query("
            SELECT sql
            FROM sqlite_master
            WHERE type = 'trigger'
              AND name = " . $upgradeDb->quote($triggerName) . "
        ")->fetchColumn();
        $matched = preg_match(
            '/AFTER\\s+UPDATE\\s+OF\\s+(.+?)\\s+ON\\s+[a-z_]+/is',
            $triggerSql,
            $triggerMatch
        ) === 1;
        $actualColumns = $matched
            ? explode(
                ',',
                preg_replace('/\\s+/', '', $triggerMatch[1])
            )
            : [];
        sort($actualColumns);
        sort($expectedColumns);
        recipeTestAssert(
            $actualColumns === $expectedColumns,
            'Ontology source update trigger must cover its exact consumed '
                . 'identity fields: ' . $triggerName
        );
    }
    $upgradeDb->exec("
        UPDATE recipe_origins
        SET recipe_id = 1
        WHERE id = 1
    ");
    $sourceRevisionBeforeIdempotentMigration = (int)$upgradeDb->query("
        SELECT ontology_source_revision
        FROM recipe_score_state
        WHERE id = 1
    ")->fetchColumn();
    recipeSchemaMigrate($upgradeDb);
    recipeTestAssert(
        (int)$upgradeDb->query("
            SELECT ontology_source_revision
            FROM recipe_score_state
            WHERE id = 1
        ")->fetchColumn() === $sourceRevisionBeforeIdempotentMigration,
        'Current ontology source triggers must not be replaced or invalidate '
            . 'sources on every schema check'
    );
    $upgradeDb->exec(
        'DROP TRIGGER recipe_ontology_source_rows_update'
    );
    $sourceRevisionBeforeTriggerRepair = (int)$upgradeDb->query("
        SELECT ontology_source_revision
        FROM recipe_score_state
        WHERE id = 1
    ")->fetchColumn();
    recipeSchemaMigrate($upgradeDb);
    $sourceRevisionAfterTriggerRepair = (int)$upgradeDb->query("
        SELECT ontology_source_revision
        FROM recipe_score_state
        WHERE id = 1
    ")->fetchColumn();
    recipeTestAssert(
        $sourceRevisionAfterTriggerRepair
            === $sourceRevisionBeforeTriggerRepair
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*)
             FROM sqlite_master
             WHERE type = 'trigger'
               AND name = 'recipe_ontology_source_rows_update'"
        ) === 1
        && (int)$upgradeDb->query("
            SELECT ontology_source_trigger_version
            FROM recipe_score_state
            WHERE id = 1
        ")->fetchColumn()
            === RECIPE_ONTOLOGY_SOURCE_TRIGGER_VERSION
        && strlen((string)$upgradeDb->query("
            SELECT ontology_source_trigger_hash
            FROM recipe_score_state
            WHERE id = 1
        ")->fetchColumn()) === 64,
        'Schema checks must atomically repair any drifted source trigger and '
            . 'preserve unchanged semantic source fences'
    );
    recipeSchemaMigrate($upgradeDb);
    recipeTestAssert(
        (int)$upgradeDb->query("
            SELECT ontology_source_revision
            FROM recipe_score_state
            WHERE id = 1
        ")->fetchColumn() === $sourceRevisionAfterTriggerRepair,
        'A repaired source trigger set must remain idempotent'
    );
    $upgradeDb->exec("
        CREATE TRIGGER reject_ontology_source_trigger_state
        BEFORE UPDATE OF ontology_source_trigger_version
        ON recipe_score_state
        BEGIN
            SELECT RAISE(
                ABORT,
                'forced ontology source trigger repair failure'
            );
        END;
        DROP TRIGGER recipe_ontology_source_products_update;
    ");
    $failedTriggerRepairRejected = false;
    try {
        recipeSchemaMigrate($upgradeDb);
    } catch (PDOException $e) {
        $failedTriggerRepairRejected = str_contains(
            $e->getMessage(),
            'forced ontology source trigger repair failure'
        );
    }
    $upgradeDb->exec(
        'DROP TRIGGER reject_ontology_source_trigger_state'
    );
    recipeSchemaMigrate($upgradeDb);
    recipeTestAssert(
        $failedTriggerRepairRejected
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*)
             FROM sqlite_master
             WHERE type = 'trigger'
               AND name = 'recipe_ontology_source_products_update'"
        ) === 1,
        'Failed source-trigger repair must roll back its SQL-started '
            . 'transaction and release the write lock'
    );
    recipeTestAssert(
        in_array(
            'scoring_config_hash',
            array_column(
                $upgradeDb->query(
                    "PRAGMA table_info(recipe_score_revisions)"
                )->fetchAll(PDO::FETCH_ASSOC),
                'name'
            ),
            true
        ),
        'Prior score schemas must add the bounded scoring configuration hash'
    );
    $upgradeGroceryColumns = array_column(
        $upgradeDb->query("PRAGMA table_info(recipe_grocery_requests)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    recipeTestAssert(
        in_array('request_fingerprint', $upgradeGroceryColumns, true)
        && recipeTestCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM recipe_grocery_requests
             WHERE idempotency_key = 'legacy-upgrade-key'
               AND request_fingerprint IS NULL"
        ) === 1,
        'Existing grocery idempotency rows must survive later schema migrations'
    );
    $upgradeOriginColumns = array_column(
        $upgradeDb->query("PRAGMA table_info(recipe_origins)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    recipeTestAssert(
        in_array('content_language', $upgradeOriginColumns, true),
        'Current installations must add recipe origin language independently of unrelated grocery migrations'
    );
    recipeTestAssert(
        str_contains(
            strtolower((string)$upgradeDb->query("
                SELECT sql
                FROM sqlite_master
                WHERE type = 'index'
                  AND name = 'idx_recipe_grocery_requests_created'
            ")->fetchColumn()),
            'created_at'
        ),
        'Grocery idempotency retention must have a created-at index'
    );
    $upgradeDb = null;

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');

    initializeDB($db);
    migrateDB($db);
    $db->exec("
        DROP TABLE ontology_generation_intents;
        DROP TABLE ontology_quarantine_retries;
        DROP TABLE ontology_provisional_queue;
        DROP TABLE ontology_version_fork_id_map;
        DROP TABLE ontology_version_fork_progress;
        DROP TABLE ontology_generation_constraint_heads;
        DROP TABLE ontology_artifact_supersessions;
        DROP TABLE ontology_gold_cases;
        DROP TABLE ontology_gold_adversarial_candidates;
        DROP TABLE ontology_gold_releases;
        DROP TABLE ontology_generation_plans;
        DROP TABLE ontology_generations;
        DROP TABLE ontology_mutation_plans;
        DROP TABLE ontology_controller_benchmark_policies;
        DROP TABLE ontology_controller_responses;
        DROP TABLE ontology_controller_prompts;
        DROP TABLE ontology_controller_jobs;
        DROP TABLE ingredient_ontology_pair_constraints;
        DROP TABLE ingredient_ontology_subject_resolutions;
        DROP TABLE ontology_constraint_ledger;
        DROP TABLE ontology_observation_events;
        DROP TABLE ontology_subject_occurrences;
        DROP TABLE ontology_subjects;
        DROP TABLE ontology_coverage_state;
        DROP TABLE ontology_backfill_state;
        DROP TABLE ontology_controller_state;
        DROP TABLE recipe_planner_command_events;
        DROP TABLE recipe_planner_commands;
        DROP TABLE recipe_ingredient_proposal_responses;
        DROP TABLE recipe_ingredient_proposal_prompts;
        DROP TABLE recipe_ingredient_proposal_outbox;
        DROP TABLE recipe_ingredient_feedback_regression_fixtures;
        DROP TABLE recipe_ingredient_feedback_events;
        DROP TABLE recipe_ingredient_user_overrides;
        DROP TABLE recipe_cookidoo_language_assessments;
        DROP TABLE recipe_quantity_parse_proposals;
        DROP TABLE ingredient_ontology_shadow_requirement_matches;
        DROP TABLE ingredient_ontology_recipe_identity_annex;
        DROP TABLE recipe_score_contributor_revisions;
        DROP TABLE recipe_score_match_contributors;
        DROP TABLE ingredient_ontology_shadow_matches;
        DROP TABLE ingredient_ontology_requirement_members;
        DROP TABLE ingredient_ontology_requirement_recipe_states;
        DROP TABLE ingredient_ontology_recipe_requirements;
        DROP TABLE ingredient_ontology_requirement_revisions;
        DROP TABLE ingredient_ontology_review_import_rows;
        DROP TABLE ingredient_ontology_review_imports;
        DROP TABLE ingredient_ontology_mapping_assertion_history;
        DROP TABLE ingredient_ontology_change_events;
        DROP TABLE ingredient_ontology_proposals;
        DROP TABLE ingredient_ontology_change_sets;
        DROP TABLE ingredient_ontology_provider_observations;
        DROP TABLE ingredient_ontology_curated_provider_conflict_reviews;
        DROP TABLE ingredient_ontology_curated_provider_reviews;
        DROP TABLE ingredient_ontology_mapping_relations;
        DROP TABLE ingredient_ontology_mapping_attributes;
        DROP TABLE ingredient_ontology_identity_annex;
        DROP TABLE ingredient_ontology_mappings;
        DROP TABLE ingredient_ontology_curated_product_assertions;
        DROP TABLE ingredient_ontology_provider_terms;
        DROP TABLE ingredient_ontology_terminal_dispositions;
        DROP TABLE ingredient_ontology_disposition_scopes;
        DROP TABLE ingredient_ontology_primary_edge_reviews;
        DROP TABLE ingredient_ontology_recipe_cohorts;
        DROP TABLE ingredient_ontology_label_context_policies;
        DROP TABLE ingredient_ontology_label_attributes;
        DROP TABLE ingredient_ontology_entity_defaults;
        DROP TABLE ingredient_ontology_entity_facet_policies;
        DROP TABLE ingredient_ontology_facet_values;
        DROP TABLE ingredient_ontology_facets;
        DROP TABLE ingredient_ontology_evidence_sources;
        DROP TABLE ingredient_ontology_resolution_manifests;
        DROP TABLE ingredient_ontology_relations;
        DROP TABLE ingredient_ontology_labels;
        DROP TABLE ingredient_ontology_entities;
        DROP TABLE ingredient_ontology_versions;
        DROP TABLE recipe_catalog_fts;
        DROP TABLE recipe_search_documents;
        DROP TABLE recipe_clusters;
        DROP TABLE recipe_user_state;
        DROP TABLE recipe_score_effective_sources;
        DROP TABLE recipe_score_recipe_ingredients;
        DROP TABLE recipe_score_recipe_operations;
        DROP TABLE recipe_score_incremental_recipes;
        DROP TABLE recipe_inventory_scores;
        DROP TABLE recipe_score_revisions;
        DROP TABLE recipe_score_mutations;
        DROP TABLE recipe_score_pending_recipes;
        DROP TABLE recipe_score_pending_products;
        DROP TABLE recipe_score_state;
        DROP TABLE recipe_grocery_requests;
        DROP TABLE recipe_source_ingredients;
        DROP TABLE recipe_ingredients;
        DROP TABLE recipe_origins;
        DROP TABLE recipe_jobs;
        DROP TABLE recipe_connector_state;
        DROP TABLE recipe_catalog;
    ");
    migrateDB($db);
    recipeSchemaMigrate($db);
    recipeSchemaMigrate($db);

    $requiredTables = [
        'recipe_catalog', 'recipe_origins', 'recipe_ingredients',
        'recipe_source_ingredients', 'recipe_grocery_requests', 'recipe_user_state',
        'recipe_clusters', 'recipe_jobs', 'recipe_connector_state',
        'recipe_score_state', 'recipe_score_revisions', 'recipe_inventory_scores',
        'recipe_score_effective_sources',
        'recipe_score_recipe_ingredients',
        'recipe_score_recipe_operations',
        'recipe_score_match_contributors',
        'recipe_score_contributor_revisions',
        'recipe_score_pending_recipes',
        'recipe_score_mutations',
        'ingredient_ontology_recipe_identity_annex',
        'recipe_score_pending_products',
        'recipe_score_incremental_recipes',
        'recipe_search_documents', 'recipe_catalog_fts',
        'ingredient_ontology_versions', 'ingredient_ontology_entities',
        'ingredient_ontology_identity_annex',
        'ingredient_ontology_labels', 'ingredient_ontology_relations',
        'ingredient_ontology_facets', 'ingredient_ontology_facet_values',
        'ingredient_ontology_entity_defaults',
        'ingredient_ontology_label_attributes',
        'ingredient_ontology_mappings',
        'ingredient_ontology_mapping_attributes',
        'ingredient_ontology_mapping_relations',
        'ingredient_ontology_change_sets', 'ingredient_ontology_proposals',
        'ingredient_ontology_change_events',
        'ingredient_ontology_shadow_matches',
        'ontology_controller_state', 'ontology_subjects',
        'ontology_subject_occurrences', 'ontology_observation_events',
        'ontology_constraint_ledger', 'ontology_controller_jobs',
        'ontology_controller_prompts', 'ontology_controller_responses',
        'ontology_mutation_plans', 'ontology_generations',
        'ontology_controller_benchmark_policies',
        'ontology_generation_plans',
        'ingredient_ontology_subject_resolutions',
        'ingredient_ontology_pair_constraints',
        'ontology_gold_releases', 'ontology_gold_cases',
        'ontology_gold_adversarial_candidates',
        'ingredient_ontology_provider_terms',
        'ingredient_ontology_provider_observations',
        'ingredient_ontology_curated_product_assertions',
        'ingredient_ontology_curated_provider_reviews',
        'ingredient_ontology_curated_provider_conflict_reviews',
        'ingredient_ontology_requirement_revisions',
        'ingredient_ontology_requirement_recipe_states',
        'ingredient_ontology_recipe_requirements',
        'ingredient_ontology_requirement_members',
        'ingredient_ontology_shadow_requirement_matches',
    ];
    foreach ($requiredTables as $table) {
        recipeTestAssert(
            recipeTestCount(
                $db,
                "SELECT COUNT(*) FROM sqlite_master WHERE name = ?",
                [$table]
            ) === 1,
            'Missing migrated table: ' . $table
        );
    }
    $jobColumns = $db->query("PRAGMA table_info(recipe_jobs)")->fetchAll(PDO::FETCH_ASSOC);
    $idempotencyColumn = array_values(array_filter(
        $jobColumns,
        static fn(array $column): bool => $column['name'] === 'idempotency_key'
    ))[0] ?? null;
    recipeTestAssert($idempotencyColumn !== null && (int)$idempotencyColumn['notnull'] === 1, 'Job key must be NOT NULL');
    recipeTestAssert(
        !array_diff(
            [
                'priority',
                'request_epoch',
                'request_generation',
                'request_hash',
                'lease_token',
                'lease_generation',
                'lease_expires_at',
            ],
            array_column($jobColumns, 'name')
        ),
        'Recipe jobs must support priority, immutable requests, and leases'
    );
    recipeTestAssert(
        str_contains(
            strtolower((string)$db->query("
                SELECT sql FROM sqlite_master
                WHERE type = 'index' AND name = 'idx_recipe_jobs_ready'
            ")->fetchColumn()),
            'priority'
        )
        && str_contains(
            strtolower((string)$db->query("
                SELECT sql FROM sqlite_master
                WHERE type = 'index' AND name = 'idx_recipe_jobs_ready'
            ")->fetchColumn()),
            'lease_expires_at'
        ),
        'Recipe job readiness index must cover priority and leases'
    );
    recipeTestAssert(
        in_array(
            'catalog_revision',
            $scoreStateColumnNames = array_column(
                $db->query("PRAGMA table_info(recipe_score_state)")
                    ->fetchAll(PDO::FETCH_ASSOC),
                'name'
            ),
            true
        )
        && in_array('cursor_revision', $scoreStateColumnNames, true)
        && in_array(
            'catalog_revision',
            $scoreRevisionColumnNames = array_column(
                $db->query("PRAGMA table_info(recipe_score_revisions)")->fetchAll(PDO::FETCH_ASSOC),
                'name'
            ),
            true
        )
        && in_array('scoring_config_hash', $scoreRevisionColumnNames, true),
        'Recipe score revisions must track immutable catalog revisions'
    );
    $transactionColumns = array_column(
        $db->query("PRAGMA table_info(transactions)")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    foreach ([
        'inventory_id', 'prepared_food', 'inventory_expiry_date',
        'inventory_expiry_user_set', 'inventory_vacuum_sealed',
        'inventory_opened_at', 'accounting_only', 'undo_safe',
    ] as $column) {
        recipeTestAssert(
            in_array($column, $transactionColumns, true),
            'Missing transaction batch snapshot column: ' . $column
        );
    }
    $catalogColumns = array_column(
        $db->query("PRAGMA table_info(recipe_catalog)")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    recipeTestAssert(in_array('stale_at', $catalogColumns, true), 'Catalog stale_at migration is required');
    foreach ([
        'yield_quantity', 'yield_unit', 'prep_time_seconds',
        'cook_time_seconds', 'active_time_seconds',
        'inactive_time_seconds', 'total_time_seconds', 'difficulty',
        'primary_category', 'devices_json', 'optional_devices_json',
        'equipment_json',
    ] as $column) {
        recipeTestAssert(
            in_array($column, $catalogColumns, true),
            'Missing metadata-v2 catalog column: ' . $column
        );
    }
    recipeTestAssert(
        in_array(
            'metadata_version',
            array_column(
                $db->query("PRAGMA table_info(recipe_origins)")
                    ->fetchAll(PDO::FETCH_ASSOC),
                'name'
            ),
            true
        ),
        'Recipe origins must store per-origin metadata versions'
    );
    recipeTestAssert(
        in_array(
            'canonical_key',
            array_column(
                $db->query("PRAGMA table_info(shopping_list)")->fetchAll(PDO::FETCH_ASSOC),
                'name'
            ),
            true
        ),
        'Internal shopping rows must support canonical recipe deduplication'
    );
    recipeTestAssert(recipeConnectorExists('cookidoo'), 'Cookidoo connector must be registered');
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_connector_state WHERE connector = 'cookidoo'"
        ) === 1,
        'Cookidoo connector state must be seeded'
    );
    $cookidooRegistry = recipeConnectorRegistry()['cookidoo'];
    recipeTestAssert(
        RECIPE_COOKIDOO_DETAIL_POLICY_VERSION
            === 'metadata-v3-operator-enabled'
        && $cookidooRegistry['detail_hydration'] === true
        && $cookidooRegistry['detail_hydration_reason'] === null
        && $cookidooRegistry['policy_version']
            === RECIPE_COOKIDOO_DETAIL_POLICY_VERSION
        && in_array(
            'direct_metadata_refresh',
            $cookidooRegistry['capabilities'],
            true
        ),
        'Synthetic Cookidoo tests must expose enabled hydration'
    );
    $policyJobCountBefore = recipeTestCount(
        $db,
        "SELECT COUNT(*) FROM recipe_jobs"
    );
    $policyConnectorStateBefore = recipeConnectorStateRow(
        $db,
        RECIPE_COOKIDOO_CONNECTOR
    );
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_DETAIL_HYDRATION_ENABLED'
    ] = 'false';
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_METADATA_BACKFILL_ENABLED'
    ] = 'true';
    $policyStatus = recipeCookidooMetadataBackfillStatus($db, 'en-GB');
    $policyPlan = recipeCookidooMetadataBackfillPlan(
        $db,
        'en-GB',
        20,
        200
    );
    $policyDiscovery = recipeCookidooDiscover($db, [
        'query' => 'policy disabled discovery',
        'locale' => 'en-GB',
        'include_local_results' => false,
    ]);
    $policyCrawlDryRun = recipeCookidooSeedTaxonomyCrawls($db, [
        'dry_run' => true,
    ]);
    $policyCrawlWrite = recipeCookidooSeedTaxonomyCrawls($db, [
        'dry_run' => false,
    ]);
    $policyEnqueueRejected = false;
    try {
        recipeCookidooEnqueueMetadataBackfill($db, 'en-GB', 20, 200);
    } catch (RuntimeException $e) {
        $policyEnqueueRejected =
            $e->getMessage() === RECIPE_COOKIDOO_DETAIL_POLICY_REASON;
    }
    $policyMetadataDispatch = recipeJobDispatchMetadataRefresh(
        $db,
        ['connector' => 'cookidoo'],
        []
    );
    $policyDiscoveryDispatch = recipeJobDispatchConnectorDiscovery(
        $db,
        ['connector' => 'cookidoo'],
        []
    );
    recipeTestAssert(
        $policyStatus['enabled'] === false
        && $policyStatus['configured_enabled'] === true
        && $policyStatus['refreshable'] === false
        && $policyStatus['unrefreshable_reason']
            === RECIPE_COOKIDOO_DETAIL_POLICY_REASON
        && $policyPlan['refreshable'] === false
        && $policyPlan['unrefreshable_reason']
            === RECIPE_COOKIDOO_DETAIL_POLICY_REASON
        && $policyPlan['batch_count'] === 0
        && $policyDiscovery['job'] === null
        && $policyDiscovery['unrefreshable_reason']
            === RECIPE_COOKIDOO_DETAIL_POLICY_REASON
        && $policyCrawlDryRun['dry_run'] === true
        && $policyCrawlDryRun['queued'] === 0
        && $policyCrawlDryRun['reason']
            === RECIPE_COOKIDOO_DETAIL_POLICY_REASON
        && $policyCrawlWrite['dry_run'] === false
        && $policyCrawlWrite['queued'] === 0
        && $policyCrawlWrite['reason']
            === RECIPE_COOKIDOO_DETAIL_POLICY_REASON
        && $policyEnqueueRejected
        && $policyMetadataDispatch['status'] === 'skipped'
        && $policyMetadataDispatch['result']['reason']
            === RECIPE_COOKIDOO_DETAIL_POLICY_REASON
        && $policyDiscoveryDispatch['status'] === 'skipped'
        && $policyDiscoveryDispatch['result']['reason']
            === RECIPE_COOKIDOO_DETAIL_POLICY_REASON
        && recipeTestCount($db, "SELECT COUNT(*) FROM recipe_jobs")
            === $policyJobCountBefore
        && recipeConnectorStateRow(
            $db,
            RECIPE_COOKIDOO_CONNECTOR
        ) === $policyConnectorStateBefore,
        'Policy-disabled hydration must stay local, terminal, and side-effect free'
    );
    $policyTransportCalls = 0;
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] = static function (
        string $url,
        string $token,
        array $payload,
        int $timeout
    ) use (&$policyTransportCalls): array {
        $policyTransportCalls++;
        return ['status' => 500, 'body' => '{"error":"must_not_call"}'];
    };
    $policyMetadataJob = recipeJobEnqueue(
        $db,
        'recipe_metadata_refresh',
        ['scope' => 'policy-disabled-metadata', 'connector' => 'cookidoo'],
        [],
        'policy-disabled-metadata-job',
        3,
        10000
    );
    $policyDiscoveryJob = recipeJobEnqueue(
        $db,
        'connector_discovery',
        ['scope' => 'policy-disabled-discovery', 'connector' => 'cookidoo'],
        [],
        'policy-disabled-discovery-job',
        3,
        10000
    );
    $policyQueue = recipeJobProcessQueueBatch($db, 2, 3, true);
    recipeTestAssert(
        $policyQueue['processed'] === 0
        && $policyQueue['skipped'] === 0
        && $policyTransportCalls === 0
        && recipeJobGet($db, (int)$policyMetadataJob['id'])['status']
            === 'pending'
        && recipeJobGet($db, (int)$policyDiscoveryJob['id'])['status']
            === 'pending'
        && recipeConnectorStateRow(
            $db,
            RECIPE_COOKIDOO_CONNECTOR
        ) === $policyConnectorStateBefore,
        'Disabled hydration must preserve queued jobs without provider or connector accounting'
    );
    $db->prepare("DELETE FROM recipe_jobs WHERE id IN (?, ?)")->execute([
        (int)$policyMetadataJob['id'],
        (int)$policyDiscoveryJob['id'],
    ]);
    unset($GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT']);
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_DETAIL_HYDRATION_ENABLED'
    ] = 'true';
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_METADATA_BACKFILL_ENABLED'
    ] = 'false';
    $db->exec("
        UPDATE recipe_connector_state
        SET policy_version = 'metadata-v1'
        WHERE connector = 'cookidoo'
    ");
    recipeSchemaMigrate($db);
    recipeTestAssert(
        $db->query("
            SELECT policy_version
            FROM recipe_connector_state
            WHERE connector = 'cookidoo'
        ")->fetchColumn() === RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
        'Cookidoo connector execution policy must migrate to v3'
    );
    $ruleResolution = recipeIngredientResolve($db, 'Chicken breast');
    recipeTestAssert(
        $ruleResolution['taxonomy_node_id'] !== null
        && $ruleResolution['source'] === 'taxonomy_rule',
        'Ingredient resolver must use existing taxonomy rules on a fresh database'
    );

    $db->exec("
        INSERT OR IGNORE INTO taxonomy_trees (slug, name) VALUES ('food', 'Food');
        INSERT INTO taxonomy_nodes (tree_id, slug, name) VALUES
            ((SELECT id FROM taxonomy_trees WHERE slug='food'), 'plant-test', 'Plant Test'),
            ((SELECT id FROM taxonomy_trees WHERE slug='food'), 'vegetable-test', 'Vegetable Test'),
            ((SELECT id FROM taxonomy_trees WHERE slug='food'), 'tomato-test', 'Tomato Test'),
            ((SELECT id FROM taxonomy_trees WHERE slug='food'), 'cherry-tomato-test', 'Cherry Tomato Test'),
            ((SELECT id FROM taxonomy_trees WHERE slug='food'), 'basil-test', 'Basil Test'),
            ((SELECT id FROM taxonomy_trees WHERE slug='food'), 'missing-spice-test', 'Missing Spice Test');
    ");
    $treeId = (int)$db->query("SELECT id FROM taxonomy_trees WHERE slug='food'")->fetchColumn();
    $nodeIds = [];
    foreach ($db->query("SELECT slug, id FROM taxonomy_nodes WHERE tree_id = {$treeId}") as $row) {
        $nodeIds[$row['slug']] = (int)$row['id'];
    }
    $closure = $db->prepare("
        INSERT OR IGNORE INTO taxonomy_closure (tree_id, ancestor_node_id, descendant_node_id, depth)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($nodeIds as $nodeId) {
        $closure->execute([$treeId, $nodeId, $nodeId, 0]);
    }
    $closure->execute([$treeId, $nodeIds['vegetable-test'], $nodeIds['tomato-test'], 1]);
    $closure->execute([$treeId, $nodeIds['tomato-test'], $nodeIds['cherry-tomato-test'], 1]);
    $closure->execute([$treeId, $nodeIds['vegetable-test'], $nodeIds['cherry-tomato-test'], 2]);
    $closure->execute([$treeId, $nodeIds['plant-test'], $nodeIds['vegetable-test'], 1]);
    $closure->execute([$treeId, $nodeIds['plant-test'], $nodeIds['tomato-test'], 2]);
    $closure->execute([$treeId, $nodeIds['plant-test'], $nodeIds['cherry-tomato-test'], 3]);

    $exact = recipeTaxonomyRelationScore($db, $nodeIds['tomato-test'], $nodeIds['tomato-test']);
    $descendant = recipeTaxonomyRelationScore($db, $nodeIds['vegetable-test'], $nodeIds['tomato-test']);
    $ancestor = recipeTaxonomyRelationScore($db, $nodeIds['tomato-test'], $nodeIds['vegetable-test']);
    recipeTestAssert(
        $exact['score'] > $descendant['score'] && $descendant['score'] > $ancestor['score'],
        'Taxonomy direction ordering must be exact > descendant > ancestor'
    );
    recipeTestAssert(
        recipePantryRoleWeight('primary') > recipePantryRoleWeight('broader')
        && recipePantryRoleWeight('broader') > recipePantryRoleWeight('inferred')
        && recipePantryRoleWeight('inferred') > recipePantryRoleWeight('contains'),
        'Pantry role ordering must be primary > broader > inferred > contains'
    );

    $canonicalInsert = $db->prepare("
        INSERT INTO canonical_ingredients (slug, name) VALUES (?, ?)
    ");
    $canonicalInsert->execute(['tomato-test', 'Tomato Test']);
    $tomatoCanonical = (int)$db->lastInsertId();
    $canonicalInsert->execute(['basil-test', 'Basil Test']);
    $basilCanonical = (int)$db->lastInsertId();
    $canonicalInsert->execute(['missing-spice-test', 'Missing Spice Test']);
    $missingSpiceCanonical = (int)$db->lastInsertId();

    $productInsert = $db->prepare("
        INSERT INTO products (name, unit, prepared_food) VALUES (?, 'pz', ?)
    ");
    $productInsert->execute(['Tomato Test', 0]);
    $tomatoProduct = (int)$db->lastInsertId();
    $productInsert->execute(['Prepared Tomato Test', 1]);
    $preparedProduct = (int)$db->lastInsertId();
    $productInsert->execute(['Expired Basil Test', 0]);
    $expiredProduct = (int)$db->lastInsertId();
    $productInsert->execute(['Vacuum Basil Test', 0]);
    $vacuumProduct = (int)$db->lastInsertId();
    $productInsert->execute(['Latte fresco', 0]);
    $openedProduct = (int)$db->lastInsertId();
    $productInsert->execute(['Mixed Test', 0]);
    $mixedProduct = (int)$db->lastInsertId();

    $mappingInsert = $db->prepare("
        INSERT INTO product_ingredients (product_id, ingredient_id, role, confidence, source)
        VALUES (?, ?, 'primary', 1, 'test')
    ");
    $mappingInsert->execute([$tomatoProduct, $tomatoCanonical]);
    $mappingInsert->execute([$preparedProduct, $tomatoCanonical]);
    $mappingInsert->execute([$expiredProduct, $basilCanonical]);
    $mappingInsert->execute([$vacuumProduct, $basilCanonical]);

    $inventoryInsert = $db->prepare("
        INSERT INTO inventory (
            product_id, location, quantity, expiry_date, prepared_food, vacuum_sealed
        )
        VALUES (?, 'frigo', 1, ?, ?, 0)
    ");
    $businessToday = new DateTimeImmutable(
        recipeScoreCurrentDate(),
        recipeScoreTimezone()
    );
    $businessDateOffset = static function (
        int $days
    ) use ($businessToday): string {
        return $businessToday
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->format('Y-m-d');
    };
    $inventoryInsert->execute([
        $tomatoProduct,
        $businessDateOffset(1),
        0,
    ]);
    $rawInventoryId = (int)$db->lastInsertId();
    $inventoryInsert->execute([
        $preparedProduct,
        $businessDateOffset(2),
        1,
    ]);
    $preparedInventoryId = (int)$db->lastInsertId();
    $inventoryInsert->execute([
        $expiredProduct,
        $businessDateOffset(-1),
        0,
    ]);
    $expiredInventoryId = (int)$db->lastInsertId();
    $db->prepare("UPDATE inventory SET expiry_user_set = 1 WHERE id = ?")
       ->execute([$expiredInventoryId]);
    $db->prepare("
        INSERT INTO inventory (
            product_id, location, quantity, expiry_date, prepared_food, vacuum_sealed
        )
        VALUES (?, 'frigo', 1, ?, 0, 1)
    ")->execute([$vacuumProduct, $businessDateOffset(-1)]);
    $vacuumInventoryId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO inventory (
            product_id, location, quantity, expiry_date, prepared_food, vacuum_sealed, opened_at
        )
        VALUES (?, 'frigo', 1, ?, 0, 0, ?)
    ")->execute([
        $openedProduct,
        $businessDateOffset(100),
        date('Y-m-d H:i:s', strtotime('-10 days')),
    ]);
    $openedInventoryId = (int)$db->lastInsertId();
    $inventoryInsert->execute([
        $mixedProduct,
        $businessDateOffset(4),
        0,
    ]);
    $mixedRawId = (int)$db->lastInsertId();
    $inventoryInsert->execute([
        $mixedProduct,
        $businessDateOffset(5),
        1,
    ]);
    $productInsert->execute(['Business Date Boundary', 0]);
    $businessDateProduct = (int)$db->lastInsertId();
    $inventoryInsert->execute([
        $businessDateProduct,
        '2026-08-17',
        0,
    ]);
    $businessDateInventoryId = (int)$db->lastInsertId();
    $productInsert->execute(['Business Date Future', 0]);
    $businessDateFutureProduct = (int)$db->lastInsertId();
    $inventoryInsert->execute([
        $businessDateFutureProduct,
        '2026-08-18',
        0,
    ]);
    $businessDateNextId = (int)$db->lastInsertId();
    $inventoryInsert->execute([
        $businessDateFutureProduct,
        '2026-08-20',
        0,
    ]);
    $businessDateThreeDaysId = (int)$db->lastInsertId();
    $inventoryInsert->execute([
        $businessDateFutureProduct,
        '2026-08-16',
        0,
    ]);
    $businessDatePriorId = (int)$db->lastInsertId();

    $candidateIds = array_column(recipeInventoryCandidates($db), 'inventory_id');
    recipeTestAssert(in_array($rawInventoryId, $candidateIds, true), 'Raw stock must be eligible');
    recipeTestAssert(!in_array($preparedInventoryId, $candidateIds, true), 'Prepared stock must be excluded');
    recipeTestAssert(!in_array($expiredInventoryId, $candidateIds, true), 'Expired stock must be excluded');
    recipeTestAssert(in_array($vacuumInventoryId, $candidateIds, true), 'Vacuum extension must affect effective expiry');
    recipeTestAssert(!in_array($openedInventoryId, $candidateIds, true), 'Opened effective expiry must exclude stale stock');
    $boundaryCandidates = recipeInventoryCandidates($db, [
        'score_date' => '2026-08-17',
        'score_timezone' => 'America/Los_Angeles',
    ]);
    $boundaryCandidate = array_values(array_filter(
        $boundaryCandidates,
        static fn(array $candidate): bool =>
            (int)$candidate['inventory_id']
                === $businessDateInventoryId
    ));
    recipeTestAssert(
        count($boundaryCandidate) === 1
        && (int)$boundaryCandidate[0]['days_remaining'] === 0
        && !in_array(
            $businessDateInventoryId,
            array_column(
                recipeInventoryCandidates($db, [
                    'score_date' => '2026-08-18',
                    'score_timezone' => 'America/Los_Angeles',
                ]),
                'inventory_id'
            ),
            true
        ),
        'Inventory expiry eligibility and days remaining must use the supplied score date'
    );
    $boundaryById = [];
    foreach (recipeInventoryCandidates($db, [
        'exclude_expired' => false,
        'score_date' => '2026-08-17',
        'score_timezone' => 'America/Los_Angeles',
    ]) as $candidate) {
        $boundaryById[(int)$candidate['inventory_id']] = $candidate;
    }
    recipeTestAssert(
        (int)$boundaryById[$businessDateNextId]['days_remaining'] === 1
        && (int)$boundaryById[$businessDateThreeDaysId][
            'days_remaining'
        ] === 3
        && (int)$boundaryById[$businessDatePriorId][
            'days_remaining'
        ] === -1,
        'Calendar-day expiry distance must use one explicit negative-offset timezone'
    );
    $positiveOffsetById = [];
    foreach (recipeInventoryCandidates($db, [
        'exclude_expired' => false,
        'score_date' => '2026-08-17',
        'score_timezone' => 'Europe/Rome',
    ]) as $candidate) {
        $positiveOffsetById[(int)$candidate['inventory_id']] =
            $candidate;
    }
    recipeTestAssert(
        (int)$positiveOffsetById[$businessDatePriorId][
            'days_remaining'
        ] === -1,
        'Calendar-day expiry distance must remain exact in positive-offset timezones'
    );

    $autoInventoryDiscovery = recipeJobDispatchInventoryChanged(
        $db,
        ['product_id' => $tomatoProduct],
        ['reason' => 'inventory_add']
    );
    recipeTestAssert(
        $autoInventoryDiscovery['status'] === 'done'
        && ($autoInventoryDiscovery['result']['remote_discovery']['queued'] ?? 0) >= 1,
        'Inventory additions with existing taxonomy must enqueue remote discovery'
    );
    $autoLanes = array_values(array_unique(array_column(
        $autoInventoryDiscovery['result']['remote_discovery']['jobs'] ?? [],
        'lane'
    )));
    sort($autoLanes);
    recipeTestAssert(
        $autoLanes === ['ingredient', 'text'],
        'Automatic discovery must enqueue ingredient-filtered and text-only lanes'
    );
    foreach ($autoInventoryDiscovery['result']['remote_discovery']['jobs'] ?? [] as $job) {
        $autoJob = recipeJobGet($db, (int)$job['id']);
        recipeTestAssert(
            $autoJob !== null
            && $autoJob['payload']['crawl_all'] === true
            && $autoJob['payload']['exclude_cached'] === true
            && $autoJob['payload']['limit'] === 20
            && $autoJob['payload']['max_pages'] === 1
            && $autoJob['payload']['page'] === 0,
            'Automatic taxonomy discovery must enqueue one-page full-crawl roots'
        );
    }
    $autoTerms = recipeCookidooAutoDiscoveryTermsForProduct($db, $tomatoProduct);
    recipeTestAssert(
        max(array_column($autoTerms, 'depth')) >= 2,
        'Automatic discovery must traverse the full taxonomy ancestor chain'
    );
    recipeTestAssert(
        !recipeCookidooTaxonomyTermIsEligible('food', 'Food')
        && !recipeCookidooTaxonomyTermIsEligible('prepared-meal', 'Prepared meal'),
        'Structural roots and prepared-meal nodes must never become discovery terms'
    );
    $autoTaxonomyDiscovery = recipeJobDispatchTaxonomyReady(
        $db,
        ['product_id' => $tomatoProduct],
        ['reason' => 'canonical_queue_success']
    );
    recipeTestAssert(
        $autoTaxonomyDiscovery['status'] === 'done'
        && ($autoTaxonomyDiscovery['result']['remote_discovery']['skipped'] ?? 0) >= 1,
        'Taxonomy completion must reuse discovery cooldowns instead of duplicating jobs'
    );
    $preparedAutoDiscovery = recipeCookidooAutoDiscoverProduct(
        $db,
        $preparedProduct,
        'inventory_add'
    );
    recipeTestAssert(
        $preparedAutoDiscovery['queued'] === 0,
        'Prepared-food inventory must never enqueue recipe discovery'
    );
    $firstAutoJob = $autoInventoryDiscovery['result']['remote_discovery']['jobs'][0]['id'] ?? 0;
    recipeTestAssert($firstAutoJob > 0, 'Automatic discovery must expose queued job IDs');
    $db->prepare("
        UPDATE recipe_jobs
        SET status = 'done', updated_at = datetime('now', '-30 days')
        WHERE id = ?
    ")->execute([$firstAutoJob]);
    $periodicRefresh = recipeCookidooEnqueuePeriodicRefreshes($db, 1);
    $periodicRefreshJobId = (int)($periodicRefresh['jobs'][0] ?? 0);
    recipeTestAssert(
        $periodicRefresh['queued'] === 1
        && $periodicRefresh['crawl_refresh_strategy']
            === 'page_zero_only'
        && recipeJobGet($db, $firstAutoJob)['status'] === 'done'
        && recipeJobGet($db, $periodicRefreshJobId)['status']
            === 'pending',
        'Periodic refresh must enqueue bounded page-zero work without reopening the historical crawl'
    );
    foreach ($autoInventoryDiscovery['result']['remote_discovery']['jobs'] ?? [] as $job) {
        $db->prepare("DELETE FROM recipe_jobs WHERE id = ?")->execute([(int)$job['id']]);
    }
    $db->prepare("DELETE FROM recipe_jobs WHERE id = ?")
        ->execute([$periodicRefreshJobId]);
    $backgroundJob = recipeJobEnqueueOnce(
        $db,
        'catalog_rebuild_search',
        ['scope' => 'priority-background', 'connector' => 'local'],
        [],
        'test-priority-background',
        3,
        0
    )['job'];
    $interactiveJob = recipeJobEnqueueOnce(
        $db,
        'rerank_discovery',
        ['scope' => 'priority-interactive', 'connector' => 'local'],
        [],
        'test-priority-interactive',
        3,
        100
    )['job'];
    $priorityBatch = recipeJobProcessQueueBatch($db, 2, 3, false);
    recipeTestAssert(
        array_column($priorityBatch['items'], 'id') === [
            $interactiveJob['id'],
            $backgroundJob['id'],
        ],
        'Queue processing must reserve one slot for interactive work and one for background work'
    );
    $db->prepare("DELETE FROM recipe_jobs WHERE id IN (?, ?)")
        ->execute([$backgroundJob['id'], $interactiveJob['id']]);
    $leaseExhaustedLocal = recipeJobEnqueueOnce(
        $db,
        'catalog_rebuild_search',
        ['scope' => 'lease-exhausted-local', 'connector' => 'local'],
        [],
        'test-lease-exhausted-local',
        5
    )['job'];
    $leaseExhaustedCookidoo = recipeJobEnqueueOnce(
        $db,
        'connector_discovery',
        ['scope' => 'lease-exhausted-cookidoo', 'connector' => 'cookidoo'],
        [],
        'test-lease-exhausted-cookidoo',
        2
    )['job'];
    $leaseRetryCookidoo = recipeJobEnqueueOnce(
        $db,
        'recipe_metadata_refresh',
        ['scope' => 'lease-retry-cookidoo', 'connector' => 'cookidoo'],
        [],
        'test-lease-retry-cookidoo',
        5
    )['job'];
    $policyPendingCookidoo = recipeJobEnqueueOnce(
        $db,
        'recipe_refresh',
        ['scope' => 'policy-pending-cookidoo', 'connector' => 'cookidoo'],
        ['recipe_id' => null],
        'test-policy-pending-cookidoo',
        5
    )['job'];
    $policyRetryCookidoo = recipeJobEnqueueOnce(
        $db,
        'connector_discovery',
        ['scope' => 'policy-retry-cookidoo', 'connector' => 'cookidoo'],
        [],
        'test-policy-retry-cookidoo',
        5
    )['job'];
    $db->prepare("
        UPDATE recipe_jobs
        SET status = 'retry',
            next_retry_at = datetime('now', '+1 day'),
            last_error = 'old retry error'
        WHERE id = ?
    ")->execute([$policyRetryCookidoo['id']]);
    $unrelatedLocalPending = recipeJobEnqueueOnce(
        $db,
        'catalog_rebuild_search',
        ['scope' => 'policy-unrelated-local', 'connector' => 'local'],
        [],
        'test-policy-unrelated-local',
        5
    )['job'];
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_DETAIL_HYDRATION_ENABLED'
    ] = 'false';
    $db->prepare("
        UPDATE recipe_connector_state
        SET last_error = 'preserve-policy-accounting',
            failure_count = 7
        WHERE connector = 'cookidoo'
    ")->execute();
    $db->prepare("
        UPDATE recipe_jobs
        SET status = 'in_progress',
            attempts = ?,
            started_at = datetime('now', '-30 minutes'),
            lease_token = 'expired-local',
            lease_generation = lease_generation + 1,
            lease_expires_at = datetime('now', '-1 minute'),
            last_error = 'old lease error',
            finished_at = NULL
        WHERE id = ?
    ")->execute([3, $leaseExhaustedLocal['id']]);
    $db->prepare("
        UPDATE recipe_jobs
        SET status = 'in_progress',
            attempts = ?,
            started_at = datetime('now', '-30 minutes'),
            lease_token = 'expired-cookidoo-exhausted',
            lease_generation = lease_generation + 1,
            lease_expires_at = datetime('now', '-1 minute'),
            last_error = 'old lease error',
            finished_at = NULL
        WHERE id = ?
    ")->execute([2, $leaseExhaustedCookidoo['id']]);
    $db->prepare("
        UPDATE recipe_jobs
        SET status = 'in_progress',
            attempts = ?,
            started_at = datetime('now', '-30 minutes'),
            lease_token = 'expired-cookidoo-retry',
            lease_generation = lease_generation + 1,
            lease_expires_at = datetime('now', '-1 minute'),
            last_error = 'old lease error',
            finished_at = NULL
        WHERE id = ?
    ")->execute([2, $leaseRetryCookidoo['id']]);
    recipeJobProcessQueueBatch($db, 0, 3, false);
    $leaseExhaustedLocal = recipeJobGet(
        $db,
        $leaseExhaustedLocal['id']
    );
    $leaseExhaustedCookidoo = recipeJobGet(
        $db,
        $leaseExhaustedCookidoo['id']
    );
    $leaseRetryCookidoo = recipeJobGet(
        $db,
        $leaseRetryCookidoo['id']
    );
    $policyPendingCookidoo = recipeJobGet(
        $db,
        $policyPendingCookidoo['id']
    );
    $policyRetryCookidoo = recipeJobGet(
        $db,
        $policyRetryCookidoo['id']
    );
    $unrelatedLocalPending = recipeJobGet(
        $db,
        $unrelatedLocalPending['id']
    );
    $policyConnectorState = recipeConnectorStateRow($db, 'cookidoo');
    recipeTestAssert(
        $leaseExhaustedLocal['status'] === 'failed'
            && $leaseExhaustedLocal['last_error'] === 'lease_exhausted'
            && $leaseExhaustedLocal['finished_at'] !== null
            && $leaseExhaustedCookidoo['status'] === 'failed'
            && $leaseExhaustedCookidoo['last_error']
                === 'lease_exhausted'
            && $leaseExhaustedCookidoo['finished_at'] !== null
            && $leaseExhaustedCookidoo['started_at'] === null
            && $leaseExhaustedCookidoo['next_retry_at'] === null
            && $leaseRetryCookidoo['status'] === 'retry'
            && $leaseRetryCookidoo['last_error']
                === 'processing lease expired'
            && $leaseRetryCookidoo['finished_at'] === null
            && $policyPendingCookidoo['status'] === 'pending'
            && $policyPendingCookidoo['finished_at'] === null
            && $policyRetryCookidoo['status'] === 'retry'
            && $policyRetryCookidoo['last_error']
                === 'old retry error'
            && $policyRetryCookidoo['next_retry_at'] !== null
            && $policyRetryCookidoo['finished_at'] === null
            && $unrelatedLocalPending['status'] === 'pending'
            && $policyConnectorState['last_error']
                === 'preserve-policy-accounting'
            && (int)$policyConnectorState['failure_count'] === 7,
        'Policy-disabled Cookidoo jobs must retain demand while expired leases '
            . 'recover independently without connector accounting: '
            . json_encode([
                'local' => $leaseExhaustedLocal,
                'cookidoo_exhausted' => $leaseExhaustedCookidoo,
                'cookidoo_retry' => $leaseRetryCookidoo,
                'cookidoo_pending' => $policyPendingCookidoo,
                'cookidoo_policy_retry' => $policyRetryCookidoo,
                'local_pending' => $unrelatedLocalPending,
                'connector' => $policyConnectorState,
            ])
    );
    $policyTrueWorkerCookidoo = recipeJobEnqueueOnce(
        $db,
        'connector_discovery',
        ['scope' => 'policy-true-worker-cookidoo', 'connector' => 'cookidoo'],
        [],
        'test-policy-true-worker-cookidoo',
        2
    )['job'];
    $db->prepare("
        UPDATE recipe_jobs
        SET status = 'in_progress',
            attempts = 2,
            started_at = datetime('now', '-30 minutes'),
            lease_token = 'expired-policy-worker',
            lease_generation = lease_generation + 1,
            lease_expires_at = datetime('now', '-1 minute'),
            last_error = 'old enabled-cadence lease'
        WHERE id = ?
    ")->execute([$policyTrueWorkerCookidoo['id']]);
    recipeJobProcessQueueBatch($db, 0, 3, true);
    $policyTrueWorkerCookidoo = recipeJobGet(
        $db,
        $policyTrueWorkerCookidoo['id']
    );
    recipeTestAssert(
        $policyTrueWorkerCookidoo['status'] === 'failed'
            && $policyTrueWorkerCookidoo['last_error']
                === 'lease_exhausted'
            && $policyTrueWorkerCookidoo['finished_at'] !== null
            && $policyTrueWorkerCookidoo['started_at'] === null,
        'Expired Cookidoo leases must recover without provider traffic'
    );
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_DETAIL_HYDRATION_ENABLED'
    ] = 'true';
    $cadenceGatedCookidoo = recipeJobEnqueueOnce(
        $db,
        'connector_discovery',
        ['scope' => 'cadence-gated-cookidoo', 'connector' => 'cookidoo'],
        [],
        'test-cadence-gated-cookidoo',
        2
    )['job'];
    recipeJobProcessQueueBatch($db, 0, 3, false);
    $cadenceGatedCookidoo = recipeJobGet(
        $db,
        $cadenceGatedCookidoo['id']
    );
    recipeTestAssert(
        $cadenceGatedCookidoo['status'] === 'pending',
        'Cadence gating must not terminalize Cookidoo jobs when policy is enabled'
    );
    $db->prepare("DELETE FROM recipe_jobs WHERE id IN (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $leaseExhaustedLocal['id'],
            $leaseExhaustedCookidoo['id'],
            $leaseRetryCookidoo['id'],
            $policyPendingCookidoo['id'],
            $policyRetryCookidoo['id'],
            $unrelatedLocalPending['id'],
            $policyTrueWorkerCookidoo['id'],
            $cadenceGatedCookidoo['id'],
        ]);
    $db->prepare("
        UPDATE recipe_connector_state
        SET last_error = '', failure_count = 0
        WHERE connector = 'cookidoo'
    ")->execute();

    $beforeBackfillJobs = recipeTestCount($db, "SELECT COUNT(*) FROM recipe_jobs");
    $backfillDryRun = recipeCookidooSeedTaxonomyCrawls($db, [
        'locale' => 'en-GB',
        'tmv' => 'TM6',
        'dry_run' => true,
    ]);
    recipeTestAssert(
        $backfillDryRun['dry_run'] === true
        && $backfillDryRun['eligible_products'] === 2
        && $backfillDryRun['terms'] === 4
        && $backfillDryRun['planned'] === 8
        && $backfillDryRun['would_queue'] === 8
        && recipeTestCount($db, "SELECT COUNT(*) FROM recipe_jobs") === $beforeBackfillJobs,
        'Cookidoo taxonomy backfill dry-run must cover only eligible stocked terms without writes'
    );
    $backfillSeed = recipeCookidooSeedTaxonomyCrawls($db, [
        'locale' => 'en-GB',
        'tmv' => 'TM6',
    ]);
    $backfillRepeat = recipeCookidooSeedTaxonomyCrawls($db, [
        'locale' => 'en-GB',
        'tmv' => 'TM6',
    ]);
    recipeTestAssert(
        $backfillSeed['queued'] === 8
        && $backfillRepeat['queued'] === 0
        && $backfillRepeat['skipped'] === 8,
        'Cookidoo taxonomy backfill must be repeatable and skip existing crawl roots'
    );
    foreach ($backfillSeed['jobs'] as $job) {
        if (!empty($job['id'])) {
            $db->prepare("DELETE FROM recipe_jobs WHERE id = ?")->execute([(int)$job['id']]);
        }
    }

    $db->prepare("UPDATE products SET prepared_food = 1 WHERE id = ?")->execute([$mixedProduct]);
    $db->exec("DELETE FROM app_settings WHERE key = 'migration_prepared_food_all_positive_v1'");
    migratePreparedFoodAggregateSemantics($db);
    recipeTestAssert(
        recipeTestCount($db, "SELECT prepared_food FROM products WHERE id = ?", [$mixedProduct]) === 0,
        'Existing mixed stock migration must clear product prepared state'
    );
    recipeTestAssert(
        recipeTestCount($db, "SELECT COUNT(*) FROM canonical_processing_queue WHERE product_id = ?", [$mixedProduct]) === 1,
        'Prepared aggregate migration must requeue taxonomy'
    );
    recipeTestAssert(_syncProductPreparedFood($db, $mixedProduct) === 0, 'Mixed stock must not mark product prepared');
    $db->prepare("UPDATE inventory SET quantity = 0 WHERE id = ?")->execute([$mixedRawId]);
    recipeTestAssert(_syncProductPreparedFood($db, $mixedProduct) === 1, 'All positive rows prepared must mark product prepared');
    $db->prepare("UPDATE inventory SET quantity = 0 WHERE product_id = ?")->execute([$mixedProduct]);
    $db->prepare("UPDATE products SET prepared_food = 1 WHERE id = ?")->execute([$mixedProduct]);
    recipeTestAssert(_syncProductPreparedFood($db, $mixedProduct) === 1, 'Explicit empty prepared product must stay prepared');

    $variantOne = recipeCatalogSaveVariant($db, [
        'title' => 'Roasted Tomato Soup',
        'language' => 'en',
        'servings' => 2,
        'tags' => ['soup'],
        'ingredients' => [['name' => 'Tomato Test', 'qty' => '1 pz']],
        'steps' => ['Roast the tomato.', 'Blend it.'],
    ], ['connector' => 'manual', 'external_id' => 'variant-one']);
    $variantTwo = recipeCatalogSaveVariant($db, [
        'title' => 'Roasted Tomato Soup',
        'language' => 'en',
        'servings' => 2,
        'ingredients' => [['name' => 'Tomato Test', 'qty' => '1 pz']],
        'steps' => ['Simmer the tomato.', 'Blend it.'],
    ], ['connector' => 'manual', 'external_id' => 'variant-two']);
    recipeTestAssert($variantOne['id'] !== $variantTwo['id'], 'Distinct source variants must be preserved');
    recipeTestAssert(
        $variantOne['cluster_key'] === $variantTwo['cluster_key'],
        'Heuristic clustering must group variants without merging them'
    );
    $variantOneUpdated = recipeCatalogSaveVariant($db, [
        'title' => 'Roasted Tomato Soup Updated',
        'ingredients' => [['name' => 'Tomato Test', 'qty' => '1 pz']],
        'steps' => ['Roast and blend.'],
    ], ['connector' => 'manual', 'external_id' => 'variant-one']);
    recipeTestAssert($variantOneUpdated['id'] === $variantOne['id'], 'Exact source identity must update in place');
    $variantOneExplicit = recipeCatalogSaveVariant($db, [
        'title' => 'Roasted Tomato Soup Explicit Update',
        'ingredients' => [['name' => 'Tomato Test', 'qty' => '1 pz']],
        'steps' => ['Roast, blend, and serve.'],
    ], [
        'recipe_id' => $variantOne['id'],
        'connector' => 'manual',
        'external_id' => 'variant-one',
    ]);
    recipeTestAssert($variantOneExplicit['id'] === $variantOne['id'], 'Explicit recipe updates must reuse the origin');
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_origins WHERE recipe_id = ? AND connector = 'manual'",
            [$variantOne['id']]
        ) === 1,
        'Explicit recipe updates must not duplicate connector origins'
    );
    $identityConflictA = recipeCatalogSaveVariant($db, [
        'title' => 'Identity Conflict A',
        'ingredients' => [['name' => 'Tomato Test']],
        'steps' => ['A'],
    ], [
        'connector' => 'manual',
        'external_id' => 'identity-conflict-a',
        'canonical_url' => 'https://example.invalid/identity/a',
    ]);
    $identityConflictB = recipeCatalogSaveVariant($db, [
        'title' => 'Identity Conflict B',
        'ingredients' => [['name' => 'Tomato Test']],
        'steps' => ['B'],
    ], [
        'connector' => 'manual',
        'external_id' => 'identity-conflict-b',
        'canonical_url' => 'https://example.invalid/identity/b',
    ]);
    $conflictingIdentifiersRejected = false;
    try {
        recipeCatalogSaveVariant($db, [
            'title' => 'Identity Conflict Overwrite',
            'ingredients' => [['name' => 'Tomato Test']],
            'steps' => ['Overwrite'],
        ], [
            'recipe_id' => (int)$identityConflictA['id'],
            'connector' => 'manual',
            'external_id' => 'identity-conflict-a',
            'canonical_url' => 'https://example.invalid/identity/b',
        ]);
    } catch (InvalidArgumentException $e) {
        $conflictingIdentifiersRejected = str_contains(
            $e->getMessage(),
            'different catalog rows'
        );
    }
    recipeTestAssert(
        $conflictingIdentifiersRejected
            && recipeCatalogGetById(
                $db,
                (int)$identityConflictA['id']
            )['title'] === 'Identity Conflict A'
            && recipeCatalogGetById(
                $db,
                (int)$identityConflictB['id']
            )['title'] === 'Identity Conflict B',
        'Conflicting recipe identifiers must fail before either row is mutated'
    );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    foreach ([true, 1.0, '1', '1x', 0, -1] as $invalidRecipeId) {
        $GLOBALS['RECIPE_API_JSON_INPUT'] = [
            'recipe_id' => $invalidRecipeId,
            'connector' => 'manual',
            'recipe' => [
                'title' => 'Invalid Strict Recipe ID',
                'ingredients' => [['name' => 'Tomato Test']],
                'steps' => ['No write'],
            ],
        ];
        http_response_code(200);
        ob_start();
        recipeCatalogApiSave($db);
        $invalidSaveId = json_decode((string)ob_get_clean(), true);
        $GLOBALS['RECIPE_API_JSON_INPUT'] = [
            'recipe_id' => $invalidRecipeId,
        ];
        http_response_code(200);
        ob_start();
        recipeCatalogApiDelete($db);
        $invalidDeleteId = json_decode((string)ob_get_clean(), true);
        $GLOBALS['RECIPE_API_JSON_INPUT'] = [
            'recipe_id' => $invalidRecipeId,
            'favorite' => true,
        ];
        http_response_code(200);
        ob_start();
        recipeCatalogApiFavorite($db);
        $invalidFavoriteId = json_decode((string)ob_get_clean(), true);
        recipeTestAssert(
            ($invalidSaveId['error'] ?? '') === 'invalid_recipe_id'
                && ($invalidDeleteId['error'] ?? '') === 'invalid_recipe_id'
                && ($invalidFavoriteId['error'] ?? '')
                    === 'invalid_recipe_id',
            'Save/delete/favorite APIs must reject non-integer recipe_id: '
                . get_debug_type($invalidRecipeId)
                . ':' . (string)$invalidRecipeId
        );
    }
    $GLOBALS['RECIPE_API_JSON_INPUT'] = [
        'connector' => 'manual',
        'content_language' => 'x',
        'recipe' => [
            'title' => 'Invalid Content Language',
            'ingredients' => [['name' => 'Tomato Test']],
            'steps' => ['No write'],
        ],
    ];
    http_response_code(200);
    ob_start();
    recipeCatalogApiSave($db);
    $invalidContentLanguage = json_decode(
        (string)ob_get_clean(),
        true
    );
    recipeTestAssert(
        http_response_code() === 400
        && ($invalidContentLanguage['error'] ?? '')
            === 'content_language is invalid',
        'Catalog save must reject invalid content language before SQLite constraints'
    );
    unset($GLOBALS['RECIPE_API_JSON_INPUT']);
    http_response_code(200);
    $urlVariant = recipeCatalogSaveVariant($db, [
        'title' => 'Canonical URL Recipe',
        'ingredients' => [['name' => 'Tomato Test']],
        'steps' => ['Serve.'],
    ], [
        'connector' => 'local',
        'external_id' => 'url-local',
        'canonical_url' => 'https://example.invalid/recipes/one',
    ]);
    $sameUrlVariant = recipeCatalogSaveVariant($db, [
        'title' => 'Canonical URL Recipe',
        'ingredients' => [['name' => 'Tomato Test']],
        'steps' => ['Serve warm.'],
    ], [
        'connector' => 'generated',
        'external_id' => 'url-generated',
        'canonical_url' => 'https://example.invalid/recipes/one',
    ]);
    recipeTestAssert(
        $urlVariant['id'] !== $sameUrlVariant['id'],
        'Canonical URLs from different connectors must preserve separate content rows'
    );
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_origins WHERE recipe_id = ?",
            [$urlVariant['id']]
        ) === 1
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_origins WHERE recipe_id = ?",
            [$sameUrlVariant['id']]
        ) === 1,
        'Cross-connector canonical URLs must retain isolated origins'
    );

    $basilRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Garden Bowl',
        'ingredients' => [['name' => 'Basil Test', 'qty' => '1 pz']],
        'steps' => ['Mix the basil.'],
    ], ['connector' => 'local', 'external_id' => 'garden-bowl']);
    $titleSearch = recipeCatalogTextSearch($db, 'roasted', null, 20, 0);
    $ingredientSearch = recipeCatalogTextSearch($db, 'basil', null, 20, 0);
    recipeTestAssert(
        in_array($variantOne['id'], array_map('intval', array_column($titleSearch['rows'], 'recipe_id')), true),
        'FTS must find recipe titles'
    );
    recipeTestAssert(
        in_array($basilRecipe['id'], array_map('intval', array_column($ingredientSearch['rows'], 'recipe_id')), true),
        'FTS must find ingredient text'
    );
    recipeTestAssert(
        str_contains(recipeCatalogBuildFtsQuery('marry me chicken'), ' AND ')
        && !str_contains(recipeCatalogBuildFtsQuery('marry me chicken'), ' OR '),
        'Multi-term recipe search must require every query term'
    );
    $exactTitleRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Marry Me Chicken',
        'ingredients' => [['name' => 'Tomato Test', 'qty' => '1 pz']],
        'steps' => ['Serve.'],
    ], ['connector' => 'manual', 'external_id' => 'exact-title-search']);
    $scoreBootstrap = recipeScoreRebuild($db, true);
    recipeTestAssert(
        !empty($scoreBootstrap['rebuilt']),
        'The maintenance score worker must bootstrap browse scores '
            . 'before query-only recipe reads'
    );
    $exactTitleSearch = recipeCatalogSearchResult($db, [
        'query' => 'Marry Me Chicken',
        'mode' => 'stocked',
        'limit' => 20,
        'offset' => 0,
    ]);
    recipeTestAssert(
        (int)$exactTitleSearch['results'][0]['recipe']['id'] === $exactTitleRecipe['id']
        && isset($exactTitleSearch['results'][0]['explain']['ingredient_matches']),
        'Exact recipe titles must rank ahead of broader text matches'
    );
    recipeSearchRebuildAll($db);
    recipeTestAssert(recipeCatalogTextSearch($db, 'basil', null, 20, 0)['total'] >= 1, 'FTS rebuild must remain searchable');

    $jobOne = recipeJobEnqueue($db, 'catalog_rebuild_search');
    $jobTwo = recipeJobEnqueue($db, 'catalog_rebuild_search');
    recipeTestAssert($jobOne['id'] === $jobTwo['id'], 'Nullable-scope jobs must be idempotent');
    recipeTestAssert($jobOne['idempotency_key'] !== '', 'Job idempotency key must be populated');
    $queueResult = recipeJobProcessQueueBatch($db, 20, 3);
    recipeTestAssert($queueResult['processed'] >= 1, 'Recipe worker must process a bounded batch');
    recipeTestAssert(
        recipeJobGet($db, $jobOne['id'])['status'] === 'done',
        'Local search rebuild job must finish explicitly'
    );
    $staleJob = recipeJobEnqueue(
        $db,
        'catalog_rebuild_search',
        ['scope' => 'stale-test'],
        [],
        'test:stale-job'
    );
    $db->prepare("
        UPDATE recipe_jobs
        SET status = 'in_progress',
            started_at = datetime('now', '-2 hours'),
            lease_token = 'expired-stale-job',
            lease_generation = lease_generation + 1,
            lease_expires_at = datetime('now', '-1 minute')
        WHERE id = ?
    ")->execute([$staleJob['id']]);
    recipeJobProcessQueueBatch($db, 1, 3);
    recipeTestAssert(
        recipeJobGet($db, $staleJob['id'])['status'] === 'done',
        'Expired processing leases must be reclaimed'
    );
    $deferredJob = recipeJobEnqueue(
        $db,
        'connector_discovery',
        ['connector' => 'local'],
        [],
        'test:connector-discovery'
    );
    recipeJobProcessQueueBatch($db, 5, 3);
    recipeTestAssert(
        recipeJobGet($db, $deferredJob['id'])['status'] === 'skipped',
        'Deferred connector work must have an explicit skipped state'
    );
    $failingJob = recipeJobEnqueue(
        $db,
        'unknown_test_job',
        [],
        [],
        'test:unknown-job',
        2
    );
    recipeJobProcessQueueBatch($db, 5, 2);
    recipeTestAssert(
        recipeJobGet($db, $failingJob['id'])['status'] === 'retry',
        'Failed recipe work must enter explicit retry state'
    );
    $db->prepare("UPDATE recipe_jobs SET next_retry_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([$failingJob['id']]);
    recipeJobProcessQueueBatch($db, 5, 2);
    $failedJob = recipeJobGet($db, $failingJob['id']);
    recipeTestAssert(
        $failedJob['status'] === 'failed' && $failedJob['last_error'] !== '',
        'Exhausted recipe work must retain an explicit failure'
    );

    $allowlisted = recipeCookidooNormalizeBridgeResponse([
        'recipes' => [recipeTestCookidooBridgeRecipe([
            'external_id' => 'r-cookidoo-allowlist',
            'title' => 'Allowlisted Metadata',
            'general' => [
                'yield_quantity' => 4,
                'yield_unit' => 'portions',
                'prep_time_seconds' => 300,
                'cook_time_seconds' => 900,
                'active_time_seconds' => 600,
                'inactive_time_seconds' => 300,
                'total_time_seconds' => 1800,
                'difficulty' => 'easy',
                'primary_category' => 'Soups',
                'devices' => ['TM6', 'Oven'],
                'optional_devices' => ['Slow cooker'],
                'equipment' => ['sieve'],
                'notes' => ['prohibited'],
            ],
            'ingredients' => [
                [
                    'name' => 'Tomato Test',
                    'source_quantity' => 500,
                    'source_quantity_max' => null,
                    'source_unit' => 'g',
                    'source_amount_text' => '500 g',
                    'source_group_index' => 0,
                    'source_group_position' => 0,
                    'source_group_title' => 'Sauce',
                    'source_ingredient_ref' => 'ingredient-tomato',
                    'source_default_title' => 'Tomato',
                    'source_unit_ref' => 'unit-g',
                    'source_optional' => false,
                    'source_shopping_category_ref' => 'category-produce',
                    'optional' => true,
                ],
                [
                    'name' => 'Tomato Test',
                    'source_quantity' => 2,
                    'source_quantity_max' => 3,
                    'source_unit' => 'pieces',
                    'source_amount_text' => '2 - 3 pieces',
                    'source_group_index' => 0,
                    'source_group_position' => 1,
                    'source_group_title' => 'Sauce',
                    'source_ingredient_ref' => 'ingredient-tomato',
                    'source_default_title' => 'Tomato',
                    'source_unit_ref' => 'unit-piece',
                    'source_optional' => true,
                    'source_shopping_category_ref' => 'category-produce',
                ],
                [
                    'name' => 'Missing Spice Test',
                    'source_quantity' => 1,
                    'source_quantity_max' => null,
                    'source_unit' => 'tsp',
                    'source_amount_text' => '1 tsp',
                    'source_group_index' => 1,
                    'source_group_position' => 0,
                    'source_group_title' => null,
                    'source_ingredient_ref' => null,
                    'source_default_title' => null,
                    'source_unit_ref' => null,
                    'source_optional' => null,
                    'source_shopping_category_ref' => null,
                ],
            ],
            'image_url' => 'https://assets.tmecosys.com/image/upload/allowlist.jpg',
            'canonical_url' => 'https://cookidoo.co.uk/recipes/recipe/en-GB/r-cookidoo-allowlist',
            'locale' => 'en-GB',
            'instructions' => ['prohibited'],
            'nutrition' => ['calories' => 100],
            'quantity' => '500 g',
            'raw_payload' => ['prohibited' => true],
        ])],
        'count' => 1,
        'pages_scanned' => 1,
        'last_page' => 0,
        'next_page' => 1,
        'last_page_had_raw_hits' => true,
        'raw_payload' => ['prohibited' => true],
    ], recipeCookidooNormalizeDiscoveryInput([
        'query' => 'allowlist',
        'locale' => 'en-GB',
        'limit' => 2,
    ]));
    recipeTestAssert(
        array_keys($allowlisted) === [
            'recipes', 'count', 'pages_scanned', 'last_page',
            'next_page', 'last_page_had_raw_hits',
            'language_rejected_count', 'language_rejected_ids',
        ]
        && array_keys($allowlisted['recipes'][0]) === [
            'external_id', 'title', 'metadata_schema_version',
            'general', 'ingredients', 'topology_metrics',
            'image_url', 'canonical_url', 'locale',
            'provider_language',
            '_language_assessment',
        ]
        && $allowlisted['language_rejected_count'] === 0
        && $allowlisted['language_rejected_ids'] === []
        && array_keys($allowlisted['recipes'][0]['general']) === [
            'yield_quantity', 'yield_unit', 'prep_time_seconds',
            'cook_time_seconds', 'active_time_seconds',
            'inactive_time_seconds', 'total_time_seconds', 'difficulty',
            'primary_category', 'devices', 'optional_devices', 'equipment',
        ]
        && array_keys($allowlisted['recipes'][0]['ingredients'][0]) === [
            'name', 'source_quantity', 'source_quantity_max',
            'source_unit', 'source_amount_text',
            'source_group_index', 'source_group_position',
            'source_group_title', 'source_ingredient_ref',
            'source_default_title', 'source_unit_ref',
            'source_optional', 'source_shopping_category_ref',
        ]
        && array_column($allowlisted['recipes'][0]['ingredients'], 'name')
            === ['Tomato Test', 'Tomato Test', 'Missing Spice Test'],
        'Cookidoo bridge normalization must retain only allowlisted recipes and scalar progress'
    );
    $batchPolicyBefore = $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_INGEST_LANGUAGE_POLICY'
    ];
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_INGEST_LANGUAGE_POLICY'
    ] = 'enforce';
    $languageFilteredBatch = recipeCookidooNormalizeBridgeResponse([
        'recipes' => [
            recipeTestCookidooBridgeRecipe([
                'external_id' => 'batch-english',
                'title' => 'Chicken soup',
                'general' => [
                    'yield_quantity' => null,
                    'yield_unit' => null,
                    'active_time_seconds' => null,
                    'total_time_seconds' => null,
                    'difficulty' => null,
                    'primary_category' => null,
                    'equipment' => [],
                ],
                'ingredients' => [
                    ['name' => 'water'],
                    ['name' => 'chicken'],
                    ['name' => 'salt'],
                ],
                'image_url' => '',
                'canonical_url' => (
                    'https://cookidoo.co.uk/recipes/recipe/en-GB/'
                    . 'batch-english'
                ),
                'locale' => 'en-GB',
            ]),
            recipeTestCookidooBridgeRecipe([
                'external_id' => 'batch-foreign',
                'title' => 'Kartoffelsuppe',
                'general' => [
                    'yield_quantity' => null,
                    'yield_unit' => null,
                    'active_time_seconds' => null,
                    'total_time_seconds' => null,
                    'difficulty' => null,
                    'primary_category' => null,
                    'equipment' => [],
                ],
                'ingredients' => [
                    ['name' => 'Wasser'],
                    ['name' => 'Kartoffeln'],
                    ['name' => 'Salz'],
                ],
                'image_url' => '',
                'canonical_url' => (
                    'https://cookidoo.co.uk/recipes/recipe/en-GB/'
                    . 'batch-foreign'
                ),
                'locale' => 'en-GB',
            ]),
        ],
        'count' => 2,
        'pages_scanned' => 1,
        'last_page' => 0,
        'next_page' => 1,
        'last_page_had_raw_hits' => true,
    ], recipeCookidooNormalizeDiscoveryInput([
        'query' => 'batch language',
        'locale' => 'en-GB',
        'limit' => 2,
    ]));
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_INGEST_LANGUAGE_POLICY'
    ] = $batchPolicyBefore;
    recipeTestAssert(
        $languageFilteredBatch['count'] === 1
        && $languageFilteredBatch['recipes'][0]['external_id']
            === 'batch-english'
        && $languageFilteredBatch['language_rejected_count'] === 1
        && $languageFilteredBatch['language_rejected_ids']
            === ['batch-foreign'],
        'One foreign Cookidoo recipe must be skipped without poisoning the entire discovery batch'
    );
    recipeTestAssert(
        recipeCookidooMetadataFailureIsPermanent(
            'content_language_rejected'
        )
        && recipeCookidooMetadataFailureNextProbeAt(
            'content_language_rejected',
            1
        ) === null,
        'Rejected Cookidoo content language must be a permanent per-item outcome'
    );
    $providerLanguageRejected = false;
    try {
        recipeCookidooNormalizeBridgeRecipe(
            recipeTestCookidooBridgeRecipe([
                'external_id' => 'provider-language-de',
                'title' => 'Chicken soup',
                'ingredients' => [
                    ['name' => 'water'],
                    ['name' => 'chicken'],
                    ['name' => 'salt'],
                ],
                'image_url' => '',
                'canonical_url' => (
                    'https://cookidoo.co.uk/recipes/recipe/en-GB/'
                    . 'provider-language-de'
                ),
                'locale' => 'en-GB',
                'provider_language' => 'de',
            ])
        );
    } catch (RecipeCookidooLanguageRejectedException $e) {
        $providerLanguageRejected = true;
    }
    $providerLanguageEnglish = recipeCookidooNormalizeBridgeRecipe(
        recipeTestCookidooBridgeRecipe([
            'external_id' => 'provider-language-en',
            'title' => 'Chicken soup',
            'ingredients' => [
                ['name' => 'water'],
                ['name' => 'chicken'],
                ['name' => 'salt'],
            ],
            'image_url' => '',
            'canonical_url' => (
                'https://cookidoo.co.uk/recipes/recipe/en-GB/'
                . 'provider-language-en'
            ),
            'locale' => 'en-GB',
            'provider_language' => 'en-US',
        ])
    );
    recipeTestAssert(
        $providerLanguageRejected
        && $providerLanguageEnglish['provider_language']
            === 'en-US'
        && $providerLanguageEnglish['_language_assessment'][
            'request_languages'
        ] === ['en'],
        'Undocumented provider language must remain bounded provenance, stay separate from locale, and explicitly reject non-English ingestion'
    );
    $fallbackGroupedIngredients = recipeCookidooNormalizeOrderedIngredients([
        ['name' => 'Fallback One'],
        ['name' => 'Fallback Two'],
    ]);
    recipeTestAssert(
        array_map(
            static fn(array $item): array => [
                $item['source_group_index'],
                $item['source_group_position'],
            ],
            $fallbackGroupedIngredients
        ) === [[0, 0], [0, 1]],
        'Boundary-free parser output must fall back to one ordered group'
    );
    $invalidGroupOrderingRejected = false;
    try {
        recipeCookidooNormalizeOrderedIngredients([
            [
                'name' => 'Invalid Group',
                'source_group_index' => 1,
                'source_group_position' => 0,
            ],
        ]);
    } catch (InvalidArgumentException $e) {
        $invalidGroupOrderingRejected = true;
    }
    recipeTestAssert(
        $invalidGroupOrderingRejected,
        'Malformed group ordinals must be rejected rather than flattened'
    );
    foreach ([
        [[
            'name' => 'Invalid reference',
            'source_ingredient_ref' => 'bad reference',
        ]],
        [[
            'name' => 'Invalid optional',
            'source_optional' => 'false',
        ]],
        [
            [
                'name' => 'First',
                'source_group_index' => 0,
                'source_group_position' => 0,
                'source_group_title' => 'One',
            ],
            [
                'name' => 'Second',
                'source_group_index' => 0,
                'source_group_position' => 1,
                'source_group_title' => 'Two',
            ],
        ],
    ] as $invalidTopology) {
        $invalidTopologyRejected = false;
        try {
            recipeCookidooNormalizeOrderedIngredients($invalidTopology);
        } catch (InvalidArgumentException $e) {
            $invalidTopologyRejected = true;
        }
        recipeTestAssert(
            $invalidTopologyRejected,
            'Malformed source topology fields must be rejected'
        );
    }
        $orphanSourceMaximumRejected = false;
        try {
            recipeCookidooNormalizeOrderedIngredients([[
                'name' => 'Orphan maximum',
                'source_quantity' => null,
                'source_quantity_max' => 3,
            ]]);
        } catch (InvalidArgumentException $e) {
            $orphanSourceMaximumRejected = true;
        }
        recipeTestAssert(
            $orphanSourceMaximumRejected,
            'Cookidoo source quantities must reject an orphan maximum'
        );
        $genericOrphanMaximumRejected = false;
        try {
            recipeCatalogSaveVariant($db, [
                'title' => 'Generic Orphan Maximum',
                'source_ingredients' => [[
                    'name' => 'Orphan maximum',
                    'source_quantity_max' => 3,
                ]],
            ], [
                'connector' => 'manual',
                'external_id' => 'generic-orphan-maximum',
            ]);
        } catch (InvalidArgumentException $e) {
            $genericOrphanMaximumRejected = true;
        }
        recipeTestAssert(
            $genericOrphanMaximumRejected,
            'Generic source rows must reject an orphan maximum'
        );
        foreach (
            ['1', '1/2 cup', '500 g', '2 - 3 pieces']
            as $closedAmount
        ) {
            recipeTestAssert(
                recipeIngredientValidateSourceAmountText(
                    $closedAmount,
                    null,
                    null,
                    null
                ) === $closedAmount,
                'Closed source amount notation must remain valid'
            );
        }
        foreach ([
            '1 peeled onion',
            '500 g flour sifted',
            '3 chicken breasts skin removed',
        ] as $proseAmount) {
            $proseAmountRejected = false;
            try {
                recipeIngredientNormalizeSourceRow($db, [
                    'name' => 'Amount prose rejection',
                    'source_amount_text' => $proseAmount,
                ], 0);
            } catch (InvalidArgumentException $e) {
                $proseAmountRejected = true;
            }
            recipeTestAssert(
                $proseAmountRejected,
                'Preparation prose must not persist as source amount text'
            );
        }
        foreach ([
            [
                'source_quantity' => 500,
                'source_unit' => 'g',
                'source_amount_text' => '400 g',
            ],
            [
                'source_quantity' => 2,
                'source_quantity_max' => 3,
                'source_unit' => 'pieces',
                'source_amount_text' => '2 pieces',
            ],
            [
                'source_quantity' => 500,
                'source_unit' => 'g',
                'source_amount_text' => '500 g flour sifted',
            ],
        ] as $inconsistentAmount) {
            $inconsistentAmountRejected = false;
            try {
                recipeIngredientNormalizeSourceRow(
                    $db,
                    ['name' => 'Inconsistent amount'] + $inconsistentAmount,
                    0
                );
            } catch (InvalidArgumentException $e) {
                $inconsistentAmountRejected = true;
            }
            recipeTestAssert(
                $inconsistentAmountRejected,
                'Structured source amount text must match quantity and unit'
            );
        }
    $invalidProgressRejected = false;
    try {
        recipeCookidooNormalizeBridgeResponse([
            'recipes' => [],
            'count' => 0,
            'pages_scanned' => 1,
            'last_page' => 0,
            'next_page' => 50,
            'last_page_had_raw_hits' => false,
        ], recipeCookidooNormalizeDiscoveryInput([
            'query' => 'invalid progress',
            'locale' => 'en-GB',
            'limit' => 1,
        ]));
    } catch (RuntimeException $e) {
        $invalidProgressRejected = true;
    }
    recipeTestAssert(
        $invalidProgressRejected,
        'Cookidoo bridge progress metadata must be normalized and validated'
    );
    recipeTestAssert(
        recipeCookidooValidateHttpsUrl(
            'https://cookidoo.thermomix.com/recipes/recipe/en-US/r1',
            'canonical_url',
            true,
            true
        ) !== ''
        && recipeCookidooValidateHttpsUrl(
            'https://cookidoo.international/recipes/recipe/en/r2',
            'canonical_url',
            true,
            true
        ) !== '',
        'Official Cookidoo hostnames must be accepted'
    );
    $evilCookidooRejected = false;
    try {
        recipeCookidooValidateHttpsUrl(
            'https://cookidoo.evil.com/recipes/r3',
            'canonical_url',
            true,
            true
        );
    } catch (InvalidArgumentException $e) {
        $evilCookidooRejected = true;
    }
    recipeTestAssert($evilCookidooRejected, 'Lookalike Cookidoo hostnames must be rejected');

    $bridgeCalls = 0;
    $lastBridgePayload = null;
    $metadataBridgeTransport = static function (
        string $url,
        string $token,
        array $payload,
        int $timeout
    ) use (&$bridgeCalls, &$lastBridgePayload): array {
        $bridgeCalls++;
        $lastBridgePayload = $payload;
        recipeTestAssert($url === 'http://cookidoo-bridge:8081/v1/search', 'Bridge URL must remain internal');
        recipeTestAssert($token === 'unit-test-token', 'Bridge bearer token must be supplied');
        recipeTestAssert($timeout === 5, 'Bridge timeout must be bounded');
        $isMetadataRefresh = $bridgeCalls > 1;
        return [
            'status' => 200,
            'body' => json_encode([
                'recipes' => [recipeTestCookidooBridgeRecipe([
                    'external_id' => 'r-cookidoo-metadata-1',
                    'title' => $isMetadataRefresh
                        ? 'Bridge Title Must Not Replace Catalog'
                        : 'Cookidoo Tomato Cloud Soup',
                    'general' => [
                        'yield_quantity' => $isMetadataRefresh ? 6 : 4,
                        'yield_unit' => 'portions',
                        'prep_time_seconds' =>
                            $isMetadataRefresh ? 360 : 300,
                        'cook_time_seconds' =>
                            $isMetadataRefresh ? 1200 : 900,
                        'active_time_seconds' => $isMetadataRefresh ? 720 : 600,
                        'inactive_time_seconds' =>
                            $isMetadataRefresh ? 180 : 120,
                        'total_time_seconds' => $isMetadataRefresh
                            ? 2100
                            : 2682000,
                        'difficulty' => $isMetadataRefresh ? 'medium' : 'easy',
                        'primary_category' => $isMetadataRefresh ? 'Main dishes' : 'Soups',
                        'devices' => $isMetadataRefresh
                            ? ['TM7', 'Air fryer']
                            : ['TM6', 'Oven'],
                        'optional_devices' => $isMetadataRefresh
                            ? ['Slow cooker']
                            : ['Sous vide cooker'],
                        'equipment' => $isMetadataRefresh ? ['whisk'] : ['sieve'],
                        'notes' => ['must never persist'],
                    ],
                    'ingredients' => [
                        [
                            'name' => 'Tomato Test',
                            'source_quantity' => 500,
                            'source_quantity_max' => null,
                            'source_unit' => 'g',
                            'source_amount_text' => '500 g',
                            'source_group_index' => 0,
                            'source_group_position' => 0,
                            'source_group_title' => 'Sauce',
                            'source_ingredient_ref' => 'ingredient-tomato',
                            'source_default_title' => 'Tomato',
                            'source_unit_ref' => 'unit-g',
                            'source_optional' => false,
                            'source_shopping_category_ref' => 'category-produce',
                        ],
                        [
                            'name' => 'Missing Spice Test',
                            'source_quantity' => 1,
                            'source_quantity_max' => null,
                            'source_unit' => 'tsp',
                            'source_amount_text' => '1 tsp',
                            'source_group_index' => 0,
                            'source_group_position' => 1,
                            'source_group_title' => 'Sauce',
                            'source_ingredient_ref' => 'ingredient-spice',
                            'source_default_title' => 'Spice',
                            'source_unit_ref' => 'unit-tsp',
                            'source_optional' => false,
                            'source_shopping_category_ref' => 'category-spices',
                        ],
                        [
                            'name' => 'Basil Test',
                            'source_quantity' => null,
                            'source_quantity_max' => null,
                            'source_unit' => null,
                            'source_amount_text' => null,
                            'source_group_index' => $isMetadataRefresh ? 1 : 0,
                            'source_group_position' => $isMetadataRefresh ? 0 : 2,
                            'source_group_title' => $isMetadataRefresh
                                ? 'Garnish'
                                : 'Sauce',
                            'source_ingredient_ref' => 'ingredient-basil',
                            'source_default_title' => 'Basil',
                            'source_unit_ref' => null,
                            'source_optional' => null,
                            'source_shopping_category_ref' => 'category-produce',
                        ],
                        [
                            'name' => 'Tomato Test',
                            'source_quantity' => 2,
                            'source_quantity_max' => 3,
                            'source_unit' => 'pieces',
                            'source_amount_text' => '2 - 3 pieces',
                            'source_group_index' => 1,
                            'source_group_position' => $isMetadataRefresh ? 1 : 0,
                            'source_group_title' => 'Garnish',
                            'source_ingredient_ref' => 'ingredient-tomato',
                            'source_default_title' => 'Tomato',
                            'source_unit_ref' => 'unit-piece',
                            'source_optional' => true,
                            'source_shopping_category_ref' => 'category-produce',
                        ],
                        [
                            'name' => 'Missing Spice Test',
                            'source_quantity' => 2,
                            'source_quantity_max' => null,
                            'source_unit' => 'tsp',
                            'source_amount_text' => '2 tsp',
                            'source_group_index' => 1,
                            'source_group_position' => $isMetadataRefresh ? 2 : 1,
                            'source_group_title' => 'Garnish',
                            'source_ingredient_ref' => 'ingredient-spice',
                            'source_default_title' => 'Spice',
                            'source_unit_ref' => 'unit-tsp',
                            'source_optional' => true,
                            'source_shopping_category_ref' => 'category-spices',
                        ],
                    ],
                    'image_url' => $isMetadataRefresh
                        ? 'https://assets.tmecosys.com/image/upload/must-not-replace.jpg'
                        : 'https://assets.tmecosys.com/image/upload/cookidoo-r1.jpg',
                    'canonical_url' => 'https://cookidoo.co.uk/recipes/recipe/en-GB/r-cookidoo-metadata-1',
                    'locale' => 'en-GB',
                    'provider_language' => 'en',
                    'instructions' => ['must never persist'],
                    'nutrition' => ['calories' => 123],
                    'quantities' => ['500 g'],
                ])],
                'count' => 1,
                'pages_scanned' => 1,
                'last_page' => (int)($payload['page'] ?? 0),
                'next_page' => (int)($payload['page'] ?? 0) + 1,
                'last_page_had_raw_hits' => true,
                'raw_payload' => ['must never persist'],
            ], JSON_UNESCAPED_SLASHES),
        ];
    };
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] = $metadataBridgeTransport;

    $db->prepare("
        UPDATE recipe_connector_state SET enabled = 0
        WHERE connector = 'cookidoo'
    ")->execute();
    $disabledOutcome = recipeJobDispatchConnectorDiscovery($db, [
        'connector' => 'cookidoo',
    ], [
        'query' => 'disabled connector',
        'ingredients' => [[
            'name' => 'Locale Test Ingredient',
            'source_quantity' => 1,
            'source_unit' => 'piece',
            'source_amount_text' => '1 piece',
        ]],
        'exclude_ingredients' => [],
        'locale' => 'en-GB',
        'tmv' => 'TM6',
        'limit' => 1,
    ]);
    $disabledMetadataOutcome = recipeJobDispatchMetadataRefresh(
        $db,
        ['connector' => 'cookidoo'],
        []
    );
    $disabledMetadataPlan = recipeCookidooMetadataBackfillPlan(
        $db,
        'en-GB',
        20,
        200
    );
    recipeTestAssert(
        $disabledOutcome['status'] === 'skipped'
        && $disabledOutcome['result']['reason'] === 'connector_disabled'
        && $disabledMetadataOutcome['status'] === 'skipped'
        && $disabledMetadataOutcome['result']['reason']
            === 'connector_disabled'
        && $disabledMetadataPlan['refreshable'] === false
        && $disabledMetadataPlan['unrefreshable_reason']
            === 'connector_disabled'
        && $bridgeCalls === 0,
        'Disabled Cookidoo connector must block discovery and metadata traffic'
    );
    $jobsBeforeDisabledDiscovery = recipeTestCount(
        $db,
        "SELECT COUNT(*) FROM recipe_jobs"
    );
    $disabledDiscovery = recipeCookidooDiscover($db, [
        'query' => 'disabled connector search',
        'interactive' => true,
        'include_local_results' => false,
    ]);
    recipeTestAssert(
        $disabledDiscovery['connector_enabled'] === false
        && $disabledDiscovery['job'] === null
        && recipeTestCount($db, "SELECT COUNT(*) FROM recipe_jobs")
            === $jobsBeforeDisabledDiscovery,
        'Disabled Cookidoo discovery must not enqueue a false-success hydration job'
    );
    $db->prepare("
        UPDATE recipe_connector_state SET enabled = 1
        WHERE connector = 'cookidoo'
    ")->execute();

    $discoveryOne = recipeCookidooDiscover($db, [
        'query' => 'Cookidoo Metadata',
        'ingredient_names' => ['Basil Test', 'Tomato Test'],
        'exclude_names' => ['Cream Test'],
        'locale' => 'en',
        'limit' => 2,
    ]);
    $discoveryTwo = recipeCookidooDiscover($db, [
        'query' => 'cookidoo metadata',
        'ingredients' => ['tomato test', 'basil test'],
        'exclude_ingredients' => ['cream test'],
        'locale' => 'en',
        'limit' => 2,
    ]);
    recipeTestAssert(
        $discoveryOne['job']['id'] === $discoveryTwo['job']['id']
        && $discoveryOne['job']['idempotency_key'] === $discoveryTwo['job']['idempotency_key'],
        'Cookidoo discovery idempotency must ignore ingredient order and case'
    );
    $interactiveDiscovery = recipeCookidooDiscover($db, [
        'query' => 'cookidoo metadata',
        'ingredients' => ['tomato test', 'basil test'],
        'exclude_ingredients' => ['cream test'],
        'locale' => 'en',
        'limit' => 2,
        'interactive' => true,
    ]);
    recipeTestAssert(
        $interactiveDiscovery['job']['id'] === $discoveryOne['job']['id']
        && $interactiveDiscovery['job_reused'] === true
        && $interactiveDiscovery['job']['priority'] === 100,
        'Interactive discovery must reuse and elevate an equivalent queued search'
    );
    $differentRequest = recipeCookidooNormalizeDiscoveryInput([
        'query' => 'cookidoo metadata',
        'ingredients' => ['Basil Test', 'Tomato Test'],
        'exclude_ingredients' => ['Cream Test'],
        'locale' => 'en',
        'tmv' => 'TM7',
        'limit' => 3,
        'page' => 1,
    ]);
    recipeTestAssert(
        recipeCookidooDiscoveryIdempotencyKey($differentRequest)
            !== $discoveryOne['job']['idempotency_key']
        && recipeCookidooSearchId($differentRequest) !== $discoveryOne['search_id'],
        'Cookidoo discovery identity must include TM version and result limit'
    );
    $excludedSetA = recipeCookidooNormalizeDiscoveryInput([
        'query' => 'exclude identity',
        'locale' => 'en-GB',
        'limit' => 2,
        'exclude_ids' => ['recipe-z', 'recipe-a', 'recipe-a'],
    ]);
    $excludedSetB = recipeCookidooNormalizeDiscoveryInput([
        'query' => 'exclude identity',
        'locale' => 'en-GB',
        'limit' => 2,
        'exclude_ids' => ['recipe-a', 'recipe-z'],
    ]);
    $excludedSetC = recipeCookidooNormalizeDiscoveryInput([
        'query' => 'exclude identity',
        'locale' => 'en-GB',
        'limit' => 2,
        'exclude_ids' => ['recipe-a', 'recipe-x'],
    ]);
    $excludedIdentity = recipeCookidooDiscoveryIdentity($excludedSetA);
    recipeTestAssert(
        recipeCookidooDiscoveryIdempotencyKey($excludedSetA)
            === recipeCookidooDiscoveryIdempotencyKey($excludedSetB)
        && recipeCookidooSearchId($excludedSetA)
            === recipeCookidooSearchId($excludedSetB)
        && recipeCookidooDiscoveryIdempotencyKey($excludedSetA)
            !== recipeCookidooDiscoveryIdempotencyKey($excludedSetC)
        && recipeCookidooSearchId($excludedSetA)
            !== recipeCookidooSearchId($excludedSetC)
        && $excludedIdentity['exclude_ids_count'] === 2
        && strlen($excludedIdentity['exclude_ids_digest']) === 64
        && !array_key_exists('exclude_ids', $excludedIdentity),
        'Discovery cache and job identity must include an order-insensitive exclusion digest'
    );
    $fullCrawlRequest = recipeCookidooNormalizeDiscoveryInput([
        'query' => 'full crawl contract',
        'locale' => 'en-GB',
        'tmv' => 'TM6',
        'limit' => 3,
        'max_pages' => 7,
        'exclude_cached' => false,
        'crawl_all' => true,
    ]);
    $fullCrawlNextPage = $fullCrawlRequest;
    $fullCrawlNextPage['page'] = 1;
    recipeTestAssert(
        $fullCrawlRequest['crawl_all'] === true
        && $fullCrawlRequest['limit'] === 20
        && $fullCrawlRequest['max_pages'] === 1
        && $fullCrawlRequest['exclude_cached'] === true
        && recipeCookidooDiscoveryIdempotencyKey($fullCrawlRequest)
            !== recipeCookidooDiscoveryIdempotencyKey($fullCrawlNextPage)
        && recipeCookidooSearchId($fullCrawlRequest)
            === recipeCookidooSearchId($fullCrawlNextPage),
        'Full-crawl requests must normalize to one stable 20-hit job per page'
    );
    $legacyScopeRequest = recipeCookidooNormalizeDiscoveryInput([
        'query' => 'legacy scope migration',
        'locale' => 'en-GB',
        'page' => 1,
        'crawl_all' => true,
    ]);
    $legacyScopeKey =
        recipeCookidooDiscoveryIdempotencyKey($legacyScopeRequest);
    $legacyScopeJob = recipeJobEnqueue(
        $db,
        'connector_discovery',
        [
            'scope' => 'cookidoo:' . substr(
                recipeCookidooDiscoveryHash($legacyScopeRequest),
                0,
                24
            ),
            'connector' => 'cookidoo',
            'query' => $legacyScopeRequest['query'],
        ],
        $legacyScopeRequest,
        $legacyScopeKey
    );
    $db->prepare("
        UPDATE recipe_jobs SET status = 'done'
        WHERE id = ?
    ")->execute([(int)$legacyScopeJob['id']]);
    $migratedScope = recipeCookidooEnqueueDiscoveryJob(
        $db,
        $legacyScopeRequest,
        false
    );
    recipeTestAssert(
        !empty($migratedScope['migrated'])
        && (int)$migratedScope['job']['id']
            === (int)$legacyScopeJob['id']
        && $migratedScope['job']['status'] === 'pending'
        && $migratedScope['job']['scope']
            === recipeCookidooSearchId($legacyScopeRequest)
        && $migratedScope['job']['payload'][
            RECIPE_COOKIDOO_POLICY_FIELD
        ] === RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
        'Legacy page-scoped discovery jobs must migrate and requeue under the current crawl-wide scope'
    );
    $db->prepare("DELETE FROM recipe_jobs WHERE id = ?")
        ->execute([(int)$legacyScopeJob['id']]);
    $invalidCrawlFlagRejected = false;
    try {
        recipeCookidooNormalizeDiscoveryInput([
            'query' => 'invalid crawl flag',
            'crawl_all' => 'sometimes',
        ]);
    } catch (InvalidArgumentException $e) {
        $invalidCrawlFlagRejected = true;
    }
    recipeTestAssert(
        $invalidCrawlFlagRejected,
        'crawl_all must be validated as a boolean'
    );
    recipeTestAssert(
        str_starts_with($discoveryOne['search_id'], 'cookidoo:')
        && isset($discoveryOne['local_results']['results']),
        'Discovery must return a stable search ID and local results without network work'
    );
    $lockOrderRejected = false;
    $db->beginTransaction();
    try {
        recipeCookidooDiscoveryCatalogLock($db);
    } catch (RuntimeException $e) {
        $lockOrderRejected = str_contains(
            $e->getMessage(),
            'before a write transaction'
        );
    } finally {
        $db->rollBack();
    }
    recipeTestAssert(
        $lockOrderRejected,
        'Discovery must reject SQLite-before-flock lock ordering'
    );

    $cookidooQueue = recipeJobProcessQueueBatch($db, 5, 3);
    recipeTestAssert(
        $cookidooQueue['succeeded'] === 1
        && $bridgeCalls === 1
        && (int)($GLOBALS['_RECIPE_CATALOG_LOCK_DEPTH'] ?? 0) === 0
        && !$db->inTransaction(),
        'Cookidoo discovery worker must use the mocked bridge once: '
            . json_encode($cookidooQueue)
    );
    $cookidooJob = recipeJobGet($db, $discoveryOne['job']['id']);
    $cookidooRecipeId = (int)($cookidooJob['result']['imported_ids'][0] ?? 0);
    recipeTestAssert($cookidooRecipeId > 0, 'Cookidoo dispatch must return imported recipe IDs');
    recipeTestAssert(
        array_keys($lastBridgePayload) === [
            'query',
            'ingredients',
            'exclude_ingredients',
            'locale',
            'languages',
            'tmv',
            'limit',
            'page',
            'exclude_ids',
            'max_pages',
        ]
        && $lastBridgePayload['tmv'] === 'TM6'
        && $lastBridgePayload['limit'] === 2
        && $lastBridgePayload['locale'] === 'en'
        && $lastBridgePayload['languages'] === ['en']
        && !array_key_exists('interactive', $lastBridgePayload)
        && !array_key_exists('force', $lastBridgePayload)
        && !array_key_exists('include_local_results', $lastBridgePayload)
        && !array_key_exists('exclude_cached', $lastBridgePayload)
        && !array_key_exists('crawl_all', $lastBridgePayload),
        'Cookidoo bridge payload must contain only SearchRequest fields'
    );

    $db->prepare("
        UPDATE recipe_connector_state
        SET circuit_open_until = datetime('now', '+30 minutes')
        WHERE connector = 'cookidoo'
    ")->execute();
    $circuitDiscovery = recipeCookidooDiscover($db, [
        'query' => 'circuit test',
        'locale' => 'en-GB',
        'limit' => 1,
    ]);
    recipeJobProcessQueueBatch($db, 5, 3);
    $circuitJob = recipeJobGet($db, $circuitDiscovery['job']['id']);
    recipeTestAssert(
        $circuitJob['status'] === 'retry'
        && $circuitJob['next_retry_at'] !== null
        && $circuitJob['attempts'] === 0,
        'Circuit-open discovery must remain retryable without consuming attempts'
    );
    $db->prepare("
        UPDATE recipe_connector_state
        SET circuit_open_until = NULL
        WHERE connector = 'cookidoo'
    ")->execute();

    $cookidooRecipe = recipeCatalogGetById($db, $cookidooRecipeId);
    $cookidooOrigin = $db->query("
        SELECT canonical_url, locale, metadata_schema_version
        FROM recipe_origins
        WHERE recipe_id = {$cookidooRecipeId}
          AND connector = 'cookidoo'
    ")->fetch(PDO::FETCH_ASSOC);
    recipeTestAssert(
        $cookidooRecipe['primary_connector'] === 'cookidoo'
        && $cookidooRecipe['storage_policy'] === 'metadata_only'
        && $cookidooRecipe['rights_basis'] === 'cookidoo_metadata_operator_approved'
        && $cookidooRecipe['image_url'] === 'https://assets.tmecosys.com/image/upload/cookidoo-r1.jpg'
        && $cookidooRecipe['stale_at'] !== null
        && $cookidooRecipe['cache_expires_at'] === null
        && abs((float)$cookidooRecipe['yield_quantity'] - 4.0) < 0.000001
        && $cookidooRecipe['yield_unit'] === 'portions'
        && $cookidooRecipe['prep_time_seconds'] === 300
        && $cookidooRecipe['cook_time_seconds'] === 900
        && $cookidooRecipe['active_time_seconds'] === 600
        && $cookidooRecipe['inactive_time_seconds'] === 120
        && $cookidooRecipe['total_time_seconds'] === 2682000
        && $cookidooRecipe['difficulty'] === 'easy'
        && $cookidooRecipe['primary_category'] === 'Soups'
        && $cookidooRecipe['devices'] === ['TM6', 'Oven']
        && $cookidooRecipe['optional_devices'] === ['Sous vide cooker']
        && $cookidooRecipe['equipment'] === ['sieve'],
        'Cookidoo recipes must persist only approved metadata policy fields'
    );
    $overlongDiscoveryImportRejected = false;
    try {
        recipeCatalogSaveVariant($db, [
            'title' => 'Overlong Discovery Import',
            'total_time_seconds' =>
                RECIPE_MAX_FACTUAL_DURATION_SECONDS + 1,
            'source_ingredients' => [],
        ], [
            'connector' => 'cookidoo',
            'external_id' => 'overlong-discovery-import',
            'canonical_url' => (
                'https://cookidoo.co.uk/recipes/recipe/en-GB/'
                . 'overlong-discovery-import'
            ),
            'locale' => 'en-GB',
        ]);
    } catch (InvalidArgumentException $e) {
        $overlongDiscoveryImportRejected = true;
    }
    recipeTestAssert(
        $overlongDiscoveryImportRejected
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_origins
             WHERE connector = 'cookidoo'
               AND external_id = 'overlong-discovery-import'"
        ) === 0,
        'Initial discovery imports must reject durations above 366 days'
    );
    recipeTestAssert(
        ($cookidooOrigin['locale'] ?? null) === 'en-GB'
        && ($cookidooOrigin['canonical_url'] ?? null)
            === 'https://cookidoo.co.uk/recipes/recipe/en-GB/r-cookidoo-metadata-1'
        && ($cookidooOrigin['metadata_schema_version'] ?? null)
            === RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        'Language-only discovery must persist the effective regional locale and canonical URL'
    );
    $db->prepare("
        UPDATE recipe_origins
        SET metadata_version = NULL,
            metadata_schema_version = NULL
        WHERE recipe_id = ? AND connector = 'cookidoo'
    ")->execute([$cookidooRecipeId]);
    recipeTestAssert(
        in_array(
            'r-cookidoo-metadata-1',
            array_column(
                recipeCookidooMetadataBackfillCandidates(
                    $db,
                    'en-GB',
                    0,
                    200
                ),
                'external_id'
            ),
            true
        ),
        'Effective regional discovery rows must be eligible for exact-locale metadata refresh'
    );
    $db->prepare("
        UPDATE recipe_origins
        SET metadata_version = ?
        WHERE recipe_id = ? AND connector = 'cookidoo'
    ")->execute([RECIPE_COOKIDOO_METADATA_VERSION, $cookidooRecipeId]);
    $db->prepare("
        UPDATE recipe_origins
        SET metadata_schema_version = NULL
        WHERE recipe_id = ? AND connector = 'cookidoo'
    ")->execute([$cookidooRecipeId]);
    recipeTestAssert(
        in_array(
            'r-cookidoo-metadata-1',
            array_column(
                recipeCookidooMetadataBackfillCandidates(
                    $db,
                    'en-GB',
                    0,
                    200
                ),
                'external_id'
            ),
            true
        ),
        'Metadata-v2 rows must remain candidates until topology schema is current'
    );
    $db->prepare("
        UPDATE recipe_origins
        SET metadata_schema_version = ?
        WHERE recipe_id = ? AND connector = 'cookidoo'
    ")->execute([
        RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        $cookidooRecipeId,
    ]);
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_catalog
             WHERE id = ? AND source_payload_json IS NULL
               AND description = ''
               AND servings IS NULL
               AND prep_time IS NULL
               AND cook_time IS NULL
               AND total_time IS NULL",
            [$cookidooRecipeId]
        ) === 1,
        'Cookidoo metadata must not retain source payloads or recipe content'
    );
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_ingredients
             WHERE recipe_id = ?
               AND quantity IS NULL
               AND quantity_text IS NULL
               AND unit IS NULL",
            [$cookidooRecipeId]
        ) === 3,
        'Cookidoo ranking ingredients must remain deduplicated names without quantities'
    );
    $sourceRows = $db->prepare("
        SELECT position, name, source_quantity, source_quantity_max,
               source_unit, source_amount_text, source_group_index,
               source_group_position, source_group_title,
               source_ingredient_ref, source_default_title,
               source_unit_ref, source_optional,
               source_shopping_category_ref, mapping_version
        FROM recipe_source_ingredients
        WHERE recipe_id = ?
        ORDER BY position
    ");
    $sourceRows->execute([$cookidooRecipeId]);
    $sourceRows = $sourceRows->fetchAll(PDO::FETCH_ASSOC);
    recipeTestAssert(
        array_column($sourceRows, 'name') === [
            'Tomato Test',
            'Missing Spice Test',
            'Basil Test',
            'Tomato Test',
            'Missing Spice Test',
        ]
        && (float)$sourceRows[0]['source_quantity'] === 500.0
        && $sourceRows[0]['source_unit'] === 'g'
        && (float)$sourceRows[3]['source_quantity'] === 2.0
        && (float)$sourceRows[3]['source_quantity_max'] === 3.0
        && $sourceRows[3]['source_amount_text'] === '2 - 3 pieces',
        'Cookidoo source ingredients must preserve ordered repeated display-only facts'
    );
    recipeTestAssert(
        array_map(
            static fn(array $row): array => [
                (int)$row['source_group_index'],
                (int)$row['source_group_position'],
            ],
            $sourceRows
        ) === [[0, 0], [0, 1], [0, 2], [1, 0], [1, 1]]
        && array_unique(array_column($sourceRows, 'mapping_version'))
            === [RECIPE_SOURCE_MAPPING_VERSION_LEGACY]
        && array_column($sourceRows, 'source_group_title')
            === ['Sauce', 'Sauce', 'Sauce', 'Garnish', 'Garnish']
        && array_column($sourceRows, 'source_ingredient_ref') === [
            'ingredient-tomato',
            'ingredient-spice',
            'ingredient-basil',
            'ingredient-tomato',
            'ingredient-spice',
        ]
        && array_map(
            static fn(array $row): ?bool =>
                $row['source_optional'] !== null
                    ? (bool)$row['source_optional']
                    : null,
            $sourceRows
        ) === [false, false, null, true, true],
        'Cookidoo source rows must persist group ordinals and the active legacy mapper version'
    );
    recipeTestAssert(
        $cookidooRecipe['instructions'] === []
        && $cookidooRecipe['instruction_groups'] === []
        && $cookidooRecipe['nutrition'] === []
        && $cookidooRecipe['source_payload'] === [],
        'Prohibited Cookidoo fields must be absent after readback'
    );
    $cookidooRankBeforeAmountChange = recipeCatalogRankRecipe(
        $db,
        $cookidooRecipe,
        recipeInventoryCandidates($db)
    );
    $db->prepare("
        UPDATE recipe_source_ingredients
        SET source_quantity = 999, source_amount_text = '999 g'
        WHERE recipe_id = ? AND position = 0
    ")->execute([$cookidooRecipeId]);
    $cookidooRankAfterAmountChange = recipeCatalogRankRecipe(
        $db,
        recipeCatalogGetById($db, $cookidooRecipeId),
        recipeInventoryCandidates($db)
    );
    recipeTestAssert(
        $cookidooRankBeforeAmountChange === $cookidooRankAfterAmountChange,
        'Display-only source amounts must not change ranking, coverage, or cookability'
    );

    $manualCookidooUrl = recipeCatalogSaveVariant($db, [
        'title' => 'Operator Recipe With Cookidoo Link',
        'ingredients' => [['name' => 'Tomato Test']],
        'source_ingredients' => [[
            'name' => 'Tomato Test',
            'source_group_index' => 0,
            'source_group_position' => 0,
            'source_group_title' => 'Local ingredients',
        ]],
        'steps' => ['Keep this operator-authored instruction.'],
        'instruction_groups' => [[
            'label' => 'Operator preparation',
            'step_positions' => [0],
        ]],
    ], [
        'connector' => 'manual',
        'external_id' => 'manual-cookidoo-url',
        'canonical_url' => 'https://cookidoo.co.uk/recipes/recipe/en-GB/shared-url',
    ]);
    $metadataSameUrl = recipeCatalogSaveVariant($db, [
        'title' => 'Cookidoo Metadata Same URL',
        'image_url' => 'https://assets.tmecosys.com/image/upload/shared-url.jpg',
        'ingredients' => [['name' => 'Tomato Test']],
    ], [
        'connector' => 'cookidoo',
        'external_id' => 'cookidoo-shared-url',
        'canonical_url' => 'https://cookidoo.co.uk/recipes/recipe/en-GB/shared-url',
        'locale' => 'en-GB',
    ]);
    recipeTestAssert(
        $manualCookidooUrl['id'] !== $metadataSameUrl['id']
        && recipeCatalogGetById($db, $manualCookidooUrl['id'])['instructions']
            === ['Keep this operator-authored instruction.'],
        'Cookidoo metadata must not overwrite persistent content sharing a canonical URL'
    );
    $db->prepare("DELETE FROM recipe_source_ingredients WHERE recipe_id = ?")
        ->execute([(int)$metadataSameUrl['id']]);
    $db->prepare("
        UPDATE recipe_origins
        SET metadata_version = NULL,
            metadata_schema_version = NULL
        WHERE recipe_id = ? AND connector = 'cookidoo'
    ")->execute([(int)$metadataSameUrl['id']]);
    $metadataV1Detail = recipeCatalogDetail($db, (int)$metadataSameUrl['id']);
    recipeTestAssert(
        $metadataV1Detail !== null
        && count($metadataV1Detail['ingredients']) === 1
        && $metadataV1Detail['ingredients'][0]['inventory']['quantity_state'] === 'unknown'
        && $metadataV1Detail['instructions']['reason'] === 'provider_external_only',
        'Existing metadata-v1 rows without source detail must degrade gracefully'
    );
    $db->prepare("
        UPDATE recipe_origins
        SET metadata_version = ?,
            metadata_schema_version = ?
        WHERE recipe_id = ? AND connector = 'cookidoo'
    ")->execute([
        RECIPE_COOKIDOO_METADATA_VERSION,
        RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        (int)$metadataSameUrl['id'],
    ]);
    recipeTestAssert(
        recipeCatalogTextSearch($db, 'operator', 'cookidoo', 20, 0)['total'] === 0,
        'Cookidoo source filtering must not expose manual recipe content'
    );
    $crossConnectorRejected = false;
    try {
        recipeCatalogSaveVariant($db, [
            'title' => 'Forbidden Manual Rewrite',
            'ingredients' => [['name' => 'Tomato Test']],
            'steps' => ['This must not replace metadata.'],
        ], [
            'recipe_id' => $metadataSameUrl['id'],
            'connector' => 'manual',
            'external_id' => 'forbidden-cross-connector',
        ]);
    } catch (InvalidArgumentException $e) {
        $crossConnectorRejected = true;
    }
    recipeTestAssert(
        $crossConnectorRejected
        && recipeCatalogGetById($db, $metadataSameUrl['id'])['storage_policy'] === 'metadata_only',
        'Cross-connector recipe_id updates must be rejected'
    );
    recipeTestAssert(
        recipeCatalogTextSearch($db, 'cloud', 'cookidoo', 20, 0)['total'] === 1
        && recipeCatalogTextSearch($db, 'basil', 'cookidoo', 20, 0)['total'] === 1,
        'Cookidoo title and ingredient names must be indexed in FTS'
    );
    recipeTestAssert(
        recipeCatalogTextSearch($db, 'cloud', 'non_cookidoo', 20, 0)['total'] === 0
        && recipeCatalogTextSearch($db, 'roasted', 'non_cookidoo', 20, 0)['total'] >= 1,
        'Source filtering must support excluding all Cookidoo metadata'
    );
    $duplicateDetailRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Duplicate Detail Ranking Rows',
        'ingredients' => [
            ['name' => 'duplicate detail herb zxq'],
            ['name' => 'duplicate detail herb zxq'],
        ],
        'source_ingredients' => [[
            'name' => 'duplicate detail herb zxq',
        ]],
        'instructions' => [],
    ], [
        'connector' => 'manual',
        'external_id' => 'duplicate-detail-ranking-rows',
    ]);
    $duplicateRankingRows = $db->prepare("
        SELECT id, position
        FROM recipe_ingredients
        WHERE recipe_id = ?
        ORDER BY position
    ");
    $duplicateRankingRows->execute([(int)$duplicateDetailRecipe['id']]);
    $duplicateRankingRows = $duplicateRankingRows->fetchAll(PDO::FETCH_ASSOC);
    $db->prepare("
        UPDATE recipe_ingredients
        SET mapping_confidence = 0.9,
            mapping_source = CASE position
                WHEN 0 THEN 'taxonomy_alias'
                ELSE 'taxonomy_rule'
            END
        WHERE recipe_id = ?
    ")->execute([(int)$duplicateDetailRecipe['id']]);
    $duplicateDetailRows = recipeDetailLoadIngredients(
        $db,
        (int)$duplicateDetailRecipe['id']
    )['rows'];
    recipeTestAssert(
        count($duplicateDetailRows) === 1
            && (int)$duplicateDetailRows[0]['ranking_ingredient_id']
                === (int)$duplicateRankingRows[0]['id']
            && $duplicateDetailRows[0]['mapping_source']
                === 'taxonomy_alias',
        'Detail source rows must select one deterministic best ranking mapping without fanout'
    );

    $db->prepare("
        UPDATE recipe_catalog SET
            instructions_json = ?,
            instruction_groups_json = ?
        WHERE id = ?
    ")->execute([
        recipeCatalogJsonEncode(['Synthetic provider step must stay external']),
        recipeCatalogJsonEncode([[
            'index' => 0,
            'label' => 'Synthetic provider group',
            'step_positions' => [0],
        ]]),
        $cookidooRecipeId,
    ]);
    $cookidooDetail = recipeCatalogDetail($db, $cookidooRecipeId);
    recipeTestAssert(
        $cookidooDetail !== null
        && $cookidooDetail['schema_version'] === 'recipe_detail_v1'
        && $cookidooDetail['source']['connector'] === 'cookidoo'
        && $cookidooDetail['source']['attribution'] === 'Cookidoo'
        && $cookidooDetail['source']['metadata_schema_version']
            === RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION
        && $cookidooDetail['source']['content_language'] === 'en'
        && $cookidooDetail['general'] === [
            'yield' => [
                'quantity' => 4.0,
                'unit' => 'portions',
            ],
            'prep_time_seconds' => 300,
            'cook_time_seconds' => 900,
            'active_time_seconds' => 600,
            'inactive_time_seconds' => 120,
            'total_time_seconds' => 2682000,
            'difficulty' => 'easy',
            'primary_category' => 'Soups',
            'devices' => ['TM6', 'Oven'],
            'optional_devices' => ['Sous vide cooker'],
            'equipment' => ['sieve'],
        ]
        && $cookidooDetail['capabilities'] === [
            'general' => 'full',
            'ingredients' => 'checklist',
            'instructions' => 'external_link',
            'quantities' => 'display_only',
            'grocery_add' => true,
            'ingredient_feedback' => true,
            'ingredient_feedback_v2' => true,
            'planner' => false,
            'score_preview' => [
                'requested' => false,
                'active' => false,
                'status' => 'disabled',
                'configured_revision_id' => null,
                'diagnostics' => [],
            ],
        ]
        && $cookidooDetail['grocery'] === [
            'missing_count' => 2,
            'uncertain_count' => 0,
            'in_stock_count' => 3,
            'staple_count' => 0,
            'eligible_count' => 2,
            'max_selections' => 100,
            'blocked_reason' => null,
        ],
        'Cookidoo detail must expose the compact metadata-v2 capability contract'
    );
    recipeTestAssert(
        $cookidooDetail['instructions'] === [
            'available' => false,
            'reason' => 'provider_external_only',
            'steps' => [],
            'fallback_url' => 'https://cookidoo.co.uk/recipes/recipe/en-GB/r-cookidoo-metadata-1',
            'truncated' => false,
        ]
        && !array_key_exists('groups', $cookidooDetail['instructions']),
        'Cookidoo detail must remain external-only without instruction text'
    );
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_DETAIL_HYDRATION_ENABLED'
    ] = 'false';
    $cachedPolicyDetail = recipeCatalogDetail($db, $cookidooRecipeId);
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_DETAIL_HYDRATION_ENABLED'
    ] = 'true';
    recipeTestAssert(
        $cachedPolicyDetail !== null
        && $cachedPolicyDetail['id'] === $cookidooRecipeId
        && $cachedPolicyDetail['ingredients']
            === $cookidooDetail['ingredients'],
        'Policy disablement must preserve existing cached catalog readability'
    );
    recipeTestAssert(
        array_column($cookidooDetail['ingredients'], 'name') === [
            'Tomato Test',
            'Missing Spice Test',
            'Basil Test',
            'Tomato Test',
            'Missing Spice Test',
        ]
        && array_column($cookidooDetail['ingredients'], 'display_name')
            === array_column($cookidooDetail['ingredients'], 'name')
        && array_column($cookidooDetail['ingredients'], 'source_text') === [
            'Tomato Test',
            'Missing Spice Test',
            'Basil Test',
            'Tomato Test',
            'Missing Spice Test',
        ]
        && ($cookidooDetail['ingredients'][0]['closest_match']['label'] ?? null)
            === 'Tomato Test'
        && in_array(
            $cookidooDetail['ingredients'][0]['closest_match']['mapping_source'] ?? '',
            ['taxonomy_alias', 'taxonomy_slug', 'canonical_slug'],
            true
        )
        && !array_filter(
            array_keys($cookidooDetail['ingredients'][0]),
            static fn(string $key): bool => str_starts_with($key, '_')
        )
        && $cookidooDetail['ingredients'][0]['amount']['text'] === '999 g'
        && $cookidooDetail['ingredients'][0]['inventory']['state'] === 'in_stock'
        && $cookidooDetail['ingredients'][0]['inventory']['relation'] === 'exact'
        && $cookidooDetail['ingredients'][0]['inventory']['quantity_state'] === 'display_only'
        && $cookidooDetail['ingredients'][0]['inventory']['quantity_sufficiency'] === 'unknown'
        && $cookidooDetail['ingredients'][1]['inventory']['state'] === 'missing'
        && $cookidooDetail['ingredients'][4]['inventory']['state'] === 'missing'
        && $cookidooDetail['ingredients'][0]['provider'] === [
            'ingredient_ref' => 'ingredient-tomato',
            'default_title' => 'Tomato',
            'unit_ref' => 'unit-g',
            'optional' => false,
            'shopping_category_ref' => 'category-produce',
        ]
        && $cookidooDetail['ingredients'][2]['provider']['optional'] === null
        && $cookidooDetail['ingredients'][3]['provider']['optional'] === true,
        'Detail ingredients must preserve order/repetition and never claim source-amount sufficiency'
    );
    recipeTestAssert(
        array_column($cookidooDetail['ingredient_groups'], 'index')
            === [0, 1]
        && array_column($cookidooDetail['ingredient_groups'], 'order')
            === [0, 1]
        && array_column($cookidooDetail['ingredient_groups'], 'label')
            === ['Sauce', 'Garnish']
        && array_column(
            $cookidooDetail['ingredient_groups'],
            'ingredient_positions'
        ) === [[0, 1, 2], [3, 4]]
        && !array_filter(
            $cookidooDetail['ingredient_groups'],
            static fn(array $group): bool =>
                !preg_match(
                    '/^rig:\\d+:[a-f0-9]{16}$/',
                    (string)$group['key']
                )
        ),
        'Cookidoo ingredient groups must expose stable named ordinal boundaries'
    );
    $feedbackStateBefore = recipeScoreState($db);
    $feedbackShoppingBefore = recipeTestCount(
        $db,
        'SELECT COUNT(*) FROM shopping_list'
    );
    $feedbackMissingIngredient =
        $cookidooDetail['ingredients'][1];
    $previewSettingBefore =
        $GLOBALS['RECIPE_SCORE_PREVIEW_REVISION_ID'];
    $GLOBALS['RECIPE_SCORE_PREVIEW_REVISION_ID'] = '999999';
    recipeScoreReadRevisionCacheClear();
    $activeFeedbackCurrent =
        recipeIngredientFeedbackCurrentIngredient($db, [
            'recipe_id' => $cookidooRecipeId,
            'ingredient_key' =>
                $feedbackMissingIngredient['key'],
            'position' =>
                $feedbackMissingIngredient['position'],
            'feedback_token' =>
                $feedbackMissingIngredient['feedback_token'],
        ]);
    $GLOBALS['RECIPE_SCORE_PREVIEW_REVISION_ID'] =
        $previewSettingBefore;
    recipeScoreReadRevisionCacheClear();
    recipeTestAssert(
        $activeFeedbackCurrent['detail']['revision']['preview']
            === false
        && $activeFeedbackCurrent['detail'][
            'preview_diagnostics'
        ] === [],
        'Ingredient feedback mutation validation must always use the true active score and ontology, never preview/read mode'
    );
    $overrideResult = recipeIngredientOverrideSet($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $feedbackMissingIngredient['key'],
        'position' => $feedbackMissingIngredient['position'],
        'availability' => 'have',
        'feedback_token' =>
            $feedbackMissingIngredient['feedback_token'],
        'idempotency_key' => 'feedback-override-1',
    ]);
    $overrideDetail = recipeCatalogDetail(
        $db,
        $cookidooRecipeId
    );
    $overrideReplay = recipeIngredientOverrideSet($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $feedbackMissingIngredient['key'],
        'position' => $feedbackMissingIngredient['position'],
        'availability' => 'have',
        'feedback_token' =>
            $feedbackMissingIngredient['feedback_token'],
        'idempotency_key' => 'feedback-override-1',
    ]);
    $overrideConflict = false;
    try {
        recipeIngredientOverrideSet($db, [
            'recipe_id' => $cookidooRecipeId,
            'ingredient_key' =>
                $feedbackMissingIngredient['key'],
            'position' =>
                $feedbackMissingIngredient['position'],
            'availability' => 'missing',
            'feedback_token' =>
                $feedbackMissingIngredient['feedback_token'],
            'idempotency_key' => 'feedback-override-1',
        ]);
    } catch (RecipeIngredientFeedbackConflictException $e) {
        $overrideConflict =
            $e->getMessage() === 'idempotency_key_conflict';
    }
    recipeTestAssert(
        $overrideResult['availability'] === 'have'
        && !$overrideResult['replayed']
        && $overrideReplay['replayed']
        && $overrideConflict
        && $overrideDetail['ingredients'][1][
            'user_override'
        ]['availability'] === 'have'
        && $overrideDetail['ingredients'][1]['inventory']['state']
            === 'missing'
        && $overrideDetail['grocery'] ===
            $cookidooDetail['grocery']
        && recipeScoreState($db) === $feedbackStateBefore
        && recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM shopping_list'
        ) === $feedbackShoppingBefore,
        'Availability overrides must be idempotent display evidence without changing inventory, scores, or grocery truth'
    );
    $identityIngredient = $cookidooDetail['ingredients'][0];
    $identityResult = recipeIngredientIdentityFeedbackRecord(
        $db,
        [
            'recipe_id' => $cookidooRecipeId,
            'ingredient_key' => $identityIngredient['key'],
            'position' => $identityIngredient['position'],
            'verdict' => 'wrong',
            'target_kind' => 'closest_match',
            'feedback_token' =>
                $identityIngredient['feedback_token'],
            'idempotency_key' => 'feedback-identity-1',
        ]
    );
    $identityDetail = recipeCatalogDetail(
        $db,
        $cookidooRecipeId
    );
    recipeTestAssert(
        $identityResult['verdict'] === 'wrong'
        && $identityResult['settle_days'] === 14
        && $identityDetail['ingredients'][0][
            'identity_feedback'
        ]['verdict'] === 'wrong'
        && recipeTestCount(
            $db,
            "SELECT COUNT(*)
             FROM recipe_ingredient_feedback_events
             WHERE review_state = 'settling'
               AND settle_after > CURRENT_TIMESTAMP"
        ) === 2
        && recipeScoreState($db) === $feedbackStateBefore,
        'Explicit identity feedback must remain append-only settling evidence and never mutate ontology or score state'
    );
    $db->prepare("
        UPDATE recipe_ingredient_feedback_events
        SET target_label = 'Different identity'
        WHERE idempotency_key = 'feedback-identity-1'
    ")->execute();
    recipeTestAssert(
        recipeCatalogDetail(
            $db,
            $cookidooRecipeId
        )['ingredients'][0]['identity_feedback'] === null,
        'Identity feedback must not project onto a different current target'
    );
    $db->prepare("
        UPDATE recipe_ingredient_feedback_events
        SET target_label = ?
        WHERE idempotency_key = 'feedback-identity-1'
    ")->execute([
        $identityIngredient['closest_match']['label'],
    ]);
    $selectedProductId = (int)(
        $identityIngredient['inventory']['matched_product']['id']
            ?? 0
    );
    recipeTestAssert(
        $selectedProductId > 0,
        'Ingredient decision tests require one exact stocked product'
    );
    $decisionEventsBefore = recipeTestCount(
        $db,
        'SELECT COUNT(*) FROM recipe_ingredient_feedback_events'
    );
    $decisionOutboxBefore = recipeTestCount(
        $db,
        'SELECT COUNT(*) FROM recipe_ingredient_proposal_outbox'
    );
    $GLOBALS['ONTOLOGY_AUTONOMOUS_ENABLED_OVERRIDE'] = true;
    $selectDecision = recipeIngredientDecision($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $feedbackMissingIngredient['key'],
        'position' => $feedbackMissingIngredient['position'],
        'action' => 'select_inventory_product',
        'selected_product_id' => $selectedProductId,
        'feedback_token' =>
            $feedbackMissingIngredient['feedback_token'],
        'idempotency_key' => 'feedback-v2-select-1',
        'action_origin' => 'react_dashboard',
    ]);
    $selectReplay = recipeIngredientDecision($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $feedbackMissingIngredient['key'],
        'position' => $feedbackMissingIngredient['position'],
        'action' => 'select_inventory_product',
        'selected_product_id' => $selectedProductId,
        'feedback_token' =>
            $feedbackMissingIngredient['feedback_token'],
        'idempotency_key' => 'feedback-v2-select-1',
        'action_origin' => 'react_dashboard',
    ]);
    $selectDetail = recipeCatalogDetail(
        $db,
        $cookidooRecipeId
    );
    $selectEvent = $db->query("
        SELECT *
        FROM recipe_ingredient_feedback_events
        WHERE idempotency_key = 'feedback-v2-select-1'
    ")->fetch(PDO::FETCH_ASSOC);
    $selectOutbox = $db->query("
        SELECT *
        FROM recipe_ingredient_proposal_outbox
        WHERE feedback_event_id = " . (int)$selectEvent['id']
    )->fetch(PDO::FETCH_ASSOC);
    recipeTestAssert(
        $selectDecision['identity_evidence'] === true
        && $selectDecision['proposal_enqueued'] === true
        && $selectDecision['availability'] === 'have'
        && $selectReplay['replayed'] === true
        && $selectDetail['ingredients'][1]['user_override'][
            'selected_product'
        ]['id'] === $selectedProductId
        && $selectEvent['decision_action']
            === 'select_inventory_product'
        && $selectEvent['target_kind'] === 'inventory_product'
        && $selectEvent['review_state'] === 'eligible'
        && $selectEvent['source_fingerprint_v2'] !== null
        && strlen((string)$selectEvent[
            'target_owner_fingerprint'
        ]) === 64
        && (int)$selectEvent['observed_inventory_revision']
            === (int)$feedbackStateBefore['inventory_revision']
        && (int)$selectEvent['observed_catalog_revision']
            === (int)$feedbackStateBefore['catalog_revision']
        && $selectDetail['ingredients'][1][
            'identity_feedback'
        ]['target_kind'] === 'inventory_product'
        && $selectOutbox['status'] === 'queued'
        && recipeTestCount(
            $db,
            'SELECT COUNT(*)
             FROM recipe_ingredient_feedback_regression_fixtures
             WHERE feedback_event_id = '
                . (int)$selectEvent['id']
                . " AND polarity = 'positive'"
        ) === 1,
        'Positive ingredient decisions must atomically persist the display override, immutable product-bound evidence, outbox row, and candidate regression fixture'
    );
    $proposalLeaseToken = hash('sha256', 'proposal-state-link-test');
    $db->prepare("
        UPDATE recipe_ingredient_proposal_outbox
        SET status = 'processing',
            lease_token = ?,
            lease_generation = lease_generation + 1,
            lease_expires_at = datetime('now', '+10 minutes')
        WHERE id = ?
    ")->execute([
        $proposalLeaseToken,
        (int)$selectOutbox['id'],
    ]);
    $proposalLeaseGeneration = (int)$db->query("
        SELECT lease_generation
        FROM recipe_ingredient_proposal_outbox
        WHERE id = " . (int)$selectOutbox['id']
    )->fetchColumn();
    $proposalStateLinked = recipeIngredientProposalSetState(
        $db,
        (int)$selectOutbox['id'],
        'retry',
        'synthetic_retry',
        'Synthetic successful-link probe.',
        null,
        null,
        $proposalLeaseToken,
        $proposalLeaseGeneration
    );
    $db->prepare("
        UPDATE recipe_ingredient_proposal_outbox
        SET status = 'superseded'
        WHERE id = ?
    ")->execute([(int)$selectOutbox['id']]);
    $proposalStateLostRace = recipeIngredientProposalSetState(
        $db,
        (int)$selectOutbox['id'],
        'staged',
        null,
        '',
        null,
        null,
        $proposalLeaseToken,
        $proposalLeaseGeneration
    );
    $newProposalLeaseToken = hash(
        'sha256',
        'proposal-state-link-new-worker'
    );
    $db->prepare("
        UPDATE recipe_ingredient_proposal_outbox
        SET status = 'processing',
            lease_token = ?,
            lease_generation = lease_generation + 1,
            lease_expires_at = datetime('now', '+10 minutes')
        WHERE id = ?
    ")->execute([
        $newProposalLeaseToken,
        (int)$selectOutbox['id'],
    ]);
    $staleProposalWorkerRejected =
        !recipeIngredientProposalSetState(
            $db,
            (int)$selectOutbox['id'],
            'staged',
            null,
            '',
            null,
            null,
            $proposalLeaseToken,
            $proposalLeaseGeneration
        );
    $db->prepare("
        UPDATE recipe_ingredient_proposal_outbox
        SET status = 'queued',
            next_attempt_at = NULL,
            lease_token = NULL,
            lease_expires_at = NULL,
            last_error_kind = NULL,
            last_error = ''
        WHERE id = ?
    ")->execute([(int)$selectOutbox['id']]);
    recipeTestAssert(
        $proposalStateLinked
        && !$proposalStateLostRace
        && $staleProposalWorkerRejected,
        'Proposal state linking must distinguish a live processing row from a superseded race loser'
    );
    $db->prepare("
        INSERT INTO ingredient_ontology_versions (
            version, status, schema_hash, prompt_hash,
            model_hash, model_name, corpus_hash, content_hash
        )
        VALUES (?, 'building', ?, ?, ?, ?, ?, ?)
    ")->execute([
        'feedback-worker-test',
        str_repeat('1', 64),
        str_repeat('2', 64),
        ingredientOntologyV3ModelHash(
            ingredientOntologyV3ConfiguredProposalModel()
        ),
        ingredientOntologyV3ConfiguredProposalModel(),
        str_repeat('3', 64),
        str_repeat('4', 64),
    ]);
    $feedbackWorkerVersionId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO ingredient_ontology_entities (
            ontology_version_id, local_key, slug,
            canonical_name, entity_kind, active, provenance
        )
        VALUES (?, 'feedback-target', 'feedback-target',
                'Feedback Target', 'ingredient', 1, 'test')
    ")->execute([$feedbackWorkerVersionId]);
    $selectOutboxInput = json_decode(
        (string)$selectOutbox['input_json'],
        true
    );
    $selectOutboxInput['ontology_version_id'] =
        $feedbackWorkerVersionId;
    $db->prepare("
        UPDATE recipe_ingredient_proposal_outbox
        SET input_json = ?
        WHERE id = ?
    ")->execute([
        recipeCatalogJsonEncode($selectOutboxInput),
        (int)$selectOutbox['id'],
    ]);
    $proposalBlocked = recipeIngredientProposalProcessQueue(
        $db,
        1
    );
    $selectOutboxState = $db->query("
        SELECT status, last_error_kind
        FROM recipe_ingredient_proposal_outbox
        WHERE id = " . (int)$selectOutbox['id']
    )->fetch(PDO::FETCH_ASSOC);
    recipeTestAssert(
        $proposalBlocked['claimed'] === 1
        && $selectOutboxState['status'] === 'blocked'
        && $selectOutboxState['last_error_kind']
            === 'gemini_api_key_unavailable'
        && recipeTestCount(
            $db,
            'SELECT COUNT(*)
             FROM recipe_ingredient_proposal_prompts
             WHERE outbox_id = ' . (int)$selectOutbox['id']
                . ' AND model_name = '
                . $db->quote(
                    ingredientOntologyV3ConfiguredProposalModel()
                )
        ) === 1,
        'Proposal intake must persist the frozen prompt/manifest and remain durably blocked without a runtime Gemini key, with no fallback model'
    );
    $handoff = recipeIngredientProposalExportPackages($db, 1);
    recipeTestAssert(
        $handoff['runtime_model_calls'] === false
        && $handoff['operator_or_copilot_handoff_required'] === true
        && count($handoff['packages']) === 1
        && $handoff['packages'][0]['outbox_id']
            === (int)$selectOutbox['id']
        && $handoff['packages'][0]['model']
            === ingredientOntologyV3ConfiguredProposalModel(),
        'The proposal worker must expose an immutable operator/Copilot artifact handoff without silently calling or substituting a model'
    );
    recipeIngredientOverrideSet($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $feedbackMissingIngredient['key'],
        'position' => $feedbackMissingIngredient['position'],
        'availability' => 'missing',
        'feedback_token' =>
            $feedbackMissingIngredient['feedback_token'],
        'idempotency_key' =>
            'feedback-v1-after-v2-selection-1',
    ]);
    $legacyOverrideAfterDecision = $db->query("
        SELECT selected_product_id,
               selected_product_fingerprint,
               decision_action, action_origin,
               observed_inventory_revision,
               observed_catalog_revision
        FROM recipe_ingredient_user_overrides
        WHERE recipe_id = {$cookidooRecipeId}
          AND ingredient_key = "
            . $db->quote($feedbackMissingIngredient['key'])
    )->fetch(PDO::FETCH_ASSOC);
    recipeTestAssert(
        $legacyOverrideAfterDecision['selected_product_id'] === null
        && $legacyOverrideAfterDecision[
            'selected_product_fingerprint'
        ] === null
        && $legacyOverrideAfterDecision['decision_action'] === null
        && $legacyOverrideAfterDecision['action_origin'] === null
        && (int)$legacyOverrideAfterDecision[
            'observed_inventory_revision'
        ] === (int)$feedbackStateBefore['inventory_revision']
        && (int)$legacyOverrideAfterDecision[
            'observed_catalog_revision'
        ] === (int)$feedbackStateBefore['catalog_revision']
        && recipeCatalogDetail(
            $db,
            $cookidooRecipeId
        )['ingredients'][1]['user_override'][
            'selected_product'
        ] === null,
        'Legacy availability overrides must clear v2 product and action provenance rather than retaining a revoked ontology target'
    );
    $feedbackPrompt = $db->query("
        SELECT *
        FROM recipe_ingredient_proposal_prompts
        WHERE outbox_id = " . (int)$selectOutbox['id']
    )->fetch(PDO::FETCH_ASSOC);
    $feedbackManifest = json_decode(
        (string)$feedbackPrompt['manifest_json'],
        true
    );
    $db->prepare("
        INSERT INTO ingredient_ontology_change_sets (
            ontology_version_id, change_set_key, input_hash,
            prompt_hash, model_hash, schema_hash, model_name,
            raw_model_json, validator_result_json, review_state
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, '{}', ?, 'pending')
    ")->execute([
        $feedbackWorkerVersionId,
        'feedback-supersede-test',
        $feedbackManifest['input_hash'],
        $feedbackManifest['prompt_hash'],
        $feedbackManifest['model_hash'],
        $feedbackManifest['schema_hash'],
        $feedbackManifest['model'],
        recipeCatalogJsonEncode(['valid' => true]),
    ]);
    $feedbackChangeSetId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO ingredient_ontology_proposals (
            change_set_id, input_id, decision,
            normalized_json, raw_json, validator_result_json,
            merge_key, review_state
        )
        VALUES (?, 'feedback_supersede', 'reject',
                '{}', '{}', '{}', ?, 'pending')
    ")->execute([
        $feedbackChangeSetId,
        str_repeat('5', 64),
    ]);
    $feedbackProposalId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO recipe_ingredient_proposal_responses (
            prompt_artifact_id, feedback_event_id, source,
            raw_response_json, response_hash,
            validation_json, change_set_id
        )
        VALUES (?, ?, 'operator_import', '{}', ?, ?, ?)
    ")->execute([
        (int)$feedbackPrompt['id'],
        (int)$selectEvent['id'],
        hash('sha256', '{}'),
        recipeCatalogJsonEncode(['valid' => true]),
        $feedbackChangeSetId,
    ]);
    $feedbackResponseId = (int)$db->lastInsertId();
    $db->prepare("
        UPDATE recipe_ingredient_proposal_outbox
        SET status = 'staged', response_artifact_id = ?
        WHERE id = ?
    ")->execute([
        $feedbackResponseId,
        (int)$selectOutbox['id'],
    ]);
    $assumeDecision = recipeIngredientDecision($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $feedbackMissingIngredient['key'],
        'position' => $feedbackMissingIngredient['position'],
        'action' => 'assume_have',
        'feedback_token' =>
            $feedbackMissingIngredient['feedback_token'],
        'idempotency_key' => 'feedback-v2-assume-1',
        'action_origin' => 'react_dashboard',
    ]);
    $supersededOutbox = $db->query("
        SELECT status
        FROM recipe_ingredient_proposal_outbox
        WHERE id = " . (int)$selectOutbox['id']
    )->fetchColumn();
    recipeTestAssert(
        $assumeDecision['identity_evidence'] === false
        && $assumeDecision['proposal_enqueued'] === false
        && (int)$db->query("
            SELECT supersedes_event_id
            FROM recipe_ingredient_feedback_events
            WHERE idempotency_key = 'feedback-v2-assume-1'
        ")->fetchColumn() === (int)$selectEvent['id']
        && $supersededOutbox === 'superseded'
        && $db->query("
            SELECT review_state
            FROM ingredient_ontology_change_sets
            WHERE id = {$feedbackChangeSetId}
        ")->fetchColumn() === 'rejected'
        && $db->query("
            SELECT review_state
            FROM ingredient_ontology_proposals
            WHERE id = {$feedbackProposalId}
        ")->fetchColumn() === 'rejected'
        && $db->query("
            SELECT status
            FROM recipe_ingredient_feedback_regression_fixtures
            WHERE feedback_event_id = " . (int)$selectEvent['id']
        )->fetchColumn() === 'rejected'
        && recipeCatalogDetail(
            $db,
            $cookidooRecipeId
        )['ingredients'][1]['identity_feedback'] === null
        && recipeTestCount(
            $db,
            "SELECT COUNT(*)
             FROM recipe_ingredient_proposal_outbox
             WHERE feedback_event_id IN (
                 SELECT id
                 FROM recipe_ingredient_feedback_events
                 WHERE idempotency_key = 'feedback-v2-assume-1'
             )"
        ) === 0,
        'Assume-have must remain availability-only and deterministically supersede any prior provisional proposal'
    );
    $db->prepare("
        INSERT INTO ingredient_ontology_change_sets (
            ontology_version_id, change_set_key, input_hash,
            prompt_hash, model_hash, schema_hash, model_name,
            raw_model_json, validator_result_json, review_state
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, '{}', ?, 'pending')
    ")->execute([
        $feedbackWorkerVersionId,
        'feedback-detached-stage-test',
        $feedbackManifest['input_hash'],
        $feedbackManifest['prompt_hash'],
        $feedbackManifest['model_hash'],
        $feedbackManifest['schema_hash'],
        $feedbackManifest['model'],
        recipeCatalogJsonEncode(['valid' => true]),
    ]);
    $detachedChangeSetId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO ingredient_ontology_proposals (
            change_set_id, input_id, decision,
            normalized_json, raw_json, validator_result_json,
            merge_key, review_state
        )
        VALUES (?, 'feedback_detached', 'reject',
                '{}', '{}', '{}', ?, 'pending')
    ")->execute([
        $detachedChangeSetId,
        str_repeat('7', 64),
    ]);
    recipeIngredientProposalRejectDetachedStage($db, [
        'stage' => ['change_set_id' => $detachedChangeSetId],
    ]);
    recipeTestAssert(
        $db->query("
            SELECT review_state
            FROM ingredient_ontology_change_sets
            WHERE id = {$detachedChangeSetId}
        ")->fetchColumn() === 'rejected'
        && recipeTestCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_proposals
             WHERE change_set_id = {$detachedChangeSetId}
               AND review_state = 'rejected'"
        ) === 1,
        'A staged proposal that loses the outbox-link race must be rejected immediately'
    );
    $rejectDecision = recipeIngredientDecision($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' => $identityIngredient['key'],
        'position' => $identityIngredient['position'],
        'action' => 'reject_current_match',
        'expected_target_product_id' => $selectedProductId,
        'feedback_token' =>
            $identityIngredient['feedback_token'],
        'idempotency_key' => 'feedback-v2-reject-1',
        'action_origin' => 'react_dashboard',
    ]);
    $rejectEvent = $db->query("
        SELECT *
        FROM recipe_ingredient_feedback_events
        WHERE idempotency_key = 'feedback-v2-reject-1'
    ")->fetch(PDO::FETCH_ASSOC);
    $rejectOutboxId = (int)$db->query("
        SELECT id
        FROM recipe_ingredient_proposal_outbox
        WHERE feedback_event_id = " . (int)$rejectEvent['id']
    )->fetchColumn();
    $settlingHandoff =
        recipeIngredientProposalExportPackages($db, 100);
    $settlingHandoffIds = array_map(
        static fn(array $package): int =>
            (int)($package['outbox_id'] ?? 0),
        $settlingHandoff['packages']
    );
    $settlingImportBlocked = false;
    try {
        recipeIngredientProposalImportPackage($db, [
            'schema_version' =>
                'recipe_ingredient_proposal_handoff_result_v1',
            'outbox_id' => $rejectOutboxId,
        ]);
    } catch (InvalidArgumentException $e) {
        $settlingImportBlocked =
            $e->getMessage()
                === 'proposal feedback is still settling';
    }
    recipeTestAssert(
        $rejectDecision['availability'] === 'missing'
        && $rejectDecision['identity_evidence'] === true
        && $rejectDecision['proposal_enqueued'] === true
        && $rejectEvent['identity_verdict'] === 'wrong'
        && (int)$rejectEvent['target_product_id']
            === $selectedProductId
        && $rejectEvent['review_state'] === 'settling'
        && strtotime((string)$rejectEvent['settle_after'])
            >= time() + (47 * 3600)
        && recipeTestCount(
            $db,
            'SELECT COUNT(*)
             FROM recipe_ingredient_feedback_regression_fixtures
             WHERE feedback_event_id = '
                . (int)$rejectEvent['id']
                . " AND polarity = 'negative'"
        ) === 1,
        'Negative exact-match decisions must be product-bound, immediately queued, and remain provisional for 48 hours'
    );
    recipeTestAssert(
        $rejectOutboxId > 0
        && !in_array(
            $rejectOutboxId,
            $settlingHandoffIds,
            true
        )
        && $settlingImportBlocked,
        'Negative proposal handoff export and import must enforce the same 48-hour settlement gate as the runtime worker'
    );
    $rejectOutbox = $db->query("
        SELECT * FROM recipe_ingredient_proposal_outbox
        WHERE id = {$rejectOutboxId}
    ")->fetch(PDO::FETCH_ASSOC);
    ingredientOntologyV3WithPublicationGuard(
        $db,
        static function () use (
            $db,
            $feedbackWorkerVersionId
        ): void {
            $db->prepare("
                UPDATE ingredient_ontology_versions
                SET status = 'ready', ready_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$feedbackWorkerVersionId]);
        }
    );
    $proposalBaseVersionId = $feedbackWorkerVersionId;
    $rejectPromptInput = json_decode(
        (string)$rejectOutbox['input_json'],
        true
    );
    $rejectPromptInput['ontology_version_id'] =
        $proposalBaseVersionId;
    $rejectOutbox['input_json'] =
        recipeCatalogJsonEncode($rejectPromptInput);
    $db->prepare("
        UPDATE recipe_ingredient_proposal_outbox
        SET input_json = ?
        WHERE id = ?
    ")->execute([
        $rejectOutbox['input_json'],
        $rejectOutboxId,
    ]);
    unset($GLOBALS['ONTOLOGY_AUTONOMOUS_ENABLED_OVERRIDE']);
    $manualProposalPrompt =
        recipeIngredientProposalEnsurePrompt(
            $db,
            $rejectOutbox
        );
    $manualProposalVersion = ingredientOntologyV3Version(
        $db,
        (int)$manualProposalPrompt['ontology_version_id']
    );
    recipeTestAssert(
        $manualProposalVersion['controller_activation_policy']
            === 'manual',
        'Default-off legacy proposal prompts must fork manual-policy children'
    );
    $GLOBALS['ONTOLOGY_AUTONOMOUS_ENABLED_OVERRIDE'] = true;
    $detailAfterReject = recipeCatalogDetail(
        $db,
        $cookidooRecipeId
    );
    $reselectIngredient = null;
    foreach ($detailAfterReject['ingredients'] as $candidate) {
        if (
            (string)$candidate['key']
                === (string)$identityIngredient['key']
        ) {
            $reselectIngredient = $candidate;
            break;
        }
    }
    $reselectDecision = recipeIngredientDecision($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' => $reselectIngredient['key'],
        'position' => $reselectIngredient['position'],
        'action' => 'select_inventory_product',
        'selected_product_id' => $selectedProductId,
        'feedback_token' => $reselectIngredient['feedback_token'],
        'idempotency_key' => 'feedback-v2-reselect-same-1',
        'action_origin' => 'react_dashboard',
    ]);
    $reselectEventId = (int)$db->query("
        SELECT id FROM recipe_ingredient_feedback_events
        WHERE idempotency_key = 'feedback-v2-reselect-same-1'
    ")->fetchColumn();
    $reselectOutbox = $db->query("
        SELECT * FROM recipe_ingredient_proposal_outbox
        WHERE feedback_event_id = {$reselectEventId}
    ")->fetch(PDO::FETCH_ASSOC);
    $reselectPromptInput = json_decode(
        (string)$reselectOutbox['input_json'],
        true
    );
    $reselectPromptInput['ontology_version_id'] =
        $proposalBaseVersionId;
    $reselectOutbox['input_json'] =
        recipeCatalogJsonEncode($reselectPromptInput);
    $db->prepare("
        UPDATE recipe_ingredient_proposal_outbox
        SET input_json = ?
        WHERE id = ?
    ")->execute([
        $reselectOutbox['input_json'],
        (int)$reselectOutbox['id'],
    ]);
    $GLOBALS['ONTOLOGY_AUTONOMOUS_ENABLED_OVERRIDE'] = true;
    $autonomousProposalPrompt =
        recipeIngredientProposalEnsurePrompt(
            $db,
            $reselectOutbox
        );
    unset($GLOBALS['ONTOLOGY_AUTONOMOUS_ENABLED_OVERRIDE']);
    $autonomousProposalVersion = ingredientOntologyV3Version(
        $db,
        (int)$autonomousProposalPrompt['ontology_version_id']
    );
    recipeTestAssert(
        $autonomousProposalVersion['controller_activation_policy']
            === 'autonomous',
        'Controller-enabled proposal prompts must join autonomous-policy children'
    );
    $reselectConstraint = $db->prepare("
        SELECT constraint_kind, constraint_epoch
        FROM ontology_constraint_ledger
        WHERE id = ?
    ");
    $reselectConstraint->execute([
        (int)$reselectDecision['constraint_id'],
    ]);
    $reselectConstraint = $reselectConstraint->fetch(PDO::FETCH_ASSOC);
    recipeTestAssert(
        $reselectDecision['availability'] === 'have'
        && $reselectDecision['selected_product']['id']
            === $selectedProductId
        && $reselectDecision['constraint_epoch']
            > (int)$rejectDecision['constraint_epoch']
        && $reselectConstraint['constraint_kind'] === 'must_equal'
        && $db->query("
            SELECT status
            FROM recipe_ingredient_proposal_outbox
            WHERE id = {$rejectOutboxId}
        ")->fetchColumn() === 'superseded'
        && recipeTestCount(
            $db,
            "SELECT COUNT(*)
             FROM ontology_constraint_ledger
             WHERE stream_key = "
                . $db->quote(
                    ingredientOntologyControllerStreamKey(
                        $cookidooRecipeId,
                        (string)$identityIngredient['key']
                    )
                )
                . " AND active = 1"
        ) === 1,
        'Same-product reselect must immediately supersede the negative and install one latest positive constraint'
    );
    unset($GLOBALS['ONTOLOGY_AUTONOMOUS_ENABLED_OVERRIDE']);
    $availabilityOnlyIngredient =
        $cookidooDetail['ingredients'][4];
    $outboxBeforeAvailabilityOnly = recipeTestCount(
        $db,
        'SELECT COUNT(*) FROM recipe_ingredient_proposal_outbox'
    );
    $availabilityOnlyReject = recipeIngredientDecision($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $availabilityOnlyIngredient['key'],
        'position' => $availabilityOnlyIngredient['position'],
        'action' => 'reject_current_match',
        'feedback_token' =>
            $availabilityOnlyIngredient['feedback_token'],
        'idempotency_key' =>
            'feedback-v2-reject-no-target-1',
        'action_origin' => 'react_dashboard',
    ]);
    recipeTestAssert(
        $availabilityOnlyReject['identity_evidence'] === false
        && $availabilityOnlyReject['proposal_enqueued'] === false
        && recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM recipe_ingredient_proposal_outbox'
        ) === $outboxBeforeAvailabilityOnly,
        'Rejecting without an exact displayed target must change availability only and never fabricate identity evidence'
    );
    $staleWritesBefore = [
        'events' => recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM recipe_ingredient_feedback_events'
        ),
        'outbox' => recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM recipe_ingredient_proposal_outbox'
        ),
    ];
    $staleDecisionRejected = false;
    try {
        recipeIngredientDecision($db, [
            'recipe_id' => $cookidooRecipeId,
            'ingredient_key' => $identityIngredient['key'],
            'position' => $identityIngredient['position'],
            'action' => 'reject_current_match',
            'expected_target_product_id' => 999999,
            'feedback_token' =>
                $identityIngredient['feedback_token'],
            'idempotency_key' => 'feedback-v2-stale-1',
            'action_origin' => 'react_dashboard',
        ]);
    } catch (RecipeIngredientFeedbackConflictException $e) {
        $staleDecisionRejected =
            $e->getMessage() === 'ingredient_feedback_stale';
    }
    $noStockProduct = $db->prepare("
        INSERT INTO products (
            name, brand, category, prepared_food
        )
        VALUES ('No Stock Decision Product', '', '', 0)
    ");
    $noStockProduct->execute();
    $noStockProductId = (int)$db->lastInsertId();
    $noStockRejected = false;
    try {
        recipeIngredientDecision($db, [
            'recipe_id' => $cookidooRecipeId,
            'ingredient_key' =>
                $feedbackMissingIngredient['key'],
            'position' => $feedbackMissingIngredient['position'],
            'action' => 'select_inventory_product',
            'selected_product_id' => $noStockProductId,
            'feedback_token' =>
                $feedbackMissingIngredient['feedback_token'],
            'idempotency_key' => 'feedback-v2-no-stock-1',
            'action_origin' => 'react_dashboard',
        ]);
    } catch (RecipeIngredientFeedbackConflictException $e) {
        $noStockRejected =
            $e->getMessage() === 'ingredient_feedback_stale';
    }
    recipeTestAssert(
        $staleDecisionRejected
        && $noStockRejected
        && recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM recipe_ingredient_feedback_events'
        ) === $staleWritesBefore['events']
        && recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM recipe_ingredient_proposal_outbox'
        ) === $staleWritesBefore['outbox'],
        'Submit-time target and positive-stock drift must return stale with no partial writes'
    );
    $underivableRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Underivable Controller Subject Fixture',
        'ingredients' => [['name' => 'Placeholder Ingredient']],
        'steps' => ['Use it.'],
    ], [
        'connector' => 'manual',
        'external_id' => 'underivable-controller-subject',
    ]);
    $underivableRecipeId = (int)$underivableRecipe['id'];
    $db->prepare("
        UPDATE recipe_ingredients
        SET raw_text = '---', normalized_name = ''
        WHERE recipe_id = ?
    ")->execute([$underivableRecipeId]);
    $underivableDetail = recipeCatalogDetail(
        $db,
        $underivableRecipeId
    );
    $underivableIngredient = $underivableDetail['ingredients'][0];
    $underivableBefore = [
        'events' => recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM recipe_ingredient_feedback_events'
        ),
        'outbox' => recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM recipe_ingredient_proposal_outbox'
        ),
        'jobs' => recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM ontology_controller_jobs'
        ),
        'epoch' => (int)$db->query("
            SELECT constraint_epoch
            FROM ontology_controller_state WHERE id = 1
        ")->fetchColumn(),
    ];
    $underivableDecision = recipeIngredientDecision($db, [
        'recipe_id' => $underivableRecipeId,
        'ingredient_key' => $underivableIngredient['key'],
        'position' => $underivableIngredient['position'],
        'action' => 'select_inventory_product',
        'selected_product_id' => $selectedProductId,
        'feedback_token' => $underivableIngredient['feedback_token'],
        'idempotency_key' =>
            'feedback-v2-underivable-controller-subject',
        'action_origin' => 'react_dashboard',
    ]);
    recipeTestAssert(
        $underivableDecision['availability'] === 'have'
        && $underivableDecision['proposal_enqueued'] === true
        && recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM recipe_ingredient_feedback_events'
        ) === $underivableBefore['events'] + 1
        && recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM recipe_ingredient_proposal_outbox'
        ) === $underivableBefore['outbox'] + 1
        && recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM ontology_controller_jobs'
        ) === $underivableBefore['jobs']
        && (int)$db->query("
            SELECT constraint_epoch
            FROM ontology_controller_state WHERE id = 1
        ")->fetchColumn() === $underivableBefore['epoch']
        && empty($underivableDecision['controller_degraded']),
        'Default-off ingredient decisions must preserve feedback without controller writes'
    );
    $degradedDecisionRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Controller Degraded Decision Fixture',
        'ingredients' => [['name' => 'Degraded decision ingredient']],
        'steps' => ['Use it.'],
    ], [
        'connector' => 'manual',
        'external_id' => 'controller-degraded-decision',
    ]);
    $degradedDecisionDetail = recipeCatalogDetail(
        $db,
        (int)$degradedDecisionRecipe['id']
    );
    $degradedDecisionIngredient =
        $degradedDecisionDetail['ingredients'][0];
    $degradedControllerBefore = [
        'subjects' => recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM ontology_subjects'
        ),
        'jobs' => recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM ontology_controller_jobs'
        ),
        'constraints' => recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM ontology_constraint_ledger'
        ),
    ];
    $db->exec("
        CREATE TRIGGER recipe_test_controller_degradation
        BEFORE INSERT ON ontology_observation_events
        BEGIN
            SELECT RAISE(
                ABORT,
                'controller degradation fixture'
            );
        END
    ");
    $GLOBALS['ONTOLOGY_AUTONOMOUS_ENABLED_OVERRIDE'] = true;
    $degradedDecision = recipeIngredientDecision($db, [
        'recipe_id' => (int)$degradedDecisionRecipe['id'],
        'ingredient_key' => $degradedDecisionIngredient['key'],
        'position' => $degradedDecisionIngredient['position'],
        'action' => 'select_inventory_product',
        'selected_product_id' => $selectedProductId,
        'feedback_token' =>
            $degradedDecisionIngredient['feedback_token'],
        'idempotency_key' =>
            'feedback-v2-controller-degraded',
        'action_origin' => 'react_dashboard',
    ]);
    unset($GLOBALS['ONTOLOGY_AUTONOMOUS_ENABLED_OVERRIDE']);
    $db->exec('DROP TRIGGER recipe_test_controller_degradation');
    recipeTestAssert(
        $degradedDecision['availability'] === 'have'
        && !empty($degradedDecision['controller_degraded'])
        && recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM ontology_subjects'
        ) === $degradedControllerBefore['subjects']
        && recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM ontology_controller_jobs'
        ) === $degradedControllerBefore['jobs']
        && recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM ontology_constraint_ledger'
        ) === $degradedControllerBefore['constraints'],
        'Enabled controller degradation must never fail or partially write an ingredient decision'
    );
    $cookidooDetail = recipeCatalogDetail($db, $cookidooRecipeId);
    $feedbackMissingIngredient = $cookidooDetail['ingredients'][1];
    $availabilityOnlyIngredient = $cookidooDetail['ingredients'][4];
    recipeIngredientOverrideSet($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $feedbackMissingIngredient['key'],
        'position' => $feedbackMissingIngredient['position'],
        'availability' => 'clear',
        'feedback_token' =>
            $feedbackMissingIngredient['feedback_token'],
        'idempotency_key' => 'feedback-override-clear-1',
    ]);
    recipeTestAssert(
        recipeCatalogDetail(
            $db,
            $cookidooRecipeId
        )['ingredients'][1]['user_override'] === null,
        'Availability overrides must support an explicit reset to system truth'
    );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['RECIPE_API_JSON_INPUT'] = [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $feedbackMissingIngredient['key'],
        'position' => $feedbackMissingIngredient['position'],
        'availability' => 'missing',
        'feedback_token' =>
            $feedbackMissingIngredient['feedback_token'],
        'idempotency_key' => 'feedback-api-override-1',
    ];
    ob_start();
    recipeCatalogApiIngredientOverride($db);
    $feedbackApi = json_decode((string)ob_get_clean(), true);
    unset($GLOBALS['RECIPE_API_JSON_INPUT']);
    recipeTestAssert(
        http_response_code() === 200
        && ($feedbackApi['success'] ?? false) === true
        && ($feedbackApi['availability'] ?? null) === 'missing',
        'Ingredient feedback API must expose bounded availability overrides'
    );
    $GLOBALS['RECIPE_API_JSON_INPUT'] = [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $availabilityOnlyIngredient['key'],
        'position' => $availabilityOnlyIngredient['position'],
        'action' => 'assume_have',
        'feedback_token' =>
            $availabilityOnlyIngredient['feedback_token'],
        'idempotency_key' => 'feedback-v2-api-assume-1',
        'action_origin' => 'home_assistant',
    ];
    ob_start();
    recipeCatalogApiIngredientDecision($db);
    $decisionApi = json_decode((string)ob_get_clean(), true);
    unset($GLOBALS['RECIPE_API_JSON_INPUT']);
    recipeTestAssert(
        http_response_code() === 200
        && ($decisionApi['success'] ?? false) === true
        && ($decisionApi['action'] ?? null) === 'assume_have'
        && ($decisionApi['identity_evidence'] ?? true) === false,
        'Ingredient decision v2 API must expose one closed atomic action command'
    );
    recipeIngredientOverrideSet($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $availabilityOnlyIngredient['key'],
        'position' => $availabilityOnlyIngredient['position'],
        'availability' => 'clear',
        'feedback_token' =>
            $availabilityOnlyIngredient['feedback_token'],
        'idempotency_key' =>
            'feedback-v2-api-assume-clear-1',
    ]);
    recipeIngredientOverrideSet($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $feedbackMissingIngredient['key'],
        'position' => $feedbackMissingIngredient['position'],
        'availability' => 'clear',
        'feedback_token' =>
            $feedbackMissingIngredient['feedback_token'],
        'idempotency_key' => 'feedback-api-override-clear-1',
    ]);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $cookidooTopologyRows = recipeDetailLoadIngredients(
        $db,
        $cookidooRecipeId
    )['rows'];
    recipeTestAssert(
        array_column($cookidooTopologyRows, 'is_optional')
            === [false, false, false, true, true]
        && array_column($cookidooTopologyRows, 'is_required')
            === [true, true, true, false, false],
        'Provider optional true must be optional while false/null remain required'
    );
    $manualDetail = recipeCatalogDetail($db, (int)$manualCookidooUrl['id']);
    recipeTestAssert(
        $manualDetail !== null
        && $manualDetail['instructions']['available'] === true
        && $manualDetail['instructions']['steps']
            === ['Keep this operator-authored instruction.']
        && count($manualDetail['instructions']['groups']) === 1
        && $manualDetail['instructions']['groups'][0]['label']
            === 'Operator preparation'
        && $manualDetail['instructions']['groups'][0]['step_positions']
            === [0]
        && $manualDetail['ingredient_groups'][0]['label']
            === 'Local ingredients'
        && $manualDetail['capabilities']['instructions'] === 'local',
        'Authorized local/manual instructions must use the same detail DTO'
    );
    $allStockRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'All Stock Grocery Capability',
        'ingredients' => [['name' => 'Tomato Test']],
        'steps' => ['Use the tomato.'],
    ], ['connector' => 'manual', 'external_id' => 'all-stock-grocery-capability']);
    $allStockDetail = recipeCatalogDetail($db, (int)$allStockRecipe['id']);
    recipeTestAssert(
        $allStockDetail['capabilities']['grocery_add'] === true
        && $allStockDetail['grocery']['missing_count'] === 0
        && $allStockDetail['grocery']['in_stock_count'] === 1
        && $allStockDetail['grocery']['eligible_count'] === 0
        && $allStockDetail['grocery']['blocked_reason'] === null,
        'All-in-stock recipes must retain grocery feature capability with zero missing items'
    );
    $GLOBALS['RECIPE_GROCERY_BACKEND_SUPPORTED'] = false;
    $unsupportedGroceryDetail = recipeCatalogDetail(
        $db,
        (int)$allStockRecipe['id']
    );
    unset($GLOBALS['RECIPE_GROCERY_BACKEND_SUPPORTED']);
    recipeTestAssert(
        $unsupportedGroceryDetail['capabilities']['grocery_add'] === false
        && $unsupportedGroceryDetail['grocery']['blocked_reason'] === null
        && $unsupportedGroceryDetail['grocery']['in_stock_count'] === 1,
        'Unsupported grocery actions must be distinct from recipe-list blockers'
    );
    $noIngredientRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'No Ingredient Grocery Capability',
        'ingredients' => [],
        'steps' => ['No ingredients.'],
    ], ['connector' => 'manual', 'external_id' => 'no-ingredient-grocery-capability']);
    $noIngredientDetail = recipeCatalogDetail($db, (int)$noIngredientRecipe['id']);
    recipeTestAssert(
        $noIngredientDetail['capabilities']['grocery_add'] === false
        && $noIngredientDetail['grocery']['blocked_reason'] === 'no_ingredients'
        && $noIngredientDetail['grocery']['eligible_count'] === 0,
        'Recipes without ingredients must expose the no_ingredients blocker'
    );
    $broadMappings = [
        ['source' => 'olive tapenade', 'display' => 'Olive Tapenade', 'label' => 'Olives'],
        ['source' => 'cake flour', 'display' => 'Cake Flour', 'label' => 'Cake'],
        ['source' => 'almond extract', 'display' => 'Almond Extract', 'label' => 'Almonds'],
    ];
    $broadMappingIds = [];
    foreach ($broadMappings as $index => $mapping) {
        $slug = 'broad-display-test-' . $index;
        $canonicalInsert->execute([$slug, $mapping['label']]);
        $canonicalId = (int)$db->lastInsertId();
        $db->prepare("
            INSERT INTO taxonomy_nodes (tree_id, slug, name)
            VALUES (?, ?, ?)
        ")->execute([$treeId, $slug, $mapping['label']]);
        $nodeId = (int)$db->lastInsertId();
        $closure->execute([$treeId, $nodeId, $nodeId, 0]);
        $broadMappingIds[] = [$canonicalId, $nodeId];
    }
    $broadRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Broad Mapping Display Test',
        'ingredients' => array_map(
            static fn(array $mapping): array => ['name' => $mapping['source']],
            $broadMappings
        ),
        'steps' => ['Use the source-labelled ingredients.'],
    ], ['connector' => 'manual', 'external_id' => 'broad-mapping-display-test']);
    $forceBroadMapping = $db->prepare("
        UPDATE recipe_ingredients SET
            canonical_ingredient_id = ?,
            taxonomy_node_id = ?,
            mapping_confidence = 0.999,
            mapping_source = 'taxonomy_rule'
        WHERE recipe_id = ? AND position = ?
    ");
    foreach ($broadMappingIds as $position => [$canonicalId, $nodeId]) {
        $forceBroadMapping->execute([
            $canonicalId,
            $nodeId,
            (int)$broadRecipe['id'],
            $position,
        ]);
    }
    $broadDetail = recipeCatalogDetail($db, (int)$broadRecipe['id']);
    recipeTestAssert(
        array_column($broadDetail['ingredients'], 'source_text')
            === array_column($broadMappings, 'source')
        && array_column($broadDetail['ingredients'], 'display_name')
            === array_column($broadMappings, 'display')
        && array_column($broadDetail['ingredients'], 'name')
            === array_column($broadMappings, 'display')
        && count(array_filter(
            $broadDetail['ingredients'],
            static fn(array $ingredient): bool =>
                ($ingredient['mapping']['source'] ?? '') === 'taxonomy_rule'
                && !array_key_exists('closest_match', $ingredient)
        )) === count($broadMappings),
        'Taxonomy-rule confidence and broad labels must never replace source display names or emit closest_match'
    );
    $broadGrocery = recipeGroceryAddMissing($db, [
        'recipe_id' => (int)$broadRecipe['id'],
        'idempotency_key' => 'broad-mapping-display-grocery',
        'positions' => [0, 1, 2],
    ]);
    $broadShoppingRows = $db->query("
        SELECT name, canonical_key
        FROM shopping_list
        WHERE canonical_key IN (
            'name:olive tapenade',
            'name:cake flour',
            'name:almond extract'
        )
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);
    recipeTestAssert(
        array_column($broadGrocery['outcomes'], 'outcome') === [
            'added',
            'added',
            'added',
        ]
        && array_column($broadShoppingRows, 'name')
            === array_column($broadMappings, 'display')
        && array_column($broadShoppingRows, 'canonical_key') === [
            'name:olive tapenade',
            'name:cake flour',
            'name:almond extract',
        ],
        'Unsafe broad mappings must dedupe and name groceries by normalized source identity'
    );
    $canonicalInsert->execute([
        'amount-tomatoes-identity-test',
        'Tomatoes',
    ]);
    $amountTomatoesCanonical = (int)$db->lastInsertId();
    $canonicalInsert->execute([
        'amount-flour-identity-test',
        'Flour',
    ]);
    $amountFlourCanonical = (int)$db->lastInsertId();
    $amountIdentityNodeInsert = $db->prepare("
        INSERT INTO taxonomy_nodes (tree_id, slug, name)
        VALUES (?, ?, ?)
    ");
    $amountIdentityNodeInsert->execute([
        $treeId,
        'amount-tomatoes-identity-test',
        'Tomatoes',
    ]);
    $amountTomatoesNode = (int)$db->lastInsertId();
    $amountIdentityNodeInsert->execute([
        $treeId,
        'amount-flour-identity-test',
        'Flour',
    ]);
    $amountFlourNode = (int)$db->lastInsertId();
    $closure->execute([
        $treeId,
        $amountTomatoesNode,
        $amountTomatoesNode,
        0,
    ]);
    $closure->execute([
        $treeId,
        $amountFlourNode,
        $amountFlourNode,
        0,
    ]);
    $amountIdentityAliasInsert = $db->prepare("
        INSERT INTO taxonomy_aliases (
            tree_id, node_id, alias, normalized_alias, source
        )
        VALUES (?, ?, ?, ?, 'test')
    ");
    $amountIdentityAliasInsert->execute([
        $treeId,
        $amountTomatoesNode,
        'Tomatoes',
        'tomatoes',
    ]);
    $amountIdentityAliasInsert->execute([
        $treeId,
        $amountFlourNode,
        'Flour',
        'flour',
    ]);
    $productInsert->execute(['Tomatoes', 0]);
    $amountTomatoesProduct = (int)$db->lastInsertId();
    $productInsert->execute(['Flour', 0]);
    $amountFlourProduct = (int)$db->lastInsertId();
    $mappingInsert->execute([
        $amountTomatoesProduct,
        $amountTomatoesCanonical,
    ]);
    $mappingInsert->execute([
        $amountFlourProduct,
        $amountFlourCanonical,
    ]);
    $inventoryInsert->execute([
        $amountTomatoesProduct,
        date('Y-m-d', strtotime('+5 days')),
        0,
    ]);
    $inventoryInsert->execute([
        $amountFlourProduct,
        date('Y-m-d', strtotime('+5 days')),
        0,
    ]);

    $amountIdentityInputs = [
        '1 can tomatoes',
        '500 g flour',
        '2 - 3 cans tomatoes',
        '1/2 cup flour',
        '1 1/2 cups flour',
        '7 Up',
        '1000 Island dressing',
    ];
    $amountIdentityRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Amount Identity Ranking',
        'ingredients' => $amountIdentityInputs,
        'steps' => ['Use the listed ingredients.'],
    ], [
        'connector' => 'manual',
        'external_id' => 'amount-identity-ranking',
    ]);
    $amountIdentityRanking = $db->query("
        SELECT position, raw_text, normalized_name, quantity, quantity_text,
               unit, mapping_source
        FROM recipe_ingredients
        WHERE recipe_id = " . (int)$amountIdentityRecipe['id'] . "
        ORDER BY position
    ")->fetchAll(PDO::FETCH_ASSOC);
    $amountIdentityDetail = recipeCatalogDetail(
        $db,
        (int)$amountIdentityRecipe['id']
    );
    recipeTestAssert(
        array_column($amountIdentityRanking, 'raw_text')
            === $amountIdentityInputs
        && array_column($amountIdentityRanking, 'normalized_name') === [
            'tomatoes',
            'flour',
            'tomatoes',
            'flour',
            'flour',
            '7 up',
            '1000 island dressing',
        ]
        && array_slice(
            array_column($amountIdentityRanking, 'mapping_source'),
            0,
            5
        ) === array_fill(0, 5, 'taxonomy_alias')
        && !array_filter(
            $amountIdentityRanking,
            static fn(array $row): bool =>
                $row['quantity'] !== null
                || $row['quantity_text'] !== null
                || $row['unit'] !== null
        )
        && array_column(
            array_slice($amountIdentityDetail['ingredients'], 0, 5),
            'display_name'
        ) === ['Tomatoes', 'Flour', 'Tomatoes', 'Flour', 'Flour']
        && array_column(
            array_slice($amountIdentityDetail['ingredients'], 0, 5),
            'source_text'
        ) === array_slice($amountIdentityInputs, 0, 5)
        && array_column(
            array_slice($amountIdentityDetail['ingredients'], 0, 5),
            'closest_match'
        ) === [
            [
                'label' => 'Tomatoes',
                'canonical_ingredient_id' => $amountTomatoesCanonical,
                'taxonomy_node_id' => $amountTomatoesNode,
                'mapping_source' => 'taxonomy_alias',
                'confidence' => 0.98,
            ],
            [
                'label' => 'Flour',
                'canonical_ingredient_id' => $amountFlourCanonical,
                'taxonomy_node_id' => $amountFlourNode,
                'mapping_source' => 'taxonomy_alias',
                'confidence' => 0.98,
            ],
            [
                'label' => 'Tomatoes',
                'canonical_ingredient_id' => $amountTomatoesCanonical,
                'taxonomy_node_id' => $amountTomatoesNode,
                'mapping_source' => 'taxonomy_alias',
                'confidence' => 0.98,
            ],
            [
                'label' => 'Flour',
                'canonical_ingredient_id' => $amountFlourCanonical,
                'taxonomy_node_id' => $amountFlourNode,
                'mapping_source' => 'taxonomy_alias',
                'confidence' => 0.98,
            ],
            [
                'label' => 'Flour',
                'canonical_ingredient_id' => $amountFlourCanonical,
                'taxonomy_node_id' => $amountFlourNode,
                'mapping_source' => 'taxonomy_alias',
                'confidence' => 0.98,
            ],
        ]
        && array_column(
            array_column(
                array_slice($amountIdentityDetail['ingredients'], 0, 5),
                'inventory'
            ),
            'state'
        ) === array_fill(0, 5, 'in_stock'),
        'Legacy ranking identities must clean recognized amount/unit prefixes '
            . 'without changing provenance or ranking quantities'
    );

    $amountSourceInputs = [
        [
            'name' => '1 can tomatoes',
            'source_quantity' => 1,
            'source_quantity_max' => null,
            'source_unit' => 'can',
            'source_amount_text' => '1 can',
        ],
        [
            'name' => '2 - 3 cans tomatoes',
            'source_quantity' => 2,
            'source_quantity_max' => 3,
            'source_unit' => 'cans',
            'source_amount_text' => '2 - 3 cans',
        ],
        [
            'name' => '500 g flour',
            'source_quantity' => 500,
            'source_quantity_max' => null,
            'source_unit' => 'g',
            'source_amount_text' => '500 g',
        ],
        [
            'name' => '1/2 cup flour',
            'source_quantity' => 0.5,
            'source_quantity_max' => null,
            'source_unit' => 'cup',
            'source_amount_text' => '1/2 cup',
        ],
    ];
    $amountSourceRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Amount Identity Source',
        'source_ingredients' => $amountSourceInputs,
    ], [
        'connector' => 'cookidoo',
        'external_id' => 'amount-identity-source',
        'canonical_url' => (
            'https://cookidoo.co.uk/recipes/recipe/en-GB/'
            . 'amount-identity-source'
        ),
        'locale' => 'en-GB',
    ]);
    $amountSourceRows = $db->query("
        SELECT position, name, normalized_name, source_quantity,
               source_quantity_max, source_unit, source_amount_text,
               mapping_source
        FROM recipe_source_ingredients
        WHERE recipe_id = " . (int)$amountSourceRecipe['id'] . "
        ORDER BY position
    ")->fetchAll(PDO::FETCH_ASSOC);
    $amountSourceRanking = $db->query("
        SELECT raw_text, normalized_name, quantity, quantity_text, unit,
               mapping_source
        FROM recipe_ingredients
        WHERE recipe_id = " . (int)$amountSourceRecipe['id'] . "
        ORDER BY position
    ")->fetchAll(PDO::FETCH_ASSOC);
    $amountSourceDetail = recipeCatalogDetail(
        $db,
        (int)$amountSourceRecipe['id']
    );
    recipeTestAssert(
        array_column($amountSourceRows, 'name') === array_column(
            $amountSourceInputs,
            'name'
        )
        && array_column($amountSourceRows, 'normalized_name') === [
            'tomatoes',
            'tomatoes',
            'flour',
            'flour',
        ]
        && array_column($amountSourceRows, 'mapping_source')
            === array_fill(0, 4, 'taxonomy_alias')
        && count($amountSourceRanking) === 2
        && array_column($amountSourceRanking, 'raw_text') === [
            '500 g flour',
            '1 can tomatoes',
        ]
        && array_column($amountSourceRanking, 'normalized_name')
            === ['flour', 'tomatoes']
        && !array_filter(
            $amountSourceRanking,
            static fn(array $row): bool =>
                $row['quantity'] !== null
                || $row['quantity_text'] !== null
                || $row['unit'] !== null
        )
        && count($amountSourceDetail['ingredients']) === 4
        && array_column($amountSourceDetail['ingredients'], 'source_text')
            === array_column($amountSourceInputs, 'name')
        && array_column($amountSourceDetail['ingredients'], 'display_name')
            === ['Tomatoes', 'Tomatoes', 'Flour', 'Flour']
        && array_column(
            array_column($amountSourceDetail['ingredients'], 'inventory'),
            'state'
        ) === array_fill(0, 4, 'in_stock')
        && array_column(
            array_column($amountSourceDetail['ingredients'], 'inventory'),
            'quantity_state'
        ) === array_fill(0, 4, 'display_only')
        && array_column(
            array_column($amountSourceDetail['ingredients'], 'closest_match'),
            'label'
        ) === ['Tomatoes', 'Tomatoes', 'Flour', 'Flour'],
        'Source metadata identities must share amount-prefix cleanup while '
            . 'preserving ordered display-only facts'
    );
    $db->prepare("DELETE FROM recipe_catalog WHERE id IN (?, ?)")
        ->execute([
            (int)$amountIdentityRecipe['id'],
            (int)$amountSourceRecipe['id'],
        ]);
    $db->prepare("DELETE FROM products WHERE id IN (?, ?)")
        ->execute([$amountTomatoesProduct, $amountFlourProduct]);
    $db->prepare("DELETE FROM canonical_ingredients WHERE id IN (?, ?)")
        ->execute([$amountTomatoesCanonical, $amountFlourCanonical]);
    $db->prepare("DELETE FROM taxonomy_nodes WHERE id IN (?, ?)")
        ->execute([$amountTomatoesNode, $amountFlourNode]);

    $canonicalInsert->execute([
        'legacy-amount-prefix-test',
        'Legacy Amount Prefix Test',
    ]);
    $legacyAmountCanonical = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO taxonomy_nodes (tree_id, slug, name)
        VALUES (?, 'legacy-amount-prefix-test', 'Legacy Amount Prefix Test')
    ")->execute([$treeId]);
    $legacyAmountNode = (int)$db->lastInsertId();
    $closure->execute([
        $treeId,
        $legacyAmountNode,
        $legacyAmountNode,
        0,
    ]);
    $legacyAmountRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Legacy Amount Prefix Detail',
        'ingredients' => [[
            'name' => '1 can legacy amount tomatoes',
            'raw_text' => '1 can legacy amount tomatoes',
        ]],
        'steps' => ['Use the source-labelled ingredient.'],
    ], [
        'connector' => 'manual',
        'external_id' => 'legacy-amount-prefix-detail',
    ]);
    $forceBroadMapping->execute([
        $legacyAmountCanonical,
        $legacyAmountNode,
        (int)$legacyAmountRecipe['id'],
        0,
    ]);
    $legacyAmountDetail = recipeCatalogDetail(
        $db,
        (int)$legacyAmountRecipe['id']
    );
    $legacyAmountGrocery = recipeGroceryAddMissing($db, [
        'recipe_id' => (int)$legacyAmountRecipe['id'],
        'idempotency_key' => 'legacy-amount-prefix-grocery',
        'positions' => [0],
    ]);
    $legacyAmountShopping = $db->query("
        SELECT name, canonical_key
        FROM shopping_list
        WHERE canonical_key = 'name:legacy amount tomatoes'
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    $legacyAmountRanking = $db->query("
        SELECT quantity, quantity_text, unit
        FROM recipe_ingredients
        WHERE recipe_id = " . (int)$legacyAmountRecipe['id'] . "
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    recipeTestAssert(
        $legacyAmountDetail['ingredients'][0]['display_name']
            === 'Legacy Amount Tomatoes'
        && $legacyAmountDetail['ingredients'][0]['name']
            === 'Legacy Amount Tomatoes'
        && $legacyAmountDetail['ingredients'][0]['inventory']['state']
            === 'missing'
        && $legacyAmountRanking === [
            'quantity' => null,
            'quantity_text' => null,
            'unit' => null,
        ]
        && ($legacyAmountGrocery['outcomes'][0]['outcome'] ?? null) === 'added'
        && $legacyAmountShopping === [
            'name' => 'Legacy Amount Tomatoes',
            'canonical_key' => 'name:legacy amount tomatoes',
        ],
        'Legacy raw amount prefixes must clean detail, grocery names, and '
            . 'unsafe source-name dedupe without parsed quantity columns'
    );
    $stapleRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Staple Detail',
        'ingredients' => [['name' => 'Water']],
        'steps' => ['Use water.'],
    ], ['connector' => 'manual', 'external_id' => 'staple-detail']);
    recipeTestAssert(
        recipeCatalogDetail($db, (int)$stapleRecipe['id'])['ingredients'][0]['inventory']['state']
            === 'staple',
        'Detail inventory projection must preserve staple state'
    );
    $boundedDetailRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Bounded Detail',
        'ingredients' => array_fill(0, 201, ['name' => 'Bounded Ingredient']),
        'steps' => array_fill(0, 101, 'Bounded authorized step.'),
    ], ['connector' => 'manual', 'external_id' => 'bounded-detail']);
    $boundedDetail = recipeCatalogDetail($db, (int)$boundedDetailRecipe['id']);
    recipeTestAssert(
        count($boundedDetail['ingredients']) === 200
        && $boundedDetail['ingredients_truncated'] === true
        && $boundedDetail['capabilities']['grocery_add'] === false
        && $boundedDetail['grocery']['blocked_reason'] === 'ingredients_truncated'
        && count($boundedDetail['instructions']['steps']) === 100
        && $boundedDetail['instructions']['truncated'] === true,
        'One-recipe detail output must enforce ingredient and instruction bounds'
    );
    $truncatedGroceryRejected = false;
    try {
        recipeGroceryAddMissing($db, [
            'recipe_id' => (int)$boundedDetailRecipe['id'],
            'idempotency_key' => 'truncated-grocery-rejected',
            'positions' => [0],
        ]);
    } catch (InvalidArgumentException $e) {
        $truncatedGroceryRejected = $e->getMessage()
            === 'grocery_add_blocked_ingredients_truncated';
    }
    recipeTestAssert(
        $truncatedGroceryRejected,
        'Grocery mutation must reject incomplete truncated ingredient lists'
    );

    $inventoryOverflowRows = RECIPE_DETAIL_MAX_INVENTORY_ROWS + 2;
    $db->exec("
        WITH RECURSIVE sequence(value) AS (
            SELECT 1
            UNION ALL
            SELECT value + 1
            FROM sequence
            WHERE value < {$inventoryOverflowRows}
        )
        INSERT INTO inventory (
            product_id, location, quantity, expiry_date,
            prepared_food, vacuum_sealed, opened_at
        )
        SELECT {$openedProduct}, 'frigo', 1,
               date('now', '+100 days'), 0, 0, datetime('now', '-10 days')
        FROM sequence
    ");
    $canonicalInsert->execute(['inventory-limit-test', 'Inventory Limit Test']);
    $inventoryLimitCanonical = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO taxonomy_nodes (tree_id, slug, name)
        VALUES (?, 'inventory-limit-test', 'Inventory Limit Test')
    ")->execute([$treeId]);
    $inventoryLimitNode = (int)$db->lastInsertId();
    $closure->execute([$treeId, $inventoryLimitNode, $inventoryLimitNode, 0]);
    $productInsert->execute(['Inventory Limit Test', 0]);
    $inventoryLimitProduct = (int)$db->lastInsertId();
    $mappingInsert->execute([$inventoryLimitProduct, $inventoryLimitCanonical]);
    $db->prepare("
        INSERT INTO inventory (product_id, location, quantity, prepared_food)
        VALUES (?, 'dispensa', 1, 0)
    ")->execute([$inventoryLimitProduct]);
    $inventoryLimitRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Inventory Limit Detail',
        'ingredients' => [['name' => 'Inventory Limit Test']],
        'steps' => ['Use the ingredient.'],
    ], ['connector' => 'manual', 'external_id' => 'inventory-limit-detail']);
    $inventoryLimitDetail = recipeCatalogDetail($db, (int)$inventoryLimitRecipe['id']);
    $inventoryLimitGrocery = recipeGroceryAddMissing($db, [
        'recipe_id' => (int)$inventoryLimitRecipe['id'],
        'idempotency_key' => 'metadata-v2-inventory-limit',
        'positions' => [0],
    ]);
    recipeTestAssert(
        $inventoryLimitDetail['ingredients'][0]['inventory']['state'] === 'uncertain'
        && $inventoryLimitGrocery['outcomes'][0]['outcome'] === 'unresolved'
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM shopping_list WHERE canonical_key = ?",
            ['canonical:' . $inventoryLimitCanonical]
        ) === 0,
        'Pre-filter inventory truncation must never turn hidden valid stock into grocery-eligible missing'
    );
    $db->prepare("DELETE FROM recipe_catalog WHERE id = ?")
        ->execute([(int)$inventoryLimitRecipe['id']]);
    $db->prepare("DELETE FROM products WHERE id = ?")->execute([$inventoryLimitProduct]);
    $db->prepare("DELETE FROM inventory WHERE product_id = ? AND id <> ?")
        ->execute([$openedProduct, $openedInventoryId]);

    $_GET = ['id' => $cookidooRecipeId];
    ob_start();
    recipeCatalogApiDetail($db);
    $detailApi = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        !empty($detailApi['success'])
        && ($detailApi['detail']['schema_version'] ?? '') === 'recipe_detail_v1',
        'recipe_catalog_detail must return the purpose-built detail projection'
    );
    $_GET = ['id' => 0];
    ob_start();
    recipeCatalogApiDetail($db);
    $invalidDetailApi = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        http_response_code() === 400
        && ($invalidDetailApi['error'] ?? '') === 'invalid_recipe_id',
        'recipe_catalog_detail must reject non-positive recipe IDs'
    );
    http_response_code(200);
    $_GET = ['id' => '1junk'];
    ob_start();
    recipeCatalogApiDetail($db);
    $malformedDetailApi = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        http_response_code() === 400
            && ($malformedDetailApi['error'] ?? '') === 'invalid_recipe_id',
        'Recipe detail must reject numeric-prefix query IDs'
    );
    http_response_code(200);
    $_GET = ['id' => '1junk'];
    ob_start();
    recipeCatalogApiJobsStatus($db);
    $malformedJobStatusApi = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        http_response_code() === 400
            && ($malformedJobStatusApi['error'] ?? '') === 'invalid_job_id'
            && !array_key_exists('jobs', $malformedJobStatusApi),
        'Jobs status must reject a supplied malformed ID instead of listing jobs'
    );
    http_response_code(200);
    $_GET = [];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $refreshJobsBeforeInvalidIds = recipeTestCount(
        $db,
        "SELECT COUNT(*) FROM recipe_jobs WHERE job_type = 'recipe_refresh'"
    );
    foreach ([true, 1.0, '1', '1junk', 0, -1] as $invalidRefreshId) {
        $GLOBALS['RECIPE_API_JSON_INPUT'] = [
            'recipe_id' => $invalidRefreshId,
            'connector' => 'local',
        ];
        http_response_code(200);
        ob_start();
        recipeCatalogApiRefresh($db);
        $invalidRefresh = json_decode((string)ob_get_clean(), true);
        recipeTestAssert(
            http_response_code() === 400
                && ($invalidRefresh['error'] ?? '') === 'invalid_recipe_id'
                && recipeTestCount(
                    $db,
                    "SELECT COUNT(*) FROM recipe_jobs
                     WHERE job_type = 'recipe_refresh'"
                ) === $refreshJobsBeforeInvalidIds,
            'Malformed refresh IDs must not fall through to catalog refresh: '
                . get_debug_type($invalidRefreshId)
        );
    }
    $GLOBALS['RECIPE_API_JSON_INPUT'] = [[
        'connector' => 'local',
    ]];
    http_response_code(200);
    ob_start();
    recipeCatalogApiRefresh($db);
    $invalidRefreshShape = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        http_response_code() === 400
            && ($invalidRefreshShape['error'] ?? '') === 'invalid_request'
            && recipeTestCount(
                $db,
                "SELECT COUNT(*) FROM recipe_jobs
                 WHERE job_type = 'recipe_refresh'"
            ) === $refreshJobsBeforeInvalidIds,
        'Recipe refresh must require a JSON object'
    );
    $GLOBALS['RECIPE_API_JSON_INPUT'] = ['connector' => 'local'];
    http_response_code(200);
    ob_start();
    recipeCatalogApiRefresh($db);
    $catalogRefresh = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        !empty($catalogRefresh['success'])
            && ($catalogRefresh['job']['scope'] ?? '') === 'catalog'
            && array_key_exists(
                'recipe_id',
                $catalogRefresh['job']['payload'] ?? []
            )
            && $catalogRefresh['job']['payload']['recipe_id'] === null,
        'An explicitly ID-less object may enqueue a catalog refresh'
    );
    if (!empty($catalogRefresh['job']['id'])) {
        $db->prepare("DELETE FROM recipe_jobs WHERE id = ?")
            ->execute([(int)$catalogRefresh['job']['id']]);
    }
    unset($GLOBALS['RECIPE_API_JSON_INPUT']);
    http_response_code(200);

    $missingSelections = array_values(array_filter(
        $cookidooDetail['ingredients'],
        static fn(array $ingredient): bool =>
            $ingredient['name'] === 'Missing Spice Test'
            && $ingredient['inventory']['state'] === 'missing'
    ));
    recipeTestAssert(
        count($missingSelections) === 2,
        'Cookidoo detail must expose both repeated missing positions'
    );
    $groceryFirst = recipeGroceryAddMissing($db, [
        'recipe_id' => $cookidooRecipeId,
        'idempotency_key' => 'metadata-v2-grocery-1',
        'ingredient_keys' => array_column($missingSelections, 'key'),
    ]);
    recipeTestAssert(
        array_column($groceryFirst['outcomes'], 'outcome') === ['added', 'already_listed']
        && $groceryFirst['summary']['added'] === 1
        && $groceryFirst['summary']['already_listed'] === 1,
        'Missing-grocery mutation must canonicalize repeated equivalent ingredients'
    );
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM shopping_list
             WHERE canonical_key = ? AND quantity = 1",
            ['canonical:' . $missingSpiceCanonical]
        ) === 1,
        'Source amount text must never become the internal shopping quantity'
    );
    $groceryReplay = recipeGroceryAddMissing($db, [
        'recipe_id' => $cookidooRecipeId,
        'idempotency_key' => 'metadata-v2-grocery-1',
        'ingredient_keys' => array_column($missingSelections, 'key'),
    ]);
    recipeTestAssert(
        $groceryReplay['replayed'] === true
        && $groceryReplay['outcomes'] === $groceryFirst['outcomes']
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM shopping_list
             WHERE canonical_key = ? AND quantity = 1",
            ['canonical:' . $missingSpiceCanonical]
        ) === 1,
        'Exact grocery command replay must not grow shopping quantities'
    );
    $unsafeIdentityRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Unsafe Grocery Identity Variants',
        'ingredients' => [
            'chicken thighs, boneless and skinless',
            'chicken thighs, skin on and bone in',
        ],
        'instructions' => [],
    ], [
        'connector' => 'manual',
        'external_id' => 'unsafe-grocery-identity-variants',
    ]);
    $db->prepare("
        UPDATE recipe_ingredients
        SET mapping_confidence = 0.9,
            mapping_source = 'taxonomy_rule'
        WHERE recipe_id = ?
    ")->execute([(int)$unsafeIdentityRecipe['id']]);
    $unsafeIdentityDetail = recipeCatalogDetail(
        $db,
        (int)$unsafeIdentityRecipe['id']
    );
    $db->prepare("DELETE FROM shopping_list WHERE lower(name) = lower(?)")
        ->execute(['Chicken Thighs']);
    $unsafeIdentityGrocery = recipeGroceryAddMissing($db, [
        'recipe_id' => (int)$unsafeIdentityRecipe['id'],
        'idempotency_key' => 'unsafe-grocery-identity-variants',
        'positions' => [0, 1],
    ]);
    $unsafeIdentityReplay = recipeGroceryAddMissing($db, [
        'recipe_id' => (int)$unsafeIdentityRecipe['id'],
        'idempotency_key' => 'unsafe-grocery-identity-variants',
        'positions' => [0, 1],
    ]);
    recipeTestAssert(
        array_column($unsafeIdentityDetail['ingredients'], 'display_name')
            === ['Chicken Thighs', 'Chicken Thighs']
            && array_column(
                $unsafeIdentityDetail['ingredients'],
                'inventory'
            )[0]['state'] === 'missing'
            && array_column(
                $unsafeIdentityDetail['ingredients'],
                'inventory'
            )[1]['state'] === 'missing'
            && array_column($unsafeIdentityGrocery['outcomes'], 'outcome')
                === ['added', 'added']
            && $unsafeIdentityReplay['replayed'] === true
            && recipeTestCount(
                $db,
                "SELECT COUNT(*) FROM shopping_list
                 WHERE canonical_key IN (?, ?)",
                [
                    'name:chicken thighs boneless and skinless',
                    'name:chicken thighs skin on and bone in',
                ]
            ) === 2,
        'Unsafe source identities sharing one display name must remain distinct and replayable'
    );
    $grocerySecondKey = recipeGroceryAddMissing($db, [
        'recipe_id' => $cookidooRecipeId,
        'idempotency_key' => 'metadata-v2-grocery-2',
        'selections' => array_map(
            static fn(array $ingredient): array => [
                'key' => $ingredient['key'],
                'position' => $ingredient['position'],
            ],
            $missingSelections
        ),
    ]);
    recipeTestAssert(
        array_column($grocerySecondKey['outcomes'], 'outcome')
            === ['already_listed', 'already_listed'],
        'Equivalent ingredients already on the internal list must not be duplicated'
    );
    $db->prepare("
        UPDATE recipe_grocery_requests
        SET request_fingerprint = NULL
        WHERE idempotency_key = 'metadata-v2-grocery-2'
    ")->execute();
    $legacyGroceryReplay = recipeGroceryAddMissing($db, [
        'recipe_id' => $cookidooRecipeId,
        'idempotency_key' => 'metadata-v2-grocery-2',
        'selections' => array_map(
            static fn(array $ingredient): array => [
                'key' => $ingredient['key'],
                'position' => $ingredient['position'],
            ],
            $missingSelections
        ),
    ]);
    recipeTestAssert(
        $legacyGroceryReplay['replayed'] === true
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_grocery_requests
             WHERE idempotency_key = 'metadata-v2-grocery-2'
               AND length(request_fingerprint) = 64"
        ) === 1,
        'Legacy selection hashes must validate once and backfill the stable request fingerprint'
    );
    $idempotencyConflict = false;
    try {
        recipeGroceryAddMissing($db, [
            'recipe_id' => $cookidooRecipeId,
            'idempotency_key' => 'metadata-v2-grocery-1',
            'ingredient_keys' => [$missingSelections[0]['key']],
        ]);
    } catch (RecipeGroceryConflictException $e) {
        $idempotencyConflict = true;
    }
    recipeTestAssert(
        $idempotencyConflict,
        'Reusing an idempotency key for another selection must be rejected'
    );

    $mutableReplayRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Mutable Grocery Replay',
        'ingredients' => [['name' => 'Missing Spice Test']],
        'steps' => ['Use the spice.'],
    ], ['connector' => 'manual', 'external_id' => 'mutable-grocery-replay']);
    $mutableReplayInput = [
        'recipe_id' => (int)$mutableReplayRecipe['id'],
        'idempotency_key' => 'metadata-v2-grocery-mutable-replay',
        'positions' => [0],
    ];
    $mutableReplayFirst = recipeGroceryAddMissing($db, $mutableReplayInput);
    $db->prepare("DELETE FROM recipe_ingredients WHERE recipe_id = ?")
        ->execute([(int)$mutableReplayRecipe['id']]);
    $mutableReplayAgain = recipeGroceryAddMissing($db, $mutableReplayInput);
    $mutableReplayConflict = false;
    try {
        recipeGroceryAddMissing($db, [
            'recipe_id' => (int)$mutableReplayRecipe['id'],
            'idempotency_key' => 'metadata-v2-grocery-mutable-replay',
            'positions' => [1],
        ]);
    } catch (RecipeGroceryConflictException $e) {
        $mutableReplayConflict = true;
    }
    recipeTestAssert(
        $mutableReplayAgain['replayed'] === true
        && $mutableReplayAgain['outcomes'] === $mutableReplayFirst['outcomes']
        && $mutableReplayConflict,
        'Persisted grocery outcomes must replay before mutable ingredient validation'
    );
    $db->prepare("DELETE FROM recipe_catalog WHERE id = ?")
        ->execute([(int)$mutableReplayRecipe['id']]);

    $retentionRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Grocery Request Retention',
        'ingredients' => [['name' => 'Missing Spice Test']],
        'steps' => ['Use the spice.'],
    ], ['connector' => 'manual', 'external_id' => 'grocery-request-retention']);
    $retentionRecentInput = [
        'recipe_id' => (int)$retentionRecipe['id'],
        'idempotency_key' => 'metadata-v2-retention-recent',
        'positions' => [0],
    ];
    recipeGroceryAddMissing($db, $retentionRecentInput);
    $retentionCurrentInput = [
        'recipe_id' => (int)$retentionRecipe['id'],
        'idempotency_key' => 'metadata-v2-retention-current',
        'positions' => [0],
    ];
    recipeGroceryAddMissing($db, $retentionCurrentInput);
    $db->prepare("
        UPDATE recipe_grocery_requests
        SET created_at = datetime('now', '-31 days')
        WHERE idempotency_key = 'metadata-v2-retention-current'
    ")->execute();
    $db->prepare("
        INSERT INTO recipe_grocery_requests (
            idempotency_key, recipe_id, request_fingerprint,
            selection_hash, outcomes_json, created_at
        )
        VALUES (?, ?, ?, ?, '[]', datetime('now', '-31 days'))
    ")->execute([
        'metadata-v2-retention-expired',
        (int)$retentionRecipe['id'],
        str_repeat('a', 64),
        str_repeat('b', 64),
    ]);
    $retentionCurrentReplay = recipeGroceryAddMissing($db, $retentionCurrentInput);
    recipeTestAssert(
        $retentionCurrentReplay['replayed'] === true
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_grocery_requests
             WHERE idempotency_key = 'metadata-v2-retention-current'"
        ) === 1
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_grocery_requests
             WHERE idempotency_key = 'metadata-v2-retention-expired'"
        ) === 0,
        'Bounded retention pruning must remove expired rows without deleting the replayed command'
    );
    $retentionRecentReplay = recipeGroceryAddMissing($db, $retentionRecentInput);
    recipeTestAssert(
        $retentionRecentReplay['replayed'] === true,
        'Recent grocery idempotency records must remain replayable during pruning'
    );
    $overrideGroceryDetail = recipeCatalogDetail(
        $db,
        $cookidooRecipeId
    );
    $overrideGroceryIngredient = null;
    foreach ($overrideGroceryDetail['ingredients'] as $ingredient) {
        if (
            (string)$ingredient['key']
                === (string)$missingSelections[0]['key']
        ) {
            $overrideGroceryIngredient = $ingredient;
            break;
        }
    }
    recipeIngredientOverrideSet($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $overrideGroceryIngredient['key'],
        'position' => $overrideGroceryIngredient['position'],
        'availability' => 'have',
        'feedback_token' =>
            $overrideGroceryIngredient['feedback_token'],
        'idempotency_key' =>
            'feedback-grocery-have-override-1',
    ]);
    $overrideSuppressedGrocery = recipeGroceryAddMissing(
        $db,
        [
            'recipe_id' => $cookidooRecipeId,
            'idempotency_key' =>
                'metadata-v2-grocery-have-override',
            'ingredient_keys' => [
                $overrideGroceryIngredient['key'],
            ],
        ]
    );
    recipeTestAssert(
        $overrideSuppressedGrocery['outcomes'][0]['outcome']
            === 'now_in_stock',
        'Grocery mutations must honor trusted have overrides server-side'
    );
    recipeIngredientOverrideSet($db, [
        'recipe_id' => $cookidooRecipeId,
        'ingredient_key' =>
            $overrideGroceryIngredient['key'],
        'position' => $overrideGroceryIngredient['position'],
        'availability' => 'clear',
        'feedback_token' =>
            $overrideGroceryIngredient['feedback_token'],
        'idempotency_key' =>
            'feedback-grocery-have-override-clear-1',
    ]);
    $db->prepare("DELETE FROM recipe_catalog WHERE id = ?")
        ->execute([(int)$retentionRecipe['id']]);

    $productInsert->execute(['Missing Spice Test', 0]);
    $missingSpiceProduct = (int)$db->lastInsertId();
    $mappingInsert->execute([$missingSpiceProduct, $missingSpiceCanonical]);
    $db->prepare("
        INSERT INTO inventory (product_id, location, quantity, prepared_food)
        VALUES (?, 'dispensa', 1, 0)
    ")->execute([$missingSpiceProduct]);
    $groceryRevalidated = recipeGroceryAddMissing($db, [
        'recipe_id' => $cookidooRecipeId,
        'idempotency_key' => 'metadata-v2-grocery-revalidate',
        'ingredient_keys' => [$missingSelections[0]['key']],
    ]);
    recipeTestAssert(
        $groceryRevalidated['outcomes'][0]['outcome'] === 'now_in_stock',
        'Grocery mutation must revalidate inventory at mutation time'
    );

    $unresolvedRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Unresolved Grocery Detail',
        'ingredients' => [['name' => 'Unknown Alias Ingredient']],
        'steps' => ['Authorized local step.'],
    ], ['connector' => 'manual', 'external_id' => 'unresolved-grocery-detail']);
    $unresolvedDetail = recipeCatalogDetail($db, (int)$unresolvedRecipe['id']);
    $unresolvedSearch = recipeCatalogTextSearch(
        $db,
        'Unknown Alias Ingredient',
        null,
        20,
        0
    );
    recipeTestAssert(
        $unresolvedDetail['ingredients'][0]['inventory']['state'] === 'uncertain'
        && $unresolvedDetail['capabilities']['grocery_add'] === true
        && $unresolvedDetail['grocery']['uncertain_count'] === 1
        && $unresolvedDetail['grocery']['missing_count'] === 0
        && $unresolvedDetail['grocery']['eligible_count'] === 0,
        'Unresolved inventory taxonomy must not be claimed missing'
    );
    recipeTestAssert(
        in_array(
            (int)$unresolvedRecipe['id'],
            array_map(
                static fn(array $row): int =>
                    (int)$row['recipe_id'],
                $unresolvedSearch['rows']
            ),
            true
        ),
        'Unresolved ingredients must remain text-searchable without becoming satisfying'
    );
    $unresolvedGrocery = recipeGroceryAddMissing($db, [
        'recipe_id' => (int)$unresolvedRecipe['id'],
        'idempotency_key' => 'metadata-v2-grocery-unresolved',
        'positions' => [0],
    ]);
    recipeTestAssert(
        $unresolvedGrocery['outcomes'][0]['outcome'] === 'unresolved',
        'Uncertain ingredient selections must not be added'
    );
    $canonicalInsert->execute(['grocery-failure-test', 'Grocery Failure Test']);
    $groceryFailureCanonical = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO taxonomy_nodes (tree_id, slug, name)
        VALUES (?, 'grocery-failure-test', 'Grocery Failure Test')
    ")->execute([$treeId]);
    $groceryFailureNode = (int)$db->lastInsertId();
    $closure->execute([$treeId, $groceryFailureNode, $groceryFailureNode, 0]);
    $groceryFailureRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Failed Grocery Detail',
        'ingredients' => [
            ['name' => 'Grocery Failure Test', 'qty' => '1 piece'],
            ['name' => 'Grocery Failure Test', 'qty' => '2 pieces'],
        ],
        'steps' => ['Authorized local step.'],
    ], ['connector' => 'manual', 'external_id' => 'failed-grocery-detail']);
    $db->exec("
        CREATE TRIGGER recipe_test_fail_first_grocery_insert
        BEFORE INSERT ON shopping_list
        WHEN NEW.canonical_key = 'canonical:{$groceryFailureCanonical}'
          AND NEW.specification = '1 piece'
        BEGIN
            SELECT RAISE(ABORT, 'forced grocery failure');
        END
    ");
    $partlyFailedGroceryInput = [
        'recipe_id' => (int)$groceryFailureRecipe['id'],
        'idempotency_key' => 'metadata-v2-grocery-first-failed',
        'positions' => [0, 1],
    ];
    $partlyFailedGrocery = recipeGroceryAddMissing(
        $db,
        $partlyFailedGroceryInput
    );
    $db->exec("DROP TRIGGER recipe_test_fail_first_grocery_insert");
    recipeTestAssert(
        array_column($partlyFailedGrocery['outcomes'], 'outcome')
            === ['failed', 'added']
        && $partlyFailedGrocery['summary']['failed'] === 1
        && $partlyFailedGrocery['summary']['added'] === 1
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM shopping_list
             WHERE canonical_key = ?",
            ['canonical:' . $groceryFailureCanonical]
        ) === 1,
        'A failed equivalent write must not make a later successful write look '
            . 'already listed: ' . json_encode($partlyFailedGrocery)
    );
    $partlyFailedGroceryReplay = recipeGroceryAddMissing(
        $db,
        $partlyFailedGroceryInput
    );
    recipeTestAssert(
        $partlyFailedGroceryReplay['replayed'] === true
        && $partlyFailedGroceryReplay['outcomes']
            === $partlyFailedGrocery['outcomes'],
        'Mixed failed/success grocery outcomes must replay truthfully'
    );
    $db->prepare("DELETE FROM shopping_list WHERE canonical_key = ?")
        ->execute(['canonical:' . $groceryFailureCanonical]);
    $db->exec("
        CREATE TRIGGER recipe_test_fail_all_grocery_inserts
        BEFORE INSERT ON shopping_list
        WHEN NEW.canonical_key = 'canonical:{$groceryFailureCanonical}'
        BEGIN
            SELECT RAISE(ABORT, 'forced grocery failure');
        END
    ");
    $fullyFailedGroceryInput = [
        'recipe_id' => (int)$groceryFailureRecipe['id'],
        'idempotency_key' => 'metadata-v2-grocery-both-failed',
        'positions' => [0, 1],
    ];
    $fullyFailedGrocery = recipeGroceryAddMissing(
        $db,
        $fullyFailedGroceryInput
    );
    $db->exec("DROP TRIGGER recipe_test_fail_all_grocery_inserts");
    recipeTestAssert(
        array_column($fullyFailedGrocery['outcomes'], 'outcome')
            === ['failed', 'failed']
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM shopping_list
             WHERE canonical_key = ?",
            ['canonical:' . $groceryFailureCanonical]
        ) === 0,
        'A failed equivalent write must not make a later failed write look already listed'
    );
    $fullyFailedGroceryReplay = recipeGroceryAddMissing(
        $db,
        $fullyFailedGroceryInput
    );
    recipeTestAssert(
        $fullyFailedGroceryReplay['replayed'] === true
        && $fullyFailedGroceryReplay['outcomes']
            === $fullyFailedGrocery['outcomes'],
        'All-failed grocery outcomes must replay truthfully'
    );
    $invalidGrocerySelection = false;
    try {
        recipeGroceryAddMissing($db, [
            'recipe_id' => $cookidooRecipeId,
            'idempotency_key' => 'metadata-v2-grocery-invalid',
            'positions' => [999],
        ]);
    } catch (InvalidArgumentException $e) {
        $invalidGrocerySelection = true;
    }
    recipeTestAssert(
        $invalidGrocerySelection,
        'Grocery mutation must reject positions outside the reloaded recipe'
    );

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['RECIPE_API_JSON_INPUT'] = [
        'recipe_id' => $cookidooRecipeId,
        'idempotency_key' => 'metadata-v2-grocery-api',
        'positions' => [$missingSelections[0]['position']],
    ];
    ob_start();
    recipeCatalogApiGroceryAdd($db);
    $groceryApi = json_decode((string)ob_get_clean(), true);
    unset($GLOBALS['RECIPE_API_JSON_INPUT']);
    recipeTestAssert(
        !empty($groceryApi['success'])
        && ($groceryApi['outcomes'][0]['outcome'] ?? '') === 'now_in_stock',
        'recipe_catalog_grocery_add must expose bounded per-item outcomes'
    );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    recipeCatalogApiGroceryAdd($db);
    $groceryMethodError = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        http_response_code() === 405
        && ($groceryMethodError['error'] ?? '') === 'method_not_allowed',
        'recipe_catalog_grocery_add must reject mutating GET requests'
    );
    http_response_code(200);
    $_SERVER['REQUEST_METHOD'] = 'GET';

    ob_start();
    haGetInfo($db);
    $haInfo = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        in_array(
            'inventory_decrement_v1',
            $haInfo['capabilities'] ?? [],
            true
        )
        && in_array('recipe_detail_v1', $haInfo['capabilities'] ?? [], true)
        && in_array('recipe_grocery_v1', $haInfo['capabilities'] ?? [], true)
        && in_array(
            'recipe_ingredient_feedback_v1',
            $haInfo['capabilities'] ?? [],
            true
        ),
        'ha_info must advertise decrement, detail, grocery, and ingredient-feedback capabilities'
    );

    $discoveryUpdated = recipeCookidooDiscover($db, [
        'query' => 'cookidoo metadata',
        'ingredients' => ['Basil Test', 'Tomato Test'],
        'exclude_ingredients' => ['Cream Test'],
        'locale' => 'en-GB',
        'limit' => 2,
    ]);
    $metadataOnlyBefore = recipeCatalogGetById($db, $cookidooRecipeId);
    $metadataOnlyScoreStateBefore = recipeScoreState($db);
    $metadataOnlyRankingBefore = $db->query("
        SELECT position, raw_text, normalized_name, quantity, quantity_text, unit,
               is_required, is_optional, is_staple, canonical_ingredient_id,
               taxonomy_node_id, mapping_confidence, mapping_source
        FROM recipe_ingredients
        WHERE recipe_id = {$cookidooRecipeId}
        ORDER BY position
    ")->fetchAll(PDO::FETCH_ASSOC);
    $metadataOnlySearchBefore = $db->query("
        SELECT * FROM recipe_search_documents
        WHERE recipe_id = {$cookidooRecipeId}
    ")->fetch(PDO::FETCH_ASSOC);
    $metadataOnlyFtsBefore = $db->query("
        SELECT rowid, title, ingredient_text, tags, description
        FROM recipe_catalog_fts
        WHERE rowid = {$cookidooRecipeId}
    ")->fetch(PDO::FETCH_ASSOC);
    $metadataOnlyClusterBefore = $db->query("
        SELECT * FROM recipe_clusters
        WHERE recipe_id = {$cookidooRecipeId}
    ")->fetch(PDO::FETCH_ASSOC);
    $metadataOnlyScoresBefore = $db->query("
        SELECT * FROM recipe_inventory_scores
        WHERE recipe_id = {$cookidooRecipeId}
        ORDER BY score_revision_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    recipeJobProcessQueueBatch($db, 1, 3);
    $updatedJob = recipeJobGet($db, $discoveryUpdated['job']['id']);
    recipeTestAssert(
        ($updatedJob['result']['updated_ids'][0] ?? null) === $cookidooRecipeId
        && $bridgeCalls === 2
        && ($lastBridgePayload['locale'] ?? null) === 'en-GB',
        'An effective regional discovery row must accept a later exact-locale metadata refresh'
    );
    $metadataOnlyAfter = recipeCatalogGetById($db, $cookidooRecipeId);
    $metadataOnlyOrigin = $db->query("
        SELECT metadata_version, metadata_schema_version
        FROM recipe_origins
        WHERE recipe_id = {$cookidooRecipeId}
          AND connector = 'cookidoo'
    ")->fetch(PDO::FETCH_ASSOC);
    recipeTestAssert(
        $metadataOnlyAfter['title'] === $metadataOnlyBefore['title']
        && $metadataOnlyAfter['image_url'] === $metadataOnlyBefore['image_url']
        && (float)$metadataOnlyAfter['yield_quantity'] === 6.0
        && $metadataOnlyAfter['prep_time_seconds'] === 360
        && $metadataOnlyAfter['cook_time_seconds'] === 1200
        && $metadataOnlyAfter['active_time_seconds'] === 720
        && $metadataOnlyAfter['inactive_time_seconds'] === 180
        && $metadataOnlyAfter['total_time_seconds'] === 2100
        && $metadataOnlyAfter['difficulty'] === 'medium'
        && $metadataOnlyAfter['primary_category'] === 'Main dishes'
        && $metadataOnlyAfter['devices'] === ['TM7', 'Air fryer']
        && $metadataOnlyAfter['optional_devices'] === ['Slow cooker']
        && $metadataOnlyAfter['equipment'] === ['whisk']
        && ($metadataOnlyOrigin['metadata_version'] ?? null)
            === RECIPE_COOKIDOO_METADATA_VERSION
        && ($metadataOnlyOrigin['metadata_schema_version'] ?? null)
            === RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        'Metadata-only refreshes must update only v2 General facts and per-origin freshness/version'
    );
    $metadataInvariantChecks = [
        'ranking' => $db->query("
            SELECT position, raw_text, normalized_name, quantity, quantity_text, unit,
                   is_required, is_optional, is_staple, canonical_ingredient_id,
                   taxonomy_node_id, mapping_confidence, mapping_source
            FROM recipe_ingredients
            WHERE recipe_id = {$cookidooRecipeId}
            ORDER BY position
        ")->fetchAll(PDO::FETCH_ASSOC) === $metadataOnlyRankingBefore,
        'search' => $db->query("
            SELECT * FROM recipe_search_documents
            WHERE recipe_id = {$cookidooRecipeId}
        ")->fetch(PDO::FETCH_ASSOC) === $metadataOnlySearchBefore,
        'fts' => $db->query("
            SELECT rowid, title, ingredient_text, tags, description
            FROM recipe_catalog_fts
            WHERE rowid = {$cookidooRecipeId}
        ")->fetch(PDO::FETCH_ASSOC) === $metadataOnlyFtsBefore,
        'cluster' => $db->query("
            SELECT * FROM recipe_clusters
            WHERE recipe_id = {$cookidooRecipeId}
        ")->fetch(PDO::FETCH_ASSOC) === $metadataOnlyClusterBefore,
        'scores' => $db->query("
            SELECT * FROM recipe_inventory_scores
            WHERE recipe_id = {$cookidooRecipeId}
            ORDER BY score_revision_id
        ")->fetchAll(PDO::FETCH_ASSOC) === $metadataOnlyScoresBefore,
        'revisions' => recipeScoreState($db) === $metadataOnlyScoreStateBefore,
    ];
    recipeTestAssert(
        !in_array(false, $metadataInvariantChecks, true),
        'Metadata-only updates must preserve ranking ingredients, search/FTS, '
            . 'clusters, scores, and catalog/cursor revisions: '
            . json_encode($metadataInvariantChecks)
    );
    $atomicSourceBefore = $db->query("
        SELECT position, name, normalized_name, source_quantity,
               source_quantity_max, source_unit, source_amount_text,
               source_group_index, source_group_position,
               source_group_title, source_ingredient_ref,
               source_default_title, source_unit_ref, source_optional,
               source_shopping_category_ref, mapping_version,
               canonical_ingredient_id, taxonomy_node_id,
               mapping_confidence, mapping_source
        FROM recipe_source_ingredients
        WHERE recipe_id = {$cookidooRecipeId}
        ORDER BY position
    ")->fetchAll(PDO::FETCH_ASSOC);
    recipeTestAssert(
        array_map(
            static fn(array $row): array => [
                (int)$row['source_group_index'],
                (int)$row['source_group_position'],
            ],
            $atomicSourceBefore
        ) === [[0, 0], [0, 1], [1, 0], [1, 1], [1, 2]],
        'Successful metadata-only refreshes must replace complete group boundaries atomically'
    );
    $cookidooOriginId = (int)$db->query("
        SELECT id FROM recipe_origins
        WHERE recipe_id = {$cookidooRecipeId} AND connector = 'cookidoo'
    ")->fetchColumn();
    $db->exec("
        CREATE TRIGGER recipe_test_fail_source_metadata_insert
        BEFORE INSERT ON recipe_source_ingredients
        WHEN NEW.recipe_id = {$cookidooRecipeId}
        BEGIN
            SELECT RAISE(ABORT, 'forced source metadata failure');
        END
    ");
    $atomicMetadataFailed = false;
    try {
        recipeCookidooApplyMetadataV2(
            $db,
            $cookidooRecipeId,
            $cookidooOriginId,
            recipeTestCookidooBridgeRecipe([
                'external_id' => 'r-cookidoo-metadata-1',
                'title' => 'Ignored atomic title',
                'general' => [
                    'yield_quantity' => 9,
                    'yield_unit' => 'portions',
                    'active_time_seconds' => 900,
                    'total_time_seconds' => 2400,
                    'difficulty' => 'hard',
                    'primary_category' => 'Atomic failure',
                    'equipment' => ['spatula'],
                ],
                'ingredients' => [[
                    'name' => 'Atomic Replacement Ingredient',
                    'source_quantity' => 1,
                    'source_quantity_max' => null,
                    'source_unit' => 'piece',
                    'source_amount_text' => '1 piece',
                ]],
                'image_url' => 'https://assets.tmecosys.com/image/upload/ignored.jpg',
                'canonical_url' => 'https://cookidoo.co.uk/recipes/recipe/en-GB/r-cookidoo-metadata-1',
                'locale' => 'en-GB',
            ]),
            gmdate('Y-m-d H:i:s')
        );
    } catch (PDOException $e) {
        $atomicMetadataFailed = true;
    } finally {
        $db->exec("DROP TRIGGER recipe_test_fail_source_metadata_insert");
    }
    recipeTestAssert(
        $atomicMetadataFailed
        && $db->query("
            SELECT position, name, normalized_name, source_quantity,
                   source_quantity_max, source_unit, source_amount_text,
                   source_group_index, source_group_position,
                   source_group_title, source_ingredient_ref,
                   source_default_title, source_unit_ref, source_optional,
                   source_shopping_category_ref, mapping_version,
                   canonical_ingredient_id, taxonomy_node_id,
                   mapping_confidence, mapping_source
            FROM recipe_source_ingredients
            WHERE recipe_id = {$cookidooRecipeId}
            ORDER BY position
        ")->fetchAll(PDO::FETCH_ASSOC) === $atomicSourceBefore
        && (float)recipeCatalogGetById($db, $cookidooRecipeId)['yield_quantity']
            === 6.0
        && recipeScoreState($db) === $metadataOnlyScoreStateBefore,
        'Failed metadata refreshes must roll back the full source list and General fields atomically'
    );

    $metadataCanonicalLocaleRejected = false;
    try {
        recipeCookidooNormalizeMetadataBridgeResponse([
            'outcomes' => [[
                'external_id' => 'locale-mismatch',
                'status' => 'succeeded',
                'recipe' => recipeTestCookidooBridgeRecipe([
                    'external_id' => 'locale-mismatch',
                    'title' => 'Locale mismatch',
                    'general' => [
                        'yield_quantity' => null,
                        'yield_unit' => null,
                        'active_time_seconds' => null,
                        'total_time_seconds' => null,
                        'difficulty' => null,
                        'primary_category' => null,
                        'equipment' => [],
                    ],
                    'ingredients' => [],
                    'image_url' => '',
                    'canonical_url' => (
                        'https://cookidoo.co.uk/recipes/recipe/en-US/'
                        . 'locale-mismatch'
                    ),
                    'locale' => 'en-GB',
                ]),
            ]],
            'count' => 1,
            'succeeded_count' => 1,
            'failed_count' => 0,
            'locale' => 'en-GB',
            'metadata_schema_version' =>
                RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        ], [
            'locale' => 'en-GB',
            'recipes' => [[
                'recipe_id' => 1,
                'origin_id' => 1,
                'external_id' => 'locale-mismatch',
            ]],
        ]);
    } catch (RuntimeException $e) {
        $metadataCanonicalLocaleRejected = true;
    }
    recipeTestAssert(
        $metadataCanonicalLocaleRejected,
        'Direct metadata success outcomes must use the exact requested canonical URL locale'
    );
    $emptyMetadataSuccessRejected = false;
    try {
        recipeCookidooNormalizeBridgeRecipe(
            recipeTestCookidooBridgeRecipe([
                'external_id' => 'empty-metadata-success',
                'title' => 'Empty Metadata Success',
                'general' => [
                    'yield_quantity' => null,
                    'yield_unit' => null,
                    'active_time_seconds' => null,
                    'total_time_seconds' => null,
                    'difficulty' => null,
                    'primary_category' => null,
                    'equipment' => [],
                ],
                'ingredients' => [],
                'image_url' => '',
                'canonical_url' => (
                    'https://cookidoo.co.uk/recipes/recipe/en-GB/'
                    . 'empty-metadata-success'
                ),
                'locale' => 'en-GB',
            ]),
            'en-GB',
            true
        );
    } catch (RuntimeException $e) {
        $emptyMetadataSuccessRejected = true;
    }
    recipeTestAssert(
        $emptyMetadataSuccessRejected,
        'Empty Cookidoo metadata success must not erase stored source rows'
    );
    $englishLanguageAssessment =
        recipeCookidooContentLanguageAssessment(
            'Chicken and vegetable soup',
            [
                ['name' => 'water'],
                ['name' => 'chicken'],
                ['name' => 'salt'],
            ]
        );
    $foreignLanguageAssessment =
        recipeCookidooContentLanguageAssessment(
            'Kartoffelsuppe',
            [
                ['name' => 'Wasser'],
                ['name' => 'Kartoffeln'],
                ['name' => 'Salz'],
            ]
        );
    $scriptLanguageAssessment =
        recipeCookidooContentLanguageAssessment(
            'Πίτα',
            [['name' => 'νερό']]
        );
    $ambiguousLanguageAssessment =
        recipeCookidooContentLanguageAssessment(
            'Miso udon',
            [['name' => 'miso']]
        );
    $mixedScriptLanguageAssessment =
        recipeCookidooContentLanguageAssessment(
            'English miso soup (味噌)',
            [
                ['name' => 'water'],
                ['name' => 'miso paste'],
                ['name' => 'green onion'],
            ]
        );
    $accentedGermanAssessment =
        recipeCookidooContentLanguageAssessment(
            'Käsesuppe',
            [
                ['name' => 'Käse'],
                ['name' => 'Öl'],
                ['name' => 'Eier'],
            ]
        );
    $db->beginTransaction();
    try {
        foreach ([
            0 => 'Wasser',
            1 => 'Kartoffeln',
            2 => 'Salz',
        ] as $position => $name) {
            $db->prepare("
                UPDATE recipe_source_ingredients
                SET name = ?, normalized_name = lower(?)
                WHERE recipe_id = ? AND position = ?
            ")->execute([
                $name,
                $name,
                $cookidooRecipeId,
                $position,
            ]);
        }
        $sourcePreferredAssessment =
            recipeCookidooLanguageRecipeAssessment(
                $db,
                $cookidooRecipeId
            );
    } finally {
        $db->rollBack();
    }
    recipeTestAssert(
        $englishLanguageAssessment['verdict'] === 'english'
        && $foreignLanguageAssessment['verdict'] === 'non_english'
        && $foreignLanguageAssessment['foreign_language'] === 'de'
        && $scriptLanguageAssessment['verdict'] === 'non_english'
        && $scriptLanguageAssessment['reason'] === 'foreign_script'
        && $ambiguousLanguageAssessment['verdict'] === 'undetermined'
        && $mixedScriptLanguageAssessment['verdict']
            === 'english'
        && $accentedGermanAssessment['verdict']
            === 'non_english'
        && $sourcePreferredAssessment['verdict']
            === 'non_english'
        && strlen(recipeCookidooLanguageRulesHash()) === 64,
        'Cookidoo language classification must be deterministic and fail open on ambiguous culinary text'
    );
    $saturatedAssessment =
        recipeCookidooContentLanguageAssessment(
            str_repeat('界', 400),
            array_fill(
                0,
                200,
                ['name' => str_repeat('界', 240)]
            )
        );
    $saturatedAssessmentBefore =
        recipeCookidooLanguageAssessmentRow(
            $db,
            $cookidooRecipeId
        );
    $saturatedStored = recipeCookidooLanguageAssessmentStore(
        $db,
        $cookidooRecipeId,
        $saturatedAssessment,
        'review'
    );
    recipeTestAssert(
        $saturatedAssessment['verdict'] === 'non_english'
        && $saturatedAssessment['script_hits']
            === RECIPE_COOKIDOO_LANGUAGE_MAX_SCRIPT_HITS
        && (int)$saturatedStored['current']['script_hits']
            === RECIPE_COOKIDOO_LANGUAGE_MAX_SCRIPT_HITS,
        'Large valid foreign-script assessments must saturate within the persisted schema bound'
    );
    recipeCookidooLanguageAssessmentRestore(
        $db,
        $cookidooRecipeId,
        $saturatedAssessmentBefore
    );
    $languagePolicyBefore = $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_INGEST_LANGUAGE_POLICY'
    ];
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_INGEST_LANGUAGE_POLICY'
    ] = 'enforce';
    $foreignCookidooRejected = false;
    try {
        recipeCookidooNormalizeBridgeRecipe(
            recipeTestCookidooBridgeRecipe([
                'external_id' => 'foreign-language',
                'title' => 'Kartoffelsuppe',
                'general' => [
                    'yield_quantity' => null,
                    'yield_unit' => null,
                    'active_time_seconds' => null,
                    'total_time_seconds' => null,
                    'difficulty' => null,
                    'primary_category' => null,
                    'equipment' => [],
                ],
                'ingredients' => [
                    ['name' => 'Wasser'],
                    ['name' => 'Kartoffeln'],
                    ['name' => 'Salz'],
                ],
                'image_url' => '',
                'canonical_url' => (
                    'https://cookidoo.co.uk/recipes/recipe/en-GB/'
                    . 'foreign-language'
                ),
                'locale' => 'en-GB',
            ]),
            'en-GB',
            true
        );
    } catch (RuntimeException $e) {
        $foreignCookidooRejected = str_contains(
            $e->getMessage(),
            'language'
        );
    }
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_INGEST_LANGUAGE_POLICY'
    ] = 'observe';
    $foreignCookidooObserved = recipeCookidooNormalizeBridgeRecipe(
        recipeTestCookidooBridgeRecipe([
            'external_id' => 'foreign-language-observe',
            'title' => 'Kartoffelsuppe',
            'general' => [
                'yield_quantity' => null,
                'yield_unit' => null,
                'active_time_seconds' => null,
                'total_time_seconds' => null,
                'difficulty' => null,
                'primary_category' => null,
                'equipment' => [],
            ],
            'ingredients' => [
                ['name' => 'Wasser'],
                ['name' => 'Kartoffeln'],
                ['name' => 'Salz'],
            ],
            'image_url' => '',
            'canonical_url' => (
                'https://cookidoo.co.uk/recipes/recipe/en-GB/'
                . 'foreign-language-observe'
            ),
            'locale' => 'en-GB',
        ]),
        'en-GB',
        true
    );
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_INGEST_LANGUAGE_POLICY'
    ] = $languagePolicyBefore;
    recipeTestAssert(
        $foreignCookidooRejected
        && $foreignCookidooObserved['_language_assessment']['verdict']
            === 'non_english',
        'Cookidoo ingestion must reject high-confidence foreign content only in enforce mode'
    );
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_INGEST_LANGUAGE_POLICY'
    ] = 'enforce';
    $metadataLanguageFiltered =
        recipeCookidooNormalizeMetadataBridgeResponse([
            'outcomes' => [[
                'external_id' => 'foreign-metadata-item',
                'status' => 'succeeded',
                'recipe' => recipeTestCookidooBridgeRecipe([
                    'external_id' => 'foreign-metadata-item',
                    'title' => 'Kartoffelsuppe',
                    'general' => [
                        'yield_quantity' => null,
                        'yield_unit' => null,
                        'active_time_seconds' => null,
                        'total_time_seconds' => null,
                        'difficulty' => null,
                        'primary_category' => null,
                        'equipment' => [],
                    ],
                    'ingredients' => [
                        ['name' => 'Wasser'],
                        ['name' => 'Kartoffeln'],
                        ['name' => 'Salz'],
                    ],
                    'image_url' => '',
                    'canonical_url' => (
                        'https://cookidoo.co.uk/recipes/recipe/en-GB/'
                        . 'foreign-metadata-item'
                    ),
                    'locale' => 'en-GB',
                ]),
            ]],
            'count' => 1,
            'succeeded_count' => 1,
            'failed_count' => 0,
            'locale' => 'en-GB',
            'metadata_schema_version' =>
                RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        ], [
            'locale' => 'en-GB',
            'recipes' => [[
                'recipe_id' => 1,
                'origin_id' => 1,
                'external_id' => 'foreign-metadata-item',
            ]],
        ]);
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_INGEST_LANGUAGE_POLICY'
    ] = $languagePolicyBefore;
    recipeTestAssert(
        $metadataLanguageFiltered['succeeded_count'] === 0
        && $metadataLanguageFiltered['failed_count'] === 1
        && $metadataLanguageFiltered['outcomes'][0][
            'error_kind'
        ] === 'content_language_rejected',
        'Foreign metadata items must become permanent per-item failures without aborting sibling outcomes'
    );
    $languageVisibilityBefore =
        recipeCookidooLanguageAssessmentRow(
        $db,
        $cookidooRecipeId
    );
    $languageVisibilityTitle = (string)recipeCatalogGetById(
        $db,
        $cookidooRecipeId
    )['title'];
    $languageScoreStateBefore = recipeScoreState($db);
    recipeCookidooLanguageAssessmentStore(
        $db,
        $cookidooRecipeId,
        recipeCookidooLanguageRecipeAssessment(
            $db,
            $cookidooRecipeId
        ),
        'quarantine'
    );
    $preservedQuarantine = recipeCookidooLanguageAssessmentStore(
        $db,
        $cookidooRecipeId,
        recipeCookidooLanguageRecipeAssessment(
            $db,
            $cookidooRecipeId
        )
    );
    recipeTestAssert(
        recipeCatalogGetById($db, $cookidooRecipeId) === null
        && recipeCatalogGetById(
            $db,
            $cookidooRecipeId,
            true
        )['id'] === $cookidooRecipeId
        && recipeCatalogDetail($db, $cookidooRecipeId) === null
        && $preservedQuarantine['current']['disposition']
            === 'quarantine'
        && !$preservedQuarantine['visibility_changed']
        && !in_array(
            $cookidooRecipeId,
            array_map(
                static fn(array $row): int =>
                    (int)$row['recipe_id'],
                recipeCatalogTextSearch(
                    $db,
                    $languageVisibilityTitle,
                    'cookidoo',
                    100,
                    0
                )['rows']
            ),
            true
        ),
        'Quarantined Cookidoo recipes must be absent from user-facing reads and search'
    );
    recipeCookidooLanguageAssessmentRestore(
        $db,
        $cookidooRecipeId,
        $languageVisibilityBefore
    );
    $languageScoreStateAfter = recipeScoreState($db);
    unset(
        $languageScoreStateBefore['cursor_revision'],
        $languageScoreStateAfter['cursor_revision']
    );
    recipeTestAssert(
        recipeCatalogGetById($db, $cookidooRecipeId) !== null
        && $languageScoreStateAfter === $languageScoreStateBefore,
        'Language visibility must restore without changing inventory, catalog, or ontology score state'
    );
    $metadataSchemaRejected = false;
    try {
        recipeCookidooNormalizeMetadataBridgeResponse([
            'outcomes' => [[
                'external_id' => 'schema-mismatch',
                'status' => 'failed',
                'error_kind' => 'not_found',
            ]],
            'count' => 1,
            'succeeded_count' => 0,
            'failed_count' => 1,
            'locale' => 'en-GB',
            'metadata_schema_version' => 'ingredient-topology-v0',
        ], [
            'locale' => 'en-GB',
            'recipes' => [[
                'recipe_id' => 1,
                'origin_id' => 1,
                'external_id' => 'schema-mismatch',
            ]],
        ]);
    } catch (RuntimeException $e) {
        $metadataSchemaRejected = true;
    }
    recipeTestAssert(
        $metadataSchemaRejected,
        'Direct metadata outcomes must match the active topology schema marker'
    );

    $languageOnlyOrigins = [];
    foreach ([
        [
            'external_id' => 'legacy-language-de',
            'locale' => 'de',
            'canonical_url' => (
                'https://cookidoo.de/recipes/recipe/de-DE/'
                . 'legacy-language-de'
            ),
        ],
        [
            'external_id' => 'legacy-language-en-gb',
            'locale' => 'en',
            'canonical_url' => (
                'https://cookidoo.co.uk/recipes/recipe/en-GB/'
                . 'legacy-language-en-gb'
            ),
        ],
        [
            'external_id' => 'legacy-language-en-us',
            'locale' => 'en',
            'canonical_url' => (
                'https://cookidoo.thermomix.com/recipes/recipe/en-US/'
                . 'legacy-language-en-us'
            ),
        ],
    ] as $legacyLanguageOrigin) {
        $saved = recipeCatalogSaveVariant($db, [
            'title' => 'Legacy language-only ' . $legacyLanguageOrigin['external_id'],
            'source_ingredients' => [],
        ], [
            'connector' => 'cookidoo',
            'external_id' => $legacyLanguageOrigin['external_id'],
            'canonical_url' => $legacyLanguageOrigin['canonical_url'],
            'locale' => $legacyLanguageOrigin['locale'],
        ]);
        $languageOnlyOrigins[] = $saved;
        $db->prepare("
            UPDATE recipe_origins
            SET metadata_version = NULL,
                metadata_schema_version = NULL
            WHERE recipe_id = ? AND connector = 'cookidoo'
        ")->execute([(int)$saved['id']]);
    }
    $singleLanguageStatus = recipeCookidooMetadataBackfillStatus($db, 'de');
    $ambiguousLanguageStatus = recipeCookidooMetadataBackfillStatus($db, 'en');
    $singleLanguagePlan = recipeCookidooMetadataBackfillPlan($db, 'de', 20, 200);
    $ambiguousLanguagePlan = recipeCookidooMetadataBackfillPlan($db, 'en', 20, 200);
    recipeTestAssert(
        $singleLanguageStatus['refreshable'] === false
        && $singleLanguageStatus['unrefreshable_reason']
            === 'language_only_locale'
        && $singleLanguageStatus['origins']['invalid_locale'] === 1
        && $singleLanguageStatus['origins']['unrefreshable'] === 1
        && $singleLanguageStatus['origins']['remaining'] === 0
        && $singleLanguagePlan['refreshable'] === false
        && $singleLanguagePlan['batch_count'] === 0
        && recipeCookidooMetadataBackfillCandidates($db, 'de', 0, 200)
            === [],
        'A lone language-only origin must be reported unrefreshable rather than inferred to a market'
    );
    recipeTestAssert(
        $ambiguousLanguageStatus['refreshable'] === false
        && $ambiguousLanguageStatus['origins']['invalid_locale'] === 2
        && $ambiguousLanguageStatus['origins']['unrefreshable'] === 2
        && $ambiguousLanguageStatus['origins']['remaining'] === 0
        && $ambiguousLanguagePlan['refreshable'] === false
        && $ambiguousLanguagePlan['batch_count'] === 0
        && recipeCookidooMetadataBackfillCandidates($db, 'en', 0, 200)
            === [],
        'Ambiguous language-only origins must never silently select another market'
    );

    $legacyMetadataRecipes = [];
    foreach (['a', 'b', 'c', 'd'] as $suffix) {
        $legacyMetadataRecipes[] = recipeCatalogSaveVariant($db, [
            'title' => 'Legacy Metadata V1 ' . strtoupper($suffix),
            'image_url' => (
                'https://assets.tmecosys.com/image/upload/legacy-v1-'
                . $suffix . '.jpg'
            ),
            'source_ingredients' => [[
                'name' => 'Legacy Source ' . strtoupper($suffix),
                'source_quantity' => 1,
                'source_quantity_max' => null,
                'source_unit' => 'piece',
                'source_amount_text' => '1 piece',
            ]],
        ], [
            'connector' => 'cookidoo',
            'external_id' => 'legacy-v2-' . $suffix,
            'canonical_url' => (
                'https://cookidoo.co.uk/recipes/recipe/en-GB/legacy-v2-'
                . $suffix
            ),
            'locale' => 'en-GB',
        ]);
    }
    $legacyMetadataIds = array_column($legacyMetadataRecipes, 'id');
    $legacyMetadataIdList = implode(',', array_map('intval', $legacyMetadataIds));
    $db->exec("
        UPDATE recipe_origins
        SET metadata_version = NULL,
            metadata_schema_version = NULL
        WHERE recipe_id IN ({$legacyMetadataIdList})
    ");
    $metadataJobsBefore = recipeTestCount(
        $db,
        "SELECT COUNT(*) FROM recipe_jobs
         WHERE job_type = 'recipe_metadata_refresh'"
    );
    $disabledMetadataPlan = recipeCookidooMetadataBackfillPlan(
        $db,
        'en-GB',
        3,
        4
    );
    $disabledMetadataEnqueueRejected = false;
    try {
        recipeCookidooEnqueueMetadataBackfill($db, 'en-GB', 2, 3);
    } catch (RuntimeException $e) {
        $disabledMetadataEnqueueRejected = $e->getMessage()
            === 'cookidoo_metadata_backfill_disabled';
    }
    recipeTestAssert(
        $disabledMetadataPlan['recipe_count'] === 4
        && $disabledMetadataPlan['batch_count'] === 2
        && $disabledMetadataEnqueueRejected
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_jobs
             WHERE job_type = 'recipe_metadata_refresh'"
        ) === $metadataJobsBefore,
        'Disabled bulk metadata backfill must support dry planning without enqueueing'
    );

    $GLOBALS['RECIPE_COOKIDOO_CONFIG']['COOKIDOO_METADATA_BACKFILL_ENABLED'] = 'true';
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_DETAIL_HYDRATION_ENABLED'
    ] = 'true';
    recipeTestAssert(
        recipeCookidooMetadataBackfillHasPendingWork($db, 'en-GB'),
        'Enabled metadata backfill must expose pending work'
    );
    $languageOnlyEnqueueRejected = false;
    try {
        recipeCookidooEnqueueMetadataBackfill($db, 'en', 20, 200);
    } catch (InvalidArgumentException $e) {
        $languageOnlyEnqueueRejected = $e->getMessage()
            === 'cookidoo_metadata_backfill_requires_regional_or_script_locale';
    }
    recipeTestAssert(
        $languageOnlyEnqueueRejected,
        'Language-only metadata backfill must reject enqueue even when enabled'
    );
    $directMetadataCalls = 0;
    $failDirectMetadata = true;
    $directMetadataBatchSizes = [];
    $directMetadataRequestIds = [];
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] = static function (
        string $url,
        string $token,
        array $payload,
        int $timeout
    ) use (
        &$directMetadataCalls,
        &$failDirectMetadata,
        &$directMetadataBatchSizes,
        &$directMetadataRequestIds
    ): array {
        recipeTestAssert(
            $url === 'http://cookidoo-bridge:8081/v1/metadata',
            'Direct metadata refresh must use the bounded bridge endpoint'
        );
        recipeTestAssert(
            $token === 'unit-test-token' && $timeout === 5,
            'Direct metadata refresh must retain bridge auth and timeout protections'
        );
        $externalIds = $payload['external_ids'] ?? null;
        recipeTestAssert(
            ($payload['locale'] ?? null) === 'en-GB'
            && is_array($externalIds)
            && count($externalIds) >= 1
            && count($externalIds) <= 20,
            'Direct metadata bridge requests must contain one locale and 1-20 IDs'
        );
        $directMetadataCalls++;
        $directMetadataBatchSizes[] = count($externalIds);
        $directMetadataRequestIds[] = $externalIds;
        if ($failDirectMetadata && in_array('legacy-v2-d', $externalIds, true)) {
            return ['status' => 500, 'body' => '{"error":"forced"}'];
        }
        $outcomes = [];
        foreach ($externalIds as $externalId) {
            $errorKind = match ($externalId) {
                'legacy-v2-b' => 'not_found',
                'legacy-v2-c' => 'invalid_metadata',
                'legacy-v2-d' => $failDirectMetadata
                    ? null
                    : 'invalid_metadata',
                default => null,
            };
            if ($errorKind !== null) {
                $outcomes[] = [
                    'external_id' => $externalId,
                    'status' => 'failed',
                    'error_kind' => $errorKind,
                ];
                continue;
            }
            $nullMetadata = $externalId === 'legacy-v2-d';
            $recipe = recipeTestCookidooBridgeRecipe([
                'external_id' => $externalId,
                'title' => 'Ignored Direct Title ' . $externalId,
                'general' => [
                    'yield_quantity' => $nullMetadata ? null : 2,
                    'yield_unit' => $nullMetadata ? null : 'portions',
                    'active_time_seconds' => $nullMetadata ? null : 300,
                    'total_time_seconds' => $nullMetadata ? null : 900,
                    'difficulty' => $nullMetadata ? null : 'easy',
                    'primary_category' => $nullMetadata ? null : 'Test',
                    'equipment' => $nullMetadata ? [] : ['spoon'],
                ],
                'ingredients' => $nullMetadata ? [] : [[
                    'name' => 'Refreshed Source ' . strtoupper(
                        substr($externalId, -1)
                    ),
                    'source_quantity' => 2,
                    'source_quantity_max' => 3,
                    'source_unit' => 'pieces',
                    'source_amount_text' => '2 - 3 pieces',
                    'source_group_index' => 0,
                    'source_group_position' => 0,
                    'source_group_title' => 'Main ingredients',
                    'source_ingredient_ref' =>
                        'ingredient-' . substr($externalId, -1),
                    'source_default_title' => 'Refreshed source',
                    'source_unit_ref' => 'unit-piece',
                    'source_optional' => false,
                    'source_shopping_category_ref' => 'category-test',
                ]],
                'image_url' => (
                    'https://assets.tmecosys.com/image/upload/ignored-direct-'
                    . $externalId . '.jpg'
                ),
                'canonical_url' => (
                    'https://cookidoo.co.uk/recipes/recipe/en-GB/'
                    . $externalId
                ),
                'locale' => 'en-GB',
            ]);
            $outcomes[] = [
                'external_id' => $externalId,
                'status' => 'succeeded',
                'recipe' => $recipe,
            ];
        }
        $succeededCount = count(array_filter(
            $outcomes,
            static fn(array $outcome): bool =>
                $outcome['status'] === 'succeeded'
        ));
        return [
            'status' => 200,
            'body' => json_encode([
                'outcomes' => $outcomes,
                'count' => count($outcomes),
                'succeeded_count' => $succeededCount,
                'failed_count' => count($outcomes) - $succeededCount,
                'locale' => 'en-GB',
                'metadata_schema_version' =>
                    RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
                'instructions' => ['must never persist'],
                'raw_payload' => ['must never persist'],
            ], JSON_UNESCAPED_SLASHES),
        ];
    };
    $metadataBackfillEnqueue = recipeCookidooEnqueueMetadataBackfill(
        $db,
        'en-GB',
        3,
        4
    );
    recipeTestAssert(
        $metadataBackfillEnqueue['queued_jobs'] === 2
        && $metadataBackfillEnqueue['recipe_count'] === 4
        && $metadataBackfillEnqueue['jobs'][0]['scheduled_at'] === null
        && $metadataBackfillEnqueue['jobs'][1]['scheduled_at'] !== null
        && strtotime($metadataBackfillEnqueue['jobs'][1]['scheduled_at'])
            >= time() + 60
        && $metadataBackfillEnqueue['next_cursor']
            === max(array_column(
                recipeCookidooMetadataBackfillCandidates($db, 'en-GB', 0, 4),
                'origin_id'
            ))
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_jobs
             WHERE job_type = 'recipe_metadata_refresh'"
        ) === $metadataJobsBefore + 2,
        'Metadata backfill enqueue must use bounded stable jobs and advance its checkpoint'
    );
    $metadataJobIds = array_column($metadataBackfillEnqueue['jobs'], 'id');
    $db->prepare("
        UPDATE recipe_catalog
        SET stale_at = datetime('now', '-1 day')
        WHERE id = ?
    ")->execute([(int)$legacyMetadataIds[0]]);
    $metadataBatchScoreStateBefore = recipeScoreState($db);
    $metadataBatchRevisionsBefore = $db->query("
        SELECT *
        FROM recipe_score_revisions
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $metadataBatchScoresBefore = $db->query("
        SELECT *
        FROM recipe_inventory_scores
        WHERE recipe_id IN ({$legacyMetadataIdList})
        ORDER BY score_revision_id, recipe_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $db->prepare("
        UPDATE recipe_jobs SET
            max_attempts = 1,
            next_retry_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$metadataJobIds[1]]);
    $metadataBatchFirstPass = recipeJobProcessQueueBatch($db, 2, 3, true);
    $metadataStatusAfterFailure = recipeCookidooMetadataBackfillStatus(
        $db,
        'en-GB'
    );
    $mixedMetadataResult = $metadataBatchFirstPass['items'][0]['result'] ?? [];
    $metadataBatchScoreStateAfter = recipeScoreState($db);
    $metadataBatchExpectedScoreState = $metadataBatchScoreStateBefore;
    recipeTestAssert(
        $metadataBatchFirstPass['succeeded'] === 1
        && $metadataBatchFirstPass['failed'] === 1
        && ($mixedMetadataResult['succeeded_count'] ?? null) === 1
        && ($mixedMetadataResult['failed_count'] ?? null) === 2
        && ($mixedMetadataResult['succeeded_external_ids'] ?? null)
            === ['legacy-v2-a']
        && ($mixedMetadataResult['failed_external_ids'] ?? null)
            === ['legacy-v2-b', 'legacy-v2-c']
        && array_column(
            $mixedMetadataResult['failures'] ?? [],
            'error_kind'
        ) === ['not_found', 'invalid_metadata']
        && ($mixedMetadataResult['metadata_schema_version'] ?? null)
            === RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION
        && ($mixedMetadataResult['mapping_version'] ?? null)
            === RECIPE_SOURCE_MAPPING_VERSION_LEGACY
        && ($mixedMetadataResult['source_group_counts'][
            (int)$legacyMetadataIds[0]
        ] ?? null) === 1
        && ($mixedMetadataResult['distinct_unit_strings'] ?? null)
            === ['pieces']
        && ($mixedMetadataResult['null_quantity_count'] ?? null) === 0
        && ($mixedMetadataResult['range_quantity_count'] ?? null) === 1
        && ($mixedMetadataResult['topology_recipe_count'] ?? null) === 1
        && ($mixedMetadataResult['topology_metrics'] ?? null) === [
            'group_count' => 1,
            'group_title_key_count' => 1,
            'group_title_nonempty_count' => 1,
            'group_title_length_total' => 16,
            'group_title_length_max' => 16,
            'ingredient_count' => 1,
            'ingredient_ref_key_count' => 1,
            'ingredient_ref_nonempty_count' => 1,
            'default_title_key_count' => 1,
            'default_title_nonempty_count' => 1,
            'unit_ref_key_count' => 1,
            'unit_ref_nonempty_count' => 1,
            'optional_key_count' => 1,
            'optional_true_count' => 0,
            'optional_false_count' => 1,
            'optional_null_count' => 0,
            'shopping_category_ref_key_count' => 1,
            'shopping_category_ref_nonempty_count' => 1,
        ]
        && ($mixedMetadataResult['topology_rates'][
            'group_title_nonempty_rate'
        ] ?? null) === 1.0
        && ($mixedMetadataResult['topology_rates'][
            'optional_false_rate'
        ] ?? null) === 1.0
        && ($mixedMetadataResult['failure_kind_counts'] ?? null) === [
            'invalid_metadata' => 1,
            'not_found' => 1,
        ]
        && ($mixedMetadataResult['response_bytes'] ?? 0) > 0
        && ($mixedMetadataResult['latency_ms'] ?? -1) >= 0
        && ($mixedMetadataResult['revision_invariants']['preserved']
            ?? false) === true
        && $metadataStatusAfterFailure['origins']['current'] >= 1
        && $metadataStatusAfterFailure['origins']['failed'] >= 2
        && $metadataStatusAfterFailure['origins']['remaining'] >= 1
        && max($directMetadataBatchSizes) <= 20,
        'Metadata refresh jobs must persist ordered mixed outcomes while transient batches remain resumable: '
            . json_encode([
                'result' => $mixedMetadataResult,
                'status' => $metadataStatusAfterFailure,
            ])
    );
    recipeTestAssert(
        $metadataBatchScoreStateAfter['active_score_revision_id']
            === $metadataBatchExpectedScoreState[
                'active_score_revision_id'
            ]
        && $metadataBatchScoreStateAfter['inventory_revision']
            === $metadataBatchExpectedScoreState['inventory_revision']
        && $metadataBatchScoreStateAfter['catalog_revision']
            === $metadataBatchExpectedScoreState['catalog_revision']
        && $metadataBatchScoreStateAfter['cursor_revision']
            === $metadataBatchExpectedScoreState['cursor_revision']
        && $metadataBatchScoreStateAfter['ontology_source_revision']
            > $metadataBatchScoreStateBefore[
                'ontology_source_revision'
            ]
        && $metadataBatchScoreStateAfter['ontology_source_hash'] === ''
        && $db->query("
            SELECT *
            FROM recipe_score_revisions
            ORDER BY id
        ")->fetchAll(PDO::FETCH_ASSOC) === $metadataBatchRevisionsBefore
        && $db->query("
            SELECT *
            FROM recipe_inventory_scores
            WHERE recipe_id IN ({$legacyMetadataIdList})
            ORDER BY score_revision_id, recipe_id
        ")->fetchAll(PDO::FETCH_ASSOC) === $metadataBatchScoresBefore,
        'Metadata freshness refresh must preserve cursor and score revisions'
    );
    $failureCandidateIds = array_column(
        recipeCookidooMetadataBackfillCandidates($db, 'en-GB', 0, 200),
        'external_id'
    );
    recipeTestAssert(
        !in_array('legacy-v2-b', $failureCandidateIds, true)
        && !in_array('legacy-v2-c', $failureCandidateIds, true)
        && in_array('legacy-v2-d', $failureCandidateIds, true),
        'Fresh not-found and parser failures must wait while transient batches remain eligible'
    );
    $legacyFailureOrigins = $db->query("
        SELECT external_id, id, metadata_next_probe_at
        FROM recipe_origins
        WHERE recipe_id IN ({$legacyMetadataIdList})
    ")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $db->prepare("
        UPDATE recipe_origins
        SET metadata_failure_schema_version = 'metadata-parser-v2'
        WHERE id = ?
    ")->execute([(int)$legacyFailureOrigins['legacy-v2-c']['id']]);
    recipeTestAssert(
        in_array(
            'legacy-v2-c',
            array_column(
                recipeCookidooMetadataBackfillCandidates(
                    $db,
                    'en-GB',
                    0,
                    200
                ),
                'external_id'
            ),
            true
        ),
        'Invalid metadata must be reconsidered when the parser schema changes'
    );
    $db->prepare("
        UPDATE recipe_origins
        SET metadata_failure_schema_version = ?
        WHERE id = ?
    ")->execute([
        RECIPE_COOKIDOO_METADATA_FAILURE_SCHEMA_VERSION,
        (int)$legacyFailureOrigins['legacy-v2-c']['id'],
    ]);
    $db->prepare("
        UPDATE recipe_origins
        SET metadata_next_probe_at = datetime('now', '-1 second')
        WHERE id = ?
    ")->execute([(int)$legacyFailureOrigins['legacy-v2-b']['id']]);
    recipeTestAssert(
        in_array(
            'legacy-v2-b',
            array_column(
                recipeCookidooMetadataBackfillCandidates(
                    $db,
                    'en-GB',
                    0,
                    200
                ),
                'external_id'
            ),
            true
        ),
        'Not-found failures must become eligible after their long probe TTL'
    );
    $db->prepare("
        UPDATE recipe_origins
        SET metadata_next_probe_at = ?
        WHERE id = ?
    ")->execute([
        $legacyFailureOrigins['legacy-v2-b']['metadata_next_probe_at'],
        (int)$legacyFailureOrigins['legacy-v2-b']['id'],
    ]);
    $failDirectMetadata = false;
    $metadataBackfillResume = recipeCookidooEnqueueMetadataBackfill(
        $db,
        'en-GB',
        3,
        4
    );
    recipeTestAssert(
        $metadataBackfillResume['wrapped'] === true
        && $metadataBackfillResume['queued_jobs'] === 1
        && $metadataBackfillResume['jobs'][0]['requeued'] === true
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_jobs
             WHERE job_type = 'recipe_metadata_refresh'"
        ) === $metadataJobsBefore + 2,
        'Metadata backfill resume must wrap the checkpoint and requeue the stable failed job without duplicates'
    );
    $metadataResumeScoreStateBefore = recipeScoreState($db);
    $metadataBatchResume = recipeJobProcessQueueBatch($db, 1, 3, true);
    $metadataStatusComplete = recipeCookidooMetadataBackfillStatus($db, 'en-GB');
    $legacyMetadataRows = $db->query("
        SELECT c.id, c.title, c.image_url, c.yield_quantity,
               o.metadata_version, o.metadata_schema_version,
               o.metadata_failure_version,
               o.metadata_failure_kind,
               (SELECT COUNT(*) FROM recipe_source_ingredients rsi
                WHERE rsi.recipe_id = c.id) AS source_count,
               (SELECT COUNT(*) FROM recipe_source_ingredients rsi
                WHERE rsi.recipe_id = c.id
                  AND rsi.source_ingredient_ref IS NOT NULL
                  AND rsi.source_default_title IS NOT NULL
                  AND rsi.source_unit_ref IS NOT NULL
                  AND rsi.source_optional IS NOT NULL
                  AND rsi.source_shopping_category_ref IS NOT NULL
               ) AS topology_count
        FROM recipe_catalog c
        JOIN recipe_origins o ON o.recipe_id = c.id
        WHERE c.id IN ({$legacyMetadataIdList})
        ORDER BY c.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $rejectedEmptyMetadataDetail = recipeCatalogDetail(
        $db,
        (int)$legacyMetadataIds[3]
    );
    $resumeMetadataResult = $metadataBatchResume['items'][0]['result'] ?? [];
    recipeTestAssert(
        $metadataBatchResume['succeeded'] === 1
        && ($resumeMetadataResult['succeeded_count'] ?? null) === 0
        && ($resumeMetadataResult['failed_count'] ?? null) === 1
        && ($resumeMetadataResult['failed_external_ids'] ?? null)
            === ['legacy-v2-d']
        && $metadataStatusComplete['origins']['remaining'] === 0
        && $metadataStatusComplete['origins']['failed'] >= 2
        && $directMetadataRequestIds === [
            ['legacy-v2-a', 'legacy-v2-b', 'legacy-v2-c'],
            ['legacy-v2-d'],
            ['legacy-v2-d'],
        ]
        && array_column($legacyMetadataRows, 'title') === [
            'Legacy Metadata V1 A',
            'Legacy Metadata V1 B',
            'Legacy Metadata V1 C',
            'Legacy Metadata V1 D',
        ]
        && array_column($legacyMetadataRows, 'image_url') === [
            'https://assets.tmecosys.com/image/upload/legacy-v1-a.jpg',
            'https://assets.tmecosys.com/image/upload/legacy-v1-b.jpg',
            'https://assets.tmecosys.com/image/upload/legacy-v1-c.jpg',
            'https://assets.tmecosys.com/image/upload/legacy-v1-d.jpg',
        ]
        && array_column($legacyMetadataRows, 'metadata_version') === [
            RECIPE_COOKIDOO_METADATA_VERSION,
            null,
            null,
            null,
        ]
        && array_column(
            $legacyMetadataRows,
            'metadata_schema_version'
        ) === [
            RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
            null,
            null,
            null,
        ]
        && array_column($legacyMetadataRows, 'metadata_failure_kind') === [
            null,
            'not_found',
            'invalid_metadata',
            'invalid_metadata',
        ]
        && array_map(
            'intval',
            array_column($legacyMetadataRows, 'source_count')
        ) === [1, 1, 1, 1]
        && array_map(
            'intval',
            array_column($legacyMetadataRows, 'topology_count')
        ) === [1, 0, 0, 0]
        && $legacyMetadataRows[3]['yield_quantity'] === null
        && array_column(
            $rejectedEmptyMetadataDetail['ingredients'],
            'source_text'
        ) === ['Legacy Source D']
        && count($rejectedEmptyMetadataDetail['ingredient_groups']) === 1,
        'Direct-ID refresh must not re-fetch terminal siblings and must retain '
            . 'bounded failures without erasing source rows on empty metadata'
    );
    recipeTestAssert(
        recipeScoreState($db) === $metadataResumeScoreStateBefore,
        'Fresh-to-fresh metadata job batches must not invalidate cursors'
    );
    recipeTestAssert(
        $metadataStatusComplete['metadata_schema_version']
            === RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION
        && $metadataStatusComplete['mapping_version']
            === RECIPE_SOURCE_MAPPING_VERSION_LEGACY
        && $metadataStatusComplete['failure_schema_version']
            === RECIPE_COOKIDOO_METADATA_FAILURE_SCHEMA_VERSION
        && $metadataStatusComplete['source_metrics']['group_count'] >= 2
        && in_array(
            'pieces',
            $metadataStatusComplete['source_metrics'][
                'distinct_unit_strings'
            ],
            true
        )
        && $metadataStatusComplete['recent_job_observability'][
            'response_bytes'
        ] > 0
        && $metadataStatusComplete['recent_job_observability'][
            'revision_invariant_failures'
        ] === 0
        && $metadataStatusComplete['source_metrics']['topology'][
            'labeled_group_count'
        ] >= 1
        && $metadataStatusComplete['source_metrics']['topology'][
            'default_title_count'
        ] >= 1
        && $metadataStatusComplete['source_metrics']['topology'][
            'unit_ref_count'
        ] >= 1
        && $metadataStatusComplete['recent_job_observability'][
            'topology'
        ]['group_title_key_count'] >= 1
        && $metadataStatusComplete['recent_job_observability'][
            'topology'
        ]['optional_false_count'] >= 1
        && $metadataStatusComplete['pilot_controls']['ladder']
            === [1, 5, 10, 20, 200]
        && $metadataStatusComplete['pilot_controls']['detail_concurrency']
            === 1
        && $metadataStatusComplete['pilot_controls'][
            'nightly_window_required'
        ] === true,
        'Backfill status must expose bounded pilot metrics without recipe content'
    );

    $permanentFailureRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Permanent Failure Reconsideration',
        'source_ingredients' => [],
    ], [
        'connector' => 'cookidoo',
        'external_id' => 'permanent-failure-old',
        'canonical_url' => (
            'https://cookidoo.co.uk/recipes/recipe/en-GB/'
            . 'permanent-failure-old'
        ),
        'locale' => 'en-GB',
    ]);
    $permanentOriginId = (int)$db->query("
        SELECT id FROM recipe_origins
        WHERE recipe_id = " . (int)$permanentFailureRecipe['id'] . "
          AND connector = 'cookidoo'
    ")->fetchColumn();
    $db->prepare("
        UPDATE recipe_origins
        SET metadata_version = NULL
        WHERE id = ?
    ")->execute([$permanentOriginId]);
    $invalidIdFailure = recipeCookidooRecordMetadataFailure(
        $db,
        (int)$permanentFailureRecipe['id'],
        $permanentOriginId,
        'permanent-failure-old',
        'en-GB',
        'invalid_id'
    );
    $db->prepare("
        UPDATE recipe_origins SET
            metadata_next_probe_at = datetime('now', '-1 day'),
            metadata_failure_schema_version = 'old-parser'
        WHERE id = ?
    ")->execute([$permanentOriginId]);
    recipeTestAssert(
        $invalidIdFailure['failure_count'] === 1
        && $invalidIdFailure['next_probe_at'] === null
        && !in_array(
            'permanent-failure-old',
            array_column(
                recipeCookidooMetadataBackfillCandidates(
                    $db,
                    'en-GB',
                    0,
                    200
                ),
                'external_id'
            ),
            true
        ),
        'Invalid IDs must remain blocked regardless of TTL or parser changes'
    );
    recipeTestAssert(
        recipeCookidooResetMetadataFailure($db, $permanentOriginId)
        && in_array(
            'permanent-failure-old',
            array_column(
                recipeCookidooMetadataBackfillCandidates(
                    $db,
                    'en-GB',
                    0,
                    200
                ),
                'external_id'
            ),
            true
        ),
        'Manual failure reset must make a permanent origin eligible again'
    );
    $firstParserFailure = recipeCookidooRecordMetadataFailure(
        $db,
        (int)$permanentFailureRecipe['id'],
        $permanentOriginId,
        'permanent-failure-old',
        'en-GB',
        'invalid_metadata'
    );
    $secondParserFailure = recipeCookidooRecordMetadataFailure(
        $db,
        (int)$permanentFailureRecipe['id'],
        $permanentOriginId,
        'permanent-failure-old',
        'en-GB',
        'invalid_metadata'
    );
    recipeTestAssert(
        $firstParserFailure['failure_count'] === 1
        && $secondParserFailure['failure_count'] === 2
        && strtotime((string)$secondParserFailure['next_probe_at'])
            > strtotime((string)$firstParserFailure['next_probe_at']),
        'Parser failures must use bounded increasing reconsideration TTLs'
    );
    recipeCatalogSaveVariant($db, [
        'title' => 'Permanent Failure Reconsideration',
        'source_ingredients' => [],
    ], [
        'recipe_id' => (int)$permanentFailureRecipe['id'],
        'connector' => 'cookidoo',
        'external_id' => 'permanent-failure-new',
        'canonical_url' => (
            'https://cookidoo.co.uk/recipes/recipe/en-GB/'
            . 'permanent-failure-new'
        ),
        'locale' => 'en-GB',
    ]);
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_origins
             WHERE id = ?
               AND external_id = 'permanent-failure-new'
               AND metadata_failure_kind IS NULL
               AND metadata_failure_count = 0
               AND metadata_next_probe_at IS NULL",
            [$permanentOriginId]
        ) === 1,
        'Changing an origin identity must clear permanent failure state'
    );
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] = static fn(
        string $url,
        string $token,
        array $payload,
        int $timeout
    ): array => [
        'status' => 429,
        'body' => '{"error":"rate_limited"}',
    ];
    $pilotCircuitBreakRaised = false;
    try {
        recipeCookidooBridgeMetadataBatch($db, [
            'locale' => 'en-GB',
            'recipes' => [[
                'recipe_id' => (int)$permanentFailureRecipe['id'],
                'origin_id' => $permanentOriginId,
                'external_id' => 'permanent-failure-new',
            ]],
        ]);
    } catch (RecipeCookidooCircuitBreakException $e) {
        $pilotCircuitBreakRaised = true;
    }
    recipeTestAssert(
        $pilotCircuitBreakRaised,
        'HTTP 429 metadata responses must trip the pilot circuit break'
    );
    $staleMetadataRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Queued Metadata Delete',
        'source_ingredients' => [[
            'name' => 'Tomato Test',
            'source_quantity' => 1,
            'source_unit' => 'piece',
            'source_amount_text' => '1 piece',
        ]],
    ], [
        'connector' => 'cookidoo',
        'external_id' => 'queued-metadata-delete',
        'canonical_url' => (
            'https://cookidoo.co.uk/recipes/recipe/en-GB/'
            . 'queued-metadata-delete'
        ),
        'locale' => 'en-GB',
    ]);
    $staleMetadataOriginId = (int)$db->query("
        SELECT id
        FROM recipe_origins
        WHERE recipe_id = " . (int)$staleMetadataRecipe['id'] . "
          AND connector = 'cookidoo'
    ")->fetchColumn();
    $db->prepare("
        UPDATE recipe_origins SET
            metadata_version = NULL,
            metadata_schema_version = NULL
        WHERE id = ?
    ")->execute([$staleMetadataOriginId]);
    $staleMetadataEnqueue = recipeCookidooEnqueueMetadataRefreshJob(
        $db,
        [
            'locale' => 'en-GB',
            'recipes' => [[
                'recipe_id' => (int)$staleMetadataRecipe['id'],
                'origin_id' => $staleMetadataOriginId,
                'external_id' => 'queued-metadata-delete',
            ], [
                'recipe_id' => 2147483001,
                'origin_id' => 2147483002,
                'external_id' => 'queued-metadata-missing',
            ]],
        ]
    );
    $db->prepare("
        UPDATE recipe_jobs
        SET priority = 10000
        WHERE id = ?
    ")->execute([(int)$staleMetadataEnqueue['job']['id']]);
    recipeCatalogDelete($db, (int)$staleMetadataRecipe['id']);
    $connectorStateOriginal = $db->query("
        SELECT last_success_at, last_error, failure_count, circuit_open_until
        FROM recipe_connector_state
        WHERE connector = 'cookidoo'
    ")->fetch(PDO::FETCH_ASSOC);
    $db->exec("
        UPDATE recipe_connector_state SET
            last_error = 'preexisting stale target state',
            failure_count = 2,
            circuit_open_until = datetime('now', '+30 minutes')
        WHERE connector = 'cookidoo'
    ");
    $connectorStateBeforeStale = $db->query("
        SELECT last_success_at, last_error, failure_count, circuit_open_until
        FROM recipe_connector_state
        WHERE connector = 'cookidoo'
    ")->fetch(PDO::FETCH_ASSOC);
    $staleMetadataBridgeCalls = 0;
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] = static function (
        string $url,
        string $token,
        array $payload,
        int $timeout
    ) use (&$staleMetadataBridgeCalls): array {
        $staleMetadataBridgeCalls++;
        return ['status' => 500, 'body' => '{"error":"must_not_call"}'];
    };
    $staleMetadataQueue = recipeJobProcessQueueBatch($db, 1, 3, true);
    $staleMetadataJob = recipeJobGet(
        $db,
        (int)$staleMetadataEnqueue['job']['id']
    );
    $connectorStateAfterStale = $db->query("
        SELECT last_success_at, last_error, failure_count, circuit_open_until
        FROM recipe_connector_state
        WHERE connector = 'cookidoo'
    ")->fetch(PDO::FETCH_ASSOC);
    recipeTestAssert(
        $staleMetadataQueue['processed'] === 1
        && $staleMetadataQueue['skipped'] === 1
        && $staleMetadataBridgeCalls === 0
        && $staleMetadataJob['status'] === 'skipped'
        && ($staleMetadataJob['result']['reason'] ?? null)
            === 'stale_metadata_targets'
        && ($staleMetadataJob['result']['skipped_external_ids'] ?? null)
            === [
                'queued-metadata-delete',
                'queued-metadata-missing',
            ]
        && $connectorStateAfterStale === $connectorStateBeforeStale,
        'Deleted queued metadata targets must terminate locally without connector accounting'
    );
    $db->prepare("
        UPDATE recipe_connector_state SET
            last_success_at = ?,
            last_error = ?,
            failure_count = ?,
            circuit_open_until = ?
        WHERE connector = 'cookidoo'
    ")->execute([
        $connectorStateOriginal['last_success_at'],
        $connectorStateOriginal['last_error'],
        $connectorStateOriginal['failure_count'],
        $connectorStateOriginal['circuit_open_until'],
    ]);
    $GLOBALS['RECIPE_COOKIDOO_CONFIG']['COOKIDOO_METADATA_BACKFILL_ENABLED'] = 'false';
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] = $metadataBridgeTransport;

    $db->prepare("
        UPDATE recipe_catalog SET stale_at = datetime('now', '-1 day')
        WHERE id = ?
    ")->execute([$cookidooRecipeId]);
    recipeTestAssert(
        recipeCatalogTextSearch(
            $db,
            'cloud',
            'cookidoo',
            20,
            0
        )['total'] === 1
        && recipeCatalogGetById(
            $db,
            $cookidooRecipeId
        )['is_stale'],
        'Stale Cookidoo metadata must remain visible and explicitly stale'
    );
    recipeCatalogSetFavorite($db, $cookidooRecipeId, true);
    recipeTestAssert(
        recipeCatalogTextSearch($db, 'cloud', 'cookidoo', 20, 0)['total'] === 1
        && recipeCatalogGetById($db, $cookidooRecipeId)['is_stale'],
        'Favorited Cookidoo metadata must remain visible and explicitly stale'
    );

    $connectors = recipeConnectorsWithState($db);
    $cookidooConnector = array_values(array_filter(
        $connectors,
        static fn(array $connector): bool => $connector['connector'] === 'cookidoo'
    ))[0] ?? null;
    recipeTestAssert(
        $cookidooConnector !== null
        && $cookidooConnector['network'] === true
        && $cookidooConnector['metadata_only'] === true
        && $cookidooConnector['state']['enabled'] === true
        && $cookidooConnector['health']['configured'] === true
        && in_array(
            $cookidooConnector['health']['status'],
            ['configured', 'healthy'],
            true
        )
        && $cookidooConnector['state']['policy_version']
            === RECIPE_COOKIDOO_DETAIL_POLICY_VERSION
        && $cookidooConnector['policy_version']
            === RECIPE_COOKIDOO_DETAIL_POLICY_VERSION
        && in_array(
            'cached_catalog_read',
            $cookidooConnector['capabilities'],
            true
        )
        && in_array(
            'canonical_link',
            $cookidooConnector['capabilities'],
            true
        )
        && in_array(
            'direct_metadata_refresh',
            $cookidooConnector['capabilities'],
            true
        )
        && !in_array('instructions', $cookidooConnector['capabilities'], true),
        'Connector API state must report Cookidoo metadata health without '
            . 'secrets: ' . json_encode($cookidooConnector)
    );

    $defaultLocaleRequest = recipeCookidooNormalizeDiscoveryInput([
        'query' => 'default locale',
        'limit' => 1,
    ]);
    recipeTestAssert(
        $defaultLocaleRequest['locale'] === '',
        'Omitted Cookidoo locale must defer to the bridge default'
    );
    recipeTestAssert(
        recipeCookidooNormalizeLocale('zh-Hans') === 'zh-Hans',
        'Cookidoo locale validation must accept supported script subtags'
    );
    $lastBridgePayload = null;
    recipeCookidooBridgeSearch($db, $defaultLocaleRequest);
    recipeTestAssert(
        !array_key_exists('locale', $lastBridgePayload),
        'Bridge requests must omit an unspecified locale'
    );

    $crawlBridgeCalls = 0;
    $crawlRefreshWithoutExclusion = false;
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] = static function (
        string $url,
        string $token,
        array $payload,
        int $timeout
    ) use (&$crawlBridgeCalls, &$crawlRefreshWithoutExclusion): array {
        $crawlBridgeCalls++;
        $page = (int)($payload['page'] ?? 0);
        recipeTestAssert(
            $payload['limit'] === 20 && $payload['max_pages'] === 1,
            'Full-crawl pages must use one 20-hit bridge page'
        );
        if (empty($payload['exclude_ids'])) {
            $crawlRefreshWithoutExclusion = true;
        } else {
            recipeTestAssert(
                in_array('r-cookidoo-metadata-1', $payload['exclude_ids'], true),
                'Initial full-crawl pages must use global cached-ID exclusion'
            );
        }
        $hadRawHits = $page === 0 || $page === 50;
        return [
            'status' => 200,
            'body' => json_encode([
                'recipes' => [],
                'count' => 0,
                'pages_scanned' => 1,
                'last_page' => $page,
                'next_page' => $page + 1,
                'last_page_had_raw_hits' => $hadRawHits,
                'raw_payload' => ['must never persist'],
            ], JSON_UNESCAPED_SLASHES),
        ];
    };
    $legacyDispatchRequest = recipeCookidooNormalizeDiscoveryInput([
        'query' => 'legacy dispatch migration',
        'locale' => 'en-GB',
        'page' => 50,
        'crawl_all' => true,
    ]);
    $legacyDispatchJob = recipeJobEnqueue(
        $db,
        'connector_discovery',
        [
            'scope' => 'cookidoo:' . substr(
                recipeCookidooDiscoveryHash($legacyDispatchRequest),
                0,
                24
            ),
            'connector' => 'cookidoo',
            'query' => $legacyDispatchRequest['query'],
        ],
        $legacyDispatchRequest,
        recipeCookidooDiscoveryIdempotencyKey(
            $legacyDispatchRequest
        )
    );
    $legacyDispatchOutcome = recipeCookidooDispatchDiscovery(
        $db,
        $legacyDispatchJob,
        $legacyDispatchRequest
    );
    $legacyDispatchMigrated = recipeJobGet(
        $db,
        (int)$legacyDispatchJob['id']
    );
    recipeTestAssert(
        $legacyDispatchMigrated['scope']
            !== recipeCookidooSearchId($legacyDispatchRequest)
        && !isset($legacyDispatchMigrated['payload'][
            RECIPE_COOKIDOO_POLICY_FIELD
        ])
        && $legacyDispatchOutcome['status'] === 'skipped'
        && $legacyDispatchOutcome['result']['reason']
            === 'cookidoo_policy_job_mismatch',
        'A queued legacy discovery job must fail closed without a refresh escape hatch'
    );
    $db->prepare("DELETE FROM recipe_jobs WHERE id = ?")
        ->execute([(int)$legacyDispatchJob['id']]);
    $crawlInput = [
        'query' => 'cached-only crawl',
        'ingredients' => ['Tomato Test'],
        'locale' => 'en-GB',
        'tmv' => 'TM6',
        'crawl_all' => true,
    ];
    $crawlDiscovery = recipeCookidooDiscover($db, $crawlInput);
    recipeJobProcessQueueBatch($db, 1, 3);
    $crawlRoot = recipeJobGet($db, (int)$crawlDiscovery['job']['id']);
    recipeTestAssert(
        $crawlRoot['status'] === 'done'
        && $crawlRoot['result']['page'] === 0
        && $crawlRoot['result']['last_page_had_raw_hits'] === true
        && $crawlRoot['result']['next_page_enqueued'] === true
        && $crawlRoot['result']['imported_ids'] === [],
        'A raw page containing only cached recipes must still advance the crawl'
    );
    $pageOneRequest = recipeCookidooNormalizeDiscoveryInput($crawlInput + ['page' => 1]);
    $pageOneKey = recipeCookidooDiscoveryIdempotencyKey($pageOneRequest);
    $pageOneJob = recipeJobGet($db, null, $pageOneKey);
    recipeTestAssert(
        $pageOneJob !== null
        && $pageOneJob['payload']['page'] === 1
        && $pageOneJob['scope'] === $crawlRoot['scope']
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_jobs WHERE idempotency_key = ?",
            [$pageOneKey]
        ) === 1,
        'Crawl chaining must create one stable idempotent job per page'
    );
    $legacyPagePayload = $pageOneJob['payload'];
    unset($legacyPagePayload[RECIPE_COOKIDOO_POLICY_FIELD]);
    $db->prepare("
        UPDATE recipe_jobs
        SET scope = ?,
            payload_json = ?,
            status = 'skipped'
        WHERE id = ?
    ")->execute([
        'cookidoo:' . substr(
            recipeCookidooDiscoveryHash($pageOneRequest),
            0,
            24
        ),
        recipeCatalogJsonEncode($legacyPagePayload),
        (int)$pageOneJob['id'],
    ]);
    $legacyContinuation = recipeCookidooEnqueueNextCrawlPage(
        $db,
        $crawlInput,
        [
            'last_page_had_raw_hits' => true,
            'next_page' => 1,
        ],
        false
    );
    $pageOneJob = recipeJobGet($db, (int)$pageOneJob['id']);
    recipeTestAssert(
        !empty($legacyContinuation['next_page_migrated'])
        && $pageOneJob['status'] === 'pending'
        && $pageOneJob['scope'] === $crawlRoot['scope']
        && $pageOneJob['payload'][
            RECIPE_COOKIDOO_POLICY_FIELD
        ] === RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
        'A skipped legacy crawl continuation must migrate and requeue under the root search scope'
    );
    $repeatCrawl = recipeCookidooDiscover($db, $crawlInput);
    recipeTestAssert(
        $repeatCrawl['job']['id'] === $crawlRoot['id']
        && recipeJobGet($db, $crawlRoot['id'])['status'] === 'done',
        'Repeating a full-crawl request must not restart an existing page job'
    );
    recipeJobProcessQueueBatch($db, 1, 3);
    $pageOneJob = recipeJobGet($db, (int)$pageOneJob['id']);
    $pageTwoRequest = recipeCookidooNormalizeDiscoveryInput($crawlInput + ['page' => 2]);
    recipeTestAssert(
        $pageOneJob['status'] === 'done'
        && $pageOneJob['result']['last_page_had_raw_hits'] === false
        && $pageOneJob['result']['crawl_complete'] === true
        && $pageOneJob['result']['stop_reason'] === 'empty_page'
        && recipeJobGet(
            $db,
            null,
            recipeCookidooDiscoveryIdempotencyKey($pageTwoRequest)
        ) === null,
        'An empty raw Cookidoo page must stop the persistent crawl'
    );

    $legacyPeriodicRequest = recipeCookidooNormalizeDiscoveryInput([
        'query' => 'legacy periodic root',
        'locale' => 'en-GB',
        'crawl_all' => true,
    ]);
    $legacyPeriodicPayload = $legacyPeriodicRequest;
    $legacyPeriodicPayload[RECIPE_COOKIDOO_POLICY_FIELD] =
        RECIPE_COOKIDOO_DETAIL_POLICY_VERSION;
    $legacyPeriodicJob = recipeJobEnqueue(
        $db,
        'connector_discovery',
        [
            'scope' => recipeCookidooSearchId(
                $legacyPeriodicRequest
            ),
            'connector' => 'cookidoo',
            'query' => $legacyPeriodicRequest['query'],
        ],
        $legacyPeriodicPayload,
        recipeCookidooDiscoveryIdempotencyKey(
            $legacyPeriodicRequest
        )
    );
    $db->prepare("
        UPDATE recipe_jobs
        SET status = 'skipped',
            updated_at = datetime('now', '-30 days')
        WHERE id = ?
    ")->execute([(int)$legacyPeriodicJob['id']]);
    $db->prepare("
        UPDATE recipe_jobs
        SET payload_json = json_set(
                payload_json,
                '$." . RECIPE_COOKIDOO_POLICY_FIELD . "',
                ?
            ),
            updated_at = CURRENT_TIMESTAMP
        WHERE connector = 'cookidoo' AND id <> ?
    ")->execute([
        RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
        (int)$legacyPeriodicJob['id'],
    ]);
    $legacyPeriodicRefresh =
        recipeCookidooEnqueuePeriodicRefreshes($db, 1);
    $legacyPeriodicSource = recipeJobGet(
        $db,
        (int)$legacyPeriodicJob['id']
    );
    recipeTestAssert(
        $legacyPeriodicRefresh['queued'] === 1
        && $legacyPeriodicRefresh['legacy_migrated'] === 0
        && $legacyPeriodicSource['payload'][
            RECIPE_COOKIDOO_POLICY_FIELD
        ] === RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
        'Persisted v3 jobs must remain eligible without legacy migration: '
            . json_encode([
                'refresh' => $legacyPeriodicRefresh,
                'source' => $legacyPeriodicSource,
            ])
    );
    $legacyPeriodicRefreshId =
        (int)$legacyPeriodicRefresh['jobs'][0];
    recipeJobProcessQueueBatch($db, 1, 3);
    recipeTestAssert(
        recipeJobGet($db, $legacyPeriodicRefreshId)['status']
            === 'done',
        'Migrated legacy refresh work must execute as one bounded page'
    );
    $db->prepare("DELETE FROM recipe_jobs WHERE id IN (?, ?)")
        ->execute([
            (int)$legacyPeriodicJob['id'],
            $legacyPeriodicRefreshId,
        ]);

    $db->prepare("
        UPDATE recipe_jobs SET updated_at = datetime('now', '-30 days')
        WHERE id = ?
    ")->execute([(int)$pageOneJob['id']]);
    $nonRootRefresh = recipeCookidooEnqueuePeriodicRefreshes($db, 10);
    recipeTestAssert(
        $nonRootRefresh['queued'] === 0,
        'Periodic refresh must not independently restart non-root crawl pages'
    );
    $db->prepare("
        UPDATE recipe_jobs SET updated_at = datetime('now', '-30 days')
        WHERE id = ?
    ")->execute([(int)$crawlRoot['id']]);
    $rootRefresh = recipeCookidooEnqueuePeriodicRefreshes($db, 1);
    recipeTestAssert(
        $rootRefresh['queued'] === 1
        && $rootRefresh['crawl_refresh_strategy'] === 'page_zero_only',
        'Periodic refresh must enqueue one bounded page-zero refresh'
    );
    $refreshJobId = (int)$rootRefresh['jobs'][0];
    recipeJobProcessQueueBatch($db, 1, 3);
    $refreshJob = recipeJobGet($db, $refreshJobId);
    recipeTestAssert(
        $refreshJob['status'] === 'done'
        && recipeJobGet($db, (int)$pageOneJob['id'])['status']
            === 'done',
        'A periodic refresh must not restart the historical crawl chain'
    );
    recipeTestAssert(
        $crawlRefreshWithoutExclusion,
        'Refresh crawl pages must rehydrate cached metadata instead of excluding it'
    );
    $pageFiftyRequest = recipeCookidooNormalizeDiscoveryInput(
        $crawlInput + ['page' => 50]
    );
    $pageFiftyEnqueue = recipeCookidooEnqueueDiscoveryJob(
        $db,
        $pageFiftyRequest,
        true
    );
    recipeJobProcessQueueBatch($db, 1, 3);
    $pageFiftyJob = recipeJobGet(
        $db,
        (int)$pageFiftyEnqueue['job']['id']
    );
    $pageFiftyOutcome = [
        'status' => $pageFiftyJob['status'],
        'result' => $pageFiftyJob['result'],
    ];
    recipeTestAssert(
        $pageFiftyOutcome['status'] === 'done'
        && $pageFiftyOutcome['result']['next_page'] === 51
        && $pageFiftyOutcome['result']['crawl_complete'] === true
        && $pageFiftyOutcome['result']['stop_reason'] === 'page_limit',
        'A full crawl must stop after Cookidoo page 50 even when it has raw hits'
    );
    recipeTestAssert(
        $crawlBridgeCalls === 5,
        'Crawl tests must execute only their five bounded mocked bridge pages'
    );
    $db->prepare("DELETE FROM recipe_jobs WHERE id IN (?, ?)")
       ->execute([(int)$crawlRoot['id'], (int)$pageOneJob['id']]);
    $db->prepare("DELETE FROM recipe_jobs WHERE id = ?")
       ->execute([(int)$pageFiftyJob['id']]);
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] = $metadataBridgeTransport;

    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    $GLOBALS['RECIPE_API_JSON_INPUT'] = ['connector' => 'cookidoo'];
    recipeCatalogApiRefresh($db);
    $networkRefresh = json_decode((string)ob_get_clean(), true);
    unset($GLOBALS['RECIPE_API_JSON_INPUT']);
    recipeTestAssert(
        http_response_code() === 400
        && ($networkRefresh['error'] ?? '') === 'connector_refresh_unsupported',
        'Network metadata refresh must require rediscovery'
    );
    http_response_code(200);
    unset($GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT']);

    $db->exec("
        INSERT INTO products (name, unit, prepared_food)
        VALUES ('Unit Conversion History Fixture', 'conf', 0)
    ");
    $conversionProductId = (int)$db->lastInsertId();
    $conversionInventoryIds = [];
    foreach ([
        ['dispensa', 2.0],
        ['freezer', 3.0],
    ] as [$conversionLocation, $conversionQuantity]) {
        $db->prepare("
            INSERT INTO inventory (
                product_id, location, quantity, prepared_food
            )
            VALUES (?, ?, ?, 0)
        ")->execute([
            $conversionProductId,
            $conversionLocation,
            $conversionQuantity,
        ]);
        $conversionInventoryId = (int)$db->lastInsertId();
        $conversionInventoryIds[] = $conversionInventoryId;
        $db->prepare("
            INSERT INTO transactions (
                product_id, inventory_id, type, quantity,
                location, notes
            )
            VALUES (?, ?, 'in', ?, ?, '[Original history]')
        ")->execute([
            $conversionProductId,
            $conversionInventoryId,
            $conversionQuantity,
            $conversionLocation,
        ]);
    }
    $conversionBaselines = resetProductUnitConversionHistory(
        $db,
        $conversionProductId
    );
    recipeTestAssert(
        $conversionBaselines === 2
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM transactions
             WHERE product_id = ?
               AND notes = '[Original history]'
               AND undone = 1
               AND undo_safe = 0",
            [$conversionProductId]
        ) === 2
        && recipeTestCount(
            $db,
            "SELECT COUNT(DISTINCT inventory_id)
             FROM transactions
             WHERE product_id = ?
               AND notes = '[Unit conversion baseline]'
               AND undone = 0",
            [$conversionProductId]
        ) === 2,
        'Unit conversion must preserve a baseline for every positive lot'
    );

    $inventory = recipeInventoryCandidates($db);
    $completeRank = recipeCatalogRankRecipe($db, recipeCatalogGetById($db, $variantOne['id']), $inventory, 'expiring');
    recipeTestAssert(
        recipeCatalogExpiryUrgency(9) > recipeCatalogExpiryUrgency(21)
        && recipeCatalogExpiryUrgency(21) > recipeCatalogExpiryUrgency(60)
        && recipeCatalogExpiryUrgency(60) > 0,
        'Expiry urgency must decay continuously without a hard day cutoff'
    );
    $farInventory = array_map(static function (array $candidate): array {
        $candidate['days_remaining'] = 300;
        return $candidate;
    }, $inventory);
    $stockedCurrent = recipeCatalogRankRecipe(
        $db,
        recipeCatalogGetById($db, $variantOne['id']),
        $inventory,
        'stocked'
    );
    $stockedFar = recipeCatalogRankRecipe(
        $db,
        recipeCatalogGetById($db, $variantOne['id']),
        $farInventory,
        'stocked'
    );
    $expiringFar = recipeCatalogRankRecipe(
        $db,
        recipeCatalogGetById($db, $variantOne['id']),
        $farInventory,
        'expiring'
    );
    recipeTestAssert(
        abs($stockedCurrent['score'] - $stockedFar['score']) < 0.000001
        && $completeRank['score'] > $expiringFar['score'],
        'Stocked ranking must ignore expiry while expiring ranking uses it'
    );
    $missingRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Tomato and Truffle',
        'ingredients' => [
            ['name' => 'Tomato Test', 'qty' => '1 pz'],
            ['name' => 'Unobtainium Truffle', 'qty' => '1 pz'],
        ],
        'steps' => ['Cook everything.'],
    ], ['connector' => 'manual', 'external_id' => 'missing-required']);
    $missingRank = recipeCatalogRankRecipe($db, $missingRecipe, $inventory, 'expiring');
    recipeTestAssert($completeRank['cookable'], 'Fully stocked recipe should be cookable');
    recipeTestAssert(!$missingRank['cookable'], 'Missing required ingredient must gate cookability');
    recipeTestAssert($completeRank['score'] > $missingRank['score'], 'Expiry must not lift an un-cookable recipe');

    $db->prepare("
        INSERT INTO inventory (product_id, location, quantity, expiry_date, prepared_food)
        VALUES (?, 'frigo', 4, ?, 0), (?, 'frigo', 5, ?, 0)
    ")->execute([
        $tomatoProduct,
        date('Y-m-d', strtotime('+1 day')),
        $tomatoProduct,
        date('Y-m-d', strtotime('+2 days')),
    ]);
    $inventory = recipeInventoryCandidates($db);
    $aggregateRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Ten Tomatoes',
        'ingredients' => [['name' => 'Tomato Test', 'qty' => '10 pz']],
        'steps' => ['Use all tomatoes.'],
    ], ['connector' => 'manual', 'external_id' => 'ten-tomatoes']);
    $aggregateRank = recipeCatalogRankRecipe($db, $aggregateRecipe, $inventory);
    recipeTestAssert($aggregateRank['cookable'], 'Compatible stock rows must aggregate for quantity checks');
    $aggregateDetail = recipeCatalogDetail($db, (int)$aggregateRecipe['id']);
    recipeTestAssert(
        $aggregateDetail['ingredients'][0]['inventory']['quantity_state'] === 'known'
        && $aggregateDetail['ingredients'][0]['inventory']['quantity_sufficiency'] === 'sufficient'
        && $aggregateDetail['capabilities']['quantities'] === 'known',
        'Detail may report sufficiency only for genuinely known ranking quantity data'
    );
    $db->exec("
        INSERT INTO products (
            name, unit, default_quantity, prepared_food
        )
        VALUES ('Tomato Test Sauce', 'pz', 1, 0)
    ");
    $tomatoSauceProduct = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO inventory (
            product_id, location, quantity, prepared_food
        )
        VALUES (?, 'dispensa', 100, 0)
    ")->execute([$tomatoSauceProduct]);
    $inventory = recipeInventoryCandidates($db);
    $insufficientRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Eleven Tomatoes',
        'ingredients' => [['name' => 'Tomato Test', 'qty' => '11 pz']],
        'steps' => ['Use eleven tomatoes.'],
    ], ['connector' => 'manual', 'external_id' => 'eleven-tomatoes']);
    recipeTestAssert(
        !recipeCatalogRankRecipe($db, $insufficientRecipe, $inventory)['cookable'],
        'Non-satisfying name matches must not inflate available quantities'
    );
    $insufficientDetail = recipeCatalogDetail($db, (int)$insufficientRecipe['id']);
    recipeTestAssert(
        $insufficientDetail['ingredients'][0]['inventory']['state'] === 'missing'
        && $insufficientDetail['ingredients'][0]['inventory']['quantity_sufficiency']
            === 'insufficient',
        'Known insufficient ranking quantities must remain distinct from display-only amounts'
    );
    $unitMismatchRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Tomato by Weight',
        'ingredients' => [['name' => 'Tomato Test', 'qty' => '1 g']],
        'steps' => ['Use tomato by weight.'],
    ], ['connector' => 'manual', 'external_id' => 'tomato-unit-mismatch']);
    recipeTestAssert(
        !recipeCatalogRankRecipe($db, $unitMismatchRecipe, $inventory)['cookable'],
        'Recognized incompatible units must not count as sufficient'
    );

    $canonicalInsert->execute(['rice-package-test', 'Rice Package Test']);
    $riceCanonical = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO taxonomy_nodes (tree_id, slug, name)
        VALUES (?, 'rice-package-test', 'Rice Package Test')
    ")->execute([$treeId]);
    $riceNode = (int)$db->lastInsertId();
    $closure->execute([$treeId, $riceNode, $riceNode, 0]);
    $db->prepare("
        INSERT INTO products (
            name, unit, default_quantity, package_unit, prepared_food
        )
        VALUES ('Rice Package Test', 'conf', 500, 'g', 0)
    ")->execute();
    $riceProduct = (int)$db->lastInsertId();
    $mappingInsert->execute([$riceProduct, $riceCanonical]);
    $db->prepare("
        INSERT INTO inventory (product_id, location, quantity, prepared_food)
        VALUES (?, 'dispensa', 1, 0)
    ")->execute([$riceProduct]);
    $riceRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Packaged Rice Quantity',
        'ingredients' => [['name' => 'Rice Package Test', 'qty' => '500 g']],
        'steps' => ['Cook rice.'],
    ], ['connector' => 'manual', 'external_id' => 'packaged-rice-quantity']);
    recipeTestAssert(
        recipeCatalogRankRecipe(
            $db,
            $riceRecipe,
            recipeInventoryCandidates($db)
        )['cookable'],
        'Package counts must convert through default_quantity and package_unit'
    );
    $ricePackageRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'One Rice Package',
        'ingredients' => [['name' => 'Rice Package Test', 'qty' => '1 conf']],
        'steps' => ['Use one package.'],
    ], ['connector' => 'manual', 'external_id' => 'one-rice-package']);
    recipeTestAssert(
        recipeCatalogRankRecipe(
            $db,
            $ricePackageRecipe,
            recipeInventoryCandidates($db)
        )['cookable'],
        'Package stock must remain comparable when the recipe also requests packages'
    );
    $piecePackageIngredient = [
        'name' => 'Egg Package Test',
        'qty' => '2 pz',
        'qty_number' => 2,
        'inventory_unit' => 'conf',
        'default_quantity' => 6,
        'package_unit' => 'pz',
    ];
    recipeFinalizeIngQty($piecePackageIngredient, 1);
    recipeTestAssert(
        abs((float)$piecePackageIngredient['qty_number'] - 2.0) < 0.0001
        && abs((float)$piecePackageIngredient['stock_have'] - 6.0) < 0.0001
        && abs((float)$piecePackageIngredient['stock_remain'] - 4.0) < 0.0001
        && $piecePackageIngredient['stock_unit'] === 'pz',
        'Piece-based packages must persist and display quantities in pieces'
    );

    $db->prepare("
        INSERT INTO taxonomy_nodes (tree_id, slug, name)
        VALUES (?, 'onion-test', 'Onion Test')
    ")->execute([$treeId]);
    $onionNode = (int)$db->lastInsertId();
    $closure->execute([$treeId, $onionNode, $onionNode, 0]);
    $canonicalInsert->execute(['onion-test', 'Onion Test']);
    $onionCanonical = (int)$db->lastInsertId();
    $productInsert->execute(['Onion Sauce Test', 0]);
    $onionSauceProduct = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO product_ingredients (product_id, ingredient_id, role, confidence, source)
        VALUES (?, ?, 'contains', 1, 'test')
    ")->execute([$onionSauceProduct, $onionCanonical]);
    $inventoryInsert->execute([
        $onionSauceProduct,
        date('Y-m-d', strtotime('+3 days')),
        0,
    ]);
    $containsRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Raw Onion Test',
        'ingredients' => [['name' => 'Onion Test', 'qty' => '1 pz']],
        'steps' => ['Slice the onion.'],
    ], ['connector' => 'manual', 'external_id' => 'contains-required']);
    recipeTestAssert(
        !recipeCatalogRankRecipe($db, $containsRecipe, recipeInventoryCandidates($db))['cookable'],
        'Contains mappings must not satisfy required raw ingredients'
    );
    $sourceContainsRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Source Raw Onion Test',
        'source_ingredients' => [[
            'name' => 'Onion Test',
            'source_quantity' => 1,
            'source_unit' => 'piece',
            'source_amount_text' => '1 piece',
        ]],
    ], [
        'connector' => 'cookidoo',
        'external_id' => 'source-contains-required',
        'canonical_url' => 'https://cookidoo.co.uk/recipes/recipe/en-GB/source-contains-required',
        'locale' => 'en-GB',
    ]);
    $sourceTaxonomyRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Source Vegetable Test',
        'source_ingredients' => [['name' => 'Vegetable Test']],
    ], [
        'connector' => 'cookidoo',
        'external_id' => 'source-taxonomy-required',
        'canonical_url' => 'https://cookidoo.co.uk/recipes/recipe/en-GB/source-taxonomy-required',
        'locale' => 'en-GB',
    ]);
    $sourceContainsDetail = recipeCatalogDetail($db, (int)$sourceContainsRecipe['id']);
    $sourceTaxonomyDetail = recipeCatalogDetail($db, (int)$sourceTaxonomyRecipe['id']);
    recipeTestAssert(
        $sourceContainsDetail['ingredients'][0]['inventory']['state'] === 'uncertain'
        && $sourceContainsDetail['ingredients'][0]['inventory']['matched_product']
            === null
        && $sourceContainsDetail['ingredients'][0]['inventory']['relation']
            === null
        && $sourceTaxonomyDetail['ingredients'][0]['inventory']['state'] === 'in_stock'
        && $sourceTaxonomyDetail['ingredients'][0]['inventory']['relation']
            === 'pantry_descendant',
        'Metadata-v2 source ingredients must default to required while preserving approved taxonomy matches'
    );
    $db->prepare("
        INSERT INTO taxonomy_nodes (tree_id, slug, name)
        VALUES (?, 'olive-public-test', 'Olive Public Test')
    ")->execute([$treeId]);
    $olivePublicNode = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO taxonomy_nodes (tree_id, slug, name)
        VALUES (?, 'garlic-public-test', 'Garlic Public Test')
    ")->execute([$treeId]);
    $garlicPublicNode = (int)$db->lastInsertId();
    $closure->execute([
        $treeId,
        $olivePublicNode,
        $olivePublicNode,
        0,
    ]);
    $closure->execute([
        $treeId,
        $garlicPublicNode,
        $garlicPublicNode,
        0,
    ]);
    $closure->execute([
        $treeId,
        $olivePublicNode,
        $garlicPublicNode,
        1,
    ]);
    $canonicalInsert->execute([
        'olive-public-test',
        'Olive Public Test',
    ]);
    $olivePublicCanonical = (int)$db->lastInsertId();
    $productInsert->execute([
        'Whole Foods Organic Kalamata Pitted Olives',
        0,
    ]);
    $olivePublicProduct = (int)$db->lastInsertId();
    $mappingInsert->execute([
        $olivePublicProduct,
        $olivePublicCanonical,
    ]);
    $inventoryInsert->execute([
        $olivePublicProduct,
        date('Y-m-d', strtotime('+7 days')),
        0,
    ]);
    $garlicPublicRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Garlic Public Projection Test',
        'ingredients' => [[
            'name' => 'garlic clove',
            'qty' => '1 pz',
        ]],
        'steps' => ['Use the garlic.'],
    ], [
        'connector' => 'manual',
        'external_id' => 'garlic-public-projection',
    ]);
    $garlicPublicIngredientId = (int)$db->query("
        SELECT id FROM recipe_ingredients
        WHERE recipe_id = " . (int)$garlicPublicRecipe['id'] . "
        ORDER BY id LIMIT 1
    ")->fetchColumn();
    $db->prepare("
        UPDATE recipe_ingredients
        SET taxonomy_node_id = ?, canonical_ingredient_id = NULL,
            mapping_confidence = 1, mapping_source = 'taxonomy_alias'
        WHERE id = ?
    ")->execute([
        $garlicPublicNode,
        $garlicPublicIngredientId,
    ]);
    $garlicPublicIngredients = recipeDetailLoadIngredients(
        $db,
        (int)$garlicPublicRecipe['id']
    )['rows'];
    $garlicPublicInventory = recipeInventoryCandidates($db);
    $garlicPublicRelations = recipeTaxonomyRelationMap(
        $db,
        $garlicPublicIngredients,
        $garlicPublicInventory
    );
    $garlicInternalCandidate = recipeIngredientBestInventoryMatch(
        $db,
        $garlicPublicIngredients[0],
        $garlicPublicInventory,
        $garlicPublicRelations,
        true
    );
    $garlicPublicDetail = recipeCatalogDetail(
        $db,
        (int)$garlicPublicRecipe['id']
    );
    recipeTestAssert(
        $garlicInternalCandidate !== null
        && $garlicInternalCandidate['relation'] === 'pantry_ancestor'
        && $garlicInternalCandidate['product_name']
            === 'Whole Foods Organic Kalamata Pitted Olives'
        && $garlicPublicDetail['ingredients'][0]['inventory']['state']
            === 'uncertain'
        && $garlicPublicDetail['ingredients'][0]['inventory']['matched_product']
            === null
        && $garlicPublicDetail['ingredients'][0]['inventory']['relation']
            === null,
        'An internal olive pantry-ancestor candidate must never be exposed '
            . 'as the public garlic match'
    );
    $db->prepare("DELETE FROM recipe_catalog WHERE id = ?")
        ->execute([(int)$garlicPublicRecipe['id']]);
    $db->prepare("DELETE FROM products WHERE id = ?")
        ->execute([$olivePublicProduct]);
    $db->prepare("DELETE FROM recipe_catalog WHERE id IN (?, ?)")
        ->execute([(int)$sourceContainsRecipe['id'], (int)$sourceTaxonomyRecipe['id']]);
    $canonicalInsert->execute(['cherry-tomato-test', 'Cherry Tomato Test']);
    $cherryRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Specific Cherry Tomato Test',
        'ingredients' => [['name' => 'Cherry Tomato Test', 'qty' => '1 pz']],
        'steps' => ['Use a cherry tomato.'],
    ], ['connector' => 'manual', 'external_id' => 'ancestor-required']);
    recipeTestAssert(
        !recipeCatalogRankRecipe($db, $cherryRecipe, recipeInventoryCandidates($db))['cookable'],
        'A generic pantry ancestor must not satisfy a more specific required ingredient'
    );
    $productInsert->execute(['Chicken Soup Test', 0]);
    $chickenSoupProduct = (int)$db->lastInsertId();
    $inventoryInsert->execute([
        $chickenSoupProduct,
        date('Y-m-d', strtotime('+5 days')),
        0,
    ]);
    $chickenRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Raw Chicken Test',
        'ingredients' => [['name' => 'Chicken', 'qty' => '1 pz']],
        'steps' => ['Cook chicken.'],
    ], ['connector' => 'manual', 'external_id' => 'name-contains-required']);
    recipeTestAssert(
        !recipeCatalogRankRecipe($db, $chickenRecipe, recipeInventoryCandidates($db))['cookable'],
        'A name substring must not satisfy a required raw ingredient'
    );
    recipeScoreRebuild($db, true);
    $noQuerySuggestions = recipeCatalogSearchResult($db, [
        'query' => '',
        'mode' => 'stocked',
        'limit' => 5,
        'offset' => 0,
    ]);
    recipeTestAssert(
        !isset($noQuerySuggestions['results'][0]['text_rank']),
        'No-query suggestions must not contain an FTS score'
    );
    $sourceSearch = recipeCatalogSearchResult($db, [
        'query' => 'basil',
        'source' => 'local',
        'mode' => 'stocked',
        'limit' => 1,
        'offset' => 0,
    ]);
    recipeTestAssert(
        $sourceSearch['total'] === 1
        && (int)$sourceSearch['results'][0]['recipe']['id'] === $basilRecipe['id'],
        'Text search must support source filtering and pagination'
    );
    $rankedCookable = recipeCatalogSaveVariant($db, [
        'title' => 'Ranked Tomato Choice',
        'ingredients' => [['name' => 'Tomato Test', 'qty' => '1 pz']],
        'steps' => ['Serve.'],
    ], ['connector' => 'manual', 'external_id' => 'ranked-cookable']);
    recipeCatalogSaveVariant($db, [
        'title' => 'Ranked Tomato Choice Missing',
        'ingredients' => [
            ['name' => 'Tomato Test', 'qty' => '1 pz'],
            ['name' => 'Impossible Ranked Ingredient', 'qty' => '1 pz'],
        ],
        'steps' => ['Serve.'],
    ], ['connector' => 'manual', 'external_id' => 'ranked-missing']);
    recipeScoreRebuild($db, true);
    foreach (['stocked', 'expiring'] as $rankMode) {
        $rankedSearch = recipeCatalogSearchResult($db, [
            'query' => 'ranked',
            'mode' => $rankMode,
            'limit' => 2,
            'offset' => 0,
        ]);
        recipeTestAssert(
            (int)$rankedSearch['results'][0]['recipe']['id'] === $rankedCookable['id'],
            'Text search must apply pantry ranking before pagination in ' . $rankMode
                . ' mode: ' . json_encode(array_map(
                    static fn(array $result): array => [
                        'id' => $result['recipe']['id'],
                        'title' => $result['recipe']['title'],
                        'cookable' => $result['cookable'],
                        'score' => $result['suggestion_score'],
                    ],
                    $rankedSearch['results']
                ))
        );
    }
    for ($fixtureIndex = 1; $fixtureIndex <= 75; $fixtureIndex++) {
        $fixtureSuffix = chr(97 + intdiv($fixtureIndex - 1, 26))
                . chr(97 + (($fixtureIndex - 1) % 26));
        recipeCatalogSaveVariant($db, [
                'title' => 'Carousel Fixture ' . $fixtureSuffix,
                'image_url' => 'https://images.example.test/recipe-' . $fixtureIndex . '.jpg',
                'ingredients' => [['name' => 'Tomato Test', 'qty' => '1 pz']],
        ], [
                'connector' => 'manual',
                'external_id' => 'carousel-fixture-' . $fixtureIndex,
                'canonical_url' => 'https://example.test/recipes/' . $fixtureIndex,
        ]);
    }
    foreach (['A', 'B', 'C', 'D'] as $suffix) {
        recipeCatalogSaveVariant($db, [
            'title' => 'Metadata Cursor Fixture ' . $suffix,
            'ingredients' => [['name' => 'Tomato Test', 'qty' => '1 pz']],
        ], [
            'connector' => 'manual',
            'external_id' => 'metadata-cursor-fixture-' . strtolower($suffix),
        ]);
    }
    $metadataCursorRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Metadata Cursor Fixture Refresh',
        'source_ingredients' => [[
            'name' => 'Tomato Test',
            'source_quantity' => 1,
            'source_unit' => 'piece',
            'source_amount_text' => '1 piece',
        ]],
    ], [
        'connector' => 'cookidoo',
        'external_id' => 'metadata-cursor-refresh',
        'canonical_url' => (
            'https://cookidoo.co.uk/recipes/recipe/en-GB/'
            . 'metadata-cursor-refresh'
        ),
        'locale' => 'en-GB',
    ]);
    $metadataFavoriteRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Metadata Cursor Fixture Favorite',
        'source_ingredients' => [[
            'name' => 'Tomato Test',
            'source_quantity' => 1,
            'source_unit' => 'piece',
            'source_amount_text' => '1 piece',
        ]],
    ], [
        'connector' => 'cookidoo',
        'external_id' => 'metadata-cursor-favorite',
        'canonical_url' => (
            'https://cookidoo.co.uk/recipes/recipe/en-GB/'
            . 'metadata-cursor-favorite'
        ),
        'locale' => 'en-GB',
    ]);
    $metadataExpiredRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Metadata Cursor Fixture Expired',
        'source_ingredients' => [[
            'name' => 'Tomato Test',
            'source_quantity' => 1,
            'source_unit' => 'piece',
            'source_amount_text' => '1 piece',
        ]],
    ], [
        'connector' => 'cookidoo',
        'external_id' => 'metadata-cursor-expired',
        'canonical_url' => (
            'https://cookidoo.co.uk/recipes/recipe/en-GB/'
            . 'metadata-cursor-expired'
        ),
        'locale' => 'en-GB',
    ]);
    recipeCatalogSetFavorite($db, (int)$metadataFavoriteRecipe['id'], true);
    $db->prepare("
        UPDATE recipe_catalog
        SET stale_at = datetime('now', '-1 day')
        WHERE id IN (?, ?, ?)
    ")->execute([
        (int)$metadataCursorRecipe['id'],
        (int)$metadataFavoriteRecipe['id'],
        (int)$metadataExpiredRecipe['id'],
    ]);
    $db->prepare("
        UPDATE recipe_catalog
        SET cache_expires_at = datetime('now', '-1 day')
        WHERE id = ?
    ")->execute([(int)$metadataExpiredRecipe['id']]);
    $metadataCursorOriginId = (int)$db->query("
        SELECT id
        FROM recipe_origins
        WHERE recipe_id = " . (int)$metadataCursorRecipe['id'] . "
          AND connector = 'cookidoo'
        LIMIT 1
    ")->fetchColumn();
    $metadataFavoriteOriginId = (int)$db->query("
        SELECT id
        FROM recipe_origins
        WHERE recipe_id = " . (int)$metadataFavoriteRecipe['id'] . "
          AND connector = 'cookidoo'
        LIMIT 1
    ")->fetchColumn();
    $metadataExpiredOriginId = (int)$db->query("
        SELECT id
        FROM recipe_origins
        WHERE recipe_id = " . (int)$metadataExpiredRecipe['id'] . "
          AND connector = 'cookidoo'
        LIMIT 1
    ")->fetchColumn();
    $GLOBALS['RECIPE_SCORE_BEFORE_PRUNE_CLEANUP'] =
        static function (): void {
            throw new RuntimeException(str_repeat('legacy prune failure ', 40));
        };
    try {
        $legacyScoreRebuild = recipeScoreRebuild($db, true);
    } finally {
        unset($GLOBALS['RECIPE_SCORE_BEFORE_PRUNE_CLEANUP']);
    }
    $activeScoreRevision = recipeScoreActiveRevision($db);
    $resolvedAfterLegacyCleanupFailure = recipeScoreResolveRevision($db);
    recipeTestAssert(
        $legacyScoreRebuild['rebuilt']
        && $legacyScoreRebuild['activated']
        && isset($legacyScoreRebuild['cleanup_warning'])
        && strlen((string)$legacyScoreRebuild['cleanup_warning']) <= 500
        && $activeScoreRevision !== null
        && $activeScoreRevision['status'] === 'ready'
        && (int)$activeScoreRevision['id']
            === (int)$legacyScoreRebuild['revision_id']
        && (int)$resolvedAfterLegacyCleanupFailure['revision']['id']
            === (int)$activeScoreRevision['id'],
        'Committed legacy rebuild must stay ready and usable on prune failure'
    );
    recipeTestAssert(
        $activeScoreRevision !== null
        && recipeTestCount(
                $db,
                "SELECT COUNT(*) FROM recipe_inventory_scores WHERE score_revision_id = ?",
                [(int)$activeScoreRevision['id']]
        ) === recipeTestCount($db, "SELECT COUNT(*) FROM recipe_catalog WHERE deleted_at IS NULL"),
        'Active score revisions must contain every visible catalog recipe'
    );
    $scoreState = recipeScoreState($db);
    ingredientOntologyV3SetReadyMutationGuard($db, true);
    $fakeRevisionInsert = $db->prepare("
        INSERT INTO recipe_score_revisions (
            inventory_revision, catalog_revision, inventory_fingerprint,
            score_date, catalog_max_id, status, recipe_count, completed_at
        )
        VALUES (?, ?, 'fake', ?, ?, 'ready', 0, CURRENT_TIMESTAMP)
    ");
    $fakeRevisionInsert->execute([
        $scoreState['inventory_revision'],
        $scoreState['catalog_revision'],
        recipeScoreCurrentDate(),
        recipeScoreCatalogMaxId($db),
    ]);
    $fakeRevisionInsert->execute([
        $scoreState['inventory_revision'],
        $scoreState['catalog_revision'],
        recipeScoreCurrentDate(),
        recipeScoreCatalogMaxId($db),
    ]);
    ingredientOntologyV3SetReadyMutationGuard($db, false);
    $nestedPruneRejected = false;
    $db->beginTransaction();
    try {
        recipeScorePruneRevisions($db);
    } catch (RuntimeException $e) {
        $nestedPruneRejected = str_contains(
            $e->getMessage(),
            'cannot run inside a transaction'
        );
    }
    $nestedPruneKeptCallerTransaction = $db->inTransaction();
    $db->rollBack();
    recipeTestAssert(
        $nestedPruneRejected && $nestedPruneKeptCallerTransaction,
        'Score pruning must reject nested transactions without ending the caller transaction'
    );
    recipeScorePruneRevisions($db);
    recipeTestAssert(
        recipeScoreRevision($db, (int)$activeScoreRevision['id']) !== null,
        'Score pruning must always retain the active revision'
    );
    ingredientOntologyV3SetReadyMutationGuard($db, true);
    $db->prepare("
        DELETE FROM recipe_score_revisions WHERE id <> ?
    ")->execute([(int)$activeScoreRevision['id']]);
    ingredientOntologyV3SetReadyMutationGuard($db, false);
    $metadataRefreshItem = static function (
        string $externalId
    ): array {
        return recipeTestCookidooBridgeRecipe([
            'external_id' => $externalId,
            'title' => 'Ignored cursor refresh title',
            'general' => [
                'yield_quantity' => 2,
                'yield_unit' => 'portions',
                'active_time_seconds' => 300,
                'total_time_seconds' => 900,
                'difficulty' => 'easy',
                'primary_category' => 'Test',
                'equipment' => ['spoon'],
            ],
            'ingredients' => [[
                'name' => 'Tomato Test',
                'source_quantity' => 2,
                'source_quantity_max' => null,
                'source_unit' => 'pieces',
                'source_amount_text' => '2 pieces',
            ]],
            'image_url' => (
                'https://assets.tmecosys.com/image/upload/'
                . $externalId . '.jpg'
            ),
            'canonical_url' => (
                'https://cookidoo.co.uk/recipes/recipe/en-GB/'
                . $externalId
            ),
            'locale' => 'en-GB',
        ]);
    };
    $expiredCursorStateBefore = recipeScoreState($db);
    $expiredRefresh = recipeCookidooApplyMetadataV2(
        $db,
        (int)$metadataExpiredRecipe['id'],
        $metadataExpiredOriginId,
        $metadataRefreshItem('metadata-cursor-expired'),
        gmdate('Y-m-d H:i:s')
    );
    recipeTestAssert(
        empty($expiredRefresh['visibility_changed'])
        && recipeScoreState($db) === $expiredCursorStateBefore,
        'Metadata refreshes that remain outside the freshness window must not '
            . 'invalidate cursors'
    );
    $favoriteCursorStateBefore = recipeScoreState($db);
    $favoriteRefresh = recipeCookidooApplyMetadataV2(
        $db,
        (int)$metadataFavoriteRecipe['id'],
        $metadataFavoriteOriginId,
        $metadataRefreshItem('metadata-cursor-favorite'),
        gmdate('Y-m-d H:i:s')
    );
    recipeTestAssert(
        empty($favoriteRefresh['visibility_changed'])
        && recipeScoreState($db) === $favoriteCursorStateBefore,
        'Favorited stale recipes remain visible and must not invalidate cursors '
            . 'when metadata becomes fresh'
    );
    $metadataCursorPageOne = recipeCatalogSearchResult($db, [
        'query' => 'metadata cursor fixture',
        'fields' => 'card',
        'limit' => 2,
    ]);
    $metadataCursorStaleCards = recipeCatalogSearchResult($db, [
        'query' => 'metadata cursor fixture',
        'fields' => 'card',
        'limit' => 10,
    ]);
    $metadataCursorStaleCard = array_values(array_filter(
        $metadataCursorStaleCards['items'],
        static fn(array $card): bool =>
            (int)$card['id'] === (int)$metadataCursorRecipe['id']
    ))[0] ?? null;
    $metadataCursorStateBefore = recipeScoreState($db);
    $metadataCursorRevisionBefore = recipeScoreRevision(
        $db,
        (int)$activeScoreRevision['id']
    );
    $metadataCursorScoreBefore = $db->query("
        SELECT *
        FROM recipe_inventory_scores
        WHERE score_revision_id = " . (int)$activeScoreRevision['id'] . "
          AND recipe_id = " . (int)$metadataCursorRecipe['id'] . "
    ")->fetch(PDO::FETCH_ASSOC);
    $metadataCursorRefresh = recipeCookidooApplyMetadataV2(
        $db,
        (int)$metadataCursorRecipe['id'],
        $metadataCursorOriginId,
        $metadataRefreshItem('metadata-cursor-refresh'),
        gmdate('Y-m-d H:i:s')
    );
    $metadataCursorStateAfter = recipeScoreState($db);
    $metadataCursorExpectedState = $metadataCursorStateBefore;
    $staleMetadataCursorAccepted = true;
    try {
        recipeCatalogSearchResult($db, [
            'query' => 'metadata cursor fixture',
            'fields' => 'card',
            'limit' => 2,
            'cursor' => $metadataCursorPageOne['next_cursor'],
        ]);
    } catch (InvalidArgumentException $e) {
        $staleMetadataCursorAccepted = false;
    }
    $metadataCursorFreshResults = recipeCatalogSearchResult($db, [
        'query' => 'metadata cursor fixture',
        'fields' => 'card',
        'limit' => 10,
    ]);
    recipeTestAssert(
        $metadataCursorPageOne['total'] === 7
        && $metadataCursorPageOne['next_cursor'] !== null
        && !empty($metadataCursorStaleCard['is_stale'])
        && empty($metadataCursorRefresh['visibility_changed'])
        && $metadataCursorStateAfter === $metadataCursorExpectedState
        && recipeScoreRevision(
            $db,
            (int)$activeScoreRevision['id']
        ) === $metadataCursorRevisionBefore
        && $db->query("
            SELECT *
            FROM recipe_inventory_scores
            WHERE score_revision_id = " . (int)$activeScoreRevision['id'] . "
              AND recipe_id = " . (int)$metadataCursorRecipe['id'] . "
        ")->fetch(PDO::FETCH_ASSOC) === $metadataCursorScoreBefore
        && $staleMetadataCursorAccepted
        && $metadataCursorFreshResults['total'] === 7
        && in_array(
            (int)$metadataCursorRecipe['id'],
            array_column($metadataCursorFreshResults['items'], 'id'),
            true
        ),
        'Stale-to-fresh metadata must preserve catalog membership, score state, '
            . 'and existing cursors: ' . json_encode([
                'page_total' => $metadataCursorPageOne['total'],
                'refresh' => $metadataCursorRefresh,
                'state_before' => $metadataCursorStateBefore,
                'state_after' => $metadataCursorStateAfter,
                'cursor_accepted' => $staleMetadataCursorAccepted,
                'fresh_total' => $metadataCursorFreshResults['total'],
            ])
    );
    $freshCursorStateBefore = recipeScoreState($db);
    $metadataSourceIdsBefore = $db->query("
        SELECT id
        FROM recipe_source_ingredients
        WHERE recipe_id = " . (int)$metadataCursorRecipe['id'] . "
        ORDER BY position
    ")->fetchAll(PDO::FETCH_COLUMN);
    $metadataCorpusBefore = ingredientOntologyV3CorpusHash($db);
    $freshMetadataRefresh = recipeCookidooApplyMetadataV2(
        $db,
        (int)$metadataCursorRecipe['id'],
        $metadataCursorOriginId,
        $metadataRefreshItem('metadata-cursor-refresh'),
        gmdate('Y-m-d H:i:s', time() + 1)
    );
    recipeTestAssert(
        empty($freshMetadataRefresh['visibility_changed'])
        && recipeScoreState($db) === $freshCursorStateBefore
        && $db->query("
            SELECT id
            FROM recipe_source_ingredients
            WHERE recipe_id = " . (int)$metadataCursorRecipe['id'] . "
            ORDER BY position
        ")->fetchAll(PDO::FETCH_COLUMN) === $metadataSourceIdsBefore
        && hash_equals(
            $metadataCorpusBefore,
            ingredientOntologyV3CorpusHash($db)
        ),
        'Fresh-to-fresh metadata refreshes must preserve cursor state, '
            . 'source row IDs, and ontology identity'
    );
    $cardPage = recipeCatalogSearchResult($db, [
        'query' => 'carousel fixture',
        'sort' => 'availability',
        'availability_weight' => 100,
        'expiry_weight' => 25,
        'minimum_coverage' => 0,
        'fields' => 'card',
        'limit' => 5,
    ]);
    recipeTestAssert(
        count($cardPage['items']) === 5
        && !isset($cardPage['results'])
        && isset($cardPage['items'][0]['dedupe_key'])
        && isset($cardPage['items'][0]['matched_required'])
        && strlen(json_encode($cardPage['items'])) < 6000,
        'Compact card search must paginate before hydration and remain bounded'
    );
    $fullyCovered = recipeCatalogSearchResult($db, [
        'query' => 'carousel fixture',
        'minimum_coverage' => 100,
        'fields' => 'card',
        'limit' => 50,
    ]);
    recipeTestAssert(
        $fullyCovered['items']
        && count(array_filter(
                $fullyCovered['items'],
                static fn(array $item): bool => (float)$item['coverage'] < 1
        )) === 0,
        'Minimum coverage must filter before pagination'
    );
    $alphabetical = recipeCatalogSearchResult($db, [
        'query' => 'carousel fixture',
        'sort' => 'alphabetical',
        'fields' => 'card',
        'limit' => 3,
    ]);
    recipeTestAssert(
        array_column($alphabetical['items'], 'title') === [
                'Carousel Fixture aa',
                'Carousel Fixture ab',
                'Carousel Fixture ac',
        ],
        'Alphabetical recipe sorting must be stable'
    );
    $snapshotPageOne = recipeCatalogSearchResult($db, [
        'query' => 'carousel fixture',
        'fields' => 'card',
        'limit' => 2,
    ]);
    $snapshotRevision = recipeScoreActiveRevision($db);
    $lateRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Carousel Fixture zz Late',
        'ingredients' => [['name' => 'Tomato Test', 'qty' => '1 pz']],
    ], ['connector' => 'manual', 'external_id' => 'carousel-fixture-late']);
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_inventory_scores
             WHERE score_revision_id = ? AND recipe_id = ?",
            [(int)$snapshotRevision['id'], (int)$lateRecipe['id']]
        ) === 0
        && recipeScoreState($db)['catalog_revision']
            > (int)$snapshotRevision['catalog_revision'],
        'Activated score revisions must remain immutable after catalog imports'
    );
    $snapshotPageTwo = recipeCatalogSearchResult($db, [
        'query' => 'carousel fixture',
        'fields' => 'card',
        'limit' => 2,
        'cursor' => $snapshotPageOne['next_cursor'],
    ]);
    recipeTestAssert(
        $snapshotPageTwo['snapshot_id'] === $snapshotPageOne['snapshot_id']
        && $snapshotPageTwo['total'] === $snapshotPageOne['total']
        && !in_array($lateRecipe['id'], array_column($snapshotPageTwo['items'], 'id'), true),
        'Recipe cursors must retain their score/catalog snapshot during imports'
    );
    recipeCatalogSetFavorite(
        $db,
        (int)$snapshotPageOne['items'][0]['id'],
        true
    );
    $updatedCursorRejected = false;
    try {
        recipeCatalogSearchResult($db, [
            'query' => 'carousel fixture',
            'fields' => 'card',
            'limit' => 2,
            'cursor' => $snapshotPageOne['next_cursor'],
        ]);
    } catch (InvalidArgumentException $e) {
        $updatedCursorRejected = str_contains($e->getMessage(), 'catalog changed');
    }
    recipeTestAssert(
        $updatedCursorRejected,
        'Ordering-changing catalog updates must invalidate existing cursors'
    );
    $recommendations = recipeCatalogRecommendationResult($db, ['locale' => 'en']);
    recipeTestAssert(
        count($recommendations['items']) === 30
        && count(array_unique(array_column($recommendations['items'], 'dedupe_key'))) === 30
        && !$recommendations['degraded'],
        'Recommendations must return 30 unique ordered card summaries: '
            . json_encode([
                'count' => count($recommendations['items']),
                'unique' => count(array_unique(array_column(
                    $recommendations['items'],
                    'dedupe_key'
                ))),
                'degraded' => $recommendations['degraded'],
            ])
    );
    $wideRecommendations = recipeCatalogRecommendationResult($db, [
        'locale' => 'en',
        'limit' => 70,
    ]);
    recipeTestAssert(
        count($wideRecommendations['items']) === 70
        && count(array_unique(array_column(
            $wideRecommendations['items'],
            'dedupe_key'
        ))) === 70
        && !$wideRecommendations['degraded'],
        'Responsive recommendations must honor a requested five-page total'
    );
    $hydrationStatus = recipeCookidooHydrationStatus(
        $db,
        $discoveryOne['search_id']
    );
    recipeTestAssert(
        $hydrationStatus['status'] === 'complete'
        && ($hydrationStatus['imported_count'] + $hydrationStatus['updated_count']) >= 1
        && count($hydrationStatus['new_items']) >= 1,
        'Cookidoo hydration status must aggregate compact imported recipe cards: '
            . json_encode($hydrationStatus)
    );
    $invalidWeightRejected = false;
    try {
        recipeCatalogNormalizeBrowseOptions(['availability_weight' => 101]);
    } catch (InvalidArgumentException $e) {
        $invalidWeightRejected = true;
    }
    recipeTestAssert($invalidWeightRejected, 'Recipe weights must be range validated');

    $_GET = ['q' => 'basil', 'source' => 'local', 'limit' => '1', 'page' => '1'];
    ob_start();
    recipeCatalogApiSearch($db);
    $apiSearch = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        !empty($apiSearch['success'])
        && (int)$apiSearch['results'][0]['recipe']['id'] === $basilRecipe['id'],
        'Recipe catalog search action must return local FTS results'
    );
    $_GET = ['limit' => '3', 'mode' => 'expiring'];
    ob_start();
    recipeCatalogApiSuggest($db);
    $apiSuggest = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        !empty($apiSuggest['success']) && $apiSuggest['mode'] === 'expiring',
        'Recipe catalog suggest action must expose inventory ranking mode'
    );
    $_GET = ['locale' => 'en', 'limit' => '70'];
    ob_start();
    recipeCatalogApiRecommendations($db);
    $apiRecommendations = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        !empty($apiRecommendations['success'])
        && count($apiRecommendations['items']) === 70,
        'Recipe recommendations action must expose the responsive carousel contract'
    );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['RECIPE_API_JSON_INPUT'] = [
        'connector' => 'cookidoo',
        'recipe' => [
            'title' => 'Forbidden Public Cookidoo Save',
            'ingredients' => [['name' => 'Tomato Test']],
        ],
    ];
    ob_start();
    recipeCatalogApiSave($db);
    $forbiddenConnectorSave = json_decode((string)ob_get_clean(), true);
    unset($GLOBALS['RECIPE_API_JSON_INPUT']);
    recipeTestAssert(
        http_response_code() === 400
        && ($forbiddenConnectorSave['error'] ?? '') === 'connector_save_unsupported',
        'Public catalog saves must respect connector capabilities'
    );
    http_response_code(200);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $refreshJobsBefore = recipeTestCount(
        $db,
        "SELECT COUNT(*) FROM recipe_jobs WHERE job_type = 'recipe_refresh'"
    );
    ob_start();
    recipeCatalogApiRefresh($db);
    $refreshResponse = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        http_response_code() === 405
        && ($refreshResponse['error'] ?? '') === 'method_not_allowed'
        && recipeTestCount($db, "SELECT COUNT(*) FROM recipe_jobs WHERE job_type = 'recipe_refresh'") === $refreshJobsBefore,
        'Recipe refresh must reject mutating GET requests'
    );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    http_response_code(200);
    $_GET = [];

    $oldDate = date('Y-m-d', strtotime('-30 days'));
    $legacy = $db->prepare("
        INSERT INTO recipes (date, meal, recipe_json, is_favorite) VALUES (?, ?, '{}', ?)
    ");
    $legacy->execute([$oldDate, 'favorite', 1]);
    $favoriteLegacyId = (int)$db->lastInsertId();
    $legacy->execute([$oldDate, 'ordinary', 0]);
    $ordinaryLegacyId = (int)$db->lastInsertId();
    recipeLegacyCleanup($db, 7);
    recipeTestAssert(
        recipeTestCount($db, "SELECT COUNT(*) FROM recipes WHERE id = ?", [$favoriteLegacyId]) === 1,
        'Legacy favorite must survive cleanup'
    );
    recipeTestAssert(
        recipeTestCount($db, "SELECT COUNT(*) FROM recipes WHERE id = ?", [$ordinaryLegacyId]) === 0,
        'Non-favorite legacy recipe should honor retention'
    );
    recipeCatalogSetFavorite($db, $variantOne['id'], true);
    recipeLegacyCleanup($db, 7);
    recipeTestAssert(recipeCatalogGetById($db, $variantOne['id']) !== null, 'Catalog favorites must not be touched by legacy cleanup');

    $generated = recipeCatalogPersistGenerated($db, [
        'title' => 'Generated Tomato Plate',
        'persons' => 1,
        'ingredients' => [['name' => 'Tomato Test', 'qty' => '1 pz']],
        'steps' => ['Slice and serve.'],
    ], ['language' => 'en']);
    $generatedAgain = recipeCatalogPersistGenerated($db, [
        'title' => 'Generated Tomato Plate',
        'persons' => 1,
        'ingredients' => [['name' => 'Tomato Test', 'qty' => '1 pz']],
        'steps' => ['Slice and serve.'],
    ], ['language' => 'en']);
    recipeTestAssert($generated['id'] === $generatedAgain['id'], 'Generated exact identity must deduplicate');
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_origins WHERE recipe_id = ? AND connector = 'generated'",
            [$generated['id']]
        ) === 1,
        'Generated recipe origin must persist'
    );
    $manualMetadata = ['connector' => 'manual', 'language' => 'en', 'locale' => 'en'];
    $exactGeneratedId = recipeCatalogFindExactContentRecipeId($db, [
        'title' => 'Generated Tomato Plate',
        'language' => 'en',
        'persons' => 1,
        'ingredients' => [['name' => 'Tomato Test', 'qty' => '1 pz']],
        'steps' => ['Slice and serve.'],
    ], $manualMetadata);
    recipeTestAssert($exactGeneratedId === $generated['id'], 'Manual archive saves must locate an exact generated variant');
    recipeTestAssert(
        recipeTestCount($db, "SELECT COUNT(*) FROM recipe_catalog WHERE title = 'Generated Tomato Plate'") === 1,
        'Legacy archive save must not duplicate a generated catalog row'
    );
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_origins WHERE recipe_id = ? AND connector = 'manual'",
            [$generated['id']]
        ) === 0,
        'Archiving an existing generated recipe must not change its source provenance'
    );
    $db->prepare("
        INSERT INTO recipes (
            date, meal, recipe_json, catalog_recipe_id, is_favorite
        )
        VALUES (?, 'favorite-sync', '{}', ?, 0)
    ")->execute([recipeScoreCurrentDate(), $generated['id']]);
    $legacyFavoriteSyncId = (int)$db->lastInsertId();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['RECIPE_API_JSON_INPUT'] = [
        'id' => $generated['id'],
        'favorite' => true,
    ];
    ob_start();
    recipeCatalogApiFavorite($db);
    ob_end_clean();
    unset($GLOBALS['RECIPE_API_JSON_INPUT']);
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT is_favorite FROM recipes WHERE id = ?",
            [$legacyFavoriteSyncId]
        ) === 1,
        'Catalog favorite changes must update linked legacy archive rows'
    );
    $GLOBALS['LEGACY_RECIPE_FAVORITE_INPUT'] = ['id' => $legacyFavoriteSyncId];
    ob_start();
    recipeToggleFavorite($db);
    ob_end_clean();
    unset($GLOBALS['LEGACY_RECIPE_FAVORITE_INPUT']);
    recipeTestAssert(
        !recipeCatalogGetById($db, $generated['id'])['favorite'],
        'Legacy archive favorite changes must update linked catalog state'
    );
    $favoriteReplacementA = recipeCatalogSaveVariant($db, [
        'title' => 'Favorite Replacement A',
        'ingredients' => [['name' => 'Tomato Test']],
        'steps' => ['A'],
    ], ['connector' => 'manual', 'external_id' => 'favorite-replacement-a']);
    recipeCatalogSetFavorite($db, $favoriteReplacementA['id'], true);
    $GLOBALS['LEGACY_RECIPE_SAVE_INPUT'] = [
        'date' => '2030-01-01',
        'meal' => 'replacement',
        'recipe' => [
            'title' => 'Favorite Replacement A',
            'ingredients' => [['name' => 'Tomato Test']],
            'steps' => ['A'],
        ],
    ];
    ob_start();
    recipesSave($db);
    ob_end_clean();
    $GLOBALS['LEGACY_RECIPE_SAVE_INPUT']['recipe'] = [
        'title' => 'Favorite Replacement B',
        'ingredients' => [['name' => 'Tomato Test']],
        'steps' => ['B'],
    ];
    ob_start();
    recipesSave($db);
    ob_end_clean();
    unset($GLOBALS['LEGACY_RECIPE_SAVE_INPUT']);
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT is_favorite FROM recipes
             WHERE date = '2030-01-01' AND meal = 'replacement'"
        ) === 0,
        'Replacing a legacy recipe slot must adopt the new catalog favorite state'
    );
    $db->prepare("
        INSERT INTO recipes (
            date, meal, recipe_json, is_favorite, catalog_recipe_id
        )
        VALUES ('2030-01-03', 'upgrade-favorite', ?, 1, NULL)
    ")->execute([json_encode([
        'title' => 'Upgraded Legacy Favorite',
        'meal' => 'upgrade-favorite',
        'ingredients' => [['name' => 'Tomato Test']],
        'steps' => ['Keep favorite.'],
    ])]);
    $GLOBALS['LEGACY_RECIPE_SAVE_INPUT'] = [
        'date' => '2030-01-03',
        'meal' => 'upgrade-favorite',
        'recipe' => [
            'title' => 'Upgraded Legacy Favorite',
            'meal' => 'upgrade-favorite',
            'ingredients' => [['name' => 'Tomato Test']],
            'steps' => ['Keep favorite.'],
        ],
    ];
    ob_start();
    recipesSave($db);
    ob_end_clean();
    unset($GLOBALS['LEGACY_RECIPE_SAVE_INPUT']);
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT is_favorite FROM recipes
             WHERE date = '2030-01-03' AND meal = 'upgrade-favorite'"
        ) === 1,
        'First catalog linkage must preserve an upgraded legacy favorite'
    );
    $legacyLocaleRecipe = [
        'title' => 'Legacy Locale Quantity Identity',
        'language' => 'es',
        'ingredients' => ['1,000 g harina'],
        'steps' => ['Mezclar.'],
    ];
    $GLOBALS['LEGACY_RECIPE_SAVE_INPUT'] = [
        'date' => '2030-01-04',
        'meal' => 'locale-mx',
        'recipe' => $legacyLocaleRecipe + ['locale' => 'es-MX'],
    ];
    ob_start();
    recipesSave($db);
    ob_end_clean();
    $GLOBALS['LEGACY_RECIPE_SAVE_INPUT'] = [
        'date' => '2030-01-05',
        'meal' => 'locale-es',
        'recipe' => $legacyLocaleRecipe + ['locale' => 'es-ES'],
    ];
    ob_start();
    recipesSave($db);
    ob_end_clean();
    unset($GLOBALS['LEGACY_RECIPE_SAVE_INPUT']);
    $legacyLocaleRows = $db->query("
        SELECT date, catalog_recipe_id
        FROM recipes
        WHERE date IN ('2030-01-04', '2030-01-05')
        ORDER BY date
    ")->fetchAll(PDO::FETCH_ASSOC);
    $legacyLocaleMx = recipeCatalogGetById(
        $db,
        (int)$legacyLocaleRows[0]['catalog_recipe_id']
    );
    $legacyLocaleEs = recipeCatalogGetById(
        $db,
        (int)$legacyLocaleRows[1]['catalog_recipe_id']
    );
    recipeTestAssert(
        count($legacyLocaleRows) === 2
            && (int)$legacyLocaleRows[0]['catalog_recipe_id']
                !== (int)$legacyLocaleRows[1]['catalog_recipe_id']
            && $legacyLocaleMx['ingredients'][0]['quantity_parse']['locale']
                === 'es-MX'
            && (float)$legacyLocaleMx['ingredients'][0]
                ['quantity_parse']['quantity'] === 1000.0
            && $legacyLocaleEs['ingredients'][0]['quantity_parse']['locale']
                === 'es-ES'
            && (float)$legacyLocaleEs['ingredients'][0]
                ['quantity_parse']['quantity'] === 1.0,
        'Legacy recipes_save must preserve explicit regional locale identity and parsing'
    );

    $productInsert->execute(['Structural Unit Edit Test', 0]);
    $structuralProduct = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO inventory (product_id, location, quantity, prepared_food)
        VALUES (?, 'dispensa', 2, 0)
    ")->execute([$structuralProduct]);
    $structuralInventory = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO transactions (
            product_id, inventory_id, type, quantity, location,
            prepared_food, undo_safe
        )
        VALUES (?, ?, 'in', 2, 'dispensa', 0, 1)
    ")->execute([$structuralProduct, $structuralInventory]);
    $db->prepare("
        UPDATE transactions SET undo_safe = 0
        WHERE product_id = ? AND undone = 0
    ")->execute([$structuralProduct]);
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM transactions
             WHERE product_id = ? AND undone = 0 AND undo_safe = 1",
            [$structuralProduct]
        ) === 0,
        'Structural unit changes must invalidate prior undo operations'
    );

    $deletableRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Deletable Catalog Recipe',
        'ingredients' => [['name' => 'Tomato Test']],
        'steps' => ['Delete me.'],
    ], ['connector' => 'manual', 'external_id' => 'deletable-catalog-recipe']);
    $db->prepare("
        INSERT INTO recipes (
            date, meal, recipe_json, catalog_recipe_id, is_favorite
        )
        VALUES ('2030-01-02', 'delete-link', '{}', ?, 0)
    ")->execute([$deletableRecipe['id']]);
    recipeTestAssert(recipeCatalogDelete($db, $deletableRecipe['id']), 'Catalog deletion must succeed');
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipes
             WHERE catalog_recipe_id = ?",
            [$deletableRecipe['id']]
        ) === 0
        && recipeCatalogFindExactContentRecipeId($db, [
            'title' => 'Deletable Catalog Recipe',
            'ingredients' => [['name' => 'Tomato Test']],
            'steps' => ['Delete me.'],
        ], ['connector' => 'manual']) === null,
        'Catalog deletion must clear legacy links and exact-content lookup'
    );
    $deletedOriginRejected = false;
    try {
        recipeCatalogSaveVariant($db, [
            'title' => 'Deletable Catalog Recipe',
            'ingredients' => [['name' => 'Tomato Test']],
            'steps' => ['Delete me.'],
        ], ['connector' => 'manual', 'external_id' => 'deletable-catalog-recipe']);
    } catch (InvalidArgumentException $e) {
        $deletedOriginRejected = true;
    }
    recipeTestAssert(
        $deletedOriginRejected
        && recipeCatalogGetById($db, $deletableRecipe['id'], true)['deleted_at'] !== null,
        'Saving the same origin must not silently resurrect a deleted recipe'
    );
    $deletedIdRejected = false;
    try {
        recipeCatalogSaveVariant($db, [
            'title' => 'Deleted ID Rewrite',
            'ingredients' => [['name' => 'Tomato Test']],
        ], [
            'recipe_id' => $deletableRecipe['id'],
            'connector' => 'manual',
            'external_id' => 'new-origin-on-deleted-id',
        ]);
    } catch (InvalidArgumentException $e) {
        $deletedIdRejected = true;
    }
    recipeTestAssert($deletedIdRejected, 'Deleted recipe IDs must not be implicitly restored');

    $unmappedRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Unmapped Herb Dish',
        'ingredients' => [['name' => 'Unmapped Herb Test']],
        'steps' => ['Serve.'],
    ], ['connector' => 'manual', 'external_id' => 'unmapped-herb']);
    $oldCluster = $unmappedRecipe['cluster_key'];
    $db->prepare("
        INSERT INTO taxonomy_nodes (tree_id, slug, name)
        VALUES (?, 'unmapped-herb-test', 'Unmapped Herb Test')
    ")->execute([$treeId]);
    $unmappedNode = (int)$db->lastInsertId();
    $closure->execute([$treeId, $unmappedNode, $unmappedNode, 0]);
    $canonicalInsert->execute(['unmapped-herb-test', 'Unmapped Herb Test']);
    $cursorRevisionBeforeRemap = recipeScoreState($db)['cursor_revision'];
    recipeCatalogRefreshUnresolvedMappings($db);
    $remappedRecipe = recipeCatalogGetById($db, $unmappedRecipe['id']);
    recipeTestAssert(
        $remappedRecipe['cluster_key'] !== $oldCluster
        && recipeScoreState($db)['cursor_revision'] > $cursorRevisionBeforeRemap,
        'Taxonomy remapping must refresh cluster keys and invalidate cursors'
    );

    $db->exec("
        INSERT INTO recipe_catalog (primary_connector, title)
        VALUES ('manual', 'Mapping Cursor Test')
    ");
    $cursorRecipeId = (int)$db->lastInsertId();
    $cursorInsert = $db->prepare("
        INSERT INTO recipe_ingredients (
            recipe_id, position, raw_text, normalized_name,
            mapping_confidence, mapping_source
        )
        VALUES (?, ?, ?, ?, 0, 'unresolved')
    ");
    for ($position = 1; $position <= 500; $position++) {
        $name = 'Never Resolved Cursor ' . $position;
        $cursorInsert->execute([$cursorRecipeId, $position, $name, recipeIngredientNormalizeName($name)]);
    }
    $cursorInsert->execute([$cursorRecipeId, 501, 'Tomato Test', 'tomato test']);
    $db->prepare("
        INSERT INTO app_settings (key, value)
        VALUES ('recipe_mapping_cursor', '0')
        ON CONFLICT(key) DO UPDATE SET value = '0'
    ")->execute();
    recipeCatalogRefreshUnresolvedMappings($db, 500);
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_ingredients
             WHERE recipe_id = ? AND position = 501
               AND canonical_ingredient_id IS NOT NULL",
            [$cursorRecipeId]
        ) === 0,
        'First mapping page should stop before the cursor target'
    );
    recipeCatalogRefreshUnresolvedMappings($db, 500);
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_ingredients
             WHERE recipe_id = ? AND position = 501
               AND canonical_ingredient_id IS NOT NULL",
            [$cursorRecipeId]
        ) === 1,
        'Mapping cursor must advance past permanently unresolved rows'
    );

    $sourceRemapRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Source Mapping Version Test',
        'ingredients' => [['name' => 'Tomato Test']],
        'source_ingredients' => [[
            'name' => 'Tomato Test',
            'source_group_index' => 0,
            'source_group_position' => 0,
        ]],
        'steps' => ['Serve.'],
    ], ['connector' => 'manual', 'external_id' => 'source-mapping-version']);
    $sourceRemapRowId = (int)$db->query("
        SELECT id
        FROM recipe_source_ingredients
        WHERE recipe_id = " . (int)$sourceRemapRecipe['id'] . "
        LIMIT 1
    ")->fetchColumn();
    $rankingRemapRowId = (int)$db->query("
        SELECT id
        FROM recipe_ingredients
        WHERE recipe_id = " . (int)$sourceRemapRecipe['id'] . "
        LIMIT 1
    ")->fetchColumn();
    $sourceRewriteCorpusHash = ingredientOntologyV3CorpusHash($db);
    $sourceRemapRewrite = recipeCatalogSaveVariant($db, [
        'title' => 'Source Mapping Version Test',
        'ingredients' => [['name' => 'Tomato Test']],
        'source_ingredients' => [[
            'name' => 'Tomato Test',
            'source_group_index' => 0,
            'source_group_position' => 0,
        ]],
        'steps' => ['Serve.'],
    ], ['connector' => 'manual', 'external_id' => 'source-mapping-version']);
    recipeTestAssert(
        (int)$sourceRemapRewrite['id'] === (int)$sourceRemapRecipe['id']
        && (int)$db->query("
            SELECT id FROM recipe_ingredients
            WHERE recipe_id = " . (int)$sourceRemapRecipe['id'] . "
            LIMIT 1
        ")->fetchColumn() === $rankingRemapRowId
        && (int)$db->query("
            SELECT id FROM recipe_source_ingredients
            WHERE recipe_id = " . (int)$sourceRemapRecipe['id'] . "
            LIMIT 1
        ")->fetchColumn() === $sourceRemapRowId
        && hash_equals(
            $sourceRewriteCorpusHash,
            ingredientOntologyV3CorpusHash($db)
        ),
        'No-op repository saves must preserve ranking/source row IDs and ontology identity'
    );
    $db->prepare("
        UPDATE recipe_source_ingredients SET
            canonical_ingredient_id = NULL,
            taxonomy_node_id = NULL,
            mapping_confidence = 0,
            mapping_source = 'unresolved',
            mapping_version = 'legacy-v0'
        WHERE id = ?
    ")->execute([$sourceRemapRowId]);
    $sourceRemapRevisionBefore = recipeScoreState($db);
    $sourceRemap = recipeSourceIngredientRemap(
        $db,
        recipeIngredientActiveMappingVersion(),
        1
    );
    $sourceRemappedRow = $db->query("
        SELECT canonical_ingredient_id, taxonomy_node_id, mapping_source,
               mapping_version
        FROM recipe_source_ingredients
        WHERE id = {$sourceRemapRowId}
    ")->fetch(PDO::FETCH_ASSOC);
    recipeTestAssert(
        $sourceRemap['scanned'] === 1
        && $sourceRemap['updated'] === 1
        && $sourceRemap['target_mapping_version']
            === RECIPE_SOURCE_MAPPING_VERSION_LEGACY
        && $sourceRemappedRow['mapping_version']
            === RECIPE_SOURCE_MAPPING_VERSION_LEGACY
        && (
            $sourceRemappedRow['canonical_ingredient_id'] !== null
            || $sourceRemappedRow['taxonomy_node_id'] !== null
        )
        && recipeScoreState($db)['active_score_revision_id']
            === $sourceRemapRevisionBefore['active_score_revision_id']
        && recipeScoreState($db)['inventory_revision']
            === $sourceRemapRevisionBefore['inventory_revision']
        && recipeScoreState($db)['catalog_revision']
            === $sourceRemapRevisionBefore['catalog_revision']
        && recipeScoreState($db)['ontology_source_revision']
            > $sourceRemapRevisionBefore['ontology_source_revision'],
        'Bounded source remaps must use stored names without provider fetches '
            . 'while invalidating ontology source identity'
    );
    $db->prepare("
        UPDATE recipe_source_ingredients
        SET mapping_version = 'legacy-v0'
        WHERE id = ?
    ")->execute([$sourceRemapRowId]);
    $sourceRemapJob = recipeJobEnqueueSourceIngredientRemap(
        $db,
        RECIPE_SOURCE_MAPPING_VERSION_LEGACY,
        1
    );
    $sourceRemapOutcome = recipeJobDispatch($db, $sourceRemapJob);
    recipeTestAssert(
        $sourceRemapOutcome['status'] === 'done'
        && $sourceRemapOutcome['result']['updated'] === 1
        && $sourceRemapOutcome['result']['remaining'] === 0,
        'Source mapping remaps must also be available as bounded local jobs'
    );
    $ontologyV3Activated = false;
    try {
        recipeSourceIngredientRemap($db, 'ontology-v3', 1);
        $ontologyV3Activated = true;
    } catch (InvalidArgumentException $e) {
        $ontologyV3Activated = false;
    }
    recipeTestAssert(
        !$ontologyV3Activated,
        'Ontology v3 must remain inactive until its resolver is explicitly registered'
    );

    $productInsert->execute(['Undo Mixed Prepared Test', 0]);
    $undoProductId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO inventory (product_id, location, quantity, prepared_food)
        VALUES (?, 'frigo', 1, 0)
    ")->execute([$undoProductId]);
    $db->prepare("
        INSERT INTO inventory (
            product_id, location, quantity, expiry_date, expiry_user_set,
            vacuum_sealed, opened_at, prepared_food
        )
        VALUES (?, 'frigo', 0, '2030-12-31', 1, 1, '2030-12-01 12:00:00', 1)
    ")->execute([$undoProductId]);
    $undoPreparedInventoryId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO transactions (
            product_id, inventory_id, type, quantity, location, prepared_food,
            inventory_expiry_date, inventory_expiry_user_set,
            inventory_vacuum_sealed, inventory_opened_at
        )
        VALUES (?, ?, 'out', 1, 'frigo', 1, '2030-12-31', 1, 1, '2030-12-01 12:00:00')
    ")->execute([$undoProductId, $undoPreparedInventoryId]);
    $undoTransactionId = (int)$db->lastInsertId();
    $GLOBALS['TRANSACTION_UNDO_INPUT'] = ['id' => $undoTransactionId];
    ob_start();
    undoTransaction($db);
    $undoResponse = json_decode((string)ob_get_clean(), true);
    unset($GLOBALS['TRANSACTION_UNDO_INPUT']);
    recipeTestAssert(
        !empty($undoResponse['success'])
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM inventory
             WHERE product_id = ? AND quantity > 0 AND prepared_food = 1
               AND expiry_date = '2030-12-31'
               AND expiry_user_set = 1
               AND vacuum_sealed = 1
               AND opened_at = '2030-12-01 12:00:00'",
            [$undoProductId]
        ) === 1,
        'Undo must restore the complete prepared inventory batch snapshot: '
            . json_encode([
                'response' => $undoResponse,
                'rows' => $db->query(
                    "SELECT quantity, expiry_date, expiry_user_set, vacuum_sealed, opened_at, prepared_food
                     FROM inventory WHERE product_id = {$undoProductId}"
                )->fetchAll(PDO::FETCH_ASSOC),
            ])
    );
    recipeTestAssert(
        recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM transactions
             WHERE product_id = ? AND undone = 0",
            [$undoProductId]
        ) === 0,
        'Undo audit rows must not remain active in transaction analytics'
    );

    $productInsert->execute(['Undo Partial Add Test', 0]);
    $partialAddProduct = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO inventory (product_id, location, quantity, prepared_food)
        VALUES (?, 'dispensa', 0.5, 0)
    ")->execute([$partialAddProduct]);
    $partialAddInventory = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO transactions (
            product_id, inventory_id, type, quantity, location, prepared_food,
            inventory_expiry_user_set, inventory_vacuum_sealed
        )
        VALUES (?, ?, 'in', 1, 'dispensa', 0, 0, 0)
    ")->execute([$partialAddProduct, $partialAddInventory]);
    $partialAddTransaction = (int)$db->lastInsertId();
    $GLOBALS['TRANSACTION_UNDO_INPUT'] = ['id' => $partialAddTransaction];
    ob_start();
    undoTransaction($db);
    $partialUndo = json_decode((string)ob_get_clean(), true);
    unset($GLOBALS['TRANSACTION_UNDO_INPUT']);
    recipeTestAssert(
        http_response_code() === 409
        && ($partialUndo['error'] ?? '') === 'undo_conflict'
        && abs((float)$db->query(
            "SELECT quantity FROM inventory WHERE id = {$partialAddInventory}"
        )->fetchColumn() - 0.5) < 0.0001,
        'Undo must reject an add whose batch has already been partly consumed'
    );
    http_response_code(200);

    $productInsert->execute(['Accounting Undo Test', 0]);
    $accountingProduct = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO transactions (
            product_id, type, quantity, location, notes, accounting_only
        )
        VALUES (?, 'out', 5, 'dispensa', '[Riconciliazione] Test', 1)
    ")->execute([$accountingProduct]);
    $accountingTransaction = (int)$db->lastInsertId();
    $GLOBALS['TRANSACTION_UNDO_INPUT'] = ['id' => $accountingTransaction];
    ob_start();
    undoTransaction($db);
    $accountingUndo = json_decode((string)ob_get_clean(), true);
    unset($GLOBALS['TRANSACTION_UNDO_INPUT']);
    recipeTestAssert(
        !empty($accountingUndo['success'])
        && !empty($accountingUndo['accounting_only'])
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM inventory WHERE product_id = ?",
            [$accountingProduct]
        ) === 0,
        'Accounting-only reconciliation undo must not fabricate inventory'
    );

    $productInsert->execute(['Multi Batch Use Test', 0]);
    $multiBatchProduct = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO inventory (product_id, location, quantity, prepared_food)
        VALUES (?, 'dispensa', 1, 0), (?, 'dispensa', 2, 0)
    ")->execute([$multiBatchProduct, $multiBatchProduct]);
    ob_start();
    useFromInventoryCore($db, $multiBatchProduct, 3, false, 'dispensa', 'test multi batch');
    $multiBatchUse = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        !empty($multiBatchUse['success'])
        && recipeTestCount(
            $db,
            "SELECT CAST(COALESCE(SUM(quantity), 0) AS INTEGER)
             FROM inventory WHERE product_id = ?",
            [$multiBatchProduct]
        ) === 0
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM transactions
             WHERE product_id = ? AND type = 'out' AND undone = 0",
            [$multiBatchProduct]
        ) === 2,
        'Using all stock at a location must deduct and snapshot every batch'
    );

    $productInsert->execute(['Partial Multi Batch Use Test', 0]);
    $partialBatchProduct = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO inventory (product_id, location, quantity, prepared_food)
        VALUES (?, 'dispensa', 1, 0), (?, 'dispensa', 2, 0)
    ")->execute([$partialBatchProduct, $partialBatchProduct]);
    ob_start();
    useFromInventoryCore($db, $partialBatchProduct, 2.5, false, 'dispensa', 'test partial batches');
    $partialBatchUse = json_decode((string)ob_get_clean(), true);
    recipeTestAssert(
        !empty($partialBatchUse['success'])
        && abs((float)$db->query(
            "SELECT COALESCE(SUM(quantity), 0)
             FROM inventory WHERE product_id = {$partialBatchProduct}"
        )->fetchColumn() - 0.5) < 0.0001
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM transactions
             WHERE product_id = ? AND type = 'out' AND undone = 0",
            [$partialBatchProduct]
        ) === 2,
        'Partial quantities must deduct progressively across inventory batches'
    );

    $productInsert->execute(['Undo Split Batch Test', 0]);
    $splitUndoProduct = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO inventory (
            product_id, location, quantity, expiry_date, opened_at, prepared_food
        )
        VALUES (?, 'frigo', 0.75, '2030-01-05', '2030-01-01 10:00:00', 0)
    ")->execute([$splitUndoProduct]);
    $splitUndoInventory = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO transactions (
            product_id, inventory_id, type, quantity, location, prepared_food,
            inventory_expiry_date, inventory_expiry_user_set,
            inventory_vacuum_sealed
        )
        VALUES (?, ?, 'out', 0.25, 'frigo', 0, '2030-12-31', 0, 0)
    ")->execute([$splitUndoProduct, $splitUndoInventory]);
    $splitUndoTransaction = (int)$db->lastInsertId();
    $GLOBALS['TRANSACTION_UNDO_INPUT'] = ['id' => $splitUndoTransaction];
    ob_start();
    undoTransaction($db);
    $splitUndo = json_decode((string)ob_get_clean(), true);
    unset($GLOBALS['TRANSACTION_UNDO_INPUT']);
    recipeTestAssert(
        http_response_code() === 409
        && ($splitUndo['error'] ?? '') === 'undo_conflict',
        'Undo must reject a use after the original batch was split or transformed'
    );
    http_response_code(200);

    $db->prepare("
        INSERT INTO transactions (
            product_id, inventory_id, type, quantity, location,
            prepared_food, undo_safe
        )
        VALUES (?, ?, 'out', 0.25, 'frigo', 0, 0)
    ")->execute([$splitUndoProduct, $splitUndoInventory]);
    $unsafeUndoTransaction = (int)$db->lastInsertId();
    $GLOBALS['TRANSACTION_UNDO_INPUT'] = ['id' => $unsafeUndoTransaction];
    ob_start();
    undoTransaction($db);
    $unsafeUndo = json_decode((string)ob_get_clean(), true);
    unset($GLOBALS['TRANSACTION_UNDO_INPUT']);
    recipeTestAssert(
        http_response_code() === 409
        && ($unsafeUndo['error'] ?? '') === 'undo_conflict',
        'Transactions that changed package structure must be explicitly non-undoable'
    );
    http_response_code(200);

    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_PLANNER_ENABLED'
    ] = 'true';
    $plannerProbeCallsInTransaction = 0;
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
        static function () use (
            &$plannerProbeCallsInTransaction
        ): array {
            $plannerProbeCallsInTransaction++;
            return ['status' => 500, 'body' => '{}'];
        };
    $db->exec('BEGIN IMMEDIATE');
    try {
        $plannerMutationDetail = recipeCatalogDetailBuild(
            $db,
            $cookidooRecipeId,
            true,
            'active',
            false
        );
    } finally {
        $db->exec('ROLLBACK');
    }
    recipeTestAssert(
        $plannerProbeCallsInTransaction === 0
        && $plannerMutationDetail['planner']['available'] === false
        && $plannerMutationDetail['planner']['reason']
            === 'planner_probe_unavailable',
        'Detail projections used by mutations must suppress planner bridge probes while SQLite holds a transaction'
    );
    $GLOBALS['RECIPE_PLANNER_CAPABILITY'] = [
        'available' => true,
        'reason' => null,
        'put_semantics' => 'append',
        'account_scope' => 'configured_account',
    ];
    $plannerBridgeCalls = 0;
    $plannerLastPayload = null;
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
        static function (
            string $url,
            string $token,
            array $payload,
            int $timeout
        ) use (
            &$plannerBridgeCalls,
            &$plannerLastPayload,
            $db
        ): array {
            recipeTestAssert(
                !$db->inTransaction(),
                'Planner bridge traffic must never run inside a SQLite transaction'
            );
            recipeTestAssert(
                str_ends_with($url, '/v1/planner-add')
                && $token === 'unit-test-token'
                && $timeout === 5,
                'Planner bridge requests must retain internal auth and timeout bounds'
            );
            $plannerBridgeCalls++;
            $plannerLastPayload = $payload;
            return [
                'status' => 200,
                'body' => recipeCatalogJsonEncode([
                    'changed' => true,
                    'already_present' => false,
                    'verified' => true,
                    'date' => $payload['date'],
                    'account_scope' => 'configured_account',
                    'reconciled' => false,
                ]),
            ];
        };
    $plannerDetail = recipeCatalogDetail(
        $db,
        $cookidooRecipeId
    );
    $plannerDate = recipePlannerDateBounds()['minimum'];
    $plannerDate = (new DateTimeImmutable(
        $plannerDate,
        new DateTimeZone('UTC')
    ))->modify('+1 day')->format('Y-m-d');
    recipeTestAssert(
        $plannerDetail['capabilities']['planner'] === true
        && $plannerDetail['planner']['available'] === true
        && strlen((string)$plannerDetail['planner'][
            'provider_action_token'
        ]) === 64
        && $plannerDetail['planner']['account_scope']
            === 'configured_account',
        'Cookidoo details must expose a revision-bound planner token only through the enabled capability chain'
    );
    $plannerRequest = [
        'recipe_id' => $cookidooRecipeId,
        'date' => $plannerDate,
        'provider_action_token' =>
            $plannerDetail['planner']['provider_action_token'],
        'idempotency_key' => 'planner-command-success-1',
    ];
    $plannerResult = recipePlannerAdd($db, $plannerRequest);
    $plannerReplay = recipePlannerAdd($db, $plannerRequest);
    recipeTestAssert(
        $plannerResult['changed'] === true
        && $plannerResult['verified'] === true
        && $plannerReplay['replayed'] === true
        && $plannerBridgeCalls === 1
        && $plannerLastPayload['external_id']
            === $plannerDetail['source']['external_id']
        && !array_key_exists('external_id', $plannerRequest)
        && $plannerLastPayload['account_scope']
            === 'configured_account'
        && recipeTestCount(
            $db,
            "SELECT COUNT(*)
             FROM recipe_planner_command_events
             WHERE command_id = (
                 SELECT id FROM recipe_planner_commands
                 WHERE idempotency_key = 'planner-command-success-1'
             )
               AND state IN ('reserved', 'dispatching', 'succeeded', 'replayed')"
        ) === 4,
        'Planner commands must resolve provider identity server-side, journal append-only states, and replay without a second write'
    );
    $plannerConflict = false;
    try {
        recipePlannerAdd($db, array_merge($plannerRequest, [
            'date' => (new DateTimeImmutable(
                $plannerDate,
                new DateTimeZone('UTC')
            ))->modify('+1 day')->format('Y-m-d'),
        ]));
    } catch (RecipePlannerConflictException $e) {
        $plannerConflict =
            $e->getMessage() === 'idempotency_key_conflict';
    }
    recipeTestAssert(
        $plannerConflict && $plannerBridgeCalls === 1,
        'Planner idempotency keys must reject conflicting request fingerprints'
    );
    $plannerStaleToken = $plannerDetail['planner'][
        'provider_action_token'
    ];
    recipeScoreMarkCatalogDirty($db);
    $plannerStale = false;
    try {
        recipePlannerAdd($db, [
            'recipe_id' => $cookidooRecipeId,
            'date' => $plannerDate,
            'provider_action_token' => $plannerStaleToken,
            'idempotency_key' => 'planner-command-stale-1',
        ]);
    } catch (RecipePlannerConflictException $e) {
        $plannerStale =
            $e->getMessage() === 'recipe_planner_stale';
    }
    recipeTestAssert(
        $plannerStale
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_planner_commands
             WHERE idempotency_key = 'planner-command-stale-1'"
        ) === 0,
        'Provider action tokens must fail closed after catalog revision drift'
    );
    $plannerDetail = recipeCatalogDetail(
        $db,
        $cookidooRecipeId
    );
    $plannerTransientCalls = 0;
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
        static function (
            string $url,
            string $token,
            array $payload,
            int $timeout
        ) use (&$plannerTransientCalls, $db): array {
            recipeTestAssert(
                !$db->inTransaction(),
                'Planner retry transport must remain outside SQLite transactions'
            );
            $plannerTransientCalls++;
            if ($plannerTransientCalls === 1) {
                throw new RuntimeException(
                    'synthetic ambiguous planner timeout'
                );
            }
            return [
                'status' => 200,
                'body' => recipeCatalogJsonEncode([
                    'changed' => false,
                    'already_present' => true,
                    'verified' => true,
                    'date' => $payload['date'],
                    'account_scope' => 'configured_account',
                    'reconciled' => true,
                ]),
            ];
        };
    $transientRequest = [
        'recipe_id' => $cookidooRecipeId,
        'date' => $plannerDate,
        'provider_action_token' =>
            $plannerDetail['planner']['provider_action_token'],
        'idempotency_key' => 'planner-command-transient-1',
    ];
    $plannerTransientFailed = false;
    try {
        recipePlannerAdd($db, $transientRequest);
    } catch (RecipePlannerUnavailableException $e) {
        $plannerTransientFailed =
            $e->getMessage() === 'recipe_planner_retryable';
    }
    $plannerTransientResult = recipePlannerAdd(
        $db,
        $transientRequest
    );
    recipeTestAssert(
        $plannerTransientFailed
        && $plannerTransientResult['already_present'] === true
        && $plannerTransientCalls === 2
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_planner_commands
             WHERE idempotency_key = 'planner-command-transient-1'
               AND status = 'succeeded'"
        ) === 1,
        'Ambiguous planner transport failures must keep the same command resumable for bridge-side reconciliation'
    );
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
        static fn(
            string $url,
            string $token,
            array $payload,
            int $timeout
        ): array => [
            'status' => 403,
            'body' => recipeCatalogJsonEncode([
                'error' => 'cookidoo_upstream_forbidden',
            ]),
        ];
    $plannerBlockedRequest = [
        'recipe_id' => $cookidooRecipeId,
        'date' => $plannerDate,
        'provider_action_token' =>
            $plannerDetail['planner']['provider_action_token'],
        'idempotency_key' => 'planner-command-blocked-1',
    ];
    $plannerBlocked = false;
    try {
        recipePlannerAdd($db, $plannerBlockedRequest);
    } catch (RecipePlannerUnavailableException $e) {
        $plannerBlocked =
            $e->getMessage() === 'recipe_planner_circuit_open';
    }
    $plannerBlockedReplay = false;
    try {
        recipePlannerAdd($db, $plannerBlockedRequest);
    } catch (RecipePlannerUnavailableException $e) {
        $plannerBlockedReplay =
            $e->getMessage() === 'recipe_planner_circuit_open';
    }
    recipeTestAssert(
        $plannerBlocked
        && $plannerBlockedReplay
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM recipe_planner_commands
             WHERE idempotency_key = 'planner-command-blocked-1'
               AND status = 'blocked'"
        ) === 1,
        'Planner 403/429 circuit outcomes must be durable and replayed without blind writes'
    );
    $manualPlannerDetail = recipeCatalogDetail(
        $db,
        (int)$variantOne['id']
    );
    recipeTestAssert(
        $manualPlannerDetail['capabilities']['planner'] === false
        && $manualPlannerDetail['planner']['reason'] === 'not_cookidoo',
        'Non-Cookidoo recipes must never advertise planner actions'
    );
    $nonCookidooRejected = false;
    try {
        recipePlannerAdd($db, [
            'recipe_id' => (int)$variantOne['id'],
            'date' => $plannerDate,
            'provider_action_token' => str_repeat('a', 64),
            'idempotency_key' => 'planner-command-manual-1',
        ]);
    } catch (OutOfBoundsException $e) {
        $nonCookidooRejected =
            $e->getMessage() ===
                'recipe_planner_recipe_unavailable';
    }
    $invalidPlannerDate = false;
    try {
        recipePlannerNormalizeDate('2020-01-01');
    } catch (InvalidArgumentException $e) {
        $invalidPlannerDate = true;
    }
    recipeTestAssert(
        $nonCookidooRejected && $invalidPlannerDate,
        'Planner commands must reject non-Cookidoo recipes and out-of-range ISO dates'
    );
    $quarantinedPlannerRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Quarantined Planner Recipe',
        'source_ingredients' => [
            ['name' => 'water'],
            ['name' => 'salt'],
        ],
    ], [
        'connector' => RECIPE_COOKIDOO_CONNECTOR,
        'external_id' => 'planner-quarantined',
        'canonical_url' => (
            'https://cookidoo.co.uk/recipes/recipe/en-GB/'
            . 'planner-quarantined'
        ),
        'locale' => 'en-GB',
    ]);
    recipeCookidooLanguageAssessmentStore(
        $db,
        (int)$quarantinedPlannerRecipe['id'],
        recipeCookidooContentLanguageAssessment(
            'Quarantined Planner Recipe',
            [['name' => 'water'], ['name' => 'salt']]
        ),
        'quarantine',
        true
    );
    $quarantinedPlannerRejected = false;
    try {
        recipePlannerAdd($db, [
            'recipe_id' => (int)$quarantinedPlannerRecipe['id'],
            'date' => $plannerDate,
            'provider_action_token' => str_repeat('b', 64),
            'idempotency_key' =>
                'planner-command-quarantined-1',
        ]);
    } catch (OutOfBoundsException $e) {
        $quarantinedPlannerRejected = true;
    }
    recipeTestAssert(
        $quarantinedPlannerRejected,
        'Quarantined Cookidoo recipes must remain ineligible for planner writes'
    );
    $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_PLANNER_ENABLED'
    ] = 'false';
    unset($GLOBALS['RECIPE_PLANNER_CAPABILITY']);
    $plannerCallsWhileDisabled = 0;
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
        static function () use (&$plannerCallsWhileDisabled): array {
            $plannerCallsWhileDisabled++;
            return ['status' => 500, 'body' => '{}'];
        };
    $plannerDefaultOff = false;
    try {
        recipePlannerAdd($db, [
            'recipe_id' => $cookidooRecipeId,
            'date' => $plannerDate,
            'provider_action_token' => str_repeat('c', 64),
            'idempotency_key' => 'planner-command-disabled-1',
        ]);
    } catch (RecipePlannerUnavailableException $e) {
        $plannerDefaultOff = true;
    }
    recipeTestAssert(
        $plannerDefaultOff
        && $plannerCallsWhileDisabled === 0
        && !recipePlannerCapabilityEnabled(),
        'Default-off planner gates must suppress capability and make zero bridge requests'
    );
    unset($GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT']);

    $db->prepare("
        INSERT INTO products (
            name, unit, default_quantity, prepared_food
        )
        VALUES ('Inventory Add Transaction Regression', 'pz', 1, 0)
    ")->execute();
    $inventoryAddRegressionProductId = (int)$db->lastInsertId();
    $GLOBALS['INVENTORY_ADD_INPUT'] = [
        'product_id' => $inventoryAddRegressionProductId,
        'quantity' => 1,
        'location' => 'frigo',
    ];
    ob_start();
    addToInventory($db);
    $inventoryAddRegression = json_decode(
        (string)ob_get_clean(),
        true
    );
    unset($GLOBALS['INVENTORY_ADD_INPUT']);
    recipeTestAssert(
        ($inventoryAddRegression['success'] ?? false) === true
        && !$db->inTransaction()
        && recipeTestCount(
            $db,
            "SELECT COUNT(*)
             FROM inventory
             WHERE product_id = ?
               AND location = 'frigo'
               AND quantity = 1",
            [$inventoryAddRegressionProductId]
        ) === 1
        && recipeTestCount(
            $db,
            "SELECT COUNT(*)
             FROM recipe_jobs
             WHERE product_id = ?
               AND job_type = 'inventory_changed'
               AND status = 'pending'",
            [$inventoryAddRegressionProductId]
        ) === 1,
        'Inventory add must commit an immediate transaction without mixing PDO transaction APIs'
    );

    $manualTimeRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Deterministic Manual Time Facts',
        'servings' => 2,
        'prep_time' => 'PT15M',
        'cook_time' => '30 minuti',
        'active_time_seconds' => 600,
        'total_time_seconds' => 3600,
        'difficulty' => 'easy',
        'category' => 'Dinner',
        'devices' => ['Oven', 'oven'],
        'optional_devices' => ['Slow cooker', 'OVEN'],
        'equipment' => ['Mixing bowl'],
        'ingredients' => [['name' => 'Test ingredient']],
        'instructions' => ['Ignore this synthetic instruction duration: 99 hours.'],
    ], [
        'connector' => 'manual',
        'language' => 'it',
        'locale' => 'it-IT',
    ]);
    recipeTestAssert(
        $manualTimeRecipe['prep_time_seconds'] === 900
        && $manualTimeRecipe['cook_time_seconds'] === 1800
        && $manualTimeRecipe['inactive_time_seconds'] === null
        && $manualTimeRecipe['devices'] === ['Oven']
        && $manualTimeRecipe['optional_devices'] === ['Slow cooker']
        && $manualTimeRecipe['equipment'] === ['Mixing bowl'],
        'Manual catalog saves must derive only deterministic prep/cook fields '
            . 'and keep devices distinct from equipment'
    );
    $db->prepare("
        UPDATE recipe_catalog
        SET prep_time_seconds = NULL,
            cook_time_seconds = NULL,
            inactive_time_seconds = NULL
        WHERE id = ?
    ")->execute([(int)$manualTimeRecipe['id']]);
    $legacyManualTimeDetail = recipeCatalogDetail(
        $db,
        (int)$manualTimeRecipe['id']
    );
    recipeTestAssert(
        $legacyManualTimeDetail !== null
        && $legacyManualTimeDetail['general']['prep_time_seconds'] === 900
        && $legacyManualTimeDetail['general']['cook_time_seconds'] === 1800
        && $legacyManualTimeDetail['general']['inactive_time_seconds'] === null
        && $legacyManualTimeDetail['general']['devices'] === ['Oven']
        && $legacyManualTimeDetail['general']['optional_devices']
            === ['Slow cooker']
        && $legacyManualTimeDetail['general']['equipment'] === ['Mixing bowl'],
        'Legacy manual rows must derive bounded time facts at read time without '
            . 'using instruction text'
    );
    $inactiveOnlyRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Inactive Only Derivation',
        'active_time_seconds' => 900,
        'total_time_seconds' => 600,
        'ingredients' => [['name' => 'Second test ingredient']],
        'instructions' => ['Cook for 4 hours in this ignored synthetic step.'],
    ], [
        'connector' => 'manual',
        'language' => 'en',
        'locale' => 'en-US',
    ]);
    $inactiveOnlyDetail = recipeCatalogDetail(
        $db,
        (int)$inactiveOnlyRecipe['id']
    );
    recipeTestAssert(
        $inactiveOnlyDetail !== null
        && $inactiveOnlyDetail['general']['prep_time_seconds'] === null
        && $inactiveOnlyDetail['general']['cook_time_seconds'] === null
        && $inactiveOnlyDetail['general']['active_time_seconds'] === 900
        && $inactiveOnlyDetail['general']['inactive_time_seconds'] === 0
        && $inactiveOnlyDetail['general']['total_time_seconds'] === 600,
        'Total minus active must be labeled only as non-negative inactive/rest '
            . 'time and never as cook time'
    );
    $explicitTimeRecipe = recipeCatalogSaveVariant($db, [
        'title' => 'Explicit Time Fact Precedence',
        'prep_time' => '99 minutes',
        'cook_time' => '88 minutes',
        'prep_time_seconds' => 120,
        'cook_time_seconds' => 240,
        'inactive_time_seconds' => 30,
        'active_time_seconds' => 300,
        'total_time_seconds' => 600,
        'ingredients' => [['name' => 'Third test ingredient']],
    ], [
        'connector' => 'manual',
        'language' => 'en',
        'locale' => 'en-US',
    ]);
    recipeTestAssert(
        $explicitTimeRecipe['prep_time_seconds'] === 120
        && $explicitTimeRecipe['cook_time_seconds'] === 240
        && $explicitTimeRecipe['inactive_time_seconds'] === 30,
        'Explicit structured seconds must take precedence over legacy time strings'
    );

    $db->exec("
        INSERT INTO products (
            barcode, name, brand, category, image_url,
            unit, default_quantity, notes, package_unit,
            shopping_name, shopping_name_provenance,
            prepared_food
        )
        VALUES (
            'partial-save-barcode', 'Partial Save Product',
            'Preserved Brand', 'Preserved Category',
            'https://example.test/product.jpg', 'kg', 2.5,
            'Preserved notes', 'bag', 'Preserved shopping',
            'copilot', 0
        )
    ");
    $partialSaveProductId = (int)$db->lastInsertId();
    $deleteActiveRevision = recipeScoreActiveRevision($db);
    $deleteUsesV3 = $deleteActiveRevision !== null
        && (string)($deleteActiveRevision['scoring_model'] ?? '')
            === 'faceted-ontology-v3';
    $GLOBALS['PRODUCT_API_JSON_INPUT'] = [
        'id' => $partialSaveProductId,
        'name' => 'Partial Save Product Updated',
    ];
    ob_start();
    saveProduct($db);
    $partialSaveResponse = json_decode(
        (string)ob_get_clean(),
        true
    );
    unset($GLOBALS['PRODUCT_API_JSON_INPUT']);
    $partialSaveRow = $db->query("
        SELECT barcode, name, brand, category, image_url,
               unit, default_quantity, notes, package_unit,
               shopping_name, shopping_name_provenance
        FROM products
        WHERE id = {$partialSaveProductId}
    ")->fetch(PDO::FETCH_ASSOC);
    recipeTestAssert(
        !empty($partialSaveResponse['success'])
        && $partialSaveRow === [
            'barcode' => 'partial-save-barcode',
            'name' => 'Partial Save Product Updated',
            'brand' => 'Preserved Brand',
            'category' => 'Preserved Category',
            'image_url' => 'https://example.test/product.jpg',
            'unit' => 'kg',
            'default_quantity' => 2.5,
            'notes' => 'Preserved notes',
            'package_unit' => 'bag',
            'shopping_name' => 'Preserved shopping',
            'shopping_name_provenance' => 'copilot',
        ],
        'Partial product commits must preserve every omitted metadata field'
    );

    $GLOBALS['PRODUCT_API_JSON_INPUT'] = [
        'id' => $partialSaveProductId,
        'name' => 'Concurrent Partial Save Product',
    ];
    $GLOBALS['PRODUCT_SAVE_TEST_HOOK'] =
        static function (
            string $name
        ) use ($db, $partialSaveProductId): void {
            if ($name !== 'before_transaction') {
                return;
            }
            $db->prepare("
                UPDATE products
                SET barcode = 'concurrent-partial-barcode',
                    brand = 'Concurrent Brand',
                    category = 'Concurrent Category',
                    image_url = 'https://example.test/concurrent.jpg',
                    unit = 'l',
                    default_quantity = 4.5,
                    notes = 'Concurrent notes',
                    package_unit = 'bottle',
                    shopping_name = 'Concurrent shopping'
                WHERE id = ?
            ")->execute([$partialSaveProductId]);
        };
    ob_start();
    saveProduct($db);
    $concurrentPartialResponse = json_decode(
        (string)ob_get_clean(),
        true
    );
    unset(
        $GLOBALS['PRODUCT_API_JSON_INPUT'],
        $GLOBALS['PRODUCT_SAVE_TEST_HOOK']
    );
    $concurrentPartialRow = $db->query("
        SELECT barcode, name, brand, category, image_url,
               unit, default_quantity, notes, package_unit,
               shopping_name, shopping_name_provenance
        FROM products
        WHERE id = {$partialSaveProductId}
    ")->fetch(PDO::FETCH_ASSOC);
    recipeTestAssert(
        !empty($concurrentPartialResponse['success'])
        && $concurrentPartialRow === [
            'barcode' => 'concurrent-partial-barcode',
            'name' => 'Concurrent Partial Save Product',
            'brand' => 'Concurrent Brand',
            'category' => 'Concurrent Category',
            'image_url' => 'https://example.test/concurrent.jpg',
            'unit' => 'l',
            'default_quantity' => 4.5,
            'notes' => 'Concurrent notes',
            'package_unit' => 'bottle',
            'shopping_name' => 'Concurrent shopping',
            'shopping_name_provenance' => 'copilot',
        ],
        'Partial product commits must merge omitted fields after acquiring the write lock'
    );

    $duplicateRaceInserted = false;
    $GLOBALS['PRODUCT_API_JSON_INPUT'] = [
        'name' => 'Duplicate Barcode Race Product',
        'brand' => 'Test',
        'category' => 'Test',
        'barcode' => 'duplicate-barcode-race',
    ];
    $GLOBALS['PRODUCT_SAVE_TEST_HOOK'] =
        static function (
            string $name,
            array $context
        ) use ($db, &$duplicateRaceInserted): void {
            if (
                $name !== 'before_transaction'
                || $duplicateRaceInserted
                || !empty($context['id'])
            ) {
                return;
            }
            $duplicateRaceInserted = true;
            $db->exec("
                INSERT INTO products (
                    barcode, name, brand, category
                )
                VALUES (
                    'duplicate-barcode-race',
                    'Duplicate Barcode Race Product',
                    'Test',
                    'Test'
                )
            ");
        };
    ob_start();
    saveProduct($db);
    $duplicateRaceResponse = json_decode(
        (string)ob_get_clean(),
        true
    );
    unset(
        $GLOBALS['PRODUCT_API_JSON_INPUT'],
        $GLOBALS['PRODUCT_SAVE_TEST_HOOK']
    );
    $db->exec('BEGIN IMMEDIATE');
    $db->exec('ROLLBACK');
    recipeTestAssert(
        $duplicateRaceInserted
        && !empty($duplicateRaceResponse['success'])
        && !empty($duplicateRaceResponse['merged'])
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM products
             WHERE barcode = 'duplicate-barcode-race'"
        ) === 1,
        'Duplicate-barcode race recovery must roll back the failed insert and leave the connection transaction-ready'
    );

    $db->exec("
        INSERT INTO products (
            barcode, name, brand, category, notes
        )
        VALUES (
            'explicit-race-source',
            'Explicit Race Source',
            'Source Brand',
            'Source Category',
            'Source notes'
        )
    ");
    $explicitRaceSourceId = (int)$db->lastInsertId();
    $explicitRaceOwnerId = 0;
    $GLOBALS['PRODUCT_API_JSON_INPUT'] = [
        'id' => $explicitRaceSourceId,
        'name' => 'Unsafe Explicit Merge',
        'brand' => 'Requested Brand',
        'barcode' => 'explicit-race-target',
    ];
    $GLOBALS['PRODUCT_SAVE_TEST_HOOK'] =
        static function (
            string $name
        ) use ($db, &$explicitRaceOwnerId): void {
            if (
                $name !== 'before_transaction'
                || $explicitRaceOwnerId > 0
            ) {
                return;
            }
            $db->exec("
                INSERT INTO products (
                    barcode, name, brand, category, notes
                )
                VALUES (
                    'explicit-race-target',
                    'Competing Barcode Owner',
                    'Competing Brand',
                    'Competing Category',
                    'Competing notes'
                )
            ");
            $explicitRaceOwnerId = (int)$db->lastInsertId();
        };
    ob_start();
    saveProduct($db);
    $explicitRaceResponse = json_decode(
        (string)ob_get_clean(),
        true
    );
    unset(
        $GLOBALS['PRODUCT_API_JSON_INPUT'],
        $GLOBALS['PRODUCT_SAVE_TEST_HOOK']
    );
    $explicitRaceSource = $db->query("
        SELECT barcode, name, brand, category, notes
        FROM products
        WHERE id = {$explicitRaceSourceId}
    ")->fetch(PDO::FETCH_ASSOC);
    $explicitRaceOwner = $db->query("
        SELECT barcode, name, brand, category, notes
        FROM products
        WHERE id = {$explicitRaceOwnerId}
    ")->fetch(PDO::FETCH_ASSOC);
    recipeTestAssert(
        ($explicitRaceResponse['error'] ?? '')
            === 'barcode_already_used'
        && (int)($explicitRaceResponse['existing_id'] ?? 0)
            === $explicitRaceOwnerId
        && $explicitRaceSource === [
            'barcode' => 'explicit-race-source',
            'name' => 'Explicit Race Source',
            'brand' => 'Source Brand',
            'category' => 'Source Category',
            'notes' => 'Source notes',
        ]
        && $explicitRaceOwner === [
            'barcode' => 'explicit-race-target',
            'name' => 'Competing Barcode Owner',
            'brand' => 'Competing Brand',
            'category' => 'Competing Category',
            'notes' => 'Competing notes',
        ],
        'Explicit-ID barcode races must return 409 without overwriting either product'
    );

    $GLOBALS['PRODUCT_API_JSON_INPUT'] = [
        'name' => 'Injected Product Rollback',
        'brand' => 'Test',
        'category' => 'Test',
        'barcode' => 'injected-product-rollback',
    ];
    $GLOBALS['PRODUCT_SAVE_TEST_HOOK'] =
        static function (string $name): void {
            if ($name === 'before_commit') {
                throw new RuntimeException(
                    'injected_product_save_failure'
                );
            }
        };
    $injectedProductRollback = false;
    ob_start();
    try {
        saveProduct($db);
    } catch (RuntimeException $error) {
        $injectedProductRollback =
            $error->getMessage()
                === 'injected_product_save_failure';
    } finally {
        ob_end_clean();
        unset(
            $GLOBALS['PRODUCT_API_JSON_INPUT'],
            $GLOBALS['PRODUCT_SAVE_TEST_HOOK']
        );
    }
    $db->exec('BEGIN IMMEDIATE');
    $db->exec('ROLLBACK');
    recipeTestAssert(
        $injectedProductRollback
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM products
             WHERE barcode = 'injected-product-rollback'"
        ) === 0,
        'Injected product-save failure must roll back fully and leave subsequent transactions available'
    );

    $db->exec("
        INSERT INTO products (
            barcode, name, brand, category, prepared_food
        )
        VALUES (
            'inventory-prepared-controller-test',
            'Inventory Prepared Controller Test',
            'Test',
            'Test',
            0
        )
    ");
    $preparedFlipProductId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO inventory (
            product_id, location, quantity, prepared_food
        )
        VALUES (?, 'dispensa', 2, 0)
    ")->execute([$preparedFlipProductId]);
    $GLOBALS['ONTOLOGY_AUTONOMOUS_ENABLED_OVERRIDE'] = true;
    ingredientOntologyControllerObserveProductSafely(
        $db,
        $preparedFlipProductId
    );
    $preparedFlipSubject = ingredientOntologyControllerSubjectForOwner(
        $db,
        'product',
        $preparedFlipProductId
    );
    $db->prepare("
        UPDATE inventory
        SET prepared_food = 1
        WHERE product_id = ? AND quantity > 0
    ")->execute([$preparedFlipProductId]);
    $preparedFromInventory =
        _syncProductPreparedFood($db, $preparedFlipProductId);
    recipeTestAssert(
        $preparedFromInventory === 1
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM ontology_subject_occurrences
             WHERE owner_type = 'product'
               AND owner_id = ? AND active = 1",
            [$preparedFlipProductId]
        ) === 0
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM ontology_controller_jobs
             WHERE subject_id = ? AND finished_at IS NULL",
            [(int)$preparedFlipSubject['id']]
        ) === 0
        && (
            !$deleteUsesV3
            || recipeTestCount(
                $db,
                "SELECT COUNT(*)
                 FROM recipe_score_pending_products
                 WHERE product_id = ?",
                [$preparedFlipProductId]
            ) === 1
        ),
        'Inventory aggregation raw-to-prepared must deactivate controller occurrence/jobs'
    );
    $db->prepare("
        UPDATE inventory
        SET prepared_food = 0
        WHERE product_id = ? AND quantity > 0
    ")->execute([$preparedFlipProductId]);
    $rawFromInventory =
        _syncProductPreparedFood($db, $preparedFlipProductId);
    recipeTestAssert(
        $rawFromInventory === 0
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM ontology_subject_occurrences
             WHERE owner_type = 'product'
               AND owner_id = ? AND active = 1",
            [$preparedFlipProductId]
        ) === 1
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM ontology_controller_jobs
             WHERE subject_id = ?
               AND status = 'queued'",
            [(int)$preparedFlipSubject['id']]
        ) >= 1,
        'Inventory aggregation prepared-to-raw must observe and requeue controller coverage'
    );
    $GLOBALS['PRODUCT_API_JSON_INPUT'] = [
        'id' => $preparedFlipProductId,
    ];
    ob_start();
    deleteProduct($db);
    $deletePreparedResponse = json_decode(
        (string)ob_get_clean(),
        true
    );
    unset($GLOBALS['PRODUCT_API_JSON_INPUT']);
    recipeTestAssert(
        !empty($deletePreparedResponse['success'])
        && recipeTestCount(
            $db,
            'SELECT COUNT(*) FROM products WHERE id = ?',
            [$preparedFlipProductId]
        ) === 0
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM ontology_subject_occurrences
             WHERE owner_type = 'product'
               AND owner_id = ? AND active = 1",
            [$preparedFlipProductId]
        ) === 0
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM ontology_subject_occurrences
             WHERE owner_type = 'product' AND owner_id = ?",
            [$preparedFlipProductId]
        ) >= 1
        && recipeTestCount(
            $db,
            "SELECT COUNT(*) FROM ontology_controller_jobs
             WHERE subject_id = ? AND finished_at IS NULL",
            [(int)$preparedFlipSubject['id']]
        ) === 0,
        'deleteProduct must deactivate controller occurrence/jobs while retaining immutable history'
    );
    unset($GLOBALS['ONTOLOGY_AUTONOMOUS_ENABLED_OVERRIDE']);

    $db->prepare("
        INSERT INTO canonical_ingredients (
            slug, name, source, external_ids_json
        )
        VALUES (
            'foodon-parent-test',
            'FoodOn Parent Test',
            'test',
            ?
        )
        ON CONFLICT(slug) DO UPDATE SET
            external_ids_json = excluded.external_ids_json
    ")->execute([json_encode([
        'foodon' => ['id' => 'FOODON:TEST_PARENT'],
    ])]);
    $validFoodOnParent = [
        'short_form' => 'FOODON_00000001',
        'obo_id' => 'FOODON:00000001',
        'iri' =>
            'http://purl.obolibrary.org/obo/FOODON_00000001',
        'label' => 'Valid FoodOn Parent',
    ];
    $decodedFoodOnParent =
        canonicalIngredientFoodOnDecodeParentTerms(json_encode([
            '_embedded' => ['terms' => [$validFoodOnParent]],
        ]));
    $mixedFoodOnParents = $validFoodOnParent;
    unset($mixedFoodOnParents['label']);
    $mismatchedFoodOnIri = $validFoodOnParent;
    $mismatchedFoodOnIri['iri'] =
        'http://purl.obolibrary.org/obo/FOODON_00000002';
    $mismatchedFoodOnId = $validFoodOnParent;
    $mismatchedFoodOnId['obo_id'] = 'FOODON:00000002';
    $consistentForeignParent = [
        'short_form' => 'CHEBI_24866',
        'obo_id' => 'CHEBI:24866',
        'iri' =>
            'http://purl.obolibrary.org/obo/CHEBI_24866',
        'label' => 'salt',
    ];
    $crossNamespaceParent = [
        'short_form' => 'BFO_0000001',
        'obo_id' => 'BFO:0000001',
        'iri' =>
            'http://purl.obolibrary.org/obo/FOODON_00000001',
        'label' => 'Cross Namespace Parent',
    ];
    $decodedMixedNamespaceParents =
        canonicalIngredientFoodOnDecodeParentTerms(json_encode([
            '_embedded' => [
                'terms' => [
                    $consistentForeignParent,
                    $validFoodOnParent,
                ],
            ],
        ]));
    $decodedSearchDocs =
        canonicalIngredientFoodOnDecodeSearchDocs(json_encode([
            'response' => ['docs' => [$validFoodOnParent]],
        ]));
    $malformedRootIdentity = false;
    $mismatchedRoot = canonicalIngredientFoodOnSelectBest(
        [[
            'label' => 'Valid FoodOn Parent',
            'short_form' => 'FOODON_00000001',
            'obo_id' => 'FOODON:00000002',
            'iri' =>
                'http://purl.obolibrary.org/obo/FOODON_00000001',
            'type' => 'class',
        ]],
        'Valid FoodOn Parent',
        'Valid FoodOn Parent',
        $malformedRootIdentity
    );
    $validRootDoc = [
        'label' => 'Valid FoodOn Parent',
        'short_form' => 'FOODON_00000001',
        'obo_id' => 'FOODON:00000001',
        'iri' =>
            'http://purl.obolibrary.org/obo/FOODON_00000001',
        'type' => 'class',
        '_foodon_identity' => [
            'id' => 'FOODON:99999999',
            'iri' => 'http://invalid.example/forged',
        ],
        '_match_score' => 999,
    ];
    $validRootMalformed = null;
    $validRoot = canonicalIngredientFoodOnSelectBest(
        [$validRootDoc],
        'Valid FoodOn Parent',
        'Valid FoodOn Parent',
        $validRootMalformed
    );
    $mixedRootMalformed = null;
    $mixedImportedRoot = canonicalIngredientFoodOnSelectBest(
        [[
            'label' => 'salt',
            'short_form' => 'CHEBI_24866',
            'obo_id' => 'CHEBI:24866',
            'iri' =>
                'http://purl.obolibrary.org/obo/CHEBI_24866',
            'type' => 'class',
        ], $validRootDoc],
        'Valid FoodOn Parent',
        'Valid FoodOn Parent',
        $mixedRootMalformed
    );
    $foreignOnlyMalformed = null;
    $foreignOnlyRoot = canonicalIngredientFoodOnSelectBest(
        [[
            'label' => 'salt',
            'short_form' => 'CHEBI_24866',
            'obo_id' => 'CHEBI:24866',
            'iri' =>
                'http://purl.obolibrary.org/obo/CHEBI_24866',
            'type' => 'class',
        ]],
        'salt',
        'salt',
        $foreignOnlyMalformed
    );
    recipeTestAssert(
        canonicalIngredientFoodOnDecodeParentTerms('not-json') === null
        && canonicalIngredientFoodOnDecodeParentTerms('{}') === null
        && canonicalIngredientFoodOnDecodeSearchDocs('{}') === null
        && canonicalIngredientFoodOnDecodeSearchDocs(
            '{"response":{"docs":{}}}'
        ) === null
        && count($decodedSearchDocs ?? []) === 1
        && canonicalIngredientFoodOnDecodeParentTerms(
            '{"_embedded":{"terms":{}}}'
        ) === null
        && canonicalIngredientFoodOnDecodeParentTerms(json_encode([
            '_embedded' => [
                'terms' => [
                    $validFoodOnParent,
                    $mixedFoodOnParents,
                ],
            ],
        ])) === null
        && canonicalIngredientFoodOnDecodeParentTerms(json_encode([
            '_embedded' => ['terms' => []],
        ])) === []
        && canonicalIngredientFoodOnDecodeParentTerms(json_encode([
            '_embedded' => ['terms' => [$mismatchedFoodOnIri]],
        ])) === null
        && canonicalIngredientFoodOnDecodeParentTerms(json_encode([
            '_embedded' => ['terms' => [$mismatchedFoodOnId]],
        ])) === null
        && canonicalIngredientFoodOnDecodeParentTerms(json_encode([
            '_embedded' => ['terms' => [$crossNamespaceParent]],
        ])) === null
        && count($decodedMixedNamespaceParents ?? []) === 1
        && count($decodedFoodOnParent ?? []) === 1
        && $mismatchedRoot === null
        && $malformedRootIdentity
        && $validRootMalformed === false
        && ($validRoot['_foodon_identity']['id'] ?? null)
            === 'FOODON:00000001'
        && ($validRoot['_foodon_identity']['iri'] ?? null)
            === 'http://purl.obolibrary.org/obo/FOODON_00000001'
        && (int)($validRoot['_match_score'] ?? 0) !== 999
        && $mixedRootMalformed === false
        && ($mixedImportedRoot['_foodon_identity']['id'] ?? null)
            === 'FOODON:00000001'
        && $foreignOnlyRoot === null
        && $foreignOnlyMalformed === false,
        'Malformed FoodOn parent responses must remain retryable while a verified empty parent set is accepted'
    );
    $overflowParents = [];
    for ($index = 1; $index <= 17; $index++) {
        $short = 'FOODON_' . str_pad(
            (string)$index,
            8,
            '0',
            STR_PAD_LEFT
        );
        $overflowParents[] = [
            'id' => str_replace('_', ':', $short),
            'short_form' => $short,
            'iri' => 'http://purl.obolibrary.org/obo/' . $short,
            'label' => $index === 17
                ? 'Omitted Canonical Alternative'
                : 'FoodOn Parent ' . $index,
        ];
    }
    $overflowHierarchy = [];
    $overflowNext = [];
    $overflowSeen = [];
    $overflowComplete =
        canonicalIngredientFoodOnAppendHierarchyParents(
            $overflowHierarchy,
            $overflowNext,
            $overflowSeen,
            $overflowParents,
            1
        );
    recipeTestAssert(
        !$overflowComplete
        && count($overflowHierarchy) === 16
        && !in_array(
            'http://purl.obolibrary.org/obo/FOODON_00000017',
            $overflowNext,
            true
        ),
        'FoodOn parent overflow must remain incomplete instead of authorizing from a truncated hierarchy'
    );
    $foodOnResolved = canonicalIngredientResolveFoodOnParents(
        $db,
        [[
            'slug' => 'foodon-child-test',
            'name' => 'FoodOn Child Test',
            'parent_slug' => 'curated-parent-test',
            'external_ids' => [
                'foodon' => [
                    'id' => 'FOODON:TEST_CHILD',
                    'hierarchy' => [[
                        'id' => 'FOODON:TEST_PARENT',
                        'label' => 'FoodOn Parent Test',
                        'depth' => 2,
                    ]],
                ],
            ],
        ]]
    );
    recipeTestAssert(
        $foodOnResolved[0]['parent_slug'] === 'curated-parent-test'
        && $foodOnResolved[0]['external_ids']['foodon'][
            'resolved_parent'
        ]['id'] === 'FOODON:TEST_PARENT'
        && $foodOnResolved[0]['external_ids']['foodon'][
            'resolved_parent'
        ]['child_id'] === 'FOODON:TEST_CHILD',
        'FoodOn hierarchy must retain a child-bound canonical proof without creating a primary parent edge'
    );
    canonicalIngredientUpsert($db, $foodOnResolved[0]);
    canonicalIngredientUpsert($db, [
        'slug' => 'foodon-child-test',
        'name' => 'FoodOn Child Test',
        'parent_slug' => null,
        'category' => '',
        'external_ids' => [
            'foodon' => [
                'id' => 'FOODON:TEST_REPLACED_CHILD',
                'hierarchy' => [],
            ],
        ],
    ]);
    $refreshedFoodOn = json_decode(
        (string)$db->query("
            SELECT external_ids_json
            FROM canonical_ingredients
            WHERE slug = 'foodon-child-test'
        ")->fetchColumn(),
        true
    );
    recipeTestAssert(
        ($refreshedFoodOn['foodon']['id'] ?? null)
            === 'FOODON:TEST_REPLACED_CHILD'
        && ($refreshedFoodOn['foodon']['hierarchy'] ?? null) === []
        && !isset($refreshedFoodOn['foodon']['resolved_parent']),
        'A FoodOn identity refresh must replace stale hierarchy proof atomically'
    );
    canonicalIngredientUpsert($db, [
        'slug' => 'foodon-child-test',
        'name' => 'Product-local Link Only',
        'parent_slug' => 'different-parent',
        'category' => 'different-category',
        'external_ids' => [
            'foodon' => [
                'id' => 'FOODON:PRODUCT_LOCAL_ONLY',
            ],
        ],
    ], false);
    $linkOnlyFoodOn = $db->query("
        SELECT name, parent_slug, category, external_ids_json
        FROM canonical_ingredients
        WHERE slug = 'foodon-child-test'
    ")->fetch(PDO::FETCH_ASSOC);
    $linkOnlyExternalIds = json_decode(
        (string)$linkOnlyFoodOn['external_ids_json'],
        true
    );
    recipeTestAssert(
        (string)$linkOnlyFoodOn['name'] === 'FoodOn Child Test'
        && (string)$linkOnlyFoodOn['parent_slug']
            === 'curated-parent-test'
        && (string)$linkOnlyFoodOn['category'] === ''
        && ($linkOnlyExternalIds['foodon']['id'] ?? null)
            === 'FOODON:TEST_REPLACED_CHILD',
        'Product-local canonical linking must not rewrite shared '
            . 'canonical metadata'
    );
    $db->prepare("
        INSERT INTO canonical_ingredients (
            slug, name, source, external_ids_json
        )
        VALUES (
            'foodon-nearest-test',
            'FoodOn Nearest Test',
            'test',
            ?
        )
    ")->execute([json_encode([
        'foodon' => ['id' => 'FOODON:TEST_NEAREST'],
    ])]);
    $foodOnNearest = canonicalIngredientResolveFoodOnParents(
        $db,
        [[
            'slug' => 'foodon-nearest-child-test',
            'name' => 'FoodOn Nearest Child Test',
            'parent_slug' => null,
            'external_ids' => [
                'foodon' => [
                    'id' => 'FOODON:TEST_NEAREST_CHILD',
                    'hierarchy' => [[
                        'id' => 'FOODON:TEST_PARENT',
                        'depth' => 2,
                    ], [
                        'id' => 'FOODON:TEST_NEAREST',
                        'depth' => 1,
                    ]],
                ],
            ],
        ]]
    );
    $db->prepare("
        INSERT INTO canonical_ingredients (
            slug, name, source, external_ids_json
        )
        VALUES (
            'foodon-nearest-duplicate-test',
            'FoodOn Nearest Duplicate Test',
            'test',
            ?
        )
    ")->execute([json_encode([
        'foodon' => ['id' => 'FOODON:TEST_NEAREST'],
    ])]);
    $foodOnAmbiguous = canonicalIngredientResolveFoodOnParents(
        $db,
        [[
            'slug' => 'foodon-ambiguous-child-test',
            'name' => 'FoodOn Ambiguous Child Test',
            'parent_slug' => null,
            'external_ids' => [
                'foodon' => [
                    'id' => 'FOODON:TEST_AMBIGUOUS_CHILD',
                    'hierarchy' => [[
                        'id' => 'FOODON:TEST_NEAREST',
                        'depth' => 1,
                    ]],
                ],
            ],
        ]]
    );
    $foodOnTooDeep = canonicalIngredientResolveFoodOnParents(
        $db,
        [[
            'slug' => 'foodon-deep-child-test',
            'name' => 'FoodOn Deep Child Test',
            'parent_slug' => null,
            'external_ids' => [
                'foodon' => [
                    'id' => 'FOODON:TEST_DEEP_CHILD',
                    'hierarchy' => [[
                        'id' => 'FOODON:TEST_PARENT',
                        'depth' => 3,
                    ]],
                ],
            ],
        ]]
    );
    recipeTestAssert(
        ($foodOnNearest[0]['external_ids']['foodon'][
            'resolved_parent'
        ]['id'] ?? null) === 'FOODON:TEST_NEAREST'
        && !isset(
            $foodOnAmbiguous[0]['external_ids']['foodon'][
                'resolved_parent'
            ]
        )
        && !isset(
            $foodOnTooDeep[0]['external_ids']['foodon'][
                'resolved_parent'
            ]
        ),
        'FoodOn proof must select one unique nearest depth-1/2 canonical ancestor'
    );

    $disabledDb = new PDO('sqlite:' . $disabledPath);
    $disabledDb->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );
    $disabledDb->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );
    $disabledDb->exec('PRAGMA foreign_keys=ON');
    initializeDB($disabledDb);
    migrateDB($disabledDb);
    $disabledDb->exec('PRAGMA foreign_keys=OFF');
    $disabledDb->exec("
        DROP TABLE IF EXISTS ontology_generation_intents;
        DROP TABLE IF EXISTS ontology_quarantine_retries;
        DROP TABLE IF EXISTS ontology_provisional_queue;
        DROP TABLE IF EXISTS ontology_version_fork_id_map;
        DROP TABLE IF EXISTS ontology_version_fork_progress;
        DROP TABLE IF EXISTS ontology_generation_constraint_heads;
        DROP TABLE IF EXISTS ontology_generation_plans;
        DROP TABLE IF EXISTS ontology_gold_cases;
        DROP TABLE IF EXISTS ontology_gold_adversarial_candidates;
        DROP TABLE IF EXISTS ontology_gold_releases;
        DROP TABLE IF EXISTS ontology_generations;
        DROP TABLE IF EXISTS ontology_mutation_plans;
        DROP TABLE IF EXISTS ontology_controller_responses;
        DROP TABLE IF EXISTS ontology_controller_prompts;
        DROP TABLE IF EXISTS ontology_controller_jobs;
        DROP TABLE IF EXISTS ingredient_ontology_pair_constraints;
        DROP TABLE IF EXISTS ingredient_ontology_subject_resolutions;
        DROP TABLE IF EXISTS ontology_constraint_ledger;
        DROP TABLE IF EXISTS ontology_observation_events;
        DROP TABLE IF EXISTS ontology_subject_occurrences;
        DROP TABLE IF EXISTS ontology_subjects;
        DROP TABLE IF EXISTS ontology_coverage_state;
        DROP TABLE IF EXISTS ontology_backfill_state;
        DROP TABLE IF EXISTS ontology_controller_state;
        DROP TABLE IF EXISTS ontology_artifact_supersessions
    ");
    $disabledDb->exec('PRAGMA foreign_keys=ON');
    $disabledDb->exec("
        INSERT INTO products (
            barcode, name, brand, category
        )
        VALUES (
            'disabled-no-controller',
            'Disabled No Controller Product',
            'Test',
            'Test'
        )
    ");
    $disabledProductId = (int)$disabledDb->lastInsertId();
    $disabledDb->prepare("
        INSERT INTO inventory (product_id, location, quantity)
        VALUES (?, 'dispensa', 2)
    ")->execute([$disabledProductId]);
    $disabledQueue = canonicalIngredientEnqueueProduct(
        $disabledDb,
        $disabledProductId,
        'disabled_controller_test'
    );
    $disabledRecipe = recipeCatalogSaveVariant(
        $disabledDb,
        [
            'title' => 'Disabled Controller Decision',
            'ingredients' => [['name' => 'Disabled ingredient']],
            'steps' => ['Use it.'],
        ],
        [
            'connector' => 'manual',
            'external_id' => 'disabled-controller-decision',
        ]
    );
    $disabledDetail = recipeCatalogDetail(
        $disabledDb,
        (int)$disabledRecipe['id']
    );
    $disabledIngredient = $disabledDetail['ingredients'][0];
    $disabledDecision = recipeIngredientDecision(
        $disabledDb,
        [
            'recipe_id' => (int)$disabledRecipe['id'],
            'ingredient_key' => $disabledIngredient['key'],
            'position' => $disabledIngredient['position'],
            'action' => 'select_inventory_product',
            'selected_product_id' => $disabledProductId,
            'feedback_token' => $disabledIngredient['feedback_token'],
            'idempotency_key' => 'disabled-no-controller-decision',
            'action_origin' => 'test',
        ]
    );
    $GLOBALS['PRODUCT_API_JSON_INPUT'] = [
        'name' => 'Disabled Product Save',
        'brand' => 'Test',
        'category' => 'Test',
        'barcode' => 'disabled-product-save',
    ];
    ob_start();
    saveProduct($disabledDb);
    $disabledSave = json_decode((string)ob_get_clean(), true);
    unset($GLOBALS['PRODUCT_API_JSON_INPUT']);
    $disabledSavedId = (int)($disabledSave['id'] ?? 0);
    $disabledDb->exec("
        INSERT INTO products (name, brand, category)
        VALUES ('Disabled Merge Keep', 'Test', 'Test')
    ");
    $disabledMergeKeep = (int)$disabledDb->query("
        SELECT id FROM products
        WHERE name = 'Disabled Merge Keep'
        ORDER BY id DESC LIMIT 1
    ")->fetchColumn();
    $disabledDb->exec("
        INSERT INTO products (name, brand, category)
        VALUES ('Disabled Merge Drop', 'Test', 'Test')
    ");
    $disabledMergeDrop = (int)$disabledDb->query("
        SELECT id FROM products
        WHERE name = 'Disabled Merge Drop'
        ORDER BY id DESC LIMIT 1
    ")->fetchColumn();
    mergeProducts(
        $disabledDb,
        $disabledMergeKeep,
        $disabledMergeDrop
    );
    $GLOBALS['PRODUCT_API_JSON_INPUT'] = ['id' => $disabledSavedId];
    ob_start();
    deleteProduct($disabledDb);
    $disabledDelete = json_decode((string)ob_get_clean(), true);
    unset($GLOBALS['PRODUCT_API_JSON_INPUT']);
    $disabledCanonicalColumns = array_column(
        $disabledDb->query("
            PRAGMA table_info(canonical_processing_queue)
        ")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    $disabledProposalColumns = array_column(
        $disabledDb->query("
            PRAGMA table_info(recipe_ingredient_proposal_outbox)
        ")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    recipeTestAssert(
        !empty($disabledQueue['queued'])
        && $disabledDecision['availability'] === 'have'
        && $disabledDecision['constraint_epoch'] === 0
        && $disabledDecision['constraint_id'] === null
        && $disabledDecision['autonomous_job_id'] === null
        && !empty($disabledSave['success'])
        && !empty($disabledDelete['success'])
        && recipeTestCount(
            $disabledDb,
            'SELECT COUNT(*) FROM products WHERE id = ?',
            [$disabledMergeDrop]
        ) === 0
        && !ingredientOntologyControllerTableExists(
            $disabledDb,
            'ontology_controller_state'
        )
        && count(array_diff([
            'request_generation', 'lease_token',
            'lease_generation', 'lease_expires_at',
            'request_fingerprint',
        ], $disabledCanonicalColumns)) === 0
        && count(array_diff([
            'lease_token', 'lease_generation', 'lease_expires_at',
        ], $disabledProposalColumns)) === 0,
        'Disabled core product/canonical/recipe decision paths must work without controller tables'
    );
    $disabledDb = null;

    echo 'Recipe backend tests passed: '
        . number_format($recipeTestAssertions)
        . ' assertions; peak_php_mb='
        . number_format(memory_get_peak_usage(true) / 1048576, 2)
        . "\n";
} finally {
    $db = null;
    foreach ($cleanupPaths as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}
