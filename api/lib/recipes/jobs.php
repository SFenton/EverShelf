<?php

function recipeJobStableScope(array $scope): array {
    return recipeCatalogStableValue([
        'scope' => $scope['scope'] ?? null,
        'connector' => $scope['connector'] ?? null,
        'ingredient_id' => isset($scope['ingredient_id']) ? (int)$scope['ingredient_id'] : null,
        'product_id' => isset($scope['product_id']) ? (int)$scope['product_id'] : null,
        'query' => $scope['query'] ?? null,
    ]);
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
    $stmt = $db->prepare("
        INSERT INTO recipe_jobs (
            idempotency_key, job_type, priority, scope, connector, ingredient_id, product_id,
            query, payload_json, status, max_attempts
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
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
            started_at = NULL,
            finished_at = NULL,
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $idempotencyKey,
        $jobType,
        max(0, $priority),
        isset($scope['scope']) ? (string)$scope['scope'] : null,
        isset($scope['connector']) ? (string)$scope['connector'] : null,
        isset($scope['ingredient_id']) ? (int)$scope['ingredient_id'] : null,
        isset($scope['product_id']) ? (int)$scope['product_id'] : null,
        isset($scope['query']) ? (string)$scope['query'] : null,
        recipeCatalogJsonEncode($payload),
        max(1, $maxAttempts),
    ]);
    $read = $db->prepare("SELECT * FROM recipe_jobs WHERE idempotency_key = ?");
    $read->execute([$idempotencyKey]);
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Recipe job could not be read after enqueue');
    }
    return recipeJobDecodeRow($row);
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
    $stmt = $db->prepare("
        INSERT INTO recipe_jobs (
            idempotency_key, job_type, priority, scope, connector, ingredient_id, product_id,
            query, payload_json, status, max_attempts
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ON CONFLICT(idempotency_key) DO NOTHING
    ");
    $stmt->execute([
        $idempotencyKey,
        $jobType,
        max(0, $priority),
        isset($scope['scope']) ? (string)$scope['scope'] : null,
        isset($scope['connector']) ? (string)$scope['connector'] : null,
        isset($scope['ingredient_id']) ? (int)$scope['ingredient_id'] : null,
        isset($scope['product_id']) ? (int)$scope['product_id'] : null,
        isset($scope['query']) ? (string)$scope['query'] : null,
        recipeCatalogJsonEncode($payload),
        max(1, $maxAttempts),
    ]);
    $created = $stmt->rowCount() > 0;
    $read = $db->prepare("SELECT * FROM recipe_jobs WHERE idempotency_key = ?");
    $read->execute([$idempotencyKey]);
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Recipe job could not be read after enqueue');
    }
    return ['job' => recipeJobDecodeRow($row), 'created' => $created];
}

function recipeJobEnqueueInventoryChanged(PDO $db, int $productId, string $reason): array {
    $inventoryRevision = recipeScoreMarkDirty($db);
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

function recipeJobDecodeRow(array $row): array {
    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    $result = json_decode((string)($row['last_result_json'] ?? ''), true);
    $row['id'] = (int)$row['id'];
    $row['ingredient_id'] = $row['ingredient_id'] !== null ? (int)$row['ingredient_id'] : null;
    $row['product_id'] = $row['product_id'] !== null ? (int)$row['product_id'] : null;
    $row['attempts'] = (int)$row['attempts'];
    $row['max_attempts'] = (int)$row['max_attempts'];
    $row['priority'] = (int)($row['priority'] ?? 0);
    $row['payload'] = is_array($payload) ? $payload : [];
    $row['result'] = is_array($result) ? $result : null;
    unset($row['payload_json'], $row['last_result_json']);
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

function recipeJobQueueLock(): mixed {
    $path = __DIR__ . '/../../../data/recipe_queue.lock';
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create recipe queue lock directory');
    }
    if (!file_exists($path)) {
        $oldUmask = umask(0);
        try {
            @touch($path);
        } finally {
            umask($oldUmask);
        }
    }
    @chmod($path, 0666);
    $handle = @fopen($path, 'c');
    if ($handle === false) {
        // A root-created 0644 lock is still readable by the cron user; flock does
        // not need file contents to be writable.
        $handle = @fopen($path, 'r');
    }
    if ($handle === false) {
        throw new RuntimeException('Cannot open recipe queue lock file');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

function recipeJobQueueUnlock(mixed $handle): void {
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
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
    if (!recipeCookidooMetadataBackfillEnabled()) {
        return [
            'status' => 'skipped',
            'result' => ['reason' => 'metadata_backfill_disabled'],
        ];
    }
    $request = recipeCookidooNormalizeMetadataRefreshInput($payload);
    $revisionBefore = recipeScoreState($db);
    $verify = $db->prepare("
        SELECT o.connector, o.external_id, o.locale,
               c.primary_connector, c.deleted_at,
               o.metadata_version,
               o.metadata_schema_version,
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
        ) {
            $terminal[$externalId] = [
                'status' => 'succeeded',
                'source_ingredient_count' => (int)(
                    $stored['source_ingredient_count'] ?? 0
                ),
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
        $bridgeResult = recipeCookidooBridgeMetadataBatch([
            'locale' => $request['locale'],
            'recipes' => $pending,
        ]);
        $responseBytes = (int)($bridgeResult['response_bytes'] ?? 0);
        $latencyMs = (int)($bridgeResult['latency_ms'] ?? 0);
        $retrievedAt = gmdate('Y-m-d H:i:s');
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        try {
            foreach ($pending as $index => $recipe) {
                $outcome = $bridgeResult['outcomes'][$index];
                $externalId = $recipe['external_id'];
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
                        $retrievedAt
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
                    $outcome['error_kind']
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
            if ($ownsTransaction) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
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
    $remapped = recipeCatalogRefreshUnresolvedMappings($db);
    return [
        'status' => 'done',
        'result' => [
            'remapped_ingredients' => $remapped,
            'ranking' => 'score_rebuild_queued',
            'inventory_revision' => recipeScoreMarkDirty($db),
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

function recipeJobProcessQueue(
    PDO $db,
    int $limit = 10,
    int $maxAttempts = 3,
    bool $allowCookidoo = true
): array {
    $limit = max(0, min(100, $limit));
    if ($limit === 0) {
        return ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0, 'items' => []];
    }
    $lock = recipeJobQueueLock();
    if ($lock === false) {
        return [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'items' => [],
            'lock_skipped' => true,
        ];
    }
    try {
        return recipeJobProcessQueueBatch($db, $limit, $maxAttempts, $allowCookidoo);
    } finally {
        recipeJobQueueUnlock($lock);
    }
}

function recipeJobProcessQueueBatch(
    PDO $db,
    int $limit,
    int $maxAttempts,
    bool $allowCookidoo = true
): array {
    $maxAttempts = max(1, $maxAttempts);
    $leaseMinutes = max(1, min(1440, (int)env('RECIPE_QUEUE_LEASE_MINUTES', '15')));
    $staleBefore = '-' . $leaseMinutes . ' minutes';
    $policySkippedRows = [];
    $cookidooPolicyAllowed = recipeCookidooDetailHydrationPolicyAllows();
    if (!$cookidooPolicyAllowed) {
        $policyReason = RECIPE_COOKIDOO_DETAIL_POLICY_REASON;
        $disableCookidoo = $db->prepare("
            UPDATE recipe_jobs SET
                status = 'skipped',
                next_retry_at = NULL,
                last_error = ?,
                last_result_json = ?,
                started_at = NULL,
                finished_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE connector = 'cookidoo'
              AND job_type IN (
                  'connector_discovery',
                  'recipe_metadata_refresh',
                  'recipe_refresh'
              )
              AND status IN ('pending', 'retry', 'in_progress')
            RETURNING id, job_type
        ");
        $disableCookidoo->execute([
            $policyReason,
            recipeCatalogJsonEncode(['reason' => $policyReason]),
        ]);
        $policySkippedRows = $disableCookidoo->fetchAll(PDO::FETCH_ASSOC);
    }
    $terminalizeExpired = $db->prepare("
        UPDATE recipe_jobs SET
            status = 'failed',
            next_retry_at = NULL,
            last_error = 'lease_exhausted',
            started_at = NULL,
            finished_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE status = 'in_progress'
          AND started_at IS NOT NULL
          AND started_at <= datetime('now', ?)
          AND attempts >= MIN(max_attempts, CAST(? AS INTEGER))
    ");
    $terminalizeExpired->execute([
        $staleBefore,
        $maxAttempts,
    ]);
    $reclaim = $db->prepare("
        UPDATE recipe_jobs SET
            status = 'retry',
            next_retry_at = CURRENT_TIMESTAMP,
            last_error = 'processing lease expired',
            started_at = NULL,
            finished_at = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE status = 'in_progress'
          AND started_at IS NOT NULL
          AND started_at <= datetime('now', ?)
          AND attempts < MIN(max_attempts, CAST(? AS INTEGER))
    ");
    $reclaim->execute([$staleBefore, $maxAttempts]);

    $cookidooFilter = $cookidooPolicyAllowed && !$allowCookidoo
        ? "AND (connector IS NULL OR connector <> 'cookidoo')"
        : '';
    $fetchRows = static function (
        string $priorityWhere,
        int $rowLimit,
        array $excludeIds = []
    ) use ($db, $maxAttempts, $cookidooFilter): array {
        if ($rowLimit <= 0) {
            return [];
        }
        $excludeWhere = '';
        $params = [$maxAttempts];
        if ($excludeIds) {
            $excludeWhere = 'AND id NOT IN ('
                . implode(',', array_fill(0, count($excludeIds), '?'))
                . ')';
            $params = array_merge($params, array_map('intval', $excludeIds));
        }
        $stmt = $db->prepare("
            SELECT *
            FROM recipe_jobs
            WHERE status IN ('pending', 'retry')
              AND attempts < MIN(max_attempts, CAST(? AS INTEGER))
              AND (next_retry_at IS NULL OR next_retry_at <= CURRENT_TIMESTAMP)
              {$priorityWhere}
              {$excludeWhere}
              {$cookidooFilter}
            ORDER BY priority DESC, created_at ASC, id ASC
            LIMIT {$rowLimit}
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    // Reserve at most one slot for an interactive request, while allowing the
    // remaining capacity to continue draining background crawl jobs.
    $rows = $fetchRows('AND priority > 0', 1);
    $rows = array_merge(
        $rows,
        $fetchRows('AND priority <= 0', $limit - count($rows))
    );
    if (count($rows) < $limit) {
        $rows = array_merge(
            $rows,
            $fetchRows(
                'AND priority > 0',
                $limit - count($rows),
                array_map(static fn(array $row): int => (int)$row['id'], $rows)
            )
        );
    }
    $summary = [
        'processed' => count($policySkippedRows),
        'succeeded' => 0,
        'failed' => 0,
        'skipped' => count($policySkippedRows),
        'items' => array_map(
            static fn(array $row): array => [
                'id' => (int)$row['id'],
                'job_type' => (string)$row['job_type'],
                'status' => 'skipped',
                'result' => [
                    'reason' => RECIPE_COOKIDOO_DETAIL_POLICY_REASON,
                ],
            ],
            $policySkippedRows
        ),
    ];

    foreach ($rows as $rawRow) {
        $job = recipeJobDecodeRow($rawRow);
        $summary['processed']++;
        $db->prepare("
            UPDATE recipe_jobs SET
                status = 'in_progress', attempts = attempts + 1,
                started_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$job['id']]);
        $job = recipeJobGet($db, $job['id']) ?? $job;

        try {
            $outcome = recipeJobDispatch($db, $job);
            if (($outcome['status'] ?? '') === 'retry') {
                $db->prepare("
                    UPDATE recipe_jobs SET
                        status = 'retry',
                        attempts = MAX(0, attempts - 1),
                        next_retry_at = ?,
                        last_result_json = ?,
                        last_error = '',
                        started_at = NULL,
                        finished_at = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ")->execute([
                    $outcome['retry_at'] ?? date('Y-m-d H:i:s', time() + 900),
                    recipeCatalogJsonEncode($outcome['result'] ?? []),
                    $job['id'],
                ]);
                $summary['skipped']++;
                $summary['items'][] = [
                    'id' => $job['id'],
                    'job_type' => $job['job_type'],
                    'status' => 'retry',
                    'result' => $outcome['result'] ?? [],
                ];
                continue;
            }
            $status = $outcome['status'] === 'skipped' ? 'skipped' : 'done';
            $db->prepare("
                UPDATE recipe_jobs SET
                    status = CASE WHEN status = 'pending' THEN 'pending' ELSE ? END,
                    last_result_json = ?, last_error = '',
                    finished_at = CASE
                        WHEN status = 'pending' THEN NULL ELSE CURRENT_TIMESTAMP
                    END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([
                $status,
                recipeCatalogJsonEncode($outcome['result'] ?? []),
                $job['id'],
            ]);
            if ($status === 'skipped') {
                $summary['skipped']++;
            } else {
                $summary['succeeded']++;
            }
            if ($status === 'done' && !empty($job['connector'])) {
                $db->prepare("
                    UPDATE recipe_connector_state SET
                        last_success_at = CURRENT_TIMESTAMP, last_error = '',
                        failure_count = 0, circuit_open_until = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE connector = ?
                ")->execute([$job['connector']]);
            }
            $summary['items'][] = [
                'id' => $job['id'],
                'job_type' => $job['job_type'],
                'status' => $status,
                'result' => $outcome['result'] ?? [],
            ];
        } catch (Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 1000, 'UTF-8');
            $canRetry = $job['attempts'] < min($job['max_attempts'], $maxAttempts);
            $forceCircuitBreak =
                $e instanceof RecipeCookidooCircuitBreakException;
            $retrySeconds = $forceCircuitBreak
                ? 900
                : min(3600, 30 * (2 ** max(0, $job['attempts'] - 1)));
            $db->prepare("
                UPDATE recipe_jobs SET
                    status = CASE WHEN status = 'pending' THEN 'pending' ELSE ? END,
                    next_retry_at = CASE
                        WHEN status = 'pending' THEN NULL
                        WHEN ? = 1 THEN datetime('now', '+' || ? || ' seconds')
                        ELSE NULL
                    END,
                    last_error = ?, finished_at = CASE
                        WHEN status = 'pending' OR ? = 1 THEN NULL
                        ELSE CURRENT_TIMESTAMP
                    END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([
                $canRetry ? 'retry' : 'failed',
                $canRetry ? 1 : 0,
                $retrySeconds,
                $message,
                $canRetry ? 1 : 0,
                $job['id'],
            ]);
            if (!empty($job['connector'])) {
                $db->prepare("
                    UPDATE recipe_connector_state SET
                        last_error = ?,
                        failure_count = CASE
                            WHEN ? = 1 THEN MAX(failure_count, 3)
                            ELSE failure_count + 1
                        END,
                        circuit_open_until = CASE
                            WHEN ? = 1 THEN datetime('now', '+12 hours')
                            WHEN failure_count + 1 >= 3
                            THEN datetime('now', '+15 minutes')
                            ELSE circuit_open_until
                        END,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE connector = ?
                ")->execute([
                    $message,
                    $forceCircuitBreak ? 1 : 0,
                    $forceCircuitBreak ? 1 : 0,
                    $job['connector'],
                ]);
            }
            if (!$canRetry) {
                $summary['failed']++;
            }
            $summary['items'][] = [
                'id' => $job['id'],
                'job_type' => $job['job_type'],
                'status' => $canRetry ? 'retry' : 'failed',
                'error' => $message,
            ];
        }
    }
    return $summary;
}
