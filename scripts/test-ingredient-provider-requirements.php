#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$assertions = 0;
function providerRequirementAssert(bool $condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function providerRequirementCount(
    PDO $db,
    string $sql,
    array $params = []
): int {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function providerRequirementAddRecipe(
    PDO $db,
    string $title,
    array $sourceRows,
    array $rankingRows,
    bool $currentSource = true
): int {
    $connector = $currentSource ? 'cookidoo' : 'manual';
    $insertRecipe = $db->prepare("
        INSERT INTO recipe_catalog (
            primary_connector, title, language, storage_policy, rights_basis
        )
        VALUES (?, ?, 'en-GB', 'persistent', 'user_or_generated')
    ");
    $insertRecipe->execute([$connector, $title]);
    $recipeId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO recipe_origins (
            recipe_id, connector, external_id, locale,
            metadata_version, metadata_schema_version
        )
        VALUES (?, ?, ?, 'en-GB', ?, ?)
    ")->execute([
        $recipeId,
        $connector,
        strtolower(str_replace(' ', '-', $title)),
        $currentSource ? RECIPE_COOKIDOO_METADATA_VERSION : null,
        $currentSource
            ? RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION
            : null,
    ]);
    $insertSource = $db->prepare("
        INSERT INTO recipe_source_ingredients (
            recipe_id, position, name, normalized_name,
            source_quantity, source_quantity_max, source_unit,
            source_amount_text, source_group_index,
            source_group_position, source_group_title,
            source_ingredient_ref, source_default_title,
            source_unit_ref, source_optional,
            source_shopping_category_ref, mapping_version
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                'legacy-v1')
    ");
    foreach ($sourceRows as $index => $row) {
        $position = array_key_exists('position', $row)
            ? (int)$row['position']
            : $index;
        $name = (string)$row['name'];
        $insertSource->execute([
            $recipeId,
            $position,
            $name,
            ingredientOntologyV3NormalizeLabel($name),
            $row['quantity'] ?? null,
            $row['quantity_max'] ?? null,
            $row['unit'] ?? null,
            $row['amount_text'] ?? null,
            $row['group'] ?? 0,
            $row['group_position'] ?? $position,
            $row['group_title'] ?? null,
            $row['ref'] ?? null,
            $row['title'] ?? null,
            $row['unit_ref'] ?? null,
            array_key_exists('optional', $row)
                ? (
                    $row['optional'] === null
                        ? null
                        : ($row['optional'] ? 1 : 0)
                )
                : null,
            $row['category_ref'] ?? null,
        ]);
    }
    $insertRanking = $db->prepare("
        INSERT INTO recipe_ingredients (
            recipe_id, position, raw_text, normalized_name,
            is_required, is_optional, is_staple,
            source_is_required, source_is_optional, requiredness_source,
            mapping_confidence, mapping_source
        )
        VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 'explicit_input', 0,
                'unresolved')
    ");
    foreach ($rankingRows as $index => $row) {
        $position = array_key_exists('position', $row)
            ? (int)$row['position']
            : $index;
        $name = (string)$row['name'];
        $optional = !empty($row['optional']);
        $insertRanking->execute([
            $recipeId,
            $position,
            $name,
            ingredientOntologyV3NormalizeLabel($name),
            $optional ? 0 : 1,
            $optional ? 1 : 0,
            $optional ? 0 : 1,
            $optional ? 1 : 0,
        ]);
    }
    return $recipeId;
}

function providerRequirementMetadataItem(
    string $externalId,
    string $name,
    string $providerRef,
    string $defaultTitle
): array {
    return [
        'external_id' => $externalId,
        'title' => 'Fingerprint metadata fixture',
        'metadata_schema_version' =>
            RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        'general' => [
            'yield_quantity' => null,
            'yield_unit' => null,
            'active_time_seconds' => null,
            'total_time_seconds' => null,
            'difficulty' => null,
            'primary_category' => null,
            'equipment' => [],
        ],
        'ingredients' => [[
            'name' => $name,
            'source_quantity' => null,
            'source_quantity_max' => null,
            'source_unit' => null,
            'source_amount_text' => null,
            'source_group_index' => 0,
            'source_group_position' => 0,
            'source_group_title' => null,
            'source_ingredient_ref' => $providerRef,
            'source_default_title' => $defaultTitle,
            'source_unit_ref' => null,
            'source_optional' => false,
            'source_shopping_category_ref' => null,
        ]],
        'topology_metrics' => [
            'group_count' => 1,
            'group_title_key_count' => 1,
            'group_title_nonempty_count' => 0,
            'group_title_length_total' => 0,
            'group_title_length_max' => 0,
            'ingredient_count' => 1,
            'ingredient_ref_key_count' => 1,
            'ingredient_ref_nonempty_count' => 1,
            'default_title_key_count' => 1,
            'default_title_nonempty_count' => 1,
            'unit_ref_key_count' => 1,
            'unit_ref_nonempty_count' => 0,
            'optional_key_count' => 1,
            'optional_true_count' => 0,
            'optional_false_count' => 1,
            'optional_null_count' => 0,
            'shopping_category_ref_key_count' => 1,
            'shopping_category_ref_nonempty_count' => 0,
        ],
        'image_url' => '',
        'canonical_url' => (
            'https://cookidoo.co.uk/recipes/recipe/en-GB/'
            . $externalId
        ),
        'locale' => 'en-GB',
    ];
}

$dbPath = __DIR__ . '/../data/.provider-requirement-test-'
    . getmypid() . '.sqlite';
$baselineUpgradePath = __DIR__
    . '/../data/.provider-baseline-upgrade-'
    . getmypid() . '.sqlite';
$emptyPrunePath = __DIR__
    . '/../data/.provider-empty-prune-'
    . getmypid() . '.sqlite';
$cleanup = [
    $dbPath,
    $dbPath . '-wal',
    $dbPath . '-shm',
    dirname($dbPath) . '/.' . basename($dbPath) . '.recipe-score.lock',
    $baselineUpgradePath,
    $baselineUpgradePath . '-wal',
    $baselineUpgradePath . '-shm',
    $emptyPrunePath,
    $emptyPrunePath . '-wal',
    $emptyPrunePath . '-shm',
];

try {
    foreach ($cleanup as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    initializeDB($db);
    migrateDB($db);
    $triggerSqlBefore = $db->query("
        SELECT group_concat(name || ':' || sql, char(10))
        FROM (
            SELECT name, sql
            FROM sqlite_master
            WHERE type = 'trigger'
              AND name LIKE 'ingredient_ontology_%immutable%'
            ORDER BY name
        )
    ")->fetchColumn();
    $secondConnection = new PDO('sqlite:' . $dbPath);
    $secondConnection->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );
    $secondConnection->exec('PRAGMA busy_timeout = 10000');
    for ($migrationPass = 0; $migrationPass < 10; $migrationPass++) {
        ingredientOntologyV3SchemaMigrate(
            $migrationPass % 2 === 0 ? $db : $secondConnection
        );
    }
    $triggerSqlAfter = $db->query("
        SELECT group_concat(name || ':' || sql, char(10))
        FROM (
            SELECT name, sql
            FROM sqlite_master
            WHERE type = 'trigger'
              AND name LIKE 'ingredient_ontology_%immutable%'
            ORDER BY name
        )
    ")->fetchColumn();
    providerRequirementAssert(
        $triggerSqlBefore === $triggerSqlAfter
        && $db->query("
            SELECT state_value
            FROM ingredient_ontology_schema_state
            WHERE state_key = 'immutable_trigger_version'
        ")->fetchColumn() === 'requirement-immutability-v3.6',
        'Repeated multi-connection schema migration must preserve a '
            . 'version-gated immutable-trigger set without replacement gaps'
    );
    $secondConnection = null;
    $freshBaselineForeignKeys = array_values(array_filter(
        $db->query("PRAGMA foreign_key_list(recipe_score_revisions)")
            ->fetchAll(PDO::FETCH_ASSOC),
        static fn(array $row): bool =>
            (string)$row['from']
                === 'parity_baseline_score_revision_id'
            && (string)$row['table'] === 'recipe_score_revisions'
            && strtolower((string)$row['on_delete']) === 'set null'
    ));
    $upgradeDb = new PDO('sqlite:' . $baselineUpgradePath);
    $upgradeDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $upgradeDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $upgradeDb->exec('PRAGMA foreign_keys = ON');
    $upgradeDb->exec("
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
        INSERT INTO recipe_score_revisions (
            inventory_revision, catalog_revision,
            inventory_fingerprint, score_date, status, recipe_count
        )
        VALUES (1, 1, 'legacy', date('now'), 'ready', 0);
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
        INSERT INTO recipe_score_state (id) VALUES (1)
    ");
    recipeSchemaMigrate($upgradeDb);
    $upgradedBaselineForeignKeys = array_values(array_filter(
        $upgradeDb->query(
            "PRAGMA foreign_key_list(recipe_score_revisions)"
        )->fetchAll(PDO::FETCH_ASSOC),
        static fn(array $row): bool =>
            (string)$row['from']
                === 'parity_baseline_score_revision_id'
            && (string)$row['table'] === 'recipe_score_revisions'
            && strtolower((string)$row['on_delete']) === 'set null'
    ));
    $upgradeDb->exec("
        DROP TRIGGER IF EXISTS
            recipe_score_revisions_parity_baseline_update
    ");
    $upgradeDb->exec('PRAGMA foreign_keys = OFF');
    ingredientOntologyV3SetReadyMutationGuard($upgradeDb, true);
    $upgradeDb->exec("
        UPDATE recipe_score_revisions
        SET parity_baseline_score_revision_id = 999
        WHERE id = 1
    ");
    ingredientOntologyV3SetReadyMutationGuard($upgradeDb, false);
    $upgradeDb->exec('PRAGMA foreign_keys = ON');
    recipeSchemaMigrate($upgradeDb);
    providerRequirementAssert(
        count($freshBaselineForeignKeys) === 1
        && count($upgradedBaselineForeignKeys) === 1
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'index'
               AND name =
                   'idx_recipe_score_revisions_parity_baseline'"
        ) === 1
        && providerRequirementCount(
            $upgradeDb,
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'index'
               AND name =
                   'idx_recipe_score_revisions_parity_baseline'"
        ) === 1
        && $upgradeDb->query("
            SELECT parity_baseline_score_revision_id
            FROM recipe_score_revisions WHERE id = 1
        ")->fetchColumn() === null,
        'Fresh and upgraded schemas must share parity baseline FK and index '
            . 'semantics: ' . ingredientOntologyV3Json([
                'fresh_fk' => $freshBaselineForeignKeys,
                'upgraded_fk' => $upgradedBaselineForeignKeys,
                'fresh_index' => providerRequirementCount(
                    $db,
                    "SELECT COUNT(*) FROM sqlite_master
                     WHERE type = 'index'
                       AND name =
                           'idx_recipe_score_revisions_parity_baseline'"
                ),
                'upgraded_index' => providerRequirementCount(
                    $upgradeDb,
                    "SELECT COUNT(*) FROM sqlite_master
                     WHERE type = 'index'
                       AND name =
                           'idx_recipe_score_revisions_parity_baseline'"
                ),
                'dangling' => $upgradeDb->query("
                    SELECT parity_baseline_score_revision_id
                    FROM recipe_score_revisions WHERE id = 1
                ")->fetchColumn(),
            ])
    );
    $upgradeDb = null;
    $emptyPruneDb = new PDO('sqlite:' . $emptyPrunePath);
    $emptyPruneDb->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );
    $emptyPruneDb->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );
    $emptyPruneDb->exec('PRAGMA foreign_keys = ON');
    initializeDB($emptyPruneDb);
    migrateDB($emptyPruneDb);
    $emptyPruneDb->prepare("
        INSERT INTO ingredient_ontology_versions (
            version, status, schema_hash, prompt_hash, model_hash,
            model_name, corpus_hash, content_hash, ready_at
        )
        VALUES ('empty-prune', 'ready', ?, ?, ?, ?, ?, ?,
                CURRENT_TIMESTAMP)
    ")->execute([
        str_repeat('1', 64),
        str_repeat('2', 64),
        str_repeat('3', 64),
        INGREDIENT_ONTOLOGY_V3_DEFAULT_MODEL,
        str_repeat('4', 64),
        str_repeat('5', 64),
    ]);
    $emptyVersionId = (int)$emptyPruneDb->lastInsertId();
    $emptyPruneDb->prepare("
        INSERT INTO ingredient_ontology_requirement_revisions (
            ontology_version_id, projection_model, status,
            source_corpus_hash, ontology_content_hash, mapping_hash
        )
        VALUES (?, ?, 'building', ?, ?, ?)
    ")->execute([
        $emptyVersionId,
        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
        str_repeat('6', 64),
        str_repeat('5', 64),
        str_repeat('7', 64),
    ]);
    $emptyRequirementRevisionId =
        (int)$emptyPruneDb->lastInsertId();
    $emptyPruneDb->exec("
        INSERT INTO recipe_catalog (title) VALUES ('Empty prune recipe')
    ");
    $emptyRecipeId = (int)$emptyPruneDb->lastInsertId();
    $emptyPruneDb->prepare("
        INSERT INTO ingredient_ontology_recipe_requirements (
            requirement_revision_id, ontology_version_id, recipe_id,
            requirement_key, basis, mapping_status, mapping_source,
            confidence, identity_basis, defining_signature,
            requiredness, contributor_count, quantity_audit_state
        )
        VALUES (?, ?, ?, ?, 'legacy', 'unresolved', 'unresolved',
                0, 'local_label', ?, 'required', 1, 'none')
    ")->execute([
        $emptyRequirementRevisionId,
        $emptyVersionId,
        $emptyRecipeId,
        hash('sha256', 'empty-prune-requirement'),
        hash('sha256', '{}'),
    ]);
    $emptyRequirementId = (int)$emptyPruneDb->lastInsertId();
    ingredientOntologyV3SetPublicationGuard($emptyPruneDb, true);
    try {
        $emptyPruneDb->prepare("
            UPDATE ingredient_ontology_requirement_revisions
            SET status = 'ready', recipe_count = 1,
                requirement_count = 1, completed_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$emptyRequirementRevisionId]);
    } finally {
        ingredientOntologyV3SetPublicationGuard($emptyPruneDb, false);
    }
    $emptyPruneDb->prepare("
        INSERT INTO recipe_score_revisions (
            inventory_revision, catalog_revision, inventory_fingerprint,
            score_date, status, recipe_count, ontology_version_id,
            scoring_model, requirement_revision_id, requirement_model
        )
        VALUES (1, 1, 'empty', date('now'), 'failed', 1, ?, ?, ?, ?)
    ")->execute([
        $emptyVersionId,
        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_SCORING_MODEL,
        $emptyRequirementRevisionId,
        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
    ]);
    $emptyScoreRevisionId = (int)$emptyPruneDb->lastInsertId();
    $emptyPruneDb->prepare("
        INSERT INTO ingredient_ontology_shadow_requirement_matches (
            score_revision_id, requirement_id, requirement_revision_id,
            outcome, satisfies_required, confidence, relationship
        )
        VALUES (?, ?, ?, 'failed', 0, 0, 'none')
    ")->execute([
        $emptyScoreRevisionId,
        $emptyRequirementId,
        $emptyRequirementRevisionId,
    ]);
    $emptyKeep = recipeScorePruneRevisions($emptyPruneDb);
    providerRequirementAssert(
        $emptyKeep === []
        && recipeScoreRevision(
            $emptyPruneDb,
            $emptyScoreRevisionId
        ) === null,
        'Fresh-schema empty score pruning must register the requirement '
            . 'guard and delete failed requirement-backed scores'
    );
    $emptyPruneDb = null;

    foreach ([
        'Water',
        'Salt',
        'Garlic Powder',
        'Fresh Garlic Cloves',
        'Olive Oil',
    ] as $name) {
        $db->prepare("
            INSERT INTO products (name, unit, default_quantity)
            VALUES (?, 'pz', 1)
        ")->execute([$name]);
        $productId = (int)$db->lastInsertId();
        $db->prepare("
            INSERT INTO inventory (
                product_id, location, quantity, prepared_food
            )
            VALUES (?, 'dispensa', 10, 0)
        ")->execute([$productId]);
    }

    $repeatedRecipe = providerRequirementAddRecipe(
        $db,
        'Repeated water sections',
        [
            [
                'name' => 'water',
                'ref' => 'provider-water',
                'title' => 'Water',
                'optional' => true,
                'group' => 0,
                'unit' => 'g',
                'quantity' => 100,
            ],
            [
                'name' => 'Wasser',
                'ref' => 'provider-water',
                'title' => 'Water',
                'optional' => false,
                'group' => 1,
                'unit' => 'ml',
                'quantity' => 50,
            ],
        ],
        [
            ['name' => 'salt', 'position' => 7],
            ['name' => 'water', 'position' => 9],
        ]
    );
    $differentRefRecipe = providerRequirementAddRecipe(
        $db,
        'Same label different refs',
        [
            [
                'name' => 'water',
                'ref' => 'provider-water',
                'title' => 'Water',
                'optional' => false,
            ],
            [
                'name' => 'water',
                'ref' => 'provider-water-alias',
                'title' => 'Water',
                'optional' => false,
            ],
        ],
        [['name' => 'garlic powder', 'position' => 12]]
    );
    providerRequirementAddRecipe(
        $db,
        'Semantic title variants one',
        [[
            'name' => 'jalapeño chillies',
            'ref' => 'provider-jalapeno',
            'title' => 'fresh jalapeño chilli',
            'optional' => false,
        ]],
        [['name' => 'salt']]
    );
    providerRequirementAddRecipe(
        $db,
        'Semantic title variants two',
        [[
            'name' => 'fresh jalapeño chillies',
            'ref' => 'provider-jalapeno',
            'title' => 'jalapeño chilli, fresh',
            'optional' => false,
        ]],
        [['name' => 'salt']]
    );
    providerRequirementAddRecipe(
        $db,
        'True provider title conflict one',
        [[
            'name' => 'water',
            'ref' => 'provider-conflict',
            'title' => 'Water',
            'optional' => false,
        ]],
        [['name' => 'water']]
    );
    providerRequirementAddRecipe(
        $db,
        'True provider title conflict two',
        [[
            'name' => 'salt',
            'ref' => 'provider-conflict',
            'title' => 'Salt',
            'optional' => false,
        ]],
        [['name' => 'salt']]
    );
    providerRequirementAddRecipe(
        $db,
        'Missing provider identity',
        [[
            'name' => 'mystery ingredient',
            'ref' => null,
            'title' => null,
            'optional' => null,
        ]],
        [['name' => 'water']]
    );
    providerRequirementAddRecipe(
        $db,
        'Missing provider title',
        [[
            'name' => 'water',
            'ref' => 'provider-missing-title',
            'title' => null,
            'optional' => false,
        ]],
        [['name' => 'water']]
    );
    providerRequirementAddRecipe(
        $db,
        'Generic provider title',
        [[
            'name' => 'cooking oil',
            'ref' => 'provider-any-oil',
            'title' => 'oil, any type',
            'optional' => false,
        ]],
        [['name' => 'olive oil']]
    );
    $expandingProviderLabel = str_repeat('&', 100);
    providerRequirementAddRecipe(
        $db,
        'Expanded normalized provider title',
        [[
            'name' => $expandingProviderLabel,
            'ref' => 'provider-expanded-normalization',
            'title' => $expandingProviderLabel,
            'optional' => false,
        ]],
        [['name' => 'water']]
    );
    $hardConflictRecipe = providerRequirementAddRecipe(
        $db,
        'Provider local hard conflict',
        [[
            'name' => 'fresh garlic cloves',
            'ref' => 'provider-garlic-powder',
            'title' => 'Garlic Powder',
            'optional' => false,
        ]],
        [['name' => 'fresh garlic cloves']]
    );
    $baseConflictRecipe = providerRequirementAddRecipe(
        $db,
        'Provider local base conflict',
        [[
            'name' => 'salt',
            'ref' => 'provider-water',
            'title' => 'Water',
            'optional' => false,
        ]],
        [['name' => 'salt']]
    );
    $unresolvedRecipe = providerRequirementAddRecipe(
        $db,
        'Fail closed unresolved rows',
        [
            [
                'name' => 'mystery dust',
                'ref' => 'provider-mystery',
                'title' => 'Mystery Dust',
                'optional' => null,
            ],
            [
                'name' => 'mystery dust',
                'ref' => 'provider-mystery',
                'title' => 'Mystery Dust',
                'optional' => null,
            ],
        ],
        [['name' => 'salt']]
    );
    $gapRecipe = providerRequirementAddRecipe(
        $db,
        'Incomplete source positions',
        [
            [
                'position' => 0,
                'name' => 'water',
                'ref' => 'provider-water',
                'title' => 'Water',
                'optional' => false,
            ],
            [
                'position' => 2,
                'name' => 'salt',
                'ref' => 'provider-salt',
                'title' => 'Salt',
                'optional' => false,
            ],
        ],
        [
            ['position' => 0, 'name' => 'olive oil'],
            ['position' => 2, 'name' => 'garlic powder'],
        ]
    );
    $fingerprintRecipe = providerRequirementAddRecipe(
        $db,
        'Fingerprint metadata fixture',
        [[
            'name' => 'water',
            'ref' => 'provider-fingerprint-water',
            'title' => 'Water',
            'optional' => false,
        ]],
        [['name' => 'water']]
    );
    $providerFreshRecipe = providerRequirementAddRecipe(
        $db,
        'Provider preserves fresh title facet',
        [[
            'name' => 'parsley',
            'ref' => 'provider-fresh-parsley',
            'title' => 'fresh parsley',
            'optional' => false,
        ]],
        [['name' => 'parsley']]
    );
    $providerDriedRecipe = providerRequirementAddRecipe(
        $db,
        'Provider preserves dried title facet',
        [[
            'name' => 'oregano',
            'ref' => 'provider-dried-oregano',
            'title' => 'dried oregano',
            'optional' => false,
        ]],
        [['name' => 'oregano']]
    );
    $providerFacetConflictRecipe = providerRequirementAddRecipe(
        $db,
        'Provider local fresh dried conflict',
        [[
            'name' => 'dried parsley',
            'ref' => 'provider-fresh-parsley-conflict',
            'title' => 'fresh parsley',
            'optional' => false,
        ]],
        [['name' => 'dried parsley']]
    );
    $providerBreadFlourRecipe = providerRequirementAddRecipe(
        $db,
        'Provider bread flour grade conflict',
        [[
            'name' => 'farinha tipo 65',
            'ref' => 'provider-bread-flour',
            'title' => 'bread flour',
            'optional' => false,
        ]],
        [['name' => 'farinha tipo 65']]
    );
    $ambiguousSalsaRecipe = providerRequirementAddRecipe(
        $db,
        'Bare salsa remains ambiguous',
        [[
            'name' => 'salsa',
            'ref' => 'provider-bare-salsa',
            'title' => 'salsa',
            'optional' => false,
        ]],
        [['name' => 'salsa']]
    );
    $deletedSourceRecipe = providerRequirementAddRecipe(
        $db,
        'Deleted source recipe',
        [[
            'name' => 'water',
            'ref' => 'provider-deleted-water',
            'title' => 'Water',
            'optional' => false,
        ]],
        [['name' => 'water']]
    );
    $db->prepare("
        UPDATE recipe_catalog
        SET deleted_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$deletedSourceRecipe]);
    $legacyRecipe = providerRequirementAddRecipe(
        $db,
        'Legacy parity recipe',
        [],
        [
            ['name' => 'water'],
            ['name' => 'salt'],
            ['name' => 'garlic powder', 'optional' => true],
        ],
        false
    );

    $legacy = recipeScoreRebuild($db, true, 50);
    $legacyRevisionId = (int)$legacy['revision_id'];
    $treeId = (int)$db->query("
        SELECT id FROM taxonomy_trees WHERE slug = 'food' LIMIT 1
    ")->fetchColumn();
    if ($treeId <= 0) {
        $db->exec("
            INSERT INTO taxonomy_trees (slug, name)
            VALUES ('food', 'Food')
        ");
        $treeId = (int)$db->lastInsertId();
    }
    foreach ([
        ['water', 'Water'],
        ['unstocked-remap', 'Unstocked Remap'],
    ] as [$slug, $name]) {
        $db->prepare("
            INSERT OR IGNORE INTO canonical_ingredients (slug, name)
            VALUES (?, ?)
        ")->execute([$slug, $name]);
        $db->prepare("
            INSERT OR IGNORE INTO taxonomy_nodes (
                tree_id, slug, name, source
            )
            VALUES (?, ?, ?, 'synthetic_test')
        ")->execute([$treeId, $slug, $name]);
    }
    $waterCanonicalId = (int)$db->query("
        SELECT id FROM canonical_ingredients WHERE slug = 'water'
    ")->fetchColumn();
    $unstockedCanonicalId = (int)$db->query("
        SELECT id FROM canonical_ingredients
        WHERE slug = 'unstocked-remap'
    ")->fetchColumn();
    $waterNodeId = (int)$db->query("
        SELECT id FROM taxonomy_nodes
        WHERE tree_id = {$treeId} AND slug = 'water'
    ")->fetchColumn();
    $unstockedNodeId = (int)$db->query("
        SELECT id FROM taxonomy_nodes
        WHERE tree_id = {$treeId} AND slug = 'unstocked-remap'
    ")->fetchColumn();
    $waterProductId = (int)$db->query("
        SELECT id FROM products WHERE name = 'Water'
        ORDER BY id LIMIT 1
    ")->fetchColumn();
    $db->prepare("
        UPDATE products SET name = 'Tap Water' WHERE id = ?
    ")->execute([$waterProductId]);
    $db->prepare("
        INSERT OR IGNORE INTO product_ingredients (
            product_id, ingredient_id, role, confidence, source, evidence
        )
        VALUES (?, ?, 'primary', 1.0, 'synthetic_test', 'catch-up fixture')
    ")->execute([$waterProductId, $waterCanonicalId]);
    $catchupRecipe = providerRequirementAddRecipe(
        $db,
        'Taxonomy remap catch-up',
        [],
        [['name' => 'water']],
        false
    );
    $catchupIngredientId = (int)$db->query("
        SELECT id FROM recipe_ingredients
        WHERE recipe_id = {$catchupRecipe}
        LIMIT 1
    ")->fetchColumn();
    $db->prepare("
        UPDATE recipe_ingredients
        SET canonical_ingredient_id = ?,
            taxonomy_node_id = ?,
            mapping_confidence = 1,
            mapping_source = 'synthetic_test',
            updated_at = datetime('now', '-1 second')
        WHERE id = ?
    ")->execute([
        $waterCanonicalId,
        $waterNodeId,
        $catchupIngredientId,
    ]);
    $GLOBALS['RECIPE_SCORE_BEFORE_FINAL_CATCHUP'] =
        static function (PDO $hookDb) use (
            $catchupIngredientId,
            $unstockedCanonicalId,
            $unstockedNodeId
        ): void {
            $hookDb->prepare("
                UPDATE recipe_ingredients
                SET canonical_ingredient_id = ?,
                    taxonomy_node_id = ?,
                    mapping_confidence = 0.99,
                    mapping_source = 'taxonomy_remap_test',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([
                $unstockedCanonicalId,
                $unstockedNodeId,
                $catchupIngredientId,
            ]);
        };
    $catchupBuild = recipeScoreRebuild($db, true, 50);
    unset($GLOBALS['RECIPE_SCORE_BEFORE_FINAL_CATCHUP']);
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT cookable
             FROM recipe_inventory_scores
             WHERE score_revision_id = ? AND recipe_id = ?",
            [(int)$catchupBuild['revision_id'], $catchupRecipe]
        ) === 0,
        'Final score catch-up must rescore taxonomy mapping changes made '
            . 'during the long build'
    );
    $db->prepare("
        UPDATE products SET name = 'Water' WHERE id = ?
    ")->execute([$waterProductId]);
    $atomicRemapRecipe = providerRequirementAddRecipe(
        $db,
        'Atomic taxonomy remap',
        [],
        [['name' => 'water']],
        false
    );
    $atomicRemapIngredientId = (int)$db->query("
        SELECT id FROM recipe_ingredients
        WHERE recipe_id = {$atomicRemapRecipe}
        LIMIT 1
    ")->fetchColumn();
    $db->prepare("
        INSERT INTO app_settings (key, value, updated_at)
        VALUES ('recipe_mapping_cursor', ?, CURRENT_TIMESTAMP)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value
    ")->execute([(string)max(0, $atomicRemapIngredientId - 1)]);
    $db->exec("
        CREATE TRIGGER fail_atomic_mapping_dirty
        BEFORE UPDATE ON recipe_score_state
        BEGIN
            SELECT RAISE(ABORT, 'synthetic score dirty failure');
        END
    ");
    $atomicRemapRejected = false;
    try {
        recipeCatalogRefreshUnresolvedMappings($db, 1);
    } catch (PDOException $e) {
        $atomicRemapRejected = str_contains(
            $e->getMessage(),
            'synthetic score dirty failure'
        );
    }
    $db->exec("DROP TRIGGER fail_atomic_mapping_dirty");
    providerRequirementAssert(
        $atomicRemapRejected
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*) FROM recipe_ingredients
             WHERE id = ?
               AND canonical_ingredient_id IS NULL
               AND taxonomy_node_id IS NULL",
            [$atomicRemapIngredientId]
        ) === 1,
        'Taxonomy remap and score/catalog dirty mutation must roll back atomically'
    );
    $atomicStateBefore = recipeScoreState($db);
    providerRequirementAssert(
        recipeCatalogRefreshUnresolvedMappings($db, 1) === 1
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*) FROM recipe_ingredients
             WHERE id = ?
               AND canonical_ingredient_id IS NOT NULL
               AND taxonomy_node_id IS NOT NULL",
            [$atomicRemapIngredientId]
        ) === 1
        && recipeScoreState($db)['catalog_revision']
            === $atomicStateBefore['catalog_revision'] + 1,
        'Successful taxonomy remap must commit mapping and catalog dirty state together'
    );
    $db->prepare("
        UPDATE recipe_ingredients
        SET canonical_ingredient_id = ?, taxonomy_node_id = ?,
            mapping_source = 'synthetic_cleanup'
        WHERE canonical_ingredient_id = ? OR taxonomy_node_id = ?
    ")->execute([
        $waterCanonicalId,
        $waterNodeId,
        $unstockedCanonicalId,
        $unstockedNodeId,
    ]);
    $db->prepare("
        DELETE FROM taxonomy_edges
        WHERE child_node_id = ? OR parent_node_id = ?
    ")->execute([$unstockedNodeId, $unstockedNodeId]);
    $db->prepare("
        DELETE FROM taxonomy_nodes WHERE id = ?
    ")->execute([$unstockedNodeId]);
    $db->prepare("
        DELETE FROM canonical_ingredients WHERE id = ?
    ")->execute([$unstockedCanonicalId]);
    $stateBefore = recipeScoreState($db);
    $candidate = ingredientOntologyV3BuildCandidate($db, [
        'version' => 'v3-provider-requirement-test',
    ]);
    $versionId = (int)$candidate['version_id'];
    providerRequirementAssert(
        $candidate['report']['provider_terms']['complete'],
        'Provider observations must cover every source row'
    );
    $providerAudit = ingredientOntologyV3ProviderAudit($db, $versionId);
    providerRequirementAssert(
        ($providerAudit['by_consistency']['consistent'] ?? 0) > 0
        && ($providerAudit['by_consistency']['variant'] ?? 0) === 1
        && ($providerAudit['by_consistency']['conflicted'] ?? 0) === 1,
        'Provider registry must distinguish consistent, semantic variants, '
            . 'and true title conflicts'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_provider_terms
             WHERE ontology_version_id = ?
               AND provider_ref = 'provider-water'
               AND consistency_state = 'consistent'
               AND mapping_status = 'accepted'
               AND review_state = 'accepted'",
            [$versionId]
        ) === 1,
        'A single exact consistent provider title may auto-accept'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_provider_terms
             WHERE ontology_version_id = ?
               AND provider_ref = 'provider-jalapeno'
               AND consistency_state = 'variant'
               AND mapping_status = 'unresolved'
               AND review_state = 'quarantined'
               AND terminal_disposition_id IN (
                   SELECT id
                   FROM ingredient_ontology_terminal_dispositions
                   WHERE ontology_version_id = ?
                     AND disposition_code = 'D8'
               )",
            [$versionId, $versionId]
        ) === 1,
        'Semantically similar title variants must terminate as provider-specific unresolved'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_provider_terms
             WHERE ontology_version_id = ?
               AND provider_ref = 'provider-conflict'
               AND consistency_state = 'conflicted'
               AND mapping_status = 'unresolved'
               AND review_state = 'quarantined'",
            [$versionId]
        ) === 1,
        'True provider title conflicts must be ambiguous'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_provider_terms
             WHERE ontology_version_id = ?
               AND provider_ref = 'provider-any-oil'
               AND is_generic = 1
               AND mapping_status = 'unresolved'
               AND review_state = 'quarantined'",
            [$versionId]
        ) === 1,
        'Generic any-type provider titles must never auto-accept'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_provider_terms
             WHERE ontology_version_id = ?
               AND provider_ref = 'provider-expanded-normalization'
               AND mapping_status = 'unresolved'
               AND review_state = 'quarantined'
               AND provenance = 'full-resolution-v3:D8'
               AND length(normalized_default_title) <= 200",
            [$versionId]
        ) === 1
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_provider_observations
             WHERE ontology_version_id = ?
               AND provider_ref = 'provider-expanded-normalization'
               AND normalized_default_title LIKE 'oversize-sha256:%'
               AND normalized_local_label LIKE 'oversize-sha256:%'",
            [$versionId]
        ) === 1,
        'Legal provider labels whose normalization expands beyond 200 '
            . 'characters must quarantine without aborting the corpus build'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_provider_observations
             WHERE ontology_version_id = ?
               AND provider_ref IS NULL
               AND ref_provenance = 'unknown_legacy_adapter'",
            [$versionId]
        ) === 1,
        'Missing provider refs must record unknown legacy provenance'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_provider_terms
             WHERE ontology_version_id = ?
               AND provider_ref = 'provider-missing-title'
               AND consistency_state = 'missing'
               AND mapping_status = 'unresolved'",
            [$versionId]
        ) === 1,
        'A persisted ref without a title must remain a missing unresolved term'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_provider_terms
             WHERE ontology_version_id = ?
               AND provider_ref = 'provider-bare-salsa'
               AND mapping_status = 'unresolved'
               AND review_state = 'quarantined'",
            [$versionId]
        ) === 1
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_mappings m
             JOIN recipe_source_ingredients si ON si.id = m.owner_id
             WHERE m.ontology_version_id = ?
               AND m.owner_type = 'recipe_source_ingredient'
               AND si.recipe_id = ?
               AND m.status <> 'accepted'",
            [$versionId, $ambiguousSalsaRecipe]
        ) === 1,
        'Bare salsa must remain unresolved without provider-specific '
            . 'identity context'
    );
    $providerFreshWithoutInjectedState = providerRequirementCount(
        $db,
        "SELECT COUNT(*)
         FROM ingredient_ontology_mappings m
         JOIN recipe_source_ingredients si ON si.id = m.owner_id
         WHERE m.ontology_version_id = ?
           AND m.owner_type = 'recipe_source_ingredient'
           AND si.recipe_id = ?
           AND m.status = 'accepted'
           AND json_extract(m.attributes_json, '$.state') IS NULL",
        [$versionId, $providerFreshRecipe]
    );
    $providerDriedLocalFacet = providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_mappings m
             JOIN recipe_source_ingredients si ON si.id = m.owner_id
             WHERE m.ontology_version_id = ?
               AND m.owner_type = 'recipe_source_ingredient'
               AND si.recipe_id = ?
               AND m.status = 'accepted'
               AND json_extract(m.attributes_json, '$.processing') IS NULL",
            [$versionId, $providerDriedRecipe]
        );
    $providerInjectedFreshAttribute = providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_mapping_attributes ma
             JOIN ingredient_ontology_mappings m ON m.id = ma.mapping_id
             JOIN ingredient_ontology_facets f ON f.id = ma.facet_id
             JOIN ingredient_ontology_facet_values fv
               ON fv.id = ma.facet_value_id
             JOIN recipe_source_ingredients si ON si.id = m.owner_id
             WHERE m.ontology_version_id = ?
               AND m.owner_type = 'recipe_source_ingredient'
               AND si.recipe_id = ?
               AND f.facet_key = 'state'
               AND fv.value_key = 'fresh'",
            [$versionId, $providerFreshRecipe]
        );
    providerRequirementAssert(
        $providerFreshWithoutInjectedState === 1
        && $providerDriedLocalFacet === 1
        && $providerInjectedFreshAttribute === 0,
        'Provider refs must not inject defining facets absent from the local '
            . 'reviewed identity: '
            . ingredientOntologyV3Json([
                'fresh_without_state' =>
                    $providerFreshWithoutInjectedState,
                'dried_local' => $providerDriedLocalFacet,
                'injected_fresh' => $providerInjectedFreshAttribute,
            ])
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_mappings m
             JOIN recipe_source_ingredients si ON si.id = m.owner_id
             WHERE m.ontology_version_id = ?
               AND m.owner_type = 'recipe_source_ingredient'
               AND si.recipe_id = ?
               AND m.status = 'unresolved'
               AND m.identity_basis = 'provider_local_conflict'",
            [$versionId, $providerFacetConflictRecipe]
        ) === 1,
        'Fresh/dried provider conflicts must terminate provider-specific'
    );
    $providerBreadTermAccepted = providerRequirementCount(
        $db,
        "SELECT COUNT(*)
         FROM ingredient_ontology_provider_terms
         WHERE ontology_version_id = ?
           AND provider_ref = 'provider-bread-flour'
           AND mapping_status = 'accepted'
           AND json_extract(attributes_json, '$.refinement') = 'bread'",
        [$versionId]
    );
    $providerBreadContextMissing = providerRequirementCount(
        $db,
        "SELECT COUNT(*)
         FROM ingredient_ontology_mappings m
         JOIN recipe_source_ingredients si ON si.id = m.owner_id
         WHERE m.ontology_version_id = ?
           AND m.owner_type = 'recipe_source_ingredient'
           AND si.recipe_id = ?
           AND m.status = 'unresolved'
           AND m.identity_basis = 'provider_candidate'
           AND json_extract(
               m.evidence_json,
               '$.context_gate_missing'
           ) = 1
           AND json_extract(
               m.evidence_json,
               '$.attribute_hints.refinement'
           ) = 'type_65'
           AND json_extract(
               m.evidence_json,
               '$.provider_term.provider_title_attributes.refinement'
           ) = 'bread'",
        [$versionId, $providerBreadFlourRecipe]
    );
    providerRequirementAssert(
        $providerBreadTermAccepted === 1
        && $providerBreadContextMissing === 1,
        'A context-missing local flour grade must preserve both local and '
            . 'provider facet evidence while failing closed: '
            . ingredientOntologyV3Json([
                'accepted_term_count' => $providerBreadTermAccepted,
                'context_missing_count' =>
                    $providerBreadContextMissing,
            ])
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_mappings m
             JOIN recipe_source_ingredients si ON si.id = m.owner_id
             WHERE m.ontology_version_id = ?
               AND m.owner_type = 'recipe_source_ingredient'
               AND si.recipe_id = ?
               AND m.status = 'unresolved'
               AND m.identity_basis = 'provider_local_conflict'",
            [$versionId, $hardConflictRecipe]
        ) === 1,
        'Provider/local defining-attribute conflicts must fail closed'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_mappings m
             JOIN recipe_source_ingredients si ON si.id = m.owner_id
             WHERE m.ontology_version_id = ?
               AND m.owner_type = 'recipe_source_ingredient'
               AND si.recipe_id = ?
               AND m.status = 'unresolved'
               AND m.identity_basis = 'provider_local_conflict'",
            [$versionId, $baseConflictRecipe]
        ) === 1
        && ($providerAudit['counts']['local_provider_base_conflicts'] ?? 0)
            > 0
        && ($providerAudit['counts']['local_provider_hard_conflicts'] ?? 0)
            > 0,
        'Provider audit must distinguish accepted-base and hard-facet conflicts'
    );
    providerRequirementAssert(
        $providerAudit['inverse_local_label_ambiguity']['count'] > 0
        && $providerAudit['inverse_title_ambiguity']['count'] > 0,
        'Audits must expose labels and titles observed under multiple refs'
    );

    $manualRecipeCountBeforeBounds = providerRequirementCount(
        $db,
        'SELECT COUNT(*) FROM recipe_catalog'
    );
    $oversizedSaveRejected = false;
    try {
        recipeCatalogSaveVariant($db, [
            'title' => 'Oversized legacy unit fixture',
            'ingredients' => [[
                'name' => 'water',
                'qty' => str_repeat('1', 161),
                'unit' => str_repeat('u', 81),
            ]],
        ], [
            'connector' => 'manual',
            'external_id' => 'oversized-legacy-unit-fixture',
        ]);
    } catch (InvalidArgumentException $e) {
        $oversizedSaveRejected = str_contains(
            $e->getMessage(),
            'too long'
        );
    }
    $controlSaveRejected = false;
    try {
        recipeCatalogSaveVariant($db, [
            'title' => 'Control legacy unit fixture',
            'ingredients' => [[
                'name' => 'water',
                'quantity_text' => "1\n2",
                'unit' => "m\tl",
            ]],
        ], [
            'connector' => 'manual',
            'external_id' => 'control-legacy-unit-fixture',
        ]);
    } catch (InvalidArgumentException $e) {
        $controlSaveRejected = str_contains(
            $e->getMessage(),
            'control characters'
        );
    }
    providerRequirementAssert(
        $oversizedSaveRejected
        && $controlSaveRejected
        && providerRequirementCount(
            $db,
            'SELECT COUNT(*) FROM recipe_catalog'
        ) === $manualRecipeCountBeforeBounds,
        'Manual recipe saves must reject oversized/control quantity and unit text'
    );
    $oversizedLegacyIngredientId = (int)$db->query("
        SELECT id FROM recipe_ingredients
        WHERE recipe_id = {$legacyRecipe}
        ORDER BY position DESC LIMIT 1
    ")->fetchColumn();
    $db->prepare("
        UPDATE recipe_ingredients
        SET quantity_text = ?, unit = ?
        WHERE id = ?
    ")->execute([
        str_repeat('q', 220),
        str_repeat('u', 120),
        $oversizedLegacyIngredientId,
    ]);
    $legacyIngredientId = (int)$db->query("
        SELECT id FROM recipe_ingredients
        WHERE recipe_id = {$legacyRecipe}
        ORDER BY position LIMIT 1
    ")->fetchColumn();
    $db->prepare("
        UPDATE recipe_ingredients
        SET quantity = 1, quantity_text = '1', unit = 'g'
        WHERE id = ?
    ")->execute([$legacyIngredientId]);

    $regularShadow = ingredientOntologyV3BuildShadow($db, $versionId, 50);
    $regularShadowId = (int)$regularShadow['revision_id'];
    $stateAfterRegularShadow = recipeScoreState($db);
    providerRequirementAssert(
        $stateAfterRegularShadow['active_score_revision_id']
            === $stateBefore['active_score_revision_id']
        && $stateAfterRegularShadow['inventory_revision']
            === $stateBefore['inventory_revision']
        && $stateAfterRegularShadow['catalog_revision']
            === $stateBefore['catalog_revision']
        && $stateAfterRegularShadow['cursor_revision']
            === $stateBefore['cursor_revision']
        && $stateAfterRegularShadow['ontology_source_revision']
            === $stateBefore['ontology_source_revision']
        && strlen($stateAfterRegularShadow['ontology_source_hash']) === 64,
        'Candidate and regular shadow builds must preserve active ranking '
            . 'state while sealing the current ontology source hash'
    );

    $originId = (int)$db->query("
        SELECT id FROM recipe_origins
        WHERE recipe_id = {$fingerprintRecipe}
        LIMIT 1
    ")->fetchColumn();
    $fingerprintOwnerId = (int)$db->query("
        SELECT id FROM recipe_source_ingredients
        WHERE recipe_id = {$fingerprintRecipe}
        LIMIT 1
    ")->fetchColumn();
    $storedFingerprintStmt = $db->prepare("
        SELECT owner_fingerprint
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
          AND owner_type = 'recipe_source_ingredient'
          AND owner_id = ?
    ");
    $storedFingerprintStmt->execute([$versionId, $fingerprintOwnerId]);
    $storedFingerprint = (string)$storedFingerprintStmt->fetchColumn();
    $fingerprintSourceBefore = $db->query("
        SELECT name, normalized_name, source_optional,
               source_ingredient_ref, source_default_title,
               canonical_ingredient_id, taxonomy_node_id,
               mapping_confidence, mapping_source
        FROM recipe_source_ingredients
        WHERE id = {$fingerprintOwnerId}
    ")->fetch(PDO::FETCH_ASSOC);
    $scoreStateBeforeMetadata = recipeScoreState($db);
    $scoreRevisionCountBefore = providerRequirementCount(
        $db,
        'SELECT COUNT(*) FROM recipe_score_revisions'
    );
    $changed = recipeCookidooApplyMetadataV2(
        $db,
        $fingerprintRecipe,
        $originId,
        providerRequirementMetadataItem(
            'fingerprint-metadata-fixture',
            'water',
            'provider-fingerprint-water',
            'Drinking Water'
        ),
        gmdate('Y-m-d H:i:s')
    );
    providerRequirementAssert(
        !empty($changed['ontology_source_changed'])
        && empty($changed['score_catalog_dirty_required'])
        && recipeScoreState($db)['active_score_revision_id']
            === $scoreStateBeforeMetadata['active_score_revision_id']
        && recipeScoreState($db)['inventory_revision']
            === $scoreStateBeforeMetadata['inventory_revision']
        && recipeScoreState($db)['catalog_revision']
            === $scoreStateBeforeMetadata['catalog_revision']
        && recipeScoreState($db)['ontology_source_revision']
            > $scoreStateBeforeMetadata['ontology_source_revision']
        && recipeScoreState($db)['ontology_source_hash'] === ''
        && providerRequirementCount(
            $db,
            'SELECT COUNT(*) FROM recipe_score_revisions'
        ) === $scoreRevisionCountBefore,
        'Default-title metadata changes must not dirty or rebuild scores'
    );
    providerRequirementAssert(
        ingredientOntologyV3CurrentOwnerFingerprint(
            $db,
            'recipe_source_ingredient',
            $fingerprintOwnerId
        ) !== $storedFingerprint,
        'Default-title changes must invalidate the immutable owner fingerprint'
    );
    recipeCookidooApplyMetadataV2(
        $db,
        $fingerprintRecipe,
        $originId,
        providerRequirementMetadataItem(
            'fingerprint-metadata-fixture',
            'water',
            'provider-fingerprint-water',
            'Water'
        ),
        gmdate('Y-m-d H:i:s', time() + 1)
    );
    providerRequirementAssert(
        ingredientOntologyV3CurrentOwnerFingerprint(
            $db,
            'recipe_source_ingredient',
            $fingerprintOwnerId
        ) !== $storedFingerprint,
        'Restoring one title field must not hide other source mapping drift'
    );

    $db->prepare("
        UPDATE recipe_source_ingredients
        SET name = 'salt', normalized_name = 'salt'
        WHERE id = ?
    ")->execute([$fingerprintOwnerId]);
    $staleFingerprintAudit =
        ingredientOntologyV3OwnerFingerprintAudit($db, $versionId);
    $staleProjectionRejected = false;
    try {
        ingredientOntologyV3BuildRequirementProjection(
            $db,
            $versionId,
            50
        );
    } catch (RuntimeException $e) {
        $staleProjectionRejected = str_contains(
            $e->getMessage(),
            'source owner fingerprints are stale'
        );
    }
    $failedRequirementRevisionId = (int)$db->query("
        SELECT id
        FROM ingredient_ontology_requirement_revisions
        WHERE ontology_version_id = {$versionId}
        ORDER BY id DESC LIMIT 1
    ")->fetchColumn();
    providerRequirementAssert(
        !$staleFingerprintAudit['valid']
        && $staleProjectionRejected
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_revisions
             WHERE id = ? AND status = 'failed'",
            [$failedRequirementRevisionId]
        ) === 1
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_recipe_requirements
             WHERE requirement_revision_id = ?",
            [$failedRequirementRevisionId]
        ) === 0,
        'Changed source rows must fail projection and leave no ready or '
            . 'partial requirement snapshot'
    );
    $db->prepare("
        UPDATE recipe_source_ingredients
        SET name = ?, normalized_name = ?, source_optional = ?,
            source_ingredient_ref = ?, source_default_title = ?,
            canonical_ingredient_id = ?, taxonomy_node_id = ?,
            mapping_confidence = ?, mapping_source = ?
        WHERE id = ?
    ")->execute([
        $fingerprintSourceBefore['name'],
        $fingerprintSourceBefore['normalized_name'],
        $fingerprintSourceBefore['source_optional'],
        $fingerprintSourceBefore['source_ingredient_ref'],
        $fingerprintSourceBefore['source_default_title'],
        $fingerprintSourceBefore['canonical_ingredient_id'],
        $fingerprintSourceBefore['taxonomy_node_id'],
        $fingerprintSourceBefore['mapping_confidence'],
        $fingerprintSourceBefore['mapping_source'],
        $fingerprintOwnerId,
    ]);
    providerRequirementAssert(
        ingredientOntologyV3OwnerFingerprintAudit(
            $db,
            $versionId
        )['valid'],
        'Restoring a source row must restore mapping fingerprint validity'
    );
    $GLOBALS[
        'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SOURCE_AUDIT'
    ] = static function (PDO $hookDb) use ($fingerprintOwnerId): void {
        $hookDb->prepare("
            UPDATE recipe_source_ingredients
            SET name = 'salt', normalized_name = 'salt'
            WHERE id = ?
        ")->execute([$fingerprintOwnerId]);
    };
    $midBuildMutationRejected = false;
    $midBuildMutationError = '';
    try {
        ingredientOntologyV3BuildRequirementProjection(
            $db,
            $versionId,
            50
        );
    } catch (RuntimeException $e) {
        $midBuildMutationError = $e->getMessage();
        $midBuildMutationRejected = str_contains(
            $e->getMessage(),
            'inputs changed'
        ) || str_contains(
            $e->getMessage(),
            'stale source mapping fingerprint'
        );
    }
    unset(
        $GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SOURCE_AUDIT'
        ]
    );
    $midBuildFailedId = (int)$db->query("
        SELECT id
        FROM ingredient_ontology_requirement_revisions
        WHERE ontology_version_id = {$versionId}
        ORDER BY id DESC LIMIT 1
    ")->fetchColumn();
    providerRequirementAssert(
        $midBuildMutationRejected
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_revisions
             WHERE id = ? AND status = 'failed'",
            [$midBuildFailedId]
        ) === 1
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_recipe_requirements
             WHERE requirement_revision_id = ?",
            [$midBuildFailedId]
        ) === 0,
        'A source mutation after the reserved input snapshot must fail final '
            . 'publication validation and clean partial output: '
            . $midBuildMutationError
    );
    $db->prepare("
        UPDATE recipe_source_ingredients
        SET name = 'water', normalized_name = 'water'
        WHERE id = ?
    ")->execute([$fingerprintOwnerId]);
    $GLOBALS[
        'INGREDIENT_ONTOLOGY_V3_BEFORE_REQUIREMENT_PUBLICATION_RESERVATION'
    ] = static function (PDO $hookDb) use ($fingerprintOwnerId): void {
        $hookDb->prepare("
            UPDATE recipe_source_ingredients
            SET source_default_title = 'Changed Before Publication'
            WHERE id = ?
        ")->execute([$fingerprintOwnerId]);
    };
    $publicationRaceRejected = false;
    try {
        ingredientOntologyV3BuildRequirementProjection(
            $db,
            $versionId,
            50
        );
    } catch (RuntimeException $e) {
        $publicationRaceRejected = str_contains(
            $e->getMessage(),
            'inputs changed'
        ) || str_contains(
            $e->getMessage(),
            'stale source mapping fingerprint'
        );
    }
    unset(
        $GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_BEFORE_REQUIREMENT_PUBLICATION_RESERVATION'
        ]
    );
    $publicationRaceRevisionId = (int)$db->query("
        SELECT id
        FROM ingredient_ontology_requirement_revisions
        WHERE ontology_version_id = {$versionId}
        ORDER BY id DESC LIMIT 1
    ")->fetchColumn();
    providerRequirementAssert(
        $publicationRaceRejected
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_revisions
             WHERE id = ? AND status = 'failed'",
            [$publicationRaceRevisionId]
        ) === 1
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_recipe_requirements
             WHERE requirement_revision_id = ?",
            [$publicationRaceRevisionId]
        ) === 0,
        'Metadata mutation before publication reservation must never publish '
            . 'a stale or empty ready projection'
    );
    $db->prepare("
        UPDATE recipe_source_ingredients
        SET source_default_title = 'Water'
        WHERE id = ?
    ")->execute([$fingerprintOwnerId]);
    $restoredOwnerFingerprint =
        ingredientOntologyV3CurrentOwnerFingerprint(
            $db,
            'recipe_source_ingredient',
            $fingerprintOwnerId
        );
    ingredientOntologyV3SetReadyMutationGuard($db, true);
    $db->prepare("
        UPDATE ingredient_ontology_mappings
        SET owner_fingerprint = ?
        WHERE ontology_version_id = ?
          AND owner_type = 'recipe_source_ingredient'
          AND owner_id = ?
    ")->execute([
        $restoredOwnerFingerprint,
        $versionId,
        $fingerprintOwnerId,
    ]);
    $db->prepare("
        UPDATE ingredient_ontology_provider_observations
        SET owner_fingerprint = ?
        WHERE ontology_version_id = ?
          AND owner_type = 'recipe_source_ingredient'
          AND owner_id = ?
    ")->execute([
        $restoredOwnerFingerprint,
        $versionId,
        $fingerprintOwnerId,
    ]);
    ingredientOntologyV3ResealVersionForTest($db, $versionId);
    ingredientOntologyV3SetReadyMutationGuard($db, false);
    $createImmediateCrashedRequirement =
        static function (PDO $crashDb) use (
            $versionId,
            $legacyRecipe
        ): int {
            $version = ingredientOntologyV3Version(
                $crashDb,
                $versionId
            );
            $crashDb->prepare("
                INSERT INTO ingredient_ontology_requirement_revisions (
                    ontology_version_id, projection_model, status,
                    source_corpus_hash, ontology_content_hash, mapping_hash
                )
                VALUES (?, ?, 'building', ?, ?, ?)
            ")->execute([
                $versionId,
                INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
                ingredientOntologyV3SourceCorpusHash(
                    $crashDb,
                    $versionId
                ),
                $version['content_hash'],
                ingredientOntologyV3MappingHash(
                    $crashDb,
                    $versionId
                ),
            ]);
            $crashedId = (int)$crashDb->lastInsertId();
            $crashDb->prepare("
                INSERT INTO ingredient_ontology_recipe_requirements (
                    requirement_revision_id, ontology_version_id,
                    recipe_id, requirement_key, basis,
                    mapping_status, mapping_source, confidence,
                    identity_basis, defining_signature, requiredness,
                    contributor_count, quantity_audit_state
                )
                VALUES (?, ?, ?, ?, 'legacy', 'unresolved',
                        'unresolved', 0, 'local_label', ?,
                        'required', 1, 'none')
            ")->execute([
                $crashedId,
                $versionId,
                $legacyRecipe,
                hash('sha256', 'immediate-crash-' . $crashedId),
                hash('sha256', '{}'),
            ]);
            return $crashedId;
        };
    $immediateCrashOne = $createImmediateCrashedRequirement($db);
    $GLOBALS[
        'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SOURCE_AUDIT'
    ] = static function (PDO $hookDb) use ($fingerprintOwnerId): void {
        $hookDb->prepare("
            UPDATE recipe_source_ingredients
            SET name = 'salt', normalized_name = 'salt'
            WHERE id = ?
        ")->execute([$fingerprintOwnerId]);
        $hookDb->prepare("
            UPDATE recipe_source_ingredients
            SET name = 'water', normalized_name = 'water'
            WHERE id = ?
        ")->execute([$fingerprintOwnerId]);
    };
    $projection = ingredientOntologyV3BuildRequirementProjection(
        $db,
        $versionId,
        50
    );
    unset(
        $GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SOURCE_AUDIT'
        ]
    );
    $requirementRevisionId =
        (int)$projection['requirement_revision_id'];
    providerRequirementAssert(
        !empty($projection['input_snapshot_materialized'])
        && strlen((string)$projection['input_snapshot_hash']) === 64
        && $projection['input_snapshot_hash'] !== str_repeat('0', 64)
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_members
             WHERE requirement_revision_id = ?
               AND owner_type = 'recipe_source_ingredient'
               AND owner_id = ?
               AND source_label = 'water'",
            [$requirementRevisionId, $fingerprintOwnerId]
        ) === 1
        && providerRequirementCount(
            $db,
            "SELECT
                 (SELECT COUNT(*)
                  FROM ingredient_ontology_requirement_input_recipes
                  WHERE requirement_revision_id = ?)
               + (SELECT COUNT(*)
                  FROM ingredient_ontology_requirement_input_rows
                  WHERE requirement_revision_id = ?)",
            [$requirementRevisionId, $requirementRevisionId]
        ) === 0,
        'An ABA source mutation must project the exact reserved input '
            . 'snapshot rather than any transient live row, then clean '
            . 'bounded staging payloads'
    );
    $requirementAudit = ingredientOntologyV3RequirementAudit(
        $db,
        $requirementRevisionId
    );
    $boundedLegacyMember = $db->prepare("
        SELECT length(source_amount_text), length(source_unit)
        FROM ingredient_ontology_requirement_members
        WHERE requirement_revision_id = ?
          AND owner_type = 'recipe_ingredient'
          AND owner_id = ?
    ");
    $boundedLegacyMember->execute([
        $requirementRevisionId,
        $oversizedLegacyIngredientId,
    ]);
    $boundedLegacyMember = $boundedLegacyMember->fetch(PDO::FETCH_NUM);
    providerRequirementAssert(
        (int)$boundedLegacyMember[0] === 160
        && (int)$boundedLegacyMember[1] === 80,
        'Preexisting oversized legacy quantity/unit snapshots must be '
            . 'defensively bounded during projection'
    );
    $reusedProjection = ingredientOntologyV3BuildRequirementProjection(
        $db,
        $versionId,
        50
    );
    $immediateCrashTwo = $createImmediateCrashedRequirement($db);
    $reusedAfterImmediateCrash =
        ingredientOntologyV3BuildRequirementProjection(
            $db,
            $versionId,
            50
        );
    providerRequirementAssert(
        empty($reusedProjection['built'])
        && !empty($reusedProjection['reused'])
        && (int)$reusedProjection['requirement_revision_id']
            === $requirementRevisionId,
        'Identical requirement projection inputs must reuse the ready revision'
    );
    providerRequirementAssert(
        !empty($reusedAfterImmediateCrash['reused'])
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_revisions
             WHERE id IN (?, ?)
               AND status = 'failed'",
            [$immediateCrashOne, $immediateCrashTwo]
        ) >= 1
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_recipe_requirements
             WHERE requirement_revision_id IN (?, ?)",
            [$immediateCrashOne, $immediateCrashTwo]
        ) === 0
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_revisions
             WHERE ontology_version_id = ?",
            [$versionId]
        ) <= 3,
        'Exclusive requirement builds must immediately fail/clean all '
            . 'pre-existing building revisions and remain bounded'
    );
    $sourceRowCount = providerRequirementCount(
        $db,
        "SELECT COUNT(*)
         FROM recipe_source_ingredients si
         JOIN recipe_catalog c ON c.id = si.recipe_id
         WHERE c.deleted_at IS NULL"
    );
    providerRequirementAssert(
        $requirementAudit['source_members'] === $sourceRowCount
        && $requirementAudit['contributor_complete']
        && $requirementAudit['member_owner_duplicates'] === 0,
        'Every source row must appear exactly once as an immutable member'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_recipe_states
             WHERE requirement_revision_id = ?
               AND recipe_id = ?",
            [$requirementRevisionId, $deletedSourceRecipe]
        ) === 0
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_members rm
             JOIN recipe_source_ingredients si
               ON si.id = rm.owner_id
              AND rm.owner_type = 'recipe_source_ingredient'
             WHERE rm.requirement_revision_id = ?
               AND si.recipe_id = ?",
            [$requirementRevisionId, $deletedSourceRecipe]
        ) === 0
        && $requirementAudit['contributor_complete'],
        'Deleted recipes must be excluded consistently from snapshot hashes, '
            . 'members, and contributor completeness'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_recipe_requirements
             WHERE requirement_revision_id = ?
               AND recipe_id = ?",
            [$requirementRevisionId, $repeatedRecipe]
        ) === 2
        && providerRequirementCount(
            $db,
            "SELECT SUM(contributor_count)
             FROM ingredient_ontology_recipe_requirements
             WHERE requirement_revision_id = ?
               AND recipe_id = ?",
            [$requirementRevisionId, $repeatedRecipe]
        ) === 2,
        'A cross-language provider ref must not merge identities without '
            . 'independent local/cohort acceptance'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_recipe_requirements
             WHERE requirement_revision_id = ?
               AND recipe_id = ?",
            [$requirementRevisionId, $differentRefRecipe]
        ) === 2,
        'The same local label under different provider refs must remain distinct'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_recipe_requirements
             WHERE requirement_revision_id = ?
               AND recipe_id = ?
               AND requiredness = 'required'
               AND quantity_audit_state = 'single_unit'",
            [$requirementRevisionId, $repeatedRecipe]
        ) === 1,
        'Unmerged provider rows retain independent requiredness and '
            . 'display-only quantity audit'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_recipe_requirements
             WHERE requirement_revision_id = ?
               AND recipe_id = ?",
            [$requirementRevisionId, $unresolvedRecipe]
        ) === 2,
        'Unresolved source rows must use owner-specific fail-closed keys'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_recipe_states
             WHERE requirement_revision_id = ?
               AND recipe_id = ?
               AND basis = 'source_incomplete'
               AND complete = 0",
            [$requirementRevisionId, $gapRecipe]
        ) === 1,
        'Source position gaps must mark the immutable recipe projection incomplete'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_members
             WHERE requirement_revision_id = ?
               AND owner_type = 'recipe_ingredient'
               AND requirement_id IN (
                   SELECT id
                   FROM ingredient_ontology_recipe_requirements
                   WHERE requirement_revision_id = ?
                     AND recipe_id IN (?, ?, ?)
               )",
            [
                $requirementRevisionId,
                $requirementRevisionId,
                $repeatedRecipe,
                $differentRefRecipe,
                $gapRecipe,
            ]
        ) === 0,
        'Source-backed recipes must never positionally join ranking rows'
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_recipe_states
             WHERE requirement_revision_id = ?
               AND recipe_id = ?
               AND basis = 'legacy'
               AND complete = 1",
            [$requirementRevisionId, $legacyRecipe]
        ) === 1,
        'Recipes without current source metadata must project from ranking rows'
    );
    $db->prepare("
        UPDATE recipe_source_ingredients
        SET source_default_title = 'Changed After Projection'
        WHERE id = ?
    ")->execute([$fingerprintOwnerId]);
    providerRequirementAssert(
        !ingredientOntologyV3RequirementAudit(
            $db,
            $requirementRevisionId
        )['source_corpus_hash_current'],
        'Default-title changes must stale an immutable requirement corpus hash'
    );
    $db->prepare("
        UPDATE recipe_source_ingredients
        SET source_default_title = 'Water'
        WHERE id = ?
    ")->execute([$fingerprintOwnerId]);
    providerRequirementAssert(
        ingredientOntologyV3RequirementAudit(
            $db,
            $requirementRevisionId
        )['source_corpus_hash_current'],
        'Restoring default title must restore requirement corpus hash equality'
    );
    $immutableRejected = false;
    $immutableRequirementId = (int)$db->query("
        SELECT id FROM ingredient_ontology_recipe_requirements
        WHERE requirement_revision_id = {$requirementRevisionId}
        ORDER BY id LIMIT 1
    ")->fetchColumn();
    try {
        $db->prepare("
            UPDATE ingredient_ontology_recipe_requirements
            SET requiredness = 'optional'
            WHERE id = ?
        ")->execute([$immutableRequirementId]);
    } catch (PDOException $e) {
        $immutableRejected = true;
    }
    providerRequirementAssert(
        $immutableRejected,
        'Ready immutable requirements must reject in-place mutation'
    );

    $requirementShadow =
        ingredientOntologyV3BuildRequirementShadow(
            $db,
            $requirementRevisionId,
            50
        );
    $requirementShadowId = (int)$requirementShadow['revision_id'];
    providerRequirementAssert(
        $requirementShadow['legacy_parity']['available']
        && $requirementShadow['legacy_parity']['valid']
        && $requirementShadow['legacy_parity']['score_mismatch_count'] === 0
        && $requirementShadow['legacy_parity']['match_mismatch_count'] === 0,
        'Legacy-backed immutable requirements must exactly match existing v3 '
            . 'scores: '
            . ingredientOntologyV3Json([
                'parity' => $requirementShadow['legacy_parity'],
                'regular' => recipeScoreRevision($db, $regularShadowId),
                'requirement' => recipeScoreRevision(
                    $db,
                    $requirementShadowId
                ),
                'reselected' =>
                    ingredientOntologyV3SelectRequirementParityBaseline(
                        $db,
                        $versionId,
                        recipeScoreState($db),
                        (string)recipeScoreRevision(
                            $db,
                            $requirementShadowId
                        )['inventory_fingerprint'],
                        (string)recipeScoreRevision(
                            $db,
                            $requirementShadowId
                        )['catalog_fingerprint'],
                        (int)recipeScoreRevision(
                            $db,
                            $requirementShadowId
                        )['catalog_max_id'],
                        (int)recipeScoreRevision(
                            $db,
                            $requirementShadowId
                        )['recipe_count']
                    ),
            ])
    );
    $requirementSetAudit =
        ingredientOntologyV3MaterializedIdSetAudit(
            $db,
            recipeScoreRevision($db, $requirementShadowId)
        );
    providerRequirementAssert(
        $requirementSetAudit['valid'],
        'Requirement shadow ID sets and stored hashes must initially match'
    );
    $requirementValueAudit = ingredientOntologyV3MaterializedValueAudit(
        $db,
        recipeScoreRevision($db, $requirementShadowId)
    );
    $projectionValueAudit =
        ingredientOntologyV3RequirementMaterializationAudit(
            $db,
            ingredientOntologyV3RequirementRevision(
                $db,
                $requirementRevisionId
            )
        );
    providerRequirementAssert(
        $requirementValueAudit['valid']
        && $projectionValueAudit['valid'],
        'Requirement score, match, projection, member, and state hashes must '
            . 'initially match'
    );
    $readyRequirementScoreRejected = false;
    try {
        $db->prepare("
            UPDATE recipe_inventory_scores
            SET coverage = CASE coverage WHEN 1 THEN 0.5 ELSE 1 END
            WHERE score_revision_id = ?
              AND recipe_id = (
                  SELECT recipe_id FROM recipe_inventory_scores
                  WHERE score_revision_id = ? ORDER BY recipe_id LIMIT 1
              )
        ")->execute([$requirementShadowId, $requirementShadowId]);
    } catch (PDOException $e) {
        $readyRequirementScoreRejected = true;
    }
    $readyRequirementMatchRejected = false;
    try {
        $db->prepare("
            UPDATE ingredient_ontology_shadow_requirement_matches
            SET satisfies_required =
                CASE satisfies_required WHEN 1 THEN 0 ELSE 1 END
            WHERE score_revision_id = ?
              AND requirement_id = (
                  SELECT requirement_id
                  FROM ingredient_ontology_shadow_requirement_matches
                  WHERE score_revision_id = ?
                  ORDER BY requirement_id LIMIT 1
              )
        ")->execute([$requirementShadowId, $requirementShadowId]);
    } catch (PDOException $e) {
        $readyRequirementMatchRejected = true;
    }
    $readyRequirementResealRejected = false;
    try {
        $db->prepare("
            UPDATE ingredient_ontology_requirement_revisions
            SET materialization_hash = ?
            WHERE id = ?
        ")->execute([str_repeat('f', 64), $requirementRevisionId]);
    } catch (PDOException $e) {
        $readyRequirementResealRejected = true;
    }
    providerRequirementAssert(
        $readyRequirementScoreRejected
        && $readyRequirementMatchRejected
        && $readyRequirementResealRejected,
        'Ready requirement scores, matches, and seals must reject mutation'
    );
    ingredientOntologyV3SetReadyMutationGuard($db, true);
    $db->beginTransaction();
    $db->prepare("
        UPDATE ingredient_ontology_shadow_requirement_matches
        SET outcome = 'synthetic_value_mutation',
            confidence = 0.123456
        WHERE score_revision_id = ?
          AND requirement_id = (
              SELECT requirement_id
              FROM ingredient_ontology_shadow_requirement_matches
              WHERE score_revision_id = ?
              ORDER BY requirement_id LIMIT 1
          )
    ")->execute([$requirementShadowId, $requirementShadowId]);
    $mutatedRequirementValueAudit =
        ingredientOntologyV3MaterializedValueAudit(
            $db,
            recipeScoreRevision($db, $requirementShadowId)
        );
    $db->prepare("
        UPDATE ingredient_ontology_recipe_requirements
        SET confidence = CASE confidence WHEN 1 THEN 0.5 ELSE 1 END
        WHERE requirement_revision_id = ?
          AND id = (
              SELECT id FROM ingredient_ontology_recipe_requirements
              WHERE requirement_revision_id = ?
              ORDER BY id LIMIT 1
          )
    ")->execute([$requirementRevisionId, $requirementRevisionId]);
    $mutatedProjectionValueAudit =
        ingredientOntologyV3RequirementMaterializationAudit(
            $db,
            ingredientOntologyV3RequirementRevision(
                $db,
                $requirementRevisionId
            )
        );
    $db->rollBack();
    ingredientOntologyV3SetReadyMutationGuard($db, false);
    providerRequirementAssert(
        !$mutatedRequirementValueAudit['valid']
        && !$mutatedProjectionValueAudit['valid'],
        'Guarded requirement value mutations must fail canonical hash equality'
    );
    ingredientOntologyV3SetReadyMutationGuard($db, true);
    $db->beginTransaction();
    $version = ingredientOntologyV3Version($db, $versionId);
    $db->prepare("
        INSERT INTO ingredient_ontology_requirement_revisions (
            ontology_version_id, projection_model, status,
            source_corpus_hash, ontology_content_hash, mapping_hash
        )
        VALUES (?, ?, 'building', ?, ?, ?)
    ")->execute([
        $versionId,
        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
        ingredientOntologyV3SourceCorpusHash($db, $versionId),
        $version['content_hash'],
        ingredientOntologyV3MappingHash($db, $versionId),
    ]);
    $extraRequirementRevisionId = (int)$db->lastInsertId();
    $baseRequirement = $db->query("
        SELECT *
        FROM ingredient_ontology_recipe_requirements
        WHERE requirement_revision_id = {$requirementRevisionId}
        ORDER BY id
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    $db->prepare("
        INSERT INTO ingredient_ontology_recipe_requirements (
            requirement_revision_id, ontology_version_id, recipe_id,
            requirement_key, basis, entity_id, mapping_status,
            mapping_source, confidence, identity_basis, attributes_json,
            defining_signature, requiredness, is_staple,
            contributor_count, provider_ref_count, quantity_audit_state,
            evidence_json
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $extraRequirementRevisionId,
        $versionId,
        (int)$baseRequirement['recipe_id'],
        hash('sha256', 'extra-requirement-id-mutant'),
        (string)$baseRequirement['basis'],
        $baseRequirement['entity_id'],
        (string)$baseRequirement['mapping_status'],
        (string)$baseRequirement['mapping_source'],
        (float)$baseRequirement['confidence'],
        (string)$baseRequirement['identity_basis'],
        (string)$baseRequirement['attributes_json'],
        (string)$baseRequirement['defining_signature'],
        (string)$baseRequirement['requiredness'],
        (int)$baseRequirement['is_staple'],
        1,
        0,
        (string)$baseRequirement['quantity_audit_state'],
        '{}',
    ]);
    $extraRequirementId = (int)$db->lastInsertId();
    $originalRequirementMatch = $db->query("
        SELECT *
        FROM ingredient_ontology_shadow_requirement_matches
        WHERE score_revision_id = {$requirementShadowId}
        ORDER BY requirement_id
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    $db->prepare("
        INSERT INTO ingredient_ontology_shadow_requirement_matches (
            score_revision_id, requirement_id, requirement_revision_id,
            inventory_product_id, inventory_mapping_id, outcome,
            satisfies_required, confidence, relationship,
            explanation_json
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $requirementShadowId,
        $extraRequirementId,
        $requirementRevisionId,
        $originalRequirementMatch['inventory_product_id'],
        $originalRequirementMatch['inventory_mapping_id'],
        $originalRequirementMatch['outcome'],
        $originalRequirementMatch['satisfies_required'],
        $originalRequirementMatch['confidence'],
        $originalRequirementMatch['relationship'],
        $originalRequirementMatch['explanation_json'],
    ]);
    $db->prepare("
        DELETE FROM ingredient_ontology_shadow_requirement_matches
        WHERE score_revision_id = ? AND requirement_id = ?
    ")->execute([
        $requirementShadowId,
        (int)$originalRequirementMatch['requirement_id'],
    ]);
    $originalRequirementScoreRecipeId = (int)$db->query("
        SELECT recipe_id
        FROM recipe_inventory_scores
        WHERE score_revision_id = {$requirementShadowId}
        ORDER BY recipe_id
        LIMIT 1
    ")->fetchColumn();
    $requirementScoreColumns = array_column(
        $db->query("PRAGMA table_info(recipe_inventory_scores)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    $requirementScoreSelect = array_map(
        static fn(string $column): string =>
            $column === 'recipe_id'
                ? (string)$deletedSourceRecipe
                : $column,
        $requirementScoreColumns
    );
    $db->prepare("
        INSERT INTO recipe_inventory_scores (
            " . implode(', ', $requirementScoreColumns) . "
        )
        SELECT " . implode(', ', $requirementScoreSelect) . "
        FROM recipe_inventory_scores
        WHERE score_revision_id = ? AND recipe_id = ?
    ")->execute([
        $requirementShadowId,
        $originalRequirementScoreRecipeId,
    ]);
    $db->prepare("
        DELETE FROM recipe_inventory_scores
        WHERE score_revision_id = ? AND recipe_id = ?
    ")->execute([
        $requirementShadowId,
        $originalRequirementScoreRecipeId,
    ]);
    $mutatedRequirementSetAudit =
        ingredientOntologyV3MaterializedIdSetAudit(
            $db,
            recipeScoreRevision($db, $requirementShadowId)
        );
    $db->rollBack();
    ingredientOntologyV3SetReadyMutationGuard($db, false);
    providerRequirementAssert(
        !$mutatedRequirementSetAudit['valid']
        && $mutatedRequirementSetAudit[
            'requirement_recipe_missing'
        ] === 1
        && $mutatedRequirementSetAudit[
            'requirement_recipe_extra'
        ] === 1
        && $mutatedRequirementSetAudit['requirement_missing'] === 1
        && $mutatedRequirementSetAudit['requirement_extra'] === 1,
        'Count-preserving wrong requirement IDs must fail anti-joins'
    );
    $persistedParityBaseline = (int)recipeScoreRevision(
        $db,
        $requirementShadowId
    )['parity_baseline_score_revision_id'];
    providerRequirementAssert(
        $persistedParityBaseline === $regularShadowId
        && $requirementShadow['legacy_parity']['baseline_revision_id']
            === $persistedParityBaseline,
        'Requirement shadows must persist and report the selected compatible baseline'
    );
    ingredientOntologyV3SetReadyMutationGuard($db, true);
    $db->beginTransaction();
    try {
        $db->prepare("
            UPDATE recipe_score_revisions
            SET score_date = date('now', '-1 day')
            WHERE id = ?
        ")->execute([$regularShadowId]);
        $yesterdayParity =
            ingredientOntologyV3RequirementLegacyParity(
                $db,
                $requirementShadowId
            );
        providerRequirementAssert(
            !$yesterdayParity['available']
            && !$yesterdayParity['valid']
            && $yesterdayParity['reason']
                === 'persisted_baseline_is_not_input_compatible',
            'Yesterday parity baselines must be rejected for current expiry scoring'
        );
    } finally {
        $db->rollBack();
    }
    $db->beginTransaction();
    try {
        $db->prepare("
            UPDATE recipe_score_revisions
            SET parity_baseline_score_revision_id = NULL
            WHERE id = ?
        ")->execute([$requirementShadowId]);
        $noBaselineParity = ingredientOntologyV3RequirementLegacyParity(
            $db,
            $requirementShadowId
        );
        providerRequirementAssert(
            !$noBaselineParity['available']
            && !$noBaselineParity['valid'],
            'Legacy parity must fail closed when no persisted baseline exists'
        );
    } finally {
        $db->rollBack();
    }
    $db->beginTransaction();
    try {
        $db->prepare("
            DELETE FROM recipe_inventory_scores
            WHERE score_revision_id = ?
              AND recipe_id = ?
        ")->execute([$regularShadowId, $legacyRecipe]);
        $missingScoreParity =
            ingredientOntologyV3RequirementLegacyParity(
                $db,
                $requirementShadowId
            );
        providerRequirementAssert(
            !$missingScoreParity['valid']
            && $missingScoreParity['score_mismatch_count'] > 0
            && !$missingScoreParity['cardinality_valid'],
            'Parity anti-joins must count missing baseline recipe score rows'
        );
    } finally {
        $db->rollBack();
    }
    $legacyOwnerForParity = (int)$db->query("
        SELECT owner_id
        FROM ingredient_ontology_requirement_members
        WHERE requirement_revision_id = {$requirementRevisionId}
          AND owner_type = 'recipe_ingredient'
        ORDER BY owner_id LIMIT 1
    ")->fetchColumn();
    $db->beginTransaction();
    try {
        $db->prepare("
            DELETE FROM ingredient_ontology_shadow_matches
            WHERE score_revision_id = ?
              AND recipe_ingredient_id = ?
        ")->execute([$regularShadowId, $legacyOwnerForParity]);
        $missingMatchParity =
            ingredientOntologyV3RequirementLegacyParity(
                $db,
                $requirementShadowId
            );
        providerRequirementAssert(
            !$missingMatchParity['valid']
            && $missingMatchParity['match_mismatch_count'] > 0
            && !$missingMatchParity['cardinality_valid'],
            'Parity anti-joins must count missing baseline match rows'
        );
    } finally {
        $db->rollBack();
    }
    $currentRequirementForParity = (int)$db->query("
        SELECT match.requirement_id
        FROM ingredient_ontology_shadow_requirement_matches match
        JOIN ingredient_ontology_recipe_requirements requirement
          ON requirement.id = match.requirement_id
        WHERE match.score_revision_id = {$requirementShadowId}
          AND requirement.basis = 'legacy'
        ORDER BY match.requirement_id LIMIT 1
    ")->fetchColumn();
    $db->beginTransaction();
    try {
        ingredientOntologyV3SetRequirementPruneGuard($db, true);
        $db->prepare("
            DELETE FROM ingredient_ontology_shadow_requirement_matches
            WHERE score_revision_id = ?
              AND requirement_id = ?
        ")->execute([
            $requirementShadowId,
            $currentRequirementForParity,
        ]);
        $missingCurrentMatchParity =
            ingredientOntologyV3RequirementLegacyParity(
                $db,
                $requirementShadowId
            );
        providerRequirementAssert(
            !$missingCurrentMatchParity['valid']
            && $missingCurrentMatchParity['match_mismatch_count'] > 0
            && !$missingCurrentMatchParity['cardinality_valid'],
            'Parity anti-joins must count missing current requirement matches'
        );
    } finally {
        $db->rollBack();
        ingredientOntologyV3SetRequirementPruneGuard($db, false);
    }
    for ($baselinePressure = 0; $baselinePressure < 5; $baselinePressure++) {
        $db->prepare("
            INSERT INTO recipe_score_revisions (
                inventory_revision, catalog_revision,
                inventory_fingerprint, score_date, catalog_max_id,
                status, recipe_count, ontology_version_id,
                scoring_model, scoring_config_hash,
                parent_score_revision_id, catalog_fingerprint,
                ontology_schema_hash, ontology_prompt_hash,
                ontology_model_hash, ontology_corpus_hash,
                ontology_content_hash, validation_report_json,
                completed_at
            )
            SELECT inventory_revision, catalog_revision,
                   inventory_fingerprint, score_date, catalog_max_id,
                   'ready', recipe_count, ontology_version_id,
                   scoring_model, scoring_config_hash, NULL,
                   catalog_fingerprint, ontology_schema_hash,
                   ontology_prompt_hash, ontology_model_hash,
                   ontology_corpus_hash, ontology_content_hash,
                   '{}', CURRENT_TIMESTAMP
            FROM recipe_score_revisions
            WHERE id = ?
        ")->execute([$regularShadowId]);
    }
    recipeScorePruneRevisions($db);
    ingredientOntologyV3SetReadyMutationGuard($db, false);
    providerRequirementAssert(
        recipeScoreRevision($db, $requirementShadowId) !== null
        && recipeScoreRevision(
            $db,
            $requirementShadowId
        )['parity_baseline_score_revision_id'] === $regularShadowId
        && recipeScoreRevision($db, $regularShadowId) !== null
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM sqlite_master
             WHERE type = 'index'
               AND name =
                   'idx_recipe_score_revisions_parity_baseline'"
        ) === 1,
        'Score pruning must retain every kept requirement shadow parity baseline'
    );
    $report = ingredientOntologyV3RequirementShadowReport(
        $db,
        $requirementShadowId
    );
    providerRequirementAssert(
        $report['requirement_match_complete']
        && !$report['activation_gate']['allowed']
        && !$report['requirements']['quantities_affect_scoring']
        && array_key_exists('required_count_delta', $report)
        && $report['required_count_delta']['legacy_recipes'] === 0,
        'Requirement shadow reports must prove completeness and display-only quantities'
    );
    $activation = ingredientOntologyV3ValidateActivation(
        $db,
        $requirementShadowId
    );
    providerRequirementAssert(
        !$activation['valid']
        && $activation['source_aware']
        && str_contains(
            implode(' ', $activation['errors']),
            'source-aware requirement revisions are shadow-only'
        ),
        'Source-aware requirement revisions must be explicitly activation-blocked'
    );

    $pageOne = recipeCatalogBrowseResult($db, [
        'fields' => 'card',
        'limit' => 1,
    ]);
    $publishedCursorActiveId =
        recipeScoreState($db)['active_score_revision_id'];
    providerRequirementAssert(
        is_string($pageOne['next_cursor'])
        && $pageOne['next_cursor'] !== '',
        'Cursor security fixture requires a real issued active cursor'
    );
    $issuedCursor = recipeCatalogDecodeCursor($pageOne['next_cursor']);
    $forgedRequirementCursor = $issuedCursor;
    $forgedRequirementCursor['revision_id'] = $requirementShadowId;
    $requirementCursorRejected = false;
    try {
        recipeCatalogBrowseResult($db, [
            'fields' => 'card',
            'limit' => 1,
            'cursor' => recipeCatalogEncodeCursor(
                $forgedRequirementCursor
            ),
        ]);
    } catch (InvalidArgumentException $e) {
        $requirementCursorRejected = str_contains(
            $e->getMessage(),
            'configured read revision'
        );
    }
    $forgedRetainedCursor = $issuedCursor;
    $forgedRetainedCursor['revision_id'] = $regularShadowId;
    $retainedCursorRejected = false;
    try {
        recipeCatalogBrowseResult($db, [
            'fields' => 'card',
            'limit' => 1,
            'cursor' => recipeCatalogEncodeCursor($forgedRetainedCursor),
        ]);
    } catch (InvalidArgumentException $e) {
        $retainedCursorRejected = str_contains(
            $e->getMessage(),
            'configured read revision'
        );
    }
    providerRequirementAssert(
        $requirementCursorRejected && $retainedCursorRejected,
        'Unsigned forged cursors must not browse ready requirement shadows '
            . 'or retained non-active revisions'
    );

    $readyRequirementId = (int)$db->query("
        SELECT id FROM ingredient_ontology_recipe_requirements
        WHERE requirement_revision_id = {$requirementRevisionId}
        ORDER BY id LIMIT 1
    ")->fetchColumn();
    $readyMemberId = (int)$db->query("
        SELECT id FROM ingredient_ontology_requirement_members
        WHERE requirement_revision_id = {$requirementRevisionId}
        ORDER BY id LIMIT 1
    ")->fetchColumn();
    $readyInsertRejected = false;
    try {
        $db->exec("
            INSERT INTO ingredient_ontology_recipe_requirements (
                requirement_revision_id, ontology_version_id, recipe_id,
                requirement_key, basis, entity_id, mapping_status,
                mapping_source, confidence, identity_basis,
                attributes_json, defining_signature, requiredness,
                is_staple, contributor_count, provider_ref_count,
                quantity_audit_state, evidence_json
            )
            SELECT requirement_revision_id, ontology_version_id, recipe_id,
                   '" . hash('sha256', 'forged-ready-insert') . "',
                   basis, entity_id, mapping_status, mapping_source,
                   confidence, identity_basis, attributes_json,
                   defining_signature, requiredness, is_staple, 1,
                   provider_ref_count, quantity_audit_state, evidence_json
            FROM ingredient_ontology_recipe_requirements
            WHERE id = {$readyRequirementId}
        ");
    } catch (PDOException $e) {
        $readyInsertRejected = true;
    }
    $readyMemberInsertRejected = false;
    try {
        $db->exec("
            INSERT INTO ingredient_ontology_requirement_members (
                requirement_revision_id, requirement_id,
                ontology_version_id, owner_type, owner_id,
                owner_fingerprint, mapping_id, provider_term_id,
                source_position, group_index, group_position,
                provider_ref, default_title, title_hash, source_label,
                source_label_hash, source_optional, source_quantity,
                source_quantity_max, source_unit, source_amount_text,
                quantity_state, evidence_json
            )
            SELECT requirement_revision_id, requirement_id,
                   ontology_version_id, owner_type, owner_id + 1000000,
                   owner_fingerprint, mapping_id, provider_term_id,
                   source_position, group_index, group_position,
                   provider_ref, default_title, title_hash, source_label,
                   source_label_hash, source_optional, source_quantity,
                   source_quantity_max, source_unit, source_amount_text,
                   quantity_state, evidence_json
            FROM ingredient_ontology_requirement_members
            WHERE id = {$readyMemberId}
        ");
    } catch (PDOException $e) {
        $readyMemberInsertRejected = true;
    }
    $readyMatchInsertRejected = false;
    try {
        $db->exec("
            INSERT INTO ingredient_ontology_shadow_requirement_matches (
                score_revision_id, requirement_id,
                requirement_revision_id, outcome,
                satisfies_required, confidence, relationship,
                explanation_json
            )
            VALUES (
                {$requirementShadowId}, {$readyRequirementId},
                {$requirementRevisionId}, 'forged', 0, 0,
                'none', '{}'
            )
        ");
    } catch (PDOException $e) {
        $readyMatchInsertRejected = true;
    }
    $buildingRevisionInsert = $db->prepare("
        INSERT INTO ingredient_ontology_requirement_revisions (
            ontology_version_id, projection_model, status,
            source_corpus_hash, ontology_content_hash, mapping_hash
        )
        SELECT ontology_version_id, projection_model, 'building',
               source_corpus_hash, ontology_content_hash, mapping_hash
        FROM ingredient_ontology_requirement_revisions
        WHERE id = ?
    ");
    $buildingRevisionInsert->execute([$requirementRevisionId]);
    $buildingRevisionId = (int)$db->lastInsertId();
    $db->exec("
        INSERT INTO ingredient_ontology_recipe_requirements (
            requirement_revision_id, ontology_version_id, recipe_id,
            requirement_key, basis, entity_id, mapping_status,
            mapping_source, confidence, identity_basis, attributes_json,
            defining_signature, requiredness, is_staple,
            contributor_count, provider_ref_count,
            quantity_audit_state, evidence_json
        )
        SELECT {$buildingRevisionId}, ontology_version_id, recipe_id,
               requirement_key, basis, entity_id, mapping_status,
               mapping_source, confidence, identity_basis, attributes_json,
               defining_signature, requiredness, is_staple,
               contributor_count, provider_ref_count,
               quantity_audit_state, evidence_json
        FROM ingredient_ontology_recipe_requirements
        WHERE id = {$readyRequirementId}
    ");
    $buildingRequirementId = (int)$db->lastInsertId();
    $reparentRejected = false;
    try {
        $db->prepare("
            UPDATE ingredient_ontology_recipe_requirements
            SET requirement_revision_id = ?
            WHERE id = ?
        ")->execute([
            $requirementRevisionId,
            $buildingRequirementId,
        ]);
    } catch (PDOException $e) {
        $reparentRejected = true;
    }
    providerRequirementAssert(
        $readyInsertRejected
        && $readyMemberInsertRejected
        && $readyMatchInsertRejected
        && $reparentRejected
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_recipe_requirements
             WHERE requirement_revision_id = ?",
            [$buildingRevisionId]
        ) === 1,
        'Ready snapshots must reject insert/reparent/match mutation while '
            . 'building revisions remain writable'
    );

    $liveBuildLock = ingredientOntologyV3AcquireLock($db);
    providerRequirementAssert(
        $liveBuildLock !== false,
        'Synthetic live requirement build must acquire the score lock'
    );
    $lockedPrune = ingredientOntologyV3PruneRequirementRevisions(
        $db,
        $versionId,
        1
    );
    ingredientOntologyV3ReleaseLock($liveBuildLock);
    providerRequirementAssert(
        !empty($lockedPrune['locked'])
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_revisions
             WHERE id = ? AND status = 'building'",
            [$buildingRevisionId]
        ) === 1
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_recipe_requirements
             WHERE requirement_revision_id = ?",
            [$buildingRevisionId]
        ) === 1,
        'Standalone requirement pruning must not fail or clean a live build '
            . 'while its score lock is held'
    );
    $db->prepare("
        UPDATE ingredient_ontology_requirement_revisions
        SET created_at = datetime('now', '-2 hours')
        WHERE id = ?
    ")->execute([$buildingRevisionId]);
    $pruneFailed = ingredientOntologyV3PruneRequirementRevisions(
        $db,
        $versionId,
        1
    );
    providerRequirementAssert(
        $pruneFailed['abandoned_failed'] >= 1
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_recipe_requirements
             WHERE requirement_revision_id = ?",
            [$buildingRevisionId]
        ) === 0,
        'Abandoned failed requirement builds must have partial payloads cleaned'
    );

    $insertLifecycleRevision = $db->prepare("
        INSERT INTO ingredient_ontology_requirement_revisions (
            ontology_version_id, parent_revision_id, projection_model,
            status, source_corpus_hash, ontology_content_hash, mapping_hash,
            created_at, completed_at, last_error
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insertLifecycleRevision->execute([
        $versionId,
        null,
        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
        'building',
        hash('sha256', 'wedge-old-source'),
        hash('sha256', 'wedge-old-content'),
        hash('sha256', 'wedge-old-mapping'),
        gmdate('Y-m-d H:i:s', time() - 10800),
        null,
        '',
    ]);
    $wedgeOldId = (int)$db->lastInsertId();
    ingredientOntologyV3SetPublicationGuard($db, true);
    try {
        $db->prepare("
            UPDATE ingredient_ontology_requirement_revisions
            SET status = 'ready',
                completed_at = datetime('now', '-3 hours')
            WHERE id = ?
        ")->execute([$wedgeOldId]);
    } finally {
        ingredientOntologyV3SetPublicationGuard($db, false);
    }
    $insertLifecycleRevision->execute([
        $versionId,
        $wedgeOldId,
        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
        'failed',
        hash('sha256', 'wedge-failed-source'),
        hash('sha256', 'wedge-failed-content'),
        hash('sha256', 'wedge-failed-mapping'),
        gmdate('Y-m-d H:i:s'),
        gmdate('Y-m-d H:i:s'),
        'synthetic failed child',
    ]);
    $wedgeFailedId = (int)$db->lastInsertId();
    $insertLifecycleRevision->execute([
        $versionId,
        $wedgeOldId,
        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
        'building',
        hash('sha256', 'wedge-building-source'),
        hash('sha256', 'wedge-building-content'),
        hash('sha256', 'wedge-building-mapping'),
        gmdate('Y-m-d H:i:s'),
        null,
        '',
    ]);
    $wedgeBuildingId = (int)$db->lastInsertId();
    $insertLifecycleRevision->execute([
        $versionId,
        null,
        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
        'building',
        hash('sha256', 'wedge-new-source'),
        hash('sha256', 'wedge-new-content'),
        hash('sha256', 'wedge-new-mapping'),
        gmdate('Y-m-d H:i:s'),
        null,
        '',
    ]);
    $wedgeNewReadyId = (int)$db->lastInsertId();
    ingredientOntologyV3SetPublicationGuard($db, true);
    try {
        $db->prepare("
            UPDATE ingredient_ontology_requirement_revisions
            SET status = 'ready', completed_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$wedgeNewReadyId]);
    } finally {
        ingredientOntologyV3SetPublicationGuard($db, false);
    }
    $db->prepare("
        INSERT INTO recipe_score_revisions (
            inventory_revision, catalog_revision, inventory_fingerprint,
            score_date, catalog_max_id, status, recipe_count,
            ontology_version_id, scoring_model,
            parent_score_revision_id, catalog_fingerprint,
            requirement_revision_id, requirement_model,
            created_at
        )
        VALUES (1, 1, 'abandoned', date('now'), ?, 'building', 1,
                ?, ?, NULL, 'abandoned', ?, ?,
                datetime('now', '-2 hours'))
    ")->execute([
        $legacyRecipe,
        $versionId,
        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_SCORING_MODEL,
        $wedgeOldId,
        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
    ]);
    $abandonedScoreId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO recipe_inventory_scores (
            score_revision_id, recipe_id, required_count,
            missing_required_count, cookable
        )
        VALUES (?, ?, 1, 1, 0)
    ")->execute([$abandonedScoreId, $legacyRecipe]);
    $wedgePruneOne = ingredientOntologyV3PruneRequirementRevisions(
        $db,
        $versionId,
        1
    );
    $wedgePruneTwo = ingredientOntologyV3PruneRequirementRevisions(
        $db,
        $versionId,
        1
    );
    $wedgeParents = $db->prepare("
        SELECT id, parent_revision_id
        FROM ingredient_ontology_requirement_revisions
        WHERE id IN (?, ?)
        ORDER BY id
    ");
    $wedgeParents->execute([$wedgeFailedId, $wedgeBuildingId]);
    $wedgeParentRows = $wedgeParents->fetchAll(PDO::FETCH_ASSOC);
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_revisions
             WHERE id = ?",
            [$wedgeOldId]
        ) === 0
        && recipeScoreRevision($db, $abandonedScoreId) === null
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*) FROM recipe_inventory_scores
             WHERE score_revision_id = ?",
            [$abandonedScoreId]
        ) === 0
        && count($wedgeParentRows) === 2
        && count(array_filter(
            $wedgeParentRows,
            static fn(array $row): bool =>
                $row['parent_revision_id'] !== null
        )) === 0
        && $wedgePruneOne['lineage_links_severed'] >= 2
        && is_array($wedgePruneTwo),
        'Failed/building retained revisions must sever deleted parents and '
            . 'allow repeated pruning without a foreign-key wedge: '
            . ingredientOntologyV3Json([
                'first' => $wedgePruneOne,
                'second' => $wedgePruneTwo,
                'old_exists' => providerRequirementCount(
                    $db,
                    "SELECT COUNT(*)
                     FROM ingredient_ontology_requirement_revisions
                     WHERE id = ?",
                    [$wedgeOldId]
                ),
                'parents' => $wedgeParentRows,
                'score' => recipeScoreRevision($db, $abandonedScoreId),
            ])
    );

    $successiveRequirementIds = [];
    for ($index = 1; $index <= 6; $index++) {
        $db->prepare("
            UPDATE recipe_ingredients
            SET quantity = ?, quantity_text = ?, unit = ?
            WHERE id = ?
        ")->execute([
            $index + 1,
            (string)($index + 1),
            $index % 2 === 0 ? 'ml' : 'g',
            $legacyIngredientId,
        ]);
        $nextProjection = ingredientOntologyV3BuildRequirementProjection(
            $db,
            $versionId,
            50
        );
        providerRequirementAssert(
            !empty($nextProjection['built'])
            && empty($nextProjection['reused']),
            'Legacy quantity/unit changes must not reuse a stale requirement revision'
        );
        $successiveRequirementIds[] =
            (int)$nextProjection['requirement_revision_id'];
    }
    $pruned = ingredientOntologyV3PruneRequirementRevisions(
        $db,
        $versionId,
        2
    );
    $retainedSuccessive = providerRequirementCount(
        $db,
        "SELECT COUNT(*)
         FROM ingredient_ontology_requirement_revisions
         WHERE id IN (?, ?, ?, ?, ?, ?)",
        $successiveRequirementIds
    );
    $oldestRetainedUnreferencedParent = $db->prepare("
        SELECT parent_revision_id
        FROM ingredient_ontology_requirement_revisions
        WHERE id IN (?, ?, ?, ?, ?, ?)
        ORDER BY id ASC LIMIT 1
    ");
    $oldestRetainedUnreferencedParent->execute($successiveRequirementIds);
    $oldestParent = $oldestRetainedUnreferencedParent->fetchColumn();
    $originalLegacyQuantity = $db->prepare("
        SELECT source_quantity, source_unit
        FROM ingredient_ontology_requirement_members
        WHERE requirement_revision_id = ?
          AND owner_type = 'recipe_ingredient'
          AND owner_id = ?
    ");
    $originalLegacyQuantity->execute([
        $requirementRevisionId,
        $legacyIngredientId,
    ]);
    $originalLegacyQuantity = $originalLegacyQuantity->fetch(
        PDO::FETCH_ASSOC
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_revisions
             WHERE id = ?",
            [$requirementRevisionId]
        ) === 1
        && $retainedSuccessive <= 2
        && $retainedSuccessive >= 1
        && ($oldestParent === false || $oldestParent === null)
        && (float)$originalLegacyQuantity['source_quantity'] === 1.0
        && (string)$originalLegacyQuantity['source_unit'] === 'g',
        'Six changed builds must retain the referenced immutable snapshot, '
            . 'bound unreferenced payloads to two, and sever old lineage: '
            . ingredientOntologyV3Json([
                'pruned' => $pruned,
                'referenced_exists' => providerRequirementCount(
                    $db,
                    "SELECT COUNT(*)
                     FROM ingredient_ontology_requirement_revisions
                     WHERE id = ?",
                    [$requirementRevisionId]
                ),
                'unreferenced_remaining' => $retainedSuccessive,
                'oldest_parent' => $oldestParent,
                'original_quantity' => $originalLegacyQuantity,
            ])
    );

    $latestRequirementRevisionId =
        (int)end($successiveRequirementIds);
    $cursorStateBefore = recipeScoreState($db);
    $GLOBALS[
        'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SHADOW_RESERVATION'
    ] = static function (PDO $hookDb): void {
        recipeScoreInvalidateCursors($hookDb);
    };
    $cursorShadow = ingredientOntologyV3BuildRequirementShadow(
        $db,
        $latestRequirementRevisionId,
        50
    );
    unset(
        $GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SHADOW_RESERVATION'
        ]
    );
    providerRequirementAssert(
        !empty($cursorShadow['built'])
        && recipeScoreRevision(
            $db,
            (int)$cursorShadow['revision_id']
        )['status'] === 'ready'
        && recipeScoreState($db)['cursor_revision']
            === $cursorStateBefore['cursor_revision'] + 1
        && recipeScoreState($db)['catalog_revision']
            === $cursorStateBefore['catalog_revision']
        && recipeScoreState($db)['inventory_revision']
            === $cursorStateBefore['inventory_revision'],
        'Cursor-only revision changes during requirement shadow build must '
            . 'not discard otherwise identical scoring inputs'
    );
    for ($shadowBuildIndex = 0; $shadowBuildIndex < 5; $shadowBuildIndex++) {
        ingredientOntologyV3BuildRequirementShadow(
            $db,
            $latestRequirementRevisionId,
            50
        );
    }
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM recipe_score_revisions
             WHERE scoring_model = ?
               AND status = 'ready'",
            [INGREDIENT_ONTOLOGY_V3_REQUIREMENT_SCORING_MODEL]
        ) <= RECIPE_SCORE_REQUIREMENT_SHADOW_RETENTION
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM recipe_score_revisions
             WHERE requirement_revision_id IS NOT NULL
               AND status <> 'ready'"
        ) === 0
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_requirement_revisions
             WHERE id = ?",
            [$latestRequirementRevisionId]
        ) === 1,
        'Repeated requirement shadows must remain bounded, remove failed '
            . 'score pins, and retain referenced requirement payloads'
    );

    $catalogStateBefore = recipeScoreState($db);
    $GLOBALS[
        'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SHADOW_RESERVATION'
    ] = static function (PDO $hookDb): void {
        recipeScoreMarkCatalogDirty($hookDb);
    };
    $catalogRaceRejected = false;
    try {
        ingredientOntologyV3BuildRequirementShadow(
            $db,
            $latestRequirementRevisionId,
            50
        );
    } catch (RuntimeException $e) {
        $catalogRaceRejected = str_contains(
            $e->getMessage(),
            'inputs changed'
        );
    }
    unset(
        $GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SHADOW_RESERVATION'
        ]
    );
    $db->prepare("
        UPDATE recipe_score_state
        SET catalog_revision = ?, dirty_at = ?, updated_at = ?
        WHERE id = 1
    ")->execute([
        $catalogStateBefore['catalog_revision'],
        $catalogStateBefore['dirty_at'],
        $catalogStateBefore['updated_at'],
    ]);
    providerRequirementAssert(
        $catalogRaceRejected,
        'Catalog revision changes during requirement shadow build must fail'
    );

    $inventoryStateBefore = recipeScoreState($db);
    $GLOBALS[
        'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SHADOW_RESERVATION'
    ] = static function (PDO $hookDb): void {
        recipeScoreMarkDirty($hookDb);
    };
    $inventoryRevisionRaceRejected = false;
    try {
        ingredientOntologyV3BuildRequirementShadow(
            $db,
            $latestRequirementRevisionId,
            50
        );
    } catch (RuntimeException $e) {
        $inventoryRevisionRaceRejected = str_contains(
            $e->getMessage(),
            'inputs changed'
        );
    }
    unset(
        $GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SHADOW_RESERVATION'
        ]
    );
    $db->prepare("
        UPDATE recipe_score_state
        SET inventory_revision = ?, dirty_at = ?, updated_at = ?
        WHERE id = 1
    ")->execute([
        $inventoryStateBefore['inventory_revision'],
        $inventoryStateBefore['dirty_at'],
        $inventoryStateBefore['updated_at'],
    ]);
    providerRequirementAssert(
        $inventoryRevisionRaceRejected,
        'Inventory revision changes during requirement shadow build must fail'
    );

    $waterProductId = (int)$db->query("
        SELECT id FROM products WHERE name = 'Water'
        ORDER BY id LIMIT 1
    ")->fetchColumn();
    $stateBeforeInventoryRace = recipeScoreState($db);
    $GLOBALS[
        'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SHADOW_RESERVATION'
    ] = static function (
        PDO $hookDb,
        int $_versionId,
        int $scoreRevisionId
    ) use ($waterProductId): void {
        $GLOBALS['TEST_FAILED_REQUIREMENT_SCORE_ID'] =
            $scoreRevisionId;
        $hookDb->prepare("
            UPDATE products SET name = 'Salt' WHERE id = ?
        ")->execute([$waterProductId]);
    };
    $inventoryRaceRejected = false;
    try {
        ingredientOntologyV3BuildRequirementShadow(
            $db,
            $latestRequirementRevisionId,
            50
        );
    } catch (RuntimeException $e) {
        $inventoryRaceRejected = str_contains(
            $e->getMessage(),
            'inputs changed'
        );
    }
    unset(
        $GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SHADOW_RESERVATION'
        ]
    );
    $failedInventoryRevisionId = (int)(
        $GLOBALS['TEST_FAILED_REQUIREMENT_SCORE_ID'] ?? 0
    );
    unset($GLOBALS['TEST_FAILED_REQUIREMENT_SCORE_ID']);
    $db->prepare("
        UPDATE products SET name = 'Water' WHERE id = ?
    ")->execute([$waterProductId]);
    $failedInventoryRevision = recipeScoreRevision(
        $db,
        $failedInventoryRevisionId
    );
    providerRequirementAssert(
        $inventoryRaceRejected
        && (
            $failedInventoryRevision === null
            || $failedInventoryRevision['status'] === 'failed'
        )
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*) FROM recipe_inventory_scores
             WHERE score_revision_id = ?",
            [$failedInventoryRevisionId]
        ) === 0
        && providerRequirementCount(
            $db,
            "SELECT COUNT(*)
             FROM ingredient_ontology_shadow_requirement_matches
             WHERE score_revision_id = ?",
            [$failedInventoryRevisionId]
        ) === 0
        && recipeScoreState($db)['active_score_revision_id']
            === $stateBeforeInventoryRace['active_score_revision_id']
        && recipeScoreState($db)['inventory_revision']
            === $stateBeforeInventoryRace['inventory_revision']
        && recipeScoreState($db)['catalog_revision']
            === $stateBeforeInventoryRace['catalog_revision']
        && recipeScoreState($db)['cursor_revision']
            === $stateBeforeInventoryRace['cursor_revision']
        && recipeScoreState($db)['ontology_source_revision']
            > $stateBeforeInventoryRace['ontology_source_revision']
        && recipeScoreState($db)['ontology_source_hash'] === '',
        'Product identity mutation after write reservation must fail the '
            . 'requirement shadow and remove all partial cookable '
            . 'materialization: ' . ingredientOntologyV3Json([
                'rejected' => $inventoryRaceRejected,
                'revision' => $failedInventoryRevision,
                'scores' => providerRequirementCount(
                    $db,
                    "SELECT COUNT(*) FROM recipe_inventory_scores
                     WHERE score_revision_id = ?",
                    [$failedInventoryRevisionId]
                ),
                'matches' => providerRequirementCount(
                    $db,
                    "SELECT COUNT(*)
                     FROM ingredient_ontology_shadow_requirement_matches
                     WHERE score_revision_id = ?",
                    [$failedInventoryRevisionId]
                ),
                'state' => recipeScoreState($db),
                'expected_state' => $stateBeforeInventoryRace,
            ])
    );

    $finalState = recipeScoreState($db);
    $finalRequirementReadyCount = providerRequirementCount(
        $db,
        "SELECT COUNT(*)
         FROM recipe_score_revisions
         WHERE scoring_model = ?
           AND status = 'ready'",
        [INGREDIENT_ONTOLOGY_V3_REQUIREMENT_SCORING_MODEL]
    );
    providerRequirementAssert(
        $finalState['active_score_revision_id']
            === $publishedCursorActiveId
        && $finalState['inventory_revision']
            === $stateBefore['inventory_revision']
        && $finalState['catalog_revision']
            === $stateBefore['catalog_revision']
        && $finalState['cursor_revision']
            === $stateBefore['cursor_revision'] + 1
        && $finalRequirementReadyCount >= 1
        && $finalRequirementReadyCount
            <= RECIPE_SCORE_REQUIREMENT_SHADOW_RETENTION,
        'Shadow foundations must preserve the active pointer and prior '
            . 'revisions: ' . ingredientOntologyV3Json([
                'final_state' => $finalState,
                'initial_state' => $stateBefore,
                'published_cursor_active_id' =>
                    $publishedCursorActiveId,
                'regular' => recipeScoreRevision($db, $regularShadowId),
                'requirement_ready_count' =>
                    $finalRequirementReadyCount ?? null,
            ])
    );
    providerRequirementAssert(
        providerRequirementCount(
            $db,
            "SELECT COUNT(*) FROM recipe_catalog
             WHERE instructions_json <> '[]'
                OR instruction_groups_json <> '[]'
                OR nutrition_json <> '{}'
                OR source_payload_json IS NOT NULL"
        ) === 0,
        'Provider requirement fixtures must contain no prohibited provider data'
    );

    echo 'Provider requirement tests passed: '
        . number_format($assertions) . " assertions\n";
} finally {
    $db = null;
    foreach ($cleanup as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
