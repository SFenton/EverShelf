#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$GLOBALS['RECIPE_COOKIDOO_CONFIG'] = [
    'COOKIDOO_BRIDGE_URL' => 'http://cookidoo-bridge:8081',
    'COOKIDOO_BRIDGE_TOKEN' => 'stale-test-token',
    'COOKIDOO_CONNECTOR_ENABLED' => 'true',
    'COOKIDOO_DETAIL_HYDRATION_ENABLED' => 'true',
    'COOKIDOO_METADATA_BACKFILL_ENABLED' => 'false',
    'COOKIDOO_METADATA_REFRESH_DAYS' => '14',
];
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
$path = dirname(__DIR__) . '/data/.recipe-stale-revalidate-'
    . getmypid() . '.sqlite';
@unlink($path);
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
migrateDB($db);
$cursorAfterMigration = recipeScoreState($db)['cursor_revision'];
recipeSchemaMigrate($db);
$assert(
    (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_schema_migrations
        WHERE migration_key = 'recipe_stale_while_revalidate_v1'
    ")->fetchColumn() === 1
    && recipeScoreState($db)['cursor_revision']
        === $cursorAfterMigration,
    'Stale catalog migration must bump cursor semantics exactly once'
);

$recipe = recipeCatalogSaveVariant($db, [
    'title' => 'Stale cached soup',
    'source_ingredients' => [[
        'name' => 'Tomato',
        'source_quantity' => 1,
        'source_unit' => 'piece',
        'source_amount_text' => '1 piece',
    ]],
], [
    'connector' => 'cookidoo',
    'external_id' => 'stale-cached-soup',
    'canonical_url' =>
        'https://cookidoo.co.uk/recipes/recipe/en-GB/stale-cached-soup',
    'locale' => 'en-GB',
]);
$recipeId = (int)$recipe['id'];
$db->prepare("
    UPDATE recipe_catalog
    SET stale_at = datetime('now', '-1 day'),
        cache_expires_at = datetime('now', '-2 days')
    WHERE id = ?
")->execute([$recipeId]);
$originId = (int)$db->query("
    SELECT id
    FROM recipe_origins
    WHERE recipe_id = {$recipeId}
      AND connector = 'cookidoo'
")->fetchColumn();
$db->prepare("
    UPDATE recipe_origins
    SET metadata_version = ?,
        metadata_schema_version = ?
    WHERE id = ?
")->execute([
    RECIPE_COOKIDOO_METADATA_VERSION,
    RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
    $originId,
]);
$cached = recipeCatalogGetById($db, $recipeId);
$detail = recipeCatalogDetail($db, $recipeId);
$search = recipeCatalogTextSearch(
    $db,
    'stale cached soup',
    'cookidoo',
    20,
    0
);
$suggestions = recipeCatalogSuggestionIds($db, 'cookidoo');
$candidateIds = array_column(
    recipeCookidooMetadataBackfillCandidates(
        $db,
        'en-GB',
        0,
        20
    ),
    'origin_id'
);
$backfillStatus = recipeCookidooMetadataBackfillStatus($db, 'en-GB');
$assert(
    !empty($cached['is_stale'])
    && !empty($detail['freshness']['is_stale'])
    && $search['total'] === 1
    && in_array($recipeId, $suggestions, true)
    && !in_array($originId, $candidateIds, true)
    && $backfillStatus['origins']['current'] === 1
    && $backfillStatus['origins']['refresh_due'] === 1
    && $backfillStatus['origins']['remaining'] === 0,
    'Stale metadata-v2 rows must remain visible without reopening bulk backfill'
);

$_GET = ['id' => (string)$recipeId];
ob_start();
recipeCatalogApiGet($db);
$getPayload = json_decode(
    (string)ob_get_clean(),
    true,
    64,
    JSON_THROW_ON_ERROR
);
$demandJob = $db->query("
    SELECT *
    FROM recipe_jobs
    WHERE job_type = 'recipe_metadata_refresh'
    ORDER BY id DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
$assert(
    !empty($getPayload['success'])
    && $demandJob !== false
    && (string)$demandJob['status'] === 'pending'
    && !recipeCookidooMetadataBackfillConfigured(),
    'Cached get did not enqueue demand refresh independently of bulk backfill'
);

$db->exec("DELETE FROM recipe_jobs");
$GLOBALS['RECIPE_COOKIDOO_CONFIG'][
    'COOKIDOO_DETAIL_HYDRATION_ENABLED'
] = 'false';
ob_start();
recipeCatalogApiDetail($db);
$disabledPayload = json_decode(
    (string)ob_get_clean(),
    true,
    64,
    JSON_THROW_ON_ERROR
);
$assert(
    !empty($disabledPayload['success'])
    && (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_jobs
        WHERE job_type = 'recipe_metadata_refresh'
    ")->fetchColumn() === 0,
    'Disabled detail hydration did not suppress demand refresh'
);

$GLOBALS['RECIPE_COOKIDOO_CONFIG'][
    'COOKIDOO_DETAIL_HYDRATION_ENABLED'
] = 'true';
$db->exec("
    CREATE TRIGGER fail_recipe_demand_enqueue
    BEFORE INSERT ON recipe_jobs
    WHEN NEW.job_type = 'recipe_metadata_refresh'
    BEGIN
        SELECT RAISE(FAIL, 'synthetic demand enqueue failure');
    END
");
ob_start();
recipeCatalogApiDetail($db);
$failurePayload = json_decode(
    (string)ob_get_clean(),
    true,
    64,
    JSON_THROW_ON_ERROR
);
$db->exec("DROP TRIGGER fail_recipe_demand_enqueue");
$assert(
    !empty($failurePayload['success'])
    && (int)$failurePayload['detail']['id'] === $recipeId
    && (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_jobs
        WHERE job_type = 'recipe_metadata_refresh'
    ")->fetchColumn() === 0,
    'Demand enqueue failure failed the cached detail read'
);

$db->prepare("
    UPDATE recipe_connector_state
    SET enabled = 0
    WHERE connector = 'cookidoo'
")->execute();
$gated = recipeCookidooDemandRefresh(
    $db,
    [$recipeId],
    'recommendations'
);
$assert(
    $gated['queued'] === 0
    && $gated['reason'] === 'hydration_unavailable',
    'Connector gate did not suppress recommendation demand refresh'
);

unset($GLOBALS['RECIPE_COOKIDOO_CONFIG']);
$_GET = [];
$db = null;
@unlink($path);
@unlink($path . '-wal');
@unlink($path . '-shm');
echo 'Recipe stale-while-revalidate tests passed: '
    . $assertions . " assertions.\n";
