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
$databasePath = trim((string)($options['db'] ?? ''));
if ($databasePath === '') {
    throw new InvalidArgumentException('--db is required');
}
$databasePath = recipeCliAssertDatabaseInputSafe($databasePath, false);
$runToken = substr(
    hash('sha256', $databasePath . ':' . microtime(true)),
    0,
    12
);
$db = new PDO('sqlite:' . $databasePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA synchronous=NORMAL');
$db->exec('PRAGMA busy_timeout=10000');
ingredientOntologyV3RegisterGuardFunctions($db);
databaseEnsureMigrated(
    $db,
    $databasePath . '.migration.lock'
);

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
    return [
        'status' => http_response_code(),
        'payload' => is_array($payload) ? $payload : [],
    ];
};
$GLOBALS['CANONICAL_QUEUE_TEST_WAKE'] =
    static fn(): bool => true;
$GLOBALS['CANONICAL_QUEUE_TEST_LOCK_PATH'] =
    $databasePath . '.canonical.lock';
$GLOBALS['RECIPE_QUEUE_TEST_WAKE'] =
    static fn(): bool => true;
$GLOBALS['CANONICAL_QUEUE_TEST_PROCESSOR'] =
    static function (PDO $queueDb, int $productId): array {
        $stmt = $queueDb->prepare("
            SELECT name FROM products WHERE id = ?
        ");
        $stmt->execute([$productId]);
        $name = trim((string)$stmt->fetchColumn());
        return [
            'product_id' => $productId,
            'mapped' => 1,
            'mappings' => [[
                'slug' => canonicalIngredientSlug($name),
                'name' => canonicalIngredientTitle($name),
                'role' => 'primary',
                'confidence' => 1.0,
                'source' => 'identity_flow_fixture',
                'evidence' => 'deterministic identity flow fixture',
                'category' => 'food',
                'parent_slug' => null,
                'external_ids' => [],
            ]],
            'tags' => [],
            'decision' => 'identity_flow_fixture',
            'decision_detail' => [],
            '_apply_canonical' => true,
            '_product_exists' => true,
        ];
    };

$active = recipeScoreActiveRevision($db);
if (
    $active === null
    || $active['ontology_version_id'] === null
    || (string)$active['scoring_model'] !== 'faceted-ontology-v3'
) {
    throw new RuntimeException(
        'Pecorino flow requires an active ontology v3 score'
    );
}
$versionId = (int)$active['ontology_version_id'];
$entityBySlug = $db->prepare("
    SELECT id
    FROM ingredient_ontology_entities
    WHERE ontology_version_id = ?
      AND slug = ?
      AND active = 1
");
$entityBySlug->execute([$versionId, 'pecorino']);
$pecorinoEntityId = (int)($entityBySlug->fetchColumn() ?: 0);
$entityBySlug->execute([$versionId, 'pecorino-romano']);
$romanoEntityId = (int)($entityBySlug->fetchColumn() ?: 0);
$entityBySlug->execute([$versionId, 'cheese']);
$cheeseEntityId = (int)($entityBySlug->fetchColumn() ?: 0);
$assert(
    $pecorinoEntityId > 0
    && $romanoEntityId > 0
    && $cheeseEntityId > 0,
    'Reviewed Pecorino identities are unavailable'
);

$target = $db->prepare("
    SELECT ingredient.id AS ingredient_id,
           ingredient.recipe_id,
           ingredient.normalized_name,
           recipe.language,
           origin.content_language,
           origin.locale
    FROM recipe_ingredients ingredient
    JOIN recipe_catalog recipe
      ON recipe.id = ingredient.recipe_id
     AND recipe.deleted_at IS NULL
    JOIN recipe_origins origin
      ON origin.recipe_id = recipe.id
     AND origin.connector = recipe.primary_connector
    WHERE recipe.primary_connector = 'cookidoo'
      AND ingredient.normalized_name = ?
      AND recipe.language = 'und'
      AND origin.content_language = 'en'
    ORDER BY ingredient.recipe_id, ingredient.id
    LIMIT 1
");
$target->execute(['pecorino cheese']);
$genericTarget = $target->fetch(PDO::FETCH_ASSOC) ?: [];
$target->execute(['pecorino romano']);
$romanoTarget = $target->fetch(PDO::FETCH_ASSOC) ?: [];
$assert(
    (int)($genericTarget['ingredient_id'] ?? 0) > 0
    && (int)($romanoTarget['ingredient_id'] ?? 0) > 0,
    'Trusted-language Pecorino recipe fixtures are unavailable'
);

$db->exec("
    UPDATE inventory
    SET quantity = 0,
        updated_at = CURRENT_TIMESTAMP
    WHERE product_id IN (
        SELECT id
        FROM products
        WHERE lower(trim(name)) IN (
            'pecorino', 'pecorino cheese',
            'pecorino romano', 'cheese',
            'pecorino sauce'
        )
    )
");
while (
    (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_pending_products
    ")->fetchColumn() > 0
) {
    $settled = ingredientOntologyV3IncrementalRebuild(
        $db,
        true,
        250
    );
    if (
        empty($settled['rebuilt'])
        && (string)($settled['reason'] ?? '') !==
            'no_pending_changes'
    ) {
        throw new RuntimeException(
            'Could not settle pre-existing Pecorino inventory'
        );
    }
}

$settle = static function (PDO $db): array {
    $started = hrtime(true);
    $canonicalCycles = 0;
    $jobCycles = 0;
    $scoreCycles = 0;
    for ($cycle = 0; $cycle < 100; $cycle++) {
        $canonical = canonicalIngredientDrainQueue(
            $db,
            20,
            3,
            20
        );
        $canonicalCycles += (int)($canonical['processed'] ?? 0);
        if (recipeJobLocalWorkDue($db, 20)) {
            $jobs = recipeJobProcessQueue(
                $db,
                20,
                20,
                false,
                'local'
            );
            $jobCycles += (int)($jobs['processed'] ?? 0);
        }
        $pendingScores = (int)$db->query("
            SELECT (
                SELECT COUNT(*)
                FROM recipe_score_pending_products
            ) + (
                SELECT COUNT(*)
                FROM recipe_score_pending_recipes
                WHERE lane = 'serving'
            )
        ")->fetchColumn();
        if ($pendingScores > 0) {
            $score = ingredientOntologyV3IncrementalRebuild(
                $db,
                true,
                250
            );
            if (!empty($score['rebuilt'])) {
                $scoreCycles++;
            } elseif (!in_array(
                (string)($score['reason'] ?? ''),
                ['no_pending_changes', 'coalescing'],
                true
            )) {
                throw new RuntimeException(
                    'Pecorino score settlement failed: '
                        . ingredientOntologyV3Json($score)
                );
            }
        }
        $open = (int)$db->query("
            SELECT (
                SELECT COUNT(*)
                FROM canonical_processing_queue
                WHERE status IN ('pending', 'in_progress')
            ) + (
                SELECT COUNT(*)
                FROM recipe_jobs
                WHERE status IN ('pending', 'retry', 'in_progress')
                  AND (connector IS NULL OR connector <> 'cookidoo')
            ) + (
                SELECT COUNT(*)
                FROM recipe_score_pending_products
            ) + (
                SELECT COUNT(*)
                FROM recipe_score_pending_recipes
                WHERE lane = 'serving'
            )
        ")->fetchColumn();
        if ($open === 0) {
            return [
                'elapsed_ms' =>
                    (hrtime(true) - $started) / 1000000,
                'canonical_processed' => $canonicalCycles,
                'jobs_processed' => $jobCycles,
                'score_cycles' => $scoreCycles,
            ];
        }
        usleep(20000);
    }
    throw new RuntimeException(
        'Pecorino identity flow did not settle'
    );
};

$createProduct = static function (
    PDO $db,
    string $name,
    bool $preparedFood,
    int $index
) use ($capture, $runToken): array {
    $barcode = '29' . substr(
        sprintf(
            '%010u',
            crc32($runToken . ':' . $name . ':' . $index)
        ),
        0,
        11
    );
    $GLOBALS['PRODUCT_API_JSON_INPUT'] = [
        'barcode' => $barcode,
        'name' => $name,
        'brand' => 'EverShelf Identity Gate',
        'category' => 'food',
        'unit' => 'pz',
        'default_quantity' => 1,
        'prepared_food' => $preparedFood,
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
            'Pecorino flow product save failed: '
                . recipeCatalogJsonEncode($saved)
        );
    }
    $GLOBALS['INVENTORY_ADD_INPUT'] = [
        'idempotency_key' =>
            'pecorino-flow-' . $runToken . '-' . $index,
        'product_id' => $productId,
        'quantity' => 1,
        'location' => 'frigo',
        'expiry_date' => (new DateTimeImmutable('today'))
            ->modify('+7 days')
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
            'Pecorino flow inventory add failed: '
                . recipeCatalogJsonEncode($added)
        );
    }
    return [
        'product_id' => $productId,
        'inventory_id' =>
            (int)$added['payload']['inventory_id'],
    ];
};

$productIdentity = static function (
    PDO $db,
    int $productId
): array {
    $stmt = $db->prepare("
        SELECT annex.status, annex.language,
               annex.entity_id, annex.extension_entity_id,
               entity.slug AS entity_slug,
               readiness.status AS readiness_status,
               readiness.score_revision_id,
               readiness.visible_ms
        FROM ingredient_ontology_identity_annex annex
        LEFT JOIN ingredient_ontology_entities entity
          ON entity.id = annex.entity_id
        LEFT JOIN ingredient_ontology_product_readiness readiness
          ON readiness.product_id = annex.product_id
        WHERE annex.product_id = ?
    ");
    $stmt->execute([$productId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$effectiveMatch = static function (
    PDO $db,
    int $ingredientId
): array {
    $stmt = $db->prepare("
        SELECT match.outcome, match.satisfies_required,
               match.inventory_product_id,
               match.relationship, match.confidence
        FROM recipe_ingredients ingredient
        JOIN recipe_score_effective_sources source
          ON source.recipe_id = ingredient.recipe_id
        JOIN ingredient_ontology_shadow_matches match
          ON match.score_revision_id = source.score_revision_id
         AND match.recipe_ingredient_id = ingredient.id
        WHERE ingredient.id = ?
    ");
    $stmt->execute([$ingredientId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$generic = $createProduct($db, 'Pecorino Cheese', false, 1);
$genericSettle = $settle($db);
$genericIdentity = $productIdentity(
    $db,
    (int)$generic['product_id']
);
$genericExact = $effectiveMatch(
    $db,
    (int)$genericTarget['ingredient_id']
);
$genericVariant = $effectiveMatch(
    $db,
    (int)$romanoTarget['ingredient_id']
);
$genericTargetAnnex = $db->prepare("
    SELECT COALESCE(annex.language, mapping.language) AS language,
           COALESCE(annex.entity_id, mapping.entity_id) AS entity_id,
           annex.extension_entity_id,
           COALESCE(annex.status, mapping.status) AS status
    FROM recipe_ingredients ingredient
    LEFT JOIN ingredient_ontology_recipe_identity_annex annex
      ON annex.recipe_ingredient_id = ingredient.id
    LEFT JOIN ingredient_ontology_mappings mapping
      ON mapping.ontology_version_id = ?
     AND mapping.owner_type = 'recipe_ingredient'
     AND mapping.owner_id = ingredient.id
    WHERE ingredient.id = ?
");
$genericTargetAnnex->execute([
    $versionId,
    (int)$genericTarget['ingredient_id'],
]);
$genericTargetAnnex = $genericTargetAnnex->fetch(PDO::FETCH_ASSOC) ?: [];
$assert(
    (string)($genericIdentity['status'] ?? '') === 'accepted'
    && (string)($genericIdentity['readiness_status'] ?? '') === 'ready'
    && (int)($genericIdentity['entity_id'] ?? 0)
        === $pecorinoEntityId
    && $genericIdentity['extension_entity_id'] === null
    && (string)($genericTargetAnnex['language'] ?? '') === 'en'
    && (int)($genericTargetAnnex['entity_id'] ?? 0)
        === $pecorinoEntityId
    && $genericTargetAnnex['extension_entity_id'] === null
    && (string)($genericExact['outcome'] ?? '') === 'exact'
    && !empty($genericExact['satisfies_required'])
    && (int)($genericExact['inventory_product_id'] ?? 0)
        === (int)$generic['product_id']
    && (string)($genericVariant['outcome'] ?? '')
        === 'compatible_variant'
    && empty($genericVariant['satisfies_required'])
    && (int)($genericVariant['inventory_product_id'] ?? 0)
        === (int)$generic['product_id'],
    'Generic Pecorino must satisfy generic wording and remain a '
        . 'non-satisfying Romano variant: '
        . ingredientOntologyV3Json([
            'identity' => $genericIdentity,
            'target_identity' => $genericTargetAnnex,
            'generic_match' => $genericExact,
            'romano_match' => $genericVariant,
        ])
);

$romano = $createProduct($db, 'Pecorino Romano', false, 2);
$romanoSettle = $settle($db);
$romanoIdentity = $productIdentity(
    $db,
    (int)$romano['product_id']
);
$romanoExact = $effectiveMatch(
    $db,
    (int)$romanoTarget['ingredient_id']
);
$assert(
    (string)($romanoIdentity['status'] ?? '') === 'accepted'
    && (string)($romanoIdentity['readiness_status'] ?? '') === 'ready'
    && (int)($romanoIdentity['entity_id'] ?? 0)
        === $romanoEntityId
    && $romanoIdentity['extension_entity_id'] === null
    && (string)($romanoExact['outcome'] ?? '') === 'exact'
    && !empty($romanoExact['satisfies_required'])
    && (int)($romanoExact['inventory_product_id'] ?? 0)
        === (int)$romano['product_id'],
    'Pecorino Romano must retain its distinct exact identity: '
        . ingredientOntologyV3Json([
            'identity' => $romanoIdentity,
            'match' => $romanoExact,
        ])
);

$db->prepare("
    UPDATE inventory
    SET quantity = 0,
        updated_at = CURRENT_TIMESTAMP
    WHERE product_id IN (?, ?)
")->execute([
    (int)$generic['product_id'],
    (int)$romano['product_id'],
]);
recipeScoreMarkProductDirty(
    $db,
    (int)$generic['product_id'],
    'pecorino_flow_isolate_cheese'
);
recipeScoreMarkProductDirty(
    $db,
    (int)$romano['product_id'],
    'pecorino_flow_isolate_cheese'
);
$settle($db);

$cheese = $createProduct($db, 'Cheese', false, 3);
$cheeseSettle = $settle($db);
$cheeseIdentity = $productIdentity(
    $db,
    (int)$cheese['product_id']
);
$cheeseGenericMatch = $effectiveMatch(
    $db,
    (int)$genericTarget['ingredient_id']
);
$cheeseSemanticContributor = $db->prepare("
    SELECT COUNT(*)
    FROM recipe_score_match_contributors contributor
    JOIN recipe_score_effective_sources source
      ON source.recipe_id = contributor.recipe_id
     AND source.score_revision_id =
         contributor.score_revision_id
    WHERE contributor.product_id = ?
      AND contributor.recipe_ingredient_id = ?
      AND contributor.semantic = 1
");
$cheeseSemanticContributor->execute([
    (int)$cheese['product_id'],
    (int)$genericTarget['ingredient_id'],
]);
$db->prepare("
    UPDATE inventory
    SET quantity = 0,
        updated_at = CURRENT_TIMESTAMP
    WHERE product_id = ?
")->execute([(int)$cheese['product_id']]);
recipeScoreMarkProductDirty(
    $db,
    (int)$cheese['product_id'],
    'pecorino_flow_isolate_prepared'
);
$settle($db);

$prepared = $createProduct($db, 'Pecorino Sauce', true, 4);
$preparedSettle = $settle($db);
$preparedIdentity = $productIdentity(
    $db,
    (int)$prepared['product_id']
);
$negativeContributors = $db->prepare("
    SELECT COUNT(*)
    FROM recipe_score_match_contributors contributor
    JOIN recipe_score_effective_sources source
      ON source.recipe_id = contributor.recipe_id
     AND source.score_revision_id =
         contributor.score_revision_id
    WHERE contributor.product_id IN (?, ?)
      AND contributor.recipe_ingredient_id IN (?, ?)
      AND contributor.semantic = 1
");
$negativeContributors->execute([
    (int)$cheese['product_id'],
    (int)$prepared['product_id'],
    (int)$genericTarget['ingredient_id'],
    (int)$romanoTarget['ingredient_id'],
]);
$assert(
    (int)($cheeseIdentity['entity_id'] ?? 0) === $cheeseEntityId
    && (int)$cheeseSemanticContributor->fetchColumn() === 0
    && (string)($preparedIdentity['readiness_status'] ?? '')
        === 'non_satisfying'
    && (int)$negativeContributors->fetchColumn() === 0,
    'Bare cheese and prepared Pecorino sauce must not satisfy '
        . 'Pecorino identities'
);

$maxElapsedMs = max(
    (float)$genericSettle['elapsed_ms'],
    (float)$romanoSettle['elapsed_ms'],
    (float)$cheeseSettle['elapsed_ms'],
    (float)$preparedSettle['elapsed_ms']
);
$assert(
    $maxElapsedMs < 10000,
    'Every targeted identity flow must settle within 10 seconds'
);

$report = [
    'success' => true,
    'assertions' => $assertions,
    'ontology_version_id' => $versionId,
    'targets' => [
        'generic' => $genericTarget,
        'romano' => $romanoTarget,
    ],
    'products' => [
        'generic' => $generic + [
            'identity' => $genericIdentity,
            'settle' => $genericSettle,
            'generic_match' => $genericExact,
            'romano_match' => $genericVariant,
        ],
        'romano' => $romano + [
            'identity' => $romanoIdentity,
            'settle' => $romanoSettle,
            'romano_match' => $romanoExact,
        ],
        'cheese' => $cheese + [
            'identity' => $cheeseIdentity,
            'settle' => $cheeseSettle,
            'generic_match' => $cheeseGenericMatch,
        ],
        'prepared' => $prepared + [
            'identity' => $preparedIdentity,
            'settle' => $preparedSettle,
        ],
    ],
    'maximum_settle_ms' => round($maxElapsedMs, 3),
];
echo json_encode(
    $report,
    JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
) . PHP_EOL;
