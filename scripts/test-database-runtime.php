#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys = ON');
initializeDB($db);
migrateDB($db);

$db->exec("
    INSERT INTO products (barcode, name)
    VALUES ('', 'Empty barcode migration fixture')
");
$db->exec("
    INSERT INTO shopping_list (name, raw_name, quantity)
    VALUES ('Invalid quantity fixture', 'Invalid quantity fixture', 0)
");

$lockPath = tempnam(sys_get_temp_dir(), 'evershelf-migration-test-');
if ($lockPath === false) {
    throw new RuntimeException('Could not create migration test lock');
}
$reopenPath = $lockPath . '.sqlite';
$reopenLockPath = $lockPath . '.reopen.lock';

try {
    databaseEnsureMigrated($db, $lockPath);
    $assert(
        databaseSchemaVersion($db) === EVERSHELF_DATABASE_SCHEMA_VERSION,
        'Database migration must persist the current schema marker'
    );
    $assert(
        (int)$db->query("
            SELECT COUNT(*)
            FROM sqlite_master
            WHERE type = 'table'
              AND name = 'api_idempotency_receipts'
        ")->fetchColumn() === 1,
        'Database migration must create API idempotency receipts'
    );
    $assert(
        (int)$db->query("
            SELECT barcode IS NULL
            FROM products
            WHERE name = 'Empty barcode migration fixture'
        ")->fetchColumn() === 1,
        'Database migration must normalize empty barcodes'
    );
    $assert(
        (float)$db->query("
            SELECT quantity
            FROM shopping_list
            WHERE name = 'Invalid quantity fixture'
        ")->fetchColumn() === 1.0,
        'Database migration must normalize invalid shopping quantities'
    );

    $changesBefore = (int)$db->query('SELECT total_changes()')->fetchColumn();
    $db->exec('PRAGMA query_only = ON');
    databaseEnsureMigrated($db, $lockPath);
    $changesAfter = (int)$db->query('SELECT total_changes()')->fetchColumn();
    $assert(
        $changesAfter === $changesBefore,
        'Current-schema database opens must execute no writes'
    );
    $db->exec('PRAGMA query_only = OFF');

    $fileDb = new PDO('sqlite:' . $reopenPath);
    $fileDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $fileDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    databaseEnsureMigrated($fileDb, $reopenLockPath);
    $fileDb = null;

    $reopenedDb = new PDO('sqlite:' . $reopenPath);
    $reopenedDb->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );
    $reopenedDb->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );
    $reopenedDb->exec('PRAGMA query_only = ON');
    databaseEnsureMigrated($reopenedDb, $reopenLockPath);
    $guards = $reopenedDb->query("
        SELECT
            ingredient_ontology_prune_guard() AS prune_guard,
            ingredient_ontology_ready_mutation_guard() AS ready_guard,
            ingredient_ontology_publication_guard() AS publication_guard
    ")->fetch(PDO::FETCH_ASSOC);
    $guards = array_map('intval', $guards);
    $assert(
        $guards === [
            'prune_guard' => 0,
            'ready_guard' => 0,
            'publication_guard' => 0,
        ],
        'Current-schema reconnects must register ontology guard functions'
    );
    $reopenedDb = null;

    $assert(
        databaseIsLockError(new PDOException('database is locked')),
        'SQLite busy errors must be recognized'
    );
    $assert(
        !databaseIsLockError(new RuntimeException('database is locked')),
        'Non-PDO errors must not be treated as SQLite busy errors'
    );

    $emptyDb = new PDO('sqlite::memory:');
    $emptyDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $emptyDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    databaseEnsureMigrated($emptyDb, $lockPath);
    $assert(
        databaseSchemaVersion($emptyDb)
            === EVERSHELF_DATABASE_SCHEMA_VERSION
        && (int)$emptyDb->query("
            SELECT COUNT(*) FROM sqlite_master
            WHERE type = 'table' AND name = 'products'
        ")->fetchColumn() === 1,
        'An existing empty database must initialize and migrate completely'
    );

    $db->prepare("
        UPDATE app_settings
        SET value = ?
        WHERE key = 'database_schema_version'
    ")->execute([
        (string)(EVERSHELF_DATABASE_SCHEMA_VERSION + 1),
    ]);
    $newerRejected = false;
    try {
        databaseEnsureMigrated($db, $lockPath);
    } catch (RuntimeException $error) {
        $newerRejected = str_contains(
            $error->getMessage(),
            'newer than this EverShelf build'
        );
    }
    $assert(
        $newerRejected
        && databaseSchemaVersion($db)
            === EVERSHELF_DATABASE_SCHEMA_VERSION + 1,
        'Older builds must fail closed without lowering a newer schema marker'
    );

    $partialDb = new PDO('sqlite::memory:');
    $partialDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $partialDb->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );
    $partialDb->exec('PRAGMA foreign_keys = OFF');
    initializeDB($partialDb);
    migrateDB($partialDb);
    $partialDb->exec("
        DROP TABLE inventory;
        DROP TABLE canonical_processing_queue;
    ");
    migrateDB($partialDb);
    $assert(
        databaseCoreTableExists($partialDb, 'inventory')
        && databaseCoreTableExists(
            $partialDb,
            'canonical_processing_queue'
        ),
        'Migration must recreate missing core tables in a partial database'
    );

    $canonicalUpgradeDb = new PDO('sqlite::memory:');
    $canonicalUpgradeDb->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );
    $canonicalUpgradeDb->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );
    initializeDB($canonicalUpgradeDb);
    $canonicalUpgradeDb->exec("
        DROP TABLE canonical_processing_queue;
        CREATE TABLE canonical_processing_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL UNIQUE,
            reason TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT '',
            requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME DEFAULT NULL,
            processed_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            request_generation INTEGER NOT NULL DEFAULT 1,
            lease_token TEXT DEFAULT NULL,
            lease_generation INTEGER NOT NULL DEFAULT 0,
            lease_expires_at DATETIME DEFAULT NULL,
            request_fingerprint TEXT NOT NULL DEFAULT ''
        )
    ");
    migrateDB($canonicalUpgradeDb);
    $canonicalColumns = array_column(
        $canonicalUpgradeDb->query("
            PRAGMA table_info(canonical_processing_queue)
        ")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    $canonicalDueIndex = (bool)$canonicalUpgradeDb->query("
        SELECT 1 FROM sqlite_master
        WHERE type = 'index' AND name = 'idx_canonical_queue_due'
    ")->fetchColumn();
    $assert(
        in_array('next_retry_at', $canonicalColumns, true)
        && in_array('last_error_kind', $canonicalColumns, true)
        && $canonicalDueIndex,
        'Canonical retry scheduling must upgrade legacy queue tables idempotently'
    );

    $legacyDb = new PDO('sqlite::memory:');
    $legacyDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $legacyDb->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );
    $legacyDb->exec('PRAGMA foreign_keys = OFF');
    initializeDB($legacyDb);
    migrateDB($legacyDb);
    $legacyDb->exec("
        INSERT INTO products (name) VALUES ('Legacy transaction fixture');
        INSERT INTO transactions (
            product_id, type, quantity, location, notes
        ) VALUES (1, 'in', 1, 'dispensa', 'legacy');
        ALTER TABLE transactions RENAME TO transactions_old;
    ");
    migrateDB($legacyDb);
    $legacySql = (string)$legacyDb->query("
        SELECT sql FROM sqlite_master
        WHERE type = 'table' AND name = 'transactions'
    ")->fetchColumn();
    $assert(
        !databaseCoreTableExists($legacyDb, 'transactions_old')
        && (int)$legacyDb->query(
            'SELECT COUNT(*) FROM transactions'
        )->fetchColumn() === 1
        && str_contains($legacySql, "'waste'"),
        'Migration must atomically recover rows from transactions_old'
    );

    $workerSources = [
        'incremental-score-worker.php' =>
            (string)file_get_contents(
                __DIR__ . '/incremental-score-worker.php'
            ),
        'canonical-queue-worker.php' =>
            (string)file_get_contents(
                __DIR__ . '/canonical-queue-worker.php'
            ),
        'ontology-controller.php' =>
            (string)file_get_contents(
                __DIR__ . '/ontology-controller.php'
            ),
    ];
    $workersUseSharedMigration = true;
    foreach ($workerSources as $source) {
        $workersUseSharedMigration =
            $workersUseSharedMigration
            && str_contains($source, 'databaseEnsureMigrated(')
            && str_contains(
                $source,
                "\$databasePath . '.migration.lock'"
            );
    }
    $assert(
        $workersUseSharedMigration,
        'All long-running workers must use the shared schema marker and migration lock'
    );
} finally {
    @unlink($lockPath);
    @unlink($reopenLockPath);
    @unlink($reopenPath);
    @unlink($reopenPath . '-wal');
    @unlink($reopenPath . '-shm');
}

echo "Database runtime tests passed: {$assertions} assertions\n";
