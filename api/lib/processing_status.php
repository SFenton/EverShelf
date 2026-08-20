<?php
declare(strict_types=1);

const EVERSHELF_PROCESSING_STATUS_SCHEMA =
    'evershelf-processing-status-v1';
const EVERSHELF_PROCESSING_STATUS_PUBLIC_ERROR =
    'EverShelf processing needs attention. Check the server logs for details.';

function evershelfProcessingStatusPublicError(
    ?string $error
): ?string {
    return trim((string)$error) === ''
        ? null
        : EVERSHELF_PROCESSING_STATUS_PUBLIC_ERROR;
}

function evershelfProcessingStatusPublicOutcome(
    ?string $kind,
    array $outcome
): array {
    $public = [];
    foreach ([
        'intent_count',
        'applied_count',
        'deferred_count',
        'claimed_intents',
        'expected_retry_count',
        'expected_retry_limit',
        'next_attempt_seconds',
    ] as $field) {
        if (isset($outcome[$field]) && is_numeric($outcome[$field])) {
            $public[$field] = (int)$outcome[$field];
        }
    }
    foreach ([
        'policy_deferred',
        'escalated',
    ] as $field) {
        if (array_key_exists($field, $outcome)) {
            $public[$field] = !empty($outcome[$field]);
        }
    }
    if (is_array($outcome['drift_codes'] ?? null)) {
        $public['drift_codes'] = array_values(array_filter(
            array_map('strval', $outcome['drift_codes']),
            static fn(string $code): bool =>
                preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $code) === 1
        ));
    }
    $reason = trim((string)($outcome['reason'] ?? ''));
    if (preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $reason)) {
        $public['reason'] = $reason;
    }
    return $public;
}

function evershelfProcessingStatusTableExists(
    PDO $db,
    string $table
): bool {
    $stmt = $db->prepare("
        SELECT 1
        FROM sqlite_master
        WHERE type = 'table' AND name = ?
        LIMIT 1
    ");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function evershelfProcessingStatusAgeSeconds(
    ?string $timestamp
): ?int {
    $timestamp = trim((string)$timestamp);
    if ($timestamp === '') {
        return null;
    }
    $parsed = strtotime($timestamp . ' UTC');
    if ($parsed === false) {
        return null;
    }
    return max(0, time() - $parsed);
}

function evershelfProcessingStatusEarliest(
    ?string ...$timestamps
): ?string {
    $available = array_values(array_filter(
        array_map(
            static fn(?string $value): string =>
                trim((string)$value),
            $timestamps
        ),
        static fn(string $value): bool => $value !== ''
    ));
    if (!$available) {
        return null;
    }
    sort($available, SORT_STRING);
    return $available[0];
}

function evershelfProcessingStatusRecipeQueue(PDO $db): array {
    if (!evershelfProcessingStatusTableExists($db, 'recipe_jobs')) {
        return [
            'available' => false,
            'open_count' => 0,
            'in_progress_count' => 0,
            'failed_24h_count' => 0,
            'oldest_at' => null,
            'oldest_age_seconds' => null,
            'by_status' => [],
            'by_type' => [],
        ];
    }
    $rows = $db->query("
        SELECT status, job_type, COUNT(*) AS job_count,
               MIN(created_at) AS oldest_at
        FROM recipe_jobs
        WHERE status IN ('pending', 'retry', 'in_progress')
        GROUP BY status, job_type
        ORDER BY status, job_type
    ")->fetchAll(PDO::FETCH_ASSOC);
    $byStatus = [];
    $byType = [];
    $oldestAt = null;
    $openCount = 0;
    foreach ($rows as $row) {
        $count = (int)$row['job_count'];
        $status = (string)$row['status'];
        $type = (string)$row['job_type'];
        $openCount += $count;
        $byStatus[$status] = ($byStatus[$status] ?? 0) + $count;
        $byType[$type] = ($byType[$type] ?? 0) + $count;
        $oldestAt = evershelfProcessingStatusEarliest(
            $oldestAt,
            $row['oldest_at'] ?? null
        );
    }
    $failed = (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_jobs
        WHERE status = 'failed'
          AND updated_at >= datetime('now', '-24 hours')
    ")->fetchColumn();
    ksort($byStatus);
    ksort($byType);
    return [
        'available' => true,
        'open_count' => $openCount,
        'in_progress_count' => (int)($byStatus['in_progress'] ?? 0),
        'failed_24h_count' => $failed,
        'oldest_at' => $oldestAt,
        'oldest_age_seconds' =>
            evershelfProcessingStatusAgeSeconds($oldestAt),
        'by_status' => $byStatus,
        'by_type' => $byType,
    ];
}

function evershelfProcessingStatusCanonicalQueue(PDO $db): array {
    if (
        !evershelfProcessingStatusTableExists(
            $db,
            'canonical_processing_queue'
        )
    ) {
        return [
            'available' => false,
            'lock_available' => function_exists(
                'canonicalIngredientQueueLockAvailable'
            )
                ? canonicalIngredientQueueLockAvailable()
                : false,
            'open_count' => 0,
            'active_count' => 0,
            'pending_count' => 0,
            'in_progress_count' => 0,
            'retry_count' => 0,
            'retry_due_count' => 0,
            'failed_count' => 0,
            'exhausted_count' => 0,
            'exhausted_pending_count' => 0,
            'failed_24h_count' => 0,
            'overdue_lease_count' => 0,
            'oldest_pending_at' => null,
            'oldest_pending_age_seconds' => null,
            'oldest_retry_at' => null,
            'oldest_retry_age_seconds' => null,
            'oldest_due_at' => null,
            'oldest_due_age_seconds' => null,
            'stale_due_seconds' => 300,
            'stale_due_count' => 0,
            'oldest_in_progress_at' => null,
            'oldest_in_progress_age_seconds' => null,
            'earliest_lease_expires_at' => null,
            'next_due_at' => null,
            'oldest_active_at' => null,
            'last_error_kind' => null,
            'last_error' => null,
            'last_error_at' => null,
        ];
    }
    $maxAttempts = max(
        1,
        min(
            20,
            function_exists('env')
                ? (int)env('CANONICAL_QUEUE_MAX_ATTEMPTS', '3')
                : 3
        )
    );
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE
                WHEN status IN ('pending', 'failed')
                 AND attempts < ?
                THEN 1 ELSE 0 END), 0) AS retryable_count,
            COALESCE(SUM(CASE
                WHEN status = 'pending'
                THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE
                WHEN status = 'in_progress'
                THEN 1 ELSE 0 END), 0) AS in_progress_count,
            COALESCE(SUM(CASE
                WHEN status IN ('pending', 'failed')
                 AND attempts < ?
                 AND next_retry_at IS NOT NULL
                THEN 1 ELSE 0 END), 0) AS retry_count,
            COALESCE(SUM(CASE
                WHEN status IN ('pending', 'failed')
                 AND attempts < ?
                 AND (
                    next_retry_at IS NULL
                    OR next_retry_at <= CURRENT_TIMESTAMP
                 )
                THEN 1 ELSE 0 END), 0) AS retry_due_count,
            COALESCE(SUM(CASE
                WHEN status = 'failed' AND attempts >= ?
                THEN 1 ELSE 0 END), 0) AS failed_count,
            COALESCE(SUM(CASE
                WHEN status IN ('pending', 'failed')
                 AND attempts >= ?
                THEN 1 ELSE 0 END), 0) AS exhausted_count,
            COALESCE(SUM(CASE
                WHEN status = 'pending'
                 AND attempts >= ?
                THEN 1 ELSE 0 END), 0)
                AS exhausted_pending_count,
            COALESCE(SUM(CASE
                WHEN status = 'failed'
                 AND attempts >= ?
                 AND updated_at >= datetime('now', '-24 hours')
                THEN 1 ELSE 0 END), 0) AS failed_24h_count,
            COALESCE(SUM(CASE
                WHEN status = 'in_progress'
                 AND lease_expires_at <= CURRENT_TIMESTAMP
                THEN 1 ELSE 0 END), 0) AS overdue_lease_count,
            MIN(CASE
                WHEN status IN ('pending', 'failed')
                 AND attempts < ?
                THEN requested_at
                ELSE NULL
            END) AS oldest_pending_at,
            MIN(CASE
                WHEN status IN ('pending', 'failed')
                 AND attempts < ?
                 AND next_retry_at IS NOT NULL
                THEN next_retry_at
                ELSE NULL
            END) AS oldest_retry_at,
            MIN(CASE
                WHEN status IN ('pending', 'failed')
                 AND attempts < ?
                 AND (
                    next_retry_at IS NULL
                    OR next_retry_at <= CURRENT_TIMESTAMP
                 )
                THEN requested_at
                ELSE NULL
            END) AS oldest_due_at,
            MIN(CASE
                WHEN status = 'in_progress'
                THEN started_at
                ELSE NULL
            END) AS oldest_in_progress_at,
            MIN(CASE
                WHEN status = 'in_progress'
                THEN lease_expires_at
                ELSE NULL
            END) AS earliest_lease_expires_at,
            MIN(CASE
                WHEN status IN ('pending', 'failed')
                 AND attempts < ?
                 AND next_retry_at > CURRENT_TIMESTAMP
                THEN next_retry_at
                ELSE NULL
            END) AS next_due_at
        FROM canonical_processing_queue
    ");
    $stmt->execute(array_fill(0, 11, $maxAttempts));
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stmt->closeCursor();
    $lastErrorStmt = $db->query("
        SELECT last_error_kind, last_error, updated_at
        FROM canonical_processing_queue
        WHERE TRIM(COALESCE(last_error_kind, '')) <> ''
           OR TRIM(COALESCE(last_error, '')) <> ''
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $lastError = $lastErrorStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $lastErrorStmt->closeCursor();
    $retryable = (int)($row['retryable_count'] ?? 0);
    $inProgress = (int)($row['in_progress_count'] ?? 0);
    $exhausted = (int)($row['exhausted_count'] ?? 0);
    $exhaustedPending =
        (int)($row['exhausted_pending_count'] ?? 0);
    $due = (int)($row['retry_due_count'] ?? 0);
    $staleDueSeconds = max(
        30,
        min(
            86400,
            function_exists('env')
                ? (int)env(
                    'CANONICAL_QUEUE_STALE_DUE_SECONDS',
                    '300'
                )
                : 300
        )
    );
    $oldestDueAt = trim((string)(
        $row['oldest_due_at'] ?? ''
    )) ?: null;
    $oldestDueAge =
        evershelfProcessingStatusAgeSeconds($oldestDueAt);
    $staleDueStmt = $db->prepare("
        SELECT COUNT(*)
        FROM canonical_processing_queue
        WHERE status IN ('pending', 'failed')
          AND attempts < ?
          AND (
              next_retry_at IS NULL
              OR next_retry_at <= CURRENT_TIMESTAMP
          )
          AND requested_at <= datetime(
              'now',
              '-' || ? || ' seconds'
          )
    ");
    $staleDueStmt->execute([
        $maxAttempts,
        $staleDueSeconds,
    ]);
    $staleDueCount = (int)$staleDueStmt->fetchColumn();
    $staleDueStmt->closeCursor();
    $lockAvailable = function_exists(
        'canonicalIngredientQueueLockAvailable'
    )
        ? canonicalIngredientQueueLockAvailable()
        : false;
    $oldestActiveAt = evershelfProcessingStatusEarliest(
        $inProgress > 0
            ? ($row['oldest_in_progress_at'] ?? null)
            : null,
        $due > 0
            ? $oldestDueAt
            : null
    );
    return [
        'available' => true,
        'lock_available' => $lockAvailable,
        'open_count' =>
            $retryable + $inProgress + $exhaustedPending,
        'active_count' => $due + $inProgress,
        'pending_count' => (int)($row['pending_count'] ?? 0),
        'in_progress_count' => $inProgress,
        'retry_count' => (int)($row['retry_count'] ?? 0),
        'retry_due_count' => $due,
        'failed_count' => (int)($row['failed_count'] ?? 0),
        'exhausted_count' => $exhausted,
        'exhausted_pending_count' => $exhaustedPending,
        'failed_24h_count' =>
            (int)($row['failed_24h_count'] ?? 0),
        'overdue_lease_count' =>
            (int)($row['overdue_lease_count'] ?? 0),
        'oldest_pending_at' => $row['oldest_pending_at'] ?? null,
        'oldest_pending_age_seconds' =>
            evershelfProcessingStatusAgeSeconds(
                $row['oldest_pending_at'] ?? null
            ),
        'oldest_retry_at' => $row['oldest_retry_at'] ?? null,
        'oldest_retry_age_seconds' =>
            evershelfProcessingStatusAgeSeconds(
                $row['oldest_retry_at'] ?? null
            ),
        'oldest_due_at' => $oldestDueAt,
        'oldest_due_age_seconds' => $oldestDueAge,
        'stale_due_seconds' => $staleDueSeconds,
        'stale_due_count' => $staleDueCount,
        'oldest_in_progress_at' =>
            $row['oldest_in_progress_at'] ?? null,
        'oldest_in_progress_age_seconds' =>
            evershelfProcessingStatusAgeSeconds(
                $row['oldest_in_progress_at'] ?? null
            ),
        'earliest_lease_expires_at' =>
            $row['earliest_lease_expires_at'] ?? null,
        'next_due_at' => $row['next_due_at'] ?? null,
        'oldest_active_at' => $oldestActiveAt,
        'last_error_kind' => trim((string)(
            $lastError['last_error_kind'] ?? ''
        )) ?: null,
        'last_error' => evershelfProcessingStatusPublicError(
            $lastError['last_error'] ?? null
        ),
        'last_error_at' => $lastError['updated_at'] ?? null,
    ];
}

function evershelfProcessingStatusOntologyQueue(PDO $db): array {
    if (
        !evershelfProcessingStatusTableExists(
            $db,
            'ontology_controller_jobs'
        )
    ) {
        return [
            'available' => false,
            'runtime_enabled' => false,
            'minimum_priority' => 0,
            'intake_open_count' => 0,
            'generation_open_count' => 0,
            'deferred_count' => 0,
            'generation_intent_pending_count' => 0,
            'generation_intent_due_count' => 0,
            'generation_intent_policy_deferred_count' => 0,
            'generation_intent_oldest_at' => null,
            'generation_intent_oldest_age_seconds' => null,
            'generation_intent_due_oldest_at' => null,
            'generation_intent_due_oldest_age_seconds' => null,
            'coverage_gap_open_count' => 0,
            'coverage_gap_oldest_at' => null,
            'coverage_gap_oldest_age_seconds' => null,
            'active_count' => 0,
            'failed_24h_count' => 0,
            'oldest_at' => null,
            'oldest_age_seconds' => null,
            'provider' => [
                'provider' => '',
                'configured' => false,
                'healthy' => false,
                'reason' => 'controller_schema_unavailable',
            ],
        ];
    }
    $minimumPriority = function_exists(
        'ingredientOntologyControllerMinimumPriority'
    )
        ? ingredientOntologyControllerMinimumPriority()
        : 0;
    $runtimeEnabled = function_exists(
        'ingredientOntologyControllerEnabled'
    ) && ingredientOntologyControllerEnabled();
    $openStatuses = [
        'queued', 'leased', 'model_running', 'responses_ready',
        'staged', 'validating', 'generation_pending', 'shadowing',
        'promotable', 'promoting', 'retry',
    ];
    $statusSql = "'" . implode("','", $openStatuses) . "'";
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE
                WHEN job_type IN (
                    'subject_resolution', 'correction', 'compensation'
                )
                 AND priority >= ?
                THEN 1 ELSE 0 END), 0) AS intake_open_count,
            COALESCE(SUM(CASE
                WHEN job_type IN ('generation', 'gold_release')
                 AND priority >= ?
                THEN 1 ELSE 0 END), 0) AS generation_open_count,
            COALESCE(SUM(CASE
                WHEN priority < ?
                THEN 1 ELSE 0 END), 0) AS deferred_count,
            COALESCE(SUM(CASE
                WHEN status NOT IN ('queued', 'retry')
                THEN 1 ELSE 0 END), 0) AS active_count,
            MIN(CASE
                WHEN priority >= ?
                THEN created_at ELSE NULL END
            ) AS oldest_at
        FROM ontology_controller_jobs
        WHERE status IN ({$statusSql})
    ");
    $stmt->execute([
        $minimumPriority,
        $minimumPriority,
        $minimumPriority,
        $minimumPriority,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $failed = (int)$db->query("
        SELECT COUNT(*)
        FROM ontology_controller_jobs
        WHERE status = 'failed'
          AND COALESCE(last_error_kind, '')
              <> 'generation_abandoned'
          AND updated_at >= datetime('now', '-24 hours')
    ")->fetchColumn();
    $provider = function_exists(
        'ingredientOntologyControllerProviderHealth'
    )
        ? ingredientOntologyControllerProviderHealth()
        : [
            'provider' => '',
            'configured' => false,
            'healthy' => false,
            'reason' => 'controller_runtime_unavailable',
        ];
    $intent = [
        'pending_count' => 0,
        'due_count' => 0,
        'policy_deferred_count' => 0,
        'oldest_at' => null,
        'oldest_due_at' => null,
    ];
    if (evershelfProcessingStatusTableExists(
        $db,
        'ontology_generation_intents'
    )) {
        $intent = $db->query("
            SELECT COUNT(*) AS pending_count,
                   COALESCE(SUM(CASE
                       WHEN job.next_attempt_at IS NULL
                         OR job.next_attempt_at <= CURRENT_TIMESTAMP
                       THEN 1 ELSE 0 END), 0) AS due_count,
                   COALESCE(SUM(CASE
                       WHEN job.last_error_kind =
                            'generation_policy_deferred'
                       THEN 1 ELSE 0 END), 0)
                       AS policy_deferred_count,
                   MIN(intent.created_at) AS oldest_at,
                   MIN(CASE
                       WHEN job.next_attempt_at IS NULL
                         OR job.next_attempt_at <= CURRENT_TIMESTAMP
                       THEN intent.created_at ELSE NULL END)
                       AS oldest_due_at
            FROM ontology_generation_intents intent
            JOIN ontology_controller_jobs job
              ON job.id = intent.source_job_id
            WHERE intent.status = 'pending'
        ")->fetch(PDO::FETCH_ASSOC) ?: $intent;
    }
    $coverageGap = [
        'open_count' => 0,
        'oldest_at' => null,
    ];
    if (evershelfProcessingStatusTableExists(
        $db,
        'ontology_controller_coverage_gaps'
    )) {
        $coverageGap = $db->query("
            SELECT COUNT(*) AS open_count,
                   MIN(created_at) AS oldest_at
            FROM ontology_controller_coverage_gaps
            WHERE status = 'open'
        ")->fetch(PDO::FETCH_ASSOC) ?: $coverageGap;
    }
    $oldestAt = trim((string)($row['oldest_at'] ?? '')) ?: null;
    $intentOldest =
        trim((string)($intent['oldest_at'] ?? '')) ?: null;
    $intentDueOldest =
        trim((string)($intent['oldest_due_at'] ?? '')) ?: null;
    $coverageGapOldest =
        trim((string)($coverageGap['oldest_at'] ?? '')) ?: null;
    return [
        'available' => true,
        'runtime_enabled' => $runtimeEnabled,
        'minimum_priority' => $minimumPriority,
        'intake_open_count' =>
            (int)($row['intake_open_count'] ?? 0),
        'generation_open_count' =>
            (int)($row['generation_open_count'] ?? 0),
        'deferred_count' => (int)($row['deferred_count'] ?? 0),
        'generation_intent_pending_count' =>
            (int)($intent['pending_count'] ?? 0),
        'generation_intent_due_count' =>
            (int)($intent['due_count'] ?? 0),
        'generation_intent_policy_deferred_count' =>
            (int)($intent['policy_deferred_count'] ?? 0),
        'generation_intent_oldest_at' => $intentOldest,
        'generation_intent_oldest_age_seconds' =>
            evershelfProcessingStatusAgeSeconds($intentOldest),
        'generation_intent_due_oldest_at' => $intentDueOldest,
        'generation_intent_due_oldest_age_seconds' =>
            evershelfProcessingStatusAgeSeconds($intentDueOldest),
        'coverage_gap_open_count' =>
            (int)($coverageGap['open_count'] ?? 0),
        'coverage_gap_oldest_at' => $coverageGapOldest,
        'coverage_gap_oldest_age_seconds' =>
            evershelfProcessingStatusAgeSeconds($coverageGapOldest),
        'active_count' => (int)($row['active_count'] ?? 0),
        'failed_24h_count' => $failed,
        'oldest_at' => $oldestAt,
        'oldest_age_seconds' =>
            evershelfProcessingStatusAgeSeconds($oldestAt),
        'provider' => [
            'provider' => substr(
                trim((string)($provider['provider'] ?? '')),
                0,
                80
            ),
            'configured' => !empty($provider['configured']),
            'healthy' => !empty($provider['healthy']),
            'reason' => isset($provider['reason'])
                ? substr(trim((string)$provider['reason']), 0, 120)
                : null,
        ],
    ];
}

function evershelfProcessingStatusScores(PDO $db): array {
    if (
        !evershelfProcessingStatusTableExists($db, 'recipe_score_state')
        || !function_exists('recipeScoreState')
    ) {
        return [
            'available' => false,
            'active_revision_id' => null,
            'status' => 'unavailable',
            'stale' => false,
            'reasons' => [],
            'current' => [],
            'built' => [],
            'dirty_at' => null,
            'last_built_at' => null,
        ];
    }
    $state = recipeScoreState($db);
    $revisionId = (int)($state['active_score_revision_id'] ?? 0);
    $revision = $revisionId > 0
        && function_exists('recipeScoreRevision')
            ? recipeScoreRevision($db, $revisionId)
            : null;
    if (!is_array($revision)) {
        return [
            'available' => true,
            'active_revision_id' => $revisionId ?: null,
            'status' => 'missing',
            'stale' => true,
            'reasons' => ['active_revision_missing'],
            'current' => [
                'inventory_revision' =>
                    (int)$state['inventory_revision'],
                'catalog_revision' =>
                    (int)$state['catalog_revision'],
                'ontology_source_revision' =>
                    (int)$state['ontology_source_revision'],
            ],
            'built' => [],
            'dirty_at' => $state['dirty_at'] ?? null,
            'last_built_at' => $state['last_built_at'] ?? null,
        ];
    }
    $overlay = function_exists('recipeScoreActiveOverlay')
        ? recipeScoreActiveOverlay($db, $state, $revision)
        : null;
    $effectiveRevision = $overlay ?? $revision;
    $status = $overlay !== null
        ? 'fresh_overlay'
        : (
            function_exists('recipeScoreRevisionStatus')
                ? recipeScoreRevisionStatus($db, $revision)
                : 'unknown'
        );
    $reasons = [];
    foreach ([
        'inventory_revision',
        'catalog_revision',
        'ontology_source_revision',
    ] as $field) {
        if (
            (int)$effectiveRevision[$field]
                !== (int)$state[$field]
        ) {
            $reasons[] = $field;
        }
    }
    if (
        isset($effectiveRevision['score_date'])
        && (string)$effectiveRevision['score_date']
            !== recipeScoreCurrentDate()
    ) {
        $reasons[] = 'score_date';
    }
    return [
        'available' => true,
        'active_revision_id' => $revisionId,
        'overlay_revision_id' => $overlay !== null
            ? (int)$overlay['id']
            : null,
        'ontology_version_id' =>
            isset($effectiveRevision['ontology_version_id'])
                ? (int)$effectiveRevision['ontology_version_id']
                : null,
        'status' => $status,
        'stale' => !in_array(
            $status,
            ['fresh', 'fresh_overlay'],
            true
        ),
        'reasons' => $reasons,
        'current' => [
            'inventory_revision' => (int)$state['inventory_revision'],
            'catalog_revision' => (int)$state['catalog_revision'],
            'ontology_source_revision' =>
                (int)$state['ontology_source_revision'],
        ],
        'built' => [
            'inventory_revision' =>
                (int)$effectiveRevision['inventory_revision'],
            'catalog_revision' =>
                (int)$effectiveRevision['catalog_revision'],
            'ontology_source_revision' =>
                (int)$effectiveRevision['ontology_source_revision'],
        ],
        'dirty_at' => $state['dirty_at'] ?? null,
        'last_built_at' => $state['last_built_at'] ?? null,
    ];
}

function evershelfProcessingStatusActivation(PDO $db): array {
    $state = null;
    if (
        evershelfProcessingStatusTableExists(
            $db,
            'ontology_activation_state'
        )
    ) {
        $state = $db->query("
            SELECT requested_at, requested_reason, next_attempt_at,
                   failure_count, last_error,
                   last_outcome_kind, last_outcome_json,
                   last_outcome_at, updated_at
            FROM ontology_activation_state
            WHERE id = 1
        ")->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $latest = null;
    $current = null;
    $latestError = null;
    if (
        evershelfProcessingStatusTableExists(
            $db,
            'ontology_activation_imports'
        )
    ) {
        $latest = $db->query("
            SELECT id, bundle_kind, status, phase, rows_imported,
                   parent_ontology_version_id,
                   candidate_ontology_version_id,
                   parent_score_revision_id,
                   candidate_score_revision_id,
                   last_error, created_at, updated_at,
                   activated_at, completed_at
            FROM ontology_activation_imports
            ORDER BY id DESC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC) ?: null;
        $current = $db->query("
            SELECT id, bundle_kind, status, phase, rows_imported,
                   parent_ontology_version_id,
                   candidate_ontology_version_id,
                   parent_score_revision_id,
                   candidate_score_revision_id,
                   last_error, created_at, updated_at,
                   activated_at, completed_at
            FROM ontology_activation_imports
            WHERE status IN (
                'staging', 'importing', 'verifying', 'activatable'
            )
            ORDER BY
                CASE bundle_kind WHEN 'ontology' THEN 0 ELSE 1 END,
                id
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC) ?: null;
        $latestError = $db->query("
            SELECT last_error, updated_at
            FROM ontology_activation_imports
            WHERE trim(last_error) <> ''
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $running = is_array($current);
    $errors = [];
    $stateError = trim((string)($state['last_error'] ?? ''));
    if ($stateError !== '') {
        $errors[] = [
            'message' => $stateError,
            'updated_at' => (string)($state['updated_at'] ?? ''),
        ];
    }
    $importError = trim((string)($latestError['last_error'] ?? ''));
    if ($importError !== '') {
        $errors[] = [
            'message' => $importError,
            'updated_at' => (string)($latestError['updated_at'] ?? ''),
        ];
    }
    usort(
        $errors,
        static fn(array $left, array $right): int =>
            strcmp($right['updated_at'], $left['updated_at'])
    );
    $lastError = evershelfProcessingStatusPublicError(
        (string)($errors[0]['message'] ?? '')
    );
    $lastOutcome = json_decode(
        (string)($state['last_outcome_json'] ?? '{}'),
        true
    );
    $lastOutcome = is_array($lastOutcome) ? $lastOutcome : [];
    $lastOutcomeKind =
        trim((string)($state['last_outcome_kind'] ?? '')) ?: null;
    $formatImport = static function (?array $import): ?array {
        if (!is_array($import)) {
            return null;
        }
        $error = trim((string)($import['last_error'] ?? ''));
        return [
            'id' => (int)$import['id'],
            'bundle_kind' => (string)$import['bundle_kind'],
            'status' => (string)$import['status'],
            'phase' => (int)$import['phase'],
            'rows_imported' => (int)$import['rows_imported'],
            'parent_ontology_version_id' =>
                isset($import['parent_ontology_version_id'])
                    ? (int)$import['parent_ontology_version_id']
                    : null,
            'candidate_ontology_version_id' =>
                isset($import['candidate_ontology_version_id'])
                    ? (int)$import['candidate_ontology_version_id']
                    : null,
            'parent_score_revision_id' =>
                isset($import['parent_score_revision_id'])
                    ? (int)$import['parent_score_revision_id']
                    : null,
            'candidate_score_revision_id' =>
                isset($import['candidate_score_revision_id'])
                    ? (int)$import['candidate_score_revision_id']
                    : null,
            'last_error' =>
                evershelfProcessingStatusPublicError($error),
            'created_at' => $import['created_at'],
            'updated_at' => $import['updated_at'],
            'activated_at' => $import['activated_at'],
            'completed_at' => $import['completed_at'],
        ];
    };
    return [
        'available' =>
            $state !== null || $latest !== null || $current !== null,
        'running' => $running,
        'requested_at' => $state['requested_at'] ?? null,
        'requested_reason' => substr(
            trim((string)($state['requested_reason'] ?? '')),
            0,
            160
        ),
        'next_attempt_at' => $state['next_attempt_at'] ?? null,
        'failure_count' => (int)($state['failure_count'] ?? 0),
        'last_error' => $lastError,
        'last_outcome_kind' => $lastOutcomeKind,
        'last_outcome' =>
            evershelfProcessingStatusPublicOutcome(
                $lastOutcomeKind,
                $lastOutcome
            ),
        'last_outcome_at' => $state['last_outcome_at'] ?? null,
        'updated_at' => $state['updated_at'] ?? null,
        'current_import' => $formatImport($current),
        'latest_import' => $formatImport($latest),
    ];
}

function evershelfProcessingStatusRecipeOntologyCoverage(
    PDO $db
): array {
    if (
        !evershelfProcessingStatusTableExists(
            $db,
            'recipe_source_ingredients'
        )
        || !evershelfProcessingStatusTableExists(
            $db,
            'ontology_subject_occurrences'
        )
    ) {
        return [
            'available' => false,
            'source_row_count' => 0,
            'covered_row_count' => 0,
            'missing_row_count' => 0,
            'recipe_count' => 0,
            'complete_recipe_count' => 0,
            'missing_recipe_count' => 0,
            'coverage_percent' => 0.0,
            'oldest_missing_at' => null,
        ];
    }
    $row = $db->query("
        WITH coverage AS (
            SELECT source.recipe_id, source.updated_at,
                   CASE WHEN occurrence.id IS NULL THEN 0 ELSE 1 END
                       AS covered
            FROM recipe_source_ingredients source
            LEFT JOIN ontology_subject_occurrences occurrence
              ON occurrence.owner_type = 'recipe_source_ingredient'
             AND occurrence.owner_id = source.id
             AND occurrence.active = 1
        ),
        recipes AS (
            SELECT recipe_id, COUNT(*) AS source_count,
                   SUM(covered) AS covered_count
            FROM coverage
            GROUP BY recipe_id
        )
        SELECT
            (SELECT COUNT(*) FROM coverage) AS source_row_count,
            (SELECT COALESCE(SUM(covered), 0) FROM coverage)
                AS covered_row_count,
            (SELECT COUNT(*) FROM coverage WHERE covered = 0)
                AS missing_row_count,
            (SELECT COUNT(*) FROM recipes) AS recipe_count,
            (SELECT COUNT(*) FROM recipes
             WHERE covered_count = source_count)
                AS complete_recipe_count,
            (SELECT COUNT(*) FROM recipes
             WHERE covered_count < source_count)
                AS missing_recipe_count,
            (SELECT MIN(updated_at) FROM coverage WHERE covered = 0)
                AS oldest_missing_at
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $sourceRows = (int)($row['source_row_count'] ?? 0);
    $coveredRows = (int)($row['covered_row_count'] ?? 0);
    return [
        'available' => true,
        'source_row_count' => $sourceRows,
        'covered_row_count' => $coveredRows,
        'missing_row_count' =>
            (int)($row['missing_row_count'] ?? 0),
        'recipe_count' => (int)($row['recipe_count'] ?? 0),
        'complete_recipe_count' =>
            (int)($row['complete_recipe_count'] ?? 0),
        'missing_recipe_count' =>
            (int)($row['missing_recipe_count'] ?? 0),
        'coverage_percent' => $sourceRows > 0
            ? round(($coveredRows / $sourceRows) * 100, 1)
            : 100.0,
        'oldest_missing_at' =>
            trim((string)($row['oldest_missing_at'] ?? '')) ?: null,
    ];
}

function evershelfProcessingStatusMissingRecipeIds(
    PDO $db,
    int $limit = 100
): array {
    $limit = max(1, min(500, $limit));
    if (
        !evershelfProcessingStatusTableExists(
            $db,
            'ontology_subject_occurrences'
        )
        || !evershelfProcessingStatusTableExists(
            $db,
            'recipe_source_ingredients'
        )
        || !evershelfProcessingStatusTableExists(
            $db,
            'recipe_ingredients'
        )
    ) {
        return [];
    }
    $rows = $db->query("
        SELECT DISTINCT missing.recipe_id
        FROM (
            SELECT source.recipe_id
            FROM recipe_source_ingredients source
            LEFT JOIN ontology_subject_occurrences occurrence
              ON occurrence.owner_type = 'recipe_source_ingredient'
             AND occurrence.owner_id = source.id
             AND occurrence.active = 1
            WHERE occurrence.id IS NULL
            UNION
            SELECT ingredient.recipe_id
            FROM recipe_ingredients ingredient
            LEFT JOIN ontology_subject_occurrences occurrence
              ON occurrence.owner_type = 'recipe_ingredient'
             AND occurrence.owner_id = ingredient.id
             AND occurrence.active = 1
            WHERE occurrence.id IS NULL
        ) missing
        ORDER BY missing.recipe_id
        LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

function evershelfProcessingStatusWorkPhase(
    array $recipeQueue,
    array $ontologyQueue,
    array $scores,
    array $activation,
    array $incremental = [],
    array $canonicalQueue = []
): string {
    if (in_array(
        (string)($incremental['phase'] ?? 'idle'),
        ['preparing', 'scoring', 'publishing', 'compacting'],
        true
    )) {
        return (string)$incremental['phase'];
    }
    if (!empty($activation['running'])) {
        return 'activating';
    }
    if ((int)($canonicalQueue['active_count'] ?? 0) > 0) {
        return 'canonical';
    }
    if (
        !empty($ontologyQueue['runtime_enabled'])
        && (
            (int)($ontologyQueue['intake_open_count'] ?? 0) > 0
            || (int)($ontologyQueue['generation_open_count'] ?? 0) > 0
            || (int)(
                $ontologyQueue['generation_intent_due_count'] ?? 0
            ) > 0
        )
    ) {
        return 'ontology';
    }
    if ((int)($recipeQueue['open_count'] ?? 0) > 0) {
        return 'recipes';
    }
    if (!empty($scores['stale'])) {
        return 'scoring';
    }
    return 'idle';
}

function evershelfProcessingStatusEffectiveIdentityCounts(
    PDO $db,
    array $version,
    array $products
): array {
    $counts = [
        'accepted' => 0,
        'unresolved' => 0,
        'rejected' => 0,
    ];
    $lastUpdatedAt = null;
    $stmt = $db->prepare("
        SELECT annex.*
        FROM ingredient_ontology_identity_annex annex
        WHERE annex.ontology_version_id = ?
    ");
    $stmt->execute([(int)$version['id']]);
    $annexByProduct = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $annexByProduct[(int)$row['product_id']] = $row;
    }
    foreach ($products as $product) {
        $productId = (int)$product['id'];
        $fingerprint =
            ingredientOntologyV3ProductOwnerFingerprint($product);
        $row = $annexByProduct[$productId] ?? null;
        $status = null;
        if (
            $row !== null
            && hash_equals(
                $fingerprint,
                (string)$row['owner_fingerprint']
            )
            && (string)$row['resolver_version'] ===
                INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION
            && hash_equals(
                ingredientOntologyV3IdentityAnnexReviewManifestHash(),
                (string)$row['review_manifest_hash']
            )
            && hash_equals(
                (string)$version['content_hash'],
                (string)$row['ontology_content_hash']
            )
            && hash_equals(
                (string)$version['seal_hash'],
                (string)$row['ontology_seal_hash']
            )
        ) {
            $status = (string)$row['status'];
        } else {
            $resolution =
                ingredientOntologyV3IdentityAnnexResolution(
                    $db,
                    $version,
                    $product
                );
            $status = (string)$resolution['status'];
        }
        if (!array_key_exists((string)$status, $counts)) {
            $status = 'unresolved';
        }
        $counts[(string)$status]++;
        $candidateUpdatedAt = trim(
            (string)($row['updated_at'] ?? '')
        );
        if (
            $candidateUpdatedAt !== ''
            && (
                $lastUpdatedAt === null
                || $candidateUpdatedAt > $lastUpdatedAt
            )
        ) {
            $lastUpdatedAt = $candidateUpdatedAt;
        }
    }
    return [
        'counts' => $counts,
        'last_updated_at' => $lastUpdatedAt,
    ];
}

function evershelfProcessingStatusIdentityAnnex(PDO $db): array {
    if (!evershelfProcessingStatusTableExists(
        $db,
        'ingredient_ontology_identity_annex'
    )) {
        return [
            'available' => false,
            'ontology_version_id' => null,
            'inventory_product_count' => 0,
            'accepted_count' => 0,
            'unresolved_count' => 0,
            'rejected_count' => 0,
            'last_updated_at' => null,
            'admission_revision' => 0,
            'review_manifest_hash' => '',
            'last_changed_label_count' => 0,
        ];
    }
    $active = function_exists('recipeScoreActiveRevision')
        ? recipeScoreActiveRevision($db)
        : null;
    $versionId = is_array($active)
        && $active['ontology_version_id'] !== null
            ? (int)$active['ontology_version_id']
            : 0;
    $products = $db->query("
        SELECT product.id, product.name, product.brand,
               product.category, product.prepared_food
        FROM products product
        WHERE EXISTS (
            SELECT 1
            FROM inventory stock
            WHERE stock.product_id = product.id
              AND stock.quantity > 0
        )
        ORDER BY product.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $inventoryCount = count($products);
    $counts = [
        'accepted' => 0,
        'unresolved' => $inventoryCount,
        'rejected' => 0,
    ];
    $lastUpdatedAt = null;
    $admissionState = function_exists(
        'ingredientOntologyV3IdentityAdmissionState'
    ) ? ingredientOntologyV3IdentityAdmissionState($db) : [
        'available' => false,
        'revision' => 0,
        'review_manifest_hash' => '',
        'last_changed_label_count' => 0,
    ];
    if ($versionId > 0) {
        $version = ingredientOntologyV3Version($db, $versionId);
        if ($version !== null && (string)$version['status'] === 'ready') {
            $effective =
                evershelfProcessingStatusEffectiveIdentityCounts(
                    $db,
                    $version,
                    $products
                );
            $counts = $effective['counts'];
            $lastUpdatedAt = $effective['last_updated_at'];
        }
    }
    return [
        'available' => true,
        'ontology_version_id' => $versionId ?: null,
        'inventory_product_count' => $inventoryCount,
        'accepted_count' => $counts['accepted'],
        'unresolved_count' => $counts['unresolved'],
        'rejected_count' => $counts['rejected'],
        'last_updated_at' => $lastUpdatedAt,
        'admission_revision' =>
            (int)($admissionState['revision'] ?? 0),
        'review_manifest_hash' =>
            (string)($admissionState['review_manifest_hash'] ?? ''),
        'last_changed_label_count' =>
            (int)($admissionState['last_changed_label_count'] ?? 0),
    ];
}

function evershelfProcessingStatusIncrementalScores(PDO $db): array {
    if (!evershelfProcessingStatusTableExists(
        $db,
        'recipe_score_pending_products'
    )) {
        return [
            'available' => false,
            'pending_product_count' => 0,
            'pending_recipe_count' => 0,
            'phase' => 'idle',
            'processed_recipe_count' => 0,
            'total_recipe_count' => 0,
            'oldest_at' => null,
            'oldest_age_seconds' => null,
            'latest_inventory_revision' => null,
            'last_revision_id' => null,
            'last_completed_at' => null,
        ];
    }
    $pending = $db->query("
        SELECT COUNT(*) AS pending_product_count,
               MIN(created_at) AS oldest_at,
               MAX(latest_inventory_revision)
                   AS latest_inventory_revision
        FROM recipe_score_pending_products
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $pendingRecipes = evershelfProcessingStatusTableExists(
        $db,
        'recipe_score_pending_recipes'
    ) ? (
        $db->query("
            SELECT COUNT(*) AS pending_recipe_count,
                   MIN(created_at) AS oldest_at,
                   MAX(latest_catalog_revision)
                       AS latest_catalog_revision
            FROM recipe_score_pending_recipes
        ")->fetch(PDO::FETCH_ASSOC) ?: []
    ) : [];
    $work = evershelfProcessingStatusTableExists(
        $db,
        'recipe_score_work_state'
    ) ? (
        $db->query("
            SELECT phase, revision_id, parent_revision_id,
                   total_recipe_count, processed_recipe_count,
                   pending_product_count, pending_recipe_count,
                   last_error, started_at, updated_at
            FROM recipe_score_work_state
            WHERE id = 1
        ")->fetch(PDO::FETCH_ASSOC) ?: []
    ) : [];
    $latest = $db->query("
        SELECT id, completed_at
        FROM recipe_score_revisions
        WHERE status = 'ready'
          AND json_extract(
              validation_report_json,
              '$.version'
          ) = 'identity-annex-incremental-score-v1'
        ORDER BY id DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC) ?: null;
    $oldestAt = evershelfProcessingStatusEarliest(
        trim((string)($pending['oldest_at'] ?? '')) ?: null,
        trim((string)($pendingRecipes['oldest_at'] ?? '')) ?: null,
        in_array(
            (string)($work['phase'] ?? 'idle'),
            ['preparing', 'scoring', 'publishing', 'compacting'],
            true
        ) ? ($work['started_at'] ?? null) : null
    );
    return [
        'available' => true,
        'pending_product_count' =>
            (int)($pending['pending_product_count'] ?? 0),
        'pending_recipe_count' =>
            (int)($pendingRecipes['pending_recipe_count'] ?? 0),
        'phase' => (string)($work['phase'] ?? 'idle'),
        'revision_id' => ($work['revision_id'] ?? null) !== null
            ? (int)$work['revision_id']
            : null,
        'parent_revision_id' =>
            ($work['parent_revision_id'] ?? null) !== null
                ? (int)$work['parent_revision_id']
                : null,
        'processed_recipe_count' =>
            (int)($work['processed_recipe_count'] ?? 0),
        'total_recipe_count' =>
            (int)($work['total_recipe_count'] ?? 0),
        'progress_percent' =>
            (int)($work['total_recipe_count'] ?? 0) > 0
                ? round(
                    100
                    * (int)($work['processed_recipe_count'] ?? 0)
                    / (int)$work['total_recipe_count'],
                    1
                )
                : 0.0,
        'work_started_at' => $work['started_at'] ?? null,
        'work_updated_at' => $work['updated_at'] ?? null,
        'last_error' => trim(
            (string)($work['last_error'] ?? '')
        ) ?: null,
        'oldest_at' => $oldestAt,
        'oldest_age_seconds' =>
            evershelfProcessingStatusAgeSeconds($oldestAt),
        'latest_inventory_revision' =>
            ($pending['latest_inventory_revision'] ?? null) !== null
                ? (int)$pending['latest_inventory_revision']
                : null,
        'latest_catalog_revision' =>
            ($pendingRecipes['latest_catalog_revision'] ?? null) !== null
                ? (int)$pendingRecipes['latest_catalog_revision']
                : null,
        'last_revision_id' => $latest !== null
            ? (int)$latest['id']
            : null,
        'last_completed_at' => $latest['completed_at'] ?? null,
    ];
}

function evershelfProcessingStatus(PDO $db): array {
    $recipeQueue = evershelfProcessingStatusRecipeQueue($db);
    $canonicalQueue =
        evershelfProcessingStatusCanonicalQueue($db);
    $ontologyQueue = evershelfProcessingStatusOntologyQueue($db);
    $scores = evershelfProcessingStatusScores($db);
    $activation = evershelfProcessingStatusActivation($db);
    $coverage =
        evershelfProcessingStatusRecipeOntologyCoverage($db);
    $identity = evershelfProcessingStatusIdentityAnnex($db);
    $incremental =
        evershelfProcessingStatusIncrementalScores($db);
    $logging = class_exists('EverLog', false)
        ? EverLog::status()
        : [
            'healthy' => false,
            'writable' => false,
            'last_error' => 'logger_unavailable',
        ];

    $providerProblem = $ontologyQueue['runtime_enabled']
        && $ontologyQueue['intake_open_count'] > 0
        && !$ontologyQueue['provider']['healthy'];
    $activationProblem = $activation['failure_count'] > 0
        && $activation['last_error'] !== null;
    $incrementalProblem =
        (string)($incremental['phase'] ?? '') === 'failed'
        && $incremental['last_error'] !== null;
    $canonicalProblem =
        (int)$canonicalQueue['overdue_lease_count'] > 0
        || (int)$canonicalQueue['failed_24h_count'] > 0
        || (int)$canonicalQueue['exhausted_pending_count'] > 0
        || (int)$canonicalQueue['stale_due_count'] > 0
        || (
            (int)$canonicalQueue['retry_due_count'] > 0
            && empty($canonicalQueue['lock_available'])
        );
    $coverageAdvisory = $ontologyQueue['runtime_enabled']
        && $coverage['available']
        && $coverage['missing_recipe_count'] > 0;
    $currentImport = $activation['current_import'];
    $problem = !$logging['healthy']
        || $providerProblem
        || $activationProblem
        || $incrementalProblem
        || $canonicalProblem
        || $recipeQueue['failed_24h_count'] > 0
        || $ontologyQueue['failed_24h_count'] > 0
        || (
            is_array($currentImport)
            && in_array(
                $currentImport['status'],
                ['failed'],
                true
            )
        );

    $workPhase = evershelfProcessingStatusWorkPhase(
        $recipeQueue,
        $ontologyQueue,
        $scores,
        $activation,
        $incremental,
        $canonicalQueue
    );
    $phase = $workPhase === 'idle' && $problem
        ? 'degraded'
        : $workPhase;

    $oldestAt = evershelfProcessingStatusEarliest(
        $recipeQueue['oldest_at'],
        $canonicalQueue['oldest_active_at'],
        $ontologyQueue['oldest_at'],
        $scores['stale'] ? $scores['dirty_at'] : null,
        $activation['running'] ? (
            $activation['requested_at']
                ?? $activation['current_import']['created_at']
                ?? null
        ) : null,
        $ontologyQueue['generation_intent_due_oldest_at'] ?? null,
        $incremental['oldest_at'] ?? null
    );
    $pending = [
        'total' =>
            $recipeQueue['open_count']
            + $canonicalQueue['open_count']
            + $ontologyQueue['intake_open_count']
            + $ontologyQueue['generation_open_count']
            + (int)(
                $ontologyQueue['generation_intent_due_count'] ?? 0
            )
            + ($scores['stale'] ? 1 : 0)
            + (int)$incremental['pending_product_count']
            + (int)$incremental['pending_recipe_count'],
        'recipe_jobs' => $recipeQueue['open_count'],
        'canonical_queue' => $canonicalQueue['open_count'],
        'canonical_due' => $canonicalQueue['retry_due_count'],
        'ontology_intake_jobs' =>
            $ontologyQueue['intake_open_count'],
        'ontology_generation_jobs' =>
            $ontologyQueue['generation_open_count'],
        'ontology_deferred_jobs' =>
            $ontologyQueue['deferred_count'],
        'ontology_generation_intents' =>
            (int)($ontologyQueue[
                'generation_intent_due_count'
            ] ?? 0),
        'ontology_policy_deferred_intents' =>
            (int)($ontologyQueue[
                'generation_intent_policy_deferred_count'
            ] ?? 0),
        'identity_coverage_gaps' =>
            (int)($ontologyQueue['coverage_gap_open_count'] ?? 0),
        'recipes_missing_observation' =>
            $ontologyQueue['runtime_enabled']
                ? (int)$coverage['missing_recipe_count']
                : 0,
        'score_publication' => $scores['stale'] ? 1 : 0,
        'score_products' =>
            (int)$incremental['pending_product_count'],
        'score_recipes' =>
            (int)$incremental['pending_recipe_count'],
    ];
    $lastError = $canonicalProblem
        ? $canonicalQueue['last_error']
        : null;
    $lastError ??= $incremental['last_error']
        ?? $activation['last_error']
        ?? $logging['last_error']
        ?? null;

    return [
        'schema_version' => EVERSHELF_PROCESSING_STATUS_SCHEMA,
        'observed_at' => gmdate('c'),
        'phase' => $phase,
        'active' => $workPhase !== 'idle',
        'problem' => $problem,
        'pending' => $pending,
        'oldest_at' => $oldestAt,
        'oldest_age_seconds' =>
            evershelfProcessingStatusAgeSeconds($oldestAt),
        'last_error' => $lastError !== null
            ? mb_substr((string)$lastError, 0, 300, 'UTF-8')
            : null,
        'recipe_queue' => $recipeQueue,
        'canonical_queue' => $canonicalQueue,
        'ontology_queue' => $ontologyQueue,
        'recipe_scores' => $scores,
        'incremental_scores' => $incremental,
        'identity_admission' => $identity,
        'activation' => $activation,
        'recipe_source_ontology' => $coverage,
        'logging' => [
            'healthy' => !empty($logging['healthy']),
            'writable' => !empty($logging['writable']),
            'last_error' => $logging['last_error'] ?? null,
        ],
        'advisories' => [
            'unresolved_inventory_identities' =>
                (int)($identity['unresolved_count'] ?? 0),
            'recipe_identity_coverage_missing' =>
                $coverageAdvisory
                    ? (int)$coverage['missing_recipe_count']
                    : 0,
            'identity_coverage_gaps' =>
                (int)($ontologyQueue['coverage_gap_open_count'] ?? 0),
            'policy_deferred_intents' =>
                (int)($ontologyQueue[
                    'generation_intent_policy_deferred_count'
                ] ?? 0),
        ],
    ];
}

function evershelfProcessingStatusApi(PDO $db): void {
    echo json_encode(
        [
            'success' => true,
            'status' => evershelfProcessingStatus($db),
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}
