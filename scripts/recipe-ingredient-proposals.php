#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

function recipeIngredientProposalCliUsage(): never {
    fwrite(
        STDERR,
        "Usage:\n"
            . "  php scripts/recipe-ingredient-proposals.php status "
            . "--db=copy.sqlite\n"
            . "  php scripts/recipe-ingredient-proposals.php export "
            . "--db=copy.sqlite --out=handoff.json --write\n"
            . "  php scripts/recipe-ingredient-proposals.php import "
            . "--db=copy.sqlite --input=result.json --write\n"
            . "  php scripts/recipe-ingredient-proposals.php work "
            . "--db=copy.sqlite --limit=N --write --allow-network\n"
            . "  php scripts/recipe-ingredient-proposals.php fixtures "
            . "--db=copy.sqlite --out=fixtures.json\n"
            . "  php scripts/recipe-ingredient-proposals.php retry "
            . "--db=copy.sqlite --outbox-id=N --write\n"
            . "All model output is staging-only. No command activates ontology "
            . "changes. Runtime model calls require --allow-network and use "
            . "the separate INGREDIENT_ONTOLOGY_V3_PROPOSAL_API_KEY plus the "
            . "single configured ontology proposal model without fallback.\n"
    );
    exit(1);
}

function recipeIngredientProposalCliJsonFile(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        throw new InvalidArgumentException('input file is not readable');
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || strlen($raw) > 1048576) {
        throw new InvalidArgumentException('input file is invalid');
    }
    return recipeIngredientProposalDecodeJson($raw, 'input file');
}

$command = $argv[1] ?? '';
if (!in_array($command, [
    'status', 'export', 'import', 'work', 'fixtures', 'retry',
], true)) {
    recipeIngredientProposalCliUsage();
}
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    $parts = explode('=', substr($arg, 2), 2);
    $options[$parts[0]] = $parts[1] ?? true;
}
$databasePath = trim((string)($options['db'] ?? ''));
if ($databasePath === '') {
    recipeIngredientProposalCliUsage();
}
$databasePath = recipeCliAssertDatabaseInputSafe(
    $databasePath,
    false
);
$write = isset($options['write']);
$mutating = in_array(
    $command,
    ['export', 'import', 'work', 'retry'],
    true
);
if ($mutating && !$write) {
    throw new InvalidArgumentException(
        $command . ' requires --write'
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
}

switch ($command) {
    case 'status':
        $rows = $db->query("
            SELECT status, COUNT(*) AS count
            FROM recipe_ingredient_proposal_outbox
            GROUP BY status
            ORDER BY status
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo recipeCatalogJsonEncode([
            'schema_version' =>
                'recipe_ingredient_proposal_status_v1',
            'model' =>
                ingredientOntologyV3ConfiguredProposalModel(),
            'automatic_activation' => false,
            'states' => $rows,
        ]) . PHP_EOL;
        break;

    case 'export':
        $outputPath = trim((string)($options['out'] ?? ''));
        if ($outputPath === '') {
            throw new InvalidArgumentException('--out is required');
        }
        $outputPath = recipeCliAssertOutputPathSafe(
            $outputPath,
            $databasePath
        );
        $limit = max(1, min(
            RECIPE_INGREDIENT_PROPOSAL_CLAIM_LIMIT,
            (int)($options['limit'] ?? 20)
        ));
        $result = recipeIngredientProposalExportPackages(
            $db,
            $limit
        );
        recipeCliWriteFileAtomically(
            $outputPath,
            json_encode(
                $result,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
        echo recipeCatalogJsonEncode([
            'exported' => count($result['packages']),
            'path' => $outputPath,
            'model_calls' => false,
        ]) . PHP_EOL;
        break;

    case 'import':
        $inputPath = trim((string)($options['input'] ?? ''));
        if ($inputPath === '') {
            throw new InvalidArgumentException('--input is required');
        }
        $document = recipeIngredientProposalCliJsonFile($inputPath);
        $packages = $document['packages'] ?? [$document];
        if (
            !is_array($packages)
            || !recipeArrayIsList($packages)
            || count($packages) > 100
        ) {
            throw new InvalidArgumentException(
                'import packages are invalid'
            );
        }
        $results = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                throw new InvalidArgumentException(
                    'import package is invalid'
                );
            }
            $results[] = recipeIngredientProposalImportPackage(
                $db,
                $package
            );
        }
        echo recipeCatalogJsonEncode([
            'imported' => count($results),
            'automatic_activation' => false,
            'results' => $results,
        ]) . PHP_EOL;
        break;

    case 'work':
        if (!isset($options['allow-network'])) {
            throw new RuntimeException(
                'Runtime Gemini calls are disabled without --allow-network. '
                . 'Use export/import for an operator or Copilot artifact handoff.'
            );
        }
        $limit = max(1, min(
            RECIPE_INGREDIENT_PROPOSAL_CLAIM_LIMIT,
            (int)($options['limit'] ?? 5)
        ));
        echo recipeCatalogJsonEncode(
            recipeIngredientProposalProcessQueue($db, $limit)
            + [
                'model' =>
                    ingredientOntologyV3ConfiguredProposalModel(),
                'fallback_models' => [],
                'automatic_activation' => false,
            ]
        ) . PHP_EOL;
        break;

    case 'fixtures':
        $outputPath = trim((string)($options['out'] ?? ''));
        if ($outputPath === '') {
            throw new InvalidArgumentException('--out is required');
        }
        $outputPath = recipeCliAssertOutputPathSafe(
            $outputPath,
            $databasePath
        );
        $rows = $db->query("
            SELECT case_key, polarity, fixture_json, created_at
            FROM recipe_ingredient_feedback_regression_fixtures
            WHERE status IN ('candidate', 'accepted')
            ORDER BY id
            LIMIT 10000
        ")->fetchAll(PDO::FETCH_ASSOC);
        $fixtures = array_map(
            static fn(array $row): array => [
                'case_key' => (string)$row['case_key'],
                'polarity' => (string)$row['polarity'],
                'fixture' => recipeIngredientProposalDecodeJson(
                    (string)$row['fixture_json'],
                    'regression fixture'
                ),
                'created_at' => (string)$row['created_at'],
            ],
            $rows
        );
        recipeCliWriteFileAtomically(
            $outputPath,
            json_encode(
                [
                    'schema_version' =>
                        'recipe_ingredient_feedback_regressions_v1',
                    'candidate_only' => true,
                    'gold' => false,
                    'fixtures' => $fixtures,
                ],
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
        echo recipeCatalogJsonEncode([
            'exported' => count($fixtures),
            'path' => $outputPath,
        ]) . PHP_EOL;
        break;

    case 'retry':
        $outboxId = recipeCatalogRequirePositiveInt(
            isset($options['outbox-id'])
                ? (int)$options['outbox-id']
                : null,
            'outbox_id'
        );
        $stmt = $db->prepare("
            UPDATE recipe_ingredient_proposal_outbox
            SET status = 'retry',
                next_attempt_at = CURRENT_TIMESTAMP,
                lease_token = NULL,
                last_error_kind = NULL,
                last_error = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND status IN ('blocked', 'retry')
        ");
        $stmt->execute([$outboxId]);
        echo recipeCatalogJsonEncode([
            'outbox_id' => $outboxId,
            'queued' => $stmt->rowCount() === 1,
        ]) . PHP_EOL;
        break;
}
