#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$GLOBALS['RECIPE_COOKIDOO_CONFIG'] = [
    'COOKIDOO_BRIDGE_URL' => 'http://cookidoo-bridge:8081',
    'COOKIDOO_BRIDGE_TOKEN' => 'lease-test-token',
    'COOKIDOO_BRIDGE_TIMEOUT_SECONDS' => '5',
    'COOKIDOO_CONNECTOR_ENABLED' => 'true',
    'COOKIDOO_DETAIL_HYDRATION_ENABLED' => 'true',
    'COOKIDOO_METADATA_BACKFILL_ENABLED' => 'false',
    'COOKIDOO_METADATA_REFRESH_DAYS' => '14',
    'COOKIDOO_RESULT_LIMIT' => '20',
];

$assertions = 0;
$assert = static function (
    bool $condition,
    string $message
) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assert(
    recipeJobFailureRetrySeconds(
        new PDOException('database is locked'),
        1
    ) === 1
    && recipeJobFailureRetrySeconds(
        new PDOException('database is locked'),
        3
    ) === 4
    && recipeJobFailureRetrySeconds(
        new RuntimeException('provider timeout'),
        1
    ) === 30
    && recipeJobFailureRetrySeconds(
        new RecipeCookidooCircuitBreakException('rate limited'),
        1,
        true
    ) === 900,
    'SQLite contention must retry quickly without changing provider backoff'
);
$path = dirname(__DIR__) . '/data/.recipe-job-leases-'
    . getmypid() . '.sqlite';
@unlink($path);
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=1000');
migrateDB($db);

$openPeer = static function () use ($path): PDO {
    $peer = new PDO('sqlite:' . $path);
    $peer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $peer->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );
    $peer->exec('PRAGMA foreign_keys=ON');
    $peer->exec('PRAGMA busy_timeout=1000');
    return $peer;
};
$emptyBridgeResponse = static function (array $payload): array {
    $page = (int)($payload['page'] ?? 0);
    return [
        'status' => 200,
        'body' => json_encode([
            'recipes' => [],
            'count' => 0,
            'pages_scanned' => 1,
            'last_page' => $page,
            'next_page' => $page + 1,
            'last_page_had_raw_hits' => false,
        ], JSON_UNESCAPED_SLASHES),
    ];
};

$legacyLease = recipeJobEnqueue(
    $db,
    'catalog_rebuild_search',
    ['scope' => 'legacy-lease', 'connector' => 'local'],
    [],
    'lease-test-legacy'
);
$db->prepare("
    UPDATE recipe_jobs
    SET status = 'in_progress',
        lease_token = NULL,
        lease_expires_at = NULL,
        started_at = datetime('now', '-1 hour')
    WHERE id = ?
")->execute([(int)$legacyLease['id']]);
recipeSchemaMigrate($db);
$legacyLease = recipeJobGet($db, (int)$legacyLease['id']);
$assert(
    $legacyLease['status'] === 'retry'
    && $legacyLease['next_retry_at'] !== null
    && (int)$legacyLease['request_epoch'] > 0
    && strlen((string)$legacyLease['request_hash']) === 64,
    'Legacy in-progress jobs did not migrate to fenced retry'
);
$cron = (string)file_get_contents(
    __DIR__ . '/../docker/evershelf-cron'
);
$metadataBackfillSource = (string)file_get_contents(
    __DIR__ . '/backfill-cookidoo-metadata-v2.php'
);
$recipeCronLines = [];
foreach (preg_split('/\R/', $cron) ?: [] as $line) {
    if (str_contains($line, 'process-recipe-queue.php')) {
        $recipeCronLines[] = $line;
    }
}
$providerCronLine = '';
$localCronLine = '';
$metadataBackfillCronLine = '';
foreach ($recipeCronLines as $line) {
    if (str_contains($line, '--provider-only')) {
        $providerCronLine = $line;
    }
    if (str_contains($line, '--local-only')) {
        $localCronLine = $line;
    }
}
foreach (preg_split('/\R/', $cron) ?: [] as $line) {
    if (str_contains($line, '--enqueue-if-enabled')) {
        $metadataBackfillCronLine = $line;
    }
}
$assert(
    $providerCronLine !== ''
    && $localCronLine !== ''
    && !str_contains($providerCronLine, 'background-writer.lock')
    && !str_contains($providerCronLine, 'flock')
    && !str_contains($localCronLine, 'background-writer.lock')
    && !str_contains($localCronLine, 'flock'),
    'Recipe cron still holds the outer background-writer flock'
);
$assert(
    str_contains($providerCronLine, '--provider-only')
    && str_contains($providerCronLine, '--limit=6')
    && str_contains($localCronLine, '--local-only'),
    'Recipe cron lanes must remain isolated'
);
$assert(
    str_contains(
        $metadataBackfillCronLine,
        'backfill-cookidoo-metadata-v2.php'
    )
    && str_contains($metadataBackfillCronLine, '--batch-size=6')
    && str_contains($metadataBackfillCronLine, '--max-recipes=6'),
    'Cookidoo metadata backfill must use one six-recipe request per minute'
);
$assert(
    str_contains(
        $metadataBackfillSource,
        'cookidoo_metadata_backfill_queue_not_empty'
    )
    && str_contains(
        $metadataBackfillSource,
        "(int)\$status['jobs']['queued']"
    )
    && str_contains(
        $metadataBackfillSource,
        "(int)\$status['jobs']['running']"
    ),
    'Cron-safe metadata enqueue must apply pending-work backpressure'
);
$assert(
    strpos($metadataBackfillSource, 'try {')
        < strpos(
            $metadataBackfillSource,
            'cookidoo_metadata_backfill_queue_not_empty'
        ),
    'Metadata backpressure must execute after status is loaded'
);
$db->prepare("DELETE FROM recipe_jobs WHERE id = ?")
    ->execute([(int)$legacyLease['id']]);

$localWorkerLease = recipeJobWorkerLeaseAcquire(
    $db,
    2,
    'queue_local',
    'local'
);
$providerWorkerLease = recipeJobWorkerLeaseAcquire(
    $openPeer(),
    2,
    'queue_provider',
    'provider'
);
$assert(
    $localWorkerLease !== null
    && $providerWorkerLease !== null
    && (int)$localWorkerLease['lease_seconds'] <= 900
    && (int)$providerWorkerLease['lease_seconds']
        > (int)$localWorkerLease['lease_seconds']
    && recipeJobWorkerLeaseRelease(
        $openPeer(),
        $localWorkerLease
    )
    && recipeJobWorkerLeaseRelease(
        $db,
        $providerWorkerLease
    ),
    'Local and provider recipe workers must lease independently'
);
$db->exec("
    DELETE FROM recipe_worker_leases
    WHERE lease_name IN ('queue_local', 'queue_provider')
");

$providerFixtureIds = [];
for ($index = 0; $index < 3; $index++) {
    $providerFixtureIds[] = (int)recipeJobEnqueue(
        $db,
        'recipe_metadata_refresh',
        [
            'scope' => 'provider-metadata-cap-' . $index,
            'connector' => 'cookidoo',
        ],
        ['fixture' => $index],
        'lease-test-provider-metadata-cap-' . $index
    )['id'];
}
$providerDiscoveryId = (int)recipeJobEnqueue(
    $db,
    'connector_discovery',
    [
        'scope' => 'provider-discovery-cap',
        'connector' => 'cookidoo',
    ],
    ['fixture' => 'discovery'],
    'lease-test-provider-discovery-cap'
)['id'];
$providerFixtureIds[] = $providerDiscoveryId;
$providerClaims = recipeJobClaimBatch(
    $db,
    6,
    3,
    true,
    null,
    'provider'
);
$metadataClaims = array_values(array_filter(
    $providerClaims,
    static fn(array $claim): bool =>
        (string)$claim['job_type'] === 'recipe_metadata_refresh'
));
$discoveryClaims = array_values(array_filter(
    $providerClaims,
    static fn(array $claim): bool =>
        (string)$claim['job_type'] === 'connector_discovery'
));
$db->exec("
    UPDATE recipe_connector_state
    SET last_error = '',
        failure_count = 0,
        circuit_open_until = NULL
    WHERE connector = 'cookidoo'
");
$lockRetryStatus = recipeJobReleaseClaim(
    $db,
    $metadataClaims[0],
    new PDOException('database is locked'),
    3
);
$lockRetryConnector = $db->query("
    SELECT failure_count, circuit_open_until
    FROM recipe_connector_state
    WHERE connector = 'cookidoo'
")->fetch(PDO::FETCH_ASSOC);
$assert(
    count($metadataClaims) === 1
    && count($discoveryClaims) === 1
    && $lockRetryStatus === 'retry'
    && (int)$lockRetryConnector['failure_count'] === 0
    && $lockRetryConnector['circuit_open_until'] === null,
    'Provider claims must cap metadata at one batch and ignore local lock '
        . 'errors for connector health'
);
$db->exec("
    DELETE FROM recipe_jobs
    WHERE id IN (" . implode(',', $providerFixtureIds) . ")
");

$socketPath = $path . '.recipe-worker.sock';
$heartbeatPath = $path . '.recipe-worker-heartbeat';
$statusPath = $path . '.recipe-worker-status';
$workerPipes = [];
$worker = proc_open(
    [
        PHP_BINARY,
        __DIR__ . '/recipe-queue-worker.php',
        '--db=' . $path,
        '--loop',
        '--poll-ms=30000',
        '--limit=5',
        '--max-attempts=3',
        '--socket=' . $socketPath,
        '--heartbeat=' . $heartbeatPath,
        '--status-file=' . $statusPath,
    ],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $workerPipes,
    dirname(__DIR__)
);
if (!is_resource($worker)) {
    throw new RuntimeException(
        'Could not start the recipe queue worker fixture'
    );
}
fclose($workerPipes[0]);
stream_set_blocking($workerPipes[1], false);
stream_set_blocking($workerPipes[2], false);
$workerReadyDeadline = microtime(true) + 5;
while (!file_exists($socketPath)) {
    if (microtime(true) >= $workerReadyDeadline) {
        throw new RuntimeException(
            'Recipe queue worker wake socket did not become ready'
        );
    }
    usleep(20000);
}
$idleLocalLeaseCount = (int)$db->query("
    SELECT COUNT(*)
    FROM recipe_worker_leases
    WHERE lease_name = 'queue_local'
")->fetchColumn();
$wakeJob = recipeJobEnqueue(
    $db,
    'catalog_rebuild_search',
    ['scope' => 'wake-driven-local', 'connector' => 'local'],
    [],
    'lease-test-wake-driven-local'
);
$GLOBALS['RECIPE_QUEUE_TEST_WAKE_SOCKET'] = $socketPath;
$wakeStarted = hrtime(true);
$assert(
    $idleLocalLeaseCount === 0
    && recipeJobWake(),
    'Recipe queue wake datagram could not be delivered'
);
$wakeDeadline = microtime(true) + 2;
$wakeStatus = '';
do {
    usleep(20000);
    $wakeStatus = (string)(
        recipeJobGet($db, (int)$wakeJob['id'])['status'] ?? ''
    );
} while (
    $wakeStatus !== 'done'
    && microtime(true) < $wakeDeadline
);
$wakeElapsedMs =
    (hrtime(true) - $wakeStarted) / 1000000;
unset($GLOBALS['RECIPE_QUEUE_TEST_WAKE_SOCKET']);
proc_terminate($worker);
foreach ($workerPipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}
proc_close($worker);
foreach ([
    $socketPath,
    $heartbeatPath,
    $statusPath,
    dirname($socketPath) . '/.recipe-queue-worker.lock',
] as $workerPath) {
    @unlink($workerPath);
}
$assert(
    $wakeStatus === 'done'
    && $wakeElapsedMs < 2000,
    'A wake-driven local recipe job did not settle promptly: '
        . json_encode([
            'status' => $wakeStatus,
            'elapsed_ms' => round($wakeElapsedMs, 3),
        ], JSON_UNESCAPED_SLASHES)
);

$localLaneJob = recipeJobEnqueue(
    $db,
    'catalog_rebuild_search',
    ['scope' => 'local-lane', 'connector' => 'local'],
    [],
    'lease-test-local-lane'
);
$providerLaneJob = recipeJobEnqueue(
    $db,
    'connector_discovery',
    [
        'scope' => 'provider-lane',
        'connector' => 'cookidoo',
        'query' => 'lane fixture',
    ],
    [
        'query' => 'lane fixture',
        'locale' => 'en-US',
        'languages' => ['en'],
        'tmv' => 'TM6',
        'limit' => 20,
        'page' => 0,
    ],
    'lease-test-provider-lane'
);
$localClaims = recipeJobClaimBatch(
    $db,
    5,
    3,
    false,
    null,
    'local'
);
$providerClaims = recipeJobClaimBatch(
    $openPeer(),
    5,
    3,
    true,
    null,
    'provider'
);
$assert(
    array_map(
        static fn(array $claim): int => (int)$claim['id'],
        $localClaims
    ) === [(int)$localLaneJob['id']]
    && array_map(
        static fn(array $claim): int => (int)$claim['id'],
        $providerClaims
    ) === [(int)$providerLaneJob['id']],
    'Local and provider claims crossed recipe queue lanes'
);
$db->prepare("DELETE FROM recipe_jobs WHERE id IN (?, ?)")
    ->execute([
        (int)$localLaneJob['id'],
        (int)$providerLaneJob['id'],
    ]);

$singletonJob = recipeJobEnqueue(
    $db,
    'catalog_rebuild_search',
    ['scope' => 'worker-singleton', 'connector' => 'local'],
    [],
    'lease-test-worker-singleton'
);
$singletonLease = recipeJobWorkerLeaseAcquire($db, 2);
$singletonStarted = hrtime(true);
$singletonSkipped = recipeJobProcessQueueBatch(
    $openPeer(),
    1,
    3,
    false
);
$singletonElapsedMs = (int)round(
    (hrtime(true) - $singletonStarted) / 1000000
);
$assert(
    $singletonLease !== null
    && $singletonSkipped['worker_skipped'] === true
    && $singletonSkipped['worker_skip_reason']
        === 'worker_lease_active'
    && $singletonSkipped['processed'] === 0
    && $singletonElapsedMs < 500
    && recipeJobGet(
        $db,
        (int)$singletonJob['id']
    )['status'] === 'pending'
    && !str_contains(
        json_encode($singletonSkipped, JSON_THROW_ON_ERROR),
        (string)$singletonLease['lease_token']
    ),
    'An active singleton worker lease did not make a second batch skip quickly'
);
$db->exec("
    UPDATE recipe_worker_leases
    SET lease_expires_at = datetime('now', '-1 second')
    WHERE lease_name = 'queue'
");
$reclaimedWorkerLease = recipeJobWorkerLeaseAcquire($openPeer(), 2);
$staleWorkerRelease = recipeJobWorkerLeaseRelease(
    $db,
    $singletonLease
);
$workerLeaseState = $db->query("
    SELECT lease_token, lease_generation
    FROM recipe_worker_leases
    WHERE lease_name = 'queue'
")->fetch(PDO::FETCH_ASSOC);
$assert(
    $reclaimedWorkerLease !== null
    && (int)$reclaimedWorkerLease['lease_generation']
        > (int)$singletonLease['lease_generation']
    && $staleWorkerRelease === false
    && hash_equals(
        (string)$reclaimedWorkerLease['lease_token'],
        (string)$workerLeaseState['lease_token']
    )
    && (int)$workerLeaseState['lease_generation']
        === (int)$reclaimedWorkerLease['lease_generation'],
    'Expired singleton recovery did not fence a stale release token'
);
$assert(
    recipeJobWorkerLeaseRelease(
        $openPeer(),
        $reclaimedWorkerLease
    )
    && recipeJobProcessQueueBatch(
        $db,
        1,
        3,
        false
    )['succeeded'] === 1
    && recipeJobGet(
        $db,
        (int)$singletonJob['id']
    )['status'] === 'done',
    'A crashed singleton worker lease was not reclaimed safely'
);

$job = recipeJobEnqueue(
    $db,
    'catalog_rebuild_search',
    ['scope' => 'duplicate-claim', 'connector' => 'local'],
    [],
    'lease-test-duplicate'
);
$claim = recipeJobClaimBatch($db, 1, 3, true)[0];
$peer = $openPeer();
$assert(
    recipeJobClaimBatch($peer, 1, 3, true) === [],
    'A second worker claimed an active lease'
);
$publicJob = recipeJobGet($db, (int)$job['id']);
$assert(
    !array_key_exists('lease_token', $publicJob)
    && (int)$publicJob['lease_generation'] === 1,
    'Public job decoding exposed a lease token'
);
$leaseDeadline = strtotime(
    (string)$claim['lease_expires_at'] . ' UTC'
);
$assert(
    $leaseDeadline !== false
    && $leaseDeadline - time()
        > recipeCookidooBridgeTimeoutSeconds(),
    'Provider timeout is not bounded below the lease deadline'
);
$outcome = recipeJobDispatch($db, $claim);
recipeJobFinishLocalOutcome($db, $claim, $outcome);
$assert(
    recipeJobGet($db, (int)$job['id'])['status'] === 'done',
    'The owning worker could not complete its claim'
);

$crashJob = recipeJobEnqueue(
    $db,
    'catalog_rebuild_search',
    ['scope' => 'crash-reclaim', 'connector' => 'local'],
    [],
    'lease-test-crash'
);
$oldClaim = recipeJobClaimBatch($db, 1, 3, true)[0];
$db->prepare("
    UPDATE recipe_jobs
    SET lease_expires_at = datetime('now', '-1 second')
    WHERE id = ?
")->execute([(int)$crashJob['id']]);
$newClaim = recipeJobClaimBatch($db, 1, 3, true)[0];
$staleCompletionRejected = false;
try {
    recipeJobFinishLocalOutcome(
        $db,
        $oldClaim,
        ['status' => 'done', 'result' => []]
    );
} catch (RecipeJobFenceException $error) {
    $staleCompletionRejected = true;
}
$assert(
    $staleCompletionRejected
    && (int)$newClaim['request_epoch']
        === (int)$oldClaim['request_epoch']
    && (int)$newClaim['lease_generation']
        > (int)$oldClaim['lease_generation'],
    'Crash reclaim did not retain request order and fence the stale worker'
);
recipeJobFinishLocalOutcome(
    $db,
    $newClaim,
    recipeJobDispatch($db, $newClaim)
);

$superseded = recipeJobEnqueue(
    $db,
    'catalog_rebuild_search',
    ['scope' => 'supersede', 'connector' => 'local'],
    ['revision' => 1],
    'lease-test-supersede'
);
$supersededClaim = recipeJobClaimBatch($db, 1, 3, true)[0];
$replacement = recipeJobEnqueue(
    $db,
    'catalog_rebuild_search',
    ['scope' => 'supersede', 'connector' => 'local'],
    ['revision' => 2],
    'lease-test-supersede'
);
$supersedeRejected = false;
try {
    recipeJobFinishLocalOutcome(
        $db,
        $supersededClaim,
        ['status' => 'done', 'result' => []]
    );
} catch (RecipeJobFenceException $error) {
    $supersedeRejected = true;
}
$assert(
    $supersedeRejected
    && (int)$replacement['request_epoch']
        > (int)$superseded['request_epoch']
    && (int)$replacement['request_generation']
        > (int)$superseded['request_generation'],
    'A superseding request did not invalidate the prior lease'
);
$replacementClaim = recipeJobClaimBatch($db, 1, 3, true)[0];
recipeJobFinishLocalOutcome(
    $db,
    $replacementClaim,
    recipeJobDispatch($db, $replacementClaim)
);

$providerHooks = [];
$GLOBALS['RECIPE_JOB_TEST_HOOK'] =
    static function (
        string $stage,
        array $context
    ) use (&$providerHooks): void {
        if ($stage === 'before_provider_io') {
            $providerHooks[] = $context;
        }
    };
$transactionProviderCalls = 0;
$GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
    static function (
        string $url,
        string $token,
        array $payload,
        int $timeout
    ) use (&$transactionProviderCalls, $emptyBridgeResponse): array {
        $transactionProviderCalls++;
        return $emptyBridgeResponse($payload);
    };
$transactionRejected = false;
$db->exec('BEGIN IMMEDIATE');
try {
    recipeCookidooBridgeSearch($db, [
        'query' => 'caller transaction',
        'locale' => 'en-GB',
        'limit' => 1,
    ]);
} catch (RuntimeException $error) {
    $transactionRejected = str_contains(
        $error->getMessage(),
        'SQLite transaction'
    );
} finally {
    $db->exec('ROLLBACK');
}
$assert(
    $transactionRejected && $transactionProviderCalls === 0,
    'A caller-owned write transaction reached provider I/O'
);
$legacyQueueLockPath = recipeJobLegacyQueueLockPath();
$legacyQueueLockCreated = !file_exists($legacyQueueLockPath);
if ($legacyQueueLockCreated) {
    file_put_contents($legacyQueueLockPath, '');
}
$heldLegacyQueueLock = fopen($legacyQueueLockPath, 'c');
if (
    $heldLegacyQueueLock === false
    || !flock($heldLegacyQueueLock, LOCK_EX | LOCK_NB)
) {
    throw new RuntimeException(
        'Could not establish the legacy queue-lock test fixture'
    );
}
$legacyFlockRejected = false;
try {
    recipeCookidooBridgeSearch($db, [
        'query' => 'legacy flock boundary',
        'locale' => 'en-GB',
        'limit' => 1,
    ]);
} catch (RuntimeException $error) {
    $legacyFlockRejected = str_contains(
        $error->getMessage(),
        'legacy recipe queue flock'
    );
} finally {
    flock($heldLegacyQueueLock, LOCK_UN);
    fclose($heldLegacyQueueLock);
}
$assert(
    $legacyFlockRejected && $transactionProviderCalls === 0,
    'Provider I/O was not blocked by an actual held legacy queue flock'
);
$providerFlockChecks = 0;
$GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
    static function (
        string $url,
        string $token,
        array $payload,
        int $timeout
    ) use (
        $openPeer,
        $emptyBridgeResponse,
        $legacyQueueLockPath,
        &$providerFlockChecks
    ): array {
        $lockProbe = fopen($legacyQueueLockPath, 'c');
        if (
            $lockProbe === false
            || !flock($lockProbe, LOCK_EX | LOCK_NB)
        ) {
            throw new RuntimeException(
                'provider observed a held recipe queue flock'
            );
        }
        $providerFlockChecks++;
        flock($lockProbe, LOCK_UN);
        fclose($lockProbe);
        $peer = $openPeer();
        $peer->exec('BEGIN IMMEDIATE');
        $peer->prepare("
            INSERT INTO app_settings (key, value, updated_at)
            VALUES ('recipe_provider_contention_probe', 'ok', CURRENT_TIMESTAMP)
            ON CONFLICT(key) DO UPDATE SET
                value = excluded.value,
                updated_at = CURRENT_TIMESTAMP
        ")->execute();
        $peer->exec('COMMIT');
        usleep(50000);
        return $emptyBridgeResponse($payload);
    };
$discovery = recipeCookidooEnqueueDiscoveryJob(
    $db,
    [
        'query' => 'contention probe',
        'locale' => 'en-GB',
        'limit' => 1,
    ],
    false
);
$providerResult = recipeJobProcessQueueBatch($db, 1, 3, true);
$assert(
    $providerResult['succeeded'] === 1
    && $db->query("
        SELECT value
        FROM app_settings
        WHERE key = 'recipe_provider_contention_probe'
    ")->fetchColumn() === 'ok'
    && $providerHooks !== []
    && $providerFlockChecks === 1
    && count(array_filter(
        $providerHooks,
        static fn(array $hook): bool =>
            !empty($hook['sqlite_transaction'])
            || !empty($hook['legacy_queue_flock_held'])
    )) === 0,
    'Provider I/O held a SQLite transaction or queue flock: '
        . json_encode([
            'result' => $providerResult,
            'hooks' => $providerHooks,
            'probe' => $db->query("
                SELECT value
                FROM app_settings
                WHERE key = 'recipe_provider_contention_probe'
            ")->fetchColumn(),
        ])
);

$expiring = recipeCookidooEnqueueDiscoveryJob(
    $db,
    [
        'query' => 'lease expiry probe',
        'locale' => 'en-GB',
        'limit' => 1,
    ],
    false
);
$expiringJobId = (int)$expiring['job']['id'];
$GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
    static function (
        string $url,
        string $token,
        array $payload,
        int $timeout
    ) use (
        $openPeer,
        $emptyBridgeResponse,
        $expiringJobId
    ): array {
        $peer = $openPeer();
        $peer->prepare("
            UPDATE recipe_jobs
            SET lease_expires_at = datetime('now', '-1 second')
            WHERE id = ?
        ")->execute([$expiringJobId]);
        return $emptyBridgeResponse($payload);
    };
$expiredResult = recipeJobProcessQueueBatch($db, 1, 3, true);
$assert(
    $expiredResult['superseded'] === 1
    && recipeJobGet($db, $expiringJobId)['status']
        === 'in_progress',
    'A provider result applied after its lease expired'
);
$GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
    static fn(
        string $url,
        string $token,
        array $payload,
        int $timeout
    ): array => $emptyBridgeResponse($payload);
$recoveredResult = recipeJobProcessQueueBatch($db, 1, 3, true);
$assert(
    $recoveredResult['succeeded'] === 1
    && recipeJobGet($db, $expiringJobId)['status'] === 'done',
    'An expired provider lease did not recover safely'
);

$recipe = recipeCatalogSaveVariant($db, [
    'title' => 'Request epoch recipe',
    'source_ingredients' => [[
        'name' => 'Tomato',
        'source_quantity' => 1,
        'source_unit' => 'piece',
        'source_amount_text' => '1 piece',
    ]],
], [
    'connector' => 'cookidoo',
    'external_id' => 'request-epoch-recipe',
    'canonical_url' =>
        'https://cookidoo.co.uk/recipes/recipe/en-GB/request-epoch-recipe',
    'locale' => 'en-GB',
]);
$recipeId = (int)$recipe['id'];
$originId = (int)$db->query("
    SELECT id
    FROM recipe_origins
    WHERE recipe_id = {$recipeId}
      AND connector = 'cookidoo'
")->fetchColumn();
$db->prepare("
    UPDATE recipe_catalog
    SET stale_at = datetime('now', '-1 day')
    WHERE id = ?
")->execute([$recipeId]);
$db->exec("DELETE FROM recipe_jobs");
$metadataPayload = [
    'locale' => 'en-GB',
    'recipes' => [[
        'recipe_id' => $recipeId,
        'origin_id' => $originId,
        'external_id' => 'request-epoch-recipe',
    ]],
];
$olderJob = recipeJobEnqueue(
    $db,
    'recipe_metadata_refresh',
    ['scope' => 'epoch-old', 'connector' => 'cookidoo'],
    $metadataPayload,
    'lease-test-epoch-old'
);
$newerJob = recipeJobEnqueue(
    $db,
    'recipe_metadata_refresh',
    ['scope' => 'epoch-new', 'connector' => 'cookidoo'],
    $metadataPayload,
    'lease-test-epoch-new'
);
$claims = recipeJobClaimBatch($db, 2, 3, true);
$claimsById = [];
foreach ($claims as $claimed) {
    $claimsById[(int)$claimed['id']] = $claimed;
}
$metadataItem = static function (float $yield): array {
    return [
        'external_id' => 'request-epoch-recipe',
        'title' => 'Request epoch recipe',
        'general' => [
            'yield_quantity' => $yield,
            'yield_unit' => 'portions',
            'prep_time_seconds' => 60,
            'cook_time_seconds' => 120,
            'active_time_seconds' => 60,
            'inactive_time_seconds' => 60,
            'total_time_seconds' => 180,
            'difficulty' => 'easy',
            'primary_category' => 'Test',
            'devices' => ['TM6'],
            'optional_devices' => [],
            'equipment' => ['spoon'],
        ],
        'ingredients' => [[
            'name' => 'Tomato',
            'source_quantity' => 1,
            'source_quantity_max' => null,
            'source_unit' => 'piece',
            'source_amount_text' => '1 piece',
            'source_group_index' => 0,
            'source_group_position' => 0,
            'source_group_title' => 'Ingredients',
            'source_ingredient_ref' => 'tomato',
            'source_default_title' => 'Tomato',
            'source_unit_ref' => 'piece',
            'source_optional' => false,
            'source_shopping_category_ref' => 'produce',
        ]],
        'image_url' =>
            'https://assets.tmecosys.com/image/upload/request-epoch.jpg',
        'canonical_url' =>
            'https://cookidoo.co.uk/recipes/recipe/en-GB/request-epoch-recipe',
        'locale' => 'en-GB',
        'provider_language' => 'en',
        'metadata_schema_version' =>
            RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        'topology_metrics' => [
            'group_count' => 1,
            'group_title_key_count' => 1,
            'group_title_nonempty_count' => 1,
            'group_title_length_total' => 11,
            'group_title_length_max' => 11,
            'ingredient_count' => 1,
            'ingredient_ref_key_count' => 1,
            'ingredient_ref_nonempty_count' => 1,
            'default_title_key_count' => 1,
            'default_title_nonempty_count' => 1,
            'unit_ref_key_count' => 1,
            'unit_ref_nonempty_count' => 1,
            'optional_key_count' => 1,
            'optional_true_count' => 0,
            'optional_false_count' => 1,
            'optional_null_count' => 0,
            'shopping_category_ref_key_count' => 1,
            'shopping_category_ref_nonempty_count' => 1,
        ],
    ];
};
$newerClaim = $claimsById[(int)$newerJob['id']];
$db->exec('BEGIN IMMEDIATE');
recipeJobAssertClaimOwned($db, $newerClaim);
recipeCookidooApplyMetadataV2(
    $db,
    $recipeId,
    $originId,
    $metadataItem(9),
    gmdate('Y-m-d H:i:s'),
    (int)$newerClaim['request_epoch']
);
recipeJobCompleteClaimInTransaction(
    $db,
    $newerClaim,
    'done',
    ['yield' => 9]
);
$db->exec('COMMIT');
$olderClaim = $claimsById[(int)$olderJob['id']];
$olderRejected = false;
$db->exec('BEGIN IMMEDIATE');
try {
    recipeJobAssertClaimOwned($db, $olderClaim);
    recipeCookidooApplyMetadataV2(
        $db,
        $recipeId,
        $originId,
        $metadataItem(1),
        gmdate('Y-m-d H:i:s'),
        (int)$olderClaim['request_epoch']
    );
    $db->exec('COMMIT');
} catch (RecipeJobFenceException $error) {
    $olderRejected = true;
    $db->exec('ROLLBACK');
}
recipeJobFinishLocalOutcome(
    $db,
    $olderClaim,
    [
        'status' => 'skipped',
        'result' => ['reason' => 'request_epoch_superseded'],
    ]
);
$epochState = $db->query("
    SELECT c.yield_quantity, o.last_applied_request_epoch
    FROM recipe_catalog c
    JOIN recipe_origins o ON o.recipe_id = c.id
    WHERE c.id = {$recipeId}
      AND o.id = {$originId}
")->fetch(PDO::FETCH_ASSOC);
$assert(
    $olderRejected
    && (float)$epochState['yield_quantity'] === 9.0
    && (int)$epochState['last_applied_request_epoch']
        === (int)$newerClaim['request_epoch'],
    'An older distinct job overwrote a newer origin result'
);

$GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
    static function (
        string $url,
        string $token,
        array $payload,
        int $timeout
    ): array {
        throw new RuntimeException('synthetic provider timeout');
    };
$timeout = recipeCookidooEnqueueDiscoveryJob(
    $db,
    [
        'query' => 'timeout retry',
        'locale' => 'en-GB',
        'limit' => 1,
    ],
    false
);
$timeoutResult = recipeJobProcessQueueBatch($db, 1, 3, true);
$timeoutState = recipeJobGet($db, (int)$timeout['job']['id']);
$assert(
    $timeoutResult['skipped'] === 1
    && $timeoutState['status'] === 'retry'
    && $timeoutState['next_retry_at'] !== null
    && str_contains(
        (string)$timeoutState['last_error'],
        'synthetic provider timeout'
    ),
    'Provider timeout did not enter fenced retry'
);
$db->prepare("
    UPDATE recipe_jobs
    SET next_retry_at = CURRENT_TIMESTAMP
    WHERE id = ?
")->execute([(int)$timeout['job']['id']]);
$GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
    static fn(
        string $url,
        string $token,
        array $payload,
        int $timeout
    ): array => $emptyBridgeResponse($payload);
$assert(
    recipeJobProcessQueueBatch($db, 1, 3, true)['succeeded'] === 1
    && recipeJobGet(
        $db,
        (int)$timeout['job']['id']
    )['status'] === 'done',
    'Retryable provider timeout did not recover'
);

$db->exec("
    UPDATE recipe_connector_state
    SET last_error = '',
        failure_count = 0,
        circuit_open_until = NULL,
        last_outcome_request_epoch = 0
    WHERE connector = 'cookidoo'
");
$GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
    static fn(
        string $url,
        string $token,
        array $payload,
        int $timeout
    ): array => [
        'status' => 429,
        'body' => '{"error":"cookidoo_upstream_rate_limited"}',
    ];
$rateLimited = recipeCookidooEnqueueDiscoveryJob(
    $db,
    [
        'query' => 'synthetic rate limit',
        'locale' => 'en-GB',
        'limit' => 1,
    ],
    false
);
$rateLimitedResult = recipeJobProcessQueueBatch(
    $db,
    1,
    3,
    true
);
$rateLimitedState = recipeJobGet(
    $db,
    (int)$rateLimited['job']['id']
);
$rateLimitedConnector = recipeConnectorStateRow(
    $db,
    'cookidoo'
);
$rateLimitedLeaseReleased = (int)$db->query("
    SELECT lease_token IS NULL
    FROM recipe_jobs
    WHERE id = " . (int)$rateLimited['job']['id']
)->fetchColumn();
$assert(
    $rateLimitedResult['skipped'] === 1
    && $rateLimitedState['status'] === 'retry'
    && $rateLimitedState['next_retry_at'] !== null
    && str_contains(
        (string)$rateLimitedState['last_error'],
        'rate limited'
    )
    && $rateLimitedLeaseReleased === 1
    && (int)$rateLimitedConnector['last_outcome_request_epoch']
        === (int)$rateLimitedState['request_epoch']
    && (int)$rateLimitedConnector['failure_count'] >= 3
    && $rateLimitedConnector['circuit_open_until'] !== null,
    'Synthetic HTTP 429 did not enter the fenced rate-limit retry path'
);

$db->exec("
    UPDATE recipe_connector_state
    SET last_error = '',
        failure_count = 0,
        circuit_open_until = NULL
    WHERE connector = 'cookidoo'
");
$GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
    static fn(
        string $url,
        string $token,
        array $payload,
        int $timeout
    ): array => [
        'status' => 500,
        'body' => '{"error":"synthetic_permanent_failure"}',
    ];
$permanent = recipeCookidooEnqueueDiscoveryJob(
    $db,
    [
        'query' => 'synthetic permanent failure',
        'locale' => 'en-GB',
        'limit' => 1,
    ],
    false,
    false,
    1
);
$permanentResult = recipeJobProcessQueueBatch(
    $db,
    1,
    1,
    true
);
$permanentState = recipeJobGet(
    $db,
    (int)$permanent['job']['id']
);
$permanentLeaseReleased = (int)$db->query("
    SELECT lease_token IS NULL
    FROM recipe_jobs
    WHERE id = " . (int)$permanent['job']['id']
)->fetchColumn();
$assert(
    $permanentResult['failed'] === 1
    && $permanentState['status'] === 'failed'
    && $permanentState['next_retry_at'] === null
    && $permanentState['finished_at'] !== null
    && str_contains(
        (string)$permanentState['last_error'],
        'HTTP 500'
    )
    && $permanentLeaseReleased === 1,
    'Permanent provider failure did not reach a fenced terminal state'
);

$db->exec("
    UPDATE recipe_connector_state
    SET last_error = '',
        failure_count = 0,
        circuit_open_until = NULL,
        last_outcome_request_epoch = 0
    WHERE connector = 'cookidoo'
");
$olderFailure = recipeJobEnqueue(
    $db,
    'connector_discovery',
    ['scope' => 'older-failure', 'connector' => 'cookidoo'],
    [
        'query' => 'older failure',
        'locale' => 'en-GB',
        RECIPE_COOKIDOO_POLICY_FIELD =>
            RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
    ],
    'lease-test-older-failure',
    3
);
$newerFailure = recipeJobEnqueue(
    $db,
    'connector_discovery',
    ['scope' => 'newer-failure', 'connector' => 'cookidoo'],
    [
        'query' => 'newer failure',
        'locale' => 'en-GB',
        RECIPE_COOKIDOO_POLICY_FIELD =>
            RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
    ],
    'lease-test-newer-failure',
    1
);
$failureClaims = recipeJobClaimBatch($db, 2, 3, true);
$failureClaimsById = [];
foreach ($failureClaims as $failureClaim) {
    $failureClaimsById[(int)$failureClaim['id']] = $failureClaim;
}
$newerFailureClaim =
    $failureClaimsById[(int)$newerFailure['id']];
$olderFailureClaim =
    $failureClaimsById[(int)$olderFailure['id']];
recipeJobReleaseClaim(
    $db,
    $newerFailureClaim,
    new RuntimeException('newer terminal provider failure'),
    1
);
recipeJobReleaseClaim(
    $db,
    $olderFailureClaim,
    new RecipeCookidooCircuitBreakException(
        'older stale synthetic 429'
    ),
    3
);
$failureConnector = recipeConnectorStateRow($db, 'cookidoo');
$assert(
    recipeJobGet(
        $db,
        (int)$newerFailure['id']
    )['status'] === 'failed'
    && recipeJobGet(
        $db,
        (int)$olderFailure['id']
    )['status'] === 'retry'
    && (int)$failureConnector['last_outcome_request_epoch']
        === (int)$newerFailureClaim['request_epoch']
    && $failureConnector['last_error']
        === 'newer terminal provider failure'
    && !str_contains(
        (string)$failureConnector['last_error'],
        'older stale'
    ),
    'An older fenced failure overwrote newer connector outcome state'
);

$providerCalls = 0;
$GLOBALS['RECIPE_COOKIDOO_CAPABILITIES_TRANSPORT'] =
    static fn(
        string $url,
        string $token,
        int $timeout
    ): array => [
        'status' => 200,
        'body' => json_encode([
            'detail_hydration' => true,
            'metadata_hydration' => true,
            'ingredient_aware_discovery' => true,
            'policy_version' => 'metadata-v2-detail-disabled',
        ]),
    ];
$GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'] =
    static function (
        string $url,
        string $token,
        array $payload,
        int $timeout
    ) use (&$providerCalls, $emptyBridgeResponse): array {
        $providerCalls++;
        return $emptyBridgeResponse($payload);
    };
$mismatch = recipeCookidooEnqueueDiscoveryJob(
    $db,
    [
        'query' => 'policy mismatch',
        'locale' => 'en-GB',
        'limit' => 1,
    ],
    false
);
$mismatchResult = recipeJobProcessQueueBatch($db, 1, 3, true);
$mismatchState = recipeJobGet(
    $db,
    (int)$mismatch['job']['id']
);
$assert(
    $mismatchResult['skipped'] === 1
    && $mismatchState['status'] === 'retry'
    && $providerCalls === 0
    && recipeConnectorCircuitIsOpen($db, 'cookidoo'),
    'Capability policy mismatch did not fail closed before hydration: '
        . json_encode([
            'result' => $mismatchResult,
            'job' => $mismatchState,
            'provider_calls' => $providerCalls,
            'connector' => recipeConnectorStateRow(
                $db,
                'cookidoo'
            ),
        ])
);

unset(
    $GLOBALS['RECIPE_JOB_TEST_HOOK'],
    $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'],
    $GLOBALS['RECIPE_COOKIDOO_CAPABILITIES_TRANSPORT'],
    $GLOBALS['RECIPE_COOKIDOO_CONFIG']
);
$peer = null;
$db = null;
@unlink($path);
@unlink($path . '-wal');
@unlink($path . '-shm');
if (!empty($legacyQueueLockCreated)) {
    @unlink(recipeJobLegacyQueueLockPath());
}

echo 'Recipe job lease tests passed: '
    . $assertions . ' assertions; wake_ms='
    . number_format($wakeElapsedMs, 3, '.', '')
    . ".\n";
