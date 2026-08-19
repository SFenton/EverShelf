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
    'eggplant' => 'Eggplant',
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
    ['eggplant', 'eggplant', 'en', 'Eggplant', 'eggplant', 'exact_alias', 'full-resolution-v3', null],
    ['eggplants', 'eggplant', 'en', 'Eggplants', 'eggplants', 'exact_alias', 'full-resolution-v3', null],
    ['aubergine', 'eggplant', 'en', 'Aubergine', 'aubergine', 'exact_alias', 'full-resolution-v3', null],
    ['aubergines', 'eggplant', 'en', 'Aubergines', 'aubergines', 'exact_alias', 'full-resolution-v3', null],
    ['auberginen', 'eggplant', 'de', 'Auberginen', 'auberginen', 'exact_alias', 'full-resolution-v3', null],
    ['auberginer', 'eggplant', 'da', 'Auberginer', 'auberginer', 'exact_alias', 'full-resolution-v3', null],
    ['melanzana', 'eggplant', 'it', 'Melanzana', 'melanzana', 'exact_alias', 'full-resolution-v3', null],
    ['melanzane', 'eggplant', 'it', 'Melanzane', 'melanzane', 'exact_alias', 'full-resolution-v3', null],
    ['melanzani', 'eggplant', 'de', 'Melanzani', 'melanzani', 'exact_alias', 'full-resolution-v3', null],
    ['di-melanzana', 'eggplant', 'it', 'di melanzana', 'di melanzana', 'exact_alias', 'full-resolution-v3', null],
    ['di-melanzane', 'eggplant', 'it', 'di melanzane', 'di melanzane', 'exact_alias', 'full-resolution-v3', null],
    ['d-aubergine', 'eggplant', 'fr', 'd aubergine', 'd aubergine', 'exact_alias', 'full-resolution-v3', null],
    ['d-aubergines', 'eggplant', 'fr', 'd aubergines', 'd aubergines', 'exact_alias', 'full-resolution-v3', null],
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
    'eggplant' => ['Eggplant', 0],
    'melanzana' => ['Melanzana', 0],
    'thai_eggplant' => ['Thai Eggplant', 0],
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
$db->prepare("
    INSERT INTO recipe_catalog (
        title, primary_connector, language
    )
    VALUES ('Eggplant annex fixture', 'manual', 'it')
")->execute();
$eggplantRecipeId = (int)$db->lastInsertId();
$recipeIngredient = $db->prepare("
    INSERT INTO recipe_ingredients (
        recipe_id, position, raw_text, normalized_name,
        is_required, is_optional, is_staple
    )
    VALUES (?, ?, ?, ?, 1, 0, 0)
");
$recipeIngredient->execute([
    $eggplantRecipeId,
    0,
    'di melanzane',
    'di melanzane',
]);
$reviewedEggplantIngredientId = (int)$db->lastInsertId();
$recipeIngredient->execute([
    $eggplantRecipeId,
    1,
    'Thai Eggplant',
    'thai eggplant',
]);
$qualifiedEggplantIngredientId = (int)$db->lastInsertId();
$recipeIngredient->execute([
    $eggplantRecipeId,
    2,
    'Aubergine',
    'aubergine',
]);
$rejectedEggplantIngredientId = (int)$db->lastInsertId();
$recipeOwner = $db->prepare("
    SELECT ingredient.*, recipe.language,
           recipe.primary_connector,
           '' AS origin_external_id,
           '' AS origin_locale
    FROM recipe_ingredients ingredient
    JOIN recipe_catalog recipe
      ON recipe.id = ingredient.recipe_id
    WHERE ingredient.id = ?
");
$sealedRecipeMapping = $db->prepare("
    INSERT INTO ingredient_ontology_mappings (
        ontology_version_id, owner_type, owner_id,
        owner_fingerprint, source_label, normalized_label,
        language, entity_id, status, confidence,
        mapping_source, evidence_json, attributes_json,
        is_staple
    )
    VALUES (
        ?, 'recipe_ingredient', ?, ?, ?, ?, ?,
        NULL, ?, 0, 'test_preexisting_terminal',
        '{}', '{}', 0
    )
");
foreach ([
    [$reviewedEggplantIngredientId, 'unresolved'],
    [$rejectedEggplantIngredientId, 'rejected'],
] as [$ingredientId, $status]) {
    $recipeOwner->execute([$ingredientId]);
    $ownerRow = $recipeOwner->fetch(PDO::FETCH_ASSOC);
    $sealedRecipeMapping->execute([
        $versionId,
        $ingredientId,
        ingredientOntologyV3RecipeOwnerFingerprint(
            'recipe_ingredient',
            $ownerRow
        ),
        (string)$ownerRow['raw_text'],
        (string)$ownerRow['normalized_name'],
        (string)$ownerRow['language'],
        $status,
    ]);
}
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
$eggplant = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['eggplant'],
    $versionId
);
$melanzana = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['melanzana'],
    $versionId
);
$thaiEggplant = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['thai_eggplant'],
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
$assert(
    $eggplant['accepted'] === true
    && $eggplant['entity_id'] === $entities['eggplant']
    && $melanzana['accepted'] === true
    && $melanzana['entity_id'] === $entities['eggplant'],
    'Reviewed Eggplant and Melanzana product labels must share one identity'
);
$assert(
    $thaiEggplant['accepted'] === false
    && $thaiEggplant['status'] === 'unresolved',
    'Qualified Thai Eggplant must remain unresolved without separate review'
);

$recipeAnnex = ingredientOntologyV3RecipeAnnexRefreshRecipe(
    $db,
    $eggplantRecipeId,
    $versionId
);
$reviewedRecipeAnnex = $db->query("
    SELECT status, entity_id
    FROM ingredient_ontology_recipe_identity_annex
    WHERE recipe_ingredient_id = {$reviewedEggplantIngredientId}
")->fetch(PDO::FETCH_ASSOC);
$qualifiedRecipeAnnex = $db->query("
    SELECT status, entity_id
    FROM ingredient_ontology_recipe_identity_annex
    WHERE recipe_ingredient_id = {$qualifiedEggplantIngredientId}
")->fetch(PDO::FETCH_ASSOC);
$rejectedRecipeAnnex = $db->query("
    SELECT status, entity_id
    FROM ingredient_ontology_recipe_identity_annex
    WHERE recipe_ingredient_id = {$rejectedEggplantIngredientId}
")->fetch(PDO::FETCH_ASSOC);
$recipeAnnexRepeat = ingredientOntologyV3RecipeAnnexRefreshRecipe(
    $db,
    $eggplantRecipeId,
    $versionId
);
$assert(
    !empty($recipeAnnex['ready'])
    && (int)$recipeAnnex['changed_row_count'] === 3
    && (int)$recipeAnnex['write_statement_count'] === 1
    && (int)$recipeAnnexRepeat['changed_row_count'] === 0
    && (int)$recipeAnnexRepeat['unchanged_row_count'] === 3
    && (int)$recipeAnnexRepeat['write_statement_count'] === 0
    && (string)$reviewedRecipeAnnex['status'] === 'accepted'
    && (int)$reviewedRecipeAnnex['entity_id']
        === $entities['eggplant']
    && (string)$qualifiedRecipeAnnex['status'] === 'unresolved'
    && $qualifiedRecipeAnnex['entity_id'] === null
    && (string)$rejectedRecipeAnnex['status'] === 'accepted'
    && (int)$rejectedRecipeAnnex['entity_id']
        === $entities['eggplant'],
    'Recipe annex must override pre-existing unresolved/rejected mappings for reviewed labels while qualified forms remain unresolved'
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
$initialAdmissionSync =
    ingredientOntologyV3IdentityAdmissionSync($db);
$labelPlan = $db->query("
    EXPLAIN QUERY PLAN
    SELECT owner_id
    FROM ingredient_ontology_mappings
    WHERE ontology_version_id = {$versionId}
      AND owner_type = 'recipe_ingredient'
      AND normalized_label IN ('eggplant', 'melanzana')
")->fetchAll(PDO::FETCH_ASSOC);
$assert(
    str_contains(
        implode(' ', array_column($labelPlan, 'detail')),
        'idx_ontology_mappings_label'
    ),
    'Admission invalidation discovery must use the normalized-label index'
);
$db->prepare("
    INSERT INTO inventory (
        product_id, location, quantity
    )
    VALUES (?, 'dispensa', 1)
")->execute([$productIds['unknown']]);
$db->exec("DELETE FROM recipe_score_pending_products");
$reviewedAliases =
    ingredientOntologyV3IdentityAnnexReviewedAliases();
$reviewedAliases['mystery root'] = [
    'target_normalized_label' => 'potato',
    'target_language' => 'und',
    'target_entity_slug' => 'potato',
    'target_kind' => 'exact_alias',
    'review_key' => 'test-mystery-root',
    'rationale' => 'Test-only reviewed alias.',
];
$GLOBALS['INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_REVIEWED_ALIASES'] =
    $reviewedAliases;
$identityRaceCalls = 0;
$GLOBALS['IDENTITY_ADMISSION_BEFORE_RESERVATION'] =
    static function (PDO $raceDb) use (&$identityRaceCalls): void {
        if ($identityRaceCalls++ > 0) {
            return;
        }
        $raceDb->prepare("
            UPDATE ingredient_ontology_identity_admission_state
            SET revision = revision + 1,
                review_manifest_hash = ?,
                manifest_json = '{}',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1
        ")->execute([hash('sha256', 'identity-admission-race')]);
    };
$changedAdmissionSync =
    ingredientOntologyV3IdentityAdmissionSync($db);
unset(
    $GLOBALS['INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_REVIEWED_ALIASES'],
    $GLOBALS['IDENTITY_ADMISSION_BEFORE_RESERVATION']
);
$assert(
    !empty($initialAdmissionSync['changed'])
    && !empty($changedAdmissionSync['changed'])
    && (int)$changedAdmissionSync['revision']
        >= (int)$initialAdmissionSync['revision'] + 2
    && $identityRaceCalls >= 2
    && in_array(
        'mystery root',
        $changedAdmissionSync['changed_labels'],
        true
    )
    && (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_products
        WHERE product_id = {$productIds['unknown']}
          AND reason = 'identity_admission_manifest_changed'
    ")->fetchColumn() === 1,
    'Admission manifest changes must advance monotonically and target matching inventory products'
);
$assert(
    hash_equals(
        $contentHash,
        ingredientOntologyV3ContentHash($db, $versionId)
    ),
    'Identity annex writes must not change the sealed ontology content hash'
);
$db->prepare("
    INSERT INTO inventory (
        product_id, location, quantity
    )
    VALUES (?, 'dispensa', 1)
")->execute([$productIds['eggplant']]);
$db->prepare("
    DELETE FROM ingredient_ontology_identity_annex
    WHERE product_id IN (?, ?)
")->execute([
    $productIds['unknown'],
    $productIds['eggplant'],
]);
$fixtureState = recipeScoreState($db);
ingredientOntologyV3SetReadyMutationGuard($db, true);
try {
    $db->prepare("
        INSERT INTO recipe_score_revisions (
            inventory_revision, catalog_revision, inventory_fingerprint,
            score_date, catalog_max_id, status, recipe_count,
            ontology_version_id, scoring_model, scoring_config_hash,
            catalog_fingerprint, ontology_source_revision,
            ontology_source_hash, completed_at
        )
        VALUES (?, ?, ?, ?, 0, 'ready', 0, ?,
                'faceted-ontology-v3', ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ")->execute([
        $fixtureState['inventory_revision'],
        $fixtureState['catalog_revision'],
        str_repeat('1', 64),
        recipeScoreCurrentDate(),
        $versionId,
        ingredientOntologyV3ScoringConfigHash(),
        str_repeat('2', 64),
        $fixtureState['ontology_source_revision'],
        str_repeat('3', 64),
    ]);
} finally {
    ingredientOntologyV3SetReadyMutationGuard($db, false);
}
$fixtureActiveRevisionId = (int)$db->lastInsertId();
$db->prepare("
    UPDATE recipe_score_state
    SET active_score_revision_id = ?,
        active_score_projection_revision_id = ?,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = 1
")->execute([
    $fixtureActiveRevisionId,
    $fixtureActiveRevisionId,
]);
$db->exec("DELETE FROM recipe_score_pending_products");
$reconcileRevisionBefore =
    recipeScoreState($db)['inventory_revision'];
$reconciled =
    ingredientOntologyActivationReconcileProductAnnex($db);
$reconcileRevisionAfter =
    recipeScoreState($db)['inventory_revision'];
$assert(
    (int)$reconciled['changed_product_count'] === 2
    && $reconcileRevisionAfter === $reconcileRevisionBefore + 1
    && (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_products
        WHERE product_id IN (
            {$productIds['unknown']},
            {$productIds['eggplant']}
        )
          AND reason = 'active_ontology_identity_reconciled'
    ")->fetchColumn() === 2
    && (int)$db->query("
        SELECT COUNT(*)
        FROM ingredient_ontology_identity_annex
        WHERE product_id IN (
            {$productIds['unknown']},
            {$productIds['eggplant']}
        )
          AND ontology_version_id = {$versionId}
    ")->fetchColumn() === 2,
    'Active ontology reconciliation must refresh products and journal one sparse revision'
);

$db = null;
@unlink($path);
@unlink($path . '-wal');
@unlink($path . '-shm');

echo "Instant identity annex tests passed: {$assertions} assertions.\n";
