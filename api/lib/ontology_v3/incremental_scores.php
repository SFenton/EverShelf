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

function ingredientOntologyV3IncrementalMaximumCoalesceMilliseconds(): int {
    $value = function_exists('env')
        ? (int)env(
            'RECIPE_SCORE_INCREMENTAL_MAX_COALESCE_MS',
            '2000'
        )
        : 2000;
    return max(100, min(30000, $value));
}

function ingredientOntologyV3IncrementalProductLimit(): int {
    return function_exists('env')
        ? max(1, min(1000, (int)env(
            'RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT',
            '500'
        )))
        : 500;
}

function ingredientOntologyV3IncrementalCoveredRevision(
    PDO $db,
    string $domain,
    int $parentCoveredRevision,
    int $currentRevision,
    bool $servingOnly,
    array $productIds = [],
    array $recipeIds = []
): int {
    if (!$servingOnly || $currentRevision <= $parentCoveredRevision) {
        return $servingOnly
            ? $parentCoveredRevision
            : $currentRevision;
    }
    if (!in_array($domain, ['catalog', 'source'], true)) {
        throw new InvalidArgumentException(
            'incremental covered revision domain is invalid'
        );
    }
    $productIds = array_values(array_unique(array_filter(
        array_map('intval', $productIds),
        static fn(int $productId): bool => $productId > 0
    )));
    $recipeIds = array_values(array_unique(array_filter(
        array_map('intval', $recipeIds),
        static fn(int $recipeId): bool => $recipeId > 0
    )));
    $allowed = [];
    $params = [
        $domain,
        $parentCoveredRevision,
        $currentRevision,
    ];
    if ($recipeIds) {
        $allowed[] = "(owner_type = 'recipe' AND owner_id IN ("
            . implode(',', array_fill(0, count($recipeIds), '?'))
            . '))';
        array_push($params, ...$recipeIds);
    }
    if ($domain === 'source' && $productIds) {
        $allowed[] = "(owner_type = 'product' AND owner_id IN ("
            . implode(',', array_fill(0, count($productIds), '?'))
            . '))';
        array_push($params, ...$productIds);
    }
    $scopeWhere = $allowed
        ? ' AND NOT (' . implode(' OR ', $allowed) . ')'
        : '';
    $uncovered = $db->prepare("
        SELECT 1
        FROM recipe_score_mutations
        WHERE domain = ?
          AND revision > ?
          AND revision <= ?
          {$scopeWhere}
        LIMIT 1
    ");
    $uncovered->execute($params);
    if ($uncovered->fetchColumn() !== false) {
        return $parentCoveredRevision;
    }
    $count = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_score_mutations
        WHERE domain = ?
          AND revision > ?
          AND revision <= ?
    ");
    $count->execute([
        $domain,
        $parentCoveredRevision,
        $currentRevision,
    ]);
    return (int)$count->fetchColumn()
            === $currentRevision - $parentCoveredRevision
        ? $currentRevision
        : $parentCoveredRevision;
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

function ingredientOntologyV3IncrementalPendingRecipes(
    PDO $db,
    string $lane = 'serving'
): array {
    if (!ingredientOntologyV3TableExists(
        $db,
        'recipe_score_pending_recipes'
    )) {
        return [];
    }
    if (!in_array($lane, ['serving', 'maintenance'], true)) {
        throw new InvalidArgumentException(
            'incremental pending recipe lane is invalid'
        );
    }
    $limit = ingredientOntologyV3IncrementalProductLimit();
    $rows = $db->prepare("
        SELECT recipe_id, operation,
               lane,
               first_catalog_revision,
               latest_catalog_revision,
               latest_ontology_source_revision,
               reason, created_at, updated_at
        FROM recipe_score_pending_recipes
        WHERE lane = ?
        ORDER BY updated_at, recipe_id
        LIMIT {$limit}
    ");
    $rows->execute([$lane]);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn(array $row): array => [
        'recipe_id' => (int)$row['recipe_id'],
        'operation' => (string)$row['operation'],
        'lane' => (string)$row['lane'],
        'first_catalog_revision' =>
            (int)$row['first_catalog_revision'],
        'latest_catalog_revision' =>
            (int)$row['latest_catalog_revision'],
        'latest_ontology_source_revision' =>
            (int)$row['latest_ontology_source_revision'],
        'reason' => (string)$row['reason'],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ], $rows);
}

function ingredientOntologyV3IncrementalPendingRecipeOverflow(
    PDO $db,
    int $selectedCount,
    string $lane = 'serving'
): bool {
    if (!in_array($lane, ['serving', 'maintenance'], true)) {
        throw new InvalidArgumentException(
            'incremental pending recipe lane is invalid'
        );
    }
    $limit = ingredientOntologyV3IncrementalProductLimit();
    if ($selectedCount < $limit) {
        return false;
    }
    $stmt = $db->prepare("
        SELECT 1
        FROM recipe_score_pending_recipes
        WHERE lane = ?
        ORDER BY updated_at, recipe_id
        LIMIT 1 OFFSET {$limit}
    ");
    $stmt->execute([$lane]);
    return $stmt->fetchColumn() !== false;
}

function ingredientOntologyV3IncrementalScopedMutationErrors(
    PDO $db,
    array $parent,
    array $state,
    array $productIds,
    array $recipeIds,
    bool $servingOnly = false
): array {
    $productIds = array_values(array_unique(array_filter(
        array_map('intval', $productIds),
        static fn(int $productId): bool => $productId > 0
    )));
    $recipeIds = array_values(array_unique(array_filter(
        array_map('intval', $recipeIds),
        static fn(int $recipeId): bool => $recipeId > 0
    )));
    if ($servingOnly) {
        return [];
    }
    $errors = [];
    foreach ([
        'catalog' => [
            (int)$parent['catalog_revision'],
            (int)$state['catalog_revision'],
        ],
        'source' => [
            (int)$parent['ontology_source_revision'],
            (int)$state['ontology_source_revision'],
        ],
    ] as $domain => [$from, $through]) {
        if ($through === $from) {
            continue;
        }
        if ($through < $from) {
            $errors[] = "{$domain}_revision_regressed";
            continue;
        }
        $count = $db->prepare("
            SELECT COUNT(*)
            FROM recipe_score_mutations
            WHERE domain = ?
              AND revision > ?
              AND revision <= ?
        ");
        $count->execute([$domain, $from, $through]);
        if ((int)$count->fetchColumn() !== $through - $from) {
            $errors[] = "{$domain}_mutation_journal_incomplete";
            continue;
        }
        $allowed = [];
        $params = [$domain, $from, $through];
        if ($recipeIds) {
            $allowed[] = "(owner_type = 'recipe' AND owner_id IN ("
                . implode(',', array_fill(0, count($recipeIds), '?'))
                . '))';
            array_push($params, ...$recipeIds);
        }
        if ($domain === 'source' && $productIds) {
            $allowed[] = "(owner_type = 'product' AND owner_id IN ("
                . implode(',', array_fill(0, count($productIds), '?'))
                . '))';
            array_push($params, ...$productIds);
        }
        $scopeWhere = $allowed
            ? ' AND NOT (' . implode(' OR ', $allowed) . ')'
            : '';
        $violation = $db->prepare("
            SELECT 1
            FROM recipe_score_mutations
            WHERE domain = ?
              AND revision > ?
              AND revision <= ?
              {$scopeWhere}
            LIMIT 1
        ");
        $violation->execute($params);
        if ($violation->fetchColumn() !== false) {
            $errors[] = "{$domain}_mutation_unscoped";
        }
    }
    return array_values(array_unique($errors));
}

function ingredientOntologyV3IncrementalSourceDeltaIsProductOnly(
    PDO $db,
    array $parent,
    array $state,
    array $productIds
): bool {
    $from = (int)$parent['ontology_source_revision'];
    $through = (int)$state['ontology_source_revision'];
    if ($through === $from) {
        return true;
    }
    $productIds = array_values(array_unique(array_filter(
        array_map('intval', $productIds),
        static fn(int $productId): bool => $productId > 0
    )));
    if ($through < $from || !$productIds) {
        return false;
    }
    $count = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_score_mutations
        WHERE domain = 'source'
          AND revision > ?
          AND revision <= ?
    ");
    $count->execute([$from, $through]);
    if ((int)$count->fetchColumn() !== $through - $from) {
        return false;
    }
    $placeholders = implode(
        ',',
        array_fill(0, count($productIds), '?')
    );
    $violation = $db->prepare("
        SELECT 1
        FROM recipe_score_mutations
        WHERE domain = 'source'
          AND revision > ?
          AND revision <= ?
          AND (
              owner_type <> 'product'
              OR owner_id NOT IN ({$placeholders})
          )
        LIMIT 1
    ");
    $violation->execute([$from, $through, ...$productIds]);
    return $violation->fetchColumn() === false;
}

function ingredientOntologyV3IncrementalSourceProductIds(
    PDO $db,
    array $parent,
    array $state
): array {
    $from = (int)$parent['ontology_source_revision'];
    $through = (int)$state['ontology_source_revision'];
    if ($through <= $from) {
        return [];
    }
    $stmt = $db->prepare("
        SELECT DISTINCT owner_id
        FROM recipe_score_mutations
        WHERE domain = 'source'
          AND revision > ?
          AND revision <= ?
          AND owner_type = 'product'
          AND owner_id IS NOT NULL
          AND owner_id > 0
        ORDER BY owner_id
    ");
    $stmt->execute([$from, $through]);
    return array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );
}

function ingredientOntologyV3IncrementalScopedParentErrors(
    PDO $db,
    array $parent,
    array $state,
    array $productIds,
    array $recipeIds,
    bool $servingOnly = false
): array {
    $scopeErrors =
        ingredientOntologyV3IncrementalScopedMutationErrors(
            $db,
            $parent,
            $state,
            $productIds,
            $recipeIds,
            $servingOnly
        );
    $catalogScoped = (int)$parent['catalog_revision']
            === (int)$state['catalog_revision']
        || !array_filter(
            $scopeErrors,
            static fn(string $error): bool =>
                str_starts_with($error, 'catalog_')
        );
    $sourceScoped = (int)$parent['ontology_source_revision']
            === (int)$state['ontology_source_revision']
        || !array_filter(
            $scopeErrors,
            static fn(string $error): bool =>
                str_starts_with($error, 'source_')
        );
    $validationState = $state;
    if ($catalogScoped) {
        $validationState['catalog_revision'] =
            (int)$parent['catalog_revision'];
    }
    if ($sourceScoped) {
        $validationState['ontology_source_revision'] =
            (int)$parent['ontology_source_revision'];
        $validationState['ontology_source_hash'] =
            (string)$parent['ontology_source_hash'];
        $validationState['ontology_source_lineage_hash'] =
            (string)(
                $parent['ontology_source_lineage_hash'] ?? ''
            );
    }
    $errors = ingredientOntologyV3IncrementalParentErrors(
        $db,
        $parent,
        $validationState
    );
    if ($servingOnly) {
        $errors = array_values(array_diff(
            $errors,
            ['parent_score_date_stale']
        ));
    }
    if ($catalogScoped) {
        $errors = array_values(array_diff(
            $errors,
            ['catalog_revision_changed']
        ));
    }
    if ($sourceScoped) {
        $errors = array_values(array_diff(
            $errors,
            ['ontology_source_changed']
        ));
    }
    return array_values(array_unique(array_merge(
        $errors,
        $scopeErrors
    )));
}

function ingredientOntologyV3IncrementalScopedInputHash(
    PDO $db,
    string $domain,
    string $parentHash,
    int $fromRevision,
    int $throughRevision,
    array $productIds,
    array $recipeIds,
    bool $servingOnly = false
): string {
    if ($fromRevision === $throughRevision) {
        return $parentHash;
    }
    $laneWhere = $servingOnly
        ? " AND lane = 'serving'"
        : '';
    $events = $db->prepare("
        SELECT revision, owner_type, owner_id,
               operation, reason
        FROM recipe_score_mutations
        WHERE domain = ?
          AND revision > ?
          AND revision <= ?
          {$laneWhere}
        ORDER BY revision
    ");
    $events->execute([$domain, $fromRevision, $throughRevision]);
    $eventHash = hash_init('sha256');
    $eventCount = 0;
    while ($event = $events->fetch(PDO::FETCH_ASSOC)) {
        hash_update(
            $eventHash,
            ingredientOntologyV3Json([
                'revision' => (int)$event['revision'],
                'owner_type' => (string)$event['owner_type'],
                'owner_id' => $event['owner_id'] !== null
                    ? (int)$event['owner_id']
                    : null,
                'operation' => (string)$event['operation'],
                'reason' => (string)$event['reason'],
            ]) . "\n"
        );
        $eventCount++;
    }
    $events->closeCursor();
    $productIds = array_values(array_unique(array_map(
        'intval',
        $productIds
    )));
    sort($productIds, SORT_NUMERIC);
    $recipeIds = array_values(array_unique(array_map(
        'intval',
        $recipeIds
    )));
    sort($recipeIds, SORT_NUMERIC);
    $productEvidence = [];
    if ($productIds) {
        $placeholders = implode(
            ',',
            array_fill(0, count($productIds), '?')
        );
        $stmt = $db->prepare("
            SELECT product_id, owner_fingerprint,
                   status, entity_id, extension_entity_id,
                   evidence_hash
            FROM ingredient_ontology_identity_annex
            WHERE product_id IN ({$placeholders})
            ORDER BY product_id
        ");
        $stmt->execute($productIds);
        $productEvidence = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $recipeEvidence = [];
    if ($recipeIds) {
        $placeholders = implode(
            ',',
            array_fill(0, count($recipeIds), 'CAST(? AS INTEGER)')
        );
        $stmt = $db->prepare("
            SELECT ingredient.recipe_id,
                   annex.recipe_ingredient_id,
                   annex.owner_fingerprint,
                   annex.status, annex.entity_id,
                   annex.extension_entity_id,
                   annex.evidence_hash
            FROM ingredient_ontology_recipe_identity_annex annex
            JOIN recipe_ingredients ingredient
              ON ingredient.id = annex.recipe_ingredient_id
            WHERE ingredient.recipe_id IN ({$placeholders})
            ORDER BY ingredient.recipe_id,
                     annex.recipe_ingredient_id
        ");
        $stmt->execute($recipeIds);
        $recipeEvidence = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return ingredientOntologyV3Hash([
        'algorithm' => 'scoped-input-delta-v2',
        'domain' => $domain,
        'parent_hash' => $parentHash,
        'from_revision' => $fromRevision,
        'through_revision' => $throughRevision,
        'event_count' => $eventCount,
        'events_hash' => hash_final($eventHash),
        'product_ids' => $productIds,
        'recipe_ids' => $recipeIds,
        'recipe_catalog_hash' =>
            recipeScoreCatalogRecipeFingerprint($db, $recipeIds),
        'product_evidence' => $productEvidence,
        'recipe_evidence' => $recipeEvidence,
    ]);
}

function ingredientOntologyV3IncrementalRecipeIngredientRows(
    PDO $db,
    array $operations
): array {
    $recipeIds = array_map('intval', array_keys($operations));
    if ($recipeIds) {
        $placeholders = implode(
            ',',
            array_fill(0, count($recipeIds), '?')
        );
        $stmt = $db->prepare("
            SELECT recipe_id, id
            FROM recipe_ingredients
            WHERE recipe_id IN ({$placeholders})
            ORDER BY recipe_id, id
        ");
        $stmt->execute($recipeIds);
        return array_map(
            static fn(array $row): array => [
                'recipe_id' => (int)$row['recipe_id'],
                'ingredient_id' => (int)$row['id'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }
    return [];
}

function ingredientOntologyV3IncrementalIdSetHashes(
    PDO $db,
    array $parent,
    array $operations,
    array $ingredientRows
): array {
    ksort($operations, SORT_NUMERIC);
    $operationRows = [];
    foreach ($operations as $recipeId => $operation) {
        $operationRows[] = [
            'recipe_id' => (int)$recipeId,
            'operation' => (string)$operation,
        ];
    }
    usort(
        $ingredientRows,
        static fn(array $left, array $right): int =>
            (int)$left['recipe_id'] <=> (int)$right['recipe_id']
                ?: (int)$left['ingredient_id']
                    <=> (int)$right['ingredient_id']
    );
    $operationHash = ingredientOntologyV3Hash($operationRows);
    return [
        'algorithm' => 'parent-delta-v2',
        'operation_rows_hash' => $operationHash,
        'catalog_id_set_hash' => ingredientOntologyV3Hash([
            'algorithm' => 'parent-delta-v2',
            'parent_catalog_id_set_hash' =>
                (string)$parent['catalog_id_set_hash'],
            'operation_rows_hash' => $operationHash,
        ]),
        'ingredient_id_set_hash' => ingredientOntologyV3Hash([
            'algorithm' => 'parent-delta-v2',
            'parent_ingredient_id_set_hash' =>
                (string)$parent['ingredient_id_set_hash'],
            'operation_rows_hash' => $operationHash,
            'ingredient_rows' => $ingredientRows,
        ]),
        'recipe_operation_count' => count($operationRows),
        'ingredient_row_count' => count($ingredientRows),
    ];
}

function ingredientOntologyV3IncrementalIdSetAudit(
    PDO $db,
    array $revision
): array {
    $parent = recipeScoreRevision(
        $db,
        (int)($revision['parent_score_revision_id'] ?? 0)
    );
    if ($parent === null || $parent['status'] !== 'ready') {
        return [
            'valid' => false,
            'reason' => 'incremental_parent_unavailable',
        ];
    }
    $operations = $db->prepare("
        SELECT recipe_id, operation
        FROM recipe_score_recipe_operations
        WHERE score_revision_id = ?
        ORDER BY recipe_id
    ");
    $operations->execute([(int)$revision['id']]);
    $operationMap = [];
    foreach ($operations->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $operationMap[(int)$row['recipe_id']] =
            (string)$row['operation'];
    }
    $ingredients = $db->prepare("
        SELECT recipe_id, recipe_ingredient_id
        FROM recipe_score_recipe_ingredients
        WHERE score_revision_id = ?
        ORDER BY recipe_id, recipe_ingredient_id
    ");
    $ingredients->execute([(int)$revision['id']]);
    $ingredientRows = array_map(
        static fn(array $row): array => [
            'recipe_id' => (int)$row['recipe_id'],
            'ingredient_id' => (int)$row['recipe_ingredient_id'],
        ],
        $ingredients->fetchAll(PDO::FETCH_ASSOC)
    );
    $current = ingredientOntologyV3IncrementalIdSetHashes(
        $db,
        $parent,
        $operationMap,
        $ingredientRows
    );
    $parentAudit = ingredientOntologyV3RetainedIdSetAudit(
        $db,
        $parent
    );
    $matches = [
        'catalog_id_set_hash' => is_string(
            $revision['catalog_id_set_hash'] ?? null
        ) && hash_equals(
            (string)$revision['catalog_id_set_hash'],
            (string)$current['catalog_id_set_hash']
        ),
        'ingredient_id_set_hash' => is_string(
            $revision['ingredient_id_set_hash'] ?? null
        ) && hash_equals(
            (string)$revision['ingredient_id_set_hash'],
            (string)$current['ingredient_id_set_hash']
        ),
    ];
    return [
        'valid' => !in_array(false, $matches, true)
            && !empty($parentAudit['valid']),
        'algorithm' => 'parent-delta-v2',
        'current_hashes' => $current,
        'hash_matches' => $matches,
        'parent' => [
            'revision_id' => (int)$parent['id'],
            'valid' => !empty($parentAudit['valid']),
        ],
    ];
}

function ingredientOntologyV3RetainedIdSetAudit(
    PDO $db,
    array $revision
): array {
    if (recipeScoreRevisionIsSparseDelta($revision)) {
        return ingredientOntologyV3IncrementalIdSetAudit(
            $db,
            $revision
        );
    }
    $catalogHash = ingredientOntologyV3OrderedIdSetHash(
        $db,
        "SELECT recipe_id
         FROM recipe_inventory_scores
         WHERE score_revision_id = ?
         ORDER BY recipe_id",
        [(int)$revision['id']]
    );
    $ingredientHash = ingredientOntologyV3OrderedIdSetHash(
        $db,
        "SELECT recipe_ingredient_id
         FROM ingredient_ontology_shadow_matches
         WHERE score_revision_id = ?
         ORDER BY recipe_ingredient_id",
        [(int)$revision['id']]
    );
    $matches = [
        'catalog_id_set_hash' => is_string(
            $revision['catalog_id_set_hash'] ?? null
        ) && hash_equals(
            (string)$revision['catalog_id_set_hash'],
            $catalogHash
        ),
        'ingredient_id_set_hash' => is_string(
            $revision['ingredient_id_set_hash'] ?? null
        ) && hash_equals(
            (string)$revision['ingredient_id_set_hash'],
            $ingredientHash
        ),
    ];
    return [
        'valid' => !in_array(false, $matches, true)
            && (int)$revision['recipe_count']
                === (int)$db->query("
                    SELECT COUNT(*)
                    FROM recipe_inventory_scores
                    WHERE score_revision_id = "
                    . (int)$revision['id']
                )->fetchColumn(),
        'current_hashes' => [
            'catalog_id_set_hash' => $catalogHash,
            'ingredient_id_set_hash' => $ingredientHash,
        ],
        'hash_matches' => $matches,
    ];
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

function ingredientOntologyV3IncrementalOldestPendingAgeMs(
    array $pending
): int {
    $oldest = PHP_INT_MAX;
    foreach ($pending as $row) {
        $timestamp = strtotime(
            (string)($row['created_at'] ?? '') . ' UTC'
        );
        if ($timestamp !== false && $timestamp > 0) {
            $oldest = min($oldest, $timestamp);
        }
    }
    if ($oldest === PHP_INT_MAX) {
        return PHP_INT_MAX;
    }
    return max(
        0,
        (int)round((microtime(true) - $oldest) * 1000)
    );
}

function ingredientOntologyV3IncrementalChainPolicy(
    mixed $parentReport
): array {
    $parentAlgorithm = is_array($parentReport)
        ? (string)(
            $parentReport['materialized_hash_algorithm'] ?? ''
        )
        : '';
    $parentUsesDelta = in_array(
        $parentAlgorithm,
        ['parent-delta-v1', 'parent-delta-v2'],
        true
    );
    $parentDepth = $parentUsesDelta
        ? (int)(
            $parentReport['incremental_chain_depth']
                ?? INGREDIENT_ONTOLOGY_V3_INCREMENTAL_FULL_HASH_INTERVAL
        )
        : 0;
    $depth = $parentDepth + 1;
    return [
        'depth' => $depth,
        'compact' => $parentAlgorithm === 'parent-delta-v1'
            && $depth
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
        (int)$parent['ontology_source_revision']
            !== (int)$state['ontology_source_revision']
    ) {
        $currentSourceHash = strlen(
            (string)$state['ontology_source_hash']
        ) === 64
            ? (string)$state['ontology_source_hash']
            : ingredientOntologyV3CorpusHash($db);
        if (
            !is_string($parent['ontology_source_hash'] ?? null)
            || !hash_equals(
                (string)$parent['ontology_source_hash'],
                $currentSourceHash
            )
        ) {
            $errors[] = 'ontology_source_changed';
        }
    }
    if (
        !hash_equals(
            (string)(
                $parent['ontology_source_lineage_hash'] ?? ''
            ),
            (string)(
                $state['ontology_source_lineage_hash'] ?? ''
            )
        )
    ) {
        $errors[] = 'ontology_source_lineage_changed';
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
        static fn(int $id): bool => $id !== 0
    )));
    $related = [];
    foreach ($inventoryEntityIds as $inventoryEntityId) {
        if (isset($context->entities[$inventoryEntityId])) {
            $related[$inventoryEntityId] = true;
        }
    }
    $nativeInventoryEntityIds = array_values(array_filter(
        $inventoryEntityIds,
        static fn(int $entityId): bool => $entityId > 0
    ));
    foreach (array_keys($context->entities) as $requiredEntityId) {
        $requiredEntityId = (int)$requiredEntityId;
        if ($requiredEntityId < 0) {
            continue;
        }
        foreach ($nativeInventoryEntityIds as $inventoryEntityId) {
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
    $useProjection = recipeScoreEffectiveProjectionReady(
        $db,
        $parentRevisionId
    );
    $projectionJoin = $useProjection ? "
        JOIN recipe_score_effective_sources source
          ON source.recipe_id =
             COALESCE(match.recipe_id, ingredient.recipe_id)
         AND source.score_revision_id = match.score_revision_id
    " : '';
    $revisionWhere = $useProjection
        ? ''
        : 'match.score_revision_id = ? AND';
    $matchParams = $useProjection
        ? $productIds
        : array_merge([$parentRevisionId], $productIds);
    $contributorsComplete = $useProjection
        ? !(bool)$db->query("
            SELECT 1
            FROM (
                SELECT DISTINCT score_revision_id
                FROM recipe_score_effective_sources
            ) source
            LEFT JOIN recipe_score_contributor_revisions complete
              ON complete.score_revision_id = source.score_revision_id
            WHERE complete.score_revision_id IS NULL
            LIMIT 1
        ")->fetchColumn()
        : recipeScoreContributorRevisionComplete(
            $db,
            $parentRevisionId
        );
    if (!$contributorsComplete) {
        $selected = $db->prepare("
            SELECT DISTINCT
                   COALESCE(match.recipe_id, ingredient.recipe_id)
            FROM ingredient_ontology_shadow_matches match
            LEFT JOIN recipe_ingredients ingredient
              ON ingredient.id = match.recipe_ingredient_id
            {$projectionJoin}
            WHERE {$revisionWhere}
                  COALESCE(match.recipe_id, ingredient.recipe_id)
                      IS NOT NULL
              AND match.inventory_product_id
                  IN ({$placeholders})
        ");
        $selected->execute($matchParams);
        foreach (
            $selected->fetchAll(PDO::FETCH_COLUMN) as $recipeId
        ) {
            $recipeIds[(int)$recipeId] = true;
        }
    }

    $contributorProjectionJoin = $useProjection ? "
        JOIN recipe_score_effective_sources source
          ON source.recipe_id =
             COALESCE(contributor.recipe_id, ingredient.recipe_id)
         AND source.score_revision_id =
             contributor.score_revision_id
    " : '';
    $contributorRevisionWhere = $useProjection
        ? ''
        : 'contributor.score_revision_id = ? AND';
    $contributorParams = $useProjection
        ? $productIds
        : array_merge([$parentRevisionId], $productIds);
    $normalizedContributors = $db->prepare("
        SELECT DISTINCT
               COALESCE(contributor.recipe_id, ingredient.recipe_id)
        FROM recipe_score_match_contributors contributor
        LEFT JOIN recipe_ingredients ingredient
          ON ingredient.id = contributor.recipe_ingredient_id
        {$contributorProjectionJoin}
        WHERE {$contributorRevisionWhere}
              COALESCE(contributor.recipe_id, ingredient.recipe_id)
                  IS NOT NULL
          AND
              contributor.product_id IN ({$placeholders})
    ");
    $normalizedContributors->execute($contributorParams);
    foreach (
        $normalizedContributors->fetchAll(PDO::FETCH_COLUMN)
        as $recipeId
    ) {
        $recipeIds[(int)$recipeId] = true;
    }

    if (!$contributorsComplete) {
        $contributors = $db->prepare("
            SELECT DISTINCT
                   COALESCE(match.recipe_id, ingredient.recipe_id)
            FROM ingredient_ontology_shadow_matches match
            LEFT JOIN recipe_ingredients ingredient
              ON ingredient.id = match.recipe_ingredient_id
            {$projectionJoin}
            JOIN json_each(
                CASE
                    WHEN json_valid(match.explanation_json)
                    THEN match.explanation_json
                    ELSE '{}'
                END,
                '$.inventory_aggregate.product_ids'
            ) contributor
            WHERE {$revisionWhere}
                  COALESCE(match.recipe_id, ingredient.recipe_id)
                      IS NOT NULL
              AND
                  contributor.type = 'integer'
              AND CAST(contributor.value AS INTEGER)
                  IN ({$placeholders})
        ");
        $contributors->execute($matchParams);
        foreach (
            $contributors->fetchAll(PDO::FETCH_COLUMN)
            as $recipeId
        ) {
            $recipeIds[(int)$recipeId] = true;
        }
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
        static fn(int $id): bool => $id !== 0
    )));
    foreach ($productIds as $productId) {
        $mapping = $inventory['by_product'][$productId] ?? null;
        if (
            is_array($mapping)
            && (string)($mapping['status'] ?? '') === 'accepted'
            && (int)($mapping['entity_id'] ?? 0) !== 0
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
    $nativeEntityIds = array_values(array_filter(
        $relatedEntityIds,
        static fn(int $entityId): bool => $entityId > 0
    ));
    if ($nativeEntityIds) {
        $entityPlaceholders = implode(
            ',',
            array_fill(0, count($nativeEntityIds), '?')
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
            $nativeEntityIds
        ));
        foreach ($current->fetchAll(PDO::FETCH_COLUMN) as $recipeId) {
            $recipeIds[(int)$recipeId] = true;
        }
        $annex = $db->prepare("
            SELECT DISTINCT ingredient.recipe_id
            FROM ingredient_ontology_recipe_identity_annex annex
            JOIN recipe_ingredients ingredient
              ON ingredient.id = annex.recipe_ingredient_id
            JOIN recipe_catalog recipe
              ON recipe.id = ingredient.recipe_id
             AND recipe.deleted_at IS NULL
            WHERE annex.ontology_version_id = ?
              AND annex.status = 'accepted'
              AND annex.entity_id IN ({$entityPlaceholders})
        ");
        $annex->execute(array_merge(
            [$versionId],
            $nativeEntityIds
        ));
        foreach ($annex->fetchAll(PDO::FETCH_COLUMN) as $recipeId) {
            $recipeIds[(int)$recipeId] = true;
        }
    }
    $extensionEntityIds = array_values(array_map(
        static fn(int $entityId): int => -$entityId,
        array_filter(
            $relatedEntityIds,
            static fn(int $entityId): bool => $entityId < 0
        )
    ));
    if ($extensionEntityIds) {
        $extensionPlaceholders = implode(
            ',',
            array_fill(0, count($extensionEntityIds), '?')
        );
        $extensionAnnex = $db->prepare("
            SELECT DISTINCT ingredient.recipe_id
            FROM ingredient_ontology_recipe_identity_annex annex
            JOIN recipe_ingredients ingredient
              ON ingredient.id = annex.recipe_ingredient_id
            JOIN recipe_catalog recipe
              ON recipe.id = ingredient.recipe_id
             AND recipe.deleted_at IS NULL
            WHERE annex.ontology_version_id = ?
              AND annex.status = 'accepted'
              AND annex.extension_entity_id
                  IN ({$extensionPlaceholders})
        ");
        $extensionAnnex->execute(array_merge(
            [$versionId],
            $extensionEntityIds
        ));
        foreach (
            $extensionAnnex->fetchAll(PDO::FETCH_COLUMN)
            as $recipeId
        ) {
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
    string $ontologySourceHash,
    array $identityExtension,
    bool $servingOnly = false,
    ?int $coveredCatalogRevision = null,
    ?int $coveredOntologySourceRevision = null,
    ?string $scoreDate = null
): int {
    $insert = $db->prepare("
        INSERT INTO recipe_score_revisions (
            inventory_revision, catalog_revision,
            covered_catalog_revision, revision_kind,
            inventory_fingerprint, score_date, catalog_max_id,
            status, ontology_version_id, scoring_model,
            scoring_config_hash, parent_score_revision_id,
            catalog_fingerprint, ontology_schema_hash,
            ontology_prompt_hash, ontology_model_hash,
            ontology_corpus_hash, ontology_content_hash,
            ontology_portable_content_hash,
            ontology_review_manifest_hash,
            ontology_resolution_gold_hash, ontology_seal_hash,
            ontology_source_revision,
            covered_ontology_source_revision,
            ontology_source_hash,
            identity_extension_revision, identity_extension_hash,
            requirement_revision_id, requirement_model,
            parity_baseline_score_revision_id,
            catalog_id_set_hash, ingredient_id_set_hash,
            requirement_recipe_id_set_hash,
            requirement_id_set_hash
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, 'building', ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, NULL, NULL
        )
    ");
    $insert->execute([
        (int)$state['inventory_revision'],
        (int)$state['catalog_revision'],
        $coveredCatalogRevision
            ?? (
                $servingOnly
                    ? (int)$parent['covered_catalog_revision']
                    : (int)$state['catalog_revision']
            ),
        $servingOnly ? 'serving_delta' : 'maintenance_delta',
        $inventoryFingerprint,
        $scoreDate ?? (string)$parent['score_date'],
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
        $coveredOntologySourceRevision
            ?? (
                $servingOnly
                    ? (int)$parent[
                        'covered_ontology_source_revision'
                    ]
                    : (int)$state['ontology_source_revision']
            ),
        $ontologySourceHash,
        (int)$identityExtension['revision'],
        (string)$identityExtension['hash'],
        (string)$parent['catalog_id_set_hash'],
        (string)$parent['ingredient_id_set_hash'],
    ]);
    return (int)$db->lastInsertId();
}

function ingredientOntologyV3IncrementalRecordAffectedRecipes(
    PDO $db,
    int $revisionId,
    array $recipeIds,
    array $operations = [],
    array $ingredientRows = []
): void {
    if (!$recipeIds) {
        return;
    }
    recipeScoreWithWriteRetry(static function () use (
        $db,
        $revisionId,
        $recipeIds,
        $operations,
        $ingredientRows
    ): void {
        $db->beginTransaction();
        try {
            foreach (array_chunk($recipeIds, 250) as $chunk) {
                $values = implode(
                    ',',
                    array_fill(0, count($chunk), '(?, ?)')
                );
                $operationValues = implode(
                    ',',
                    array_fill(0, count($chunk), '(?, ?, ?)')
                );
                $params = [];
                $operationParams = [];
                foreach ($chunk as $recipeId) {
                    $operation = (string)(
                        $operations[(int)$recipeId] ?? 'replace'
                    );
                    if (!in_array(
                        $operation,
                        ['replace', 'delete'],
                        true
                    )) {
                        throw new InvalidArgumentException(
                            'incremental recipe operation is invalid'
                        );
                    }
                    $params[] = $revisionId;
                    $params[] = (int)$recipeId;
                    $operationParams[] = $revisionId;
                    $operationParams[] = (int)$recipeId;
                    $operationParams[] = $operation;
                }
                $db->prepare("
                    INSERT INTO recipe_score_incremental_recipes (
                        score_revision_id, recipe_id
                    )
                    VALUES {$values}
                ")->execute($params);
                $db->prepare("
                    INSERT INTO recipe_score_recipe_operations (
                        score_revision_id, recipe_id, operation
                    )
                    VALUES {$operationValues}
                ")->execute($operationParams);
            }
            foreach (array_chunk($ingredientRows, 500) as $chunk) {
                if (!$chunk) {
                    continue;
                }
                $values = implode(
                    ',',
                    array_fill(0, count($chunk), '(?, ?, ?)')
                );
                $params = [];
                foreach ($chunk as $row) {
                    $params[] = $revisionId;
                    $params[] = (int)$row['recipe_id'];
                    $params[] = (int)$row['ingredient_id'];
                }
                $db->prepare("
                    INSERT INTO recipe_score_recipe_ingredients (
                        score_revision_id, recipe_id,
                        recipe_ingredient_id
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
    $operations = ingredientOntologyV3HashMaterializedRows(
        $db,
        "SELECT recipe_id, operation
         FROM recipe_score_recipe_operations
         WHERE score_revision_id = ?
         ORDER BY recipe_id",
        [$revisionId],
        static fn(array $row): array => [
            'recipe_id' => (int)$row['recipe_id'],
            'operation' => (string)$row['operation'],
        ]
    );
    $affectedIdSetHash = ingredientOntologyV3OrderedIdSetHash(
        $db,
        "SELECT recipe_id
         FROM recipe_score_recipe_operations
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
         LEFT JOIN recipe_ingredients ingredient
           ON ingredient.id = match.recipe_ingredient_id
         JOIN recipe_score_incremental_recipes affected
           ON affected.score_revision_id = match.score_revision_id
          AND affected.recipe_id =
              COALESCE(match.recipe_id, ingredient.recipe_id)
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
        'algorithm' => 'parent-delta-v2',
        'parent_score_rows_hash' =>
            (string)$parent['score_rows_hash'],
        'affected_recipe_id_set_hash' => $affectedIdSetHash,
        'operation_rows_hash' => $operations['hash'],
        'changed_score_rows_hash' => $scores['hash'],
        'changed_score_row_count' => $scores['count'],
    ]);
    $matchRowsHash = ingredientOntologyV3Hash([
        'algorithm' => 'parent-delta-v2',
        'parent_match_rows_hash' =>
            (string)$parent['match_rows_hash'],
        'affected_recipe_id_set_hash' => $affectedIdSetHash,
        'operation_rows_hash' => $operations['hash'],
        'changed_match_rows_hash' => $matches['hash'],
        'changed_match_row_count' => $matches['count'],
    ]);
    return [
        'algorithm' => 'parent-delta-v2',
        'affected_recipe_id_set_hash' => $affectedIdSetHash,
        'affected_recipe_count' => $operations['count'],
        'operation_rows_hash' => $operations['hash'],
        'operation_row_count' => $operations['count'],
        'changed_score_rows_hash' => $scores['hash'],
        'changed_score_row_count' => $scores['count'],
        'changed_match_rows_hash' => $matches['hash'],
        'changed_match_row_count' => $matches['count'],
        'score_rows_hash' => $scoreRowsHash,
        'match_rows_hash' => $matchRowsHash,
        'materialization_hash' => ingredientOntologyV3Hash([
            'algorithm' => 'parent-delta-v2',
            'parent_revision_id' => (int)$parent['id'],
            'score_rows_hash' => $scoreRowsHash,
            'match_rows_hash' => $matchRowsHash,
            'affected_recipe_id_set_hash' => $affectedIdSetHash,
            'operation_rows_hash' => $operations['hash'],
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
    $algorithm = is_array($report)
        ? (string)($report['materialized_hash_algorithm'] ?? '')
        : '';
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
    $parentAudit = ingredientOntologyV3MaterializedValueAudit(
        $db,
        $parent
    );
    if ($algorithm === 'parent-delta-v2') {
        $operations = $db->prepare("
            SELECT operation, COUNT(*) AS operation_count
            FROM recipe_score_recipe_operations
            WHERE score_revision_id = ?
            GROUP BY operation
        ");
        $operations->execute([(int)$revision['id']]);
        $operationCounts = ['replace' => 0, 'delete' => 0];
        foreach ($operations->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $operationCounts[(string)$row['operation']] =
                (int)$row['operation_count'];
        }
        $physicalScoreCount = (int)$scoreCount->fetchColumn();
        $physicalMatchCount = (int)$matchCount->fetchColumn();
        $expectedChangedMatchCount = (int)(
            $report['materialized_values']['current'][
                'changed_match_row_count'
            ] ?? -1
        );
        $activeProjectionValid = true;
        $state = recipeScoreState($db);
        if (
            (int)($state['active_score_revision_id'] ?? 0)
                === (int)$revision['id']
        ) {
            $activeProjectionValid =
                recipeScoreEffectiveProjectionReady(
                    $db,
                    (int)$revision['id'],
                    $state
                )
                && (int)$db->query("
                    SELECT COUNT(*)
                    FROM recipe_score_effective_sources
                ")->fetchColumn() === (int)$revision['recipe_count'];
        }
        return [
            'valid' => !in_array(false, $hashMatches, true)
                && $physicalScoreCount
                    === $operationCounts['replace']
                && $expectedChangedMatchCount >= 0
                && $physicalMatchCount
                    === $expectedChangedMatchCount
                && $expectedMatchCount >= 0
                && !empty($parentAudit['valid'])
                && $activeProjectionValid,
            'algorithm' => 'parent-delta-v2',
            'current' => $current,
            'hash_matches' => $hashMatches,
            'operation_counts' => $operationCounts,
            'physical_score_count' => $physicalScoreCount,
            'physical_match_count' => $physicalMatchCount,
            'effective_match_count' => $expectedMatchCount,
            'projection_valid' => $activeProjectionValid,
            'parent' => [
                'revision_id' => $parentId,
                'valid' => !empty($parentAudit['valid']),
            ],
        ];
    }
    $copyMismatches =
        ingredientOntologyV3IncrementalCopyMismatchCount(
            $db,
            (int)$revision['id'],
            $parentId
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

function ingredientOntologyV3IncrementalEffectiveMatchCount(
    PDO $db,
    array $recipeIds,
    ?int $affectedRevisionId = null
): int {
    $recipeIds = array_values(array_unique(array_filter(
        array_map('intval', $recipeIds),
        static fn(int $id): bool => $id > 0
    )));
    if (!$recipeIds) {
        return 0;
    }
    if ($affectedRevisionId !== null && $affectedRevisionId > 0) {
        $count = $db->prepare("
            SELECT COUNT(*)
            FROM ingredient_ontology_shadow_matches match
            JOIN recipe_score_effective_sources source
              ON source.recipe_id = match.recipe_id
             AND source.score_revision_id =
                 match.score_revision_id
            JOIN recipe_score_incremental_recipes affected
              ON affected.recipe_id = match.recipe_id
             AND affected.score_revision_id = ?
            WHERE match.recipe_id IS NOT NULL
        ");
        $count->execute([$affectedRevisionId]);
        $total = (int)$count->fetchColumn();
        $legacy = $db->prepare("
            SELECT COUNT(*)
            FROM ingredient_ontology_shadow_matches match
            JOIN recipe_ingredients ingredient
              ON ingredient.id = match.recipe_ingredient_id
            JOIN recipe_score_effective_sources source
              ON source.recipe_id = ingredient.recipe_id
             AND source.score_revision_id =
                 match.score_revision_id
            JOIN recipe_score_incremental_recipes affected
              ON affected.recipe_id = ingredient.recipe_id
             AND affected.score_revision_id = ?
            WHERE match.recipe_id IS NULL
        ");
        $legacy->execute([$affectedRevisionId]);
        return $total + (int)$legacy->fetchColumn();
    }
    $placeholders = implode(
        ',',
        array_fill(0, count($recipeIds), '?')
    );
    $count = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_shadow_matches match
        JOIN recipe_score_effective_sources source
          ON source.recipe_id = match.recipe_id
         AND source.score_revision_id = match.score_revision_id
        WHERE match.recipe_id IN ({$placeholders})
    ");
    $count->execute($recipeIds);
    $total = (int)$count->fetchColumn();
    $legacyRequired = (bool)$db->query("
        SELECT 1
        FROM (
            SELECT DISTINCT score_revision_id
            FROM recipe_score_effective_sources
        ) source
        JOIN ingredient_ontology_shadow_matches match
          ON match.score_revision_id = source.score_revision_id
         AND match.recipe_id IS NULL
        LIMIT 1
    ")->fetchColumn();
    if (!$legacyRequired) {
        return $total;
    }
    $legacyPlaceholders = implode(
        ',',
        array_fill(
            0,
            count($recipeIds),
            'CAST(? AS INTEGER)'
        )
    );
    $legacy = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_shadow_matches match
        JOIN recipe_ingredients ingredient
          ON ingredient.id = match.recipe_ingredient_id
        JOIN recipe_score_effective_sources source
          ON source.recipe_id = ingredient.recipe_id
         AND source.score_revision_id = match.score_revision_id
        WHERE match.recipe_id IS NULL
          AND CAST(ingredient.recipe_id AS INTEGER)
              IN ({$legacyPlaceholders})
    ");
    $legacy->execute($recipeIds);
    return $total + (int)$legacy->fetchColumn();
}

function ingredientOntologyV3EffectiveProjectionMatchCount(
    PDO $db
): int {
    $count = (int)$db->query("
        SELECT COUNT(*)
        FROM ingredient_ontology_shadow_matches match
        JOIN recipe_score_effective_sources source
          ON source.recipe_id = match.recipe_id
         AND source.score_revision_id = match.score_revision_id
        WHERE match.recipe_id IS NOT NULL
    ")->fetchColumn();
    $legacy = (int)$db->query("
        SELECT COUNT(*)
        FROM ingredient_ontology_shadow_matches match
        JOIN recipe_ingredients ingredient
          ON ingredient.id = match.recipe_ingredient_id
        JOIN recipe_score_effective_sources source
          ON source.recipe_id = ingredient.recipe_id
         AND source.score_revision_id = match.score_revision_id
        WHERE match.recipe_id IS NULL
    ")->fetchColumn();
    return $count + $legacy;
}

function ingredientOntologyV3IncrementalRebuild(
    PDO $db,
    bool $force = false,
    int $batchSize = 1000,
    bool $requireServing = false
): array {
    $started = hrtime(true);
    $identityRetries =
        ingredientOntologyV3ProductReadinessRetryDue($db);
    $pendingProducts =
        ingredientOntologyV3IncrementalPendingProducts($db);
    $pendingRecipes =
        ingredientOntologyV3IncrementalPendingRecipes(
            $db,
            'serving'
        );
    $servingOnly = (bool)($pendingProducts || $pendingRecipes);
    if ($requireServing && !$servingOnly) {
        return [
            'rebuilt' => false,
            'reason' => 'serving_mode_lost',
        ];
    }
    if ($servingOnly) {
        $identityAdmission =
            ingredientOntologyV3IdentityAdmissionState($db);
    } else {
        $identityAdmission =
            ingredientOntologyV3IdentityAdmissionSync($db);
        $identityMigrationRemaining = max(
            (int)(
                $identityAdmission[
                    'resolver_migration'
                ]['remaining'] ?? 0
            ),
            (int)(
                $identityAdmission[
                    'recipe_resolver_migration'
                ]['remaining'] ?? 0
            )
        );
        if ($identityMigrationRemaining > 0) {
            return [
                'rebuilt' => false,
                'reason' => 'identity_migration_pending',
                'remaining' => $identityMigrationRemaining,
                'retry_after_ms' => 50,
            ];
        }
        $pendingProducts =
            ingredientOntologyV3IncrementalPendingProducts($db);
        $pendingRecipes =
            ingredientOntologyV3IncrementalPendingRecipes(
                $db,
                'serving'
            );
        $servingOnly = (bool)($pendingProducts || $pendingRecipes);
    }
    $pending = array_merge($pendingProducts, $pendingRecipes);
    if (!$pending) {
        recipeScoreReconcileWorkState($db);
        return [
            'rebuilt' => false,
            'reason' => 'no_pending_changes',
            'identity_retries' => $identityRetries,
        ];
    }
    $quietAgeMs = ingredientOntologyV3IncrementalPendingAgeMs(
        $pending
    );
    $oldestAgeMs =
        ingredientOntologyV3IncrementalOldestPendingAgeMs($pending);
    $coalesceMs = ingredientOntologyV3IncrementalCoalesceMilliseconds();
    $maximumCoalesceMs =
        ingredientOntologyV3IncrementalMaximumCoalesceMilliseconds();
    if (
        !$force
        && $quietAgeMs < $coalesceMs
        && $oldestAgeMs < $maximumCoalesceMs
    ) {
        return [
            'rebuilt' => false,
            'reason' => 'coalescing',
            'retry_after_ms' => max(
                1,
                min(
                    $coalesceMs - $quietAgeMs,
                    $maximumCoalesceMs - $oldestAgeMs
                )
            ),
        ];
    }
    $lock = recipeScoreAcquireLock($db);
    if ($lock === false) {
        return ['rebuilt' => false, 'reason' => 'locked'];
    }
    $revisionId = 0;
    $publicationCommitted = false;
    $affectedRecipeFingerprint = '';
    try {
        recipeScoreWithWriteRetry(
            static fn(): int => recipeScoreFailAbandonedBuilds($db)
        );
        $pendingProducts =
            ingredientOntologyV3IncrementalPendingProducts($db);
        $pendingRecipes =
            ingredientOntologyV3IncrementalPendingRecipes(
                $db,
                'serving'
            );
        $servingOnly = (bool)($pendingProducts || $pendingRecipes);
        if ($requireServing && !$servingOnly) {
            recipeScoreReconcileWorkState($db);
            return [
                'rebuilt' => false,
                'reason' => 'serving_mode_lost',
            ];
        }
        if (!$pendingProducts && !$pendingRecipes) {
            recipeScoreReconcileWorkState($db);
            return [
                'rebuilt' => false,
                'reason' => 'no_pending_changes',
            ];
        }
        $productOverflow =
            ingredientOntologyV3IncrementalPendingOverflow(
                $db,
                count($pendingProducts)
            );
        $recipeOverflow =
            ingredientOntologyV3IncrementalPendingRecipeOverflow(
                $db,
                count($pendingRecipes),
                'serving'
            );
        if ($productOverflow || $recipeOverflow) {
            $limit = ingredientOntologyV3IncrementalProductLimit();
            return [
                'rebuilt' => false,
                'reason' => 'full_rebuild_required',
                'errors' => [
                    $productOverflow
                        ? 'incremental pending product limit exceeded'
                        : 'incremental pending recipe limit exceeded',
                ],
                'pending_product_count_at_least' => $limit + 1,
            ];
        }

        $state = recipeScoreState($db);
        $staleOverlayId = (int)(
            $state['active_score_overlay_revision_id'] ?? 0
        );
        if ($staleOverlayId > 0) {
            $db->exec('BEGIN IMMEDIATE');
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
                $db->exec('COMMIT');
            } catch (Throwable $error) {
                try {
                    $db->exec('ROLLBACK');
                } catch (Throwable $ignored) {
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
        $parentReport = recipeScoreRevisionReport($parent);
        if (
            recipeScoreRevisionIsSparseDelta($parent)
            && (int)(
                $parentReport['incremental_chain_depth'] ?? 0
            ) >= 240
        ) {
            return [
                'rebuilt' => false,
                'reason' => 'compaction_required',
                'depth' => (int)(
                    $parentReport['incremental_chain_depth'] ?? 0
                ),
            ];
        }
        $productIds = array_map(
            static fn(array $row): int => (int)$row['product_id'],
            $pendingProducts
        );
        $recipeOperations = [];
        foreach ($pendingRecipes as $pendingRecipe) {
            $recipeOperations[
                (int)$pendingRecipe['recipe_id']
            ] = (string)$pendingRecipe['operation'];
        }
        $pendingRecipeIds = array_map(
            'intval',
            array_keys($recipeOperations)
        );
        $productIds = array_values(array_unique(array_merge(
            $productIds,
            $servingOnly
                ? []
                : ingredientOntologyV3IncrementalSourceProductIds(
                    $db,
                    $parent,
                    $state
                )
        )));
        sort($productIds, SORT_NUMERIC);
        if (
            count($productIds)
                > ingredientOntologyV3IncrementalProductLimit()
        ) {
            return [
                'rebuilt' => false,
                'reason' => 'full_rebuild_required',
                'errors' => [
                    'incremental scoped product limit exceeded',
                ],
                'pending_product_count_at_least' =>
                    ingredientOntologyV3IncrementalProductLimit() + 1,
            ];
        }
        $parentErrors =
            ingredientOntologyV3IncrementalScopedParentErrors(
                $db,
                $parent,
                $state,
                $productIds,
                $pendingRecipeIds,
                $servingOnly
        );
        if ($parentErrors) {
            return [
                'rebuilt' => false,
                'reason' => 'full_rebuild_required',
                'errors' => $parentErrors,
            ];
        }
        $preSnapshotSourceProductOnly =
            $servingOnly
                ? (bool)$productIds
                : ingredientOntologyV3IncrementalSourceDeltaIsProductOnly(
                    $db,
                    $parent,
                    $state,
                    $productIds
                );
        $preSnapshotSourceScope = (
            $preSnapshotSourceProductOnly
            && (
                (int)$parent['ontology_source_revision']
                    !== (int)$state['ontology_source_revision']
                || (string)(
                    $parentReport['ontology_source_scope'] ?? ''
                ) === 'product_identity_annex'
            )
        ) ? 'product_identity_annex' : '';
        $preSnapshotSourceRevision =
            (int)$state['ontology_source_revision'];
        $preSnapshotProductSemanticHash =
            $preSnapshotSourceScope === 'product_identity_annex'
                && !$servingOnly
                ? ingredientOntologyV3IdentityAnnexSemanticHash(
                    $db,
                    (int)$parent['ontology_version_id']
                )
                : '';
        recipeScoreEnsureEffectiveProjection($db, $parent);
        $contributorStarted = hrtime(true);
        $contributorState = recipeScoreEnsureEffectiveContributors(
            $db,
            (int)$parent['id']
        );
        $contributorMs =
            (hrtime(true) - $contributorStarted) / 1000000;

        $versionId = (int)$parent['ontology_version_id'];
        $identityEntityIds = [];
        $productAdmissions = [];
        if (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && is_callable(
                $GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_BEFORE_INCREMENTAL_SNAPSHOT'
                ] ?? null
            )
        ) {
            ($GLOBALS[
                'INGREDIENT_ONTOLOGY_V3_BEFORE_INCREMENTAL_SNAPSHOT'
            ])($db, (int)$parent['id']);
        }
        $snapshotStarted = hrtime(true);
        $snapshotTransactionStarted = false;
        if (!$servingOnly) {
            dbBeginImmediateWithRetry($db);
            $snapshotTransactionStarted = true;
        }
        try {
            $pendingProducts =
                ingredientOntologyV3IncrementalPendingProducts($db);
            $pendingRecipes =
                ingredientOntologyV3IncrementalPendingRecipes(
                    $db,
                    'serving'
                );
            $servingOnly = (bool)(
                $pendingProducts || $pendingRecipes
            );
            if ($requireServing && !$servingOnly) {
                recipeScoreReconcileWorkState($db);
                return [
                    'rebuilt' => false,
                    'reason' => 'serving_mode_lost',
                ];
            }
            if (
                ingredientOntologyV3IncrementalPendingOverflow(
                    $db,
                    count($pendingProducts)
                )
                || ingredientOntologyV3IncrementalPendingRecipeOverflow(
                    $db,
                    count($pendingRecipes),
                    'serving'
                )
            ) {
                throw new RuntimeException(
                    'incremental pending change limit exceeded'
                );
            }
            $productIds = array_map(
                static fn(array $row): int =>
                    (int)$row['product_id'],
                $pendingProducts
            );
            $recipeOperations = [];
            foreach ($pendingRecipes as $pendingRecipe) {
                $recipeOperations[
                    (int)$pendingRecipe['recipe_id']
                ] = (string)$pendingRecipe['operation'];
            }
            $pendingRecipeIds = array_map(
                'intval',
                array_keys($recipeOperations)
            );
            $state = recipeScoreState($db);
            $productIds = array_values(array_unique(array_merge(
                $productIds,
                $servingOnly
                    ? []
                    : ingredientOntologyV3IncrementalSourceProductIds(
                        $db,
                        $parent,
                        $state
                    )
            )));
            sort($productIds, SORT_NUMERIC);
            if (
                count($productIds)
                    > ingredientOntologyV3IncrementalProductLimit()
            ) {
                throw new RuntimeException(
                    'incremental scoped product limit exceeded'
                );
            }
            recipeScoreSetWorkState(
                $db,
                'preparing',
                null,
                (int)$parent['id'],
                0,
                0,
                count($productIds),
                count($pendingRecipeIds)
            );
            $snapshotParent = recipeScoreActiveRevision($db);
            if (
                $snapshotParent === null
                || (int)$snapshotParent['id'] !== (int)$parent['id']
            ) {
                throw new RuntimeException(
                    'incremental score parent changed before snapshot'
                );
            }
            $snapshotErrors =
                ingredientOntologyV3IncrementalScopedParentErrors(
                    $db,
                    $snapshotParent,
                    $state,
                    $productIds,
                    $pendingRecipeIds,
                    $servingOnly
                );
            if ($snapshotErrors) {
                throw new RuntimeException(
                    'incremental score snapshot is incompatible: '
                    . implode('; ', $snapshotErrors)
                );
            }
            foreach ($productIds as $productId) {
                $productExists = $db->prepare("
                    SELECT 1 FROM products WHERE id = ?
                ");
                $productExists->execute([$productId]);
                $productIsPresent =
                    $productExists->fetchColumn() !== false;
                $productExists->closeCursor();
                if (!$productIsPresent) {
                    continue;
                }
                $identity =
                    ingredientOntologyV3IdentityAdmissionPublishProduct(
                        $db,
                        $productId,
                        $versionId,
                        'incremental_score_snapshot',
                        false
                    );
                $productAdmissions[$productId] = $identity;
                foreach (
                    ['entity_id', 'previous_entity_id'] as $key
                ) {
                    if ((int)($identity[$key] ?? 0) !== 0) {
                        $identityEntityIds[] =
                            (int)$identity[$key];
                    }
                }
            }
            foreach (
                ingredientOntologyV3IdentityExtensionRecipeIdsForProducts(
                    $db,
                    $versionId,
                    $productIds
                ) as $recipeId
            ) {
                $recipeOperations[$recipeId] =
                    $recipeOperations[$recipeId] ?? 'replace';
            }
            ingredientOntologyV3ProductReadinessBeginScoring(
                $db,
                $productAdmissions
            );
            $replaceCandidates = array_map(
                'intval',
                array_keys(array_filter(
                    $recipeOperations,
                    static fn(string $operation): bool =>
                        $operation !== 'delete'
                ))
            );
            $activeRecipeIds = [];
            foreach (
                array_chunk($replaceCandidates, 500)
                as $recipeChunk
            ) {
                $activeRecipe = $db->prepare("
                    SELECT id
                    FROM recipe_catalog
                    WHERE deleted_at IS NULL
                      AND id IN ("
                        . implode(
                            ',',
                            array_fill(
                                0,
                                count($recipeChunk),
                                '?'
                            )
                        )
                        . ")
                ");
                $activeRecipe->execute($recipeChunk);
                foreach (
                    $activeRecipe->fetchAll(PDO::FETCH_COLUMN)
                    as $recipeId
                ) {
                    $activeRecipeIds[(int)$recipeId] = true;
                }
            }
            foreach ($replaceCandidates as $recipeId) {
                if (!isset($activeRecipeIds[$recipeId])) {
                    $recipeOperations[$recipeId] = 'delete';
                }
            }
            $annexRecipeIds = array_map(
                'intval',
                array_keys($activeRecipeIds)
            );
            sort($annexRecipeIds, SORT_NUMERIC);
            foreach (
                array_chunk($annexRecipeIds, 500)
                as $recipeChunk
            ) {
                $recipeAnnex =
                    ingredientOntologyV3RecipeAnnexRefreshBatch(
                        $db,
                        $recipeChunk,
                        $versionId
                    );
                foreach ($recipeChunk as $recipeId) {
                    if (empty(
                        $recipeAnnex['recipes'][$recipeId]['ready']
                    )) {
                        throw new RuntimeException(
                            "recipe mappings are pending: {$recipeId}"
                        );
                    }
                }
            }
            $identityExtension =
                ingredientOntologyV3IdentityExtensionSnapshot(
                    $db,
                    $versionId
                );
            $scoreDate = $servingOnly
                ? recipeScoreCurrentDate()
                : (string)$parent['score_date'];
            $inventory = ingredientOntologyV3Inventory(
                $db,
                $versionId,
                $scoreDate,
                (int)$identityExtension['revision']
            );
            $inventoryFingerprint =
                ingredientOntologyV3InventoryFingerprint(
                    $inventory,
                    $versionId
                );
            $ontologySourceLineageHash =
                (int)$parent['ontology_source_revision']
                    === (int)$state['ontology_source_revision']
                ? (string)(
                    $parent['ontology_source_lineage_hash'] ?? ''
                )
                : ingredientOntologyV3IncrementalScopedInputHash(
                    $db,
                    'source',
                    (string)(
                        $parent['ontology_source_lineage_hash']
                            ?: $parent['ontology_source_hash']
                    ),
                    (int)$parent['ontology_source_revision'],
                    (int)$state['ontology_source_revision'],
                    $productIds,
                    $pendingRecipeIds,
                    $servingOnly
                );
            $catalogLineageHash =
                (int)$parent['catalog_revision']
                    === (int)$state['catalog_revision']
                ? (string)($parent['catalog_lineage_hash'] ?? '')
                : ingredientOntologyV3IncrementalScopedInputHash(
                    $db,
                    'catalog',
                    (string)(
                        $parent['catalog_lineage_hash']
                            ?: $parent['catalog_fingerprint']
                    ),
                    (int)$parent['catalog_revision'],
                    (int)$state['catalog_revision'],
                    $productIds,
                    $pendingRecipeIds,
                    $servingOnly
                );
            $ontologySourceHash =
                (string)$parent['ontology_source_hash'];
            $parentSourceScope = (string)(
                $parentReport['ontology_source_scope'] ?? ''
            );
            $sourceDeltaProductOnly =
                $servingOnly
                    ? (bool)$productIds
                    : ingredientOntologyV3IncrementalSourceDeltaIsProductOnly(
                        $db,
                        $parent,
                        $state,
                        $productIds
                    );
            $ontologySourceScope = (
                $sourceDeltaProductOnly
                && (
                    (int)$parent['ontology_source_revision']
                        !== (int)$state['ontology_source_revision']
                    || $parentSourceScope
                        === 'product_identity_annex'
                )
            ) ? 'product_identity_annex' : '';
            $productIdentitySemanticHash =
                '';
            if ($ontologySourceScope === 'product_identity_annex') {
                if ($servingOnly) {
                    $semanticAdmissions = [];
                    foreach ($productAdmissions as $admission) {
                        $semanticAdmissions[] = [
                            'product_id' =>
                                (int)$admission['product_id'],
                            'owner_fingerprint' =>
                                (string)$admission[
                                    'owner_fingerprint'
                                ],
                            'evidence_hash' =>
                                (string)$admission['evidence_hash'],
                            'status' => (string)$admission['status'],
                            'entity_id' =>
                                (int)($admission['entity_id'] ?? 0),
                            'extension_entity_id' =>
                                (int)(
                                    $admission[
                                        'extension_entity_id'
                                    ] ?? 0
                                ),
                        ];
                    }
                    usort(
                        $semanticAdmissions,
                        static fn(array $left, array $right): int =>
                            $left['product_id'] <=> $right['product_id']
                    );
                    $preSnapshotProductSemanticHash =
                        ingredientOntologyV3Hash(
                            $semanticAdmissions
                        );
                } elseif (
                    $preSnapshotSourceScope
                        !== 'product_identity_annex'
                    || $preSnapshotSourceRevision
                        !== (int)$state['ontology_source_revision']
                    || strlen($preSnapshotProductSemanticHash) !== 64
                ) {
                    $preSnapshotProductSemanticHash =
                        ingredientOntologyV3IdentityAnnexSemanticHash(
                            $db,
                            (int)$parent['ontology_version_id']
                        );
                }
                if (strlen($preSnapshotProductSemanticHash) !== 64) {
                    throw new RuntimeException(
                        'product identity semantic hash is unavailable'
                    );
                }
                $productIdentitySemanticHash =
                    $preSnapshotProductSemanticHash;
            }
            $catalogFingerprint =
                (string)$parent['catalog_fingerprint'];
            $catalogMaxId = recipeScoreCatalogMaxId($db);
            $context = new IngredientOntologyV3MatcherContext(
                $db,
                $versionId,
                (int)$identityExtension['revision']
            );
            $inventoryAffectedRecipeIds =
                ingredientOntologyV3IncrementalAffectedRecipeIds(
                    $db,
                    $versionId,
                    (int)$parent['id'],
                    $productIds,
                    $inventory,
                    $context,
                    $identityEntityIds
                );
            $affectedRecipeIds = array_values(array_unique(array_merge(
                $inventoryAffectedRecipeIds,
                $pendingRecipeIds,
                array_map('intval', array_keys($recipeOperations))
            )));
            sort($affectedRecipeIds, SORT_NUMERIC);
            $operations = [];
            foreach ($affectedRecipeIds as $recipeId) {
                $operations[$recipeId] =
                    $recipeOperations[$recipeId] ?? 'replace';
            }
            $operationIngredientRows =
                ingredientOntologyV3IncrementalRecipeIngredientRows(
                    $db,
                    $operations
                );
            $idSetHashes =
                ingredientOntologyV3IncrementalIdSetHashes(
                    $db,
                    $parent,
                    $operations,
                    $operationIngredientRows
                );
            $affectedRecipeFingerprint =
                recipeScoreCatalogRecipeFingerprint(
                    $db,
                    $affectedRecipeIds
                );
            if ($snapshotTransactionStarted) {
                $db->exec('COMMIT');
            }
        } catch (Throwable $error) {
            if ($snapshotTransactionStarted) {
                try {
                    $db->exec('ROLLBACK');
                } catch (Throwable $ignored) {
                }
            }
            throw $error;
        }
        $snapshotMs = (hrtime(true) - $snapshotStarted) / 1000000;

        if (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && is_callable(
                $GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_AFTER_INCREMENTAL_SNAPSHOT'
                ] ?? null
            )
        ) {
            ($GLOBALS[
                'INGREDIENT_ONTOLOGY_V3_AFTER_INCREMENTAL_SNAPSHOT'
            ])(
                $db,
                (int)$parent['id'],
                (int)$state['inventory_revision'],
                $productIds,
                $affectedRecipeIds,
                $pendingRecipeIds
            );
        }

        $coveredCatalogRevision =
            ingredientOntologyV3IncrementalCoveredRevision(
                $db,
                'catalog',
                (int)$parent['covered_catalog_revision'],
                (int)$state['catalog_revision'],
                $servingOnly,
                $productIds,
                $pendingRecipeIds
            );
        $coveredOntologySourceRevision =
            ingredientOntologyV3IncrementalCoveredRevision(
                $db,
                'source',
                (int)$parent['covered_ontology_source_revision'],
                (int)$state['ontology_source_revision'],
                $servingOnly,
                $productIds,
                $pendingRecipeIds
            );
        $revisionId =
            ingredientOntologyV3IncrementalInsertRevision(
                $db,
                $parent,
                $state,
                $inventoryFingerprint,
                $ontologySourceHash,
                $identityExtension,
                $servingOnly,
                $coveredCatalogRevision,
                $coveredOntologySourceRevision,
                $scoreDate
            );
        $db->prepare("
            UPDATE recipe_score_revisions
            SET catalog_max_id = ?,
                catalog_fingerprint = ?,
                catalog_lineage_hash = ?,
                ontology_source_lineage_hash = ?,
                catalog_id_set_hash = ?,
                ingredient_id_set_hash = ?
            WHERE id = ? AND status = 'building'
        ")->execute([
            $catalogMaxId,
            $catalogFingerprint,
            $catalogLineageHash,
            $ontologySourceLineageHash,
            (string)$idSetHashes['catalog_id_set_hash'],
            (string)$idSetHashes['ingredient_id_set_hash'],
            $revisionId,
        ]);
        ingredientOntologyV3IncrementalRecordAffectedRecipes(
            $db,
            $revisionId,
            $affectedRecipeIds,
            $operations,
            $operationIngredientRows
        );
        $replaceRecipeIds = array_values(array_filter(
            $affectedRecipeIds,
            static fn(int $recipeId): bool =>
                ($operations[$recipeId] ?? 'replace') === 'replace'
        ));
        $prepareMs = (hrtime(true) - $started) / 1000000;

        $batchSize = max(1, min(1000, $batchSize));
        $candidateCache = [];
        $recomputed = 0;
        $scoreStarted = hrtime(true);
        foreach (
            array_chunk($replaceRecipeIds, $batchSize)
            as $recipeIds
        ) {
            $recipes = ingredientOntologyV3LoadRecipeBatch(
                $db,
                $versionId,
                $recipeIds,
                true,
                (int)$identityExtension['revision']
            );
            if (count($recipes) !== count($recipeIds)) {
                throw new RuntimeException(
                    'incremental score recipe batch is incomplete'
                );
            }
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
            $nextRecomputed = $recomputed + count($scores);
            recipeScoreWithWriteRetry(static function () use (
                $db,
                $revisionId,
                $scores,
                $matches,
                $parent,
                $replaceRecipeIds,
                $nextRecomputed,
                $productIds,
                $pendingRecipeIds
            ): void {
                $db->beginTransaction();
                try {
                    ingredientOntologyV3WriteScoreRows(
                        $db,
                        $revisionId,
                        $scores,
                        $matches
                    );
                    recipeScoreSetWorkState(
                        $db,
                        'scoring',
                        $revisionId,
                        (int)$parent['id'],
                        count($replaceRecipeIds),
                        $nextRecomputed,
                        count($productIds),
                        count($pendingRecipeIds)
                    );
                    $db->commit();
                } catch (Throwable $error) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $error;
                }
            });
            $recomputed = $nextRecomputed;
            if (
                defined('RECIPE_BACKEND_TEST_MODE')
                && RECIPE_BACKEND_TEST_MODE
                && is_callable(
                    $GLOBALS[
                        'INGREDIENT_ONTOLOGY_V3_AFTER_SCORE_BATCH'
                    ] ?? null
                )
            ) {
                ($GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_AFTER_SCORE_BATCH'
                ])(
                    $db,
                    $revisionId,
                    $recomputed,
                    count($replaceRecipeIds)
                );
            }
        }
        $scoreMs = (hrtime(true) - $scoreStarted) / 1000000;

        $scoreCountStmt = $db->prepare("
            SELECT COUNT(*)
            FROM recipe_inventory_scores
            WHERE score_revision_id = ?
        ");
        $scoreCountStmt->execute([$revisionId]);
        $changedScoreCount = (int)$scoreCountStmt->fetchColumn();
        $scoreCountStmt->closeCursor();
        $matchCountStmt = $db->prepare("
            SELECT COUNT(*)
            FROM ingredient_ontology_shadow_matches
            WHERE score_revision_id = ?
        ");
        $matchCountStmt->execute([$revisionId]);
        $changedMatchCount = (int)$matchCountStmt->fetchColumn();
        $matchCountStmt->closeCursor();
        if ($changedScoreCount !== count($replaceRecipeIds)) {
            throw new RuntimeException(
                'incremental score delta is incomplete'
            );
        }
        $changedContributorCounts =
            recipeScoreMarkContributorRevisionComplete(
                $db,
                $revisionId
            );
        if (
            (int)$changedContributorCounts['match_count']
                !== $changedMatchCount
        ) {
            throw new RuntimeException(
                'incremental score contributors are incomplete'
            );
        }

        if (
            !recipeScoreEffectiveProjectionReady(
                $db,
                (int)$parent['id']
            )
        ) {
            throw new RuntimeException(
                'incremental score parent projection changed'
            );
        }
        $oldAffectedMatchCount =
            ingredientOntologyV3IncrementalEffectiveMatchCount(
                $db,
                $affectedRecipeIds,
                $revisionId
            );
        $parentMatchCount =
            (int)($parentReport['ingredient_match_count'] ?? -1);
        if ($parentMatchCount < $oldAffectedMatchCount) {
            throw new RuntimeException(
                'incremental parent match count is invalid'
            );
        }
        $previouslyPresent = [];
        if ($affectedRecipeIds) {
            $placeholders = implode(
                ',',
                array_fill(0, count($affectedRecipeIds), '?')
            );
            $present = $db->prepare("
                SELECT recipe_id
                FROM recipe_score_effective_sources
                WHERE recipe_id IN ({$placeholders})
            ");
            $present->execute($affectedRecipeIds);
            $previouslyPresent = array_fill_keys(
                array_map(
                    'intval',
                    $present->fetchAll(PDO::FETCH_COLUMN)
                ),
                true
            );
            $present->closeCursor();
        }
        $addedRecipeCount = 0;
        $deletedRecipeCount = 0;
        foreach ($operations as $recipeId => $operation) {
            if (
                $operation === 'replace'
                && !isset($previouslyPresent[$recipeId])
            ) {
                $addedRecipeCount++;
            } elseif (
                $operation === 'delete'
                && isset($previouslyPresent[$recipeId])
            ) {
                $deletedRecipeCount++;
            }
        }
        $recipeCount = (int)$parent['recipe_count']
            + $addedRecipeCount
            - $deletedRecipeCount;
        $matchCount = $parentMatchCount
            - $oldAffectedMatchCount
            + $changedMatchCount;

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
            || (int)$valueHashes['changed_score_row_count']
                !== $changedScoreCount
            || (int)$valueHashes['changed_match_row_count']
                !== $changedMatchCount
        ) {
            throw new RuntimeException(
                'incremental score delta hashes are incomplete'
            );
        }
        $chainPolicy =
            ingredientOntologyV3IncrementalChainPolicy($parentReport);
        $report = [
            'version' =>
                INGREDIENT_ONTOLOGY_V3_INCREMENTAL_REPORT_VERSION,
            'shadow_only' => false,
            'incremental' => true,
            'overlay_ready' => false,
            'materialized_hash_algorithm' => 'parent-delta-v2',
            'materialized_id_set_algorithm' => 'parent-delta-v2',
            'incremental_chain_depth' =>
                (int)$chainPolicy['depth'],
            'compaction_recommended' =>
                (int)$chainPolicy['depth'] >= 64,
            'activated' => true,
            'ontology_version_id' => $versionId,
            'recipe_count' => $recipeCount,
            'ingredient_match_count' => $matchCount,
            'inventory_revision' =>
                (int)$state['inventory_revision'],
            'catalog_revision' => (int)$state['catalog_revision'],
            'covered_catalog_revision' =>
                $coveredCatalogRevision,
            'revision_kind' =>
                $servingOnly
                    ? 'serving_delta'
                    : 'maintenance_delta',
            'catalog_fingerprint' =>
                (string)$parent['catalog_fingerprint'],
            'catalog_lineage_hash' => $catalogLineageHash,
            'inventory_fingerprint' => $inventoryFingerprint,
            'score_date' => $scoreDate,
            'ontology_source_revision' =>
                (int)$state['ontology_source_revision'],
            'covered_ontology_source_revision' =>
                $coveredOntologySourceRevision,
            'ontology_source_hash' => $ontologySourceHash,
            'ontology_source_lineage_hash' =>
                $ontologySourceLineageHash,
            'ontology_source_scope' => $ontologySourceScope,
            'product_identity_semantic_hash' =>
                $productIdentitySemanticHash,
            'identity_extension_revision' =>
                (int)$identityExtension['revision'],
            'identity_extension_hash' =>
                (string)$identityExtension['hash'],
            'active_score_revision_id_before' =>
                (int)$parent['id'],
            'scoring_configuration' => array_merge(
                ingredientOntologyV3ScoringConfiguration(),
                ['hash' => ingredientOntologyV3ScoringConfigHash()]
            ),
            'identity_annex_overlay' => [
                'resolver_version' =>
                    ingredientOntologyV3ProductIdentityResolverVersion(),
                'review_manifest_hash' =>
                    ingredientOntologyV3IdentityAnnexReviewManifestHash(),
                'admission_revision' =>
                    (int)($identityAdmission['revision'] ?? 0),
                'product_ids' => $productIds,
                'affected_recipe_count' =>
                    count($affectedRecipeIds),
            ],
            'snapshot' => [
                'inventory_revision' =>
                    (int)$state['inventory_revision'],
                'pending_product_ids' => $productIds,
                'pending_recipe_ids' => $pendingRecipeIds,
                'old_affected_match_count' =>
                    $oldAffectedMatchCount,
                'affected_recipe_fingerprint' =>
                    $affectedRecipeFingerprint,
            ],
            'physical_rows' => [
                'score' => $changedScoreCount,
                'match' => $changedMatchCount,
                'operations' => count($affectedRecipeIds),
            ],
            'contributors' => $contributorState,
            'materialized_id_sets' => [
                'valid' => true,
                'copied_from_revision_id' =>
                    (int)$state['catalog_revision']
                        === (int)$parent['catalog_revision']
                        ? (int)$parent['id']
                        : null,
                'current_hashes' => [
                    'catalog_id_set_hash' =>
                        (string)$idSetHashes['catalog_id_set_hash'],
                    'ingredient_id_set_hash' =>
                        (string)$idSetHashes['ingredient_id_set_hash'],
                    'requirement_recipe_id_set_hash' => null,
                    'requirement_id_set_hash' => null,
                ],
            ],
            'materialized_values' => [
                'valid' => true,
                'current' => $valueHashes,
            ],
            'timing_ms' => [
                'snapshot' => round($snapshotMs, 3),
                'contributors' => round($contributorMs, 3),
                'score' => round($scoreMs, 3),
                'hash' => round($hashMs, 3),
                'copy' => 0.0,
            ],
        ];

        $publishStarted = hrtime(true);
        $db->exec('BEGIN IMMEDIATE');
        $guardWasEnabled =
            ingredientOntologyV3PublicationGuardEnabled($db);
        ingredientOntologyV3SetPublicationGuard($db, true);
        try {
            recipeScoreSetWorkState(
                $db,
                'publishing',
                $revisionId,
                (int)$parent['id'],
                count($replaceRecipeIds),
                count($replaceRecipeIds),
                count($productIds),
                count($pendingRecipeIds)
            );
            $lockedState = recipeScoreState($db);
            if (
                (int)$lockedState['active_score_revision_id']
                    !== (int)$parent['id']
                || (int)$lockedState['inventory_revision']
                    < (int)$state['inventory_revision']
                || (int)$lockedState['catalog_revision']
                    < (int)$state['catalog_revision']
                || (int)$lockedState['ontology_source_revision']
                    < (int)$state['ontology_source_revision']
                || !hash_equals(
                    (string)(
                        $lockedState[
                            'ontology_source_lineage_hash'
                        ] ?? ''
                    ),
                    (string)(
                        $parent[
                            'ontology_source_lineage_hash'
                        ] ?? ''
                    )
                )
                || (int)(
                    $lockedState[
                        'active_score_projection_revision_id'
                    ] ?? 0
                ) !== (int)$parent['id']
                || recipeScoreCurrentDate() !== $scoreDate
            ) {
                throw new RuntimeException(
                    'incremental score publication fence changed'
                );
            }
            if (
                $servingOnly
                && !hash_equals(
                    $affectedRecipeFingerprint,
                    recipeScoreCatalogRecipeFingerprint(
                        $db,
                        $affectedRecipeIds
                    )
                )
            ) {
                throw new RuntimeException(
                    'serving score recipe fingerprint changed'
                );
            }
            if ($servingOnly) {
                $admissionFence = $db->prepare("
                    SELECT owner_fingerprint, evidence_hash, status
                    FROM ingredient_ontology_identity_annex
                    WHERE product_id = ?
                ");
                foreach ($productAdmissions as $admission) {
                    $admissionFence->execute([
                        (int)$admission['product_id'],
                    ]);
                    $currentAdmission =
                        $admissionFence->fetch(PDO::FETCH_ASSOC);
                    $admissionFence->closeCursor();
                    if (
                        !is_array($currentAdmission)
                        || !hash_equals(
                            (string)$admission['owner_fingerprint'],
                            (string)$currentAdmission[
                                'owner_fingerprint'
                            ]
                        )
                        || !hash_equals(
                            (string)$admission['evidence_hash'],
                            (string)$currentAdmission['evidence_hash']
                        )
                        || (string)$admission['status']
                            !== (string)$currentAdmission['status']
                    ) {
                        throw new RuntimeException(
                            'serving score product identity changed'
                        );
                    }
                }
            }
            $ready = $db->prepare("
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
            ");
            $ready->execute([
                $recipeCount,
                (string)$valueHashes['score_rows_hash'],
                (string)$valueHashes['match_rows_hash'],
                (string)$valueHashes['materialization_hash'],
                ingredientOntologyV3Json($report),
                $revisionId,
            ]);
            if ($ready->rowCount() !== 1) {
                throw new RuntimeException(
                    'incremental score revision publication was lost'
                );
            }
            recipeScoreApplyDeltaProjection(
                $db,
                (int)$parent['id'],
                $revisionId
            );
            $activate = $db->prepare("
                UPDATE recipe_score_state
                SET active_score_revision_id = ?,
                    active_score_overlay_revision_id = NULL,
                    cursor_revision = cursor_revision + 1,
                    ontology_source_hash = ?,
                    ontology_source_lineage_hash = ?,
                    last_built_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = 1
                  AND active_score_revision_id = ?
                  AND active_score_projection_revision_id = ?
                  AND inventory_revision >= ?
                  AND catalog_revision >= ?
                  AND ontology_source_revision >= ?
            ");
            $activate->execute([
                $revisionId,
                $ontologySourceHash,
                $ontologySourceLineageHash,
                (int)$parent['id'],
                $revisionId,
                (int)$state['inventory_revision'],
                (int)$state['catalog_revision'],
                (int)$state['ontology_source_revision'],
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
            ingredientOntologyV3ProductReadinessMarkReady(
                $db,
                $productAdmissions,
                $revisionId,
                (int)$state['inventory_revision'],
                count($affectedRecipeIds)
            );
            if ($pendingRecipeIds) {
                recipeScoreClearPendingRecipes(
                    $db,
                    (int)$state['catalog_revision'],
                    (int)$state['ontology_source_revision'],
                    $pendingRecipeIds
                );
            }
            if (!$servingOnly) {
                $db->prepare("
                    DELETE FROM recipe_score_mutations
                    WHERE (
                        domain = 'catalog' AND revision <= ?
                    ) OR (
                        domain = 'source' AND revision <= ?
                    )
                ")->execute([
                    (int)$state['catalog_revision'],
                    (int)$state['ontology_source_revision'],
                ]);
            }
            recipeScoreSetWorkState(
                $db,
                'idle',
                $revisionId,
                (int)$parent['id'],
                count($replaceRecipeIds),
                count($replaceRecipeIds),
                0,
                0
            );
            if (
                defined('RECIPE_BACKEND_TEST_MODE')
                && RECIPE_BACKEND_TEST_MODE
                && is_callable(
                    $GLOBALS[
                        'INGREDIENT_ONTOLOGY_V3_BEFORE_PUBLICATION_COMMIT'
                    ] ?? null
                )
            ) {
                ($GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_BEFORE_PUBLICATION_COMMIT'
                ])($db, $revisionId, (int)$parent['id']);
            }
            $db->exec('COMMIT');
            $publicationCommitted = true;
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
        $publishMs = (hrtime(true) - $publishStarted) / 1000000;
        $visibleMs = (hrtime(true) - $started) / 1000000;
        $postPublicationWarnings = [];
        try {
            ingredientOntologyV3ProductReadinessRecordVisibleMs(
                $db,
                $revisionId,
                $visibleMs
            );
        } catch (Throwable $error) {
            $postPublicationWarnings[] =
                'readiness_visible_ms: ' . $error->getMessage();
        }

        if (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && is_callable(
                $GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_AFTER_OVERLAY_PUBLICATION'
                ] ?? null
            )
        ) {
            try {
                ($GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_AFTER_OVERLAY_PUBLICATION'
                ])(
                    $db,
                    $revisionId,
                    (int)$parent['id'],
                    $affectedRecipeIds
                );
            } catch (Throwable $error) {
                $postPublicationWarnings[] =
                    'after_publication_hook: '
                    . $error->getMessage();
            }
        }

        $currentInventoryRevision =
            (int)$state['inventory_revision'];
        $pendingProductCount = null;
        $pendingRecipeCount = null;
        try {
            $currentInventoryRevision =
                (int)recipeScoreState($db)['inventory_revision'];
            $pendingProductCount = (int)$db->query("
                SELECT COUNT(*)
                FROM recipe_score_pending_products
            ")->fetchColumn();
            $pendingRecipeCount = (int)$db->query("
                SELECT COUNT(*)
                FROM recipe_score_pending_recipes
            ")->fetchColumn();
        } catch (Throwable $error) {
            $postPublicationWarnings[] =
                'post_publication_status: '
                . $error->getMessage();
        }
        return [
            'rebuilt' => true,
            'revision_id' => $revisionId,
            'parent_revision_id' => (int)$parent['id'],
            'ontology_version_id' => $versionId,
            'inventory_revision' =>
                (int)$state['inventory_revision'],
            'current_inventory_revision' =>
                $currentInventoryRevision,
            'product_ids' => $productIds,
            'recipe_ids' => $pendingRecipeIds,
            'recipe_operations' => $recipeOperations,
            'affected_recipe_count' => count($affectedRecipeIds),
            'recipe_count' => $recipeCount,
            'match_count' => $matchCount,
            'physical_score_rows' => $changedScoreCount,
            'physical_match_rows' => $changedMatchCount,
            'elapsed_ms' => round(
                (hrtime(true) - $started) / 1000000,
                3
            ),
            'visible_ms' => round($visibleMs, 3),
            'timing_ms' => $report['timing_ms'] + [
                'prepare' => round($prepareMs, 3),
                'publish' => round($publishMs, 3),
                'cleanup' => 0.0,
            ],
            'pending_product_count' => $pendingProductCount,
            'pending_recipe_count' => $pendingRecipeCount,
            'cleanup_deferred' => true,
            'serving_only' => $servingOnly,
            'cleanup_warning' => $postPublicationWarnings
                ? implode('; ', $postPublicationWarnings)
                : null,
        ];
    } catch (Throwable $error) {
        if ($publicationCommitted) {
            recipeScoreReadRevisionCacheClear();
            return [
                'rebuilt' => true,
                'revision_id' => $revisionId,
                'parent_revision_id' =>
                    isset($parent) ? (int)$parent['id'] : null,
                'inventory_revision' =>
                    isset($state)
                        ? (int)$state['inventory_revision']
                        : null,
                'product_ids' =>
                    isset($productIds) ? $productIds : [],
                'recipe_ids' =>
                    isset($pendingRecipeIds)
                        ? $pendingRecipeIds
                        : [],
                'affected_recipe_count' =>
                    isset($affectedRecipeIds)
                        ? count($affectedRecipeIds)
                        : null,
                'elapsed_ms' => round(
                    (hrtime(true) - $started) / 1000000,
                    3
                ),
                'cleanup_deferred' => true,
                'cleanup_warning' =>
                    'post_publication: '
                    . mb_substr(
                        $error->getMessage(),
                        0,
                        1000,
                        'UTF-8'
                    ),
            ];
        }
        $errorMessage = mb_substr(
            $error->getMessage(),
            0,
            1000,
            'UTF-8'
        );
        $failureAdmissions = isset($productAdmissions)
            ? $productAdmissions
            : [];
        $failureParentRevisionId = isset($parent)
            ? (int)$parent['id']
            : null;
        $failureTotalRecipeCount = isset($replaceRecipeIds)
            ? count($replaceRecipeIds)
            : 0;
        $failureProcessedRecipeCount = isset($recomputed)
            ? (int)$recomputed
            : 0;
        $failurePendingProductCount = isset($productIds)
            ? count($productIds)
            : 0;
        $failurePendingRecipeCount = isset($pendingRecipeIds)
            ? count($pendingRecipeIds)
            : 0;
        $cleanupError = null;
        try {
            databaseRollbackDanglingTransaction($db);
            recipeScoreWithWriteRetry(static function () use (
                $db,
                $revisionId,
                $failureAdmissions,
                $failureParentRevisionId,
                $failureTotalRecipeCount,
                $failureProcessedRecipeCount,
                $failurePendingProductCount,
                $failurePendingRecipeCount,
                $errorMessage
            ): void {
                $db->exec('BEGIN IMMEDIATE');
                try {
                    ingredientOntologyV3ProductReadinessScoreFailed(
                        $db,
                        $failureAdmissions,
                        $errorMessage
                    );
                    recipeScoreSetWorkState(
                        $db,
                        'failed',
                        $revisionId ?: null,
                        $failureParentRevisionId,
                        $failureTotalRecipeCount,
                        $failureProcessedRecipeCount,
                        $failurePendingProductCount,
                        $failurePendingRecipeCount,
                        $errorMessage
                    );
                    if ($revisionId > 0) {
                        $db->prepare("
                            UPDATE recipe_score_state
                            SET active_score_overlay_revision_id = NULL,
                                cursor_revision =
                                    cursor_revision + 1,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = 1
                              AND active_score_overlay_revision_id = ?
                        ")->execute([$revisionId]);
                        $db->prepare("
                            UPDATE recipe_score_revisions
                            SET status = 'failed',
                                last_error = ?,
                                completed_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                              AND status = 'building'
                        ")->execute([
                            $errorMessage,
                            $revisionId,
                        ]);
                    }
                    $db->exec('COMMIT');
                } catch (Throwable $cleanupFailure) {
                    try {
                        $db->exec('ROLLBACK');
                    } catch (Throwable $ignored) {
                    }
                    throw $cleanupFailure;
                }
            });
        } catch (Throwable $cleanupFailure) {
            $cleanupError = mb_substr(
                $cleanupFailure->getMessage(),
                0,
                1000,
                'UTF-8'
            );
        }
        return [
            'rebuilt' => false,
            'reason' => databaseIsLockError($error)
                ? 'locked'
                : 'failed',
            'error' => $errorMessage,
            'cleanup_error' => $cleanupError,
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

function ingredientOntologyV3CompactActiveScores(
    PDO $db,
    bool $force = false
): array {
    $started = hrtime(true);
    $lock = recipeScoreAcquireLock($db);
    if ($lock === false) {
        return ['compacted' => false, 'reason' => 'locked'];
    }
    $revisionId = 0;
    try {
        $state = recipeScoreState($db);
        $parent = recipeScoreActiveRevision($db);
        if ($parent === null || !recipeScoreRevisionIsSparseDelta($parent)) {
            recipeScoreReconcileWorkState($db);
            return [
                'compacted' => false,
                'reason' => 'active_revision_is_full',
            ];
        }
        $parentReport = recipeScoreRevisionReport($parent);
        $depth = (int)(
            $parentReport['incremental_chain_depth'] ?? 0
        );
        if (!$force && $depth < 64) {
            return [
                'compacted' => false,
                'reason' => 'not_due',
                'depth' => $depth,
            ];
        }
        if (
            (int)$db->query("
                SELECT COUNT(*) FROM recipe_score_pending_recipes
            ")->fetchColumn() > 0
            || (int)$state['catalog_revision']
                !== (int)$parent['catalog_revision']
            || (int)$state['ontology_source_revision']
                !== (int)$parent['ontology_source_revision']
        ) {
            return [
                'compacted' => false,
                'reason' => 'pending_catalog_or_source_changes',
            ];
        }
        recipeScoreEnsureEffectiveProjection($db, $parent);
        $effectiveMatchCount =
            ingredientOntologyV3EffectiveProjectionMatchCount($db);
        recipeScoreSetWorkState(
            $db,
            'compacting',
            null,
            (int)$parent['id'],
            (int)$parent['recipe_count'],
            0,
            0,
            0
        );

        $versionId = (int)$parent['ontology_version_id'];
        $inventoryFingerprint =
            (string)$parent['inventory_fingerprint'];
        $catalogFingerprint = recipeScoreCatalogFingerprint($db);
        $sourceLineageHash = (string)(
            $parent['ontology_source_lineage_hash'] ?? ''
        );
        $sourceHash = $sourceLineageHash !== ''
            ? (string)$parent['ontology_source_hash']
            : ingredientOntologyV3CorpusHash($db);
        $idSetHashes =
            ingredientOntologyV3MaterializedIdSetHashes(
                $db,
                (int)$parent['id'],
                null
            );
        $insert = $db->prepare("
            INSERT INTO recipe_score_revisions (
                inventory_revision, catalog_revision,
                inventory_fingerprint, score_date, catalog_max_id,
                status, recipe_count, ontology_version_id,
                scoring_model, scoring_config_hash,
                parent_score_revision_id, catalog_fingerprint,
                ontology_schema_hash, ontology_prompt_hash,
                ontology_model_hash, ontology_corpus_hash,
                ontology_content_hash,
                ontology_portable_content_hash,
                ontology_review_manifest_hash,
                ontology_resolution_gold_hash, ontology_seal_hash,
                ontology_source_revision, ontology_source_hash,
                ontology_source_lineage_hash,
                identity_extension_revision, identity_extension_hash,
                catalog_id_set_hash, ingredient_id_set_hash
            )
            VALUES (
                ?, ?, ?, ?, ?, 'building', ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");
        $insert->execute([
            (int)$parent['inventory_revision'],
            (int)$parent['catalog_revision'],
            $inventoryFingerprint,
            (string)$parent['score_date'],
            recipeScoreCatalogMaxId($db),
            (int)$parent['recipe_count'],
            $versionId,
            (string)$parent['scoring_model'],
            (string)$parent['scoring_config_hash'],
            (int)$parent['id'],
            $catalogFingerprint,
            (string)$parent['ontology_schema_hash'],
            (string)$parent['ontology_prompt_hash'],
            (string)$parent['ontology_model_hash'],
            (string)$parent['ontology_corpus_hash'],
            (string)$parent['ontology_content_hash'],
            (string)$parent['ontology_portable_content_hash'],
            (string)$parent['ontology_review_manifest_hash'],
            (string)$parent['ontology_resolution_gold_hash'],
            (string)$parent['ontology_seal_hash'],
            (int)$parent['ontology_source_revision'],
            $sourceHash,
            $sourceLineageHash,
            (int)($parent['identity_extension_revision'] ?? 0),
            (string)(
                $parent['identity_extension_hash']
                    ?? ingredientOntologyV3IdentityExtensionZeroHash()
            ),
            (string)$idSetHashes['catalog_id_set_hash'],
            (string)$idSetHashes['ingredient_id_set_hash'],
        ]);
        $revisionId = (int)$db->lastInsertId();

        $copyStarted = hrtime(true);
        $db->beginTransaction();
        try {
            $db->prepare("
                INSERT INTO recipe_inventory_scores (
                    score_revision_id, recipe_id, coverage,
                    directness, expiry_score, source_user_score,
                    availability_score, required_count,
                    matched_required_count, missing_required_count,
                    uncertain_required_count, cookable,
                    soonest_expiry_days, created_at, updated_at
                )
                SELECT ?, score.recipe_id, score.coverage,
                       score.directness, score.expiry_score,
                       score.source_user_score,
                       score.availability_score,
                       score.required_count,
                       score.matched_required_count,
                       score.missing_required_count,
                       score.uncertain_required_count,
                       score.cookable, score.soonest_expiry_days,
                       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM recipe_score_effective_sources source
                JOIN recipe_inventory_scores score
                  ON score.score_revision_id = source.score_revision_id
                 AND score.recipe_id = source.recipe_id
                ORDER BY score.recipe_id
            ")->execute([$revisionId]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        $db->beginTransaction();
        try {
            $db->prepare("
                INSERT INTO ingredient_ontology_shadow_matches (
                    score_revision_id, recipe_ingredient_id,
                    recipe_id,
                    recipe_mapping_id, inventory_product_id,
                    inventory_mapping_id, outcome,
                    satisfies_required, confidence, relationship,
                    explanation_json, created_at
                )
                SELECT ?, match.recipe_ingredient_id,
                       COALESCE(
                           match.recipe_id,
                           ingredient.recipe_id
                       ),
                       match.recipe_mapping_id,
                       match.inventory_product_id,
                       match.inventory_mapping_id,
                       match.outcome, match.satisfies_required,
                       match.confidence, match.relationship,
                       match.explanation_json, CURRENT_TIMESTAMP
                FROM ingredient_ontology_shadow_matches match
                LEFT JOIN recipe_ingredients ingredient
                  ON ingredient.id = match.recipe_ingredient_id
                JOIN recipe_score_effective_sources source
                  ON source.recipe_id =
                     COALESCE(match.recipe_id, ingredient.recipe_id)
                 AND source.score_revision_id =
                     match.score_revision_id
                ORDER BY match.recipe_ingredient_id
            ")->execute([$revisionId]);
            $db->prepare("
                INSERT INTO recipe_score_match_contributors (
                    score_revision_id, recipe_ingredient_id,
                    recipe_id, product_id, created_at
                )
                SELECT ?, contributor.recipe_ingredient_id,
                       COALESCE(
                           contributor.recipe_id,
                           ingredient.recipe_id
                       ),
                       contributor.product_id, CURRENT_TIMESTAMP
                FROM recipe_score_match_contributors contributor
                LEFT JOIN recipe_ingredients ingredient
                  ON ingredient.id =
                     contributor.recipe_ingredient_id
                JOIN recipe_score_effective_sources source
                  ON source.recipe_id = COALESCE(
                      contributor.recipe_id,
                      ingredient.recipe_id
                  )
                 AND source.score_revision_id =
                     contributor.score_revision_id
                ORDER BY contributor.recipe_ingredient_id,
                         contributor.product_id
            ")->execute([$revisionId]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        $copyMs = (hrtime(true) - $copyStarted) / 1000000;
        recipeScoreSetWorkState(
            $db,
            'compacting',
            $revisionId,
            (int)$parent['id'],
            (int)$parent['recipe_count'],
            (int)$parent['recipe_count'],
            0,
            0
        );

        $scoreCount = $db->prepare("
            SELECT COUNT(*) FROM recipe_inventory_scores
            WHERE score_revision_id = ?
        ");
        $scoreCount->execute([$revisionId]);
        $matchCount = $db->prepare("
            SELECT COUNT(*)
            FROM ingredient_ontology_shadow_matches
            WHERE score_revision_id = ?
        ");
        $matchCount->execute([$revisionId]);
        $physicalScoreCount = (int)$scoreCount->fetchColumn();
        $physicalMatchCount = (int)$matchCount->fetchColumn();
        if (
            $physicalScoreCount !== (int)$parent['recipe_count']
            || $physicalMatchCount !== $effectiveMatchCount
        ) {
            throw new RuntimeException(
                'score compaction materialization is incomplete'
            );
        }
        recipeScoreMarkContributorRevisionComplete($db, $revisionId);
        $valueHashes = ingredientOntologyV3MaterializedValueHashes(
            $db,
            $revisionId,
            null
        );
        $report = $parentReport;
        $report['version'] =
            INGREDIENT_ONTOLOGY_V3_INCREMENTAL_REPORT_VERSION;
        $report['incremental'] = false;
        $report['overlay_ready'] = false;
        $report['materialized_hash_algorithm'] = 'full-v1';
        unset($report['materialized_id_set_algorithm']);
        unset($report['catalog_lineage_hash']);
        if ($sourceLineageHash === '') {
            unset($report['ontology_source_lineage_hash']);
        } else {
            $report['ontology_source_lineage_hash'] =
                $sourceLineageHash;
        }
        $report['incremental_chain_depth'] = 0;
        $report['ingredient_match_count'] = $physicalMatchCount;
        $report['compaction_recommended'] = false;
        $report['compaction'] = [
            'parent_revision_id' => (int)$parent['id'],
            'parent_depth' => $depth,
            'physical_score_rows' => $physicalScoreCount,
            'physical_match_rows' => $physicalMatchCount,
        ];
        $report['catalog_fingerprint'] = $catalogFingerprint;
        $report['ontology_source_hash'] = $sourceHash;
        $report['materialized_id_sets'] = [
            'valid' => true,
            'current_hashes' => $idSetHashes + [
                'requirement_recipe_id_set_hash' => null,
                'requirement_id_set_hash' => null,
            ],
        ];
        $report['materialized_values'] = [
            'valid' => true,
            'current' => $valueHashes,
        ];
        $report['timing_ms']['compaction_copy'] =
            round($copyMs, 3);

        $publishStarted = hrtime(true);
        $db->exec('BEGIN IMMEDIATE');
        $guardWasEnabled =
            ingredientOntologyV3PublicationGuardEnabled($db);
        ingredientOntologyV3SetPublicationGuard($db, true);
        try {
            $lockedState = recipeScoreState($db);
            if (
                (int)$lockedState['active_score_revision_id']
                    !== (int)$parent['id']
                || (int)$lockedState['inventory_revision']
                    < (int)$parent['inventory_revision']
                || (int)$lockedState['catalog_revision']
                    !== (int)$parent['catalog_revision']
                || (int)$lockedState['ontology_source_revision']
                    !== (int)$parent['ontology_source_revision']
            ) {
                throw new RuntimeException(
                    'score compaction publication fence changed'
                );
            }
            $ready = $db->prepare("
                UPDATE recipe_score_revisions
                SET status = 'ready',
                    score_rows_hash = ?,
                    match_rows_hash = ?,
                    materialization_hash = ?,
                    validation_report_json = ?,
                    completed_at = CURRENT_TIMESTAMP,
                    last_error = ''
                WHERE id = ? AND status = 'building'
            ");
            $ready->execute([
                (string)$valueHashes['score_rows_hash'],
                (string)$valueHashes['match_rows_hash'],
                (string)$valueHashes['materialization_hash'],
                ingredientOntologyV3Json($report),
                $revisionId,
            ]);
            if ($ready->rowCount() !== 1) {
                throw new RuntimeException(
                    'score compaction revision publication was lost'
                );
            }
            recipeScoreBuildEffectiveProjection($db, $revisionId);
            $pointer = $db->prepare("
                UPDATE recipe_score_state
                SET active_score_revision_id = ?,
                    active_score_overlay_revision_id = NULL,
                    ontology_source_hash = ?,
                    ontology_source_lineage_hash = ?,
                    cursor_revision = cursor_revision + 1,
                    last_built_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = 1
                  AND active_score_revision_id = ?
                  AND active_score_projection_revision_id = ?
                  AND inventory_revision >= ?
                  AND catalog_revision = ?
                  AND ontology_source_revision = ?
            ");
            $pointer->execute([
                $revisionId,
                $sourceHash,
                $sourceLineageHash,
                (int)$parent['id'],
                $revisionId,
                (int)$parent['inventory_revision'],
                (int)$parent['catalog_revision'],
                (int)$parent['ontology_source_revision'],
            ]);
            if ($pointer->rowCount() !== 1) {
                throw new RuntimeException(
                    'score compaction pointer CAS failed'
                );
            }
            recipeScoreSetWorkState(
                $db,
                'idle',
                $revisionId,
                (int)$parent['id'],
                $physicalScoreCount,
                $physicalScoreCount,
                0,
                0
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
        return [
            'compacted' => true,
            'revision_id' => $revisionId,
            'parent_revision_id' => (int)$parent['id'],
            'parent_depth' => $depth,
            'recipe_count' => $physicalScoreCount,
            'match_count' => $physicalMatchCount,
            'elapsed_ms' => round(
                (hrtime(true) - $started) / 1000000,
                3
            ),
            'timing_ms' => [
                'copy' => round($copyMs, 3),
                'publish' => round(
                    (hrtime(true) - $publishStarted) / 1000000,
                    3
                ),
            ],
        ];
    } catch (Throwable $error) {
        try {
            recipeScoreSetWorkState(
                $db,
                'failed',
                $revisionId ?: null,
                isset($parent) ? (int)$parent['id'] : null,
                isset($parent)
                    ? (int)$parent['recipe_count']
                    : 0,
                0,
                0,
                0,
                $error->getMessage()
            );
        } catch (Throwable $ignored) {
        }
        if ($revisionId > 0) {
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
            'compacted' => false,
            'reason' => 'failed',
            'error' => mb_substr(
                $error->getMessage(),
                0,
                1000,
                'UTF-8'
            ),
            'revision_id' => $revisionId ?: null,
        ];
    } finally {
        recipeScoreReleaseLock($lock);
    }
}
