#!/usr/bin/env php
<?php
declare(strict_types=1);

putenv('INGREDIENT_ONTOLOGY_CONTROLLER_ENABLED=false');
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
$maximumScenarioMs = max(
    1000,
    min(300000, (int)($options['max-scenario-ms'] ?? 30000))
);
$maximumWriteLockMs = max(
    250,
    min(30000, (int)($options['max-write-lock-ms'] ?? 5000))
);
$maximumPhpRssMb = max(
    32,
    min(512, (int)($options['max-php-rss-mb'] ?? 256))
);
$maximumPages = max(
    1,
    min(25, (int)($options['max-pages'] ?? 4))
);
$maximumRolloverMs = max(
    10000,
    min(1800000, (int)($options['max-rollover-ms'] ?? 1200000))
);
$workerLifecycle = isset($options['worker-lifecycle']);
$requestedPath = trim((string)($options['db'] ?? ''));
if ($requestedPath === '') {
    throw new InvalidArgumentException('--db is required');
}
if (
    (string)($options['confirm-disposable'] ?? '')
        !== 'SELECTIVE-CORPUS-BENCHMARK'
) {
    throw new InvalidArgumentException(
        '--confirm-disposable=SELECTIVE-CORPUS-BENCHMARK is required'
    );
}
$databasePath = recipeCliAssertDatabaseInputSafe(
    $requestedPath,
    false
);
$basename = basename($databasePath);
if (
    $basename === 'evershelf.db'
    || !preg_match(
        '/(?:benchmark|disposable|scratch|test)/i',
        $basename
    )
) {
    throw new InvalidArgumentException(
        'benchmark database name must identify a disposable copy'
    );
}
$jsonOut = trim((string)($options['json-out'] ?? ''));
if ($jsonOut !== '') {
    $jsonOut = recipeCliAssertOutputPathSafe(
        $jsonOut,
        $databasePath
    );
}
foreach ([
    'GEMINI_API_KEY',
    'GOOGLE_API_KEY',
    'GOOGLE_GENERATIVE_AI_API_KEY',
] as $key) {
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}

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

$token = gmdate('YmdHis')
    . '-' . getmypid()
    . '-' . bin2hex(random_bytes(8));
ingredientOntologyV3IncrementalBenchmarkFixtureInstall($db);
putenv(
    'INGREDIENT_ONTOLOGY_V3_BENCHMARK_FIXTURE_TOKEN=' . $token
);

$runWorkerCycle = static function () use (
    $databasePath,
    $token
): array {
    $prefix = $databasePath . '.benchmark-worker-' . getmypid();
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
            '--benchmark-fixture-token=' . $token,
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
            'benchmark worker lifecycle could not start'
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
            'benchmark worker lifecycle failed: '
                . ingredientOntologyV3Json([
                    'status' => $status,
                    'stdout' => mb_substr(
                        (string)$stdout,
                        0,
                        1000,
                        'UTF-8'
                    ),
                    'stderr' => mb_substr(
                        (string)$stderr,
                        0,
                        1000,
                        'UTF-8'
                    ),
                ])
        );
    }
    $metrics = $payload['benchmark_metrics'] ?? null;
    if (
        !is_array($metrics)
        || !isset(
            $metrics['full_corpus_scans'],
            $metrics['corpus_operation_counts'],
            $metrics['initial_rss_bytes'],
            $metrics['peak_rss_bytes'],
            $metrics['peak_php_memory_bytes']
        )
        || !is_array($metrics['corpus_operation_counts'])
        || (int)$metrics['initial_rss_bytes'] <= 0
        || (int)$metrics['peak_rss_bytes'] <= 0
        || (int)$metrics['peak_rss_bytes']
            < (int)$metrics['initial_rss_bytes']
        || (int)$metrics['peak_php_memory_bytes'] <= 0
    ) {
        throw new RuntimeException(
            'benchmark worker lifecycle metrics are unavailable: '
                . ingredientOntologyV3Json($payload)
        );
    }
    return $payload;
};

$assert = static function (
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$currentRssBytes = static function (): int {
    $status = @file_get_contents('/proc/self/status');
    if (
        is_string($status)
        && preg_match('/^VmRSS:\s+([0-9]+)\s+kB$/m', $status, $match)
    ) {
        return (int)$match[1] * 1024;
    }
    return memory_get_usage(true);
};
$aggregateHash = static function (
    PDO $db,
    int $versionId,
    string $type,
    int $id
): string {
    $stmt = $db->prepare("
        SELECT aggregate_hash
        FROM ingredient_ontology_corpus_annex_effective_aggregates
        WHERE ontology_version_id = ?
          AND aggregate_type = ?
          AND aggregate_id = ?
    ");
    $stmt->execute([$versionId, $type, $id]);
    return (string)($stmt->fetchColumn() ?: '');
};
$aggregateHead = static function (
    PDO $db,
    int $versionId,
    string $type,
    int $id
): ?array {
    $stmt = $db->prepare("
        SELECT operation, aggregate_hash, member_count
        FROM ingredient_ontology_corpus_annex_effective_aggregates
        WHERE ontology_version_id = ?
          AND aggregate_type = ?
          AND aggregate_id = ?
    ");
    $stmt->execute([$versionId, $type, $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
    ];
};

$active = recipeScoreActiveRevision($db);
$assert(
    $active !== null
    && (string)$active['status'] === 'ready'
    && (string)$active['scoring_model']
        === INGREDIENT_ONTOLOGY_V3_SCORING_MODEL,
    'benchmark requires an active ready ontology v3 score revision'
);
$initialState = recipeScoreState($db);
$assert(
    (int)(
        $active['covered_ontology_source_revision']
            ?? $active['ontology_source_revision']
    ) >= (int)$initialState['ontology_source_revision']
    && (int)(
        $active['covered_catalog_revision']
            ?? $active['catalog_revision']
    ) >= (int)$initialState['catalog_revision'],
    'benchmark requires a score revision current with both source lanes'
);
$versionId = (int)$active['ontology_version_id'];
$annex = ingredientOntologyV3CorpusAnnexForScore($db, $active);
$assert(
    $annex !== null
    && ingredientOntologyV3CorpusAnnexProjectionReady($db, $annex),
    'benchmark requires a current materialized corpus projection'
);
$dataset = [
    'products' => (int)$db->query("
        SELECT COUNT(*) FROM products
    ")->fetchColumn(),
    'recipes' => (int)$db->query("
        SELECT COUNT(*) FROM recipe_catalog
        WHERE deleted_at IS NULL
    ")->fetchColumn(),
    'recipe_ingredients' => (int)$db->query("
        SELECT COUNT(*) FROM recipe_ingredients
    ")->fetchColumn(),
    'source_ingredients' => (int)$db->query("
        SELECT COUNT(*) FROM recipe_source_ingredients
    ")->fetchColumn(),
    'projection_aggregates' => (int)$db->query("
        SELECT COUNT(*)
        FROM ingredient_ontology_corpus_annex_effective_aggregates
        WHERE ontology_version_id = {$versionId}
    ")->fetchColumn(),
];
$assert(
    $dataset['recipes'] >= 10000,
    'production-sized benchmark requires at least 10,000 recipes'
);
$assert(
    array_sum($pendingCounts($db)) === 0,
    'benchmark requires settled pending queues'
);
$baselineQuickCheckStarted = hrtime(true);
fwrite(STDERR, "benchmark: baseline quick_check\n");
$assert(
    (string)$db->query('PRAGMA quick_check')->fetchColumn() === 'ok',
    'benchmark database failed its baseline quick check'
);
$dataset['baseline_quick_check_ms'] = round(
    (hrtime(true) - $baselineQuickCheckStarted) / 1000000,
    3
);
$existingFixtureCount = (int)$db->query("
    SELECT (
        SELECT COUNT(*) FROM products
        WHERE name LIKE 'Selective Benchmark %'
    ) + (
        SELECT COUNT(*) FROM recipe_catalog
        WHERE title LIKE 'Selective Benchmark %'
          AND deleted_at IS NULL
    ) + (
        SELECT COUNT(*) FROM taxonomy_aliases
        WHERE source = 'gemini_selective_benchmark'
    ) + (
        SELECT COUNT(*)
        FROM ingredient_ontology_incremental_benchmark_fixtures
    )
")->fetchColumn();
$assert(
    $existingFixtureCount === 0,
    'benchmark fixtures already exist; restore a fresh disposable copy'
);

$candidateRows = $db->query("
    SELECT canonical.id, canonical.slug, canonical.name,
           canonical.parent_slug,
           COUNT(DISTINCT ingredient.recipe_id) AS recipe_count
    FROM canonical_ingredients canonical
    JOIN recipe_ingredients ingredient
      ON ingredient.canonical_ingredient_id = canonical.id
    JOIN ingredient_ontology_entities entity
      ON entity.ontology_version_id = {$versionId}
     AND entity.legacy_canonical_ingredient_id = canonical.id
     AND entity.active = 1
    WHERE canonical.parent_slug IS NOT NULL
      AND trim(canonical.parent_slug) <> ''
    GROUP BY canonical.id
    HAVING recipe_count BETWEEN 20 AND 120
    ORDER BY recipe_count DESC, canonical.id
")->fetchAll(PDO::FETCH_ASSOC);
$target = null;
$alternate = null;
$parentCycleCheck = $db->prepare("
    WITH RECURSIVE ancestors(slug, parent_slug) AS (
        SELECT slug, parent_slug
        FROM canonical_ingredients
        WHERE slug = ?
        UNION
        SELECT parent.slug, parent.parent_slug
        FROM canonical_ingredients parent
        JOIN ancestors child
          ON parent.slug = child.parent_slug
    )
    SELECT COUNT(*)
    FROM ancestors
    WHERE slug = ?
");
foreach ($candidateRows as $candidate) {
    if ($target === null) {
        $target = $candidate;
        continue;
    }
    $parentCycleCheck->execute([
        (string)$candidate['parent_slug'],
        (string)$target['slug'],
    ]);
    if (
        (string)$candidate['parent_slug']
            !== (string)$target['parent_slug']
        && (int)$parentCycleCheck->fetchColumn() === 0
    ) {
        $alternate = $candidate;
        break;
    }
}
$assert(
    is_array($target) && is_array($alternate),
    'benchmark canonical ingredient candidates are unavailable'
);
$targetId = (int)$target['id'];
$alternateId = (int)$alternate['id'];
$sentinelStmt = $db->prepare("
    SELECT aggregate.aggregate_id
    FROM ingredient_ontology_corpus_annex_effective_aggregates aggregate
    WHERE aggregate.ontology_version_id = ?
      AND aggregate.aggregate_type = 'recipe'
      AND aggregate.operation = 'replace'
      AND NOT EXISTS (
          SELECT 1
          FROM ingredient_ontology_corpus_annex_effective_members member
          WHERE member.ontology_version_id =
                    aggregate.ontology_version_id
            AND member.aggregate_type = 'recipe'
            AND member.aggregate_id = aggregate.aggregate_id
            AND CAST(json_extract(
                member.payload_json,
                '$.canonical_ingredient_id'
            ) AS INTEGER) IN (?, ?)
      )
    ORDER BY aggregate.aggregate_id
      LIMIT 3
");
$sentinelStmt->execute([$versionId, $targetId, $alternateId]);
$sentinelRecipeIds = array_map(
      'intval',
      $sentinelStmt->fetchAll(PDO::FETCH_COLUMN)
);
$sentinelRecipeId = (int)($sentinelRecipeIds[0] ?? 0);
$assert(
      count($sentinelRecipeIds) === 3,
      'benchmark requires three unrelated sentinel recipes'
);
$identityCandidate = $db->prepare("
      SELECT product.aggregate_id AS product_id,
             MIN(recipe.aggregate_id) AS recipe_id,
             product.entity_key,
             COUNT(DISTINCT recipe.aggregate_id) AS recipe_count
      FROM ingredient_ontology_corpus_annex_effective_entities product
      JOIN ingredient_ontology_corpus_annex_effective_entities recipe
        ON recipe.ontology_version_id = product.ontology_version_id
       AND recipe.entity_key = product.entity_key
       AND recipe.aggregate_type = 'recipe'
      JOIN products source_product
        ON source_product.id = product.aggregate_id
      JOIN recipe_catalog source_recipe
        ON source_recipe.id = recipe.aggregate_id
       AND source_recipe.deleted_at IS NULL
      WHERE product.ontology_version_id = ?
        AND product.aggregate_type = 'product'
        AND product.entity_key LIKE 'extension:%'
      GROUP BY product.aggregate_id, product.entity_key
      HAVING COUNT(DISTINCT recipe.aggregate_id) >= 1
         AND COUNT(DISTINCT recipe.aggregate_id) <= ?
         AND SUM(
             CASE
                 WHEN recipe.aggregate_id IN (?, ?, ?)
                 THEN 1 ELSE 0
             END
         ) = 0
      ORDER BY recipe_count DESC, product.aggregate_id
      LIMIT 1
");
$identityCandidate->execute([
    $versionId,
    max(
        1,
        $maximumPages
            * ingredientOntologyV3IncrementalProductLimit() - 1
    ),
    ...$sentinelRecipeIds,
]);
$identityCandidate = $identityCandidate->fetch(PDO::FETCH_ASSOC);
$assert(
      is_array($identityCandidate),
      'benchmark requires an identity-extension dependency'
);
$identityBenchmarkProductId = (int)$identityCandidate['product_id'];
$identityRecipeLimit =
    max(1, (int)$identityCandidate['recipe_count']) + 1;
$identityRecipeIds = $db->prepare("
    SELECT DISTINCT recipe.aggregate_id
    FROM ingredient_ontology_corpus_annex_effective_entities product
    JOIN ingredient_ontology_corpus_annex_effective_entities recipe
      ON recipe.ontology_version_id = product.ontology_version_id
     AND recipe.entity_key = product.entity_key
     AND recipe.aggregate_type = 'recipe'
    JOIN recipe_catalog source_recipe
      ON source_recipe.id = recipe.aggregate_id
     AND source_recipe.deleted_at IS NULL
    WHERE product.ontology_version_id = ?
      AND product.aggregate_type = 'product'
      AND product.aggregate_id = ?
    ORDER BY recipe.aggregate_id
    LIMIT {$identityRecipeLimit}
");
$identityRecipeIds->execute([
    $versionId,
    $identityBenchmarkProductId,
]);
$identityBenchmarkRecipeIds = array_map(
    'intval',
    $identityRecipeIds->fetchAll(PDO::FETCH_COLUMN)
);
sort($identityBenchmarkRecipeIds, SORT_NUMERIC);
$assert(
    count($identityBenchmarkRecipeIds)
        === (int)$identityCandidate['recipe_count'],
    'benchmark identity dependency page changed during selection'
);

$runScenario = static function (
    string $name,
    callable $mutate,
    PDO $db,
    int $versionId,
    int $sentinelRecipeId,
    int $totalAggregates
) use (
    $assert,
    $aggregateHash,
    $aggregateHead,
    $pendingCounts,
    $sentinelRecipeIds,
    $maximumScenarioMs,
    $maximumWriteLockMs,
    $maximumPhpRssMb,
    $maximumPages,
    $currentRssBytes,
    $workerLifecycle,
    $runWorkerCycle
): array {
    fwrite(STDERR, "benchmark: {$name}\n");
    $beforeActive = recipeScoreActiveRevision($db);
    $assert($beforeActive !== null, "{$name}: active score missing");
    $beforeState = recipeScoreState($db);
    $beforeSentinels = [];
    foreach ($sentinelRecipeIds as $sentinelId) {
        $beforeSentinels[$sentinelId] = $aggregateHash(
            $db,
            $versionId,
            'recipe',
            $sentinelId
        );
    }
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $GLOBALS['INGREDIENT_ONTOLOGY_V3_CORPUS_FULL_SCAN_COUNT'] = 0;
    $GLOBALS['INGREDIENT_ONTOLOGY_V3_CORPUS_OPERATION_COUNTS'] = [];
    $memoryBefore = memory_get_usage(true);
    $mutationStarted = hrtime(true);
    $metadata = $mutate();
    $mutationMs =
        (hrtime(true) - $mutationStarted) / 1000000;
    $pages = [];
    $workerCycleCount = 0;
    $workerFullCorpusScans = 0;
    $workerPeakRssBytes = 0;
    $workerPeakPhpMemoryBytes = 0;
    $workerMemoryDeltaBytes = 0;
    $workerCorpusOperations = [];
    $rebuildStarted = hrtime(true);
    for ($attempt = 1; $attempt <= 25; $attempt++) {
        $result = $workerLifecycle
            ? $runWorkerCycle()
            : ingredientOntologyV3IncrementalRebuild(
                $db,
                true,
                500
            );
        if ($workerLifecycle) {
            $metrics = (array)$result['benchmark_metrics'];
            $workerCycleCount++;
            $workerFullCorpusScans +=
                (int)$metrics['full_corpus_scans'];
            $workerPeakRssBytes = max(
                $workerPeakRssBytes,
                (int)$metrics['peak_rss_bytes']
            );
            $workerPeakPhpMemoryBytes = max(
                $workerPeakPhpMemoryBytes,
                (int)$metrics['peak_php_memory_bytes']
            );
            $workerMemoryDeltaBytes = max(
                $workerMemoryDeltaBytes,
                max(
                    0,
                    (int)$metrics['peak_rss_bytes']
                        - (int)$metrics['initial_rss_bytes']
                )
            );
            foreach (
                (array)$metrics['corpus_operation_counts']
                as $operation => $count
            ) {
                $workerCorpusOperations[(string)$operation] =
                    (int)($workerCorpusOperations[
                        (string)$operation
                    ] ?? 0) + (int)$count;
            }
        }
        if (
            empty($result['rebuilt'])
            && (string)($result['reason'] ?? '')
                === 'identity_migration_pending'
        ) {
            continue;
        }
        $assert(
            !empty($result['rebuilt']),
            $name . ': selective rebuild failed: '
                . ingredientOntologyV3Json($result)
        );
        $active = recipeScoreActiveRevision($db);
        $assert($active !== null, "{$name}: active score was lost");
        $revision = ingredientOntologyV3CorpusAnnexRevision(
            $db,
            (int)$result['corpus_annex_revision_id']
        );
        $assert(
            $revision !== null,
            "{$name}: published corpus revision is unavailable"
        );
        $report = recipeScoreRevisionReport($active);
        $pages[] = [
            'score_revision_id' => (int)$result['revision_id'],
            'corpus_annex_revision_id' =>
                (int)$result['corpus_annex_revision_id'],
            'reconciliation_mode' =>
                (string)$revision['reconciliation_mode'],
            'covered_ontology_source_revision' =>
                (int)$revision[
                    'covered_ontology_source_revision'
                ],
            'covered_catalog_revision' => (int)(
                $active['covered_catalog_revision']
                    ?? $active['catalog_revision']
            ),
            'touched_aggregates' =>
                (int)$revision['aggregate_count'],
            'annex_entries' => (int)$revision['entry_count'],
            'affected_recipes' =>
                (int)$result['affected_recipe_count'],
            'physical_score_rows' =>
                (int)$result['physical_score_rows'],
            'physical_match_rows' =>
                (int)$result['physical_match_rows'],
            'visible_ms' => (float)$result['visible_ms'],
            'timing_ms' => (array)$result['timing_ms'],
            'has_more' => !empty(
                $report['corpus_annex']['has_more']
            ),
            'scope_reconciliation_complete' => !empty(
                $report[
                    'corpus_annex'
                ]['scope_reconciliation_complete']
            ),
            'captured_identity_extension_revision' => (int)(
                $active['identity_extension_revision'] ?? 0
            ),
            'covered_identity_extension_revision' => (int)(
                $active[
                    'covered_identity_extension_revision'
                ] ?? 0
            ),
            'pending_identity_recipe_count' =>
                ingredientOntologyV3IdentityProjectionPendingCount(
                    $db,
                    $versionId
                ),
        ];
        $state = recipeScoreState($db);
        $active = recipeScoreActiveRevision($db);
        $pending = $pendingCounts($db);
        $coveredOntology = (int)(
            $active['covered_ontology_source_revision']
                ?? $active['ontology_source_revision']
        );
        $coveredCatalog = (int)(
            $active['covered_catalog_revision']
                ?? $active['catalog_revision']
        );
        $identitySnapshot =
            ingredientOntologyV3IdentityExtensionSnapshot(
                $db,
                $versionId
            );
        if (
            array_sum($pending) === 0
            && $coveredOntology
                >= (int)$state['ontology_source_revision']
            && $coveredCatalog >= (int)$state['catalog_revision']
            && (int)($active[
                'covered_identity_extension_revision'
            ] ?? 0) >= (int)$identitySnapshot['revision']
        ) {
            break;
        }
    }
    $state = recipeScoreState($db);
    $afterActive = recipeScoreActiveRevision($db);
    $assert($afterActive !== null, "{$name}: final score missing");
    $pending = $pendingCounts($db);
    $finalIdentitySnapshot =
        ingredientOntologyV3IdentityExtensionSnapshot(
            $db,
            $versionId
        );
    $assert(
        array_sum($pending) === 0
        && (int)(
            $afterActive['covered_ontology_source_revision']
                ?? $afterActive['ontology_source_revision']
        ) >= (int)$state['ontology_source_revision']
        && (int)(
            $afterActive['covered_catalog_revision']
                ?? $afterActive['catalog_revision']
        ) >= (int)$state['catalog_revision']
        && (int)($afterActive[
            'covered_identity_extension_revision'
        ] ?? 0) >= (int)$finalIdentitySnapshot['revision'],
        "{$name}: selective work did not settle"
    );
    foreach ($beforeSentinels as $sentinelId => $beforeSentinel) {
        $afterSentinel = $aggregateHash(
            $db,
            $versionId,
            'recipe',
            (int)$sentinelId
        );
        $assert(
            $beforeSentinel !== ''
            && hash_equals($beforeSentinel, $afterSentinel),
            "{$name}: unrelated sentinel {$sentinelId} changed"
        );
    }
    foreach ((array)($metadata['expected_aggregates'] ?? []) as $expected) {
        $type = (string)($expected['type'] ?? '');
        $id = (int)($expected['id'] ?? 0);
        $head = $aggregateHead($db, $versionId, $type, $id);
        $assert(
            $head !== null
            && (string)$head['operation']
                === (string)($expected['operation'] ?? 'replace'),
            "{$name}: expected {$type}:{$id} projection is missing"
        );
        if (isset($expected['before_hash'])) {
            $assert(
                strlen((string)$expected['before_hash']) === 64,
                "{$name}: expected {$type}:{$id} baseline is missing"
            );
            $assert(
                !hash_equals(
                    (string)$expected['before_hash'],
                    (string)$head['aggregate_hash']
                ),
                "{$name}: expected {$type}:{$id} hash did not change"
            );
        }
        if ((string)$head['operation'] === 'delete') {
            $assert(
                (int)$head['member_count'] === 0,
                "{$name}: deleted {$type}:{$id} retained members"
            );
        }
    }
    $touchedAggregates = array_sum(array_column(
        $pages,
        'touched_aggregates'
    ));
    $affectedRecipes = array_sum(array_column(
        $pages,
        'affected_recipes'
    ));
    $physicalScoreRows = array_sum(array_column(
        $pages,
        'physical_score_rows'
    ));
    $physicalMatchRows = array_sum(array_column(
        $pages,
        'physical_match_rows'
    ));
    $expectedAggregates = (array)(
        $metadata['expected_aggregates'] ?? []
    );
    $expectedTouched = array_key_exists(
        'expected_touched_aggregates',
        $metadata
    )
        ? (int)$metadata['expected_touched_aggregates']
        : count($expectedAggregates);
    $expectedModes = array_values(array_map(
        'strval',
        (array)($metadata['expected_reconciliation_modes'] ?? ['journal'])
    ));
    $scenarioMaximumPages = min(
        $maximumPages,
        max(1, (int)($metadata['max_pages'] ?? 1))
    );
    $rescoreMs =
        (hrtime(true) - $rebuildStarted) / 1000000;
    $peak = $workerLifecycle
        ? $workerPeakRssBytes
        : max(
            memory_get_peak_usage(true),
            $currentRssBytes()
        );
    $assert(
        $touchedAggregates === $expectedTouched,
        "{$name}: touched {$touchedAggregates} aggregates; expected "
            . $expectedTouched
    );
    $assert(
        count($pages) <= $scenarioMaximumPages,
        "{$name}: reconciliation used " . count($pages)
            . " pages; maximum is {$scenarioMaximumPages}"
    );
    foreach ($pages as $page) {
        $assert(
            in_array(
                (string)$page['reconciliation_mode'],
                $expectedModes,
                true
            ),
            "{$name}: unexpected reconciliation mode "
                . (string)$page['reconciliation_mode']
        );
        $timing = (array)$page['timing_ms'];
        $snapshotWriteLockMs =
            (float)($timing['snapshot_write_lock'] ?? INF);
        $publishWriteLockMs =
            (float)($timing[
                'publish_write_lock'
            ] ?? $timing['publish'] ?? INF);
        $assert(
            $snapshotWriteLockMs <= $maximumWriteLockMs,
            "{$name}: snapshot write lock {$snapshotWriteLockMs} ms "
                . "exceeded {$maximumWriteLockMs} ms"
        );
        $assert(
            $publishWriteLockMs <= $maximumWriteLockMs,
            "{$name}: publish write lock {$publishWriteLockMs} ms "
                . "exceeded {$maximumWriteLockMs} ms"
        );
    }
    if (!empty(
        $metadata['require_scope_reconciliation_complete']
    )) {
        $assert(
            (bool)array_filter(
                $pages,
                static fn(array $page): bool => !empty(
                    $page['scope_reconciliation_complete']
                )
            ),
            "{$name}: no page proved complete durable scope reconciliation"
        );
    }
    if (array_key_exists('expected_affected_recipes', $metadata)) {
        $assert(
            $affectedRecipes
                === (int)$metadata['expected_affected_recipes'],
            "{$name}: affected {$affectedRecipes} recipes; expected "
                . (int)$metadata['expected_affected_recipes']
        );
    } elseif (empty($metadata['allow_zero_affected_recipes'])) {
        $assert(
            $affectedRecipes > 0,
            "{$name}: mutation affected no recipes"
        );
    }
    if (array_key_exists('expected_physical_score_rows', $metadata)) {
        $assert(
            $physicalScoreRows
                === (int)$metadata['expected_physical_score_rows'],
            "{$name}: wrote {$physicalScoreRows} score rows; expected "
                . (int)$metadata['expected_physical_score_rows']
        );
    } elseif (empty($metadata['contains_recipe_delete'])) {
        $assert(
            $physicalScoreRows === $affectedRecipes,
            "{$name}: sparse score rows do not match affected recipes"
        );
    }
    if (!empty($metadata['expect_source_revision_unchanged'])) {
        $assert(
            (int)$state['ontology_source_revision']
                === (int)$beforeState['ontology_source_revision'],
            "{$name}: identity-only work changed the source revision"
        );
    }
    $assert(
        $rescoreMs <= $maximumScenarioMs,
        "{$name}: {$rescoreMs} ms exceeded {$maximumScenarioMs} ms"
    );
    $assert(
        $peak <= $maximumPhpRssMb * 1048576,
        "{$name}: PHP RSS "
            . round($peak / 1048576, 3)
            . " MB exceeded {$maximumPhpRssMb} MB"
    );
    $fullCorpusScans = $workerLifecycle
        ? $workerFullCorpusScans
        : (int)($GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_CORPUS_FULL_SCAN_COUNT'
        ] ?? 0);
    $corpusOperations = $workerLifecycle
        ? $workerCorpusOperations
        : (array)($GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_CORPUS_OPERATION_COUNTS'
        ] ?? []);
    $assert(
        $fullCorpusScans === 0,
        "{$name}: selective reconciliation performed "
            . "{$fullCorpusScans} full corpus scans"
    );
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
        $assert(
            (int)($corpusOperations[$operation] ?? 0) === 0,
            "{$name}: selective reconciliation used {$operation}"
        );
    }
    $memoryDeltaBytes = $workerLifecycle
        ? $workerMemoryDeltaBytes
        : max(0, $peak - $memoryBefore);
    return [
        'name' => $name,
        'metadata' => is_array($metadata) ? $metadata : [],
        'mutation_ms' => round($mutationMs, 3),
        'rescore_ms' => round($rescoreMs, 3),
        'score_revision_delta' =>
            (int)$afterActive['id'] - (int)$beforeActive['id'],
        'reconciliation_pages' => count($pages),
        'touched_aggregates' => $touchedAggregates,
        'affected_recipes' => $affectedRecipes,
        'physical_score_rows' => $physicalScoreRows,
        'physical_match_rows' => $physicalMatchRows,
        'sentinel_count' => count($sentinelRecipeIds),
        'unrelated_hashes_stable' => true,
        'full_corpus_scans' => $fullCorpusScans,
        'corpus_operation_counts' => $corpusOperations,
        'peak_rss_mb' => round($peak / 1048576, 3),
        'peak_php_memory_mb' => round($peak / 1048576, 3),
        'memory_delta_mb' => round(
            $memoryDeltaBytes / 1048576,
            3
        ),
        'worker_metrics' => $workerLifecycle
            ? [
                'cycle_count' => $workerCycleCount,
                'peak_rss_mb' =>
                    round($workerPeakRssBytes / 1048576, 3),
                'peak_php_memory_mb' => round(
                    $workerPeakPhpMemoryBytes / 1048576,
                    3
                ),
            ]
            : null,
        'pages' => $pages,
    ];
};

$aliasLabel = 'Selective Benchmark Alias ' . $token;
$scenarios = [];
$productId = 0;
$recipeAId = 0;
$recipeBId = 0;
$aliasId = 0;
$snapshotFirstProductId = 0;
$snapshotSecondProductId = 0;
$snapshotFixtureId = 0;

$scenarios[] = $runScenario(
    'product_insert',
    static function () use (
        $db,
        $target,
        $token,
        &$productId
    ): array {
        $db->prepare("
            INSERT INTO products (
                barcode, name, brand, category, unit,
                default_quantity, prepared_food
            )
            VALUES (?, ?, 'Selective Benchmark', ?, 'pz', 1, 0)
        ")->execute([
            'SB-' . $token,
            (string)$target['name'],
            'selective benchmark',
        ]);
        $productId = (int)$db->lastInsertId();
        $db->prepare("
            INSERT INTO inventory (
                product_id, location, quantity, expiry_date,
                prepared_food
            )
            VALUES (?, 'dispensa', 1, date('now', '+7 days'), 0)
        ")->execute([$productId]);
        recipeScoreMarkProductDirty(
            $db,
            $productId,
            'selective_production_benchmark_insert'
        );
        return [
            'product_id' => $productId,
            'expected_aggregates' => [[
                'type' => 'product',
                'id' => $productId,
                'operation' => 'replace',
            ]],
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);
$scenarios[] = $runScenario(
    'product_update',
    static function () use (
        $db,
        $alternate,
        $aggregateHash,
        $versionId,
        &$productId
    ): array {
        $beforeHash = $aggregateHash(
            $db,
            $versionId,
            'product',
            $productId
        );
        $db->prepare("
            UPDATE products
            SET name = ?, category = 'selective benchmark updated',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([(string)$alternate['name'], $productId]);
        recipeScoreMarkProductDirty(
            $db,
            $productId,
            'selective_production_benchmark_update'
        );
        return [
            'product_id' => $productId,
            'expected_aggregates' => [[
                'type' => 'product',
                'id' => $productId,
                'operation' => 'replace',
                'before_hash' => $beforeHash,
            ]],
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);
$scenarios[] = $runScenario(
    'product_delete',
    static function () use (
        $db,
        $aggregateHash,
        $versionId,
        &$productId
    ): array {
        $beforeHash = $aggregateHash(
            $db,
            $versionId,
            'product',
            $productId
        );
        recipeScoreMarkProductDirty(
            $db,
            $productId,
            'selective_production_benchmark_delete'
        );
        $db->prepare("DELETE FROM products WHERE id = ?")
            ->execute([$productId]);
        return [
            'product_id' => $productId,
            'expected_aggregates' => [[
                'type' => 'product',
                'id' => $productId,
                'operation' => 'delete',
                'before_hash' => $beforeHash,
            ]],
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);
$scenarios[] = $runScenario(
    'snapshot_race',
    static function () use (
        $db,
        $token,
        &$snapshotFirstProductId,
        &$snapshotFixtureId
    ): array {
        $db->prepare("
            INSERT INTO products (
                barcode, name, brand, category, unit,
                default_quantity, prepared_food
            )
            VALUES (?, ?, 'Selective Benchmark Race',
                    'selective benchmark race', 'pz', 1, 0)
        ")->execute([
            'SB-RACE-A-' . $token,
            'Selective Benchmark Race A ' . $token,
        ]);
        $snapshotFirstProductId = (int)$db->lastInsertId();
        $snapshotFixtureId =
            ingredientOntologyV3IncrementalBenchmarkFixtureStage(
                $db,
                $token,
                'before_incremental_snapshot',
                'insert_product',
                [
                    'barcode' => 'SB-RACE-B-' . $token,
                    'name' =>
                        'Selective Benchmark Race B ' . $token,
                    'brand' => 'Selective Benchmark Race',
                    'category' => 'selective benchmark race',
                    'unit' => 'pz',
                    'default_quantity' => 1,
                    'prepared_food' => false,
                ]
            );
        return [
            'durable_fixture_id' => $snapshotFixtureId,
            'expected_touched_aggregates' => 2,
            'expected_affected_recipes' => 0,
            'expected_physical_score_rows' => 0,
            'allow_zero_affected_recipes' => true,
            'expected_aggregates' => [[
                'type' => 'product',
                'id' => $snapshotFirstProductId,
                'operation' => 'replace',
            ]],
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);
$snapshotFixture = ingredientOntologyV3IncrementalBenchmarkFixture(
    $db,
    $snapshotFixtureId
);
$assert(
    is_array($snapshotFixture)
    && (string)$snapshotFixture['status'] === 'applied'
    && (int)$snapshotFixture['attempt_count'] === 1,
    'snapshot race durable fixture was not consumed exactly once'
);
$snapshotSecondProductId = (int)(
    $snapshotFixture['result']['product_id'] ?? 0
);
$snapshotSecondHead = $aggregateHead(
    $db,
    $versionId,
    'product',
    $snapshotSecondProductId
);
$assert(
    $snapshotSecondProductId > 0
    && $snapshotSecondHead !== null
    && (string)$snapshotSecondHead['operation'] === 'replace',
    'snapshot race must publish the durable fixture aggregate'
);
$scenarios[] = $runScenario(
    'recipe_insert',
    static function () use (
        $db,
        $target,
        $aliasLabel,
        $token,
        &$recipeAId,
        &$recipeBId
    ): array {
        $first = recipeCatalogSaveVariant($db, [
            'title' => 'Selective Benchmark Primary ' . $token,
            'language' => 'en',
            'ingredients' => [
                [
                    'name' => (string)$target['name'],
                    'is_required' => true,
                ],
                [
                    'name' => $aliasLabel,
                    'is_required' => true,
                ],
            ],
            'steps' => ['Combine.'],
        ], [
            'connector' => 'manual',
            'external_id' => 'selective-primary-' . $token,
            'locale' => 'en-US',
        ]);
        $second = recipeCatalogSaveVariant($db, [
            'title' => 'Selective Benchmark Reparent ' . $token,
            'language' => 'en',
            'ingredients' => [[
                'name' => 'water',
                'is_required' => true,
            ]],
            'steps' => ['Combine.'],
        ], [
            'connector' => 'manual',
            'external_id' => 'selective-reparent-' . $token,
            'locale' => 'en-US',
        ]);
        $recipeAId = (int)$first['id'];
        $recipeBId = (int)$second['id'];
        return [
            'recipe_ids' => [$recipeAId, $recipeBId],
            'expected_affected_recipes' => 2,
            'expected_physical_score_rows' => 2,
            'expected_aggregates' => [
                [
                    'type' => 'recipe',
                    'id' => $recipeAId,
                    'operation' => 'replace',
                ],
                [
                    'type' => 'recipe',
                    'id' => $recipeBId,
                    'operation' => 'replace',
                ],
            ],
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);
$scenarios[] = $runScenario(
    'recipe_update',
    static function () use (
        $db,
        $alternate,
        $aliasLabel,
        $token,
        $aggregateHash,
        $versionId,
        &$recipeAId
    ): array {
        $beforeHash = $aggregateHash(
            $db,
            $versionId,
            'recipe',
            $recipeAId
        );
        recipeCatalogSaveVariant($db, [
            'title' => 'Selective Benchmark Primary Updated ' . $token,
            'language' => 'en',
            'ingredients' => [
                [
                    'name' => (string)$alternate['name'],
                    'is_required' => true,
                ],
                [
                    'name' => $aliasLabel,
                    'is_required' => true,
                ],
            ],
            'steps' => ['Combine again.'],
        ], [
            'recipe_id' => $recipeAId,
            'connector' => 'manual',
            'external_id' => 'selective-primary-' . $token,
            'locale' => 'en-US',
        ]);
        return [
            'recipe_id' => $recipeAId,
            'expected_affected_recipes' => 1,
            'expected_physical_score_rows' => 1,
            'expected_aggregates' => [[
                'type' => 'recipe',
                'id' => $recipeAId,
                'operation' => 'replace',
                'before_hash' => $beforeHash,
            ]],
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);
$scenarios[] = $runScenario(
    'recipe_reparent',
    static function () use (
        $db,
        $aliasLabel,
        $aggregateHash,
        $versionId,
        &$recipeAId,
        &$recipeBId
    ): array {
        $beforeA = $aggregateHash(
            $db,
            $versionId,
            'recipe',
            $recipeAId
        );
        $beforeB = $aggregateHash(
            $db,
            $versionId,
            'recipe',
            $recipeBId
        );
        $normalized = ingredientOntologyV3NormalizeLabel($aliasLabel);
        $moved = [];
        foreach ([
            ['recipe_ingredients', 'normalized_name'],
            ['recipe_source_ingredients', 'normalized_name'],
        ] as [$table, $column]) {
            $nextPosition = $db->prepare("
                SELECT COALESCE(MAX(position), 0) + 1
                FROM {$table}
                WHERE recipe_id = ?
            ");
            $nextPosition->execute([$recipeBId]);
            $position = (int)$nextPosition->fetchColumn();
            $db->prepare("
                UPDATE {$table}
                SET recipe_id = ?, position = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE recipe_id = ? AND {$column} = ?
            ")->execute([
                $recipeBId,
                $position,
                $recipeAId,
                $normalized,
            ]);
            $moved[$table] = (int)$db->query(
                'SELECT changes()'
            )->fetchColumn();
            if (
                $table === 'recipe_ingredients'
                && $moved[$table] !== 1
            ) {
                throw new RuntimeException(
                    "benchmark expected one {$table} row to re-parent"
                );
            }
            if ($moved[$table] > 1) {
                throw new RuntimeException(
                    "benchmark re-parented too many {$table} rows"
                );
            }
        }
        return [
            'from_recipe_id' => $recipeAId,
            'to_recipe_id' => $recipeBId,
            'moved_rows' => $moved,
            'expected_affected_recipes' => 2,
            'expected_physical_score_rows' => 2,
            'expected_aggregates' => [
                [
                    'type' => 'recipe',
                    'id' => $recipeAId,
                    'operation' => 'replace',
                    'before_hash' => $beforeA,
                ],
                [
                    'type' => 'recipe',
                    'id' => $recipeBId,
                    'operation' => 'replace',
                    'before_hash' => $beforeB,
                ],
            ],
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);
$scenarios[] = $runScenario(
    'recipe_delete',
    static function () use (
        $db,
        $aggregateHash,
        $versionId,
        &$recipeAId
    ): array {
        $beforeHash = $aggregateHash(
            $db,
            $versionId,
            'recipe',
            $recipeAId
        );
        $deleted = recipeCatalogDelete($db, $recipeAId);
        if (!$deleted) {
            throw new RuntimeException(
                'benchmark recipe delete did not change a row'
            );
        }
        return [
            'recipe_id' => $recipeAId,
            'contains_recipe_delete' => true,
            'expected_affected_recipes' => 1,
            'expected_physical_score_rows' => 0,
            'expected_aggregates' => [[
                'type' => 'recipe',
                'id' => $recipeAId,
                'operation' => 'delete',
                'before_hash' => $beforeHash,
            ]],
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);
$scenarios[] = $runScenario(
    'alias_source_label_divergence',
    static function () use (
        $db,
        $aliasLabel,
        $aggregateHash,
        $versionId,
        &$recipeBId
    ): array {
        $beforeHash = $aggregateHash(
            $db,
            $versionId,
            'recipe',
            $recipeBId
        );
        $ranking = $db->prepare("
            UPDATE recipe_ingredients
            SET raw_text = ?,
                normalized_name = 'legacy identity fallback mismatch',
                updated_at = CURRENT_TIMESTAMP
            WHERE recipe_id = ?
              AND normalized_name = ?
        ");
        $ranking->execute([
            $aliasLabel,
            $recipeBId,
            ingredientOntologyV3NormalizeLabel($aliasLabel),
        ]);
        $source = $db->prepare("
            UPDATE recipe_source_ingredients
            SET name = ?,
                normalized_name = 'legacy identity fallback mismatch',
                updated_at = CURRENT_TIMESTAMP
            WHERE recipe_id = ?
              AND name = ?
        ");
        $source->execute([$aliasLabel, $recipeBId, $aliasLabel]);
        if (
            $ranking->rowCount() !== 1
            || $source->rowCount() > 1
        ) {
            throw new RuntimeException(
                'benchmark alias divergence rows are unavailable'
            );
        }
        return [
            'recipe_id' => $recipeBId,
            'raw_source_label' => $aliasLabel,
            'normalized_name' =>
                'legacy identity fallback mismatch',
            'expected_affected_recipes' => 1,
            'expected_physical_score_rows' => 1,
            'expected_aggregates' => [[
                'type' => 'recipe',
                'id' => $recipeBId,
                'operation' => 'replace',
                'before_hash' => $beforeHash,
            ]],
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);
$scenarios[] = $runScenario(
    'taxonomy_reparent',
    static function () use (
        $db,
        $target,
        $alternate,
        $aggregateHash,
        $assert,
        $versionId,
        $dataset
    ): array {
        $dependency = $db->prepare("
            SELECT aggregate_id
            FROM ingredient_ontology_corpus_annex_effective_members
            WHERE ontology_version_id = ?
              AND aggregate_type = 'recipe'
              AND CAST(json_extract(
                    payload_json,
                    '$.canonical_ingredient_id'
                  ) AS INTEGER) = ?
            ORDER BY aggregate_id
            LIMIT 1
        ");
        $dependency->execute([$versionId, (int)$target['id']]);
        $recipeId = (int)($dependency->fetchColumn() ?: 0);
        $assert(
            $recipeId > 0,
            'benchmark taxonomy dependency recipe is unavailable'
        );
        $beforeHash = $aggregateHash(
            $db,
            $versionId,
            'recipe',
            $recipeId
        );
        $dependencies =
            ingredientOntologyV3CorpusAnnexCanonicalDependencyScopes(
                $db,
                [(int)$target['id']],
                $versionId,
                (int)$dataset['projection_aggregates'] + 1
            );
        $assert(
            empty($dependencies['has_more']),
            'benchmark taxonomy dependency closure exceeded the corpus'
        );
        $expectedTouched = count($dependencies['product'])
            + count($dependencies['recipe']);
        $db->prepare("
            UPDATE canonical_ingredients
            SET parent_slug = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([
            (string)$alternate['parent_slug'],
            (int)$target['id'],
        ]);
        return [
            'canonical_ingredient_id' => (int)$target['id'],
            'old_parent' => (string)$target['parent_slug'],
            'new_parent' => (string)$alternate['parent_slug'],
            'expected_touched_aggregates' => $expectedTouched,
            'expected_aggregates' => [[
                'type' => 'recipe',
                'id' => $recipeId,
                'operation' => 'replace',
                'before_hash' => $beforeHash,
            ]],
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);
$taxonomyNode = $db->query("
    SELECT node.id, node.tree_id
    FROM taxonomy_nodes node
    WHERE node.active = 1
    ORDER BY node.id
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
$assert(is_array($taxonomyNode), 'taxonomy alias target is unavailable');
$scenarios[] = $runScenario(
    'overlay_alias_insert',
    static function () use (
        $db,
        $taxonomyNode,
        $aliasLabel,
        $aggregateHash,
        $versionId,
        &$recipeBId,
        &$aliasId
    ): array {
        $beforeHash = $aggregateHash(
            $db,
            $versionId,
            'recipe',
            $recipeBId
        );
        $db->prepare("
            INSERT INTO taxonomy_aliases (
                tree_id, node_id, alias, normalized_alias,
                source, active
            )
            VALUES (?, ?, ?, ?, 'gemini_selective_benchmark', 1)
        ")->execute([
            (int)$taxonomyNode['tree_id'],
            (int)$taxonomyNode['id'],
            $aliasLabel,
            ingredientOntologyV3NormalizeLabel($aliasLabel),
        ]);
        $aliasId = (int)$db->lastInsertId();
        return [
            'taxonomy_alias_id' => $aliasId,
            'expected_affected_recipes' => 1,
            'expected_physical_score_rows' => 1,
            'expected_aggregates' => [[
                'type' => 'recipe',
                'id' => $recipeBId,
                'operation' => 'replace',
                'before_hash' => $beforeHash,
            ]],
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);
$scenarios[] = $runScenario(
    'overlay_alias_deactivate',
    static function () use (
        $db,
        $aggregateHash,
        $versionId,
        &$recipeBId,
        &$aliasId
    ): array {
        $beforeHash = $aggregateHash(
            $db,
            $versionId,
            'recipe',
            $recipeBId
        );
        $db->prepare("
            UPDATE taxonomy_aliases
            SET active = 0, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$aliasId]);
        return [
            'taxonomy_alias_id' => $aliasId,
            'expected_affected_recipes' => 1,
            'expected_physical_score_rows' => 1,
            'expected_aggregates' => [[
                'type' => 'recipe',
                'id' => $recipeBId,
                'operation' => 'replace',
                'before_hash' => $beforeHash,
            ]],
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);
$identityBenchmarkClaim = null;
$identityFixtureId = 0;
$identityAnnexBefore = $db->prepare("
    SELECT label_id, entity_id, extension_entity_id, status,
           admission_source, evidence_hash, reason
    FROM ingredient_ontology_identity_annex
    WHERE product_id = ? AND ontology_version_id = ?
");
$identityAnnexBefore->execute([
    $identityBenchmarkProductId,
    $versionId,
]);
$identityAnnexBefore = $identityAnnexBefore->fetch(PDO::FETCH_ASSOC);
$assert(
    is_array($identityAnnexBefore),
    'identity benchmark product annex is unavailable'
);
$identityPinBefore = ingredientOntologyV3CorpusAnnexForScore(
    $db,
    recipeScoreActiveRevision($db)
);
$scenarios[] = $runScenario(
    'identity_extension_only',
    static function () use (
        $db,
        $versionId,
        $token,
        $aggregateHash,
        $identityBenchmarkProductId,
        $identityBenchmarkRecipeIds,
        &$identityFixtureId
    ): array {
        $productHash = $aggregateHash(
            $db,
            $versionId,
            'product',
            $identityBenchmarkProductId
        );
        $expectedAggregates = [[
            'type' => 'product',
            'id' => $identityBenchmarkProductId,
            'operation' => 'replace',
            'before_hash' => $productHash,
        ]];
        foreach ($identityBenchmarkRecipeIds as $recipeId) {
            $expectedAggregates[] = [
                'type' => 'recipe',
                'id' => $recipeId,
                'operation' => 'replace',
                'before_hash' => $aggregateHash(
                    $db,
                    $versionId,
                    'recipe',
                    $recipeId
                ),
            ];
        }
        recipeScoreMarkProductDirty(
            $db,
            $identityBenchmarkProductId,
            'selective_benchmark_identity_only'
        );
        $identityFixtureId =
            ingredientOntologyV3IncrementalBenchmarkFixtureStage(
                $db,
                $token,
                'after_identity_admission',
                'assign_identity_extension',
                [
                    'product_id' => $identityBenchmarkProductId,
                    'context_signature' =>
                        'production-benchmark-' . $token,
                    'admission_source' =>
                        'benchmark_identity_only',
                    'reason' => 'benchmark_identity_only',
                    'evidence_namespace' =>
                        'identity-extension-only',
                ]
            );
        return [
            'durable_fixture_id' => $identityFixtureId,
            'product_id' => $identityBenchmarkProductId,
            'recipe_ids' => $identityBenchmarkRecipeIds,
            'expect_source_revision_unchanged' => true,
            'expected_touched_aggregates' =>
                1 + count($identityBenchmarkRecipeIds),
            'max_pages' => max(
                2,
                (int)ceil(
                    (1 + count($identityBenchmarkRecipeIds))
                    / ingredientOntologyV3IncrementalProductLimit()
                ) + 2
            ),
            'expected_aggregates' => $expectedAggregates,
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);
$identityFixture = ingredientOntologyV3IncrementalBenchmarkFixture(
    $db,
    $identityFixtureId
);
$assert(
    is_array($identityFixture)
    && (string)$identityFixture['status'] === 'applied'
    && (int)$identityFixture['attempt_count'] === 1,
    'identity durable fixture was not consumed exactly once'
);
$identityBenchmarkClaim = is_array($identityFixture['result'] ?? null)
    ? [
        'id' => (int)$identityFixture['result'][
            'extension_entity_id'
        ],
        'created_revision' => (int)$identityFixture['result'][
            'created_revision'
        ],
    ]
    : null;
$identityPinAfter = ingredientOntologyV3CorpusAnnexForScore(
    $db,
    recipeScoreActiveRevision($db)
);
$assert(
    $identityBenchmarkClaim !== null
    && (int)$identityPinAfter['identity_extension_revision']
        > (int)$identityPinBefore['identity_extension_revision'],
    'identity-only benchmark must advance the exact projection fence'
);
$scenarios[] = $runScenario(
    'recoverable_journal_gap',
    static function () use (
        $db,
        $target,
        $token
    ): array {
        $db->prepare("
            INSERT INTO products (
                barcode, name, brand, category, unit,
                default_quantity, prepared_food
            )
            VALUES (?, ?, 'Selective Benchmark Gap',
                    'selective benchmark gap', 'pz', 1, 0)
        ")->execute([
            'SB-GAP-' . $token,
            (string)$target['name'],
        ]);
        $productId = (int)$db->lastInsertId();
        $revision = (int)recipeScoreState($db)[
            'ontology_source_revision'
        ];
        $db->prepare("
            DELETE FROM recipe_score_mutations
            WHERE domain = 'source' AND revision = ?
        ")->execute([$revision]);
        $decision =
            ingredientOntologyV3CorpusProjectionV2DriftDecision($db);
        if (
            (string)($decision['reason'] ?? '')
                !== 'authoritative_reconciliation_pending'
        ) {
            throw new RuntimeException(
                'benchmark journal gap did not request reconciliation: '
                . ingredientOntologyV3Json($decision)
            );
        }
        return [
            'product_id' => $productId,
            'missing_source_revision' => $revision,
            'expected_reconciliation_modes' => ['authoritative'],
            'require_scope_reconciliation_complete' => true,
            'expected_aggregates' => [[
                'type' => 'product',
                'id' => $productId,
                'operation' => 'replace',
            ]],
        ];
    },
    $db,
    $versionId,
    $sentinelRecipeId,
    $dataset['projection_aggregates']
);

$rolloverParent = recipeScoreActiveRevision($db);
$rolloverPinBefore = ingredientOntologyV3CorpusAnnexForScore(
    $db,
    $rolloverParent
);
$rolloverContentBefore =
    ingredientOntologyV3CorpusAnnexEffectiveContentHash(
        $db,
        $versionId
    );
$rolloverScoreSourcesBefore =
    ingredientOntologyV3HashMaterializedRows(
        $db,
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
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$GLOBALS[
    'INGREDIENT_ONTOLOGY_CORPUS_ANNEX_COMPACTION_DEPTH_OVERRIDE'
] = 1;
$GLOBALS['INGREDIENT_ONTOLOGY_V3_CORPUS_FULL_SCAN_COUNT'] = 0;
$rolloverStarted = hrtime(true);
$rollover =
    ingredientOntologyV3CorpusProjectionV2Compact($db);
$rolloverMs = (hrtime(true) - $rolloverStarted) / 1000000;
unset(
    $GLOBALS[
        'INGREDIENT_ONTOLOGY_CORPUS_ANNEX_COMPACTION_DEPTH_OVERRIDE'
    ]
);
$rolloverPeak = max(
    memory_get_peak_usage(true),
    $currentRssBytes()
);
$rolloverActive = recipeScoreActiveRevision($db);
$rolloverPinAfter = ingredientOntologyV3CorpusAnnexForScore(
    $db,
    $rolloverActive
);
$rolloverScoreSourcesAfter =
    ingredientOntologyV3HashMaterializedRows(
        $db,
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
$rolloverPhysicalRows = $db->prepare("
    SELECT COUNT(*)
    FROM recipe_inventory_scores
    WHERE score_revision_id = ?
");
$rolloverPhysicalRows->execute([(int)$rolloverActive['id']]);
$rolloverManifest = json_decode(
    (string)$rolloverPinAfter['mutation_manifest_json'],
    true
);
$rolloverFullCorpusScans = (int)($GLOBALS[
    'INGREDIENT_ONTOLOGY_V3_CORPUS_FULL_SCAN_COUNT'
] ?? 0);
$assert(
    !empty($rollover['compacted'])
    && $rolloverPinAfter['parent_revision_id'] === null
    && (int)$rolloverPinAfter['entry_count'] === 0
    && (int)$rolloverPinAfter['aggregate_count'] === 0
    && (int)($rolloverManifest[
        'checkpoint_source'
    ]['revision_id'] ?? 0) === (int)$rolloverPinBefore['id']
    && (int)$rolloverPinAfter['id']
        !== (int)$rolloverPinBefore['id']
    && (int)$rolloverActive['parent_score_revision_id']
        === (int)$rolloverParent['id']
    && recipeScoreRevisionIsSparseDelta($rolloverActive)
    && (int)$rolloverPhysicalRows->fetchColumn() === 0
    && hash_equals(
        $rolloverContentBefore,
        ingredientOntologyV3CorpusAnnexEffectiveContentHash(
            $db,
            $versionId
        )
    )
    && hash_equals(
        (string)$rolloverScoreSourcesBefore['hash'],
        (string)$rolloverScoreSourcesAfter['hash']
    )
    && $rolloverFullCorpusScans === 0
    && $rolloverMs <= $maximumRolloverMs
    && $rolloverPeak <= $maximumPhpRssMb * 1048576,
    'production-sized chain rollover violated score, content, time, '
        . 'or memory parity: ' . ingredientOntologyV3Json([
            'result' => $rollover,
            'elapsed_ms' => $rolloverMs,
            'peak_memory_mb' => $rolloverPeak / 1048576,
            'full_corpus_scans' => $rolloverFullCorpusScans,
        ])
);

$gapProductId = 0;
foreach ($scenarios as $scenario) {
    if ((string)$scenario['name'] === 'recoverable_journal_gap') {
        $gapProductId = (int)(
            $scenario['metadata']['product_id'] ?? 0
        );
        break;
    }
}
foreach ([$recipeAId, $recipeBId] as $cleanupRecipeId) {
    recipeCatalogDelete($db, $cleanupRecipeId);
}
$db->beginTransaction();
try {
    if ($aliasId > 0) {
        $db->prepare("DELETE FROM taxonomy_aliases WHERE id = ?")
            ->execute([$aliasId]);
    }
    $db->prepare("
        UPDATE canonical_ingredients
        SET parent_slug = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([
        (string)$target['parent_slug'],
        $targetId,
    ]);
    $cleanupProductIds = array_values(array_filter([
        $snapshotFirstProductId,
        $snapshotSecondProductId,
        $gapProductId,
    ], static fn(int $id): bool => $id > 0));
    if ($cleanupProductIds) {
        $db->prepare("
            DELETE FROM products
            WHERE id IN (
                " . implode(
                    ',',
                    array_fill(0, count($cleanupProductIds), '?')
                ) . "
            )
        ")->execute($cleanupProductIds);
    }
    $db->prepare("
        UPDATE ingredient_ontology_identity_annex
        SET label_id = ?,
            entity_id = ?,
            extension_entity_id = ?,
            status = ?,
            admission_source = ?,
            evidence_hash = ?,
            reason = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
          AND ontology_version_id = ?
    ")->execute([
        $identityAnnexBefore['label_id'],
        $identityAnnexBefore['entity_id'],
        $identityAnnexBefore['extension_entity_id'],
        (string)$identityAnnexBefore['status'],
        (string)$identityAnnexBefore['admission_source'],
        (string)$identityAnnexBefore['evidence_hash'],
        (string)$identityAnnexBefore['reason'],
        $identityBenchmarkProductId,
        $versionId,
    ]);
    $db->commit();
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
recipeScoreMarkProductDirty(
    $db,
    $identityBenchmarkProductId,
    'selective_benchmark_identity_restore'
);
$cleanupState = recipeScoreState($db);
$cleanupPendingRecipe = $db->prepare("
    INSERT INTO recipe_score_pending_recipes (
        recipe_id, operation, lane, first_catalog_revision,
        latest_catalog_revision,
        latest_ontology_source_revision, reason,
        created_at, updated_at
    )
    VALUES (
        ?, 'replace', 'maintenance', ?, ?, ?,
        'recipe_insert', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
    ON CONFLICT(recipe_id) DO UPDATE SET
        operation = 'replace',
        lane = 'maintenance',
        latest_catalog_revision = excluded.latest_catalog_revision,
        latest_ontology_source_revision =
            excluded.latest_ontology_source_revision,
        reason = excluded.reason,
        updated_at = CURRENT_TIMESTAMP
");
foreach ($identityBenchmarkRecipeIds as $recipeId) {
    $cleanupPendingRecipe->execute([
        $recipeId,
        (int)$cleanupState['catalog_revision'],
        (int)$cleanupState['catalog_revision'],
        (int)$cleanupState['ontology_source_revision'],
    ]);
}
$cleanupPages = 0;
for ($attempt = 0; $attempt < 25; $attempt++) {
    $cleanupResult =
        ingredientOntologyV3IncrementalRebuild($db, true, 500);
    $cleanupPages++;
    $cleanupState = recipeScoreState($db);
    $cleanupActive = recipeScoreActiveRevision($db);
    if (
        array_sum($pendingCounts($db)) === 0
        && (int)$cleanupActive['covered_ontology_source_revision']
            >= (int)$cleanupState['ontology_source_revision']
        && (int)$cleanupActive['covered_catalog_revision']
            >= (int)$cleanupState['catalog_revision']
    ) {
        break;
    }
}
$remainingBenchmarkFixtures = (int)$db->query("
    SELECT COUNT(*)
    FROM ingredient_ontology_incremental_benchmark_fixtures
    WHERE status <> 'applied'
")->fetchColumn();
$assert(
    $remainingBenchmarkFixtures === 0,
    'benchmark durable fixtures were not fully consumed'
);
ingredientOntologyV3IncrementalBenchmarkFixtureClear($db);
putenv('INGREDIENT_ONTOLOGY_V3_BENCHMARK_FIXTURE_TOKEN');
$leftoverBenchmarkEntities = (int)$db->query("
    SELECT (
        SELECT COUNT(*) FROM products
        WHERE barcode LIKE 'SB-%'
    ) + (
        SELECT COUNT(*) FROM recipe_catalog
        WHERE title LIKE 'Selective Benchmark %'
          AND deleted_at IS NULL
    ) + (
        SELECT COUNT(*) FROM taxonomy_aliases
        WHERE source = 'gemini_selective_benchmark'
    )
")->fetchColumn();
$restoredParentStmt = $db->prepare("
    SELECT parent_slug FROM canonical_ingredients WHERE id = ?
");
$restoredParentStmt->execute([$targetId]);
$restoredParent = (string)$restoredParentStmt->fetchColumn();
$foreignKeyViolations = (int)$db->query("
    SELECT COUNT(*) FROM pragma_foreign_key_check
")->fetchColumn();
$assert(
    $leftoverBenchmarkEntities === 0
    && $restoredParent === (string)$target['parent_slug']
    && array_sum($pendingCounts($db)) === 0
    && $foreignKeyViolations === 0,
    'benchmark cleanup left source fixtures, pending work, or foreign '
        . 'key violations'
);
$cleanupReport = [
    'pages' => $cleanupPages,
    'leftover_benchmark_entities' => $leftoverBenchmarkEntities,
    'foreign_key_violations' => $foreignKeyViolations,
];

$finalActive = recipeScoreActiveRevision($db);
$assert($finalActive !== null, 'benchmark final score is unavailable');
$finalAnnex = ingredientOntologyV3CorpusAnnexForScore(
    $db,
    $finalActive
);
$assert(
    $finalAnnex !== null
    && ingredientOntologyV3CorpusAnnexProjectionReady(
        $db,
        $finalAnnex
    ),
    'benchmark final projection is stale'
);
fwrite(STDERR, "benchmark: final corpus integrity audit\n");
$audit = ingredientOntologyV3CorpusAnnexIntegrityAudit(
    $db,
    (int)$finalAnnex['id'],
    (string)$finalAnnex['revision_hash'],
    true
);
$finalQuickCheckStarted = hrtime(true);
fwrite(STDERR, "benchmark: final quick_check\n");
$quickCheck = (string)$db->query(
    'PRAGMA quick_check'
)->fetchColumn();
$finalQuickCheckMs = round(
    (hrtime(true) - $finalQuickCheckStarted) / 1000000,
    3
);
$assert(
    !empty($audit['valid']) && $quickCheck === 'ok',
    'benchmark final integrity validation failed'
);

$output = [
    'success' => true,
    'database' => $databasePath,
    'dataset' => $dataset,
    'target_ingredient' => [
        'id' => $targetId,
        'slug' => (string)$target['slug'],
        'name' => (string)$target['name'],
        'recipe_count' => (int)$target['recipe_count'],
    ],
    'alternate_ingredient' => [
        'id' => $alternateId,
        'slug' => (string)$alternate['slug'],
        'name' => (string)$alternate['name'],
        'recipe_count' => (int)$alternate['recipe_count'],
    ],
    'sentinel_recipe_ids' => $sentinelRecipeIds,
    'limits' => [
        'scenario_ms' => $maximumScenarioMs,
        'write_lock_ms' => $maximumWriteLockMs,
        'php_rss_mb' => $maximumPhpRssMb,
        'pages' => $maximumPages,
        'rollover_ms' => $maximumRolloverMs,
        'worker_lifecycle' => $workerLifecycle,
    ],
    'baseline_score_revision_id' => (int)$active['id'],
    'final_score_revision_id' => (int)$finalActive['id'],
    'score_revision_delta' =>
        (int)$finalActive['id'] - (int)$active['id'],
    'scenarios' => $scenarios,
    'rollover' => $rollover + [
        'measured_elapsed_ms' => round($rolloverMs, 3),
        'peak_php_memory_mb' =>
            round($rolloverPeak / 1048576, 3),
    ],
    'cleanup' => $cleanupReport,
    'integrity_valid' => !empty($audit['valid']),
    'integrity_errors' => (array)$audit['errors'],
    'quick_check' => $quickCheck,
    'final_quick_check_ms' => $finalQuickCheckMs,
];
$json = json_encode(
    $output,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
if ($jsonOut !== '') {
    recipeCliWriteFileAtomically($jsonOut, $json);
}
echo $json;
