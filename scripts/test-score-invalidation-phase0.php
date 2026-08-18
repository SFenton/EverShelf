#!/usr/bin/env php
<?php
declare(strict_types=1);

putenv('INGREDIENT_ONTOLOGY_CONTROLLER_ENABLED=false');
define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/index.php';

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

$path = dirname(__DIR__) . '/data/.score-phase0-'
    . getmypid() . '.sqlite';
@unlink($path);
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
migrateDB($db);

$hash = str_repeat('a', 64);
$db->prepare("
    INSERT INTO ingredient_ontology_versions (
        version, status, schema_hash, prompt_hash, model_hash,
        model_name, corpus_hash, content_hash,
        portable_content_hash, review_manifest_hash,
        resolution_gold_hash, seal_hash,
        activation_policy, activation_block_reason,
        corpus_profile, frozen_corpus_hash,
        frozen_subjects_hash, policy_hash, ready_at
    )
    VALUES (
        'phase0-test', 'building', ?, ?, ?,
        'gemini-3.5-flash', ?, ?, ?, ?, ?, ?,
        'test_only', 'test', 'test', ?, ?, ?, CURRENT_TIMESTAMP
    )
")->execute(array_fill(0, 12, $hash));
$versionId = (int)$db->lastInsertId();
ingredientOntologyV3SetPublicationGuard($db, true);
$db->prepare("
    UPDATE ingredient_ontology_versions
    SET status = 'ready', ready_at = CURRENT_TIMESTAMP
    WHERE id = ?
")->execute([$versionId]);
ingredientOntologyV3SetPublicationGuard($db, false);

$db->exec("
    INSERT INTO recipe_catalog (
        primary_connector, title, cache_expires_at
    )
    VALUES ('manual', 'Phase Zero Recipe', datetime('now', '+1 day'))
");
$recipeId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO recipe_ingredients (
        recipe_id, position, raw_text, normalized_name,
        mapping_confidence, mapping_source
    )
    VALUES (?, 1, 'phase zero ingredient',
            'phase zero ingredient', 0, 'unresolved')
")->execute([$recipeId]);

$sourceHash = ingredientOntologyV3CorpusHash($db);
$db->prepare("
    INSERT INTO recipe_score_revisions (
        inventory_revision, catalog_revision,
        inventory_fingerprint, score_date, catalog_max_id,
        status, recipe_count, ontology_version_id,
        scoring_model, scoring_config_hash,
        catalog_fingerprint, ontology_schema_hash,
        ontology_prompt_hash, ontology_model_hash,
        ontology_corpus_hash, ontology_content_hash,
        ontology_portable_content_hash,
        ontology_review_manifest_hash,
        ontology_resolution_gold_hash, ontology_seal_hash,
        ontology_source_revision, ontology_source_hash,
        catalog_id_set_hash, ingredient_id_set_hash,
        score_rows_hash, match_rows_hash,
        materialization_hash, validation_report_json,
        completed_at
    )
    VALUES (
        1, 1, ?, ?, ?, 'building', 1, ?,
        'faceted-ontology-v3', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
        1, ?, ?, ?, ?, ?, ?, '{}', CURRENT_TIMESTAMP
    )
")->execute([
    $hash,
    recipeScoreCurrentDate(),
    $recipeId,
    $versionId,
    ingredientOntologyV3ScoringConfigHash(),
    recipeScoreCatalogFingerprint($db),
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $sourceHash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
]);
$activeRevisionId = (int)$db->lastInsertId();
ingredientOntologyV3SetPublicationGuard($db, true);
$db->prepare("
    UPDATE recipe_score_revisions
    SET status = 'ready',
        completed_at = CURRENT_TIMESTAMP
    WHERE id = ?
")->execute([$activeRevisionId]);
ingredientOntologyV3SetPublicationGuard($db, false);
$db->prepare("
    UPDATE recipe_score_state
    SET inventory_revision = 1,
        catalog_revision = 1,
        ontology_source_revision = 1,
        ontology_source_hash = ?,
        active_score_revision_id = ?,
        active_score_overlay_revision_id = NULL
    WHERE id = 1
")->execute([$sourceHash, $activeRevisionId]);
$db->exec("DELETE FROM recipe_score_mutations");

$db->exec("
    INSERT INTO products (name, brand, category)
    VALUES ('Phase Zero Product', '', '')
");
$productId = (int)$db->lastInsertId();
$catalogBefore = recipeScoreState($db)['catalog_revision'];
$taxonomyResult = recipeJobDispatchTaxonomyReady(
    $db,
    ['product_id' => $productId],
    ['reason' => 'phase0_test']
);
$assert(
    $taxonomyResult['status'] === 'done'
    && $taxonomyResult['result']['remapped_ingredients'] === 0
    && (
        $taxonomyResult['result']['legacy_mapping_backfill']['reason']
            ?? ''
    ) === 'active_v3_uses_sealed_recipe_mappings'
    && recipeScoreState($db)['catalog_revision'] === $catalogBefore,
    'Active v3 taxonomy completion must not run the legacy global backfill'
);

$db->exec("
    INSERT INTO products (name, brand, category)
    VALUES ('Merge Keep', '', '')
");
$keepId = (int)$db->lastInsertId();
$db->exec("
    INSERT INTO products (name, brand, category)
    VALUES ('Merge Drop', '', '')
");
$dropId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO inventory (product_id, location, quantity)
    VALUES (?, 'dispensa', 1)
")->execute([$dropId]);
mergeProducts($db, $keepId, $dropId);
$assert(
    (int)$db->query("
        SELECT COUNT(*) FROM products WHERE id = {$dropId}
    ")->fetchColumn() === 0
    && (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_pending_products
        WHERE product_id IN ({$keepId}, {$dropId})
    ")->fetchColumn() === 2,
    'Product merge must retain both kept and dropped dependency keys'
);

$stableState = recipeScoreState($db);
$stableSourceHash = ingredientOntologyV3CorpusHash($db);
$db->prepare("
    UPDATE recipe_score_state
    SET ontology_source_hash = ?
    WHERE id = 1
")->execute([$stableSourceHash]);
$parent = recipeScoreRevision($db, $activeRevisionId);
$parent['ontology_source_revision'] =
    (int)$stableState['ontology_source_revision'];
$parent['ontology_source_hash'] = $stableSourceHash;
$db->prepare("
    UPDATE recipe_score_state
    SET ontology_source_revision = ontology_source_revision + 1,
        ontology_source_hash = ''
    WHERE id = 1
")->execute();
$sameHashErrors = ingredientOntologyV3IncrementalParentErrors(
    $db,
    $parent,
    recipeScoreState($db)
);
$assert(
    !in_array('ontology_source_changed', $sameHashErrors, true),
    'Equal source content must tolerate a revision-only source bump'
);

$db->prepare("
    UPDATE recipe_ingredients
    SET raw_text = 'phase zero changed ingredient'
    WHERE recipe_id = ?
")->execute([$recipeId]);
$changedHashErrors = ingredientOntologyV3IncrementalParentErrors(
    $db,
    $parent,
    recipeScoreState($db)
);
$assert(
    in_array('ontology_source_changed', $changedHashErrors, true),
    'Real source fingerprint drift must fail incremental scoring closed'
);

$sequenceBefore = ingredientOntologyActivationSequence(
    $db,
    'recipe_score_revisions'
);
$db->exec('BEGIN IMMEDIATE');
try {
    ingredientOntologyActivationReserveManifestSequences(
        $db,
        [[
            'table' => 'recipe_score_revisions',
            'cursor' => 'id',
            'baseline_sequence' => $sequenceBefore,
            'expected_post_sequence' => $sequenceBefore + 1,
            'row_count' => 1,
            'minimum_cursor' => $sequenceBefore + 1,
            'maximum_cursor' => $sequenceBefore + 1,
        ]]
    );
    $db->exec('COMMIT');
} catch (Throwable $error) {
    $db->exec('ROLLBACK');
    throw $error;
}
$db->prepare("
    INSERT INTO recipe_score_revisions (
        inventory_revision, inventory_fingerprint, score_date
    )
    VALUES (1, ?, ?)
")->execute([$hash, recipeScoreCurrentDate()]);
$assert(
    ingredientOntologyActivationSequence(
        $db,
        'recipe_score_revisions'
    ) === $sequenceBefore + 2
    && (int)$db->lastInsertId() === $sequenceBefore + 2,
    'Imported score revision IDs must be reserved before live writers allocate'
);

$db->exec("
    DROP TABLE ingredient_ontology_shadow_matches;
    CREATE TABLE ingredient_ontology_shadow_matches (
        score_revision_id INTEGER NOT NULL,
        recipe_ingredient_id INTEGER NOT NULL,
        recipe_id INTEGER DEFAULT NULL,
        recipe_mapping_id INTEGER DEFAULT NULL,
        inventory_product_id INTEGER DEFAULT NULL,
        inventory_mapping_id INTEGER DEFAULT NULL,
        outcome TEXT NOT NULL,
        satisfies_required INTEGER NOT NULL DEFAULT 0,
        confidence REAL NOT NULL DEFAULT 0,
        relationship TEXT NOT NULL DEFAULT 'none',
        explanation_json TEXT NOT NULL DEFAULT '{}',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(score_revision_id, recipe_ingredient_id),
        FOREIGN KEY (score_revision_id)
            REFERENCES recipe_score_revisions(id) ON DELETE CASCADE,
        FOREIGN KEY (recipe_ingredient_id)
            REFERENCES recipe_ingredients(id) ON DELETE CASCADE
    );
");
$db->prepare("
    INSERT INTO ingredient_ontology_shadow_matches (
        score_revision_id, recipe_ingredient_id,
        recipe_id, outcome
    )
    SELECT ?, id, recipe_id, 'legacy_owner_test'
    FROM recipe_ingredients
    WHERE recipe_id = ?
    LIMIT 1
")->execute([$activeRevisionId, $recipeId]);
ingredientOntologyV3EnsureHistoricalShadowMatchOwners($db);
$migratedOwner = $db->query("
    SELECT recipe_id
    FROM ingredient_ontology_shadow_matches
    WHERE score_revision_id = {$activeRevisionId}
")->fetchColumn();
$ingredientForeignKeys = array_filter(
    $db->query("
        PRAGMA foreign_key_list(
            ingredient_ontology_shadow_matches
        )
    ")->fetchAll(PDO::FETCH_ASSOC),
    static fn(array $row): bool =>
        (string)$row['table'] === 'recipe_ingredients'
);
$assert(
    (int)$migratedOwner === $recipeId
    && $ingredientForeignKeys === [],
    'Legacy shadow migration must preserve immutable recipe ownership'
);

$db = null;
@unlink($path);
@unlink($path . '-wal');
@unlink($path . '-shm');

echo "Phase 0 score invalidation tests passed: "
    . "{$assertions} assertions.\n";
