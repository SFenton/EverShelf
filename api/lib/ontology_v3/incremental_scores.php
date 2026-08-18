<?php
declare(strict_types=1);

const INGREDIENT_ONTOLOGY_V3_INCREMENTAL_MODEL =
    'faceted-ontology-v3';
const INGREDIENT_ONTOLOGY_V3_INCREMENTAL_REPORT_VERSION =
    'identity-annex-incremental-score-v1';
const INGREDIENT_ONTOLOGY_V3_INCREMENTAL_FULL_HASH_INTERVAL = 4;

function ingredientOntologyV3IncrementalCoalesceMilliseconds(): int {
    $value = function_exists('env')
        ? (int)env(
            'RECIPE_SCORE_INCREMENTAL_COALESCE_MS',
            '500'
        )
        : 500;
    return max(0, min(5000, $value));
}

function ingredientOntologyV3IncrementalProductLimit(): int {
    return function_exists('env')
        ? max(1, min(1000, (int)env(
            'RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT',
            '500'
        )))
        : 500;
}

function ingredientOntologyV3IncrementalPendingProducts(
    PDO $db
): array {
    if (!ingredientOntologyV3TableExists(
        $db,
        'recipe_score_pending_products'
    )) {
        return [];
    }
    $limit = ingredientOntologyV3IncrementalProductLimit();
    $rows = $db->query("
        SELECT product_id, first_inventory_revision,
               latest_inventory_revision, reason,
               created_at, updated_at
        FROM recipe_score_pending_products
        ORDER BY updated_at, product_id
        LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn(array $row): array => [
        'product_id' => (int)$row['product_id'],
        'first_inventory_revision' =>
            (int)$row['first_inventory_revision'],
        'latest_inventory_revision' =>
            (int)$row['latest_inventory_revision'],
        'reason' => (string)$row['reason'],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ], $rows);
}

function ingredientOntologyV3IncrementalPendingOverflow(
    PDO $db,
    int $selectedCount
): bool {
    $limit = ingredientOntologyV3IncrementalProductLimit();
    if ($selectedCount < $limit) {
        return false;
    }
    $stmt = $db->query("
        SELECT 1
        FROM recipe_score_pending_products
        ORDER BY updated_at, product_id
        LIMIT 1 OFFSET {$limit}
    ");
    return $stmt->fetchColumn() !== false;
}

function ingredientOntologyV3IncrementalPendingAgeMs(
    array $pending
): int {
    $latest = 0;
    foreach ($pending as $row) {
        $timestamp = strtotime(
            (string)($row['updated_at'] ?? '') . ' UTC'
        );
        $latest = max($latest, $timestamp !== false ? $timestamp : 0);
    }
    if ($latest <= 0) {
        return PHP_INT_MAX;
    }
    return max(0, (int)round((microtime(true) - $latest) * 1000));
}

function ingredientOntologyV3IncrementalChainPolicy(
    mixed $parentReport
): array {
    $parentUsesDelta = is_array($parentReport)
        && (string)(
            $parentReport['materialized_hash_algorithm'] ?? ''
        ) === 'parent-delta-v1';
    $parentDepth = $parentUsesDelta
        ? (int)(
            $parentReport['incremental_chain_depth']
                ?? INGREDIENT_ONTOLOGY_V3_INCREMENTAL_FULL_HASH_INTERVAL
        )
        : 0;
    $depth = $parentDepth + 1;
    return [
        'depth' => $depth,
        'compact' => $depth
            >= INGREDIENT_ONTOLOGY_V3_INCREMENTAL_FULL_HASH_INTERVAL,
    ];
}

function ingredientOntologyV3IncrementalParentErrors(
    PDO $db,
    array $parent,
    array $state
): array {
    $errors = [];
    $versionId = (int)($parent['ontology_version_id'] ?? 0);
    $version = $versionId > 0
        ? ingredientOntologyV3Version($db, $versionId)
        : null;
    if (
        (string)($parent['status'] ?? '') !== 'ready'
        || (string)($parent['scoring_model'] ?? '')
            !== INGREDIENT_ONTOLOGY_V3_INCREMENTAL_MODEL
        || $version === null
        || (string)$version['status'] !== 'ready'
    ) {
        $errors[] = 'active_v3_parent_unavailable';
        return $errors;
    }
    if ((string)$parent['score_date'] !== recipeScoreCurrentDate()) {
        $errors[] = 'parent_score_date_stale';
    }
    if ($parent['requirement_revision_id'] !== null) {
        $errors[] = 'requirement_projection_not_supported';
    }
    if (
        (int)$parent['catalog_revision']
            !== (int)$state['catalog_revision']
    ) {
        $errors[] = 'catalog_revision_changed';
    }
    if (
        !hash_equals(
            (string)($parent['scoring_config_hash'] ?? ''),
            ingredientOntologyV3ScoringConfigHash()
        )
    ) {
        $errors[] = 'scoring_configuration_changed';
    }
    foreach ([
        'ontology_schema_hash' => 'schema_hash',
        'ontology_prompt_hash' => 'prompt_hash',
        'ontology_model_hash' => 'model_hash',
        'ontology_corpus_hash' => 'corpus_hash',
        'ontology_content_hash' => 'content_hash',
        'ontology_portable_content_hash' =>
            'portable_content_hash',
        'ontology_review_manifest_hash' =>
            'review_manifest_hash',
        'ontology_resolution_gold_hash' =>
            'resolution_gold_hash',
        'ontology_seal_hash' => 'seal_hash',
    ] as $revisionColumn => $versionColumn) {
        if (
            !is_string($parent[$revisionColumn] ?? null)
            || !is_string($version[$versionColumn] ?? null)
            || !hash_equals(
                (string)$parent[$revisionColumn],
                (string)$version[$versionColumn]
            )
        ) {
            $errors[] = $revisionColumn . '_changed';
        }
    }
    if (
        (int)($parent['recipe_count'] ?? 0) <= 0
        || !is_string($parent['catalog_id_set_hash'] ?? null)
        || !is_string($parent['ingredient_id_set_hash'] ?? null)
    ) {
        $errors[] = 'parent_materialization_metadata_missing';
    }
    return $errors;
}

function ingredientOntologyV3IncrementalRelatedEntityIds(
    IngredientOntologyV3MatcherContext $context,
    array $inventoryEntityIds
): array {
    $inventoryEntityIds = array_values(array_unique(array_filter(
        array_map('intval', $inventoryEntityIds),
        static fn(int $id): bool => $id > 0
    )));
    $related = [];
    foreach (array_keys($context->entities) as $requiredEntityId) {
        $requiredEntityId = (int)$requiredEntityId;
        foreach ($inventoryEntityIds as $inventoryEntityId) {
            if (
                $inventoryEntityId === $requiredEntityId
                || isset(
                    $context->ancestry[
                        $inventoryEntityId
                    ][$requiredEntityId]
                )
                || isset(
                    $context->ancestry[
                        $requiredEntityId
                    ][$inventoryEntityId]
                )
                || isset(
                    $context->relations[
                        $requiredEntityId
                    ][$inventoryEntityId]
                )
                || isset(
                    $context->relations[
                        $inventoryEntityId
                    ][$requiredEntityId]
                )
            ) {
                $related[$requiredEntityId] = true;
                break;
            }
        }
    }
    $ids = array_map('intval', array_keys($related));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function ingredientOntologyV3IncrementalPreviousRecipeIds(
    PDO $db,
    int $parentRevisionId,
    array $productIds
): array {
    $productIds = array_values(array_unique(array_filter(
        array_map('intval', $productIds),
        static fn(int $id): bool => $id > 0
    )));
    if (!$productIds) {
        return [];
    }
    $recipeIds = [];
    $placeholders = implode(
        ',',
        array_fill(0, count($productIds), '?')
    );
    $selected = $db->prepare("
        SELECT DISTINCT ingredient.recipe_id
        FROM ingredient_ontology_shadow_matches match
        JOIN recipe_ingredients ingredient
          ON ingredient.id = match.recipe_ingredient_id
        WHERE match.score_revision_id = ?
          AND match.inventory_product_id IN ({$placeholders})
    ");
    $selected->execute(array_merge(
        [$parentRevisionId],
        $productIds
    ));
    foreach ($selected->fetchAll(PDO::FETCH_COLUMN) as $recipeId) {
        $recipeIds[(int)$recipeId] = true;
    }

    $contributors = $db->prepare("
        SELECT DISTINCT ingredient.recipe_id
        FROM ingredient_ontology_shadow_matches match
        JOIN recipe_ingredients ingredient
          ON ingredient.id = match.recipe_ingredient_id
        JOIN json_each(
            CASE
                WHEN json_valid(match.explanation_json)
                THEN match.explanation_json
                ELSE '{}'
            END,
            '$.inventory_aggregate.product_ids'
        ) contributor
        WHERE match.score_revision_id = ?
          AND contributor.type = 'integer'
          AND CAST(contributor.value AS INTEGER)
              IN ({$placeholders})
    ");
    $contributors->execute(array_merge(
        [$parentRevisionId],
        $productIds
    ));
    foreach ($contributors->fetchAll(PDO::FETCH_COLUMN) as $recipeId) {
        $recipeIds[(int)$recipeId] = true;
    }

    $ids = array_map('intval', array_keys($recipeIds));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function ingredientOntologyV3IncrementalAffectedRecipeIds(
    PDO $db,
    int $versionId,
    int $parentRevisionId,
    array $productIds,
    array $inventory,
    IngredientOntologyV3MatcherContext $context,
    array $additionalEntityIds = []
): array {
    $productIds = array_values(array_unique(array_filter(
        array_map('intval', $productIds),
        static fn(int $id): bool => $id > 0
    )));
    if (!$productIds) {
        return [];
    }
    $recipeIds = array_fill_keys(
        ingredientOntologyV3IncrementalPreviousRecipeIds(
            $db,
            $parentRevisionId,
            $productIds
        ),
        true
    );
    $productPlaceholders = implode(
        ',',
        array_fill(0, count($productIds), '?')
    );

    $inventoryEntityIds = array_values(array_unique(array_filter(
        array_map('intval', $additionalEntityIds),
        static fn(int $id): bool => $id > 0
    )));
    foreach ($productIds as $productId) {
        $mapping = $inventory['by_product'][$productId] ?? null;
        if (
            is_array($mapping)
            && (string)($mapping['status'] ?? '') === 'accepted'
            && (int)($mapping['entity_id'] ?? 0) > 0
        ) {
            $inventoryEntityIds[] = (int)$mapping['entity_id'];
        }
    }
    // Stale sealed mappings are conservative invalidation hints only; they
    // never become current inventory satisfiers without a fingerprint match.
    $sealedMappings = $db->prepare("
        SELECT DISTINCT entity_id
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
          AND owner_type = 'product'
          AND owner_id IN ({$productPlaceholders})
          AND status = 'accepted'
          AND entity_id IS NOT NULL
    ");
    $sealedMappings->execute(array_merge(
        [$versionId],
        $productIds
    ));
    foreach ($sealedMappings->fetchAll(PDO::FETCH_COLUMN) as $entityId) {
        $inventoryEntityIds[] = (int)$entityId;
    }
    $relatedEntityIds =
        ingredientOntologyV3IncrementalRelatedEntityIds(
            $context,
            $inventoryEntityIds
        );
    if ($relatedEntityIds) {
        $entityPlaceholders = implode(
            ',',
            array_fill(0, count($relatedEntityIds), '?')
        );
        $current = $db->prepare("
            SELECT DISTINCT ingredient.recipe_id
            FROM ingredient_ontology_mappings mapping
            JOIN recipe_ingredients ingredient
              ON ingredient.id = mapping.owner_id
            JOIN recipe_catalog recipe
              ON recipe.id = ingredient.recipe_id
             AND recipe.deleted_at IS NULL
            WHERE mapping.ontology_version_id = ?
              AND mapping.owner_type = 'recipe_ingredient'
              AND mapping.status = 'accepted'
              AND mapping.entity_id IN ({$entityPlaceholders})
        ");
        $current->execute(array_merge(
            [$versionId],
            $relatedEntityIds
        ));
        foreach ($current->fetchAll(PDO::FETCH_COLUMN) as $recipeId) {
            $recipeIds[(int)$recipeId] = true;
        }
    }
    $ids = array_map('intval', array_keys($recipeIds));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function ingredientOntologyV3IncrementalInsertRevision(
    PDO $db,
    array $parent,
    array $state,
    string $inventoryFingerprint,
    string $ontologySourceHash
): int {
    $insert = $db->prepare("
        INSERT INTO recipe_score_revisions (
            inventory_revision, catalog_revision,
            inventory_fingerprint, score_date, catalog_max_id,
            status, ontology_version_id, scoring_model,
            scoring_config_hash, parent_score_revision_id,
            catalog_fingerprint, ontology_schema_hash,
            ontology_prompt_hash, ontology_model_hash,
            ontology_corpus_hash, ontology_content_hash,
            ontology_portable_content_hash,
            ontology_review_manifest_hash,
            ontology_resolution_gold_hash, ontology_seal_hash,
            ontology_source_revision, ontology_source_hash,
            requirement_revision_id, requirement_model,
            parity_baseline_score_revision_id,
            catalog_id_set_hash, ingredient_id_set_hash,
            requirement_recipe_id_set_hash,
            requirement_id_set_hash
        )
        VALUES (
            ?, ?, ?, ?, ?, 'building', ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, NULL, NULL
        )
    ");
    $insert->execute([
        (int)$state['inventory_revision'],
        (int)$state['catalog_revision'],
        $inventoryFingerprint,
        (string)$parent['score_date'],
        (int)$parent['catalog_max_id'],
        (int)$parent['ontology_version_id'],
        INGREDIENT_ONTOLOGY_V3_INCREMENTAL_MODEL,
        (string)$parent['scoring_config_hash'],
        (int)$parent['id'],
        (string)$parent['catalog_fingerprint'],
        (string)$parent['ontology_schema_hash'],
        (string)$parent['ontology_prompt_hash'],
        (string)$parent['ontology_model_hash'],
        (string)$parent['ontology_corpus_hash'],
        (string)$parent['ontology_content_hash'],
        (string)$parent['ontology_portable_content_hash'],
        (string)$parent['ontology_review_manifest_hash'],
        (string)$parent['ontology_resolution_gold_hash'],
        (string)$parent['ontology_seal_hash'],
        (int)$state['ontology_source_revision'],
        $ontologySourceHash,
        (string)$parent['catalog_id_set_hash'],
        (string)$parent['ingredient_id_set_hash'],
    ]);
    return (int)$db->lastInsertId();
}

function ingredientOntologyV3IncrementalCopyParentRows(
    PDO $db,
    int $parentRevisionId,
    int $revisionId
): array {
    $scoreBatchSize = max(
        100,
        min(
            10000,
            (int)(function_exists('env')
                ? env('RECIPE_SCORE_INCREMENTAL_SCORE_COPY_BATCH', '2000')
                : 2000)
        )
    );
    $matchBatchSize = max(
        500,
        min(
            50000,
            (int)(function_exists('env')
                ? env('RECIPE_SCORE_INCREMENTAL_MATCH_COPY_BATCH', '10000')
                : 10000)
        )
    );
    $scoreRows = 0;
    $matchRows = 0;
    $lastRecipeId = 0;
    while (true) {
        $ids = $db->prepare("
            SELECT recipe_id
            FROM recipe_inventory_scores
            WHERE score_revision_id = ? AND recipe_id > ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM recipe_score_incremental_recipes affected
                  WHERE affected.score_revision_id = ?
                    AND affected.recipe_id =
                        recipe_inventory_scores.recipe_id
              )
            ORDER BY recipe_id
            LIMIT {$scoreBatchSize}
        ");
        $ids->execute([
            $parentRevisionId,
            $lastRecipeId,
            $revisionId,
        ]);
        $batchIds = array_map(
            'intval',
            $ids->fetchAll(PDO::FETCH_COLUMN)
        );
        if (!$batchIds) {
            break;
        }
        $batchMax = max($batchIds);
        recipeScoreWithWriteRetry(static function () use (
            $db,
            $parentRevisionId,
            $revisionId,
            $lastRecipeId,
            $batchMax
        ): void {
            $db->beginTransaction();
            try {
                $copy = $db->prepare("
                    INSERT INTO recipe_inventory_scores (
                        score_revision_id, recipe_id, coverage,
                        directness, expiry_score, source_user_score,
                        availability_score, required_count,
                        matched_required_count, missing_required_count,
                        uncertain_required_count, cookable,
                        soonest_expiry_days, created_at, updated_at
                    )
                    SELECT ?, recipe_id, coverage, directness,
                           expiry_score, source_user_score,
                           availability_score, required_count,
                           matched_required_count,
                           missing_required_count,
                           uncertain_required_count, cookable,
                           soonest_expiry_days,
                           CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    FROM recipe_inventory_scores
                    WHERE score_revision_id = ?
                      AND recipe_id > ? AND recipe_id <= ?
                      AND NOT EXISTS (
                          SELECT 1
                          FROM recipe_score_incremental_recipes affected
                          WHERE affected.score_revision_id = ?
                            AND affected.recipe_id =
                                recipe_inventory_scores.recipe_id
                      )
                    ORDER BY recipe_id
                ");
                $copy->execute([
                    $revisionId,
                    $parentRevisionId,
                    $lastRecipeId,
                    $batchMax,
                    $revisionId,
                ]);
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }
        });
        $scoreRows += count($batchIds);
        $lastRecipeId = $batchMax;
    }

    $lastIngredientId = 0;
    while (true) {
        $ids = $db->prepare("
            SELECT match.recipe_ingredient_id
            FROM ingredient_ontology_shadow_matches match
            JOIN recipe_ingredients ingredient
              ON ingredient.id = match.recipe_ingredient_id
            WHERE match.score_revision_id = ?
              AND match.recipe_ingredient_id > ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM recipe_score_incremental_recipes affected
                  WHERE affected.score_revision_id = ?
                    AND affected.recipe_id = ingredient.recipe_id
              )
            ORDER BY match.recipe_ingredient_id
            LIMIT {$matchBatchSize}
        ");
        $ids->execute([
            $parentRevisionId,
            $lastIngredientId,
            $revisionId,
        ]);
        $batchIds = array_map(
            'intval',
            $ids->fetchAll(PDO::FETCH_COLUMN)
        );
        if (!$batchIds) {
            break;
        }
        $batchMax = max($batchIds);
        recipeScoreWithWriteRetry(static function () use (
            $db,
            $parentRevisionId,
            $revisionId,
            $lastIngredientId,
            $batchMax
        ): void {
            $db->beginTransaction();
            try {
                $copy = $db->prepare("
                    INSERT INTO ingredient_ontology_shadow_matches (
                        score_revision_id, recipe_ingredient_id,
                        recipe_mapping_id, inventory_product_id,
                        inventory_mapping_id, outcome,
                        satisfies_required, confidence, relationship,
                        explanation_json, created_at
                    )
                    SELECT ?, recipe_ingredient_id,
                           recipe_mapping_id, inventory_product_id,
                           inventory_mapping_id, outcome,
                           satisfies_required, confidence, relationship,
                           explanation_json, CURRENT_TIMESTAMP
                    FROM ingredient_ontology_shadow_matches match
                    JOIN recipe_ingredients ingredient
                      ON ingredient.id = match.recipe_ingredient_id
                    WHERE match.score_revision_id = ?
                      AND match.recipe_ingredient_id > ?
                      AND match.recipe_ingredient_id <= ?
                      AND NOT EXISTS (
                          SELECT 1
                          FROM recipe_score_incremental_recipes affected
                          WHERE affected.score_revision_id = ?
                            AND affected.recipe_id =
                                ingredient.recipe_id
                      )
                    ORDER BY match.recipe_ingredient_id
                ");
                $copy->execute([
                    $revisionId,
                    $parentRevisionId,
                    $lastIngredientId,
                    $batchMax,
                    $revisionId,
                ]);
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }
        });
        $matchRows += count($batchIds);
        $lastIngredientId = $batchMax;
    }
    return [
        'score_rows' => $scoreRows,
        'match_rows' => $matchRows,
    ];
}

function ingredientOntologyV3IncrementalRecordAffectedRecipes(
    PDO $db,
    int $revisionId,
    array $recipeIds
): void {
    if (!$recipeIds) {
        return;
    }
    recipeScoreWithWriteRetry(static function () use (
        $db,
        $revisionId,
        $recipeIds
    ): void {
        $db->beginTransaction();
        try {
            foreach (array_chunk($recipeIds, 250) as $chunk) {
                $values = implode(
                    ',',
                    array_fill(0, count($chunk), '(?, ?)')
                );
                $params = [];
                foreach ($chunk as $recipeId) {
                    $params[] = $revisionId;
                    $params[] = (int)$recipeId;
                }
                $db->prepare("
                    INSERT INTO recipe_score_incremental_recipes (
                        score_revision_id, recipe_id
                    )
                    VALUES {$values}
                ")->execute($params);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    });
}

function ingredientOntologyV3IncrementalValueHashes(
    PDO $db,
    int $revisionId,
    array $parent
): array {
    $affectedIdSetHash = ingredientOntologyV3OrderedIdSetHash(
        $db,
        "SELECT recipe_id
         FROM recipe_score_incremental_recipes
         WHERE score_revision_id = ?
         ORDER BY recipe_id",
        [$revisionId]
    );
    $scores = ingredientOntologyV3HashMaterializedRows(
        $db,
        "SELECT score.recipe_id, score.coverage,
                score.directness, score.expiry_score,
                score.source_user_score, score.availability_score,
                score.required_count,
                score.matched_required_count,
                score.missing_required_count,
                score.uncertain_required_count,
                score.cookable, score.soonest_expiry_days
         FROM recipe_inventory_scores score
         JOIN recipe_score_incremental_recipes affected
           ON affected.score_revision_id = score.score_revision_id
          AND affected.recipe_id = score.recipe_id
         WHERE score.score_revision_id = ?
         ORDER BY score.recipe_id",
        [$revisionId],
        static fn(array $row): array => [
            'recipe_id' => (int)$row['recipe_id'],
            'coverage' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['coverage']
                ),
            'directness' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['directness']
                ),
            'expiry_score' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['expiry_score']
                ),
            'source_user_score' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['source_user_score']
                ),
            'availability_score' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['availability_score']
                ),
            'required_count' => (int)$row['required_count'],
            'matched_required_count' =>
                (int)$row['matched_required_count'],
            'missing_required_count' =>
                (int)$row['missing_required_count'],
            'uncertain_required_count' =>
                (int)$row['uncertain_required_count'],
            'cookable' => (int)$row['cookable'],
            'soonest_expiry_days' =>
                $row['soonest_expiry_days'] !== null
                    ? (int)$row['soonest_expiry_days']
                    : null,
        ]
    );
    $matches = ingredientOntologyV3HashMaterializedRows(
        $db,
        "SELECT match.recipe_ingredient_id,
                match.recipe_mapping_id,
                match.inventory_product_id,
                match.inventory_mapping_id,
                match.outcome, match.satisfies_required,
                match.confidence, match.relationship,
                match.explanation_json
         FROM ingredient_ontology_shadow_matches match
         JOIN recipe_ingredients ingredient
           ON ingredient.id = match.recipe_ingredient_id
         JOIN recipe_score_incremental_recipes affected
           ON affected.score_revision_id = match.score_revision_id
          AND affected.recipe_id = ingredient.recipe_id
         WHERE match.score_revision_id = ?
         ORDER BY match.recipe_ingredient_id",
        [$revisionId],
        static fn(array $row): array => [
            'recipe_ingredient_id' =>
                (int)$row['recipe_ingredient_id'],
            'recipe_mapping_id' =>
                $row['recipe_mapping_id'] !== null
                    ? (int)$row['recipe_mapping_id']
                    : null,
            'inventory_product_id' =>
                $row['inventory_product_id'] !== null
                    ? (int)$row['inventory_product_id']
                    : null,
            'inventory_mapping_id' =>
                $row['inventory_mapping_id'] !== null
                    ? (int)$row['inventory_mapping_id']
                    : null,
            'outcome' => (string)$row['outcome'],
            'satisfies_required' =>
                (int)$row['satisfies_required'],
            'confidence' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['confidence']
                ),
            'relationship' => (string)$row['relationship'],
            'explanation' =>
                ingredientOntologyV3MaterializationJson(
                    (string)$row['explanation_json']
                ),
        ]
    );
    $scoreRowsHash = ingredientOntologyV3Hash([
        'algorithm' => 'parent-delta-v1',
        'parent_score_rows_hash' =>
            (string)$parent['score_rows_hash'],
        'affected_recipe_id_set_hash' => $affectedIdSetHash,
        'changed_score_rows_hash' => $scores['hash'],
        'changed_score_row_count' => $scores['count'],
    ]);
    $matchRowsHash = ingredientOntologyV3Hash([
        'algorithm' => 'parent-delta-v1',
        'parent_match_rows_hash' =>
            (string)$parent['match_rows_hash'],
        'affected_recipe_id_set_hash' => $affectedIdSetHash,
        'changed_match_rows_hash' => $matches['hash'],
        'changed_match_row_count' => $matches['count'],
    ]);
    return [
        'algorithm' => 'parent-delta-v1',
        'affected_recipe_id_set_hash' => $affectedIdSetHash,
        'affected_recipe_count' => $scores['count'],
        'changed_score_rows_hash' => $scores['hash'],
        'changed_score_row_count' => $scores['count'],
        'changed_match_rows_hash' => $matches['hash'],
        'changed_match_row_count' => $matches['count'],
        'score_rows_hash' => $scoreRowsHash,
        'match_rows_hash' => $matchRowsHash,
        'materialization_hash' => ingredientOntologyV3Hash([
            'algorithm' => 'parent-delta-v1',
            'parent_revision_id' => (int)$parent['id'],
            'score_rows_hash' => $scoreRowsHash,
            'match_rows_hash' => $matchRowsHash,
            'affected_recipe_id_set_hash' => $affectedIdSetHash,
        ]),
    ];
}

function ingredientOntologyV3IncrementalOverlayScoreHash(
    PDO $db,
    int $revisionId
): array {
    $affectedIdSetHash = ingredientOntologyV3OrderedIdSetHash(
        $db,
        "SELECT recipe_id
         FROM recipe_score_incremental_recipes
         WHERE score_revision_id = ?
         ORDER BY recipe_id",
        [$revisionId]
    );
    $scores = ingredientOntologyV3HashMaterializedRows(
        $db,
        "SELECT score.recipe_id, score.coverage,
                score.directness, score.expiry_score,
                score.source_user_score, score.availability_score,
                score.required_count,
                score.matched_required_count,
                score.missing_required_count,
                score.uncertain_required_count,
                score.cookable, score.soonest_expiry_days
         FROM recipe_inventory_scores score
         JOIN recipe_score_incremental_recipes affected
           ON affected.score_revision_id = score.score_revision_id
          AND affected.recipe_id = score.recipe_id
         WHERE score.score_revision_id = ?
         ORDER BY score.recipe_id",
        [$revisionId],
        static fn(array $row): array => [
            'recipe_id' => (int)$row['recipe_id'],
            'coverage' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['coverage']
                ),
            'directness' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['directness']
                ),
            'expiry_score' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['expiry_score']
                ),
            'source_user_score' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['source_user_score']
                ),
            'availability_score' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['availability_score']
                ),
            'required_count' => (int)$row['required_count'],
            'matched_required_count' =>
                (int)$row['matched_required_count'],
            'missing_required_count' =>
                (int)$row['missing_required_count'],
            'uncertain_required_count' =>
                (int)$row['uncertain_required_count'],
            'cookable' => (int)$row['cookable'],
            'soonest_expiry_days' =>
                $row['soonest_expiry_days'] !== null
                    ? (int)$row['soonest_expiry_days']
                    : null,
        ]
    );
    return [
        'affected_recipe_id_set_hash' => $affectedIdSetHash,
        'score_rows_hash' => $scores['hash'],
        'score_row_count' => $scores['count'],
        'overlay_hash' => ingredientOntologyV3Hash([
            'algorithm' => 'overlay-score-v1',
            'affected_recipe_id_set_hash' => $affectedIdSetHash,
            'score_rows_hash' => $scores['hash'],
            'score_row_count' => $scores['count'],
        ]),
    ];
}

function ingredientOntologyV3IncrementalCopyMismatchCount(
    PDO $db,
    int $revisionId,
    int $parentRevisionId
): array {
    $score = $db->prepare("
        SELECT COUNT(*)
        FROM (
            SELECT recipe_id, coverage, directness, expiry_score,
                   source_user_score, availability_score,
                   required_count, matched_required_count,
                   missing_required_count, uncertain_required_count,
                   cookable, soonest_expiry_days
            FROM recipe_inventory_scores child
            WHERE child.score_revision_id = ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM recipe_score_incremental_recipes affected
                  WHERE affected.score_revision_id = ?
                    AND affected.recipe_id = child.recipe_id
              )
            EXCEPT
            SELECT recipe_id, coverage, directness, expiry_score,
                   source_user_score, availability_score,
                   required_count, matched_required_count,
                   missing_required_count, uncertain_required_count,
                   cookable, soonest_expiry_days
            FROM recipe_inventory_scores parent
            WHERE parent.score_revision_id = ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM recipe_score_incremental_recipes affected
                  WHERE affected.score_revision_id = ?
                    AND affected.recipe_id = parent.recipe_id
              )
        )
    ");
    $score->execute([
        $revisionId,
        $revisionId,
        $parentRevisionId,
        $revisionId,
    ]);
    $match = $db->prepare("
        SELECT COUNT(*)
        FROM (
            SELECT child.recipe_ingredient_id,
                   child.recipe_mapping_id,
                   child.inventory_product_id,
                   child.inventory_mapping_id,
                   child.outcome, child.satisfies_required,
                   child.confidence, child.relationship,
                   child.explanation_json
            FROM ingredient_ontology_shadow_matches child
            JOIN recipe_ingredients ingredient
              ON ingredient.id = child.recipe_ingredient_id
            WHERE child.score_revision_id = ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM recipe_score_incremental_recipes affected
                  WHERE affected.score_revision_id = ?
                    AND affected.recipe_id = ingredient.recipe_id
              )
            EXCEPT
            SELECT parent.recipe_ingredient_id,
                   parent.recipe_mapping_id,
                   parent.inventory_product_id,
                   parent.inventory_mapping_id,
                   parent.outcome, parent.satisfies_required,
                   parent.confidence, parent.relationship,
                   parent.explanation_json
            FROM ingredient_ontology_shadow_matches parent
            JOIN recipe_ingredients ingredient
              ON ingredient.id = parent.recipe_ingredient_id
            WHERE parent.score_revision_id = ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM recipe_score_incremental_recipes affected
                  WHERE affected.score_revision_id = ?
                    AND affected.recipe_id = ingredient.recipe_id
              )
        )
    ");
    $match->execute([
        $revisionId,
        $revisionId,
        $parentRevisionId,
        $revisionId,
    ]);
    return [
        'score' => (int)$score->fetchColumn(),
        'match' => (int)$match->fetchColumn(),
    ];
}

function ingredientOntologyV3IncrementalValueAudit(
    PDO $db,
    array $revision
): array {
    $parentId = (int)($revision['parent_score_revision_id'] ?? 0);
    $parent = recipeScoreRevision($db, $parentId);
    if ($parent === null || $parent['status'] !== 'ready') {
        return [
            'valid' => false,
            'reason' => 'incremental_parent_unavailable',
        ];
    }
    $current = ingredientOntologyV3IncrementalValueHashes(
        $db,
        (int)$revision['id'],
        $parent
    );
    $hashMatches = [];
    foreach ([
        'score_rows_hash',
        'match_rows_hash',
        'materialization_hash',
    ] as $column) {
        $hashMatches[$column] =
            is_string($revision[$column] ?? null)
            && hash_equals(
                (string)$revision[$column],
                (string)$current[$column]
            );
    }
    $report = json_decode(
        (string)($revision['validation_report_json'] ?? '{}'),
        true
    );
    $expectedMatchCount = is_array($report)
        ? (int)($report['ingredient_match_count'] ?? -1)
        : -1;
    $scoreCount = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_inventory_scores
        WHERE score_revision_id = ?
    ");
    $scoreCount->execute([(int)$revision['id']]);
    $matchCount = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_shadow_matches
        WHERE score_revision_id = ?
    ");
    $matchCount->execute([(int)$revision['id']]);
    $copyMismatches =
        ingredientOntologyV3IncrementalCopyMismatchCount(
            $db,
            (int)$revision['id'],
            $parentId
        );
    $parentAudit = ingredientOntologyV3MaterializedValueAudit(
        $db,
        $parent
    );
    return [
        'valid' => !in_array(false, $hashMatches, true)
            && (int)$scoreCount->fetchColumn()
                === (int)$revision['recipe_count']
            && $expectedMatchCount >= 0
            && (int)$matchCount->fetchColumn() === $expectedMatchCount
            && $copyMismatches['score'] === 0
            && $copyMismatches['match'] === 0
            && !empty($parentAudit['valid']),
        'algorithm' => 'parent-delta-v1',
        'current' => $current,
        'hash_matches' => $hashMatches,
        'copy_mismatch_count' => $copyMismatches,
        'parent' => [
            'revision_id' => $parentId,
            'valid' => !empty($parentAudit['valid']),
        ],
    ];
}

function ingredientOntologyV3IncrementalRebuild(
    PDO $db,
    bool $force = false,
    int $batchSize = 100
): array {
    $started = hrtime(true);
    $pending = ingredientOntologyV3IncrementalPendingProducts($db);
    if (!$pending) {
        return ['rebuilt' => false, 'reason' => 'no_pending_products'];
    }
    $ageMs = ingredientOntologyV3IncrementalPendingAgeMs($pending);
    $coalesceMs = ingredientOntologyV3IncrementalCoalesceMilliseconds();
    if (!$force && $ageMs < $coalesceMs) {
        return [
            'rebuilt' => false,
            'reason' => 'coalescing',
            'retry_after_ms' => $coalesceMs - $ageMs,
        ];
    }
    $lock = recipeScoreAcquireLock($db);
    if ($lock === false) {
        return ['rebuilt' => false, 'reason' => 'locked'];
    }
    $revisionId = 0;
    try {
        $pending = ingredientOntologyV3IncrementalPendingProducts($db);
        if (!$pending) {
            return [
                'rebuilt' => false,
                'reason' => 'no_pending_products',
            ];
        }
        if (
            ingredientOntologyV3IncrementalPendingOverflow(
                $db,
                count($pending)
            )
        ) {
            $limit = ingredientOntologyV3IncrementalProductLimit();
            return [
                'rebuilt' => false,
                'reason' => 'full_rebuild_required',
                'errors' => [
                    'incremental pending product limit exceeded',
                ],
                'pending_product_count_at_least' => $limit + 1,
            ];
        }
        $state = recipeScoreState($db);
        $staleOverlayId = (int)(
            $state['active_score_overlay_revision_id'] ?? 0
        );
        if ($staleOverlayId > 0) {
            $db->beginTransaction();
            try {
                $db->prepare("
                    UPDATE recipe_score_state
                    SET active_score_overlay_revision_id = NULL,
                        cursor_revision = cursor_revision + 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = 1
                      AND active_score_overlay_revision_id = ?
                ")->execute([$staleOverlayId]);
                $db->prepare("
                    UPDATE recipe_score_revisions
                    SET status = 'failed',
                        last_error =
                            'stale incremental overlay recovered',
                        completed_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND status = 'building'
                ")->execute([$staleOverlayId]);
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }
            $state = recipeScoreState($db);
        }
        $parent = recipeScoreActiveRevision($db);
        if ($parent === null) {
            return [
                'rebuilt' => false,
                'reason' => 'active_revision_missing',
            ];
        }
        $parentErrors = ingredientOntologyV3IncrementalParentErrors(
            $db,
            $parent,
            $state
        );
        if ($parentErrors) {
            return [
                'rebuilt' => false,
                'reason' => 'full_rebuild_required',
                'errors' => $parentErrors,
            ];
        }
        $versionId = (int)$parent['ontology_version_id'];
        $productIds = array_map(
            static fn(array $row): int => (int)$row['product_id'],
            $pending
        );
        $identityEntityIds = [];
        foreach ($productIds as $productId) {
            $productExists = $db->prepare("
                SELECT 1 FROM products WHERE id = ?
            ");
            $productExists->execute([$productId]);
            if ($productExists->fetchColumn()) {
                $identity = ingredientOntologyV3IdentityAnnexRefreshProduct(
                    $db,
                    $productId,
                    $versionId
                );
                foreach (['entity_id', 'previous_entity_id'] as $key) {
                    if ((int)($identity[$key] ?? 0) > 0) {
                        $identityEntityIds[] = (int)$identity[$key];
                    }
                }
            }
        }
        $scoreDate = (string)$parent['score_date'];
        $inventory = ingredientOntologyV3Inventory(
            $db,
            $versionId,
            $scoreDate
        );
        $inventoryFingerprint =
            ingredientOntologyV3InventoryFingerprint(
                $inventory,
                $versionId
            );
        $ontologySourceHash = strlen(
            (string)$state['ontology_source_hash']
        ) === 64
            ? (string)$state['ontology_source_hash']
            : ingredientOntologyV3CorpusHash($db);
        $context = new IngredientOntologyV3MatcherContext(
            $db,
            $versionId
        );
        $affectedRecipeIds =
            ingredientOntologyV3IncrementalAffectedRecipeIds(
                $db,
                $versionId,
                (int)$parent['id'],
                $productIds,
                $inventory,
                $context,
                $identityEntityIds
            );
        $revisionId =
            ingredientOntologyV3IncrementalInsertRevision(
                $db,
                $parent,
                $state,
                $inventoryFingerprint,
                $ontologySourceHash
            );
        $prepareMs = (hrtime(true) - $started) / 1000000;
        ingredientOntologyV3IncrementalRecordAffectedRecipes(
            $db,
            $revisionId,
            $affectedRecipeIds
        );
        $copied = ['score_rows' => 0, 'match_rows' => 0];
        $copyMs = 0.0;

        $batchSize = max(1, min(500, $batchSize));
        $candidateCache = [];
        $recomputed = 0;
        $scoreStarted = hrtime(true);
        foreach (
            array_chunk($affectedRecipeIds, $batchSize)
            as $recipeIds
        ) {
            $recipes = ingredientOntologyV3LoadRecipeBatch(
                $db,
                $versionId,
                $recipeIds
            );
            $scores = [];
            $matches = [];
            foreach ($recipes as $recipe) {
                $result = ingredientOntologyV3ScoreRecipe(
                    $context,
                    $recipe,
                    $inventory,
                    $candidateCache
                );
                $scores[] = $result['score'];
                array_push($matches, ...$result['matches']);
            }
            recipeScoreWithWriteRetry(static function () use (
                $db,
                $revisionId,
                $scores,
                $matches
            ): void {
                $db->beginTransaction();
                try {
                    ingredientOntologyV3WriteScoreRows(
                        $db,
                        $revisionId,
                        $scores,
                        $matches
                    );
                    $db->commit();
                } catch (Throwable $error) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $error;
                }
            });
            $recomputed += count($scores);
        }
        $scoreMs = (hrtime(true) - $scoreStarted) / 1000000;

        $currentState = recipeScoreState($db);
        if (
            (int)$currentState['active_score_revision_id']
                !== (int)$parent['id']
            || (int)$currentState['inventory_revision']
                !== (int)$state['inventory_revision']
            || (int)$currentState['catalog_revision']
                !== (int)$state['catalog_revision']
            || (int)$currentState['ontology_source_revision']
                !== (int)$state['ontology_source_revision']
        ) {
            throw new RuntimeException(
                'incremental score inputs changed during build'
            );
        }
        $scoreCountStmt = $db->prepare("
            SELECT COUNT(*)
            FROM recipe_inventory_scores
            WHERE score_revision_id = ?
        ");
        $scoreCountStmt->execute([$revisionId]);
        $overlayScoreCount = (int)$scoreCountStmt->fetchColumn();
        $matchCountStmt = $db->prepare("
            SELECT COUNT(*)
            FROM ingredient_ontology_shadow_matches
            WHERE score_revision_id = ?
        ");
        $matchCountStmt->execute([$revisionId]);
        $overlayMatchCount = (int)$matchCountStmt->fetchColumn();
        $parentReport = json_decode(
            (string)($parent['validation_report_json'] ?? '{}'),
            true
        );
        $expectedMatchCount = is_array($parentReport)
            ? (int)($parentReport['ingredient_match_count'] ?? -1)
            : -1;
        $chainPolicy =
            ingredientOntologyV3IncrementalChainPolicy($parentReport);
        $incrementalChainDepth = (int)$chainPolicy['depth'];
        $compactFullHashes = !empty($chainPolicy['compact']);
        $scoreCount = (int)$parent['recipe_count'];
        $matchCount = $expectedMatchCount;
        if (
            $expectedMatchCount < 0
            || $overlayScoreCount !== count($affectedRecipeIds)
        ) {
            throw new RuntimeException(
                'incremental score overlay is incomplete'
            );
        }
        $overlayHashStarted = hrtime(true);
        $overlayScoreHash =
            ingredientOntologyV3IncrementalOverlayScoreHash(
                $db,
                $revisionId
            );
        $overlayHashMs =
            (hrtime(true) - $overlayHashStarted) / 1000000;
        if (
            (int)$overlayScoreHash['score_row_count']
                !== count($affectedRecipeIds)
        ) {
            throw new RuntimeException(
                'incremental score overlay hash is incomplete'
            );
        }
        $hashMs = 0.0;
        $valueHashes = [];
        $report = [
            'version' =>
                INGREDIENT_ONTOLOGY_V3_INCREMENTAL_REPORT_VERSION,
            'shadow_only' => false,
            'incremental' => true,
            'overlay_ready' => true,
            'materialized_hash_algorithm' => 'parent-delta-v1',
            'incremental_chain_depth' => $incrementalChainDepth,
            'activated' => false,
            'ontology_version_id' => $versionId,
            'recipe_count' => $scoreCount,
            'ingredient_match_count' => $matchCount,
            'inventory_revision' =>
                (int)$state['inventory_revision'],
            'catalog_revision' => (int)$state['catalog_revision'],
            'catalog_fingerprint' =>
                (string)$parent['catalog_fingerprint'],
            'inventory_fingerprint' => $inventoryFingerprint,
            'score_date' => $scoreDate,
            'ontology_source_revision' =>
                (int)$state['ontology_source_revision'],
            'ontology_source_hash' => $ontologySourceHash,
            'active_score_revision_id_before' =>
                (int)$parent['id'],
            'scoring_configuration' => array_merge(
                ingredientOntologyV3ScoringConfiguration(),
                ['hash' => ingredientOntologyV3ScoringConfigHash()]
            ),
            'identity_annex_overlay' => [
                'resolver_version' =>
                    INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
                'review_manifest_hash' =>
                    ingredientOntologyV3IdentityAnnexReviewManifestHash(),
                'product_ids' => $productIds,
                'affected_recipe_count' =>
                    count($affectedRecipeIds),
            ],
            'materialized_id_sets' => [
                'valid' => true,
                'copied_from_revision_id' => (int)$parent['id'],
                'current_hashes' => [
                    'catalog_id_set_hash' =>
                        (string)$parent['catalog_id_set_hash'],
                    'ingredient_id_set_hash' =>
                        (string)$parent['ingredient_id_set_hash'],
                    'requirement_recipe_id_set_hash' => null,
                    'requirement_id_set_hash' => null,
                ],
            ],
            'materialized_values' => [
                'valid' => true,
                'overlay' => $overlayScoreHash,
            ],
            'timing_ms' => [
                'copy' => round($copyMs, 3),
                'score' => round($scoreMs, 3),
                'overlay_hash' => round($overlayHashMs, 3),
            ],
        ];

        $overlayPublishStarted = hrtime(true);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $lockedState = recipeScoreState($db);
            if (
                (int)$lockedState['active_score_revision_id']
                    !== (int)$parent['id']
                || (int)$lockedState['inventory_revision']
                    !== (int)$state['inventory_revision']
                || (int)$lockedState['catalog_revision']
                    !== (int)$state['catalog_revision']
                || (int)$lockedState['ontology_source_revision']
                    !== (int)$state['ontology_source_revision']
                || recipeScoreCurrentDate() !== $scoreDate
            ) {
                throw new RuntimeException(
                    'incremental score overlay fence changed'
                );
            }
            $db->prepare("
                UPDATE recipe_score_revisions
                SET recipe_count = ?,
                    validation_report_json = ?,
                    last_error = ''
                WHERE id = ? AND status = 'building'
            ")->execute([
                $scoreCount,
                ingredientOntologyV3Json($report),
                $revisionId,
            ]);
            $overlay = $db->prepare("
                UPDATE recipe_score_state
                SET active_score_overlay_revision_id = ?,
                    cursor_revision = cursor_revision + 1,
                    ontology_source_hash = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = 1
                  AND active_score_revision_id = ?
                  AND inventory_revision = ?
                  AND catalog_revision = ?
                  AND ontology_source_revision = ?
                  AND active_score_overlay_revision_id IS NULL
            ");
            $overlay->execute([
                $revisionId,
                $ontologySourceHash,
                (int)$parent['id'],
                (int)$state['inventory_revision'],
                (int)$state['catalog_revision'],
                (int)$state['ontology_source_revision'],
            ]);
            if ($overlay->rowCount() !== 1) {
                throw new RuntimeException(
                    'incremental score overlay CAS failed'
                );
            }
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $error;
        }
        recipeScoreReadRevisionCacheClear();
        $visibleMs = (hrtime(true) - $started) / 1000000;
        $overlayPublishMs =
            (hrtime(true) - $overlayPublishStarted) / 1000000;
        if (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && is_callable(
                $GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_AFTER_OVERLAY_PUBLICATION'
                ] ?? null
            )
        ) {
            ($GLOBALS[
                'INGREDIENT_ONTOLOGY_V3_AFTER_OVERLAY_PUBLICATION'
            ])(
                $db,
                $revisionId,
                (int)$parent['id'],
                $affectedRecipeIds
            );
        }

        $copyStarted = hrtime(true);
        $copied = ingredientOntologyV3IncrementalCopyParentRows(
            $db,
            (int)$parent['id'],
            $revisionId
        );
        $copyMs = (hrtime(true) - $copyStarted) / 1000000;
        $scoreCountStmt->execute([$revisionId]);
        $materializedScoreCount =
            (int)$scoreCountStmt->fetchColumn();
        $matchCountStmt->execute([$revisionId]);
        $materializedMatchCount =
            (int)$matchCountStmt->fetchColumn();
        if (
            $materializedScoreCount !== $scoreCount
            || $materializedMatchCount !== $matchCount
            || $copied['score_rows']
                !== $scoreCount - $overlayScoreCount
            || $copied['match_rows']
                !== $matchCount - $overlayMatchCount
        ) {
            throw new RuntimeException(
                'incremental score materialization is incomplete'
            );
        }
        $hashStarted = hrtime(true);
        $valueHashes = ingredientOntologyV3IncrementalValueHashes(
            $db,
            $revisionId,
            $parent
        );
        $hashMs = (hrtime(true) - $hashStarted) / 1000000;
        if (
            (int)$valueHashes['affected_recipe_count']
                !== count($affectedRecipeIds)
            || (int)$valueHashes['changed_match_row_count']
                !== $overlayMatchCount
        ) {
            throw new RuntimeException(
                'incremental score value hashes are incomplete'
            );
        }
        $report['overlay_ready'] = false;
        $report['activated'] = true;
        $report['materialized_values'] = [
            'valid' => true,
            'current' => $valueHashes,
        ];
        $report['timing_ms']['copy'] = round($copyMs, 3);
        $report['timing_ms']['hash'] = round($hashMs, 3);
        $report['timing_ms']['overlay_publish'] =
            round($overlayPublishMs, 3);
        $finalValueHashes = $valueHashes;
        if ($compactFullHashes) {
            $fullHashStarted = hrtime(true);
            $finalValueHashes =
                ingredientOntologyV3MaterializedValueHashes(
                    $db,
                    $revisionId,
                    null
                );
            if (
                (int)$finalValueHashes['score_row_count']
                    !== $scoreCount
                || (int)$finalValueHashes['match_row_count']
                    !== $matchCount
            ) {
                throw new RuntimeException(
                    'incremental score full hash compaction is incomplete'
                );
            }
            $report['materialized_hash_algorithm'] = 'full-v1';
            $report['incremental_chain_depth'] = 0;
            $report['materialized_values'] = [
                'valid' => true,
                'current' => $finalValueHashes,
            ];
            $report['timing_ms']['full_hash'] = round(
                (hrtime(true) - $fullHashStarted) / 1000000,
                3
            );
        }

        $db->exec('BEGIN IMMEDIATE');
        $finalizeStarted = hrtime(true);
        $guardWasEnabled =
            ingredientOntologyV3PublicationGuardEnabled($db);
        ingredientOntologyV3SetPublicationGuard($db, true);
        try {
            $lockedState = recipeScoreState($db);
            if (
                (int)$lockedState['active_score_revision_id']
                    !== (int)$parent['id']
                || (int)$lockedState['inventory_revision']
                    !== (int)$state['inventory_revision']
                || (int)$lockedState['catalog_revision']
                    !== (int)$state['catalog_revision']
                || (int)$lockedState['ontology_source_revision']
                    !== (int)$state['ontology_source_revision']
                || recipeScoreCurrentDate() !== $scoreDate
                || (int)(
                    $lockedState[
                        'active_score_overlay_revision_id'
                    ] ?? 0
                ) !== $revisionId
            ) {
                throw new RuntimeException(
                    'incremental score publication fence changed'
                );
            }
            $db->prepare("
                UPDATE recipe_score_revisions
                SET status = 'ready',
                    recipe_count = ?,
                    score_rows_hash = ?,
                    match_rows_hash = ?,
                    materialization_hash = ?,
                    validation_report_json = ?,
                    completed_at = CURRENT_TIMESTAMP,
                    last_error = ''
                WHERE id = ? AND status = 'building'
            ")->execute([
                $scoreCount,
                (string)$finalValueHashes['score_rows_hash'],
                (string)$finalValueHashes['match_rows_hash'],
                (string)$finalValueHashes['materialization_hash'],
                ingredientOntologyV3Json($report),
                $revisionId,
            ]);
            $activate = $db->prepare("
                UPDATE recipe_score_state
                SET active_score_revision_id = ?,
                    active_score_overlay_revision_id = NULL,
                    cursor_revision = cursor_revision + 1,
                    ontology_source_hash = ?,
                    last_built_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = 1
                  AND active_score_revision_id = ?
                  AND inventory_revision = ?
                  AND catalog_revision = ?
                  AND ontology_source_revision = ?
                  AND active_score_overlay_revision_id = ?
            ");
            $activate->execute([
                $revisionId,
                $ontologySourceHash,
                (int)$parent['id'],
                (int)$state['inventory_revision'],
                (int)$state['catalog_revision'],
                (int)$state['ontology_source_revision'],
                $revisionId,
            ]);
            if ($activate->rowCount() !== 1) {
                throw new RuntimeException(
                    'incremental score publication CAS failed'
                );
            }
            recipeScoreClearPendingProducts(
                $db,
                (int)$state['inventory_revision'],
                $productIds
            );
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $error;
        } finally {
            ingredientOntologyV3SetPublicationGuard(
                $db,
                $guardWasEnabled
            );
        }
        recipeScoreReadRevisionCacheClear();
        $finalizeMs = (hrtime(true) - $finalizeStarted) / 1000000;
        $cleanupStarted = hrtime(true);
        $cleanupWarning = ingredientOntologyV3PostActivationCleanup($db);
        $cleanupMs = (hrtime(true) - $cleanupStarted) / 1000000;
        return [
            'rebuilt' => true,
            'revision_id' => $revisionId,
            'parent_revision_id' => (int)$parent['id'],
            'ontology_version_id' => $versionId,
            'inventory_revision' =>
                (int)$state['inventory_revision'],
            'product_ids' => $productIds,
            'affected_recipe_count' => count($affectedRecipeIds),
            'recipe_count' => $scoreCount,
            'match_count' => $matchCount,
            'elapsed_ms' => round(
                (hrtime(true) - $started) / 1000000,
                3
            ),
            'visible_ms' => round($visibleMs, 3),
            'timing_ms' => $report['timing_ms'] + [
                'prepare' => round($prepareMs, 3),
                'finalize' => round($finalizeMs, 3),
                'cleanup' => round($cleanupMs, 3),
            ],
            'cleanup_warning' => $cleanupWarning,
        ];
    } catch (Throwable $error) {
        if ($revisionId > 0) {
            $db->prepare("
                UPDATE recipe_score_state
                SET active_score_overlay_revision_id = NULL,
                    cursor_revision = cursor_revision + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = 1
                  AND active_score_overlay_revision_id = ?
            ")->execute([$revisionId]);
            $db->prepare("
                UPDATE recipe_score_revisions
                SET status = 'failed',
                    last_error = ?,
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'building'
            ")->execute([
                mb_substr($error->getMessage(), 0, 1000, 'UTF-8'),
                $revisionId,
            ]);
        }
        return [
            'rebuilt' => false,
            'reason' => 'failed',
            'error' => mb_substr(
                $error->getMessage(),
                0,
                1000,
                'UTF-8'
            ),
            'revision_id' => $revisionId ?: null,
            'elapsed_ms' => round(
                (hrtime(true) - $started) / 1000000,
                3
            ),
        ];
    } finally {
        recipeScoreReleaseLock($lock);
    }
}
