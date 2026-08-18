<?php
declare(strict_types=1);

const INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION =
    'identity-annex-r0-v1';
const INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_REVIEW_VERSION =
    'operator-reviewed-aliases-2026-08-17-v1';

function ingredientOntologyV3IdentityAnnexReviewedAliases(): array {
    return [
        'russet potato' => [
            'target_normalized_label' => 'potatoes',
            'target_language' => 'en',
            'target_entity_slug' => 'potato',
            'target_kind' => 'exact_alias',
            'review_key' => 'russet-potato-to-potato',
            'rationale' =>
                'Russet is a reviewed potato variety and preserves potato identity.',
        ],
        'russet potatoes' => [
            'target_normalized_label' => 'potatoes',
            'target_language' => 'en',
            'target_entity_slug' => 'potato',
            'target_kind' => 'exact_alias',
            'review_key' => 'russet-potatoes-to-potato',
            'rationale' =>
                'Russet is a reviewed potato variety and preserves potato identity.',
        ],
    ];
}

function ingredientOntologyV3IdentityAnnexReviewManifestHash(): string {
    return ingredientOntologyV3Hash([
        'version' => INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_REVIEW_VERSION,
        'aliases' => ingredientOntologyV3IdentityAnnexReviewedAliases(),
    ]);
}

function ingredientOntologyV3IdentityAnnexTableExists(PDO $db): bool {
    return ingredientOntologyV3TableExists(
        $db,
        'ingredient_ontology_identity_annex'
    );
}

function ingredientOntologyV3IdentityAnnexLabelCandidates(
    PDO $db,
    int $versionId,
    string $normalizedLabel,
    string $language
): array {
    $stmt = $db->prepare("
        SELECT label.id AS label_id, label.entity_id,
               label.normalized_label, label.language,
               label.kind, label.provenance, label.source_ref,
               entity.slug AS entity_slug,
               entity.canonical_name AS entity_name,
               policy.required_cohort,
               policy.required_evidence_kind,
               policy.required_evidence_key,
               facet.facet_key, value.value_key,
               attribute.is_defining
        FROM ingredient_ontology_labels label
        JOIN ingredient_ontology_entities entity
          ON entity.id = label.entity_id
         AND entity.ontology_version_id = label.ontology_version_id
        LEFT JOIN ingredient_ontology_label_context_policies policy
          ON policy.label_id = label.id
        LEFT JOIN ingredient_ontology_label_attributes attribute
          ON attribute.label_id = label.id
        LEFT JOIN ingredient_ontology_facets facet
          ON facet.id = attribute.facet_id
        LEFT JOIN ingredient_ontology_facet_values value
          ON value.id = attribute.facet_value_id
        WHERE label.ontology_version_id = ?
          AND label.normalized_label = ?
          AND label.review_state = 'accepted'
          AND label.kind IN ('exact_alias', 'attribute_alias')
          AND entity.active = 1
          AND entity.entity_kind = 'ingredient'
          AND entity.identity_role = 'identity_leaf'
          AND entity.provenance <> 'autonomous_controller'
          AND entity.slug NOT LIKE 'provisional-subject-%'
        ORDER BY label.id, facet.facet_key
    ");
    $stmt->execute([$versionId, $normalizedLabel]);
    $byLabel = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $labelId = (int)$row['label_id'];
        if (!isset($byLabel[$labelId])) {
            $candidateLanguage = ingredientOntologyV3NormalizeLanguage(
                (string)$row['language']
            );
            if (
                !ingredientOntologyV3LanguageMatches(
                    $candidateLanguage,
                    $language
                )
                || !ingredientOntologyV3AcceptedLabelProvenanceAllowed(
                    (string)$row['provenance']
                )
                || trim((string)($row['required_cohort'] ?? '')) !== ''
                || trim(
                    (string)($row['required_evidence_kind'] ?? '')
                ) !== ''
                || trim(
                    (string)($row['required_evidence_key'] ?? '')
                ) !== ''
            ) {
                continue;
            }
            $requestedLanguage = ingredientOntologyV3NormalizeLanguage(
                $language
            );
            $byLabel[$labelId] = [
                'label_id' => $labelId,
                'entity_id' => (int)$row['entity_id'],
                'normalized_label' =>
                    (string)$row['normalized_label'],
                'language' => $candidateLanguage,
                'kind' => (string)$row['kind'],
                'provenance' => (string)$row['provenance'],
                'source_ref' => $row['source_ref'] !== null
                    ? (string)$row['source_ref']
                    : null,
                'entity_slug' => (string)$row['entity_slug'],
                'entity_name' => (string)$row['entity_name'],
                'attributes' => [],
                'language_rank' => $candidateLanguage === $requestedLanguage
                    ? 2
                    : 1,
            ];
        }
        if (
            isset($byLabel[$labelId])
            && $row['facet_key'] !== null
            && $row['value_key'] !== null
            && !empty($row['is_defining'])
        ) {
            $byLabel[$labelId]['attributes'][
                (string)$row['facet_key']
            ] = (string)$row['value_key'];
        }
    }
    foreach ($byLabel as &$candidate) {
        ksort($candidate['attributes'], SORT_STRING);
    }
    unset($candidate);
    usort(
        $byLabel,
        static fn(array $left, array $right): int =>
            $right['language_rank'] <=> $left['language_rank']
                ?: $left['label_id'] <=> $right['label_id']
    );
    return array_values($byLabel);
}

function ingredientOntologyV3IdentityAnnexResolution(
    PDO $db,
    array $version,
    array $product
): array {
    $normalizedLabel = ingredientOntologyV3NormalizeLabel(
        (string)$product['name']
    );
    $language = 'en';
    if (!empty($product['prepared_food'])) {
        return [
            'status' => 'rejected',
            'reason' => 'prepared_food',
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'attributes' => [],
            'normalized_label' => $normalizedLabel,
            'language' => $language,
        ];
    }
    if ($normalizedLabel === '') {
        return [
            'status' => 'unresolved',
            'reason' => 'empty_label',
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'attributes' => [],
            'normalized_label' => '',
            'language' => $language,
        ];
    }

    $candidates = ingredientOntologyV3IdentityAnnexLabelCandidates(
        $db,
        (int)$version['id'],
        $normalizedLabel,
        $language
    );
    $admissionSource = 'accepted_label';
    $review = null;
    if (!$candidates) {
        $review = ingredientOntologyV3IdentityAnnexReviewedAliases()[
            $normalizedLabel
        ] ?? null;
        if ($review !== null) {
            $candidates = ingredientOntologyV3IdentityAnnexLabelCandidates(
                $db,
                (int)$version['id'],
                (string)$review['target_normalized_label'],
                (string)$review['target_language']
            );
            $candidates = array_values(array_filter(
                $candidates,
                static fn(array $candidate): bool =>
                    (string)$candidate['entity_slug']
                        === (string)$review['target_entity_slug']
                    && (string)$candidate['kind']
                        === (string)$review['target_kind']
            ));
            $admissionSource = 'reviewed_alias';
        }
    }

    $entities = [];
    foreach ($candidates as $candidate) {
        $entities[(int)$candidate['entity_id']] = true;
    }
    if (!$candidates) {
        return [
            'status' => 'unresolved',
            'reason' => 'no_reviewed_exact_alias',
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'attributes' => [],
            'normalized_label' => $normalizedLabel,
            'language' => $language,
        ];
    }
    if (count($entities) !== 1) {
        return [
            'status' => 'rejected',
            'reason' => 'reviewed_alias_collision',
            'admission_source' => 'none',
            'label_id' => null,
            'entity_id' => null,
            'attributes' => [],
            'normalized_label' => $normalizedLabel,
            'language' => $language,
        ];
    }

    $candidate = $candidates[0];
    return [
        'status' => 'accepted',
        'reason' => $admissionSource,
        'admission_source' => $admissionSource,
        'label_id' => (int)$candidate['label_id'],
        'entity_id' => (int)$candidate['entity_id'],
        'entity_slug' => (string)$candidate['entity_slug'],
        'entity_name' => (string)$candidate['entity_name'],
        'attributes' => (array)$candidate['attributes'],
        'normalized_label' => $normalizedLabel,
        'language' => $language,
        'label' => $candidate,
        'review' => $review,
    ];
}

function ingredientOntologyV3IdentityAnnexRefreshProduct(
    PDO $db,
    int $productId,
    ?int $versionId = null
): array {
    if (
        $productId <= 0
        || !ingredientOntologyV3IdentityAnnexTableExists($db)
    ) {
        return [
            'available' => false,
            'accepted' => false,
            'changed' => false,
            'reason' => 'identity_annex_unavailable',
        ];
    }
    $productStmt = $db->prepare("
        SELECT id, name, brand, category, prepared_food
        FROM products
        WHERE id = ?
    ");
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($product === null) {
        throw new InvalidArgumentException(
            'identity annex product is unavailable'
        );
    }
    $version = $versionId !== null
        ? ingredientOntologyV3Version($db, $versionId)
        : ingredientOntologyV3ActiveVersion($db);
    if (
        $version === null
        || (string)$version['status'] !== 'ready'
        || !is_string($version['content_hash'] ?? null)
        || !is_string($version['seal_hash'] ?? null)
    ) {
        $db->prepare("
            DELETE FROM ingredient_ontology_identity_annex
            WHERE product_id = ?
        ")->execute([$productId]);
        return [
            'available' => false,
            'accepted' => false,
            'changed' => true,
            'reason' => 'active_ontology_unavailable',
        ];
    }

    $ownerFingerprint =
        ingredientOntologyV3ProductOwnerFingerprint($product);
    $resolution = ingredientOntologyV3IdentityAnnexResolution(
        $db,
        $version,
        $product
    );
    $persistedNormalizedLabel = mb_substr(
        (string)$resolution['normalized_label'],
        0,
        200,
        'UTF-8'
    );
    $reviewManifestHash =
        ingredientOntologyV3IdentityAnnexReviewManifestHash();
    $attributes = (array)($resolution['attributes'] ?? []);
    ksort($attributes, SORT_STRING);
    $evidence = [
        'resolver_version' =>
            INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
        'review_manifest_hash' => $reviewManifestHash,
        'ontology_version_id' => (int)$version['id'],
        'ontology_content_hash' => (string)$version['content_hash'],
        'ontology_seal_hash' => (string)$version['seal_hash'],
        'product_id' => $productId,
        'owner_fingerprint' => $ownerFingerprint,
        'source_label' => (string)$product['name'],
        'normalized_label' => $persistedNormalizedLabel,
        'language' => (string)$resolution['language'],
        'status' => (string)$resolution['status'],
        'admission_source' =>
            (string)$resolution['admission_source'],
        'label_id' => $resolution['label_id'],
        'entity_id' => $resolution['entity_id'],
        'attributes' => $attributes,
        'review' => $resolution['review'] ?? null,
    ];
    $evidenceHash = ingredientOntologyV3Hash($evidence);
    $previousStmt = $db->prepare("
        SELECT owner_fingerprint, ontology_version_id,
               status, entity_id, evidence_hash
        FROM ingredient_ontology_identity_annex
        WHERE product_id = ?
    ");
    $previousStmt->execute([$productId]);
    $previous = $previousStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $db->prepare("
        INSERT INTO ingredient_ontology_identity_annex (
            product_id, ontology_version_id,
            ontology_content_hash, ontology_seal_hash,
            owner_fingerprint, source_label, normalized_label,
            language, label_id, entity_id, status,
            admission_source, attributes_json,
            resolver_version, review_manifest_hash,
            evidence_hash, reason, created_at, updated_at
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )
        ON CONFLICT(product_id) DO UPDATE SET
            ontology_version_id = excluded.ontology_version_id,
            ontology_content_hash = excluded.ontology_content_hash,
            ontology_seal_hash = excluded.ontology_seal_hash,
            owner_fingerprint = excluded.owner_fingerprint,
            source_label = excluded.source_label,
            normalized_label = excluded.normalized_label,
            language = excluded.language,
            label_id = excluded.label_id,
            entity_id = excluded.entity_id,
            status = excluded.status,
            admission_source = excluded.admission_source,
            attributes_json = excluded.attributes_json,
            resolver_version = excluded.resolver_version,
            review_manifest_hash = excluded.review_manifest_hash,
            evidence_hash = excluded.evidence_hash,
            reason = excluded.reason,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([
        $productId,
        (int)$version['id'],
        (string)$version['content_hash'],
        (string)$version['seal_hash'],
        $ownerFingerprint,
        mb_substr((string)$product['name'], 0, 200, 'UTF-8'),
        $persistedNormalizedLabel,
        (string)$resolution['language'],
        $resolution['label_id'],
        $resolution['entity_id'],
        (string)$resolution['status'],
        (string)$resolution['admission_source'],
        ingredientOntologyV3Json($attributes),
        INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
        $reviewManifestHash,
        $evidenceHash,
        mb_substr((string)$resolution['reason'], 0, 240, 'UTF-8'),
    ]);

    $previousEntityId = $previous !== null
        && $previous['entity_id'] !== null
            ? (int)$previous['entity_id']
            : null;
    $changed = $previous === null
        || (int)$previous['ontology_version_id'] !== (int)$version['id']
        || !hash_equals(
            (string)$previous['owner_fingerprint'],
            $ownerFingerprint
        )
        || (string)$previous['status'] !== (string)$resolution['status']
        || $previousEntityId !== $resolution['entity_id']
        || !hash_equals(
            (string)$previous['evidence_hash'],
            $evidenceHash
        );
    return [
        'available' => true,
        'accepted' => (string)$resolution['status'] === 'accepted',
        'changed' => $changed,
        'product_id' => $productId,
        'owner_fingerprint' => $ownerFingerprint,
        'ontology_version_id' => (int)$version['id'],
        'label_id' => $resolution['label_id'],
        'entity_id' => $resolution['entity_id'],
        'previous_entity_id' => $previousEntityId,
        'entity_slug' => $resolution['entity_slug'] ?? null,
        'attributes' => $attributes,
        'status' => (string)$resolution['status'],
        'source' => (string)$resolution['admission_source'],
        'reason' => (string)$resolution['reason'],
        'evidence_hash' => $evidenceHash,
    ];
}

function ingredientOntologyV3IdentityAnnexMapping(
    PDO $db,
    int $versionId,
    int $productId,
    string $ownerFingerprint
): ?array {
    if (!ingredientOntologyV3IdentityAnnexTableExists($db)) {
        return null;
    }
    $stmt = $db->prepare("
        SELECT annex.id AS annex_id, annex.owner_fingerprint,
               annex.source_label, annex.attributes_json,
               annex.label_id, annex.entity_id,
               annex.admission_source, annex.evidence_hash,
               entity.slug AS entity_slug,
               entity.canonical_name AS entity_name,
               (
                   SELECT occurrence.subject_id
                   FROM ontology_subject_occurrences occurrence
                   WHERE occurrence.owner_type = 'product'
                     AND occurrence.owner_id = annex.product_id
                     AND occurrence.owner_fingerprint =
                         annex.owner_fingerprint
                     AND occurrence.active = 1
                   ORDER BY occurrence.id DESC
                   LIMIT 1
               ) AS subject_id
        FROM ingredient_ontology_identity_annex annex
        JOIN ingredient_ontology_versions version
          ON version.id = annex.ontology_version_id
         AND version.status = 'ready'
         AND version.content_hash = annex.ontology_content_hash
         AND version.seal_hash = annex.ontology_seal_hash
        JOIN ingredient_ontology_entities entity
          ON entity.id = annex.entity_id
         AND entity.ontology_version_id = annex.ontology_version_id
         AND entity.active = 1
         AND entity.entity_kind = 'ingredient'
         AND entity.identity_role = 'identity_leaf'
        JOIN ingredient_ontology_labels label
          ON label.id = annex.label_id
         AND label.ontology_version_id = annex.ontology_version_id
         AND label.entity_id = annex.entity_id
         AND label.review_state = 'accepted'
         AND label.kind IN ('exact_alias', 'attribute_alias')
        WHERE annex.product_id = ?
          AND annex.ontology_version_id = ?
          AND annex.owner_fingerprint = ?
          AND annex.status = 'accepted'
          AND annex.resolver_version = ?
          AND annex.review_manifest_hash = ?
        LIMIT 1
    ");
    $stmt->execute([
        $productId,
        $versionId,
        $ownerFingerprint,
        INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
        ingredientOntologyV3IdentityAnnexReviewManifestHash(),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $attributes = json_decode(
        (string)$row['attributes_json'],
        true
    );
    $attributes = is_array($attributes) ? $attributes : [];
    $normalizedAttributes = [];
    foreach ($attributes as $facet => $value) {
        if (!is_string($facet) || !is_string($value)) {
            continue;
        }
        $normalizedAttributes[$facet] = [
            'value' => $value,
            'is_defining' =>
                ingredientOntologyV3FacetIsDefining($facet),
            'source' => 'reviewed_identity_annex',
        ];
    }
    ksort($normalizedAttributes, SORT_STRING);
    return [
        'mapping_id' => null,
        'annex_id' => (int)$row['annex_id'],
        'owner_fingerprint' => (string)$row['owner_fingerprint'],
        'subject_id' => $row['subject_id'] !== null
            ? (int)$row['subject_id']
            : null,
        'entity_id' => (int)$row['entity_id'],
        'entity_slug' => (string)$row['entity_slug'],
        'entity_name' => (string)$row['entity_name'],
        'status' => 'accepted',
        'confidence' => 1.0,
        'mapping_source' => 'deterministic_identity_annex',
        'source_label' => (string)$row['source_label'],
        'attributes' => $normalizedAttributes,
        'is_staple' => false,
        'label_id' => (int)$row['label_id'],
        'evidence_hash' => (string)$row['evidence_hash'],
        'admission_source' => (string)$row['admission_source'],
    ];
}

function ingredientOntologyV3IdentityAnnexResolvedMapping(
        PDO $db,
        array $version,
        array $product,
        array $resolution
    ): ?array {
        if (
            (string)($version['status'] ?? '') !== 'ready'
            || (string)($resolution['status'] ?? '') !== 'accepted'
            || (int)($resolution['label_id'] ?? 0) <= 0
            || (int)($resolution['entity_id'] ?? 0) <= 0
            || !is_string($version['content_hash'] ?? null)
            || !is_string($version['seal_hash'] ?? null)
        ) {
            return null;
        }
        $ownerFingerprint =
            ingredientOntologyV3ProductOwnerFingerprint($product);
        $subjectStmt = $db->prepare("
            SELECT subject_id
            FROM ontology_subject_occurrences
            WHERE owner_type = 'product'
              AND owner_id = ?
              AND owner_fingerprint = ?
              AND active = 1
            ORDER BY id DESC
            LIMIT 1
        ");
        $subjectStmt->execute([
            (int)$product['id'],
            $ownerFingerprint,
        ]);
        $subjectId = $subjectStmt->fetchColumn();
        $attributes = (array)($resolution['attributes'] ?? []);
        ksort($attributes, SORT_STRING);
        $normalizedAttributes = [];
        foreach ($attributes as $facet => $value) {
            if (!is_string($facet) || !is_string($value)) {
                continue;
            }
            $normalizedAttributes[$facet] = [
                'value' => $value,
                'is_defining' =>
                    ingredientOntologyV3FacetIsDefining($facet),
                'source' => 'reviewed_identity_annex',
            ];
        }
        $evidenceHash = ingredientOntologyV3Hash([
            'resolver_version' =>
                INGREDIENT_ONTOLOGY_IDENTITY_ANNEX_RESOLVER_VERSION,
            'review_manifest_hash' =>
                ingredientOntologyV3IdentityAnnexReviewManifestHash(),
            'ontology_version_id' => (int)$version['id'],
            'ontology_content_hash' => (string)$version['content_hash'],
            'ontology_seal_hash' => (string)$version['seal_hash'],
            'product_id' => (int)$product['id'],
            'owner_fingerprint' => $ownerFingerprint,
            'label_id' => (int)$resolution['label_id'],
            'entity_id' => (int)$resolution['entity_id'],
            'attributes' => $attributes,
            'admission_source' =>
                (string)$resolution['admission_source'],
        ]);
        return [
            'mapping_id' => null,
            'annex_id' => null,
            'owner_fingerprint' => $ownerFingerprint,
            'subject_id' => $subjectId !== false
                ? (int)$subjectId
                : null,
            'entity_id' => (int)$resolution['entity_id'],
            'entity_slug' => (string)$resolution['entity_slug'],
            'entity_name' => (string)$resolution['entity_name'],
            'status' => 'accepted',
            'confidence' => 1.0,
            'mapping_source' => 'deterministic_identity_annex_read',
            'source_label' => (string)$product['name'],
            'attributes' => $normalizedAttributes,
            'is_staple' => false,
            'label_id' => (int)$resolution['label_id'],
            'evidence_hash' => $evidenceHash,
            'admission_source' =>
                (string)$resolution['admission_source'],
        ];
}
