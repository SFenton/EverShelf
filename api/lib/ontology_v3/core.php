<?php
declare(strict_types=1);

function ingredientOntologyV3Json(mixed $value): string {
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
    );
}

function ingredientOntologyV3StableValue(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (function_exists('recipeArrayIsList') && recipeArrayIsList($value)) {
        return array_map('ingredientOntologyV3StableValue', $value);
    }
    $keys = array_keys($value);
    $isList = $value === [] || $keys === range(0, count($keys) - 1);
    if ($isList) {
        return array_map('ingredientOntologyV3StableValue', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = ingredientOntologyV3StableValue($item);
    }
    return $value;
}

function ingredientOntologyV3Hash(mixed $value): string {
    return hash(
        'sha256',
        ingredientOntologyV3Json(ingredientOntologyV3StableValue($value))
    );
}

function ingredientOntologyV3SchemaHash(): string {
    return ingredientOntologyV3Hash([
        'schema_version' => INGREDIENT_ONTOLOGY_V3_SCHEMA_VERSION,
        'facets' => ingredientOntologyV3FacetDefinitions(),
        'relations' => [
            'is_a',
            'equivalent_to',
            'variant_of',
            'substitutes_for',
            'derived_from',
            'component_of',
        ],
        'mapping_statuses' => [
            'accepted', 'candidate', 'ambiguous', 'unresolved', 'rejected',
        ],
        'identity_roles' => [
            'structural_category', 'identity_leaf', 'prepared_identity',
            'composite_identity', 'staple_class',
        ],
        'terminal_dispositions' =>
            array_keys(ingredientOntologyV3DispositionDefinitions()),
        'activation_policy' => 'manual_review',
        'frozen_corpus_profiles' => ['eval', 'provider', 'production'],
        'frozen_corpus_algorithm' => 'frozen-owner-universe-v1',
        'ontology_source_revision' => 'monotonic-owner-source-v1',
        'mapping_attribute_integrity' =>
            'attributes-json-authoritative-v1',
        'prior_gold_lineage' =>
            'retained-superseded-retired-exact-v1',
        'matcher_gold' => [
            'sha256' => INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_SHA256,
            'case_ids_sha256' =>
                INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_IDS_SHA256,
            'case_count' =>
                INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_COUNT,
            'case_ids' => ingredientOntologyV3MatcherGoldCaseIds(),
        ],
    ]);
}

function ingredientOntologyV3PromptHash(): string {
    return ingredientOntologyV3Hash([
        'prompt_schema' => INGREDIENT_ONTOLOGY_V3_PROMPT_SCHEMA_VERSION,
        'model_default' => INGREDIENT_ONTOLOGY_V3_DEFAULT_MODEL,
        'untrusted_fence' => true,
        'closed_candidate_ids' => true,
        'model_is_defining_ignored' => true,
    ]);
}

function ingredientOntologyV3ModelHash(
    string $model = INGREDIENT_ONTOLOGY_V3_DEFAULT_MODEL
): string {
    return hash('sha256', trim($model));
}

function ingredientOntologyV3ConfiguredProposalModel(): string {
    $model = function_exists('env')
        ? trim((string)env(
            'INGREDIENT_ONTOLOGY_V3_PROPOSAL_MODEL',
            INGREDIENT_ONTOLOGY_V3_DEFAULT_MODEL
        ))
        : INGREDIENT_ONTOLOGY_V3_DEFAULT_MODEL;
    if ($model === '' || strlen($model) > 100) {
        return INGREDIENT_ONTOLOGY_V3_DEFAULT_MODEL;
    }
    return $model;
}

function ingredientOntologyV3TableExists(PDO $db, string $table): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM sqlite_master
        WHERE type = 'table' AND name = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function ingredientOntologyV3Version(PDO $db, int $versionId): ?array {
    if ($versionId <= 0) {
        return null;
    }
    $stmt = $db->prepare("
        SELECT * FROM ingredient_ontology_versions WHERE id = ?
    ");
    $stmt->execute([$versionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    if (
        !is_string($row['model_name'] ?? null)
        || trim((string)$row['model_name']) === ''
    ) {
        $row['model_name'] = INGREDIENT_ONTOLOGY_V3_DEFAULT_MODEL;
    }
    $row['parent_version_id'] = $row['parent_version_id'] !== null
        ? (int)$row['parent_version_id']
        : null;
    return $row;
}

function ingredientOntologyV3ProductOwnerFingerprint(array $row): string {
    return ingredientOntologyV3Hash([
        'name' => trim((string)($row['name'] ?? '')),
        'brand' => trim((string)($row['brand'] ?? '')),
        'category' => trim((string)($row['category'] ?? '')),
        'prepared_food' => (int)($row['prepared_food'] ?? 0),
    ]);
}

function ingredientOntologyV3ProviderNamespace(?string $providerRef): string {
    $providerRef = trim((string)$providerRef);
    if ($providerRef === '') {
        return 'unknown_legacy_adapter';
    }
    if (preg_match('/^(.+?)[-:\/][A-Za-z0-9]+$/', $providerRef, $match)) {
        return mb_substr((string)$match[1], 0, 160, 'UTF-8');
    }
    return 'opaque';
}

function ingredientOntologyV3RecipeOwnerFingerprint(
    string $ownerType,
    array $row
): string {
    $sourceText = $ownerType === 'recipe_source_ingredient'
        ? (string)(
            $row['source_label']
                ?? $row['name']
                ?? $row['normalized_name']
                ?? ''
        )
        : (string)(
            $row['source_label']
                ?? $row['raw_text']
                ?? $row['normalized_name']
                ?? ''
        );
    $base = [
        'owner_type' => $ownerType,
        'connector' => trim((string)(
            $row['connector']
                ?? $row['primary_connector']
                ?? 'unknown'
        )),
        'origin_external_id' => trim(
            (string)($row['origin_external_id'] ?? '')
        ),
        'origin_locale' => trim(
            (string)($row['origin_locale'] ?? '')
        ),
        'position' => (int)$row['position'],
        'language' => ingredientOntologyV3NormalizeLanguage(
            (string)($row['language'] ?? 'und')
        ),
        'source_text' => trim($sourceText),
        'normalized_name' => trim((string)($row['normalized_name'] ?? '')),
    ];
    if ($ownerType === 'recipe_source_ingredient') {
        $base['source_optional'] = ($row['source_optional'] ?? null) !== null
            ? (int)$row['source_optional']
            : null;
        $base['source_ingredient_ref'] = trim(
            (string)($row['source_ingredient_ref'] ?? '')
        );
        $base['source_default_title'] = trim(
            (string)($row['source_default_title'] ?? '')
        );
        $base['connector'] = trim(
            (string)($row['connector'] ?? 'unknown_legacy_adapter')
        ) ?: 'unknown_legacy_adapter';
        $base['metadata_version'] = trim(
            (string)($row['metadata_version'] ?? '')
        );
        $base['metadata_schema_version'] = trim(
            (string)($row['metadata_schema_version'] ?? '')
        );
        $base['provider_namespace'] =
            ingredientOntologyV3ProviderNamespace(
                $base['source_ingredient_ref']
            );
        $base['source_ref_provenance'] =
            $base['source_ingredient_ref'] !== ''
                ? 'persisted_source_ingredient_ref'
                : 'unknown_legacy_adapter';
        $base['canonical_ingredient_id'] =
            (
                $row['source_canonical_ingredient_id']
                    ?? $row['canonical_ingredient_id']
                    ?? null
            ) !== null
                ? (int)(
                    $row['source_canonical_ingredient_id']
                        ?? $row['canonical_ingredient_id']
                )
                : null;
        $base['taxonomy_node_id'] =
            (
                $row['source_taxonomy_node_id']
                    ?? $row['taxonomy_node_id']
                    ?? null
            ) !== null
                ? (int)(
                    $row['source_taxonomy_node_id']
                        ?? $row['taxonomy_node_id']
                )
                : null;
        $base['mapping_confidence'] = round(
            (float)(
                $row['source_mapping_confidence']
                    ?? $row['mapping_confidence']
                    ?? 0
            ),
            6
        );
        $base['mapping_source'] = trim(
            (string)(
                $row['source_mapping_source']
                    ?? $row['mapping_source']
                    ?? ''
            )
        );
        return ingredientOntologyV3Hash($base);
    }
    $base['source_is_required'] =
        ($row['source_is_required'] ?? null) !== null
            ? (int)$row['source_is_required']
            : null;
    $base['source_is_optional'] =
        ($row['source_is_optional'] ?? null) !== null
            ? (int)$row['source_is_optional']
            : null;
    $base['requiredness_source'] = trim(
        (string)($row['requiredness_source'] ?? '')
    );
    $base['canonical_ingredient_id'] =
        (
            $row['source_canonical_ingredient_id']
                ?? $row['canonical_ingredient_id']
                ?? null
        ) !== null
            ? (int)(
                $row['source_canonical_ingredient_id']
                    ?? $row['canonical_ingredient_id']
            )
            : null;
    $base['taxonomy_node_id'] =
        (
            $row['source_taxonomy_node_id']
                ?? $row['taxonomy_node_id']
                ?? null
        ) !== null
            ? (int)(
                $row['source_taxonomy_node_id']
                    ?? $row['taxonomy_node_id']
            )
            : null;
    $base['mapping_confidence'] = round(
        (float)(
            $row['source_mapping_confidence']
                ?? $row['mapping_confidence']
                ?? 0
        ),
        6
    );
    $base['mapping_source'] = trim(
        (string)(
            $row['source_mapping_source']
                ?? $row['mapping_source']
                ?? ''
        )
    );
    return ingredientOntologyV3Hash($base);
}

function ingredientOntologyV3CurrentOwnerFingerprint(
    PDO $db,
    string $ownerType,
    int $ownerId
): ?string {
    if ($ownerType === 'product') {
        $stmt = $db->prepare("
            SELECT id, name, brand, category, prepared_food
            FROM products WHERE id = ?
        ");
        $stmt->execute([$ownerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row
            ? ingredientOntologyV3ProductOwnerFingerprint($row)
            : null;
    }
    $table = $ownerType === 'recipe_ingredient'
        ? 'recipe_ingredients'
        : (
            $ownerType === 'recipe_source_ingredient'
                ? 'recipe_source_ingredients'
                : ''
        );
    if ($table === '') {
        return null;
    }
    $sourceLabel = $table === 'recipe_ingredients'
        ? "COALESCE(NULLIF(si.raw_text, ''), si.normalized_name)"
        : "COALESCE(NULLIF(si.name, ''), si.normalized_name)";
    $scopeJoin = "
        LEFT JOIN recipe_origins scope_origin
          ON scope_origin.id = (
              SELECT ro.id
              FROM recipe_origins ro
              WHERE ro.recipe_id = si.recipe_id
                AND ro.connector = c.primary_connector
              ORDER BY ro.id
              LIMIT 1
          )
    ";
    $scopeSelect = ",
        COALESCE(
            NULLIF(scope_origin.connector, ''),
            NULLIF(c.primary_connector, ''),
            'unknown_legacy_adapter'
        ) AS connector,
        COALESCE(scope_origin.external_id, '') AS origin_external_id,
        COALESCE(scope_origin.locale, '') AS origin_locale,
        COALESCE(scope_origin.metadata_version, '') AS metadata_version,
        COALESCE(
            scope_origin.metadata_schema_version,
            ''
        ) AS metadata_schema_version
    ";
    $stmt = $db->prepare("
        SELECT si.*, {$sourceLabel} AS source_label, c.language
               {$scopeSelect}
        FROM {$table} si
        JOIN recipe_catalog c ON c.id = si.recipe_id
        {$scopeJoin}
        WHERE si.id = ?
    ");
    $stmt->execute([$ownerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row
        ? ingredientOntologyV3RecipeOwnerFingerprint($ownerType, $row)
        : null;
}

function ingredientOntologyV3ActiveVersion(PDO $db): ?array {
    if (!ingredientOntologyV3TableExists($db, 'recipe_score_state')) {
        return null;
    }
    $row = $db->query("
        SELECT v.*, r.id AS score_revision_id
        FROM recipe_score_state s
        JOIN recipe_score_revisions r
          ON r.id = s.active_score_revision_id
        JOIN ingredient_ontology_versions v
          ON v.id = r.ontology_version_id
        WHERE s.id = 1
          AND r.status = 'ready'
          AND r.scoring_model = 'faceted-ontology-v3'
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['score_revision_id'] = (int)$row['score_revision_id'];
    $row['effective_status'] = 'active';
    return $row;
}

function ingredientOntologyV3FacetDefinitions(): array {
    return [
        'form' => [
            'hard' => true,
            'values' => [
                'whole', 'sliced', 'shredded', 'block', 'ground', 'powder',
                'liquid', 'paste', 'flour', 'meal', 'noodle', 'pod', 'clove',
                'chopped', 'diced', 'minced', 'florets', 'rings', 'cubes',
                'fine', 'coarse', 'flakes', 'leaf', 'stalk', 'halves',
                'granules', 'seed', 'bean',
                'shaved',
            ],
        ],
        'processing' => [
            'hard' => true,
            'values' => [
                'raw', 'cooked', 'dried', 'frozen', 'smoked', 'pickled',
                'roasted', 'baked', 'blanched', 'fermented',
                'ultra_pasteurized',
            ],
        ],
        'preservation' => [
            'hard' => true,
            'values' => ['canned', 'in_oil', 'brined'],
        ],
        'preparation' => [
            'hard' => true,
            'values' => [
                'boiling', 'peeled', 'pitted', 'crushed', 'grated',
                'thick_cut',
            ],
        ],
        'plant_part' => [
            'hard' => true,
            'values' => [
                'root', 'leaf', 'stem', 'flower', 'fruit', 'seed', 'pod',
            ],
        ],
        'filtration' => [
            'hard' => true,
            'values' => ['ultra_filtered'],
        ],
        'cut' => [
            'hard' => true,
            'values' => ['breast', 'thigh', 'wing', 'drumstick'],
        ],
        'bone' => [
            'hard' => true,
            'values' => ['bone_in', 'boneless'],
        ],
        'skin' => [
            'hard' => true,
            'values' => ['skin_on', 'skinless'],
        ],
        'refinement' => [
            'hard' => true,
            'values' => [
                'brown', 'white', 'powdered', 'caster', 'granulated',
                'all_purpose', 'bread', 'cake',
                'plain', 'self_raising', 'type_00', 'type_65',
                'type_0',
            ],
        ],
        'variety' => [
            'hard' => true,
            'values' => [
                'ramen', 'rice', 'corn', 'olive', 'vegetable', 'almond',
                'apple_cider', 'jalapeno', 'thousand_island', 'tomato',
                'egg', 'sesame', 'white_wine', 'red_wine', 'balsamic',
                'chicken', 'beef', 'coconut', 'sunflower',
                'red', 'green', 'white', 'yellow', 'black',
                'canola', 'rapeseed', 'peanut', 'grapeseed', 'walnut',
                'hazelnut', 'basmati', 'jasmine', 'arborio',
                'cherry', 'plum', 'cayenne', 'kalamata',
            ],
        ],
        'package_form' => [
            'hard' => false,
            'values' => [
                'can', 'jar', 'bottle', 'packet', 'bag', 'carton',
            ],
        ],
        'state' => [
            'hard' => true,
            'values' => [
                'fresh', 'shelf_stable', 'chilled', 'room_temperature',
            ],
        ],
        'size' => [
            'hard' => false,
            'values' => ['small', 'medium', 'large', 'extra_large'],
        ],
        'species' => [
            'hard' => true,
            'values' => [
                'chicken', 'turkey', 'beef', 'pork', 'lamb', 'duck',
                'almond', 'olive',
            ],
        ],
        'saltedness' => [
            'hard' => true,
            'values' => ['salted', 'unsalted'],
        ],
        'sweetening' => [
            'hard' => true,
            'values' => ['sweetened', 'unsweetened'],
        ],
        'fat_content' => [
            'hard' => true,
            'values' => [
                'whole', 'reduced_fat', 'low_fat', 'fat_free', 'heavy',
            ],
        ],
        'cream_class' => [
            'hard' => true,
            'values' => [
                'whipping', 'double', 'half_and_half', 'sour',
            ],
        ],
        'egg_part' => [
            'hard' => true,
            'values' => ['whole', 'yolk', 'white'],
        ],
        'wine_color' => [
            'hard' => true,
            'values' => ['red', 'white', 'rose'],
        ],
        'wine_sweetness' => [
            'hard' => true,
            'values' => ['dry', 'off_dry', 'sweet'],
        ],
        'chocolate_class' => [
            'hard' => true,
            'values' => [
                'dark', 'milk', 'white', 'bittersweet', 'unsweetened',
            ],
        ],
        'salt_class' => [
            'hard' => true,
            'values' => ['table', 'sea', 'kosher'],
        ],
        'moisture' => [
            'hard' => true,
            'values' => ['low_moisture'],
        ],
        'seasoning' => [
            'hard' => true,
            'values' => ['seasoned', 'unseasoned'],
        ],
    ];
}

function ingredientOntologyV3FacetIsDefining(string $facet): bool {
    $definition = ingredientOntologyV3FacetDefinitions()[$facet] ?? null;
    return is_array($definition) && !empty($definition['hard']);
}

function ingredientOntologyV3SeedFacets(PDO $db, int $versionId): array {
    $insertFacet = $db->prepare("
        INSERT INTO ingredient_ontology_facets (
            ontology_version_id, facet_key, display_name, hard_default
        )
        VALUES (?, ?, ?, ?)
        ON CONFLICT(ontology_version_id, facet_key) DO UPDATE SET
            display_name = excluded.display_name,
            hard_default = excluded.hard_default
    ");
    $facetId = $db->prepare("
        SELECT id FROM ingredient_ontology_facets
        WHERE ontology_version_id = ? AND facet_key = ?
    ");
    $insertValue = $db->prepare("
        INSERT INTO ingredient_ontology_facet_values (
            ontology_version_id, facet_id, value_key, display_name
        )
        VALUES (?, ?, ?, ?)
        ON CONFLICT(facet_id, value_key) DO UPDATE SET
            display_name = excluded.display_name
    ");
    $valueId = $db->prepare("
        SELECT id FROM ingredient_ontology_facet_values
        WHERE facet_id = ? AND value_key = ?
    ");
    $result = [];
    foreach (ingredientOntologyV3FacetDefinitions() as $key => $definition) {
        $insertFacet->execute([
            $versionId,
            $key,
            ucwords(str_replace('_', ' ', $key)),
            !empty($definition['hard']) ? 1 : 0,
        ]);
        $facetId->execute([$versionId, $key]);
        $id = (int)$facetId->fetchColumn();
        $result[$key] = [
            'id' => $id,
            'hard' => !empty($definition['hard']),
            'values' => [],
        ];
        foreach ($definition['values'] as $value) {
            $insertValue->execute([
                $versionId,
                $id,
                $value,
                ucwords(str_replace('_', ' ', $value)),
            ]);
            $valueId->execute([$id, $value]);
            $result[$key]['values'][$value] = (int)$valueId->fetchColumn();
        }
    }
    return $result;
}

function ingredientOntologyV3FacetMap(PDO $db, int $versionId): array {
    $stmt = $db->prepare("
        SELECT f.id AS facet_id, f.facet_key, f.hard_default,
               v.id AS value_id, v.value_key
        FROM ingredient_ontology_facets f
        JOIN ingredient_ontology_facet_values v
          ON v.facet_id = f.id AND v.active = 1
        WHERE f.ontology_version_id = ? AND f.active = 1
        ORDER BY f.id, v.id
    ");
    $stmt->execute([$versionId]);
    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $facet = (string)$row['facet_key'];
        if (!isset($result[$facet])) {
            $result[$facet] = [
                'id' => (int)$row['facet_id'],
                'hard' => !empty($row['hard_default']),
                'values' => [],
            ];
        }
        $result[$facet]['values'][(string)$row['value_key']] =
            (int)$row['value_id'];
    }
    return $result;
}

function ingredientOntologyV3NormalizeLanguage(string $language): string {
    $language = str_replace('_', '-', strtolower(trim($language)));
    if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $language)) {
        return 'und';
    }
    return $language;
}

function ingredientOntologyV3NormalizeLabel(string $label): string {
    $label = mb_strtolower(trim($label), 'UTF-8');
    $label = str_replace(
        ['’', "'", '`', '&', '/', '_', '+', '–', '—', '-'],
        [' ', ' ', ' ', ' and ', ' ', ' ', ' plus ', ' ', ' ', ' '],
        $label
    );
    $label = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $label) ?? $label;
    return trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
}

function ingredientOntologyV3Slug(string $name): string {
    $slug = ingredientOntologyV3NormalizeLabel($name);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if (is_string($ascii) && $ascii !== '') {
            $slug = strtolower($ascii);
        }
    }
    return trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug, '-');
}

function ingredientOntologyV3LookupCandidates(
    string $label,
    string $language = 'und'
): array {
    $normalized = ingredientOntologyV3NormalizeLabel($label);
    if ($normalized === '') {
        return [];
    }
    $candidates = [$normalized];
    $language = ingredientOntologyV3NormalizeLanguage($language);
    $base = explode('-', $language)[0];
    $prefixes = [
        'fr' => ['de l ', 'de la ', 'du ', 'des ', 'de '],
        'it' => ['dell ', 'della ', 'dello ', 'del ', 'degli ', 'd ', 'di '],
        'es' => ['del ', 'de la ', 'de los ', 'de las ', 'de '],
        'de' => ['von der ', 'von '],
        'und' => [
            'de l ', 'de la ', 'du ', 'des ', 'de ', 'dell ', 'della ',
            'dello ', 'del ', 'degli ', 'd ', 'di ',
        ],
    ];
    foreach ($prefixes[$base] ?? $prefixes['und'] as $prefix) {
        if (str_starts_with($normalized, $prefix)) {
            $remaining = trim(substr($normalized, strlen($prefix)));
            if ($remaining !== '') {
                $candidates[] = $remaining;
            }
        }
    }
    return array_values(array_unique($candidates));
}

function ingredientOntologyV3AliasIsRetailUnsafe(
    string $label,
    string $brand = ''
): bool {
    $normalized = ingredientOntologyV3NormalizeLabel($label);
    $brand = ingredientOntologyV3NormalizeLabel($brand);
    if ($normalized === '') {
        return true;
    }
    if (preg_match('/\d/u', $normalized)) {
        return true;
    }
    if (
        $brand !== ''
        && (
            $normalized === $brand
            || str_starts_with($normalized, $brand . ' ')
        )
    ) {
        return true;
    }
    return (bool)preg_match(
        '/\b(oz|ounce|ounces|lb|lbs|kg|g|gram|grams|ml|l|liter|litre|'
            . 'pack|packet|box|bag|bottle|jar|can|count|ct|size|family|'
            . 'amazon|kroger|walmart|costco|tesco|aldi|lidl)\b/u',
        $normalized
    );
}

function ingredientOntologyV3IsStapleLabel(
    string $label,
    string $language = 'und'
): bool {
    $barePepperTerms = [
        'pepper', 'pepe', 'pfeffer', 'poivre',
        'pimienta', 'pimenta', 'pieprz',
    ];
    foreach (
        ingredientOntologyV3LookupCandidates($label, $language)
        as $candidate
    ) {
        if (in_array($candidate, $barePepperTerms, true)) {
            return false;
        }
    }
    static $terms = [
        'water' => true,
        'acqua' => true,
        'wasser' => true,
        'eau' => true,
        'd eau' => true,
        'de l eau' => true,
        'agua' => true,
        'água' => true,
        'woda' => true,
        'wody' => true,
        'di acqua' => true,
        'salt' => true,
        'sea salt' => true,
        'fine sea salt' => true,
        'sale' => true,
        'salz' => true,
        'sel' => true,
        'sal' => true,
        'sól' => true,
        'soli' => true,
        'di sale' => true,
        'de sel' => true,
        'de sal' => true,
        'du sel' => true,
        'pepper' => true,
        'black pepper' => true,
        'ground black pepper' => true,
        'pepe' => true,
        'pepe nero macinato' => true,
        'di pepe nero macinato' => true,
        'pfeffer' => true,
        'poivre' => true,
        'poivre moulu' => true,
        'de poivre moulu' => true,
        'du poivre moulu' => true,
        'pimienta' => true,
        'pimenta' => true,
        'pieprz' => true,
        'oil' => true,
        'olive oil' => true,
        'olio' => true,
        'd olio' => true,
        'olio d oliva' => true,
        'olio extravergine di oliva' => true,
        'di olio extravergine di oliva' => true,
        'öl' => true,
        'olivenöl' => true,
        'huile' => true,
        'd huile' => true,
        'huile d olive' => true,
        'aceite' => true,
        'aceite de oliva' => true,
        'de aceite de oliva' => true,
        'azeite' => true,
        'azeite de oliva' => true,
        'olej' => true,
    ];
    foreach (ingredientOntologyV3LookupCandidates($label, $language) as $term) {
        if (isset($terms[$term])) {
            return true;
        }
    }
    return false;
}

function ingredientOntologyV3ExtractAttributes(string $label): array {
    $normalized = ingredientOntologyV3NormalizeLabel($label);
    $attributes = [];
    $put = static function (
        string $facet,
        string $value
    ) use (&$attributes): void {
        if (!isset($attributes[$facet])) {
            $attributes[$facet] = $value;
        }
    };

    foreach ([
        '/\bsliced?\b/u' => ['form', 'sliced'],
        '/\bshredded?\b/u' => ['form', 'shredded'],
        '/\bblock\b/u' => ['form', 'block'],
        '/\b(liquid)\b/u' => ['form', 'liquid'],
        '/\bpaste\b/u' => ['form', 'paste'],
        '/\bflou?r\b/u' => ['form', 'flour'],
        '/\b(cornmeal|meal)\b/u' => ['form', 'meal'],
        '/\bnoodles?\b/u' => ['form', 'noodle'],
        '/\bpods?\b/u' => ['form', 'pod'],
        '/\bcloves?\b/u' => ['form', 'clove'],
        '/\bwhole\b/u' => ['form', 'whole'],
        '/\b(ground|gemahlen(?:e|en|er|es)?|'
            . 'moulu(?:e|es|s)?|macinat(?:o|a|i|e)|'
            . 'molid(?:o|a|os|as)|moíd(?:o|a|os|as)|'
            . 'mielon(?:y|a|e))\b/u' => ['form', 'ground'],
        '/\bchopped\b/u' => ['form', 'chopped'],
        '/\bdiced\b/u' => ['form', 'diced'],
        '/\bminced\b/u' => ['form', 'minced'],
        '/\bflorets?\b/u' => ['form', 'florets'],
        '/\brings?\b/u' => ['form', 'rings'],
        '/\bcubes?\b/u' => ['form', 'cubes'],
        '/\bflakes?\b/u' => ['form', 'flakes'],
        '/\bhalves\b/u' => ['form', 'halves'],
        '/\bgranules?\b/u' => ['form', 'granules'],
        '/\bshaved?\b/u' => ['form', 'shaved'],
    ] as $pattern => [$facet, $value]) {
        if (preg_match($pattern, $normalized)) {
            $put($facet, $value);
            break;
        }
    }
    if (
        preg_match('/\bpowder(?:ed)?\b/u', $normalized)
        && !preg_match('/\b(powdered|icing)\s+sugar\b/u', $normalized)
    ) {
        $put('form', 'powder');
    }

    foreach ([
        '/\b(uncooked)\b/u' => 'raw',
        '/\bpickled\b/u' => 'pickled',
        '/\b(dried|getrocknet(?:e|en|er|es)?|'
            . 'séché(?:e|es|s)?|seché(?:e|es|s)?|'
            . 'sèche|sèches|seche|seches|sec|secs|'
            . 'seco|seca|secos|secas|'
            . 'secco|secca|secchi|secche|'
            . 'essiccato|essiccata|essiccati|essiccate|'
            . 'deshidratado|deshidratada|deshidratados|deshidratadas|'
            . 'suszony|suszona|suszone)\b/u' => 'dried',
        '/\bfrozen\b/u' => 'frozen',
        '/\bsmoked\b/u' => 'smoked',
        '/(?<!un)\broasted\b/u' => 'roasted',
        '/\bbaked\b/u' => 'baked',
        '/\b(blanched|blanchi(?:e|es|s)?|blanchiert(?:e|en|er|es)?)\b/u'
            => 'blanched',
        '/\bfermented\b/u' => 'fermented',
        '/\bcooked\b/u' => 'cooked',
        '/\braw\b/u' => 'raw',
        '/\bultra pasteurized\b/u' => 'ultra_pasteurized',
    ] as $pattern => $value) {
        if (preg_match($pattern, $normalized)) {
            $put('processing', $value);
            break;
        }
    }
    if (preg_match('/\bultra filtered\b/u', $normalized)) {
        $put('filtration', 'ultra_filtered');
    }
    if (preg_match('/\bcanned\b/u', $normalized)) {
        $put('preservation', 'canned');
    } elseif (preg_match('/\bin oil\b/u', $normalized)) {
        $put('preservation', 'in_oil');
    } elseif (preg_match('/\bbrined\b/u', $normalized)) {
        $put('preservation', 'brined');
    }
    foreach ([
        '/\bboiling\b/u' => 'boiling',
        '/\bpeeled\b/u' => 'peeled',
        '/\b(pitted|dénoyaut(?:é|ée|és|ées)|'
            . 'denoyaut(?:e|ee|es|ees)|'
            . 'entsteint(?:e|en|er|es)?|'
            . 'denocciolat(?:o|a|i|e)|'
            . 'sem caroço|sem caroco|'
            . 'fără sâmburi|fara samburi|'
            . 'udstenet|udstenede|bez pestek)\b/u' => 'pitted',
        '/\bcrushed\b/u' => 'crushed',
        '/\bgrated\b/u' => 'grated',
        '/\bthick cut\b/u' => 'thick_cut',
    ] as $pattern => $value) {
        if (preg_match($pattern, $normalized)) {
            $put('preparation', $value);
            break;
        }
    }
    foreach ([
        '/\broot\b/u' => 'root',
        '/\bleaves?\b/u' => 'leaf',
        '/\bstems?\b/u' => 'stem',
        '/\bflowers?\b/u' => 'flower',
    ] as $pattern => $value) {
        if (preg_match($pattern, $normalized)) {
            $put('plant_part', $value);
            break;
        }
    }

    foreach ([
        '/\bbreasts?\b/u' => 'breast',
        '/\bthighs?\b/u' => 'thigh',
        '/\bwings?\b/u' => 'wing',
        '/\bdrumsticks?\b/u' => 'drumstick',
    ] as $pattern => $value) {
        if (preg_match($pattern, $normalized)) {
            $put('cut', $value);
            break;
        }
    }
    if (preg_match('/\bunsalted\b/u', $normalized)) {
        $put('saltedness', 'unsalted');
    } elseif (preg_match('/\bsalted\b/u', $normalized)) {
        $put('saltedness', 'salted');
    }
    if (preg_match('/\bunsweetened\b/u', $normalized)) {
        $put('sweetening', 'unsweetened');
    } elseif (
        preg_match('/\b(sweetened|condensed milk sweetened)\b/u', $normalized)
    ) {
        $put('sweetening', 'sweetened');
    }
    if (preg_match('/\bfat free\b/u', $normalized)) {
        $put('fat_content', 'fat_free');
    } elseif (preg_match('/\blow fat\b/u', $normalized)) {
        $put('fat_content', 'low_fat');
    } elseif (preg_match('/\breduced fat\b/u', $normalized)) {
        $put('fat_content', 'reduced_fat');
    } elseif (preg_match('/\bwhole milk\b/u', $normalized)) {
        $put('fat_content', 'whole');
    } elseif (preg_match('/\bheavy\b/u', $normalized)) {
        $put('fat_content', 'heavy');
    }
    if (preg_match('/\bhalf and half\b/u', $normalized)) {
        $put('cream_class', 'half_and_half');
    } elseif (preg_match('/\bdouble cream\b/u', $normalized)) {
        $put('cream_class', 'double');
    } elseif (preg_match('/\bwhipping cream\b/u', $normalized)) {
        $put('cream_class', 'whipping');
    } elseif (preg_match('/\bsour cream\b/u', $normalized)) {
        $put('cream_class', 'sour');
    }
    if (preg_match('/\begg yolks?\b/u', $normalized)) {
        $put('egg_part', 'yolk');
    } elseif (preg_match('/\begg whites?\b/u', $normalized)) {
        $put('egg_part', 'white');
    } elseif (preg_match('/\beggs?\b/u', $normalized)) {
        $put('egg_part', 'whole');
    }
    if (preg_match('/\b(bone in|bonein)\b/u', $normalized)) {
        $put('bone', 'bone_in');
    } elseif (preg_match('/\bboneless\b/u', $normalized)) {
        $put('bone', 'boneless');
    }
    if (preg_match('/\bskin on\b/u', $normalized)) {
        $put('skin', 'skin_on');
    } elseif (preg_match('/\bskinless\b/u', $normalized)) {
        $put('skin', 'skinless');
    }

    foreach ([
        '/\ball purpose\b/u' => 'all_purpose',
        '/\bbread flour\b/u' => 'bread',
        '/\bcake flour\b/u' => 'cake',
        '/\bplain flour\b/u' => 'plain',
        '/\bself raising flour\b/u' => 'self_raising',
        '/\b(tipo|type)\s*00\b/u' => 'type_00',
        '/\b(tipo|type)\s*65\b/u' => 'type_65',
        '/\bbrown sugar\b/u' => 'brown',
        '/\bwhite sugar\b/u' => 'white',
        '/\b(powdered|icing) sugar\b/u' => 'powdered',
        '/\bcaster sugar\b/u' => 'caster',
        '/\bgranulated(?: white)? sugar\b/u' => 'granulated',
    ] as $pattern => $value) {
        if (preg_match($pattern, $normalized)) {
            $put('refinement', $value);
            break;
        }
    }

    foreach ([
        '/\bramen\b/u' => 'ramen',
        '/\bkalamata\b/u' => 'kalamata',
        '/\bbasmati\b/u' => 'basmati',
        '/\bjasmine\b/u' => 'jasmine',
        '/\barborio\b/u' => 'arborio',
        '/\bcherry tomatoes?\b/u' => 'cherry',
        '/\bplum tomatoes?\b/u' => 'plum',
        '/\bpeanut oil\b/u' => 'peanut',
        '/\bwalnut oil\b/u' => 'walnut',
        '/\bcorn(?:meal)?\b/u' => 'corn',
        '/\bolive\b/u' => 'olive',
        '/\bvegetable\b/u' => 'vegetable',
        '/\balmond\b/u' => 'almond',
        '/\bapple cider\b/u' => 'apple_cider',
        '/\bjalape(?:n|ñ)o\b/u' => 'jalapeno',
        '/\b(1000|thousand) island\b/u' => 'thousand_island',
        '/\btomato\b/u' => 'tomato',
        '/\begg noodles?\b/u' => 'egg',
        '/\bsesame\b/u' => 'sesame',
        '/\bwhite wine\b/u' => 'white_wine',
        '/\bred wine\b/u' => 'red_wine',
        '/\bbalsamic\b/u' => 'balsamic',
        '/\bcoconut\b/u' => 'coconut',
        '/\bsunflower\b/u' => 'sunflower',
        '/\bcanola\b/u' => 'canola',
        '/\brapeseed\b/u' => 'rapeseed',
        '/\bgrapeseed\b/u' => 'grapeseed',
        '/\bhazelnut\b/u' => 'hazelnut',
        '/\bcayenne\b/u' => 'cayenne',
        '/\brice\b/u' => 'rice',
        '/\bred bell pepper\b/u' => 'red',
        '/\bgreen bell pepper\b/u' => 'green',
        '/\bred onions?\b/u' => 'red',
        '/\bwhite onions?\b/u' => 'white',
        '/\byellow onions?\b/u' => 'yellow',
        '/\b(black pepper|pepe nero|poivre noir|schwarzer pfeffer|'
            . 'pimienta negra|pimenta preta|pieprz czarny)\b/u' => 'black',
        '/\b(white pepper|pepe bianco|poivre blanc|weißer pfeffer|'
            . 'weisser pfeffer|pimienta blanca|pimenta branca|'
            . 'pieprz biały)\b/u' => 'white',
    ] as $pattern => $value) {
        if (preg_match($pattern, $normalized)) {
            $put('variety', $value);
            break;
        }
    }
    foreach ([
        '/\bextra large\b/u' => 'extra_large',
        '/\blarge\b/u' => 'large',
        '/\bmedium\b/u' => 'medium',
        '/\bsmall\b/u' => 'small',
    ] as $pattern => $value) {
        if (preg_match($pattern, $normalized)) {
            $put('size', $value);
            break;
        }
    }
    if (preg_match('/\bwhite wine\b/u', $normalized)) {
        $put('wine_color', 'white');
    } elseif (preg_match('/\bred wine\b/u', $normalized)) {
        $put('wine_color', 'red');
    } elseif (preg_match('/\b(?:rose|rosé) wine\b/u', $normalized)) {
        $put('wine_color', 'rose');
    }
    if (preg_match('/\boff dry\b/u', $normalized)) {
        $put('wine_sweetness', 'off_dry');
    } elseif (preg_match('/\bdry\b/u', $normalized)) {
        $put('wine_sweetness', 'dry');
    } elseif (preg_match('/\b(?:sweet|dessert) wine\b/u', $normalized)) {
        $put('wine_sweetness', 'sweet');
    }
    foreach ([
        '/\bbittersweet chocolate\b/u' => 'bittersweet',
        '/\bunsweetened chocolate\b/u' => 'unsweetened',
        '/\bdark chocolate\b/u' => 'dark',
        '/\bmilk chocolate\b/u' => 'milk',
        '/\bwhite chocolate\b/u' => 'white',
    ] as $pattern => $value) {
        if (preg_match($pattern, $normalized)) {
            $put('chocolate_class', $value);
            break;
        }
    }
    if (preg_match('/\bkosher salt\b/u', $normalized)) {
        $put('salt_class', 'kosher');
    } elseif (preg_match('/\bsea salt\b/u', $normalized)) {
        $put('salt_class', 'sea');
    } elseif (preg_match('/\btable salt\b/u', $normalized)) {
        $put('salt_class', 'table');
    }

    foreach ([
        '/\bcans?\b/u' => 'can',
        '/\bjars?\b/u' => 'jar',
        '/\bbottles?\b/u' => 'bottle',
        '/\b(packets?|packs?)\b/u' => 'packet',
        '/\bbags?\b/u' => 'bag',
        '/\bcartons?\b/u' => 'carton',
    ] as $pattern => $value) {
        if (preg_match($pattern, $normalized)) {
            $put('package_form', $value);
            break;
        }
    }

    if (preg_match(
        '/\b(fresh|freshly|frais|fraîche|fraiche|fraiches|fraîches|'
            . 'fresco|fresca|frescos|frescas|'
            . 'freschi|fresche|frisch|frische|frischer|'
            . 'frisches|frischen|świeży|świeża|świeże)\b/u',
        $normalized
    )) {
        $put('state', 'fresh');
    } elseif (preg_match('/\bshelf stable\b/u', $normalized)) {
        $put('state', 'shelf_stable');
    }
    foreach ([
        '/\bchicken\b/u' => 'chicken',
        '/\bturkey\b/u' => 'turkey',
        '/\bbeef\b/u' => 'beef',
        '/\bpork\b/u' => 'pork',
        '/\blamb\b/u' => 'lamb',
        '/\bduck\b/u' => 'duck',
    ] as $pattern => $value) {
        if (preg_match($pattern, $normalized)) {
            $put('species', $value);
            break;
        }
    }
    if (preg_match('/\b(?:type|tipo)\s*0\b/u', $normalized)) {
        $put('refinement', 'type_0');
    }
    if (preg_match('/\blow[\s-]+moisture\b/u', $normalized)) {
        $put('moisture', 'low_moisture');
    }
    if (preg_match('/\bunseasoned\b/u', $normalized)) {
        $put('seasoning', 'unseasoned');
    } elseif (preg_match('/\bseasoned\b/u', $normalized)) {
        $put('seasoning', 'seasoned');
    }
    ksort($attributes, SORT_STRING);
    return $attributes;
}

function ingredientOntologyV3LegacyBase(string $slug, string $name): array {
    $normalized = ingredientOntologyV3NormalizeLabel($name);
    $attributes = ingredientOntologyV3ExtractAttributes($name);
    $baseSlug = $slug;
    if (preg_match('/\b(brown|white|powdered|caster)\s+sugar\b/u', $normalized)) {
        $baseSlug = 'sugar';
    } elseif (preg_match('/\bchicken\s+(breast|thigh)s?\b/u', $normalized)) {
        $baseSlug = 'chicken';
    } elseif (preg_match('/\b(garlic|onion|ginger)\s+powder\b/u', $normalized, $m)) {
        $baseSlug = $m[1];
    } elseif (preg_match('/\bpickled\s+jalape/u', $normalized)) {
        $baseSlug = 'jalapeno-pepper';
    } elseif (preg_match('/\b(rice|ramen|egg)\s+noodles?\b/u', $normalized)) {
        $baseSlug = 'noodle';
    } elseif (preg_match('/\balmond\s+flour\b/u', $normalized)) {
        $baseSlug = 'flour';
    } elseif (preg_match('/\balmond\s+milk\b/u', $normalized)) {
        $baseSlug = 'milk-alternative';
    } elseif (preg_match('/\b(olive|vegetable|sesame)\s+oil\b/u', $normalized)) {
        $baseSlug = 'oil';
    } elseif (preg_match('/\b(apple cider|rice|balsamic|white wine|red wine)\s+vinegar\b/u', $normalized)) {
        $baseSlug = 'vinegar';
    } elseif (
        preg_match(
            '/\b(chicken|beef|vegetable)\s+(stock|broth)\b/u',
            $normalized,
            $match
        )
    ) {
        $baseSlug = 'stock';
        $attributes['variety'] = (string)$match[1];
    }
    if (function_exists('ingredientOntologyV3CuratedCanonicalSlug')) {
        $baseSlug = ingredientOntologyV3CuratedCanonicalSlug($baseSlug);
    }
    return ['slug' => ingredientOntologyV3Slug($baseSlug), 'attributes' => $attributes];
}

function ingredientOntologyV3EntityKind(string $slug, string $name): string {
    $text = ingredientOntologyV3NormalizeLabel($slug . ' ' . $name);
    if (preg_match('/\b(meal|dish|pizza|soup|sandwich|salad)\b/u', $text)) {
        return str_contains($text, 'soup') || str_contains($text, 'pizza')
            ? 'composite_food'
            : 'prepared_food';
    }
    return 'ingredient';
}

function ingredientOntologyV3CoreEntities(): array {
    return [
        ['food', 'Food', 'ingredient', null],
        ['ingredient', 'Ingredient', 'ingredient', 'food'],
        ['prepared-food', 'Prepared Food', 'prepared_food', 'food'],
        ['composite-food', 'Composite Food', 'composite_food', 'prepared-food'],
        ['water', 'Water', 'ingredient', 'ingredient'],
        ['salt', 'Salt', 'ingredient', 'ingredient'],
        ['pepper', 'Pepper', 'ingredient', 'ingredient'],
        ['oil', 'Oil', 'ingredient', 'ingredient'],
        ['olive', 'Olive', 'ingredient', 'ingredient'],
        ['vegetable', 'Vegetable', 'ingredient', 'ingredient'],
        ['stock', 'Stock', 'ingredient', 'ingredient'],
        ['rice', 'Rice', 'ingredient', 'ingredient'],
        ['noodle', 'Noodle', 'ingredient', 'ingredient'],
        ['egg', 'Egg', 'ingredient', 'ingredient'],
        ['almond', 'Almond', 'ingredient', 'ingredient'],
        ['legume', 'Legume', 'ingredient', 'ingredient'],
        ['milk-alternative', 'Milk Alternative', 'ingredient', 'ingredient'],
        ['flour', 'Flour', 'ingredient', 'ingredient'],
        ['sugar', 'Sugar', 'ingredient', 'ingredient'],
        ['garlic', 'Garlic', 'ingredient', 'ingredient'],
        ['onion', 'Onion', 'ingredient', 'ingredient'],
        ['ginger', 'Ginger', 'ingredient', 'ingredient'],
        ['jalapeno-pepper', 'Jalapeño Pepper', 'ingredient', 'vegetable'],
        ['chicken', 'Chicken', 'ingredient', 'ingredient'],
        ['cheese', 'Cheese', 'ingredient', 'ingredient'],
        ['mozzarella', 'Mozzarella', 'ingredient', 'cheese'],
        ['pepper-jack-cheese', 'Pepper Jack Cheese', 'ingredient', 'cheese'],
        ['vinegar', 'Vinegar', 'ingredient', 'ingredient'],
        ['soup', 'Soup', 'composite_food', 'composite-food'],
        ['noodle-soup', 'Noodle Soup', 'composite_food', 'soup'],
        ['vanilla', 'Vanilla', 'ingredient', 'ingredient'],
        ['cardamom', 'Cardamom', 'ingredient', 'ingredient'],
        ['coffee-pod', 'Coffee Pod', 'prepared_food', 'prepared-food'],
    ];
}

function ingredientOntologyV3UpsertEntity(
    PDO $db,
    int $versionId,
    string $localKey,
    string $slug,
    string $name,
    string $kind,
    string $provenance,
    ?int $legacyNodeId = null,
    ?int $legacyCanonicalId = null,
    bool $active = true
): int {
    $slug = ingredientOntologyV3Slug($slug);
    $localKey = trim($localKey);
    $name = trim(mb_substr($name, 0, 200, 'UTF-8'));
    if ($slug === '' || $localKey === '' || $name === '') {
        throw new InvalidArgumentException('ontology entity identity is invalid');
    }
    $stmt = $db->prepare("
        INSERT INTO ingredient_ontology_entities (
            ontology_version_id, local_key, slug, canonical_name,
            entity_kind, active, provenance, legacy_taxonomy_node_id,
            legacy_canonical_ingredient_id, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(ontology_version_id, slug) DO UPDATE SET
            canonical_name = CASE
                WHEN ingredient_ontology_entities.provenance = 'core_seed'
                THEN excluded.canonical_name
                ELSE ingredient_ontology_entities.canonical_name
            END,
            legacy_taxonomy_node_id = COALESCE(
                ingredient_ontology_entities.legacy_taxonomy_node_id,
                excluded.legacy_taxonomy_node_id
            ),
            legacy_canonical_ingredient_id = COALESCE(
                ingredient_ontology_entities.legacy_canonical_ingredient_id,
                excluded.legacy_canonical_ingredient_id
            ),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $versionId,
        $localKey,
        $slug,
        $name,
        $kind,
        $active ? 1 : 0,
        $provenance,
        $legacyNodeId,
        $legacyCanonicalId,
    ]);
    $id = $db->prepare("
        SELECT id FROM ingredient_ontology_entities
        WHERE ontology_version_id = ? AND slug = ?
    ");
    $id->execute([$versionId, $slug]);
    return (int)$id->fetchColumn();
}

function ingredientOntologyV3EntityMap(PDO $db, int $versionId): array {
    $stmt = $db->prepare("
        SELECT id, slug, canonical_name, entity_kind, identity_role, active,
               legacy_taxonomy_node_id, legacy_canonical_ingredient_id
        FROM ingredient_ontology_entities
        WHERE ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    $result = [
        'by_slug' => [],
        'by_taxonomy_node' => [],
        'by_canonical' => [],
    ];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $item = [
            'id' => (int)$row['id'],
            'slug' => (string)$row['slug'],
            'name' => (string)$row['canonical_name'],
            'kind' => (string)$row['entity_kind'],
            'identity_role' => (string)$row['identity_role'],
            'active' => !empty($row['active']),
        ];
        $result['by_slug'][$item['slug']] = $item;
        if ($row['legacy_taxonomy_node_id'] !== null) {
            $result['by_taxonomy_node'][(int)$row['legacy_taxonomy_node_id']] =
                $item;
        }
        if ($row['legacy_canonical_ingredient_id'] !== null) {
            $result['by_canonical'][(int)$row['legacy_canonical_ingredient_id']] =
                $item;
        }
    }
    return $result;
}

function ingredientOntologyV3UpsertLabel(
    PDO $db,
    int $versionId,
    int $entityId,
    string $language,
    string $label,
    string $kind,
    string $reviewState,
    string $provenance,
    ?string $sourceRef,
    array $attributes,
    array $facetMap
): int {
    $label = trim(mb_substr($label, 0, 200, 'UTF-8'));
    $normalized = ingredientOntologyV3NormalizeLabel($label);
    if ($normalized === '') {
        return 0;
    }
    $language = ingredientOntologyV3NormalizeLanguage($language);
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_labels (
            ontology_version_id, entity_id, language, label,
            normalized_label, kind, review_state, provenance, source_ref,
            updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(
            ontology_version_id, entity_id, language, normalized_label, kind
        ) DO UPDATE SET
            label = excluded.label,
            review_state = excluded.review_state,
            provenance = excluded.provenance,
            source_ref = excluded.source_ref,
            updated_at = CURRENT_TIMESTAMP
    ");
    try {
        $insert->execute([
            $versionId,
            $entityId,
            $language,
            $label,
            $normalized,
            $kind,
            $reviewState,
            $provenance,
            $sourceRef,
        ]);
    } catch (PDOException $e) {
        if (
            $reviewState === 'accepted'
            && str_contains(strtolower($e->getMessage()), 'unique constraint')
        ) {
            $reviewState = 'pending';
            $kind = 'candidate_only';
            $insert->closeCursor();
            $insert = $db->prepare("
                INSERT INTO ingredient_ontology_labels (
                    ontology_version_id, entity_id, language, label,
                    normalized_label, kind, review_state, provenance,
                    source_ref, updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(
                    ontology_version_id, entity_id, language,
                    normalized_label, kind
                ) DO UPDATE SET
                    label = excluded.label,
                    review_state = excluded.review_state,
                    provenance = excluded.provenance,
                    source_ref = excluded.source_ref,
                    updated_at = CURRENT_TIMESTAMP
            ");
            $insert->execute([
                $versionId,
                $entityId,
                $language,
                $label,
                $normalized,
                $kind,
                $reviewState,
                $provenance,
                $sourceRef,
            ]);
        } else {
            throw $e;
        }
    }
    $lookup = $db->prepare("
        SELECT id FROM ingredient_ontology_labels
        WHERE ontology_version_id = ? AND entity_id = ? AND language = ?
          AND normalized_label = ? AND kind = ?
    ");
    $lookup->execute([
        $versionId,
        $entityId,
        $language,
        $normalized,
        $kind,
    ]);
    $labelId = (int)$lookup->fetchColumn();
    if ($labelId <= 0) {
        return 0;
    }
    $db->prepare("
        DELETE FROM ingredient_ontology_label_attributes
        WHERE label_id = ?
    ")->execute([$labelId]);
    $attr = $db->prepare("
        INSERT INTO ingredient_ontology_label_attributes (
            ontology_version_id, label_id, facet_id,
            facet_value_id, is_defining
        )
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(label_id, facet_id) DO UPDATE SET
            facet_value_id = excluded.facet_value_id,
            is_defining = excluded.is_defining
    ");
    foreach ($attributes as $facet => $value) {
        if (!isset($facetMap[$facet]['values'][$value])) {
            continue;
        }
        $attr->execute([
            $versionId,
            $labelId,
            $facetMap[$facet]['id'],
            $facetMap[$facet]['values'][$value],
            !empty($facetMap[$facet]['hard']) ? 1 : 0,
        ]);
    }
    return $labelId;
}

function ingredientOntologyV3InsertRelation(
    PDO $db,
    int $versionId,
    int $fromEntityId,
    int $toEntityId,
    string $relation,
    bool $primary,
    bool $satisfiesRequired,
    float $confidence,
    string $provenance,
    string $reviewState = 'accepted',
    string $direction = 'forward',
    array $semantics = []
): void {
    if ($fromEntityId <= 0 || $toEntityId <= 0 || $fromEntityId === $toEntityId) {
        return;
    }
    if ($satisfiesRequired) {
        throw new InvalidArgumentException(
            'relations between distinct entities never satisfy identity'
        );
    }
    $sql = "
        INSERT INTO ingredient_ontology_relations (
            ontology_version_id, from_entity_id, to_entity_id, relation,
            direction, is_primary, satisfies_required, confidence,
            provenance, review_state, semantics_json, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(
            ontology_version_id, from_entity_id, to_entity_id, relation
        ) DO UPDATE SET
            direction = CASE
                WHEN ingredient_ontology_relations.direction = 'bidirectional'
                  OR excluded.direction = 'bidirectional'
                THEN 'bidirectional'
                ELSE 'forward'
            END,
            is_primary = CASE
                WHEN excluded.is_primary = 1 THEN 1
                ELSE ingredient_ontology_relations.is_primary
            END,
            satisfies_required = MAX(
                ingredient_ontology_relations.satisfies_required,
                excluded.satisfies_required
            ),
            confidence = MAX(
                ingredient_ontology_relations.confidence,
                excluded.confidence
            ),
            provenance = excluded.provenance,
            review_state = CASE
                WHEN ingredient_ontology_relations.review_state = 'accepted'
                 AND excluded.review_state = 'pending'
                THEN 'accepted'
                ELSE excluded.review_state
            END,
            semantics_json = excluded.semantics_json,
            updated_at = CURRENT_TIMESTAMP
    ";
    $stmt = $db->prepare($sql);
    try {
        $stmt->execute([
            $versionId,
            $fromEntityId,
            $toEntityId,
            $relation,
            $direction,
            $primary ? 1 : 0,
            $satisfiesRequired ? 1 : 0,
            max(0.0, min(1.0, $confidence)),
            $provenance,
            $reviewState,
            ingredientOntologyV3Json($semantics),
        ]);
    } catch (PDOException $e) {
        if (
            $primary
            && str_contains(strtolower($e->getMessage()), 'unique constraint')
        ) {
            $stmt->closeCursor();
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $versionId,
                $fromEntityId,
                $toEntityId,
                $relation,
                $direction,
                0,
                0,
                max(0.0, min(1.0, $confidence)),
                $provenance,
                'pending',
                ingredientOntologyV3Json([
                    'candidate_secondary_parent' => true,
                ] + $semantics),
            ]);
        } else {
            throw $e;
        }
    }
}

function ingredientOntologyV3SemanticAliases(): array {
    return [
        ['sugar', 'Brown Sugar', 'en', ['refinement' => 'brown']],
        ['sugar', 'White Sugar', 'en', ['refinement' => 'white']],
        ['sugar', 'Caster Sugar', 'en', ['refinement' => 'caster']],
        ['sugar', 'Powdered Sugar', 'en', ['refinement' => 'powdered']],
        ['sugar', 'Icing Sugar', 'en', ['refinement' => 'powdered']],
        ['flour', 'All-Purpose Flour', 'en', ['form' => 'flour', 'refinement' => 'all_purpose']],
        ['flour', 'All Purpose Flour', 'en', ['form' => 'flour', 'refinement' => 'all_purpose']],
        ['flour', 'Bread Flour', 'en', ['form' => 'flour', 'refinement' => 'bread']],
        ['flour', 'Cake Flour', 'en', ['form' => 'flour', 'refinement' => 'cake']],
        ['flour', 'Almond Flour', 'en', ['form' => 'flour', 'variety' => 'almond']],
        ['noodle', 'Rice Noodles', 'en', ['form' => 'noodle', 'variety' => 'rice']],
        ['noodle', 'Egg Noodles', 'en', ['form' => 'noodle', 'variety' => 'egg']],
        ['noodle', 'Ramen Noodles', 'en', ['form' => 'noodle', 'variety' => 'ramen']],
        ['noodle', 'Dried Ramen Noodles', 'en', ['form' => 'noodle', 'variety' => 'ramen', 'processing' => 'dried']],
        ['oil', 'Olive Oil', 'en', ['variety' => 'olive']],
        ['oil', 'Extra Virgin Olive Oil', 'en', ['variety' => 'olive']],
        ['oil', 'Vegetable Oil', 'en', ['variety' => 'vegetable']],
        ['oil', 'Sesame Oil', 'en', ['variety' => 'sesame']],
        ['milk-alternative', 'Almond Milk', 'en', ['variety' => 'almond']],
        ['stock', 'Vegetable Stock', 'en', ['variety' => 'vegetable']],
        ['stock', 'Vegetable Broth', 'en', ['variety' => 'vegetable']],
        ['vinegar', 'Apple Cider Vinegar', 'en', ['variety' => 'apple_cider']],
        ['vinegar', 'Rice Vinegar', 'en', ['variety' => 'rice']],
        ['vinegar', 'Balsamic Vinegar', 'en', ['variety' => 'balsamic']],
        ['vinegar', 'White Wine Vinegar', 'en', ['variety' => 'white_wine']],
        ['vinegar', 'Red Wine Vinegar', 'en', ['variety' => 'red_wine']],
        ['garlic', 'Garlic Powder', 'en', ['form' => 'powder']],
        ['garlic', 'Fresh Garlic', 'en', ['state' => 'fresh']],
        ['garlic', 'Fresh Garlic Cloves', 'en', ['form' => 'clove', 'state' => 'fresh']],
        ['onion', 'Onion Powder', 'en', ['form' => 'powder']],
        ['ginger', 'Ginger Powder', 'en', ['form' => 'powder']],
        ['jalapeno-pepper', 'Fresh Jalapeño Peppers', 'en', ['variety' => 'jalapeno', 'state' => 'fresh']],
        ['jalapeno-pepper', 'Pickled Jalapeño Peppers', 'en', ['variety' => 'jalapeno', 'processing' => 'pickled']],
        ['chicken', 'Chicken Breast', 'en', ['cut' => 'breast', 'species' => 'chicken']],
        ['chicken', 'Chicken Breasts', 'en', ['cut' => 'breast', 'species' => 'chicken']],
        ['chicken', 'Chicken Thigh', 'en', ['cut' => 'thigh', 'species' => 'chicken']],
        ['chicken', 'Chicken Thighs', 'en', ['cut' => 'thigh', 'species' => 'chicken']],
        ['mozzarella', 'Sliced Mozzarella', 'en', ['form' => 'sliced']],
        ['mozzarella', 'Shredded Mozzarella', 'en', ['form' => 'shredded']],
        ['mozzarella', 'Mozzarella Block', 'en', ['form' => 'block']],
        ['pepper-jack-cheese', 'Pepper Jack Slices', 'en', ['form' => 'sliced']],
        ['pepper-jack-cheese', 'Pepper Jack Cheese Slices', 'en', ['form' => 'sliced']],
        ['pepper-jack-cheese', 'Pepper Jack Cheese Block', 'en', ['form' => 'block']],
        ['vanilla', 'Vanilla Pod', 'en', ['form' => 'pod']],
        ['cardamom', 'Cardamom Pod', 'en', ['form' => 'pod']],
        ['noodle-soup', 'Ramen Noodle Soup', 'en', ['variety' => 'ramen']],
        ['noodle-soup', 'Chicken Ramen Noodle Soup', 'en', ['variety' => 'ramen', 'species' => 'chicken']],
    ];
}

function ingredientOntologyV3MultilingualStapleAliases(): array {
    return [
        ['water', 'Water', 'en'],
        ['water', 'Acqua', 'it'],
        ['water', "D'acqua", 'it'],
        ['water', 'Wasser', 'de'],
        ['water', 'Eau', 'fr'],
        ['water', "De l'eau", 'fr'],
        ['water', 'Agua', 'es'],
        ['water', 'De agua', 'es'],
        ['water', 'Água', 'pt'],
        ['water', 'De água', 'pt'],
        ['water', 'Woda', 'pl'],
        ['water', 'Wody', 'pl'],
        ['water', 'Apă', 'ro'],
        ['water', 'Apa', 'ro'],
        ['water', 'Di acqua', 'it'],
        ['salt', 'Salt', 'en'],
        ['salt', 'Sea Salt', 'en'],
        ['salt', 'Fine Sea Salt', 'en'],
        ['salt', 'Sale', 'it'],
        ['salt', 'Di sale', 'it'],
        ['salt', 'Salz', 'de'],
        ['salt', 'Sel', 'fr'],
        ['salt', 'De sel', 'fr'],
        ['salt', 'Sal', 'es'],
        ['salt', 'De sal', 'es'],
        ['salt', 'Sal', 'pt'],
        ['salt', 'Sól', 'pl'],
        ['salt', 'Soli', 'pl'],
        ['salt', 'Sare', 'ro'],
        ['pepper', 'Pepper', 'en'],
        ['pepper', 'Black Pepper', 'en', ['variety' => 'black']],
        ['pepper', 'Ground Black Pepper', 'en', [
            'form' => 'ground', 'variety' => 'black',
        ]],
        ['pepper', 'White Pepper', 'en', ['variety' => 'white']],
        ['pepper', 'Ground White Pepper', 'en', [
            'form' => 'ground', 'variety' => 'white',
        ]],
        ['pepper', 'Pepe', 'it'],
        ['pepper', 'Pepe nero', 'it', ['variety' => 'black']],
        ['pepper', 'Pepe nero macinato', 'it', [
            'form' => 'ground', 'variety' => 'black',
        ]],
        ['pepper', 'Di pepe nero macinato', 'it', [
            'form' => 'ground', 'variety' => 'black',
        ]],
        ['pepper', 'Pepe bianco macinato', 'it', [
            'form' => 'ground', 'variety' => 'white',
        ]],
        ['pepper', 'Pfeffer', 'de'],
        ['pepper', 'Schwarzer Pfeffer', 'de', ['variety' => 'black']],
        ['pepper', 'Gemahlener schwarzer Pfeffer', 'de', [
            'form' => 'ground', 'variety' => 'black',
        ]],
        ['pepper', 'Gemahlener weißer Pfeffer', 'de', [
            'form' => 'ground', 'variety' => 'white',
        ]],
        ['pepper', 'Poivre', 'fr'],
        ['pepper', 'Poivre moulu', 'fr', ['form' => 'ground']],
        ['pepper', 'De poivre moulu', 'fr', ['form' => 'ground']],
        ['pepper', 'Du poivre moulu', 'fr', ['form' => 'ground']],
        ['pepper', 'Poivre noir moulu', 'fr', [
            'form' => 'ground', 'variety' => 'black',
        ]],
        ['pepper', 'Poivre blanc moulu', 'fr', [
            'form' => 'ground', 'variety' => 'white',
        ]],
        ['pepper', 'Pimienta', 'es'],
        ['pepper', 'Pimienta negra molida', 'es', [
            'form' => 'ground', 'variety' => 'black',
        ]],
        ['pepper', 'Pimienta blanca molida', 'es', [
            'form' => 'ground', 'variety' => 'white',
        ]],
        ['pepper', 'Pimenta', 'pt'],
        ['pepper', 'Pimenta preta moída', 'pt', [
            'form' => 'ground', 'variety' => 'black',
        ]],
        ['pepper', 'Pimenta branca moída', 'pt', [
            'form' => 'ground', 'variety' => 'white',
        ]],
        ['pepper', 'Pieprz', 'pl'],
        ['pepper', 'Mielony czarny pieprz', 'pl', [
            'form' => 'ground', 'variety' => 'black',
        ]],
        ['pepper', 'Mielony biały pieprz', 'pl', [
            'form' => 'ground', 'variety' => 'white',
        ]],
        ['oil', 'Oil', 'en'],
        ['oil', 'Olio', 'it'],
        ['oil', "D'olio", 'it'],
        ['oil', 'Olio d oliva', 'it', ['variety' => 'olive']],
        ['oil', 'Olio extravergine di oliva', 'it', [
            'variety' => 'olive',
        ]],
        ['oil', 'Olio extra vergine di oliva', 'it', [
            'variety' => 'olive',
        ]],
        ['oil', 'Di olio extravergine di oliva', 'it', [
            'variety' => 'olive',
        ]],
        ['oil', 'Öl', 'de'],
        ['oil', 'Olivenöl', 'de', ['variety' => 'olive']],
        ['oil', 'Huile', 'fr'],
        ['oil', "D'huile", 'fr'],
        ['oil', "Huile d'olive", 'fr', ['variety' => 'olive']],
        ['oil', "D'huile d'olive", 'fr', ['variety' => 'olive']],
        ['oil', 'Aceite', 'es'],
        ['oil', 'Aceite de oliva', 'es', ['variety' => 'olive']],
        ['oil', 'De aceite de oliva', 'es', ['variety' => 'olive']],
        // In Portuguese culinary usage, bare "azeite" conventionally means olive oil.
        ['oil', 'Azeite', 'pt', ['variety' => 'olive']],
        ['oil', 'Azeite de oliva', 'pt', ['variety' => 'olive']],
        ['oil', 'Olej', 'pl'],
        ['oil', 'Oliwa z oliwek', 'pl', ['variety' => 'olive']],
        ['oil', 'Oliwy z oliwek', 'pl', ['variety' => 'olive']],
    ];
}

function ingredientOntologyV3SeedCore(
    PDO $db,
    int $versionId,
    array $facetMap
): void {
    foreach (ingredientOntologyV3CoreEntities() as [$slug, $name, $kind]) {
        ingredientOntologyV3UpsertEntity(
            $db,
            $versionId,
            'core:' . $slug,
            $slug,
            $name,
            $kind,
            'core_seed'
        );
    }
    $entities = ingredientOntologyV3EntityMap($db, $versionId)['by_slug'];
    $insertDefault = $db->prepare("
        INSERT INTO ingredient_ontology_entity_defaults (
            ontology_version_id, entity_id, facet_id, facet_value_id,
            is_defining, provenance
        )
        VALUES (?, ?, ?, ?, 1, 'core_seed')
        ON CONFLICT(entity_id, facet_id) DO UPDATE SET
            facet_value_id = excluded.facet_value_id,
            is_defining = excluded.is_defining,
            provenance = excluded.provenance
    ");
    foreach ([
        ['flour', 'form', 'flour'],
        ['noodle', 'form', 'noodle'],
        ['chicken', 'species', 'chicken'],
        ['coffee-pod', 'form', 'pod'],
    ] as [$slug, $facet, $value]) {
        if (
            !isset(
                $entities[$slug],
                $facetMap[$facet]['values'][$value]
            )
        ) {
            continue;
        }
        $insertDefault->execute([
            $versionId,
            $entities[$slug]['id'],
            $facetMap[$facet]['id'],
            $facetMap[$facet]['values'][$value],
        ]);
    }
    foreach (ingredientOntologyV3CoreEntities() as [$slug]) {
        $entity = $entities[$slug] ?? null;
        if ($entity === null) {
            continue;
        }
        ingredientOntologyV3UpsertLabel(
            $db,
            $versionId,
            $entity['id'],
            'und',
            $entity['name'],
            'exact_alias',
            'accepted',
            'canonical_name',
            $entity['slug'],
            [],
            $facetMap
        );
    }
    foreach (ingredientOntologyV3MultilingualStapleAliases() as $alias) {
        [$slug, $label, $language] = $alias;
        if (!isset($entities[$slug])) {
            continue;
        }
        $attributes = array_replace(
            ingredientOntologyV3ExtractAttributes($label),
            (array)($alias[3] ?? [])
        );
        ksort($attributes, SORT_STRING);
        ingredientOntologyV3UpsertLabel(
            $db,
            $versionId,
            $entities[$slug]['id'],
            $language,
            $label,
            $attributes ? 'attribute_alias' : 'exact_alias',
            'accepted',
            'multilingual_staple_seed',
            null,
            $attributes,
            $facetMap
        );
    }
    foreach (ingredientOntologyV3SemanticAliases() as [$slug, $label, $language, $attributes]) {
        if (!isset($entities[$slug])) {
            continue;
        }
        ingredientOntologyV3UpsertLabel(
            $db,
            $versionId,
            $entities[$slug]['id'],
            $language,
            $label,
            'attribute_alias',
            'accepted',
            'semantic_seed',
            null,
            $attributes,
            $facetMap
        );
    }
}

function ingredientOntologyV3SeedLegacyEntities(
    PDO $db,
    int $versionId,
    array $facetMap
): array {
    $treeId = (int)($db->query("
        SELECT id FROM taxonomy_trees WHERE slug = 'food' LIMIT 1
    ")->fetchColumn() ?: 0);
    if ($treeId > 0) {
        $stmt = $db->prepare("
            SELECT id, slug, name, active FROM taxonomy_nodes
            WHERE tree_id = ? ORDER BY id
        ");
        $stmt->execute([$treeId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            ingredientOntologyV3UpsertEntity(
                $db,
                $versionId,
                'legacy-taxonomy:' . (int)$row['id'],
                (string)$row['slug'],
                (string)$row['name'],
                ingredientOntologyV3EntityKind(
                    (string)$row['slug'],
                    (string)$row['name']
                ),
                'legacy_taxonomy',
                (int)$row['id'],
                null,
                !empty($row['active'])
            );
        }
    }
    if (ingredientOntologyV3TableExists($db, 'canonical_ingredients')) {
        $stmt = $db->query("
            SELECT id, slug, name, parent_slug
            FROM canonical_ingredients ORDER BY id
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            ingredientOntologyV3UpsertEntity(
                $db,
                $versionId,
                'legacy-canonical:' . (int)$row['id'],
                (string)$row['slug'],
                (string)$row['name'],
                ingredientOntologyV3EntityKind(
                    (string)$row['slug'],
                    (string)$row['name']
                ),
                'legacy_canonical',
                null,
                (int)$row['id']
            );
        }
    }
    ingredientOntologyV3SeedCore($db, $versionId, $facetMap);
    if (function_exists('ingredientOntologyV3SeedCuratedEntities')) {
        ingredientOntologyV3SeedCuratedEntities(
            $db,
            $versionId,
            $facetMap
        );
    }
    $entities = ingredientOntologyV3EntityMap($db, $versionId);
    $targetByTaxonomy = [];
    $targetByCanonical = [];
    $collapsedTaxonomy = [];
    $collapsedCanonical = [];

    if ($treeId > 0) {
        $stmt = $db->prepare("
            SELECT id, slug, name, active FROM taxonomy_nodes
            WHERE tree_id = ? ORDER BY id
        ");
        $stmt->execute([$treeId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $base = ingredientOntologyV3LegacyBase(
                (string)$row['slug'],
                (string)$row['name']
            );
            $target = $entities['by_slug'][$base['slug']]
                ?? $entities['by_taxonomy_node'][(int)$row['id']]
                ?? null;
            if ($target === null) {
                continue;
            }
            $targetByTaxonomy[(int)$row['id']] = $target;
            $collapsedTaxonomy[(int)$row['id']] =
                $base['slug'] !== (string)$row['slug'];
            if (empty($row['active'])) {
                continue;
            }
            $collapsed = $base['slug'] !== (string)$row['slug'];
            ingredientOntologyV3UpsertLabel(
                $db,
                $versionId,
                $target['id'],
                'und',
                (string)$row['name'],
                $collapsed ? 'attribute_alias' : 'exact_alias',
                'accepted',
                'legacy_taxonomy_name',
                'taxonomy_node:' . (int)$row['id'],
                $base['attributes'],
                $facetMap
            );
            $slugLabel = str_replace('-', ' ', (string)$row['slug']);
            if (
                ingredientOntologyV3NormalizeLabel($slugLabel)
                !== ingredientOntologyV3NormalizeLabel((string)$row['name'])
            ) {
                ingredientOntologyV3UpsertLabel(
                    $db,
                    $versionId,
                    $target['id'],
                    'und',
                    $slugLabel,
                    $collapsed ? 'attribute_alias' : 'exact_alias',
                    'accepted',
                    'legacy_taxonomy_slug',
                    'taxonomy_node:' . (int)$row['id'],
                    $base['attributes'],
                    $facetMap
                );
            }
        }
    }

    if (ingredientOntologyV3TableExists($db, 'canonical_ingredients')) {
        $stmt = $db->query("
            SELECT id, slug, name FROM canonical_ingredients ORDER BY id
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $base = ingredientOntologyV3LegacyBase(
                (string)$row['slug'],
                (string)$row['name']
            );
            $target = $entities['by_slug'][$base['slug']]
                ?? $entities['by_canonical'][(int)$row['id']]
                ?? null;
            if ($target === null) {
                continue;
            }
            $targetByCanonical[(int)$row['id']] = $target;
            $collapsedCanonical[(int)$row['id']] =
                $base['slug'] !== (string)$row['slug'];
            ingredientOntologyV3UpsertLabel(
                $db,
                $versionId,
                $target['id'],
                'und',
                (string)$row['name'],
                $base['slug'] !== (string)$row['slug']
                    ? 'attribute_alias'
                    : 'exact_alias',
                'accepted',
                'legacy_canonical_name',
                'canonical_ingredient:' . (int)$row['id'],
                $base['attributes'],
                $facetMap
            );
        }
    }
    if (ingredientOntologyV3TableExists($db, 'canonical_ingredients')) {
        $stmt = $db->query("
            SELECT id, parent_slug
            FROM canonical_ingredients
            WHERE parent_slug IS NOT NULL AND TRIM(parent_slug) <> ''
            ORDER BY id
        ");
        $primaryParent = $db->prepare("
            SELECT COUNT(*) FROM ingredient_ontology_relations
            WHERE ontology_version_id = ? AND from_entity_id = ?
              AND relation = 'is_a' AND is_primary = 1
              AND review_state = 'accepted'
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($collapsedCanonical[(int)$row['id']])) {
                continue;
            }
            $child = $targetByCanonical[(int)$row['id']] ?? null;
            $parentSlug = ingredientOntologyV3Slug(
                (string)$row['parent_slug']
            );
            $parent = $entities['by_slug'][$parentSlug] ?? null;
            if (
                $child === null
                || $parent === null
                || $child['id'] === $parent['id']
            ) {
                continue;
            }
            $primaryParent->execute([$versionId, $child['id']]);
            $isPrimary = (int)$primaryParent->fetchColumn() === 0;
            ingredientOntologyV3InsertRelation(
                $db,
                $versionId,
                $child['id'],
                $parent['id'],
                'is_a',
                $isPrimary,
                false,
                0.88,
                'legacy_canonical_parent',
                $isPrimary ? 'accepted' : 'pending',
                'forward',
                $isPrimary ? [] : ['candidate_secondary_parent' => true]
            );
        }
    }

    if ($treeId > 0) {
        $stmt = $db->prepare("
            SELECT parent_node_id, child_node_id, relation, is_primary, id
            FROM taxonomy_edges
            WHERE tree_id = ? AND active = 1
            ORDER BY child_node_id, is_primary DESC, sort_order, id
        ");
        $stmt->execute([$treeId]);
        $primaryChildren = [];
        $demoteFallbackParent = $db->prepare("
            UPDATE ingredient_ontology_relations
            SET is_primary = 0,
                review_state = 'pending',
                semantics_json =
                    '{\"candidate_secondary_parent\":true}',
                updated_at = CURRENT_TIMESTAMP
            WHERE ontology_version_id = ?
              AND from_entity_id = ?
              AND relation = 'is_a'
              AND is_primary = 1
              AND review_state = 'accepted'
              AND provenance IN (
                  'legacy_canonical_parent', 'core_seed'
              )
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (
                !empty(
                    $collapsedTaxonomy[(int)$row['child_node_id']]
                )
            ) {
                continue;
            }
            $child = $targetByTaxonomy[(int)$row['child_node_id']] ?? null;
            $parent = $targetByTaxonomy[(int)$row['parent_node_id']] ?? null;
            if ($child === null || $parent === null || $child['id'] === $parent['id']) {
                continue;
            }
            $isPrimary = !isset($primaryChildren[$child['id']]);
            if ($isPrimary) {
                $primaryChildren[$child['id']] = true;
                $demoteFallbackParent->execute([
                    $versionId,
                    $child['id'],
                ]);
            }
            ingredientOntologyV3InsertRelation(
                $db,
                $versionId,
                $child['id'],
                $parent['id'],
                'is_a',
                $isPrimary,
                false,
                0.90,
                'legacy_taxonomy_edge',
                $isPrimary ? 'accepted' : 'pending',
                'forward',
                $isPrimary ? [] : ['candidate_secondary_parent' => true]
            );
        }
    }

    if (ingredientOntologyV3TableExists($db, 'taxonomy_aliases') && $treeId > 0) {
        $stmt = $db->prepare("
            SELECT id, node_id, alias, source
            FROM taxonomy_aliases
            WHERE tree_id = ?
            ORDER BY id
        ");
        $stmt->execute([$treeId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $target = $targetByTaxonomy[(int)$row['node_id']] ?? null;
            if ($target === null) {
                continue;
            }
            $source = strtolower((string)$row['source']);
            $gemini = str_contains($source, 'gemini');
            $unsafe = ingredientOntologyV3AliasIsRetailUnsafe(
                (string)$row['alias']
            );
            $entityName = ingredientOntologyV3NormalizeLabel($target['name']);
            $aliasNormalized = ingredientOntologyV3NormalizeLabel(
                (string)$row['alias']
            );
            $defensible = !$gemini
                && !$unsafe
                && $aliasNormalized === $entityName;
            ingredientOntologyV3UpsertLabel(
                $db,
                $versionId,
                $target['id'],
                'und',
                (string)$row['alias'],
                $defensible ? 'exact_alias' : 'candidate_only',
                $gemini ? 'quarantined' : ($defensible ? 'accepted' : 'pending'),
                $gemini ? 'legacy_gemini_quarantine' : 'legacy_taxonomy_alias',
                'taxonomy_alias:' . (int)$row['id'],
                ingredientOntologyV3ExtractAttributes((string)$row['alias']),
                $facetMap
            );
        }
    }

    $entitiesBySlug = ingredientOntologyV3EntityMap(
        $db,
        $versionId
    )['by_slug'];
    $parentCount = $db->prepare("
        SELECT COUNT(*) FROM ingredient_ontology_relations
        WHERE ontology_version_id = ? AND from_entity_id = ?
          AND relation = 'is_a' AND is_primary = 1
          AND review_state = 'accepted'
    ");
    foreach (ingredientOntologyV3CoreEntities() as [$slug, , , $parentSlug]) {
        if (
            $parentSlug === null
            || !isset($entitiesBySlug[$slug], $entitiesBySlug[$parentSlug])
        ) {
            continue;
        }
        $parentCount->execute([$versionId, $entitiesBySlug[$slug]['id']]);
        if ((int)$parentCount->fetchColumn() === 0) {
            ingredientOntologyV3InsertRelation(
                $db,
                $versionId,
                $entitiesBySlug[$slug]['id'],
                $entitiesBySlug[$parentSlug]['id'],
                'is_a',
                true,
                false,
                1.0,
                'core_seed'
            );
        }
    }

    return [
        'tree_id' => $treeId,
        'entities' => ingredientOntologyV3EntityMap($db, $versionId),
        'taxonomy_targets' => $targetByTaxonomy,
        'canonical_targets' => $targetByCanonical,
    ];
}

function ingredientOntologyV3LabelIndex(PDO $db, int $versionId): array {
    $stmt = $db->prepare("
        SELECT l.id, l.entity_id, l.language, l.normalized_label, l.kind,
               l.provenance, l.source_ref,
               e.slug, e.canonical_name, e.identity_role,
               f.facet_key, fv.value_key
               ,p.required_cohort, p.required_evidence_kind,
               p.required_evidence_key
        FROM ingredient_ontology_labels l
        JOIN ingredient_ontology_entities e
          ON e.id = l.entity_id AND e.active = 1
        LEFT JOIN ingredient_ontology_label_attributes la
          ON la.label_id = l.id
        LEFT JOIN ingredient_ontology_facets f ON f.id = la.facet_id
        LEFT JOIN ingredient_ontology_facet_values fv
          ON fv.id = la.facet_value_id
        LEFT JOIN ingredient_ontology_label_context_policies p
          ON p.label_id = l.id
        WHERE l.ontology_version_id = ?
          AND l.review_state = 'accepted'
          AND l.kind IN ('exact_alias', 'attribute_alias')
        ORDER BY l.id, f.id
    ");
    $stmt->execute([$versionId]);
    $byId = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $labelId = (int)$row['id'];
        if (!isset($byId[$labelId])) {
            $byId[$labelId] = [
                'label_id' => $labelId,
                'entity_id' => (int)$row['entity_id'],
                'language' => (string)$row['language'],
                'normalized_label' => (string)$row['normalized_label'],
                'kind' => (string)$row['kind'],
                'provenance' => (string)$row['provenance'],
                'source_ref' => $row['source_ref'] !== null
                    ? (string)$row['source_ref']
                    : null,
                'slug' => (string)$row['slug'],
                'name' => (string)$row['canonical_name'],
                'identity_role' => (string)$row['identity_role'],
                'required_cohort' => $row['required_cohort'] !== null
                    ? (string)$row['required_cohort']
                    : null,
                'required_evidence_kind' =>
                    $row['required_evidence_kind'] !== null
                        ? (string)$row['required_evidence_kind']
                        : null,
                'required_evidence_key' =>
                    $row['required_evidence_key'] !== null
                        ? (string)$row['required_evidence_key']
                        : null,
                'attributes' => [],
            ];
        }
        if ($row['facet_key'] !== null && $row['value_key'] !== null) {
            $byId[$labelId]['attributes'][(string)$row['facet_key']] =
                (string)$row['value_key'];
        }
    }
    $index = [];
    foreach ($byId as $entry) {
        $index[$entry['normalized_label']][] = $entry;
    }
    return $index;
}

function ingredientOntologyV3LanguageMatches(
    string $candidate,
    string $requested
): bool {
    $candidate = ingredientOntologyV3NormalizeLanguage($candidate);
    $requested = ingredientOntologyV3NormalizeLanguage($requested);
    if ($requested === 'und') {
        return $candidate === 'und';
    }
    if ($candidate === 'und') {
        return true;
    }
    return $candidate === $requested
        || explode('-', $candidate)[0] === explode('-', $requested)[0];
}

function ingredientOntologyV3ResolveLabel(
    array $labelIndex,
    string $label,
    string $language = 'und',
    array $context = []
): array {
    $parsed = ingredientOntologyV3ExtractAttributes($label);
    foreach (ingredientOntologyV3LookupCandidates($label, $language) as $candidate) {
        $allEntries = $labelIndex[$candidate] ?? [];
        $entries = array_values(array_filter(
            $allEntries,
            static function (array $entry) use (
                $language,
                $context
            ): bool {
                if (!ingredientOntologyV3LanguageMatches(
                    (string)$entry['language'],
                    $language
                )) {
                    return false;
                }
                $requiredCohort = $entry['required_cohort'] ?? null;
                if (
                    $requiredCohort !== null
                    && (string)($context['cohort'] ?? '')
                        !== (string)$requiredCohort
                ) {
                    return false;
                }
                $requiredKind =
                    $entry['required_evidence_kind'] ?? null;
                $requiredKey =
                    $entry['required_evidence_key'] ?? null;
                if ($requiredKind !== null || $requiredKey !== null) {
                    return !empty(
                        $context['evidence'][
                            (string)$requiredKind
                        ][(string)$requiredKey]
                    );
                }
                return true;
            }
        ));
        if (!$entries) {
            if ($allEntries) {
                $contextCandidates = array_values(array_map(
                    static fn(array $entry): array => [
                        'entity_slug' => (string)$entry['slug'],
                        'language' => (string)$entry['language'],
                        'provenance' => (string)$entry['provenance'],
                        'source_ref' => $entry['source_ref'] ?? null,
                        'required_cohort' =>
                            $entry['required_cohort'] ?? null,
                        'required_evidence_kind' =>
                            $entry['required_evidence_kind'] ?? null,
                        'required_evidence_key' =>
                            $entry['required_evidence_key'] ?? null,
                        'attributes' => $entry['attributes'] ?? [],
                    ],
                    $allEntries
                ));
                $firstCandidate = $contextCandidates[0] ?? [];
                return [
                    'status' => 'unresolved',
                    'entity_id' => null,
                    'confidence' => 0.0,
                    'mapping_source' => 'context_gated_unresolved',
                    'attributes' => [],
                    'attribute_hints' => $parsed,
                    'context_gate_missing' => true,
                    'matched_label' => $candidate,
                    'required_language' =>
                        $firstCandidate['language'] ?? null,
                    'context_gate_provenance' =>
                        $firstCandidate['provenance'] ?? null,
                    'context_gate_source_ref' =>
                        $firstCandidate['source_ref'] ?? null,
                    'required_cohort' =>
                        $firstCandidate['required_cohort'] ?? null,
                    'required_evidence_kind' =>
                        $firstCandidate['required_evidence_kind'] ?? null,
                    'required_evidence_key' =>
                        $firstCandidate['required_evidence_key'] ?? null,
                    'proposed_entity_slug' =>
                        $firstCandidate['entity_slug'] ?? null,
                    'proposed_attributes' =>
                        $firstCandidate['attributes'] ?? [],
                    'context_candidates' => $contextCandidates,
                ];
            }
            continue;
        }
        $entities = [];
        $aliasAttributeConflicts = [];
        foreach ($entries as $entry) {
            $entityId = (int)$entry['entity_id'];
            if (!isset($entities[$entityId])) {
                $entities[$entityId] = $entry;
                continue;
            }
            foreach ($entry['attributes'] as $facet => $value) {
                if (
                    isset($entities[$entityId]['attributes'][$facet])
                    && (string)$entities[$entityId]['attributes'][$facet]
                        !== (string)$value
                ) {
                    $aliasAttributeConflicts[$facet] = [
                        'left' => (string)$entities[$entityId][
                            'attributes'
                        ][$facet],
                        'right' => (string)$value,
                    ];
                    continue;
                }
                $entities[$entityId]['attributes'][$facet] = $value;
            }
            if ($entry['kind'] === 'attribute_alias') {
                $entities[$entityId]['kind'] = 'attribute_alias';
            }
        }
        if ($aliasAttributeConflicts) {
            return [
                'status' => 'ambiguous',
                'entity_id' => null,
                'confidence' => 0.0,
                'mapping_source' => 'conflicting_alias_attributes',
                'attributes' => $parsed,
                'conflicts' => $aliasAttributeConflicts,
            ];
        }
        if (count($entities) > 1) {
            return [
                'status' => 'ambiguous',
                'entity_id' => null,
                'confidence' => 0.0,
                'mapping_source' => 'ambiguous_exact_alias',
                'attributes' => $parsed,
                'candidates' => array_values(array_map(
                    static fn(array $entry): array => [
                        'entity_id' => $entry['entity_id'],
                        'slug' => $entry['slug'],
                    ],
                    $entities
                )),
            ];
        }
        $entry = array_values($entities)[0];
        $attributes = $entry['attributes'];
        ksort($attributes, SORT_STRING);
        return [
            'status' => 'accepted',
            'entity_id' => $entry['entity_id'],
            'entity_slug' => $entry['slug'],
            'entity_name' => $entry['name'],
            'confidence' => $entry['kind'] === 'exact_alias' ? 1.0 : 0.99,
            'mapping_source' => $entry['kind'],
            'label_provenance' => $entry['provenance'],
            'label_source_ref' => $entry['source_ref'],
            'required_cohort' => $entry['required_cohort'],
            'required_evidence_kind' =>
                $entry['required_evidence_kind'],
            'required_evidence_key' =>
                $entry['required_evidence_key'],
            'attributes' => $attributes,
            'label_id' => $entry['label_id'],
            'matched_label' => $candidate,
        ];
    }
    return [
        'status' => 'unresolved',
        'entity_id' => null,
        'confidence' => 0.0,
        'mapping_source' => 'unresolved',
        'attributes' => [],
        'attribute_hints' => $parsed,
    ];
}

function ingredientOntologyV3AssertionRelations(
    array $entitiesBySlug,
    int $entityId,
    array $attributes
): array {
    $relations = [];
    $target = null;
    $relation = 'derived_from';
    if (($attributes['variety'] ?? null) === 'olive') {
        $target = $entitiesBySlug['olive']['id'] ?? null;
    } elseif (($attributes['variety'] ?? null) === 'almond') {
        $target = $entitiesBySlug['almond']['id'] ?? null;
    } elseif (($attributes['variety'] ?? null) === 'rice') {
        $target = $entitiesBySlug['rice']['id'] ?? null;
    } elseif (($attributes['variety'] ?? null) === 'vegetable') {
        $target = $entitiesBySlug['vegetable']['id'] ?? null;
    } elseif (($attributes['variety'] ?? null) === 'egg') {
        $target = $entitiesBySlug['egg']['id'] ?? null;
    }
    if ($target !== null && $target !== $entityId) {
        $relations[] = [
            'to_entity_id' => $target,
            'relation' => $relation,
            'direction' => 'forward',
            'confidence' => 1.0,
        ];
    }
    return $relations;
}

function ingredientOntologyV3UpsertMapping(
    PDO $db,
    int $versionId,
    string $ownerType,
    int $ownerId,
    string $sourceLabel,
    string $language,
    array $resolution,
    string $ownerFingerprint,
    array $facetMap,
    array $entitiesBySlug,
    bool $isStaple
): int {
    $attributes = $resolution['attributes'] ?? [];
    ksort($attributes, SORT_STRING);
    $evidence = [
        'matched_label' => $resolution['matched_label'] ?? null,
        'label_id' => $resolution['label_id'] ?? null,
        'legacy_source' => $resolution['legacy_source'] ?? null,
        'candidates' => $resolution['candidates'] ?? null,
        'conflicts' => $resolution['conflicts'] ?? null,
        'curated_rationale' =>
            $resolution['curated_rationale'] ?? null,
        'curated_provenance' =>
            $resolution['curated_provenance'] ?? null,
        'label_provenance' =>
            $resolution['label_provenance'] ?? null,
        'recipe_cohort' =>
            $resolution['recipe_cohort'] ?? null,
        'provider_review' =>
            $resolution['provider_review'] ?? null,
        'owner_evidence_keys' =>
            $resolution['owner_evidence_keys'] ?? null,
        'context_gate_missing' =>
            $resolution['context_gate_missing'] ?? null,
        'required_cohort' =>
            $resolution['required_cohort'] ?? null,
        'required_evidence_kind' =>
            $resolution['required_evidence_kind'] ?? null,
        'required_evidence_key' =>
            $resolution['required_evidence_key'] ?? null,
        'required_language' =>
            $resolution['required_language'] ?? null,
        'context_gate_provenance' =>
            $resolution['context_gate_provenance'] ?? null,
        'context_gate_source_ref' =>
            $resolution['context_gate_source_ref'] ?? null,
        'attribute_hints' =>
            $resolution['attribute_hints'] ?? null,
        'proposed_attributes' =>
            $resolution['proposed_attributes'] ?? null,
        'context_candidates' =>
            $resolution['context_candidates'] ?? null,
        'proposed_entity_slug' =>
            $resolution['entity_slug']
                ?? $resolution['proposed_entity_slug']
                ?? null,
        'proposed_entity_name' =>
            $resolution['entity_name'] ?? null,
    ];
    $evidence = array_filter(
        $evidence,
        static fn(mixed $value): bool => $value !== null && $value !== []
    );
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_mappings (
            ontology_version_id, owner_type, owner_id, owner_fingerprint,
            source_label, normalized_label, language, entity_id, status,
            confidence, mapping_source, evidence_json, attributes_json,
            is_staple, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(ontology_version_id, owner_type, owner_id) DO UPDATE SET
            owner_fingerprint = excluded.owner_fingerprint,
            source_label = excluded.source_label,
            normalized_label = excluded.normalized_label,
            language = excluded.language,
            entity_id = excluded.entity_id,
            status = excluded.status,
            confidence = excluded.confidence,
            mapping_source = excluded.mapping_source,
            evidence_json = excluded.evidence_json,
            attributes_json = excluded.attributes_json,
            is_staple = excluded.is_staple,
            updated_at = CURRENT_TIMESTAMP
    ");
    $normalizedSourceLabel =
        $ownerType === 'recipe_source_ingredient'
        && function_exists('ingredientOntologyV3NormalizeProviderLabel')
            ? ingredientOntologyV3NormalizeProviderLabel(
                $sourceLabel
            )['normalized']
            : mb_substr(
                ingredientOntologyV3NormalizeLabel($sourceLabel),
                0,
                200,
                'UTF-8'
            );
    $insert->execute([
        $versionId,
        $ownerType,
        $ownerId,
        $ownerFingerprint,
        mb_substr($sourceLabel, 0, 200, 'UTF-8'),
        $normalizedSourceLabel,
        ingredientOntologyV3NormalizeLanguage($language),
        $resolution['entity_id'] ?? null,
        $resolution['status'],
        max(0.0, min(1.0, (float)($resolution['confidence'] ?? 0))),
        (string)($resolution['mapping_source'] ?? 'unresolved'),
        ingredientOntologyV3Json($evidence),
        ingredientOntologyV3Json($attributes),
        $isStaple ? 1 : 0,
    ]);
    $idStmt = $db->prepare("
        SELECT id FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ? AND owner_type = ? AND owner_id = ?
    ");
    $idStmt->execute([$versionId, $ownerType, $ownerId]);
    $mappingId = (int)$idStmt->fetchColumn();
    ingredientOntologyV3ReplaceMappingSemantics(
        $db,
        $versionId,
        $mappingId,
        isset($resolution['entity_id'])
            ? (int)$resolution['entity_id']
            : null,
        $attributes,
        $facetMap,
        $entitiesBySlug,
        'deterministic_parser'
    );
    return $mappingId;
}

function ingredientOntologyV3ReplaceMappingSemantics(
    PDO $db,
    int $versionId,
    int $mappingId,
    ?int $entityId,
    array $attributes,
    array $facetMap,
    array $entitiesBySlug,
    string $provenance
): void {
    $db->prepare("
        DELETE FROM ingredient_ontology_mapping_attributes
        WHERE mapping_id = ?
    ")->execute([$mappingId]);
    $insertAttribute = $db->prepare("
        INSERT INTO ingredient_ontology_mapping_attributes (
            ontology_version_id, mapping_id, facet_id, facet_value_id,
            is_defining, provenance
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($attributes as $facet => $value) {
        if (!isset($facetMap[$facet]['values'][$value])) {
            continue;
        }
        $insertAttribute->execute([
            $versionId,
            $mappingId,
            $facetMap[$facet]['id'],
            $facetMap[$facet]['values'][$value],
            !empty($facetMap[$facet]['hard']) ? 1 : 0,
            mb_substr($provenance, 0, 100, 'UTF-8'),
        ]);
    }
    $db->prepare("
        DELETE FROM ingredient_ontology_mapping_relations WHERE mapping_id = ?
    ")->execute([$mappingId]);
    $insertRelation = $db->prepare("
        INSERT INTO ingredient_ontology_mapping_relations (
            ontology_version_id, mapping_id, to_entity_id, relation,
            direction, confidence, provenance, review_state
        )
        VALUES (?, ?, ?, ?, ?, ?, 'deterministic_attribute_semantics', 'accepted')
    ");
    if ($entityId !== null) {
        foreach (ingredientOntologyV3AssertionRelations(
            $entitiesBySlug,
            $entityId,
            $attributes
        ) as $relation) {
            $insertRelation->execute([
                $versionId,
                $mappingId,
                $relation['to_entity_id'],
                $relation['relation'],
                $relation['direction'],
                $relation['confidence'],
            ]);
        }
    }
}

function ingredientOntologyV3MappingAttributeIntegrityAudit(
    PDO $db,
    int $versionId
): array {
    $stmt = $db->prepare("
        SELECT mapping.id AS mapping_id,
               mapping.attributes_json,
               attribute.id AS attribute_id,
               attribute.ontology_version_id AS attribute_version_id,
               attribute.is_defining,
               facet.ontology_version_id AS facet_version_id,
               facet.facet_key, facet.hard_default,
               value.ontology_version_id AS value_version_id,
               value.value_key
        FROM ingredient_ontology_mappings mapping
        LEFT JOIN ingredient_ontology_mapping_attributes attribute
          ON attribute.mapping_id = mapping.id
        LEFT JOIN ingredient_ontology_facets facet
          ON facet.id = attribute.facet_id
        LEFT JOIN ingredient_ontology_facet_values value
          ON value.id = attribute.facet_value_id
        WHERE mapping.ontology_version_id = ?
        ORDER BY mapping.id, facet.facet_key
    ");
    $stmt->execute([$versionId]);
    $currentId = null;
    $expected = [];
    $actual = [];
    $defining = [];
    $attributesJsonValid = true;
    $mismatchCount = 0;
    $crossVersionCount = 0;
    $invalidAttributesJsonCount = 0;
    $mismatchSample = [];
    $flush = static function () use (
        &$currentId,
        &$expected,
        &$actual,
        &$defining,
        &$attributesJsonValid,
        &$mismatchCount,
        &$invalidAttributesJsonCount,
        &$mismatchSample
    ): void {
        if ($currentId === null) {
            return;
        }
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);
        $definingValid = !in_array(false, $defining, true);
        if (!$attributesJsonValid) {
            $invalidAttributesJsonCount++;
        }
        if (
            !$attributesJsonValid
            || $expected !== $actual
            || !$definingValid
        ) {
            $mismatchCount++;
            if (count($mismatchSample) < 100) {
                $mismatchSample[] = [
                    'mapping_id' => $currentId,
                    'attributes_json_valid' =>
                        $attributesJsonValid,
                    'attributes_json' => $expected,
                    'companion_attributes' => $actual,
                    'defining_flags_valid' => $definingValid,
                ];
            }
        }
    };
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mappingId = (int)$row['mapping_id'];
        if ($currentId !== null && $mappingId !== $currentId) {
            $flush();
            $expected = [];
            $actual = [];
            $defining = [];
            $attributesJsonValid = true;
        }
        if ($currentId !== $mappingId) {
            $currentId = $mappingId;
            $attributesJson = trim(
                (string)$row['attributes_json']
            );
            try {
                $decoded = json_decode(
                    $attributesJson,
                    true,
                    64,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $e) {
                $decoded = null;
            }
            $attributesJsonValid = is_array($decoded)
                && (
                    $attributesJson === '[]'
                    || str_starts_with($attributesJson, '{')
                );
            if ($attributesJsonValid) {
                foreach ($decoded as $facet => $value) {
                    if (!is_string($facet) || !is_string($value)) {
                        $attributesJsonValid = false;
                        break;
                    }
                }
            }
            $expected = $attributesJsonValid ? $decoded : [];
        }
        if ($row['attribute_id'] === null) {
            continue;
        }
        if (
            (int)$row['attribute_version_id'] !== $versionId
            || (int)$row['facet_version_id'] !== $versionId
            || (int)$row['value_version_id'] !== $versionId
        ) {
            $crossVersionCount++;
        }
        $facet = (string)$row['facet_key'];
        $actual[$facet] = (string)$row['value_key'];
        $defining[] = (int)$row['hard_default']
            === (int)$row['is_defining'];
    }
    $flush();
    return [
        'valid' => $mismatchCount === 0
            && $crossVersionCount === 0
            && $invalidAttributesJsonCount === 0,
        'mapping_attribute_mismatch_count' => $mismatchCount,
        'cross_version_attribute_count' => $crossVersionCount,
        'invalid_attributes_json_count' =>
            $invalidAttributesJsonCount,
        'mismatch_sample' => $mismatchSample,
        'authoritative_representation' => 'mapping.attributes_json',
        'defining_flag_policy' => 'facet.hard_default',
    ];
}

function ingredientOntologyV3CorpusHash(PDO $db): string {
    $hash = hash_init('sha256');
    $sources = [
        'products' => [
            'owner_type' => 'product',
            'sql' => "
                SELECT id, name, brand, category, prepared_food
                FROM products
                ORDER BY id
            ",
        ],
        'recipe_ingredients' => [
            'owner_type' => 'recipe_ingredient',
            'sql' => "
                SELECT si.*,
                       COALESCE(NULLIF(si.raw_text, ''), si.normalized_name)
                           AS source_label,
                       c.language,
                       c.primary_connector,
                       COALESCE(scope_origin.external_id, '')
                           AS origin_external_id,
                       COALESCE(scope_origin.locale, '') AS origin_locale
                FROM recipe_ingredients si
                JOIN recipe_catalog c ON c.id = si.recipe_id
                LEFT JOIN recipe_origins scope_origin
                  ON scope_origin.id = (
                      SELECT ro.id
                      FROM recipe_origins ro
                      WHERE ro.recipe_id = si.recipe_id
                        AND ro.connector = c.primary_connector
                      ORDER BY ro.id
                      LIMIT 1
                  )
                ORDER BY si.id
            ",
        ],
        'recipe_source_ingredients' => [
            'owner_type' => 'recipe_source_ingredient',
            'sql' => "
                SELECT si.*,
                       COALESCE(NULLIF(si.name, ''), si.normalized_name)
                           AS source_label,
                       c.language,
                       COALESCE(
                           NULLIF(scope_origin.connector, ''),
                           NULLIF(c.primary_connector, ''),
                           'unknown_legacy_adapter'
                       ) AS connector,
                       COALESCE(
                           scope_origin.metadata_version,
                           ''
                       ) AS metadata_version,
                       COALESCE(
                           scope_origin.metadata_schema_version,
                           ''
                       ) AS metadata_schema_version,
                       COALESCE(scope_origin.external_id, '')
                           AS origin_external_id,
                       COALESCE(scope_origin.locale, '') AS origin_locale
                FROM recipe_source_ingredients si
                JOIN recipe_catalog c ON c.id = si.recipe_id
                LEFT JOIN recipe_origins scope_origin
                  ON scope_origin.id = (
                      SELECT ro.id
                      FROM recipe_origins ro
                      WHERE ro.recipe_id = si.recipe_id
                        AND ro.connector = c.primary_connector
                      ORDER BY ro.id
                      LIMIT 1
                  )
                ORDER BY si.id
            ",
        ],
    ];
    foreach ($sources as $table => $source) {
        if (!ingredientOntologyV3TableExists($db, $table)) {
            continue;
        }
        hash_update($hash, $table . "\n");
        $stmt = $db->query($source['sql']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $fingerprint = $source['owner_type'] === 'product'
                ? ingredientOntologyV3ProductOwnerFingerprint($row)
                : ingredientOntologyV3RecipeOwnerFingerprint(
                    $source['owner_type'],
                    $row
                );
            hash_update(
                $hash,
                ingredientOntologyV3Json([
                    'owner_type' => $source['owner_type'],
                    'owner_id' => (int)$row['id'],
                    'fingerprint' => $fingerprint,
                ]) . "\n"
            );
        }
    }
    return hash_final($hash);
}

function ingredientOntologyV3HashQuery(
    PDO $db,
    mixed $hash,
    string $section,
    string $sql,
    array $params
): void {
    hash_update($hash, $section . "\n");
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        hash_update(
            $hash,
            ingredientOntologyV3Json(array_values($row)) . "\n"
        );
    }
}

function ingredientOntologyV3PortableContentHash(
    PDO $db,
    int $versionId
): string {
    $hash = hash_init('sha256');
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'entities',
        "SELECT slug, canonical_name, entity_kind, identity_role,
                active, provenance
         FROM ingredient_ontology_entities
         WHERE ontology_version_id = ?
         ORDER BY slug",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'labels',
        "SELECT entity.slug, label.language, label.label,
                label.normalized_label, label.kind, label.review_state,
                label.provenance, COALESCE(label.source_ref, '')
         FROM ingredient_ontology_labels label
         JOIN ingredient_ontology_entities entity
           ON entity.id = label.entity_id
         WHERE label.ontology_version_id = ?
         ORDER BY entity.slug, label.language,
                  label.normalized_label, label.kind",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'label_attributes',
        "SELECT entity.slug, label.language, label.normalized_label,
                label.kind, facet.facet_key, value.value_key,
                attribute.is_defining
         FROM ingredient_ontology_label_attributes attribute
         JOIN ingredient_ontology_labels label
           ON label.id = attribute.label_id
         JOIN ingredient_ontology_entities entity
           ON entity.id = label.entity_id
         JOIN ingredient_ontology_facets facet
           ON facet.id = attribute.facet_id
         JOIN ingredient_ontology_facet_values value
           ON value.id = attribute.facet_value_id
         WHERE attribute.ontology_version_id = ?
         ORDER BY entity.slug, label.language, label.normalized_label,
                  label.kind, facet.facet_key",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'legacy_mapping_attributes',
        "SELECT mapping.owner_type, mapping.owner_fingerprint,
                COALESCE(entity.slug, ''), facet.facet_key,
                value.value_key, attribute.is_defining,
                attribute.provenance
         FROM ingredient_ontology_mapping_attributes attribute
         JOIN ingredient_ontology_mappings mapping
           ON mapping.id = attribute.mapping_id
         LEFT JOIN ingredient_ontology_entities entity
           ON entity.id = mapping.entity_id
         JOIN ingredient_ontology_facets facet
           ON facet.id = attribute.facet_id
         JOIN ingredient_ontology_facet_values value
           ON value.id = attribute.facet_value_id
         WHERE attribute.ontology_version_id = ?
           AND mapping.owner_type IN ('product', 'recipe_ingredient')
         ORDER BY mapping.owner_type, mapping.owner_fingerprint,
                  facet.facet_key, value.value_key",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'relations',
        "SELECT source.slug AS source_slug,
                target.slug AS target_slug, relation.relation,
                relation.direction, relation.is_primary,
                relation.satisfies_required, relation.confidence,
                relation.provenance, relation.review_state,
                relation.semantics_json
         FROM ingredient_ontology_relations relation
         JOIN ingredient_ontology_entities source
           ON source.id = relation.from_entity_id
         JOIN ingredient_ontology_entities target
           ON target.id = relation.to_entity_id
         WHERE relation.ontology_version_id = ?
         ORDER BY source.slug, target.slug, relation.relation",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'facets',
        "SELECT facet_key, display_name, hard_default, active
         FROM ingredient_ontology_facets
         WHERE ontology_version_id = ?
         ORDER BY facet_key",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'facet_values',
        "SELECT facet.facet_key, value.value_key,
                value.display_name, value.active
         FROM ingredient_ontology_facet_values value
         JOIN ingredient_ontology_facets facet
           ON facet.id = value.facet_id
         WHERE value.ontology_version_id = ?
         ORDER BY facet.facet_key, value.value_key",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'entity_defaults',
        "SELECT entity.slug, facet.facet_key, value.value_key,
                default_value.is_defining, default_value.provenance
         FROM ingredient_ontology_entity_defaults default_value
         JOIN ingredient_ontology_entities entity
           ON entity.id = default_value.entity_id
         JOIN ingredient_ontology_facets facet
           ON facet.id = default_value.facet_id
         JOIN ingredient_ontology_facet_values value
           ON value.id = default_value.facet_value_id
         WHERE default_value.ontology_version_id = ?
         ORDER BY entity.slug, facet.facet_key",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'entity_facet_policies',
        "SELECT entity.slug, facet.facet_key, policy.allowed,
                policy.defining, policy.policy_hash, policy.provenance
         FROM ingredient_ontology_entity_facet_policies policy
         JOIN ingredient_ontology_entities entity
           ON entity.id = policy.entity_id
         JOIN ingredient_ontology_facets facet
           ON facet.id = policy.facet_id
         WHERE policy.ontology_version_id = ?
         ORDER BY entity.slug, facet.facet_key",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'label_context_policies',
        "SELECT entity.slug, label.language, label.normalized_label,
                label.kind, policy.required_cohort,
                policy.required_evidence_kind,
                policy.required_evidence_key,
                policy.policy_hash, policy.provenance
         FROM ingredient_ontology_label_context_policies policy
         JOIN ingredient_ontology_labels label
           ON label.id = policy.label_id
         JOIN ingredient_ontology_entities entity
           ON entity.id = label.entity_id
         WHERE policy.ontology_version_id = ?
         ORDER BY entity.slug, label.language,
                  label.normalized_label, label.kind",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'manifests',
        "SELECT manifest_key, manifest_version, manifest_hash,
                content_hash, reviewer,
                review_batch, metadata_json
         FROM ingredient_ontology_resolution_manifests
         WHERE ontology_version_id = ?
         ORDER BY manifest_key",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'edge_reviews',
        "SELECT child.slug,
                COALESCE(previous.slug, ''),
                COALESCE(next.slug, ''),
                review.change_kind, review.disposition,
                review.rationale, review.content_hash,
                review.reviewer, review.review_batch
         FROM ingredient_ontology_primary_edge_reviews review
         JOIN ingredient_ontology_entities child
           ON child.id = review.child_entity_id
         LEFT JOIN ingredient_ontology_entities previous
           ON previous.id = review.previous_parent_entity_id
         LEFT JOIN ingredient_ontology_entities next
           ON next.id = review.new_parent_entity_id
         WHERE review.ontology_version_id = ?
         ORDER BY child.slug",
        [$versionId]
    );
    return hash_final($hash);
}

function ingredientOntologyV3ContentHash(PDO $db, int $versionId): string {
    $hash = hash_init('sha256');
    hash_update(
        $hash,
        'portable:' . ingredientOntologyV3PortableContentHash(
            $db,
            $versionId
        ) . "\n"
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'mappings',
        "SELECT mapping.owner_type, mapping.owner_fingerprint,
                mapping.source_label, mapping.normalized_label,
                mapping.language, COALESCE(entity.slug, ''),
                mapping.status, mapping.confidence,
                mapping.mapping_source, mapping.attributes_json,
                mapping.is_staple,
                COALESCE(term.connector, ''),
                COALESCE(term.metadata_schema_version, ''),
                COALESCE(term.namespace, ''),
                COALESCE(term.provider_ref, ''),
                mapping.identity_basis,
                COALESCE(scope.scope_fingerprint, '')
         FROM ingredient_ontology_mappings mapping
         LEFT JOIN ingredient_ontology_entities entity
           ON entity.id = mapping.entity_id
         LEFT JOIN ingredient_ontology_provider_terms term
           ON term.id = mapping.provider_term_id
         LEFT JOIN ingredient_ontology_terminal_dispositions disposition
           ON disposition.id = mapping.terminal_disposition_id
         LEFT JOIN ingredient_ontology_disposition_scopes scope
           ON scope.id = disposition.scope_id
         WHERE mapping.ontology_version_id = ?
         ORDER BY mapping.owner_type, mapping.owner_fingerprint,
                  mapping.normalized_label, mapping.source_label,
                  mapping.language, mapping.status,
                  mapping.mapping_source, mapping.attributes_json",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'mapping_attributes',
        "SELECT mapping.owner_type, mapping.owner_fingerprint,
                attribute.ontology_version_id,
                facet.facet_key, value.value_key,
                attribute.is_defining, attribute.provenance
         FROM ingredient_ontology_mapping_attributes attribute
         JOIN ingredient_ontology_mappings mapping
           ON mapping.id = attribute.mapping_id
         JOIN ingredient_ontology_facets facet
           ON facet.id = attribute.facet_id
         JOIN ingredient_ontology_facet_values value
           ON value.id = attribute.facet_value_id
         WHERE mapping.ontology_version_id = ?
         ORDER BY mapping.owner_type, mapping.owner_fingerprint,
                  facet.facet_key, value.value_key",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'mapping_relations',
        "SELECT mapping.owner_type, mapping.owner_fingerprint,
                target.slug, relation.relation, relation.direction,
                relation.confidence, relation.provenance,
                relation.review_state
         FROM ingredient_ontology_mapping_relations relation
         JOIN ingredient_ontology_mappings mapping
           ON mapping.id = relation.mapping_id
         JOIN ingredient_ontology_entities target
           ON target.id = relation.to_entity_id
         WHERE relation.ontology_version_id = ?
         ORDER BY mapping.owner_type, mapping.owner_fingerprint,
                  target.slug, relation.relation, relation.direction,
                  relation.provenance, relation.review_state",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'products',
        "SELECT assertion.product_fingerprint,
                assertion.product_name,
                assertion.normalized_product_name,
                COALESCE(entity.slug, ''), assertion.status,
                assertion.confidence, assertion.attributes_json,
                assertion.rationale, assertion.provenance,
                assertion.review_state,
                COALESCE(scope.scope_fingerprint, '')
         FROM ingredient_ontology_curated_product_assertions assertion
         LEFT JOIN ingredient_ontology_entities entity
           ON entity.id = assertion.entity_id
         LEFT JOIN ingredient_ontology_terminal_dispositions disposition
           ON disposition.id = assertion.terminal_disposition_id
         LEFT JOIN ingredient_ontology_disposition_scopes scope
           ON scope.id = disposition.scope_id
         WHERE assertion.ontology_version_id = ?
         ORDER BY assertion.product_fingerprint",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'provider_terms',
        "SELECT term.connector, term.metadata_schema_version,
                term.namespace, term.provider_ref,
                COALESCE(term.default_title, ''),
                COALESCE(term.normalized_default_title, ''),
                COALESCE(term.title_hash, ''),
                term.observed_row_count, term.distinct_title_count,
                term.consistency_state, term.is_generic,
                term.mapping_status, term.review_state,
                COALESCE(entity.slug, ''), term.attributes_json,
                term.evidence_json, term.provenance,
                COALESCE(scope.scope_fingerprint, '')
         FROM ingredient_ontology_provider_terms term
         LEFT JOIN ingredient_ontology_entities entity
           ON entity.id = term.entity_id
         LEFT JOIN ingredient_ontology_terminal_dispositions disposition
           ON disposition.id = term.terminal_disposition_id
         LEFT JOIN ingredient_ontology_disposition_scopes scope
           ON scope.id = disposition.scope_id
         WHERE term.ontology_version_id = ?
         ORDER BY term.connector, term.metadata_schema_version,
                  term.namespace, term.provider_ref",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'provider_observations',
        "SELECT owner_fingerprint, connector,
                metadata_schema_version, namespace,
                COALESCE(provider_ref, ''),
                COALESCE(title_hash, ''), normalized_local_label,
                local_label_hash, consistency_state, ref_provenance,
                COALESCE(group_index, -1),
                COALESCE(group_position, -1), source_position,
                evidence_json
         FROM ingredient_ontology_provider_observations
         WHERE ontology_version_id = ?
         ORDER BY owner_fingerprint, connector,
                  metadata_schema_version, namespace,
                  COALESCE(provider_ref, ''), source_position,
                  normalized_local_label, local_label_hash",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'evidence',
        "SELECT evidence_kind, evidence_key, evidence_scope,
                COALESCE(owner_fingerprint, ''),
                COALESCE(connector, ''),
                COALESCE(metadata_schema_version, ''),
                COALESCE(provider_ref, ''),
                COALESCE(title_hash, ''),
                COALESCE(observation_hash, ''),
                scope_hash, payload_hash, payload_json,
                algorithm_hash, reviewer, review_batch
         FROM ingredient_ontology_evidence_sources
         WHERE ontology_version_id = ?
         ORDER BY evidence_kind, evidence_key",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'cohorts',
        "SELECT recipe_fingerprint, COALESCE(cohort, ''),
                winner_votes, runner_up_votes, margin,
                conflict_count, votes_json, algorithm_hash
         FROM ingredient_ontology_recipe_cohorts
         WHERE ontology_version_id = ?
         ORDER BY recipe_fingerprint",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'scopes',
        "SELECT scope_type, scope_key, scope_fingerprint,
                normalized_label, language, context_json, content_hash
         FROM ingredient_ontology_disposition_scopes
         WHERE ontology_version_id = ?
         ORDER BY scope_type, scope_fingerprint",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'dispositions',
        "SELECT scope.scope_fingerprint, disposition.disposition_code,
                disposition.disposition_name,
                COALESCE(entity.slug, ''),
                disposition.attributes_json, disposition.mechanism,
                disposition.evidence_json, disposition.evidence_hash,
                disposition.reviewer, disposition.review_batch,
                disposition.batch_hash, disposition.content_hash
         FROM ingredient_ontology_terminal_dispositions disposition
         JOIN ingredient_ontology_disposition_scopes scope
           ON scope.id = disposition.scope_id
         LEFT JOIN ingredient_ontology_entities entity
           ON entity.id = disposition.entity_id
         WHERE disposition.ontology_version_id = ?
         ORDER BY scope.scope_fingerprint",
        [$versionId]
    );
    ingredientOntologyV3HashQuery(
        $db,
        $hash,
        'assertion_history',
        "SELECT owner_type, owner_fingerprint, phase, prior_status,
                COALESCE(proposed_entity_slug, ''),
                proposed_confidence, proposed_attributes_json,
                proposed_relations_json, mapping_source,
                legacy_target_json, denied_provenance_json,
                evidence_hash, content_hash
         FROM ingredient_ontology_mapping_assertion_history
         WHERE ontology_version_id = ?
         ORDER BY owner_type, owner_fingerprint, phase,
                  COALESCE(proposed_entity_slug, ''),
                  mapping_source, proposed_attributes_json,
                  proposed_relations_json",
        [$versionId]
    );
    return hash_final($hash);
}

function ingredientOntologyV3OwnerFingerprintAudit(
    PDO $db,
    int $versionId,
    int $sampleLimit = 20
): array {
    $sampleLimit = max(1, min(100, $sampleLimit));
    $stale = [];
    $checked = 0;
    $staleCount = 0;
    $queries = [
        'product' => "
            SELECT m.owner_id, m.owner_fingerprint,
                   p.id, p.name, p.brand, p.category, p.prepared_food
            FROM ingredient_ontology_mappings m
            LEFT JOIN products p ON p.id = m.owner_id
            WHERE m.ontology_version_id = ?
              AND m.owner_type = 'product'
            ORDER BY m.owner_id
        ",
        'recipe_ingredient' => "
            SELECT m.owner_id, m.owner_fingerprint,
                   si.*, COALESCE(NULLIF(si.raw_text, ''), si.normalized_name)
                       AS source_label,
                   c.language, c.primary_connector,
                   COALESCE(scope_origin.external_id, '')
                       AS origin_external_id,
                   COALESCE(scope_origin.locale, '') AS origin_locale
            FROM ingredient_ontology_mappings m
            LEFT JOIN recipe_ingredients si ON si.id = m.owner_id
            LEFT JOIN recipe_catalog c ON c.id = si.recipe_id
            LEFT JOIN recipe_origins scope_origin
              ON scope_origin.id = (
                  SELECT ro.id
                  FROM recipe_origins ro
                  WHERE ro.recipe_id = si.recipe_id
                    AND ro.connector = c.primary_connector
                  ORDER BY ro.id
                  LIMIT 1
              )
            WHERE m.ontology_version_id = ?
              AND m.owner_type = 'recipe_ingredient'
            ORDER BY m.owner_id
        ",
        'recipe_source_ingredient' => "
            SELECT m.owner_id, m.owner_fingerprint,
                   si.*, COALESCE(NULLIF(si.name, ''), si.normalized_name)
                       AS source_label,
                   c.language,
                   COALESCE(
                       NULLIF(scope_origin.connector, ''),
                       NULLIF(c.primary_connector, ''),
                       'unknown_legacy_adapter'
                   ) AS connector,
                   COALESCE(
                       scope_origin.metadata_version,
                       ''
                   ) AS metadata_version,
                   COALESCE(
                       scope_origin.metadata_schema_version,
                       ''
                   ) AS metadata_schema_version,
                   COALESCE(scope_origin.external_id, '')
                       AS origin_external_id,
                   COALESCE(scope_origin.locale, '') AS origin_locale
            FROM ingredient_ontology_mappings m
            LEFT JOIN recipe_source_ingredients si ON si.id = m.owner_id
            LEFT JOIN recipe_catalog c ON c.id = si.recipe_id
            LEFT JOIN recipe_origins scope_origin
              ON scope_origin.id = (
                  SELECT ro.id
                  FROM recipe_origins ro
                  WHERE ro.recipe_id = si.recipe_id
                    AND ro.connector = c.primary_connector
                  ORDER BY ro.id
                  LIMIT 1
              )
            WHERE m.ontology_version_id = ?
              AND m.owner_type = 'recipe_source_ingredient'
            ORDER BY m.owner_id
        ",
    ];
    foreach ($queries as $ownerType => $sql) {
        $stmt = $db->prepare($sql);
        $stmt->execute([$versionId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $checked++;
            $sourceMissing = $row['id'] === null;
            $current = $sourceMissing
                ? null
                : (
                    $ownerType === 'product'
                        ? ingredientOntologyV3ProductOwnerFingerprint($row)
                        : ingredientOntologyV3RecipeOwnerFingerprint(
                            $ownerType,
                            $row
                        )
                );
            if ($current === (string)$row['owner_fingerprint']) {
                continue;
            }
            $staleCount++;
            if (count($stale) < $sampleLimit) {
                $stale[] = [
                    'owner_type' => $ownerType,
                    'owner_id' => (int)$row['owner_id'],
                    'source_missing' => $sourceMissing,
                ];
            }
        }
    }
    return [
        'valid' => $staleCount === 0,
        'checked' => $checked,
        'stale_count' => $staleCount,
        'stale_sample' => $stale,
    ];
}

function ingredientOntologyV3LegacyGeminiAliases(PDO $db): array {
    if (!ingredientOntologyV3TableExists($db, 'taxonomy_aliases')) {
        return [];
    }
    $result = [];
    $stmt = $db->query("
        SELECT normalized_alias FROM taxonomy_aliases
        WHERE active = 1 AND lower(source) LIKE '%gemini%'
    ");
    while ($value = $stmt->fetchColumn()) {
        $result[(string)$value] = true;
    }
    return $result;
}

function ingredientOntologyV3ProductLegacyTargets(
    PDO $db,
    array $canonicalTargets
): array {
    if (!ingredientOntologyV3TableExists($db, 'product_ingredients')) {
        return [];
    }
    $stmt = $db->query("
        SELECT product_id, ingredient_id, role, confidence, source
        FROM product_ingredients
        ORDER BY product_id,
            CASE role WHEN 'primary' THEN 0 ELSE 1 END,
            confidence DESC, id
    ");
    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ((string)$row['role'] !== 'primary') {
            continue;
        }
        $target = $canonicalTargets[(int)$row['ingredient_id']] ?? null;
        if ($target === null) {
            continue;
        }
        $result[(int)$row['product_id']][] = [
            'entity_id' => $target['id'],
            'entity_slug' => $target['slug'],
            'entity_name' => $target['name'],
            'confidence' => (float)$row['confidence'],
            'source' => (string)$row['source'],
        ];
    }
    return $result;
}

function ingredientOntologyV3LegacyFallbackResolution(
    string $legacySource,
    ?array $target,
    array $attributes,
    bool $geminiQuarantined = false
): array {
    if ($target === null) {
        return [
            'status' => 'unresolved',
            'entity_id' => null,
            'confidence' => 0.0,
            'mapping_source' => 'unresolved',
            'attributes' => $attributes,
            'legacy_source' => $legacySource,
        ];
    }
    $targetId = $target['id'] ?? $target['entity_id'] ?? null;
    $targetSlug = $target['slug'] ?? $target['entity_slug'] ?? null;
    $targetName = $target['name'] ?? $target['entity_name'] ?? null;
    if ($targetId === null || $targetSlug === null || $targetName === null) {
        return [
            'status' => 'unresolved',
            'entity_id' => null,
            'confidence' => 0.0,
            'mapping_source' => 'unresolved',
            'attributes' => $attributes,
            'legacy_source' => $legacySource,
        ];
    }
    $legacySource = strtolower(trim($legacySource));
    if ($geminiQuarantined || str_contains($legacySource, 'gemini')) {
        $mechanism = 'quarantined_model_evidence';
    } elseif ($legacySource === 'taxonomy_rule') {
        $mechanism = 'taxonomy_rule_evidence';
    } elseif (in_array(
        $legacySource,
        ['taxonomy_alias', 'taxonomy_slug', 'canonical_slug'],
        true
    )) {
        $mechanism = 'legacy_identity_candidate';
    } else {
        $mechanism = 'legacy_primary_candidate';
    }
    return [
        'status' => 'candidate',
        'entity_id' => (int)$targetId,
        'entity_slug' => (string)$targetSlug,
        'entity_name' => (string)$targetName,
        'confidence' => min(0.80, 0.50),
        'mapping_source' => $mechanism,
        'attributes' => $attributes,
        'legacy_source' => $legacySource,
    ];
}

function ingredientOntologyV3BuildMappings(
    PDO $db,
    int $versionId,
    array $legacy,
    array $facetMap,
    ?callable $progress = null
): array {
    $labelIndex = ingredientOntologyV3LabelIndex($db, $versionId);
    $entitiesBySlug = $legacy['entities']['by_slug'];
    $geminiAliases = ingredientOntologyV3LegacyGeminiAliases($db);
    $productLegacy = ingredientOntologyV3ProductLegacyTargets(
        $db,
        $legacy['canonical_targets']
    );
    $curatedProducts = function_exists(
        'ingredientOntologyV3CuratedProductMap'
    ) ? ingredientOntologyV3CuratedProductMap($db, $versionId) : [];
    $cohortMap = function_exists('ingredientOntologyV3RecipeCohortMap')
        ? ingredientOntologyV3RecipeCohortMap($db, $versionId)
        : [];
    $counts = [
        'product' => 0,
        'recipe_ingredient' => 0,
        'recipe_source_ingredient' => 0,
    ];
    $statuses = [
        'accepted' => 0,
        'candidate' => 0,
        'ambiguous' => 0,
        'unresolved' => 0,
        'rejected' => 0,
    ];

    $productStmt = $db->query("
        SELECT id, name, brand, category, prepared_food
        FROM products ORDER BY id
    ");
    while ($row = $productStmt->fetch(PDO::FETCH_ASSOC)) {
        $label = trim((string)$row['name']);
        $curated = $curatedProducts[(int)$row['id']] ?? null;
        $hasCuratedAssertion = false;
        if (
            $curated !== null
            && hash_equals(
                (string)$curated['product_fingerprint'],
                ingredientOntologyV3ProductOwnerFingerprint($row)
            )
        ) {
            $hasCuratedAssertion = true;
            $assertionProvenance =
                (string)$curated['provenance'];
            $resolution = [
                'status' => $curated['status'],
                'entity_id' => $curated['entity_id'],
                'confidence' => $curated['confidence'],
                'mapping_source' =>
                    $curated['status'] === 'accepted'
                        ? 'curated_product_manifest'
                        : (
                            $curated['status'] === 'candidate'
                                ? 'legacy_product_candidate'
                                : 'unresolved'
                        ),
                'attributes' => $curated['attributes'],
                'curated_rationale' => $curated['rationale'],
                'curated_provenance' =>
                    $curated['status'] === 'accepted'
                        ? $assertionProvenance
                        : null,
                'legacy_source' =>
                    $curated['status'] === 'accepted'
                        ? null
                        : $assertionProvenance,
            ];
        } else {
            $resolution = ingredientOntologyV3ResolveLabel(
                $labelIndex,
                $label,
                'und'
            );
        }
        if (
            !$hasCuratedAssertion
            && $resolution['status'] === 'unresolved'
        ) {
            $targets = $productLegacy[(int)$row['id']] ?? [];
            $unique = [];
            foreach ($targets as $target) {
                $unique[$target['entity_id']] = $target;
            }
            if (count($unique) > 1) {
                $resolution = [
                    'status' => 'ambiguous',
                    'entity_id' => null,
                    'confidence' => 0.0,
                    'mapping_source' => 'ambiguous_legacy_primary',
                    'attributes' => ingredientOntologyV3ExtractAttributes($label),
                    'candidates' => array_values($unique),
                ];
            } else {
                $target = $unique ? array_values($unique)[0] : null;
                $resolution = ingredientOntologyV3LegacyFallbackResolution(
                    (string)($target['source'] ?? 'unresolved'),
                    $target,
                    ingredientOntologyV3ExtractAttributes($label),
                    $target !== null
                        && str_contains(
                            strtolower((string)$target['source']),
                            'gemini'
                        )
                );
            }
        }
        ingredientOntologyV3UpsertMapping(
            $db,
            $versionId,
            'product',
            (int)$row['id'],
            $label,
            'und',
            $resolution,
            ingredientOntologyV3ProductOwnerFingerprint($row),
            $facetMap,
            $entitiesBySlug,
            false
        );
        $counts['product']++;
        $statuses[$resolution['status']]++;
    }

    $processRows = static function (
        string $table,
        string $ownerType
    ) use (
        $db,
        $versionId,
        $facetMap,
        $entitiesBySlug,
        $labelIndex,
        $geminiAliases,
        $legacy,
        $cohortMap,
        $progress,
        &$counts,
        &$statuses
    ): void {
        if (!ingredientOntologyV3TableExists($db, $table)) {
            return;
        }
        $nameColumn = $table === 'recipe_source_ingredients'
            ? 'COALESCE(NULLIF(si.name, \'\'), si.normalized_name)'
            : 'COALESCE(NULLIF(si.raw_text, \'\'), si.normalized_name)';
        $scopeJoin = "
            LEFT JOIN recipe_origins scope_origin
              ON scope_origin.id = (
                  SELECT ro.id
                  FROM recipe_origins ro
                  WHERE ro.recipe_id = si.recipe_id
                    AND ro.connector = c.primary_connector
                  ORDER BY ro.id
                  LIMIT 1
              )
        ";
        $scopeSelect = ",
            COALESCE(
                NULLIF(scope_origin.connector, ''),
                NULLIF(c.primary_connector, ''),
                'unknown_legacy_adapter'
            ) AS connector,
            COALESCE(scope_origin.external_id, '')
                AS origin_external_id,
            COALESCE(scope_origin.locale, '') AS origin_locale,
            COALESCE(scope_origin.metadata_version, '')
                AS metadata_version,
            COALESCE(scope_origin.metadata_schema_version, '')
                AS metadata_schema_version
        ";
        $stmt = $db->query("
            SELECT si.*, {$nameColumn} AS source_label,
                   si.canonical_ingredient_id,
                   si.taxonomy_node_id, si.mapping_confidence,
                   si.mapping_source, c.language
                   {$scopeSelect}
            FROM {$table} si
            JOIN recipe_catalog c ON c.id = si.recipe_id
            {$scopeJoin}
            ORDER BY si.id
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $label = trim((string)$row['source_label']);
            if ($label === '') {
                $label = trim((string)$row['normalized_name']);
            }
            $language = (string)($row['language'] ?? 'und');
            $cohort = $cohortMap[(int)$row['recipe_id']] ?? null;
            $effectiveLanguage = $cohort !== null
                ? (string)$cohort
                : $language;
            $ownerFingerprint =
                ingredientOntologyV3RecipeOwnerFingerprint(
                    $ownerType,
                    $row
                );
            $ownerContext = $ownerType
                === 'recipe_source_ingredient'
                && function_exists(
                    'ingredientOntologyV3OwnerEvidenceContext'
                )
                    ? ingredientOntologyV3OwnerEvidenceContext(
                        $db,
                        $versionId,
                        $row,
                        $ownerFingerprint
                    )
                    : [
                        'evidence' => [],
                        'provider_review' => null,
                    ];
            $resolution = ingredientOntologyV3ResolveLabel(
                $labelIndex,
                $label,
                $effectiveLanguage,
                [
                    'cohort' => $cohort,
                    'evidence' => $ownerContext['evidence'],
                ]
            );
            if ($ownerContext['provider_review'] !== null) {
                $resolution['provider_review'] =
                    $ownerContext['provider_review'];
            }
            if (!empty($ownerContext['evidence'])) {
                $resolution['owner_evidence_keys'] = array_map(
                    static fn(array $keys): array => array_keys($keys),
                    $ownerContext['evidence']
                );
            }
            if ($cohort !== null) {
                $resolution['recipe_cohort'] = $cohort;
            }
            if (
                $resolution['status'] === 'unresolved'
                && empty($resolution['context_gate_missing'])
            ) {
                $target = null;
                if ($row['taxonomy_node_id'] !== null) {
                    $target = $legacy['taxonomy_targets'][
                        (int)$row['taxonomy_node_id']
                    ] ?? null;
                }
                if (
                    $target === null
                    && $row['canonical_ingredient_id'] !== null
                ) {
                    $target = $legacy['canonical_targets'][
                        (int)$row['canonical_ingredient_id']
                    ] ?? null;
                }
                $normalized = ingredientOntologyV3NormalizeLabel($label);
                $resolution = ingredientOntologyV3LegacyFallbackResolution(
                    (string)$row['mapping_source'],
                    $target,
                    ingredientOntologyV3ExtractAttributes($label),
                    isset($geminiAliases[$normalized])
                );
            }
            if ($cohort !== null) {
                $resolution['recipe_cohort'] = $cohort;
            }
            $isStaple = $ownerType === 'recipe_ingredient'
                || $ownerType === 'recipe_source_ingredient'
                ? ingredientOntologyV3IsStapleLabel(
                    $label,
                    $effectiveLanguage
                )
                : false;
            ingredientOntologyV3UpsertMapping(
                $db,
                $versionId,
                $ownerType,
                (int)$row['id'],
                $label,
                $effectiveLanguage,
                $resolution,
                $ownerFingerprint,
                $facetMap,
                $entitiesBySlug,
                $isStaple
            );
            $counts[$ownerType]++;
            $statuses[$resolution['status']]++;
            $total = array_sum($counts);
            if ($progress !== null && $total % 10000 === 0) {
                $progress($total, $counts);
            }
        }
    };
    $processRows('recipe_ingredients', 'recipe_ingredient');
    $processRows(
        'recipe_source_ingredients',
        'recipe_source_ingredient'
    );
    return ['owners' => $counts, 'statuses' => $statuses];
}

function ingredientOntologyV3GraphValidate(
    PDO $db,
    int $versionId
): array {
    $entities = [];
    $entitySlugs = [];
    $stmt = $db->prepare("
        SELECT id, slug FROM ingredient_ontology_entities
        WHERE ontology_version_id = ? AND active = 1
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$row['id'];
        $entities[$id] = true;
        $entitySlugs[$id] = (string)$row['slug'];
    }
    $parents = [];
    $primaryParents = [];
    $secondaryParents = [];
    $dangling = [];
    $stmt = $db->prepare("
        SELECT id, from_entity_id, to_entity_id, relation,
               direction, is_primary, provenance
        FROM ingredient_ontology_relations
        WHERE ontology_version_id = ?
          AND review_state = 'accepted'
        ORDER BY id
    ");
    $stmt->execute([$versionId]);
    $acceptedRelations = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $from = (int)$row['from_entity_id'];
        $to = (int)$row['to_entity_id'];
        if (!isset($entities[$from], $entities[$to])) {
            if ((string)$row['relation'] === 'is_a') {
                $dangling[] = (int)$row['id'];
            }
            continue;
        }
        $relation = (string)$row['relation'];
        $acceptedRelations[] = [
            'id' => (int)$row['id'],
            'from' => $from,
            'to' => $to,
            'relation' => $relation,
            'direction' => (string)$row['direction'],
            'primary' => !empty($row['is_primary']),
            'provenance' => (string)$row['provenance'],
        ];
        if ($relation !== 'is_a') {
            continue;
        }
        $parents[$from][$to] = true;
        if (!empty($row['is_primary'])) {
            $primaryParents[$from][$to] = true;
        } else {
            $secondaryParents[$from][$to] = true;
        }
    }
    $multipleParents = [];
    $excessSecondaryParents = [];
    foreach ($entities as $entityId => $_present) {
        if (count($primaryParents[$entityId] ?? []) > 1) {
            $multipleParents[] = $entityId;
        }
        if (count($secondaryParents[$entityId] ?? []) > 2) {
            $excessSecondaryParents[] = $entityId;
        }
    }
    $children = [];
    $indegree = [];
    foreach (array_keys($entities) as $entityId) {
        $indegree[$entityId] = count($parents[$entityId] ?? []);
        foreach (array_keys($parents[$entityId] ?? []) as $parentId) {
            $children[(int)$parentId][$entityId] = true;
        }
    }
    $queue = [];
    foreach ($indegree as $entityId => $count) {
        if ($count === 0) {
            $queue[] = (int)$entityId;
        }
    }
    sort($queue, SORT_NUMERIC);
    $topological = [];
    for ($offset = 0; $offset < count($queue); $offset++) {
        $node = $queue[$offset];
        $topological[] = $node;
        foreach (array_keys($children[$node] ?? []) as $childId) {
            $childId = (int)$childId;
            $indegree[$childId]--;
            if ($indegree[$childId] === 0) {
                $queue[] = $childId;
            }
        }
    }
    $cyclicEntityIds = [];
    if (count($topological) !== count($entities)) {
        foreach ($indegree as $entityId => $count) {
            if ($count > 0) {
                $cyclicEntityIds[] = (int)$entityId;
            }
        }
    }
    $cycles = $cyclicEntityIds
        ? [array_slice($cyclicEntityIds, 0, 100)]
        : [];
    $crossVersion = (int)$db->query("
        SELECT COUNT(*)
        FROM ingredient_ontology_relations r
        JOIN ingredient_ontology_entities f ON f.id = r.from_entity_id
        JOIN ingredient_ontology_entities t ON t.id = r.to_entity_id
        WHERE r.ontology_version_id = {$versionId}
          AND (
              f.ontology_version_id != r.ontology_version_id
              OR t.ontology_version_id != r.ontology_version_id
          )
    ")->fetchColumn();
    $roots = [];
    foreach (array_keys($entities) as $entityId) {
        if (empty($parents[$entityId])) {
            $roots[] = $entityId;
        }
    }

    $foodIds = array_keys(array_filter(
        $entitySlugs,
        static fn(string $slug): bool => $slug === 'food'
    ));
    $foodId = count($foodIds) === 1 ? (int)$foodIds[0] : 0;
    $missingPrimaryParents = [];
    foreach (array_keys($entities) as $entityId) {
        if (
            $entityId !== $foodId
            && count($primaryParents[$entityId] ?? []) !== 1
        ) {
            $missingPrimaryParents[] = $entityId;
        }
    }

    $unreachable = [];
    $ancestorOverflow = [];
    $depthOverflow = [];
    $pathOverflow = [];
    $ancestorCounts = [];
    $maximumDepths = [];
    $rootPathCounts = [];
    $traversalExpansions = 0;
    $traversalExpansionLimit = max(
        16384,
        min(2000000, count($entities) * 256)
    );
    $traversalExpansionExceeded = false;
    if (!$cycles && $foodId > 0) {
        $memo = [];
        foreach ($topological as $node) {
            $traversalExpansions++;
            if ($traversalExpansions > $traversalExpansionLimit) {
                $traversalExpansionExceeded = true;
                break;
            }
            if ($node === $foodId) {
                $memo[$node] = [
                    'ancestors' => [],
                    'depth' => 0,
                    'paths' => 1,
                ];
                continue;
            }
            $ancestors = [];
            $maximumDepth = 0;
            $pathsToFood = 0;
            foreach (array_keys($parents[$node] ?? []) as $parentId) {
                $parentId = (int)$parentId;
                $traversalExpansions++;
                if ($traversalExpansions > $traversalExpansionLimit) {
                    $traversalExpansionExceeded = true;
                    break 2;
                }
                $ancestors[$parentId] = true;
                $parentStats = $memo[$parentId] ?? [
                    'ancestors' => [],
                    'depth' => 0,
                    'paths' => 0,
                ];
                $maximumDepth = max(
                    $maximumDepth,
                    1 + (int)$parentStats['depth']
                );
                $pathsToFood = min(
                    9,
                    $pathsToFood + (int)$parentStats['paths']
                );
                foreach (
                    array_keys($parentStats['ancestors']) as $ancestorId
                ) {
                    $traversalExpansions++;
                    if (
                        $traversalExpansions
                        > $traversalExpansionLimit
                    ) {
                        $traversalExpansionExceeded = true;
                        break 3;
                    }
                    $ancestors[(int)$ancestorId] = true;
                    if (count($ancestors) > 64) {
                        break;
                    }
                }
                if (count($ancestors) > 64) {
                    break;
                }
            }
            $memo[$node] = [
                'ancestors' => $ancestors,
                'depth' => $maximumDepth,
                'paths' => $pathsToFood,
            ];
        }
        foreach (array_keys($entities) as $start) {
            $stats = $memo[$start] ?? [
                'ancestors' => [],
                'depth' => 0,
                'paths' => 0,
            ];
            $ancestorCounts[$start] = count($stats['ancestors']);
            $maximumDepths[$start] = (int)$stats['depth'];
            $rootPathCounts[$start] = (int)$stats['paths'];
            if ($ancestorCounts[$start] > 64) {
                $ancestorOverflow[$start] = true;
            }
            if ($maximumDepths[$start] > 32) {
                $depthOverflow[$start] = true;
            }
            if ($rootPathCounts[$start] > 8) {
                $pathOverflow[$start] = true;
            }
            if ($rootPathCounts[$start] === 0) {
                $unreachable[] = $start;
            }
        }
    } elseif ($foodId <= 0) {
        $unreachable = array_keys($entities);
    }

    $pairRelations = [];
    $reciprocal = [];
    foreach ($acceptedRelations as $relation) {
        $pair = $relation['from'] . ':' . $relation['to'];
        $pairRelations[$pair][$relation['relation']][] =
            (string)$relation['provenance'];
        if (
            in_array($relation['relation'], [
                'is_a', 'variant_of', 'derived_from', 'component_of',
            ], true)
            && isset(
                $pairRelations[
                    $relation['to'] . ':' . $relation['from']
                ][$relation['relation']]
            )
        ) {
            $reciprocal[] = [
                $relation['from'],
                $relation['to'],
                $relation['relation'],
            ];
        }
    }
    $pairConflicts = [];
    foreach ($pairRelations as $pair => $types) {
        $controllerOwned = false;
        foreach ($types as $provenances) {
            if (in_array(
                'autonomous_controller',
                $provenances,
                true
            )) {
                $controllerOwned = true;
                break;
            }
        }
        if (count($types) > 1 && $controllerOwned) {
            $pairConflicts[] = [
                'pair' => $pair,
                'relations' => array_keys($types),
            ];
        }
    }

    $valid = !$dangling
        && !$multipleParents
        && !$excessSecondaryParents
        && !$cycles
        && $crossVersion === 0
        && count($entities) > 0
        && $foodId > 0
        && count($roots) === 1
        && $roots[0] === $foodId
        && !$missingPrimaryParents
        && !$unreachable
        && !$ancestorOverflow
        && !$depthOverflow
        && !$pathOverflow
        && !$traversalExpansionExceeded
        && !$pairConflicts
        && !$reciprocal;
    return [
        'valid' => $valid,
        'entity_count' => count($entities),
        'root_count' => count($roots),
        'food_entity_id' => $foodId > 0 ? $foodId : null,
        'dangling_relation_ids' => $dangling,
        'multiple_parent_entity_ids' => $multipleParents,
        'missing_primary_parent_entity_ids' =>
            array_slice($missingPrimaryParents, 0, 100),
        'excess_secondary_parent_entity_ids' =>
            array_slice($excessSecondaryParents, 0, 100),
        'unreachable_from_food_entity_ids' =>
            array_slice($unreachable, 0, 100),
        'ancestor_overflow_entity_ids' =>
            array_map('intval', array_keys($ancestorOverflow)),
        'depth_overflow_entity_ids' =>
            array_map('intval', array_keys($depthOverflow)),
        'path_overflow_entity_ids' =>
            array_map('intval', array_keys($pathOverflow)),
        'maximum_ancestor_count' =>
            $ancestorCounts ? max($ancestorCounts) : 0,
        'maximum_depth' =>
            $maximumDepths ? max($maximumDepths) : 0,
        'maximum_root_path_count' =>
            $rootPathCounts ? max($rootPathCounts) : 0,
        'traversal_expansions' => $traversalExpansions,
        'traversal_expansion_limit' => $traversalExpansionLimit,
        'traversal_expansion_exceeded' =>
            $traversalExpansionExceeded,
        'relation_pair_conflicts' =>
            array_slice($pairConflicts, 0, 100),
        'reciprocal_relation_conflicts' =>
            array_slice($reciprocal, 0, 100),
        'cycles' => array_slice($cycles, 0, 20),
        'cross_version_relations' => $crossVersion,
    ];
}

function ingredientOntologyV3CorpusCompleteness(
    PDO $db,
    int $versionId
): array {
    $owners = [
        'product' => 'products',
        'recipe_ingredient' => 'recipe_ingredients',
        'recipe_source_ingredient' => 'recipe_source_ingredients',
    ];
    $result = [];
    $complete = true;
    foreach ($owners as $ownerType => $table) {
        $sourceCount = ingredientOntologyV3TableExists($db, $table)
            ? (int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn()
            : 0;
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM ingredient_ontology_mappings
            WHERE ontology_version_id = ? AND owner_type = ?
        ");
        $stmt->execute([$versionId, $ownerType]);
        $mappingCount = (int)$stmt->fetchColumn();
        $missingStmt = $db->prepare("
            SELECT COUNT(*)
            FROM {$table} source
            LEFT JOIN ingredient_ontology_mappings m
              ON m.ontology_version_id = ?
             AND m.owner_type = ?
             AND m.owner_id = source.id
            WHERE m.id IS NULL
        ");
        $missingStmt->execute([$versionId, $ownerType]);
        $missing = (int)$missingStmt->fetchColumn();
        $result[$ownerType] = [
            'source_count' => $sourceCount,
            'mapping_count' => $mappingCount,
            'missing_count' => $missing,
        ];
        if ($sourceCount !== $mappingCount || $missing !== 0) {
            $complete = false;
        }
    }
    return ['complete' => $complete, 'owners' => $result];
}

function ingredientOntologyV3BuildCandidate(
    PDO $db,
    array $options = []
): array {
    ingredientOntologyV3SchemaMigrate($db);
    $model = trim((string)(
        $options['model'] ?? ingredientOntologyV3ConfiguredProposalModel()
    ));
    if ($model === '') {
        $model = INGREDIENT_ONTOLOGY_V3_DEFAULT_MODEL;
    }
    $corpusProfile = ingredientOntologyV3ResolveCorpusProfile($options);
    $frozenCorpusAudit = ingredientOntologyV3FrozenCorpusAudit(
        $db,
        $corpusProfile
    );
    if (!$frozenCorpusAudit['valid']) {
        throw new RuntimeException(
            'selected frozen corpus profile does not match the database: '
            . ingredientOntologyV3Json($frozenCorpusAudit)
        );
    }
    $resolutionManifest = ingredientOntologyV3ResolutionManifest();
    $corpusHash = ingredientOntologyV3CorpusHash($db);
    $version = trim((string)($options['version'] ?? ''));
    if ($version === '') {
        $version = 'v3-' . gmdate('Ymd-His') . '-' . substr($corpusHash, 0, 10);
    }
    if (
        strlen($version) > 80
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $version)
    ) {
        throw new InvalidArgumentException('ontology version is invalid');
    }
    $activationPolicy = (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && (string)($options['activation_policy'] ?? '') === 'test_only'
    ) ? 'test_only' : (string)(
        $resolutionManifest['activation_policy'] ?? 'blocked'
    );
    $activationBlockReason = $activationPolicy === 'test_only'
        ? 'Synthetic test-only activation policy'
        : (string)(
            $resolutionManifest['activation_block_reason']
                ?? 'Full ontology resolution remains shadow-only.'
        );
    $frozenSubjectsHash =
        ingredientOntologyV3SubjectUniverseHash($corpusProfile);
    $policyHash = ingredientOntologyV3VersionPolicyHash(
        $corpusProfile,
        $activationPolicy,
        $activationBlockReason
    );
    $parentId = isset($options['parent_version_id'])
        ? max(0, (int)$options['parent_version_id'])
        : (int)($db->query("
            SELECT id FROM ingredient_ontology_versions
            WHERE status IN ('ready', 'active')
            ORDER BY id DESC LIMIT 1
        ")->fetchColumn() ?: 0);
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_versions (
            version, status, schema_hash, prompt_hash, model_hash,
            model_name, corpus_hash, content_hash, parent_version_id,
            activation_policy, activation_block_reason, corpus_profile,
            frozen_corpus_hash, frozen_subjects_hash, policy_hash
        )
        VALUES (?, 'building', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $version,
        ingredientOntologyV3SchemaHash(),
        ingredientOntologyV3PromptHash(),
        ingredientOntologyV3ModelHash($model),
        $model,
        $corpusHash,
        str_repeat('0', 64),
        $parentId > 0 ? $parentId : null,
        $activationPolicy,
        $activationBlockReason,
        $corpusProfile,
        (string)$frozenCorpusAudit['actual_hash'],
        $frozenSubjectsHash,
        $policyHash,
    ]);
    $versionId = (int)$db->lastInsertId();
    try {
        $db->beginTransaction();
        $facetMap = ingredientOntologyV3SeedFacets($db, $versionId);
        $legacy = ingredientOntologyV3SeedLegacyEntities(
            $db,
            $versionId,
            $facetMap
        );
        $resolutionManifest =
            ingredientOntologyV3RegisterResolutionManifest(
                $db,
                $versionId,
                (string)$frozenCorpusAudit['actual_hash']
            );
        $manifestEvidence = ingredientOntologyV3SeedManifestEvidence(
            $db,
            $versionId,
            $resolutionManifest
        );
        $policyEvidenceId = ingredientOntologyV3InsertEvidenceSource(
            $db,
            $versionId,
            (int)$resolutionManifest['id'],
            'curated_manifest',
            'full-resolution-entity-facet-policy',
            [
                'manifest_hash' => $resolutionManifest['manifest_hash'],
                'identity_roles_are_orthogonal' => true,
                'structural_identity_allowed' => false,
                'ancestry_identity_allowed' => false,
            ],
            'entity-facet-policy-v1'
        );
        $resolutionEntities = ingredientOntologyV3ApplyResolutionEntities(
            $db,
            $versionId,
            $facetMap,
            $resolutionManifest,
            $policyEvidenceId
        );
        $curated = function_exists('ingredientOntologyV3ApplyCuratedSeed')
            ? ingredientOntologyV3ApplyCuratedSeed(
                $db,
                $versionId,
                $facetMap
            )
            : [];
        $resolutionAliases = ingredientOntologyV3ApplyResolutionAliases(
            $db,
            $versionId,
            $facetMap,
            $resolutionManifest,
            $resolutionEntities
        );
        $resolutionProducts =
            ingredientOntologyV3ApplyResolutionProductReviews(
                $db,
                $versionId,
                $resolutionEntities['duplicates']
            );
        $cohorts = ingredientOntologyV3BuildRecipeCohorts(
            $db,
            $versionId,
            (int)$resolutionManifest['id']
        );
        $legacy['entities'] = ingredientOntologyV3EntityMap(
            $db,
            $versionId
        );
        $mappingResult = ingredientOntologyV3BuildMappings(
            $db,
            $versionId,
            $legacy,
            $facetMap,
            $options['progress'] ?? null
        );
        $preTerminalMappingResult = $mappingResult;
        $candidateHistory =
            ingredientOntologyV3SnapshotCandidateAssertions(
                $db,
                $versionId,
                'post_mapping'
            );
        $providerTerms = ingredientOntologyV3BuildProviderTerms(
            $db,
            $versionId
        );
        $ruleAdjudications = ingredientOntologyV3SeedRuleAdjudications(
            $db,
            $versionId,
            (int)$resolutionManifest['id']
        );
        $providerEvidence =
            ingredientOntologyV3BuildProviderClusterEvidence(
                $db,
                $versionId,
                (int)$resolutionManifest['id']
            );
        $modifierEvidence = ingredientOntologyV3BuildModifierEvidence(
            $db,
            $versionId,
            (int)$resolutionManifest['id']
        );
        $terminalDispositions =
            ingredientOntologyV3FinalizeTerminalDispositions(
                $db,
                $versionId,
                $resolutionManifest
            );
        $resolutionGold =
            ingredientOntologyV3EvaluateResolutionGold(
                $db,
                $versionId,
                false
            );
        $mappingResult = ingredientOntologyV3MappingDispositionSummary(
            $db,
            $versionId
        );
        $graph = ingredientOntologyV3GraphValidate($db, $versionId);
        $corpus = ingredientOntologyV3CorpusCompleteness($db, $versionId);
        $dispositionAudit = ingredientOntologyV3DispositionAudit(
            $db,
            $versionId
        );
        $hashIntegrity = ingredientOntologyV3HashIntegrityAudit(
            $db,
            $versionId,
            false
        );
        $mappingAttributeIntegrity =
            ingredientOntologyV3MappingAttributeIntegrityAudit(
                $db,
                $versionId
            );
        $frozenCorpusAudit = ingredientOntologyV3FrozenCorpusAudit(
            $db,
            $corpusProfile
        );
        $subjectUniverse =
            ingredientOntologyV3SubjectUniverseAudit(
                $db,
                $versionId,
                $corpusProfile
            );
        $matcherGold = ingredientOntologyV3EvaluateGold(
            $db,
            $versionId
        );
        $portableContentHash =
            ingredientOntologyV3PortableContentHash($db, $versionId);
        $contentHash = ingredientOntologyV3ContentHash($db, $versionId);
        $resolutionGoldHash = (string)(
            $resolutionManifest['file_hashes'][
                INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_FILENAME
            ] ?? ''
        );
        if (strlen($resolutionGoldHash) !== 64) {
            throw new RuntimeException(
                'resolution gold hash is missing from the review manifest'
            );
        }
        $sealHash = ingredientOntologyV3Hash([
            'schema_hash' => ingredientOntologyV3SchemaHash(),
            'prompt_hash' => ingredientOntologyV3PromptHash(),
            'model_hash' => ingredientOntologyV3ModelHash($model),
            'corpus_hash' => $corpusHash,
            'content_hash' => $contentHash,
            'portable_content_hash' => $portableContentHash,
            'review_manifest_hash' =>
                $resolutionManifest['manifest_hash'],
            'resolution_gold_hash' => $resolutionGoldHash,
            'matcher_gold_hash' =>
                INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_SHA256,
            'matcher_gold_case_ids_hash' =>
                INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_IDS_SHA256,
            'matcher_gold_case_count' =>
                INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_COUNT,
            'corpus_profile' => $corpusProfile,
            'frozen_corpus_hash' =>
                (string)$frozenCorpusAudit['actual_hash'],
            'frozen_subjects_hash' => $frozenSubjectsHash,
            'activation_policy' => $activationPolicy,
            'activation_block_reason' => $activationBlockReason,
            'policy_hash' => $policyHash,
        ]);
        $sourceIdentity = ingredientOntologyV3OwnerFingerprintAudit(
            $db,
            $versionId
        );
        $currentCorpusHash = ingredientOntologyV3CorpusHash($db);
        $report = [
            'schema_version' => INGREDIENT_ONTOLOGY_V3_SCHEMA_VERSION,
            'graph' => $graph,
            'corpus' => $corpus,
            'content_hash' => $contentHash,
            'portable_content_hash' => $portableContentHash,
            'seal_hash' => $sealHash,
            'source_identity' => $sourceIdentity,
            'mapping_build' => $mappingResult,
            'pre_terminal_mapping_build' =>
                $preTerminalMappingResult,
            'candidate_history' => $candidateHistory,
            'curated_seed' => $curated,
            'resolution_manifest' => [
                'manifest_version' =>
                    $resolutionManifest['manifest_version'],
                'manifest_hash' => $resolutionManifest['manifest_hash'],
                'content_hash' => $resolutionManifest['content_hash'],
                'evidence_rows' => $manifestEvidence['count'],
            ],
            'resolution_entities' => $resolutionEntities,
            'resolution_aliases' => $resolutionAliases,
            'resolution_gold' => $resolutionGold,
            'matcher_gold' => $matcherGold,
            'frozen_corpus' => $frozenCorpusAudit,
            'subject_universe' => $subjectUniverse,
            'resolution_products' => $resolutionProducts,
            'cohorts' => $cohorts,
            'provider_terms' => $providerTerms,
            'provider_cluster_evidence' => $providerEvidence,
            'modifier_evidence' => $modifierEvidence,
            'rule_adjudications' => $ruleAdjudications,
            'terminal_dispositions' => $terminalDispositions,
            'disposition_audit' => $dispositionAudit,
            'hash_integrity' => $hashIntegrity,
            'mapping_attribute_integrity' =>
                $mappingAttributeIntegrity,
            'shadow_only' => true,
            'activated' => false,
            'activation_policy' => $activationPolicy,
            'activation_block_reason' => $activationBlockReason,
            'corpus_profile' => $corpusProfile,
            'policy_hash' => $policyHash,
            'selected_proposal_model' => $model,
        ];
        $ready = $graph['valid']
            && $graph['root_count'] === 1
            && $corpus['complete']
            && $providerTerms['complete']
            && $resolutionAliases['collision_audit']['valid']
            && $resolutionGold['valid']
            && $matcherGold['valid']
            && $frozenCorpusAudit['valid']
            && $subjectUniverse['valid']
            && $dispositionAudit['valid']
            && $hashIntegrity['valid']
            && $mappingAttributeIntegrity['valid']
            && $candidateHistory['complete']
            && $candidateHistory['candidate_count']
                === (int)(
                    $preTerminalMappingResult['statuses']['candidate']
                        ?? 0
                )
            && $sourceIdentity['valid']
            && hash_equals($corpusHash, $currentCorpusHash);
        $update = $db->prepare("
            UPDATE ingredient_ontology_versions SET
                status = ?,
                validation_report_json = ?,
                content_hash = ?,
                portable_content_hash = ?,
                review_manifest_hash = ?,
                resolution_gold_hash = ?,
                seal_hash = ?,
                ready_at = CASE WHEN ? = 'ready' THEN CURRENT_TIMESTAMP ELSE NULL END,
                failed_at = CASE WHEN ? = 'failed' THEN CURRENT_TIMESTAMP ELSE NULL END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $status = $ready ? 'ready' : 'failed';
        if ($ready) {
            ingredientOntologyV3WithPublicationGuard(
                $db,
                static fn() => $update->execute([
                    $status,
                    ingredientOntologyV3Json($report),
                    $contentHash,
                    $portableContentHash,
                    (string)$resolutionManifest['manifest_hash'],
                    $resolutionGoldHash,
                    $sealHash,
                    $status,
                    $status,
                    $versionId,
                ])
            );
        } else {
            $update->execute([
                $status,
                ingredientOntologyV3Json($report),
                $contentHash,
                $portableContentHash,
                (string)$resolutionManifest['manifest_hash'],
                $resolutionGoldHash,
                $sealHash,
                $status,
                $status,
                $versionId,
            ]);
        }
        $db->commit();
        if (!$ready) {
            throw new RuntimeException(
                'candidate ontology failed deterministic validation: '
                . ingredientOntologyV3Json([
                    'graph' => $graph,
                    'corpus_complete' => $corpus['complete'],
                    'provider_complete' => $providerTerms['complete'],
                    'alias_collisions' =>
                        $resolutionAliases['collision_audit'],
                    'dispositions' => $dispositionAudit,
                    'resolution_gold' => $resolutionGold,
                    'matcher_gold' => $matcherGold,
                    'frozen_corpus' => $frozenCorpusAudit,
                    'subject_universe' => $subjectUniverse,
                    'hash_integrity' => $hashIntegrity,
                    'mapping_attribute_integrity' =>
                        $mappingAttributeIntegrity,
                    'candidate_history' => $candidateHistory,
                    'pre_terminal_mapping' =>
                        $preTerminalMappingResult,
                    'source_identity_valid' => $sourceIdentity['valid'],
                    'corpus_hash_matches' =>
                        hash_equals($corpusHash, $currentCorpusHash),
                ])
            );
        }
        return [
            'version_id' => $versionId,
            'version' => $version,
            'status' => 'ready',
            'corpus_hash' => $corpusHash,
            'content_hash' => $contentHash,
            'report' => $report,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $db->prepare("
            UPDATE ingredient_ontology_versions SET
                status = 'failed',
                failed_at = CURRENT_TIMESTAMP,
                validation_report_json = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([
            ingredientOntologyV3Json([
                'error' => mb_substr($e->getMessage(), 0, 1000, 'UTF-8'),
            ]),
            $versionId,
        ]);
        throw $e;
    }
}

function ingredientOntologyV3AuditSummary(
    PDO $db,
    int $versionId
): array {
    $version = ingredientOntologyV3Version($db, $versionId);
    if ($version === null) {
        throw new InvalidArgumentException('ontology version not found');
    }
    $groupCount = static function (
        PDO $db,
        string $sql,
        array $params
    ): array {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $result[(string)$row[0]] = (int)$row[1];
        }
        return $result;
    };
    $byStatus = $groupCount(
        $db,
        "SELECT status, COUNT(*)
         FROM ingredient_ontology_mappings
         WHERE ontology_version_id = ?
         GROUP BY status ORDER BY status",
        [$versionId]
    );
    $byMechanism = $groupCount(
        $db,
        "SELECT mapping_source, COUNT(*)
         FROM ingredient_ontology_mappings
         WHERE ontology_version_id = ?
         GROUP BY mapping_source ORDER BY COUNT(*) DESC, mapping_source",
        [$versionId]
    );
    $byLanguage = $groupCount(
        $db,
        "SELECT language, COUNT(*)
         FROM ingredient_ontology_mappings
         WHERE ontology_version_id = ?
         GROUP BY language ORDER BY COUNT(*) DESC, language",
        [$versionId]
    );
    $byOwner = $groupCount(
        $db,
        "SELECT owner_type, COUNT(*)
         FROM ingredient_ontology_mappings
         WHERE ontology_version_id = ?
         GROUP BY owner_type ORDER BY owner_type",
        [$versionId]
    );
    $byAttribute = $groupCount(
        $db,
        "SELECT f.facet_key || '=' || fv.value_key, COUNT(*)
         FROM ingredient_ontology_mapping_attributes a
         JOIN ingredient_ontology_facets f ON f.id = a.facet_id
         JOIN ingredient_ontology_facet_values fv ON fv.id = a.facet_value_id
         WHERE a.ontology_version_id = ?
         GROUP BY f.facet_key, fv.value_key
         ORDER BY COUNT(*) DESC, f.facet_key, fv.value_key",
        [$versionId]
    );
    $top = $db->prepare("
        SELECT normalized_label, language, COUNT(*) AS occurrences
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
          AND status IN ('unresolved', 'ambiguous')
          AND owner_type IN (
              'recipe_ingredient', 'recipe_source_ingredient'
          )
        GROUP BY normalized_label, language
        ORDER BY occurrences DESC, normalized_label
        LIMIT 100
    ");
    $top->execute([$versionId]);
    $topUnresolved = [];
    while ($row = $top->fetch(PDO::FETCH_ASSOC)) {
        $row['occurrences'] = (int)$row['occurrences'];
        $topUnresolved[] = $row;
    }
    $deltas = [];
    foreach ([
        'recipe_ingredient' => 'recipe_ingredients',
        'recipe_source_ingredient' => 'recipe_source_ingredients',
    ] as $ownerType => $table) {
        $legacyMatched = (int)$db->query("
            SELECT COUNT(*) FROM {$table}
            WHERE mapping_source <> 'unresolved'
               OR canonical_ingredient_id IS NOT NULL
               OR taxonomy_node_id IS NOT NULL
        ")->fetchColumn();
        $acceptedStmt = $db->prepare("
            SELECT COUNT(*) FROM ingredient_ontology_mappings
            WHERE ontology_version_id = ? AND owner_type = ?
              AND status = 'accepted'
        ");
        $acceptedStmt->execute([$versionId, $ownerType]);
        $deltas[$ownerType] = [
            'current_mapped' => $legacyMatched,
            'v3_accepted' => (int)$acceptedStmt->fetchColumn(),
        ];
    }
    $badClusters = [];
    foreach ([
        'taxonomy_rule_identity' => (
            "mapping_source = 'taxonomy_rule_evidence'"
        ),
        'quarantined_model_alias' => (
            "mapping_source = 'quarantined_model_evidence'"
        ),
        'false_staple_prefix' => (
            "owner_type = 'recipe_ingredient' AND is_staple = 0 "
            . "AND normalized_label GLOB 'pepper *' "
            . "OR owner_type = 'recipe_ingredient' AND is_staple = 0 "
            . "AND normalized_label GLOB 'salt *' "
            . "OR owner_type = 'recipe_ingredient' AND is_staple = 0 "
            . "AND normalized_label GLOB 'water *'"
        ),
    ] as $name => $where) {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM ingredient_ontology_mappings
            WHERE ontology_version_id = ? AND ({$where})
        ");
        $stmt->execute([$versionId]);
        $badClusters[$name] = (int)$stmt->fetchColumn();
    }
    $sourceIdentity = ingredientOntologyV3OwnerFingerprintAudit(
        $db,
        $versionId
    );
    $currentCorpusHash = ingredientOntologyV3CorpusHash($db);
    $currentContentHash = ingredientOntologyV3ContentHash($db, $versionId);
    $currentPortableHash = ingredientOntologyV3PortableContentHash(
        $db,
        $versionId
    );
    $hashIntegrity = ingredientOntologyV3HashIntegrityAudit(
        $db,
        $versionId,
        true
    );
    $hashesValid = hash_equals(
        (string)$version['schema_hash'],
        ingredientOntologyV3SchemaHash()
    ) && hash_equals(
        (string)$version['prompt_hash'],
        ingredientOntologyV3PromptHash()
    ) && hash_equals(
        (string)$version['model_hash'],
        ingredientOntologyV3ModelHash((string)$version['model_name'])
    ) && hash_equals(
        (string)$version['corpus_hash'],
        $currentCorpusHash
    ) && hash_equals(
        (string)$version['content_hash'],
        $currentContentHash
    ) && hash_equals(
        (string)$version['portable_content_hash'],
        $currentPortableHash
    ) && $hashIntegrity['valid'];
    return [
        'version' => [
            'id' => $versionId,
            'version' => $version['version'],
            'status' => $version['status'],
            'corpus_hash' => $version['corpus_hash'],
            'content_hash' => $version['content_hash'],
            'portable_content_hash' =>
                $version['portable_content_hash'],
            'seal_hash' => $version['seal_hash'],
            'corpus_hash_matches' =>
                hash_equals((string)$version['corpus_hash'], $currentCorpusHash),
            'content_hash_matches' =>
                hash_equals((string)$version['content_hash'], $currentContentHash),
            'portable_content_hash_matches' =>
                hash_equals(
                    (string)$version['portable_content_hash'],
                    $currentPortableHash
                ),
            'hashes_valid' => $hashesValid,
            'row_hash_integrity' => $hashIntegrity,
        ],
        'shadow_only' => true,
        'active_score_unchanged' => true,
        'by_status' => $byStatus,
        'by_mechanism' => $byMechanism,
        'by_language' => $byLanguage,
        'by_owner' => $byOwner,
        'by_attribute' => $byAttribute,
        'top_unresolved' => $topUnresolved,
        'current_vs_v3' => $deltas,
        'bad_match_clusters' => $badClusters,
        'graph' => ingredientOntologyV3GraphValidate($db, $versionId),
        'corpus' => ingredientOntologyV3CorpusCompleteness($db, $versionId),
        'source_identity' => $sourceIdentity,
        'provider_terms' => ingredientOntologyV3ProviderAudit(
            $db,
            $versionId
        ),
        'dispositions' => ingredientOntologyV3DispositionAudit(
            $db,
            $versionId
        ),
        'curated' => function_exists('ingredientOntologyV3CuratedAudit')
            ? ingredientOntologyV3CuratedAudit($db, $versionId)
            : [],
    ];
}

function ingredientOntologyV3WriteAuditJson(
    PDO $db,
    int $versionId,
    mixed $stream
): array {
    if (!is_resource($stream)) {
        throw new InvalidArgumentException('audit stream is invalid');
    }
    $summary = ingredientOntologyV3AuditSummary($db, $versionId);
    fwrite($stream, '{"summary":');
    fwrite($stream, ingredientOntologyV3Json($summary));
    fwrite($stream, ',"products":[');
    $stmt = $db->prepare("
        SELECT p.id, p.name, p.brand, m.status, m.confidence,
               m.mapping_source, e.slug AS entity_slug,
               e.canonical_name AS entity_name,
               d.disposition_code,
               (
                   SELECT group_concat(ci.slug, ',')
                   FROM product_ingredients pi
                   JOIN canonical_ingredients ci ON ci.id = pi.ingredient_id
                   WHERE pi.product_id = p.id AND pi.role = 'primary'
               ) AS current_primary_slugs
        FROM products p
        LEFT JOIN ingredient_ontology_mappings m
          ON m.ontology_version_id = ?
         AND m.owner_type = 'product'
         AND m.owner_id = p.id
        LEFT JOIN ingredient_ontology_entities e ON e.id = m.entity_id
        LEFT JOIN ingredient_ontology_terminal_dispositions d
          ON d.id = m.terminal_disposition_id
        ORDER BY p.id
    ");
    $stmt->execute([$versionId]);
    $first = true;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row['id'] = (int)$row['id'];
        $row['confidence'] = $row['confidence'] !== null
            ? (float)$row['confidence']
            : null;
        if (!$first) {
            fwrite($stream, ',');
        }
        fwrite($stream, ingredientOntologyV3Json($row));
        $first = false;
    }
    fwrite($stream, '],"distinct_labels":[');
    $labels = $db->prepare("
        SELECT normalized_label, language, COUNT(*) AS occurrences,
               group_concat(DISTINCT status) AS statuses,
               group_concat(DISTINCT d.disposition_code)
                    AS disposition_codes,
               group_concat(DISTINCT mapping_source) AS mechanisms,
               group_concat(DISTINCT COALESCE(e.slug, '')) AS entity_slugs
        FROM ingredient_ontology_mappings m
        LEFT JOIN ingredient_ontology_entities e ON e.id = m.entity_id
        LEFT JOIN ingredient_ontology_terminal_dispositions d
          ON d.id = m.terminal_disposition_id
        WHERE m.ontology_version_id = ?
          AND m.owner_type IN (
              'recipe_ingredient', 'recipe_source_ingredient'
          )
        GROUP BY normalized_label, language
        ORDER BY occurrences DESC, normalized_label, language
    ");
    $labels->execute([$versionId]);
    $first = true;
    while ($row = $labels->fetch(PDO::FETCH_ASSOC)) {
        $row['occurrences'] = (int)$row['occurrences'];
        if (!$first) {
            fwrite($stream, ',');
        }
        fwrite($stream, ingredientOntologyV3Json($row));
        $first = false;
    }
    fwrite($stream, '],"primary_edge_diffs":[');
    $edges = $db->prepare("
        SELECT child.slug AS child_slug,
               previous.slug AS previous_parent_slug,
               next.slug AS new_parent_slug,
               r.change_kind, r.disposition, r.rationale,
               r.content_hash, r.reviewer, r.review_batch
        FROM ingredient_ontology_primary_edge_reviews r
        JOIN ingredient_ontology_entities child
          ON child.id = r.child_entity_id
        LEFT JOIN ingredient_ontology_entities previous
          ON previous.id = r.previous_parent_entity_id
        LEFT JOIN ingredient_ontology_entities next
          ON next.id = r.new_parent_entity_id
        WHERE r.ontology_version_id = ?
          AND r.change_kind <> 'unchanged'
        ORDER BY child.slug
    ");
    $edges->execute([$versionId]);
    $first = true;
    while ($row = $edges->fetch(PDO::FETCH_ASSOC)) {
        if (!$first) {
            fwrite($stream, ',');
        }
        fwrite($stream, ingredientOntologyV3Json($row));
        $first = false;
    }
    fwrite($stream, ']}' . PHP_EOL);
    return $summary;
}

function ingredientOntologyV3HumanAuditSummary(array $summary): string {
    $statusParts = [];
    foreach ($summary['by_status'] as $status => $count) {
        $statusParts[] = "{$status}={$count}";
    }
    $ownerParts = [];
    foreach ($summary['by_owner'] as $owner => $count) {
        $ownerParts[] = "{$owner}={$count}";
    }
    return implode(PHP_EOL, [
        'Ingredient ontology v3 audit: '
            . $summary['version']['version']
            . ' (' . $summary['version']['status'] . ')',
        'Mode: shadow-only; active score/ontology pointer not changed',
        'Owners: ' . implode(', ', $ownerParts),
        'Statuses: ' . implode(', ', $statusParts),
        'Graph valid: ' . ($summary['graph']['valid'] ? 'yes' : 'no'),
        'Corpus complete: '
            . ($summary['corpus']['complete'] ? 'yes' : 'no'),
        'Source fingerprints valid: '
            . ($summary['source_identity']['valid'] ? 'yes' : 'no'),
        'Version hashes valid: '
            . ($summary['version']['hashes_valid'] ? 'yes' : 'no'),
        'Terminal dispositions valid: '
            . (!empty($summary['dispositions']['valid']) ? 'yes' : 'no'),
        'Undispositioned/candidate: '
            . (int)($summary['dispositions']['undispositioned_count'] ?? -1)
            . '/'
            . (int)($summary['dispositions']['candidate_count'] ?? -1),
        'Top unresolved: '
            . (($summary['top_unresolved'][0]['normalized_label'] ?? 'none'))
            . ' (' . (($summary['top_unresolved'][0]['occurrences'] ?? 0)) . ')',
    ]) . PHP_EOL;
}
