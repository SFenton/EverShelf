#!/usr/bin/env php
<?php
declare(strict_types=1);

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

$path = dirname(__DIR__) . '/data/.sparse-projection-'
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
        'sparse-projection-test', 'building', ?, ?, ?,
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

$recipe = $db->prepare("
    INSERT INTO recipe_catalog (
        primary_connector, title, cache_expires_at
    )
    VALUES ('manual', ?, datetime('now', '+1 day'))
");
$recipe->execute(['Parent Winner']);
$firstRecipeId = (int)$db->lastInsertId();
$recipe->execute(['Sparse Winner']);
$secondRecipeId = (int)$db->lastInsertId();
$ingredient = $db->prepare("
    INSERT INTO recipe_ingredients (
        recipe_id, position, raw_text, normalized_name
    )
    VALUES (?, 1, ?, ?)
");
$ingredient->execute([
    $firstRecipeId,
    'first ingredient',
    'first ingredient',
]);
$firstIngredientId = (int)$db->lastInsertId();
$ingredient->execute([
    $secondRecipeId,
    'second ingredient',
    'second ingredient',
]);
$secondIngredientId = (int)$db->lastInsertId();

$revision = $db->prepare("
    INSERT INTO recipe_score_revisions (
        inventory_revision, catalog_revision,
        inventory_fingerprint, score_date, catalog_max_id,
        status, recipe_count, ontology_version_id,
        scoring_model, scoring_config_hash,
        parent_score_revision_id, catalog_fingerprint,
        ontology_schema_hash, ontology_prompt_hash,
        ontology_model_hash, ontology_corpus_hash,
        ontology_content_hash, ontology_source_revision,
        ontology_source_hash, catalog_id_set_hash,
        ingredient_id_set_hash, score_rows_hash,
        match_rows_hash, materialization_hash,
        validation_report_json
    )
    VALUES (
        ?, 1, ?, ?, ?, 'building', 2, ?,
        'faceted-ontology-v3', ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?
    )
");
$revision->execute([
    1,
    $hash,
    recipeScoreCurrentDate(),
    $secondRecipeId,
    $versionId,
    $hash,
    null,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    ingredientOntologyV3Json([
        'materialized_hash_algorithm' => 'full-v1',
        'recipe_count' => 2,
        'ingredient_match_count' => 2,
    ]),
]);
$parentRevisionId = (int)$db->lastInsertId();

$score = $db->prepare("
    INSERT INTO recipe_inventory_scores (
        score_revision_id, recipe_id, coverage, directness,
        expiry_score, source_user_score, availability_score,
        required_count, matched_required_count,
        missing_required_count, uncertain_required_count,
        cookable, soonest_expiry_days
    )
    VALUES (?, ?, ?, 1, 0, 0, ?, 1, ?, ?, 0, ?, NULL)
");
$score->execute([
    $parentRevisionId,
    $firstRecipeId,
    0.8,
    0.8,
    1,
    0,
    1,
]);
$score->execute([
    $parentRevisionId,
    $secondRecipeId,
    0.2,
    0.2,
    0,
    1,
    0,
]);
$match = $db->prepare("
    INSERT INTO ingredient_ontology_shadow_matches (
        score_revision_id, recipe_ingredient_id,
        outcome, satisfies_required, confidence,
        relationship, explanation_json
    )
    VALUES (?, ?, ?, ?, 1, ?, '{}')
");
$match->execute([
    $parentRevisionId,
    $firstIngredientId,
    'exact',
    1,
    'exact',
]);
$match->execute([
    $parentRevisionId,
    $secondIngredientId,
    'not_in_inventory',
    0,
    'none',
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
    SET inventory_revision = 1,
        catalog_revision = 1,
        ontology_source_revision = 1,
        ontology_source_hash = ?,
        active_score_revision_id = ?
    WHERE id = 1
")->execute([$hash, $parentRevisionId]);
$db->exec("DELETE FROM recipe_score_mutations");
$db->exec('BEGIN IMMEDIATE');
recipeScoreBuildEffectiveProjection($db, $parentRevisionId);
$db->exec('COMMIT');

$childReport = ingredientOntologyV3Json([
    'materialized_hash_algorithm' => 'parent-delta-v2',
    'incremental_chain_depth' => 1,
    'recipe_count' => 2,
    'ingredient_match_count' => 2,
    'materialized_values' => [
        'current' => [
            'changed_match_row_count' => 1,
        ],
    ],
]);
$revision->execute([
    2,
    $hash,
    recipeScoreCurrentDate(),
    $secondRecipeId,
    $versionId,
    $hash,
    $parentRevisionId,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $childReport,
]);
$childRevisionId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO recipe_score_recipe_operations (
        score_revision_id, recipe_id, operation
    )
    VALUES (?, ?, 'replace')
")->execute([$childRevisionId, $secondRecipeId]);
$score->execute([
    $childRevisionId,
    $secondRecipeId,
    1.0,
    1.0,
    1,
    0,
    1,
]);
$match->execute([
    $childRevisionId,
    $secondIngredientId,
    'exact',
    1,
    'exact',
]);
$db->prepare("
    INSERT INTO recipe_score_recipe_ingredients (
        score_revision_id, recipe_id, recipe_ingredient_id
    )
    VALUES (?, ?, ?)
")->execute([
    $childRevisionId,
    $secondRecipeId,
    $secondIngredientId,
]);
ingredientOntologyV3SetPublicationGuard($db, true);
$db->prepare("
    UPDATE recipe_score_revisions
    SET status = 'ready', completed_at = CURRENT_TIMESTAMP
    WHERE id = ?
")->execute([$childRevisionId]);
ingredientOntologyV3SetPublicationGuard($db, false);

$readySparseGuardFailures = 0;
foreach ([
    [
        "INSERT INTO recipe_score_recipe_operations (
            score_revision_id, recipe_id, operation
        ) VALUES (
            {$childRevisionId}, {$firstRecipeId}, 'replace'
        )",
    ],
    [
        "UPDATE recipe_score_recipe_operations
         SET operation = 'delete'
         WHERE score_revision_id = {$childRevisionId}
           AND recipe_id = {$secondRecipeId}",
    ],
    [
        "DELETE FROM recipe_score_recipe_operations
         WHERE score_revision_id = {$childRevisionId}
           AND recipe_id = {$secondRecipeId}",
    ],
    [
        "INSERT INTO recipe_score_recipe_ingredients (
            score_revision_id, recipe_id, recipe_ingredient_id
        ) VALUES (
            {$childRevisionId}, {$firstRecipeId},
            {$secondIngredientId}
        )",
    ],
    [
        "UPDATE recipe_score_recipe_ingredients
         SET recipe_id = {$firstRecipeId}
         WHERE score_revision_id = {$childRevisionId}
           AND recipe_id = {$secondRecipeId}",
    ],
    [
        "DELETE FROM recipe_score_recipe_ingredients
         WHERE score_revision_id = {$childRevisionId}",
    ],
] as [$sql]) {
    try {
        $db->exec($sql);
    } catch (PDOException $error) {
        if (str_contains(
            $error->getMessage(),
            'ready recipe score'
        )) {
            $readySparseGuardFailures++;
        }
    }
}
$assert(
    $readySparseGuardFailures === 6,
    'Ready sparse operation and ingredient snapshots must reject every mutation kind: '
        . $readySparseGuardFailures
);

$db->exec('BEGIN IMMEDIATE');
recipeScoreApplyDeltaProjection(
    $db,
    $parentRevisionId,
    $childRevisionId
);
$db->prepare("
    UPDATE recipe_score_state
    SET active_score_revision_id = ?,
        inventory_revision = 2,
        cursor_revision = cursor_revision + 1
    WHERE id = 1
")->execute([$childRevisionId]);
$db->exec('COMMIT');
recipeScoreReadRevisionCacheClear();

$sources = $db->query("
    SELECT recipe_id, score_revision_id
    FROM recipe_score_effective_sources
    ORDER BY recipe_id
")->fetchAll(PDO::FETCH_KEY_PAIR);
$assert(
    (int)$sources[$firstRecipeId] === $parentRevisionId
    && (int)$sources[$secondRecipeId] === $childRevisionId
    && (int)$db->query("
        SELECT COUNT(*) FROM recipe_inventory_scores
        WHERE score_revision_id = {$childRevisionId}
    ")->fetchColumn() === 1,
    'Sparse publication must retain parent sources and write only changed recipes'
);

$browse = recipeCatalogBrowseResult($db, [
    'sort' => 'availability',
    'limit' => 10,
    'fields' => 'card',
]);
$assert(
    (int)$browse['items'][0]['id'] === $secondRecipeId
    && (float)$browse['items'][0]['coverage'] === 1.0
    && (int)$browse['revision']['score'] === $childRevisionId,
    'Recipe browse must rank through the active effective-source projection'
);
$detail = recipeDetailV3MatchMap(
    $db,
    [['ranking_ingredient_id' => $secondIngredientId]]
);
$assert(
    (int)$detail['revision']['id'] === $childRevisionId
    && (string)$detail['matches'][$secondIngredientId]['outcome']
        === 'exact',
    'Recipe detail must read physical matches through the logical sparse revision'
);

$db->exec('BEGIN IMMEDIATE');
recipeScoreBuildEffectiveProjection($db, $parentRevisionId);
$db->prepare("
    UPDATE recipe_score_state
    SET active_score_revision_id = ?,
        inventory_revision = 1,
        cursor_revision = cursor_revision + 1
    WHERE id = 1
")->execute([$parentRevisionId]);
$db->exec('COMMIT');
recipeScoreReadRevisionCacheClear();
$rolledBack = recipeCatalogBrowseResult($db, [
    'sort' => 'availability',
    'limit' => 10,
    'fields' => 'card',
]);
$assert(
    (int)$rolledBack['items'][0]['id'] === $firstRecipeId,
    'Projection rollback must restore the immutable parent ranking'
);

$db->exec('BEGIN IMMEDIATE');
recipeScoreBuildEffectiveProjection($db, $childRevisionId);
$db->prepare("
    UPDATE recipe_score_state
    SET active_score_revision_id = ?,
        inventory_revision = 2,
        cursor_revision = cursor_revision + 1
    WHERE id = 1
")->execute([$childRevisionId]);
$db->exec('COMMIT');
$sources = $db->query("
    SELECT recipe_id, score_revision_id
    FROM recipe_score_effective_sources
    ORDER BY recipe_id
")->fetchAll(PDO::FETCH_KEY_PAIR);
$assert(
    (int)$sources[$firstRecipeId] === $parentRevisionId
    && (int)$sources[$secondRecipeId] === $childRevisionId,
    'Projection reconstruction must replay the immutable delta chain'
);

$depthReport = json_decode(
    $childReport,
    true,
    64,
    JSON_THROW_ON_ERROR
);
$depthReport['incremental_chain_depth'] = 240;
ingredientOntologyV3SetReadyMutationGuard($db, true);
$db->prepare("
    UPDATE recipe_score_revisions
    SET validation_report_json = ?
    WHERE id = ?
")->execute([
    ingredientOntologyV3Json($depthReport),
    $childRevisionId,
]);
ingredientOntologyV3SetReadyMutationGuard($db, false);
$pendingInventoryRevision = recipeScoreMarkDirty($db);
$db->prepare("
    INSERT INTO recipe_score_pending_products (
        product_id, first_inventory_revision,
        latest_inventory_revision, reason
    )
    VALUES (999999, ?, ?, 'compaction_depth_test')
")->execute([
    $pendingInventoryRevision,
    $pendingInventoryRevision,
]);
$depthGate = ingredientOntologyV3IncrementalRebuild($db, true);
$assert(
    empty($depthGate['rebuilt'])
    && (string)$depthGate['reason'] === 'compaction_required',
    'Sparse publication must stop before the hard ancestry limit'
);
$db->prepare("
    DELETE FROM recipe_ingredients
    WHERE id = ?
")->execute([$secondIngredientId]);
$db->prepare("
    UPDATE recipe_score_state
    SET ontology_source_revision = ?,
        ontology_source_hash = ?,
        ontology_source_lineage_hash = ''
    WHERE id = 1
")->execute([
    (int)recipeScoreRevision(
        $db,
        $childRevisionId
    )['ontology_source_revision'],
    (string)recipeScoreRevision(
        $db,
        $childRevisionId
    )['ontology_source_hash'],
]);
$db->exec("DELETE FROM recipe_score_mutations");
$compaction = ingredientOntologyV3CompactActiveScores($db, true);
$compacted = recipeScoreActiveRevision($db);
$compactedReport = recipeScoreRevisionReport($compacted);
$assert(
    !empty($compaction['compacted'])
    && (int)$compacted['id'] === (int)$compaction['revision_id']
    && (string)(
        $compactedReport['materialized_hash_algorithm'] ?? ''
    ) === 'full-v1'
    && (int)$db->query("
        SELECT COUNT(*) FROM recipe_inventory_scores
        WHERE score_revision_id = " . (int)$compacted['id']
    )->fetchColumn() === 2
    && (int)$compaction['match_count'] === 1
    && (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_pending_products
        WHERE product_id = 999999
    ")->fetchColumn() === 1,
    'Background compaction must collapse the sparse projection into one full revision'
);
recipeScorePruneRevisions($db);
$assert(
    recipeScoreRevision($db, $childRevisionId) !== null
    && recipeScoreRevision($db, $parentRevisionId) !== null,
    'Pruning after compaction must retain the sparse parent closure'
);

$db = null;
@unlink($path);
@unlink($path . '-wal');
@unlink($path . '-shm');

echo "Sparse score projection tests passed: "
    . "{$assertions} assertions.\n";
