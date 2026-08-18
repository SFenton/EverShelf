<?php
declare(strict_types=1);

function ingredientOntologyV3LockPath(PDO $db): string {
    return recipeScoreLockPath($db);
}

function ingredientOntologyV3AcquireLock(PDO $db): mixed {
    $handle = fopen(ingredientOntologyV3LockPath($db), 'c+');
    if ($handle === false) {
        throw new RuntimeException('ontology v3 lock could not be opened');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

function ingredientOntologyV3ReleaseLock(mixed $handle): void {
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function ingredientOntologyV3MappingFromRow(array $row): ?array {
    if (($row['mapping_id'] ?? null) === null) {
        return null;
    }
    $attributes = json_decode(
        (string)($row['attributes_json'] ?? '{}'),
        true
    );
    if (!is_array($attributes)) {
        $attributes = [];
    }
    $normalizedAttributes = [];
    foreach ($attributes as $facet => $value) {
        if (!is_string($facet) || !is_string($value)) {
            continue;
        }
        $normalizedAttributes[$facet] = [
            'value' => $value,
            'is_defining' => ingredientOntologyV3FacetIsDefining($facet),
            'source' => 'deterministic_parser',
        ];
    }
    return [
        'mapping_id' => (int)$row['mapping_id'],
        'owner_fingerprint' => (string)(
            $row['owner_fingerprint'] ?? ''
        ),
        'subject_id' => isset($row['subject_id'])
            && $row['subject_id'] !== null
                ? (int)$row['subject_id']
                : null,
        'entity_id' => $row['entity_id'] !== null
            ? (int)$row['entity_id']
            : null,
        'entity_slug' => $row['entity_slug'] ?? null,
        'entity_name' => $row['entity_name'] ?? null,
        'status' => (string)$row['mapping_status'],
        'confidence' => (float)$row['mapping_confidence_v3'],
        'mapping_source' => (string)$row['mapping_source_v3'],
        'source_label' => (string)($row['source_label'] ?? ''),
        'attributes' => $normalizedAttributes,
        'is_staple' => !empty($row['mapping_is_staple']),
    ];
}

function ingredientOntologyV3Inventory(
    PDO $db,
    int $versionId
): array {
    $inventory = recipeInventoryCandidates($db, ['exclude_expired' => true]);
    $productStmt = $db->prepare("
        SELECT id, name, brand, category, prepared_food
        FROM products
        WHERE id = ?
    ");
    $annexDecisionStmt = $db->prepare("
        SELECT annex.status
        FROM ingredient_ontology_identity_annex annex
        JOIN ingredient_ontology_versions version
          ON version.id = annex.ontology_version_id
         AND version.status = 'ready'
         AND version.content_hash = annex.ontology_content_hash
         AND version.seal_hash = annex.ontology_seal_hash
        WHERE annex.product_id = ?
          AND annex.ontology_version_id = ?
          AND annex.owner_fingerprint = ?
          AND annex.resolver_version = ?
          AND annex.review_manifest_hash = ?
        LIMIT 1
    ");
    $mappingStmt = $db->prepare("
        SELECT m.id AS mapping_id, m.entity_id, m.status AS mapping_status,
               m.confidence AS mapping_confidence_v3,
               m.mapping_source AS mapping_source_v3,
               m.owner_fingerprint,
               (
                   SELECT occurrence.subject_id
                   FROM ontology_subject_occurrences occurrence
                   WHERE occurrence.owner_type = 'product'
                     AND occurrence.owner_id = m.owner_id
                     AND occurrence.owner_fingerprint =
                         m.owner_fingerprint
                     AND occurrence.active = 1
                   ORDER BY occurrence.id DESC
                   LIMIT 1
               ) AS subject_id,
               m.source_label, m.attributes_json,
               m.is_staple AS mapping_is_staple,
               e.slug AS entity_slug, e.canonical_name AS entity_name
        FROM ingredient_ontology_mappings m
        LEFT JOIN ingredient_ontology_entities e ON e.id = m.entity_id
        WHERE m.ontology_version_id = ?
          AND m.owner_type = 'product'
          AND m.owner_id = ?
        LIMIT 1
    ");
    $byEntity = [];
    $byProduct = [];
    $ownerFingerprints = [];
    foreach ($inventory as &$candidate) {
        $productId = (int)$candidate['product_id'];
        if (!isset($ownerFingerprints[$productId])) {
            $productStmt->execute([$productId]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $ownerFingerprints[$productId] = $product !== null
                ? ingredientOntologyV3ProductOwnerFingerprint($product)
                : '';
        }
        $mapping = null;
        $annexAuthoritative = false;
        if ($ownerFingerprints[$productId] !== '') {
            $annexDecisionStmt->execute([
                $productId,
                $versionId,
                $ownerFingerprints[$productId],
                INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
                ingredientOntologyV3IdentityAnnexReviewManifestHash(),
            ]);
            $annexStatus = $annexDecisionStmt->fetchColumn();
            $annexAuthoritative = in_array(
                $annexStatus,
                ['accepted', 'rejected'],
                true
            );
            if ($annexStatus === 'accepted') {
                $mapping = ingredientOntologyV3IdentityAnnexMapping(
                    $db,
                    $versionId,
                    $productId,
                    $ownerFingerprints[$productId]
                );
            }
        }
        if (!$annexAuthoritative) {
            $mappingStmt->execute([$versionId, $productId]);
            $row = $mappingStmt->fetch(PDO::FETCH_ASSOC);
            $mapping = (
                $row
                && hash_equals(
                    $ownerFingerprints[$productId],
                    (string)($row['owner_fingerprint'] ?? '')
                )
            ) ? ingredientOntologyV3MappingFromRow($row) : null;
        }
        $candidate['ontology_v3_mapping'] = $mapping;
        $byProduct[$productId] = $mapping;
        if (
            $mapping !== null
            && $mapping['status'] === 'accepted'
            && $mapping['entity_id'] !== null
        ) {
            $byEntity[$mapping['entity_id']][] = &$candidate;
        }
    }
    unset($candidate);
    return [
        'rows' => $inventory,
        'by_entity' => $byEntity,
        'by_product' => $byProduct,
    ];
}

function ingredientOntologyV3QuantityGateEnabled(): bool {
    if (isset($GLOBALS['INGREDIENT_ONTOLOGY_V3_QUANTITY_GATE'])) {
        return (bool)$GLOBALS['INGREDIENT_ONTOLOGY_V3_QUANTITY_GATE'];
    }
    $value = function_exists('env')
        ? env('INGREDIENT_ONTOLOGY_V3_QUANTITY_SUFFICIENCY_GATE', 'false')
        : (
            getenv('INGREDIENT_ONTOLOGY_V3_QUANTITY_SUFFICIENCY_GATE')
                ?: 'false'
        );
    return in_array(
        strtolower(trim((string)$value)),
        ['1', 'true', 'yes', 'on'],
        true
    );
}

function ingredientOntologyV3ScoringConfiguration(): array {
    return [
        'scoring_model' => INGREDIENT_ONTOLOGY_V3_SCORING_MODEL,
        'scoring_version' => INGREDIENT_ONTOLOGY_V3_SCORING_VERSION,
        'quantity_sufficiency_gate' =>
            ingredientOntologyV3QuantityGateEnabled(),
    ];
}

function ingredientOntologyV3ScoringConfigHash(): string {
    return ingredientOntologyV3Hash(
        ingredientOntologyV3ScoringConfiguration()
    );
}

function ingredientOntologyV3ScoringConfigAudit(array $revision): array {
    $configuration = ingredientOntologyV3ScoringConfiguration();
    $currentHash = ingredientOntologyV3Hash($configuration);
    $revisionHash = is_string(
        $revision['scoring_config_hash'] ?? null
    ) ? (string)$revision['scoring_config_hash'] : null;
    $report = json_decode(
        (string)($revision['validation_report_json'] ?? ''),
        true
    );
    $reportConfiguration = is_array($report)
        && is_array($report['scoring_configuration'] ?? null)
            ? $report['scoring_configuration']
            : null;
    $reportHash = is_array($reportConfiguration)
        && is_string($reportConfiguration['hash'] ?? null)
            ? (string)$report['scoring_configuration']['hash']
            : null;
    $reportPayload = $reportConfiguration;
    if (is_array($reportPayload)) {
        unset($reportPayload['hash']);
    }
    $reportSelfValid = is_array($reportPayload)
        && $reportHash !== null
        && hash_equals(
            ingredientOntologyV3Hash($reportPayload),
            $reportHash
        );
    $valid = $revisionHash !== null
        && strlen($revisionHash) === 64
        && $reportHash !== null
        && strlen($reportHash) === 64
        && $reportSelfValid
        && hash_equals($currentHash, $revisionHash)
        && hash_equals($revisionHash, $reportHash);
    return [
        'valid' => $valid,
        'current' => array_merge(
            $configuration,
            ['hash' => $currentHash]
        ),
        'revision_hash' => $revisionHash,
        'report_hash' => $reportHash,
        'report_self_valid' => $reportSelfValid,
    ];
}

function ingredientOntologyV3RevisionIntegrityAudit(
    PDO $db,
    array $revision
): array {
    $versionId = (int)($revision['ontology_version_id'] ?? 0);
    $version = $versionId > 0
        ? ingredientOntologyV3Version($db, $versionId)
        : null;
    $errors = [];
    if ($version === null) {
        return [
            'valid' => false,
            'errors' => ['ontology version is unavailable'],
            'ontology_version_id' => $versionId,
            'hashes' => [],
            'source_owner_fingerprints' => [
                'valid' => false,
                'checked' => 0,
                'stale_count' => 0,
                'stale_sample' => [],
            ],
        ];
    }
    if ((string)$version['status'] !== 'ready') {
        $errors[] = 'ontology version is not ready';
    }
    $profile = (string)($version['corpus_profile'] ?? '');
    if (!in_array(
        $profile,
        ['eval', 'provider', 'production', 'test'],
        true
    )) {
        $errors[] = 'ontology frozen corpus profile is invalid';
    }
    $dynamicPins = function_exists(
        'ingredientOntologyControllerUsesDynamicPins'
    ) && ingredientOntologyControllerUsesDynamicPins($version)
        ? ingredientOntologyControllerDynamicVersionPins(
            $db,
            $versionId,
            $version
        )
        : null;
    $frozenCorpus = $dynamicPins !== null
        ? $dynamicPins['corpus']
        : (
            in_array(
                $profile,
                ['eval', 'provider', 'production', 'test'],
                true
            ) ? ingredientOntologyV3FrozenCorpusAudit(
                $db,
                $profile
            ) : ['valid' => false]
        );
    $subjectUniverse = $dynamicPins !== null
        ? $dynamicPins['subjects']
        : (
            in_array(
                $profile,
                ['eval', 'provider', 'production', 'test'],
                true
            ) ? ingredientOntologyV3SubjectUniverseAudit(
                $db,
                $versionId,
                $profile
            ) : ['valid' => false]
        );
    if ($dynamicPins !== null) {
        $frozenCorpus['valid'] = hash_equals(
            (string)$version['frozen_corpus_hash'],
            (string)$frozenCorpus['actual_hash']
        );
        $subjectUniverse['valid'] = hash_equals(
            (string)$version['frozen_subjects_hash'],
            (string)$subjectUniverse['subject_universe_hash']
        );
    }
    if (empty($frozenCorpus['valid'])) {
        $errors[] = 'frozen source corpus profile changed';
    }
    if (empty($subjectUniverse['valid'])) {
        $errors[] = 'reviewed subject universe changed';
    }
    $mappingAttributeIntegrity =
        ingredientOntologyV3MappingAttributeIntegrityAudit(
            $db,
            $versionId
        );
    if (!$mappingAttributeIntegrity['valid']) {
        $errors[] = 'mapping attribute companion integrity failed';
    }
    $scoreState = recipeScoreState($db);
    $currentOntologySourceHash = ingredientOntologyV3CorpusHash($db);
    if (
        (int)($revision['ontology_source_revision'] ?? -1)
            !== (int)$scoreState['ontology_source_revision']
        || !hash_equals(
            (string)($revision['ontology_source_hash'] ?? ''),
            (string)$scoreState['ontology_source_hash']
        )
        || !hash_equals(
            (string)($revision['ontology_source_hash'] ?? ''),
            $currentOntologySourceHash
        )
    ) {
        $errors[] = 'ontology source revision or hash changed';
    }
    $activationPolicy = (string)(
        $version['activation_policy'] ?? ''
    );
    $activationBlockReason = (string)(
        $version['activation_block_reason'] ?? ''
    );
    $manifest = ingredientOntologyV3ResolutionManifest();
    $expectedActivationPolicy = (
        $profile === 'test'
        || $dynamicPins !== null
    )
        ? $activationPolicy
        : (string)($manifest['activation_policy'] ?? 'blocked');
    $expectedActivationBlockReason = (
        $profile === 'test'
        || $dynamicPins !== null
    )
        ? $activationBlockReason
        : (string)($manifest['activation_block_reason'] ?? '');
    if (
        $activationPolicy !== $expectedActivationPolicy
        || $activationBlockReason !== $expectedActivationBlockReason
    ) {
        $errors[] = 'ontology activation policy or reason changed';
    }
    $expectedPolicyHash = $dynamicPins !== null
        ? (string)$dynamicPins['policy_hash']
        : (in_array(
        $profile,
        ['eval', 'provider', 'production', 'test'],
        true
    ) ? ingredientOntologyV3VersionPolicyHash(
        $profile,
        $expectedActivationPolicy,
        $expectedActivationBlockReason
    ) : '');
    if (
        !is_string($version['policy_hash'] ?? null)
        || !hash_equals(
            (string)$version['policy_hash'],
            $expectedPolicyHash
        )
    ) {
        $errors[] = 'ontology profile or activation policy hash changed';
    }
    if (
        !is_string($version['frozen_corpus_hash'] ?? null)
        || !hash_equals(
            (string)$version['frozen_corpus_hash'],
            (string)($frozenCorpus['actual_hash'] ?? '')
        )
        || !is_string($version['frozen_subjects_hash'] ?? null)
        || !hash_equals(
            (string)$version['frozen_subjects_hash'],
            (string)($subjectUniverse['subject_universe_hash'] ?? '')
        )
    ) {
        $errors[] = 'ontology frozen corpus or subject seal changed';
    }
    $currentHashes = [
        'schema' => ingredientOntologyV3SchemaHash(),
        'prompt' => ingredientOntologyV3PromptHash(),
        'model' => ingredientOntologyV3ModelHash(
            (string)$version['model_name']
        ),
        'corpus' => ingredientOntologyV3CorpusHash($db),
        'content' => ingredientOntologyV3ContentHash($db, $versionId),
        'portable_content' =>
            ingredientOntologyV3PortableContentHash($db, $versionId),
        'review_manifest' => (string)(
            ingredientOntologyV3ResolutionManifest()['manifest_hash']
        ),
        'resolution_gold' => (string)(
            ingredientOntologyV3ResolutionManifest()['file_hashes'][
                INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_FILENAME
            ] ?? ''
        ),
    ];
    $currentHashes['seal'] = ingredientOntologyV3Hash([
        'schema_hash' => $currentHashes['schema'],
        'prompt_hash' => $currentHashes['prompt'],
        'model_hash' => $currentHashes['model'],
        'corpus_hash' => $currentHashes['corpus'],
        'content_hash' => $currentHashes['content'],
        'portable_content_hash' => $currentHashes['portable_content'],
        'review_manifest_hash' => $currentHashes['review_manifest'],
        'resolution_gold_hash' => $currentHashes['resolution_gold'],
        'matcher_gold_hash' =>
            INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_SHA256,
        'matcher_gold_case_ids_hash' =>
            INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_IDS_SHA256,
        'matcher_gold_case_count' =>
            INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_COUNT,
        'corpus_profile' => $profile,
        'frozen_corpus_hash' =>
            (string)($frozenCorpus['actual_hash'] ?? ''),
        'frozen_subjects_hash' =>
            (string)($subjectUniverse['subject_universe_hash'] ?? ''),
        'activation_policy' => $activationPolicy,
        'activation_block_reason' => $activationBlockReason,
        'policy_hash' => $expectedPolicyHash,
    ]);
    $revisionColumns = [
        'schema' => 'ontology_schema_hash',
        'prompt' => 'ontology_prompt_hash',
        'model' => 'ontology_model_hash',
        'corpus' => 'ontology_corpus_hash',
        'content' => 'ontology_content_hash',
        'portable_content' => 'ontology_portable_content_hash',
        'review_manifest' => 'ontology_review_manifest_hash',
        'resolution_gold' => 'ontology_resolution_gold_hash',
        'seal' => 'ontology_seal_hash',
    ];
    $versionColumns = [
        'schema' => 'schema_hash',
        'prompt' => 'prompt_hash',
        'model' => 'model_hash',
        'corpus' => 'corpus_hash',
        'content' => 'content_hash',
        'portable_content' => 'portable_content_hash',
        'review_manifest' => 'review_manifest_hash',
        'resolution_gold' => 'resolution_gold_hash',
        'seal' => 'seal_hash',
    ];
    $hashes = [];
    foreach ($currentHashes as $name => $currentHash) {
        $revisionHash = $revision[$revisionColumns[$name]] ?? null;
        $versionHash = $version[$versionColumns[$name]] ?? null;
        $matches = is_string($revisionHash)
            && is_string($versionHash)
            && strlen($revisionHash) === 64
            && strlen($versionHash) === 64
            && hash_equals($versionHash, $revisionHash)
            && hash_equals($versionHash, $currentHash);
        $hashes[$name] = $matches;
        if (!$matches) {
            $errors[] = "ontology {$name} hash changed";
        }
    }
    $ownerFingerprints = ingredientOntologyV3OwnerFingerprintAudit(
        $db,
        $versionId
    );
    if (!$ownerFingerprints['valid']) {
        $errors[] = 'source owner fingerprints changed after ontology build';
    }
    $hashIntegrity = ingredientOntologyV3HashIntegrityAudit(
        $db,
        $versionId,
        true
    );
    if (!$hashIntegrity['valid']) {
        $errors[] = 'ontology row or seal hash integrity failed';
    }
    return [
        'valid' => !$errors,
        'errors' => $errors,
        'ontology_version_id' => $versionId,
        'hashes' => $hashes,
        'source_owner_fingerprints' => $ownerFingerprints,
        'row_hash_integrity' => $hashIntegrity,
        'frozen_corpus' => $frozenCorpus,
        'subject_universe' => $subjectUniverse,
        'mapping_attribute_integrity' =>
            $mappingAttributeIntegrity,
        'policy_hash_matches' => !in_array(
            'ontology profile or activation policy hash changed',
            $errors,
            true
        ),
    ];
}

function ingredientOntologyV3InventoryFingerprint(
    array $inventory,
    int $versionId
): string {
    $rows = [];
    foreach ($inventory['rows'] as $candidate) {
        $mapping = $candidate['ontology_v3_mapping'] ?? null;
        $rows[] = [
            'inventory_id' => (int)$candidate['inventory_id'],
            'product_id' => (int)$candidate['product_id'],
            'quantity' => round((float)$candidate['quantity'], 6),
            'unit' => (string)$candidate['unit'],
            'default_quantity' => round(
                (float)($candidate['default_quantity'] ?? 0),
                6
            ),
            'package_unit' => (string)($candidate['package_unit'] ?? ''),
            'effective_expiry_date' =>
                $candidate['effective_expiry_date'] ?? null,
            'mapping_id' => $mapping['mapping_id'] ?? null,
            'entity_id' => $mapping['entity_id'] ?? null,
            'status' => $mapping['status'] ?? null,
            'attributes' => $mapping['attributes'] ?? [],
        ];
    }

    return ingredientOntologyV3Hash([
        'ontology_version_id' => $versionId,
        'inventory' => $rows,
    ]);
}

function ingredientOntologyV3LoadRecipeBatch(
    PDO $db,
    int $versionId,
    array $recipeIds
): array {
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
               COALESCE(us.favorite, 0) AS favorite, us.rating
        FROM recipe_catalog c
        LEFT JOIN recipe_user_state us ON us.recipe_id = c.id
        WHERE c.id IN ({$placeholders}) AND c.deleted_at IS NULL
    ");
    $summary->execute($recipeIds);
    $recipes = [];
    while ($row = $summary->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$row['id'];
        $recipes[$id] = [
            'id' => $id,
            'primary_connector' => (string)$row['primary_connector'],
            'favorite' => !empty($row['favorite']),
            'rating' => $row['rating'] !== null ? (int)$row['rating'] : null,
            'ingredients' => [],
        ];
    }
    $sourceOptionality = [];
    $source = $db->prepare("
        SELECT recipe_id, normalized_name,
               CASE
                   WHEN MAX(
                       CASE WHEN source_optional = 0 THEN 1 ELSE 0 END
                   ) = 1 THEN 0
                   WHEN MAX(
                       CASE WHEN source_optional = 1 THEN 1 ELSE 0 END
                   ) = 1 THEN 1
                   ELSE NULL
               END AS provider_source_optional
        FROM recipe_source_ingredients
        WHERE recipe_id IN ({$placeholders})
        GROUP BY recipe_id, normalized_name
    ");
    $source->execute($recipeIds);
    while ($row = $source->fetch(PDO::FETCH_ASSOC)) {
        $key = (int)$row['recipe_id']
            . "\n"
            . (string)$row['normalized_name'];
        $sourceOptionality[$key] =
            $row['provider_source_optional'] !== null
                ? (int)$row['provider_source_optional']
                : null;
    }
    $params = array_merge([$versionId], $recipeIds);
    $ingredients = $db->prepare("
        SELECT ri.id AS recipe_ingredient_id, ri.recipe_id, ri.position,
               ri.normalized_name, ri.quantity, ri.unit,
               ri.is_required, ri.is_optional, ri.is_staple,
               ri.source_is_required, ri.source_is_optional,
               ri.requiredness_source,
               m.id AS mapping_id, m.entity_id,
               m.status AS mapping_status,
               m.confidence AS mapping_confidence_v3,
               m.mapping_source AS mapping_source_v3,
               m.owner_fingerprint,
               (
                   SELECT occurrence.subject_id
                   FROM ontology_subject_occurrences occurrence
                   WHERE occurrence.owner_type = 'recipe_ingredient'
                     AND occurrence.owner_id = m.owner_id
                     AND occurrence.owner_fingerprint =
                         m.owner_fingerprint
                     AND occurrence.active = 1
                   ORDER BY occurrence.id DESC
                   LIMIT 1
               ) AS subject_id,
               m.source_label, m.attributes_json,
               m.is_staple AS mapping_is_staple,
               e.slug AS entity_slug, e.canonical_name AS entity_name
        FROM recipe_ingredients ri
        LEFT JOIN ingredient_ontology_mappings m
          ON m.ontology_version_id = ?
         AND m.owner_type = 'recipe_ingredient'
         AND m.owner_id = ri.id
        LEFT JOIN ingredient_ontology_entities e ON e.id = m.entity_id
        WHERE ri.recipe_id IN ({$placeholders})
        ORDER BY ri.recipe_id, ri.position
    ");
    $ingredients->execute($params);
    while ($row = $ingredients->fetch(PDO::FETCH_ASSOC)) {
        $recipeId = (int)$row['recipe_id'];
        if (!isset($recipes[$recipeId])) {
            continue;
        }
        $sourceKey = $recipeId
            . "\n"
            . (string)$row['normalized_name'];
        $providerSourceOptional = $sourceOptionality[$sourceKey] ?? null;
        $recipes[$recipeId]['ingredients'][] = [
            'id' => (int)$row['recipe_ingredient_id'],
            'position' => (int)$row['position'],
            'normalized_name' => (string)$row['normalized_name'],
            'quantity' => $row['quantity'] !== null
                ? (float)$row['quantity']
                : null,
            'unit' => $row['unit'] !== null ? (string)$row['unit'] : null,
            'is_required' => !empty($row['is_required']),
            'is_optional' => !empty($row['is_optional']),
            'legacy_is_staple' => !empty($row['is_staple']),
            'source_is_required' => $row['source_is_required'] !== null
                ? !empty($row['source_is_required'])
                : null,
            'source_is_optional' => $row['source_is_optional'] !== null
                ? !empty($row['source_is_optional'])
                : null,
            'requiredness_source' =>
                (string)($row['requiredness_source'] ?? 'legacy_backfill'),
            'provider_source_optional' =>
                $providerSourceOptional !== null
                    ? (bool)$providerSourceOptional
                    : null,
            'mapping' => ingredientOntologyV3MappingFromRow($row),
        ];
    }
    return $recipes;
}

function ingredientOntologyV3CandidateEntityIds(
    IngredientOntologyV3MatcherContext $context,
    int $requiredEntityId,
    array $inventoryByEntity
): array {
    $result = [];
    foreach (array_keys($inventoryByEntity) as $inventoryEntityId) {
        $inventoryEntityId = (int)$inventoryEntityId;
        if (
            $inventoryEntityId === $requiredEntityId
            || isset($context->ancestry[$inventoryEntityId][$requiredEntityId])
            || isset($context->ancestry[$requiredEntityId][$inventoryEntityId])
            || isset($context->relations[$requiredEntityId][$inventoryEntityId])
            || isset($context->relations[$inventoryEntityId][$requiredEntityId])
        ) {
            $result[] = $inventoryEntityId;
        }
    }
    return $result;
}

function ingredientOntologyV3MatchRank(array $match): array {
    $outcomePriority = [
        'exact' => 100,
        'reviewed_equivalent' => 95,
        'reviewed_descendant' => 90,
        'compatible_variant' => 80,
        'reviewed_generalization' => 75,
        'uncertain' => 60,
        'different_form' => 55,
        'different_processing' => 55,
        'different_cut' => 55,
        'different_bone' => 55,
        'different_skin' => 55,
        'different_refinement' => 55,
        'different_variety' => 55,
        'different_state' => 55,
        'different_species' => 55,
        'different_saltedness' => 55,
        'different_sweetening' => 55,
        'different_fat_content' => 55,
        'different_cream_class' => 55,
        'different_egg_part' => 55,
        'possible_substitute' => 40,
        'broader_requirement_evidence' => 30,
        'pantry_ancestor' => 30,
        'non_identity_relation' => 20,
        'no_identity_match' => 0,
    ];
    return [
        !empty($match['satisfies_required']) ? 1 : 0,
        (float)($match['score'] ?? 0),
        (int)($outcomePriority[$match['outcome'] ?? ''] ?? 0),
        (float)($match['confidence'] ?? 0),
    ];
}

function ingredientOntologyV3RequiredOutcomeClass(string $outcome): string {
    return in_array($outcome, [
        'unresolved',
        'uncertain',
        'candidate_evidence',
        'ambiguous',
        'broader_requirement_evidence',
        'pantry_ancestor',
        'possible_substitute',
        'non_identity_relation',
        'structural_category',
        'staple_path_required',
    ], true) ? 'uncertain' : 'missing';
}

function ingredientOntologyV3RankGreater(array $left, array $right): bool {
    foreach ($left as $index => $value) {
        if ($value > $right[$index]) {
            return true;
        }
        if ($value < $right[$index]) {
            return false;
        }
    }
    return false;
}

function ingredientOntologyV3BestInventoryMatch(
    IngredientOntologyV3MatcherContext $context,
    array $recipeMapping,
    array $inventory,
    array &$candidateCache
): ?array {
    if (
        $recipeMapping['status'] !== 'accepted'
        || $recipeMapping['entity_id'] === null
    ) {
        return null;
    }
    $requiredEntityId = (int)$recipeMapping['entity_id'];
    if (!isset($candidateCache[$requiredEntityId])) {
        $candidateCache[$requiredEntityId] =
            ingredientOntologyV3CandidateEntityIds(
                $context,
                $requiredEntityId,
                $inventory['by_entity']
            );
    }
    $best = null;
    $bestRank = [-1, -1.0, -1, -1.0];
    $compatibleRows = [];
    foreach ($candidateCache[$requiredEntityId] as $entityId) {
        foreach ($inventory['by_entity'][$entityId] ?? [] as $candidate) {
            $inventoryMapping = $candidate['ontology_v3_mapping'];
            $match = ingredientOntologyV3MatchWithContext(
                $context,
                $recipeMapping,
                $inventoryMapping
            );
            $rank = ingredientOntologyV3MatchRank($match);
            if (ingredientOntologyV3RankGreater($rank, $bestRank)) {
                $best = [
                    'match' => $match,
                    'candidate' => $candidate,
                    'inventory_mapping' => $inventoryMapping,
                ];
                $bestRank = $rank;
            }
            if (!empty($match['satisfies_required'])) {
                $compatibleRows[(int)$candidate['inventory_id']] = $candidate;
            }
        }
    }
    if ($best !== null && !empty($best['match']['satisfies_required'])) {
        ksort($compatibleRows, SORT_NUMERIC);
        $best['stock_rows'] = array_values($compatibleRows)
            ?: [$best['candidate']];
        $productIds = [];
        $minimumDays = null;
        foreach ($best['stock_rows'] as $stockRow) {
            $productIds[(int)$stockRow['product_id']] = true;
            if ($stockRow['days_remaining'] === null) {
                continue;
            }
            $days = (int)$stockRow['days_remaining'];
            $minimumDays = $minimumDays === null
                ? $days
                : min($minimumDays, $days);
        }
        $compatibleProductIds = array_map(
            'intval',
            array_keys($productIds)
        );
        sort($compatibleProductIds, SORT_NUMERIC);
        $best['compatible_product_ids'] = $compatibleProductIds;
        $best['compatible_row_count'] = count($best['stock_rows']);
        $best['compatible_product_count'] =
            count($compatibleProductIds);
        $best['minimum_days_remaining'] = $minimumDays;
    }
    return $best;
}

function ingredientOntologyV3ScoreRecipe(
    IngredientOntologyV3MatcherContext $context,
    array $recipe,
    array $inventory,
    array &$candidateCache
): array {
    $requiredCount = 0;
    $matchedRequired = 0;
    $missingRequired = 0;
    $uncertainRequired = 0;
    $directnessTotal = 0.0;
    $directnessCount = 0;
    $expiryScore = 0.0;
    $matchedExpiryDays = [];
    $matchRows = [];
    foreach ($recipe['ingredients'] as $ingredient) {
        $mapping = $ingredient['mapping'];
        $isStaple = $mapping !== null && !empty($mapping['is_staple']);
        $providerSourceOptional =
            $ingredient['provider_source_optional'] ?? null;
        if ($providerSourceOptional !== null) {
            $sourceOptional = $providerSourceOptional;
            $sourceRequired = !$sourceOptional;
        } else {
            $sourceOptional = $ingredient['source_is_optional']
                ?? !empty($ingredient['is_optional']);
            $sourceRequired = $ingredient['source_is_required']
                ?? (
                    !empty($ingredient['is_required'])
                    || !empty($ingredient['legacy_is_staple'])
                );
        }
        $requirementSource = $providerSourceOptional !== null
            ? 'provider_source_optional'
            : (string)(
                $ingredient['requiredness_source'] ?? 'legacy_backfill'
            );
        $isRequired = $sourceRequired
            && !$sourceOptional
            && !$isStaple;
        if ($isRequired) {
            $requiredCount++;
        }
        if ($isStaple) {
            $matchRows[] = [
                'recipe_ingredient_id' => $ingredient['id'],
                'recipe_mapping_id' => $mapping['mapping_id'],
                'inventory_product_id' => null,
                'inventory_mapping_id' => null,
                'outcome' => 'staple',
                'satisfies_required' => 1,
                'confidence' => 1.0,
                'relationship' => 'staple',
                'explanation' => [
                    'outcome' => 'staple',
                    'reason' => 'exact_multilingual_staple_allowlist',
                    'requirement' => [
                        'required' => false,
                        'optional' => $sourceOptional,
                        'staple' => true,
                        'source' => $requirementSource,
                    ],
                ],
            ];
            continue;
        }
        if ($mapping === null || $mapping['status'] !== 'accepted') {
            $outcome = $mapping === null
                ? 'unresolved'
                : match ($mapping['status']) {
                    'candidate' => 'candidate_evidence',
                    'ambiguous' => 'ambiguous',
                    'rejected' => 'rejected',
                    default => 'unresolved',
                };
            if ($isRequired) {
                if (
                    ingredientOntologyV3RequiredOutcomeClass($outcome)
                        === 'uncertain'
                ) {
                    $uncertainRequired++;
                } else {
                    $missingRequired++;
                }
            }
            $matchRows[] = [
                'recipe_ingredient_id' => $ingredient['id'],
                'recipe_mapping_id' => $mapping['mapping_id'] ?? null,
                'inventory_product_id' => null,
                'inventory_mapping_id' => null,
                'outcome' => $outcome,
                'satisfies_required' => 0,
                'confidence' => 0.0,
                'relationship' => 'none',
                'explanation' => [
                    'outcome' => $outcome,
                    'reason' => 'recipe_mapping_is_not_accepted_identity',
                    'mapping_status' => $mapping['status'] ?? 'missing',
                    'requirement' => [
                        'required' => $isRequired,
                        'optional' => $sourceOptional,
                        'staple' => false,
                        'source' => $requirementSource,
                    ],
                ],
            ];
            continue;
        }
        $best = ingredientOntologyV3BestInventoryMatch(
            $context,
            $mapping,
            $inventory,
            $candidateCache
        );
        if ($best === null) {
            if ($isRequired) {
                $missingRequired++;
            }
            $matchRows[] = [
                'recipe_ingredient_id' => $ingredient['id'],
                'recipe_mapping_id' => $mapping['mapping_id'],
                'inventory_product_id' => null,
                'inventory_mapping_id' => null,
                'outcome' => 'not_in_inventory',
                'satisfies_required' => 0,
                'confidence' => 1.0,
                'relationship' => 'none',
                'explanation' => [
                    'outcome' => 'not_in_inventory',
                    'entity' => [
                        'id' => $mapping['entity_id'],
                        'slug' => $mapping['entity_slug'] ?? null,
                    ],
                    'attributes' => $mapping['attributes'],
                    'requirement' => [
                        'required' => $isRequired,
                        'optional' => $sourceOptional,
                        'staple' => false,
                        'source' => $requirementSource,
                    ],
                ],
            ];
            continue;
        }
        $match = $best['match'];
        $quantity = !empty($match['satisfies_required'])
            ? recipeCatalogQuantitySufficiency(
                $ingredient,
                ['stock_rows' => $best['stock_rows'] ?? [$best['candidate']]]
            )
            : ['known' => false, 'ratio' => null, 'sufficient' => false];
        $identitySatisfied = !empty($match['satisfies_required']);
        $quantityEnforced = ingredientOntologyV3QuantityGateEnabled();
        $satisfied = $identitySatisfied
            && (
                !$quantityEnforced
                || !empty($quantity['sufficient'])
            );
        $storedOutcome = $satisfied
            ? $match['outcome']
            : (
                $identitySatisfied
                && empty($quantity['sufficient'])
                && $quantityEnforced
                    ? 'insufficient_quantity'
                    : $match['outcome']
            );
        if ($satisfied) {
            if ($isRequired) {
                $matchedRequired++;
            }
            $directnessTotal += min(1.0, (float)$match['score']);
            $directnessCount++;
            $daysRemaining = $best['minimum_days_remaining'] ?? null;
            if ($daysRemaining !== null) {
                $matchedExpiryDays[] = (int)$daysRemaining;
            }
            $expiryScore = max(
                $expiryScore,
                recipeCatalogExpiryUrgency(
                    $daysRemaining !== null ? (int)$daysRemaining : null
                ) * min(1.0, (float)$match['score'])
            );
        } elseif ($isRequired) {
            if (
                ingredientOntologyV3RequiredOutcomeClass($storedOutcome)
                    === 'uncertain'
            ) {
                $uncertainRequired++;
            } else {
                $missingRequired++;
            }
        }
        $explanation = $match;
        $explanation['quantity'] = $quantity;
        $explanation['quantity']['enforced'] = $quantityEnforced;
        $explanation['inventory_aggregate'] = [
            'compatible_row_count' => min(
                1000000,
                (int)($best['compatible_row_count'] ?? 1)
            ),
            'compatible_product_count' => min(
                1000000,
                (int)($best['compatible_product_count'] ?? 1)
            ),
            'minimum_days_remaining' =>
                $best['minimum_days_remaining'] ?? null,
            'product_ids' => array_values(array_map(
                'intval',
                $best['compatible_product_ids'] ?? [
                    (int)$best['candidate']['product_id'],
                ]
            )),
            'contributors_complete' => true,
        ];
        $explanation['requirement'] = [
            'required' => $isRequired,
            'optional' => $sourceOptional,
            'staple' => false,
            'source' => $requirementSource,
        ];
        $matchRows[] = [
            'recipe_ingredient_id' => $ingredient['id'],
            'recipe_mapping_id' => $mapping['mapping_id'],
            'inventory_product_id' =>
                (int)$best['candidate']['product_id'],
            'inventory_mapping_id' =>
                $best['inventory_mapping']['mapping_id'] !== null
                    ? (int)$best['inventory_mapping']['mapping_id']
                    : null,
            'outcome' => $storedOutcome,
            'satisfies_required' => $satisfied ? 1 : 0,
            'confidence' => (float)$match['confidence'],
            'relationship' => (string)$match['relationship'],
            'explanation' => $explanation,
        ];
    }
    $coverage = $requiredCount === 0
        ? 1.0
        : $matchedRequired / $requiredCount;
    $directness = $directnessCount > 0
        ? $directnessTotal / $directnessCount
        : 0.0;
    $sourceUser = recipeCatalogSourceUserScore($recipe);
    $blockedCount = $missingRequired + $uncertainRequired;
    $availabilityScore = recipeCatalogApplyMissingGate(
        $coverage * 0.75 + $directness * 0.15 + $sourceUser * 0.10,
        $coverage,
        $blockedCount
    );
    return [
        'score' => [
            'recipe_id' => (int)$recipe['id'],
            'coverage' => round($coverage, 6),
            'directness' => round($directness, 6),
            'expiry_score' => round($expiryScore, 6),
            'source_user_score' => round($sourceUser, 6),
            'availability_score' => round(
                max(0.0, min(1.0, $availabilityScore)),
                6
            ),
            'required_count' => $requiredCount,
            'matched_required_count' => $matchedRequired,
            'missing_required_count' => $missingRequired,
            'uncertain_required_count' => $uncertainRequired,
            'cookable' => $missingRequired === 0
                && $uncertainRequired === 0
                ? 1
                : 0,
            'soonest_expiry_days' => $matchedExpiryDays
                ? min($matchedExpiryDays)
                : null,
        ],
        'matches' => $matchRows,
    ];
}

function ingredientOntologyV3WriteScoreRows(
    PDO $db,
    int $revisionId,
    array $scores,
    array $matches
): void {
    $score = $db->prepare("
        INSERT INTO recipe_inventory_scores (
            score_revision_id, recipe_id, coverage, directness,
            expiry_score, source_user_score, availability_score,
            required_count, matched_required_count, missing_required_count,
            uncertain_required_count, cookable, soonest_expiry_days,
            updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(score_revision_id, recipe_id) DO UPDATE SET
            coverage = excluded.coverage,
            directness = excluded.directness,
            expiry_score = excluded.expiry_score,
            source_user_score = excluded.source_user_score,
            availability_score = excluded.availability_score,
            required_count = excluded.required_count,
            matched_required_count = excluded.matched_required_count,
            missing_required_count = excluded.missing_required_count,
            uncertain_required_count = excluded.uncertain_required_count,
            cookable = excluded.cookable,
            soonest_expiry_days = excluded.soonest_expiry_days,
            updated_at = CURRENT_TIMESTAMP
    ");
    foreach ($scores as $row) {
        $score->execute([
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
            $row['uncertain_required_count'],
            $row['cookable'],
            $row['soonest_expiry_days'],
        ]);
    }
    $match = $db->prepare("
        INSERT INTO ingredient_ontology_shadow_matches (
            score_revision_id, recipe_ingredient_id, recipe_mapping_id,
            inventory_product_id, inventory_mapping_id, outcome,
            satisfies_required, confidence, relationship,
            explanation_json
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(score_revision_id, recipe_ingredient_id) DO UPDATE SET
            recipe_mapping_id = excluded.recipe_mapping_id,
            inventory_product_id = excluded.inventory_product_id,
            inventory_mapping_id = excluded.inventory_mapping_id,
            outcome = excluded.outcome,
            satisfies_required = excluded.satisfies_required,
            confidence = excluded.confidence,
            relationship = excluded.relationship,
            explanation_json = excluded.explanation_json
    ");
    foreach ($matches as $row) {
        $json = ingredientOntologyV3Json($row['explanation']);
        if (strlen($json) > 32768) {
            $aggregate = is_array(
                $row['explanation']['inventory_aggregate'] ?? null
            ) ? $row['explanation']['inventory_aggregate'] : [];
            $productIds = array_values(array_filter(
                array_map(
                    'intval',
                    (array)($aggregate['product_ids'] ?? [])
                ),
                static fn(int $id): bool => $id > 0
            ));
            $json = ingredientOntologyV3Json([
                'outcome' => $row['outcome'],
                'reason' => 'explanation_truncated',
                'requirement' => is_array(
                    $row['explanation']['requirement'] ?? null
                ) ? $row['explanation']['requirement'] : [
                    'required' => false,
                    'optional' => false,
                    'staple' => $row['outcome'] === 'staple',
                ],
                'inventory_aggregate' => [
                    'product_ids' => $productIds,
                    'contributors_complete' => true,
                ],
            ]);
            if (strlen($json) > 32768) {
                throw new RuntimeException(
                    'score match contributor set exceeds explanation limit'
                );
            }
        }
        $match->execute([
            $revisionId,
            $row['recipe_ingredient_id'],
            $row['recipe_mapping_id'],
            $row['inventory_product_id'],
            $row['inventory_mapping_id'],
            $row['outcome'],
            $row['satisfies_required'],
            $row['confidence'],
            $row['relationship'],
            $json,
        ]);
    }
}

function ingredientOntologyV3BuildShadow(
    PDO $db,
    int $versionId,
    int $batchSize = 250,
    ?callable $progress = null,
    bool $lockAlreadyHeld = false,
    ?array $expectedParent = null
): array {
    if (function_exists(
        'ingredientOntologyControllerAssertCopiedGenerationDatabase'
    )) {
        ingredientOntologyControllerAssertCopiedGenerationDatabase($db);
    }
    ingredientOntologyV3SchemaMigrate($db);
    $version = ingredientOntologyV3Version($db, $versionId);
    $activeVersion = ingredientOntologyV3ActiveVersion($db);
    if (
        $version !== null
        && (string)$version['status'] === 'ready'
        && ($activeVersion === null
            || (int)$activeVersion['id'] !== $versionId)
        && function_exists('ingredientOntologyControllerSealVersion')
        && !hash_equals(
            (string)$version['corpus_hash'],
            ingredientOntologyV3CorpusHash($db)
        )
    ) {
        ingredientOntologyV3WithReadyMutationGuard(
            $db,
            static function () use ($db, $versionId): void {
                $db->prepare("
                    UPDATE ingredient_ontology_versions
                    SET status = 'building',
                        ready_at = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND status = 'ready'
                ")->execute([$versionId]);
            }
        );
        ingredientOntologyControllerSealVersion(
            $db,
            $versionId,
            [
                'allow_test_fixture' =>
                    defined('RECIPE_BACKEND_TEST_MODE')
                    && RECIPE_BACKEND_TEST_MODE,
            ]
        );
        $version = ingredientOntologyV3Version($db, $versionId);
    }
    if ($version === null || $version['status'] !== 'ready') {
        throw new InvalidArgumentException(
            'shadow scoring requires a ready ontology version'
        );
    }
    $lock = null;
    if (!$lockAlreadyHeld) {
        $lock = ingredientOntologyV3AcquireLock($db);
        if ($lock === false) {
            return ['built' => false, 'reason' => 'locked'];
        }
        recipeScoreFailAbandonedBuilds($db);
    }
    $revisionId = 0;
    try {
        $state = recipeScoreState($db);
        $expectedParentRevisionId = null;
        $expectedParentOntologyVersionId = null;
        if ($expectedParent !== null) {
            $expectedParentRevisionId = (int)(
                $expectedParent['revision_id']
                    ?? $expectedParent['id']
                    ?? 0
            );
            $expectedParentOntologyVersionId = (int)(
                $expectedParent['ontology_version_id'] ?? 0
            );
            if (
                $expectedParentRevisionId <= 0
                || $expectedParentOntologyVersionId <= 0
                || $expectedParentOntologyVersionId !== $versionId
            ) {
                throw new InvalidArgumentException(
                    'expected shadow parent is invalid'
                );
            }
            if (
                $state['active_score_revision_id']
                    !== $expectedParentRevisionId
            ) {
                throw new RuntimeException(
                    'active score pointer changed before shadow build'
                );
            }
            $currentParent = recipeScoreRevision(
                $db,
                $expectedParentRevisionId
            );
            if (
                $currentParent === null
                || $currentParent['status'] !== 'ready'
                || (string)($currentParent['scoring_model'] ?? '')
                    !== INGREDIENT_ONTOLOGY_V3_SCORING_MODEL
                || (int)($currentParent['ontology_version_id'] ?? 0)
                    !== $expectedParentOntologyVersionId
            ) {
                throw new RuntimeException(
                    'expected v3 shadow parent changed or is unavailable'
                );
            }
        }
        $scoringConfiguration =
            ingredientOntologyV3ScoringConfiguration();
        $scoringConfigHash =
            ingredientOntologyV3ScoringConfigHash();
        $parentRevisionId = $expectedParentRevisionId
            ?? $state['active_score_revision_id'];
        $catalogCount = (int)$db->query("
            SELECT COUNT(*) FROM recipe_catalog WHERE deleted_at IS NULL
        ")->fetchColumn();
        $catalogMaxId = recipeScoreCatalogMaxId($db);
        $catalogFingerprint = recipeScoreCatalogFingerprint($db);
        $ontologySourceHash = ingredientOntologyV3CorpusHash($db);
        $inventory = ingredientOntologyV3Inventory($db, $versionId);
        $fingerprint = ingredientOntologyV3InventoryFingerprint(
            $inventory,
            $versionId
        );
        $insert = $db->prepare("
            INSERT INTO recipe_score_revisions (
                inventory_revision, catalog_revision, inventory_fingerprint,
                score_date, catalog_max_id, status, ontology_version_id,
                scoring_model, scoring_config_hash,
                parent_score_revision_id, catalog_fingerprint,
                ontology_schema_hash, ontology_prompt_hash,
                ontology_model_hash, ontology_corpus_hash,
                ontology_content_hash, ontology_portable_content_hash,
                ontology_review_manifest_hash,
                ontology_resolution_gold_hash, ontology_seal_hash,
                ontology_source_revision, ontology_source_hash
            )
            VALUES (?, ?, ?, ?, ?, 'building', ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $state['inventory_revision'],
            $state['catalog_revision'],
            $fingerprint,
            date('Y-m-d'),
            $catalogMaxId,
            $versionId,
            INGREDIENT_ONTOLOGY_V3_SCORING_MODEL,
            $scoringConfigHash,
            $parentRevisionId,
            $catalogFingerprint,
            $version['schema_hash'],
            $version['prompt_hash'],
            $version['model_hash'],
            $version['corpus_hash'],
            $version['content_hash'],
            $version['portable_content_hash'],
            $version['review_manifest_hash'],
            $version['resolution_gold_hash'],
            $version['seal_hash'],
            $state['ontology_source_revision'],
            $ontologySourceHash,
        ]);
        $revisionId = (int)$db->lastInsertId();
        $sourceIntegrity = ingredientOntologyV3OwnerFingerprintAudit(
            $db,
            $versionId
        );
        $currentHashes = [
            'schema' => ingredientOntologyV3SchemaHash(),
            'prompt' => ingredientOntologyV3PromptHash(),
            'model' => ingredientOntologyV3ModelHash(
                (string)$version['model_name']
            ),
            'corpus' => ingredientOntologyV3CorpusHash($db),
            'content' =>
                ingredientOntologyV3ContentHash($db, $versionId),
        ];
        $stale = [];
        if (!$sourceIntegrity['valid']) {
            $stale[] = 'owner_fingerprints';
        }
        foreach ([
            'schema' => 'schema_hash',
            'prompt' => 'prompt_hash',
            'model' => 'model_hash',
            'corpus' => 'corpus_hash',
            'content' => 'content_hash',
        ] as $name => $column) {
            if (!hash_equals(
                (string)$version[$column],
                (string)$currentHashes[$name]
            )) {
                $stale[] = $name;
            }
        }
        if ($stale) {
            throw new RuntimeException(
                'shadow scoring source or ontology hashes are stale: '
                . ingredientOntologyV3Json([
                    'fields' => $stale,
                    'sealed_corpus' => (string)$version['corpus_hash'],
                    'current_corpus' => (string)$currentHashes['corpus'],
                    'source_revision' =>
                        (int)$state['ontology_source_revision'],
                ])
            );
        }
        $context = new IngredientOntologyV3MatcherContext($db, $versionId);
        $batchSize = max(1, min(500, $batchSize));
        $lastId = 0;
        $written = 0;
        $candidateCache = [];
        while (true) {
            $ids = $db->prepare("
                SELECT id FROM recipe_catalog
                WHERE deleted_at IS NULL AND id > ? AND id <= ?
                ORDER BY id LIMIT {$batchSize}
            ");
            $ids->execute([$lastId, $catalogMaxId]);
            $recipeIds = array_map(
                'intval',
                $ids->fetchAll(PDO::FETCH_COLUMN)
            );
            if (!$recipeIds) {
                break;
            }
            $recipes = ingredientOntologyV3LoadRecipeBatch(
                $db,
                $versionId,
                $recipeIds
            );
            $scores = [];
            $matches = [];
            foreach ($recipes as $recipe) {
                $result = ingredientOntologyV3ScoreRecipe(
                    $context,
                    $recipe,
                    $inventory,
                    $candidateCache
                );
                $scores[] = $result['score'];
                array_push($matches, ...$result['matches']);
            }
            $db->beginTransaction();
            try {
                ingredientOntologyV3WriteScoreRows(
                    $db,
                    $revisionId,
                    $scores,
                    $matches
                );
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            $written += count($scores);
            $lastId = max($recipeIds);
            if ($progress !== null) {
                $progress($written, $catalogCount);
            }
        }
        $db->beginTransaction();
        try {
            $currentState = recipeScoreState($db);
            $currentCatalogCount = (int)$db->query("
                SELECT COUNT(*) FROM recipe_catalog WHERE deleted_at IS NULL
            ")->fetchColumn();
            $currentCatalogMaxId = recipeScoreCatalogMaxId($db);
            $currentCatalogFingerprint = recipeScoreCatalogFingerprint($db);
            $currentCorpusHash = ingredientOntologyV3CorpusHash($db);
            $currentContentHash = ingredientOntologyV3ContentHash(
                $db,
                $versionId
            );
            $currentVersion = ingredientOntologyV3Version($db, $versionId);
            $currentParent = $expectedParentRevisionId !== null
                ? recipeScoreRevision($db, $expectedParentRevisionId)
                : null;
            $ownerFingerprints = ingredientOntologyV3OwnerFingerprintAudit(
                $db,
                $versionId
            );
            $scoreCountStmt = $db->prepare("
                SELECT COUNT(*) FROM recipe_inventory_scores
                WHERE score_revision_id = ?
            ");
            $scoreCountStmt->execute([$revisionId]);
            $scoreCount = (int)$scoreCountStmt->fetchColumn();
            $matchCountStmt = $db->prepare("
                SELECT COUNT(*) FROM ingredient_ontology_shadow_matches
                WHERE score_revision_id = ?
            ");
            $matchCountStmt->execute([$revisionId]);
            $matchCount = (int)$matchCountStmt->fetchColumn();
            $ingredientCount = (int)$db->query("
                SELECT COUNT(*)
                FROM recipe_ingredients ri
                JOIN recipe_catalog c ON c.id = ri.recipe_id
                WHERE c.deleted_at IS NULL
            ")->fetchColumn();
            if (
                $currentState['inventory_revision']
                    !== $state['inventory_revision']
                || $currentState['catalog_revision']
                    !== $state['catalog_revision']
                || $currentState['ontology_source_revision']
                    !== $state['ontology_source_revision']
                || $currentState['active_score_revision_id']
                    !== $parentRevisionId
                || $currentCatalogCount !== $catalogCount
                || $currentCatalogMaxId !== $catalogMaxId
                || !hash_equals(
                    $catalogFingerprint,
                    $currentCatalogFingerprint
                )
                || !hash_equals(
                    (string)$version['corpus_hash'],
                    $currentCorpusHash
                )
                || !hash_equals(
                    $ontologySourceHash,
                    $currentCorpusHash
                )
                || !hash_equals(
                    (string)$version['content_hash'],
                    $currentContentHash
                )
                || $currentVersion === null
                || $currentVersion['status'] !== 'ready'
                || !hash_equals(
                    (string)$version['schema_hash'],
                    (string)$currentVersion['schema_hash']
                )
                || !hash_equals(
                    (string)$version['prompt_hash'],
                    (string)$currentVersion['prompt_hash']
                )
                || !hash_equals(
                    (string)$version['model_hash'],
                    (string)$currentVersion['model_hash']
                )
                || !hash_equals(
                    (string)$version['corpus_hash'],
                    (string)$currentVersion['corpus_hash']
                )
                || !hash_equals(
                    (string)$version['content_hash'],
                    (string)$currentVersion['content_hash']
                )
                || !hash_equals(
                    $scoringConfigHash,
                    ingredientOntologyV3ScoringConfigHash()
                )
                || (
                    $expectedParentRevisionId !== null
                    && (
                        $currentParent === null
                        || $currentParent['status'] !== 'ready'
                        || (string)($currentParent['scoring_model'] ?? '')
                            !== INGREDIENT_ONTOLOGY_V3_SCORING_MODEL
                        || (int)(
                            $currentParent['ontology_version_id'] ?? 0
                        ) !== $expectedParentOntologyVersionId
                    )
                )
                || !$ownerFingerprints['valid']
                || $scoreCount !== $catalogCount
                || $matchCount !== $ingredientCount
            ) {
                throw new RuntimeException(
                    'shadow inputs changed or materialization is incomplete'
                );
            }
            $idSetHashes = ingredientOntologyV3MaterializedIdSetHashes(
                $db,
                $revisionId,
                null
            );
            $revisionForSetAudit = recipeScoreRevision(
                $db,
                $revisionId
            );
            $idSetAudit = ingredientOntologyV3MaterializedIdSetAudit(
                $db,
                array_merge(
                    $revisionForSetAudit ?? ['id' => $revisionId],
                    $idSetHashes,
                    ['requirement_revision_id' => null]
                )
            );
            if (!$idSetAudit['valid']) {
                throw new RuntimeException(
                    'shadow materialized ID sets are not equal'
                );
            }
            $valueHashes = ingredientOntologyV3MaterializedValueHashes(
                $db,
                $revisionId,
                null
            );
            $valueAudit = ingredientOntologyV3MaterializedValueAudit(
                $db,
                array_merge(
                    $revisionForSetAudit ?? ['id' => $revisionId],
                    $valueHashes,
                    [
                        'recipe_count' => $scoreCount,
                        'requirement_revision_id' => null,
                    ]
                )
            );
            if (!$valueAudit['valid']) {
                throw new RuntimeException(
                    'shadow materialized value hashes are invalid'
                );
            }
            $report = [
                'shadow_only' => true,
                'activated' => false,
                'ontology_version_id' => $versionId,
                'recipe_count' => $scoreCount,
                'ingredient_match_count' => $matchCount,
                'inventory_revision' => $state['inventory_revision'],
                'catalog_revision' => $state['catalog_revision'],
                'catalog_fingerprint' => $catalogFingerprint,
                'inventory_fingerprint' => $fingerprint,
                'scoring_configuration' => array_merge(
                    $scoringConfiguration,
                    ['hash' => $scoringConfigHash]
                ),
                'ontology_hashes' => [
                    'schema' => $version['schema_hash'],
                    'prompt' => $version['prompt_hash'],
                    'model' => $version['model_hash'],
                    'corpus' => $version['corpus_hash'],
                    'content' => $version['content_hash'],
                ],
                'source_owner_fingerprints' => $ownerFingerprints,
                'ontology_source_revision' =>
                    $state['ontology_source_revision'],
                'ontology_source_hash' => $ontologySourceHash,
                'active_score_revision_id_before' => $parentRevisionId,
                'materialized_id_sets' => $idSetAudit,
                'materialized_values' => $valueAudit,
            ];
            $publicationGuardWasEnabled =
                ingredientOntologyV3PublicationGuardEnabled($db);
            ingredientOntologyV3SetPublicationGuard($db, true);
            try {
                $db->prepare("
                    UPDATE recipe_score_revisions SET
                        status = 'ready',
                        recipe_count = ?,
                        catalog_max_id = ?,
                        catalog_id_set_hash = ?,
                        ingredient_id_set_hash = ?,
                        requirement_recipe_id_set_hash = NULL,
                        requirement_id_set_hash = NULL,
                        score_rows_hash = ?,
                        match_rows_hash = ?,
                        materialization_hash = ?,
                        validation_report_json = ?,
                        completed_at = CURRENT_TIMESTAMP,
                        last_error = ''
                    WHERE id = ?
                ")->execute([
                    $scoreCount,
                    $catalogMaxId,
                    $idSetHashes['catalog_id_set_hash'],
                    $idSetHashes['ingredient_id_set_hash'],
                    $valueHashes['score_rows_hash'],
                    $valueHashes['match_rows_hash'],
                    $valueHashes['materialization_hash'],
                    ingredientOntologyV3Json($report),
                    $revisionId,
                ]);
                $db->prepare("
                    UPDATE recipe_score_state
                    SET ontology_source_hash = ?
                    WHERE id = 1
                      AND ontology_source_revision = ?
                ")->execute([
                    $ontologySourceHash,
                    $state['ontology_source_revision'],
                ]);
            } finally {
                ingredientOntologyV3SetPublicationGuard(
                    $db,
                    $publicationGuardWasEnabled
                );
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return [
            'built' => true,
            'revision_id' => $revisionId,
            'ontology_version_id' => $versionId,
            'recipe_count' => $catalogCount,
            'scoring_config_hash' => $scoringConfigHash,
            'active_score_revision_id' =>
                recipeScoreState($db)['active_score_revision_id'],
            'activated' => false,
        ];
    } catch (Throwable $e) {
        if ($revisionId > 0) {
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
        }
        throw $e;
    } finally {
        if (!$lockAlreadyHeld) {
            ingredientOntologyV3ReleaseLock($lock);
        }
    }
}

function ingredientOntologyV3ScheduledRebuild(
    PDO $db,
    bool $force = false,
    int $batchSize = 250
): array {
    $lock = recipeScoreAcquireLock($db);
    if ($lock === false) {
        return ['rebuilt' => false, 'reason' => 'locked'];
    }
    $legacyBranch = false;
    $unsupportedModel = false;
    try {
        if (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && is_callable(
                $GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_BEFORE_SCHEDULED_SELECTION'
                ] ?? null
            )
        ) {
            ($GLOBALS[
                'INGREDIENT_ONTOLOGY_V3_BEFORE_SCHEDULED_SELECTION'
            ])($db);
        }
        $active = recipeScoreActiveRevision($db);
        if ($active === null || $active['ontology_version_id'] === null) {
            $legacyBranch = true;
            return recipeScoreRebuild(
                $db,
                $force,
                $batchSize,
                true
            );
        }
        if (
            (string)($active['scoring_model'] ?? '')
                !== INGREDIENT_ONTOLOGY_V3_SCORING_MODEL
        ) {
            $unsupportedModel = true;
            throw new RuntimeException(
                'active ontology revision has an unsupported scoring model'
            );
        }
        if (
            function_exists('ingredientOntologyControllerDatabaseIsActive')
            && ingredientOntologyControllerDatabaseIsActive($db)
            && !(
                defined('RECIPE_BACKEND_TEST_MODE')
                && RECIPE_BACKEND_TEST_MODE
            )
        ) {
            return [
                'rebuilt' => false,
                'reason' => 'copied_activation_required',
                'revision_id' => (int)$active['id'],
                'ontology_version_id' =>
                    (int)$active['ontology_version_id'],
            ];
        }
        $state = recipeScoreState($db);
        $versionId = (int)$active['ontology_version_id'];
        $scoringConfigHash = ingredientOntologyV3ScoringConfigHash();
        $today = date('Y-m-d');
        $abandoned = $db->prepare("
            SELECT id, inventory_fingerprint, catalog_fingerprint,
                   scoring_config_hash
            FROM recipe_score_revisions
            WHERE scoring_model = 'faceted-ontology-v3'
              AND ontology_version_id = ?
              AND parent_score_revision_id = ?
              AND inventory_revision = ?
              AND catalog_revision = ?
              AND score_date = ?
              AND status = 'building'
            ORDER BY id DESC
            LIMIT 1
        ");
        $abandoned->execute([
            $versionId,
            (int)$active['id'],
            $state['inventory_revision'],
            $state['catalog_revision'],
            $today,
        ]);
        $abandonedRow = $abandoned->fetch(PDO::FETCH_ASSOC) ?: null;
        $abandonedBuildCount = recipeScoreFailAbandonedBuilds($db);
        $abandonedBuilding = false;
        $inventory = null;
        $inventoryFingerprint = null;
        $catalogFingerprint = null;
        $integrity = ingredientOntologyV3RevisionIntegrityAudit(
            $db,
            $active
        );
        if (!$integrity['valid']) {
            $result = [
                'rebuilt' => false,
                'reason' => 'ontology_stale',
                'errors' => $integrity['errors'],
                'ontology_version_id' => $versionId,
                'active_score_revision_id' =>
                    recipeScoreState($db)['active_score_revision_id'],
            ];
            if ($abandonedBuildCount > 0) {
                $cleanupWarning =
                    ingredientOntologyV3PostActivationCleanup($db);
                if ($cleanupWarning !== null) {
                    $result['cleanup_warning'] = $cleanupWarning;
                }
            }
            return $result;
        }
        if ($abandonedRow !== null) {
            $inventory = ingredientOntologyV3Inventory($db, $versionId);
            $inventoryFingerprint =
                ingredientOntologyV3InventoryFingerprint(
                    $inventory,
                    $versionId
                );
            $catalogFingerprint = recipeScoreCatalogFingerprint($db);
            $abandonedBuilding = hash_equals(
                $inventoryFingerprint,
                (string)$abandonedRow['inventory_fingerprint']
            ) && hash_equals(
                $catalogFingerprint,
                (string)$abandonedRow['catalog_fingerprint']
            ) && is_string($abandonedRow['scoring_config_hash'])
                && hash_equals(
                    $scoringConfigHash,
                    (string)$abandonedRow['scoring_config_hash']
                );
        }
        if (
            !$force
            && !$abandonedBuilding
            && recipeScoreRevisionStatus($db, $active) === 'fresh'
        ) {
            $result = [
                'rebuilt' => false,
                'reason' => 'fresh',
                'revision_id' => (int)$active['id'],
                'recipe_count' => (int)$active['recipe_count'],
                'scoring_model' => INGREDIENT_ONTOLOGY_V3_SCORING_MODEL,
            ];
            if ($abandonedBuildCount > 0) {
                $cleanupWarning =
                    ingredientOntologyV3PostActivationCleanup($db);
                if ($cleanupWarning !== null) {
                    $result['cleanup_warning'] = $cleanupWarning;
                }
            }
            return $result;
        }
        if ($inventory === null) {
            $inventory = ingredientOntologyV3Inventory($db, $versionId);
            $inventoryFingerprint =
                ingredientOntologyV3InventoryFingerprint(
                    $inventory,
                    $versionId
                );
            $catalogFingerprint = recipeScoreCatalogFingerprint($db);
        }
        $existingRow = null;
        if (!$abandonedBuilding) {
            $existing = $db->prepare("
                SELECT id, status, last_error
                FROM recipe_score_revisions
                WHERE scoring_model = 'faceted-ontology-v3'
                  AND ontology_version_id = ?
                  AND parent_score_revision_id = ?
                  AND inventory_revision = ?
                  AND catalog_revision = ?
                  AND inventory_fingerprint = ?
                  AND catalog_fingerprint = ?
                  AND scoring_config_hash = ?
                  AND score_date = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $existing->execute([
                $versionId,
                (int)$active['id'],
                $state['inventory_revision'],
                $state['catalog_revision'],
                $inventoryFingerprint,
                $catalogFingerprint,
                $scoringConfigHash,
                $today,
            ]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $existingId = (int)($existingRow['id'] ?? 0);
        if ($existingId > 0) {
            $existingStatus = (string)$existingRow['status'];
            if ($existingStatus === 'failed' && !$force) {
                return [
                    'rebuilt' => false,
                    'reason' => 'previous_failure',
                    'revision_id' => $existingId,
                    'error' => (string)$existingRow['last_error'],
                    'active_score_revision_id' =>
                        recipeScoreState($db)['active_score_revision_id'],
                ];
            }
            if ($existingStatus !== 'ready') {
                $existingId = 0;
            }
        }
        if ($existingId > 0) {
            $existingRevision = recipeScoreRevision($db, $existingId);
            if ($existingRevision === null) {
                throw new RuntimeException(
                    'reusable score revision disappeared before activation'
                );
            }
            $existingRecipeCount = (int)$existingRevision['recipe_count'];
            $activated = $existingId === (int)$active['id'];
            if ($existingId !== (int)$active['id']) {
                $validation = ingredientOntologyV3ValidateActivation(
                    $db,
                    $existingId
                );
                if (!$validation['valid']) {
                    return [
                        'rebuilt' => false,
                        'reason' => 'validation_failed',
                        'revision_id' => $existingId,
                        'errors' => $validation['errors'],
                        'active_score_revision_id' =>
                            recipeScoreState($db)['active_score_revision_id'],
                    ];
                }
                $activation = ingredientOntologyV3Activate($db, $existingId);
                $activated = !empty($activation['activated']);
            }
            $result = [
                'rebuilt' => false,
                'reason' => $existingId === (int)$active['id']
                    ? 'fresh'
                    : 'reused',
                'revision_id' => $existingId,
                'recipe_count' => $existingRecipeCount,
                'activated' => $activated,
                'scoring_model' => 'faceted-ontology-v3',
            ];
            $cleanupWarning = ingredientOntologyV3PostActivationCleanup($db);
            if ($cleanupWarning !== null) {
                $result['cleanup_warning'] = $cleanupWarning;
            }
            return $result;
        }
        if (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && is_callable(
                $GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_BEFORE_SCHEDULED_SHADOW_BUILD'
                ] ?? null
            )
        ) {
            ($GLOBALS[
                'INGREDIENT_ONTOLOGY_V3_BEFORE_SCHEDULED_SHADOW_BUILD'
            ])($db, $active, $versionId);
        }
        $built = ingredientOntologyV3BuildShadow(
            $db,
            $versionId,
            $batchSize,
            null,
            true,
            [
                'revision_id' => (int)$active['id'],
                'ontology_version_id' => $versionId,
            ]
        );
        if (empty($built['built'])) {
            return [
                'rebuilt' => false,
                'reason' => $built['reason'] ?? 'build_skipped',
            ];
        }
        $revisionId = (int)$built['revision_id'];
        $validation = ingredientOntologyV3ValidateActivation($db, $revisionId);
        if (!$validation['valid']) {
            return [
                'rebuilt' => false,
                'reason' => 'validation_failed',
                'revision_id' => $revisionId,
                'errors' => $validation['errors'],
                'active_score_revision_id' =>
                    recipeScoreState($db)['active_score_revision_id'],
            ];
        }
        $activation = ingredientOntologyV3Activate($db, $revisionId);
        $result = [
            'rebuilt' => true,
            'revision_id' => $revisionId,
            'ontology_version_id' => $versionId,
            'recipe_count' => (int)$built['recipe_count'],
            'activated' => !empty($activation['activated']),
            'scoring_model' => 'faceted-ontology-v3',
        ];
        $cleanupWarning = ingredientOntologyV3PostActivationCleanup($db);
        if ($cleanupWarning !== null) {
            $result['cleanup_warning'] = $cleanupWarning;
        }
        return $result;
    } catch (Throwable $e) {
        if ($legacyBranch || $unsupportedModel) {
            throw $e;
        }
        return [
            'rebuilt' => false,
            'reason' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 1000, 'UTF-8'),
            'active_score_revision_id' =>
                recipeScoreState($db)['active_score_revision_id'],
        ];
    } finally {
        recipeScoreReleaseLock($lock);
    }
}

function ingredientOntologyV3PostActivationCleanup(PDO $db): ?string {
    try {
        if (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && is_callable(
                $GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_BEFORE_PRUNE_CLEANUP'
                ] ?? null
            )
        ) {
            ($GLOBALS['INGREDIENT_ONTOLOGY_V3_BEFORE_PRUNE_CLEANUP'])($db);
        }
        recipeScorePruneRevisions($db);
        return null;
    } catch (Throwable $e) {
        return mb_substr($e->getMessage(), 0, 500, 'UTF-8');
    }
}

function ingredientOntologyV3ShadowRevision(
    PDO $db,
    int $revisionId
): ?array {
    $revision = recipeScoreRevision($db, $revisionId);
    if (
        $revision === null
        || (string)($revision['scoring_model'] ?? '') !== 'faceted-ontology-v3'
        || $revision['ontology_version_id'] === null
    ) {
        return null;
    }
    return $revision;
}

function ingredientOntologyV3OrderedIdSetHash(
        PDO $db,
        string $sql,
        array $params = []
    ): string {
        $hash = hash_init('sha256');
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        while ($id = $stmt->fetchColumn()) {
            hash_update($hash, (string)(int)$id . "\n");
        }
        return hash_final($hash);
    }

function ingredientOntologyV3MaterializedIdSetAudit(
        PDO $db,
        array $revision
    ): array {
        $revisionId = (int)$revision['id'];
        $requirementRevisionId =
            $revision['requirement_revision_id'] !== null
                ? (int)$revision['requirement_revision_id']
                : null;
        $countExcept = static function (
            PDO $db,
            string $left,
            string $right,
            array $params
        ): int {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM ({$left} EXCEPT {$right})"
            );
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        };
        $catalogExpected = "
            SELECT id
            FROM recipe_catalog
            WHERE deleted_at IS NULL
        ";
        $catalogActual = "
            SELECT recipe_id
            FROM recipe_inventory_scores
            WHERE score_revision_id = ?
        ";
        $ingredientExpected = "
            SELECT ingredient.id
            FROM recipe_ingredients ingredient
            JOIN recipe_catalog recipe
              ON recipe.id = ingredient.recipe_id
            WHERE recipe.deleted_at IS NULL
        ";
        $ingredientActual = "
            SELECT recipe_ingredient_id
            FROM ingredient_ontology_shadow_matches
            WHERE score_revision_id = ?
        ";
        $result = [
            'catalog_missing' => $countExcept(
                $db,
                $catalogExpected,
                $catalogActual,
                [$revisionId]
            ),
            'catalog_extra' => $countExcept(
                $db,
                $catalogActual,
                $catalogExpected,
                [$revisionId]
            ),
            'ingredient_missing' => 0,
            'ingredient_extra' => 0,
            'requirement_recipe_missing' => 0,
            'requirement_recipe_extra' => 0,
            'requirement_missing' => 0,
            'requirement_extra' => 0,
        ];
        if ($requirementRevisionId === null) {
            $result['ingredient_missing'] = $countExcept(
                $db,
                $ingredientExpected,
                $ingredientActual,
                [$revisionId]
            );
            $result['ingredient_extra'] = $countExcept(
                $db,
                $ingredientActual,
                $ingredientExpected,
                [$revisionId]
            );
        } else {
            $requirementRecipeExpected = "
                SELECT recipe_id
                FROM ingredient_ontology_requirement_recipe_states
                WHERE requirement_revision_id = ?
            ";
            $requirementExpected = "
                SELECT id
                FROM ingredient_ontology_recipe_requirements
                WHERE requirement_revision_id = ?
            ";
            $requirementActual = "
                SELECT requirement_id
                FROM ingredient_ontology_shadow_requirement_matches
                WHERE score_revision_id = ?
                  AND requirement_revision_id = ?
            ";
            $result['requirement_recipe_missing'] = $countExcept(
                $db,
                $requirementRecipeExpected,
                $catalogActual,
                [$requirementRevisionId, $revisionId]
            );
            $result['requirement_recipe_extra'] = $countExcept(
                $db,
                $catalogActual,
                $requirementRecipeExpected,
                [$revisionId, $requirementRevisionId]
            );
            $result['requirement_missing'] = $countExcept(
                $db,
                $requirementExpected,
                $requirementActual,
                [
                    $requirementRevisionId,
                    $revisionId,
                    $requirementRevisionId,
                ]
            );
            $result['requirement_extra'] = $countExcept(
                $db,
                $requirementActual,
                $requirementExpected,
                [
                    $revisionId,
                    $requirementRevisionId,
                    $requirementRevisionId,
                ]
            );
        }
        $currentHashes = [
            'catalog_id_set_hash' =>
                ingredientOntologyV3OrderedIdSetHash(
                    $db,
                    $catalogExpected . ' ORDER BY id'
                ),
            'ingredient_id_set_hash' => $requirementRevisionId === null
                ? ingredientOntologyV3OrderedIdSetHash(
                    $db,
                    $ingredientExpected . ' ORDER BY ingredient.id'
                )
                : null,
            'requirement_recipe_id_set_hash' =>
                $requirementRevisionId !== null
                    ? ingredientOntologyV3OrderedIdSetHash(
                        $db,
                        "SELECT recipe_id
                         FROM ingredient_ontology_requirement_recipe_states
                         WHERE requirement_revision_id = ?
                         ORDER BY recipe_id",
                        [$requirementRevisionId]
                    )
                    : null,
            'requirement_id_set_hash' =>
                $requirementRevisionId !== null
                    ? ingredientOntologyV3OrderedIdSetHash(
                        $db,
                        "SELECT id
                         FROM ingredient_ontology_recipe_requirements
                         WHERE requirement_revision_id = ?
                         ORDER BY id",
                        [$requirementRevisionId]
                    )
                    : null,
        ];
        $hashMatches = [];
        foreach ($currentHashes as $column => $current) {
            $stored = $revision[$column] ?? null;
            $hashMatches[$column] = $current === null
                ? $stored === null
                : is_string($stored) && hash_equals($stored, $current);
        }
        $result['current_hashes'] = $currentHashes;
        $result['hash_matches'] = $hashMatches;
        $result['valid'] = array_sum(array_filter(
            $result,
            static fn(mixed $value, string $key): bool =>
                str_ends_with($key, '_missing')
                || str_ends_with($key, '_extra'),
            ARRAY_FILTER_USE_BOTH
        )) === 0
            && !in_array(false, $hashMatches, true);
        return $result;
    }

function ingredientOntologyV3MaterializedIdSetHashes(
        PDO $db,
        int $revisionId,
        ?int $requirementRevisionId
    ): array {
        return [
            'catalog_id_set_hash' =>
                ingredientOntologyV3OrderedIdSetHash(
                    $db,
                    "SELECT id FROM recipe_catalog
                     WHERE deleted_at IS NULL ORDER BY id"
                ),
            'ingredient_id_set_hash' => $requirementRevisionId === null
                ? ingredientOntologyV3OrderedIdSetHash(
                    $db,
                    "SELECT ingredient.id
                     FROM recipe_ingredients ingredient
                     JOIN recipe_catalog recipe
                       ON recipe.id = ingredient.recipe_id
                     WHERE recipe.deleted_at IS NULL
                     ORDER BY ingredient.id"
                )
                : null,
            'requirement_recipe_id_set_hash' =>
                $requirementRevisionId !== null
                    ? ingredientOntologyV3OrderedIdSetHash(
                        $db,
                        "SELECT recipe_id
                         FROM ingredient_ontology_requirement_recipe_states
                         WHERE requirement_revision_id = ?
                         ORDER BY recipe_id",
                        [$requirementRevisionId]
                    )
                    : null,
            'requirement_id_set_hash' =>
                $requirementRevisionId !== null
                    ? ingredientOntologyV3OrderedIdSetHash(
                        $db,
                        "SELECT id
                         FROM ingredient_ontology_recipe_requirements
                         WHERE requirement_revision_id = ?
                         ORDER BY id",
                        [$requirementRevisionId]
                    )
                    : null,
        ];
}

function ingredientOntologyV3MaterializationDecimal(mixed $value): string {
    return number_format((float)$value, 6, '.', '');
}

function ingredientOntologyV3MaterializationJson(string $json): array {
    $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException(
            'materialized JSON value is not canonicalizable'
        );
    }
    return ingredientOntologyV3StableValue($value);
}

function ingredientOntologyV3HashMaterializedRows(
    PDO $db,
    string $sql,
    array $params,
    callable $normalize
): array {
    $hash = hash_init('sha256');
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        hash_update(
            $hash,
            ingredientOntologyV3Json($normalize($row)) . "\n"
        );
        $count++;
    }
    return ['hash' => hash_final($hash), 'count' => $count];
}

function ingredientOntologyV3MaterializedValueHashes(
    PDO $db,
    int $revisionId,
    ?int $requirementRevisionId
): array {
    $scores = ingredientOntologyV3HashMaterializedRows(
        $db,
        "SELECT recipe_id, coverage, directness, expiry_score,
                source_user_score, availability_score, required_count,
                matched_required_count, missing_required_count,
                uncertain_required_count, cookable, soonest_expiry_days
         FROM recipe_inventory_scores
         WHERE score_revision_id = ?
         ORDER BY recipe_id",
        [$revisionId],
        static fn(array $row): array => [
            'recipe_id' => (int)$row['recipe_id'],
            'coverage' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['coverage']
                ),
            'directness' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['directness']
                ),
            'expiry_score' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['expiry_score']
                ),
            'source_user_score' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['source_user_score']
                ),
            'availability_score' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['availability_score']
                ),
            'required_count' => (int)$row['required_count'],
            'matched_required_count' =>
                (int)$row['matched_required_count'],
            'missing_required_count' =>
                (int)$row['missing_required_count'],
            'uncertain_required_count' =>
                (int)$row['uncertain_required_count'],
            'cookable' => (int)$row['cookable'],
            'soonest_expiry_days' =>
                $row['soonest_expiry_days'] !== null
                    ? (int)$row['soonest_expiry_days']
                    : null,
        ]
    );
    if ($requirementRevisionId === null) {
        $matches = ingredientOntologyV3HashMaterializedRows(
            $db,
            "SELECT recipe_ingredient_id, recipe_mapping_id,
                    inventory_product_id, inventory_mapping_id,
                    outcome, satisfies_required, confidence,
                    relationship, explanation_json
             FROM ingredient_ontology_shadow_matches
             WHERE score_revision_id = ?
             ORDER BY recipe_ingredient_id",
            [$revisionId],
            static fn(array $row): array => [
                'recipe_ingredient_id' =>
                    (int)$row['recipe_ingredient_id'],
                'recipe_mapping_id' =>
                    $row['recipe_mapping_id'] !== null
                        ? (int)$row['recipe_mapping_id']
                        : null,
                'inventory_product_id' =>
                    $row['inventory_product_id'] !== null
                        ? (int)$row['inventory_product_id']
                        : null,
                'inventory_mapping_id' =>
                    $row['inventory_mapping_id'] !== null
                        ? (int)$row['inventory_mapping_id']
                        : null,
                'outcome' => (string)$row['outcome'],
                'satisfies_required' =>
                    (int)$row['satisfies_required'],
                'confidence' =>
                    ingredientOntologyV3MaterializationDecimal(
                        $row['confidence']
                    ),
                'relationship' => (string)$row['relationship'],
                'explanation' =>
                    ingredientOntologyV3MaterializationJson(
                        (string)$row['explanation_json']
                    ),
            ]
        );
    } else {
        $matches = ingredientOntologyV3HashMaterializedRows(
            $db,
            "SELECT requirement.requirement_key,
                    match.inventory_product_id,
                    match.inventory_mapping_id,
                    match.outcome, match.satisfies_required,
                    match.confidence, match.relationship,
                    match.explanation_json
             FROM ingredient_ontology_shadow_requirement_matches match
             JOIN ingredient_ontology_recipe_requirements requirement
               ON requirement.id = match.requirement_id
             WHERE match.score_revision_id = ?
               AND match.requirement_revision_id = ?
             ORDER BY requirement.requirement_key",
            [$revisionId, $requirementRevisionId],
            static fn(array $row): array => [
                'requirement_key' => (string)$row['requirement_key'],
                'inventory_product_id' =>
                    $row['inventory_product_id'] !== null
                        ? (int)$row['inventory_product_id']
                        : null,
                'inventory_mapping_id' =>
                    $row['inventory_mapping_id'] !== null
                        ? (int)$row['inventory_mapping_id']
                        : null,
                'outcome' => (string)$row['outcome'],
                'satisfies_required' =>
                    (int)$row['satisfies_required'],
                'confidence' =>
                    ingredientOntologyV3MaterializationDecimal(
                        $row['confidence']
                    ),
                'relationship' => (string)$row['relationship'],
                'explanation' =>
                    ingredientOntologyV3MaterializationJson(
                        (string)$row['explanation_json']
                    ),
            ]
        );
    }
    return [
        'score_rows_hash' => $scores['hash'],
        'match_rows_hash' => $matches['hash'],
        'materialization_hash' => ingredientOntologyV3Hash([
            'score_rows_hash' => $scores['hash'],
            'score_row_count' => $scores['count'],
            'match_rows_hash' => $matches['hash'],
            'match_row_count' => $matches['count'],
            'requirement_revision_id' => $requirementRevisionId,
        ]),
        'score_row_count' => $scores['count'],
        'match_row_count' => $matches['count'],
    ];
}

function ingredientOntologyV3MaterializedValueAudit(
    PDO $db,
    array $revision
): array {
    $report = json_decode(
        (string)($revision['validation_report_json'] ?? '{}'),
        true
    );
    if (
        is_array($report)
        && (string)($report['materialized_hash_algorithm'] ?? '')
            === 'parent-delta-v1'
        && function_exists(
            'ingredientOntologyV3IncrementalValueAudit'
        )
    ) {
        return ingredientOntologyV3IncrementalValueAudit(
            $db,
            $revision
        );
    }
    $requirementRevisionId =
        $revision['requirement_revision_id'] !== null
            ? (int)$revision['requirement_revision_id']
            : null;
    $current = ingredientOntologyV3MaterializedValueHashes(
        $db,
        (int)$revision['id'],
        $requirementRevisionId
    );
    $matches = [];
    foreach ([
        'score_rows_hash',
        'match_rows_hash',
        'materialization_hash',
    ] as $column) {
        $matches[$column] = is_string($revision[$column] ?? null)
            && hash_equals(
                (string)$revision[$column],
                (string)$current[$column]
            );
    }
    return [
        'valid' => !in_array(false, $matches, true)
            && (int)$revision['recipe_count']
                === $current['score_row_count'],
        'current' => $current,
        'hash_matches' => $matches,
    ];
}

function ingredientOntologyV3RequirementMaterializationHashes(
    PDO $db,
    int $requirementRevisionId
): array {
    $requirements = ingredientOntologyV3HashMaterializedRows(
        $db,
        "SELECT requirement.requirement_key, requirement.recipe_id,
                requirement.basis, entity.slug AS entity_slug,
                requirement.mapping_status, requirement.mapping_source,
                requirement.confidence, requirement.identity_basis,
                requirement.attributes_json,
                requirement.defining_signature,
                requirement.requiredness, requirement.is_staple,
                requirement.contributor_count,
                requirement.provider_ref_count,
                requirement.quantity_audit_state,
                requirement.evidence_json
         FROM ingredient_ontology_recipe_requirements requirement
         LEFT JOIN ingredient_ontology_entities entity
           ON entity.id = requirement.entity_id
         WHERE requirement.requirement_revision_id = ?
         ORDER BY requirement.requirement_key",
        [$requirementRevisionId],
        static fn(array $row): array => [
            'requirement_key' => (string)$row['requirement_key'],
            'recipe_id' => (int)$row['recipe_id'],
            'basis' => (string)$row['basis'],
            'entity_slug' => $row['entity_slug'] !== null
                ? (string)$row['entity_slug']
                : null,
            'mapping_status' => (string)$row['mapping_status'],
            'mapping_source' => (string)$row['mapping_source'],
            'confidence' =>
                ingredientOntologyV3MaterializationDecimal(
                    $row['confidence']
                ),
            'identity_basis' => (string)$row['identity_basis'],
            'attributes' => ingredientOntologyV3MaterializationJson(
                (string)$row['attributes_json']
            ),
            'defining_signature' =>
                (string)$row['defining_signature'],
            'requiredness' => (string)$row['requiredness'],
            'is_staple' => (int)$row['is_staple'],
            'contributor_count' => (int)$row['contributor_count'],
            'provider_ref_count' =>
                (int)$row['provider_ref_count'],
            'quantity_audit_state' =>
                (string)$row['quantity_audit_state'],
            'evidence' => ingredientOntologyV3MaterializationJson(
                (string)$row['evidence_json']
            ),
        ]
    );
    $members = ingredientOntologyV3HashMaterializedRows(
        $db,
        "SELECT requirement.requirement_key, member.owner_type,
                member.owner_fingerprint, member.source_position,
                member.group_index, member.group_position,
                member.provider_ref, member.default_title,
                member.title_hash, member.source_label,
                member.source_label_hash, member.source_optional,
                member.source_quantity, member.source_quantity_max,
                member.source_unit, member.source_amount_text,
                member.quantity_state, member.evidence_json
         FROM ingredient_ontology_requirement_members member
         JOIN ingredient_ontology_recipe_requirements requirement
           ON requirement.id = member.requirement_id
         WHERE member.requirement_revision_id = ?
         ORDER BY member.owner_type, member.owner_fingerprint",
        [$requirementRevisionId],
        static fn(array $row): array => [
            'requirement_key' => (string)$row['requirement_key'],
            'owner_type' => (string)$row['owner_type'],
            'owner_fingerprint' =>
                (string)$row['owner_fingerprint'],
            'source_position' => (int)$row['source_position'],
            'group_index' => $row['group_index'] !== null
                ? (int)$row['group_index']
                : null,
            'group_position' => $row['group_position'] !== null
                ? (int)$row['group_position']
                : null,
            'provider_ref' => $row['provider_ref'],
            'default_title' => $row['default_title'],
            'title_hash' => $row['title_hash'],
            'source_label' => (string)$row['source_label'],
            'source_label_hash' => (string)$row['source_label_hash'],
            'source_optional' => $row['source_optional'] !== null
                ? (int)$row['source_optional']
                : null,
            'source_quantity' => $row['source_quantity'] !== null
                ? ingredientOntologyV3MaterializationDecimal(
                    $row['source_quantity']
                )
                : null,
            'source_quantity_max' => $row['source_quantity_max'] !== null
                ? ingredientOntologyV3MaterializationDecimal(
                    $row['source_quantity_max']
                )
                : null,
            'source_unit' => $row['source_unit'],
            'source_amount_text' => $row['source_amount_text'],
            'quantity_state' => (string)$row['quantity_state'],
            'evidence' => ingredientOntologyV3MaterializationJson(
                (string)$row['evidence_json']
            ),
        ]
    );
    $states = ingredientOntologyV3HashMaterializedRows(
        $db,
        "SELECT recipe_id, basis, complete, source_row_count,
                projected_member_count, projected_requirement_count,
                recipe_fingerprint, evidence_json
         FROM ingredient_ontology_requirement_recipe_states
         WHERE requirement_revision_id = ?
         ORDER BY recipe_id",
        [$requirementRevisionId],
        static fn(array $row): array => [
            'recipe_id' => (int)$row['recipe_id'],
            'basis' => (string)$row['basis'],
            'complete' => (int)$row['complete'],
            'source_row_count' => (int)$row['source_row_count'],
            'projected_member_count' =>
                (int)$row['projected_member_count'],
            'projected_requirement_count' =>
                (int)$row['projected_requirement_count'],
            'recipe_fingerprint' =>
                (string)$row['recipe_fingerprint'],
            'evidence' => ingredientOntologyV3MaterializationJson(
                (string)$row['evidence_json']
            ),
        ]
    );
    return [
        'requirement_rows_hash' => $requirements['hash'],
        'requirement_member_rows_hash' => $members['hash'],
        'requirement_recipe_state_rows_hash' => $states['hash'],
        'materialization_hash' => ingredientOntologyV3Hash([
            'requirement_rows_hash' => $requirements['hash'],
            'requirement_count' => $requirements['count'],
            'requirement_member_rows_hash' => $members['hash'],
            'member_count' => $members['count'],
            'requirement_recipe_state_rows_hash' => $states['hash'],
            'recipe_state_count' => $states['count'],
        ]),
        'requirement_count' => $requirements['count'],
        'member_count' => $members['count'],
        'recipe_state_count' => $states['count'],
    ];
}

function ingredientOntologyV3RequirementMaterializationAudit(
    PDO $db,
    array $revision
): array {
    $current = ingredientOntologyV3RequirementMaterializationHashes(
        $db,
        (int)$revision['id']
    );
    $matches = [];
    foreach ([
        'requirement_rows_hash',
        'requirement_member_rows_hash',
        'requirement_recipe_state_rows_hash',
        'materialization_hash',
    ] as $column) {
        $matches[$column] = is_string($revision[$column] ?? null)
            && hash_equals(
                (string)$revision[$column],
                (string)$current[$column]
            );
    }
    return [
        'valid' => !in_array(false, $matches, true)
            && (int)$revision['requirement_count']
                === $current['requirement_count']
            && (int)$revision['member_count']
                === $current['member_count']
            && (int)$revision['recipe_count']
                === $current['recipe_state_count'],
        'current' => $current,
        'hash_matches' => $matches,
    ];
}

function ingredientOntologyV3ActivationSnapshot(
    PDO $db,
    int $revisionId
): array {
    $revision = ingredientOntologyV3ShadowRevision($db, $revisionId);
    if ($revision === null) {
        throw new InvalidArgumentException('v3 score revision not found');
    }
    $versionId = (int)$revision['ontology_version_id'];
    $version = ingredientOntologyV3Version($db, $versionId);
    if ($version === null) {
        throw new InvalidArgumentException('ontology version not found');
    }
    $state = recipeScoreState($db);
    $catalogCount = (int)$db->query("
        SELECT COUNT(*) FROM recipe_catalog WHERE deleted_at IS NULL
    ")->fetchColumn();
    $ingredientCount = (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_ingredients ri
        JOIN recipe_catalog c ON c.id = ri.recipe_id
        WHERE c.deleted_at IS NULL
    ")->fetchColumn();
    $scoreCountStmt = $db->prepare("
        SELECT COUNT(*) FROM recipe_inventory_scores
        WHERE score_revision_id = ?
    ");
    $scoreCountStmt->execute([$revisionId]);
    $matchCountStmt = $db->prepare("
        SELECT COUNT(*) FROM ingredient_ontology_shadow_matches
        WHERE score_revision_id = ?
    ");
    $matchCountStmt->execute([$revisionId]);
    $inventory = ingredientOntologyV3Inventory($db, $versionId);
    $pending = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_change_sets
        WHERE ontology_version_id = ?
          AND review_state IN ('pending', 'approved')
    ");
    $pending->execute([$versionId]);
    $invalid = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_change_sets
        WHERE ontology_version_id = ?
          AND review_state IN ('pending', 'approved', 'applied')
          AND (
              json_valid(validator_result_json) = 0
              OR COALESCE(
                  json_extract(validator_result_json, '$.valid'),
                  0
              ) = 0
          )
    ");
    $invalid->execute([$versionId]);
    $parentRevisionId = (int)(
        $revision['parent_score_revision_id'] ?? 0
    );
    $parentRevision = $parentRevisionId > 0
        ? recipeScoreRevision($db, $parentRevisionId)
        : null;
    return [
        'revision' => $revision,
        'version' => $version,
        'state' => $state,
        'score_date' => date('Y-m-d'),
        'catalog_count' => $catalogCount,
        'catalog_max_id' => recipeScoreCatalogMaxId($db),
        'catalog_fingerprint' => recipeScoreCatalogFingerprint($db),
        'ingredient_count' => $ingredientCount,
        'score_count' => (int)$scoreCountStmt->fetchColumn(),
        'match_count' => (int)$matchCountStmt->fetchColumn(),
        'scoring_configuration' =>
            ingredientOntologyV3ScoringConfigAudit($revision),
        'inventory_fingerprint' =>
            ingredientOntologyV3InventoryFingerprint($inventory, $versionId),
        'corpus_hash' => ingredientOntologyV3CorpusHash($db),
        'content_hash' => ingredientOntologyV3ContentHash($db, $versionId),
        'owner_fingerprints' =>
            ingredientOntologyV3OwnerFingerprintAudit($db, $versionId),
        'pending_change_sets' => (int)$pending->fetchColumn(),
        'invalid_change_sets' => (int)$invalid->fetchColumn(),
        'resolution_gold' =>
            ingredientOntologyV3EvaluateResolutionGold(
                $db,
                $versionId,
                true
            ),
        'matcher_gold' =>
            ingredientOntologyV3EvaluateGold(
                $db,
                $versionId
            ),
        'materialized_id_sets' =>
            ingredientOntologyV3MaterializedIdSetAudit(
                $db,
                $revision
            ),
        'materialized_values' =>
            ingredientOntologyV3MaterializedValueAudit(
                $db,
                $revision
            ),
        'version_integrity' =>
            ingredientOntologyV3RevisionIntegrityAudit(
                $db,
                $revision
            ),
        'parent_revision' => $parentRevision,
        'parent_materialization_errors' => $parentRevision === null
            ? ['rollback baseline is missing']
            : ingredientOntologyV3RetainedMaterializationErrors(
                $db,
                $parentRevision
            ),
    ];
}

function ingredientOntologyV3ActivationErrors(array $snapshot): array {
    $revision = $snapshot['revision'];
    $version = $snapshot['version'];
    $state = $snapshot['state'];
    $errors = [];
    if ($revision['status'] !== 'ready') {
        $errors[] = 'shadow revision is not ready';
    }
    if ($version['status'] !== 'ready') {
        $errors[] = 'ontology version is not ready';
    }
    if (empty($snapshot['resolution_gold']['valid'])) {
        $errors[] = 'adjudicated resolution gold failed';
    }
    if (empty($snapshot['matcher_gold']['valid'])) {
        $errors[] = 'pinned matcher gold failed';
    }
    if (empty($snapshot['version_integrity']['valid'])) {
        $errors[] = 'ontology revision integrity failed';
    }
    if (empty($snapshot['materialized_id_sets']['valid'])) {
        $errors[] = 'materialized ID sets are not equal';
    }
    if (empty($snapshot['materialized_values']['valid'])) {
        $errors[] = 'materialized score or match values changed';
    }
    $activationPolicy = (string)(
        $version['activation_policy'] ?? 'blocked'
    );
    $testMode = defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE;
    if (!in_array(
        $activationPolicy,
        ['manual_review', 'test_only'],
        true
    )) {
        $errors[] = (string)(
            $version['activation_block_reason']
                ?? 'ontology activation is policy-blocked'
        );
    } elseif ($activationPolicy === 'test_only' && !$testMode) {
        $errors[] = 'test-only ontology activation requires test mode';
    } elseif (
        $activationPolicy === 'manual_review'
        && $snapshot['parent_materialization_errors'] !== []
    ) {
        $errors[] = 'rollback baseline validation failed: '
            . implode(
                '; ',
                $snapshot['parent_materialization_errors']
            );
    }
    if (
        (int)$revision['inventory_revision'] !== $state['inventory_revision']
        || (int)$revision['catalog_revision'] !== $state['catalog_revision']
        || (int)($revision['ontology_source_revision'] ?? -1)
            !== (int)($state['ontology_source_revision'] ?? -2)
        || !hash_equals(
            (string)($revision['ontology_source_hash'] ?? ''),
            (string)($state['ontology_source_hash'] ?? '')
        )
        || !hash_equals(
            (string)($revision['ontology_source_hash'] ?? ''),
            (string)$snapshot['corpus_hash']
        )
        || !hash_equals(
            (string)$revision['inventory_fingerprint'],
            (string)$snapshot['inventory_fingerprint']
        )
        || (int)$revision['catalog_max_id']
            !== (int)$snapshot['catalog_max_id']
        || !hash_equals(
            (string)$revision['catalog_fingerprint'],
            (string)$snapshot['catalog_fingerprint']
        )
    ) {
        $errors[] = 'inventory or catalog inputs changed after shadow build';
    }
    $expectedActiveId = $revision['parent_score_revision_id'];
    if (
        ($expectedActiveId === null)
            !== ($state['active_score_revision_id'] === null)
        || (
            $expectedActiveId !== null
            && (int)$expectedActiveId
                !== (int)$state['active_score_revision_id']
        )
    ) {
        $errors[] = 'active score pointer changed after shadow build';
    }
    if (
        (int)$revision['recipe_count'] !== (int)$snapshot['catalog_count']
        || (int)$snapshot['score_count'] !== (int)$snapshot['catalog_count']
        || (int)$snapshot['match_count'] !== (int)$snapshot['ingredient_count']
    ) {
        $errors[] = 'shadow materialization is incomplete';
    }
    if (
        (string)$revision['score_date'] !== (string)$snapshot['score_date']
    ) {
        $errors[] = 'shadow score date is not current';
    }
    if (!$snapshot['scoring_configuration']['valid']) {
        $errors[] = 'shadow scoring configuration changed or is invalid';
    }
    $hashes = [
        'schema' => [
            $revision['ontology_schema_hash'],
            $version['schema_hash'],
            ingredientOntologyV3SchemaHash(),
        ],
        'prompt' => [
            $revision['ontology_prompt_hash'],
            $version['prompt_hash'],
            ingredientOntologyV3PromptHash(),
        ],
        'model' => [
            $revision['ontology_model_hash'],
            $version['model_hash'],
            ingredientOntologyV3ModelHash((string)$version['model_name']),
        ],
        'corpus' => [
            $revision['ontology_corpus_hash'],
            $version['corpus_hash'],
            $snapshot['corpus_hash'],
        ],
        'content' => [
            $revision['ontology_content_hash'],
            $version['content_hash'],
            $snapshot['content_hash'],
        ],
    ];
    foreach ($hashes as $name => [$revisionHash, $versionHash, $currentHash]) {
        if (
            !is_string($revisionHash)
            || !hash_equals($versionHash, $revisionHash)
            || !hash_equals($versionHash, $currentHash)
        ) {
            $errors[] = "ontology {$name} hash changed";
        }
    }
    if (!$snapshot['owner_fingerprints']['valid']) {
        $errors[] = 'source owner fingerprints changed after candidate build';
    }
    if ((int)$snapshot['pending_change_sets'] !== 0) {
        $errors[] = 'change sets remain pending or approved-but-unapplied';
    }
    if ((int)$snapshot['invalid_change_sets'] !== 0) {
        $errors[] = 'applicable change sets contain validator violations';
    }
    return $errors;
}

function ingredientOntologyV3RecipeExplanation(
    PDO $db,
    int $revisionId,
    int $recipeId
): array {
    $revision = ingredientOntologyV3ShadowRevision($db, $revisionId);
    $overlay = $revision !== null
        && (string)$revision['status'] === 'building'
        && function_exists('recipeScoreActiveOverlay')
            ? recipeScoreActiveOverlay($db)
            : null;
    $overlayRecipe = false;
    if ($overlay !== null && (int)$overlay['id'] === $revisionId) {
        $affected = $db->prepare("
            SELECT 1
            FROM recipe_score_incremental_recipes
            WHERE score_revision_id = ? AND recipe_id = ?
        ");
        $affected->execute([$revisionId, $recipeId]);
        $overlayRecipe = (bool)$affected->fetchColumn();
    }
    if (
        $revision === null
        || (
            $revision['status'] !== 'ready'
            && !$overlayRecipe
        )
    ) {
        throw new InvalidArgumentException('v3 score revision is unavailable');
    }
    $stmt = $db->prepare("
        SELECT ri.position, ri.normalized_name, sm.outcome,
               ri.is_required, ri.is_optional, ri.is_staple,
               ri.source_is_required, ri.source_is_optional,
               ri.requiredness_source,
               sm.satisfies_required, sm.confidence, sm.relationship,
               sm.explanation_json
        FROM recipe_ingredients ri
        JOIN ingredient_ontology_shadow_matches sm
          ON sm.recipe_ingredient_id = ri.id
         AND sm.score_revision_id = ?
        WHERE ri.recipe_id = ?
        ORDER BY ri.position
    ");
    $stmt->execute([$revisionId, $recipeId]);
    $matches = [];
    $missing = [];
    $uncertain = [];
    $optionalUnmatched = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $explanation = json_decode(
            (string)$row['explanation_json'],
            true
        );
        if (!is_array($explanation)) {
            $explanation = [];
        }
        $storedRequirement = is_array($explanation['requirement'] ?? null)
            ? $explanation['requirement']
            : [];
        $isStaple = !empty($storedRequirement['staple'])
            || (string)$row['outcome'] === 'staple';
        $isOptional = array_key_exists('optional', $storedRequirement)
            ? !empty($storedRequirement['optional'])
            : (
                $row['source_is_optional'] !== null
                    ? !empty($row['source_is_optional'])
                    : !empty($row['is_optional'])
            );
        $isRequired = array_key_exists('required', $storedRequirement)
            ? !empty($storedRequirement['required'])
            : (
                (
                    $row['source_is_required'] !== null
                        ? !empty($row['source_is_required'])
                        : (
                            !empty($row['is_required'])
                            || !empty($row['is_staple'])
                        )
                )
                && !$isOptional
                && !$isStaple
            );
        $item = [
            'position' => (int)$row['position'],
            'ingredient' => (string)$row['normalized_name'],
            'outcome' => (string)$row['outcome'],
            'matched' => !empty($row['satisfies_required']),
            'confidence' => (float)$row['confidence'],
            'relationship' => (string)$row['relationship'],
            'required' => $isRequired,
            'optional' => $isOptional,
            'staple' => $isStaple,
            'detail' => $explanation,
        ];
        $matches[] = $item;
        if (!empty($row['satisfies_required']) || $row['outcome'] === 'staple') {
            continue;
        }
        if (!$isRequired) {
            if ($isOptional) {
                $optionalUnmatched[] = [
                    'position' => (int)$row['position'],
                    'name' => (string)$row['normalized_name'],
                    'reason' => (string)$row['outcome'],
                ];
            }
            continue;
        }
        if (
            ingredientOntologyV3RequiredOutcomeClass(
                (string)$row['outcome']
            ) === 'uncertain'
        ) {
            $uncertain[] = [
                'position' => (int)$row['position'],
                'name' => (string)$row['normalized_name'],
                'reason' => (string)$row['outcome'],
            ];
        } else {
            $missing[] = [
                'position' => (int)$row['position'],
                'name' => (string)$row['normalized_name'],
                'reason' => (string)$row['outcome'],
            ];
        }
    }
    return [
        'ontology_version_id' => (int)$revision['ontology_version_id'],
        'missing_required' => $missing,
        'uncertain_required' => $uncertain,
        'optional_unmatched' => $optionalUnmatched,
        'ingredient_matches' => $matches,
    ];
}

function ingredientOntologyV3ShadowSummary(
    PDO $db,
    int $revisionId
): array {
    $shadow = ingredientOntologyV3ShadowRevision($db, $revisionId);
    if ($shadow === null) {
        throw new InvalidArgumentException('v3 shadow revision not found');
    }
    $version = ingredientOntologyV3Version(
        $db,
        (int)$shadow['ontology_version_id']
    );
    $sourceIdentity = ingredientOntologyV3OwnerFingerprintAudit(
        $db,
        (int)$shadow['ontology_version_id']
    );
    $scoringConfiguration =
        ingredientOntologyV3ScoringConfigAudit($shadow);
    $materializedValues = ingredientOntologyV3MaterializedValueAudit(
        $db,
        $shadow
    );
    $materializedIdSets = ingredientOntologyV3MaterializedIdSetAudit(
        $db,
        $shadow
    );
    $versionIntegrity = [
        'valid' => $version !== null
            && $sourceIdentity['valid']
            && $scoringConfiguration['valid']
            && $materializedValues['valid']
            && $materializedIdSets['valid']
            && hash_equals(
                (string)$shadow['ontology_schema_hash'],
                (string)$version['schema_hash']
            )
            && hash_equals(
                (string)$shadow['ontology_prompt_hash'],
                (string)$version['prompt_hash']
            )
            && hash_equals(
                (string)$shadow['ontology_model_hash'],
                (string)$version['model_hash']
            )
            && hash_equals(
                (string)$shadow['ontology_corpus_hash'],
                (string)$version['corpus_hash']
            )
            && hash_equals(
                (string)$shadow['ontology_content_hash'],
                (string)$version['content_hash']
            )
            && hash_equals(
                (string)$version['schema_hash'],
                ingredientOntologyV3SchemaHash()
            )
            && hash_equals(
                (string)$version['prompt_hash'],
                ingredientOntologyV3PromptHash()
            )
            && hash_equals(
                (string)$version['model_hash'],
                ingredientOntologyV3ModelHash(
                    (string)$version['model_name']
                )
            )
            && hash_equals(
                (string)$version['corpus_hash'],
                ingredientOntologyV3CorpusHash($db)
            )
            && hash_equals(
                (string)$version['content_hash'],
                ingredientOntologyV3ContentHash(
                    $db,
                    (int)$shadow['ontology_version_id']
                )
            ),
        'source_owner_fingerprints' => $sourceIdentity,
        'scoring_configuration' => $scoringConfiguration,
        'materialized_values' => $materializedValues,
        'materialized_id_sets' => $materializedIdSets,
    ];

    $baselineId = (int)($shadow['parent_score_revision_id'] ?? 0);
    if ($baselineId <= 0) {
        $baselineId = (int)(
            recipeScoreState($db)['active_score_revision_id'] ?? 0
        );
    }
    $aggregate = static function (PDO $db, int $id): array {
        $stmt = $db->prepare("
            SELECT COUNT(*) AS recipe_count,
                   SUM(cookable) AS cookable_count,
                   AVG(coverage) AS average_coverage,
                   SUM(missing_required_count) AS missing_required,
                   SUM(uncertain_required_count) AS uncertain_required
            FROM recipe_inventory_scores
            WHERE score_revision_id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'revision_id' => $id,
            'recipe_count' => (int)($row['recipe_count'] ?? 0),
            'cookable_count' => (int)($row['cookable_count'] ?? 0),
            'average_coverage' => round(
                (float)($row['average_coverage'] ?? 0),
                6
            ),
            'missing_required' => (int)($row['missing_required'] ?? 0),
            'uncertain_required' => (int)($row['uncertain_required'] ?? 0),
        ];
    };
    $baseline = $aggregate($db, $baselineId);
    $candidate = $aggregate($db, $revisionId);
    $changes = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_inventory_scores old
        JOIN recipe_inventory_scores new ON new.recipe_id = old.recipe_id
        WHERE old.score_revision_id = ?
          AND new.score_revision_id = ?
          AND (
              old.cookable <> new.cookable
              OR ABS(old.coverage - new.coverage) > 0.000001
              OR ABS(old.availability_score - new.availability_score)
                    > 0.000001
          )
    ");
    $changes->execute([$baselineId, $revisionId]);
    $changedCount = (int)$changes->fetchColumn();
    $cookabilityChanges = $db->prepare("
        SELECT COUNT(*)
        FROM recipe_inventory_scores old
        JOIN recipe_inventory_scores new ON new.recipe_id = old.recipe_id
        WHERE old.score_revision_id = ?
          AND new.score_revision_id = ?
          AND old.cookable <> new.cookable
    ");
    $cookabilityChanges->execute([$baselineId, $revisionId]);
    $cookabilityChangedCount = (int)$cookabilityChanges->fetchColumn();
    $cookabilityExplained = $db->prepare("
        SELECT COUNT(*)
        FROM (
            SELECT old.recipe_id
            FROM recipe_inventory_scores old
            JOIN recipe_inventory_scores new
              ON new.recipe_id = old.recipe_id
            WHERE old.score_revision_id = ?
              AND new.score_revision_id = ?
              AND old.cookable <> new.cookable
        ) changed
        WHERE NOT EXISTS (
            SELECT 1
            FROM recipe_ingredients ri
            LEFT JOIN ingredient_ontology_shadow_matches sm
              ON sm.score_revision_id = ?
             AND sm.recipe_ingredient_id = ri.id
            WHERE ri.recipe_id = changed.recipe_id
              AND (
                  sm.recipe_ingredient_id IS NULL
                  OR json_valid(sm.explanation_json) = 0
                  OR sm.explanation_json = '{}'
              )
        )
    ");
    $cookabilityExplained->execute([
        $baselineId,
        $revisionId,
        $revisionId,
    ]);
    $cookabilityExplainedCount =
        (int)$cookabilityExplained->fetchColumn();
    $productChanges = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_mappings m
        WHERE m.ontology_version_id = ?
          AND m.owner_type = 'product'
          AND (
              m.status <> 'accepted'
              OR COALESCE((
                  SELECT ci.slug
                  FROM product_ingredients pi
                  JOIN canonical_ingredients ci
                    ON ci.id = pi.ingredient_id
                  WHERE pi.product_id = m.owner_id
                    AND pi.role = 'primary'
                  ORDER BY pi.confidence DESC, pi.id
                  LIMIT 1
              ), '') <> COALESCE((
                  SELECT e.slug FROM ingredient_ontology_entities e
                  WHERE e.id = m.entity_id
              ), '')
          )
    ");
    $productChanges->execute([(int)$shadow['ontology_version_id']]);
    return [
        'shadow_revision_id' => $revisionId,
        'ontology_version_id' => (int)$shadow['ontology_version_id'],
        'active_score_revision_id' =>
            recipeScoreState($db)['active_score_revision_id'],
        'shadow_only' =>
            recipeScoreState($db)['active_score_revision_id'] !== $revisionId,
        'baseline' => $baseline,
        'candidate' => $candidate,
        'changed_recipe_count' => $changedCount,
        'product_match_change_count' => (int)$productChanges->fetchColumn(),
        'cookable_delta' =>
            $candidate['cookable_count'] - $baseline['cookable_count'],
        'cookability_explanations' => [
            'changed_count' => $cookabilityChangedCount,
            'explained_count' => $cookabilityExplainedCount,
            'unexplained_count' => max(
                0,
                $cookabilityChangedCount - $cookabilityExplainedCount
            ),
            'complete' =>
                $cookabilityChangedCount === $cookabilityExplainedCount,
            'zero_denied_mechanisms' => true,
        ],
        'coverage_delta' => round(
            $candidate['average_coverage'] - $baseline['average_coverage'],
            6
        ),
        'version_integrity' => $versionIntegrity,
    ];
}

function ingredientOntologyV3WriteShadowReportJson(
    PDO $db,
    int $revisionId,
    mixed $stream
): array {
    if (!is_resource($stream)) {
        throw new InvalidArgumentException('report stream is invalid');
    }
    $shadow = ingredientOntologyV3ShadowRevision($db, $revisionId);
    if ($shadow === null) {
        throw new InvalidArgumentException('v3 shadow revision not found');
    }
    $summary = ingredientOntologyV3ShadowSummary($db, $revisionId);
    $baselineId = $summary['baseline']['revision_id'];
    fwrite($stream, '{"summary":');
    fwrite($stream, ingredientOntologyV3Json($summary));
    fwrite($stream, ',"currently_cookable_changes":[');
    $stmt = $db->prepare("
        SELECT c.id AS recipe_id, c.title,
               old.cookable AS current_cookable,
               new.cookable AS v3_cookable,
               old.coverage AS current_coverage,
               new.coverage AS v3_coverage,
               new.missing_required_count AS v3_missing_required,
               new.uncertain_required_count AS v3_uncertain_required
        FROM recipe_inventory_scores old
        JOIN recipe_inventory_scores new ON new.recipe_id = old.recipe_id
        JOIN recipe_catalog c ON c.id = old.recipe_id
        WHERE old.score_revision_id = ?
          AND new.score_revision_id = ?
          AND old.cookable = 1
          AND (
              new.cookable <> old.cookable
              OR ABS(new.coverage - old.coverage) > 0.000001
          )
        ORDER BY c.id
    ");
    $stmt->execute([$baselineId, $revisionId]);
    $explanationStmt = $db->prepare("
        SELECT ri.id AS recipe_ingredient_id,
               COALESCE(NULLIF(ri.raw_text, ''), ri.normalized_name)
                   AS ingredient_label,
               ri.is_required, ri.is_optional, ri.is_staple,
               sm.outcome, sm.satisfies_required, sm.confidence,
               sm.relationship, sm.inventory_product_id,
               sm.explanation_json
        FROM recipe_ingredients ri
        LEFT JOIN ingredient_ontology_shadow_matches sm
          ON sm.score_revision_id = ?
         AND sm.recipe_ingredient_id = ri.id
        WHERE ri.recipe_id = ?
        ORDER BY ri.position, ri.id
        LIMIT 100
    ");
    $first = true;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        foreach ([
            'recipe_id', 'current_cookable', 'v3_cookable',
            'v3_missing_required', 'v3_uncertain_required',
        ] as $key) {
            $row[$key] = (int)$row[$key];
        }
        $row['current_coverage'] = (float)$row['current_coverage'];
        $row['v3_coverage'] = (float)$row['v3_coverage'];
        $explanationStmt->execute([
            $revisionId,
            (int)$row['recipe_id'],
        ]);
        $requiredExplanations = [];
        while ($match = $explanationStmt->fetch(PDO::FETCH_ASSOC)) {
            foreach ([
                'recipe_ingredient_id', 'is_required', 'is_optional',
                'is_staple', 'satisfies_required',
            ] as $key) {
                $match[$key] = (int)$match[$key];
            }
            $match['inventory_product_id'] =
                $match['inventory_product_id'] !== null
                    ? (int)$match['inventory_product_id']
                    : null;
            $match['confidence'] = $match['confidence'] !== null
                ? (float)$match['confidence']
                : 0.0;
            $match['explanation'] = json_decode(
                (string)($match['explanation_json'] ?? '{}'),
                true
            ) ?: [];
            unset($match['explanation_json']);
            $requiredExplanations[] = $match;
        }
        $row['required_explanations'] = $requiredExplanations;
        $row['explanation_complete'] =
            count($requiredExplanations) > 0
            && count(array_filter(
                $requiredExplanations,
                static fn(array $match): bool =>
                    $match['outcome'] === null
                    || !$match['explanation']
            )) === 0;
        if (!$first) {
            fwrite($stream, ',');
        }
        fwrite($stream, ingredientOntologyV3Json($row));
        $first = false;
    }
    fwrite($stream, '],"top_rank_changes":[');
    $ranks = $db->prepare("
        WITH old_rank AS (
            SELECT recipe_id,
                   row_number() OVER (
                       ORDER BY availability_score DESC, coverage DESC,
                                recipe_id ASC
                   ) AS rank_value
            FROM recipe_inventory_scores
            WHERE score_revision_id = ?
        ),
        new_rank AS (
            SELECT recipe_id,
                   row_number() OVER (
                       ORDER BY availability_score DESC, coverage DESC,
                                recipe_id ASC
                   ) AS rank_value
            FROM recipe_inventory_scores
            WHERE score_revision_id = ?
        )
        SELECT c.id AS recipe_id, c.title,
               old_rank.rank_value AS current_rank,
               new_rank.rank_value AS v3_rank,
               old_rank.rank_value - new_rank.rank_value AS rank_delta
        FROM old_rank
        JOIN new_rank ON new_rank.recipe_id = old_rank.recipe_id
        JOIN recipe_catalog c ON c.id = old_rank.recipe_id
        WHERE old_rank.rank_value <> new_rank.rank_value
        ORDER BY ABS(old_rank.rank_value - new_rank.rank_value) DESC,
                 c.id
        LIMIT 500
    ");
    $ranks->execute([$baselineId, $revisionId]);
    $first = true;
    while ($row = $ranks->fetch(PDO::FETCH_ASSOC)) {
        foreach (['recipe_id', 'current_rank', 'v3_rank', 'rank_delta'] as $key) {
            $row[$key] = (int)$row[$key];
        }
        if (!$first) {
            fwrite($stream, ',');
        }
        fwrite($stream, ingredientOntologyV3Json($row));
        $first = false;
    }
    fwrite($stream, '],"high_frequency_labels":[');
    $labels = $db->prepare("
        SELECT m.normalized_label, m.language, COUNT(*) AS frequency,
               group_concat(DISTINCT m.status) AS mapping_statuses,
               group_concat(DISTINCT sm.outcome) AS shadow_outcomes,
               SUM(sm.satisfies_required) AS satisfied_rows
        FROM ingredient_ontology_mappings m
        LEFT JOIN ingredient_ontology_shadow_matches sm
          ON sm.score_revision_id = ?
         AND sm.recipe_mapping_id = m.id
        WHERE m.ontology_version_id = ?
          AND m.owner_type = 'recipe_ingredient'
        GROUP BY m.normalized_label, m.language
        HAVING COUNT(*) >= 100
        ORDER BY frequency DESC, m.normalized_label
    ");
    $labels->execute([$revisionId, (int)$shadow['ontology_version_id']]);
    $first = true;
    while ($row = $labels->fetch(PDO::FETCH_ASSOC)) {
        $row['frequency'] = (int)$row['frequency'];
        $row['satisfied_rows'] = (int)($row['satisfied_rows'] ?? 0);
        if (!$first) {
            fwrite($stream, ',');
        }
        fwrite($stream, ingredientOntologyV3Json($row));
        $first = false;
    }
    fwrite($stream, '],"product_match_changes":[');
    $products = $db->prepare("
        SELECT p.id AS product_id, p.name,
               m.status AS v3_status, e.slug AS v3_entity_slug,
               (
                   SELECT ci.slug
                   FROM product_ingredients pi
                   JOIN canonical_ingredients ci ON ci.id = pi.ingredient_id
                   WHERE pi.product_id = p.id AND pi.role = 'primary'
                   ORDER BY pi.confidence DESC, pi.id
                   LIMIT 1
               ) AS current_primary_slug
        FROM products p
        JOIN ingredient_ontology_mappings m
          ON m.ontology_version_id = ?
         AND m.owner_type = 'product'
         AND m.owner_id = p.id
        LEFT JOIN ingredient_ontology_entities e ON e.id = m.entity_id
        WHERE m.status <> 'accepted'
           OR COALESCE(e.slug, '') <> COALESCE((
               SELECT ci.slug
               FROM product_ingredients pi
               JOIN canonical_ingredients ci ON ci.id = pi.ingredient_id
               WHERE pi.product_id = p.id AND pi.role = 'primary'
               ORDER BY pi.confidence DESC, pi.id
               LIMIT 1
           ), '')
        ORDER BY p.id
    ");
    $products->execute([(int)$shadow['ontology_version_id']]);
    $first = true;
    while ($row = $products->fetch(PDO::FETCH_ASSOC)) {
        $row['product_id'] = (int)$row['product_id'];
        if (!$first) {
            fwrite($stream, ',');
        }
        fwrite($stream, ingredientOntologyV3Json($row));
        $first = false;
    }
    fwrite($stream, '],"false_positive_clusters":');
    $clusters = [];
    foreach ([
        'taxonomy_rule' => "m.mapping_source = 'taxonomy_rule_evidence'",
        'model_quarantine' => (
            "m.mapping_source = 'quarantined_model_evidence'"
        ),
        'ancestry_only' => (
            "sm.outcome IN ('broader_requirement_evidence','pantry_ancestor')"
        ),
        'component_or_derived' => (
            "sm.outcome = 'non_identity_relation'"
        ),
        'defining_attribute_conflict' => (
            "sm.outcome LIKE 'different_%'"
        ),
    ] as $name => $where) {
        $cluster = $db->prepare("
            SELECT COUNT(*)
            FROM ingredient_ontology_shadow_matches sm
            LEFT JOIN ingredient_ontology_mappings m
              ON m.id = sm.recipe_mapping_id
            WHERE sm.score_revision_id = ? AND ({$where})
        ");
        $cluster->execute([$revisionId]);
        $clusters[$name] = (int)$cluster->fetchColumn();
    }
    fwrite($stream, ingredientOntologyV3Json($clusters));
    fwrite($stream, '}' . PHP_EOL);
    return $summary;
}

function ingredientOntologyV3HumanShadowSummary(array $summary): string {
    return implode(PHP_EOL, [
        'Ingredient ontology v3 shadow revision #'
            . $summary['shadow_revision_id'],
        'Recipes: ' . $summary['candidate']['recipe_count'],
        'Cookable: ' . $summary['baseline']['cookable_count']
            . ' -> ' . $summary['candidate']['cookable_count']
            . ' (' . sprintf('%+d', $summary['cookable_delta']) . ')',
        'Average coverage: ' . $summary['baseline']['average_coverage']
            . ' -> ' . $summary['candidate']['average_coverage'],
        'Changed recipes: ' . $summary['changed_recipe_count'],
        'Product mapping changes: ' . $summary['product_match_change_count'],
        'Version hashes/source valid: '
            . ($summary['version_integrity']['valid'] ? 'yes' : 'no'),
        'Scoring configuration valid: '
            . (
                $summary['version_integrity']['scoring_configuration']['valid']
                    ? 'yes'
                    : 'no'
            ),
        'Active pointer changed: ' . ($summary['shadow_only'] ? 'no' : 'yes'),
    ]) . PHP_EOL;
}

function ingredientOntologyV3GoldPath(): string {
    return dirname(__DIR__, 3)
        . '/tests/fixtures/ingredient_ontology_v3_gold.json';
}

function ingredientOntologyV3EvaluateGold(
    PDO $db,
    int $versionId,
    ?string $path = null,
    bool $enforcePin = true
): array {
    $policy = [
        'minimum_expected_negatives' => 1,
        'minimum_critical_negatives' => 1,
        'minimum_precision' => 0.99,
        'minimum_recall' => 0.99,
        'maximum_false_negatives' => 0,
        'maximum_critical_false_positives' => 0,
        'scope' => 'deterministic_fixture_regression_only',
    ];
    $failure = static function (string $error) use ($policy): array {
        return [
            'valid' => false,
            'error' => $error,
            'case_count' => 0,
            'resolved' => 0,
            'unresolved' => 0,
            'expected_positive' => 0,
            'expected_negative' => 0,
            'critical_negative' => 0,
            'predicted_positive' => 0,
            'true_positive' => 0,
            'false_positive' => 0,
            'false_negative' => 0,
            'critical_overmatches' => 0,
            'precision' => 0.0,
            'recall' => 0.0,
            'precision_interval_95' => [0.0, 0.0],
            'recall_interval_95' => [0.0, 0.0],
            'fixture_only' => true,
            'statistical_precision_claim' => false,
            'confidence_limitations' =>
                'Synthetic fixtures detect enumerated regressions only; '
                . 'they do not establish corpus-wide statistical precision.',
            'policy' => $policy,
        ];
    };
    $path = $path ?? ingredientOntologyV3GoldPath();
    if (!is_file($path)) {
        return $failure('gold fixture is missing');
    }
    clearstatcache(true, $path);
    $size = filesize($path);
    if ($size === false || $size <= 0 || $size > 262144) {
        return $failure('gold fixture is empty or oversized');
    }
    $fixtureHash = hash_file('sha256', $path);
    if (
        $enforcePin
        && (
            !is_string($fixtureHash)
            || !hash_equals(
                INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_SHA256,
                $fixtureHash
            )
        )
    ) {
        return $failure('gold fixture hash does not match its pin');
    }
    try {
        $fixture = json_decode(
            (string)file_get_contents($path),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable $e) {
        return $failure('gold fixture is malformed');
    }
    if (
        !is_array($fixture)
        || ($fixture['schema_version'] ?? null)
            !== 'ingredient_ontology_v3_gold_1'
        || !is_array($fixture['cases'] ?? null)
    ) {
        return $failure('gold fixture is invalid');
    }
    $cases = $fixture['cases'];
    $caseIds = array_map(
        static fn(mixed $case): mixed =>
            is_array($case) ? ($case['id'] ?? null) : null,
        $cases
    );
    if (
        $enforcePin
        && (
        count($cases) !== INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_COUNT
        || $caseIds !== ingredientOntologyV3MatcherGoldCaseIds()
        || !hash_equals(
            INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_IDS_SHA256,
            ingredientOntologyV3Hash($caseIds)
        )
        )
    ) {
        return $failure('gold fixture case universe does not match its pin');
    }
    if (!$cases || count($cases) > 500) {
        return $failure('gold fixture case count is invalid');
    }
    $facetMap = ingredientOntologyV3FacetMap($db, $versionId);
    $entityStmt = $db->prepare("
        SELECT id FROM ingredient_ontology_entities
        WHERE ontology_version_id = ? AND slug = ? AND active = 1
    ");
    $context = new IngredientOntologyV3MatcherContext($db, $versionId);
    $truePositive = 0;
    $falsePositive = 0;
    $falseNegative = 0;
    $resolved = 0;
    $criticalOvermatches = [];
    $unresolvedCases = [];
    $falsePositiveCases = [];
    $falseNegativeCases = [];
    $seenIds = [];
    $expectedPositive = 0;
    $expectedNegative = 0;
    $criticalNegative = 0;
    $validAssertion = static function (
        array $assertion
    ) use ($facetMap): bool {
        $slug = $assertion['entity_slug'] ?? null;
        $attributes = $assertion['attributes'] ?? [];
        if (
            !is_string($slug)
            || $slug === ''
            || strlen($slug) > 160
            || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)
            || !is_array($attributes)
            || count($attributes) > 16
        ) {
            return false;
        }
        foreach ($attributes as $facet => $value) {
            if (
                !is_string($facet)
                || $facet === ''
                || strlen($facet) > 60
                || !is_string($value)
                || $value === ''
                || strlen($value) > 80
                || !isset($facetMap[$facet]['values'][$value])
            ) {
                return false;
            }
        }
        return true;
    };
    foreach ($cases as $case) {
        if (
            !is_array($case)
            || !is_string($case['id'] ?? null)
            || trim($case['id']) === ''
            || strlen($case['id']) > 120
            || isset($seenIds[$case['id']])
            || !is_array($case['required'] ?? null)
            || !is_array($case['inventory'] ?? null)
            || !$validAssertion($case['required'])
            || !$validAssertion($case['inventory'])
            || !array_key_exists('expected_satisfies_required', $case)
            || !is_bool($case['expected_satisfies_required'])
            || (
                array_key_exists('critical', $case)
                && !is_bool($case['critical'])
            )
        ) {
            return $failure('gold fixture contains an invalid or duplicate case');
        }
        $seenIds[$case['id']] = true;
        if ($case['expected_satisfies_required']) {
            $expectedPositive++;
        } else {
            $expectedNegative++;
            if (!empty($case['critical'])) {
                $criticalNegative++;
            }
        }
        $entityStmt->execute([$versionId, $case['required']['entity_slug']]);
        $requiredId = (int)($entityStmt->fetchColumn() ?: 0);
        $entityStmt->execute([$versionId, $case['inventory']['entity_slug']]);
        $inventoryId = (int)($entityStmt->fetchColumn() ?: 0);
        if ($requiredId <= 0 || $inventoryId <= 0) {
            if (count($unresolvedCases) < 50) {
                $unresolvedCases[] = [
                    'id' => $case['id'],
                    'required_resolved' => $requiredId > 0,
                    'inventory_resolved' => $inventoryId > 0,
                ];
            }
            continue;
        }
        $resolved++;
        $result = ingredientOntologyV3MatchWithContext(
            $context,
            [
                'entity_id' => $requiredId,
                'status' => 'accepted',
                'mapping_source' => 'gold',
                'attributes' => $case['required']['attributes'] ?? [],
            ],
            [
                'entity_id' => $inventoryId,
                'status' => 'accepted',
                'mapping_source' => 'gold',
                'attributes' => $case['inventory']['attributes'] ?? [],
            ]
        );
        $expected = !empty($case['expected_satisfies_required']);
        $actual = !empty($result['satisfies_required']);
        if ($expected && $actual) {
            $truePositive++;
        } elseif (!$expected && $actual) {
            $falsePositive++;
            if (count($falsePositiveCases) < 50) {
                $falsePositiveCases[] = [
                    'id' => $case['id'],
                    'outcome' => $result['outcome'],
                    'critical' => !empty($case['critical']),
                ];
            }
            if (!empty($case['critical'])) {
                $criticalOvermatches[] = [
                    'id' => $case['id'],
                    'outcome' => $result['outcome'],
                ];
            }
        } elseif ($expected && !$actual) {
            $falseNegative++;
            if (count($falseNegativeCases) < 50) {
                $falseNegativeCases[] = [
                    'id' => $case['id'],
                    'outcome' => $result['outcome'],
                ];
            }
        }
    }
    $precisionDenominator = $truePositive + $falsePositive;
    $recallDenominator = $truePositive + $falseNegative;
    $precision = $precisionDenominator > 0
        ? $truePositive / $precisionDenominator
        : 0.0;
    $recall = $recallDenominator > 0
        ? $truePositive / $recallDenominator
        : 0.0;
    $wilson = static function (int $successes, int $trials): array {
        if ($trials <= 0) {
            return [0.0, 0.0];
        }
        $z = 1.959963984540054;
        $p = $successes / $trials;
        $denominator = 1 + (($z * $z) / $trials);
        $center = (
            $p + (($z * $z) / (2 * $trials))
        ) / $denominator;
        $margin = (
            $z * sqrt(
                (($p * (1 - $p)) / $trials)
                + (($z * $z) / (4 * $trials * $trials))
            )
        ) / $denominator;
        return [
            round(max(0.0, $center - $margin), 6),
            round(min(1.0, $center + $margin), 6),
        ];
    };
    $predictedPositive = $truePositive + $falsePositive;
    $unresolved = count($cases) - $resolved;
    $valid = $resolved === count($cases)
        && $expectedPositive > 0
        && $expectedNegative >= $policy['minimum_expected_negatives']
        && $criticalNegative >= $policy['minimum_critical_negatives']
        && $predictedPositive > 0
        && $precision >= $policy['minimum_precision']
        && $recall >= $policy['minimum_recall']
        && $falseNegative <= $policy['maximum_false_negatives']
        && count($criticalOvermatches)
            <= $policy['maximum_critical_false_positives'];
    return [
        'valid' => $valid,
        'fixture_hash' => $fixtureHash,
        'fixture_hash_matches_pin' => is_string($fixtureHash)
            && hash_equals(
                INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_SHA256,
                $fixtureHash
            ),
        'case_ids_hash' =>
            ingredientOntologyV3Hash($caseIds),
        'case_ids_match_pin' => $caseIds
                === ingredientOntologyV3MatcherGoldCaseIds()
            && hash_equals(
                INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_IDS_SHA256,
                ingredientOntologyV3Hash($caseIds)
            ),
        'case_count' => count($cases),
        'resolved' => $resolved,
        'unresolved' => $unresolved,
        'unresolved_cases' => $unresolvedCases,
        'expected_positive' => $expectedPositive,
        'expected_negative' => $expectedNegative,
        'critical_negative' => $criticalNegative,
        'predicted_positive' => $predictedPositive,
        'true_positive' => $truePositive,
        'false_positive' => $falsePositive,
        'false_negative' => $falseNegative,
        'false_positive_cases' => $falsePositiveCases,
        'false_negative_cases' => $falseNegativeCases,
        'critical_overmatches' => count($criticalOvermatches),
        'critical_overmatch_cases' => $criticalOvermatches,
        'precision' => round($precision, 6),
        'recall' => round($recall, 6),
        'fixture_precision' => round($precision, 6),
        'fixture_recall' => round($recall, 6),
        'precision_interval_95' => $wilson(
            $truePositive,
            $precisionDenominator
        ),
        'recall_interval_95' => $wilson(
            $truePositive,
            $recallDenominator
        ),
        'fixture_only' => true,
        'statistical_precision_claim' => false,
        'confidence_limitations' =>
            'Synthetic fixtures detect enumerated regressions only; '
            . 'the observed rates and intervals do not establish '
            . 'corpus-wide statistical precision.',
        'policy' => $policy,
    ];
}

function ingredientOntologyV3ValidateActivation(
    PDO $db,
    int $revisionId
): array {
    $candidateRevision = recipeScoreRevision($db, $revisionId);
    if (
        $candidateRevision !== null
        && ($candidateRevision['requirement_revision_id'] ?? null) !== null
        && function_exists(
            'ingredientOntologyV3ValidateRequirementActivation'
        )
    ) {
        return ingredientOntologyV3ValidateRequirementActivation(
            $db,
            $revisionId
        );
    }
    $revision = ingredientOntologyV3ShadowRevision($db, $revisionId);
    if ($revision === null || $revision['status'] !== 'ready') {
        return ['valid' => false, 'errors' => ['shadow revision is not ready']];
    }
    $versionId = (int)$revision['ontology_version_id'];
    $version = ingredientOntologyV3Version($db, $versionId);
    $graph = ingredientOntologyV3GraphValidate($db, $versionId);
    $corpus = ingredientOntologyV3CorpusCompleteness($db, $versionId);
    $gold = ingredientOntologyV3EvaluateGold($db, $versionId);
    $snapshot = ingredientOntologyV3ActivationSnapshot($db, $revisionId);
    $errors = ingredientOntologyV3ActivationErrors($snapshot);
    if (!$graph['valid']) {
        $errors[] = 'ontology graph validation failed';
    }
    if (!$corpus['complete']) {
        $errors[] = 'mapping corpus is incomplete';
    }
    if (!$gold['valid']) {
        $errors[] = 'frozen gold policy failed';
    }
    return [
        'valid' => !$errors,
        'errors' => $errors,
        'revision_id' => $revisionId,
        'ontology_version_id' => $versionId,
        'graph' => $graph,
        'corpus' => $corpus,
        'gold' => $gold,
        'matcher_gold' => $snapshot['matcher_gold'],
        'resolution_gold' => $snapshot['resolution_gold'],
        'materialized_id_sets' =>
            $snapshot['materialized_id_sets'],
        'materialized_values' =>
            $snapshot['materialized_values'],
        'version_integrity' => $snapshot['version_integrity'],
        'rollback_baseline' => [
            'revision_id' =>
                $snapshot['parent_revision']['id'] ?? null,
            'valid' =>
                $snapshot['parent_materialization_errors'] === [],
            'errors' =>
                $snapshot['parent_materialization_errors'],
        ],
        'shadow' => [
            'catalog_recipe_count' => $snapshot['catalog_count'],
            'score_count' => $snapshot['score_count'],
            'ingredient_count' => $snapshot['ingredient_count'],
            'match_count' => $snapshot['match_count'],
        ],
        'inputs' => [
            'inventory_revision' => $snapshot['state']['inventory_revision'],
            'catalog_revision' => $snapshot['state']['catalog_revision'],
            'inventory_fingerprint_matches' =>
                hash_equals(
                    (string)$revision['inventory_fingerprint'],
                    (string)$snapshot['inventory_fingerprint']
                ),
            'catalog_fingerprint_matches' =>
                hash_equals(
                    (string)$revision['catalog_fingerprint'],
                    (string)$snapshot['catalog_fingerprint']
                ),
            'scoring_configuration' =>
                $snapshot['scoring_configuration'],
            'source_owner_fingerprints' =>
                $snapshot['owner_fingerprints'],
        ],
        'approved_change_sets' => $snapshot['pending_change_sets'] === 0,
        'invalid_change_sets' => $snapshot['invalid_change_sets'],
    ];
}

function ingredientOntologyV3Activate(
    PDO $db,
    int $revisionId
): array {
    $validation = ingredientOntologyV3ValidateActivation($db, $revisionId);
    if (!$validation['valid']) {
        throw new RuntimeException(
            'ontology v3 activation blocked: '
            . implode('; ', $validation['errors'])
        );
    }
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && is_callable(
            $GLOBALS['INGREDIENT_ONTOLOGY_V3_BEFORE_ACTIVATION_RESERVATION']
                ?? null
        )
    ) {
        ($GLOBALS['INGREDIENT_ONTOLOGY_V3_BEFORE_ACTIVATION_RESERVATION'])(
            $db,
            $revisionId
        );
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        if (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && is_callable(
                $GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_AFTER_ACTIVATION_RESERVATION'
                ] ?? null
            )
        ) {
            ($GLOBALS[
                'INGREDIENT_ONTOLOGY_V3_AFTER_ACTIVATION_RESERVATION'
            ])($db, $revisionId);
        }
        $snapshot = ingredientOntologyV3ActivationSnapshot($db, $revisionId);
        $errors = ingredientOntologyV3ActivationErrors($snapshot);
        if ($errors) {
            throw new RuntimeException(
                'ontology v3 activation inputs changed: '
                . implode('; ', $errors)
            );
        }
        $revision = $snapshot['revision'];
        $cursorRevision = (int)$snapshot['state']['cursor_revision'] + 1;
        $db->prepare("
            UPDATE recipe_score_state
            SET active_score_revision_id = ?,
                active_score_overlay_revision_id = NULL,
                cursor_revision = cursor_revision + 1
            WHERE id = 1
        ")->execute([$revisionId]);
        recipeScoreClearPendingProducts(
            $db,
            (int)$revision['inventory_revision']
        );
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $e;
    }
    return [
        'activated' => true,
        'revision_id' => $revisionId,
        'ontology_version_id' => (int)$revision['ontology_version_id'],
        'active_version_derived_from_score' => true,
        'cursor_revision' => $cursorRevision,
    ];
}

function ingredientOntologyV3RetainedMaterializationErrors(
    PDO $db,
    array $target
): array {
    $errors = [];
    if ($target['status'] !== 'ready') {
        $errors[] = 'rollback target is not ready';
        return $errors;
    }
    $scoreCount = $db->prepare("
        SELECT COUNT(*) FROM recipe_inventory_scores
        WHERE score_revision_id = ?
    ");
    $scoreCount->execute([(int)$target['id']]);
    if ((int)$scoreCount->fetchColumn() !== (int)$target['recipe_count']) {
        $errors[] = 'rollback target materialization is incomplete';
    }
    if (!ingredientOntologyV3MaterializedValueAudit($db, $target)['valid']) {
        $errors[] = 'rollback target materialized values changed';
    }
    if ($target['ontology_version_id'] !== null) {
        if (
            (string)($target['scoring_model'] ?? '')
                !== 'faceted-ontology-v3'
        ) {
            $errors[] = 'rollback target scoring model is inconsistent';
            return $errors;
        }
        if (!ingredientOntologyV3ScoringConfigAudit($target)['valid']) {
            $errors[] = 'rollback target scoring configuration is incompatible';
        }
        $versionId = (int)$target['ontology_version_id'];
        $version = ingredientOntologyV3Version($db, $versionId);
        if ($version === null || $version['status'] !== 'ready') {
            $errors[] = 'rollback target ontology version is not ready';
        }
        $report = json_decode(
            (string)($target['validation_report_json'] ?? ''),
            true
        );
        $expectedRecipeCount = is_array($report)
            && is_int($report['recipe_count'] ?? null)
                ? $report['recipe_count']
                : -1;
        $expectedMatchCount = is_array($report)
            && is_int($report['ingredient_match_count'] ?? null)
                ? $report['ingredient_match_count']
                : -1;
        $matchCount = $db->prepare("
            SELECT COUNT(*) FROM ingredient_ontology_shadow_matches
            WHERE score_revision_id = ?
        ");
        $matchCount->execute([(int)$target['id']]);
        if (
            $expectedRecipeCount !== (int)$target['recipe_count']
            || $expectedMatchCount < 0
            || (int)$matchCount->fetchColumn() !== $expectedMatchCount
        ) {
            $errors[] = 'rollback target explanations are incomplete';
        }
    } elseif (
        (string)($target['scoring_model'] ?? 'legacy-v2')
            === 'faceted-ontology-v3'
    ) {
        $errors[] = 'rollback target scoring model is inconsistent';
    }
    return array_values(array_unique($errors));
}

function ingredientOntologyV3AncestorProof(
    PDO $db,
    array $active,
    int $targetRevisionId
): array {
    $cursor = $active;
    $seen = [(int)$active['id'] => true];
    for (
        $depth = 1;
        $depth <= RECIPE_SCORE_V3_ROLLBACK_ANCESTOR_LIMIT;
        $depth++
    ) {
        $parentId = (int)($cursor['parent_score_revision_id'] ?? 0);
        if ($parentId <= 0) {
            return ['proven' => false, 'cycle' => false, 'depth' => 0];
        }
        if (isset($seen[$parentId])) {
            return ['proven' => false, 'cycle' => true, 'depth' => $depth];
        }
        if ($parentId === $targetRevisionId) {
            return ['proven' => true, 'cycle' => false, 'depth' => $depth];
        }
        $parent = recipeScoreRevision($db, $parentId);
        if ($parent === null || $parent['status'] !== 'ready') {
            return ['proven' => false, 'cycle' => false, 'depth' => 0];
        }
        $seen[$parentId] = true;
        $cursor = $parent;
    }
    return [
        'proven' => false,
        'cycle' => false,
        'depth' => RECIPE_SCORE_V3_ROLLBACK_ANCESTOR_LIMIT,
    ];
}

function ingredientOntologyV3Rollback(
    PDO $db,
    ?int $targetRevisionId = null,
    ?int $expectedActiveRevisionId = null
): array {
    $db->exec('BEGIN IMMEDIATE');
    try {
        $state = recipeScoreState($db);
        $activeId = (int)($state['active_score_revision_id'] ?? 0);
        if (
            $expectedActiveRevisionId !== null
            && $activeId !== $expectedActiveRevisionId
        ) {
            $db->exec('ROLLBACK');
            return [
                'rolled_back' => false,
                'superseded' => true,
                'expected_active_revision_id' =>
                    $expectedActiveRevisionId,
                'active_revision_id' => $activeId,
                'to_revision_id' => $targetRevisionId,
            ];
        }
        $active = recipeScoreRevision($db, $activeId);
        if ($active === null) {
            throw new RuntimeException('there is no active score revision');
        }
        if ($targetRevisionId === null) {
            $targetRevisionId = (int)($active['parent_score_revision_id'] ?? 0);
        }
        $target = recipeScoreRevision($db, $targetRevisionId);
        if ($target === null || $target['status'] !== 'ready') {
            throw new InvalidArgumentException('rollback target is not ready');
        }
        $ancestorProof = ingredientOntologyV3AncestorProof(
            $db,
            $active,
            $targetRevisionId
        );
        if ($ancestorProof['cycle']) {
            throw new RuntimeException('active score lineage contains a cycle');
        }
        if (!$ancestorProof['proven']) {
            if (
                $target['ontology_version_id'] === null
                || (string)($target['scoring_model'] ?? '')
                    !== 'faceted-ontology-v3'
            ) {
                throw new RuntimeException(
                    'non-ancestor legacy score revisions cannot be activated'
                );
            }
            $db->exec('ROLLBACK');
            $activation = ingredientOntologyV3Activate(
                $db,
                $targetRevisionId
            );
            return [
                'rolled_back' => false,
                'activated_non_ancestor' => true,
                'from_revision_id' => $activeId,
                'to_revision_id' => $targetRevisionId,
                'cursor_revision' => $activation['cursor_revision'],
            ];
        }
        $targetErrors = ingredientOntologyV3RetainedMaterializationErrors(
            $db,
            $target
        );
        if ($targetErrors) {
            throw new RuntimeException(
                'rollback target validation failed: '
                . implode('; ', $targetErrors)
            );
        }
        $db->prepare("
            UPDATE recipe_score_state
            SET active_score_revision_id = ?,
                active_score_overlay_revision_id = NULL,
                cursor_revision = cursor_revision + 1
            WHERE id = 1
        ")->execute([$targetRevisionId]);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $e;
    }
    return [
        'rolled_back' => true,
        'from_revision_id' => $activeId,
        'to_revision_id' => $targetRevisionId,
        'ranking_status' => recipeScoreRevisionStatus($db, $target),
        'active_ontology_version_id' =>
            ingredientOntologyV3ActiveVersion($db)['id'] ?? null,
        'cursor_revision' => recipeScoreState($db)['cursor_revision'],
    ];
}
