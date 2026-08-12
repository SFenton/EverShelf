#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$assertions = 0;
function curatedTestAssert(bool $condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function curatedTestResolveRegistered(
    array $index,
    string $label
): array {
    $normalized = ingredientOntologyV3NormalizeLabel($label);
    foreach ($index[$normalized] ?? [] as $entry) {
        $context = [];
        if (($entry['required_cohort'] ?? null) !== null) {
            $context['cohort'] = $entry['required_cohort'];
        }
        if (
            ($entry['required_evidence_kind'] ?? null) !== null
            && ($entry['required_evidence_key'] ?? null) !== null
        ) {
            $context['evidence'][
                $entry['required_evidence_kind']
            ][$entry['required_evidence_key']] = true;
        }
        $resolved = ingredientOntologyV3ResolveLabel(
            $index,
            $label,
            (string)$entry['language'],
            $context
        );
        if ($resolved['status'] === 'accepted') {
            return $resolved;
        }
    }
    return ingredientOntologyV3ResolveLabel($index, $label, 'und');
}

$dbPath = __DIR__ . '/../data/.ontology-curated-test-'
    . getmypid() . '.sqlite';
$cleanup = [
    $dbPath,
    $dbPath . '-wal',
    $dbPath . '-shm',
    dirname($dbPath) . '/.' . basename($dbPath) . '.recipe-score.lock',
];

try {
    foreach ($cleanup as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    initializeDB($db);
    migrateDB($db);

    $manifestById = [];
    foreach (ingredientOntologyV3CuratedProductManifest() as $entry) {
        $manifestById[(int)$entry['expected_product_id']] = $entry;
    }
    $products = $manifestById;
    $demotedProducts = [
        15 => ['name' => "Thick 'N Chunky Salsa Verde", 'brand' => 'Victoria', 'category' => 'en:salsa', 'prepared_food' => 0, 'slug' => 'salsa', 'source' => 'seed'],
        25 => ['name' => 'Kroger Light Mayo', 'brand' => 'Kroger', 'category' => '', 'prepared_food' => 0, 'slug' => 'mayonnaise', 'source' => 'seed'],
        49 => ['name' => 'Costco Chicken Tortilla Soup', 'brand' => '', 'category' => '', 'prepared_food' => 0, 'slug' => 'multi-ingredient-soup', 'source' => 'seed'],
        54 => ['name' => 'Paris Baguette Cake', 'brand' => '', 'category' => '', 'prepared_food' => 0, 'slug' => 'cake', 'source' => 'seed'],
        60 => ['name' => 'Tostitos Chunky Salsa Mild', 'brand' => 'Tostitos', 'category' => 'en:plant-based-foods-and-beverages', 'prepared_food' => 0, 'slug' => 'salsa', 'source' => 'seed'],
        61 => ['name' => 'QFC Birthday Cake', 'brand' => '', 'category' => '', 'prepared_food' => 0, 'slug' => 'cake', 'source' => 'seed'],
        66 => ['name' => 'Crispy Fruit Pineapple', 'brand' => 'Crispy Green', 'category' => '', 'prepared_food' => 0, 'slug' => 'pineapple', 'source' => 'seed'],
        175 => ['name' => 'Garlic Shrimp Pasta', 'brand' => '', 'category' => '', 'prepared_food' => 1, 'slug' => 'prepared-meal', 'source' => 'prepared_food_flag'],
        180 => ['name' => 'Smoke Roasted Salmon Bites', 'brand' => 'Sustainable Seas', 'category' => 'en:seafood', 'prepared_food' => 0, 'slug' => 'smoke-roasted-salmon-bites', 'source' => 'fallback_name'],
    ];
    foreach ($demotedProducts as $id => $product) {
        $products[$id] = $product;
    }
    $products[200] = [
        'name' => 'Uncertain Retail Mystery',
        'brand' => '',
        'category' => '',
        'prepared_food' => 0,
    ];
    $products[201] = [
        'name' => 'Unreviewed Water Product',
        'brand' => '',
        'category' => '',
        'prepared_food' => 0,
    ];
    ksort($products, SORT_NUMERIC);
    $insertProduct = $db->prepare("
        INSERT INTO products (
            id, name, brand, category, unit,
            default_quantity, prepared_food
        )
        VALUES (?, ?, ?, ?, 'pz', 1, ?)
    ");
    foreach ($products as $id => $product) {
        $insertProduct->execute([
            $id,
            $product['name'],
            $product['brand'],
            $product['category'],
            $product['prepared_food'],
        ]);
    }
    $insertCanonical = $db->prepare("
        INSERT OR IGNORE INTO canonical_ingredients (slug, name, source)
        VALUES (?, ?, 'synthetic_test')
    ");
    $canonicalTerms = [
        ['water', 'Water'],
        ['black-beans', 'Black Beans'],
        ['cilantro', 'Cilantro'],
        ['green-onion', 'Green Onion'],
        ['walnuts', 'Walnuts'],
        ['blueberries', 'Blueberries'],
        ['peanuts', 'Peanuts'],
        ['bread-crumbs', 'Bread Crumbs'],
        ['egg-whites', 'Egg Whites'],
        ['garlic-cloves', 'Garlic Cloves'],
        ['chicken-stock', 'Chicken Stock'],
        ['beef-stock', 'Beef Stock'],
    ];
    foreach ($manifestById as $entry) {
        $canonicalTerms[] = [
            $entry['slug'],
            ucwords(str_replace('-', ' ', $entry['slug'])),
        ];
    }
    foreach ($demotedProducts as $product) {
        $canonicalTerms[] = [
            $product['slug'],
            ucwords(str_replace('-', ' ', $product['slug'])),
        ];
    }
    foreach ($canonicalTerms as [$slug, $name]) {
        $insertCanonical->execute([$slug, $name]);
    }
    $canonicalIds = [];
    foreach ($db->query("
        SELECT id, slug FROM canonical_ingredients
    ") as $row) {
        $canonicalIds[(string)$row['slug']] = (int)$row['id'];
    }
    $linkProduct = $db->prepare("
        INSERT INTO product_ingredients (
            product_id, ingredient_id, role, confidence, source, evidence
        )
        VALUES (?, ?, 'primary', 0.99, ?, ?)
    ");
    foreach ($demotedProducts as $id => $product) {
        $linkProduct->execute([
            $id,
            $canonicalIds[$product['slug']],
            $product['source'],
            'deliberately unreviewed product fixture',
        ]);
    }
    $linkProduct->execute([
        201,
        $canonicalIds['water'],
        'seed',
        'unreviewed high-confidence seed fixture',
    ]);
    $candidate = ingredientOntologyV3BuildCandidate($db, [
        'version' => 'v3-curated-test',
    ]);
    $versionId = (int)$candidate['version_id'];
    $curatedAudit = ingredientOntologyV3CuratedAudit($db, $versionId);
    curatedTestAssert(
        $curatedAudit['product_count'] === count($products),
        'Every synthetic pantry product must receive a reviewed disposition'
    );

    $assertionsByProduct = [];
    $stmt = $db->prepare("
        SELECT a.product_id, a.status, a.attributes_json, e.slug,
               e.entity_kind, m.mapping_source, a.provenance,
               a.review_state, d.disposition_code
        FROM ingredient_ontology_curated_product_assertions a
        LEFT JOIN ingredient_ontology_entities e ON e.id = a.entity_id
        LEFT JOIN ingredient_ontology_mappings m
          ON m.ontology_version_id = a.ontology_version_id
         AND m.owner_type = 'product'
         AND m.owner_id = a.product_id
        LEFT JOIN ingredient_ontology_terminal_dispositions d
          ON d.id = a.terminal_disposition_id
        WHERE a.ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row['attributes'] = json_decode(
            (string)$row['attributes_json'],
            true
        ) ?: [];
        $assertionsByProduct[(int)$row['product_id']] = $row;
    }
    curatedTestAssert(
        count($manifestById) === 113,
        'The prior reviewed product manifest must retain 113 '
            . 'fingerprint-bound review inputs'
    );
    foreach ($manifestById as $productId => $review) {
        $actual = $assertionsByProduct[$productId] ?? null;
        curatedTestAssert(
            $actual !== null
            && $actual['status'] !== 'candidate'
            && in_array(
                $actual['disposition_code'],
                ['D1', 'D2', 'D5', 'D9'],
                true
            )
            && str_starts_with(
                $actual['provenance'],
                INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION . ':'
            ),
            "Reviewed manifest product {$productId} lacks a terminal "
                . 'fingerprint-bound disposition'
        );
    }
    foreach ([
        26 => ['olive', []],
        43 => ['butter', ['saltedness' => 'salted']],
        47 => ['stock-paste', ['variety' => 'chicken']],
        56 => ['butter', ['saltedness' => 'unsalted']],
        58 => ['cream', ['cream_class' => 'whipping']],
        79 => ['stock', ['variety' => 'beef']],
        86 => ['almond', ['saltedness' => 'unsalted']],
        91 => ['sugar', ['refinement' => 'brown']],
        92 => ['mozzarella', ['form' => 'block']],
        95 => ['pepper-jack-cheese', ['form' => 'sliced']],
        107 => ['chicken', ['cut' => 'breast']],
        109 => ['coffee-pod', ['form' => 'pod']],
        117 => ['spring-onion', []],
        119 => ['cauliflower', ['processing' => 'baked']],
        130 => ['black-bean', []],
        134 => ['coriander', []],
        36 => ['walnut', ['form' => 'chopped']],
        44 => ['breadcrumbs', []],
        51 => ['peanut', ['saltedness' => 'unsalted']],
        76 => ['breadcrumbs', []],
        26 => ['olive', [
            'preparation' => 'pitted',
            'variety' => 'kalamata',
        ]],
        97 => ['foie-gras', ['form' => 'block', 'species' => 'duck']],
        106 => ['bacon', [
            'preparation' => 'thick_cut',
            'processing' => 'smoked',
        ]],
        143 => ['chicken', ['cut' => 'thigh']],
        145 => ['egg', ['egg_part' => 'whole']],
        167 => ['noodle-soup', ['variety' => 'ramen']],
        168 => ['noodle-soup', ['variety' => 'ramen']],
    ] as $productId => [$slug, $attributes]) {
        $actual = $assertionsByProduct[$productId];
        curatedTestAssert(
            $actual['status'] === 'accepted'
            && $actual['slug'] === $slug
            && $actual['mapping_source'] === 'curated_product_manifest'
            && in_array(
                $actual['disposition_code'],
                ['D1', 'D2'],
                true
            ),
            "Curated product {$productId} identity is incorrect"
        );
        foreach ($attributes as $facet => $value) {
            curatedTestAssert(
                ($actual['attributes'][$facet] ?? null) === $value,
                "Curated product {$productId} missing {$facet}={$value}"
            );
        }
    }
    curatedTestAssert(
        !isset(
            $assertionsByProduct[86]['attributes']['processing']
        ),
        'Unroasted must not be parsed as roasted'
    );
    $largeEggAttributes =
        ingredientOntologyV3ExtractAttributes('large eggs');
    curatedTestAssert(
        $largeEggAttributes === [
            'egg_part' => 'whole',
            'size' => 'large',
        ],
        'Egg size must be represented only as a nondefining closed facet'
    );
    $ultraFilteredAttributes =
        ingredientOntologyV3ExtractAttributes('ultra filtered milk');
    curatedTestAssert(
        ($ultraFilteredAttributes['filtration'] ?? null)
            === 'ultra_filtered'
        && !isset($ultraFilteredAttributes['processing']),
        'Ultra-filtered must never imply ultra-pasteurized processing'
    );
    curatedTestAssert(
        ingredientOntologyV3ExtractAttributes(
            'pitted Kalamata olives'
        ) === [
            'preparation' => 'pitted',
            'variety' => 'kalamata',
        ]
        && ingredientOntologyV3ExtractAttributes(
            'Kalamata Oliven, entsteint'
        ) === [
            'preparation' => 'pitted',
            'variety' => 'kalamata',
        ]
        && ingredientOntologyV3ExtractAttributes(
            'azeitona Kalamata'
        ) === [
            'variety' => 'kalamata',
        ],
        'Kalamata and pitted wording must use exact closed facets'
    );
    curatedTestAssert(
        $assertionsByProduct[200]['status'] === 'unresolved',
        'Unproven retail products must remain unresolved'
    );
    curatedTestAssert(
        $assertionsByProduct[175]['status'] === 'unresolved'
        && $assertionsByProduct[175]['disposition_code'] === 'D5',
        'Prepared foods must terminate as reviewed composite/prepared'
    );
    curatedTestAssert(
        $assertionsByProduct[201]['status'] === 'unresolved'
        && $assertionsByProduct[201]['disposition_code'] === 'D9',
        'High-confidence history must terminate without automatic acceptance'
    );
    foreach ([15, 25, 49, 54, 60, 61, 66, 175, 180] as $productId) {
        curatedTestAssert(
            $assertionsByProduct[$productId]['status'] !== 'accepted'
            && !in_array(
                $assertionsByProduct[$productId]['disposition_code'],
                ['D1', 'D2'],
                true
            ),
            "Unsupported generic/region-dependent product {$productId} "
                . 'must retain its original candidate provenance'
        );
    }

    $index = ingredientOntologyV3LabelIndex($db, $versionId);
    $resolutionGold = ingredientOntologyV3EvaluateResolutionGold(
        $db,
        $versionId,
        false
    );
    curatedTestAssert(
        $resolutionGold['valid']
        && $resolutionGold['positive_count'] === 84
        && $resolutionGold['critical_negative_count'] === 52
        && $resolutionGold['pinned_hash_matches']
        && $resolutionGold['maintainer_review_metadata_valid']
        && $resolutionGold['structural_error_count'] === 0,
        'Pinned adjudicated resolution gold failed: '
            . ingredientOntologyV3Json($resolutionGold)
    );
    curatedTestAssert(
        hash_equals(
            (string)ingredientOntologyV3ResolutionManifest()[
                'file_hashes'
            ][INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_FILENAME],
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_SHA256
        ),
        'Adjudicated gold must match both its code pin and review manifest'
    );
    curatedTestAssert(
        $resolutionGold['supersessions']['valid']
        && $resolutionGold['supersessions']
            ['computed_changed_decision_count'] === 35
        && $resolutionGold['supersessions']
            ['supersession_row_count'] === 35,
        'Every changed prior gold decision must have one exact supersession'
    );
    curatedTestAssert(
        $resolutionGold['supersessions']['prior_case_count'] === 465
        && $resolutionGold['supersessions']['retained_case_count'] === 68
        && $resolutionGold['supersessions']
            ['computed_retired_case_count'] === 362
        && $resolutionGold['supersessions']['retirement_row_count'] === 362
        && $resolutionGold['supersessions']
            ['retirement_fixture_hash_matches_pin']
        && $resolutionGold['supersessions']
            ['lineage_accounted_count'] === 465
        && $resolutionGold['supersessions']['lineage_overlap_count'] === 0,
        'Every prior gold case must be retained, superseded, or retired'
    );
    $providerFacetAudit = ingredientOntologyV3ProviderFacetAudit(
        $db,
        $versionId
    );
    curatedTestAssert(
        $providerFacetAudit['valid']
        && $providerFacetAudit[
            'provider_expected_attribute_mismatch_count'
        ] === 0
        && $providerFacetAudit[
            'provider_parsed_hard_facet_unreviewed_count'
        ] === 0
        && $providerFacetAudit['provider_term_review_count'] === 646
        && $providerFacetAudit[
            'provider_term_hard_facet_unreviewed_count'
        ] === 0
        && $providerFacetAudit[
            'provider_term_signature_disagreement_count'
        ] === 0
        && $providerFacetAudit[
            'unused_provider_term_facet_waiver_count'
        ] === 0,
        'Every accepted provider review must preserve exact reviewed facets: '
            . ingredientOntologyV3Json($providerFacetAudit)
    );
    ingredientOntologyV3SetReadyMutationGuard($db, true);
    $db->beginTransaction();
    $db->prepare("
        DELETE FROM ingredient_ontology_label_attributes
        WHERE label_id = (
            SELECT id FROM ingredient_ontology_labels
            WHERE ontology_version_id = ?
              AND source_ref = ?
            LIMIT 1
        )
    ")->execute([
        $versionId,
        '9c56e769ad11c17f7ce8bf685d375f77ffdaffe8eef21b864ba1ca4c5c6dbaf6',
    ]);
    $mutatedProviderFacetAudit =
        ingredientOntologyV3ProviderFacetAudit($db, $versionId);
    $db->rollBack();
    ingredientOntologyV3SetReadyMutationGuard($db, false);
    curatedTestAssert(
        !$mutatedProviderFacetAudit['valid']
        && $mutatedProviderFacetAudit[
            'provider_expected_attribute_mismatch_count'
        ] > 0,
        'Dropping a reviewed provider defining facet must fail its exact gate'
    );
    ingredientOntologyV3SetReadyMutationGuard($db, true);
    $db->beginTransaction();
    $salsaOwnerFingerprint = hash(
        'sha256',
        'synthetic-rpf-33-owner'
    );
    $salsaOwnerContext = ingredientOntologyV3OwnerEvidenceContext(
        $db,
        $versionId,
        [
            'source_ingredient_ref' =>
                'com.vorwerk.ingredients.Ingredient-rpf-33',
            'source_default_title' => 'parsley, fresh',
            'connector' => 'cookidoo',
            'metadata_schema_version' => 'ingredient-topology-v1',
            'source_label' => 'salsa',
        ],
        $salsaOwnerFingerprint
    );
    $scopedSalsa = ingredientOntologyV3ResolveLabel(
        $index,
        'salsa',
        'pt',
        [
            'cohort' => 'pt',
            'evidence' => $salsaOwnerContext['evidence'],
        ]
    );
    $unscopedSalsa = ingredientOntologyV3ResolveLabel(
        $index,
        'salsa',
        'pt',
        ['cohort' => 'pt']
    );
    $wrongOwnerContext = ingredientOntologyV3OwnerEvidenceContext(
        $db,
        $versionId,
        [
            'source_ingredient_ref' =>
                'com.vorwerk.ingredients.Ingredient-rpf-34',
            'source_default_title' => 'parsley, fresh',
            'connector' => 'cookidoo',
            'metadata_schema_version' => 'ingredient-topology-v1',
            'source_label' => 'salsa',
        ],
        hash('sha256', 'wrong-provider-owner')
    );
    $db->rollBack();
    ingredientOntologyV3SetReadyMutationGuard($db, false);
    curatedTestAssert(
        $scopedSalsa['status'] === 'accepted'
        && $scopedSalsa['entity_slug'] === 'parsley'
        && ($scopedSalsa['attributes']['state'] ?? null) === 'fresh'
        && $unscopedSalsa['status'] === 'unresolved'
        && !$wrongOwnerContext['evidence'],
        'Provider-gated salsa must require the matching owner evidence and PT '
            . 'cohort'
    );
    $acceptedManifestRows = 0;
    foreach ($index as $entries) {
        foreach ($entries as $entry) {
            if (!ingredientOntologyV3AcceptedLabelProvenanceAllowed(
                (string)$entry['provenance']
            )) {
                continue;
            }
            $context = [];
            if (($entry['required_cohort'] ?? null) !== null) {
                $context['cohort'] = $entry['required_cohort'];
            }
            if (
                ($entry['required_evidence_kind'] ?? null) !== null
                && ($entry['required_evidence_key'] ?? null) !== null
            ) {
                $context['evidence'][
                    $entry['required_evidence_kind']
                ][$entry['required_evidence_key']] = true;
            }
            $resolved = ingredientOntologyV3ResolveLabel(
                $index,
                (string)$entry['normalized_label'],
                (string)$entry['language'],
                $context
            );
            $expectedEntryAttributes = $entry['attributes'];
            ksort($expectedEntryAttributes, SORT_STRING);
            curatedTestAssert(
                $resolved['status'] === 'accepted'
                && (int)$resolved['entity_id'] === (int)$entry['entity_id']
                && $resolved['attributes'] === $expectedEntryAttributes,
                'Accepted manifest alias failed generated resolution: '
                    . (string)$entry['normalized_label']
                    . ' ' . ingredientOntologyV3Json([
                        'entry' => $entry,
                        'resolved' => $resolved,
                    ])
            );
            $acceptedManifestRows++;
        }
    }
    curatedTestAssert(
        $acceptedManifestRows >= 300,
        'Generated accepted-row conformance remains exhaustive but is not gold'
    );
    foreach (
        ingredientOntologyV3CuratedAliases()
        as [$slug, $label, $language, $explicitAttributes]
    ) {
        if ($slug === 'pepper') {
            $normalized = ingredientOntologyV3NormalizeLabel($label);
            if (
                !preg_match(
                    '/\b(black|white|nero|bianco|noir|blanc|schwarz|'
                        . 'weiß|weiss|negra|blanca|preta|branca|czarn|biał)\b/u',
                    $normalized
                )
            ) {
                curatedTestAssert(
                    ingredientOntologyV3ResolveLabel(
                        $index,
                        $label,
                        $language
                    )['status'] === 'unresolved',
                    "Bare pepper alias must be contextual: {$label}"
                );
                continue;
            }
            $slug = 'piper-pepper';
        }
        $expectedAttributes = array_replace(
            ingredientOntologyV3ExtractAttributes($label),
            $explicitAttributes
        );
        if (
            $slug === 'baking-powder'
            && ($expectedAttributes['form'] ?? null) === 'powder'
        ) {
            unset($expectedAttributes['form']);
        }
        if (
            $slug === 'milk'
            && ingredientOntologyV3NormalizeLabel($label)
                === 'whole milk'
        ) {
            unset($expectedAttributes['form']);
        }
        $resolved = curatedTestResolveRegistered($index, $label);
        curatedTestAssert(
            $resolved['status'] === 'accepted'
            && $resolved['entity_slug'] === $slug,
            "Curated accepted alias failed exhaustive resolution: {$label} "
                . ingredientOntologyV3Json([
                    'resolved' => $resolved,
                    'entries' => $index[
                        ingredientOntologyV3NormalizeLabel($label)
                    ] ?? [],
                ])
        );
        foreach ($expectedAttributes as $facet => $value) {
            curatedTestAssert(
                ($resolved['attributes'][$facet] ?? null) === $value,
                "Curated accepted alias {$label} lost {$facet}={$value}"
            );
        }
    }
    foreach (ingredientOntologyV3MultilingualStapleAliases() as $alias) {
        [$slug, $label, $language] = $alias;
        $expectedAttributes = array_replace(
            ingredientOntologyV3ExtractAttributes($label),
            (array)($alias[3] ?? [])
        );
        $resolved = curatedTestResolveRegistered($index, $label);
        if (in_array(
            ingredientOntologyV3NormalizeLabel($label),
            ['pepper', 'pimenta'],
            true
        )) {
            curatedTestAssert(
                $resolved['status'] === 'unresolved',
                "Bare pepper staple must be contextual: {$label}"
            );
            continue;
        }
        if ($slug === 'pepper') {
            $slug = 'piper-pepper';
        }
        curatedTestAssert(
            $resolved['status'] === 'accepted'
            && $resolved['entity_slug'] === $slug,
            "Multilingual staple alias failed exhaustive resolution: {$label} "
                . ingredientOntologyV3Json([
                    'resolved' => $resolved,
                    'entries' => $index[
                        ingredientOntologyV3NormalizeLabel($label)
                    ] ?? [],
                ])
        );
        foreach ($expectedAttributes as $facet => $value) {
            curatedTestAssert(
                ($resolved['attributes'][$facet] ?? null) === $value,
                "Multilingual staple alias {$label} lost {$facet}={$value}"
            );
        }
    }
    $ambiguousGroups = $db->prepare("
        SELECT normalized_label
        FROM ingredient_ontology_labels
        WHERE ontology_version_id = ?
          AND review_state = 'accepted'
          AND kind IN ('exact_alias', 'attribute_alias')
        GROUP BY normalized_label
        HAVING COUNT(DISTINCT entity_id) > 1
        ORDER BY normalized_label
    ");
    $ambiguousGroups->execute([$versionId]);
    foreach ($ambiguousGroups->fetchAll(PDO::FETCH_COLUMN) as $label) {
        curatedTestAssert(
            ingredientOntologyV3ResolveLabel(
                $index,
                (string)$label,
                'und'
            )['status'] === 'ambiguous',
            "Accepted ambiguous label group {$label} must fail closed"
        );
    }
    foreach (['salsa', 'tomato puree', 'tomato purée'] as $quarantined) {
        curatedTestAssert(
            ingredientOntologyV3ResolveLabel(
                $index,
                $quarantined,
                'und'
            )['status'] === 'unresolved',
            "{$quarantined} must remain quarantined without provider context"
        );
    }
    foreach (['pt', 'ro'] as $cohort) {
        $legume = ingredientOntologyV3ResolveLabel(
            $index,
            'legume',
            $cohort,
            ['cohort' => $cohort]
        );
        curatedTestAssert(
            $legume['status'] === 'unresolved',
            strtoupper($cohort)
                . ' legume wording must not become pulse identity'
        );
    }
    foreach ([
        ['lemon juice', 'lemon-juice', []],
        ['Zitronensaft', 'lemon-juice', []],
        ['tomato paste', 'tomato-paste', []],
        ['zucchini', 'zucchini', []],
        ['fresh flat leaf parsley', 'parsley', []],
        ['dried instant yeast', 'yeast', ['processing' => 'dried']],
        ['dried oregano', 'oregano', ['processing' => 'dried']],
        ['fresh thyme', 'thyme', []],
        ['smoked paprika', 'paprika', ['processing' => 'smoked']],
        ['ground turmeric', 'turmeric', ['form' => 'ground']],
        ['ground cinnamon', 'cinnamon', ['form' => 'ground']],
        ['ground cumin', 'cumin', ['form' => 'ground']],
        ['ground nutmeg', 'nutmeg', ['form' => 'ground']],
        ['shallots', 'shallot', []],
        ['orange', 'orange', []],
        ['avocado', 'avocado', []],
        ['natural vanilla extract', 'vanilla-extract', []],
        ['egg yolks', 'egg-yolk', ['egg_part' => 'yolk']],
        ['egg whites', 'egg-white', ['egg_part' => 'white']],
        ['Eier', 'egg', ['egg_part' => 'whole']],
        ['Zucker', 'sugar', []],
        ['Mehl', 'flour', ['form' => 'flour']],
        ['di farina tipo 00', 'flour', ['refinement' => 'type_00']],
        ['vegetable stock paste', 'stock-paste', ['variety' => 'vegetable']],
        ['Vanillezucker', 'vanilla-sugar', []],
        ['eggs', 'egg', ['egg_part' => 'whole']],
        ['large eggs', 'egg', ['egg_part' => 'whole']],
        ['black beans', 'black-bean', []],
        ['Cilantro', 'coriander', []],
        ['green onion', 'spring-onion', []],
        ['coriander seeds', 'coriander-seed', ['form' => 'whole']],
        ['garlic cloves', 'garlic', ['form' => 'clove']],
        ['tomatoes', 'tomato', []],
        ['walnuts', 'walnut', []],
        ['blueberries', 'blueberry', []],
        ['peanuts', 'peanut', []],
        ['bread crumbs', 'breadcrumbs', []],
        ['egg whites', 'egg-white', ['egg_part' => 'white']],
        ["huile d'olive", 'oil', ['variety' => 'olive']],
        ['azeite', 'oil', ['variety' => 'olive']],
        ['oliwy z oliwek', 'oil', ['variety' => 'olive']],
        ['ground black pepper', 'piper-pepper', ['form' => 'ground', 'variety' => 'black']],
        ['ground white pepper', 'piper-pepper', ['form' => 'ground', 'variety' => 'white']],
        ['sucre glace', 'sugar', ['refinement' => 'powdered']],
        ['de sucre en poudre', 'sugar', ['refinement' => 'granulated']],
        ['de farine de blé', 'flour', ['form' => 'flour']],
        ['Honig', 'honey', []],
        ['de miel', 'honey', []],
        ['Apă', 'water', []],
        ['Sare', 'salt', []],
        ['Senf', 'mustard', []],
        ['red onions', 'onion', ['variety' => 'red']],
        ['blanched almonds', 'almond', ['processing' => 'blanched']],
        ['persil frais', 'parsley', ['state' => 'fresh']],
        ['de coriandre fraîche', 'coriander', ['state' => 'fresh']],
        ['frische Petersilie', 'parsley', ['state' => 'fresh']],
        ['cilantro fresco', 'coriander', ['state' => 'fresh']],
        ['Oregano getrocknet', 'oregano', ['processing' => 'dried']],
        ['orégano seco', 'oregano', ['processing' => 'dried']],
        ['origano secco', 'oregano', ['processing' => 'dried']],
        ['bread flour', 'flour', ['refinement' => 'bread']],
        ['farinha tipo65', 'flour', ['refinement' => 'type_65']],
    ] as [$label, $slug, $attributes]) {
        $resolved = curatedTestResolveRegistered($index, $label);
        curatedTestAssert(
            $resolved['status'] === 'accepted'
            && $resolved['entity_slug'] === $slug,
            "Curated alias failed: {$label}"
        );
        foreach ($attributes as $facet => $value) {
            curatedTestAssert(
                ($resolved['attributes'][$facet] ?? null) === $value,
                "Curated alias {$label} missing {$facet}={$value}"
            );
        }
    }
    foreach (['Speisestärke', 'cornflour', 'ice cubes'] as $ambiguous) {
        curatedTestAssert(
            ingredientOntologyV3ResolveLabel(
                $index,
                $ambiguous,
                'und'
            )['status'] === 'unresolved',
            "{$ambiguous} must remain unresolved without provider context"
        );
    }

    $entities = ingredientOntologyV3EntityMap(
        $db,
        $versionId
    )['by_slug'];
    curatedTestAssert(
        (int)$db->query("
            SELECT COUNT(*)
            FROM ingredient_ontology_primary_edge_reviews
            WHERE ontology_version_id = {$versionId}
        ")->fetchColumn()
            === (int)$db->query("
                SELECT COUNT(*)
                FROM ingredient_ontology_entities
                WHERE ontology_version_id = {$versionId}
            ")->fetchColumn()
        && (int)$db->query("
            SELECT COUNT(*)
            FROM ingredient_ontology_primary_edge_reviews
            WHERE ontology_version_id = {$versionId}
              AND change_kind IN ('changed', 'removed', 'restored')
              AND disposition <> 'reviewed'
        ")->fetchColumn() === 0,
        'Every retained or removed primary edge must have one reviewed diff'
    );
    foreach ([
        'piper-pepper' => 'spice',
        'bell-pepper' => 'capsicum',
        'jalapeno-pepper' => 'chilli-pepper',
        'coffee-pod' => 'coffee',
        'stock' => 'stock-broth',
    ] as $childSlug => $parentSlug) {
        $parent = $db->prepare("
            SELECT parent.slug
            FROM ingredient_ontology_relations r
            JOIN ingredient_ontology_entities child
              ON child.id = r.from_entity_id
            JOIN ingredient_ontology_entities parent
              ON parent.id = r.to_entity_id
            WHERE r.ontology_version_id = ?
              AND child.slug = ?
              AND r.relation = 'is_a'
              AND r.is_primary = 1
              AND r.review_state = 'accepted'
        ");
        $parent->execute([$versionId, $childSlug]);
        curatedTestAssert(
            (string)$parent->fetchColumn() === $parentSlug,
            "{$childSlug} must have reviewed parent {$parentSlug}"
        );
    }
    foreach ([
        'black-beans' => 'black-bean',
        'cilantro' => 'coriander',
        'green-onion' => 'spring-onion',
        'coriander-seeds' => 'coriander-seed',
    ] as $duplicate => $canonical) {
        if (!isset($entities[$duplicate], $entities[$canonical])) {
            continue;
        }
        $relation = $db->prepare("
            SELECT COUNT(*)
            FROM ingredient_ontology_relations
            WHERE ontology_version_id = ?
              AND from_entity_id = ?
              AND to_entity_id = ?
              AND relation = 'equivalent_to'
              AND review_state = 'accepted'
              AND satisfies_required = 0
        ");
        $relation->execute([
            $versionId,
            $entities[$duplicate]['id'],
            $entities[$canonical]['id'],
        ]);
        curatedTestAssert(
            (int)$relation->fetchColumn() === 1,
            "{$duplicate} equivalence must remain non-satisfying evidence"
        );
    }
    foreach ([
        'beans', 'corn-starch', 'garlic-cloves', 'tomatoes', 'lemons',
        'walnuts', 'blueberries', 'peanuts', 'bread-crumbs',
        'egg-whites', 'chicken-stock', 'chicken-stock-base',
        'beef-stock', 'black-pepper', 'white-pepper', 'peppercorn', 'pepper',
        'legumes', 'olives', 'brown-sugar', 'heavy-cream',
        'sun-dried-tomatoes', 'sesame-oil', 'chicken-breast',
        'jasmine-rice', 'arborio-rice', 'pickled-jalapeno-peppers',
    ] as $duplicate) {
        curatedTestAssert(
            !isset($entities[$duplicate])
            || !$entities[$duplicate]['active'],
            "{$duplicate} must be inactive after canonical identity collapse"
        );
    }
    $context = new IngredientOntologyV3MatcherContext($db, $versionId);
    $match = static function (
        string $requiredSlug,
        array $requiredAttributes,
        string $inventorySlug,
        array $inventoryAttributes
    ) use ($context, $entities): array {
        return ingredientOntologyV3MatchWithContext(
            $context,
            [
                'entity_id' => $entities[$requiredSlug]['id'],
                'status' => 'accepted',
                'mapping_source' => 'curated_gold',
                'attributes' => $requiredAttributes,
            ],
            [
                'entity_id' => $entities[$inventorySlug]['id'],
                'status' => 'accepted',
                'mapping_source' => 'curated_gold',
                'attributes' => $inventoryAttributes,
            ]
        );
    };
    foreach ([
        ['oil', ['variety' => 'olive'], 'olive', []],
        ['stock', ['variety' => 'vegetable'], 'vegetable', []],
        ['vanilla', ['form' => 'pod'], 'coffee-pod', ['form' => 'pod']],
        ['cardamom', ['form' => 'pod'], 'coffee-pod', ['form' => 'pod']],
        ['flour', ['refinement' => 'cake'], 'cake', []],
        ['milk-alternative', ['variety' => 'almond'], 'almond', []],
        ['flour', ['variety' => 'almond'], 'almond', []],
        ['noodle-soup', ['variety' => 'ramen'], 'noodle', ['variety' => 'ramen']],
        ['sugar', ['refinement' => 'brown'], 'sugar', []],
        ['pepper-jack-cheese', ['form' => 'sliced'], 'pepper-jack-cheese', ['form' => 'block']],
        ['chicken', ['cut' => 'thigh'], 'chicken', ['cut' => 'breast']],
        ['chicken', ['bone' => 'bone_in'], 'chicken', ['bone' => 'boneless']],
        ['chicken', ['skin' => 'skin_on'], 'chicken', ['skin' => 'skinless']],
        ['stock', [], 'stock-paste', []],
        ['vanilla', [], 'vanilla-sugar', []],
        ['garlic', ['form' => 'clove'], 'garlic', []],
        ['piper-pepper', ['form' => 'ground', 'variety' => 'black'], 'piper-pepper', ['form' => 'ground', 'variety' => 'white']],
        ['onion', ['variety' => 'red'], 'onion', ['variety' => 'white']],
        ['almond', ['processing' => 'blanched'], 'almond', []],
    ] as [$requiredSlug, $requiredAttributes, $inventorySlug, $inventoryAttributes]) {
        $result = $match(
            $requiredSlug,
            $requiredAttributes,
            $inventorySlug,
            $inventoryAttributes
        );
        curatedTestAssert(
            !$result['satisfies_required'],
            "Catastrophic overmatch: {$requiredSlug} vs {$inventorySlug}"
        );
    }
    curatedTestAssert(
        ingredientOntologyV3MatchWithContext(
            $context,
            [
                'entity_id' => $entities['sugar']['id'],
                'status' => 'candidate',
                'mapping_source' => 'taxonomy_rule_evidence',
            ],
            [
                'entity_id' => $entities['sugar']['id'],
                'status' => 'accepted',
            ]
        )['satisfies_required'] === false,
        'Rule/model candidate evidence must never satisfy required identity'
    );
    $prompt = ingredientOntologyV3BuildProposalPrompt(
        $db,
        $versionId,
        [
            [
                'input_id' => 'unsalted_butter',
                'text' => 'unsalted butter',
                'language' => 'en',
            ],
            [
                'input_id' => 'egg_yolk',
                'text' => 'egg yolk',
                'language' => 'en',
            ],
        ]
    );
    curatedTestAssert(
        str_contains($prompt['prompt'], '- saltedness:')
        && str_contains($prompt['prompt'], '- sweetening:')
        && str_contains($prompt['prompt'], '- egg_part:')
        && str_contains($prompt['prompt'], '- cream_class:')
        && str_contains(
            $prompt['prompt'],
            'roasted, baked, blanched, fermented'
        )
        && str_contains($prompt['prompt'], 'type_00, type_65')
        && !str_contains($prompt['prompt'], 'thinkingBudget'),
        'Curated facet additions must flow through the closed staging prompt '
            . 'without a thinking-budget assumption'
    );
    $db->exec("
        UPDATE products
        SET name = 'Reused product ID now means table salt'
        WHERE id = 26
    ");
    $mutatedCandidate = ingredientOntologyV3BuildCandidate($db, [
        'version' => 'v3-curated-product-id-reuse-test',
    ]);
    $mutatedVersionId = (int)$mutatedCandidate['version_id'];
    $mutatedAssertion = $db->prepare("
        SELECT a.status, a.provenance, m.mapping_source
        FROM ingredient_ontology_curated_product_assertions a
        JOIN ingredient_ontology_mappings m
          ON m.ontology_version_id = a.ontology_version_id
         AND m.owner_type = 'product'
         AND m.owner_id = a.product_id
        WHERE a.ontology_version_id = ? AND a.product_id = 26
    ");
    $mutatedAssertion->execute([$mutatedVersionId]);
    $mutated = $mutatedAssertion->fetch(PDO::FETCH_ASSOC);
    curatedTestAssert(
        $mutated
        && $mutated['status'] !== 'accepted'
        && $mutated['provenance']
            !== INGREDIENT_ONTOLOGY_V3_CURATED_PRODUCT_MANIFEST_VERSION
        && $mutated['mapping_source'] !== 'curated_product_manifest',
        'A reused product ID or mutated name must not inherit reviewed '
            . 'manifest acceptance'
    );
    echo 'Curated ontology tests passed: '
        . number_format($assertions) . " assertions\n";
} finally {
    $db = null;
    foreach ($cleanup as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
