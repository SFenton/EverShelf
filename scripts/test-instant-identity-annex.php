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
    'orange' => 'Orange',
    'garlic' => 'Garlic',
    'bean' => 'Bean',
    'bean-alt' => 'Alternate Bean',
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
    VALUES (?, 'test:ketchup', 'ketchup',
            'Ketchup', 'ingredient',
            'prepared_identity', 'full-resolution-v3')
")->execute([$versionId]);
$entities['ketchup'] = (int)$db->lastInsertId();
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
    ['orange', 'orange', 'en', 'Orange', 'orange', 'exact_alias', 'full-resolution-v3', null],
    ['garlic', 'garlic', 'en', 'Garlic', 'garlic', 'exact_alias', 'full-resolution-v3', null],
    ['bean', 'bean', 'en', 'Bean', 'bean', 'exact_alias', 'full-resolution-v3', null],
    ['ketchup', 'ketchup', 'en', 'Ketchup', 'ketchup', 'exact_alias', 'full-resolution-v3', null],
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
$db->prepare("
    INSERT INTO ingredient_ontology_labels (
        ontology_version_id, entity_id, language,
        label, normalized_label, kind, review_state,
        provenance, source_ref
    )
    VALUES (
        ?, ?, 'en', 'Oranges', 'oranges',
        'exact_alias', 'quarantined',
        'autonomous_controller', 'test:structural-oranges'
    )
")->execute([
    $versionId,
    $entities['structural'],
]);
$db->prepare("
    INSERT INTO ingredient_ontology_labels (
        ontology_version_id, entity_id, language,
        label, normalized_label, kind, review_state,
        provenance, source_ref
    )
    VALUES (
        ?, ?, 'en', 'Beans', 'beans',
        'exact_alias', 'quarantined',
        'semantic_seed', 'test:eligible-beans-conflict'
    )
")->execute([
    $versionId,
    $entities['bean-alt'],
]);
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
    'oranges' => ['Oranges', 0],
    'garlics' => ['Garlics', 0],
    'beans_conflict' => ['Beans', 0],
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
$oranges = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['oranges'],
    $versionId
);
$garlics = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['garlics'],
    $versionId
);
$beansConflict = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['beans_conflict'],
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
    && $unknown['status'] === 'unresolved'
    && $unknown['reason'] === 'no_reviewed_exact_alias',
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
$assert(
    $oranges['accepted'] === true
    && $oranges['entity_id'] === $entities['orange']
    && $oranges['source'] === 'exact_number_v1'
    && $garlics['accepted'] === true
    && $garlics['entity_id'] === $entities['garlic']
    && $garlics['source'] === 'exact_number_v1',
    'Product-local exact-number proof must admit unique reviewed identity leaves'
);
$assert(
    $beansConflict['accepted'] === false
    && $beansConflict['status'] === 'unresolved'
    && $beansConflict['reason']
        === 'number_variant_source_conflict',
    'Eligible quarantined homographs must veto exact-number admission'
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
$db->prepare("
    INSERT INTO inventory (
        product_id, location, quantity
    )
    VALUES (?, 'dispensa', 1)
")->execute([$productIds['garlics']]);
$db->prepare("
    UPDATE ingredient_ontology_identity_annex
    SET resolver_version = ?
    WHERE product_id = ?
")->execute([
    INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
    $productIds['garlics'],
]);
$db->prepare("
    DELETE FROM ingredient_ontology_product_readiness
    WHERE product_id = ?
")->execute([$productIds['garlics']]);
$currentAdmissionManifest = [
    'version' => INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_REVIEW_VERSION,
    'resolver_version' =>
        ingredientOntologyV3ProductIdentityResolverVersion(),
    'aliases' =>
        ingredientOntologyV3IdentityAnnexReviewedAliases(),
];
$db->prepare("
    UPDATE ingredient_ontology_identity_admission_state
    SET resolver_version = ?,
        review_manifest_hash = ?,
        manifest_json = ?,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = 1
")->execute([
    INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
    ingredientOntologyV3IdentityAnnexReviewManifestHash(),
    ingredientOntologyV3Json($currentAdmissionManifest),
]);
$resolverMigration =
    ingredientOntologyV3IdentityAdmissionSync($db);
$migratedGarlicsReadiness =
    ingredientOntologyV3ProductReadinessRow(
        $db,
        $productIds['garlics']
    );
$assert(
    !empty($resolverMigration['changed'])
    && (string)$migratedGarlicsReadiness['status'] === 'ready'
    && (int)$migratedGarlicsReadiness['score_revision_id']
        === $fixtureActiveRevisionId
    && (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_products
        WHERE product_id = {$productIds['garlics']}
    ")->fetchColumn() === 0,
    'Resolver migration must backfill unchanged accepted readiness without rescoring'
);
$db->prepare("
    UPDATE ingredient_ontology_identity_annex
    SET resolver_version = ?
    WHERE product_id = ?
")->execute([
    INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
    $productIds['garlics'],
]);
$steadyResolverMigration =
    ingredientOntologyV3IdentityAdmissionSync($db);
$assert(
    empty($steadyResolverMigration['changed'])
    && (int)(
        $steadyResolverMigration[
            'resolver_migration'
        ]['processed'] ?? 0
    ) >= 1
    && (string)$db->query("
        SELECT resolver_version
        FROM ingredient_ontology_identity_annex
        WHERE product_id = {$productIds['garlics']}
    ")->fetchColumn()
        === ingredientOntologyV3ProductIdentityResolverVersion()
    && (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_products
        WHERE product_id = {$productIds['garlics']}
    ")->fetchColumn() === 0,
    'Current manifest sync must continue bounded stale-resolver migration'
);
$db->prepare("
    DELETE FROM ingredient_ontology_product_readiness
    WHERE product_id = ?
")->execute([$productIds['garlics']]);
$publishedGarlics =
    ingredientOntologyV3IdentityAdmissionPublishProduct(
        $db,
        $productIds['garlics'],
        $versionId,
        'test_exact_number_publication',
        true
    );
$garlicsReadiness =
    ingredientOntologyV3ProductReadinessRow(
        $db,
        $productIds['garlics']
    );
$assert(
    $publishedGarlics['accepted'] === true
    && !empty($publishedGarlics['score_queued'])
    && (string)$garlicsReadiness['status']
        === 'accepted_unscored'
    && (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_products
        WHERE product_id = {$productIds['garlics']}
    ")->fetchColumn() === 1,
    'Accepted identity publication must atomically expose score readiness'
);
$db->prepare("
    DELETE FROM recipe_score_pending_products
    WHERE product_id = ?
")->execute([$productIds['garlics']]);
$db->prepare("
    DELETE FROM inventory
    WHERE product_id = ?
")->execute([$productIds['garlics']]);

$unknownRetry =
    ingredientOntologyV3IdentityAdmissionPublishProduct(
        $db,
        $productIds['unknown'],
        $versionId,
        'test_unresolved_publication',
        false
    );
for ($attempt = 0; $attempt < 4; $attempt++) {
    $db->prepare("
        UPDATE ingredient_ontology_product_readiness
        SET next_retry_at = datetime('now', '-1 second')
        WHERE product_id = ?
    ")->execute([$productIds['unknown']]);
    ingredientOntologyV3ProductReadinessRetryDue($db);
}
$unknownReadiness =
    ingredientOntologyV3ProductReadinessRow(
        $db,
        $productIds['unknown']
    );
$assert(
    $unknownRetry['status'] === 'unresolved'
    && (string)$unknownReadiness['status'] === 'needs_review'
    && (int)$unknownReadiness['attempts'] === 4
    && $unknownReadiness['next_retry_at'] === null,
    'Unresolved identities must terminate visibly within a bounded retry budget'
);
$db->prepare("
    UPDATE ingredient_ontology_product_readiness
    SET requested_at = '2000-01-01 00:00:00'
    WHERE product_id = ?
")->execute([$productIds['unknown']]);
$identityStatus =
    evershelfProcessingStatusIdentityReadiness($db);
$assert(
    (int)$identityStatus['needs_review_count'] >= 1
    && (string)($identityStatus['oldest_pending_at'] ?? '')
        !== '2000-01-01 00:00:00',
    'Terminal identity review rows must not pin pending-age telemetry'
);
$db->prepare("
    DELETE FROM ingredient_ontology_identity_annex
    WHERE product_id IN (?, ?)
")->execute([
    $productIds['unknown'],
    $productIds['eggplant'],
]);
$reconcileRevisionBefore =
    recipeScoreState($db)['inventory_revision'];
$reconcilePendingBefore = (int)$db->query("
    SELECT COUNT(*)
    FROM recipe_score_pending_products
    WHERE product_id IN (
        {$productIds['unknown']},
        {$productIds['eggplant']}
    )
")->fetchColumn();
$reconcileReadinessBefore = ingredientOntologyV3Json(
    $db->query("
        SELECT product_id, owner_fingerprint,
               annex_evidence_hash, identity_status, status
        FROM ingredient_ontology_product_readiness
        WHERE product_id IN (
            {$productIds['unknown']},
            {$productIds['eggplant']}
        )
        ORDER BY product_id
    ")->fetchAll(PDO::FETCH_ASSOC)
);
$GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_RECONCILE_PRODUCT'] =
    static function (): void {
        throw new RuntimeException(
            'reconcile_transaction_fault'
        );
    };
$reconcileFault = null;
try {
    ingredientOntologyActivationReconcileProductAnnex($db);
} catch (Throwable $error) {
    $reconcileFault = $error->getMessage();
} finally {
    unset(
        $GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_AFTER_RECONCILE_PRODUCT'
        ]
    );
}
$reconcileAnnexAfter = (int)$db->query("
    SELECT COUNT(*)
    FROM ingredient_ontology_identity_annex
    WHERE product_id IN (
        {$productIds['unknown']},
        {$productIds['eggplant']}
    )
")->fetchColumn();
$reconcileRevisionAfterFault =
    recipeScoreState($db)['inventory_revision'];
$reconcilePendingAfter = (int)$db->query("
    SELECT COUNT(*)
    FROM recipe_score_pending_products
    WHERE product_id IN (
        {$productIds['unknown']},
        {$productIds['eggplant']}
    )
")->fetchColumn();
$reconcileReadinessAfter = ingredientOntologyV3Json(
    $db->query("
        SELECT product_id, owner_fingerprint,
               annex_evidence_hash, identity_status, status
        FROM ingredient_ontology_product_readiness
        WHERE product_id IN (
            {$productIds['unknown']},
            {$productIds['eggplant']}
        )
        ORDER BY product_id
    ")->fetchAll(PDO::FETCH_ASSOC)
);
$assert(
    $reconcileFault === 'reconcile_transaction_fault'
    && $reconcileAnnexAfter === 0
    && $reconcileRevisionAfterFault === $reconcileRevisionBefore
    && $reconcilePendingAfter === $reconcilePendingBefore
    && hash_equals(
        $reconcileReadinessBefore,
        $reconcileReadinessAfter
    ),
    'Reconciliation faults must roll back annex, readiness, and score '
        . 'queuing together: '
        . ingredientOntologyV3Json([
            'fault' => $reconcileFault,
            'annex_after' => $reconcileAnnexAfter,
            'revision_before' => $reconcileRevisionBefore,
            'revision_after' => $reconcileRevisionAfterFault,
            'pending_before' => $reconcilePendingBefore,
            'pending_after' => $reconcilePendingAfter,
            'readiness_before' => $reconcileReadinessBefore,
            'readiness_after' => $reconcileReadinessAfter,
        ])
);
$reconciled =
    ingredientOntologyActivationReconcileProductAnnex($db);
$reconcileRevisionAfter =
    recipeScoreState($db)['inventory_revision'];
$assert(
    (int)$reconciled['changed_product_count'] === 1
    && $reconcileRevisionAfter >= $reconcileRevisionBefore
    && $reconcileRevisionAfter <= $reconcileRevisionBefore + 1
    && (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_products
        WHERE product_id IN (
            {$productIds['unknown']},
            {$productIds['eggplant']}
        )
    ")->fetchColumn() === 1
    && (int)$db->query("
        SELECT COUNT(*)
        FROM ingredient_ontology_identity_annex
        WHERE product_id IN (
            {$productIds['unknown']},
            {$productIds['eggplant']}
        )
          AND ontology_version_id = {$versionId}
    ")->fetchColumn() === 2,
    'Active ontology reconciliation must preserve existing score work and avoid unresolved semantic no-op scoring'
);

$recipeNumberProof = ingredientOntologyV3RecipeAnnexResolution(
    $db,
    ingredientOntologyV3Version($db, $versionId),
    'Garlics',
    'en'
);
$assert(
    (string)$recipeNumberProof['status'] === 'accepted'
    && (string)$recipeNumberProof['admission_source']
        === 'exact_number_v1'
    && (int)$recipeNumberProof['entity_id'] === $entities['garlic'],
    'Recipe admission must share the product-local exact-number proof'
);

$product->execute(['Legacy Sliced Ham', 0]);
$legacySlicedHamProductId = (int)$db->lastInsertId();
$legacySlicedHam =
    ingredientOntologyV3IdentityAdmissionPublishProduct(
        $db,
        $legacySlicedHamProductId,
        $versionId,
        'legacy_exact_identity_fixture',
        false
    );
for ($attempt = 0; $attempt < 4; $attempt++) {
    $db->prepare("
        UPDATE ingredient_ontology_product_readiness
        SET next_retry_at = datetime('now', '-1 second')
        WHERE product_id = ?
    ")->execute([$legacySlicedHamProductId]);
    ingredientOntologyV3ProductReadinessRetryDue($db);
}
$legacySlicedHamReadiness =
    ingredientOntologyV3ProductReadinessRow(
        $db,
        $legacySlicedHamProductId
    );
$assert(
    $legacySlicedHam['status'] === 'unresolved'
    && (string)$legacySlicedHamReadiness['status']
        === 'needs_review',
    'Legacy unknown-food behavior must be reproduced before exact fallback'
);

$GLOBALS[
    'INGREDIENT_ONTOLOGY_EXACT_SELF_IDENTITY_ENABLED_OVERRIDE'
] = true;
$GLOBALS[
    'INGREDIENT_ONTOLOGY_IDENTITY_ROLE_WIDENING_ENABLED_OVERRIDE'
] = true;
$GLOBALS[
    'INGREDIENT_ONTOLOGY_IDENTITY_READINESS_V2_ENABLED_OVERRIDE'
] = true;
$GLOBALS['INGREDIENT_ONTOLOGY_PRODUCT_LANGUAGE_OVERRIDE'] = 'en';

$sealedRecipeExtension = ingredientOntologyV3RecipeAnnexResolution(
    $db,
    ingredientOntologyV3Version($db, $versionId),
    'Curated Oyster Sauce',
    'en-US',
    true
);
$sealedWithExactFallback =
    ingredientOntologyV3IdentityAnnexRefreshProduct(
        $db,
        $productIds['sealed'],
        $versionId
    );
$sealedLookupOnly =
    ingredientOntologyV3IdentityAnnexRefreshProduct(
        $db,
        $productIds['sealed'],
        $versionId,
        true,
        false,
        true
    );
$sealedReadOnlyResolution =
    ingredientOntologyV3IdentityAnnexResolution(
        $db,
        ingredientOntologyV3Version($db, $versionId),
        $sealedProduct
    );
$sealedPublished =
    ingredientOntologyV3IdentityAdmissionPublishProduct(
        $db,
        $productIds['sealed'],
        $versionId,
        'sealed_mapping_identity_fixture',
        false
    );
$assert(
    (string)$sealedRecipeExtension['status'] === 'accepted'
    && (int)$sealedRecipeExtension['effective_entity_id'] < 0
    && (string)$sealedWithExactFallback['status'] === 'unresolved'
    && $sealedWithExactFallback['extension_entity_id'] === null
    && !empty($sealedWithExactFallback['sealed_mapping_preserved'])
    && (string)$sealedLookupOnly['status'] === 'unresolved'
    && $sealedLookupOnly['extension_entity_id'] === null
    && !empty($sealedLookupOnly['sealed_mapping_preserved'])
    && $sealedLookupOnly['changed'] === false
    && (string)$sealedReadOnlyResolution['status'] === 'unresolved'
    && !empty(
        $sealedReadOnlyResolution['sealed_mapping_preserved']
    )
    && (string)($sealedPublished['readiness']['status'] ?? '')
        === 'ready',
    'Every exact-self entry point must preserve an accepted sealed mapping'
);
$db->prepare("
    DELETE FROM ingredient_ontology_identity_annex
    WHERE product_id = ?
")->execute([$productIds['sealed']]);
$db->prepare("
    INSERT INTO inventory (
        product_id, location, quantity
    )
    VALUES (?, 'dispensa', 1)
")->execute([$productIds['sealed']]);
$sealedFallbackInventory = ingredientOntologyV3Inventory(
    $db,
    $versionId
);
$assert(
    (int)(
        $sealedFallbackInventory['by_product'][
            $productIds['sealed']
        ]['entity_id'] ?? 0
    ) === $entities['potato'],
    'Read-only scoring fallback must retain the sealed product identity'
);
$db->prepare("
    DELETE FROM inventory
    WHERE product_id = ?
")->execute([$productIds['sealed']]);
$sealedPublished =
    ingredientOntologyV3IdentityAdmissionPublishProduct(
        $db,
        $productIds['sealed'],
        $versionId,
        'sealed_mapping_identity_fixture',
        false,
        false,
        true,
        false,
        true
    );
$assert(
    (string)($sealedPublished['readiness']['status'] ?? '') === 'ready'
    && (int)($sealedPublished['readiness']['attempts'] ?? -1) === 0,
    'Foreground lookup-only admission must keep sealed mappings ready'
);

$resolvedAmbiguous = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $productIds['ambiguous'],
    $versionId
);
$assert(
    $resolvedAmbiguous['accepted'] === true
    && (string)$resolvedAmbiguous['source']
        === 'reviewed_alias_collision_exact_self_identity'
    && (int)$resolvedAmbiguous['entity_id'] < 0,
    'Conflicting reviewed candidates must use an observable isolated exact '
        . 'identity instead of needs_review'
);

$recoveredLegacySlicedHam =
    ingredientOntologyV3IdentityAdmissionPublishProduct(
        $db,
        $legacySlicedHamProductId,
        $versionId,
        'exact_identity_recovery',
        false
    );
$recoveredLegacyReadiness =
    ingredientOntologyV3ProductReadinessRow(
        $db,
        $legacySlicedHamProductId
    );
$assert(
    $recoveredLegacySlicedHam['accepted'] === true
    && (string)$recoveredLegacySlicedHam['source']
        === 'exact_self_identity'
    && (int)$recoveredLegacySlicedHam['entity_id'] < 0
    && !in_array(
        (string)$recoveredLegacyReadiness['status'],
        ['needs_review', 'failed'],
        true
    )
    && (int)$recoveredLegacyReadiness['attempts'] === 0,
    'Exact fallback must recover a legacy needs_review food without model or provider calls'
);

$product->execute(['Sliced Ham', 0]);
$slicedHamProductId = (int)$db->lastInsertId();
$product->execute(['Ham', 0]);
$hamProductId = (int)$db->lastInsertId();
$product->execute(['Ketchup', 0]);
$ketchupProductId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO inventory (product_id, location, quantity)
    VALUES (?, 'frigo', 1)
")->execute([$slicedHamProductId]);

$db->prepare("
    INSERT INTO recipe_catalog (
        title, primary_connector, language
    )
    VALUES ('Sliced ham exact identity fixture', 'manual', 'en-US')
")->execute();
$slicedHamRecipeId = (int)$db->lastInsertId();
$recipeIngredient->execute([
    $slicedHamRecipeId,
    0,
    'Sliced ham',
    'sliced ham',
]);
$slicedHamIngredientId = (int)$db->lastInsertId();
$recipeOwner->execute([$slicedHamIngredientId]);
$slicedHamOwner = $recipeOwner->fetch(PDO::FETCH_ASSOC);
ingredientOntologyV3SetReadyMutationGuard($db, true);
$sealedRecipeMapping->execute([
    $versionId,
    $slicedHamIngredientId,
    ingredientOntologyV3RecipeOwnerFingerprint(
        'recipe_ingredient',
        $slicedHamOwner
    ),
    'Sliced ham',
    'sliced ham',
    'en-US',
    'unresolved',
]);
ingredientOntologyV3SetReadyMutationGuard($db, false);
$contentHashBeforeExactExtensions =
    ingredientOntologyV3ContentHash($db, $versionId);

$slicedHamAdmission =
    ingredientOntologyV3IdentityAnnexRefreshProduct(
        $db,
        $slicedHamProductId,
        $versionId
    );
$extensionBeforeRepeat =
    ingredientOntologyV3IdentityExtensionSnapshot(
        $db,
        $versionId
    );
$slicedHamRepeat =
    ingredientOntologyV3IdentityAnnexRefreshProduct(
        $db,
        $slicedHamProductId,
        $versionId
    );
$slicedHamAffectedRecipes =
    ingredientOntologyV3IdentityExtensionRecipeIdsForProducts(
        $db,
        $versionId,
        [$slicedHamProductId]
    );
$extensionAfterRepeat =
    ingredientOntologyV3IdentityExtensionSnapshot(
        $db,
        $versionId
    );
$slicedHamRecipeAnnex =
    ingredientOntologyV3RecipeAnnexRefreshRecipe(
        $db,
        $slicedHamRecipeId,
        $versionId
    );
$slicedHamRecipeLanguage = (string)$db->query("
    SELECT language
    FROM ingredient_ontology_recipe_identity_annex
    WHERE recipe_ingredient_id = {$slicedHamIngredientId}
")->fetchColumn();
$extensionSnapshot =
    ingredientOntologyV3IdentityExtensionSnapshot(
        $db,
        $versionId
    );
$slicedHamInventory = ingredientOntologyV3Inventory(
    $db,
    $versionId,
    null,
    (int)$extensionSnapshot['revision']
);
$slicedHamRecipeBatch = ingredientOntologyV3LoadRecipeBatch(
    $db,
    $versionId,
    [$slicedHamRecipeId],
    true,
    (int)$extensionSnapshot['revision']
);
$slicedHamProductMapping =
    $slicedHamInventory['by_product'][$slicedHamProductId] ?? null;
$slicedHamRecipeMapping =
    $slicedHamRecipeBatch[$slicedHamRecipeId]['ingredients'][0][
        'mapping'
    ] ?? null;
$slicedHamMatch = ingredientOntologyV3MatchWithContext(
    new IngredientOntologyV3MatcherContext(
        $db,
        $versionId,
        (int)$extensionSnapshot['revision']
    ),
    $slicedHamRecipeMapping,
    $slicedHamProductMapping
);
$assert(
    $slicedHamAdmission['accepted'] === true
    && $slicedHamAdmission['source'] === 'exact_self_identity'
    && (int)$slicedHamAdmission['entity_id'] < 0
    && (int)$slicedHamRepeat['entity_id']
        === (int)$slicedHamAdmission['entity_id']
    && (int)$extensionAfterRepeat['revision']
        === (int)$extensionBeforeRepeat['revision']
    && in_array(
        $slicedHamRecipeId,
        $slicedHamAffectedRecipes,
        true
    )
    && !empty($slicedHamRecipeAnnex['ready'])
    && $slicedHamRecipeLanguage === 'en'
    && (int)$slicedHamRecipeMapping['entity_id']
        === (int)$slicedHamProductMapping['entity_id']
    && !empty($slicedHamMatch['satisfies_required'])
    && (string)$slicedHamMatch['outcome'] === 'exact',
    'Sliced Ham product and en-US recipe labels must converge on one '
        . 'idempotent exact self identity'
);

$pinnedExtensionSnapshot =
    ingredientOntologyV3IdentityExtensionSnapshot(
        $db,
        $versionId
    );
$futureExtension = ingredientOntologyV3RecipeAnnexResolution(
    $db,
    ingredientOntologyV3Version($db, $versionId),
    'Future extension prefix fixture',
    'en-US',
    true
);
$advancedExtensionSnapshot =
    ingredientOntologyV3IdentityExtensionSnapshot(
        $db,
        $versionId
    );
$assert(
    (string)$futureExtension['status'] === 'accepted'
    && (int)$advancedExtensionSnapshot['revision']
        === (int)$pinnedExtensionSnapshot['revision'] + 1
    && ingredientOntologyV3IdentityExtensionSnapshotMatches(
        $db,
        $versionId,
        $pinnedExtensionSnapshot
    ),
    'A newer append-only extension head must preserve an older score prefix'
);

$extensionIntegrity =
    ingredientOntologyV3IdentityExtensionIntegrityAudit(
        $db,
        $versionId,
        (int)$advancedExtensionSnapshot['revision'],
        (string)$advancedExtensionSnapshot['hash']
    );
$immutableExtensionError = '';
try {
    $db->prepare("
        UPDATE ingredient_ontology_identity_extension_entities
        SET display_label = 'mutated'
        WHERE ontology_version_id = ?
          AND created_revision = 1
    ")->execute([$versionId]);
} catch (Throwable $error) {
    $immutableExtensionError = $error->getMessage();
}
$assert(
    !empty($extensionIntegrity['valid'])
    && str_contains(
        $immutableExtensionError,
        'identity extension entities are immutable'
    ),
    'Extension hash chains must be audited and published rows immutable'
);

$nestedSnapshotBefore =
    ingredientOntologyV3IdentityExtensionSnapshot(
        $db,
        $versionId
    );
dbBeginImmediateWithRetry($db);
$db->exec("
    CREATE TEMP TRIGGER identity_extension_claim_failure
    BEFORE UPDATE OF head_revision
    ON ingredient_ontology_identity_extension_state
    BEGIN
        SELECT RAISE(ABORT, 'forced identity extension claim failure');
    END
");
$nestedClaimError = '';
try {
    ingredientOntologyV3IdentityExtensionClaim(
        $db,
        ingredientOntologyV3Version($db, $versionId),
        'Nested rollback identity fixture',
        'en-US',
        '',
        true,
        true
    );
} catch (Throwable $error) {
    $nestedClaimError = $error->getMessage();
}
$nestedClaimRows = (int)$db->query("
    SELECT COUNT(*)
    FROM ingredient_ontology_identity_extension_entities
    WHERE ontology_version_id = {$versionId}
      AND normalized_label = 'nested rollback identity fixture'
")->fetchColumn();
$nestedSnapshotAfter =
    ingredientOntologyV3IdentityExtensionSnapshot(
        $db,
        $versionId
    );
$db->exec('DROP TRIGGER identity_extension_claim_failure');
$db->exec('COMMIT');
$assert(
    str_contains(
        $nestedClaimError,
        'forced identity extension claim failure'
    )
    && $nestedClaimRows === 0
    && $nestedSnapshotAfter === $nestedSnapshotBefore,
    'A failed nested extension claim must roll back its entity and chain '
        . 'state without aborting the caller transaction'
);

$hamAdmission = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $hamProductId,
    $versionId
);
$assert(
    $hamAdmission['accepted'] === true
    && (int)$hamAdmission['entity_id'] < 0
    && (int)$hamAdmission['entity_id']
        !== (int)$slicedHamAdmission['entity_id'],
    'Exact self identities must preserve modifiers and never equate Sliced Ham with Ham'
);

$ketchupAdmission = ingredientOntologyV3IdentityAnnexRefreshProduct(
    $db,
    $ketchupProductId,
    $versionId
);
$assert(
    $ketchupAdmission['accepted'] === true
    && (int)$ketchupAdmission['entity_id'] === $entities['ketchup']
    && (string)$ketchupAdmission['source'] === 'accepted_label',
    'Prepared and composite identity roles must be eligible only through exact accepted labels'
);

$undSlicedHam = ingredientOntologyV3RecipeAnnexResolution(
    $db,
    ingredientOntologyV3Version($db, $versionId),
    'Sliced ham',
    'und',
    true
);
$assert(
    $undSlicedHam['status'] === 'accepted'
    && (int)$undSlicedHam['effective_entity_id']
        !== (int)$slicedHamAdmission['entity_id'],
    'Undetermined language must not silently merge with an English exact identity'
);

$publishedSlicedHam =
    ingredientOntologyV3IdentityAdmissionPublishProduct(
        $db,
        $slicedHamProductId,
        $versionId,
        'test_exact_self_publication',
        false
    );
$assert(
    $publishedSlicedHam['accepted'] === true
    && !in_array(
        (string)($publishedSlicedHam['readiness']['status'] ?? ''),
        ['needs_review', 'failed'],
        true
    ),
    'Exact self identity publication must never route a legitimate food to needs_review'
);
$assert(
    hash_equals(
        $contentHashBeforeExactExtensions,
        ingredientOntologyV3ContentHash($db, $versionId)
    ),
    'Exact identity extensions must not mutate the sealed ontology hash'
);

unset(
    $GLOBALS[
        'INGREDIENT_ONTOLOGY_EXACT_SELF_IDENTITY_ENABLED_OVERRIDE'
    ],
    $GLOBALS[
        'INGREDIENT_ONTOLOGY_IDENTITY_ROLE_WIDENING_ENABLED_OVERRIDE'
    ],
    $GLOBALS[
        'INGREDIENT_ONTOLOGY_IDENTITY_READINESS_V2_ENABLED_OVERRIDE'
    ],
    $GLOBALS['INGREDIENT_ONTOLOGY_PRODUCT_LANGUAGE_OVERRIDE']
);
$db = null;
@unlink($path);
@unlink($path . '-wal');
@unlink($path . '-shm');

echo "Instant identity annex tests passed: {$assertions} assertions.\n";
