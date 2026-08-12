<?php

const RECIPE_SOURCE_MAPPING_VERSION_LEGACY = 'legacy-v1';

function recipeIngredientActiveMappingVersion(): string {
    return RECIPE_SOURCE_MAPPING_VERSION_LEGACY;
}

function recipeIngredientNormalizeMappingVersion(mixed $value): string {
    if (!is_string($value)) {
        throw new InvalidArgumentException('mapping_version must be a string');
    }
    $value = trim($value);
    if (
        $value === ''
        || strlen($value) > 40
        || !preg_match('/^[a-z0-9][a-z0-9._-]*$/', $value)
    ) {
        throw new InvalidArgumentException('mapping_version is invalid');
    }
    return $value;
}

function recipeIngredientMappingSourceIsIdentitySafe(?string $source): bool {
    return in_array(
        strtolower(trim((string)$source)),
        ['taxonomy_alias', 'taxonomy_slug', 'canonical_slug'],
        true
    );
}

function recipeIngredientBoundedSourceText(
    mixed $value,
    mixed $fallback = ''
): string {
    $clean = static function (mixed $candidate): string {
        if (!is_string($candidate)) {
            return '';
        }
        $candidate = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $candidate)
            ?? $candidate;
        $candidate = trim(preg_replace('/\s+/u', ' ', $candidate) ?? '');
        return mb_substr($candidate, 0, 200, 'UTF-8');
    };
    $text = $clean($value);
    if ($text === '') {
        $text = $clean($fallback);
    }
    return $text !== '' ? $text : 'Ingredient';
}

function recipeIngredientDisplayDescriptor(string $value): bool {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = str_replace(['–', '—', '-'], ' ', $value);
    $value = trim(preg_replace('/[.;:]+$/u', '', $value) ?? $value);
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    if ($value === '') {
        return false;
    }
    static $descriptors = [
        'air chilled' => true,
        'at room temperature' => true,
        'atta' => true,
        'boneless' => true,
        'boneless and skinless' => true,
        'chopped' => true,
        'coarsely chopped' => true,
        'cored' => true,
        'crushed' => true,
        'deseeded' => true,
        'diced' => true,
        'divided' => true,
        'drained' => true,
        'finely chopped' => true,
        'finely diced' => true,
        'finely ground' => true,
        'finely sliced' => true,
        'for garnish' => true,
        'for serving' => true,
        'minced' => true,
        'optional' => true,
        'peeled' => true,
        'rinsed' => true,
        'rinsed and drained' => true,
        'room temperature' => true,
        'roughly chopped' => true,
        'seeded' => true,
        'bone in' => true,
        'skin on' => true,
        'skin on and bone in' => true,
        'skinless' => true,
        'sliced' => true,
        'softened' => true,
        'thawed' => true,
        'thinly sliced' => true,
        'to serve' => true,
        'to taste' => true,
        'trimmed' => true,
    ];
    return isset($descriptors[$value]);
}

function recipeIngredientDisplayUnitVocabulary(): array {
    static $displayUnits = [
        'bottle' => true,
        'bottles' => true,
        'bunch' => true,
        'bunches' => true,
        'can' => true,
        'cans' => true,
        'centiliter' => true,
        'centiliters' => true,
        'centilitre' => true,
        'centilitres' => true,
        'centilitro' => true,
        'centilitri' => true,
        'chilogrammi' => true,
        'chilogrammo' => true,
        'cl' => true,
        'conf' => true,
        'confezione' => true,
        'confezioni' => true,
        'cup' => true,
        'cups' => true,
        'deciliter' => true,
        'deciliters' => true,
        'decilitre' => true,
        'decilitres' => true,
        'decilitro' => true,
        'decilitri' => true,
        'dl' => true,
        'g' => true,
        'gr' => true,
        'gram' => true,
        'grammi' => true,
        'grammo' => true,
        'grams' => true,
        'jar' => true,
        'jars' => true,
        'kg' => true,
        'kilogram' => true,
        'kilograms' => true,
        'l' => true,
        'lb' => true,
        'lbs' => true,
        'liter' => true,
        'liters' => true,
        'litre' => true,
        'litres' => true,
        'litri' => true,
        'litro' => true,
        'mg' => true,
        'milligram' => true,
        'milligrams' => true,
        'milliliter' => true,
        'milliliters' => true,
        'millilitre' => true,
        'millilitres' => true,
        'millilitri' => true,
        'millilitro' => true,
        'ml' => true,
        'ounce' => true,
        'ounces' => true,
        'oz' => true,
        'pack' => true,
        'package' => true,
        'packages' => true,
        'packs' => true,
        'pc' => true,
        'pcs' => true,
        'pezzi' => true,
        'pezzo' => true,
        'piece' => true,
        'pieces' => true,
        'pound' => true,
        'pounds' => true,
        'pz' => true,
        'tablespoon' => true,
        'tablespoons' => true,
        'tbsp' => true,
        'teaspoon' => true,
        'teaspoons' => true,
        'tsp' => true,
        'tin' => true,
        'tins' => true,
    ];
    return $displayUnits;
}

function recipeIngredientDisplayUnitIsSafe(string $unit): bool {
    $unit = recipeIngredientNormalizeName($unit);
    return $unit !== ''
        && isset(recipeIngredientDisplayUnitVocabulary()[$unit]);
}

function recipeIngredientDisplayUnitPattern(): string {
    static $pattern = null;
    if ($pattern === null) {
        $units = array_keys(recipeIngredientDisplayUnitVocabulary());
        usort(
            $units,
            static fn(string $left, string $right): int =>
                strlen($right) <=> strlen($left)
        );
        $pattern = implode('|', array_map(
            static fn(string $unit): string => preg_quote($unit, '/'),
            $units
        ));
    }
    return $pattern;
}

function recipeIngredientStripLeadingAmount(
    string $value,
    array $parsedAmount = []
): string {
    $advisoryParse = $parsedAmount['parse'] ?? null;
    if (
        is_array($advisoryParse)
        && ($advisoryParse['source_text'] ?? null) === $value
        && in_array(
            $advisoryParse['status'] ?? null,
            ['parsed', 'ambiguous', 'not_present'],
            true
        )
        && is_string($advisoryParse['ingredient'] ?? null)
        && trim($advisoryParse['ingredient']) !== ''
    ) {
        return trim($advisoryParse['ingredient']);
    }
    $number = recipeQuantityNumberPattern();
    $quantity = $number
        . '(?:\s*(?:-|–|—|to)\s*' . $number . ')?';

    if (!preg_match(
        '/^\s*(' . $quantity . ')\s+('
            . recipeIngredientDisplayUnitPattern()
            . ')\.?\s+(.+)$/iu',
        $value,
        $match
    )) {
        return $value;
    }
    if (!recipeIngredientDisplayUnitIsSafe((string)$match[2])) {
        return $value;
    }
    $amountText = trim((string)($parsedAmount['text'] ?? ''));
    if (
        $amountText !== ''
        && preg_match(
            '/^\s*(' . $quantity . ')(?:\s|$)/iu',
            $amountText,
            $expected
        )
    ) {
        $normalizeQuantity = static function (string $candidate): string {
            $candidate = mb_strtolower($candidate, 'UTF-8');
            $candidate = str_replace(
                ['–', '—', ' to ', ',', "'", '’'],
                ['-', '-', '-', '.', '', ''],
                $candidate
            );
            return preg_replace(
                '/[\s\x{00A0}\x{202F}]+/u',
                '',
                $candidate
            ) ?? $candidate;
        };
        if (
            $normalizeQuantity((string)$expected[1])
            !== $normalizeQuantity((string)$match[1])
        ) {
            return $value;
        }
    }
    return trim((string)$match[3]);
}

function recipeIngredientIdentityCandidate(
    string $value,
    array $parsedAmount = []
): string {
    $candidate = trim(recipeIngredientStripLeadingAmount(
        $value,
        $parsedAmount
    ));
    if (
        $candidate === ''
        || !preg_match('/[\p{L}\p{N}]/u', $candidate)
    ) {
        return trim($value);
    }
    return $candidate;
}

function recipeIngredientDisplayName(
    string $sourceText,
    string $fallback = '',
    bool $stripLeadingAmount = false,
    array $parsedAmount = []
): string {
    $sourceText = recipeIngredientBoundedSourceText($sourceText, $fallback);
    $display = recipeIngredientIdentityCandidate(
        $sourceText,
        $stripLeadingAmount ? $parsedAmount : []
    );
    $display = preg_replace_callback(
        '/\s*\(([^()]*)\)/u',
        static fn(array $match): string =>
            recipeIngredientDisplayDescriptor((string)$match[1])
                ? ''
                : (string)$match[0],
        $display
    ) ?? $display;

    $clauses = preg_split('/\s*,\s*/u', $display) ?: [$display];
    if (count($clauses) > 1) {
        for ($index = 1; $index < count($clauses); $index++) {
            $tail = array_slice($clauses, $index);
            if (
                $tail
                && count(array_filter(
                    $tail,
                    static fn(string $clause): bool =>
                        recipeIngredientDisplayDescriptor($clause)
                )) === count($tail)
            ) {
                $display = implode(', ', array_slice($clauses, 0, $index));
                break;
            }
        }
    }

    $display = preg_replace('/\ball[\s-]+purpose\b/iu', 'all-purpose', $display)
        ?? $display;
    $display = preg_replace('/\s+([,;:!?])/u', '$1', $display) ?? $display;
    $display = preg_replace('/([(\[])\s+/u', '$1', $display) ?? $display;
    $display = preg_replace('/\s+([)\]])/u', '$1', $display) ?? $display;
    $display = trim(preg_replace('/\s+/u', ' ', $display) ?? '');
    if ($display === '' || !preg_match('/[\p{L}\p{N}]/u', $display)) {
        $display = $sourceText;
    }
    $display = mb_convert_case($display, MB_CASE_TITLE, 'UTF-8');
    $display = trim(mb_substr($display, 0, 200, 'UTF-8'));
    return $display !== '' ? $display : $sourceText;
}

function recipeIngredientNormalizeName(string $name): string {
    if (function_exists('canonicalIngredientNormalizeText')) {
        return canonicalIngredientNormalizeText($name);
    }
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name) ?? $name;
    return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
}

function recipeIngredientSlug(string $name): string {
    if (function_exists('canonicalIngredientSlug')) {
        return canonicalIngredientSlug($name);
    }
    $slug = recipeIngredientNormalizeName($name);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if (is_string($ascii) && $ascii !== '') {
            $slug = strtolower($ascii);
        }
    }
    return trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug, '-');
}

function recipeIngredientIsStaple(string $name): bool {
    $name = recipeIngredientNormalizeName($name);
    return (bool)preg_match(
        '/^(water|acqua|salt|sale|pepper|pepe|olio|oil|olive oil|olio d oliva)\b/u',
        $name
    );
}

/**
 * Resolve a recipe ingredient against aliases, canonical slugs, and local rules.
 * An unresolved ingredient is valid and remains searchable by normalized name.
 */
function recipeIngredientResolve(PDO $db, string $name): array {
    $normalized = recipeIngredientNormalizeName($name);
    $slug = recipeIngredientSlug($name);
    $result = [
        'normalized_name' => $normalized,
        'canonical_ingredient_id' => null,
        'taxonomy_node_id' => null,
        'confidence' => 0.0,
        'source' => 'unresolved',
    ];
    if ($normalized === '') {
        return $result;
    }

    $treeId = (int)($db->query(
        "SELECT id FROM taxonomy_trees WHERE slug = 'food' LIMIT 1"
    )->fetchColumn() ?: 0);

    if ($treeId > 0) {
        $alias = $db->prepare("
            SELECT n.id, n.slug
            FROM taxonomy_aliases a
            JOIN taxonomy_nodes n ON n.id = a.node_id AND n.active = 1
            WHERE a.tree_id = ? AND a.normalized_alias = ? AND a.active = 1
            ORDER BY a.id ASC
            LIMIT 1
        ");
        $alias->execute([$treeId, $normalized]);
        $node = $alias->fetch(PDO::FETCH_ASSOC);
        if ($node) {
            $result['taxonomy_node_id'] = (int)$node['id'];
            $slug = (string)$node['slug'];
            $result['confidence'] = 0.98;
            $result['source'] = 'taxonomy_alias';
        } else {
            $nodeStmt = $db->prepare("
                SELECT id, slug
                FROM taxonomy_nodes
                WHERE tree_id = ? AND active = 1
                  AND (slug = ? OR lower(name) = lower(?))
                ORDER BY CASE WHEN slug = ? THEN 0 ELSE 1 END, id ASC
                LIMIT 1
            ");
            $nodeStmt->execute([$treeId, $slug, $name, $slug]);
            $node = $nodeStmt->fetch(PDO::FETCH_ASSOC);
            if ($node) {
                $result['taxonomy_node_id'] = (int)$node['id'];
                $slug = (string)$node['slug'];
                $result['confidence'] = 0.96;
                $result['source'] = 'taxonomy_slug';
            }
        }
    }

    $canonical = $db->prepare("
        SELECT id, slug
        FROM canonical_ingredients
        WHERE slug = ? OR lower(name) = lower(?)
        ORDER BY CASE WHEN slug = ? THEN 0 ELSE 1 END, id ASC
        LIMIT 1
    ");
    $canonical->execute([$slug, $name, $slug]);
    $canonicalRow = $canonical->fetch(PDO::FETCH_ASSOC);
    if ($canonicalRow) {
        $result['canonical_ingredient_id'] = (int)$canonicalRow['id'];
        $slug = (string)$canonicalRow['slug'];
        if ($result['confidence'] < 0.96) {
            $result['confidence'] = 0.96;
            $result['source'] = 'canonical_slug';
        }
    }

    if ($result['taxonomy_node_id'] === null && $treeId > 0 && $slug !== '') {
        $nodeStmt = $db->prepare("
            SELECT id FROM taxonomy_nodes
            WHERE tree_id = ? AND slug = ? AND active = 1
            LIMIT 1
        ");
        $nodeStmt->execute([$treeId, $slug]);
        $nodeId = (int)($nodeStmt->fetchColumn() ?: 0);
        if ($nodeId > 0) {
            $result['taxonomy_node_id'] = $nodeId;
        }
    }

    if (
        $result['canonical_ingredient_id'] === null
        && $result['taxonomy_node_id'] === null
        && function_exists('canonicalIngredientInferProduct')
    ) {
        $mappings = canonicalIngredientInferProduct(['name' => $name], $db);
        if ($treeId <= 0) {
            $treeId = (int)($db->query(
                "SELECT id FROM taxonomy_trees WHERE slug = 'food' LIMIT 1"
            )->fetchColumn() ?: 0);
        }
        foreach ($mappings as $mapping) {
            if (($mapping['role'] ?? '') !== 'primary') {
                continue;
            }
            $ruleSlug = (string)($mapping['slug'] ?? '');
            if ($ruleSlug === '') {
                continue;
            }
            $canonical->execute([$ruleSlug, (string)($mapping['name'] ?? $name), $ruleSlug]);
            $canonicalRow = $canonical->fetch(PDO::FETCH_ASSOC);
            if ($canonicalRow) {
                $result['canonical_ingredient_id'] = (int)$canonicalRow['id'];
            }
            if ($treeId > 0) {
                $nodeStmt = $db->prepare("
                    SELECT id FROM taxonomy_nodes
                    WHERE tree_id = ? AND slug = ? AND active = 1
                    LIMIT 1
                ");
                $nodeStmt->execute([$treeId, $ruleSlug]);
                $nodeId = (int)($nodeStmt->fetchColumn() ?: 0);
                if ($nodeId > 0) {
                    $result['taxonomy_node_id'] = $nodeId;
                }
            }
            if ($result['canonical_ingredient_id'] !== null || $result['taxonomy_node_id'] !== null) {
                $result['confidence'] = (float)($mapping['confidence'] ?? 0.8);
                $result['source'] = 'taxonomy_rule';
            }
            break;
        }
    }

    return $result;
}

function recipeIngredientResolveForMappingVersion(
    PDO $db,
    string $name,
    string $mappingVersion
): array {
    $mappingVersion = recipeIngredientNormalizeMappingVersion($mappingVersion);
    if ($mappingVersion !== recipeIngredientActiveMappingVersion()) {
        throw new InvalidArgumentException(
            'unsupported source ingredient mapping version'
        );
    }
    return recipeIngredientResolve($db, $name);
}

function recipeIngredientNormalizeLegacyBoundedText(
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
    if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new InvalidArgumentException(
            $field . ' contains control characters'
        );
    }
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value, 'UTF-8') > $maximum) {
        throw new InvalidArgumentException($field . ' is too long');
    }
    return $value;
}

function recipeIngredientNormalizeRow(
    PDO $db,
    mixed $ingredient,
    int $position,
    string $sourceConnector = 'manual',
    string $locale = 'und'
): array {
    if (is_string($ingredient)) {
        $rawText = trim($ingredient);
        $name = $rawText;
        $input = [];
    } elseif (is_array($ingredient)) {
        $input = $ingredient;
        $name = trim((string)($input['name'] ?? $input['ingredient'] ?? $input['normalized_name'] ?? ''));
        $rawText = trim((string)($input['raw_text'] ?? ''));
        if ($rawText === '') {
            $rawText = trim(implode(' ', array_filter([
                (string)($input['qty'] ?? $input['quantity_text'] ?? $input['quantity'] ?? ''),
                (string)($input['unit'] ?? ''),
                $name,
            ], static fn(string $value): bool => $value !== '')));
        }
    } else {
        $input = [];
        $name = '';
        $rawText = '';
    }

    $inputUnit = recipeIngredientNormalizeLegacyBoundedText(
        $input['unit'] ?? null,
        'ingredient unit',
        80
    );
    $explicitQuantityText = $input['quantity_text'] ?? null;
    if (
        $explicitQuantityText === null
        && isset($input['qty'])
        && is_string($input['qty'])
    ) {
        $explicitQuantityText = $input['qty'];
    }
    if (
        $explicitQuantityText === null
        && isset($input['quantity'])
        && is_string($input['quantity'])
    ) {
        $explicitQuantityText = $input['quantity'];
    }
    $explicitQuantityText = recipeIngredientNormalizeLegacyBoundedText(
        $explicitQuantityText,
        'ingredient quantity_text',
        160
    );
    $quantityValue = $input['qty_number'] ?? $input['quantity'] ?? $input['qty'] ?? null;
    $parsed = recipeIngredientParseQuantity($quantityValue, $inputUnit);
    if ($explicitQuantityText !== null) {
        $parsed['quantity_text'] = $explicitQuantityText;
    }
    if (isset($input['qty']) && is_string($input['qty'])) {
        $qtyParsed = recipeIngredientParseQuantity($input['qty'], $parsed['unit']);
        $parsed['quantity_text'] = $explicitQuantityText;
        if ($parsed['quantity'] === null) {
            $parsed['quantity'] = $qtyParsed['quantity'];
        }
        if ($parsed['unit'] === null || $parsed['unit'] === '') {
            $parsed['unit'] = $qtyParsed['unit'];
        }
    }
    $parsed['quantity_text'] = recipeIngredientNormalizeLegacyBoundedText(
        $parsed['quantity_text'],
        'ingredient quantity_text',
        160
    );
    $parsed['unit'] = recipeIngredientNormalizeLegacyBoundedText(
        $parsed['unit'],
        'ingredient unit',
        80
    );

    $quantityParse = strtolower(trim($sourceConnector)) !== 'cookidoo'
        && $rawText !== ''
            ? recipeQuantityParseText($rawText, $locale)
            : null;
    $identityParse = strtolower(trim($sourceConnector)) !== 'cookidoo'
        && $name !== ''
            ? recipeQuantityParseText($name, $locale)
            : null;
    $identityName = recipeIngredientIdentityCandidate($name, [
        'text' => $parsed['quantity_text'],
        'unit' => $parsed['unit'],
    ]);
    if (
        is_array($identityParse)
        && in_array(
            $identityParse['status'],
            ['parsed', 'not_present', 'ambiguous'],
            true
        )
        && trim((string)($identityParse['ingredient'] ?? '')) !== ''
    ) {
        $identityName = (string)$identityParse['ingredient'];
    }
    $quantityParseJson = null;
    if (
        is_array($quantityParse)
        && is_string($quantityParse['source_text'] ?? null)
    ) {
        $quantityParseJson = recipeQuantityEncodeResult($quantityParse);
    }
    if ($quantityParseJson === null) {
        $quantityParse = null;
    }
    $isOptional = !empty($input['optional']) || !empty($input['is_optional']);
    $hasExplicitRequired = array_key_exists('required', $input)
        || array_key_exists('is_required', $input);
    $sourceIsRequired = array_key_exists('required', $input)
        ? (bool)$input['required']
        : (
            array_key_exists('is_required', $input)
                ? (bool)$input['is_required']
                : !$isOptional
        );
    if ($isOptional) {
        $sourceIsRequired = false;
    }
    $requirednessSource = $hasExplicitRequired
        ? 'explicit_required'
        : ($isOptional ? 'explicit_optional' : 'default_required');
    $isStaple = !empty($input['staple'])
        || !empty($input['is_staple'])
        || recipeIngredientIsStaple($identityName);
    $isRequired = $sourceIsRequired;
    if ($isOptional || $isStaple) {
        $isRequired = false;
    }

    $resolution = recipeIngredientResolve($db, $identityName);
    return [
        'position' => $position,
        'raw_text' => $rawText,
        'normalized_name' => $resolution['normalized_name'],
        'quantity' => $parsed['quantity'],
        'quantity_text' => $parsed['quantity_text'],
        'unit' => $parsed['unit'],
        'quantity_parse_json' => $quantityParseJson,
        'quantity_parse_version' => $quantityParseJson !== null
            ? (string)$quantityParse['parser_version']
            : null,
        'quantity_parse' => $quantityParse,
        'is_required' => $isRequired ? 1 : 0,
        'is_optional' => $isOptional ? 1 : 0,
        'is_staple' => $isStaple ? 1 : 0,
        'source_is_required' => $sourceIsRequired ? 1 : 0,
        'source_is_optional' => $isOptional ? 1 : 0,
        'requiredness_source' => $requirednessSource,
        'canonical_ingredient_id' => $resolution['canonical_ingredient_id'],
        'taxonomy_node_id' => $resolution['taxonomy_node_id'],
        'mapping_confidence' => $resolution['confidence'],
        'mapping_source' => $resolution['source'],
    ];
}

function recipeIngredientNormalizeSourceNumber(mixed $value, string $field): ?float {
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

function recipeIngredientNormalizeSourceText(
    mixed $value,
    string $field,
    int $maximum,
    bool $required = false
): string {
    if (!is_string($value)) {
        throw new InvalidArgumentException($field . ' must be a string');
    }
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($required && $value === '') {
        throw new InvalidArgumentException($field . ' is required');
    }
    if (mb_strlen($value, 'UTF-8') > $maximum) {
        throw new InvalidArgumentException($field . ' is too long');
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new InvalidArgumentException($field . ' contains control characters');
    }
    return $value;
}

function recipeIngredientNormalizeSourceOrdinal(
    mixed $value,
    string $field,
    int $default
): int {
    if ($value === null || $value === '') {
        return $default;
    }
    if (
        is_bool($value)
        || (!is_int($value) && !(is_string($value) && ctype_digit($value)))
    ) {
        throw new InvalidArgumentException($field . ' must be an integer');
    }
    $value = (int)$value;
    if ($value < 0 || $value > 199) {
        throw new InvalidArgumentException($field . ' is out of range');
    }
    return $value;
}

function recipeIngredientNormalizeSourceReference(
    mixed $value,
    string $field
): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $value = recipeIngredientNormalizeSourceText(
        $value,
        $field,
        200,
        true
    );
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value)) {
        throw new InvalidArgumentException($field . ' is invalid');
    }
    return $value;
}

function recipeIngredientNormalizeSourceOptional(mixed $value): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if (is_int($value) && ($value === 0 || $value === 1)) {
        return $value;
    }
    throw new InvalidArgumentException(
        'source_optional must be a nullable boolean'
    );
}

function recipeIngredientSourceAmountNumbersMatch(
    ?float $left,
    ?float $right
): bool {
    if ($left === null || $right === null) {
        return $left === $right;
    }
    return abs($left - $right)
        <= 1e-9 * max(1.0, abs($left), abs($right));
}

function recipeIngredientValidateSourceAmountText(
    string $amountText,
    ?float $quantity,
    ?float $quantityMax,
    ?string $unit
): ?string {
    if ($amountText === '') {
        return null;
    }
    if ($quantity !== null) {
        $vulgar = '[¼½¾⅐⅑⅒⅓⅔⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞]';
        $number = '(?:'
            . '\d+\s+\d+\s*\/\s*\d+'
            . '|\d+\s*' . $vulgar
            . '|\d+\s*\/\s*\d+'
            . '|\d+(?:\.\d+)?'
            . '|' . $vulgar
            . ')';
        $unitPattern = recipeQuantityUnitPattern();
        if (!preg_match(
            '/^(?<quantity>' . $number . ')'
                . '(?:\s*(?:-|–|—)\s*'
                . '(?<quantity_max>' . $number . '))?'
                . '(?:\s*(?<unit>' . $unitPattern . '))?$/iu',
            $amountText,
            $match
        )) {
            throw new InvalidArgumentException(
                'source_amount_text is inconsistent with structured quantity'
            );
        }
        $parsedQuantity = recipeQuantityParseNumberToken(
            (string)$match['quantity'],
            'en-US'
        );
        $parsedQuantityMax = isset($match['quantity_max'])
            && trim((string)$match['quantity_max']) !== ''
            ? recipeQuantityParseNumberToken(
                (string)$match['quantity_max'],
                'en-US'
            )
            : null;
        $expectedUnit = trim((string)$unit) !== ''
            ? recipeQuantityCanonicalUnit((string)$unit)
            : null;
        $parsedUnit = isset($match['unit'])
            && trim((string)$match['unit']) !== ''
                ? recipeQuantityCanonicalUnit((string)$match['unit'])
                : null;
        if (
            $parsedQuantity === null
            || !recipeIngredientSourceAmountNumbersMatch(
                $quantity,
                $parsedQuantity
            )
            || !recipeIngredientSourceAmountNumbersMatch(
                $quantityMax,
                $parsedQuantityMax
            )
            || $expectedUnit !== $parsedUnit
            || (
                trim((string)$unit) !== ''
                && $expectedUnit === null
            )
            || recipeQuantityFormatNumber($quantity)
                !== recipeQuantityFormatNumber($parsedQuantity)
            || (
                $quantityMax !== null
                && recipeQuantityFormatNumber($quantityMax)
                    !== recipeQuantityFormatNumber(
                        (float)$parsedQuantityMax
                    )
            )
        ) {
            throw new InvalidArgumentException(
                'source_amount_text is inconsistent with structured quantity'
            );
        }
        return $amountText;
    }

    $parsed = recipeQuantityParseClosedAmountText($amountText, 'und');
    if ($parsed === null) {
        throw new InvalidArgumentException(
            'source_amount_text must use closed amount notation'
        );
    }
    if (trim((string)$unit) !== '') {
        $canonicalUnit = recipeQuantityCanonicalUnit((string)$unit);
        if (
            $canonicalUnit === null
            || $parsed['unit'] !== $canonicalUnit
        ) {
            throw new InvalidArgumentException(
                'source_amount_text is inconsistent with source_unit'
            );
        }
    }
    return $amountText;
}

function recipeIngredientNormalizeSourceRow(PDO $db, mixed $ingredient, int $position): array {
    if (is_string($ingredient)) {
        $input = ['name' => $ingredient];
    } elseif (is_array($ingredient)) {
        $input = $ingredient;
    } else {
        throw new InvalidArgumentException('source ingredients contains an invalid entry');
    }
    $name = recipeIngredientNormalizeSourceText(
        $input['name'] ?? $input['ingredient'] ?? '',
        'source ingredient name',
        200,
        true
    );
    $quantity = recipeIngredientNormalizeSourceNumber(
        $input['source_quantity'] ?? null,
        'source_quantity'
    );
    $quantityMax = recipeIngredientNormalizeSourceNumber(
        $input['source_quantity_max'] ?? null,
        'source_quantity_max'
    );
    if ($quantity === null && $quantityMax !== null) {
        throw new InvalidArgumentException(
            'source_quantity_max requires source_quantity'
        );
    }
    if ($quantity !== null && $quantityMax !== null && $quantityMax < $quantity) {
        throw new InvalidArgumentException('source_quantity_max must not be less than source_quantity');
    }
    $unit = recipeIngredientNormalizeSourceText(
        $input['source_unit'] ?? '',
        'source_unit',
        80
    );
    $amountText = recipeIngredientNormalizeSourceText(
        $input['source_amount_text'] ?? '',
        'source_amount_text',
        160
    );
    $amountText = recipeIngredientValidateSourceAmountText(
        $amountText,
        $quantity,
        $quantityMax,
        $unit !== '' ? $unit : null
    );
    $groupTitle = recipeIngredientNormalizeSourceText(
        $input['source_group_title'] ?? '',
        'source_group_title',
        160
    );
    $defaultTitle = recipeIngredientNormalizeSourceText(
        $input['source_default_title'] ?? '',
        'source_default_title',
        200
    );
    $mappingVersion = recipeIngredientActiveMappingVersion();
    $resolution = recipeIngredientResolveForMappingVersion(
        $db,
        recipeIngredientIdentityCandidate($name),
        $mappingVersion
    );
    return [
        'position' => $position,
        'name' => $name,
        'normalized_name' => $resolution['normalized_name'],
        'source_quantity' => $quantity,
        'source_quantity_max' => $quantityMax,
        'source_unit' => $unit !== '' ? $unit : null,
        'source_amount_text' => $amountText,
        'source_group_index' => recipeIngredientNormalizeSourceOrdinal(
            $input['source_group_index'] ?? null,
            'source_group_index',
            0
        ),
        'source_group_position' => recipeIngredientNormalizeSourceOrdinal(
            $input['source_group_position'] ?? null,
            'source_group_position',
            $position
        ),
        'source_group_title' => $groupTitle !== '' ? $groupTitle : null,
        'source_ingredient_ref' => recipeIngredientNormalizeSourceReference(
            $input['source_ingredient_ref'] ?? null,
            'source_ingredient_ref'
        ),
        'source_default_title' =>
            $defaultTitle !== '' ? $defaultTitle : null,
        'source_unit_ref' => recipeIngredientNormalizeSourceReference(
            $input['source_unit_ref'] ?? null,
            'source_unit_ref'
        ),
        'source_optional' => recipeIngredientNormalizeSourceOptional(
            $input['source_optional'] ?? null
        ),
        'source_shopping_category_ref' =>
            recipeIngredientNormalizeSourceReference(
                $input['source_shopping_category_ref'] ?? null,
                'source_shopping_category_ref'
            ),
        'canonical_ingredient_id' => $resolution['canonical_ingredient_id'],
        'taxonomy_node_id' => $resolution['taxonomy_node_id'],
        'mapping_confidence' => $resolution['confidence'],
        'mapping_source' => $resolution['source'],
        'mapping_version' => $mappingVersion,
    ];
}

function recipeIngredientValidateSourceGrouping(array $rows): array {
    $expectedGroup = 0;
    $expectedGroupPosition = 0;
    $expectedGroupTitle = null;
    foreach ($rows as $row) {
        $group = (int)($row['source_group_index'] ?? 0);
        $groupPosition = (int)($row['source_group_position'] ?? 0);
        if (
            $expectedGroupPosition > 0
            && $group === $expectedGroup + 1
            && $groupPosition === 0
        ) {
            $expectedGroup = $group;
            $expectedGroupPosition = 0;
            $expectedGroupTitle = $row['source_group_title'] ?? null;
        } elseif ($expectedGroupPosition === 0) {
            $expectedGroupTitle = $row['source_group_title'] ?? null;
        }
        if (
            $group !== $expectedGroup
            || $groupPosition !== $expectedGroupPosition
            || ($row['source_group_title'] ?? null) !== $expectedGroupTitle
        ) {
            throw new InvalidArgumentException(
                'source ingredient group ordering is invalid'
            );
        }
        $expectedGroupPosition++;
    }
    return $rows;
}

function recipeSourceIngredientRemap(
    PDO $db,
    string $targetVersion,
    int $limit = 500
): array {
    $targetVersion = recipeIngredientNormalizeMappingVersion($targetVersion);
    if ($targetVersion !== recipeIngredientActiveMappingVersion()) {
        throw new InvalidArgumentException(
            'unsupported source ingredient mapping version'
        );
    }
    $limit = max(1, min(1000, $limit));
    $cursorKey = 'recipe_source_mapping_cursor:'
        . substr(hash('sha256', $targetVersion), 0, 16);
    $cursorStmt = $db->prepare("SELECT value FROM app_settings WHERE key = ?");
    $cursorStmt->execute([$cursorKey]);
    $cursor = max(0, (int)($cursorStmt->fetchColumn() ?: 0));
    $queryRows = static function (
        PDO $db,
        int $afterId,
        int $limit,
        string $targetVersion
    ): array {
        $stmt = $db->prepare("
            SELECT id, name
            FROM recipe_source_ingredients
            WHERE id > ?
              AND COALESCE(mapping_version, '') <> ?
            ORDER BY id ASC
            LIMIT {$limit}
        ");
        $stmt->execute([$afterId, $targetVersion]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $rows = $queryRows($db, $cursor, $limit, $targetVersion);
    $wrapped = false;
    if (!$rows && $cursor > 0) {
        $cursor = 0;
        $rows = $queryRows($db, 0, $limit, $targetVersion);
        $wrapped = true;
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $updated = 0;
        $lastId = $cursor;
        $update = $db->prepare("
            UPDATE recipe_source_ingredients SET
                normalized_name = ?,
                canonical_ingredient_id = ?,
                taxonomy_node_id = ?,
                mapping_confidence = ?,
                mapping_source = ?,
                mapping_version = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        foreach ($rows as $row) {
            $lastId = (int)$row['id'];
            $resolution = recipeIngredientResolveForMappingVersion(
                $db,
                recipeIngredientIdentityCandidate((string)$row['name']),
                $targetVersion
            );
            $update->execute([
                $resolution['normalized_name'],
                $resolution['canonical_ingredient_id'],
                $resolution['taxonomy_node_id'],
                $resolution['confidence'],
                $resolution['source'],
                $targetVersion,
                $lastId,
            ]);
            $updated += $update->rowCount() > 0 ? 1 : 0;
        }
        $db->prepare("
            INSERT INTO app_settings (key, value, updated_at)
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(key) DO UPDATE SET
                value = excluded.value,
                updated_at = CURRENT_TIMESTAMP
        ")->execute([$cursorKey, (string)$lastId]);
        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    $remainingStmt = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_source_ingredients
        WHERE COALESCE(mapping_version, '') <> ?
    ");
    $remainingStmt->execute([$targetVersion]);
    return [
        'target_mapping_version' => $targetVersion,
        'scanned' => count($rows),
        'updated' => $updated,
        'cursor' => $lastId,
        'wrapped' => $wrapped,
        'remaining' => (int)($remainingStmt->fetchColumn() ?: 0),
    ];
}

function recipeInventoryEffectiveExpiry(array $row, int $vacuumExtensionDays = 30): ?string {
    $stored = !empty($row['expiry_date']) ? new DateTimeImmutable((string)$row['expiry_date']) : null;
    $opened = !empty($row['opened_at']) ? new DateTimeImmutable((string)$row['opened_at']) : null;
    $vacuum = !empty($row['vacuum_sealed']);

    if ($opened !== null && function_exists('estimateOpenedExpiryDaysPHP')) {
        $days = estimateOpenedExpiryDaysPHP(
            (string)($row['name'] ?? ''),
            (string)($row['category'] ?? ''),
            (string)($row['location'] ?? '')
        );
        if ($vacuum) {
            $days = (int)round($days * 1.5);
        }
        $calculated = $opened->modify('+' . max(0, $days) . ' days');
        $effective = $stored !== null && $stored < $calculated ? $stored : $calculated;
        return $effective->format('Y-m-d');
    }

    if ($stored !== null && $vacuum) {
        return $stored->modify('+' . max(0, $vacuumExtensionDays) . ' days')->format('Y-m-d');
    }
    return $stored?->format('Y-m-d');
}

function recipeInventoryVacuumExtensionDays(): int {
    if (defined('RECIPE_BACKEND_TEST_MODE') && RECIPE_BACKEND_TEST_MODE) {
        return 30;
    }
    if (function_exists('env')) {
        return max(0, (int)env('VACUUM_EXPIRY_EXTENSION_DAYS', '30'));
    }
    return max(0, (int)(getenv('VACUUM_EXPIRY_EXTENSION_DAYS') ?: 30));
}

/**
 * Positive, raw inventory rows eligible for automatic recipe suggestions.
 * expiry_user_set is intentionally not used as a freshness override; it is provenance.
 */
function recipeInventoryCandidateResult(PDO $db, array $options = []): array {
    $excludeExpired = !array_key_exists('exclude_expired', $options) || (bool)$options['exclude_expired'];
    $vacuumExtensionDays = max(
        0,
        (int)($options['vacuum_extension_days'] ?? recipeInventoryVacuumExtensionDays())
    );
    $params = [];
    $where = [
        'i.quantity > 0',
        'COALESCE(i.prepared_food, 0) = 0',
    ];
    if (!empty($options['product_id'])) {
        $where[] = 'i.product_id = ?';
        $params[] = (int)$options['product_id'];
    }
    if ($excludeExpired) {
        $where[] = "(
            i.expiry_date IS NULL
            OR (
                i.opened_at IS NULL
                AND (
                    (
                        COALESCE(i.vacuum_sealed, 0) = 0
                        AND date(i.expiry_date) >= date('now', 'localtime')
                    )
                    OR (
                        COALESCE(i.vacuum_sealed, 0) <> 0
                        AND date(i.expiry_date, ?) >= date('now', 'localtime')
                    )
                )
            )
            OR (
                i.opened_at IS NOT NULL
                AND date(i.expiry_date) >= date('now', 'localtime')
            )
        )";
        $params[] = '+' . $vacuumExtensionDays . ' days';
    }

    $limit = isset($options['limit'])
        ? max(1, min(5001, (int)$options['limit']))
        : null;
    $limitSql = $limit !== null ? ' LIMIT ' . ($limit + 1) : '';
    $textLimit = isset($options['text_limit'])
        ? max(1, min(500, (int)$options['text_limit']))
        : null;
    $nameSql = $textLimit !== null
        ? "substr(p.name, 1, {$textLimit})"
        : 'p.name';
    $brandSql = $textLimit !== null
        ? "substr(p.brand, 1, {$textLimit})"
        : 'p.brand';
    $categorySql = $textLimit !== null
        ? "substr(p.category, 1, {$textLimit})"
        : 'p.category';
    $stmt = $db->prepare("
        SELECT i.id AS inventory_id, i.product_id, i.location, i.quantity,
               i.expiry_date, i.expiry_user_set, i.vacuum_sealed, i.opened_at,
               i.prepared_food, {$nameSql} AS name, {$brandSql} AS brand,
               {$categorySql} AS category, p.unit,
               p.default_quantity, p.package_unit
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY i.product_id, i.id
        {$limitSql}
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sourceTruncated = $limit !== null && count($rows) > $limit;
    $today = new DateTimeImmutable('today');
    $candidates = [];
    foreach ($rows as $row) {
        $effectiveExpiry = recipeInventoryEffectiveExpiry($row, $vacuumExtensionDays);
        $daysRemaining = null;
        if ($effectiveExpiry !== null) {
            $expiryDate = new DateTimeImmutable($effectiveExpiry);
            $daysRemaining = (int)$today->diff($expiryDate)->format('%r%a');
            if ($excludeExpired && $daysRemaining < 0) {
                continue;
            }
        }
        $row['inventory_id'] = (int)$row['inventory_id'];
        $row['product_id'] = (int)$row['product_id'];
        $row['quantity'] = (float)$row['quantity'];
        $row['effective_expiry_date'] = $effectiveExpiry;
        $row['days_remaining'] = $daysRemaining;
        $row['normalized_name'] = recipeIngredientNormalizeName((string)$row['name']);
        $candidates[] = $row;
    }
    if ($limit !== null && count($candidates) > $limit) {
        $sourceTruncated = true;
        $candidates = array_slice($candidates, 0, $limit);
    }

    $productIds = array_values(array_unique(array_map(
        static fn(array $row): int => (int)$row['product_id'],
        $candidates
    )));
    $mappings = [];
    $mappingsTruncated = false;
    if ($productIds) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $mappingLimit = isset($options['mapping_limit'])
            ? max(1, min(10000, (int)$options['mapping_limit']))
            : null;
        $mappingLimitSql = $mappingLimit !== null
            ? ' LIMIT ' . ($mappingLimit + 1)
            : '';
        $canonicalNameSql = $textLimit !== null
            ? "substr(ci.name, 1, {$textLimit})"
            : 'ci.name';
        $mapStmt = $db->prepare("
            SELECT pi.id AS product_mapping_id,
                   pi.product_id, pi.role, pi.confidence, pi.source,
                   ci.id AS canonical_ingredient_id, ci.slug,
                   {$canonicalNameSql} AS canonical_name,
                   tn.id AS taxonomy_node_id
            FROM product_ingredients pi
            JOIN canonical_ingredients ci ON ci.id = pi.ingredient_id
            LEFT JOIN taxonomy_trees tt ON tt.slug = 'food'
            LEFT JOIN taxonomy_nodes tn
              ON tn.tree_id = tt.id AND tn.slug = ci.slug AND tn.active = 1
            WHERE pi.product_id IN ({$placeholders})
            ORDER BY pi.product_id,
                CASE pi.role
                    WHEN 'primary' THEN 0
                    WHEN 'broader' THEN 1
                    WHEN 'inferred' THEN 2
                    WHEN 'contains' THEN 3
                    ELSE 4
                END,
                pi.confidence DESC
            {$mappingLimitSql}
        ");
        $mapStmt->execute($productIds);
        $mappingRows = $mapStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($mappingLimit !== null && count($mappingRows) > $mappingLimit) {
            $mappingsTruncated = true;
            $mappingRows = array_slice($mappingRows, 0, $mappingLimit);
        }
        foreach ($mappingRows as $mapping) {
            $mappings[(int)$mapping['product_id']][] = [
                'role' => (string)$mapping['role'],
                'confidence' => (float)$mapping['confidence'],
                'product_mapping_id' =>
                    (int)$mapping['product_mapping_id'],
                'mapping_source' => (string)$mapping['source'],
                'canonical_ingredient_id' => (int)$mapping['canonical_ingredient_id'],
                'taxonomy_node_id' => $mapping['taxonomy_node_id'] !== null
                    ? (int)$mapping['taxonomy_node_id']
                    : null,
                'slug' => (string)$mapping['slug'],
                'name' => (string)$mapping['canonical_name'],
            ];
        }
    }

    foreach ($candidates as &$row) {
        $row['normalized_name'] = recipeIngredientNormalizeName((string)$row['name']);
        $row['mappings'] = $mappings[$row['product_id']] ?? [];
        $row['mappings_truncated'] = $mappingsTruncated;
    }
    unset($row);
    return [
        'candidates' => $candidates,
        'source_truncated' => $sourceTruncated,
        'mappings_truncated' => $mappingsTruncated,
    ];
}

function recipeInventoryCandidates(PDO $db, array $options = []): array {
    return recipeInventoryCandidateResult($db, $options)['candidates'];
}

function recipePantryRoleWeight(string $role): float {
    return match ($role) {
        'primary' => 1.0,
        'broader' => 0.85,
        'inferred' => 0.70,
        'contains' => 0.55,
        default => 0.45,
    };
}

function recipeIngredientMatchCanSatisfyRequired(?array $match): bool {
    return $match !== null
        && (string)($match['role'] ?? '') !== 'contains'
        && in_array(
            (string)($match['relation'] ?? ''),
            ['exact', 'pantry_descendant', 'normalized_name'],
            true
        );
}

/**
 * Directional relation: a specific pantry descendant can satisfy a broader recipe term,
 * while a pantry ancestor is only weak evidence for a more specific recipe term.
 */
function recipeTaxonomyRelationScore(PDO $db, ?int $recipeNodeId, ?int $pantryNodeId): array {
    if (!$recipeNodeId || !$pantryNodeId) {
        return ['relation' => 'none', 'depth' => null, 'score' => 0.0];
    }
    static $cache = [];
    $cacheKey = spl_object_id($db) . ':' . $recipeNodeId . ':' . $pantryNodeId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    if ($recipeNodeId === $pantryNodeId) {
        return $cache[$cacheKey] = ['relation' => 'exact', 'depth' => 0, 'score' => 1.0];
    }

    $descendant = $db->prepare("
        SELECT depth FROM taxonomy_closure
        WHERE ancestor_node_id = ? AND descendant_node_id = ? AND depth > 0
        ORDER BY depth ASC LIMIT 1
    ");
    $descendant->execute([$recipeNodeId, $pantryNodeId]);
    $depth = $descendant->fetchColumn();
    if ($depth !== false) {
        $depth = (int)$depth;
        return $cache[$cacheKey] = [
            'relation' => 'pantry_descendant',
            'depth' => $depth,
            'score' => max(0.55, 0.90 * (0.88 ** max(0, $depth - 1))),
        ];
    }

    $ancestor = $db->prepare("
        SELECT depth FROM taxonomy_closure
        WHERE ancestor_node_id = ? AND descendant_node_id = ? AND depth > 0
        ORDER BY depth ASC LIMIT 1
    ");
    $ancestor->execute([$pantryNodeId, $recipeNodeId]);
    $depth = $ancestor->fetchColumn();
    if ($depth !== false) {
        $depth = (int)$depth;
        return $cache[$cacheKey] = [
            'relation' => 'pantry_ancestor',
            'depth' => $depth,
            'score' => max(0.15, 0.42 * (0.82 ** max(0, $depth - 1))),
        ];
    }
    return $cache[$cacheKey] = ['relation' => 'none', 'depth' => null, 'score' => 0.0];
}

function recipeTaxonomyRelationMap(
    PDO $db,
    array $ingredients,
    array $candidates
): array {
    $recipeNodeIds = [];
    foreach ($ingredients as $ingredient) {
        $nodeId = (int)($ingredient['taxonomy_node_id'] ?? 0);
        if ($nodeId > 0) {
            $recipeNodeIds[$nodeId] = true;
        }
    }
    $pantryNodeIds = [];
    foreach ($candidates as $candidate) {
        foreach (($candidate['mappings'] ?? []) as $mapping) {
            $nodeId = (int)($mapping['taxonomy_node_id'] ?? 0);
            if ($nodeId > 0) {
                $pantryNodeIds[$nodeId] = true;
            }
        }
    }
    $recipeNodeIds = array_keys($recipeNodeIds);
    $pantryNodeIds = array_keys($pantryNodeIds);
    if (!$recipeNodeIds || !$pantryNodeIds) {
        return [];
    }

    $recipeSet = array_fill_keys($recipeNodeIds, true);
    $pantrySet = array_fill_keys($pantryNodeIds, true);
    $relations = [];
    foreach (array_chunk($pantryNodeIds, 250) as $pantryChunk) {
        $recipePlaceholders = implode(',', array_fill(0, count($recipeNodeIds), '?'));
        $pantryPlaceholders = implode(',', array_fill(0, count($pantryChunk), '?'));
        $stmt = $db->prepare("
            SELECT ancestor_node_id, descendant_node_id, depth
            FROM taxonomy_closure
            WHERE (
                ancestor_node_id IN ({$recipePlaceholders})
                AND descendant_node_id IN ({$pantryPlaceholders})
            ) OR (
                ancestor_node_id IN ({$pantryPlaceholders})
                AND descendant_node_id IN ({$recipePlaceholders})
            )
        ");
        $stmt->execute(array_merge(
            $recipeNodeIds,
            $pantryChunk,
            $pantryChunk,
            $recipeNodeIds
        ));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ancestor = (int)$row['ancestor_node_id'];
            $descendant = (int)$row['descendant_node_id'];
            $depth = (int)$row['depth'];
            if ($depth <= 0) {
                continue;
            }
            if (isset($recipeSet[$ancestor], $pantrySet[$descendant])) {
                $current = $relations[$ancestor][$descendant] ?? null;
                if ($current === null || $depth < $current['depth']) {
                    $relations[$ancestor][$descendant] = [
                        'relation' => 'pantry_descendant',
                        'depth' => $depth,
                        'score' => max(0.55, 0.90 * (0.88 ** max(0, $depth - 1))),
                    ];
                }
            }
            if (isset($pantrySet[$ancestor], $recipeSet[$descendant])) {
                $current = $relations[$descendant][$ancestor] ?? null;
                if ($current === null || $depth < $current['depth']) {
                    $relations[$descendant][$ancestor] = [
                        'relation' => 'pantry_ancestor',
                        'depth' => $depth,
                        'score' => max(0.15, 0.42 * (0.82 ** max(0, $depth - 1))),
                    ];
                }
            }
        }
    }
    return $relations;
}

function recipeTaxonomyRelationScoreFromMap(
    array $relations,
    ?int $recipeNodeId,
    ?int $pantryNodeId
): array {
    if (!$recipeNodeId || !$pantryNodeId) {
        return ['relation' => 'none', 'depth' => null, 'score' => 0.0];
    }
    if ($recipeNodeId === $pantryNodeId) {
        return ['relation' => 'exact', 'depth' => 0, 'score' => 1.0];
    }
    return $relations[$recipeNodeId][$pantryNodeId]
        ?? ['relation' => 'none', 'depth' => null, 'score' => 0.0];
}

function recipeIngredientBestInventoryMatch(
    PDO $db,
    array $ingredient,
    array $candidates,
    ?array $taxonomyRelations = null,
    bool $includeRequiredContains = false
): ?array {
    $best = null;
    $matches = [];
    $isRequired = !empty($ingredient['is_required'])
        && empty($ingredient['is_optional'])
        && empty($ingredient['is_staple']);
    foreach ($candidates as $candidate) {
        $candidateBest = null;
        foreach ($candidate['mappings'] as $mapping) {
            if (
                $isRequired
                && (string)$mapping['role'] === 'contains'
                && !$includeRequiredContains
            ) {
                continue;
            }
            $relation = ['relation' => 'none', 'depth' => null, 'score' => 0.0];
            if (
                !empty($ingredient['canonical_ingredient_id'])
                && (int)$ingredient['canonical_ingredient_id'] === (int)$mapping['canonical_ingredient_id']
            ) {
                $relation = ['relation' => 'exact', 'depth' => 0, 'score' => 1.0];
            } else {
                $recipeNodeId = !empty($ingredient['taxonomy_node_id'])
                    ? (int)$ingredient['taxonomy_node_id']
                    : null;
                $pantryNodeId = !empty($mapping['taxonomy_node_id'])
                    ? (int)$mapping['taxonomy_node_id']
                    : null;
                $relation = $taxonomyRelations === null
                    ? recipeTaxonomyRelationScore($db, $recipeNodeId, $pantryNodeId)
                    : recipeTaxonomyRelationScoreFromMap(
                        $taxonomyRelations,
                        $recipeNodeId,
                        $pantryNodeId
                    );
            }
            if ($relation['score'] <= 0) {
                continue;
            }
            $score = $relation['score']
                * recipePantryRoleWeight((string)$mapping['role'])
                * (0.75 + 0.25 * max(0.0, min(1.0, (float)$mapping['confidence'])));
            $match = [
                'score' => $score,
                'relation' => $relation['relation'],
                'depth' => $relation['depth'],
                'role' => $mapping['role'],
                'canonical_ingredient_id' => $mapping['canonical_ingredient_id'],
                'taxonomy_node_id' => $mapping['taxonomy_node_id'],
            ];
            if ($candidateBest === null || $match['score'] > $candidateBest['score']) {
                $candidateBest = $match;
            }
        }

        if ($candidateBest === null) {
            $recipeName = (string)($ingredient['normalized_name'] ?? '');
            $pantryName = (string)($candidate['normalized_name'] ?? '');
            if ($recipeName !== '' && $recipeName === $pantryName) {
                $candidateBest = [
                    'score' => 0.72,
                    'relation' => 'normalized_name',
                    'depth' => null,
                    'role' => 'name',
                    'canonical_ingredient_id' => null,
                    'taxonomy_node_id' => null,
                ];
            } elseif (
                mb_strlen($recipeName, 'UTF-8') >= 4
                && mb_strlen($pantryName, 'UTF-8') >= 4
                && ($recipeName !== '' && $pantryName !== '')
                && (str_contains($recipeName, $pantryName) || str_contains($pantryName, $recipeName))
            ) {
                $candidateBest = [
                    'score' => 0.48,
                    'relation' => 'name_contains',
                    'depth' => null,
                    'role' => 'name',
                    'canonical_ingredient_id' => null,
                    'taxonomy_node_id' => null,
                ];
            }
        }

        if ($candidateBest === null) {
            continue;
        }
        $candidateBest += [
            'inventory_id' => (int)$candidate['inventory_id'],
            'product_id' => (int)$candidate['product_id'],
            'product_name' => (string)$candidate['name'],
            'quantity' => (float)$candidate['quantity'],
            'unit' => (string)$candidate['unit'],
            'default_quantity' => (float)($candidate['default_quantity'] ?? 0),
            'package_unit' => (string)($candidate['package_unit'] ?? ''),
            'location' => (string)$candidate['location'],
            'effective_expiry_date' => $candidate['effective_expiry_date'],
            'days_remaining' => $candidate['days_remaining'],
        ];
        $matches[] = $candidateBest;
        $candidateDays = $candidateBest['days_remaining'] ?? PHP_INT_MAX;
        $bestDays = $best['days_remaining'] ?? PHP_INT_MAX;
        if (
            $best === null
            || $candidateBest['score'] > $best['score']
            || (
                abs((float)$candidateBest['score'] - (float)$best['score']) < 0.000001
                && $candidateDays < $bestDays
            )
        ) {
            $best = $candidateBest;
        }
    }
    if ($best !== null) {
        $best['stock_rows'] = array_values(array_map(static fn(array $match): array => [
            'inventory_id' => $match['inventory_id'],
            'product_id' => $match['product_id'],
            'product_name' => $match['product_name'],
            'quantity' => $match['quantity'],
            'unit' => $match['unit'],
            'default_quantity' => $match['default_quantity'],
            'package_unit' => $match['package_unit'],
            'score' => $match['score'],
            'relation' => $match['relation'],
            'role' => $match['role'],
            'days_remaining' => $match['days_remaining'],
        ], array_filter(
            $matches,
            static fn(array $match): bool =>
                recipeIngredientMatchCanSatisfyRequired($match)
        )));
        $days = array_values(array_filter(
            array_column($best['stock_rows'], 'days_remaining'),
            static fn(mixed $value): bool => $value !== null
        ));
        if ($days) {
            $best['days_remaining'] = min(array_map('intval', $days));
        }
    }
    return $best;
}
