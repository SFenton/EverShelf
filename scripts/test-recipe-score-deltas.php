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

$path = dirname(__DIR__) . '/data/.recipe-score-deltas-'
    . getmypid() . '.sqlite';
@unlink($path);
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
migrateDB($db);
$laneIndexes = $db->query("
    SELECT name
    FROM sqlite_master
    WHERE type = 'index'
      AND name IN (
          'idx_recipe_score_pending_recipe_lane',
          'idx_recipe_score_mutations_lane_revision'
      )
    ORDER BY name
")->fetchAll(PDO::FETCH_COLUMN);
$assert(
    $laneIndexes === [
        'idx_recipe_score_mutations_lane_revision',
        'idx_recipe_score_pending_recipe_lane',
    ],
    'Fresh schema migration must create both score-lane indexes'
);
$journalMode = $db->query('PRAGMA journal_mode=WAL');
$journalModeValue = $journalMode->fetchColumn();
$journalMode->closeCursor();
$assert(
    strtolower((string)$journalModeValue) === 'wal',
    'Incremental concurrency regression requires WAL mode'
);

$db->exec("
    INSERT INTO recipe_catalog (
        primary_connector, title, language, cache_expires_at
    )
    VALUES (
        'manual', 'Baseline Delta Recipe', 'en',
        datetime('now', '+1 day')
    )
");
$baselineRecipeId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO recipe_ingredients (
        recipe_id, position, raw_text, normalized_name,
        source_is_required, source_is_optional,
        requiredness_source
    )
    VALUES (?, 0, 'baseline unknown', 'baseline unknown',
            1, 0, 'explicit_required')
")->execute([$baselineRecipeId]);
$baselineIngredientId = (int)$db->lastInsertId();
$db->prepare("
    INSERT OR IGNORE INTO recipe_user_state (recipe_id)
    VALUES (?)
")->execute([$baselineRecipeId]);
recipeSearchRebuildDocument($db, $baselineRecipeId);

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
        'recipe-delta-test', 'building', ?, ?, ?,
        'gemini-3.5-flash', ?, ?, ?, ?, ?, ?,
        'test_only', 'test', 'test', ?, ?, ?, CURRENT_TIMESTAMP
    )
")->execute(array_fill(0, 12, $hash));
$versionId = (int)$db->lastInsertId();
ingredientOntologyV3SetPublicationGuard($db, true);
$db->prepare("
    UPDATE ingredient_ontology_versions
    SET status = 'ready', ready_at = CURRENT_TIMESTAMP
    WHERE id = ?
")->execute([$versionId]);
ingredientOntologyV3SetPublicationGuard($db, false);
ingredientOntologyV3IdentityAdmissionSync($db);

$state = recipeScoreState($db);
$sourceHash = ingredientOntologyV3CorpusHash($db);
$catalogFingerprint = recipeScoreCatalogFingerprint($db);
$idSets = ingredientOntologyV3MaterializedIdSetHashes(
    $db,
    0,
    null
);
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
        catalog_id_set_hash, ingredient_id_set_hash,
        score_rows_hash, match_rows_hash,
        materialization_hash, validation_report_json
    )
    VALUES (
        ?, ?, ?, ?, ?, 'building', 1, ?,
        'faceted-ontology-v3', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?, ?
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
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    (int)$state['ontology_source_revision'],
    $sourceHash,
    (string)$idSets['catalog_id_set_hash'],
    (string)$idSets['ingredient_id_set_hash'],
    $hash,
    $hash,
    $hash,
    ingredientOntologyV3Json([
        'materialized_hash_algorithm' => 'full-v1',
        'recipe_count' => 1,
        'ingredient_match_count' => 1,
    ]),
]);
$parentRevisionId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO recipe_inventory_scores (
        score_revision_id, recipe_id, coverage, directness,
        expiry_score, source_user_score, availability_score,
        required_count, matched_required_count,
        missing_required_count, uncertain_required_count,
        cookable
    )
    VALUES (?, ?, 0, 0, 0, 0, 0, 1, 0, 1, 0, 0)
")->execute([$parentRevisionId, $baselineRecipeId]);
$db->prepare("
    INSERT INTO ingredient_ontology_shadow_matches (
        score_revision_id, recipe_ingredient_id,
        outcome, satisfies_required, confidence,
        relationship, explanation_json
    )
    VALUES (?, ?, 'recipe_unmapped', 0, 0, 'none', '{}')
")->execute([$parentRevisionId, $baselineIngredientId]);
$baseValueHashes = ingredientOntologyV3MaterializedValueHashes(
    $db,
    $parentRevisionId,
    null
);
$db->prepare("
    UPDATE recipe_score_revisions
    SET score_rows_hash = ?,
        match_rows_hash = ?,
        materialization_hash = ?
    WHERE id = ?
")->execute([
    (string)$baseValueHashes['score_rows_hash'],
    (string)$baseValueHashes['match_rows_hash'],
    (string)$baseValueHashes['materialization_hash'],
    $parentRevisionId,
]);
ingredientOntologyV3SetPublicationGuard($db, true);
$db->prepare("
    UPDATE recipe_score_revisions
    SET status = 'ready', completed_at = CURRENT_TIMESTAMP
    WHERE id = ?
")->execute([$parentRevisionId]);
ingredientOntologyV3SetPublicationGuard($db, false);
$db->prepare("
    UPDATE recipe_score_state
    SET ontology_source_hash = ?,
        active_score_revision_id = ?,
        active_score_overlay_revision_id = NULL
    WHERE id = 1
")->execute([$sourceHash, $parentRevisionId]);
$db->exec('BEGIN IMMEDIATE');
recipeScoreBuildEffectiveProjection($db, $parentRevisionId);
$db->exec('DELETE FROM recipe_score_mutations');
$db->exec('COMMIT');
$root = ingredientOntologyV3CorpusAnnexEnsureScoreRoot(
    $db,
    recipeScoreActiveRevision($db)
);
$assert(
    $root !== null,
    'The baseline score must have a sealed corpus annex root'
);
$parentRevisionId = (int)recipeScoreActiveRevision($db)['id'];

$maintenancePath = $path . '.maintenance';
@unlink($maintenancePath);
databaseMaintenanceOnlineBackup($path, $maintenancePath);
$maintenanceDb = new PDO('sqlite:' . $maintenancePath);
$maintenanceDb->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);
$maintenanceDb->setAttribute(
    PDO::ATTR_DEFAULT_FETCH_MODE,
    PDO::FETCH_ASSOC
);
$maintenanceDb->exec('PRAGMA foreign_keys=ON');
$maintenanceDb->exec('PRAGMA journal_mode=WAL');
$maintenanceDb->exec('PRAGMA busy_timeout=10000');
ingredientOntologyV3RegisterGuardFunctions($maintenanceDb);
do {
    $maintenanceIdentity =
        ingredientOntologyV3IdentityAdmissionSync($maintenanceDb);
    $maintenanceIdentityRemaining = max(
        (int)(
            $maintenanceIdentity['resolver_migration']['remaining']
                ?? 0
        ),
        (int)(
            $maintenanceIdentity[
                'recipe_resolver_migration'
            ]['remaining'] ?? 0
        )
    );
} while ($maintenanceIdentityRemaining > 0);
$maintenanceBaselineState = recipeScoreState($maintenanceDb);
$maintenanceBaselineHash =
    ingredientOntologyV3CorpusHash($maintenanceDb);
ingredientOntologyV3SetReadyMutationGuard($maintenanceDb, true);
ingredientOntologyV3SetPublicationGuard($maintenanceDb, true);
$maintenanceDb->prepare("
    UPDATE recipe_score_revisions
    SET catalog_revision = ?,
        covered_catalog_revision = ?,
        ontology_source_revision = ?,
        covered_ontology_source_revision = ?,
        ontology_source_hash = ?
    WHERE id = ?
")->execute([
    (int)$maintenanceBaselineState['catalog_revision'],
    (int)$maintenanceBaselineState['catalog_revision'],
    (int)$maintenanceBaselineState['ontology_source_revision'],
    (int)$maintenanceBaselineState['ontology_source_revision'],
    $maintenanceBaselineHash,
    $parentRevisionId,
]);
ingredientOntologyV3SetPublicationGuard($maintenanceDb, false);
ingredientOntologyV3SetReadyMutationGuard($maintenanceDb, false);
$maintenanceDb->prepare("
    UPDATE recipe_score_state
    SET ontology_source_hash = ?
    WHERE id = 1
")->execute([$maintenanceBaselineHash]);
$maintenanceDb->exec('DELETE FROM recipe_score_pending_products');
$maintenanceDb->exec('DELETE FROM recipe_score_pending_recipes');
$maintenanceDb->exec('DELETE FROM recipe_score_mutations');
recipeCatalogSetFavorite(
    $maintenanceDb,
    $baselineRecipeId,
    true
);
$maintenanceDb->prepare("
    UPDATE recipe_score_pending_recipes
    SET lane = 'maintenance'
    WHERE recipe_id = ?
")->execute([$baselineRecipeId]);
$maintenanceDb->prepare("
    UPDATE recipe_score_mutations
    SET lane = 'maintenance'
    WHERE domain = 'catalog'
      AND owner_type = 'recipe'
      AND owner_id = ?
")->execute([$baselineRecipeId]);
$maintenanceParent = recipeScoreActiveRevision($maintenanceDb);
$assert(
    is_array($maintenanceParent)
    && ingredientOntologyActivationMaintenanceDriftIsIncremental(
        $maintenanceDb,
        $maintenanceParent
    )
    && !ingredientOntologyActivationNeedsScoreBuild($maintenanceDb),
    'Bounded recipe maintenance must stay on the incremental lane'
);
$maintenanceDelta = ingredientOntologyV3IncrementalRebuild(
    $maintenanceDb,
    true
);
$maintenanceRevision = recipeScoreRevision(
    $maintenanceDb,
    (int)($maintenanceDelta['revision_id'] ?? 0)
);
$assert(
    !empty($maintenanceDelta['rebuilt'])
    && empty($maintenanceDelta['serving_only'])
    && is_array($maintenanceRevision)
    && (string)$maintenanceRevision['revision_kind']
        === 'maintenance_delta'
    && (int)$maintenanceDb->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_recipes
        WHERE lane = 'maintenance'
    ")->fetchColumn() === 0,
    'A bounded maintenance recipe batch must publish incrementally: '
        . ingredientOntologyV3Json([
            'result' => $maintenanceDelta,
            'revision' => $maintenanceRevision,
            'mutations' => $maintenanceDb->query("
                SELECT domain, revision, lane, owner_type, owner_id,
                       operation, reason
                FROM recipe_score_mutations
                ORDER BY domain, revision
            ")->fetchAll(PDO::FETCH_ASSOC),
        ])
);
$maintenanceDb = null;
foreach ([
    $maintenancePath,
    $maintenancePath . '-wal',
    $maintenancePath . '-shm',
    $maintenancePath . '.migration.lock',
    dirname($maintenancePath) . '/.'
        . basename($maintenancePath) . '.recipe-score.lock',
] as $maintenanceArtifact) {
    @unlink($maintenanceArtifact);
}

$db->exec("
    INSERT INTO products (name, brand, category)
    VALUES ('Serving Lane Fixture', '', 'food')
");
$servingProductId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO inventory (product_id, location, quantity)
    VALUES (?, 'dispensa', 1)
")->execute([$servingProductId]);
$maintenanceRecipeIds = range(300000, 300500);
$maintenanceParentId =
    (int)recipeScoreState($db)['active_score_revision_id'];
recipeScoreMarkRecipesDirtyBatch(
    $db,
    $maintenanceRecipeIds,
    'replace',
    'maintenance_backlog_regression',
    false,
    'maintenance'
);
recipeScoreMarkProductDirty(
    $db,
    $servingProductId,
    'serving_lane_regression'
);
$maintenanceCount = (int)$db->query("
    SELECT COUNT(*)
    FROM recipe_score_pending_recipes
    WHERE lane = 'maintenance'
")->fetchColumn();
$servingResult = ingredientOntologyV3IncrementalRebuild(
    $db,
    true
);
$servingRevision = recipeScoreRevision(
    $db,
    (int)($servingResult['revision_id'] ?? 0)
);
$assert(
    $maintenanceCount === 501
    && !empty($servingResult['rebuilt'])
    && !empty($servingResult['serving_only'])
    && is_array($servingRevision)
    && (string)$servingRevision['revision_kind']
        === 'serving_delta'
    && (int)$servingRevision['parent_score_revision_id']
        === $maintenanceParentId
    && (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_pending_products
    ")->fetchColumn() === 0
    && (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_recipes
        WHERE lane = 'maintenance'
    ")->fetchColumn() === $maintenanceCount,
    'Maintenance recipe overflow must not block serving product scores: '
        . ingredientOntologyV3Json([
            'result' => $servingResult,
            'revision' => $servingRevision,
            'maintenance_count' => $maintenanceCount,
        ])
);
$db->exec("
    INSERT INTO products (name, brand, category)
    VALUES ('Unscoped Source Fixture', '', 'food')
");
$unscopedSourceProductId = (int)$db->lastInsertId();
$db->exec("
    INSERT INTO products (name, brand, category)
    VALUES ('Serving Lock Fixture', '', 'food')
");
$servingLockProductId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO inventory (product_id, location, quantity)
    VALUES (?, 'dispensa', 1)
")->execute([$servingLockProductId]);
recipeScoreMarkProductDirty(
    $db,
    $servingLockProductId,
    'serving_lock_regression'
);
$servingCoordinationPath =
    $path . '.serving-coordination';
$servingBackgroundPath =
    $path . '.serving-background';
$servingHeartbeatPath =
    $path . '.serving-heartbeat';
$servingStatusPath =
    $path . '.serving-status';
$servingCoordination = fopen(
    $servingCoordinationPath,
    'c+'
);
$assert(
    is_resource($servingCoordination)
    && flock(
        $servingCoordination,
        LOCK_EX | LOCK_NB
    ),
    'Serving coordination fixture must acquire the copied-build lock'
);
$servingPipes = [];
$servingWorker = proc_open(
    [
        PHP_BINARY,
        __DIR__ . '/incremental-score-worker.php',
        '--db=' . $path,
        '--background-lock=' . $servingBackgroundPath,
        '--coordination-lock=' . $servingCoordinationPath,
        '--heartbeat=' . $servingHeartbeatPath,
        '--status-file=' . $servingStatusPath,
        '--force',
        '--json',
    ],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $servingPipes,
    dirname(__DIR__)
);
if (!is_resource($servingWorker)) {
    throw new RuntimeException(
        'Could not start serving lock worker probe'
    );
}
fclose($servingPipes[0]);
$servingStdout =
    stream_get_contents($servingPipes[1]);
$servingStderr =
    stream_get_contents($servingPipes[2]);
fclose($servingPipes[1]);
fclose($servingPipes[2]);
$servingWorkerStatus = proc_close($servingWorker);
flock($servingCoordination, LOCK_UN);
fclose($servingCoordination);
$servingPayload = json_decode(
    (string)$servingStdout,
    true
);
$assert(
    $servingWorkerStatus === 0
    && is_array($servingPayload)
    && !empty($servingPayload['success'])
    && !empty($servingPayload['rebuilt'])
    && !empty($servingPayload['serving_only']),
    'Serving products must publish while copied recovery owns its '
        . 'coordination lock: '
        . ingredientOntologyV3Json([
            'status' => $servingWorkerStatus,
            'stdout' => $servingStdout,
            'stderr' => $servingStderr,
        ])
);
$servingLockRevision = recipeScoreActiveRevision($db);
$servingLockState = recipeScoreState($db);
$assert(
    is_array($servingLockRevision)
    && (int)$servingLockRevision[
        'covered_ontology_source_revision'
    ] === (int)$servingLockState['ontology_source_revision']
    && in_array(
        recipeScoreRevisionStatus($db, $servingLockRevision),
        ['fresh', 'partial'],
        true
    )
    && (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_products
        WHERE product_id = {$unscopedSourceProductId}
    ")->fetchColumn() === 0,
    'All source-scoped products must be folded into the same bounded '
        . 'projection revision'
);
$db->exec("
    DELETE FROM recipe_score_pending_recipes
    WHERE lane = 'maintenance'
");
recipeScoreMarkRecipesDirtyBatch(
    $db,
    [400001],
    'replace',
    'concurrent_maintenance_recipe_regression',
    false,
    'maintenance'
);
$concurrentMaintenanceCount = (int)$db->query("
    SELECT COUNT(*)
    FROM recipe_score_pending_recipes
    WHERE lane = 'maintenance'
")->fetchColumn();

$db->exec("
    CREATE TABLE incremental_external_commit_probe (
        id INTEGER PRIMARY KEY,
        value INTEGER NOT NULL
    )
");
$db->exec("
    INSERT INTO incremental_external_commit_probe (id, value)
    VALUES (1, 0)
");
$parentForRecovery = recipeScoreRevision($db, $parentRevisionId);
$abandonedRevisionId =
    ingredientOntologyV3IncrementalInsertRevision(
        $db,
        $parentForRecovery,
        recipeScoreState($db),
        (string)$parentForRecovery['inventory_fingerprint'],
        (string)$parentForRecovery['ontology_source_hash'],
        ingredientOntologyV3IdentityExtensionSnapshot(
            $db,
            $versionId
        )
    );
$favorite = recipeCatalogSetFavorite(
    $db,
    $baselineRecipeId,
    true
);
$externalCommitObserved = false;
$publicationDb = new PDO('sqlite:' . $path);
$publicationDb->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);
$publicationDb->setAttribute(
    PDO::ATTR_DEFAULT_FETCH_MODE,
    PDO::FETCH_ASSOC
);
$publicationDb->exec('PRAGMA foreign_keys=ON');
$publicationDb->exec('PRAGMA busy_timeout=10000');
ingredientOntologyV3RegisterGuardFunctions($publicationDb);
$GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_INCREMENTAL_SNAPSHOT'] =
    static function () use (
        $path,
        &$externalCommitObserved
    ): void {
        $writer = new PDO('sqlite:' . $path);
        $writer->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );
        $writer->exec('PRAGMA busy_timeout=10000');
        $writer->exec("
            UPDATE incremental_external_commit_probe
            SET value = value + 1
            WHERE id = 1
        ");
        $writer->exec("
            INSERT INTO products (name, brand, category)
            VALUES (
                'Concurrent publication fence product',
                '',
                'food'
            )
        ");
        $externalCommitObserved = true;
    };
$favoriteDelta = ingredientOntologyV3IncrementalRebuild(
    $publicationDb,
    true
);
unset($GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_INCREMENTAL_SNAPSHOT']);
$favoriteRevision = recipeScoreRevision(
    $db,
    (int)($favoriteDelta['revision_id'] ?? 0)
);
$favoriteProcessingStatus =
    evershelfProcessingStatusScores($db);
$assert(
    $favorite
    && $externalCommitObserved
    && !empty($favoriteDelta['rebuilt'])
    && !empty($favoriteDelta['serving_only'])
    && (string)($favoriteRevision['revision_kind'] ?? '')
        === 'serving_delta'
    && $favoriteDelta['recipe_operations'][$baselineRecipeId]
        === 'replace'
    && (int)$favoriteDelta['physical_score_rows'] === 1
    && (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_recipes
        WHERE lane = 'serving'
    ")->fetchColumn() === 0,
    'Serving recipe edits must publish despite concurrent unrelated '
        . 'source writes and maintenance debt: '
        . ingredientOntologyV3Json([
            'result' => $favoriteDelta,
            'revision' => $favoriteRevision,
            'maintenance_count' => $concurrentMaintenanceCount,
        ])
);
$assert(
    (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_recipes
        WHERE lane = 'maintenance'
    ")->fetchColumn() === $concurrentMaintenanceCount,
    'Serving recipe publication must preserve maintenance recipe work'
);
$assert(
    (string)$favoriteProcessingStatus['status'] === 'partial'
    && in_array(
        'catalog_coverage',
        $favoriteProcessingStatus['reasons'],
        true
    ),
    'Partial serving revisions must expose maintenance coverage lag'
);
$db->exec("
    DELETE FROM recipe_score_pending_recipes
    WHERE lane = 'maintenance'
");
$assert(
    (string)$db->query("
        SELECT status
        FROM recipe_score_revisions
        WHERE id = {$abandonedRevisionId}
    ")->fetchColumn() === 'failed'
    && (int)$db->query("
        SELECT value
        FROM incremental_external_commit_probe
        WHERE id = 1
    ")->fetchColumn() === 1,
    'Exclusive scoring must recover abandoned builds before publication'
);

$inserted = recipeCatalogSaveVariant($db, [
    'title' => 'New Delta Recipe',
    'language' => 'en',
    'ingredients' => [[
        'name' => 'unknown delta ingredient',
        'is_required' => true,
    ], [
        'name' => 'second unknown delta ingredient',
        'is_required' => true,
    ]],
    'steps' => ['Test the sparse recipe append.'],
], [
    'connector' => 'manual',
    'external_id' => 'recipe-delta-new',
]);
$insertedRecipeId = (int)$inserted['id'];
$unscoredIngredientIds = array_map(
    'intval',
    $db->query("
        SELECT id
        FROM recipe_ingredients
        WHERE recipe_id = {$insertedRecipeId}
        ORDER BY position
    ")->fetchAll(PDO::FETCH_COLUMN)
);
$unscoredDetail = recipeDetailV3MatchMap(
    $db,
    array_map(
        static fn(int $ingredientId): array => [
            'ranking_ingredient_id' => $ingredientId,
        ],
        $unscoredIngredientIds
    )
);
$assert(
    (int)$unscoredDetail['revision']['id']
        === (int)$favoriteDelta['revision_id']
    && $unscoredDetail['matches'] === [],
    'New recipe detail must remain readable before score publication'
);
$insertDelta = ingredientOntologyV3IncrementalRebuild(
    $db,
    true
);
$insertedSource = $db->prepare("
    SELECT score_revision_id
    FROM recipe_score_effective_sources
    WHERE recipe_id = ?
");
$insertedSource->execute([$insertedRecipeId]);
$annexCount = $db->prepare("
    SELECT COUNT(*)
    FROM ingredient_ontology_recipe_identity_annex annex
    JOIN recipe_ingredients ingredient
      ON ingredient.id = annex.recipe_ingredient_id
    WHERE ingredient.recipe_id = ?
      AND annex.ontology_version_id = ?
");
$annexCount->execute([$insertedRecipeId, $versionId]);
$insertRevision = recipeScoreRevision(
    $db,
    (int)$insertDelta['revision_id']
);
$insertState = recipeScoreState($db);
$assert(
    !empty($insertDelta['rebuilt'])
    && $insertDelta['recipe_operations'][$insertedRecipeId]
        === 'replace'
    && (int)$insertDelta['recipe_count'] === 2
    && (int)$insertedSource->fetchColumn()
        === (int)$insertDelta['revision_id']
    && (int)$annexCount->fetchColumn() === 2
    && (int)$insertDelta['pending_recipe_count'] === 0,
    'A new recipe must receive terminal annex mappings and append sparsely: '
        . ingredientOntologyV3Json($insertDelta)
);
$assert(
    (string)$insertRevision['ontology_source_hash'] === $sourceHash
    && (string)$insertState['ontology_source_hash'] === $sourceHash
    && strlen(
        (string)$insertRevision['ontology_source_lineage_hash']
    ) === 64
    && hash_equals(
        (string)$insertRevision['ontology_source_lineage_hash'],
        (string)$insertState['ontology_source_lineage_hash']
    ),
    'Scoped source lineage must not overwrite canonical source hashes'
);
$removedIngredientId = (int)$db->query("
    SELECT id FROM recipe_ingredients
    WHERE recipe_id = {$insertedRecipeId}
    ORDER BY position DESC
    LIMIT 1
")->fetchColumn();
$db->prepare("
    INSERT INTO recipe_score_match_contributors (
        score_revision_id, recipe_ingredient_id,
        recipe_id, product_id
    )
    VALUES (?, ?, ?, 999999)
")->execute([
    (int)$insertDelta['revision_id'],
    $removedIngredientId,
    $insertedRecipeId,
]);
$db->prepare("
    DELETE FROM recipe_score_contributor_revisions
    WHERE score_revision_id = ?
")->execute([(int)$insertDelta['revision_id']]);

$mutationPath = $path . '.source-mutation';
@unlink($mutationPath);
databaseMaintenanceOnlineBackup($path, $mutationPath);
$mutationDb = new PDO('sqlite:' . $mutationPath);
$mutationDb->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);
$mutationDb->setAttribute(
    PDO::ATTR_DEFAULT_FETCH_MODE,
    PDO::FETCH_ASSOC
);
$mutationDb->exec('PRAGMA foreign_keys=ON');
$mutationDb->exec('PRAGMA journal_mode=WAL');
ingredientOntologyV3RegisterGuardFunctions($mutationDb);

$updated = recipeCatalogSaveVariant($mutationDb, [
    'title' => 'Updated Delta Recipe',
    'language' => 'en',
    'ingredients' => [[
        'name' => 'updated unknown delta ingredient',
        'is_required' => true,
    ]],
    'steps' => ['Test the sparse recipe update.'],
], [
    'recipe_id' => $insertedRecipeId,
    'connector' => 'manual',
    'external_id' => 'recipe-delta-new',
]);
$updateDelta = ingredientOntologyV3IncrementalRebuild(
    $mutationDb,
    true
);
$historicalContributorCount = (int)$db->query("
    SELECT COUNT(*)
    FROM recipe_score_match_contributors
    WHERE score_revision_id = " . (int)$insertDelta['revision_id'] . "
      AND recipe_ingredient_id = {$removedIngredientId}
      AND recipe_id = {$insertedRecipeId}
")->fetchColumn();
$backfill = recipeScoreBackfillContributorRevision(
    $db,
    (int)$insertDelta['revision_id']
);
$expectedBackfillMatchCount = (int)$db->query("
    SELECT COUNT(*)
    FROM ingredient_ontology_shadow_matches
    WHERE score_revision_id = " . (int)$insertDelta['revision_id']
)->fetchColumn();
$assert(
    (int)$updated['id'] === $insertedRecipeId
    && !empty($updateDelta['rebuilt'])
    && (array)$updateDelta['recipe_ids'] === [$insertedRecipeId]
    && (string)$updateDelta['recipe_operations'][
        $insertedRecipeId
    ] === 'replace'
    && $historicalContributorCount === 1
    && (int)$backfill['match_count']
        === $expectedBackfillMatchCount,
    'Existing recipe edits must publish a selective replacement: '
        . ingredientOntologyV3Json([
            'updated_id' => (int)$updated['id'],
            'update_delta' => $updateDelta,
            'historical_contributor_count' =>
                $historicalContributorCount,
            'backfill' => $backfill,
        ])
);
$updateProjectionDecision =
    ingredientOntologyV3CorpusProjectionV2DriftDecision(
        $mutationDb
    );
$assert(
    !empty($updateProjectionDecision['handled'])
    && empty($updateProjectionDecision['requires_full_seal']),
    'Existing-owner source edits must remain covered by the projection'
);

$deleted = recipeCatalogDelete($mutationDb, $insertedRecipeId);
$deleteDelta = ingredientOntologyV3IncrementalRebuild(
    $mutationDb,
    true
);
$assert(
    $deleted
    && !empty($deleteDelta['rebuilt'])
    && (array)$deleteDelta['recipe_ids'] === [$insertedRecipeId]
    && (string)$deleteDelta['recipe_operations'][
        $insertedRecipeId
    ] === 'delete',
    'Recipe deletion must publish a selective tombstone: '
        . ingredientOntologyV3Json([
            'delete_delta' => $deleteDelta,
        ])
);
$mutationDb = null;
@unlink($mutationPath);
@unlink($mutationPath . '-wal');
@unlink($mutationPath . '-shm');

$annexBeforeCompaction = ingredientOntologyV3CorpusAnnexForScore(
    $db,
    recipeScoreActiveRevision($db)
);
$compaction = ingredientOntologyV3CompactActiveScores($db, true);
$compactedRevision = recipeScoreActiveRevision($db);
$assert(
    !empty($compaction['compacted'])
    && (int)$compaction['match_count'] === 3
    && (int)$compactedRevision['corpus_annex_revision_id']
        === (int)$annexBeforeCompaction['id']
    && hash_equals(
        (string)$compactedRevision['corpus_annex_hash'],
        (string)$annexBeforeCompaction['revision_hash']
    ),
    'Score compaction must preserve values and the exact annex pin: '
        . ingredientOntologyV3Json([
            'result' => $compaction,
            'revision' => $compactedRevision,
            'annex' => $annexBeforeCompaction,
        ])
);
$compactedAnnexIntegrity =
    ingredientOntologyV3CorpusAnnexIntegrityAudit(
        $db,
        (int)$compactedRevision['corpus_annex_revision_id'],
        (string)$compactedRevision['corpus_annex_hash'],
        true
    );
$compactedIdSetAudit = ingredientOntologyV3MaterializedIdSetAudit(
    $db,
    $compactedRevision
);
$compactedValueAudit = ingredientOntologyV3MaterializedValueAudit(
    $db,
    $compactedRevision
);
$assert(
    !empty($compactedAnnexIntegrity['valid'])
    && !empty($compactedIdSetAudit['valid'])
    && !empty($compactedValueAudit['valid']),
    'Compacted mutable score lineage must remain auditable: '
        . ingredientOntologyV3Json([
            'annex' => $compactedAnnexIntegrity,
            'id_sets' => $compactedIdSetAudit,
            'values' => $compactedValueAudit,
        ])
);

recipeScoreSetWorkState(
    $db,
    'publishing',
    (int)$compactedRevision['id'],
    (int)$compactedRevision['parent_score_revision_id'],
    1,
    1,
    0,
    0
);
$settleResults = [];
for ($settlePass = 0; $settlePass < 10; $settlePass++) {
    $settle = ingredientOntologyV3IncrementalRebuild(
        $db,
        true
    );
    if (
        empty($settle['rebuilt'])
        && (string)($settle['reason'] ?? '')
            === 'no_pending_changes'
    ) {
        $noWork = $settle;
        break;
    }
    $settleResults[] = $settle;
}
$noWork ??= ingredientOntologyV3IncrementalRebuild($db, true);
$servingModeLost = ingredientOntologyV3IncrementalRebuild(
    $db,
    true,
    requireServing: true
);
$workPhase = (string)$db->query("
    SELECT phase FROM recipe_score_work_state WHERE id = 1
")->fetchColumn();
$assert(
    (string)$noWork['reason'] === 'no_pending_changes'
    && $workPhase === 'idle',
    'No-work recovery must clear a stale published work phase: '
        . ingredientOntologyV3Json([
            'settled' => $settleResults,
            'no_work' => $noWork,
        ])
);
$assert(
    (string)$servingModeLost['reason'] === 'serving_mode_lost',
    'A coordination-bypassed run must not enter maintenance mode'
);
$db = null;
@unlink($path);
@unlink($path . '-wal');
@unlink($path . '-shm');
foreach ([
    $servingCoordinationPath,
    $servingBackgroundPath,
    $servingHeartbeatPath,
    $servingStatusPath,
] as $statePath) {
    @unlink($statePath);
}

echo "Recipe score delta tests passed: "
    . "{$assertions} assertions.\n";
