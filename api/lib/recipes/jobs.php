<?php

class RecipeJobFenceException extends RuntimeException {
}

function recipeJobTestHook(
    string $stage,
    array $context = []
): void {
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && is_callable($GLOBALS['RECIPE_JOB_TEST_HOOK'] ?? null)
    ) {
        ($GLOBALS['RECIPE_JOB_TEST_HOOK'])($stage, $context);
    }
}

function recipeJobStableScope(array $scope): array {
    return recipeCatalogStableValue([
        'scope' => $scope['scope'] ?? null,
        'connector' => $scope['connector'] ?? null,
        'ingredient_id' => isset($scope['ingredient_id']) ? (int)$scope['ingredient_id'] : null,
        'product_id' => isset($scope['product_id']) ? (int)$scope['product_id'] : null,
        'query' => $scope['query'] ?? null,
    ]);
}

function recipeJobRequestHash(
    string $jobType,
    array $scope,
    array $payload
): string {
    return hash(
        'sha256',
        recipeCatalogJsonEncode(recipeCatalogStableValue([
            'job_type' => $jobType,
            'scope' => recipeJobStableScope($scope),
            'payload' => $payload,
        ]))
    );
}

function recipeJobAllocateRequestEpoch(PDO $db): int {
    $stmt = $db->query("
        UPDATE recipe_job_request_epoch
        SET next_epoch = next_epoch + 1
        WHERE id = 1
        RETURNING next_epoch - 1 AS request_epoch
    ");
    $epoch = (int)($stmt->fetchColumn() ?: 0);
    if ($epoch <= 0) {
        throw new RuntimeException(
            'recipe request epoch allocator is unavailable'
        );
    }
    return $epoch;
}

function recipeJobBuildIdempotencyKey(string $jobType, array $scope = [], array $payload = []): string {
    $basis = [
        'job_type' => $jobType,
        'scope' => recipeJobStableScope($scope),
        'payload' => recipeCatalogStableValue($payload),
    ];
    return $jobType . ':' . hash('sha256', recipeCatalogJsonEncode($basis));
}

function recipeJobEnqueue(
    PDO $db,
    string $jobType,
    array $scope = [],
    array $payload = [],
    ?string $idempotencyKey = null,
    int $maxAttempts = 3,
    int $priority = 0
): array {
    $jobType = trim($jobType);
    if ($jobType === '') {
        throw new InvalidArgumentException('Recipe job type is required');
    }
    $idempotencyKey = trim((string)($idempotencyKey ?? ''));
    if ($idempotencyKey === '') {
        $idempotencyKey = recipeJobBuildIdempotencyKey($jobType, $scope, $payload);
    }
    static $savepointSequence = 0;
    $savepoint = 'recipe_job_enqueue_' . (++$savepointSequence);
    $db->exec("SAVEPOINT {$savepoint}");
    try {
        $requestEpoch = recipeJobAllocateRequestEpoch($db);
        $requestHash = recipeJobRequestHash(
            $jobType,
            $scope,
            $payload
        );
        $stmt = $db->prepare("
        INSERT INTO recipe_jobs (
            idempotency_key, job_type, priority, scope, connector, ingredient_id, product_id,
            query, payload_json, status, max_attempts,
            request_epoch, request_generation, request_hash
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, 1, ?)
        ON CONFLICT(idempotency_key) DO UPDATE SET
            job_type = excluded.job_type,
            priority = MAX(recipe_jobs.priority, excluded.priority),
            scope = excluded.scope,
            connector = excluded.connector,
            ingredient_id = excluded.ingredient_id,
            product_id = excluded.product_id,
            query = excluded.query,
            payload_json = excluded.payload_json,
            status = 'pending',
            attempts = 0,
            max_attempts = excluded.max_attempts,
            next_retry_at = NULL,
            last_error = '',
            last_result_json = NULL,
            request_epoch = excluded.request_epoch,
            request_generation = recipe_jobs.request_generation + 1,
            request_hash = excluded.request_hash,
            lease_token = NULL,
            lease_expires_at = NULL,
            started_at = NULL,
            finished_at = NULL,
            updated_at = CURRENT_TIMESTAMP
    ");
        $stmt->execute([
            $idempotencyKey,
            $jobType,
            max(0, $priority),
            isset($scope['scope']) ? (string)$scope['scope'] : null,
            isset($scope['connector'])
                ? (string)$scope['connector']
                : null,
            isset($scope['ingredient_id'])
                ? (int)$scope['ingredient_id']
                : null,
            isset($scope['product_id'])
                ? (int)$scope['product_id']
                : null,
            isset($scope['query']) ? (string)$scope['query'] : null,
            recipeCatalogJsonEncode($payload),
            max(1, $maxAttempts),
            $requestEpoch,
            $requestHash,
        ]);
        $read = $db->prepare(
            "SELECT * FROM recipe_jobs WHERE idempotency_key = ?"
        );
        $read->execute([$idempotencyKey]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(
                'Recipe job could not be read after enqueue'
            );
        }
        $db->exec("RELEASE SAVEPOINT {$savepoint}");
        return recipeJobDecodeRow($row);
    } catch (Throwable $error) {
        try {
            $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
}

function recipeJobEnqueueOnce(
    PDO $db,
    string $jobType,
    array $scope = [],
    array $payload = [],
    ?string $idempotencyKey = null,
    int $maxAttempts = 3,
    int $priority = 0
): array {
    $jobType = trim($jobType);
    if ($jobType === '') {
        throw new InvalidArgumentException('Recipe job type is required');
    }
    $idempotencyKey = trim((string)($idempotencyKey ?? ''));
    if ($idempotencyKey === '') {
        $idempotencyKey = recipeJobBuildIdempotencyKey($jobType, $scope, $payload);
    }
    static $savepointSequence = 0;
    $savepoint = 'recipe_job_enqueue_once_'
        . (++$savepointSequence);
    $db->exec("SAVEPOINT {$savepoint}");
    try {
        $read = $db->prepare(
            "SELECT * FROM recipe_jobs WHERE idempotency_key = ?"
        );
        $read->execute([$idempotencyKey]);
        $existing = $read->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
            return [
                'job' => recipeJobDecodeRow($existing),
                'created' => false,
            ];
        }
        $requestEpoch = recipeJobAllocateRequestEpoch($db);
        $requestHash = recipeJobRequestHash(
            $jobType,
            $scope,
            $payload
        );
        $stmt = $db->prepare("
        INSERT INTO recipe_jobs (
            idempotency_key, job_type, priority, scope, connector, ingredient_id, product_id,
            query, payload_json, status, max_attempts,
            request_epoch, request_generation, request_hash
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, 1, ?)
        ON CONFLICT(idempotency_key) DO NOTHING
    ");
        $stmt->execute([
            $idempotencyKey,
            $jobType,
            max(0, $priority),
            isset($scope['scope']) ? (string)$scope['scope'] : null,
            isset($scope['connector'])
                ? (string)$scope['connector']
                : null,
            isset($scope['ingredient_id'])
                ? (int)$scope['ingredient_id']
                : null,
            isset($scope['product_id'])
                ? (int)$scope['product_id']
                : null,
            isset($scope['query']) ? (string)$scope['query'] : null,
            recipeCatalogJsonEncode($payload),
            max(1, $maxAttempts),
            $requestEpoch,
            $requestHash,
        ]);
        $created = $stmt->rowCount() > 0;
        $read = $db->prepare(
            "SELECT * FROM recipe_jobs WHERE idempotency_key = ?"
        );
        $read->execute([$idempotencyKey]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(
                'Recipe job could not be read after enqueue'
            );
        }
        $db->exec("RELEASE SAVEPOINT {$savepoint}");
        return [
            'job' => recipeJobDecodeRow($row),
            'created' => $created,
        ];
    } catch (Throwable $error) {
        try {
            $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
}

function recipeJobEnqueueInventoryChanged(PDO $db, int $productId, string $reason): array {
    $inventoryRevision = recipeScoreMarkProductDirty(
        $db,
        $productId,
        $reason
    );
    return recipeJobEnqueue(
        $db,
        'inventory_changed',
        ['scope' => 'product:' . $productId, 'connector' => 'local', 'product_id' => $productId],
        ['reason' => $reason, 'inventory_revision' => $inventoryRevision],
        'inventory_changed:product:' . $productId
    );
}

function recipeJobEnqueueTaxonomyReady(PDO $db, int $productId): array {
    return recipeJobEnqueue(
        $db,
        'taxonomy_ready',
        ['scope' => 'product:' . $productId, 'connector' => 'local', 'product_id' => $productId],
        ['reason' => 'canonical_queue_success'],
        'taxonomy_ready:product:' . $productId
    );
}

function recipeJobEnqueueIdentityAdmission(
    PDO $db,
    int $productId,
    string $reason
): array {
    return recipeJobEnqueue(
        $db,
        'identity_admission',
        [
            'scope' => 'product:' . $productId,
            'connector' => 'local',
            'product_id' => $productId,
        ],
        ['reason' => mb_substr($reason, 0, 160, 'UTF-8')],
        'identity_admission:product:' . $productId,
        20,
        50
    );
}

function recipeJobEnqueueSourceIngredientRemap(
    PDO $db,
    string $targetMappingVersion,
    int $limit = 500
): array {
    $targetMappingVersion = recipeIngredientNormalizeMappingVersion(
        $targetMappingVersion
    );
    $limit = max(1, min(1000, $limit));
    return recipeJobEnqueue(
        $db,
        'recipe_source_ingredient_remap',
        [
            'scope' => 'source-mapping:' . $targetMappingVersion,
            'connector' => 'local',
        ],
        [
            'target_mapping_version' => $targetMappingVersion,
            'limit' => $limit,
        ],
        'recipe_source_ingredient_remap:' . $targetMappingVersion,
        3,
        0
    );
}

function recipeJobDecodeInternalRow(array $row): array {
    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    $result = json_decode((string)($row['last_result_json'] ?? ''), true);
    $row['id'] = (int)$row['id'];
    $row['ingredient_id'] = $row['ingredient_id'] !== null ? (int)$row['ingredient_id'] : null;
    $row['product_id'] = $row['product_id'] !== null ? (int)$row['product_id'] : null;
    $row['attempts'] = (int)$row['attempts'];
    $row['max_attempts'] = (int)$row['max_attempts'];
    $row['priority'] = (int)($row['priority'] ?? 0);
    $row['request_epoch'] = (int)($row['request_epoch'] ?? 0);
    $row['request_generation'] =
        (int)($row['request_generation'] ?? 1);
    $row['lease_generation'] =
        (int)($row['lease_generation'] ?? 0);
    $row['payload'] = is_array($payload) ? $payload : [];
    $row['result'] = is_array($result) ? $result : null;
    unset($row['payload_json'], $row['last_result_json']);
    return $row;
}

function recipeJobDecodeRow(array $row): array {
    $row = recipeJobDecodeInternalRow($row);
    unset($row['lease_token']);
    return $row;
}

function recipeJobGet(PDO $db, ?int $id = null, ?string $idempotencyKey = null): ?array {
    if ($id !== null && $id > 0) {
        $stmt = $db->prepare("SELECT * FROM recipe_jobs WHERE id = ?");
        $stmt->execute([$id]);
    } elseif ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
        $stmt = $db->prepare("SELECT * FROM recipe_jobs WHERE idempotency_key = ?");
        $stmt->execute([trim($idempotencyKey)]);
    } else {
        return null;
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? recipeJobDecodeRow($row) : null;
}

function recipeJobsRecent(PDO $db, int $limit = 50): array {
    $limit = max(1, min(200, $limit));
    $rows = $db->query("
        SELECT * FROM recipe_jobs
        ORDER BY created_at DESC, id DESC
        LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_ASSOC);
    return array_map('recipeJobDecodeRow', $rows);
}

function recipeJobWorkerLeaseSeconds(int $batchLimit): int {
    $batchLimit = max(1, min(100, $batchLimit));
    $providerDeadline = function_exists(
        'recipeCookidooBridgeTimeoutSeconds'
    ) ? recipeCookidooBridgeTimeoutSeconds() + 30 : 150;
    $boundedBatchDeadline = ($providerDeadline * $batchLimit) + 60;
    return min(
        21600,
        max(recipeJobLeaseSeconds(), $boundedBatchDeadline)
    );
}

function recipeJobWorkerLeaseAcquire(
    PDO $db,
    int $batchLimit
): ?array {
    if (databaseTransactionIsActive($db)) {
        throw new RuntimeException(
            'recipe worker lease cannot be claimed inside a transaction'
        );
    }
    $leaseSeconds = recipeJobWorkerLeaseSeconds($batchLimit);
    $leaseToken = bin2hex(random_bytes(32));
    dbBeginImmediateWithRetry($db);
    try {
        $db->exec("
            INSERT OR IGNORE INTO recipe_worker_leases (
                lease_name, lease_generation
            )
            VALUES ('queue', 0)
        ");
        $claim = $db->prepare("
            UPDATE recipe_worker_leases
            SET lease_token = ?,
                lease_generation = lease_generation + 1,
                lease_expires_at = datetime(
                    'now',
                    '+' || ? || ' seconds'
                ),
                owner_started_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE lease_name = 'queue'
              AND (
                  lease_token IS NULL
                  OR lease_expires_at IS NULL
                  OR lease_expires_at <= CURRENT_TIMESTAMP
              )
        ");
        $claim->execute([$leaseToken, $leaseSeconds]);
        $row = $db->query("
            SELECT lease_generation, lease_expires_at,
                   owner_started_at
            FROM recipe_worker_leases
            WHERE lease_name = 'queue'
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
    if ($claim->rowCount() !== 1) {
        recipeJobTestHook('worker_lease_skipped', [
            'lease_generation' =>
                (int)($row['lease_generation'] ?? 0),
            'lease_expires_at' =>
                $row['lease_expires_at'] ?? null,
        ]);
        return null;
    }
    $lease = [
        'lease_name' => 'queue',
        'lease_token' => $leaseToken,
        'lease_generation' =>
            (int)($row['lease_generation'] ?? 0),
        'lease_expires_at' => $row['lease_expires_at'] ?? null,
        'owner_started_at' => $row['owner_started_at'] ?? null,
        'lease_seconds' => $leaseSeconds,
    ];
    recipeJobTestHook('worker_lease_acquired', [
        'lease_generation' => $lease['lease_generation'],
        'lease_expires_at' => $lease['lease_expires_at'],
        'lease_seconds' => $leaseSeconds,
    ]);
    return $lease;
}

function recipeJobWorkerLeaseRenew(
    PDO $db,
    array &$lease,
    int $remainingJobs
): bool {
    if (databaseTransactionIsActive($db)) {
        throw new RuntimeException(
            'recipe worker lease cannot be renewed inside a transaction'
        );
    }
    $leaseSeconds = recipeJobWorkerLeaseSeconds($remainingJobs);
    dbBeginImmediateWithRetry($db);
    try {
        $renew = $db->prepare("
            UPDATE recipe_worker_leases
            SET lease_expires_at = datetime(
                    'now',
                    '+' || ? || ' seconds'
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE lease_name = ?
              AND lease_token = ?
              AND lease_generation = ?
              AND lease_expires_at > CURRENT_TIMESTAMP
        ");
        $renew->execute([
            $leaseSeconds,
            (string)$lease['lease_name'],
            (string)$lease['lease_token'],
            (int)$lease['lease_generation'],
        ]);
        $expiresAt = $renew->rowCount() === 1
            ? $db->query("
                SELECT lease_expires_at
                FROM recipe_worker_leases
                WHERE lease_name = 'queue'
            ")->fetchColumn()
            : false;
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
    if ($expiresAt === false) {
        return false;
    }
    $lease['lease_expires_at'] = (string)$expiresAt;
    $lease['lease_seconds'] = $leaseSeconds;
    recipeJobTestHook('worker_lease_renewed', [
        'lease_generation' => (int)$lease['lease_generation'],
        'lease_expires_at' => (string)$expiresAt,
        'lease_seconds' => $leaseSeconds,
        'remaining_jobs' => max(0, $remainingJobs),
    ]);
    return true;
}

function recipeJobWorkerLeaseRelease(
    PDO $db,
    array $lease
): bool {
    if (databaseTransactionIsActive($db)) {
        throw new RuntimeException(
            'recipe worker lease cannot be released inside a transaction'
        );
    }
    dbBeginImmediateWithRetry($db);
    try {
        $release = $db->prepare("
            UPDATE recipe_worker_leases
            SET lease_token = NULL,
                lease_expires_at = NULL,
                owner_started_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE lease_name = ?
              AND lease_token = ?
              AND lease_generation = ?
              AND lease_expires_at > CURRENT_TIMESTAMP
        ");
        $release->execute([
            (string)$lease['lease_name'],
            (string)$lease['lease_token'],
            (int)$lease['lease_generation'],
        ]);
        $released = $release->rowCount() === 1;
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
    recipeJobTestHook('worker_lease_released', [
        'lease_generation' =>
            (int)($lease['lease_generation'] ?? 0),
        'released' => $released,
    ]);
    return $released;
}

function recipeJobLegacyQueueLockPath(): string {
    return __DIR__ . '/../../../data/recipe_queue.lock';
}

function recipeJobLegacyQueueFlockHeld(): bool {
    $path = recipeJobLegacyQueueLockPath();
    if (!file_exists($path)) {
        return false;
    }
    $handle = @fopen($path, 'c');
    if ($handle === false) {
        $handle = @fopen($path, 'r');
    }
    if ($handle === false) {
        throw new RuntimeException(
            'Cannot inspect legacy recipe queue lock file'
        );
    }
    $available = flock($handle, LOCK_EX | LOCK_NB);
    if ($available) {
        flock($handle, LOCK_UN);
    }
    fclose($handle);
    return !$available;
}

function recipeCatalogRefreshUnresolvedMappings(PDO $db, int $limit = 500): int {
    $limit = max(1, min(5000, $limit));
    $cursorStmt = $db->prepare("
        SELECT value FROM app_settings WHERE key = 'recipe_mapping_cursor'
    ");
    $cursorStmt->execute();
    $cursor = max(0, (int)($cursorStmt->fetchColumn() ?: 0));
    $queryRows = static function (PDO $db, int $afterId, int $limit): array {
        $stmt = $db->prepare("
            SELECT id, normalized_name
            FROM recipe_ingredients
            WHERE id > ?
              AND normalized_name <> ''
              AND (canonical_ingredient_id IS NULL OR taxonomy_node_id IS NULL)
            ORDER BY id
            LIMIT {$limit}
        ");
        $stmt->execute([$afterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $rows = $queryRows($db, $cursor, $limit);
    if (!$rows && $cursor > 0) {
        $cursor = 0;
        $rows = $queryRows($db, 0, $limit);
    }
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
    if ($rows) {
        $lastId = (int)$rows[count($rows) - 1]['id'];
        $db->prepare("
            INSERT INTO app_settings (key, value, updated_at)
            VALUES ('recipe_mapping_cursor', ?, CURRENT_TIMESTAMP)
            ON CONFLICT(key) DO UPDATE SET
                value = excluded.value,
                updated_at = CURRENT_TIMESTAMP
        ")->execute([(string)$lastId]);
    }
    $updated = 0;
    $stmt = $db->prepare("
        UPDATE recipe_ingredients SET
            canonical_ingredient_id = COALESCE(?, canonical_ingredient_id),
            taxonomy_node_id = COALESCE(?, taxonomy_node_id),
            mapping_confidence = CASE
                WHEN ? > mapping_confidence THEN ? ELSE mapping_confidence
            END,
            mapping_source = CASE
                WHEN ? > mapping_confidence THEN ? ELSE mapping_source
            END,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $affectedRecipeIds = [];
    foreach ($rows as $row) {
        $resolution = recipeIngredientResolve($db, (string)$row['normalized_name']);
        if (
            $resolution['canonical_ingredient_id'] === null
            && $resolution['taxonomy_node_id'] === null
        ) {
            continue;
        }
        $confidence = (float)$resolution['confidence'];
        $stmt->execute([
            $resolution['canonical_ingredient_id'],
            $resolution['taxonomy_node_id'],
            $confidence,
            $confidence,
            $confidence,
            $resolution['source'],
            (int)$row['id'],
        ]);
        if ($stmt->rowCount() > 0) {
            $updated++;
            $recipeIdStmt = $db->prepare("SELECT recipe_id FROM recipe_ingredients WHERE id = ?");
            $recipeIdStmt->execute([(int)$row['id']]);
            $recipeId = (int)($recipeIdStmt->fetchColumn() ?: 0);
            if ($recipeId > 0) {
                $affectedRecipeIds[$recipeId] = true;
            }
        }
    }
    foreach (array_keys($affectedRecipeIds) as $recipeId) {
        recipeCatalogRebuildCluster($db, (int)$recipeId);
        recipeSearchRebuildDocument($db, (int)$recipeId);
    }
    if ($updated > 0) {
        recipeScoreMarkCatalogDirty($db, true);
    }
    if ($ownsTransaction) {
        $db->commit();
    }
    return $updated;
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function recipeJobDispatch(PDO $db, array $job): array {
    $payload = $job['payload'];
    return match ($job['job_type']) {
        'inventory_changed' => recipeJobDispatchInventoryChanged($db, $job, $payload),
        'rerank_discovery' => [
            'status' => 'done',
            'result' => [
                'ranking' => 'score_rebuild_queued',
                'inventory_revision' => recipeScoreMarkDirty($db),
                'product_id' => $job['product_id'],
            ],
        ],
        'taxonomy_ready' => recipeJobDispatchTaxonomyReady($db, $job, $payload),
        'identity_admission' => recipeJobDispatchIdentityAdmission(
            $db,
            $job,
            $payload
        ),
        'catalog_rebuild_search' => [
            'status' => 'done',
            'result' => ['rebuilt_documents' => recipeSearchRebuildAll($db)],
        ],
        'recipe_metadata_refresh' => recipeJobDispatchMetadataRefresh(
            $db,
            $job,
            $payload
        ),
        'recipe_source_ingredient_remap' =>
            recipeJobDispatchSourceIngredientRemap($db, $payload),
        'recipe_refresh' => recipeJobDispatchRefresh($db, $job, $payload),
        'connector_discovery' => recipeJobDispatchConnectorDiscovery($db, $job, $payload),
        default => throw new InvalidArgumentException('Unknown recipe job type: ' . $job['job_type']),
    };
}

function recipeJobDispatchIdentityAdmission(
    PDO $db,
    array $job,
    array $payload
): array {
    $productId = (int)($job['product_id'] ?? 0);
    if ($productId <= 0) {
        return [
            'status' => 'skipped',
            'result' => ['reason' => 'missing_product'],
        ];
    }
    $admission =
        ingredientOntologyV3IdentityAdmissionPublishProduct(
            $db,
            $productId,
            null,
            (string)($payload['reason'] ?? 'background_exact_self'),
            true,
            true,
            true,
            true,
            true
        );
    return [
        'status' => 'done',
        'result' => [
            'product_id' => $productId,
            'accepted' => !empty($admission['accepted']),
            'status' => (string)($admission['status'] ?? ''),
            'source' => (string)($admission['source'] ?? ''),
            'entity_id' => (int)($admission['entity_id'] ?? 0),
            'readiness' => $admission['readiness'] ?? null,
        ],
    ];
}

function recipeJobDispatchSourceIngredientRemap(
    PDO $db,
    array $payload
): array {
    $target = recipeIngredientNormalizeMappingVersion(
        $payload['target_mapping_version'] ?? ''
    );
    $limit = $payload['limit'] ?? 500;
    if (
        is_bool($limit)
        || (!is_int($limit) && !(is_string($limit) && ctype_digit($limit)))
    ) {
        throw new InvalidArgumentException('source mapping remap limit is invalid');
    }
    return [
        'status' => 'done',
        'result' => recipeSourceIngredientRemap(
            $db,
            $target,
            max(1, min(1000, (int)$limit))
        ),
    ];
}

function recipeJobDispatchMetadataRefresh(
    PDO $db,
    array $job,
    array $payload
): array {
    if (!recipeCookidooDetailHydrationPolicyAllows()) {
        return [
            'status' => 'skipped',
            'result' => [
                'reason' => RECIPE_COOKIDOO_DETAIL_POLICY_REASON,
                'policy_version' =>
                    RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
            ],
        ];
    }
    if (!recipeConnectorIsEnabled($db, RECIPE_COOKIDOO_CONNECTOR)) {
        return [
            'status' => 'skipped',
            'result' => ['reason' => 'connector_disabled'],
        ];
    }
    $request = recipeCookidooNormalizeMetadataRefreshInput($payload);
    $revisionBefore = recipeScoreState($db);
    $verify = $db->prepare("
        SELECT o.connector, o.external_id, o.locale,
               c.primary_connector, c.deleted_at,
               c.cache_expires_at, c.stale_at,
               o.metadata_version,
               o.metadata_schema_version,
               o.last_applied_request_epoch,
               o.metadata_failure_version,
               o.metadata_failure_kind,
               o.metadata_failure_count,
               o.metadata_next_probe_at,
               o.metadata_failure_schema_version,
               (
                   SELECT COUNT(*)
                   FROM recipe_source_ingredients rsi
                   WHERE rsi.recipe_id = o.recipe_id
               ) AS source_ingredient_count
        FROM recipe_origins o
        LEFT JOIN recipe_catalog c ON c.id = o.recipe_id
        WHERE o.id = ?
          AND o.recipe_id = ?
        LIMIT 1
    ");
    $terminal = [];
    $pending = [];
    $epochAdvanceOriginIds = [];
    foreach ($request['recipes'] as $recipe) {
        $verify->execute([
            $recipe['origin_id'],
            $recipe['recipe_id'],
        ]);
        $stored = $verify->fetch(PDO::FETCH_ASSOC);
        $externalId = $recipe['external_id'];
        if (
            !$stored
            || (string)($stored['connector'] ?? '')
                !== RECIPE_COOKIDOO_CONNECTOR
            || (string)($stored['external_id'] ?? '') !== $externalId
            || strtolower(trim((string)($stored['locale'] ?? '')))
                !== strtolower($request['locale'])
            || (string)($stored['primary_connector'] ?? '')
                !== RECIPE_COOKIDOO_CONNECTOR
            || ($stored['deleted_at'] ?? null) !== null
        ) {
            $terminal[$externalId] = [
                'status' => 'skipped',
                'reason' => 'stale_metadata_target',
            ];
            continue;
        }
        if (
            (string)($stored['metadata_version'] ?? '')
            === RECIPE_COOKIDOO_METADATA_VERSION
            && (string)($stored['metadata_schema_version'] ?? '')
                === RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION
            && (
                ($stored['cache_expires_at'] ?? null) === null
                || (string)$stored['cache_expires_at']
                    >= gmdate('Y-m-d H:i:s')
            )
            && (
                ($stored['stale_at'] ?? null) === null
                || (string)$stored['stale_at']
                    >= gmdate('Y-m-d H:i:s')
            )
        ) {
            $terminal[$externalId] = [
                'status' => 'succeeded',
                'source_ingredient_count' => (int)(
                    $stored['source_ingredient_count'] ?? 0
                ),
            ];
            $epochAdvanceOriginIds[] = (int)$recipe['origin_id'];
            continue;
        }
        if (
            (int)($stored['last_applied_request_epoch'] ?? 0)
                > (int)($job['request_epoch'] ?? 0)
        ) {
            $terminal[$externalId] = [
                'status' => 'skipped',
                'reason' => 'request_epoch_superseded',
            ];
            continue;
        }
        $failureKind = (string)($stored['metadata_failure_kind'] ?? '');
        if (recipeCookidooMetadataFailureBlocks($stored)) {
            $terminal[$externalId] = [
                'status' => 'failed',
                'error_kind' => $failureKind,
                'failure_count' => min(
                    255,
                    max(0, (int)($stored['metadata_failure_count'] ?? 0))
                ),
                'next_probe_at' => $stored['metadata_next_probe_at'] ?? null,
            ];
            $epochAdvanceOriginIds[] = (int)$recipe['origin_id'];
            continue;
        }
        $pending[] = $recipe;
    }
    if ($pending && (
        (string)($job['connector'] ?? '') !== RECIPE_COOKIDOO_CONNECTOR
        || !recipeConnectorIsEnabled($db, RECIPE_COOKIDOO_CONNECTOR)
        || !recipeCookidooBridgeConfigured()
    )) {
        return [
            'status' => 'skipped',
            'result' => ['reason' => 'connector_unavailable'],
        ];
    }
    if (
        $pending
        && recipeConnectorCircuitIsOpen($db, RECIPE_COOKIDOO_CONNECTOR)
    ) {
        return [
            'status' => 'retry',
            'retry_at' => recipeConnectorCircuitOpenUntil(
                $db,
                RECIPE_COOKIDOO_CONNECTOR
            ) ?? date('Y-m-d H:i:s', time() + 900),
            'result' => ['reason' => 'circuit_open'],
        ];
    }

    $updated = [];
    $cursorInvalidationRequired = false;
    $responseBytes = 0;
    $latencyMs = 0;
    $jobCompletedInApply = false;
    $topologyRecipeCount = 0;
    $topologyMetrics = [
        'group_count' => 0,
        'group_title_key_count' => 0,
        'group_title_nonempty_count' => 0,
        'group_title_length_total' => 0,
        'group_title_length_max' => 0,
        'ingredient_count' => 0,
        'ingredient_ref_key_count' => 0,
        'ingredient_ref_nonempty_count' => 0,
        'default_title_key_count' => 0,
        'default_title_nonempty_count' => 0,
        'unit_ref_key_count' => 0,
        'unit_ref_nonempty_count' => 0,
        'optional_key_count' => 0,
        'optional_true_count' => 0,
        'optional_false_count' => 0,
        'optional_null_count' => 0,
        'shopping_category_ref_key_count' => 0,
        'shopping_category_ref_nonempty_count' => 0,
    ];
    if ($pending) {
        $bridgeResult = recipeCookidooBridgeMetadataBatch($db, [
            'locale' => $request['locale'],
            'recipes' => $pending,
        ]);
        $responseBytes = (int)($bridgeResult['response_bytes'] ?? 0);
        $latencyMs = (int)($bridgeResult['latency_ms'] ?? 0);
        $retrievedAt = gmdate('Y-m-d H:i:s');
        $saveLock = recipeCatalogSaveLock();
        try {
            dbBeginImmediateWithRetry($db);
            recipeJobAssertClaimOwned($db, $job);
            if ($epochAdvanceOriginIds) {
                $advanceEpoch = $db->prepare("
                    UPDATE recipe_origins
                    SET last_applied_request_epoch = MAX(
                            last_applied_request_epoch,
                            ?
                        )
                    WHERE id = ?
                      AND last_applied_request_epoch <= ?
                ");
                foreach (
                    array_values(array_unique($epochAdvanceOriginIds))
                    as $originId
                ) {
                    $advanceEpoch->execute([
                        (int)$job['request_epoch'],
                        $originId,
                        (int)$job['request_epoch'],
                    ]);
                }
            }
            $epochCheck = $db->prepare("
                SELECT last_applied_request_epoch
                FROM recipe_origins
                WHERE id = ? AND recipe_id = ?
            ");
            foreach ($pending as $index => $recipe) {
                $outcome = $bridgeResult['outcomes'][$index];
                $externalId = $recipe['external_id'];
                $epochCheck->execute([
                    $recipe['origin_id'],
                    $recipe['recipe_id'],
                ]);
                $lastAppliedEpoch = $epochCheck->fetchColumn();
                if (
                    $lastAppliedEpoch === false
                    || (int)$lastAppliedEpoch
                        > (int)$job['request_epoch']
                ) {
                    $terminal[$externalId] = [
                        'status' => 'skipped',
                        'reason' => $lastAppliedEpoch === false
                            ? 'stale_metadata_target'
                            : 'request_epoch_superseded',
                    ];
                    continue;
                }
                if ($outcome['status'] === 'succeeded') {
                    $recipeTopology = $outcome['recipe'][
                        'topology_metrics'
                    ];
                    $topologyRecipeCount++;
                    foreach ($topologyMetrics as $field => $_value) {
                        if ($field === 'group_title_length_max') {
                            $topologyMetrics[$field] = max(
                                $topologyMetrics[$field],
                                (int)$recipeTopology[$field]
                            );
                            continue;
                        }
                        $topologyMetrics[$field] += (int)$recipeTopology[$field];
                    }
                    $applied = recipeCookidooApplyMetadataV2(
                        $db,
                        $recipe['recipe_id'],
                        $recipe['origin_id'],
                        $outcome['recipe'],
                        $retrievedAt,
                        (int)$job['request_epoch']
                    );
                    $cursorInvalidationRequired = $cursorInvalidationRequired
                        || !empty($applied['visibility_changed']);
                    $updated[] = $applied;
                    $terminal[$externalId] = [
                        'status' => 'succeeded',
                        'source_ingredient_count' => (int)(
                            $applied['source_ingredient_count'] ?? 0
                        ),
                    ];
                    continue;
                }
                $recorded = recipeCookidooRecordMetadataFailure(
                    $db,
                    $recipe['recipe_id'],
                    $recipe['origin_id'],
                    $externalId,
                    $request['locale'],
                    $outcome['error_kind'],
                    (int)$job['request_epoch']
                );
                $terminal[$externalId] = [
                    'status' => 'failed',
                    'error_kind' => $recorded['error_kind'],
                    'failure_count' => $recorded['failure_count'],
                    'next_probe_at' => $recorded['next_probe_at'],
                ];
            }
            if ($cursorInvalidationRequired) {
                recipeScoreInvalidateCursors($db);
            }
            $atomicSucceeded = count(array_filter(
                $terminal,
                static fn(array $item): bool =>
                    ($item['status'] ?? '') === 'succeeded'
            ));
            $atomicFailed = count(array_filter(
                $terminal,
                static fn(array $item): bool =>
                    ($item['status'] ?? '') === 'failed'
            ));
            $atomicSkipped = count(array_filter(
                $terminal,
                static fn(array $item): bool =>
                    ($item['status'] ?? '') === 'skipped'
            ));
            recipeJobCompleteClaimInTransaction(
                $db,
                $job,
                $atomicSkipped === count($request['recipes'])
                    ? 'skipped'
                    : 'done',
                [
                    'reason' => $atomicSkipped
                        === count($request['recipes'])
                        ? 'stale_metadata_targets'
                        : null,
                    'metadata_version' =>
                        RECIPE_COOKIDOO_METADATA_VERSION,
                    'metadata_schema_version' =>
                        RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
                    'succeeded_count' => $atomicSucceeded,
                    'failed_count' => $atomicFailed,
                    'skipped_count' => $atomicSkipped,
                    'response_bytes' => $responseBytes,
                    'latency_ms' => $latencyMs,
                    'topology_metrics' => $topologyMetrics,
                ]
            );
            $db->exec('COMMIT');
            $jobCompletedInApply = true;
        } catch (Throwable $e) {
            if (databaseTransactionIsActive($db)) {
                $db->exec('ROLLBACK');
            }
            throw $e;
        } finally {
            recipeCatalogSaveUnlock($saveLock);
        }
    }
    if (
        !$pending
        && !empty($job['lease_token'])
        && !$jobCompletedInApply
    ) {
        $saveLock = recipeCatalogSaveLock();
        try {
            dbBeginImmediateWithRetry($db);
            recipeJobAssertClaimOwned($db, $job);
            if ($epochAdvanceOriginIds) {
                $advanceEpoch = $db->prepare("
                    UPDATE recipe_origins
                    SET last_applied_request_epoch = MAX(
                            last_applied_request_epoch,
                            ?
                        )
                    WHERE id = ?
                      AND last_applied_request_epoch <= ?
                ");
                foreach (
                    array_values(array_unique($epochAdvanceOriginIds))
                    as $originId
                ) {
                    $advanceEpoch->execute([
                        (int)$job['request_epoch'],
                        $originId,
                        (int)$job['request_epoch'],
                    ]);
                }
            }
            $allTerminalSkipped = count(array_filter(
                $terminal,
                static fn(array $item): bool =>
                    ($item['status'] ?? '') === 'skipped'
            )) === count($request['recipes']);
            recipeJobCompleteClaimInTransaction(
                $db,
                $job,
                $allTerminalSkipped ? 'skipped' : 'done',
                [
                    'reason' => $allTerminalSkipped
                        ? 'stale_metadata_targets'
                        : null,
                    'metadata_version' =>
                        RECIPE_COOKIDOO_METADATA_VERSION,
                    'metadata_schema_version' =>
                        RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
                    'succeeded_count' => count(array_filter(
                        $terminal,
                        static fn(array $item): bool =>
                            ($item['status'] ?? '') === 'succeeded'
                    )),
                    'failed_count' => count(array_filter(
                        $terminal,
                        static fn(array $item): bool =>
                            ($item['status'] ?? '') === 'failed'
                    )),
                    'skipped_count' => count(array_filter(
                        $terminal,
                        static fn(array $item): bool =>
                            ($item['status'] ?? '') === 'skipped'
                    )),
                    'skipped_external_ids' => array_values(
                        array_keys(array_filter(
                            $terminal,
                            static fn(array $item): bool =>
                                ($item['status'] ?? '') === 'skipped'
                        ))
                    ),
                ]
            );
            $db->exec('COMMIT');
            $jobCompletedInApply = true;
        } catch (Throwable $error) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $error;
        } finally {
            recipeCatalogSaveUnlock($saveLock);
        }
    }

    $succeededRecipeIds = [];
    $succeededExternalIds = [];
    $failedRecipeIds = [];
    $failedExternalIds = [];
    $failures = [];
    $skippedRecipeIds = [];
    $skippedExternalIds = [];
    $skippedTargets = [];
    $sourceIngredientCounts = [];
    foreach ($request['recipes'] as $recipe) {
        $externalId = $recipe['external_id'];
        $outcome = $terminal[$externalId] ?? null;
        if (!is_array($outcome)) {
            throw new RuntimeException(
                'metadata refresh outcome is incomplete'
            );
        }
        if ($outcome['status'] === 'succeeded') {
            $succeededRecipeIds[] = $recipe['recipe_id'];
            $succeededExternalIds[] = $externalId;
            $sourceIngredientCounts[$recipe['recipe_id']] = (int)(
                $outcome['source_ingredient_count'] ?? 0
            );
            continue;
        }
        if ($outcome['status'] === 'skipped') {
            $skippedRecipeIds[] = $recipe['recipe_id'];
            $skippedExternalIds[] = $externalId;
            $skippedTargets[] = [
                'recipe_id' => $recipe['recipe_id'],
                'origin_id' => $recipe['origin_id'],
                'external_id' => $externalId,
                'reason' => (string)($outcome['reason']
                    ?? 'stale_metadata_target'),
            ];
            continue;
        }
        $failedRecipeIds[] = $recipe['recipe_id'];
        $failedExternalIds[] = $externalId;
        $failures[] = [
            'recipe_id' => $recipe['recipe_id'],
            'origin_id' => $recipe['origin_id'],
            'external_id' => $externalId,
            'error_kind' => (string)$outcome['error_kind'],
            'failure_count' => min(
                255,
                max(0, (int)($outcome['failure_count'] ?? 0))
            ),
            'next_probe_at' => $outcome['next_probe_at'] ?? null,
        ];
    }
    $sourceGroupCounts = [];
    $distinctUnits = [];
    $nullQuantityCount = 0;
    $rangeQuantityCount = 0;
    $mappingVersionCounts = [];
    if ($succeededRecipeIds) {
        $placeholders = implode(
            ',',
            array_fill(0, count($succeededRecipeIds), '?')
        );
        $sourceRows = $db->prepare("
            SELECT recipe_id, COALESCE(source_group_index, 0)
                       AS source_group_index,
                   source_quantity, source_quantity_max,
                   substr(source_unit, 1, 80) AS source_unit,
                   substr(COALESCE(mapping_version, ''), 1, 40)
                       AS mapping_version
            FROM recipe_source_ingredients
            WHERE recipe_id IN ({$placeholders})
            ORDER BY recipe_id, source_group_index,
                     COALESCE(source_group_position, position), position
        ");
        $sourceRows->execute($succeededRecipeIds);
        $groupSets = [];
        foreach ($sourceRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recipeId = (int)$row['recipe_id'];
            $groupSets[$recipeId][(int)$row['source_group_index']] = true;
            if ($row['source_quantity'] === null) {
                $nullQuantityCount++;
            }
            if ($row['source_quantity_max'] !== null) {
                $rangeQuantityCount++;
            }
            $unit = trim((string)($row['source_unit'] ?? ''));
            if ($unit !== '') {
                $distinctUnits[$unit] = true;
            }
            $mappingVersion = trim((string)($row['mapping_version'] ?? ''))
                ?: 'unversioned';
            $mappingVersionCounts[$mappingVersion] =
                ($mappingVersionCounts[$mappingVersion] ?? 0) + 1;
        }
        foreach ($succeededRecipeIds as $recipeId) {
            $sourceGroupCounts[$recipeId] = count(
                $groupSets[$recipeId] ?? []
            );
        }
    }
    $distinctUnitStrings = array_keys($distinctUnits);
    natcasesort($distinctUnitStrings);
    $distinctUnitStrings = array_values($distinctUnitStrings);
    $distinctUnitsTruncated = count($distinctUnitStrings) > 100;
    $distinctUnitStrings = array_slice($distinctUnitStrings, 0, 100);
    ksort($mappingVersionCounts, SORT_STRING);
    $failureKindCounts = [];
    foreach ($failures as $failure) {
        $kind = (string)$failure['error_kind'];
        $failureKindCounts[$kind] = ($failureKindCounts[$kind] ?? 0) + 1;
    }
    ksort($failureKindCounts, SORT_STRING);
    $revisionAfter = recipeScoreState($db);
    $expectedCatalogDelta = 0;
    $actualCatalogDelta = (int)$revisionAfter['catalog_revision']
        - (int)$revisionBefore['catalog_revision'];
    $expectedCursorDelta = $cursorInvalidationRequired ? 1 : 0;
    $actualCursorDelta = (int)$revisionAfter['cursor_revision']
        - (int)$revisionBefore['cursor_revision'];
    $revisionInvariants = [
        'inventory_unchanged' =>
            (int)$revisionAfter['inventory_revision']
                === (int)$revisionBefore['inventory_revision'],
        'catalog_unchanged' =>
            (int)$revisionAfter['catalog_revision']
                === (int)$revisionBefore['catalog_revision'],
        'catalog_expected_delta' => $expectedCatalogDelta,
        'catalog_actual_delta' => $actualCatalogDelta,
        'ranking_unchanged' =>
            $revisionAfter['active_score_revision_id']
                === $revisionBefore['active_score_revision_id'],
        'cursor_expected_delta' => $expectedCursorDelta,
        'cursor_actual_delta' => $actualCursorDelta,
    ];
    $revisionInvariants['preserved'] =
        $revisionInvariants['inventory_unchanged']
        && $actualCatalogDelta === $expectedCatalogDelta
        && $revisionInvariants['ranking_unchanged']
        && $actualCursorDelta === $expectedCursorDelta;
    $topologyRate = static function (
        int $numerator,
        int $denominator
    ): ?float {
        return $denominator > 0
            ? round($numerator / $denominator, 6)
            : null;
    };
    $topologyRates = [
        'group_title_key_rate' => $topologyRate(
            $topologyMetrics['group_title_key_count'],
            $topologyMetrics['group_count']
        ),
        'group_title_nonempty_rate' => $topologyRate(
            $topologyMetrics['group_title_nonempty_count'],
            $topologyMetrics['group_count']
        ),
        'group_title_average_length' => $topologyRate(
            $topologyMetrics['group_title_length_total'],
            $topologyMetrics['group_title_nonempty_count']
        ),
        'ingredient_ref_key_rate' => $topologyRate(
            $topologyMetrics['ingredient_ref_key_count'],
            $topologyMetrics['ingredient_count']
        ),
        'ingredient_ref_nonempty_rate' => $topologyRate(
            $topologyMetrics['ingredient_ref_nonempty_count'],
            $topologyMetrics['ingredient_count']
        ),
        'default_title_key_rate' => $topologyRate(
            $topologyMetrics['default_title_key_count'],
            $topologyMetrics['ingredient_count']
        ),
        'default_title_nonempty_rate' => $topologyRate(
            $topologyMetrics['default_title_nonempty_count'],
            $topologyMetrics['ingredient_count']
        ),
        'unit_ref_key_rate' => $topologyRate(
            $topologyMetrics['unit_ref_key_count'],
            $topologyMetrics['ingredient_count']
        ),
        'unit_ref_nonempty_rate' => $topologyRate(
            $topologyMetrics['unit_ref_nonempty_count'],
            $topologyMetrics['ingredient_count']
        ),
        'optional_key_rate' => $topologyRate(
            $topologyMetrics['optional_key_count'],
            $topologyMetrics['ingredient_count']
        ),
        'optional_true_rate' => $topologyRate(
            $topologyMetrics['optional_true_count'],
            $topologyMetrics['ingredient_count']
        ),
        'optional_false_rate' => $topologyRate(
            $topologyMetrics['optional_false_count'],
            $topologyMetrics['ingredient_count']
        ),
        'optional_null_rate' => $topologyRate(
            $topologyMetrics['optional_null_count'],
            $topologyMetrics['ingredient_count']
        ),
        'shopping_category_ref_key_rate' => $topologyRate(
            $topologyMetrics['shopping_category_ref_key_count'],
            $topologyMetrics['ingredient_count']
        ),
        'shopping_category_ref_nonempty_rate' => $topologyRate(
            $topologyMetrics['shopping_category_ref_nonempty_count'],
            $topologyMetrics['ingredient_count']
        ),
    ];
    $allSkipped = count($skippedTargets) === count($request['recipes']);
    return [
        'status' => $allSkipped ? 'skipped' : 'done',
        'job_completed' => $jobCompletedInApply,
        'result' => [
            'reason' => $allSkipped ? 'stale_metadata_targets' : null,
            'metadata_version' => RECIPE_COOKIDOO_METADATA_VERSION,
            'metadata_schema_version' =>
                RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
            'mapping_version' => recipeIngredientActiveMappingVersion(),
            'failure_schema_version' =>
                RECIPE_COOKIDOO_METADATA_FAILURE_SCHEMA_VERSION,
            'locale' => $request['locale'],
            'succeeded_count' => count($succeededRecipeIds),
            'failed_count' => count($failedRecipeIds),
            'succeeded_recipe_ids' => $succeededRecipeIds,
            'succeeded_external_ids' => $succeededExternalIds,
            'failed_recipe_ids' => $failedRecipeIds,
            'failed_external_ids' => $failedExternalIds,
            'failures' => $failures,
            'skipped_count' => count($skippedTargets),
            'skipped_recipe_ids' => $skippedRecipeIds,
            'skipped_external_ids' => $skippedExternalIds,
            'skipped_targets' => $skippedTargets,
            'updated_count' => count($updated),
            'updated_recipe_ids' => array_column($updated, 'recipe_id'),
            'source_ingredient_counts' => $sourceIngredientCounts,
            'source_group_counts' => $sourceGroupCounts,
            'distinct_unit_strings' => $distinctUnitStrings,
            'distinct_unit_strings_truncated' => $distinctUnitsTruncated,
            'null_quantity_count' => $nullQuantityCount,
            'range_quantity_count' => $rangeQuantityCount,
            'mapping_version_counts' => $mappingVersionCounts,
            'topology_recipe_count' => $topologyRecipeCount,
            'topology_metrics' => $topologyMetrics,
            'topology_rates' => $topologyRates,
            'response_bytes' => $responseBytes,
            'latency_ms' => $latencyMs,
            'failure_kind_counts' => $failureKindCounts,
            'revision_invariants' => $revisionInvariants,
        ],
    ];
}

function recipeJobDispatchInventoryChanged(PDO $db, array $job, array $payload): array {
    $reason = (string)($payload['reason'] ?? '');
    $result = [
        'ranking' => 'score_rebuild_queued',
        'inventory_revision' => (int)(
            $payload['inventory_revision']
            ?? recipeScoreState($db)['inventory_revision']
        ),
        'product_id' => $job['product_id'],
        'reason' => $reason,
    ];
    if ($reason === 'inventory_add' && !empty($job['product_id'])) {
        $result['remote_discovery'] = recipeCookidooAutoDiscoverProduct(
            $db,
            (int)$job['product_id'],
            $reason
        );
    }
    return ['status' => 'done', 'result' => $result];
}

function recipeJobDispatchTaxonomyReady(PDO $db, array $job, array $payload): array {
    $productId = (int)($job['product_id'] ?? 0);
    $activeScore = recipeScoreActiveRevision($db);
    $activeUsesOntologyV3 = $activeScore !== null
        && (string)($activeScore['scoring_model'] ?? '')
            === 'faceted-ontology-v3'
        && $activeScore['ontology_version_id'] !== null;
    $remapped = $activeUsesOntologyV3
        ? 0
        : recipeCatalogRefreshUnresolvedMappings($db);
    return [
        'status' => 'done',
        'result' => [
            'remapped_ingredients' => $remapped,
            'legacy_mapping_backfill' => $activeUsesOntologyV3
                ? [
                    'status' => 'skipped',
                    'reason' =>
                        'active_v3_uses_sealed_recipe_mappings',
                ]
                : [
                    'status' => 'processed',
                    'reason' => '',
                ],
            'ranking' => 'score_rebuild_queued',
            'inventory_revision' => $productId > 0
                ? recipeScoreMarkProductDirty(
                    $db,
                    $productId,
                    'taxonomy_ready'
                )
                : recipeScoreMarkDirty($db),
            'product_id' => $productId,
            'remote_discovery' => $productId > 0
                ? recipeCookidooAutoDiscoverProduct(
                    $db,
                    $productId,
                    (string)($payload['reason'] ?? 'taxonomy_ready')
                )
                : ['queued' => 0, 'skipped' => 0, 'reason' => 'missing_product'],
        ],
    ];
}

function recipeJobDispatchConnectorDiscovery(PDO $db, array $job, array $payload): array {
    $connector = trim((string)($job['connector'] ?? ''));
    if (!recipeConnectorExists($connector)) {
        throw new InvalidArgumentException('Unknown connector: ' . $connector);
    }
    if ($connector !== RECIPE_COOKIDOO_CONNECTOR) {
        return ['status' => 'skipped', 'result' => ['reason' => 'unsupported_connector']];
    }
    if (!recipeCookidooDetailHydrationPolicyAllows()) {
        return [
            'status' => 'skipped',
            'result' => [
                'reason' => RECIPE_COOKIDOO_DETAIL_POLICY_REASON,
                'policy_version' =>
                    RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
            ],
        ];
    }
    if (!recipeConnectorIsEnabled($db, $connector)) {
        return ['status' => 'skipped', 'result' => ['reason' => 'connector_disabled']];
    }
    if (recipeConnectorCircuitIsOpen($db, $connector)) {
        return [
            'status' => 'retry',
            'retry_at' => recipeConnectorCircuitOpenUntil($db, $connector)
                ?? date('Y-m-d H:i:s', time() + 900),
            'result' => ['reason' => 'circuit_open'],
        ];
    }
    return recipeCookidooDispatchDiscovery($db, $job, $payload);
}

function recipeJobDispatchRefresh(PDO $db, array $job, array $payload): array {
    $connector = (string)($job['connector'] ?? 'local');
    if (!recipeConnectorExists($connector)) {
        throw new InvalidArgumentException('Unknown connector: ' . $connector);
    }
    if (!empty(recipeConnectorRegistry()[$connector]['network'])) {
        return ['status' => 'skipped', 'result' => ['reason' => 'network_connector_deferred']];
    }
    $recipeId = (int)($payload['recipe_id'] ?? 0);
    if ($recipeId > 0) {
        recipeSearchRebuildDocument($db, $recipeId);
        return ['status' => 'done', 'result' => ['rebuilt_recipe_id' => $recipeId]];
    }
    return ['status' => 'done', 'result' => ['rebuilt_documents' => recipeSearchRebuildAll($db)]];
}

function recipeJobLeaseSeconds(): int {
    $minutes = max(
        1,
        min(60, (int)env('RECIPE_QUEUE_LEASE_MINUTES', '15'))
    );
    $providerMinimum = function_exists(
        'recipeCookidooBridgeTimeoutSeconds'
    ) ? recipeCookidooBridgeTimeoutSeconds() + 30 : 150;
    return min(3600, max($minutes * 60, $providerMinimum));
}

function recipeJobScopeFromRow(array $row): array {
    return [
        'scope' => $row['scope'] ?? null,
        'connector' => $row['connector'] ?? null,
        'ingredient_id' => $row['ingredient_id'] ?? null,
        'product_id' => $row['product_id'] ?? null,
        'query' => $row['query'] ?? null,
    ];
}

function recipeJobHashFromRow(array $row): string {
    $payload = isset($row['payload']) && is_array($row['payload'])
        ? $row['payload']
        : json_decode((string)($row['payload_json'] ?? ''), true);
    return recipeJobRequestHash(
        (string)$row['job_type'],
        recipeJobScopeFromRow($row),
        is_array($payload) ? $payload : []
    );
}

function recipeJobClaimBatch(
    PDO $db,
    int $limit,
    int $maxAttempts,
    bool $allowCookidoo,
    ?int $leaseSeconds = null
): array {
    if (databaseTransactionIsActive($db)) {
        throw new RuntimeException(
            'recipe jobs cannot be claimed inside a caller transaction'
        );
    }
    $leaseSeconds = max(
        recipeJobLeaseSeconds(),
        min(21600, (int)($leaseSeconds ?? 0))
    );
    dbBeginImmediateWithRetry($db);
    try {
        $reclaim = $db->prepare("
            UPDATE recipe_jobs
            SET status = CASE
                    WHEN attempts >= MIN(
                        max_attempts,
                        CAST(? AS INTEGER)
                    )
                    THEN 'failed' ELSE 'retry'
                END,
                next_retry_at = CASE
                    WHEN attempts >= MIN(
                        max_attempts,
                        CAST(? AS INTEGER)
                    )
                    THEN NULL ELSE CURRENT_TIMESTAMP
                END,
                last_error = CASE
                    WHEN attempts >= MIN(
                        max_attempts,
                        CAST(? AS INTEGER)
                    )
                    THEN 'lease_exhausted'
                    ELSE 'processing lease expired'
                END,
                lease_token = NULL,
                lease_expires_at = NULL,
                started_at = NULL,
                finished_at = CASE
                    WHEN attempts >= MIN(
                        max_attempts,
                        CAST(? AS INTEGER)
                    )
                    THEN CURRENT_TIMESTAMP ELSE NULL
                END,
                updated_at = CURRENT_TIMESTAMP
            WHERE status = 'in_progress'
              AND lease_expires_at IS NOT NULL
              AND lease_expires_at <= CURRENT_TIMESTAMP
        ");
        $reclaim->execute([
            $maxAttempts,
            $maxAttempts,
            $maxAttempts,
            $maxAttempts,
        ]);
        $providerAllowed =
            recipeCookidooDetailHydrationPolicyAllows()
            && $allowCookidoo;
        $providerWhere = $providerAllowed
            ? ''
            : "AND (connector IS NULL OR connector <> 'cookidoo')";
        $fetch = static function (
            string $priorityWhere,
            int $rowLimit,
            array $excludeIds = []
        ) use (
            $db,
            $maxAttempts,
            $providerWhere
        ): array {
            if ($rowLimit <= 0) {
                return [];
            }
            $excludeWhere = '';
            $params = [$maxAttempts];
            if ($excludeIds) {
                $excludeWhere = 'AND id NOT IN ('
                    . implode(
                        ',',
                        array_fill(0, count($excludeIds), '?')
                    )
                    . ')';
                $params = array_merge($params, $excludeIds);
            }
            $stmt = $db->prepare("
                SELECT *
                FROM recipe_jobs
                WHERE status IN ('pending', 'retry')
                  AND attempts < MIN(
                      max_attempts,
                      CAST(? AS INTEGER)
                  )
                  AND (
                      next_retry_at IS NULL
                      OR next_retry_at <= CURRENT_TIMESTAMP
                  )
                  {$priorityWhere}
                  {$excludeWhere}
                  {$providerWhere}
                ORDER BY priority DESC, created_at ASC, id ASC
                LIMIT {$rowLimit}
            ");
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };
        $rows = $fetch('AND priority >= 100', min(1, $limit));
        $rows = array_merge(
            $rows,
            $fetch(
                'AND priority < 100',
                $limit - count($rows)
            )
        );
        if (count($rows) < $limit) {
            $rows = array_merge(
                $rows,
                $fetch(
                    'AND priority >= 100',
                    $limit - count($rows),
                    array_map(
                        static fn(array $row): int =>
                            (int)$row['id'],
                        $rows
                    )
                )
            );
        }
        $claims = [];
        foreach ($rows as $row) {
            $requestHash = trim((string)($row['request_hash'] ?? ''));
            if ($requestHash === '') {
                $requestHash = recipeJobHashFromRow($row);
            }
            $leaseToken = bin2hex(random_bytes(32));
            $claim = $db->prepare("
                UPDATE recipe_jobs
                SET status = 'in_progress',
                    attempts = attempts + 1,
                    next_retry_at = NULL,
                    last_error = '',
                    request_hash = ?,
                    lease_token = ?,
                    lease_generation = lease_generation + 1,
                    lease_expires_at = datetime(
                        'now',
                        '+' || ? || ' seconds'
                    ),
                    started_at = CURRENT_TIMESTAMP,
                    finished_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND request_generation = ?
                  AND request_epoch = ?
                  AND status IN ('pending', 'retry')
                  AND attempts < MIN(
                      max_attempts,
                      CAST(? AS INTEGER)
                  )
                  AND (
                      next_retry_at IS NULL
                      OR next_retry_at <= CURRENT_TIMESTAMP
                  )
            ");
            $claim->execute([
                $requestHash,
                $leaseToken,
                $leaseSeconds,
                (int)$row['id'],
                (int)($row['request_generation'] ?? 1),
                (int)($row['request_epoch'] ?? 0),
                $maxAttempts,
            ]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            $read = $db->prepare("
                SELECT *
                FROM recipe_jobs
                WHERE id = ? AND lease_token = ?
            ");
            $read->execute([(int)$row['id'], $leaseToken]);
            $claimed = $read->fetch(PDO::FETCH_ASSOC);
            if ($claimed) {
                $claims[] = recipeJobDecodeInternalRow($claimed);
            }
        }
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
    foreach ($claims as $claim) {
        recipeJobTestHook('after_claim', [
            'id' => (int)$claim['id'],
            'request_epoch' => (int)$claim['request_epoch'],
            'lease_generation' => (int)$claim['lease_generation'],
        ]);
    }
    return $claims;
}

function recipeJobAssertProviderIoSafe(
    PDO $db,
    array $job
): void {
    if (databaseTransactionIsActive($db)) {
        throw new RuntimeException(
            'provider I/O cannot run inside a SQLite transaction'
        );
    }
    $legacyFlockHeld = recipeJobLegacyQueueFlockHeld();
    if ($legacyFlockHeld) {
        throw new RuntimeException(
            'provider I/O cannot run while a legacy recipe queue flock is held'
        );
    }
    recipeJobTestHook('before_provider_io', [
        'id' => (int)($job['id'] ?? 0),
        'sqlite_transaction' => databaseTransactionIsActive($db),
        'legacy_queue_flock_held' => $legacyFlockHeld,
    ]);
}

function recipeJobAssertClaimOwned(
    PDO $db,
    array $claim
): array {
    if (!databaseTransactionIsActive($db)) {
        throw new RuntimeException(
            'recipe job fence validation requires a transaction'
        );
    }
    $stmt = $db->prepare("
        SELECT *,
               CASE
                   WHEN lease_expires_at > CURRENT_TIMESTAMP
                   THEN 1 ELSE 0
               END AS lease_valid
        FROM recipe_jobs
        WHERE id = ?
    ");
    $stmt->execute([(int)$claim['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $owned = $row !== null
        && (string)$row['status'] === 'in_progress'
        && (int)$row['request_epoch']
            === (int)$claim['request_epoch']
        && (int)$row['request_generation']
            === (int)$claim['request_generation']
        && hash_equals(
            (string)$row['request_hash'],
            (string)$claim['request_hash']
        )
        && hash_equals(
            (string)$row['lease_token'],
            (string)$claim['lease_token']
        )
        && (int)$row['lease_generation']
            === (int)$claim['lease_generation']
        && (int)$row['lease_valid'] === 1
        && hash_equals(
            recipeJobHashFromRow($row),
            (string)$claim['request_hash']
        );
    if (!$owned) {
        throw new RecipeJobFenceException(
            'recipe_job_lease_fence_lost'
        );
    }
    return $row;
}

function recipeJobConnectorOutcomeInTransaction(
    PDO $db,
    array $claim,
    bool $success,
    string $error = '',
    bool $forceCircuitBreak = false
): void {
    $connector = trim((string)($claim['connector'] ?? ''));
    if ($connector === '') {
        return;
    }
    if ($success) {
        $stmt = $db->prepare("
            UPDATE recipe_connector_state
            SET last_success_at = CURRENT_TIMESTAMP,
                last_error = '',
                failure_count = 0,
                circuit_open_until = NULL,
                last_outcome_request_epoch = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE connector = ?
              AND last_outcome_request_epoch <= ?
        ");
        $stmt->execute([
            (int)$claim['request_epoch'],
            $connector,
            (int)$claim['request_epoch'],
        ]);
        return;
    }
    $stmt = $db->prepare("
        UPDATE recipe_connector_state
        SET last_error = ?,
            failure_count = CASE
                WHEN CAST(? AS INTEGER) = 1
                THEN MAX(failure_count, 3)
                ELSE failure_count + 1
            END,
            circuit_open_until = CASE
                WHEN CAST(? AS INTEGER) = 1
                THEN datetime('now', '+12 hours')
                WHEN failure_count + 1 >= 3
                THEN datetime('now', '+15 minutes')
                ELSE circuit_open_until
            END,
            last_outcome_request_epoch = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE connector = ?
          AND last_outcome_request_epoch <= ?
    ");
    $stmt->execute([
        mb_substr($error, 0, 1000, 'UTF-8'),
        $forceCircuitBreak ? 1 : 0,
        $forceCircuitBreak ? 1 : 0,
        (int)$claim['request_epoch'],
        $connector,
        (int)$claim['request_epoch'],
    ]);
}

function recipeJobCompleteClaimInTransaction(
    PDO $db,
    array $claim,
    string $status,
    array $result
): void {
    recipeJobAssertClaimOwned($db, $claim);
    $storedStatus = $status === 'skipped' ? 'skipped' : 'done';
    $stmt = $db->prepare("
        UPDATE recipe_jobs
        SET status = ?,
            last_result_json = ?,
            last_error = '',
            next_retry_at = NULL,
            lease_token = NULL,
            lease_expires_at = NULL,
            finished_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND status = 'in_progress'
          AND request_epoch = ?
          AND request_generation = ?
          AND request_hash = ?
          AND lease_token = ?
          AND lease_generation = ?
          AND lease_expires_at > CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $storedStatus,
        recipeCatalogJsonEncode($result),
        (int)$claim['id'],
        (int)$claim['request_epoch'],
        (int)$claim['request_generation'],
        (string)$claim['request_hash'],
        (string)$claim['lease_token'],
        (int)$claim['lease_generation'],
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RecipeJobFenceException(
            'recipe_job_completion_fence_lost'
        );
    }
    if ($storedStatus === 'done') {
        recipeJobConnectorOutcomeInTransaction(
            $db,
            $claim,
            true
        );
    }
}

function recipeJobFinishLocalOutcome(
    PDO $db,
    array $claim,
    array $outcome
): string {
    if (databaseTransactionIsActive($db)) {
        throw new RuntimeException(
            'recipe outcome completion cannot inherit a transaction'
        );
    }
    $status = (string)($outcome['status'] ?? 'done');
    dbBeginImmediateWithRetry($db);
    try {
        if ($status === 'retry') {
            recipeJobAssertClaimOwned($db, $claim);
            $stmt = $db->prepare("
                UPDATE recipe_jobs
                SET status = 'retry',
                    attempts = MAX(0, attempts - 1),
                    next_retry_at = ?,
                    last_result_json = ?,
                    last_error = '',
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    started_at = NULL,
                    finished_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND request_epoch = ?
                  AND request_generation = ?
                  AND request_hash = ?
                  AND lease_token = ?
                  AND lease_generation = ?
                  AND lease_expires_at > CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                $outcome['retry_at']
                    ?? gmdate('Y-m-d H:i:s', time() + 900),
                recipeCatalogJsonEncode($outcome['result'] ?? []),
                (int)$claim['id'],
                (int)$claim['request_epoch'],
                (int)$claim['request_generation'],
                (string)$claim['request_hash'],
                (string)$claim['lease_token'],
                (int)$claim['lease_generation'],
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RecipeJobFenceException(
                    'recipe_job_retry_fence_lost'
                );
            }
            $db->exec('COMMIT');
            return 'retry';
        }
        recipeJobCompleteClaimInTransaction(
            $db,
            $claim,
            $status,
            $outcome['result'] ?? []
        );
        $db->exec('COMMIT');
        return $status === 'skipped' ? 'skipped' : 'done';
    } catch (Throwable $error) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
}

function recipeJobFailureRetrySeconds(
    Throwable $error,
    int $attempts,
    bool $forceCircuitBreak = false
): int {
    if (databaseIsLockError($error)) {
        return min(5, 2 ** max(0, $attempts - 1));
    }
    if ($forceCircuitBreak) {
        return 900;
    }
    return min(3600, 30 * (2 ** max(0, $attempts - 1)));
}

function recipeJobReleaseClaim(
    PDO $db,
    array $claim,
    Throwable $error,
    int $maxAttempts
): string {
    if (databaseTransactionIsActive($db)) {
        throw new RuntimeException(
            'recipe failure release cannot inherit a transaction'
        );
    }
    dbBeginImmediateWithRetry($db);
    try {
        recipeJobAssertClaimOwned($db, $claim);
        $canRetry = (int)$claim['attempts']
            < min((int)$claim['max_attempts'], $maxAttempts);
        $forceCircuitBreak =
            $error instanceof RecipeCookidooCircuitBreakException;
        $retrySeconds = recipeJobFailureRetrySeconds(
            $error,
            (int)$claim['attempts'],
            $forceCircuitBreak
        );
        $status = $canRetry ? 'retry' : 'failed';
        $stmt = $db->prepare("
            UPDATE recipe_jobs
            SET status = ?,
                next_retry_at = CASE
                    WHEN CAST(? AS INTEGER) = 1
                    THEN datetime('now', '+' || ? || ' seconds')
                    ELSE NULL
                END,
                last_error = ?,
                lease_token = NULL,
                lease_expires_at = NULL,
                started_at = NULL,
                finished_at = CASE
                    WHEN CAST(? AS INTEGER) = 1
                    THEN NULL ELSE CURRENT_TIMESTAMP
                END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND request_epoch = ?
              AND request_generation = ?
              AND request_hash = ?
              AND lease_token = ?
              AND lease_generation = ?
              AND lease_expires_at > CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $status,
            $canRetry ? 1 : 0,
            $retrySeconds,
            mb_substr($error->getMessage(), 0, 1000, 'UTF-8'),
            $canRetry ? 1 : 0,
            (int)$claim['id'],
            (int)$claim['request_epoch'],
            (int)$claim['request_generation'],
            (string)$claim['request_hash'],
            (string)$claim['lease_token'],
            (int)$claim['lease_generation'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RecipeJobFenceException(
                'recipe_job_failure_fence_lost'
            );
        }

        recipeJobConnectorOutcomeInTransaction(
            $db,
            $claim,
            false,
            $error->getMessage(),
            $forceCircuitBreak
        );
        $db->exec('COMMIT');
        return $status;
    } catch (Throwable $releaseError) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $releaseError;
    }
}

function recipeJobProcessQueue(
    PDO $db,
    int $limit = 10,
    int $maxAttempts = 3,
    bool $allowCookidoo = true
): array {
    $limit = max(0, min(100, $limit));
    return recipeJobProcessQueueBatch(
        $db,
        $limit,
        $maxAttempts,
        $allowCookidoo
    );
}

function recipeJobProcessQueueBatch(
    PDO $db,
    int $limit,
    int $maxAttempts,
    bool $allowCookidoo = true
): array {
    $limit = max(0, min(100, $limit));
    $maxAttempts = max(1, $maxAttempts);
    $summary = [
        'processed' => 0,
        'succeeded' => 0,
        'failed' => 0,
        'skipped' => 0,
        'superseded' => 0,
        'items' => [],
        'worker_lease_acquired' => false,
        'worker_lease_released' => false,
        'worker_skipped' => false,
        'worker_lease_generation' => 0,
        'worker_lease_expires_at' => null,
    ];
    $workerLease = recipeJobWorkerLeaseAcquire($db, $limit);
    if ($workerLease === null) {
        $active = $db->query("
            SELECT lease_generation, lease_expires_at
            FROM recipe_worker_leases
            WHERE lease_name = 'queue'
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
        $summary['worker_skipped'] = true;
        $summary['worker_skip_reason'] = 'worker_lease_active';
        $summary['worker_lease_generation'] =
            (int)($active['lease_generation'] ?? 0);
        $summary['worker_lease_expires_at'] =
            $active['lease_expires_at'] ?? null;
        return $summary;
    }
    $summary['worker_lease_acquired'] = true;
    $summary['worker_lease_generation'] =
        (int)$workerLease['lease_generation'];
    $summary['worker_lease_expires_at'] =
        $workerLease['lease_expires_at'];
    try {
        $claims = recipeJobClaimBatch(
            $db,
            $limit,
            $maxAttempts,
            $allowCookidoo,
            (int)$workerLease['lease_seconds']
        );
        foreach ($claims as $index => $job) {
            if (!recipeJobWorkerLeaseRenew(
                $db,
                $workerLease,
                count($claims) - $index
            )) {
                $summary['worker_lease_lost'] = true;
                $summary['worker_skip_reason'] =
                    'worker_lease_lost';
                break;
            }
            $summary['worker_lease_expires_at'] =
                $workerLease['lease_expires_at'];
            $summary['processed']++;
            try {
                $outcome = recipeJobDispatch($db, $job);
                $status = !empty($outcome['job_completed'])
                    ? (string)($outcome['status'] ?? 'done')
                    : recipeJobFinishLocalOutcome(
                        $db,
                        $job,
                        $outcome
                    );
                if ($status === 'retry') {
                    $summary['skipped']++;
                } elseif ($status === 'skipped') {
                    $summary['skipped']++;
                } else {
                    $summary['succeeded']++;
                }
                $summary['items'][] = [
                    'id' => (int)$job['id'],
                    'job_type' => (string)$job['job_type'],
                    'status' => $status,
                    'result' => $outcome['result'] ?? [],
                ];
            } catch (RecipeJobFenceException $fence) {
                $summary['superseded']++;
                $summary['items'][] = [
                    'id' => (int)$job['id'],
                    'job_type' => (string)$job['job_type'],
                    'status' => 'superseded',
                    'error' => $fence->getMessage(),
                ];
            } catch (Throwable $error) {
                $published = recipeJobGet($db, (int)$job['id']);
                if (
                    $published !== null
                    && in_array(
                        (string)$published['status'],
                        ['done', 'skipped'],
                        true
                    )
                    && (int)$published['request_epoch']
                        === (int)$job['request_epoch']
                    && (int)$published['request_generation']
                        === (int)$job['request_generation']
                ) {
                    $status = (string)$published['status'];
                    $summary[
                        $status === 'done'
                            ? 'succeeded'
                            : 'skipped'
                    ]++;
                    $summary['items'][] = [
                        'id' => (int)$job['id'],
                        'job_type' => (string)$job['job_type'],
                        'status' => $status,
                        'result' => $published['result'] ?? [],
                        'post_completion_warning' => mb_substr(
                            $error->getMessage(),
                            0,
                            300,
                            'UTF-8'
                        ),
                    ];
                    continue;
                }
                try {
                    $status = recipeJobReleaseClaim(
                        $db,
                        $job,
                        $error,
                        $maxAttempts
                    );
                } catch (RecipeJobFenceException $fence) {
                    $summary['superseded']++;
                    $summary['items'][] = [
                        'id' => (int)$job['id'],
                        'job_type' =>
                            (string)$job['job_type'],
                        'status' => 'superseded',
                        'error' => $fence->getMessage(),
                    ];
                    continue;
                }
                if ($status === 'failed') {
                    $summary['failed']++;
                } else {
                    $summary['skipped']++;
                }
                $summary['items'][] = [
                    'id' => (int)$job['id'],
                    'job_type' => (string)$job['job_type'],
                    'status' => $status,
                    'error' => mb_substr(
                        $error->getMessage(),
                        0,
                        1000,
                        'UTF-8'
                    ),
                ];
            }
        }
    } finally {
        $summary['worker_lease_released'] =
            recipeJobWorkerLeaseRelease($db, $workerLease);
    }
    return $summary;
}
