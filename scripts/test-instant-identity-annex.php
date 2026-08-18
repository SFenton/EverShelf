#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (
    &$assertions
): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$path = dirname(__DIR__) . '/data/.instant-identity-annex-'
    . getmypid() . '.sqlite';
@unlink($path);
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
migrateDB($db);

$hash = str_repeat('a', 64);
$db->prepare("
    INSERT INTO ingredient_ontology_versions (
        version, status, schema_hash, prompt_hash, model_hash,
        model_name, corpus_hash, content_hash,
        portable_content_hash, review_manifest_hash,
        resolution_gold_hash, seal_hash,
        activation_policy, activation_block_reason,
        corpus_profile, frozen_corpus_hash,
        frozen_subjects_hash, policy_hash, ready_at
    )
    VALUES (
        'identity-annex-test', 'building', ?, ?, ?,
        'gemini-3.5-flash', ?, ?, ?, ?, ?, ?,
        'test_only', 'test', 'test', ?, ?, ?, CURRENT_TIMESTAMP
    )
")->execute(array_fill(0, 12, $hash));
$versionId = (int)$db->lastInsertId();

$entity = $db->prepare("
    INSERT INTO ingredient_ontology_entities (
        ontology_version_id, local_key, slug,
        canonical_name, entity_kind, identity_role,
        provenance
    )
    VALUES (?, ?, ?, ?, 'ingredient', 'identity_leaf',
            'full-resolution-v3')
");
$entities = [];
foreach ([
    'onion' => 'Onion',
    'potato' => 'Potato',
    'sweet-potato' => 'Sweet Potato',
] as $slug => $name) {
    $entity->execute([
        $versionId,
        'test:' . $slug,
        $slug,
        $name,
    ]);
    $entities[$slug] = (int)$db->lastInsertId();
}
$db->prepare("
    INSERT INTO ingredient_ontology_entities (
        ontology_version_id, local_key, slug,
        canonical_name, entity_kind, identity_role,
        provenance
    )
    VALUES (?, 'test:structural', 'root-vegetable',
            'Root Vegetable', 'ingredient',
            'structural_category', 'full-resolution-v3')
")->execute([$versionId]);
$entities['structural'] = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO ingredient_ontology_entities (
        ontology_version_id, local_key, slug,
        canonical_name, entity_kind, identity_role,
        provenance
    )
    VALUES (?, 'test:provisional', 'provisional-subject-test',
            'Provisional Root', 'ingredient',
            'identity_leaf', 'autonomous_controller')
")->execute([$versionId]);
$entities['provisional'] = (int)$db->lastInsertId();
$facet = $db->prepare("
    INSERT INTO ingredient_ontology_facets (
        ontology_version_id, facet_key, display_name, hard_default
    )
    VALUES (?, ?, ?, 1)
");
$facetValues = [];
foreach ([
    'variety' => 'red',
    'form' => 'powder',
] as $facetKey => $valueKey) {
    $facet->execute([$versionId, $facetKey, ucfirst($facetKey)]);
    $facetId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO ingredient_ontology_facet_values (
            ontology_version_id, facet_id, value_key, display_name
        )
        VALUES (?, ?, ?, ?)
    ")->execute([
        $versionId,
        $facetId,
        $valueKey,
        ucfirst($valueKey),
    ]);
    $facetValues[$facetKey] = [
        'facet_id' => $facetId,
        'value_id' => (int)$db->lastInsertId(),
    ];
}

$label = $db->prepare("
    INSERT INTO ingredient_ontology_labels (
        ontology_version_id, entity_id, language,
        label, normalized_label, kind, review_state,
        provenance, source_ref
    )
    VALUES (?, ?, ?, ?, ?, ?, 'accepted', ?, ?)
");
$labels = [];
foreach ([
    ['onion-powder', 'onion', 'en', 'Onion Powder', 'onion powder', 'attribute_alias', 'prior-label-transition-v3', 'form'],
    ['potato', 'potato', 'und', 'Potato', 'potato', 'exact_alias', 'prior-label-transition-v3', null],
    ['potatoes', 'potato', 'en', 'Potatoes', 'potatoes', 'exact_alias', 'prior-label-transition-v3', null],
    ['red-onion', 'onion', 'en', 'Red Onion', 'red onion', 'attribute_alias', 'prior-label-transition-v3', 'variety'],
    ['sweet-potatoes', 'sweet-potato', 'en', 'Sweet Potatoes', 'sweet potatoes', 'exact_alias', 'full-resolution-v3', null],
] as [
    $key,
    $entitySlug,
    $language,
    $display,
    $normalized,
    $kind,
    $provenance,
    $attributeFacet
]) {
    $label->execute([
        $versionId,
        $entities[$entitySlug],
        $language,
        $display,
        $normalized,
        $kind,
        $provenance,
        'test:' . $key,
    ]);
    $labelId = (int)$db->lastInsertId();
    $labels[$key] = $labelId;
    if ($attributeFacet !== null) {
        $db->prepare("
            INSERT INTO ingredient_ontology_label_attributes (
                ontology_version_id, label_id, facet_id,
                facet_value_id, is_defining
            )
            VALUES (?, ?, ?, ?, 1)
        ")->execute([
            $versionId,
            $labelId,
            $facetValues[$attributeFacet]['facet_id'],
            $facetValues[$attributeFacet]['value_id'],
        ]);
    }
}
$label->execute([
    $versionId,
    $entities['onion'],
    'und',
    'Red Onion',
    'red onion',
    'exact_alias',
    'semantic_seed',
    'test:red-onion-und',
]);
foreach ([
    [$entities['structural'], 'Root Vegetable', 'root vegetable', 'en'],
    [$entities['provisional'], 'Provisional Root', 'provisional root', 'en'],
    [$entities['onion'], 'Ambiguous Root', 'ambiguous root', 'und'],
    [$entities['potato'], 'Ambiguous Root', 'ambiguous root', 'en'],
] as [$entityId, $display, $normalized, $language]) {
    $label->execute([
        $versionId,
        $entityId,
        $language,
        $display,
        $normalized,
        'exact_alias',
        'semantic_seed',
        'test:' . str_replace(' ', '-', $normalized) . ':' . $language,
    ]);
}
$product = $db->prepare("
    INSERT INTO products (
        name, brand, category, prepared_food
    )
    VALUES (?, '', '', ?)
");
$productIds = [];
foreach ([
    'red_onion' => ['Red Onion', 0],
    'russet' => ['Russet Potatoes', 0],
    'sweet' => ['Sweet Potatoes', 0],
    'powder' => ['Onion Powder', 0],
    'prepared' => ['Potato Salad', 1],
    'unknown' => ['Mystery Root', 0],
    'sealed' => ['Curated Oyster Sauce', 0],
    'expanded' => [str_repeat('a', 199) . '&', 0],
    'oversized' => [str_repeat('b', 260), 0],
    'structural' => ['Root Vegetable', 0],
    'provisional' => ['Provisional Root', 0],
    'ambiguous' => ['Ambiguous Root', 0],
] as $key => [$name, $prepared]) {
    $product->execute([$name, $prepared]);
    $productIds[$key] = (int)$db->lastInsertId();
}
$preparedProduct = $db->query("
    SELECT id, name, brand, category, prepared_food
    FROM products
    WHERE id = {$productIds['prepared']}
")->fetch(PDO::FETCH_ASSOC);
$sealedProduct = $db->query("
    SELECT id, name, brand, category, prepared_food
    FROM products
    WHERE id = {$productIds['sealed']}
")->fetch(PDO::FETCH_ASSOC);
$sealedMapping = $db->prepare("
    INSERT INTO ingredient_ontology_mappings (
        ontology_version_id, owner_type, owner_id,
        owner_fingerprint, source_label, normalized_label,
        language, entity_id, status, confidence,
        mapping_source, evidence_json, attributes_json,
        is_staple
    )
    VALUES (
        ?, 'product', ?, ?, ?, ?,
        'en', ?, 'accepted', 1,
        'curated_product_manifest', '{}', '{}', 0
    )
");
$sealedMapping->execute([
    $versionId,
    $productIds['prepared'],
    ingredientOntologyV3ProductOwnerFingerprint($preparedProduct),
    'Potato Salad',
    'potato salad',
    $entities['potato'],
]);
$sealedMapping->execute([
    $versionId,
    $productIds['sealed'],
    ingredientOntologyV3ProductOwnerFingerprint($sealedProduct),
    'Curated Oyster Sauce',
    'curated oyster sauce',
    $entities['potato'],
]);
$contentHash = ingredientOntologyV3ContentHash($db, $versionId);
ingredientOntologyV3SetPublicationGuard($db, true);
$db->prepare("
    UPDATE ingredient_ontology_versions
    SET content_hash = ?,
        status = 'ready',
        ready_at = CURRENT_TIMESTAMP
    WHERE id = ?
")->execute([$contentHash, $versionId]);
ingredientOntologyV3SetPublicationGuard($db, false);

$red = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['red_onion'],
    $versionId
);
$russet = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['russet'],
    $versionId
);
$sweet = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['sweet'],
    $versionId
);
$powder = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['powder'],
    $versionId
);
$prepared = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['prepared'],
    $versionId
);
$unknown = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['unknown'],
    $versionId
);
$sealed = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['sealed'],
    $versionId
);
$expanded = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['expanded'],
    $versionId
);
$oversized = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['oversized'],
    $versionId
);
$structural = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['structural'],
    $versionId
);
$provisional = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['provisional'],
    $versionId
);
$ambiguous = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['ambiguous'],
    $versionId
);

$assert(
    $red['accepted'] === true
    && $red['entity_id'] === $entities['onion']
    && $red['attributes'] === ['variety' => 'red'],
    'Red Onion must admit through its reviewed attribute alias'
);
$assert(
    $russet['accepted'] === true
    && $russet['entity_id'] === $entities['potato']
    && $russet['source'] === 'reviewed_alias',
    'Russet Potatoes must admit through the reviewed annex alias'
);
$assert(
    $sweet['accepted'] === true
    && $sweet['entity_id'] === $entities['sweet-potato']
    && $sweet['entity_id'] !== $entities['potato'],
    'Sweet Potatoes must retain its distinct reviewed identity'
);
$assert(
    $powder['accepted'] === true
    && $powder['attributes'] === ['form' => 'powder'],
    'Onion Powder must preserve its defining form attribute'
);
$assert(
    $prepared['accepted'] === false
    && $prepared['status'] === 'rejected'
    && $prepared['reason'] === 'prepared_food',
    'Prepared foods must never receive ingredient identity'
);
$db->prepare("
    INSERT INTO inventory (
        product_id, location, quantity
    )
    VALUES (?, 'frigo', 1)
")->execute([$productIds['prepared']]);
$preparedInventory = ingredientOntologyV3Inventory(
    $db,
    $versionId
);
$assert(
    ($preparedInventory['by_product'][
        $productIds['prepared']
    ] ?? null) === null,
    'A rejected annex decision must suppress an older accepted sealed mapping'
);
$assert(
    $unknown['accepted'] === false
    && $unknown['status'] === 'unresolved',
    'Unknown foods must remain non-satisfying'
);
$db->prepare("
    INSERT INTO inventory (
        product_id, location, quantity
    )
    VALUES (?, 'dispensa', 1)
")->execute([$productIds['sealed']]);
$sealedInventory = ingredientOntologyV3Inventory(
    $db,
    $versionId
);
$assert(
    $sealed['status'] === 'unresolved'
    && (int)(
        $sealedInventory['by_product'][
            $productIds['sealed']
        ]['entity_id'] ?? 0
    ) === $entities['potato'],
    'An unresolved annex decision must preserve a current-fingerprint sealed mapping'
);
$db->prepare("
    INSERT INTO inventory (
        product_id, location, quantity
    )
    VALUES (?, 'dispensa', 1)
")->execute([$productIds['russet']]);
$db->prepare("
    DELETE FROM ingredient_ontology_identity_annex
    WHERE product_id = ?
")->execute([$productIds['russet']]);
$annexCountBeforeRead = (int)$db->query("
    SELECT COUNT(*) FROM ingredient_ontology_identity_annex
")->fetchColumn();
$transientInventory = ingredientOntologyV3Inventory(
    $db,
    $versionId
);
$transientRusset =
    $transientInventory['by_product'][$productIds['russet']]
        ?? null;
$assert(
    is_array($transientRusset)
    && $transientRusset['status'] === 'accepted'
    && $transientRusset['mapping_source']
        === 'deterministic_identity_annex_read'
    && (int)$transientRusset['entity_id'] === $entities['potato']
    && (int)$db->query("
        SELECT COUNT(*) FROM ingredient_ontology_identity_annex
    ")->fetchColumn() === $annexCountBeforeRead,
    'Missing current-version annex rows must resolve reviewed aliases without writes'
);
$identityProducts = $db->query("
    SELECT product.id, product.name, product.brand,
           product.category, product.prepared_food
    FROM products product
    WHERE EXISTS (
        SELECT 1 FROM inventory stock
        WHERE stock.product_id = product.id
          AND stock.quantity > 0
    )
    ORDER BY product.id
")->fetchAll(PDO::FETCH_ASSOC);
$identityStatus =
    evershelfProcessingStatusEffectiveIdentityCounts(
        $db,
        ingredientOntologyV3Version($db, $versionId),
        $identityProducts
    );
$identityCounts = $identityStatus['counts'];
$assert(
    $identityCounts['accepted'] >= 1
    && count($identityProducts)
        === array_sum([
            $identityCounts['accepted'],
            $identityCounts['unresolved'],
            $identityCounts['rejected'],
        ]),
    'Processing identity counts must include effective read-only annex admissions'
);
ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['russet'],
    $versionId
);
$annexLabelLength = $db->prepare("
    SELECT length(normalized_label)
    FROM ingredient_ontology_identity_annex
    WHERE product_id = ?
");
$annexLabelLength->execute([$productIds['expanded']]);
$assert(
    $expanded['status'] === 'unresolved'
    && (int)$annexLabelLength->fetchColumn() === 200,
    'Normalization expansion must remain within the persisted annex label bound'
);
$annexLabelLength->execute([$productIds['oversized']]);
$assert(
    $oversized['status'] === 'unresolved'
    && (int)$annexLabelLength->fetchColumn() === 200,
    'Oversized product labels must not fail annex persistence'
);
$db->prepare("
    DELETE FROM inventory
    WHERE product_id = ?
")->execute([$productIds['sealed']]);
$requirementInventory = ingredientOntologyV3Inventory(
    $db,
    $versionId
);
$requirementCandidateCache = [];
$requirementScore = ingredientOntologyV3ScoreRequirementRecipe(
    new IngredientOntologyV3MatcherContext($db, $versionId),
    [
        'id' => 1,
        'favorite' => 0,
        'rating' => null,
        'primary_connector' => 'manual',
        'complete' => true,
        'requirements' => [[
            'id' => 1,
            'entity_id' => $entities['potato'],
            'status' => 'accepted',
            'confidence' => 1.0,
            'mapping_source' => 'test_requirement',
            'attributes' => [],
            'requiredness' => 'required',
            'is_staple' => false,
            'contributor_count' => 1,
            'provider_ref_count' => 0,
            'quantity_audit_state' => 'not_applicable',
        ]],
    ],
    $requirementInventory,
    $requirementCandidateCache
);
$assert(
    ($requirementScore['matches'][0]['inventory_product_id'] ?? null)
        === $productIds['russet']
    && array_key_exists(
        'inventory_mapping_id',
        $requirementScore['matches'][0]
    )
    && $requirementScore['matches'][0]['inventory_mapping_id'] === null,
    'Requirement shadows must preserve null mapping IDs for annex-backed products'
);
$assert(
    $structural['accepted'] === false
    && $structural['status'] === 'unresolved',
    'Structural categories must never receive product identity'
);
$assert(
    $provisional['accepted'] === false
    && $provisional['status'] === 'unresolved',
    'Provisional controller entities must never receive product identity'
);
$assert(
    $ambiguous['accepted'] === false
    && $ambiguous['status'] === 'rejected'
    && $ambiguous['reason'] === 'reviewed_alias_collision',
    'Effective-language aliases resolving to multiple entities must fail closed'
);

$russetProduct = $db->query("
    SELECT id, name, brand, category, prepared_food
    FROM products
    WHERE id = {$productIds['russet']}
")->fetch(PDO::FETCH_ASSOC);
$russetMapping = ingredientOntologyV3IdentityAnnexMapping(
    $db,
    $versionId,
    $productIds['russet'],
    ingredientOntologyV3ProductOwnerFingerprint($russetProduct)
);
$assert(
    $russetMapping !== null
    && $russetMapping['status'] === 'accepted'
    && $russetMapping['mapping_id'] === null
    && $russetMapping['entity_id'] === $entities['potato'],
    'Scoring must read an accepted annex mapping without mutating sealed mappings'
);
$assert(
    ingredientOntologyV3IdentityAnnexMapping(
        $db,
        $versionId,
        $productIds['russet'],
        str_repeat('0', 64)
    ) === null,
    'Stale product fingerprints must not read annex identity'
);
$assert(
    hash_equals(
        $contentHash,
        ingredientOntologyV3ContentHash($db, $versionId)
    ),
    'Identity annex writes must not change the sealed ontology content hash'
);

$db = null;
@unlink($path);
@unlink($path . '-wal');
@unlink($path . '-shm');

echo "Instant identity annex tests passed: {$assertions} assertions.\n";
