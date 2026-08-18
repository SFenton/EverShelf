<?php

const RECIPE_DETAIL_SCHEMA_VERSION = 'recipe_detail_v1';
const RECIPE_DETAIL_MAX_INGREDIENTS = 200;
const RECIPE_DETAIL_MAX_INVENTORY_ROWS = 500;
const RECIPE_DETAIL_MAX_INSTRUCTIONS = 100;
const RECIPE_DETAIL_MAX_INSTRUCTION_LENGTH = 2000;
const RECIPE_DETAIL_MAX_INSTRUCTIONS_JSON_BYTES = 210000;
const RECIPE_DETAIL_MAX_INSTRUCTION_GROUPS = 50;
const RECIPE_DETAIL_MAX_INSTRUCTION_GROUPS_JSON_BYTES = 16384;
const RECIPE_DETAIL_MAX_EQUIPMENT_JSON_BYTES = 8192;
const RECIPE_DETAIL_MAX_DEVICE_JSON_BYTES = 8192;
const RECIPE_DETAIL_MAX_USER_NOTE_LENGTH = 2000;
const RECIPE_GROCERY_MAX_SELECTIONS = 100;
const RECIPE_GROCERY_REQUEST_RETENTION_DAYS = 30;
const RECIPE_GROCERY_PRUNE_BATCH_SIZE = 100;

class RecipeGroceryConflictException extends RuntimeException {
}

function recipeDetailSourceAttribution(string $connector, ?string $stored): string {
    $stored = trim((string)$stored);
    if ($stored !== '') {
        return $stored;
    }
    return match ($connector) {
        'cookidoo' => 'Cookidoo',
        'manual' => 'User',
        default => 'EverShelf',
    };
}

function recipeDetailDecodeFactList(
    mixed $value,
    bool $scalarOnly = false,
    bool $deduplicate = true
): array {
    $decoded = json_decode((string)$value, true);
    if (!is_array($decoded) || !recipeArrayIsList($decoded)) {
        return [];
    }
    $names = [];
    $seen = [];
    foreach (array_slice($decoded, 0, 50) as $item) {
        if ($scalarOnly && !is_string($item)) {
            continue;
        }
        $name = mb_substr(trim((string)$item), 0, 120, 'UTF-8');
        if ($name === '') {
            continue;
        }
        $key = mb_strtolower($name, 'UTF-8');
        if ($deduplicate && isset($seen[$key])) {
            continue;
        }
        if ($deduplicate) {
            $seen[$key] = true;
        }
        $names[] = $name;
    }
    return $names;
}

function recipeDetailLoadBase(PDO $db, int $recipeId): ?array {
    $equipmentBytes = RECIPE_DETAIL_MAX_EQUIPMENT_JSON_BYTES;
    $deviceBytes = RECIPE_DETAIL_MAX_DEVICE_JSON_BYTES;
    $instructionBytes = RECIPE_DETAIL_MAX_INSTRUCTIONS_JSON_BYTES;
    $instructionGroupBytes =
        RECIPE_DETAIL_MAX_INSTRUCTION_GROUPS_JSON_BYTES;
    $noteLength = RECIPE_DETAIL_MAX_USER_NOTE_LENGTH;
    $languageVisibility =
        recipeCookidooLanguageVisibilitySql('c');
    $stmt = $db->prepare("
        SELECT c.id, c.primary_connector, substr(c.title, 1, 400) AS title,
               substr(c.image_url, 1, 2048) AS image_url,
               substr(c.language, 1, 16) AS language,
               c.servings, substr(c.category, 1, 160) AS category,
               substr(c.prep_time, 1, 80) AS prep_time,
               length(c.prep_time) AS prep_time_length,
               substr(c.cook_time, 1, 80) AS cook_time,
               length(c.cook_time) AS cook_time_length,
               c.yield_quantity, substr(c.yield_unit, 1, 80) AS yield_unit,
               c.prep_time_seconds, c.cook_time_seconds,
               c.active_time_seconds, c.inactive_time_seconds,
               c.total_time_seconds,
               substr(c.difficulty, 1, 80) AS difficulty,
               substr(c.primary_category, 1, 160) AS primary_category,
               substr(c.devices_json, 1, {$deviceBytes}) AS devices_json,
               substr(c.optional_devices_json, 1, {$deviceBytes})
                   AS optional_devices_json,
               substr(c.equipment_json, 1, {$equipmentBytes}) AS equipment_json,
               substr(c.instructions_json, 1, {$instructionBytes}) AS instructions_json,
               length(c.instructions_json) AS instructions_json_length,
               substr(
                   c.instruction_groups_json,
                   1,
                   {$instructionGroupBytes}
               ) AS instruction_groups_json,
               length(c.instruction_groups_json)
                   AS instruction_groups_json_length,
               c.storage_policy, substr(c.rights_basis, 1, 160) AS rights_basis,
               c.retrieved_at, c.stale_at,
               CASE
                   WHEN c.stale_at IS NOT NULL
                    AND c.stale_at < CURRENT_TIMESTAMP
                   THEN 1 ELSE 0
               END AS is_stale,
               c.created_at, c.updated_at,
               COALESCE(s.favorite, 0) AS favorite,
               COALESCE(s.hidden, 0) AS hidden,
               s.rating, substr(COALESCE(s.note, ''), 1, {$noteLength}) AS note,
               COALESCE(s.cooked_count, 0) AS cooked_count, s.last_cooked,
               substr(o.external_id, 1, 160) AS external_id,
               o.id AS origin_id,
               substr(o.canonical_url, 1, 2048) AS canonical_url,
               substr(o.locale, 1, 16) AS locale,
               substr(o.content_language, 1, 20) AS content_language,
               substr(o.attribution, 1, 160) AS attribution,
               substr(o.metadata_version, 1, 40) AS metadata_version,
               substr(o.metadata_schema_version, 1, 40)
                   AS metadata_schema_version,
               o.first_seen_at, o.last_seen_at, o.availability,
               ss.inventory_revision, ss.catalog_revision,
               ss.cursor_revision, ss.active_score_revision_id,
               active_revision.ontology_version_id
        FROM recipe_catalog c
        LEFT JOIN recipe_user_state s ON s.recipe_id = c.id
        LEFT JOIN recipe_origins o ON o.id = COALESCE(
            (
                SELECT ro.id
                FROM recipe_origins ro
                WHERE ro.recipe_id = c.id
                  AND ro.connector = c.primary_connector
                ORDER BY ro.id ASC
                LIMIT 1
            ),
            (
                SELECT ro.id
                FROM recipe_origins ro
                WHERE ro.recipe_id = c.id
                ORDER BY ro.id ASC
                LIMIT 1
            )
        )
        LEFT JOIN recipe_score_state ss ON ss.id = 1
        LEFT JOIN recipe_score_revisions active_revision
          ON active_revision.id = ss.active_score_revision_id
        WHERE c.id = ? AND c.deleted_at IS NULL
        {$languageVisibility}
        LIMIT 1
    ");
    $stmt->execute([$recipeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function recipeDetailLoadIngredients(
    PDO $db,
    int $recipeId,
    ?string $parseLocale = null
): array {
    $limit = RECIPE_DETAIL_MAX_INGREDIENTS + 1;
    $parseLocale = recipeQuantityNormalizeLocale(
        (string)($parseLocale ?? '')
    );
    if ($parseLocale === 'und') {
        $localeStmt = $db->prepare("
            SELECT COALESCE(
                (
                    SELECT origin.locale
                    FROM recipe_origins origin
                    WHERE origin.recipe_id = catalog.id
                      AND origin.connector = catalog.primary_connector
                    ORDER BY origin.id ASC
                    LIMIT 1
                ),
                catalog.language,
                'und'
            )
            FROM recipe_catalog catalog
            WHERE catalog.id = ?
            LIMIT 1
        ");
        $localeStmt->execute([$recipeId]);
        $parseLocale = recipeQuantityNormalizeLocale(
            (string)($localeStmt->fetchColumn() ?: 'und')
        );
    }
    $stmt = $db->prepare("
        WITH ingredient_rows AS (
            SELECT rsi.id AS ingredient_id, 'source' AS ingredient_source,
                   ri_match.id AS ranking_ingredient_id, rsi.position,
                   COALESCE(rsi.source_group_index, 0)
                       AS source_group_index,
                   COALESCE(rsi.source_group_position, rsi.position)
                       AS source_group_position,
                   substr(rsi.source_group_title, 1, 160)
                       AS source_group_title,
                   substr(rsi.source_ingredient_ref, 1, 200)
                       AS source_ingredient_ref,
                   substr(rsi.source_default_title, 1, 200)
                       AS source_default_title,
                   substr(rsi.source_unit_ref, 1, 200)
                       AS source_unit_ref,
                   rsi.source_optional,
                   substr(rsi.source_shopping_category_ref, 1, 200)
                       AS source_shopping_category_ref,
                   rsi.name AS raw_source_text,
                   substr(rsi.name, 1, 200) AS source_text,
                   substr(rsi.normalized_name, 1, 200) AS normalized_name,
                   rsi.source_quantity, rsi.source_quantity_max,
                   substr(rsi.source_unit, 1, 80) AS source_unit,
                   substr(rsi.source_amount_text, 1, 160) AS source_amount_text,
                   NULL AS ranking_quantity, NULL AS ranking_quantity_text,
                   NULL AS ranking_unit,
                   NULL AS quantity_parse_json,
                   NULL AS quantity_parse_version,
                   CASE WHEN rsi.source_optional = 1 THEN 0 ELSE 1 END
                       AS is_required,
                   CASE WHEN rsi.source_optional = 1 THEN 1 ELSE 0 END
                       AS is_optional,
                   0 AS is_staple,
                   CASE
                       WHEN (
                           COALESCE(ri_match.mapping_confidence, 0)
                               > COALESCE(rsi.mapping_confidence, 0)
                           OR (
                               rsi.canonical_ingredient_id IS NULL
                               AND rsi.taxonomy_node_id IS NULL
                               AND (
                                   ri_match.canonical_ingredient_id IS NOT NULL
                                   OR ri_match.taxonomy_node_id IS NOT NULL
                               )
                           )
                       )
                       THEN ri_match.canonical_ingredient_id
                       ELSE rsi.canonical_ingredient_id
                   END AS canonical_ingredient_id,
                   CASE
                       WHEN (
                           COALESCE(ri_match.mapping_confidence, 0)
                               > COALESCE(rsi.mapping_confidence, 0)
                           OR (
                               rsi.canonical_ingredient_id IS NULL
                               AND rsi.taxonomy_node_id IS NULL
                               AND (
                                   ri_match.canonical_ingredient_id IS NOT NULL
                                   OR ri_match.taxonomy_node_id IS NOT NULL
                               )
                           )
                       )
                       THEN ri_match.taxonomy_node_id
                       ELSE rsi.taxonomy_node_id
                   END AS taxonomy_node_id,
                   CASE
                       WHEN (
                           COALESCE(ri_match.mapping_confidence, 0)
                               > COALESCE(rsi.mapping_confidence, 0)
                           OR (
                               rsi.canonical_ingredient_id IS NULL
                               AND rsi.taxonomy_node_id IS NULL
                               AND (
                                   ri_match.canonical_ingredient_id IS NOT NULL
                                   OR ri_match.taxonomy_node_id IS NOT NULL
                               )
                           )
                       )
                       THEN COALESCE(ri_match.mapping_confidence, 0)
                       ELSE COALESCE(rsi.mapping_confidence, 0)
                   END AS mapping_confidence,
                   CASE
                       WHEN (
                           COALESCE(ri_match.mapping_confidence, 0)
                               > COALESCE(rsi.mapping_confidence, 0)
                           OR (
                               rsi.canonical_ingredient_id IS NULL
                               AND rsi.taxonomy_node_id IS NULL
                               AND (
                                   ri_match.canonical_ingredient_id IS NOT NULL
                                   OR ri_match.taxonomy_node_id IS NOT NULL
                               )
                           )
                       )
                       THEN substr(ri_match.mapping_source, 1, 80)
                       ELSE substr(rsi.mapping_source, 1, 80)
                   END AS mapping_source,
                   substr(
                       COALESCE(rsi.mapping_version, 'legacy-v1'),
                       1,
                       40
                   ) AS mapping_version
            FROM recipe_source_ingredients rsi
            LEFT JOIN recipe_ingredients ri_match
              ON ri_match.id = (
                  SELECT candidate.id
                  FROM recipe_ingredients candidate
                  WHERE candidate.recipe_id = rsi.recipe_id
                    AND candidate.normalized_name = rsi.normalized_name
                  ORDER BY
                      candidate.mapping_confidence DESC,
                      CASE
                          WHEN lower(candidate.mapping_source) IN (
                              'taxonomy_alias',
                              'taxonomy_slug',
                              'canonical_slug'
                          )
                          THEN 0 ELSE 1
                      END ASC,
                      candidate.id ASC,
                      candidate.position ASC
                  LIMIT 1
              )
            WHERE rsi.recipe_id = ?

            UNION ALL

            SELECT ri.id AS ingredient_id, 'ranking' AS ingredient_source,
                   ri.id AS ranking_ingredient_id,
                   ri.position, 0 AS source_group_index,
                   ri.position AS source_group_position,
                   NULL AS source_group_title,
                   NULL AS source_ingredient_ref,
                   NULL AS source_default_title,
                   NULL AS source_unit_ref,
                   NULL AS source_optional,
                   NULL AS source_shopping_category_ref,
                   COALESCE(
                       NULLIF(ri.raw_text, ''),
                       NULLIF(ri.normalized_name, ''),
                       'Ingredient'
                   ) AS raw_source_text,
                   substr(COALESCE(
                       NULLIF(ri.raw_text, ''),
                       NULLIF(ri.normalized_name, ''),
                       'Ingredient'
                   ), 1, 200) AS source_text,
                   substr(ri.normalized_name, 1, 200) AS normalized_name,
                   NULL AS source_quantity, NULL AS source_quantity_max,
                   NULL AS source_unit, NULL AS source_amount_text,
                   ri.quantity AS ranking_quantity,
                   substr(ri.quantity_text, 1, 160) AS ranking_quantity_text,
                   substr(ri.unit, 1, 80) AS ranking_unit,
                   ri.quantity_parse_json,
                   ri.quantity_parse_version,
                   ri.is_required, ri.is_optional, ri.is_staple,
                   ri.canonical_ingredient_id,
                   ri.taxonomy_node_id,
                   ri.mapping_confidence,
                   substr(ri.mapping_source, 1, 80) AS mapping_source,
                   'legacy-v1' AS mapping_version
            FROM recipe_ingredients ri
            WHERE ri.recipe_id = ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM recipe_source_ingredients present
                  WHERE present.recipe_id = ri.recipe_id
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM recipe_catalog source_catalog
                  JOIN recipe_origins source_origin
                    ON source_origin.recipe_id = source_catalog.id
                   AND source_origin.connector =
                       source_catalog.primary_connector
                  WHERE source_catalog.id = ri.recipe_id
                    AND source_origin.metadata_version = ?
                    AND source_origin.metadata_schema_version = ?
              )
        )
        SELECT ingredient_rows.*,
               substr(ci.name, 1, 200) AS canonical_name,
               substr(tn.name, 1, 200) AS taxonomy_name
        FROM ingredient_rows
        LEFT JOIN canonical_ingredients ci
          ON ci.id = ingredient_rows.canonical_ingredient_id
        LEFT JOIN taxonomy_nodes tn
          ON tn.id = ingredient_rows.taxonomy_node_id
        ORDER BY ingredient_rows.position ASC
        LIMIT {$limit}
    ");
    $stmt->execute([
        $recipeId,
        $recipeId,
        RECIPE_COOKIDOO_METADATA_VERSION,
        RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $truncated = count($rows) > RECIPE_DETAIL_MAX_INGREDIENTS;
    if ($truncated) {
        $rows = array_slice($rows, 0, RECIPE_DETAIL_MAX_INGREDIENTS);
    }
    foreach ($rows as &$row) {
        $rawSourceText = is_string($row['raw_source_text'] ?? null)
            ? (string)$row['raw_source_text']
            : '';
        $row['quantity_parse'] = recipeQuantityDecodePersistedResult(
            $row['quantity_parse_json'] ?? null,
            $rawSourceText,
            $parseLocale,
            $row['quantity_parse_version'] ?? null
        );
        $row['source_text'] = recipeIngredientBoundedSourceText(
            $rawSourceText,
            $row['normalized_name'] ?? ''
        );
        unset(
            $row['raw_source_text'],
            $row['quantity_parse_json'],
            $row['quantity_parse_version']
        );
        $stripLeadingAmount = (string)$row['ingredient_source'] === 'ranking'
            && (
                $row['ranking_quantity'] !== null
                || trim((string)($row['ranking_quantity_text'] ?? '')) !== ''
                || trim((string)($row['ranking_unit'] ?? '')) !== ''
                || (
                    is_array($row['quantity_parse'])
                    && in_array(
                        $row['quantity_parse']['status'] ?? null,
                        ['parsed', 'ambiguous'],
                        true
                    )
                )
            );
        $row['display_name'] = recipeIngredientDisplayName(
            $row['source_text'],
            (string)($row['normalized_name'] ?? ''),
            $stripLeadingAmount,
            [
                'quantity' => $row['ranking_quantity'],
                'text' => $row['ranking_quantity_text'],
                'unit' => $row['ranking_unit'],
                'parse' => $row['quantity_parse'],
            ]
        );
        $row['name'] = $row['display_name'];
        $row['ingredient_id'] = (int)$row['ingredient_id'];
        $row['ranking_ingredient_id'] =
            $row['ranking_ingredient_id'] !== null
                ? (int)$row['ranking_ingredient_id']
                : null;
        $row['position'] = (int)$row['position'];
        $row['source_group_index'] = (int)$row['source_group_index'];
        $row['source_group_position'] =
            (int)$row['source_group_position'];
        $row['source_optional'] = $row['source_optional'] !== null
            ? (bool)$row['source_optional']
            : null;
        $row['source_quantity'] = $row['source_quantity'] !== null
            ? (float)$row['source_quantity']
            : null;
        $row['source_quantity_max'] = $row['source_quantity_max'] !== null
            ? (float)$row['source_quantity_max']
            : null;
        $row['ranking_quantity'] = $row['ranking_quantity'] !== null
            ? (float)$row['ranking_quantity']
            : null;
        $row['is_required'] = !empty($row['is_required']);
        $row['is_optional'] = !empty($row['is_optional']);
        $row['is_staple'] = !empty($row['is_staple'])
            || recipeIngredientIsStaple((string)$row['name']);
        $row['canonical_ingredient_id'] = $row['canonical_ingredient_id'] !== null
            ? (int)$row['canonical_ingredient_id']
            : null;
        $row['taxonomy_node_id'] = $row['taxonomy_node_id'] !== null
            ? (int)$row['taxonomy_node_id']
            : null;
        $row['mapping_confidence'] = (float)$row['mapping_confidence'];
        $row['mapping_source'] = trim((string)($row['mapping_source'] ?? ''))
            ?: 'unresolved';
        $row['mapping_version'] = trim((string)(
            $row['mapping_version'] ?? ''
        )) ?: recipeIngredientActiveMappingVersion();
    }
    unset($row);
    return ['rows' => $rows, 'truncated' => $truncated];
}

function recipeDetailIngredientKey(int $recipeId, array $ingredient): string {
    $identity = implode('|', [
        (string)$recipeId,
        (string)$ingredient['ingredient_source'],
        (string)$ingredient['position'],
        (string)$ingredient['normalized_name'],
    ]);
    return 'ri:' . (int)$ingredient['position'] . ':' . substr(hash('sha256', $identity), 0, 16);
}

function recipeDetailCanonicalShoppingKey(array $ingredient): string {
    if (recipeIngredientMappingSourceIsIdentitySafe(
        (string)($ingredient['mapping_source'] ?? '')
    )) {
        if (!empty($ingredient['canonical_ingredient_id'])) {
            return 'canonical:' . (int)$ingredient['canonical_ingredient_id'];
        }
        if (!empty($ingredient['taxonomy_node_id'])) {
            return 'taxonomy:' . (int)$ingredient['taxonomy_node_id'];
        }
    }
    $normalizedSourceName = '';
    foreach ([
        'source_text',
        'normalized_name',
        'display_name',
        'name',
    ] as $field) {
        $sourceName = trim((string)($ingredient[$field] ?? ''));
        if ($sourceName === '') {
            continue;
        }
        $normalizedSourceName = recipeIngredientNormalizeName(
            recipeIngredientIdentityCandidate($sourceName, [
                'parse' => $ingredient['quantity_parse'] ?? null,
            ])
        );
        if ($normalizedSourceName !== '') {
            break;
        }
    }
    return 'name:' . $normalizedSourceName;
}

function recipeDetailShoppingName(array $ingredient): string {
    foreach (['display_name', 'source_text', 'name', 'normalized_name'] as $field) {
        $name = trim((string)($ingredient[$field] ?? ''));
        if ($name !== '') {
            return mb_substr($name, 0, 200, 'UTF-8');
        }
    }
    return '';
}

function recipeDetailGroceryBackendSupported(): bool {
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && array_key_exists('RECIPE_GROCERY_BACKEND_SUPPORTED', $GLOBALS)
    ) {
        return (bool)$GLOBALS['RECIPE_GROCERY_BACKEND_SUPPORTED'];
    }
    return function_exists('recipeGroceryAddMissing')
        && (!function_exists('env') || env('DEMO_MODE', 'false') !== 'true');
}

function recipeDetailV3MatchMap(
    PDO $db,
    array $ingredients,
    ?array $read = null
): ?array {
    if (!function_exists('ingredientOntologyV3ShadowRevision')) {
        return null;
    }
    $read ??= recipeScoreReadRevision($db);
    $active = $read['revision'] ?? null;
    if (
        $active === null
        || ($active['ontology_version_id'] ?? null) === null
        || ($active['scoring_model'] ?? '') !== 'faceted-ontology-v3'
    ) {
        return null;
    }
    $ingredientIds = array_values(array_unique(array_filter(
        array_map(
            static fn(array $ingredient): int =>
                (int)($ingredient['ranking_ingredient_id'] ?? 0),
            $ingredients
        ),
        static fn(int $id): bool => $id > 0
    )));
    if (!$ingredientIds) {
        return ['revision' => $active, 'matches' => []];
    }
    $overlay = $read['overlay_revision'] ?? null;
    $effective = $active;
    if ($overlay !== null) {
        $overlayPlaceholders = implode(
            ',',
            array_fill(0, count($ingredientIds), '?')
        );
        $affected = $db->prepare("
            SELECT 1
            FROM recipe_score_incremental_recipes overlay_recipe
            JOIN recipe_ingredients ingredient
              ON ingredient.recipe_id = overlay_recipe.recipe_id
            WHERE overlay_recipe.score_revision_id = ?
              AND ingredient.id IN ({$overlayPlaceholders})
            LIMIT 1
        ");
        $affected->execute(array_merge(
            [(int)$overlay['id']],
            $ingredientIds
        ));
        if ($affected->fetchColumn()) {
            $effective = $overlay;
        }
    }
    $placeholders = implode(',', array_fill(0, count($ingredientIds), '?'));
    $stmt = $db->prepare("
        SELECT sm.recipe_ingredient_id, sm.outcome,
               sm.satisfies_required, sm.confidence, sm.relationship,
               sm.inventory_product_id, sm.explanation_json,
               substr(p.name, 1, 200) AS product_name
        FROM ingredient_ontology_shadow_matches sm
        LEFT JOIN products p ON p.id = sm.inventory_product_id
        WHERE sm.score_revision_id = ?
          AND sm.recipe_ingredient_id IN ({$placeholders})
    ");
    $stmt->execute(array_merge(
        [(int)$effective['id']],
        $ingredientIds
    ));
    $matches = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $explanation = json_decode(
            (string)$row['explanation_json'],
            true
        );
        $row['recipe_ingredient_id'] = (int)$row['recipe_ingredient_id'];
        $row['satisfies_required'] = !empty($row['satisfies_required']);
        $row['confidence'] = (float)$row['confidence'];
        $row['inventory_product_id'] =
            $row['inventory_product_id'] !== null
                ? (int)$row['inventory_product_id']
                : null;
        $row['explanation'] = is_array($explanation) ? $explanation : [];
        unset($row['explanation_json']);
        $matches[$row['recipe_ingredient_id']] = $row;
    }
    return ['revision' => $effective, 'matches' => $matches];
}

function recipeDetailBuildIngredientPresence(
    PDO $db,
    array $ingredients,
    ?array $read = null
): array {
    $v3 = recipeDetailV3MatchMap($db, $ingredients, $read);
    $inventoryResult = $v3 === null
        ? recipeInventoryCandidateResult($db, [
            'exclude_expired' => true,
            'limit' => RECIPE_DETAIL_MAX_INVENTORY_ROWS,
            'mapping_limit' => RECIPE_DETAIL_MAX_INVENTORY_ROWS * 8,
            'text_limit' => 200,
        ])
        : [
            'candidates' => [],
            'source_truncated' => false,
            'mappings_truncated' => false,
        ];
    $inventory = $inventoryResult['candidates'];
    $inventoryTruncated = !empty($inventoryResult['source_truncated'])
        || !empty($inventoryResult['mappings_truncated']);
    $relations = $v3 === null
        ? recipeTaxonomyRelationMap($db, $ingredients, $inventory)
        : [];
    $out = [];
    foreach ($ingredients as $ingredient) {
        $v3Match = $v3 !== null
            ? ($v3['matches'][
                (int)($ingredient['ranking_ingredient_id'] ?? 0)
            ] ?? null)
            : null;
        $isStaple = $v3 !== null
            ? ($v3Match !== null && $v3Match['outcome'] === 'staple')
            : !empty($ingredient['is_staple']);
        $storedRequirement = is_array(
            $v3Match['explanation']['requirement'] ?? null
        ) ? $v3Match['explanation']['requirement'] : null;
        $isRequired = $v3 !== null && $storedRequirement !== null
            ? !empty($storedRequirement['required'])
            : (
                !empty($ingredient['is_required'])
                && empty($ingredient['is_optional'])
                && !$isStaple
            );
        $rankingQuantityKnown = $ingredient['ranking_quantity'] !== null
            && (float)$ingredient['ranking_quantity'] > 0
            && recipeCatalogUnitDefinition((string)($ingredient['ranking_unit'] ?? '')) !== null;
        $hasDisplayAmount = $ingredient['source_quantity'] !== null
            || $ingredient['source_quantity_max'] !== null
            || trim((string)($ingredient['source_unit'] ?? '')) !== ''
            || trim((string)($ingredient['source_amount_text'] ?? '')) !== '';
        $quantityState = $rankingQuantityKnown
            ? 'known'
            : ($hasDisplayAmount ? 'display_only' : 'unknown');
        $quantitySufficiency = 'unknown';
        $quantityResult = null;
        $match = null;
        $identityCanSatisfy = false;
        $identityExact = false;
        $quantityMissingOnly = false;
        $state = 'uncertain';
        if ($v3 !== null) {
            if ($v3Match !== null) {
                $storedQuantity =
                    $v3Match['explanation']['quantity'] ?? null;
                if ($rankingQuantityKnown && is_array($storedQuantity)) {
                    $quantityResult = $storedQuantity;
                    if (!empty($storedQuantity['known'])) {
                        $quantitySufficiency =
                            !empty($storedQuantity['sufficient'])
                                ? 'sufficient'
                                : 'insufficient';
                    }
                }
                if ($v3Match['inventory_product_id'] !== null) {
                    $match = [
                        'relation' => (string)$v3Match['relationship'],
                        'score' => (float)$v3Match['confidence'],
                        'product_id' =>
                            (int)$v3Match['inventory_product_id'],
                        'product_name' =>
                            (string)($v3Match['product_name'] ?? ''),
                    ];
                }
                $identityCanSatisfy =
                    !empty($v3Match['satisfies_required'])
                    || !empty(
                        $v3Match['explanation']['satisfies_required']
                    );
                $identityExact =
                    (string)$v3Match['relationship'] === 'same_entity';
                $quantityMissingOnly =
                    (string)$v3Match['outcome']
                        === 'insufficient_quantity'
                    && $identityCanSatisfy
                    && $identityExact
                    && !empty(
                        $v3Match['explanation']['quantity']['enforced']
                    );
                if ($isStaple) {
                    $state = 'staple';
                } elseif (!empty($v3Match['satisfies_required'])) {
                    $state = 'in_stock';
                } elseif (
                    $v3Match['outcome'] === 'not_in_inventory'
                    || $v3Match['outcome'] === 'insufficient_quantity'
                    || str_starts_with(
                        (string)$v3Match['outcome'],
                        'different_'
                    )
                ) {
                    $state = 'missing';
                }
            }
        } else {
            $match = $isStaple
                ? null
                : recipeIngredientBestInventoryMatch(
                    $db,
                    $ingredient,
                    $inventory,
                    $relations
                );
            if ($match === null && $isRequired) {
                $match = recipeIngredientBestInventoryMatch(
                    $db,
                    $ingredient,
                    $inventory,
                    $relations,
                    true
                );
            }
            if ($rankingQuantityKnown) {
                if ($match === null) {
                    $quantitySufficiency = $inventoryTruncated
                        ? 'unknown'
                        : 'insufficient';
                } else {
                    $quantityResult = recipeCatalogQuantitySufficiency([
                        'quantity' => $ingredient['ranking_quantity'],
                        'unit' => $ingredient['ranking_unit'],
                    ], $match);
                    if (!empty($quantityResult['known'])) {
                        if (!empty($quantityResult['sufficient'])) {
                            $quantitySufficiency = 'sufficient';
                        } elseif (!$inventoryTruncated) {
                            $quantitySufficiency = 'insufficient';
                        }
                    }
                }
            }
            if ($isStaple) {
                $state = 'staple';
            } elseif ($match === null) {
                $state = $inventoryTruncated
                    || (float)$ingredient['mapping_confidence'] <= 0
                    ? 'uncertain'
                    : 'missing';
            } else {
                $relationCanSatisfy = $isRequired
                    ? recipeIngredientMatchCanSatisfyRequired($match)
                    : in_array(
                        (string)($match['relation'] ?? ''),
                        ['exact', 'pantry_descendant', 'normalized_name'],
                        true
                    );
                $identityCanSatisfy = $relationCanSatisfy;
                $identityExact =
                    (string)($match['relation'] ?? '') === 'exact';
                if (
                    !$relationCanSatisfy
                    || (float)$match['score'] < 0.20
                ) {
                    $state = 'uncertain';
                } elseif ($quantitySufficiency === 'insufficient') {
                    $state = 'missing';
                    $quantityMissingOnly =
                        $identityCanSatisfy
                        && $identityExact
                        && $rankingQuantityKnown;
                } else {
                    $state = 'in_stock';
                }
            }
        }
        $publicMatch = null;
        if (
            $match !== null
            && (
                ($state === 'in_stock' && $identityCanSatisfy)
                || ($state === 'missing' && $quantityMissingOnly)
            )
        ) {
            $publicMatch = $match;
        }

        $amountQuantity = $ingredient['ingredient_source'] === 'source'
            ? $ingredient['source_quantity']
            : $ingredient['ranking_quantity'];
        $amountUnit = $ingredient['ingredient_source'] === 'source'
            ? $ingredient['source_unit']
            : $ingredient['ranking_unit'];
        $amountText = $ingredient['ingredient_source'] === 'source'
            ? $ingredient['source_amount_text']
            : $ingredient['ranking_quantity_text'];
        $key = recipeDetailIngredientKey((int)$ingredient['recipe_id'], $ingredient);
        $item = [
            'key' => $key,
            'position' => (int)$ingredient['position'],
            'source_text' => (string)$ingredient['source_text'],
            'display_name' => (string)$ingredient['display_name'],
            'name' => (string)$ingredient['display_name'],
            'amount' => [
                'quantity' => $amountQuantity !== null ? (float)$amountQuantity : null,
                'quantity_max' => $ingredient['ingredient_source'] === 'source'
                    && $ingredient['source_quantity_max'] !== null
                    ? (float)$ingredient['source_quantity_max']
                    : null,
                'unit' => trim((string)$amountUnit) !== '' ? (string)$amountUnit : null,
                'text' => trim((string)$amountText) !== '' ? (string)$amountText : null,
            ],
            'provider' => [
                'ingredient_ref' => trim((string)(
                    $ingredient['source_ingredient_ref'] ?? ''
                )) !== ''
                    ? (string)$ingredient['source_ingredient_ref']
                    : null,
                'default_title' => trim((string)(
                    $ingredient['source_default_title'] ?? ''
                )) !== ''
                    ? (string)$ingredient['source_default_title']
                    : null,
                'unit_ref' => trim((string)(
                    $ingredient['source_unit_ref'] ?? ''
                )) !== ''
                    ? (string)$ingredient['source_unit_ref']
                    : null,
                'optional' => $ingredient['source_optional'] !== null
                    ? (bool)$ingredient['source_optional']
                    : null,
                'shopping_category_ref' => trim((string)(
                    $ingredient['source_shopping_category_ref'] ?? ''
                )) !== ''
                    ? (string)$ingredient['source_shopping_category_ref']
                    : null,
            ],
            'inventory' => [
                'state' => $state,
                'relation' => $publicMatch !== null
                    ? (string)$publicMatch['relation']
                    : null,
                'confidence' => $publicMatch !== null
                    ? round((float)$publicMatch['score'], 6)
                    : round((float)$ingredient['mapping_confidence'], 6),
                'matched_product' => $publicMatch !== null ? [
                    'id' => (int)$publicMatch['product_id'],
                    'name' => (string)$publicMatch['product_name'],
                ] : null,
                'quantity_state' => $quantityState,
                'quantity_sufficiency' => $quantitySufficiency,
            ],
            'mapping' => [
                'source' => (string)$ingredient['mapping_source'],
                'confidence' => round(
                    (float)$ingredient['mapping_confidence'],
                    6
                ),
                'canonical' => $ingredient['canonical_ingredient_id'] !== null
                    ? [
                        'id' => (int)$ingredient['canonical_ingredient_id'],
                        'label' => trim((string)($ingredient['canonical_name'] ?? '')) !== ''
                            ? (string)$ingredient['canonical_name']
                            : null,
                    ]
                    : null,
                'taxonomy' => $ingredient['taxonomy_node_id'] !== null
                    ? [
                        'id' => (int)$ingredient['taxonomy_node_id'],
                        'label' => trim((string)($ingredient['taxonomy_name'] ?? '')) !== ''
                            ? (string)$ingredient['taxonomy_name']
                            : null,
                    ]
                    : null,
            ],
            '_canonical_key' => recipeDetailCanonicalShoppingKey($ingredient),
            '_shopping_name' => recipeDetailShoppingName($ingredient),
            '_ingredient_source' =>
                (string)$ingredient['ingredient_source'],
            '_ingredient_id' => (int)$ingredient['ingredient_id'],
            '_ranking_ingredient_id' =>
                (int)($ingredient['ranking_ingredient_id'] ?? 0),
            '_source_group_index' =>
                (int)$ingredient['source_group_index'],
            '_source_group_position' =>
                (int)$ingredient['source_group_position'],
            '_source_group_title' => trim((string)(
                $ingredient['source_group_title'] ?? ''
            )) !== ''
                ? (string)$ingredient['source_group_title']
                : null,
        ];
        if (recipeIngredientMappingSourceIsIdentitySafe(
            (string)$ingredient['mapping_source']
        )) {
            $matchLabel = trim((string)($ingredient['canonical_name'] ?? ''));
            if ($matchLabel === '') {
                $matchLabel = trim((string)($ingredient['taxonomy_name'] ?? ''));
            }
            if ($matchLabel !== '') {
                $item['closest_match'] = [
                    'label' => $matchLabel,
                    'canonical_ingredient_id' =>
                        $ingredient['canonical_ingredient_id'] !== null
                            ? (int)$ingredient['canonical_ingredient_id']
                            : null,
                    'taxonomy_node_id' => $ingredient['taxonomy_node_id'] !== null
                        ? (int)$ingredient['taxonomy_node_id']
                        : null,
                    'mapping_source' => (string)$ingredient['mapping_source'],
                    'confidence' => round(
                        (float)$ingredient['mapping_confidence'],
                        6
                    ),
                ];
            }
        }
        $out[] = $item;
    }
    return [
        'ingredients' => $out,
        'inventory_truncated' => $inventoryTruncated,
        'score_revision' => $v3['revision'] ?? null,
    ];
}

function recipeDetailIngredientGroups(
    int $recipeId,
    array $ingredients
): array {
    $groupRows = [];
    foreach ($ingredients as $ingredient) {
        $groupIndex = max(
            0,
            min(199, (int)($ingredient['_source_group_index'] ?? 0))
        );
        $groupRows[$groupIndex][] = [
            'key' => (string)$ingredient['key'],
            'position' => (int)$ingredient['position'],
            'label' => $ingredient['_source_group_title'] ?? null,
            'group_position' => max(
                0,
                min(
                    199,
                    (int)($ingredient['_source_group_position']
                        ?? $ingredient['position'])
                )
            ),
        ];
    }
    ksort($groupRows, SORT_NUMERIC);
    $groups = [];
    foreach ($groupRows as $groupIndex => $rows) {
        usort(
            $rows,
            static fn(array $left, array $right): int =>
                [$left['group_position'], $left['position']]
                    <=> [$right['group_position'], $right['position']]
        );
        $ingredientKeys = array_column($rows, 'key');
        $ingredientPositions = array_map(
            static fn(array $row): int => (int)$row['position'],
            $rows
        );
        $labels = array_values(array_unique(
            array_map(
                static fn(array $row): ?string => $row['label'],
                $rows
            ),
            SORT_REGULAR
        ));
        $label = count($labels) === 1 ? $labels[0] : null;
        $identity = recipeCatalogJsonEncode([
            'recipe_id' => $recipeId,
            'group_index' => (int)$groupIndex,
            'ingredient_keys' => $ingredientKeys,
        ]);
        $groups[] = [
            'key' => 'rig:' . (int)$groupIndex . ':'
                . substr(hash('sha256', $identity), 0, 16),
            'index' => (int)$groupIndex,
            'order' => count($groups),
            'label' => $label,
            'ingredient_keys' => $ingredientKeys,
            'ingredient_positions' => $ingredientPositions,
        ];
    }
    return $groups;
}

function recipeDetailInstructions(array $base): array {
    $connector = (string)$base['primary_connector'];
    $fallbackUrl = trim((string)($base['canonical_url'] ?? ''));
    if ($connector === RECIPE_COOKIDOO_CONNECTOR) {
        return [
            'available' => false,
            'reason' => 'provider_external_only',
            'steps' => [],
            'fallback_url' => $fallbackUrl !== '' ? $fallbackUrl : null,
            'truncated' => false,
        ];
    }
    $decoded = json_decode((string)($base['instructions_json'] ?? ''), true);
    $rawSteps = is_array($decoded) && recipeArrayIsList($decoded) ? $decoded : [];
    $truncated = (int)($base['instructions_json_length'] ?? 0)
            > RECIPE_DETAIL_MAX_INSTRUCTIONS_JSON_BYTES
        || count($rawSteps) > RECIPE_DETAIL_MAX_INSTRUCTIONS;
    $steps = [];
    foreach (array_slice($rawSteps, 0, RECIPE_DETAIL_MAX_INSTRUCTIONS) as $step) {
        if (!is_string($step)) {
            continue;
        }
        $step = trim($step);
        if ($step === '') {
            continue;
        }
        if (mb_strlen($step, 'UTF-8') > RECIPE_DETAIL_MAX_INSTRUCTION_LENGTH) {
            $step = mb_substr($step, 0, RECIPE_DETAIL_MAX_INSTRUCTION_LENGTH, 'UTF-8');
            $truncated = true;
        }
        $steps[] = $step;
    }
    $rawGroups = json_decode(
        (string)($base['instruction_groups_json'] ?? ''),
        true
    );
    if (!is_array($rawGroups) || !recipeArrayIsList($rawGroups)) {
        $rawGroups = [];
    }
    if (
        (int)($base['instruction_groups_json_length'] ?? 0)
            > RECIPE_DETAIL_MAX_INSTRUCTION_GROUPS_JSON_BYTES
        || count($rawGroups) > RECIPE_DETAIL_MAX_INSTRUCTION_GROUPS
    ) {
        $truncated = true;
    }
    $groups = [];
    foreach (
        array_slice($rawGroups, 0, RECIPE_DETAIL_MAX_INSTRUCTION_GROUPS)
        as $groupIndex => $group
    ) {
        if (!is_array($group)) {
            $truncated = true;
            continue;
        }
        $positions = $group['step_positions'] ?? null;
        if (!is_array($positions) || !recipeArrayIsList($positions)) {
            $truncated = true;
            continue;
        }
        $validPositions = [];
        foreach ($positions as $position) {
            if (
                is_bool($position)
                || !is_int($position)
                || $position < 0
                || $position >= count($steps)
            ) {
                $truncated = true;
                continue 2;
            }
            $validPositions[] = $position;
        }
        if (!$validPositions) {
            continue;
        }
        try {
            $label = recipeCatalogNormalizeOptionalText(
                $group['label'] ?? null,
                'instruction group label',
                160
            );
        } catch (InvalidArgumentException $e) {
            $truncated = true;
            continue;
        }
        $index = isset($group['index']) && is_int($group['index'])
            ? max(0, min(49, $group['index']))
            : (int)$groupIndex;
        $groups[] = [
            'key' => 'ig:' . $index . ':'
                . substr(hash('sha256', recipeCatalogJsonEncode([
                    'index' => $index,
                    'label' => $label,
                    'step_positions' => $validPositions,
                ])), 0, 16),
            'index' => $index,
            'order' => count($groups),
            'label' => $label,
            'step_positions' => $validPositions,
        ];
    }
    return [
        'available' => !empty($steps),
        'reason' => $steps ? null : 'not_available',
        'steps' => $steps,
        'groups' => $groups,
        'fallback_url' => null,
        'truncated' => $truncated,
    ];
}

function recipeCatalogDetailBuild(
    PDO $db,
    int $recipeId,
    bool $includeInternal = false,
    string $scoreMode = 'read',
    bool $allowProviderProbe = true
): ?array {
    if ($recipeId <= 0) {
        throw new InvalidArgumentException('invalid_recipe_id');
    }
    $base = recipeDetailLoadBase($db, $recipeId);
    if ($base === null) {
        return null;
    }
    if (!in_array($scoreMode, ['read', 'active'], true)) {
        throw new InvalidArgumentException('invalid_score_read_mode');
    }
    $read = $scoreMode === 'active'
        ? recipeScoreActiveReadRevision($db)
        : recipeScoreReadRevision($db);
    $readMetadata = recipeScoreReadMetadata($read);
    $loaded = recipeDetailLoadIngredients(
        $db,
        $recipeId,
        (string)($base['locale'] ?? $base['language'] ?? 'und')
    );
    foreach ($loaded['rows'] as &$ingredient) {
        $ingredient['recipe_id'] = $recipeId;
    }
    unset($ingredient);
    $presence = recipeDetailBuildIngredientPresence(
        $db,
        $loaded['rows'],
        $read
    );
    $effectiveScoreRevision =
        $presence['score_revision'] ?? ($read['revision'] ?? null);
    if (is_array($effectiveScoreRevision)) {
        $readMetadata['score_revision_id'] =
            (int)$effectiveScoreRevision['id'];
        $readMetadata['ontology_version_id'] =
            $effectiveScoreRevision['ontology_version_id'] !== null
                ? (int)$effectiveScoreRevision['ontology_version_id']
                : null;
        $readMetadata['overlay_score_revision_id'] = (
            (int)($readMetadata['overlay_score_revision_id'] ?? 0)
                === (int)$effectiveScoreRevision['id']
        ) ? (int)$effectiveScoreRevision['id'] : null;
    }
    $effectiveInventoryRevision = is_array($effectiveScoreRevision)
        ? (int)$effectiveScoreRevision['inventory_revision']
        : (int)($base['inventory_revision'] ?? 0);
    $ingredients = $presence['ingredients'];
    $ingredients = recipeIngredientFeedbackDecorate(
        $db,
        $recipeId,
        $ingredients,
        [
            'inventory' =>
                $effectiveInventoryRevision,
            'ranking' => $readMetadata['score_revision_id'],
            'catalog' =>
                (int)($base['catalog_revision'] ?? 0),
            'ontology' => $readMetadata['ontology_version_id'],
        ]
    );
    $ingredientGroups = recipeDetailIngredientGroups(
        $recipeId,
        $ingredients
    );

    $equipment = recipeDetailDecodeFactList(
        $base['equipment_json'] ?? '',
        false,
        false
    );
    $devices = recipeDetailDecodeFactList(
        $base['devices_json'] ?? '',
        true
    );
    $deviceKeys = array_fill_keys(
        array_map(
            static fn(string $name): string =>
                mb_strtolower($name, 'UTF-8'),
            $devices
        ),
        true
    );
    $optionalDevices = array_values(array_filter(
        recipeDetailDecodeFactList(
            $base['optional_devices_json'] ?? '',
            true
        ),
        static fn(string $name): bool =>
            !isset($deviceKeys[mb_strtolower($name, 'UTF-8')])
    ));
    $connector = (string)$base['primary_connector'];
    $prepTimeSeconds = $base['prep_time_seconds'] !== null
        ? (int)$base['prep_time_seconds']
        : null;
    $cookTimeSeconds = $base['cook_time_seconds'] !== null
        ? (int)$base['cook_time_seconds']
        : null;
    if ($connector !== RECIPE_COOKIDOO_CONNECTOR) {
        if (
            $prepTimeSeconds === null
            && (int)($base['prep_time_length'] ?? 0)
                <= RECIPE_TIME_MAX_SOURCE_LENGTH
        ) {
            $prepTimeSeconds = recipeTimeParseDurationSeconds(
                $base['prep_time'] ?? null,
                (string)($base['language'] ?? 'und')
            );
        }
        if (
            $cookTimeSeconds === null
            && (int)($base['cook_time_length'] ?? 0)
                <= RECIPE_TIME_MAX_SOURCE_LENGTH
        ) {
            $cookTimeSeconds = recipeTimeParseDurationSeconds(
                $base['cook_time'] ?? null,
                (string)($base['language'] ?? 'und')
            );
        }
    }
    $activeTimeSeconds = $base['active_time_seconds'] !== null
        ? (int)$base['active_time_seconds']
        : null;
    $totalTimeSeconds = $base['total_time_seconds'] !== null
        ? (int)$base['total_time_seconds']
        : null;
    $inactiveTimeSeconds = recipeTimeDeriveInactiveSeconds(
        $activeTimeSeconds,
        $totalTimeSeconds,
        $prepTimeSeconds,
        $cookTimeSeconds,
        $base['inactive_time_seconds'] !== null
            ? (int)$base['inactive_time_seconds']
            : null
    );
    $yieldQuantity = $base['yield_quantity'] !== null
        ? (float)$base['yield_quantity']
        : null;
    $yieldUnit = trim((string)($base['yield_unit'] ?? ''));
    if ($connector === RECIPE_COOKIDOO_CONNECTOR) {
        if ($yieldQuantity === null || $yieldUnit === '') {
            $yieldQuantity = null;
            $yieldUnit = '';
        }
    } elseif ($yieldQuantity === null && $base['servings'] !== null) {
        $yieldQuantity = (float)$base['servings'];
    }
    $primaryCategory = trim((string)($base['primary_category'] ?? ''));
    if ($primaryCategory === '' && $connector !== RECIPE_COOKIDOO_CONNECTOR) {
        $primaryCategory = trim((string)($base['category'] ?? ''));
    }
    $general = [
        'yield' => [
            'quantity' => $yieldQuantity,
            'unit' => $yieldUnit !== '' ? $yieldUnit : null,
        ],
        'prep_time_seconds' => $prepTimeSeconds,
        'cook_time_seconds' => $cookTimeSeconds,
        'active_time_seconds' => $activeTimeSeconds,
        'inactive_time_seconds' => $inactiveTimeSeconds,
        'total_time_seconds' => $totalTimeSeconds,
        'difficulty' => trim((string)($base['difficulty'] ?? '')) !== ''
            ? (string)$base['difficulty']
            : null,
        'primary_category' => $primaryCategory !== '' ? $primaryCategory : null,
        'devices' => $devices,
        'optional_devices' => $optionalDevices,
        'equipment' => $equipment,
    ];
    $generalValues = [
        $general['yield']['quantity'] !== null && $general['yield']['unit'] !== null,
        $general['active_time_seconds'] !== null,
        $general['total_time_seconds'] !== null,
        $general['difficulty'] !== null,
        $general['primary_category'] !== null,
    ];
    $generalCount = count(array_filter($generalValues));
    $supplementalGeneral = $general['prep_time_seconds'] !== null
        || $general['cook_time_seconds'] !== null
        || $general['inactive_time_seconds'] !== null
        || $devices
        || $optionalDevices;
    $generalCapability = $generalCount === count($generalValues)
        ? 'full'
        : (
            $generalCount > 0
            || $supplementalGeneral
            || $equipment
                ? 'partial'
                : 'none'
        );

    $instructions = recipeDetailInstructions($base);
    $instructionCapability = $instructions['available']
        ? 'local'
        : (
            $instructions['reason'] === 'provider_external_only'
                && $instructions['fallback_url'] !== null
                ? 'external_link'
                : 'none'
        );
    $quantityStates = [];
    foreach ($ingredients as $ingredient) {
        if (($ingredient['inventory']['state'] ?? '') === 'staple') {
            continue;
        }
        $quantityStates[] = (string)$ingredient['inventory']['quantity_state'];
    }
    $quantityCapability = $quantityStates
        && count(array_unique($quantityStates)) === 1
        && $quantityStates[0] === 'known'
        ? 'known'
        : (in_array('display_only', $quantityStates, true) ? 'display_only' : 'unknown');
    $groceryCounts = [
        'missing_count' => 0,
        'uncertain_count' => 0,
        'in_stock_count' => 0,
        'staple_count' => 0,
    ];
    foreach ($ingredients as $ingredient) {
        $state = (string)($ingredient['inventory']['state'] ?? 'uncertain');
        $countKey = $state . '_count';
        if (array_key_exists($countKey, $groceryCounts)) {
            $groceryCounts[$countKey]++;
        }
    }
    $groceryBlockedReason = $loaded['truncated']
        ? 'ingredients_truncated'
        : (!$ingredients ? 'no_ingredients' : null);
    $grocerySupported = recipeDetailGroceryBackendSupported();
    $groceryCapability = $grocerySupported
        && $groceryBlockedReason === null;

    $connector = (string)$base['primary_connector'];
    $connectorMetadata = recipeConnectorRegistry()[$connector] ?? [
        'label' => $connector,
    ];
    $planner = recipePlannerDetailProjection(
        $base,
        $allowProviderProbe && !$db->inTransaction()
    );
    $imageUrl = trim((string)($base['image_url'] ?? ''));
    $thumbnailUrl = $imageUrl !== ''
        ? recipeCatalogCookidooThumbnail($imageUrl)
        : '';
    $detail = [
        'schema_version' => RECIPE_DETAIL_SCHEMA_VERSION,
        'id' => (int)$base['id'],
        'title' => (string)$base['title'],
        'source' => [
            'connector' => $connector,
            'label' => (string)($connectorMetadata['label'] ?? $connector),
            'attribution' => recipeDetailSourceAttribution(
                $connector,
                $base['attribution'] ?? null
            ),
            'external_id' => trim((string)($base['external_id'] ?? '')) !== ''
                ? (string)$base['external_id']
                : null,
            'canonical_url' => trim((string)($base['canonical_url'] ?? '')) !== ''
                ? (string)$base['canonical_url']
                : null,
            'locale' => trim((string)($base['locale'] ?? $base['language'] ?? '')) !== ''
                ? (string)($base['locale'] ?? $base['language'])
                : null,
            'content_language' =>
                trim((string)($base['content_language'] ?? '')) !== ''
                    ? (string)$base['content_language']
                    : null,
            'rights_basis' => (string)$base['rights_basis'],
            'metadata_version' => trim((string)($base['metadata_version'] ?? '')) !== ''
                ? (string)$base['metadata_version']
                : null,
            'metadata_schema_version' => trim((string)(
                $base['metadata_schema_version'] ?? ''
            )) !== ''
                ? (string)$base['metadata_schema_version']
                : null,
        ],
        'images' => [
            'primary' => $imageUrl !== '' ? $imageUrl : null,
            'thumbnail' => $thumbnailUrl !== '' && $thumbnailUrl !== $imageUrl
                ? $thumbnailUrl
                : null,
        ],
        'general' => $general,
        'planner' => $planner,
        'ingredients' => $ingredients,
        'ingredient_groups' => $ingredientGroups,
        'ingredients_truncated' => (bool)$loaded['truncated'],
        'grocery' => $groceryCounts + [
            'eligible_count' => $groceryCounts['missing_count'],
            'max_selections' => RECIPE_GROCERY_MAX_SELECTIONS,
            'blocked_reason' => $groceryBlockedReason,
        ],
        'instructions' => $instructions,
        'user_state' => [
            'favorite' => !empty($base['favorite']),
            'hidden' => !empty($base['hidden']),
            'rating' => $base['rating'] !== null ? (int)$base['rating'] : null,
            'note' => (string)$base['note'],
            'cooked_count' => (int)$base['cooked_count'],
            'last_cooked' => $base['last_cooked'],
        ],
        'freshness' => [
            'retrieved_at' => $base['retrieved_at'],
            'stale_at' => $base['stale_at'],
            'updated_at' => $base['updated_at'],
            'is_stale' => !empty($base['is_stale']),
        ],
        'revision' => [
            'inventory' => $effectiveInventoryRevision,
            'ranking' => $readMetadata['score_revision_id'],
            'catalog' => (int)($base['catalog_revision'] ?? 0),
            'ontology' => $readMetadata['ontology_version_id'],
            'preview' => $readMetadata['preview'],
            'active_ranking' =>
                $readMetadata['active_score_revision_id'],
            'preview_ranking' =>
                $readMetadata['preview_revision_id'],
            'preview_ontology' =>
                $readMetadata['preview_ontology_version_id'],
        ],
        'preview_diagnostics' =>
            $readMetadata['preview_diagnostics'],
        'capabilities' => [
            'general' => $generalCapability,
            'ingredients' => $ingredients ? 'checklist' : 'none',
            'instructions' => $instructionCapability,
            'quantities' => $quantityCapability,
            'grocery_add' => $groceryCapability,
            'ingredient_feedback' => true,
            'ingredient_feedback_v2' => true,
            'planner' => (bool)$planner['available'],
            'score_preview' =>
                $readMetadata['preview_capability'],
        ],
        '_inventory_truncated' => (bool)$presence['inventory_truncated'],
    ];
    if (!$includeInternal) {
        foreach ($detail['ingredients'] as &$ingredient) {
            unset(
                $ingredient['_canonical_key'],
                $ingredient['_shopping_name'],
                $ingredient['_ingredient_source'],
                $ingredient['_ingredient_id'],
                $ingredient['_ranking_ingredient_id'],
                $ingredient['_source_group_index'],
                $ingredient['_source_group_position'],
                $ingredient['_source_group_title']
            );
        }
        unset($ingredient);
        unset($detail['_inventory_truncated']);
    }
    return $detail;
}

function recipeCatalogDetail(PDO $db, int $recipeId): ?array {
    return recipeCatalogDetailBuild($db, $recipeId, false);
}

function recipeGroceryNormalizeSelectors(array $input): array {
    $selectors = [];
    if (array_key_exists('selections', $input)) {
        if (!is_array($input['selections']) || !recipeArrayIsList($input['selections'])) {
            throw new InvalidArgumentException('selections must be an array');
        }
        foreach ($input['selections'] as $selection) {
            if (!is_array($selection)) {
                throw new InvalidArgumentException('selection is invalid');
            }
            $keyValue = $selection['key'] ?? $selection['ingredient_key'] ?? '';
            if (!is_string($keyValue)) {
                throw new InvalidArgumentException('selection is invalid');
            }
            $position = null;
            if (array_key_exists('position', $selection)) {
                if (is_bool($selection['position']) || !is_int($selection['position'])) {
                    throw new InvalidArgumentException('selection is invalid');
                }
                $position = $selection['position'];
            }
            $selectors[] = [
                'key' => trim($keyValue),
                'position' => $position,
            ];
        }
    }
    if (array_key_exists('ingredient_keys', $input)) {
        if (!is_array($input['ingredient_keys']) || !recipeArrayIsList($input['ingredient_keys'])) {
            throw new InvalidArgumentException('ingredient_keys must be an array');
        }
        foreach ($input['ingredient_keys'] as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('ingredient_keys contains an invalid key');
            }
            $selectors[] = ['key' => trim($key), 'position' => null];
        }
    }
    if (array_key_exists('positions', $input)) {
        if (!is_array($input['positions']) || !recipeArrayIsList($input['positions'])) {
            throw new InvalidArgumentException('positions must be an array');
        }
        foreach ($input['positions'] as $position) {
            if (is_bool($position) || !is_int($position)) {
                throw new InvalidArgumentException('positions contains an invalid position');
            }
            $selectors[] = ['key' => '', 'position' => $position];
        }
    }
    if (!$selectors || count($selectors) > RECIPE_GROCERY_MAX_SELECTIONS) {
        throw new InvalidArgumentException('selection count is invalid');
    }
    foreach ($selectors as $selector) {
        if (
            ($selector['key'] === '' && $selector['position'] === null)
            || ($selector['position'] !== null && $selector['position'] < 0)
            || ($selector['key'] !== '' && !preg_match('/^ri:\d+:[a-f0-9]{16}$/', $selector['key']))
        ) {
            throw new InvalidArgumentException('selection is invalid');
        }
    }
    return $selectors;
}

function recipeGroceryRequestFingerprint(int $recipeId, array $selectors): string {
    $normalizedSelectors = array_map(
        static fn(array $selector): array => [
            'key' => (string)$selector['key'],
            'position' => $selector['position'] !== null
                ? (int)$selector['position']
                : null,
        ],
        $selectors
    );
    return hash('sha256', recipeCatalogJsonEncode([
        'version' => 1,
        'recipe_id' => $recipeId,
        'selectors' => $normalizedSelectors,
    ]));
}

function recipeGrocerySummary(array $outcomes): array {
    $summary = [
        'added' => 0,
        'already_listed' => 0,
        'now_in_stock' => 0,
        'unresolved' => 0,
        'failed' => 0,
    ];
    foreach ($outcomes as $outcome) {
        $name = (string)($outcome['outcome'] ?? '');
        if (array_key_exists($name, $summary)) {
            $summary[$name]++;
        }
    }
    return $summary;
}

function recipeGroceryPruneRequests(PDO $db, string $preserveKey): int {
    $retentionModifier = '-' . RECIPE_GROCERY_REQUEST_RETENTION_DAYS . ' days';
    $batchSize = RECIPE_GROCERY_PRUNE_BATCH_SIZE;
    $stmt = $db->prepare("
        DELETE FROM recipe_grocery_requests
        WHERE id IN (
            SELECT id
            FROM recipe_grocery_requests
            WHERE created_at < datetime('now', ?)
              AND idempotency_key <> ?
            ORDER BY created_at ASC, id ASC
            LIMIT {$batchSize}
        )
    ");
    $stmt->execute([$retentionModifier, $preserveKey]);
    return $stmt->rowCount();
}

function recipeGroceryReplayResult(
    int $recipeId,
    string $idempotencyKey,
    string $outcomesJson
): array {
    $outcomes = json_decode($outcomesJson, true);
    $outcomes = is_array($outcomes) ? $outcomes : [];
    return [
        'recipe_id' => $recipeId,
        'idempotency_key' => $idempotencyKey,
        'replayed' => true,
        'outcomes' => $outcomes,
        'summary' => recipeGrocerySummary($outcomes),
    ];
}

function recipeGroceryAddMissing(PDO $db, array $input): array {
    $recipeIdValue = $input['recipe_id'] ?? $input['id'] ?? null;
    if (is_bool($recipeIdValue) || !is_int($recipeIdValue) || $recipeIdValue <= 0) {
        throw new InvalidArgumentException('invalid_recipe_id');
    }
    $recipeId = $recipeIdValue;
    $idempotencyValue = $input['idempotency_key'] ?? '';
    $idempotencyKey = is_string($idempotencyValue) ? trim($idempotencyValue) : '';
    if (
        $idempotencyKey === ''
        || strlen($idempotencyKey) > 128
        || !preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey)
    ) {
        throw new InvalidArgumentException('invalid_idempotency_key');
    }
    $selectors = recipeGroceryNormalizeSelectors($input);
    $requestFingerprint = recipeGroceryRequestFingerprint($recipeId, $selectors);
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->exec('BEGIN IMMEDIATE');
    }
    try {
        recipeGroceryPruneRequests($db, $idempotencyKey);
        $existingRequest = $db->prepare("
            SELECT recipe_id, request_fingerprint, selection_hash, outcomes_json
            FROM recipe_grocery_requests
            WHERE idempotency_key = ?
            LIMIT 1
        ");
        $existingRequest->execute([$idempotencyKey]);
        $legacyRequest = null;
        if ($existing = $existingRequest->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$existing['recipe_id'] !== $recipeId) {
                throw new RecipeGroceryConflictException('idempotency_key_conflict');
            }
            $storedFingerprint = trim((string)($existing['request_fingerprint'] ?? ''));
            if ($storedFingerprint !== '') {
                if (!hash_equals($storedFingerprint, $requestFingerprint)) {
                    throw new RecipeGroceryConflictException('idempotency_key_conflict');
                }
                $result = recipeGroceryReplayResult(
                    $recipeId,
                    $idempotencyKey,
                    (string)$existing['outcomes_json']
                );
                if ($ownsTransaction) {
                    $db->exec('COMMIT');
                }
                return $result;
            }
            $legacyRequest = $existing;
        }

        $detail = recipeCatalogDetailBuild(
            $db,
            $recipeId,
            true,
            'active',
            false
        );
        if ($detail === null) {
            throw new OutOfBoundsException('recipe_not_found');
        }
        if (empty($detail['capabilities']['grocery_add'])) {
            $blockedReason = (string)($detail['grocery']['blocked_reason'] ?? '');
            throw new InvalidArgumentException(
                $blockedReason !== ''
                    ? 'grocery_add_blocked_' . $blockedReason
                    : 'grocery_add_unsupported'
            );
        }
        $byKey = [];
        $byPosition = [];
        foreach ($detail['ingredients'] as $ingredient) {
            $byKey[(string)$ingredient['key']] = $ingredient;
            $byPosition[(int)$ingredient['position']] = $ingredient;
        }
        $selected = [];
        foreach ($selectors as $selector) {
            $ingredient = null;
            if ($selector['key'] !== '') {
                $ingredient = $byKey[$selector['key']] ?? null;
            } elseif ($selector['position'] !== null) {
                $ingredient = $byPosition[$selector['position']] ?? null;
            }
            if (
                $ingredient === null
                || (
                    $selector['position'] !== null
                    && (int)$ingredient['position'] !== $selector['position']
                )
            ) {
                throw new InvalidArgumentException('invalid_recipe_ingredient_selection');
            }
            $selected[] = $ingredient;
        }
        $selectionKeys = array_values(array_unique(array_map(
            static fn(array $ingredient): string => (string)$ingredient['key'],
            $selected
        )));
        sort($selectionKeys, SORT_STRING);
        $selectionHash = hash('sha256', recipeCatalogJsonEncode($selectionKeys));

        if ($legacyRequest !== null) {
            if (!hash_equals((string)$legacyRequest['selection_hash'], $selectionHash)) {
                throw new RecipeGroceryConflictException('idempotency_key_conflict');
            }
            $db->prepare("
                UPDATE recipe_grocery_requests
                SET request_fingerprint = ?
                WHERE idempotency_key = ?
                  AND (
                      request_fingerprint IS NULL
                      OR TRIM(request_fingerprint) = ''
                  )
            ")->execute([$requestFingerprint, $idempotencyKey]);
            $result = recipeGroceryReplayResult(
                $recipeId,
                $idempotencyKey,
                (string)$legacyRequest['outcomes_json']
            );
            if ($ownsTransaction) {
                $db->exec('COMMIT');
            }
            return $result;
        }

        $findListed = $db->prepare("
            SELECT id, name, canonical_key
            FROM shopping_list
            WHERE canonical_key = ?
               OR (
                   (canonical_key IS NULL OR TRIM(canonical_key) = '')
                   AND lower(name) = lower(?)
               )
            ORDER BY CASE WHEN canonical_key = ? THEN 0 ELSE 1 END, id ASC
            LIMIT 1
        ");
        $insertListed = $db->prepare("
            INSERT INTO shopping_list (
                name, raw_name, specification, quantity, canonical_key
            )
            VALUES (?, ?, ?, 1, ?)
        ");
        $findNameCollision = $db->prepare("
            SELECT id
            FROM shopping_list
            WHERE lower(name) = lower(?)
            LIMIT 1
        ");
        $attachCanonicalKey = $db->prepare("
            UPDATE shopping_list
            SET canonical_key = ?
            WHERE id = ? AND (canonical_key IS NULL OR TRIM(canonical_key) = '')
        ");
        $seenCanonical = [];
        $outcomes = [];
        foreach ($selected as $ingredient) {
            $outcome = [
                'key' => (string)$ingredient['key'],
                'position' => (int)$ingredient['position'],
                'outcome' => 'unresolved',
                'normalized_name' => (string)$ingredient['_shopping_name'],
                'amount_text' => $ingredient['amount']['text'],
            ];
            $state = (string)($ingredient['inventory']['state'] ?? 'uncertain');
            $availabilityOverride = (string)(
                $ingredient['user_override']['availability'] ?? ''
            );
            if (
                $availabilityOverride === 'have'
                || $state === 'in_stock'
                || $state === 'staple'
            ) {
                $outcome['outcome'] = 'now_in_stock';
                $outcomes[] = $outcome;
                continue;
            }
            if ($state !== 'missing' || $outcome['normalized_name'] === '') {
                $outcomes[] = $outcome;
                continue;
            }
            $canonicalKey = (string)$ingredient['_canonical_key'];
            if (isset($seenCanonical[$canonicalKey])) {
                $outcome['outcome'] = 'already_listed';
                $outcomes[] = $outcome;
                continue;
            }
            $db->exec('SAVEPOINT recipe_grocery_item');
            try {
                $confirmedListed = false;
                $findListed->execute([
                    $canonicalKey,
                    $outcome['normalized_name'],
                    $canonicalKey,
                ]);
                $listed = $findListed->fetch(PDO::FETCH_ASSOC);
                if ($listed) {
                    if (trim((string)($listed['canonical_key'] ?? '')) === '') {
                        $attachCanonicalKey->execute([$canonicalKey, (int)$listed['id']]);
                    }
                    $outcome['outcome'] = 'already_listed';
                    $confirmedListed = true;
                } else {
                    $insertName = $outcome['normalized_name'];
                    $findNameCollision->execute([$insertName]);
                    if ($findNameCollision->fetchColumn() !== false) {
                        $sourceSpecificName = recipeIngredientBoundedSourceText(
                            $ingredient['source_text'] ?? '',
                            $insertName
                        );
                        $sourceSpecificName = mb_convert_case(
                            $sourceSpecificName,
                            MB_CASE_TITLE,
                            'UTF-8'
                        );
                        if (
                            recipeIngredientNormalizeName($sourceSpecificName)
                            === recipeIngredientNormalizeName($insertName)
                        ) {
                            $sourceSpecificName = mb_substr(
                                $insertName . ' ' . substr(
                                    hash('sha256', $canonicalKey),
                                    0,
                                    8
                                ),
                                0,
                                200,
                                'UTF-8'
                            );
                        }
                        $insertName = $sourceSpecificName;
                    }
                    $insertListed->execute([
                        $insertName,
                        mb_substr((string)$ingredient['name'], 0, 200, 'UTF-8'),
                        mb_substr((string)($outcome['amount_text'] ?? ''), 0, 160, 'UTF-8'),
                        $canonicalKey,
                    ]);
                    $outcome['normalized_name'] = $insertName;
                    $outcome['outcome'] = 'added';
                    $confirmedListed = true;
                }
                $db->exec('RELEASE SAVEPOINT recipe_grocery_item');
                if ($confirmedListed) {
                    $seenCanonical[$canonicalKey] = true;
                }
            } catch (Throwable $e) {
                $db->exec('ROLLBACK TO SAVEPOINT recipe_grocery_item');
                $db->exec('RELEASE SAVEPOINT recipe_grocery_item');
                foreach (
                    [
                        $findListed,
                        $findNameCollision,
                        $insertListed,
                        $attachCanonicalKey,
                    ]
                    as $statement
                ) {
                    try {
                        $statement->closeCursor();
                    } catch (Throwable $closeError) {
                    }
                }
                $outcome['outcome'] = 'failed';
            }
            $outcomes[] = $outcome;
        }
        $db->prepare("
            INSERT INTO recipe_grocery_requests (
                idempotency_key, recipe_id, request_fingerprint,
                selection_hash, outcomes_json
            )
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            $idempotencyKey,
            $recipeId,
            $requestFingerprint,
            $selectionHash,
            recipeCatalogJsonEncode($outcomes),
        ]);
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
        return [
            'recipe_id' => $recipeId,
            'idempotency_key' => $idempotencyKey,
            'replayed' => false,
            'outcomes' => $outcomes,
            'summary' => recipeGrocerySummary($outcomes),
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $rollbackError) {
            }
        }
        throw $e;
    }
}
