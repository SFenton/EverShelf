#!/usr/bin/env php
<?php
declare(strict_types=1);

$testDirectory = sys_get_temp_dir()
    . '/evershelf-processing-status-'
    . getmypid()
    . '-'
    . bin2hex(random_bytes(4));
if (!mkdir($testDirectory, 0770, true)) {
    throw new RuntimeException('Could not create processing status test directory');
}
putenv('LOG_DIR=' . $testDirectory . '/logs');
putenv('ONTOLOGY_AUTONOMOUS_ENABLED=true');
putenv('INGREDIENT_ONTOLOGY_CONTROLLER_PROVIDER=fake');
define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
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

$metadataItem = static function (
    string $externalId,
    string $ingredient
): array {
    return [
        'external_id' => $externalId,
        'title' => 'Processing status fixture',
        'metadata_schema_version' =>
            RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        'general' => [
            'yield_quantity' => null,
            'yield_unit' => null,
            'active_time_seconds' => null,
            'total_time_seconds' => null,
            'difficulty' => null,
            'primary_category' => null,
            'equipment' => [],
        ],
        'ingredients' => [[
            'name' => $ingredient,
            'source_quantity' => null,
            'source_quantity_max' => null,
            'source_unit' => null,
            'source_amount_text' => null,
            'source_group_index' => 0,
            'source_group_position' => 0,
            'source_group_title' => null,
            'source_ingredient_ref' => 'provider-' . $ingredient,
            'source_default_title' => ucfirst($ingredient),
            'source_unit_ref' => null,
            'source_optional' => false,
            'source_shopping_category_ref' => null,
        ]],
        'topology_metrics' => [
            'group_count' => 1,
            'group_title_key_count' => 1,
            'group_title_nonempty_count' => 0,
            'group_title_length_total' => 0,
            'group_title_length_max' => 0,
            'ingredient_count' => 1,
            'ingredient_ref_key_count' => 1,
            'ingredient_ref_nonempty_count' => 1,
            'default_title_key_count' => 1,
            'default_title_nonempty_count' => 1,
            'unit_ref_key_count' => 1,
            'unit_ref_nonempty_count' => 0,
            'optional_key_count' => 1,
            'optional_true_count' => 0,
            'optional_false_count' => 1,
            'optional_null_count' => 0,
            'shopping_category_ref_key_count' => 1,
            'shopping_category_ref_nonempty_count' => 0,
        ],
        'image_url' => '',
        'canonical_url' =>
            'https://cookidoo.co.uk/recipes/recipe/en-GB/'
            . $externalId,
        'locale' => 'en-GB',
    ];
};

$databasePath = $testDirectory . '/processing.sqlite';
$lockDatabasePath = $testDirectory . '/barcode-lock.sqlite';
try {
    $db = new PDO('sqlite:' . $databasePath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    initializeDB($db);
    migrateDB($db);

    $saved = recipeCatalogSaveVariant($db, [
        'title' => 'Processing status fixture',
        'ingredients' => [['name' => 'water']],
        'source_ingredients' => [[
            'name' => 'water',
            'source_group_index' => 0,
            'source_group_position' => 0,
            'source_ingredient_ref' => 'provider-water',
            'source_default_title' => 'Water',
            'source_optional' => false,
        ]],
    ], [
        'connector' => RECIPE_COOKIDOO_CONNECTOR,
        'external_id' => 'processing-status-fixture',
        'canonical_url' =>
            'https://cookidoo.co.uk/recipes/recipe/en-GB/'
            . 'processing-status-fixture',
        'locale' => 'en-GB',
        'content_language' => 'en',
        'metadata_version' => RECIPE_COOKIDOO_METADATA_VERSION,
        'metadata_schema_version' =>
            RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        'store_source_payload' => false,
    ]);
    $recipeId = (int)$saved['id'];
    $originStmt = $db->prepare("
        SELECT id
        FROM recipe_origins
        WHERE recipe_id = ? AND connector = ?
    ");
    $originStmt->execute([$recipeId, RECIPE_COOKIDOO_CONNECTOR]);
    $originId = (int)$originStmt->fetchColumn();
    $db->prepare("
        DELETE FROM ontology_subject_occurrences
        WHERE owner_type = 'recipe_source_ingredient'
          AND CAST(json_extract(provenance_json, '$.recipe_id') AS INTEGER) = ?
    ")->execute([$recipeId]);

    $before = evershelfProcessingStatusRecipeOntologyCoverage($db);
    $assert(
        $before['missing_recipe_count'] === 1
        && evershelfProcessingStatusMissingRecipeIds($db, 10)
            === [$recipeId],
        'Missing recipe observations must be visible and selectable'
    );

    $scoreStateBefore = recipeScoreState($db);
    $applied = recipeCookidooApplyMetadataV2(
        $db,
        $recipeId,
        $originId,
        $metadataItem('processing-status-fixture', 'chicken'),
        gmdate('Y-m-d H:i:s')
    );
    $scoreStateAfter = recipeScoreState($db);
    $after = evershelfProcessingStatusRecipeOntologyCoverage($db);
    $assert(
        !empty($applied['ontology_observation']['observed'])
        && $applied['ontology_observation']['occurrence_count'] >= 2
        && $after['missing_row_count'] === 0
        && $after['coverage_percent'] === 100.0,
        'Cookidoo metadata refresh must observe ranking and source ingredients'
    );
    $assert(
        empty($applied['score_catalog_dirty_required'])
        && $scoreStateAfter['catalog_revision']
            === $scoreStateBefore['catalog_revision']
        && $scoreStateAfter['ontology_source_revision']
            > $scoreStateBefore['ontology_source_revision'],
        'Metadata refresh must invalidate ontology source state without '
            . 'creating catalog revision storms'
    );

    $status = evershelfProcessingStatus($db);
    $assert(
        $status['schema_version']
            === EVERSHELF_PROCESSING_STATUS_SCHEMA
        && $status['recipe_source_ontology']['missing_row_count'] === 0
        && $status['ontology_queue']['intake_open_count'] >= 1
        && $status['pending']['total'] >= 1
        && in_array(
            $status['phase'],
            ['ontology', 'scoring', 'degraded'],
            true
        ),
        'Processing status must expose bounded queue, score, and coverage state'
    );
    $lineage = (string)$db->query("
        SELECT database_lineage_uuid
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetchColumn();
    $insertImport = $db->prepare("
        INSERT INTO ontology_activation_imports (
            bundle_hash, bundle_kind, database_lineage_uuid,
            schema_version, payload_path, payload_sha256,
            payload_bytes, manifest_json, status, last_error,
            created_at, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, 0, '{}', ?, ?, ?, ?)
    ");
    $insertImport->execute([
        hash('sha256', 'processing-status-ontology-import'),
        'ontology',
        $lineage,
        INGREDIENT_ONTOLOGY_ACTIVATION_SCHEMA_VERSION,
        $testDirectory . '/ontology.sqlite',
        hash('sha256', ''),
        'failed',
        'ontology import failed',
        '2026-08-16 17:00:00',
        '2026-08-16 18:00:00',
    ]);
    $ontologyImportId = (int)$db->lastInsertId();
    $insertImport->execute([
        hash('sha256', 'processing-status-score-import'),
        'score',
        $lineage,
        INGREDIENT_ONTOLOGY_ACTIVATION_SCHEMA_VERSION,
        $testDirectory . '/score.sqlite',
        hash('sha256', ''),
        'staging',
        '',
        '2026-08-16 19:00:00',
        '2026-08-16 19:00:00',
    ]);
    $scoreImportId = (int)$db->lastInsertId();
    $db->exec("
        UPDATE ontology_activation_state
        SET failure_count = 1,
            last_error = 'older activation error',
            updated_at = '2026-08-16 16:00:00'
        WHERE id = 1
    ");
    $activation = evershelfProcessingStatusActivation($db);
    $assert(
        (int)$activation['current_import']['id'] === $ontologyImportId
        && (int)$activation['latest_import']['id'] === $scoreImportId
        && $activation['current_import']['last_error']
            === 'ontology import failed'
        && $activation['last_error'] === 'ontology import failed',
        'Activation status must distinguish scheduler-current work from '
            . 'the latest row and preserve the newest non-empty error'
    );
    $problemStatus = evershelfProcessingStatus($db);
    $assert(
        $problemStatus['phase'] === 'activating'
        && $problemStatus['active'] === true
        && $problemStatus['problem'] === true,
        'Active work must remain visible when an independent problem exists'
    );
    $disabledPhase = evershelfProcessingStatusWorkPhase(
        ['open_count' => 0],
        [
            'runtime_enabled' => false,
            'intake_open_count' => 4,
            'generation_open_count' => 2,
        ],
        ['stale' => false],
        ['running' => false]
    );
    $assert(
        $disabledPhase === 'idle',
        'Disabled ontology queues must stay visible without implying active work'
    );
    $assert(
        in_array(
            'processing_status',
            evershelfDemoReadOnlyActions(),
            true
        ),
        'Processing status must remain available in read-only demo mode'
    );

    EverLog::info('processing status test log');
    $logStatus = EverLog::status();
    $assert(
        $logStatus['healthy']
        && $logStatus['writable']
        && is_file(EverLog::currentFile()),
        'Application logging must use a writable runtime directory'
    );

    $lockDb = new PDO('sqlite:' . $lockDatabasePath);
    $lockDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $lockDb->exec("
        CREATE TABLE barcode_cache (
            barcode TEXT PRIMARY KEY,
            found INTEGER NOT NULL,
            source TEXT,
            payload TEXT,
            updated_at DATETIME
        )
    ");
    $cacheDb = new PDO('sqlite:' . $lockDatabasePath);
    $cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $cacheDb->exec('PRAGMA busy_timeout = 1');
    $lockDb->exec('BEGIN IMMEDIATE');
    $cacheDeferred = barcodeCacheSet(
        $cacheDb,
        '0123456789012',
        ['found' => true, 'source' => 'fixture'],
        true
    );
    $lockDb->exec('ROLLBACK');
    $cacheWritten = barcodeCacheSet(
        $cacheDb,
        '0123456789012',
        ['found' => true, 'source' => 'fixture'],
        true
    );
    $assert(
        $cacheDeferred === false
        && $cacheWritten === true
        && barcodeCacheGet($cacheDb, '0123456789012')['found'] === true,
        'Barcode cache contention must not discard a successful provider result'
    );
} finally {
    foreach ([
        $databasePath,
        $databasePath . '-wal',
        $databasePath . '-shm',
        $lockDatabasePath,
        $lockDatabasePath . '-wal',
        $lockDatabasePath . '-shm',
    ] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    $logFiles = glob($testDirectory . '/logs/*') ?: [];
    foreach ($logFiles as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($testDirectory . '/logs')) {
        rmdir($testDirectory . '/logs');
    }
    if (is_dir($testDirectory)) {
        rmdir($testDirectory);
    }
}

echo "Processing status tests passed: {$assertions} assertions.\n";
