<?php

function recipeCatalogStableValue(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (recipeArrayIsList($value)) {
        return array_map('recipeCatalogStableValue', $value);
    }
    ksort($value);
    foreach ($value as $key => $item) {
        $value[$key] = recipeCatalogStableValue($item);
    }
    return $value;
}

function recipeCatalogJsonEncode(mixed $value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new InvalidArgumentException('Recipe data is not JSON serializable: ' . json_last_error_msg());
    }
    return $json;
}

function recipeCatalogNormalizeOptionalText(
    mixed $value,
    string $field,
    int $maximum
): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException($field . ' must be a string');
    }
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value, 'UTF-8') > $maximum) {
        throw new InvalidArgumentException($field . ' is too long');
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new InvalidArgumentException($field . ' contains control characters');
    }
    return $value;
}

function recipeCatalogNormalizeOptionalNumber(mixed $value, string $field): ?float {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_bool($value) || !is_numeric($value)) {
        throw new InvalidArgumentException($field . ' must be numeric');
    }
    $number = (float)$value;
    if (!is_finite($number) || $number < 0 || $number > 1000000000) {
        throw new InvalidArgumentException($field . ' is out of range');
    }
    return $number;
}

function recipeCatalogNormalizeOptionalSeconds(mixed $value, string $field): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    if (
        is_bool($value)
        || (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value)))
    ) {
        throw new InvalidArgumentException($field . ' must be a non-negative integer');
    }
    $seconds = (int)$value;
    if ($seconds < 0 || $seconds > RECIPE_MAX_FACTUAL_DURATION_SECONDS) {
        throw new InvalidArgumentException($field . ' is out of range');
    }
    return $seconds;
}

function recipeCatalogNormalizeEquipment(mixed $value): array {
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || !recipeArrayIsList($value)) {
        throw new InvalidArgumentException('equipment must be an array');
    }
    if (count($value) > 50) {
        throw new InvalidArgumentException('equipment has too many entries');
    }
    $equipment = [];
    foreach ($value as $item) {
        $name = recipeCatalogNormalizeOptionalText($item, 'equipment item', 120);
        if ($name !== null) {
            $equipment[] = $name;
        }
    }
    return $equipment;
}

function recipeCatalogNormalizeFactNameList(
    mixed $value,
    string $field
): array {
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || !recipeArrayIsList($value)) {
        throw new InvalidArgumentException($field . ' must be an array');
    }
    if (count($value) > 50) {
        throw new InvalidArgumentException($field . ' has too many entries');
    }
    $names = [];
    $seen = [];
    foreach ($value as $item) {
        $name = recipeCatalogNormalizeOptionalText(
            $item,
            $field . ' item',
            120
        );
        if ($name === null) {
            continue;
        }
        $key = mb_strtolower($name, 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $names[] = $name;
    }
    return $names;
}

function recipeCatalogFirstValidLocale(array $candidates): string {
    foreach ($candidates as $candidate) {
        if (
            !is_string($candidate)
            || trim($candidate) === ''
            || !mb_check_encoding($candidate, 'UTF-8')
        ) {
            continue;
        }
        $candidate = recipeQuantityNormalizeLocale($candidate);
        if ($candidate !== 'und') {
            return $candidate;
        }
    }
    return 'und';
}

function recipeCatalogEffectiveIngredientLocale(
    array $metadata,
    array $recipe
): string {
    return recipeCatalogFirstValidLocale([
        $metadata['locale'] ?? null,
        $metadata['language'] ?? null,
        $recipe['locale'] ?? null,
        $recipe['language'] ?? null,
        $recipe['lang'] ?? null,
    ]);
}

function recipeCatalogEffectiveDisplayLanguage(
    array $metadata,
    array $recipe
): string {
    return recipeCatalogFirstValidLocale([
        $metadata['language'] ?? null,
        $recipe['language'] ?? null,
        $recipe['lang'] ?? null,
    ]);
}

function recipeCatalogExactIdentityRawText(mixed $value): string {
    if (
        !is_string($value)
        || !mb_check_encoding($value, 'UTF-8')
    ) {
        return '';
    }
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return mb_strtolower(
        mb_substr($value, 0, RECIPE_QUANTITY_MAX_TEXT_LENGTH, 'UTF-8'),
        'UTF-8'
    );
}

function recipeCatalogNormalizeKeywords(mixed $keywords): array {
    if (is_string($keywords)) {
        $keywords = preg_split('/[,;]+/u', $keywords, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    if (!is_array($keywords)) {
        return [];
    }
    $out = [];
    foreach ($keywords as $keyword) {
        $keyword = trim((string)$keyword);
        if ($keyword === '') {
            continue;
        }
        $key = recipeIngredientNormalizeName($keyword);
        if ($key !== '' && !isset($out[$key])) {
            $out[$key] = $keyword;
        }
    }
    return array_values($out);
}

function recipeCatalogNormalizeInstructionGroups(
    mixed $value,
    array $instructions
): array {
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || !recipeArrayIsList($value)) {
        throw new InvalidArgumentException('instruction_groups must be an array');
    }
    if (count($value) > 50) {
        throw new InvalidArgumentException(
            'instruction_groups has too many entries'
        );
    }
    $groups = [];
    $assigned = [];
    foreach ($value as $groupIndex => $group) {
        if (!is_array($group) || ($group !== [] && recipeArrayIsList($group))) {
            throw new InvalidArgumentException(
                'instruction_groups contains an invalid group'
            );
        }
        $label = recipeCatalogNormalizeOptionalText(
            $group['label'] ?? null,
            'instruction group label',
            160
        );
        $positions = $group['step_positions']
            ?? $group['instruction_positions']
            ?? null;
        if (!is_array($positions) || !recipeArrayIsList($positions) || !$positions) {
            throw new InvalidArgumentException(
                'instruction group step_positions must be a nonempty array'
            );
        }
        if (count($positions) > 100) {
            throw new InvalidArgumentException(
                'instruction group has too many step positions'
            );
        }
        $normalizedPositions = [];
        $previousPosition = -1;
        foreach ($positions as $position) {
            if (is_bool($position) || !is_int($position)) {
                throw new InvalidArgumentException(
                    'instruction group step position is invalid'
                );
            }
            if ($position < 0 || $position >= count($instructions)) {
                throw new InvalidArgumentException(
                    'instruction group step position is out of range'
                );
            }
            if ($position <= $previousPosition) {
                throw new InvalidArgumentException(
                    'instruction group step positions must be ordered'
                );
            }
            if (isset($assigned[$position])) {
                throw new InvalidArgumentException(
                    'instruction steps may belong to only one group'
                );
            }
            $assigned[$position] = true;
            $normalizedPositions[] = $position;
            $previousPosition = $position;
        }
        $groups[] = [
            'index' => (int)$groupIndex,
            'label' => $label,
            'step_positions' => $normalizedPositions,
        ];
    }
    return $groups;
}

function recipeCatalogNormalizeRecipe(PDO $db, array $recipe, array $metadata = []): array {
    $connector = trim((string)($metadata['connector'] ?? $recipe['connector'] ?? 'manual'));
    if (!recipeConnectorExists($connector)) {
        throw new InvalidArgumentException('Unknown recipe connector: ' . $connector);
    }
    $connectorMetadata = recipeConnectorRegistry()[$connector];
    if ($connector === RECIPE_COOKIDOO_CONNECTOR) {
        $recipe = recipeCookidooNormalizeRecipeForPersistence($recipe);
        $retrievedAt = recipeCookidooNormalizeRetrievedAt(
            $metadata['retrieved_at'] ?? null
        );
        $metadata['language'] = recipeCookidooNormalizeLocale(
            $metadata['language'] ?? $metadata['locale'] ?? 'en'
        );
        $metadata['locale'] = recipeCookidooNormalizeLocale(
            $metadata['locale'] ?? $metadata['language']
        );
        $metadata['storage_policy'] = RECIPE_COOKIDOO_STORAGE_POLICY;
        $metadata['rights_basis'] = RECIPE_COOKIDOO_RIGHTS_BASIS;
        $metadata['cache_expires_at'] = null;
        $metadata['stale_at'] = recipeCookidooMetadataStaleAt($retrievedAt);
        $metadata['retrieved_at'] = $retrievedAt;
        $metadata['metadata_version'] = RECIPE_COOKIDOO_METADATA_VERSION;
        $metadata['metadata_schema_version'] =
            RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION;
        $metadata['store_source_payload'] = false;
    }

    $title = trim((string)($recipe['title'] ?? $recipe['name'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('Recipe title is required');
    }

    $instructions = $recipe['instructions'] ?? $recipe['steps'] ?? [];
    if (is_string($instructions)) {
        $instructions = preg_split('/\r?\n/u', $instructions, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    if (!is_array($instructions)) {
        $instructions = [];
    }
    $instructions = array_values(array_map(static function (mixed $instruction): string {
        if (is_string($instruction)) {
            return trim($instruction);
        }
        if (is_array($instruction)) {
            return trim((string)($instruction['text'] ?? $instruction['instruction'] ?? $instruction['description'] ?? ''));
        }
        return trim((string)$instruction);
    }, $instructions));
    $instructions = array_values(array_filter($instructions, static fn(string $value): bool => $value !== ''));
    $instructionGroups = $connector === RECIPE_COOKIDOO_CONNECTOR
        ? []
        : recipeCatalogNormalizeInstructionGroups(
            $recipe['instruction_groups'] ?? [],
            $instructions
        );

    $ingredientInput = $recipe['ingredients'] ?? [];
    if (!is_array($ingredientInput)) {
        throw new InvalidArgumentException('Recipe ingredients must be an array');
    }
    $ingredientLocale = recipeCatalogEffectiveIngredientLocale(
        $metadata,
        $recipe
    );
    $ingredients = [];
    foreach ($ingredientInput as $position => $ingredient) {
        $row = recipeIngredientNormalizeRow(
            $db,
            $ingredient,
            (int)$position,
            $connector,
            $ingredientLocale
        );
        if ($row['normalized_name'] !== '' || $row['raw_text'] !== '') {
            $ingredients[] = $row;
        }
    }
    foreach ($ingredients as $position => &$ingredient) {
        $ingredient['position'] = $position;
    }
    unset($ingredient);

    $sourceIngredientInput = $recipe['source_ingredients'] ?? [];
    if (!is_array($sourceIngredientInput) || !recipeArrayIsList($sourceIngredientInput)) {
        throw new InvalidArgumentException('Recipe source ingredients must be an array');
    }
    if (count($sourceIngredientInput) > 200) {
        throw new InvalidArgumentException('Recipe source ingredients has too many entries');
    }
    $sourceIngredients = [];
    foreach ($sourceIngredientInput as $position => $ingredient) {
        $sourceIngredients[] = recipeIngredientNormalizeSourceRow(
            $db,
            $ingredient,
            (int)$position
        );
    }
    $sourceIngredients = recipeIngredientValidateSourceGrouping(
        $sourceIngredients
    );

    $keywords = recipeCatalogNormalizeKeywords($recipe['keywords'] ?? $recipe['tags'] ?? []);
    $nutrition = $recipe['nutrition'] ?? [];
    if (!is_array($nutrition)) {
        $nutrition = ['note' => (string)$nutrition];
    }
    $contentLanguage = trim((string)(
        $metadata['content_language']
        ?? $recipe['content_language']
        ?? ''
    ));
    if (
        $contentLanguage !== ''
        && (
            strlen($contentLanguage) > 20
            || !preg_match(
                '/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D',
                $contentLanguage
            )
        )
    ) {
        throw new InvalidArgumentException(
            'content_language is invalid'
        );
    }
    $contentLanguage = strtolower($contentLanguage);
    $prepTime = isset($recipe['prep_time'])
        ? trim((string)$recipe['prep_time'])
        : (
            isset($recipe['prepTime'])
                ? trim((string)$recipe['prepTime'])
                : null
        );
    $cookTime = isset($recipe['cook_time'])
        ? trim((string)$recipe['cook_time'])
        : (
            isset($recipe['cookTime'])
                ? trim((string)$recipe['cookTime'])
                : null
        );
    $activeTimeSeconds = recipeCatalogNormalizeOptionalSeconds(
        $recipe['active_time_seconds']
            ?? $recipe['activeTimeSeconds']
            ?? null,
        'active_time_seconds'
    );
    $totalTimeSeconds = recipeCatalogNormalizeOptionalSeconds(
        $recipe['total_time_seconds']
            ?? $recipe['totalTimeSeconds']
            ?? null,
        'total_time_seconds'
    );
    $prepTimeSeconds = recipeCatalogNormalizeOptionalSeconds(
        $recipe['prep_time_seconds']
            ?? $recipe['prepTimeSeconds']
            ?? null,
        'prep_time_seconds'
    );
    $cookTimeSeconds = recipeCatalogNormalizeOptionalSeconds(
        $recipe['cook_time_seconds']
            ?? $recipe['cookTimeSeconds']
            ?? null,
        'cook_time_seconds'
    );
    $inactiveTimeSeconds = recipeCatalogNormalizeOptionalSeconds(
        $recipe['inactive_time_seconds']
            ?? $recipe['inactiveTimeSeconds']
            ?? null,
        'inactive_time_seconds'
    );
    if ($connector !== RECIPE_COOKIDOO_CONNECTOR) {
        $prepTimeSeconds ??= recipeTimeParseDurationSeconds(
            $prepTime,
            $ingredientLocale
        );
        $cookTimeSeconds ??= recipeTimeParseDurationSeconds(
            $cookTime,
            $ingredientLocale
        );
    }
    $inactiveTimeSeconds = recipeTimeDeriveInactiveSeconds(
        $activeTimeSeconds,
        $totalTimeSeconds,
        $prepTimeSeconds,
        $cookTimeSeconds,
        $inactiveTimeSeconds
    );
    $devices = recipeCatalogNormalizeFactNameList(
        $recipe['devices'] ?? [],
        'devices'
    );
    $requiredDeviceKeys = array_fill_keys(
        array_map(
            static fn(string $name): string =>
                mb_strtolower($name, 'UTF-8'),
            $devices
        ),
        true
    );
    $optionalDevices = array_values(array_filter(
        recipeCatalogNormalizeFactNameList(
            $recipe['optional_devices']
                ?? $recipe['optionalDevices']
                ?? [],
            'optional_devices'
        ),
        static fn(string $name): bool =>
            !isset($requiredDeviceKeys[mb_strtolower($name, 'UTF-8')])
    ));

    $normalized = [
        'primary_connector' => $connector,
        'ingredient_parse_locale' => $ingredientLocale,
        'title' => $title,
        'description' => trim((string)($recipe['description'] ?? $recipe['summary'] ?? $recipe['nutrition_note'] ?? '')),
        'image_url' => trim((string)($recipe['image_url'] ?? $recipe['image'] ?? '')),
        'language' => recipeCatalogEffectiveDisplayLanguage(
            $metadata,
            $recipe
        ),
        'servings' => isset($recipe['servings'])
            ? max(1, (int)$recipe['servings'])
            : (isset($recipe['persons']) ? max(1, (int)$recipe['persons']) : null),
        'prep_time' => $prepTime,
        'cook_time' => $cookTime,
        'total_time' => isset($recipe['total_time']) ? trim((string)$recipe['total_time']) : null,
        'cuisine' => trim((string)($recipe['cuisine'] ?? '')),
        'category' => trim((string)($recipe['category'] ?? $recipe['meal'] ?? '')),
        'yield_quantity' => recipeCatalogNormalizeOptionalNumber(
            $recipe['yield_quantity'] ?? null,
            'yield_quantity'
        ),
        'yield_unit' => recipeCatalogNormalizeOptionalText(
            $recipe['yield_unit'] ?? null,
            'yield_unit',
            80
        ),
        'prep_time_seconds' => $prepTimeSeconds,
        'cook_time_seconds' => $cookTimeSeconds,
        'active_time_seconds' => $activeTimeSeconds,
        'inactive_time_seconds' => $inactiveTimeSeconds,
        'total_time_seconds' => $totalTimeSeconds,
        'difficulty' => recipeCatalogNormalizeOptionalText(
            $recipe['difficulty'] ?? null,
            'difficulty',
            80
        ),
        'primary_category' => recipeCatalogNormalizeOptionalText(
            $recipe['primary_category'] ?? null,
            'primary_category',
            160
        ),
        'devices' => $devices,
        'optional_devices' => $optionalDevices,
        'equipment' => recipeCatalogNormalizeEquipment($recipe['equipment'] ?? []),
        'keywords' => $keywords,
        'instructions' => $instructions,
        'instruction_groups' => $instructionGroups,
        'nutrition' => $nutrition,
        'storage_policy' => trim((string)(
            $metadata['storage_policy']
            ?? $recipe['storage_policy']
            ?? $connectorMetadata['storage_policy']
        )),
        'rights_basis' => trim((string)(
            $metadata['rights_basis']
            ?? $recipe['rights_basis']
            ?? $connectorMetadata['rights_basis']
        )),
        'cache_expires_at' => $metadata['cache_expires_at'] ?? $recipe['cache_expires_at'] ?? null,
        'stale_at' => $metadata['stale_at'] ?? $recipe['stale_at'] ?? null,
        'retrieved_at' => $metadata['retrieved_at'] ?? $recipe['retrieved_at'] ?? date('Y-m-d H:i:s'),
        'source_payload' => !array_key_exists('store_source_payload', $metadata)
            || (bool)$metadata['store_source_payload']
            ? $recipe
            : null,
        'ingredients' => $ingredients,
        'source_ingredients' => $sourceIngredients,
        'origin' => [
            'connector' => $connector,
            'external_id' => trim((string)($metadata['external_id'] ?? $recipe['external_id'] ?? '')),
            'canonical_url' => trim((string)($metadata['canonical_url'] ?? $recipe['canonical_url'] ?? $recipe['url'] ?? '')),
            'locale' => $ingredientLocale,
            'content_language' => $contentLanguage,
            'attribution' => trim((string)($metadata['attribution'] ?? $recipe['attribution'] ?? '')),
            'license' => trim((string)($metadata['license'] ?? $recipe['license'] ?? '')),
            'metadata_version' => trim((string)(
                $metadata['metadata_version']
                ?? $recipe['metadata_version']
                ?? ''
            )),
            'metadata_schema_version' => trim((string)(
                $metadata['metadata_schema_version']
                ?? $recipe['metadata_schema_version']
                ?? ''
            )),
            'availability' => trim((string)($metadata['availability'] ?? 'available')) ?: 'available',
        ],
    ];
    if ($connector === RECIPE_COOKIDOO_CONNECTOR) {
        $normalized['origin'] = recipeCookidooNormalizeOrigin($normalized['origin']);
    } elseif ($normalized['origin']['external_id'] === '') {
        $normalized['origin']['external_id'] = recipeCatalogBuildExactExternalId($normalized);
    }
    return $normalized;
}

function recipeCatalogBuildExactExternalId(array $normalized): string {
    $includeSourceIdentity = (string)($normalized['primary_connector'] ?? '')
        !== RECIPE_COOKIDOO_CONNECTOR;
    $ingredientParseLocale = strtolower(recipeQuantityNormalizeLocale(
        (string)($normalized['ingredient_parse_locale'] ?? 'und')
    ));
    $identity = [
        'title' => recipeIngredientNormalizeName((string)$normalized['title']),
        'language' => recipeQuantityNormalizeLocale(
            (string)$normalized['language']
        ),
        'servings' => $normalized['servings'],
        'ingredients' => array_map(
            static function (array $ingredient) use (
                $includeSourceIdentity,
                $ingredientParseLocale
            ): array {
                $identity = [
                    'name' => $ingredient['normalized_name'],
                    'quantity' => $ingredient['quantity'],
                    'quantity_text' => $ingredient['quantity_text'],
                    'unit' => $ingredient['unit'],
                    'required' => $ingredient['is_required'],
                    'optional' => $ingredient['is_optional'],
                    'staple' => $ingredient['is_staple'],
                ];
                if ($includeSourceIdentity) {
                    $parse = is_array($ingredient['quantity_parse'] ?? null)
                        ? $ingredient['quantity_parse']
                        : [];
                    $identity['raw_text'] =
                        recipeCatalogExactIdentityRawText(
                            $ingredient['raw_text'] ?? ''
                        );
                    $identity['parse_locale'] = strtolower(
                        recipeQuantityNormalizeLocale(
                                (string)($parse['locale']
                                    ?? $ingredientParseLocale)
                        )
                    );
                    $identity['parse_provenance'] = is_string(
                        $parse['provenance'] ?? null
                    ) ? $parse['provenance'] : 'none';
                }
                return $identity;
            },
            $normalized['ingredients']
        ),
        'instructions' => $normalized['instructions'],
        'instruction_groups' => $normalized['instruction_groups'],
    ];
    return 'sha256:' . hash('sha256', recipeCatalogJsonEncode(recipeCatalogStableValue($identity)));
}

function recipeCatalogHeuristicClusterKey(array $normalized): string {
    $ingredientKeys = [];
    foreach ($normalized['ingredients'] as $ingredient) {
        $ingredientKeys[] = !empty($ingredient['canonical_ingredient_id'])
            ? 'c:' . (int)$ingredient['canonical_ingredient_id']
            : 'n:' . (string)$ingredient['normalized_name'];
    }
    sort($ingredientKeys);
    $basis = [
        'title' => recipeIngredientNormalizeName((string)$normalized['title']),
        'ingredients' => array_slice(array_values(array_unique($ingredientKeys)), 0, 12),
    ];
    return 'heuristic:' . substr(hash('sha256', recipeCatalogJsonEncode($basis)), 0, 32);
}

function recipeCatalogFindExactContentRecipeId(PDO $db, array $recipe, array $metadata = []): ?int {
    $normalized = recipeCatalogNormalizeRecipe($db, $recipe, $metadata);
    $stmt = $db->prepare("
        SELECT recipe_id
        FROM recipe_origins o
        JOIN recipe_catalog c ON c.id = o.recipe_id
        WHERE o.external_id = ?
          AND c.storage_policy <> 'metadata_only'
          AND c.deleted_at IS NULL
        ORDER BY
            CASE connector WHEN 'generated' THEN 0 WHEN 'manual' THEN 1 ELSE 2 END,
            o.id ASC
        LIMIT 1
    ");
    $stmt->execute([$normalized['origin']['external_id']]);
    $recipeId = $stmt->fetchColumn();
    return $recipeId !== false ? (int)$recipeId : null;
}

function recipeCatalogRebuildCluster(PDO $db, int $recipeId): void {
    $recipeStmt = $db->prepare("
        SELECT title
        FROM recipe_catalog
        WHERE id = ? AND deleted_at IS NULL
    ");
    $recipeStmt->execute([$recipeId]);
    $title = $recipeStmt->fetchColumn();
    if ($title === false) {
        $db->prepare("DELETE FROM recipe_clusters WHERE recipe_id = ?")->execute([$recipeId]);
        return;
    }

    $ingredientStmt = $db->prepare("
        SELECT normalized_name, canonical_ingredient_id
        FROM recipe_ingredients
        WHERE recipe_id = ?
        ORDER BY position
    ");
    $ingredientStmt->execute([$recipeId]);
    $ingredients = $ingredientStmt->fetchAll(PDO::FETCH_ASSOC);
    $clusterKey = recipeCatalogHeuristicClusterKey([
        'title' => (string)$title,
        'ingredients' => $ingredients,
    ]);
    $db->prepare("
        INSERT INTO recipe_clusters (recipe_id, cluster_key, method, confidence, updated_at)
        VALUES (?, ?, 'heuristic', 0.55, CURRENT_TIMESTAMP)
        ON CONFLICT(recipe_id) DO UPDATE SET
            cluster_key = excluded.cluster_key,
            method = excluded.method,
            confidence = excluded.confidence,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([$recipeId, $clusterKey]);
}

function recipeSearchRebuildDocument(PDO $db, int $recipeId): void {
    $stmt = $db->prepare("
        SELECT title, description, cuisine, category, keywords_json, deleted_at
        FROM recipe_catalog
        WHERE id = ?
    ");
    $stmt->execute([$recipeId]);
    $recipe = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$recipe || $recipe['deleted_at'] !== null) {
        $db->prepare("DELETE FROM recipe_search_documents WHERE recipe_id = ?")->execute([$recipeId]);
        return;
    }

    $ingredients = $db->prepare("
        SELECT raw_text, normalized_name
        FROM recipe_ingredients
        WHERE recipe_id = ?
        ORDER BY position
    ");
    $ingredients->execute([$recipeId]);
    $ingredientText = [];
    foreach ($ingredients->fetchAll(PDO::FETCH_ASSOC) as $ingredient) {
        $ingredientText[] = trim((string)$ingredient['raw_text']);
        $ingredientText[] = trim((string)$ingredient['normalized_name']);
    }
    $ingredientText = implode(' ', array_values(array_filter($ingredientText)));

    $keywords = json_decode((string)$recipe['keywords_json'], true);
    $tags = array_merge(
        is_array($keywords) ? array_map('strval', $keywords) : [],
        [(string)$recipe['cuisine'], (string)$recipe['category']]
    );
    $tags = implode(' ', array_values(array_filter(array_map('trim', $tags))));

    $upsert = $db->prepare("
        INSERT INTO recipe_search_documents (
            recipe_id, title, ingredient_text, tags, description, updated_at
        )
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(recipe_id) DO UPDATE SET
            title = excluded.title,
            ingredient_text = excluded.ingredient_text,
            tags = excluded.tags,
            description = excluded.description,
            updated_at = CURRENT_TIMESTAMP
    ");
    $upsert->execute([
        $recipeId,
        (string)$recipe['title'],
        $ingredientText,
        $tags,
        (string)$recipe['description'],
    ]);
}

function recipeSearchRebuildAll(PDO $db): int {
    $ids = $db->query("SELECT id FROM recipe_catalog WHERE deleted_at IS NULL ORDER BY id")
        ->fetchAll(PDO::FETCH_COLUMN);
    $active = array_map('intval', $ids);
    $db->exec("
        DELETE FROM recipe_search_documents
        WHERE recipe_id NOT IN (
            SELECT id FROM recipe_catalog WHERE deleted_at IS NULL
        )
    ");
    foreach ($active as $recipeId) {
        recipeSearchRebuildDocument($db, $recipeId);
    }
    $db->exec("INSERT INTO recipe_catalog_fts(recipe_catalog_fts) VALUES ('rebuild')");
    return count($active);
}

function recipeCatalogSaveLock(): mixed {
    $depth = (int)($GLOBALS['_RECIPE_CATALOG_LOCK_DEPTH'] ?? 0);
    if ($depth > 0) {
        $GLOBALS['_RECIPE_CATALOG_LOCK_DEPTH'] = $depth + 1;
        return $GLOBALS['_RECIPE_CATALOG_LOCK_HANDLE'];
    }
    $path = __DIR__ . '/../../../data/recipe_catalog.lock';
    if (!file_exists($path)) {
        $oldUmask = umask(0);
        try {
            @touch($path);
        } finally {
            umask($oldUmask);
        }
    }
    @chmod($path, 0666);
    $handle = @fopen($path, 'c');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('Cannot lock recipe catalog saves');
    }
    $GLOBALS['_RECIPE_CATALOG_LOCK_HANDLE'] = $handle;
    $GLOBALS['_RECIPE_CATALOG_LOCK_DEPTH'] = 1;
    return $handle;
}

function recipeCatalogSaveUnlock(mixed $handle): void {
    $depth = (int)($GLOBALS['_RECIPE_CATALOG_LOCK_DEPTH'] ?? 0);
    if ($depth > 1) {
        $GLOBALS['_RECIPE_CATALOG_LOCK_DEPTH'] = $depth - 1;
        return;
    }
    $GLOBALS['_RECIPE_CATALOG_LOCK_DEPTH'] = 0;
    unset($GLOBALS['_RECIPE_CATALOG_LOCK_HANDLE']);
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

function recipeCatalogRequirePositiveInt(
    mixed $value,
    string $field = 'recipe_id'
): int {
    if (!is_int($value) || $value <= 0) {
        throw new InvalidArgumentException('invalid_' . $field);
    }
    return $value;
}

function recipeCatalogSaveVariant(PDO $db, array $recipe, array $metadata = []): array {
    $requestedRecipeId = array_key_exists('recipe_id', $metadata)
        ? recipeCatalogRequirePositiveInt(
            $metadata['recipe_id'],
            'recipe_id'
        )
        : 0;
    $normalized = recipeCatalogNormalizeRecipe($db, $recipe, $metadata);
    $saveLock = recipeCatalogSaveLock();
    try {
    $origin = $normalized['origin'];
    $recipeId = 0;
    $originId = 0;
    $resolvedRecipeIds = [];
    if ($requestedRecipeId > 0) {
        $resolvedRecipeIds['recipe_id'] = $requestedRecipeId;
    }

    if ($origin['external_id'] !== '') {
        $lookup = $db->prepare("
            SELECT o.id, o.recipe_id, c.deleted_at
            FROM recipe_origins o
            JOIN recipe_catalog c ON c.id = o.recipe_id
            WHERE o.connector = ? AND o.external_id = ?
            LIMIT 1
        ");
        $lookup->execute([$origin['connector'], $origin['external_id']]);
        if ($row = $lookup->fetch(PDO::FETCH_ASSOC)) {
            if ($row['deleted_at'] !== null) {
                throw new InvalidArgumentException(
                    'Recipe origin is deleted; explicit restore is required'
                );
            }
            $originId = (int)$row['id'];
            $resolvedRecipeIds['external_id'] = (int)$row['recipe_id'];
        }
    }
    if ($origin['canonical_url'] !== '') {
        $lookup = $db->prepare("
            SELECT o.id, o.recipe_id, c.deleted_at
            FROM recipe_origins o
            JOIN recipe_catalog c ON c.id = o.recipe_id
            WHERE o.connector = ? AND o.canonical_url = ?
            LIMIT 1
        ");
        $lookup->execute([$origin['connector'], $origin['canonical_url']]);
        if ($row = $lookup->fetch(PDO::FETCH_ASSOC)) {
            if ($row['deleted_at'] !== null) {
                throw new InvalidArgumentException(
                    'Recipe origin is deleted; explicit restore is required'
                );
            }
            $resolvedRecipeIds['canonical_url'] = (int)$row['recipe_id'];
            if ($originId <= 0) {
                $originId = (int)$row['id'];
            }
        }
    }
    $uniqueResolvedIds = array_values(array_unique(array_values(
        $resolvedRecipeIds
    )));
    if (count($uniqueResolvedIds) > 1) {
        throw new InvalidArgumentException(
            'Recipe identifiers resolve to different catalog rows'
        );
    }
    if ($uniqueResolvedIds) {
        $recipeId = (int)$uniqueResolvedIds[0];
    }
    if ($recipeId > 0 && $originId <= 0) {
        $lookup = $db->prepare("
            SELECT id
            FROM recipe_origins
            WHERE recipe_id = ? AND connector = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $lookup->execute([$recipeId, $origin['connector']]);
        $originId = (int)($lookup->fetchColumn() ?: 0);
    }

    if ($recipeId > 0) {
        $exists = $db->prepare("
            SELECT id, primary_connector, deleted_at
            FROM recipe_catalog
            WHERE id = ?
        ");
        $exists->execute([$recipeId]);
        $existingRecipe = $exists->fetch(PDO::FETCH_ASSOC);
        if (!$existingRecipe) {
            throw new RuntimeException('Recipe catalog row not found: ' . $recipeId);
        }
        if ($existingRecipe['deleted_at'] !== null) {
            throw new InvalidArgumentException(
                'Deleted recipes require an explicit restore operation'
            );
        }
        if (
            $requestedRecipeId > 0
            && (string)$existingRecipe['primary_connector'] !== (string)$origin['connector']
        ) {
            throw new InvalidArgumentException(
                'Cross-connector recipe updates must create a separate variant'
            );
        }
    }

    $isNewRecipe = $recipeId <= 0;
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $catalogParams = [
            $normalized['primary_connector'],
            $normalized['title'],
            $normalized['description'],
            $normalized['image_url'],
            $normalized['language'],
            $normalized['servings'],
            $normalized['prep_time'],
            $normalized['cook_time'],
            $normalized['total_time'],
            $normalized['cuisine'],
            $normalized['category'],
            $normalized['yield_quantity'],
            $normalized['yield_unit'],
            $normalized['prep_time_seconds'],
            $normalized['cook_time_seconds'],
            $normalized['active_time_seconds'],
            $normalized['inactive_time_seconds'],
            $normalized['total_time_seconds'],
            $normalized['difficulty'],
            $normalized['primary_category'],
            recipeCatalogJsonEncode($normalized['devices']),
            recipeCatalogJsonEncode($normalized['optional_devices']),
            recipeCatalogJsonEncode($normalized['equipment']),
            recipeCatalogJsonEncode($normalized['keywords']),
            recipeCatalogJsonEncode($normalized['instructions']),
            recipeCatalogJsonEncode($normalized['instruction_groups']),
            recipeCatalogJsonEncode($normalized['nutrition']),
            $normalized['storage_policy'],
            $normalized['rights_basis'],
            $normalized['cache_expires_at'],
            $normalized['stale_at'],
            $normalized['source_payload'] === null
                ? null
                : recipeCatalogJsonEncode($normalized['source_payload']),
            $normalized['retrieved_at'],
        ];

        if ($recipeId > 0) {
            $update = $db->prepare("
                UPDATE recipe_catalog SET
                    primary_connector = ?, title = ?, description = ?, image_url = ?,
                    language = ?, servings = ?, prep_time = ?, cook_time = ?, total_time = ?,
                    cuisine = ?, category = ?, yield_quantity = ?, yield_unit = ?,
                    prep_time_seconds = ?, cook_time_seconds = ?,
                    active_time_seconds = ?, inactive_time_seconds = ?,
                    total_time_seconds = ?, difficulty = ?, primary_category = ?,
                    devices_json = ?, optional_devices_json = ?,
                    equipment_json = ?, keywords_json = ?,
                    instructions_json = ?, instruction_groups_json = ?,
                    nutrition_json = ?, storage_policy = ?, rights_basis = ?,
                    cache_expires_at = ?, stale_at = ?, source_payload_json = ?,
                    retrieved_at = ?,
                    deleted_at = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $update->execute([...$catalogParams, $recipeId]);
        } else {
            $insert = $db->prepare("
                INSERT INTO recipe_catalog (
                    primary_connector, title, description, image_url, language, servings,
                    prep_time, cook_time, total_time, cuisine, category, yield_quantity,
                    yield_unit, prep_time_seconds, cook_time_seconds,
                    active_time_seconds, inactive_time_seconds, total_time_seconds,
                    difficulty, primary_category, devices_json, optional_devices_json,
                    equipment_json, keywords_json, instructions_json,
                    instruction_groups_json, nutrition_json, storage_policy, rights_basis,
                    cache_expires_at, stale_at,
                    source_payload_json, retrieved_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute($catalogParams);
            $recipeId = (int)$db->lastInsertId();
        }

        if ($originId > 0) {
            $existingOrigin = $db->prepare("
                SELECT external_id, canonical_url, locale
                FROM recipe_origins
                WHERE id = ?
                LIMIT 1
            ");
            $existingOrigin->execute([$originId]);
            $existingOrigin = $existingOrigin->fetch(PDO::FETCH_ASSOC) ?: [];
            $originChanged = trim((string)($existingOrigin['external_id'] ?? ''))
                    !== $origin['external_id']
                || trim((string)($existingOrigin['canonical_url'] ?? ''))
                    !== $origin['canonical_url']
                || strtolower(trim((string)($existingOrigin['locale'] ?? '')))
                    !== strtolower($origin['locale']);
            $clearMetadataFailure = $originChanged
                || (
                    $origin['connector'] === RECIPE_COOKIDOO_CONNECTOR
                    && $origin['metadata_version']
                        === RECIPE_COOKIDOO_METADATA_VERSION
                    && $origin['metadata_schema_version']
                        === RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION
                );
            $failureResetSql = $clearMetadataFailure
                ? ",
                    metadata_failure_version = NULL,
                    metadata_failure_kind = NULL,
                    metadata_failure_at = NULL,
                    metadata_failure_count = 0,
                    metadata_next_probe_at = NULL,
                    metadata_failure_schema_version = NULL"
                : '';
            $updateOrigin = $db->prepare("
                UPDATE recipe_origins SET
                    recipe_id = ?, connector = ?, external_id = NULLIF(?, ''),
                    canonical_url = NULLIF(?, ''), locale = NULLIF(?, ''),
                    content_language = NULLIF(?, ''),
                    attribution = NULLIF(?, ''), license = NULLIF(?, ''),
                    metadata_version = COALESCE(NULLIF(?, ''), metadata_version),
                    metadata_schema_version = COALESCE(
                        NULLIF(?, ''),
                        metadata_schema_version
                    ),
                    availability = ?, last_seen_at = CURRENT_TIMESTAMP
                    {$failureResetSql}
                WHERE id = ?
            ");
            $updateOrigin->execute([
                $recipeId,
                $origin['connector'],
                $origin['external_id'],
                $origin['canonical_url'],
                $origin['locale'],
                $origin['content_language'],
                $origin['attribution'],
                $origin['license'],
                $origin['metadata_version'],
                $origin['metadata_schema_version'],
                $origin['availability'],
                $originId,
            ]);
        } else {
            $insertOrigin = $db->prepare("
                INSERT INTO recipe_origins (
                    recipe_id, connector, external_id, canonical_url, locale,
                    content_language, attribution, license, metadata_version,
                    metadata_schema_version, availability
                )
                VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
                        NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
                        NULLIF(?, ''),
                        NULLIF(?, ''), ?)
            ");
            $insertOrigin->execute([
                $recipeId,
                $origin['connector'],
                $origin['external_id'],
                $origin['canonical_url'],
                $origin['locale'],
                $origin['content_language'],
                $origin['attribution'],
                $origin['license'],
                $origin['metadata_version'],
                $origin['metadata_schema_version'],
                $origin['availability'],
            ]);
        }

        $ingredientInsert = $db->prepare("
            INSERT INTO recipe_ingredients (
                recipe_id, position, raw_text, normalized_name, quantity, quantity_text,
                unit, quantity_parse_json, quantity_parse_version,
                is_required, is_optional, is_staple,
                source_is_required, source_is_optional, requiredness_source,
                canonical_ingredient_id, taxonomy_node_id, mapping_confidence,
                mapping_source
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(recipe_id, position) DO UPDATE SET
                raw_text = excluded.raw_text,
                normalized_name = excluded.normalized_name,
                quantity = excluded.quantity,
                quantity_text = excluded.quantity_text,
                unit = excluded.unit,
                quantity_parse_json = excluded.quantity_parse_json,
                quantity_parse_version = excluded.quantity_parse_version,
                is_required = excluded.is_required,
                is_optional = excluded.is_optional,
                is_staple = excluded.is_staple,
                source_is_required = excluded.source_is_required,
                source_is_optional = excluded.source_is_optional,
                requiredness_source = excluded.requiredness_source,
                canonical_ingredient_id = excluded.canonical_ingredient_id,
                taxonomy_node_id = excluded.taxonomy_node_id,
                mapping_confidence = excluded.mapping_confidence,
                mapping_source = excluded.mapping_source,
                updated_at = CURRENT_TIMESTAMP
        ");
        foreach ($normalized['ingredients'] as $ingredient) {
            $ingredientInsert->execute([
                $recipeId,
                $ingredient['position'],
                $ingredient['raw_text'],
                $ingredient['normalized_name'],
                $ingredient['quantity'],
                $ingredient['quantity_text'],
                $ingredient['unit'],
                $ingredient['quantity_parse_json'],
                $ingredient['quantity_parse_version'],
                $ingredient['is_required'],
                $ingredient['is_optional'],
                $ingredient['is_staple'],
                $ingredient['source_is_required'],
                $ingredient['source_is_optional'],
                $ingredient['requiredness_source'],
                $ingredient['canonical_ingredient_id'],
                $ingredient['taxonomy_node_id'],
                $ingredient['mapping_confidence'],
                $ingredient['mapping_source'],
            ]);
        }
        $db->prepare("
            DELETE FROM recipe_ingredients
            WHERE recipe_id = ? AND position >= ?
        ")->execute([$recipeId, count($normalized['ingredients'])]);

        $sourceIngredientInsert = $db->prepare("
            INSERT INTO recipe_source_ingredients (
                recipe_id, position, name, normalized_name, source_quantity,
                source_quantity_max, source_unit, source_amount_text,
                source_group_index, source_group_position,
                source_group_title, source_ingredient_ref,
                source_default_title, source_unit_ref, source_optional,
                source_shopping_category_ref,
                canonical_ingredient_id, taxonomy_node_id, mapping_confidence,
                mapping_source, mapping_version
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(recipe_id, position) DO UPDATE SET
                name = excluded.name,
                normalized_name = excluded.normalized_name,
                source_quantity = excluded.source_quantity,
                source_quantity_max = excluded.source_quantity_max,
                source_unit = excluded.source_unit,
                source_amount_text = excluded.source_amount_text,
                source_group_index = excluded.source_group_index,
                source_group_position = excluded.source_group_position,
                source_group_title = excluded.source_group_title,
                source_ingredient_ref = excluded.source_ingredient_ref,
                source_default_title = excluded.source_default_title,
                source_unit_ref = excluded.source_unit_ref,
                source_optional = excluded.source_optional,
                source_shopping_category_ref =
                    excluded.source_shopping_category_ref,
                canonical_ingredient_id = excluded.canonical_ingredient_id,
                taxonomy_node_id = excluded.taxonomy_node_id,
                mapping_confidence = excluded.mapping_confidence,
                mapping_source = excluded.mapping_source,
                mapping_version = excluded.mapping_version,
                updated_at = CURRENT_TIMESTAMP
        ");
        foreach ($normalized['source_ingredients'] as $ingredient) {
            $sourceIngredientInsert->execute([
                $recipeId,
                $ingredient['position'],
                $ingredient['name'],
                $ingredient['normalized_name'],
                $ingredient['source_quantity'],
                $ingredient['source_quantity_max'],
                $ingredient['source_unit'],
                $ingredient['source_amount_text'],
                $ingredient['source_group_index'],
                $ingredient['source_group_position'],
                $ingredient['source_group_title'],
                $ingredient['source_ingredient_ref'],
                $ingredient['source_default_title'],
                $ingredient['source_unit_ref'],
                $ingredient['source_optional'],
                $ingredient['source_shopping_category_ref'],
                $ingredient['canonical_ingredient_id'],
                $ingredient['taxonomy_node_id'],
                $ingredient['mapping_confidence'],
                $ingredient['mapping_source'],
                $ingredient['mapping_version'],
            ]);
        }
        $db->prepare("
            DELETE FROM recipe_source_ingredients
            WHERE recipe_id = ? AND position >= ?
        ")->execute([$recipeId, count($normalized['source_ingredients'])]);

        $db->prepare("
            INSERT OR IGNORE INTO recipe_user_state (recipe_id)
            VALUES (?)
        ")->execute([$recipeId]);
        recipeCatalogRebuildCluster($db, $recipeId);

        recipeSearchRebuildDocument($db, $recipeId);
        if (function_exists('recipeScoreMarkCatalogDirty')) {
            recipeScoreMarkCatalogDirty($db, !$isNewRecipe);
        }
        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $saved = recipeCatalogGetById($db, $recipeId, true);
    if ($saved === null) {
        throw new RuntimeException('Recipe catalog save could not be read back');
    }
    return $saved;
    } finally {
        recipeCatalogSaveUnlock($saveLock);
    }
}

function recipeCatalogDecodeRow(array $row): array {
    foreach ([
        'devices_json' => 'devices',
        'optional_devices_json' => 'optional_devices',
        'equipment_json' => 'equipment',
        'keywords_json' => 'keywords',
        'instructions_json' => 'instructions',
        'instruction_groups_json' => 'instruction_groups',
        'nutrition_json' => 'nutrition',
        'source_payload_json' => 'source_payload',
    ] as $jsonKey => $outputKey) {
        $decoded = json_decode((string)($row[$jsonKey] ?? ''), true);
        $row[$outputKey] = is_array($decoded) ? $decoded : [];
        unset($row[$jsonKey]);
    }
    $row['id'] = (int)$row['id'];
    $row['servings'] = $row['servings'] !== null ? (int)$row['servings'] : null;
    $row['yield_quantity'] = $row['yield_quantity'] !== null
        ? (float)$row['yield_quantity']
        : null;
    $row['prep_time_seconds'] = $row['prep_time_seconds'] !== null
        ? (int)$row['prep_time_seconds']
        : null;
    $row['cook_time_seconds'] = $row['cook_time_seconds'] !== null
        ? (int)$row['cook_time_seconds']
        : null;
    $row['active_time_seconds'] = $row['active_time_seconds'] !== null
        ? (int)$row['active_time_seconds']
        : null;
    $row['inactive_time_seconds'] = $row['inactive_time_seconds'] !== null
        ? (int)$row['inactive_time_seconds']
        : null;
    $row['total_time_seconds'] = $row['total_time_seconds'] !== null
        ? (int)$row['total_time_seconds']
        : null;
    $row['favorite'] = !empty($row['favorite']);
    $row['hidden'] = !empty($row['hidden']);
    $row['is_stale'] = !empty($row['is_stale']);
    $row['rating'] = $row['rating'] !== null ? (int)$row['rating'] : null;
    $row['cooked_count'] = (int)($row['cooked_count'] ?? 0);
    $row['cluster_confidence'] = $row['cluster_confidence'] !== null
        ? (float)$row['cluster_confidence']
        : null;
    return $row;
}

function recipeCatalogGetById(PDO $db, int $recipeId, bool $includeDeleted = false): ?array {
    $languageVisibility = $includeDeleted
        ? ''
        : recipeCookidooLanguageVisibilitySql('c');
    $stmt = $db->prepare("
        SELECT c.*, COALESCE(s.favorite, 0) AS favorite, COALESCE(s.hidden, 0) AS hidden,
               s.rating, COALESCE(s.note, '') AS note,
               COALESCE(s.cooked_count, 0) AS cooked_count, s.last_cooked,
               cl.cluster_key, cl.method AS cluster_method, cl.confidence AS cluster_confidence,
               CASE
                   WHEN c.stale_at IS NOT NULL AND c.stale_at < CURRENT_TIMESTAMP
                   THEN 1 ELSE 0
               END AS is_stale
        FROM recipe_catalog c
        LEFT JOIN recipe_user_state s ON s.recipe_id = c.id
        LEFT JOIN recipe_clusters cl ON cl.recipe_id = c.id
        WHERE c.id = ?"
            . ($includeDeleted ? '' : ' AND c.deleted_at IS NULL')
            . $languageVisibility . "
        LIMIT 1
    ");
    $stmt->execute([$recipeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $recipe = recipeCatalogDecodeRow($row);

    $origins = $db->prepare("
        SELECT connector, external_id, canonical_url, locale,
               content_language, attribution, license,
               metadata_version, metadata_schema_version,
               first_seen_at, last_seen_at, availability
        FROM recipe_origins
        WHERE recipe_id = ?
        ORDER BY id
    ");
    $origins->execute([$recipeId]);
    $recipe['origins'] = $origins->fetchAll(PDO::FETCH_ASSOC);
    $ingredientParseLocale = (string)($recipe['language'] ?? 'und');
    foreach ($recipe['origins'] as $origin) {
        if (
            (string)($origin['connector'] ?? '')
            !== (string)($recipe['primary_connector'] ?? '')
        ) {
            continue;
        }
        $primaryOriginLocale = recipeQuantityNormalizeLocale(
            (string)($origin['locale'] ?? '')
        );
        if ($primaryOriginLocale !== 'und') {
            $ingredientParseLocale = $primaryOriginLocale;
        }
        break;
    }

    $ingredients = $db->prepare("
        SELECT ri.position, ri.raw_text, ri.normalized_name, ri.quantity,
               ri.quantity_text, ri.unit, ri.quantity_parse_json,
               ri.quantity_parse_version,
               ri.is_required, ri.is_optional, ri.is_staple,
               ri.canonical_ingredient_id, ci.slug AS canonical_slug,
               ci.name AS canonical_name, ri.taxonomy_node_id,
               tn.slug AS taxonomy_slug, tn.name AS taxonomy_name,
               ri.mapping_confidence, ri.mapping_source
        FROM recipe_ingredients ri
        LEFT JOIN canonical_ingredients ci ON ci.id = ri.canonical_ingredient_id
        LEFT JOIN taxonomy_nodes tn ON tn.id = ri.taxonomy_node_id
        WHERE ri.recipe_id = ?
        ORDER BY ri.position
    ");
    $ingredients->execute([$recipeId]);
    $recipe['ingredients'] = array_map(static function (
        array $ingredient
    ) use ($ingredientParseLocale): array {
        $ingredient['position'] = (int)$ingredient['position'];
        $ingredient['quantity'] = $ingredient['quantity'] !== null ? (float)$ingredient['quantity'] : null;
        $ingredient['quantity_parse'] = recipeQuantityDecodePersistedResult(
            $ingredient['quantity_parse_json'] ?? null,
            (string)$ingredient['raw_text'],
            $ingredientParseLocale,
            $ingredient['quantity_parse_version'] ?? null
        );
        unset($ingredient['quantity_parse_json']);
        $ingredient['quantity_parse_version'] =
            $ingredient['quantity_parse']['parser_version'] ?? null;
        $ingredient['is_required'] = (bool)$ingredient['is_required'];
        $ingredient['is_optional'] = (bool)$ingredient['is_optional'];
        $ingredient['is_staple'] = (bool)$ingredient['is_staple'];
        $ingredient['canonical_ingredient_id'] = $ingredient['canonical_ingredient_id'] !== null
            ? (int)$ingredient['canonical_ingredient_id']
            : null;
        $ingredient['taxonomy_node_id'] = $ingredient['taxonomy_node_id'] !== null
            ? (int)$ingredient['taxonomy_node_id']
            : null;
        $ingredient['mapping_confidence'] = (float)$ingredient['mapping_confidence'];
        return $ingredient;
    }, $ingredients->fetchAll(PDO::FETCH_ASSOC));
    return $recipe;
}

function recipeCatalogDelete(PDO $db, int $recipeId): bool {
    $saveLock = recipeCatalogSaveLock();
    try {
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        try {
            $stmt = $db->prepare("
                UPDATE recipe_catalog
                SET deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$recipeId]);
            if ($stmt->rowCount() <= 0) {
                if ($ownsTransaction) {
                    $db->commit();
                }
                return false;
            }
            $db->prepare("DELETE FROM recipe_search_documents WHERE recipe_id = ?")->execute([$recipeId]);
            $db->prepare("
                UPDATE recipes
                SET catalog_recipe_id = NULL
                WHERE catalog_recipe_id = ?
            ")->execute([$recipeId]);
            if (function_exists('recipeScoreMarkCatalogDirty')) {
                recipeScoreMarkCatalogDirty($db, true);
            }
            if ($ownsTransaction) {
                $db->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    } finally {
        recipeCatalogSaveUnlock($saveLock);
    }
}

function recipeCatalogSetFavorite(PDO $db, int $recipeId, ?bool $favorite = null): bool {
    $exists = $db->prepare("SELECT id FROM recipe_catalog WHERE id = ? AND deleted_at IS NULL");
    $exists->execute([$recipeId]);
    if (!$exists->fetchColumn()) {
        throw new OutOfBoundsException('Recipe not found');
    }
    $db->prepare("INSERT OR IGNORE INTO recipe_user_state (recipe_id) VALUES (?)")->execute([$recipeId]);
    if ($favorite === null) {
        $db->prepare("
            UPDATE recipe_user_state
            SET favorite = 1 - favorite, updated_at = CURRENT_TIMESTAMP
            WHERE recipe_id = ?
        ")->execute([$recipeId]);
    } else {
        $db->prepare("
            UPDATE recipe_user_state
            SET favorite = ?, updated_at = CURRENT_TIMESTAMP
            WHERE recipe_id = ?
        ")->execute([$favorite ? 1 : 0, $recipeId]);
    }
    if (function_exists('recipeScoreMarkCatalogDirty')) {
        recipeScoreMarkCatalogDirty($db, true);
    }
    $read = $db->prepare("SELECT favorite FROM recipe_user_state WHERE recipe_id = ?");
    $read->execute([$recipeId]);
    $value = (bool)$read->fetchColumn();
    return $value;
}

function recipeCatalogPersistGenerated(PDO $db, array $recipe, array $metadata = []): array {
    return recipeCatalogSaveVariant($db, $recipe, array_merge([
        'connector' => 'generated',
        'rights_basis' => 'generated_for_user',
        'storage_policy' => 'persistent',
    ], $metadata));
}

function recipeLegacyCleanup(PDO $db, int $recipeDays): int {
    $stmt = $db->prepare("
        DELETE FROM recipes
        WHERE date < date('now', ? || ' days')
          AND COALESCE(is_favorite, 0) = 0
    ");
    $stmt->execute(['-' . max(1, $recipeDays)]);
    return $stmt->rowCount();
}
