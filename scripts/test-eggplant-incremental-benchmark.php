#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        continue;
    }
    $parts = explode('=', substr($argument, 2), 2);
    $options[$parts[0]] = $parts[1] ?? true;
}
$catalogSize = 34652;
$ingredientCount = 409839;
$eggplantRecipeCount = 467;
$runs = 5;
$keepPath = trim((string)($options['keep-db'] ?? ''));
$path = $keepPath !== ''
    ? recipeCliAssertOutputPathSafe(
        $keepPath,
        __DIR__ . '/../data/evershelf.db'
    )
    : dirname(__DIR__) . '/data/.eggplant-incremental-benchmark-'
        . getmypid() . '.sqlite';
@unlink($path);

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
$percentile = static function (array $values, float $p): float {
    sort($values, SORT_NUMERIC);
    return (float)$values[
        (int)floor((count($values) - 1) * $p)
    ];
};

try {
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA journal_mode=WAL');
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
            'eggplant-benchmark', 'building', ?, ?, ?,
            'gemini-3.5-flash', ?, ?, ?, ?, ?, ?,
            'test_only', 'benchmark', 'test', ?, ?, ?,
            CURRENT_TIMESTAMP
        )
    ")->execute(array_fill(0, 12, $hash));
    $versionId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO ingredient_ontology_entities (
            ontology_version_id, local_key, slug,
            canonical_name, entity_kind, identity_role,
            provenance
        )
        VALUES (
            ?, 'benchmark:eggplant', 'eggplant',
            'Eggplant', 'ingredient', 'identity_leaf',
            'full-resolution-v3'
        )
    ")->execute([$versionId]);
    $eggplantEntityId = (int)$db->lastInsertId();
    $labelInsert = $db->prepare("
        INSERT INTO ingredient_ontology_labels (
            ontology_version_id, entity_id, language,
            label, normalized_label, kind, review_state,
            provenance, source_ref
        )
        VALUES (
            ?, ?, ?, ?, ?, 'exact_alias', 'accepted',
            'full-resolution-v3', ?
        )
    ");
    $reviewedLabels = [
        ['eggplant', 'en'],
        ['eggplants', 'en'],
        ['aubergine', 'en'],
        ['aubergines', 'en'],
        ['auberginen', 'de'],
        ['auberginer', 'da'],
        ['melanzana', 'it'],
        ['melanzane', 'it'],
        ['melanzani', 'de'],
        ['di melanzana', 'it'],
        ['di melanzane', 'it'],
        ['d aubergine', 'fr'],
        ['d aubergines', 'fr'],
    ];
    foreach ($reviewedLabels as [$label, $language]) {
        $labelInsert->execute([
            $versionId,
            $eggplantEntityId,
            $language,
            $label,
            ingredientOntologyV3NormalizeLabel($label),
            'benchmark:' . $language . ':' . $label,
        ]);
    }
    $recipeInsert = $db->prepare("
        INSERT INTO recipe_catalog (
            title, primary_connector, language, cache_expires_at
        )
        VALUES (?, 'manual', ?, datetime('now', '+1 day'))
    ");
    $ingredientInsert = $db->prepare("
        INSERT INTO recipe_ingredients (
            recipe_id, position, raw_text, normalized_name,
            is_required, is_optional, is_staple
        )
        VALUES (?, 0, ?, ?, 1, 0, 0)
    ");
    $extraIngredientInsert = $db->prepare("
        INSERT INTO recipe_ingredients (
            recipe_id, position, raw_text, normalized_name,
            is_required, is_optional, is_staple
        )
        VALUES (?, ?, ?, ?, 0, 1, 0)
    ");
    $eggplantRecipeIds = [];
    $additionalIngredientCount = $ingredientCount - $catalogSize;
    $baseAdditionalPerRecipe = intdiv(
        $additionalIngredientCount,
        $catalogSize
    );
    $additionalRemainder =
        $additionalIngredientCount % $catalogSize;
    $db->beginTransaction();
    for ($index = 0; $index < $catalogSize; $index++) {
        if ($index < $eggplantRecipeCount) {
            [$label, $language] =
                $reviewedLabels[$index % count($reviewedLabels)];
            $title = 'Eggplant benchmark ' . $index;
        } else {
            $label = 'filler ingredient ' . $index;
            $language = 'en';
            $title = 'Filler benchmark ' . $index;
        }
        $recipeInsert->execute([$title, $language]);
        $recipeId = (int)$db->lastInsertId();
        $ingredientInsert->execute([
            $recipeId,
            $label,
            ingredientOntologyV3NormalizeLabel($label),
        ]);
        $extraCount = $baseAdditionalPerRecipe
            + ($index < $additionalRemainder ? 1 : 0);
        for ($position = 1; $position <= $extraCount; $position++) {
            $extraLabel = 'benchmark pantry staple '
                . ($position % 32);
            $extraIngredientInsert->execute([
                $recipeId,
                $position,
                $extraLabel,
                $extraLabel,
            ]);
        }
        if ($index < $eggplantRecipeCount) {
            $eggplantRecipeIds[] = $recipeId;
        }
    }
    $db->commit();

    ingredientOntologyV3SchemaMigrate($db);
    recipeSchemaMigrate($db);
    $eggplantOwners = $db->prepare("
        SELECT ingredient.*, recipe.language,
               recipe.primary_connector,
               '' AS origin_external_id,
               '' AS origin_locale
        FROM recipe_ingredients ingredient
        JOIN recipe_catalog recipe
          ON recipe.id = ingredient.recipe_id
        WHERE ingredient.recipe_id IN ("
            . implode(',', $eggplantRecipeIds)
            . ")
        ORDER BY ingredient.recipe_id
    ");
    $eggplantOwners->execute();
    $preexistingMapping = $db->prepare("
        INSERT INTO ingredient_ontology_mappings (
            ontology_version_id, owner_type, owner_id,
            owner_fingerprint, source_label, normalized_label,
            language, entity_id, status, confidence,
            mapping_source, evidence_json, attributes_json,
            is_staple
        )
        VALUES (
            ?, 'recipe_ingredient', ?, ?, ?, ?, ?,
            NULL, ?, 0, 'benchmark_preexisting_terminal',
            '{}', '{}', 0
        )
    ");
    $ownerIndex = 0;
    while ($owner = $eggplantOwners->fetch(PDO::FETCH_ASSOC)) {
        $preexistingMapping->execute([
            $versionId,
            (int)$owner['id'],
            ingredientOntologyV3RecipeOwnerFingerprint(
                'recipe_ingredient',
                $owner
            ),
            (string)$owner['raw_text'],
            (string)$owner['normalized_name'],
            (string)$owner['language'],
            ($ownerIndex++ % 2) === 0
                ? 'unresolved'
                : 'rejected',
        ]);
    }
    $db->exec("
        INSERT INTO products (name, brand, category)
        VALUES ('Eggplant', '', '')
    ");
    $eggplantProductId = (int)$db->lastInsertId();
    $schemaHash = ingredientOntologyV3SchemaHash();
    $promptHash = ingredientOntologyV3PromptHash();
    $modelHash = ingredientOntologyV3ModelHash(
        'gemini-3.5-flash'
    );
    $corpusHash = ingredientOntologyV3CorpusHash($db);
    $contentHash = ingredientOntologyV3ContentHash($db, $versionId);
    ingredientOntologyV3SetPublicationGuard($db, true);
    $db->prepare("
        UPDATE ingredient_ontology_versions
        SET schema_hash = ?,
            prompt_hash = ?,
            model_hash = ?,
            corpus_hash = ?,
            content_hash = ?,
            portable_content_hash = ?,
            seal_hash = ?,
            status = 'ready',
            ready_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([
        $schemaHash,
        $promptHash,
        $modelHash,
        $corpusHash,
        $contentHash,
        ingredientOntologyV3PortableContentHash($db, $versionId),
        ingredientOntologyV3Hash([
            'benchmark' => $contentHash,
        ]),
        $versionId,
    ]);
    ingredientOntologyV3SetPublicationGuard($db, false);
    ingredientOntologyV3IdentityAdmissionSync($db);
    $state = recipeScoreState($db);
    $sourceHash = ingredientOntologyV3CorpusHash($db);
    $db->prepare("
        UPDATE recipe_score_state
        SET ontology_source_hash = ?,
            ontology_source_lineage_hash = ''
        WHERE id = 1
    ")->execute([$sourceHash]);
    $state = recipeScoreState($db);
    $catalogFingerprint = recipeScoreCatalogFingerprint($db);
    $inventory = ingredientOntologyV3Inventory(
        $db,
        $versionId,
        recipeScoreCurrentDate()
    );
    $inventoryFingerprint =
        ingredientOntologyV3InventoryFingerprint(
            $inventory,
            $versionId
        );
    $report = ingredientOntologyV3Json([
        'shadow_only' => false,
        'activated' => true,
        'ontology_version_id' => $versionId,
        'recipe_count' => $catalogSize,
        'ingredient_match_count' => $ingredientCount,
        'inventory_revision' => (int)$state['inventory_revision'],
        'catalog_revision' => (int)$state['catalog_revision'],
        'ontology_source_revision' =>
            (int)$state['ontology_source_revision'],
        'ontology_source_hash' => $sourceHash,
        'scoring_configuration' =>
            ingredientOntologyV3ScoringConfiguration() + [
                'hash' => ingredientOntologyV3ScoringConfigHash(),
            ],
    ]);
    $version = ingredientOntologyV3Version($db, $versionId);
    $db->prepare("
        INSERT INTO recipe_score_revisions (
            inventory_revision, catalog_revision,
            inventory_fingerprint, score_date, catalog_max_id,
            status, recipe_count, ontology_version_id,
            scoring_model, scoring_config_hash,
            parent_score_revision_id, catalog_fingerprint,
            ontology_schema_hash, ontology_prompt_hash,
            ontology_model_hash, ontology_corpus_hash,
            ontology_content_hash, ontology_portable_content_hash,
            ontology_review_manifest_hash,
            ontology_resolution_gold_hash, ontology_seal_hash,
            ontology_source_revision, ontology_source_hash,
            catalog_id_set_hash, ingredient_id_set_hash,
            score_rows_hash, match_rows_hash,
            materialization_hash, validation_report_json,
            completed_at
        )
        VALUES (
            ?, ?, ?, ?, ?, 'building', ?, ?, 'faceted-ontology-v3',
            ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP
        )
    ")->execute([
        (int)$state['inventory_revision'],
        (int)$state['catalog_revision'],
        $inventoryFingerprint,
        recipeScoreCurrentDate(),
        recipeScoreCatalogMaxId($db),
        $catalogSize,
        $versionId,
        ingredientOntologyV3ScoringConfigHash(),
        $catalogFingerprint,
        (string)$version['schema_hash'],
        (string)$version['prompt_hash'],
        (string)$version['model_hash'],
        (string)$version['corpus_hash'],
        (string)$version['content_hash'],
        (string)$version['portable_content_hash'],
        (string)$version['review_manifest_hash'],
        (string)$version['resolution_gold_hash'],
        (string)$version['seal_hash'],
        (int)$state['ontology_source_revision'],
        $sourceHash,
        hash('sha256', 'benchmark-catalog-ids'),
        hash('sha256', 'benchmark-ingredient-ids'),
        hash('sha256', 'benchmark-score-rows'),
        hash('sha256', 'benchmark-match-rows'),
        hash('sha256', 'benchmark-materialization'),
        $report,
    ]);
    $parentRevisionId = (int)$db->lastInsertId();
    $scoreInsert = $db->prepare("
        INSERT INTO recipe_inventory_scores (
            score_revision_id, recipe_id, coverage, directness,
            expiry_score, source_user_score, availability_score,
            required_count, matched_required_count,
            missing_required_count, uncertain_required_count,
            cookable, soonest_expiry_days
        )
        VALUES (?, ?, 0, 0, 0, 0, 0, 1, 0, 1, 0, 0, NULL)
    ");
    $matchInsert = $db->prepare("
        INSERT INTO ingredient_ontology_shadow_matches (
            score_revision_id, recipe_ingredient_id, recipe_id,
            recipe_mapping_id, inventory_product_id,
            inventory_mapping_id, outcome, satisfies_required,
            confidence, relationship, explanation_json
        )
        VALUES (
            ?, ?, ?, NULL, NULL, NULL,
            'unresolved', 0, 0, 'none', '{}'
        )
    ");
    $rows = $db->query("
        SELECT recipe.id, ingredient.id AS ingredient_id
        FROM recipe_catalog recipe
        JOIN recipe_ingredients ingredient
          ON ingredient.recipe_id = recipe.id
        ORDER BY recipe.id
    ");
    $db->beginTransaction();
    $lastScoreRecipeId = 0;
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $recipeId = (int)$row['id'];
        if ($recipeId !== $lastScoreRecipeId) {
            $scoreInsert->execute([
                $parentRevisionId,
                $recipeId,
            ]);
            $lastScoreRecipeId = $recipeId;
        }
        $matchInsert->execute([
            $parentRevisionId,
            (int)$row['ingredient_id'],
            $recipeId,
        ]);
    }
    $db->commit();
    recipeScoreMarkContributorRevisionComplete(
        $db,
        $parentRevisionId
    );
    ingredientOntologyV3SetPublicationGuard($db, true);
    $db->prepare("
        UPDATE recipe_score_revisions
        SET status = 'ready'
        WHERE id = ?
    ")->execute([$parentRevisionId]);
    recipeScoreBuildEffectiveProjection($db, $parentRevisionId);
    $db->prepare("
        UPDATE recipe_score_state
        SET active_score_revision_id = ?,
            active_score_projection_revision_id = ?,
            ontology_source_hash = ?,
            ontology_source_lineage_hash = '',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ")->execute([
        $parentRevisionId,
        $parentRevisionId,
        $sourceHash,
    ]);
    ingredientOntologyV3SetPublicationGuard($db, false);

    $db->prepare("
        INSERT INTO inventory (
            product_id, location, quantity, expiry_date,
            expiry_user_set
        )
        VALUES (?, 'frigo', 1, '2030-01-01', 1)
    ")->execute([$eggplantProductId]);
    $fullShadowStarted = hrtime(true);
    $fullShadow = ingredientOntologyV3BuildShadow(
        $db,
        $versionId,
        500
    );
    $fullShadowMs =
        (hrtime(true) - $fullShadowStarted) / 1000000;
    $fullShadowMatchCount = (int)$db->query("
        SELECT COUNT(*)
        FROM ingredient_ontology_shadow_matches
        WHERE score_revision_id = "
            . (int)$fullShadow['revision_id'] . "
          AND inventory_product_id = {$eggplantProductId}
          AND satisfies_required = 1
    ")->fetchColumn();
    $fullShadowAnnexCount = (int)$db->query("
        SELECT COUNT(*)
        FROM ingredient_ontology_recipe_identity_annex
        WHERE ontology_version_id = {$versionId}
          AND status = 'accepted'
          AND entity_id = {$eggplantEntityId}
    ")->fetchColumn();
    $assert(
        !empty($fullShadow['built'])
        && $fullShadowMatchCount === $eggplantRecipeCount
        && $fullShadowAnnexCount === $eggplantRecipeCount
        && (int)$fullShadow['annex_sync']['batch_count']
            === (int)ceil($catalogSize / 500)
        && (int)$fullShadow['annex_sync']['transaction_count']
            === (int)ceil($catalogSize / 500)
        && (int)$fullShadow['annex_sync']['changed_row_count']
            === $ingredientCount
        && (int)$fullShadow['annex_sync']['write_statement_count']
            <= (int)ceil($ingredientCount / 40)
                + (int)ceil($catalogSize / 500),
        'Full copied shadow scoring must materialize reviewed recipe annex identity bilaterally'
    );
    $repeatFullShadowStarted = hrtime(true);
    $repeatFullShadow = ingredientOntologyV3BuildShadow(
        $db,
        $versionId,
        500
    );
    $repeatFullShadowMs =
        (hrtime(true) - $repeatFullShadowStarted) / 1000000;
    $repeatFullShadowMatchCount = (int)$db->query("
        SELECT COUNT(*)
        FROM ingredient_ontology_shadow_matches
        WHERE score_revision_id = "
            . (int)$repeatFullShadow['revision_id'] . "
          AND inventory_product_id = {$eggplantProductId}
          AND satisfies_required = 1
    ")->fetchColumn();
    $assert(
        !empty($repeatFullShadow['built'])
        && $repeatFullShadowMatchCount === $eggplantRecipeCount
        && (int)$repeatFullShadow['annex_sync']['batch_count']
            === (int)ceil($catalogSize / 500)
        && (int)$repeatFullShadow['annex_sync']['transaction_count']
            === (int)ceil($catalogSize / 500)
        && (int)$repeatFullShadow['annex_sync']['changed_row_count']
            === 0
        && (int)$repeatFullShadow['annex_sync']['unchanged_row_count']
            === $ingredientCount
        && (int)$repeatFullShadow['annex_sync'][
            'write_statement_count'
        ] === 0,
        'Repeated full shadow scoring must reuse identical annex rows without corpus-wide writes'
    );
    recipeScoreMarkProductDirty(
        $db,
        $eggplantProductId,
        'eggplant_benchmark'
    );
    $state = recipeScoreState($db);
    $pendingRecipe = $db->prepare("
        INSERT INTO recipe_score_pending_recipes (
            recipe_id, operation, first_catalog_revision,
            latest_catalog_revision,
            latest_ontology_source_revision, reason
        )
        VALUES (
            ?, 'replace', ?, ?, ?, 'eggplant_benchmark'
        )
        ON CONFLICT(recipe_id) DO UPDATE SET
            operation = 'replace',
            latest_catalog_revision =
                excluded.latest_catalog_revision,
            latest_ontology_source_revision =
                excluded.latest_ontology_source_revision,
            reason = excluded.reason,
            updated_at = CURRENT_TIMESTAMP
    ");
    foreach ($eggplantRecipeIds as $recipeId) {
        $pendingRecipe->execute([
            $recipeId,
            (int)$state['catalog_revision'],
            (int)$state['catalog_revision'],
            (int)$state['ontology_source_revision'],
        ]);
    }
    $eggplantLatencies = [];
    $eggplantResults = [];
    for ($run = 0; $run < $runs; $run++) {
        if ($run > 0) {
            $db->prepare("
                UPDATE inventory
                SET quantity = quantity + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE product_id = ?
            ")->execute([$eggplantProductId]);
            recipeScoreMarkProductDirty(
                $db,
                $eggplantProductId,
                'eggplant_benchmark_repeat'
            );
        }
        $result = ingredientOntologyV3IncrementalRebuild(
            $db,
            true,
            500
        );
        $eggplantResults[] = $result;
        $eggplantLatencies[] = (float)($result['visible_ms'] ?? INF);
    }

    recipeScoreMarkProductDirty(
        $db,
        $eggplantProductId,
        'continuous_catalog_before_snapshot'
    );
    $catalogMutationRecipeId = 0;
    $GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_INCREMENTAL_SNAPSHOT'] =
        static function (PDO $hookDb) use (
            $recipeInsert,
            $ingredientInsert,
            &$catalogMutationRecipeId
        ): void {
            $recipeInsert->execute([
                'Continuous catalog Eggplant',
                'en',
            ]);
            $catalogMutationRecipeId = (int)$hookDb->lastInsertId();
            $ingredientInsert->execute([
                $catalogMutationRecipeId,
                'eggplant',
                'eggplant',
            ]);
            recipeScoreMarkRecipeDirty(
                $hookDb,
                $catalogMutationRecipeId,
                'replace',
                'continuous_catalog_during_build'
            );
        };
    $continuousCatalogFirst =
        ingredientOntologyV3IncrementalRebuild(
            $db,
            true,
            500
        );
    unset(
        $GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_AFTER_INCREMENTAL_SNAPSHOT'
        ]
    );
    $continuousCatalogSecond =
        ingredientOntologyV3IncrementalRebuild(
            $db,
            true,
            500
        );
    $assert(
        !empty($continuousCatalogFirst['rebuilt'])
        && $catalogMutationRecipeId > 0
        && (int)$continuousCatalogFirst['pending_recipe_count'] === 1
        && !empty($continuousCatalogSecond['rebuilt'])
        && in_array(
            $catalogMutationRecipeId,
            $continuousCatalogSecond['recipe_ids'],
            true
        )
        && (int)$continuousCatalogSecond['pending_recipe_count'] === 0,
        'A catalog mutation after the sparse snapshot must remain pending and publish in the next child: '
            . ingredientOntologyV3Json([
                'recipe_id' => $catalogMutationRecipeId,
                'first' => $continuousCatalogFirst,
                'second' => $continuousCatalogSecond,
            ])
    );

    recipeScoreMarkProductDirty(
        $db,
        $eggplantProductId,
        'continuous_inventory_before_snapshot'
    );
    $continuousInventoryRevision = 0;
    $GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_INCREMENTAL_SNAPSHOT'] =
        static function (PDO $hookDb) use (
            $eggplantProductId,
            &$continuousInventoryRevision
        ): void {
            $hookDb->prepare("
                UPDATE inventory
                SET quantity = quantity + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE product_id = ?
            ")->execute([$eggplantProductId]);
            $continuousInventoryRevision =
                recipeScoreMarkProductDirty(
                    $hookDb,
                    $eggplantProductId,
                    'continuous_inventory_during_build'
                );
        };
    $continuousInventoryFirst =
        ingredientOntologyV3IncrementalRebuild(
            $db,
            true,
            500
        );
    unset(
        $GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_AFTER_INCREMENTAL_SNAPSHOT'
        ]
    );
    $continuousInventorySecond =
        ingredientOntologyV3IncrementalRebuild(
            $db,
            true,
            500
        );
    $assert(
        !empty($continuousInventoryFirst['rebuilt'])
        && $continuousInventoryRevision
            > (int)$continuousInventoryFirst['inventory_revision']
        && (int)$continuousInventoryFirst['pending_product_count']
            === 1
        && !empty($continuousInventorySecond['rebuilt'])
        && (int)$continuousInventorySecond['inventory_revision']
            === $continuousInventoryRevision
        && (int)$continuousInventorySecond['pending_product_count']
            === 0,
        'An inventory mutation after the sparse snapshot must remain pending and publish in the next child'
    );

    $db->exec("
        INSERT INTO products (name, brand, category)
        VALUES ('Unknown Benchmark Identity', '', '')
    ");
    $unknownProductId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO inventory (
            product_id, location, quantity, expiry_date,
            expiry_user_set
        )
        VALUES (?, 'dispensa', 1, '2030-01-01', 1)
    ")->execute([$unknownProductId]);
    $unknownLatencies = [];
    $unknownResults = [];
    for ($run = 0; $run < $runs; $run++) {
        if ($run > 0) {
            $db->prepare("
                UPDATE inventory
                SET quantity = quantity + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE product_id = ?
            ")->execute([$unknownProductId]);
        }
        recipeScoreMarkProductDirty(
            $db,
            $unknownProductId,
            'unknown_benchmark'
        );
        $result = ingredientOntologyV3IncrementalRebuild(
            $db,
            true,
            500
        );
        $unknownResults[] = $result;
        $unknownLatencies[] = (float)($result['visible_ms'] ?? INF);
    }

    ingredientOntologyV3SchemaMigrate($db);
    recipeSchemaMigrate($db);
    $activeAfterMigration = recipeScoreActiveRevision($db);
    $stateAfterMigration = recipeScoreState($db);
    $fixtureMigrationCatchup = null;
    if (
        (int)$activeAfterMigration['ontology_source_revision']
            < (int)$stateAfterMigration['ontology_source_revision']
    ) {
        recipeScoreMarkRecipeDirty(
            $db,
            $catalogMutationRecipeId,
            'replace',
            'benchmark_fixture_migration_catchup'
        );
        $fixtureMigrationCatchup =
            ingredientOntologyV3IncrementalRebuild(
                $db,
                true,
                500
            );
    }

    $eggplantP95 = $percentile($eggplantLatencies, 0.95);
    $unknownP95 = $percentile($unknownLatencies, 0.95);
    $eggplantReadiness =
        ingredientOntologyV3ProductReadinessRow(
            $db,
            $eggplantProductId
        );
    $eggplantReadinessRevision = is_array($eggplantReadiness)
        && (int)($eggplantReadiness['score_revision_id'] ?? 0) > 0
            ? recipeScoreRevision(
                $db,
                (int)$eggplantReadiness['score_revision_id']
            )
            : null;
    $assert(
        count(array_filter(
            $eggplantResults,
            static fn(array $result): bool =>
                !empty($result['rebuilt'])
                && (int)$result['affected_recipe_count'] === 467
        )) === $runs
        && $eggplantP95 < 2000,
        'Reviewed Eggplant sparse cohort must remain below 2 seconds p95'
    );
    $assert(
        is_array($eggplantReadiness)
        && (string)$eggplantReadiness['status'] === 'ready'
        && (int)$eggplantReadiness['affected_recipe_count']
            >= $eggplantRecipeCount
        && (float)$eggplantReadiness['visible_ms'] > 0
        && (string)($eggplantReadinessRevision['status'] ?? '')
            === 'ready',
        'Sparse publication must atomically mark accepted product readiness: '
            . json_encode([
                'readiness' => $eggplantReadiness,
                'revision' => $eggplantReadinessRevision,
            ], JSON_UNESCAPED_SLASHES)
    );
    $assert(
        count(array_filter(
            $unknownResults,
            static fn(array $result): bool =>
                !empty($result['rebuilt'])
                && (int)$result['affected_recipe_count'] === 0
                && (int)$result['physical_score_rows'] === 0
                && (int)$result['physical_match_rows'] === 0
        )) === $runs
        && $unknownP95 < 50,
        'Unknown identity sparse publication must remain zero-impact below 50 ms p95'
    );

    echo json_encode([
        'success' => true,
        'assertions' => $assertions,
        'catalog_size' => $catalogSize,
        'ingredient_count' => $ingredientCount,
        'eggplant_recipe_count' => $eggplantRecipeCount,
        'runs' => $runs,
        'full_shadow_ms' => round($fullShadowMs, 3),
        'full_shadow_repeat_ms' =>
            round($repeatFullShadowMs, 3),
        'full_shadow_annex' => $fullShadow['annex_sync'],
        'full_shadow_repeat_annex' =>
            $repeatFullShadow['annex_sync'],
        'eggplant_visible_ms' => $eggplantLatencies,
        'eggplant_p95_ms' => round($eggplantP95, 3),
        'unknown_visible_ms' => $unknownLatencies,
        'unknown_p95_ms' => round($unknownP95, 3),
        'continuous_catalog' => [
            'first_revision_id' =>
                (int)$continuousCatalogFirst['revision_id'],
            'second_revision_id' =>
                (int)$continuousCatalogSecond['revision_id'],
            'recipe_id' => $catalogMutationRecipeId,
        ],
        'continuous_inventory' => [
            'first_revision_id' =>
                (int)$continuousInventoryFirst['revision_id'],
            'second_revision_id' =>
                (int)$continuousInventorySecond['revision_id'],
            'inventory_revision' =>
                $continuousInventoryRevision,
        ],
        'fixture_migration_catchup_revision_id' =>
            is_array($fixtureMigrationCatchup)
                ? (int)$fixtureMigrationCatchup['revision_id']
                : null,
        'peak_php_mb' =>
            round(memory_get_peak_usage(true) / 1048576, 2),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if ($keepPath === '') {
        @unlink($path);
        @unlink($path . '-wal');
        @unlink($path . '-shm');
        @unlink(
            dirname($path) . '/.' . basename($path)
                . '.recipe-score.lock'
        );
    }
}
