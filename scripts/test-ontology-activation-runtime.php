#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/ontology_v3/core.php';
require_once __DIR__ . '/../api/lib/ontology_v3/activation.php';

$assertions = 0;
function activationRuntimeTestAssert(
    bool $condition,
    string $message
): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function activationRuntimeLegacyHash(mixed $value): string {
    return hash(
        'sha256',
        ingredientOntologyV3Json(
            ingredientOntologyV3StableValue($value)
        )
    );
}

function activationRuntimeLegacyQueryRowsHash(
    PDO $db,
    string $sql,
    array $params = []
): string {
    $hash = hash_init('sha256');
    hash_update($hash, '[');
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $separator = '';
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        hash_update(
            $hash,
            $separator
                . ingredientOntologyV3Json(
                    ingredientOntologyV3StableValue($row)
                )
        );
        $separator = ',';
    }
    $stmt->closeCursor();
    hash_update($hash, ']');
    return hash_final($hash);
}

$dbPath = __DIR__ . '/../data/.ontology-activation-runtime-test-'
    . getmypid() . '.sqlite';
$lockPath = __DIR__ . '/../data/.ontology-activation-runtime-lock-'
    . getmypid();
$cleanup = [
    $dbPath,
    $dbPath . '-journal',
    $dbPath . '-wal',
    $dbPath . '-shm',
    $lockPath,
];
$holder = null;
$contender = null;

try {
    foreach ($cleanup as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    ini_set('memory_limit', '32M');

    $fixtures = [
        null,
        true,
        42,
        1.25,
        'unicode/fixture café',
        ['z', 3, false, null],
        [
            'zeta' => ['second' => 2, 'first' => 1],
            'alpha' => [
                ['b' => 2, 'a' => 1],
                ['slash' => '/path', 'unicode' => 'jalapeño'],
            ],
        ],
        [2 => 'two', 0 => 'zero'],
        [1 => 'second', 0 => 'first'],
        [2 => 'third', 1 => 'second', 0 => 'first'],
        [
            'nested' => [
                2 => ['z' => 3, 'a' => 1],
                1 => ['second' => 2, 'first' => 1],
                0 => 'first',
            ],
        ],
        (object)[
            'preserved_order' => ['z' => 1, 'a' => 2],
            'value' => 7,
        ],
    ];
    foreach ($fixtures as $fixture) {
        activationRuntimeTestAssert(
            hash_equals(
                activationRuntimeLegacyHash($fixture),
                ingredientOntologyV3Hash($fixture)
            ),
            'Streaming canonical hashes must match legacy stable JSON'
        );
    }
    $permanentLineage =
        ingredientOntologyActivationClassifyValidationErrors([
            'incremental input lineage changed',
        ]);
    $liveDrift =
        ingredientOntologyActivationClassifyValidationErrors([
            'inventory or catalog inputs changed after shadow build',
            'shadow materialization is incomplete',
        ]);
    $scoreDateDrift =
        ingredientOntologyActivationClassifyValidationErrors([
            'shadow score date is not current',
        ]);
    $activationDateDrift =
        ingredientOntologyActivationClassifyValidationErrors([
            'activation score date changed',
        ]);
    activationRuntimeTestAssert(
        empty($permanentLineage['expected'])
        && !empty($liveDrift['expected'])
        && $liveDrift['drift_codes'] === [
            'live_inputs_changed',
        ]
        && !empty($scoreDateDrift['expected'])
        && $scoreDateDrift['drift_codes'] === [
            'score_date_rolled_over',
        ]
        && $scoreDateDrift['outcome_kind'] === 'rebase_required'
        && !empty($activationDateDrift['expected'])
        && $activationDateDrift['outcome_kind'] === 'rebase_required',
        'Only primary live drift plus known derivative errors may rebase'
    );
    $standaloneRollover = null;
    try {
        ingredientOntologyActivationAssertScoreValidation(
            [
                'valid' => false,
                'errors' => ['shadow score date is not current'],
            ],
            'score bundle validation failed',
            'superseded_snapshot',
            ['validation_path' => 'standalone_score_bundle']
        );
    } catch (IngredientOntologyActivationExpectedOutcome $error) {
        $standaloneRollover = $error;
    }
    $generationRollover = null;
    try {
        ingredientOntologyActivationAssertScoreValidation(
            [
                'valid' => false,
                'errors' => ['shadow score date is not current'],
            ],
            'ontology activation score attestation failed',
            'superseded_snapshot',
            ['validation_path' => 'generation_bundle']
        );
    } catch (IngredientOntologyActivationExpectedOutcome $error) {
        $generationRollover = $error;
    }
    $lineageFailureVisible = false;
    try {
        ingredientOntologyActivationAssertScoreValidation(
            [
                'valid' => false,
                'errors' => ['incremental input lineage changed'],
            ],
            'score bundle validation failed',
            'rebase_required'
        );
    } catch (RuntimeException $error) {
        $lineageFailureVisible = !($error instanceof
            IngredientOntologyActivationExpectedOutcome)
            && str_contains(
                $error->getMessage(),
                'incremental input lineage changed'
            );
    }
    activationRuntimeTestAssert(
        $standaloneRollover instanceof
            IngredientOntologyActivationExpectedOutcome
        && $standaloneRollover->outcomeKind() === 'rebase_required'
        && $generationRollover instanceof
            IngredientOntologyActivationExpectedOutcome
        && $generationRollover->outcomeKind() === 'rebase_required'
        && $lineageFailureVisible,
        'Standalone and generation score validation must share typed rollover handling while lineage mismatches fail closed'
    );

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    ingredientOntologyActivationConfigureDatabase(
        $db,
        INGREDIENT_ONTOLOGY_ACTIVATION_LIVE_BUSY_TIMEOUT_MS
    );
    activationRuntimeTestAssert(
        (int)$db->query('PRAGMA temp_store')->fetchColumn() === 1
        && (int)$db->query('PRAGMA busy_timeout')->fetchColumn()
            === INGREDIENT_ONTOLOGY_ACTIVATION_LIVE_BUSY_TIMEOUT_MS,
        'Activation connections must use file-backed temp storage and '
            . 'the bounded live contention budget'
    );
    $db->exec("
        CREATE TABLE canonical_rows (
            id INTEGER PRIMARY KEY,
            payload TEXT NOT NULL,
            metric REAL NOT NULL,
            optional_value TEXT
        )
    ");
    $insert = $db->prepare("
        INSERT INTO canonical_rows (
            id, payload, metric, optional_value
        ) VALUES (?, ?, ?, ?)
    ");
    $payload = str_repeat('bounded/hash/é', 96);
    $db->beginTransaction();
    for ($id = 1; $id <= 12000; $id++) {
        $insert->execute([
            $id,
            $payload,
            $id / 10,
            $id % 3 === 0 ? null : 'row-' . $id,
        ]);
    }
    $db->commit();
    $insert = null;

    $sql = "
        SELECT payload, id, metric, optional_value
        FROM canonical_rows
        ORDER BY id
    ";
    $expected = activationRuntimeLegacyQueryRowsHash($db, $sql);
    $actual = ingredientOntologyV3CanonicalQueryRowsHash($db, $sql);
    activationRuntimeTestAssert(
        hash_equals($expected, $actual),
        'Streaming query hashes must preserve exact row materialization'
    );

    $smallRows = $db->query($sql . ' LIMIT 25')
        ->fetchAll(PDO::FETCH_ASSOC);
    $expectedMap = activationRuntimeLegacyHash([
        'subject_resolutions' => $smallRows,
        'pair_constraints' => array_reverse($smallRows),
    ]);
    $actualMap = ingredientOntologyV3CanonicalQueryMapHash($db, [
        'subject_resolutions' => [
            'sql' => $sql . ' LIMIT 25',
        ],
        'pair_constraints' => [
            'sql' => "
                SELECT payload, id, metric, optional_value
                FROM canonical_rows
                WHERE id <= 25
                ORDER BY id DESC
            ",
        ],
    ]);
    activationRuntimeTestAssert(
        hash_equals($expectedMap, $actualMap),
        'Streaming query-map hashes must preserve canonical key order'
    );
    unset($smallRows);

    $holder = fopen($lockPath, 'c+');
    $contender = fopen($lockPath, 'c+');
    activationRuntimeTestAssert(
        is_resource($holder)
        && is_resource($contender)
        && flock($holder, LOCK_EX | LOCK_NB),
        'Activation lock fixture must be available'
    );
    $operationRan = false;
    $locked = false;
    try {
        ingredientOntologyActivationWithNonBlockingFileLock(
            $contender,
            'publication',
            static function () use (&$operationRan): void {
                $operationRan = true;
            }
        );
    } catch (
        IngredientOntologyActivationReservationUnavailable $error
    ) {
        $locked = $error->phase() === 'publication'
            && $error->getMessage()
                === 'ontology_activation_background_writer_locked';
    }
    activationRuntimeTestAssert(
        $locked && !$operationRan,
        'Busy background-writer locks must return an explicit retryable phase'
    );
    flock($holder, LOCK_UN);
    $phases = [];
    $result = ingredientOntologyActivationWithLiveReservation(
        [
            'live_reservation' => static function (
                string $phase,
                callable $operation
            ) use ($contender, &$phases): mixed {
                $phases[] = $phase;
                return ingredientOntologyActivationWithNonBlockingFileLock(
                    $contender,
                    $phase,
                    $operation
                );
            },
        ],
        'import',
        static fn(): string => 'reserved'
    );
    activationRuntimeTestAssert(
        $result === 'reserved' && $phases === ['import'],
        'Available live reservations must run exactly one named phase'
    );
    $cdcPhases = [];
    $pruned = ingredientOntologyActivationPruneCdcBestEffort(
        $db,
        [
            'live_reservation' => static function (
                string $phase,
                callable $operation
            ) use (&$cdcPhases): mixed {
                $cdcPhases[] = $phase;
                throw new
                    IngredientOntologyActivationReservationUnavailable(
                        $phase
                    );
            },
        ]
    );
    activationRuntimeTestAssert(
        $pruned === 0 && $cdcPhases === ['cdc_prune'],
        'Optional CDC pruning must not block the activation cycle'
    );

    echo 'Ontology activation runtime tests passed: '
        . $assertions
        . ' assertions; rows=12000; peak_php_mb='
        . number_format(memory_get_peak_usage(true) / 1048576, 2)
        . PHP_EOL;
} finally {
    $db = null;
    if (is_resource($holder)) {
        flock($holder, LOCK_UN);
        fclose($holder);
    }
    if (is_resource($contender)) {
        flock($contender, LOCK_UN);
        fclose($contender);
    }
    foreach ($cleanup as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
