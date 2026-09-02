<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/food_identity_text.php';

const INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION =
    'identity-annex-r0-v5';
const INGREDIENT_ONTOLOGY_PRODUCT_IDENTITY_ANNEX_RESOLVER_VERSION =
    'identity-annex-product-r0-v6';
const INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_REVIEW_VERSION =
    'operator-reviewed-aliases-2026-08-18-v2';
const INGREDIENT_ONTOLOGY_IDENTITY_EXTENSION_NORMALIZER_VERSION =
    'identity-exact-lexeme-v2';
const INGREDIENT_ONTOLOGY_PRODUCT_IDENTITY_MIGRATION_BATCH_SIZE = 25;
const INGREDIENT_ONTOLOGY_RECIPE_IDENTITY_MIGRATION_BATCH_SIZE = 250;
const INGREDIENT_ONTOLOGY_PRODUCT_READINESS_MAX_ATTEMPTS = 4;
const INGREDIENT_ONTOLOGY_PRODUCT_READINESS_DEADLINE_SECONDS = 30;
const INGREDIENT_ONTOLOGY_PRODUCT_READINESS_MAX_RETRY_SECONDS = 3600;

function ingredientOntologyV3IdentityFeatureEnabled(
    string $environmentKey,
    string $testOverrideKey,
    bool $default = false
): bool {
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && array_key_exists($testOverrideKey, $GLOBALS)
    ) {
        return !empty($GLOBALS[$testOverrideKey]);
    }
    $raw = function_exists('env')
        ? env($environmentKey, $default ? 'true' : 'false')
        : (
            getenv($environmentKey) !== false
                ? getenv($environmentKey)
                : ($default ? 'true' : 'false')
        );
    return in_array(
        strtolower(trim((string)$raw)),
        ['1', 'true', 'yes', 'on'],
        true
    );
}

function ingredientOntologyV3ExactSelfIdentityEnabled(): bool {
    return ingredientOntologyV3IdentityFeatureEnabled(
        'INGREDIENT_ONTOLOGY_EXACT_SELF_IDENTITY_ENABLED',
        'INGREDIENT_ONTOLOGY_EXACT_SELF_IDENTITY_ENABLED_OVERRIDE',
        true
    );
}

function ingredientOntologyV3IdentityRoleWideningEnabled(): bool {
    return ingredientOntologyV3IdentityFeatureEnabled(
        'INGREDIENT_ONTOLOGY_IDENTITY_ROLE_WIDENING_ENABLED',
        'INGREDIENT_ONTOLOGY_IDENTITY_ROLE_WIDENING_ENABLED_OVERRIDE'
    );
}

function ingredientOntologyV3IdentityReadinessV2Enabled(): bool {
    return ingredientOntologyV3IdentityFeatureEnabled(
        'INGREDIENT_ONTOLOGY_IDENTITY_READINESS_V2_ENABLED',
        'INGREDIENT_ONTOLOGY_IDENTITY_READINESS_V2_ENABLED_OVERRIDE',
        true
    );
}

function ingredientOntologyV3IdentityLanguageKey(
    string $language
): string {
    $language = ingredientOntologyV3NormalizeLanguage($language);
    return $language === 'und'
        ? 'und'
        : explode('-', $language, 2)[0];
}

function ingredientOntologyV3IdentityOrthographicLabel(
    string $sourceLabel
): string {
    return ingredientOntologyV3NormalizeLabel(
        foodIdentityNormalizePossessiveOrthography($sourceLabel)
    );
}

function ingredientOntologyV3ProductIdentityLanguage(): string {
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && array_key_exists(
            'INGREDIENT_ONTOLOGY_PRODUCT_LANGUAGE_OVERRIDE',
            $GLOBALS
        )
    ) {
        return ingredientOntologyV3IdentityLanguageKey(
            (string)$GLOBALS[
                'INGREDIENT_ONTOLOGY_PRODUCT_LANGUAGE_OVERRIDE'
            ]
        );
    }
    $defaultLanguage = 'en';
    $raw = function_exists('env')
        ? env(
            'INGREDIENT_ONTOLOGY_PRODUCT_LANGUAGE',
            $defaultLanguage
        )
        : (
            getenv('INGREDIENT_ONTOLOGY_PRODUCT_LANGUAGE')
                ?: $defaultLanguage
        );
    return ingredientOntologyV3IdentityLanguageKey((string)$raw);
}

function ingredientOntologyV3ProductIdentityResolverVersion(): string {
    return implode(':', [
        INGREDIENT_ONTOLOGY_PRODUCT_IDENTITY_ANNEX_RESOLVER_VERSION,
        ingredientOntologyV3ExactSelfIdentityEnabled()
            ? 'exact-on'
            : 'exact-off',
        ingredientOntologyV3IdentityRoleWideningEnabled()
            ? 'roles-on'
            : 'roles-off',
        ingredientOntologyV3IdentityReadinessV2Enabled()
            ? 'readiness-v2'
            : 'readiness-v1',
        ingredientOntologyV3ProductIdentityLanguage(),
    ]);
}

function ingredientOntologyV3RecipeIdentityResolverVersion(): string {
    return implode(':', [
        INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
        ingredientOntologyV3ExactSelfIdentityEnabled()
            ? 'exact-on'
            : 'exact-off',
        ingredientOntologyV3IdentityRoleWideningEnabled()
            ? 'roles-on'
            : 'roles-off',
    ]);
}

function ingredientOntologyV3IdentityEligibleRolesSql(): string {
    return ingredientOntologyV3IdentityRoleWideningEnabled()
        ? "('identity_leaf', 'prepared_identity', 'composite_identity')"
        : "('identity_leaf')";
}

function ingredientOntologyV3IdentityExtensionZeroHash(): string {
    return str_repeat('0', 64);
}

function ingredientOntologyV3IdentityExtensionRuntimeEntityId(
    int $extensionEntityId
): int {
    if ($extensionEntityId <= 0) {
        throw new InvalidArgumentException(
            'identity extension entity id must be positive'
        );
    }
    return -$extensionEntityId;
}

function ingredientOntologyV3IdentityExtensionDatabaseId(
    int $runtimeEntityId
): ?int {
    return $runtimeEntityId < 0 ? -$runtimeEntityId : null;
}

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
                ingredientOntologyV3ProductIdentityResolverVersion(),
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

function ingredientOntologyV3IdentityAdmissionMigrateProductBatch(
    PDO $db,
    int $limit =
        INGREDIENT_ONTOLOGY_PRODUCT_IDENTITY_MIGRATION_BATCH_SIZE
): array {
    if (
        !ingredientOntologyV3TableExists($db, 'recipe_score_state')
        || !ingredientOntologyV3TableExists(
            $db,
            'recipe_score_pending_products'
        )
        || !ingredientOntologyV3IdentityAnnexTableExists($db)
    ) {
        return [
            'available' => false,
            'processed' => 0,
            'changed_product_ids' => [],
            'backfilled_product_ids' => [],
            'remaining' => 0,
        ];
    }
    $activeScore = recipeScoreActiveRevision($db);
    if (
        $activeScore === null
        || $activeScore['ontology_version_id'] === null
    ) {
        return [
            'available' => false,
            'processed' => 0,
            'changed_product_ids' => [],
            'backfilled_product_ids' => [],
            'remaining' => 0,
        ];
    }
    $limit = max(1, min(100, $limit));
    $versionId = (int)$activeScore['ontology_version_id'];
    $manifestHash =
        ingredientOntologyV3IdentityAnnexReviewManifestHash();
    $products = $db->prepare("
        SELECT product.id
        FROM products product
        LEFT JOIN ingredient_ontology_identity_annex annex
          ON annex.product_id = product.id
        WHERE EXISTS (
            SELECT 1
            FROM inventory stock
            WHERE stock.product_id = product.id
              AND stock.quantity > 0
        )
          AND (
              annex.product_id IS NULL
              OR annex.ontology_version_id <> ?
              OR COALESCE(annex.resolver_version, '') <> ?
              OR COALESCE(annex.review_manifest_hash, '') <> ?
          )
        ORDER BY product.id
        LIMIT {$limit}
    ");
    $products->execute([
        $versionId,
        ingredientOntologyV3ProductIdentityResolverVersion(),
        $manifestHash,
    ]);
    $productIds = array_map(
        'intval',
        $products->fetchAll(PDO::FETCH_COLUMN)
    );
    if (!$productIds) {
        return [
            'available' => true,
            'processed' => 0,
            'changed_product_ids' => [],
            'backfilled_product_ids' => [],
            'remaining' => 0,
        ];
    }
    $changed = [];
    $backfilled = [];
    foreach ($productIds as $productId) {
        $nested = databaseTransactionIsActive($db);
        $savepoint = 'identity_product_migration_' . $productId;
        if ($nested) {
            $db->exec("SAVEPOINT {$savepoint}");
        } else {
            dbBeginImmediateWithRetry($db);
        }
        try {
            $currentActive = recipeScoreActiveRevision($db);
            if (
                $currentActive === null
                || (int)($currentActive['id'] ?? 0)
                    !== (int)$activeScore['id']
                || (int)($currentActive['ontology_version_id'] ?? 0)
                    !== $versionId
            ) {
                throw new RuntimeException(
                    'product identity migration score fence changed'
                );
            }
            $refreshed =
                ingredientOntologyV3IdentityAdmissionPublishProduct(
                    $db,
                    $productId,
                    $versionId,
                    'product_identity_resolver_migration',
                    false
                );
            if (!empty($refreshed['semantic_changed'])) {
                    $pending = $db->prepare("
                        SELECT latest_inventory_revision
                        FROM recipe_score_pending_products
                        WHERE product_id = ?
                    ");
                    $pending->execute([$productId]);
                    if ((int)($pending->fetchColumn() ?: 0) <= 0) {
                        recipeScoreMarkProductDirty(
                            $db,
                            $productId,
                            'product_identity_resolver_migration'
                        );
                    }
                    ingredientOntologyV3IdentityAdmissionPublishProduct(
                    $db,
                    $productId,
                    $versionId,
                    'product_identity_resolver_migration',
                    true
                );
                $changed[] = $productId;
            } elseif (
                !empty($refreshed['accepted'])
                && ingredientOntologyV3ProductReadinessBackfillReady(
                    $db,
                    $refreshed,
                    $activeScore
                )
            ) {
                $backfilled[] = $productId;
            }
            $db->exec(
                $nested
                    ? "RELEASE SAVEPOINT {$savepoint}"
                    : 'COMMIT'
            );
        } catch (Throwable $error) {
            if ($nested) {
                try {
                    $db->exec(
                        "ROLLBACK TO SAVEPOINT {$savepoint}"
                    );
                    $db->exec(
                        "RELEASE SAVEPOINT {$savepoint}"
                    );
                } catch (Throwable $ignored) {
                }
            } else {
                try {
                    $db->exec('ROLLBACK');
                } catch (Throwable $ignored) {
                }
            }
            throw $error;
        }
    }
    $remaining = $db->prepare("
        SELECT COUNT(*)
        FROM products product
        LEFT JOIN ingredient_ontology_identity_annex annex
          ON annex.product_id = product.id
        WHERE EXISTS (
            SELECT 1
            FROM inventory stock
            WHERE stock.product_id = product.id
              AND stock.quantity > 0
        )
          AND (
              annex.product_id IS NULL
              OR annex.ontology_version_id <> ?
              OR COALESCE(annex.resolver_version, '') <> ?
              OR COALESCE(annex.review_manifest_hash, '') <> ?
          )
    ");
    $remaining->execute([
        $versionId,
        ingredientOntologyV3ProductIdentityResolverVersion(),
        $manifestHash,
    ]);
    return [
        'available' => true,
        'processed' => count($productIds),
        'changed_product_ids' => $changed,
        'backfilled_product_ids' => $backfilled,
        'remaining' => (int)$remaining->fetchColumn(),
    ];
}

function ingredientOntologyV3IdentityAdmissionMigrateRecipeBatch(
    PDO $db,
    int $limit =
        INGREDIENT_ONTOLOGY_RECIPE_IDENTITY_MIGRATION_BATCH_SIZE
): array {
    if (
        !ingredientOntologyV3TableExists($db, 'recipe_score_state')
        || !ingredientOntologyV3TableExists(
            $db,
            'ingredient_ontology_recipe_identity_annex'
        )
    ) {
        return [
            'available' => false,
            'processed' => 0,
            'changed_row_count' => 0,
            'remaining' => 0,
        ];
    }
    $activeScore = recipeScoreActiveRevision($db);
    if (
        $activeScore === null
        || $activeScore['ontology_version_id'] === null
    ) {
        return [
            'available' => false,
            'processed' => 0,
            'changed_row_count' => 0,
            'remaining' => 0,
        ];
    }
    $versionId = (int)$activeScore['ontology_version_id'];
    $version = ingredientOntologyV3Version($db, $versionId);
    if ($version === null || (string)$version['status'] !== 'ready') {
        return [
            'available' => false,
            'processed' => 0,
            'changed_row_count' => 0,
            'remaining' => 0,
        ];
    }
    $limit = max(1, min(1000, $limit));
    $refreshWhere = "
        (
            annex.recipe_ingredient_id IS NOT NULL
            AND (
                annex.ontology_version_id <> ?
                OR annex.ontology_content_hash <> ?
                OR annex.ontology_seal_hash <> ?
                OR annex.resolver_version <> ?
                OR annex.review_manifest_hash <> ?
            )
        )
        OR (
            annex.recipe_ingredient_id IS NULL
            AND (
                COALESCE(mapping.status, '') <> 'accepted'
                OR (
                    mapping.status = 'accepted'
                    AND (
                        (
                            LOWER(REPLACE(
                                mapping.language, '_', '-'
                            )) = 'und'
                            AND recipe.primary_connector = 'cookidoo'
                            AND (
                                TRIM(COALESCE(
                                    origin.content_language, ''
                                )) <> ''
                                OR TRIM(COALESCE(
                                    origin.locale, ''
                                )) <> ''
                            )
                        )
                    )
                )
            )
        )
    ";
    $params = [
        $versionId,
        $versionId,
        (string)$version['content_hash'],
        (string)$version['seal_hash'],
        ingredientOntologyV3RecipeIdentityResolverVersion(),
        ingredientOntologyV3IdentityAnnexReviewManifestHash(),
    ];
    $recipes = $db->prepare("
        SELECT DISTINCT ingredient.recipe_id
        FROM recipe_ingredients ingredient
        JOIN recipe_catalog recipe
          ON recipe.id = ingredient.recipe_id
         AND recipe.deleted_at IS NULL
        LEFT JOIN ingredient_ontology_recipe_identity_annex annex
          ON annex.recipe_ingredient_id = ingredient.id
        LEFT JOIN ingredient_ontology_mappings mapping
          ON mapping.ontology_version_id = ?
         AND mapping.owner_type = 'recipe_ingredient'
         AND mapping.owner_id = ingredient.id
        LEFT JOIN recipe_origins origin
          ON origin.id = (
              SELECT candidate.id
              FROM recipe_origins candidate
              WHERE candidate.recipe_id = ingredient.recipe_id
                AND candidate.connector = recipe.primary_connector
              ORDER BY candidate.id
              LIMIT 1
          )
        WHERE {$refreshWhere}
        ORDER BY ingredient.recipe_id
        LIMIT {$limit}
    ");
    $recipes->execute($params);
    $recipeIds = array_map(
        'intval',
        $recipes->fetchAll(PDO::FETCH_COLUMN)
    );
    if (!$recipeIds) {
        return [
            'available' => true,
            'processed' => 0,
            'changed_row_count' => 0,
            'remaining' => 0,
        ];
    }
    $refreshed = ingredientOntologyV3RecipeAnnexRefreshBatch(
        $db,
        $recipeIds,
        $versionId
    );
    $changedRecipeIds = [];
    foreach (
        (array)($refreshed['recipes'] ?? []) as $recipeId => $recipe
    ) {
        if ((int)($recipe['changed_row_count'] ?? 0) > 0) {
            $changedRecipeIds[] = (int)$recipeId;
        }
    }
    sort($changedRecipeIds, SORT_NUMERIC);
    if (
        $changedRecipeIds
        && function_exists('recipeScoreMarkRecipesDirtyBatch')
    ) {
        recipeScoreMarkRecipesDirtyBatch(
            $db,
            $changedRecipeIds,
            'replace',
            'recipe_identity_resolver_migration',
            false,
            'maintenance'
        );
    }
    $remaining = $db->prepare("
        SELECT COUNT(DISTINCT ingredient.recipe_id)
        FROM recipe_ingredients ingredient
        JOIN recipe_catalog recipe
          ON recipe.id = ingredient.recipe_id
         AND recipe.deleted_at IS NULL
        LEFT JOIN ingredient_ontology_recipe_identity_annex annex
          ON annex.recipe_ingredient_id = ingredient.id
        LEFT JOIN ingredient_ontology_mappings mapping
          ON mapping.ontology_version_id = ?
         AND mapping.owner_type = 'recipe_ingredient'
         AND mapping.owner_id = ingredient.id
        LEFT JOIN recipe_origins origin
          ON origin.id = (
              SELECT candidate.id
              FROM recipe_origins candidate
              WHERE candidate.recipe_id = ingredient.recipe_id
                AND candidate.connector = recipe.primary_connector
              ORDER BY candidate.id
              LIMIT 1
          )
        WHERE {$refreshWhere}
    ");
    $remaining->execute($params);
    return [
        'available' => true,
        'processed' => count($recipeIds),
        'changed_row_count' =>
            (int)($refreshed['changed_row_count'] ?? 0),
        'changed_recipe_ids' => $changedRecipeIds,
        'remaining' => (int)$remaining->fetchColumn(),
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
            ingredientOntologyV3ProductIdentityResolverVersion(),
        'recipe_resolver_version' =>
            ingredientOntologyV3RecipeIdentityResolverVersion(),
        'aliases' => $aliases,
    ];
    $manifestJson = ingredientOntologyV3Json($manifest);
    $manifestHash =
        ingredientOntologyV3IdentityAnnexReviewManifestHash();
    $previous = json_decode(
        (string)($state['manifest_json'] ?? '{}'),
        true
    );
    $previous = is_array($previous) ? $previous : [];
    if (
        hash_equals(
            (string)$state['review_manifest_hash'],
            $manifestHash
        )
        && (string)$state['resolver_version']
            === ingredientOntologyV3ProductIdentityResolverVersion()
        && (string)($previous['recipe_resolver_version'] ?? '')
            === ingredientOntologyV3RecipeIdentityResolverVersion()
    ) {
        $migration =
            ingredientOntologyV3IdentityAdmissionMigrateProductBatch($db);
        $recipeMigration =
            ingredientOntologyV3IdentityAdmissionMigrateRecipeBatch($db);
        return $state + [
            'changed' => false,
            'changed_labels' => [],
            'resolver_migration' => $migration,
            'recipe_resolver_migration' => $recipeMigration,
        ];
    }
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
    $discoveryActiveScore = null;
    $resolverChanged = (string)($state['resolver_version'] ?? '')
        !== ingredientOntologyV3ProductIdentityResolverVersion();
    $recipeResolverChanged = (string)(
        $previous['recipe_resolver_version'] ?? ''
    ) !== ingredientOntologyV3RecipeIdentityResolverVersion();
    if (
        ($changedLabels || $resolverChanged || $recipeResolverChanged)
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
            SELECT product.id, product.name,
                   product.prepared_food,
                   annex.status AS annex_status
            FROM products product
            LEFT JOIN ingredient_ontology_identity_annex annex
              ON annex.product_id = product.id
            WHERE EXISTS (
                SELECT 1 FROM inventory stock
                WHERE stock.product_id = product.id
                  AND stock.quantity > 0
            )
            ORDER BY product.id
        ")->fetchAll(PDO::FETCH_ASSOC);
        $changedSet = array_fill_keys($changedLabels, true);
        foreach ($products as $product) {
            if (
                isset($changedSet[
                    ingredientOntologyV3NormalizeLabel(
                        (string)$product['name']
                    )
                ])
            ) {
                $productIds[] = (int)$product['id'];
            }
        }
        $discoveryActiveScore = recipeScoreActiveRevision($db);
        $discoveryActiveScoreId =
            (int)($discoveryActiveScore['id'] ?? 0);
        $versionId = (int)(
            $discoveryActiveScore['ontology_version_id'] ?? 0
        );
        if ($versionId > 0 && $changedLabels) {
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
            ingredientOntologyV3ProductIdentityResolverVersion(),
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
            ($changedLabels || $resolverChanged || $recipeResolverChanged)
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
                        recipe_id, operation, lane,
                        first_catalog_revision,
                        latest_catalog_revision,
                        latest_ontology_source_revision,
                        reason, created_at, updated_at
                    )
                    VALUES (
                        ?, 'replace', 'maintenance', ?, ?, ?,
                        'identity_admission_manifest_changed',
                        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                    ON CONFLICT(recipe_id) DO UPDATE SET
                        operation = 'replace',
                        lane = CASE
                            WHEN recipe_score_pending_recipes.lane =
                                'serving'
                            THEN 'serving'
                            ELSE 'maintenance'
                        END,
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
    $migration =
        ingredientOntologyV3IdentityAdmissionMigrateProductBatch($db);
    $recipeMigration =
        ingredientOntologyV3IdentityAdmissionMigrateRecipeBatch($db);
    $productIds = array_values(array_unique(array_merge(
        array_map('intval', $productIds),
        array_map(
            'intval',
            (array)($migration['changed_product_ids'] ?? [])
        )
    )));
    sort($productIds, SORT_NUMERIC);
    $current = ingredientOntologyV3IdentityAdmissionState($db);
    return $current + [
        'changed' => true,
        'changed_labels' => $changedLabels,
        'queued_product_ids' => $productIds,
        'queued_recipe_ids' => $recipeIds,
        'recipe_resolver_changed' => $recipeResolverChanged,
        'resolver_migration' => $migration,
        'recipe_resolver_migration' => $recipeMigration,
    ];
}

function ingredientOntologyV3IdentityExtensionSnapshot(
    PDO $db,
    int $versionId
): array {
    $zeroHash = ingredientOntologyV3IdentityExtensionZeroHash();
    if (
        $versionId <= 0
        || !ingredientOntologyV3TableExists(
            $db,
            'ingredient_ontology_identity_extension_state'
        )
    ) {
        return [
            'revision' => 0,
            'hash' => $zeroHash,
        ];
    }
    $stmt = $db->prepare("
        SELECT state.head_revision, state.head_hash,
               state.ontology_content_hash,
               state.ontology_seal_hash,
               version.content_hash,
               version.seal_hash
        FROM ingredient_ontology_identity_extension_state state
        JOIN ingredient_ontology_versions version
          ON version.id = state.ontology_version_id
        WHERE state.ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'revision' => 0,
            'hash' => $zeroHash,
        ];
    }
    if (
        !hash_equals(
            (string)$row['content_hash'],
            (string)$row['ontology_content_hash']
        )
        || !hash_equals(
            (string)$row['seal_hash'],
            (string)$row['ontology_seal_hash']
        )
    ) {
        throw new RuntimeException(
            'identity extension state ontology fence is stale'
        );
    }
    return [
        'revision' => (int)$row['head_revision'],
        'hash' => (string)$row['head_hash'],
    ];
}

function ingredientOntologyV3IdentityExtensionSnapshotMatches(
    PDO $db,
    int $versionId,
    array $snapshot
): bool {
    $revision = max(0, (int)($snapshot['revision'] ?? 0));
    $hash = (string)($snapshot['hash'] ?? '');
    if ($revision === 0) {
        return hash_equals(
            ingredientOntologyV3IdentityExtensionZeroHash(),
            $hash
        );
    }
    $stmt = $db->prepare("
        SELECT content_hash
        FROM ingredient_ontology_identity_extension_entities
        WHERE ontology_version_id = ?
          AND created_revision = ?
        LIMIT 1
    ");
    $stmt->execute([$versionId, $revision]);
    $storedHash = $stmt->fetchColumn();
    return $storedHash !== false
        && hash_equals((string)$storedHash, $hash);
}

function ingredientOntologyV3IdentityExtensionIntegrityAudit(
    PDO $db,
    int $versionId,
    int $throughRevision,
    string $expectedHash
): array {
    ingredientOntologyV3TrackCorpusOperation(
        'identity_extension_deep_audit',
        true
    );
    $throughRevision = max(0, $throughRevision);
    $zeroHash = ingredientOntologyV3IdentityExtensionZeroHash();
    if ($throughRevision === 0) {
        $valid = hash_equals($zeroHash, $expectedHash);
        return [
            'valid' => $valid,
            'revision' => 0,
            'hash' => $zeroHash,
            'row_count' => 0,
            'errors' => $valid
                ? []
                : ['zero revision hash changed'],
        ];
    }
    $version = ingredientOntologyV3Version($db, $versionId);
    if ($version === null) {
        return [
            'valid' => false,
            'revision' => $throughRevision,
            'hash' => '',
            'row_count' => 0,
            'errors' => ['ontology version unavailable'],
        ];
    }
    $stmt = $db->prepare("
        SELECT ontology_content_hash, ontology_seal_hash,
               created_revision, previous_hash, content_hash,
               identity_key_hash, identity_domain,
               normalizer_version, normalized_label, language,
               context_signature, display_label, slug
        FROM ingredient_ontology_identity_extension_entities
        WHERE ontology_version_id = ?
          AND created_revision <= ?
        ORDER BY created_revision
    ");
    $stmt->execute([$versionId, $throughRevision]);
    $errors = [];
    $expectedRevision = 1;
    $previousHash = $zeroHash;
    $rowCount = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rowCount++;
        $revision = (int)$row['created_revision'];
        if ($revision !== $expectedRevision) {
            $errors[] = 'identity extension revision gap';
            break;
        }
        if (
            !hash_equals(
                (string)$version['content_hash'],
                (string)$row['ontology_content_hash']
            )
            || !hash_equals(
                (string)$version['seal_hash'],
                (string)$row['ontology_seal_hash']
            )
        ) {
            $errors[] = 'identity extension ontology fence changed';
            break;
        }
        if (!hash_equals($previousHash, (string)$row['previous_hash'])) {
            $errors[] = 'identity extension previous hash changed';
            break;
        }
        $identityKeyHash =
            ingredientOntologyV3IdentityExtensionKeyHash(
                (string)$row['normalizer_version'],
                (string)$row['identity_domain'],
                (string)$row['normalized_label'],
                (string)$row['language'],
                (string)$row['context_signature']
            );
        if (!hash_equals(
            $identityKeyHash,
            (string)$row['identity_key_hash']
        )) {
            $errors[] = 'identity extension key hash changed';
            break;
        }
        $contentHash =
            ingredientOntologyV3IdentityExtensionContentHash(
                $versionId,
                $revision,
                $previousHash,
                $identityKeyHash,
                (string)$row['identity_domain'],
                (string)$row['normalizer_version'],
                (string)$row['normalized_label'],
                (string)$row['language'],
                (string)$row['context_signature'],
                (string)$row['display_label'],
                (string)$row['slug']
            );
        if (!hash_equals($contentHash, (string)$row['content_hash'])) {
            $errors[] = 'identity extension content hash changed';
            break;
        }
        $previousHash = $contentHash;
        $expectedRevision++;
    }
    if (!$errors && $rowCount !== $throughRevision) {
        $errors[] = 'identity extension revision count changed';
    }
    if (!$errors && !hash_equals($previousHash, $expectedHash)) {
        $errors[] = 'identity extension head hash changed';
    }
    return [
        'valid' => !$errors,
        'revision' => $throughRevision,
        'hash' => $previousHash,
        'row_count' => $rowCount,
        'errors' => $errors,
    ];
}

function ingredientOntologyV3IdentityExtensionReconcileState(
    PDO $db,
    int $versionId,
    ?int $requiredRevision = null,
    ?string $requiredHash = null
): array {
    $version = ingredientOntologyV3Version($db, $versionId);
    if ($version === null) {
        throw new RuntimeException(
            'identity extension state ontology version is unavailable'
        );
    }
    $headStmt = $db->prepare("
        SELECT created_revision, content_hash
        FROM ingredient_ontology_identity_extension_entities
        WHERE ontology_version_id = ?
        ORDER BY created_revision DESC
        LIMIT 1
    ");
    $headStmt->execute([$versionId]);
    $head = $headStmt->fetch(PDO::FETCH_ASSOC);
    $headRevision = $head
        ? (int)$head['created_revision']
        : 0;
    $headHash = $head
        ? (string)$head['content_hash']
        : ingredientOntologyV3IdentityExtensionZeroHash();
    $integrity = ingredientOntologyV3IdentityExtensionIntegrityAudit(
        $db,
        $versionId,
        $headRevision,
        $headHash
    );
    if (empty($integrity['valid'])) {
        throw new RuntimeException(
            'identity extension imported chain is invalid: '
            . implode(', ', (array)$integrity['errors'])
        );
    }
    if ($requiredRevision !== null) {
        $requiredRevision = max(0, $requiredRevision);
        $requiredHash ??=
            ingredientOntologyV3IdentityExtensionZeroHash();
        if ($requiredRevision > $headRevision) {
            throw new RuntimeException(
                'identity extension imported chain is incomplete'
            );
        }
        $requiredIntegrity =
            ingredientOntologyV3IdentityExtensionIntegrityAudit(
                $db,
                $versionId,
                $requiredRevision,
                $requiredHash
            );
        if (empty($requiredIntegrity['valid'])) {
            throw new RuntimeException(
                'identity extension imported prefix is invalid: '
                . implode(
                    ', ',
                    (array)$requiredIntegrity['errors']
                )
            );
        }
    }
    $stateStmt = $db->prepare("
        SELECT ontology_content_hash, ontology_seal_hash,
               head_revision, head_hash
        FROM ingredient_ontology_identity_extension_state
        WHERE ontology_version_id = ?
    ");
    $stateStmt->execute([$versionId]);
    $state = $stateStmt->fetch(PDO::FETCH_ASSOC);
    if ($state) {
        if (
            !hash_equals(
                (string)$version['content_hash'],
                (string)$state['ontology_content_hash']
            )
            || !hash_equals(
                (string)$version['seal_hash'],
                (string)$state['ontology_seal_hash']
            )
        ) {
            throw new RuntimeException(
                'identity extension imported state ontology fence changed'
            );
        }
        $stateRevision = (int)$state['head_revision'];
        if ($stateRevision > $headRevision) {
            throw new RuntimeException(
                'identity extension imported state references missing rows'
            );
        }
        $stateIntegrity =
            ingredientOntologyV3IdentityExtensionIntegrityAudit(
                $db,
                $versionId,
                $stateRevision,
                (string)$state['head_hash']
            );
        if (empty($stateIntegrity['valid'])) {
            throw new RuntimeException(
                'identity extension imported state prefix is invalid'
            );
        }
    }
    $db->prepare("
        INSERT INTO ingredient_ontology_identity_extension_state (
            ontology_version_id, ontology_content_hash,
            ontology_seal_hash, head_revision, head_hash,
            updated_at
        )
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(ontology_version_id) DO UPDATE SET
            head_revision = excluded.head_revision,
            head_hash = excluded.head_hash,
            updated_at = CURRENT_TIMESTAMP
        WHERE
            ingredient_ontology_identity_extension_state
                .ontology_content_hash =
                    excluded.ontology_content_hash
            AND ingredient_ontology_identity_extension_state
                .ontology_seal_hash =
                    excluded.ontology_seal_hash
            AND ingredient_ontology_identity_extension_state
                .head_revision <= excluded.head_revision
    ")->execute([
        $versionId,
        (string)$version['content_hash'],
        (string)$version['seal_hash'],
        $headRevision,
        $headHash,
    ]);
    $reconciled = ingredientOntologyV3IdentityExtensionSnapshot(
        $db,
        $versionId
    );
    if (
        (int)$reconciled['revision'] !== $headRevision
        || !hash_equals((string)$reconciled['hash'], $headHash)
    ) {
        throw new RuntimeException(
            'identity extension imported state reconciliation failed'
        );
    }
    return $reconciled;
}

function ingredientOntologyV3IdentityExtensionRow(array $row): array {
    $extensionEntityId = (int)$row['id'];
    return [
        'id' => $extensionEntityId,
        'runtime_entity_id' =>
            ingredientOntologyV3IdentityExtensionRuntimeEntityId(
                $extensionEntityId
            ),
        'ontology_version_id' => (int)$row['ontology_version_id'],
        'created_revision' => (int)$row['created_revision'],
        'content_hash' => (string)$row['content_hash'],
        'identity_key_hash' => (string)$row['identity_key_hash'],
        'identity_domain' => (string)$row['identity_domain'],
        'normalizer_version' => (string)$row['normalizer_version'],
        'normalized_label' => (string)$row['normalized_label'],
        'language' => (string)$row['language'],
        'context_signature' => (string)$row['context_signature'],
        'display_label' => (string)$row['display_label'],
        'slug' => (string)$row['slug'],
        'canonical_name' => (string)$row['canonical_name'],
        'status' => (string)$row['status'],
        'resolver_version' => (string)$row['resolver_version'],
    ];
}

function ingredientOntologyV3IdentityExtensionLookup(
    PDO $db,
    array $version,
    string $normalizedLabel,
    string $language,
    string $contextSignature = '',
    ?int $throughRevision = null
): ?array {
    if (!ingredientOntologyV3TableExists(
        $db,
        'ingredient_ontology_identity_extension_entities'
    )) {
        return null;
    }
    $normalizedLabel = ingredientOntologyV3NormalizeLabel(
        $normalizedLabel
    );
    $language = ingredientOntologyV3NormalizeLanguage($language);
    $contextSignature = trim($contextSignature);
    if ($normalizedLabel === '') {
        return null;
    }
    $revisionSql = $throughRevision !== null
        ? ' AND created_revision <= ?'
        : '';
    $stmt = $db->prepare("
        SELECT *
        FROM ingredient_ontology_identity_extension_entities
        WHERE ontology_version_id = ?
          AND ontology_content_hash = ?
          AND ontology_seal_hash = ?
          AND identity_domain = 'food'
          AND normalized_label = ?
          AND language = ?
          AND context_signature = ?
          AND status = 'active'
          {$revisionSql}
        LIMIT 1
    ");
    $params = [
        (int)$version['id'],
        (string)$version['content_hash'],
        (string)$version['seal_hash'],
        $normalizedLabel,
        $language,
        $contextSignature,
    ];
    if ($throughRevision !== null) {
        $params[] = max(0, $throughRevision);
    }
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row
        ? ingredientOntologyV3IdentityExtensionRow($row)
        : null;
}

function ingredientOntologyV3IdentityExtensionEligibility(
    array $version,
    string $sourceLabel,
    string $language
): array {
    $normalizedLabel =
        ingredientOntologyV3IdentityOrthographicLabel($sourceLabel);
    $language = ingredientOntologyV3IdentityLanguageKey($language);
    if (!ingredientOntologyV3ExactSelfIdentityEnabled()) {
        return [
            'eligible' => false,
            'reason' => 'exact_self_identity_disabled',
            'normalized_label' => $normalizedLabel,
            'language' => $language,
        ];
    }
    if (
        (string)($version['status'] ?? '') !== 'ready'
        || !is_string($version['content_hash'] ?? null)
        || !is_string($version['seal_hash'] ?? null)
    ) {
        return [
            'eligible' => false,
            'reason' => 'exact_self_ontology_unavailable',
            'normalized_label' => $normalizedLabel,
            'language' => $language,
        ];
    }
    if ($normalizedLabel === '') {
        return [
            'eligible' => false,
            'reason' => 'empty_label',
            'normalized_label' => '',
            'language' => $language,
        ];
    }
    if (mb_strlen($normalizedLabel, 'UTF-8') > 200) {
        return [
            'eligible' => false,
            'reason' => 'exact_self_label_too_long',
            'normalized_label' => $normalizedLabel,
            'language' => $language,
        ];
    }
    return [
        'eligible' => true,
        'reason' => 'exact_self_identity',
        'normalized_label' => $normalizedLabel,
        'language' => $language,
    ];
}

function ingredientOntologyV3IdentityExtensionKeyHash(
    string $normalizerVersion,
    string $identityDomain,
    string $normalizedLabel,
    string $language,
    string $contextSignature
): string {
    return ingredientOntologyV3Hash([
        'schema' => 'identity-exact-lexeme-key-v1',
        'normalizer_version' => $normalizerVersion,
        'identity_domain' => $identityDomain,
        'language' => $language,
        'normalized_label' => $normalizedLabel,
        'context_signature' => $contextSignature,
    ]);
}

function ingredientOntologyV3IdentityExtensionContentHash(
    int $ontologyVersionId,
    int $revision,
    string $previousHash,
    string $identityKeyHash,
    string $identityDomain,
    string $normalizerVersion,
    string $normalizedLabel,
    string $language,
    string $contextSignature,
    string $displayLabel,
    string $slug
): string {
    return ingredientOntologyV3Hash([
        'schema' => 'identity-exact-lexeme-chain-v1',
        'previous_hash' => $previousHash,
        'revision' => $revision,
        'ontology_version_id' => $ontologyVersionId,
        'identity_key_hash' => $identityKeyHash,
        'identity_domain' => $identityDomain,
        'normalizer_version' => $normalizerVersion,
        'normalized_label' => $normalizedLabel,
        'language' => $language,
        'context_signature' => $contextSignature,
        'display_label' => $displayLabel,
        'slug' => $slug,
    ]);
}

function ingredientOntologyV3IdentityExtensionImportInProgress(
    PDO $db,
    int $versionId
): bool {
    if (
        $versionId <= 0
        || !function_exists(
            'ingredientOntologyControllerDatabaseIsActive'
        )
        || !ingredientOntologyControllerDatabaseIsActive($db)
        || !ingredientOntologyV3TableExists(
            $db,
            'ontology_activation_imports'
        )
    ) {
        return false;
    }
    $stmt = $db->prepare("
        SELECT 1
        FROM ontology_activation_imports
        WHERE bundle_kind = 'score'
          AND candidate_ontology_version_id = ?
          AND status IN ('staging', 'importing')
        LIMIT 1
    ");
    $stmt->execute([$versionId]);
    return $stmt->fetchColumn() !== false;
}

function ingredientOntologyV3IdentityExtensionClaim(
    PDO $db,
    array $version,
    string $sourceLabel,
    string $language,
    string $contextSignature = '',
    bool $create = true,
    bool $lookup = true
): ?array {
    if (!$lookup) {
        return null;
    }
    $eligibility =
        ingredientOntologyV3IdentityExtensionEligibility(
            $version,
            $sourceLabel,
            $language
        );
    if (empty($eligibility['eligible'])) {
        return null;
    }
    $normalizedLabel = (string)$eligibility['normalized_label'];
    $language = (string)$eligibility['language'];
    $contextSignature = mb_substr(
        trim($contextSignature),
        0,
        120,
        'UTF-8'
    );
    $existing = ingredientOntologyV3IdentityExtensionLookup(
        $db,
        $version,
        $normalizedLabel,
        $language,
        $contextSignature
    );
    if ($existing !== null || !$create) {
        return $existing;
    }
    if (ingredientOntologyV3IdentityExtensionImportInProgress(
        $db,
        (int)$version['id']
    )) {
        throw new RuntimeException(
            'identity extension activation import is in progress'
        );
    }

    static $savepointSequence = 0;
    $ownsTransaction = false;
    $savepoint = 'identity_extension_claim_'
        . (++$savepointSequence);
    $nestedTransaction = databaseTransactionIsActive($db);
    if (!$nestedTransaction) {
        try {
            dbBeginImmediateWithRetry($db);
            $ownsTransaction = true;
        } catch (PDOException $error) {
            if (!str_contains(
                strtolower($error->getMessage()),
                'within a transaction'
            )) {
                throw $error;
            }
            $nestedTransaction = true;
        }
    }
    if ($nestedTransaction) {
        $db->exec("SAVEPOINT {$savepoint}");
    }
    try {
        $existing = ingredientOntologyV3IdentityExtensionLookup(
            $db,
            $version,
            $normalizedLabel,
            $language,
            $contextSignature
        );
        if ($existing !== null) {
            if ($ownsTransaction) {
                $db->exec('COMMIT');
            } else {
                $db->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            return $existing;
        }
        $db->prepare("
            INSERT OR IGNORE INTO
                ingredient_ontology_identity_extension_state (
                ontology_version_id, ontology_content_hash,
                ontology_seal_hash, head_revision, head_hash,
                updated_at
            )
            VALUES (?, ?, ?, 0, ?, CURRENT_TIMESTAMP)
        ")->execute([
            (int)$version['id'],
            (string)$version['content_hash'],
            (string)$version['seal_hash'],
            ingredientOntologyV3IdentityExtensionZeroHash(),
        ]);
        $stateStmt = $db->prepare("
            SELECT head_revision, head_hash,
                   ontology_content_hash, ontology_seal_hash
            FROM ingredient_ontology_identity_extension_state
            WHERE ontology_version_id = ?
        ");
        $stateStmt->execute([(int)$version['id']]);
        $state = $stateStmt->fetch(PDO::FETCH_ASSOC);
        if (
            !$state
            || !hash_equals(
                (string)$version['content_hash'],
                (string)$state['ontology_content_hash']
            )
            || !hash_equals(
                (string)$version['seal_hash'],
                (string)$state['ontology_seal_hash']
            )
        ) {
            throw new RuntimeException(
                'identity extension state fence changed'
            );
        }
        $revision = (int)$state['head_revision'] + 1;
        $previousHash = (string)$state['head_hash'];
        $displayLabel = mb_substr(
            trim($sourceLabel),
            0,
            200,
            'UTF-8'
        );
        if ($displayLabel === '') {
            $displayLabel = $normalizedLabel;
        }
        $identityKeyHash =
            ingredientOntologyV3IdentityExtensionKeyHash(
                INGREDIENT_ONTOLOGY_IDENTITY_EXTENSION_NORMALIZER_VERSION,
                'food',
                $normalizedLabel,
                $language,
                $contextSignature
            );
        $slug = 'exact-self-' . substr($identityKeyHash, 0, 24);
        $contentHash =
            ingredientOntologyV3IdentityExtensionContentHash(
                (int)$version['id'],
                $revision,
                $previousHash,
                $identityKeyHash,
                'food',
                INGREDIENT_ONTOLOGY_IDENTITY_EXTENSION_NORMALIZER_VERSION,
                $normalizedLabel,
                $language,
                $contextSignature,
                $displayLabel,
                $slug
            );
        $db->prepare("
            INSERT INTO ingredient_ontology_identity_extension_entities (
                ontology_version_id, ontology_content_hash,
                ontology_seal_hash, created_revision,
                previous_hash, content_hash, identity_key_hash,
                identity_domain, normalizer_version,
                normalized_label, language, context_signature,
                display_label, slug, canonical_name,
                status, resolver_version, created_at
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?, 'food', ?, ?, ?, ?, ?, ?, ?,
                'active', ?, CURRENT_TIMESTAMP
            )
        ")->execute([
            (int)$version['id'],
            (string)$version['content_hash'],
            (string)$version['seal_hash'],
            $revision,
            $previousHash,
            $contentHash,
            $identityKeyHash,
            INGREDIENT_ONTOLOGY_IDENTITY_EXTENSION_NORMALIZER_VERSION,
            $normalizedLabel,
            $language,
            $contextSignature,
            $displayLabel,
            $slug,
            $displayLabel,
            INGREDIENT_ONTOLOGY_IDENTITY_EXTENSION_NORMALIZER_VERSION,
        ]);
        $extensionEntityId = (int)$db->lastInsertId();
        $update = $db->prepare("
            UPDATE ingredient_ontology_identity_extension_state
            SET head_revision = ?,
                head_hash = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE ontology_version_id = ?
              AND head_revision = ?
              AND head_hash = ?
        ");
        $update->execute([
            $revision,
            $contentHash,
            (int)$version['id'],
            $revision - 1,
            $previousHash,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException(
                'identity extension revision compare-and-swap failed'
            );
        }
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        } else {
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
        }
        return ingredientOntologyV3IdentityExtensionRow([
            'id' => $extensionEntityId,
            'ontology_version_id' => (int)$version['id'],
            'created_revision' => $revision,
            'content_hash' => $contentHash,
            'identity_key_hash' => $identityKeyHash,
            'identity_domain' => 'food',
            'normalizer_version' =>
                INGREDIENT_ONTOLOGY_IDENTITY_EXTENSION_NORMALIZER_VERSION,
            'normalized_label' => $normalizedLabel,
            'language' => $language,
            'context_signature' => $contextSignature,
            'display_label' => $displayLabel,
            'slug' => $slug,
            'canonical_name' => $displayLabel,
            'status' => 'active',
            'resolver_version' =>
                INGREDIENT_ONTOLOGY_IDENTITY_EXTENSION_NORMALIZER_VERSION,
        ]);
    } catch (Throwable $error) {
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        } else {
            try {
                $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $db->exec("RELEASE SAVEPOINT {$savepoint}");
            } catch (Throwable $ignored) {
            }
        }
        try {
            $existing = ingredientOntologyV3IdentityExtensionLookup(
                $db,
                $version,
                $normalizedLabel,
                $language,
                $contextSignature
            );
        } catch (Throwable $lookupError) {
            $existing = null;
        }
        $contention = $error instanceof PDOException
            && (
                str_contains(
                    strtolower($error->getMessage()),
                    'unique constraint'
                )
                || (
                    function_exists('databaseIsLockError')
                    && databaseIsLockError($error)
                )
            );
        if (
            $existing !== null
            && $contention
        ) {
            return $existing;
        }
        throw $error;
    }
}

function ingredientOntologyV3RecipeAnnexResolution(
    PDO $db,
    array $version,
    string $sourceLabel,
    string $language,
    bool $createExtension = false,
    bool $lookupExtension = true
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
            'extension_entity_id' => null,
            'effective_entity_id' => null,
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
    $unresolvedReason = 'no_reviewed_exact_alias';
    $orthographicLabel =
        ingredientOntologyV3IdentityOrthographicLabel($sourceLabel);
    if (!$candidates && $orthographicLabel !== $normalizedLabel) {
        $candidates = ingredientOntologyV3IdentityAnnexLabelCandidates(
            $db,
            (int)$version['id'],
            $orthographicLabel,
            $language
        );
        if ($candidates) {
            $admissionSource = 'orthographic_alias';
        }
    }
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
    if (!$candidates && $review === null) {
        $numberProof =
            ingredientOntologyV3IdentityAnnexExactNumberProof(
                $db,
                (int)$version['id'],
                $normalizedLabel,
                $language
            );
        $candidates = (array)$numberProof['candidates'];
            if (
        (string)$numberProof['reason']
            === 'number_variant_source_conflict'
            ) {
        $unresolvedReason = (string)$numberProof['reason'];
            }
            if ($candidates) {
            $admissionSource = 'exact_number_v1';
            $review = $numberProof['proof'];
        }
    }
    $entities = [];
    foreach ($candidates as $candidate) {
        $entities[(int)$candidate['entity_id']] = true;
    }
    $candidateCollision = $candidates && count($entities) !== 1;
    if (!$candidates || $candidateCollision) {
        $extension = ingredientOntologyV3IdentityExtensionClaim(
            $db,
            $version,
            $sourceLabel,
            $language,
            '',
            $createExtension,
            $lookupExtension
        );
        if ($extension !== null) {
            return [
                'status' => 'accepted',
                'reason' => 'exact_self_identity',
                'admission_source' => 'exact_self_identity',
                'label_id' => null,
                'entity_id' => null,
                'extension_entity_id' => (int)$extension['id'],
                'effective_entity_id' =>
                    (int)$extension['runtime_entity_id'],
                'entity_slug' => (string)$extension['slug'],
                'entity_name' =>
                    (string)$extension['canonical_name'],
                'attributes' => [],
                'normalized_label' =>
                    (string)$extension['normalized_label'],
                'language' => (string)$extension['language'],
                'extension_revision' =>
                    (int)$extension['created_revision'],
                'extension_hash' => (string)$extension['content_hash'],
                'review' => null,
            ];
        }
        if (
            $candidateCollision
            && !ingredientOntologyV3ExactSelfIdentityEnabled()
        ) {
            return [
                'status' => 'rejected',
                'reason' => 'reviewed_alias_collision',
                'admission_source' => 'none',
                'label_id' => null,
                'entity_id' => null,
                'extension_entity_id' => null,
                'effective_entity_id' => null,
                'attributes' => [],
                'normalized_label' => $normalizedLabel,
                'language' => $language,
            ];
        }
        if ($candidateCollision) {
            $unresolvedReason =
                'reviewed_alias_collision_exact_self_pending';
        }
        $eligibility =
            ingredientOntologyV3IdentityExtensionEligibility(
                $version,
                $sourceLabel,
                $language
            );
        if (
            (string)$eligibility['reason']
                === 'exact_self_label_too_long'
        ) {
            $unresolvedReason = (string)$eligibility['reason'];
        }
        return [
            'status' => 'unresolved',
            'reason' => $unresolvedReason,
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'extension_entity_id' => null,
            'effective_entity_id' => null,
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
        'extension_entity_id' => null,
        'effective_entity_id' => (int)$candidate['entity_id'],
        'entity_slug' => (string)$candidate['entity_slug'],
        'entity_name' => (string)$candidate['entity_name'],
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
               COALESCE(origin.locale, '') AS origin_locale,
               COALESCE(origin.content_language, '')
                   AS origin_content_language
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
               COALESCE(origin.content_language, '')
                   AS origin_content_language,
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
               annex.extension_entity_id
                   AS annex_extension_entity_id,
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
        'extension_entity_id',
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
        ingredientOntologyV3RecipeIdentityResolverVersion(),
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
        $identityLanguage =
            ingredientOntologyV3RecipeIdentityLanguage($row);
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
            $identityLanguage
        );
        if (!isset($resolutionCache[$cacheKey])) {
            $resolutionCache[$cacheKey] =
                ingredientOntologyV3RecipeAnnexResolution(
                    $db,
                    $version,
                    (string)$row['source_label'],
                    $identityLanguage,
                    true
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
                ingredientOntologyV3RecipeIdentityResolverVersion(),
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
            'extension_entity_id' =>
                $resolution['extension_entity_id'] ?? null,
            'extension_revision' =>
                $resolution['extension_revision'] ?? null,
            'extension_hash' =>
                $resolution['extension_hash'] ?? null,
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
            'extension_entity_id' =>
                $resolution['extension_entity_id'] ?? null,
            'status' => (string)$resolution['status'],
            'confidence' =>
                (string)$resolution['status'] === 'accepted'
                    ? 1.0
                    : 0.0,
            'admission_source' =>
                (string)$resolution['admission_source'],
            'attributes_json' => $attributesJson,
            'resolver_version' =>
                ingredientOntologyV3RecipeIdentityResolverVersion(),
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
            'extension_entity_id',
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
                    extension_entity_id =
                        excluded.extension_entity_id,
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
    $eligibleRoles = ingredientOntologyV3IdentityEligibleRolesSql();
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
          AND entity.identity_role IN {$eligibleRoles}
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

function ingredientOntologyV3IdentityAnnexEligibleLabelConflicts(
    PDO $db,
    int $versionId,
    string $normalizedLabel
): array {
    $eligibleRoles = ingredientOntologyV3IdentityEligibleRolesSql();
    $stmt = $db->prepare("
        SELECT label.id AS label_id, label.entity_id,
               label.review_state, label.kind,
               entity.slug AS entity_slug
        FROM ingredient_ontology_labels label
        JOIN ingredient_ontology_entities entity
          ON entity.id = label.entity_id
         AND entity.ontology_version_id = label.ontology_version_id
        WHERE label.ontology_version_id = ?
          AND label.normalized_label = ?
          AND entity.active = 1
          AND entity.entity_kind = 'ingredient'
          AND entity.identity_role IN {$eligibleRoles}
          AND entity.provenance <> 'autonomous_controller'
          AND entity.slug NOT LIKE 'provisional-subject-%'
        ORDER BY label.id
    ");
    $stmt->execute([$versionId, $normalizedLabel]);
    return array_map(
        static fn(array $row): array => [
            'label_id' => (int)$row['label_id'],
            'entity_id' => (int)$row['entity_id'],
            'entity_slug' => (string)$row['entity_slug'],
            'review_state' => (string)$row['review_state'],
            'kind' => (string)$row['kind'],
        ],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

function ingredientOntologyV3IdentityAnnexExactNumberProof(
    PDO $db,
    int $versionId,
    string $normalizedLabel,
    string $language
): array {
    if (
        ingredientOntologyV3IdentityLanguageKey($language) !== 'en'
        || mb_strlen($normalizedLabel, 'UTF-8') < 4
        || !str_ends_with($normalizedLabel, 's')
    ) {
        return [
            'candidates' => [],
            'reason' => 'number_variant_not_applicable',
            'proof' => null,
        ];
    }
    $singular = mb_substr(
        $normalizedLabel,
        0,
        mb_strlen($normalizedLabel, 'UTF-8') - 1,
        'UTF-8'
    );
    if ($singular === '' || $singular . 's' !== $normalizedLabel) {
        return [
            'candidates' => [],
            'reason' => 'number_variant_not_reversible',
            'proof' => null,
        ];
    }
    $conflicts =
        ingredientOntologyV3IdentityAnnexEligibleLabelConflicts(
            $db,
            $versionId,
            $normalizedLabel
        );
    if ($conflicts) {
        return [
            'candidates' => [],
            'reason' => 'number_variant_source_conflict',
            'proof' => [
                'algorithm' => 'exact-number-v1',
                'source' => $normalizedLabel,
                'target' => $singular,
                'conflicts' => $conflicts,
            ],
        ];
    }
    $candidates = array_values(array_filter(
        ingredientOntologyV3IdentityAnnexLabelCandidates(
            $db,
            $versionId,
            $singular,
            $language
        ),
        static fn(array $candidate): bool =>
            (string)$candidate['kind'] === 'exact_alias'
            && (array)$candidate['attributes'] === []
            && (string)$candidate['normalized_label'] === $singular
    ));
    return [
        'candidates' => $candidates,
        'reason' => $candidates
            ? 'exact_number_v1'
            : 'number_variant_target_missing',
        'proof' => [
            'algorithm' => 'exact-number-v1',
            'source' => $normalizedLabel,
            'target' => $singular,
            'language' => 'en',
        ],
    ];
}

function ingredientOntologyV3IdentityAnnexResolution(
    PDO $db,
    array $version,
    array $product,
    bool $createExtension = false,
    bool $lookupExtension = true,
    bool $preserveSealedMapping = true
): array {
    $normalizedLabel = ingredientOntologyV3NormalizeLabel(
        (string)$product['name']
    );
    $language = ingredientOntologyV3ProductIdentityLanguage();
    if (!empty($product['prepared_food'])) {
        return [
            'status' => 'rejected',
            'reason' => 'prepared_food',
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'extension_entity_id' => null,
            'effective_entity_id' => null,
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
            'extension_entity_id' => null,
            'effective_entity_id' => null,
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
    $unresolvedReason = 'no_reviewed_exact_alias';
    $orthographicLabel =
        ingredientOntologyV3IdentityOrthographicLabel(
            (string)$product['name']
        );
    if (!$candidates && $orthographicLabel !== $normalizedLabel) {
        $candidates = ingredientOntologyV3IdentityAnnexLabelCandidates(
            $db,
            (int)$version['id'],
            $orthographicLabel,
            $language
        );
        if ($candidates) {
            $admissionSource = 'orthographic_alias';
        }
    }
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
    if (!$candidates && $review === null) {
        $numberProof =
            ingredientOntologyV3IdentityAnnexExactNumberProof(
                $db,
                (int)$version['id'],
                $normalizedLabel,
                $language
            );
        $candidates = (array)$numberProof['candidates'];
            if (
                (string)$numberProof['reason']
                    === 'number_variant_source_conflict'
            ) {
                $unresolvedReason = (string)$numberProof['reason'];
            }
            if ($candidates) {
            $admissionSource = 'exact_number_v1';
            $review = $numberProof['proof'];
        }
    }

    $entities = [];
    foreach ($candidates as $candidate) {
        $entities[(int)$candidate['entity_id']] = true;
    }
    $candidateCollision = $candidates && count($entities) !== 1;
    if (!$candidates || $candidateCollision) {
        $sealedMappingPreserved = false;
        $productId = (int)($product['id'] ?? 0);
        if (
            $preserveSealedMapping
            &&
            ($createExtension || $lookupExtension)
            && $productId > 0
        ) {
            $sealedAccepted = $db->prepare("
                SELECT 1
                FROM ingredient_ontology_mappings
                WHERE ontology_version_id = ?
                  AND owner_type = 'product'
                  AND owner_id = ?
                  AND owner_fingerprint = ?
                  AND status = 'accepted'
                  AND entity_id IS NOT NULL
                LIMIT 1
            ");
            $sealedAccepted->execute([
                (int)$version['id'],
                $productId,
                ingredientOntologyV3ProductOwnerFingerprint($product),
            ]);
            if ($sealedAccepted->fetchColumn() !== false) {
                $createExtension = false;
                $lookupExtension = false;
                $sealedMappingPreserved = true;
            }
        }
        $collisionReason = $admissionSource === 'exact_number_v1'
            ? 'number_variant_collision'
            : 'reviewed_alias_collision';
        $extension = ingredientOntologyV3IdentityExtensionClaim(
            $db,
            $version,
            (string)$product['name'],
            $language,
            '',
            $createExtension,
            $lookupExtension
        );
        if ($extension !== null) {
            $extensionSource = $candidateCollision
                ? $collisionReason . '_exact_self_identity'
                : 'exact_self_identity';
            return [
                'status' => 'accepted',
                'reason' => $extensionSource,
                'admission_source' => $extensionSource,
                'label_id' => null,
                'entity_id' => null,
                'extension_entity_id' => (int)$extension['id'],
                'effective_entity_id' =>
                    (int)$extension['runtime_entity_id'],
                'entity_slug' => (string)$extension['slug'],
                'entity_name' =>
                    (string)$extension['canonical_name'],
                'attributes' => [],
                'normalized_label' =>
                    (string)$extension['normalized_label'],
                'language' => (string)$extension['language'],
                'extension_revision' =>
                    (int)$extension['created_revision'],
                'extension_hash' => (string)$extension['content_hash'],
                'label' => null,
                'review' => null,
            ];
        }
        if ($sealedMappingPreserved) {
            return [
                'status' => 'unresolved',
                'reason' => 'sealed_mapping_preserved',
                'admission_source' => 'none',
                'label_id' => null,
                'entity_id' => null,
                'extension_entity_id' => null,
                'effective_entity_id' => null,
                'attributes' => [],
                'normalized_label' => $normalizedLabel,
                'language' => $language,
                'sealed_mapping_preserved' => true,
            ];
        }
        if (
            $candidateCollision
            && !ingredientOntologyV3ExactSelfIdentityEnabled()
        ) {
            return [
                'status' => 'rejected',
                'reason' => $collisionReason,
                'admission_source' => 'none',
                'label_id' => null,
                'entity_id' => null,
                'extension_entity_id' => null,
                'effective_entity_id' => null,
                'attributes' => [],
                'normalized_label' => $normalizedLabel,
                'language' => $language,
            ];
        }
        if ($candidateCollision) {
            $unresolvedReason =
                $collisionReason . '_exact_self_pending';
        }
        $eligibility =
            ingredientOntologyV3IdentityExtensionEligibility(
                $version,
                (string)$product['name'],
                $language
            );
        if (
            (string)$eligibility['reason']
                === 'exact_self_label_too_long'
        ) {
            $unresolvedReason = (string)$eligibility['reason'];
        }
        return [
            'status' => 'unresolved',
            'reason' => $unresolvedReason,
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'extension_entity_id' => null,
            'effective_entity_id' => null,
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
        'extension_entity_id' => null,
        'effective_entity_id' => (int)$candidate['entity_id'],
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
    bool $resolveCoverageGaps = true,
    bool $createExtension = true,
    bool $lookupExtension = true,
    bool $preserveSealedMapping = true
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
        $product,
        $createExtension,
        $lookupExtension,
        $preserveSealedMapping
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
    $sealedMappingPreserved =
        !empty($resolution['sealed_mapping_preserved']);
    $evidence = [
        'resolver_version' =>
            ingredientOntologyV3ProductIdentityResolverVersion(),
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
        'extension_entity_id' =>
            $resolution['extension_entity_id'] ?? null,
        'extension_revision' =>
            $resolution['extension_revision'] ?? null,
        'extension_hash' =>
            $resolution['extension_hash'] ?? null,
        'sealed_mapping_preserved' =>
            $sealedMappingPreserved
            && (string)$resolution['status'] === 'unresolved',
        'attributes' => $attributes,
        'review' => $resolution['review'] ?? null,
    ];
    $evidenceHash = ingredientOntologyV3Hash($evidence);
    $previousStmt = $db->prepare("
        SELECT owner_fingerprint, ontology_version_id,
               status, entity_id, extension_entity_id,
               attributes_json, evidence_hash
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
            language, label_id, entity_id, extension_entity_id,
            status,
            admission_source, attributes_json,
            resolver_version, review_manifest_hash,
            evidence_hash, reason, created_at, updated_at
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
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
            extension_entity_id = excluded.extension_entity_id,
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
        $resolution['extension_entity_id'] ?? null,
        (string)$resolution['status'],
        (string)$resolution['admission_source'],
        ingredientOntologyV3Json($attributes),
        ingredientOntologyV3ProductIdentityResolverVersion(),
        $reviewManifestHash,
        $evidenceHash,
        mb_substr((string)$resolution['reason'], 0, 240, 'UTF-8'),
    ]);

    $previousEntityId = $previous !== null
        && $previous['entity_id'] !== null
            ? (int)$previous['entity_id']
            : null;
    $previousExtensionEntityId = $previous !== null
        && $previous['extension_entity_id'] !== null
            ? (int)$previous['extension_entity_id']
            : null;
    $previousEffectiveEntityId = $previousEntityId;
    if ($previousExtensionEntityId !== null) {
        $previousEffectiveEntityId =
            ingredientOntologyV3IdentityExtensionRuntimeEntityId(
                $previousExtensionEntityId
            );
    }
    $effectiveEntityId =
        $resolution['effective_entity_id']
            ?? $resolution['entity_id'];
    $previousAttributes = $previous !== null
        ? json_decode((string)$previous['attributes_json'], true)
        : [];
    $previousAttributes = is_array($previousAttributes)
        ? $previousAttributes
        : [];
    ksort($previousAttributes, SORT_STRING);
    $semanticChanged = $previous === null
        ? in_array(
            (string)$resolution['status'],
            ['accepted', 'rejected'],
            true
        )
        : (
            (string)$previous['status']
                !== (string)$resolution['status']
            || $previousEffectiveEntityId !== $effectiveEntityId
            || !hash_equals(
                ingredientOntologyV3Hash($previousAttributes),
                ingredientOntologyV3Hash($attributes)
            )
        );
    $changed = $previous === null
        || (int)$previous['ontology_version_id'] !== (int)$version['id']
        || !hash_equals(
            (string)$previous['owner_fingerprint'],
            $ownerFingerprint
        )
        || (string)$previous['status'] !== (string)$resolution['status']
        || $previousEffectiveEntityId !== $effectiveEntityId
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
            if (function_exists(
                'ingredientOntologyControllerSupersedeResolvedIdentityWork'
            )) {
                ingredientOntologyControllerSupersedeResolvedIdentityWork(
                    $db,
                    $subjectId,
                    0
                );
            }
        }
    }
    return [
        'available' => true,
        'accepted' => (string)$resolution['status'] === 'accepted',
        'changed' => $changed,
        'semantic_changed' => $semanticChanged,
        'product_id' => $productId,
        'owner_fingerprint' => $ownerFingerprint,
        'ontology_version_id' => (int)$version['id'],
        'ontology_content_hash' => (string)$version['content_hash'],
        'ontology_seal_hash' => (string)$version['seal_hash'],
        'label_id' => $resolution['label_id'],
        'entity_id' => $effectiveEntityId,
        'ontology_entity_id' => $resolution['entity_id'],
        'extension_entity_id' =>
            $resolution['extension_entity_id'] ?? null,
        'previous_entity_id' => $previousEffectiveEntityId,
        'previous_extension_entity_id' =>
            $previousExtensionEntityId,
        'previous_status' => $previous['status'] ?? null,
        'entity_slug' => $resolution['entity_slug'] ?? null,
        'attributes' => $attributes,
        'status' => (string)$resolution['status'],
        'source' => (string)$resolution['admission_source'],
        'reason' => (string)$resolution['reason'],
        'evidence_hash' => $evidenceHash,
        'review_manifest_hash' => $reviewManifestHash,
        'sealed_mapping_preserved' =>
            $sealedMappingPreserved
            && (string)$resolution['status'] === 'unresolved',
    ];
}

function ingredientOntologyV3IdentityExtensionRecipeIdsForProducts(
    PDO $db,
    int $versionId,
    array $productIds,
    ?int $limit = null
): array {
    $productIds = array_values(array_unique(array_filter(
        array_map('intval', $productIds),
        static fn(int $productId): bool => $productId > 0
    )));
    if (
        !$productIds
        || !ingredientOntologyV3ExactSelfIdentityEnabled()
        || !ingredientOntologyV3TableExists(
            $db,
            'ingredient_ontology_identity_extension_entities'
        )
    ) {
        return [];
    }
    $limit = max(
        1,
        $limit ?? (
            function_exists('ingredientOntologyV3IncrementalProductLimit')
                ? ingredientOntologyV3IncrementalProductLimit()
                : 250
        )
    );
    return ingredientOntologyV3IdentityExtensionRecipeIdsForProductsQuery(
        $db,
        $versionId,
        $productIds,
        $limit
    );
}

function ingredientOntologyV3IdentityProjectionHashAtRevision(
    PDO $db,
    int $versionId,
    int $revision
): string {
        if ($revision <= 0) {
            return ingredientOntologyV3IdentityExtensionZeroHash();
        }
        $stmt = $db->prepare("
            SELECT content_hash
            FROM ingredient_ontology_identity_extension_entities
            WHERE ontology_version_id = ?
              AND created_revision = ?
        ");
        $stmt->execute([$versionId, $revision]);
        $hash = $stmt->fetchColumn();
        if ($hash === false || strlen((string)$hash) !== 64) {
            throw new RuntimeException(
                'identity projection prefix hash is unavailable'
            );
        }
        return (string)$hash;
    }

function ingredientOntologyV3IdentityProjectionPendingCount(
    PDO $db,
    int $versionId
): int {
        if (
            $versionId <= 0
            || !ingredientOntologyV3TableExists(
                $db,
                'recipe_score_identity_projection_work'
            )
        ) {
            return 0;
        }
        $stmt = $db->prepare("
            SELECT
                (SELECT COUNT(*)
                 FROM recipe_score_identity_projection_work
                 WHERE ontology_version_id = ?)
                +
                (SELECT COUNT(*)
                 FROM recipe_score_identity_projection_events
                 WHERE ontology_version_id = ?
                   AND completed = 0)
        ");
        $stmt->execute([$versionId, $versionId]);
        return (int)$stmt->fetchColumn();
    }

function ingredientOntologyV3IdentityProjectionWorkState(
    PDO $db,
    int $versionId
): ?array {
        if (
            $versionId <= 0
            || !ingredientOntologyV3TableExists(
                $db,
                'recipe_score_identity_projection_state'
            )
        ) {
            return null;
        }
        $stmt = $db->prepare("
            SELECT *
            FROM recipe_score_identity_projection_state
            WHERE ontology_version_id = ?
        ");
        $stmt->execute([$versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        foreach ([
            'ontology_version_id',
            'work_head_annex_revision_id',
            'discovered_revision',
            'covered_revision',
        ] as $key) {
            $row[$key] = $row[$key] !== null
                ? (int)$row[$key]
                : null;
        }
        return $row;
    }

function ingredientOntologyV3IdentityProjectionUpsertSql(
    string $selectSql
): string {
        return "
            INSERT INTO recipe_score_identity_projection_work (
                ontology_version_id, recipe_id,
                first_event_id, latest_event_id,
                first_required_revision, latest_required_revision,
                latest_required_hash, created_at, updated_at
            )
            {$selectSql}
            ON CONFLICT(ontology_version_id, recipe_id) DO UPDATE SET
                first_required_revision = MIN(
                    recipe_score_identity_projection_work
                        .first_required_revision,
                    excluded.first_required_revision
                ),
                first_event_id = CASE
                    WHEN recipe_score_identity_projection_work
                        .first_event_id = 0
                    THEN excluded.first_event_id
                    ELSE MIN(
                        recipe_score_identity_projection_work
                            .first_event_id,
                        excluded.first_event_id
                    )
                END,
                latest_event_id = MAX(
                    recipe_score_identity_projection_work
                        .latest_event_id,
                    excluded.latest_event_id
                ),
                latest_required_hash = CASE
                    WHEN excluded.latest_required_revision >=
                        recipe_score_identity_projection_work
                            .latest_required_revision
                    THEN excluded.latest_required_hash
                    ELSE recipe_score_identity_projection_work
                        .latest_required_hash
                END,
                latest_required_revision = MAX(
                    recipe_score_identity_projection_work
                        .latest_required_revision,
                    excluded.latest_required_revision
                ),
                updated_at = CURRENT_TIMESTAMP
        ";
    }

function ingredientOntologyV3IdentityProjectionLanguageSql(
    string $column
): string {
    return "
        CASE
            WHEN lower(replace({$column}, '_', '-')) = 'und'
            THEN 'und'
            ELSE substr(
                lower(replace({$column}, '_', '-')) || '-',
                1,
                instr(
                    lower(replace({$column}, '_', '-')) || '-',
                    '-'
                ) - 1
            )
        END
    ";
}

function ingredientOntologyV3IdentityProjectionDependencyExistsSql(
    string $extensionAlias,
    string $afterRecipeSql,
    ?string $requiredRevisionSql = null,
    string $resolutionInputSql = '',
    ?string $eventAlias = null
): string {
    $mappingLanguage =
        ingredientOntologyV3IdentityProjectionLanguageSql(
            'mapping.language'
        );
    $annexLanguage =
        ingredientOntologyV3IdentityProjectionLanguageSql(
            'recipe_annex.language'
        );
    $extensionLanguage =
        ingredientOntologyV3IdentityProjectionLanguageSql(
            $extensionAlias . '.language'
        );
    $requiresWork = (
        $requiredRevisionSql !== null
        && $resolutionInputSql !== ''
    ) ? ingredientOntologyV3IdentityProjectionRecipeNeedsWorkSql(
        'ingredient.recipe_id',
        $extensionAlias,
        $requiredRevisionSql,
        $resolutionInputSql
    ) : '1 = 1';
    if ($eventAlias !== null && $requiresWork !== '1 = 1') {
        $requiresWork = "(
            {$eventAlias}.product_id IS NOT NULL
            OR {$eventAlias}.source_revision IS NOT NULL
            OR {$requiresWork}
        )";
    }
    return "
        (
            EXISTS (
                SELECT 1
                FROM ingredient_ontology_mappings mapping
                JOIN recipe_ingredients ingredient
                  ON ingredient.id = mapping.owner_id
                JOIN recipe_catalog recipe
                  ON recipe.id = ingredient.recipe_id
                 AND recipe.deleted_at IS NULL
                WHERE mapping.ontology_version_id =
                    {$extensionAlias}.ontology_version_id
                  AND mapping.owner_type = 'recipe_ingredient'
                  AND mapping.normalized_label =
                    {$extensionAlias}.normalized_label
                  AND {$mappingLanguage} = {$extensionLanguage}
                  AND ingredient.recipe_id >
                    CAST({$afterRecipeSql} AS INTEGER)
                  AND {$requiresWork}
            )
            OR EXISTS (
                SELECT 1
                FROM ingredient_ontology_recipe_identity_annex
                    recipe_annex
                JOIN recipe_ingredients ingredient
                  ON ingredient.id =
                    recipe_annex.recipe_ingredient_id
                JOIN recipe_catalog recipe
                  ON recipe.id = ingredient.recipe_id
                 AND recipe.deleted_at IS NULL
                WHERE recipe_annex.ontology_version_id =
                    {$extensionAlias}.ontology_version_id
                  AND recipe_annex.ontology_content_hash =
                    {$extensionAlias}.ontology_content_hash
                  AND recipe_annex.ontology_seal_hash =
                    {$extensionAlias}.ontology_seal_hash
                  AND recipe_annex.normalized_label =
                    {$extensionAlias}.normalized_label
                  AND recipe_annex.resolver_version = ?
                  AND recipe_annex.review_manifest_hash = ?
                  AND {$annexLanguage} = {$extensionLanguage}
                  AND ingredient.recipe_id >
                    CAST({$afterRecipeSql} AS INTEGER)
                  AND {$requiresWork}
            )
        )
    ";
}

function ingredientOntologyV3IdentityProjectionRecipeNeedsWorkSql(
    string $recipeIdSql,
    string $extensionAlias,
    string $requiredRevisionSql,
    string $resolutionInputSql
): string {
    return "
        NOT EXISTS (
            SELECT 1
            FROM recipe_score_effective_sources effective
            JOIN recipe_score_revisions score_revision
              ON score_revision.id = effective.score_revision_id
             AND score_revision.status = 'ready'
            JOIN ingredient_ontology_corpus_annex_revisions score_annex
              ON score_annex.id =
                    score_revision.corpus_annex_revision_id
             AND score_annex.status = 'ready'
             AND score_annex.revision_hash =
                    score_revision.corpus_annex_hash
            WHERE effective.recipe_id = {$recipeIdSql}
              AND score_revision.ontology_version_id =
                    {$extensionAlias}.ontology_version_id
              AND score_revision.identity_extension_revision >=
                    CAST({$requiredRevisionSql} AS INTEGER)
              AND (
                    score_revision.covered_identity_extension_revision >=
                        CAST({$requiredRevisionSql} AS INTEGER)
                    OR json_extract(
                        score_revision.validation_report_json,
                        '$.materialized_hash_algorithm'
                    ) = 'parent-delta-v2'
                    AND EXISTS (
                        SELECT 1
                        FROM
                            ingredient_ontology_corpus_annex_effective_aggregates
                                recipe_aggregate
                        WHERE recipe_aggregate.ontology_version_id =
                                {$extensionAlias}.ontology_version_id
                          AND recipe_aggregate.aggregate_type = 'recipe'
                          AND recipe_aggregate.aggregate_id =
                                {$recipeIdSql}
                          AND recipe_aggregate.head_revision_id =
                                score_annex.id
                          AND recipe_aggregate.head_revision_hash =
                                score_annex.revision_hash
                    )
              )
              AND score_annex.ontology_version_id =
                    {$extensionAlias}.ontology_version_id
              AND score_annex.identity_extension_revision >=
                    CAST({$requiredRevisionSql} AS INTEGER)
              AND score_annex.resolution_input_hash =
                    {$resolutionInputSql}
        )
    ";
}

function ingredientOntologyV3IdentityProjectionSeedEvents(
    PDO $db,
    int $versionId,
    int $discoveredRevision,
    int $targetRevision,
    int $sourceThroughRevision,
    array $productIds
): void {
    $dependencyExists =
        ingredientOntologyV3IdentityProjectionDependencyExistsSql(
            'extension',
            '0'
        );
    $db->prepare("
        INSERT OR IGNORE INTO recipe_score_identity_projection_events (
            ontology_version_id, event_key,
            required_revision, required_hash,
            extension_entity_id, product_id, source_revision,
            after_recipe_id, completed, created_at, updated_at
        )
        SELECT extension.ontology_version_id,
               'extension:' || extension.created_revision,
               extension.created_revision, extension.content_hash,
               extension.id, NULL, NULL, 0, 0,
               CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        FROM ingredient_ontology_identity_extension_entities extension
        WHERE extension.ontology_version_id = ?
          AND extension.created_revision > ?
          AND extension.created_revision <= ?
          AND extension.status = 'active'
          AND {$dependencyExists}
        ORDER BY extension.created_revision
    ")->execute([
        $versionId,
        $discoveredRevision,
        $targetRevision,
        ingredientOntologyV3RecipeIdentityResolverVersion(),
        ingredientOntologyV3IdentityAnnexReviewManifestHash(),
    ]);
    $productIds = array_values(array_unique(array_filter(
        array_map('intval', $productIds),
        static fn(int $productId): bool => $productId > 0
    )));
    if (!$productIds || $targetRevision <= 0) {
        return;
    }
    $targetHash = ingredientOntologyV3IdentityProjectionHashAtRevision(
        $db,
        $versionId,
        $targetRevision
    );
    foreach (array_chunk($productIds, 200) as $chunk) {
        $placeholders = implode(
            ',',
            array_fill(0, count($chunk), '?')
        );
        $db->prepare("
            INSERT OR IGNORE INTO
                recipe_score_identity_projection_events (
                ontology_version_id, event_key,
                required_revision, required_hash,
                extension_entity_id, product_id, source_revision,
                after_recipe_id, completed, created_at, updated_at
            )
            SELECT annex.ontology_version_id,
                   'product:' || annex.product_id || ':'
                       || ? || ':' || annex.extension_entity_id
                       || ':' || annex.evidence_hash,
                   ?, ?, annex.extension_entity_id,
                   annex.product_id, ?, 0, 0,
                   CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM ingredient_ontology_identity_annex annex
            WHERE annex.ontology_version_id = ?
              AND annex.product_id IN ({$placeholders})
              AND annex.status = 'accepted'
              AND annex.extension_entity_id IS NOT NULL
        ")->execute([
            $sourceThroughRevision,
            $targetRevision,
            $targetHash,
            $sourceThroughRevision,
            $versionId,
            ...$chunk,
        ]);
        $db->prepare("
            INSERT OR IGNORE INTO
                recipe_score_identity_projection_events (
                ontology_version_id, event_key,
                required_revision, required_hash,
                extension_entity_id, product_id, source_revision,
                after_recipe_id, completed, created_at, updated_at
            )
            SELECT history.ontology_version_id,
                   'history:' || history.id,
                   ?, ?, history.extension_entity_id,
                   history.product_id, history.source_revision,
                   0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM ingredient_ontology_identity_annex_history history
            WHERE history.ontology_version_id = ?
              AND history.product_id IN ({$placeholders})
              AND history.source_revision <= ?
              AND history.status = 'accepted'
              AND history.extension_entity_id IS NOT NULL
        ")->execute([
            $targetRevision,
            $targetHash,
            $versionId,
            ...$chunk,
            $sourceThroughRevision,
        ]);
    }
}

function ingredientOntologyV3IdentityProjectionCompleteEmptyEvents(
    PDO $db,
    int $versionId,
    int $limit,
    string $resolutionInputHash = ''
): int {
    $limit = max(1, min(10000, $limit));
    $hasResolutionInput =
        strlen($resolutionInputHash) === 64;
    $dependencyExists =
        ingredientOntologyV3IdentityProjectionDependencyExistsSql(
            'extension',
            'event.after_recipe_id',
            $hasResolutionInput
                ? 'event.required_revision'
                : null,
            $hasResolutionInput ? '?' : '',
            $hasResolutionInput ? 'event' : null
        );
    $stmt = $db->prepare("
        UPDATE recipe_score_identity_projection_events
        SET completed = 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id IN (
            SELECT event.id
            FROM recipe_score_identity_projection_events event
            JOIN ingredient_ontology_identity_extension_entities
                extension
              ON extension.id = event.extension_entity_id
             AND extension.ontology_version_id =
                event.ontology_version_id
            WHERE event.ontology_version_id = ?
              AND event.completed = 0
              AND NOT {$dependencyExists}
            ORDER BY event.id
            LIMIT {$limit}
        )
    ");
    $params = [$versionId];
    if ($hasResolutionInput) {
        $params[] = $resolutionInputHash;
    }
    $params[] =
        ingredientOntologyV3RecipeIdentityResolverVersion();
    $params[] =
        ingredientOntologyV3IdentityAnnexReviewManifestHash();
    if ($hasResolutionInput) {
        $params[] = $resolutionInputHash;
    }
    $stmt->execute($params);
    return $stmt->rowCount();
}

function ingredientOntologyV3IdentityProjectionProcessEvents(
    PDO $db,
    int $versionId,
    int $limit,
    string $resolutionInputHash = ''
): int {
    $remaining = max(0, $limit);
    $inserted = 0;
    $loops = 0;
    $maximumLoops = max(
        32,
        min(2000, max(1, $limit) * 2)
    );
    $mappingLanguage =
        ingredientOntologyV3IdentityProjectionLanguageSql(
            'mapping.language'
        );
    $annexLanguage =
        ingredientOntologyV3IdentityProjectionLanguageSql(
            'recipe_annex.language'
        );
    $extensionLanguage =
        ingredientOntologyV3IdentityProjectionLanguageSql(
            'extension.language'
        );
    $hasResolutionInput =
        strlen($resolutionInputHash) === 64;
    ingredientOntologyV3IdentityProjectionCompleteEmptyEvents(
        $db,
        $versionId,
        max(1000, min(10000, max(1, $limit) * 20)),
        $resolutionInputHash
    );
    while ($remaining > 0 && $loops++ < $maximumLoops) {
        $eventStmt = $db->prepare("
            SELECT event.*, extension.normalized_label,
                   extension.language
            FROM recipe_score_identity_projection_events event
            JOIN ingredient_ontology_identity_extension_entities extension
              ON extension.id = event.extension_entity_id
             AND extension.ontology_version_id =
                 event.ontology_version_id
            WHERE event.ontology_version_id = ?
              AND event.completed = 0
            ORDER BY event.id
            LIMIT 1
        ");
        $eventStmt->execute([$versionId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            break;
        }
        $skipCoveredGenericEvent =
            $hasResolutionInput
            && $event['product_id'] === null
            && $event['source_revision'] === null;
        $requiresWork = $skipCoveredGenericEvent
            ? ingredientOntologyV3IdentityProjectionRecipeNeedsWorkSql(
                'ingredient.recipe_id',
                'extension',
                '?',
                '?'
            )
            : '1 = 1';
        $rowLimit = $remaining + 1;
        $recipeStmt = $db->prepare("
            SELECT DISTINCT dependency.recipe_id
            FROM (
                SELECT ingredient.recipe_id
                FROM ingredient_ontology_identity_extension_entities
                    extension
                JOIN ingredient_ontology_mappings mapping
                  ON mapping.ontology_version_id =
                     extension.ontology_version_id
                 AND mapping.owner_type = 'recipe_ingredient'
                 AND mapping.normalized_label =
                     extension.normalized_label
                 AND {$mappingLanguage} = {$extensionLanguage}
                JOIN recipe_ingredients ingredient
                  ON ingredient.id = mapping.owner_id
                JOIN recipe_catalog recipe
                  ON recipe.id = ingredient.recipe_id
                 AND recipe.deleted_at IS NULL
                WHERE extension.id = ?
                  AND ingredient.recipe_id > CAST(? AS INTEGER)
                  AND {$requiresWork}
                UNION
                SELECT ingredient.recipe_id
                FROM ingredient_ontology_identity_extension_entities
                    extension
                JOIN ingredient_ontology_recipe_identity_annex recipe_annex
                  ON recipe_annex.ontology_version_id =
                     extension.ontology_version_id
                 AND recipe_annex.ontology_content_hash =
                     extension.ontology_content_hash
                 AND recipe_annex.ontology_seal_hash =
                     extension.ontology_seal_hash
                 AND recipe_annex.normalized_label =
                     extension.normalized_label
                 AND recipe_annex.resolver_version = ?
                 AND recipe_annex.review_manifest_hash = ?
                 AND {$annexLanguage} = {$extensionLanguage}
                JOIN recipe_ingredients ingredient
                  ON ingredient.id =
                     recipe_annex.recipe_ingredient_id
                JOIN recipe_catalog recipe
                  ON recipe.id = ingredient.recipe_id
                 AND recipe.deleted_at IS NULL
                WHERE extension.id = ?
                  AND ingredient.recipe_id > CAST(? AS INTEGER)
                  AND {$requiresWork}
            ) dependency
            ORDER BY dependency.recipe_id
            LIMIT {$rowLimit}
        ");
        $afterRecipeId = (int)$event['after_recipe_id'];
        $params = [
            (int)$event['extension_entity_id'],
            $afterRecipeId,
        ];
        if ($skipCoveredGenericEvent) {
            $params[] = (int)$event['required_revision'];
            $params[] = (int)$event['required_revision'];
            $params[] = $resolutionInputHash;
        }
        $params[] =
            ingredientOntologyV3RecipeIdentityResolverVersion();
        $params[] =
            ingredientOntologyV3IdentityAnnexReviewManifestHash();
        $params[] =
            (int)$event['extension_entity_id'];
        $params[] =
            $afterRecipeId;
        if ($skipCoveredGenericEvent) {
            $params[] = (int)$event['required_revision'];
            $params[] = (int)$event['required_revision'];
            $params[] = $resolutionInputHash;
        }
        $recipeStmt->execute($params);
        $recipeIds = array_map(
            'intval',
            $recipeStmt->fetchAll(PDO::FETCH_COLUMN)
        );
        $hasMore = count($recipeIds) > $remaining;
        if ($hasMore) {
            $recipeIds = array_slice($recipeIds, 0, $remaining);
        }
        if ($recipeIds) {
            $placeholders = implode(
                ',',
                array_fill(0, count($recipeIds), '?')
            );
            $select = "
                SELECT ?, recipe.id, ?, ?, ?, ?, ?,
                       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM recipe_catalog recipe
                WHERE recipe.id IN ({$placeholders})
                  AND recipe.deleted_at IS NULL
            ";
            $upsert = $db->prepare(
                ingredientOntologyV3IdentityProjectionUpsertSql(
                    $select
                )
            );
            $upsert->execute([
                $versionId,
                (int)$event['id'],
                (int)$event['id'],
                (int)$event['required_revision'],
                (int)$event['required_revision'],
                (string)$event['required_hash'],
                ...$recipeIds,
            ]);
            $lastRecipeId =
                $recipeIds[array_key_last($recipeIds)];
            $inserted += count($recipeIds);
            $remaining -= count($recipeIds);
        } else {
            $lastRecipeId = $afterRecipeId;
        }
        $db->prepare("
            UPDATE recipe_score_identity_projection_events
            SET after_recipe_id = ?,
                completed = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND after_recipe_id = ?
        ")->execute([
            $lastRecipeId,
            $hasMore ? 0 : 1,
            (int)$event['id'],
            $afterRecipeId,
        ]);
        if ($hasMore) {
            break;
        }
    }
    return $inserted;
}

function ingredientOntologyV3IdentityProjectionDiscover(
    PDO $db,
    array $parentAnnex,
    array $targetSnapshot,
    array $productIds = []
): array {
        if (!databaseTransactionIsActive($db)) {
            throw new RuntimeException(
                'identity projection discovery requires a transaction'
            );
        }
        $versionId = (int)$parentAnnex['ontology_version_id'];
        $parentCovered = (int)(
            $parentAnnex['covered_identity_extension_revision']
                ?? $parentAnnex['identity_extension_revision']
        );
        $parentCoveredHash = (string)(
            $parentAnnex['covered_identity_extension_hash']
                ?? $parentAnnex['identity_extension_hash']
        );
        $targetRevision = max(
            0,
            (int)($targetSnapshot['revision'] ?? 0)
        );
        $targetHash = (string)($targetSnapshot['hash'] ?? '');
        if (
            $targetRevision < $parentCovered
            || !ingredientOntologyV3IdentityExtensionSnapshotMatches(
                $db,
                $versionId,
                $targetSnapshot
            )
        ) {
            throw new RuntimeException(
                'identity projection discovery fence is invalid'
            );
        }
        $state = ingredientOntologyV3IdentityProjectionWorkState(
            $db,
            $versionId
        );
        $headMatches =
            $state !== null
            && (int)($state['work_head_annex_revision_id'] ?? 0)
                === (int)$parentAnnex['id']
            && hash_equals(
                (string)($state['work_head_annex_hash'] ?? ''),
                (string)$parentAnnex['revision_hash']
            )
            && (int)$state['covered_revision'] === $parentCovered
            && hash_equals(
                (string)$state['covered_hash'],
                $parentCoveredHash
            );
        if (!$headMatches) {
            $db->prepare("
                INSERT INTO recipe_score_identity_projection_state (
                    ontology_version_id,
                    work_head_annex_revision_id,
                    work_head_annex_hash,
                    discovered_revision, discovered_hash,
                    covered_revision, covered_hash, updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(ontology_version_id) DO UPDATE SET
                    work_head_annex_revision_id =
                        excluded.work_head_annex_revision_id,
                    work_head_annex_hash =
                        excluded.work_head_annex_hash,
                    discovered_revision = MAX(
                        recipe_score_identity_projection_state
                            .discovered_revision,
                        excluded.discovered_revision
                    ),
                    discovered_hash = CASE
                        WHEN excluded.discovered_revision >=
                            recipe_score_identity_projection_state
                                .discovered_revision
                        THEN excluded.discovered_hash
                        ELSE recipe_score_identity_projection_state
                            .discovered_hash
                    END,
                    covered_revision = excluded.covered_revision,
                    covered_hash = excluded.covered_hash,
                    updated_at = CURRENT_TIMESTAMP
            ")->execute([
                $versionId,
                (int)$parentAnnex['id'],
                (string)$parentAnnex['revision_hash'],
                $parentCovered,
                $parentCoveredHash,
                $parentCovered,
                $parentCoveredHash,
            ]);
            $state = ingredientOntologyV3IdentityProjectionWorkState(
                $db,
                $versionId
            );
        }
        $discovered = (int)($state['discovered_revision'] ?? 0);
        if ($discovered > $targetRevision) {
            throw new RuntimeException(
                'identity projection discovery revision regressed'
            );
        }
        $scoreState = recipeScoreState($db);
        ingredientOntologyV3IdentityProjectionSeedEvents(
            $db,
            $versionId,
            $discovered,
            $targetRevision,
            (int)$scoreState['ontology_source_revision'],
            $productIds
        );
        if ($targetRevision > $discovered) {
            $db->prepare("
                UPDATE recipe_score_identity_projection_state
                SET discovered_revision = ?,
                    discovered_hash = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE ontology_version_id = ?
                  AND discovered_revision = ?
            ")->execute([
                $targetRevision,
                $targetHash,
                $versionId,
                $discovered,
            ]);
        }
        $discoveryLimit = function_exists(
            'ingredientOntologyV3IncrementalProductLimit'
        ) ? ingredientOntologyV3IncrementalProductLimit() : 250;
        ingredientOntologyV3IdentityProjectionProcessEvents(
            $db,
            $versionId,
            $discoveryLimit,
            (string)$parentAnnex['resolution_input_hash']
        );
        $capturedEventId = (int)$db->query("
            SELECT COALESCE(MAX(id), 0)
            FROM recipe_score_identity_projection_events
            WHERE ontology_version_id = {$versionId}
        ")->fetchColumn();
        return [
            'captured_revision' => $targetRevision,
            'captured_hash' => $targetHash,
            'captured_event_id' => $capturedEventId,
            'covered_revision' => $parentCovered,
            'covered_hash' => $parentCoveredHash,
            'pending_recipe_count' =>
                ingredientOntologyV3IdentityProjectionPendingCount(
                    $db,
                    $versionId
                ),
        ];
    }

function ingredientOntologyV3IdentityProjectionPendingRecipes(
    PDO $db,
    int $versionId,
    int $limit
): array {
        $limit = max(0, $limit);
        if ($versionId <= 0 || $limit === 0) {
            return [];
        }
        $stmt = $db->prepare("
            SELECT recipe_id, first_event_id, latest_event_id,
                   first_required_revision,
                   latest_required_revision, latest_required_hash,
                   created_at, updated_at
            FROM recipe_score_identity_projection_work
            WHERE ontology_version_id = ?
            ORDER BY first_required_revision, updated_at, recipe_id
            LIMIT ?
        ");
        $stmt->bindValue(1, $versionId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static fn(array $row): array => [
            'recipe_id' => (int)$row['recipe_id'],
            'first_event_id' => (int)$row['first_event_id'],
            'latest_event_id' => (int)$row['latest_event_id'],
            'first_required_revision' =>
                (int)$row['first_required_revision'],
            'latest_required_revision' =>
                (int)$row['latest_required_revision'],
            'latest_required_hash' =>
                (string)$row['latest_required_hash'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

function ingredientOntologyV3IdentityProjectionCoverageAfter(
    PDO $db,
    int $versionId,
    int $parentCoveredRevision,
    int $capturedRevision,
    array $processedRecipeIds,
    ?int $maximumEventId = null
): array {
        $processedRecipeIds = array_values(array_unique(array_filter(
            array_map('intval', $processedRecipeIds),
            static fn(int $recipeId): bool => $recipeId > 0
        )));
        $state = ingredientOntologyV3IdentityProjectionWorkState(
            $db,
            $versionId
        );
        if (
            $state === null
            || (int)$state['discovered_revision'] < $capturedRevision
        ) {
            throw new RuntimeException(
                'identity projection discovery is incomplete'
            );
        }
        $minimums = [];
        $params = [$versionId];
        $selectedWhere = '';
        $eventWhere = '';
        if ($maximumEventId !== null) {
            $eventWhere = ' AND first_event_id <= ?';
            $params[] = max(0, $maximumEventId);
        }
        if ($processedRecipeIds) {
            $selectedWhere = ' AND recipe_id NOT IN ('
                . implode(
                    ',',
                    array_fill(0, count($processedRecipeIds), '?')
                )
                . ')';
            array_push($params, ...$processedRecipeIds);
        }
        $remaining = $db->prepare("
            SELECT MIN(first_required_revision)
            FROM recipe_score_identity_projection_work
            WHERE ontology_version_id = ?
              {$eventWhere}
              {$selectedWhere}
        ");
        $remaining->execute($params);
        $minimum = $remaining->fetchColumn();
        if ($minimum !== false && $minimum !== null) {
            $minimums[] = (int)$minimum;
        }
        if ($processedRecipeIds && $maximumEventId === null) {
            $selected = $db->prepare("
                SELECT MIN(MAX(first_required_revision, ?))
                FROM recipe_score_identity_projection_work
                WHERE ontology_version_id = ?
                  AND latest_required_revision > ?
                  AND recipe_id IN ("
                    . implode(
                        ',',
                        array_fill(0, count($processedRecipeIds), '?')
                    )
                    . ")
            ");
            $selected->execute([
                $capturedRevision + 1,
                $versionId,
                $capturedRevision,
                ...$processedRecipeIds,
            ]);
            $minimum = $selected->fetchColumn();
            if ($minimum !== false && $minimum !== null) {
                $minimums[] = (int)$minimum;
            }
        }
        $undiscovered = $db->prepare("
            SELECT MIN(required_revision)
            FROM recipe_score_identity_projection_events
            WHERE ontology_version_id = ?
              AND completed = 0
              " . ($maximumEventId !== null ? "AND id <= ?" : "") . "
        ");
        $undiscovered->execute(
            $maximumEventId !== null
                ? [$versionId, max(0, $maximumEventId)]
                : [$versionId]
        );
        $minimum = $undiscovered->fetchColumn();
        if ($minimum !== false && $minimum !== null) {
            $minimums[] = (int)$minimum;
        }
        $coveredRevision = $minimums
            ? min($capturedRevision, min($minimums) - 1)
            : $capturedRevision;
        $coveredRevision = max(
            $parentCoveredRevision,
            min(
                $coveredRevision,
                (int)$state['discovered_revision']
            )
        );
        return [
            'revision' => $coveredRevision,
            'hash' => ingredientOntologyV3IdentityProjectionHashAtRevision(
                $db,
                $versionId,
                $coveredRevision
            ),
        ];
    }

function ingredientOntologyV3IdentityProjectionPublish(
    PDO $db,
    array $parentAnnex,
    array $publishedAnnex,
    int $capturedRevision,
    int $capturedEventId,
    array $processedRecipeIds,
    array $coveredSnapshot
): void {
        if (!databaseTransactionIsActive($db)) {
            throw new RuntimeException(
                'identity projection publication requires a transaction'
            );
        }
        $versionId = (int)$publishedAnnex['ontology_version_id'];
        $processedRecipeIds = array_values(array_unique(array_filter(
            array_map('intval', $processedRecipeIds),
            static fn(int $recipeId): bool => $recipeId > 0
        )));
        if ($processedRecipeIds) {
            $placeholders = implode(
                ',',
                array_fill(0, count($processedRecipeIds), '?')
            );
            $db->prepare("
                DELETE FROM recipe_score_identity_projection_work
                WHERE ontology_version_id = ?
                  AND latest_required_revision <= ?
                  AND latest_event_id <= ?
                  AND recipe_id IN ({$placeholders})
            ")->execute([
                $versionId,
                $capturedRevision,
                $capturedEventId,
                ...$processedRecipeIds,
            ]);
            $db->prepare("
                UPDATE recipe_score_identity_projection_work
                SET first_required_revision = MAX(
                        first_required_revision,
                        ?
                    ),
                    updated_at = CURRENT_TIMESTAMP
                WHERE ontology_version_id = ?
                  AND first_required_revision <= ?
                  AND latest_required_revision > ?
                  AND latest_event_id <= ?
                  AND recipe_id IN ({$placeholders})
            ")->execute([
                $capturedRevision + 1,
                $versionId,
                $capturedRevision,
                $capturedRevision,
                $capturedEventId,
                ...$processedRecipeIds,
            ]);
        }
        $db->prepare("
            UPDATE recipe_score_identity_projection_state
            SET work_head_annex_revision_id = ?,
                work_head_annex_hash = ?,
                covered_revision = ?,
                covered_hash = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE ontology_version_id = ?
              AND work_head_annex_revision_id = ?
              AND work_head_annex_hash = ?
        ")->execute([
            (int)$publishedAnnex['id'],
            (string)$publishedAnnex['revision_hash'],
            (int)$coveredSnapshot['revision'],
            (string)$coveredSnapshot['hash'],
            $versionId,
            (int)$parentAnnex['id'],
            (string)$parentAnnex['revision_hash'],
        ]);
        $state = ingredientOntologyV3IdentityProjectionWorkState(
            $db,
            $versionId
        );
        if (
            $state === null
            || (int)$state['work_head_annex_revision_id']
                !== (int)$publishedAnnex['id']
            || (int)$state['covered_revision']
                !== (int)$coveredSnapshot['revision']
            || !hash_equals(
                (string)$state['covered_hash'],
                (string)$coveredSnapshot['hash']
            )
        ) {
            throw new RuntimeException(
                'identity projection publication state changed'
            );
        }
    }

function ingredientOntologyV3IdentityProjectionRebaseHead(
    PDO $db,
    array $parentAnnex,
    array $publishedAnnex
): void {
    $versionId = (int)$publishedAnnex['ontology_version_id'];
    $db->prepare("
        UPDATE recipe_score_identity_projection_state
        SET work_head_annex_revision_id = ?,
            work_head_annex_hash = ?,
            covered_revision = ?,
            covered_hash = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE ontology_version_id = ?
          AND (
              work_head_annex_revision_id IS NULL
              OR (
                  work_head_annex_revision_id = ?
                  AND work_head_annex_hash = ?
              )
          )
    ")->execute([
        (int)$publishedAnnex['id'],
        (string)$publishedAnnex['revision_hash'],
        (int)$publishedAnnex[
            'covered_identity_extension_revision'
        ],
        (string)$publishedAnnex[
            'covered_identity_extension_hash'
        ],
        $versionId,
        (int)$parentAnnex['id'],
        (string)$parentAnnex['revision_hash'],
    ]);
}

function ingredientOntologyV3IdentityHistoricalBindingsForProducts(
    PDO $db,
    int $versionId,
    array $productIds,
    int $throughSourceRevision
): array {
    $productIds = array_values(array_unique(array_filter(
        array_map('intval', $productIds),
        static fn(int $productId): bool => $productId > 0
    )));
    $result = [
        'entity_ids' => [],
        'extension_entity_ids' => [],
    ];
    if (!$productIds || $throughSourceRevision <= 0) {
        return $result;
    }
    foreach (array_chunk($productIds, 200) as $chunk) {
        $placeholders = implode(
            ',',
            array_fill(0, count($chunk), '?')
        );
        $stmt = $db->prepare("
            SELECT entity_id, extension_entity_id
            FROM ingredient_ontology_identity_annex_history
            WHERE ontology_version_id = ?
              AND product_id IN ({$placeholders})
              AND source_revision <= ?
              AND status = 'accepted'
            ORDER BY source_revision, id
        ");
        $stmt->execute([
            $versionId,
            ...$chunk,
            $throughSourceRevision,
        ]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['entity_id'] !== null) {
                $result['entity_ids'][(int)$row['entity_id']] = true;
            }
            if ($row['extension_entity_id'] !== null) {
                $result['extension_entity_ids'][
                    (int)$row['extension_entity_id']
                ] = true;
            }
        }
    }
    return [
        'entity_ids' => array_map(
            'intval',
            array_keys($result['entity_ids'])
        ),
        'extension_entity_ids' => array_map(
            'intval',
            array_keys($result['extension_entity_ids'])
        ),
    ];
}

function ingredientOntologyV3IdentityExtensionRecipeIdsForProductsQuery(
    PDO $db,
    int $versionId,
    array $productIds,
    int $limit
): array {
    $limit = max(1, $limit);
    $rowLimit = $limit + 1;
    $placeholders = implode(
        ',',
        array_fill(0, count($productIds), '?')
    );
    $stmt = $db->prepare("
        SELECT DISTINCT ingredient.recipe_id
        FROM ingredient_ontology_identity_annex product_annex
        JOIN ingredient_ontology_identity_extension_entities extension
          ON extension.id = product_annex.extension_entity_id
         AND extension.ontology_version_id =
             product_annex.ontology_version_id
         AND extension.ontology_content_hash =
             product_annex.ontology_content_hash
         AND extension.ontology_seal_hash =
             product_annex.ontology_seal_hash
         AND extension.status = 'active'
        JOIN ingredient_ontology_mappings mapping
          ON mapping.ontology_version_id =
             product_annex.ontology_version_id
         AND mapping.owner_type = 'recipe_ingredient'
         AND mapping.normalized_label = extension.normalized_label
         AND (
             CASE
                 WHEN lower(replace(mapping.language, '_', '-')) = 'und'
                 THEN 'und'
                 ELSE substr(
                     lower(replace(mapping.language, '_', '-')) || '-',
                     1,
                     instr(
                         lower(replace(mapping.language, '_', '-')) || '-',
                         '-'
                     ) - 1
                 )
             END
         ) = (
             CASE
                 WHEN lower(replace(extension.language, '_', '-')) = 'und'
                 THEN 'und'
                 ELSE substr(
                     lower(replace(extension.language, '_', '-')) || '-',
                     1,
                     instr(
                         lower(replace(extension.language, '_', '-')) || '-',
                         '-'
                     ) - 1
                 )
             END
         )
        JOIN recipe_ingredients ingredient
          ON ingredient.id = mapping.owner_id
        JOIN recipe_catalog recipe
          ON recipe.id = ingredient.recipe_id
         AND recipe.deleted_at IS NULL
        WHERE product_annex.ontology_version_id = ?
          AND product_annex.product_id IN ({$placeholders})
          AND product_annex.status = 'accepted'
          AND product_annex.extension_entity_id IS NOT NULL
          AND product_annex.resolver_version = ?
          AND product_annex.review_manifest_hash = ?
        ORDER BY ingredient.recipe_id
        LIMIT {$rowLimit}
    ");
    $stmt->execute([
        $versionId,
        ...$productIds,
        ingredientOntologyV3ProductIdentityResolverVersion(),
        ingredientOntologyV3IdentityAnnexReviewManifestHash(),
    ]);
    $recipeIds = [];
    while (($recipeId = $stmt->fetchColumn()) !== false) {
        if (count($recipeIds) >= $limit) {
            break;
        }
        $recipeIds[(int)$recipeId] = true;
    }
    if (ingredientOntologyV3TableExists(
        $db,
        'ingredient_ontology_recipe_identity_annex'
    )) {
        $annex = $db->prepare("
            SELECT DISTINCT ingredient.recipe_id
            FROM ingredient_ontology_identity_annex product_annex
            JOIN ingredient_ontology_identity_extension_entities extension
              ON extension.id = product_annex.extension_entity_id
             AND extension.ontology_version_id =
                 product_annex.ontology_version_id
             AND extension.ontology_content_hash =
                 product_annex.ontology_content_hash
             AND extension.ontology_seal_hash =
                 product_annex.ontology_seal_hash
             AND extension.status = 'active'
            JOIN ingredient_ontology_recipe_identity_annex recipe_annex
              ON recipe_annex.ontology_version_id =
                 product_annex.ontology_version_id
             AND recipe_annex.ontology_content_hash =
                 product_annex.ontology_content_hash
             AND recipe_annex.ontology_seal_hash =
                 product_annex.ontology_seal_hash
             AND recipe_annex.normalized_label =
                 extension.normalized_label
             AND recipe_annex.resolver_version = ?
             AND recipe_annex.review_manifest_hash = ?
             AND (
                 CASE
                     WHEN lower(replace(
                         recipe_annex.language, '_', '-'
                     )) = 'und'
                     THEN 'und'
                     ELSE substr(
                         lower(replace(
                             recipe_annex.language, '_', '-'
                         )) || '-',
                         1,
                         instr(
                             lower(replace(
                                 recipe_annex.language, '_', '-'
                             )) || '-',
                             '-'
                         ) - 1
                     )
                 END
             ) = (
                 CASE
                     WHEN lower(replace(
                         extension.language, '_', '-'
                     )) = 'und'
                     THEN 'und'
                     ELSE substr(
                         lower(replace(
                             extension.language, '_', '-'
                         )) || '-',
                         1,
                         instr(
                             lower(replace(
                                 extension.language, '_', '-'
                             )) || '-',
                             '-'
                         ) - 1
                     )
                 END
             )
            JOIN recipe_ingredients ingredient
              ON ingredient.id = recipe_annex.recipe_ingredient_id
            JOIN recipe_catalog recipe
              ON recipe.id = ingredient.recipe_id
             AND recipe.deleted_at IS NULL
            WHERE product_annex.ontology_version_id = ?
              AND product_annex.product_id IN ({$placeholders})
              AND product_annex.status = 'accepted'
              AND product_annex.extension_entity_id IS NOT NULL
              AND product_annex.resolver_version = ?
              AND product_annex.review_manifest_hash = ?
            ORDER BY ingredient.recipe_id
            LIMIT {$rowLimit}
        ");
        $annex->execute([
            ingredientOntologyV3RecipeIdentityResolverVersion(),
            ingredientOntologyV3IdentityAnnexReviewManifestHash(),
            $versionId,
            ...$productIds,
            ingredientOntologyV3ProductIdentityResolverVersion(),
            ingredientOntologyV3IdentityAnnexReviewManifestHash(),
        ]);
        while (($recipeId = $annex->fetchColumn()) !== false) {
            if (count($recipeIds) >= $limit) {
                break;
            }
            $recipeIds[(int)$recipeId] = true;
        }
    }
    $result = array_map('intval', array_keys($recipeIds));
    sort($result, SORT_NUMERIC);
    return $result;
}

function ingredientOntologyV3ProductReadinessTableExists(
    PDO $db
): bool {
    return ingredientOntologyV3TableExists(
        $db,
        'ingredient_ontology_product_readiness'
    );
}

function ingredientOntologyV3ProductReadinessRetryDelaySeconds(
    int $attempts
): int {
    return min(
        INGREDIENT_ONTOLOGY_PRODUCT_READINESS_MAX_RETRY_SECONDS,
        match (max(1, $attempts)) {
        1 => 1,
        2 => 5,
        3 => 30,
        4 => 120,
        5 => 600,
        6 => 1800,
        default =>
            INGREDIENT_ONTOLOGY_PRODUCT_READINESS_MAX_RETRY_SECONDS,
        }
    );
}

function ingredientOntologyV3ProductReadinessRow(
    PDO $db,
    int $productId
): ?array {
    if (
        $productId <= 0
        || !ingredientOntologyV3ProductReadinessTableExists($db)
    ) {
        return null;
    }
    $stmt = $db->prepare("
        SELECT *
        FROM ingredient_ontology_product_readiness
        WHERE product_id = ?
    ");
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ingredientOntologyV3ProductHasActiveInventory(
    PDO $db,
    int $productId
): bool {
    $stmt = $db->prepare("
        SELECT 1
        FROM inventory
        WHERE product_id = ? AND quantity > 0
        LIMIT 1
    ");
    $stmt->execute([$productId]);
    return $stmt->fetchColumn() !== false;
}

function ingredientOntologyV3ProductReadinessRecordResolution(
    PDO $db,
    array $admission,
    string $trigger,
    bool $incrementAttempt = false
): array {
    $productId = (int)($admission['product_id'] ?? 0);
    if (
        $productId <= 0
        || empty($admission['available'])
        || !ingredientOntologyV3ProductReadinessTableExists($db)
    ) {
        return [
            'available' => false,
            'product_id' => $productId,
        ];
    }
    $previous =
        ingredientOntologyV3ProductReadinessRow($db, $productId);
    $ownerFingerprint = (string)$admission['owner_fingerprint'];
    $evidenceHash = (string)$admission['evidence_hash'];
    $identityStatus = (string)$admission['status'];
    $storedIdentityStatus = $identityStatus;
    $sameOwner = $previous !== null
        && hash_equals(
            (string)$previous['owner_fingerprint'],
            $ownerFingerprint
        );
    $sameEvidence = $sameOwner
        && hash_equals(
            (string)$previous['annex_evidence_hash'],
            $evidenceHash
        );
    $now = gmdate('Y-m-d H:i:s');
    $requestedAt = $sameEvidence
        ? (string)$previous['requested_at']
        : $now;
    $attempts = $sameEvidence
        ? (int)$previous['attempts']
        : 0;
    $scoreAttempts = $sameEvidence
        ? (int)$previous['score_attempts']
        : 0;
    $requestedInventoryRevision = $sameEvidence
        && $previous['requested_inventory_revision'] !== null
            ? (int)$previous['requested_inventory_revision']
            : null;
    $status = 'retry';
    $nextRetryAt = null;
    $startedAt = null;
    $readyAt = null;
    $failedAt = null;
    $scoreRevisionId = null;
    $affectedRecipeCount = 0;
    $visibleMs = null;
    $lastErrorKind = '';
    $lastError = '';

    if (!empty($admission['sealed_mapping_preserved'])) {
        $storedIdentityStatus = 'accepted';
        $status = 'ready';
        $attempts = 0;
        $scoreAttempts = 0;
        $readyAt = $now;
    } elseif ($identityStatus === 'accepted') {
        $preserveState = $sameEvidence
            || (
                empty($admission['semantic_changed'])
                && $sameOwner
            );
        $previousStatus = $preserveState
            ? (string)($previous['status'] ?? '')
            : '';
        $status = in_array(
            $previousStatus,
            ['accepted_unscored', 'scoring', 'ready'],
            true
        ) ? $previousStatus : 'accepted_unscored';
        $attempts = 0;
        $scoreAttempts = $status === 'ready'
            ? (int)($previous['score_attempts'] ?? 0)
            : 0;
        $startedAt = $status === 'scoring'
            ? ($previous['started_at'] ?? $now)
            : null;
        $readyAt = $status === 'ready'
            ? ($previous['ready_at'] ?? $now)
            : null;
        $scoreRevisionId = $status === 'ready'
            && $previous['score_revision_id'] !== null
                ? (int)$previous['score_revision_id']
                : null;
        $affectedRecipeCount = $status === 'ready'
            ? (int)($previous['affected_recipe_count'] ?? 0)
            : 0;
        $visibleMs = $status === 'ready'
            && $previous['visible_ms'] !== null
                ? (float)$previous['visible_ms']
                : null;
    } elseif ($identityStatus === 'rejected') {
        $status = 'non_satisfying';
        $attempts = 0;
        $scoreAttempts = 0;
        $lastErrorKind = (string)$admission['reason'];
        $lastError = 'Product is intentionally non-satisfying.';
    } else {
        $reason = (string)$admission['reason'];
        $permanentUnsupported =
            ingredientOntologyV3IdentityReadinessV2Enabled()
            && in_array(
                $reason,
                ['empty_label', 'exact_self_label_too_long'],
                true
            );
        if ($permanentUnsupported) {
            $storedIdentityStatus = 'rejected';
            $status = 'non_satisfying';
            $attempts = 0;
            $scoreAttempts = 0;
            $failedAt = $now;
            $lastErrorKind = $reason;
            $lastError = 'Product label cannot represent a food identity.';
        } else {
            if ($incrementAttempt) {
                $attempts = min(20, $attempts + 1);
            }
            $status = 'retry';
            $nextRetryAt = gmdate(
                'Y-m-d H:i:s',
                time()
                    + ingredientOntologyV3ProductReadinessRetryDelaySeconds(
                        $attempts + 1
                    )
            );
            $failedAt = null;
            $lastErrorKind = $reason;
            $lastError = mb_substr(
                'Identity unresolved after ' . trim($trigger) . '.',
                0,
                1000,
                'UTF-8'
            );
        }
    }

    $db->prepare("
        INSERT INTO ingredient_ontology_product_readiness (
            product_id, ontology_version_id,
            ontology_content_hash, ontology_seal_hash,
            owner_fingerprint, annex_evidence_hash,
            identity_status, status, attempts, max_attempts,
            score_attempts, next_retry_at,
            requested_inventory_revision, requested_at,
            started_at, ready_at, failed_at,
            score_revision_id, affected_recipe_count,
            visible_ms, last_error_kind, last_error,
            created_at, updated_at
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )
        ON CONFLICT(product_id) DO UPDATE SET
            ontology_version_id = excluded.ontology_version_id,
            ontology_content_hash = excluded.ontology_content_hash,
            ontology_seal_hash = excluded.ontology_seal_hash,
            owner_fingerprint = excluded.owner_fingerprint,
            annex_evidence_hash = excluded.annex_evidence_hash,
            identity_status = excluded.identity_status,
            status = excluded.status,
            attempts = excluded.attempts,
            max_attempts = excluded.max_attempts,
            score_attempts = excluded.score_attempts,
            next_retry_at = excluded.next_retry_at,
            requested_inventory_revision =
                excluded.requested_inventory_revision,
            requested_at = excluded.requested_at,
            started_at = excluded.started_at,
            ready_at = excluded.ready_at,
            failed_at = excluded.failed_at,
            score_revision_id = excluded.score_revision_id,
            affected_recipe_count = excluded.affected_recipe_count,
            visible_ms = excluded.visible_ms,
            last_error_kind = excluded.last_error_kind,
            last_error = excluded.last_error,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([
        $productId,
        (int)$admission['ontology_version_id'],
        (string)$admission['ontology_content_hash'],
        (string)$admission['ontology_seal_hash'],
        $ownerFingerprint,
        $evidenceHash,
        $storedIdentityStatus,
        $status,
        $attempts,
        INGREDIENT_ONTOLOGY_PRODUCT_READINESS_MAX_ATTEMPTS,
        $scoreAttempts,
        $nextRetryAt,
        $requestedInventoryRevision,
        $requestedAt,
        $startedAt,
        $readyAt,
        $failedAt,
        $scoreRevisionId,
        $affectedRecipeCount,
        $visibleMs,
        mb_substr($lastErrorKind, 0, 160, 'UTF-8'),
        $lastError,
    ]);
    return [
        'available' => true,
        'product_id' => $productId,
        'identity_status' => $storedIdentityStatus,
        'status' => $status,
        'attempts' => $attempts,
        'max_attempts' =>
            INGREDIENT_ONTOLOGY_PRODUCT_READINESS_MAX_ATTEMPTS,
        'next_retry_at' => $nextRetryAt,
        'terminal' => in_array(
            $status,
            ['non_satisfying', 'failed'],
            true
        ),
    ];
}

function ingredientOntologyV3IdentityAdmissionPublishProduct(
    PDO $db,
    int $productId,
    ?int $versionId = null,
    string $trigger = 'identity_refresh',
    bool $queueScore = true,
    bool $incrementAttempt = false,
    bool $resolveCoverageGaps = true,
    bool $createExtension = true,
    bool $lookupExtension = true,
    bool $preserveSealedMapping = true
): array {
    $admission = ingredientOntologyV3IdentityAnnexRefreshProduct(
        $db,
        $productId,
        $versionId,
        $resolveCoverageGaps,
        $createExtension,
        $lookupExtension,
        $preserveSealedMapping
    );
    if (empty($admission['available'])) {
        return $admission + [
            'readiness' => ['available' => false],
            'score_required' => false,
            'score_queued' => false,
        ];
    }
    $readiness =
        ingredientOntologyV3ProductReadinessRecordResolution(
            $db,
            $admission,
            $trigger,
            $incrementAttempt
        );
    $activeInventory =
        ingredientOntologyV3ProductHasActiveInventory(
            $db,
            $productId
        );
    $pendingRevision = 0;
    if (ingredientOntologyV3TableExists(
        $db,
        'recipe_score_pending_products'
    )) {
        $pending = $db->prepare("
            SELECT latest_inventory_revision
            FROM recipe_score_pending_products
            WHERE product_id = ?
        ");
        $pending->execute([$productId]);
        $pendingRevision = (int)($pending->fetchColumn() ?: 0);
    }
    $scoreRequired = $activeInventory && (
        !empty($admission['semantic_changed'])
        || (
            (string)$admission['status'] === 'accepted'
            && !in_array(
                (string)($readiness['status'] ?? ''),
                ['scoring', 'ready'],
                true
            )
        )
        || (
            (int)($admission['previous_entity_id'] ?? 0) !== 0
            && (string)$admission['status'] !== 'accepted'
        )
    );
    if (
        $queueScore
        && $scoreRequired
        && $pendingRevision <= 0
        && function_exists('recipeScoreMarkProductDirty')
    ) {
        $pendingRevision = recipeScoreMarkProductDirty(
            $db,
            $productId,
            mb_substr(trim($trigger), 0, 160, 'UTF-8')
        );
        $pending = $db->prepare("
            SELECT latest_inventory_revision
            FROM recipe_score_pending_products
            WHERE product_id = ?
        ");
        $pending->execute([$productId]);
        $pendingRevision = (int)($pending->fetchColumn() ?: 0);
    }
    if (
        $activeInventory
        && $pendingRevision > 0
        && ingredientOntologyV3ProductReadinessTableExists($db)
    ) {
        $db->prepare("
            UPDATE ingredient_ontology_product_readiness
            SET requested_inventory_revision = ?,
                requested_at = CASE
                    WHEN requested_inventory_revision IS NULL
                      OR requested_inventory_revision < ?
                    THEN CURRENT_TIMESTAMP
                    ELSE requested_at
                END,
                status = CASE
                    WHEN identity_status = 'accepted'
                    THEN 'accepted_unscored'
                    ELSE status
                END,
                updated_at = CURRENT_TIMESTAMP
            WHERE product_id = ?
              AND owner_fingerprint = ?
              AND annex_evidence_hash = ?
        ")->execute([
            $pendingRevision,
            $pendingRevision,
            $productId,
            (string)$admission['owner_fingerprint'],
            (string)$admission['evidence_hash'],
        ]);
        $readiness =
            ingredientOntologyV3ProductReadinessRow(
                $db,
                $productId
            ) ?? $readiness;
    }
    return $admission + [
        'readiness' => $readiness,
        'score_required' => $scoreRequired,
        'score_queued' =>
            $queueScore && $scoreRequired && $pendingRevision > 0,
        'score_inventory_revision' =>
            $pendingRevision > 0 ? $pendingRevision : null,
    ];
}

function ingredientOntologyV3ProductReadinessBeginScoring(
    PDO $db,
    array $admissions
): void {
    if (!ingredientOntologyV3ProductReadinessTableExists($db)) {
        return;
    }
    $stmt = $db->prepare("
        UPDATE ingredient_ontology_product_readiness
        SET status = 'scoring',
            started_at = CURRENT_TIMESTAMP,
            failed_at = NULL,
            last_error_kind = '',
            last_error = '',
            updated_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
          AND owner_fingerprint = ?
          AND annex_evidence_hash = ?
          AND identity_status = 'accepted'
    ");
    foreach ($admissions as $admission) {
        if (
            (string)($admission['status'] ?? '') !== 'accepted'
            && empty($admission['sealed_mapping_preserved'])
        ) {
            continue;
        }
        $stmt->execute([
            (int)$admission['product_id'],
            (string)$admission['owner_fingerprint'],
            (string)$admission['evidence_hash'],
        ]);
    }
}

function ingredientOntologyV3ProductReadinessMarkReady(
    PDO $db,
    array $admissions,
    int $scoreRevisionId,
    int $inventoryRevision,
    int $affectedRecipeCount
): void {
    if (!ingredientOntologyV3ProductReadinessTableExists($db)) {
        return;
    }
    $stmt = $db->prepare("
        UPDATE ingredient_ontology_product_readiness
        SET status = 'ready',
            attempts = 0,
            score_attempts = 0,
            next_retry_at = NULL,
            ready_at = CURRENT_TIMESTAMP,
            failed_at = NULL,
            score_revision_id = ?,
            affected_recipe_count = ?,
            last_error_kind = '',
            last_error = '',
            updated_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
          AND owner_fingerprint = ?
          AND annex_evidence_hash = ?
          AND identity_status = 'accepted'
          AND NOT EXISTS (
              SELECT 1
              FROM recipe_score_pending_products pending
              WHERE pending.product_id =
                    ingredient_ontology_product_readiness.product_id
                AND pending.latest_inventory_revision > ?
          )
    ");
    foreach ($admissions as $admission) {
        if (
            (string)($admission['status'] ?? '') !== 'accepted'
            && empty($admission['sealed_mapping_preserved'])
        ) {
            continue;
        }
        $stmt->execute([
            $scoreRevisionId,
            $affectedRecipeCount,
            (int)$admission['product_id'],
            (string)$admission['owner_fingerprint'],
            (string)$admission['evidence_hash'],
            $inventoryRevision,
        ]);
    }
}

function ingredientOntologyV3ProductReadinessBackfillReady(
    PDO $db,
    array $admission,
    array $activeScore
): bool {
    if (
        !ingredientOntologyV3ProductReadinessTableExists($db)
        || (string)($admission['status'] ?? '') !== 'accepted'
        || (int)($activeScore['id'] ?? 0) <= 0
        || (int)($activeScore['ontology_version_id'] ?? 0)
            !== (int)($admission['ontology_version_id'] ?? 0)
    ) {
        return false;
    }
    $affectedRecipeCount = 0;
    if (
        ingredientOntologyV3TableExists(
            $db,
            'recipe_score_match_contributors'
        )
        && ingredientOntologyV3TableExists(
            $db,
            'recipe_score_effective_sources'
        )
    ) {
        $count = $db->prepare("
            SELECT COUNT(DISTINCT contributor.recipe_id)
            FROM recipe_score_match_contributors contributor
            JOIN recipe_score_effective_sources source
              ON source.recipe_id = contributor.recipe_id
             AND source.score_revision_id =
                 contributor.score_revision_id
            WHERE contributor.product_id = ?
              AND contributor.semantic = 1
        ");
        $count->execute([(int)$admission['product_id']]);
        $affectedRecipeCount = (int)$count->fetchColumn();
    }
    $stmt = $db->prepare("
        UPDATE ingredient_ontology_product_readiness
        SET status = 'ready',
            attempts = 0,
            score_attempts = 0,
            next_retry_at = NULL,
            requested_inventory_revision = ?,
            ready_at = CURRENT_TIMESTAMP,
            failed_at = NULL,
            score_revision_id = ?,
            affected_recipe_count = ?,
            visible_ms = COALESCE(visible_ms, 0),
            last_error_kind = '',
            last_error = '',
            updated_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
          AND owner_fingerprint = ?
          AND annex_evidence_hash = ?
          AND identity_status = 'accepted'
          AND NOT EXISTS (
              SELECT 1
              FROM recipe_score_pending_products pending
              WHERE pending.product_id =
                    ingredient_ontology_product_readiness.product_id
          )
    ");
    $stmt->execute([
        (int)$activeScore['inventory_revision'],
        (int)$activeScore['id'],
        $affectedRecipeCount,
        (int)$admission['product_id'],
        (string)$admission['owner_fingerprint'],
        (string)$admission['evidence_hash'],
    ]);
    return $stmt->rowCount() === 1;
}

function ingredientOntologyV3ProductReadinessRecordVisibleMs(
    PDO $db,
    int $scoreRevisionId,
    float $visibleMs
): void {
    if (!ingredientOntologyV3ProductReadinessTableExists($db)) {
        return;
    }
    $db->prepare("
        UPDATE ingredient_ontology_product_readiness
        SET visible_ms = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE score_revision_id = ? AND status = 'ready'
    ")->execute([
        max(0.0, $visibleMs),
        $scoreRevisionId,
    ]);
}

function ingredientOntologyV3ProductReadinessScoreFailed(
    PDO $db,
    array $admissions,
    string $error
): void {
    if (!ingredientOntologyV3ProductReadinessTableExists($db)) {
        return;
    }
    $admissions = array_values(array_filter(
        $admissions,
        static fn(mixed $admission): bool =>
            is_array($admission)
            && (int)($admission['product_id'] ?? 0) > 0
            && strlen((string)($admission['owner_fingerprint'] ?? ''))
                === 64
            && strlen((string)($admission['evidence_hash'] ?? ''))
                === 64
    ));
    if (!$admissions) {
        return;
    }
    $stmt = $db->prepare("
        UPDATE ingredient_ontology_product_readiness
        SET status = CASE
                WHEN identity_status = 'accepted'
                THEN 'accepted_unscored'
                ELSE status
            END,
            score_attempts = score_attempts + 1,
            last_error_kind = 'score_publication_failed',
            last_error = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
          AND owner_fingerprint = ?
          AND annex_evidence_hash = ?
    ");
    $error = mb_substr($error, 0, 1000, 'UTF-8');
    foreach ($admissions as $admission) {
        $stmt->execute([
            $error,
            (int)$admission['product_id'],
            (string)$admission['owner_fingerprint'],
            (string)$admission['evidence_hash'],
        ]);
    }
}

function ingredientOntologyV3ProductReadinessRetryDue(
    PDO $db,
    int $limit = 25
): array {
    if (!ingredientOntologyV3ProductReadinessTableExists($db)) {
        return [
            'available' => false,
            'processed' => 0,
            'accepted' => 0,
            'terminal' => 0,
            'errors' => [],
        ];
    }
    $limit = max(1, min(100, $limit));
    $requeueHistorical = $db->prepare("
        UPDATE ingredient_ontology_product_readiness
        SET status = 'retry',
            next_retry_at = CURRENT_TIMESTAMP,
            failed_at = NULL,
            last_error_kind = 'historical_identity_requeued',
            last_error =
                'Historical unresolved identity requeued for exact-self admission.',
            updated_at = CURRENT_TIMESTAMP
        WHERE product_id IN (
            SELECT product_id
            FROM ingredient_ontology_product_readiness
            WHERE status = 'needs_review'
               OR (
                   status = 'failed'
                   AND identity_status = 'unresolved'
               )
            ORDER BY updated_at, product_id
            LIMIT ?
        )
    ");
    $requeueHistorical->execute([$limit]);
    $due = $db->query("
        SELECT 1
        FROM ingredient_ontology_product_readiness
        WHERE status = 'retry'
          AND next_retry_at IS NOT NULL
          AND next_retry_at <= CURRENT_TIMESTAMP
        LIMIT 1
    ")->fetchColumn();
    if ($due === false) {
        return [
            'available' => true,
            'processed' => 0,
            'accepted' => 0,
            'terminal' => 0,
            'errors' => [],
        ];
    }
    $processed = 0;
    $accepted = 0;
    $terminal = 0;
    $errors = [];
    dbBeginImmediateWithRetry($db);
    try {
        $rows = $db->query("
            SELECT product_id, owner_fingerprint,
                   annex_evidence_hash
            FROM ingredient_ontology_product_readiness
            WHERE status = 'retry'
              AND next_retry_at IS NOT NULL
              AND next_retry_at <= CURRENT_TIMESTAMP
            ORDER BY next_retry_at, product_id
            LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            $db->exec('COMMIT');
            return [
                'available' => true,
                'processed' => 0,
                'accepted' => 0,
                'terminal' => 0,
                'errors' => [],
            ];
        }
        $activeScore = function_exists('recipeScoreActiveRevision')
            ? recipeScoreActiveRevision($db)
            : null;
        $versionId = $activeScore !== null
            && $activeScore['ontology_version_id'] !== null
                ? (int)$activeScore['ontology_version_id']
                : null;
        foreach ($rows as $retryRow) {
            $productId = (int)$retryRow['product_id'];
            $savepoint = 'identity_readiness_retry_' . $productId;
            $db->exec("SAVEPOINT {$savepoint}");
            try {
                $result =
                    ingredientOntologyV3IdentityAdmissionPublishProduct(
                        $db,
                        $productId,
                        $versionId,
                        'bounded_identity_retry',
                        true,
                        true
                    );
                $db->exec("RELEASE SAVEPOINT {$savepoint}");
                $processed++;
                $accepted += !empty($result['accepted']) ? 1 : 0;
                $terminal += !empty(
                    $result['readiness']['terminal']
                ) ? 1 : 0;
            } catch (Throwable $error) {
                $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $db->exec("RELEASE SAVEPOINT {$savepoint}");
                $current =
                    ingredientOntologyV3ProductReadinessRow(
                        $db,
                        $productId
                    );
                $attempts = min(
                    20,
                    (int)($current['attempts'] ?? 0) + 1
                );
                $db->prepare("
                    UPDATE ingredient_ontology_product_readiness
                    SET status = 'retry',
                        attempts = ?,
                        next_retry_at = ?,
                        failed_at = NULL,
                        last_error_kind =
                            'identity_retry_exception',
                        last_error = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE product_id = ?
                      AND status = 'retry'
                      AND owner_fingerprint = ?
                      AND annex_evidence_hash = ?
                      AND next_retry_at <= CURRENT_TIMESTAMP
                ")->execute([
                    $attempts,
                    gmdate(
                        'Y-m-d H:i:s',
                        time()
                            + ingredientOntologyV3ProductReadinessRetryDelaySeconds(
                                $attempts + 1
                            )
                    ),
                    mb_substr(
                        $error->getMessage(),
                        0,
                        1000,
                        'UTF-8'
                    ),
                    $productId,
                    (string)$retryRow['owner_fingerprint'],
                    (string)$retryRow['annex_evidence_hash'],
                ]);
                $processed++;
                $errors[$productId] = mb_substr(
                    $error->getMessage(),
                    0,
                    300,
                    'UTF-8'
                );
            }
        }
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
    return [
        'available' => true,
        'processed' => $processed,
        'accepted' => $accepted,
        'terminal' => $terminal,
        'errors' => $errors,
    ];
}

function ingredientOntologyV3IdentityAnnexSemanticHash(
    PDO $db,
    int $versionId
): string {
    $version = ingredientOntologyV3Version($db, $versionId);
    if ($version === null || (string)$version['status'] !== 'ready') {
        return '';
    }
    $products = $db->query("
        SELECT id, name, brand, category, prepared_food
        FROM products
        ORDER BY id
    ");
    $rows = [];
    while ($product = $products->fetch(PDO::FETCH_ASSOC)) {
        $resolution = ingredientOntologyV3IdentityAnnexResolution(
            $db,
            $version,
            $product
        );
        $attributes = (array)($resolution['attributes'] ?? []);
        ksort($attributes, SORT_STRING);
        $rows[] = [
            'product_id' => (int)$product['id'],
            'owner_fingerprint' =>
                ingredientOntologyV3ProductOwnerFingerprint(
                    $product
                ),
            'status' => (string)$resolution['status'],
            'entity_slug' =>
                (string)($resolution['entity_slug'] ?? ''),
            'attributes' => $attributes,
        ];
    }
    return ingredientOntologyV3Hash([
        'algorithm' => 'product-identity-semantic-v1',
        'resolver_version' =>
            ingredientOntologyV3ProductIdentityResolverVersion(),
        'review_manifest_hash' =>
            ingredientOntologyV3IdentityAnnexReviewManifestHash(),
        'ontology_content_hash' => (string)$version['content_hash'],
        'ontology_seal_hash' => (string)$version['seal_hash'],
        'products' => $rows,
    ]);
}

function ingredientOntologyV3IdentityAnnexMapping(
    PDO $db,
    int $versionId,
    int $productId,
    string $ownerFingerprint,
    ?int $identityExtensionRevision = null
): ?array {
    if (!ingredientOntologyV3IdentityAnnexTableExists($db)) {
        return null;
    }
    $identityExtensionRevision ??=
        ingredientOntologyV3IdentityExtensionSnapshot(
            $db,
            $versionId
        )['revision'];
    $eligibleRoles = ingredientOntologyV3IdentityEligibleRolesSql();
    $stmt = $db->prepare("
        SELECT annex.id AS annex_id, annex.owner_fingerprint,
               annex.source_label, annex.attributes_json,
               annex.label_id, annex.entity_id,
               annex.extension_entity_id,
               annex.admission_source, annex.evidence_hash,
               CASE
                   WHEN extension.id IS NOT NULL THEN -extension.id
                   ELSE entity.id
               END AS effective_entity_id,
               COALESCE(extension.slug, entity.slug) AS entity_slug,
               COALESCE(
                   extension.canonical_name,
                   entity.canonical_name
               ) AS entity_name,
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
        LEFT JOIN ingredient_ontology_entities entity
          ON entity.id = annex.entity_id
         AND entity.ontology_version_id = annex.ontology_version_id
         AND entity.active = 1
         AND entity.entity_kind = 'ingredient'
         AND entity.identity_role IN {$eligibleRoles}
        LEFT JOIN ingredient_ontology_labels label
          ON label.id = annex.label_id
         AND label.ontology_version_id = annex.ontology_version_id
         AND label.entity_id = annex.entity_id
         AND label.review_state = 'accepted'
         AND label.kind IN ('exact_alias', 'attribute_alias')
        LEFT JOIN ingredient_ontology_identity_extension_entities
            extension
          ON extension.id = annex.extension_entity_id
         AND extension.ontology_version_id = annex.ontology_version_id
         AND extension.ontology_content_hash =
             annex.ontology_content_hash
         AND extension.ontology_seal_hash = annex.ontology_seal_hash
         AND extension.created_revision <= ?
         AND extension.status = 'active'
        WHERE annex.product_id = ?
          AND annex.ontology_version_id = ?
          AND annex.owner_fingerprint = ?
          AND annex.status = 'accepted'
          AND annex.resolver_version = ?
          AND annex.review_manifest_hash = ?
          AND (
              (
                  annex.entity_id IS NOT NULL
                  AND entity.id IS NOT NULL
                  AND label.id IS NOT NULL
              )
              OR (
                  annex.extension_entity_id IS NOT NULL
                  AND extension.id IS NOT NULL
              )
          )
        LIMIT 1
    ");
    $stmt->execute([
        max(0, $identityExtensionRevision),
        $productId,
        $versionId,
        $ownerFingerprint,
        ingredientOntologyV3ProductIdentityResolverVersion(),
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
        'entity_id' => (int)$row['effective_entity_id'],
        'ontology_entity_id' => $row['entity_id'] !== null
            ? (int)$row['entity_id']
            : null,
        'extension_entity_id' =>
            $row['extension_entity_id'] !== null
                ? (int)$row['extension_entity_id']
                : null,
        'entity_slug' => (string)$row['entity_slug'],
        'entity_name' => (string)$row['entity_name'],
        'status' => 'accepted',
        'confidence' => 1.0,
        'mapping_source' => 'deterministic_identity_annex',
        'source_label' => (string)$row['source_label'],
        'attributes' => $normalizedAttributes,
        'is_staple' => false,
        'label_id' => $row['label_id'] !== null
            ? (int)$row['label_id']
            : null,
        'evidence_hash' => (string)$row['evidence_hash'],
        'admission_source' => (string)$row['admission_source'],
    ];
}

function ingredientOntologyV3IdentityAnnexResolvedMapping(
        PDO $db,
        array $version,
        array $product,
        array $resolution,
        ?int $identityExtensionRevision = null
    ): ?array {
        $nativeEntityId = (int)($resolution['entity_id'] ?? 0);
        $extensionEntityId = (int)(
            $resolution['extension_entity_id'] ?? 0
        );
        $effectiveEntityId = (int)(
            $resolution['effective_entity_id']
                ?? $nativeEntityId
        );
        $identityExtensionRevision ??=
            ingredientOntologyV3IdentityExtensionSnapshot(
                $db,
                (int)($version['id'] ?? 0)
            )['revision'];
        if (
            (string)($version['status'] ?? '') !== 'ready'
            || (string)($resolution['status'] ?? '') !== 'accepted'
            || $effectiveEntityId === 0
            || (
                $nativeEntityId > 0
                && (int)($resolution['label_id'] ?? 0) <= 0
            )
            || (
                $nativeEntityId <= 0
                && (
                    $extensionEntityId <= 0
                    || (int)($resolution['extension_revision'] ?? 0)
                        > $identityExtensionRevision
                )
            )
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
                ingredientOntologyV3ProductIdentityResolverVersion(),
            'review_manifest_hash' =>
                ingredientOntologyV3IdentityAnnexReviewManifestHash(),
            'ontology_version_id' => (int)$version['id'],
            'ontology_content_hash' => (string)$version['content_hash'],
            'ontology_seal_hash' => (string)$version['seal_hash'],
            'product_id' => (int)$product['id'],
            'owner_fingerprint' => $ownerFingerprint,
            'label_id' => $resolution['label_id'] ?? null,
            'entity_id' => $nativeEntityId > 0
                ? $nativeEntityId
                : null,
            'extension_entity_id' => $extensionEntityId > 0
                ? $extensionEntityId
                : null,
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
            'entity_id' => $effectiveEntityId,
            'ontology_entity_id' => $nativeEntityId > 0
                ? $nativeEntityId
                : null,
            'extension_entity_id' => $extensionEntityId > 0
                ? $extensionEntityId
                : null,
            'entity_slug' => (string)$resolution['entity_slug'],
            'entity_name' => (string)$resolution['entity_name'],
            'status' => 'accepted',
            'confidence' => 1.0,
            'mapping_source' => 'deterministic_identity_annex_read',
            'source_label' => (string)$product['name'],
            'attributes' => $normalizedAttributes,
            'is_staple' => false,
            'label_id' => $resolution['label_id'] !== null
                ? (int)$resolution['label_id']
                : null,
            'evidence_hash' => $evidenceHash,
            'admission_source' =>
                (string)$resolution['admission_source'],
        ];
}
