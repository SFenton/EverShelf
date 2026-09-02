<?php
declare(strict_types=1);

final class IngredientOntologyV3MatcherContext {
    public int $versionId;
    public array $entities = [];
    public array $facets = [];
    public array $defaults = [];
    public array $relations = [];
    public array $ancestry = [];
    public array $pairConstraints = [];
    public int $identityExtensionRevision = 0;
    public string $identityExtensionHash = '';

    public function __construct(
        PDO $db,
        int $versionId,
        ?int $identityExtensionRevision = null
    ) {
        $this->versionId = $versionId;
        $version = ingredientOntologyV3Version($db, $versionId);
        if ($version === null) {
            throw new InvalidArgumentException('ontology version not found');
        }
        $stmt = $db->prepare("
            SELECT id, slug, canonical_name, entity_kind, identity_role
            FROM ingredient_ontology_entities
            WHERE ontology_version_id = ? AND active = 1
        ");
        $stmt->execute([$versionId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->entities[(int)$row['id']] = [
                'id' => (int)$row['id'],
                'slug' => (string)$row['slug'],
                'name' => (string)$row['canonical_name'],
                'kind' => (string)$row['entity_kind'],
                'identity_role' => (string)$row['identity_role'],
            ];
        }
        $snapshot = ingredientOntologyV3IdentityExtensionSnapshot(
            $db,
            $versionId
        );
        $this->identityExtensionRevision =
            $identityExtensionRevision !== null
                ? max(0, $identityExtensionRevision)
                : (int)$snapshot['revision'];
        if ($this->identityExtensionRevision === 0) {
            $this->identityExtensionHash =
                ingredientOntologyV3IdentityExtensionZeroHash();
        } elseif (
            $this->identityExtensionRevision === (int)$snapshot['revision']
        ) {
            $this->identityExtensionHash = (string)$snapshot['hash'];
        } else {
            $extensionHash = $db->prepare("
                SELECT content_hash
                FROM ingredient_ontology_identity_extension_entities
                WHERE ontology_version_id = ?
                  AND created_revision = ?
            ");
            $extensionHash->execute([
                $versionId,
                $this->identityExtensionRevision,
            ]);
            $hash = $extensionHash->fetchColumn();
            if ($hash === false) {
                throw new RuntimeException(
                    'identity extension revision is unavailable'
                );
            }
            $this->identityExtensionHash = (string)$hash;
        }
        if (
            $this->identityExtensionRevision > 0
            && ingredientOntologyV3TableExists(
                $db,
                'ingredient_ontology_identity_extension_entities'
            )
        ) {
            $extensions = $db->prepare("
                SELECT id, slug, canonical_name
                FROM ingredient_ontology_identity_extension_entities
                WHERE ontology_version_id = ?
                  AND created_revision <= ?
                  AND status = 'active'
                ORDER BY created_revision
            ");
            $extensions->execute([
                $versionId,
                $this->identityExtensionRevision,
            ]);
            while ($row = $extensions->fetch(PDO::FETCH_ASSOC)) {
                $runtimeEntityId =
                    ingredientOntologyV3IdentityExtensionRuntimeEntityId(
                        (int)$row['id']
                    );
                $this->entities[$runtimeEntityId] = [
                    'id' => $runtimeEntityId,
                    'slug' => (string)$row['slug'],
                    'name' => (string)$row['canonical_name'],
                    'kind' => 'ingredient',
                    'identity_role' => 'identity_leaf',
                    'extension' => true,
                ];
            }
        }
        $facetMap = ingredientOntologyV3FacetMap($db, $versionId);
        foreach ($facetMap as $facet => $definition) {
            $this->facets[$facet] = [
                'hard' => !empty($definition['hard']),
            ];
        }
        $defaults = $db->prepare("
            SELECT d.entity_id, f.facet_key, fv.value_key, d.is_defining
            FROM ingredient_ontology_entity_defaults d
            JOIN ingredient_ontology_facets f ON f.id = d.facet_id
            JOIN ingredient_ontology_facet_values fv
              ON fv.id = d.facet_value_id
            WHERE d.ontology_version_id = ?
        ");
        $defaults->execute([$versionId]);
        while ($row = $defaults->fetch(PDO::FETCH_ASSOC)) {
            $this->defaults[(int)$row['entity_id']][(string)$row['facet_key']] = [
                'value' => (string)$row['value_key'],
                'is_defining' => !empty($row['is_defining']),
                'source' => 'entity_default',
            ];
        }
        $relations = $db->prepare("
            SELECT from_entity_id, to_entity_id, relation, direction,
                   satisfies_required, confidence, provenance, review_state,
                   semantics_json
            FROM ingredient_ontology_relations
            WHERE ontology_version_id = ?
              AND review_state = 'accepted'
            ORDER BY id
        ");
        $relations->execute([$versionId]);
        while ($row = $relations->fetch(PDO::FETCH_ASSOC)) {
            $from = (int)$row['from_entity_id'];
            $to = (int)$row['to_entity_id'];
            $relation = [
                'from_entity_id' => $from,
                'to_entity_id' => $to,
                'relation' => (string)$row['relation'],
                'direction' => (string)$row['direction'],
                'satisfies_required' => !empty($row['satisfies_required']),
                'confidence' => (float)$row['confidence'],
                'provenance' => (string)$row['provenance'],
                'semantics' => json_decode(
                    (string)$row['semantics_json'],
                    true
                ) ?: [],
            ];
            $this->relations[$from][$to][] = $relation;
            if ($relation['direction'] === 'bidirectional') {
                $reverse = $relation;
                $reverse['from_entity_id'] = $to;
                $reverse['to_entity_id'] = $from;
                $this->relations[$to][$from][] = $reverse;
            }
        }
        $this->buildAncestry();
        if (ingredientOntologyV3TableExists(
            $db,
            'ingredient_ontology_pair_constraints'
        )) {
            $constraints = $db->prepare("
                SELECT subject_id, target_owner_fingerprint,
                       constraint_kind, constraint_epoch
                FROM ingredient_ontology_pair_constraints
                WHERE ontology_version_id = ?
                ORDER BY constraint_epoch
            ");
            $constraints->execute([$versionId]);
            while ($row = $constraints->fetch(PDO::FETCH_ASSOC)) {
                $this->pairConstraints[
                    (int)$row['subject_id']
                ][
                    (string)$row['target_owner_fingerprint']
                ] = (string)$row['constraint_kind'];
            }
        }
    }

    private function buildAncestry(): void {
        $parents = [];
        foreach ($this->relations as $from => $targets) {
            foreach ($targets as $to => $relations) {
                foreach ($relations as $relation) {
                    if ($relation['relation'] === 'is_a') {
                        $parents[$from][$to] = $relation;
                    }
                }
            }
        }
        foreach (array_keys($this->entities) as $start) {
            $queue = [];
            foreach ($parents[$start] ?? [] as $parent => $relation) {
                $queue[] = [
                    'entity_id' => (int)$parent,
                    'depth' => 1,
                    'all_satisfy' => $relation['satisfies_required'],
                    'confidence' => $relation['confidence'],
                    'path' => [$start, (int)$parent],
                ];
            }
            while ($queue) {
                $path = array_shift($queue);
                $ancestor = $path['entity_id'];
                $current = $this->ancestry[$start][$ancestor] ?? null;
                if (
                    $current !== null
                    && $current['depth'] <= $path['depth']
                    && (
                        $current['all_satisfy']
                        || !$path['all_satisfy']
                    )
                ) {
                    continue;
                }
                $this->ancestry[$start][$ancestor] = $path;
                if ($path['depth'] >= 32) {
                    continue;
                }
                foreach ($parents[$ancestor] ?? [] as $parent => $relation) {
                    if (in_array((int)$parent, $path['path'], true)) {
                        continue;
                    }
                    $queue[] = [
                        'entity_id' => (int)$parent,
                        'depth' => $path['depth'] + 1,
                        'all_satisfy' => $path['all_satisfy']
                            && $relation['satisfies_required'],
                        'confidence' => min(
                            $path['confidence'],
                            $relation['confidence']
                        ),
                        'path' => array_merge($path['path'], [(int)$parent]),
                    ];
                }
            }
        }
    }
}

function ingredientOntologyV3LoadMapping(
    PDO $db,
    int $versionId,
    string $ownerType,
    int $ownerId
): ?array {
    $stmt = $db->prepare("
        SELECT m.*, e.slug AS entity_slug, e.canonical_name AS entity_name,
               e.entity_kind, e.identity_role
        FROM ingredient_ontology_mappings m
        LEFT JOIN ingredient_ontology_entities e ON e.id = m.entity_id
        WHERE m.ontology_version_id = ?
          AND m.owner_type = ?
          AND m.owner_id = ?
    ");
    $stmt->execute([$versionId, $ownerType, $ownerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $attributes = [];
    $attr = $db->prepare("
        SELECT f.facet_key, fv.value_key, a.is_defining, a.provenance
        FROM ingredient_ontology_mapping_attributes a
        JOIN ingredient_ontology_facets f ON f.id = a.facet_id
        JOIN ingredient_ontology_facet_values fv
          ON fv.id = a.facet_value_id
        WHERE a.mapping_id = ?
        ORDER BY f.facet_key
    ");
    $attr->execute([(int)$row['id']]);
    while ($attribute = $attr->fetch(PDO::FETCH_ASSOC)) {
        $attributes[(string)$attribute['facet_key']] = [
            'value' => (string)$attribute['value_key'],
            'is_defining' => !empty($attribute['is_defining']),
            'source' => (string)$attribute['provenance'],
        ];
    }
    $relations = [];
    $rel = $db->prepare("
        SELECT r.to_entity_id, r.relation, r.direction, r.confidence,
               r.provenance, e.slug AS to_slug,
               e.canonical_name AS to_name
        FROM ingredient_ontology_mapping_relations r
        JOIN ingredient_ontology_entities e ON e.id = r.to_entity_id
        WHERE r.mapping_id = ? AND r.review_state = 'accepted'
        ORDER BY r.id
    ");
    $rel->execute([(int)$row['id']]);
    while ($relation = $rel->fetch(PDO::FETCH_ASSOC)) {
        $relations[] = [
            'to_entity_id' => (int)$relation['to_entity_id'],
            'to_slug' => (string)$relation['to_slug'],
            'to_name' => (string)$relation['to_name'],
            'relation' => (string)$relation['relation'],
            'direction' => (string)$relation['direction'],
            'confidence' => (float)$relation['confidence'],
            'provenance' => (string)$relation['provenance'],
        ];
    }
    $subjectId = null;
    if (ingredientOntologyV3TableExists(
        $db,
        'ontology_subject_occurrences'
    )) {
        $subject = $db->prepare("
            SELECT subject_id
            FROM ontology_subject_occurrences
            WHERE owner_type = ?
              AND owner_id = ?
              AND owner_fingerprint = ?
              AND active = 1
            ORDER BY id DESC
            LIMIT 1
        ");
        $subject->execute([
            (string)$row['owner_type'],
            (int)$row['owner_id'],
            (string)$row['owner_fingerprint'],
        ]);
        $value = $subject->fetchColumn();
        $subjectId = $value === false ? null : (int)$value;
    }
    return [
        'mapping_id' => (int)$row['id'],
        'ontology_version_id' => (int)$row['ontology_version_id'],
        'owner_type' => (string)$row['owner_type'],
        'owner_id' => (int)$row['owner_id'],
        'owner_fingerprint' => (string)$row['owner_fingerprint'],
        'subject_id' => $subjectId,
        'source_label' => (string)$row['source_label'],
        'normalized_label' => (string)$row['normalized_label'],
        'language' => (string)$row['language'],
        'entity_id' => $row['entity_id'] !== null
            ? (int)$row['entity_id']
            : null,
        'entity_slug' => $row['entity_slug'] !== null
            ? (string)$row['entity_slug']
            : null,
        'entity_name' => $row['entity_name'] !== null
            ? (string)$row['entity_name']
            : null,
        'entity_kind' => $row['entity_kind'] !== null
            ? (string)$row['entity_kind']
            : null,
        'identity_role' => $row['identity_role'] !== null
            ? (string)$row['identity_role']
            : null,
        'status' => (string)$row['status'],
        'confidence' => (float)$row['confidence'],
        'mapping_source' => (string)$row['mapping_source'],
        'is_staple' => !empty($row['is_staple']),
        'attributes' => $attributes,
        'assertion_relations' => $relations,
    ];
}

function ingredientOntologyV3NormalizeAssertion(
    IngredientOntologyV3MatcherContext $context,
    array $assertion
): array {
    $entityId = isset($assertion['entity_id'])
        ? (int)$assertion['entity_id']
        : null;
    $attributes = $context->defaults[$entityId] ?? [];
    foreach (($assertion['attributes'] ?? []) as $facet => $attribute) {
        if (is_string($attribute)) {
            $attributes[$facet] = [
                'value' => $attribute,
                'is_defining' => ingredientOntologyV3FacetIsDefining($facet),
                'source' => 'assertion',
            ];
            continue;
        }
        if (is_array($attribute) && isset($attribute['value'])) {
            $attributes[$facet] = [
                'value' => (string)$attribute['value'],
                'is_defining' => array_key_exists('is_defining', $attribute)
                    ? (bool)$attribute['is_defining']
                    : ingredientOntologyV3FacetIsDefining($facet),
                'source' => (string)($attribute['source'] ?? 'assertion'),
            ];
        }
    }
    ksort($attributes, SORT_STRING);
    $entity = $entityId !== null
        ? ($context->entities[$entityId] ?? null)
        : null;
    return [
        'mapping_id' => isset($assertion['mapping_id'])
            ? (int)$assertion['mapping_id']
            : null,
        'entity_id' => $entityId,
        'entity' => $entity,
        'status' => (string)($assertion['status'] ?? 'accepted'),
        'confidence' => max(
            0.0,
            min(1.0, (float)($assertion['confidence'] ?? 1.0))
        ),
        'mapping_source' => (string)(
            $assertion['mapping_source'] ?? 'manual'
        ),
        'source_label' => (string)($assertion['source_label'] ?? ''),
        'owner_fingerprint' => (string)(
            $assertion['owner_fingerprint'] ?? ''
        ),
        'subject_id' => isset($assertion['subject_id'])
            ? (int)$assertion['subject_id']
            : null,
        'attributes' => $attributes,
        'assertion_relations' => $assertion['assertion_relations'] ?? [],
    ];
}

function ingredientOntologyV3AttributeCompatibility(
    IngredientOntologyV3MatcherContext $context,
    array $required,
    array $inventory
): array {
    $facets = array_values(array_unique(array_merge(
        array_keys($required['attributes']),
        array_keys($inventory['attributes'])
    )));
    sort($facets, SORT_STRING);
    $conflicts = [];
    $unknown = [];
    $differences = [];
    foreach ($facets as $facet) {
        $requiredAttribute = $required['attributes'][$facet] ?? null;
        $inventoryAttribute = $inventory['attributes'][$facet] ?? null;
        $hard = !empty($context->facets[$facet]['hard'])
            || !empty($requiredAttribute['is_defining'])
            || !empty($inventoryAttribute['is_defining']);
        if ($requiredAttribute !== null && $inventoryAttribute !== null) {
            if (
                (string)$requiredAttribute['value']
                !== (string)$inventoryAttribute['value']
            ) {
                $differences[$facet] = [
                    'required' => (string)$requiredAttribute['value'],
                    'inventory' => (string)$inventoryAttribute['value'],
                    'is_defining' => $hard,
                ];
                if ($hard) {
                    $conflicts[$facet] = $differences[$facet];
                }
            }
            continue;
        }
        if ($hard) {
            $unknown[$facet] = [
                'required' => $requiredAttribute['value'] ?? null,
                'inventory' => $inventoryAttribute['value'] ?? null,
                'is_defining' => true,
            ];
        }
    }
    return [
        'compatible' => !$conflicts && !$unknown,
        'conflicts' => $conflicts,
        'unknown' => $unknown,
        'differences' => $differences,
    ];
}

function ingredientOntologyV3ConflictOutcome(array $conflicts): string {
    $facet = (string)(array_key_first($conflicts) ?? 'attribute');
    return match ($facet) {
        'form' => 'different_form',
        'processing' => 'different_processing',
        'cut' => 'different_cut',
        'bone' => 'different_bone',
        'skin' => 'different_skin',
        'refinement' => 'different_refinement',
        'variety' => 'different_variety',
        'state' => 'different_state',
        'species' => 'different_species',
        'saltedness' => 'different_saltedness',
        'sweetening' => 'different_sweetening',
        'fat_content' => 'different_fat_content',
        'cream_class' => 'different_cream_class',
        'egg_part' => 'different_egg_part',
        default => 'attribute_mismatch',
    };
}

function ingredientOntologyV3DirectRelation(
    IngredientOntologyV3MatcherContext $context,
    int $from,
    int $to,
    array $types
): ?array {
    foreach ($context->relations[$from][$to] ?? [] as $relation) {
        if (in_array($relation['relation'], $types, true)) {
            return $relation;
        }
    }
    foreach ($context->relations[$to][$from] ?? [] as $relation) {
        if (in_array($relation['relation'], $types, true)) {
            $relation['reverse_lookup'] = true;
            return $relation;
        }
    }
    return null;
}

function ingredientOntologyV3MatchWithContext(
    IngredientOntologyV3MatcherContext $context,
    array $requiredAssertion,
    array $inventoryAssertion
): array {
    $required = ingredientOntologyV3NormalizeAssertion(
        $context,
        $requiredAssertion
    );
    $inventory = ingredientOntologyV3NormalizeAssertion(
        $context,
        $inventoryAssertion
    );
    $constraint = (
        $required['subject_id'] !== null
        && $inventory['owner_fingerprint'] !== ''
    )
        ? (
            $context->pairConstraints[
                $required['subject_id']
            ][
                $inventory['owner_fingerprint']
            ] ?? null
        )
        : null;
    if ($constraint === 'must_not_equal') {
        return [
            'outcome' => 'explicit_negative_constraint',
            'score' => 0.0,
            'confidence' => 1.0,
            'satisfies_required' => false,
            'relationship' => 'constraint_deny',
            'required' => $required,
            'inventory' => $inventory,
            'conflicts' => [],
            'unknown_attributes' => [],
            'attribute_differences' => [],
            'reason' => 'latest_exact_negative_constraint',
        ];
    }
    $deniedSources = [
        'taxonomy_rule',
        'taxonomy_rule_evidence',
        'quarantined_model_evidence',
        'model',
        'model_proposal',
        'lexical',
        'normalized_name',
        'foodon_hierarchy',
    ];
    foreach ([
        'required' => $required,
        'inventory' => $inventory,
    ] as $side => $assertion) {
        if (
            $assertion['status'] !== 'accepted'
            || in_array($assertion['mapping_source'], $deniedSources, true)
        ) {
            return [
                'outcome' => match ($assertion['status']) {
                    'ambiguous' => 'ambiguous',
                    'rejected' => 'rejected',
                    'unresolved' => 'unresolved',
                    default => 'candidate_evidence',
                },
                'score' => 0.0,
                'confidence' => 0.0,
                'satisfies_required' => false,
                'relationship' => 'none',
                'required' => $required,
                'inventory' => $inventory,
                'conflicts' => [],
                'unknown_attributes' => [],
                'attribute_differences' => [],
                'reason' => "{$side}_mapping_is_not_accepted_identity",
            ];
        }
    }
    if (
        $required['entity_id'] === null
        || $inventory['entity_id'] === null
        || $required['entity'] === null
        || $inventory['entity'] === null
    ) {
        return [
            'outcome' => 'unresolved',
            'score' => 0.0,
            'confidence' => 0.0,
            'satisfies_required' => false,
            'relationship' => 'none',
            'required' => $required,
            'inventory' => $inventory,
            'conflicts' => [],
            'unknown_attributes' => [],
            'attribute_differences' => [],
            'reason' => 'entity_is_missing_or_inactive',
        ];
    }
    foreach ([
        'required' => $required,
        'inventory' => $inventory,
    ] as $side => $assertion) {
        $role = (string)(
            $assertion['entity']['identity_role'] ?? 'identity_leaf'
        );
        if ($role === 'structural_category') {
            return [
                'outcome' => 'structural_category',
                'score' => 0.0,
                'confidence' => 0.0,
                'satisfies_required' => false,
                'relationship' => 'none',
                'required' => $required,
                'inventory' => $inventory,
                'conflicts' => [],
                'unknown_attributes' => [],
                'attribute_differences' => [],
                'reason' =>
                    "{$side}_structural_category_is_not_identity",
            ];
        }
        if (
            $role === 'staple_class'
            && (
                empty($requiredAssertion['is_staple'])
                || empty($inventoryAssertion['is_staple'])
            )
        ) {
            return [
                'outcome' => 'staple_path_required',
                'score' => 0.0,
                'confidence' => 0.0,
                'satisfies_required' => false,
                'relationship' => 'none',
                'required' => $required,
                'inventory' => $inventory,
                'conflicts' => [],
                'unknown_attributes' => [],
                'attribute_differences' => [],
                'reason' => 'staple_class_requires_explicit_staple_path',
            ];
        }
    }
    $compatibility = ingredientOntologyV3AttributeCompatibility(
        $context,
        $required,
        $inventory
    );
    if ($compatibility['conflicts']) {
        return [
            'outcome' => ingredientOntologyV3ConflictOutcome(
                $compatibility['conflicts']
            ),
            'score' => 0.0,
            'confidence' => min(
                $required['confidence'],
                $inventory['confidence']
            ),
            'satisfies_required' => false,
            'relationship' => $required['entity_id'] === $inventory['entity_id']
                ? 'same_entity'
                : 'related_entity',
            'required' => $required,
            'inventory' => $inventory,
            'conflicts' => $compatibility['conflicts'],
            'unknown_attributes' => $compatibility['unknown'],
            'attribute_differences' => $compatibility['differences'],
            'reason' => 'defining_attribute_conflict',
        ];
    }
    if ($compatibility['unknown']) {
        return [
            'outcome' => 'uncertain',
            'score' => 0.0,
            'confidence' => min(
                $required['confidence'],
                $inventory['confidence']
            ),
            'satisfies_required' => false,
            'relationship' => $required['entity_id'] === $inventory['entity_id']
                ? 'same_entity'
                : 'related_entity',
            'required' => $required,
            'inventory' => $inventory,
            'conflicts' => [],
            'unknown_attributes' => $compatibility['unknown'],
            'attribute_differences' => $compatibility['differences'],
            'reason' => 'defining_attribute_unknown',
        ];
    }

    $requiredId = $required['entity_id'];
    $inventoryId = $inventory['entity_id'];
    $baseConfidence = min(
        $required['confidence'],
        $inventory['confidence']
    );
    if ($requiredId === $inventoryId) {
        return [
            'outcome' => 'exact',
            'score' => 1.0,
            'confidence' => $baseConfidence,
            'satisfies_required' => true,
            'relationship' => 'same_entity',
            'required' => $required,
            'inventory' => $inventory,
            'conflicts' => [],
            'unknown_attributes' => [],
            'attribute_differences' => $compatibility['differences'],
            'reason' => 'same_entity_and_compatible_defining_attributes',
        ];
    }

    $equivalent = ingredientOntologyV3DirectRelation(
        $context,
        $requiredId,
        $inventoryId,
        ['equivalent_to']
    );
    if ($equivalent !== null) {
        return [
            'outcome' => 'reviewed_equivalent',
            'score' => 0.0,
            'confidence' => min(
                $baseConfidence,
                (float)$equivalent['confidence']
            ),
            'satisfies_required' => false,
            'relationship' => 'equivalent_to',
            'relationship_detail' => $equivalent,
            'required' => $required,
            'inventory' => $inventory,
            'conflicts' => [],
            'unknown_attributes' => [],
            'attribute_differences' => $compatibility['differences'],
            'reason' =>
                'equivalence_is_evidence_until_canonical_identity_is_shared',
        ];
    }

    $variant = ingredientOntologyV3DirectRelation(
        $context,
        $requiredId,
        $inventoryId,
        ['variant_of']
    );
    if ($variant !== null) {
        return [
            'outcome' => 'compatible_variant',
            'score' => 0.82,
            'confidence' => min(
                $baseConfidence,
                (float)$variant['confidence']
            ),
            'satisfies_required' => false,
            'relationship' => 'variant_of',
            'relationship_detail' => $variant,
            'required' => $required,
            'inventory' => $inventory,
            'conflicts' => [],
            'unknown_attributes' => [],
            'attribute_differences' => $compatibility['differences'],
            'reason' => 'variant_is_visible_but_not_identity',
        ];
    }

    $substitute = ingredientOntologyV3DirectRelation(
        $context,
        $requiredId,
        $inventoryId,
        ['substitutes_for']
    );
    if ($substitute !== null) {
        return [
            'outcome' => 'possible_substitute',
            'score' => 0.60,
            'confidence' => min(
                $baseConfidence,
                (float)$substitute['confidence']
            ),
            'satisfies_required' => false,
            'relationship' => 'substitutes_for',
            'relationship_detail' => $substitute,
            'required' => $required,
            'inventory' => $inventory,
            'conflicts' => [],
            'unknown_attributes' => [],
            'attribute_differences' => $compatibility['differences'],
            'reason' => 'substitutes_never_make_a_recipe_auto_cookable',
        ];
    }

    $descendant = $context->ancestry[$inventoryId][$requiredId] ?? null;
    if ($descendant !== null) {
        $allowed = false;
        return [
            'outcome' => $allowed
                ? 'reviewed_descendant'
                : 'broader_requirement_evidence',
            'score' => $allowed ? 0.88 : 0.0,
            'confidence' => min(
                $baseConfidence,
                (float)$descendant['confidence']
            ),
            'satisfies_required' => $allowed,
            'relationship' => 'pantry_descendant',
            'relationship_detail' => $descendant,
            'required' => $required,
            'inventory' => $inventory,
            'conflicts' => [],
            'unknown_attributes' => [],
            'attribute_differences' => $compatibility['differences'],
            'reason' => $allowed
                ? 'every_reviewed_is_a_edge_explicitly_allows_satisfaction'
                : 'is_a_ancestry_is_evidence_not_identity',
        ];
    }
    $ancestor = $context->ancestry[$requiredId][$inventoryId] ?? null;
    if ($ancestor !== null) {
        $allowed = false;
        return [
            'outcome' => $allowed
                ? 'reviewed_generalization'
                : 'pantry_ancestor',
            'score' => $allowed ? 0.72 : 0.0,
            'confidence' => min(
                $baseConfidence,
                (float)$ancestor['confidence']
            ),
            'satisfies_required' => $allowed,
            'relationship' => 'pantry_ancestor',
            'relationship_detail' => $ancestor,
            'required' => $required,
            'inventory' => $inventory,
            'conflicts' => [],
            'unknown_attributes' => [],
            'attribute_differences' => $compatibility['differences'],
            'reason' => $allowed
                ? 'reviewed_generalization_explicitly_allows_satisfaction'
                : 'broader_inventory_entity_never_implies_specific_identity',
        ];
    }

    $nonIdentity = ingredientOntologyV3DirectRelation(
        $context,
        $requiredId,
        $inventoryId,
        ['component_of', 'derived_from']
    );
    if ($nonIdentity !== null) {
        return [
            'outcome' => 'non_identity_relation',
            'score' => 0.0,
            'confidence' => min(
                $baseConfidence,
                (float)$nonIdentity['confidence']
            ),
            'satisfies_required' => false,
            'relationship' => (string)$nonIdentity['relation'],
            'relationship_detail' => $nonIdentity,
            'required' => $required,
            'inventory' => $inventory,
            'conflicts' => [],
            'unknown_attributes' => [],
            'attribute_differences' => $compatibility['differences'],
            'reason' => 'component_and_derivation_relations_are_not_identity',
        ];
    }
    return [
        'outcome' => 'no_identity_match',
        'score' => 0.0,
        'confidence' => 0.0,
        'satisfies_required' => false,
        'relationship' => 'none',
        'required' => $required,
        'inventory' => $inventory,
        'conflicts' => [],
        'unknown_attributes' => [],
        'attribute_differences' => $compatibility['differences'],
        'reason' => 'entities_are_not_identity_compatible',
    ];
}

function ingredientOntologyV3Match(
    PDO $db,
    int $versionId,
    array $requiredAssertion,
    array $inventoryAssertion
): array {
    $context = new IngredientOntologyV3MatcherContext($db, $versionId);
    return ingredientOntologyV3MatchWithContext(
        $context,
        $requiredAssertion,
        $inventoryAssertion
    );
}
