#!/usr/bin/env php
<?php
declare(strict_types=1);

$revisionPath = dirname(__DIR__) . '/.build-revision';
$revision = is_readable($revisionPath)
    ? trim((string)file_get_contents($revisionPath))
    : '';
if (
    preg_match(
        '/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/D',
        $revision
    ) !== 1
) {
    fwrite(STDERR, "Container build revision is invalid\n");
    exit(1);
}

$databasePath = dirname(__DIR__) . '/data/evershelf.db';
if (!is_file($databasePath) || !is_readable($databasePath)) {
    fwrite(STDERR, "EverShelf database is unavailable\n");
    exit(1);
}
try {
    $db = new PDO('sqlite:' . $databasePath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout=1000');
    $products = $db->query("
        SELECT 1
        FROM sqlite_master
        WHERE type = 'table' AND name = 'products'
    ")->fetchColumn();
    if (!$products) {
        throw new RuntimeException(
            'EverShelf database schema is unavailable'
        );
    }
} catch (Throwable $error) {
    fwrite(STDERR, "EverShelf database probe failed\n");
    exit(1);
}

exit(0);
