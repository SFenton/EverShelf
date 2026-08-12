#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

function recipeQuantityCliOptions(array $argv): array {
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (!str_starts_with($argument, '--')) {
            throw new InvalidArgumentException(
                'unexpected argument: ' . $argument
            );
        }
        $parts = explode('=', substr($argument, 2), 2);
        $options[$parts[0]] = $parts[1] ?? true;
    }
    return $options;
}

function recipeQuantityCliUsage(): string {
    return <<<'TEXT'
EverShelf recipe quantity parser and proposal staging

Usage:
  php scripts/recipe-quantity-parser.php parse --text=TEXT [--locale=en-US] [--source=manual]
  php scripts/recipe-quantity-parser.php prompt --input=input.json [--prompt-out=prompt.txt] [--manifest-out=manifest.json] [--model=NAME]
  php scripts/recipe-quantity-parser.php validate --payload=response.json --manifest=manifest.json
  php scripts/recipe-quantity-parser.php stage --db=copy.sqlite --payload=response.json --manifest=manifest.json --write
  php scripts/recipe-quantity-parser.php list --db=copy.sqlite [--status=pending]
  php scripts/recipe-quantity-parser.php review --db=copy.sqlite --proposal-id=N --decision=approved|rejected --actor=NAME --reason=TEXT --write

The prompt command accepts {"source":"manual","locale":"en-US","text":"..."}.
No command calls a model API. Stage/review only write proposal records and never
activate a parse, alter recipe ranking fields, or modify Cookidoo source amounts.
Mutating commands require --write and refuse the active database unless
--allow-active-db is also supplied.
TEXT;
}

function recipeQuantityCliPath(string $path): string {
    if (!str_starts_with($path, '/')) {
        $path = getcwd() . '/' . $path;
    }
    $directory = realpath(dirname($path));
    if ($directory === false) {
        throw new InvalidArgumentException('path directory does not exist');
    }
    return $directory . '/' . basename($path);
}

function recipeQuantityCliReadJson(mixed $path): array {
    if (!is_string($path) || trim($path) === '') {
        throw new InvalidArgumentException('JSON input path is required');
    }
    $path = recipeQuantityCliPath($path);
    $raw = file_get_contents($path);
    if (
        $raw === false
        || strlen($raw) > RECIPE_QUANTITY_MODEL_MAX_PAYLOAD_BYTES
    ) {
        throw new InvalidArgumentException('JSON input is missing or too large');
    }
    $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('JSON input must be an object');
    }
    return $decoded;
}

function recipeQuantityCliWrite(mixed $path, string $contents): void {
    if (!is_string($path) || trim($path) === '') {
        return;
    }
    $path = recipeQuantityCliPath($path);
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('could not write output file');
    }
}

function recipeQuantityCliDatabase(
    array $options,
    bool $mutating
): PDO {
    $path = $options['db'] ?? null;
    if (!is_string($path) || trim($path) === '') {
        throw new InvalidArgumentException('--db is required');
    }
    $path = recipeQuantityCliPath($path);
    if (!is_file($path)) {
        throw new InvalidArgumentException('database copy does not exist');
    }
    if (
        $mutating
        && realpath($path) === realpath(DB_PATH)
        && ($options['allow-active-db'] ?? false) !== true
    ) {
        throw new RuntimeException(
            'refusing to mutate the active database without --allow-active-db'
        );
    }
    if ($mutating && ($options['write'] ?? false) !== true) {
        throw new RuntimeException('mutating commands require --write');
    }
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 10000');
    if ($mutating) {
        recipeSchemaMigrate($db);
    }
    return $db;
}

function recipeQuantityCliJson(mixed $value): string {
    return json_encode(
        $value,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
}

$command = strtolower(trim((string)($argv[1] ?? 'help')));

try {
    if (in_array($command, ['', 'help', '--help', '-h'], true)) {
        echo recipeQuantityCliUsage();
        exit(0);
    }
    $options = recipeQuantityCliOptions($argv);
    if ($command === 'parse') {
        if (!is_string($options['text'] ?? null)) {
            throw new InvalidArgumentException('--text is required');
        }
        echo recipeQuantityCliJson(recipeQuantityParse(
            $options['text'],
            (string)($options['locale'] ?? 'und'),
            (string)($options['source'] ?? 'manual')
        ));
        exit(0);
    }
    if ($command === 'prompt') {
        $input = recipeQuantityCliReadJson($options['input'] ?? null);
        $built = recipeQuantityBuildModelPrompt(
            (string)($input['text'] ?? ''),
            (string)($input['locale'] ?? 'und'),
            (string)($input['source'] ?? 'manual'),
            ['model' => (string)($options['model'] ?? 'unconfigured')]
        );
        recipeQuantityCliWrite(
            $options['prompt-out'] ?? null,
            $built['prompt'] . PHP_EOL
        );
        recipeQuantityCliWrite(
            $options['manifest-out'] ?? null,
            recipeQuantityCliJson($built['manifest'])
        );
        echo recipeQuantityCliJson($built);
        exit(0);
    }
    if ($command === 'validate') {
        $payload = recipeQuantityCliReadJson($options['payload'] ?? null);
        $manifest = recipeQuantityCliReadJson($options['manifest'] ?? null);
        echo recipeQuantityCliJson([
            'valid' => true,
            'result' => recipeQuantityValidateModelProposal(
                $payload,
                $manifest
            ),
        ]);
        exit(0);
    }
    if ($command === 'stage') {
        $db = recipeQuantityCliDatabase($options, true);
        $payload = recipeQuantityCliReadJson($options['payload'] ?? null);
        $manifest = recipeQuantityCliReadJson($options['manifest'] ?? null);
        echo recipeQuantityCliJson(recipeQuantityStageModelProposal(
            $db,
            $payload,
            $manifest
        ));
        exit(0);
    }
    if ($command === 'list') {
        $db = recipeQuantityCliDatabase($options, false);
        $status = (string)($options['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            throw new InvalidArgumentException('--status is invalid');
        }
        $stmt = $db->prepare("
            SELECT id, input_hash, source_connector, source_locale,
                   parser_version, prompt_version, model_name, result_hash,
                   review_status, reviewed_by, review_reason, reviewed_at,
                   created_at
            FROM recipe_quantity_parse_proposals
            WHERE review_status = ?
            ORDER BY id
            LIMIT 200
        ");
        $stmt->execute([$status]);
        echo recipeQuantityCliJson([
            'status' => $status,
            'proposals' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
        exit(0);
    }
    if ($command === 'review') {
        $db = recipeQuantityCliDatabase($options, true);
        $proposalId = filter_var(
            $options['proposal-id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($proposalId === false) {
            throw new InvalidArgumentException('--proposal-id is required');
        }
        echo recipeQuantityCliJson(recipeQuantityReviewModelProposal(
            $db,
            (int)$proposalId,
            (string)($options['decision'] ?? ''),
            (string)($options['actor'] ?? ''),
            (string)($options['reason'] ?? '')
        ));
        exit(0);
    }
    throw new InvalidArgumentException('unknown command: ' . $command);
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
