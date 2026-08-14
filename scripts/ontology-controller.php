#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

function ontologyControllerCliUsage(): never {
    fwrite(
        STDERR,
        "Usage:\n"
        . "  php scripts/ontology-controller.php status --db=copy.sqlite\n"
        . "  php scripts/ontology-controller.php backfill --db=copy.sqlite [--write] [--json-out=report.json]\n"
        . "  php scripts/ontology-controller.php work --db=copy.sqlite --write [--limit=10] [--minimum-priority=50] [--provider=fake] [--model=ID] [--critic-provider=KEY] [--critic-model=ID] [--allow-network] [--copy-generation --run-generation [--promote] [--allow-active-generation]] [--loop] [--max-cycles=N]\n"
        . "  php scripts/ontology-controller.php generation --db=copy.sqlite --generation-id=N --write [--promote]\n"
        . "  php scripts/ontology-controller.php monitor --db=copy.sqlite --generation-id=N --write\n"
        . "  php scripts/ontology-controller.php gold-build --db=copy.sqlite --write\n"
        . "  php scripts/ontology-controller.php gold-export --db=copy.sqlite --release-id=N --out=release.json\n"
        . "  php scripts/ontology-controller.php gold-advance --db=copy.sqlite --release-id=N --write\n"
        . "  php scripts/ontology-controller.php benchmark-import --db=copy.sqlite --file=policy.json --write [--activate]\n"
        . "  php scripts/ontology-controller.php benchmark-list --db=copy.sqlite\n"
        . "\nAll runtime/model/promotion paths are disabled by default. "
        . "Network calls additionally require --allow-network and the "
        . "separate controller feature flags/API key. Backfill is dry-run "
        . "unless --write is supplied. Mutating commands refuse the active "
        . "database unless --allow-active-db is explicitly provided. "
        . "Active-database generation additionally requires "
        . "--allow-active-generation, --run-generation, --promote, and the "
        . "promotion feature gate.\n"
    );
    exit(1);
}

$command = strtolower(trim((string)($argv[1] ?? '')));
$valid = [
    'status', 'backfill', 'work', 'generation', 'monitor',
    'gold-build', 'gold-export', 'gold-advance',
    'benchmark-import', 'benchmark-list',
];
if (!in_array($command, $valid, true)) {
    ontologyControllerCliUsage();
}
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (!str_starts_with($argument, '--')) {
        continue;
    }
    $parts = explode('=', substr($argument, 2), 2);
    $options[$parts[0]] = $parts[1] ?? true;
}
$databasePath = trim((string)($options['db'] ?? ''));
if ($databasePath === '') {
    ontologyControllerCliUsage();
}
$write = isset($options['write']);
$mutating = in_array($command, [
    'backfill', 'work', 'generation', 'monitor',
    'gold-build', 'gold-advance',
    'benchmark-import',
], true) && ($command !== 'backfill' || $write);
if (
    in_array($command, [
        'work', 'generation', 'monitor', 'gold-build', 'gold-advance',
        'benchmark-import',
    ], true)
    && !$write
) {
    throw new InvalidArgumentException(
        $command . ' requires --write'
    );
}
$generationCopyOnly = in_array($command, [
    'generation', 'monitor', 'gold-build', 'gold-advance',
], true);
$databasePath = recipeCliAssertDatabaseInputSafe(
    $databasePath,
    isset($options['allow-active-db']) && !$generationCopyOnly
);
$db = new PDO('sqlite:' . $databasePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA busy_timeout=10000');
if ($mutating) {
    recipeSchemaMigrate($db);
    ingredientOntologyControllerSchemaMigrate($db);
} elseif ($command !== 'backfill') {
    $db->exec('PRAGMA query_only=ON');
}

$positiveInt = static function (string $name) use ($options): int {
    $value = $options[$name] ?? null;
    if (
        !is_string($value)
        || !ctype_digit($value)
        || (int)$value <= 0
    ) {
        throw new InvalidArgumentException("--{$name} is required");
    }
    return (int)$value;
};

$nonNegativeInt = static function (
    string $name,
    int $default
) use ($options): int {
    if (!array_key_exists($name, $options)) {
        return $default;
    }
    $value = $options[$name];
    if (!is_string($value) || !ctype_digit($value)) {
        throw new InvalidArgumentException(
            "--{$name} must be a non-negative integer"
        );
    }
    return min(1000000, (int)$value);
};

$writeJson = static function (
    array $value,
    ?string $path = null
) use ($databasePath): void {
    $json = json_encode(
        $value,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    if ($path === null || trim($path) === '') {
        echo $json;
        return;
    }
    $safe = recipeCliAssertOutputPathSafe($path, $databasePath);
    recipeCliWriteFileAtomically($safe, $json);
    echo recipeCatalogJsonEncode([
        'written' => true,
        'path' => $safe,
    ]) . PHP_EOL;
};

$readJson = static function (string $path): array {
    $path = trim($path);
    if ($path === '') {
        throw new InvalidArgumentException('--file is required');
    }
    if (!str_starts_with($path, '/')) {
        $path = getcwd() . '/' . $path;
    }
    $raw = file_get_contents($path);
    if ($raw === false || strlen($raw) > 1048576) {
        throw new InvalidArgumentException(
            'benchmark policy input is missing or too large'
        );
    }
    $document = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($document)) {
        throw new InvalidArgumentException(
            'benchmark policy input must be a JSON object'
        );
    }
    return $document;
};

switch ($command) {
    case 'status':
        $tables = [
            'ontology_subjects',
            'ontology_subject_occurrences',
            'ontology_observation_events',
            'ontology_constraint_ledger',
            'ontology_controller_jobs',
            'ontology_mutation_plans',
            'ontology_generations',
            'ontology_gold_releases',
        ];
        $counts = [];
        foreach ($tables as $table) {
            $exists = ingredientOntologyControllerTableExists($db, $table);
            $counts[$table] = $exists
                ? (int)$db->query(
                    "SELECT COUNT(*) FROM {$table}"
                )->fetchColumn()
                : null;
        }
        $writeJson(
            ingredientOntologyControllerRuntimeStatus(
                $db,
                isset($options['include-coverage'])
            ) + [
            'counts' => $counts,
            'model_roster' =>
                ingredientOntologyControllerModelRoster(),
            ]
        );
        break;

    case 'backfill':
        $result = ingredientOntologyControllerBackfillSubjects(
            $db,
            $write,
            max(1, min(2000, (int)($options['batch'] ?? 500)))
        );
        $writeJson(
            $result,
            is_string($options['json-out'] ?? null)
                ? $options['json-out']
                : null
        );
        break;

    case 'work':
        $limit = max(1, min(100, (int)($options['limit'] ?? 10)));
        $copyGeneration = isset($options['copy-generation']);
        $minimumPriority = $nonNegativeInt(
            'minimum-priority',
            $copyGeneration
                ? 0
                : ingredientOntologyControllerMinimumPriority()
        );
        if (
            $copyGeneration
            && (
                $databasePath === recipeCliCanonicalPath(DB_PATH)
                || recipeCliSameFile($databasePath, DB_PATH)
            )
        ) {
            if (!isset($options['allow-active-generation'])) {
                throw new RuntimeException(
                    'active generation requires --allow-active-generation'
                );
            }
            if (
                !isset($options['run-generation'])
                || !isset($options['promote'])
            ) {
                throw new RuntimeException(
                    'active generation requires --run-generation and --promote'
                );
            }
            if (!ingredientOntologyControllerPromotionEnabled()) {
                throw new RuntimeException(
                    'active generation requires the promotion feature gate'
                );
            }
        }
        $workOptions = [
            'provider' => (string)(
                $options['provider']
                    ?? ingredientOntologyControllerProvider()
            ),
            'model' => (string)(
                $options['model']
                    ?? ingredientOntologyControllerProposerModel()
            ),
            'critic_provider' => (string)(
                $options['critic-provider']
                    ?? ingredientOntologyControllerCriticProvider()
            ),
            'critic_model' => (string)(
                $options['critic-model']
                    ?? ingredientOntologyControllerCriticModel()
            ),
            'allow_network' => isset($options['allow-network']),
            'intake_only' => !$copyGeneration,
            'job_types' => !$copyGeneration
                ? [
                    'subject_resolution',
                    'correction',
                    'compensation',
                ]
                : null,
            'minimum_priority' => $minimumPriority,
            'run_generation' => $copyGeneration
                && isset($options['run-generation']),
            'promote' => $copyGeneration
                && isset($options['promote']),
        ];
        $loop = isset($options['loop']);
        $maximumCycles = max(0, (int)($options['max-cycles'] ?? 0));
        $pollMs = max(
            50,
            min(
                5000,
                (int)env(
                    'INGREDIENT_ONTOLOGY_CONTROLLER_POLL_MS',
                    '250'
                )
            )
        );
        $cycle = 0;
        do {
            $result = ingredientOntologyControllerProcessQueue(
                $db,
                $limit,
                $workOptions
            );
            $writeJson(['cycle' => $cycle + 1] + $result);
            $cycle++;
            if (
                !$loop
                || ($maximumCycles > 0 && $cycle >= $maximumCycles)
            ) {
                break;
            }
            usleep($pollMs * 1000);
        } while (true);
        break;

    case 'generation':
        $result = ingredientOntologyControllerFinalizeGeneration(
            $db,
            $positiveInt('generation-id'),
            [
                'promote' => isset($options['promote']),
                'batch_size' =>
                    max(1, min(500, (int)($options['batch'] ?? 250))),
            ]
        );
        $writeJson($result);
        break;

    case 'monitor':
        $writeJson(
            ingredientOntologyControllerMonitorGeneration(
                $db,
                $positiveInt('generation-id')
            )
        );
        break;

    case 'gold-build':
        $writeJson(
            ingredientOntologyControllerBuildGoldRelease($db)
        );
        break;

    case 'gold-export':
        $releaseId = $positiveInt('release-id');
        $out = trim((string)($options['out'] ?? ''));
        if ($out === '') {
            throw new InvalidArgumentException('--out is required');
        }
        $writeJson(
            ingredientOntologyControllerGoldReleaseDocument(
                $db,
                $releaseId
            ),
            $out
        );
        break;

    case 'gold-advance':
        $writeJson(
            ingredientOntologyControllerAdvanceGoldRelease(
                $db,
                $positiveInt('release-id')
            )
        );
        break;

    case 'benchmark-import':
        $writeJson(
            ingredientOntologyControllerImportBenchmarkPolicy(
                $db,
                $readJson((string)($options['file'] ?? '')),
                isset($options['activate'])
            )
        );
        break;

    case 'benchmark-list':
        $rows = ingredientOntologyControllerTableExists(
            $db,
            'ontology_controller_benchmark_policies'
        ) ? $db->query("
            SELECT id, policy_key, model_policy_hash, risk_tier,
                   authorized, case_count, critical_error_count,
                   one_sided_error_upper, adjudicator_authorized,
                   content_hash, active, policy_json, created_at
            FROM ontology_controller_benchmark_policies
            ORDER BY risk_tier, created_at, id
        ")->fetchAll(PDO::FETCH_ASSOC) : [];
        $writeJson(['policies' => $rows]);
        break;
}
