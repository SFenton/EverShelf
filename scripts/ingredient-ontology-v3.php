#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
if (
    getenv('EVERSHELF_ONTOLOGY_TEST_MODE') === '1'
    && !defined('RECIPE_BACKEND_TEST_MODE')
) {
    define('RECIPE_BACKEND_TEST_MODE', true);
}
require_once __DIR__ . '/../api/bootstrap.php';

function ontologyV3CliOptions(array $argv): array {
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }
        $pair = explode('=', substr($argument, 2), 2);
        $options[$pair[0]] = $pair[1] ?? true;
    }
    return $options;
}

function ontologyV3CliUsage(): string {
    return <<<'TEXT'
EverShelf ingredient ontology v3 (shadow-only by default)

Usage:
  php scripts/ingredient-ontology-v3.php build-candidate --db=copy.sqlite --write --corpus-profile=eval|provider|production [--version=v3-name]
  php scripts/ingredient-ontology-v3.php audit --db=copy.sqlite --version-id=N [--json-out=report.json]
  php scripts/ingredient-ontology-v3.php disposition-audit --db=copy.sqlite --version-id=N [--json-out=report.json]
  php scripts/ingredient-ontology-v3.php cross-copy-audit --db=left.sqlite --version-id=N --other-db=right.sqlite --other-version-id=M [--json-out=report.json]
  php scripts/ingredient-ontology-v3.php export-dispositions --db=copy.sqlite --version-id=N --csv-out=workbook.csv
  php scripts/ingredient-ontology-v3.php import-dispositions --db=copy.sqlite --version-id=N --workbook=reviewed.csv --reviewer=NAME --batch=NAME --write
  php scripts/ingredient-ontology-v3.php provider-audit --db=copy.sqlite --version-id=N [--json-out=report.json]
  php scripts/ingredient-ontology-v3.php export-provider-workbook --db=copy.sqlite --version-id=N --csv-out=provider.csv
  php scripts/ingredient-ontology-v3.php import-provider-workbook --db=copy.sqlite --version-id=N --workbook=reviewed.csv --reviewer=NAME --batch=NAME --write
  php scripts/ingredient-ontology-v3.php curated-audit --db=copy.sqlite --version-id=N [--json-out=report.json] [--products-csv=products.csv]
  php scripts/ingredient-ontology-v3.php build-requirements --db=copy.sqlite --version-id=N --write [--batch=250]
  php scripts/ingredient-ontology-v3.php requirement-audit --db=copy.sqlite --requirement-revision-id=N [--json-out=report.json]
  php scripts/ingredient-ontology-v3.php prune-requirements --db=copy.sqlite --version-id=N --write [--ready-retention=2]
  php scripts/ingredient-ontology-v3.php build-shadow --db=copy.sqlite --version-id=N --write [--batch=250]
  php scripts/ingredient-ontology-v3.php report --db=copy.sqlite --revision-id=N [--json-out=report.json]
  php scripts/ingredient-ontology-v3.php build-requirement-shadow --db=copy.sqlite --requirement-revision-id=N --write [--batch=250]
  php scripts/ingredient-ontology-v3.php requirement-report --db=copy.sqlite --revision-id=N [--json-out=report.json]
  php scripts/ingredient-ontology-v3.php validate --db=copy.sqlite --revision-id=N [--json]
  php scripts/ingredient-ontology-v3.php prompt --db=copy.sqlite --version-id=N --inputs=inputs.json [--prompt-out=prompt.txt] [--manifest-out=manifest.json]
  php scripts/ingredient-ontology-v3.php stage-proposals --db=copy.sqlite --version-id=N --payload=proposals.json --manifest=manifest.json --write
  php scripts/ingredient-ontology-v3.php reject --db=copy.sqlite --change-set-id=N --actor=NAME --reason=TEXT --write
  php scripts/ingredient-ontology-v3.php dispose --db=copy.sqlite --change-set-id=N --actor=NAME --reason=TEXT --write
  php scripts/ingredient-ontology-v3.php revert --db=copy.sqlite --change-set-id=N --actor=NAME --reason=TEXT --write
  php scripts/ingredient-ontology-v3.php activate --db=copy.sqlite --revision-id=N --write --confirm-activate=N
  php scripts/ingredient-ontology-v3.php rollback --db=copy.sqlite --write --confirm-rollback=YES [--target-revision-id=N]

Mutating commands require --write. The repository's active data/evershelf.db is
refused unless --allow-active-db is also supplied. No command calls a model API.
Mutating commands migrate only the selected --db connection, applying the current
recipe schema before ontology v3. The default/help command never activates
anything. Revert withdraws only unapplied pending/approved sets; applied sets fail
closed. Rollback directly restores only one of the eight retained, cycle-safe
ancestors (even if stale). A non-ancestor must be a v3 child of the current active
revision and pass normal activation.
Provider refs are used only when persisted on source rows. Older adapter rows
without a persisted ref are recorded as `unknown_legacy_adapter`; the CLI never
fabricates a provider identity. Requirement quantities remain display/audit only.
Workbook imports are fingerprint-checked staging records for the next immutable
copy rebuild. They never rewrite a ready disposition or activate a revision.
TEXT;
}

function ontologyV3CliRequireInt(
    array $options,
    string $name,
    int $minimum = 1
): int {
    $value = $options[$name] ?? null;
    if (
        !is_string($value)
        || !preg_match('/^\d+$/', $value)
        || (int)$value < $minimum
    ) {
        throw new InvalidArgumentException("--{$name} is required");
    }
    return (int)$value;
}

function ontologyV3CliRequireWrite(
    array $options,
    string $command
): void {
    if (($options['write'] ?? false) !== true) {
        throw new RuntimeException(
            "{$command} is mutating and requires the explicit --write flag"
        );
    }
}

function ontologyV3CliDatabase(
    array $options,
    bool $mutating,
    bool $copyOnly = false
): PDO {
    $path = $options['db'] ?? null;
    if (!is_string($path) || trim($path) === '') {
        throw new InvalidArgumentException('--db is required');
    }
    $path = trim($path);
    if (!str_starts_with($path, '/')) {
        $path = getcwd() . '/' . $path;
    }
    $directory = realpath(dirname($path));
    if ($directory === false) {
        throw new InvalidArgumentException('database directory does not exist');
    }
    $path = $directory . '/' . basename($path);
    if (!is_file($path)) {
        throw new InvalidArgumentException('database copy does not exist');
    }
    $activePath = realpath(DB_PATH);
    $activeDirectory = realpath(dirname(DB_PATH));
    $configuredActivePath = $activeDirectory !== false
        ? $activeDirectory . '/' . basename(DB_PATH)
        : DB_PATH;
    $selectedPath = realpath($path);
    if (
        $mutating
        && (
            ($activePath !== false && $selectedPath === $activePath)
            || $selectedPath === $configuredActivePath
        )
        && (
            $copyOnly
            || ($options['allow-active-db'] ?? false) !== true
        )
    ) {
        throw new RuntimeException(
            'refusing to mutate the active repository database without '
            . '--allow-active-db'
        );
    }
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 10000');
    if ($mutating) {
        recipeSchemaMigrate($db);
        ingredientOntologyV3SchemaMigrate($db);
    }
    return $db;
}

function ontologyV3CliRequireString(
    array $options,
    string $name,
    int $maximum = 200
): string {
    $value = trim((string)($options[$name] ?? ''));
    if ($value === '' || mb_strlen($value, 'UTF-8') > $maximum) {
        throw new InvalidArgumentException("--{$name} is required");
    }
    return $value;
}

function ontologyV3CliReadJson(string $path): array {
    if (!str_starts_with($path, '/')) {
        $path = getcwd() . '/' . $path;
    }
    $raw = file_get_contents($path);
    if ($raw === false || strlen($raw) > 1048576) {
        throw new InvalidArgumentException('JSON input is missing or too large');
    }
    $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new InvalidArgumentException('JSON input must be an object or array');
    }
    return $value;
}

function ontologyV3CliWrite(string $path, string $contents): void {
    if (!str_starts_with($path, '/')) {
        $path = getcwd() . '/' . $path;
    }
    $directory = dirname($path);
    if (!is_dir($directory)) {
        throw new InvalidArgumentException('output directory does not exist');
    }
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('could not write output file');
    }
}

$command = strtolower(trim((string)($argv[1] ?? 'help')));
$options = ontologyV3CliOptions($argv);
$mutatingCommands = [
    'build-candidate',
    'build-requirements',
    'prune-requirements',
    'build-shadow',
    'build-requirement-shadow',
    'stage-proposals',
    'reject',
    'dispose',
    'revert',
    'activate',
    'rollback',
    'import-dispositions',
    'import-provider-workbook',
];

try {
    if (in_array($command, ['help', '--help', '-h', ''], true)) {
        echo ontologyV3CliUsage() . PHP_EOL;
        exit(0);
    }
    $mutating = in_array($command, $mutatingCommands, true);
    if ($mutating) {
        ontologyV3CliRequireWrite($options, $command);
    }
    $copyOnly = in_array($command, [
        'build-candidate',
        'build-requirements',
        'prune-requirements',
        'build-shadow',
        'build-requirement-shadow',
        'stage-proposals',
        'reject',
        'dispose',
        'revert',
        'import-dispositions',
        'import-provider-workbook',
    ], true);
    $db = ontologyV3CliDatabase($options, $mutating, $copyOnly);
    $jsonOutput = ($options['json'] ?? false) === true;

    switch ($command) {
        case 'build-candidate':
            $result = ingredientOntologyV3BuildCandidate($db, [
                'version' => is_string($options['version'] ?? null)
                    ? $options['version']
                    : '',
                'model' => is_string($options['model'] ?? null)
                    ? $options['model']
                    : ingredientOntologyV3ConfiguredProposalModel(),
                'corpus_profile' => is_string(
                    $options['corpus-profile'] ?? null
                ) ? $options['corpus-profile'] : '',
                'progress' => static function (int $total): void {
                    fwrite(
                        STDERR,
                        "\rMapped " . number_format($total) . ' owners'
                    );
                },
            ]);
            fwrite(STDERR, PHP_EOL);
            echo ingredientOntologyV3Json($result) . PHP_EOL;
            break;

        case 'audit':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            if (is_string($options['json-out'] ?? null)) {
                $stream = fopen($options['json-out'], 'wb');
                if ($stream === false) {
                    throw new RuntimeException('could not open audit output');
                }
                try {
                    $summary = ingredientOntologyV3WriteAuditJson(
                        $db,
                        $versionId,
                        $stream
                    );
                } finally {
                    fclose($stream);
                }
                echo ingredientOntologyV3HumanAuditSummary($summary);
                echo 'JSON: ' . $options['json-out'] . PHP_EOL;
            } elseif ($jsonOutput) {
                ingredientOntologyV3WriteAuditJson(
                    $db,
                    $versionId,
                    STDOUT
                );
            } else {
                echo ingredientOntologyV3HumanAuditSummary(
                    ingredientOntologyV3AuditSummary($db, $versionId)
                );
            }
            break;

        case 'disposition-audit':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            $result = ingredientOntologyV3DispositionAudit(
                $db,
                $versionId
            );
            $encoded = json_encode(
                $result,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            if (is_string($options['json-out'] ?? null)) {
                ontologyV3CliWrite($options['json-out'], $encoded);
                echo 'JSON: ' . $options['json-out'] . PHP_EOL;
            } else {
                echo $encoded;
            }
            break;

        case 'cross-copy-audit':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            $otherVersionId = ontologyV3CliRequireInt(
                $options,
                'other-version-id'
            );
            $otherPath = ontologyV3CliRequireString(
                $options,
                'other-db',
                1000
            );
            $otherDb = ontologyV3CliDatabase(
                ['db' => $otherPath],
                false
            );
            $result = ingredientOntologyV3CrossCopyHashAudit(
                $db,
                $versionId,
                $otherDb,
                $otherVersionId
            );
            $encoded = json_encode(
                $result,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            if (is_string($options['json-out'] ?? null)) {
                ontologyV3CliWrite($options['json-out'], $encoded);
                echo 'JSON: ' . $options['json-out'] . PHP_EOL;
            } else {
                echo $encoded;
            }
            break;

        case 'export-dispositions':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            $path = ontologyV3CliRequireString(
                $options,
                'csv-out',
                1000
            );
            if (!str_starts_with($path, '/')) {
                $path = getcwd() . '/' . $path;
            }
            if (!is_dir(dirname($path))) {
                throw new InvalidArgumentException(
                    'output directory does not exist'
                );
            }
            $stream = fopen($path, 'wb');
            if ($stream === false) {
                throw new RuntimeException(
                    'could not open disposition workbook'
                );
            }
            try {
                ingredientOntologyV3WriteDispositionCsv(
                    $db,
                    $versionId,
                    $stream
                );
            } finally {
                fclose($stream);
            }
            echo 'CSV: ' . $path . PHP_EOL;
            break;

        case 'import-dispositions':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            $workbook = ontologyV3CliRequireString(
                $options,
                'workbook',
                1000
            );
            if (!str_starts_with($workbook, '/')) {
                $workbook = getcwd() . '/' . $workbook;
            }
            echo ingredientOntologyV3Json(
                ingredientOntologyV3ImportReviewWorkbook(
                    $db,
                    $versionId,
                    $workbook,
                    'disposition',
                    ontologyV3CliRequireString($options, 'reviewer', 120),
                    ontologyV3CliRequireString($options, 'batch', 120)
                )
            ) . PHP_EOL;
            break;

        case 'provider-audit':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            $result = ingredientOntologyV3ProviderAudit($db, $versionId);
            $encoded = json_encode(
                $result,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            if (is_string($options['json-out'] ?? null)) {
                ontologyV3CliWrite($options['json-out'], $encoded);
                echo 'Provider terms: '
                    . ($result['counts']['terms'] ?? 0)
                    . '; observations: '
                    . ($result['counts']['observations'] ?? 0)
                    . PHP_EOL;
                echo 'JSON: ' . $options['json-out'] . PHP_EOL;
            } else {
                echo $encoded;
            }
            break;

        case 'export-provider-workbook':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            $path = ontologyV3CliRequireString(
                $options,
                'csv-out',
                1000
            );
            if (!str_starts_with($path, '/')) {
                $path = getcwd() . '/' . $path;
            }
            if (!is_dir(dirname($path))) {
                throw new InvalidArgumentException(
                    'output directory does not exist'
                );
            }
            $stream = fopen($path, 'wb');
            if ($stream === false) {
                throw new RuntimeException(
                    'could not open provider workbook'
                );
            }
            try {
                ingredientOntologyV3WriteProviderWorkbookCsv(
                    $db,
                    $versionId,
                    $stream
                );
            } finally {
                fclose($stream);
            }
            echo 'CSV: ' . $path . PHP_EOL;
            break;

        case 'import-provider-workbook':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            $workbook = ontologyV3CliRequireString(
                $options,
                'workbook',
                1000
            );
            if (!str_starts_with($workbook, '/')) {
                $workbook = getcwd() . '/' . $workbook;
            }
            echo ingredientOntologyV3Json(
                ingredientOntologyV3ImportReviewWorkbook(
                    $db,
                    $versionId,
                    $workbook,
                    'provider_workbook',
                    ontologyV3CliRequireString($options, 'reviewer', 120),
                    ontologyV3CliRequireString($options, 'batch', 120)
                )
            ) . PHP_EOL;
            break;

        case 'curated-audit':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            $result = ingredientOntologyV3CuratedAudit(
                $db,
                $versionId
            );
            $encoded = json_encode(
                $result,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            if (is_string($options['json-out'] ?? null)) {
                ontologyV3CliWrite($options['json-out'], $encoded);
            } else {
                echo $encoded;
            }
            if (is_string($options['products-csv'] ?? null)) {
                $csv = fopen($options['products-csv'], 'wb');
                if ($csv === false) {
                    throw new RuntimeException(
                        'could not open curated product CSV output'
                    );
                }
                try {
                    ingredientOntologyV3WriteCuratedProductCsv(
                        $db,
                        $versionId,
                        $csv
                    );
                } finally {
                    fclose($csv);
                }
            }
            if (is_string($options['json-out'] ?? null)) {
                echo 'Curated products: ' . $result['product_count']
                    . '; accepted: '
                    . ($result['product_statuses']['accepted'] ?? 0)
                    . '; top-300 accepted: '
                    . $result['top_300_accepted_labels']
                    . PHP_EOL;
            }
            break;

        case 'build-requirements':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            $batchSize = is_string($options['batch'] ?? null)
                ? max(1, min(500, (int)$options['batch']))
                : 250;
            $result = ingredientOntologyV3BuildRequirementProjection(
                $db,
                $versionId,
                $batchSize,
                static function (int $written): void {
                    fwrite(
                        STDERR,
                        "\rProjected " . number_format($written)
                        . ' recipes'
                    );
                }
            );
            fwrite(STDERR, PHP_EOL);
            echo ingredientOntologyV3Json($result) . PHP_EOL;
            break;

        case 'requirement-audit':
            $requirementRevisionId = ontologyV3CliRequireInt(
                $options,
                'requirement-revision-id'
            );
            $result = ingredientOntologyV3RequirementAudit(
                $db,
                $requirementRevisionId
            );
            $encoded = json_encode(
                $result,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            if (is_string($options['json-out'] ?? null)) {
                ontologyV3CliWrite($options['json-out'], $encoded);
                echo 'Requirements: '
                    . $result['revision']['requirement_count']
                    . '; members: '
                    . $result['revision']['member_count']
                    . PHP_EOL;
                echo 'JSON: ' . $options['json-out'] . PHP_EOL;
            } else {
                echo $encoded;
            }
            break;

        case 'prune-requirements':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            $readyRetention = is_string(
                $options['ready-retention'] ?? null
            )
                ? max(1, min(10, (int)$options['ready-retention']))
                : INGREDIENT_ONTOLOGY_V3_REQUIREMENT_READY_RETENTION;
            echo ingredientOntologyV3Json(
                ingredientOntologyV3PruneRequirementRevisions(
                    $db,
                    $versionId,
                    $readyRetention
                )
            ) . PHP_EOL;
            break;

        case 'build-shadow':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            $batchSize = is_string($options['batch'] ?? null)
                ? max(1, min(500, (int)$options['batch']))
                : 250;
            $result = ingredientOntologyV3BuildShadow(
                $db,
                $versionId,
                $batchSize,
                static function (int $written, int $total): void {
                    fwrite(
                        STDERR,
                        "\rScored " . number_format($written)
                        . '/' . number_format($total) . ' recipes'
                    );
                }
            );
            fwrite(STDERR, PHP_EOL);
            echo ingredientOntologyV3Json($result) . PHP_EOL;
            break;

        case 'build-requirement-shadow':
            $requirementRevisionId = ontologyV3CliRequireInt(
                $options,
                'requirement-revision-id'
            );
            $batchSize = is_string($options['batch'] ?? null)
                ? max(1, min(500, (int)$options['batch']))
                : 250;
            $result = ingredientOntologyV3BuildRequirementShadow(
                $db,
                $requirementRevisionId,
                $batchSize,
                static function (int $written, int $total): void {
                    fwrite(
                        STDERR,
                        "\rScored " . number_format($written)
                        . '/' . number_format($total)
                        . ' requirement recipes'
                    );
                }
            );
            fwrite(STDERR, PHP_EOL);
            echo ingredientOntologyV3Json($result) . PHP_EOL;
            break;

        case 'report':
            $revisionId = ontologyV3CliRequireInt($options, 'revision-id');
            if (is_string($options['json-out'] ?? null)) {
                $stream = fopen($options['json-out'], 'wb');
                if ($stream === false) {
                    throw new RuntimeException('could not open report output');
                }
                try {
                    $summary = ingredientOntologyV3WriteShadowReportJson(
                        $db,
                        $revisionId,
                        $stream
                    );
                } finally {
                    fclose($stream);
                }
                echo ingredientOntologyV3HumanShadowSummary($summary);
                echo 'JSON: ' . $options['json-out'] . PHP_EOL;
            } elseif ($jsonOutput) {
                ingredientOntologyV3WriteShadowReportJson(
                    $db,
                    $revisionId,
                    STDOUT
                );
            } else {
                echo ingredientOntologyV3HumanShadowSummary(
                    ingredientOntologyV3ShadowSummary($db, $revisionId)
                );
            }
            break;

        case 'requirement-report':
            $revisionId = ontologyV3CliRequireInt($options, 'revision-id');
            if (is_string($options['json-out'] ?? null)) {
                $stream = fopen($options['json-out'], 'wb');
                if ($stream === false) {
                    throw new RuntimeException(
                        'could not open requirement report output'
                    );
                }
                try {
                    $result =
                        ingredientOntologyV3WriteRequirementShadowReportJson(
                            $db,
                            $revisionId,
                            $stream
                        );
                } finally {
                    fclose($stream);
                }
                echo 'Requirement matches: '
                    . $result['requirement_match_count']
                    . '; legacy parity: '
                    . (
                        $result['legacy_parity']['valid']
                            ? 'PASS'
                            : 'NOT PROVEN'
                    )
                    . PHP_EOL;
                echo 'JSON: ' . $options['json-out'] . PHP_EOL;
            } else {
                echo json_encode(
                    ingredientOntologyV3RequirementShadowReport(
                        $db,
                        $revisionId
                    ),
                    JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                        | JSON_THROW_ON_ERROR
                ) . PHP_EOL;
            }
            break;

        case 'validate':
            $revisionId = ontologyV3CliRequireInt($options, 'revision-id');
            $result = ingredientOntologyV3ValidateActivation($db, $revisionId);
            echo $jsonOutput
                ? ingredientOntologyV3Json($result) . PHP_EOL
                : (
                    'Activation validation: '
                    . ($result['valid'] ? 'PASS' : 'BLOCKED')
                    . PHP_EOL
                    . ($result['errors']
                        ? '- ' . implode(PHP_EOL . '- ', $result['errors'])
                            . PHP_EOL
                        : '')
                );
            exit($result['valid'] ? 0 : 2);

        case 'prompt':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            $inputPath = $options['inputs'] ?? null;
            if (!is_string($inputPath)) {
                throw new InvalidArgumentException('--inputs is required');
            }
            $inputDocument = ontologyV3CliReadJson($inputPath);
            $inputs = $inputDocument['inputs'] ?? $inputDocument;
            if (!is_array($inputs)) {
                throw new InvalidArgumentException('inputs JSON is invalid');
            }
            $result = ingredientOntologyV3BuildProposalPrompt(
                $db,
                $versionId,
                $inputs
            );
            if (is_string($options['prompt-out'] ?? null)) {
                ontologyV3CliWrite(
                    $options['prompt-out'],
                    $result['prompt'] . PHP_EOL
                );
            } else {
                echo $result['prompt'] . PHP_EOL;
            }
            if (is_string($options['manifest-out'] ?? null)) {
                ontologyV3CliWrite(
                    $options['manifest-out'],
                    json_encode(
                        $result['manifest'],
                        JSON_PRETTY_PRINT
                            | JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                            | JSON_THROW_ON_ERROR
                    ) . PHP_EOL
                );
            }
            break;

        case 'stage-proposals':
            $versionId = ontologyV3CliRequireInt($options, 'version-id');
            if (
                !is_string($options['payload'] ?? null)
                || !is_string($options['manifest'] ?? null)
            ) {
                throw new InvalidArgumentException(
                    '--payload and --manifest are required'
                );
            }
            $result = ingredientOntologyV3StageProposals(
                $db,
                $versionId,
                ontologyV3CliReadJson($options['payload']),
                ontologyV3CliReadJson($options['manifest']),
                [
                    'change_set_key' =>
                        is_string($options['change-set-key'] ?? null)
                            ? $options['change-set-key']
                            : '',
                ]
            );
            echo ingredientOntologyV3Json($result) . PHP_EOL;
            exit($result['valid'] ? 0 : 2);

        case 'reject':
        case 'dispose':
        case 'revert':
            $changeSetId = ontologyV3CliRequireInt(
                $options,
                'change-set-id'
            );
            if (
                !is_string($options['actor'] ?? null)
                || !is_string($options['reason'] ?? null)
            ) {
                throw new InvalidArgumentException(
                    '--actor and --reason are required'
                );
            }
            echo ingredientOntologyV3Json(
                ingredientOntologyV3ChangeSetLifecycle(
                    $db,
                    $changeSetId,
                    $command,
                    $options['actor'],
                    $options['reason']
                )
            ) . PHP_EOL;
            break;

        case 'activate':
            $revisionId = ontologyV3CliRequireInt($options, 'revision-id');
            if (
                !is_string($options['confirm-activate'] ?? null)
                || (int)$options['confirm-activate'] !== $revisionId
            ) {
                throw new RuntimeException(
                    '--confirm-activate must equal --revision-id'
                );
            }
            echo ingredientOntologyV3Json(
                ingredientOntologyV3Activate($db, $revisionId)
            ) . PHP_EOL;
            break;

        case 'rollback':
            if (($options['confirm-rollback'] ?? null) !== 'YES') {
                throw new RuntimeException(
                    'rollback requires --confirm-rollback=YES'
                );
            }
            $target = is_string($options['target-revision-id'] ?? null)
                ? ontologyV3CliRequireInt($options, 'target-revision-id')
                : null;
            echo ingredientOntologyV3Json(
                ingredientOntologyV3Rollback($db, $target)
            ) . PHP_EOL;
            break;

        default:
            throw new InvalidArgumentException(
                "unknown command {$command}\n\n" . ontologyV3CliUsage()
            );
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
