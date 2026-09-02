#!/usr/bin/env php
<?php
declare(strict_types=1);

putenv('INGREDIENT_ONTOLOGY_CONTROLLER_ENABLED=false');
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

$path = dirname(__DIR__) . '/data/.corpus-annex-'
    . getmypid() . '.sqlite';
$artifacts = [$path];
$cleanup = static function () use (&$artifacts): void {
    foreach ($artifacts as $artifact) {
        foreach ([
            $artifact,
            $artifact . '-wal',
            $artifact . '-shm',
            $artifact . '.migration.lock',
            dirname($artifact) . '/.'
                . basename($artifact) . '.recipe-score.lock',
        ] as $file) {
            @unlink($file);
        }
    }
};
$cleanup();
register_shutdown_function($cleanup);

$open = static function (string $databasePath): PDO {
    $db = new PDO('sqlite:' . $databasePath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');
    ingredientOntologyV3RegisterGuardFunctions($db);
    return $db;
};
$runBenchmarkWorker = static function (
    string $databasePath,
    string $fixtureToken
): array {
    $prefix = $databasePath . '.fixture-worker-' . getmypid();
    $pipes = [];
    $process = proc_open(
        [
            PHP_BINARY,
            __DIR__ . '/incremental-score-worker.php',
            '--db=' . $databasePath,
            '--background-lock=' . $prefix . '.background',
            '--coordination-lock=' . $prefix . '.coordination',
            '--heartbeat=' . $prefix . '.heartbeat',
            '--status-file=' . $prefix . '.status',
            '--force',
            '--json',
            '--benchmark-metrics',
            '--benchmark-fixture-token=' . $fixtureToken,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        throw new RuntimeException(
            'fixture worker lifecycle could not start'
        );
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    foreach ([
        '.background',
        '.coordination',
        '.heartbeat',
        '.status',
    ] as $suffix) {
        @unlink($prefix . $suffix);
    }
    $payload = json_decode(trim((string)$stdout), true);
    if ($status !== 0 || !is_array($payload)) {
        throw new RuntimeException(
            'fixture worker lifecycle failed: '
                . ingredientOntologyV3Json([
                    'status' => $status,
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ])
        );
    }
    return $payload;
};

$db = $open($path);
migrateDB($db);

$tables = $db->query("
    SELECT name
    FROM sqlite_master
    WHERE type = 'table'
      AND name IN (
          'ingredient_ontology_corpus_annex_revisions',
          'ingredient_ontology_corpus_annex_entries',
          'ingredient_ontology_corpus_annex_effective_aggregates',
          'ingredient_ontology_corpus_annex_effective_members',
          'ingredient_ontology_corpus_annex_effective_entities',
          'ingredient_ontology_corpus_annex_projection_state',
          'ingredient_ontology_identity_annex_history',
          'recipe_processing_status_snapshot',
          'recipe_score_identity_projection_events',
          'recipe_score_identity_projection_state',
          'recipe_score_identity_projection_work',
          'recipe_score_mutation_scopes',
          'recipe_score_projection_status',
          'recipe_score_source_reconciliation_events',
          'recipe_score_source_reconciliation_scopes'
      )
    ORDER BY name
")->fetchAll(PDO::FETCH_COLUMN);
$scoreColumns = array_column(
    $db->query("PRAGMA table_info(recipe_score_revisions)")
        ->fetchAll(PDO::FETCH_ASSOC),
    'name'
);
$annexRevisionColumns = array_column(
    $db->query("
        PRAGMA table_info(
            ingredient_ontology_corpus_annex_revisions
        )
    ")->fetchAll(PDO::FETCH_ASSOC),
    'name'
);
$effectiveMemberColumns = array_column(
    $db->query("
        PRAGMA table_info(
            ingredient_ontology_corpus_annex_effective_members
        )
    ")->fetchAll(PDO::FETCH_ASSOC),
    'name'
);
$projectionStateColumns = array_column(
    $db->query("
        PRAGMA table_info(
            ingredient_ontology_corpus_annex_projection_state
        )
    ")->fetchAll(PDO::FETCH_ASSOC),
    'name'
);
$reconciliationBackfillColumns = array_column(
    $db->query("
        PRAGMA table_info(
            recipe_score_source_reconciliation_backfill
        )
    ")->fetchAll(PDO::FETCH_ASSOC),
    'name'
);
$reconciliationEventColumns = array_column(
    $db->query("
        PRAGMA table_info(
            recipe_score_source_reconciliation_events
        )
    ")->fetchAll(PDO::FETCH_ASSOC),
    'name'
);
$assert(
    $tables === [
        'ingredient_ontology_corpus_annex_effective_aggregates',
        'ingredient_ontology_corpus_annex_effective_entities',
        'ingredient_ontology_corpus_annex_effective_members',
        'ingredient_ontology_corpus_annex_entries',
        'ingredient_ontology_corpus_annex_projection_state',
        'ingredient_ontology_corpus_annex_revisions',
        'ingredient_ontology_identity_annex_history',
        'recipe_processing_status_snapshot',
        'recipe_score_identity_projection_events',
        'recipe_score_identity_projection_state',
        'recipe_score_identity_projection_work',
        'recipe_score_mutation_scopes',
        'recipe_score_projection_status',
        'recipe_score_source_reconciliation_events',
        'recipe_score_source_reconciliation_scopes',
    ]
    && in_array('corpus_annex_revision_id', $scoreColumns, true)
    && in_array('corpus_annex_hash', $scoreColumns, true)
    && in_array(
        'covered_identity_extension_revision',
        $scoreColumns,
        true
    )
    && in_array('hash_version', $annexRevisionColumns, true)
    && in_array(
        'covered_identity_extension_revision',
        $annexRevisionColumns,
        true
    )
    && in_array(
        'normalized_source_label',
        $effectiveMemberColumns,
        true
    )
    && in_array(
        'cache_schema_version',
        $projectionStateColumns,
        true
    )
    && in_array(
        'scope_backfill_version',
        $reconciliationBackfillColumns,
        true
    )
    && in_array(
        'scope_backfill_started',
        $reconciliationBackfillColumns,
        true
    )
    && in_array(
        'expected_scope_count',
        $reconciliationEventColumns,
        true
    ),
    'Corpus annex schema and score pins must be installed'
);
$assert(
    ingredientOntologyV3CorpusAnnexIndexesRelation(
        'equivalent_to'
    )
    && ingredientOntologyV3CorpusAnnexIndexesRelation(
        'variant_of'
    )
    && ingredientOntologyV3CorpusAnnexIndexesRelation(
        'substitutes_for'
    )
    && !ingredientOntologyV3CorpusAnnexIndexesRelation(
        'derived_from'
    )
    && !ingredientOntologyV3CorpusAnnexIndexesRelation(
        'component_of'
    ),
    'Reverse dependency indexing must exclude non-identity '
        . 'derivation and component evidence'
);

$db->exec("
    INSERT INTO products (id, name, brand, category)
    VALUES (235, 'Baseline Annex Product', '', 'food')
");
$db->exec("
    INSERT INTO recipe_catalog (
        id, primary_connector, title, language, cache_expires_at
    )
    VALUES (
        34814, 'manual', 'Baseline Annex Recipe', 'en',
        datetime('now', '+1 day')
    )
");
$baselineRecipeId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO recipe_origins (
        id, recipe_id, connector, external_id, locale,
        content_language
    )
    VALUES (
        34814, ?, 'manual', 'annex-baseline', 'en-US', 'en'
    )
")->execute([$baselineRecipeId]);
$db->prepare("
    INSERT INTO recipe_ingredients (
        id, recipe_id, position, raw_text, normalized_name,
        source_is_required, source_is_optional,
        requiredness_source
    )
    VALUES (
        414834, ?, 0, 'baseline ingredient', 'baseline ingredient',
        1, 0, 'explicit_required'
    )
")->execute([$baselineRecipeId]);
$baselineIngredientId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO recipe_source_ingredients (
        id, recipe_id, position, name, normalized_name,
        source_optional
    )
    VALUES (
        167994, ?, 0, 'baseline ingredient',
        'baseline ingredient', 0
    )
")->execute([$baselineRecipeId]);
$db->prepare("
    INSERT OR IGNORE INTO recipe_user_state (recipe_id)
    VALUES (?)
")->execute([$baselineRecipeId]);
recipeSearchRebuildDocument($db, $baselineRecipeId);

$baselineCorpusHash = ingredientOntologyV3CorpusHash($db);
$contentHash = hash('sha256', 'corpus-annex-content');
$portableHash = hash('sha256', 'corpus-annex-portable');
$goldHash = hash('sha256', 'corpus-annex-gold');
$sealHash = hash('sha256', 'corpus-annex-seal');
$frozenHash = hash('sha256', 'corpus-annex-frozen');
$subjectsHash = hash('sha256', 'corpus-annex-subjects');
$policyHash = ingredientOntologyControllerPolicyHash();
$db->prepare("
    INSERT INTO ingredient_ontology_versions (
        version, status, schema_hash, prompt_hash, model_hash,
        model_name, corpus_hash, content_hash,
        portable_content_hash, review_manifest_hash,
        resolution_gold_hash, seal_hash,
        activation_policy, activation_block_reason,
        corpus_profile, frozen_corpus_hash,
        frozen_subjects_hash, policy_hash,
        controller_policy_hash, ready_at
    )
    VALUES (
        'corpus-annex-test', 'building', ?, ?, ?,
        'gemini-3.5-flash', ?, ?, ?, ?, ?, ?,
        'test_only', 'test', 'test', ?, ?, ?, ?,
        CURRENT_TIMESTAMP
    )
")->execute([
    ingredientOntologyV3SchemaHash(),
    ingredientOntologyV3PromptHash(),
    ingredientOntologyV3ModelHash('gemini-3.5-flash'),
    $baselineCorpusHash,
    $contentHash,
    $portableHash,
    ingredientOntologyV3IdentityAnnexReviewManifestHash(),
    $goldHash,
    $sealHash,
    $frozenHash,
    $subjectsHash,
    $policyHash,
    ingredientOntologyControllerPolicyHash(),
]);
$versionId = (int)$db->lastInsertId();
$manifest = ingredientOntologyV3ResolutionManifest();
$db->prepare("
    INSERT INTO ingredient_ontology_resolution_manifests (
        ontology_version_id, manifest_key, manifest_version,
        manifest_hash, content_hash, source_corpus_hash,
        reviewer, review_batch, metadata_json
    )
    VALUES (?, ?, ?, ?, ?, ?, 'test', 'corpus-annex', '{}')
")->execute([
    $versionId,
    (string)$manifest['manifest_key'],
    (string)$manifest['manifest_version'],
    (string)$manifest['manifest_hash'],
    (string)$manifest['content_hash'],
    $baselineCorpusHash,
]);
ingredientOntologyV3SetPublicationGuard($db, true);
$db->prepare("
    UPDATE ingredient_ontology_versions
    SET status = 'ready', ready_at = CURRENT_TIMESTAMP
    WHERE id = ?
")->execute([$versionId]);
ingredientOntologyV3SetPublicationGuard($db, false);

do {
    $identity = ingredientOntologyV3IdentityAdmissionSync($db);
    $remaining = max(
        (int)($identity['resolver_migration']['remaining'] ?? 0),
        (int)(
            $identity[
                'recipe_resolver_migration'
            ]['remaining'] ?? 0
        )
    );
} while ($remaining > 0);

$state = recipeScoreState($db);
$baselineCorpusHash = ingredientOntologyV3CorpusHash($db);
$catalogFingerprint = recipeScoreCatalogFingerprint($db);
$idSets = ingredientOntologyV3MaterializedIdSetHashes($db, 0, null);
$hash = hash('sha256', 'corpus-annex-score');
$identityExtension =
    ingredientOntologyV3IdentityExtensionSnapshot($db, $versionId);
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
        catalog_id_set_hash, ingredient_id_set_hash,
        score_rows_hash, match_rows_hash,
        materialization_hash, validation_report_json
    )
    VALUES (
        ?, ?, ?, ?, ?, 'building', 1, ?,
        'faceted-ontology-v3', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
    )
")->execute([
    (int)$state['inventory_revision'],
    (int)$state['catalog_revision'],
    $hash,
    recipeScoreCurrentDate(),
    $baselineRecipeId,
    $versionId,
    ingredientOntologyV3ScoringConfigHash(),
    $catalogFingerprint,
    ingredientOntologyV3SchemaHash(),
    ingredientOntologyV3PromptHash(),
    ingredientOntologyV3ModelHash('gemini-3.5-flash'),
    $baselineCorpusHash,
    $contentHash,
    $portableHash,
    ingredientOntologyV3IdentityAnnexReviewManifestHash(),
    $goldHash,
    $sealHash,
    (int)$state['ontology_source_revision'],
    $baselineCorpusHash,
    (int)$identityExtension['revision'],
    (string)$identityExtension['hash'],
    (string)$idSets['catalog_id_set_hash'],
    (string)$idSets['ingredient_id_set_hash'],
    $hash,
    $hash,
    $hash,
    ingredientOntologyV3Json([
        'materialized_hash_algorithm' => 'full-v1',
        'recipe_count' => 1,
        'ingredient_match_count' => 1,
        'ontology_source_scope' =>
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_SCOPE_BASE,
        'scoring_configuration' => array_merge(
            ingredientOntologyV3ScoringConfiguration(),
            ['hash' => ingredientOntologyV3ScoringConfigHash()]
        ),
    ]),
]);
$baselineScoreId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO recipe_inventory_scores (
        score_revision_id, recipe_id, coverage, directness,
        expiry_score, source_user_score, availability_score,
        required_count, matched_required_count,
        missing_required_count, uncertain_required_count,
        cookable
    )
    VALUES (?, ?, 0, 0, 0, 0, 0, 1, 0, 1, 0, 0)
")->execute([$baselineScoreId, $baselineRecipeId]);
$db->prepare("
    INSERT INTO ingredient_ontology_shadow_matches (
        score_revision_id, recipe_ingredient_id, recipe_id,
        outcome, satisfies_required, confidence,
        relationship, explanation_json
    )
    VALUES (?, ?, ?, 'recipe_unmapped', 0, 0, 'none', '{}')
")->execute([
    $baselineScoreId,
    $baselineIngredientId,
    $baselineRecipeId,
]);
$valueHashes = ingredientOntologyV3MaterializedValueHashes(
    $db,
    $baselineScoreId,
    null
);
$db->prepare("
    UPDATE recipe_score_revisions
    SET score_rows_hash = ?,
        match_rows_hash = ?,
        materialization_hash = ?
    WHERE id = ?
")->execute([
    (string)$valueHashes['score_rows_hash'],
    (string)$valueHashes['match_rows_hash'],
    (string)$valueHashes['materialization_hash'],
    $baselineScoreId,
]);
ingredientOntologyV3SetPublicationGuard($db, true);
$db->prepare("
    UPDATE recipe_score_revisions
    SET status = 'ready', completed_at = CURRENT_TIMESTAMP
    WHERE id = ?
")->execute([$baselineScoreId]);
ingredientOntologyV3SetPublicationGuard($db, false);
$db->prepare("
    UPDATE recipe_score_state
    SET ontology_source_hash = ?,
        active_score_revision_id = ?,
        active_score_overlay_revision_id = NULL
    WHERE id = 1
")->execute([$baselineCorpusHash, $baselineScoreId]);
$db->exec('BEGIN IMMEDIATE');
recipeScoreBuildEffectiveProjection($db, $baselineScoreId);
$db->exec('DELETE FROM recipe_score_pending_products');
$db->exec('DELETE FROM recipe_score_pending_recipes');
$db->exec('DELETE FROM recipe_score_mutations');
$db->exec('COMMIT');

$legacyBaselineScoreId = $baselineScoreId;
$sourceRevisionBeforeTriggerUpgrade = (int)recipeScoreState($db)[
    'ontology_source_revision'
];
$db->exec("
    UPDATE recipe_score_state
    SET ontology_source_trigger_version = 31599,
        ontology_source_trigger_hash = ''
    WHERE id = 1
");
migrateDB($db);
$unmigratedScore = recipeScoreActiveRevision($db);
$bootstrapDecision =
    ingredientOntologyV3CorpusProjectionV2DriftDecision(
        $db,
        $unmigratedScore
    );
$assert(
    (int)$unmigratedScore['id'] === $legacyBaselineScoreId
    && $unmigratedScore['corpus_annex_revision_id'] === null
    && !empty($bootstrapDecision['handled'])
    && empty($bootstrapDecision['requires_full_seal'])
    && (string)$bootstrapDecision['reason']
        === 'projection_bootstrap_pending'
    && !ingredientOntologyActivationOntologyStateRequiresBuild($db),
    'A non-special trigger migration must preserve the source fence and '
        . 'leave full projection bootstrap to the '
        . 'background score worker without requesting an ontology build'
);
$assert(
    (int)recipeScoreState($db)['ontology_source_revision']
        === $sourceRevisionBeforeTriggerUpgrade
    && !(bool)$db->query("
        SELECT 1
        FROM recipe_score_mutations
        WHERE domain = 'source'
          AND reason = 'ontology_source_trigger_upgrade'
        LIMIT 1
    ")->fetchColumn(),
    'Trigger implementation upgrades must not invent a global source event'
);
$bootstrap = ingredientOntologyV3IncrementalRebuild($db, true);
$baselineScore = recipeScoreActiveRevision($db);
$bootstrapPin = ingredientOntologyV3CorpusAnnexForScore(
    $db,
    $baselineScore
);
$bootstrapChain = $bootstrapPin !== null
    ? ingredientOntologyV3CorpusAnnexChain(
        $db,
        (int)$bootstrapPin['id']
    )
    : [];
$root = $bootstrapChain[0] ?? null;
$baselineScoreId = (int)$baselineScore['id'];
$assert(
    !empty($bootstrap['rebuilt'])
    && $root !== null
    && $root['parent_revision_id'] === null
    && (int)$root['entry_count'] === 5
    && (int)$root['aggregate_count'] === 2
    && (string)$root['reconciliation_mode'] === 'checkpoint'
    && (int)$root['covered_ontology_source_revision']
        === (int)$baselineScore['ontology_source_revision']
    && (int)recipeScoreState($db)['ontology_source_revision']
        === $sourceRevisionBeforeTriggerUpgrade,
    'A clean active v3 score must publish a complete checkpoint root: '
        . ingredientOntologyV3Json([
            'bootstrap' => $bootstrap,
            'root' => $root,
            'score' => $baselineScore,
        ])
);
$migrationScoreStmt = $db->prepare("
    SELECT id
    FROM recipe_score_revisions
    WHERE parent_score_revision_id = ?
      AND corpus_annex_revision_id = ?
      AND corpus_annex_hash = ?
      AND status = 'ready'
    ORDER BY id
    LIMIT 1
");
$migrationScoreStmt->execute([
    $legacyBaselineScoreId,
    (int)$root['id'],
    (string)$root['revision_hash'],
]);
$migrationScoreId = (int)($migrationScoreStmt->fetchColumn() ?: 0);
$migrationScore = recipeScoreRevision($db, $migrationScoreId);
$assert(
    $migrationScore !== null
    && $migrationScoreId !== $legacyBaselineScoreId
    && (int)$migrationScore['parent_score_revision_id']
        === $legacyBaselineScoreId
    && recipeScoreRevisionIsSparseDelta($migrationScore)
    && (int)$migrationScore['corpus_annex_revision_id']
        === (int)$root['id']
    && hash_equals(
        (string)$migrationScore['corpus_annex_hash'],
        (string)$root['revision_hash']
    )
    && (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_inventory_scores
        WHERE score_revision_id = {$migrationScoreId}
    ")->fetchColumn() === 0,
    'Projection bootstrap must publish an immutable zero-score child '
        . 'pinning the root'
);

$prestockPath = $path . '.prestock-fanout';
$artifacts[] = $prestockPath;
databaseMaintenanceOnlineBackup($path, $prestockPath);
$prestockDb = $open($prestockPath);
ingredientOntologyV3SetReadyMutationGuard($prestockDb, true);
try {
    $prestockDb->prepare("
        INSERT INTO ingredient_ontology_entities (
            ontology_version_id, local_key, slug,
            canonical_name, entity_kind, identity_role,
            provenance
        )
        VALUES (
            ?, 'test:baseline-native', 'baseline-native',
            'Baseline Native', 'ingredient', 'identity_leaf',
            'test_fixture'
        )
    ")->execute([$versionId]);
    $prestockEntityId = (int)$prestockDb->lastInsertId();
    $prestockDb->prepare("
        INSERT INTO ingredient_ontology_labels (
            ontology_version_id, entity_id, language,
            label, normalized_label, kind, review_state,
            provenance, source_ref
        )
        VALUES (
            ?, ?, 'en', 'Baseline Native', 'baseline native',
            'exact_alias', 'accepted', 'test_fixture',
            'prestock-fanout'
        )
    ")->execute([$versionId, $prestockEntityId]);
    $baselineOwner = $prestockDb->prepare("
        SELECT ingredient.*, recipe.language,
               recipe.primary_connector,
               '' AS origin_external_id,
               '' AS origin_locale
        FROM recipe_ingredients ingredient
        JOIN recipe_catalog recipe
          ON recipe.id = ingredient.recipe_id
        WHERE ingredient.id = ?
    ");
    $baselineOwner->execute([$baselineIngredientId]);
    $baselineOwner = $baselineOwner->fetch(PDO::FETCH_ASSOC);
    $prestockDb->prepare("
        INSERT INTO ingredient_ontology_mappings (
            ontology_version_id, owner_type, owner_id,
            owner_fingerprint, source_label, normalized_label,
            language, entity_id, status, confidence,
            mapping_source, evidence_json, attributes_json,
            is_staple
        )
        VALUES (
            ?, 'recipe_ingredient', ?, ?,
            'baseline ingredient', 'baseline ingredient',
            'en', ?, 'accepted', 1,
            'test_fixture', '{}', '{}', 0
        )
    ")->execute([
        $versionId,
        $baselineIngredientId,
        ingredientOntologyV3RecipeOwnerFingerprint(
            'recipe_ingredient',
            $baselineOwner
        ),
        $prestockEntityId,
    ]);
} finally {
    ingredientOntologyV3SetReadyMutationGuard($prestockDb, false);
}
$fixtureSetup = [];
for ($pass = 0; $pass < 10; $pass++) {
    $fixtureSetup[] = ingredientOntologyV3IncrementalRebuild(
        $prestockDb,
        true
    );
    if (
        (int)$prestockDb->query("
            SELECT COUNT(*) FROM recipe_score_pending_recipes
        ")->fetchColumn() === 0
        && (int)$prestockDb->query("
            SELECT COUNT(*)
            FROM recipe_score_identity_projection_work
        ")->fetchColumn() === 0
    ) {
        break;
    }
}
$assert(
    (int)$prestockDb->query("
        SELECT COUNT(*) FROM recipe_score_pending_recipes
    ")->fetchColumn() === 0
    && (int)$prestockDb->query("
        SELECT COUNT(*)
        FROM recipe_score_identity_projection_work
    ")->fetchColumn() === 0,
    'The isolated native recipe mapping must settle before the '
        . 'pre-stock product mutation'
);
$prestockDb->exec("
    INSERT INTO products (name, brand, category, prepared_food)
    VALUES ('Baseline Native', '', 'food', 0)
");
$prestockProductId = (int)$prestockDb->lastInsertId();
$prestockProduct = $prestockDb->prepare("
    SELECT id, name, brand, category, prepared_food
    FROM products
    WHERE id = ?
");
$prestockProduct->execute([$prestockProductId]);
$prestockProduct = $prestockProduct->fetch(PDO::FETCH_ASSOC);
ingredientOntologyV3SetReadyMutationGuard($prestockDb, true);
try {
    $prestockDb->prepare("
        INSERT INTO ingredient_ontology_mappings (
            ontology_version_id, owner_type, owner_id,
            owner_fingerprint, source_label, normalized_label,
            language, entity_id, status, confidence,
            mapping_source, evidence_json, attributes_json,
            is_staple
        )
        VALUES (
            ?, 'product', ?, ?, 'Baseline Native',
            'baseline native', 'en', ?, 'accepted', 1,
            'test_fixture', '{}', '{}', 0
        )
    ")->execute([
        $versionId,
        $prestockProductId,
        ingredientOntologyV3ProductOwnerFingerprint(
            $prestockProduct
        ),
        $prestockEntityId,
    ]);
} finally {
    ingredientOntologyV3SetReadyMutationGuard($prestockDb, false);
}
$runProductFlow = static function (
    PDO $fixtureDb,
    int $productId
): array {
    $result = ingredientOntologyV3IncrementalRebuild(
        $fixtureDb,
        true
    );
    $affected = $fixtureDb->prepare("
        SELECT recipe_id
        FROM recipe_score_incremental_recipes
        WHERE score_revision_id = ?
        ORDER BY recipe_id
    ");
    $affected->execute([(int)($result['revision_id'] ?? 0)]);
    $result['affected_recipe_ids'] = array_map(
        'intval',
        $affected->fetchAll(PDO::FETCH_COLUMN)
    );
    $result['pending_for_product'] =
        (int)$fixtureDb->query("
            SELECT COUNT(*)
            FROM recipe_score_pending_products
            WHERE product_id = {$productId}
        ")->fetchColumn()
        + (int)$fixtureDb->query("
            SELECT COUNT(*)
            FROM recipe_score_product_fanout_state
            WHERE product_id = {$productId}
        ")->fetchColumn();
    return $result;
};
recipeScoreMarkProductDirty(
    $prestockDb,
    $prestockProductId,
    'prestock_fanout_suppression'
);
$prestockFlow = $runProductFlow(
    $prestockDb,
    $prestockProductId
);
$prestockHead =
    ingredientOntologyV3CorpusAnnexEffectiveHead(
        $prestockDb,
        $versionId,
        'product',
        $prestockProductId
    );
$prestockReadiness =
    ingredientOntologyV3ProductReadinessRow(
        $prestockDb,
        $prestockProductId
    );
$assert(
    $prestockFlow['affected_recipe_count'] === 0
    && $prestockFlow['physical_score_rows'] === 0
    && $prestockFlow['pending_for_product'] === 0
    && in_array(
        $prestockProductId,
        $prestockFlow['product_ids'],
        true
    )
    && in_array(
        $prestockProductId,
        $prestockFlow['score_fanout_product_ids'],
        true
    )
    && $prestockHead !== null
    && is_array($prestockReadiness)
    && (string)$prestockReadiness['identity_status'] === 'accepted'
    && (string)$prestockReadiness['status'] === 'ready'
    && (int)$prestockReadiness['affected_recipe_count'] === 0,
    'A projected identity with no current or previous stock contribution '
        . 'must drain without recipe score work: '
        . ingredientOntologyV3Json([
            'flow' => $prestockFlow,
            'head' => $prestockHead,
            'readiness' => $prestockReadiness,
        ])
);
$prestockDb->prepare("
    INSERT INTO inventory (
        product_id, location, quantity, expiry_date,
        expiry_user_set
    )
    VALUES (?, 'dispensa', 1, '2030-01-01', 1)
")->execute([$prestockProductId]);
recipeScoreMarkProductDirty(
    $prestockDb,
    $prestockProductId,
    'prestock_first_inventory'
);
$firstStockFlow = $runProductFlow(
    $prestockDb,
    $prestockProductId
);
$stockAvailability = $prestockDb->prepare("
    SELECT score.availability_score,
           score.matched_required_count,
           score.cookable
    FROM recipe_score_effective_sources source
    JOIN recipe_inventory_scores score
      ON score.score_revision_id = source.score_revision_id
     AND score.recipe_id = source.recipe_id
    WHERE source.recipe_id = ?
");
$stockAvailability->execute([$baselineRecipeId]);
$stockScore = $stockAvailability->fetch(PDO::FETCH_ASSOC);
$assert(
    $firstStockFlow['affected_recipe_ids'] === [$baselineRecipeId]
    && $firstStockFlow['affected_recipe_count'] === 1
    && $firstStockFlow['physical_score_rows'] === 1
    && $firstStockFlow['pending_for_product'] === 0
    && is_array($stockScore)
    && (float)$stockScore['availability_score'] > 0
    && (int)$stockScore['matched_required_count'] === 1
    && (int)$stockScore['cookable'] === 1,
    'The first positive inventory must restore the exact bounded '
        . 'dependency closure: '
        . ingredientOntologyV3Json([
            'flow' => $firstStockFlow,
            'score' => $stockScore,
        ])
);
$prestockDb->prepare("
    UPDATE inventory
    SET quantity = 0, updated_at = CURRENT_TIMESTAMP
    WHERE product_id = ?
")->execute([$prestockProductId]);
recipeScoreMarkProductDirty(
    $prestockDb,
    $prestockProductId,
    'prestock_inventory_removed'
);
$removedStockFlow = $runProductFlow(
    $prestockDb,
    $prestockProductId
);
$removedAvailability = $prestockDb->prepare("
    SELECT score.availability_score,
           score.matched_required_count,
           score.cookable
    FROM recipe_score_effective_sources source
    JOIN recipe_inventory_scores score
      ON score.score_revision_id = source.score_revision_id
     AND score.recipe_id = source.recipe_id
    WHERE source.recipe_id = ?
");
$removedAvailability->execute([$baselineRecipeId]);
$removedScore = $removedAvailability->fetch(PDO::FETCH_ASSOC);
$assert(
    $removedStockFlow['affected_recipe_ids']
        === [$baselineRecipeId]
    && $removedStockFlow['affected_recipe_count'] === 1
    && $removedStockFlow['physical_score_rows'] === 1
    && $removedStockFlow['pending_for_product'] === 0
    && is_array($removedScore)
    && (float)$removedScore['availability_score']
        < (float)$stockScore['availability_score']
    && (int)$removedScore['matched_required_count'] === 0
    && (int)$removedScore['cookable'] === 0,
    'Removing the last stock must retain previous-contributor invalidation: '
        . ingredientOntologyV3Json([
            'flow' => $removedStockFlow,
            'score' => $removedScore,
        ])
);
$prestockDb = null;

$resolverUpgradePath = $path . '.resolver-upgrade';
$artifacts[] = $resolverUpgradePath;
databaseMaintenanceOnlineBackup($path, $resolverUpgradePath);
$resolverUpgradeDb = $open($resolverUpgradePath);
$resolverUpgradeBeforeState = recipeScoreState($resolverUpgradeDb);
$resolverUpgradeBeforeScore =
    recipeScoreActiveRevision($resolverUpgradeDb);
$resolverUpgradeBeforePin =
    ingredientOntologyV3CorpusAnnexForScore(
        $resolverUpgradeDb,
        $resolverUpgradeBeforeScore
    );
$resolverUpgradeAdmission =
    ingredientOntologyV3IdentityAdmissionState($resolverUpgradeDb);
$resolverUpgradeManifest = json_decode(
    (string)$resolverUpgradeAdmission['manifest_json'],
    true
);
$resolverUpgradeManifest = is_array($resolverUpgradeManifest)
    ? $resolverUpgradeManifest
    : [];
$resolverUpgradeManifest['recipe_resolver_version'] =
    'identity-annex-r0-v3:exact-on:roles-off';
$resolverUpgradeDb->prepare("
    UPDATE ingredient_ontology_identity_admission_state
    SET manifest_json = ?,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = 1
")->execute([
    ingredientOntologyV3Json($resolverUpgradeManifest),
]);
$resolverUpgradeDb->exec("
    UPDATE ingredient_ontology_recipe_identity_annex
    SET resolver_version = 'identity-annex-r0-v3:exact-on:roles-off'
");
do {
    $resolverUpgradeSync =
        ingredientOntologyV3IdentityAdmissionSync($resolverUpgradeDb);
    $resolverUpgradeRemaining = max(
        (int)($resolverUpgradeSync[
            'resolver_migration'
        ]['remaining'] ?? 0),
        (int)($resolverUpgradeSync[
            'recipe_resolver_migration'
        ]['remaining'] ?? 0)
    );
} while ($resolverUpgradeRemaining > 0);
$resolverUpgradeAfterState = recipeScoreState($resolverUpgradeDb);
$resolverUpgradeMutation = $resolverUpgradeDb->query("
    SELECT id, revision
    FROM recipe_score_mutations
    WHERE domain = 'catalog'
      AND reason = 'recipe_identity_resolver_migration_batch'
    ORDER BY id DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC) ?: [];
$resolverUpgradeScopeCount = $resolverUpgradeMutation
    ? (int)$resolverUpgradeDb->query("
        SELECT COUNT(*)
        FROM recipe_score_mutation_scopes
        WHERE mutation_id = "
            . (int)$resolverUpgradeMutation['id']
    )->fetchColumn()
    : 0;
$resolverUpgradeBuild =
    ingredientOntologyV3IncrementalRebuild(
        $resolverUpgradeDb,
        true
    );
$resolverUpgradeAfterScore =
    recipeScoreActiveRevision($resolverUpgradeDb);
$resolverUpgradeAfterPin =
    ingredientOntologyV3CorpusAnnexForScore(
        $resolverUpgradeDb,
        $resolverUpgradeAfterScore
    );
$assert(
    !empty($resolverUpgradeSync['recipe_resolver_changed'])
    && (int)$resolverUpgradeAfterState['ontology_source_revision']
        === (int)$resolverUpgradeBeforeState[
            'ontology_source_revision'
        ]
    && !(bool)$resolverUpgradeDb->query("
        SELECT 1
        FROM recipe_score_mutations
        WHERE domain = 'source'
          AND owner_type = 'global'
          AND reason = 'recipe_identity_resolver_changed'
        LIMIT 1
    ")->fetchColumn()
    && !(bool)$resolverUpgradeDb->query("
        SELECT 1
        FROM recipe_score_source_reconciliation_events
        WHERE event_owner_type = 'global'
          AND event_reason = 'recipe_identity_resolver_changed'
        LIMIT 1
    ")->fetchColumn()
    && $resolverUpgradeScopeCount === 1
    && !empty($resolverUpgradeBuild['rebuilt'])
    && $resolverUpgradeBeforePin !== null
    && $resolverUpgradeAfterPin !== null
    && (int)$resolverUpgradeAfterPin['parent_revision_id']
        === (int)$resolverUpgradeBeforePin['id']
    && (string)$resolverUpgradeAfterPin['reconciliation_mode']
        !== 'authoritative'
    && (int)$resolverUpgradeAfterPin['aggregate_count'] === 1,
    'Recipe resolver upgrades must preserve the source fence and publish '
        . 'only scoped maintenance work: '
        . ingredientOntologyV3Json([
            'sync' => $resolverUpgradeSync,
            'before_state' => $resolverUpgradeBeforeState,
            'after_state' => $resolverUpgradeAfterState,
            'catalog_mutation' => $resolverUpgradeMutation,
            'scope_count' => $resolverUpgradeScopeCount,
            'build' => $resolverUpgradeBuild,
            'before_pin' => $resolverUpgradeBeforePin,
            'after_pin' => $resolverUpgradeAfterPin,
        ])
);
$resolverUpgradeDb = null;

$rootMaxima = [
    'products' => (int)$root['base_products_max_id'],
    'recipe_catalog' =>
        (int)$root['base_recipe_catalog_max_id'],
    'recipe_origins' =>
        (int)$root['base_recipe_origins_max_id'],
    'recipe_ingredients' =>
        (int)$root['base_recipe_ingredients_max_id'],
    'recipe_source_ingredients' =>
        (int)$root['base_recipe_source_ingredients_max_id'],
];
$ontologyCountBefore = (int)$db->query("
    SELECT COUNT(*) FROM ingredient_ontology_versions
")->fetchColumn();
$importCountBefore = (int)$db->query("
    SELECT COUNT(*) FROM ontology_activation_imports
")->fetchColumn();
$intentCountBefore = (int)$db->query("
    SELECT COUNT(*) FROM ontology_generation_intents
")->fetchColumn();

$db->exec("
    INSERT INTO products (name, brand, category)
    VALUES ('Kabocha', '', 'produce')
");
$kabochaProductId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO inventory (product_id, location, quantity)
    VALUES (?, 'dispensa', 1)
")->execute([$kabochaProductId]);
recipeScoreMarkProductDirty(
    $db,
    $kabochaProductId,
    'corpus_annex_kabocha'
);

$saved = recipeCatalogSaveVariant($db, [
    'title' => 'Kabocha Annex Soup',
    'language' => 'en',
    'ingredients' => [[
        'name' => 'Kabocha',
        'is_required' => true,
    ], [
        'name' => 'Vegetable stock',
        'is_required' => true,
    ], [
        'name' => 'Onion',
        'is_required' => true,
    ], [
        'name' => 'Olive oil',
        'is_required' => true,
    ], [
        'name' => 'Salt',
        'is_required' => true,
    ], [
        'name' => 'Pepper',
        'is_required' => true,
    ], [
        'name' => 'Water',
        'is_required' => true,
    ]],
    'steps' => ['Simmer.'],
], [
    'connector' => 'manual',
    'external_id' => 'corpus-annex-kabocha',
]);
$kabochaRecipeId = (int)$saved['id'];
$db->prepare("
    INSERT INTO recipe_ingredients (
        recipe_id, position, raw_text, normalized_name,
        source_is_required, source_is_optional,
        requiredness_source
    )
    VALUES (?, 7, '', '', 1, 0, 'explicit_required')
")->execute([$kabochaRecipeId]);
$unresolvedIngredientId = (int)$db->lastInsertId();
$sourceInsert = $db->prepare("
    INSERT INTO recipe_source_ingredients (
        id, recipe_id, position, name, normalized_name,
        source_optional
    )
    VALUES (?, ?, ?, ?, ?, ?)
");
$sourceNames = [
    'Kabocha',
    'Vegetable stock',
    'Onion',
    'Olive oil',
    'Salt',
    'Pepper',
    'Water',
    '',
];
foreach ($sourceNames as $position => $sourceName) {
    $sourceInsert->execute([
        167995 + $position,
        $kabochaRecipeId,
        $position,
        $sourceName,
        strtolower($sourceName),
        0,
    ]);
}

$result = ingredientOntologyV3IncrementalRebuild($db, true);
$assert(
    !empty($result['rebuilt'])
    && (int)$result['affected_recipe_count'] === 1
    && (int)$result['physical_score_rows'] === 1
    && (int)$result['recipe_ids'][0] === $kabochaRecipeId,
    'A pure new-product/new-recipe closure must publish one sparse score: '
        . ingredientOntologyV3Json($result)
);
$active = recipeScoreActiveRevision($db);
$annex = ingredientOntologyV3CorpusAnnexForScore($db, $active);
$assert(
    $annex !== null
    && (int)$annex['id'] !== (int)$root['id']
    && (int)$annex['parent_revision_id']
        === (int)$bootstrapPin['id']
    && hash_equals(
        (string)$annex['revision_hash'],
        (string)$active['corpus_annex_hash']
    )
    && (int)$active['covered_ontology_source_revision']
        === (int)$annex['covered_ontology_source_revision'],
    'The active score must pin the exact published annex prefix'
);
$entries = $db->prepare("
    SELECT entry_type, operation, owner_id, recipe_id, identity_status,
           satisfies_required, row_hash
    FROM ingredient_ontology_corpus_annex_entries
    WHERE corpus_annex_revision_id = ?
    ORDER BY ordinal
");
$entries->execute([(int)$annex['id']]);
$entries = $entries->fetchAll(PDO::FETCH_ASSOC);
$types = array_count_values(array_column($entries, 'entry_type'));
$unresolved = array_values(array_filter(
    $entries,
    static fn(array $entry): bool =>
        (string)$entry['entry_type'] === 'recipe_ingredient'
        && (int)$entry['owner_id'] === $unresolvedIngredientId
));
$assert(
    array_values(array_unique(array_column($entries, 'operation')))
        === ['replace']
    && ($types['product'] ?? 0) === 1
    && ($types['recipe_scope'] ?? 0) === 1
    && ($types['recipe_origin'] ?? 0) === 1
    && ($types['recipe_ingredient'] ?? 0) === 8
    && ($types['recipe_source_ingredient'] ?? 0) === 8
    && count($entries) === (int)$annex['entry_count'],
    'The annex must contain the complete deterministic recipe closure'
);
$assert(
    count($unresolved) === 1
    && (string)$unresolved[0]['identity_status'] === 'unresolved'
    && (int)$unresolved[0]['satisfies_required'] === 0,
    'Unresolved identities must remain explicit and non-satisfying'
);
$assert(
    $kabochaProductId === 236
    && $kabochaRecipeId === 34815
    && $unresolvedIngredientId === 414842
    && $kabochaProductId > $rootMaxima['products']
    && $kabochaRecipeId > $rootMaxima['recipe_catalog']
    && (int)$db->query("
        SELECT MIN(id) FROM recipe_origins
        WHERE recipe_id = {$kabochaRecipeId}
    ")->fetchColumn() > $rootMaxima['recipe_origins']
    && (int)$db->query("
        SELECT MIN(id) FROM recipe_ingredients
        WHERE recipe_id = {$kabochaRecipeId}
    ")->fetchColumn() > $rootMaxima['recipe_ingredients']
    && (int)$db->query("
        SELECT MIN(id) FROM recipe_source_ingredients
        WHERE recipe_id = {$kabochaRecipeId}
    ")->fetchColumn()
        > $rootMaxima['recipe_source_ingredients'],
    'Newly discovered owners must exercise post-checkpoint IDs '
        . 'across every source table'
);
$audit = ingredientOntologyV3CorpusAnnexIntegrityAudit(
    $db,
    (int)$annex['id'],
    (string)$annex['revision_hash'],
    true
);
$replay = ingredientOntologyV3CorpusAnnexIntegrityAudit(
    $db,
    (int)$annex['id'],
    (string)$annex['revision_hash'],
    true
);
$assert(
    !empty($audit['valid'])
    && $audit === $replay
    && hash_equals(
        ingredientOntologyV3CorpusAnnexRevisionHash($annex),
        (string)$annex['revision_hash']
    ),
    'Corpus annex integrity replay must be deterministic: '
        . ingredientOntologyV3Json([
            'audit' => $audit,
            'replay' => $replay,
            'annex' => $annex,
        ])
);
$decision = ingredientOntologyV3CorpusProjectionV2DriftDecision(
    $db,
    $active
);
$assert(
    !empty($decision['handled'])
    && empty($decision['requires_full_seal'])
    && !ingredientOntologyActivationOntologyStateRequiresBuild(
        $db,
        $active
    )
    && !ingredientOntologyActivationNeedsReviewedManifestRefresh($db)
    && (int)$db->query("
        SELECT COUNT(*) FROM ingredient_ontology_versions
    ")->fetchColumn() === $ontologyCountBefore
    && (int)$db->query("
        SELECT COUNT(*) FROM ontology_activation_imports
    ")->fetchColumn() === $importCountBefore
    && (int)$db->query("
        SELECT COUNT(*) FROM ontology_generation_intents
    ")->fetchColumn() === $intentCountBefore,
    'A valid annex must explain corpus drift without an ontology build: '
        . ingredientOntologyV3Json([
            'decision' => $decision,
            'annex' => $annex,
            'current_corpus_hash' =>
                ingredientOntologyV3CorpusHash($db),
            'requires_build' =>
                ingredientOntologyActivationOntologyStateRequiresBuild(
                    $db,
                    $active
                ),
        ])
);

$updatePath = $path . '.existing-product-update';
$artifacts[] = $updatePath;
databaseMaintenanceOnlineBackup($path, $updatePath);
$updateDb = $open($updatePath);
$updateDb->prepare("
    UPDATE products
    SET category = 'winter squash'
    WHERE id = ?
")->execute([$kabochaProductId]);
$productUpdate = ingredientOntologyV3IncrementalRebuild(
    $updateDb,
    true
);
$assert(
    !empty($productUpdate['rebuilt'])
    && (array)$productUpdate['product_ids'] === [$kabochaProductId],
    'An existing product update must publish a selective replacement: '
        . ingredientOntologyV3Json($productUpdate)
);
$updateDb = null;

do {
    $identityBeforeOverlay =
        ingredientOntologyV3IdentityAdmissionSync($db);
    $identityBeforeOverlayRemaining = max(
        (int)($identityBeforeOverlay[
            'resolver_migration'
        ]['remaining'] ?? 0),
        (int)($identityBeforeOverlay[
            'recipe_resolver_migration'
        ]['remaining'] ?? 0)
    );
} while ($identityBeforeOverlayRemaining > 0);
for ($settle = 0; $settle < 10; $settle++) {
    $pendingBeforeOverlay = (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_pending_recipes
    ")->fetchColumn();
    if ($pendingBeforeOverlay === 0) {
        break;
    }
    $settledBeforeOverlay =
        ingredientOntologyV3IncrementalRebuild($db, true);
    if (empty($settledBeforeOverlay['rebuilt'])) {
        throw new RuntimeException(
            'Could not settle preexisting recipe work before the '
                . 'product-overlay isolation check: '
                . ingredientOntologyV3Json($settledBeforeOverlay)
        );
    }
}
$productAggregateBeforeOverlay = (string)$db->query("
    SELECT aggregate_hash
    FROM ingredient_ontology_corpus_annex_effective_aggregates
    WHERE ontology_version_id = {$versionId}
      AND aggregate_type = 'product'
      AND aggregate_id = {$kabochaProductId}
")->fetchColumn();
$baselineRecipeHashBeforeProductOverlay = (string)$db->query("
    SELECT aggregate_hash
    FROM ingredient_ontology_corpus_annex_effective_aggregates
    WHERE ontology_version_id = {$versionId}
      AND aggregate_type = 'recipe'
      AND aggregate_id = {$baselineRecipeId}
")->fetchColumn();
$db->exec("
    INSERT INTO canonical_ingredients (
        slug, name, category, source
    )
    VALUES (
        'kabocha-projection', 'Kabocha Projection',
        'produce', 'test'
    )
");
$canonicalId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO product_ingredients (
        product_id, ingredient_id, role, confidence, source
    )
    VALUES (?, ?, 'primary', 1, 'test')
")->execute([$kabochaProductId, $canonicalId]);
$canonicalInsert = ingredientOntologyV3IncrementalRebuild($db, true);
$canonicalReport = recipeScoreRevisionReport(
    recipeScoreActiveRevision($db)
);
$productAggregateAfterOverlay = (string)$db->query("
    SELECT aggregate_hash
    FROM ingredient_ontology_corpus_annex_effective_aggregates
    WHERE ontology_version_id = {$versionId}
      AND aggregate_type = 'product'
      AND aggregate_id = {$kabochaProductId}
")->fetchColumn();
$assert(
    !empty($canonicalInsert['rebuilt'])
    && in_array(
        $kabochaProductId,
        (array)$canonicalInsert['product_ids'],
        true
    )
    && (int)$canonicalInsert['affected_recipe_count'] <= 2
    && (string)($canonicalReport['corpus_annex'][
        'reconciliation_mode'
    ] ?? '') === 'journal'
    && !hash_equals(
        $productAggregateBeforeOverlay,
        $productAggregateAfterOverlay
    ),
    'Canonical and product-ingredient overlay inserts must reconcile '
        . 'only dependent aggregates: '
        . ingredientOntologyV3Json([
            'result' => $canonicalInsert,
            'product_payload' =>
                ingredientOntologyV3CorpusAnnexProductPayload(
                    $db,
                    $kabochaProductId,
                    $versionId
                ),
            'product_head' =>
                ingredientOntologyV3CorpusAnnexEffectiveHead(
                    $db,
                    $versionId,
                    'product',
                    $kabochaProductId
                ),
            'mutations' => $db->query("
                SELECT mutation.revision, mutation.owner_type,
                       mutation.owner_id, mutation.source_table,
                       mutation.source_row_id,
                       scope.aggregate_type, scope.aggregate_id
                FROM recipe_score_mutations mutation
                LEFT JOIN recipe_score_mutation_scopes scope
                  ON scope.mutation_id = mutation.id
                WHERE mutation.domain = 'source'
                ORDER BY mutation.revision, scope.ordinal
            ")->fetchAll(PDO::FETCH_ASSOC),
        ])
);
$assert(
    hash_equals(
        $baselineRecipeHashBeforeProductOverlay,
        (string)$db->query("
            SELECT aggregate_hash
            FROM ingredient_ontology_corpus_annex_effective_aggregates
            WHERE ontology_version_id = {$versionId}
              AND aggregate_type = 'recipe'
              AND aggregate_id = {$baselineRecipeId}
        ")->fetchColumn()
    ),
    'A product-local overlay change must not rewrite unrelated recipes: '
        . ingredientOntologyV3Json([
            'before' => $baselineRecipeHashBeforeProductOverlay,
            'after' => (string)$db->query("
                SELECT aggregate_hash
                FROM ingredient_ontology_corpus_annex_effective_aggregates
                WHERE ontology_version_id = {$versionId}
                  AND aggregate_type = 'recipe'
                  AND aggregate_id = {$baselineRecipeId}
            ")->fetchColumn(),
            'result' => $canonicalInsert,
        ])
);
$db->prepare("
    UPDATE canonical_ingredients
    SET name = 'Kabocha Projection Updated'
    WHERE id = ?
")->execute([$canonicalId]);
$canonicalUpdate = ingredientOntologyV3IncrementalRebuild($db, true);
$productAggregateAfterCanonicalUpdate = (string)$db->query("
    SELECT aggregate_hash
    FROM ingredient_ontology_corpus_annex_effective_aggregates
    WHERE ontology_version_id = {$versionId}
      AND aggregate_type = 'product'
      AND aggregate_id = {$kabochaProductId}
")->fetchColumn();
$assert(
    !empty($canonicalUpdate['rebuilt'])
    && (array)$canonicalUpdate['product_ids']
        === [$kabochaProductId]
    && (array)$canonicalUpdate['score_fanout_product_ids']
        === []
    && !hash_equals(
        $productAggregateAfterOverlay,
        $productAggregateAfterCanonicalUpdate
    ),
    'Canonical target updates must remain selectively scoped: '
        . ingredientOntologyV3Json($canonicalUpdate)
);
$boundedDependencyPath = $path . '.bounded-dependencies';
$artifacts[] = $boundedDependencyPath;
databaseMaintenanceOnlineBackup($path, $boundedDependencyPath);
$boundedDependencyDb = $open($boundedDependencyPath);
$boundedDependencyDb->prepare("
    INSERT INTO product_ingredients (
        product_id, ingredient_id, role, confidence, source
    )
    VALUES (?, ?, 'primary', 1, 'test')
")->execute([235, $canonicalId]);
$boundedDependencyDb->prepare("
    UPDATE products
    SET name = 'bounded dependency alias'
    WHERE id IN (?, ?)
")->execute([235, $kabochaProductId]);
$boundedCanonical =
    ingredientOntologyV3CorpusAnnexCanonicalDependencyScopes(
        $boundedDependencyDb,
        [$canonicalId],
        $versionId,
        1
    );
$boundedAlias =
    ingredientOntologyV3CorpusAnnexAliasDependencyScopes(
        $boundedDependencyDb,
        ['bounded dependency alias'],
        $versionId,
        1
    );
$assert(
    !empty($boundedCanonical['has_more'])
    && count($boundedCanonical['product'])
        + count($boundedCanonical['recipe']) === 1
    && !empty($boundedAlias['has_more'])
    && count($boundedAlias['product'])
        + count($boundedAlias['recipe']) === 1,
    'High-fan-out dependency discovery must stop at its bounded '
        . 'limit and request authoritative reconciliation'
);
$malformedAliasScopes =
    ingredientOntologyV3CorpusAnnexEventScopes(
        $boundedDependencyDb,
        [[
            'owner_type' => 'global',
            'owner_id' => null,
            'reason' => 'taxonomy_aliases',
            'scopes' => [[
                'aggregate_type' => 'overlay',
                'aggregate_id' => 1,
                'source_table' => 'taxonomy_aliases',
                'source_key' => '',
                'metadata_json' => '{}',
            ]],
        ]],
        $versionId
    );
$assert(
    !empty($malformedAliasScopes['authoritative']),
    'Incomplete alias scope metadata must force authoritative '
        . 'reconciliation instead of silently dropping dependencies'
);
$boundedDependencyDb = null;
$productIngredientId = (int)$db->query("
    SELECT id
    FROM product_ingredients
    WHERE product_id = {$kabochaProductId}
      AND ingredient_id = {$canonicalId}
")->fetchColumn();
$db->prepare("
    UPDATE product_ingredients
    SET role = 'broader'
    WHERE id = ?
")->execute([$productIngredientId]);
$productRelationUpdate =
    ingredientOntologyV3IncrementalRebuild($db, true);
$db->prepare("
    UPDATE product_ingredients
    SET product_id = 235
    WHERE id = ?
")->execute([$productIngredientId]);
$productRelationReparent =
    ingredientOntologyV3IncrementalRebuild($db, true);
$reparentedProductIds = array_map(
    'intval',
    (array)$productRelationReparent['product_ids']
);
sort($reparentedProductIds, SORT_NUMERIC);
$db->prepare("
    DELETE FROM product_ingredients
    WHERE id = ?
")->execute([$productIngredientId]);
$productRelationDelete =
    ingredientOntologyV3IncrementalRebuild($db, true);
$assert(
    !empty($productRelationUpdate['rebuilt'])
    && !empty($productRelationReparent['rebuilt'])
    && !empty($productRelationDelete['rebuilt'])
    && (array)$productRelationUpdate['product_ids']
        === [$kabochaProductId]
    && $reparentedProductIds === [235, $kabochaProductId]
    && (array)$productRelationDelete['product_ids']
        === [235],
    'Product-ingredient update, re-parent, and delete must remain '
        . 'selectively scoped to old and new aggregates'
);

$kabochaOriginId = (int)$db->query("
    SELECT id
    FROM recipe_origins
    WHERE recipe_id = {$kabochaRecipeId}
    ORDER BY id
    LIMIT 1
")->fetchColumn();
$recipeAggregateBeforeLanguage = (string)$db->query("
    SELECT aggregate_hash
    FROM ingredient_ontology_corpus_annex_effective_aggregates
    WHERE ontology_version_id = {$versionId}
      AND aggregate_type = 'recipe'
      AND aggregate_id = {$kabochaRecipeId}
")->fetchColumn();
$db->prepare("
    UPDATE recipe_origins
    SET content_language = 'it'
    WHERE id = ?
")->execute([$kabochaOriginId]);
$originLanguageUpdate =
    ingredientOntologyV3IncrementalRebuild($db, true);
$recipeAggregateAfterLanguage = (string)$db->query("
    SELECT aggregate_hash
    FROM ingredient_ontology_corpus_annex_effective_aggregates
    WHERE ontology_version_id = {$versionId}
      AND aggregate_type = 'recipe'
      AND aggregate_id = {$kabochaRecipeId}
")->fetchColumn();
$assert(
    !empty($originLanguageUpdate['rebuilt'])
    && (array)$originLanguageUpdate['recipe_ids']
        === [$kabochaRecipeId]
    && !hash_equals(
        $recipeAggregateBeforeLanguage,
        $recipeAggregateAfterLanguage
    )
    && !ingredientOntologyActivationOntologyStateRequiresBuild($db),
    'Origin content-language changes must selectively replace their '
        . 'complete recipe aggregate'
);

$rawAliasLabel = 'Raw & Identity/Alias';
$db->prepare("
    UPDATE recipe_ingredients
    SET raw_text = ?, normalized_name = 'baseline ingredient'
    WHERE id = ?
")->execute([$rawAliasLabel, $baselineIngredientId]);
$rawAliasSourceUpdate =
    ingredientOntologyV3IncrementalRebuild($db, true);
$rawAliasLookup = $db->prepare("
    SELECT normalized_source_label
    FROM ingredient_ontology_corpus_annex_effective_members
    WHERE ontology_version_id = ?
      AND aggregate_type = 'recipe'
      AND aggregate_id = ?
      AND entry_type = 'recipe_ingredient'
      AND owner_id = ?
");
$rawAliasLookup->execute([
    $versionId,
    $baselineRecipeId,
    $baselineIngredientId,
]);
$assert(
    !empty($rawAliasSourceUpdate['rebuilt'])
    && (string)$rawAliasLookup->fetchColumn()
        === ingredientOntologyV3NormalizeLabel($rawAliasLabel),
    'The reverse alias index must store normalized source-label '
        . 'semantics rather than normalized_name'
);

$db->exec("
    INSERT INTO taxonomy_trees (slug, name)
    VALUES ('projection-test', 'Projection Test')
");
$treeId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO taxonomy_nodes (tree_id, slug, name)
    VALUES (?, 'baseline-ingredient', 'Baseline Ingredient')
")->execute([$treeId]);
$nodeId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO taxonomy_aliases (
        tree_id, node_id, alias, normalized_alias,
        source, active
    )
    VALUES (
        ?, ?, ?, ?,
        'gemini_test', 1
    )
")->execute([
    $treeId,
    $nodeId,
    $rawAliasLabel,
    ingredientOntologyV3NormalizeLabel($rawAliasLabel),
]);
$aliasId = (int)$db->lastInsertId();
$aliasUpdate = ingredientOntologyV3IncrementalRebuild($db, true);
$aliasReport = recipeScoreRevisionReport(
    recipeScoreActiveRevision($db)
);
$assert(
    !empty($aliasUpdate['rebuilt'])
    && (array)$aliasUpdate['recipe_ids'] === [$baselineRecipeId]
    && (string)($aliasReport['corpus_annex'][
        'reconciliation_mode'
    ] ?? '') === 'journal',
    'Active Gemini aliases must discover raw-text dependencies even '
        . 'when normalized_name differs: '
        . ingredientOntologyV3Json([
            'result' => $aliasUpdate,
            'report' => $aliasReport,
        ])
);
$db->prepare("
    UPDATE taxonomy_aliases
    SET active = 0
    WHERE id = ?
")->execute([$aliasId]);
$aliasDeactivate = ingredientOntologyV3IncrementalRebuild($db, true);
$db->prepare("
    DELETE FROM taxonomy_aliases
    WHERE id = ?
")->execute([$aliasId]);
$aliasDelete = ingredientOntologyV3IncrementalRebuild($db, true);
$aliasDeleteReport = recipeScoreRevisionReport(
    recipeScoreActiveRevision($db)
);
$assert(
    !empty($aliasDeactivate['rebuilt'])
    && (array)$aliasDeactivate['recipe_ids']
        === [$baselineRecipeId]
    && !empty($aliasDelete['rebuilt'])
    && (int)$aliasDelete['physical_score_rows'] === 0
    && (int)($aliasDeleteReport['corpus_annex'][
        'revision_aggregate_count'
    ] ?? -1) === 0,
    'Taxonomy alias deactivation must replace dependents and deleting '
        . 'an already inactive alias must advance lineage as a no-op'
);

$second = recipeCatalogSaveVariant($db, [
    'title' => 'Projection Reparent Target',
    'language' => 'en',
    'ingredients' => [[
        'name' => 'water',
        'is_required' => true,
    ]],
    'steps' => ['Mix.'],
], [
    'connector' => 'manual',
    'external_id' => 'projection-reparent-target',
]);
$secondRecipeId = (int)$second['id'];
$secondInsert = ingredientOntologyV3IncrementalRebuild($db, true);
$assert(
    !empty($secondInsert['rebuilt']),
    'The re-parent target must first be published'
);
$moveIngredientId = (int)$db->query("
    SELECT id
    FROM recipe_ingredients
    WHERE recipe_id = {$kabochaRecipeId}
    ORDER BY id DESC
    LIMIT 1
")->fetchColumn();
$moveOriginId = (int)$db->query("
    SELECT id
    FROM recipe_origins
    WHERE recipe_id = {$kabochaRecipeId}
    ORDER BY id
    LIMIT 1
")->fetchColumn();
$beforeReparentRevision = (int)recipeScoreState($db)[
    'ontology_source_revision'
];
$db->prepare("
    UPDATE recipe_ingredients
    SET recipe_id = ?
    WHERE id = ?
")->execute([$secondRecipeId, $moveIngredientId]);
$db->prepare("
    UPDATE recipe_origins
    SET recipe_id = ?
    WHERE id = ?
")->execute([$secondRecipeId, $moveOriginId]);
$scopeQuery = $db->prepare("
    SELECT DISTINCT scope.aggregate_id
    FROM recipe_score_mutations mutation
    JOIN recipe_score_mutation_scopes scope
      ON scope.mutation_id = mutation.id
    WHERE mutation.domain = 'source'
      AND mutation.revision > ?
      AND scope.aggregate_type = 'recipe'
    ORDER BY scope.aggregate_id
");
$scopeQuery->execute([$beforeReparentRevision]);
$reparentScopeIds = array_map(
    'intval',
    $scopeQuery->fetchAll(PDO::FETCH_COLUMN)
);
$reparent = ingredientOntologyV3IncrementalRebuild($db, true);
$reparentRecipeIds = array_map(
    'intval',
    (array)$reparent['recipe_ids']
);
sort($reparentRecipeIds, SORT_NUMERIC);
$expectedReparentIds = [$kabochaRecipeId, $secondRecipeId];
sort($expectedReparentIds, SORT_NUMERIC);
$assert(
    $reparentScopeIds === $expectedReparentIds
    && !empty($reparent['rebuilt'])
    && $reparentRecipeIds === $expectedReparentIds,
    'Origin and ingredient re-parenting must replace both old and '
        . 'new recipe aggregates: '
        . ingredientOntologyV3Json([
            'scopes' => $reparentScopeIds,
            'result' => $reparent,
        ])
);
$cacheMutationBlocked = false;
try {
    $db->prepare("
        UPDATE ingredient_ontology_corpus_annex_effective_members
        SET attributes_json = '{}'
        WHERE ontology_version_id = ?
          AND owner_type = 'recipe_ingredient'
          AND owner_id = ?
    ")->execute([$versionId, $moveIngredientId]);
} catch (PDOException $error) {
    $cacheMutationBlocked = str_contains(
        $error->getMessage(),
        'corpus projection cache mutation requires a guard'
    );
}
$assert(
    $cacheMutationBlocked,
    'Derived projection state must reject unguarded mutation'
);
ingredientOntologyV3WithReadyMutationGuard(
    $db,
    static function () use (
        $db,
        $versionId,
        $moveIngredientId,
        $secondRecipeId
    ): void {
        $db->prepare("
            UPDATE ingredient_ontology_corpus_annex_effective_members
            SET attributes_json = '{\"stale\":\"attribute\"}',
                relations_json =
                    '[{\"to_entity_slug\":\"stale-target\"}]'
            WHERE ontology_version_id = ?
              AND owner_type = 'recipe_ingredient'
              AND owner_id = ?
        ")->execute([$versionId, $moveIngredientId]);
        $db->prepare("
            INSERT OR IGNORE INTO
                ingredient_ontology_corpus_annex_effective_entities (
                ontology_version_id, entity_key,
                aggregate_type, aggregate_id,
                owner_type, owner_id, head_revision_id
            )
            VALUES (
                ?, 'native:stale-target', 'recipe', ?,
                'recipe_ingredient', ?, ?
            )
        ")->execute([
            $versionId,
            $secondRecipeId,
            $moveIngredientId,
            (int)recipeScoreActiveRevision($db)[
                'corpus_annex_revision_id'
            ],
        ]);
    }
);
$corruptCachePin = ingredientOntologyV3CorpusAnnexForScore(
    $db,
    recipeScoreActiveRevision($db)
);
$corruptCacheAudit =
    ingredientOntologyV3CorpusProjectionV2IntegrityAudit(
        $db,
        (int)$corruptCachePin['id'],
        (string)$corruptCachePin['revision_hash'],
        true
    );
$assert(
    empty($corruptCacheAudit['valid'])
    && in_array(
        'active corpus projection materialization is stale',
        (array)$corruptCacheAudit['errors'],
        true
    ),
    'The deep audit must detect derived projection corruption'
);
$db->prepare("
    DELETE FROM recipe_ingredients
    WHERE id = ?
")->execute([$moveIngredientId]);
$removal = ingredientOntologyV3IncrementalRebuild($db, true);
$removedMember = $db->prepare("
    SELECT COUNT(*)
    FROM ingredient_ontology_corpus_annex_effective_members
    WHERE ontology_version_id = ?
      AND owner_type = 'recipe_ingredient'
      AND owner_id = ?
");
$removedMember->execute([$versionId, $moveIngredientId]);
$removedReverse = $db->prepare("
    SELECT COUNT(*)
    FROM ingredient_ontology_corpus_annex_effective_entities
    WHERE ontology_version_id = ?
      AND aggregate_type = 'recipe'
      AND aggregate_id = ?
      AND entity_key = 'native:stale-target'
");
$removedReverse->execute([$versionId, $secondRecipeId]);
$assert(
    !empty($removal['rebuilt'])
    && (int)$removedMember->fetchColumn() === 0
    && (int)$removedReverse->fetchColumn() === 0,
    'Complete recipe replacement must remove stale member, attribute, '
        . 'relation, and reverse-index state'
);
$assert(
    (int)$db->query("
        SELECT COUNT(*) FROM ingredient_ontology_versions
    ")->fetchColumn() === $ontologyCountBefore
    && (int)$db->query("
        SELECT COUNT(*) FROM ontology_activation_imports
    ")->fetchColumn() === $importCountBefore
    && (int)$db->query("
        SELECT COUNT(*) FROM ontology_generation_intents
    ")->fetchColumn() === $intentCountBefore
    && !ingredientOntologyActivationOntologyStateRequiresBuild($db),
    'Mutable projection work must not create ontology build state'
);

$snapshotRacePath = $path . '.snapshot-race';
$artifacts[] = $snapshotRacePath;
databaseMaintenanceOnlineBackup($path, $snapshotRacePath);
$snapshotRaceDb = $open($snapshotRacePath);
$snapshotRaceToken = 'snapshot-direct-' . getmypid();
ingredientOntologyV3IncrementalBenchmarkFixtureInstall(
    $snapshotRaceDb
);
$snapshotRaceDb->exec("
    INSERT INTO products (barcode, name, brand, category)
    VALUES (
        'SB-SNAPSHOT-DIRECT-A',
        'Selective Benchmark Snapshot Direct A',
        '',
        'test'
    )
");
$snapshotRaceFirstId = (int)$snapshotRaceDb->lastInsertId();
$snapshotRaceFixtureId =
    ingredientOntologyV3IncrementalBenchmarkFixtureStage(
        $snapshotRaceDb,
        $snapshotRaceToken,
        'before_incremental_snapshot',
        'insert_product',
        [
            'barcode' => 'SB-SNAPSHOT-DIRECT-B',
            'name' => 'Selective Benchmark Snapshot Direct B',
            'category' => 'test',
        ]
    );
putenv(
    'INGREDIENT_ONTOLOGY_V3_BENCHMARK_FIXTURE_TOKEN='
        . $snapshotRaceToken
);
$snapshotRaceResult =
    ingredientOntologyV3IncrementalRebuild($snapshotRaceDb, true);
putenv('INGREDIENT_ONTOLOGY_V3_BENCHMARK_FIXTURE_TOKEN');
$snapshotRaceFixture =
    ingredientOntologyV3IncrementalBenchmarkFixture(
        $snapshotRaceDb,
        $snapshotRaceFixtureId
    );
$snapshotRaceSecondId = (int)(
    $snapshotRaceFixture['result']['product_id'] ?? 0
);
$snapshotRaceActive = recipeScoreActiveRevision($snapshotRaceDb);
$snapshotRacePin = ingredientOntologyV3CorpusAnnexForScore(
    $snapshotRaceDb,
    $snapshotRaceActive
);
$snapshotRaceProductIds = array_map(
    'intval',
    (array)$snapshotRaceResult['product_ids']
);
sort($snapshotRaceProductIds, SORT_NUMERIC);
$snapshotRaceExpectedIds = [
    $snapshotRaceFirstId,
    $snapshotRaceSecondId,
];
sort($snapshotRaceExpectedIds, SORT_NUMERIC);
$snapshotRaceHeads = $snapshotRaceDb->prepare("
    SELECT COUNT(*)
    FROM ingredient_ontology_corpus_annex_effective_aggregates
    WHERE ontology_version_id = ?
      AND aggregate_type = 'product'
      AND aggregate_id IN (?, ?)
      AND head_revision_id = ?
");
$snapshotRaceHeads->execute([
    $versionId,
    $snapshotRaceFirstId,
    $snapshotRaceSecondId,
    (int)$snapshotRacePin['id'],
]);
$assert(
    !empty($snapshotRaceResult['rebuilt'])
    && (string)($snapshotRaceFixture['status'] ?? '') === 'applied'
    && (int)($snapshotRaceFixture['attempt_count'] ?? 0) === 1
    && $snapshotRaceSecondId > 0
    && $snapshotRaceProductIds === $snapshotRaceExpectedIds
    && (int)$snapshotRaceHeads->fetchColumn() === 2
    && (int)$snapshotRacePin['captured_ontology_source_revision']
        === (int)recipeScoreState($snapshotRaceDb)[
            'ontology_source_revision'
        ]
    && (int)$snapshotRacePin['covered_ontology_source_revision']
        === (int)$snapshotRacePin[
            'captured_ontology_source_revision'
        ],
    'A mutation injected before the locked incremental snapshot must '
        . 'be included in the same complete suffix rather than hidden '
        . 'behind a zero-entry coverage advance: '
        . ingredientOntologyV3Json($snapshotRaceResult)
);
ingredientOntologyV3IncrementalBenchmarkFixtureClear(
    $snapshotRaceDb
);
$snapshotRaceDb = null;

$snapshotWorkerPath = $path . '.snapshot-worker';
$artifacts[] = $snapshotWorkerPath;
databaseMaintenanceOnlineBackup($path, $snapshotWorkerPath);
$snapshotWorkerDb = $open($snapshotWorkerPath);
$snapshotWorkerToken = 'snapshot-worker-' . getmypid();
ingredientOntologyV3IncrementalBenchmarkFixtureInstall(
    $snapshotWorkerDb
);
$snapshotWorkerDb->exec("
    INSERT INTO products (barcode, name, brand, category)
    VALUES (
        'SB-SNAPSHOT-WORKER-A',
        'Selective Benchmark Snapshot Worker A',
        '',
        'test'
    )
");
$snapshotWorkerFirstId = (int)$snapshotWorkerDb->lastInsertId();
$snapshotWorkerFixtureId =
    ingredientOntologyV3IncrementalBenchmarkFixtureStage(
        $snapshotWorkerDb,
        $snapshotWorkerToken,
        'before_incremental_snapshot',
        'insert_product',
        [
            'barcode' => 'SB-SNAPSHOT-WORKER-B',
            'name' => 'Selective Benchmark Snapshot Worker B',
            'category' => 'test',
        ]
    );
$snapshotWorkerResult = $runBenchmarkWorker(
    $snapshotWorkerPath,
    $snapshotWorkerToken
);
$snapshotWorkerFixture =
    ingredientOntologyV3IncrementalBenchmarkFixture(
        $snapshotWorkerDb,
        $snapshotWorkerFixtureId
    );
$snapshotWorkerSecondId = (int)(
    $snapshotWorkerFixture['result']['product_id'] ?? 0
);
$snapshotWorkerPin = ingredientOntologyV3CorpusAnnexForScore(
    $snapshotWorkerDb,
    recipeScoreActiveRevision($snapshotWorkerDb)
);
$snapshotWorkerHeads = $snapshotWorkerDb->prepare("
    SELECT COUNT(*)
    FROM ingredient_ontology_corpus_annex_effective_aggregates
    WHERE ontology_version_id = ?
      AND aggregate_type = 'product'
      AND aggregate_id IN (?, ?)
      AND head_revision_id = ?
");
$snapshotWorkerHeads->execute([
    $versionId,
    $snapshotWorkerFirstId,
    $snapshotWorkerSecondId,
    (int)$snapshotWorkerPin['id'],
]);
$snapshotWorkerMetrics = (array)(
    $snapshotWorkerResult['benchmark_metrics'] ?? []
);
$assert(
    !empty($snapshotWorkerResult['rebuilt'])
    && (string)($snapshotWorkerFixture['status'] ?? '') === 'applied'
    && (int)($snapshotWorkerFixture['attempt_count'] ?? 0) === 1
    && $snapshotWorkerSecondId > 0
    && (int)$snapshotWorkerHeads->fetchColumn() === 2
    && (int)($snapshotWorkerMetrics['full_corpus_scans'] ?? -1) === 0
    && (int)($snapshotWorkerMetrics['initial_rss_bytes'] ?? 0) > 0
    && (int)($snapshotWorkerMetrics['peak_rss_bytes'] ?? 0) > 0
    && (int)$snapshotWorkerMetrics['peak_rss_bytes']
        >= (int)$snapshotWorkerMetrics['initial_rss_bytes']
    && (int)(
        $snapshotWorkerMetrics['peak_php_memory_bytes'] ?? 0
    ) > 0,
    'The worker subprocess must consume a durable snapshot fixture and '
        . 'report its own full-scan and peak-RSS metrics: '
        . ingredientOntologyV3Json([
            'fixture' => $snapshotWorkerFixture,
            'result' => $snapshotWorkerResult,
        ])
);
ingredientOntologyV3IncrementalBenchmarkFixtureClear(
    $snapshotWorkerDb
);
$snapshotWorkerDb = null;

$identityOnlyPath = $path . '.identity-only';
$artifacts[] = $identityOnlyPath;
databaseMaintenanceOnlineBackup($path, $identityOnlyPath);
$identityOnlyDb = $open($identityOnlyPath);
$identityOnlyToken = 'identity-worker-' . getmypid();
ingredientOntologyV3IncrementalBenchmarkFixtureInstall(
    $identityOnlyDb
);
$identityActiveBefore = recipeScoreActiveRevision($identityOnlyDb);
$identityPinBefore = ingredientOntologyV3CorpusAnnexForScore(
    $identityOnlyDb,
    $identityActiveBefore
);
$identitySourceRevision = (int)recipeScoreState($identityOnlyDb)[
    'ontology_source_revision'
];
$identityProductHashBefore = (string)$identityOnlyDb->query("
    SELECT aggregate_hash
    FROM ingredient_ontology_corpus_annex_effective_aggregates
    WHERE ontology_version_id = {$versionId}
      AND aggregate_type = 'product'
      AND aggregate_id = {$kabochaProductId}
")->fetchColumn();
$identityRecipeHashBefore = (string)$identityOnlyDb->query("
    SELECT aggregate_hash
    FROM ingredient_ontology_corpus_annex_effective_aggregates
    WHERE ontology_version_id = {$versionId}
      AND aggregate_type = 'recipe'
      AND aggregate_id = {$kabochaRecipeId}
")->fetchColumn();
recipeScoreMarkProductDirty(
    $identityOnlyDb,
    $kabochaProductId,
    'corpus_projection_identity_only'
);
$identityOnlyFixtureId =
    ingredientOntologyV3IncrementalBenchmarkFixtureStage(
        $identityOnlyDb,
        $identityOnlyToken,
        'after_identity_admission',
        'assign_identity_extension',
        [
            'product_id' => $kabochaProductId,
            'context_signature' =>
                'corpus-projection-identity-only-'
                    . getmypid(),
            'admission_source' =>
                'benchmark_identity_only_test',
            'reason' => 'benchmark_identity_only_test',
            'evidence_namespace' =>
                'corpus-projection-identity-only',
        ]
    );
$identityOnlyResults = [];
$identityWorkerFullCorpusScans = 0;
$identityWorkerPeakRssBytes = 0;
$identityWorkerCorpusOperations = [];
$identityWorkerMaximumWriteLockMs = 0.0;
$identityWorkerMetricsComplete = true;
for ($identityPass = 0; $identityPass < 5; $identityPass++) {
    $identityOnlyResults[] = $runBenchmarkWorker(
        $identityOnlyPath,
        $identityOnlyToken
    );
    $identityMetrics = (array)(
        $identityOnlyResults[array_key_last(
            $identityOnlyResults
        )]['benchmark_metrics'] ?? []
    );
    $identityWorkerMetricsComplete =
        $identityWorkerMetricsComplete
        && isset(
            $identityMetrics['corpus_operation_counts'],
            $identityMetrics['peak_rss_bytes']
        )
        && is_array($identityMetrics['corpus_operation_counts']);
    $identityWorkerFullCorpusScans +=
        (int)($identityMetrics['full_corpus_scans'] ?? -1);
    $identityWorkerPeakRssBytes = max(
        $identityWorkerPeakRssBytes,
        (int)($identityMetrics['peak_rss_bytes'] ?? 0)
    );
    foreach (
        (array)($identityMetrics['corpus_operation_counts'] ?? [])
        as $operation => $count
    ) {
        $identityWorkerCorpusOperations[(string)$operation] =
            (int)($identityWorkerCorpusOperations[
                (string)$operation
            ] ?? 0) + (int)$count;
    }
    $identityTiming = (array)($identityOnlyResults[
        array_key_last($identityOnlyResults)
    ]['timing_ms'] ?? []);
    $identityWorkerMetricsComplete =
        $identityWorkerMetricsComplete
        && isset(
            $identityTiming['snapshot_write_lock'],
            $identityTiming['publish_write_lock']
        );
    $identityWorkerMaximumWriteLockMs = max(
        $identityWorkerMaximumWriteLockMs,
        (float)($identityTiming['snapshot_write_lock'] ?? INF),
        (float)($identityTiming[
            'publish_write_lock'
        ] ?? $identityTiming['publish'] ?? INF)
    );
    $identityCurrentPin = ingredientOntologyV3CorpusAnnexForScore(
        $identityOnlyDb,
        recipeScoreActiveRevision($identityOnlyDb)
    );
    $identityCurrentSnapshot =
        ingredientOntologyV3IdentityExtensionSnapshot(
            $identityOnlyDb,
            $versionId
        );
    if (
        (int)$identityOnlyDb->query("
            SELECT COUNT(*)
            FROM recipe_score_identity_projection_work
            WHERE ontology_version_id = {$versionId}
        ")->fetchColumn() === 0
        && (int)$identityCurrentPin[
            'covered_identity_extension_revision'
        ] >= (int)$identityCurrentSnapshot['revision']
    ) {
        break;
    }
}
$identityOnlyResult = $identityOnlyResults[
    array_key_last($identityOnlyResults)
];
$identityOnlyFixture =
    ingredientOntologyV3IncrementalBenchmarkFixture(
        $identityOnlyDb,
        $identityOnlyFixtureId
    );
$identityClaim = is_array($identityOnlyFixture['result'] ?? null)
    ? [
        'id' => (int)$identityOnlyFixture['result'][
            'extension_entity_id'
        ],
        'created_revision' => (int)$identityOnlyFixture['result'][
            'created_revision'
        ],
    ]
    : null;
$identityDiscoveredRecipeIds =
    ingredientOntologyV3IdentityExtensionRecipeIdsForProducts(
        $identityOnlyDb,
        $versionId,
        [$kabochaProductId]
    );
$identitySourceAfterAdmission = (int)recipeScoreState(
    $identityOnlyDb
)['ontology_source_revision'];
$identityOnlyActive = recipeScoreActiveRevision($identityOnlyDb);
$identityPinAfter = ingredientOntologyV3CorpusAnnexForScore(
    $identityOnlyDb,
    $identityOnlyActive
);
$identityHeads = $identityOnlyDb->prepare("
    SELECT aggregate_type, aggregate_id, aggregate_hash,
           head_revision_id
    FROM ingredient_ontology_corpus_annex_effective_aggregates
    WHERE ontology_version_id = ?
      AND (
          (aggregate_type = 'product' AND aggregate_id = ?)
          OR
          (aggregate_type = 'recipe' AND aggregate_id = ?)
      )
    ORDER BY aggregate_type
");
$identityHeads->execute([
    $versionId,
    $kabochaProductId,
    $kabochaRecipeId,
]);
$identityHeads = $identityHeads->fetchAll(PDO::FETCH_ASSOC);
$identityHeadByType = array_column(
    $identityHeads,
    null,
    'aggregate_type'
);
$identityWorkerDisallowedOperations = [];
foreach ([
    'legacy_corpus_hash',
    'identity_extension_deep_audit',
    'corpus_annex_deep_audit',
    'effective_projection_counts',
    'effective_projection_hash',
    'effective_content_hash',
    'effective_projection_rebuild',
    'effective_checkpoint_build',
    'score_full_compaction',
    'authoritative_candidate_page',
] as $operation) {
    if ((int)($identityWorkerCorpusOperations[$operation] ?? 0) > 0) {
        $identityWorkerDisallowedOperations[$operation] =
            (int)$identityWorkerCorpusOperations[$operation];
    }
}
$assert(
    $identityPinBefore !== null
    && $identityClaim !== null
    && (string)($identityOnlyFixture['status'] ?? '') === 'applied'
    && (int)($identityOnlyFixture['attempt_count'] ?? 0) === 1
    && (int)(
        $identityWorkerFullCorpusScans
    ) === 0
    && $identityWorkerPeakRssBytes > 0
    && $identityWorkerMetricsComplete
    && $identityWorkerMaximumWriteLockMs <= 5000
    && !$identityWorkerDisallowedOperations
    && in_array(
        $kabochaRecipeId,
        $identityDiscoveredRecipeIds,
        true
    )
    && $identitySourceAfterAdmission === $identitySourceRevision
    && !empty($identityOnlyResult['rebuilt'])
    && (array)$identityOnlyResult['recipe_ids']
        === [$kabochaRecipeId]
    && (int)$identityOnlyResult['physical_score_rows'] === 1
    && (int)$identityPinAfter['id'] !== (int)$identityPinBefore['id']
    && (int)$identityPinAfter['identity_extension_revision']
        > (int)$identityPinBefore['identity_extension_revision']
    && (int)$identityPinAfter['identity_extension_revision']
        === (int)$identityOnlyActive['identity_extension_revision']
    && hash_equals(
        (string)$identityPinAfter['identity_extension_hash'],
        (string)$identityOnlyActive['identity_extension_hash']
    )
    && (int)$identityHeadByType['product']['head_revision_id']
        > (int)$identityPinBefore['id']
    && (int)$identityHeadByType['product']['head_revision_id']
        <= (int)$identityPinAfter['id']
    && (int)$identityHeadByType['recipe']['head_revision_id']
        === (int)$identityPinAfter['id']
    && !hash_equals(
        $identityProductHashBefore,
        (string)$identityHeadByType['product']['aggregate_hash']
    )
    && !hash_equals(
        $identityRecipeHashBefore,
        (string)$identityHeadByType['recipe']['aggregate_hash']
    ),
    'A no-source-mutation identity extension must republish the '
        . 'product and dependent recipe aggregates and pin the exact '
        . 'extension revision: '
        . ingredientOntologyV3Json([
            'claim' => $identityClaim,
            'discovered_recipe_ids' =>
                $identityDiscoveredRecipeIds,
            'result' => $identityOnlyResult,
            'results' => $identityOnlyResults,
            'maximum_write_lock_ms' =>
                $identityWorkerMaximumWriteLockMs,
            'corpus_operations' =>
                $identityWorkerCorpusOperations,
            'before_pin' => $identityPinBefore,
            'after_pin' => $identityPinAfter,
            'heads' => $identityHeads,
        ])
);
ingredientOntologyV3IncrementalBenchmarkFixtureClear(
    $identityOnlyDb
);
$identityOnlyDb = null;

$lockContentionPath = $path . '.lock-contention';
$artifacts[] = $lockContentionPath;
databaseMaintenanceOnlineBackup(
    $identityOnlyPath,
    $lockContentionPath
);
$lockContentionDb = $open($lockContentionPath);
$lockContentionDb->exec("
    CREATE TABLE corpus_annex_lock_probe (
        id INTEGER PRIMARY KEY CHECK(id = 1),
        stop_requested INTEGER NOT NULL DEFAULT 0
            CHECK(stop_requested IN (0, 1)),
        attempts INTEGER NOT NULL DEFAULT 0
    );
    INSERT INTO corpus_annex_lock_probe (id) VALUES (1)
");
recipeScoreMarkProductDirty(
    $lockContentionDb,
    $kabochaProductId,
    'corpus_annex_lock_contention'
);
$lockWriterCode = <<<'PHP'
$path = $argv[1];
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA busy_timeout=5000');
$deadline = microtime(true) + 30;
$attempts = 0;
$maximumMs = 0.0;
$errors = [];
while (microtime(true) < $deadline) {
    $started = hrtime(true);
    try {
        $db->exec("
            UPDATE corpus_annex_lock_probe
            SET attempts = attempts + 1
            WHERE id = 1
        ");
        $attempts++;
    } catch (Throwable $error) {
        $errors[] = $error->getMessage();
    }
    $maximumMs = max(
        $maximumMs,
        (hrtime(true) - $started) / 1000000
    );
    $stop = (int)$db->query("
        SELECT stop_requested
        FROM corpus_annex_lock_probe
        WHERE id = 1
    ")->fetchColumn();
    if ($stop === 1) {
        break;
    }
    usleep(20000);
}
echo json_encode([
    'attempts' => $attempts,
    'maximum_ms' => round($maximumMs, 3),
    'errors' => $errors,
]);
PHP;
$lockWriterPipes = [];
$lockWriter = proc_open(
    [
        PHP_BINARY,
        '-r',
        $lockWriterCode,
        $lockContentionPath,
    ],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $lockWriterPipes,
    dirname(__DIR__)
);
if (!is_resource($lockWriter)) {
    throw new RuntimeException(
        'Concurrent writer regression could not start'
    );
}
fclose($lockWriterPipes[0]);
usleep(100000);
$lockContentionWorker = null;
$lockContentionWorkerError = null;
try {
    $lockContentionWorker = $runBenchmarkWorker(
        $lockContentionPath,
        $identityOnlyToken
    );
} catch (Throwable $error) {
    $lockContentionWorkerError = $error;
} finally {
    $lockContentionDb->exec("
        UPDATE corpus_annex_lock_probe
        SET stop_requested = 1
        WHERE id = 1
    ");
}
$lockWriterStdout = stream_get_contents($lockWriterPipes[1]);
$lockWriterStderr = stream_get_contents($lockWriterPipes[2]);
fclose($lockWriterPipes[1]);
fclose($lockWriterPipes[2]);
$lockWriterStatus = proc_close($lockWriter);
$lockWriterResult = json_decode(
    trim((string)$lockWriterStdout),
    true
);
$lockContentionTiming =
    is_array($lockContentionWorker)
        ? (array)($lockContentionWorker['timing_ms'] ?? [])
        : [];
$assert(
    $lockContentionWorkerError === null
    && $lockWriterStatus === 0
    && is_array($lockWriterResult)
    && (int)($lockWriterResult['attempts'] ?? 0) >= 2
    && !(array)($lockWriterResult['errors'] ?? [])
    && (float)($lockWriterResult['maximum_ms'] ?? INF) <= 5500
    && !empty($lockContentionWorker['rebuilt'])
    && (float)($lockContentionTiming[
        'snapshot_write_lock'
    ] ?? INF) <= 5000
    && (float)($lockContentionTiming[
        'publish_write_lock'
    ] ?? INF) <= 5000,
    'A normal SQLite writer must remain available through a real '
        . 'incremental worker cycle: '
        . ingredientOntologyV3Json([
            'writer' => $lockWriterResult,
            'writer_status' => $lockWriterStatus,
            'writer_stderr' => $lockWriterStderr,
            'worker' => $lockContentionWorker,
            'worker_error' => $lockContentionWorkerError
                ? $lockContentionWorkerError->getMessage()
                : null,
        ])
);
$lockContentionDb = null;

$emptyIdentityPath = $path . '.identity-empty-events';
$artifacts[] = $emptyIdentityPath;
databaseMaintenanceOnlineBackup(
    $identityOnlyPath,
    $emptyIdentityPath
);
$emptyIdentityDb = $open($emptyIdentityPath);
$emptyIdentityVersion = ingredientOntologyV3Version(
    $emptyIdentityDb,
    $versionId
);
$emptyIdentityClaim = ingredientOntologyV3IdentityExtensionClaim(
    $emptyIdentityDb,
    $emptyIdentityVersion,
    'Unused identity projection ingredient ' . getmypid(),
    'en',
    'empty-event-regression-' . getmypid()
);
if (
    !is_array($emptyIdentityVersion)
    || !is_array($emptyIdentityClaim)
) {
    throw new RuntimeException(
        'Empty identity event fixture could not claim an extension'
    );
}
$emptyIdentityPin = ingredientOntologyV3CorpusAnnexForScore(
    $emptyIdentityDb,
    recipeScoreActiveRevision($emptyIdentityDb)
);
$emptyIdentityCoveredExtension = $emptyIdentityDb->query("
    SELECT extension.id, extension.created_revision,
           extension.content_hash
    FROM ingredient_ontology_identity_annex annex
    JOIN ingredient_ontology_identity_extension_entities extension
      ON extension.id = annex.extension_entity_id
    WHERE annex.product_id = {$kabochaProductId}
")->fetch(PDO::FETCH_ASSOC);
if (
    !is_array($emptyIdentityPin)
    || !is_array($emptyIdentityCoveredExtension)
) {
    throw new RuntimeException(
        'Covered identity event fixture is unavailable'
    );
}
$emptyIdentityDb->exec('BEGIN IMMEDIATE');
try {
    ingredientOntologyV3IdentityProjectionSeedEvents(
        $emptyIdentityDb,
        $versionId,
        (int)$emptyIdentityClaim['created_revision'] - 1,
        (int)$emptyIdentityClaim['created_revision'],
        (int)recipeScoreState($emptyIdentityDb)[
            'ontology_source_revision'
        ],
        []
    );
    $emptyIdentitySeeded = $emptyIdentityDb->prepare("
        SELECT COUNT(*)
        FROM recipe_score_identity_projection_events
        WHERE extension_entity_id = ?
    ");
    $emptyIdentitySeeded->execute([
        (int)$emptyIdentityClaim['id'],
    ]);
    $emptyIdentitySeededCount =
        (int)$emptyIdentitySeeded->fetchColumn();
    $emptyIdentityDb->prepare("
        INSERT INTO recipe_score_identity_projection_events (
            ontology_version_id, event_key,
            required_revision, required_hash,
            extension_entity_id, after_recipe_id, completed
        )
        VALUES (?, ?, ?, ?, ?, 0, 0)
    ")->execute([
        $versionId,
        'empty-event-regression-' . getmypid(),
        (int)$emptyIdentityClaim['created_revision'],
        (string)$emptyIdentityClaim['content_hash'],
        (int)$emptyIdentityClaim['id'],
    ]);
    $emptyIdentityEventId =
        (int)$emptyIdentityDb->lastInsertId();
    $emptyIdentityDb->prepare("
        INSERT INTO recipe_score_identity_projection_events (
            ontology_version_id, event_key,
            required_revision, required_hash,
            extension_entity_id, after_recipe_id, completed
        )
        VALUES (?, ?, ?, ?, ?, 0, 0)
    ")->execute([
        $versionId,
        'covered-event-regression-' . getmypid(),
        (int)$emptyIdentityCoveredExtension['created_revision'],
        (string)$emptyIdentityCoveredExtension['content_hash'],
        (int)$emptyIdentityCoveredExtension['id'],
    ]);
    $emptyIdentityCoveredEventId =
        (int)$emptyIdentityDb->lastInsertId();
    $emptyIdentityInserted =
        ingredientOntologyV3IdentityProjectionProcessEvents(
            $emptyIdentityDb,
            $versionId,
            1,
            (string)$emptyIdentityPin['resolution_input_hash']
        );
    $emptyIdentityCompleted = $emptyIdentityDb->prepare("
        SELECT completed
        FROM recipe_score_identity_projection_events
        WHERE id = ?
    ");
    $emptyIdentityCompleted->execute([$emptyIdentityEventId]);
    $emptyIdentityCompleted =
        (int)$emptyIdentityCompleted->fetchColumn();
    $emptyIdentityCoveredCompleted = $emptyIdentityDb->prepare("
        SELECT completed
        FROM recipe_score_identity_projection_events
        WHERE id = ?
    ");
    $emptyIdentityCoveredCompleted->execute([
        $emptyIdentityCoveredEventId,
    ]);
    $emptyIdentityCoveredCompleted =
        (int)$emptyIdentityCoveredCompleted->fetchColumn();
    $emptyIdentityCoveredWork = $emptyIdentityDb->prepare("
        SELECT COUNT(*)
        FROM recipe_score_identity_projection_work
        WHERE ontology_version_id = ?
          AND recipe_id = ?
    ");
    $emptyIdentityCoveredWork->execute([
        $versionId,
        $kabochaRecipeId,
    ]);
    $emptyIdentityCoveredWork =
        (int)$emptyIdentityCoveredWork->fetchColumn();
    $emptyIdentityDb->exec('ROLLBACK');
} catch (Throwable $error) {
    $emptyIdentityDb->exec('ROLLBACK');
    throw $error;
}
$assert(
    is_array($emptyIdentityVersion)
    && is_array($emptyIdentityClaim)
    && $emptyIdentitySeededCount === 0
    && $emptyIdentityInserted === 0
    && $emptyIdentityCompleted === 1
    && $emptyIdentityCoveredCompleted === 1
    && $emptyIdentityCoveredWork === 0,
    'Identity projection must not seed dependency-free extension '
        . 'events, must retire legacy empty events, and must not '
        . 'requeue recipes whose physical score source already pins '
        . 'the required identity and resolver input'
);
$emptyIdentityDb = null;

$identityEventBudgetPath = $path . '.identity-event-budget';
$artifacts[] = $identityEventBudgetPath;
databaseMaintenanceOnlineBackup(
    $identityOnlyPath,
    $identityEventBudgetPath
);
$identityEventBudgetDb = $open($identityEventBudgetPath);
$identityEventBudget = 40;
$identityEventCount = 48;
$identityEventSnapshot =
    ingredientOntologyV3IdentityExtensionSnapshot(
        $identityEventBudgetDb,
        $versionId
    );
$identityEventExtensionId =
    (int)$identityEventBudgetDb->query("
        SELECT extension_entity_id
        FROM ingredient_ontology_identity_annex
        WHERE product_id = {$kabochaProductId}
          AND extension_entity_id IS NOT NULL
    ")->fetchColumn();
$identityEventInitialPending =
    (int)$identityEventBudgetDb->query("
        SELECT COUNT(*)
        FROM recipe_score_identity_projection_events
        WHERE ontology_version_id = {$versionId}
          AND completed = 0
    ")->fetchColumn();
$identityEventInitialWork =
    (int)$identityEventBudgetDb->query("
        SELECT COUNT(*)
        FROM recipe_score_identity_projection_work
        WHERE ontology_version_id = {$versionId}
          AND recipe_id = {$kabochaRecipeId}
    ")->fetchColumn();
$identityEventIds = [];
$identityEventBudgetDb->exec('BEGIN IMMEDIATE');
try {
    $insertIdentityEvent = $identityEventBudgetDb->prepare("
        INSERT INTO recipe_score_identity_projection_events (
            ontology_version_id, event_key,
            required_revision, required_hash,
            extension_entity_id, product_id, source_revision,
            after_recipe_id, completed
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
    ");
    for ($index = 0; $index < $identityEventCount; $index++) {
        $insertIdentityEvent->execute([
            $versionId,
            'event-budget-' . getmypid() . '-' . $index,
            (int)$identityEventSnapshot['revision'],
            (string)$identityEventSnapshot['hash'],
            $identityEventExtensionId,
            $kabochaProductId,
            (int)recipeScoreState($identityEventBudgetDb)[
                'ontology_source_revision'
            ] + $index,
            max(0, $kabochaRecipeId - 1),
        ]);
        $identityEventIds[] =
            (int)$identityEventBudgetDb->lastInsertId();
    }
    $identityEventInserted =
        ingredientOntologyV3IdentityProjectionProcessEvents(
            $identityEventBudgetDb,
            $versionId,
            $identityEventBudget,
            (string)ingredientOntologyV3CorpusAnnexForScore(
                $identityEventBudgetDb,
                recipeScoreActiveRevision($identityEventBudgetDb)
            )['resolution_input_hash']
        );
    $identityEventPlaceholders = implode(
        ',',
        array_fill(0, count($identityEventIds), '?')
    );
    $identityEventCounts = $identityEventBudgetDb->prepare("
        SELECT completed, COUNT(*) AS event_count
        FROM recipe_score_identity_projection_events
        WHERE id IN ({$identityEventPlaceholders})
        GROUP BY completed
        ORDER BY completed
    ");
    $identityEventCounts->execute($identityEventIds);
    $identityEventCounts = array_column(
        $identityEventCounts->fetchAll(PDO::FETCH_ASSOC),
        'event_count',
        'completed'
    );
    $identityEventWork = $identityEventBudgetDb->prepare("
        SELECT first_event_id, latest_event_id
        FROM recipe_score_identity_projection_work
        WHERE ontology_version_id = ?
          AND recipe_id = ?
    ");
    $identityEventWork->execute([
        $versionId,
        $kabochaRecipeId,
    ]);
    $identityEventWork =
        $identityEventWork->fetch(PDO::FETCH_ASSOC);
    $identityEventBudgetDb->exec('ROLLBACK');
} catch (Throwable $error) {
    $identityEventBudgetDb->exec('ROLLBACK');
    throw $error;
}
$assert(
    $identityEventExtensionId > 0
    && $identityEventInitialPending === 0
    && $identityEventInitialWork === 0
    && $identityEventInserted === $identityEventBudget
    && (int)($identityEventCounts[1] ?? 0)
        === $identityEventBudget
    && (int)($identityEventCounts[0] ?? 0)
        === $identityEventCount - $identityEventBudget
    && is_array($identityEventWork)
    && (int)$identityEventWork['first_event_id']
        === $identityEventIds[0]
    && (int)$identityEventWork['latest_event_id']
        === $identityEventIds[$identityEventBudget - 1],
    'More than 32 genuine identity events must complete in one '
        . 'budgeted call while recipe-occurrence work remains capped: '
        . ingredientOntologyV3Json([
            'budget' => $identityEventBudget,
            'event_count' => $identityEventCount,
            'inserted' => $identityEventInserted,
            'counts' => $identityEventCounts,
            'work' => $identityEventWork,
        ])
);
$identityEventBudgetDb = null;

$staleIdentityPath = $path . '.identity-stale-date';
$artifacts[] = $staleIdentityPath;
databaseMaintenanceOnlineBackup(
    $identityOnlyPath,
    $staleIdentityPath
);
$staleIdentityDb = $open($staleIdentityPath);
$staleIdentityParent = recipeScoreActiveRevision($staleIdentityDb);
$staleIdentitySnapshot =
    ingredientOntologyV3IdentityExtensionSnapshot(
        $staleIdentityDb,
        $versionId
    );
$staleIdentityExtensionId = (int)$staleIdentityDb->query("
    SELECT extension_entity_id
    FROM ingredient_ontology_identity_annex
    WHERE product_id = {$kabochaProductId}
      AND extension_entity_id IS NOT NULL
")->fetchColumn();
$staleIdentityDb->prepare("
    INSERT INTO recipe_score_identity_projection_events (
        ontology_version_id, event_key,
        required_revision, required_hash,
        extension_entity_id, product_id,
        after_recipe_id, completed
    )
    VALUES (?, ?, ?, ?, ?, ?, 0, 0)
")->execute([
    $versionId,
    'stale-score-date-' . getmypid(),
    (int)$staleIdentitySnapshot['revision'],
    (string)$staleIdentitySnapshot['hash'],
    $staleIdentityExtensionId,
    $kabochaProductId,
]);
$staleIdentityEventId = (int)$staleIdentityDb->lastInsertId();
$staleIdentityDb->prepare("
    INSERT INTO recipe_score_identity_projection_work (
        ontology_version_id, recipe_id,
        first_event_id, latest_event_id,
        first_required_revision, latest_required_revision,
        latest_required_hash
    )
    VALUES (?, ?, ?, ?, ?, ?, ?)
")->execute([
    $versionId,
    $kabochaRecipeId,
    $staleIdentityEventId,
    $staleIdentityEventId,
    (int)$staleIdentitySnapshot['revision'],
    (int)$staleIdentitySnapshot['revision'],
    (string)$staleIdentitySnapshot['hash'],
]);
$staleScoreDate = (new DateTimeImmutable(
    recipeScoreCurrentDate(),
    recipeScoreTimezone()
))->modify('-1 day')->format('Y-m-d');
ingredientOntologyV3SetReadyMutationGuard(
    $staleIdentityDb,
    true
);
try {
    $staleIdentityDb->prepare("
        UPDATE recipe_score_revisions
        SET score_date = ?
        WHERE id = ?
    ")->execute([
        $staleScoreDate,
        (int)$staleIdentityParent['id'],
    ]);
} finally {
    ingredientOntologyV3SetReadyMutationGuard(
        $staleIdentityDb,
        false
    );
}
$staleIdentityScoreMaxBefore = (int)$staleIdentityDb->query("
    SELECT COALESCE(MAX(id), 0)
    FROM recipe_score_revisions
")->fetchColumn();
$staleIdentityAnnexMaxBefore = (int)$staleIdentityDb->query("
    SELECT COALESCE(MAX(id), 0)
    FROM ingredient_ontology_corpus_annex_revisions
")->fetchColumn();
$staleIdentityFailedBefore = (int)$staleIdentityDb->query("
    SELECT COUNT(*)
    FROM recipe_score_revisions
    WHERE status = 'failed'
")->fetchColumn();
$staleIdentityDeferred =
    ingredientOntologyV3IncrementalRebuild(
        $staleIdentityDb,
        true
    );
$staleIdentityPendingAfterDefer = (int)$staleIdentityDb->query("
    SELECT COUNT(*)
    FROM recipe_score_identity_projection_work
    WHERE ontology_version_id = {$versionId}
")->fetchColumn();
recipeScoreMarkProductDirty(
    $staleIdentityDb,
    $kabochaProductId,
    'stale_score_date_serving_rollover'
);
$staleIdentityServing =
    ingredientOntologyV3IncrementalRebuild(
        $staleIdentityDb,
        true
    );
$staleIdentityActive = recipeScoreActiveRevision($staleIdentityDb);
$staleIdentityPendingAfterServing = (int)$staleIdentityDb->query("
    SELECT COUNT(*)
    FROM recipe_score_identity_projection_work
    WHERE ontology_version_id = {$versionId}
")->fetchColumn();
$assert(
    $staleIdentityExtensionId > 0
    && empty($staleIdentityDeferred['rebuilt'])
    && (string)$staleIdentityDeferred['reason']
        === 'score_date_refresh_required'
    && (string)$staleIdentityDeferred['parent_score_date']
        === $staleScoreDate
    && (string)$staleIdentityDeferred['current_score_date']
        === recipeScoreCurrentDate()
    && $staleIdentityPendingAfterDefer === 1
    && (int)$staleIdentityDb->query("
        SELECT COALESCE(MAX(id), 0)
        FROM recipe_score_revisions
    ")->fetchColumn() === $staleIdentityScoreMaxBefore + 1
    && (int)$staleIdentityDb->query("
        SELECT COALESCE(MAX(id), 0)
        FROM ingredient_ontology_corpus_annex_revisions
    ")->fetchColumn() === $staleIdentityAnnexMaxBefore + 1
    && (int)$staleIdentityDb->query("
        SELECT COUNT(*)
        FROM recipe_score_revisions
        WHERE status = 'failed'
    ")->fetchColumn() === $staleIdentityFailedBefore
    && !empty($staleIdentityServing['rebuilt'])
    && !empty($staleIdentityServing['serving_only'])
    && (string)$staleIdentityActive['score_date']
        === recipeScoreCurrentDate()
    && $staleIdentityPendingAfterServing === 1,
    'Stale-date identity maintenance must defer without failed '
        . 'revisions, then remain pending while serving work rolls '
        . 'the active score date forward: '
        . ingredientOntologyV3Json([
            'deferred' => $staleIdentityDeferred,
            'serving' => $staleIdentityServing,
            'pending_after_defer' =>
                $staleIdentityPendingAfterDefer,
            'pending_after_serving' =>
                $staleIdentityPendingAfterServing,
        ])
);
$staleIdentityDb = null;

$identityRebindPath = $path . '.identity-rebind';
$artifacts[] = $identityRebindPath;
databaseMaintenanceOnlineBackup(
    $identityOnlyPath,
    $identityRebindPath
);
$identityRebindDb = $open($identityRebindPath);
$identityRebindLabel = 'selective historical rebind target';
$identityRebindDb->prepare("
    INSERT INTO recipe_catalog (
        primary_connector, title, language, cache_expires_at
    )
    VALUES (
        'manual', 'Historical rebind target', 'en',
        datetime('now', '+1 day')
    )
")->execute();
$identityRebindRecipeId =
    (int)$identityRebindDb->lastInsertId();
$identityRebindDb->prepare("
    INSERT INTO recipe_ingredients (
        recipe_id, position, raw_text, normalized_name,
        source_is_required, source_is_optional,
        requiredness_source
    )
    VALUES (?, 0, ?, ?, 1, 0, 'explicit_required')
")->execute([
    $identityRebindRecipeId,
    $identityRebindLabel,
    $identityRebindLabel,
]);
for ($pass = 0; $pass < 10; $pass++) {
    ingredientOntologyV3IncrementalRebuild(
        $identityRebindDb,
        true
    );
    $setupPin = ingredientOntologyV3CorpusAnnexForScore(
        $identityRebindDb,
        recipeScoreActiveRevision($identityRebindDb)
    );
    if (
        (int)$identityRebindDb->query("
            SELECT COUNT(*)
            FROM recipe_score_pending_recipes
        ")->fetchColumn() === 0
        && (int)$setupPin['covered_ontology_source_revision']
            === (int)recipeScoreState($identityRebindDb)[
                'ontology_source_revision'
            ]
    ) {
        break;
    }
}
$identityRebindDb->prepare("
    UPDATE products
    SET name = ?
    WHERE id = ?
")->execute([
    $identityRebindLabel,
    $kabochaProductId,
]);
$identityRebindClaim = null;
$GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_IDENTITY_ADMISSION'] =
    static function (
        PDO $hookDb,
        int $hookVersionId,
        array $productIds
    ) use (
        $kabochaProductId,
        $identityRebindLabel,
        &$identityRebindClaim
    ): void {
        if (!in_array($kabochaProductId, $productIds, true)) {
            return;
        }
        $version = ingredientOntologyV3Version(
            $hookDb,
            $hookVersionId
        );
        $annex = $hookDb->prepare("
            SELECT language
            FROM ingredient_ontology_identity_annex
            WHERE product_id = ? AND ontology_version_id = ?
        ");
        $annex->execute([$kabochaProductId, $hookVersionId]);
        $annex = $annex->fetch(PDO::FETCH_ASSOC);
        if (!$version || !$annex) {
            throw new RuntimeException(
                'identity rebind evidence is unavailable'
            );
        }
        $identityRebindClaim =
            ingredientOntologyV3IdentityExtensionClaim(
                $hookDb,
                $version,
                $identityRebindLabel,
                (string)$annex['language'],
                'identity-rebind-regression',
                true,
                true
            );
        $hookDb->prepare("
            UPDATE ingredient_ontology_identity_annex
            SET source_label = ?,
                normalized_label = ?,
                label_id = NULL,
                entity_id = NULL,
                extension_entity_id = ?,
                status = 'accepted',
                admission_source = 'identity_rebind_regression',
                evidence_hash = ?,
                reason = 'identity_rebind_regression',
                updated_at = CURRENT_TIMESTAMP
            WHERE product_id = ?
              AND ontology_version_id = ?
        ")->execute([
            $identityRebindLabel,
            $identityRebindLabel,
            (int)$identityRebindClaim['id'],
            ingredientOntologyV3Hash([
                'test' => 'identity-rebind-regression',
                'extension_id' => (int)$identityRebindClaim['id'],
            ]),
            $kabochaProductId,
            $hookVersionId,
        ]);
    };
$identityRebindRecipeIds = [];
$identityRebindResults = [];
for ($pass = 0; $pass < 10; $pass++) {
    $result = ingredientOntologyV3IncrementalRebuild(
        $identityRebindDb,
        true
    );
    $identityRebindResults[] = $result;
    $identityRebindRecipeIds = array_merge(
        $identityRebindRecipeIds,
        array_map('intval', (array)($result['recipe_ids'] ?? []))
    );
    $rebindPin = ingredientOntologyV3CorpusAnnexForScore(
        $identityRebindDb,
        recipeScoreActiveRevision($identityRebindDb)
    );
    if (
        ingredientOntologyV3IdentityProjectionPendingCount(
            $identityRebindDb,
            $versionId
        ) === 0
        && (int)$rebindPin['covered_ontology_source_revision']
            === (int)recipeScoreState($identityRebindDb)[
                'ontology_source_revision'
            ]
    ) {
        break;
    }
}
unset($GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_IDENTITY_ADMISSION']);
$identityRebindRecipeIds = array_values(array_unique(
    $identityRebindRecipeIds
));
sort($identityRebindRecipeIds, SORT_NUMERIC);
$identityRebindDb->prepare("
    DELETE FROM products WHERE id = ?
")->execute([$kabochaProductId]);
$identityDeleteRecipeIds = [];
for ($pass = 0; $pass < 10; $pass++) {
    $result = ingredientOntologyV3IncrementalRebuild(
        $identityRebindDb,
        true
    );
    $identityDeleteRecipeIds = array_merge(
        $identityDeleteRecipeIds,
        array_map('intval', (array)($result['recipe_ids'] ?? []))
    );
    $deletePin = ingredientOntologyV3CorpusAnnexForScore(
        $identityRebindDb,
        recipeScoreActiveRevision($identityRebindDb)
    );
    if (
        ingredientOntologyV3IdentityProjectionPendingCount(
            $identityRebindDb,
            $versionId
        ) === 0
        && (int)$deletePin['covered_ontology_source_revision']
            === (int)recipeScoreState($identityRebindDb)[
                'ontology_source_revision'
            ]
    ) {
        break;
    }
}
$identityDeleteRecipeIds = array_values(array_unique(
    $identityDeleteRecipeIds
));
$assert(
    $identityRebindClaim !== null
    && in_array(
        $kabochaRecipeId,
        $identityRebindRecipeIds,
        true
    )
    && in_array(
        $identityRebindRecipeId,
        $identityRebindRecipeIds,
        true
    )
    && in_array(
        $identityRebindRecipeId,
        $identityDeleteRecipeIds,
        true
    )
    && (int)$identityRebindDb->query("
        SELECT COUNT(*)
        FROM ingredient_ontology_identity_annex_history
        WHERE product_id = {$kabochaProductId}
          AND extension_entity_id IS NOT NULL
    ")->fetchColumn() >= 2,
    'Delete and rebind must selectively reprocess recipes depending '
        . 'on both former and current identity bindings: '
        . ingredientOntologyV3Json([
            'rebind_recipe_ids' => $identityRebindRecipeIds,
            'delete_recipe_ids' => $identityDeleteRecipeIds,
            'results' => $identityRebindResults,
        ])
);
$identityRebindDb = null;

$identityFairnessPath = $path . '.identity-fairness';
$artifacts[] = $identityFairnessPath;
databaseMaintenanceOnlineBackup(
    $identityOnlyPath,
    $identityFairnessPath
);
$identityFairnessDb = $open($identityFairnessPath);
$identityFairnessSnapshot =
    ingredientOntologyV3IdentityExtensionSnapshot(
        $identityFairnessDb,
        $versionId
    );
$identityFairnessExtensionId = (int)$identityFairnessDb->query("
    SELECT extension_entity_id
    FROM ingredient_ontology_identity_annex
    WHERE product_id = {$kabochaProductId}
      AND extension_entity_id IS NOT NULL
")->fetchColumn();
$identityFairnessDb->prepare("
    INSERT INTO recipe_score_identity_projection_events (
        ontology_version_id, event_key,
        required_revision, required_hash,
        extension_entity_id, product_id,
        after_recipe_id, completed
    )
    VALUES (?, 'fairness-fixture', ?, ?, ?, ?, ?, 1)
")->execute([
    $versionId,
    (int)$identityFairnessSnapshot['revision'],
    (string)$identityFairnessSnapshot['hash'],
    $identityFairnessExtensionId,
    $kabochaProductId,
    $kabochaRecipeId,
]);
$identityFairnessEventId =
    (int)$identityFairnessDb->lastInsertId();
$identityFairnessDb->prepare("
    INSERT INTO recipe_score_identity_projection_work (
        ontology_version_id, recipe_id,
        first_event_id, latest_event_id,
        first_required_revision, latest_required_revision,
        latest_required_hash
    )
    VALUES (?, ?, ?, ?, ?, ?, ?)
")->execute([
    $versionId,
    $kabochaRecipeId,
    $identityFairnessEventId,
    $identityFairnessEventId,
    (int)$identityFairnessSnapshot['revision'],
    (int)$identityFairnessSnapshot['revision'],
    (string)$identityFairnessSnapshot['hash'],
]);
$identityFairnessDb->exec("
    INSERT INTO products (name, brand, category)
    VALUES ('Fairness source one', '', 'food');
    INSERT INTO products (name, brand, category)
    VALUES ('Fairness source two', '', 'food')
");
$fairnessPreviousLimit = getenv(
    'RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT'
);
putenv('RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT=1');
$identityFairnessRecipeProgress = false;
$identityFairnessSourceProgress = false;
$identityFairnessResults = [];
for ($pass = 0; $pass < 3; $pass++) {
    $result = ingredientOntologyV3IncrementalRebuild(
        $identityFairnessDb,
        true
    );
    $identityFairnessResults[] = $result;
    $identityFairnessRecipeProgress =
        $identityFairnessRecipeProgress
        || (bool)(array)($result[
            'identity_projection_recipe_ids'
        ] ?? []);
    $identityFairnessSourceProgress =
        $identityFairnessSourceProgress
        || (bool)array_filter(
            (array)($result[
                'corpus_annex_aggregate_keys'
            ] ?? []),
            static fn(string $key): bool =>
                str_starts_with($key, 'product:')
        );
}
if ($fairnessPreviousLimit === false) {
    putenv('RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT');
} else {
    putenv(
        'RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT='
            . $fairnessPreviousLimit
    );
}
$assert(
    $identityFairnessRecipeProgress
    && $identityFairnessSourceProgress,
    'A saturated one-item source lane must still permit bounded '
        . 'identity progress: '
        . ingredientOntologyV3Json($identityFairnessResults)
);
$identityFairnessDb = null;

$identityFanoutPath = $path . '.identity-fanout';
$artifacts[] = $identityFanoutPath;
databaseMaintenanceOnlineBackup($path, $identityFanoutPath);
$identityFanoutDb = $open($identityFanoutPath);
$identityFanoutLabel = 'durable identity fanout';
$identityFanoutCount =
    ingredientOntologyV3IncrementalProductLimit() + 1;
$identityFanoutIngredientIds = [];
$identityFanoutDb->exec('BEGIN IMMEDIATE');
try {
    $insertFanoutRecipe = $identityFanoutDb->prepare("
        INSERT INTO recipe_catalog (
            primary_connector, title, language, cache_expires_at
        )
        VALUES (
            'manual', ?, 'en', datetime('now', '+1 day')
        )
    ");
    $insertFanoutIngredient = $identityFanoutDb->prepare("
        INSERT INTO recipe_ingredients (
            recipe_id, position, raw_text, normalized_name,
            source_is_required, source_is_optional,
            requiredness_source
        )
        VALUES (?, 0, 'Kabocha', 'kabocha', 1, 0,
                'explicit_required')
    ");
    for ($index = 0; $index < $identityFanoutCount; $index++) {
        $insertFanoutRecipe->execute([
            'Identity fanout recipe ' . $index,
        ]);
        $recipeId = (int)$identityFanoutDb->lastInsertId();
        $insertFanoutIngredient->execute([$recipeId]);
        $identityFanoutIngredientIds[] =
            (int)$identityFanoutDb->lastInsertId();
    }
    $identityFanoutDb->exec('COMMIT');
} catch (Throwable $error) {
    $identityFanoutDb->exec('ROLLBACK');
    throw $error;
}
$fanoutSetupResults = [];
for ($pass = 0; $pass < 20; $pass++) {
    $fanoutSetupResults[] =
        ingredientOntologyV3IncrementalRebuild(
            $identityFanoutDb,
            true
        );
    $setupPin = ingredientOntologyV3CorpusAnnexForScore(
        $identityFanoutDb,
        recipeScoreActiveRevision($identityFanoutDb)
    );
    if (
        (int)$identityFanoutDb->query("
            SELECT COUNT(*) FROM recipe_score_pending_recipes
        ")->fetchColumn() === 0
        && $setupPin !== null
        && (int)$setupPin['covered_ontology_source_revision']
            === (int)recipeScoreState($identityFanoutDb)[
                'ontology_source_revision'
            ]
    ) {
        break;
    }
}
$identityFanoutDb->exec("
    DROP TRIGGER recipe_ontology_source_ingredients_update
");
$fanoutPlaceholders = implode(
    ',',
    array_fill(0, count($identityFanoutIngredientIds), '?')
);
ingredientOntologyV3SetReadyMutationGuard(
    $identityFanoutDb,
    true
);
try {
    $identityFanoutDb->prepare("
    UPDATE recipe_ingredients
    SET raw_text = ?, normalized_name = ?
    WHERE id IN ({$fanoutPlaceholders})
")->execute([
    $identityFanoutLabel,
    $identityFanoutLabel,
    ...$identityFanoutIngredientIds,
]);
$identityFanoutDb->prepare("
    INSERT INTO ingredient_ontology_mappings (
        ontology_version_id, owner_type, owner_id,
        owner_fingerprint, source_label, normalized_label,
        language, entity_id, status, confidence,
        mapping_source, evidence_json, attributes_json,
        is_staple, created_at, updated_at
    )
    SELECT ?, 'recipe_ingredient', ingredient.id,
           ?, ?, ?, 'und', NULL, 'unresolved', 0,
           'identity_fanout_fixture', '{}', '{}', 0,
           CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    FROM recipe_ingredients ingredient
    WHERE ingredient.id IN ({$fanoutPlaceholders})
    ON CONFLICT(ontology_version_id, owner_type, owner_id)
    DO UPDATE SET
        owner_fingerprint = excluded.owner_fingerprint,
        source_label = excluded.source_label,
        normalized_label = excluded.normalized_label,
        language = excluded.language,
        entity_id = NULL,
        status = 'unresolved',
        confidence = 0,
        mapping_source = excluded.mapping_source,
        updated_at = CURRENT_TIMESTAMP
    ")->execute([
        $versionId,
        hash('sha256', 'identity-fanout-stale-owner'),
        $identityFanoutLabel,
        $identityFanoutLabel,
        ...$identityFanoutIngredientIds,
    ]);
} finally {
    ingredientOntologyV3SetReadyMutationGuard(
        $identityFanoutDb,
        false
    );
}
recipeSchemaMigrate($identityFanoutDb);
$identityFanoutBefore = ingredientOntologyV3CorpusAnnexForScore(
    $identityFanoutDb,
    recipeScoreActiveRevision($identityFanoutDb)
);
$identityFanoutCoveredBefore = (int)$identityFanoutBefore[
    'covered_identity_extension_revision'
];
$identityFanoutProductName =
    'Durable Identity Fanout Product';
$identityFanoutDb->prepare("
    INSERT INTO products (name, brand, category)
    VALUES (?, '', 'food')
")->execute([$identityFanoutProductName]);
$identityFanoutProductId =
    (int)$identityFanoutDb->lastInsertId();
$identityFanoutDb->prepare("
    INSERT INTO inventory (product_id, location, quantity)
    VALUES (?, 'dispensa', 1)
")->execute([$identityFanoutProductId]);
recipeScoreMarkProductDirty(
    $identityFanoutDb,
    $identityFanoutProductId,
    'identity_fanout_without_recipe_prequeue'
);
$identityFanoutClaim = null;
$GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_IDENTITY_ADMISSION'] =
    static function (
        PDO $hookDb,
        int $hookVersionId,
        array $productIds
    ) use (
        $identityFanoutProductId,
        $identityFanoutLabel,
        &$identityFanoutClaim
    ): void {
        if (!in_array(
            $identityFanoutProductId,
            $productIds,
            true
        )) {
            return;
        }
        $version = ingredientOntologyV3Version(
            $hookDb,
            $hookVersionId
        );
        $annex = $hookDb->prepare("
            SELECT language, owner_fingerprint
            FROM ingredient_ontology_identity_annex
            WHERE product_id = ? AND ontology_version_id = ?
        ");
        $annex->execute([
            $identityFanoutProductId,
            $hookVersionId,
        ]);
        $annex = $annex->fetch(PDO::FETCH_ASSOC);
        if (!$version || !$annex) {
            throw new RuntimeException(
                'identity fanout product evidence is unavailable'
            );
        }
        $identityFanoutClaim =
            ingredientOntologyV3IdentityExtensionClaim(
                $hookDb,
                $version,
                $identityFanoutLabel,
                (string)$annex['language'],
                'identity-fanout-regression',
                true,
                true
            );
        if ($identityFanoutClaim === null) {
            throw new RuntimeException(
                'identity fanout extension was not created'
            );
        }
        $hookDb->prepare("
            UPDATE ingredient_ontology_identity_annex
            SET source_label = ?,
                normalized_label = ?,
                label_id = NULL,
                entity_id = NULL,
                extension_entity_id = ?,
                status = 'accepted',
                admission_source = 'identity_fanout_regression',
                evidence_hash = ?,
                reason = 'identity_fanout_regression',
                updated_at = CURRENT_TIMESTAMP
            WHERE product_id = ?
              AND ontology_version_id = ?
        ")->execute([
            $identityFanoutLabel,
            $identityFanoutLabel,
            (int)$identityFanoutClaim['id'],
            ingredientOntologyV3Hash([
                'test' => 'identity-fanout-regression',
                'product_id' => $identityFanoutProductId,
                'extension_id' =>
                    (int)$identityFanoutClaim['id'],
            ]),
            $identityFanoutProductId,
            $hookVersionId,
        ]);
    };
$identityFanoutResults = [];
$identityFanoutCovered = [];
$identityFanoutConcurrentClaim = null;
$identityFanoutCompaction = null;
$identityFanoutPendingAcrossCompaction = null;
$identityFanoutScoreCompaction = null;
$identityFanoutGenericEventPreserved = false;
for ($pass = 0; $pass < 10; $pass++) {
    $result = ingredientOntologyV3IncrementalRebuild(
        $identityFanoutDb,
        true
    );
    $identityFanoutResults[] = $result;
    $active = recipeScoreActiveRevision($identityFanoutDb);
    $pin = ingredientOntologyV3CorpusAnnexForScore(
        $identityFanoutDb,
        $active
    );
    $identityFanoutCovered[] = [
        'captured' => (int)$pin['identity_extension_revision'],
        'covered' =>
            (int)$pin['covered_identity_extension_revision'],
        'pending' =>
            ingredientOntologyV3IdentityProjectionPendingCount(
                $identityFanoutDb,
                $versionId
            ),
    ];
    if (
        $identityFanoutCompaction === null
        && (int)$identityFanoutCovered[
            array_key_last($identityFanoutCovered)
        ]['pending'] > 0
    ) {
        $genericEvent = $identityFanoutDb->query("
            SELECT id
            FROM recipe_score_identity_projection_events
            WHERE ontology_version_id = {$versionId}
              AND completed = 0
              AND product_id IS NULL
              AND source_revision IS NULL
            ORDER BY id
            LIMIT 1
        ")->fetchColumn();
        $pendingBeforeCompaction =
            ingredientOntologyV3IdentityProjectionPendingCount(
                $identityFanoutDb,
                $versionId
            );
        $identityFanoutCompaction =
            ingredientOntologyV3CompactCorpusProjection(
                $identityFanoutDb,
                true
            );
        $pendingAfterCompaction =
            ingredientOntologyV3IdentityProjectionPendingCount(
                $identityFanoutDb,
                $versionId
            );
        $identityFanoutPendingAcrossCompaction = [
            $pendingBeforeCompaction,
            $pendingAfterCompaction,
        ];
        if ($genericEvent !== false) {
            $identityFanoutScoreCompaction =
                ingredientOntologyV3CompactActiveScores(
                    $identityFanoutDb,
                    true
                );
            $compactedActive = recipeScoreActiveRevision(
                $identityFanoutDb
            );
            $compactedPin = is_array($compactedActive)
                ? ingredientOntologyV3CorpusAnnexForScore(
                    $identityFanoutDb,
                    $compactedActive
                )
                : null;
            if (
                !empty($identityFanoutScoreCompaction['compacted'])
                && is_array($compactedPin)
            ) {
                ingredientOntologyV3IdentityProjectionCompleteEmptyEvents(
                    $identityFanoutDb,
                    $versionId,
                    1000,
                    (string)$compactedPin['resolution_input_hash']
                );
                $genericEventCompleted = $identityFanoutDb->prepare("
                    SELECT completed
                    FROM recipe_score_identity_projection_events
                    WHERE id = ?
                ");
                $genericEventCompleted->execute([(int)$genericEvent]);
                $identityFanoutGenericEventPreserved =
                    (int)$genericEventCompleted->fetchColumn() === 0;
            }
        }
    }
    if ($pass === 0) {
        $identityFanoutConcurrentClaim =
            ingredientOntologyV3IdentityExtensionClaim(
                $identityFanoutDb,
                ingredientOntologyV3Version(
                    $identityFanoutDb,
                    $versionId
                ),
                $identityFanoutLabel,
                'und',
                'identity-fanout-concurrent-revision',
                true,
                true
            );
    }
    $identityFanoutCurrentSnapshot =
        ingredientOntologyV3IdentityExtensionSnapshot(
            $identityFanoutDb,
            $versionId
        );
    if (
        (int)$pin['covered_identity_extension_revision']
            === (int)$identityFanoutCurrentSnapshot['revision']
        && ingredientOntologyV3IdentityProjectionPendingCount(
            $identityFanoutDb,
            $versionId
        ) === 0
    ) {
        break;
    }
}
unset($GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_IDENTITY_ADMISSION']);
$identityFanoutRecipeIds = [];
$identityFanoutPhysicalRows = 0;
$identityFanoutPagesExact = true;
$identityFanoutPageCounts = [];
foreach ($identityFanoutResults as $result) {
    $identityFanoutRecipeIds = array_merge(
        $identityFanoutRecipeIds,
        array_map(
            'intval',
            (array)($result[
                'identity_projection_recipe_ids'
            ] ?? [])
        )
    );
    $identityFanoutPhysicalRows +=
        (int)($result['physical_score_rows'] ?? 0);
    $pageRecipeIds = array_values(array_unique(array_map(
        'intval',
        (array)($result['recipe_ids'] ?? [])
    )));
    $pageProductIds = array_values(array_unique(array_map(
        'intval',
        (array)($result['product_ids'] ?? [])
    )));
    $expectedAggregateCount =
        count($pageRecipeIds) + count($pageProductIds);
    $expectedAggregateKeys = [];
    foreach ($pageProductIds as $productId) {
        $expectedAggregateKeys[
            ingredientOntologyV3CorpusAnnexAggregateKey(
                'product',
                $productId
            )
        ] = true;
    }
    foreach ($pageRecipeIds as $recipeId) {
        $expectedAggregateKeys[
            ingredientOntologyV3CorpusAnnexAggregateKey(
                'recipe',
                $recipeId
            )
        ] = true;
    }
    $actualAggregateKeys = array_values(array_unique(array_map(
        'strval',
        (array)($result[
            'corpus_annex_aggregate_keys'
        ] ?? [])
    )));
    $identityFanoutPagesExact =
        $identityFanoutPagesExact
        && count((array)($result[
            'identity_projection_recipe_ids'
        ] ?? [])) <= ingredientOntologyV3IncrementalProductLimit()
        && (int)($result['physical_score_rows'] ?? -1)
            === count($pageRecipeIds)
        && (int)($result[
            'corpus_annex_aggregate_count'
        ] ?? -1) === count($actualAggregateKeys)
        && !array_filter(
            $actualAggregateKeys,
            static fn(string $key): bool =>
                !isset($expectedAggregateKeys[$key])
        )
        && count($actualAggregateKeys)
            <= ingredientOntologyV3IncrementalProductLimit();
    $identityFanoutPageCounts[] = [
        'recipes' => count($pageRecipeIds),
        'products' => count($pageProductIds),
        'aggregates' => (int)($result[
            'corpus_annex_aggregate_count'
        ] ?? -1),
    ];
}
$identityFanoutUniqueRecipeIds = array_values(array_unique(
    $identityFanoutRecipeIds
));
sort($identityFanoutUniqueRecipeIds, SORT_NUMERIC);
$identityFanoutFinal = ingredientOntologyV3CorpusAnnexForScore(
    $identityFanoutDb,
    recipeScoreActiveRevision($identityFanoutDb)
);
$identityFanoutHeadCount = $identityFanoutDb->prepare("
    SELECT COUNT(DISTINCT aggregate_id)
    FROM ingredient_ontology_corpus_annex_effective_aggregates
    WHERE ontology_version_id = ?
      AND aggregate_type = 'recipe'
      AND aggregate_id IN (
          SELECT recipe_id
          FROM recipe_ingredients
          WHERE id IN ({$fanoutPlaceholders})
      )
      AND head_revision_id > ?
");
$identityFanoutHeadCount->execute([
    $versionId,
    ...$identityFanoutIngredientIds,
    (int)$identityFanoutBefore['id'],
]);
$identityFanoutHeadCountValue =
    (int)$identityFanoutHeadCount->fetchColumn();
$identityFanoutPendingRecipeCount =
    (int)$identityFanoutDb->query("
        SELECT COUNT(*) FROM recipe_score_pending_recipes
    ")->fetchColumn();
$identityFanoutCapturedRevisions = array_unique(array_column(
    $identityFanoutCovered,
    'captured'
));
$identityFanoutPartialFenceHeld = (bool)array_filter(
    $identityFanoutCovered,
    static fn(array $page): bool =>
        (int)$page['pending'] > 0
        && (int)$page['covered'] < (int)$page['captured']
);
$assert(
    $identityFanoutClaim !== null
    && $identityFanoutConcurrentClaim !== null
    && !empty($identityFanoutCompaction['compacted'])
    && !empty($identityFanoutScoreCompaction['compacted'])
    && $identityFanoutGenericEventPreserved
    && $identityFanoutPendingAcrossCompaction[0] > 0
    && $identityFanoutPendingAcrossCompaction[1]
        === $identityFanoutPendingAcrossCompaction[0]
    && $identityFanoutPendingRecipeCount === 0
    && count($identityFanoutResults) >= 2
    && count($identityFanoutUniqueRecipeIds)
        === $identityFanoutCount
    && count($identityFanoutRecipeIds)
        >= $identityFanoutCount
    && $identityFanoutPagesExact
    && $identityFanoutHeadCountValue === $identityFanoutCount
    && min(array_column(
        $identityFanoutCovered,
        'covered'
    )) >= $identityFanoutCoveredBefore
    && $identityFanoutPartialFenceHeld
    && count($identityFanoutCapturedRevisions) >= 2
    && (int)$identityFanoutFinal[
        'covered_identity_extension_revision'
    ] === (int)$identityFanoutFinal[
        'identity_extension_revision'
    ]
    && ingredientOntologyV3IdentityProjectionPendingCount(
        $identityFanoutDb,
        $versionId
    ) === 0,
    'Identity fan-out above the configured limit must drain durably '
        . 'across pages without pre-queued recipe IDs or an early '
        . 'covered fence: '
        . ingredientOntologyV3Json([
            'setup_passes' => count($fanoutSetupResults),
            'fanout_passes' => count($identityFanoutResults),
            'covered' => $identityFanoutCovered,
            'unique_recipe_count' =>
                count($identityFanoutUniqueRecipeIds),
            'physical_rows' => $identityFanoutPhysicalRows,
            'page_counts' => $identityFanoutPageCounts,
            'compaction' => $identityFanoutCompaction,
            'score_compaction' =>
                $identityFanoutScoreCompaction,
            'generic_event_preserved' =>
                $identityFanoutGenericEventPreserved,
            'pending_across_compaction' =>
                $identityFanoutPendingAcrossCompaction,
            'pages_exact' => $identityFanoutPagesExact,
            'selection_count' => count($identityFanoutRecipeIds),
            'head_count' => $identityFanoutHeadCountValue,
            'pending_recipe_count' =>
                $identityFanoutPendingRecipeCount,
            'final_pending_identity' =>
                ingredientOntologyV3IdentityProjectionPendingCount(
                    $identityFanoutDb,
                    $versionId
                ),
        ])
);
$fanoutPreviousLimit = getenv(
    'RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT'
);
putenv('RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT=10');
$identityFanoutDb->prepare("
    INSERT OR IGNORE INTO recipe_score_match_contributors (
        score_revision_id, recipe_ingredient_id,
        recipe_id, product_id, semantic
    )
    SELECT source.score_revision_id, ingredient.id,
           ingredient.recipe_id, ?, 1
    FROM recipe_ingredients ingredient
    JOIN recipe_score_effective_sources source
      ON source.recipe_id = ingredient.recipe_id
    WHERE ingredient.id IN ({$fanoutPlaceholders})
")->execute([
    $identityFanoutProductId,
    ...$identityFanoutIngredientIds,
]);
recipeScoreMarkProductDirty(
    $identityFanoutDb,
    $identityFanoutProductId,
    'paged_contributor_fanout_regression'
);
$contributorFanoutRecipeIds = [];
$contributorFanoutObservedCursor = false;
$contributorFanoutPartialStatus = false;
$contributorFanoutResults = [];
for ($pass = 0; $pass < 80; $pass++) {
    $result = ingredientOntologyV3IncrementalRebuild(
        $identityFanoutDb,
        true
    );
    $contributorFanoutResults[] = $result;
    $fanoutRevisionId = (int)($result['revision_id'] ?? 0);
    $fanoutPageRecipeIds = $fanoutRevisionId > 0
        ? array_map(
            'intval',
            $identityFanoutDb->query("
                SELECT recipe_id
                FROM recipe_score_recipe_operations
                WHERE score_revision_id = {$fanoutRevisionId}
                ORDER BY recipe_id
            ")->fetchAll(PDO::FETCH_COLUMN)
        )
        : [];
    $contributorFanoutRecipeIds = array_merge(
        $contributorFanoutRecipeIds,
        $fanoutPageRecipeIds
    );
    $contributorFanoutObservedCursor =
        $contributorFanoutObservedCursor
        || (bool)$identityFanoutDb->query("
            SELECT 1
            FROM recipe_score_product_fanout_state
            WHERE product_id = {$identityFanoutProductId}
              AND after_recipe_id > 0
        ")->fetchColumn();
    if ($contributorFanoutObservedCursor) {
        $contributorFanoutPartialStatus =
            $contributorFanoutPartialStatus
            || recipeScoreRevisionStatus(
                $identityFanoutDb,
                recipeScoreActiveRevision($identityFanoutDb)
            ) === 'partial';
    }
    if (
        !(bool)$identityFanoutDb->query("
            SELECT 1
            FROM recipe_score_product_fanout_state
            WHERE product_id = {$identityFanoutProductId}
        ")->fetchColumn()
        && !(bool)$identityFanoutDb->query("
            SELECT 1
            FROM recipe_score_pending_products
            WHERE product_id = {$identityFanoutProductId}
        ")->fetchColumn()
    ) {
        break;
    }
}
if ($fanoutPreviousLimit === false) {
    putenv('RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT');
} else {
    putenv(
        'RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT='
            . $fanoutPreviousLimit
    );
}
$contributorFanoutRecipeIds = array_values(array_unique(
    $contributorFanoutRecipeIds
));
$contributorFanoutRemainingState =
    (bool)$identityFanoutDb->query("
        SELECT 1
        FROM recipe_score_product_fanout_state
        WHERE product_id = {$identityFanoutProductId}
    ")->fetchColumn();
$contributorFanoutMaximumAffected = max(array_map(
    static fn(array $result): int => (int)(
        $result['affected_recipe_count'] ?? 0
    ),
    $contributorFanoutResults
));
$assert(
    $contributorFanoutObservedCursor
    && $contributorFanoutPartialStatus
    && count($contributorFanoutRecipeIds)
        === $identityFanoutCount
    && !$contributorFanoutRemainingState
    && $contributorFanoutMaximumAffected <= 10,
    'Materialized product contributors must drain through a durable '
        . 'bounded cursor rather than one all-recipe PHP fan-out: '
        . ingredientOntologyV3Json([
            'unique_recipe_count' =>
                count($contributorFanoutRecipeIds),
            'passes' => count($contributorFanoutResults),
            'observed_cursor' =>
                $contributorFanoutObservedCursor,
            'partial_status' =>
                $contributorFanoutPartialStatus,
            'remaining_state' =>
                $contributorFanoutRemainingState,
            'maximum_affected' =>
                $contributorFanoutMaximumAffected,
        ])
);
$identityFanoutDb = null;

$annex = ingredientOntologyV3CorpusAnnexForScore(
    $db,
    recipeScoreActiveRevision($db)
);
$compaction = ingredientOntologyV3CompactActiveScores($db, true);
$compacted = recipeScoreActiveRevision($db);
$assert(
    !empty($compaction['compacted'])
    && (int)$compacted['corpus_annex_revision_id']
        === (int)$annex['id']
    && hash_equals(
        (string)$compacted['corpus_annex_hash'],
        (string)$annex['revision_hash']
    ),
    'Score compaction must preserve the exact corpus annex pin: '
        . ingredientOntologyV3Json([
            'compaction' => $compaction,
            'compacted' => $compacted,
            'annex' => $annex,
        ])
);
$projectionCompactionPath = $path . '.projection-compaction';
$artifacts[] = $projectionCompactionPath;
databaseMaintenanceOnlineBackup($path, $projectionCompactionPath);
$projectionCompactionDb = $open($projectionCompactionPath);
$projectionParent = recipeScoreActiveRevision($projectionCompactionDb);
if ($projectionParent === null) {
    throw new RuntimeException(
        'Projection checkpoint fixture lost its active score: '
            . ingredientOntologyV3Json([
                'state' => recipeScoreState($projectionCompactionDb),
                'ready_scores' => (int)$projectionCompactionDb->query("
                    SELECT COUNT(*) FROM recipe_score_revisions
                    WHERE status = 'ready'
                ")->fetchColumn(),
            ])
    );
}
$projectionParentPin = ingredientOntologyV3CorpusAnnexForScore(
    $projectionCompactionDb,
    $projectionParent
);
$projectionContentBefore =
    ingredientOntologyV3CorpusAnnexEffectiveContentHash(
        $projectionCompactionDb,
        $versionId
    );
$projectionSourcesBefore = ingredientOntologyV3HashMaterializedRows(
    $projectionCompactionDb,
    "
        SELECT recipe_id, score_revision_id
        FROM recipe_score_effective_sources
        ORDER BY recipe_id
    ",
    [],
    static fn(array $row): array => [
        'recipe_id' => (int)$row['recipe_id'],
        'score_revision_id' => (int)$row['score_revision_id'],
    ]
);
$GLOBALS[
    'INGREDIENT_ONTOLOGY_CORPUS_ANNEX_COMPACTION_DEPTH_OVERRIDE'
] = 1;
$projectionCompaction =
    ingredientOntologyV3CorpusProjectionV2Compact(
        $projectionCompactionDb
    );
unset(
    $GLOBALS[
        'INGREDIENT_ONTOLOGY_CORPUS_ANNEX_COMPACTION_DEPTH_OVERRIDE'
    ]
);
if (empty($projectionCompaction['compacted'])) {
    throw new RuntimeException(
        'Projection checkpoint compaction failed: '
            . ingredientOntologyV3Json($projectionCompaction)
    );
}
$projectionChild = recipeScoreActiveRevision($projectionCompactionDb);
$projectionRoot = ingredientOntologyV3CorpusAnnexForScore(
    $projectionCompactionDb,
    $projectionChild
);
$projectionChildId = (int)$projectionChild['id'];
$projectionRootId = (int)$projectionRoot['id'];
$projectionCompactionDb = null;
$projectionCompactionDb = $open($projectionCompactionPath);
$projectionChild = recipeScoreRevision(
    $projectionCompactionDb,
    $projectionChildId
);
$projectionRoot = ingredientOntologyV3CorpusAnnexRevision(
    $projectionCompactionDb,
    $projectionRootId
);
$projectionAudit =
    ingredientOntologyV3CorpusProjectionV2IntegrityAudit(
        $projectionCompactionDb,
        (int)$projectionRoot['id'],
        (string)$projectionRoot['revision_hash'],
        true
    );
$projectionPhysicalRows = $projectionCompactionDb->prepare("
    SELECT COUNT(*)
    FROM recipe_inventory_scores
    WHERE score_revision_id = ?
");
$projectionPhysicalRows->execute([(int)$projectionChild['id']]);
$projectionPhysicalRowCount =
    (int)$projectionPhysicalRows->fetchColumn();
$projectionSourcesAfter = ingredientOntologyV3HashMaterializedRows(
    $projectionCompactionDb,
    "
        SELECT recipe_id, score_revision_id
        FROM recipe_score_effective_sources
        ORDER BY recipe_id
    ",
    [],
    static fn(array $row): array => [
        'recipe_id' => (int)$row['recipe_id'],
        'score_revision_id' => (int)$row['score_revision_id'],
    ]
);
$projectionRollback = ingredientOntologyV3Rollback(
    $projectionCompactionDb,
    (int)$projectionParent['id'],
    (int)$projectionChild['id']
);
$projectionRolledBack =
    recipeScoreActiveRevision($projectionCompactionDb);
$projectionContentAfter =
    ingredientOntologyV3CorpusAnnexEffectiveContentHash(
        $projectionCompactionDb,
        $versionId
    );
$projectionChecks = [
    'compacted' => !empty($projectionCompaction['compacted']),
    'root_is_checkpoint' =>
        $projectionRoot['parent_revision_id'] === null,
    'root_changed' =>
        (int)$projectionRoot['id']
            !== (int)$projectionParentPin['id'],
    'score_parent' =>
        (int)$projectionChild['parent_score_revision_id']
            === (int)$projectionParent['id'],
    'score_is_sparse' =>
        recipeScoreRevisionIsSparseDelta($projectionChild),
    'zero_physical_rows' => $projectionPhysicalRowCount === 0,
    'effective_source_count' =>
        (int)$projectionSourcesBefore['count']
            === (int)$projectionSourcesAfter['count'],
    'effective_source_hash' => hash_equals(
        (string)$projectionSourcesBefore['hash'],
        (string)$projectionSourcesAfter['hash']
    ),
    'content_hash' => hash_equals(
        $projectionContentBefore,
        $projectionContentAfter
    ),
    'audit' => !empty($projectionAudit['valid']),
    'rollback' => !empty($projectionRollback['rolled_back']),
    'rollback_score' =>
        (int)$projectionRolledBack['id']
            === (int)$projectionParent['id'],
    'rollback_pin' =>
        (int)$projectionRolledBack['corpus_annex_revision_id']
            === (int)$projectionParentPin['id'],
];
$assert(
    !in_array(false, $projectionChecks, true),
    'Projection checkpoint compaction must preserve effective content '
        . 'and score hashes, publish a zero-score child, and roll back: '
        . ingredientOntologyV3Json([
            'checks' => $projectionChecks,
            'result' => $projectionCompaction,
            'audit_errors' =>
                (array)($projectionAudit['errors'] ?? []),
            'rollback_result' => $projectionRollback,
        ])
);
$projectionCompactionDb = null;

$readPurityPath = $path . '.read-purity';
$artifacts[] = $readPurityPath;
databaseMaintenanceOnlineBackup($path, $readPurityPath);
$readPurityWriteDb = $open($readPurityPath);
$readPurityActive = recipeScoreActiveRevision($readPurityWriteDb);
$readPurityPin = ingredientOntologyV3CorpusAnnexForScore(
    $readPurityWriteDb,
    $readPurityActive
);
$readPuritySnapshot =
    evershelfProcessingStatusRefreshMaterialized(
        $readPurityWriteDb,
        0
    );
ingredientOntologyV3SetReadyMutationGuard($readPurityWriteDb, true);
$readPurityWriteDb->prepare("
    DELETE FROM ingredient_ontology_corpus_annex_projection_state
    WHERE ontology_version_id = ?
")->execute([$versionId]);
ingredientOntologyV3SetReadyMutationGuard($readPurityWriteDb, false);
$readPurityDb = $open($readPurityPath);
$readPurityDb->exec('PRAGMA query_only=ON');
$readChangesBefore = (int)$readPurityDb->query(
    'SELECT total_changes()'
)->fetchColumn();
$readPurityStatus =
    ingredientOntologyV3CorpusProjectionV2Status($readPurityDb);
$readPurityScoreStatus = recipeScoreRevisionStatus(
    $readPurityDb,
    recipeScoreActiveRevision($readPurityDb)
);
$readPurityBrowse = recipeCatalogBrowseResult(
    $readPurityDb,
    ['limit' => 1, 'fields' => 'card']
);
$readPurityProcessing =
    evershelfProcessingStatusScores($readPurityDb);
$readPurityFullStarted = hrtime(true);
$readPurityFullStatus =
    evershelfProcessingStatus($readPurityDb);
$readPurityFullElapsedMs =
    (hrtime(true) - $readPurityFullStarted) / 1000000;
$readChangesAfter = (int)$readPurityDb->query(
    'SELECT total_changes()'
)->fetchColumn();
$readPurityDb = null;
$projectionStateCount = (int)$readPurityWriteDb->query("
    SELECT COUNT(*)
    FROM ingredient_ontology_corpus_annex_projection_state
    WHERE ontology_version_id = {$versionId}
")->fetchColumn();
$assert(
    $readChangesAfter === $readChangesBefore
    && $projectionStateCount === 0
    && !empty($readPurityStatus['repair_needed'])
    && (string)$readPurityStatus['drift_reason']
        === 'projection_repair_required'
    && $readPurityScoreStatus === 'partial'
    && (string)$readPurityBrowse['kind'] === 'browse'
    && !empty($readPuritySnapshot['refreshed'])
    && !empty(
        $readPurityFullStatus[
            'recipe_source_ontology'
        ]['materialized']
    )
    && !empty(
        $readPurityFullStatus[
            'identity_admission'
        ]['materialized']
    )
    && $readPurityFullElapsedMs < 1000
    && !empty(
        $readPurityProcessing['corpus_annex']['repair_needed']
    ),
    'Browse, resolve, and processing status must remain query-only '
        . 'when the derived corpus projection is stale: '
        . ingredientOntologyV3Json([
            'changes_before' => $readChangesBefore,
            'changes_after' => $readChangesAfter,
            'projection_state_count' => $projectionStateCount,
            'projection_status' => $readPurityStatus,
            'score_status' => $readPurityScoreStatus,
            'browse_kind' => $readPurityBrowse['kind'] ?? null,
            'processing_projection' =>
                $readPurityProcessing['corpus_annex'] ?? null,
            'full_status_elapsed_ms' =>
                round($readPurityFullElapsedMs, 3),
        ])
);
$repairActiveId = (int)recipeScoreState(
    $readPurityWriteDb
)['active_score_revision_id'];
$projectionRepair = ingredientOntologyV3IncrementalRebuild(
    $readPurityWriteDb,
    true
);
$assert(
    !empty($projectionRepair['rebuilt'])
    && (string)$projectionRepair['reason']
        === 'corpus_projection_repaired'
    && (int)recipeScoreState(
        $readPurityWriteDb
    )['active_score_revision_id'] === $repairActiveId
    && ingredientOntologyV3CorpusAnnexProjectionReady(
        $readPurityWriteDb,
        $readPurityPin
    ),
    'The maintenance worker must deep-verify and repair '
        . 'a stale derived corpus projection'
);
$readPurityWriteDb = null;

$upgradePath = $path . '.projection-upgrade';
$artifacts[] = $upgradePath;
databaseMaintenanceOnlineBackup($identityOnlyPath, $upgradePath);
$upgradeDb = $open($upgradePath);
$upgradeActive = recipeScoreActiveRevision($upgradeDb);
$upgradeDb->exec('BEGIN IMMEDIATE');
try {
    $upgradeDb->exec(
        'DELETE FROM recipe_score_effective_sources'
    );
    $upgradeDb->prepare("
        UPDATE recipe_score_state
        SET active_score_projection_revision_id = NULL
        WHERE id = 1
          AND active_score_revision_id = ?
    ")->execute([(int)$upgradeActive['id']]);
    $upgradeDb->exec('COMMIT');
} catch (Throwable $error) {
    $upgradeDb->exec('ROLLBACK');
    throw $error;
}
recipeSchemaMigrate($upgradeDb);
$upgradeDeferredCount = (int)$upgradeDb->query("
    SELECT COUNT(*) FROM recipe_score_effective_sources
")->fetchColumn();
$upgradeDeferredState = recipeScoreState($upgradeDb);
$upgradeDeferredStatus = $upgradeDb->query("
    SELECT score_projection_repair_pending, repair_needed,
           verdict
    FROM recipe_score_projection_status
    WHERE id = 1
")->fetch(PDO::FETCH_ASSOC) ?: [];
$upgradeScoreStatus = recipeScoreRevisionStatus(
    $upgradeDb,
    $upgradeActive
);
$upgradeBrowseUnavailable = false;
try {
    recipeCatalogBrowseResult(
        $upgradeDb,
        ['limit' => 1, 'fields' => 'card']
    );
} catch (RuntimeException $error) {
    $upgradeBrowseUnavailable = str_contains(
        $error->getMessage(),
        'projection is temporarily unavailable'
    );
}
$upgradeProcessingStatus =
    evershelfProcessingStatusScores($upgradeDb);
$upgradeRepair = ingredientOntologyV3IncrementalRebuild(
    $upgradeDb,
    true
);
$upgradeRepairedState = recipeScoreState($upgradeDb);
$upgradeRepairedCount = (int)$upgradeDb->query("
    SELECT COUNT(*) FROM recipe_score_effective_sources
")->fetchColumn();
$assert(
    $upgradeDeferredCount === 0
    && $upgradeDeferredState[
        'active_score_projection_revision_id'
    ] === null
    && (int)($upgradeDeferredStatus[
        'score_projection_repair_pending'
    ] ?? 0) === 1
    && (int)($upgradeDeferredStatus['repair_needed'] ?? 0) === 1
    && (string)($upgradeDeferredStatus['verdict'] ?? '')
        === 'score_projection_repair_pending'
    && $upgradeScoreStatus === 'stale'
    && $upgradeBrowseUnavailable
    && !empty($upgradeProcessingStatus['stale'])
    && !empty($upgradeRepair['rebuilt'])
    && (string)$upgradeRepair['reason']
        === 'score_projection_repaired'
    && (int)$upgradeRepairedState[
        'active_score_projection_revision_id'
    ] === (int)$upgradeActive['id']
    && $upgradeRepairedCount === (int)$upgradeActive['recipe_count'],
    'Legacy migration must defer effective score projection work '
        . 'and let the worker repair it: '
        . ingredientOntologyV3Json([
            'deferred_count' => $upgradeDeferredCount,
            'deferred_state' => $upgradeDeferredState,
            'deferred_status' => $upgradeDeferredStatus,
            'score_status' => $upgradeScoreStatus,
            'browse_unavailable' => $upgradeBrowseUnavailable,
            'processing_status' => $upgradeProcessingStatus,
            'repair' => $upgradeRepair,
            'repaired_count' => $upgradeRepairedCount,
        ])
);
$upgradeDb = null;

$annexEntryCount = (int)$db->query("
    SELECT COUNT(*)
    FROM ingredient_ontology_corpus_annex_entries
    WHERE corpus_annex_revision_id = " . (int)$annex['id']
)->fetchColumn();
recipeScorePruneRevisions($db);
$assert(
    (int)$db->query("
        SELECT COUNT(*)
        FROM ingredient_ontology_corpus_annex_entries
        WHERE corpus_annex_revision_id = " . (int)$annex['id']
    )->fetchColumn() === $annexEntryCount,
    'Score pruning must not delete corpus annex evidence'
);

$rollbackTarget = recipeScoreRevision($db, $baselineScoreId);
$rollbackTargetPin = ingredientOntologyV3CorpusAnnexForScore(
    $db,
    $rollbackTarget
);
$rollback = ingredientOntologyV3Rollback(
    $db,
    $baselineScoreId,
    (int)$compacted['id']
);
$rolledBack = recipeScoreActiveRevision($db);
$assert(
    !empty($rollback['rolled_back'])
    && (int)$rolledBack['corpus_annex_revision_id']
        === (int)$rollbackTargetPin['id']
    && hash_equals(
        (string)$rolledBack['corpus_annex_hash'],
        (string)$rollbackTargetPin['revision_hash']
    ),
    'Rollback must restore the target score and annex prefix: '
        . ingredientOntologyV3Json([
            'result' => $rollback,
            'active' => $rolledBack,
            'expected_score_id' => $baselineScoreId,
            'expected_annex_id' => (int)$rollbackTargetPin['id'],
        ])
);

$copyPath = $path . '.pre-watermark';
$artifacts[] = $copyPath;
databaseMaintenanceOnlineBackup($path, $copyPath);
$copy = $open($copyPath);
$copy->prepare("
    UPDATE recipe_ingredients
    SET normalized_name = 'changed baseline'
    WHERE id = ?
")->execute([$baselineIngredientId]);
$preWatermarkDecision =
    ingredientOntologyV3CorpusProjectionV2DriftDecision($copy);
$assert(
    !empty($preWatermarkDecision['handled'])
    && empty($preWatermarkDecision['requires_full_seal'])
    && !ingredientOntologyActivationOntologyStateRequiresBuild(
        $copy
    )
    && !ingredientOntologyActivationNeedsReviewedManifestRefresh(
        $copy
    ),
    'A checkpoint-era update must remain selectively reconcilable: '
        . ingredientOntologyV3Json($preWatermarkDecision)
);
$copy = null;

$deletePath = $path . '.pre-watermark-delete';
$artifacts[] = $deletePath;
databaseMaintenanceOnlineBackup($path, $deletePath);
$deleteDb = $open($deletePath);
$deleteDb->prepare("
    DELETE FROM recipe_ingredients
    WHERE id = ?
")->execute([$baselineIngredientId]);
$deleteDecision =
    ingredientOntologyV3CorpusProjectionV2DriftDecision($deleteDb);
$assert(
    !empty($deleteDecision['handled'])
    && empty($deleteDecision['requires_full_seal']),
    'A checkpoint-era delete must remain selectively reconcilable'
);
$deleteDb = null;

$globalPath = $path . '.global';
$artifacts[] = $globalPath;
databaseMaintenanceOnlineBackup($identityOnlyPath, $globalPath);
$global = $open($globalPath);
$globalBaseline = recipeScoreActiveRevision($global);
$global->prepare("
    UPDATE recipe_score_state
    SET catalog_revision = ?
    WHERE id = 1
")->execute([(int)$globalBaseline['catalog_revision']]);
$global->exec("
    DELETE FROM recipe_score_pending_recipes;
    DELETE FROM recipe_score_mutations WHERE domain = 'catalog';
");
$global->exec("
    UPDATE recipe_score_state
    SET ontology_source_revision =
            ontology_source_revision + 1,
        ontology_source_hash = ''
    WHERE id = 1
");
$globalRevision = (int)recipeScoreState($global)[
    'ontology_source_revision'
];
$global->prepare("
    INSERT INTO recipe_score_mutations (
        domain, revision, lane, owner_type, owner_id,
        operation, reason
    )
    VALUES (
        'source', ?, 'maintenance', 'global', NULL,
        'global', 'corpus_annex_global_fixture'
    )
")->execute([$globalRevision]);
$globalEvidence = [
    'event' => (int)$global->query("
        SELECT COUNT(*)
        FROM recipe_score_source_reconciliation_events
        WHERE source_revision = {$globalRevision}
    ")->fetchColumn(),
    'scope' => (int)$global->query("
        SELECT COUNT(*)
        FROM recipe_score_source_reconciliation_scopes
        WHERE source_revision = {$globalRevision}
    ")->fetchColumn(),
];
$global->prepare("
    DELETE FROM recipe_score_mutations
    WHERE domain = 'source' AND revision = ?
")->execute([$globalRevision]);
$globalDecision =
    ingredientOntologyV3CorpusProjectionV2DriftDecision($global);
$globalPlan = ingredientOntologyV3CorpusProjectionV2Classify(
    $global,
    recipeScoreActiveRevision($global),
    recipeScoreState($global),
    false
);
$globalResult = ingredientOntologyV3IncrementalRebuild($global, true);
$assert(
    !empty($globalDecision['handled'])
    && empty($globalDecision['requires_full_seal'])
    && $globalEvidence === ['event' => 1, 'scope' => 0]
    && !empty($globalPlan['eligible'])
    && (string)$globalPlan['reconciliation_mode']
        === 'authoritative'
    && !empty($globalResult['rebuilt'])
    && !ingredientOntologyActivationOntologyStateRequiresBuild(
        $global
    ),
    'A recoverable global source mutation must use authoritative '
        . 'aggregate reconciliation without an ontology seal: '
        . ingredientOntologyV3Json([
            'decision' => $globalDecision,
            'plan' => $globalPlan,
            'result' => $globalResult,
            'durable_evidence' => $globalEvidence,
        ])
);
$global = null;

$scopeBackfillPath = $path . '.scope-backfill';
$artifacts[] = $scopeBackfillPath;
databaseMaintenanceOnlineBackup($path, $scopeBackfillPath);
$scopeBackfillDb = $open($scopeBackfillPath);
$scopeBackfillDb->exec("
    DROP TRIGGER recipe_score_source_reconciliation_event_capture;
    DROP TRIGGER recipe_score_source_reconciliation_scope_capture
");
$scopeBackfillDb->exec('BEGIN IMMEDIATE');
try {
    $scopeBackfillDb->exec("
        UPDATE recipe_score_state
        SET ontology_source_revision =
                ontology_source_revision + 1,
            ontology_source_hash = ''
        WHERE id = 1
    ");
    $scopeBackfillRevision = (int)recipeScoreState(
        $scopeBackfillDb
    )['ontology_source_revision'];
    $scopeBackfillDb->prepare("
        INSERT INTO recipe_score_mutations (
            domain, revision, lane, owner_type, owner_id,
            operation, reason, source_table, source_row_id
        )
        VALUES (
            'source', ?, 'maintenance', 'recipe', ?,
            'update', 'historical_recipe_reparent',
            'recipe_ingredients', 1
        )
    ")->execute([
        $scopeBackfillRevision,
        $kabochaRecipeId,
    ]);
    $scopeBackfillMutationId =
        (int)$scopeBackfillDb->lastInsertId();
    $scopeBackfillDb->prepare("
        INSERT INTO recipe_score_mutation_scopes (
            mutation_id, ordinal, aggregate_type, aggregate_id,
            scope_role, source_table, source_row_id,
            source_key, metadata_json
        )
        VALUES (
            ?, 2, 'recipe', ?, 'before',
            'recipe_ingredients', 1, '', '{}'
        )
    ")->execute([
        $scopeBackfillMutationId,
        $baselineRecipeId,
    ]);
    $scopeBackfillDb->exec("
        UPDATE recipe_score_source_reconciliation_backfill
        SET last_mutation_id = 0,
            complete = 0,
            scope_backfill_version = 0,
            scope_backfill_started = 0
        WHERE id = 1
    ");
    $scopeBackfillDb->exec('COMMIT');
} catch (Throwable $error) {
    $scopeBackfillDb->exec('ROLLBACK');
    throw $error;
}
do {
    $scopeBackfillResult =
        ingredientOntologyV3CorpusAnnexReconciliationBackfill(
            $scopeBackfillDb,
            5000
        );
} while (empty($scopeBackfillResult['complete']));
$scopeBackfillDurableCount = $scopeBackfillDb->prepare("
    SELECT COUNT(*)
    FROM recipe_score_source_reconciliation_scopes
    WHERE source_revision = ?
");
$scopeBackfillDurableCount->execute([$scopeBackfillRevision]);
$scopeBackfillDurableCount =
    (int)$scopeBackfillDurableCount->fetchColumn();
$scopeBackfillDb->prepare("
    DELETE FROM recipe_score_mutations WHERE id = ?
")->execute([$scopeBackfillMutationId]);
$scopeBackfillWindow =
    ingredientOntologyV3CorpusAnnexDurableScopeWindow(
        $scopeBackfillDb,
        $scopeBackfillRevision - 1,
        $scopeBackfillRevision
    );
$scopeBackfillScopes =
    ingredientOntologyV3CorpusAnnexEventScopes(
        $scopeBackfillDb,
        (array)$scopeBackfillWindow['events'],
        $versionId,
        10
    );
$scopeBackfillRecipeIds = array_map(
    'intval',
    array_keys((array)$scopeBackfillScopes['recipe'])
);
sort($scopeBackfillRecipeIds, SORT_NUMERIC);
$scopeBackfillExpectedRecipeIds = [
    $baselineRecipeId,
    $kabochaRecipeId,
];
sort($scopeBackfillExpectedRecipeIds, SORT_NUMERIC);
$scopeBackfillDb->prepare("
    DELETE FROM recipe_score_source_reconciliation_scopes
    WHERE source_revision = ? AND ordinal = 2
")->execute([$scopeBackfillRevision]);
$scopeMissingWindow =
    ingredientOntologyV3CorpusAnnexDurableScopeWindow(
        $scopeBackfillDb,
        $scopeBackfillRevision - 1,
        $scopeBackfillRevision
    );
$scopeMissingScopes =
    ingredientOntologyV3CorpusAnnexEventScopes(
        $scopeBackfillDb,
        (array)$scopeMissingWindow['events'],
        $versionId,
        10
    );
$assert(
    $scopeBackfillDurableCount === 2
    && !empty($scopeBackfillWindow['available'])
    && empty($scopeBackfillScopes['authoritative'])
    && $scopeBackfillRecipeIds
        === $scopeBackfillExpectedRecipeIds
    && !empty($scopeMissingWindow['available'])
    && !empty($scopeMissingScopes['authoritative'])
    && !(array)$scopeMissingScopes['recipe'],
    'Historical reconciliation backfill must preserve both re-parent '
        . 'scopes, while missing durable scope evidence fails closed '
        . 'instead of silently trusting the new owner'
);
$scopeBackfillDb = null;

$gcPath = $path . '.reconciliation-gc';
$artifacts[] = $gcPath;
databaseMaintenanceOnlineBackup($path, $gcPath);
$gcDb = $open($gcPath);
do {
    $gcBackfill =
        ingredientOntologyV3CorpusAnnexReconciliationBackfill(
            $gcDb,
            5000
        );
} while (empty($gcBackfill['complete']));
$gcSafeRevision = (int)$gcDb->query("
    SELECT MIN(annex.covered_ontology_source_revision)
    FROM recipe_score_revisions score
    JOIN ingredient_ontology_corpus_annex_revisions annex
      ON annex.id = score.corpus_annex_revision_id
     AND annex.revision_hash = score.corpus_annex_hash
    WHERE score.status IN ('building', 'ready')
")->fetchColumn();
$gcNeededRevision = $gcSafeRevision + 1000000;
$gcInsertEvent = $gcDb->prepare("
    INSERT OR REPLACE INTO
        recipe_score_source_reconciliation_events (
        source_revision, event_lane, event_owner_type,
        event_owner_id, event_operation, event_reason,
        source_table, source_row_id
    )
    VALUES (?, 'maintenance', 'global', NULL,
            'global', ?, 'gc_fixture', NULL)
");
$gcInsertEvent->execute([
    $gcSafeRevision,
    'gc_obsolete_fixture',
]);
$gcInsertEvent->execute([
    $gcNeededRevision,
    'gc_needed_fixture',
]);
$gcInsertScope = $gcDb->prepare("
    INSERT OR REPLACE INTO
        recipe_score_source_reconciliation_scopes (
        source_revision, ordinal, event_lane,
        event_owner_type, event_owner_id,
        event_operation, event_reason,
        aggregate_type, aggregate_id, scope_role,
        source_table, source_row_id, source_key, metadata_json
    )
    VALUES (
        ?, 1, 'maintenance', 'global', NULL,
        'global', ?, 'recipe', ?, 'affected',
        'gc_fixture', NULL, '', '{}'
    )
");
$gcInsertScope->execute([
    $gcSafeRevision,
    'gc_obsolete_fixture',
    $kabochaRecipeId,
]);
$gcInsertScope->execute([
    $gcNeededRevision,
    'gc_needed_fixture',
    $kabochaRecipeId,
]);
$gcResult = ingredientOntologyV3CorpusAnnexReconciliationGc(
    $gcDb,
    5000
);
$gcRemaining = $gcDb->query("
    SELECT source_revision
    FROM recipe_score_source_reconciliation_events
    WHERE source_revision IN (
        {$gcSafeRevision}, {$gcNeededRevision}
    )
    ORDER BY source_revision
")->fetchAll(PDO::FETCH_COLUMN);
$gcRemaining = array_map('intval', $gcRemaining);
$assert(
    (int)$gcResult['safe_revision'] === $gcSafeRevision
    && $gcRemaining === [$gcNeededRevision]
    && (int)$gcDb->query("
        SELECT COUNT(*)
        FROM recipe_score_source_reconciliation_scopes
        WHERE source_revision = {$gcSafeRevision}
    ")->fetchColumn() === 0
    && (int)$gcDb->query("
        SELECT COUNT(*)
        FROM recipe_score_source_reconciliation_scopes
        WHERE source_revision = {$gcNeededRevision}
    ")->fetchColumn() === 1,
    'Reconciliation GC must remove only evidence older than every '
        . 'recoverable covered fence: '
        . ingredientOntologyV3Json([
            'safe_revision' => $gcSafeRevision,
            'result' => $gcResult,
            'remaining' => $gcRemaining,
        ])
);
$gcDb = null;

$gapPath = $path . '.journal-gap';
$artifacts[] = $gapPath;
databaseMaintenanceOnlineBackup($path, $gapPath);
$gap = $open($gapPath);
$gapBaseline = recipeScoreActiveRevision($gap);
$gap->prepare("
    UPDATE recipe_score_state
    SET catalog_revision = ?
    WHERE id = 1
")->execute([(int)$gapBaseline['catalog_revision']]);
$gap->exec("
    DELETE FROM recipe_score_pending_recipes;
    DELETE FROM recipe_score_mutations WHERE domain = 'catalog';
");
$gap->exec("
    INSERT INTO products (name, brand, category)
    VALUES ('Journal Gap Product', '', 'food')
");
$gapProductId = (int)$gap->lastInsertId();
$gapRevision = (int)recipeScoreState($gap)[
    'ontology_source_revision'
];
$gap->prepare("
    DELETE FROM recipe_score_mutations
    WHERE domain = 'source' AND revision = ?
")->execute([$gapRevision]);
$gapDecision = ingredientOntologyV3CorpusProjectionV2DriftDecision(
    $gap
);
$gapPlan = ingredientOntologyV3CorpusProjectionV2Classify(
    $gap,
    recipeScoreActiveRevision($gap),
    recipeScoreState($gap),
    false
);
$assert(
    !empty($gapDecision['handled'])
    && empty($gapDecision['requires_full_seal'])
    && !empty($gapDecision['pending_suffix']),
    'A source mutation journal gap must request reconciliation: '
        . ingredientOntologyV3Json($gapDecision)
);
$gapPlans = [$gapPlan];
$gapResults = [];
for ($gapPass = 0; $gapPass < 10; $gapPass++) {
    $gapResults[] =
        ingredientOntologyV3IncrementalRebuild($gap, true);
    $gapActive = recipeScoreActiveRevision($gap);
    $gapPin = ingredientOntologyV3CorpusAnnexForScore(
        $gap,
        $gapActive
    );
    if (
        $gapPin !== null
        && (int)$gapPin['covered_ontology_source_revision']
            >= (int)recipeScoreState($gap)[
                'ontology_source_revision'
            ]
    ) {
        break;
    }
    $gapPlans[] = ingredientOntologyV3CorpusProjectionV2Classify(
        $gap,
        $gapActive,
        recipeScoreState($gap),
        false
    );
}
$gapScopeComplete = (bool)array_filter(
    $gapPlans,
    static fn(array $plan): bool =>
        !empty($plan['scope_reconciliation_complete'])
);
$gapProductCovered = (bool)array_filter(
    $gapPlans,
    static fn(array $plan): bool => in_array(
        $gapProductId,
        (array)($plan['product_ids'] ?? []),
        true
    )
);
$gapAllRebuilt = !array_filter(
    $gapResults,
    static fn(array $result): bool => empty($result['rebuilt'])
);
$gapMaximumElapsed = max(array_map(
    static fn(array $result): int =>
        (int)($result['elapsed_ms'] ?? PHP_INT_MAX),
    $gapResults
));
$assert(
    $gapAllRebuilt
    && $gapScopeComplete
    && $gapProductCovered
    && $gapMaximumElapsed < 5000,
    'A journal gap must reconcile through selective aggregate hashes: '
        . ingredientOntologyV3Json([
            'passes' => count($gapResults),
            'scope_reconciliation_complete' =>
                $gapScopeComplete,
            'product_covered' => $gapProductCovered,
            'results' => array_map(
                static fn(array $result): array => [
                    'rebuilt' => $result['rebuilt'] ?? null,
                    'reason' => $result['reason'] ?? null,
                    'error' => $result['error'] ?? null,
                ],
                $gapResults
            ),
        ])
);
$gap = null;

$sourcePagingPath = $path . '.source-paging';
$sourcePagingDurablePath =
    $path . '.source-paging-durable';
$artifacts[] = $sourcePagingPath;
$artifacts[] = $sourcePagingDurablePath;
databaseMaintenanceOnlineBackup(
    $identityOnlyPath,
    $sourcePagingPath
);
$sourcePagingDb = $open($sourcePagingPath);
$sourcePagingStartPin = ingredientOntologyV3CorpusAnnexForScore(
    $sourcePagingDb,
    recipeScoreActiveRevision($sourcePagingDb)
);
$sourcePagingStartCovered = (int)$sourcePagingStartPin[
    'covered_ontology_source_revision'
];
$sourcePagingEventCount = 1200;
$sourcePagingDb->exec('BEGIN IMMEDIATE');
try {
    $advanceSource = $sourcePagingDb->prepare("
        UPDATE recipe_score_state
        SET ontology_source_revision =
                ontology_source_revision + 1,
            ontology_source_hash = '',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ");
    $insertSourceEvent = $sourcePagingDb->prepare("
        INSERT INTO recipe_score_mutations (
            domain, revision, lane, owner_type, owner_id,
            operation, source_table, source_row_id, reason
        )
        SELECT 'source', ontology_source_revision,
               'maintenance', 'product', ?,
               'update', 'products', ?,
               'source_paging_fixture'
        FROM recipe_score_state
        WHERE id = 1
    ");
    $insertSecondScope = $sourcePagingDb->prepare("
        INSERT INTO recipe_score_mutation_scopes (
            mutation_id, ordinal, aggregate_type, aggregate_id,
            scope_role, source_table, source_row_id,
            source_key, metadata_json
        )
        VALUES (
            ?, 2, 'recipe', ?, 'dependency',
            'products', ?, '', '{}'
        )
    ");
    for ($index = 1; $index <= $sourcePagingEventCount; $index++) {
        $advanceSource->execute();
        $insertSourceEvent->execute([
            $kabochaProductId,
            $kabochaProductId,
        ]);
        if ($index % 100 === 0) {
            $insertSecondScope->execute([
                (int)$sourcePagingDb->lastInsertId(),
                $kabochaRecipeId,
                $kabochaProductId,
            ]);
        }
    }
    $sourcePagingDb->exec('COMMIT');
} catch (Throwable $error) {
    $sourcePagingDb->exec('ROLLBACK');
    throw $error;
}
$sourcePagingTarget = (int)recipeScoreState($sourcePagingDb)[
    'ontology_source_revision'
];
databaseMaintenanceOnlineBackup(
    $sourcePagingPath,
    $sourcePagingDurablePath
);
$sourceStatusDb = $open($sourcePagingPath);
$sourceStatusDb->exec('PRAGMA query_only=ON');
$sourceStatusChangesBefore = (int)$sourceStatusDb->query(
    'SELECT total_changes()'
)->fetchColumn();
$sourceStatusStarted = hrtime(true);
$sourceStatus = null;
for ($index = 0; $index < 20; $index++) {
    $sourceStatus =
        ingredientOntologyV3CorpusProjectionV2Status(
            $sourceStatusDb
        );
}
$sourceStatusElapsedMs =
    (hrtime(true) - $sourceStatusStarted) / 1000000;
$sourceStatusChangesAfter = (int)$sourceStatusDb->query(
    'SELECT total_changes()'
)->fetchColumn();
$sourceStatusDb = null;
$denseProgress = [];
$densePlansBounded = true;
for ($pass = 0; $pass < 10; $pass++) {
    $denseParent = recipeScoreActiveRevision($sourcePagingDb);
    $densePin = ingredientOntologyV3CorpusAnnexForScore(
        $sourcePagingDb,
        $denseParent
    );
    if (
        (int)$densePin['covered_ontology_source_revision']
            >= $sourcePagingTarget
    ) {
        break;
    }
    $densePlan = ingredientOntologyV3CorpusProjectionV2Classify(
        $sourcePagingDb,
        $denseParent,
        recipeScoreState($sourcePagingDb),
        false
    );
    $densePlansBounded =
        $densePlansBounded
        && count((array)$densePlan['events'])
            <= INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS
        && (int)$densePlan['through_revision']
            - (int)$densePlan['from_revision']
            <= INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS;
    $denseResult = ingredientOntologyV3IncrementalRebuild(
        $sourcePagingDb,
        true
    );
    $denseAfter = ingredientOntologyV3CorpusAnnexForScore(
        $sourcePagingDb,
        recipeScoreActiveRevision($sourcePagingDb)
    );
    $denseProgress[] = [
        'from' => (int)$densePin[
            'covered_ontology_source_revision'
        ],
        'through' => (int)$denseAfter[
            'covered_ontology_source_revision'
        ],
        'rebuilt' => !empty($denseResult['rebuilt']),
        'reason' => $denseResult['reason'] ?? null,
        'error' => $denseResult['error'] ?? null,
    ];
}
$sourcePagingDurableDb = $open($sourcePagingDurablePath);
$sourcePagingDurableDb->prepare("
    DELETE FROM recipe_score_mutations
    WHERE domain = 'source' AND revision > ?
")->execute([$sourcePagingStartCovered]);
$durableProgress = [];
$durablePlansBounded = true;
$durableScopeComplete = true;
for ($pass = 0; $pass < 10; $pass++) {
    $durableParent = recipeScoreActiveRevision(
        $sourcePagingDurableDb
    );
    $durablePin = ingredientOntologyV3CorpusAnnexForScore(
        $sourcePagingDurableDb,
        $durableParent
    );
    if (
        (int)$durablePin['covered_ontology_source_revision']
            >= $sourcePagingTarget
    ) {
        break;
    }
    $durablePlan = ingredientOntologyV3CorpusProjectionV2Classify(
        $sourcePagingDurableDb,
        $durableParent,
        recipeScoreState($sourcePagingDurableDb),
        false
    );
    $durablePlansBounded =
        $durablePlansBounded
        && count((array)$durablePlan['events'])
            <= INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS
        && (int)$durablePlan['through_revision']
            - (int)$durablePlan['from_revision']
            <= INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS;
    $durableScopeComplete =
        $durableScopeComplete
        && !empty($durablePlan[
            'scope_reconciliation_complete'
        ]);
    $durableResult = ingredientOntologyV3IncrementalRebuild(
        $sourcePagingDurableDb,
        true
    );
    $durableAfter = ingredientOntologyV3CorpusAnnexForScore(
        $sourcePagingDurableDb,
        recipeScoreActiveRevision($sourcePagingDurableDb)
    );
    $durableProgress[] = [
        'from' => (int)$durablePin[
            'covered_ontology_source_revision'
        ],
        'through' => (int)$durableAfter[
            'covered_ontology_source_revision'
        ],
        'rebuilt' => !empty($durableResult['rebuilt']),
        'reason' => $durableResult['reason'] ?? null,
        'error' => $durableResult['error'] ?? null,
    ];
}
$denseFinal = ingredientOntologyV3CorpusAnnexForScore(
    $sourcePagingDb,
    recipeScoreActiveRevision($sourcePagingDb)
);
$durableFinal = ingredientOntologyV3CorpusAnnexForScore(
    $sourcePagingDurableDb,
    recipeScoreActiveRevision($sourcePagingDurableDb)
);
$denseProgressValid = !array_filter(
    $denseProgress,
    static fn(array $page): bool =>
        empty($page['rebuilt'])
        || $page['through'] <= $page['from']
        || $page['through'] - $page['from']
            > INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS
);
$durableProgressValid = !array_filter(
    $durableProgress,
    static fn(array $page): bool =>
        empty($page['rebuilt'])
        || $page['through'] <= $page['from']
        || $page['through'] - $page['from']
            > INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS
);
$assert(
    $sourceStatusChangesAfter === $sourceStatusChangesBefore
    && $sourceStatusElapsedMs < 1000
    && is_array($sourceStatus)
    && empty($sourceStatus['fresh'])
    && array_key_exists('computed_at', $sourceStatus)
    && array_key_exists(
        'captured_identity_extension_revision',
        $sourceStatus
    )
    && array_key_exists(
        'covered_identity_extension_revision',
        $sourceStatus
    )
    && (int)$sourceStatus[
        'current_ontology_source_revision'
    ] === $sourcePagingTarget
    && $densePlansBounded
    && $durablePlansBounded
    && $durableScopeComplete
    && $denseProgressValid
    && $durableProgressValid
    && (int)$denseFinal['covered_ontology_source_revision']
        === $sourcePagingTarget
    && (int)$durableFinal['covered_ontology_source_revision']
        === $sourcePagingTarget,
    'Large dense and durable source windows must page complete '
        . 'events while materialized status remains bounded and '
        . 'query-only: '
        . ingredientOntologyV3Json([
            'status_elapsed_ms' =>
                round($sourceStatusElapsedMs, 3),
            'status' => $sourceStatus,
            'dense_progress' => $denseProgress,
            'durable_progress' => $durableProgress,
            'durable_scope_complete' =>
                $durableScopeComplete,
        ])
);
$sourcePagingDb = null;
$sourcePagingDurableDb = null;

$fingerprintPath = $path . '.fingerprint';
$artifacts[] = $fingerprintPath;
databaseMaintenanceOnlineBackup($path, $fingerprintPath);
$fingerprint = $open($fingerprintPath);
$fingerprint->prepare("
    UPDATE products SET name = 'Changed Kabocha'
    WHERE id = ?
")->execute([$kabochaProductId]);
$fingerprintDecision =
    ingredientOntologyV3CorpusProjectionV2DriftDecision(
        $fingerprint
    );
$assert(
    !empty($fingerprintDecision['handled'])
    && empty($fingerprintDecision['requires_full_seal']),
    'A captured owner fingerprint change must remain selective'
);
$fingerprint = null;

$entryHashPath = $path . '.entry-hash';
$artifacts[] = $entryHashPath;
databaseMaintenanceOnlineBackup($path, $entryHashPath);
$entryHashDb = $open($entryHashPath);
$entryHashDb->exec("
    DROP TRIGGER
        ingredient_ontology_corpus_annex_entries_immutable_update
");
$entryHashDb->prepare("
    UPDATE ingredient_ontology_corpus_annex_entries
    SET row_hash = ?
    WHERE corpus_annex_revision_id = ?
      AND ordinal = 1
")->execute([
    hash('sha256', 'corrupt-annex-entry'),
    (int)$annex['id'],
]);
$entryHashAudit =
    ingredientOntologyV3CorpusProjectionV2IntegrityAudit(
        $entryHashDb,
        (int)$annex['id'],
        (string)$annex['revision_hash'],
        false
    );
$entryHashLineage =
    ingredientOntologyV3CorpusProjectionLineageAudit(
        $entryHashDb,
        (int)$annex['id'],
        (string)$annex['revision_hash']
    );
ingredientOntologyV3SetReadyMutationGuard($entryHashDb, true);
$entryHashDb->prepare("
    DELETE FROM ingredient_ontology_corpus_annex_projection_state
    WHERE ontology_version_id = ?
")->execute([$versionId]);
ingredientOntologyV3SetReadyMutationGuard($entryHashDb, false);
$entryRepairError = '';
try {
    ingredientOntologyV3CorpusAnnexEnsureProjection(
        $entryHashDb,
        ingredientOntologyV3CorpusAnnexRevision(
            $entryHashDb,
            (int)$annex['id']
        )
    );
} catch (Throwable $error) {
    $entryRepairError = $error->getMessage();
}
$assert(
    empty($entryHashAudit['valid'])
    && !empty($entryHashLineage['valid'])
    && in_array(
        'corpus projection entry hash changed',
        (array)$entryHashAudit['errors'],
        true
    ),
    'The bounded hot-path lineage check must remain distinct from the '
        . 'explicit deep audit that detects immutable entry corruption'
);
$assert(
    str_contains(
        $entryRepairError,
        'repair refused corrupted evidence'
    )
    && !ingredientOntologyV3CorpusAnnexProjectionReady(
        $entryHashDb,
        ingredientOntologyV3CorpusAnnexRevision(
            $entryHashDb,
            (int)$annex['id']
        )
    ),
    'Worker repair must not replay immutable entries that fail deep '
        . 'integrity verification'
);
$entryHashDb = null;

$checkpointEntryPath = $path . '.checkpoint-source-entry-hash';
$artifacts[] = $checkpointEntryPath;
databaseMaintenanceOnlineBackup($path, $checkpointEntryPath);
$checkpointEntryDb = $open($checkpointEntryPath);
$checkpointEntryCompaction =
    ingredientOntologyV3CompactCorpusProjection(
        $checkpointEntryDb,
        true
    );
$checkpointEntryActive = recipeScoreActiveRevision(
    $checkpointEntryDb
);
if (
    empty($checkpointEntryCompaction['compacted'])
    || !is_array($checkpointEntryActive)
) {
    throw new RuntimeException(
        'Checkpoint-source corruption fixture is unavailable'
    );
}
$checkpointEntryPin = ingredientOntologyV3CorpusAnnexForScore(
    $checkpointEntryDb,
    $checkpointEntryActive
);
if (!is_array($checkpointEntryPin)) {
    throw new RuntimeException(
        'Checkpoint-source corruption fixture is unavailable'
    );
}
$checkpointEntrySource =
    ingredientOntologyV3CorpusAnnexCheckpointSource(
        $checkpointEntryPin
    );
if (!is_array($checkpointEntrySource)) {
    throw new RuntimeException(
        'Checkpoint-source corruption fixture is unavailable'
    );
}
$checkpointEntrySeen = [];
$checkpointEntryChain =
    ingredientOntologyV3CorpusAnnexMaterializationChain(
        $checkpointEntryDb,
        (int)$checkpointEntrySource['revision_id'],
        $checkpointEntrySeen
    );
$checkpointEntryRevisionId = 0;
foreach ($checkpointEntryChain as $checkpointEntryRevision) {
    if ((int)$checkpointEntryRevision['entry_count'] > 0) {
        $checkpointEntryRevisionId =
            (int)$checkpointEntryRevision['id'];
        break;
    }
}
if (
    $checkpointEntryRevisionId <= 0
) {
    throw new RuntimeException(
        'Checkpoint-source corruption fixture is unavailable'
    );
}
$checkpointEntryDb->exec("
    DROP TRIGGER
        ingredient_ontology_corpus_annex_entries_immutable_update
");
$checkpointEntryDb->prepare("
    UPDATE ingredient_ontology_corpus_annex_entries
    SET row_hash = ?
    WHERE corpus_annex_revision_id = ?
      AND ordinal = 1
")->execute([
    hash('sha256', 'corrupt-checkpoint-source-entry'),
    $checkpointEntryRevisionId,
]);
$checkpointEntryAudit =
    ingredientOntologyV3CorpusAnnexIntegrityAudit(
        $checkpointEntryDb,
        (int)$checkpointEntryPin['id'],
        (string)$checkpointEntryPin['revision_hash'],
        false
    );
ingredientOntologyV3SetReadyMutationGuard(
    $checkpointEntryDb,
    true
);
$checkpointEntryDb->prepare("
    DELETE FROM ingredient_ontology_corpus_annex_projection_state
    WHERE ontology_version_id = ?
")->execute([$versionId]);
ingredientOntologyV3SetReadyMutationGuard(
    $checkpointEntryDb,
    false
);
$checkpointEntryRepairError = '';
try {
    ingredientOntologyV3CorpusAnnexEnsureProjection(
        $checkpointEntryDb,
        $checkpointEntryPin
    );
} catch (Throwable $error) {
    $checkpointEntryRepairError = $error->getMessage();
}
$checkpointEntryErrors = implode(
    '; ',
    (array)$checkpointEntryAudit['errors']
);
$assert(
    empty($checkpointEntryAudit['valid'])
    && str_contains(
        $checkpointEntryErrors,
        'checkpoint source: corpus projection entry hash changed'
    )
    && str_contains(
        $checkpointEntryRepairError,
        'repair refused corrupted evidence'
    ),
    'Deep audit and repair must validate immutable entries behind '
        . 'every rollover checkpoint source'
);
$checkpointEntryDb = null;

$cleanupPath = $path . '.nonready-cleanup';
$artifacts[] = $cleanupPath;
databaseMaintenanceOnlineBackup($path, $cleanupPath);
$cleanupDb = $open($cleanupPath);
$cleanupParent = recipeScoreActiveRevision($cleanupDb);
$cleanupReadyPin = ingredientOntologyV3CorpusAnnexForScore(
    $cleanupDb,
    $cleanupParent
);
$cleanupDb->prepare("
    UPDATE products
    SET name = 'Cleanup Candidate Product'
    WHERE id = ?
")->execute([$kabochaProductId]);
$cleanupDb->exec('BEGIN IMMEDIATE');
try {
    $cleanupPlan = ingredientOntologyV3CorpusProjectionV2Classify(
        $cleanupDb,
        $cleanupParent,
        recipeScoreState($cleanupDb),
        true
    );
    $cleanupPrepared =
        ingredientOntologyV3CorpusAnnexCreateChild(
            $cleanupDb,
            $cleanupParent,
            recipeScoreState($cleanupDb),
            $cleanupPlan
        );
    ingredientOntologyV3CorpusAnnexFailPrepared(
        $cleanupDb,
        $cleanupPrepared,
        'intentional cleanup fixture'
    );
    $cleanupDb->exec('COMMIT');
} catch (Throwable $error) {
    $cleanupDb->exec('ROLLBACK');
    throw $error;
}
$failedRevisionId = (int)$cleanupPrepared['revision']['id'];
$failedEntryCount = (int)$cleanupDb->query("
    SELECT COUNT(*)
    FROM ingredient_ontology_corpus_annex_entries
    WHERE corpus_annex_revision_id = {$failedRevisionId}
")->fetchColumn();
$unguardedFailedEntryDeleteBlocked = false;
try {
    $cleanupDb->exec("
        DELETE FROM ingredient_ontology_corpus_annex_entries
        WHERE corpus_annex_revision_id = {$failedRevisionId}
    ");
} catch (Throwable $error) {
    $unguardedFailedEntryDeleteBlocked = true;
}
$cleanupResult =
    ingredientOntologyV3CorpusAnnexCleanupNonReady(
        $cleanupDb,
        0,
        10
    );
$readyDeleteBlocked = false;
ingredientOntologyV3SetRequirementPruneGuard($cleanupDb, true);
try {
    $cleanupDb->prepare("
        DELETE FROM ingredient_ontology_corpus_annex_revisions
        WHERE id = ?
    ")->execute([(int)$cleanupReadyPin['id']]);
} catch (Throwable $error) {
    $readyDeleteBlocked = true;
} finally {
    ingredientOntologyV3SetRequirementPruneGuard($cleanupDb, false);
}
$assert(
    !empty($cleanupPrepared['created'])
    && $failedEntryCount > 0
    && $unguardedFailedEntryDeleteBlocked
    && in_array(
        $failedRevisionId,
        (array)$cleanupResult['deleted_revision_ids'],
        true
    )
    && (int)$cleanupResult['deleted_entry_count']
        >= $failedEntryCount
    && ingredientOntologyV3CorpusAnnexRevision(
        $cleanupDb,
        $failedRevisionId
    ) === null
    && $readyDeleteBlocked
    && ingredientOntologyV3CorpusAnnexRevision(
        $cleanupDb,
        (int)$cleanupReadyPin['id']
    ) !== null,
    'Cleanup may prune guarded failed/building evidence but must never '
        . 'weaken ready revision or entry immutability'
);
$cleanupDb = null;

$pinMismatchPath = $path . '.pin-mismatch';
$artifacts[] = $pinMismatchPath;
databaseMaintenanceOnlineBackup($path, $pinMismatchPath);
$pinMismatchDb = $open($pinMismatchPath);
$pinMismatchParent = recipeScoreActiveRevision($pinMismatchDb);
$pinMismatchAnnex = ingredientOntologyV3CorpusAnnexForScore(
    $pinMismatchDb,
    $pinMismatchParent
);
$createPinMismatchChild = static function (
    PDO $fixtureDb,
    array $parent,
    array $annex
): int {
    $state = recipeScoreState($fixtureDb);
    $revisionId = ingredientOntologyV3IncrementalInsertRevision(
        $fixtureDb,
        $parent,
        $state,
        (string)$parent['inventory_fingerprint'],
        (string)$parent['ontology_source_hash'],
        [
            'revision' =>
                (int)$parent['identity_extension_revision'],
            'hash' =>
                (string)$parent['identity_extension_hash'],
        ],
        false,
        (int)$parent['covered_catalog_revision'],
        (int)$parent['covered_ontology_source_revision'],
        (string)$parent['score_date'],
        $annex
    );
    $fixtureDb->prepare("
        UPDATE recipe_score_revisions
        SET validation_report_json = ?
        WHERE id = ?
    ")->execute([
        ingredientOntologyV3Json([
            'materialized_hash_algorithm' => 'parent-delta-v2',
        ]),
        $revisionId,
    ]);
    return $revisionId;
};
$pinMismatchRejected = [];
$pinMismatchErrorMessages = [];
$pinMismatchMutations = [
    'identity_revision' => static function (
        PDO $fixtureDb,
        int $revisionId
    ) use ($pinMismatchParent): void {
        $fixtureDb->prepare("
            UPDATE recipe_score_revisions
            SET identity_extension_revision = ?,
                identity_extension_hash = ?
            WHERE id = ?
        ")->execute([
            (int)$pinMismatchParent[
                'identity_extension_revision'
            ] + 1,
            hash('sha256', 'mismatched-identity-revision'),
            $revisionId,
        ]);
    },
    'identity_hash' => static function (
        PDO $fixtureDb,
        int $revisionId
    ): void {
        $fixtureDb->prepare("
            UPDATE recipe_score_revisions
            SET identity_extension_hash = ?
            WHERE id = ?
        ")->execute([
            hash('sha256', 'mismatched-identity-hash'),
            $revisionId,
        ]);
    },
    'captured_source' => static function (
        PDO $fixtureDb,
        int $revisionId
    ) use ($pinMismatchParent): void {
        $fixtureDb->prepare("
            UPDATE recipe_score_revisions
            SET ontology_source_revision = ?
            WHERE id = ?
        ")->execute([
            (int)$pinMismatchParent[
                'ontology_source_revision'
            ] + 1,
            $revisionId,
        ]);
    },
];
foreach ($pinMismatchMutations as $name => $mutate) {
    $revisionId = (int)$pinMismatchParent['id'];
    $rejected = false;
    $pinMismatchDb->exec('SAVEPOINT pin_mismatch');
    try {
        $mutate($pinMismatchDb, $revisionId);
    } catch (Throwable $error) {
        $pinMismatchErrorMessages[$name] =
            $error->getMessage();
        $rejected = true;
    } finally {
        $pinMismatchDb->exec(
            'ROLLBACK TO SAVEPOINT pin_mismatch'
        );
        $pinMismatchDb->exec(
            'RELEASE SAVEPOINT pin_mismatch'
        );
    }
    $pinMismatchRejected[$name] = $rejected;
}
$sparseMismatchId = $createPinMismatchChild(
    $pinMismatchDb,
    $pinMismatchParent,
    $pinMismatchAnnex
);
$pinMismatchDb->prepare("
    UPDATE recipe_score_revisions
    SET corpus_annex_revision_id = NULL,
        corpus_annex_hash = NULL,
        identity_extension_revision =
            identity_extension_revision + 1,
        identity_extension_hash = ?
    WHERE id = ?
")->execute([
    hash('sha256', 'sparse-parent-identity-mismatch'),
    $sparseMismatchId,
]);
$sparseMismatch = recipeScoreRevision(
    $pinMismatchDb,
    $sparseMismatchId
);
$sparseInherited = ingredientOntologyV3CorpusAnnexEnsureScoreRoot(
    $pinMismatchDb,
    $sparseMismatch
);
$sparseAfter = recipeScoreRevision(
    $pinMismatchDb,
    $sparseMismatchId
);
$exactPinTriggerSql = (string)$pinMismatchDb->query("
    SELECT sql
    FROM sqlite_master
    WHERE type = 'trigger'
      AND name =
          'recipe_score_revisions_corpus_annex_ready_update'
")->fetchColumn();
$exactPinTriggerInstalled =
    str_contains(
        $exactPinTriggerSql,
        'annex.captured_ontology_source_revision ='
    )
    && str_contains(
        $exactPinTriggerSql,
        'annex.identity_extension_revision ='
    )
    && str_contains(
        $exactPinTriggerSql,
        'annex.identity_extension_hash ='
    )
    && str_contains(
        $exactPinTriggerSql,
        'annex.covered_identity_extension_revision ='
    )
    && str_contains(
        $exactPinTriggerSql,
        'covered_identity_extension_hash'
    );
$assert(
    !in_array(false, $pinMismatchRejected, true)
    && $exactPinTriggerInstalled
    && $sparseInherited === null
    && $sparseAfter['corpus_annex_revision_id'] === null
    && $sparseAfter['corpus_annex_hash'] === null,
    'Ready sparse scores must reject exact identity revision/hash '
        . 'and captured-source pin mismatches, and sparse inheritance '
        . 'must not attach the parent annex: '
        . ingredientOntologyV3Json([
            'rejected' => $pinMismatchRejected,
            'errors' => $pinMismatchErrorMessages,
            'exact_trigger_installed' =>
                $exactPinTriggerInstalled,
            'sparse_inherited' => $sparseInherited,
            'sparse_after' => $sparseAfter,
        ])
);
$pinMismatchDb = null;

$hashPath = $path . '.hash';
$artifacts[] = $hashPath;
databaseMaintenanceOnlineBackup($path, $hashPath);
$hashDb = $open($hashPath);
$hashDb->exec("
    DROP TRIGGER recipe_score_revisions_corpus_annex_ready_update
");
ingredientOntologyV3SetReadyMutationGuard($hashDb, true);
$hashDb->prepare("
    UPDATE recipe_score_revisions
    SET corpus_annex_hash = ?
    WHERE id = ?
")->execute([
    hash('sha256', 'corrupt-annex-pin'),
    $baselineScoreId,
]);
ingredientOntologyV3SetReadyMutationGuard($hashDb, false);
$hashDecision =
    ingredientOntologyV3CorpusProjectionV2DriftDecision($hashDb);
$assert(
    empty($hashDecision['handled'])
    && !empty($hashDecision['requires_full_seal'])
    && recipeScoreRevisionStatus(
        $hashDb,
        recipeScoreRevision($hashDb, $baselineScoreId)
    ) === 'stale',
    'A corrupted score annex pin must fail closed'
);
$hashDb = null;

$continuationPath = $path . '.continuation-257';
$artifacts[] = $continuationPath;
databaseMaintenanceOnlineBackup($identityOnlyPath, $continuationPath);
$continuationDb = $open($continuationPath);
$previousProductLimit = getenv(
    'RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT'
);
putenv('RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT=1');
$continuationDb->exec('BEGIN IMMEDIATE');
try {
    $insertContinuationProduct = $continuationDb->prepare("
        INSERT INTO products (name, brand, category)
        VALUES (?, '', 'food')
    ");
    for ($index = 1; $index <= 257; $index++) {
        $insertContinuationProduct->execute([
            'Continuation product ' . $index,
        ]);
    }
    $continuationDb->exec('COMMIT');
} catch (Throwable $error) {
    $continuationDb->exec('ROLLBACK');
    throw $error;
}
$continuationTarget = (int)recipeScoreState(
    $continuationDb
)['ontology_source_revision'];
$continuationPages = 0;
$continuationMaximumDepth = 0;
$continuationMaximumAggregateCount = 0;
$continuationFailures = [];
for ($pass = 0; $pass < 700; $pass++) {
    $continuationResult = ingredientOntologyV3IncrementalRebuild(
        $continuationDb,
        true
    );
    if (
        (string)($continuationResult['reason'] ?? '')
            === 'compaction_required'
    ) {
        $scoreCompaction = ingredientOntologyV3CompactActiveScores(
            $continuationDb,
            true
        );
        if (empty($scoreCompaction['compacted'])) {
            $continuationFailures[] = $scoreCompaction;
            break;
        }
        continue;
    }
    if (empty($continuationResult['rebuilt'])) {
        $continuationFailures[] = $continuationResult;
        break;
    }
    $continuationPages++;
    $continuationActive = recipeScoreActiveRevision(
        $continuationDb
    );
    $continuationPin = ingredientOntologyV3CorpusAnnexForScore(
        $continuationDb,
        $continuationActive
    );
    $continuationAudit =
        ingredientOntologyV3CorpusProjectionLineageAudit(
            $continuationDb,
            (int)$continuationPin['id'],
            (string)$continuationPin['revision_hash']
        );
    if (empty($continuationAudit['valid'])) {
        $continuationFailures[] = $continuationAudit;
        break;
    }
    $continuationMaximumDepth = max(
        $continuationMaximumDepth,
        (int)$continuationAudit['depth']
    );
    $continuationMaximumAggregateCount = max(
        $continuationMaximumAggregateCount,
        (int)($continuationResult[
            'corpus_annex_aggregate_count'
        ] ?? 0)
    );
    if (
        (int)$continuationPin[
            'covered_ontology_source_revision'
        ] >= $continuationTarget
    ) {
        break;
    }
}
if ($previousProductLimit === false) {
    putenv('RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT');
} else {
    putenv(
        'RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT='
            . $previousProductLimit
    );
}
$continuationFinalPin = ingredientOntologyV3CorpusAnnexForScore(
    $continuationDb,
    recipeScoreActiveRevision($continuationDb)
);
$assert(
    !$continuationFailures
    && $continuationPages === 257
    && $continuationMaximumDepth
        <= ingredientOntologyV3CorpusAnnexCompactionDepth()
    && $continuationMaximumAggregateCount === 1
    && (int)$continuationFinalPin[
        'covered_ontology_source_revision'
    ] === $continuationTarget,
    'At least 257 one-item continuation pages must complete through '
        . 'bounded rollover checkpoints without invalid lineage or a '
        . 'full-corpus fallback: '
        . ingredientOntologyV3Json([
            'pages' => $continuationPages,
            'maximum_depth' => $continuationMaximumDepth,
            'maximum_aggregate_count' =>
                $continuationMaximumAggregateCount,
            'target_revision' => $continuationTarget,
            'covered_revision' => (int)$continuationFinalPin[
                'covered_ontology_source_revision'
            ],
            'failures' => $continuationFailures,
        ])
);
$continuationDb = null;

$status = ingredientOntologyV3CorpusAnnexStatus($db);
$assert(
    (int)$status['active_revision_id']
        === (int)$rollbackTargetPin['id']
    && is_array($status['base_maxima'])
    && array_key_exists('compaction_due', $status)
    && array_key_exists('drift_reason', $status),
    'Corpus annex status must expose the active pin and drift reason'
);

echo "Corpus annex tests passed: {$assertions} assertions\n";
