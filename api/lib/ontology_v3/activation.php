<?php
declare(strict_types=1);

const INGREDIENT_ONTOLOGY_ACTIVATION_SCHEMA_VERSION =
    'ontology-activation-v3';
const INGREDIENT_ONTOLOGY_ACTIVATION_TRIGGER_VERSION =
    'ontology-activation-cdc-v2';
const INGREDIENT_ONTOLOGY_ACTIVATION_BUNDLE_VERSION =
    'ontology-activation-bundle-v2';
const INGREDIENT_ONTOLOGY_ACTIVATION_ACKNOWLEDGEMENT_VERSION =
    'ontology-activation-acknowledgement-v2';
const INGREDIENT_ONTOLOGY_ACTIVATION_LIVE_BUSY_TIMEOUT_MS = 2500;
const INGREDIENT_ONTOLOGY_ACTIVATION_EXPECTED_RETRY_LIMIT = 3;

function ingredientOntologyActivationConfigureDatabase(
    PDO $db,
    int $busyTimeoutMs = 10000
): void {
    $busyTimeoutMs = max(1, min(60000, $busyTimeoutMs));
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA temp_store = FILE');
    $db->exec('PRAGMA busy_timeout = ' . $busyTimeoutMs);
}

function ingredientOntologyActivationCorpusDrifted(
    PDO $db,
    ?array $active = null
): bool {
    $active ??= ingredientOntologyV3ActiveVersion($db);
    if ($active === null) {
        return false;
    }
    $sealed = trim((string)($active['corpus_hash'] ?? ''));
    $current = ingredientOntologyV3CorpusHash($db);
    return strlen($sealed) !== 64
        || strlen($current) !== 64
        || !hash_equals($sealed, $current);
}

function ingredientOntologyActivationProductDriftHandledByAnnex(
    PDO $db,
    ?array $activeScore = null
): bool {
    $activeScore ??= recipeScoreActiveRevision($db);
    if (
        $activeScore === null
        || $activeScore['ontology_version_id'] === null
    ) {
        return false;
    }
    $state = recipeScoreState($db);
    $activeScoreId = (int)$activeScore['id'];
    $sourceRevision = (int)$state['ontology_source_revision'];
    if (
        (int)$activeScore['ontology_source_revision']
            !== $sourceRevision
    ) {
        $from = (int)$activeScore['ontology_source_revision'];
        $through = $sourceRevision;
        if ($through <= $from) {
            return false;
        }
        $mutations = $db->prepare("
            SELECT owner_type, owner_id
            FROM recipe_score_mutations
            WHERE domain = 'source'
              AND revision > ?
              AND revision <= ?
            ORDER BY revision
        ");
        $mutations->execute([$from, $through]);
        $rows = $mutations->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== $through - $from) {
            return false;
        }
        $version = ingredientOntologyV3Version(
            $db,
            (int)$activeScore['ontology_version_id']
        );
        if ($version === null || (string)$version['status'] !== 'ready') {
            return false;
        }
        $productIds = [];
        foreach ($rows as $row) {
            if ((string)$row['owner_type'] !== 'product') {
                return false;
            }
            $productIds[(int)$row['owner_id']] = true;
        }
        $product = $db->prepare("
            SELECT id, name, brand, category, prepared_food
            FROM products
            WHERE id = ?
        ");
        $annex = $db->prepare("
            SELECT ontology_version_id, ontology_content_hash,
                   ontology_seal_hash, owner_fingerprint,
                   resolver_version, review_manifest_hash
            FROM ingredient_ontology_identity_annex
            WHERE product_id = ?
        ");
        foreach (array_keys($productIds) as $productId) {
            $product->execute([$productId]);
            $productRow = $product->fetch(PDO::FETCH_ASSOC);
            if (!$productRow) {
                continue;
            }
            $annex->execute([$productId]);
            $annexRow = $annex->fetch(PDO::FETCH_ASSOC);
            if (
                !$annexRow
                || (int)$annexRow['ontology_version_id']
                    !== (int)$version['id']
                || !hash_equals(
                    (string)$version['content_hash'],
                    (string)$annexRow['ontology_content_hash']
                )
                || !hash_equals(
                    (string)$version['seal_hash'],
                    (string)$annexRow['ontology_seal_hash']
                )
                || !hash_equals(
                    ingredientOntologyV3ProductOwnerFingerprint(
                        $productRow
                    ),
                    (string)$annexRow['owner_fingerprint']
                )
                || (string)$annexRow['resolver_version']
                    !== ingredientOntologyV3ProductIdentityResolverVersion()
                || !hash_equals(
                    ingredientOntologyV3IdentityAnnexReviewManifestHash(),
                    (string)$annexRow['review_manifest_hash']
                )
            ) {
                return false;
            }
        }
        $currentActive = recipeScoreActiveRevision($db);
        $currentState = recipeScoreState($db);
        return $currentActive !== null
            && (int)$currentActive['id'] === $activeScoreId
            && (int)$currentState['ontology_source_revision']
                === $sourceRevision;
    }
    if (
        (string)(
            $activeScore['ontology_source_lineage_hash'] ?? ''
        ) === ''
    ) {
        return false;
    }
    $report = recipeScoreRevisionReport($activeScore);
    $expectedHash = (string)(
        $report['product_identity_semantic_hash'] ?? ''
    );
    if (
        (string)($report['ontology_source_scope'] ?? '')
            !== 'product_identity_annex'
        || strlen($expectedHash) !== 64
    ) {
        return false;
    }
    $currentHash = ingredientOntologyV3IdentityAnnexSemanticHash(
        $db,
        (int)$activeScore['ontology_version_id']
    );
    $currentActive = recipeScoreActiveRevision($db);
    $currentState = recipeScoreState($db);
    return strlen($currentHash) === 64
        && hash_equals($expectedHash, $currentHash)
        && $currentActive !== null
        && (int)$currentActive['id'] === $activeScoreId
        && (int)$currentState['ontology_source_revision']
            === $sourceRevision;
}

function ingredientOntologyActivationProductDriftIsIncremental(
    PDO $db,
    array $active
): bool {
    if (
        !function_exists(
            'ingredientOntologyV3IncrementalScopedParentErrors'
        )
        || !ingredientOntologyV3TableExists(
            $db,
            'recipe_score_pending_products'
        )
        || !ingredientOntologyV3TableExists(
            $db,
            'recipe_score_pending_recipes'
        )
    ) {
        return false;
    }
    if (!ingredientOntologyActivationProductDriftWithinLimit($db)) {
        return false;
    }
    $pendingProducts = $db->query("
        SELECT product_id
        FROM recipe_score_pending_products
        ORDER BY product_id
    ")->fetchAll(PDO::FETCH_COLUMN);
    $productIds = array_map('intval', $pendingProducts);
    return !ingredientOntologyV3IncrementalScopedParentErrors(
        $db,
        $active,
        recipeScoreState($db),
        $productIds,
        []
    );
}

function ingredientOntologyActivationMaintenanceDriftIsIncremental(
    PDO $db,
    array $active
): bool {
    if (
        !function_exists(
            'ingredientOntologyV3IncrementalScopedParentErrors'
        )
        || !ingredientOntologyV3TableExists(
            $db,
            'recipe_score_pending_products'
        )
        || !ingredientOntologyV3TableExists(
            $db,
            'recipe_score_pending_recipes'
        )
    ) {
        return false;
    }
    if ((int)$db->query("
        SELECT COUNT(*) FROM recipe_score_pending_products
    ")->fetchColumn() > 0) {
        return false;
    }
    $limit = ingredientOntologyV3IncrementalProductLimit();
    $recipeIds = array_map(
        'intval',
        $db->query("
            SELECT recipe_id
            FROM recipe_score_pending_recipes
            WHERE lane = 'maintenance'
            ORDER BY updated_at, recipe_id
            LIMIT " . ($limit + 1)
        )->fetchAll(PDO::FETCH_COLUMN)
    );
    if (!$recipeIds || count($recipeIds) > $limit) {
        return false;
    }
    $state = recipeScoreState($db);
    $productIds = ingredientOntologyV3IncrementalSourceProductIds(
        $db,
        $active,
        $state
    );
    return count($productIds) <= $limit
        && !ingredientOntologyV3IncrementalScopedParentErrors(
            $db,
            $active,
            $state,
            $productIds,
            $recipeIds,
            false
        );
}

function ingredientOntologyActivationProductDriftWithinLimit(
    PDO $db
): bool {
    if (
        !ingredientOntologyV3TableExists(
            $db,
            'recipe_score_pending_products'
        )
        || !ingredientOntologyV3TableExists(
            $db,
            'recipe_score_pending_recipes'
        )
    ) {
        return false;
    }
    $pendingRecipeCount = (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_pending_recipes
    ")->fetchColumn();
    $productLimit = function_exists(
        'ingredientOntologyV3IncrementalProductLimit'
    )
        ? ingredientOntologyV3IncrementalProductLimit()
        : 100;
    $pendingProducts = $db->query("
        SELECT product_id
        FROM recipe_score_pending_products
        ORDER BY product_id
        LIMIT " . ($productLimit + 1)
    )->fetchAll(PDO::FETCH_COLUMN);
    $productIds = array_map('intval', $pendingProducts);
    return $pendingRecipeCount === 0
        && $productIds
        && count($productIds) <= $productLimit;
}

function ingredientOntologyActivationNeedsReviewedManifestRefresh(
    PDO $db
): bool {
    $active = ingredientOntologyV3ActiveVersion($db);
    if ($active === null) {
        return false;
    }
    if (
        ingredientOntologyActivationCorpusDrifted($db, $active)
        && !ingredientOntologyActivationProductDriftHandledByAnnex(
            $db
        )
    ) {
        return true;
    }
    $manifest = ingredientOntologyV3ResolutionManifest();
    $stmt = $db->prepare("
        SELECT manifest_version, manifest_hash, content_hash
        FROM ingredient_ontology_resolution_manifests
        WHERE ontology_version_id = ?
          AND manifest_key = ?
    ");
    $stmt->execute([
        (int)$active['id'],
        (string)$manifest['manifest_key'],
    ]);
    $stored = $stmt->fetch(PDO::FETCH_ASSOC);
    return !$stored
        || !hash_equals(
            (string)$stored['manifest_hash'],
            (string)$manifest['manifest_hash']
        )
        || !hash_equals(
            (string)$stored['content_hash'],
            (string)$manifest['content_hash']
        )
        || (string)$stored['manifest_version']
            !== (string)$manifest['manifest_version'];
}

function ingredientOntologyActivationShouldRebuildReviewedManifest(
    PDO $db,
    array $options = []
): bool {
    $testFixture = defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && !empty($options['allow_test_fixture']);
    return !$testFixture
        && ingredientOntologyActivationNeedsReviewedManifestRefresh($db);
}

final class IngredientOntologyActivationReservationUnavailable
    extends RuntimeException
{
    private string $phase;

    public function __construct(string $phase) {
        $this->phase = $phase;
        parent::__construct('ontology_activation_background_writer_locked');
    }

    public function phase(): string {
        return $this->phase;
    }
}

final class IngredientOntologyActivationExpectedOutcome
    extends RuntimeException
{
    private string $outcomeKind;
    private array $details;

    public function __construct(
        string $outcomeKind,
        string $message,
        array $details = []
    ) {
        if (!in_array($outcomeKind, [
            'superseded_snapshot',
            'rebase_required',
        ], true)) {
            throw new InvalidArgumentException(
                'ontology activation expected outcome kind is invalid'
            );
        }
        $this->outcomeKind = $outcomeKind;
        $this->details = $details;
        parent::__construct($message);
    }

    public function outcomeKind(): string {
        return $this->outcomeKind;
    }

    public function details(): array {
        return $this->details;
    }
}

function ingredientOntologyActivationWithLiveReservation(
    array $options,
    string $phase,
    callable $operation
): mixed {
    $reservation = $options['live_reservation'] ?? null;
    if ($reservation === null) {
        return $operation();
    }
    if (!is_callable($reservation)) {
        throw new InvalidArgumentException(
            'ontology activation live reservation callback is invalid'
        );
    }
    return $reservation($phase, $operation);
}

function ingredientOntologyActivationWithNonBlockingFileLock(
    mixed $lock,
    string $phase,
    callable $operation
): mixed {
    if (!is_resource($lock)) {
        throw new InvalidArgumentException(
            'ontology activation live lock handle is invalid'
        );
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        throw new IngredientOntologyActivationReservationUnavailable(
            $phase
        );
    }
    try {
        return $operation();
    } finally {
        flock($lock, LOCK_UN);
    }
}

function ingredientOntologyActivationTableExists(
    PDO $db,
    string $table
): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM sqlite_master
        WHERE type = 'table' AND name = ?
    ");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function ingredientOntologyActivationStableJson(mixed $value): string {
    return json_encode(
        ingredientOntologyV3StableValue($value),
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR
    );
}

function ingredientOntologyActivationRecordOutcome(
    PDO $db,
    string $kind,
    array $details = [],
    bool $clearFailure = false,
    ?int $retryAfterSeconds = null
): array {
    if (!preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $kind)) {
        throw new InvalidArgumentException(
            'ontology activation outcome kind is invalid'
        );
    }
    $json = ingredientOntologyActivationStableJson($details);
    if (strlen($json) > 32768) {
        throw new InvalidArgumentException(
            'ontology activation outcome details are too large'
        );
    }
    $retryAfterSeconds = $retryAfterSeconds !== null
        ? max(1, min(3600, $retryAfterSeconds))
        : null;
    $expected = in_array($kind, [
        'superseded_snapshot',
        'rebase_required',
    ], true);
    $normalizedDetails = $details;
    foreach ([
        'candidate_score_revision_id',
        'generation_id',
        'import_id',
        'message',
    ] as $ephemeralKey) {
        unset($normalizedDetails[$ephemeralKey]);
    }
    $expectedKey = $expected
        ? ingredientOntologyV3Hash([
            'kind' => $kind,
            'details' => $normalizedDetails,
        ])
        : '';
    $current = $db->query("
        SELECT failure_count, expected_outcome_key,
               expected_outcome_count
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $expectedCount = $expected
        ? (
            hash_equals(
                (string)($current['expected_outcome_key'] ?? ''),
                $expectedKey
            )
                ? (int)($current['expected_outcome_count'] ?? 0) + 1
                : 1
        )
        : 0;
    if (
        $expected
        && $expectedCount
            > INGREDIENT_ONTOLOGY_ACTIVATION_EXPECTED_RETRY_LIMIT
    ) {
        $delay = min(
            3600,
            300 * (
                2 ** min(
                    3,
                    $expectedCount
                        - INGREDIENT_ONTOLOGY_ACTIVATION_EXPECTED_RETRY_LIMIT
                        - 1
                )
            )
        );
        $message = 'Activation convergence did not settle after '
            . $expectedCount . ' identical ' . $kind . ' outcomes.';
        $escalatedDetails = $details + [
            'expected_retry_key' => $expectedKey,
            'expected_retry_count' => $expectedCount,
            'expected_retry_limit' =>
                INGREDIENT_ONTOLOGY_ACTIVATION_EXPECTED_RETRY_LIMIT,
            'escalated' => true,
        ];
        $db->prepare("
            UPDATE ontology_activation_state
            SET expected_outcome_key = ?,
                expected_outcome_count = ?,
                last_outcome_kind =
                    'non_converging_expected_outcome',
                last_outcome_json = ?,
                last_outcome_at = CURRENT_TIMESTAMP,
                failure_count = failure_count + 1,
                last_error = ?,
                next_attempt_at = datetime(
                    'now',
                    '+' || ? || ' seconds'
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1
        ")->execute([
            $expectedKey,
            $expectedCount,
            ingredientOntologyActivationStableJson(
                $escalatedDetails
            ),
            mb_substr($message, 0, 1000, 'UTF-8'),
            $delay,
        ]);
        return [
            'kind' => 'non_converging_expected_outcome',
            'expected_retry_key' => $expectedKey,
            'expected_retry_count' => $expectedCount,
            'escalated' => true,
            'next_attempt_seconds' => $delay,
        ];
    }

    if ($expected) {
        $details['expected_retry_key'] = $expectedKey;
        $details['expected_retry_count'] = $expectedCount;
        $details['expected_retry_limit'] =
            INGREDIENT_ONTOLOGY_ACTIVATION_EXPECTED_RETRY_LIMIT;
    }
    $json = ingredientOntologyActivationStableJson($details);
    if (strlen($json) > 32768) {
        throw new InvalidArgumentException(
            'ontology activation outcome details are too large'
        );
    }
    $db->prepare("
        UPDATE ontology_activation_state
        SET last_outcome_kind = ?,
            last_outcome_json = ?,
            last_outcome_at = CURRENT_TIMESTAMP,
            expected_outcome_key = ?,
            expected_outcome_count = ?,
            failure_count = CASE WHEN ? THEN 0 ELSE failure_count END,
            last_error = CASE WHEN ? THEN '' ELSE last_error END,
            next_attempt_at = CASE
                WHEN ? IS NULL THEN
                    CASE WHEN ? THEN NULL ELSE next_attempt_at END
                ELSE datetime('now', '+' || ? || ' seconds')
            END,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ")->execute([
        $kind,
        $json,
        $expectedKey,
        $expectedCount,
        $clearFailure ? 1 : 0,
        $clearFailure ? 1 : 0,
        $retryAfterSeconds,
        $clearFailure ? 1 : 0,
        $retryAfterSeconds,
    ]);
    return [
        'kind' => $kind,
        'expected_retry_key' => $expectedKey ?: null,
        'expected_retry_count' => $expectedCount,
        'escalated' => false,
        'next_attempt_seconds' => $retryAfterSeconds,
    ];
}

function ingredientOntologyActivationRecordAdvisoryOutcome(
    PDO $db,
    string $kind,
    array $details = [],
    ?int $retryAfterSeconds = null
): array {
    if ($kind !== 'policy_deferred') {
        throw new InvalidArgumentException(
            'ontology activation advisory outcome kind is invalid'
        );
    }
    $retryAfterSeconds = $retryAfterSeconds !== null
        ? max(1, min(3600, $retryAfterSeconds))
        : null;
    $details['advisory'] = true;
    $details['policy_deferred'] = true;
    $json = ingredientOntologyActivationStableJson($details);
    if (strlen($json) > 32768) {
        throw new InvalidArgumentException(
            'ontology activation advisory outcome details are too large'
        );
    }
    $db->prepare("
        UPDATE ontology_activation_state
        SET last_outcome_kind = ?,
            last_outcome_json = ?,
            last_outcome_at = CURRENT_TIMESTAMP,
            next_attempt_at = CASE
                WHEN ? IS NULL THEN next_attempt_at
                WHEN next_attempt_at IS NULL
                  OR julianday(next_attempt_at) < julianday(
                      'now',
                      '+' || ? || ' seconds'
                  )
                THEN datetime('now', '+' || ? || ' seconds')
                ELSE next_attempt_at
            END,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ")->execute([
        $kind,
        $json,
        $retryAfterSeconds,
        $retryAfterSeconds,
        $retryAfterSeconds,
    ]);
    return [
        'kind' => $kind,
        'advisory' => true,
        'next_attempt_seconds' => $retryAfterSeconds,
    ];
}

function ingredientOntologyActivationClassifyValidationErrors(
    array $errors
): array {
    $errors = array_values(array_unique(array_map('strval', $errors)));
    if (!$errors) {
        return [
            'expected' => false,
            'drift_codes' => [],
            'outcome_kind' => null,
            'errors' => [],
        ];
    }
    $primary = [
        'inventory or catalog inputs changed after shadow build' =>
            'live_inputs_changed',
        'active score pointer changed after shadow build' =>
            'active_score_pointer_changed',
        'shadow score date is not current' =>
            'score_date_rolled_over',
        'activation score date changed' =>
            'score_date_rolled_over',
    ];
    $derivative = [
        'shadow materialization is incomplete',
    ];
    $codes = [];
    foreach ($errors as $error) {
        if (isset($primary[$error])) {
            $codes[$primary[$error]] = true;
        }
    }
    $expected = (bool)$codes;
    if ($expected) {
        foreach ($errors as $error) {
            if (!isset($primary[$error])
                && !in_array($error, $derivative, true)) {
                $expected = false;
                break;
            }
        }
    }
    return [
        'expected' => $expected,
        'drift_codes' => array_keys($codes),
        'outcome_kind' => isset($codes['score_date_rolled_over'])
            ? 'rebase_required'
            : null,
        'errors' => $errors,
    ];
}

function ingredientOntologyActivationAssertScoreValidation(
    array $validation,
    string $failurePrefix,
    string $expectedOutcomeKind,
    array $details = []
): void {
    if (!empty($validation['valid'])) {
        return;
    }
    $errors = array_values(array_map(
        'strval',
        (array)($validation['errors'] ?? [])
    ));
    $classification =
        ingredientOntologyActivationClassifyValidationErrors(
            $errors
        );
    if (!empty($classification['expected'])) {
        $outcomeKind = (string)(
            $classification['outcome_kind']
                ?? $expectedOutcomeKind
        );
        throw new IngredientOntologyActivationExpectedOutcome(
            $outcomeKind,
            $failurePrefix . ' requires a fresh score snapshot',
            $details + [
                'errors' => $errors,
                'drift_codes' => $classification['drift_codes'],
            ]
        );
    }
    throw new RuntimeException(
        $failurePrefix . ': ' . implode('; ', $errors)
    );
}

function ingredientOntologyActivationLineageUuid(PDO $db): string {
    $uuid = (string)$db->query("
        SELECT database_lineage_uuid
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetchColumn();
    if (!preg_match('/^[a-f0-9]{32}$/D', $uuid)) {
        throw new RuntimeException(
            'ontology activation database lineage is unavailable'
        );
    }
    return $uuid;
}

function ingredientOntologyActivationCdcHighWater(
    PDO $db,
    ?string $domain = null
): int {
    $column = $domain === null
        ? 'cdc_all_high_water'
        : match ($domain) {
            'source' => 'cdc_source_high_water',
            'catalog' => 'cdc_catalog_high_water',
            'inventory' => 'cdc_inventory_high_water',
            'constraint' => 'cdc_constraint_high_water',
            'policy' => 'cdc_policy_high_water',
            'evidence' => 'cdc_evidence_high_water',
            default => throw new InvalidArgumentException(
                'ontology activation CDC domain is invalid'
            ),
        };
    return (int)$db->query("
        SELECT {$column}
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetchColumn();
}

function ingredientOntologyActivationCdcSnapshot(PDO $db): array {
    $snapshot = [];
    foreach ([
        'source',
        'catalog',
        'inventory',
        'constraint',
        'policy',
        'evidence',
    ] as $domain) {
        $snapshot[$domain] =
            ingredientOntologyActivationCdcHighWater($db, $domain);
    }
    $snapshot['all'] = ingredientOntologyActivationCdcHighWater($db);
    return $snapshot;
}

function ingredientOntologyActivationTriggerDefinitions(): array {
    return [
        [
            'products',
            'source',
            'id',
            'product',
            'id',
            [
                'barcode' => 'OLD.barcode IS NOT NEW.barcode',
                'name' => 'OLD.name IS NOT NEW.name',
                'brand' => 'OLD.brand IS NOT NEW.brand',
                'category' => 'OLD.category IS NOT NEW.category',
                'off_generic_name' =>
                    'OLD.off_generic_name IS NOT NEW.off_generic_name',
                'ingredients_text' =>
                    'OLD.ingredients_text IS NOT NEW.ingredients_text',
                'ingredients_tags_json' =>
                    'OLD.ingredients_tags_json '
                        . 'IS NOT NEW.ingredients_tags_json',
                'prepared_food' =>
                    'OLD.prepared_food IS NOT NEW.prepared_food',
            ],
        ],
        ['recipe_catalog', 'source', 'id', 'recipe', 'id'],
        ['recipe_origins', 'source', 'id', 'recipe_origin', 'id'],
        [
            'recipe_ingredients',
            'source',
            'id',
            'recipe_ingredient',
            'id',
        ],
        [
            'recipe_source_ingredients',
            'source',
            'id',
            'recipe_source_ingredient',
            'id',
        ],
        ['recipe_user_state', 'catalog', 'recipe_id', 'recipe', 'recipe_id'],
        [
            'inventory',
            'inventory',
            'id',
            'inventory',
            'id',
            [
                'product_id' =>
                    'OLD.product_id IS NOT NEW.product_id',
                'location' => 'OLD.location IS NOT NEW.location',
                'quantity' => 'OLD.quantity IS NOT NEW.quantity',
                'expiry_date' =>
                    'OLD.expiry_date IS NOT NEW.expiry_date',
                'expiry_user_set' =>
                    'OLD.expiry_user_set IS NOT NEW.expiry_user_set',
                'vacuum_sealed' =>
                    'OLD.vacuum_sealed IS NOT NEW.vacuum_sealed',
                'opened_at' =>
                    'OLD.opened_at IS NOT NEW.opened_at',
                'prepared_food' =>
                    'OLD.prepared_food IS NOT NEW.prepared_food',
            ],
        ],
        [
            'product_ingredients',
            'evidence',
            'id',
            'product_mapping',
            'id',
        ],
        [
            'canonical_ingredients',
            'evidence',
            'id',
            'canonical_ingredient',
            'id',
        ],
        ['taxonomy_nodes', 'evidence', 'id', 'taxonomy_node', 'id'],
        [
            'ontology_constraint_ledger',
            'constraint',
            'id',
            'constraint',
            'id',
        ],
        [
            'ontology_controller_benchmark_policies',
            'policy',
            'id',
            'benchmark_policy',
            'id',
        ],
        [
            'ontology_gold_releases',
            'policy',
            'id',
            'gold_release',
            'id',
        ],
        [
            'ontology_generation_intents',
            'evidence',
            'id',
            'generation_intent',
            'id',
        ],
        ['ontology_subjects', 'evidence', 'id', 'subject', 'id'],
        [
            'ontology_subject_occurrences',
            'evidence',
            'id',
            'subject_occurrence',
            'id',
        ],
        [
            'ontology_controller_state',
            'policy',
            'id',
            'controller_state',
            'id',
            [
                'constraint_epoch' =>
                    'OLD.constraint_epoch IS NOT NEW.constraint_epoch',
                'active_gold_release_id' =>
                    'OLD.active_gold_release_id '
                        . 'IS NOT NEW.active_gold_release_id',
                'active_policy_hash' =>
                    'OLD.active_policy_hash '
                        . 'IS NOT NEW.active_policy_hash',
            ],
        ],
    ];
}

function ingredientOntologyActivationUpdateWhen(
    PDO $db,
    string $table,
    mixed $definition
): ?string {
    if (is_string($definition)) {
        $definition = trim($definition);
        return $definition !== '' ? $definition : null;
    }
    if (!is_array($definition) || !$definition) {
        return null;
    }
    $columns = array_fill_keys(array_column(
        $db->query(
            'PRAGMA table_info('
                . ingredientOntologyActivationQuoteIdentifier($table)
                . ')'
        )->fetchAll(PDO::FETCH_ASSOC),
        'name'
    ), true);
    $predicates = [];
    foreach ($definition as $column => $predicate) {
        if (
            isset($columns[(string)$column])
            && is_string($predicate)
            && trim($predicate) !== ''
        ) {
            $predicates[] = trim($predicate);
        }
    }
    return $predicates ? implode("\n OR ", $predicates) : null;
}

function ingredientOntologyActivationAddColumn(
    PDO $db,
    string $table,
    string $column,
    string $definition
): void {
    $columns = array_column(
        $db->query(
            'PRAGMA table_info('
                . ingredientOntologyActivationQuoteIdentifier($table)
                . ')'
        )->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    if (!in_array($column, $columns, true)) {
        $db->exec(
            'ALTER TABLE '
                . ingredientOntologyActivationQuoteIdentifier($table)
                . ' ADD COLUMN '
                . ingredientOntologyActivationQuoteIdentifier($column)
                . ' ' . $definition
        );
    }
}

function ingredientOntologyActivationInstalledTriggerHash(
    PDO $db,
    array $triggerNames
): string {
    sort($triggerNames, SORT_STRING);
    $rows = [];
    $stmt = $db->prepare("
        SELECT name, sql
        FROM sqlite_master
        WHERE type = 'trigger' AND name = ?
    ");
    foreach ($triggerNames as $name) {
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $rows[] = [
            'name' => $name,
            'sql' => $row ? (string)$row['sql'] : null,
        ];
    }
    return ingredientOntologyV3Hash($rows);
}

function ingredientOntologyActivationSchemaMigrate(PDO $db): void {
    if ((int)$db->query("
        SELECT sqlite_compileoption_used('USE_URI')
    ")->fetchColumn() !== 1) {
        throw new RuntimeException(
            'ontology activation requires SQLite URI filename support'
        );
    }
    $db->exec("
        CREATE TABLE IF NOT EXISTS ontology_activation_state (
            id INTEGER PRIMARY KEY CHECK(id = 1),
            database_lineage_uuid TEXT NOT NULL
                CHECK(length(database_lineage_uuid) = 32),
            trigger_version TEXT NOT NULL DEFAULT '',
            trigger_hash TEXT NOT NULL DEFAULT ''
                CHECK(trigger_hash = '' OR length(trigger_hash) = 64),
            cdc_all_high_water INTEGER NOT NULL DEFAULT 0
                CHECK(cdc_all_high_water >= 0),
            cdc_source_high_water INTEGER NOT NULL DEFAULT 0
                CHECK(cdc_source_high_water >= 0),
            cdc_catalog_high_water INTEGER NOT NULL DEFAULT 0
                CHECK(cdc_catalog_high_water >= 0),
            cdc_inventory_high_water INTEGER NOT NULL DEFAULT 0
                CHECK(cdc_inventory_high_water >= 0),
            cdc_constraint_high_water INTEGER NOT NULL DEFAULT 0
                CHECK(cdc_constraint_high_water >= 0),
            cdc_policy_high_water INTEGER NOT NULL DEFAULT 0
                CHECK(cdc_policy_high_water >= 0),
            cdc_evidence_high_water INTEGER NOT NULL DEFAULT 0
                CHECK(cdc_evidence_high_water >= 0),
            requested_at DATETIME DEFAULT NULL,
            requested_reason TEXT NOT NULL DEFAULT ''
                CHECK(length(requested_reason) <= 500),
            next_attempt_at DATETIME DEFAULT NULL,
            failure_count INTEGER NOT NULL DEFAULT 0
                CHECK(failure_count >= 0),
            last_error TEXT NOT NULL DEFAULT ''
                CHECK(length(last_error) <= 1000),
            last_outcome_kind TEXT NOT NULL DEFAULT ''
                CHECK(length(last_outcome_kind) <= 80),
            last_outcome_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(last_outcome_json) <= 32768),
            last_outcome_at DATETIME DEFAULT NULL,
            expected_outcome_key TEXT NOT NULL DEFAULT ''
                CHECK(
                    expected_outcome_key = ''
                    OR length(expected_outcome_key) = 64
                ),
            expected_outcome_count INTEGER NOT NULL DEFAULT 0
                CHECK(expected_outcome_count >= 0),
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS ontology_activation_cdc (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT NOT NULL CHECK(domain IN (
                'source', 'catalog', 'inventory',
                'constraint', 'policy', 'evidence'
            )),
            table_name TEXT NOT NULL CHECK(length(table_name) <= 80),
            operation TEXT NOT NULL CHECK(operation IN (
                'insert', 'update', 'delete'
            )),
            owner_type TEXT DEFAULT NULL
                CHECK(owner_type IS NULL OR length(owner_type) <= 80),
            owner_id INTEGER DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_ontology_activation_cdc_domain
            ON ontology_activation_cdc(domain, id);

        CREATE TABLE IF NOT EXISTS ontology_activation_imports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bundle_hash TEXT NOT NULL UNIQUE CHECK(length(bundle_hash) = 64),
            bundle_kind TEXT NOT NULL CHECK(bundle_kind IN (
                'ontology', 'score'
            )),
            database_lineage_uuid TEXT NOT NULL
                CHECK(length(database_lineage_uuid) = 32),
            schema_version TEXT NOT NULL
                CHECK(length(schema_version) <= 80),
            payload_path TEXT NOT NULL CHECK(length(payload_path) <= 1000),
            payload_sha256 TEXT NOT NULL CHECK(length(payload_sha256) = 64),
            payload_bytes INTEGER NOT NULL CHECK(payload_bytes >= 0),
            manifest_json TEXT NOT NULL
                CHECK(length(manifest_json) <= 1048576),
            parent_ontology_version_id INTEGER DEFAULT NULL,
            candidate_ontology_version_id INTEGER DEFAULT NULL,
            parent_score_revision_id INTEGER DEFAULT NULL,
            candidate_score_revision_id INTEGER DEFAULT NULL,
            status TEXT NOT NULL DEFAULT 'staging' CHECK(status IN (
                'staging', 'importing', 'verifying', 'activatable',
                'active', 'rebase_required', 'failed',
                'purging', 'cleaned', 'complete'
            )),
            phase INTEGER NOT NULL DEFAULT 0 CHECK(phase >= 0),
            cleanup_phase INTEGER NOT NULL DEFAULT 0
                CHECK(cleanup_phase >= 0),
            chunk_rows INTEGER NOT NULL DEFAULT 250
                CHECK(chunk_rows BETWEEN 1 AND 5000),
            rows_imported INTEGER NOT NULL DEFAULT 0
                CHECK(rows_imported >= 0),
            lease_token TEXT DEFAULT NULL
                CHECK(lease_token IS NULL OR length(lease_token) = 64),
            lease_generation INTEGER NOT NULL DEFAULT 0
                CHECK(lease_generation >= 0),
            leased_until DATETIME DEFAULT NULL,
            source_fence_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(source_fence_json) <= 262144),
            validation_fence_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(validation_fence_json) <= 262144),
            attestation_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(attestation_json) <= 1048576),
            last_reservation_ms REAL NOT NULL DEFAULT 0
                CHECK(last_reservation_ms >= 0),
            maximum_reservation_ms REAL NOT NULL DEFAULT 0
                CHECK(maximum_reservation_ms >= 0),
            last_error TEXT NOT NULL DEFAULT ''
                CHECK(length(last_error) <= 1000),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            activated_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_ontology_activation_imports_status
            ON ontology_activation_imports(
                status, leased_until, updated_at, id
            );

        CREATE TABLE IF NOT EXISTS ontology_activation_import_tables (
            import_id INTEGER NOT NULL,
            phase INTEGER NOT NULL CHECK(phase >= 0),
            table_name TEXT NOT NULL CHECK(length(table_name) <= 100),
            cursor_column TEXT NOT NULL CHECK(length(cursor_column) <= 100),
            baseline_sequence INTEGER DEFAULT NULL,
            expected_post_sequence INTEGER DEFAULT NULL,
            expected_row_count INTEGER NOT NULL CHECK(expected_row_count >= 0),
            expected_min_cursor INTEGER DEFAULT NULL,
            expected_max_cursor INTEGER DEFAULT NULL,
            id_set_hash TEXT NOT NULL CHECK(length(id_set_hash) = 64),
            row_hash TEXT NOT NULL CHECK(length(row_hash) = 64),
            source_cursor INTEGER NOT NULL DEFAULT 0,
            rows_imported INTEGER NOT NULL DEFAULT 0
                CHECK(rows_imported >= 0),
            status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN (
                'pending', 'importing', 'complete', 'purging', 'purged'
            )),
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(import_id, table_name),
            UNIQUE(import_id, phase),
            FOREIGN KEY(import_id)
                REFERENCES ontology_activation_imports(id)
                    ON DELETE CASCADE
        ) WITHOUT ROWID;

        CREATE TABLE IF NOT EXISTS ontology_activation_import_intents (
            import_id INTEGER NOT NULL,
            source_job_id INTEGER NOT NULL,
            subject_id INTEGER DEFAULT NULL,
            subject_fingerprint TEXT DEFAULT NULL
                CHECK(
                    subject_fingerprint IS NULL
                    OR length(subject_fingerprint) = 64
                ),
            intent_kind TEXT NOT NULL CHECK(intent_kind IN (
                'validated_plan', 'exact_constraint', 'provisional'
            )),
            activation_action TEXT NOT NULL DEFAULT 'apply'
                CHECK(activation_action IN ('apply', 'defer')),
            input_hash TEXT NOT NULL CHECK(length(input_hash) = 64),
            response_hash TEXT DEFAULT NULL
                CHECK(response_hash IS NULL OR length(response_hash) = 64),
            plan_hash TEXT DEFAULT NULL
                CHECK(plan_hash IS NULL OR length(plan_hash) = 64),
            status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN (
                'pending', 'applied', 'deferred',
                'superseded', 'failed'
            )),
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(import_id, source_job_id),
            FOREIGN KEY(import_id)
                REFERENCES ontology_activation_imports(id)
                    ON DELETE CASCADE
        ) WITHOUT ROWID;

        CREATE TABLE IF NOT EXISTS ontology_activation_acknowledgements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_hash TEXT NOT NULL UNIQUE
                CHECK(length(document_hash) = 64),
            document_json TEXT NOT NULL
                CHECK(length(document_json) <= 1048576),
            status TEXT NOT NULL DEFAULT 'ready' CHECK(status IN (
                'ready', 'applied', 'superseded', 'failed'
            )),
            last_error TEXT NOT NULL DEFAULT ''
                CHECK(length(last_error) <= 1000),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            applied_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");

    foreach ([
        'cdc_all_high_water',
        'cdc_source_high_water',
        'cdc_catalog_high_water',
        'cdc_inventory_high_water',
        'cdc_constraint_high_water',
        'cdc_policy_high_water',
        'cdc_evidence_high_water',
    ] as $column) {
        ingredientOntologyActivationAddColumn(
            $db,
            'ontology_activation_state',
            $column,
            'INTEGER NOT NULL DEFAULT 0 CHECK('
                . $column . ' >= 0)'
        );
    }
    ingredientOntologyActivationAddColumn(
        $db,
        'ontology_activation_import_intents',
        'activation_action',
        "TEXT NOT NULL DEFAULT 'apply' "
            . "CHECK(activation_action IN ('apply','defer'))"
    );
    ingredientOntologyActivationAddColumn(
        $db,
        'ontology_activation_state',
        'last_outcome_kind',
        "TEXT NOT NULL DEFAULT '' CHECK(length(last_outcome_kind) <= 80)"
    );
    ingredientOntologyActivationAddColumn(
        $db,
        'ontology_activation_state',
        'last_outcome_json',
        "TEXT NOT NULL DEFAULT '{}' CHECK(length(last_outcome_json) <= 32768)"
    );
    ingredientOntologyActivationAddColumn(
        $db,
        'ontology_activation_state',
        'last_outcome_at',
        'DATETIME DEFAULT NULL'
    );
    ingredientOntologyActivationAddColumn(
        $db,
        'ontology_activation_state',
        'expected_outcome_key',
        "TEXT NOT NULL DEFAULT '' CHECK("
            . "expected_outcome_key = '' "
            . "OR length(expected_outcome_key) = 64)"
    );
    ingredientOntologyActivationAddColumn(
        $db,
        'ontology_activation_state',
        'expected_outcome_count',
        'INTEGER NOT NULL DEFAULT 0 '
            . 'CHECK(expected_outcome_count >= 0)'
    );

    $lineage = (string)$db->query("
        SELECT database_lineage_uuid
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetchColumn();
    if ($lineage === '') {
        $db->prepare("
            INSERT OR IGNORE INTO ontology_activation_state (
                id, database_lineage_uuid
            ) VALUES (1, ?)
        ")->execute([bin2hex(random_bytes(16))]);
    }
    $db->exec("
        UPDATE ontology_activation_state
        SET cdc_all_high_water = MAX(
                cdc_all_high_water,
                COALESCE((
                    SELECT MAX(id) FROM ontology_activation_cdc
                ), 0)
            ),
            cdc_source_high_water = MAX(
                cdc_source_high_water,
                COALESCE((
                    SELECT MAX(id) FROM ontology_activation_cdc
                    WHERE domain = 'source'
                ), 0)
            ),
            cdc_catalog_high_water = MAX(
                cdc_catalog_high_water,
                COALESCE((
                    SELECT MAX(id) FROM ontology_activation_cdc
                    WHERE domain = 'catalog'
                ), 0)
            ),
            cdc_inventory_high_water = MAX(
                cdc_inventory_high_water,
                COALESCE((
                    SELECT MAX(id) FROM ontology_activation_cdc
                    WHERE domain = 'inventory'
                ), 0)
            ),
            cdc_constraint_high_water = MAX(
                cdc_constraint_high_water,
                COALESCE((
                    SELECT MAX(id) FROM ontology_activation_cdc
                    WHERE domain = 'constraint'
                ), 0)
            ),
            cdc_policy_high_water = MAX(
                cdc_policy_high_water,
                COALESCE((
                    SELECT MAX(id) FROM ontology_activation_cdc
                    WHERE domain = 'policy'
                ), 0)
            ),
            cdc_evidence_high_water = MAX(
                cdc_evidence_high_water,
                COALESCE((
                    SELECT MAX(id) FROM ontology_activation_cdc
                    WHERE domain = 'evidence'
                ), 0)
            )
        WHERE id = 1
    ");

    $definitions = ingredientOntologyActivationTriggerDefinitions();
    $triggerPayload = [];
    $triggerNames = [];
    foreach ($definitions as $definition) {
        [
            $table,
            $domain,
            $idColumn,
            $ownerType,
            $ownerIdColumn,
            $updateWhen,
        ] = array_pad($definition, 6, null);
        if (ingredientOntologyActivationTableExists($db, $table)) {
            $triggerPayload[] = [
                'table' => $table,
                'domain' => $domain,
                'id_column' => $idColumn,
                'owner_type' => $ownerType,
                'owner_id_column' => $ownerIdColumn,
                'update_when' => $updateWhen,
            ];
            foreach (['insert', 'update', 'delete'] as $operation) {
                $triggerNames[] = 'ontology_activation_cdc_'
                    . $table . '_' . $operation;
            }
        }
    }
    $triggerHash = ingredientOntologyV3Hash([
        'version' => INGREDIENT_ONTOLOGY_ACTIVATION_TRIGGER_VERSION,
        'definitions' => $triggerPayload,
        'installed' =>
            ingredientOntologyActivationInstalledTriggerHash(
                $db,
                $triggerNames
            ),
    ]);
    $state = $db->query("
        SELECT trigger_version, trigger_hash
        FROM ontology_activation_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    if (
        (string)($state['trigger_version'] ?? '')
            === INGREDIENT_ONTOLOGY_ACTIVATION_TRIGGER_VERSION
        && hash_equals(
            (string)($state['trigger_hash'] ?? ''),
            $triggerHash
        )
    ) {
        return;
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->exec('BEGIN IMMEDIATE');
    }
    try {
        foreach ($definitions as $definition) {
            [
                $table,
                $domain,
                $idColumn,
                $ownerType,
                $ownerIdColumn,
                $updateWhen,
            ] = array_pad($definition, 6, null);
            if (!ingredientOntologyActivationTableExists($db, $table)) {
                continue;
            }
            foreach (['insert', 'update', 'delete'] as $operation) {
                $trigger = 'ontology_activation_cdc_'
                    . $table . '_' . $operation;
                $row = $operation === 'delete' ? 'OLD' : 'NEW';
                $domainColumn = 'cdc_' . $domain . '_high_water';
                $resolvedUpdateWhen =
                    ingredientOntologyActivationUpdateWhen(
                        $db,
                        $table,
                        $updateWhen
                    );
                $when = $operation === 'update'
                    && $resolvedUpdateWhen !== null
                        ? "\n                    WHEN ({$resolvedUpdateWhen})"
                        : '';
                $db->exec("DROP TRIGGER IF EXISTS {$trigger}");
                $db->exec("
                    CREATE TRIGGER {$trigger}
                    AFTER " . strtoupper($operation) . " ON {$table}{$when}
                    BEGIN
                        UPDATE ontology_activation_state
                        SET cdc_all_high_water =
                                cdc_all_high_water + 1,
                            {$domainColumn} =
                                {$domainColumn} + 1,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = 1;
                        INSERT INTO ontology_activation_cdc (
                            domain, table_name, operation,
                            owner_type, owner_id
                        )
                        VALUES (
                            '{$domain}', '{$table}', '{$operation}',
                            '{$ownerType}', {$row}.{$ownerIdColumn}
                        );
                    END
                ");
            }
        }
        $triggerHash = ingredientOntologyV3Hash([
            'version' => INGREDIENT_ONTOLOGY_ACTIVATION_TRIGGER_VERSION,
            'definitions' => $triggerPayload,
            'installed' =>
                ingredientOntologyActivationInstalledTriggerHash(
                    $db,
                    $triggerNames
                ),
        ]);
        $db->prepare("
            UPDATE ontology_activation_state
            SET trigger_version = ?,
                trigger_hash = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1
        ")->execute([
            INGREDIENT_ONTOLOGY_ACTIVATION_TRIGGER_VERSION,
            $triggerHash,
        ]);
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $error;
    }
}

function ingredientOntologyActivationQuoteIdentifier(
        string $identifier
    ): string {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier)) {
            throw new InvalidArgumentException(
                'ontology activation SQL identifier is invalid'
            );
        }
        return '"' . $identifier . '"';
    }

    function ingredientOntologyActivationTableSchemaHash(
        PDO $db,
        string $table
    ): string {
        $quoted = ingredientOntologyActivationQuoteIdentifier($table);
        return ingredientOntologyV3Hash([
            'columns' => $db->query(
                "PRAGMA table_info({$quoted})"
            )->fetchAll(PDO::FETCH_ASSOC),
            'foreign_keys' => $db->query(
                "PRAGMA foreign_key_list({$quoted})"
            )->fetchAll(PDO::FETCH_ASSOC),
            'indexes' => $db->query(
                "PRAGMA index_list({$quoted})"
            )->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    function ingredientOntologyActivationTableUsesAutoincrement(
        PDO $db,
        string $table
    ): bool {
        $stmt = $db->prepare("
            SELECT sql FROM sqlite_master
            WHERE type = 'table' AND name = ?
        ");
        $stmt->execute([$table]);
        $sql = (string)($stmt->fetchColumn() ?: '');
        return stripos($sql, 'AUTOINCREMENT') !== false;
    }

    function ingredientOntologyActivationSequence(
        PDO $db,
        string $table
    ): ?int {
        if (!ingredientOntologyActivationTableUsesAutoincrement($db, $table)) {
            return null;
        }
        $stmt = $db->prepare("
            SELECT seq FROM sqlite_sequence WHERE name = ?
        ");
        $stmt->execute([$table]);
        $sequence = $stmt->fetchColumn();
        return $sequence === false ? 0 : (int)$sequence;
    }

    function ingredientOntologyActivationReserveManifestSequences(
        PDO $db,
        array $tables
    ): void {
        foreach ($tables as $table) {
            $baseline = $table['baseline_sequence'] ?? null;
            if ($baseline === null) {
                continue;
            }
            $tableName = (string)($table['table'] ?? '');
            $current = ingredientOntologyActivationSequence(
                $db,
                $tableName
            );
            if ($current === null || $current !== (int)$baseline) {
                throw new IngredientOntologyActivationExpectedOutcome(
                    'superseded_snapshot',
                    "ontology activation sequence fence changed: {$tableName}",
                    [
                        'reason' => 'sequence_fence_changed',
                        'table' => $tableName,
                        'expected_sequence' => (int)$baseline,
                        'current_sequence' => $current,
                    ]
                );
            }
            if ((int)($table['row_count'] ?? 0) > 0) {
                $minimum = $table['minimum_cursor'] ?? null;
                $maximum = $table['maximum_cursor'] ?? null;
                if ($minimum === null || $maximum === null) {
                    throw new RuntimeException(
                        "ontology activation ID range is incomplete: {$tableName}"
                    );
                }
                $cursor = ingredientOntologyActivationQuoteIdentifier(
                    (string)$table['cursor']
                );
                $target = ingredientOntologyActivationQuoteIdentifier(
                    $tableName
                );
                $collision = $db->prepare("
                    SELECT 1 FROM {$target}
                    WHERE {$cursor} BETWEEN ? AND ?
                    LIMIT 1
                ");
                $collision->execute([
                    (int)$minimum,
                    (int)$maximum,
                ]);
                if ($collision->fetchColumn()) {
                    throw new RuntimeException(
                        "ontology activation ID range collided: {$tableName}"
                    );
                }
            }
            $reservedThrough = $table['expected_post_sequence'] ?? null;
            if (
                $reservedThrough === null
                || (int)$reservedThrough < (int)$baseline
            ) {
                throw new RuntimeException(
                    "ontology activation sequence reservation is invalid: {$tableName}"
                );
            }
            if ((int)$reservedThrough === (int)$baseline) {
                continue;
            }
            $update = $db->prepare("
                UPDATE sqlite_sequence
                SET seq = ?
                WHERE name = ?
            ");
            $update->execute([
                (int)$reservedThrough,
                $tableName,
            ]);
            if ($update->rowCount() === 0 && (int)$baseline === 0) {
                $insert = $db->prepare("
                    INSERT INTO sqlite_sequence (name, seq)
                    SELECT ?, ?
                    WHERE NOT EXISTS (
                        SELECT 1 FROM sqlite_sequence WHERE name = ?
                    )
                ");
                $insert->execute([
                    $tableName,
                    (int)$reservedThrough,
                    $tableName,
                ]);
            }
            if (
                ingredientOntologyActivationSequence($db, $tableName)
                    !== (int)$reservedThrough
            ) {
                throw new RuntimeException(
                    "ontology activation sequence reservation was lost: {$tableName}"
                );
            }
        }
    }

    function ingredientOntologyActivationRuntimeHash(): string {
        $files = [
            __FILE__,
            __DIR__ . '/controller.php',
            __DIR__ . '/core.php',
            __DIR__ . '/curated.php',
            __DIR__ . '/matcher.php',
            __DIR__ . '/proposals.php',
            __DIR__ . '/provider_requirements.php',
            __DIR__ . '/resolution.php',
            __DIR__ . '/schema.php',
            __DIR__ . '/scores.php',
            __DIR__ . '/requirement_scores.php',
            __DIR__ . '/../recipes/schema.php',
            __DIR__ . '/../recipes/scores.php',
            __DIR__ . '/../recipes/ingredients.php',
        ];
        $hashes = [];
        foreach ($files as $index => $file) {
            $hash = hash_file('sha256', $file);
            if (!is_string($hash) || strlen($hash) !== 64) {
                throw new RuntimeException(
                    'ontology activation runtime file hash failed'
                );
            }
            $hashes[$index . ':' . str_replace(
                dirname(__DIR__, 3) . '/',
                '',
                $file
            )] = $hash;
        }
        return ingredientOntologyV3Hash([
            'activation_schema' =>
                INGREDIENT_ONTOLOGY_ACTIVATION_SCHEMA_VERSION,
            'bundle_schema' =>
                INGREDIENT_ONTOLOGY_ACTIVATION_BUNDLE_VERSION,
            'database_schema' => defined('EVERSHELF_DATABASE_SCHEMA_VERSION')
                ? EVERSHELF_DATABASE_SCHEMA_VERSION
                : null,
            'ontology_schema' => INGREDIENT_ONTOLOGY_V3_SCHEMA_VERSION,
            'controller_schema' =>
                INGREDIENT_ONTOLOGY_CONTROLLER_SCHEMA_VERSION,
            'controller_policy' =>
                INGREDIENT_ONTOLOGY_CONTROLLER_POLICY_VERSION,
            'controller_prompt' =>
                INGREDIENT_ONTOLOGY_CONTROLLER_PROMPT_VERSION,
            'scoring_version' => INGREDIENT_ONTOLOGY_V3_SCORING_VERSION,
            'files' => $hashes,
        ]);
    }

    function ingredientOntologyActivationOntologyTableSpecs(): array {
        $specs = [[
            'phase' => 0,
            'table' => 'ingredient_ontology_versions',
            'cursor' => 'id',
            'selector' => 'id = ?',
            'selector_kind' => 'ontology_version',
            'root' => true,
        ]];
        foreach (
            ingredientOntologyControllerForkPhaseDefinitions(1, 2)
            as $index => $phase
        ) {
            $specs[] = [
                'phase' => $index + 1,
                'table' => (string)$phase['table'],
                'cursor' => (string)$phase['cursor'],
                'selector' => 'ontology_version_id = ?',
                'selector_kind' => 'ontology_version',
                'root' => false,
            ];
        }
        $phase = count($specs);
        foreach ([
            [
                'ingredient_ontology_review_imports',
                'id',
                'ontology_version_id = ?',
                'ontology_version',
            ],
            [
                'ingredient_ontology_review_import_rows',
                'id',
                'ontology_version_id = ?',
                'ontology_version',
            ],
            [
                'ingredient_ontology_change_sets',
                'id',
                'ontology_version_id = ?',
                'ontology_version',
            ],
            [
                'ingredient_ontology_proposals',
                'id',
                'change_set_id IN (
                    SELECT id FROM main.ingredient_ontology_change_sets
                    WHERE ontology_version_id = ?
                )',
                'ontology_version',
            ],
            [
                'ingredient_ontology_change_events',
                'id',
                'change_set_id IN (
                    SELECT id FROM main.ingredient_ontology_change_sets
                    WHERE ontology_version_id = ?
                )',
                'ontology_version',
            ],
        ] as [$table, $cursor, $selector, $selectorKind]) {
            $specs[] = [
                'phase' => $phase++,
                'table' => $table,
                'cursor' => $cursor,
                'selector' => $selector,
                'selector_kind' => $selectorKind,
                'root' => false,
            ];
        }
        return $specs;
    }

    function ingredientOntologyActivationScoreTableSpecs(): array {
        return [
            [
                'phase' => 0,
                'table' => 'recipe_score_revisions',
                'cursor' => 'id',
                'selector' => 'id = ?',
                'selector_kind' => 'score_revision',
                'root' => true,
            ],
            [
                'phase' => 1,
                'table' => 'recipe_inventory_scores',
                'cursor' => 'recipe_id',
                'selector' => 'score_revision_id = ?',
                'selector_kind' => 'score_revision',
                'root' => false,
            ],
            [
                'phase' => 2,
                'table' => 'ingredient_ontology_shadow_matches',
                'cursor' => 'recipe_ingredient_id',
                'selector' => 'score_revision_id = ?',
                'selector_kind' => 'score_revision',
                'root' => false,
            ],
            [
                'phase' => 3,
                'table' => 'recipe_score_match_contributors',
                'cursor' => 'recipe_ingredient_id',
                'selector' => 'score_revision_id = ?',
                'selector_kind' => 'score_revision',
                'root' => false,
            ],
            [
                'phase' => 4,
                'table' => 'recipe_score_contributor_revisions',
                'cursor' => 'score_revision_id',
                'selector' => 'score_revision_id = ?',
                'selector_kind' => 'score_revision',
                'root' => false,
            ],
            [
                'phase' => 5,
                'table' =>
                    'ingredient_ontology_identity_extension_entities',
                'cursor' => 'id',
                'selector' => '
                    EXISTS (
                        SELECT 1
                        FROM main.recipe_score_revisions score
                        WHERE score.id = ?
                          AND score.ontology_version_id =
                              ingredient_ontology_identity_extension_entities
                                  .ontology_version_id
                          AND
                              ingredient_ontology_identity_extension_entities
                                  .created_revision
                              <= score.identity_extension_revision
                    )
                ',
                'selector_kind' => 'score_revision',
                'root' => false,
                'after_snapshot_sequence' => true,
                'append_only' => true,
                'retain_on_cleanup' => true,
            ],
        ];
    }

    function ingredientOntologyActivationCaptureBuildSnapshot(PDO $db): array {
        ingredientOntologyControllerAssertCopiedGenerationDatabase($db);
        $tables = [];
        foreach (array_merge(
            ingredientOntologyActivationOntologyTableSpecs(),
            ingredientOntologyActivationScoreTableSpecs()
        ) as $spec) {
            $tables[(string)$spec['table']] = true;
        }
        $sequences = [];
        foreach (array_keys($tables) as $table) {
            $sequences[$table] =
                ingredientOntologyActivationSequence($db, $table);
        }
        $state = recipeScoreState($db);
        $activeScoreId = (int)($state['active_score_revision_id'] ?? 0);
        $activeScore = $activeScoreId > 0
            ? recipeScoreRevision($db, $activeScoreId)
            : null;
        $activeVersion = ingredientOntologyV3ActiveVersion($db);
        $controllerState = $db->query("
            SELECT constraint_epoch, controller_generation,
                   active_gold_release_id, active_policy_hash
            FROM ontology_controller_state
            WHERE id = 1
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'schema_version' => INGREDIENT_ONTOLOGY_ACTIVATION_BUNDLE_VERSION,
            'captured_at' => gmdate('c'),
            'database_lineage_uuid' =>
                ingredientOntologyActivationLineageUuid($db),
            'runtime_hash' => ingredientOntologyActivationRuntimeHash(),
            'database_schema_version' => defined(
                'EVERSHELF_DATABASE_SCHEMA_VERSION'
            ) ? EVERSHELF_DATABASE_SCHEMA_VERSION : null,
            'state' => [
                'active_score_revision_id' => $activeScoreId,
                'inventory_revision' => (int)$state['inventory_revision'],
                'catalog_revision' => (int)$state['catalog_revision'],
                'ontology_source_revision' =>
                    (int)$state['ontology_source_revision'],
                'ontology_source_hash' =>
                    (string)$state['ontology_source_hash'],
                'cursor_revision' => (int)$state['cursor_revision'],
            ],
            'active_score' => $activeScore,
            'active_version' => $activeVersion,
            'controller_state' => $controllerState,
            'cdc' => ingredientOntologyActivationCdcSnapshot($db),
            'sequences' => $sequences,
            'ledger_high_water' => [
                'subject_id' => (int)$db->query("
                    SELECT COALESCE(MAX(id), 0) FROM ontology_subjects
                ")->fetchColumn(),
                'subject_sequence' =>
                    ingredientOntologyActivationSequence(
                        $db,
                        'ontology_subjects'
                    ),
                'job_id' => (int)$db->query("
                    SELECT COALESCE(MAX(id), 0)
                    FROM ontology_controller_jobs
                ")->fetchColumn(),
            ],
            'score_date' => recipeScoreCurrentDate(),
            'timezone' => recipeScoreTimezone()->getName(),
        ];
    }

    function ingredientOntologyActivationPrepareCopyWorkspace(
        PDO $db,
        array $snapshot
    ): array {
        ingredientOntologyControllerAssertCopiedGenerationDatabase($db);
        $versionFence = (int)(
            $snapshot['sequences']['ingredient_ontology_versions'] ?? 0
        );
        $scoreFence = (int)(
            $snapshot['sequences']['recipe_score_revisions'] ?? 0
        );
        $activeScoreId = (int)(
            $snapshot['state']['active_score_revision_id'] ?? 0
        );
        $activeVersionId = (int)(
            $snapshot['active_version']['id'] ?? 0
        );
        $db->exec('BEGIN IMMEDIATE');
        try {
            $generations = $db->prepare("
                UPDATE ontology_generations
                SET status = 'failed',
                    gate_report_json = ?,
                    monitor_until = NULL
                WHERE status IN (
                    'building', 'shadowing', 'promotable', 'promoting'
                )
                  AND (
                      candidate_version_id <= ?
                      OR candidate_score_revision_id <= ?
                  )
            ");
            $generations->execute([
                ingredientOntologyActivationStableJson([
                    'reason' => 'superseded_copy_workspace_candidate',
                    'captured_at' => $snapshot['captured_at'] ?? null,
                ]),
                $versionFence,
                $scoreFence,
            ]);
            $versions = $db->prepare("
                UPDATE ingredient_ontology_versions
                SET status = 'failed',
                    failed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id <= ?
                  AND id <> ?
                  AND status = 'building'
            ");
            $versions->execute([$versionFence, $activeVersionId]);
            $scores = $db->prepare("
                UPDATE recipe_score_revisions
                SET status = 'failed',
                    last_error =
                        'Superseded before copied activation bundle build.'
                WHERE id <= ?
                  AND id <> ?
                  AND status = 'building'
            ");
            $scores->execute([$scoreFence, $activeScoreId]);
            $db->exec('COMMIT');
            return [
                'failed_generations' => $generations->rowCount(),
                'failed_versions' => $versions->rowCount(),
                'failed_scores' => $scores->rowCount(),
            ];
        } catch (Throwable $error) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $error;
        }
    }

    function ingredientOntologyActivationPayloadTableHash(
        PDO $db,
        string $schema,
        string $table,
        string $cursor
    ): array {
        $schemaName = ingredientOntologyActivationQuoteIdentifier($schema);
        $tableName = ingredientOntologyActivationQuoteIdentifier($table);
        $cursorName = ingredientOntologyActivationQuoteIdentifier($cursor);
        $summaryStmt = $db->query("
            SELECT COUNT(*) AS row_count,
                   MIN({$cursorName}) AS minimum_cursor,
                   MAX({$cursorName}) AS maximum_cursor
            FROM {$schemaName}.{$tableName}
        ");
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $summaryStmt->closeCursor();
        $summaryStmt = null;
        $rowHash = hash_init('sha256');
        $idHash = hash_init('sha256');
        $stmt = $db->query("
            SELECT * FROM {$schemaName}.{$tableName}
            ORDER BY {$cursorName}
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            hash_update(
                $rowHash,
                ingredientOntologyActivationStableJson($row) . "\n"
            );
            hash_update(
                $idHash,
                (string)(int)$row[$cursor] . "\n"
            );
        }
        $stmt->closeCursor();
        $stmt = null;
        return [
            'row_count' => (int)($summary['row_count'] ?? 0),
            'minimum_cursor' =>
                $summary['minimum_cursor'] !== null
                    ? (int)$summary['minimum_cursor']
                    : null,
            'maximum_cursor' =>
                $summary['maximum_cursor'] !== null
                    ? (int)$summary['maximum_cursor']
                    : null,
            'id_set_hash' => hash_final($idHash),
            'row_hash' => hash_final($rowHash),
        ];
    }

    function ingredientOntologyActivationTargetTableHash(
        PDO $db,
        string $table,
        string $cursor,
        string $selector,
        int $candidateId,
        ?int $minimumCursorExclusive = null
    ): array {
        $tableName = ingredientOntologyActivationQuoteIdentifier($table);
        $cursorName = ingredientOntologyActivationQuoteIdentifier($cursor);
        $parameters = [$candidateId];
        if ($minimumCursorExclusive !== null) {
            $selector = "({$selector}) AND {$cursorName} > ?";
            $parameters[] = $minimumCursorExclusive;
        }
        $summaryStmt = $db->prepare("
            SELECT COUNT(*) AS row_count,
                   MIN({$cursorName}) AS minimum_cursor,
                   MAX({$cursorName}) AS maximum_cursor
            FROM {$tableName}
            WHERE {$selector}
        ");
        $summaryStmt->execute($parameters);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $rowHash = hash_init('sha256');
        $idHash = hash_init('sha256');
        $stmt = $db->prepare("
            SELECT * FROM {$tableName}
            WHERE {$selector}
            ORDER BY {$cursorName}
        ");
        $stmt->execute($parameters);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            hash_update(
                $rowHash,
                ingredientOntologyActivationStableJson($row) . "\n"
            );
            hash_update(
                $idHash,
                (string)(int)$row[$cursor] . "\n"
            );
        }
        return [
            'row_count' => (int)($summary['row_count'] ?? 0),
            'minimum_cursor' =>
                $summary['minimum_cursor'] !== null
                    ? (int)$summary['minimum_cursor']
                    : null,
            'maximum_cursor' =>
                $summary['maximum_cursor'] !== null
                    ? (int)$summary['maximum_cursor']
                    : null,
            'id_set_hash' => hash_final($idHash),
            'row_hash' => hash_final($rowHash),
        ];
    }

    function ingredientOntologyActivationPreparePayloadPath(
        string $directory,
        string $filename
    ): string {
        $directory = rtrim(trim($directory), '/');
        if ($directory === '' || !str_starts_with($directory, '/')) {
            throw new InvalidArgumentException(
                'ontology activation payload directory must be absolute'
            );
        }
        if (
            !is_dir($directory)
            && !mkdir($directory, 0770, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                'ontology activation payload directory could not be created'
            );
        }
        if (is_link($directory) || !is_writable($directory)) {
            throw new RuntimeException(
                'ontology activation payload directory is unsafe'
            );
        }
        $filename = basename($filename);
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $filename)) {
            throw new InvalidArgumentException(
                'ontology activation payload filename is invalid'
            );
        }
        $path = $directory . '/' . $filename;
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException(
                'ontology activation payload path already exists'
            );
        }
        return $path;
    }

    function ingredientOntologyActivationCreatePayload(
        PDO $db,
        string $kind,
        int $candidateId,
        array $specs,
        array $snapshot,
        string $directory,
        string $filename
    ): array {
        if (!in_array($kind, ['ontology', 'score'], true)) {
            throw new InvalidArgumentException(
                'ontology activation payload kind is invalid'
            );
        }
        $path = ingredientOntologyActivationPreparePayloadPath(
            $directory,
            $filename
        );
        $databasePath = '';
        foreach (
            $db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC)
            as $database
        ) {
            if ((string)$database['name'] === 'main') {
                $databasePath = (string)$database['file'];
                break;
            }
        }
        if ($databasePath === '' || !is_file($databasePath)) {
            throw new RuntimeException(
                'ontology activation copied database path is unavailable'
            );
        }
        $payloadDb =
            ingredientOntologyActivationOpenDatabase($databasePath);
        if (
            (int)$payloadDb->query('PRAGMA temp_store')->fetchColumn() !== 1
        ) {
            throw new RuntimeException(
                'ontology activation payload temp storage is unsafe'
            );
        }
        $temporary = $path . '.tmp.' . getmypid() . '.'
            . bin2hex(random_bytes(6));
        $attached = false;
        try {
            $payloadDb->exec(
                'ATTACH DATABASE ' . $payloadDb->quote($temporary)
                . ' AS activation_payload'
            );
            $attached = true;
            $payloadDb->exec('PRAGMA activation_payload.journal_mode = DELETE');
            $payloadDb->exec('PRAGMA activation_payload.synchronous = FULL');
            $payloadDb->exec("
                CREATE TABLE activation_payload.payload_metadata (
                    metadata_key TEXT PRIMARY KEY,
                    metadata_value TEXT NOT NULL
                ) WITHOUT ROWID
            ");
            $metadata = $payloadDb->prepare("
                INSERT INTO activation_payload.payload_metadata (
                    metadata_key, metadata_value
                ) VALUES (?, ?)
            ");
            foreach ([
                'schema_version' =>
                    INGREDIENT_ONTOLOGY_ACTIVATION_BUNDLE_VERSION,
                'kind' => $kind,
                'candidate_id' => (string)$candidateId,
                'database_lineage_uuid' =>
                    (string)$snapshot['database_lineage_uuid'],
                'runtime_hash' => (string)$snapshot['runtime_hash'],
            ] as $key => $value) {
                $metadata->execute([$key, $value]);
            }
            $metadata->closeCursor();
            $metadata = null;

            $tables = [];
            foreach ($specs as $spec) {
                $table = (string)$spec['table'];
                if (!ingredientOntologyActivationTableExists(
                    $payloadDb,
                    $table
                )) {
                    continue;
                }
                $cursor = (string)$spec['cursor'];
                $tableName =
                    ingredientOntologyActivationQuoteIdentifier($table);
                $cursorName =
                    ingredientOntologyActivationQuoteIdentifier($cursor);
                $selector = (string)$spec['selector'];
                $parameters = [$candidateId];
                if (!empty($spec['after_snapshot_sequence'])) {
                    $baseline = $snapshot['sequences'][$table] ?? null;
                    if ($baseline === null) {
                        throw new RuntimeException(
                            "ontology activation payload {$table} "
                            . 'requires a sequence fence'
                        );
                    }
                    $selector =
                        "({$selector}) AND {$cursorName} > ?";
                    $parameters[] = (int)$baseline;
                }
                $payloadDb->exec("
                    CREATE TABLE activation_payload.{$tableName}
                    AS SELECT * FROM main.{$tableName} WHERE 0
                ");
                $insert = $payloadDb->prepare("
                    INSERT INTO activation_payload.{$tableName}
                    SELECT * FROM main.{$tableName}
                    WHERE {$selector}
                ");
                $insert->execute($parameters);
                $insert->closeCursor();
                $insert = null;
                $index = ingredientOntologyActivationQuoteIdentifier(
                    'idx_payload_' . $table . '_' . $cursor
                );
                $payloadDb->exec("
                    CREATE INDEX activation_payload.{$index}
                    ON {$tableName}({$cursorName})
                ");
                $hashes = ingredientOntologyActivationPayloadTableHash(
                    $payloadDb,
                    'activation_payload',
                    $table,
                    $cursor
                );
                $baseline = $snapshot['sequences'][$table] ?? null;
                $expectedPost = $baseline;
                if (
                    $baseline !== null
                    && $hashes['row_count'] > 0
                ) {
                    if (
                        $hashes['minimum_cursor'] === null
                        || $hashes['minimum_cursor'] <= (int)$baseline
                    ) {
                        throw new RuntimeException(
                            "ontology activation payload {$table} "
                            . 'does not allocate above its sequence fence'
                        );
                    }
                    $expectedPost = $hashes['maximum_cursor'];
                }
                $tables[] = [
                    'phase' => (int)$spec['phase'],
                    'table' => $table,
                    'cursor' => $cursor,
                    'root' => !empty($spec['root']),
                    'table_schema_hash' =>
                        ingredientOntologyActivationTableSchemaHash(
                            $payloadDb,
                            $table
                        ),
                    'baseline_sequence' => $baseline,
                    'expected_post_sequence' => $expectedPost,
                ] + $hashes;
            }
            $payloadDb->exec('DETACH DATABASE activation_payload');
            $attached = false;
            if (!rename($temporary, $path)) {
                throw new RuntimeException(
                    'ontology activation payload could not be published'
                );
            }
            if (!chmod($path, 0440)) {
                throw new RuntimeException(
                    'ontology activation payload permissions could not be set'
                );
            }
            clearstatcache(true, $path);
            $payloadHash = hash_file('sha256', $path);
            if (!is_string($payloadHash) || strlen($payloadHash) !== 64) {
                throw new RuntimeException(
                    'ontology activation payload hash failed'
                );
            }
            foreach ([$path . '-wal', $path . '-shm'] as $companion) {
                if (file_exists($companion)) {
                    throw new RuntimeException(
                        'ontology activation payload is not single-file'
                    );
                }
            }
            return [
                'path' => $path,
                'file' => basename($path),
                'sha256' => $payloadHash,
                'bytes' => (int)filesize($path),
                'tables' => $tables,
            ];
        } finally {
            if ($attached) {
                try {
                    $payloadDb->exec('DETACH DATABASE activation_payload');
                } catch (Throwable $ignored) {
                }
            }
            $payloadDb = null;
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    function ingredientOntologyActivationGenerationIntents(
        PDO $db,
        int $generationId,
        int $candidateVersionId
    ): array {
        $stmt = $db->prepare("
            SELECT plan.job_id AS source_job_id,
                   job.subject_id, job.input_hash, job.input_json,
                   subject.subject_fingerprint,
                   intent.source_job_id AS intent_source_job_id,
                   intent.intent_kind, intent.response_artifact_id,
                   intent_response.response_hash AS intent_response_hash,
                   response.response_hash AS job_response_hash,
                   plan.plan_hash, plan.plan_json
            FROM ontology_generation_plans item
            JOIN ontology_mutation_plans plan
              ON plan.id = item.mutation_plan_id
            JOIN ontology_controller_jobs job ON job.id = plan.job_id
            LEFT JOIN ontology_subjects subject
              ON subject.id = job.subject_id
            LEFT JOIN ontology_generation_intents intent
              ON intent.source_job_id = job.id
            LEFT JOIN ontology_controller_responses response
              ON response.id = job.response_artifact_id
            LEFT JOIN ontology_controller_responses intent_response
              ON intent_response.id = intent.response_artifact_id
            WHERE item.generation_id = ?
            ORDER BY item.ordinal
        ");
        $stmt->execute([$generationId]);
        $intents = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $source = $row;
            $jobInput = json_decode(
                (string)($row['input_json'] ?? '{}'),
                true
            );
            if (
                is_array($jobInput)
                && (string)($jobInput['operation'] ?? '')
                    === 'provisional_fallback'
                && (int)($jobInput['source_job_id'] ?? 0) > 0
            ) {
                $sourceStmt = $db->prepare("
                    SELECT job.id AS source_job_id,
                           job.subject_id, job.input_hash,
                           subject.subject_fingerprint,
                           intent.source_job_id AS intent_source_job_id,
                           intent.intent_kind, intent.response_artifact_id,
                           intent_response.response_hash
                               AS intent_response_hash,
                           response.response_hash AS job_response_hash
                    FROM ontology_controller_jobs job
                    LEFT JOIN ontology_subjects subject
                      ON subject.id = job.subject_id
                    LEFT JOIN ontology_generation_intents intent
                      ON intent.source_job_id = job.id
                    LEFT JOIN ontology_controller_responses response
                      ON response.id = job.response_artifact_id
                    LEFT JOIN ontology_controller_responses intent_response
                      ON intent_response.id = intent.response_artifact_id
                    WHERE job.id = ?
                ");
                $sourceStmt->execute([
                    (int)$jobInput['source_job_id'],
                ]);
                $resolved = $sourceStmt->fetch(PDO::FETCH_ASSOC);
                if (!$resolved) {
                    throw new RuntimeException(
                        'provisional fallback source intent is unavailable'
                    );
                }
                $source = array_merge($row, $resolved);
            }
            $plan = json_decode(
                (string)$row['plan_json'],
                true,
                64,
                JSON_THROW_ON_ERROR
            );
            $intents[] = [
                'source_job_id' => (int)$source['source_job_id'],
                'subject_id' => $source['subject_id'] !== null
                    ? (int)$source['subject_id']
                    : null,
                'subject_fingerprint' =>
                    $source['subject_fingerprint'] !== null
                        ? (string)$source['subject_fingerprint']
                        : null,
                'input_hash' => (string)$source['input_hash'],
                'intent_kind' => (string)($source['intent_kind']
                    ?? 'validated_plan'),
                'activation_action' =>
                    (string)($source['intent_kind'] ?? '')
                        === 'validated_plan'
                    && (string)($plan['repair_kind'] ?? '')
                        === 'materialize_provisional_subject'
                        ? 'defer'
                        : 'apply',
                'response_hash' =>
                    ($source['intent_source_job_id'] ?? null) !== null
                        ? (
                            ($source['intent_response_hash'] ?? null)
                                !== null
                                ? (string)$source['intent_response_hash']
                                : null
                        )
                        : (
                            ($source['job_response_hash'] ?? null) !== null
                                ? (string)$source['job_response_hash']
                                : null
                        ),
                'plan_hash' => (string)$row['plan_hash'],
                'portable_plan' =>
                    ingredientOntologyControllerPortablePlan(
                        $db,
                        $candidateVersionId,
                        is_array($plan) ? $plan : []
                    ),
            ];
        }
        return $intents;
    }

    function ingredientOntologyActivationParsedPlanHash(
        ?string $planJson
    ): ?string {
        if (!is_string($planJson) || trim($planJson) === '') {
            return null;
        }
        $plan = json_decode(
            $planJson,
            true,
            64,
            JSON_THROW_ON_ERROR
        );
        return is_array($plan)
            ? ingredientOntologyV3Hash($plan)
            : null;
    }

    function ingredientOntologyActivationNoOpGenerationIntents(
        PDO $db,
        int $generationId
    ): array {
        $plans = $db->prepare("
            SELECT plan.job_id, plan.plan_hash, plan.plan_json,
                   job.input_json
            FROM ontology_generation_plans item
            JOIN ontology_mutation_plans plan
              ON plan.id = item.mutation_plan_id
            JOIN ontology_controller_jobs job ON job.id = plan.job_id
            WHERE item.generation_id = ?
            ORDER BY item.ordinal
        ");
        $plans->execute([$generationId]);
        $source = $db->prepare("
            SELECT job.id AS source_job_id,
                   job.subject_id, job.input_hash,
                   subject.subject_fingerprint,
                   intent.intent_kind, intent.status,
                   intent_response.response_hash
                       AS intent_response_hash,
                   intent_response.parsed_plan_json
                       AS intent_plan_json,
                   response.response_hash AS job_response_hash,
                   response.parsed_plan_json AS job_plan_json,
                   source_plan.plan_hash AS source_plan_hash
            FROM ontology_controller_jobs job
            JOIN ontology_generation_intents intent
              ON intent.source_job_id = job.id
            LEFT JOIN ontology_subjects subject
              ON subject.id = job.subject_id
            LEFT JOIN ontology_controller_responses response
              ON response.id = job.response_artifact_id
            LEFT JOIN ontology_controller_responses intent_response
              ON intent_response.id = intent.response_artifact_id
            LEFT JOIN ontology_mutation_plans source_plan
              ON source_plan.job_id = job.id
            WHERE job.id = ?
            ORDER BY source_plan.id DESC
            LIMIT 1
        ");
        $records = [];
        $staleSourceJobIds = [];
        foreach ($plans->fetchAll(PDO::FETCH_ASSOC) as $planRow) {
            $sourceJobId = (int)$planRow['job_id'];
            $input = json_decode(
                (string)($planRow['input_json'] ?? '{}'),
                true
            );
            $fallback = is_array($input)
                && (string)($input['operation'] ?? '')
                    === 'provisional_fallback'
                && (int)($input['source_job_id'] ?? 0) > 0;
            if ($fallback) {
                $sourceJobId = (int)$input['source_job_id'];
            }
            $source->execute([$sourceJobId]);
            $row = $source->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $staleSourceJobIds[$sourceJobId] = true;
                continue;
            }
            $intentKind = (string)$row['intent_kind'];
            $plan = json_decode(
                (string)$planRow['plan_json'],
                true,
                64,
                JSON_THROW_ON_ERROR
            );
            $action = $intentKind === 'validated_plan'
                && (
                    $fallback
                    || (string)($plan['repair_kind'] ?? '')
                        === 'materialize_provisional_subject'
                )
                    ? 'defer'
                    : 'apply';
            $responseHash = $row['intent_response_hash'] !== null
                ? (string)$row['intent_response_hash']
                : (
                    $row['job_response_hash'] !== null
                        ? (string)$row['job_response_hash']
                        : null
                );
            $sourcePlanJson = $row['intent_plan_json'] !== null
                ? (string)$row['intent_plan_json']
                : (
                    $row['job_plan_json'] !== null
                        ? (string)$row['job_plan_json']
                        : null
                );
            $record = [
                'source_job_id' => $sourceJobId,
                'subject_id' => $row['subject_id'] !== null
                    ? (int)$row['subject_id']
                    : null,
                'subject_fingerprint' =>
                    $row['subject_fingerprint'] !== null
                        ? (string)$row['subject_fingerprint']
                        : null,
                'input_hash' => (string)$row['input_hash'],
                'intent_kind' => $intentKind,
                'activation_action' => $action,
                'response_hash' => $responseHash,
                'source_plan_hash' =>
                    ingredientOntologyActivationParsedPlanHash(
                        $sourcePlanJson
                    ),
                'plan_hash' => (string)$planRow['plan_hash'],
            ];
            if (isset($records[$sourceJobId])) {
                if (!hash_equals(
                    ingredientOntologyV3Hash($records[$sourceJobId]),
                    ingredientOntologyV3Hash($record)
                )) {
                    throw new RuntimeException(
                        'semantic no-op source intent is ambiguous'
                    );
                }
                continue;
            }
            $records[$sourceJobId] = $record;
        }
        ksort($records, SORT_NUMERIC);
        $staleSourceJobIds = array_map(
            'intval',
            array_keys($staleSourceJobIds)
        );
        sort($staleSourceJobIds, SORT_NUMERIC);
        return [
            'intents' => array_values($records),
            'stale_source_job_ids' => $staleSourceJobIds,
        ];
    }

    function ingredientOntologyActivationIntentRecords(
        PDO $db,
        array $sourceJobIds,
        string|array $requiredStatuses = 'applied'
    ): array {
        $sourceJobIds = array_values(array_unique(array_filter(
            array_map('intval', $sourceJobIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$sourceJobIds) {
            return [];
        }
        $placeholders = implode(
            ',',
            array_fill(0, count($sourceJobIds), '?')
        );
        $requiredStatuses = is_array($requiredStatuses)
            ? array_values(array_unique(array_map(
                'strval',
                $requiredStatuses
            )))
            : [(string)$requiredStatuses];
        $requiredStatuses = array_values(array_filter(
            $requiredStatuses,
            static fn(string $status): bool => in_array($status, [
                'pending', 'queued', 'applied', 'superseded', 'failed',
            ], true)
        ));
        if (!$requiredStatuses) {
            return [];
        }
        $statusPlaceholders = implode(
            ',',
            array_fill(0, count($requiredStatuses), '?')
        );
        $stmt = $db->prepare("
            SELECT job.id AS source_job_id,
                   job.subject_id, job.input_hash,
                   subject.subject_fingerprint,
                   intent.intent_kind, intent.status,
                   intent_response.response_hash
                       AS intent_response_hash,
                   intent_response.parsed_plan_json
                       AS intent_plan_json,
                   response.response_hash AS job_response_hash,
                   response.parsed_plan_json AS job_plan_json,
                   plan.plan_hash
            FROM ontology_controller_jobs job
            JOIN ontology_generation_intents intent
              ON intent.source_job_id = job.id
            LEFT JOIN ontology_subjects subject
              ON subject.id = job.subject_id
            LEFT JOIN ontology_controller_responses response
              ON response.id = job.response_artifact_id
            LEFT JOIN ontology_controller_responses intent_response
              ON intent_response.id = intent.response_artifact_id
            LEFT JOIN ontology_mutation_plans plan
              ON plan.job_id = job.id
            WHERE job.id IN ({$placeholders})
              AND intent.status IN ({$statusPlaceholders})
            ORDER BY job.id
        ");
        $stmt->execute(array_merge($sourceJobIds, $requiredStatuses));
        return array_map(
            static fn(array $row): array => [
                'source_job_id' => (int)$row['source_job_id'],
                'subject_id' => $row['subject_id'] !== null
                    ? (int)$row['subject_id']
                    : null,
                'subject_fingerprint' =>
                    $row['subject_fingerprint'] !== null
                        ? (string)$row['subject_fingerprint']
                        : null,
                'input_hash' => (string)$row['input_hash'],
                'intent_kind' => (string)$row['intent_kind'],
                'activation_action' =>
                    (string)$row['intent_kind'] === 'validated_plan'
                        ? 'defer'
                        : 'apply',
                'response_hash' =>
                    $row['intent_response_hash'] !== null
                        ? (string)$row['intent_response_hash']
                        : (
                            $row['job_response_hash'] !== null
                                ? (string)$row['job_response_hash']
                                : null
                        ),
                'source_plan_hash' =>
                    ingredientOntologyActivationParsedPlanHash(
                        $row['intent_plan_json'] !== null
                            ? (string)$row['intent_plan_json']
                            : (
                                $row['job_plan_json'] !== null
                                    ? (string)$row['job_plan_json']
                                    : null
                            )
                    ),
                'plan_hash' => $row['plan_hash'] !== null
                    ? (string)$row['plan_hash']
                    : null,
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    function ingredientOntologyActivationBuildAcknowledgement(
        PDO $db,
        array $snapshot,
        array $intents
    ): ?array {
        if (!$intents) {
            return null;
        }
        $normalized = [];
        foreach ($intents as $intent) {
            if (!is_array($intent)) {
                throw new InvalidArgumentException(
                    'ontology activation acknowledgement intent is invalid'
                );
            }
            $jobId = (int)($intent['source_job_id'] ?? 0);
            $action = (string)($intent['activation_action'] ?? '');
            if (
                $jobId <= 0
                || !in_array($action, ['apply', 'defer'], true)
                || !preg_match(
                    '/^[a-f0-9]{64}$/D',
                    (string)($intent['input_hash'] ?? '')
                )
                || (
                    ($intent['subject_fingerprint'] ?? null) !== null
                    && !preg_match(
                        '/^[a-f0-9]{64}$/D',
                        (string)$intent['subject_fingerprint']
                    )
                )
                || (
                    ($intent['response_hash'] ?? null) !== null
                    && !preg_match(
                        '/^[a-f0-9]{64}$/D',
                        (string)$intent['response_hash']
                    )
                )
                || (
                    ($intent['source_plan_hash'] ?? null) !== null
                    && !preg_match(
                        '/^[a-f0-9]{64}$/D',
                        (string)$intent['source_plan_hash']
                    )
                )
                || (
                    ($intent['plan_hash'] ?? null) !== null
                    && !preg_match(
                        '/^[a-f0-9]{64}$/D',
                        (string)$intent['plan_hash']
                    )
                )
            ) {
                throw new InvalidArgumentException(
                    'ontology activation acknowledgement intent is invalid'
                );
            }
            if (isset($normalized[$jobId])) {
                if (!hash_equals(
                    ingredientOntologyV3Hash($normalized[$jobId]),
                    ingredientOntologyV3Hash($intent)
                )) {
                    throw new InvalidArgumentException(
                        'ontology activation acknowledgement intent is duplicated'
                    );
                }
                continue;
            }
            $normalized[$jobId] = $intent;
        }
        ksort($normalized, SORT_NUMERIC);
        $intents = array_values($normalized);
        $document = [
            'schema_version' =>
                INGREDIENT_ONTOLOGY_ACTIVATION_ACKNOWLEDGEMENT_VERSION,
            'created_at' => gmdate('c'),
            'database_lineage_uuid' =>
                (string)$snapshot['database_lineage_uuid'],
            'runtime_hash' => (string)$snapshot['runtime_hash'],
            'parent' => [
                'score_revision_id' =>
                    (int)$snapshot['state']['active_score_revision_id'],
                'ontology_version_id' =>
                    (int)($snapshot['active_version']['id'] ?? 0),
                'ontology_content_hash' => (string)(
                    $snapshot['active_version']['content_hash'] ?? ''
                ),
            ],
            'source_fence' => [
                'ontology_source_revision' =>
                    (int)$snapshot['state']['ontology_source_revision'],
                'cdc' => $snapshot['cdc'],
                'controller_state' => $snapshot['controller_state'],
            ],
            'proof' => [
                'kind' => 'copied_semantic_no_op_with_actions',
                'source_job_ids_hash' => ingredientOntologyV3Hash(
                    array_map(
                        static fn(array $intent): int =>
                            (int)$intent['source_job_id'],
                        $intents
                    )
                ),
                'intent_set_hash' => ingredientOntologyV3Hash($intents),
                'active_ontology_content_hash' => (string)(
                    $snapshot['active_version']['content_hash'] ?? ''
                ),
            ],
            'intents' => $intents,
        ];
        $document['document_hash'] =
            ingredientOntologyV3Hash($document);
        return $document;
    }

    function ingredientOntologyActivationAcknowledgeNoOp(
        PDO $db,
        array $document
    ): array {
        ingredientOntologyActivationAssertActiveDatabase($db);
        $expected = (string)($document['document_hash'] ?? '');
        $payload = $document;
        unset($payload['document_hash']);
        $schemaVersion = (string)($document['schema_version'] ?? '');
        $legacy = $schemaVersion ===
            'ontology-activation-acknowledgement-v1';
        $expectedProofKind = $legacy
            ? 'copied_semantic_no_op'
            : 'copied_semantic_no_op_with_actions';
        $intents = (array)($document['intents'] ?? []);
        if (
            !in_array($schemaVersion, [
                'ontology-activation-acknowledgement-v1',
                INGREDIENT_ONTOLOGY_ACTIVATION_ACKNOWLEDGEMENT_VERSION,
            ], true)
            || !preg_match('/^[a-f0-9]{64}$/D', $expected)
            || !hash_equals(ingredientOntologyV3Hash($payload), $expected)
            || !hash_equals(
                ingredientOntologyActivationLineageUuid($db),
                (string)($document['database_lineage_uuid'] ?? '')
            )
            || !hash_equals(
                ingredientOntologyActivationRuntimeHash(),
                (string)($document['runtime_hash'] ?? '')
            )
            || (string)($document['proof']['kind'] ?? '')
                !== $expectedProofKind
            || !hash_equals(
                (string)($document['proof']['source_job_ids_hash'] ?? ''),
                ingredientOntologyV3Hash(array_map(
                    static fn(array $intent): int =>
                        (int)$intent['source_job_id'],
                    $intents
                ))
            )
            || (
                !$legacy
                && !hash_equals(
                    (string)(
                        $document['proof']['intent_set_hash'] ?? ''
                    ),
                    ingredientOntologyV3Hash($intents)
                )
            )
        ) {
            throw new RuntimeException(
                'ontology activation acknowledgement is invalid'
            );
        }
        $state = recipeScoreState($db);
        $activeVersion = ingredientOntologyV3ActiveVersion($db);
        if (
            (int)($state['active_score_revision_id'] ?? 0)
                !== (int)$document['parent']['score_revision_id']
            || $activeVersion === null
            || (int)$activeVersion['id']
                !== (int)$document['parent']['ontology_version_id']
            || !hash_equals(
                (string)$activeVersion['content_hash'],
                (string)$document['parent']['ontology_content_hash']
            )
            || (int)$state['ontology_source_revision']
                !== (int)$document['source_fence'][
                    'ontology_source_revision'
                ]
        ) {
            ingredientOntologyActivationRecordOutcome(
                $db,
                'superseded_snapshot',
                [
                    'reason' => 'acknowledgement_parent_changed',
                    'expected_score_revision_id' =>
                        (int)$document['parent']['score_revision_id'],
                    'active_score_revision_id' =>
                        (int)($state['active_score_revision_id'] ?? 0),
                ],
                true
            );
            return [
                'applied' => false,
                'document_hash' => $expected,
                'intent_count' => count($intents),
                'applied_count' => 0,
                'deferred_count' => 0,
                'outcome' => 'superseded_snapshot',
                'reason' => 'acknowledgement_parent_changed',
                'policy_deferred' => false,
            ];
        }
        $cdc = ingredientOntologyActivationCdcSnapshot($db);
        foreach (['source', 'constraint', 'policy'] as $domain) {
            if (
                (int)$cdc[$domain]
                    !== (int)$document['source_fence']['cdc'][$domain]
            ) {
                throw new RuntimeException(
                    "ontology activation acknowledgement {$domain} changed"
                );
            }
        }
        $controller = ingredientOntologyActivationControllerState($db);
        foreach ([
            'constraint_epoch',
            'active_gold_release_id',
            'active_policy_hash',
        ] as $field) {
            if (
                (string)($controller[$field] ?? '')
                    !== (string)(
                        $document['source_fence']['controller_state'][$field]
                            ?? ''
                    )
            ) {
                throw new RuntimeException(
                    'ontology activation acknowledgement controller changed'
                );
            }
        }
        $json = ingredientOntologyActivationStableJson($document);
        if (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && is_callable(
                $GLOBALS[
                    'ONTOLOGY_ACTIVATION_BEFORE_ACK_RESERVATION'
                ] ?? null
            )
        ) {
            ($GLOBALS[
                'ONTOLOGY_ACTIVATION_BEFORE_ACK_RESERVATION'
            ])($db, $document);
        }
        dbBeginImmediateWithRetry($db);
        try {
            $lockedState = recipeScoreState($db);
            $lockedVersion = ingredientOntologyV3ActiveVersion($db);
            $lockedCdc = ingredientOntologyActivationCdcSnapshot($db);
            $lockedController =
                ingredientOntologyActivationControllerState($db);
            if (
                (int)($lockedState['active_score_revision_id'] ?? 0)
                    !== (int)$document['parent']['score_revision_id']
                || $lockedVersion === null
                || (int)$lockedVersion['id']
                    !== (int)$document['parent']['ontology_version_id']
                || !hash_equals(
                    (string)$lockedVersion['content_hash'],
                    (string)$document['parent']['ontology_content_hash']
                )
                || (int)$lockedState['ontology_source_revision']
                    !== (int)$document['source_fence'][
                        'ontology_source_revision'
                    ]
            ) {
                throw new RuntimeException(
                    'ontology activation acknowledgement reservation parent changed'
                );
            }
            foreach (['source', 'constraint', 'policy'] as $domain) {
                if (
                    (int)$lockedCdc[$domain]
                        !== (int)$document['source_fence']['cdc'][$domain]
                ) {
                    throw new RuntimeException(
                        "ontology activation acknowledgement reservation {$domain} changed"
                    );
                }
            }
            foreach ([
                'constraint_epoch',
                'active_gold_release_id',
                'active_policy_hash',
            ] as $field) {
                if (
                    (string)($lockedController[$field] ?? '')
                        !== (string)(
                            $document['source_fence']['controller_state'][
                                $field
                            ] ?? ''
                        )
                ) {
                    throw new RuntimeException(
                        'ontology activation acknowledgement reservation controller changed'
                    );
                }
            }
            $db->prepare("
                INSERT INTO ontology_activation_acknowledgements (
                    document_hash, document_json
                )
                VALUES (?, ?)
                ON CONFLICT(document_hash) DO NOTHING
            ")->execute([$expected, $json]);
            $appliedCount = 0;
            $deferredCount = 0;
            foreach ($intents as $intent) {
                $jobId = (int)$intent['source_job_id'];
                $action = $legacy
                    ? 'apply'
                    : (string)($intent['activation_action'] ?? '');
                if (!in_array($action, ['apply', 'defer'], true)) {
                    throw new RuntimeException(
                        'ontology activation acknowledgement action is invalid'
                    );
                }
                $live = $db->prepare("
                    SELECT intent.status, intent.intent_kind,
                           job.input_hash,
                           intent_response.response_hash
                               AS intent_response_hash,
                           intent_response.parsed_plan_json
                               AS intent_plan_json,
                           response.response_hash AS job_response_hash,
                           response.parsed_plan_json AS job_plan_json,
                           source_plan.plan_hash AS source_plan_hash,
                           subject.subject_fingerprint
                    FROM ontology_generation_intents intent
                    JOIN ontology_controller_jobs job
                      ON job.id = intent.source_job_id
                    LEFT JOIN ontology_controller_responses response
                      ON response.id = job.response_artifact_id
                    LEFT JOIN ontology_controller_responses intent_response
                      ON intent_response.id = intent.response_artifact_id
                    LEFT JOIN ontology_subjects subject
                      ON subject.id = job.subject_id
                    LEFT JOIN ontology_mutation_plans source_plan
                      ON source_plan.job_id = job.id
                    WHERE intent.source_job_id = ?
                    ORDER BY source_plan.id DESC
                    LIMIT 1
                ");
                $live->execute([$jobId]);
                $row = $live->fetch(PDO::FETCH_ASSOC);
                if (
                    !$row
                    || !in_array(
                        (string)$row['status'],
                        ['pending', 'queued'],
                        true
                    )
                    || (string)$row['intent_kind']
                        !== (string)$intent['intent_kind']
                    || !hash_equals(
                        (string)$row['input_hash'],
                        (string)$intent['input_hash']
                    )
                    || (
                        $intent['response_hash'] !== null
                        && !hash_equals(
                            (string)(
                                $row['intent_response_hash']
                                    ?? $row['job_response_hash']
                                    ?? ''
                            ),
                            (string)$intent['response_hash']
                        )
                    )
                    || (
                        !$legacy
                        && ($intent['source_plan_hash'] ?? null) !== null
                        && !hash_equals(
                            (string)$intent['source_plan_hash'],
                            (string)(
                                ingredientOntologyActivationParsedPlanHash(
                                    $row['intent_plan_json'] !== null
                                        ? (string)$row['intent_plan_json']
                                        : (
                                            $row['job_plan_json'] !== null
                                                ? (string)$row['job_plan_json']
                                                : null
                                        )
                                ) ?? ''
                            )
                        )
                    )
                    || (
                        !$legacy
                        && $action === 'apply'
                        && ($intent['plan_hash'] ?? null) !== null
                        && $row['source_plan_hash'] !== null
                        && !hash_equals(
                            (string)$row['source_plan_hash'],
                            (string)$intent['plan_hash']
                        )
                    )
                    || (
                        $intent['subject_fingerprint'] !== null
                        && !hash_equals(
                            (string)($row['subject_fingerprint'] ?? ''),
                            (string)$intent['subject_fingerprint']
                        )
                    )
                ) {
                    throw new RuntimeException(
                        'ontology activation acknowledgement intent changed'
                    );
                }
                if ($action === 'apply') {
                    $intentUpdate = $db->prepare("
                        UPDATE ontology_generation_intents
                        SET status = 'applied',
                            last_error = '',
                            finished_at = CURRENT_TIMESTAMP,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE source_job_id = ?
                          AND status IN ('pending', 'queued')
                    ");
                    $intentUpdate->execute([$jobId]);
                    $appliedCount++;
                } else {
                    $intentUpdate = $db->prepare("
                        UPDATE ontology_generation_intents
                        SET status = 'pending',
                            attempts = attempts + 1,
                            last_error =
                                'Validated plan deferred; copied semantic fallback is already active.',
                            finished_at = NULL,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE source_job_id = ?
                          AND status IN ('pending', 'queued')
                    ");
                    $intentUpdate->execute([$jobId]);
                    $deferredCount++;
                }
                if ($intentUpdate->rowCount() !== 1) {
                    throw new RuntimeException(
                        'ontology activation acknowledgement intent CAS failed'
                    );
                }
                $jobUpdate = $db->prepare("
                    UPDATE ontology_controller_jobs
                    SET candidate_version_id = ?,
                        candidate_score_revision_id = ?,
                        next_attempt_at = CASE
                            WHEN ? = 'defer'
                            THEN datetime('now', '+24 hours')
                            ELSE next_attempt_at
                        END,
                        last_error_kind = CASE
                            WHEN ? = 'defer'
                            THEN 'generation_policy_deferred'
                            ELSE last_error_kind
                        END,
                        last_error = CASE
                            WHEN ? = 'defer'
                            THEN 'Validated plan awaits policy or evidence change; copied semantic fallback is active.'
                            ELSE last_error
                        END,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $jobUpdate->execute([
                    (int)$activeVersion['id'],
                    (int)$state['active_score_revision_id'],
                    $action,
                    $action,
                    $action,
                    $jobId,
                ]);
                if ($jobUpdate->rowCount() !== 1) {
                    throw new RuntimeException(
                        'ontology activation acknowledgement job CAS failed'
                    );
                }
            }
            $db->prepare("
                UPDATE ontology_activation_acknowledgements
                SET status = 'applied',
                    applied_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE document_hash = ?
            ")->execute([$expected]);
            ingredientOntologyActivationRecordOutcome(
                $db,
                'no_op_acknowledged',
                [
                    'document_hash' => $expected,
                    'intent_count' => count($intents),
                    'applied_count' => $appliedCount,
                    'deferred_count' => $deferredCount,
                    'policy_deferred' => $deferredCount > 0,
                ],
                true
            );
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $error;
        }
        return [
            'applied' => true,
            'document_hash' => $expected,
            'intent_count' => count($intents),
            'applied_count' => $appliedCount,
            'deferred_count' => $deferredCount,
            'outcome' => 'no_op_acknowledged',
            'policy_deferred' => $deferredCount > 0,
        ];
    }

    function ingredientOntologyActivationBundleDocument(
        string $kind,
        array $snapshot,
        array $payload,
        array $parent,
        array $candidate,
        array $sourceFence,
        array $intents,
        array $attestation
    ): array {
        $document = [
            'schema_version' =>
                INGREDIENT_ONTOLOGY_ACTIVATION_BUNDLE_VERSION,
            'bundle_kind' => $kind,
            'created_at' => gmdate('c'),
            'database_lineage_uuid' =>
                (string)$snapshot['database_lineage_uuid'],
            'runtime_hash' => (string)$snapshot['runtime_hash'],
            'database_schema_version' =>
                $snapshot['database_schema_version'],
            'snapshot' => $snapshot,
            'parent' => $parent,
            'candidate' => $candidate,
            'source_fence' => $sourceFence,
            'payload' => [
                'file' => (string)$payload['file'],
                'sha256' => (string)$payload['sha256'],
                'bytes' => (int)$payload['bytes'],
            ],
            'tables' => $payload['tables'],
            'intents' => $intents,
            'attestation' => $attestation,
        ];
        $document['bundle_hash'] = ingredientOntologyV3Hash($document);
        return $document;
    }

    function ingredientOntologyActivationBuildGenerationBundleSet(
        PDO $db,
        int $generationId,
        array $snapshot,
        string $payloadDirectory,
        array $options = []
    ): array {
        ingredientOntologyControllerAssertCopiedGenerationDatabase($db);
        $generationStmt = $db->prepare("
            SELECT * FROM ontology_generations WHERE id = ?
        ");
        $generationStmt->execute([$generationId]);
        $generation = $generationStmt->fetch(PDO::FETCH_ASSOC);
        if (
            !$generation
            || !in_array(
                (string)$generation['status'],
                ['promotable', 'promoted'],
                true
            )
        ) {
            throw new RuntimeException(
                'ontology activation generation is not bundle-ready'
            );
        }
        $parentVersionId =
            (int)$generation['parent_ontology_version_id'];
        $candidateVersionId = (int)$generation['candidate_version_id'];
        $parentScoreId = (int)$generation['parent_score_revision_id'];
        $candidateScoreId =
            (int)$generation['candidate_score_revision_id'];
        $parentVersion = ingredientOntologyV3Version($db, $parentVersionId);
        $candidateVersion = ingredientOntologyV3Version(
            $db,
            $candidateVersionId
        );
        $parentScore = recipeScoreRevision($db, $parentScoreId);
        $candidateScore = recipeScoreRevision($db, $candidateScoreId);
        if (
            $parentVersion === null
            || $candidateVersion === null
            || $parentScore === null
            || $candidateScore === null
            || (string)$candidateVersion['status'] !== 'ready'
            || (string)$candidateScore['status'] !== 'ready'
        ) {
            throw new RuntimeException(
                'ontology activation candidate artifacts are incomplete'
            );
        }
        $testFixture = defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && !empty($options['allow_test_fixture']);
        $scoreValidation = $testFixture
            ? [
                'valid' => true,
                'errors' => [],
                'test_fixture' => true,
            ]
            : ingredientOntologyV3ValidateActivation(
                $db,
                $candidateScoreId
            );
        ingredientOntologyActivationAssertScoreValidation(
            $scoreValidation,
            'ontology activation score attestation failed',
            'superseded_snapshot',
            [
                'generation_id' => $generationId,
                'candidate_score_revision_id' => $candidateScoreId,
            ]
        );
        $generationKey = (string)$generation['generation_key'];
        $ontologyPayload = ingredientOntologyActivationCreatePayload(
            $db,
            'ontology',
            $candidateVersionId,
            ingredientOntologyActivationOntologyTableSpecs(),
            $snapshot,
            $payloadDirectory,
            'ontology-' . $generationKey . '-'
                . bin2hex(random_bytes(8)) . '.sqlite'
        );
        $scorePayload = ingredientOntologyActivationCreatePayload(
            $db,
            'score',
            $candidateScoreId,
            ingredientOntologyActivationScoreTableSpecs(),
            $snapshot,
            $payloadDirectory,
            'score-' . $generationKey . '-'
                . bin2hex(random_bytes(8)) . '.sqlite'
        );
        $intents = ingredientOntologyActivationGenerationIntents(
            $db,
            $generationId,
            $candidateVersionId
        );
        $ontologyAttestation = [
            'generation_key' => $generationKey,
            'controller_generation' =>
                (int)$generation['controller_generation'],
            'constraint_epoch' => (int)$generation['constraint_epoch'],
            'constraint_hash' => (string)$generation['constraint_hash'],
            'controller_policy_hash' =>
                (string)$generation['controller_policy_hash'],
            'risk_summary' => json_decode(
                (string)$generation['risk_summary_json'],
                true
            ),
            'blast_report' => json_decode(
                (string)$generation['blast_report_json'],
                true
            ),
            'gate_report' => json_decode(
                (string)$generation['gate_report_json'],
                true
            ),
            'critique' => json_decode(
                (string)$generation['critique_json'],
                true
            ),
            'version_validation' => json_decode(
                (string)$candidateVersion['validation_report_json'],
                true
            ),
        ];
        $ontologyBundle = ingredientOntologyActivationBundleDocument(
            'ontology',
            $snapshot,
            $ontologyPayload,
            [
                'ontology_version_id' => $parentVersionId,
                'content_hash' => (string)$parentVersion['content_hash'],
                'portable_content_hash' =>
                    (string)$parentVersion['portable_content_hash'],
                'seal_hash' => (string)$parentVersion['seal_hash'],
                'controller_seal_hash' =>
                    (string)$parentVersion['controller_seal_hash'],
                'score_revision_id' => $parentScoreId,
            ],
            [
                'ontology_version_id' => $candidateVersionId,
                'content_hash' => (string)$candidateVersion['content_hash'],
                'portable_content_hash' =>
                    (string)$candidateVersion['portable_content_hash'],
                'seal_hash' => (string)$candidateVersion['seal_hash'],
                'controller_seal_hash' =>
                    (string)$candidateVersion['controller_seal_hash'],
                'corpus_hash' => (string)$candidateVersion['corpus_hash'],
                'frozen_corpus_hash' =>
                    (string)$candidateVersion['frozen_corpus_hash'],
                'frozen_subjects_hash' =>
                    (string)$candidateVersion['frozen_subjects_hash'],
            ],
            [
                'active_score_revision_id' => $parentScoreId,
                'ontology_source_revision' =>
                    (int)$candidateScore['ontology_source_revision'],
                'ontology_source_hash' =>
                    (string)$candidateScore['ontology_source_hash'],
                'cdc' => $snapshot['cdc'],
            ],
            $intents,
            $ontologyAttestation
        );
        $scoreBundle = ingredientOntologyActivationBundleDocument(
            'score',
            $snapshot,
            $scorePayload,
            [
                'score_revision_id' => $parentScoreId,
                'ontology_version_id' => $parentVersionId,
                'materialization_hash' =>
                    (string)$parentScore['materialization_hash'],
            ],
            [
                'score_revision_id' => $candidateScoreId,
                'ontology_version_id' => $candidateVersionId,
                'inventory_fingerprint' =>
                    (string)$candidateScore['inventory_fingerprint'],
                'catalog_fingerprint' =>
                    (string)$candidateScore['catalog_fingerprint'],
                'ontology_source_hash' =>
                    (string)$candidateScore['ontology_source_hash'],
                'catalog_id_set_hash' =>
                    (string)$candidateScore['catalog_id_set_hash'],
                'ingredient_id_set_hash' =>
                    (string)$candidateScore['ingredient_id_set_hash'],
                'score_rows_hash' =>
                    (string)$candidateScore['score_rows_hash'],
                'match_rows_hash' =>
                    (string)$candidateScore['match_rows_hash'],
                'materialization_hash' =>
                    (string)$candidateScore['materialization_hash'],
                'score_date' => (string)$candidateScore['score_date'],
            ],
            [
                'active_score_revision_id' => $parentScoreId,
                'inventory_revision' =>
                    (int)$candidateScore['inventory_revision'],
                'catalog_revision' =>
                    (int)$candidateScore['catalog_revision'],
                'ontology_source_revision' =>
                    (int)$candidateScore['ontology_source_revision'],
                'ontology_source_hash' =>
                    (string)$candidateScore['ontology_source_hash'],
                'cdc' => $snapshot['cdc'],
            ],
            $intents,
            [
                'validation' => $scoreValidation,
                'score_report' => json_decode(
                    (string)$candidateScore['validation_report_json'],
                    true
                ),
            ]
        );
        $bundleSet = [
            'schema_version' =>
                'ontology-activation-bundle-set-v2',
            'created_at' => gmdate('c'),
            'generation_id' => $generationId,
            'generation_key' => $generationKey,
            'ontology' => $ontologyBundle,
            'score' => $scoreBundle,
        ];
        $bundleSet['bundle_set_hash'] =
            ingredientOntologyV3Hash($bundleSet);
        return $bundleSet;
    }

    function ingredientOntologyActivationBuildRefreshBundleSet(
        PDO $db,
        array $snapshot,
        string $payloadDirectory,
        array $options = []
    ): array {
        ingredientOntologyControllerAssertCopiedGenerationDatabase($db);
        $state = recipeScoreState($db);
        $parentScoreId = (int)($state['active_score_revision_id'] ?? 0);
        $parentScore = recipeScoreRevision($db, $parentScoreId);
        $parentVersion = ingredientOntologyV3ActiveVersion($db);
        if ($parentScore === null || $parentVersion === null) {
            throw new RuntimeException(
                'ontology refresh parent is unavailable'
            );
        }
        $constraintEpoch = (int)$db->query("
            SELECT constraint_epoch
            FROM ontology_controller_state
            WHERE id = 1
        ")->fetchColumn();
        $constraintHash = ingredientOntologyControllerConstraintHash(
            $db,
            $constraintEpoch
        );
        $generationKey = ingredientOntologyV3Hash([
            'kind' => 'source_refresh',
            'parent_version_id' => (int)$parentVersion['id'],
            'parent_score_revision_id' => $parentScoreId,
            'ontology_source_revision' =>
                (int)$state['ontology_source_revision'],
            'corpus_hash' => ingredientOntologyV3CorpusHash($db),
            'constraint_hash' => $constraintHash,
            'policy_hash' => ingredientOntologyControllerPolicyHash(),
        ]);
        $reviewedManifestRefresh =
            ingredientOntologyActivationShouldRebuildReviewedManifest(
                $db,
                $options
            );
        if ($reviewedManifestRefresh) {
            $candidate = ingredientOntologyV3BuildCandidate(
                $db,
                [
                    'model' => (string)$parentVersion['model_name'],
                    'corpus_profile' =>
                        (string)$parentVersion['corpus_profile'],
                    'version' => 'v3-reviewed-'
                        . gmdate('Ymd-His') . '-'
                        . substr(
                            (string)ingredientOntologyV3ResolutionManifest()[
                                'manifest_hash'
                            ],
                            0,
                            10
                        ),
                    'parent_version_id' => (int)$parentVersion['id'],
                    'dynamic_controller' => true,
                    'controller_base_content_hash' =>
                        (string)$parentVersion['content_hash'],
                    'controller_constraint_epoch' => $constraintEpoch,
                    'controller_constraint_hash' => $constraintHash,
                    'controller_policy_hash' =>
                        ingredientOntologyControllerPolicyHash(),
                    'controller_generation_key' => $generationKey,
                ]
            );
            $candidateVersionId = (int)$candidate['version_id'];
        } else {
            $fork = ingredientOntologyControllerChunkedFork(
                $db,
                (int)$parentVersion['id'],
                [
                    'generation_key' => $generationKey,
                    'constraint_epoch' => $constraintEpoch,
                    'constraint_hash' => $constraintHash,
                    'controller_policy_hash' =>
                        ingredientOntologyControllerPolicyHash(),
                    'activation_policy' => 'autonomous',
                ]
            );
            $candidateVersionId = (int)$fork['version_id'];
        }
        $candidateVersion = ingredientOntologyV3Version(
            $db,
            $candidateVersionId
        );
        if (
            $candidateVersion !== null
            && (string)$candidateVersion['status'] === 'building'
        ) {
            ingredientOntologyControllerMaterializeMissingOwnerMappings(
                $db,
                $candidateVersionId
            );
            ingredientOntologyControllerMaterializeConstraints(
                $db,
                $candidateVersionId,
                $constraintEpoch
            );
            $db->prepare("
                UPDATE ingredient_ontology_versions
                SET controller_constraint_epoch = ?,
                    controller_constraint_hash = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'building'
            ")->execute([
                $constraintEpoch,
                $constraintHash,
                $candidateVersionId,
            ]);
            ingredientOntologyControllerSealVersion(
                $db,
                $candidateVersionId,
                [
                    'allow_test_fixture' =>
                        !empty($options['allow_test_fixture']),
                ]
            );
        }
        $shadow = ingredientOntologyV3BuildShadow(
            $db,
            $candidateVersionId,
            max(1, min(1000, (int)($options['batch_size'] ?? 250)))
        );
        if (empty($shadow['built'])) {
            throw new RuntimeException(
                'ontology refresh shadow build did not complete'
            );
        }
        $candidateScoreId = (int)$shadow['revision_id'];
        $testFixture = defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && !empty($options['allow_test_fixture']);
        $validation = $testFixture
            ? ['valid' => true, 'errors' => [], 'test_fixture' => true]
            : ingredientOntologyV3ValidateActivation(
                $db,
                $candidateScoreId
            );
        ingredientOntologyActivationAssertScoreValidation(
            $validation,
            'ontology refresh validation failed',
            'rebase_required',
            [
                'candidate_score_revision_id' => $candidateScoreId,
            ]
        );
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->exec("
                UPDATE ontology_controller_state
                SET controller_generation = controller_generation + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = 1
            ");
            $controllerGeneration = (int)$db->query("
                SELECT controller_generation
                FROM ontology_controller_state
                WHERE id = 1
            ")->fetchColumn();
            $db->prepare("
                INSERT INTO ontology_generations (
                    generation_key, controller_generation,
                    parent_ontology_version_id,
                    parent_score_revision_id, constraint_epoch,
                    constraint_hash, controller_policy_hash,
                    candidate_version_id, candidate_score_revision_id,
                    status, risk_summary_json, blast_report_json,
                    gate_report_json
                )
                VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    'promotable', '{}', ?, ?
                )
            ")->execute([
                $generationKey,
                $controllerGeneration,
                (int)$parentVersion['id'],
                $parentScoreId,
                $constraintEpoch,
                $constraintHash,
                ingredientOntologyControllerPolicyHash(),
                $candidateVersionId,
                $candidateScoreId,
                ingredientOntologyActivationStableJson([
                    'valid' => true,
                    'refresh_only' => true,
                    'changed_recipe_count' => 0,
                ]),
                ingredientOntologyActivationStableJson([
                    'valid' => true,
                    'refresh_only' => true,
                    'validation' => $validation,
                ]),
            ]);
            $generationId = (int)$db->lastInsertId();
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $error;
        }
        return ingredientOntologyActivationBuildGenerationBundleSet(
            $db,
            $generationId,
            $snapshot,
            $payloadDirectory,
            $options
        );
    }

    function ingredientOntologyActivationBuildScoreBundle(
        PDO $db,
        int $ontologyVersionId,
        array $snapshot,
        string $payloadDirectory,
        array $intents = [],
        array $options = []
    ): array {
        ingredientOntologyControllerAssertCopiedGenerationDatabase($db);
        $version = ingredientOntologyV3Version($db, $ontologyVersionId);
        if ($version === null || (string)$version['status'] !== 'ready') {
            throw new InvalidArgumentException(
                'score bundle requires a ready ontology version'
            );
        }
        $state = recipeScoreState($db);
        $parentScoreId = (int)($state['active_score_revision_id'] ?? 0);
        $parentScore = recipeScoreRevision($db, $parentScoreId);
        $parentVersion = ingredientOntologyV3ActiveVersion($db);
        if ($parentScore === null || $parentVersion === null) {
            throw new RuntimeException(
                'score bundle parent is unavailable'
            );
        }
        if (
            $ontologyVersionId !== (int)$parentVersion['id']
            && (int)($version['parent_version_id'] ?? 0)
                !== (int)$parentVersion['id']
        ) {
            throw new RuntimeException(
                'score bundle ontology parent changed'
            );
        }
        $built = ingredientOntologyV3BuildShadow(
            $db,
            $ontologyVersionId,
            max(1, min(1000, (int)($options['batch_size'] ?? 250)))
        );
        if (empty($built['built'])) {
            throw new RuntimeException(
                'score bundle shadow build failed: '
                . (string)($built['reason'] ?? 'unknown')
            );
        }
        $scoreId = (int)$built['revision_id'];
        $score = recipeScoreRevision($db, $scoreId);
        if ($score === null || (string)$score['status'] !== 'ready') {
            throw new RuntimeException(
                'score bundle revision is incomplete'
            );
        }
        $testFixture = defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && !empty($options['allow_test_fixture']);
        $validation = $testFixture
            ? ['valid' => true, 'errors' => [], 'test_fixture' => true]
            : ingredientOntologyV3ValidateActivation($db, $scoreId);
        ingredientOntologyActivationAssertScoreValidation(
            $validation,
            'score bundle validation failed',
            'rebase_required',
            [
                'candidate_score_revision_id' => $scoreId,
            ]
        );
        $key = ingredientOntologyV3Hash([
            'ontology_version_id' => $ontologyVersionId,
            'score_revision_id' => $scoreId,
            'parent_score_revision_id' => $parentScoreId,
            'inventory_revision' => (int)$score['inventory_revision'],
            'catalog_revision' => (int)$score['catalog_revision'],
            'ontology_source_revision' =>
                (int)$score['ontology_source_revision'],
        ]);
        $payload = ingredientOntologyActivationCreatePayload(
            $db,
            'score',
            $scoreId,
            ingredientOntologyActivationScoreTableSpecs(),
            $snapshot,
            $payloadDirectory,
            'score-refresh-' . $key . '-'
                . bin2hex(random_bytes(8)) . '.sqlite'
        );
        return ingredientOntologyActivationBundleDocument(
            'score',
            $snapshot,
            $payload,
            [
                'score_revision_id' => $parentScoreId,
                'ontology_version_id' => (int)$parentVersion['id'],
                'materialization_hash' =>
                    (string)$parentScore['materialization_hash'],
            ],
            [
                'score_revision_id' => $scoreId,
                'ontology_version_id' => $ontologyVersionId,
                'inventory_fingerprint' =>
                    (string)$score['inventory_fingerprint'],
                'catalog_fingerprint' =>
                    (string)$score['catalog_fingerprint'],
                'ontology_source_hash' =>
                    (string)$score['ontology_source_hash'],
                'catalog_id_set_hash' =>
                    (string)$score['catalog_id_set_hash'],
                'ingredient_id_set_hash' =>
                    (string)$score['ingredient_id_set_hash'],
                'score_rows_hash' => (string)$score['score_rows_hash'],
                'match_rows_hash' => (string)$score['match_rows_hash'],
                'materialization_hash' =>
                    (string)$score['materialization_hash'],
                'score_date' => (string)$score['score_date'],
            ],
            [
                'active_score_revision_id' => $parentScoreId,
                'inventory_revision' => (int)$score['inventory_revision'],
                'catalog_revision' => (int)$score['catalog_revision'],
                'ontology_source_revision' =>
                    (int)$score['ontology_source_revision'],
                'ontology_source_hash' =>
                    (string)$score['ontology_source_hash'],
                'cdc' => $snapshot['cdc'],
            ],
            $intents,
            [
                'validation' => $validation,
                'score_report' => json_decode(
                    (string)$score['validation_report_json'],
                    true
                ),
            ]
        );
    }

    function ingredientOntologyActivationVerifyBundle(array $bundle): void {
        $expected = (string)($bundle['bundle_hash'] ?? '');
        $payload = $bundle;
        unset($payload['bundle_hash']);
        if (
            !preg_match('/^[a-f0-9]{64}$/D', $expected)
            || !hash_equals(ingredientOntologyV3Hash($payload), $expected)
        ) {
            throw new InvalidArgumentException(
                'ontology activation bundle hash is invalid'
            );
        }
        if (
            (string)($bundle['schema_version'] ?? '')
                !== INGREDIENT_ONTOLOGY_ACTIVATION_BUNDLE_VERSION
            || !in_array(
                (string)($bundle['bundle_kind'] ?? ''),
                ['ontology', 'score'],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'ontology activation bundle schema is unsupported'
            );
        }
    }

    function ingredientOntologyActivationRegisterGuardFunctions(PDO $db): void {
        if (function_exists('ingredientOntologyV3RegisterGuardFunctions')) {
            ingredientOntologyV3RegisterGuardFunctions($db);
        }
    }

function ingredientOntologyActivationAssertActiveDatabase(PDO $db): void {
        ingredientOntologyActivationRegisterGuardFunctions($db);
        if (
            function_exists('ingredientOntologyControllerDatabaseIsActive')
            && !ingredientOntologyControllerDatabaseIsActive($db)
        ) {
            throw new RuntimeException(
                'ontology_activation_import_requires_active_database'
            );
        }
    }

    function ingredientOntologyActivationResolvePayload(
        array $bundle,
        string $payloadDirectory
    ): string {
        $payload = is_array($bundle['payload'] ?? null)
            ? $bundle['payload']
            : [];
        $filename = basename((string)($payload['file'] ?? ''));
        if (
            $filename === ''
            || !preg_match('/^[A-Za-z0-9._-]+$/D', $filename)
        ) {
            throw new InvalidArgumentException(
                'ontology activation payload filename is invalid'
            );
        }
        $directory = realpath($payloadDirectory);
        if (
            $directory === false
            || !is_dir($directory)
            || is_link($payloadDirectory)
        ) {
            throw new InvalidArgumentException(
                'ontology activation payload directory is invalid'
            );
        }
        $path = $directory . '/' . $filename;
        if (!is_file($path) || is_link($path)) {
            throw new InvalidArgumentException(
                'ontology activation payload is unavailable'
            );
        }
        clearstatcache(true, $path);
        if (
            (int)filesize($path) !== (int)($payload['bytes'] ?? -1)
            || !hash_equals(
                (string)($payload['sha256'] ?? ''),
                (string)hash_file('sha256', $path)
            )
            || is_file($path . '-wal')
            || is_file($path . '-shm')
        ) {
            throw new RuntimeException(
                'ontology activation payload integrity failed'
            );
        }
        return $path;
    }

    function ingredientOntologyActivationUseVerifiedPayload(
        array $bundle,
        string $payloadDirectory,
        ?string $verifiedPath
    ): string {
        if ($verifiedPath === null) {
            return ingredientOntologyActivationResolvePayload(
                $bundle,
                $payloadDirectory
            );
        }
        $payload = is_array($bundle['payload'] ?? null)
            ? $bundle['payload']
            : [];
        $filename = basename((string)($payload['file'] ?? ''));
        $directory = realpath($payloadDirectory);
        $path = realpath($verifiedPath);
        if (
            $filename === ''
            || $directory === false
            || $path === false
            || $path !== $directory . '/' . $filename
            || !is_file($path)
            || is_link($path)
        ) {
            throw new InvalidArgumentException(
                'ontology activation verified payload path is invalid'
            );
        }
        clearstatcache(true, $path);
        if (
            (int)filesize($path) !== (int)($payload['bytes'] ?? -1)
            || is_file($path . '-wal')
            || is_file($path . '-shm')
        ) {
            throw new RuntimeException(
                'ontology activation verified payload changed'
            );
        }
        return $path;
    }

    function ingredientOntologyActivationSpecsForKind(
        string $kind
    ): array {
        $specs = $kind === 'ontology'
            ? ingredientOntologyActivationOntologyTableSpecs()
            : ingredientOntologyActivationScoreTableSpecs();
        $byTable = [];
        foreach ($specs as $spec) {
            $byTable[(string)$spec['table']] = $spec;
        }
        return $byTable;
    }

    function ingredientOntologyActivationCandidateId(
        array $bundle
    ): int {
        return (int)(
            (string)$bundle['bundle_kind'] === 'ontology'
                ? ($bundle['candidate']['ontology_version_id'] ?? 0)
                : ($bundle['candidate']['score_revision_id'] ?? 0)
        );
    }

    function ingredientOntologyActivationReconcileScoreIdentityExtension(
        PDO $db,
        int $scoreRevisionId,
        bool $requireScorePrefix
    ): array {
        $score = recipeScoreRevision($db, $scoreRevisionId);
        if ($score === null) {
            throw new RuntimeException(
                'score identity extension root is unavailable'
            );
        }
        return ingredientOntologyV3IdentityExtensionReconcileState(
            $db,
            (int)$score['ontology_version_id'],
            $requireScorePrefix
                ? (int)$score['identity_extension_revision']
                : null,
            $requireScorePrefix
                ? (string)$score['identity_extension_hash']
                : null
        );
    }

    function ingredientOntologyActivationImportRow(
        PDO $db,
        int $importId
    ): array {
        $stmt = $db->prepare("
            SELECT * FROM ontology_activation_imports WHERE id = ?
        ");
        $stmt->execute([$importId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException(
                'ontology activation import is unavailable'
            );
        }
        return $row;
    }

    function ingredientOntologyActivationImportBundle(array $row): array {
        $bundle = json_decode(
            (string)$row['manifest_json'],
            true,
            128,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($bundle)) {
            throw new RuntimeException(
                'ontology activation import manifest is invalid'
            );
        }
        ingredientOntologyActivationVerifyBundle($bundle);
        return $bundle;
    }

    function ingredientOntologyActivationEnsureLiveIntent(
        PDO $db,
        array $intent
    ): void {
        $sourceJobId = (int)$intent['source_job_id'];
        $liveIntent = $db->prepare("
            SELECT source_job_id
            FROM ontology_generation_intents
            WHERE source_job_id = ?
        ");
        $liveIntent->execute([$sourceJobId]);
        if ($liveIntent->fetchColumn()) {
            return;
        }
        $source = $db->prepare("
            SELECT job.subject_id, job.input_hash,
                   job.response_artifact_id,
                   subject.subject_fingerprint
            FROM ontology_controller_jobs job
            LEFT JOIN ontology_subjects subject
              ON subject.id = job.subject_id
            WHERE job.id = ?
        ");
        $source->execute([$sourceJobId]);
        $source = $source->fetch(PDO::FETCH_ASSOC);
        $responseArtifactId = $source['response_artifact_id'] ?? null;
        $responseHash = null;
        if ($intent['response_hash'] !== null) {
            $response = $db->prepare("
                SELECT response.id, response.response_hash
                FROM ontology_controller_responses response
                JOIN ontology_controller_prompts prompt
                  ON prompt.id = response.prompt_artifact_id
                WHERE prompt.job_id = ?
                  AND response.response_hash = ?
                ORDER BY response.id DESC
                LIMIT 1
            ");
            $response->execute([
                $sourceJobId,
                (string)$intent['response_hash'],
            ]);
            $response = $response->fetch(PDO::FETCH_ASSOC);
            if ($response) {
                $responseArtifactId = (int)$response['id'];
                $responseHash = (string)$response['response_hash'];
            }
        }
        if (
            !$source
            || !hash_equals(
                (string)$source['input_hash'],
                (string)$intent['input_hash']
            )
            || (
                $intent['subject_fingerprint'] !== null
                && !hash_equals(
                    (string)($source['subject_fingerprint'] ?? ''),
                    (string)$intent['subject_fingerprint']
                )
            )
            || (
                $intent['response_hash'] !== null
                && !hash_equals(
                    (string)($responseHash ?? ''),
                    (string)$intent['response_hash']
                )
            )
        ) {
            throw new RuntimeException(
                'ontology activation missing intent source changed'
            );
        }
        $db->prepare("
            INSERT INTO ontology_generation_intents (
                source_job_id, subject_id, intent_kind,
                response_artifact_id
            )
            VALUES (?, ?, ?, ?)
        ")->execute([
            $sourceJobId,
            $source['subject_id'] !== null
                ? (int)$source['subject_id']
                : null,
            (string)$intent['intent_kind'],
            $responseArtifactId !== null
                ? (int)$responseArtifactId
                : null,
        ]);
    }

    function ingredientOntologyActivationRegisterImport(
        PDO $db,
        array $bundle,
        string $payloadDirectory,
        ?string $verifiedPayloadPath = null
    ): array {
        ingredientOntologyActivationAssertActiveDatabase($db);
        ingredientOntologyActivationVerifyBundle($bundle);
        $kind = (string)$bundle['bundle_kind'];
        $bundleHash = (string)$bundle['bundle_hash'];
        $existing = $db->prepare("
            SELECT * FROM ontology_activation_imports
            WHERE bundle_hash = ?
        ");
        $existing->execute([$bundleHash]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            ingredientOntologyActivationUseVerifiedPayload(
                $bundle,
                $payloadDirectory,
                $verifiedPayloadPath
            );
            if (
                !hash_equals(
                    ingredientOntologyActivationLineageUuid($db),
                    (string)($bundle['database_lineage_uuid'] ?? '')
                )
                || !hash_equals(
                    ingredientOntologyActivationRuntimeHash(),
                    (string)($bundle['runtime_hash'] ?? '')
                )
                || (
                    defined('EVERSHELF_DATABASE_SCHEMA_VERSION')
                    && (int)($bundle['database_schema_version'] ?? -1)
                        !== EVERSHELF_DATABASE_SCHEMA_VERSION
                )
            ) {
                throw new RuntimeException(
                    'ontology activation bundle lineage or runtime changed'
                );
            }
            dbBeginImmediateWithRetry($db);
            try {
                foreach ((array)($bundle['intents'] ?? []) as $intent) {
                    ingredientOntologyActivationEnsureLiveIntent(
                        $db,
                        $intent
                    );
                }
                $db->exec('COMMIT');
            } catch (Throwable $error) {
                try {
                    $db->exec('ROLLBACK');
                } catch (Throwable $ignored) {
                }
                throw $error;
            }
            return $row;
        }
        $payloadPath = ingredientOntologyActivationUseVerifiedPayload(
            $bundle,
            $payloadDirectory,
            $verifiedPayloadPath
        );
        if (
            !hash_equals(
                ingredientOntologyActivationLineageUuid($db),
                (string)($bundle['database_lineage_uuid'] ?? '')
            )
            || !hash_equals(
                ingredientOntologyActivationRuntimeHash(),
                (string)($bundle['runtime_hash'] ?? '')
            )
            || (
                defined('EVERSHELF_DATABASE_SCHEMA_VERSION')
                && (int)($bundle['database_schema_version'] ?? -1)
                    !== EVERSHELF_DATABASE_SCHEMA_VERSION
            )
        ) {
            throw new RuntimeException(
                'ontology activation bundle lineage or runtime changed'
            );
        }
        $parentScoreId = (int)(
            $bundle['parent']['score_revision_id'] ?? 0
        );
        if ($kind === 'score') {
            $state = recipeScoreState($db);
            if (
                (int)($state['active_score_revision_id'] ?? 0)
                    !== $parentScoreId
            ) {
                throw new IngredientOntologyActivationExpectedOutcome(
                    'superseded_snapshot',
                    'ontology activation parent score pointer changed',
                    [
                        'reason' => 'parent_score_pointer_changed',
                        'expected_score_revision_id' => $parentScoreId,
                        'active_score_revision_id' =>
                            (int)(
                                $state['active_score_revision_id'] ?? 0
                            ),
                    ]
                );
            }
        }
        $activeVersion = ingredientOntologyV3ActiveVersion($db);
        $parentVersionId = (int)(
            $bundle['parent']['ontology_version_id'] ?? 0
        );
        if (
            $activeVersion === null
            || (int)$activeVersion['id'] !== $parentVersionId
            || (
                isset($bundle['parent']['content_hash'])
                && !hash_equals(
                    (string)$bundle['parent']['content_hash'],
                    (string)$activeVersion['content_hash']
                )
            )
        ) {
            throw new IngredientOntologyActivationExpectedOutcome(
                'superseded_snapshot',
                'ontology activation parent ontology changed',
                [
                    'reason' => 'parent_ontology_changed',
                    'expected_ontology_version_id' => $parentVersionId,
                    'active_ontology_version_id' =>
                        $activeVersion !== null
                            ? (int)$activeVersion['id']
                            : null,
                ]
            );
        }
        $candidateId = ingredientOntologyActivationCandidateId($bundle);
        if ($candidateId <= 0) {
            throw new InvalidArgumentException(
                'ontology activation candidate identifier is invalid'
            );
        }
        $specs = ingredientOntologyActivationSpecsForKind($kind);
        $tables = is_array($bundle['tables'] ?? null)
            ? $bundle['tables']
            : [];
        if (!$tables || count($tables) !== count($specs)) {
            throw new RuntimeException(
                'ontology activation table manifest is incomplete'
            );
        }
        foreach ($tables as $table) {
            $tableName = (string)($table['table'] ?? '');
            $spec = $specs[$tableName] ?? null;
            if ($spec === null) {
                throw new RuntimeException(
                    'ontology activation table manifest is unexpected'
                );
            }
            if (!hash_equals(
                ingredientOntologyActivationTableSchemaHash($db, $tableName),
                (string)($table['table_schema_hash'] ?? '')
            )) {
                throw new RuntimeException(
                    "ontology activation target schema changed: {$tableName}"
                );
            }
        }
        $manifestJson = ingredientOntologyActivationStableJson($bundle);
        if (strlen($manifestJson) > 1048576) {
            throw new RuntimeException(
                'ontology activation manifest exceeds its size limit'
            );
        }
        dbBeginImmediateWithRetry($db);
        try {
            ingredientOntologyActivationReserveManifestSequences(
                $db,
                $tables
            );
            $insert = $db->prepare("
                INSERT INTO ontology_activation_imports (
                    bundle_hash, bundle_kind, database_lineage_uuid,
                    schema_version, payload_path, payload_sha256,
                    payload_bytes, manifest_json,
                    parent_ontology_version_id,
                    candidate_ontology_version_id,
                    parent_score_revision_id,
                    candidate_score_revision_id,
                    source_fence_json
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $bundleHash,
                $kind,
                (string)$bundle['database_lineage_uuid'],
                (string)$bundle['schema_version'],
                $payloadPath,
                (string)$bundle['payload']['sha256'],
                (int)$bundle['payload']['bytes'],
                $manifestJson,
                $parentVersionId ?: null,
                (int)($bundle['candidate']['ontology_version_id'] ?? 0)
                    ?: null,
                $parentScoreId ?: null,
                (int)($bundle['candidate']['score_revision_id'] ?? 0)
                    ?: null,
                ingredientOntologyActivationStableJson(
                    $bundle['source_fence'] ?? []
                ),
            ]);
            $importId = (int)$db->lastInsertId();
            $tableInsert = $db->prepare("
                INSERT INTO ontology_activation_import_tables (
                    import_id, phase, table_name, cursor_column,
                    baseline_sequence, expected_post_sequence,
                    expected_row_count, expected_min_cursor,
                    expected_max_cursor, id_set_hash, row_hash
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($tables as $table) {
                $tableInsert->execute([
                    $importId,
                    (int)$table['phase'],
                    (string)$table['table'],
                    (string)$table['cursor'],
                    $table['baseline_sequence'] !== null
                        ? (int)$table['baseline_sequence']
                        : null,
                    $table['expected_post_sequence'] !== null
                        ? (int)$table['expected_post_sequence']
                        : null,
                    (int)$table['row_count'],
                    $table['minimum_cursor'] !== null
                        ? (int)$table['minimum_cursor']
                        : null,
                    $table['maximum_cursor'] !== null
                        ? (int)$table['maximum_cursor']
                        : null,
                    (string)$table['id_set_hash'],
                    (string)$table['row_hash'],
                ]);
            }
            $intentInsert = $db->prepare("
                INSERT INTO ontology_activation_import_intents (
                    import_id, source_job_id, subject_id,
                    subject_fingerprint, intent_kind,
                    activation_action, input_hash,
                    response_hash, plan_hash
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ((array)($bundle['intents'] ?? []) as $intent) {
                $sourceJobId = (int)$intent['source_job_id'];
                ingredientOntologyActivationEnsureLiveIntent(
                    $db,
                    $intent
                );
                $intentInsert->execute([
                    $importId,
                    $sourceJobId,
                    $intent['subject_id'] !== null
                        ? (int)$intent['subject_id']
                        : null,
                    $intent['subject_fingerprint'],
                    (string)$intent['intent_kind'],
                    (string)($intent['activation_action'] ?? 'apply'),
                    (string)$intent['input_hash'],
                    $intent['response_hash'],
                    $intent['plan_hash'],
                ]);
            }
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $error;
        }
        return ingredientOntologyActivationImportRow($db, $importId);
    }

    function ingredientOntologyActivationClaimImport(
        PDO $db,
        int $importId,
        int $leaseSeconds = 600
    ): array {
        $leaseSeconds = max(30, min(3600, $leaseSeconds));
        $token = bin2hex(random_bytes(32));
        dbBeginImmediateWithRetry($db);
        try {
            $claim = $db->prepare("
                UPDATE ontology_activation_imports
                SET lease_token = ?,
                    lease_generation = lease_generation + 1,
                    leased_until = datetime('now', ?),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND status IN ('staging', 'importing')
                  AND (
                      lease_token IS NULL
                      OR leased_until IS NULL
                      OR leased_until <= CURRENT_TIMESTAMP
                  )
            ");
            $claim->execute([
                $token,
                '+' . $leaseSeconds . ' seconds',
                $importId,
            ]);
            if ($claim->rowCount() !== 1) {
                throw new RuntimeException(
                    'ontology_activation_import_lease_busy'
                );
            }
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $error;
        }
        $row = ingredientOntologyActivationImportRow($db, $importId);
        return [
            'token' => $token,
            'generation' => (int)$row['lease_generation'],
            'row' => $row,
        ];
    }

    function ingredientOntologyActivationRootColumns(
        PDO $db,
        string $table
    ): array {
        $quoted = ingredientOntologyActivationQuoteIdentifier($table);
        return array_map(
            static fn(array $column): string => (string)$column['name'],
            $db->query(
                "PRAGMA table_info({$quoted})"
            )->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    function ingredientOntologyActivationImportRoot(
        PDO $db,
        string $payloadSchema,
        string $table,
        int $candidateId
    ): int {
        $columns = ingredientOntologyActivationRootColumns($db, $table);
        $columnSql = implode(', ', array_map(
            'ingredientOntologyActivationQuoteIdentifier',
            $columns
        ));
        $tableName = ingredientOntologyActivationQuoteIdentifier($table);
        $schemaName =
            ingredientOntologyActivationQuoteIdentifier($payloadSchema);
        $guardAvailable = function_exists(
            'ingredientOntologyV3SetReadyMutationGuard'
        );
        $guardWasEnabled = $guardAvailable
            && ingredientOntologyV3ReadyMutationGuardEnabled($db);
        try {
            if ($guardAvailable) {
                ingredientOntologyV3SetReadyMutationGuard($db, true);
            }
            $insert = $db->prepare("
                INSERT INTO main.{$tableName} ({$columnSql})
                SELECT {$columnSql}
                FROM {$schemaName}.{$tableName}
                WHERE id = ?
            ");
            $insert->execute([$candidateId]);
            $insert->closeCursor();
            if ($insert->rowCount() !== 1) {
                throw new RuntimeException(
                    'ontology activation root import was incomplete'
                );
            }
            if ($table === 'ingredient_ontology_versions') {
                $db->prepare("
                    UPDATE ingredient_ontology_versions
                    SET status = 'building',
                        ready_at = NULL,
                        failed_at = NULL,
                        retired_at = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ")->execute([$candidateId]);
            } else {
                $db->prepare("
                    UPDATE recipe_score_revisions
                    SET status = 'building',
                        completed_at = NULL,
                        last_error = ''
                    WHERE id = ?
                ")->execute([$candidateId]);
            }
            return 1;
        } finally {
            if ($guardAvailable) {
                ingredientOntologyV3SetReadyMutationGuard(
                    $db,
                    $guardWasEnabled
                );
            }
        }
    }

    function ingredientOntologyActivationRunImport(
        PDO $db,
        int $importId,
        int $maximumChunks = 100,
        ?string $verifiedPayloadPath = null,
        int $maximumMilliseconds = 1500
    ): array {
        ingredientOntologyActivationAssertActiveDatabase($db);
        $callerDb = $db;
        $maximumChunks = max(1, min(10000, $maximumChunks));
        $maximumMilliseconds = max(
            100,
            min(10000, $maximumMilliseconds)
        );
        $runStarted = hrtime(true);
        $lease = ingredientOntologyActivationClaimImport($db, $importId);
        $row = $lease['row'];
        $bundle = ingredientOntologyActivationImportBundle($row);
        $payloadPath = (string)$row['payload_path'];
        $resolvedPayloadPath =
            ingredientOntologyActivationUseVerifiedPayload(
                $bundle,
                dirname($payloadPath),
                $verifiedPayloadPath
            );
        if (!hash_equals($payloadPath, $resolvedPayloadPath)) {
            throw new RuntimeException(
                'ontology activation import payload path changed'
            );
        }
        $databasePath = '';
        foreach (
            $db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC)
            as $database
        ) {
            if ((string)$database['name'] === 'main') {
                $databasePath = (string)$database['file'];
                break;
            }
        }
        if ($databasePath === '' || !is_file($databasePath)) {
            throw new RuntimeException(
                'ontology activation target database path is unavailable'
            );
        }
        $db = new PDO('sqlite:' . $databasePath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        ingredientOntologyActivationConfigureDatabase(
            $db,
            INGREDIENT_ONTOLOGY_ACTIVATION_LIVE_BUSY_TIMEOUT_MS
        );
        ingredientOntologyActivationRegisterGuardFunctions($db);
        $payloadUri = 'file:' . $payloadPath . '?mode=ro&immutable=1';
        $db->exec(
            'ATTACH DATABASE ' . $db->quote($payloadUri)
            . ' AS activation_payload'
        );
        $attached = true;
        $chunks = 0;
        try {
            $candidateId = ingredientOntologyActivationCandidateId($bundle);
            while ($chunks < $maximumChunks) {
                if (
                    $chunks > 0
                    && (hrtime(true) - $runStarted) / 1000000
                        >= $maximumMilliseconds
                ) {
                    break;
                }
                $progress = $db->prepare("
                    SELECT * FROM ontology_activation_import_tables
                    WHERE import_id = ? AND status <> 'complete'
                    ORDER BY phase
                    LIMIT 1
                ");
                $progress->execute([$importId]);
                $table = $progress->fetch(PDO::FETCH_ASSOC);
                $progress->closeCursor();
                $progress = null;
                if (!$table) {
                    dbBeginImmediateWithRetry($db);
                    try {
                        if (
                            (string)$row['bundle_kind'] === 'score'
                        ) {
                            ingredientOntologyActivationReconcileScoreIdentityExtension(
                                $db,
                                $candidateId,
                                true
                            );
                        }
                        $done = $db->prepare("
                            UPDATE ontology_activation_imports
                            SET status = 'verifying',
                                lease_token = NULL,
                                leased_until = NULL,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                              AND lease_token = ?
                              AND lease_generation = ?
                              AND status IN ('staging', 'importing')
                        ");
                        $done->execute([
                            $importId,
                            $lease['token'],
                            $lease['generation'],
                        ]);
                        if ($done->rowCount() !== 1) {
                            throw new RuntimeException(
                                'ontology activation import completion fence lost'
                            );
                        }
                        $db->exec('COMMIT');
                    } catch (Throwable $error) {
                        try {
                            $db->exec('ROLLBACK');
                        } catch (Throwable $ignored) {
                        }
                        throw $error;
                    }
                    break;
                }
                $tableName = (string)$table['table_name'];
                $cursor = (string)$table['cursor_column'];
                $expectedRows = (int)$table['expected_row_count'];
                $started = hrtime(true);
                dbBeginImmediateWithRetry($db);
                try {
                    $current = $db->prepare("
                        SELECT status, lease_token, lease_generation,
                               chunk_rows
                        FROM ontology_activation_imports
                        WHERE id = ?
                    ");
                    $current->execute([$importId]);
                    $fence = $current->fetch(PDO::FETCH_ASSOC);
                    if (
                        !$fence
                        || !in_array(
                            (string)$fence['status'],
                            ['staging', 'importing'],
                            true
                        )
                        || !hash_equals(
                            (string)$fence['lease_token'],
                            (string)$lease['token']
                        )
                        || (int)$fence['lease_generation']
                            !== (int)$lease['generation']
                    ) {
                        throw new RuntimeException(
                            'ontology activation import lease fence lost'
                        );
                    }
                    $inserted = 0;
                    $newCursor = (int)$table['source_cursor'];
                    if ((int)$table['phase'] === 0) {
                        $inserted = ingredientOntologyActivationImportRoot(
                            $db,
                            'activation_payload',
                            $tableName,
                            $candidateId
                        );
                        $newCursor = (int)$table['expected_max_cursor'];
                    } elseif (
                        (int)$table['rows_imported'] < $expectedRows
                    ) {
                        $tableSql =
                            ingredientOntologyActivationQuoteIdentifier(
                                $tableName
                            );
                        $cursorSql =
                            ingredientOntologyActivationQuoteIdentifier(
                                $cursor
                            );
                        $chunkRows = max(1, (int)$fence['chunk_rows']);
                        $upper = $db->prepare("
                            SELECT MAX({$cursorSql}) FROM (
                                SELECT {$cursorSql}
                                FROM activation_payload.{$tableSql}
                                WHERE {$cursorSql} > ?
                                ORDER BY {$cursorSql}
                                LIMIT {$chunkRows}
                            )
                        ");
                        $upper->execute([(int)$table['source_cursor']]);
                        $upperCursor = $upper->fetchColumn();
                        $upper->closeCursor();
                        $upper = null;
                        if ($upperCursor !== false && $upperCursor !== null) {
                            $insert = $db->prepare("
                                INSERT INTO main.{$tableSql}
                                SELECT * FROM activation_payload.{$tableSql}
                                WHERE {$cursorSql} > ?
                                  AND {$cursorSql} <= ?
                                ORDER BY {$cursorSql}
                            ");
                            $insert->execute([
                                (int)$table['source_cursor'],
                                (int)$upperCursor,
                            ]);
                            $inserted = $insert->rowCount();
                            $insert->closeCursor();
                            $insert = null;
                            $newCursor = (int)$upperCursor;
                        }
                    }
                    if (
                        $tableName ===
                            'ingredient_ontology_identity_extension_entities'
                    ) {
                        ingredientOntologyActivationReconcileScoreIdentityExtension(
                            $db,
                            $candidateId,
                            false
                        );
                    }
                    $complete = (
                        (int)$table['rows_imported'] + $inserted
                    ) >= $expectedRows;
                    $updateTable = $db->prepare("
                        UPDATE ontology_activation_import_tables
                        SET source_cursor = ?,
                            rows_imported = rows_imported + ?,
                            status = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE import_id = ?
                          AND table_name = ?
                          AND source_cursor = ?
                          AND rows_imported = ?
                    ");
                    $updateTable->execute([
                        $newCursor,
                        $inserted,
                        $complete ? 'complete' : 'importing',
                        $importId,
                        $tableName,
                        (int)$table['source_cursor'],
                        (int)$table['rows_imported'],
                    ]);
                    if ($updateTable->rowCount() !== 1) {
                        throw new RuntimeException(
                            'ontology activation table progress fence lost'
                        );
                    }
                    $elapsedMs = (hrtime(true) - $started) / 1000000;
                    $chunkRows = (int)$fence['chunk_rows'];
                    if ($elapsedMs > 250 && $chunkRows > 25) {
                        $chunkRows = max(25, intdiv($chunkRows, 2));
                    } elseif ($elapsedMs < 75 && $chunkRows < 5000) {
                        $chunkRows = min(5000, $chunkRows * 2);
                    }
                    $updateImport = $db->prepare("
                        UPDATE ontology_activation_imports
                        SET status = 'importing',
                            phase = ?,
                            chunk_rows = ?,
                            rows_imported = rows_imported + ?,
                            last_reservation_ms = ?,
                            maximum_reservation_ms = MAX(
                                maximum_reservation_ms,
                                ?
                            ),
                            last_error = CASE
                                WHEN CAST(? AS REAL) > 250
                                THEN 'import reservation exceeded 250 ms'
                                ELSE last_error
                            END,
                            leased_until = datetime('now', '+10 minutes'),
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                          AND lease_token = ?
                          AND lease_generation = ?
                    ");
                    $updateImport->execute([
                        (int)$table['phase'],
                        $chunkRows,
                        $inserted,
                        $elapsedMs,
                        $elapsedMs,
                        $elapsedMs,
                        $importId,
                        $lease['token'],
                        $lease['generation'],
                    ]);
                    if ($updateImport->rowCount() !== 1) {
                        throw new RuntimeException(
                            'ontology activation import progress fence lost'
                        );
                    }
                    $db->exec('COMMIT');
                } catch (Throwable $error) {
                    try {
                        $db->exec('ROLLBACK');
                    } catch (Throwable $ignored) {
                    }
                    throw $error;
                }
                $chunks++;
            }
            if (
                $chunks >= $maximumChunks
                || (hrtime(true) - $runStarted) / 1000000
                    >= $maximumMilliseconds
            ) {
                $db->prepare("
                    UPDATE ontology_activation_imports
                    SET lease_token = NULL,
                        leased_until = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                      AND lease_token = ?
                      AND lease_generation = ?
                      AND status IN ('staging', 'importing')
                ")->execute([
                    $importId,
                    $lease['token'],
                    $lease['generation'],
                ]);
            }
        } catch (Throwable $error) {
            $message = (
                function_exists('databaseIsLockError')
                && databaseIsLockError($error)
            )
                ? 'Retryable SQLite contention: '
                    . $error->getMessage()
                : $error->getMessage();
            $db->prepare("
                UPDATE ontology_activation_imports
                SET lease_token = NULL,
                    leased_until = NULL,
                    last_error = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND lease_token = ?
                  AND lease_generation = ?
            ")->execute([
                mb_substr($message, 0, 1000, 'UTF-8'),
                $importId,
                $lease['token'],
                $lease['generation'],
            ]);
            throw $error;
        } finally {
            $db = null;
        }
        return ingredientOntologyActivationImportRow($callerDb, $importId);
    }

    function ingredientOntologyActivationVerifyImportedRows(
        PDO $db,
        int $importId
    ): array {
        $row = ingredientOntologyActivationImportRow($db, $importId);
        if ((string)$row['status'] !== 'verifying') {
            throw new RuntimeException(
                'ontology activation import is not ready for verification'
            );
        }
        $bundle = ingredientOntologyActivationImportBundle($row);
        $candidateId = ingredientOntologyActivationCandidateId($bundle);
        $specs = ingredientOntologyActivationSpecsForKind(
            (string)$row['bundle_kind']
        );
        $errors = [];
        $tables = $db->prepare("
            SELECT * FROM ontology_activation_import_tables
            WHERE import_id = ?
            ORDER BY phase
        ");
        $tables->execute([$importId]);
        foreach ($tables->fetchAll(PDO::FETCH_ASSOC) as $table) {
            $tableName = (string)$table['table_name'];
            $spec = $specs[$tableName];
            $actual = ingredientOntologyActivationTargetTableHash(
                $db,
                $tableName,
                (string)$table['cursor_column'],
                (string)$spec['selector'],
                $candidateId,
                !empty($spec['after_snapshot_sequence'])
                    ? (int)$table['baseline_sequence']
                    : null
            );
            if (
                (int)($actual['row_count'] ?? -1)
                    !== (int)$table['expected_row_count']
                || (
                    (int)$table['expected_row_count'] > 0
                    && (
                        (int)$actual['minimum_cursor']
                            !== (int)$table['expected_min_cursor']
                        || (int)$actual['maximum_cursor']
                            !== (int)$table['expected_max_cursor']
                    )
                )
            ) {
                $errors[] = "{$tableName} row fence changed";
            }
            if (
                empty($spec['root'])
                && (
                    !hash_equals(
                        (string)$table['id_set_hash'],
                        (string)$actual['id_set_hash']
                    )
                    || !hash_equals(
                        (string)$table['row_hash'],
                        (string)$actual['row_hash']
                    )
                )
            ) {
                $errors[] = "{$tableName} content hash changed";
            }
            if (
                $table['expected_post_sequence'] !== null
                && (
                    !empty($spec['append_only'])
                        ? ingredientOntologyActivationSequence(
                            $db,
                            $tableName
                        ) < (int)$table['expected_post_sequence']
                        : ingredientOntologyActivationSequence(
                            $db,
                            $tableName
                        ) !== (int)$table['expected_post_sequence']
                )
            ) {
                $errors[] = "{$tableName} sequence fence changed";
            }
        }
        if ($errors) {
            $db->prepare("
                UPDATE ontology_activation_imports
                SET status = 'rebase_required',
                    last_error = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'verifying'
            ")->execute([
                mb_substr(implode('; ', $errors), 0, 1000, 'UTF-8'),
                $importId,
            ]);
        }
        return [
            'valid' => !$errors,
            'errors' => $errors,
            'import_id' => $importId,
        ];
    }

    function ingredientOntologyActivationPayloadRootRow(
        string $payloadPath,
        string $table,
        int $candidateId
    ): array {
        $payloadDb = new PDO(
            'sqlite:file:' . $payloadPath . '?mode=ro&immutable=1'
        );
        $payloadDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tableName = ingredientOntologyActivationQuoteIdentifier($table);
        $stmt = $payloadDb->prepare("
            SELECT * FROM {$tableName} WHERE id = ?
        ");
        $stmt->execute([$candidateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $payloadDb = null;
        if (!$row) {
            throw new RuntimeException(
                'ontology activation payload root is unavailable'
            );
        }
        return $row;
    }

    function ingredientOntologyActivationUpdateRootRow(
        PDO $db,
        string $table,
        array $root
    ): void {
        if (!isset($root['id']) || (int)$root['id'] <= 0) {
            throw new InvalidArgumentException(
                'ontology activation root row is invalid'
            );
        }
        $tableName = ingredientOntologyActivationQuoteIdentifier($table);
        $columns = ingredientOntologyActivationRootColumns($db, $table);
        $assignments = [];
        $params = [];
        foreach ($columns as $column) {
            if ($column === 'id') {
                continue;
            }
            if (!array_key_exists($column, $root)) {
                throw new RuntimeException(
                    "ontology activation root column is missing: {$column}"
                );
            }
            $assignments[] =
                ingredientOntologyActivationQuoteIdentifier($column) . ' = ?';
            $params[] = $root[$column];
        }
        $params[] = (int)$root['id'];
        $publicationWas =
            ingredientOntologyV3PublicationGuardEnabled($db);
        $readyWas = ingredientOntologyV3ReadyMutationGuardEnabled($db);
        ingredientOntologyV3SetPublicationGuard($db, true);
        ingredientOntologyV3SetReadyMutationGuard($db, true);
        try {
            $update = $db->prepare("
                UPDATE {$tableName}
                SET " . implode(', ', $assignments) . "
                WHERE id = ?
            ");
            $update->execute($params);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'ontology activation root publication fence was lost'
                );
            }
        } finally {
            ingredientOntologyV3SetReadyMutationGuard($db, $readyWas);
            ingredientOntologyV3SetPublicationGuard($db, $publicationWas);
        }
    }

    function ingredientOntologyActivationControllerState(PDO $db): array {
        return $db->query("
            SELECT constraint_epoch, controller_generation,
                   active_gold_release_id, active_policy_hash
            FROM ontology_controller_state
            WHERE id = 1
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    function ingredientOntologyActivationRestampScoreRoot(
        PDO $db,
        array $root
    ): array {
        $state = recipeScoreState($db);
        $active = recipeScoreActiveRevision($db);
        if (
            (string)(
                $state['ontology_source_lineage_hash'] ?? ''
            ) !== ''
            && $active !== null
            && (int)($root['ontology_version_id'] ?? 0)
                === (int)($active['ontology_version_id'] ?? 0)
        ) {
            throw new RuntimeException(
                'score refresh requires ontology refresh for scoped source lineage'
            );
        }
        $corpusHash = ingredientOntologyV3CorpusHash($db);
        $report = json_decode(
            (string)($root['validation_report_json'] ?? '{}'),
            true
        );
        $report = is_array($report) ? $report : [];
        $report['inventory_revision'] = (int)$state['inventory_revision'];
        $report['catalog_revision'] = (int)$state['catalog_revision'];
        $report['ontology_source_revision'] =
            (int)$state['ontology_source_revision'];
        $report['ontology_source_hash'] = $corpusHash;
        $report['active_score_revision_id_before'] =
            (int)($state['active_score_revision_id'] ?? 0);
        $root['parent_score_revision_id'] =
            (int)($state['active_score_revision_id'] ?? 0);
        $root['inventory_revision'] = (int)$state['inventory_revision'];
        $root['catalog_revision'] = (int)$state['catalog_revision'];
        $root['ontology_source_revision'] =
            (int)$state['ontology_source_revision'];
        $root['ontology_source_hash'] = $corpusHash;
        $root['catalog_lineage_hash'] = '';
        $root['ontology_source_lineage_hash'] = '';
        unset(
            $report['catalog_lineage_hash'],
            $report['ontology_source_lineage_hash']
        );
        $root['validation_report_json'] =
            ingredientOntologyActivationStableJson($report);
        return $root;
    }

    function ingredientOntologyActivationValidateOntologyCopy(
        PDO $db,
        int $versionId,
        bool $allowTestFixture
    ): array {
        $version = ingredientOntologyV3Version($db, $versionId);
        if ($version === null || (string)$version['status'] !== 'ready') {
            return [
                'valid' => false,
                'errors' => ['imported ontology version is not ready'],
            ];
        }
        $graph = ingredientOntologyV3GraphValidate($db, $versionId);
        $corpus = ingredientOntologyV3CorpusCompleteness($db, $versionId);
        $owners = ingredientOntologyV3OwnerFingerprintAudit($db, $versionId);
        $attributes =
            ingredientOntologyV3MappingAttributeIntegrityAudit(
                $db,
                $versionId
            );
        $constraints =
            ingredientOntologyControllerConstraintAudit($db, $versionId);
        $integrity =
            ingredientOntologyControllerVersionIntegrityAudit($db, $versionId);
        $gold = $allowTestFixture
            ? ['valid' => true, 'test_fixture' => true]
            : ingredientOntologyV3EvaluateGold($db, $versionId);
        $resolutionGold = $allowTestFixture
            ? ['valid' => true, 'test_fixture' => true]
            : ingredientOntologyV3EvaluateResolutionGold(
                $db,
                $versionId,
                true
            );
        $controllerGold = $allowTestFixture
            ? ['valid' => true, 'test_fixture' => true]
            : ingredientOntologyControllerActiveGoldAudit(
                $db,
                $versionId
            );
        $pending = $db->prepare("
            SELECT COUNT(*) FROM ingredient_ontology_change_sets
            WHERE ontology_version_id = ?
              AND review_state IN ('pending', 'approved')
        ");
        $pending->execute([$versionId]);
        $invalid = $db->prepare("
            SELECT COUNT(*) FROM ingredient_ontology_change_sets
            WHERE ontology_version_id = ?
              AND review_state IN ('pending', 'approved', 'applied')
              AND (
                  json_valid(validator_result_json) = 0
                  OR COALESCE(
                      json_extract(validator_result_json, '$.valid'),
                      0
                  ) = 0
              )
        ");
        $invalid->execute([$versionId]);
        $errors = [];
        foreach ([
            'graph' => $graph['valid'],
            'corpus' => $corpus['complete'],
            'owner fingerprints' => $owners['valid'],
            'mapping attributes' => $attributes['valid'],
            'constraints' => $constraints['valid'],
            'controller integrity' => $integrity['valid'],
            'matcher gold' => $gold['valid'],
            'resolution gold' => $resolutionGold['valid'],
            'controller gold' => $controllerGold['valid'],
            'pending change sets' => (int)$pending->fetchColumn() === 0,
            'invalid change sets' => (int)$invalid->fetchColumn() === 0,
        ] as $name => $valid) {
            if (!$valid) {
                $errors[] = "{$name} failed";
            }
        }
        return [
            'valid' => !$errors,
            'errors' => $errors,
            'graph' => $graph,
            'corpus' => $corpus,
            'owner_fingerprints' => $owners,
            'mapping_attributes' => $attributes,
            'constraints' => $constraints,
            'controller_integrity' => $integrity,
            'matcher_gold' => $gold,
            'resolution_gold' => $resolutionGold,
            'controller_gold' => $controllerGold,
        ];
    }

    function ingredientOntologyActivationValidateImportOnCopy(
        PDO $db,
        int $importId,
        array $options = []
    ): array {
        ingredientOntologyControllerAssertCopiedGenerationDatabase($db);
        ingredientOntologyActivationRegisterGuardFunctions($db);
        $row = ingredientOntologyActivationImportRow($db, $importId);
        if ((string)$row['status'] !== 'verifying') {
            throw new RuntimeException(
                'ontology activation import copy is not ready for validation'
            );
        }
        $bundle = ingredientOntologyActivationImportBundle($row);
        $payloadPath = (string)$row['payload_path'];
        ingredientOntologyActivationResolvePayload(
            $bundle,
            dirname($payloadPath)
        );
        $candidateId = ingredientOntologyActivationCandidateId($bundle);
        $kind = (string)$row['bundle_kind'];
        $rootTable = $kind === 'ontology'
            ? 'ingredient_ontology_versions'
            : 'recipe_score_revisions';
        $root = ingredientOntologyActivationPayloadRootRow(
            $payloadPath,
            $rootTable,
            $candidateId
        );
        if ($kind === 'score') {
            $root = ingredientOntologyActivationRestampScoreRoot($db, $root);
            $state = recipeScoreState($db);
            $sourceHash = (string)$root['ontology_source_hash'];
            $source = $db->prepare("
                UPDATE recipe_score_state
                SET ontology_source_hash = ?,
                    ontology_source_lineage_hash = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = 1
                  AND ontology_source_revision = ?
            ");
            $source->execute([
                $sourceHash,
                (string)(
                    $root['ontology_source_lineage_hash'] ?? ''
                ),
                (int)$state['ontology_source_revision'],
            ]);
            if ($source->rowCount() !== 1) {
                throw new RuntimeException(
                    'ontology activation validation source fence was lost'
                );
            }
        }
        ingredientOntologyActivationUpdateRootRow($db, $rootTable, $root);
        $allowTestFixture = defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && !empty($options['allow_test_fixture']);
        $validation = $kind === 'ontology'
            ? ingredientOntologyActivationValidateOntologyCopy(
                $db,
                $candidateId,
                $allowTestFixture
            )
            : (
                $allowTestFixture
                    ? [
                        'valid' => true,
                        'errors' => [],
                        'test_fixture' => true,
                        'materialized_values' =>
                            ingredientOntologyV3MaterializedValueAudit(
                                $db,
                                recipeScoreRevision($db, $candidateId)
                            ),
                    ]
                    : ingredientOntologyV3ValidateActivation(
                        $db,
                        $candidateId
                    )
            );
        if ($kind === 'score') {
            ingredientOntologyActivationAssertScoreValidation(
                $validation,
                'ontology activation copied validation failed',
                'rebase_required',
                [
                    'import_id' => $importId,
                    'candidate_score_revision_id' => $candidateId,
                ]
            );
        } elseif (empty($validation['valid'])) {
            $errors = (array)($validation['errors'] ?? []);
            throw new RuntimeException(
                'ontology activation copied validation failed: '
                . implode('; ', $errors)
            );
        }
        $state = recipeScoreState($db);
        $fence = [
            'database_lineage_uuid' =>
                ingredientOntologyActivationLineageUuid($db),
            'runtime_hash' => ingredientOntologyActivationRuntimeHash(),
            'active_score_revision_id' =>
                (int)($state['active_score_revision_id'] ?? 0),
            'active_ontology_version_id' => (int)(
                ingredientOntologyV3ActiveVersion($db)['id'] ?? 0
            ),
            'inventory_revision' => (int)$state['inventory_revision'],
            'catalog_revision' => (int)$state['catalog_revision'],
            'ontology_source_revision' =>
                (int)$state['ontology_source_revision'],
            'ontology_source_hash' => $kind === 'score'
                ? (string)$root['ontology_source_hash']
                : (string)$root['corpus_hash'],
            'score_date' => recipeScoreCurrentDate(),
            'score_timezone' => recipeScoreTimezone()->getName(),
            'cdc' => ingredientOntologyActivationCdcSnapshot($db),
            'controller_state' =>
                ingredientOntologyActivationControllerState($db),
        ];
        $attestation = [
            'schema_version' =>
                'ontology-activation-validation-attestation-v1',
            'bundle_hash' => (string)$row['bundle_hash'],
            'bundle_kind' => $kind,
            'import_id' => $importId,
            'validated_at' => gmdate('c'),
            'validation_fence' => $fence,
            'root_row' => $root,
            'validation' => $validation,
        ];
        $attestation['attestation_hash'] =
            ingredientOntologyV3Hash($attestation);
        return $attestation;
    }

    function ingredientOntologyActivationStoreValidation(
        PDO $db,
        int $importId,
        array $attestation
    ): array {
        ingredientOntologyActivationAssertActiveDatabase($db);
        $expectedHash = (string)($attestation['attestation_hash'] ?? '');
        $payload = $attestation;
        unset($payload['attestation_hash']);
        $row = ingredientOntologyActivationImportRow($db, $importId);
        if (
            !preg_match('/^[a-f0-9]{64}$/D', $expectedHash)
            || !hash_equals(
                ingredientOntologyV3Hash($payload),
                $expectedHash
            )
            || !hash_equals(
                (string)$row['bundle_hash'],
                (string)($attestation['bundle_hash'] ?? '')
            )
            || (string)$row['bundle_kind']
                !== (string)($attestation['bundle_kind'] ?? '')
            || (int)($attestation['import_id'] ?? 0) !== $importId
        ) {
            throw new RuntimeException(
                'ontology activation validation attestation is invalid'
            );
        }
        $attestationJson =
            ingredientOntologyActivationStableJson($attestation);
        if (strlen($attestationJson) > 1048576) {
            throw new RuntimeException(
                'ontology activation attestation exceeds its size limit'
            );
        }
        $update = $db->prepare("
            UPDATE ontology_activation_imports
            SET status = 'activatable',
                parent_score_revision_id = CASE
                    WHEN bundle_kind = 'score' THEN ?
                    ELSE parent_score_revision_id
                END,
                validation_fence_json = ?,
                attestation_json = ?,
                last_error = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status = 'verifying'
        ");
        $update->execute([
            (int)(
                $attestation['root_row'][
                    'parent_score_revision_id'
                ] ?? 0
            ),
            ingredientOntologyActivationStableJson(
                $attestation['validation_fence']
            ),
            $attestationJson,
            $importId,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException(
                'ontology activation validation storage fence was lost'
            );
        }
        return ingredientOntologyActivationImportRow($db, $importId);
    }

    function ingredientOntologyActivationIntentErrors(
        PDO $db,
        int $importId
    ): array {
        $stmt = $db->prepare("
            SELECT expected.*, intent.status AS live_status,
                   intent.intent_kind AS live_kind,
                   job.input_hash AS live_input_hash,
                   response.response_hash AS live_response_hash,
                   subject.subject_fingerprint AS live_subject_fingerprint
            FROM ontology_activation_import_intents expected
            LEFT JOIN ontology_generation_intents intent
              ON intent.source_job_id = expected.source_job_id
            LEFT JOIN ontology_controller_jobs job
              ON job.id = expected.source_job_id
            LEFT JOIN ontology_controller_responses response
              ON response.id = intent.response_artifact_id
            LEFT JOIN ontology_subjects subject
              ON subject.id = expected.subject_id
            WHERE expected.import_id = ?
            ORDER BY expected.source_job_id
        ");
        $stmt->execute([$importId]);
        $errors = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $intent) {
            if (
                !in_array(
                    (string)($intent['live_status'] ?? ''),
                    ['pending', 'queued'],
                    true
                )
                || (string)($intent['live_kind'] ?? '')
                    !== (string)$intent['intent_kind']
                || !hash_equals(
                    (string)$intent['input_hash'],
                    (string)($intent['live_input_hash'] ?? '')
                )
                || (
                    $intent['subject_fingerprint'] !== null
                    && !hash_equals(
                        (string)$intent['subject_fingerprint'],
                        (string)($intent['live_subject_fingerprint'] ?? '')
                    )
                )
                || (
                    $intent['response_hash'] !== null
                    && !hash_equals(
                        (string)$intent['response_hash'],
                        (string)($intent['live_response_hash'] ?? '')
                    )
                )
            ) {
                $errors[] = 'generation intent changed: '
                    . (int)$intent['source_job_id'];
            }
        }
        return $errors;
    }

    function ingredientOntologyActivationFenceErrors(
        PDO $db,
        array $row,
        array $attestation
    ): array {
        $fence = (array)($attestation['validation_fence'] ?? []);
        $errors = [];
        if (
            !hash_equals(
                ingredientOntologyActivationLineageUuid($db),
                (string)($fence['database_lineage_uuid'] ?? '')
            )
            || !hash_equals(
                ingredientOntologyActivationRuntimeHash(),
                (string)($fence['runtime_hash'] ?? '')
            )
        ) {
            $errors[] = 'activation runtime fence changed';
        }
        $state = recipeScoreState($db);
        foreach (['ontology_source_revision'] as $field) {
            if (
                (int)($state[$field] ?? 0)
                    !== (int)($fence[$field] ?? -1)
            ) {
                $errors[] = "activation {$field} changed";
            }
        }
        if ((string)$row['bundle_kind'] === 'score') {
            if (
                (int)($state['active_score_revision_id'] ?? 0)
                    !== (int)(
                        $fence['active_score_revision_id'] ?? -1
                    )
            ) {
                $errors[] =
                    'activation active_score_revision_id changed';
            }
            foreach (['inventory_revision', 'catalog_revision'] as $field) {
                if (
                    (int)($state[$field] ?? 0)
                        !== (int)($fence[$field] ?? -1)
                ) {
                    $errors[] = "activation {$field} changed";
                }
            }
            if ((string)($fence['score_date'] ?? '')
                !== recipeScoreCurrentDate()) {
                $errors[] = 'activation score date changed';
            }
            if ((string)($fence['score_timezone'] ?? '')
                !== recipeScoreTimezone()->getName()) {
                $errors[] = 'activation score timezone changed';
            }
        } else {
            $activeVersionId = (int)(
                ingredientOntologyV3ActiveVersion($db)['id'] ?? 0
            );
            if (
                $activeVersionId !== (int)(
                    $fence['active_ontology_version_id'] ?? -1
                )
            ) {
                $errors[] =
                    'activation active_ontology_version_id changed';
            }
        }
        $currentCdc = ingredientOntologyActivationCdcSnapshot($db);
        $domains = (string)$row['bundle_kind'] === 'ontology'
            ? ['source', 'constraint', 'policy']
            : ['source', 'catalog', 'inventory', 'constraint', 'policy'];
        foreach ($domains as $domain) {
            if (
                (int)($currentCdc[$domain] ?? 0)
                    !== (int)($fence['cdc'][$domain] ?? -1)
            ) {
                $errors[] = "activation {$domain} CDC changed";
            }
        }
        $controller = ingredientOntologyActivationControllerState($db);
        foreach ([
            'constraint_epoch',
            'active_gold_release_id',
            'active_policy_hash',
        ] as $field) {
            if (
                (string)($controller[$field] ?? '')
                    !== (string)($fence['controller_state'][$field] ?? '')
            ) {
                $errors[] = "activation controller {$field} changed";
            }
        }
        $tables = $db->prepare("
            SELECT table_name, expected_post_sequence,
                   expected_row_count, status
            FROM ontology_activation_import_tables
            WHERE import_id = ?
        ");
        $tables->execute([(int)$row['id']]);
        $specs = ingredientOntologyActivationSpecsForKind(
            (string)$row['bundle_kind']
        );
        foreach ($tables->fetchAll(PDO::FETCH_ASSOC) as $table) {
            $spec = $specs[(string)$table['table_name']] ?? [];
            if ((string)$table['status'] !== 'complete') {
                $errors[] = 'activation table is incomplete: '
                    . (string)$table['table_name'];
            }
            if (
                $table['expected_post_sequence'] !== null
                && (
                    !empty($spec['append_only'])
                        ? ingredientOntologyActivationSequence(
                            $db,
                            (string)$table['table_name']
                        ) < (int)$table['expected_post_sequence']
                        : ingredientOntologyActivationSequence(
                            $db,
                            (string)$table['table_name']
                        ) !== (int)$table['expected_post_sequence']
                )
            ) {
                $errors[] = 'activation sequence changed: '
                    . (string)$table['table_name'];
            }
            if (
                (string)$row['bundle_kind'] === 'score'
                && in_array(
                    (string)$table['table_name'],
                    [
                        'recipe_inventory_scores',
                        'ingredient_ontology_shadow_matches',
                    ],
                    true
                )
            ) {
                $tableName = ingredientOntologyActivationQuoteIdentifier(
                    (string)$table['table_name']
                );
                $count = $db->prepare("
                    SELECT COUNT(*) FROM {$tableName}
                    WHERE score_revision_id = ?
                ");
                $count->execute([
                    (int)$row['candidate_score_revision_id'],
                ]);
                if (
                    (int)$count->fetchColumn()
                        !== (int)$table['expected_row_count']
                ) {
                    $errors[] = 'activation score rows changed: '
                        . (string)$table['table_name'];
                }
            }
        }
        $rootTable = (string)$row['bundle_kind'] === 'score'
            ? 'recipe_score_revisions'
            : 'ingredient_ontology_versions';
        $rootId = (string)$row['bundle_kind'] === 'score'
            ? (int)$row['candidate_score_revision_id']
            : (int)$row['candidate_ontology_version_id'];
        $rootStatus = $db->prepare("
            SELECT status FROM {$rootTable} WHERE id = ?
        ");
        $rootStatus->execute([$rootId]);
        if ($rootStatus->fetchColumn() !== 'building') {
            $errors[] = 'activation candidate root changed';
        }
        return array_merge(
            $errors,
            ingredientOntologyActivationIntentErrors(
                $db,
                (int)$row['id']
            )
        );
    }

    function ingredientOntologyActivationActivateImport(
        PDO $db,
        int $importId
    ): array {
        ingredientOntologyActivationAssertActiveDatabase($db);
        $row = ingredientOntologyActivationImportRow($db, $importId);
        if ((string)$row['status'] !== 'activatable') {
            throw new RuntimeException(
                'ontology activation import is not activatable'
            );
        }
        $attestation = json_decode(
            (string)$row['attestation_json'],
            true,
            128,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($attestation)) {
            throw new RuntimeException(
                'ontology activation attestation is unavailable'
            );
        }
        $expectedHash = (string)($attestation['attestation_hash'] ?? '');
        $payload = $attestation;
        unset($payload['attestation_hash']);
        if (!hash_equals(
            ingredientOntologyV3Hash($payload),
            $expectedHash
        )) {
            throw new RuntimeException(
                'ontology activation attestation hash changed'
            );
        }
        $reservationStarted = hrtime(true);
        dbBeginImmediateWithRetry($db);
        try {
            $locked = ingredientOntologyActivationImportRow($db, $importId);
            $errors = ingredientOntologyActivationFenceErrors(
                $db,
                $locked,
                $attestation
            );
            if ($errors) {
                if ((string)$locked['bundle_kind'] === 'score') {
                    $classification =
                        ingredientOntologyActivationClassifyValidationErrors(
                            $errors
                        );
                    if (!empty($classification['expected'])) {
                        throw new
                            IngredientOntologyActivationExpectedOutcome(
                                (string)(
                                    $classification['outcome_kind']
                                        ?? 'rebase_required'
                                ),
                                'ontology activation final fence requires rebase',
                                [
                                    'import_id' => $importId,
                                    'candidate_score_revision_id' =>
                                        (int)$locked[
                                            'candidate_score_revision_id'
                                        ],
                                    'errors' => $errors,
                                    'drift_codes' =>
                                        $classification['drift_codes'],
                                ]
                            );
                    }
                }
                throw new RuntimeException(
                    'ontology activation final fence failed: '
                    . implode('; ', $errors)
                );
            }
            $kind = (string)$locked['bundle_kind'];
            $rootTable = $kind === 'ontology'
                ? 'ingredient_ontology_versions'
                : 'recipe_score_revisions';
            ingredientOntologyActivationUpdateRootRow(
                $db,
                $rootTable,
                (array)$attestation['root_row']
            );
            if ($kind === 'score') {
                $root = (array)$attestation['root_row'];
                $state = recipeScoreState($db);
                recipeScoreBuildEffectiveProjection(
                    $db,
                    (int)$root['id']
                );
                $pointer = $db->prepare("
                    UPDATE recipe_score_state
                    SET active_score_revision_id = ?,
                        active_score_overlay_revision_id = NULL,
                        ontology_source_hash = ?,
                        ontology_source_lineage_hash = ?,
                        cursor_revision = cursor_revision + 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = 1
                      AND active_score_revision_id = ?
                      AND inventory_revision = ?
                      AND catalog_revision = ?
                      AND ontology_source_revision = ?
                ");
                $pointer->execute([
                    (int)$root['id'],
                    (string)$root['ontology_source_hash'],
                    (string)(
                        $root['ontology_source_lineage_hash'] ?? ''
                    ),
                    (int)$root['parent_score_revision_id'],
                    (int)$state['inventory_revision'],
                    (int)$state['catalog_revision'],
                    (int)$state['ontology_source_revision'],
                ]);
                if ($pointer->rowCount() !== 1) {
                    throw new RuntimeException(
                        'ontology activation pointer CAS failed'
                    );
                }
                $readinessAdmissions = [];
                if (
                    function_exists(
                        'ingredientOntologyV3ProductReadinessBackfillReady'
                    )
                    && ingredientOntologyV3TableExists(
                        $db,
                        'ingredient_ontology_product_readiness'
                    )
                ) {
                    $readiness = $db->prepare("
                        SELECT annex.product_id,
                               annex.ontology_version_id,
                               annex.owner_fingerprint,
                               annex.evidence_hash,
                               annex.status
                        FROM recipe_score_pending_products pending
                        JOIN ingredient_ontology_identity_annex annex
                          ON annex.product_id = pending.product_id
                        WHERE pending.latest_inventory_revision <= ?
                        ORDER BY annex.product_id
                    ");
                    $readiness->execute([
                        (int)$root['inventory_revision'],
                    ]);
                    $readinessAdmissions =
                        $readiness->fetchAll(PDO::FETCH_ASSOC);
                }
                recipeScoreClearPendingProducts(
                    $db,
                    (int)$root['inventory_revision']
                );
                foreach (
                    $readinessAdmissions as $readinessAdmission
                ) {
                    ingredientOntologyV3ProductReadinessBackfillReady(
                        $db,
                        $readinessAdmission,
                        $root
                    );
                }
                recipeScoreClearPendingRecipes(
                    $db,
                    (int)$root['catalog_revision'],
                    (int)$root['ontology_source_revision']
                );
                $db->prepare("
                    DELETE FROM recipe_score_mutations
                    WHERE (
                        domain = 'catalog' AND revision <= ?
                    ) OR (
                        domain = 'source' AND revision <= ?
                    )
                ")->execute([
                    (int)$root['catalog_revision'],
                    (int)$root['ontology_source_revision'],
                ]);
                recipeScoreSetWorkState(
                    $db,
                    'idle',
                    (int)$root['id'],
                    (int)$root['parent_score_revision_id'],
                    (int)$root['recipe_count'],
                    (int)$root['recipe_count'],
                    0,
                    0
                );
                $intents = $db->prepare("
                    SELECT source_job_id, activation_action
                    FROM ontology_activation_import_intents
                    WHERE import_id = ?
                    ORDER BY source_job_id
                ");
                $intents->execute([$importId]);
                foreach ($intents->fetchAll(PDO::FETCH_ASSOC) as $intentRow) {
                    $sourceJobId = (int)$intentRow['source_job_id'];
                    $activationAction =
                        (string)$intentRow['activation_action'];
                    if ($activationAction === 'apply') {
                        $intent = $db->prepare("
                            UPDATE ontology_generation_intents
                            SET status = 'applied',
                                last_error = '',
                                finished_at = CURRENT_TIMESTAMP,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE source_job_id = ?
                              AND status IN ('pending', 'queued')
                        ");
                        $intent->execute([$sourceJobId]);
                        if ($intent->rowCount() !== 1) {
                            throw new RuntimeException(
                                'ontology activation intent CAS failed'
                            );
                        }
                    } else {
                        $intent = $db->prepare("
                            UPDATE ontology_generation_intents
                            SET status = 'pending',
                                attempts = attempts + 1,
                                last_error =
                                    'Validated plan deferred by benchmark policy; provisional fallback is active.',
                                finished_at = NULL,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE source_job_id = ?
                              AND status IN ('pending', 'queued')
                        ");
                        $intent->execute([$sourceJobId]);
                        if ($intent->rowCount() !== 1) {
                            throw new RuntimeException(
                                'ontology activation deferred intent CAS failed'
                            );
                        }
                    }
                    $db->prepare("
                        UPDATE ontology_activation_import_intents
                        SET status = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE import_id = ? AND source_job_id = ?
                    ")->execute([
                        $activationAction === 'apply'
                            ? 'applied'
                            : 'deferred',
                        $importId,
                        $sourceJobId,
                    ]);
                    $db->prepare("
                        UPDATE ontology_controller_jobs
                        SET candidate_version_id = ?,
                            candidate_score_revision_id = ?,
                            next_attempt_at = CASE
                                WHEN ? = 'defer'
                                THEN datetime('now', '+24 hours')
                                ELSE next_attempt_at
                            END,
                            last_error_kind = CASE
                                WHEN ? = 'defer'
                                THEN 'generation_policy_deferred'
                                ELSE last_error_kind
                            END,
                            last_error = CASE
                                WHEN ? = 'defer'
                                THEN 'Validated plan awaits an authorized benchmark policy.'
                                ELSE last_error
                            END,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ")->execute([
                        (int)$root['ontology_version_id'],
                        (int)$root['id'],
                        $activationAction,
                        $activationAction,
                        $activationAction,
                        $sourceJobId,
                    ]);
                    $db->prepare("
                        UPDATE ontology_provisional_queue
                        SET status = 'resolved',
                            reason = 'Activated from copied bundle.',
                            updated_at = CURRENT_TIMESTAMP
                        WHERE source_job_id = ?
                    ")->execute([$sourceJobId]);
                }
            }
            $finish = $db->prepare("
                UPDATE ontology_activation_imports
                SET status = ?,
                    activated_at = CASE
                        WHEN ? = 'active' THEN CURRENT_TIMESTAMP
                        ELSE activated_at
                    END,
                    last_error = '',
                    completed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'activatable'
            ");
            $finalStatus = $kind === 'score' ? 'active' : 'complete';
            $finish->execute([$finalStatus, $finalStatus, $importId]);
            if ($finish->rowCount() !== 1) {
                throw new RuntimeException(
                    'ontology activation import status CAS failed'
                );
            }
            ingredientOntologyActivationRecordOutcome(
                $db,
                $kind === 'score' ? 'activated' : 'converged',
                [
                    'reason' => $kind === 'score'
                        ? 'score_import_activated'
                        : 'ontology_import_converged',
                    'import_id' => $importId,
                    'bundle_kind' => $kind,
                    'candidate_ontology_version_id' =>
                        $locked['candidate_ontology_version_id'] !== null
                            ? (int)$locked[
                                'candidate_ontology_version_id'
                            ]
                            : null,
                    'candidate_score_revision_id' =>
                        $locked['candidate_score_revision_id'] !== null
                            ? (int)$locked[
                                'candidate_score_revision_id'
                            ]
                            : null,
                ],
                true
            );
            $db->exec('COMMIT');
            $reservationMs = (hrtime(true) - $reservationStarted) / 1000000;
        } catch (Throwable $error) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            if (
                function_exists('databaseIsLockError')
                && databaseIsLockError($error)
            ) {
                $db->prepare("
                    UPDATE ontology_activation_imports
                    SET last_error = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND status = 'activatable'
                ")->execute([
                    mb_substr(
                        'Retryable SQLite contention: '
                            . $error->getMessage(),
                        0,
                        1000,
                        'UTF-8'
                    ),
                    $importId,
                ]);
                throw $error;
            }
            $db->prepare("
                UPDATE ontology_activation_imports
                SET status = 'rebase_required',
                    last_error = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'activatable'
            ")->execute([
                mb_substr($error->getMessage(), 0, 1000, 'UTF-8'),
                $importId,
            ]);
            throw $error;
        }
        try {
            $db->prepare("
                UPDATE ontology_activation_imports
                SET last_reservation_ms = ?,
                    maximum_reservation_ms = MAX(
                        maximum_reservation_ms,
                        ?
                    ),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([
                $reservationMs,
                $reservationMs,
                $importId,
            ]);
        } catch (Throwable $ignored) {
        }
        return ingredientOntologyActivationImportRow($db, $importId);
    }

    function ingredientOntologyActivationFailImport(
        PDO $db,
        int $importId,
        Throwable|string $error,
        string $status = 'rebase_required'
    ): array {
        if (!in_array($status, ['rebase_required', 'failed'], true)) {
            throw new InvalidArgumentException(
                'ontology activation failure status is invalid'
            );
        }
        $message = $error instanceof Throwable
            ? $error->getMessage()
            : $error;
        $update = $db->prepare("
            UPDATE ontology_activation_imports
            SET status = ?,
                lease_token = NULL,
                leased_until = NULL,
                last_error = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND status NOT IN ('active', 'cleaned')
        ");
        $update->execute([
            $status,
            mb_substr($message, 0, 1000, 'UTF-8'),
            $importId,
        ]);
        return ingredientOntologyActivationImportRow($db, $importId);
    }

    function ingredientOntologyActivationCleanupImport(
        PDO $db,
        int $importId,
        int $maximumChunks = 50
    ): array {
        ingredientOntologyActivationAssertActiveDatabase($db);
        $maximumChunks = max(1, min(1000, $maximumChunks));
        $row = ingredientOntologyActivationImportRow($db, $importId);
        if (in_array((string)$row['status'], ['active', 'cleaned'], true)) {
            return $row;
        }
        $bundle = ingredientOntologyActivationImportBundle($row);
        $candidateId = ingredientOntologyActivationCandidateId($bundle);
        $kind = (string)$row['bundle_kind'];
        if ($kind === 'score') {
            if (
                (int)(recipeScoreState($db)['active_score_revision_id'] ?? 0)
                    === $candidateId
            ) {
                throw new RuntimeException(
                    'active ontology score import cannot be cleaned'
                );
            }
        } else {
            $activeVersion = ingredientOntologyV3ActiveVersion($db);
            if (
                $activeVersion !== null
                && (int)$activeVersion['id'] === $candidateId
            ) {
                throw new RuntimeException(
                    'active ontology import cannot be cleaned'
                );
            }
        }
        $db->prepare("
            UPDATE ontology_activation_imports
            SET status = 'purging',
                lease_token = NULL,
                leased_until = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND status NOT IN ('active', 'cleaned')
        ")->execute([$importId]);
        $specs = ingredientOntologyActivationSpecsForKind($kind);
        $chunks = 0;
        while ($chunks < $maximumChunks) {
            $next = $db->prepare("
                SELECT * FROM ontology_activation_import_tables
                WHERE import_id = ?
                  AND status <> 'purged'
                ORDER BY phase DESC
                LIMIT 1
            ");
            $next->execute([$importId]);
            $table = $next->fetch(PDO::FETCH_ASSOC);
            if (!$table) {
                break;
            }
            $tableName = (string)$table['table_name'];
            $spec = $specs[$tableName];
            if (!empty($spec['retain_on_cleanup'])) {
                $db->prepare("
                    UPDATE ontology_activation_import_tables
                    SET status = 'purged',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE import_id = ? AND table_name = ?
                ")->execute([$importId, $tableName]);
                $chunks++;
                continue;
            }
            $target = ingredientOntologyActivationQuoteIdentifier($tableName);
            if (!empty($spec['root'])) {
                $children = $db->prepare("
                    SELECT COUNT(*)
                    FROM ontology_activation_import_tables
                    WHERE import_id = ?
                      AND phase > 0
                      AND status <> 'purged'
                ");
                $children->execute([$importId]);
                if ((int)$children->fetchColumn() > 0) {
                    throw new RuntimeException(
                        'ontology activation cleanup root reached before children'
                    );
                }
            }
            $guardWas =
                ingredientOntologyV3RequirementPruneGuardEnabled($db);
            $readyWas = ingredientOntologyV3ReadyMutationGuardEnabled($db);
            $started = hrtime(true);
            dbBeginImmediateWithRetry($db);
            try {
                ingredientOntologyV3SetRequirementPruneGuard($db, true);
                ingredientOntologyV3SetReadyMutationGuard($db, true);
                if (!empty($spec['root'])) {
                    $delete = $db->prepare("
                        DELETE FROM {$target} WHERE id = ?
                    ");
                    $delete->execute([$candidateId]);
                    $deleted = $delete->rowCount();
                } else {
                    $delete = $db->prepare("
                        DELETE FROM {$target}
                        WHERE rowid IN (
                            SELECT rowid FROM {$target}
                            WHERE {$spec['selector']}
                            LIMIT 1000
                        )
                    ");
                    $delete->execute([$candidateId]);
                    $deleted = $delete->rowCount();
                }
                if ($deleted === 0 || !empty($spec['root'])) {
                    $db->prepare("
                        UPDATE ontology_activation_import_tables
                        SET status = 'purged',
                            updated_at = CURRENT_TIMESTAMP
                        WHERE import_id = ? AND table_name = ?
                    ")->execute([$importId, $tableName]);
                } else {
                    $remaining = $db->prepare("
                        SELECT 1 FROM {$target}
                        WHERE {$spec['selector']}
                        LIMIT 1
                    ");
                    $remaining->execute([$candidateId]);
                    if (!$remaining->fetchColumn()) {
                        $db->prepare("
                            UPDATE ontology_activation_import_tables
                            SET status = 'purged',
                                updated_at = CURRENT_TIMESTAMP
                            WHERE import_id = ? AND table_name = ?
                        ")->execute([$importId, $tableName]);
                    }
                }
                $elapsedMs = (hrtime(true) - $started) / 1000000;
                $db->prepare("
                    UPDATE ontology_activation_imports
                    SET last_reservation_ms = ?,
                        maximum_reservation_ms = MAX(
                            maximum_reservation_ms,
                            ?
                        ),
                        last_error = CASE
                            WHEN CAST(? AS REAL) > 250
                            THEN 'cleanup reservation exceeded 250 ms'
                            ELSE last_error
                        END,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ")->execute([
                    $elapsedMs,
                    $elapsedMs,
                    $elapsedMs,
                    $importId,
                ]);
                $db->exec('COMMIT');
            } catch (Throwable $error) {
                try {
                    $db->exec('ROLLBACK');
                } catch (Throwable $ignored) {
                }
                throw $error;
            } finally {
                ingredientOntologyV3SetReadyMutationGuard($db, $readyWas);
                ingredientOntologyV3SetRequirementPruneGuard($db, $guardWas);
            }
            $chunks++;
        }
        $remaining = $db->prepare("
            SELECT COUNT(*) FROM ontology_activation_import_tables
            WHERE import_id = ? AND status <> 'purged'
        ");
        $remaining->execute([$importId]);
        if ((int)$remaining->fetchColumn() === 0) {
            $db->prepare("
                UPDATE ontology_activation_imports
                SET status = 'cleaned',
                    last_error = '',
                    completed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'purging'
            ")->execute([$importId]);
        }
        return ingredientOntologyActivationImportRow($db, $importId);
    }

    function ingredientOntologyActivationEnabled(): bool {
        $value = function_exists('env')
            ? env('ONTOLOGY_ACTIVATION_ENABLED', 'true')
            : (getenv('ONTOLOGY_ACTIVATION_ENABLED') ?: 'true');
        return in_array(
            strtolower(trim((string)$value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    function ingredientOntologyActivationWorkDirectory(): string {
        $default = dirname(DB_PATH) . '/ontology-activation';
        $directory = trim((string)(
            function_exists('env')
                ? env('ONTOLOGY_ACTIVATION_DIRECTORY', $default)
                : (getenv('ONTOLOGY_ACTIVATION_DIRECTORY') ?: $default)
        ));
        if ($directory === '' || !str_starts_with($directory, '/')) {
            throw new RuntimeException(
                'ontology activation work directory must be absolute'
            );
        }
        if (
            !is_dir($directory)
            && !mkdir($directory, 0770, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                'ontology activation work directory could not be created'
            );
        }
        if (is_link($directory) || !is_writable($directory)) {
            throw new RuntimeException(
                'ontology activation work directory is unsafe'
            );
        }
        return rtrim($directory, '/');
    }

    function ingredientOntologyActivationDirectoryFiles(
        string $directory
    ): array {
        $files = [];
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isFile() && !$entry->isLink()) {
                $files[$entry->getPathname()] = true;
            }
        }
        return $files;
    }

    function ingredientOntologyActivationRemoveNewPayloadFiles(
        string $directory,
        array $before
    ): void {
        foreach (new DirectoryIterator($directory) as $entry) {
            if (!$entry->isFile() || $entry->isLink()) {
                continue;
            }
            $path = $entry->getPathname();
            if (isset($before[$path])) {
                continue;
            }
            if (preg_match(
                '/^(ontology|score|score-refresh)-[a-f0-9]{64}'
                    . '(?:-[a-f0-9]{16})?\.sqlite$/D',
                $entry->getFilename()
            )) {
                if (!unlink($path)) {
                    throw new RuntimeException(
                        'new ontology activation payload could not be removed'
                    );
                }
            }
        }
    }

    function ingredientOntologyActivationAssertDiskSpace(
        string $directory,
        string $sourcePath
    ): void {
        $free = disk_free_space($directory);
        $sourceBytes = is_file($sourcePath)
            ? (int)filesize($sourcePath)
            : 0;
        $reserve = max(
            2147483648,
            (int)ceil($sourceBytes * 0.20)
        );
        $required = $sourceBytes + $reserve;
        if ($free === false || $sourceBytes <= 0 || $free < $required) {
            throw new RuntimeException(
                'ontology activation disk reserve is insufficient'
            );
        }
    }

    function ingredientOntologyActivationDatabasePath(PDO $db): string {
        foreach (
            $db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC)
            as $database
        ) {
            if ((string)$database['name'] === 'main') {
                $path = (string)$database['file'];
                if ($path !== '' && is_file($path)) {
                    return $path;
                }
            }
        }
        throw new RuntimeException(
            'ontology activation database path is unavailable'
        );
    }

    function ingredientOntologyActivationOpenDatabase(string $path): PDO {
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        ingredientOntologyActivationConfigureDatabase($db);
        ingredientOntologyActivationRegisterGuardFunctions($db);
        return $db;
    }

    function ingredientOntologyActivationDeleteDatabaseCopy(
        string $path
    ): void {
        foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal']
            as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
        $lock = dirname($path) . '/.' . basename($path)
            . '.recipe-score.lock';
        if (is_file($lock)) {
            unlink($lock);
        }
    }

    function ingredientOntologyActivationManifestHash(
        array $document,
        string $prefix
    ): string {
        if ($prefix === 'bundle-set') {
            $hash = (string)($document['bundle_set_hash'] ?? '');
        } elseif ($prefix === 'score-bundle') {
            $hash = (string)($document['bundle_hash'] ?? '');
        } elseif ($prefix === 'acknowledgement') {
            $hash = (string)($document['document_hash'] ?? '');
        } elseif (preg_match(
            '/^validation-attestation-[1-9][0-9]*$/D',
            $prefix
        )) {
            $hash = (string)($document['attestation_hash'] ?? '');
        } else {
            throw new InvalidArgumentException(
                'ontology activation manifest prefix is invalid'
            );
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new InvalidArgumentException(
                'ontology activation manifest hash is invalid'
            );
        }
        return $hash;
    }

    function ingredientOntologyActivationWriteManifest(
        array $document,
        string $directory,
        string $prefix
    ): string {
        $hash = ingredientOntologyActivationManifestHash(
            $document,
            $prefix
        );
        $path = $directory . '/' . $prefix . '-' . $hash . '.json';
        recipeCliWriteFileAtomically(
            $path,
            json_encode(
                $document,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
        return $path;
    }

    function ingredientOntologyActivationResolveWorkDirectory(
        ?string $directory = null
    ): string {
        if ($directory === null) {
            return ingredientOntologyActivationWorkDirectory();
        }
        $directory = rtrim(trim($directory), '/');
        $resolved = realpath($directory);
        if (
            $directory === ''
            || $resolved === false
            || !is_dir($resolved)
            || is_link($directory)
            || !is_writable($resolved)
        ) {
            throw new InvalidArgumentException(
                'ontology activation artifact directory is invalid'
            );
        }
        return $resolved;
    }

    function ingredientOntologyActivationLoadManifest(
        string $path,
        string $prefix
    ): array {
        $resolved = realpath($path);
        $directory = realpath(dirname($path));
        if (
            $resolved === false
            || $directory === false
            || $resolved !== $directory . '/' . basename($path)
            || !is_file($resolved)
            || is_link($path)
        ) {
            throw new InvalidArgumentException(
                'ontology activation manifest path is invalid'
            );
        }
        clearstatcache(true, $resolved);
        $bytes = filesize($resolved);
        if ($bytes === false || $bytes <= 0 || $bytes > 1048577) {
            throw new RuntimeException(
                'ontology activation manifest size is invalid'
            );
        }
        $json = @file_get_contents($resolved);
        if ($json === false) {
            throw new RuntimeException(
                'ontology activation manifest could not be read'
            );
        }
        $document = json_decode(
            $json,
            true,
            128,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($document)) {
            throw new RuntimeException(
                'ontology activation manifest document is invalid'
            );
        }
        $hash = ingredientOntologyActivationManifestHash(
            $document,
            $prefix
        );
        if (
            basename($resolved) !== $prefix . '-' . $hash . '.json'
        ) {
            throw new RuntimeException(
                'ontology activation manifest filename is invalid'
            );
        }
        $payload = $document;
        if ($prefix === 'bundle-set') {
            unset($payload['bundle_set_hash']);
            if (
                (string)($document['schema_version'] ?? '')
                    !== 'ontology-activation-bundle-set-v2'
                || !is_array($document['ontology'] ?? null)
                || !is_array($document['score'] ?? null)
                || !hash_equals(
                    ingredientOntologyV3Hash($payload),
                    $hash
                )
            ) {
                throw new RuntimeException(
                    'ontology activation bundle-set manifest is invalid'
                );
            }
            ingredientOntologyActivationVerifyBundle(
                $document['ontology']
            );
            ingredientOntologyActivationVerifyBundle(
                $document['score']
            );
        } elseif ($prefix === 'score-bundle') {
            ingredientOntologyActivationVerifyBundle($document);
            if ((string)$document['bundle_kind'] !== 'score') {
                throw new RuntimeException(
                    'ontology activation score manifest kind is invalid'
                );
            }
        } elseif ($prefix === 'acknowledgement') {
            unset($payload['document_hash']);
            if (
                !in_array(
                    (string)($document['schema_version'] ?? ''),
                    [
                        'ontology-activation-acknowledgement-v1',
                        INGREDIENT_ONTOLOGY_ACTIVATION_ACKNOWLEDGEMENT_VERSION,
                    ],
                    true
                )
                || !hash_equals(
                    ingredientOntologyV3Hash($payload),
                    $hash
                )
            ) {
                throw new RuntimeException(
                    'ontology activation acknowledgement manifest is invalid'
                );
            }
        } elseif (preg_match(
            '/^validation-attestation-([1-9][0-9]*)$/D',
            $prefix,
            $matches
        )) {
            unset($payload['attestation_hash']);
            if (
                (string)($document['schema_version'] ?? '')
                    !== 'ontology-activation-validation-attestation-v1'
                || (int)($document['import_id'] ?? 0)
                    !== (int)$matches[1]
                || !hash_equals(
                    ingredientOntologyV3Hash($payload),
                    $hash
                )
            ) {
                throw new RuntimeException(
                    'ontology activation validation manifest is invalid'
                );
            }
        } else {
            throw new InvalidArgumentException(
                'ontology activation manifest prefix is invalid'
            );
        }
        return $document;
    }

    function ingredientOntologyActivationManifestCandidates(
        string $directory
    ): array {
        $candidates = [];
        foreach (new DirectoryIterator($directory) as $entry) {
            if (!$entry->isFile() || $entry->isLink()) {
                continue;
            }
            if (!preg_match(
                '/^(bundle-set|score-bundle|acknowledgement)-'
                    . '[a-f0-9]{64}\.json$/D',
                $entry->getFilename(),
                $matches
            )) {
                continue;
            }
            $candidates[] = [
                'path' => $entry->getPathname(),
                'prefix' => $matches[1],
                'modified_at' => $entry->getMTime(),
            ];
        }
        usort(
            $candidates,
            static fn(array $left, array $right): int =>
                $right['modified_at'] <=> $left['modified_at']
                    ?: strcmp($right['path'], $left['path'])
        );
        return $candidates;
    }

    function ingredientOntologyActivationImportByBundleHash(
        PDO $db,
        string $bundleHash
    ): ?array {
        $stmt = $db->prepare("
            SELECT *
            FROM ontology_activation_imports
            WHERE bundle_hash = ?
        ");
        $stmt->execute([$bundleHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    function ingredientOntologyActivationRemoveManifestFile(
        string $path
    ): void {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException(
                'ontology activation manifest could not be removed'
            );
        }
    }

    function ingredientOntologyActivationDiscardManifest(
        PDO $db,
        string $path,
        array $document
    ): void {
        $bundles = [];
        if (is_array($document['ontology'] ?? null)) {
            $bundles[] = $document['ontology'];
        }
        if (is_array($document['score'] ?? null)) {
            $bundles[] = $document['score'];
        } elseif (
            isset($document['bundle_hash'])
            && isset($document['payload'])
        ) {
            $bundles[] = $document;
        }
        foreach ($bundles as $bundle) {
            $bundleHash = (string)($bundle['bundle_hash'] ?? '');
            if (
                preg_match('/^[a-f0-9]{64}$/D', $bundleHash)
                && ingredientOntologyActivationImportByBundleHash(
                    $db,
                    $bundleHash
                ) !== null
            ) {
                continue;
            }
            $filename = basename((string)(
                $bundle['payload']['file'] ?? ''
            ));
            if ($filename === '') {
                continue;
            }
            $payloadPath = dirname($path) . '/' . $filename;
            if (
                is_file($payloadPath)
                && !is_link($payloadPath)
                && !unlink($payloadPath)
            ) {
                throw new RuntimeException(
                    'ontology activation stale payload could not be removed'
                );
            }
        }
        ingredientOntologyActivationRemoveManifestFile($path);
    }

    function ingredientOntologyActivationLogDiscardedArtifact(
        string $path,
        Throwable $error
    ): void {
        if (class_exists('EverLog', false)) {
            EverLog::warn(
                'ontology activation discarded stale artifact',
                [
                    'manifest' => basename($path),
                    'error' => mb_substr(
                        $error->getMessage(),
                        0,
                        300,
                        'UTF-8'
                    ),
                ]
            );
        }
    }

    function ingredientOntologyActivationRecoverPendingArtifacts(
        PDO $db,
        array $options = [],
        ?string $workDirectory = null
    ): ?array {
        $directory = ingredientOntologyActivationResolveWorkDirectory(
            $workDirectory
        );
        foreach (
            ingredientOntologyActivationManifestCandidates($directory)
            as $candidate
        ) {
            $path = (string)$candidate['path'];
            $prefix = (string)$candidate['prefix'];
            $document = [];
            try {
                $document = ingredientOntologyActivationLoadManifest(
                    $path,
                    $prefix
                );
                if ($prefix === 'acknowledgement') {
                    $hash = (string)$document['document_hash'];
                    $existing = $db->prepare("
                        SELECT status
                        FROM ontology_activation_acknowledgements
                        WHERE document_hash = ?
                    ");
                    $existing->execute([$hash]);
                    if ($existing->fetchColumn() === 'applied') {
                        ingredientOntologyActivationRemoveManifestFile(
                            $path
                        );
                        continue;
                    }
                    $result =
                        ingredientOntologyActivationWithLiveReservation(
                            $options,
                            'acknowledge_no_op',
                            static fn(): array =>
                                ingredientOntologyActivationAcknowledgeNoOp(
                                    $db,
                                    $document
                                )
                        );
                    ingredientOntologyActivationRemoveManifestFile($path);
                    return [
                        'action' => 'resume_acknowledgement',
                        'acknowledgement' => $result,
                    ];
                }
                if ($prefix === 'bundle-set') {
                    $imports = [];
                    $pending = [];
                    foreach (['ontology', 'score'] as $kind) {
                        $bundle = $document[$kind];
                        $existing =
                            ingredientOntologyActivationImportByBundleHash(
                                $db,
                                (string)$bundle['bundle_hash']
                            );
                        if ($existing !== null) {
                            if (in_array(
                                (string)$existing['status'],
                                [
                                    'rebase_required',
                                    'failed',
                                    'purging',
                                    'cleaned',
                                ],
                                true
                            )) {
                                throw new RuntimeException(
                                    'ontology activation retained bundle '
                                        . 'has a terminal failed import'
                                );
                            }
                            $imports[$kind] = $existing;
                            continue;
                        }
                        $pending[$kind] = [
                            'bundle' => $bundle,
                            'payload_path' =>
                                ingredientOntologyActivationResolvePayload(
                                    $bundle,
                                    $directory
                                ),
                        ];
                    }
                    if (!$pending) {
                        ingredientOntologyActivationRemoveManifestFile(
                            $path
                        );
                        continue;
                    }
                    ingredientOntologyActivationWithLiveReservation(
                        $options,
                        'register_bundle_set',
                        static function () use (
                            $db,
                            $directory,
                            $pending,
                            &$imports
                        ): void {
                            foreach ($pending as $kind => $artifact) {
                                $imports[$kind] =
                                    ingredientOntologyActivationRegisterImport(
                                        $db,
                                        $artifact['bundle'],
                                        $directory,
                                        $artifact['payload_path']
                                    );
                            }
                        }
                    );
                    ingredientOntologyActivationRemoveManifestFile($path);
                    return [
                        'action' => 'resume_bundle_set',
                        'ontology_import' => $imports['ontology'] ?? null,
                        'score_import' => $imports['score'] ?? null,
                        'scheduled' => true,
                    ];
                }
                $existing =
                    ingredientOntologyActivationImportByBundleHash(
                        $db,
                        (string)$document['bundle_hash']
                    );
                if ($existing !== null) {
                    if (in_array(
                        (string)$existing['status'],
                        [
                            'rebase_required',
                            'failed',
                            'purging',
                            'cleaned',
                        ],
                        true
                    )) {
                        throw new RuntimeException(
                            'ontology activation retained score bundle '
                                . 'has a terminal failed import'
                        );
                    }
                    ingredientOntologyActivationRemoveManifestFile($path);
                    continue;
                }
                $payloadPath =
                    ingredientOntologyActivationResolvePayload(
                        $document,
                        $directory
                    );
                $import =
                    ingredientOntologyActivationWithLiveReservation(
                        $options,
                        'register_score_import',
                        static fn(): array =>
                            ingredientOntologyActivationRegisterImport(
                                $db,
                                $document,
                                $directory,
                                $payloadPath
                            )
                    );
                ingredientOntologyActivationRemoveManifestFile($path);
                return [
                    'action' => 'resume_score_bundle',
                    'import' => $import,
                    'scheduled' => true,
                ];
            } catch (
                IngredientOntologyActivationReservationUnavailable $error
            ) {
                throw $error;
            } catch (Throwable $error) {
                if (
                    function_exists('databaseIsLockError')
                    && databaseIsLockError($error)
                ) {
                    throw $error;
                }
                if ($document) {
                    ingredientOntologyActivationDiscardManifest(
                        $db,
                        $path,
                        $document
                    );
                } else {
                    ingredientOntologyActivationRemoveManifestFile($path);
                }
                ingredientOntologyActivationLogDiscardedArtifact(
                    $path,
                    $error
                );
            }
        }
        return null;
    }

    function ingredientOntologyActivationWriteValidationAttestation(
        array $attestation,
        ?string $workDirectory = null
    ): string {
        $importId = (int)($attestation['import_id'] ?? 0);
        if ($importId <= 0) {
            throw new InvalidArgumentException(
                'ontology activation validation import is invalid'
            );
        }
        return ingredientOntologyActivationWriteManifest(
            $attestation,
            ingredientOntologyActivationResolveWorkDirectory(
                $workDirectory
            ),
            'validation-attestation-' . $importId
        );
    }

    function ingredientOntologyActivationLoadValidationAttestation(
        PDO $db,
        int $importId,
        ?string $workDirectory = null
    ): ?array {
        $directory = ingredientOntologyActivationResolveWorkDirectory(
            $workDirectory
        );
        $prefix = 'validation-attestation-' . $importId;
        $pattern = '/^' . preg_quote($prefix, '/') . '-'
            . '[a-f0-9]{64}\.json$/D';
        $candidates = [];
        foreach (new DirectoryIterator($directory) as $entry) {
            if (
                $entry->isFile()
                && !$entry->isLink()
                && preg_match($pattern, $entry->getFilename())
            ) {
                $candidates[] = [
                    'path' => $entry->getPathname(),
                    'modified_at' => $entry->getMTime(),
                ];
            }
        }
        usort(
            $candidates,
            static fn(array $left, array $right): int =>
                $right['modified_at'] <=> $left['modified_at']
                    ?: strcmp($right['path'], $left['path'])
        );
        $row = ingredientOntologyActivationImportRow($db, $importId);
        foreach ($candidates as $candidate) {
            $path = (string)$candidate['path'];
            try {
                $attestation =
                    ingredientOntologyActivationLoadManifest(
                        $path,
                        $prefix
                    );
                if (
                    !hash_equals(
                        (string)$row['bundle_hash'],
                        (string)($attestation['bundle_hash'] ?? '')
                    )
                    || (string)$row['bundle_kind']
                        !== (string)($attestation['bundle_kind'] ?? '')
                ) {
                    throw new RuntimeException(
                        'ontology activation validation artifact changed'
                    );
                }
                return [
                    'attestation' => $attestation,
                    'path' => $path,
                ];
            } catch (Throwable $error) {
                ingredientOntologyActivationRemoveManifestFile($path);
                ingredientOntologyActivationLogDiscardedArtifact(
                    $path,
                    $error
                );
            }
        }
        return null;
    }

    function ingredientOntologyActivationValidationCopy(
        PDO $liveDb,
        int $importId,
        array $options = []
    ): array {
        $observer = $options['validation_copy_observer'] ?? null;
        if ($observer !== null) {
            if (!is_callable($observer)) {
                throw new InvalidArgumentException(
                    'ontology activation validation observer is invalid'
                );
            }
            $observer($importId);
        }
        $directory = ingredientOntologyActivationResolveWorkDirectory(
            isset($options['work_directory'])
                ? (string)$options['work_directory']
                : null
        );
        $path = $directory . '/validation-' . $importId . '-'
            . bin2hex(random_bytes(8)) . '.sqlite';
        ingredientOntologyActivationAssertDiskSpace(
            $directory,
            ingredientOntologyActivationDatabasePath($liveDb)
        );
        databaseMaintenanceOnlineBackup(
            ingredientOntologyActivationDatabasePath($liveDb),
            $path
        );
        try {
            $copy = ingredientOntologyActivationOpenDatabase($path);
            $attestation = ingredientOntologyActivationValidateImportOnCopy(
                $copy,
                $importId,
                $options
            );
            $copy = null;
            return $attestation;
        } finally {
            ingredientOntologyActivationDeleteDatabaseCopy($path);
        }
    }

    function ingredientOntologyActivationDriveImport(
        PDO $db,
        int $importId,
        array $options = []
    ): array {
        $maximumLoops = max(
            1,
            min(1000, (int)($options['maximum_loops'] ?? 100))
        );
        $maximumChunks = max(
            1,
            min(10000, (int)($options['maximum_chunks'] ?? 500))
        );
        $maximumImportMs = max(
            100,
            min(
                10000,
                (int)($options['maximum_import_ms'] ?? 1500)
            )
        );
        $lockRetries = 0;
        $yieldAfterReservation =
            !empty($options['yield_after_live_reservation']);
        for ($loop = 0; $loop < $maximumLoops; $loop++) {
            $row = ingredientOntologyActivationImportRow($db, $importId);
            $status = (string)$row['status'];
            try {
                if (in_array($status, ['staging', 'importing'], true)) {
                    $bundle =
                        ingredientOntologyActivationImportBundle($row);
                    $verifiedPayloadPath =
                        ingredientOntologyActivationResolvePayload(
                            $bundle,
                            dirname((string)$row['payload_path'])
                        );
                    $row = ingredientOntologyActivationWithLiveReservation(
                        $options,
                        'import',
                        static fn(): array =>
                            ingredientOntologyActivationRunImport(
                                $db,
                                $importId,
                                $maximumChunks,
                                $verifiedPayloadPath,
                                $maximumImportMs
                            )
                    );
                    if ($yieldAfterReservation) {
                        return $row;
                    }
                    continue;
                }
                if ($status === 'verifying') {
                    $transport =
                        ingredientOntologyActivationVerifyImportedRows(
                            $db,
                            $importId
                        );
                    if (empty($transport['valid'])) {
                        continue;
                    }
                    $workDirectory = isset($options['work_directory'])
                        ? (string)$options['work_directory']
                        : null;
                    $stored =
                        ingredientOntologyActivationLoadValidationAttestation(
                            $db,
                            $importId,
                            $workDirectory
                        );
                    if ($stored === null) {
                        $attestation =
                            ingredientOntologyActivationValidationCopy(
                                $db,
                                $importId,
                                $options
                            );
                        $attestationPath =
                            ingredientOntologyActivationWriteValidationAttestation(
                                $attestation,
                                $workDirectory
                            );
                    } else {
                        $attestation = $stored['attestation'];
                        $attestationPath = (string)$stored['path'];
                    }
                    try {
                        $row =
                            ingredientOntologyActivationWithLiveReservation(
                                $options,
                                'validation_store',
                                static fn(): array =>
                                    ingredientOntologyActivationStoreValidation(
                                        $db,
                                        $importId,
                                        $attestation
                                    )
                            );
                    } catch (Throwable $error) {
                        if (
                            $error instanceof
                                IngredientOntologyActivationReservationUnavailable
                            || (
                                function_exists('databaseIsLockError')
                                && databaseIsLockError($error)
                            )
                        ) {
                            throw $error;
                        }
                        ingredientOntologyActivationRemoveManifestFile(
                            $attestationPath
                        );
                        throw $error;
                    }
                    try {
                        ingredientOntologyActivationRemoveManifestFile(
                            $attestationPath
                        );
                    } catch (Throwable $cleanupError) {
                        ingredientOntologyActivationLogDiscardedArtifact(
                            $attestationPath,
                            $cleanupError
                        );
                    }
                    if ($yieldAfterReservation) {
                        return $row;
                    }
                    continue;
                }
                if ($status === 'activatable') {
                    $row =
                        ingredientOntologyActivationWithLiveReservation(
                            $options,
                            'publication',
                            static fn(): array =>
                                ingredientOntologyActivationActivateImport(
                                    $db,
                                    $importId
                                )
                        );
                    if ($yieldAfterReservation) {
                        return $row;
                    }
                    continue;
                }
                if (in_array(
                    $status,
                    ['rebase_required', 'failed', 'purging'],
                    true
                )) {
                    $row = ingredientOntologyActivationWithLiveReservation(
                        $options,
                        'cleanup_import',
                        static fn(): array =>
                            ingredientOntologyActivationCleanupImport(
                                $db,
                                $importId,
                                $maximumChunks
                            )
                    );
                    if ($yieldAfterReservation) {
                        return $row;
                    }
                    continue;
                }
                return $row;
            } catch (Throwable $error) {
                if ($error instanceof
                    IngredientOntologyActivationReservationUnavailable
                ) {
                    throw $error;
                }
                if (
                    function_exists('databaseIsLockError')
                    && databaseIsLockError($error)
                ) {
                    $lockRetries++;
                    if ($yieldAfterReservation) {
                        return ingredientOntologyActivationImportRow(
                            $db,
                            $importId
                        );
                    }
                    $db->prepare("
                        UPDATE ontology_activation_imports
                        SET lease_token = NULL,
                            leased_until = NULL,
                            last_error = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                          AND status IN (
                              'staging', 'importing', 'verifying',
                              'activatable', 'purging'
                          )
                    ")->execute([
                        mb_substr(
                            'Retryable SQLite contention: '
                                . $error->getMessage(),
                            0,
                            1000,
                            'UTF-8'
                        ),
                        $importId,
                    ]);
                    if ($lockRetries >= 4) {
                        return ingredientOntologyActivationImportRow(
                            $db,
                            $importId
                        );
                    }
                    usleep(150000 * $lockRetries);
                    continue;
                }
                $expectedOutcome = $error instanceof
                    IngredientOntologyActivationExpectedOutcome;
                $failed =
                    ingredientOntologyActivationWithLiveReservation(
                        $options,
                        $expectedOutcome
                            ? 'mark_import_rebase_required'
                            : 'mark_import_failed',
                        static function () use (
                            $db,
                            $importId,
                            $error,
                            $expectedOutcome
                        ): array {
                            $row = ingredientOntologyActivationFailImport(
                                $db,
                                $importId,
                                $error,
                                $expectedOutcome
                                    ? 'rebase_required'
                                    : 'failed'
                            );
                            if ($expectedOutcome) {
                                $record =
                                    ingredientOntologyActivationRecordOutcome(
                                    $db,
                                    $error->outcomeKind(),
                                    $error->details() + [
                                        'import_id' => $importId,
                                        'message' => $error->getMessage(),
                                    ],
                                    true,
                                    30
                                );
                                if (!empty($record['escalated'])) {
                                    $db->prepare("
                                        UPDATE ontology_activation_imports
                                        SET status = 'failed',
                                            last_error = ?,
                                            updated_at =
                                                CURRENT_TIMESTAMP
                                        WHERE id = ?
                                          AND status =
                                                'rebase_required'
                                    ")->execute([
                                        'Expected activation rebase did not converge.',
                                        $importId,
                                    ]);
                                    $row =
                                        ingredientOntologyActivationImportRow(
                                            $db,
                                            $importId
                                        );
                                }
                            }
                            return $row;
                        }
                    );
                if (
                    !$expectedOutcome
                    && (string)($failed['status'] ?? '') === 'failed'
                ) {
                    $failureRecorded = true;
                    try {
                        ingredientOntologyActivationWithLiveReservation(
                            $options,
                            'record_import_failure',
                            static function () use (
                                $db,
                                $importId,
                                $error
                            ): void {
                                $db->prepare("
                                    UPDATE ontology_activation_state
                                    SET failure_count =
                                            failure_count + 1,
                                        last_error = ?,
                                        last_outcome_kind =
                                            'integrity_failure',
                                        last_outcome_json = ?,
                                        last_outcome_at =
                                            CURRENT_TIMESTAMP,
                                        next_attempt_at =
                                            datetime('now', '+60 seconds'),
                                        updated_at = CURRENT_TIMESTAMP
                                    WHERE id = 1
                                ")->execute([
                                    mb_substr(
                                        $error->getMessage(),
                                        0,
                                        1000,
                                        'UTF-8'
                                    ),
                                    ingredientOntologyActivationStableJson([
                                        'import_id' => $importId,
                                        'error' => mb_substr(
                                            $error->getMessage(),
                                            0,
                                            1000,
                                            'UTF-8'
                                        ),
                                    ]),
                                ]);
                            }
                        );
                    } catch (
                        IngredientOntologyActivationReservationUnavailable
                        $reservationError
                    ) {
                        $failureRecorded = false;
                    }
                    $failed['activation_state_recorded'] =
                        $failureRecorded;
                    return $failed;
                }
                if ($yieldAfterReservation) {
                    return $failed;
                }
            }
        }
        return ingredientOntologyActivationImportRow($db, $importId);
    }

    function ingredientOntologyActivationPendingImport(PDO $db): ?array {
        $row = $db->query("
            SELECT * FROM ontology_activation_imports
            WHERE status IN (
                'staging', 'importing', 'verifying', 'activatable'
            )
            ORDER BY
                CASE bundle_kind WHEN 'ontology' THEN 0 ELSE 1 END,
                id
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    function ingredientOntologyActivationPendingCleanupImport(
        PDO $db
    ): ?array {
        $row = $db->query("
            SELECT * FROM ontology_activation_imports
            WHERE status IN ('rebase_required', 'purging')
            ORDER BY
                CASE bundle_kind WHEN 'ontology' THEN 0 ELSE 1 END,
                id
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    function ingredientOntologyActivationWaitingOntologyImport(
        PDO $db
    ): ?array {
        $row = $db->query("
            SELECT ontology_import.*
            FROM ontology_activation_imports ontology_import
            WHERE ontology_import.bundle_kind = 'ontology'
              AND ontology_import.status = 'complete'
              AND ontology_import.candidate_ontology_version_id IS NOT NULL
              AND ontology_import.parent_ontology_version_id = (
                  SELECT score.ontology_version_id
                  FROM recipe_score_state state
                  JOIN recipe_score_revisions score
                    ON score.id = state.active_score_revision_id
                  WHERE state.id = 1
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM recipe_score_state state
                  JOIN recipe_score_revisions score
                    ON score.id = state.active_score_revision_id
                  WHERE state.id = 1
                    AND score.ontology_version_id =
                        ontology_import.candidate_ontology_version_id
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM ontology_activation_imports score_import
                  WHERE score_import.bundle_kind = 'score'
                    AND score_import.candidate_ontology_version_id =
                        ontology_import.candidate_ontology_version_id
                    AND score_import.status NOT IN (
                        'cleaned', 'rebase_required', 'failed'
                    )
              )
            ORDER BY ontology_import.id DESC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    function ingredientOntologyActivationStaleOntologyImport(
        PDO $db
    ): ?array {
        $row = $db->query("
            SELECT ontology_import.*
            FROM ontology_activation_imports ontology_import
            WHERE ontology_import.bundle_kind = 'ontology'
              AND ontology_import.status = 'complete'
              AND ontology_import.candidate_ontology_version_id <> (
                  SELECT score.ontology_version_id
                  FROM recipe_score_state state
                  JOIN recipe_score_revisions score
                    ON score.id = state.active_score_revision_id
                  WHERE state.id = 1
              )
              AND ontology_import.parent_ontology_version_id <> (
                  SELECT score.ontology_version_id
                  FROM recipe_score_state state
                  JOIN recipe_score_revisions score
                    ON score.id = state.active_score_revision_id
                  WHERE state.id = 1
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM recipe_score_revisions retained_score
                  WHERE retained_score.ontology_version_id =
                        ontology_import.candidate_ontology_version_id
                    AND retained_score.status = 'ready'
              )
            ORDER BY ontology_import.id
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    function ingredientOntologyActivationOntologyStateRequiresBuild(
        PDO $db,
        ?array $active = null
    ): bool {
        $active ??= recipeScoreActiveRevision($db);
        if ($active === null || $active['ontology_version_id'] === null) {
            return false;
        }
        $productDriftHandled =
            ingredientOntologyActivationProductDriftHandledByAnnex(
                $db,
                $active
            );
        $productDriftIsIncremental = $productDriftHandled
            && ingredientOntologyActivationProductDriftIsIncremental(
                $db,
                $active
            );
        if (
            ingredientOntologyActivationCorpusDrifted($db)
            && !$productDriftIsIncremental
        ) {
            return true;
        }
        $state = recipeScoreState($db);
        if (
            !$productDriftIsIncremental
            && (
                (string)(
                    $active['ontology_source_lineage_hash'] ?? ''
                ) !== ''
                || (string)(
                    $state['ontology_source_lineage_hash'] ?? ''
                ) !== ''
            )
        ) {
            return true;
        }
        if (
            !$productDriftIsIncremental
            && (
                (int)($active['ontology_source_revision'] ?? -1)
                    !== (int)$state['ontology_source_revision']
                || strlen((string)$state['ontology_source_hash']) !== 64
                || !hash_equals(
                    (string)($active['ontology_source_hash'] ?? ''),
                    (string)$state['ontology_source_hash']
                )
            )
        ) {
            return true;
        }
        $version = ingredientOntologyV3Version(
            $db,
            (int)$active['ontology_version_id']
        );
        return $version === null
            || !hash_equals(
                (string)$version['schema_hash'],
                ingredientOntologyV3SchemaHash()
            )
            || !hash_equals(
                (string)$version['prompt_hash'],
                ingredientOntologyV3PromptHash()
            )
            || !hash_equals(
                (string)$version['model_hash'],
                ingredientOntologyV3ModelHash(
                    (string)$version['model_name']
                )
            )
            || !hash_equals(
                (string)$version['controller_policy_hash'],
                ingredientOntologyControllerPolicyHash()
            );
    }

    function ingredientOntologyActivationNeedsOntologyBuild(
        PDO $db
    ): bool {
        return ingredientOntologyActivationPendingIntentCount($db) > 0
            || ingredientOntologyActivationOntologyStateRequiresBuild(
               $db
            );
    }

    function ingredientOntologyActivationShouldPrioritizeMaintenanceScoreRefresh(
        PDO $db
    ): bool {
        $maintenancePending = (int)$db->query("
            SELECT COUNT(*)
            FROM recipe_score_pending_recipes
            WHERE lane = 'maintenance'
        ")->fetchColumn();
        if ($maintenancePending <= 0) {
            return false;
        }
        $active = recipeScoreActiveRevision($db);
        return $active !== null
            && ingredientOntologyActivationNeedsScoreBuild($db)
            && !ingredientOntologyActivationOntologyStateRequiresBuild(
               $db,
               $active
            );
    }

    function ingredientOntologyActivationPendingIntentCount(
        PDO $db
    ): int {
        return (int)$db->query("
            SELECT COUNT(*)
            FROM ontology_generation_intents intent
            JOIN ontology_controller_jobs job
              ON job.id = intent.source_job_id
            WHERE intent.status = 'pending'
              AND (
                  job.next_attempt_at IS NULL
                  OR job.next_attempt_at <= CURRENT_TIMESTAMP
              )
        ")->fetchColumn();
    }

    function ingredientOntologyActivationReconcileProductAnnex(
        PDO $db
    ): array {
        if (
            !function_exists(
                'ingredientOntologyV3IdentityAdmissionPublishProduct'
            )
        ) {
            return [
                'available' => false,
                'product_count' => 0,
                'changed_product_count' => 0,
            ];
        }
        $active = ingredientOntologyV3ActiveVersion($db);
        if ($active === null) {
            return [
                'available' => false,
                'product_count' => 0,
                'changed_product_count' => 0,
            ];
        }
        $versionId = (int)$active['id'];
        $products = $db->prepare("
            SELECT DISTINCT inventory.product_id
            FROM inventory
            LEFT JOIN ingredient_ontology_identity_annex annex
              ON annex.product_id = inventory.product_id
            WHERE annex.product_id IS NULL
               OR annex.ontology_version_id <> ?
            ORDER BY inventory.product_id
        ");
        $products->execute([$versionId]);
        $productIds = array_map(
            'intval',
            $products->fetchAll(PDO::FETCH_COLUMN)
        );
        $changed = [];
        foreach ($productIds as $productId) {
            dbBeginImmediateWithRetry($db);
            try {
                $result =
                    ingredientOntologyV3IdentityAdmissionPublishProduct(
                        $db,
                        $productId,
                        $versionId,
                        'active_ontology_identity_reconciled',
                        true
                    );
                if (
                    defined('RECIPE_BACKEND_TEST_MODE')
                    && RECIPE_BACKEND_TEST_MODE
                    && is_callable(
                        $GLOBALS[
                            'INGREDIENT_ONTOLOGY_V3_AFTER_RECONCILE_PRODUCT'
                        ] ?? null
                    )
                ) {
                    ($GLOBALS[
                        'INGREDIENT_ONTOLOGY_V3_AFTER_RECONCILE_PRODUCT'
                    ])($db, $productId, $result);
                }
                $db->exec('COMMIT');
            } catch (Throwable $error) {
                try {
                    $db->exec('ROLLBACK');
                } catch (Throwable $ignored) {
                }
                throw $error;
            }
            if (
                !empty($result['semantic_changed'])
                || !empty($result['score_required'])
            ) {
                $changed[] = $productId;
            }
        }
        return [
            'available' => true,
            'ontology_version_id' => $versionId,
            'product_count' => count($productIds),
            'changed_product_count' => count($changed),
            'changed_product_ids' => $changed,
        ];
    }

    function ingredientOntologyActivationNeedsScoreBuild(PDO $db): bool {
        $active = recipeScoreActiveRevision($db);
        if (
            $active === null
            || $active['ontology_version_id'] === null
            || recipeScoreRevisionStatus($db, $active) === 'fresh'
        ) {
            return false;
        }
        if (ingredientOntologyActivationProductDriftIsIncremental(
            $db,
            $active
        ) || ingredientOntologyActivationMaintenanceDriftIsIncremental(
            $db,
            $active
        )) {
            return false;
        }
        return true;
    }

    function ingredientOntologyActivationBuildGeneration(
        PDO $liveDb,
        array $options = []
    ): array {
        if (!ingredientOntologyControllerEnabled()) {
            throw new RuntimeException(
                'ontology_activation_generation_requires_enabled_controller'
            );
        }
        $directory = ingredientOntologyActivationWorkDirectory();
        $before = ingredientOntologyActivationDirectoryFiles($directory);
        $workspace = $directory . '/generation-'
            . bin2hex(random_bytes(8)) . '.sqlite';
        ingredientOntologyActivationAssertDiskSpace(
            $directory,
            ingredientOntologyActivationDatabasePath($liveDb)
        );
        databaseMaintenanceOnlineBackup(
            ingredientOntologyActivationDatabasePath($liveDb),
            $workspace
        );
        try {
            $copy = ingredientOntologyActivationOpenDatabase($workspace);
            $built = ingredientOntologyControllerBuildActivationBundle(
                $copy,
                [
                    'limit' => max(
                        1,
                        min(50, (int)($options['intent_limit'] ?? 50))
                    ),
                    'maximum_cycles' => 100,
                    'batch_size' => max(
                        1,
                        min(1000, (int)($options['batch_size'] ?? 250))
                    ),
                    'payload_directory' => $directory,
                    'provider' => (string)(
                        $options['provider']
                            ?? ingredientOntologyControllerProvider()
                    ),
                    'model' => (string)(
                        $options['model']
                            ?? ingredientOntologyControllerProposerModel()
                    ),
                    'critic_provider' => (string)(
                        $options['critic_provider']
                            ?? ingredientOntologyControllerCriticProvider()
                    ),
                    'critic_model' => (string)(
                        $options['critic_model']
                            ?? ingredientOntologyControllerCriticModel()
                    ),
                    'allow_network' => !empty($options['allow_network']),
                    'allow_test_fixture' =>
                        !empty($options['allow_test_fixture']),
                    'critic' => $options['critic'] ?? null,
                    'bypass_cadence' => true,
                    'bypass_debounce' => true,
                    'promote' => false,
                    'disable_automatic_promotion' => true,
                ]
            );
            $copy = null;
            if (is_array($built['acknowledgement'] ?? null)) {
                ingredientOntologyActivationWriteManifest(
                    $built['acknowledgement'],
                    $directory,
                    'acknowledgement'
                );
                return [
                    'acknowledgement' => $built['acknowledgement'],
                ];
            }
            if (!empty($built['no_work'])) {
                return [
                    'no_work' => true,
                    'claimed_intents' =>
                        (int)($built['claimed_intents'] ?? 0),
                    'superseded_source_job_ids' => array_map(
                        'intval',
                        (array)($built[
                            'superseded_source_job_ids'
                        ] ?? [])
                    ),
                ];
            }
            $bundleSet = $built['bundle_set'];
            ingredientOntologyActivationWriteManifest(
                $bundleSet,
                $directory,
                'bundle-set'
            );
            return $bundleSet;
        } catch (Throwable $error) {
            ingredientOntologyActivationRemoveNewPayloadFiles(
                $directory,
                $before
            );
            throw $error;
        } finally {
            ingredientOntologyActivationDeleteDatabaseCopy($workspace);
        }
    }

    function ingredientOntologyActivationBuildRefresh(
            PDO $liveDb,
            array $options = []
        ): array {
            $directory = ingredientOntologyActivationWorkDirectory();
            $before = ingredientOntologyActivationDirectoryFiles($directory);
            $workspace = $directory . '/generation-'
                . bin2hex(random_bytes(8)) . '.sqlite';
            ingredientOntologyActivationAssertDiskSpace(
                $directory,
                ingredientOntologyActivationDatabasePath($liveDb)
            );
            databaseMaintenanceOnlineBackup(
                ingredientOntologyActivationDatabasePath($liveDb),
                $workspace
            );
            try {
                $copy = ingredientOntologyActivationOpenDatabase($workspace);
                $snapshot =
                    ingredientOntologyActivationCaptureBuildSnapshot($copy);
                $bundleSet =
                    ingredientOntologyActivationBuildRefreshBundleSet(
                        $copy,
                        $snapshot,
                        $directory,
                        $options
                    );
                $copy = null;
                ingredientOntologyActivationWriteManifest(
                    $bundleSet,
                    $directory,
                    'bundle-set'
                );
                return $bundleSet;
            } catch (Throwable $error) {
                ingredientOntologyActivationRemoveNewPayloadFiles(
                    $directory,
                    $before
                );
                throw $error;
            } finally {
                ingredientOntologyActivationDeleteDatabaseCopy($workspace);
            }
        }

    function ingredientOntologyActivationBuildScoreRefresh(
        PDO $liveDb,
        int $ontologyVersionId,
        array $intents = [],
        array $options = []
    ): array {
        $directory = ingredientOntologyActivationWorkDirectory();
        $before = ingredientOntologyActivationDirectoryFiles($directory);
        $workspace = $directory . '/score-'
            . bin2hex(random_bytes(8)) . '.sqlite';
        ingredientOntologyActivationAssertDiskSpace(
            $directory,
            ingredientOntologyActivationDatabasePath($liveDb)
        );
        databaseMaintenanceOnlineBackup(
            ingredientOntologyActivationDatabasePath($liveDb),
            $workspace
        );
        try {
            $copy = ingredientOntologyActivationOpenDatabase($workspace);
            $snapshot =
                ingredientOntologyActivationCaptureBuildSnapshot($copy);
            $bundle = ingredientOntologyActivationBuildScoreBundle(
                $copy,
                $ontologyVersionId,
                $snapshot,
                $directory,
                $intents,
                $options
            );
            $copy = null;
            ingredientOntologyActivationWriteManifest(
                $bundle,
                $directory,
                'score-bundle'
            );
            return $bundle;
        } catch (Throwable $error) {
            ingredientOntologyActivationRemoveNewPayloadFiles(
                $directory,
                $before
            );
            throw $error;
        } finally {
            ingredientOntologyActivationDeleteDatabaseCopy($workspace);
        }
    }

    function ingredientOntologyActivationRemovePayload(array $bundle): void {
        $directory = ingredientOntologyActivationWorkDirectory();
        $filename = basename((string)($bundle['payload']['file'] ?? ''));
        if ($filename === '') {
            return;
        }
        $path = $directory . '/' . $filename;
        if (is_file($path)) {
            unlink($path);
        }
    }

    function ingredientOntologyActivationManifestPayloadPaths(
        string $directory
    ): array {
        $referenced = [];
        foreach (
            ingredientOntologyActivationManifestCandidates($directory)
            as $candidate
        ) {
            try {
                $document = ingredientOntologyActivationLoadManifest(
                    (string)$candidate['path'],
                    (string)$candidate['prefix']
                );
            } catch (Throwable $error) {
                // Recovery owns invalid-manifest disposal and diagnostics.
                continue;
            }
            $bundles = [];
            if (is_array($document['ontology'] ?? null)) {
                $bundles[] = $document['ontology'];
            }
            if (is_array($document['score'] ?? null)) {
                $bundles[] = $document['score'];
            } elseif (
                isset($document['bundle_hash'])
                && is_array($document['payload'] ?? null)
            ) {
                $bundles[] = $document;
            }
            foreach ($bundles as $bundle) {
                $filename = basename((string)(
                    $bundle['payload']['file'] ?? ''
                ));
                if (
                    $filename !== ''
                    && preg_match('/^[A-Za-z0-9._-]+$/D', $filename)
                ) {
                    $referenced[$directory . '/' . $filename] = true;
                }
            }
        }
        return $referenced;
    }

    function ingredientOntologyActivationCleanupWorkFiles(
        PDO $db,
        ?string $workDirectory = null
    ): array {
        $directory = ingredientOntologyActivationResolveWorkDirectory(
            $workDirectory
        );
        $referenced = [];
        $paths = $db->query("
            SELECT payload_path
            FROM ontology_activation_imports
            WHERE status NOT IN ('active', 'complete', 'cleaned')
        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($paths as $path) {
            $referenced[(string)$path] = true;
        }
        $referenced +=
            ingredientOntologyActivationManifestPayloadPaths($directory);
        $deleted = [];
        $errors = [];
        $now = time();
        foreach (new DirectoryIterator($directory) as $entry) {
            if (!$entry->isFile() || $entry->isLink()) {
                continue;
            }
            $name = $entry->getFilename();
            $path = $entry->getPathname();
            $age = $now - $entry->getMTime();
            $workspace = preg_match(
                '/^(generation|score)-[a-f0-9]{16}\.sqlite'
                    . '(-wal|-shm|-journal)?$/D',
                $name
            );
            $validation = preg_match(
                '/^validation-[1-9][0-9]*-[a-f0-9]{16}\.sqlite'
                    . '(-wal|-shm|-journal)?$/D',
                $name
            );
            $lock = preg_match(
                '/^\.(generation|score|validation)-.+'
                    . '\.sqlite\.recipe-score\.lock$/D',
                $name
            );
            $temporary = preg_match(
                '/^(generation|score|validation|ontology)-.+'
                    . '\.sqlite\.tmp\.[1-9][0-9]*\.[a-f0-9]{12}'
                    . '(?:-wal|-shm|-journal)?$/D',
                $name
            ) || preg_match(
                '/^(bundle-set|score-bundle|acknowledgement|'
                    . 'validation-attestation-[1-9][0-9]*)-.+'
                    . '\.json\.tmp-[a-f0-9]{16}$/D',
                $name
            );
            if (
                ($workspace || $validation || $lock || $temporary)
                && $age >= 60
            ) {
                if (unlink($path)) {
                    $deleted[] = $path;
                } else {
                    $errors[] = $path;
                }
                continue;
            }
            $payload = preg_match(
                '/^(ontology|score|score-refresh)-[a-f0-9]{64}'
                    . '(?:-[a-f0-9]{16})?\.sqlite$/D',
                $name
            );
            if (
                $payload
                && $age >= 86400
                && !isset($referenced[$path])
            ) {
                if (unlink($path)) {
                    $deleted[] = $path;
                } else {
                    $errors[] = $path;
                }
            }
            $manifest = preg_match(
                '/^(bundle-set|score-bundle|acknowledgement|'
                    . 'validation-attestation-[1-9][0-9]*)-'
                    . '[a-f0-9]{64}\.json$/D',
                $name
            );
            if ($manifest && $age >= 2592000) {
                if (unlink($path)) {
                    $deleted[] = $path;
                } else {
                    $errors[] = $path;
                }
            }
        }
        return [
            'deleted' => count($deleted),
            'paths' => $deleted,
            'errors' => $errors,
        ];
    }

    function ingredientOntologyActivationPruneCdc(
        PDO $db,
        int $limit = 1000
    ): int {
        $limit = max(1, min(10000, $limit));
        $delete = $db->prepare("
            DELETE FROM ontology_activation_cdc
            WHERE id IN (
                SELECT candidate.id
                FROM ontology_activation_cdc candidate
                WHERE candidate.created_at < datetime('now', '-7 days')
                  AND candidate.id < (
                      SELECT MAX(latest.id)
                      FROM ontology_activation_cdc latest
                      WHERE latest.domain = candidate.domain
                  )
                ORDER BY candidate.id
                LIMIT {$limit}
            )
        ");
        $delete->execute();
        return $delete->rowCount();
    }

    function ingredientOntologyActivationPruneCdcBestEffort(
        PDO $db,
        array $options = []
    ): int {
        try {
            return ingredientOntologyActivationWithLiveReservation(
                $options,
                'cdc_prune',
                static fn(): int =>
                    ingredientOntologyActivationPruneCdc($db)
            );
        } catch (
            IngredientOntologyActivationReservationUnavailable $error
        ) {
            return 0;
        } catch (Throwable $error) {
            if (
                !function_exists('databaseIsLockError')
                || !databaseIsLockError($error)
            ) {
                throw $error;
            }
            return 0;
        }
    }

    function ingredientOntologyActivationStartScoreRefresh(
        PDO $db,
        array $options
    ): array {
        $activeVersion = ingredientOntologyV3ActiveVersion($db);
        $scoreBundle = ingredientOntologyActivationBuildScoreRefresh(
            $db,
            (int)$activeVersion['id'],
            [],
            $options
        );
        $directory = ingredientOntologyActivationWorkDirectory();
        $verifiedPayloadPath =
            ingredientOntologyActivationResolvePayload(
                $scoreBundle,
                $directory
            );
        $import =
            ingredientOntologyActivationWithLiveReservation(
                $options,
                'register_score_import',
                static fn(): array =>
                    ingredientOntologyActivationRegisterImport(
                        $db,
                        $scoreBundle,
                        $directory,
                        $verifiedPayloadPath
                    )
            );
        if (!empty($options['yield_after_live_reservation'])) {
            return [
                'action' => 'refresh_score',
                'import' => $import,
            ];
        }
        return [
            'action' => 'refresh_score',
            'import' => ingredientOntologyActivationDriveImport(
                $db,
                (int)$import['id'],
                $options
            ),
        ];
    }

    function ingredientOntologyActivationRunOnce(
        PDO $db,
        array $options = []
    ): array {
        ingredientOntologyActivationAssertActiveDatabase($db);
        $workCleanup = ingredientOntologyActivationCleanupWorkFiles($db);
        $cdcPruned = ingredientOntologyActivationPruneCdcBestEffort(
            $db,
            $options
        );
        $identityMaintenance =
            ingredientOntologyActivationWithLiveReservation(
                $options,
                'identity_migration',
                static function () use ($db): array {
                    $identitySync =
                        ingredientOntologyV3IdentityAdmissionSync(
                            $db
                        );
                    $remaining = max(
                        (int)(
                            $identitySync[
                                'resolver_migration'
                            ]['remaining'] ?? 0
                        ),
                        (int)(
                            $identitySync[
                                'recipe_resolver_migration'
                            ]['remaining'] ?? 0
                        )
                    );
                    return [
                        'sync' => $identitySync,
                        'remaining' => $remaining,
                        'product_reconcile' => $remaining > 0
                            ? null
                            : ingredientOntologyActivationReconcileProductAnnex(
                                $db
                            ),
                    ];
                }
            );
        $identitySync = $identityMaintenance['sync'];
        $identityMigrationRemaining = max(
            (int)(
                $identitySync['resolver_migration']['remaining']
                    ?? 0
            ),
            (int)(
                $identitySync[
                    'recipe_resolver_migration'
                ]['remaining'] ?? 0
            )
        );
        if ($identityMigrationRemaining > 0) {
            return [
                'action' => 'migrate_identity_resolvers',
                'remaining' => $identityMigrationRemaining,
                'product_migration' =>
                    $identitySync['resolver_migration'] ?? [],
                'recipe_migration' =>
                    $identitySync['recipe_resolver_migration'] ?? [],
                'work_cleanup' => $workCleanup,
                'cdc_pruned' => $cdcPruned,
            ];
        }
        $pending = ingredientOntologyActivationPendingImport($db);
        if ($pending !== null) {
            $result = ingredientOntologyActivationDriveImport(
                $db,
                (int)$pending['id'],
                $options
            );
            if (in_array(
                (string)$result['status'],
                ['active', 'complete', 'cleaned'],
                true
            )) {
                ingredientOntologyActivationRemovePayload(
                    ingredientOntologyActivationImportBundle($result)
                );
            }
            return [
                'action' => 'drive_import',
                'import' => $result,
                'work_cleanup' => $workCleanup,
                'cdc_pruned' => $cdcPruned,
            ];
        }

        $recovered =
            ingredientOntologyActivationRecoverPendingArtifacts(
                $db,
                $options
            );
        if ($recovered !== null) {
            return $recovered + [
                'work_cleanup' => $workCleanup,
                'cdc_pruned' => $cdcPruned,
            ];
        }

        $waiting = ingredientOntologyActivationWaitingOntologyImport($db);
        if ($waiting !== null) {
            $ontologyBundle =
                ingredientOntologyActivationImportBundle($waiting);
            try {
                $scoreBundle =
                    ingredientOntologyActivationBuildScoreRefresh(
                        $db,
                        (int)$waiting['candidate_ontology_version_id'],
                        (array)($ontologyBundle['intents'] ?? []),
                        $options
                    );
            } catch (Throwable $error) {
                ingredientOntologyActivationWithLiveReservation(
                    $options,
                    'mark_rebase_required',
                    static fn(): array =>
                        ingredientOntologyActivationFailImport(
                            $db,
                            (int)$waiting['id'],
                            $error
                        )
                );
                return [
                    'action' => 'rebase_ontology',
                    'error' => $error->getMessage(),
                    'work_cleanup' => $workCleanup,
                    'cdc_pruned' => $cdcPruned,
                ];
            }
            try {
                $directory =
                    ingredientOntologyActivationWorkDirectory();
                $verifiedPayloadPath =
                    ingredientOntologyActivationResolvePayload(
                        $scoreBundle,
                        $directory
                    );
                $import =
                    ingredientOntologyActivationWithLiveReservation(
                        $options,
                        'register_score_import',
                        static fn(): array =>
                            ingredientOntologyActivationRegisterImport(
                                $db,
                                $scoreBundle,
                                $directory,
                                $verifiedPayloadPath
                            )
                    );
                if (!empty(
                    $options['yield_after_live_reservation']
                )) {
                    return [
                        'action' => 'build_score',
                        'import' => $import,
                        'work_cleanup' => $workCleanup,
                        'cdc_pruned' => $cdcPruned,
                    ];
                }
            } catch (Throwable $error) {
                throw $error;
            }
            return [
                'action' => 'build_score',
                'import' => ingredientOntologyActivationDriveImport(
                    $db,
                    (int)$import['id'],
                    $options
                ),
                'work_cleanup' => $workCleanup,
                'cdc_pruned' => $cdcPruned,
            ];
        }

        $activeScore = recipeScoreActiveRevision($db);
        if (
            $activeScore !== null
            && recipeScoreRevisionStatus($db, $activeScore)
                !== 'fresh'
            && !ingredientOntologyActivationNeedsScoreBuild($db)
        ) {
            return [
                'action' => 'none',
                'reason' => 'incremental_score_pending',
                'work_cleanup' => $workCleanup,
                'cdc_pruned' => $cdcPruned,
            ];
        }

        if (
            ingredientOntologyActivationShouldPrioritizeMaintenanceScoreRefresh(
                $db
            )
        ) {
            return ingredientOntologyActivationStartScoreRefresh(
                $db,
                $options
            ) + [
                'generation_deferred' => [
                    'reason' => 'maintenance_score_refresh_priority',
                    'pending_intent_count' =>
                        ingredientOntologyActivationPendingIntentCount(
                            $db
                        ),
                ],
                'work_cleanup' => $workCleanup,
                'cdc_pruned' => $cdcPruned,
            ];
        }

        $needsOntologyBuild =
            ingredientOntologyActivationNeedsOntologyBuild($db);
        if (
            $needsOntologyBuild
            && function_exists(
                'recipeCookidooMetadataBackfillHasPendingWork'
            )
            && recipeCookidooMetadataBackfillHasPendingWork($db)
        ) {
            $deferred =
                ingredientOntologyActivationWithLiveReservation(
                    $options,
                    'record_metadata_backfill_deferred',
                    static fn(): array =>
                        ingredientOntologyActivationRecordOutcome(
                            $db,
                            'metadata_backfill_deferred',
                            [
                                'reason' =>
                                    'cookidoo_metadata_backfill_active',
                            ],
                            true,
                            60
                        )
                );
            return [
                'action' => 'policy_deferred',
                'reason' => 'cookidoo_metadata_backfill_active',
                'outcome' => $deferred,
                'work_cleanup' => $workCleanup,
                'cdc_pruned' => $cdcPruned,
            ];
        }

        if ($needsOntologyBuild) {
            $reviewedManifestRefresh =
                ingredientOntologyActivationShouldRebuildReviewedManifest(
                    $db,
                    $options
                );
            $built =
                $reviewedManifestRefresh
                    ? ingredientOntologyActivationBuildRefresh(
                        $db,
                        $options
                    )
                    : (
                ingredientOntologyActivationPendingIntentCount($db) > 0
                    ? ingredientOntologyActivationBuildGeneration(
                        $db,
                        $options
                    )
                    : ingredientOntologyActivationBuildRefresh(
                        $db,
                        $options
                    ));
            if (is_array($built['acknowledgement'] ?? null)) {
                $acknowledgement =
                    ingredientOntologyActivationWithLiveReservation(
                        $options,
                        'acknowledge_no_op',
                        static fn(): array =>
                            ingredientOntologyActivationAcknowledgeNoOp(
                                $db,
                                $built['acknowledgement']
                            )
                    );
                return [
                    'action' => 'acknowledge_no_op',
                    'acknowledgement' => $acknowledgement,
                    'work_cleanup' => $workCleanup,
                    'cdc_pruned' => $cdcPruned,
                ];
            }
            if (!empty($built['no_work'])) {
                $noWorkReason = (string)(
                    $built['reason'] ?? 'no_due_generation_work'
                );
                if (ingredientOntologyActivationNeedsScoreBuild($db)) {
                    return ingredientOntologyActivationStartScoreRefresh(
                        $db,
                        $options
                    ) + [
                        'generation_deferred' => [
                            'claimed_intents' =>
                                (int)(
                                    $built['claimed_intents'] ?? 0
                                ),
                            'reason' => $noWorkReason,
                            'superseded_source_job_ids' =>
                                array_map(
                                    'intval',
                                    (array)($built[
                                        'superseded_source_job_ids'
                                    ] ?? [])
                                ),
                        ],
                        'work_cleanup' => $workCleanup,
                        'cdc_pruned' => $cdcPruned,
                    ];
                }
                ingredientOntologyActivationWithLiveReservation(
                    $options,
                    'record_policy_deferred',
                    static fn(): array =>
                        ingredientOntologyActivationRecordAdvisoryOutcome(
                            $db,
                            'policy_deferred',
                            [
                                'claimed_intents' =>
                                    (int)(
                                        $built['claimed_intents'] ?? 0
                                    ),
                                'reason' => $noWorkReason,
                                'superseded_source_job_ids' =>
                                    array_map(
                                        'intval',
                                        (array)($built[
                                            'superseded_source_job_ids'
                                        ] ?? [])
                                    ),
                            ],
                            300
                        )
                );
                return [
                    'action' => 'policy_deferred',
                    'reason' => $noWorkReason,
                    'work_cleanup' => $workCleanup,
                    'cdc_pruned' => $cdcPruned,
                ];
            }
            $bundleSet = $built;
            if (!empty($options['yield_after_live_reservation'])) {
                $ontologyImport = null;
                $scoreImport = null;
                try {
                    $directory =
                        ingredientOntologyActivationWorkDirectory();
                    $ontologyPayloadPath =
                        ingredientOntologyActivationResolvePayload(
                            $bundleSet['ontology'],
                            $directory
                        );
                    $scorePayloadPath =
                        ingredientOntologyActivationResolvePayload(
                            $bundleSet['score'],
                            $directory
                        );
                    ingredientOntologyActivationWithLiveReservation(
                        $options,
                        'register_bundle_set',
                        function () use (
                            $db,
                            $bundleSet,
                            $directory,
                            $ontologyPayloadPath,
                            $scorePayloadPath,
                            &$ontologyImport,
                            &$scoreImport
                        ): void {
                            $ontologyImport =
                                ingredientOntologyActivationRegisterImport(
                                    $db,
                                    $bundleSet['ontology'],
                                    $directory,
                                    $ontologyPayloadPath
                                );
                            $scoreImport =
                                ingredientOntologyActivationRegisterImport(
                                    $db,
                                    $bundleSet['score'],
                                    $directory,
                                    $scorePayloadPath
                                );
                        }
                    );
                } catch (Throwable $error) {
                    throw $error;
                }
                return [
                    'action' => 'build_ontology_and_score',
                    'ontology_import' => $ontologyImport,
                    'score_import' => $scoreImport,
                    'scheduled' => true,
                    'work_cleanup' => $workCleanup,
                    'cdc_pruned' => $cdcPruned,
                ];
            }
            try {
                $directory =
                    ingredientOntologyActivationWorkDirectory();
                $verifiedPayloadPath =
                    ingredientOntologyActivationResolvePayload(
                        $bundleSet['ontology'],
                        $directory
                    );
                $ontologyImport =
                    ingredientOntologyActivationWithLiveReservation(
                        $options,
                        'register_ontology_import',
                        static fn(): array =>
                            ingredientOntologyActivationRegisterImport(
                                $db,
                                $bundleSet['ontology'],
                                $directory,
                                $verifiedPayloadPath
                            )
                    );
            } catch (Throwable $error) {
                throw $error;
            }
            $ontologyImport = ingredientOntologyActivationDriveImport(
                $db,
                (int)$ontologyImport['id'],
                $options
            );
            if ((string)$ontologyImport['status'] !== 'complete') {
                return [
                    'action' => 'build_ontology',
                    'import' => $ontologyImport,
                    'work_cleanup' => $workCleanup,
                    'cdc_pruned' => $cdcPruned,
                ];
            }
            ingredientOntologyActivationRemovePayload(
                $bundleSet['ontology']
            );
            try {
                $directory =
                    ingredientOntologyActivationWorkDirectory();
                $verifiedPayloadPath =
                    ingredientOntologyActivationResolvePayload(
                        $bundleSet['score'],
                        $directory
                    );
                $scoreImport =
                    ingredientOntologyActivationWithLiveReservation(
                        $options,
                        'register_score_import',
                        static fn(): array =>
                            ingredientOntologyActivationRegisterImport(
                                $db,
                                $bundleSet['score'],
                                $directory,
                                $verifiedPayloadPath
                            )
                    );
            } catch (Throwable $error) {
                throw $error;
            }
            $scoreImport = ingredientOntologyActivationDriveImport(
                $db,
                (int)$scoreImport['id'],
                $options
            );
            if (in_array(
                (string)$scoreImport['status'],
                ['active', 'cleaned'],
                true
            )) {
                ingredientOntologyActivationRemovePayload(
                    $bundleSet['score']
                );
            }
            return [
                'action' => 'build_ontology_and_score',
                'ontology_import' => $ontologyImport,
                'score_import' => $scoreImport,
                'work_cleanup' => $workCleanup,
                'cdc_pruned' => $cdcPruned,
            ];
        }

        if (ingredientOntologyActivationNeedsScoreBuild($db)) {
            return ingredientOntologyActivationStartScoreRefresh(
                $db,
                $options
            ) + [
                'work_cleanup' => $workCleanup,
                'cdc_pruned' => $cdcPruned,
            ];
        }

        $cleanupImport =
            ingredientOntologyActivationPendingCleanupImport($db);
        if ($cleanupImport !== null) {
            return [
                'action' => 'cleanup_import',
                'import' => ingredientOntologyActivationDriveImport(
                    $db,
                    (int)$cleanupImport['id'],
                    $options
                ),
                'work_cleanup' => $workCleanup,
                'cdc_pruned' => $cdcPruned,
            ];
        }

        $staleOntology =
            ingredientOntologyActivationStaleOntologyImport($db);
        if ($staleOntology !== null) {
            $failed =
                ingredientOntologyActivationWithLiveReservation(
                    $options,
                    'mark_stale_import',
                    static fn(): array =>
                        ingredientOntologyActivationFailImport(
                            $db,
                            (int)$staleOntology['id'],
                            'Active ontology parent changed before score build.'
                        )
                );
            if (!empty(
                $options['yield_after_live_reservation']
            )) {
                return [
                    'action' => 'supersede_stale_ontology',
                    'import' => $failed,
                    'work_cleanup' => $workCleanup,
                    'cdc_pruned' => $cdcPruned,
                ];
            }
            return [
                'action' => 'supersede_stale_ontology',
                'import' => ingredientOntologyActivationDriveImport(
                    $db,
                    (int)$staleOntology['id'],
                    $options
                ),
                'work_cleanup' => $workCleanup,
                'cdc_pruned' => $cdcPruned,
            ];
        }

        $recoveredFailure = null;
        $activationState = $db->query("
            SELECT failure_count, last_error
            FROM ontology_activation_state
            WHERE id = 1
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
        if (
            (int)($activationState['failure_count'] ?? 0) > 0
            || trim((string)($activationState['last_error'] ?? ''))
                !== ''
        ) {
            $recoveredFailure =
                ingredientOntologyActivationWithLiveReservation(
                    $options,
                    'clear_recovered_failure',
                    static fn(): array =>
                        ingredientOntologyActivationRecordOutcome(
                            $db,
                            'fresh',
                            ['reason' => 'activation_state_fresh'],
                            true
                        )
                );
        }
        return [
            'action' => 'none',
            'reason' => 'fresh',
            'recovered_failure' => $recoveredFailure,
            'work_cleanup' => $workCleanup,
            'cdc_pruned' => $cdcPruned,
        ];
    }
