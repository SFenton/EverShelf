#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/lib/database_maintenance.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$directory = sys_get_temp_dir() . '/evershelf-maintenance-'
    . getmypid() . '-' . bin2hex(random_bytes(4));
if (!mkdir($directory, 0770, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create maintenance test directory');
}
$sourcePath = $directory . '/source.db';
$backupPath = $directory . '/backup.db';

try {
    $source = new PDO('sqlite:' . $sourcePath);
    $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $source->exec('PRAGMA journal_mode = WAL');
    $source->exec('CREATE TABLE backup_fixture (value TEXT NOT NULL)');
    $source->exec("INSERT INTO backup_fixture (value) VALUES ('durable')");

    $backup = databaseMaintenanceOnlineBackup(
        $sourcePath,
        $backupPath
    );
    $copy = new SQLite3($backupPath, SQLITE3_OPEN_READONLY);
    $assert(
        $backup['quick_check'] === 'ok'
        && $backup['bytes'] > 0
        && $copy->querySingle(
            'SELECT value FROM backup_fixture LIMIT 1'
        ) === 'durable',
        'Online backup must include committed WAL content'
    );
    $copy->close();
    $assert(
        glob($backupPath . '.tmp.*') === [],
        'Online backup must leave no temporary files'
    );

    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    initializeDB($db);
    migrateDB($db);
    $db->exec("
        INSERT INTO products (name) VALUES ('Cleanup fixture');
        INSERT INTO transactions (
            product_id, type, quantity, location, created_at
        ) VALUES (
            1, 'in', 1, 'dispensa', datetime('now', '-120 days')
        );
        INSERT INTO recipes (date, meal, recipe_json)
        VALUES (date('now', '-30 days'), 'dinner', '{}');
        INSERT INTO api_idempotency_receipts (
            action, idempotency_key, request_hash, response_json,
            expires_at
        ) VALUES (
            'inventory_add', 'expired', 'hash', '{}',
            datetime('now', '-1 day')
        );
    ");
    $cleanup = databaseMaintenanceCleanup($db, 7, 90);
    $assert(
        $cleanup === [
            'deleted_recipes' => 1,
            'deleted_transactions' => 1,
            'deleted_receipts' => 1,
            'vacuumed' => false,
        ],
        'Scheduled cleanup must delete expired rows without VACUUM'
    );
    $assert(
        (int)$db->query(
            'SELECT COUNT(*) FROM transactions'
        )->fetchColumn() === 0
        && (int)$db->query(
            'SELECT COUNT(*) FROM recipes'
        )->fetchColumn() === 0
        && (int)$db->query(
            'SELECT COUNT(*) FROM api_idempotency_receipts'
        )->fetchColumn() === 0,
        'Cleanup must persist each bounded retention deletion'
    );

    $indexSource = (string)file_get_contents(
        __DIR__ . '/../api/index.php'
    );
    $cleanupStart = strpos($indexSource, 'function dbCleanup(');
    $cleanupEnd = strpos(
        $indexSource,
        'function saveSettings(',
        (int)$cleanupStart
    );
    $cleanupSource = (
        is_int($cleanupStart)
        && is_int($cleanupEnd)
        && $cleanupEnd > $cleanupStart
    ) ? substr(
        $indexSource,
        $cleanupStart,
        $cleanupEnd - $cleanupStart
    ) : '';
    $backupStart = strpos(
        $indexSource,
        'function createLocalBackup('
    );
    $backupEnd = strpos(
        $indexSource,
        'function listLocalBackups(',
        (int)$backupStart
    );
    $backupSource = (
        is_int($backupStart)
        && is_int($backupEnd)
        && $backupEnd > $backupStart
    ) ? substr(
        $indexSource,
        $backupStart,
        $backupEnd - $backupStart
    ) : '';
    $smartCronSource = (string)file_get_contents(
        __DIR__ . '/../api/cron_smart_shopping.php'
    );
    $assert(
        $cleanupSource !== ''
        && !str_contains($cleanupSource, 'VACUUM')
        && str_contains(
            $cleanupSource,
            'databaseMaintenanceCleanup'
        )
        && $backupSource !== ''
        && !str_contains($backupSource, 'wal_checkpoint')
        && !str_contains($backupSource, 'copy(')
        && str_contains(
            $backupSource,
            'databaseMaintenanceOnlineBackup'
        )
        && !str_contains($smartCronSource, 'dbCleanup(')
        && !str_contains($smartCronSource, 'createLocalBackup('),
        'Routine jobs must not VACUUM, checkpoint-copy, or run maintenance every five minutes'
    );
} finally {
    foreach (glob($directory . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory);
}

echo "Database maintenance tests passed: {$assertions} assertions\n";
