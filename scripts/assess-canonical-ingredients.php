#!/usr/bin/env php
<?php
/**
 * Report canonical ingredient mapping coverage.
 *
 * Usage:
 *   php scripts/assess-canonical-ingredients.php [--all] [--json]
 */
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

$activeOnly = !in_array('--all', $argv, true);
$json = in_array('--json', $argv, true);

$db = getDB();
$assessment = canonicalIngredientAssess($db, $activeOnly);

if ($json) {
    echo json_encode($assessment, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

echo 'Scope: ' . $assessment['scope'] . PHP_EOL;
echo 'Coverage: ' . $assessment['products_with_primary'] . '/' . $assessment['products_total']
    . ' (' . $assessment['coverage_pct'] . "%)\n";
echo 'Roles: ' . json_encode($assessment['role_counts'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'Sources: ' . json_encode($assessment['source_counts'], JSON_UNESCAPED_UNICODE) . PHP_EOL;

echo PHP_EOL . "Examples\n";
foreach (array_slice($assessment['examples'], 0, 12) as $row) {
    echo '- #' . $row['product_id'] . ' ' . $row['product'] . ' => ' . $row['primary']
        . ' (' . $row['confidence'] . ', ' . $row['source'] . ')';
    if (!empty($row['aliases'])) {
        echo ' [' . implode(' > ', $row['aliases']) . ']';
    }
    echo PHP_EOL;
}

if (!empty($assessment['low_confidence'])) {
    echo PHP_EOL . "Low confidence\n";
    foreach (array_slice($assessment['low_confidence'], 0, 12) as $row) {
        echo '- #' . $row['product_id'] . ' ' . $row['product'] . ' => ' . $row['primary']
            . ' (' . $row['confidence'] . ', ' . $row['source'] . ')' . PHP_EOL;
    }
}

if (!empty($assessment['unmatched'])) {
    echo PHP_EOL . "Unmatched\n";
    foreach (array_slice($assessment['unmatched'], 0, 12) as $row) {
        echo '- #' . $row['product_id'] . ' ' . $row['product'] . PHP_EOL;
    }
}
