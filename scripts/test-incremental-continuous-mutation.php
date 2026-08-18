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
$databasePath = trim((string)($options['db'] ?? ''));
$productId = (int)($options['product-id'] ?? 0);
if ($databasePath === '' || $productId <= 0) {
    throw new InvalidArgumentException(
        '--db and --product-id are required'
    );
}
$databasePath = recipeCliAssertDatabaseInputSafe(
    $databasePath,
    isset($options['allow-active-db'])
);
$db = new PDO('sqlite:' . $databasePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA busy_timeout=10000');
ingredientOntologyV3RegisterGuardFunctions($db);
ingredientOntologyV3SchemaMigrate($db);
recipeSchemaMigrate($db);

$inventory = $db->prepare("
    SELECT id, quantity
    FROM inventory
    WHERE product_id = ?
    ORDER BY id
");
$inventory->execute([$productId]);
$rows = $inventory->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    throw new RuntimeException(
        'continuous mutation fixture requires inventory'
    );
}
$inventoryId = (int)$rows[0]['id'];
$originalQuantity = (float)$rows[0]['quantity'];
$firstQuantity = $originalQuantity + 1;
$secondQuantity = $originalQuantity + 2;
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
$mutate = static function (
    PDO $db,
    int $inventoryId,
    int $productId,
    float $quantity,
    string $reason
): int {
    $db->beginTransaction();
    try {
        $db->prepare("
            UPDATE inventory
            SET quantity = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$quantity, $inventoryId]);
        $revision = recipeScoreMarkProductDirty(
            $db,
            $productId,
            $reason
        );
        $db->commit();
        return $revision;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
};

$firstMutationRevision = $mutate(
    $db,
    $inventoryId,
    $productId,
    $firstQuantity,
    'continuous_mutation_first'
);
$secondaryProductId = 203;
$secondaryExists = $db->prepare("
    SELECT 1 FROM products WHERE id = ?
");
$secondaryExists->execute([$secondaryProductId]);
if (!$secondaryExists->fetchColumn()) {
    $secondaryProductId = $productId;
}
$preSnapshotRevision = 0;
$GLOBALS['INGREDIENT_ONTOLOGY_V3_BEFORE_INCREMENTAL_SNAPSHOT'] =
    static function (PDO $hookDb) use (
        $secondaryProductId,
        &$preSnapshotRevision
    ): void {
        $preSnapshotRevision = recipeScoreMarkProductDirty(
            $hookDb,
            $secondaryProductId,
            'continuous_mutation_before_snapshot'
        );
    };
$hookRevision = 0;
$observedProgress = null;
$GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_INCREMENTAL_SNAPSHOT'] =
    static function (
        PDO $hookDb,
        int $parentRevisionId,
        int $snapshotInventoryRevision
    ) use (
        $mutate,
        $inventoryId,
        $productId,
        $secondQuantity,
        &$hookRevision
    ): void {
        $hookRevision = $mutate(
            $hookDb,
            $inventoryId,
            $productId,
            $secondQuantity,
            'continuous_mutation_during_build'
        );
        if ($hookRevision <= $snapshotInventoryRevision) {
            throw new RuntimeException(
                'concurrent mutation did not advance the high-water mark'
            );
        }
    };
$GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_SCORE_BATCH'] =
    static function (
        PDO $hookDb
    ) use (&$observedProgress): void {
        if ($observedProgress === null) {
            $observedProgress =
                evershelfProcessingStatusIncrementalScores(
                    $hookDb
                );
        }
    };
$first = ingredientOntologyV3IncrementalRebuild(
    $db,
    true,
    100
);
unset($GLOBALS['INGREDIENT_ONTOLOGY_V3_BEFORE_INCREMENTAL_SNAPSHOT']);
unset($GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_INCREMENTAL_SNAPSHOT']);
unset($GLOBALS['INGREDIENT_ONTOLOGY_V3_AFTER_SCORE_BATCH']);
$stateAfterFirst = recipeScoreState($db);
$pendingAfterFirst = $db->prepare("
    SELECT first_inventory_revision, latest_inventory_revision
    FROM recipe_score_pending_products
    WHERE product_id = ?
");
$pendingAfterFirst->execute([$productId]);
$pendingRow = $pendingAfterFirst->fetch(PDO::FETCH_ASSOC) ?: null;
$assert(
    !empty($first['rebuilt'])
    && $preSnapshotRevision > $firstMutationRevision
    && (int)$first['inventory_revision'] === $preSnapshotRevision
    && (int)$first['current_inventory_revision'] === $hookRevision
    && in_array($secondaryProductId, $first['product_ids'], true)
    && (int)$stateAfterFirst['active_score_revision_id']
        === (int)$first['revision_id']
    && (int)$stateAfterFirst['active_score_projection_revision_id']
        === (int)$first['revision_id']
    && $pendingRow !== null
    && (int)$pendingRow['latest_inventory_revision']
        === $hookRevision
    && is_array($observedProgress)
    && (string)$observedProgress['phase'] === 'scoring'
    && (int)$observedProgress['processed_recipe_count'] > 0
    && (int)$observedProgress['processed_recipe_count']
        < (int)$observedProgress['total_recipe_count'],
    'A scored prefix must publish while a newer mutation remains pending'
);

$second = ingredientOntologyV3IncrementalRebuild($db, true, 100);
$stateAfterSecond = recipeScoreState($db);
$assert(
    !empty($second['rebuilt'])
    && (int)$second['parent_revision_id']
        === (int)$first['revision_id']
    && (int)$second['inventory_revision'] === $hookRevision
    && (int)$second['current_inventory_revision'] === $hookRevision
    && (int)$second['pending_product_count'] === 0
    && (int)$stateAfterSecond['active_score_revision_id']
        === (int)$second['revision_id'],
    'The next sparse child must consume the newer pending mutation'
);

$restoreRevision = $mutate(
    $db,
    $inventoryId,
    $productId,
    $originalQuantity,
    'continuous_mutation_restore'
);
$restored = ingredientOntologyV3IncrementalRebuild($db, true, 100);
$assert(
    !empty($restored['rebuilt'])
    && (int)$restored['inventory_revision'] === $restoreRevision
    && (int)$restored['pending_product_count'] === 0
    && abs(
        (float)$db->query("
            SELECT quantity FROM inventory
            WHERE id = {$inventoryId}
        ")->fetchColumn() - $originalQuantity
    ) < 0.000001,
    'Continuous mutation harness must restore its inventory fixture'
);

echo json_encode([
    'success' => true,
    'assertions' => $assertions,
    'product_id' => $productId,
    'observed_progress' => $observedProgress,
    'first' => $first,
    'second' => $second,
    'restored' => $restored,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
