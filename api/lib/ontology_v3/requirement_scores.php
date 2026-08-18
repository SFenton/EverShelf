<?php
declare(strict_types=1);

function ingredientOntologyV3RequirementScoringConfiguration(): array {
    return [
        'scoring_model' =>
            INGREDIENT_ONTOLOGY_V3_REQUIREMENT_SCORING_MODEL,
        'requirement_model' => INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
        'quantity_sufficiency_gate' => false,
        'quantities_are_display_only' => true,
        'source_and_ranking_joined' => false,
    ];
}

function ingredientOntologyV3RequirementScoringConfigHash(): string {
    return ingredientOntologyV3Hash(
        ingredientOntologyV3RequirementScoringConfiguration()
    );
}

function ingredientOntologyV3RequirementShadowCleanup(
    PDO $db,
    int $ontologyVersionId,
    bool $exclusiveBuildLockHeld = false
): ?string {
    try {
        recipeScoreFailAbandonedBuilds($db);
        recipeScorePruneRevisions($db);
        ingredientOntologyV3PruneRequirementRevisions(
            $db,
            $ontologyVersionId,
            INGREDIENT_ONTOLOGY_V3_REQUIREMENT_READY_RETENTION,
            $exclusiveBuildLockHeld
        );
        return null;
    } catch (Throwable $e) {
        return mb_substr($e->getMessage(), 0, 500, 'UTF-8');
    }
}

function ingredientOntologyV3RequirementScoreRevision(
    PDO $db,
    int $revisionId
): ?array {
    $revision = recipeScoreRevision($db, $revisionId);
    if (
        $revision === null
        || (string)($revision['scoring_model'] ?? '')
            !== INGREDIENT_ONTOLOGY_V3_REQUIREMENT_SCORING_MODEL
        || ($revision['requirement_revision_id'] ?? null) === null
    ) {
        return null;
    }
    return $revision;
}

function ingredientOntologyV3SelectRequirementParityBaseline(
    PDO $db,
    int $ontologyVersionId,
    array $state,
    string $inventoryFingerprint,
    string $catalogFingerprint,
    int $catalogMaxId,
    int $catalogCount
): ?int {
    $ingredientCount = (int)$db->query("
        SELECT COUNT(*)
        FROM recipe_ingredients ri
        JOIN recipe_catalog c ON c.id = ri.recipe_id
        WHERE c.deleted_at IS NULL
    ")->fetchColumn();
    $stmt = $db->prepare("
        SELECT r.id
        FROM recipe_score_revisions r
        WHERE r.ontology_version_id = ?
          AND r.scoring_model = ?
          AND r.status = 'ready'
          AND r.requirement_revision_id IS NULL
          AND r.inventory_revision = ?
          AND r.catalog_revision = ?
          AND r.inventory_fingerprint = ?
          AND r.catalog_fingerprint = ?
          AND r.catalog_max_id = ?
          AND r.recipe_count = ?
          AND r.score_date = ?
          AND r.scoring_config_hash = ?
        ORDER BY r.completed_at DESC, r.id DESC
    ");
    $stmt->execute([
        $ontologyVersionId,
        INGREDIENT_ONTOLOGY_V3_SCORING_MODEL,
        $state['inventory_revision'],
        $state['catalog_revision'],
        $inventoryFingerprint,
        $catalogFingerprint,
        $catalogMaxId,
        $catalogCount,
        date('Y-m-d'),
        ingredientOntologyV3ScoringConfigHash(),
    ]);
    $scoreCount = $db->prepare("
        SELECT COUNT(*) FROM recipe_inventory_scores
        WHERE score_revision_id = ?
    ");
    $matchCount = $db->prepare("
        SELECT COUNT(*) FROM ingredient_ontology_shadow_matches
        WHERE score_revision_id = ?
    ");
    while ($id = $stmt->fetchColumn()) {
        $id = (int)$id;
        $scoreCount->execute([$id]);
        $matchCount->execute([$id]);
        if (
            (int)$scoreCount->fetchColumn() === $catalogCount
            && (int)$matchCount->fetchColumn() === $ingredientCount
        ) {
            return $id;
        }
    }
    return null;
}

function ingredientOntologyV3LoadRequirementBatch(
    PDO $db,
    int $requirementRevisionId,
    array $recipeIds
): array {
    if (!$recipeIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($recipeIds), '?'));
    $state = $db->prepare("
        SELECT s.recipe_id, s.basis, s.complete, s.source_row_count,
               s.projected_member_count, s.projected_requirement_count,
               s.recipe_fingerprint, s.evidence_json,
               c.primary_connector,
               COALESCE(us.favorite, 0) AS favorite, us.rating
        FROM ingredient_ontology_requirement_recipe_states s
        JOIN recipe_catalog c ON c.id = s.recipe_id
        LEFT JOIN recipe_user_state us ON us.recipe_id = s.recipe_id
        WHERE s.requirement_revision_id = ?
          AND s.recipe_id IN ({$placeholders})
        ORDER BY s.recipe_id
    ");
    $state->execute(array_merge([$requirementRevisionId], $recipeIds));
    $recipes = [];
    while ($row = $state->fetch(PDO::FETCH_ASSOC)) {
        $recipeId = (int)$row['recipe_id'];
        $recipes[$recipeId] = [
            'id' => $recipeId,
            'basis' => (string)$row['basis'],
            'complete' => !empty($row['complete']),
            'source_row_count' => (int)$row['source_row_count'],
            'projected_member_count' =>
                (int)$row['projected_member_count'],
            'projected_requirement_count' =>
                (int)$row['projected_requirement_count'],
            'recipe_fingerprint' => (string)$row['recipe_fingerprint'],
            'primary_connector' => (string)$row['primary_connector'],
            'favorite' => !empty($row['favorite']),
            'rating' => $row['rating'] !== null
                ? (int)$row['rating']
                : null,
            'requirements' => [],
        ];
    }
    $requirements = $db->prepare("
        SELECT id, recipe_id, requirement_key, basis, entity_id,
               mapping_status, mapping_source, confidence,
               identity_basis, attributes_json, defining_signature,
               requiredness, is_staple, contributor_count,
               provider_ref_count, quantity_audit_state, evidence_json
        FROM ingredient_ontology_recipe_requirements
        WHERE requirement_revision_id = ?
          AND recipe_id IN ({$placeholders})
        ORDER BY recipe_id, id
    ");
    $requirements->execute(array_merge(
        [$requirementRevisionId],
        $recipeIds
    ));
    while ($row = $requirements->fetch(PDO::FETCH_ASSOC)) {
        $recipeId = (int)$row['recipe_id'];
        if (!isset($recipes[$recipeId])) {
            continue;
        }
        $attributes = json_decode(
            (string)$row['attributes_json'],
            true
        );
        if (!is_array($attributes)) {
            $attributes = [];
        }
        $recipes[$recipeId]['requirements'][] = [
            'id' => (int)$row['id'],
            'requirement_key' => (string)$row['requirement_key'],
            'basis' => (string)$row['basis'],
            'entity_id' => $row['entity_id'] !== null
                ? (int)$row['entity_id']
                : null,
            'status' => (string)$row['mapping_status'],
            'mapping_source' => (string)$row['mapping_source'],
            'confidence' => (float)$row['confidence'],
            'identity_basis' => (string)$row['identity_basis'],
            'attributes' => $attributes,
            'defining_signature' => (string)$row['defining_signature'],
            'requiredness' => (string)$row['requiredness'],
            'is_staple' => !empty($row['is_staple']),
            'contributor_count' => (int)$row['contributor_count'],
            'provider_ref_count' => (int)$row['provider_ref_count'],
            'quantity_audit_state' =>
                (string)$row['quantity_audit_state'],
        ];
    }
    return $recipes;
}

function ingredientOntologyV3RequirementAssertion(array $requirement): array {
    $attributes = [];
    foreach ($requirement['attributes'] as $facet => $value) {
        if (!is_string($facet) || !is_string($value)) {
            continue;
        }
        $attributes[$facet] = [
            'value' => $value,
            'is_defining' =>
                ingredientOntologyV3FacetIsDefining($facet),
            'source' => 'immutable_requirement_snapshot',
        ];
    }
    return [
        'mapping_id' => null,
        'entity_id' => $requirement['entity_id'],
        'status' => $requirement['status'],
        'confidence' => $requirement['confidence'],
        'mapping_source' => $requirement['mapping_source'],
        'source_label' => '',
        'attributes' => $attributes,
    ];
}

function ingredientOntologyV3ScoreRequirementRecipe(
    IngredientOntologyV3MatcherContext $context,
    array $recipe,
    array $inventory,
    array &$candidateCache
): array {
    $requiredCount = 0;
    $matchedRequired = 0;
    $missingRequired = 0;
    $uncertainRequired = $recipe['complete'] ? 0 : 1;
    $directnessTotal = 0.0;
    $directnessCount = 0;
    $expiryScore = 0.0;
    $matchedExpiryDays = [];
    $matches = [];
    foreach ($recipe['requirements'] as $requirement) {
        $isStaple = !empty($requirement['is_staple']);
        $requiredness = (string)$requirement['requiredness'];
        $isRequired = $requiredness === 'required' && !$isStaple;
        $isRequirednessUncertain =
            $requiredness === 'uncertain' && !$isStaple;
        if ($isRequired) {
            $requiredCount++;
        }
        if ($isStaple) {
            $matches[] = [
                'requirement_id' => $requirement['id'],
                'inventory_product_id' => null,
                'inventory_mapping_id' => null,
                'outcome' => 'staple',
                'satisfies_required' => 1,
                'confidence' => 1.0,
                'relationship' => 'staple',
                'explanation' => [
                    'outcome' => 'staple',
                    'requiredness' => $requiredness,
                    'quantity_audit_state' =>
                        $requirement['quantity_audit_state'],
                    'quantity_enforced' => false,
                    'contributors' => $requirement['contributor_count'],
                ],
            ];
            continue;
        }
        $assertion = ingredientOntologyV3RequirementAssertion(
            $requirement
        );
        if (
            $assertion['status'] !== 'accepted'
            || $assertion['entity_id'] === null
        ) {
            $outcome = match ($assertion['status']) {
                'candidate' => 'candidate_evidence',
                'ambiguous' => 'ambiguous',
                'rejected' => 'rejected',
                default => 'unresolved',
            };
            if ($isRequired || $isRequirednessUncertain) {
                $uncertainRequired++;
            }
            $matches[] = [
                'requirement_id' => $requirement['id'],
                'inventory_product_id' => null,
                'inventory_mapping_id' => null,
                'outcome' => $outcome,
                'satisfies_required' => 0,
                'confidence' => 0.0,
                'relationship' => 'none',
                'explanation' => [
                    'outcome' => $outcome,
                    'mapping_status' => $assertion['status'],
                    'requiredness' => $requiredness,
                    'quantity_audit_state' =>
                        $requirement['quantity_audit_state'],
                    'quantity_enforced' => false,
                    'contributors' => $requirement['contributor_count'],
                ],
            ];
            continue;
        }
        $best = ingredientOntologyV3BestInventoryMatch(
            $context,
            $assertion,
            $inventory,
            $candidateCache
        );
        if ($best === null) {
            if ($isRequired) {
                $missingRequired++;
            } elseif ($isRequirednessUncertain) {
                $uncertainRequired++;
            }
            $matches[] = [
                'requirement_id' => $requirement['id'],
                'inventory_product_id' => null,
                'inventory_mapping_id' => null,
                'outcome' => 'not_in_inventory',
                'satisfies_required' => 0,
                'confidence' => 1.0,
                'relationship' => 'none',
                'explanation' => [
                    'outcome' => 'not_in_inventory',
                    'entity_id' => $requirement['entity_id'],
                    'attributes' => $requirement['attributes'],
                    'requiredness' => $requiredness,
                    'quantity_audit_state' =>
                        $requirement['quantity_audit_state'],
                    'quantity_enforced' => false,
                    'contributors' => $requirement['contributor_count'],
                ],
            ];
            continue;
        }
        $match = $best['match'];
        $satisfied = !empty($match['satisfies_required']);
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
                ingredientOntologyV3RequiredOutcomeClass(
                    (string)$match['outcome']
                ) === 'uncertain'
            ) {
                $uncertainRequired++;
            } else {
                $missingRequired++;
            }
        } elseif ($isRequirednessUncertain) {
            $uncertainRequired++;
        }
        $explanation = $match;
        $explanation['requiredness'] = $requiredness;
        $explanation['quantity_audit_state'] =
            $requirement['quantity_audit_state'];
        $explanation['quantity_enforced'] = false;
        $explanation['contributors'] = $requirement['contributor_count'];
        $explanation['provider_ref_count'] =
            $requirement['provider_ref_count'];
        $matches[] = [
            'requirement_id' => $requirement['id'],
            'inventory_product_id' =>
                (int)$best['candidate']['product_id'],
            'inventory_mapping_id' =>
                $best['inventory_mapping']['mapping_id'] !== null
                    ? (int)$best['inventory_mapping']['mapping_id']
                    : null,
            'outcome' => (string)$match['outcome'],
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
        'matches' => $matches,
    ];
}

function ingredientOntologyV3WriteRequirementScoreRows(
    PDO $db,
    int $scoreRevisionId,
    int $requirementRevisionId,
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
    ");
    foreach ($scores as $row) {
        $score->execute([
            $scoreRevisionId,
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
        INSERT INTO ingredient_ontology_shadow_requirement_matches (
            score_revision_id, requirement_id, requirement_revision_id,
            inventory_product_id, inventory_mapping_id, outcome,
            satisfies_required, confidence, relationship, explanation_json
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($matches as $row) {
        $json = ingredientOntologyV3Json($row['explanation']);
        if (strlen($json) > 32768) {
            $json = ingredientOntologyV3Json([
                'outcome' => $row['outcome'],
                'explanation_truncated' => true,
                'quantity_enforced' => false,
            ]);
        }
        $match->execute([
            $scoreRevisionId,
            $row['requirement_id'],
            $requirementRevisionId,
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

function ingredientOntologyV3RequirementLegacyParity(
    PDO $db,
    int $scoreRevisionId
): array {
    $revision = ingredientOntologyV3RequirementScoreRevision(
        $db,
        $scoreRevisionId
    );
    if ($revision === null) {
        throw new InvalidArgumentException(
            'requirement shadow revision not found'
        );
    }
    $baselineId = (int)(
        $revision['parity_baseline_score_revision_id'] ?? 0
    );
    if ($baselineId <= 0) {
        return [
            'available' => false,
            'valid' => false,
            'reason' => 'legacy_v3_baseline_missing',
            'baseline_revision_id' => null,
            'legacy_recipe_count' => 0,
            'score_mismatch_count' => null,
            'match_mismatch_count' => null,
        ];
    }
    $baseline = recipeScoreRevision($db, $baselineId);
    if (
        $baseline === null
        || $baseline['status'] !== 'ready'
        || (string)$baseline['scoring_model']
            !== INGREDIENT_ONTOLOGY_V3_SCORING_MODEL
        || (int)$baseline['ontology_version_id']
            !== (int)$revision['ontology_version_id']
        || (int)$baseline['inventory_revision']
            !== (int)$revision['inventory_revision']
        || (int)$baseline['catalog_revision']
            !== (int)$revision['catalog_revision']
        || !hash_equals(
            (string)$baseline['inventory_fingerprint'],
            (string)$revision['inventory_fingerprint']
        )
        || !hash_equals(
            (string)$baseline['catalog_fingerprint'],
            (string)$revision['catalog_fingerprint']
        )
        || (int)$baseline['catalog_max_id']
            !== (int)$revision['catalog_max_id']
        || (string)$baseline['score_date']
            !== (string)$revision['score_date']
        || (string)$revision['score_date'] !== date('Y-m-d')
        || !hash_equals(
            ingredientOntologyV3ScoringConfigHash(),
            (string)$baseline['scoring_config_hash']
        )
    ) {
        return [
            'available' => false,
            'valid' => false,
            'reason' => 'persisted_baseline_is_not_input_compatible',
            'baseline_revision_id' => $baselineId,
            'legacy_recipe_count' => 0,
            'score_mismatch_count' => null,
            'match_mismatch_count' => null,
        ];
    }
    $requirementRevisionId = (int)$revision['requirement_revision_id'];
    $legacyRecipeCountStmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_requirement_recipe_states
        WHERE requirement_revision_id = ? AND basis = 'legacy'
    ");
    $legacyRecipeCountStmt->execute([$requirementRevisionId]);
    $legacyRecipeCount = (int)$legacyRecipeCountStmt->fetchColumn();
    $legacyMatchCountStmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_requirement_members member
        JOIN ingredient_ontology_recipe_requirements requirement
          ON requirement.id = member.requirement_id
        WHERE member.requirement_revision_id = ?
          AND member.owner_type = 'recipe_ingredient'
          AND requirement.basis = 'legacy'
    ");
    $legacyMatchCountStmt->execute([$requirementRevisionId]);
    $legacyMatchCount = (int)$legacyMatchCountStmt->fetchColumn();
    $scoreMismatch = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_requirement_recipe_states state
        LEFT JOIN recipe_inventory_scores old
          ON old.score_revision_id = ?
         AND old.recipe_id = state.recipe_id
        LEFT JOIN recipe_inventory_scores new
          ON new.score_revision_id = ?
         AND new.recipe_id = state.recipe_id
        WHERE state.requirement_revision_id = ?
          AND state.basis = 'legacy'
          AND (
              old.recipe_id IS NULL
              OR new.recipe_id IS NULL
              OR ABS(old.coverage - new.coverage) > 0.000001
              OR ABS(old.directness - new.directness) > 0.000001
              OR ABS(old.expiry_score - new.expiry_score) > 0.000001
              OR ABS(old.source_user_score - new.source_user_score) > 0.000001
              OR ABS(old.availability_score - new.availability_score)
                    > 0.000001
              OR old.required_count <> new.required_count
              OR old.matched_required_count <> new.matched_required_count
              OR old.missing_required_count <> new.missing_required_count
              OR old.uncertain_required_count
                    <> new.uncertain_required_count
              OR old.cookable <> new.cookable
              OR COALESCE(old.soonest_expiry_days, -999999)
                    <> COALESCE(new.soonest_expiry_days, -999999)
          )
    ");
    $scoreMismatch->execute([
        $baselineId,
        $scoreRevisionId,
        $requirementRevisionId,
    ]);
    $scoreComparable = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_requirement_recipe_states state
        JOIN recipe_inventory_scores old
          ON old.score_revision_id = ?
         AND old.recipe_id = state.recipe_id
        JOIN recipe_inventory_scores new
          ON new.score_revision_id = ?
         AND new.recipe_id = state.recipe_id
        WHERE state.requirement_revision_id = ?
          AND state.basis = 'legacy'
    ");
    $scoreComparable->execute([
        $baselineId,
        $scoreRevisionId,
        $requirementRevisionId,
    ]);
    $matchMismatch = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_requirement_members member
        JOIN ingredient_ontology_recipe_requirements requirement
          ON requirement.id = member.requirement_id
        LEFT JOIN ingredient_ontology_shadow_matches old
          ON old.score_revision_id = ?
         AND old.recipe_ingredient_id = member.owner_id
        LEFT JOIN ingredient_ontology_shadow_requirement_matches new
          ON new.score_revision_id = ?
         AND new.requirement_id = requirement.id
        WHERE member.requirement_revision_id = ?
          AND member.owner_type = 'recipe_ingredient'
          AND requirement.basis = 'legacy'
          AND (
              old.recipe_ingredient_id IS NULL
              OR new.requirement_id IS NULL
              OR old.outcome <> new.outcome
              OR old.satisfies_required <> new.satisfies_required
              OR ABS(old.confidence - new.confidence) > 0.000001
              OR old.relationship <> new.relationship
              OR COALESCE(old.inventory_product_id, 0)
                    <> COALESCE(new.inventory_product_id, 0)
              OR COALESCE(old.inventory_mapping_id, 0)
                    <> COALESCE(new.inventory_mapping_id, 0)
          )
    ");
    $matchMismatch->execute([
        $baselineId,
        $scoreRevisionId,
        $requirementRevisionId,
    ]);
    $matchComparable = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_requirement_members member
        JOIN ingredient_ontology_recipe_requirements requirement
          ON requirement.id = member.requirement_id
        JOIN ingredient_ontology_shadow_matches old
          ON old.score_revision_id = ?
         AND old.recipe_ingredient_id = member.owner_id
        JOIN ingredient_ontology_shadow_requirement_matches new
          ON new.score_revision_id = ?
         AND new.requirement_id = requirement.id
        WHERE member.requirement_revision_id = ?
          AND member.owner_type = 'recipe_ingredient'
          AND requirement.basis = 'legacy'
    ");
    $matchComparable->execute([
        $baselineId,
        $scoreRevisionId,
        $requirementRevisionId,
    ]);
    $scoreMismatchCount = (int)$scoreMismatch->fetchColumn();
    $matchMismatchCount = (int)$matchMismatch->fetchColumn();
    $scoreComparableCount = (int)$scoreComparable->fetchColumn();
    $matchComparableCount = (int)$matchComparable->fetchColumn();
    $cardinalityValid =
        $scoreComparableCount === $legacyRecipeCount
        && $matchComparableCount === $legacyMatchCount
        && (
            $legacyRecipeCount === 0
            || $scoreComparableCount > 0
        )
        && (
            $legacyMatchCount === 0
            || $matchComparableCount > 0
        );
    return [
        'available' => true,
        'valid' => $scoreMismatchCount === 0
            && $matchMismatchCount === 0
            && $cardinalityValid,
        'baseline_revision_id' => $baselineId,
        'legacy_recipe_count' => $legacyRecipeCount,
        'expected_legacy_match_count' => $legacyMatchCount,
        'comparable_recipe_count' => $scoreComparableCount,
        'comparable_match_count' => $matchComparableCount,
        'cardinality_valid' => $cardinalityValid,
        'score_mismatch_count' => $scoreMismatchCount,
        'match_mismatch_count' => $matchMismatchCount,
    ];
}

function ingredientOntologyV3BuildRequirementShadow(
    PDO $db,
    int $requirementRevisionId,
    int $batchSize = 250,
    ?callable $progress = null
): array {
    if (function_exists(
        'ingredientOntologyControllerAssertCopiedGenerationDatabase'
    )) {
        ingredientOntologyControllerAssertCopiedGenerationDatabase($db);
    }
    ingredientOntologyV3SchemaMigrate($db);
    $requirements = ingredientOntologyV3RequirementRevision(
        $db,
        $requirementRevisionId
    );
    if ($requirements === null || $requirements['status'] !== 'ready') {
        throw new InvalidArgumentException(
            'requirement shadow requires a ready requirement revision'
        );
    }
    $versionId = (int)$requirements['ontology_version_id'];
    $version = ingredientOntologyV3Version($db, $versionId);
    if ($version === null || $version['status'] !== 'ready') {
        throw new InvalidArgumentException(
            'requirement shadow requires a ready ontology version'
        );
    }
    if (
        !hash_equals(
            (string)$requirements['source_corpus_hash'],
            ingredientOntologyV3SourceCorpusHash($db, $versionId)
        )
        || !hash_equals(
            (string)$requirements['mapping_hash'],
            ingredientOntologyV3MappingHash($db, $versionId)
        )
        || !hash_equals(
            (string)$requirements['ontology_content_hash'],
            (string)$version['content_hash']
        )
        || !hash_equals(
            (string)$version['content_hash'],
            ingredientOntologyV3ContentHash($db, $versionId)
        )
        || !ingredientOntologyV3RequirementMaterializationAudit(
            $db,
            $requirements
        )['valid']
    ) {
        throw new RuntimeException(
            'requirement projection inputs are stale'
        );
    }
    $lock = ingredientOntologyV3AcquireLock($db);
    if ($lock === false) {
        return ['built' => false, 'reason' => 'locked'];
    }
    $scoreRevisionId = 0;
    try {
        $db->exec('BEGIN IMMEDIATE');
        try {
        $state = recipeScoreState($db);
        $catalogCount = (int)$db->query("
            SELECT COUNT(*) FROM recipe_catalog WHERE deleted_at IS NULL
        ")->fetchColumn();
        $catalogMaxId = recipeScoreCatalogMaxId($db);
        $catalogFingerprint = recipeScoreCatalogFingerprint($db);
        $ownerFingerprintAudit =
            ingredientOntologyV3OwnerFingerprintAudit($db, $versionId);
        if (!$ownerFingerprintAudit['valid']) {
            throw new RuntimeException(
                'ontology owner fingerprints are stale before requirement shadow'
            );
        }
        if (
            !hash_equals(
                (string)$version['content_hash'],
                ingredientOntologyV3ContentHash($db, $versionId)
            )
            || !hash_equals(
                (string)$requirements['source_corpus_hash'],
                ingredientOntologyV3SourceCorpusHash($db, $versionId)
            )
            || !hash_equals(
                (string)$requirements['mapping_hash'],
                ingredientOntologyV3MappingHash($db, $versionId)
            )
        ) {
            throw new RuntimeException(
                'requirement shadow source or ontology integrity failed'
            );
        }
        $inventory = ingredientOntologyV3Inventory($db, $versionId);
        $inventoryFingerprint =
            ingredientOntologyV3InventoryFingerprint(
                $inventory,
                $versionId
            );
        $parityBaselineId =
            ingredientOntologyV3SelectRequirementParityBaseline(
                $db,
                $versionId,
                $state,
                $inventoryFingerprint,
                $catalogFingerprint,
                $catalogMaxId,
                $catalogCount
            );
        $configHash =
            ingredientOntologyV3RequirementScoringConfigHash();
        $ontologySourceHash = ingredientOntologyV3CorpusHash($db);
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
                ontology_source_revision, ontology_source_hash,
                requirement_revision_id,
                requirement_model, parity_baseline_score_revision_id
            )
            VALUES (?, ?, ?, ?, ?, 'building', ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $state['inventory_revision'],
            $state['catalog_revision'],
            $inventoryFingerprint,
            date('Y-m-d'),
            $catalogMaxId,
            $versionId,
            INGREDIENT_ONTOLOGY_V3_REQUIREMENT_SCORING_MODEL,
            $configHash,
            $state['active_score_revision_id'],
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
            $requirementRevisionId,
            INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
            $parityBaselineId,
        ]);
        $scoreRevisionId = (int)$db->lastInsertId();
        $db->exec('COMMIT');
        } catch (Throwable $reservationError) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $reservationError;
        }
        if (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
            && is_callable(
                $GLOBALS[
                    'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SHADOW_RESERVATION'
                ] ?? null
            )
        ) {
            ($GLOBALS[
                'INGREDIENT_ONTOLOGY_V3_AFTER_REQUIREMENT_SHADOW_RESERVATION'
            ])($db, $versionId, $scoreRevisionId);
        }
        $context = new IngredientOntologyV3MatcherContext(
            $db,
            $versionId
        );
        $candidateCache = [];
        $lastId = 0;
        $written = 0;
        $batchSize = max(1, min(500, $batchSize));
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
            $recipes = ingredientOntologyV3LoadRequirementBatch(
                $db,
                $requirementRevisionId,
                $recipeIds
            );
            if (count($recipes) !== count($recipeIds)) {
                throw new RuntimeException(
                    'requirement recipe state coverage is incomplete'
                );
            }
            $scores = [];
            $matches = [];
            foreach ($recipes as $recipe) {
                $result = ingredientOntologyV3ScoreRequirementRecipe(
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
                ingredientOntologyV3WriteRequirementScoreRows(
                    $db,
                    $scoreRevisionId,
                    $requirementRevisionId,
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
        $db->exec('BEGIN IMMEDIATE');
        try {
        $scoreCountStmt = $db->prepare("
            SELECT COUNT(*) FROM recipe_inventory_scores
            WHERE score_revision_id = ?
        ");
        $scoreCountStmt->execute([$scoreRevisionId]);
        $scoreCount = (int)$scoreCountStmt->fetchColumn();
        $matchCountStmt = $db->prepare("
            SELECT COUNT(*)
            FROM ingredient_ontology_shadow_requirement_matches
            WHERE score_revision_id = ?
        ");
        $matchCountStmt->execute([$scoreRevisionId]);
        $matchCount = (int)$matchCountStmt->fetchColumn();
        $currentVersion = ingredientOntologyV3Version($db, $versionId);
        $currentOwnerFingerprintAudit =
            ingredientOntologyV3OwnerFingerprintAudit($db, $versionId);
        $currentInventory = ingredientOntologyV3Inventory($db, $versionId);
        $currentInventoryFingerprint =
            ingredientOntologyV3InventoryFingerprint(
                $currentInventory,
                $versionId
            );
        $currentState = recipeScoreState($db);
        if (
            $scoreCount !== $catalogCount
            || $matchCount !== $requirements['requirement_count']
            || $currentState['inventory_revision']
                !== $state['inventory_revision']
            || $currentState['catalog_revision']
                !== $state['catalog_revision']
            || $currentState['ontology_source_revision']
                !== $state['ontology_source_revision']
            || $currentState['active_score_revision_id']
                !== $state['active_score_revision_id']
            || !hash_equals(
                $ontologySourceHash,
                ingredientOntologyV3CorpusHash($db)
            )
            || $currentVersion === null
            || $currentVersion['status'] !== 'ready'
            || !$currentOwnerFingerprintAudit['valid']
            || !hash_equals(
                $catalogFingerprint,
                recipeScoreCatalogFingerprint($db)
            )
            || !hash_equals(
                (string)$requirements['source_corpus_hash'],
                ingredientOntologyV3SourceCorpusHash($db, $versionId)
            )
            || !hash_equals(
                (string)$requirements['mapping_hash'],
                ingredientOntologyV3MappingHash($db, $versionId)
            )
            || !hash_equals(
                (string)$version['content_hash'],
                (string)$currentVersion['content_hash']
            )
            || !hash_equals(
                (string)$version['content_hash'],
                ingredientOntologyV3ContentHash($db, $versionId)
            )
            || !hash_equals(
                $inventoryFingerprint,
                $currentInventoryFingerprint
            )
        ) {
            throw new RuntimeException(
                'requirement shadow inputs changed or output is incomplete'
            );
        }
        $idSetHashes = ingredientOntologyV3MaterializedIdSetHashes(
            $db,
            $scoreRevisionId,
            $requirementRevisionId
        );
        $revisionForSetAudit = recipeScoreRevision(
            $db,
            $scoreRevisionId
        );
        $idSetAudit = ingredientOntologyV3MaterializedIdSetAudit(
            $db,
            array_merge(
                $revisionForSetAudit ?? ['id' => $scoreRevisionId],
                $idSetHashes,
                [
                    'requirement_revision_id' =>
                        $requirementRevisionId,
                ]
            )
        );
        if (!$idSetAudit['valid']) {
            throw new RuntimeException(
                'requirement shadow materialized ID sets are not equal'
            );
        }
        $valueHashes = ingredientOntologyV3MaterializedValueHashes(
            $db,
            $scoreRevisionId,
            $requirementRevisionId
        );
        $valueAudit = ingredientOntologyV3MaterializedValueAudit(
            $db,
            array_merge(
                $revisionForSetAudit ?? ['id' => $scoreRevisionId],
                $valueHashes,
                [
                    'recipe_count' => $scoreCount,
                    'requirement_revision_id' =>
                        $requirementRevisionId,
                ]
            )
        );
        if (!$valueAudit['valid']) {
            throw new RuntimeException(
                'requirement shadow materialized value hashes are invalid'
            );
        }
        $parity = ingredientOntologyV3RequirementLegacyParity(
            $db,
            $scoreRevisionId
        );
        if ($parity['available'] && !$parity['valid']) {
            throw new RuntimeException(
                'legacy requirement projection parity failed'
            );
        }
        $publicationGuardWasEnabled =
            ingredientOntologyV3PublicationGuardEnabled($db);
        ingredientOntologyV3SetPublicationGuard($db, true);
        try {
            $db->prepare("
                UPDATE recipe_score_revisions SET
                    status = 'ready',
                    recipe_count = ?,
                    catalog_id_set_hash = ?,
                    ingredient_id_set_hash = NULL,
                    requirement_recipe_id_set_hash = ?,
                    requirement_id_set_hash = ?,
                    score_rows_hash = ?,
                    match_rows_hash = ?,
                    materialization_hash = ?,
                    validation_report_json = ?,
                    completed_at = CURRENT_TIMESTAMP,
                    last_error = ''
                WHERE id = ?
            ")->execute([
                $scoreCount,
                $idSetHashes['catalog_id_set_hash'],
                $idSetHashes['requirement_recipe_id_set_hash'],
                $idSetHashes['requirement_id_set_hash'],
                $valueHashes['score_rows_hash'],
                $valueHashes['match_rows_hash'],
                $valueHashes['materialization_hash'],
                ingredientOntologyV3Json([
                    'shadow_only' => true,
                    'activated' => false,
                    'requirement_revision_id' => $requirementRevisionId,
                    'requirement_model' =>
                        INGREDIENT_ONTOLOGY_V3_REQUIREMENT_MODEL,
                    'recipe_count' => $scoreCount,
                    'requirement_match_count' => $matchCount,
                    'source_recipe_count' =>
                        $requirements['source_recipe_count'],
                    'legacy_recipe_count' =>
                        $requirements['legacy_recipe_count'],
                    'quantities_affect_scoring' => false,
                    'source_and_ranking_joined' => false,
                    'scoring_configuration' => array_merge(
                        ingredientOntologyV3RequirementScoringConfiguration(),
                        ['hash' => $configHash]
                    ),
                    'source_owner_fingerprints' =>
                        $currentOwnerFingerprintAudit,
                    'ontology_source_revision' =>
                        $state['ontology_source_revision'],
                    'ontology_source_hash' => $ontologySourceHash,
                    'materialized_id_sets' => $idSetAudit,
                    'materialized_values' => $valueAudit,
                    'legacy_parity' => $parity,
                ]),
                $scoreRevisionId,
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
        $db->exec('COMMIT');
        } catch (Throwable $publicationError) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $publicationError;
        }
        $result = [
            'built' => true,
            'revision_id' => $scoreRevisionId,
            'requirement_revision_id' => $requirementRevisionId,
            'ontology_version_id' => $versionId,
            'recipe_count' => $scoreCount,
            'requirement_match_count' => $matchCount,
            'legacy_parity' => $parity,
            'active_score_revision_id' =>
                recipeScoreState($db)['active_score_revision_id'],
            'activated' => false,
        ];
        $cleanupWarning =
            ingredientOntologyV3RequirementShadowCleanup(
                $db,
                $versionId,
                true
            );
        if ($cleanupWarning !== null) {
            $result['cleanup_warning'] = $cleanupWarning;
        }
        return $result;
    } catch (Throwable $e) {
        if ($scoreRevisionId > 0) {
            $db->prepare("
                UPDATE recipe_score_revisions SET
                    status = 'failed',
                    last_error = ?,
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'building'
            ")->execute([
                mb_substr($e->getMessage(), 0, 1000, 'UTF-8'),
                $scoreRevisionId,
            ]);
            $db->prepare("
                DELETE FROM ingredient_ontology_shadow_requirement_matches
                WHERE score_revision_id = ?
            ")->execute([$scoreRevisionId]);
            $db->prepare("
                DELETE FROM recipe_inventory_scores
                WHERE score_revision_id = ?
            ")->execute([$scoreRevisionId]);
        }
        ingredientOntologyV3RequirementShadowCleanup(
            $db,
            $versionId,
            true
        );
        throw $e;
    } finally {
        ingredientOntologyV3ReleaseLock($lock);
    }
}

function ingredientOntologyV3RequirementShadowReport(
    PDO $db,
    int $scoreRevisionId
): array {
    $revision = ingredientOntologyV3RequirementScoreRevision(
        $db,
        $scoreRevisionId
    );
    if ($revision === null) {
        throw new InvalidArgumentException(
            'requirement shadow revision not found'
        );
    }
    $requirementRevisionId = (int)$revision['requirement_revision_id'];
    $requirementAudit = ingredientOntologyV3RequirementAudit(
        $db,
        $requirementRevisionId
    );
    $providerAudit = ingredientOntologyV3ProviderAudit(
        $db,
        (int)$revision['ontology_version_id']
    );
    $aggregate = $db->prepare("
        SELECT COUNT(*) AS recipe_count,
               SUM(cookable) AS cookable_count,
               AVG(coverage) AS average_coverage,
               SUM(required_count) AS required_count,
               SUM(missing_required_count) AS missing_required,
               SUM(uncertain_required_count) AS uncertain_required
        FROM recipe_inventory_scores
        WHERE score_revision_id = ?
    ");
    $aggregate->execute([$scoreRevisionId]);
    $scores = $aggregate->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ([
        'recipe_count', 'cookable_count', 'required_count',
        'missing_required', 'uncertain_required',
    ] as $field) {
        $scores[$field] = (int)($scores[$field] ?? 0);
    }
    $scores['average_coverage'] = round(
        (float)($scores['average_coverage'] ?? 0),
        6
    );
    $matchCountStmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_shadow_requirement_matches
        WHERE score_revision_id = ?
    ");
    $matchCountStmt->execute([$scoreRevisionId]);
    $matchCount = (int)$matchCountStmt->fetchColumn();
    $outcomes = [];
    $outcomeStmt = $db->prepare("
        SELECT outcome, COUNT(*)
        FROM ingredient_ontology_shadow_requirement_matches
        WHERE score_revision_id = ?
        GROUP BY outcome ORDER BY COUNT(*) DESC, outcome
    ");
    $outcomeStmt->execute([$scoreRevisionId]);
    while ($row = $outcomeStmt->fetch(PDO::FETCH_NUM)) {
        $outcomes[(string)$row[0]] = (int)$row[1];
    }
    $parity = ingredientOntologyV3RequirementLegacyParity(
        $db,
        $scoreRevisionId
    );
    $requiredDelta = [
        'baseline_revision_id' =>
            $parity['baseline_revision_id'] ?? null,
        'overall' => null,
        'source_recipes' => null,
        'legacy_recipes' => null,
    ];
    if (!empty($parity['available'])) {
        $delta = $db->prepare("
            SELECT state.basis,
                   SUM(new.required_count) - SUM(old.required_count)
                       AS required_delta
            FROM ingredient_ontology_requirement_recipe_states state
            JOIN recipe_inventory_scores old
              ON old.score_revision_id = ?
             AND old.recipe_id = state.recipe_id
            JOIN recipe_inventory_scores new
              ON new.score_revision_id = ?
             AND new.recipe_id = state.recipe_id
            WHERE state.requirement_revision_id = ?
            GROUP BY state.basis
        ");
        $delta->execute([
            (int)$parity['baseline_revision_id'],
            $scoreRevisionId,
            $requirementRevisionId,
        ]);
        $overall = 0;
        while ($row = $delta->fetch(PDO::FETCH_ASSOC)) {
            $value = (int)$row['required_delta'];
            $overall += $value;
            if ((string)$row['basis'] === 'legacy') {
                $requiredDelta['legacy_recipes'] = $value;
            } else {
                $requiredDelta['source_recipes'] =
                    ($requiredDelta['source_recipes'] ?? 0) + $value;
            }
        }
        $requiredDelta['overall'] = $overall;
    }
    $requirementRevision = $requirementAudit['revision'];
    return [
        'score_revision_id' => $scoreRevisionId,
        'requirement_revision_id' => $requirementRevisionId,
        'ontology_version_id' => (int)$revision['ontology_version_id'],
        'shadow_only' => true,
        'active_score_revision_id' =>
            recipeScoreState($db)['active_score_revision_id'],
        'scores' => $scores,
        'requirement_match_count' => $matchCount,
        'requirement_match_complete' =>
            $matchCount === $requirementRevision['requirement_count'],
        'match_outcomes' => $outcomes,
        'legacy_parity' => $parity,
        'required_count_delta' => $requiredDelta,
        'provider_terms' => $providerAudit,
        'requirements' => $requirementAudit,
        'materialized_values' =>
            ingredientOntologyV3MaterializedValueAudit(
                $db,
                $revision
            ),
        'materialized_id_sets' =>
            ingredientOntologyV3MaterializedIdSetAudit(
                $db,
                $revision
            ),
        'source_staleness' => [
            'source_corpus_hash_current' =>
                $requirementAudit['source_corpus_hash_current'],
            'mapping_hash_current' =>
                $requirementAudit['mapping_hash_current'],
        ],
        'activation_gate' => [
            'allowed' => false,
            'reason' => $requirementRevision['source_recipe_count'] > 0
                ? 'source-aware requirement revisions are shadow-only'
                : 'requirement projection revisions are shadow-only',
        ],
    ];
}

function ingredientOntologyV3WriteRequirementShadowReportJson(
    PDO $db,
    int $scoreRevisionId,
    mixed $stream
): array {
    if (!is_resource($stream)) {
        throw new InvalidArgumentException('report stream is invalid');
    }
    $report = ingredientOntologyV3RequirementShadowReport(
        $db,
        $scoreRevisionId
    );
    fwrite(
        $stream,
        json_encode(
            $report,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
        ) . PHP_EOL
    );
    return $report;
}

function ingredientOntologyV3ValidateRequirementActivation(
    PDO $db,
    int $scoreRevisionId
): array {
    $revision = ingredientOntologyV3RequirementScoreRevision(
        $db,
        $scoreRevisionId
    );
    if ($revision === null || $revision['status'] !== 'ready') {
        return [
            'valid' => false,
            'errors' => ['requirement shadow revision is not ready'],
        ];
    }
    $requirementRevision = ingredientOntologyV3RequirementRevision(
        $db,
        (int)$revision['requirement_revision_id']
    );
    $errors = [];
    if (
        $requirementRevision === null
        || $requirementRevision['status'] !== 'ready'
    ) {
        $errors[] = 'requirement projection is not ready';
    } else {
        if (
            !hash_equals(
                (string)$requirementRevision['source_corpus_hash'],
                ingredientOntologyV3SourceCorpusHash(
                    $db,
                    (int)$revision['ontology_version_id']
                )
            )
        ) {
            $errors[] = 'source requirement corpus hash changed';
        }
        if (
            !hash_equals(
                (string)$requirementRevision['mapping_hash'],
                ingredientOntologyV3MappingHash(
                    $db,
                    (int)$revision['ontology_version_id']
                )
            )
        ) {
            $errors[] = 'requirement mapping hash changed';
        }
        $version = ingredientOntologyV3Version(
            $db,
            (int)$revision['ontology_version_id']
        );
        if (
            $version === null
            || !hash_equals(
                (string)$requirementRevision['ontology_content_hash'],
                (string)$version['content_hash']
            )
            || !hash_equals(
                (string)$version['content_hash'],
                ingredientOntologyV3ContentHash(
                    $db,
                    (int)$revision['ontology_version_id']
                )
            )
        ) {
            $errors[] = 'requirement ontology content hash changed';
        }
        $matchCountStmt = $db->prepare("
            SELECT COUNT(*)
            FROM ingredient_ontology_shadow_requirement_matches
            WHERE score_revision_id = ?
        ");
        $matchCountStmt->execute([$scoreRevisionId]);
        if (
            (int)$matchCountStmt->fetchColumn()
                !== $requirementRevision['requirement_count']
        ) {
            $errors[] = 'requirement match materialization is incomplete';
        }
        $idSetAudit = ingredientOntologyV3MaterializedIdSetAudit(
            $db,
            $revision
        );
        if (!$idSetAudit['valid']) {
            $errors[] = 'requirement materialized ID sets are not equal';
        }
        $valueAudit = ingredientOntologyV3MaterializedValueAudit(
            $db,
            $revision
        );
        if (!$valueAudit['valid']) {
            $errors[] =
                'requirement score or match materialized values changed';
        }
        $requirementValueAudit =
            ingredientOntologyV3RequirementMaterializationAudit(
                $db,
                $requirementRevision
            );
        if (!$requirementValueAudit['valid']) {
            $errors[] =
                'requirement projection materialized values changed';
        }
        $revisionIntegrity = ingredientOntologyV3RevisionIntegrityAudit(
            $db,
            $revision
        );
        if (!$revisionIntegrity['valid']) {
            $errors[] = 'requirement ontology seal or gold is invalid';
        }
        $scoreState = recipeScoreState($db);
        if (
            (int)($revision['ontology_source_revision'] ?? -1)
                !== (int)$scoreState['ontology_source_revision']
            || !hash_equals(
                (string)($revision['ontology_source_hash'] ?? ''),
                (string)$scoreState['ontology_source_hash']
            )
            || !hash_equals(
                (string)($revision['ontology_source_hash'] ?? ''),
                ingredientOntologyV3CorpusHash($db)
            )
        ) {
            $errors[] =
                'requirement ontology source owner inputs changed';
        }
        $parity = ingredientOntologyV3RequirementLegacyParity(
            $db,
            $scoreRevisionId
        );
        if ($parity['available'] && !$parity['valid']) {
            $errors[] = 'legacy requirement projection parity failed';
        }
        if ($requirementRevision['source_recipe_count'] > 0) {
            $errors[] =
                'source-aware requirement revisions are shadow-only '
                . 'and cannot be activated';
        } else {
            $errors[] =
                'requirement-projection revisions are shadow-only '
                . 'and cannot be activated';
        }
    }
    return [
        'valid' => false,
        'errors' => array_values(array_unique($errors)),
        'revision_id' => $scoreRevisionId,
        'requirement_revision_id' =>
            (int)$revision['requirement_revision_id'],
        'ontology_version_id' =>
            (int)$revision['ontology_version_id'],
        'source_aware' => $requirementRevision !== null
            && $requirementRevision['source_recipe_count'] > 0,
        'materialized_id_sets' => $idSetAudit ?? null,
        'materialized_values' => $valueAudit ?? null,
        'requirement_materialized_values' =>
            $requirementValueAudit ?? null,
        'version_integrity' => $revisionIntegrity ?? null,
    ];
}
