#!/usr/bin/env php
<?php
declare(strict_types=1);

$assertions = 0;
$assert = static function (
    bool $condition,
    string $message
) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static function (string $path): string {
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException("Could not read {$path}");
    }
    return $contents;
};
$serviceBlock = static function (
    string $compose,
    string $service
): string {
    $pattern = '/^  ' . preg_quote($service, '/')
        . ':\R(?<block>(?: {4}.*\R?)*)/m';
    if (!preg_match($pattern, $compose, $matches)) {
        throw new RuntimeException(
            "Compose service {$service} is unavailable"
        );
    }
    return (string)$matches['block'];
};

$root = dirname(__DIR__);
$compose = $read($root . '/docker-compose.yml');
$ontology = $serviceBlock($compose, 'ontology-worker');
$activation = $serviceBlock(
    $compose,
    'ontology-activation-worker'
);
$score = $serviceBlock($compose, 'recipe-score-worker');
$canonical = $serviceBlock($compose, 'canonical-worker');
$dockerfile = $read($root . '/Dockerfile');
$bridgeDockerfile = $read($root . '/cookidoo-bridge/Dockerfile');
$ci = $read($root . '/.github/workflows/ci.yml');
$manifest = json_decode(
    $read($root . '/manifest.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);
$index = $read($root . '/index.html');
$changelog = $read($root . '/CHANGELOG.md');
$envExample = $read($root . '/.env.example');
$activationWorker = $read(
    $root . '/docker/ontology-activation-worker.sh'
);
$releaseBuilder = $read(
    $root . '/scripts/build-release-images.sh'
);
$composeBuilder = $read(
    $root . '/scripts/compose-up-exact.sh'
);
$containerHealth = $read(
    $root . '/scripts/container-health.php'
);

$assert(
    str_contains($ontology, 'ontology-controller.php')
    && str_contains($ontology, '--allow-active-db')
    && !str_contains($ontology, '--copy-generation')
    && !str_contains($ontology, '--run-generation'),
    'The live ontology worker must remain intake-only'
);
$assert(
    str_contains(
        $activation,
        'evershelf-ontology-activation-worker'
    )
    && str_contains(
        $activationWorker,
        'process-ontology-activation.php'
    )
    && str_contains(
        $activationWorker,
        'ONTOLOGY_ACTIVATION_WORKER_MEMORY_LIMIT'
    ),
    'A bounded copied-build activation worker must run separately'
);
foreach ([
    'ontology-worker' => $ontology,
    'ontology-activation-worker' => $activation,
    'recipe-score-worker' => $score,
    'canonical-worker' => $canonical,
] as $service => $block) {
    $assert(
        str_contains($block, 'check-worker-health.php')
        && !str_contains($block, 'disable: true'),
        "{$service} must expose an enabled health check"
    );
}
$assert(
    str_contains($ontology, 'memory_limit=')
    && str_contains($score, 'memory_limit=')
    && str_contains(
        $activationWorker,
        'memory_limit=${memory_limit}'
    ),
    'Ontology, activation, and score workers must have explicit memory limits'
);
foreach ([
    'application' => $dockerfile,
    'Cookidoo bridge' => $bridgeDockerfile,
] as $image => $source) {
    $assert(
        str_contains($source, 'ARG VCS_REF')
        && str_contains(
            $source,
            'VCS_REF must be an exact 40- or 64-character commit SHA'
        )
        && str_contains(
            $source,
            'org.opencontainers.image.revision="${VCS_REF}"'
        ),
        "{$image} image must carry exact source revision provenance"
    );
}
$version = (string)($manifest['version'] ?? '');
$assert(
    preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) === 1
    && str_contains(
        $index,
        '<span class="header-version">v' . $version . '</span>'
    )
    && str_contains($changelog, '## [' . $version . ']'),
    'Release metadata must publish one consistent application version'
);
$assert(
    str_contains($compose, 'EVERSHELF_BUILD_SHA:?')
    && str_contains($releaseBuilder, 'git status --porcelain')
    && str_contains($releaseBuilder, 'git rev-parse HEAD')
    && str_contains(
        $releaseBuilder,
        'org.opencontainers.image.revision'
    )
    && str_contains($composeBuilder, 'git status --porcelain')
    && str_contains($composeBuilder, 'git rev-parse HEAD'),
    'Release builds must require a clean exact-SHA source tree'
);
$assert(
    preg_match(
        '/^ONTOLOGY_ACTIVATION_ENABLED=true$/m',
        $envExample
    ) === 1,
    'Copy-safe activation must remain enabled in the default deployment'
);
$assert(
    str_contains($dockerfile, 'scripts/container-health.php')
    && str_contains($compose, 'scripts/container-health.php')
    && str_contains($containerHealth, "name = 'products'")
    && str_contains($containerHealth, '.build-revision'),
    'Application health must verify the schema and immutable build revision'
);
$assert(
    str_contains($ci, 'php -d memory_limit=512M '
        . 'scripts/test-inventory-flow-stress.php')
    && str_contains($ci, 'python -m unittest discover')
    && str_contains($ci, 'VCS_REF="${GITHUB_SHA}"')
    && str_contains(
        $ci,
        'org.opencontainers.image.revision'
    ),
    'CI must gate concurrent ingestion, bridge behavior, and exact-SHA images'
);

$databasePath = sys_get_temp_dir()
    . '/evershelf-worker-health-' . getmypid() . '.sqlite';
@unlink($databasePath);
$db = new PDO('sqlite:' . $databasePath);
$db->exec('CREATE TABLE health_probe (id INTEGER PRIMARY KEY)');
$db->exec("
    CREATE TABLE ontology_activation_state (
        id INTEGER PRIMARY KEY,
        failure_count INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NOT NULL DEFAULT ''
    )
");
$db->exec("
    INSERT INTO ontology_activation_state (
        id, failure_count, last_error
    )
    VALUES (1, 0, '')
");
$db = null;
$runHealth = static function (
    string $needle,
    array $extra = []
) use ($root, $databasePath): array {
    $command = [
        PHP_BINARY,
        $root . '/scripts/check-worker-health.php',
        '--needle=' . $needle,
        '--db=' . $databasePath,
        ...$extra,
    ];
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException(
            'Could not start worker health probe'
        );
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'status' => proc_close($process),
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
};

try {
    $healthy = $runHealth('test-worker-topology.php');
    $assert(
        $healthy['status'] === 0,
        'Worker health must accept a running process and readable database: '
            . $healthy['stderr']
    );
    $missing = $runHealth('evershelf-definitely-missing-worker');
    $assert(
        $missing['status'] === 1,
        'Worker health must reject a missing worker process'
    );
    $heartbeatPath = $databasePath . '.heartbeat';
    file_put_contents($heartbeatPath, (string)time(), LOCK_EX);
    $heartbeat = $runHealth(
        'test-worker-topology.php',
        [
            '--heartbeat=' . $heartbeatPath,
            '--max-age=30',
        ]
    );
    $assert(
        $heartbeat['status'] === 0,
        'Worker health must accept a current heartbeat'
    );
    file_put_contents(
        $heartbeatPath,
        (string)(time() - 60),
        LOCK_EX
    );
    $staleHeartbeat = $runHealth(
        'test-worker-topology.php',
        [
            '--heartbeat=' . $heartbeatPath,
            '--max-age=30',
        ]
    );
    $assert(
        $staleHeartbeat['status'] === 1,
        'Worker health must reject a stale heartbeat'
    );
    file_put_contents($heartbeatPath, (string)time(), LOCK_EX);
    $statusPath = $databasePath . '.status';
    file_put_contents($statusPath, (string)'0 ' . time(), LOCK_EX);
    $healthyStatus = $runHealth(
        'test-worker-topology.php',
        [
            '--heartbeat=' . $heartbeatPath,
            '--status-file=' . $statusPath,
            '--max-age=30',
        ]
    );
    $assert(
        $healthyStatus['status'] === 0,
        'Worker health must accept a successful last cycle'
    );
    file_put_contents($statusPath, (string)'2 ' . time(), LOCK_EX);
    $failedStatus = $runHealth(
        'test-worker-topology.php',
        [
            '--heartbeat=' . $heartbeatPath,
            '--status-file=' . $statusPath,
            '--max-age=30',
        ]
    );
    $assert(
        $failedStatus['status'] === 1,
        'Worker health must reject a failed last cycle'
    );
    $db = new PDO('sqlite:' . $databasePath);
    $db->exec("
        UPDATE ontology_activation_state
        SET failure_count = 1,
            last_error = 'fixture failure'
        WHERE id = 1
    ");
    $db = null;
    file_put_contents($statusPath, (string)'0 ' . time(), LOCK_EX);
    $activationFailure = $runHealth(
        'test-worker-topology.php',
        [
            '--activation-state',
            '--status-file=' . $statusPath,
        ]
    );
    $assert(
        $activationFailure['status'] === 1,
        'Activation health must reject unresolved persisted failures'
    );
} finally {
    @unlink($databasePath);
    @unlink($databasePath . '.heartbeat');
    @unlink($databasePath . '.status');
}

echo "Worker topology tests passed: {$assertions} assertions.\n";
