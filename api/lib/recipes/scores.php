<?php

final class RecipeScoreUnavailableException extends RuntimeException {
}

const RECIPE_SCORE_LEGACY_READY_RETENTION = 2;
const RECIPE_SCORE_V3_ROLLBACK_ANCESTOR_LIMIT = 8;
const RECIPE_SCORE_V3_SPARSE_ANCESTOR_LIMIT = 256;
const RECIPE_SCORE_V3_READY_HISTORY_LIMIT = 4;
const RECIPE_SCORE_REQUIREMENT_SHADOW_RETENTION = 2;

function recipeScoreTimezone(
    ?string $timezone = null
): DateTimeZone {
    $timezone = trim($timezone ?? (
        function_exists('env')
            ? env('TZ', 'UTC')
            : (string)(getenv('TZ') ?: 'UTC')
    ));
    if ($timezone === '') {
        $timezone = 'UTC';
    }
    try {
        $zone = new DateTimeZone($timezone);
    } catch (Throwable $error) {
        throw new RuntimeException(
            'Invalid recipe score timezone: ' . $timezone,
            0,
            $error
        );
    }
    return $zone;
}

function recipeScoreCurrentDate(
    ?DateTimeInterface $instant = null,
    ?string $timezone = null
): string {
    $zone = recipeScoreTimezone($timezone);
    $date = $instant !== null
        ? DateTimeImmutable::createFromInterface($instant)
        : new DateTimeImmutable('now', $zone);
    return $date->setTimezone($zone)->format('Y-m-d');
}

function recipeScoreWithWriteRetry(callable $callback): mixed {
    return function_exists('dbWithRetry') ? dbWithRetry($callback) : $callback();
}

function recipeScoreState(PDO $db): array {
    $row = $db->query("SELECT * FROM recipe_score_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        recipeScoreWithWriteRetry(
            static fn(): int => $db->exec(
                "INSERT OR IGNORE INTO recipe_score_state (id) VALUES (1)"
            )
        );
        $row = $db->query("SELECT * FROM recipe_score_state WHERE id = 1")
            ->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) {
        throw new RuntimeException('Recipe score state is unavailable');
    }
    $row['inventory_revision'] = (int)$row['inventory_revision'];
    $row['catalog_revision'] = (int)($row['catalog_revision'] ?? 1);
    $row['cursor_revision'] = (int)($row['cursor_revision'] ?? 1);
    $row['ontology_source_revision'] = (int)(
        $row['ontology_source_revision'] ?? 1
    );
    $row['ontology_source_hash'] = (string)(
        $row['ontology_source_hash'] ?? ''
    );
    $row['ontology_source_lineage_hash'] = (string)(
        $row['ontology_source_lineage_hash'] ?? ''
    );
    $row['active_score_revision_id'] = $row['active_score_revision_id'] !== null
        ? (int)$row['active_score_revision_id']
        : null;
    $row['active_score_overlay_revision_id'] =
        isset($row['active_score_overlay_revision_id'])
        && $row['active_score_overlay_revision_id'] !== null
            ? (int)$row['active_score_overlay_revision_id']
            : null;
    $row['active_score_projection_revision_id'] =
        isset($row['active_score_projection_revision_id'])
        && $row['active_score_projection_revision_id'] !== null
            ? (int)$row['active_score_projection_revision_id']
            : null;
    return $row;
}

function recipeScoreSetWorkState(
    PDO $db,
    string $phase,
    ?int $revisionId = null,
    ?int $parentRevisionId = null,
    int $totalRecipeCount = 0,
    int $processedRecipeCount = 0,
    int $pendingProductCount = 0,
    int $pendingRecipeCount = 0,
    string $lastError = ''
): void {
    if (!in_array($phase, [
        'idle', 'preparing', 'scoring',
        'publishing', 'compacting', 'failed',
    ], true)) {
        throw new InvalidArgumentException(
            'recipe score work phase is invalid'
        );
    }
    $totalRecipeCount = max(0, $totalRecipeCount);
    $processedRecipeCount = max(
        0,
        min($totalRecipeCount, $processedRecipeCount)
    );
    $db->prepare("
        INSERT INTO recipe_score_work_state (
            id, phase, revision_id, parent_revision_id,
            total_recipe_count, processed_recipe_count,
            pending_product_count, pending_recipe_count,
            last_error, started_at, updated_at
        )
        VALUES (
            1, ?, ?, ?, ?, ?, ?, ?, ?,
            CASE WHEN ? = 'idle' THEN NULL ELSE CURRENT_TIMESTAMP END,
            CURRENT_TIMESTAMP
        )
        ON CONFLICT(id) DO UPDATE SET
            phase = excluded.phase,
            revision_id = excluded.revision_id,
            parent_revision_id = excluded.parent_revision_id,
            total_recipe_count = excluded.total_recipe_count,
            processed_recipe_count = excluded.processed_recipe_count,
            pending_product_count = excluded.pending_product_count,
            pending_recipe_count = excluded.pending_recipe_count,
            last_error = excluded.last_error,
            started_at = CASE
                WHEN recipe_score_work_state.phase = 'idle'
                 AND excluded.phase <> 'idle'
                THEN CURRENT_TIMESTAMP
                WHEN excluded.phase = 'idle' THEN NULL
                ELSE recipe_score_work_state.started_at
            END,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([
        $phase,
        $revisionId,
        $parentRevisionId,
        $totalRecipeCount,
        $processedRecipeCount,
        max(0, $pendingProductCount),
        max(0, $pendingRecipeCount),
        mb_substr($lastError, 0, 1000, 'UTF-8'),
        $phase,
    ]);
}

function recipeScoreReconcileWorkState(PDO $db): void {
    $work = $db->query("
        SELECT phase
        FROM recipe_score_work_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((string)($work['phase'] ?? 'idle') === 'idle') {
        return;
    }
    $pendingProducts = (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_pending_products
    ")->fetchColumn();
    $pendingServingRecipes = (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_pending_recipes
        WHERE lane = 'serving'
    ")->fetchColumn();
    $active = recipeScoreActiveRevision($db);
    $activeStatus = $active !== null
        ? recipeScoreRevisionStatus($db, $active)
        : 'stale';
    if (
        $pendingProducts === 0
        && $pendingServingRecipes === 0
        && $active !== null
        && in_array($activeStatus, ['fresh', 'partial'], true)
    ) {
        recipeScoreSetWorkState(
            $db,
            'idle',
            (int)$active['id'],
            $active['parent_score_revision_id'] !== null
                ? (int)$active['parent_score_revision_id']
                : null,
            (int)$active['recipe_count'],
            (int)$active['recipe_count'],
            0,
            0
        );
    }
}

function recipeScoreRevisionReport(array $revision): array {
    $report = json_decode(
        (string)($revision['validation_report_json'] ?? '{}'),
        true
    );
    return is_array($report) ? $report : [];
}

function recipeScoreRevisionIsSparseDelta(array $revision): bool {
    return (string)(
        recipeScoreRevisionReport($revision)[
            'materialized_hash_algorithm'
        ] ?? ''
    ) === 'parent-delta-v2';
}

function recipeScoreEffectiveProjectionReady(
    PDO $db,
    int $revisionId,
    ?array $state = null
): bool {
    if ($revisionId <= 0) {
        return false;
    }
    $state ??= recipeScoreState($db);
    return (int)(
        $state['active_score_projection_revision_id'] ?? 0
    ) === $revisionId;
}

function recipeScoreBuildEffectiveProjection(
    PDO $db,
    int $revisionId
): int {
    $target = recipeScoreRevision($db, $revisionId);
    if ($target === null) {
        throw new RuntimeException(
            'score projection target revision is unavailable'
        );
    }
    $chain = [];
    $base = $target;
    $seen = [];
    while (recipeScoreRevisionIsSparseDelta($base)) {
        $baseId = (int)$base['id'];
        if (isset($seen[$baseId]) || count($chain) >= 256) {
            throw new RuntimeException(
                'score projection delta ancestry is invalid'
            );
        }
        $seen[$baseId] = true;
        $chain[] = $base;
        $parentId = (int)($base['parent_score_revision_id'] ?? 0);
        $base = recipeScoreRevision($db, $parentId);
        if ($base === null) {
            throw new RuntimeException(
                'score projection delta parent is unavailable'
            );
        }
    }
    $baseCount = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_inventory_scores
        WHERE score_revision_id = ?
    ");
    $baseCount->execute([(int)$base['id']]);
    if ((int)$baseCount->fetchColumn() !== (int)$base['recipe_count']) {
        throw new RuntimeException(
            'score projection base materialization is incomplete'
        );
    }
    $db->exec("DELETE FROM recipe_score_effective_sources");
    $insertBase = $db->prepare("
        INSERT INTO recipe_score_effective_sources (
            recipe_id, score_revision_id, updated_at
        )
        SELECT recipe_id, ?, CURRENT_TIMESTAMP
        FROM recipe_inventory_scores
        WHERE score_revision_id = ?
        ORDER BY recipe_id
    ");
    $insertBase->execute([(int)$base['id'], (int)$base['id']]);

    foreach (array_reverse($chain) as $delta) {
        $deltaId = (int)$delta['id'];
        $delete = $db->prepare("
            DELETE FROM recipe_score_effective_sources
            WHERE recipe_id IN (
                SELECT recipe_id
                FROM recipe_score_recipe_operations
                WHERE score_revision_id = ?
                  AND operation = 'delete'
            )
        ");
        $delete->execute([$deltaId]);
        $replace = $db->prepare("
            INSERT INTO recipe_score_effective_sources (
                recipe_id, score_revision_id, updated_at
            )
            SELECT operation.recipe_id, ?, CURRENT_TIMESTAMP
            FROM recipe_score_recipe_operations operation
            JOIN recipe_inventory_scores score
              ON score.score_revision_id = operation.score_revision_id
             AND score.recipe_id = operation.recipe_id
            WHERE operation.score_revision_id = ?
              AND operation.operation = 'replace'
            ON CONFLICT(recipe_id) DO UPDATE SET
                score_revision_id = excluded.score_revision_id,
                updated_at = CURRENT_TIMESTAMP
        ");
        $replace->execute([$deltaId, $deltaId]);
        $missing = $db->prepare("
            SELECT COUNT(*)
            FROM recipe_score_recipe_operations operation
            LEFT JOIN recipe_inventory_scores score
              ON score.score_revision_id = operation.score_revision_id
             AND score.recipe_id = operation.recipe_id
            WHERE operation.score_revision_id = ?
              AND operation.operation = 'replace'
              AND score.recipe_id IS NULL
        ");
        $missing->execute([$deltaId]);
        if ((int)$missing->fetchColumn() !== 0) {
            throw new RuntimeException(
                'score projection delta replacement is incomplete'
            );
        }
    }
    $projectionCount = (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_effective_sources
    ")->fetchColumn();
    if ($projectionCount !== (int)$target['recipe_count']) {
        throw new RuntimeException(
            'score projection recipe count is incomplete'
        );
    }
    $missingSources = (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_score_effective_sources source
        LEFT JOIN recipe_inventory_scores score
          ON score.score_revision_id = source.score_revision_id
         AND score.recipe_id = source.recipe_id
        WHERE score.recipe_id IS NULL
    ")->fetchColumn();
    if ($missingSources !== 0) {
        throw new RuntimeException(
            'score projection contains a missing score source'
        );
    }
    $db->prepare("
        UPDATE recipe_score_state
        SET active_score_projection_revision_id = ?
        WHERE id = 1
    ")->execute([$revisionId]);
    return $projectionCount;
}

function recipeScoreApplyDeltaProjection(
    PDO $db,
    int $parentRevisionId,
    int $revisionId
): int {
    $state = recipeScoreState($db);
    if (
        (int)($state['active_score_projection_revision_id'] ?? 0)
            !== $parentRevisionId
    ) {
        throw new RuntimeException(
            'active score projection parent changed'
        );
    }
    $revision = recipeScoreRevision($db, $revisionId);
    if ($revision === null || !recipeScoreRevisionIsSparseDelta($revision)) {
        throw new RuntimeException(
            'score projection child is not a sparse delta'
        );
    }
    $missing = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_score_recipe_operations operation
        LEFT JOIN recipe_inventory_scores score
          ON score.score_revision_id = operation.score_revision_id
         AND score.recipe_id = operation.recipe_id
        WHERE operation.score_revision_id = ?
          AND operation.operation = 'replace'
          AND score.recipe_id IS NULL
    ");
    $missing->execute([$revisionId]);
    if ((int)$missing->fetchColumn() !== 0) {
        throw new RuntimeException(
            'score delta contains an incomplete replacement'
        );
    }
    $unexpected = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_inventory_scores score
        LEFT JOIN recipe_score_recipe_operations operation
          ON operation.score_revision_id = score.score_revision_id
         AND operation.recipe_id = score.recipe_id
         AND operation.operation = 'replace'
        WHERE score.score_revision_id = ?
          AND operation.recipe_id IS NULL
    ");
    $unexpected->execute([$revisionId]);
    if ((int)$unexpected->fetchColumn() !== 0) {
        throw new RuntimeException(
            'score delta contains an untracked replacement'
        );
    }
    $delete = $db->prepare("
        DELETE FROM recipe_score_effective_sources
        WHERE recipe_id IN (
            SELECT recipe_id
            FROM recipe_score_recipe_operations
            WHERE score_revision_id = ?
              AND operation = 'delete'
        )
    ");
    $delete->execute([$revisionId]);
    $replace = $db->prepare("
        INSERT INTO recipe_score_effective_sources (
            recipe_id, score_revision_id, updated_at
        )
        SELECT recipe_id, ?, CURRENT_TIMESTAMP
        FROM recipe_score_recipe_operations
        WHERE score_revision_id = ?
          AND operation = 'replace'
        ON CONFLICT(recipe_id) DO UPDATE SET
            score_revision_id = excluded.score_revision_id,
            updated_at = CURRENT_TIMESTAMP
    ");
    $replace->execute([$revisionId, $revisionId]);
    $projectionCount = (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_effective_sources
    ")->fetchColumn();
    if ($projectionCount !== (int)$revision['recipe_count']) {
        throw new RuntimeException(
            'score delta projection recipe count is incomplete'
        );
    }
    $db->prepare("
        UPDATE recipe_score_state
        SET active_score_projection_revision_id = ?
        WHERE id = 1
          AND active_score_projection_revision_id = ?
    ")->execute([$revisionId, $parentRevisionId]);
    if (
        !recipeScoreEffectiveProjectionReady(
            $db,
            $revisionId
        )
    ) {
        throw new RuntimeException(
            'score delta projection publication fence was lost'
        );
    }
    return $projectionCount;
}

function recipeScoreEnsureEffectiveProjection(
    PDO $db,
    array $active
): void {
    $activeId = (int)$active['id'];
    if (recipeScoreEffectiveProjectionReady($db, $activeId)) {
        return;
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $state = recipeScoreState($db);
        if ((int)($state['active_score_revision_id'] ?? 0) !== $activeId) {
            throw new RuntimeException(
                'active score changed before projection initialization'
            );
        }
        recipeScoreBuildEffectiveProjection($db, $activeId);
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
}

function recipeScoreReadUsesEffectiveProjection(
    PDO $db,
    array $read,
    array $revision
): bool {
    return empty($read['preview'])
        && (int)($read['active_revision']['id'] ?? 0)
            === (int)$revision['id']
        && recipeScoreEffectiveProjectionReady(
            $db,
            (int)$revision['id'],
            $read['state'] ?? null
        );
}

function recipeScoreEffectiveSourceRevisionId(
    PDO $db,
    int $recipeId,
    int $logicalRevisionId
): int {
    $state = recipeScoreState($db);
    if (
        !recipeScoreEffectiveProjectionReady(
            $db,
            $logicalRevisionId,
            $state
        )
        || (int)($state['active_score_revision_id'] ?? 0)
            !== $logicalRevisionId
    ) {
        return $logicalRevisionId;
    }
    $source = $db->prepare("
        SELECT score_revision_id
        FROM recipe_score_effective_sources
        WHERE recipe_id = ?
    ");
    $source->execute([$recipeId]);
    $sourceRevisionId = (int)($source->fetchColumn() ?: 0);
    if ($sourceRevisionId <= 0) {
        return $logicalRevisionId;
    }
    return $sourceRevisionId;
}

function recipeScoreMarkContributorRevisionComplete(
    PDO $db,
    int $revisionId
): array {
    $matchCount = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_shadow_matches
        WHERE score_revision_id = ?
    ");
    $matchCount->execute([$revisionId]);
    $contributorCount = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_score_match_contributors
        WHERE score_revision_id = ?
    ");
    $contributorCount->execute([$revisionId]);
    $counts = [
        'match_count' => (int)$matchCount->fetchColumn(),
        'contributor_count' =>
            (int)$contributorCount->fetchColumn(),
    ];
    $db->prepare("
        INSERT INTO recipe_score_contributor_revisions (
            score_revision_id, match_count,
            contributor_count, completed_at
        )
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(score_revision_id) DO UPDATE SET
            match_count = excluded.match_count,
            contributor_count = excluded.contributor_count,
            completed_at = CURRENT_TIMESTAMP
    ")->execute([
        $revisionId,
        $counts['match_count'],
        $counts['contributor_count'],
    ]);
    return $counts;
}

function recipeScoreContributorRevisionComplete(
    PDO $db,
    int $revisionId
): bool {
    $complete = $db->prepare("
        SELECT 1
        FROM recipe_score_contributor_revisions
        WHERE score_revision_id = ?
    ");
    $complete->execute([$revisionId]);
    return (bool)$complete->fetchColumn();
}

function recipeScoreBackfillContributorRevision(
    PDO $db,
    int $revisionId
): array {
    if (recipeScoreContributorRevisionComplete($db, $revisionId)) {
        $counts = $db->prepare("
            SELECT match_count, contributor_count
            FROM recipe_score_contributor_revisions
            WHERE score_revision_id = ?
        ");
        $counts->execute([$revisionId]);
        $row = $counts->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'backfilled' => false,
            'match_count' => (int)($row['match_count'] ?? 0),
            'contributor_count' =>
                (int)($row['contributor_count'] ?? 0),
        ];
    }
    $db->beginTransaction();
    try {
        $direct = $db->prepare("
            INSERT OR IGNORE INTO recipe_score_match_contributors (
                score_revision_id, recipe_ingredient_id,
                recipe_id, product_id, semantic
            )
            SELECT score_revision_id, recipe_ingredient_id,
                   recipe_id, inventory_product_id,
                   CASE
                       WHEN outcome IN (
                           'exact', 'compatible_variant',
                           'possible_substitute',
                           'insufficient_quantity', 'staple'
                       )
                       THEN 1 ELSE 0
                   END
            FROM ingredient_ontology_shadow_matches
            WHERE score_revision_id = ?
              AND inventory_product_id IS NOT NULL
              AND inventory_product_id > 0
        ");
        $direct->execute([$revisionId]);
        $aggregate = $db->prepare("
            INSERT OR IGNORE INTO recipe_score_match_contributors (
                score_revision_id, recipe_ingredient_id,
                recipe_id, product_id, semantic
            )
            SELECT match.score_revision_id,
                   match.recipe_ingredient_id,
                   match.recipe_id,
                   CAST(contributor.value AS INTEGER),
                   CASE
                       WHEN match.outcome IN (
                           'exact', 'compatible_variant',
                           'possible_substitute',
                           'insufficient_quantity', 'staple'
                       )
                       THEN 1 ELSE 0
                   END
            FROM ingredient_ontology_shadow_matches match
            JOIN json_each(
                CASE
                    WHEN json_valid(match.explanation_json)
                    THEN match.explanation_json
                    ELSE '{}'
                END,
                '$.inventory_aggregate.product_ids'
            ) contributor
            WHERE match.score_revision_id = ?
              AND contributor.type IN ('integer', 'text')
              AND CAST(contributor.value AS INTEGER) > 0
        ");
        $aggregate->execute([$revisionId]);
        $counts = recipeScoreMarkContributorRevisionComplete(
            $db,
            $revisionId
        );
        $db->commit();
        return ['backfilled' => true] + $counts;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function recipeScoreEnsureEffectiveContributors(
    PDO $db,
    int $activeRevisionId
): array {
    if (
        !recipeScoreEffectiveProjectionReady(
            $db,
            $activeRevisionId
        )
    ) {
        throw new RuntimeException(
            'effective score projection is unavailable for contributors'
        );
    }
    $revisionIds = array_map('intval', $db->query("
        SELECT DISTINCT score_revision_id
        FROM recipe_score_effective_sources
        ORDER BY score_revision_id
    ")->fetchAll(PDO::FETCH_COLUMN));
    $backfilled = 0;
    $contributors = 0;
    foreach ($revisionIds as $revisionId) {
        $result = recipeScoreBackfillContributorRevision(
            $db,
            $revisionId
        );
        if (!empty($result['backfilled'])) {
            $backfilled++;
        }
        $contributors += (int)$result['contributor_count'];
    }
    return [
        'revision_count' => count($revisionIds),
        'backfilled_revision_count' => $backfilled,
        'contributor_count' => $contributors,
    ];
}

function recipeScoreMarkDirty(PDO $db): int {
    recipeScoreState($db);
    $db->exec("
        UPDATE recipe_score_state SET
            inventory_revision = inventory_revision + 1,
            cursor_revision = cursor_revision + CASE
                WHEN active_score_overlay_revision_id IS NOT NULL
                THEN 1 ELSE 0 END,
            active_score_overlay_revision_id = NULL,
            dirty_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ");
    return recipeScoreState($db)['inventory_revision'];
}

function recipeScoreMarkProductDirty(
    PDO $db,
    int $productId,
    string $reason
): int {
    if ($productId <= 0) {
        throw new InvalidArgumentException(
            'recipe score dirty product is invalid'
        );
    }
    $inventoryRevision = recipeScoreMarkDirty($db);
    $active = recipeScoreActiveRevision($db);
    if (
        $active === null
        || (string)($active['scoring_model'] ?? '')
            !== 'faceted-ontology-v3'
        || $active['ontology_version_id'] === null
    ) {
        return $inventoryRevision;
    }
    $db->prepare("
        INSERT INTO recipe_score_pending_products (
            product_id, first_inventory_revision,
            latest_inventory_revision, reason,
            created_at, updated_at
        )
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON CONFLICT(product_id) DO UPDATE SET
            latest_inventory_revision = MAX(
                recipe_score_pending_products.latest_inventory_revision,
                excluded.latest_inventory_revision
            ),
            reason = excluded.reason,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([
        $productId,
        $inventoryRevision,
        $inventoryRevision,
        mb_substr(trim($reason), 0, 160, 'UTF-8'),
    ]);
    return $inventoryRevision;
}

function recipeScoreMarkProductsDirty(
    PDO $db,
    array $productIds,
    string $reason
): int {
    $productIds = array_values(array_unique(array_filter(
        array_map('intval', $productIds),
        static fn(int $id): bool => $id > 0
    )));
    if (!$productIds) {
        return recipeScoreState($db)['inventory_revision'];
    }
    $inventoryRevision = recipeScoreMarkDirty($db);
    $active = recipeScoreActiveRevision($db);
    if (
        $active === null
        || (string)($active['scoring_model'] ?? '')
            !== 'faceted-ontology-v3'
        || $active['ontology_version_id'] === null
    ) {
        return $inventoryRevision;
    }
    $insert = $db->prepare("
        INSERT INTO recipe_score_pending_products (
            product_id, first_inventory_revision,
            latest_inventory_revision, reason,
            created_at, updated_at
        )
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON CONFLICT(product_id) DO UPDATE SET
            latest_inventory_revision = MAX(
                recipe_score_pending_products.latest_inventory_revision,
                excluded.latest_inventory_revision
            ),
            reason = excluded.reason,
            updated_at = CURRENT_TIMESTAMP
    ");
    $reason = mb_substr(trim($reason), 0, 160, 'UTF-8');
    foreach ($productIds as $productId) {
        $insert->execute([
            $productId,
            $inventoryRevision,
            $inventoryRevision,
            $reason,
        ]);
    }
    return $inventoryRevision;
}

function recipeScoreClearPendingProducts(
    PDO $db,
    int $inventoryRevision,
    array $productIds = []
): int {
    $exists = $db->query("
        SELECT 1
        FROM sqlite_master
        WHERE type = 'table'
          AND name = 'recipe_score_pending_products'
    ")->fetchColumn();
    if (!$exists) {
        return 0;
    }
    $productIds = array_values(array_unique(array_filter(
        array_map('intval', $productIds),
        static fn(int $id): bool => $id > 0
    )));
    $productWhere = '';
    $params = [$inventoryRevision];
    if ($productIds) {
        $productWhere = ' AND product_id IN ('
            . implode(',', array_fill(0, count($productIds), '?'))
            . ')';
        array_push($params, ...$productIds);
    }
    $delete = $db->prepare("
        DELETE FROM recipe_score_pending_products
        WHERE latest_inventory_revision <= ?
        {$productWhere}
    ");
    $delete->execute($params);
    return $delete->rowCount();
}

function recipeScoreMarkRecipeDirty(
    PDO $db,
    int $recipeId,
    string $operation,
    string $reason,
    bool $cursorUnsafe = true,
    string $lane = 'serving'
): int {
    if ($recipeId <= 0) {
        throw new InvalidArgumentException(
            'recipe score dirty recipe is invalid'
        );
    }
    if (!in_array($operation, ['replace', 'delete'], true)) {
        throw new InvalidArgumentException(
            'recipe score dirty operation is invalid'
        );
    }
    if (!in_array($lane, ['serving', 'maintenance'], true)) {
        throw new InvalidArgumentException(
            'recipe score dirty lane is invalid'
        );
    }
    recipeScoreState($db);
    $db->prepare("
        UPDATE recipe_score_state SET
            catalog_revision = catalog_revision + 1,
            cursor_revision = cursor_revision + ?,
            active_score_overlay_revision_id = NULL,
            dirty_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ")->execute([$cursorUnsafe ? 1 : 0]);
    $state = recipeScoreState($db);
    $catalogRevision = (int)$state['catalog_revision'];
    $db->prepare("
        INSERT INTO recipe_score_mutations (
            domain, revision, lane, owner_type, owner_id,
            operation, reason
        )
        VALUES ('catalog', ?, ?, 'recipe', ?, ?, ?)
    ")->execute([
        $catalogRevision,
        $lane,
        $recipeId,
        $operation,
        mb_substr(trim($reason), 0, 160, 'UTF-8'),
    ]);
    $active = recipeScoreActiveRevision($db);
    if (
        $active === null
        || (string)($active['scoring_model'] ?? '')
            !== 'faceted-ontology-v3'
        || $active['ontology_version_id'] === null
    ) {
        return $catalogRevision;
    }
    $db->prepare("
        INSERT INTO recipe_score_pending_recipes (
            recipe_id, operation, lane, first_catalog_revision,
            latest_catalog_revision,
            latest_ontology_source_revision,
            reason, created_at, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON CONFLICT(recipe_id) DO UPDATE SET
            operation = excluded.operation,
            lane = CASE
                WHEN recipe_score_pending_recipes.lane = 'serving'
                  OR excluded.lane = 'serving'
                THEN 'serving'
                ELSE 'maintenance'
            END,
            latest_catalog_revision = MAX(
                recipe_score_pending_recipes.latest_catalog_revision,
                excluded.latest_catalog_revision
            ),
            latest_ontology_source_revision = MAX(
                recipe_score_pending_recipes
                    .latest_ontology_source_revision,
                excluded.latest_ontology_source_revision
            ),
            reason = excluded.reason,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([
        $recipeId,
        $operation,
        $lane,
        $catalogRevision,
        $catalogRevision,
        (int)$state['ontology_source_revision'],
        mb_substr(trim($reason), 0, 160, 'UTF-8'),
    ]);
    return $catalogRevision;
}

function recipeScoreMarkRecipesDirtyBatch(
    PDO $db,
    array $recipeIds,
    string $operation,
    string $reason,
    bool $cursorUnsafe = true,
    string $lane = 'maintenance'
): int {
    $recipeIds = array_values(array_unique(array_filter(
        array_map('intval', $recipeIds),
        static fn(int $recipeId): bool => $recipeId > 0
    )));
    if (!$recipeIds) {
        return (int)recipeScoreState($db)['catalog_revision'];
    }
    if (!in_array($operation, ['replace', 'delete'], true)) {
        throw new InvalidArgumentException(
            'recipe score dirty operation is invalid'
        );
    }
    if (!in_array($lane, ['serving', 'maintenance'], true)) {
        throw new InvalidArgumentException(
            'recipe score dirty lane is invalid'
        );
    }
    static $batchSequence = 0;
    $savepoint = 'recipe_score_dirty_batch_' . (++$batchSequence);
    $db->exec("SAVEPOINT {$savepoint}");
    try {
        recipeScoreState($db);
        $db->prepare("
        UPDATE recipe_score_state SET
            catalog_revision = catalog_revision + 1,
            cursor_revision = cursor_revision + ?,
            active_score_overlay_revision_id = NULL,
            dirty_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
        ")->execute([$cursorUnsafe ? 1 : 0]);
        $state = recipeScoreState($db);
        $catalogRevision = (int)$state['catalog_revision'];
        $reason = mb_substr(trim($reason), 0, 160, 'UTF-8');
        $db->prepare("
            INSERT INTO recipe_score_mutations (
                domain, revision, lane, owner_type, owner_id,
                operation, reason
            )
            VALUES (
                'catalog', ?, ?, 'global', NULL, 'global', ?
            )
        ")->execute([
            $catalogRevision,
            $lane,
            mb_substr($reason . '_batch', 0, 160, 'UTF-8'),
        ]);
        $active = recipeScoreActiveRevision($db);
        if (
            $active === null
            || (string)($active['scoring_model'] ?? '')
                !== 'faceted-ontology-v3'
            || $active['ontology_version_id'] === null
        ) {
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
            return $catalogRevision;
        }
        $insert = $db->prepare("
            INSERT INTO recipe_score_pending_recipes (
                recipe_id, operation, lane,
                first_catalog_revision, latest_catalog_revision,
                latest_ontology_source_revision, reason,
                created_at, updated_at
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            ON CONFLICT(recipe_id) DO UPDATE SET
                operation = excluded.operation,
                lane = CASE
                    WHEN recipe_score_pending_recipes.lane = 'serving'
                      OR excluded.lane = 'serving'
                    THEN 'serving'
                    ELSE 'maintenance'
                END,
                latest_catalog_revision = MAX(
                    recipe_score_pending_recipes.latest_catalog_revision,
                    excluded.latest_catalog_revision
                ),
                latest_ontology_source_revision = MAX(
                    recipe_score_pending_recipes
                        .latest_ontology_source_revision,
                    excluded.latest_ontology_source_revision
                ),
                reason = excluded.reason,
                updated_at = CURRENT_TIMESTAMP
        ");
        foreach ($recipeIds as $recipeId) {
            $insert->execute([
                $recipeId,
                $operation,
                $lane,
                $catalogRevision,
                $catalogRevision,
                (int)$state['ontology_source_revision'],
                $reason,
            ]);
        }
        $db->exec("RELEASE SAVEPOINT {$savepoint}");
        return $catalogRevision;
    } catch (Throwable $error) {
        try {
            $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
}

function recipeScoreClearPendingRecipes(
    PDO $db,
    int $catalogRevision,
    int $ontologySourceRevision,
    array $recipeIds = []
): int {
    $recipeIds = array_values(array_unique(array_filter(
        array_map('intval', $recipeIds),
        static fn(int $id): bool => $id > 0
    )));
    $recipeWhere = '';
    $params = [$catalogRevision, $ontologySourceRevision];
    if ($recipeIds) {
        $recipeWhere = ' AND recipe_id IN ('
            . implode(',', array_fill(0, count($recipeIds), '?'))
            . ')';
        array_push($params, ...$recipeIds);
    }
    $delete = $db->prepare("
        DELETE FROM recipe_score_pending_recipes
        WHERE latest_catalog_revision <= ?
          AND latest_ontology_source_revision <= ?
          {$recipeWhere}
    ");
    $delete->execute($params);
    return $delete->rowCount();
}

function recipeScoreMarkCatalogDirty(PDO $db, bool $cursorUnsafe = false): int {
    recipeScoreState($db);
    $db->prepare("
        UPDATE recipe_score_state SET
            catalog_revision = catalog_revision + 1,
            cursor_revision = cursor_revision + CASE
                WHEN active_score_overlay_revision_id IS NOT NULL
                THEN 1 ELSE ? END,
            active_score_overlay_revision_id = NULL,
            dirty_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
    ")->execute([$cursorUnsafe ? 1 : 0]);
    $revision = recipeScoreState($db)['catalog_revision'];
    $db->prepare("
        INSERT INTO recipe_score_mutations (
            domain, revision, lane, owner_type, owner_id,
            operation, reason
        )
        VALUES (
            'catalog', ?, 'maintenance', 'global', NULL, 'global',
            'unscoped_catalog_mutation'
        )
    ")->execute([$revision]);
    return $revision;
}

function recipeScoreInvalidateCursors(PDO $db): int {
    recipeScoreState($db);
    $db->exec("
        UPDATE recipe_score_state
        SET cursor_revision = cursor_revision + 1
        WHERE id = 1
    ");
    return recipeScoreState($db)['cursor_revision'];
}

function recipeScoreCatalogMaxId(PDO $db): int {
    return (int)$db->query("
        SELECT COALESCE(MAX(id), 0)
        FROM recipe_catalog
        WHERE deleted_at IS NULL
    ")->fetchColumn();
}

function recipeScoreCatalogFingerprint(PDO $db): string {
    $hash = hash_init('sha256');
    $queries = [
        'recipe_catalog' => "
            SELECT id, primary_connector, deleted_at
            FROM recipe_catalog
            ORDER BY id
        ",
        'recipe_ingredients' => "
            SELECT ri.id, ri.recipe_id, ri.position, ri.raw_text,
                   ri.normalized_name, ri.quantity, ri.quantity_text, ri.unit,
                   ri.is_required, ri.is_optional, ri.is_staple,
                   ri.source_is_required, ri.source_is_optional,
                   ri.requiredness_source,
                   ri.canonical_ingredient_id, ri.taxonomy_node_id,
                   ri.mapping_confidence, ri.mapping_source
            FROM recipe_ingredients ri
            JOIN recipe_catalog c ON c.id = ri.recipe_id
            WHERE c.deleted_at IS NULL
            ORDER BY ri.id
        ",
        'recipe_user_state' => "
            SELECT us.recipe_id, us.favorite, us.rating
            FROM recipe_user_state us
            JOIN recipe_catalog c ON c.id = us.recipe_id
            WHERE c.deleted_at IS NULL
            ORDER BY us.recipe_id
        ",
    ];
    foreach ($queries as $name => $sql) {
        hash_update($hash, $name . "\n");
        $stmt = $db->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            hash_update(
                $hash,
                recipeCatalogJsonEncode(recipeCatalogStableValue($row)) . "\n"
            );
        }
    }
    return hash_final($hash);
}

function recipeScoreCatalogRecipeFingerprint(
    PDO $db,
    array $recipeIds
): string {
    $recipeIds = array_values(array_unique(array_filter(
        array_map('intval', $recipeIds),
        static fn(int $id): bool => $id > 0
    )));
    if (!$recipeIds) {
        return hash('sha256', '');
    }
    $placeholders = implode(
        ',',
        array_fill(0, count($recipeIds), '?')
    );
    $hash = hash_init('sha256');
    foreach ([
        'recipe_catalog' => "
            SELECT id, primary_connector, deleted_at
            FROM recipe_catalog
            WHERE id IN ({$placeholders})
            ORDER BY id
        ",
        'recipe_ingredients' => "
            SELECT id, recipe_id, position, raw_text,
                   normalized_name, quantity, quantity_text, unit,
                   is_required, is_optional, is_staple,
                   source_is_required, source_is_optional,
                   requiredness_source,
                   canonical_ingredient_id, taxonomy_node_id,
                   mapping_confidence, mapping_source
            FROM recipe_ingredients
            WHERE recipe_id IN ({$placeholders})
            ORDER BY id
        ",
        'recipe_user_state' => "
            SELECT recipe_id, favorite, rating
            FROM recipe_user_state
            WHERE recipe_id IN ({$placeholders})
            ORDER BY recipe_id
        ",
    ] as $name => $sql) {
        hash_update($hash, $name . "\n");
        $stmt = $db->prepare($sql);
        $stmt->execute($recipeIds);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            hash_update(
                $hash,
                recipeCatalogJsonEncode(
                    recipeCatalogStableValue($row)
                ) . "\n"
            );
        }
    }
    return hash_final($hash);
}

function recipeScoreInventoryFingerprint(array $inventoryCandidates): string {
    $rows = [];
    foreach ($inventoryCandidates as $candidate) {
        $mappings = [];
        foreach (($candidate['mappings'] ?? []) as $mapping) {
            $mappings[] = [
                'role' => (string)($mapping['role'] ?? ''),
                'confidence' => round((float)($mapping['confidence'] ?? 0), 6),
                'product_mapping_id' => isset($mapping['product_mapping_id'])
                    ? (int)$mapping['product_mapping_id']
                    : null,
                'mapping_source' =>
                    (string)($mapping['mapping_source'] ?? ''),
                'canonical_ingredient_id' => isset($mapping['canonical_ingredient_id'])
                    ? (int)$mapping['canonical_ingredient_id']
                    : null,
                'taxonomy_node_id' => isset($mapping['taxonomy_node_id'])
                    ? (int)$mapping['taxonomy_node_id']
                    : null,
            ];
        }
        $rows[] = [
            'inventory_id' => (int)$candidate['inventory_id'],
            'product_id' => (int)$candidate['product_id'],
            'quantity' => round((float)$candidate['quantity'], 6),
            'unit' => (string)$candidate['unit'],
            'default_quantity' => round((float)($candidate['default_quantity'] ?? 0), 6),
            'package_unit' => (string)($candidate['package_unit'] ?? ''),
            'effective_expiry_date' => $candidate['effective_expiry_date'] ?? null,
            'normalized_name' => (string)($candidate['normalized_name'] ?? ''),
            'mappings' => $mappings,
        ];
    }
    return hash('sha256', recipeCatalogJsonEncode(recipeCatalogStableValue($rows)));
}

function recipeScoreRevision(PDO $db, int $revisionId): ?array {
    if ($revisionId <= 0) {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM recipe_score_revisions WHERE id = ?");
    $stmt->execute([$revisionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    foreach ([
        'id', 'inventory_revision', 'catalog_revision',
        'ontology_source_revision', 'catalog_max_id', 'recipe_count',
    ] as $key) {
        $row[$key] = (int)$row[$key];
    }
    $row['covered_catalog_revision'] =
        ($row['covered_catalog_revision'] ?? null) !== null
            ? (int)$row['covered_catalog_revision']
            : (int)$row['catalog_revision'];
    $row['covered_ontology_source_revision'] =
        ($row['covered_ontology_source_revision'] ?? null) !== null
            ? (int)$row['covered_ontology_source_revision']
            : (int)$row['ontology_source_revision'];
    $row['revision_kind'] = (string)(
        $row['revision_kind'] ?? 'baseline'
    );
    foreach ([
        'ontology_version_id',
        'parent_score_revision_id',
        'requirement_revision_id',
        'parity_baseline_score_revision_id',
    ] as $key) {
        if (array_key_exists($key, $row)) {
            $row[$key] = $row[$key] !== null ? (int)$row[$key] : null;
        }
    }
    foreach ([
        'catalog_fingerprint',
        'catalog_lineage_hash',
        'scoring_config_hash',
        'ontology_schema_hash',
        'ontology_prompt_hash',
        'ontology_model_hash',
        'ontology_corpus_hash',
        'ontology_content_hash',
        'ontology_source_hash',
        'ontology_source_lineage_hash',
    ] as $key) {
        $row[$key] = array_key_exists($key, $row)
            && $row[$key] !== null
            ? (string)$row[$key]
            : null;
    }
    return $row;
}

function recipeScoreActiveRevision(PDO $db): ?array {
    $state = recipeScoreState($db);
    if ($state['active_score_revision_id'] === null) {
        return null;
    }
    $revision = recipeScoreRevision($db, $state['active_score_revision_id']);
    return $revision !== null && $revision['status'] === 'ready' ? $revision : null;
}

function recipeScoreActiveOverlay(
    PDO $db,
    ?array $state = null,
    ?array $active = null
): ?array {
    $state ??= recipeScoreState($db);
    $overlayId = (int)(
        $state['active_score_overlay_revision_id'] ?? 0
    );
    if ($overlayId <= 0) {
        return null;
    }
    $active ??= recipeScoreActiveRevision($db);
    $overlay = recipeScoreRevision($db, $overlayId);
    if (
        $active === null
        || $overlay === null
        || (string)$overlay['status'] !== 'building'
        || (string)($active['scoring_model'] ?? '')
            !== 'faceted-ontology-v3'
        || (string)($overlay['scoring_model'] ?? '')
            !== 'faceted-ontology-v3'
        || (int)($overlay['ontology_version_id'] ?? 0)
            !== (int)($active['ontology_version_id'] ?? 0)
        || (int)($overlay['parent_score_revision_id'] ?? 0)
            !== (int)$active['id']
        || (int)$overlay['inventory_revision']
            !== (int)$state['inventory_revision']
        || (int)$overlay['catalog_revision']
            !== (int)$state['catalog_revision']
        || (int)$overlay['ontology_source_revision']
            !== (int)$state['ontology_source_revision']
        || (string)$overlay['score_date'] !== recipeScoreCurrentDate()
    ) {
        return null;
    }
    $report = json_decode(
        (string)($overlay['validation_report_json'] ?? '{}'),
        true
    );
    if (
        !is_array($report)
        || empty($report['overlay_ready'])
        || (string)($report['materialized_hash_algorithm'] ?? '')
            !== 'parent-delta-v1'
        || !is_string(
            $report['materialized_values']['overlay']['overlay_hash']
                ?? null
        )
        || strlen(
            (string)(
                $report['materialized_values']['overlay']['overlay_hash']
                    ?? ''
            )
        ) !== 64
    ) {
        return null;
    }
    return $overlay;
}

function recipeScoreRuntimeEnvironment(): string {
    $value = getenv('EVERSHELF_ENV');
    if ($value === false || trim($value) === '') {
        $value = function_exists('env')
            ? env('EVERSHELF_ENV', '')
            : '';
    }
    $value = strtolower(trim((string)$value));
    return $value !== '' ? $value : 'production';
}

function recipeScorePreviewEnvironmentAllowed(
    ?string $environment = null
): bool {
    $environment = strtolower(trim(
        (string)($environment ?? recipeScoreRuntimeEnvironment())
    ));
    return in_array($environment, ['development', 'test'], true);
}

function recipeScorePreviewSetting(): array {
    $raw = defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && array_key_exists(
            'RECIPE_SCORE_PREVIEW_REVISION_ID',
            $GLOBALS
        )
            ? trim((string)$GLOBALS['RECIPE_SCORE_PREVIEW_REVISION_ID'])
            : trim((string)(
                function_exists('env')
                    ? env('RECIPE_SCORE_PREVIEW_REVISION_ID', '')
                    : (getenv('RECIPE_SCORE_PREVIEW_REVISION_ID') ?: '')
            ));
    if ($raw === '' || preg_match('/^0+$/', $raw)) {
        return [
            'raw' => $raw,
            'requested' => false,
            'revision_id' => null,
            'diagnostics' => [],
        ];
    }
    if (!preg_match('/^[1-9][0-9]*$/', $raw)) {
        return [
            'raw' => $raw,
            'requested' => true,
            'revision_id' => null,
            'diagnostics' => ['preview_configuration_invalid'],
        ];
    }
    if (!recipeScorePreviewEnvironmentAllowed()) {
        return [
            'raw' => $raw,
            'requested' => true,
            'revision_id' => (int)$raw,
            'diagnostics' => ['preview_environment_forbidden'],
        ];
    }
    return [
        'raw' => $raw,
        'requested' => true,
        'revision_id' => (int)$raw,
        'diagnostics' => [],
    ];
}

function recipeScorePreviewRevisionDiagnostics(
    PDO $db,
    array $revision,
    array $state
): array {
    $diagnostics = [];
    $add = static function (string $code) use (&$diagnostics): void {
        if (count($diagnostics) < 8 && !in_array($code, $diagnostics, true)) {
            $diagnostics[] = $code;
        }
    };
    if ((string)($revision['status'] ?? '') !== 'ready') {
        $add('preview_revision_not_ready');
    }
    if (
        (string)($revision['scoring_model'] ?? '')
            !== 'faceted-ontology-v3'
        || ($revision['ontology_version_id'] ?? null) === null
    ) {
        $add('preview_revision_not_regular_v3');
    }
    if (($revision['requirement_revision_id'] ?? null) !== null) {
        $add('preview_requirement_shadow_forbidden');
    }
    if (
        (int)($revision['inventory_revision'] ?? -1)
            !== (int)$state['inventory_revision']
        || (int)($revision['catalog_revision'] ?? -1)
            !== (int)$state['catalog_revision']
    ) {
        $add('preview_source_revisions_stale');
    }
    $currentOntologySourceHash = function_exists(
        'ingredientOntologyV3CorpusHash'
    ) ? ingredientOntologyV3CorpusHash($db) : '';
    if (
        (int)($revision['ontology_source_revision'] ?? -1)
            !== (int)$state['ontology_source_revision']
        || !is_string($revision['ontology_source_hash'] ?? null)
        || strlen((string)$revision['ontology_source_hash']) !== 64
        || !hash_equals(
            (string)$revision['ontology_source_hash'],
            (string)$state['ontology_source_hash']
        )
        || !hash_equals(
            (string)$revision['ontology_source_hash'],
            $currentOntologySourceHash
        )
    ) {
        $add('preview_source_owner_hash_stale');
    }
    if ((string)($revision['score_date'] ?? '')
        !== recipeScoreCurrentDate()) {
        $add('preview_score_date_stale');
    }
    if (
        (int)($revision['catalog_max_id'] ?? -1)
            !== recipeScoreCatalogMaxId($db)
    ) {
        $add('preview_catalog_boundary_stale');
    }
    if (
        !function_exists('ingredientOntologyV3ScoringConfigAudit')
        || empty(
            ingredientOntologyV3ScoringConfigAudit($revision)['valid']
        )
    ) {
        $add('preview_scoring_configuration_invalid');
    }
    $report = json_decode(
        (string)($revision['validation_report_json'] ?? ''),
        true
    );
    if (
        !is_array($report)
        || empty($report['shadow_only'])
        || !empty($report['activated'])
        || (int)($report['ontology_version_id'] ?? 0)
            !== (int)($revision['ontology_version_id'] ?? 0)
        || !is_array($report['materialized_id_sets'] ?? null)
        || !is_array($report['materialized_values'] ?? null)
        || empty($report['materialized_id_sets']['valid'])
        || empty($report['materialized_values']['valid'])
        || empty($report['source_owner_fingerprints']['valid'])
        || (int)($report['ontology_source_revision'] ?? -1)
            !== (int)($revision['ontology_source_revision'] ?? -2)
        || !hash_equals(
            (string)($report['ontology_source_hash'] ?? ''),
            (string)($revision['ontology_source_hash'] ?? '')
        )
    ) {
        $add('preview_validation_report_invalid');
    } else {
        $idSets = $report['materialized_id_sets'];
        $values = $report['materialized_values'];
        foreach (
            [
                'catalog_id_set_hash',
                'ingredient_id_set_hash',
            ] as $column
        ) {
            if (
                !is_string($revision[$column] ?? null)
                || !is_string($idSets['current_hashes'][$column] ?? null)
                || !hash_equals(
                    (string)$revision[$column],
                    (string)$idSets['current_hashes'][$column]
                )
                || empty($idSets['hash_matches'][$column])
            ) {
                $add('preview_id_set_seal_invalid');
                break;
            }
        }
        foreach (
            [
                'score_rows_hash',
                'match_rows_hash',
                'materialization_hash',
            ] as $column
        ) {
            if (
                !is_string($revision[$column] ?? null)
                || !is_string($values['current'][$column] ?? null)
                || !hash_equals(
                    (string)$revision[$column],
                    (string)$values['current'][$column]
                )
                || empty($values['hash_matches'][$column])
            ) {
                $add('preview_materialization_seal_invalid');
                break;
            }
        }
        if (
            !hash_equals(
                (string)($revision['inventory_fingerprint'] ?? ''),
                (string)($report['inventory_fingerprint'] ?? '')
            )
            || !hash_equals(
                (string)($revision['catalog_fingerprint'] ?? ''),
                (string)($report['catalog_fingerprint'] ?? '')
            )
        ) {
            $add('preview_input_fingerprint_seal_invalid');
        }
    }
    $versionId = (int)($revision['ontology_version_id'] ?? 0);
    $versionStmt = $db->prepare("
        SELECT *
        FROM ingredient_ontology_versions
        WHERE id = ?
        LIMIT 1
    ");
    $versionStmt->execute([$versionId]);
    $version = $versionStmt->fetch(PDO::FETCH_ASSOC);
    $versionHashes = [
        'ontology_schema_hash' => 'schema_hash',
        'ontology_prompt_hash' => 'prompt_hash',
        'ontology_model_hash' => 'model_hash',
        'ontology_corpus_hash' => 'corpus_hash',
        'ontology_content_hash' => 'content_hash',
        'ontology_portable_content_hash' => 'portable_content_hash',
        'ontology_review_manifest_hash' => 'review_manifest_hash',
        'ontology_resolution_gold_hash' => 'resolution_gold_hash',
        'ontology_seal_hash' => 'seal_hash',
    ];
    if (!$version || (string)$version['status'] !== 'ready') {
        $add('preview_ontology_not_ready');
    } else {
        $profile = (string)($version['corpus_profile'] ?? '');
        $profileAllowed = in_array(
            $profile,
            ['eval', 'provider', 'production'],
            true
        ) || (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && $profile === 'test'
        );
        if (!$profileAllowed) {
            $add('preview_frozen_profile_invalid');
        }
        foreach ($versionHashes as $revisionColumn => $versionColumn) {
            if (
                !is_string($revision[$revisionColumn] ?? null)
                || !is_string($version[$versionColumn] ?? null)
                || !hash_equals(
                    (string)$revision[$revisionColumn],
                    (string)$version[$versionColumn]
                )
            ) {
                $add('preview_ontology_seal_mismatch');
                break;
            }
        }
        $versionReport = json_decode(
            (string)($version['validation_report_json'] ?? ''),
            true
        );
        $manifest = ingredientOntologyV3ResolutionManifest();
        $expectedPolicyHash = $profileAllowed
            ? ingredientOntologyV3VersionPolicyHash(
                $profile,
                (string)$version['activation_policy'],
                (string)$version['activation_block_reason']
            )
            : '';
        if (
            !is_array($versionReport)
            || empty($versionReport['graph']['valid'])
            || empty($versionReport['corpus']['complete'])
            || empty($versionReport['resolution_gold']['valid'])
            || empty($versionReport['matcher_gold']['valid'])
            || empty($versionReport['frozen_corpus']['valid'])
            || empty($versionReport['subject_universe']['valid'])
            || empty($versionReport['disposition_audit']['valid'])
            || empty($versionReport['hash_integrity']['valid'])
            || !hash_equals(
                (string)$version['schema_hash'],
                ingredientOntologyV3SchemaHash()
            )
            || !hash_equals(
                (string)$version['review_manifest_hash'],
                (string)$manifest['manifest_hash']
            )
            || !hash_equals(
                (string)$version['resolution_gold_hash'],
                (string)$manifest['file_hashes'][
                    INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_FILENAME
                ]
            )
            || !hash_equals(
                (string)$version['frozen_corpus_hash'],
                (string)($versionReport['frozen_corpus'][
                    'actual_hash'
                ] ?? '')
            )
            || !hash_equals(
                (string)$version['frozen_subjects_hash'],
                (string)($versionReport['subject_universe'][
                    'subject_universe_hash'
                ] ?? '')
            )
            || !hash_equals(
                (string)$version['policy_hash'],
                $expectedPolicyHash
            )
            || (
                $profile !== 'test'
                && (
                    (string)$version['activation_policy']
                        !== (string)$manifest['activation_policy']
                    || (string)$version['activation_block_reason']
                        !== (string)$manifest[
                            'activation_block_reason'
                        ]
                )
            )
        ) {
            $add('preview_ontology_validation_invalid');
        }
    }
    if (!$diagnostics && is_array($report)) {
        $scoreCount = $db->prepare("
            SELECT COUNT(*) FROM recipe_inventory_scores
            WHERE score_revision_id = ?
        ");
        $scoreCount->execute([(int)$revision['id']]);
        $matchCount = $db->prepare("
            SELECT COUNT(*) FROM ingredient_ontology_shadow_matches
            WHERE score_revision_id = ?
        ");
        $matchCount->execute([(int)$revision['id']]);
        $catalogCount = (int)$db->query("
            SELECT COUNT(*) FROM recipe_catalog
            WHERE deleted_at IS NULL
        ")->fetchColumn();
        $ingredientCount = (int)$db->query("
            SELECT COUNT(*)
            FROM recipe_ingredients ingredient
            JOIN recipe_catalog recipe ON recipe.id = ingredient.recipe_id
            WHERE recipe.deleted_at IS NULL
        ")->fetchColumn();
        if (
            (int)$scoreCount->fetchColumn()
                !== (int)($revision['recipe_count'] ?? -1)
            || $catalogCount !== (int)($revision['recipe_count'] ?? -1)
            || (int)$matchCount->fetchColumn() !== $ingredientCount
            || (int)($report['materialized_values']['current'][
                'score_row_count'
            ] ?? -1) !== $catalogCount
            || (int)($report['materialized_values']['current'][
                'match_row_count'
            ] ?? -1) !== $ingredientCount
        ) {
            $add('preview_materialized_row_count_invalid');
        }
    }
    return $diagnostics;
}

function recipeScoreReadRevision(PDO $db): array {
    static $cache = [];
    $setting = recipeScorePreviewSetting();
    $state = recipeScoreState($db);
    $cacheKey = implode(':', [
        spl_object_id($db),
        hash('sha256', $setting['raw']),
        recipeScoreRuntimeEnvironment(),
        $state['active_score_revision_id'] ?? 0,
        $state['active_score_overlay_revision_id'] ?? 0,
        $state['active_score_projection_revision_id'] ?? 0,
        $state['inventory_revision'],
        $state['catalog_revision'],
        $state['cursor_revision'],
        $state['ontology_source_revision'],
        hash('sha256', $state['ontology_source_hash']),
        (int)($GLOBALS['RECIPE_SCORE_READ_CACHE_GENERATION'] ?? 0),
        recipeScoreCurrentDate(),
    ]);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    $active = recipeScoreActiveRevision($db);
    $selected = $active;
    $preview = false;
    $diagnostics = $setting['diagnostics'];
    if (
        $setting['requested']
        && $setting['revision_id'] !== null
        && !$diagnostics
    ) {
        $candidate = recipeScoreRevision(
            $db,
            (int)$setting['revision_id']
        );
        if ($candidate === null) {
            $diagnostics[] = 'preview_revision_not_found';
        } else {
            $diagnostics = recipeScorePreviewRevisionDiagnostics(
                $db,
                $candidate,
                $state
            );
            if (!$diagnostics) {
                $selected = $candidate;
                $preview = true;
            }
        }
    }
    $status = !$setting['requested']
        ? 'disabled'
        : ($preview ? 'ready' : 'invalid');
    $overlay = !$preview
        ? recipeScoreActiveOverlay($db, $state, $active)
        : null;
    return $cache[$cacheKey] = [
        'revision' => $selected,
        'active_revision' => $active,
        'overlay_revision' => $overlay,
        'state' => $state,
        'preview' => $preview,
        'preview_requested' => $setting['requested'],
        'configured_preview_revision_id' =>
            $setting['revision_id'],
        'diagnostics' => array_slice($diagnostics, 0, 8),
        'capability' => [
            'requested' => $setting['requested'],
            'active' => $preview,
            'status' => $status,
            'configured_revision_id' =>
                $setting['revision_id'],
            'diagnostics' => array_slice($diagnostics, 0, 8),
        ],
    ];
}

function recipeScoreActiveReadRevision(PDO $db): array {
    $state = recipeScoreState($db);
    $active = recipeScoreActiveRevision($db);
    $overlay = recipeScoreActiveOverlay($db, $state, $active);
    return [
        'revision' => $active,
        'active_revision' => $active,
        'overlay_revision' => $overlay,
        'state' => $state,
        'preview' => false,
        'preview_requested' => false,
        'configured_preview_revision_id' => null,
        'diagnostics' => [],
        'capability' => [
            'requested' => false,
            'active' => false,
            'status' => 'disabled',
            'configured_revision_id' => null,
            'diagnostics' => [],
        ],
    ];
}

function recipeScoreReadRevisionCacheClear(): void {
    $GLOBALS['RECIPE_SCORE_READ_CACHE_GENERATION'] =
        (int)($GLOBALS['RECIPE_SCORE_READ_CACHE_GENERATION'] ?? 0) + 1;
}

function recipeScoreReadMetadata(array $read): array {
    $revision = $read['revision'] ?? null;
    $active = $read['active_revision'] ?? null;
    $preview = !empty($read['preview']);
    $overlay = $read['overlay_revision'] ?? null;
    return [
        'preview' => $preview,
        'score_revision_id' => $revision !== null
            ? (int)$revision['id']
            : null,
        'ontology_version_id' => $revision !== null
            && $revision['ontology_version_id'] !== null
                ? (int)$revision['ontology_version_id']
                : null,
        'active_score_revision_id' => $active !== null
            ? (int)$active['id']
            : null,
        'overlay_score_revision_id' => $overlay !== null
            ? (int)$overlay['id']
            : null,
        'preview_revision_id' => $preview
            ? (int)$revision['id']
            : null,
        'preview_ontology_version_id' => $preview
            ? (int)$revision['ontology_version_id']
            : null,
        'preview_diagnostics' => $read['diagnostics'] ?? [],
        'preview_capability' => $read['capability'] ?? [
            'requested' => false,
            'active' => false,
            'status' => 'disabled',
            'configured_revision_id' => null,
            'diagnostics' => [],
        ],
    ];
}

function recipeScoreReadResponseMetadata(array $metadata): array {
    return [
        'preview' => !empty($metadata['preview']),
        'revision' => [
            'preview' => !empty($metadata['preview']),
            'score' => $metadata['score_revision_id'] ?? null,
            'ontology' => $metadata['ontology_version_id'] ?? null,
            'active_score' =>
                $metadata['active_score_revision_id'] ?? null,
            'overlay_score' =>
                $metadata['overlay_score_revision_id'] ?? null,
            'preview_score' =>
                $metadata['preview_revision_id'] ?? null,
            'preview_ontology' =>
                $metadata['preview_ontology_version_id'] ?? null,
        ],
        'preview_diagnostics' =>
            $metadata['preview_diagnostics'] ?? [],
        'capabilities' => [
            'score_preview' => $metadata['preview_capability'],
        ],
    ];
}

function recipeScoreRevisionStatus(PDO $db, array $revision): string {
    $state = recipeScoreState($db);
    if (
        (string)($revision['scoring_model'] ?? '')
            === INGREDIENT_ONTOLOGY_V3_SCORING_MODEL
        && (
            !function_exists('ingredientOntologyV3ScoringConfigHash')
            || !is_string($revision['scoring_config_hash'] ?? null)
            || !hash_equals(
                ingredientOntologyV3ScoringConfigHash(),
                (string)$revision['scoring_config_hash']
            )
        )
    ) {
        return 'stale';
    }
    if (
        (string)($revision['scoring_model'] ?? '')
            === INGREDIENT_ONTOLOGY_V3_SCORING_MODEL
        && (
            (int)($revision['ontology_source_revision'] ?? -1)
                !== (int)$state['ontology_source_revision']
            || !hash_equals(
                (string)($revision['ontology_source_hash'] ?? ''),
                (string)$state['ontology_source_hash']
            )
            || !hash_equals(
                (string)(
                    $revision['ontology_source_lineage_hash'] ?? ''
                ),
                (string)(
                    $state['ontology_source_lineage_hash'] ?? ''
                )
            )
        )
    ) {
        return 'stale';
    }
    if (
        (string)($revision['revision_kind'] ?? 'baseline')
            === 'serving_delta'
        && (int)$revision['inventory_revision']
            === $state['inventory_revision']
        && (string)$revision['score_date']
            === recipeScoreCurrentDate()
    ) {
        return (int)$revision['covered_catalog_revision']
                === (int)$state['catalog_revision']
            && (int)$revision['covered_ontology_source_revision']
                === (int)$state['ontology_source_revision']
                ? 'fresh'
                : 'partial';
    }
    if (
        (int)$revision['inventory_revision'] === $state['inventory_revision']
        && (int)$revision['catalog_revision'] === $state['catalog_revision']
        && (string)$revision['score_date'] === recipeScoreCurrentDate()
    ) {
        return 'fresh';
    }
    return 'stale';
}

function recipeScoreLockPath(PDO $db): string {
    $database = $db->query("PRAGMA database_list")->fetchAll(PDO::FETCH_ASSOC);
    $path = (string)($database[0]['file'] ?? '');
    $basis = $path !== '' ? $path : (string)spl_object_id($db);
    if ($path !== '') {
        return dirname($path) . '/.' . basename($path) . '.recipe-score.lock';
    }
    return __DIR__ . '/../../../data/.recipe-score-'
        . hash('sha256', $basis) . '.lock';
}

function recipeScoreAcquireLock(PDO $db): mixed {
    $handle = fopen(recipeScoreLockPath($db), 'c+');
    if ($handle === false) {
        throw new RuntimeException('Recipe score lock could not be opened');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

function recipeScoreReleaseLock(mixed $handle): void {
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function recipeScoreImportOwnedRevisionIds(PDO $db): array {
    $exists = $db->query("
        SELECT 1 FROM sqlite_master
        WHERE type = 'table'
          AND name = 'ontology_activation_imports'
    ")->fetchColumn();
    if (!$exists) {
        return [];
    }
    return array_map('intval', $db->query("
        SELECT DISTINCT candidate_score_revision_id
        FROM ontology_activation_imports
        WHERE bundle_kind = 'score'
          AND candidate_score_revision_id IS NOT NULL
          AND status IN (
              'staging', 'importing', 'verifying', 'activatable',
              'rebase_required', 'failed', 'purging'
          )
    ")->fetchAll(PDO::FETCH_COLUMN));
}

function recipeScoreFailAbandonedBuilds(PDO $db): int {
    $error = mb_substr(
        'abandoned building revision recovered after '
            . 'exclusive score lock acquisition',
        0,
        1000,
        'UTF-8'
    );
    $protected = recipeScoreImportOwnedRevisionIds($db);
    $where = "status = 'building'";
    if ($protected) {
        $where .= ' AND id NOT IN ('
            . implode(',', array_fill(0, count($protected), '?'))
            . ')';
    }
    $stmt = $db->prepare("
        UPDATE recipe_score_revisions SET
            status = 'failed',
            last_error = ?,
            completed_at = CURRENT_TIMESTAMP
        WHERE {$where}
    ");
    $stmt->execute(array_merge([$error], $protected));
    return $stmt->rowCount();
}

function recipeScoreLoadRecipes(PDO $db, array $recipeIds): array {
    $recipeIds = array_values(array_unique(array_filter(
        array_map('intval', $recipeIds),
        static fn(int $id): bool => $id > 0
    )));
    if (!$recipeIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($recipeIds), '?'));
    $summary = $db->prepare("
        SELECT c.id, c.primary_connector,
               COALESCE(us.favorite, 0) AS favorite,
               us.rating
        FROM recipe_catalog c
        LEFT JOIN recipe_user_state us ON us.recipe_id = c.id
        WHERE c.id IN ({$placeholders}) AND c.deleted_at IS NULL
    ");
    $summary->execute($recipeIds);
    $recipes = [];
    foreach ($summary->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        $recipes[$id] = [
            'id' => $id,
            'primary_connector' => (string)$row['primary_connector'],
            'favorite' => !empty($row['favorite']),
            'rating' => $row['rating'] !== null ? (int)$row['rating'] : null,
            'ingredients' => [],
        ];
    }
    $ingredients = $db->prepare("
        SELECT recipe_id, position, normalized_name, quantity, unit,
               is_required, is_optional, is_staple,
               canonical_ingredient_id, taxonomy_node_id
        FROM recipe_ingredients
        WHERE recipe_id IN ({$placeholders})
        ORDER BY recipe_id, position
    ");
    $ingredients->execute($recipeIds);
    foreach ($ingredients->fetchAll(PDO::FETCH_ASSOC) as $ingredient) {
        $recipeId = (int)$ingredient['recipe_id'];
        if (!isset($recipes[$recipeId])) {
            continue;
        }
        $recipes[$recipeId]['ingredients'][] = [
            'position' => (int)$ingredient['position'],
            'normalized_name' => (string)$ingredient['normalized_name'],
            'quantity' => $ingredient['quantity'] !== null ? (float)$ingredient['quantity'] : null,
            'unit' => $ingredient['unit'] !== null ? (string)$ingredient['unit'] : null,
            'is_required' => (bool)$ingredient['is_required'],
            'is_optional' => (bool)$ingredient['is_optional'],
            'is_staple' => (bool)$ingredient['is_staple'],
            'canonical_ingredient_id' => $ingredient['canonical_ingredient_id'] !== null
                ? (int)$ingredient['canonical_ingredient_id']
                : null,
            'taxonomy_node_id' => $ingredient['taxonomy_node_id'] !== null
                ? (int)$ingredient['taxonomy_node_id']
                : null,
        ];
    }
    return $recipes;
}

function recipeScoreBuildRow(PDO $db, array $recipe, array $inventoryCandidates): array {
    $ranking = recipeCatalogRankRecipe($db, $recipe, $inventoryCandidates, 'expiring');
    $requiredCount = 0;
    foreach ($recipe['ingredients'] as $ingredient) {
        if (
            !empty($ingredient['is_required'])
            && empty($ingredient['is_optional'])
            && empty($ingredient['is_staple'])
        ) {
            $requiredCount++;
        }
    }
    $missingCount = (int)$ranking['missing_required_count'];
    $days = [];
    foreach ($ranking['ingredient_matches'] as $match) {
        if (!empty($match['matched']) && isset($match['days_remaining'])) {
            $days[] = (int)$match['days_remaining'];
        }
    }
    return [
        'recipe_id' => (int)$recipe['id'],
        'coverage' => (float)$ranking['components']['coverage'],
        'directness' => (float)$ranking['components']['directness'],
        'expiry_score' => (float)$ranking['components']['expiry'],
        'source_user_score' => (float)$ranking['components']['source_user'],
        'availability_score' => (float)$ranking['scores']['availability'],
        'required_count' => $requiredCount,
        'matched_required_count' => max(0, $requiredCount - $missingCount),
        'missing_required_count' => $missingCount,
        'cookable' => $missingCount === 0 ? 1 : 0,
        'soonest_expiry_days' => $days ? min($days) : null,
    ];
}

function recipeScoreUpsertRows(PDO $db, int $revisionId, array $rows): void {
    if (!$rows) {
        return;
    }
    $stmt = $db->prepare("
        INSERT INTO recipe_inventory_scores (
            score_revision_id, recipe_id, coverage, directness, expiry_score,
            source_user_score, availability_score, required_count,
            matched_required_count, missing_required_count, cookable,
            soonest_expiry_days, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(score_revision_id, recipe_id) DO UPDATE SET
            coverage = excluded.coverage,
            directness = excluded.directness,
            expiry_score = excluded.expiry_score,
            source_user_score = excluded.source_user_score,
            availability_score = excluded.availability_score,
            required_count = excluded.required_count,
            matched_required_count = excluded.matched_required_count,
            missing_required_count = excluded.missing_required_count,
            cookable = excluded.cookable,
            soonest_expiry_days = excluded.soonest_expiry_days,
            updated_at = CURRENT_TIMESTAMP
    ");
    foreach ($rows as $row) {
        $stmt->execute([
            $revisionId,
            $row['recipe_id'],
            $row['coverage'],
            $row['directness'],
            $row['expiry_score'],
            $row['source_user_score'],
            $row['availability_score'],
            $row['required_count'],
            $row['matched_required_count'],
            $row['missing_required_count'],
            $row['cookable'],
            $row['soonest_expiry_days'],
        ]);
    }
}

function recipeScorePruneRevisions(PDO $db): array {
    if ($db->inTransaction()) {
        throw new RuntimeException(
            'recipe score pruning cannot run inside a transaction'
        );
    }
    $keep = array_map('intval', $db->query("
        SELECT id
        FROM recipe_score_revisions
        WHERE status = 'ready'
          AND COALESCE(scoring_model, 'legacy-v2') = 'legacy-v2'
        ORDER BY completed_at DESC, id DESC
        LIMIT " . RECIPE_SCORE_LEGACY_READY_RETENTION . "
    ")->fetchAll(PDO::FETCH_COLUMN));

    $activeRevisionId = recipeScoreState($db)['active_score_revision_id'];
    $active = $activeRevisionId !== null
        ? recipeScoreRevision($db, $activeRevisionId)
        : null;
    if ($active !== null) {
        $keep[] = $activeRevisionId;
        $cursor = $active;
        $seen = [$activeRevisionId => true];
        $sparseClosure = recipeScoreRevisionIsSparseDelta($active);
        $probe = $active;
        $probeSeen = [(int)$active['id'] => true];
        for (
            $probeDepth = 0;
            !$sparseClosure
                && $probeDepth < RECIPE_SCORE_V3_SPARSE_ANCESTOR_LIMIT;
            $probeDepth++
        ) {
            $probeParentId = (int)(
                $probe['parent_score_revision_id'] ?? 0
            );
            if (
                $probeParentId <= 0
                || isset($probeSeen[$probeParentId])
            ) {
                break;
            }
            $probe = recipeScoreRevision($db, $probeParentId);
            if ($probe === null) {
                break;
            }
            $probeSeen[$probeParentId] = true;
            $sparseClosure =
                recipeScoreRevisionIsSparseDelta($probe);
        }
        $ancestorLimit = $sparseClosure
            ? RECIPE_SCORE_V3_SPARSE_ANCESTOR_LIMIT
            : RECIPE_SCORE_V3_ROLLBACK_ANCESTOR_LIMIT;
        for (
            $depth = 0;
            $depth < $ancestorLimit;
            $depth++
        ) {
            $parentId = (int)($cursor['parent_score_revision_id'] ?? 0);
            if ($parentId <= 0 || isset($seen[$parentId])) {
                break;
            }
            $parent = recipeScoreRevision($db, $parentId);
            if ($parent === null) {
                break;
            }
            $keep[] = $parentId;
            $seen[$parentId] = true;
            $cursor = $parent;
            if (
                $sparseClosure
                && !recipeScoreRevisionIsSparseDelta($cursor)
            ) {
                $nextParent = recipeScoreRevision(
                    $db,
                    (int)(
                        $cursor['parent_score_revision_id'] ?? 0
                    )
                );
                if (
                    $nextParent === null
                    || !recipeScoreRevisionIsSparseDelta($nextParent)
                ) {
                    break;
                }
            }
        }

        $sameParent = $db->prepare("
            SELECT id
            FROM recipe_score_revisions
            WHERE scoring_model = 'faceted-ontology-v3'
              AND parent_score_revision_id = ?
              AND status IN ('ready', 'failed')
              AND id <> ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $sameParent->execute([$activeRevisionId, $activeRevisionId]);
        $candidateId = (int)($sameParent->fetchColumn() ?: 0);
        if ($candidateId > 0) {
            $keep[] = $candidateId;
        }
    }

    $keep = array_merge($keep, array_map('intval', $db->query("
        SELECT id
        FROM recipe_score_revisions
        WHERE status = 'ready'
          AND COALESCE(scoring_model, 'legacy-v2') = 'faceted-ontology-v3'
        ORDER BY completed_at DESC, id DESC
        LIMIT " . RECIPE_SCORE_V3_READY_HISTORY_LIMIT . "
    ")->fetchAll(PDO::FETCH_COLUMN)));
    $keep = array_merge($keep, array_map('intval', $db->query("
        SELECT id
        FROM recipe_score_revisions
        WHERE status = 'ready'
          AND COALESCE(scoring_model, 'legacy-v2')
                = 'faceted-ontology-v3-requirements'
        ORDER BY completed_at DESC, id DESC
        LIMIT " . RECIPE_SCORE_REQUIREMENT_SHADOW_RETENTION . "
    ")->fetchAll(PDO::FETCH_COLUMN)));
    if ($keep) {
        $placeholders = implode(',', array_fill(0, count($keep), '?'));
        $baselines = $db->prepare("
            SELECT DISTINCT parity_baseline_score_revision_id
            FROM recipe_score_revisions
            WHERE id IN ({$placeholders})
              AND parity_baseline_score_revision_id IS NOT NULL
        ");
        $baselines->execute($keep);
        $keep = array_merge(
            $keep,
            array_map('intval', $baselines->fetchAll(PDO::FETCH_COLUMN))
        );
    }
    $keep = array_values(array_unique($keep));
    sort($keep, SORT_NUMERIC);
    $maximumKeepCount = RECIPE_SCORE_LEGACY_READY_RETENTION
        + 1
        + RECIPE_SCORE_V3_SPARSE_ANCESTOR_LIMIT
        + 1
        + RECIPE_SCORE_V3_READY_HISTORY_LIMIT
        + RECIPE_SCORE_REQUIREMENT_SHADOW_RETENTION
        + RECIPE_SCORE_REQUIREMENT_SHADOW_RETENTION;
    if (count($keep) > $maximumKeepCount) {
        throw new RuntimeException(
            'recipe score pruning keep set exceeded its bounded limit'
        );
    }
    $keep = array_values(array_unique(array_merge(
        $keep,
        recipeScoreImportOwnedRevisionIds($db),
        array_map('intval', $db->query("
            SELECT DISTINCT score_revision_id
            FROM recipe_score_effective_sources
        ")->fetchAll(PDO::FETCH_COLUMN))
    )));
    sort($keep, SORT_NUMERIC);
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && is_callable(
            $GLOBALS['RECIPE_SCORE_AFTER_PRUNE_KEEP_COMPUTATION'] ?? null
        )
    ) {
        ($GLOBALS['RECIPE_SCORE_AFTER_PRUNE_KEEP_COMPUTATION'])(
            $db,
            $keep,
            $activeRevisionId
        );
    }

    $params = [];
    $where = "status <> 'building'";
    if ($keep) {
        $placeholders = implode(',', array_fill(0, count($keep), '?'));
        $where .= " AND id NOT IN ({$placeholders})";
        $params = $keep;
    }
    $targets = $db->prepare("
        SELECT id FROM recipe_score_revisions
        WHERE {$where}
        ORDER BY id
    ");
    $targets->execute($params);
    $targetIds = array_map('intval', $targets->fetchAll(PDO::FETCH_COLUMN));
    $chunkRows = max(
        25,
        min(
            5000,
            (int)(function_exists('env')
                ? env('RECIPE_SCORE_PRUNE_CHUNK_ROWS', '5000')
                : (getenv('RECIPE_SCORE_PRUNE_CHUNK_ROWS') ?: 5000))
        )
    );
    $maximumChunks = max(
        1,
        min(
            500,
            (int)(function_exists('env')
                ? env('RECIPE_SCORE_PRUNE_MAX_CHUNKS', '500')
                : (getenv('RECIPE_SCORE_PRUNE_MAX_CHUNKS') ?: 500))
        )
    );
    $guardAvailable = function_exists(
        'ingredientOntologyV3SetRequirementPruneGuard'
    );
    $guardWasEnabled = $guardAvailable
        && function_exists('ingredientOntologyV3RequirementPruneGuardEnabled')
            ? ingredientOntologyV3RequirementPruneGuardEnabled($db)
            : false;
    $runReservation = static function (
        callable $callback
    ) use (
        $db,
        $activeRevisionId,
        $guardAvailable,
        $guardWasEnabled
    ): mixed {
        $db->exec('BEGIN IMMEDIATE');
        try {
            if (
                recipeScoreState($db)['active_score_revision_id']
                    !== $activeRevisionId
            ) {
                throw new RuntimeException(
                    'active score pointer changed during pruning'
                );
            }
            if ($guardAvailable) {
                ingredientOntologyV3SetRequirementPruneGuard($db, true);
            }
            $result = $callback();
            $db->exec('COMMIT');
            return $result;
        } catch (Throwable $error) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $error;
        } finally {
            if ($guardAvailable) {
                ingredientOntologyV3SetRequirementPruneGuard(
                    $db,
                    $guardWasEnabled
                );
            }
        }
    };

    if ($keep) {
        $placeholders = implode(',', array_fill(0, count($keep), '?'));
        $runReservation(static function () use (
            $db,
            $keep,
            $placeholders
        ): void {
            $truncate = $db->prepare("
                UPDATE recipe_score_revisions
                SET parent_score_revision_id = NULL
                WHERE id IN ({$placeholders})
                  AND parent_score_revision_id IS NOT NULL
                  AND parent_score_revision_id NOT IN ({$placeholders})
            ");
            $truncate->execute(array_merge($keep, $keep));
        });
    }

    $childTables = [
        'ingredient_ontology_shadow_requirement_matches',
        'recipe_score_contributor_revisions',
        'recipe_score_match_contributors',
        'ingredient_ontology_shadow_matches',
        'recipe_inventory_scores',
        'recipe_score_recipe_ingredients',
        'recipe_score_recipe_operations',
        'recipe_score_incremental_recipes',
    ];
    $chunks = 0;
    foreach ($targetIds as $targetId) {
        foreach ($childTables as $table) {
            while ($chunks < $maximumChunks) {
                $deleted = (int)$runReservation(
                    static function () use (
                        $db,
                        $table,
                        $targetId,
                        $chunkRows
                    ): int {
                        $delete = $db->prepare("
                            DELETE FROM {$table}
                            WHERE rowid IN (
                                SELECT rowid FROM {$table}
                                WHERE score_revision_id = ?
                                LIMIT {$chunkRows}
                            )
                        ");
                        $delete->execute([$targetId]);
                        return $delete->rowCount();
                    }
                );
                if ($deleted === 0) {
                    break;
                }
                $chunks++;
            }
            if ($chunks >= $maximumChunks) {
                return $keep;
            }
        }
        $runReservation(static function () use (
            $db,
            $targetId
        ): void {
            $delete = $db->prepare("
                DELETE FROM recipe_score_revisions
                WHERE id = ?
                  AND status <> 'building'
                  AND NOT EXISTS (
                      SELECT 1 FROM recipe_score_state
                      WHERE id = 1
                        AND active_score_revision_id =
                            recipe_score_revisions.id
                  )
            ");
            $delete->execute([$targetId]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException(
                    'recipe score pruning revision fence was lost'
                );
            }
        });
        $chunks++;
        if ($chunks >= $maximumChunks) {
            return $keep;
        }
    }

    $finalActiveRevisionId =
        recipeScoreState($db)['active_score_revision_id'];
    if ($finalActiveRevisionId !== $activeRevisionId) {
        throw new RuntimeException(
            'active score pointer changed during pruning'
        );
    }
    if ($activeRevisionId !== null) {
        $finalActive = recipeScoreRevision($db, $activeRevisionId);
        if ($finalActive === null || $finalActive['status'] !== 'ready') {
            throw new RuntimeException(
                'active score pointer does not resolve to a ready revision'
            );
        }
    }
    return $keep;
}

function recipeScorePostCommitCleanup(PDO $db): ?string {
    try {
        if (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && is_callable(
                $GLOBALS['RECIPE_SCORE_BEFORE_PRUNE_CLEANUP'] ?? null
            )
        ) {
            ($GLOBALS['RECIPE_SCORE_BEFORE_PRUNE_CLEANUP'])($db);
        }
        recipeScorePruneRevisions($db);
        return null;
    } catch (Throwable $e) {
        return mb_substr($e->getMessage(), 0, 500, 'UTF-8');
    }
}

function recipeScoreRebuild(
    PDO $db,
    bool $force = false,
    int $batchSize = 250,
    bool $lockAlreadyHeld = false
): array {
    $lock = null;
    if (!$lockAlreadyHeld) {
        $lock = recipeScoreAcquireLock($db);
        if ($lock === false) {
            return ['rebuilt' => false, 'reason' => 'locked'];
        }
    }
    try {
        $abandonedBuildCount = recipeScoreFailAbandonedBuilds($db);
        $state = recipeScoreState($db);
        $active = recipeScoreActiveRevision($db);
        if (!$force && $active !== null && recipeScoreRevisionStatus($db, $active) === 'fresh') {
            $result = [
                'rebuilt' => false,
                'reason' => 'fresh',
                'revision_id' => (int)$active['id'],
                'recipe_count' => (int)$active['recipe_count'],
            ];
            if ($abandonedBuildCount > 0) {
                $cleanupWarning = recipeScorePostCommitCleanup($db);
                if ($cleanupWarning !== null) {
                    $result['cleanup_warning'] = $cleanupWarning;
                }
            }
            return $result;
        }

        $scoreDate = recipeScoreCurrentDate();
        $inventory = recipeInventoryCandidates($db, [
            'exclude_expired' => true,
            'score_date' => $scoreDate,
        ]);
        $fingerprint = recipeScoreInventoryFingerprint($inventory);
        $catalogMaxId = recipeScoreCatalogMaxId($db);
        $catalogFingerprint = recipeScoreCatalogFingerprint($db);
        $insert = $db->prepare("
            INSERT INTO recipe_score_revisions (
                inventory_revision, catalog_revision, inventory_fingerprint, score_date,
                catalog_max_id, catalog_fingerprint, status
            )
            VALUES (?, ?, ?, ?, ?, ?, 'building')
        ");
        recipeScoreWithWriteRetry(static function () use (
            $insert,
            $state,
            $fingerprint,
            $catalogMaxId,
            $catalogFingerprint,
            $scoreDate
        ): void {
            $insert->execute([
                $state['inventory_revision'],
                $state['catalog_revision'],
                $fingerprint,
                $scoreDate,
                $catalogMaxId,
                $catalogFingerprint,
            ]);
        });
        $revisionId = (int)$db->lastInsertId();
        $revision = recipeScoreRevision($db, $revisionId);
        $revisionCreatedAt = (string)($revision['created_at'] ?? date('Y-m-d H:i:s'));
        $batchSize = max(1, min(1000, $batchSize));
        $lastId = 0;
        $recipeCount = 0;
        $scoreIds = static function (array $recipeIds) use (
            $db,
            $inventory,
            $revisionId
        ): int {
            $recipes = recipeScoreLoadRecipes($db, $recipeIds);
            $rows = [];
            foreach ($recipes as $recipe) {
                $rows[] = recipeScoreBuildRow($db, $recipe, $inventory);
            }
            recipeScoreUpsertRows($db, $revisionId, $rows);
            return count($rows);
        };
        $scoreThrough = static function (
            int $targetMaxId,
            bool $transactionPerBatch
        ) use (
            $db,
            $batchSize,
            $scoreIds,
            &$lastId,
            &$recipeCount
        ): void {
            while (true) {
                $ids = $db->prepare("
                    SELECT id
                    FROM recipe_catalog
                    WHERE deleted_at IS NULL AND id > ? AND id <= ?
                    ORDER BY id ASC
                    LIMIT {$batchSize}
                ");
                $ids->execute([$lastId, $targetMaxId]);
                $recipeIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
                if (!$recipeIds) {
                    break;
                }
                if ($transactionPerBatch) {
                    $written = recipeScoreWithWriteRetry(
                        static function () use ($db, $scoreIds, $recipeIds): int {
                            $db->beginTransaction();
                            try {
                                $count = $scoreIds($recipeIds);
                                $db->commit();
                                return $count;
                            } catch (Throwable $e) {
                                if ($db->inTransaction()) {
                                    $db->rollBack();
                                }
                                throw $e;
                            }
                        }
                    );
                    $recipeCount += $written;
                } else {
                    $recipeCount += $scoreIds($recipeIds);
                }
                $lastId = max($recipeIds);
            }
        };

        try {
            $scoreThrough($catalogMaxId, true);
            if (
                defined('RECIPE_BACKEND_TEST_MODE')
                && RECIPE_BACKEND_TEST_MODE
                && is_callable(
                    $GLOBALS[
                        'RECIPE_SCORE_BEFORE_FINAL_CATCHUP'
                    ] ?? null
                )
            ) {
                ($GLOBALS['RECIPE_SCORE_BEFORE_FINAL_CATCHUP'])(
                    $db,
                    $revisionId
                );
            }

            // Block catalog writers only for the short catch-up/activation window.
            // This prevents recipes inserted or updated during the long build from
            // being omitted by an otherwise fresh active revision.
            recipeScoreWithWriteRetry(static function () use (
                $db,
                $revisionId,
                $revisionCreatedAt,
                $batchSize,
                $state,
                $scoreIds,
                $scoreThrough,
                &$catalogMaxId,
                &$recipeCount
            ): void {
                $db->beginTransaction();
                try {
                    // Acquire SQLite's write reservation before the final reads.
                    $db->prepare("
                        UPDATE recipe_score_revisions SET id = id WHERE id = ?
                    ")->execute([$revisionId]);
                    $catalogMaxId = recipeScoreCatalogMaxId($db);
                    $scoreThrough($catalogMaxId, false);
                    $changed = $db->prepare("
                        SELECT c.id
                        FROM recipe_catalog c
                        LEFT JOIN recipe_user_state us ON us.recipe_id = c.id
                        WHERE c.deleted_at IS NULL
                          AND c.id <= ?
                          AND (
                              c.updated_at >= ?
                              OR COALESCE(us.updated_at, '') >= ?
                              OR EXISTS (
                                  SELECT 1
                                  FROM recipe_ingredients changed_ingredient
                                  WHERE changed_ingredient.recipe_id = c.id
                                    AND changed_ingredient.updated_at >= ?
                              )
                          )
                        ORDER BY c.id ASC
                    ");
                    $changed->execute([
                        $catalogMaxId,
                        $revisionCreatedAt,
                        $revisionCreatedAt,
                        $revisionCreatedAt,
                    ]);
                    $changedIds = array_map(
                        'intval',
                        $changed->fetchAll(PDO::FETCH_COLUMN)
                    );
                    foreach (array_chunk($changedIds, $batchSize) as $recipeIds) {
                        $scoreIds($recipeIds);
                    }
                    $db->prepare("
                        DELETE FROM recipe_inventory_scores
                        WHERE score_revision_id = ?
                          AND recipe_id NOT IN (
                              SELECT id FROM recipe_catalog
                              WHERE deleted_at IS NULL AND id <= ?
                          )
                    ")->execute([$revisionId, $catalogMaxId]);
                    $count = $db->prepare("
                        SELECT COUNT(*) FROM recipe_inventory_scores
                        WHERE score_revision_id = ?
                    ");
                    $count->execute([$revisionId]);
                    $recipeCount = (int)$count->fetchColumn();
                    $currentState = recipeScoreState($db);
                    $currentCatalogFingerprint =
                        recipeScoreCatalogFingerprint($db);
                    $materializedHashes = function_exists(
                        'ingredientOntologyV3MaterializedValueHashes'
                    ) ? ingredientOntologyV3MaterializedValueHashes(
                        $db,
                        $revisionId,
                        null
                    ) : [
                        'score_rows_hash' => null,
                        'match_rows_hash' => null,
                        'materialization_hash' => null,
                    ];
                    $publicationGuardAvailable = function_exists(
                        'ingredientOntologyV3SetPublicationGuard'
                    );
                    $publicationGuardWasEnabled =
                        $publicationGuardAvailable
                        && function_exists(
                            'ingredientOntologyV3PublicationGuardEnabled'
                        )
                            ? ingredientOntologyV3PublicationGuardEnabled(
                                $db
                            )
                            : false;
                    if ($publicationGuardAvailable) {
                        ingredientOntologyV3SetPublicationGuard($db, true);
                    }
                    try {
                        $db->prepare("
                            UPDATE recipe_score_revisions SET
                                status = 'ready',
                                catalog_revision = ?,
                                catalog_max_id = ?,
                                catalog_fingerprint = ?,
                                recipe_count = ?,
                                score_rows_hash = ?,
                                match_rows_hash = ?,
                                materialization_hash = ?,
                                completed_at = CURRENT_TIMESTAMP,
                                last_error = ''
                            WHERE id = ?
                        ")->execute([
                            $currentState['catalog_revision'],
                            $catalogMaxId,
                            $currentCatalogFingerprint,
                            $recipeCount,
                            $materializedHashes['score_rows_hash'],
                            $materializedHashes['match_rows_hash'],
                            $materializedHashes['materialization_hash'],
                            $revisionId,
                        ]);
                    } finally {
                        if ($publicationGuardAvailable) {
                            ingredientOntologyV3SetPublicationGuard(
                                $db,
                                $publicationGuardWasEnabled
                            );
                        }
                    }

                    $currentActiveRevision = $currentState['active_score_revision_id'] !== null
                        ? recipeScoreRevision(
                            $db,
                            $currentState['active_score_revision_id']
                        )
                        : null;
                    $currentActiveUsesOntologyV3 = $currentActiveRevision !== null
                        && ($currentActiveRevision['ontology_version_id'] ?? null)
                            !== null;
                    if (
                        !$currentActiveUsesOntologyV3
                        && (
                            $currentActiveRevision === null
                            || $currentState['inventory_revision']
                                === $state['inventory_revision']
                        )
                    ) {
                        recipeScoreBuildEffectiveProjection(
                            $db,
                            $revisionId
                        );
                        $db->prepare("
                            UPDATE recipe_score_state SET
                                active_score_revision_id = ?,
                                active_score_overlay_revision_id = NULL,
                                ontology_source_lineage_hash = '',
                                last_built_at = CURRENT_TIMESTAMP,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = 1
                        ")->execute([$revisionId]);
                    }
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }
            });
            $result = [
                'rebuilt' => true,
                'revision_id' => $revisionId,
                'inventory_revision' => $state['inventory_revision'],
                'catalog_max_id' => $catalogMaxId,
                'recipe_count' => $recipeCount,
                'activated' => recipeScoreState($db)['active_score_revision_id'] === $revisionId,
            ];
            $cleanupWarning = recipeScorePostCommitCleanup($db);
            if ($cleanupWarning !== null) {
                $result['cleanup_warning'] = $cleanupWarning;
            }
            return $result;
        } catch (Throwable $e) {
            $db->prepare("
                UPDATE recipe_score_revisions SET
                    status = 'failed',
                    last_error = ?,
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'building'
            ")->execute([
                mb_substr($e->getMessage(), 0, 1000, 'UTF-8'),
                $revisionId,
            ]);
            throw $e;
        }
    } finally {
        if (!$lockAlreadyHeld) {
            recipeScoreReleaseLock($lock);
        }
    }
}

function recipeScoreResolveRevision(PDO $db, ?int $revisionId = null): array {
    $read = recipeScoreReadRevision($db);
    $state = $read['state'];
    $revision = $read['revision'];
    if (
        $revisionId !== null
        && (
            $revision === null
            || $revisionId !== (int)$revision['id']
        )
    ) {
        throw new InvalidArgumentException(
            'Recipe cursor score revision is not the configured read revision'
        );
    }
    $catalogCount = (int)$db->query("
        SELECT COUNT(*) FROM recipe_catalog WHERE deleted_at IS NULL
    ")->fetchColumn();
    $syncLimit = defined('RECIPE_BACKEND_TEST_MODE') && RECIPE_BACKEND_TEST_MODE
        ? 5000
        : max(0, (int)(function_exists('env') ? env('RECIPE_SCORE_SYNC_BOOTSTRAP_LIMIT', '250') : 250));
    $activeUsesV3 = $revision !== null
        && (string)($revision['scoring_model'] ?? '') === 'faceted-ontology-v3'
        && $revision['ontology_version_id'] !== null;
    $shouldSynchronouslyBuild = $revisionId === null
        && empty($read['preview'])
        && (
            $revision === null
            || (
                !$activeUsesV3
                && recipeScoreRevisionStatus($db, $revision) !== 'fresh'
            )
        );
    if ($shouldSynchronouslyBuild) {
        if ($catalogCount <= $syncLimit) {
            if (function_exists('ingredientOntologyV3ScheduledRebuild')) {
                ingredientOntologyV3ScheduledRebuild($db, true);
            } else {
                recipeScoreRebuild($db, true);
            }
            $read = recipeScoreReadRevision($db);
            $state = $read['state'];
            $revision = $read['revision'];
        }
    }
    if ($revision === null || $revision['status'] !== 'ready') {
        throw new RecipeScoreUnavailableException(
            'Recipe scores are being built; run scripts/rebuild-recipe-scores.php'
        );
    }
    if (($revision['requirement_revision_id'] ?? null) !== null) {
        throw new RecipeScoreUnavailableException(
            'Requirement-projection score revisions are shadow-only'
        );
    }
    return [
        'revision' => $revision,
        'overlay_revision' => $read['overlay_revision'] ?? null,
        'ranking_status' => ($read['overlay_revision'] ?? null) !== null
            ? 'fresh_overlay'
            : recipeScoreRevisionStatus($db, $revision),
        'read' => $read,
        'read_metadata' => recipeScoreReadMetadata($read),
    ];
}

function recipeCatalogBase64UrlEncode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function recipeCatalogBase64UrlDecode(string $value): string|false {
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($value, true);
}

function recipeCatalogEncodeCursor(array $cursor): string {
    return recipeCatalogBase64UrlEncode(recipeCatalogJsonEncode($cursor));
}

function recipeCatalogDecodeCursor(string $cursor): array {
    $decoded = recipeCatalogBase64UrlDecode(trim($cursor));
    $value = $decoded !== false ? json_decode($decoded, true) : null;
    if (!is_array($value)) {
        throw new InvalidArgumentException('Invalid recipe cursor');
    }
    foreach ([
        'revision_id', 'catalog_revision', 'cursor_revision',
        'catalog_max_id', 'offset', 'criteria_hash',
    ] as $key) {
        if (!array_key_exists($key, $value)) {
            throw new InvalidArgumentException('Invalid recipe cursor');
        }
    }
    return [
        'revision_id' => max(1, (int)$value['revision_id']),
        'catalog_revision' => max(1, (int)$value['catalog_revision']),
        'cursor_revision' => max(1, (int)$value['cursor_revision']),
        'catalog_max_id' => max(0, (int)$value['catalog_max_id']),
        'offset' => max(0, (int)$value['offset']),
        'criteria_hash' => (string)$value['criteria_hash'],
    ];
}

function recipeCatalogNormalizeBrowseOptions(array $options): array {
    $sort = strtolower(trim((string)($options['sort'] ?? '')));
    if ($sort === '') {
        $sort = ($options['mode'] ?? 'stocked') === 'expiring' ? 'expiry' : 'availability';
    }
    if (!in_array($sort, ['availability', 'expiry', 'alphabetical'], true)) {
        throw new InvalidArgumentException('sort must be availability, expiry, or alphabetical');
    }
    $integer = static function (mixed $value, int $default, string $name): int {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value) || (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value)))) {
            throw new InvalidArgumentException($name . ' must be an integer');
        }
        return (int)$value;
    };
    $availabilityWeight = $integer(
        $options['availability_weight'] ?? null,
        100,
        'availability_weight'
    );
    $expiryWeight = $integer($options['expiry_weight'] ?? null, 25, 'expiry_weight');
    $minimumCoverage = $integer(
        $options['minimum_coverage'] ?? null,
        0,
        'minimum_coverage'
    );
    foreach ([
        'availability_weight' => $availabilityWeight,
        'expiry_weight' => $expiryWeight,
        'minimum_coverage' => $minimumCoverage,
    ] as $name => $value) {
        if ($value < 0 || $value > 100) {
            throw new InvalidArgumentException($name . ' must be between 0 and 100');
        }
    }
    $expiringWithinDays = $options['expiring_within_days'] ?? null;
    if ($expiringWithinDays !== null && $expiringWithinDays !== '') {
        $expiringWithinDays = $integer(
            $expiringWithinDays,
            7,
            'expiring_within_days'
        );
        if ($expiringWithinDays < 1 || $expiringWithinDays > 3650) {
            throw new InvalidArgumentException(
                'expiring_within_days must be between 1 and 3650'
            );
        }
    } else {
        $expiringWithinDays = null;
    }
    $limit = max(1, min(100, (int)($options['limit'] ?? 20)));
    $offset = max(0, (int)($options['offset'] ?? 0));
    $fields = strtolower(trim((string)($options['fields'] ?? 'full')));
    if (!in_array($fields, ['full', 'card'], true)) {
        throw new InvalidArgumentException('fields must be full or card');
    }
    return [
        'query' => trim((string)($options['query'] ?? $options['q'] ?? '')),
        'sort' => $sort,
        'source' => trim((string)($options['source'] ?? '')),
        'locale' => trim((string)($options['locale'] ?? '')),
        'availability_weight' => $availabilityWeight,
        'expiry_weight' => $expiryWeight,
        'minimum_coverage' => $minimumCoverage,
        'expiring_within_days' => $expiringWithinDays,
        'limit' => $limit,
        'offset' => $offset,
        'cursor' => trim((string)($options['cursor'] ?? '')),
        'fields' => $fields,
        'explain' => !array_key_exists('explain', $options) || (bool)$options['explain'],
    ];
}

function recipeCatalogCriteriaHash(array $criteria): string {
    $identity = $criteria;
    unset($identity['limit'], $identity['offset'], $identity['cursor'], $identity['fields'], $identity['explain']);
    return hash('sha256', recipeCatalogJsonEncode(recipeCatalogStableValue($identity)));
}

function recipeCatalogSortSql(array $criteria): string {
    $prefix = $criteria['query'] !== '' ? 'title_match_rank ASC, ' : '';
    return match ($criteria['sort']) {
        'expiry' => $prefix
            . 'expiry_score DESC, coverage DESC, weighted_score DESC, '
            . 'availability_score DESC, text_rank ASC, id ASC',
        'alphabetical' => 'normalized_title ASC, id ASC',
        default => $prefix
            . 'cookable DESC, coverage DESC, weighted_score DESC, '
            . 'availability_score DESC, text_rank ASC, id ASC',
    };
}

function recipeCatalogBrowseCte(
    array $criteria,
    int $revisionId,
    int $catalogMaxId,
    ?int $overlayRevisionId = null,
    bool $useEffectiveProjection = false
): array {
    $params = [
        ':catalog_max_id' => $catalogMaxId,
        ':minimum_coverage' => $criteria['minimum_coverage'] / 100,
    ];
    if ($useEffectiveProjection) {
        $effectiveScoresSql = "
            SELECT score.recipe_id, score.coverage,
                   score.directness, score.expiry_score,
                   score.source_user_score,
                   score.availability_score,
                   score.required_count,
                   score.matched_required_count,
                   score.missing_required_count,
                   score.uncertain_required_count,
                   score.cookable, score.soonest_expiry_days
            FROM recipe_score_effective_sources source
            JOIN recipe_inventory_scores score
              ON score.score_revision_id = source.score_revision_id
             AND score.recipe_id = source.recipe_id
        ";
    } else {
        $params[':revision_id'] = $revisionId;
        $params[':overlay_revision_id'] =
            $overlayRevisionId ?? 0;
        $effectiveScoresSql = "
            SELECT parent.recipe_id,
                   COALESCE(overlay.coverage, parent.coverage)
                       AS coverage,
                   COALESCE(overlay.directness, parent.directness)
                       AS directness,
                   COALESCE(overlay.expiry_score, parent.expiry_score)
                       AS expiry_score,
                   COALESCE(
                       overlay.source_user_score,
                       parent.source_user_score
                   ) AS source_user_score,
                   COALESCE(
                       overlay.availability_score,
                       parent.availability_score
                   ) AS availability_score,
                   COALESCE(
                       overlay.required_count,
                       parent.required_count
                   ) AS required_count,
                   COALESCE(
                       overlay.matched_required_count,
                       parent.matched_required_count
                   ) AS matched_required_count,
                   COALESCE(
                       overlay.missing_required_count,
                       parent.missing_required_count
                   ) AS missing_required_count,
                   COALESCE(
                       overlay.uncertain_required_count,
                       parent.uncertain_required_count
                   ) AS uncertain_required_count,
                   COALESCE(overlay.cookable, parent.cookable)
                       AS cookable,
                   CASE
                       WHEN overlay.recipe_id IS NOT NULL
                       THEN overlay.soonest_expiry_days
                       ELSE parent.soonest_expiry_days
                   END AS soonest_expiry_days
            FROM recipe_inventory_scores parent
            LEFT JOIN recipe_inventory_scores overlay
              ON overlay.score_revision_id = :overlay_revision_id
             AND overlay.recipe_id = parent.recipe_id
            WHERE parent.score_revision_id = :revision_id
        ";
    }
    $sourceWhere = '';
    if ($criteria['source'] === 'non_cookidoo') {
        $sourceWhere = "
            AND NOT EXISTS (
                SELECT 1 FROM recipe_origins source_origin
                WHERE source_origin.recipe_id = c.id
                  AND source_origin.connector = 'cookidoo'
            )
        ";
    } elseif ($criteria['source'] !== '') {
        $sourceWhere = "
            AND EXISTS (
                SELECT 1 FROM recipe_origins source_origin
                WHERE source_origin.recipe_id = c.id
                  AND source_origin.connector = :source
            )
        ";
        $params[':source'] = $criteria['source'];
    }
    $localeWhere = '';
    if ($criteria['locale'] !== '') {
        $localeWhere = "
            AND EXISTS (
                SELECT 1 FROM recipe_origins locale_origin
                WHERE locale_origin.recipe_id = c.id
                  AND (
                      locale_origin.locale IS NULL
                      OR TRIM(locale_origin.locale) = ''
                      OR LOWER(locale_origin.locale) = 'und'
                      OR LOWER(locale_origin.locale) = LOWER(:locale)
                      OR LOWER(locale_origin.locale) LIKE LOWER(:locale) || '-%'
                      OR LOWER(:locale) LIKE LOWER(locale_origin.locale) || '-%'
                  )
            )
        ";
        $params[':locale'] = $criteria['locale'];
    }
    $languageWhere = recipeCookidooLanguageVisibilitySql('c');
    $expiryWhere = '';
    if ($criteria['expiring_within_days'] !== null) {
        $expiryWhere = "
            AND scores.soonest_expiry_days IS NOT NULL
            AND scores.soonest_expiry_days BETWEEN 0 AND :expiring_within_days
        ";
        $params[':expiring_within_days'] = $criteria['expiring_within_days'];
    }
    $availabilityWeight = $criteria['availability_weight'] / 100;
    $expiryWeight = $criteria['expiry_weight'] / 100;
    $weightTotal = $availabilityWeight + $expiryWeight;
    $weightedSql = $weightTotal > 0
        ? sprintf(
            '((%.6F * scores.availability_score) + (%.6F * scores.expiry_score)) / %.6F',
            $availabilityWeight,
            $expiryWeight,
            $weightTotal
        )
        : '0.0';
    $queryJoin = '';
    $querySelect = "2 AS title_match_rank, 0.0 AS text_rank,";
    $queryWhere = '';
    if ($criteria['query'] !== '') {
        $fts = recipeCatalogBuildFtsQuery($criteria['query']);
        if ($fts === '') {
            return ['cte' => '', 'params' => [], 'empty' => true];
        }
        $queryJoin = "
            JOIN recipe_catalog_fts ON recipe_catalog_fts.rowid = c.id
        ";
        $querySelect = "
            CASE
                WHEN LOWER(c.title) = LOWER(:raw_query) THEN 0
                WHEN INSTR(LOWER(c.title), LOWER(:raw_query)) > 0 THEN 1
                ELSE 2
            END AS title_match_rank,
            bm25(recipe_catalog_fts, 5.0, 3.0, 1.5, 1.0) AS text_rank,
        ";
        $queryWhere = "AND recipe_catalog_fts MATCH :fts_query";
        $params[':raw_query'] = $criteria['query'];
        $params[':fts_query'] = $fts;
    }
    $orderSql = recipeCatalogSortSql($criteria);
    $cte = "
        WITH effective_scores AS (
            {$effectiveScoresSql}
        ),
        base AS (
            SELECT c.id,
                   COALESCE(cl.cluster_key, 'recipe:' || c.id) AS dedupe_key,
                   LOWER(c.title) AS normalized_title,
                   {$querySelect}
                   scores.coverage,
                   scores.directness,
                   scores.expiry_score,
                   scores.source_user_score,
                   scores.availability_score,
                   scores.required_count,
                   scores.matched_required_count,
                   scores.missing_required_count,
                   scores.uncertain_required_count,
                   scores.cookable,
                   scores.soonest_expiry_days,
                   {$weightedSql} AS weighted_score
            FROM recipe_catalog c
            {$queryJoin}
            JOIN effective_scores scores
              ON scores.recipe_id = c.id
            LEFT JOIN recipe_clusters cl ON cl.recipe_id = c.id
            LEFT JOIN recipe_user_state us ON us.recipe_id = c.id
            WHERE c.id <= :catalog_max_id
              AND c.deleted_at IS NULL
              AND COALESCE(us.hidden, 0) = 0
              AND scores.coverage >= :minimum_coverage
              {$queryWhere}
              {$expiryWhere}
              {$sourceWhere}
              {$localeWhere}
              {$languageWhere}
        ),
        ranked AS (
            SELECT base.*,
                   ROW_NUMBER() OVER (
                       PARTITION BY dedupe_key
                       ORDER BY {$orderSql}
                   ) AS cluster_rank
            FROM base
        ),
        deduped AS (
            SELECT * FROM ranked WHERE cluster_rank = 1
        )
    ";
    return ['cte' => $cte, 'params' => $params, 'empty' => false, 'order' => $orderSql];
}

function recipeCatalogBindValues(object $stmt, array $params): void {
    foreach ($params as $name => $value) {
        $stmt->bindValue(
            $name,
            $value,
            is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
        );
    }
}

function recipeCatalogCookidooThumbnail(string $imageUrl): string {
    if ($imageUrl === '') {
        return '';
    }
    $enabled = function_exists('env')
        ? filter_var(env('RECIPE_COOKIDOO_THUMBNAIL_REWRITE', 'true'), FILTER_VALIDATE_BOOLEAN)
        : true;
    if (!$enabled) {
        return $imageUrl;
    }
    $host = strtolower((string)parse_url($imageUrl, PHP_URL_HOST));
    if ($host === '' || !str_ends_with($host, '.tmecosys.com')) {
        return $imageUrl;
    }
    return str_replace(
        't_web_rdp_recipe_584x480_1_5x',
        't_web_rdp_recipe_584x480',
        $imageUrl
    );
}

function recipeCatalogCardFromRow(array $row): array {
    $imageUrl = trim((string)($row['image_url'] ?? ''));
    $thumbnailUrl = $imageUrl !== ''
        ? recipeCatalogCookidooThumbnail($imageUrl)
        : '';
    return [
        'id' => (int)$row['id'],
        'dedupe_key' => (string)$row['dedupe_key'],
        'title' => (string)$row['title'],
        'image_url' => $imageUrl !== '' ? $imageUrl : null,
        'thumbnail_url' => $thumbnailUrl !== '' && $thumbnailUrl !== $imageUrl
            ? $thumbnailUrl
            : null,
        'source' => (string)$row['primary_connector'],
        'source_url' => isset($row['source_url']) && trim((string)$row['source_url']) !== ''
            ? (string)$row['source_url']
            : null,
        'coverage' => round((float)$row['coverage'], 6),
        'matched_required' => (int)$row['matched_required_count'],
        'required_total' => (int)$row['required_count'],
        'expiry_score' => round((float)$row['expiry_score'], 6),
        'soonest_expiry_days' => $row['soonest_expiry_days'] !== null
            ? (int)$row['soonest_expiry_days']
            : null,
        'score' => round((float)$row['weighted_score'], 6),
        'cookable' => !empty($row['cookable']),
        'is_stale' => !empty($row['is_stale']),
    ];
}

function recipeCatalogBrowseResult(PDO $db, array $options = []): array {
    $criteria = recipeCatalogNormalizeBrowseOptions($options);
    $criteriaHash = recipeCatalogCriteriaHash($criteria);
    $offset = $criteria['offset'];
    $cursor = null;
    $scoreState = recipeScoreState($db);
    if ($criteria['cursor'] !== '') {
        $cursor = recipeCatalogDecodeCursor($criteria['cursor']);
        if (!hash_equals($criteriaHash, $cursor['criteria_hash'])) {
            throw new InvalidArgumentException('Recipe cursor does not match query criteria');
        }
        if (
            $cursor['cursor_revision'] !== $scoreState['cursor_revision']
        ) {
            throw new InvalidArgumentException(
                'Recipe cursor has expired because the catalog changed'
            );
        }
        $offset = $cursor['offset'];
    }
    $snapshotCatalogRevision = $cursor !== null
        ? $cursor['catalog_revision']
        : $scoreState['catalog_revision'];
    $snapshotCursorRevision = $cursor !== null
        ? $cursor['cursor_revision']
        : $scoreState['cursor_revision'];
    $assertCursorStable = static function () use (
        $db,
        $snapshotCursorRevision
    ): void {
        if (recipeScoreState($db)['cursor_revision'] !== $snapshotCursorRevision) {
            throw new InvalidArgumentException(
                'Recipe catalog changed during the query; retry from the first page'
            );
        }
    };
    $resolved = recipeScoreResolveRevision(
        $db,
        $cursor !== null ? $cursor['revision_id'] : null
    );
    $revision = $resolved['revision'];
    $overlayRevision = $resolved['overlay_revision'] ?? null;
    $effectiveRevision = $overlayRevision ?? $revision;
    $readMetadata = $resolved['read_metadata'];
    $useEffectiveProjection = $overlayRevision === null
        && recipeScoreReadUsesEffectiveProjection(
            $db,
            $resolved['read'],
            $revision
        );
    $catalogMaxId = $cursor !== null
        ? $cursor['catalog_max_id']
        : (int)$revision['catalog_max_id'];
    $built = recipeCatalogBrowseCte(
        $criteria,
        (int)$revision['id'],
        $catalogMaxId,
        $overlayRevision !== null
            ? (int)$overlayRevision['id']
            : null,
        $useEffectiveProjection
    );
    if ($built['empty']) {
        $assertCursorStable();
        return [
            'kind' => 'browse',
            'query' => $criteria['query'],
            'criteria' => $criteria,
            'criteria_hash' => $criteriaHash,
            'snapshot_id' => 'score:' . $revision['id']
                . ':overlay:' . ($overlayRevision['id'] ?? 0)
                . ':catalog:' . $snapshotCatalogRevision,
            'ranking_status' => $resolved['ranking_status'],
            'catalog_revision' => $snapshotCatalogRevision,
            'inventory_revision' =>
                (int)$effectiveRevision['inventory_revision'],
            'ontology_version_id' =>
                $revision['ontology_version_id'] ?? null,
            'total' => 0,
            'offset' => $offset,
            'limit' => $criteria['limit'],
            'items' => [],
            'next_cursor' => null,
            'has_more' => false,
        ] + recipeScoreReadResponseMetadata($readMetadata)
            + ($criteria['fields'] === 'card' ? [] : ['results' => []]);
    }
    $count = $db->prepare($built['cte'] . " SELECT COUNT(*) FROM deduped");
    recipeCatalogBindValues($count, $built['params']);
    $count->execute();
    $total = (int)$count->fetchColumn();

    $page = $db->prepare($built['cte'] . "
        SELECT d.*, c.title, c.image_url, c.primary_connector,
               CASE
                   WHEN (
                       c.stale_at IS NOT NULL
                       AND c.stale_at < CURRENT_TIMESTAMP
                   ) OR (
                       c.cache_expires_at IS NOT NULL
                       AND c.cache_expires_at < CURRENT_TIMESTAMP
                   )
                   THEN 1 ELSE 0
               END AS is_stale,
               (
                   SELECT origin.canonical_url
                   FROM recipe_origins origin
                   WHERE origin.recipe_id = c.id
                     AND origin.canonical_url IS NOT NULL
                     AND TRIM(origin.canonical_url) <> ''
                   ORDER BY origin.id ASC
                   LIMIT 1
               ) AS source_url
        FROM deduped d
        JOIN recipe_catalog c ON c.id = d.id
        ORDER BY {$built['order']}
        LIMIT :page_limit OFFSET :page_offset
    ");
    recipeCatalogBindValues($page, $built['params']);
    $page->bindValue(':page_limit', $criteria['limit'], PDO::PARAM_INT);
    $page->bindValue(':page_offset', $offset, PDO::PARAM_INT);
    $page->execute();
    $rows = $page->fetchAll(PDO::FETCH_ASSOC);
    $items = array_map('recipeCatalogCardFromRow', $rows);
    $assertCursorStable();
    $nextOffset = $offset + count($rows);
    $hasMore = $nextOffset < $total;
    $nextCursor = $hasMore
        ? recipeCatalogEncodeCursor([
            'revision_id' => (int)$revision['id'],
            'catalog_revision' => $snapshotCatalogRevision,
            'cursor_revision' => $snapshotCursorRevision,
            'catalog_max_id' => $catalogMaxId,
            'offset' => $nextOffset,
            'criteria_hash' => $criteriaHash,
        ])
        : null;

    $resultRows = [];
    if ($criteria['fields'] === 'card') {
        $resultRows = $items;
    } else {
        $inventory = $criteria['explain']
            ? recipeInventoryCandidates($db, [
                'exclude_expired' => true,
                'score_date' => (string)$revision['score_date'],
            ])
            : [];
        $overlayExplanationRecipes = [];
        if ($criteria['explain'] && $overlayRevision !== null && $rows) {
            $rowIds = array_map(
                static fn(array $row): int => (int)$row['id'],
                $rows
            );
            $placeholders = implode(
                ',',
                array_fill(0, count($rowIds), '?')
            );
            $affected = $db->prepare("
                SELECT recipe_id
                FROM recipe_score_incremental_recipes
                WHERE score_revision_id = ?
                  AND recipe_id IN ({$placeholders})
            ");
            $affected->execute(array_merge(
                [(int)$overlayRevision['id']],
                $rowIds
            ));
            foreach ($affected->fetchAll(PDO::FETCH_COLUMN) as $recipeId) {
                $overlayExplanationRecipes[(int)$recipeId] = true;
            }
        }
        foreach ($rows as $row) {
            $recipe = recipeCatalogGetById($db, (int)$row['id']);
            if ($recipe === null) {
                continue;
            }
            $result = [
                'recipe' => $recipe,
                'score' => (float)$row['weighted_score'],
                'suggestion_score' => (float)$row['weighted_score'],
                'cookable' => !empty($row['cookable']),
                'components' => [
                    'coverage' => (float)$row['coverage'],
                    'directness' => (float)$row['directness'],
                    'expiry' => (float)$row['expiry_score'],
                    'source_user' => (float)$row['source_user_score'],
                ],
                'missing_required_count' => (int)$row['missing_required_count'],
                'uncertain_required_count' =>
                    (int)($row['uncertain_required_count'] ?? 0),
            ];
            if ($criteria['query'] !== '') {
                $result['text_rank'] = (float)$row['text_rank'];
                $result['title_match_rank'] = (int)$row['title_match_rank'];
            }
            if ($criteria['explain']) {
                if (
                    ($revision['ontology_version_id'] ?? null) !== null
                    && ($revision['scoring_model'] ?? '')
                        === 'faceted-ontology-v3'
                    && function_exists(
                        'ingredientOntologyV3RecipeExplanation'
                    )
                ) {
                    $result['explain'] =
                        ingredientOntologyV3RecipeExplanation(
                            $db,
                            isset(
                                $overlayExplanationRecipes[
                                    (int)$row['id']
                                ]
                            )
                                ? (int)$overlayRevision['id']
                                : (
                                    $useEffectiveProjection
                                        ? recipeScoreEffectiveSourceRevisionId(
                                            $db,
                                            (int)$row['id'],
                                            (int)$revision['id']
                                        )
                                        : (int)$revision['id']
                                ),
                            (int)$row['id']
                        );
                } else {
                    $ranking = recipeCatalogRankRecipe(
                        $db,
                        $recipe,
                        $inventory,
                        $criteria['sort'] === 'expiry'
                            ? 'expiring'
                            : 'stocked'
                    );
                    $result['explain'] = [
                        'missing_required' => $ranking['missing_required'],
                        'uncertain_required' => [],
                        'optional_unmatched' => [],
                        'ingredient_matches' =>
                            $ranking['ingredient_matches'],
                    ];
                }
                if ($criteria['query'] !== '') {
                    $result['explain']['text'] = [
                        'query' => recipeCatalogBuildFtsQuery($criteria['query']),
                        'rank' => (float)$row['text_rank'],
                    ];
                }
            }
            $resultRows[] = $result;
        }
    }
    $response = [
        'kind' => 'browse',
        'query' => $criteria['query'],
        'mode' => $criteria['sort'] === 'expiry' ? 'expiring' : 'stocked',
        'criteria' => $criteria,
        'criteria_hash' => $criteriaHash,
        'snapshot_id' => 'score:' . $revision['id']
            . ':overlay:' . ($overlayRevision['id'] ?? 0)
            . ':catalog:' . $snapshotCatalogRevision,
        'ranking_status' => $resolved['ranking_status'],
        'catalog_revision' => $snapshotCatalogRevision,
        'inventory_revision' =>
            (int)$effectiveRevision['inventory_revision'],
        'ontology_version_id' => $revision['ontology_version_id'] ?? null,
        'total' => $total,
        'offset' => $offset,
        'limit' => $criteria['limit'],
        'items' => $items,
        'next_cursor' => $nextCursor,
        'has_more' => $hasMore,
    ] + recipeScoreReadResponseMetadata($readMetadata);
    if ($criteria['fields'] !== 'card') {
        $response['results'] = $resultRows;
    }
    return $response;
}

function recipeCatalogCardsByIds(PDO $db, array $recipeIds): array {
    $recipeIds = array_values(array_unique(array_filter(
        array_map('intval', $recipeIds),
        static fn(int $id): bool => $id > 0
    )));
    if (!$recipeIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($recipeIds), '?'));
    $languageVisibility =
        recipeCookidooLanguageVisibilitySql('c');
    $stmt = $db->prepare("
        SELECT c.id, COALESCE(cl.cluster_key, 'recipe:' || c.id) AS dedupe_key,
               c.title, c.image_url, c.primary_connector,
               CASE
                   WHEN (
                       c.stale_at IS NOT NULL
                       AND c.stale_at < CURRENT_TIMESTAMP
                   ) OR (
                       c.cache_expires_at IS NOT NULL
                       AND c.cache_expires_at < CURRENT_TIMESTAMP
                   )
                   THEN 1 ELSE 0
               END AS is_stale,
               (
                   SELECT origin.canonical_url
                   FROM recipe_origins origin
                   WHERE origin.recipe_id = c.id
                     AND origin.canonical_url IS NOT NULL
                     AND TRIM(origin.canonical_url) <> ''
                   ORDER BY origin.id ASC
                   LIMIT 1
               ) AS source_url
        FROM recipe_catalog c
        LEFT JOIN recipe_clusters cl ON cl.recipe_id = c.id
        WHERE c.id IN ({$placeholders}) AND c.deleted_at IS NULL
        {$languageVisibility}
    ");
    $stmt->execute($recipeIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $recipes = recipeScoreLoadRecipes($db, $recipeIds);
    $inventory = recipeInventoryCandidates($db, ['exclude_expired' => true]);
    $byId = [];
    foreach ($rows as $row) {
        $recipeId = (int)$row['id'];
        if (!isset($recipes[$recipeId])) {
            continue;
        }
        $score = recipeScoreBuildRow($db, $recipes[$recipeId], $inventory);
        $row += $score;
        $row['weighted_score'] = (
            (float)$score['availability_score']
            + (0.25 * (float)$score['expiry_score'])
        ) / 1.25;
        $byId[(int)$row['id']] = recipeCatalogCardFromRow($row);
    }
    $cards = [];
    foreach ($recipeIds as $recipeId) {
        if (isset($byId[$recipeId])) {
            $cards[] = $byId[$recipeId];
        }
    }
    return $cards;
}

function recipeCatalogRecommendationResult(PDO $db, array $options = []): array {
    $total = max(5, min(100, (int)($options['limit'] ?? 30)));
    $availabilityTarget = (int)floor($total * 0.40);
    $expiryTarget = (int)floor($total * 0.40);
    $fillTarget = $total - $availabilityTarget - $expiryTarget;
    $common = [
        'query' => '',
        'source' => (string)($options['source'] ?? ''),
        'locale' => (string)($options['locale'] ?? ''),
        'minimum_coverage' => 0,
        'fields' => 'card',
        'limit' => 100,
        'availability_weight' => 100,
        'expiry_weight' => 25,
    ];
    $availability = recipeCatalogBrowseResult($db, $common + ['sort' => 'availability']);
    $expiry = recipeCatalogBrowseResult($db, $common + ['sort' => 'expiry']);
    $fallback = recipeCatalogBrowseResult($db, $common + ['sort' => 'alphabetical']);
    $inventoryRevision = (int)$availability['inventory_revision'];
    $seed = recipeScoreCurrentDate() . ':' . $inventoryRevision
        . ':' . strtolower((string)($options['locale'] ?? ''));

    $seen = [];
    $pick = static function (array $pool, int $count) use (&$seen): array {
        $selected = [];
        foreach ($pool as $card) {
            $key = (string)$card['dedupe_key'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $selected[] = $card;
            if (count($selected) >= $count) {
                break;
            }
        }
        return $selected;
    };
    $availabilityLane = $pick($availability['items'], $availabilityTarget);
    $expiryPool = array_values(array_filter(
        $expiry['items'],
        static fn(array $card): bool => (float)$card['expiry_score'] > 0
    ));
    $expiryLane = $pick($expiryPool, $expiryTarget);
    $fillPool = array_values(array_merge(
        $availability['items'],
        $expiry['items'],
        $fallback['items']
    ));
    usort($fillPool, static function (array $a, array $b) use ($seed): int {
        $aHash = hash('sha256', $seed . ':' . $a['id']);
        $bHash = hash('sha256', $seed . ':' . $b['id']);
        return $aHash <=> $bHash;
    });
    $fillLane = $pick($fillPool, $fillTarget);
    $fillToTarget = static function (array $lane, int $target) use (
        $pick,
        $fillPool
    ): array {
        if (count($lane) < $target) {
            $lane = array_merge($lane, $pick($fillPool, $target - count($lane)));
        }
        return $lane;
    };
    $availabilityLane = $fillToTarget($availabilityLane, $availabilityTarget);
    $expiryLane = $fillToTarget($expiryLane, $expiryTarget);
    $fillLane = $fillToTarget($fillLane, $fillTarget);
    $indices = ['a' => 0, 'e' => 0, 'f' => 0];
    $lanes = ['a' => $availabilityLane, 'e' => $expiryLane, 'f' => $fillLane];
    $items = [];
    $pattern = ['a', 'e', 'a', 'e', 'f'];
    while (count($items) < $total) {
        $added = false;
        foreach ($pattern as $lane) {
            if (isset($lanes[$lane][$indices[$lane]])) {
                $items[] = $lanes[$lane][$indices[$lane]++];
                $added = true;
                if (count($items) >= $total) {
                    break;
                }
            }
        }
        if (!$added) {
            break;
        }
    }
    foreach (['a', 'e', 'f'] as $lane) {
        while (isset($lanes[$lane][$indices[$lane]]) && count($items) < $total) {
            $items[] = $lanes[$lane][$indices[$lane]++];
        }
    }
    return [
        'kind' => 'recommendations',
        'recommendation_id' => hash(
            'sha256',
            $seed . ':' . implode(',', array_column($items, 'id'))
        ),
        'ranking_status' => $availability['ranking_status'],
        'catalog_revision' => $availability['catalog_revision'],
        'inventory_revision' => $inventoryRevision,
        'preview' => $availability['preview'],
        'revision' => $availability['revision'],
        'preview_diagnostics' =>
            $availability['preview_diagnostics'],
        'capabilities' => $availability['capabilities'],
        'items' => array_slice($items, 0, $total),
        'degraded' => count($items) < $total,
    ];
}
