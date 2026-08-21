#!/usr/bin/env php
<?php
/**
 * Process bounded leased recipe jobs. Provider I/O runs only after claim
 * commit and before the short fenced apply transaction.
 *
 * Usage:
 *   php scripts/process-recipe-queue.php [--limit=N] [--max-attempts=N] [--json]
 */
declare(strict_types=1);

define('CRON_MODE', true);

require_once __DIR__ . '/../api/bootstrap.php';

$limit = (int)env('RECIPE_QUEUE_CLI_LIMIT', '50');
$maxAttempts = (int)env('RECIPE_QUEUE_MAX_ATTEMPTS', '3');
$json = in_array('--json', $argv, true);
$respectCookidooCadence = in_array('--respect-cookidoo-cadence', $argv, true);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int)substr($arg, 8));
    } elseif (str_starts_with($arg, '--max-attempts=')) {
        $maxAttempts = max(1, (int)substr($arg, 15));
    }
}

$db = getDB();
$result = recipeJobProcessQueue(
    $db,
    $limit,
    $maxAttempts,
    !$respectCookidooCadence || recipeCookidooQueueCadenceDue()
);

if ($json) {
    echo json_encode(['success' => true] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

if (!empty($result['worker_skipped'])) {
    echo 'Worker skipped: '
        . (string)($result['worker_skip_reason'] ?? 'worker_lease_active');
    if (!empty($result['worker_lease_expires_at'])) {
        echo ' until ' . $result['worker_lease_expires_at'];
    }
    echo PHP_EOL;
    exit;
}

echo 'Processed: ' . $result['processed']
    . ', succeeded: ' . $result['succeeded']
    . ', skipped: ' . $result['skipped']
    . ', failed: ' . $result['failed'] . PHP_EOL;
foreach ($result['items'] as $item) {
    echo '- job #' . $item['id'] . ' ' . $item['job_type'] . ': ' . $item['status'];
    if (!empty($item['error'])) {
        echo ' — ' . $item['error'];
    }
    echo PHP_EOL;
}
