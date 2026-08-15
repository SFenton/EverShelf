#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$db = getDB();
echo 'Database schema ready: '
    . (databaseSchemaVersion($db) ?? 'unknown')
    . PHP_EOL;
