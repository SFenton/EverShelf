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

$scoreDateInstant = new DateTimeImmutable(
    '2026-08-18T02:30:00+00:00'
);
$assert(
    recipeScoreCurrentDate(
        $scoreDateInstant,
        'America/Los_Angeles'
    ) === '2026-08-17'
    && recipeScoreCurrentDate($scoreDateInstant, 'UTC')
        === '2026-08-18',
    'Recipe score dates must follow the configured business timezone'
);
$invalidScoreTimezoneRejected = false;
try {
    recipeScoreCurrentDate($scoreDateInstant, 'Not/A-Timezone');
} catch (RuntimeException $error) {
    $invalidScoreTimezoneRejected = str_contains(
        $error->getMessage(),
        'Invalid recipe score timezone'
    );
}
$assert(
    $invalidScoreTimezoneRejected,
    'Invalid recipe score timezones must fail closed'
);

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
        && array_key_exists(
            'generation_intent_due_count',
            $status['ontology_queue']
        )
        && array_key_exists(
            'coverage_gap_open_count',
            $status['ontology_queue']
        )
        && array_key_exists('advisories', $status)
        && $status['pending']['total'] >= 1
        && in_array(
            $status['phase'],
            ['ontology', 'scoring', 'degraded'],
            true
        ),
        'Processing status must expose bounded queue, score, and coverage state'
    );
    $maintenanceJobId = (int)$db->query("
        SELECT id FROM ontology_controller_jobs
        ORDER BY id
        LIMIT 1
    ")->fetchColumn();
    $db->prepare("
        UPDATE ontology_controller_jobs
        SET status = 'failed',
            last_error_kind = 'generation_abandoned',
            last_error = 'abandoned generation maintenance',
            finished_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$maintenanceJobId]);
    $assert(
        evershelfProcessingStatusOntologyQueue(
            $db
        )['failed_24h_count'] === 0,
        'Abandoned generation maintenance must not raise a processing problem'
    );
    $db->prepare("
        UPDATE ontology_controller_jobs
        SET last_error_kind = 'provider_failure',
            last_error = 'actionable provider failure',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$maintenanceJobId]);
    $assert(
        evershelfProcessingStatusOntologyQueue(
            $db
        )['failed_24h_count'] >= 1,
        'Actionable recent ontology failures must remain visible'
    );
    $db->prepare("
        UPDATE ontology_controller_jobs
        SET status = 'queued',
            last_error_kind = NULL,
            last_error = '',
            finished_at = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$maintenanceJobId]);
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
        'SQLSTATE table ontology_activation_imports at '
            . '/var/www/html/api/lib/ontology_v3/activation.php',
        '2026-08-16 17:00:00',
        '2026-08-16 18:00:00',
    ]);
    $ontologyImportId = (int)$db->lastInsertId();
    $insertImport->execute([
        hash('sha256', 'processing-status-cleanup-import'),
        'ontology',
        $lineage,
        INGREDIENT_ONTOLOGY_ACTIVATION_SCHEMA_VERSION,
        $testDirectory . '/cleanup.sqlite',
        hash('sha256', ''),
        'purging',
        'Historical cleanup remains pending.',
        '2026-08-16 18:30:00',
        '2026-08-16 18:30:00',
    ]);
    $cleanupImportId = (int)$db->lastInsertId();
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
    $schedulerImport = ingredientOntologyActivationPendingImport($db);
    $maintenanceImport =
        ingredientOntologyActivationPendingCleanupImport($db);
    $assert(
        (int)($schedulerImport['id'] ?? 0) === $scoreImportId
        && (int)($maintenanceImport['id'] ?? 0) === $cleanupImportId,
        'The activation scheduler must skip terminal and cleanup imports'
    );
    $db->exec("
        UPDATE ontology_activation_state
        SET failure_count = 1,
            last_error = 'SQLSTATE older activation error at /var/www/html',
            updated_at = '2026-08-16 16:00:00'
        WHERE id = 1
    ");
    $activation = evershelfProcessingStatusActivation($db);
    $assert(
        (int)$activation['current_import']['id'] === $scoreImportId
        && (int)$activation['latest_import']['id'] === $scoreImportId
        && $activation['current_import']['last_error'] === null
        && $activation['last_error']
            === EVERSHELF_PROCESSING_STATUS_PUBLIC_ERROR
        && !str_contains(
            (string)$activation['last_error'],
            'SQLSTATE'
        )
        && !str_contains(
            (string)$activation['last_error'],
            '/var/www'
        ),
        'Terminal failed imports must not block scheduler-current work or '
            . 'expose persisted diagnostics'
    );
    $problemStatus = evershelfProcessingStatus($db);
    $assert(
        $problemStatus['phase'] === 'activating'
        && $problemStatus['active'] === true
        && $problemStatus['problem'] === true,
        'Active work must remain visible when an independent problem exists'
    );
    $db->exec("
        UPDATE ontology_activation_imports
        SET status = 'cleaned',
            last_error = '',
            completed_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id IN (
            {$ontologyImportId}, {$cleanupImportId}, {$scoreImportId}
        );
        UPDATE ontology_activation_state
        SET failure_count = 0,
            last_error = '',
            last_outcome_kind = 'superseded_snapshot',
            last_outcome_json =
                '{\"reason\":\"test_snapshot_drift\","
                . "\"drift_codes\":[\"live_inputs_changed\"],"
                . "\"error\":\"SQLSTATE secret\","
                . "\"path\":\"/var/www/html/private.php\"}',
            last_outcome_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ");
    $expectedOutcomeStatus = evershelfProcessingStatus($db);
    $assert(
        $expectedOutcomeStatus['problem'] === false
        && $expectedOutcomeStatus['activation']['last_outcome_kind']
            === 'superseded_snapshot'
        && $expectedOutcomeStatus['activation']['last_outcome'][
            'reason'
        ] === 'test_snapshot_drift'
        && $expectedOutcomeStatus['activation']['last_outcome'][
            'drift_codes'
        ] === ['live_inputs_changed']
        && !array_key_exists(
            'error',
            $expectedOutcomeStatus['activation']['last_outcome']
        )
        && !array_key_exists(
            'path',
            $expectedOutcomeStatus['activation']['last_outcome']
        ),
        'Expected snapshot supersession must remain advisory instead of latching degraded health'
    );
    $db->exec("
        UPDATE ontology_activation_state
        SET failure_count = 1,
            last_error = 'incremental input lineage changed',
            last_outcome_kind = 'superseded_snapshot',
            last_outcome_json =
                '{\"drift_codes\":[\"live_inputs_changed\"]}',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ");
    $permanentFailureStatus = evershelfProcessingStatus($db);
    $assert(
        $permanentFailureStatus['problem'] === true
        && $permanentFailureStatus['activation']['failure_count'] === 1,
        'Failure health must remain actionable even if a stale expected outcome kind is present'
    );
    ingredientOntologyActivationRecordOutcome(
        $db,
        'activated',
        ['reason' => 'score_import_activated'],
        true
    );
    $recoveredStatus = evershelfProcessingStatus($db);
    $recoveredState = $db->query("
        SELECT failure_count, last_error, next_attempt_at,
               expected_outcome_key, expected_outcome_count,
               last_outcome_kind
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC);
    $assert(
        $recoveredStatus['problem'] === false
        && (int)$recoveredState['failure_count'] === 0
        && (string)$recoveredState['last_error'] === ''
        && $recoveredState['next_attempt_at'] === null
        && (string)$recoveredState['expected_outcome_key'] === ''
        && (int)$recoveredState['expected_outcome_count'] === 0
        && (string)$recoveredState['last_outcome_kind']
            === 'activated',
        'A committed terminal-good activation outcome must clear latched failure and drift state'
    );
    $db->exec("
        UPDATE ontology_activation_state
        SET failure_count = 0,
            last_error = '',
            expected_outcome_key = '',
            expected_outcome_count = 0,
            next_attempt_at = NULL
        WHERE id = 1
    ");
    $intermittentEscalated = false;
    for ($index = 0; $index < 6; $index++) {
        $drift = ingredientOntologyActivationRecordOutcome(
            $db,
            'superseded_snapshot',
            [
                'drift_codes' => ['live_inputs_changed'],
                'errors' => [
                    'inventory or catalog inputs changed after shadow build',
                ],
            ],
            true,
            60
        );
        $intermittentEscalated =
            $intermittentEscalated || !empty($drift['escalated']);
        ingredientOntologyActivationRecordOutcome(
            $db,
            'converged',
            ['reason' => 'ontology_import_converged'],
            true
        );
    }
    $intermittentState = $db->query("
        SELECT failure_count, expected_outcome_key,
               expected_outcome_count, last_outcome_kind
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC);
    $assert(
        !$intermittentEscalated
        && (int)$intermittentState['failure_count'] === 0
        && (string)$intermittentState['expected_outcome_key'] === ''
        && (int)$intermittentState['expected_outcome_count'] === 0
        && (string)$intermittentState['last_outcome_kind']
            === 'converged',
        'Intervening successful convergence must reset expected-drift consecutiveness'
    );
    $expectedRecords = [];
    for ($index = 0; $index < 4; $index++) {
        $expectedRecords[] =
            ingredientOntologyActivationRecordOutcome(
                $db,
                'superseded_snapshot',
                [
                    'drift_codes' => ['live_inputs_changed'],
                    'errors' => [
                        'inventory or catalog inputs changed after shadow build',
                    ],
                ],
                true,
                60
            );
    }
    $nonConverging = evershelfProcessingStatus($db);
    $assert(
        empty($expectedRecords[0]['escalated'])
        && empty($expectedRecords[2]['escalated'])
        && !empty($expectedRecords[3]['escalated'])
        && $nonConverging['problem'] === true
        && $nonConverging['activation']['last_outcome_kind']
            === 'non_converging_expected_outcome',
        'Repeated identical expected outcomes must escalate after the bounded retry limit'
    );
    $outcomeBeforeLock = $db->query("
        SELECT last_outcome_kind, expected_outcome_count
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC);
    $policyOutcomeLocked = false;
    try {
        ingredientOntologyActivationWithLiveReservation(
            [
                'live_reservation' =>
                    static function (): never {
                        throw new
                            IngredientOntologyActivationReservationUnavailable(
                                'record_policy_deferred'
                            );
                    },
            ],
            'record_policy_deferred',
            static fn(): array =>
                ingredientOntologyActivationRecordAdvisoryOutcome(
                    $db,
                    'policy_deferred',
                    ['reason' => 'no_due_generation_work'],
                    300
                )
        );
    } catch (
        IngredientOntologyActivationReservationUnavailable $error
    ) {
        $policyOutcomeLocked =
            $error->phase() === 'record_policy_deferred';
    }
    $outcomeAfterLock = $db->query("
        SELECT last_outcome_kind, expected_outcome_count
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC);
    $assert(
        $policyOutcomeLocked
        && $outcomeAfterLock === $outcomeBeforeLock,
        'Policy-deferred outcomes must not write outside the live reservation'
    );
    $preservedExpectedKey = str_repeat('c', 64);
    $db->prepare("
        UPDATE ontology_activation_state
        SET failure_count = 2,
            last_error = 'fixture integrity failure',
            next_attempt_at = datetime('now', '+30 minutes'),
            expected_outcome_key = ?,
            expected_outcome_count = 3,
            last_outcome_kind = 'integrity_failure',
            last_outcome_json =
                '{\"reason\":\"fixture_integrity_failure\"}',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ")->execute([$preservedExpectedKey]);
    $failureBeforeAdvisory = $db->query("
        SELECT failure_count, last_error, next_attempt_at,
               expected_outcome_key, expected_outcome_count
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC);
    ingredientOntologyActivationWithLiveReservation(
        [],
        'record_policy_deferred',
        static fn(): array =>
            ingredientOntologyActivationRecordAdvisoryOutcome(
                $db,
                'policy_deferred',
                [
                    'claimed_intents' => 0,
                    'reason' => 'no_due_generation_work',
                ],
                300
            )
    );
    $failureAfterAdvisory = $db->query("
        SELECT failure_count, last_error, next_attempt_at,
               expected_outcome_key, expected_outcome_count,
               last_outcome_kind
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC);
    $failureAdvisoryStatus = evershelfProcessingStatus($db);
    $assert(
        (int)$failureAfterAdvisory['failure_count']
            === (int)$failureBeforeAdvisory['failure_count']
        && (string)$failureAfterAdvisory['last_error']
            === (string)$failureBeforeAdvisory['last_error']
        && (string)$failureAfterAdvisory['next_attempt_at']
            === (string)$failureBeforeAdvisory['next_attempt_at']
        && hash_equals(
            (string)$failureAfterAdvisory['expected_outcome_key'],
            (string)$failureBeforeAdvisory['expected_outcome_key']
        )
        && (int)$failureAfterAdvisory['expected_outcome_count']
            === (int)$failureBeforeAdvisory['expected_outcome_count']
        && (string)$failureAfterAdvisory['last_outcome_kind']
            === 'policy_deferred'
        && $failureAdvisoryStatus['problem'] === true
        && $failureAdvisoryStatus['activation']['last_outcome'][
            'reason'
        ] === 'no_due_generation_work'
        && $failureAdvisoryStatus['activation']['last_outcome'][
            'policy_deferred'
        ] === true,
        'Policy-deferred advisory recording must preserve actionable failure, convergence, and longer backoff state'
    );
    ingredientOntologyActivationRecordOutcome(
        $db,
        'activated',
        ['reason' => 'score_import_activated'],
        true
    );
    $terminalGoodState = $db->query("
        SELECT failure_count, last_error, next_attempt_at,
               expected_outcome_key, expected_outcome_count,
               last_outcome_kind
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC);
    $assert(
        (int)$terminalGoodState['failure_count'] === 0
        && (string)$terminalGoodState['last_error'] === ''
        && $terminalGoodState['next_attempt_at'] === null
        && (string)$terminalGoodState['expected_outcome_key'] === ''
        && (int)$terminalGoodState['expected_outcome_count'] === 0
        && (string)$terminalGoodState['last_outcome_kind']
            === 'activated',
        'A later committed terminal-good outcome must clear state preserved by an advisory cycle'
    );
    ingredientOntologyActivationRecordAdvisoryOutcome(
        $db,
        'policy_deferred',
        ['reason' => 'no_due_generation_work'],
        300
    );
    $cleanAdvisoryState = $db->query("
        SELECT failure_count, last_error, next_attempt_at,
               expected_outcome_key, expected_outcome_count
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC);
    $cleanAdvisoryRetry = strtotime(
        (string)$cleanAdvisoryState['next_attempt_at']
    );
    $cleanAdvisoryStatus = evershelfProcessingStatus($db);
    $assert(
        (int)$cleanAdvisoryState['failure_count'] === 0
        && (string)$cleanAdvisoryState['last_error'] === ''
        && (string)$cleanAdvisoryState['expected_outcome_key'] === ''
        && (int)$cleanAdvisoryState['expected_outcome_count'] === 0
        && $cleanAdvisoryRetry >= time() + 295
        && $cleanAdvisoryRetry <= time() + 305
        && $cleanAdvisoryStatus['problem'] === false,
        'A clean policy-deferred advisory may schedule the normal 300-second retry without degrading health'
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
