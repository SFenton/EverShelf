#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$path = dirname(__DIR__) . '/data/.incremental-mutation-memory-'
    . getmypid() . '.sqlite';
@unlink($path);
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
initializeDB($db);
migrateDB($db);

$rowCount = 500000;
$db->exec("
    WITH RECURSIVE sequence(value) AS (
        VALUES (2)
        UNION ALL
        SELECT value + 1
        FROM sequence
        WHERE value <= {$rowCount}
    )
    INSERT INTO recipe_score_mutations (
        domain, revision, lane, owner_type,
        owner_id, operation, reason
    )
    SELECT
        'catalog', value, 'maintenance', 'global',
        NULL, 'global', 'memory_regression'
    FROM sequence
");

$parent = [
    'catalog_revision' => 1,
    'ontology_source_revision' => 1,
];
$state = [
    'catalog_revision' => $rowCount + 1,
    'ontology_source_revision' => 1,
];
$before = memory_get_usage(true);
$errors = ingredientOntologyV3IncrementalScopedMutationErrors(
    $db,
    $parent,
    $state,
    [],
    []
);
$afterErrors = memory_get_usage(true);
$lineageHash = ingredientOntologyV3IncrementalScopedInputHash(
    $db,
    'catalog',
    str_repeat('a', 64),
    1,
    $rowCount + 1,
    [],
    []
);
$afterHash = memory_get_usage(true);
$servingErrors =
    ingredientOntologyV3IncrementalScopedMutationErrors(
        $db,
        $parent,
        $state,
        [],
        [],
        true
    );
$sourceFrom = (int)$db->query("
    SELECT COALESCE(MAX(revision), 0)
    FROM recipe_score_mutations
    WHERE domain = 'source'
")->fetchColumn();
$sourceThrough = $sourceFrom + $rowCount;
$db->exec("
    WITH RECURSIVE sequence(value) AS (
        VALUES (" . ($sourceFrom + 1) . ")
        UNION ALL
        SELECT value + 1
        FROM sequence
        WHERE value < {$sourceThrough}
    )
    INSERT INTO recipe_score_mutations (
        domain, revision, lane, owner_type,
        owner_id, operation, source_table, source_row_id, reason
    )
    SELECT
        'source', value, 'maintenance', 'product',
        value, 'update', 'products', value, 'memory_regression'
    FROM sequence
");
$sourceWindow = ingredientOntologyV3CorpusAnnexJournalWindow(
    $db,
    $sourceFrom,
    $sourceThrough,
    INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS
);
$afterSourceWindow = memory_get_usage(true);
$memoryDelta = max($afterErrors, $afterHash) - $before;
$memoryDelta = max($memoryDelta, $afterSourceWindow - $before);

if (
    !in_array(
        'catalog_mutation_unscoped',
        $errors,
        true
    )
    || $servingErrors !== []
    || strlen($lineageHash) !== 64
    || empty($sourceWindow['dense'])
    || !empty($sourceWindow['complete'])
    || empty($sourceWindow['has_more'])
    || !empty($sourceWindow['oversized'])
    || (int)$sourceWindow['event_count']
        !== INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS
    || (int)$sourceWindow['scope_row_count']
        !== INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS
    || (int)$sourceWindow['through_revision']
        !== $sourceFrom
            + INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS
    || count((array)$sourceWindow['events'])
        !== INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS
    || $memoryDelta > 32 * 1024 * 1024
) {
    throw new RuntimeException(
        'Mutation journal validation exceeded its bounded contract: '
        . json_encode([
            'errors' => $errors,
            'serving_errors' => $servingErrors,
            'lineage_hash' => $lineageHash,
            'source_window' => [
                'dense' => $sourceWindow['dense'] ?? null,
                'complete' => $sourceWindow['complete'] ?? null,
                'has_more' => $sourceWindow['has_more'] ?? null,
                'oversized' => $sourceWindow['oversized'] ?? null,
                'event_count' =>
                    $sourceWindow['event_count'] ?? null,
                'scope_row_count' =>
                    $sourceWindow['scope_row_count'] ?? null,
                'through_revision' =>
                    $sourceWindow['through_revision'] ?? null,
            ],
            'memory_delta' => $memoryDelta,
            'peak_memory' => memory_get_peak_usage(true),
        ], JSON_UNESCAPED_SLASHES)
    );
}

$db = null;
@unlink($path);
@unlink($path . '-wal');
@unlink($path . '-shm');

echo json_encode([
    'success' => true,
    'mutation_rows' => $rowCount,
    'source_mutation_rows' => $rowCount,
    'memory_delta_mb' => round($memoryDelta / 1048576, 3),
    'peak_memory_mb' => round(
        memory_get_peak_usage(true) / 1048576,
        3
    ),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
