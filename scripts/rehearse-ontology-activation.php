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
$targetPath = recipeCliCanonicalPath(
    trim((string)($options['target'] ?? ''))
);
$bundlePath = recipeCliCanonicalPath(
    trim((string)($options['bundle'] ?? ''))
);
$payloadDirectory = recipeCliCanonicalPath(
    trim((string)($options['payload-dir'] ?? ''))
);
if (
    !is_file($targetPath)
    || !is_file($bundlePath)
    || !is_dir($payloadDirectory)
) {
    throw new InvalidArgumentException(
        '--target, --bundle, and --payload-dir are required'
    );
}
if (recipeCliSameFile($targetPath, DB_PATH)) {
    throw new RuntimeException(
        'activation rehearsal refuses the live database'
    );
}
$document = json_decode(
    (string)file_get_contents($bundlePath),
    true,
    128,
    JSON_THROW_ON_ERROR
);
if (
    !is_array($document)
    || (string)($document['schema_version'] ?? '')
        !== 'ontology-activation-bundle-set-v2'
) {
    throw new InvalidArgumentException(
        'activation rehearsal bundle set is invalid'
    );
}

$db = ingredientOntologyActivationOpenDatabase($targetPath);
$GLOBALS['ONTOLOGY_CONTROLLER_ACTIVE_DB_PATH_OVERRIDE'] = $targetPath;
$started = hrtime(true);
try {
    $ontology = ingredientOntologyActivationRegisterImport(
        $db,
        $document['ontology'],
        $payloadDirectory
    );
    $ontology = ingredientOntologyActivationDriveImport(
        $db,
        (int)$ontology['id'],
        [
            'maximum_loops' => 1000,
            'maximum_chunks' => 500,
        ]
    );
    if ((string)$ontology['status'] !== 'complete') {
        throw new RuntimeException(
            'ontology rehearsal import did not complete: '
            . (string)$ontology['status']
        );
    }
    $score = ingredientOntologyActivationRegisterImport(
        $db,
        $document['score'],
        $payloadDirectory
    );
    $score = ingredientOntologyActivationDriveImport(
        $db,
        (int)$score['id'],
        [
            'maximum_loops' => 1000,
            'maximum_chunks' => 500,
        ]
    );
    if ((string)$score['status'] !== 'active') {
        throw new RuntimeException(
            'score rehearsal import did not activate: '
            . (string)$score['status']
        );
    }
} finally {
    unset($GLOBALS['ONTOLOGY_CONTROLLER_ACTIVE_DB_PATH_OVERRIDE']);
}

$state = recipeScoreState($db);
$activeVersion = ingredientOntologyV3ActiveVersion($db);
echo json_encode(
    [
        'success' => true,
        'elapsed_ms' => (hrtime(true) - $started) / 1000000,
        'ontology_import' => $ontology,
        'score_import' => $score,
        'active_score_revision_id' =>
            $state['active_score_revision_id'],
        'active_ontology_version_id' =>
            $activeVersion['id'] ?? null,
        'pending_intents' => (int)$db->query("
            SELECT COUNT(*) FROM ontology_generation_intents
            WHERE status = 'pending'
        ")->fetchColumn(),
        'applied_intents' => (int)$db->query("
            SELECT COUNT(*) FROM ontology_generation_intents
            WHERE status = 'applied'
        ")->fetchColumn(),
    ],
    JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
) . PHP_EOL;

