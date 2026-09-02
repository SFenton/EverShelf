#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        continue;
    }
    $parts = explode('=', substr($argument, 2), 2);
    $options[$parts[0]] = $parts[1] ?? true;
}

$requestedPath = trim((string)($options['db'] ?? ''));
if ($requestedPath === '') {
    throw new InvalidArgumentException('--db is required');
}
if (
    (string)($options['confirm-disposable'] ?? '')
        !== 'NEW-INGREDIENT-FLOW'
) {
    throw new InvalidArgumentException(
        '--confirm-disposable=NEW-INGREDIENT-FLOW is required'
    );
}
$databasePath = recipeCliAssertDatabaseInputSafe(
    $requestedPath,
    false
);
if (!preg_match(
    '/(?:benchmark|disposable|scratch|test)/i',
    basename($databasePath)
)) {
    throw new InvalidArgumentException(
        'database name must identify a disposable copy'
    );
}
$jsonOut = trim((string)($options['json-out'] ?? ''));
if ($jsonOut !== '') {
    $jsonOut = recipeCliAssertOutputPathSafe(
        $jsonOut,
        $databasePath
    );
}
$processProvider = isset($options['process-provider']);
$dryRun = isset($options['dry-run']);
$maximumScoreCycles = max(
    1,
    min(100, (int)($options['max-score-cycles'] ?? 30))
);
$maximumCanonicalBatches = max(
    1,
    min(20, (int)($options['max-canonical-batches'] ?? 8))
);
$maximumLocalBatches = max(
    1,
    min(20, (int)($options['max-local-batches'] ?? 8))
);
$maximumProviderJobs = max(
    0,
    min(50, (int)($options['max-provider-jobs'] ?? 8))
);
$requestedIngredient = trim((string)(
    $options['ingredient'] ?? ''
));

$GLOBALS['CANONICAL_QUEUE_TEST_LOCK_PATH'] =
    $databasePath . '.canonical-queue.lock';
$GLOBALS['CANONICAL_QUEUE_TEST_WAKE'] =
    static fn(): bool => true;
$GLOBALS['RECIPE_QUEUE_TEST_WAKE'] =
    static fn(): bool => true;

foreach ([
    'GEMINI_API_KEY',
    'GOOGLE_API_KEY',
    'GOOGLE_GENERATIVE_AI_API_KEY',
] as $key) {
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}
putenv('RECIPE_SCORE_INCREMENTAL_PRODUCT_LIMIT=250');

$db = new PDO(
    'sqlite:' . $databasePath,
    null,
    null,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA busy_timeout=5000');
$db->exec('PRAGMA synchronous=NORMAL');
$db->exec('PRAGMA cache_size=-8000');
$db->exec('PRAGMA temp_store=FILE');
ingredientOntologyV3RegisterGuardFunctions($db);

$monotonicStart = hrtime(true);
$elapsedMs = static function () use (&$monotonicStart): float {
    return (hrtime(true) - $monotonicStart) / 1000000;
};
$stageRows = [];
$recordStage = static function (
    string $stage,
    string $status,
    float $durationMs,
    array $details = []
) use (&$stageRows, $elapsedMs): void {
    $stageRows[] = [
        'stage' => $stage,
        'status' => $status,
        'duration_ms' => round($durationMs, 3),
        'time_to_stage_ms' => round($elapsedMs(), 3),
        'details' => $details,
    ];
};
$activeRecipeCount = static function (PDO $db): int {
    return (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_catalog
        WHERE deleted_at IS NULL
    ")->fetchColumn();
};
$pendingCounts = static function (PDO $db): array {
    $active = recipeScoreActiveRevision($db);
    $versionId = (int)($active['ontology_version_id'] ?? 0);
    return [
        'products' => (int)$db->query("
            SELECT COUNT(*) FROM recipe_score_pending_products
        ")->fetchColumn(),
        'recipes' => (int)$db->query("
            SELECT COUNT(*) FROM recipe_score_pending_recipes
        ")->fetchColumn(),
        'identity' => $versionId > 0
            ? ingredientOntologyV3IdentityProjectionPendingCount(
                $db,
                $versionId
            )
            : 0,
        'fanout' => (int)$db->query("
            SELECT COUNT(*) FROM recipe_score_product_fanout_state
        ")->fetchColumn(),
    ];
};
$scoreSettled = static function (PDO $db) use (
    $pendingCounts
): bool {
    $state = recipeScoreState($db);
    $active = recipeScoreActiveRevision($db);
    if ($active === null) {
        return false;
    }
    $pin = ingredientOntologyV3CorpusAnnexForScore($db, $active);
    if ($pin === null) {
        return false;
    }
    if (!ingredientOntologyV3CorpusAnnexProjectionReady(
        $db,
        $pin
    )) {
        return false;
    }
    $identity =
        ingredientOntologyV3IdentityExtensionSnapshot(
            $db,
            (int)$active['ontology_version_id']
        );
    return array_sum($pendingCounts($db)) === 0
        && (string)$active['score_date']
            === recipeScoreCurrentDate()
        && (int)$active['covered_catalog_revision']
            >= (int)$state['catalog_revision']
        && (int)$pin['covered_ontology_source_revision']
            >= (int)$state['ontology_source_revision']
        && (int)$pin['covered_identity_extension_revision']
            >= (int)$identity['revision'];
};
$scoreSources = static function (
    PDO $db,
    array $recipeIds
): array {
    $recipeIds = array_values(array_unique(array_filter(
        array_map('intval', $recipeIds),
        static fn(int $id): bool => $id > 0
    )));
    if (!$recipeIds) {
        return [];
    }
    $sources = [];
    foreach (array_chunk($recipeIds, 500) as $chunk) {
        $placeholders = implode(
            ',',
            array_fill(0, count($chunk), '?')
        );
        $stmt = $db->prepare("
            SELECT recipe_id, score_revision_id
            FROM recipe_score_effective_sources
            WHERE recipe_id IN ({$placeholders})
        ");
        $stmt->execute($chunk);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sources[(int)$row['recipe_id']] =
                (int)$row['score_revision_id'];
        }
    }
    ksort($sources, SORT_NUMERIC);
    return $sources;
};
$workerCommand = static function (
    string $databasePath,
    string $token
): array {
    $command = [PHP_BINARY];
    $ini = php_ini_loaded_file();
    if (is_string($ini) && $ini !== '') {
        $command[] = '-c';
        $command[] = $ini;
    }
    array_push(
        $command,
        '-d',
        'memory_limit=512M',
        __DIR__ . '/incremental-score-worker.php',
        '--db=' . $databasePath,
        '--background-lock=' . $databasePath
            . ".{$token}.background",
        '--coordination-lock=' . $databasePath
            . ".{$token}.coordination",
        '--heartbeat=' . $databasePath . ".{$token}.heartbeat",
        '--status-file=' . $databasePath . ".{$token}.status",
        '--force',
        '--json',
        '--benchmark-metrics'
    );
    return $command;
};
$runScoreWorker = static function (
    string $databasePath,
    int $cycle
) use ($workerCommand): array {
    $token = 'ingredient-flow-' . getmypid() . '-' . $cycle;
    $stdoutPath = $databasePath . ".{$token}.stdout";
    $stderrPath = $databasePath . ".{$token}.stderr";
    $pipes = [];
    $process = proc_open(
        $workerCommand($databasePath, $token),
        [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        throw new RuntimeException(
            'incremental score worker could not start'
        );
    }
    fclose($pipes[0]);
    $status = proc_close($process);
    $stdout = (string)@file_get_contents($stdoutPath);
    $stderr = (string)@file_get_contents($stderrPath);
    foreach ([
        '.background',
        '.coordination',
        '.heartbeat',
        '.status',
        '.stdout',
        '.stderr',
    ] as $suffix) {
        @unlink($databasePath . ".{$token}{$suffix}");
    }
    $payload = json_decode(trim((string)$stdout), true);
    if ($status !== 0 || !is_array($payload)) {
        throw new RuntimeException(
            'incremental score worker failed: '
                . ingredientOntologyV3Json([
                    'status' => $status,
                    'stdout' => mb_substr(
                        (string)$stdout,
                        0,
                        2000,
                        'UTF-8'
                    ),
                    'stderr' => mb_substr(
                        (string)$stderr,
                        0,
                        2000,
                        'UTF-8'
                    ),
                ])
        );
    }
    return $payload;
};
$jobIdsOutstanding = static function (
    PDO $db,
    array $jobIds
): int {
    $jobIds = array_values(array_unique(array_filter(
        array_map('intval', $jobIds),
        static fn(int $id): bool => $id > 0
    )));
    if (!$jobIds) {
        return 0;
    }
    $placeholders = implode(
        ',',
        array_fill(0, count($jobIds), '?')
    );
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_jobs
        WHERE id IN ({$placeholders})
          AND status IN ('pending', 'retry', 'in_progress')
    ");
    $stmt->execute($jobIds);
    return (int)$stmt->fetchColumn();
};
$newJobCount = static function (
    PDO $db,
    int $afterJobId,
    ?int $productId,
    string $lane,
    bool $onlyOutstanding
): int {
    $laneSql = $lane === 'provider'
        ? "connector = 'cookidoo'"
        : "(connector IS NULL OR connector <> 'cookidoo')";
    $outstandingSql = $onlyOutstanding
        ? "AND status IN ('pending', 'retry', 'in_progress')"
        : '';
    $productSql = $productId !== null
        ? 'AND product_id = ?'
        : '';
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_jobs
        WHERE id > ?
          AND {$laneSql}
          {$productSql}
          {$outstandingSql}
    ");
    $params = [$afterJobId];
    if ($productId !== null) {
        $params[] = $productId;
    }
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
};

$preflightStarted = hrtime(true);
databaseEnsureMigrated(
    $db,
    $databasePath . '.migration.lock'
);
$db->exec("DELETE FROM recipe_worker_leases");
$effectiveProductLimit =
    ingredientOntologyV3IncrementalProductLimit();
if ($effectiveProductLimit !== 250) {
    throw new RuntimeException(
        'timing run requires effective incremental limit 250; got '
            . $effectiveProductLimit
    );
}
$backfillPages = 0;
do {
    $backfill =
        ingredientOntologyV3CorpusAnnexReconciliationBackfill(
            $db,
            5000
        );
    $backfillPages++;
    if ($backfillPages > 1000) {
        throw new RuntimeException(
            'reconciliation backfill did not converge'
        );
    }
} while (empty($backfill['complete']));
$preflightScoreDateBefore = (string)(
    recipeScoreActiveRevision($db)['score_date'] ?? ''
);
$preflightRolloverProductId = null;
if ($preflightScoreDateBefore !== recipeScoreCurrentDate()) {
    $rolloverProduct = $db->query("
        SELECT inventory.product_id
        FROM inventory
        LEFT JOIN (
            SELECT contributor.product_id,
                   COUNT(DISTINCT contributor.recipe_id)
                       AS recipe_count
            FROM recipe_score_match_contributors contributor
            JOIN recipe_score_effective_sources source
              ON source.recipe_id = contributor.recipe_id
             AND source.score_revision_id =
                    contributor.score_revision_id
            WHERE contributor.semantic = 1
            GROUP BY contributor.product_id
        ) dependency
          ON dependency.product_id = inventory.product_id
        WHERE inventory.quantity > 0
        GROUP BY inventory.product_id
        ORDER BY COALESCE(dependency.recipe_count, 0),
                 inventory.product_id
        LIMIT 1
    ")->fetchColumn();
    if ($rolloverProduct === false) {
        throw new RuntimeException(
            'score-date preflight has no stocked product'
        );
    }
    $preflightRolloverProductId = (int)$rolloverProduct;
    recipeScoreMarkProductDirty(
        $db,
        $preflightRolloverProductId,
        'new_ingredient_flow_score_date_preflight'
    );
}
$preflightScoreCycles = [];
for ($cycle = 1; $cycle <= $maximumScoreCycles; $cycle++) {
    if ($scoreSettled($db)) {
        break;
    }
    $preflightScoreCycles[] =
        $runScoreWorker($databasePath, -$cycle);
}
if (!$scoreSettled($db)) {
    throw new RuntimeException(
        'disposable baseline did not settle before the timed run'
    );
}
$preflightMs = (hrtime(true) - $preflightStarted) / 1000000;

$activeBefore = recipeScoreActiveRevision($db);
if (
    $activeBefore === null
    || (string)$activeBefore['status'] !== 'ready'
    || (string)$activeBefore['scoring_model']
        !== INGREDIENT_ONTOLOGY_V3_SCORING_MODEL
) {
    throw new RuntimeException(
        'timing run requires an active ready ontology-v3 score'
    );
}
$versionId = (int)$activeBefore['ontology_version_id'];
$version = ingredientOntologyV3Version($db, $versionId);
if ($version === null || (string)$version['status'] !== 'ready') {
    throw new RuntimeException(
        'timing run ontology version is unavailable'
    );
}

$nativeCandidates = $db->prepare("
    SELECT entity.id AS entity_id,
           entity.slug AS entity_slug,
           canonical.id AS canonical_ingredient_id,
           canonical.name AS product_name,
           node.id AS taxonomy_node_id,
           COUNT(DISTINCT ingredient.recipe_id) AS recipe_count
    FROM ingredient_ontology_recipe_identity_annex annex
    JOIN recipe_ingredients ingredient
      ON ingredient.id = annex.recipe_ingredient_id
    JOIN recipe_catalog recipe
      ON recipe.id = ingredient.recipe_id
     AND recipe.deleted_at IS NULL
    JOIN ingredient_ontology_entities entity
      ON entity.id = annex.entity_id
     AND entity.ontology_version_id = annex.ontology_version_id
     AND entity.active = 1
    JOIN canonical_ingredients canonical
      ON canonical.id = entity.legacy_canonical_ingredient_id
    JOIN taxonomy_trees tree
      ON tree.slug = 'food'
    JOIN taxonomy_nodes node
      ON node.tree_id = tree.id
     AND node.slug = canonical.slug
     AND node.active = 1
    WHERE annex.ontology_version_id = ?
      AND annex.status = 'accepted'
      AND annex.entity_id IS NOT NULL
      AND entity.entity_kind = 'ingredient'
      AND NOT EXISTS (
          SELECT 1
          FROM ingredient_ontology_identity_annex product_annex
          JOIN inventory stock
            ON stock.product_id = product_annex.product_id
           AND stock.quantity > 0
          WHERE product_annex.ontology_version_id =
                    annex.ontology_version_id
            AND product_annex.status = 'accepted'
            AND product_annex.entity_id = entity.id
      )
      AND NOT EXISTS (
          SELECT 1
          FROM products product
          WHERE lower(trim(product.name)) =
                lower(trim(canonical.name))
      )
    GROUP BY entity.id, entity.slug, canonical.id,
             canonical.name, node.id
    HAVING COUNT(DISTINCT ingredient.recipe_id)
        BETWEEN 8 AND 80
    ORDER BY recipe_count DESC, length(canonical.name),
             canonical.name, entity.id
    LIMIT 200
");
$nativeCandidates->execute([$versionId]);
$candidate = null;
foreach ($nativeCandidates->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (
        $requestedIngredient !== ''
        && ingredientOntologyV3NormalizeLabel(
            $requestedIngredient
        ) !== ingredientOntologyV3NormalizeLabel(
            (string)$row['product_name']
        )
    ) {
        continue;
    }
    $resolution = ingredientOntologyV3IdentityAnnexResolution(
        $db,
        $version,
        [
            'id' => 0,
            'name' => (string)$row['product_name'],
            'brand' => '',
            'category' => 'food',
            'prepared_food' => 0,
        ],
        false,
        false,
        false
    );
    if (
        (string)$resolution['status'] === 'accepted'
        && (int)$resolution['entity_id'] === (int)$row['entity_id']
    ) {
        $candidate = $row + [
            'selection_mode' => 'native_taxonomy',
            'resolution' => $resolution,
        ];
        break;
    }
}
if ($candidate === null) {
    $fallback = $db->prepare("
        SELECT mapping.normalized_label,
               MIN(
                   CASE
                       WHEN trim(mapping.source_label) <> ''
                       THEN mapping.source_label
                       ELSE ingredient.raw_text
                   END
               ) AS product_name,
               COUNT(DISTINCT ingredient.recipe_id)
                   AS recipe_count
        FROM ingredient_ontology_mappings mapping
        JOIN recipe_ingredients ingredient
          ON ingredient.id = mapping.owner_id
        JOIN recipe_catalog recipe
          ON recipe.id = ingredient.recipe_id
         AND recipe.deleted_at IS NULL
        WHERE mapping.ontology_version_id = ?
          AND mapping.owner_type = 'recipe_ingredient'
          AND mapping.status IN ('unresolved', 'rejected')
          AND lower(replace(mapping.language, '_', '-')) = 'en'
          AND length(mapping.normalized_label) BETWEEN 3 AND 80
          AND NOT EXISTS (
              SELECT 1
              FROM ingredient_ontology_identity_extension_entities
                  extension
              WHERE extension.ontology_version_id =
                        mapping.ontology_version_id
                AND extension.normalized_label =
                        mapping.normalized_label
                AND extension.language = 'en'
                AND extension.context_signature = ''
                AND extension.status = 'active'
          )
        GROUP BY mapping.normalized_label
        HAVING COUNT(DISTINCT ingredient.recipe_id)
            BETWEEN 8 AND 80
        ORDER BY recipe_count DESC, mapping.normalized_label
        LIMIT 200
    ");
    $fallback->execute([$versionId]);
    foreach ($fallback->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = trim((string)$row['product_name']);
        if (
            $name === ''
            || ingredientOntologyV3NormalizeLabel($name)
                !== (string)$row['normalized_label']
            || (
                $requestedIngredient !== ''
                && ingredientOntologyV3NormalizeLabel(
                    $requestedIngredient
                ) !== (string)$row['normalized_label']
            )
        ) {
            continue;
        }
        $existingProduct = $db->prepare("
            SELECT 1
            FROM products
            WHERE lower(trim(name)) = lower(trim(?))
            LIMIT 1
        ");
        $existingProduct->execute([$name]);
        if ($existingProduct->fetchColumn() !== false) {
            continue;
        }
        $resolution = ingredientOntologyV3IdentityAnnexResolution(
            $db,
            $version,
            [
                'id' => 0,
                'name' => $name,
                'brand' => '',
                'category' => 'food',
                'prepared_food' => 0,
            ],
            false,
            false,
            false
        );
        $eligibility =
            ingredientOntologyV3IdentityExtensionEligibility(
                $version,
                $name,
                ingredientOntologyV3ProductIdentityLanguage()
            );
        if (
            (string)$resolution['status'] !== 'accepted'
            && !empty($eligibility['eligible'])
        ) {
            $candidate = $row + [
                'selection_mode' => 'exact_self_extension',
                'resolution' => $resolution,
                'entity_id' => null,
                'entity_slug' => null,
                'canonical_ingredient_id' => null,
                'taxonomy_node_id' => null,
            ];
            break;
        }
    }
}
if ($candidate === null) {
    throw new RuntimeException(
        'no new ingredient candidate satisfies the benchmark gates'
    );
}

$productName = trim((string)$candidate['product_name']);
$expectedRecipeIds = [];
if ((string)$candidate['selection_mode'] === 'native_taxonomy') {
    $expectedRecipes = $db->prepare("
        SELECT DISTINCT ingredient.recipe_id
        FROM ingredient_ontology_recipe_identity_annex annex
        JOIN recipe_ingredients ingredient
          ON ingredient.id = annex.recipe_ingredient_id
        JOIN recipe_catalog recipe
          ON recipe.id = ingredient.recipe_id
         AND recipe.deleted_at IS NULL
        WHERE annex.ontology_version_id = ?
          AND annex.status = 'accepted'
          AND annex.entity_id = ?
        ORDER BY ingredient.recipe_id
    ");
    $expectedRecipes->execute([
        $versionId,
        (int)$candidate['entity_id'],
    ]);
} else {
    $expectedRecipes = $db->prepare("
        SELECT DISTINCT ingredient.recipe_id
        FROM ingredient_ontology_mappings mapping
        JOIN recipe_ingredients ingredient
          ON ingredient.id = mapping.owner_id
        JOIN recipe_catalog recipe
          ON recipe.id = ingredient.recipe_id
         AND recipe.deleted_at IS NULL
        WHERE mapping.ontology_version_id = ?
          AND mapping.owner_type = 'recipe_ingredient'
          AND mapping.normalized_label = ?
          AND lower(replace(mapping.language, '_', '-')) = 'en'
        ORDER BY ingredient.recipe_id
    ");
    $expectedRecipes->execute([
        $versionId,
        (string)$candidate['normalized_label'],
    ]);
}
$expectedRecipeIds = array_map(
    'intval',
    $expectedRecipes->fetchAll(PDO::FETCH_COLUMN)
);
$scoreSourcesBefore =
    $scoreSources($db, $expectedRecipeIds);

$baseline = [
    'score_revision_id' => (int)$activeBefore['id'],
    'recipe_count' => $activeRecipeCount($db),
    'recipe_max_id' => (int)$db->query("
        SELECT COALESCE(MAX(id), 0) FROM recipe_catalog
    ")->fetchColumn(),
    'job_max_id' => (int)$db->query("
        SELECT COALESCE(MAX(id), 0) FROM recipe_jobs
    ")->fetchColumn(),
    'identity_extension_revision' => (int)(
        ingredientOntologyV3IdentityExtensionSnapshot(
            $db,
            $versionId
        )['revision']
    ),
    'pending' => $pendingCounts($db),
];
if ($dryRun) {
    $output = [
        'success' => true,
        'dry_run' => true,
        'database' => $databasePath,
        'preflight' => [
            'elapsed_ms' => round($preflightMs, 3),
            'reconciliation_backfill_pages' => $backfillPages,
            'score_cycles' => count($preflightScoreCycles),
            'score_date_before' => $preflightScoreDateBefore,
            'score_date_after' => (string)(
                recipeScoreActiveRevision($db)['score_date'] ?? ''
            ),
            'rollover_product_id' =>
                $preflightRolloverProductId,
        ],
        'candidate' => [
            'name' => $productName,
            'selection_mode' =>
                (string)$candidate['selection_mode'],
            'expected_recipe_count' => count($expectedRecipeIds),
            'entity_id' => $candidate['entity_id'] !== null
                ? (int)$candidate['entity_id']
                : null,
            'entity_slug' => $candidate['entity_slug'],
            'canonical_ingredient_id' =>
                $candidate['canonical_ingredient_id'] !== null
                    ? (int)$candidate[
                        'canonical_ingredient_id'
                    ]
                    : null,
            'taxonomy_node_id' =>
                $candidate['taxonomy_node_id'] !== null
                    ? (int)$candidate['taxonomy_node_id']
                    : null,
        ],
        'baseline' => $baseline,
        'effective_incremental_product_limit' =>
            $effectiveProductLimit,
    ];
    $json = json_encode(
        $output,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    if ($jsonOut !== '') {
        recipeCliWriteFileAtomically($jsonOut, $json);
    }
    echo $json;
    exit(0);
}
$db->prepare("
    UPDATE recipe_jobs
    SET priority = 0
    WHERE id <= ?
      AND status IN ('pending', 'retry', 'in_progress')
")->execute([(int)$baseline['job_max_id']]);

$monotonicStart = hrtime(true);
$insertStarted = hrtime(true);
$db->exec('BEGIN IMMEDIATE');
try {
    $db->prepare("
        INSERT INTO products (
            barcode, name, brand, category, unit,
            default_quantity, prepared_food
        )
        VALUES (?, ?, 'Corpus Annex Flow Benchmark',
                'food', 'pz', 1, 0)
    ")->execute([
        'FLOW-' . gmdate('YmdHis') . '-' . getmypid(),
        $productName,
    ]);
    $productId = (int)$db->lastInsertId();
    $canonicalQueue =
        canonicalIngredientEnqueueProduct(
            $db,
            $productId,
            'new_ingredient_flow'
        );
    $db->prepare("
        UPDATE canonical_processing_queue
        SET requested_at = '1970-01-01 00:00:00',
            next_retry_at = NULL
        WHERE product_id = ?
    ")->execute([$productId]);
    $db->prepare("
        INSERT INTO inventory (
            product_id, location, quantity, expiry_date,
            expiry_user_set, prepared_food
        )
        VALUES (
            ?, 'dispensa', 1, date('now', '+7 days'), 1, 0
        )
    ")->execute([$productId]);
    $inventoryId = (int)$db->lastInsertId();
    $inventoryJob = recipeJobEnqueueInventoryChanged(
        $db,
        $productId,
        'inventory_add'
    );
    $db->prepare("
        UPDATE recipe_jobs
        SET priority = 100
        WHERE id = ?
    ")->execute([(int)$inventoryJob['id']]);
    $db->exec('COMMIT');
} catch (Throwable $error) {
    try {
        $db->exec('ROLLBACK');
    } catch (Throwable $ignored) {
    }
    throw $error;
}
$insertMs = (hrtime(true) - $insertStarted) / 1000000;
$recordStage(
    'inventory_insert_committed',
    'done',
    $insertMs,
    [
        'product_id' => $productId,
        'inventory_id' => $inventoryId,
        'inventory_job_id' => (int)$inventoryJob['id'],
        'canonical_queue_id' =>
            (int)($canonicalQueue['queue_id'] ?? 0),
    ]
);

$canonicalStarted = hrtime(true);
$canonicalResults = [];
$canonicalStatus = null;
for (
    $batch = 0;
    $batch < $maximumCanonicalBatches;
    $batch++
) {
    $db->prepare("
        UPDATE canonical_processing_queue
        SET requested_at = '1970-01-01 00:00:00',
            next_retry_at = NULL
        WHERE product_id = ?
          AND status IN ('pending', 'failed')
    ")->execute([$productId]);
    $canonicalResults[] =
        canonicalIngredientProcessQueue($db, 1, 20);
    $canonicalStatus =
        canonicalIngredientQueueStatusForProduct($db, $productId);
    if (
        is_array($canonicalStatus)
        && (string)$canonicalStatus['status'] === 'done'
    ) {
        break;
    }
    if (
        is_array($canonicalStatus)
        && (string)$canonicalStatus['status'] === 'failed'
    ) {
        break;
    }
}
$canonicalMs = (hrtime(true) - $canonicalStarted) / 1000000;
$identity = $db->prepare("
    SELECT status, admission_source, reason, entity_id,
           extension_entity_id, evidence_hash
    FROM ingredient_ontology_identity_annex
    WHERE product_id = ? AND ontology_version_id = ?
");
$identity->execute([$productId, $versionId]);
$identity = $identity->fetch(PDO::FETCH_ASSOC) ?: null;
$productMappingCount = $db->prepare("
    SELECT COUNT(*) FROM product_ingredients WHERE product_id = ?
");
$productMappingCount->execute([$productId]);
$productMappingCount = (int)$productMappingCount->fetchColumn();
$recordStage(
    'taxonomy_and_identity_ready',
    is_array($canonicalStatus)
        && (string)$canonicalStatus['status'] === 'done'
        && is_array($identity)
        && (string)$identity['status'] === 'accepted'
            ? 'done'
            : 'failed',
    $canonicalMs,
    [
        'canonical_status' =>
            $canonicalStatus['status'] ?? 'missing',
        'canonical_attempts' =>
            (int)($canonicalStatus['attempts'] ?? 0),
        'product_mapping_count' => $productMappingCount,
        'identity' => $identity,
    ]
);
if (
    !is_array($canonicalStatus)
    || (string)$canonicalStatus['status'] !== 'done'
    || !is_array($identity)
    || (string)$identity['status'] !== 'accepted'
) {
    throw new RuntimeException(
        'new ingredient taxonomy or identity did not become ready'
    );
}

$localStarted = hrtime(true);
$localResults = [];
for ($batch = 0; $batch < $maximumLocalBatches; $batch++) {
    $db->prepare("
        UPDATE recipe_jobs
        SET priority = 100,
            next_retry_at = NULL
        WHERE id > ?
          AND product_id = ?
          AND (connector IS NULL OR connector <> 'cookidoo')
          AND status IN ('pending', 'retry')
    ")->execute([
        (int)$baseline['job_max_id'],
        $productId,
    ]);
    if (
        $newJobCount(
            $db,
            (int)$baseline['job_max_id'],
            $productId,
            'local',
            true
        ) === 0
    ) {
        break;
    }
    $localResults[] =
        recipeJobProcessQueue($db, 20, 20, false, 'local');
    if (!empty(
        $localResults[array_key_last(
            $localResults
        )]['worker_skipped']
    )) {
        throw new RuntimeException(
            'local recipe worker lease was unexpectedly unavailable'
        );
    }
}
$localMs = (hrtime(true) - $localStarted) / 1000000;
$localOutstanding = $newJobCount(
    $db,
    (int)$baseline['job_max_id'],
    $productId,
    'local',
    true
);
if ($localOutstanding > 0) {
    throw new RuntimeException(
        'new ingredient local recipe jobs did not settle'
    );
}
$providerJobIds = [];
$discoveryReason = '';
$discoveryQueued = count($providerJobIds);
foreach ($localResults as $summary) {
    foreach ((array)($summary['items'] ?? []) as $item) {
        $remote = $item['result']['remote_discovery'] ?? null;
        if (!is_array($remote)) {
            continue;
        }
        $discoveryQueued = max(
            $discoveryQueued,
            (int)($remote['queued'] ?? 0)
        );
        $discoveryReason = (string)($remote['reason'] ?? '');
        foreach ((array)($remote['jobs'] ?? []) as $job) {
            $jobId = (int)($job['id'] ?? 0);
            if ($jobId > 0) {
                $providerJobIds[$jobId] = true;
            }
        }
    }
}
$providerJobIds = array_map(
    'intval',
    array_keys($providerJobIds)
);
sort($providerJobIds, SORT_NUMERIC);
$recordStage(
    'recipe_discovery_queued',
    $providerJobIds ? 'done' : 'not_queued',
    $localMs,
    [
        'provider_job_count' => count($providerJobIds),
        'local_outstanding_jobs' => $localOutstanding,
        'queued_reported' => $discoveryQueued,
        'reason' => $discoveryReason,
        'cookidoo_policy_enabled' =>
            recipeCookidooDetailHydrationPolicyAllows(),
        'cookidoo_connector_enabled' =>
            recipeConnectorIsEnabled(
                $db,
                RECIPE_COOKIDOO_CONNECTOR
            ),
        'cookidoo_bridge_configured' =>
            recipeCookidooBridgeConfigured(),
        'queue_priority_isolated' => true,
    ]
);

$scoreCycles = [];
$scoreCyclePeakRss = 0;
$scoreCycleFullScans = 0;
$scoreOperations = [];
$initialScoreStarted = hrtime(true);
$initialScoreVisibleRecorded = false;
for ($cycle = 1; $cycle <= $maximumScoreCycles; $cycle++) {
    $result = $runScoreWorker($databasePath, $cycle);
    $scoreCycles[] = $result;
    $metrics = (array)($result['benchmark_metrics'] ?? []);
    $scoreCyclePeakRss = max(
        $scoreCyclePeakRss,
        (int)($metrics['peak_rss_bytes'] ?? 0)
    );
    $scoreCycleFullScans +=
        (int)($metrics['full_corpus_scans'] ?? 0);
    foreach (
        (array)($metrics['corpus_operation_counts'] ?? [])
        as $operation => $count
    ) {
        $scoreOperations[(string)$operation] =
            (int)($scoreOperations[(string)$operation] ?? 0)
            + (int)$count;
    }
    $sourcesAfter = $scoreSources($db, $expectedRecipeIds);
    $changedSources = 0;
    foreach ($sourcesAfter as $recipeId => $sourceId) {
        if (
            $sourceId
                !== (int)($scoreSourcesBefore[$recipeId] ?? 0)
        ) {
            $changedSources++;
        }
    }
    if (
        !$initialScoreVisibleRecorded
        && (
            $changedSources > 0
            || (int)recipeScoreActiveRevision($db)['id']
                !== (int)$baseline['score_revision_id']
        )
    ) {
        $initialScoreVisibleRecorded = true;
        $recordStage(
            'first_selective_score_visible',
            'done',
            (float)($result['visible_ms'] ?? 0),
            [
                'score_revision_id' =>
                    (int)($result['revision_id'] ?? 0),
                'changed_expected_recipe_sources' =>
                    $changedSources,
                'affected_recipe_count' =>
                    (int)($result[
                        'affected_recipe_count'
                    ] ?? 0),
                'physical_score_rows' =>
                    (int)($result['physical_score_rows'] ?? 0),
                'timing_ms' =>
                    (array)($result['timing_ms'] ?? []),
            ]
        );
    }
    if ($scoreSettled($db)) {
        break;
    }
}
$initialScoreMs =
    (hrtime(true) - $initialScoreStarted) / 1000000;
if (!$scoreSettled($db)) {
    throw new RuntimeException(
        'initial selective score work did not settle'
    );
}
$sourcesAfterInitial = $scoreSources($db, $expectedRecipeIds);
$changedExpectedRecipes = [];
foreach ($sourcesAfterInitial as $recipeId => $sourceId) {
    if ($sourceId !== (int)($scoreSourcesBefore[$recipeId] ?? 0)) {
        $changedExpectedRecipes[] = (int)$recipeId;
    }
}
$readiness =
    ingredientOntologyV3ProductReadinessRow($db, $productId);
$recordStage(
    'initial_selective_work_settled',
    'done',
    $initialScoreMs,
    [
        'score_cycles' => count($scoreCycles),
        'expected_dependency_recipes' =>
            count($expectedRecipeIds),
        'changed_expected_recipes' =>
            count($changedExpectedRecipes),
        'product_readiness' => $readiness,
        'pending' => $pendingCounts($db),
    ]
);
if (!$changedExpectedRecipes) {
    throw new RuntimeException(
        'selected ingredient did not rescore any expected recipes'
    );
}

$providerBatches = [];
$providerFirstImportRecorded = false;
$providerStarted = hrtime(true);
$providerRecipeBaseline = $activeRecipeCount($db);
if (
    $processProvider
    && $providerJobIds
    && $maximumProviderJobs > 0
) {
    for (
        $batch = 0;
        $batch < $maximumProviderJobs;
        $batch++
    ) {
        $providerPlaceholders = implode(
            ',',
            array_fill(0, count($providerJobIds), '?')
        );
        $db->prepare("
            UPDATE recipe_jobs
            SET priority = 100,
                next_retry_at = NULL
            WHERE id IN ({$providerPlaceholders})
              AND status IN ('pending', 'retry')
        ")->execute($providerJobIds);
        $outstanding =
            $jobIdsOutstanding($db, $providerJobIds);
        if ($outstanding === 0) {
            break;
        }
        $providerBatch =
            recipeJobProcessQueue(
                $db,
                1,
                20,
                true,
                'provider'
            );
        $providerBatches[] = $providerBatch;
        foreach ((array)($providerBatch['items'] ?? []) as $item) {
            $nextJobId = (int)(
                $item['result']['next_job_id'] ?? 0
            );
            if ($nextJobId > 0) {
                $providerJobIds[] = $nextJobId;
            }
        }
        $providerJobIds = array_values(array_unique(array_map(
            'intval',
            $providerJobIds
        )));
        sort($providerJobIds, SORT_NUMERIC);
        if (!empty($providerBatch['worker_skipped'])) {
            throw new RuntimeException(
                'provider worker lease was unexpectedly unavailable'
            );
        }
        $currentRecipeCount = $activeRecipeCount($db);
        if (
            !$providerFirstImportRecorded
            && $currentRecipeCount > $providerRecipeBaseline
        ) {
            $providerFirstImportRecorded = true;
            $recordStage(
                'first_new_recipe_added',
                'done',
                (hrtime(true) - $providerStarted) / 1000000,
                [
                    'new_recipe_count' =>
                        $currentRecipeCount
                            - $providerRecipeBaseline,
                    'provider_batches' =>
                        count($providerBatches),
                ]
            );
        }
    }
}
$providerMs = (hrtime(true) - $providerStarted) / 1000000;
$providerRecipeCount = $activeRecipeCount($db);
$newRecipeCount =
    $providerRecipeCount - $providerRecipeBaseline;
$providerOutstanding =
    $jobIdsOutstanding($db, $providerJobIds);
$providerStatus = !$processProvider
    ? 'not_run'
    : (
        !$providerJobIds
            ? 'not_queued'
            : ($providerOutstanding === 0 ? 'done' : 'bounded_window')
    );
$recordStage(
    'provider_discovery_window',
    $providerStatus,
    $providerMs,
    [
        'processed_batches' => count($providerBatches),
        'new_recipe_count' => $newRecipeCount,
        'outstanding_provider_jobs' => $providerOutstanding,
        'maximum_provider_jobs' => $maximumProviderJobs,
    ]
);
if ($processProvider && !$providerFirstImportRecorded) {
    $recordStage(
        'first_new_recipe_added',
        $newRecipeCount > 0 ? 'done' : 'not_observed',
        $providerMs,
        [
            'new_recipe_count' => $newRecipeCount,
            'provider_batches' => count($providerBatches),
        ]
    );
}

$postProviderScoreCycles = [];
$newRecipesScored = 0;
if ($newRecipeCount > 0 || !$scoreSettled($db)) {
    $postProviderStarted = hrtime(true);
    for (
        $cycle = count($scoreCycles) + 1;
        $cycle <= count($scoreCycles) + $maximumScoreCycles;
        $cycle++
    ) {
        $result = $runScoreWorker($databasePath, $cycle);
        $postProviderScoreCycles[] = $result;
        $metrics = (array)($result['benchmark_metrics'] ?? []);
        $scoreCyclePeakRss = max(
            $scoreCyclePeakRss,
            (int)($metrics['peak_rss_bytes'] ?? 0)
        );
        $scoreCycleFullScans +=
            (int)($metrics['full_corpus_scans'] ?? 0);
        foreach (
            (array)($metrics['corpus_operation_counts'] ?? [])
            as $operation => $count
        ) {
            $scoreOperations[(string)$operation] =
                (int)($scoreOperations[
                    (string)$operation
                ] ?? 0) + (int)$count;
        }
        if ($scoreSettled($db)) {
            break;
        }
    }
    if (!$scoreSettled($db)) {
        throw new RuntimeException(
            'post-provider score work did not settle'
        );
    }
    $newRecipesScoredStmt = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_score_effective_sources
        WHERE recipe_id > ?
    ");
    $newRecipesScoredStmt->execute([
        (int)$baseline['recipe_max_id'],
    ]);
    $newRecipesScored =
        (int)$newRecipesScoredStmt->fetchColumn();
    $recordStage(
        'new_recipes_scored_and_settled',
        'done',
        (hrtime(true) - $postProviderStarted) / 1000000,
        [
            'new_recipe_count' => $newRecipeCount,
            'new_recipes_with_effective_score' =>
                $newRecipesScored,
            'score_cycles' => count($postProviderScoreCycles),
            'pending' => $pendingCounts($db),
        ]
    );
} else {
    $recordStage(
        'new_recipes_scored_and_settled',
        'not_applicable',
        0.0,
        [
            'new_recipe_count' => 0,
            'reason' => $processProvider
                ? 'provider_added_no_new_catalog_rows'
                : 'provider_processing_not_requested',
        ]
    );
}

$finalActive = recipeScoreActiveRevision($db);
if ($finalActive === null) {
    throw new RuntimeException(
        'final active score revision is unavailable'
    );
}
$finalPin = ingredientOntologyV3CorpusAnnexForScore(
    $db,
    $finalActive
);
if ($finalPin === null) {
    throw new RuntimeException(
        'final Corpus Annex pin is unavailable'
    );
}
$output = [
    'success' => true,
    'database' => $databasePath,
    'preflight' => [
        'elapsed_ms' => round($preflightMs, 3),
        'reconciliation_backfill_pages' => $backfillPages,
        'score_cycles' => count($preflightScoreCycles),
        'score_date_before' => $preflightScoreDateBefore,
        'score_date_after' => (string)(
            recipeScoreActiveRevision($db)['score_date'] ?? ''
        ),
        'rollover_product_id' =>
            $preflightRolloverProductId,
        'excluded_from_time_to_stage' => true,
    ],
    'candidate' => [
        'name' => $productName,
        'selection_mode' =>
            (string)$candidate['selection_mode'],
        'expected_recipe_count' => count($expectedRecipeIds),
        'entity_id' => $candidate['entity_id'] !== null
            ? (int)$candidate['entity_id']
            : null,
        'entity_slug' => $candidate['entity_slug'],
        'canonical_ingredient_id' =>
            $candidate['canonical_ingredient_id'] !== null
                ? (int)$candidate['canonical_ingredient_id']
                : null,
        'taxonomy_node_id' =>
            $candidate['taxonomy_node_id'] !== null
                ? (int)$candidate['taxonomy_node_id']
                : null,
    ],
    'product' => [
        'id' => $productId,
        'inventory_id' => $inventoryId,
        'identity' => $identity,
        'readiness' => $readiness,
    ],
    'baseline' => $baseline,
    'effective_incremental_product_limit' =>
        $effectiveProductLimit,
    'stages' => $stageRows,
    'score_summary' => [
        'initial_cycles' => count($scoreCycles),
        'post_provider_cycles' =>
            count($postProviderScoreCycles),
        'changed_expected_recipes' =>
            count($changedExpectedRecipes),
        'peak_rss_bytes' => $scoreCyclePeakRss,
        'full_corpus_scans' => $scoreCycleFullScans,
        'corpus_operation_counts' => $scoreOperations,
    ],
    'provider_summary' => [
        'requested' => $processProvider,
        'queued_job_ids' => $providerJobIds,
        'processed_batches' => count($providerBatches),
        'outstanding_jobs' => $providerOutstanding,
        'new_recipe_count' => $newRecipeCount,
        'new_recipes_with_effective_score' =>
            $newRecipesScored,
    ],
    'final' => [
        'score_revision_id' => (int)$finalActive['id'],
        'corpus_annex_revision_id' => (int)$finalPin['id'],
        'recipe_count' => $activeRecipeCount($db),
        'pending' => $pendingCounts($db),
        'settled' => $scoreSettled($db),
    ],
];

$json = json_encode(
    $output,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
if ($jsonOut !== '') {
    recipeCliWriteFileAtomically($jsonOut, $json);
}
echo $json;
