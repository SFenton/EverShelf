#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$mode = $argv[1] ?? '';
if ($mode === '--rate-limit-worker') {
    $statePath = (string)($argv[2] ?? '');
    $barrierPath = (string)($argv[3] ?? '');
    $readyPath = (string)($argv[4] ?? '');
    $resultPath = (string)($argv[5] ?? '');
    $now = (int)($argv[6] ?? 0);
    if (
        $statePath === ''
        || $barrierPath === ''
        || $readyPath === ''
        || $resultPath === ''
        || $now < 1
    ) {
        exit(2);
    }
    if (file_put_contents($readyPath, "ready\n") === false) {
        exit(3);
    }
    $deadline = microtime(true) + 10;
    while (!is_file($barrierPath) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (!is_file($barrierPath)) {
        exit(4);
    }
    $result = evershelfConsumeRateLimit(
        $statePath,
        120,
        60,
        $now
    );
    if (
        !is_array($result)
        || file_put_contents(
            $resultPath,
            json_encode($result)
        ) === false
    ) {
        exit(5);
    }
    exit(0);
}

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

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (!is_dir($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $removeTree($path . '/' . $entry);
    }
    rmdir($path);
};

$policyGroups = [
    [
        'bucket' => 'category_refinement',
        'limit' => 120,
        'actions' => ['guess_category'],
    ],
    [
        'bucket' => 'ai',
        'limit' => 15,
        'actions' => [
            'gemini_expiry', 'gemini_readExpiry', 'gemini_chat',
            'gemini_identify', 'gemini_suggest_shopping',
            'chat_to_recipe', 'recipe_from_ingredient',
            'gemini_number_ocr', 'gemini_barcode_visual',
            'location_suggestion_ai',
        ],
    ],
    [
        'bucket' => 'price',
        'limit' => 60,
        'actions' => [
            'get_shopping_price',
            'get_all_shopping_prices',
        ],
    ],
    [
        'bucket' => 'recipe',
        'limit' => 5,
        'actions' => [
            'generate_recipe',
            'generate_recipe_stream',
        ],
    ],
    [
        'bucket' => 'recipe_refresh',
        'limit' => 10,
        'actions' => [
            'recipe_catalog_refresh',
            'recipe_catalog_discover',
        ],
    ],
    [
        'bucket' => 'recipe_catalog',
        'limit' => 60,
        'actions' => [
            'recipe_catalog_search', 'recipe_catalog_get',
            'recipe_catalog_suggest',
            'recipe_catalog_recommendations',
            'recipe_catalog_detail',
            'recipe_catalog_grocery_add',
            'recipe_catalog_ingredient_override',
            'recipe_catalog_identity_feedback',
            'recipe_catalog_ingredient_decision',
            'recipe_catalog_planner_add', 'recipe_jobs_status',
            'recipe_connectors', 'recipe_catalog_save',
            'recipe_catalog_delete', 'recipe_catalog_favorite',
        ],
    ],
    [
        'bucket' => 'error_report',
        'limit' => 20,
        'actions' => ['report_error', 'check_update'],
    ],
];
foreach ($policyGroups as $group) {
    $expected = [
        'bucket' => $group['bucket'],
        'limit' => $group['limit'],
        'window' => 60,
    ];
    foreach ($group['actions'] as $action) {
        $assert(
            evershelfRateLimitPolicy($action) === $expected,
            "Rate policy must classify {$action} as {$group['bucket']}"
        );
        $normalized = evershelfNormalizeApiAction(
            " \t{$action}\r\n"
        );
        $assert(
            $normalized === $action
                && evershelfRateLimitPolicy($normalized) === $expected,
            "Canonicalized action must preserve {$action} rate policy"
        );
    }
}
$generalPolicy = [
    'bucket' => 'general',
    'limit' => 120,
    'window' => 60,
];
foreach (['inventory_list', 'client_log', 'unknown_action'] as $action) {
    $assert(
        evershelfRateLimitPolicy($action) === $generalPolicy,
        "Unclassified action {$action} must use the general bucket"
    );
}
$assert(
    evershelfNormalizeApiAction(
        "\0guess_category\x0B"
    ) === 'guess_category',
    'Action normalization must remove surrounding control characters'
);
$assert(
    array_is_list([])
        && array_is_list(['first', 'second'])
        && !array_is_list([1 => 'second']),
    'PHP 8.0 list compatibility must match array_is_list semantics'
);
$assert(
    evershelfCategoryRefinementCacheKey(' Tomato ')
        === evershelfCategoryRefinementCacheKey('tomato')
        && evershelfCategoryRefinementCacheKey('Sour  Cream')
        !== evershelfCategoryRefinementCacheKey('Sour Cream')
        && str_starts_with(
            evershelfCategoryRefinementCacheKey('tomato'),
            CATEGORY_REFINEMENT_CACHE_NAMESPACE
        ),
    'Category cache keys must be versioned and normalize trim/lowercase only'
);

$root = sys_get_temp_dir()
    . '/evershelf-category-refinement-'
    . getmypid()
    . '-'
    . bin2hex(random_bytes(4));
if (!mkdir($root, 0770, true)) {
    throw new RuntimeException('Could not create category test root');
}
$cachePath = $root . '/category_ai_cache.json';

try {
    $legacyKey = md5('tomato');
    file_put_contents(
        $cachePath,
        json_encode([$legacyKey => 'altro'])
    );
    $assert(
        !array_key_exists(
            evershelfCategoryRefinementCacheKey('tomato'),
            evershelfLoadCategoryRefinementCache($cachePath)
        ),
        'Versioned lookups must ignore poisoned legacy cache entries'
    );
    unlink($cachePath);

    $non200 = evershelfApplyCategoryRefinementResult(
        $cachePath,
        'non200',
        [
            'http_code' => 503,
            'data' => [
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => 'altro']]],
                ]],
            ],
        ]
    );
    $assert(
        $non200 === ['category' => null, 'cached' => false]
            && !file_exists($cachePath),
        'Non-200 AI responses must never poison the category cache'
    );

    $malformed = evershelfApplyCategoryRefinementResult(
        $cachePath,
        'malformed',
        ['http_code' => 200, 'data' => ['candidates' => []]]
    );
    $assert(
        $malformed === ['category' => null, 'cached' => false]
            && !file_exists($cachePath),
        'Malformed HTTP-200 responses must never become altro'
    );

    $malformedTexts = [
        'altro2',
        'al-tro',
        '`altro`',
        'altro.',
        'Category: altro',
        '{"category":"altro"}',
    ];
    foreach ($malformedTexts as $index => $text) {
        $outcome = evershelfApplyCategoryRefinementResult(
            $cachePath,
            'malformed-text-' . $index,
            [
                'http_code' => 200,
                'data' => [
                    'candidates' => [[
                        'finishReason' => 'STOP',
                        'content' => [
                            'parts' => [['text' => $text]],
                        ],
                    ]],
                ],
            ]
        );
        $assert(
            $outcome === ['category' => null, 'cached' => false]
                && !file_exists($cachePath),
            "Malformed category output must remain retryable: {$text}"
        );
    }

    $explicitAltro = evershelfApplyCategoryRefinementResult(
        $cachePath,
        'explicit-altro',
        [
            'http_code' => 200,
            'data' => [
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => 'altro']]],
                ]],
            ],
        ]
    );
    $assert(
        $explicitAltro === ['category' => 'altro', 'cached' => true]
            && (evershelfLoadCategoryRefinementCache(
                $cachePath
            )['explicit-altro'] ?? null) === 'altro',
        'An explicit valid altro response must remain cacheable'
    );

    $vegetable = evershelfApplyCategoryRefinementResult(
        $cachePath,
        'tomato',
        [
            'http_code' => 200,
            'data' => [
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => [
                        'parts' => [['text' => " \nVerdura\t"]],
                    ],
                ]],
            ],
        ]
    );
    $cache = evershelfLoadCategoryRefinementCache($cachePath);
    $assert(
        $vegetable === ['category' => 'verdura', 'cached' => true]
            && ($cache['explicit-altro'] ?? null) === 'altro'
            && ($cache['tomato'] ?? null) === 'verdura',
        'Atomic cache updates must preserve prior category entries'
    );

    $unsafeResults = [
        'multipart junk' => [
            'http_code' => 200,
            'data' => [
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => [
                        'parts' => [
                            ['text' => 'altro'],
                            ['text' => '2'],
                        ],
                    ],
                ]],
            ],
        ],
        'unsafe finish reason' => [
            'http_code' => 200,
            'data' => [
                'candidates' => [[
                    'finishReason' => 'SAFETY',
                    'content' => ['parts' => [['text' => 'altro']]],
                ]],
            ],
        ],
        'blocked prompt' => [
            'http_code' => 200,
            'data' => [
                'promptFeedback' => ['blockReason' => 'SAFETY'],
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => 'altro']]],
                ]],
            ],
        ],
        'blocked candidate safety rating' => [
            'http_code' => 200,
            'data' => [
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'safetyRatings' => [[
                        'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                        'blocked' => true,
                    ]],
                    'content' => ['parts' => [['text' => 'altro']]],
                ]],
            ],
        ],
    ];
    foreach ($unsafeResults as $label => $result) {
        $before = file_get_contents($cachePath);
        $outcome = evershelfApplyCategoryRefinementResult(
            $cachePath,
            'unsafe-' . md5($label),
            $result
        );
        $assert(
            $outcome === ['category' => null, 'cached' => false]
                && file_get_contents($cachePath) === $before,
            "Unsafe category envelope must remain uncached: {$label}"
        );
    }

    $multipartValid = evershelfApplyCategoryRefinementResult(
        $cachePath,
        'multipart-valid',
        [
            'http_code' => 200,
            'data' => [
                'promptFeedback' => [
                    'blockReason' => 'BLOCK_REASON_UNSPECIFIED',
                    'safetyRatings' => [['blocked' => false]],
                ],
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'safetyRatings' => [['blocked' => false]],
                    'content' => [
                        'parts' => [
                            ['thought' => true, 'text' => 'analysis'],
                            ['text' => 'ver'],
                            ['text' => 'dura'],
                        ],
                    ],
                ]],
            ],
        ]
    );
    $assert(
        $multipartValid === ['category' => 'verdura', 'cached' => true],
        'Safe normal completion must validate all visible text parts'
    );

    $strictEnvelopeFixtures = [
        'prompt feedback list' => '{"promptFeedback":[],"candidates":[{"finishReason":"STOP","content":{"parts":[{"text":"altro"}]}}]}',
        'empty candidate safety rating' => '{"candidates":[{"finishReason":"STOP","safetyRatings":[{}],"content":{"parts":[{"text":"altro"}]}}]}',
        'mixed text and function part' => '{"candidates":[{"finishReason":"STOP","content":{"parts":[{"text":"altro","functionCall":{"name":"unexpected"}}]}}]}',
    ];
    foreach ($strictEnvelopeFixtures as $label => $body) {
        $before = file_get_contents($cachePath);
        $outcome = evershelfApplyCategoryRefinementResult(
            $cachePath,
            'strict-envelope-' . md5($label),
            [
                'http_code' => 200,
                'body' => $body,
                'data' => json_decode($body, true),
            ]
        );
        $assert(
            $outcome === ['category' => null, 'cached' => false]
                && file_get_contents($cachePath) === $before,
            "Malformed raw Gemini envelope must remain uncached: {$label}"
        );
    }

    $validRawEnvelope = '{"promptFeedback":{},"candidates":[{"finishReason":"STOP","safetyRatings":[{"category":"HARM_CATEGORY_DANGEROUS_CONTENT","probability":"NEGLIGIBLE"}],"content":{"parts":[{"text":"pane"}]}}]}';
    $validRawOutcome = evershelfApplyCategoryRefinementResult(
        $cachePath,
        'valid-raw-envelope',
        [
            'http_code' => 200,
            'body' => $validRawEnvelope,
            'data' => json_decode($validRawEnvelope, true),
        ]
    );
    $assert(
        $validRawOutcome === ['category' => 'pane', 'cached' => true],
        'Strict raw validation must preserve valid empty objects and ratings'
    );

    $rateLimitDirectory = $root . '/rate-limits';
    if (!mkdir($rateLimitDirectory, 0755, true)) {
        throw new RuntimeException(
            'Could not create rate-limit test directory'
        );
    }
    $rateLimitPath = $rateLimitDirectory . '/category.json';
    $corruptStates = [
        'invalid JSON' => '{invalid json',
        'JSON object' => '{}',
        'string list' => '["bad"]',
        'mixed list' => '[1700000000,"bad"]',
        'numeric-key object' => '{"0":1700000000}',
        'float list' => '[1700000000.5]',
        'boolean list' => '[true]',
        'blank file' => '',
        'whitespace-only file' => " \n\t",
    ];
    foreach ($corruptStates as $label => $rawState) {
        file_put_contents($rateLimitPath, $rawState);
        $result = evershelfConsumeRateLimit(
            $rateLimitPath,
            120,
            60,
            1700000000
        );
        $assert(
            $result === null
                && file_get_contents($rateLimitPath) === $rawState,
            "Corrupt rate-limit state must fail closed unchanged: {$label}"
        );
    }

    unlink($rateLimitPath);
    $assert(
        evershelfConsumeRateLimit(
            $rateLimitPath,
            120,
            60,
            1700000000
        ) === ['allowed' => true, 'count' => 1]
            && file_get_contents($rateLimitPath) === '[1700000000]',
        'A newly created limiter file must accept its first request'
    );

    file_put_contents($rateLimitPath, '[]');
    $assert(
        evershelfConsumeRateLimit(
            $rateLimitPath,
            120,
            60,
            1700000000
        ) === ['allowed' => true, 'count' => 1]
            && file_get_contents($rateLimitPath) === '[1700000000]',
        'A valid empty limiter list must accept and persist one request'
    );

    $pruneDirectory = $root . '/rate-limit-prune';
    if (!mkdir($pruneDirectory, 0755, true)) {
        throw new RuntimeException(
            'Could not create rate-limit prune test directory'
        );
    }
    $pruneFixtures = [
        'blank' => '',
        'malformed' => '{bad',
        'expired' => '[1699999000]',
        'active' => '[1700000100]',
    ];
    foreach ($pruneFixtures as $name => $bytes) {
        $path = "{$pruneDirectory}/{$name}.json";
        file_put_contents($path, $bytes);
        touch($path, 1);
    }
    $assert(
        evershelfPruneRateLimitFiles(
            $pruneDirectory,
            1700000000
        ) === false
            && file_get_contents(
                $pruneDirectory . '/blank.json'
            ) === ''
            && file_get_contents(
                $pruneDirectory . '/malformed.json'
            ) === '{bad'
            && !file_exists($pruneDirectory . '/expired.json')
            && file_get_contents(
                $pruneDirectory . '/active.json'
            ) === '[1700000100]',
        'Pruning must remove only valid expired state and preserve bad bytes'
    );

    unlink($rateLimitPath);

    $seeded = true;
    for ($index = 0; $index < 119; $index++) {
        $result = evershelfConsumeRateLimit(
            $rateLimitPath,
            120,
            60,
            1700000000
        );
        if (
            $result !== [
                'allowed' => true,
                'count' => $index + 1,
            ]
        ) {
            $seeded = false;
            break;
        }
    }
    $assert(
        $seeded,
        'Rate-limit state must count sequential requests accurately'
    );

    $workerCount = 20;
    $barrierPath = $root . '/rate-limit-barrier';
    $workers = [];
    for ($index = 0; $index < $workerCount; $index++) {
        $readyPath = $root . "/rate-limit-ready-{$index}";
        $resultPath = $root . "/rate-limit-result-{$index}.json";
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                __FILE__,
                '--rate-limit-worker',
                $rateLimitPath,
                $barrierPath,
                $readyPath,
                $resultPath,
                '1700000000',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException(
                "Could not start rate-limit worker {$index}"
            );
        }
        fclose($pipes[0]);
        $workers[] = [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'ready' => $readyPath,
            'result' => $resultPath,
        ];
    }

    $readyDeadline = microtime(true) + 10;
    do {
        $readyCount = count(array_filter(
            $workers,
            static fn(array $worker): bool => is_file(
                $worker['ready']
            )
        ));
        if ($readyCount === $workerCount) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $readyDeadline);
    file_put_contents($barrierPath, "go\n");

    $allowedCount = 0;
    $workerFailures = [];
    foreach ($workers as $index => $worker) {
        $stdout = stream_get_contents($worker['stdout']);
        $stderr = stream_get_contents($worker['stderr']);
        fclose($worker['stdout']);
        fclose($worker['stderr']);
        $exitCode = proc_close($worker['process']);
        $rawResult = is_file($worker['result'])
            ? file_get_contents($worker['result'])
            : false;
        $result = is_string($rawResult)
            ? json_decode($rawResult, true)
            : null;
        if (
            $exitCode !== 0
            || !is_array($result)
            || !isset($result['allowed'])
        ) {
            $workerFailures[] = [
                'worker' => $index,
                'exit' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
            continue;
        }
        if ($result['allowed'] === true) {
            $allowedCount++;
        }
    }
    $state = json_decode(
        (string)file_get_contents($rateLimitPath),
        true
    );
    $assert(
        $readyCount === $workerCount
            && $workerFailures === []
            && $allowedCount === 1
            && is_array($state)
            && count($state) === 120,
        'Concurrent requests must atomically enforce the 120-request ceiling'
    );
} finally {
    $removeTree($root);
}

echo "Category refinement PHP tests passed: {$assertions} assertions\n";
