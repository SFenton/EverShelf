#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (
    &$assertions
): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$path = dirname(__DIR__) . '/data/.incremental-overlay-'
    . getmypid() . '.sqlite';
@unlink($path);
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
migrateDB($db);
$db->exec("
    INSERT INTO products (name, brand, category)
    VALUES ('Legacy pending control', '', '')
");
$legacyProductId = (int)$db->lastInsertId();
recipeScoreMarkProductDirty(
    $db,
    $legacyProductId,
    'legacy_control'
);
$assert(
    (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_products
    ")->fetchColumn() === 0,
    'Legacy scoring must not accumulate incremental pending products'
);

$recipe = $db->prepare("
    INSERT INTO recipe_catalog (
        title, primary_connector, language, cache_expires_at
    )
    VALUES (?, 'manual', 'en', datetime('now', '+1 day'))
");
$recipe->execute(['Parent Winner']);
$firstRecipeId = (int)$db->lastInsertId();
$recipe->execute(['Overlay Winner']);
$secondRecipeId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO recipe_ingredients (
        recipe_id, position, raw_text, normalized_name
    )
    VALUES (?, 1, 'red onion', 'red onion')
")->execute([$secondRecipeId]);
$overlayIngredientId = (int)$db->lastInsertId();

$hash = str_repeat('a', 64);
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
        ontology_source_hash, validation_report_json
    )
    VALUES (
        ?, 1, ?, date('now'), ?, ?, 2, 1,
        'faceted-ontology-v3', ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?
    )
");
$revision->execute([
    1,
    $hash,
    $secondRecipeId,
    'building',
    $hash,
    null,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    '{}',
]);
$parentRevisionId = (int)$db->lastInsertId();
$overlayReport = ingredientOntologyV3Json([
    'overlay_ready' => true,
    'materialized_hash_algorithm' => 'parent-delta-v1',
    'materialized_values' => [
        'overlay' => ['overlay_hash' => $hash],
    ],
]);
$revision->execute([
    2,
    $hash,
    $secondRecipeId,
    'building',
    $hash,
    $parentRevisionId,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $overlayReport,
]);
$overlayRevisionId = (int)$db->lastInsertId();

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
$match = $db->prepare("
    INSERT INTO ingredient_ontology_shadow_matches (
        score_revision_id, recipe_ingredient_id,
        recipe_mapping_id, inventory_product_id,
        inventory_mapping_id, outcome,
        satisfies_required, confidence, relationship,
        explanation_json
    )
    VALUES (?, ?, NULL, NULL, NULL, ?, ?, 1, ?, '{}')
");
$match->execute([
    $parentRevisionId,
    $overlayIngredientId,
    'not_in_inventory',
    0,
    'none',
]);
$match->execute([
    $overlayRevisionId,
    $overlayIngredientId,
    'exact',
    1,
    'exact',
]);
$db->prepare("
    UPDATE ingredient_ontology_shadow_matches
    SET explanation_json = ?
    WHERE score_revision_id = ?
      AND recipe_ingredient_id = ?
")->execute([
    ingredientOntologyV3Json([
        'inventory_aggregate' => [
            'product_ids' => [1, 2],
            'contributors_complete' => true,
        ],
    ]),
    $parentRevisionId,
    $overlayIngredientId,
]);
$assert(
    ingredientOntologyV3IncrementalPreviousRecipeIds(
        $db,
        $parentRevisionId,
        [2]
    ) === [$secondRecipeId],
    'Incremental invalidation must retain every aggregate inventory contributor'
);
$db->prepare("
    INSERT INTO recipe_score_incremental_recipes (
        score_revision_id, recipe_id
    )
    VALUES (?, ?)
")->execute([$overlayRevisionId, $secondRecipeId]);
$score->execute([
    $parentRevisionId,
    $secondRecipeId,
    0.2,
    0.2,
    0,
    1,
    0,
]);
$score->execute([
    $overlayRevisionId,
    $secondRecipeId,
    1.0,
    1.0,
    1,
    0,
    1,
]);
$parentReport = ingredientOntologyV3Json([
    'ingredient_match_count' => 0,
]);
ingredientOntologyV3SetPublicationGuard($db, true);
$db->prepare("
    UPDATE recipe_score_revisions
    SET status = 'ready',
        validation_report_json = ?,
        completed_at = CURRENT_TIMESTAMP
    WHERE id = ?
")->execute([$parentReport, $parentRevisionId]);
ingredientOntologyV3SetPublicationGuard($db, false);
$db->prepare("
    UPDATE recipe_score_state
    SET active_score_revision_id = ?,
        active_score_overlay_revision_id = ?,
        inventory_revision = 2,
        catalog_revision = 1,
        ontology_source_revision = 1,
        ontology_source_hash = ?
    WHERE id = 1
")->execute([
    $parentRevisionId,
    $overlayRevisionId,
    $hash,
]);

$state = recipeScoreState($db);
$active = recipeScoreActiveRevision($db);
$overlay = recipeScoreActiveOverlay($db, $state, $active);
$assert(
    $overlay !== null
    && (int)$overlay['id'] === $overlayRevisionId,
    'A committed building overlay must be readable over its active parent'
);
$criteria = recipeCatalogNormalizeBrowseOptions([
    'sort' => 'availability',
    'limit' => 10,
]);
$built = recipeCatalogBrowseCte(
    $criteria,
    $parentRevisionId,
    $secondRecipeId,
    $overlayRevisionId
);
$query = $db->prepare(
    $built['cte']
    . " SELECT id, availability_score
        FROM deduped
        ORDER BY {$built['order']}"
);
recipeCatalogBindValues($query, $built['params']);
$query->execute();
$rows = $query->fetchAll(PDO::FETCH_ASSOC);
$assert(
    (int)$rows[0]['id'] === $secondRecipeId
    && (float)$rows[0]['availability_score'] === 1.0,
    'Recipe browse must rank the overlay score ahead of the parent score'
);
$detail = recipeDetailV3MatchMap(
    $db,
    [[
        'ranking_ingredient_id' => $overlayIngredientId,
    ]],
    recipeScoreReadRevision($db)
);
$assert(
    (int)($detail['revision']['id'] ?? 0) === $overlayRevisionId
    && (string)(
        $detail['matches'][$overlayIngredientId]['outcome'] ?? ''
    ) === 'exact',
    'Recipe detail and grocery presence must use overlay matches for affected recipes'
);
$detailResponse = recipeCatalogDetailBuild(
    $db,
    $secondRecipeId,
    false,
    'read',
    false
);
$assert(
    is_array($detailResponse)
    && (int)($detailResponse['revision']['ranking'] ?? 0)
        === $overlayRevisionId
    && (int)($detailResponse['revision']['inventory'] ?? 0) === 2,
    'Feedback and detail revision metadata must bind overlay observations to the overlay revision'
);

$db->prepare("
    UPDATE recipe_score_state
    SET active_score_overlay_revision_id = NULL
    WHERE id = 1
")->execute();
recipeScoreReadRevisionCacheClear();
$withoutOverlay = recipeCatalogBrowseCte(
    $criteria,
    $parentRevisionId,
    $secondRecipeId,
    null
);
$query = $db->prepare(
    $withoutOverlay['cte']
    . " SELECT id, availability_score
        FROM deduped
        ORDER BY {$withoutOverlay['order']}"
);
recipeCatalogBindValues($query, $withoutOverlay['params']);
$query->execute();
$rows = $query->fetchAll(PDO::FETCH_ASSOC);
$assert(
    (int)$rows[0]['id'] === $firstRecipeId,
    'Clearing the overlay must restore the immutable parent ranking'
);
$assert(
    ingredientOntologyV3IncrementalChainPolicy([
        'materialized_hash_algorithm' => 'full-v1',
    ]) === ['depth' => 1, 'compact' => false]
    && ingredientOntologyV3IncrementalChainPolicy([
        'materialized_hash_algorithm' => 'parent-delta-v1',
        'incremental_chain_depth' => 3,
    ]) === ['depth' => 4, 'compact' => true]
    && ingredientOntologyV3IncrementalChainPolicy([
        'materialized_hash_algorithm' => 'parent-delta-v1',
    ])['compact'] === true,
    'Incremental chains must compact before pruning can sever their hash ancestry'
);
$db->prepare("
    UPDATE recipe_score_state
    SET active_score_overlay_revision_id = ?
    WHERE id = 1
")->execute([$overlayRevisionId]);
$cursorBeforeDirty = recipeScoreState($db)['cursor_revision'];
recipeScoreMarkDirty($db);
$dirtyState = recipeScoreState($db);
$assert(
    $dirtyState['active_score_overlay_revision_id'] === null
    && $dirtyState['cursor_revision'] === $cursorBeforeDirty + 1,
    'A newer inventory mutation must invalidate the overlay and every cursor that observed it'
);
$db->exec("
    INSERT INTO products (name, brand, category)
    VALUES ('Deleted score product', '', '')
");
$deletedProductId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO inventory (product_id, location, quantity)
    VALUES (?, 'dispensa', 1)
")->execute([$deletedProductId]);
recipeScoreMarkProductDirty(
    $db,
    $deletedProductId,
    'delete_control'
);
$db->prepare("DELETE FROM products WHERE id = ?")
    ->execute([$deletedProductId]);
$assert(
    (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_products
        WHERE product_id = {$deletedProductId}
    ")->fetchColumn() === 1,
    'Deleted products must remain pending until their old matches are rescored'
);

$pendingLimit = ingredientOntologyV3IncrementalProductLimit();
$pendingRevision = (int)recipeScoreState($db)['inventory_revision'];
$pendingInsert = $db->prepare("
    INSERT INTO recipe_score_pending_products (
        product_id, first_inventory_revision,
        latest_inventory_revision, reason
    )
    VALUES (?, ?, ?, 'overflow_regression')
");
for ($offset = 0; $offset < $pendingLimit; $offset++) {
    $pendingInsert->execute([
        100000 + $offset,
        $pendingRevision,
        $pendingRevision,
    ]);
}
$activeBeforeOverflow =
    (int)recipeScoreState($db)['active_score_revision_id'];
$revisionCountBeforeOverflow = (int)$db->query("
    SELECT COUNT(*) FROM recipe_score_revisions
")->fetchColumn();
$overflowResult = ingredientOntologyV3IncrementalRebuild(
    $db,
    true
);
$assert(
    empty($overflowResult['rebuilt'])
    && ($overflowResult['reason'] ?? '')
        === 'full_rebuild_required'
    && in_array(
        'incremental pending product limit exceeded',
        (array)($overflowResult['errors'] ?? []),
        true
    )
    && (int)recipeScoreState($db)['active_score_revision_id']
        === $activeBeforeOverflow
    && (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_revisions
    ")->fetchColumn() === $revisionCountBeforeOverflow,
    'An over-limit pending set must require a full rebuild before publishing any falsely fresh partial revision'
);
$db->prepare("
    UPDATE recipe_score_state
    SET active_score_overlay_revision_id = ?
    WHERE id = 1
")->execute([$overlayRevisionId]);
$catalogStateBefore = recipeScoreState($db);
recipeScoreMarkCatalogDirty($db, false);
$catalogStateAfter = recipeScoreState($db);
$assert(
    $catalogStateAfter['active_score_overlay_revision_id'] === null
    && $catalogStateAfter['catalog_revision']
        === $catalogStateBefore['catalog_revision'] + 1
    && $catalogStateAfter['cursor_revision']
        === $catalogStateBefore['cursor_revision'] + 1,
    'Catalog mutations must invalidate an active overlay and every cursor that observed it'
);

$db = null;
@unlink($path);
@unlink($path . '-wal');
@unlink($path . '-shm');

echo "Incremental score overlay tests passed: {$assertions} assertions.\n";
