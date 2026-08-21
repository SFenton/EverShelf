#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$assertions = 0;
$assert = static function (
    bool $condition,
    string $message
) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$fixturePath = __DIR__
    . '/../tests/fixtures/ontology_admission_gold_v1.json';
$fixture = json_decode(
    (string)file_get_contents($fixturePath),
    true,
    512,
    JSON_THROW_ON_ERROR
);
if (!is_array($fixture)) {
    throw new RuntimeException('admission fixture is invalid');
}

$foodCases = [];
$falsePairs = [];
foreach ((array)$fixture['food_groups'] as $group) {
    $templates = array_values((array)($group['templates'] ?? []));
    foreach ((array)($group['bases'] ?? []) as $base) {
        $family = [];
        foreach ($templates as $template) {
            $label = trim(sprintf((string)$template, (string)$base));
            if ($label === '') {
                continue;
            }
            $foodCases[$label] = [
                'label' => $label,
                'group' => (string)($group['id'] ?? ''),
            ];
            $family[] = $label;
        }
        $family = array_values(array_unique($family));
        for ($index = 1; $index < count($family); $index++) {
            $falsePairs[] = [$family[0], $family[$index]];
        }
    }
}
$foodCases = array_values($foodCases);
$assert(
    count($foodCases)
        >= (int)$fixture['minimum_generated_food_cases'],
    'Admission corpus must contain at least 400 food cases'
);
$assert(
    count($falsePairs)
        >= (int)$fixture['minimum_false_equivalence_pairs'],
    'Admission corpus must contain at least 200 false-equivalence pairs'
);

$path = dirname(__DIR__) . '/data/.ontology-admission-gold-'
    . getmypid() . '.sqlite';
@unlink($path);
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
migrateDB($db);

$GLOBALS[
    'INGREDIENT_ONTOLOGY_EXACT_SELF_IDENTITY_ENABLED_OVERRIDE'
] = true;
$GLOBALS[
    'INGREDIENT_ONTOLOGY_IDENTITY_ROLE_WIDENING_ENABLED_OVERRIDE'
] = true;
$GLOBALS[
    'INGREDIENT_ONTOLOGY_IDENTITY_READINESS_V2_ENABLED_OVERRIDE'
] = true;
$GLOBALS['INGREDIENT_ONTOLOGY_PRODUCT_LANGUAGE_OVERRIDE'] =
    (string)$fixture['language'];

$hash = str_repeat('b', 64);
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
        'admission-gold-v1', 'building', ?, ?, ?,
        'gemini-3.5-flash', ?, ?, ?, ?, ?, ?,
        'test_only', 'test', 'test', ?, ?, ?, CURRENT_TIMESTAMP
    )
")->execute(array_fill(0, 12, $hash));
$versionId = (int)$db->lastInsertId();
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
$version = ingredientOntologyV3Version($db, $versionId);
$recipeLanguage = 'en-US';

$insertProduct = $db->prepare("
    INSERT INTO products (name, brand, category, prepared_food)
    VALUES (?, '', 'admission-gold', ?)
");
$entityByLabel = [];
$productIdByLabel = [];
$durationsMs = [];
foreach ($foodCases as $case) {
    $label = (string)$case['label'];
    $insertProduct->execute([$label, 0]);
    $productId = (int)$db->lastInsertId();
    $started = hrtime(true);
    $admission = ingredientOntologyV3IdentityAnnexRefreshProduct(
        $db,
        $productId,
        $versionId
    );
    $durationsMs[] = (hrtime(true) - $started) / 1000000;
    $recipeResolution = ingredientOntologyV3RecipeAnnexResolution(
        $db,
        $version,
        $label,
        $recipeLanguage,
        true
    );
    $readiness =
        ingredientOntologyV3ProductReadinessRecordResolution(
            $db,
            $admission,
            'admission_gold',
            true
        );
    $assert(
        $admission['accepted'] === true
        && (string)$admission['source'] === 'exact_self_identity'
        && (int)$admission['entity_id'] < 0,
        "Legitimate food did not reach exact identity: {$label}"
    );
    $assert(
        (string)$recipeResolution['status'] === 'accepted'
        && (int)$recipeResolution['effective_entity_id']
            === (int)$admission['entity_id'],
        "Product and recipe lexemes did not converge: {$label}"
    );
    $assert(
        !in_array(
            (string)($readiness['status'] ?? ''),
            ['needs_review', 'failed'],
            true
        ),
        "Legitimate food reached a review/failure state: {$label}"
    );
    $entityByLabel[$label] = (int)$admission['entity_id'];
    $productIdByLabel[$label] = $productId;
}

$representativeIds = [];
$insertRepresentative = $db->prepare("
    INSERT INTO products (
        name, brand, category, prepared_food
    )
    VALUES (?, ?, ?, 0)
");
foreach ((array)($fixture['representative_cases'] ?? []) as $case) {
    $label = (string)$case['label'];
    $language = (string)$case['language'];
    $GLOBALS['INGREDIENT_ONTOLOGY_PRODUCT_LANGUAGE_OVERRIDE'] =
        $language;
    $insertRepresentative->execute([
        $label,
        (string)$case['brand'],
        (string)$case['category'],
    ]);
    $productId = (int)$db->lastInsertId();
    $admission = ingredientOntologyV3IdentityAnnexRefreshProduct(
        $db,
        $productId,
        $versionId
    );
    $recipeResolution = ingredientOntologyV3RecipeAnnexResolution(
        $db,
        $version,
        $label,
        $language,
        true
    );
    $readiness =
        ingredientOntologyV3ProductReadinessRecordResolution(
            $db,
            $admission,
            'admission_representative',
            true
        );
    $assert(
        !empty($admission['accepted'])
        && (string)$admission['source'] === 'exact_self_identity'
        && (int)$admission['entity_id'] < 0
        && (int)$recipeResolution['effective_entity_id']
            === (int)$admission['entity_id']
        && (string)$readiness['status'] !== 'needs_review',
        'Representative admission failed: '
            . (string)$case['id']
    );
    $representativeIds[(string)$case['id']] =
        (int)$admission['entity_id'];
    $entityByLabel[$label] = (int)$admission['entity_id'];
    $productIdByLabel[$label] = $productId;
}
$GLOBALS['INGREDIENT_ONTOLOGY_PRODUCT_LANGUAGE_OVERRIDE'] =
    (string)$fixture['language'];
foreach (
    (array)($fixture['representative_false_pairs'] ?? [])
    as $pair
) {
    $leftCase = null;
    $rightCase = null;
    foreach ((array)$fixture['representative_cases'] as $case) {
        if ((string)$case['id'] === (string)$pair[0]) {
            $leftCase = (string)$case['label'];
        }
        if ((string)$case['id'] === (string)$pair[1]) {
            $rightCase = (string)$case['label'];
        }
    }
    if ($leftCase !== null && $rightCase !== null) {
        $falsePairs[] = [$leftCase, $rightCase];
    }
}

$assert(
    count(array_unique(array_values($entityByLabel)))
        === count($entityByLabel),
    'Distinct full labels must not collapse onto one exact identity'
);
$snapshotBeforeReplay =
    ingredientOntologyV3IdentityExtensionSnapshot($db, $versionId);
foreach ($foodCases as $case) {
    $label = (string)$case['label'];
    $replayed = ingredientOntologyV3IdentityAnnexRefreshProduct(
        $db,
        $productIdByLabel[$label],
        $versionId
    );
    $assert(
        (int)$replayed['entity_id'] === $entityByLabel[$label],
        "Replay changed exact identity: {$label}"
    );
}
$snapshotAfterReplay =
    ingredientOntologyV3IdentityExtensionSnapshot($db, $versionId);
$assert(
    $snapshotAfterReplay === $snapshotBeforeReplay,
    'Idempotent replay must not append extension revisions'
);

$lateResolution = ingredientOntologyV3RecipeAnnexResolution(
    $db,
    $version,
    'late harvest identity fixture',
    $recipeLanguage,
    true
);
$lateSnapshot =
    ingredientOntologyV3IdentityExtensionSnapshot($db, $versionId);
$oldContext = new IngredientOntologyV3MatcherContext(
    $db,
    $versionId,
    (int)$snapshotAfterReplay['revision']
);
$newContext = new IngredientOntologyV3MatcherContext(
    $db,
    $versionId,
    (int)$lateSnapshot['revision']
);
$lateEntityId = (int)$lateResolution['effective_entity_id'];
$assert(
    $lateSnapshot['revision'] === $snapshotAfterReplay['revision'] + 1
    && !isset($oldContext->entities[$lateEntityId])
    && isset($newContext->entities[$lateEntityId]),
    'Score revisions must see only their pinned identity extension prefix'
);

$context = new IngredientOntologyV3MatcherContext(
    $db,
    $versionId,
    (int)$snapshotAfterReplay['revision']
);
foreach ($falsePairs as [$left, $right]) {
    $match = ingredientOntologyV3MatchWithContext(
        $context,
        [
            'entity_id' => $entityByLabel[$left],
            'status' => 'accepted',
            'confidence' => 1.0,
            'mapping_source' => 'identity_annex',
            'attributes' => [],
        ],
        [
            'entity_id' => $entityByLabel[$right],
            'status' => 'accepted',
            'confidence' => 1.0,
            'mapping_source' => 'identity_annex',
            'attributes' => [],
        ]
    );
    $assert(
        empty($match['satisfies_required']),
        "False equivalence admitted: {$left} == {$right}"
    );
}
$seaLavenderEntity =
    $representativeIds['taxonomy-trap-sea-lavender'] ?? null;
if ($seaLavenderEntity !== null) {
    $hierarchyOnlyMatch = ingredientOntologyV3MatchWithContext(
        $context,
        [
            'entity_id' => $seaLavenderEntity,
            'status' => 'accepted',
            'confidence' => 1.0,
            'mapping_source' => 'identity_annex',
            'attributes' => [],
        ],
        [
            'entity_id' => $seaLavenderEntity,
            'status' => 'accepted',
            'confidence' => 1.0,
            'mapping_source' => 'foodon_hierarchy',
            'attributes' => [],
        ]
    );
    $assert(
        empty($hierarchyOnlyMatch['satisfies_required']),
        'Semantic ancestry must never satisfy exact identity'
    );
}

foreach ((array)$fixture['prepared_products'] as $label) {
    $insertProduct->execute([(string)$label, 1]);
    $productId = (int)$db->lastInsertId();
    $admission = ingredientOntologyV3IdentityAnnexRefreshProduct(
        $db,
        $productId,
        $versionId
    );
    $readiness =
        ingredientOntologyV3ProductReadinessRecordResolution(
            $db,
            $admission,
            'admission_gold_prepared',
            true
        );
    $assert(
        (string)$admission['status'] === 'rejected'
        && (string)$admission['reason'] === 'prepared_food'
        && (string)$readiness['status'] === 'non_satisfying',
        "Prepared product did not reach terminal non-review: {$label}"
    );
}

foreach ((array)$fixture['empty_or_invalid_labels'] as $label) {
    $resolution = ingredientOntologyV3RecipeAnnexResolution(
        $db,
        $version,
        (string)$label,
        $recipeLanguage,
        true
    );
    $assert(
        (string)$resolution['status'] === 'unresolved'
        && $resolution['effective_entity_id'] === null,
        'Empty or punctuation-only labels must fail closed'
    );
}

$oversizedLabel = str_repeat('oversized food label ', 12);
$insertProduct->execute([$oversizedLabel, 0]);
$oversizedProductId = (int)$db->lastInsertId();
$oversizedAdmission =
    ingredientOntologyV3IdentityAnnexRefreshProduct(
        $db,
        $oversizedProductId,
        $versionId
    );
$oversizedReadiness = null;
for ($attempt = 0; $attempt < 6; $attempt++) {
    $oversizedReadiness =
        ingredientOntologyV3ProductReadinessRecordResolution(
            $db,
            $oversizedAdmission,
            'admission_gold_unchanged_evidence',
            true
        );
}
$assert(
    (string)$oversizedAdmission['status'] === 'unresolved'
    && (string)($oversizedReadiness['status'] ?? '')
        === 'non_satisfying'
    && (int)($oversizedReadiness['attempts'] ?? -1) === 0,
    'Permanently unsupported labels must terminate without needs_review '
        . 'or retry churn'
);

$insertProduct->execute(['Deferred retry backoff fixture', 0]);
$deferredProductId = (int)$db->lastInsertId();
$deferredAdmission =
    ingredientOntologyV3IdentityAnnexRefreshProduct(
        $db,
        $deferredProductId,
        $versionId,
        true,
        false,
        false
    );
$deferredReadiness = null;
for ($attempt = 0; $attempt < 6; $attempt++) {
    $deferredReadiness =
        ingredientOntologyV3ProductReadinessRecordResolution(
            $db,
            $deferredAdmission,
            'admission_gold_deferred_retry',
            true
        );
}
$deferredRetryAt = strtotime(
    (string)($deferredReadiness['next_retry_at'] ?? '') . ' UTC'
);
$assert(
    (string)$deferredAdmission['status'] === 'unresolved'
    && (string)($deferredReadiness['status'] ?? '') === 'retry'
    && (int)($deferredReadiness['attempts'] ?? 0) === 6
    && $deferredRetryAt !== false
    && $deferredRetryAt - time() >= 3500,
    'Unchanged transient evidence must back off without becoming '
        . 'needs_review or polling continuously'
);

$localeProductLabel = (string)$foodCases[0]['label'];
$localeProductEntity = $entityByLabel[$localeProductLabel];
$enGbResolution = ingredientOntologyV3RecipeAnnexResolution(
    $db,
    $version,
    $localeProductLabel,
    'en-GB',
    true
);
$deResolution = ingredientOntologyV3RecipeAnnexResolution(
    $db,
    $version,
    $localeProductLabel,
    'de-DE',
    true
);
$undResolution = ingredientOntologyV3RecipeAnnexResolution(
    $db,
    $version,
    $localeProductLabel,
    'und',
    true
);
$assert(
    (int)$enGbResolution['effective_entity_id']
        === $localeProductEntity
    && (int)$deResolution['effective_entity_id']
        !== $localeProductEntity
    && (int)$undResolution['effective_entity_id']
        !== $localeProductEntity,
    'English locale variants must converge while other and undetermined '
        . 'languages remain isolated'
);

sort($durationsMs, SORT_NUMERIC);
$p95Index = max(
    0,
    min(
        count($durationsMs) - 1,
        (int)ceil(count($durationsMs) * 0.95) - 1
    )
);
$p95Ms = $durationsMs[$p95Index] ?? INF;
$assert(
    $p95Ms <= 50.0,
    sprintf(
        'Exact admission p95 exceeded isolated SQLite gate: %.3f ms',
        $p95Ms
    )
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

echo 'Ontology admission coverage passed: '
    . count($foodCases) . ' foods, '
    . count($falsePairs) . ' false-equivalence pairs, '
    . $assertions . ' assertions, p95 '
    . number_format($p95Ms, 3) . " ms.\n";
