#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

function recipeFeedbackCliUsage(): never {
    fwrite(
        STDERR,
        "Usage: php scripts/recipe-ingredient-feedback.php "
            . "--db=copy.sqlite [--json-out=path.json] "
            . "[--mark-exported --write] [--allow-active-db]\n"
    );
    exit(1);
}

$databasePath = '';
$jsonOut = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--db=')) {
        $databasePath = trim(substr($arg, 5));
    } elseif (str_starts_with($arg, '--json-out=')) {
        $jsonOut = trim(substr($arg, 11));
    }
}
if ($databasePath === '') {
    recipeFeedbackCliUsage();
}
$databasePath = recipeCliAssertDatabaseInputSafe(
    $databasePath,
    in_array('--allow-active-db', $argv, true)
);
$write = in_array('--write', $argv, true);
$markExported = in_array('--mark-exported', $argv, true);
if ($markExported && !$write) {
    throw new InvalidArgumentException(
        '--mark-exported requires --write'
    );
}
if ($markExported && ($jsonOut === null || $jsonOut === '')) {
    throw new InvalidArgumentException(
        '--mark-exported requires --json-out'
    );
}
if ($jsonOut !== null && $jsonOut !== '') {
    $jsonOut = recipeCliAssertOutputPathSafe(
        $jsonOut,
        $databasePath
    );
}

$db = new PDO('sqlite:' . $databasePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
if ($write) {
    recipeSchemaMigrate($db);
} else {
    $db->exec('PRAGMA query_only=ON');
    $tableExists = (int)$db->query("
        SELECT COUNT(*)
        FROM sqlite_master
        WHERE type = 'table'
          AND name = 'recipe_ingredient_feedback_events'
    ")->fetchColumn() > 0;
    if (!$tableExists) {
        echo json_encode([
            'schema_version' =>
                'recipe-ingredient-feedback-export-v1',
            'proposal_only' => true,
            'automatic_application' => false,
            'generated_at' => gmdate('c'),
            'eligible_count' => 0,
            'stale_count' => 0,
            'stale_event_ids' => [],
            'events' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
}

$rows = $db->query("
    SELECT event.*
    FROM recipe_ingredient_feedback_events event
    JOIN (
        SELECT recipe_id, ingredient_key, target_kind, MAX(id) AS id
        FROM recipe_ingredient_feedback_events
        WHERE event_type = 'identity'
        GROUP BY recipe_id, ingredient_key, target_kind
    ) latest ON latest.id = event.id
    WHERE event.event_type = 'identity'
      AND event.review_state IN ('settling', 'eligible')
      AND event.settle_after <= CURRENT_TIMESTAMP
      AND NOT EXISTS (
          SELECT 1
          FROM recipe_ingredient_feedback_events superseder
          WHERE superseder.supersedes_event_id = event.id
      )
    ORDER BY event.id
")->fetchAll(PDO::FETCH_ASSOC);

$eligible = [];
$stale = [];
foreach ($rows as $row) {
    $detail = recipeCatalogDetailBuild(
        $db,
        (int)$row['recipe_id'],
        false,
        'active',
        false
    );
    $ingredient = null;
    foreach ($detail['ingredients'] ?? [] as $candidate) {
        if (
            (string)$candidate['key']
                === (string)$row['ingredient_key']
            && (int)$candidate['position']
                === (int)$row['position']
        ) {
            $ingredient = $candidate;
            break;
        }
    }
    $targetMatches = false;
    if ($ingredient !== null) {
        if (
            (string)$row['target_kind'] === 'inventory_product'
            || (string)($row['decision_action'] ?? '')
                === 'select_inventory_product'
        ) {
            $targetMatches = (int)(
                $ingredient['user_override']['selected_product']['id']
                    ?? 0
            ) === (int)($row['target_product_id'] ?? 0);
        } elseif ((string)$row['target_kind'] === 'matched_product') {
            $targetMatches = (int)(
                $ingredient['inventory']['matched_product']['id']
                    ?? 0
            ) === (int)($row['target_product_id'] ?? 0);
        } elseif ((string)$row['target_kind'] === 'closest_match') {
            $targetMatches = hash_equals(
                (string)($row['target_label'] ?? ''),
                (string)($ingredient['closest_match']['label'] ?? '')
            );
        }
    }
    if (
        $ingredient === null
        || !$targetMatches
        || !hash_equals(
            (string)$row['source_text_hash'],
            recipeIngredientFeedbackSourceHash($ingredient)
        )
    ) {
        $stale[] = (int)$row['id'];
        continue;
    }
    $eligible[] = [
        'event_id' => (int)$row['id'],
        'recipe_id' => (int)$row['recipe_id'],
        'ingredient_key' => (string)$row['ingredient_key'],
        'position' => (int)$row['position'],
        'verdict' => (string)$row['identity_verdict'],
        'target_kind' => (string)$row['target_kind'],
        'target_product_id' =>
            $row['target_product_id'] !== null
                ? (int)$row['target_product_id']
                : null,
        'target_label' => (string)$row['target_label'],
        'source_text_hash' => (string)$row['source_text_hash'],
        'observed_state' => (string)$row['observed_state'],
        'observed_relation' => $row['observed_relation'],
        'observed_confidence' =>
            (float)$row['observed_confidence'],
        'observed_product_id' =>
            $row['observed_product_id'] !== null
                ? (int)$row['observed_product_id']
                : null,
        'observed_closest_label' =>
            $row['observed_closest_label'],
        'observed_mapping_source' =>
            $row['observed_mapping_source'],
        'score_revision_id' =>
            $row['score_revision_id'] !== null
                ? (int)$row['score_revision_id']
                : null,
        'ontology_version_id' =>
            $row['ontology_version_id'] !== null
                ? (int)$row['ontology_version_id']
                : null,
        'created_at' => $row['created_at'],
    ];
}

$payload = [
    'schema_version' =>
        'recipe-ingredient-feedback-export-v1',
    'proposal_only' => true,
    'automatic_application' => false,
    'detector_version' =>
        RECIPE_INGREDIENT_FEEDBACK_CAPABILITY,
    'generated_at' => gmdate('c'),
    'eligible_count' => count($eligible),
    'stale_count' => count($stale),
    'stale_event_ids' => $stale,
    'events' => $eligible,
];

$encoded = json_encode(
    $payload,
    JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
);
if (!is_string($encoded)) {
    throw new RuntimeException(
        'feedback export could not be encoded'
    );
}
if ($jsonOut !== null && $jsonOut !== '') {
    recipeCliWriteFileAtomically(
        $jsonOut,
        $encoded . PHP_EOL
    );
}
if ($markExported && $eligible) {
    $db->beginTransaction();
    try {
        $update = $db->prepare("
            UPDATE recipe_ingredient_feedback_events
            SET review_state = 'exported'
            WHERE id = ? AND review_state IN ('settling', 'eligible')
        ");
        foreach ($eligible as $event) {
            $update->execute([(int)$event['event_id']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
echo $encoded . PHP_EOL;
