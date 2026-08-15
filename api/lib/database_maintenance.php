<?php
declare(strict_types=1);

function databaseMaintenanceOnlineBackup(
    string $sourcePath,
    string $destinationPath
): array {
    if (!class_exists(SQLite3::class)) {
        throw new RuntimeException('SQLite3 backup support is unavailable');
    }
    if (!is_file($sourcePath)) {
        throw new RuntimeException('Database backup source does not exist');
    }
    $destinationDirectory = dirname($destinationPath);
    if (
        !is_dir($destinationDirectory)
        && !mkdir($destinationDirectory, 0775, true)
        && !is_dir($destinationDirectory)
    ) {
        throw new RuntimeException(
            'Could not create the database backup directory'
        );
    }

    $temporaryPath = $destinationPath . '.tmp.' . getmypid() . '.'
        . bin2hex(random_bytes(6));
    $source = null;
    $destination = null;
    try {
        $source = new SQLite3($sourcePath, SQLITE3_OPEN_READONLY);
        $source->enableExceptions(true);
        $source->busyTimeout(5000);
        $destination = new SQLite3(
            $temporaryPath,
            SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE
        );
        $destination->enableExceptions(true);
        $destination->busyTimeout(5000);
        if (!$source->backup($destination)) {
            throw new RuntimeException('SQLite online backup failed');
        }
        $quickCheck = (string)$destination->querySingle(
            'PRAGMA quick_check'
        );
        if ($quickCheck !== 'ok') {
            throw new RuntimeException(
                'SQLite backup quick_check failed: ' . $quickCheck
            );
        }
        $destination->close();
        $destination = null;
        $source->close();
        $source = null;

        if (!chmod($temporaryPath, 0660)) {
            throw new RuntimeException(
                'Could not set database backup permissions'
            );
        }
        if (!rename($temporaryPath, $destinationPath)) {
            throw new RuntimeException('Could not publish database backup');
        }
        clearstatcache(true, $destinationPath);
        return [
            'path' => $destinationPath,
            'bytes' => (int)filesize($destinationPath),
            'quick_check' => $quickCheck,
        ];
    } finally {
        if ($destination instanceof SQLite3) {
            $destination->close();
        }
        if ($source instanceof SQLite3) {
            $source->close();
        }
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

function databaseMaintenanceCleanup(
    PDO $db,
    int $recipeDays,
    int $transactionDays
): array {
    $recipeDays = max(1, $recipeDays);
    $transactionDays = max(30, $transactionDays);

    return dbWithRetry(
        static function () use (
            $db,
            $recipeDays,
            $transactionDays
        ): array {
            $transactionStarted = false;
            $db->exec('BEGIN IMMEDIATE');
            $transactionStarted = true;
            try {
                $deletedRecipes = recipeLegacyCleanup(
                    $db,
                    $recipeDays
                );
                $transactions = $db->prepare("
                    DELETE FROM transactions
                    WHERE created_at < datetime('now', ? || ' days')
                ");
                $transactions->execute(['-' . $transactionDays]);
                $deletedTransactions = $transactions->rowCount();

                $deletedReceipts = 0;
                $receiptsExist = $db->query("
                    SELECT 1
                    FROM sqlite_master
                    WHERE type = 'table'
                      AND name = 'api_idempotency_receipts'
                ")->fetchColumn();
                if ($receiptsExist) {
                    $deletedReceipts = $db->exec("
                        DELETE FROM api_idempotency_receipts
                        WHERE expires_at <= CURRENT_TIMESTAMP
                    ");
                }

                $db->exec('COMMIT');
                $transactionStarted = false;
                return [
                    'deleted_recipes' => $deletedRecipes,
                    'deleted_transactions' => $deletedTransactions,
                    'deleted_receipts' => $deletedReceipts,
                    'vacuumed' => false,
                ];
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    try {
                        $db->exec('ROLLBACK');
                    } catch (Throwable $rollbackError) {
                    }
                }
                throw $error;
            }
        }
    );
}
