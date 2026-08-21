#!/usr/bin/env php
<?php
declare(strict_types=1);

putenv('INGREDIENT_ONTOLOGY_CONTROLLER_ENABLED=false');
putenv('ONTOLOGY_AUTONOMOUS_ENABLED=false');
define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

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

$path = dirname(__DIR__) . '/data/.exact-identity-product-save-'
    . getmypid() . '.sqlite';
@unlink($path);
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA synchronous=NORMAL');
migrateDB($db);

$assert(
    ingredientOntologyV3ExactSelfIdentityEnabled()
    && ingredientOntologyV3IdentityReadinessV2Enabled(),
    'Exact-self admission and retry-safe readiness must default on'
);
$GLOBALS[
    'INGREDIENT_ONTOLOGY_EXACT_SELF_IDENTITY_ENABLED_OVERRIDE'
] = false;
$GLOBALS[
    'INGREDIENT_ONTOLOGY_IDENTITY_ROLE_WIDENING_ENABLED_OVERRIDE'
] = true;
$GLOBALS[
    'INGREDIENT_ONTOLOGY_IDENTITY_READINESS_V2_ENABLED_OVERRIDE'
] = true;
$GLOBALS['INGREDIENT_ONTOLOGY_PRODUCT_LANGUAGE_OVERRIDE'] = 'en';
$GLOBALS['CANONICAL_QUEUE_TEST_WAKE'] =
    static fn(): bool => true;

$hash = str_repeat('c', 64);
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
        'exact-save-v1', 'building', ?, ?, ?,
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
$state = recipeScoreState($db);
ingredientOntologyV3SetReadyMutationGuard($db, true);
$db->prepare("
    INSERT INTO recipe_score_revisions (
        inventory_revision, catalog_revision,
        inventory_fingerprint, score_date, catalog_max_id,
        status, recipe_count, ontology_version_id,
        scoring_model, scoring_config_hash,
        catalog_fingerprint, ontology_schema_hash,
        ontology_prompt_hash, ontology_model_hash,
        ontology_corpus_hash, ontology_content_hash,
        ontology_portable_content_hash,
        ontology_review_manifest_hash,
        ontology_resolution_gold_hash, ontology_seal_hash,
        ontology_source_revision, ontology_source_hash,
        identity_extension_revision, identity_extension_hash,
        completed_at
    )
    VALUES (
        ?, ?, ?, ?, 0, 'ready', 0, ?,
        'faceted-ontology-v3', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, 0, ?, CURRENT_TIMESTAMP
    )
")->execute([
    (int)$state['inventory_revision'],
    (int)$state['catalog_revision'],
    $hash,
    recipeScoreCurrentDate(),
    $versionId,
    ingredientOntologyV3ScoringConfigHash(),
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $contentHash,
    $hash,
    $hash,
    $hash,
    $hash,
    (int)$state['ontology_source_revision'],
    $hash,
    ingredientOntologyV3IdentityExtensionZeroHash(),
]);
ingredientOntologyV3SetReadyMutationGuard($db, false);
$scoreRevisionId = (int)$db->lastInsertId();
$db->prepare("
    UPDATE recipe_score_state
    SET active_score_revision_id = ?,
        active_score_projection_revision_id = ?,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = 1
")->execute([$scoreRevisionId, $scoreRevisionId]);
ingredientOntologyV3SetPublicationGuard($db, false);

$saveProduct = static function (
    PDO $db,
    string $name,
    ?int $productId = null,
    bool $includePreparedFlag = false
): array {
    $GLOBALS['PRODUCT_API_JSON_INPUT'] = [
        'name' => $name,
        'brand' => 'Admission Benchmark',
        'category' => 'food',
        'unit' => 'pz',
        'default_quantity' => 1,
    ];
    if ($productId !== null) {
        $GLOBALS['PRODUCT_API_JSON_INPUT']['id'] = $productId;
    }
    if ($includePreparedFlag) {
        $GLOBALS['PRODUCT_API_JSON_INPUT']['prepared_food'] = false;
    }
    ob_start();
    try {
        saveProduct($db);
        $payload = json_decode(
            (string)ob_get_clean(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } finally {
        unset($GLOBALS['PRODUCT_API_JSON_INPUT']);
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
    if (!is_array($payload)) {
        throw new RuntimeException('product save payload is invalid');
    }
    return $payload;
};

$baselineLatencies = [];
for ($index = 0; $index < 5; $index++) {
    $saveProduct(
        $db,
        sprintf('Baseline Warmup Food %02d', $index)
    );
}
for ($index = 0; $index < 30; $index++) {
    $name = sprintf('Baseline Save Food %02d', $index);
    $started = hrtime(true);
    $saved = $saveProduct($db, $name);
    $baselineLatencies[] = (hrtime(true) - $started) / 1000000;
    $assert(
        !empty($saved['success'])
        && empty($saved['identity_admission']['accepted']),
        "Baseline product save unexpectedly admitted identity: {$name}"
    );
}

$GLOBALS[
    'INGREDIENT_ONTOLOGY_EXACT_SELF_IDENTITY_ENABLED_OVERRIDE'
] = true;
$latencies = [];
for ($index = 0; $index < 30; $index++) {
    $name = sprintf('Exact Save Food %02d', $index);
    $started = hrtime(true);
    $saved = $saveProduct($db, $name);
    $latencies[] = (hrtime(true) - $started) / 1000000;
    $productId = (int)($saved['id'] ?? 0);
    $backgroundAdmission =
        ingredientOntologyV3IdentityAdmissionPublishProduct(
            $db,
            $productId,
            $versionId,
            'exact_identity_background_test',
            false
        );
    $effectiveAdmission = !empty(
        $saved['identity_admission']['accepted']
    ) ? $saved['identity_admission'] : $backgroundAdmission;
    $assert(
        !empty($saved['success'])
        && !empty($effectiveAdmission['accepted'])
        && (string)(
            $effectiveAdmission['source'] ?? ''
        ) === 'exact_self_identity'
        && (int)(
            $effectiveAdmission['entity_id'] ?? 0
        ) < 0,
        "Product save/background work did not publish exact identity: {$name}"
    );
}
$updatedExact = $saveProduct(
    $db,
    'Exact Save Food 29',
    $productId
);
$assert(
    !empty($updatedExact['identity_admission']['accepted'])
    && (string)(
        $updatedExact['identity_admission']['source'] ?? ''
    ) === 'exact_self_identity',
    'Updating an exact-identity product must preserve immediate admission'
);
sort($baselineLatencies, SORT_NUMERIC);
sort($latencies, SORT_NUMERIC);
$p95Index = (int)ceil(count($latencies) * 0.95) - 1;
$baselineP95Ms =
    $baselineLatencies[max(0, $p95Index)] ?? INF;
$p95Ms = $latencies[max(0, $p95Index)] ?? INF;
$assert(
    $p95Ms <= 60.0
    && $p95Ms <= $baselineP95Ms * 1.10,
    sprintf(
        'Exact identity product-save p95 %.3f ms exceeded baseline %.3f ms',
        $p95Ms,
        $baselineP95Ms
    )
);
$assert(
    (int)$db->query("
        SELECT COUNT(*)
        FROM ingredient_ontology_product_readiness
        WHERE status = 'needs_review'
    ")->fetchColumn() === 0,
    'Exact identity product saves must not create needs_review rows'
);

$providerIndependent = $saveProduct(
    $db,
    'Provider Independent Exact Food'
);
$providerIndependentId = (int)$providerIndependent['id'];
$providerIndependentJobId = (int)(
    $providerIndependent['identity_admission'][
        'background_job_id'
    ] ?? 0
);
$db->prepare("
    DELETE FROM canonical_processing_queue
    WHERE product_id = ?
")->execute([$providerIndependentId]);
recipeJobProcessQueueBatch($db, 100, 20, false);
$providerIndependentAnnex = $db->query("
    SELECT status, admission_source
    FROM ingredient_ontology_identity_annex
    WHERE product_id = {$providerIndependentId}
")->fetch(PDO::FETCH_ASSOC);
$assert(
    $providerIndependentJobId > 0
    && recipeJobGet(
        $db,
        $providerIndependentJobId
    )['status'] === 'done'
    && (string)$providerIndependentAnnex['status'] === 'accepted'
    && (string)$providerIndependentAnnex['admission_source']
        === 'exact_self_identity',
    'Background exact-self admission depended on canonical/provider work'
);

$failureSubsystems = [
    'shopping_intent',
    'prepared_food_recipe_scoring',
    'canonical_enqueue',
    'controller_observation',
    'identity_admission',
];
foreach ($failureSubsystems as $subsystem) {
    $GLOBALS['PRODUCT_SAVE_TEST_HOOK'] =
        static function (
            string $stage,
            array $context
        ) use ($subsystem): void {
            if (
                $stage === 'before_side_effect'
                && ($context['name'] ?? null) === $subsystem
            ) {
                throw new RuntimeException(
                    'injected_' . $subsystem
                );
            }
        };
    $saved = $saveProduct(
        $db,
        'Fail-open ' . str_replace('_', ' ', $subsystem),
        null,
        true
    );
    $savedId = (int)($saved['id'] ?? 0);
    $assert(
        !empty($saved['success'])
        && !empty($saved['degraded'])
        && in_array(
            $subsystem,
            array_column(
                $saved['degraded_subsystems'] ?? [],
                'subsystem'
            ),
            true
        )
        && (int)$db->query("
            SELECT COUNT(*)
            FROM products
            WHERE id = {$savedId}
        ")->fetchColumn() === 1,
        'Subsystem failure rolled back product save: ' . $subsystem
    );
    unset($GLOBALS['PRODUCT_SAVE_TEST_HOOK']);
    if ($subsystem === 'canonical_enqueue') {
        $reconcile = canonicalIngredientReconcileMissingProducts(
            $db,
            100
        );
        $assert(
            in_array($savedId, $reconcile['product_ids'], true)
            && canonicalIngredientQueueStatusForProduct(
                $db,
                $savedId
            ) !== null,
            'Canonical enqueue failure was not reconcilable'
        );
    } elseif ($subsystem === 'identity_admission') {
        $reconciled =
            ingredientOntologyV3IdentityAdmissionPublishProduct(
                $db,
                $savedId,
                $versionId,
                'product_save_failure_reconciliation',
                false
            );
        $assert(
            !empty($reconciled['accepted']),
            'Identity admission failure was not reconcilable'
        );
    } elseif ($subsystem === 'prepared_food_recipe_scoring') {
        $requeued = recipeJobEnqueueInventoryChanged(
            $db,
            $savedId,
            'product_save_failure_reconciliation'
        );
        $assert(
            (int)($requeued['id'] ?? 0) > 0,
            'Prepared-food score failure was not reconcilable'
        );
    } elseif ($subsystem === 'controller_observation') {
        $reobserved =
            ingredientOntologyControllerObserveProductSafely(
                $db,
                $savedId
            );
        $assert(
            is_array($reobserved),
            'Controller observation failure was not reconcilable'
        );
    } elseif ($subsystem === 'shopping_intent') {
        $rerecorded = productSaveRecordShoppingIntent(
            $db,
            $savedId,
            'deterministic'
        );
        $assert(
            is_array($rerecorded),
            'Shopping intent failure was not reconcilable'
        );
    }
}

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
    $GLOBALS['INGREDIENT_ONTOLOGY_PRODUCT_LANGUAGE_OVERRIDE'],
    $GLOBALS['CANONICAL_QUEUE_TEST_WAKE'],
    $GLOBALS['PRODUCT_SAVE_TEST_HOOK']
);
$db = null;
@unlink($path);
@unlink($path . '-wal');
@unlink($path . '-shm');

echo 'Exact identity product-save test passed: '
    . $assertions . ' assertions, p95 '
    . number_format($p95Ms, 3) . ' ms vs baseline '
    . number_format($baselineP95Ms, 3) . " ms.\n";
