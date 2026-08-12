<?php

function recipeConnectorRegistry(): array {
    return [
        'local' => [
            'label' => 'EverShelf local catalog',
            'network' => false,
            'capabilities' => ['search', 'read', 'save', 'refresh_index'],
            'storage_policy' => 'persistent',
            'rights_basis' => 'local_database',
        ],
        'generated' => [
            'label' => 'EverShelf generated recipes',
            'network' => false,
            'capabilities' => ['search', 'read', 'save', 'regenerate'],
            'storage_policy' => 'persistent',
            'rights_basis' => 'generated_for_user',
        ],
        'manual' => [
            'label' => 'User recipes',
            'network' => false,
            'capabilities' => ['search', 'read', 'save', 'edit'],
            'storage_policy' => 'persistent',
            'rights_basis' => 'user_provided',
        ],
        'cookidoo' => [
            'label' => 'Cookidoo metadata',
            'network' => true,
            'experimental' => true,
            'metadata_only' => true,
            'detail_hydration' => false,
            'detail_hydration_reason' =>
                RECIPE_COOKIDOO_DETAIL_POLICY_REASON,
            'policy_version' =>
                RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
            'capabilities' => [
                'cached_catalog_read',
                'canonical_link',
                'external_instructions_link',
            ],
            'storage_policy' => 'metadata_only',
            'rights_basis' => 'cookidoo_metadata_operator_approved',
        ],
    ];
}

function recipeConnectorExists(string $connector): bool {
    return isset(recipeConnectorRegistry()[$connector]);
}

function recipeConnectorEnvironmentEnabled(string $connector): bool {
    return match ($connector) {
        'cookidoo' => recipeCookidooEnvBool('COOKIDOO_CONNECTOR_ENABLED', false),
        default => true,
    };
}

function recipeConnectorStateRow(PDO $db, string $connector): ?array {
    $stmt = $db->prepare("
        SELECT *,
               CASE
                   WHEN circuit_open_until IS NOT NULL
                    AND circuit_open_until > CURRENT_TIMESTAMP
                   THEN 1 ELSE 0
               END AS circuit_open
        FROM recipe_connector_state
        WHERE connector = ?
        LIMIT 1
    ");
    $stmt->execute([$connector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function recipeConnectorIsEnabled(PDO $db, string $connector): bool {
    if (!recipeConnectorExists($connector) || !recipeConnectorEnvironmentEnabled($connector)) {
        return false;
    }
    $state = recipeConnectorStateRow($db, $connector);
    return $state === null || !empty($state['enabled']);
}

function recipeConnectorCircuitIsOpen(PDO $db, string $connector): bool {
    $state = recipeConnectorStateRow($db, $connector);
    return $state !== null && !empty($state['circuit_open']);
}

function recipeConnectorsWithState(PDO $db): array {
    $states = [];
    foreach ($db->query("
        SELECT *,
               CASE
                   WHEN circuit_open_until IS NOT NULL
                    AND circuit_open_until > CURRENT_TIMESTAMP
                   THEN 1 ELSE 0
               END AS circuit_open
        FROM recipe_connector_state
    ") as $row) {
        $quota = json_decode((string)($row['quota_json'] ?? ''), true);
        $row['quota'] = is_array($quota) ? $quota : [];
        unset($row['quota_json']);
        $row['state_enabled'] = (bool)$row['enabled'];
        $row['environment_enabled'] = recipeConnectorEnvironmentEnabled(
            (string)$row['connector']
        );
        $row['enabled'] = $row['state_enabled'] && $row['environment_enabled'];
        $row['failure_count'] = (int)$row['failure_count'];
        $row['circuit_open'] = (bool)$row['circuit_open'];
        $states[(string)$row['connector']] = $row;
    }

    $out = [];
    foreach (recipeConnectorRegistry() as $name => $metadata) {
        $state = $states[$name] ?? [
            'connector' => $name,
            'state_enabled' => true,
            'environment_enabled' => recipeConnectorEnvironmentEnabled($name),
            'enabled' => recipeConnectorEnvironmentEnabled($name),
            'policy_version' => '1',
            'failure_count' => 0,
            'circuit_open' => false,
            'quota' => [],
        ];
        $configured = $name !== 'cookidoo' || recipeCookidooBridgeConfigured();
        if (!$state['enabled']) {
            $healthStatus = 'disabled';
        } elseif (
            $name === 'cookidoo'
            && empty($metadata['detail_hydration'])
        ) {
            $healthStatus = 'policy_disabled';
        } elseif (!$configured) {
            $healthStatus = 'misconfigured';
        } elseif (!empty($state['circuit_open'])) {
            $healthStatus = 'circuit_open';
        } elseif (!empty($state['last_error'])) {
            $healthStatus = 'error';
        } else {
            $healthStatus = !empty($state['last_success_at'])
                ? 'healthy'
                : 'configured';
        }
        $out[] = array_merge([
            'connector' => $name,
        ], $metadata, [
            'health' => [
                'status' => $healthStatus,
                'configured' => $configured,
            ],
            'state' => $state,
        ]);
    }
    return $out;
}

function recipeConnectorCircuitOpenUntil(PDO $db, string $connector): ?string {
    $state = recipeConnectorStateRow($db, $connector);
    if ($state === null || empty($state['circuit_open'])) {
        return null;
    }
    return !empty($state['circuit_open_until'])
        ? (string)$state['circuit_open_until']
        : null;
}
