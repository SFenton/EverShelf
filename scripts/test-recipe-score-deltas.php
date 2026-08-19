#!/usr/bin/env php
<?php
declare(strict_types=1);

putenv('INGREDIENT_ONTOLOGY_CONTROLLER_ENABLED=false');
define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

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

$path = dirname(__DIR__) . '/data/.recipe-score-deltas-'
    . getmypid() . '.sqlite';
@unlink($path);
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
migrateDB($db);

$db->exec("
    INSERT INTO recipe_catalog (
        primary_connector, title, language, cache_expires_at
    )
    VALUES (
        'manual', 'Baseline Delta Recipe', 'en',
        datetime('now', '+1 day')
    )
");
$baselineRecipeId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO recipe_ingredients (
        recipe_id, position, raw_text, normalized_name,
        source_is_required, source_is_optional,
        requiredness_source
    )
    VALUES (?, 0, 'baseline unknown', 'baseline unknown',
            1, 0, 'explicit_required')
")->execute([$baselineRecipeId]);
$baselineIngredientId = (int)$db->lastInsertId();
$db->prepare("
    INSERT OR IGNORE INTO recipe_user_state (recipe_id)
    VALUES (?)
")->execute([$baselineRecipeId]);
recipeSearchRebuildDocument($db, $baselineRecipeId);

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
        'recipe-delta-test', 'building', ?, ?, ?,
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

$state = recipeScoreState($db);
$sourceHash = ingredientOntologyV3CorpusHash($db);
$catalogFingerprint = recipeScoreCatalogFingerprint($db);
$idSets = ingredientOntologyV3MaterializedIdSetHashes(
    $db,
    0,
    null
);
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
        materialization_hash, validation_report_json
    )
    VALUES (
        ?, ?, ?, ?, ?, 'building', 1, ?,
        'faceted-ontology-v3', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?, ?
    )
")->execute([
    (int)$state['inventory_revision'],
    (int)$state['catalog_revision'],
    $hash,
    recipeScoreCurrentDate(),
    $baselineRecipeId,
    $versionId,
    ingredientOntologyV3ScoringConfigHash(),
    $catalogFingerprint,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    (int)$state['ontology_source_revision'],
    $sourceHash,
    (string)$idSets['catalog_id_set_hash'],
    (string)$idSets['ingredient_id_set_hash'],
    $hash,
    $hash,
    $hash,
    ingredientOntologyV3Json([
        'materialized_hash_algorithm' => 'full-v1',
        'recipe_count' => 1,
        'ingredient_match_count' => 1,
    ]),
]);
$parentRevisionId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO recipe_inventory_scores (
        score_revision_id, recipe_id, coverage, directness,
        expiry_score, source_user_score, availability_score,
        required_count, matched_required_count,
        missing_required_count, uncertain_required_count,
        cookable
    )
    VALUES (?, ?, 0, 0, 0, 0, 0, 1, 0, 1, 0, 0)
")->execute([$parentRevisionId, $baselineRecipeId]);
$db->prepare("
    INSERT INTO ingredient_ontology_shadow_matches (
        score_revision_id, recipe_ingredient_id,
        outcome, satisfies_required, confidence,
        relationship, explanation_json
    )
    VALUES (?, ?, 'recipe_unmapped', 0, 0, 'none', '{}')
")->execute([$parentRevisionId, $baselineIngredientId]);
$baseValueHashes = ingredientOntologyV3MaterializedValueHashes(
    $db,
    $parentRevisionId,
    null
);
$db->prepare("
    UPDATE recipe_score_revisions
    SET score_rows_hash = ?,
        match_rows_hash = ?,
        materialization_hash = ?
    WHERE id = ?
")->execute([
    (string)$baseValueHashes['score_rows_hash'],
    (string)$baseValueHashes['match_rows_hash'],
    (string)$baseValueHashes['materialization_hash'],
    $parentRevisionId,
]);
ingredientOntologyV3SetPublicationGuard($db, true);
$db->prepare("
    UPDATE recipe_score_revisions
    SET status = 'ready', completed_at = CURRENT_TIMESTAMP
    WHERE id = ?
")->execute([$parentRevisionId]);
ingredientOntologyV3SetPublicationGuard($db, false);
$db->prepare("
    UPDATE recipe_score_state
    SET ontology_source_hash = ?,
        active_score_revision_id = ?,
        active_score_overlay_revision_id = NULL
    WHERE id = 1
")->execute([$sourceHash, $parentRevisionId]);
$db->exec('BEGIN IMMEDIATE');
recipeScoreBuildEffectiveProjection($db, $parentRevisionId);
$db->exec('DELETE FROM recipe_score_mutations');
$db->exec('COMMIT');

$favorite = recipeCatalogSetFavorite(
    $db,
    $baselineRecipeId,
    true
);
$favoriteDelta = ingredientOntologyV3IncrementalRebuild(
    $db,
    true
);
$assert(
    $favorite
    && !empty($favoriteDelta['rebuilt'])
    && $favoriteDelta['recipe_operations'][$baselineRecipeId]
        === 'replace'
    && (int)$favoriteDelta['physical_score_rows'] === 1
    && (int)$favoriteDelta['pending_recipe_count'] === 0,
    'Favorite changes must publish a one-recipe score delta'
);

$inserted = recipeCatalogSaveVariant($db, [
    'title' => 'New Delta Recipe',
    'language' => 'en',
    'ingredients' => [[
        'name' => 'unknown delta ingredient',
        'is_required' => true,
    ], [
        'name' => 'second unknown delta ingredient',
        'is_required' => true,
    ]],
    'steps' => ['Test the sparse recipe append.'],
], [
    'connector' => 'manual',
    'external_id' => 'recipe-delta-new',
]);
$insertedRecipeId = (int)$inserted['id'];
$unscoredIngredientIds = array_map(
    'intval',
    $db->query("
        SELECT id
        FROM recipe_ingredients
        WHERE recipe_id = {$insertedRecipeId}
        ORDER BY position
    ")->fetchAll(PDO::FETCH_COLUMN)
);
$unscoredDetail = recipeDetailV3MatchMap(
    $db,
    array_map(
        static fn(int $ingredientId): array => [
            'ranking_ingredient_id' => $ingredientId,
        ],
        $unscoredIngredientIds
    )
);
$assert(
    (int)$unscoredDetail['revision']['id']
        === (int)$favoriteDelta['revision_id']
    && $unscoredDetail['matches'] === [],
    'New recipe detail must remain readable before score publication'
);
$insertDelta = ingredientOntologyV3IncrementalRebuild(
    $db,
    true
);
$insertedSource = $db->prepare("
    SELECT score_revision_id
    FROM recipe_score_effective_sources
    WHERE recipe_id = ?
");
$insertedSource->execute([$insertedRecipeId]);
$annexCount = $db->prepare("
    SELECT COUNT(*)
    FROM ingredient_ontology_recipe_identity_annex annex
    JOIN recipe_ingredients ingredient
      ON ingredient.id = annex.recipe_ingredient_id
    WHERE ingredient.recipe_id = ?
      AND annex.ontology_version_id = ?
");
$annexCount->execute([$insertedRecipeId, $versionId]);
$insertRevision = recipeScoreRevision(
    $db,
    (int)$insertDelta['revision_id']
);
$insertState = recipeScoreState($db);
$assert(
    !empty($insertDelta['rebuilt'])
    && $insertDelta['recipe_operations'][$insertedRecipeId]
        === 'replace'
    && (int)$insertDelta['recipe_count'] === 2
    && (int)$insertedSource->fetchColumn()
        === (int)$insertDelta['revision_id']
    && (int)$annexCount->fetchColumn() === 2
    && (int)$insertDelta['pending_recipe_count'] === 0,
    'A new recipe must receive terminal annex mappings and append sparsely'
);
$assert(
    (string)$insertRevision['ontology_source_hash'] === $sourceHash
    && (string)$insertState['ontology_source_hash'] === $sourceHash
    && strlen(
        (string)$insertRevision['ontology_source_lineage_hash']
    ) === 64
    && hash_equals(
        (string)$insertRevision['ontology_source_lineage_hash'],
        (string)$insertState['ontology_source_lineage_hash']
    ),
    'Scoped source lineage must not overwrite canonical source hashes'
);
$removedIngredientId = (int)$db->query("
    SELECT id FROM recipe_ingredients
    WHERE recipe_id = {$insertedRecipeId}
    ORDER BY position DESC
    LIMIT 1
")->fetchColumn();
$db->prepare("
    INSERT INTO recipe_score_match_contributors (
        score_revision_id, recipe_ingredient_id,
        recipe_id, product_id
    )
    VALUES (?, ?, ?, 999999)
")->execute([
    (int)$insertDelta['revision_id'],
    $removedIngredientId,
    $insertedRecipeId,
]);
$db->prepare("
    DELETE FROM recipe_score_contributor_revisions
    WHERE score_revision_id = ?
")->execute([(int)$insertDelta['revision_id']]);

$updated = recipeCatalogSaveVariant($db, [
    'title' => 'Updated Delta Recipe',
    'language' => 'en',
    'ingredients' => [[
        'name' => 'updated unknown delta ingredient',
        'is_required' => true,
    ]],
    'steps' => ['Test the sparse recipe update.'],
], [
    'recipe_id' => $insertedRecipeId,
    'connector' => 'manual',
    'external_id' => 'recipe-delta-new',
]);
$GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_INCREMENTAL_SNAPSHOT'] =
    static function (PDO $hookDb) use (
        $insertedRecipeId
    ): void {
        recipeCatalogSaveVariant($hookDb, [
            'title' => 'Raced Delta Recipe',
            'language' => 'en',
            'ingredients' => [[
                'name' => 'raced unknown delta ingredient',
                'is_required' => true,
            ]],
            'steps' => ['Race the sparse recipe update.'],
        ], [
            'recipe_id' => $insertedRecipeId,
            'connector' => 'manual',
            'external_id' => 'recipe-delta-new',
        ]);
    };
$racedUpdate = ingredientOntologyV3IncrementalRebuild(
    $db,
    true
);
unset($GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_INCREMENTAL_SNAPSHOT']);
$updateDelta = ingredientOntologyV3IncrementalRebuild(
    $db,
    true
);
$historicalContributorCount = (int)$db->query("
    SELECT COUNT(*)
    FROM recipe_score_match_contributors
    WHERE score_revision_id = " . (int)$insertDelta['revision_id'] . "
      AND recipe_ingredient_id = {$removedIngredientId}
      AND recipe_id = {$insertedRecipeId}
")->fetchColumn();
$backfill = recipeScoreBackfillContributorRevision(
    $db,
    (int)$insertDelta['revision_id']
);
$assert(
    (int)$updated['id'] === $insertedRecipeId
    && !empty($racedUpdate['rebuilt'])
    && $racedUpdate['recipe_operations'][$insertedRecipeId]
        === 'replace'
    && (int)$racedUpdate['physical_score_rows'] === 1
    && (int)$racedUpdate['pending_recipe_count'] === 1
    && !empty($updateDelta['rebuilt'])
    && $updateDelta['recipe_operations'][$insertedRecipeId]
        === 'replace'
    && (int)$updateDelta['physical_score_rows'] === 1
    && (int)$updateDelta['physical_match_rows'] === 1
    && (int)$updateDelta['match_count'] === 2
    && (int)$updateDelta['recipe_count'] === 2
    && (int)$updateDelta['pending_recipe_count'] === 0
    && $historicalContributorCount === 1
    && (int)$backfill['match_count'] === 2,
    'Recipe ingredient edits must refresh annex mappings and replace one score: '
        . ingredientOntologyV3Json([
            'updated_id' => (int)$updated['id'],
            'raced_update' => $racedUpdate,
            'update_delta' => $updateDelta,
            'historical_contributor_count' =>
                $historicalContributorCount,
            'backfill' => $backfill,
        ])
);
$scopedIntegrity = ingredientOntologyV3RevisionIntegrityAudit(
    $db,
    recipeScoreActiveRevision($db)
);
$assert(
    !in_array(
        'ontology source revision or hash changed',
        $scopedIntegrity['errors'],
        true
    )
    && !in_array(
        'source owner fingerprints changed after ontology build',
        $scopedIntegrity['errors'],
        true
    )
    && !in_array(
        'ontology corpus hash changed',
        $scopedIntegrity['errors'],
        true
    ),
    'Scoped source lineage must bypass only mutable-corpus integrity checks'
);
$assert(
    ingredientOntologyActivationNeedsOntologyBuild($db),
    'Scoped source lineage must route copied refresh through ontology build'
);
$sourceLineageBeforeCompaction = (string)recipeScoreState($db)[
    'ontology_source_lineage_hash'
];
$compaction = ingredientOntologyV3CompactActiveScores($db, true);
$compactedRevision = recipeScoreActiveRevision($db);
$assert(
    !empty($compaction['compacted'])
    && (int)$compaction['match_count'] === 2,
    'Ingredient shrink must retain immutable ownership and permit compaction'
);
$assert(
    (string)($compactedRevision['catalog_lineage_hash'] ?? '') === ''
    && $sourceLineageBeforeCompaction !== ''
    && hash_equals(
        $sourceLineageBeforeCompaction,
        (string)(
        $compactedRevision['ontology_source_lineage_hash'] ?? ''
        )
    )
    && hash_equals(
        $sourceLineageBeforeCompaction,
        (string)recipeScoreState($db)[
            'ontology_source_lineage_hash'
        ]
    ),
    'Compaction must canonicalize catalog inputs while preserving scoped source lineage'
);
$compactedIntegrity = ingredientOntologyV3RevisionIntegrityAudit(
    $db,
    $compactedRevision
);
$assert(
    !in_array(
        'ontology source revision or hash changed',
        $compactedIntegrity['errors'],
        true
    )
    && !in_array(
        'ontology corpus hash changed',
        $compactedIntegrity['errors'],
        true
    ),
    'Full compaction must remain source-lineage aware'
);
$assert(
    ingredientOntologyActivationNeedsOntologyBuild($db),
    'Compacted source lineage must still require ontology refresh'
);

$deleted = recipeCatalogDelete($db, $insertedRecipeId);
$deleteDelta = ingredientOntologyV3IncrementalRebuild(
    $db,
    true
);
$deletedProjection = $db->prepare("
    SELECT COUNT(*)
    FROM recipe_score_effective_sources
    WHERE recipe_id = ?
");
$deletedProjection->execute([$insertedRecipeId]);
$active = recipeScoreActiveRevision($db);
$idSetAudit = ingredientOntologyV3MaterializedIdSetAudit(
    $db,
    $active
);
$valueAudit = ingredientOntologyV3MaterializedValueAudit(
    $db,
    $active
);
$assert(
    $deleted
    && !empty($deleteDelta['rebuilt'])
    && $deleteDelta['recipe_operations'][$insertedRecipeId]
        === 'delete'
    && (int)$deleteDelta['physical_score_rows'] === 0
    && (int)$deleteDelta['recipe_count'] === 1
    && (int)$deletedProjection->fetchColumn() === 0
    && (int)$deleteDelta['pending_recipe_count'] === 0,
    'Recipe deletion must publish a tombstone without rescoring the catalog'
);
$activeBeforeReconcile = recipeScoreActiveRevision($db);
recipeScoreSetWorkState(
    $db,
    'publishing',
    (int)$activeBeforeReconcile['id'],
    (int)$activeBeforeReconcile['parent_score_revision_id'],
    1,
    1,
    0,
    0
);
$noWork = ingredientOntologyV3IncrementalRebuild($db, true);
$workPhase = (string)$db->query("
    SELECT phase FROM recipe_score_work_state WHERE id = 1
")->fetchColumn();
$assert(
    (string)$noWork['reason'] === 'no_pending_changes'
    && $workPhase === 'idle',
    'No-work recovery must clear a stale published work phase'
);
$assert(
    !empty($idSetAudit['valid'])
    && !empty($valueAudit['valid']),
    'Sparse catalog delta ancestry must remain recursively auditable'
);

$db = null;
@unlink($path);
@unlink($path . '-wal');
@unlink($path . '-shm');

echo "Recipe score delta tests passed: "
    . "{$assertions} assertions.\n";
