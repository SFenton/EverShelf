<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('CRON_MODE', true);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/index.php';

evershelfRotateCronLog();

try {
    $db = getDB();

    ob_start();
    dbCleanup($db);
    $cleanupJson = (string)ob_get_clean();
    $cleanup = json_decode($cleanupJson, true);
    if (!is_array($cleanup) || empty($cleanup['success'])) {
        throw new RuntimeException(
            (string)($cleanup['error'] ?? 'database cleanup failed')
        );
    }
    echo '[' . date('Y-m-d H:i:s') . '] DB cleanup'
        . ' — recipes: ' . (int)($cleanup['deleted_recipes'] ?? 0)
        . ', transactions: ' . (int)($cleanup['deleted_transactions'] ?? 0)
        . ', receipts: ' . (int)($cleanup['deleted_receipts'] ?? 0)
        . "\n";

    if (env('BACKUP_ENABLED', 'true') !== 'true') {
        exit(0);
    }

    $lastBackupTs = 0;
    if (is_file(BACKUP_LAST_TS_PATH)) {
        $lastData = json_decode(
            (string)file_get_contents(BACKUP_LAST_TS_PATH),
            true
        );
        $lastBackupTs = (int)($lastData['ts'] ?? 0);
    }
    if (time() - $lastBackupTs < 82800) {
        echo '[' . date('Y-m-d H:i:s')
            . "] Backup skipped — a snapshot is less than 23 hours old\n";
        exit(0);
    }

    $backup = env('GDRIVE_ENABLED', 'false') === 'true'
        ? backupToGDrive($db)
        : createLocalBackup($db);
    if (empty($backup['success'])) {
        throw new RuntimeException(
            (string)($backup['error'] ?? 'database backup failed')
        );
    }
    echo '[' . date('Y-m-d H:i:s') . '] Backup complete: '
        . (string)($backup['filename'] ?? 'unknown')
        . ' (' . (int)($backup['size_kb'] ?? 0) . "KB)\n";
} catch (Throwable $error) {
    echo '[' . date('Y-m-d H:i:s') . '] MAINTENANCE ERROR: '
        . $error->getMessage() . "\n";
    _phpErrorReport(
        $error->getMessage(),
        $error->getFile(),
        $error->getLine(),
        $error->getTraceAsString(),
        get_class($error)
    );
    exit(1);
}
