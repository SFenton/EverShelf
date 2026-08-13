<?php

/*
 * Initial, intentionally uncalibrated ranking weights. Keep these centralized so
 * production feedback can tune them without changing matching behavior.
 */
const RECIPE_RANK_WEIGHT_COVERAGE = 0.65;
const RECIPE_RANK_WEIGHT_DIRECTNESS = 0.10;
const RECIPE_RANK_WEIGHT_EXPIRY = 0.15;
const RECIPE_RANK_WEIGHT_SOURCE_USER = 0.10;

function recipeCatalogSourceUserScore(array $recipe): float {
    $score = 0.0;
    if (!empty($recipe['favorite'])) {
        $score += 0.55;
    }
    if ($recipe['rating'] !== null) {
        $score += 0.25 * max(0.0, min(1.0, ((int)$recipe['rating']) / 5));
    }
    $score += match ((string)$recipe['primary_connector']) {
        'manual' => 0.20,
        'generated' => 0.15,
        'local' => 0.10,
        default => 0.05,
    };
    return min(1.0, $score);
}

function recipeCatalogExpiryUrgency(?int $daysRemaining): float {
    if ($daysRemaining === null) {
        return 0.0;
    }
    if ($daysRemaining <= 0) {
        return 1.0;
    }
    return max(0.0, min(1.0, exp(-$daysRemaining / 30)));
}

function recipeCatalogUnitDefinition(string $unit): ?array {
    $unit = recipeIngredientNormalizeName($unit);
    return match ($unit) {
        'g', 'gr', 'gram', 'grams', 'grammo', 'grammi' => ['dimension' => 'mass', 'unit' => 'g', 'factor' => 1.0],
        'kg', 'kilogram', 'kilograms', 'chilogrammo', 'chilogrammi' => ['dimension' => 'mass', 'unit' => 'g', 'factor' => 1000.0],
        'ml', 'milliliter', 'milliliters', 'millilitro', 'millilitri' => ['dimension' => 'volume', 'unit' => 'ml', 'factor' => 1.0],
        'cl', 'centiliter', 'centiliters', 'centilitro', 'centilitri' => ['dimension' => 'volume', 'unit' => 'ml', 'factor' => 10.0],
        'dl', 'deciliter', 'deciliters', 'decilitro', 'decilitri' => ['dimension' => 'volume', 'unit' => 'ml', 'factor' => 100.0],
        'l', 'liter', 'liters', 'litro', 'litri' => ['dimension' => 'volume', 'unit' => 'ml', 'factor' => 1000.0],
        'pz', 'pc', 'pcs', 'piece', 'pieces', 'pezzo', 'pezzi' => ['dimension' => 'count', 'unit' => 'pz', 'factor' => 1.0],
        'conf', 'package', 'packages', 'pack', 'packs', 'confezione', 'confezioni' => ['dimension' => 'package', 'unit' => 'conf', 'factor' => 1.0],
        default => null,
    };
}

function recipeCatalogQuantitySufficiency(array $ingredient, array $match): array {
    $needed = $ingredient['quantity'] ?? null;
    $neededDefinition = recipeCatalogUnitDefinition((string)($ingredient['unit'] ?? ''));
    if ($needed === null || (float)$needed <= 0 || $neededDefinition === null) {
        return ['known' => false, 'ratio' => null, 'sufficient' => true];
    }

    $neededBase = (float)$needed * $neededDefinition['factor'];
    $availableBase = 0.0;
    $compatibleRows = 0;
    foreach (($match['stock_rows'] ?? [$match]) as $stockRow) {
        $stockUnit = (string)($stockRow['unit'] ?? '');
        $availableQuantity = (float)($stockRow['quantity'] ?? 0);
        $availableDefinition = recipeCatalogUnitDefinition($stockUnit);
        if (
            $availableDefinition !== null
            && $availableDefinition['dimension'] === 'package'
            && $neededDefinition['dimension'] !== 'package'
            && (float)($stockRow['default_quantity'] ?? 0) > 0
        ) {
            $packageDefinition = recipeCatalogUnitDefinition(
                (string)($stockRow['package_unit'] ?? '')
            );
            if ($packageDefinition !== null) {
                $availableDefinition = $packageDefinition;
                $availableQuantity *= (float)$stockRow['default_quantity'];
            }
        }
        if (
            $availableDefinition === null
            || $availableDefinition['dimension'] !== $neededDefinition['dimension']
        ) {
            continue;
        }
        $availableBase += $availableQuantity * $availableDefinition['factor'];
        $compatibleRows++;
    }
    if ($compatibleRows === 0) {
        return [
            'known' => true,
            'ratio' => 0.0,
            'sufficient' => false,
            'reason' => 'unit_mismatch',
            'needed' => $neededBase,
            'available' => 0.0,
            'unit' => $neededDefinition['unit'],
        ];
    }

    $ratio = $neededBase > 0 ? $availableBase / $neededBase : 0.0;
    return [
        'known' => true,
        'ratio' => $ratio,
        'sufficient' => $ratio >= 0.999999,
        'needed' => $neededBase,
        'available' => $availableBase,
        'unit' => $neededDefinition['unit'],
    ];
}

function recipeCatalogApplyMissingGate(float $score, float $coverage, int $missingCount): float {
    if ($missingCount <= 0) {
        return $score;
    }
    // A missing required ingredient is a cookability gate, not a soft preference.
    $gate = max(0.05, 0.35 * ($coverage ** 2) / $missingCount);
    $score *= $gate;
    return min($score, max(0.10, 0.39 - (($missingCount - 1) * 0.04)));
}

function recipeCatalogRankRecipe(
    PDO $db,
    array $recipe,
    array $inventoryCandidates,
    string $mode = 'stocked'
): array {
    $mode = $mode === 'expiring' ? 'expiring' : 'stocked';
    $requiredCount = 0;
    $matchedRequired = 0;
    $directnessTotal = 0.0;
    $directnessCount = 0;
    $expiryScore = 0.0;
    $missingRequired = [];
    $ingredientMatches = [];

    foreach ($recipe['ingredients'] as $ingredient) {
        $isRequired = !empty($ingredient['is_required'])
            && empty($ingredient['is_optional'])
            && empty($ingredient['is_staple']);
        if ($isRequired) {
            $requiredCount++;
        }

        if (!empty($ingredient['is_staple'])) {
            $ingredientMatches[] = [
                'ingredient' => $ingredient['normalized_name'],
                'required' => false,
                'matched' => true,
                'relation' => 'staple',
                'score' => 1.0,
            ];
            continue;
        }

        $match = recipeIngredientBestInventoryMatch($db, $ingredient, $inventoryCandidates);
        $quantity = $match !== null
            ? recipeCatalogQuantitySufficiency($ingredient, $match)
            : ['known' => false, 'ratio' => null, 'sufficient' => false];
        $relationCanSatisfyRequired = recipeIngredientMatchCanSatisfyRequired($match);
        $countsAsMatched = $match !== null
            && (float)$match['score'] >= 0.20
            && $quantity['sufficient']
            && (!$isRequired || $relationCanSatisfyRequired);

        if ($countsAsMatched) {
            if ($isRequired) {
                $matchedRequired++;
            }
            $directnessTotal += min(1.0, (float)$match['score']);
            $directnessCount++;
            $expiryScore = max(
                $expiryScore,
                recipeCatalogExpiryUrgency($match['days_remaining'])
                    * min(1.0, max(0.0, (float)$match['score']))
            );
        } elseif ($isRequired) {
            $missingRequired[] = [
                'position' => (int)$ingredient['position'],
                'name' => (string)$ingredient['normalized_name'],
                'reason' => $match === null
                    ? 'not_in_stock'
                    : (
                        !$relationCanSatisfyRequired
                            ? 'relation_too_broad'
                            : ($quantity['sufficient'] ? 'match_too_weak' : 'insufficient_quantity')
                    ),
            ];
        }

        $explain = [
            'position' => (int)$ingredient['position'],
            'ingredient' => (string)$ingredient['normalized_name'],
            'required' => $isRequired,
            'matched' => $countsAsMatched,
            'quantity' => $quantity,
        ];
        if ($match !== null) {
            $explain += $match;
        }
        $ingredientMatches[] = $explain;
    }

    $coverage = $requiredCount === 0 ? 1.0 : $matchedRequired / $requiredCount;
    $directness = $directnessCount > 0 ? $directnessTotal / $directnessCount : 0.0;
    $sourceUser = recipeCatalogSourceUserScore($recipe);
    $components = [
        'coverage' => round($coverage, 6),
        'directness' => round($directness, 6),
        'expiry' => round($expiryScore, 6),
        'source_user' => round($sourceUser, 6),
    ];

    $missingCount = count($missingRequired);
    $availabilityScore = recipeCatalogApplyMissingGate(
        $coverage * 0.75 + $directness * 0.15 + $sourceUser * 0.10,
        $coverage,
        $missingCount
    );
    $expiringScore = recipeCatalogApplyMissingGate(
        $coverage * RECIPE_RANK_WEIGHT_COVERAGE
            + $directness * RECIPE_RANK_WEIGHT_DIRECTNESS
            + $expiryScore * RECIPE_RANK_WEIGHT_EXPIRY
            + $sourceUser * RECIPE_RANK_WEIGHT_SOURCE_USER,
        $coverage,
        $missingCount
    );
    $score = $mode === 'expiring' ? $expiringScore : $availabilityScore;

    return [
        'score' => round(max(0.0, min(1.0, $score)), 6),
        'cookable' => $missingCount === 0,
        'mode' => $mode,
        'components' => $components,
        'missing_required_count' => $missingCount,
        'missing_required' => $missingRequired,
        'ingredient_matches' => $ingredientMatches,
        'scores' => [
            'availability' => round(max(0.0, min(1.0, $availabilityScore)), 6),
            'expiring' => round(max(0.0, min(1.0, $expiringScore)), 6),
        ],
    ];
}

function recipeCatalogBuildFtsQuery(string $query): string {
    preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($query, 'UTF-8'), $matches);
    $terms = array_slice(array_values(array_unique($matches[0] ?? [])), 0, 12);
    if (!$terms) {
        return '';
    }
    return implode(' AND ', array_map(static function (string $term): string {
        $term = str_replace('"', '""', $term);
        return '"' . $term . '"*';
    }, $terms));
}

function recipeCatalogTextSearch(
    PDO $db,
    string $query,
    ?string $source,
    int $limit,
    int $offset
): array {
    $ftsQuery = recipeCatalogBuildFtsQuery($query);
    if ($ftsQuery === '') {
        return ['total' => 0, 'rows' => []];
    }

    $sourceWhere = '';
    $params = [$ftsQuery];
    $sourceUsesParam = false;
    if ($source === 'non_cookidoo') {
        $sourceWhere = "
            AND NOT EXISTS (
                SELECT 1 FROM recipe_origins o
                WHERE o.recipe_id = c.id AND o.connector = 'cookidoo'
            )
        ";
    } elseif ($source !== null && $source !== '') {
        $sourceWhere = "
            AND EXISTS (
                SELECT 1 FROM recipe_origins o
                WHERE o.recipe_id = c.id AND o.connector = ?
            )
        ";
        $params[] = $source;
        $sourceUsesParam = true;
    }
    $languageVisibility =
        recipeCookidooLanguageVisibilitySql('c');

    $count = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_catalog_fts
        JOIN recipe_catalog c ON c.id = recipe_catalog_fts.rowid
        LEFT JOIN recipe_user_state us ON us.recipe_id = c.id
        WHERE recipe_catalog_fts MATCH ?
          AND c.deleted_at IS NULL
          AND (
              (
                  (c.cache_expires_at IS NULL OR c.cache_expires_at >= CURRENT_TIMESTAMP)
                  AND (c.stale_at IS NULL OR c.stale_at >= CURRENT_TIMESTAMP)
              )
              OR COALESCE(us.favorite, 0) = 1
          )
          AND COALESCE(us.hidden, 0) = 0
          {$sourceWhere}
          {$languageVisibility}
    ");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $limitClause = '';
    $searchParams = [$query, $query, $ftsQuery];
    if ($sourceUsesParam) {
        $searchParams[] = $source;
    }
    if ($limit > 0) {
        $limitClause = 'LIMIT ? OFFSET ?';
        $searchParams[] = $limit;
        $searchParams[] = $offset;
    }
    $stmt = $db->prepare("
        SELECT recipe_catalog_fts.rowid AS recipe_id,
               CASE
                   WHEN LOWER(c.title) = LOWER(?) THEN 0
                   WHEN INSTR(LOWER(c.title), LOWER(?)) > 0 THEN 1
                   ELSE 2
               END AS title_match_rank,
               bm25(recipe_catalog_fts, 5.0, 3.0, 1.5, 1.0) AS text_rank
        FROM recipe_catalog_fts
        JOIN recipe_catalog c ON c.id = recipe_catalog_fts.rowid
        LEFT JOIN recipe_user_state us ON us.recipe_id = c.id
        WHERE recipe_catalog_fts MATCH ?
          AND c.deleted_at IS NULL
          AND (
              (
                  (c.cache_expires_at IS NULL OR c.cache_expires_at >= CURRENT_TIMESTAMP)
                  AND (c.stale_at IS NULL OR c.stale_at >= CURRENT_TIMESTAMP)
              )
              OR COALESCE(us.favorite, 0) = 1
          )
          AND COALESCE(us.hidden, 0) = 0
          {$sourceWhere}
          {$languageVisibility}
        ORDER BY text_rank ASC, c.updated_at DESC
        {$limitClause}
    ");
    $stmt->execute($searchParams);
    return ['total' => $total, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
}

function recipeCatalogSuggestionIds(PDO $db, ?string $source = null): array {
    $params = [];
    $sourceWhere = '';
    if ($source === 'non_cookidoo') {
        $sourceWhere = "
            AND NOT EXISTS (
                SELECT 1 FROM recipe_origins o
                WHERE o.recipe_id = c.id AND o.connector = 'cookidoo'
            )
        ";
    } elseif ($source !== null && $source !== '') {
        $sourceWhere = "
            AND EXISTS (
                SELECT 1 FROM recipe_origins o
                WHERE o.recipe_id = c.id AND o.connector = ?
            )
        ";
        $params[] = $source;
    }
    $languageVisibility =
        recipeCookidooLanguageVisibilitySql('c');
    $stmt = $db->prepare("
        SELECT c.id
        FROM recipe_catalog c
        LEFT JOIN recipe_user_state us ON us.recipe_id = c.id
        WHERE c.deleted_at IS NULL
          AND (
              (
                  (c.cache_expires_at IS NULL OR c.cache_expires_at >= CURRENT_TIMESTAMP)
                  AND (c.stale_at IS NULL OR c.stale_at >= CURRENT_TIMESTAMP)
              )
              OR COALESCE(us.favorite, 0) = 1
          )
          AND COALESCE(us.hidden, 0) = 0
          {$sourceWhere}
          {$languageVisibility}
        ORDER BY c.updated_at DESC, c.id DESC
    ");
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function recipeCatalogSuggestionResult(
    PDO $db,
    array $options = []
): array {
    $options['query'] = '';
    $options['sort'] = ($options['mode'] ?? 'stocked') === 'expiring'
        ? 'expiry'
        : 'availability';
    return recipeCatalogBrowseResult($db, $options);
}

function recipeCatalogSearchResult(PDO $db, array $options = []): array {
    return recipeCatalogBrowseResult($db, $options);
}
