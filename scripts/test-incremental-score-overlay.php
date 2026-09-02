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
        recipe_id, position, raw_text, normalized_name,
        source_is_required, source_is_optional,
        requiredness_source
    )
    VALUES (
        ?, 1, 'red onion', 'red onion',
        1, 0, 'explicit_required'
    )
")->execute([$secondRecipeId]);
$overlayIngredientId = (int)$db->lastInsertId();

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
        'incremental-overlay-test', 'building', ?, ?, ?,
        'gemini-3.5-flash', ?, ?, ?, ?, ?, ?,
        'test_only', 'test', 'test', ?, ?, ?, CURRENT_TIMESTAMP
    )
")->execute(array_fill(0, 12, $hash));
ingredientOntologyV3SetPublicationGuard($db, true);
$db->exec("
    UPDATE ingredient_ontology_versions
    SET status = 'ready', ready_at = CURRENT_TIMESTAMP
    WHERE id = 1
");
ingredientOntologyV3SetPublicationGuard($db, false);
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
        ?, 1, ?, date('now', 'localtime'), ?, ?, 2, 1,
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
$previousContributorRecipeIds =
    ingredientOntologyV3IncrementalPreviousRecipeIds(
        $db,
        $parentRevisionId,
        [2]
    );
$assert(
    $previousContributorRecipeIds === [$secondRecipeId],
    'Incremental invalidation must retain every aggregate inventory '
        . 'contributor: '
        . ingredientOntologyV3Json($previousContributorRecipeIds)
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
    'materialized_hash_algorithm' => 'full-v1',
    'recipe_count' => 2,
    'ingredient_match_count' => 1,
]);
$parentIdSets = ingredientOntologyV3MaterializedIdSetHashes(
    $db,
    $parentRevisionId,
    null
);
$db->prepare("
    UPDATE recipe_score_revisions
    SET scoring_config_hash = ?,
        ontology_portable_content_hash = ?,
        ontology_review_manifest_hash = ?,
        ontology_resolution_gold_hash = ?,
        ontology_seal_hash = ?,
        catalog_id_set_hash = ?,
        ingredient_id_set_hash = ?
    WHERE id = ? AND status = 'building'
")->execute([
    ingredientOntologyV3ScoringConfigHash(),
    $hash,
    $hash,
    $hash,
    $hash,
    (string)$parentIdSets['catalog_id_set_hash'],
    (string)$parentIdSets['ingredient_id_set_hash'],
    $parentRevisionId,
]);
$db->prepare("
    UPDATE recipe_score_state
    SET ontology_source_revision = 1,
        ontology_source_hash = ?
    WHERE id = 1
")->execute([$hash]);
$projectionParent = recipeScoreRevision($db, $parentRevisionId);
$projectionRoot = ingredientOntologyV3CorpusAnnexEnsureScoreRoot(
    $db,
    $projectionParent
);
$assert(
    $projectionRoot !== null,
    'The building parent must receive a sealed corpus annex root: '
        . ingredientOntologyV3Json([
            'parent' => $projectionParent,
            'state' => recipeScoreState($db),
            'version' => ingredientOntologyV3Version(
                $db,
                (int)$projectionParent['ontology_version_id']
            ),
            'extension_match' =>
                ingredientOntologyV3IdentityExtensionSnapshotMatches(
                    $db,
                    (int)$projectionParent['ontology_version_id'],
                    [
                        'revision' => (int)$projectionParent[
                            'identity_extension_revision'
                        ],
                        'hash' => (string)$projectionParent[
                            'identity_extension_hash'
                        ],
                    ]
                ),
        ])
);
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
$db->exec("DELETE FROM recipe_score_mutations");

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
$fanoutGatePath = $path . '.fanout-gate';
@unlink($fanoutGatePath);
databaseMaintenanceOnlineBackup($path, $fanoutGatePath);
$fanoutGateDb = new PDO('sqlite:' . $fanoutGatePath);
$fanoutGateDb->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);
$fanoutGateDb->setAttribute(
    PDO::ATTR_DEFAULT_FETCH_MODE,
    PDO::FETCH_ASSOC
);
$fanoutGateDb->exec('PRAGMA foreign_keys=ON');
ingredientOntologyV3RegisterGuardFunctions($fanoutGateDb);
ingredientOntologyV3SetReadyMutationGuard($fanoutGateDb, true);
try {
    $fanoutGateDb->prepare("
        INSERT INTO ingredient_ontology_entities (
            ontology_version_id, local_key, slug,
            canonical_name, entity_kind, identity_role,
            provenance
        )
        VALUES (
            1, 'overlay:red-onion', 'red-onion',
            'Red Onion', 'ingredient', 'identity_leaf',
            'test_fixture'
        )
    ")->execute();
    $overlayEntityId = (int)$fanoutGateDb->lastInsertId();
    $overlayOwner = $fanoutGateDb->prepare("
        SELECT ingredient.*, recipe.language,
               recipe.primary_connector,
               '' AS origin_external_id,
               '' AS origin_locale
        FROM recipe_ingredients ingredient
        JOIN recipe_catalog recipe
          ON recipe.id = ingredient.recipe_id
        WHERE ingredient.id = ?
    ");
    $overlayOwner->execute([$overlayIngredientId]);
    $overlayOwner = $overlayOwner->fetch(PDO::FETCH_ASSOC);
    $fanoutGateDb->prepare("
        INSERT INTO ingredient_ontology_mappings (
            ontology_version_id, owner_type, owner_id,
            owner_fingerprint, source_label, normalized_label,
            language, entity_id, status, confidence,
            mapping_source, evidence_json, attributes_json,
            is_staple
        )
        VALUES (
            1, 'recipe_ingredient', ?, ?,
            'red onion', 'red onion', 'en', ?,
            'accepted', 1, 'test_fixture', '{}', '{}', 0
        )
    ")->execute([
        $overlayIngredientId,
        ingredientOntologyV3RecipeOwnerFingerprint(
            'recipe_ingredient',
            $overlayOwner
        ),
        $overlayEntityId,
    ]);
} finally {
    ingredientOntologyV3SetReadyMutationGuard(
        $fanoutGateDb,
        false
    );
}
$emptyInventory = ['by_product' => []];
$assert(
    ingredientOntologyV3IncrementalCurrentExpansionEnabled(
        $fanoutGateDb,
        $parentRevisionId,
        $emptyInventory,
        999
    ),
    'A missing effective projection must retain conservative current '
        . 'dependency expansion'
);
recipeScoreBuildEffectiveProjection(
    $fanoutGateDb,
    $parentRevisionId
);
$assert(
    !ingredientOntologyV3IncrementalCurrentExpansionEnabled(
        $fanoutGateDb,
        $parentRevisionId,
        $emptyInventory,
        999
    )
    && ingredientOntologyV3IncrementalCurrentExpansionEnabled(
        $fanoutGateDb,
        $parentRevisionId,
        ['by_product' => [999 => null]],
        999
    )
    && ingredientOntologyV3IncrementalCurrentExpansionEnabled(
        $fanoutGateDb,
        $parentRevisionId,
        [],
        999
    ),
    'A ready projection must suppress only products definitively absent '
        . 'from the captured scoring inventory'
);
$context = new IngredientOntologyV3MatcherContext(
    $fanoutGateDb,
    1,
    0
);
$suppressedHasMore = null;
$suppressedRecipeIds =
    ingredientOntologyV3IncrementalAffectedRecipeIds(
        $fanoutGateDb,
        1,
        $parentRevisionId,
        [999],
        $emptyInventory,
        $context,
        [$overlayEntityId],
        $projectionRoot,
        0,
        10,
        $suppressedHasMore,
        false
    );
$expandedHasMore = null;
$expandedRecipeIds =
    ingredientOntologyV3IncrementalAffectedRecipeIds(
        $fanoutGateDb,
        1,
        $parentRevisionId,
        [999],
        ['by_product' => [999 => null]],
        $context,
        [$overlayEntityId],
        $projectionRoot,
        0,
        10,
        $expandedHasMore,
        true
    );
$assert(
    $suppressedRecipeIds === []
    && $suppressedHasMore === false
    && $expandedRecipeIds === [$secondRecipeId]
    && $expandedHasMore === false,
    'Current identity closure must be optional while prior contributor '
        . 'selection remains independently available'
);
$fanoutGateDb = null;
@unlink($fanoutGatePath);
@unlink($fanoutGatePath . '-wal');
@unlink($fanoutGatePath . '-shm');
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
$assert(
    (string)$db->query("
        SELECT status FROM recipe_score_revisions
        WHERE id = {$overlayRevisionId}
    ")->fetchColumn() === 'failed',
    'Inventory invalidation must terminalize the displaced building overlay'
);
$deletePath = $path . '.delete';
@unlink($deletePath);
databaseMaintenanceOnlineBackup($path, $deletePath);
$deleteDb = new PDO('sqlite:' . $deletePath);
$deleteDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$deleteDb->setAttribute(
    PDO::ATTR_DEFAULT_FETCH_MODE,
    PDO::FETCH_ASSOC
);
$deleteDb->exec('PRAGMA foreign_keys=ON');
ingredientOntologyV3RegisterGuardFunctions($deleteDb);
$deleteDb->exec("
    INSERT INTO products (name, brand, category)
    VALUES ('Deleted score product', '', '')
");
$deletedProductId = (int)$deleteDb->lastInsertId();
$deleteDb->prepare("
    INSERT INTO inventory (product_id, location, quantity)
    VALUES (?, 'dispensa', 1)
")->execute([$deletedProductId]);
recipeScoreMarkProductDirty(
    $deleteDb,
    $deletedProductId,
    'delete_control'
);
$deleteDb->prepare("DELETE FROM products WHERE id = ?")
    ->execute([$deletedProductId]);
$assert(
    (int)$deleteDb->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_products
        WHERE product_id = {$deletedProductId}
    ")->fetchColumn() === 1,
    'Deleted products must remain pending until their old matches are rescored'
);
$deletePlan = ingredientOntologyV3CorpusProjectionV2Classify(
    $deleteDb,
    recipeScoreActiveRevision($deleteDb),
    recipeScoreState($deleteDb),
    false
);
$deleteProjection =
    ingredientOntologyV3IncrementalRebuild($deleteDb, true);
$assert(
    !empty($deletePlan['eligible'])
    && (array)$deletePlan['product_ids'] === [$deletedProductId]
    && (string)$deletePlan['entries'][0]['operation'] === 'delete'
    && !empty($deleteProjection['rebuilt'])
    && (array)$deleteProjection['product_ids']
        === [$deletedProductId],
    'Existing product deletion must publish a selective tombstone: '
        . ingredientOntologyV3Json([
            'plan' => $deletePlan,
            'result' => $deleteProjection,
            'mutations' => $deleteDb->query("
                SELECT revision, owner_type, owner_id, operation,
                       source_table, source_row_id, reason
                FROM recipe_score_mutations
                WHERE domain = 'source'
                ORDER BY revision
            ")->fetchAll(PDO::FETCH_ASSOC),
        ])
);
$deleteDb = null;
@unlink($deletePath);
@unlink($deletePath . '-wal');
@unlink($deletePath . '-shm');

$pendingLimit = ingredientOntologyV3IncrementalProductLimit();
$pendingRevision = (int)recipeScoreState($db)['inventory_revision'];
$pendingInsert = $db->prepare("
    INSERT INTO recipe_score_pending_products (
        product_id, first_inventory_revision,
        latest_inventory_revision, reason
    )
    VALUES (?, ?, ?, 'overflow_regression')
");
$pendingInsert->execute([
    $deletedProductId,
    $pendingRevision,
    $pendingRevision,
]);
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
$assert(
    !ingredientOntologyActivationNeedsScoreBuild($db),
    'An oversized product queue must remain bounded incremental work'
);
$heldScoreLock = recipeScoreAcquireLock($db);
$assert(
    is_resource($heldScoreLock),
    'Score recovery lock fixture must acquire the worker lock'
);
$lockedResult = ingredientOntologyV3IncrementalRebuild(
    $db,
    true
);
recipeScoreReleaseLock($heldScoreLock);
$assert(
    empty($lockedResult['rebuilt'])
    && (string)($lockedResult['reason'] ?? '') === 'locked',
    'A contended score cycle must remain retryable without changing state'
);
$backgroundLockPath = dirname($path) . '/.background-writer.lock';
$coordinationLockPath = $path . '.score-coordination';
$workerHeartbeatPath = $path . '.worker-heartbeat';
$workerStatusPath = $path . '.worker-status';
$coordinationLock = fopen($coordinationLockPath, 'c+');
$assert(
    is_resource($coordinationLock)
    && flock($coordinationLock, LOCK_EX | LOCK_NB),
    'Score coordination lock fixture must be available'
);
$pipes = [];
$coordinatedWorker = proc_open(
    [
        PHP_BINARY,
        __DIR__ . '/incremental-score-worker.php',
        '--db=' . $path,
        '--background-lock=' . $backgroundLockPath,
        '--coordination-lock=' . $coordinationLockPath,
        '--heartbeat=' . $workerHeartbeatPath,
        '--status-file=' . $workerStatusPath,
        '--force',
        '--json',
    ],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    dirname(__DIR__)
);
if (!is_resource($coordinatedWorker)) {
    throw new RuntimeException(
        'Could not start score coordination worker probe'
    );
}
fclose($pipes[0]);
$coordinatedStdout = stream_get_contents($pipes[1]);
$coordinatedStderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$coordinatedStatus = proc_close($coordinatedWorker);
flock($coordinationLock, LOCK_UN);
fclose($coordinationLock);
$coordinatedPayload = json_decode(
    (string)$coordinatedStdout,
    true
);
$assert(
    $coordinatedStatus === 0
    && is_array($coordinatedPayload)
    && ($coordinatedPayload['success'] ?? null) === true
    && (string)($coordinatedPayload['reason'] ?? '')
        === 'score_coordination_locked'
    && !empty($coordinatedPayload['skipped'])
    && !empty($coordinatedPayload['retryable']),
    'Copied builds must exclude the incremental scorer before SQLite: '
        . ingredientOntologyV3Json([
            'status' => $coordinatedStatus,
            'stdout' => $coordinatedStdout,
            'stderr' => $coordinatedStderr,
        ])
);
$backgroundLock = fopen($backgroundLockPath, 'c+');
$assert(
    is_resource($backgroundLock)
    && flock($backgroundLock, LOCK_EX | LOCK_NB),
    'Background writer lock fixture must be available'
);
$pipes = [];
$lockedWorker = proc_open(
    [
        PHP_BINARY,
        __DIR__ . '/incremental-score-worker.php',
        '--db=' . $path,
        '--background-lock=' . $backgroundLockPath,
        '--coordination-lock=' . $coordinationLockPath,
        '--heartbeat=' . $workerHeartbeatPath,
        '--status-file=' . $workerStatusPath,
        '--force',
        '--json',
    ],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    dirname(__DIR__)
);
if (!is_resource($lockedWorker)) {
    throw new RuntimeException(
        'Could not start background-lock score worker probe'
    );
}
fclose($pipes[0]);
$lockedWorkerStdout = stream_get_contents($pipes[1]);
$lockedWorkerStderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$lockedWorkerStatus = proc_close($lockedWorker);
flock($backgroundLock, LOCK_UN);
fclose($backgroundLock);
$lockedWorkerPayload = json_decode(
    (string)$lockedWorkerStdout,
    true
);
$assert(
    $lockedWorkerStatus === 0
    && is_array($lockedWorkerPayload)
    && ($lockedWorkerPayload['success'] ?? null) === true
    && (string)($lockedWorkerPayload['reason'] ?? '')
        === 'background_writer_locked'
    && !empty($lockedWorkerPayload['skipped'])
    && !empty($lockedWorkerPayload['retryable']),
    'The incremental worker must skip before touching SQLite while '
        . 'activation owns the shared writer lock: '
        . ingredientOntologyV3Json([
            'status' => $lockedWorkerStatus,
            'stdout' => $lockedWorkerStdout,
            'stderr' => $lockedWorkerStderr,
        ])
);
$assert(
    str_starts_with(
        trim((string)file_get_contents($workerStatusPath)),
        '0 '
    ),
    'Retryable background-lock skips must keep worker health successful'
);
$overflowResult = ingredientOntologyV3IncrementalRebuild(
    $db,
    true
);
$pendingProductsAfterFirstPage = (int)$db->query("
    SELECT COUNT(*) FROM recipe_score_pending_products
")->fetchColumn();
$assert(
    !empty($overflowResult['rebuilt'])
    && $pendingProductsAfterFirstPage === 1
    && (int)recipeScoreState($db)['active_score_revision_id']
        !== $activeBeforeOverflow
    && (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_revisions
    ")->fetchColumn() > $revisionCountBeforeOverflow,
    'An over-limit pending set must publish one bounded page and leave '
        . 'the remainder pending: '
        . ingredientOntologyV3Json($overflowResult)
);
$assert(
    !ingredientOntologyActivationNeedsScoreBuild($db),
    'A remaining product page must not create ontology build intent'
);

$pipes = [];
$worker = proc_open(
    [
        PHP_BINARY,
        __DIR__ . '/incremental-score-worker.php',
        '--db=' . $path,
        '--background-lock=' . $backgroundLockPath,
        '--coordination-lock=' . $coordinationLockPath,
        '--heartbeat=' . $workerHeartbeatPath,
        '--status-file=' . $workerStatusPath,
        '--force',
        '--json',
    ],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    dirname(__DIR__)
);
if (!is_resource($worker)) {
    throw new RuntimeException(
        'Could not start incremental score worker regression probe'
    );
}
fclose($pipes[0]);
$workerStdout = stream_get_contents($pipes[1]);
$workerStderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$workerStatus = proc_close($worker);
$workerPayload = json_decode((string)$workerStdout, true);
$assert(
    $workerStatus === 0
    && is_array($workerPayload)
    && ($workerPayload['success'] ?? null) === true
    && !empty($workerPayload['rebuilt'])
    && (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_pending_products
    ")->fetchColumn() === 0
    && str_starts_with(
        trim((string)file_get_contents($workerStatusPath)),
        '0 '
    ),
    'The worker must resume and publish the remaining bounded page: '
        . ingredientOntologyV3Json([
            'status' => $workerStatus,
            'stdout' => $workerStdout,
            'stderr' => $workerStderr,
        ])
);
$pipes = [];
$loopWorker = proc_open(
    [
        PHP_BINARY,
        __DIR__ . '/incremental-score-worker.php',
        '--db=' . $path,
        '--background-lock=' . $backgroundLockPath,
        '--coordination-lock=' . $coordinationLockPath,
        '--heartbeat=' . $workerHeartbeatPath,
        '--status-file=' . $workerStatusPath,
        '--force',
        '--loop',
        '--max-cycles=1',
        '--json',
    ],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    dirname(__DIR__)
);
if (!is_resource($loopWorker)) {
    throw new RuntimeException(
        'Could not start bounded loop score worker probe'
    );
}
fclose($pipes[0]);
$loopWorkerStdout = stream_get_contents($pipes[1]);
$loopWorkerStderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$loopWorkerStatus = proc_close($loopWorker);
$loopWorkerPayload = json_decode(
    (string)$loopWorkerStdout,
    true
);
$assert(
    $loopWorkerStatus === 0
    && (
        trim($loopWorkerStdout) === ''
        || (
            is_array($loopWorkerPayload)
            && ($loopWorkerPayload['success'] ?? null) === true
        )
    ),
    'A bounded loop must exit successfully after the queue converges: '
        . ingredientOntologyV3Json([
            'status' => $loopWorkerStatus,
            'stdout' => $loopWorkerStdout,
            'stderr' => $loopWorkerStderr,
        ])
);
$db->exec('DELETE FROM recipe_score_pending_products');
$overflowRecipeFixture = $db->prepare("
    INSERT INTO recipe_catalog (
        id, primary_connector, title, language,
        deleted_at, cache_expires_at
    )
    VALUES (
        ?, 'manual', ?, 'en',
        CURRENT_TIMESTAMP, datetime('now', '+1 day')
    )
");
for ($offset = 0; $offset <= $pendingLimit; $offset++) {
    $overflowRecipeFixture->execute([
        200000 + $offset,
        'Overflow recipe ' . $offset,
    ]);
}
$db->exec('DELETE FROM recipe_score_pending_recipes');
$pendingRecipeInsert = $db->prepare("
    INSERT INTO recipe_score_pending_recipes (
        recipe_id, operation, lane, first_catalog_revision,
        latest_catalog_revision, latest_ontology_source_revision,
        reason
    )
    VALUES (
        ?, 'replace', 'maintenance', ?, ?, ?,
        'overflow_regression'
    )
");
$overflowState = recipeScoreState($db);
for ($offset = 0; $offset <= $pendingLimit; $offset++) {
    $pendingRecipeInsert->execute([
        200000 + $offset,
        max(1, (int)$overflowState['catalog_revision']),
        max(1, (int)$overflowState['catalog_revision']),
        max(1, (int)$overflowState['ontology_source_revision']),
    ]);
}
$recipeOverflow = ingredientOntologyV3IncrementalRebuild($db, true);
$recipeOverflowRemaining = (int)$db->query("
    SELECT COUNT(*) FROM recipe_score_pending_recipes
")->fetchColumn();
$assert(
    !empty($recipeOverflow['rebuilt'])
    && $recipeOverflowRemaining > 0
    && $recipeOverflowRemaining <= 3
    && !ingredientOntologyActivationNeedsScoreBuild($db),
    'Recipe overflow must make a bounded fair page without requiring '
        . 'a product or serving carrier: '
        . ingredientOntologyV3Json([
            'result' => $recipeOverflow,
            'pending' => $recipeOverflowRemaining,
            'needs_build' =>
                ingredientOntologyActivationNeedsScoreBuild($db),
        ])
);
$recipeOverflowPages = [];
for ($page = 0; $page < 5; $page++) {
    $recipeOverflowPages[] =
        ingredientOntologyV3IncrementalRebuild($db, true);
    $recipeOverflowState = recipeScoreState($db);
    $recipeOverflowActive = recipeScoreActiveRevision($db);
    if (
        (int)$db->query("
            SELECT COUNT(*) FROM recipe_score_pending_recipes
        ")->fetchColumn() === 0
        && (int)$recipeOverflowActive[
            'covered_ontology_source_revision'
        ] === (int)$recipeOverflowState[
            'ontology_source_revision'
        ]
    ) {
        break;
    }
}
$recipeOverflowSecond = $recipeOverflowPages[0] ?? [];
$assert(
    !empty($recipeOverflowSecond['rebuilt'])
    && (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_pending_recipes
    ")->fetchColumn() === 0
    && (int)$recipeOverflowActive[
        'covered_ontology_source_revision'
    ] === (int)$recipeOverflowState['ontology_source_revision'],
    'The final recipe page must complete the source projection fence: '
        . ingredientOntologyV3Json($recipeOverflowPages)
);

$journalParent = recipeScoreActiveRevision($db);
$journalState = recipeScoreState($db);
$journalState['catalog_revision'] =
    (int)$journalParent['catalog_revision'] + 1;
$journalErrors = ingredientOntologyV3IncrementalScopedMutationErrors(
    $db,
    $journalParent,
    $journalState,
    [],
    [$firstRecipeId]
);
$assert(
    in_array(
        'catalog_mutation_journal_incomplete',
        $journalErrors,
        true
    ),
    'A catalog journal gap must be classified for copied score recovery'
);
$db->prepare("
    UPDATE recipe_score_revisions
    SET status = 'building',
        last_error = '',
        completed_at = NULL
    WHERE id = ?
")->execute([$overlayRevisionId]);
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
$assert(
    (string)$db->query("
        SELECT status FROM recipe_score_revisions
        WHERE id = {$overlayRevisionId}
    ")->fetchColumn() === 'failed',
    'Catalog invalidation must terminalize the displaced building overlay'
);

$db = null;
@unlink($path);
@unlink($path . '-wal');
@unlink($path . '-shm');
@unlink($backgroundLockPath);
@unlink($coordinationLockPath);
@unlink($workerHeartbeatPath);
@unlink($workerStatusPath);

echo "Incremental score overlay tests passed: {$assertions} assertions.\n";
