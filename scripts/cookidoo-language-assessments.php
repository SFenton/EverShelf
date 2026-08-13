#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

function cookidooLanguageUsage(): never {
    fwrite(
        STDERR,
        "Usage: php scripts/cookidoo-language-assessments.php "
            . "--db=copy.sqlite [--write] [--quarantine] "
            . "[--manifest=path.json] [--rollback=path.json] "
            . "[--limit=N] [--allow-active-db]\n"
    );
    exit(1);
}

function cookidooLanguageDatabasePath(array $argv): string {
    $path = '';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--db=')) {
            $path = trim(substr($arg, 5));
        }
    }
    if ($path === '') {
        cookidooLanguageUsage();
    }
    return recipeCliAssertDatabaseInputSafe(
        $path,
        in_array('--allow-active-db', $argv, true)
    );
}

function cookidooLanguageDatabase(
    string $path,
    bool $migrate
): PDO {
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA busy_timeout=10000');
    if ($migrate) {
        recipeSchemaMigrate($db);
    } else {
        $db->exec('PRAGMA query_only=ON');
    }
    return $db;
}

function cookidooLanguageManifestPath(array $argv): ?string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--manifest=')) {
            $path = trim(substr($arg, 11));
            return $path !== '' ? $path : null;
        }
    }
    return null;
}

function cookidooLanguageRollbackPath(array $argv): ?string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--rollback=')) {
            $path = trim(substr($arg, 11));
            return $path !== '' ? $path : null;
        }
    }
    return null;
}

function cookidooLanguageLimit(array $argv): int {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--limit=')) {
            return max(1, min(100000, (int)substr($arg, 8)));
        }
    }
    return 100000;
}

function cookidooLanguageRowHash(?array $row): ?string {
    if ($row === null) {
        return null;
    }
    return hash(
        'sha256',
        recipeCatalogJsonEncode(recipeCatalogStableValue($row))
    );
}

function cookidooLanguageRollback(
    PDO $db,
    string $path,
    bool $write
): array {
    $decoded = json_decode(
        (string)file_get_contents($path),
        true
    );
    if (
        !is_array($decoded)
        || !in_array(
            (string)($decoded['schema_version'] ?? ''),
            [
                'cookidoo-language-assessment-v1',
                'cookidoo-language-quarantine-v1',
            ],
            true
        )
        || !is_array($decoded['changes'] ?? null)
    ) {
        throw new InvalidArgumentException(
            'rollback manifest is invalid'
        );
    }
    if (!$write) {
        return [
            'success' => true,
            'dry_run' => true,
            'rollback_count' => count($decoded['changes']),
        ];
    }
    $db->beginTransaction();
    $conflicts = [];
    $restored = 0;
    $visibilityChanged = false;
    try {
        foreach ($decoded['changes'] as $change) {
            $recipeId = (int)($change['recipe_id'] ?? 0);
            if ($recipeId <= 0) {
                throw new RuntimeException(
                    'rollback manifest identity is invalid'
                );
            }
            $current = recipeCookidooLanguageAssessmentRow(
                $db,
                $recipeId
            );
            if (
                cookidooLanguageRowHash($current)
                    !== ($change['applied_hash'] ?? null)
            ) {
                $conflicts[] = $recipeId;
                continue;
            }
            $wasQuarantined =
                ($current['disposition'] ?? null) === 'quarantine';
            $previous = is_array($change['previous'] ?? null)
                ? $change['previous']
                : null;
            recipeCookidooLanguageAssessmentRestore(
                $db,
                $recipeId,
                $previous
            );
            $isQuarantined =
                ($previous['disposition'] ?? null) === 'quarantine';
            $visibilityChanged = $visibilityChanged
                || $wasQuarantined !== $isQuarantined;
            $restored++;
        }
        if ($visibilityChanged) {
            recipeScoreInvalidateCursors($db);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return [
        'success' => true,
        'dry_run' => false,
        'rollback_count' => $restored,
        'conflict_count' => count($conflicts),
        'conflict_recipe_ids' => array_slice($conflicts, 0, 100),
    ];
}

$write = in_array('--write', $argv, true);
$quarantine = in_array('--quarantine', $argv, true);
$manifestPath = cookidooLanguageManifestPath($argv);
$rollbackPath = cookidooLanguageRollbackPath($argv);
if ($quarantine && !$write) {
    throw new InvalidArgumentException(
        '--quarantine requires --write'
    );
}
if (
    $rollbackPath === null
    && $write
    && $manifestPath === null
) {
    throw new InvalidArgumentException(
        '--write requires --manifest'
    );
}
$databasePath = cookidooLanguageDatabasePath($argv);
if (
    $rollbackPath === null
    && $write
    && $manifestPath !== null
) {
    $manifestPath = recipeCliAssertOutputPathSafe(
        $manifestPath,
        $databasePath
    );
}
$db = cookidooLanguageDatabase($databasePath, $write);

if ($rollbackPath !== null) {
    echo json_encode(
        cookidooLanguageRollback($db, $rollbackPath, $write),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    ) . PHP_EOL;
    exit(0);
}
$limit = cookidooLanguageLimit($argv);
$stmt = $db->prepare("
    WITH scoped AS (
        SELECT c.id, c.title
        FROM recipe_catalog c
        WHERE c.primary_connector = 'cookidoo'
          AND c.storage_policy = 'metadata_only'
          AND c.rights_basis =
              'cookidoo_metadata_operator_approved'
          AND c.deleted_at IS NULL
          AND EXISTS (
              SELECT 1
              FROM recipe_origins origin
              WHERE origin.recipe_id = c.id
                AND origin.connector = 'cookidoo'
          )
          AND NOT EXISTS (
              SELECT 1
              FROM recipe_origins other_origin
              WHERE other_origin.recipe_id = c.id
                AND other_origin.connector <> 'cookidoo'
          )
    )
    SELECT scoped.id, scoped.title, source.position,
           source.name AS raw_text, source.normalized_name
    FROM scoped
    JOIN recipe_source_ingredients source
      ON source.recipe_id = scoped.id
    UNION ALL
    SELECT scoped.id, scoped.title, ranking.position,
           ranking.raw_text, ranking.normalized_name
    FROM scoped
    JOIN recipe_ingredients ranking
      ON ranking.recipe_id = scoped.id
    WHERE NOT EXISTS (
        SELECT 1
        FROM recipe_source_ingredients source
        WHERE source.recipe_id = scoped.id
    )
    ORDER BY id, position
");
$stmt->execute();

$counts = [
    'english' => 0,
    'non_english' => 0,
    'undetermined' => 0,
];
$samples = [
    'english' => [],
    'non_english' => [],
    'undetermined' => [],
];
$changes = [];
$visibilityChanged = false;
$processed = 0;
$currentId = null;
$currentTitle = '';
$currentIngredients = [];

$flush = static function () use (
    $db,
    $write,
    $quarantine,
    &$counts,
    &$samples,
    &$changes,
    &$visibilityChanged,
    &$processed,
    &$currentId,
    &$currentTitle,
    &$currentIngredients
): void {
    if ($currentId === null) {
        return;
    }
    $assessment = recipeCookidooContentLanguageAssessment(
        $currentTitle,
        $currentIngredients
    );
    $verdict = (string)$assessment['verdict'];
    $counts[$verdict]++;
    if (count($samples[$verdict]) < 10) {
        $samples[$verdict][] = [
            'recipe_id' => $currentId,
            'title' => $currentTitle,
            'reason' => $assessment['reason'],
            'foreign_language' =>
                $assessment['foreign_language'],
            'english_hits' => $assessment['english_hits'],
            'foreign_hits' => $assessment['foreign_hits'],
        ];
    }
    if ($write) {
        $disposition = $quarantine && $verdict === 'non_english'
            ? 'quarantine'
            : recipeCookidooLanguageDefaultDisposition($assessment);
        $change = recipeCookidooLanguageAssessmentStore(
            $db,
            $currentId,
            $assessment,
            $disposition,
            $quarantine && $verdict === 'non_english'
        );
        $visibilityChanged = $visibilityChanged
            || !empty($change['visibility_changed']);
        if (
            $change['previous'] !== $change['current']
        ) {
            $changes[] = [
                'recipe_id' => $currentId,
                'previous' => $change['previous'],
                'applied_hash' =>
                    cookidooLanguageRowHash($change['current']),
            ];
        }
    }
    $processed++;
};

if ($write) {
    $db->beginTransaction();
}
try {
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $recipeId = (int)$row['id'];
        if ($currentId !== null && $recipeId !== $currentId) {
            $flush();
            if ($processed >= $limit) {
                break;
            }
            $currentIngredients = [];
        }
        if ($currentId !== $recipeId) {
            $currentId = $recipeId;
            $currentTitle = (string)$row['title'];
        }
        if ($row['position'] !== null) {
            $currentIngredients[] = [
                'raw_text' => (string)$row['raw_text'],
                'normalized_name' =>
                    (string)$row['normalized_name'],
            ];
        }
    }
    if ($processed < $limit) {
        $flush();
    }
    if ($write) {
        if ($visibilityChanged) {
            recipeScoreInvalidateCursors($db);
        }
        $manifest = [
            'schema_version' =>
                'cookidoo-language-assessment-v1',
            'detector_version' =>
                RECIPE_COOKIDOO_LANGUAGE_DETECTOR_VERSION,
            'rules_hash' =>
                recipeCookidooLanguageRulesHash(),
            'created_at' => gmdate('c'),
            'changes' => $changes,
        ];
        $encoded = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
        if (!is_string($encoded)) {
            throw new RuntimeException(
                'assessment manifest could not be encoded'
            );
        }
        recipeCliWriteFileAtomically(
            $manifestPath,
            $encoded . PHP_EOL
        );
        $db->commit();
    }
} catch (Throwable $e) {
    if ($write && $db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

echo json_encode([
    'success' => true,
    'dry_run' => !$write,
    'quarantine' => $quarantine,
    'processed' => $processed,
    'counts' => $counts,
    'changes' => count($changes),
    'detector_version' =>
        RECIPE_COOKIDOO_LANGUAGE_DETECTOR_VERSION,
    'rules_hash' => recipeCookidooLanguageRulesHash(),
    'samples' => $samples,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
