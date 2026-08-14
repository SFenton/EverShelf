<?php
declare(strict_types=1);

const RECIPE_INGREDIENT_FEEDBACK_CAPABILITY =
    'recipe_ingredient_feedback_v1';
const RECIPE_INGREDIENT_FEEDBACK_V2_CAPABILITY =
    'recipe_ingredient_feedback_v2';
const RECIPE_INGREDIENT_FEEDBACK_SETTLE_DAYS = 14;
const RECIPE_INGREDIENT_NEGATIVE_SETTLE_HOURS = 48;
const RECIPE_INGREDIENT_KEY_PATTERN =
    '/^ri:\d+:[a-f0-9]{16}$/D';
const RECIPE_INGREDIENT_FEEDBACK_IDEMPOTENCY_PATTERN =
    '/^[A-Za-z0-9._:-]{1,128}$/D';

class RecipeIngredientFeedbackConflictException
    extends RuntimeException {
}

function recipeIngredientFeedbackSourceHash(
    array $ingredient
): string {
    return hash('sha256', recipeCatalogJsonEncode([
        'key' => (string)($ingredient['key'] ?? ''),
        'position' => (int)($ingredient['position'] ?? -1),
        'source_text' => (string)(
            $ingredient['source_text']
                ?? $ingredient['name']
                ?? ''
        ),
    ]));
}

function recipeIngredientFeedbackSupportsInventoryTarget(
    PDO $db
): bool {
    $stmt = $db->query("
        SELECT sql
        FROM sqlite_master
        WHERE type = 'table'
          AND name = 'recipe_ingredient_feedback_events'
        LIMIT 1
    ");
    $sql = (string)($stmt->fetchColumn() ?: '');
    return str_contains($sql, "'inventory_product'");
}

function recipeIngredientFeedbackToken(
    int $recipeId,
    array $ingredient,
    array $revision
): string {
    return hash('sha256', recipeCatalogJsonEncode([
        'recipe_id' => $recipeId,
        'ingredient_key' => (string)($ingredient['key'] ?? ''),
        'position' => (int)($ingredient['position'] ?? -1),
        'source_text_hash' =>
            recipeIngredientFeedbackSourceHash($ingredient),
        'inventory' => $ingredient['inventory'] ?? null,
        'closest_match' => $ingredient['closest_match'] ?? null,
        'mapping' => $ingredient['mapping'] ?? null,
        'ranking_revision' => $revision['ranking'] ?? null,
        'ontology_version' => $revision['ontology'] ?? null,
        'catalog_revision' => $revision['catalog'] ?? null,
        'inventory_revision' => $revision['inventory'] ?? null,
    ]));
}

function recipeIngredientFeedbackState(
    PDO $db,
    int $recipeId
): array {
    $overrides = [];
    $stmt = $db->prepare("
        SELECT override.ingredient_key,
               override.availability_override,
               override.selected_product_id,
               override.decision_action,
               override.updated_at,
               product.name AS selected_product_name
        FROM recipe_ingredient_user_overrides override
        LEFT JOIN products product
          ON product.id = override.selected_product_id
        WHERE override.recipe_id = ?
    ");
    $stmt->execute([$recipeId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $overrides[(string)$row['ingredient_key']] = [
            'availability' =>
                (string)$row['availability_override'],
            'decision_action' =>
                $row['decision_action'] !== null
                    ? (string)$row['decision_action']
                    : null,
            'selected_product' =>
                $row['selected_product_id'] !== null
                    && trim((string)($row['selected_product_name'] ?? '')) !== ''
                    ? [
                        'id' => (int)$row['selected_product_id'],
                        'name' => (string)$row['selected_product_name'],
                    ]
                    : null,
            'updated_at' => $row['updated_at'],
        ];
    }
    $identity = [];
    $stmt = $db->prepare("
        SELECT event.ingredient_key, event.identity_verdict,
               event.target_kind, event.settle_after,
               event.target_product_id, event.target_label,
               event.source_text_hash, event.decision_action,
               event.created_at
        FROM recipe_ingredient_feedback_events event
        JOIN (
            SELECT ingredient_key, MAX(id) AS latest_id
            FROM recipe_ingredient_feedback_events candidate
            WHERE recipe_id = ?
              AND event_type = 'identity'
              AND NOT EXISTS (
                  SELECT 1
                  FROM recipe_ingredient_feedback_events superseder
                  WHERE superseder.supersedes_event_id = candidate.id
              )
            GROUP BY ingredient_key
        ) latest ON latest.latest_id = event.id
        WHERE event.recipe_id = ?
    ");
    $stmt->execute([$recipeId, $recipeId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $identity[(string)$row['ingredient_key']] = [
            'verdict' => (string)$row['identity_verdict'],
            'target_kind' => (string)$row['target_kind'],
            'target_product_id' =>
                $row['target_product_id'] !== null
                    ? (int)$row['target_product_id']
                    : null,
            'decision_action' =>
                $row['decision_action'] !== null
                    ? (string)$row['decision_action']
                    : null,
            'target_label' => (string)($row['target_label'] ?? ''),
            'source_text_hash' =>
                (string)$row['source_text_hash'],
            'settle_after' => $row['settle_after'],
            'updated_at' => $row['created_at'],
        ];
    }
    return [
        'overrides' => $overrides,
        'identity' => $identity,
    ];
}

function recipeIngredientFeedbackDecorate(
    PDO $db,
    int $recipeId,
    array $ingredients,
    array $revision
): array {
    $state = recipeIngredientFeedbackState($db, $recipeId);
    foreach ($ingredients as &$ingredient) {
        $key = (string)($ingredient['key'] ?? '');
        $ingredient['feedback_token'] =
            recipeIngredientFeedbackToken(
                $recipeId,
                $ingredient,
                $revision
            );
        $ingredient['user_override'] =
            $state['overrides'][$key] ?? null;
        $identity = $state['identity'][$key] ?? null;
        if (is_array($identity)) {
            $targetMatches = false;
            if (
                $identity['target_kind'] === 'inventory_product'
                || $identity['decision_action']
                    === 'select_inventory_product'
            ) {
                $product = $ingredient['user_override'][
                    'selected_product'
                ] ?? null;
                $targetMatches = is_array($product)
                    && (int)($product['id'] ?? 0)
                        === (int)$identity['target_product_id'];
            } elseif ($identity['target_kind'] === 'matched_product') {
                $product = $ingredient['inventory'][
                    'matched_product'
                ] ?? null;
                $targetMatches = is_array($product)
                    && (int)($product['id'] ?? 0)
                        === (int)$identity['target_product_id'];
            } elseif ($identity['target_kind'] === 'closest_match') {
                $closest = $ingredient['closest_match'] ?? null;
                $targetMatches = is_array($closest)
                    && hash_equals(
                        (string)$identity['target_label'],
                        (string)($closest['label'] ?? '')
                    );
            }
            if (
                !$targetMatches
                || !hash_equals(
                    (string)$identity['source_text_hash'],
                    recipeIngredientFeedbackSourceHash($ingredient)
                )
            ) {
                $identity = null;
            }
        }
        if (is_array($identity)) {
            unset(
                $identity['target_product_id'],
                $identity['target_label'],
                $identity['source_text_hash']
            );
        }
        $ingredient['identity_feedback'] = $identity;
        $overrideSelectedProduct = !empty(
            $ingredient['user_override']['selected_product']['id']
        );
        $overrideHave = (
            $ingredient['user_override']['availability'] ?? null
        ) === 'have';
        $ingredient['feedback_capabilities'] = [
            'availability_override' => true,
            'identity' => isset($ingredient['closest_match'])
                || !empty(
                    $ingredient['inventory']['matched_product']
                ),
            'decision' => true,
            'assume_have' => true,
            'select_inventory_product' =>
                (string)($ingredient['inventory']['state'] ?? '')
                    !== 'staple',
            'reject_current_match' =>
                $overrideHave
                || in_array(
                    (string)($ingredient['inventory']['state'] ?? ''),
                    ['in_stock', 'staple'],
                    true
                ),
            'positive_identity' =>
                (string)($ingredient['inventory']['state'] ?? '')
                    !== 'staple',
            'negative_identity' =>
                $overrideSelectedProduct
                || !empty(
                    $ingredient['inventory']['matched_product']['id']
                ),
        ];
    }
    unset($ingredient);
    return $ingredients;
}

function recipeIngredientFeedbackInput(
    array $input,
    string $action
): array {
    $recipeId = recipeCatalogRequirePositiveInt(
        $input['recipe_id'] ?? null,
        'recipe_id'
    );
    $position = $input['position'] ?? null;
    if (
        is_bool($position)
        || !is_int($position)
        || $position < 0
        || $position > 10000
    ) {
        throw new InvalidArgumentException(
            'ingredient position is invalid'
        );
    }
    $key = trim((string)($input['ingredient_key'] ?? ''));
    if (!preg_match(RECIPE_INGREDIENT_KEY_PATTERN, $key)) {
        throw new InvalidArgumentException(
            'ingredient key is invalid'
        );
    }
    $token = trim((string)($input['feedback_token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        throw new InvalidArgumentException(
            'feedback token is invalid'
        );
    }
    $idempotencyKey = trim((string)(
        $input['idempotency_key'] ?? ''
    ));
    if (!preg_match(
        RECIPE_INGREDIENT_FEEDBACK_IDEMPOTENCY_PATTERN,
        $idempotencyKey
    )) {
        throw new InvalidArgumentException(
            'idempotency key is invalid'
        );
    }
    $normalized = [
        'recipe_id' => $recipeId,
        'position' => $position,
        'ingredient_key' => $key,
        'feedback_token' => $token,
        'idempotency_key' => $idempotencyKey,
    ];
    if ($action === 'override') {
        $availability = strtolower(trim((string)(
            $input['availability'] ?? ''
        )));
        if (!in_array(
            $availability,
            ['have', 'missing', 'clear'],
            true
        )) {
            throw new InvalidArgumentException(
                'availability override is invalid'
            );
        }
        $normalized['availability'] = $availability;
    } else {
        $verdict = strtolower(trim((string)(
            $input['verdict'] ?? ''
        )));
        $targetKind = strtolower(trim((string)(
            $input['target_kind'] ?? ''
        )));
        if (!in_array($verdict, ['correct', 'wrong'], true)) {
            throw new InvalidArgumentException(
                'identity verdict is invalid'
            );
        }
        if (!in_array(
            $targetKind,
            ['matched_product', 'closest_match'],
            true
        )) {
            throw new InvalidArgumentException(
                'identity target is invalid'
            );
        }
        $normalized['verdict'] = $verdict;
        $normalized['target_kind'] = $targetKind;
    }
    return $normalized;
}

function recipeIngredientFeedbackReplay(
    PDO $db,
    string $idempotencyKey,
    string $requestFingerprint
): ?array {
    $stmt = $db->prepare("
        SELECT request_fingerprint, result_json
        FROM recipe_ingredient_feedback_events
        WHERE idempotency_key = ?
        LIMIT 1
    ");
    $stmt->execute([$idempotencyKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    if (!hash_equals(
        (string)$row['request_fingerprint'],
        $requestFingerprint
    )) {
        throw new RecipeIngredientFeedbackConflictException(
            'idempotency_key_conflict'
        );
    }
    $result = json_decode((string)$row['result_json'], true);
    if (!is_array($result)) {
        throw new RuntimeException(
            'ingredient feedback replay is invalid'
        );
    }
    $result['replayed'] = true;
    return $result;
}

function recipeIngredientFeedbackCurrentIngredient(
    PDO $db,
    array $request
): array {
    $detail = recipeCatalogDetailBuild(
        $db,
        (int)$request['recipe_id'],
        true,
        'active',
        false
    );
    if ($detail === null) {
        throw new OutOfBoundsException('recipe_not_found');
    }
    foreach ($detail['ingredients'] as $ingredient) {
        if (
            (string)$ingredient['key']
                === (string)$request['ingredient_key']
            && (int)$ingredient['position']
                === (int)$request['position']
        ) {
            if (!hash_equals(
                (string)$ingredient['feedback_token'],
                (string)$request['feedback_token']
            )) {
                throw new RecipeIngredientFeedbackConflictException(
                    'ingredient_feedback_stale'
                );
            }
            return [
                'detail' => $detail,
                'ingredient' => $ingredient,
            ];
        }
    }
    throw new RecipeIngredientFeedbackConflictException(
        'ingredient_feedback_stale'
    );
}

function recipeIngredientFeedbackObservedValues(
    array $detail,
    array $ingredient
): array {
    $inventory = is_array($ingredient['inventory'] ?? null)
        ? $ingredient['inventory']
        : [];
    $matchedProduct = is_array(
        $inventory['matched_product'] ?? null
    ) ? $inventory['matched_product'] : [];
    $closestMatch = is_array(
        $ingredient['closest_match'] ?? null
    ) ? $ingredient['closest_match'] : [];
    return [
        'source_text_hash' =>
            recipeIngredientFeedbackSourceHash($ingredient),
        'observed_state' => (string)(
            $inventory['state'] ?? 'uncertain'
        ),
        'observed_relation' =>
            $inventory['relation'] ?? null,
        'observed_confidence' => (float)(
            $inventory['confidence'] ?? 0
        ),
        'observed_product_id' =>
            isset($matchedProduct['id'])
                ? (int)$matchedProduct['id']
                : null,
        'observed_closest_label' =>
            isset($closestMatch['label'])
                ? (string)$closestMatch['label']
                : null,
        'observed_mapping_source' =>
            isset($closestMatch['mapping_source'])
                ? (string)$closestMatch['mapping_source']
                : null,
        'score_revision_id' =>
            $detail['revision']['ranking'] ?? null,
        'ontology_version_id' =>
            $detail['revision']['ontology'] ?? null,
        'inventory_revision' =>
            $detail['revision']['inventory'] ?? null,
        'catalog_revision' =>
            $detail['revision']['catalog'] ?? null,
    ];
}

function recipeIngredientFeedbackSourceFingerprintV2(
    PDO $db,
    array $detail,
    array $ingredient
): array {
    $sourceOwnerFingerprint = null;
    $source = (string)($ingredient['_ingredient_source'] ?? '');
    $ownerType = $source === 'source'
        ? 'recipe_source_ingredient'
        : 'recipe_ingredient';
    $ownerId = $source === 'source'
        ? (int)($ingredient['_ingredient_id'] ?? 0)
        : (int)($ingredient['_ranking_ingredient_id'] ?? 0);
    if ($ownerId > 0) {
        $sourceOwnerFingerprint =
            ingredientOntologyV3CurrentOwnerFingerprint(
                $db,
                $ownerType,
                $ownerId
            );
    }
    $provider = is_array($ingredient['provider'] ?? null)
        ? $ingredient['provider']
        : [];
    $sourceMetadata = is_array($detail['source'] ?? null)
        ? $detail['source']
        : [];
    $fingerprint = hash('sha256', recipeCatalogJsonEncode([
        'connector' => trim((string)(
            $sourceMetadata['connector'] ?? ''
        )),
        'recipe_id' => (int)($detail['id'] ?? 0),
        'recipe_external_id' => trim((string)(
            $sourceMetadata['external_id'] ?? ''
        )),
        'recipe_owner_fingerprint' =>
            $sourceOwnerFingerprint ?? '',
        'provider_ingredient_ref' => trim((string)(
            $provider['ingredient_ref'] ?? ''
        )),
        'ingredient_key' => (string)($ingredient['key'] ?? ''),
        'position' => (int)($ingredient['position'] ?? -1),
        'source_text' => (string)(
            $ingredient['source_text']
                ?? $ingredient['name']
                ?? ''
        ),
    ]));
    return [
        'source_fingerprint_v2' => $fingerprint,
        'source_owner_fingerprint' =>
            $sourceOwnerFingerprint,
    ];
}

function recipeIngredientOverrideSet(
    PDO $db,
    array $input
): array {
    $request = recipeIngredientFeedbackInput(
        $input,
        'override'
    );
    $requestFingerprint = hash(
        'sha256',
        recipeCatalogJsonEncode($request)
    );
    $replay = recipeIngredientFeedbackReplay(
        $db,
        $request['idempotency_key'],
        $requestFingerprint
    );
    if ($replay !== null) {
        return $replay;
    }
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->exec('BEGIN IMMEDIATE');
    }
    try {
        $reservedReplay = recipeIngredientFeedbackReplay(
            $db,
            $request['idempotency_key'],
            $requestFingerprint
        );
        if ($reservedReplay !== null) {
            if ($ownsTransaction) {
                $db->exec('COMMIT');
            }
            return $reservedReplay;
        }
        $current = recipeIngredientFeedbackCurrentIngredient(
            $db,
            $request
        );
        $observed = recipeIngredientFeedbackObservedValues(
            $current['detail'],
            $current['ingredient']
        );
        $availability = $request['availability'] === 'clear'
            ? null
            : $request['availability'];
        $result = [
            'recipe_id' => $request['recipe_id'],
            'ingredient_key' => $request['ingredient_key'],
            'position' => $request['position'],
            'availability' => $availability,
            'replayed' => false,
        ];
        if ($availability === null) {
            $db->prepare("
                DELETE FROM recipe_ingredient_user_overrides
                WHERE recipe_id = ? AND ingredient_key = ?
            ")->execute([
                $request['recipe_id'],
                $request['ingredient_key'],
            ]);
        } else {
            $db->prepare("
                INSERT INTO recipe_ingredient_user_overrides (
                    recipe_id, ingredient_key, position,
                    source_text_hash, availability_override,
                    evidence_token, observed_state,
                    observed_relation, observed_confidence,
                    observed_product_id, observed_closest_label,
                    observed_mapping_source, score_revision_id,
                    ontology_version_id,
                    observed_inventory_revision,
                    observed_catalog_revision, updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        CURRENT_TIMESTAMP)
                ON CONFLICT(recipe_id, ingredient_key) DO UPDATE SET
                    position = excluded.position,
                    source_text_hash = excluded.source_text_hash,
                    availability_override =
                        excluded.availability_override,
                    evidence_token = excluded.evidence_token,
                    observed_state = excluded.observed_state,
                    observed_relation = excluded.observed_relation,
                    observed_confidence =
                        excluded.observed_confidence,
                    observed_product_id =
                        excluded.observed_product_id,
                    observed_closest_label =
                        excluded.observed_closest_label,
                    observed_mapping_source =
                        excluded.observed_mapping_source,
                    score_revision_id =
                        excluded.score_revision_id,
                    ontology_version_id =
                        excluded.ontology_version_id,
                    selected_product_id = NULL,
                    selected_product_fingerprint = NULL,
                    decision_action = NULL,
                    action_origin = NULL,
                    observed_inventory_revision =
                        excluded.observed_inventory_revision,
                    observed_catalog_revision =
                        excluded.observed_catalog_revision,
                    updated_at = CURRENT_TIMESTAMP
            ")->execute([
                $request['recipe_id'],
                $request['ingredient_key'],
                $request['position'],
                $observed['source_text_hash'],
                $availability,
                $request['feedback_token'],
                $observed['observed_state'],
                $observed['observed_relation'],
                $observed['observed_confidence'],
                $observed['observed_product_id'],
                $observed['observed_closest_label'],
                $observed['observed_mapping_source'],
                $observed['score_revision_id'],
                $observed['ontology_version_id'],
                $observed['inventory_revision'],
                $observed['catalog_revision'],
            ]);
        }
        $event = $db->prepare("
            INSERT INTO recipe_ingredient_feedback_events (
                idempotency_key, request_fingerprint,
                recipe_id, ingredient_key, position,
                event_type, availability_override,
                source_text_hash, evidence_token,
                observed_state, observed_relation,
                observed_confidence, observed_product_id,
                observed_closest_label, observed_mapping_source,
                score_revision_id, ontology_version_id,
                settle_after, result_json
            )
            VALUES (?, ?, ?, ?, ?, 'availability', ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, datetime(
                        'now',
                        '+' || ? || ' days'
                    ), ?)
        ");
        $event->execute([
            $request['idempotency_key'],
            $requestFingerprint,
            $request['recipe_id'],
            $request['ingredient_key'],
            $request['position'],
            $availability,
            $observed['source_text_hash'],
            $request['feedback_token'],
            $observed['observed_state'],
            $observed['observed_relation'],
            $observed['observed_confidence'],
            $observed['observed_product_id'],
            $observed['observed_closest_label'],
            $observed['observed_mapping_source'],
            $observed['score_revision_id'],
            $observed['ontology_version_id'],
            RECIPE_INGREDIENT_FEEDBACK_SETTLE_DAYS,
            recipeCatalogJsonEncode($result),
        ]);
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
    } catch (Throwable $e) {
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $e;
    }
    return $result;
}

function recipeIngredientIdentityFeedbackRecord(
    PDO $db,
    array $input
): array {
    $request = recipeIngredientFeedbackInput(
        $input,
        'identity'
    );
    $requestFingerprint = hash(
        'sha256',
        recipeCatalogJsonEncode($request)
    );
    $replay = recipeIngredientFeedbackReplay(
        $db,
        $request['idempotency_key'],
        $requestFingerprint
    );
    if ($replay !== null) {
        return $replay;
    }
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->exec('BEGIN IMMEDIATE');
    }
    try {
        $reservedReplay = recipeIngredientFeedbackReplay(
            $db,
            $request['idempotency_key'],
            $requestFingerprint
        );
        if ($reservedReplay !== null) {
            if ($ownsTransaction) {
                $db->exec('COMMIT');
            }
            return $reservedReplay;
        }
        $current = recipeIngredientFeedbackCurrentIngredient(
            $db,
            $request
        );
        $ingredient = $current['ingredient'];
        $target = $request['target_kind'] === 'matched_product'
            ? ($ingredient['inventory']['matched_product'] ?? null)
            : ($ingredient['closest_match'] ?? null);
        if (!is_array($target)) {
            throw new InvalidArgumentException(
                'identity feedback target is unavailable'
            );
        }
        $observed = recipeIngredientFeedbackObservedValues(
            $current['detail'],
            $ingredient
        );
        $targetProductId = $request['target_kind']
            === 'matched_product'
                ? (int)($target['id'] ?? 0)
                : null;
        $targetLabel = $request['target_kind']
            === 'matched_product'
                ? trim((string)($target['name'] ?? ''))
                : trim((string)($target['label'] ?? ''));
        if (
            ($request['target_kind'] === 'matched_product'
                && $targetProductId <= 0)
            || $targetLabel === ''
        ) {
            throw new InvalidArgumentException(
                'identity feedback target is invalid'
            );
        }
        $result = [
            'recipe_id' => $request['recipe_id'],
            'ingredient_key' => $request['ingredient_key'],
            'position' => $request['position'],
            'verdict' => $request['verdict'],
            'target_kind' => $request['target_kind'],
            'settle_days' =>
                RECIPE_INGREDIENT_FEEDBACK_SETTLE_DAYS,
            'replayed' => false,
        ];
        $stmt = $db->prepare("
        INSERT INTO recipe_ingredient_feedback_events (
            idempotency_key, request_fingerprint,
            recipe_id, ingredient_key, position,
            event_type, identity_verdict, target_kind,
            target_product_id, target_label,
            source_text_hash, evidence_token,
            observed_state, observed_relation,
            observed_confidence, observed_product_id,
            observed_closest_label, observed_mapping_source,
            score_revision_id, ontology_version_id,
            settle_after, result_json
        )
        VALUES (?, ?, ?, ?, ?, 'identity', ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, datetime(
                    'now',
                    '+' || ? || ' days'
                ), ?)
        ");
        $stmt->execute([
            $request['idempotency_key'],
            $requestFingerprint,
            $request['recipe_id'],
            $request['ingredient_key'],
            $request['position'],
            $request['verdict'],
            $request['target_kind'],
            $targetProductId,
            $targetLabel,
            $observed['source_text_hash'],
            $request['feedback_token'],
            $observed['observed_state'],
            $observed['observed_relation'],
            $observed['observed_confidence'],
            $observed['observed_product_id'],
            $observed['observed_closest_label'],
            $observed['observed_mapping_source'],
            $observed['score_revision_id'],
            $observed['ontology_version_id'],
            RECIPE_INGREDIENT_FEEDBACK_SETTLE_DAYS,
            recipeCatalogJsonEncode($result),
        ]);
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
    } catch (Throwable $e) {
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $e;
    }
    return $result;
}

function recipeIngredientDecisionInput(array $input): array {
    $baseInput = $input;
    $baseInput['availability'] = 'have';
    $request = recipeIngredientFeedbackInput(
        $baseInput,
        'override'
    );
    unset($request['availability']);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    if (!in_array($action, [
        'assume_have',
        'select_inventory_product',
        'reject_current_match',
    ], true)) {
        throw new InvalidArgumentException(
            'ingredient decision action is invalid'
        );
    }
    $origin = strtolower(trim((string)(
        $input['action_origin'] ?? 'api'
    )));
    if (!preg_match('/^[a-z][a-z0-9_.:-]{0,79}$/D', $origin)) {
        throw new InvalidArgumentException(
            'ingredient decision origin is invalid'
        );
    }
    $selectedProductId = null;
    if ($action === 'select_inventory_product') {
        $selectedProductId = recipeCatalogRequirePositiveInt(
            $input['selected_product_id'] ?? null,
            'selected_product_id'
        );
    } elseif (array_key_exists('selected_product_id', $input)) {
        throw new InvalidArgumentException(
            'selected product is not valid for this action'
        );
    }
    $expectedTargetProductId = null;
    if (
        $action === 'reject_current_match'
        && array_key_exists('expected_target_product_id', $input)
        && $input['expected_target_product_id'] !== null
    ) {
        $expectedTargetProductId = recipeCatalogRequirePositiveInt(
            $input['expected_target_product_id'],
            'expected_target_product_id'
        );
    } elseif (
        $action !== 'reject_current_match'
        && array_key_exists('expected_target_product_id', $input)
    ) {
        throw new InvalidArgumentException(
            'expected target is not valid for this action'
        );
    }
    return $request + [
        'action' => $action,
        'action_origin' => $origin,
        'selected_product_id' => $selectedProductId,
        'expected_target_product_id' =>
            $expectedTargetProductId,
    ];
}

function recipeIngredientDecisionProduct(
    PDO $db,
    int $productId,
    bool $requireStock
): array {
    $stmt = $db->prepare("
        SELECT product.id, product.name, product.brand,
               product.category, product.prepared_food,
               COALESCE(SUM(
                   CASE
                       WHEN inventory.quantity > 0
                       THEN inventory.quantity
                       ELSE 0
                   END
               ), 0) AS available_quantity
        FROM products product
        LEFT JOIN inventory
          ON inventory.product_id = product.id
        WHERE product.id = ?
        GROUP BY product.id
        LIMIT 1
    ");
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RecipeIngredientFeedbackConflictException(
            'ingredient_feedback_stale'
        );
    }
    if ($requireStock && (float)$row['available_quantity'] <= 0) {
        throw new RecipeIngredientFeedbackConflictException(
            'ingredient_feedback_stale'
        );
    }
    return [
        'id' => (int)$row['id'],
        'name' => mb_substr(
            trim((string)$row['name']),
            0,
            240,
            'UTF-8'
        ),
        'brand' => mb_substr(
            trim((string)$row['brand']),
            0,
            100,
            'UTF-8'
        ),
        'category' => mb_substr(
            trim((string)$row['category']),
            0,
            160,
            'UTF-8'
        ),
        'prepared_food' => (int)$row['prepared_food'],
        'available_quantity' => (float)$row['available_quantity'],
        'owner_fingerprint' =>
            ingredientOntologyV3ProductOwnerFingerprint($row),
    ];
}

function recipeIngredientDecisionLatestProvisional(
    PDO $db,
    int $recipeId,
    string $ingredientKey
): ?int {
    $stmt = $db->prepare("
        SELECT id
        FROM recipe_ingredient_feedback_events
        WHERE recipe_id = ?
          AND ingredient_key = ?
          AND decision_action IS NOT NULL
          AND NOT EXISTS (
              SELECT 1
              FROM recipe_ingredient_feedback_events superseder
              WHERE superseder.supersedes_event_id =
                    recipe_ingredient_feedback_events.id
          )
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$recipeId, $ingredientKey]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function recipeIngredientDecisionSupersedeOutbox(
    PDO $db,
    ?int $eventId
): void {
    if ($eventId === null) {
        return;
    }
    $staged = $db->prepare("
        SELECT response.change_set_id
        FROM recipe_ingredient_proposal_outbox outbox
        LEFT JOIN recipe_ingredient_proposal_responses response
          ON response.id = outbox.response_artifact_id
        WHERE outbox.feedback_event_id = ?
        LIMIT 1
    ");
    $staged->execute([$eventId]);
    $changeSetId = $staged->fetchColumn();
    if ($changeSetId !== false && (int)$changeSetId > 0) {
        $state = $db->prepare("
            SELECT review_state
            FROM ingredient_ontology_change_sets
            WHERE id = ?
        ");
        $state->execute([(int)$changeSetId]);
        $fromState = $state->fetchColumn();
        if (in_array($fromState, ['pending', 'approved'], true)) {
            $reason = 'Superseded by a later ingredient decision.';
            $proposals = $db->prepare("
                SELECT id, review_state
                FROM ingredient_ontology_proposals
                WHERE change_set_id = ?
                  AND review_state IN ('pending', 'approved')
                ORDER BY id
            ");
            $proposals->execute([(int)$changeSetId]);
            $proposalRows = $proposals->fetchAll(PDO::FETCH_ASSOC);
            $db->prepare("
                UPDATE ingredient_ontology_change_sets
                SET review_state = 'rejected',
                    approved_by = 'recipe_feedback_v2',
                    reviewed_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND review_state = ?
            ")->execute([(int)$changeSetId, $fromState]);
            $event = $db->prepare("
                INSERT INTO ingredient_ontology_change_events (
                    change_set_id, proposal_id, action,
                    from_state, to_state, actor, reason
                )
                VALUES (?, ?, 'reject', ?, 'rejected',
                        'recipe_feedback_v2', ?)
            ");
            $event->execute([
                (int)$changeSetId,
                null,
                $fromState,
                $reason,
            ]);
            $updateProposal = $db->prepare("
                UPDATE ingredient_ontology_proposals
                SET review_state = 'rejected',
                    approved_by = 'recipe_feedback_v2',
                    reviewed_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND review_state = ?
            ");
            foreach ($proposalRows as $proposal) {
                $updateProposal->execute([
                    (int)$proposal['id'],
                    (string)$proposal['review_state'],
                ]);
                $event->execute([
                    (int)$changeSetId,
                    (int)$proposal['id'],
                    (string)$proposal['review_state'],
                    $reason,
                ]);
            }
        }
    }
    $stmt = $db->prepare("
        UPDATE recipe_ingredient_proposal_outbox
        SET status = 'superseded',
            lease_token = NULL,
            lease_expires_at = NULL,
            last_error_kind = 'superseded_by_new_decision',
            last_error = 'A later ingredient decision superseded this proposal.',
            updated_at = CURRENT_TIMESTAMP
        WHERE feedback_event_id = ?
          AND status <> 'superseded'
    ");
    $stmt->execute([$eventId]);
    $db->prepare("
        UPDATE recipe_ingredient_feedback_regression_fixtures
        SET status = 'rejected'
        WHERE feedback_event_id = ?
          AND status = 'candidate'
    ")->execute([$eventId]);
}

function recipeIngredientDecisionRegressionFixture(
    PDO $db,
    int $eventId,
    string $polarity,
    string $sourceFingerprint,
    string $targetFingerprint,
    array $input
): void {
    $caseKey = 'feedback-' . $polarity . '-' . $eventId;
    $fixture = [
        'schema_version' =>
            'recipe_ingredient_feedback_regression_v1',
        'candidate_only' => true,
        'gold' => false,
        'event_id' => $eventId,
        'polarity' => $polarity,
        'source_fingerprint_v2' => $sourceFingerprint,
        'target_owner_fingerprint' => $targetFingerprint,
        'source_text' => mb_substr(
            (string)($input['source_text'] ?? ''),
            0,
            500,
            'UTF-8'
        ),
        'target_product_id' =>
            (int)($input['target_product_id'] ?? 0),
        'target_product_name' => mb_substr(
            (string)($input['target_product_name'] ?? ''),
            0,
            240,
            'UTF-8'
        ),
        'expected_identity' => $polarity === 'positive',
    ];
    $stmt = $db->prepare("
        INSERT INTO recipe_ingredient_feedback_regression_fixtures (
            feedback_event_id, case_key, polarity,
            source_fingerprint_v2, target_owner_fingerprint,
            fixture_json
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $eventId,
        $caseKey,
        $polarity,
        $sourceFingerprint,
        $targetFingerprint,
        recipeCatalogJsonEncode($fixture),
    ]);
}

function recipeIngredientDecisionEnqueueProposal(
    PDO $db,
    int $eventId,
    array $input
): int {
    $stmt = $db->prepare("
        INSERT INTO recipe_ingredient_proposal_outbox (
            feedback_event_id, input_json
        )
        VALUES (?, ?)
    ");
    $stmt->execute([
        $eventId,
        recipeCatalogJsonEncode($input),
    ]);
    return (int)$db->lastInsertId();
}

function recipeIngredientDecisionUpsertOverride(
    PDO $db,
    array $request,
    array $observed,
    ?array $selectedProduct
): void {
    $db->prepare("
        INSERT INTO recipe_ingredient_user_overrides (
            recipe_id, ingredient_key, position,
            source_text_hash, availability_override,
            evidence_token, observed_state,
            observed_relation, observed_confidence,
            observed_product_id, observed_closest_label,
            observed_mapping_source, selected_product_id,
            selected_product_fingerprint, decision_action,
            action_origin, observed_inventory_revision,
            observed_catalog_revision, score_revision_id,
            ontology_version_id, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(recipe_id, ingredient_key) DO UPDATE SET
            position = excluded.position,
            source_text_hash = excluded.source_text_hash,
            availability_override =
                excluded.availability_override,
            evidence_token = excluded.evidence_token,
            observed_state = excluded.observed_state,
            observed_relation = excluded.observed_relation,
            observed_confidence = excluded.observed_confidence,
            observed_product_id = excluded.observed_product_id,
            observed_closest_label =
                excluded.observed_closest_label,
            observed_mapping_source =
                excluded.observed_mapping_source,
            selected_product_id = excluded.selected_product_id,
            selected_product_fingerprint =
                excluded.selected_product_fingerprint,
            decision_action = excluded.decision_action,
            action_origin = excluded.action_origin,
            observed_inventory_revision =
                excluded.observed_inventory_revision,
            observed_catalog_revision =
                excluded.observed_catalog_revision,
            score_revision_id = excluded.score_revision_id,
            ontology_version_id = excluded.ontology_version_id,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([
        $request['recipe_id'],
        $request['ingredient_key'],
        $request['position'],
        $observed['source_text_hash'],
        $request['action'] === 'reject_current_match'
            ? 'missing'
            : 'have',
        $request['feedback_token'],
        $observed['observed_state'],
        $observed['observed_relation'],
        $observed['observed_confidence'],
        $observed['observed_product_id'],
        $observed['observed_closest_label'],
        $observed['observed_mapping_source'],
        $selectedProduct['id'] ?? null,
        $selectedProduct['owner_fingerprint'] ?? null,
        $request['action'],
        $request['action_origin'],
        $observed['inventory_revision'],
        $observed['catalog_revision'],
        $observed['score_revision_id'],
        $observed['ontology_version_id'],
    ]);
}

function recipeIngredientDecision(
    PDO $db,
    array $input
): array {
    $request = recipeIngredientDecisionInput($input);
    $requestFingerprint = hash(
        'sha256',
        recipeCatalogJsonEncode($request)
    );
    $replay = recipeIngredientFeedbackReplay(
        $db,
        $request['idempotency_key'],
        $requestFingerprint
    );
    if ($replay !== null) {
        return $replay;
    }
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->exec('BEGIN IMMEDIATE');
    }
    try {
        $reservedReplay = recipeIngredientFeedbackReplay(
            $db,
            $request['idempotency_key'],
            $requestFingerprint
        );
        if ($reservedReplay !== null) {
            if ($ownsTransaction) {
                $db->exec('COMMIT');
            }
            return $reservedReplay;
        }
        $current = recipeIngredientFeedbackCurrentIngredient(
            $db,
            $request
        );
        $detail = $current['detail'];
        $ingredient = $current['ingredient'];
        $observed = recipeIngredientFeedbackObservedValues(
            $detail,
            $ingredient
        );
        $source = recipeIngredientFeedbackSourceFingerprintV2(
            $db,
            $detail,
            $ingredient
        );
        $controllerSubjectResult =
            ingredientOntologyControllerSubjectForDetailIngredientSafely(
                $db,
                $detail,
                $ingredient
            );
        $controllerSubject =
            $controllerSubjectResult['subject'] ?? null;
        $selectedProduct = null;
        $targetProduct = null;
        $currentTargetId = isset(
            $ingredient['user_override']['selected_product']['id']
        )
            ? (int)$ingredient['user_override'][
                'selected_product'
            ]['id']
            : (
                isset($ingredient['inventory']['matched_product']['id'])
                    ? (int)$ingredient['inventory'][
                        'matched_product'
                    ]['id']
                    : null
            );
        if ($request['action'] === 'select_inventory_product') {
            $selectedProduct = recipeIngredientDecisionProduct(
                $db,
                (int)$request['selected_product_id'],
                true
            );
            $targetProduct = $selectedProduct;
        } elseif ($request['action'] === 'reject_current_match') {
            if (
                $currentTargetId
                    !== $request['expected_target_product_id']
            ) {
                throw new RecipeIngredientFeedbackConflictException(
                    'ingredient_feedback_stale'
                );
            }
            if ($currentTargetId !== null) {
                $targetProduct = recipeIngredientDecisionProduct(
                    $db,
                    $currentTargetId,
                    false
                );
            }
        }
        $identityEvidence = $targetProduct !== null
            && $request['action'] !== 'assume_have';
        $identityVerdict = $identityEvidence
            ? (
                $request['action'] === 'select_inventory_product'
                    ? 'correct'
                    : 'wrong'
            )
            : null;
        $eventType = $identityEvidence
            ? 'identity'
            : 'availability';
        $targetKind = null;
        if ($identityEvidence) {
            $targetKind = $request['action']
                === 'select_inventory_product'
                && recipeIngredientFeedbackSupportsInventoryTarget($db)
                    ? 'inventory_product'
                    : 'matched_product';
        }
        $reviewState = $request['action']
            === 'select_inventory_product'
            ? 'eligible'
            : (
                $identityEvidence
                    ? 'settling'
                    : 'reviewed'
            );
        $settleAfter = $identityEvidence
            && $request['action'] === 'reject_current_match'
            ? gmdate(
                'Y-m-d H:i:s',
                time() + (
                    RECIPE_INGREDIENT_NEGATIVE_SETTLE_HOURS * 3600
                )
            )
            : gmdate('Y-m-d H:i:s');
        $supersedesEventId =
            recipeIngredientDecisionLatestProvisional(
                $db,
                (int)$request['recipe_id'],
                (string)$request['ingredient_key']
            );
        recipeIngredientDecisionSupersedeOutbox(
            $db,
            $supersedesEventId
        );
        recipeIngredientDecisionUpsertOverride(
            $db,
            $request,
            $observed,
            $selectedProduct
        );
        $stmt = $db->prepare("
            INSERT INTO recipe_ingredient_feedback_events (
                idempotency_key, request_fingerprint,
                recipe_id, ingredient_key, position,
                event_type, availability_override,
                identity_verdict, target_kind,
                target_product_id, target_label,
                source_text_hash, evidence_token,
                observed_state, observed_relation,
                observed_confidence, observed_product_id,
                observed_closest_label, observed_mapping_source,
                score_revision_id, ontology_version_id,
                decision_action, action_origin,
                source_fingerprint_v2,
                source_owner_fingerprint,
                target_owner_fingerprint,
                observed_inventory_revision,
                observed_catalog_revision,
                supersedes_event_id, review_state,
                settle_after, result_json
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '{}')
        ");
        $stmt->execute([
            $request['idempotency_key'],
            $requestFingerprint,
            $request['recipe_id'],
            $request['ingredient_key'],
            $request['position'],
            $eventType,
            $request['action'] === 'reject_current_match'
                ? 'missing'
                : 'have',
            $identityVerdict,
            $targetKind,
            $targetProduct['id'] ?? null,
            $targetProduct['name'] ?? null,
            $observed['source_text_hash'],
            $request['feedback_token'],
            $observed['observed_state'],
            $observed['observed_relation'],
            $observed['observed_confidence'],
            $observed['observed_product_id'],
            $observed['observed_closest_label'],
            $observed['observed_mapping_source'],
            $observed['score_revision_id'],
            $observed['ontology_version_id'],
            $request['action'],
            $request['action_origin'],
            $source['source_fingerprint_v2'],
            $source['source_owner_fingerprint'],
            $targetProduct['owner_fingerprint'] ?? null,
            $observed['inventory_revision'],
            $observed['catalog_revision'],
            $supersedesEventId,
            $reviewState,
            $settleAfter,
        ]);
        $eventId = (int)$db->lastInsertId();
        $proposalEnqueued = false;
        $outboxId = null;
        if ($identityEvidence) {
            $proposalInput = [
                'schema_version' =>
                    'recipe_ingredient_proposal_input_v1',
                'feedback_event_id' => $eventId,
                'action' => $request['action'],
                'polarity' =>
                    $identityVerdict === 'correct'
                        ? 'positive'
                        : 'negative',
                'recipe_id' => $request['recipe_id'],
                'ingredient_key' => $request['ingredient_key'],
                'position' => $request['position'],
                'source_text' => mb_substr(
                    (string)($ingredient['source_text']
                        ?? $ingredient['name']
                        ?? ''),
                    0,
                    500,
                    'UTF-8'
                ),
                'source_language' => (string)(
                    $detail['source']['content_language']
                        ?? $detail['source']['locale']
                        ?? 'und'
                ),
                'source_fingerprint_v2' =>
                    $source['source_fingerprint_v2'],
                'source_owner_fingerprint' =>
                    $source['source_owner_fingerprint'],
                'target_product_id' => $targetProduct['id'],
                'target_product_name' => $targetProduct['name'],
                'target_product_brand' => $targetProduct['brand'],
                'target_owner_fingerprint' =>
                    $targetProduct['owner_fingerprint'],
                'score_revision_id' =>
                    $observed['score_revision_id'],
                'ontology_version_id' =>
                    $observed['ontology_version_id'],
                'inventory_revision' =>
                    $observed['inventory_revision'],
                'catalog_revision' =>
                    $observed['catalog_revision'],
                'settle_after' => $settleAfter,
            ];
            $outboxId = recipeIngredientDecisionEnqueueProposal(
                $db,
                $eventId,
                $proposalInput
            );
            recipeIngredientDecisionRegressionFixture(
                $db,
                $eventId,
                $identityVerdict === 'correct'
                    ? 'positive'
                    : 'negative',
                $source['source_fingerprint_v2'],
                $targetProduct['owner_fingerprint'],
                $proposalInput
            );
            $proposalEnqueued = true;
        }
        $controllerCorrection = [
            'constraint_epoch' => 0,
            'constraint_id' => null,
            'job_id' => null,
            'compensation' => false,
        ];
        if ($controllerSubject !== null) {
            $controllerCorrection =
                ingredientOntologyControllerRecordCorrectionSafely(
                    $db,
                    [
                        'recipe_id' => $request['recipe_id'],
                        'ingredient_key' => $request['ingredient_key'],
                        'action' => $identityEvidence
                            ? $request['action']
                            : 'assume_have',
                        'feedback_event_id' => $eventId,
                        'subject_id' => (int)$controllerSubject['id'],
                        'subject_fingerprint' =>
                            (string)$controllerSubject[
                                'subject_fingerprint'
                            ],
                        'target_product_id' =>
                            $targetProduct['id'] ?? null,
                        'target_owner_fingerprint' =>
                            $targetProduct['owner_fingerprint'] ?? null,
                    ]
                );
        }
        $result = [
            'recipe_id' => $request['recipe_id'],
            'ingredient_key' => $request['ingredient_key'],
            'position' => $request['position'],
            'action' => $request['action'],
            'availability' =>
                $request['action'] === 'reject_current_match'
                    ? 'missing'
                    : 'have',
            'selected_product' => $selectedProduct !== null
                ? [
                    'id' => $selectedProduct['id'],
                    'name' => $selectedProduct['name'],
                ]
                : null,
            'identity_evidence' => $identityEvidence,
            'proposal_enqueued' => $proposalEnqueued,
            'proposal_outbox_id' => $outboxId,
            'constraint_epoch' =>
                $controllerCorrection['constraint_epoch'],
            'constraint_id' =>
                $controllerCorrection['constraint_id'],
            'autonomous_job_id' =>
                $controllerCorrection['job_id'],
            'autonomous_compensation' =>
                $controllerCorrection['compensation'],
            'controller_degraded' =>
                !empty($controllerSubjectResult['degraded'])
                || !empty($controllerCorrection['degraded']),
            'settle_after' => $identityEvidence
                ? $settleAfter
                : null,
            'replayed' => false,
        ];
        $db->prepare("
            UPDATE recipe_ingredient_feedback_events
            SET result_json = ?
            WHERE id = ?
        ")->execute([
            recipeCatalogJsonEncode($result),
            $eventId,
        ]);
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
    } catch (Throwable $e) {
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $e;
    }
    ingredientOntologyControllerWake();
    return $result;
}
