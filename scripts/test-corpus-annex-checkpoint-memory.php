#!/usr/bin/env php
<?php
declare(strict_types=1);

putenv('INGREDIENT_ONTOLOGY_CONTROLLER_ENABLED=false');
define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$path = dirname(__DIR__) . '/data/.corpus-annex-checkpoint-memory-'
    . getmypid() . '.sqlite';
$cleanup = static function () use ($path): void {
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        @unlink($file);
    }
};
$cleanup();
register_shutdown_function($cleanup);

$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA journal_mode=WAL');
initializeDB($db);
migrateDB($db);

$hash = str_repeat('a', 64);
$db->prepare("
    INSERT INTO ingredient_ontology_versions (
        version, status, schema_hash, prompt_hash, model_hash,
        model_name, corpus_hash, content_hash
    )
    VALUES (
        'checkpoint-memory-test', 'building', ?, ?, ?,
        'gemini-3.5-flash', ?, ?
    )
")->execute([$hash, $hash, $hash, $hash, $hash]);
$versionId = (int)$db->lastInsertId();

$rowCount = 30000;
$membersPerAggregate = 20;
$aggregateCountExpected = intdiv($rowCount, $membersPerAggregate);
$zeroHash = str_repeat('0', 64);
$db->prepare("
    INSERT INTO ingredient_ontology_corpus_annex_revisions (
        ontology_version_id, ontology_content_hash,
        ontology_seal_hash, parent_revision_id,
        parent_revision_hash, hash_version, revision_hash,
        base_corpus_hash, captured_corpus_hash,
        captured_ontology_source_revision,
        covered_ontology_source_revision,
        mutation_manifest_hash, mutation_manifest_json,
        entry_set_hash, projection_root_hash,
        resolution_input_hash, identity_extension_hash,
        covered_identity_extension_revision,
        covered_identity_extension_hash,
        entry_count, aggregate_count,
        reconciliation_mode, status
    )
    VALUES (
        ?, ?, ?, NULL, ?, 2, ?, ?, ?, 1, 1, ?, '[]',
        ?, ?, ?, ?, 0, ?, ?, ?, 'checkpoint', 'building'
    )
")->execute([
    $versionId,
    $hash,
    $hash,
    $zeroHash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $hash,
    $zeroHash,
    $zeroHash,
    $rowCount,
    $aggregateCountExpected,
]);
$revisionId = (int)$db->lastInsertId();

$insertEntry = $db->prepare("
    INSERT INTO ingredient_ontology_corpus_annex_entries (
        corpus_annex_revision_id, ordinal, entry_type,
        operation, owner_type, owner_id, recipe_id,
        owner_fingerprint, identity_status,
        identity_disposition, satisfies_required,
        native_entity_slug, identity_extension_key_hash,
        resolver_version, review_manifest_hash,
        evidence_hash, aggregate_source_hash,
        resolution_input_hash, aggregate_hash, member_count,
        identity_json, payload_json, row_hash
    )
    VALUES (
        ?, ?, ?, 'replace', ?, ?, ?,
        ?, 'not_applicable', 'scope_only', 0, NULL, NULL,
        'checkpoint-memory-test', ?, ?, ?, ?, ?, ?,
        '{}', ?, ?
    )
");
$payloadPadding = str_repeat('0', 4096);
$entryHash = hash_init('sha256');
$sourceHash = hash_init('sha256');
$projectionHash = hash_init('sha256');
hash_update(
    $entryHash,
    INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION . ":entry-set\n"
);
hash_update(
    $sourceHash,
    INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
        . ":aggregate-source-set\n"
);
hash_update(
    $projectionHash,
    INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
        . ":aggregate-state-set\n"
);
$db->beginTransaction();
for ($id = 1; $id <= $rowCount; $id++) {
    $aggregateId = intdiv($id - 1, $membersPerAggregate) + 1;
    $isScope = (($id - 1) % $membersPerAggregate) === 0;
    $aggregateSourceHash = hash(
        'sha256',
        'checkpoint-source:' . $aggregateId
    );
    $aggregateHash = hash(
        'sha256',
        'checkpoint-aggregate:' . $aggregateId
    );
    $payloadJson = ingredientOntologyV3Json([
        'id' => $id,
        'padding' => $payloadPadding,
    ]);
    $entry = [
        'ordinal' => $id,
        'entry_type' =>
            $isScope ? 'recipe_scope' : 'recipe_ingredient',
        'operation' => 'replace',
        'owner_type' =>
            $isScope ? 'recipe' : 'recipe_ingredient',
        'owner_id' => $isScope ? $aggregateId : $id,
        'recipe_id' => $aggregateId,
        'owner_fingerprint' => $hash,
        'identity_status' => 'not_applicable',
        'identity_disposition' => 'scope_only',
        'satisfies_required' => 0,
        'native_entity_slug' => null,
        'identity_extension_key_hash' => null,
        'resolver_version' => 'checkpoint-memory-test',
        'review_manifest_hash' => $hash,
        'evidence_hash' => $hash,
        'aggregate_source_hash' => $aggregateSourceHash,
        'resolution_input_hash' => $hash,
        'aggregate_hash' => $aggregateHash,
        'member_count' => $membersPerAggregate,
        'identity_json' => '{}',
        'payload_json' => $payloadJson,
    ];
    $rowHash = ingredientOntologyV3CorpusAnnexEntryHash($entry);
    $insertEntry->execute([
        $revisionId,
        $id,
        $isScope ? 'recipe_scope' : 'recipe_ingredient',
        $isScope ? 'recipe' : 'recipe_ingredient',
        $isScope ? $aggregateId : $id,
        $aggregateId,
        $hash,
        $hash,
        $hash,
        $aggregateSourceHash,
        $hash,
        $aggregateHash,
        $membersPerAggregate,
        $payloadJson,
        $rowHash,
    ]);
    hash_update($entryHash, $rowHash . "\n");
    if ($isScope) {
        $key = ingredientOntologyV3CorpusAnnexAggregateKey(
            'recipe',
            $aggregateId
        );
        hash_update(
            $sourceHash,
            $key . "\n" . $aggregateSourceHash . "\n"
        );
        hash_update(
            $projectionHash,
            $key . "\n" . $aggregateHash . "\n"
        );
    }
}
$db->commit();
$manifest = ingredientOntologyV3CorpusAnnexMutationManifest(
    1,
    1,
    [],
    'checkpoint',
    []
);
$entrySetHash = hash_final($entryHash);
$capturedCorpusHash = hash_final($sourceHash);
$projectionRootHash = hash_final($projectionHash);
$revision = [
    'hash_version' => 2,
    'ontology_content_hash' => $hash,
    'ontology_seal_hash' => $hash,
    'parent_revision_hash' => $zeroHash,
    'base_corpus_hash' => $hash,
    'captured_corpus_hash' => $capturedCorpusHash,
    'base_products_max_id' => 0,
    'base_recipe_catalog_max_id' => 0,
    'base_recipe_origins_max_id' => 0,
    'base_recipe_ingredients_max_id' => 0,
    'base_recipe_source_ingredients_max_id' => 0,
    'captured_ontology_source_revision' => 1,
    'covered_ontology_source_revision' => 1,
    'mutation_manifest_hash' => (string)$manifest['hash'],
    'entry_set_hash' => $entrySetHash,
    'projection_root_hash' => $projectionRootHash,
    'resolution_input_hash' => $hash,
    'identity_extension_revision' => 0,
    'identity_extension_hash' => $zeroHash,
    'covered_identity_extension_revision' => 0,
    'covered_identity_extension_hash' => $zeroHash,
    'entry_count' => $rowCount,
    'aggregate_count' => $aggregateCountExpected,
    'reconciliation_mode' => 'checkpoint',
];
$revisionHash =
    ingredientOntologyV3CorpusAnnexRevisionHash($revision);
ingredientOntologyV3SetPublicationGuard($db, true);
$db->prepare("
    UPDATE ingredient_ontology_corpus_annex_revisions
    SET revision_hash = ?,
        captured_corpus_hash = ?,
        mutation_manifest_hash = ?,
        mutation_manifest_json = ?,
        entry_set_hash = ?,
        projection_root_hash = ?,
        status = 'ready',
        ready_at = CURRENT_TIMESTAMP
    WHERE id = ? AND status = 'building'
")->execute([
    $revisionHash,
    $capturedCorpusHash,
    (string)$manifest['hash'],
    (string)$manifest['json'],
    $entrySetHash,
    $projectionRootHash,
    $revisionId,
]);
ingredientOntologyV3SetPublicationGuard($db, false);

$revision = ingredientOntologyV3CorpusAnnexRevision(
    $db,
    $revisionId
);
if ($revision === null) {
    throw new RuntimeException(
        'checkpoint memory revision could not be loaded'
    );
}
gc_collect_cycles();
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$lineageBefore = memory_get_usage(true);
$lineageStarted = hrtime(true);
$lineageAudit = ingredientOntologyV3CorpusProjectionLineageAudit(
    $db,
    $revisionId,
    $revisionHash
);
$lineageMs = (hrtime(true) - $lineageStarted) / 1000000;
$lineagePeak = memory_get_peak_usage(true);
$lineageMemoryDelta = max(0, $lineagePeak - $lineageBefore);

gc_collect_cycles();
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$before = memory_get_usage(true);
$db->exec('BEGIN IMMEDIATE');
try {
    ingredientOntologyV3CorpusAnnexApplyRevisionEntries(
        $db,
        $revision
    );
    $db->exec('COMMIT');
} catch (Throwable $error) {
    $db->exec('ROLLBACK');
    throw $error;
}
$peak = memory_get_peak_usage(true);
$replayMemoryDelta = max(0, $peak - $before);

gc_collect_cycles();
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$auditBefore = memory_get_usage(true);
$auditStarted = hrtime(true);
$audit = ingredientOntologyV3CorpusAnnexIntegrityAudit(
    $db,
    $revisionId,
    $revisionHash,
    false
);
$auditMs = (hrtime(true) - $auditStarted) / 1000000;
$auditPeak = memory_get_peak_usage(true);
$auditMemoryDelta = max(0, $auditPeak - $auditBefore);

$aggregateCount = (int)$db->query("
    SELECT COUNT(*)
    FROM ingredient_ontology_corpus_annex_effective_aggregates
    WHERE ontology_version_id = {$versionId}
")->fetchColumn();
$memberCount = (int)$db->query("
    SELECT COUNT(*)
    FROM ingredient_ontology_corpus_annex_effective_members
    WHERE ontology_version_id = {$versionId}
")->fetchColumn();

if (
    $aggregateCount !== $aggregateCountExpected
    || $memberCount !== $rowCount
    || empty($lineageAudit['valid'])
    || empty($audit['valid'])
    || $lineageMs > 2000
    || $lineageMemoryDelta > 16 * 1024 * 1024
    || $replayMemoryDelta > 32 * 1024 * 1024
    || $auditMemoryDelta > 32 * 1024 * 1024
) {
    throw new RuntimeException(
        'Checkpoint replay exceeded its bounded-memory contract: '
        . ingredientOntologyV3Json([
            'entries' => $rowCount,
            'aggregate_count' => $aggregateCount,
            'member_count' => $memberCount,
            'lineage_errors' =>
                (array)($lineageAudit['errors'] ?? []),
            'audit_errors' => (array)($audit['errors'] ?? []),
            'lineage_ms' => $lineageMs,
            'audit_ms' => $auditMs,
            'lineage_memory_delta' => $lineageMemoryDelta,
            'replay_memory_delta' => $replayMemoryDelta,
            'audit_memory_delta' => $auditMemoryDelta,
            'replay_peak_memory' => $peak,
            'audit_peak_memory' => $auditPeak,
        ])
    );
}

$db = null;
echo json_encode([
    'success' => true,
    'checkpoint_entries' => $rowCount,
    'aggregate_count' => $aggregateCount,
    'lineage_ms' => round($lineageMs, 3),
    'lineage_memory_delta_mb' =>
        round($lineageMemoryDelta / 1048576, 3),
    'audit_ms' => round($auditMs, 3),
    'replay_memory_delta_mb' =>
        round($replayMemoryDelta / 1048576, 3),
    'audit_memory_delta_mb' =>
        round($auditMemoryDelta / 1048576, 3),
    'peak_memory_mb' =>
        round(max($peak, $auditPeak) / 1048576, 3),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
