<?php
declare(strict_types=1);

const INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL =
    'immutable-requirements-v2';
const INGREDIENT_ONTOLOGY_V3_REQUIREMENT_SCORING_MODEL =
    'faceted-ontology-v3-requirements';
const INGREDIENT_ONTOLOGY_V3_REQUIREMENT_READY_RETENTION = 2;
const INGREDIENT_ONTOLOGY_V3_REQUIREMENT_FAILED_RETENTION = 1;

function ingredientOntologyV3PruneRequirementRevisions(
    PDO $db,
    ?int $ontologyVersionId = null,
    int $readyRetention =
        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_READY_RETENTION,
    bool $exclusiveBuildLockHeld = false
): array {
    if ($exclusiveBuildLockHeld) {
        return ingredientOntologyV3PruneRequirementRevisionsUnlocked(
            $db,
            $ontologyVersionId,
            $readyRetention,
            true
        );
    }
    $lock = ingredientOntologyV3AcquireLock($db);
    if ($lock === false) {
        return [
            'locked' => true,
            'abandoned_failed' => 0,
            'abandoned_score_builds_failed' => 0,
            'failed_payloads_cleaned' => 0,
            'revisions_deleted' => 0,
            'lineage_links_severed' => 0,
            'kept_revision_ids' => [],
        ];
    }
    try {
        return ingredientOntologyV3PruneRequirementRevisionsUnlocked(
            $db,
            $ontologyVersionId,
            $readyRetention,
            false
        );
    } finally {
        ingredientOntologyV3ReleaseLock($lock);
    }
}

function ingredientOntologyV3PruneRequirementRevisionsUnlocked(
    PDO $db,
    ?int $ontologyVersionId = null,
    int $readyRetention =
        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_READY_RETENTION,
    bool $exclusiveBuildLockHeld = false
): array {
    if ($db->inTransaction()) {
        throw new RuntimeException(
            'requirement revision pruning cannot run inside a transaction'
        );
    }
    $readyRetention = max(1, min(10, $readyRetention));
    $abandonedScoreBuilds = recipeScoreFailAbandonedBuilds($db);
    recipeScorePruneRevisions($db);
    $pruneGuardWasEnabled =
        ingredientOntologyV3RequirementPruneGuardEnabled($db);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $params = [];
        $versionWhere = '';
        if ($ontologyVersionId !== null) {
            $versionWhere = ' AND ontology_version_id = ?';
            $params[] = $ontologyVersionId;
        }
        $abandonedCondition = $exclusiveBuildLockHeld
            ? ''
            : " AND created_at < datetime('now', '-1 hour')";
        $abandoned = $db->prepare("
            UPDATE ingredient_ontology_requirement_revisions
            SET status = 'failed',
                last_error = CASE
                    WHEN TRIM(last_error) = ''
                    THEN 'abandoned requirement build'
                    ELSE last_error
                END,
                completed_at = COALESCE(
                    completed_at,
                    CURRENT_TIMESTAMP
                )
            WHERE status = 'building'
              {$abandonedCondition}
              {$versionWhere}
        ");
        $abandoned->execute($params);
        $abandonedCount = $abandoned->rowCount();

        $failedIdsStmt = $db->prepare("
            SELECT id
            FROM ingredient_ontology_requirement_revisions
            WHERE status = 'failed' {$versionWhere}
        ");
        $failedIdsStmt->execute($params);
        $failedIds = array_map(
            'intval',
            $failedIdsStmt->fetchAll(PDO::FETCH_COLUMN)
        );
        if ($failedIds) {
            $placeholders = implode(
                ',',
                array_fill(0, count($failedIds), '?')
            );
            $db->prepare("
                DELETE FROM ingredient_ontology_requirement_input_rows
                WHERE requirement_revision_id IN ({$placeholders})
            ")->execute($failedIds);
            $db->prepare("
                DELETE FROM ingredient_ontology_requirement_input_recipes
                WHERE requirement_revision_id IN ({$placeholders})
            ")->execute($failedIds);
            $db->prepare("
                DELETE FROM ingredient_ontology_requirement_recipe_states
                WHERE requirement_revision_id IN ({$placeholders})
            ")->execute($failedIds);
            $db->prepare("
                DELETE FROM ingredient_ontology_recipe_requirements
                WHERE requirement_revision_id IN ({$placeholders})
            ")->execute($failedIds);
        }

        $referenced = array_map('intval', $db->query("
            SELECT DISTINCT requirement_revision_id
            FROM recipe_score_revisions
            WHERE requirement_revision_id IS NOT NULL
              AND status = 'ready'
        ")->fetchAll(PDO::FETCH_COLUMN));
        $keep = $referenced;
        $ready = $db->prepare("
            SELECT id, parent_revision_id
            FROM ingredient_ontology_requirement_revisions
            WHERE status = 'ready' {$versionWhere}
            ORDER BY completed_at DESC, id DESC
        ");
        $ready->execute($params);
        $readyRows = $ready->fetchAll(PDO::FETCH_ASSOC);
        $unreferencedKept = 0;
        foreach ($readyRows as $row) {
            $id = (int)$row['id'];
            if (in_array($id, $referenced, true)) {
                continue;
            }
            if ($unreferencedKept >= $readyRetention) {
                break;
            }
            $keep[] = $id;
            $unreferencedKept++;
        }
        $failedKeep = $db->prepare("
            SELECT id
            FROM ingredient_ontology_requirement_revisions
            WHERE status = 'failed' {$versionWhere}
            ORDER BY completed_at DESC, id DESC
            LIMIT " . INGREDIENT_ONTOLOGY_V3_REQUIREMENT_FAILED_RETENTION
        );
        $failedKeep->execute($params);
        $keep = array_merge(
            $keep,
            array_map('intval', $failedKeep->fetchAll(PDO::FETCH_COLUMN))
        );
        $building = $db->prepare("
            SELECT id
            FROM ingredient_ontology_requirement_revisions
            WHERE status = 'building' {$versionWhere}
        ");
        $building->execute($params);
        $keep = array_merge(
            $keep,
            array_map('intval', $building->fetchAll(PDO::FETCH_COLUMN))
        );
        $rows = $db->query("
            SELECT id, parent_revision_id, status
            FROM ingredient_ontology_requirement_revisions
        ")->fetchAll(PDO::FETCH_ASSOC);
        $parents = [];
        foreach ($rows as $row) {
            $parents[(int)$row['id']] = $row['parent_revision_id'] !== null
                ? (int)$row['parent_revision_id']
                : null;
        }
        $keep = array_values(array_unique(array_filter(
            $keep,
            static fn(int $id): bool => $id > 0
        )));
        $referencedSet = array_fill_keys($referenced, true);
        foreach ($referenced as $referencedId) {
            $id = $referencedId;
            $seen = [];
            while ($id > 0 && !isset($seen[$id])) {
                $seen[$id] = true;
                $parentId = $parents[$id] ?? null;
                if ($parentId === null) {
                    break;
                }
                if (!in_array($parentId, $keep, true)) {
                    $keep[] = $parentId;
                }
                if (!isset($referencedSet[$parentId])) {
                    break;
                }
                $id = $parentId;
            }
        }
        $keep = array_values(array_unique($keep));
        sort($keep, SORT_NUMERIC);
        $keepSet = array_fill_keys($keep, true);
        $severIds = [];
        foreach ($keep as $id) {
            if (isset($referencedSet[$id])) {
                continue;
            }
            $parentId = $parents[$id] ?? null;
            if ($parentId === null || isset($keepSet[$parentId])) {
                continue;
            }
            $severIds[] = $id;
        }
        if ($severIds) {
            $nestedGuardWasEnabled =
                ingredientOntologyV3RequirementPruneGuardEnabled($db);
            ingredientOntologyV3SetRequirementPruneGuard($db, true);
            try {
                $placeholders = implode(
                    ',',
                    array_fill(0, count($severIds), '?')
                );
                $db->prepare("
                    UPDATE ingredient_ontology_requirement_revisions
                    SET parent_revision_id = NULL
                    WHERE id IN ({$placeholders})
                ")->execute($severIds);
            } finally {
                ingredientOntologyV3SetRequirementPruneGuard(
                    $db,
                    $nestedGuardWasEnabled
                );
            }
        }

        $candidateSql = "
            SELECT id
            FROM ingredient_ontology_requirement_revisions
            WHERE status IN ('ready', 'failed', 'retired')
            {$versionWhere}
        ";
        $candidateStmt = $db->prepare($candidateSql);
        $candidateStmt->execute($params);
        $deleteIds = array_values(array_diff(
            array_map(
                'intval',
                $candidateStmt->fetchAll(PDO::FETCH_COLUMN)
            ),
            $keep
        ));
        $deletedCount = 0;
        if ($deleteIds) {
            $nestedGuardWasEnabled =
                ingredientOntologyV3RequirementPruneGuardEnabled($db);
            ingredientOntologyV3SetRequirementPruneGuard($db, true);
            try {
                $placeholders = implode(
                    ',',
                    array_fill(0, count($deleteIds), '?')
                );
                $delete = $db->prepare("
                    DELETE FROM ingredient_ontology_requirement_revisions
                    WHERE id IN ({$placeholders})
                ");
                $delete->execute($deleteIds);
                $deletedCount = $delete->rowCount();
            } finally {
                ingredientOntologyV3SetRequirementPruneGuard(
                    $db,
                    $nestedGuardWasEnabled
                );
            }
        }
        $db->exec('COMMIT');
        return [
            'abandoned_failed' => $abandonedCount,
            'abandoned_score_builds_failed' =>
                $abandonedScoreBuilds,
            'failed_payloads_cleaned' => count($failedIds),
            'revisions_deleted' => $deletedCount,
            'lineage_links_severed' => count($severIds),
            'kept_revision_ids' => $keep,
        ];
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $e;
    } finally {
        ingredientOntologyV3SetRequirementPruneGuard(
            $db,
            $pruneGuardWasEnabled
        );
    }
}

function ingredientOntologyV3ProviderTitleIsGeneric(string $title): bool {
    $normalized = ingredientOntologyV3NormalizeLabel($title);
    return $normalized !== '' && (bool)preg_match(
        '/\b(any type|any kind|all types|any oil|any colou?r|any size|'
            . 'any variety|all varieties|any form)\b/u',
        $normalized
    );
}

function ingredientOntologyV3NormalizeProviderLabel(string $value): array {
    $normalized = ingredientOntologyV3NormalizeLabel($value);
    $safe = mb_strlen($normalized, 'UTF-8') <= 200;
    return [
        'normalized' => $safe
            ? $normalized
            : 'oversize-sha256:' . hash('sha256', $normalized),
        'safe' => $safe,
        'full_hash' => hash('sha256', $normalized),
        'full_length' => mb_strlen($normalized, 'UTF-8'),
    ];
}

function ingredientOntologyV3ResolveOpaqueProviderTitle(
    array $labelIndex,
    string $title
): array {
    $normalization = ingredientOntologyV3NormalizeProviderLabel($title);
    $normalized = $normalization['normalized'];
    if (!$normalization['safe']) {
        return [
            'status' => 'unresolved',
            'entity_id' => null,
            'confidence' => 0.0,
            'mapping_source' =>
                'provider_title_normalization_unsafe',
            'attributes' => [],
            'normalization' => $normalization,
        ];
    }
    if ($normalized === '') {
        return [
            'status' => 'unresolved',
            'entity_id' => null,
            'confidence' => 0.0,
            'mapping_source' => 'provider_title_missing',
            'attributes' => [],
        ];
    }
    $entries = array_values(array_filter(
        $labelIndex[$normalized] ?? [],
        static fn(array $entry): bool =>
            ($entry['required_cohort'] ?? null) === null
            && ($entry['required_evidence_kind'] ?? null) === null
            && ($entry['required_evidence_key'] ?? null) === null
            && ingredientOntologyV3LanguageMatches(
                (string)$entry['language'],
                'en'
            )
            && !in_array(
                (string)($entry['identity_role'] ?? ''),
                ['structural_category', 'staple_class'],
                true
            )
    ));
    if (!$entries) {
        return [
            'status' => 'unresolved',
            'entity_id' => null,
            'confidence' => 0.0,
            'mapping_source' => 'provider_title_unresolved',
            'attributes' => [],
        ];
    }
    $entities = [];
    foreach ($entries as $entry) {
        $entities[(int)$entry['entity_id']] = $entry;
    }
    if (count($entities) !== 1) {
        return [
            'status' => 'ambiguous',
            'entity_id' => null,
            'confidence' => 0.0,
            'mapping_source' => 'provider_title_ambiguous',
            'attributes' => [],
            'candidates' => array_values(array_map(
                static fn(array $entry): array => [
                    'entity_id' => (int)$entry['entity_id'],
                    'slug' => (string)$entry['slug'],
                ],
                $entities
            )),
        ];
    }
    $entry = array_values($entities)[0];
    return [
        'status' => 'accepted',
        'entity_id' => (int)$entry['entity_id'],
        'entity_slug' => (string)$entry['slug'],
        'entity_name' => (string)$entry['name'],
        'confidence' => $entry['kind'] === 'exact_alias' ? 1.0 : 0.99,
        'mapping_source' => 'provider_' . (string)$entry['kind'],
        'attributes' => $entry['attributes'],
        'label_id' => (int)$entry['label_id'],
        'matched_label' => $normalized,
    ];
}

function ingredientOntologyV3ProviderSourceRows(
    PDO $db,
    int $versionId
): PDOStatement {
    $stmt = $db->prepare("
        SELECT si.id AS owner_id, si.recipe_id, si.position,
               si.name, si.normalized_name, si.source_group_index,
               si.source_group_position, si.source_group_title,
               si.source_ingredient_ref, si.source_default_title,
               si.source_unit_ref, si.source_optional,
               si.source_shopping_category_ref,
               si.source_quantity, si.source_quantity_max,
               si.source_unit, si.source_amount_text,
               si.created_at, si.updated_at,
               c.language,
               COALESCE(
                   NULLIF(scope_origin.connector, ''),
                   NULLIF(c.primary_connector, ''),
                   'unknown_legacy_adapter'
               ) AS connector,
               COALESCE(
                   NULLIF(scope_origin.metadata_version, ''),
                   'unknown_legacy_adapter'
               ) AS metadata_version,
               COALESCE(
                   NULLIF(scope_origin.metadata_schema_version, ''),
                   'unknown_legacy_adapter'
               ) AS metadata_schema_version,
               COALESCE(scope_origin.external_id, '')
                   AS origin_external_id,
               COALESCE(scope_origin.locale, '') AS origin_locale,
               m.id AS mapping_id, m.owner_fingerprint,
               m.entity_id AS mapping_entity_id,
               m.status AS mapping_status,
               m.confidence AS mapping_confidence,
               m.mapping_source, m.attributes_json,
               m.evidence_json, m.is_staple,
               m.provider_term_id, m.identity_basis
        FROM recipe_source_ingredients si
        JOIN recipe_catalog c ON c.id = si.recipe_id
        LEFT JOIN recipe_origins scope_origin
          ON scope_origin.id = (
              SELECT ro.id
              FROM recipe_origins ro
              WHERE ro.recipe_id = si.recipe_id
                AND ro.connector = c.primary_connector
              ORDER BY ro.id
              LIMIT 1
          )
        LEFT JOIN ingredient_ontology_mappings m
          ON m.ontology_version_id = ?
         AND m.owner_type = 'recipe_source_ingredient'
         AND m.owner_id = si.id
        ORDER BY connector, metadata_schema_version,
                 COALESCE(si.source_ingredient_ref, ''), si.id
    ");
    $stmt->execute([$versionId]);
    return $stmt;
}

function ingredientOntologyV3ProviderGroupFinalize(
    PDO $db,
    int $versionId,
    array $labelIndex,
    array $group
): int {
    $titles = array_values($group['titles']);
    usort(
        $titles,
        static fn(array $left, array $right): int =>
            strcmp($left['normalized'], $right['normalized'])
    );
    $resolutions = [];
    $resolvedEntities = [];
    $hasAmbiguity = false;
    $isGeneric = false;
    $hasUnsafeNormalization = false;
    foreach ($titles as $title) {
        $resolution = ingredientOntologyV3ResolveOpaqueProviderTitle(
            $labelIndex,
            $title['raw']
        );
        $resolutions[] = [
            'title_hash' => $title['hash'],
            'status' => $resolution['status'],
            'entity_id' => $resolution['entity_id'],
            'mapping_source' => $resolution['mapping_source'],
        ];
        if ($resolution['status'] === 'accepted') {
            $resolvedEntities[(int)$resolution['entity_id']] = $resolution;
        } elseif ($resolution['status'] === 'ambiguous') {
            $hasAmbiguity = true;
        }
        $isGeneric = $isGeneric
            || ingredientOntologyV3ProviderTitleIsGeneric($title['raw']);
        $hasUnsafeNormalization = $hasUnsafeNormalization
            || empty($title['identity_safe']);
    }
    $distinctTitleCount = count($titles);
    if ($distinctTitleCount === 0) {
        $consistency = 'missing';
    } elseif (
        count($resolvedEntities) > 1
        || ($hasAmbiguity && $distinctTitleCount > 0)
    ) {
        $consistency = 'conflicted';
    } elseif ($distinctTitleCount > 1) {
        $consistency = 'variant';
    } else {
        $consistency = 'consistent';
    }
    $singleResolution = $distinctTitleCount === 1
        ? ingredientOntologyV3ResolveOpaqueProviderTitle(
            $labelIndex,
            $titles[0]['raw']
        )
        : null;
    $mappingStatus = 'unresolved';
    $reviewState = 'pending';
    $entityId = null;
    $attributes = [];
    $provenance = 'provider_observation';
    if ($consistency === 'conflicted') {
        $mappingStatus = 'ambiguous';
        $reviewState = 'quarantined';
        $provenance = 'provider_title_conflict';
    } elseif ($consistency === 'variant') {
        $mappingStatus = 'candidate';
        $reviewState = 'quarantined';
        $provenance = 'provider_title_variant';
    } elseif ($consistency === 'missing') {
        $provenance = 'provider_title_missing';
    } elseif ($hasUnsafeNormalization) {
        $mappingStatus = 'candidate';
        $reviewState = 'quarantined';
        $provenance = 'provider_normalization_unsafe';
    } elseif ($isGeneric) {
        $mappingStatus = 'candidate';
        $reviewState = 'quarantined';
        $provenance = 'provider_generic_title';
    } elseif (
        is_array($singleResolution)
        && $singleResolution['status'] === 'accepted'
    ) {
        $mappingStatus = 'accepted';
        $reviewState = 'accepted';
        $entityId = (int)$singleResolution['entity_id'];
        $attributes = $singleResolution['attributes'];
        $provenance = 'provider_exact_accepted_alias';
    } elseif (
        is_array($singleResolution)
        && $singleResolution['status'] === 'ambiguous'
    ) {
        $mappingStatus = 'ambiguous';
        $reviewState = 'quarantined';
        $provenance = 'provider_inverse_ambiguity';
    }
    $defaultTitle = $titles ? (string)$titles[0]['raw'] : null;
    $normalizedTitle = $titles
        ? (string)$titles[0]['normalized']
        : null;
    $titleHash = $titles ? (string)$titles[0]['hash'] : null;
    $evidenceTitles = array_slice(array_map(
        static fn(array $title): array => [
            'text' => $title['raw'],
            'normalized' => $title['normalized'],
            'hash' => $title['hash'],
            'observed_rows' => $title['count'],
            'identity_safe' => $title['identity_safe'],
            'normalization_hash' => $title['normalization_hash'],
            'normalization_length' => $title['normalization_length'],
        ],
        $titles
    ), 0, 20);
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_provider_terms (
            ontology_version_id, connector, metadata_schema_version,
            namespace, provider_ref, default_title,
            normalized_default_title, title_hash, observed_row_count,
            distinct_title_count, first_seen_at, last_seen_at,
            consistency_state, is_generic, mapping_status, review_state,
            entity_id, attributes_json, evidence_json, provenance,
            updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, CURRENT_TIMESTAMP)
    ");
    $insert->execute([
        $versionId,
        $group['connector'],
        $group['metadata_schema_version'],
        $group['namespace'],
        $group['provider_ref'],
        $defaultTitle,
        $normalizedTitle,
        $titleHash,
        $group['observed_row_count'],
        $distinctTitleCount,
        $group['first_seen_at'],
        $group['last_seen_at'],
        $consistency,
        $isGeneric ? 1 : 0,
        $mappingStatus,
        $reviewState,
        $entityId,
        ingredientOntologyV3Json($attributes),
        ingredientOntologyV3Json([
            'titles' => $evidenceTitles,
            'resolutions' => array_slice($resolutions, 0, 20),
            'majority_vote_used' => false,
            'provider_text_created_ontology_content' => false,
            'unsafe_normalization' => $hasUnsafeNormalization,
        ]),
        $provenance,
    ]);
    return (int)$db->lastInsertId();
}

function ingredientOntologyV3ProviderHardConflicts(
    array $providerAttributes,
    array $localAttributes
): array {
    $conflicts = [];
    foreach ($providerAttributes as $facet => $providerValue) {
        if (
            !ingredientOntologyV3FacetIsDefining((string)$facet)
            || !isset($localAttributes[$facet])
            || (string)$localAttributes[$facet] === (string)$providerValue
        ) {
            continue;
        }
        $conflicts[(string)$facet] = [
            'provider' => (string)$providerValue,
            'local' => (string)$localAttributes[$facet],
        ];
    }
    $processedStates = [
        'cooked', 'dried', 'frozen', 'smoked', 'pickled',
        'roasted', 'baked', 'fermented', 'ultra_pasteurized',
    ];
    if (
        ($providerAttributes['state'] ?? null) === 'fresh'
        && in_array(
            (string)($localAttributes['processing'] ?? ''),
            $processedStates,
            true
        )
    ) {
        $conflicts['state_processing'] = [
            'provider' => 'fresh',
            'local' => (string)$localAttributes['processing'],
        ];
    }
    if (
        ($localAttributes['state'] ?? null) === 'fresh'
        && in_array(
            (string)($providerAttributes['processing'] ?? ''),
            $processedStates,
            true
        )
    ) {
        $conflicts['processing_state'] = [
            'provider' => (string)$providerAttributes['processing'],
            'local' => 'fresh',
        ];
    }
    return $conflicts;
}

function ingredientOntologyV3ProviderMappingEvidence(
    string $existingJson,
    array $providerEvidence
): string {
    $existing = json_decode($existingJson, true);
    if (!is_array($existing)) {
        $existing = [];
    }
    $existing['provider_term'] = $providerEvidence;
    $json = ingredientOntologyV3Json($existing);
    if (strlen($json) <= 32768) {
        return $json;
    }
    return ingredientOntologyV3Json([
        'provider_term' => $providerEvidence,
        'prior_evidence_truncated' => true,
    ]);
}

function ingredientOntologyV3ApplyProviderRowAssertion(
    PDO $db,
    int $versionId,
    array $row,
    ?array $term,
    array $facetMap,
    array $entitiesBySlug
): array {
    $mappingId = (int)($row['mapping_id'] ?? 0);
    if ($mappingId <= 0) {
        return ['updated' => false, 'reason' => 'mapping_missing'];
    }
    $providerRef = trim((string)($row['source_ingredient_ref'] ?? ''));
    $defaultTitle = trim((string)($row['source_default_title'] ?? ''));
    $localAttributes = json_decode(
        (string)($row['attributes_json'] ?? '{}'),
        true
    );
    if (!is_array($localAttributes)) {
        $localAttributes = [];
    }
    $entityId = $row['mapping_entity_id'] !== null
        ? (int)$row['mapping_entity_id']
        : null;
    $status = (string)($row['mapping_status'] ?? 'unresolved');
    $confidence = (float)($row['mapping_confidence'] ?? 0);
    $mappingSource = (string)($row['mapping_source'] ?? 'unresolved');
    $identityBasis = 'local_label';
    $providerTermId = $term !== null ? (int)$term['id'] : null;
    $hardConflicts = [];
    $baseConflict = false;
    $mergedAttributes = $localAttributes;
    if ($providerRef === '') {
        $identityBasis = 'unknown_legacy_adapter';
    } elseif ($term === null || $defaultTitle === '') {
        $identityBasis = 'provider_missing';
    } elseif ((string)$term['mapping_status'] !== 'accepted') {
        $identityBasis = in_array(
            (string)$term['consistency_state'],
            ['variant', 'conflicted'],
            true
        ) || !empty($term['is_generic'])
            ? 'provider_variant'
            : 'provider_candidate';
    } else {
        $providerEntityId = (int)$term['entity_id'];
        $providerAttributes = json_decode(
            (string)$term['attributes_json'],
            true
        );
        if (!is_array($providerAttributes)) {
            $providerAttributes = [];
        }
        $hardConflicts = ingredientOntologyV3ProviderHardConflicts(
            $providerAttributes,
            $localAttributes
        );
        $baseConflict = $status === 'accepted'
            && $entityId !== null
            && $entityId !== $providerEntityId;
        if ($baseConflict || $hardConflicts) {
            $status = 'ambiguous';
            $entityId = null;
            $confidence = 0.0;
            $mappingSource = 'provider_local_conflict';
            $identityBasis = 'provider_local_conflict';
        } elseif ($status === 'accepted' && $entityId !== null) {
            $mergedAttributes = $localAttributes;
            ksort($mergedAttributes, SORT_STRING);
            $identityBasis = 'local_label';
        } else {
            $identityBasis = 'provider_candidate';
        }
    }
    $providerEvidence = [
        'provider_term_id' => $providerTermId,
        'connector' => (string)$row['connector'],
        'metadata_schema_version' =>
            (string)$row['metadata_schema_version'],
        'namespace' => ingredientOntologyV3ProviderNamespace($providerRef),
        'provider_ref' => $providerRef !== '' ? $providerRef : null,
        'provider_ref_provenance' => $providerRef !== ''
            ? 'persisted_source_ingredient_ref'
            : 'unknown_legacy_adapter',
        'default_title_hash' => $defaultTitle !== ''
            ? hash('sha256', $defaultTitle)
            : null,
        'consistency_state' =>
            $term['consistency_state'] ?? 'missing',
        'term_mapping_status' =>
            $term['mapping_status'] ?? 'unresolved',
        'base_conflict' => $baseConflict,
        'hard_attribute_conflicts' => $hardConflicts,
        'provider_title_attributes' =>
            $term !== null
                ? json_decode(
                    (string)($term['attributes_json'] ?? '{}'),
                    true
                )
                : [],
        'provider_supplies_base_only' => true,
        'provider_supplies_base_and_explicit_attributes' => false,
        'provider_ref_never_direct_identity' => true,
    ];
    $update = $db->prepare("
        UPDATE ingredient_ontology_mappings SET
            provider_term_id = ?,
            identity_basis = ?,
            entity_id = ?,
            status = ?,
            confidence = ?,
            mapping_source = ?,
            attributes_json = ?,
            evidence_json = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $update->execute([
        $providerTermId,
        $identityBasis,
        $entityId,
        $status,
        $confidence,
        $mappingSource,
        ingredientOntologyV3Json($mergedAttributes),
        ingredientOntologyV3ProviderMappingEvidence(
            (string)($row['evidence_json'] ?? '{}'),
            $providerEvidence
        ),
        $mappingId,
    ]);
    ingredientOntologyV3ReplaceMappingSemantics(
        $db,
        $versionId,
        $mappingId,
        $entityId,
        $mergedAttributes,
        $facetMap,
        $entitiesBySlug,
        $identityBasis === 'provider_local_conflict'
            ? 'provider_local_conflict'
            : 'provider_title_and_local_attributes'
    );
    return [
        'updated' => true,
        'status' => $status,
        'identity_basis' => $identityBasis,
        'base_conflict' => $baseConflict,
        'hard_conflicts' => $hardConflicts,
    ];
}

function ingredientOntologyV3BuildProviderTerms(
    PDO $db,
    int $versionId
): array {
    $version = ingredientOntologyV3Version($db, $versionId);
    if ($version === null || $version['status'] !== 'building') {
        throw new InvalidArgumentException(
            'provider terms may only be built inside a building ontology version'
        );
    }
    $db->prepare("
        DELETE FROM ingredient_ontology_provider_observations
        WHERE ontology_version_id = ?
    ")->execute([$versionId]);
    $db->prepare("
        DELETE FROM ingredient_ontology_provider_terms
        WHERE ontology_version_id = ?
    ")->execute([$versionId]);
    $db->prepare("
        UPDATE ingredient_ontology_mappings SET
            provider_term_id = NULL,
            identity_basis = CASE
                WHEN owner_type = 'recipe_source_ingredient'
                THEN 'unknown_legacy_adapter'
                ELSE 'local_label'
            END
        WHERE ontology_version_id = ?
    ")->execute([$versionId]);

    $labelIndex = ingredientOntologyV3LabelIndex($db, $versionId);
    $facetMap = ingredientOntologyV3FacetMap($db, $versionId);
    $entitiesBySlug =
        ingredientOntologyV3EntityMap($db, $versionId)['by_slug'];
    $rows = ingredientOntologyV3ProviderSourceRows($db, $versionId);
    $group = null;
    $termCount = 0;
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $providerRef = trim((string)($row['source_ingredient_ref'] ?? ''));
        if ($providerRef === '') {
            continue;
        }
        $key = implode("\n", [
            (string)$row['connector'],
            (string)$row['metadata_schema_version'],
            ingredientOntologyV3ProviderNamespace($providerRef),
            $providerRef,
        ]);
        if ($group !== null && $group['key'] !== $key) {
            ingredientOntologyV3ProviderGroupFinalize(
                $db,
                $versionId,
                $labelIndex,
                $group
            );
            $termCount++;
            $group = null;
        }
        if ($group === null) {
            $group = [
                'key' => $key,
                'connector' => (string)$row['connector'],
                'metadata_schema_version' =>
                    (string)$row['metadata_schema_version'],
                'namespace' =>
                    ingredientOntologyV3ProviderNamespace($providerRef),
                'provider_ref' => $providerRef,
                'observed_row_count' => 0,
                'first_seen_at' => null,
                'last_seen_at' => null,
                'titles' => [],
            ];
        }
        $group['observed_row_count']++;
        $createdAt = $row['created_at'] !== null
            ? (string)$row['created_at']
            : null;
        $updatedAt = $row['updated_at'] !== null
            ? (string)$row['updated_at']
            : $createdAt;
        if (
            $createdAt !== null
            && (
                $group['first_seen_at'] === null
                || $createdAt < $group['first_seen_at']
            )
        ) {
            $group['first_seen_at'] = $createdAt;
        }
        if (
            $updatedAt !== null
            && (
                $group['last_seen_at'] === null
                || $updatedAt > $group['last_seen_at']
            )
        ) {
            $group['last_seen_at'] = $updatedAt;
        }
        $title = trim((string)($row['source_default_title'] ?? ''));
        if ($title !== '') {
            $title = mb_substr($title, 0, 200, 'UTF-8');
            $normalization =
                ingredientOntologyV3NormalizeProviderLabel($title);
            $normalized = $normalization['normalized'];
            if (!isset($group['titles'][$normalized])) {
                $group['titles'][$normalized] = [
                    'raw' => $title,
                    'normalized' => $normalized,
                    'hash' => hash('sha256', $title),
                    'count' => 0,
                    'identity_safe' => $normalization['safe'],
                    'normalization_hash' =>
                        $normalization['full_hash'],
                    'normalization_length' =>
                        $normalization['full_length'],
                ];
            }
            $group['titles'][$normalized]['count']++;
        }
    }
    if ($group !== null) {
        ingredientOntologyV3ProviderGroupFinalize(
            $db,
            $versionId,
            $labelIndex,
            $group
        );
        $termCount++;
    }

    $lookupTerm = $db->prepare("
        SELECT *,
               CASE
                   WHEN mapping_status = 'accepted' THEN confidence_value
                   ELSE 0
               END AS mapping_confidence
        FROM (
            SELECT t.*,
                   CASE
                       WHEN t.provenance = 'provider_exact_accepted_alias'
                       THEN 1.0 ELSE 0.0
                   END AS confidence_value
            FROM ingredient_ontology_provider_terms t
            WHERE ontology_version_id = ?
              AND connector = ?
              AND metadata_schema_version = ?
              AND namespace = ?
              AND provider_ref = ?
        )
        LIMIT 1
    ");
    $insertObservation = $db->prepare("
        INSERT INTO ingredient_ontology_provider_observations (
            ontology_version_id, provider_term_id, mapping_id,
            owner_type, owner_id, owner_fingerprint, recipe_id,
            connector, metadata_schema_version, namespace, provider_ref,
            default_title, normalized_default_title, title_hash,
            local_label, normalized_local_label, local_label_hash,
            consistency_state, ref_provenance, group_index,
            group_position, source_position, observed_first_at,
            observed_last_at, evidence_json
        )
        VALUES (?, ?, ?, 'recipe_source_ingredient', ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $rows = ingredientOntologyV3ProviderSourceRows($db, $versionId);
    $observationCount = 0;
    $missingRefCount = 0;
    $statusCounts = [];
    $lastTermKey = null;
    $lastTerm = null;
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $providerRef = trim((string)($row['source_ingredient_ref'] ?? ''));
        $namespace = ingredientOntologyV3ProviderNamespace($providerRef);
        $term = null;
        if ($providerRef !== '') {
            $termKey = implode("\n", [
                (string)$row['connector'],
                (string)$row['metadata_schema_version'],
                $namespace,
                $providerRef,
            ]);
            if ($termKey !== $lastTermKey) {
                $lookupTerm->execute([
                    $versionId,
                    (string)$row['connector'],
                    (string)$row['metadata_schema_version'],
                    $namespace,
                    $providerRef,
                ]);
                $lastTerm = $lookupTerm->fetch(PDO::FETCH_ASSOC) ?: null;
                $lastTermKey = $termKey;
            }
            $term = $lastTerm;
        } else {
            $missingRefCount++;
        }
        $assertion = ingredientOntologyV3ApplyProviderRowAssertion(
            $db,
            $versionId,
            $row,
            $term,
            $facetMap,
            $entitiesBySlug
        );
        $status = (string)($assertion['status'] ?? 'mapping_missing');
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        $title = trim((string)($row['source_default_title'] ?? ''));
        $localLabel = trim((string)($row['name'] ?? ''));
        $titleNormalization = $title !== ''
            ? ingredientOntologyV3NormalizeProviderLabel($title)
            : null;
        $localNormalization =
            ingredientOntologyV3NormalizeProviderLabel($localLabel);
        $ownerFingerprint = (string)($row['owner_fingerprint'] ?? '');
        if ($ownerFingerprint === '') {
            $ownerFingerprint =
                ingredientOntologyV3RecipeOwnerFingerprint(
                    'recipe_source_ingredient',
                    $row
                );
        }
        $insertObservation->execute([
            $versionId,
            $term !== null ? (int)$term['id'] : null,
            $row['mapping_id'] !== null ? (int)$row['mapping_id'] : null,
            (int)$row['owner_id'],
            $ownerFingerprint,
            (int)$row['recipe_id'],
            (string)$row['connector'],
            (string)$row['metadata_schema_version'],
            $namespace,
            $providerRef !== '' ? $providerRef : null,
            $title !== '' ? mb_substr($title, 0, 200, 'UTF-8') : null,
            $title !== ''
                ? $titleNormalization['normalized']
                : null,
            $title !== '' ? hash('sha256', $title) : null,
            mb_substr($localLabel, 0, 200, 'UTF-8'),
            $localNormalization['normalized'],
            hash('sha256', $localLabel),
            $term['consistency_state'] ?? 'missing',
            $providerRef !== ''
                ? 'persisted_source_ingredient_ref'
                : 'unknown_legacy_adapter',
            $row['source_group_index'],
            $row['source_group_position'],
            (int)$row['position'],
            $row['created_at'],
            $row['updated_at'],
            ingredientOntologyV3Json([
                'source_group_title' =>
                    $row['source_group_title'] !== null
                        ? mb_substr(
                            (string)$row['source_group_title'],
                            0,
                            160,
                            'UTF-8'
                        )
                        : null,
                'source_unit_ref' => $row['source_unit_ref'],
                'source_shopping_category_ref' =>
                    $row['source_shopping_category_ref'],
                'source_optional' => $row['source_optional'] !== null
                    ? (int)$row['source_optional']
                    : null,
                'identity_basis' =>
                    $assertion['identity_basis'] ?? 'unknown_legacy_adapter',
                'positional_join_used' => false,
                'title_identity_safe' =>
                    $titleNormalization['safe'] ?? null,
                'local_identity_safe' =>
                    $localNormalization['safe'],
            ]),
        ]);
        $observationCount++;
    }
    $inverseStmt = $db->prepare("
        SELECT DISTINCT observation.mapping_id
        FROM ingredient_ontology_provider_observations observation
        JOIN (
            SELECT normalized_local_label
            FROM ingredient_ontology_provider_observations
            WHERE ontology_version_id = ?
              AND provider_ref IS NOT NULL
            GROUP BY normalized_local_label
            HAVING COUNT(DISTINCT provider_ref) > 1
        ) inverse
          ON inverse.normalized_local_label =
             observation.normalized_local_label
        WHERE observation.ontology_version_id = ?
          AND observation.mapping_id IS NOT NULL
    ");
    $inverseStmt->execute([$versionId, $versionId]);
    $inverseAmbiguousMappingIds = array_map(
        'intval',
        $inverseStmt->fetchAll(PDO::FETCH_COLUMN)
    );
    if ($inverseAmbiguousMappingIds) {
        $placeholders = implode(
            ',',
            array_fill(0, count($inverseAmbiguousMappingIds), '?')
        );
        $db->prepare("
            UPDATE ingredient_ontology_mappings
            SET entity_id = NULL,
                status = 'ambiguous',
                confidence = 0,
                mapping_source = 'provider_inverse_ambiguity',
                identity_basis = 'provider_local_conflict',
                evidence_json = json_set(
                    CASE
                        WHEN json_valid(evidence_json)
                        THEN evidence_json
                        ELSE '{}'
                    END,
                    '$.provider_term.inverse_local_ambiguity',
                    1
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE id IN ({$placeholders})
        ")->execute($inverseAmbiguousMappingIds);
        $db->prepare("
            DELETE FROM ingredient_ontology_mapping_attributes
            WHERE mapping_id IN ({$placeholders})
        ")->execute($inverseAmbiguousMappingIds);
        $db->prepare("
            DELETE FROM ingredient_ontology_mapping_relations
            WHERE mapping_id IN ({$placeholders})
        ")->execute($inverseAmbiguousMappingIds);
    }
    $statusCounts = [];
    $statusStmt = $db->prepare("
        SELECT status, COUNT(*)
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
          AND owner_type = 'recipe_source_ingredient'
        GROUP BY status
    ");
    $statusStmt->execute([$versionId]);
    while ($statusRow = $statusStmt->fetch(PDO::FETCH_NUM)) {
        $statusCounts[(string)$statusRow[0]] = (int)$statusRow[1];
    }
    $sourceCount = ingredientOntologyV3TableExists(
        $db,
        'recipe_source_ingredients'
    ) ? (int)$db->query("
        SELECT COUNT(*) FROM recipe_source_ingredients
    ")->fetchColumn() : 0;
    $curatedReviews = function_exists(
        'ingredientOntologyV3ApplyCuratedProviderReviews'
    ) ? ingredientOntologyV3ApplyCuratedProviderReviews(
        $db,
        $versionId
    ) : ['review_count' => 0];
    return [
        'complete' => $observationCount === $sourceCount,
        'term_count' => $termCount,
        'observation_count' => $observationCount,
        'source_row_count' => $sourceCount,
        'missing_ref_count' => $missingRefCount,
        'row_mapping_statuses' => $statusCounts,
        'auto_created_ontology_content' => false,
        'majority_vote_used' => false,
        'curated_reviews' => $curatedReviews,
    ];
}

function ingredientOntologyV3ProviderAudit(
    PDO $db,
    int $versionId
): array {
    $group = static function (
        PDO $db,
        string $sql,
        array $params
    ): array {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $out[(string)$row[0]] = (int)$row[1];
        }
        return $out;
    };
    $byConsistency = $group(
        $db,
        "SELECT consistency_state, COUNT(*)
         FROM ingredient_ontology_provider_terms
         WHERE ontology_version_id = ?
         GROUP BY consistency_state ORDER BY consistency_state",
        [$versionId]
    );
    $byStatus = $group(
        $db,
        "SELECT mapping_status || '/' || review_state, COUNT(*)
         FROM ingredient_ontology_provider_terms
         WHERE ontology_version_id = ?
         GROUP BY mapping_status, review_state
         ORDER BY mapping_status, review_state",
        [$versionId]
    );
    $terminalDispositions = $group(
        $db,
        "SELECT d.disposition_code, COUNT(*)
         FROM ingredient_ontology_provider_terms t
         JOIN ingredient_ontology_terminal_dispositions d
           ON d.id = t.terminal_disposition_id
         WHERE t.ontology_version_id = ?
         GROUP BY d.disposition_code
         ORDER BY d.disposition_code",
        [$versionId]
    );
    $inverseTitles = $db->prepare("
        SELECT normalized_default_title, COUNT(DISTINCT provider_ref) AS refs
        FROM ingredient_ontology_provider_observations
        WHERE ontology_version_id = ?
          AND normalized_default_title IS NOT NULL
          AND provider_ref IS NOT NULL
        GROUP BY normalized_default_title
        HAVING COUNT(DISTINCT provider_ref) > 1
        ORDER BY refs DESC, normalized_default_title
        LIMIT 100
    ");
    $inverseTitles->execute([$versionId]);
    $inverseTitleRows = $inverseTitles->fetchAll(PDO::FETCH_ASSOC);
    $inverseTitleCount = $db->prepare("
        SELECT COUNT(*) FROM (
            SELECT normalized_default_title
            FROM ingredient_ontology_provider_observations
            WHERE ontology_version_id = ?
              AND normalized_default_title IS NOT NULL
              AND provider_ref IS NOT NULL
            GROUP BY normalized_default_title
            HAVING COUNT(DISTINCT provider_ref) > 1
        )
    ");
    $inverseTitleCount->execute([$versionId]);
    $inverseLocal = $db->prepare("
        SELECT normalized_local_label, COUNT(DISTINCT provider_ref) AS refs
        FROM ingredient_ontology_provider_observations
        WHERE ontology_version_id = ?
          AND provider_ref IS NOT NULL
        GROUP BY normalized_local_label
        HAVING COUNT(DISTINCT provider_ref) > 1
        ORDER BY refs DESC, normalized_local_label
        LIMIT 100
    ");
    $inverseLocal->execute([$versionId]);
    $inverseLocalRows = $inverseLocal->fetchAll(PDO::FETCH_ASSOC);
    $inverseLocalCount = $db->prepare("
        SELECT COUNT(*) FROM (
            SELECT normalized_local_label
            FROM ingredient_ontology_provider_observations
            WHERE ontology_version_id = ?
              AND provider_ref IS NOT NULL
            GROUP BY normalized_local_label
            HAVING COUNT(DISTINCT provider_ref) > 1
        )
    ");
    $inverseLocalCount->execute([$versionId]);
    $variant = $db->prepare("
        SELECT provider_ref, default_title, distinct_title_count,
               consistency_state, mapping_status, evidence_json
        FROM ingredient_ontology_provider_terms
        WHERE ontology_version_id = ?
          AND consistency_state IN ('variant', 'conflicted')
        ORDER BY provider_ref
        LIMIT 100
    ");
    $variant->execute([$versionId]);
    $variantRows = [];
    while ($row = $variant->fetch(PDO::FETCH_ASSOC)) {
        $row['distinct_title_count'] = (int)$row['distinct_title_count'];
        $row['evidence'] = json_decode(
            (string)$row['evidence_json'],
            true
        ) ?: [];
        unset($row['evidence_json']);
        $variantRows[] = $row;
    }
    $counts = $db->prepare("
        SELECT
            (SELECT COUNT(*)
             FROM ingredient_ontology_provider_terms
             WHERE ontology_version_id = :version_id) AS terms,
            (SELECT COUNT(*)
             FROM ingredient_ontology_provider_observations
             WHERE ontology_version_id = :version_id) AS observations,
            (SELECT COUNT(*)
             FROM ingredient_ontology_provider_terms
             WHERE ontology_version_id = :version_id
               AND is_generic = 1) AS generic_terms,
            (SELECT COUNT(*)
             FROM ingredient_ontology_provider_observations
             WHERE ontology_version_id = :version_id
               AND provider_ref IS NULL) AS missing_refs,
            (SELECT COUNT(*)
             FROM ingredient_ontology_provider_observations
             WHERE ontology_version_id = :version_id
               AND default_title IS NULL) AS missing_titles,
            (SELECT COUNT(*)
             FROM ingredient_ontology_mappings
             WHERE ontology_version_id = :version_id
               AND owner_type = 'recipe_source_ingredient'
               AND identity_basis = 'provider_local_conflict')
                AS local_provider_conflicts,
            (SELECT COUNT(*)
             FROM ingredient_ontology_mappings
             WHERE ontology_version_id = :version_id
               AND owner_type = 'recipe_source_ingredient'
               AND identity_basis = 'provider_local_conflict'
               AND COALESCE(
                   json_extract(
                       evidence_json,
                       '$.provider_term.base_conflict'
                   ),
                   0
               ) = 1) AS local_provider_base_conflicts,
            (SELECT COUNT(*)
             FROM ingredient_ontology_mappings
             WHERE ontology_version_id = :version_id
               AND owner_type = 'recipe_source_ingredient'
               AND identity_basis = 'provider_local_conflict'
               AND COALESCE(
                   json_extract(
                       evidence_json,
                       '$.provider_term.hard_attribute_conflicts'
                   ),
                   '{}'
               ) <> '{}') AS local_provider_hard_conflicts
    ");
    $counts->execute([':version_id' => $versionId]);
    $counts = $counts->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($counts as $key => $value) {
        $counts[$key] = (int)$value;
    }
    return [
        'counts' => $counts,
        'by_consistency' => $byConsistency,
        'by_mapping_review_status' => $byStatus,
        'terminal_dispositions' => $terminalDispositions,
        'inverse_title_ambiguity' => [
            'count' => (int)$inverseTitleCount->fetchColumn(),
            'sample' => $inverseTitleRows,
        ],
        'inverse_local_label_ambiguity' => [
            'count' => (int)$inverseLocalCount->fetchColumn(),
            'sample' => $inverseLocalRows,
        ],
        'variant_conflict_terms' => $variantRows,
        'auto_accept_policy' => [
            'single_consistent_title_only' => true,
            'accepted_alias_or_entity_only' => true,
            'generic_titles_quarantined' => true,
            'variants_quarantined' => true,
            'majority_vote' => false,
            'provider_text_creates_ontology_content' => false,
            'provider_title_is_opaque' => true,
            'provider_ref_direct_identity' => false,
            'review_cluster_only' => true,
        ],
    ];
}

function ingredientOntologyV3SourceCorpusHash(
    PDO $db,
    int $ontologyVersionId
): string {
    $hash = hash_init('sha256');
    $recipes = $db->query("
        SELECT c.id, c.primary_connector,
               COALESCE(scope_origin.connector, '')
                   AS connector,
               COALESCE(scope_origin.metadata_version, '')
                   AS metadata_version,
               COALESCE(scope_origin.metadata_schema_version, '')
                   AS metadata_schema_version,
               COUNT(si.id) AS source_count,
               COUNT(DISTINCT si.position) AS distinct_positions,
               MIN(si.position) AS minimum_position,
               MAX(si.position) AS maximum_position,
               SUM(
                   CASE
                       WHEN si.id IS NOT NULL AND TRIM(si.name) = ''
                       THEN 1 ELSE 0
                   END
               ) AS empty_names
        FROM recipe_catalog c
        LEFT JOIN recipe_origins scope_origin
          ON scope_origin.id = (
              SELECT ro.id
              FROM recipe_origins ro
              WHERE ro.recipe_id = c.id
                AND ro.connector = c.primary_connector
              ORDER BY ro.id
              LIMIT 1
          )
        LEFT JOIN recipe_source_ingredients si ON si.recipe_id = c.id
        WHERE c.deleted_at IS NULL
        GROUP BY c.id
        ORDER BY c.id
    ");
    while ($recipe = $recipes->fetch(PDO::FETCH_ASSOC)) {
        hash_update($hash, ingredientOntologyV3Json([
            'record' => 'recipe_source_state',
            'recipe_id' => (int)$recipe['id'],
            'primary_connector' =>
                (string)$recipe['primary_connector'],
            'connector' => (string)$recipe['connector'],
            'metadata_version' =>
                (string)$recipe['metadata_version'],
            'metadata_schema_version' =>
                (string)$recipe['metadata_schema_version'],
            'source_count' => (int)$recipe['source_count'],
            'distinct_positions' =>
                (int)$recipe['distinct_positions'],
            'minimum_position' => $recipe['minimum_position'] !== null
                ? (int)$recipe['minimum_position']
                : null,
            'maximum_position' => $recipe['maximum_position'] !== null
                ? (int)$recipe['maximum_position']
                : null,
            'empty_names' => (int)$recipe['empty_names'],
        ]) . "\n");
    }
    $stmt = $db->query("
        SELECT si.*, c.language,
               COALESCE(
                   NULLIF(scope_origin.connector, ''),
                   NULLIF(c.primary_connector, ''),
                   'unknown_legacy_adapter'
               ) AS connector,
               COALESCE(
                   NULLIF(scope_origin.metadata_version, ''),
                   'unknown_legacy_adapter'
               ) AS metadata_version,
               COALESCE(
                   NULLIF(scope_origin.metadata_schema_version, ''),
                   'unknown_legacy_adapter'
               ) AS metadata_schema_version
        FROM recipe_source_ingredients si
        JOIN recipe_catalog c ON c.id = si.recipe_id
        LEFT JOIN recipe_origins scope_origin
          ON scope_origin.id = (
              SELECT ro.id
              FROM recipe_origins ro
              WHERE ro.recipe_id = si.recipe_id
                AND ro.connector = c.primary_connector
              ORDER BY ro.id
              LIMIT 1
          )
        WHERE c.deleted_at IS NULL
        ORDER BY si.id
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        hash_update($hash, ingredientOntologyV3Json([
            'record' => 'source_row',
            'owner_id' => (int)$row['id'],
            'fingerprint' => ingredientOntologyV3RecipeOwnerFingerprint(
                'recipe_source_ingredient',
                $row
            ),
            'group_index' => $row['source_group_index'],
            'group_position' => $row['source_group_position'],
            'source_quantity' => $row['source_quantity'],
            'source_quantity_max' => $row['source_quantity_max'],
            'source_unit' => $row['source_unit'],
            'source_amount_text' => $row['source_amount_text'],
            'source_group_title' => $row['source_group_title'],
            'source_unit_ref' => $row['source_unit_ref'],
            'source_shopping_category_ref' =>
                $row['source_shopping_category_ref'],
        ]) . "\n");
    }
    $legacy = $db->prepare("
        SELECT ri.id, ri.recipe_id, ri.position, ri.raw_text,
               ri.normalized_name, ri.quantity, ri.quantity_text, ri.unit,
               ri.is_required, ri.is_optional, ri.is_staple,
               ri.source_is_required, ri.source_is_optional,
               ri.requiredness_source,
               m.id AS mapping_id, m.owner_fingerprint,
               m.entity_id, m.status, m.confidence, m.mapping_source,
               m.attributes_json, m.is_staple AS mapping_is_staple,
               m.provider_term_id, m.identity_basis
        FROM recipe_ingredients ri
        JOIN recipe_catalog c ON c.id = ri.recipe_id
        LEFT JOIN recipe_origins scope_origin
          ON scope_origin.id = (
              SELECT ro.id
              FROM recipe_origins ro
              WHERE ro.recipe_id = c.id
                AND ro.connector = c.primary_connector
              ORDER BY ro.id
              LIMIT 1
          )
        LEFT JOIN ingredient_ontology_mappings m
          ON m.ontology_version_id = ?
         AND m.owner_type = 'recipe_ingredient'
         AND m.owner_id = ri.id
        WHERE c.deleted_at IS NULL
          AND NOT (
              COALESCE(scope_origin.metadata_version, '') = ?
              AND COALESCE(scope_origin.metadata_schema_version, '') = ?
          )
        ORDER BY ri.id
    ");
    $legacy->execute([
        $ontologyVersionId,
        RECIPE_COOKIDOO_METADATA_VERSION,
        RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
    ]);
    while ($row = $legacy->fetch(PDO::FETCH_ASSOC)) {
        hash_update($hash, ingredientOntologyV3Json([
            'record' => 'legacy_requirement_row',
            'owner_id' => (int)$row['id'],
            'owner_fingerprint' =>
                (string)($row['owner_fingerprint'] ?? ''),
            'recipe_id' => (int)$row['recipe_id'],
            'position' => (int)$row['position'],
            'raw_text' => (string)$row['raw_text'],
            'normalized_name' => (string)$row['normalized_name'],
            'quantity' => $row['quantity'] !== null
                ? (float)$row['quantity']
                : null,
            'quantity_text' => $row['quantity_text'],
            'unit' => $row['unit'],
            'is_required' => (int)$row['is_required'],
            'is_optional' => (int)$row['is_optional'],
            'is_staple' => (int)$row['is_staple'],
            'source_is_required' => $row['source_is_required'] !== null
                ? (int)$row['source_is_required']
                : null,
            'source_is_optional' => $row['source_is_optional'] !== null
                ? (int)$row['source_is_optional']
                : null,
            'requiredness_source' =>
                (string)$row['requiredness_source'],
            'mapping' => [
                'id' => $row['mapping_id'] !== null
                    ? (int)$row['mapping_id']
                    : null,
                'entity_id' => $row['entity_id'] !== null
                    ? (int)$row['entity_id']
                    : null,
                'status' => $row['status'],
                'confidence' => $row['confidence'] !== null
                    ? (float)$row['confidence']
                    : null,
                'source' => $row['mapping_source'],
                'attributes_json' => $row['attributes_json'],
                'is_staple' => $row['mapping_is_staple'] !== null
                    ? (int)$row['mapping_is_staple']
                    : null,
                'provider_term_id' => $row['provider_term_id'] !== null
                    ? (int)$row['provider_term_id']
                    : null,
                'identity_basis' => $row['identity_basis'],
            ],
        ]) . "\n");
    }
    return hash_final($hash);
}

function ingredientOntologyV3MappingHash(
    PDO $db,
    int $versionId
): string {
    $hash = hash_init('sha256');
    $stmt = $db->prepare("
        SELECT owner_type, owner_id, owner_fingerprint, entity_id, status,
               confidence, mapping_source, attributes_json, is_staple,
               provider_term_id, identity_basis
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
        ORDER BY owner_type, owner_id
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        hash_update(
            $hash,
            ingredientOntologyV3Json(array_values($row)) . "\n"
        );
    }
    return hash_final($hash);
}

function ingredientOntologyV3RequirementRevision(
    PDO $db,
    int $revisionId
): ?array {
    if ($revisionId <= 0) {
        return null;
    }
    $stmt = $db->prepare("
        SELECT * FROM ingredient_ontology_requirement_revisions
        WHERE id = ?
    ");
    $stmt->execute([$revisionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    foreach ([
        'id', 'ontology_version_id', 'recipe_count', 'requirement_count',
        'member_count', 'source_recipe_count', 'legacy_recipe_count',
        'incomplete_recipe_count',
    ] as $field) {
        $row[$field] = (int)$row[$field];
    }
    $row['parent_revision_id'] = $row['parent_revision_id'] !== null
        ? (int)$row['parent_revision_id']
        : null;
    return $row;
}

function ingredientOntologyV3RequirementHardAttributes(
    array $attributes
): array {
    $hard = [];
    foreach ($attributes as $facet => $value) {
        if (
            is_string($facet)
            && is_string($value)
            && ingredientOntologyV3FacetIsDefining($facet)
        ) {
            $hard[$facet] = $value;
        }
    }
    ksort($hard, SORT_STRING);
    return $hard;
}

function ingredientOntologyV3RequirementRequiredness(
    array $members,
    string $basis
): string {
    if ($basis === 'legacy') {
        $member = $members[0];
        return !empty($member['legacy_required'])
            ? 'required'
            : 'optional';
    }
    $hasFalse = false;
    $allTrue = true;
    foreach ($members as $member) {
        if ($member['source_optional'] === 0) {
            $hasFalse = true;
        }
        if ($member['source_optional'] !== 1) {
            $allTrue = false;
        }
    }
    if ($hasFalse) {
        return 'required';
    }
    if ($allTrue) {
        return 'optional';
    }
    return 'uncertain';
}

function ingredientOntologyV3RequirementQuantityState(
    array $members
): string {
    $units = [];
    $withEvidence = 0;
    $withoutUnit = 0;
    foreach ($members as $member) {
        $unit = ingredientOntologyV3NormalizeLabel(
            (string)($member['source_unit'] ?? '')
        );
        $hasEvidence = ($member['source_quantity'] ?? null) !== null
            || ($member['source_quantity_max'] ?? null) !== null
            || trim((string)($member['source_amount_text'] ?? '')) !== ''
            || $unit !== '';
        if (!$hasEvidence) {
            continue;
        }
        $withEvidence++;
        if ($unit === '') {
            $withoutUnit++;
        } else {
            $units[$unit] = true;
        }
    }
    if ($withEvidence === 0) {
        return 'none';
    }
    if (count($units) > 1) {
        return 'mixed_units';
    }
    if ($withoutUnit > 0 && $units) {
        return 'mixed_known_unknown';
    }
    return 'single_unit';
}

function ingredientOntologyV3BoundedSnapshotText(
    mixed $value,
    int $maximum
): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $value = preg_replace(
        '/[\x00-\x1F\x7F]+/u',
        ' ',
        (string)$value
    ) ?? (string)$value;
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '') {
        return null;
    }
    return mb_substr($value, 0, $maximum, 'UTF-8');
}

function ingredientOntologyV3RequirementKey(
    array $member,
    string $basis
): string {
    if ($basis === 'legacy') {
        return ingredientOntologyV3Hash([
            'basis' => 'legacy',
            'owner_type' => $member['owner_type'],
            'owner_id' => $member['owner_id'],
        ]);
    }
    $status = (string)$member['mapping_status'];
    $entityId = $member['entity_id'];
    $providerRef = trim((string)($member['provider_ref'] ?? ''));
    if ($status !== 'accepted' || $entityId === null) {
        return ingredientOntologyV3Hash([
            'basis' => 'source_fail_closed',
            'owner_type' => $member['owner_type'],
            'owner_id' => $member['owner_id'],
            'status' => $status,
            'entity_id' => $entityId,
            'provider_ref' => $providerRef !== ''
                ? $providerRef
                : null,
        ]);
    }
    return ingredientOntologyV3Hash([
        'basis' => 'source_accepted',
        'entity_id' => (int)$entityId,
        'hard_attributes' => ingredientOntologyV3RequirementHardAttributes(
            $member['attributes']
        ),
        'is_staple' => !empty($member['is_staple']),
        'provider_identity' => $providerRef !== ''
            ? $providerRef
            : 'missing-ref-owner:' . (int)$member['owner_id'],
    ]);
}

function ingredientOntologyV3RequirementMemberFromRow(
    array $row,
    string $ownerType,
    bool $validateFingerprints = true
): array {
    $attributes = json_decode(
        (string)($row['attributes_json'] ?? '{}'),
        true
    );
    if (!is_array($attributes)) {
        $attributes = [];
    }
    $row['id'] = (int)$row['owner_id'];
    $currentFingerprint =
        ingredientOntologyV3RecipeOwnerFingerprint(
            $ownerType,
            $row
        );
    $mappingFingerprint = (string)(
        $row['owner_fingerprint'] ?? ''
    );
    if (
        $validateFingerprints
        && (
            $mappingFingerprint === ''
            || !hash_equals($mappingFingerprint, $currentFingerprint)
        )
    ) {
        throw new RuntimeException(
            'stale source mapping fingerprint for '
            . $ownerType . ':' . (int)$row['owner_id']
        );
    }
    if (
        $validateFingerprints
        && $ownerType === 'recipe_source_ingredient'
        && (
            !is_string($row['observation_owner_fingerprint'])
            || !hash_equals(
                $mappingFingerprint,
                (string)$row['observation_owner_fingerprint']
            )
        )
    ) {
        throw new RuntimeException(
            'stale provider observation fingerprint for source row '
            . (int)$row['owner_id']
        );
    }
    $legacyRequired = false;
    if ($ownerType === 'recipe_ingredient') {
        $sourceOptional = $row['source_is_optional'] !== null
            ? !empty($row['source_is_optional'])
            : !empty($row['is_optional']);
        $sourceRequired = $row['source_is_required'] !== null
            ? !empty($row['source_is_required'])
            : (
                !empty($row['is_required'])
                || !empty($row['legacy_staple'])
            );
        $legacyRequired = $sourceRequired
            && !$sourceOptional
            && empty($row['is_staple']);
    }
    return [
        'owner_type' => $ownerType,
        'owner_id' => (int)$row['owner_id'],
        'owner_fingerprint' => $mappingFingerprint,
        'input_fingerprint' => $currentFingerprint,
        'recipe_id' => (int)$row['recipe_id'],
        'source_position' => (int)$row['position'],
        'group_index' => $row['source_group_index'] !== null
            ? (int)$row['source_group_index']
            : null,
        'group_position' => $row['source_group_position'] !== null
            ? (int)$row['source_group_position']
            : null,
        'group_title' => $row['source_group_title'],
        'provider_ref' => ingredientOntologyV3BoundedSnapshotText(
            $row['source_ingredient_ref'],
            200
        ),
        'default_title' => ingredientOntologyV3BoundedSnapshotText(
            $row['source_default_title'],
            200
        ),
        'source_optional' => $row['source_optional'] !== null
            ? (int)$row['source_optional']
            : null,
        'source_quantity' => $row['source_quantity'] !== null
            ? (float)$row['source_quantity']
            : null,
        'source_quantity_max' => $row['source_quantity_max'] !== null
            ? (float)$row['source_quantity_max']
            : null,
        'source_unit' => ingredientOntologyV3BoundedSnapshotText(
            $row['source_unit'],
            80
        ),
        'source_amount_text' =>
            ingredientOntologyV3BoundedSnapshotText(
                $row['source_amount_text'],
                160
            ),
        'source_label' =>
            ingredientOntologyV3BoundedSnapshotText(
                $row['source_label'],
                200
            ) ?? 'Ingredient',
        'normalized_name' => (string)$row['normalized_name'],
        'mapping_id' => $row['mapping_id'] !== null
            ? (int)$row['mapping_id']
            : null,
        'entity_id' => $row['entity_id'] !== null
            ? (int)$row['entity_id']
            : null,
        'mapping_status' => (string)(
            $row['mapping_status'] ?? 'unresolved'
        ),
        'mapping_confidence' => (float)(
            $row['mapping_confidence'] ?? 0
        ),
        'mapping_source' => (string)(
            $row['mapping_source'] ?? 'unresolved'
        ),
        'attributes' => $attributes,
        'is_staple' => !empty($row['is_staple']),
        'provider_term_id' => $row['provider_term_id'] !== null
            ? (int)$row['provider_term_id']
            : null,
        'identity_basis' => (string)(
            $row['identity_basis'] ?? 'local_label'
        ),
        'provider_consistency_state' =>
            $row['provider_consistency_state'],
        'ref_provenance' => (string)$row['ref_provenance'],
        'legacy_required' => $legacyRequired,
        'requiredness_source' => $ownerType === 'recipe_ingredient'
            ? (string)($row['requiredness_source'] ?? 'legacy_backfill')
            : 'provider_source_optional',
    ];
}

function ingredientOntologyV3RequirementBatchRows(
    PDO $db,
    int $versionId,
    array $recipeIds,
    string $ownerType,
    ?int $snapshotRevisionId = null
): array {
    if (!$recipeIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($recipeIds), '?'));
    if ($snapshotRevisionId !== null) {
        $stmt = $db->prepare("
            SELECT payload_json
            FROM ingredient_ontology_requirement_input_rows
            WHERE requirement_revision_id = ?
              AND owner_type = ?
              AND recipe_id IN ({$placeholders})
            ORDER BY recipe_id, owner_id
        ");
        $stmt->execute(array_merge(
            [$snapshotRevisionId, $ownerType],
            $recipeIds
        ));
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $member = json_decode(
                (string)$row['payload_json'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $result[(int)$member['recipe_id']][] = $member;
        }
        return $result;
    }
    if ($ownerType === 'recipe_source_ingredient') {
        $sql = "
            SELECT si.id AS owner_id, si.recipe_id, si.position,
                   si.name AS source_label, si.normalized_name,
                   si.source_group_index, si.source_group_position,
                   si.source_group_title, si.source_ingredient_ref,
                   si.source_default_title, si.source_optional,
                   si.source_quantity, si.source_quantity_max,
                   si.source_unit, si.source_amount_text,
                   si.canonical_ingredient_id
                       AS source_canonical_ingredient_id,
                   si.taxonomy_node_id AS source_taxonomy_node_id,
                   si.mapping_confidence AS source_mapping_confidence,
                   si.mapping_source AS source_mapping_source,
                   m.id AS mapping_id, m.owner_fingerprint,
                   m.entity_id, m.status AS mapping_status,
                   m.confidence AS mapping_confidence,
                   m.mapping_source, m.attributes_json, m.is_staple,
                   m.provider_term_id, m.identity_basis,
                   o.consistency_state AS provider_consistency_state,
                   o.ref_provenance,
                   o.owner_fingerprint AS observation_owner_fingerprint,
                   c.language,
                   COALESCE(
                       NULLIF(scope_origin.connector, ''),
                       NULLIF(c.primary_connector, ''),
                       'unknown_legacy_adapter'
                   ) AS connector,
                   COALESCE(
                       scope_origin.metadata_version,
                       ''
                   ) AS metadata_version,
                   COALESCE(
                       scope_origin.metadata_schema_version,
                       ''
                   ) AS metadata_schema_version,
                   COALESCE(scope_origin.external_id, '')
                       AS origin_external_id,
                   COALESCE(scope_origin.locale, '') AS origin_locale
            FROM recipe_source_ingredients si
            JOIN recipe_catalog c ON c.id = si.recipe_id
            LEFT JOIN recipe_origins scope_origin
              ON scope_origin.id = (
                  SELECT ro.id
                  FROM recipe_origins ro
                  WHERE ro.recipe_id = si.recipe_id
                    AND ro.connector = c.primary_connector
                  ORDER BY ro.id
                  LIMIT 1
              )
            LEFT JOIN ingredient_ontology_mappings m
              ON m.ontology_version_id = ?
             AND m.owner_type = 'recipe_source_ingredient'
             AND m.owner_id = si.id
            LEFT JOIN ingredient_ontology_provider_observations o
              ON o.ontology_version_id = ?
             AND o.owner_type = 'recipe_source_ingredient'
             AND o.owner_id = si.id
            WHERE si.recipe_id IN ({$placeholders})
            ORDER BY si.recipe_id, si.id
        ";
        $params = array_merge([$versionId, $versionId], $recipeIds);
    } else {
        $sql = "
            SELECT ri.id AS owner_id, ri.recipe_id, ri.position,
                   COALESCE(NULLIF(ri.raw_text, ''), ri.normalized_name)
                       AS source_label,
                   ri.normalized_name, NULL AS source_group_index,
                   NULL AS source_group_position,
                   NULL AS source_group_title,
                   NULL AS source_ingredient_ref,
                   NULL AS source_default_title,
                   NULL AS source_optional,
                   ri.quantity AS source_quantity,
                   NULL AS source_quantity_max,
                   ri.unit AS source_unit,
                   ri.quantity_text AS source_amount_text,
                   ri.is_required, ri.is_optional, ri.is_staple AS legacy_staple,
                   ri.source_is_required, ri.source_is_optional,
                   ri.requiredness_source,
                   ri.canonical_ingredient_id
                       AS source_canonical_ingredient_id,
                   ri.taxonomy_node_id AS source_taxonomy_node_id,
                   ri.mapping_confidence AS source_mapping_confidence,
                   ri.mapping_source AS source_mapping_source,
                   m.id AS mapping_id, m.owner_fingerprint,
                   m.entity_id, m.status AS mapping_status,
                   m.confidence AS mapping_confidence,
                   m.mapping_source, m.attributes_json, m.is_staple,
                   m.provider_term_id, m.identity_basis,
                   NULL AS provider_consistency_state,
                   'unknown_legacy_adapter' AS ref_provenance,
                   NULL AS observation_owner_fingerprint,
                   c.language,
                   COALESCE(
                       NULLIF(scope_origin.connector, ''),
                       NULLIF(c.primary_connector, ''),
                       'unknown_legacy_adapter'
                   ) AS connector,
                   '' AS metadata_version,
                   '' AS metadata_schema_version,
                   COALESCE(scope_origin.external_id, '')
                       AS origin_external_id,
                   COALESCE(scope_origin.locale, '') AS origin_locale
            FROM recipe_ingredients ri
            JOIN recipe_catalog c ON c.id = ri.recipe_id
            LEFT JOIN recipe_origins scope_origin
              ON scope_origin.id = (
                  SELECT ro.id
                  FROM recipe_origins ro
                  WHERE ro.recipe_id = ri.recipe_id
                    AND ro.connector = c.primary_connector
                  ORDER BY ro.id
                  LIMIT 1
              )
            LEFT JOIN ingredient_ontology_mappings m
              ON m.ontology_version_id = ?
             AND m.owner_type = 'recipe_ingredient'
             AND m.owner_id = ri.id
            WHERE ri.recipe_id IN ({$placeholders})
            ORDER BY ri.recipe_id, ri.id
        ";
        $params = array_merge([$versionId], $recipeIds);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $member = ingredientOntologyV3RequirementMemberFromRow(
            $row,
            $ownerType
        );
        $result[(int)$member['recipe_id']][] = $member;
    }
    return $result;
}

function ingredientOntologyV3RequirementRecipeSummaryBatch(
    PDO $db,
    int $lastId,
    int $batchSize
): array {
    $summary = $db->prepare("
        SELECT c.id, c.primary_connector,
               COALESCE(scope_origin.connector, '')
                   AS connector,
               COALESCE(scope_origin.metadata_version, '')
                   AS metadata_version,
               COALESCE(scope_origin.metadata_schema_version, '')
                   AS metadata_schema_version,
               COUNT(si.id) AS source_count,
               COUNT(DISTINCT si.position) AS distinct_positions,
               MIN(si.position) AS minimum_position,
               MAX(si.position) AS maximum_position,
               SUM(
                   CASE
                       WHEN si.id IS NOT NULL
                        AND TRIM(si.name) = ''
                       THEN 1 ELSE 0
                   END
               ) AS empty_names
        FROM recipe_catalog c
        LEFT JOIN recipe_origins scope_origin
          ON scope_origin.id = (
              SELECT ro.id
              FROM recipe_origins ro
              WHERE ro.recipe_id = c.id
                AND ro.connector = c.primary_connector
              ORDER BY ro.id
              LIMIT 1
          )
        LEFT JOIN recipe_source_ingredients si
          ON si.recipe_id = c.id
        WHERE c.deleted_at IS NULL
          AND c.id > ?
        GROUP BY c.id
        ORDER BY c.id
        LIMIT {$batchSize}
    ");
    $summary->execute([$lastId]);
    return $summary->fetchAll(PDO::FETCH_ASSOC);
}

function ingredientOntologyV3RequirementRecipeSnapshotPayload(
    array $recipe
): array {
    $hasCurrentSource =
        (string)$recipe['metadata_version']
            === RECIPE_COOKIDOO_METADATA_VERSION
        && (string)$recipe['metadata_schema_version']
            === RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION;
    return [
        'id' => (int)$recipe['id'],
        'primary_connector' => (string)$recipe['primary_connector'],
        'connector' => (string)$recipe['connector'],
        'metadata_version' => (string)$recipe['metadata_version'],
        'metadata_schema_version' =>
            (string)$recipe['metadata_schema_version'],
        'source_count' => (int)$recipe['source_count'],
        'distinct_positions' => (int)$recipe['distinct_positions'],
        'minimum_position' => $recipe['minimum_position'] !== null
            ? (int)$recipe['minimum_position']
            : null,
        'maximum_position' => $recipe['maximum_position'] !== null
            ? (int)$recipe['maximum_position']
            : null,
        'empty_names' => (int)$recipe['empty_names'],
        'snapshot_basis' => $hasCurrentSource ? 'source' : 'legacy',
    ];
}

function ingredientOntologyV3RequirementInputSnapshotHash(
    PDO $db,
    int $requirementRevisionId
): string {
    $hash = hash_init('sha256');
    $recipes = $db->prepare("
        SELECT recipe_id, payload_hash
        FROM ingredient_ontology_requirement_input_recipes
        WHERE requirement_revision_id = ?
        ORDER BY recipe_id
    ");
    $recipes->execute([$requirementRevisionId]);
    while ($row = $recipes->fetch(PDO::FETCH_ASSOC)) {
        hash_update($hash, ingredientOntologyV3Json([
            'record' => 'recipe',
            'recipe_id' => (int)$row['recipe_id'],
            'payload_hash' => (string)$row['payload_hash'],
        ]) . "\n");
    }
    foreach (
        ['recipe_source_ingredient', 'recipe_ingredient']
        as $ownerType
    ) {
        $rows = $db->prepare("
            SELECT owner_id, payload_hash
            FROM ingredient_ontology_requirement_input_rows
            WHERE requirement_revision_id = ?
              AND owner_type = ?
            ORDER BY owner_id
        ");
        $rows->execute([$requirementRevisionId, $ownerType]);
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            hash_update($hash, ingredientOntologyV3Json([
                'record' => $ownerType,
                'owner_id' => (int)$row['owner_id'],
                'payload_hash' => (string)$row['payload_hash'],
            ]) . "\n");
        }
    }
    return hash_final($hash);
}

function ingredientOntologyV3MaterializeRequirementInputSnapshot(
    PDO $db,
    int $versionId,
    int $requirementRevisionId,
    int $batchSize
): array {
    $db->prepare("
        DELETE FROM ingredient_ontology_requirement_input_rows
        WHERE requirement_revision_id = ?
    ")->execute([$requirementRevisionId]);
    $db->prepare("
        DELETE FROM ingredient_ontology_requirement_input_recipes
        WHERE requirement_revision_id = ?
    ")->execute([$requirementRevisionId]);
    $insertRecipe = $db->prepare("
        INSERT INTO ingredient_ontology_requirement_input_recipes (
            requirement_revision_id, ontology_version_id, recipe_id,
            basis, payload_json, payload_hash
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insertRow = $db->prepare("
        INSERT INTO ingredient_ontology_requirement_input_rows (
            requirement_revision_id, ontology_version_id, owner_type,
            owner_id, recipe_id, source_position, payload_json,
            payload_hash
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $recipeCount = 0;
    $memberCount = 0;
    $lastId = 0;
    while (true) {
        $recipes = ingredientOntologyV3RequirementRecipeSummaryBatch(
            $db,
            $lastId,
            $batchSize
        );
        if (!$recipes) {
            break;
        }
        $recipeIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            $recipes
        );
        $sourceRecipeIds = [];
        $legacyRecipeIds = [];
        $recipePayloads = [];
        foreach ($recipes as $recipe) {
            $payload =
                ingredientOntologyV3RequirementRecipeSnapshotPayload(
                    $recipe
                );
            $recipePayloads[(int)$payload['id']] = $payload;
            if ($payload['snapshot_basis'] === 'source') {
                $sourceRecipeIds[] = (int)$payload['id'];
            } else {
                $legacyRecipeIds[] = (int)$payload['id'];
            }
        }
        $membersByRecipe = ingredientOntologyV3RequirementBatchRows(
            $db,
            $versionId,
            $sourceRecipeIds,
            'recipe_source_ingredient'
        );
        $legacyMembers = ingredientOntologyV3RequirementBatchRows(
            $db,
            $versionId,
            $legacyRecipeIds,
            'recipe_ingredient'
        );
        foreach ($legacyMembers as $recipeId => $members) {
            $membersByRecipe[$recipeId] = $members;
        }
        foreach ($recipePayloads as $recipeId => $payload) {
            $json = ingredientOntologyV3Json(
                ingredientOntologyV3StableValue($payload)
            );
            $insertRecipe->execute([
                $requirementRevisionId,
                $versionId,
                $recipeId,
                $payload['snapshot_basis'],
                $json,
                hash('sha256', $json),
            ]);
            $recipeCount++;
            foreach ($membersByRecipe[$recipeId] ?? [] as $member) {
                $memberJson = ingredientOntologyV3Json(
                    ingredientOntologyV3StableValue($member)
                );
                $insertRow->execute([
                    $requirementRevisionId,
                    $versionId,
                    $member['owner_type'],
                    $member['owner_id'],
                    $recipeId,
                    $member['source_position'],
                    $memberJson,
                    hash('sha256', $memberJson),
                ]);
                $memberCount++;
            }
        }
        $lastId = max($recipeIds);
    }
    return [
        'recipe_count' => $recipeCount,
        'member_count' => $memberCount,
        'hash' => ingredientOntologyV3RequirementInputSnapshotHash(
            $db,
            $requirementRevisionId
        ),
    ];
}

function ingredientOntologyV3InsertRequirementGroup(
    PDO $db,
    int $requirementRevisionId,
    int $versionId,
    int $recipeId,
    string $basis,
    string $requirementKey,
    array $members
): array {
    $representative = $members[0];
    $hardAttributes = ingredientOntologyV3RequirementHardAttributes(
        $representative['attributes']
    );
    $requiredness = ingredientOntologyV3RequirementRequiredness(
        $members,
        $basis === 'legacy' ? 'legacy' : 'source'
    );
    $providerRefs = [];
    foreach ($members as $member) {
        $ref = trim((string)($member['provider_ref'] ?? ''));
        if ($ref !== '') {
            $providerRefs[$ref] = true;
        }
    }
    $quantityState =
        ingredientOntologyV3RequirementQuantityState($members);
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_recipe_requirements (
            requirement_revision_id, ontology_version_id, recipe_id,
            requirement_key, basis, entity_id, mapping_status,
            mapping_source, confidence, identity_basis, attributes_json,
            defining_signature, requiredness, is_staple,
            contributor_count, provider_ref_count, quantity_audit_state,
            evidence_json
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $requirementRevisionId,
        $versionId,
        $recipeId,
        $requirementKey,
        $basis,
        $representative['entity_id'],
        $representative['mapping_status'],
        $representative['mapping_source'],
        $representative['mapping_confidence'],
        $representative['identity_basis'],
        ingredientOntologyV3Json($representative['attributes']),
        ingredientOntologyV3Hash($hardAttributes),
        $requiredness,
        !empty($representative['is_staple']) ? 1 : 0,
        count($members),
        count($providerRefs),
        $quantityState,
        ingredientOntologyV3Json([
            'provider_refs' => array_keys($providerRefs),
            'hard_attributes' => $hardAttributes,
            'quantities_are_display_only' => true,
            'positional_join_used' => false,
            'contributors_complete' => true,
        ]),
    ]);
    $requirementId = (int)$db->lastInsertId();
    $insertMember = $db->prepare("
        INSERT INTO ingredient_ontology_requirement_members (
            requirement_revision_id, requirement_id, ontology_version_id,
            owner_type, owner_id, owner_fingerprint, mapping_id,
            provider_term_id, source_position, group_index, group_position,
            provider_ref, default_title, title_hash, source_label,
            source_label_hash, source_optional, source_quantity,
            source_quantity_max, source_unit, source_amount_text,
            quantity_state, evidence_json
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?)
    ");
    foreach ($members as $member) {
        $title = trim((string)($member['default_title'] ?? ''));
        $sourceLabel = (string)$member['source_label'];
        $hasQuantity = $member['source_quantity'] !== null
            || $member['source_quantity_max'] !== null
            || trim((string)($member['source_unit'] ?? '')) !== ''
            || trim((string)($member['source_amount_text'] ?? '')) !== '';
        $insertMember->execute([
            $requirementRevisionId,
            $requirementId,
            $versionId,
            $member['owner_type'],
            $member['owner_id'],
            $member['owner_fingerprint'],
            $member['mapping_id'],
            $member['provider_term_id'],
            $member['source_position'],
            $member['group_index'],
            $member['group_position'],
            trim((string)($member['provider_ref'] ?? '')) !== ''
                ? (string)$member['provider_ref']
                : null,
            $title !== '' ? $title : null,
            $title !== '' ? hash('sha256', $title) : null,
            $sourceLabel,
            hash('sha256', $sourceLabel),
            $member['source_optional'],
            $member['source_quantity'],
            $member['source_quantity_max'],
            trim((string)($member['source_unit'] ?? '')) !== ''
                ? (string)$member['source_unit']
                : null,
            trim((string)($member['source_amount_text'] ?? '')) !== ''
                ? (string)$member['source_amount_text']
                : null,
            $hasQuantity ? 'display_only' : 'none',
            ingredientOntologyV3Json([
                'group_title' => $member['group_title'],
                'normalized_name' => $member['normalized_name'],
                'provider_consistency_state' =>
                    $member['provider_consistency_state'],
                'ref_provenance' => $member['ref_provenance'],
                'requiredness_source' =>
                    $member['requiredness_source'],
                'mapping_snapshot' => [
                    'entity_id' => $member['entity_id'],
                    'status' => $member['mapping_status'],
                    'source' => $member['mapping_source'],
                    'confidence' => $member['mapping_confidence'],
                    'attributes' => $member['attributes'],
                    'identity_basis' => $member['identity_basis'],
                    'is_staple' => $member['is_staple'],
                ],
                'quantities_are_display_only' => true,
                'positional_join_used' => false,
            ]),
        ]);
    }
    return [
        'requirement_id' => $requirementId,
        'requiredness' => $requiredness,
        'quantity_audit_state' => $quantityState,
        'member_count' => count($members),
    ];
}

function ingredientOntologyV3BuildRequirementProjection(
    PDO $db,
    int $versionId,
    int $batchSize = 250,
    ?callable $progress = null
): array {
    if (function_exists(
        'ingredientOntologyControllerAssertCopiedGenerationDatabase'
    )) {
        ingredientOntologyControllerAssertCopiedGenerationDatabase($db);
    }
    ingredientOntologyV3SchemaMigrate($db);
    $version = ingredientOntologyV3Version($db, $versionId);
    if ($version === null || $version['status'] !== 'ready') {
        throw new InvalidArgumentException(
            'requirement projection requires a ready ontology version'
        );
    }
    $lock = ingredientOntologyV3AcquireLock($db);
    if ($lock === false) {
        return ['built' => false, 'reason' => 'locked'];
    }
    try {
    if (!hash_equals(
        (string)$version['content_hash'],
        ingredientOntologyV3ContentHash($db, $versionId)
    )) {
        throw new RuntimeException(
            'ontology content changed before requirement projection'
        );
    }
    ingredientOntologyV3PruneRequirementRevisions(
        $db,
        $versionId,
        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_READY_RETENTION,
        true
    );
    $batchSize = max(1, min(500, $batchSize));
    $sourceCorpusHash = '';
    $mappingHash = '';
    $inputSnapshotHash = '';
    $snapshotRecipeCount = 0;
    $snapshotMemberCount = 0;
    $revisionId = 0;
    $preflightError = null;
    $db->exec('BEGIN IMMEDIATE');
    try {
        $sourceCorpusHash = ingredientOntologyV3SourceCorpusHash(
            $db,
            $versionId
        );
        $mappingHash = ingredientOntologyV3MappingHash($db, $versionId);
        $ownerFingerprintAudit =
            ingredientOntologyV3OwnerFingerprintAudit($db, $versionId);
        $currentVersion = ingredientOntologyV3Version($db, $versionId);
        if (
            $currentVersion === null
            || !hash_equals(
                (string)$version['content_hash'],
                (string)$currentVersion['content_hash']
            )
            || !hash_equals(
                (string)$version['content_hash'],
                ingredientOntologyV3ContentHash($db, $versionId)
            )
        ) {
            throw new RuntimeException(
                'ontology content changed before requirement snapshot'
            );
        }
        if (!$ownerFingerprintAudit['valid']) {
            $preflightError =
                'source owner fingerprints are stale before requirement projection';
            $failed = $db->prepare("
                INSERT INTO ingredient_ontology_requirement_revisions (
                    ontology_version_id, projection_model, status,
                    source_corpus_hash, ontology_content_hash,
                    mapping_hash, validation_report_json, last_error,
                    completed_at
                )
                VALUES (?, ?, 'failed', ?, ?, ?, ?, ?,
                        CURRENT_TIMESTAMP)
            ");
            $failed->execute([
                $versionId,
                INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
                $sourceCorpusHash,
                (string)$version['content_hash'],
                $mappingHash,
                ingredientOntologyV3Json([
                    'source_owner_fingerprints' =>
                        $ownerFingerprintAudit,
                ]),
                $preflightError,
            ]);
        } else {
            $existing = $db->prepare("
                SELECT id
                FROM ingredient_ontology_requirement_revisions
                WHERE ontology_version_id = ?
                  AND projection_model = ?
                  AND source_corpus_hash = ?
                  AND ontology_content_hash = ?
                  AND mapping_hash = ?
                  AND status = 'ready'
                ORDER BY completed_at DESC, id DESC
                LIMIT 1
            ");
            $existing->execute([
                $versionId,
                INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
                $sourceCorpusHash,
                (string)$version['content_hash'],
                $mappingHash,
            ]);
            $existingId = (int)($existing->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $audit = ingredientOntologyV3RequirementAudit(
                    $db,
                    $existingId
                );
                if (
                    $audit['source_corpus_hash_current']
                    && $audit['mapping_hash_current']
                    && !empty(
                        $audit['materialized_values']['valid']
                    )
                    && $audit['member_owner_duplicates'] === 0
                    && $audit['revision']['recipe_count']
                        === (int)$db->query("
                            SELECT COUNT(*) FROM recipe_catalog
                            WHERE deleted_at IS NULL
                        ")->fetchColumn()
                ) {
                    $db->exec('COMMIT');
                    return [
                        'built' => false,
                        'reused' => true,
                        'requirement_revision_id' => $existingId,
                        'ontology_version_id' => $versionId,
                    ] + (
                        json_decode(
                            (string)$audit['revision'][
                                'validation_report_json'
                            ],
                            true
                        ) ?: []
                    );
                }
            }
            $parentStmt = $db->prepare("
                SELECT id
                FROM ingredient_ontology_requirement_revisions
                WHERE ontology_version_id = ? AND status = 'ready'
                ORDER BY completed_at DESC, id DESC
                LIMIT 1
            ");
            $parentStmt->execute([$versionId]);
            $parentId = (int)($parentStmt->fetchColumn() ?: 0);
            $insertRevision = $db->prepare("
                INSERT INTO ingredient_ontology_requirement_revisions (
                    ontology_version_id, parent_revision_id,
                    projection_model, status, source_corpus_hash,
                    ontology_content_hash, mapping_hash
                )
                VALUES (?, ?, ?, 'building', ?, ?, ?)
            ");
            $insertRevision->execute([
                $versionId,
                $parentId > 0 ? $parentId : null,
                INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
                $sourceCorpusHash,
                (string)$version['content_hash'],
                $mappingHash,
            ]);
            $revisionId = (int)$db->lastInsertId();
            $snapshot =
                ingredientOntologyV3MaterializeRequirementInputSnapshot(
                    $db,
                    $versionId,
                    $revisionId,
                    $batchSize
                );
            $inputSnapshotHash = (string)$snapshot['hash'];
            $snapshotRecipeCount = (int)$snapshot['recipe_count'];
            $snapshotMemberCount = (int)$snapshot['member_count'];
            $db->prepare("
                UPDATE ingredient_ontology_requirement_revisions
                SET input_snapshot_hash = ?
                WHERE id = ? AND status = 'building'
            ")->execute([$inputSnapshotHash, $revisionId]);
        }
        $db->exec('COMMIT');
    } catch (Throwable $snapshotError) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $snapshotError;
    }
    if ($preflightError !== null) {
        throw new RuntimeException($preflightError);
    }
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && is_callable(
            $GLOBALS[
                'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SOURCE_AUDIT'
            ] ?? null
        )
    ) {
        ($GLOBALS[
            'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SOURCE_AUDIT'
        ])($db, $versionId, $revisionId);
    }
    $recipeCount = 0;
    $requirementCount = 0;
    $memberCount = 0;
    $sourceRecipeCount = 0;
    $legacyRecipeCount = 0;
    $incompleteRecipeCount = 0;
    $sourceMemberCount = 0;
    $sourceRequirementCount = 0;
    $lastId = 0;
    $insertRecipeState = $db->prepare("
        INSERT INTO ingredient_ontology_requirement_recipe_states (
            requirement_revision_id, ontology_version_id, recipe_id,
            basis, complete, source_row_count, projected_member_count,
            projected_requirement_count, recipe_fingerprint, evidence_json
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    try {
        while (true) {
            $summary = $db->prepare("
                SELECT payload_json
                FROM ingredient_ontology_requirement_input_recipes
                WHERE requirement_revision_id = ?
                  AND recipe_id > ?
                ORDER BY recipe_id
                LIMIT {$batchSize}
            ");
            $summary->execute([$revisionId, $lastId]);
            $recipes = [];
            while ($snapshotRow = $summary->fetch(PDO::FETCH_ASSOC)) {
                $recipes[] = json_decode(
                    (string)$snapshotRow['payload_json'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            }
            if (!$recipes) {
                break;
            }
            $recipeIds = array_map(
                static fn(array $row): int => (int)$row['id'],
                $recipes
            );
            $sourceRecipeIds = [];
            $legacyRecipeIds = [];
            foreach ($recipes as $recipe) {
                $hasCurrentSource =
                    (string)$recipe['snapshot_basis'] === 'source';
                if ($hasCurrentSource) {
                    $sourceRecipeIds[] = (int)$recipe['id'];
                } else {
                    $legacyRecipeIds[] = (int)$recipe['id'];
                }
            }
            $sourceRows = ingredientOntologyV3RequirementBatchRows(
                $db,
                $versionId,
                $sourceRecipeIds,
                'recipe_source_ingredient',
                $revisionId
            );
            $legacyRows = ingredientOntologyV3RequirementBatchRows(
                $db,
                $versionId,
                $legacyRecipeIds,
                'recipe_ingredient',
                $revisionId
            );
            $db->beginTransaction();
            try {
                foreach ($recipes as $recipe) {
                    $recipeId = (int)$recipe['id'];
                    $hasCurrentSource =
                        (string)$recipe['snapshot_basis'] === 'source';
                    if ($hasCurrentSource) {
                        $members = $sourceRows[$recipeId] ?? [];
                        $sourceCount = (int)$recipe['source_count'];
                        $complete = $sourceCount > 0
                            && (int)$recipe['distinct_positions']
                                === $sourceCount
                            && (int)$recipe['minimum_position'] === 0
                            && (int)$recipe['maximum_position']
                                === $sourceCount - 1
                            && (int)$recipe['empty_names'] === 0
                            && count($members) === $sourceCount;
                        $basis = $complete ? 'source' : 'source_incomplete';
                        $sourceRecipeCount++;
                        if (!$complete) {
                            $incompleteRecipeCount++;
                        }
                    } else {
                        $members = $legacyRows[$recipeId] ?? [];
                        $basis = 'legacy';
                        $complete = true;
                        $legacyRecipeCount++;
                    }
                    $groups = [];
                    foreach ($members as $member) {
                        $key = ingredientOntologyV3RequirementKey(
                            $member,
                            $basis === 'legacy' ? 'legacy' : 'source'
                        );
                        $groups[$key][] = $member;
                    }
                    $recipeRequirementCount = 0;
                    $recipeMemberCount = 0;
                    foreach ($groups as $key => $contributors) {
                        ingredientOntologyV3InsertRequirementGroup(
                            $db,
                            $revisionId,
                            $versionId,
                            $recipeId,
                            $basis,
                            $key,
                            $contributors
                        );
                        $requirementCount++;
                        $memberCount += count($contributors);
                        $recipeRequirementCount++;
                        $recipeMemberCount += count($contributors);
                        if ($basis !== 'legacy') {
                            $sourceRequirementCount++;
                            $sourceMemberCount += count($contributors);
                        }
                    }
                    $insertRecipeState->execute([
                        $revisionId,
                        $versionId,
                        $recipeId,
                        $basis,
                        $complete ? 1 : 0,
                        (int)$recipe['source_count'],
                        $recipeMemberCount,
                        $recipeRequirementCount,
                        ingredientOntologyV3Hash([
                            'recipe_id' => $recipeId,
                            'primary_connector' =>
                                (string)$recipe['primary_connector'],
                            'metadata_version' =>
                                (string)$recipe['metadata_version'],
                            'metadata_schema_version' =>
                                (string)$recipe['metadata_schema_version'],
                            'source_count' =>
                                (int)$recipe['source_count'],
                            'basis' => $basis,
                            'complete' => $complete,
                        ]),
                        ingredientOntologyV3Json([
                            'minimum_position' =>
                                $recipe['minimum_position'],
                            'maximum_position' =>
                                $recipe['maximum_position'],
                            'distinct_positions' =>
                                (int)$recipe['distinct_positions'],
                            'empty_names' =>
                                (int)$recipe['empty_names'],
                            'source_and_ranking_joined' => false,
                        ]),
                    ]);
                    $recipeCount++;
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            $lastId = max($recipeIds);
            if ($progress !== null) {
                $progress($recipeCount);
            }
        }
        if (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && is_callable(
                $GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_BEFORE_REQUIREMENT_PUBLICATION_RESERVATION'
                ] ?? null
            )
        ) {
            ($GLOBALS[
                'INGREDIENT_ONTOLOGY_V3_BEFORE_REQUIREMENT_PUBLICATION_RESERVATION'
            ])($db, $versionId, $revisionId);
        }
        $db->exec('BEGIN IMMEDIATE');
        try {
        $currentSourceHash = ingredientOntologyV3SourceCorpusHash(
            $db,
            $versionId
        );
        $currentMappingHash = ingredientOntologyV3MappingHash(
            $db,
            $versionId
        );
        $currentVersion = ingredientOntologyV3Version($db, $versionId);
        $currentOwnerFingerprintAudit =
            ingredientOntologyV3OwnerFingerprintAudit($db, $versionId);
        $currentInputSnapshotHash =
            ingredientOntologyV3RequirementInputSnapshotHash(
                $db,
                $revisionId
            );
        $storedInputSnapshotHash = (string)$db->query("
            SELECT input_snapshot_hash
            FROM ingredient_ontology_requirement_revisions
            WHERE id = {$revisionId}
        ")->fetchColumn();
        $catalogCount = (int)$db->query("
            SELECT COUNT(*) FROM recipe_catalog WHERE deleted_at IS NULL
        ")->fetchColumn();
        $storedRequirements = (int)$db->query("
            SELECT COUNT(*) FROM ingredient_ontology_recipe_requirements
            WHERE requirement_revision_id = {$revisionId}
        ")->fetchColumn();
        $storedMembers = (int)$db->query("
            SELECT COUNT(*) FROM ingredient_ontology_requirement_members
            WHERE requirement_revision_id = {$revisionId}
        ")->fetchColumn();
        $storedRecipeStates = (int)$db->query("
            SELECT COUNT(*)
            FROM ingredient_ontology_requirement_recipe_states
            WHERE requirement_revision_id = {$revisionId}
        ")->fetchColumn();
        if (
            !hash_equals($sourceCorpusHash, $currentSourceHash)
            || !hash_equals($mappingHash, $currentMappingHash)
            || !hash_equals(
                $inputSnapshotHash,
                $currentInputSnapshotHash
            )
            || !hash_equals(
                $inputSnapshotHash,
                $storedInputSnapshotHash
            )
            || $currentVersion === null
            || !$currentOwnerFingerprintAudit['valid']
            || !hash_equals(
                (string)$version['content_hash'],
                (string)$currentVersion['content_hash']
            )
            || !hash_equals(
                (string)$version['content_hash'],
                ingredientOntologyV3ContentHash($db, $versionId)
            )
            || $catalogCount !== $recipeCount
            || $snapshotRecipeCount !== $recipeCount
            || $snapshotMemberCount !== $memberCount
            || $storedRequirements !== $requirementCount
            || $storedMembers !== $memberCount
            || $storedRecipeStates !== $recipeCount
        ) {
            throw new RuntimeException(
                'requirement projection inputs changed or output is incomplete'
            );
        }
        $materializationHashes =
            ingredientOntologyV3RequirementMaterializationHashes(
                $db,
                $revisionId
            );
        $revisionForMaterialization = ingredientOntologyV3RequirementRevision(
            $db,
            $revisionId
        );
        $materializationAudit =
            ingredientOntologyV3RequirementMaterializationAudit(
                $db,
                array_merge(
                    $revisionForMaterialization ?? ['id' => $revisionId],
                    $materializationHashes,
                    [
                        'recipe_count' => $recipeCount,
                        'requirement_count' => $requirementCount,
                        'member_count' => $memberCount,
                    ]
                )
            );
        if (!$materializationAudit['valid']) {
            throw new RuntimeException(
                'requirement projection materialization hashes are invalid'
            );
        }
        $report = [
            'projection_model' => INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
            'shadow_only' => true,
            'recipe_count' => $recipeCount,
            'requirement_count' => $requirementCount,
            'member_count' => $memberCount,
            'source_recipe_count' => $sourceRecipeCount,
            'legacy_recipe_count' => $legacyRecipeCount,
            'incomplete_recipe_count' => $incompleteRecipeCount,
            'source_member_count' => $sourceMemberCount,
            'source_requirement_count' => $sourceRequirementCount,
            'source_dedup_delta' =>
                $sourceMemberCount - $sourceRequirementCount,
            'source_rows_are_positionally_joined' => false,
            'quantities_affect_scoring' => false,
            'input_snapshot_hash' => $inputSnapshotHash,
            'input_snapshot_materialized' => true,
            'input_snapshot_recipe_count' => $snapshotRecipeCount,
            'input_snapshot_member_count' => $snapshotMemberCount,
            'deleted_recipes_excluded' => true,
            'source_owner_fingerprints' =>
                $currentOwnerFingerprintAudit,
            'materialized_values' => $materializationAudit,
        ];
        $db->prepare("
            DELETE FROM ingredient_ontology_requirement_input_rows
            WHERE requirement_revision_id = ?
        ")->execute([$revisionId]);
        $db->prepare("
            DELETE FROM ingredient_ontology_requirement_input_recipes
            WHERE requirement_revision_id = ?
        ")->execute([$revisionId]);
        $publish = $db->prepare("
            UPDATE ingredient_ontology_requirement_revisions SET
                status = 'ready',
                recipe_count = ?,
                requirement_count = ?,
                member_count = ?,
                source_recipe_count = ?,
                legacy_recipe_count = ?,
                incomplete_recipe_count = ?,
                requirement_rows_hash = ?,
                requirement_member_rows_hash = ?,
                requirement_recipe_state_rows_hash = ?,
                materialization_hash = ?,
                validation_report_json = ?,
                completed_at = CURRENT_TIMESTAMP,
                last_error = ''
            WHERE id = ? AND status = 'building'
        ");
        $publicationGuardWasEnabled =
            ingredientOntologyV3PublicationGuardEnabled($db);
        ingredientOntologyV3SetPublicationGuard($db, true);
        try {
            $publish->execute([
                $recipeCount,
                $requirementCount,
                $memberCount,
                $sourceRecipeCount,
                $legacyRecipeCount,
                $incompleteRecipeCount,
                $materializationHashes['requirement_rows_hash'],
                $materializationHashes['requirement_member_rows_hash'],
                $materializationHashes[
                    'requirement_recipe_state_rows_hash'
                ],
                $materializationHashes['materialization_hash'],
                ingredientOntologyV3Json($report),
                $revisionId,
            ]);
        } finally {
            ingredientOntologyV3SetPublicationGuard(
                $db,
                $publicationGuardWasEnabled
            );
        }
        if ($publish->rowCount() !== 1) {
            throw new RuntimeException(
                'requirement projection publication lost its building revision'
            );
        }
        $db->exec('COMMIT');
        } catch (Throwable $publicationError) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $publicationError;
        }
        $cleanupWarning = null;
        try {
            ingredientOntologyV3PruneRequirementRevisions(
                $db,
                $versionId,
                INGREDIENT_ONTOLOGY_V3_REQUIREMENT_READY_RETENTION,
                true
            );
        } catch (Throwable $cleanupError) {
            $cleanupWarning = mb_substr(
                $cleanupError->getMessage(),
                0,
                500,
                'UTF-8'
            );
        }
        return [
            'built' => true,
            'requirement_revision_id' => $revisionId,
            'ontology_version_id' => $versionId,
        ] + $report + (
            $cleanupWarning !== null
                ? ['cleanup_warning' => $cleanupWarning]
                : []
        );
    } catch (Throwable $e) {
        $db->prepare("
            UPDATE ingredient_ontology_requirement_revisions SET
                status = 'failed',
                last_error = ?,
                completed_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status = 'building'
        ")->execute([
            mb_substr($e->getMessage(), 0, 1000, 'UTF-8'),
            $revisionId,
        ]);
        if ($revisionId > 0) {
            $db->prepare("
                DELETE FROM ingredient_ontology_requirement_input_rows
                WHERE requirement_revision_id = ?
            ")->execute([$revisionId]);
            $db->prepare("
                DELETE FROM ingredient_ontology_requirement_input_recipes
                WHERE requirement_revision_id = ?
            ")->execute([$revisionId]);
            $db->prepare("
                DELETE FROM ingredient_ontology_requirement_recipe_states
                WHERE requirement_revision_id = ?
            ")->execute([$revisionId]);
            $db->prepare("
                DELETE FROM ingredient_ontology_recipe_requirements
                WHERE requirement_revision_id = ?
            ")->execute([$revisionId]);
        }
        throw $e;
    }
    } finally {
        ingredientOntologyV3ReleaseLock($lock);
    }
}

function ingredientOntologyV3RequirementAudit(
    PDO $db,
    int $revisionId
): array {
    $revision = ingredientOntologyV3RequirementRevision($db, $revisionId);
    if ($revision === null) {
        throw new InvalidArgumentException('requirement revision not found');
    }
    $group = static function (
        PDO $db,
        string $sql,
        array $params
    ): array {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $out[(string)$row[0]] = (int)$row[1];
        }
        return $out;
    };
    $basis = $group(
        $db,
        "SELECT basis, COUNT(*)
         FROM ingredient_ontology_requirement_recipe_states
         WHERE requirement_revision_id = ?
         GROUP BY basis ORDER BY basis",
        [$revisionId]
    );
    $requiredness = $group(
        $db,
        "SELECT requiredness, COUNT(*)
         FROM ingredient_ontology_recipe_requirements
         WHERE requirement_revision_id = ?
         GROUP BY requiredness ORDER BY requiredness",
        [$revisionId]
    );
    $quantity = $group(
        $db,
        "SELECT quantity_audit_state, COUNT(*)
         FROM ingredient_ontology_recipe_requirements
         WHERE requirement_revision_id = ?
         GROUP BY quantity_audit_state ORDER BY quantity_audit_state",
        [$revisionId]
    );
    $owner = $group(
        $db,
        "SELECT owner_type, COUNT(*)
         FROM ingredient_ontology_requirement_members
         WHERE requirement_revision_id = ?
         GROUP BY owner_type ORDER BY owner_type",
        [$revisionId]
    );
    $sourceRows = (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_source_ingredients si
        JOIN recipe_catalog c ON c.id = si.recipe_id
        WHERE c.deleted_at IS NULL
    ")->fetchColumn();
    $sourceMembers = (int)($owner['recipe_source_ingredient'] ?? 0);
    $sourceRequirementStmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_recipe_requirements
        WHERE requirement_revision_id = ?
          AND basis IN ('source', 'source_incomplete')
    ");
    $sourceRequirementStmt->execute([$revisionId]);
    $sourceRequirements = (int)$sourceRequirementStmt->fetchColumn();
    $memberUniqueness = $db->prepare("
        SELECT COUNT(*) FROM (
            SELECT owner_type, owner_id, COUNT(*) AS copies
            FROM ingredient_ontology_requirement_members
            WHERE requirement_revision_id = ?
            GROUP BY owner_type, owner_id
            HAVING COUNT(*) <> 1
        )
    ");
    $memberUniqueness->execute([$revisionId]);
    $memberDuplicateCount = (int)$memberUniqueness->fetchColumn();
    $currentSourceRowsStmt = $db->query("
        SELECT COUNT(*)
        FROM recipe_source_ingredients si
        JOIN recipe_catalog c ON c.id = si.recipe_id
        JOIN recipe_origins o
          ON o.id = (
              SELECT ro.id
              FROM recipe_origins ro
              WHERE ro.recipe_id = c.id
                AND ro.connector = c.primary_connector
              ORDER BY ro.id
              LIMIT 1
          )
        WHERE o.metadata_version = '"
            . RECIPE_COOKIDOO_METADATA_VERSION . "'
          AND o.metadata_schema_version = '"
            . RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION . "'
          AND c.deleted_at IS NULL
    ");
    $currentSourceRows = (int)$currentSourceRowsStmt->fetchColumn();
    $currentSourceHash = ingredientOntologyV3SourceCorpusHash(
        $db,
        $revision['ontology_version_id']
    );
    $currentMappingHash = ingredientOntologyV3MappingHash(
        $db,
        $revision['ontology_version_id']
    );
    $materializedValues =
        ingredientOntologyV3RequirementMaterializationAudit(
            $db,
            $revision
        );
    return [
        'revision' => $revision,
        'basis_recipe_counts' => $basis,
        'requiredness_counts' => $requiredness,
        'quantity_audit_states' => $quantity,
        'member_owner_counts' => $owner,
        'source_rows' => $sourceRows,
        'current_source_rows' => $currentSourceRows,
        'source_members' => $sourceMembers,
        'source_requirements' => $sourceRequirements,
        'source_dedup_delta' => $sourceMembers - $sourceRequirements,
        'requirements_not_greater_than_source_rows' =>
            $sourceRequirements <= $sourceMembers,
        'member_owner_duplicates' => $memberDuplicateCount,
        'contributor_complete' =>
            $currentSourceRows === $sourceMembers
            && $memberDuplicateCount === 0,
        'source_corpus_hash_current' => hash_equals(
            (string)$revision['source_corpus_hash'],
            $currentSourceHash
        ),
        'mapping_hash_current' => hash_equals(
            (string)$revision['mapping_hash'],
            $currentMappingHash
        ),
        'materialized_values' => $materializedValues,
        'positional_links' => 0,
        'quantities_affect_scoring' => false,
    ];
}
