<?php
declare(strict_types=1);

const INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION =
    'identity-annex-r0-v1';
const INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_REVIEW_VERSION =
    'operator-reviewed-aliases-2026-08-18-v2';

function ingredientOntologyV3IdentityAnnexReviewedAliases(): array {
    $aliases = [
        'russet potato' => [
            'target_normalized_label' => 'potatoes',
            'target_language' => 'en',
            'target_entity_slug' => 'potato',
            'target_kind' => 'exact_alias',
            'review_key' => 'russet-potato-to-potato',
            'rationale' =>
                'Russet is a reviewed potato variety and preserves potato identity.',
        ],
        'russet potatoes' => [
            'target_normalized_label' => 'potatoes',
            'target_language' => 'en',
            'target_entity_slug' => 'potato',
            'target_kind' => 'exact_alias',
            'review_key' => 'russet-potatoes-to-potato',
            'rationale' =>
                'Russet is a reviewed potato variety and preserves potato identity.',
        ],
        'eggplant' => [
            'target_normalized_label' => 'eggplant',
            'target_language' => 'en',
            'target_entity_slug' => 'eggplant',
            'target_kind' => 'exact_alias',
            'review_key' => 'eggplant-singular-en',
            'rationale' =>
                'Reviewed unqualified English eggplant identity.',
        ],
        'eggplants' => [
            'target_normalized_label' => 'eggplant',
            'target_language' => 'en',
            'target_entity_slug' => 'eggplant',
            'target_kind' => 'exact_alias',
            'review_key' => 'eggplant-plural-en',
            'rationale' =>
                'Reviewed unqualified English plural eggplant identity.',
        ],
        'aubergine' => [
            'target_normalized_label' => 'eggplant',
            'target_language' => 'en',
            'target_entity_slug' => 'eggplant',
            'target_kind' => 'exact_alias',
            'review_key' => 'aubergine-singular',
            'rationale' =>
                'Reviewed unqualified aubergine synonym for eggplant.',
        ],
        'aubergines' => [
            'target_normalized_label' => 'eggplant',
            'target_language' => 'en',
            'target_entity_slug' => 'eggplant',
            'target_kind' => 'exact_alias',
            'review_key' => 'aubergine-plural',
            'rationale' =>
                'Reviewed unqualified plural aubergine synonym for eggplant.',
        ],
        'auberginen' => [
            'target_normalized_label' => 'eggplant',
            'target_language' => 'en',
            'target_entity_slug' => 'eggplant',
            'target_kind' => 'exact_alias',
            'review_key' => 'auberginen-de',
            'rationale' =>
                'Reviewed German plural eggplant identity.',
        ],
        'auberginer' => [
            'target_normalized_label' => 'eggplant',
            'target_language' => 'en',
            'target_entity_slug' => 'eggplant',
            'target_kind' => 'exact_alias',
            'review_key' => 'auberginer-da',
            'rationale' =>
                'Reviewed Danish plural eggplant wording.',
        ],
        'melanzana' => [
            'target_normalized_label' => 'eggplant',
            'target_language' => 'en',
            'target_entity_slug' => 'eggplant',
            'target_kind' => 'exact_alias',
            'review_key' => 'melanzana-it',
            'rationale' =>
                'Reviewed Italian singular eggplant identity.',
        ],
        'melanzane' => [
            'target_normalized_label' => 'eggplant',
            'target_language' => 'en',
            'target_entity_slug' => 'eggplant',
            'target_kind' => 'exact_alias',
            'review_key' => 'melanzane-it',
            'rationale' =>
                'Reviewed Italian plural eggplant identity.',
        ],
        'melanzani' => [
            'target_normalized_label' => 'eggplant',
            'target_language' => 'en',
            'target_entity_slug' => 'eggplant',
            'target_kind' => 'exact_alias',
            'review_key' => 'melanzani-de',
            'rationale' =>
                'Reviewed German unqualified eggplant wording.',
        ],
        'di melanzana' => [
            'target_normalized_label' => 'eggplant',
            'target_language' => 'en',
            'target_entity_slug' => 'eggplant',
            'target_kind' => 'exact_alias',
            'review_key' => 'di-melanzana-it',
            'rationale' =>
                'Reviewed Italian source phrase for singular eggplant.',
        ],
        'di melanzane' => [
            'target_normalized_label' => 'eggplant',
            'target_language' => 'en',
            'target_entity_slug' => 'eggplant',
            'target_kind' => 'exact_alias',
            'review_key' => 'di-melanzane-it',
            'rationale' =>
                'Reviewed Italian source phrase for plural eggplant.',
        ],
        'd aubergine' => [
            'target_normalized_label' => 'eggplant',
            'target_language' => 'en',
            'target_entity_slug' => 'eggplant',
            'target_kind' => 'exact_alias',
            'review_key' => 'd-aubergine-fr',
            'rationale' =>
                'Reviewed normalized French elision for singular aubergine.',
        ],
        'd aubergines' => [
            'target_normalized_label' => 'eggplant',
            'target_language' => 'en',
            'target_entity_slug' => 'eggplant',
            'target_kind' => 'exact_alias',
            'review_key' => 'd-aubergines-fr',
            'rationale' =>
                'Reviewed normalized French elision for plural aubergines.',
        ],
    ];
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && is_array(
            $GLOBALS[
                'INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_REVIEWED_ALIASES'
            ] ?? null
        )
    ) {
        $aliases = $GLOBALS[
            'INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_REVIEWED_ALIASES'
        ];
    }
    ksort($aliases, SORT_STRING);
    return $aliases;
}

function ingredientOntologyV3IdentityAnnexReviewManifestHash(): string {
    return ingredientOntologyV3Hash([
        'version' => INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_REVIEW_VERSION,
        'aliases' => ingredientOntologyV3IdentityAnnexReviewedAliases(),
    ]);
}

function ingredientOntologyV3IdentityAnnexPreviousReviewedAliases(): array {
    return [
        'russet potato' => [
            'target_normalized_label' => 'potatoes',
            'target_language' => 'en',
            'target_entity_slug' => 'potato',
            'target_kind' => 'exact_alias',
            'review_key' => 'russet-potato-to-potato',
            'rationale' =>
                'Russet is a reviewed potato variety and preserves potato identity.',
        ],
        'russet potatoes' => [
            'target_normalized_label' => 'potatoes',
            'target_language' => 'en',
            'target_entity_slug' => 'potato',
            'target_kind' => 'exact_alias',
            'review_key' => 'russet-potatoes-to-potato',
            'rationale' =>
                'Russet is a reviewed potato variety and preserves potato identity.',
        ],
    ];
}

function ingredientOntologyV3IdentityAdmissionState(PDO $db): array {
    if (!ingredientOntologyV3TableExists(
        $db,
        'ingredient_ontology_identity_admission_state'
    )) {
        return [
            'available' => false,
            'revision' => 0,
            'resolver_version' =>
                INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
            'review_manifest_hash' =>
                ingredientOntologyV3IdentityAnnexReviewManifestHash(),
            'last_changed_label_count' => 0,
            'updated_at' => null,
        ];
    }
    $row = $db->query("
        SELECT revision, resolver_version, review_manifest_hash,
               manifest_json, last_changed_label_count, updated_at
        FROM ingredient_ontology_identity_admission_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'available' => true,
        'revision' => (int)($row['revision'] ?? 0),
        'resolver_version' =>
            (string)($row['resolver_version'] ?? ''),
        'review_manifest_hash' =>
            (string)($row['review_manifest_hash'] ?? ''),
        'manifest_json' => (string)($row['manifest_json'] ?? '{}'),
        'last_changed_label_count' =>
            (int)($row['last_changed_label_count'] ?? 0),
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function ingredientOntologyV3IdentityAdmissionSync(
    PDO $db,
    int $attempt = 0
): array {
    if ($attempt > 2) {
        throw new RuntimeException(
            'identity admission manifest synchronization did not converge'
        );
    }
    $state = ingredientOntologyV3IdentityAdmissionState($db);
    if (empty($state['available'])) {
        return $state + ['changed' => false];
    }
    $aliases = ingredientOntologyV3IdentityAnnexReviewedAliases();
    $manifest = [
        'version' => INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_REVIEW_VERSION,
        'resolver_version' =>
            INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
        'aliases' => $aliases,
    ];
    $manifestJson = ingredientOntologyV3Json($manifest);
    $manifestHash =
        ingredientOntologyV3IdentityAnnexReviewManifestHash();
    if (
        hash_equals(
            (string)$state['review_manifest_hash'],
            $manifestHash
        )
        && (string)$state['resolver_version']
            === INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION
    ) {
        return $state + [
            'changed' => false,
            'changed_labels' => [],
        ];
    }
    $previous = json_decode(
        (string)($state['manifest_json'] ?? '{}'),
        true
    );
    $previousAliases = is_array($previous['aliases'] ?? null)
        ? $previous['aliases']
        : [];
    if (
        (int)($state['revision'] ?? 0) === 0
        && (string)($state['review_manifest_hash'] ?? '') === ''
        && !$previousAliases
    ) {
        $previousAliases =
            ingredientOntologyV3IdentityAnnexPreviousReviewedAliases();
    }
    $changedLabels = [];
    foreach (
        array_unique(array_merge(
            array_keys($previousAliases),
            array_keys($aliases)
        )) as $label
    ) {
        $before = $previousAliases[$label] ?? null;
        $after = $aliases[$label] ?? null;
        if (
            !hash_equals(
                ingredientOntologyV3Hash($before),
                ingredientOntologyV3Hash($after)
            )
        ) {
            $normalized = ingredientOntologyV3NormalizeLabel(
                (string)$label
            );
            if ($normalized !== '') {
                $changedLabels[$normalized] = true;
            }
        }
    }
    $changedLabels = array_keys($changedLabels);
    sort($changedLabels, SORT_STRING);
    $productIds = [];
    $recipeIds = [];
    $discoveryActiveScoreId = 0;
    if (
        $changedLabels
        && ingredientOntologyV3TableExists($db, 'recipe_score_state')
        && ingredientOntologyV3TableExists(
            $db,
            'recipe_score_pending_products'
        )
        && ingredientOntologyV3TableExists(
            $db,
            'recipe_score_pending_recipes'
        )
    ) {
        $products = $db->query("
            SELECT product.id, product.name
            FROM products product
            WHERE EXISTS (
                SELECT 1 FROM inventory stock
                WHERE stock.product_id = product.id
                  AND stock.quantity > 0
            )
            ORDER BY product.id
        ")->fetchAll(PDO::FETCH_ASSOC);
        $changedSet = array_fill_keys($changedLabels, true);
        foreach ($products as $product) {
            if (isset($changedSet[
                ingredientOntologyV3NormalizeLabel(
                    (string)$product['name']
                )
            ])) {
                $productIds[] = (int)$product['id'];
            }
        }
        $active = recipeScoreActiveRevision($db);
        $discoveryActiveScoreId = (int)($active['id'] ?? 0);
        $versionId = (int)($active['ontology_version_id'] ?? 0);
        if ($versionId > 0) {
            $placeholders = implode(
                ',',
                array_fill(0, count($changedLabels), '?')
            );
            $recipes = $db->prepare("
                SELECT DISTINCT ingredient.recipe_id
                FROM ingredient_ontology_mappings mapping
                JOIN recipe_ingredients ingredient
                  ON ingredient.id = mapping.owner_id
                JOIN recipe_catalog recipe
                  ON recipe.id = ingredient.recipe_id
                 AND recipe.deleted_at IS NULL
                WHERE mapping.ontology_version_id = ?
                  AND mapping.owner_type = 'recipe_ingredient'
                  AND mapping.normalized_label IN ({$placeholders})
                ORDER BY ingredient.recipe_id
            ");
            $recipes->execute(array_merge(
                [$versionId],
                $changedLabels
            ));
            $recipeIds = array_map(
                'intval',
                $recipes->fetchAll(PDO::FETCH_COLUMN)
            );
            $recipes->closeCursor();
        }
    }
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && is_callable(
            $GLOBALS[
                'IDENTITY_ADMISSION_BEFORE_RESERVATION'
            ] ?? null
        )
    ) {
        ($GLOBALS[
            'IDENTITY_ADMISSION_BEFORE_RESERVATION'
        ])($db, $state, $changedLabels);
    }
    $savepoint = 'identity_admission_manifest_sync';
    $db->exec("SAVEPOINT {$savepoint}");
    try {
        $currentState = ingredientOntologyV3IdentityAdmissionState($db);
        $currentActive = recipeScoreActiveRevision($db);
        if (
            (int)$currentState['revision'] !== (int)$state['revision']
            || !hash_equals(
                (string)$currentState['review_manifest_hash'],
                (string)$state['review_manifest_hash']
            )
            || (
                $discoveryActiveScoreId > 0
                && (int)($currentActive['id'] ?? 0)
                    !== $discoveryActiveScoreId
            )
        ) {
            $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
            return ingredientOntologyV3IdentityAdmissionSync(
                $db,
                $attempt + 1
            );
        }
        $manifestUpdate = $db->prepare("
            UPDATE ingredient_ontology_identity_admission_state
            SET revision = revision + 1,
                resolver_version = ?,
                review_manifest_hash = ?,
                manifest_json = ?,
                last_changed_label_count = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1
              AND revision = ?
              AND review_manifest_hash = ?
        ");
        $manifestUpdate->execute([
            INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
            $manifestHash,
            $manifestJson,
            count($changedLabels),
            (int)$state['revision'],
            (string)$state['review_manifest_hash'],
        ]);
        if ($manifestUpdate->rowCount() !== 1) {
            $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
            return ingredientOntologyV3IdentityAdmissionSync(
                $db,
                $attempt + 1
            );
        }
        if (
            $changedLabels
            && ingredientOntologyV3TableExists($db, 'recipe_score_state')
            && ingredientOntologyV3TableExists(
                $db,
                'recipe_score_pending_products'
            )
            && ingredientOntologyV3TableExists(
                $db,
                'recipe_score_pending_recipes'
            )
        ) {
            $scoreState = recipeScoreState($db);
            $inventoryRevision = (int)$scoreState['inventory_revision'];
            if ($productIds) {
                $inventoryRevision = recipeScoreMarkDirty($db);
                $pendingProduct = $db->prepare("
                    INSERT INTO recipe_score_pending_products (
                        product_id, first_inventory_revision,
                        latest_inventory_revision, reason,
                        created_at, updated_at
                    )
                    VALUES (
                        ?, ?, ?, 'identity_admission_manifest_changed',
                        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                    ON CONFLICT(product_id) DO UPDATE SET
                        latest_inventory_revision = MAX(
                            recipe_score_pending_products
                                .latest_inventory_revision,
                            excluded.latest_inventory_revision
                        ),
                        reason = excluded.reason,
                        updated_at = CURRENT_TIMESTAMP
                ");
                foreach ($productIds as $productId) {
                    $pendingProduct->execute([
                        $productId,
                        $inventoryRevision,
                        $inventoryRevision,
                    ]);
                }
                $scoreState = recipeScoreState($db);
            }
            if ($recipeIds) {
                $pendingRecipe = $db->prepare("
                    INSERT INTO recipe_score_pending_recipes (
                        recipe_id, operation,
                        first_catalog_revision,
                        latest_catalog_revision,
                        latest_ontology_source_revision,
                        reason, created_at, updated_at
                    )
                    VALUES (
                        ?, 'replace', ?, ?, ?,
                        'identity_admission_manifest_changed',
                        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                    ON CONFLICT(recipe_id) DO UPDATE SET
                        operation = 'replace',
                        latest_catalog_revision = MAX(
                            recipe_score_pending_recipes
                                .latest_catalog_revision,
                            excluded.latest_catalog_revision
                        ),
                        latest_ontology_source_revision = MAX(
                            recipe_score_pending_recipes
                                .latest_ontology_source_revision,
                            excluded.latest_ontology_source_revision
                        ),
                        reason = excluded.reason,
                        updated_at = CURRENT_TIMESTAMP
                ");
                foreach ($recipeIds as $recipeId) {
                    $pendingRecipe->execute([
                        $recipeId,
                        (int)$scoreState['catalog_revision'],
                        (int)$scoreState['catalog_revision'],
                        (int)$scoreState['ontology_source_revision'],
                    ]);
                }
            }
        }
        $db->exec("RELEASE SAVEPOINT {$savepoint}");
    } catch (Throwable $error) {
        try {
            $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
    $current = ingredientOntologyV3IdentityAdmissionState($db);
    return $current + [
        'changed' => true,
        'changed_labels' => $changedLabels,
        'queued_product_ids' => $productIds,
        'queued_recipe_ids' => $recipeIds,
    ];
}

function ingredientOntologyV3RecipeAnnexResolution(
    PDO $db,
    array $version,
    string $sourceLabel,
    string $language
): array {
    $normalizedLabel = ingredientOntologyV3NormalizeLabel(
        $sourceLabel
    );
    $language = ingredientOntologyV3NormalizeLanguage($language);
    if ($normalizedLabel === '') {
        return [
            'status' => 'unresolved',
            'reason' => 'empty_label',
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'attributes' => [],
            'normalized_label' => '',
            'language' => $language,
        ];
    }
    $candidates = ingredientOntologyV3IdentityAnnexLabelCandidates(
        $db,
        (int)$version['id'],
        $normalizedLabel,
        $language
    );
    $admissionSource = 'accepted_label';
    $review = null;
    if (!$candidates) {
        $review = ingredientOntologyV3IdentityAnnexReviewedAliases()[
            $normalizedLabel
        ] ?? null;
        if ($review !== null) {
            $candidates = ingredientOntologyV3IdentityAnnexLabelCandidates(
                $db,
                (int)$version['id'],
                (string)$review['target_normalized_label'],
                (string)$review['target_language']
            );
            $candidates = array_values(array_filter(
                $candidates,
                static fn(array $candidate): bool =>
                    (string)$candidate['entity_slug']
                        === (string)$review['target_entity_slug']
                    && (string)$candidate['kind']
                        === (string)$review['target_kind']
            ));
            $admissionSource = 'reviewed_alias';
        }
    }
    $entities = [];
    foreach ($candidates as $candidate) {
        $entities[(int)$candidate['entity_id']] = true;
    }
    if (!$candidates) {
        return [
            'status' => 'unresolved',
            'reason' => 'no_reviewed_exact_alias',
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'attributes' => [],
            'normalized_label' => $normalizedLabel,
            'language' => $language,
        ];
    }
    if (count($entities) !== 1) {
        return [
            'status' => 'rejected',
            'reason' => 'reviewed_alias_collision',
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'attributes' => [],
            'normalized_label' => $normalizedLabel,
            'language' => $language,
        ];
    }
    $candidate = $candidates[0];
    return [
        'status' => 'accepted',
        'reason' => $admissionSource,
        'admission_source' => $admissionSource,
        'label_id' => (int)$candidate['label_id'],
        'entity_id' => (int)$candidate['entity_id'],
        'attributes' => (array)$candidate['attributes'],
        'normalized_label' => $normalizedLabel,
        'language' => $language,
        'review' => $review,
    ];
}

function ingredientOntologyV3RecipeAnnexSourceRows(
    PDO $db,
    int $recipeId
): array {
    $stmt = $db->prepare("
        SELECT ingredient.*,
               COALESCE(
                   NULLIF(ingredient.raw_text, ''),
                   ingredient.normalized_name
               ) AS source_label,
               recipe.language, recipe.primary_connector,
               COALESCE(origin.external_id, '') AS origin_external_id,
               COALESCE(origin.locale, '') AS origin_locale
        FROM recipe_ingredients ingredient
        JOIN recipe_catalog recipe
          ON recipe.id = ingredient.recipe_id
        LEFT JOIN recipe_origins origin
          ON origin.id = (
              SELECT candidate.id
              FROM recipe_origins candidate
              WHERE candidate.recipe_id = ingredient.recipe_id
                AND candidate.connector = recipe.primary_connector
              ORDER BY candidate.id
              LIMIT 1
          )
        WHERE ingredient.recipe_id = ?
          AND recipe.deleted_at IS NULL
        ORDER BY ingredient.position
    ");
    $stmt->execute([$recipeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ingredientOntologyV3RecipeAnnexBatchSourceRows(
    PDO $db,
    array $recipeIds,
    int $versionId
): array {
    $recipeIds = array_values(array_unique(array_filter(
        array_map('intval', $recipeIds),
        static fn(int $id): bool => $id > 0
    )));
    if (!$recipeIds) {
        return [];
    }
    $placeholders = implode(
        ',',
        array_fill(0, count($recipeIds), '?')
    );
    $stmt = $db->prepare("
        SELECT ingredient.*,
               COALESCE(
                   NULLIF(ingredient.raw_text, ''),
                   ingredient.normalized_name
               ) AS source_label,
               recipe.language, recipe.primary_connector,
               COALESCE(origin.external_id, '') AS origin_external_id,
               COALESCE(origin.locale, '') AS origin_locale,
               mapping.owner_fingerprint
                   AS sealed_owner_fingerprint,
               mapping.status AS sealed_status,
               annex.recipe_ingredient_id AS annex_present_id,
               annex.ontology_version_id AS annex_ontology_version_id,
               annex.ontology_content_hash
                   AS annex_ontology_content_hash,
               annex.ontology_seal_hash AS annex_ontology_seal_hash,
               annex.owner_fingerprint AS annex_owner_fingerprint,
               annex.source_label AS annex_source_label,
               annex.normalized_label AS annex_normalized_label,
               annex.language AS annex_language,
               annex.label_id AS annex_label_id,
               annex.entity_id AS annex_entity_id,
               annex.status AS annex_status,
               annex.confidence AS annex_confidence,
               annex.admission_source AS annex_admission_source,
               annex.attributes_json AS annex_attributes_json,
               annex.resolver_version AS annex_resolver_version,
               annex.review_manifest_hash
                   AS annex_review_manifest_hash,
               annex.evidence_hash AS annex_evidence_hash,
               annex.reason AS annex_reason
        FROM recipe_ingredients ingredient
        JOIN recipe_catalog recipe
          ON recipe.id = ingredient.recipe_id
        LEFT JOIN recipe_origins origin
          ON origin.id = (
              SELECT candidate.id
              FROM recipe_origins candidate
              WHERE candidate.recipe_id = ingredient.recipe_id
                AND candidate.connector = recipe.primary_connector
              ORDER BY candidate.id
              LIMIT 1
          )
        LEFT JOIN ingredient_ontology_mappings mapping
          ON mapping.ontology_version_id = ?
         AND mapping.owner_type = 'recipe_ingredient'
         AND mapping.owner_id = ingredient.id
        LEFT JOIN ingredient_ontology_recipe_identity_annex annex
          ON annex.recipe_ingredient_id = ingredient.id
        WHERE ingredient.recipe_id IN ({$placeholders})
          AND recipe.deleted_at IS NULL
        ORDER BY ingredient.recipe_id, ingredient.position
    ");
    $stmt->execute(array_merge([$versionId], $recipeIds));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ingredientOntologyV3RecipeAnnexExistingMatches(
    array $row,
    array $desired
): bool {
    if ($row['annex_present_id'] === null) {
        return false;
    }
    foreach ([
        'ontology_version_id',
        'label_id',
        'entity_id',
    ] as $field) {
        $existing = $row['annex_' . $field];
        $expected = $desired[$field];
        if (
            ($existing === null) !== ($expected === null)
            || (
                $existing !== null
                && (int)$existing !== (int)$expected
            )
        ) {
            return false;
        }
    }
    foreach ([
        'ontology_content_hash',
        'ontology_seal_hash',
        'owner_fingerprint',
        'source_label',
        'normalized_label',
        'language',
        'status',
        'admission_source',
        'attributes_json',
        'resolver_version',
        'review_manifest_hash',
        'evidence_hash',
        'reason',
    ] as $field) {
        if ((string)$row['annex_' . $field]
            !== (string)$desired[$field]) {
            return false;
        }
    }
    return abs(
        (float)$row['annex_confidence']
            - (float)$desired['confidence']
    ) < 0.0000001;
}

function ingredientOntologyV3RecipeAnnexResolveCoverageForRecipes(
    PDO $db,
    array $recipeIds,
    array $version
): int {
    if (
        !$recipeIds
        || !ingredientOntologyV3TableExists(
            $db,
            'ontology_controller_coverage_gaps'
        )
    ) {
        return 0;
    }
    $placeholders = implode(
        ',',
        array_fill(0, count($recipeIds), '?')
    );
    $stmt = $db->prepare("
        UPDATE ontology_controller_coverage_gaps
        SET status = 'resolved',
            resolved_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE status = 'open'
          AND subject_id IN (
              SELECT DISTINCT occurrence.subject_id
              FROM ontology_subject_occurrences occurrence
              JOIN recipe_ingredients ingredient
                ON ingredient.id = occurrence.owner_id
              LEFT JOIN ingredient_ontology_recipe_identity_annex annex
                ON annex.recipe_ingredient_id = ingredient.id
               AND annex.ontology_version_id = ?
               AND annex.ontology_content_hash = ?
               AND annex.ontology_seal_hash = ?
               AND annex.resolver_version = ?
               AND annex.review_manifest_hash = ?
              LEFT JOIN ingredient_ontology_mappings mapping
                ON mapping.ontology_version_id = ?
               AND mapping.owner_type = 'recipe_ingredient'
               AND mapping.owner_id = ingredient.id
              WHERE occurrence.owner_type = 'recipe_ingredient'
                AND occurrence.active = 1
                AND ingredient.recipe_id IN ({$placeholders})
                AND (
                    (
                        annex.recipe_ingredient_id IS NOT NULL
                        AND annex.status = 'accepted'
                        AND occurrence.owner_fingerprint =
                            annex.owner_fingerprint
                    )
                    OR (
                        annex.recipe_ingredient_id IS NULL
                        AND mapping.status = 'accepted'
                        AND occurrence.owner_fingerprint =
                            mapping.owner_fingerprint
                    )
                )
          )
    ");
    $stmt->execute(array_merge([
        (int)$version['id'],
        (string)$version['content_hash'],
        (string)$version['seal_hash'],
        INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
        ingredientOntologyV3IdentityAnnexReviewManifestHash(),
        (int)$version['id'],
    ], $recipeIds));
    return $stmt->rowCount();
}

function ingredientOntologyV3RecipeAnnexRefreshBatch(
    PDO $db,
    array $recipeIds,
    int $versionId,
    bool $resolveCoverageGaps = true
): array {
    $recipeIds = array_values(array_unique(array_filter(
        array_map('intval', $recipeIds),
        static fn(int $id): bool => $id > 0
    )));
    sort($recipeIds, SORT_NUMERIC);
    $version = ingredientOntologyV3Version($db, $versionId);
    if (
        $version === null
        || (string)$version['status'] !== 'ready'
    ) {
        throw new RuntimeException(
            'recipe annex requires a ready ontology version'
        );
    }
    $rows = ingredientOntologyV3RecipeAnnexBatchSourceRows(
        $db,
        $recipeIds,
        $versionId
    );
    $perRecipe = [];
    foreach ($recipeIds as $recipeId) {
        $perRecipe[$recipeId] = [
            'recipe_id' => $recipeId,
            'ingredient_count' => 0,
            'terminal_count' => 0,
            'accepted_count' => 0,
            'changed_row_count' => 0,
            'unchanged_row_count' => 0,
            'ready' => true,
        ];
    }
    $reviewManifestHash =
        ingredientOntologyV3IdentityAnnexReviewManifestHash();
    $resolutionCache = [];
    $upserts = [];
    $deleteIds = [];
    $accepted = 0;
    $terminal = 0;
    $unchanged = 0;
    foreach ($rows as $row) {
        $recipeId = (int)$row['recipe_id'];
        $ingredientId = (int)$row['id'];
        $perRecipe[$recipeId]['ingredient_count']++;
        $ownerFingerprint =
            ingredientOntologyV3RecipeOwnerFingerprint(
                'recipe_ingredient',
                $row
            );
        if (
            $row['sealed_owner_fingerprint'] !== null
            && hash_equals(
                (string)$row['sealed_owner_fingerprint'],
                $ownerFingerprint
            )
            && (string)$row['sealed_status'] === 'accepted'
        ) {
            if ($row['annex_present_id'] !== null) {
                $deleteIds[] = $ingredientId;
                $perRecipe[$recipeId]['changed_row_count']++;
            } else {
                $unchanged++;
                $perRecipe[$recipeId]['unchanged_row_count']++;
            }
            $terminal++;
            $accepted++;
            $perRecipe[$recipeId]['terminal_count']++;
            $perRecipe[$recipeId]['accepted_count']++;
            continue;
        }
        $cacheKey = ingredientOntologyV3NormalizeLabel(
            (string)$row['source_label']
        ) . "\n" . ingredientOntologyV3NormalizeLanguage(
            (string)$row['language']
        );
        if (!isset($resolutionCache[$cacheKey])) {
            $resolutionCache[$cacheKey] =
                ingredientOntologyV3RecipeAnnexResolution(
                    $db,
                    $version,
                    (string)$row['source_label'],
                    (string)$row['language']
                );
        }
        $resolution = $resolutionCache[$cacheKey];
        $attributes = (array)$resolution['attributes'];
        ksort($attributes, SORT_STRING);
        $sourceLabel = mb_substr(
            (string)$row['source_label'],
            0,
            200,
            'UTF-8'
        );
        $normalizedLabel = mb_substr(
            (string)$resolution['normalized_label'],
            0,
            200,
            'UTF-8'
        );
        $attributesJson = ingredientOntologyV3Json($attributes);
        $evidence = [
            'resolver_version' =>
                INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
            'review_manifest_hash' => $reviewManifestHash,
            'ontology_version_id' => $versionId,
            'ontology_content_hash' =>
                (string)$version['content_hash'],
            'ontology_seal_hash' => (string)$version['seal_hash'],
            'recipe_ingredient_id' => $ingredientId,
            'owner_fingerprint' => $ownerFingerprint,
            'source_label' => (string)$row['source_label'],
            'normalized_label' =>
                (string)$resolution['normalized_label'],
            'language' => (string)$resolution['language'],
            'status' => (string)$resolution['status'],
            'admission_source' =>
                (string)$resolution['admission_source'],
            'label_id' => $resolution['label_id'],
            'entity_id' => $resolution['entity_id'],
            'attributes' => $attributes,
            'review' => $resolution['review'] ?? null,
        ];
        $desired = [
            'recipe_ingredient_id' => $ingredientId,
            'ontology_version_id' => $versionId,
            'ontology_content_hash' =>
                (string)$version['content_hash'],
            'ontology_seal_hash' => (string)$version['seal_hash'],
            'owner_fingerprint' => $ownerFingerprint,
            'source_label' => $sourceLabel,
            'normalized_label' => $normalizedLabel,
            'language' => (string)$resolution['language'],
            'label_id' => $resolution['label_id'],
            'entity_id' => $resolution['entity_id'],
            'status' => (string)$resolution['status'],
            'confidence' =>
                (string)$resolution['status'] === 'accepted'
                    ? 1.0
                    : 0.0,
            'admission_source' =>
                (string)$resolution['admission_source'],
            'attributes_json' => $attributesJson,
            'resolver_version' =>
                INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
            'review_manifest_hash' => $reviewManifestHash,
            'evidence_hash' => ingredientOntologyV3Hash($evidence),
            'reason' => (string)$resolution['reason'],
        ];
        if (ingredientOntologyV3RecipeAnnexExistingMatches(
            $row,
            $desired
        )) {
            $unchanged++;
            $perRecipe[$recipeId]['unchanged_row_count']++;
        } else {
            $upserts[] = $desired;
            $perRecipe[$recipeId]['changed_row_count']++;
        }
        $terminal++;
        $perRecipe[$recipeId]['terminal_count']++;
        if ((string)$resolution['status'] === 'accepted') {
            $accepted++;
            $perRecipe[$recipeId]['accepted_count']++;
        }
    }
    $upsertStatementCount = 0;
    $deleteStatementCount = 0;
    $coverageResolvedCount = 0;
    $savepoint = 'recipe_identity_annex_batch';
    $db->exec("SAVEPOINT {$savepoint}");
    try {
        foreach (array_chunk($deleteIds, 500) as $chunk) {
            $delete = $db->prepare("
                DELETE FROM ingredient_ontology_recipe_identity_annex
                WHERE recipe_ingredient_id IN ("
                    . implode(',', array_fill(0, count($chunk), '?'))
                    . ")
            ");
            $delete->execute($chunk);
            $deleteStatementCount++;
        }
        $columns = [
            'recipe_ingredient_id',
            'ontology_version_id',
            'ontology_content_hash',
            'ontology_seal_hash',
            'owner_fingerprint',
            'source_label',
            'normalized_label',
            'language',
            'label_id',
            'entity_id',
            'status',
            'confidence',
            'admission_source',
            'attributes_json',
            'resolver_version',
            'review_manifest_hash',
            'evidence_hash',
            'reason',
        ];
        foreach (array_chunk($upserts, 40) as $chunk) {
            $valueSql = implode(', ', array_fill(
                0,
                count($chunk),
                '(' . implode(', ', array_fill(
                    0,
                    count($columns),
                    '?'
                )) . ', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            ));
            $values = [];
            foreach ($chunk as $desired) {
                foreach ($columns as $column) {
                    $values[] = $desired[$column];
                }
            }
            $upsert = $db->prepare("
                INSERT INTO ingredient_ontology_recipe_identity_annex (
                    " . implode(', ', $columns) . ",
                    created_at, updated_at
                )
                VALUES {$valueSql}
                ON CONFLICT(recipe_ingredient_id) DO UPDATE SET
                    ontology_version_id = excluded.ontology_version_id,
                    ontology_content_hash =
                        excluded.ontology_content_hash,
                    ontology_seal_hash = excluded.ontology_seal_hash,
                    owner_fingerprint = excluded.owner_fingerprint,
                    source_label = excluded.source_label,
                    normalized_label = excluded.normalized_label,
                    language = excluded.language,
                    label_id = excluded.label_id,
                    entity_id = excluded.entity_id,
                    status = excluded.status,
                    confidence = excluded.confidence,
                    admission_source = excluded.admission_source,
                    attributes_json = excluded.attributes_json,
                    resolver_version = excluded.resolver_version,
                    review_manifest_hash =
                        excluded.review_manifest_hash,
                    evidence_hash = excluded.evidence_hash,
                    reason = excluded.reason,
                    updated_at = CURRENT_TIMESTAMP
            ");
            $upsert->execute($values);
            $upsertStatementCount++;
        }
        if ($resolveCoverageGaps) {
            $coverageResolvedCount =
                ingredientOntologyV3RecipeAnnexResolveCoverageForRecipes(
                    $db,
                    $recipeIds,
                    $version
                );
        }
        $db->exec("RELEASE SAVEPOINT {$savepoint}");
    } catch (Throwable $error) {
        try {
            $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
    return [
        'recipe_ids' => $recipeIds,
        'recipe_count' => count($recipeIds),
        'ingredient_count' => count($rows),
        'terminal_count' => $terminal,
        'accepted_count' => $accepted,
        'changed_row_count' => count($upserts) + count($deleteIds),
        'unchanged_row_count' => $unchanged,
        'upserted_row_count' => count($upserts),
        'deleted_row_count' => count($deleteIds),
        'write_statement_count' =>
            $upsertStatementCount + $deleteStatementCount,
        'upsert_statement_count' => $upsertStatementCount,
        'delete_statement_count' => $deleteStatementCount,
        'resolution_query_count' => count($resolutionCache),
        'coverage_resolved_count' => $coverageResolvedCount,
        'transaction_count' => 1,
        'ready' => $terminal === count($rows),
        'recipes' => $perRecipe,
    ];
}

function ingredientOntologyV3RecipeAnnexRefreshRecipe(
    PDO $db,
    int $recipeId,
    int $versionId,
    bool $resolveCoverageGaps = true
): array {
    $batch = ingredientOntologyV3RecipeAnnexRefreshBatch(
        $db,
        [$recipeId],
        $versionId,
        $resolveCoverageGaps
    );
    return ($batch['recipes'][$recipeId] ?? [
        'recipe_id' => $recipeId,
        'ingredient_count' => 0,
        'terminal_count' => 0,
        'accepted_count' => 0,
        'changed_row_count' => 0,
        'unchanged_row_count' => 0,
        'ready' => true,
    ]) + [
        'write_statement_count' =>
            (int)$batch['write_statement_count'],
        'transaction_count' => (int)$batch['transaction_count'],
    ];
}

function ingredientOntologyV3IdentityAnnexTableExists(PDO $db): bool {
    return ingredientOntologyV3TableExists(
        $db,
        'ingredient_ontology_identity_annex'
    );
}

function ingredientOntologyV3IdentityAnnexLabelCandidates(
    PDO $db,
    int $versionId,
    string $normalizedLabel,
    string $language
): array {
    $stmt = $db->prepare("
        SELECT label.id AS label_id, label.entity_id,
               label.normalized_label, label.language,
               label.kind, label.provenance, label.source_ref,
               entity.slug AS entity_slug,
               entity.canonical_name AS entity_name,
               policy.required_cohort,
               policy.required_evidence_kind,
               policy.required_evidence_key,
               facet.facet_key, value.value_key,
               attribute.is_defining
        FROM ingredient_ontology_labels label
        JOIN ingredient_ontology_entities entity
          ON entity.id = label.entity_id
         AND entity.ontology_version_id = label.ontology_version_id
        LEFT JOIN ingredient_ontology_label_context_policies policy
          ON policy.label_id = label.id
        LEFT JOIN ingredient_ontology_label_attributes attribute
          ON attribute.label_id = label.id
        LEFT JOIN ingredient_ontology_facets facet
          ON facet.id = attribute.facet_id
        LEFT JOIN ingredient_ontology_facet_values value
          ON value.id = attribute.facet_value_id
        WHERE label.ontology_version_id = ?
          AND label.normalized_label = ?
          AND label.review_state = 'accepted'
          AND label.kind IN ('exact_alias', 'attribute_alias')
          AND entity.active = 1
          AND entity.entity_kind = 'ingredient'
          AND entity.identity_role = 'identity_leaf'
          AND entity.provenance <> 'autonomous_controller'
          AND entity.slug NOT LIKE 'provisional-subject-%'
        ORDER BY label.id, facet.facet_key
    ");
    $stmt->execute([$versionId, $normalizedLabel]);
    $byLabel = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $labelId = (int)$row['label_id'];
        if (!isset($byLabel[$labelId])) {
            $candidateLanguage = ingredientOntologyV3NormalizeLanguage(
                (string)$row['language']
            );
            if (
                !ingredientOntologyV3LanguageMatches(
                    $candidateLanguage,
                    $language
                )
                || !ingredientOntologyV3AcceptedLabelProvenanceAllowed(
                    (string)$row['provenance']
                )
                || trim((string)($row['required_cohort'] ?? '')) !== ''
                || trim(
                    (string)($row['required_evidence_kind'] ?? '')
                ) !== ''
                || trim(
                    (string)($row['required_evidence_key'] ?? '')
                ) !== ''
            ) {
                continue;
            }
            $requestedLanguage = ingredientOntologyV3NormalizeLanguage(
                $language
            );
            $byLabel[$labelId] = [
                'label_id' => $labelId,
                'entity_id' => (int)$row['entity_id'],
                'normalized_label' =>
                    (string)$row['normalized_label'],
                'language' => $candidateLanguage,
                'kind' => (string)$row['kind'],
                'provenance' => (string)$row['provenance'],
                'source_ref' => $row['source_ref'] !== null
                    ? (string)$row['source_ref']
                    : null,
                'entity_slug' => (string)$row['entity_slug'],
                'entity_name' => (string)$row['entity_name'],
                'attributes' => [],
                'language_rank' => $candidateLanguage === $requestedLanguage
                    ? 2
                    : 1,
            ];
        }
        if (
            isset($byLabel[$labelId])
            && $row['facet_key'] !== null
            && $row['value_key'] !== null
            && !empty($row['is_defining'])
        ) {
            $byLabel[$labelId]['attributes'][
                (string)$row['facet_key']
            ] = (string)$row['value_key'];
        }
    }
    foreach ($byLabel as &$candidate) {
        ksort($candidate['attributes'], SORT_STRING);
    }
    unset($candidate);
    usort(
        $byLabel,
        static fn(array $left, array $right): int =>
            $right['language_rank'] <=> $left['language_rank']
                ?: $left['label_id'] <=> $right['label_id']
    );
    return array_values($byLabel);
}

function ingredientOntologyV3IdentityAnnexResolution(
    PDO $db,
    array $version,
    array $product
): array {
    $normalizedLabel = ingredientOntologyV3NormalizeLabel(
        (string)$product['name']
    );
    $language = 'en';
    if (!empty($product['prepared_food'])) {
        return [
            'status' => 'rejected',
            'reason' => 'prepared_food',
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'attributes' => [],
            'normalized_label' => $normalizedLabel,
            'language' => $language,
        ];
    }
    if ($normalizedLabel === '') {
        return [
            'status' => 'unresolved',
            'reason' => 'empty_label',
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'attributes' => [],
            'normalized_label' => '',
            'language' => $language,
        ];
    }

    $candidates = ingredientOntologyV3IdentityAnnexLabelCandidates(
        $db,
        (int)$version['id'],
        $normalizedLabel,
        $language
    );
    $admissionSource = 'accepted_label';
    $review = null;
    if (!$candidates) {
        $review = ingredientOntologyV3IdentityAnnexReviewedAliases()[
            $normalizedLabel
        ] ?? null;
        if ($review !== null) {
            $candidates = ingredientOntologyV3IdentityAnnexLabelCandidates(
                $db,
                (int)$version['id'],
                (string)$review['target_normalized_label'],
                (string)$review['target_language']
            );
            $candidates = array_values(array_filter(
                $candidates,
                static fn(array $candidate): bool =>
                    (string)$candidate['entity_slug']
                        === (string)$review['target_entity_slug']
                    && (string)$candidate['kind']
                        === (string)$review['target_kind']
            ));
            $admissionSource = 'reviewed_alias';
        }
    }

    $entities = [];
    foreach ($candidates as $candidate) {
        $entities[(int)$candidate['entity_id']] = true;
    }
    if (!$candidates) {
        return [
            'status' => 'unresolved',
            'reason' => 'no_reviewed_exact_alias',
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'attributes' => [],
            'normalized_label' => $normalizedLabel,
            'language' => $language,
        ];
    }
    if (count($entities) !== 1) {
        return [
            'status' => 'rejected',
            'reason' => 'reviewed_alias_collision',
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'attributes' => [],
            'normalized_label' => $normalizedLabel,
            'language' => $language,
        ];
    }

    $candidate = $candidates[0];
    return [
        'status' => 'accepted',
        'reason' => $admissionSource,
        'admission_source' => $admissionSource,
        'label_id' => (int)$candidate['label_id'],
        'entity_id' => (int)$candidate['entity_id'],
        'entity_slug' => (string)$candidate['entity_slug'],
        'entity_name' => (string)$candidate['entity_name'],
        'attributes' => (array)$candidate['attributes'],
        'normalized_label' => $normalizedLabel,
        'language' => $language,
        'label' => $candidate,
        'review' => $review,
    ];
}

function ingredientOntologyV3IdentityAnnexRefreshProduct(
    PDO $db,
    int $productId,
    ?int $versionId = null,
    bool $resolveCoverageGaps = true
): array {
    if (
        $productId <= 0
        || !ingredientOntologyV3IdentityAnnexTableExists($db)
    ) {
        return [
            'available' => false,
            'accepted' => false,
            'changed' => false,
            'reason' => 'identity_annex_unavailable',
        ];
    }
    $productStmt = $db->prepare("
        SELECT id, name, brand, category, prepared_food
        FROM products
        WHERE id = ?
    ");
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($product === null) {
        throw new InvalidArgumentException(
            'identity annex product is unavailable'
        );
    }
    $version = $versionId !== null
        ? ingredientOntologyV3Version($db, $versionId)
        : ingredientOntologyV3ActiveVersion($db);
    if (
        $version === null
        || (string)$version['status'] !== 'ready'
        || !is_string($version['content_hash'] ?? null)
        || !is_string($version['seal_hash'] ?? null)
    ) {
        $db->prepare("
            DELETE FROM ingredient_ontology_identity_annex
            WHERE product_id = ?
        ")->execute([$productId]);
        return [
            'available' => false,
            'accepted' => false,
            'changed' => true,
            'reason' => 'active_ontology_unavailable',
        ];
    }

    $ownerFingerprint =
        ingredientOntologyV3ProductOwnerFingerprint($product);
    $resolution = ingredientOntologyV3IdentityAnnexResolution(
        $db,
        $version,
        $product
    );
    $persistedNormalizedLabel = mb_substr(
        (string)$resolution['normalized_label'],
        0,
        200,
        'UTF-8'
    );
    $reviewManifestHash =
        ingredientOntologyV3IdentityAnnexReviewManifestHash();
    $attributes = (array)($resolution['attributes'] ?? []);
    ksort($attributes, SORT_STRING);
    $evidence = [
        'resolver_version' =>
            INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
        'review_manifest_hash' => $reviewManifestHash,
        'ontology_version_id' => (int)$version['id'],
        'ontology_content_hash' => (string)$version['content_hash'],
        'ontology_seal_hash' => (string)$version['seal_hash'],
        'product_id' => $productId,
        'owner_fingerprint' => $ownerFingerprint,
        'source_label' => (string)$product['name'],
        'normalized_label' => $persistedNormalizedLabel,
        'language' => (string)$resolution['language'],
        'status' => (string)$resolution['status'],
        'admission_source' =>
            (string)$resolution['admission_source'],
        'label_id' => $resolution['label_id'],
        'entity_id' => $resolution['entity_id'],
        'attributes' => $attributes,
        'review' => $resolution['review'] ?? null,
    ];
    $evidenceHash = ingredientOntologyV3Hash($evidence);
    $previousStmt = $db->prepare("
        SELECT owner_fingerprint, ontology_version_id,
               status, entity_id, evidence_hash
        FROM ingredient_ontology_identity_annex
        WHERE product_id = ?
    ");
    $previousStmt->execute([$productId]);
    $previous = $previousStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $db->prepare("
        INSERT INTO ingredient_ontology_identity_annex (
            product_id, ontology_version_id,
            ontology_content_hash, ontology_seal_hash,
            owner_fingerprint, source_label, normalized_label,
            language, label_id, entity_id, status,
            admission_source, attributes_json,
            resolver_version, review_manifest_hash,
            evidence_hash, reason, created_at, updated_at
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )
        ON CONFLICT(product_id) DO UPDATE SET
            ontology_version_id = excluded.ontology_version_id,
            ontology_content_hash = excluded.ontology_content_hash,
            ontology_seal_hash = excluded.ontology_seal_hash,
            owner_fingerprint = excluded.owner_fingerprint,
            source_label = excluded.source_label,
            normalized_label = excluded.normalized_label,
            language = excluded.language,
            label_id = excluded.label_id,
            entity_id = excluded.entity_id,
            status = excluded.status,
            admission_source = excluded.admission_source,
            attributes_json = excluded.attributes_json,
            resolver_version = excluded.resolver_version,
            review_manifest_hash = excluded.review_manifest_hash,
            evidence_hash = excluded.evidence_hash,
            reason = excluded.reason,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([
        $productId,
        (int)$version['id'],
        (string)$version['content_hash'],
        (string)$version['seal_hash'],
        $ownerFingerprint,
        mb_substr((string)$product['name'], 0, 200, 'UTF-8'),
        $persistedNormalizedLabel,
        (string)$resolution['language'],
        $resolution['label_id'],
        $resolution['entity_id'],
        (string)$resolution['status'],
        (string)$resolution['admission_source'],
        ingredientOntologyV3Json($attributes),
        INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
        $reviewManifestHash,
        $evidenceHash,
        mb_substr((string)$resolution['reason'], 0, 240, 'UTF-8'),
    ]);

    $previousEntityId = $previous !== null
        && $previous['entity_id'] !== null
            ? (int)$previous['entity_id']
            : null;
    $changed = $previous === null
        || (int)$previous['ontology_version_id'] !== (int)$version['id']
        || !hash_equals(
            (string)$previous['owner_fingerprint'],
            $ownerFingerprint
        )
        || (string)$previous['status'] !== (string)$resolution['status']
        || $previousEntityId !== $resolution['entity_id']
        || !hash_equals(
            (string)$previous['evidence_hash'],
            $evidenceHash
        );
    if (
        $resolveCoverageGaps
        &&
        (string)$resolution['status'] === 'accepted'
        && function_exists(
            'ingredientOntologyControllerResolveCoverageGaps'
        )
    ) {
        $subject = $db->prepare("
            SELECT subject_id
            FROM ontology_subject_occurrences
            WHERE owner_type = 'product'
              AND owner_id = ?
              AND owner_fingerprint = ?
              AND active = 1
            ORDER BY id DESC
            LIMIT 1
        ");
        $subject->execute([$productId, $ownerFingerprint]);
        $subjectId = (int)($subject->fetchColumn() ?: 0);
        if ($subjectId > 0) {
            ingredientOntologyControllerResolveCoverageGaps(
                $db,
                $subjectId
            );
        }
    }
    return [
        'available' => true,
        'accepted' => (string)$resolution['status'] === 'accepted',
        'changed' => $changed,
        'product_id' => $productId,
        'owner_fingerprint' => $ownerFingerprint,
        'ontology_version_id' => (int)$version['id'],
        'label_id' => $resolution['label_id'],
        'entity_id' => $resolution['entity_id'],
        'previous_entity_id' => $previousEntityId,
        'entity_slug' => $resolution['entity_slug'] ?? null,
        'attributes' => $attributes,
        'status' => (string)$resolution['status'],
        'source' => (string)$resolution['admission_source'],
        'reason' => (string)$resolution['reason'],
        'evidence_hash' => $evidenceHash,
    ];
}

function ingredientOntologyV3IdentityAnnexMapping(
    PDO $db,
    int $versionId,
    int $productId,
    string $ownerFingerprint
): ?array {
    if (!ingredientOntologyV3IdentityAnnexTableExists($db)) {
        return null;
    }
    $stmt = $db->prepare("
        SELECT annex.id AS annex_id, annex.owner_fingerprint,
               annex.source_label, annex.attributes_json,
               annex.label_id, annex.entity_id,
               annex.admission_source, annex.evidence_hash,
               entity.slug AS entity_slug,
               entity.canonical_name AS entity_name,
               (
                   SELECT occurrence.subject_id
                   FROM ontology_subject_occurrences occurrence
                   WHERE occurrence.owner_type = 'product'
                     AND occurrence.owner_id = annex.product_id
                     AND occurrence.owner_fingerprint =
                         annex.owner_fingerprint
                     AND occurrence.active = 1
                   ORDER BY occurrence.id DESC
                   LIMIT 1
               ) AS subject_id
        FROM ingredient_ontology_identity_annex annex
        JOIN ingredient_ontology_versions version
          ON version.id = annex.ontology_version_id
         AND version.status = 'ready'
         AND version.content_hash = annex.ontology_content_hash
         AND version.seal_hash = annex.ontology_seal_hash
        JOIN ingredient_ontology_entities entity
          ON entity.id = annex.entity_id
         AND entity.ontology_version_id = annex.ontology_version_id
         AND entity.active = 1
         AND entity.entity_kind = 'ingredient'
         AND entity.identity_role = 'identity_leaf'
        JOIN ingredient_ontology_labels label
          ON label.id = annex.label_id
         AND label.ontology_version_id = annex.ontology_version_id
         AND label.entity_id = annex.entity_id
         AND label.review_state = 'accepted'
         AND label.kind IN ('exact_alias', 'attribute_alias')
        WHERE annex.product_id = ?
          AND annex.ontology_version_id = ?
          AND annex.owner_fingerprint = ?
          AND annex.status = 'accepted'
          AND annex.resolver_version = ?
          AND annex.review_manifest_hash = ?
        LIMIT 1
    ");
    $stmt->execute([
        $productId,
        $versionId,
        $ownerFingerprint,
        INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
        ingredientOntologyV3IdentityAnnexReviewManifestHash(),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $attributes = json_decode(
        (string)$row['attributes_json'],
        true
    );
    $attributes = is_array($attributes) ? $attributes : [];
    $normalizedAttributes = [];
    foreach ($attributes as $facet => $value) {
        if (!is_string($facet) || !is_string($value)) {
            continue;
        }
        $normalizedAttributes[$facet] = [
            'value' => $value,
            'is_defining' =>
                ingredientOntologyV3FacetIsDefining($facet),
            'source' => 'reviewed_identity_annex',
        ];
    }
    ksort($normalizedAttributes, SORT_STRING);
    return [
        'mapping_id' => null,
        'annex_id' => (int)$row['annex_id'],
        'owner_fingerprint' => (string)$row['owner_fingerprint'],
        'subject_id' => $row['subject_id'] !== null
            ? (int)$row['subject_id']
            : null,
        'entity_id' => (int)$row['entity_id'],
        'entity_slug' => (string)$row['entity_slug'],
        'entity_name' => (string)$row['entity_name'],
        'status' => 'accepted',
        'confidence' => 1.0,
        'mapping_source' => 'deterministic_identity_annex',
        'source_label' => (string)$row['source_label'],
        'attributes' => $normalizedAttributes,
        'is_staple' => false,
        'label_id' => (int)$row['label_id'],
        'evidence_hash' => (string)$row['evidence_hash'],
        'admission_source' => (string)$row['admission_source'],
    ];
}

function ingredientOntologyV3IdentityAnnexResolvedMapping(
        PDO $db,
        array $version,
        array $product,
        array $resolution
    ): ?array {
        if (
            (string)($version['status'] ?? '') !== 'ready'
            || (string)($resolution['status'] ?? '') !== 'accepted'
            || (int)($resolution['label_id'] ?? 0) <= 0
            || (int)($resolution['entity_id'] ?? 0) <= 0
            || !is_string($version['content_hash'] ?? null)
            || !is_string($version['seal_hash'] ?? null)
        ) {
            return null;
        }
        $ownerFingerprint =
            ingredientOntologyV3ProductOwnerFingerprint($product);
        $subjectStmt = $db->prepare("
            SELECT subject_id
            FROM ontology_subject_occurrences
            WHERE owner_type = 'product'
              AND owner_id = ?
              AND owner_fingerprint = ?
              AND active = 1
            ORDER BY id DESC
            LIMIT 1
        ");
        $subjectStmt->execute([
            (int)$product['id'],
            $ownerFingerprint,
        ]);
        $subjectId = $subjectStmt->fetchColumn();
        $attributes = (array)($resolution['attributes'] ?? []);
        ksort($attributes, SORT_STRING);
        $normalizedAttributes = [];
        foreach ($attributes as $facet => $value) {
            if (!is_string($facet) || !is_string($value)) {
                continue;
            }
            $normalizedAttributes[$facet] = [
                'value' => $value,
                'is_defining' =>
                    ingredientOntologyV3FacetIsDefining($facet),
                'source' => 'reviewed_identity_annex',
            ];
        }
        $evidenceHash = ingredientOntologyV3Hash([
            'resolver_version' =>
                INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
            'review_manifest_hash' =>
                ingredientOntologyV3IdentityAnnexReviewManifestHash(),
            'ontology_version_id' => (int)$version['id'],
            'ontology_content_hash' => (string)$version['content_hash'],
            'ontology_seal_hash' => (string)$version['seal_hash'],
            'product_id' => (int)$product['id'],
            'owner_fingerprint' => $ownerFingerprint,
            'label_id' => (int)$resolution['label_id'],
            'entity_id' => (int)$resolution['entity_id'],
            'attributes' => $attributes,
            'admission_source' =>
                (string)$resolution['admission_source'],
        ]);
        return [
            'mapping_id' => null,
            'annex_id' => null,
            'owner_fingerprint' => $ownerFingerprint,
            'subject_id' => $subjectId !== false
                ? (int)$subjectId
                : null,
            'entity_id' => (int)$resolution['entity_id'],
            'entity_slug' => (string)$resolution['entity_slug'],
            'entity_name' => (string)$resolution['entity_name'],
            'status' => 'accepted',
            'confidence' => 1.0,
            'mapping_source' => 'deterministic_identity_annex_read',
            'source_label' => (string)$product['name'],
            'attributes' => $normalizedAttributes,
            'is_staple' => false,
            'label_id' => (int)$resolution['label_id'],
            'evidence_hash' => $evidenceHash,
            'admission_source' =>
                (string)$resolution['admission_source'],
        ];
}
