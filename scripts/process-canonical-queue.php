#!/usr/bin/env php
<?php
/**
 * Process queued canonical ingredient post-processing jobs.
 *
 * Usage:
 *   php scripts/process-canonical-queue.php [--limit=N] [--max-attempts=N] [--json]
 *
 * Product saves enqueue work here so API/HA callers can return as soon as the
 * product row is persisted. This worker performs local canonical mapping plus
 * cached FoodOn/USDA enrichment.
 */
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

$limit = (int)env('CANONICAL_QUEUE_CLI_LIMIT', '20');
$maxAttempts = (int)env('CANONICAL_QUEUE_MAX_ATTEMPTS', '3');
$json = in_array('--json', $argv, true);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int)substr($arg, 8));
    } elseif (str_starts_with($arg, '--max-attempts=')) {
        $maxAttempts = max(1, (int)substr($arg, 15));
    }
}

$db = getDB();
$result = canonicalIngredientProcessQueue($db, $limit, $maxAttempts);

if ($json) {
    echo json_encode(['success' => true] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

echo 'Processed: ' . $result['processed']
    . ', succeeded: ' . $result['succeeded']
    . ', failed: ' . $result['failed']
    . ', pending: ' . $result['pending'] . PHP_EOL;
foreach ($result['items'] as $item) {
    echo '- product #' . $item['product_id'] . ': ' . $item['status'];
    if (isset($item['mapped'])) {
        echo ' (' . $item['mapped'] . ' mappings)';
    }
    if (!empty($item['error'])) {
        echo ' — ' . $item['error'];
    }
    echo PHP_EOL;
}
