<?php
declare(strict_types=1);

const RECIPE_PLANNER_CAPABILITY = 'recipe_planner_v1';
const RECIPE_PLANNER_MAX_DAYS = 365;

class RecipePlannerConflictException extends RuntimeException {
}

class RecipePlannerUnavailableException extends RuntimeException {
}

function recipePlannerAppEnabled(): bool {
    return recipeCookidooEnvBool(
        'COOKIDOO_PLANNER_ENABLED',
        false
    );
}

function recipePlannerCapabilityEndpoint(): string {
    return recipeCookidooBridgeEndpointFor(
        '/v1/planner-capabilities'
    );
}

function recipePlannerBridgeEndpoint(): string {
    return recipeCookidooBridgeEndpointFor('/v1/planner-add');
}

function recipePlannerBridgeCapability(): array {
    if (!recipePlannerAppEnabled()) {
        return [
            'available' => false,
            'reason' => 'planner_app_disabled',
        ];
    }
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && isset($GLOBALS['RECIPE_PLANNER_CAPABILITY'])
        && is_array($GLOBALS['RECIPE_PLANNER_CAPABILITY'])
    ) {
        return $GLOBALS['RECIPE_PLANNER_CAPABILITY'];
    }
    static $cached = null;
    static $cachedAt = 0;
    if (is_array($cached) && time() - $cachedAt < 30) {
        return $cached;
    }
    try {
        $payload = recipeCookidooBridgeJsonPost(
            recipePlannerCapabilityEndpoint(),
            ['capability' => RECIPE_PLANNER_CAPABILITY]
        );
    } catch (Throwable $error) {
        return [
            'available' => false,
            'reason' => 'planner_bridge_unavailable',
        ];
    }
    $available = is_array($payload)
        && ($payload['planner_write'] ?? null) === true
        && in_array(
            (string)($payload['put_semantics'] ?? ''),
            ['append', 'replace'],
            true
        );
    $cached = [
        'available' => $available,
        'reason' => $available
            ? null
            : 'planner_bridge_disabled',
        'put_semantics' => $available
            ? (string)$payload['put_semantics']
            : null,
        'account_scope' => 'configured_account',
    ];
    $cachedAt = time();
    return $cached;
}

function recipePlannerCapabilityEnabled(): bool {
    return !empty(recipePlannerBridgeCapability()['available']);
}

function recipePlannerDateBounds(
    ?DateTimeImmutable $today = null
): array {
    $timezone = new DateTimeZone('UTC');
    $today = ($today ?? new DateTimeImmutable('today', $timezone))
        ->setTimezone($timezone)
        ->setTime(0, 0);
    return [
        'minimum' => $today->format('Y-m-d'),
        'maximum' => $today->modify(
            '+' . RECIPE_PLANNER_MAX_DAYS . ' days'
        )->format('Y-m-d'),
    ];
}

function recipePlannerNormalizeDate(mixed $value): string {
    if (!is_string($value)) {
        throw new InvalidArgumentException('planner date is invalid');
    }
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value,
        new DateTimeZone('UTC')
    );
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $date === false
        || ($errors !== false
            && (
                (int)$errors['warning_count'] > 0
                || (int)$errors['error_count'] > 0
            ))
        || $date->format('Y-m-d') !== $value
    ) {
        throw new InvalidArgumentException('planner date is invalid');
    }
    $bounds = recipePlannerDateBounds();
    if (
        strcmp($value, $bounds['minimum']) < 0
        || strcmp($value, $bounds['maximum']) > 0
    ) {
        throw new InvalidArgumentException(
            'planner date is outside the allowed range'
        );
    }
    return $value;
}

function recipePlannerProviderActionToken(
    int $recipeId,
    int $originId,
    string $externalId,
    string $locale,
    int $catalogRevision
): string {
    return hash('sha256', recipeCatalogJsonEncode([
        'capability' => RECIPE_PLANNER_CAPABILITY,
        'recipe_id' => $recipeId,
        'origin_id' => $originId,
        'connector' => RECIPE_COOKIDOO_CONNECTOR,
        'external_id' => $externalId,
        'locale' => strtolower($locale),
        'catalog_revision' => $catalogRevision,
    ]));
}

function recipePlannerDetailProjection(
    array $base,
    bool $allowBridgeProbe = true
): array {
    $bounds = recipePlannerDateBounds();
    $cookidoo = (string)($base['primary_connector'] ?? '')
        === RECIPE_COOKIDOO_CONNECTOR;
    $identityValid = $cookidoo
        && (int)($base['origin_id'] ?? 0) > 0
        && trim((string)($base['external_id'] ?? '')) !== ''
        && (int)($base['catalog_revision'] ?? 0) > 0;
    $locale = trim((string)($base['locale'] ?? ''));
    if ($locale === '') {
        $locale = 'en-GB';
    }
    if (!$cookidoo) {
        $capability = [
            'available' => false,
            'reason' => 'not_cookidoo',
        ];
    } elseif (!$identityValid) {
        $capability = [
            'available' => false,
            'reason' => 'origin_unavailable',
        ];
    } elseif (!$allowBridgeProbe) {
        $capability = [
            'available' => false,
            'reason' => 'planner_probe_unavailable',
        ];
    } else {
        $capability = recipePlannerBridgeCapability();
    }
    $available = $identityValid
        && !empty($capability['available']);
    return [
        'available' => $available,
        'account_scope' => 'configured_account',
        'minimum_date' => $bounds['minimum'],
        'maximum_date' => $bounds['maximum'],
        'provider_action_token' => $available
            ? recipePlannerProviderActionToken(
                (int)$base['id'],
                (int)$base['origin_id'],
                (string)$base['external_id'],
                $locale,
                (int)$base['catalog_revision']
            )
            : null,
        'reason' => $available
            ? null
            : (
                (string)($capability['reason']
                    ?? 'planner_unavailable')
            ),
    ];
}

function recipePlannerInput(array $input): array {
    $recipeId = recipeCatalogRequirePositiveInt(
        $input['recipe_id'] ?? null,
        'recipe_id'
    );
    $date = recipePlannerNormalizeDate(
        $input['date'] ?? null
    );
    $token = trim((string)(
        $input['provider_action_token'] ?? ''
    ));
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        throw new InvalidArgumentException(
            'provider action token is invalid'
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
    return [
        'recipe_id' => $recipeId,
        'date' => $date,
        'provider_action_token' => $token,
        'idempotency_key' => $idempotencyKey,
    ];
}

function recipePlannerRecipeOrigin(
    PDO $db,
    int $recipeId
): array {
    $languageVisibility =
        recipeCookidooLanguageVisibilitySql('catalog');
    $stmt = $db->prepare("
        SELECT catalog.id AS recipe_id,
               origin.id AS origin_id,
               origin.external_id,
               COALESCE(NULLIF(origin.locale, ''), 'en-GB') AS locale,
               state.catalog_revision
        FROM recipe_catalog catalog
        JOIN recipe_origins origin
          ON origin.id = (
              SELECT candidate.id
              FROM recipe_origins candidate
              WHERE candidate.recipe_id = catalog.id
                AND candidate.connector = ?
              ORDER BY candidate.id
              LIMIT 1
          )
        JOIN recipe_score_state state ON state.id = 1
        WHERE catalog.id = ?
          AND catalog.primary_connector = ?
          AND catalog.deleted_at IS NULL
          {$languageVisibility}
        LIMIT 1
    ");
    $stmt->execute([
        RECIPE_COOKIDOO_CONNECTOR,
        $recipeId,
        RECIPE_COOKIDOO_CONNECTOR,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new OutOfBoundsException(
            'recipe_planner_recipe_unavailable'
        );
    }
    $externalId = trim((string)$row['external_id']);
    if ($externalId === '') {
        throw new OutOfBoundsException(
            'recipe_planner_recipe_unavailable'
        );
    }
    return [
        'recipe_id' => (int)$row['recipe_id'],
        'origin_id' => (int)$row['origin_id'],
        'external_id' => $externalId,
        'locale' => recipeCookidooNormalizeLocale($row['locale']),
        'catalog_revision' => (int)$row['catalog_revision'],
    ];
}

function recipePlannerAudit(
    PDO $db,
    int $commandId,
    string $state,
    array $detail = []
): void {
    $db->prepare("
        INSERT INTO recipe_planner_command_events (
            command_id, state, detail_json
        )
        VALUES (?, ?, ?)
    ")->execute([
        $commandId,
        $state,
        recipeCatalogJsonEncode($detail),
    ]);
}

function recipePlannerStoredResult(array $row): array {
    $result = recipeIngredientProposalDecodeJson(
        (string)$row['result_json'],
        'planner result'
    );
    $result['replayed'] = true;
    return $result;
}

function recipePlannerReserve(
    PDO $db,
    array $request
): array {
    $requestFingerprint = hash(
        'sha256',
        recipeCatalogJsonEncode($request)
    );
    $db->exec('BEGIN IMMEDIATE');
    try {
        $existing = $db->prepare("
            SELECT *
            FROM recipe_planner_commands
            WHERE idempotency_key = ?
            LIMIT 1
        ");
        $existing->execute([$request['idempotency_key']]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (!hash_equals(
                (string)$row['request_fingerprint'],
                $requestFingerprint
            )) {
                throw new RecipePlannerConflictException(
                    'idempotency_key_conflict'
                );
            }
            if (in_array(
                (string)$row['status'],
                ['succeeded', 'blocked'],
                true
            )) {
                recipePlannerAudit(
                    $db,
                    (int)$row['id'],
                    'replayed',
                    ['status' => (string)$row['status']]
                );
                $db->exec('COMMIT');
                return [
                    'replay' => recipePlannerStoredResult($row),
                    'command' => $row,
                ];
            }
            $db->exec('COMMIT');
            return ['replay' => null, 'command' => $row];
        }
        $origin = recipePlannerRecipeOrigin(
            $db,
            (int)$request['recipe_id']
        );
        $expectedToken = recipePlannerProviderActionToken(
            $origin['recipe_id'],
            $origin['origin_id'],
            $origin['external_id'],
            $origin['locale'],
            $origin['catalog_revision']
        );
        if (!hash_equals(
            $expectedToken,
            (string)$request['provider_action_token']
        )) {
            throw new RecipePlannerConflictException(
                'recipe_planner_stale'
            );
        }
        $insert = $db->prepare("
            INSERT INTO recipe_planner_commands (
                idempotency_key, request_fingerprint,
                recipe_id, origin_id, external_id,
                target_date, provider_action_token,
                observed_catalog_revision
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $request['idempotency_key'],
            $requestFingerprint,
            $origin['recipe_id'],
            $origin['origin_id'],
            $origin['external_id'],
            $request['date'],
            $request['provider_action_token'],
            $origin['catalog_revision'],
        ]);
        $commandId = (int)$db->lastInsertId();
        recipePlannerAudit(
            $db,
            $commandId,
            'reserved',
            [
                'recipe_id' => $origin['recipe_id'],
                'origin_id' => $origin['origin_id'],
                'catalog_revision' => $origin['catalog_revision'],
                'target_date' => $request['date'],
                'account_scope' => 'configured_account',
            ]
        );
        $existing->execute([$request['idempotency_key']]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $db->exec('COMMIT');
        return [
            'replay' => null,
            'command' => $row,
            'origin' => $origin,
        ];
    } catch (Throwable $error) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
}

function recipePlannerNormalizeBridgeResult(
    mixed $payload,
    array $command
): array {
    if (!is_array($payload)) {
        throw new RuntimeException(
            'planner bridge response is invalid'
        );
    }
    foreach (['changed', 'already_present', 'verified'] as $field) {
        if (!is_bool($payload[$field] ?? null)) {
            throw new RuntimeException(
                'planner bridge response is invalid'
            );
        }
    }
    if (($payload['verified'] ?? false) !== true) {
        throw new RuntimeException(
            'planner bridge verification failed'
        );
    }
    if (
        (string)($payload['date'] ?? '')
            !== (string)$command['target_date']
        || (string)($payload['account_scope'] ?? '')
            !== 'configured_account'
    ) {
        throw new RuntimeException(
            'planner bridge response identity is invalid'
        );
    }
    return [
        'recipe_id' => (int)$command['recipe_id'],
        'date' => (string)$command['target_date'],
        'account_scope' => 'configured_account',
        'changed' => (bool)$payload['changed'],
        'already_present' =>
            (bool)$payload['already_present'],
        'verified' => true,
        'replayed' => false,
    ];
}

function recipePlannerAdd(
    PDO $db,
    array $input
): array {
    $request = recipePlannerInput($input);
    if (!recipePlannerCapabilityEnabled()) {
        throw new RecipePlannerUnavailableException(
            'recipe_planner_unavailable'
        );
    }
    $reserved = recipePlannerReserve($db, $request);
    if (is_array($reserved['replay'] ?? null)) {
        if (($reserved['replay']['success'] ?? true) === false) {
            throw new RecipePlannerUnavailableException(
                (string)($reserved['replay']['error']
                    ?? 'recipe_planner_unavailable')
            );
        }
        return $reserved['replay'];
    }
    $command = $reserved['command'];
    if (!is_array($command)) {
        throw new RuntimeException(
            'planner command reservation failed'
        );
    }
    $origin = recipePlannerRecipeOrigin(
        $db,
        (int)$command['recipe_id']
    );
    $expectedToken = recipePlannerProviderActionToken(
        $origin['recipe_id'],
        $origin['origin_id'],
        $origin['external_id'],
        $origin['locale'],
        $origin['catalog_revision']
    );
    if (
        !hash_equals(
            (string)$command['provider_action_token'],
            $expectedToken
        )
        || (int)$command['origin_id'] !== $origin['origin_id']
        || !hash_equals(
            (string)$command['external_id'],
            $origin['external_id']
        )
    ) {
        throw new RecipePlannerConflictException(
            'recipe_planner_stale'
        );
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare("
            UPDATE recipe_planner_commands
            SET status = 'dispatching',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([(int)$command['id']]);
        recipePlannerAudit(
            $db,
            (int)$command['id'],
            'dispatching',
            ['account_scope' => 'configured_account']
        );
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
    try {
        $payload = recipeCookidooBridgeJsonPost(
            recipePlannerBridgeEndpoint(),
            [
                'external_id' => $origin['external_id'],
                'date' => (string)$command['target_date'],
                'locale' => $origin['locale'],
                'account_scope' => 'configured_account',
                'idempotency_key' =>
                    (string)$command['idempotency_key'],
            ]
        );
        $result = recipePlannerNormalizeBridgeResult(
            $payload,
            $command
        );
        $db->exec('BEGIN IMMEDIATE');
        $db->prepare("
            UPDATE recipe_planner_commands
            SET status = 'succeeded',
                result_json = ?,
                last_error = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([
            recipeCatalogJsonEncode($result),
            (int)$command['id'],
        ]);
        recipePlannerAudit(
            $db,
            (int)$command['id'],
            'succeeded',
            [
                'changed' => $result['changed'],
                'already_present' => $result['already_present'],
                'verified' => true,
            ]
        );
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $error) {
        $blocked = $error instanceof RecipeCookidooCircuitBreakException
            || str_contains(
                strtolower($error->getMessage()),
                'authentication'
            );
        $failure = [
            'recipe_id' => (int)$command['recipe_id'],
            'date' => (string)$command['target_date'],
            'account_scope' => 'configured_account',
            'success' => false,
            'error' => $blocked
                ? 'recipe_planner_circuit_open'
                : 'recipe_planner_retryable',
            'replayed' => false,
        ];
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->prepare("
                UPDATE recipe_planner_commands
                SET status = ?,
                    result_json = ?,
                    last_error = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([
                $blocked ? 'blocked' : 'reserved',
                recipeCatalogJsonEncode($failure),
                mb_substr(
                    $error->getMessage(),
                    0,
                    1000,
                    'UTF-8'
                ),
                (int)$command['id'],
            ]);
            recipePlannerAudit(
                $db,
                (int)$command['id'],
                $blocked ? 'blocked' : 'failed',
                ['retryable' => !$blocked]
            );
            $db->exec('COMMIT');
        } catch (Throwable $storeError) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $storeError;
        }
        throw new RecipePlannerUnavailableException(
            (string)$failure['error'],
            0,
            $error
        );
    }
}
