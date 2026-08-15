#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('CRON_MODE', true);
require_once __DIR__ . '/../api/index.php';

$limit = 3;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = (int)substr($argument, 8);
    }
}
$limit = max(
    0,
    min(SHOPPING_CLASSIFICATION_QUEUE_BATCH_LIMIT, $limit)
);

try {
    $db = getDB();
    $result = shoppingClassificationProcessQueue($db, $limit);
    if (($result['applied'] ?? 0) > 0) {
        invalidateSmartShoppingCache();
    }
    echo json_encode(
        [
            'success' => true,
            'limit' => $limit,
            ...$result,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . "\n";
} catch (Throwable $error) {
    EverLog::exception(
        $error,
        'shopping_classification_worker'
    );
    fwrite(
        STDERR,
        json_encode(
            [
                'success' => false,
                'error' => mb_substr(
                    $error->getMessage(),
                    0,
                    500,
                    'UTF-8'
                ),
            ],
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
        ) . "\n"
    );
    exit(1);
}
