#!/usr/bin/env php
<?php
declare(strict_types=1);

putenv('INGREDIENT_ONTOLOGY_CONTROLLER_ENABLED=false');
putenv('ONTOLOGY_AUTONOMOUS_ENABLED=false');
putenv('COOKIDOO_DETAIL_HYDRATION_ENABLED=false');
putenv('COOKIDOO_METADATA_BACKFILL_ENABLED=false');
putenv('SHOPPING_MODE=internal');
define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        continue;
    }
    $parts = explode('=', substr($argument, 2), 2);
    $options[$parts[0]] = $parts[1] ?? true;
}

$foods = [
    'Fennel Bulb',
    'Bok Choy',
    'Mascarpone',
    'Pomegranate',
    'Smoked Tofu',
    'Golden Beet',
    'Shiitake Mushrooms',
    'Coconut Cream',
    'Parsnip',
    'Halloumi',
    'Blackberries',
    'Butternut Squash',
    'Duck Breast',
    'Tahini',
    'Lemongrass',
    'Arborio Rice',
    'Kohlrabi',
    'Watercress',
    'Paneer',
    'Persimmon',
    'Tempeh',
    'Rainbow Chard',
    'Oyster Mushrooms',
    'Coconut Milk',
    'Celeriac',
    'Manchego',
    'Gooseberries',
    'Acorn Squash',
    'Turkey Tenderloin',
    'Miso Paste',
    'Galangal',
    'Pearl Barley',
];

$open = static function (string $path): PDO {
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');
    $db->exec('PRAGMA busy_timeout=10000');
    ingredientOntologyV3RegisterGuardFunctions($db);
    return $db;
};

$configureCanonicalProcessor = static function (): void {
    $GLOBALS['CANONICAL_QUEUE_TEST_WAKE'] =
        static fn(): bool => true;
    $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
        static function (PDO $queueDb, int $productId): array {
            $stmt = $queueDb->prepare("
                SELECT name FROM products WHERE id = ?
            ");
            $stmt->execute([$productId]);
            $name = trim((string)$stmt->fetchColumn());
            $slug = canonicalIngredientSlug($name);
            return [
                'product_id' => $productId,
                'mapped' => 1,
                'mappings' => [[
                    'slug' => $slug,
                    'name' => canonicalIngredientTitle($name),
                    'role' => 'primary',
                    'confidence' => 1.0,
                    'source' => 'stress_fixture',
                    'evidence' => 'deterministic stress mapping',
                    'category' => 'food',
                    'parent_slug' => null,
                    'external_ids' => [],
                ]],
                'tags' => [],
                'decision' => 'stress_fixture',
                'decision_detail' => [],
                '_apply_canonical' => true,
                '_product_exists' => true,
            ];
        };
};

$settlerMode = isset($options['settler']);
if ($settlerMode) {
    $databasePath = trim((string)($options['db'] ?? ''));
    $doneFile = trim((string)($options['done-file'] ?? ''));
    if (
        $databasePath === ''
        || $doneFile === ''
        || !str_starts_with($doneFile, '/')
    ) {
        throw new InvalidArgumentException(
            'Stress settler arguments are invalid'
        );
    }
    $db = $open($databasePath);
    $GLOBALS['CANONICAL_QUEUE_TEST_LOCK_PATH'] =
        $databasePath . '.canonical.lock';
    $configureCanonicalProcessor();
    $cycles = 0;
    $lockRetries = 0;
    $scoreCycles = 0;
    $settledCycles = 0;
    $scoreBackgroundLock =
        $databasePath . '.score-background.lock';
    $scoreHeartbeat =
        $databasePath . '.score-worker-heartbeat';
    $scoreStatus =
        $databasePath . '.score-worker-status';
    while ($cycles++ < 2400) {
        try {
            canonicalIngredientProcessQueue($db, 8, 3);
            recipeJobProcessQueue($db, 20, 3, false);
            $pendingScore = (int)$db->query("
                SELECT (
                    SELECT COUNT(*)
                    FROM recipe_score_pending_products
                ) + (
                    SELECT COUNT(*)
                    FROM recipe_score_pending_recipes
                )
            ")->fetchColumn();
            if ($pendingScore > 0) {
                $scorePipes = [];
                $scoreProcess = proc_open(
                    [
                        PHP_BINARY,
                        __DIR__ . '/incremental-score-worker.php',
                        '--db=' . $databasePath,
                        '--background-lock='
                            . $scoreBackgroundLock,
                        '--coordination-lock='
                            . $databasePath
                            . '.score-coordination.lock',
                        '--heartbeat=' . $scoreHeartbeat,
                        '--status-file=' . $scoreStatus,
                        '--force',
                        '--json',
                    ],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $scorePipes,
                    dirname(__DIR__)
                );
                if (!is_resource($scoreProcess)) {
                    throw new RuntimeException(
                        'Could not start stress score worker'
                    );
                }
                fclose($scorePipes[0]);
                $scoreStdout =
                    stream_get_contents($scorePipes[1]);
                $scoreStderr =
                    stream_get_contents($scorePipes[2]);
                fclose($scorePipes[1]);
                fclose($scorePipes[2]);
                $scoreStatusCode = proc_close($scoreProcess);
                $score = json_decode(
                    (string)$scoreStdout,
                    true
                );
                if (
                    $scoreStatusCode !== 0
                    || !is_array($score)
                ) {
                    throw new RuntimeException(
                        'Concurrent score worker failed with status '
                            . $scoreStatusCode . ': '
                            . $scoreStderr
                            . ' stdout=' . $scoreStdout
                    );
                }
                $scoreCycles++;
                if (
                    in_array(
                        (string)($score['reason'] ?? ''),
                        ['failed', 'full_rebuild_required'],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Concurrent score cycle failed: '
                        . recipeCatalogJsonEncode($score)
                    );
                }
            }
            $canonical = canonicalIngredientQueueStats($db, 3);
            $openJobs = (int)$db->query("
                SELECT COUNT(*) FROM recipe_jobs
                WHERE status IN ('pending', 'retry', 'in_progress')
            ")->fetchColumn();
            $pendingProducts = (int)$db->query("
                SELECT COUNT(*)
                FROM recipe_score_pending_products
            ")->fetchColumn();
            $pendingRecipes = (int)$db->query("
                SELECT COUNT(*)
                FROM recipe_score_pending_recipes
            ")->fetchColumn();
            $settled = is_file($doneFile)
                && $openJobs === 0
                && $pendingProducts === 0
                && $pendingRecipes === 0
                && (int)($canonical['pending'] ?? 0) === 0
                && (int)($canonical['in_progress'] ?? 0) === 0;
            $settledCycles = $settled
                ? $settledCycles + 1
                : 0;
            if ($settledCycles >= 3) {
                echo recipeCatalogJsonEncode([
                    'success' => true,
                    'cycles' => $cycles,
                    'lock_retries' => $lockRetries,
                    'score_cycles' => $scoreCycles,
                    'score_worker_status' =>
                        is_file($scoreStatus)
                            ? trim((string)file_get_contents(
                                $scoreStatus
                            ))
                            : null,
                ]) . PHP_EOL;
                exit(0);
            }
        } catch (Throwable $error) {
            databaseRollbackDanglingTransaction($db);
            if (!databaseIsLockError($error)) {
                throw $error;
            }
            $lockRetries++;
        }
        usleep(25000);
    }
    $canonical = canonicalIngredientQueueStats($db, 3);
    $diagnostics = [
        'cycles' => $cycles,
        'lock_retries' => $lockRetries,
        'score_cycles' => $scoreCycles,
        'done' => is_file($doneFile),
        'canonical' => $canonical,
        'recipe_jobs' => $db->query("
            SELECT id, job_type, status, attempts, last_error,
                   next_retry_at, updated_at
            FROM recipe_jobs
            WHERE status IN ('pending', 'retry', 'in_progress')
            ORDER BY id
        ")->fetchAll(PDO::FETCH_ASSOC),
        'pending_products' => (int)$db->query("
            SELECT COUNT(*) FROM recipe_score_pending_products
        ")->fetchColumn(),
        'pending_recipes' => (int)$db->query("
            SELECT COUNT(*) FROM recipe_score_pending_recipes
        ")->fetchColumn(),
        'work_state' => $db->query("
            SELECT * FROM recipe_score_work_state WHERE id = 1
        ")->fetch(PDO::FETCH_ASSOC) ?: [],
    ];
    throw new RuntimeException(
        'Concurrent stress settler did not converge: '
            . recipeCatalogJsonEncode($diagnostics)
    );
}

$workerMode = isset($options['worker-index']);
if ($workerMode) {
    $databasePath = trim((string)($options['db'] ?? ''));
    $runToken = trim((string)($options['run-token'] ?? ''));
    $startFile = trim((string)($options['start-file'] ?? ''));
    $index = (int)$options['worker-index'];
    if (
        $databasePath === ''
        || $runToken === ''
        || $startFile === ''
        || !str_starts_with($startFile, '/')
        || !isset($foods[$index])
    ) {
        throw new InvalidArgumentException(
            'Stress worker arguments are invalid'
        );
    }
    $startDeadline = microtime(true) + 5;
    while (!is_file($startFile)) {
        if (microtime(true) >= $startDeadline) {
            throw new RuntimeException(
                'Stress worker start barrier timed out'
            );
        }
        usleep(10000);
    }
    usleep(($index % 8) * 75000);
    $db = $open($databasePath);
    $GLOBALS['CANONICAL_QUEUE_TEST_WAKE'] =
        static fn(): bool => true;
    $capture = static function (callable $operation): array {
        http_response_code(200);
        ob_start();
        try {
            $operation();
            $payload = json_decode(
                (string)ob_get_clean(),
                true,
                128,
                JSON_THROW_ON_ERROR
            );
        } finally {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        if (!is_array($payload)) {
            throw new RuntimeException(
                'Stress worker response is invalid'
            );
        }
        return [
            'status' => http_response_code(),
            'payload' => $payload,
        ];
    };
    $name = $foods[$index];
    $workerStarted = hrtime(true);
    $barcodeNumber = sprintf(
        '%010u',
        crc32($runToken . ':' . $index . ':' . $name)
    );
    $barcode = '29' . substr($barcodeNumber, 0, 11);
    $attempts = 0;
    while (true) {
        $attempts++;
        try {
            $GLOBALS['PRODUCT_API_JSON_INPUT'] = [
                'barcode' => $barcode,
                'name' => $name,
                'brand' => 'EverShelf Stress',
                'category' => 'food',
                'unit' => 'pz',
                'default_quantity' => 1,
                'prepared_food' => false,
            ];
            try {
                $saved = $capture(static fn() => saveProduct($db));
            } finally {
                unset($GLOBALS['PRODUCT_API_JSON_INPUT']);
            }
            $productId = (int)($saved['payload']['id'] ?? 0);
            if (
                $saved['status'] !== 200
                || empty($saved['payload']['success'])
                || $productId <= 0
            ) {
                throw new RuntimeException(
                    'Stress product save failed: '
                    . recipeCatalogJsonEncode($saved)
                );
            }
            $owner = $db->prepare("
                SELECT id FROM products WHERE barcode = ?
            ");
            $owner->execute([$barcode]);
            $barcodeOwnerId = (int)($owner->fetchColumn() ?: 0);
            if ($barcodeOwnerId !== $productId) {
                throw new RuntimeException(
                    'Stress product response ID mismatch: '
                    . recipeCatalogJsonEncode([
                        'index' => $index,
                        'name' => $name,
                        'response_product_id' => $productId,
                        'barcode_owner_id' => $barcodeOwnerId,
                        'response' => $saved,
                    ])
                );
            }
            $GLOBALS['INVENTORY_ADD_INPUT'] = [
                'idempotency_key' =>
                    'stress-' . $runToken . '-' . $index,
                'product_id' => $productId,
                'quantity' => 1,
                'location' => $index % 3 === 0
                    ? 'freezer'
                    : ($index % 2 === 0 ? 'dispensa' : 'frigo'),
                'expiry_date' => (new DateTimeImmutable('today'))
                    ->modify($index % 2 === 0 ? '+2 days' : '+30 days')
                    ->format('Y-m-d'),
                'expiry_user_set' => true,
            ];
            try {
                $added = $capture(static fn() => addToInventory($db));
            } finally {
                unset($GLOBALS['INVENTORY_ADD_INPUT']);
            }
            if (
                $added['status'] !== 200
                || empty($added['payload']['success'])
            ) {
                throw new RuntimeException(
                    'Stress inventory add failed: '
                    . recipeCatalogJsonEncode($added)
                );
            }
            echo recipeCatalogJsonEncode([
                'success' => true,
                'index' => $index,
                'name' => $name,
                'product_id' => $productId,
                'inventory_id' =>
                    (int)$added['payload']['inventory_id'],
                'attempts' => $attempts,
                'elapsed_ms' => round(
                    (hrtime(true) - $workerStarted) / 1000000,
                    3
                ),
            ]) . PHP_EOL;
            exit(0);
        } catch (Throwable $error) {
            databaseRollbackDanglingTransaction($db);
            if (
                $attempts >= 20
                || !databaseIsLockError($error)
            ) {
                throw $error;
            }
            usleep(min(500000, 50000 * $attempts));
        }
    }
}

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
$percentile = static function (array $values, float $fraction): float {
    if (!$values) {
        return 0.0;
    }
    sort($values, SORT_NUMERIC);
    $index = (int)ceil(count($values) * $fraction) - 1;
    return (float)$values[
        max(0, min(count($values) - 1, $index))
    ];
};

$requestedPath = trim((string)($options['db'] ?? ''));
$temporary = $requestedPath === '';
$databasePath = $temporary
    ? dirname(__DIR__) . '/data/.inventory-flow-stress-'
        . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite'
    : recipeCliAssertDatabaseInputSafe($requestedPath, false);
$jsonOut = trim((string)($options['json-out'] ?? ''));
$runToken = substr(
    hash('sha256', $databasePath . ':' . microtime(true)),
    0,
    16
);
$cleanup = [
    $databasePath,
    $databasePath . '-wal',
    $databasePath . '-shm',
    $databasePath . '.migration.lock',
    $databasePath . '.canonical.lock',
    $databasePath . '.score-background.lock',
    $databasePath . '.score-coordination.lock',
    $databasePath . '.score-worker-heartbeat',
    $databasePath . '.score-worker-status',
    dirname($databasePath) . '/.' . basename($databasePath)
        . '.recipe-score.lock',
];
if ($temporary) {
    foreach ($cleanup as $path) {
        @unlink($path);
    }
    if (!isset($options['keep-db'])) {
        register_shutdown_function(
            static function () use ($cleanup): void {
                foreach ($cleanup as $path) {
                    @unlink($path);
                }
            }
        );
    }
}

$db = $open($databasePath);
if ($temporary) {
    initializeDB($db);
    migrateDB($db);
} else {
    databaseEnsureMigrated(
        $db,
        $databasePath . '.migration.lock'
    );
}
$GLOBALS['CANONICAL_QUEUE_TEST_LOCK_PATH'] =
    $databasePath . '.canonical.lock';

$baselineOpenRecipeJobs = (int)$db->query("
    SELECT COUNT(*) FROM recipe_jobs
    WHERE status IN ('pending', 'retry', 'in_progress')
")->fetchColumn();
$baselineCanonical = canonicalIngredientQueueStats($db, 3);
$baselinePendingProducts = (int)$db->query("
    SELECT COUNT(*) FROM recipe_score_pending_products
")->fetchColumn();
$baselinePendingRecipes = (int)$db->query("
    SELECT COUNT(*) FROM recipe_score_pending_recipes
")->fetchColumn();
$assert(
    $baselineOpenRecipeJobs === 0
    && (int)($baselineCanonical['pending'] ?? 0) === 0
    && (int)($baselineCanonical['in_progress'] ?? 0) === 0
    && $baselinePendingProducts === 0
    && $baselinePendingRecipes === 0,
    'Stress validation requires a settled baseline database'
);

$existing = [];
$existingStmt = $db->query("
    SELECT lower(trim(name))
    FROM products
    WHERE trim(name) <> ''
");
foreach ($existingStmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
    $existing[(string)$name] = true;
}
$active = recipeScoreActiveRevision($db);
$existingRecipe = null;
if (!$temporary) {
    if (
        $active === null
        || $active['ontology_version_id'] === null
        || (string)$active['scoring_model']
            !== INGREDIENT_ONTOLOGY_V3_SCORING_MODEL
    ) {
        throw new RuntimeException(
            'Production-shaped stress requires an active ontology score'
        );
    }
    $version = ingredientOntologyV3Version(
        $db,
        (int)$active['ontology_version_id']
    );
    if ($version === null || (string)$version['status'] !== 'ready') {
        throw new RuntimeException(
            'Production-shaped stress ontology is unavailable'
        );
    }
    $existingRecipe = $db->prepare("
        SELECT ingredient.id AS ingredient_id,
               ingredient.recipe_id,
               recipe.title
        FROM recipe_ingredients ingredient
        JOIN recipe_catalog recipe
          ON recipe.id = ingredient.recipe_id
         AND recipe.deleted_at IS NULL
        JOIN ingredient_ontology_recipe_identity_annex annex
          ON annex.recipe_ingredient_id = ingredient.id
         AND annex.ontology_version_id = ?
         AND annex.ontology_content_hash = ?
         AND annex.ontology_seal_hash = ?
         AND annex.resolver_version = ?
         AND annex.review_manifest_hash = ?
         AND annex.status = 'accepted'
        WHERE ingredient.normalized_name = ?
        ORDER BY ingredient.recipe_id
        LIMIT 1
    ");
}
$selectedFoods = [];
$recipeIds = [];
$recipeIngredientIds = [];
$recipeTitles = [];
foreach ($foods as $index => $name) {
    if (isset($existing[mb_strtolower($name, 'UTF-8')])) {
        continue;
    }
    if ($existingRecipe !== null) {
        $existingRecipe->execute([
            (int)$version['id'],
            (string)$version['content_hash'],
            (string)$version['seal_hash'],
            ingredientOntologyV3RecipeIdentityResolverVersion(),
            ingredientOntologyV3IdentityAnnexReviewManifestHash(),
            ingredientOntologyV3NormalizeLabel($name),
        ]);
        $existingRecipeRow =
            $existingRecipe->fetch(PDO::FETCH_ASSOC) ?: [];
        $recipeId = (int)($existingRecipeRow['recipe_id'] ?? 0);
        if ($recipeId <= 0) {
            continue;
        }
        $recipeIds[$index] = $recipeId;
        $recipeIngredientIds[$index] =
            (int)$existingRecipeRow['ingredient_id'];
        $recipeTitles[$index] =
            (string)$existingRecipeRow['title'];
    }
    $selectedFoods[$index] = $name;
    if (count($selectedFoods) === 16) {
        break;
    }
}
$assert(
    count($selectedFoods) === 16,
    'Stress corpus must contain sixteen foods unseen by the target database'
);

if ($temporary) {
    foreach ($selectedFoods as $index => $name) {
        $savedRecipe = recipeCatalogSaveVariant($db, [
            'title' => "Stress {$name} Supper",
            'language' => 'en',
            'ingredients' => [[
                'name' => mb_strtolower($name, 'UTF-8'),
                'is_required' => true,
            ]],
        ], [
            'connector' => 'manual',
            'external_id' =>
                "stress-{$runToken}-recipe-{$index}",
        ]);
        $recipeIds[$index] = (int)$savedRecipe['id'];
        $ingredient = $db->prepare("
            SELECT id FROM recipe_ingredients
            WHERE recipe_id = ?
            ORDER BY position, id
            LIMIT 1
        ");
        $ingredient->execute([$recipeIds[$index]]);
        $recipeIngredientIds[$index] =
            (int)$ingredient->fetchColumn();
        $recipeTitles[$index] = "Stress {$name} Supper";
    }
}

if (
    $active === null
    || $active['ontology_version_id'] === null
    || (string)$active['scoring_model']
        !== INGREDIENT_ONTOLOGY_V3_SCORING_MODEL
) {
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
            ?, 'building', ?, ?, ?,
            'gemini-3.5-flash', ?, ?, ?, ?, ?, ?,
            'test_only', 'stress fixture', 'test', ?, ?, ?,
            CURRENT_TIMESTAMP
        )
    ")->execute([
        'stress-v3-' . $runToken,
        ingredientOntologyV3SchemaHash(),
        ingredientOntologyV3PromptHash(),
        ingredientOntologyV3ModelHash('gemini-3.5-flash'),
        $hash,
        $hash,
        $hash,
        ingredientOntologyV3IdentityAnnexReviewManifestHash(),
        $hash,
        $hash,
        $hash,
        $hash,
        $hash,
    ]);
    $versionId = (int)$db->lastInsertId();
    $sealedCorpusHash = ingredientOntologyV3CorpusHash($db);
    $db->prepare("
        UPDATE ingredient_ontology_versions
        SET corpus_hash = ?,
            frozen_corpus_hash = ?
        WHERE id = ?
    ")->execute([
        $sealedCorpusHash,
        $sealedCorpusHash,
        $versionId,
    ]);
    $contentHash =
        ingredientOntologyV3ContentHash($db, $versionId);
    ingredientOntologyV3SetPublicationGuard($db, true);
    $db->prepare("
        UPDATE ingredient_ontology_versions
        SET content_hash = ?, status = 'ready',
            ready_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$contentHash, $versionId]);
    ingredientOntologyV3SetPublicationGuard($db, false);

    $state = recipeScoreState($db);
    $sourceHash = ingredientOntologyV3CorpusHash($db);
    $catalogFingerprint = recipeScoreCatalogFingerprint($db);
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
            ontology_source_lineage_hash,
            identity_extension_revision, identity_extension_hash,
            catalog_id_set_hash, ingredient_id_set_hash,
            score_rows_hash, match_rows_hash,
            materialization_hash, completed_at
        )
        VALUES (
            ?, ?, ?, ?, ?, 'ready', 0, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, '', 0, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP
        )
    ")->execute([
        (int)$state['inventory_revision'],
        (int)$state['catalog_revision'],
        hash('sha256', 'stress-empty-inventory'),
        recipeScoreCurrentDate(),
        recipeScoreCatalogMaxId($db),
        $versionId,
        INGREDIENT_ONTOLOGY_V3_SCORING_MODEL,
        ingredientOntologyV3ScoringConfigHash(),
        $catalogFingerprint,
        ingredientOntologyV3SchemaHash(),
        ingredientOntologyV3PromptHash(),
        ingredientOntologyV3ModelHash('gemini-3.5-flash'),
        $hash,
        $contentHash,
        $hash,
        ingredientOntologyV3IdentityAnnexReviewManifestHash(),
        $hash,
        $hash,
        (int)$state['ontology_source_revision'],
        $sourceHash,
        ingredientOntologyV3IdentityExtensionZeroHash(),
        $hash,
        $hash,
        $hash,
        $hash,
        $hash,
    ]);
    ingredientOntologyV3SetReadyMutationGuard($db, false);
    $seedRevisionId = (int)$db->lastInsertId();
    $db->prepare("
        UPDATE recipe_score_state
        SET active_score_revision_id = ?,
            ontology_source_hash = ?,
            ontology_source_lineage_hash = '',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ")->execute([$seedRevisionId, $sourceHash]);
    recipeScoreReadRevisionCacheClear();
    ingredientOntologyV3IdentityAdmissionSync($db);
    $shadow = ingredientOntologyV3BuildShadow(
        $db,
        $versionId,
        64
    );
    if (empty($shadow['built'])) {
        throw new RuntimeException(
            'Stress baseline shadow failed: '
            . recipeCatalogJsonEncode($shadow)
        );
    }
    $baselineRevisionId = (int)$shadow['revision_id'];
    recipeScoreBuildEffectiveProjection($db, $baselineRevisionId);
    $baseline = recipeScoreRevision($db, $baselineRevisionId);
    $db->prepare("
        UPDATE recipe_score_state
        SET active_score_revision_id = ?,
            active_score_overlay_revision_id = NULL,
            ontology_source_hash = ?,
            ontology_source_lineage_hash = ?,
            last_built_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ")->execute([
        $baselineRevisionId,
        (string)$baseline['ontology_source_hash'],
        (string)($baseline['ontology_source_lineage_hash'] ?? ''),
    ]);
    $db->exec('DELETE FROM recipe_score_pending_products');
    $db->exec('DELETE FROM recipe_score_pending_recipes');
    $db->exec('DELETE FROM recipe_score_mutations');
    recipeScoreReconcileWorkState($db);
    recipeScoreReadRevisionCacheClear();
    $active = recipeScoreActiveRevision($db);
}

$versionId = (int)$active['ontology_version_id'];
$assert(
    $versionId > 0
    && (string)$active['status'] === 'ready',
    'Stress target must have one ready active ontology score revision'
);

$configureCanonicalProcessor();

$runWave = static function (
    array $wave
) use (
    $databasePath,
    $runToken
): array {
    $doneFile = $databasePath . '.wave-'
        . min(array_keys($wave)) . '.done';
    $startFile = $doneFile . '.start';
    @unlink($doneFile);
    @unlink($startFile);
    $processes = [];
    foreach (array_keys($wave) as $index) {
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                __FILE__,
                '--worker-index=' . $index,
                '--db=' . $databasePath,
                '--run-token=' . $runToken,
                '--start-file=' . $startFile,
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
                "Could not start stress worker {$index}"
            );
        }
        fclose($pipes[0]);
        $processes[$index] = [$process, $pipes];
    }
    if (file_put_contents($startFile, 'start', LOCK_EX) === false) {
        throw new RuntimeException(
            'Could not release concurrent stress start barrier'
        );
    }
    usleep(50000);
    $settlerPipes = [];
    $settler = proc_open(
        [
            PHP_BINARY,
            __FILE__,
            '--settler',
            '--db=' . $databasePath,
            '--done-file=' . $doneFile,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $settlerPipes,
        dirname(__DIR__)
    );
    if (!is_resource($settler)) {
        throw new RuntimeException(
            'Could not start concurrent stress settler'
        );
    }
    fclose($settlerPipes[0]);
    $results = [];
    foreach ($processes as $index => [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $decoded = json_decode((string)$stdout, true);
        if (
            $status !== 0
            || !is_array($decoded)
            || empty($decoded['success'])
        ) {
            throw new RuntimeException(
                "Stress worker {$index} failed with status {$status}: "
                . $stderr . ' stdout=' . $stdout
            );
        }
        $results[$index] = $decoded;
    }
    if (file_put_contents($doneFile, 'done', LOCK_EX) === false) {
        throw new RuntimeException(
            'Could not signal concurrent stress settler'
        );
    }
    $settlerStdout = stream_get_contents($settlerPipes[1]);
    $settlerStderr = stream_get_contents($settlerPipes[2]);
    fclose($settlerPipes[1]);
    fclose($settlerPipes[2]);
    $settlerStatus = proc_close($settler);
    @unlink($doneFile);
    @unlink($startFile);
    $settlerResult = json_decode(
        (string)$settlerStdout,
        true
    );
    if (
        $settlerStatus !== 0
        || !is_array($settlerResult)
        || empty($settlerResult['success'])
    ) {
        throw new RuntimeException(
            'Concurrent stress settler failed with status '
                . $settlerStatus . ': ' . $settlerStderr
                . ' stdout=' . $settlerStdout
        );
    }
    ksort($results, SORT_NUMERIC);
    return [
        'workers' => $results,
        'settler' => $settlerResult,
    ];
};

$settle = static function (PDO $db): array {
    $canonical = null;
    for ($attempt = 1; $attempt <= 20; $attempt++) {
        try {
            $canonical = canonicalIngredientDrainQueue(
                $db,
                50,
                3,
                20
            );
            break;
        } catch (Throwable $error) {
            databaseRollbackDanglingTransaction($db);
            if (!databaseIsLockError($error) || $attempt === 20) {
                throw $error;
            }
            usleep(25000 * $attempt);
        }
    }
    if (!is_array($canonical)) {
        throw new RuntimeException(
            'Canonical stress settlement did not complete'
        );
    }
    $recipeBatches = [];
    for ($batch = 0; $batch < 10; $batch++) {
        $result = recipeJobProcessQueue($db, 100, 3, false);
        $recipeBatches[] = $result;
        if (
            (int)$db->query("
                SELECT COUNT(*) FROM recipe_jobs
                WHERE status IN ('pending', 'retry', 'in_progress')
            ")->fetchColumn() === 0
        ) {
            break;
        }
    }
    $scoreCycles = [];
    for ($cycle = 0; $cycle < 10; $cycle++) {
        $score = ingredientOntologyV3IncrementalRebuild(
            $db,
            true,
            100
        );
        $scoreCycles[] = $score;
        if (
            (int)$db->query("
                SELECT COUNT(*)
                FROM recipe_score_pending_products
            ")->fetchColumn() === 0
            && (int)$db->query("
                SELECT COUNT(*)
                FROM recipe_score_pending_recipes
            ")->fetchColumn() === 0
        ) {
            break;
        }
        if (
            in_array(
                (string)($score['reason'] ?? ''),
                ['failed', 'full_rebuild_required'],
                true
            )
        ) {
            break;
        }
        usleep(50000);
    }
    return [
        'canonical' => $canonical,
        'recipe_batches' => $recipeBatches,
        'score_cycles' => $scoreCycles,
    ];
};

$waveIndexes = array_chunk(array_keys($selectedFoods), 8);
$waves = [];
$productIds = [];
$waveElapsed = [];
$workerElapsed = [];
foreach ($waveIndexes as $waveNumber => $indexes) {
    $wave = array_intersect_key(
        $selectedFoods,
        array_fill_keys($indexes, true)
    );
    $started = hrtime(true);
    $concurrent = $runWave($wave);
    $workers = $concurrent['workers'];
    $settled = [
        'canonical' => canonicalIngredientQueueStats($db, 3),
        'recipe_batches' => [],
        'score_cycles' => [],
    ];
    $elapsedMs = (hrtime(true) - $started) / 1000000;
    $waveElapsed[] = $elapsedMs;
    foreach ($workers as $index => $result) {
        $productIds[$index] = (int)$result['product_id'];
        $workerElapsed[] = (float)$result['elapsed_ms'];
    }
    $waves[] = [
        'wave' => $waveNumber + 1,
        'foods' => array_values($wave),
        'workers' => array_values($workers),
        'concurrent_settler' => $concurrent['settler'],
        'elapsed_ms' => round($elapsedMs, 3),
        'settled' => $settled,
    ];
}
ksort($productIds, SORT_NUMERIC);
$assert(
    count(array_filter(
        $waves,
        static fn(array $wave): bool =>
            (int)(
                $wave['concurrent_settler']['score_cycles']
                    ?? 0
            ) > 0
            && str_starts_with(
                (string)(
                    $wave['concurrent_settler'][
                        'score_worker_status'
                    ] ?? ''
                ),
                '0 '
            )
    )) === count($waves),
    'Every wave must overlap the production score worker entrypoint'
);
$db = null;
$db = $open($databasePath);

$pendingProducts = (int)$db->query("
    SELECT COUNT(*) FROM recipe_score_pending_products
")->fetchColumn();
$pendingRecipes = (int)$db->query("
    SELECT COUNT(*) FROM recipe_score_pending_recipes
")->fetchColumn();
$openRecipeJobs = (int)$db->query("
    SELECT COUNT(*) FROM recipe_jobs
    WHERE status IN ('pending', 'retry', 'in_progress')
")->fetchColumn();
$canonicalStats = canonicalIngredientQueueStats($db, 3);
$assert(
    $pendingProducts === 0
    && $pendingRecipes === 0
    && $openRecipeJobs === 0
    && (int)($canonicalStats['pending'] ?? 0) === 0
    && (int)($canonicalStats['in_progress'] ?? 0) === 0,
    'Both waves must settle every ingestion and scoring queue'
);

$active = recipeScoreActiveRevision($db);
$assert(
    $active !== null
    && (string)$active['status'] === 'ready'
    && recipeScoreRevisionStatus($db, $active) === 'fresh',
    'The final active score revision must be ready and fresh'
);

$soonExpiryScores = [];
$laterExpiryScores = [];
$identityRows = [];
$searchRows = [];
foreach ($productIds as $index => $productId) {
    $name = $selectedFoods[$index];
    $readiness =
        ingredientOntologyV3ProductReadinessRow($db, $productId);
    $productAnnex = $db->prepare("
        SELECT status,
               CASE
                   WHEN entity_id IS NOT NULL THEN entity_id
                   ELSE -extension_entity_id
               END AS effective_entity_id
        FROM ingredient_ontology_identity_annex
        WHERE product_id = ? AND ontology_version_id = ?
    ");
    $productAnnex->execute([$productId, $versionId]);
    $productIdentity =
        $productAnnex->fetch(PDO::FETCH_ASSOC) ?: [];
    $recipeId = $recipeIds[$index];
    $recipeAnnex = $db->prepare("
        SELECT annex.status,
               CASE
                   WHEN annex.entity_id IS NOT NULL
                   THEN annex.entity_id
                   ELSE -annex.extension_entity_id
               END AS effective_entity_id
        FROM ingredient_ontology_recipe_identity_annex annex
        WHERE annex.recipe_ingredient_id = ?
          AND annex.ontology_version_id = ?
    ");
    $recipeAnnex->execute([
        $recipeIngredientIds[$index],
        $versionId,
    ]);
    $recipeIdentity =
        $recipeAnnex->fetch(PDO::FETCH_ASSOC) ?: [];
    $scoreStmt = $db->prepare("
        SELECT score.coverage, score.directness,
               score.expiry_score, score.matched_required_count,
               score.missing_required_count, score.cookable
        FROM recipe_score_effective_sources source
        JOIN recipe_inventory_scores score
          ON score.score_revision_id = source.score_revision_id
         AND score.recipe_id = source.recipe_id
        WHERE source.recipe_id = ?
    ");
    $scoreStmt->execute([$recipeId]);
    $score = $scoreStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $matchStmt = $db->prepare("
        SELECT match.satisfies_required,
               match.inventory_product_id,
               match.explanation_json
        FROM recipe_score_effective_sources source
        JOIN ingredient_ontology_shadow_matches match
          ON match.score_revision_id = source.score_revision_id
         AND match.recipe_ingredient_id = ?
        WHERE source.recipe_id = ?
    ");
    $matchStmt->execute([
        $recipeIngredientIds[$index],
        $recipeId,
    ]);
    $targetMatch = $matchStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $targetExplanation = json_decode(
        (string)($targetMatch['explanation_json'] ?? '{}'),
        true
    );
    $targetExplanation = is_array($targetExplanation)
        ? $targetExplanation
        : [];
    $targetProductIds = array_map(
        'intval',
        (array)(
            $targetExplanation['inventory_aggregate']['product_ids']
                ?? []
        )
    );
    if ((int)($targetMatch['inventory_product_id'] ?? 0) > 0) {
        $targetProductIds[] =
            (int)$targetMatch['inventory_product_id'];
    }
    $ingredientSearch = recipeCatalogSearchResult($db, [
        'query' => $name,
        'mode' => 'all',
        'fields' => 'card',
        'limit' => 10,
    ]);
    $titleSearch = recipeCatalogSearchResult($db, [
        'query' => $recipeTitles[$index],
        'mode' => 'all',
        'fields' => 'card',
        'limit' => 10,
    ]);
    $titleRecipeIds = array_map(
        static fn(array $item): int => (int)$item['id'],
        (array)($titleSearch['items'] ?? [])
    );
    $assert(
        (string)($readiness['status'] ?? '') === 'ready'
        && (string)($productIdentity['status'] ?? '') === 'accepted'
        && (string)($recipeIdentity['status'] ?? '') === 'accepted'
        && (int)($productIdentity['effective_entity_id'] ?? 0) !== 0
        && (int)($productIdentity['effective_entity_id'] ?? 0)
            === (int)($recipeIdentity['effective_entity_id'] ?? 0),
        "{$name} must converge automatically to one ready identity: "
            . recipeCatalogJsonEncode([
                'readiness' => $readiness,
                'product_id' => $productId,
                'product_identity' => $productIdentity,
                'recipe_identity' => $recipeIdentity,
                'recipe_id' => $recipeId,
                'recipe_ingredient_id' =>
                    $recipeIngredientIds[$index],
                'ontology_version_id' => $versionId,
            ])
    );
    if ($temporary) {
        $assert(
            (float)($score['coverage'] ?? 0) >= 1.0
            && (float)($score['directness'] ?? 0) >= 1.0
            && (int)($score['matched_required_count'] ?? 0) === 1
            && (int)($score['missing_required_count'] ?? -1) === 0
            && !empty($score['cookable']),
            "{$name} must publish a directly cookable recipe score"
        );
    } else {
        $assert(
            !empty($targetMatch['satisfies_required'])
            && in_array($productId, $targetProductIds, true)
            && (float)($score['coverage'] ?? 0) > 0
            && (float)($score['directness'] ?? 0) > 0
            && (int)($score['matched_required_count'] ?? 0) > 0,
            "{$name} must contribute an exact in-stock recipe match"
        );
    }
    $assert(
        (int)($ingredientSearch['total'] ?? 0) > 0
        && in_array($recipeId, $titleRecipeIds, true),
        "{$name} must be searchable by ingredient and recipe title"
    );
    $expiryScore = (float)($score['expiry_score'] ?? 0);
    if ($index % 2 === 0) {
        $soonExpiryScores[] = $expiryScore;
    } else {
        $laterExpiryScores[] = $expiryScore;
    }
    $identityRows[] = [
        'name' => $name,
        'product_id' => $productId,
        'recipe_id' => $recipeId,
        'entity_id' =>
            (int)$productIdentity['effective_entity_id'],
        'readiness' => (string)$readiness['status'],
        'coverage' => (float)$score['coverage'],
        'directness' => (float)$score['directness'],
        'expiry_score' => $expiryScore,
    ];
    $searchRows[] = [
        'query' => $name,
        'recipe_id' => $recipeId,
        'found' => true,
    ];
}

if ($temporary) {
    $assert(
        min($soonExpiryScores) > max($laterExpiryScores),
        'Expiring-soon ingredients must receive strictly higher expiry weight'
    );
}
$assert(
    max($workerElapsed) < 15000
    && max($waveElapsed) < 65000,
    'Concurrent scan and settle latency must remain bounded'
);
$assert(
    $db->query('PRAGMA integrity_check')->fetchColumn() === 'ok',
    'Concurrent ingestion must leave the SQLite database valid'
);
$assert(
    (int)$db->query("
        SELECT COUNT(*)
        FROM ingredient_ontology_product_readiness
        WHERE product_id IN ("
        . implode(',', array_map('intval', $productIds))
        . ")
          AND status IN ('needs_review', 'failed')
    ")->fetchColumn() === 0,
    'No held-out food may land in needs_review or failed'
);

$report = [
    'success' => true,
    'assertions' => $assertions,
    'database' => basename($databasePath),
    'temporary_database' => $temporary,
    'run_token' => $runToken,
    'wave_count' => count($waves),
    'wave_size' => 8,
    'food_count' => count($productIds),
    'wave_elapsed_ms' => array_map(
        static fn(float $value): float => round($value, 3),
        $waveElapsed
    ),
    'wave_p95_ms' => round($percentile($waveElapsed, 0.95), 3),
    'scan_latency_ms' => [
        'p50' => round($percentile($workerElapsed, 0.50), 3),
        'p95' => round($percentile($workerElapsed, 0.95), 3),
        'max' => round(max($workerElapsed), 3),
    ],
    'pending' => [
        'products' => $pendingProducts,
        'recipes' => $pendingRecipes,
        'recipe_jobs' => $openRecipeJobs,
        'canonical' => (int)($canonicalStats['pending'] ?? 0),
    ],
    'active_score_revision_id' => (int)$active['id'],
    'identities' => $identityRows,
    'searches' => $searchRows,
    'waves' => $waves,
];
$encoded = json_encode(
    $report,
    JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
) . PHP_EOL;
if ($jsonOut !== '') {
    $outputPath = recipeCliAssertOutputPathSafe(
        $jsonOut,
        $databasePath
    );
    recipeCliWriteFileAtomically($outputPath, $encoded);
}
echo $encoded;

unset(
    $GLOBALS['CANONICAL_QUEUE_TEST_WAKE'],
    $GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'],
    $GLOBALS['CANONICAL_QUEUE_TEST_LOCK_PATH']
);
$db = null;
if ($temporary && !isset($options['keep-db'])) {
    foreach ($cleanup as $path) {
        @unlink($path);
    }
}
