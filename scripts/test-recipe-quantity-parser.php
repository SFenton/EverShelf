#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$quantityTestAssertions = 0;

function quantityTestAssert(bool $condition, string $message): void {
    global $quantityTestAssertions;
    $quantityTestAssertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function quantityTestSameNumber(
    mixed $actual,
    mixed $expected,
    string $message
): void {
    if ($expected === null) {
        quantityTestAssert($actual === null, $message);
        return;
    }
    quantityTestAssert(
        $actual !== null
            && abs((float)$actual - (float)$expected) <= 0.0000001,
        $message
    );
}

function quantityTestThrows(callable $callback, string $message): void {
    $thrown = false;
    try {
        $callback();
    } catch (Throwable $error) {
        $thrown = true;
    }
    quantityTestAssert($thrown, $message);
}

function quantityTestNumericEvidence(
    array $result,
    string $locale = 'und'
): void {
    foreach ([
        'quantity',
        'quantity_max',
        'package_quantity',
    ] as $field) {
        if ($result[$field] === null) {
            continue;
        }
        $spans = array_values(array_filter(
            $result['evidence_spans'],
            static fn(array $span): bool =>
                ($span['field'] ?? null) === $field
        ));
        quantityTestAssert(
            count($spans) === 1,
            "{$field} must have exactly one evidence span"
        );
        $span = $spans[0];
        if ($span['source'] === 'text') {
            quantityTestAssert(
                substr(
                    (string)$result['source_text'],
                    (int)$span['start'],
                    (int)$span['end'] - (int)$span['start']
                ) === $span['text'],
                "{$field} text evidence must be exact"
            );
            quantityTestSameNumber(
                recipeQuantityParseNumberToken(
                    (string)$span['text'],
                    $locale
                ),
                $result[$field],
                "{$field} evidence must reproduce the output"
            );
        } else {
            quantityTestAssert(
                $span['source'] === 'structured'
                    && is_string($span['path'] ?? null)
                    && str_starts_with($span['path'], 'quantity.'),
                "{$field} structured evidence must identify its exact path"
            );
        }
    }
}

$fixturePath = __DIR__ . '/../tests/fixtures/quantity_parser_gold_v1.json';
$fixture = json_decode(
    (string)file_get_contents($fixturePath),
    true,
    64,
    JSON_THROW_ON_ERROR
);
quantityTestAssert(
    $fixture['schema_version'] === 'quantity-parser-gold-v1'
        && count($fixture['cases']) === 53,
    'The frozen quantity parser corpus must contain all 53 v1 cases'
);

foreach ($fixture['cases'] as $case) {
    $result = ($case['source'] ?? null) === 'cookidoo'
        ? recipeQuantityParseStructuredCookidoo($case)
        : recipeQuantityParseText(
            $case['input'],
            (string)($case['locale'] ?? 'und')
        );
    quantityTestAssert(
        $result['status'] === $case['status'],
        $case['id'] . ': status mismatch'
    );
    $expectedQuantity = array_key_exists('quantity_value', $case)
        ? $case['quantity_value']
        : ($case['quantity'] ?? null);
    quantityTestSameNumber(
        $result['quantity'],
        $expectedQuantity,
        $case['id'] . ': quantity mismatch'
    );
    quantityTestSameNumber(
        $result['quantity_max'],
        $case['quantity_max'] ?? null,
        $case['id'] . ': quantity_max mismatch'
    );
    foreach ([
        'unit',
        'ingredient',
        'package_unit',
        'qualifier',
        'note',
    ] as $field) {
        if (!array_key_exists($field, $case)) {
            continue;
        }
        quantityTestAssert(
            $result[$field] === $case[$field],
            $case['id'] . ": {$field} mismatch"
        );
    }
    if (array_key_exists('package_quantity', $case)) {
        quantityTestSameNumber(
            $result['package_quantity'],
            $case['package_quantity'],
            $case['id'] . ': package_quantity mismatch'
        );
    }
    if (array_key_exists('approximate', $case)) {
        quantityTestAssert(
            $result['approximate'] === $case['approximate'],
            $case['id'] . ': approximate mismatch'
        );
    }
    quantityTestAssert(
        $result['ranking_eligible'] === false
            && is_string($result['parser_version'])
            && is_string($result['provenance'])
            && is_array($result['evidence_spans']),
        $case['id'] . ': result contract must remain typed and non-ranking'
    );
    quantityTestNumericEvidence(
        $result,
        (string)($case['locale'] ?? 'und')
    );
}
$qualifiedAmount = recipeQuantityParseText(
    '1 tbsp olive oil, to taste',
    'en-US'
);
$qualifiedOnly = recipeQuantityParseText('salt, to taste', 'en-US');
quantityTestAssert(
    $qualifiedAmount['status'] === 'parsed'
        && $qualifiedAmount['quantity'] === 1.0
        && $qualifiedAmount['unit'] === 'tbsp'
        && $qualifiedAmount['ingredient'] === 'olive oil'
        && $qualifiedAmount['qualifier'] === 'to_taste'
        && $qualifiedAmount['source_text']
            === '1 tbsp olive oil, to taste'
        && $qualifiedOnly['status'] === 'not_present'
        && $qualifiedOnly['ingredient'] === 'salt'
        && $qualifiedOnly['qualifier'] === 'to_taste',
    'Terminal qualifiers must be retained after amount parsing'
);
quantityTestAssert(
    recipeIngredientValidateSourceAmountText(
        '0.125 l',
        0.125,
        null,
        'l'
    ) === '0.125 l'
        && recipeIngredientValidateSourceAmountText(
            '0.125 - 0.375 l',
            0.125,
            0.375,
            'l'
        ) === '0.125 - 0.375 l'
        && recipeIngredientValidateSourceAmountText(
            '1/2 cup',
            0.5,
            null,
            'cup'
        ) === '1/2 cup',
    'Canonical source amount validation must support exact decimals, ranges, and fractions'
);
foreach ([
    ['0.126 l', 0.125, null, 'l'],
    ['0.125 l chilled', 0.125, null, 'l'],
    ['0.125 kg', 0.125, null, 'l'],
    ['0,125 l', 0.125, null, 'l'],
] as [$invalidAmountText, $invalidQuantity, $invalidMaximum, $invalidUnit]) {
    quantityTestThrows(
        static fn(): ?string => recipeIngredientValidateSourceAmountText(
            $invalidAmountText,
            $invalidQuantity,
            $invalidMaximum,
            $invalidUnit
        ),
        'Canonical source amount validation must reject: '
            . $invalidAmountText
    );
}

$structuredNoGuess = recipeQuantityParseStructuredCookidoo([
    'source' => 'cookidoo',
    'quantity' => ['wrong_value' => 260],
    'unit_ref' => '11-unit-rdpf3',
    'unit_notation' => 'g',
    'source_amount_text' => '260 g',
]);
quantityTestAssert(
    $structuredNoGuess['status'] === 'unparsed'
        && $structuredNoGuess['quantity'] === null,
    'Invalid Cookidoo structure must not be guessed from display text'
);
$structuredDecodedSource = recipeQuantityParseStructuredCookidoo([
    'source' => 'cookidoo',
    'quantity' => ['value' => 260, 'from' => null, 'to' => null],
    'unit_ref' => '11-unit-rdpf3',
    'unit_notation' => 'g',
]);
$structuredDecodedJson = recipeQuantityEncodeResult(
    $structuredDecodedSource
);
$fabricatedStructured = $structuredDecodedSource;
$fabricatedStructured['evidence_spans'][0]['path'] = 'quantity.from';
quantityTestAssert(
    recipeQuantityDecodeResult($structuredDecodedJson)['status']
        === 'structured'
        && recipeQuantityDecodeResult(
            recipeQuantityEncodeResult($fabricatedStructured)
        ) === null,
    'Structured decode must require current provenance and exact quantity paths'
);
quantityTestAssert(
    recipeQuantityParse(
        '260 g flour',
        'en-US',
        'cookidoo'
    )['status'] === 'unparsed',
    'Cookidoo text must never enter the deterministic text parser'
);
quantityTestAssert(
    recipeQuantityParseStructuredCookidoo([
        'source' => 'cookidoo',
        'unit_ref' => null,
        'unit_notation' => null,
    ])['status'] === 'unparsed',
    'Missing Cookidoo structured keys must be invalid rather than absent'
);
quantityTestAssert(
    recipeIngredientParseQuantity('2')['quantity'] === 2.0
        && recipeIngredientParseQuantity('2')['unit'] === null
        && recipeIngredientParseQuantity('2 g')['unit'] === 'g',
    'Compatibility parsing must not infer a ranking unit for a bare amount'
);
foreach ([
    '5 spice powder',
    '7 grain bread',
    '21 seasoning salute',
] as $numericIdentity) {
    $numericIdentityResult = recipeQuantityParseText(
        $numericIdentity,
        'en-US'
    );
    quantityTestAssert(
        $numericIdentityResult['status'] === 'not_present'
            && $numericIdentityResult['quantity'] === null
            && $numericIdentityResult['unit'] === null
            && $numericIdentityResult['ingredient'] === $numericIdentity,
        'Implicit count parsing must preserve numeric identity: '
            . $numericIdentity
    );
}
quantityTestAssert(
    recipeQuantityParseText('3 large eggs', 'en-US')['quantity'] === 3.0
        && recipeQuantityParseText('3 large eggs', 'en-US')['unit_raw']
            === 'piece'
        && recipeQuantityParseText('2 lemons', 'en-US')['quantity'] === 2.0
        && recipeQuantityParseText('4 cloves garlic', 'en-US')['unit']
            === 'clove',
    'Conservative implicit counts and explicit count nouns must remain parseable'
);
$enGrouped = recipeQuantityParseText(
    '1,000 g all-purpose flour',
    'en-US'
);
$deGrouped = recipeQuantityParseText('1.000 g Mehl', 'de-DE');
$deDecimal = recipeQuantityParseText('1,5 kg Kartoffeln', 'de-DE');
$ambiguousComma = recipeIngredientParseQuantity('1,000 g');
$ambiguousDot = recipeIngredientParseQuantity('1.000 g');
quantityTestAssert(
    $enGrouped['status'] === 'parsed'
        && $enGrouped['quantity'] === 1000.0
        && $deGrouped['status'] === 'parsed'
        && $deGrouped['quantity'] === 1000.0
        && $deDecimal['quantity'] === 1.5,
    'Text parsing must apply locale-specific decimal and group separators'
);
quantityTestAssert(
    $ambiguousComma['quantity'] === null
        && $ambiguousComma['unit'] === null
        && $ambiguousDot['quantity'] === null
        && $ambiguousDot['unit'] === null
        && recipeQuantityParseNumberToken('1,000', 'und') === null
        && recipeQuantityParseNumberToken('1.000', 'und') === null
        && recipeQuantityParseText(
            '1,000 g flour',
            'und'
        )['status'] === 'unparsed',
    'Locale-less compatibility parsing must reject ambiguous grouped values'
);
quantityTestNumericEvidence($enGrouped, 'en-US');
quantityTestNumericEvidence($deGrouped, 'de-DE');
$invalidDeRange = recipeQuantityParseText(
    '1-1.5 kg Kartoffeln',
    'de-DE'
);
$validDeRange = recipeQuantityParseText(
    '1-1,5 kg Kartoffeln',
    'de-DE'
);
$validEnRange = recipeQuantityParseText(
    '1-1.5 kg potatoes',
    'en-US'
);
quantityTestAssert(
    $invalidDeRange['status'] === 'unparsed'
        && $invalidDeRange['quantity'] === null
        && $invalidDeRange['quantity_max'] === null
        && $invalidDeRange['evidence_spans'] === []
        && $validDeRange['status'] === 'parsed'
        && $validDeRange['quantity_max'] === 1.5
        && $validEnRange['status'] === 'parsed'
        && $validEnRange['quantity_max'] === 1.5,
    'A captured range maximum must parse under the active locale or fail closed'
);
$legacyInvalidRange = recipeQuantityResult(
    '1-1.5 kg Kartoffeln',
    'Kartoffeln',
    'de-DE'
);
$legacyInvalidRange['status'] = 'parsed';
$legacyInvalidRange['quantity'] = 1;
$legacyInvalidRange['unit'] = 'kg';
$legacyInvalidRange['unit_raw'] = 'kg';
$legacyInvalidRange['parser_version'] =
    'recipe-quantity-deterministic-v4';
$legacyInvalidRange['evidence_spans'] = [
    [
        'field' => 'quantity',
        'source' => 'text',
        'start' => 0,
        'end' => 1,
        'text' => '1',
    ],
    [
        'field' => 'quantity_max',
        'source' => 'text',
        'start' => 2,
        'end' => 5,
        'text' => '1.5',
    ],
];
$reparsedInvalidRange = recipeQuantityDecodePersistedResult(
    recipeQuantityEncodeResult($legacyInvalidRange),
    '1-1.5 kg Kartoffeln',
    'de-DE',
    'recipe-quantity-deterministic-v4'
);
quantityTestAssert(
    $reparsedInvalidRange['status'] === 'unparsed'
        && $reparsedInvalidRange['quantity'] === null
        && $reparsedInvalidRange['quantity_max'] === null
        && $reparsedInvalidRange['parser_version']
            === RECIPE_QUANTITY_PARSER_VERSION,
    'Stale orphan-range parses must reparse under the current deterministic version'
);
foreach ([
    ['es-MX', '1,000 g harina', 1000.0],
    ['es-MX', '1.000 kg patatas', 1.0],
    ['es-ES', '1.000 g harina', 1000.0],
    ['es-ES', '1,000 kg patatas', 1.0],
    ['it-IT', '1.000 g farina', 1000.0],
    ['pt-PT', '1.000 g farinha', 1000.0],
    ['pl-PL', '1 000 g mąki', 1000.0],
    ['fr-FR', '1' . "\u{202F}" . '000 g farine', 1000.0],
    ['fr-FR', '1' . "\u{00A0}" . '000 g farine', 1000.0],
    ['fr-CH', '1,5 kg pommes', 1.5],
    ['fr-CH', '1' . "\u{202F}" . '234,5 g farine', 1234.5],
    ['de-CH', "1'000 g Mehl", 1000.0],
    ['de-CH', '1’000 g Mehl', 1000.0],
] as [$regionalLocale, $regionalText, $regionalExpected]) {
    $regionalResult = recipeQuantityParseText(
        $regionalText,
        $regionalLocale
    );
    quantityTestAssert(
        $regionalResult['status'] === 'parsed'
            && abs(
                (float)$regionalResult['quantity'] - $regionalExpected
            ) < 0.0000001,
        'Regional separator profile failed for '
            . $regionalLocale . ': ' . $regionalText
    );
    quantityTestNumericEvidence($regionalResult, $regionalLocale);
}
quantityTestAssert(
    recipeQuantityParseText(
        '1,000 g harina',
        'es'
    )['status'] === 'unparsed',
    'Language-only and unknown profiles must reject ambiguous grouped values'
);
quantityTestAssert(
    recipeQuantityParseText('1½ cups flour', 'en-US')['quantity'] === 1.5,
    'Mixed vulgar fractions must work with or without an intervening space'
);
$narrowGroupedText = '1' . "\u{202F}" . '000 g Mehl';
$narrowGroupedParse = recipeQuantityParseText(
    $narrowGroupedText,
    'de-DE'
);
quantityTestAssert(
    recipeIngredientDisplayName(
        $narrowGroupedText,
        '',
        true,
        ['parse' => $narrowGroupedParse]
    ) === 'Mehl'
        && recipeIngredientIdentityCandidate($narrowGroupedText)
            === 'Mehl'
        && recipeIngredientIdentityCandidate("1'000 g Mehl")
            === 'Mehl'
        && recipeIngredientIdentityCandidate('1’000 g Mehl')
            === 'Mehl'
        && recipeIngredientIdentityCandidate('1000 Island dressing')
            === '1000 Island dressing'
        && recipeIngredientIdentityCandidate('7-Up')
            === '7-Up',
    'Display identity must strip validated grouped amounts without corrupting numeric names'
);
quantityTestAssert(
    recipeQuantityParseText('1 cup of flour', 'en-US')['ingredient']
        === 'flour'
        && recipeQuantityParseText(
            "1 cucchiaio dell'olio",
            'it-IT'
        )['ingredient'] === 'olio',
    'Locale glue words must be removed without locale runtime dependencies'
);
$boundedIdentity = recipeQuantityParseText(
    '1 piece ' . str_repeat('a', 250),
    'en-US'
);
quantityTestAssert(
    mb_strlen($boundedIdentity['ingredient'], 'UTF-8')
        === RECIPE_QUANTITY_MAX_INGREDIENT_LENGTH
        && recipeQuantityEncodeResult($boundedIdentity) !== null,
    'Parser output fields must remain bounded and persistable'
);

$dbPath = __DIR__ . '/../data/.quantity-parser-test-'
    . getmypid() . '.sqlite';
$cleanupPaths = [
    $dbPath,
    $dbPath . '-wal',
    $dbPath . '-shm',
    dirname($dbPath) . '/.' . basename($dbPath) . '.recipe-score.lock',
];
foreach ($cleanupPaths as $path) {
    if (file_exists($path)) {
        unlink($path);
    }
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    initializeDB($db);
    migrateDB($db);
    recipeSchemaMigrate($db);
    recipeSchemaMigrate($db);

    $ingredientColumns = array_column(
        $db->query("PRAGMA table_info(recipe_ingredients)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    quantityTestAssert(
        in_array('quantity_parse_json', $ingredientColumns, true)
            && in_array('quantity_parse_version', $ingredientColumns, true),
        'Quantity parse persistence columns must migrate additively'
    );
    quantityTestAssert(
        (int)$db->query("
            SELECT COUNT(*) FROM sqlite_master
            WHERE type = 'table'
              AND name = 'recipe_quantity_parse_proposals'
        ")->fetchColumn() === 1,
        'Quantity proposal staging table must migrate idempotently'
    );
    $invalidQuantityCatalogCount = (int)$db->query("
        SELECT COUNT(*) FROM recipe_catalog
    ")->fetchColumn();
    foreach ([true, -5, 0, INF, NAN, 1e20] as $invalidQuantityIndex => $invalidQuantity) {
        quantityTestThrows(
            static fn(): array => recipeCatalogSaveVariant($db, [
                'title' => 'Invalid ranking quantity '
                    . $invalidQuantityIndex,
                'ingredients' => [[
                    'name' => 'flour',
                    'quantity' => $invalidQuantity,
                    'unit' => 'g',
                ]],
                'instructions' => [],
            ], [
                'connector' => 'manual',
                'locale' => 'en-US',
            ]),
            'Invalid typed ranking quantity must be rejected: '
                . $invalidQuantityIndex
        );
    }
    quantityTestAssert(
        (int)$db->query("
            SELECT COUNT(*) FROM recipe_catalog
        ")->fetchColumn() === $invalidQuantityCatalogCount,
        'Rejected typed quantities must not persist recipe rows'
    );
    $invalidUtf8 = "1 g flour\xFF";
    $invalidUtf8Row = recipeIngredientNormalizeRow(
        $db,
        ['name' => 'flour', 'raw_text' => $invalidUtf8],
        0,
        'manual',
        'en-US'
    );
    quantityTestAssert(
        recipeQuantityBoundedText(
            $invalidUtf8,
            RECIPE_QUANTITY_MAX_TEXT_LENGTH
        ) === null
            && $invalidUtf8Row['quantity_parse'] === null
            && $invalidUtf8Row['quantity_parse_json'] === null
            && $invalidUtf8Row['quantity_parse_version'] === null,
        'Invalid UTF-8 must not produce persisted parser metadata or versions'
    );
    $qualifiedOilRow = recipeIngredientNormalizeRow(
        $db,
        '1 tbsp olive oil, to taste',
        0,
        'manual',
        'en-US'
    );
    $qualifiedSaltRow = recipeIngredientNormalizeRow(
        $db,
        'salt, to taste',
        1,
        'manual',
        'en-US'
    );
    quantityTestAssert(
        $qualifiedOilRow['normalized_name'] === 'olive oil'
            && $qualifiedOilRow['quantity'] === null
            && $qualifiedOilRow['quantity_parse']['quantity'] === 1.0
            && $qualifiedOilRow['quantity_parse']['qualifier']
                === 'to_taste'
            && $qualifiedOilRow['source_is_required'] === 1
            && $qualifiedOilRow['is_staple'] === 1
            && $qualifiedOilRow['is_required'] === 0
            && $qualifiedSaltRow['normalized_name'] === 'salt'
            && $qualifiedSaltRow['is_staple'] === 1
            && $qualifiedSaltRow['is_required'] === 0,
        'Qualifier-aware normalization must preserve identity, source intent, and staple requiredness'
    );
    $oneCupRecipe = [
        'title' => 'Exact quantity identity',
        'ingredients' => ['1 cup flour'],
        'instructions' => ['Mix'],
    ];
    $twoCupRecipe = $oneCupRecipe;
    $twoCupRecipe['ingredients'] = ['2 cups flour'];
    $oneCupSaved = recipeCatalogSaveVariant(
        $db,
        $oneCupRecipe,
        ['connector' => 'manual', 'locale' => 'en-us']
    );
    $twoCupSaved = recipeCatalogSaveVariant(
        $db,
        $twoCupRecipe,
        ['connector' => 'manual', 'locale' => 'en-US']
    );
    $oneCupRepeated = recipeCatalogSaveVariant(
        $db,
        [
            'title' => 'Exact quantity identity',
            'ingredients' => ['1  cup flour'],
            'instructions' => ['Mix'],
        ],
        ['connector' => 'manual', 'locale' => 'en-US']
    );
    quantityTestAssert(
        (int)$oneCupSaved['id'] !== (int)$twoCupSaved['id']
            && (int)$oneCupRepeated['id'] === (int)$oneCupSaved['id'],
        'Exact identity must distinguish source quantities while deduping normalized-equivalent text'
    );
    $localeCaseRecipe = [
        'title' => 'Locale casing identity',
        'ingredients' => ['1 cup flour'],
        'instructions' => ['Mix'],
    ];
    $localeCaseLower = recipeCatalogSaveVariant(
        $db,
        $localeCaseRecipe,
        [
            'connector' => 'manual',
            'language' => 'en-us',
            'locale' => 'en-us',
        ]
    );
    $localeCaseCanonical = recipeCatalogSaveVariant(
        $db,
        $localeCaseRecipe,
        [
            'connector' => 'manual',
            'language' => 'en-US',
            'locale' => 'en-US',
        ]
    );
    quantityTestAssert(
        (int)$localeCaseLower['id'] === (int)$localeCaseCanonical['id']
            && $localeCaseCanonical['language'] === 'en-US'
            && $localeCaseCanonical['origins'][0]['locale'] === 'en-US',
        'Locale casing must canonicalize before persistence and exact identity hashing'
    );
    $localeIdentityRecipe = [
        'title' => 'Exact locale identity',
        'ingredients' => ['1,000 g flour'],
        'instructions' => ['Mix'],
    ];
    $localeIdentityEn = recipeCatalogSaveVariant(
        $db,
        $localeIdentityRecipe,
        [
            'connector' => 'manual',
            'language' => '',
            'locale' => 'en-US',
        ]
    );
    $localeIdentityDe = recipeCatalogSaveVariant(
        $db,
        $localeIdentityRecipe,
        [
            'connector' => 'manual',
            'language' => '',
            'locale' => 'de-DE',
        ]
    );
    quantityTestAssert(
        $localeIdentityEn['language'] === 'und'
            && $localeIdentityDe['language'] === 'und'
            && (int)$localeIdentityEn['id']
                !== (int)$localeIdentityDe['id']
            && (float)$localeIdentityEn['ingredients'][0]
                ['quantity_parse']['quantity'] === 1000.0
            && (float)$localeIdentityDe['ingredients'][0]
                ['quantity_parse']['quantity'] === 1.0,
        'Exact identity must include effective parse locale when display language is und'
    );
    $groupedDetailSaved = recipeCatalogSaveVariant($db, [
        'title' => 'Grouped display identity',
        'ingredients' => [
            '1' . "\u{202F}" . '000 g Mehl',
        ],
        'instructions' => [],
    ], [
        'connector' => 'manual',
        'locale' => 'de-DE',
    ]);
    $groupedDetail = recipeDetailLoadIngredients(
        $db,
        (int)$groupedDetailSaved['id']
    );
    $groupedDetailRow = $groupedDetail['rows'][0];
    $swissDetailSaved = recipeCatalogSaveVariant($db, [
        'title' => 'Swiss grouped display identity',
        'ingredients' => ["1'000 g Mehl"],
        'instructions' => [],
    ], [
        'connector' => 'manual',
        'locale' => 'de-CH',
    ]);
    $swissDetail = recipeDetailLoadIngredients(
        $db,
        (int)$swissDetailSaved['id']
    );
    quantityTestAssert(
        $groupedDetailRow['display_name'] === 'Mehl'
            && recipeDetailCanonicalShoppingKey($groupedDetailRow)
                === 'name:mehl'
            && $swissDetail['rows'][0]['display_name'] === 'Mehl'
            && recipeDetailCanonicalShoppingKey(
                $swissDetail['rows'][0]
            ) === 'name:mehl',
        'Grouped advisory amounts must share display and grocery identity'
    );
    $whitespaceRawText = '1.000 g '
        . str_repeat(' ', 210)
        . 'Mehl';
    $whitespaceDetailSaved = recipeCatalogSaveVariant($db, [
        'title' => 'Whitespace stale detail parse',
        'language' => 'de',
        'ingredients' => [$whitespaceRawText],
        'instructions' => [],
    ], [
        'connector' => 'manual',
        'language' => 'de',
        'locale' => 'de-DE',
    ]);
    $whitespaceParseStmt = $db->prepare("
        SELECT quantity_parse_json
        FROM recipe_ingredients
        WHERE recipe_id = ? AND position = 0
    ");
    $whitespaceParseStmt->execute([(int)$whitespaceDetailSaved['id']]);
    $whitespaceStaleParse = json_decode(
        (string)$whitespaceParseStmt->fetchColumn(),
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    $whitespaceStaleParse['parser_version'] =
        'recipe-quantity-deterministic-v4';
    $whitespaceStaleParse['locale'] = 'und';
    $db->prepare("
        UPDATE recipe_ingredients
        SET quantity_parse_json = ?, quantity_parse_version = ?
        WHERE recipe_id = ? AND position = 0
    ")->execute([
        json_encode(
            $whitespaceStaleParse,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        'recipe-quantity-deterministic-v4',
        (int)$whitespaceDetailSaved['id'],
    ]);
    $whitespaceDetail = recipeDetailLoadIngredients(
        $db,
        (int)$whitespaceDetailSaved['id']
    );
    $whitespaceDetailRow = $whitespaceDetail['rows'][0];
    quantityTestAssert(
        $whitespaceDetailRow['source_text'] === '1.000 g Mehl'
            && $whitespaceDetailRow['display_name'] === 'Mehl'
            && $whitespaceDetailRow['quantity_parse']['parser_version']
                === RECIPE_QUANTITY_PARSER_VERSION
            && $whitespaceDetailRow['quantity_parse']['locale'] === 'de-DE'
            && (float)$whitespaceDetailRow['quantity_parse']['quantity']
                === 1000.0,
        'Detail parsing must reparse full raw text before public truncation'
    );
    foreach ([
        '5 spice powder',
        '7 grain bread',
        '21 seasoning salute',
    ] as $numericIdentity) {
        $identityRow = recipeIngredientNormalizeRow(
            $db,
            $numericIdentity,
            0,
            'manual',
            'en-US'
        );
        quantityTestAssert(
            $identityRow['normalized_name']
                === recipeIngredientNormalizeName($numericIdentity)
                && $identityRow['quantity'] === null
                && $identityRow['quantity_parse']['ingredient']
                    === $numericIdentity,
            'Manual normalization must retain numeric product identity: '
                . $numericIdentity
        );
    }
    $localeOnlySaved = recipeCatalogSaveVariant($db, [
        'title' => 'Locale-only quantity parse',
        'ingredients' => ['1.000 g Mehl'],
        'instructions' => [],
    ], [
        'connector' => 'manual',
        'language' => '',
        'locale' => 'de-DE',
    ]);
    $localeOnlyRead = recipeCatalogGetById(
        $db,
        (int)$localeOnlySaved['id']
    );
    quantityTestAssert(
        $localeOnlyRead['language'] === 'und'
                && $localeOnlyRead['ingredients'][0]['quantity'] === null
                && $localeOnlyRead['ingredients'][0]['quantity_parse']['status']
                    === 'parsed'
                && $localeOnlyRead['ingredients'][0]['quantity_parse']['locale']
                    === 'de-DE'
                && (float)$localeOnlyRead['ingredients'][0]['quantity_parse']
                    ['quantity'] === 1000.0,
        'Locale-only metadata must round-trip its persisted parse locale without changing display language'
    );
    $localeOnlyParseStmt = $db->prepare("
        SELECT quantity_parse_json
        FROM recipe_ingredients
        WHERE recipe_id = ? AND position = 0
    ");
    $localeOnlyParseStmt->execute([(int)$localeOnlySaved['id']]);
    $localeOnlyLegacyParse = json_decode(
        (string)$localeOnlyParseStmt->fetchColumn(),
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    $localeOnlyLegacyParse['parser_version'] =
        'recipe-quantity-deterministic-v0';
    $localeOnlyLegacyParse['locale'] = 'und';
    $db->prepare("
        UPDATE recipe_ingredients
        SET quantity_parse_json = ?, quantity_parse_version = ?
        WHERE recipe_id = ? AND position = 0
    ")->execute([
        json_encode(
            $localeOnlyLegacyParse,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        'recipe-quantity-deterministic-v0',
        (int)$localeOnlySaved['id'],
    ]);
    $db->prepare("
        INSERT INTO recipe_origins (
            recipe_id, connector, external_id, locale, availability
        )
        VALUES (?, 'generated', ?, 'en-US', 'available')
    ")->execute([
        (int)$localeOnlySaved['id'],
        'quantity-secondary-' . (int)$localeOnlySaved['id'],
    ]);
    $localeOnlyLegacyRead = recipeCatalogGetById(
        $db,
        (int)$localeOnlySaved['id']
    );
    quantityTestAssert(
        $localeOnlyLegacyRead['ingredients'][0]['quantity_parse']['status']
            === 'parsed'
            && $localeOnlyLegacyRead['ingredients'][0]['quantity_parse']
                ['locale'] === 'de-DE'
            && (float)$localeOnlyLegacyRead['ingredients'][0]
                ['quantity_parse']['quantity'] === 1000.0
            && $localeOnlyLegacyRead['ingredients'][0]['quantity'] === null,
        'Legacy locale-less parses must use only the primary origin locale'
    );
    $broadLanguageSaved = recipeCatalogSaveVariant($db, [
        'title' => 'Broad language stale locale',
        'language' => 'de',
        'ingredients' => ['1.000 g Mehl'],
        'instructions' => [],
    ], [
        'connector' => 'manual',
        'language' => 'de',
        'locale' => 'de-DE',
    ]);
    $broadLanguageParseStmt = $db->prepare("
        SELECT quantity_parse_json
        FROM recipe_ingredients
        WHERE recipe_id = ? AND position = 0
    ");
    $broadLanguageParseStmt->execute([(int)$broadLanguageSaved['id']]);
    $broadLanguageStale = json_decode(
        (string)$broadLanguageParseStmt->fetchColumn(),
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    $broadLanguageStale['parser_version'] =
        'recipe-quantity-deterministic-v0';
    $broadLanguageStale['locale'] = 'und';
    $db->prepare("
        UPDATE recipe_ingredients
        SET quantity_parse_json = ?, quantity_parse_version = ?
        WHERE recipe_id = ? AND position = 0
    ")->execute([
        json_encode(
            $broadLanguageStale,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        'recipe-quantity-deterministic-v0',
        (int)$broadLanguageSaved['id'],
    ]);
    $broadLanguageRead = recipeCatalogGetById(
        $db,
        (int)$broadLanguageSaved['id']
    );
    quantityTestAssert(
        $broadLanguageRead['language'] === 'de'
            && $broadLanguageRead['ingredients'][0]['quantity_parse']
                ['locale'] === 'de-DE'
            && (float)$broadLanguageRead['ingredients'][0]
                ['quantity_parse']['quantity'] === 1000.0,
        'Stale reparsing must prefer a regional primary origin over broad display language'
    );

    $saved = recipeCatalogSaveVariant($db, [
        'title' => 'Quantity parser integration',
        'language' => 'en-US',
        'ingredients' => [
            '2 x 400 g cans chopped tomatoes',
            'salt, to taste',
        ],
        'instructions' => [],
    ], [
        'connector' => 'manual',
        'language' => 'en-US',
        'locale' => 'en-US',
    ]);
    quantityTestAssert(
        $saved['ingredients'][0]['quantity'] === null
            && $saved['ingredients'][0]['unit'] === null
            && $saved['ingredients'][0]['normalized_name']
                === 'chopped tomatoes'
            && $saved['ingredients'][0]['quantity_parse']['status']
                === 'parsed'
            && (float)$saved['ingredients'][0]['quantity_parse']['quantity']
                === 2.0
            && (float)$saved['ingredients'][0]['quantity_parse']
                ['package_quantity'] === 400.0
            && $saved['ingredients'][1]['quantity_parse']['qualifier']
                === 'to_taste',
        'Manual text parsing must persist separately without enabling ranking quantities'
    );
    $scoreRecipe = recipeScoreLoadRecipes($db, [(int)$saved['id']]);
    quantityTestAssert(
        $scoreRecipe[(int)$saved['id']]['ingredients'][0]['quantity'] === null
            && $scoreRecipe[(int)$saved['id']]['ingredients'][0]['unit'] === null,
        'Score loaders must continue to ignore parsed source-text amounts'
    );

    $explicit = recipeCatalogSaveVariant($db, [
        'title' => 'Explicit ranking quantity',
        'language' => 'en-US',
        'ingredients' => [[
            'name' => 'flour',
            'quantity' => 500,
            'unit' => 'g',
        ]],
        'instructions' => [],
    ], [
        'connector' => 'manual',
        'language' => 'en-US',
        'locale' => 'en-US',
    ]);
    quantityTestAssert(
        $explicit['ingredients'][0]['quantity'] === 500.0
            && $explicit['ingredients'][0]['unit'] === 'g',
        'Existing explicit manual ranking quantities must remain unchanged'
    );

    $cookidooRanking = recipeIngredientNormalizeRow(
        $db,
        ['name' => 'flour', 'raw_text' => 'flour'],
        0,
        'cookidoo',
        'en-GB'
    );
    $cookidooSource = recipeIngredientNormalizeSourceRow($db, [
        'name' => 'Flour',
        'source_quantity' => 260,
        'source_quantity_max' => null,
        'source_unit' => 'g',
        'source_amount_text' => '260 g',
    ], 0);
    quantityTestAssert(
        $cookidooRanking['quantity_parse_json'] === null
            && $cookidooRanking['quantity'] === null
            && $cookidooSource['source_quantity'] === 260.0
            && $cookidooSource['source_unit'] === 'g'
            && $cookidooSource['source_amount_text'] === '260 g',
        'Cookidoo structured amounts must remain unchanged and display-only'
    );

    $persistedParseStmt = $db->prepare("
        SELECT quantity_parse_json, quantity_parse_version
        FROM recipe_ingredients
        WHERE recipe_id = ? AND position = 0
    ");
    $persistedParseStmt->execute([(int)$saved['id']]);
    $persistedParse = $persistedParseStmt->fetch(PDO::FETCH_ASSOC);
    $currentParse = json_decode(
        (string)$persistedParse['quantity_parse_json'],
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    quantityTestAssert(
        recipeQuantityDecodeResult(
            (string)$persistedParse['quantity_parse_json']
        ) !== null
            && $persistedParse['quantity_parse_version']
                === RECIPE_QUANTITY_PARSER_VERSION,
        'Current deterministic persisted parses must validate semantically'
    );

    $fabricatedParse = $currentParse;
    $fabricatedParse['quantity'] = 999;
    $fabricatedParse['unit'] = 'kg';
    $fabricatedParse['unit_raw'] = 'kg';
    $fabricatedJson = json_encode(
        $fabricatedParse,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    quantityTestAssert(
        recipeQuantityDecodeResult($fabricatedJson) === null,
        'Decode must reject fabricated 999/kg outputs with stale evidence'
    );
    $db->prepare("
        UPDATE recipe_ingredients
        SET quantity_parse_json = ?, quantity_parse_version = ?
        WHERE recipe_id = ? AND position = 0
    ")->execute([
        $fabricatedJson,
        RECIPE_QUANTITY_PARSER_VERSION,
        (int)$saved['id'],
    ]);
    $fabricatedRead = recipeCatalogGetById($db, (int)$saved['id']);
    quantityTestAssert(
        $fabricatedRead['ingredients'][0]['quantity_parse'] === null
            && $fabricatedRead['ingredients'][0]['quantity'] === null
            && $fabricatedRead['ingredients'][0]['unit'] === null,
        'Current-version fabricated parse metadata must fail closed without ranking leakage'
    );

    $staleParse = $currentParse;
    $staleParse['parser_version'] = 'recipe-quantity-deterministic-v0';
    unset($staleParse['locale']);
    $staleJson = json_encode(
        $staleParse,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    quantityTestAssert(
        recipeQuantityDecodeResult($staleJson) === null,
        'Decode must reject stale v0 deterministic parses'
    );
    $db->prepare("
        UPDATE recipe_ingredients
        SET quantity_parse_json = ?, quantity_parse_version = ?
        WHERE recipe_id = ? AND position = 0
    ")->execute([
        $staleJson,
        'recipe-quantity-deterministic-v0',
        (int)$saved['id'],
    ]);
    $staleRead = recipeCatalogGetById($db, (int)$saved['id']);
    quantityTestAssert(
        $staleRead['ingredients'][0]['quantity_parse']['parser_version']
            === RECIPE_QUANTITY_PARSER_VERSION
            && $staleRead['ingredients'][0]['quantity_parse']['source_text']
                === '2 x 400 g cans chopped tomatoes'
            && (float)$staleRead['ingredients'][0]['quantity_parse']
                ['quantity'] === 2.0
            && $staleRead['ingredients'][0]['quantity'] === null,
        'Repository reads must safely reparse stale deterministic metadata without ranking'
    );

    $mismatchedSource = recipeQuantityParseText(
        '999 kg fabricated ingredient',
        'en-US'
    );
    $db->prepare("
        UPDATE recipe_ingredients
        SET quantity_parse_json = ?, quantity_parse_version = ?
        WHERE recipe_id = ? AND position = 0
    ")->execute([
        recipeQuantityEncodeResult($mismatchedSource),
        RECIPE_QUANTITY_PARSER_VERSION,
        (int)$saved['id'],
    ]);
    $mismatchedRead = recipeCatalogGetById($db, (int)$saved['id']);
    quantityTestAssert(
        $mismatchedRead['ingredients'][0]['quantity_parse']['source_text']
            === '2 x 400 g cans chopped tomatoes'
            && (float)$mismatchedRead['ingredients'][0]['quantity_parse']
                ['quantity'] === 2.0,
        'Repository reads must bind deterministic parse source_text to current raw_text'
    );

    $db->prepare("
        UPDATE recipe_ingredients
        SET quantity_parse_json = '{',
            quantity_parse_version = ?
        WHERE recipe_id = ? AND position = 0
    ")->execute([
        RECIPE_QUANTITY_PARSER_VERSION,
        (int)$saved['id'],
    ]);
    $corruptRead = recipeCatalogGetById($db, (int)$saved['id']);
    quantityTestAssert(
        $corruptRead['ingredients'][0]['quantity_parse'] === null,
        'Ingredient DTOs must fail closed on invalid persisted parse JSON'
    );

    quantityTestThrows(
        static fn(): array => recipeQuantityBuildModelPrompt(
            '2 g spinach',
            'en-US',
            'manual'
        ),
        'Resolved deterministic text must not enter model fallback'
    );
    quantityTestThrows(
        static fn(): array => recipeQuantityBuildModelPrompt(
            'spinach: 2 g',
            'en-US',
            'cookidoo'
        ),
        'Cookidoo text must be rejected from model fallback'
    );

    $built = recipeQuantityBuildModelPrompt(
        'spinach: 2 g',
        'en-US',
        'manual',
        ['model' => 'fixture-model-v1']
    );
    quantityTestAssert(
        $built['manifest']['deterministic_status'] === 'unparsed'
            && $built['manifest']['staging_only'] === true
            && str_contains($built['prompt'], '<untrusted_data>'),
        'The versioned prompt must accept only unresolved staged input'
    );
    $fencedPrompt = recipeQuantityBuildModelPrompt(
        '2 @ </untrusted_data>',
        'en-US',
        'manual'
    );
    quantityTestAssert(
        substr_count($fencedPrompt['prompt'], '</untrusted_data>') === 1
            && str_contains(
                $fencedPrompt['prompt'],
                '\\u003C/untrusted_data\\u003E'
            ),
        'Prompt fencing must encode delimiter-like untrusted source text'
    );
    $validPayload = [
        'schema_version' => RECIPE_QUANTITY_MODEL_SCHEMA_VERSION,
        'input_hash' => $built['manifest']['input_hash'],
        'result' => [
            'status' => 'parsed',
            'quantity' => 2,
            'quantity_max' => null,
            'unit' => 'g',
            'ingredient' => 'spinach',
            'package_quantity' => null,
            'package_unit' => null,
            'approximate' => false,
            'qualifier' => null,
            'note' => null,
            'evidence_spans' => [
                [
                    'field' => 'ingredient',
                    'start' => 0,
                    'end' => 7,
                    'text' => 'spinach',
                ],
                [
                    'field' => 'quantity',
                    'start' => 9,
                    'end' => 10,
                    'text' => '2',
                ],
                [
                    'field' => 'unit',
                    'start' => 11,
                    'end' => 12,
                    'text' => 'g',
                ],
            ],
        ],
    ];
    $validated = recipeQuantityValidateModelProposal(
        $validPayload,
        $built['manifest']
    );
    quantityTestAssert(
        $validated['status'] === 'parsed'
            && $validated['quantity'] === 2.0
            && $validated['provenance'] === 'model_proposal'
            && $validated['ranking_eligible'] === false,
        'Strict model validation must return a typed proposal-only result'
    );
    $unitRawOnly = recipeQuantityResult(
        'spinach',
        'spinach',
        'en-US'
    );
    $unitRawOnly['status'] = 'unparsed';
    $unitRawOnly['unit_raw'] = 'kg';
    $unitRawOnly['parser_version'] =
        RECIPE_QUANTITY_MODEL_PROMPT_VERSION;
    $unitRawOnly['provenance'] = 'model_proposal';
    $unitRawOnly['evidence_spans'] = [[
        'field' => 'ingredient',
        'source' => 'text',
        'start' => 0,
        'end' => 7,
        'text' => 'spinach',
    ]];
    quantityTestAssert(
        recipeQuantityDecodeResult(
            recipeQuantityEncodeResult($unitRawOnly)
        ) === null,
        'Model decode must reject unit_raw when canonical unit is null'
    );
    $juiceBuilt = recipeQuantityBuildModelPrompt(
        'juice of 2 lemon',
        'en-US',
        'manual'
    );
    $juicePayload = [
        'schema_version' => RECIPE_QUANTITY_MODEL_SCHEMA_VERSION,
        'input_hash' => $juiceBuilt['manifest']['input_hash'],
        'result' => [
            'status' => 'ambiguous',
            'quantity' => 2,
            'quantity_max' => null,
            'unit' => null,
            'ingredient' => 'lemon',
            'package_quantity' => null,
            'package_unit' => null,
            'approximate' => false,
            'qualifier' => null,
            'note' => null,
            'evidence_spans' => [
                [
                    'field' => 'quantity',
                    'start' => 9,
                    'end' => 10,
                    'text' => '2',
                ],
                [
                    'field' => 'ingredient',
                    'start' => 11,
                    'end' => 16,
                    'text' => 'lemon',
                ],
            ],
        ],
    ];
    $juiceValidated = recipeQuantityValidateModelProposal(
        $juicePayload,
        $juiceBuilt['manifest']
    );
    quantityTestAssert(
        $juiceValidated['status'] === 'ambiguous'
            && $juiceValidated['quantity'] === 2.0
            && $juiceValidated['quantity_max'] === null
            && $juiceValidated['unit'] === null
            && recipeQuantityDecodeResult(
                recipeQuantityEncodeResult($juiceValidated)
            ) !== null,
        'Legitimate unitless juice-of ambiguity must remain stageable and decodable'
    );

    $invalidJuiceRangeText = 'juice of 2 lemon 400';
    $invalidJuiceRangeBuilt = recipeQuantityBuildModelPrompt(
        $invalidJuiceRangeText,
        'en-US',
        'manual'
    );
    $invalidJuiceRangePayload = $juicePayload;
    $invalidJuiceRangePayload['input_hash'] =
        $invalidJuiceRangeBuilt['manifest']['input_hash'];
    $invalidJuiceRangePayload['result']['quantity_max'] = 400;
    $invalidJuiceRangePayload['result']['evidence_spans'][] = [
        'field' => 'quantity_max',
        'start' => 17,
        'end' => 20,
        'text' => '400',
    ];
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $invalidJuiceRangePayload,
            $invalidJuiceRangeBuilt['manifest']
        ),
        'Unitless range proposals must require an explicit range separator'
    );
    $invalidJuiceRangeDecoded = recipeQuantityResult(
        $invalidJuiceRangeText,
        'lemon',
        'en-US'
    );
    $invalidJuiceRangeDecoded['status'] = 'ambiguous';
    $invalidJuiceRangeDecoded['quantity'] = 2;
    $invalidJuiceRangeDecoded['quantity_max'] = 400;
    $invalidJuiceRangeDecoded['parser_version'] =
        RECIPE_QUANTITY_MODEL_PROMPT_VERSION;
    $invalidJuiceRangeDecoded['provenance'] = 'model_proposal';
    $invalidJuiceRangeDecoded['evidence_spans'] = [
        [
            'field' => 'quantity',
            'source' => 'text',
            'start' => 9,
            'end' => 10,
            'text' => '2',
        ],
        [
            'field' => 'ingredient',
            'source' => 'text',
            'start' => 11,
            'end' => 16,
            'text' => 'lemon',
        ],
        [
            'field' => 'quantity_max',
            'source' => 'text',
            'start' => 17,
            'end' => 20,
            'text' => '400',
        ],
    ];
    quantityTestAssert(
        recipeQuantityDecodeResult(
            recipeQuantityEncodeResult($invalidJuiceRangeDecoded)
        ) === null,
        'Persisted model semantics must reject text-separated unitless ranges'
    );

    $unitlessPackageText = 'juice of 2 lemon 400 g';
    $unitlessPackageBuilt = recipeQuantityBuildModelPrompt(
        $unitlessPackageText,
        'en-US',
        'manual'
    );
    $unitlessPackagePayload = $juicePayload;
    $unitlessPackagePayload['input_hash'] =
        $unitlessPackageBuilt['manifest']['input_hash'];
    $unitlessPackagePayload['result']['package_quantity'] = 400;
    $unitlessPackagePayload['result']['package_unit'] = 'g';
    $unitlessPackagePayload['result']['evidence_spans'][] = [
        'field' => 'package_quantity',
        'start' => 17,
        'end' => 20,
        'text' => '400',
    ];
    $unitlessPackagePayload['result']['evidence_spans'][] = [
        'field' => 'package_unit',
        'start' => 21,
        'end' => 22,
        'text' => 'g',
    ];
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $unitlessPackagePayload,
            $unitlessPackageBuilt['manifest']
        ),
        'Unitless proposals must reject package amounts without a main package unit'
    );
    $unitlessPackageDecoded = recipeQuantityResult(
        $unitlessPackageText,
        'lemon',
        'en-US'
    );
    $unitlessPackageDecoded['status'] = 'ambiguous';
    $unitlessPackageDecoded['quantity'] = 2;
    $unitlessPackageDecoded['package_quantity'] = 400;
    $unitlessPackageDecoded['package_unit'] = 'g';
    $unitlessPackageDecoded['parser_version'] =
        RECIPE_QUANTITY_MODEL_PROMPT_VERSION;
    $unitlessPackageDecoded['provenance'] = 'model_proposal';
    $unitlessPackageDecoded['evidence_spans'] = [
        [
            'field' => 'quantity',
            'source' => 'text',
            'start' => 9,
            'end' => 10,
            'text' => '2',
        ],
        [
            'field' => 'ingredient',
            'source' => 'text',
            'start' => 11,
            'end' => 16,
            'text' => 'lemon',
        ],
        [
            'field' => 'package_quantity',
            'source' => 'text',
            'start' => 17,
            'end' => 20,
            'text' => '400',
        ],
        [
            'field' => 'package_unit',
            'source' => 'text',
            'start' => 21,
            'end' => 22,
            'text' => 'g',
        ],
    ];
    quantityTestAssert(
        recipeQuantityDecodeResult(
            recipeQuantityEncodeResult($unitlessPackageDecoded)
        ) === null,
        'Persisted model semantics must reject unitless package amounts'
    );

    $invalidNumber = $validPayload;
    $invalidNumber['result']['quantity'] = 3;
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $invalidNumber,
            $built['manifest']
        ),
        'Model validation must reject numbers not proven by the cited span'
    );
    $invalidUnit = $validPayload;
    $invalidUnit['result']['unit'] = 'kg';
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $invalidUnit,
            $built['manifest']
        ),
        'Model validation must reject units not proven by the cited alias'
    );
    $identifierBuilt = recipeQuantityBuildModelPrompt(
        'vitamin B12 powder g',
        'en-US',
        'manual'
    );
    $identifierPayload = $validPayload;
    $identifierPayload['input_hash'] =
        $identifierBuilt['manifest']['input_hash'];
    $identifierPayload['result']['quantity'] = 12;
    $identifierPayload['result']['ingredient'] = 'vitamin';
    $identifierPayload['result']['evidence_spans'] = [
        [
            'field' => 'ingredient',
            'start' => 0,
            'end' => 7,
            'text' => 'vitamin',
        ],
        [
            'field' => 'quantity',
            'start' => 9,
            'end' => 11,
            'text' => '12',
        ],
        [
            'field' => 'unit',
            'start' => 19,
            'end' => 20,
            'text' => 'g',
        ],
    ];
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $identifierPayload,
            $identifierBuilt['manifest']
        ),
        'Model validation must reject numeric evidence embedded in identifiers'
    );
    foreach ([
        'vitamin B-12 powder g',
        'vitamin B_12 powder g',
        'vitamin B.12 powder g',
        'vitamin B 12 powder g',
        'vitamin B+12 powder g',
        'vitamin B−12 powder g',
        'vitamin B‑12 powder g',
        'vitamin B–12 powder g',
        'vitamin B—12 powder g',
    ] as $punctuatedIdentifier) {
        $punctuatedBuilt = recipeQuantityBuildModelPrompt(
            $punctuatedIdentifier,
            'en-US',
            'manual'
        );
        $punctuatedPayload = $identifierPayload;
        $punctuatedPayload['input_hash'] =
            $punctuatedBuilt['manifest']['input_hash'];
        $punctuatedPayload['result']['evidence_spans'][1] = [
            'field' => 'quantity',
            'start' => strpos($punctuatedIdentifier, '12'),
            'end' => strpos($punctuatedIdentifier, '12') + 2,
            'text' => '12',
        ];
        $punctuatedUnitStart = strrpos($punctuatedIdentifier, 'g');
        $punctuatedPayload['result']['evidence_spans'][2] = [
            'field' => 'unit',
            'start' => $punctuatedUnitStart,
            'end' => $punctuatedUnitStart + 1,
            'text' => 'g',
        ];
        quantityTestThrows(
            static fn(): array => recipeQuantityValidateModelProposal(
                $punctuatedPayload,
                $punctuatedBuilt['manifest']
            ),
            'Model validation must reject punctuated identifier number: '
                . $punctuatedIdentifier
        );
    }
    foreach ([
        'vitamin B‑12 powder g',
        'vitamin B 12 powder g',
        'vitamin B+12 powder g',
        'vitamin B−12 powder g',
    ] as $punctuatedDecodedSource) {
        $punctuatedDecodedQuantityStart = strpos(
            $punctuatedDecodedSource,
            '12'
        );
        $punctuatedDecodedUnitStart = strrpos(
            $punctuatedDecodedSource,
            'g'
        );
        $punctuatedDecoded = recipeQuantityResult(
            $punctuatedDecodedSource,
            'vitamin',
            'en-US'
        );
        $punctuatedDecoded['status'] = 'parsed';
        $punctuatedDecoded['quantity'] = 12;
        $punctuatedDecoded['unit'] = 'g';
        $punctuatedDecoded['unit_raw'] = 'g';
        $punctuatedDecoded['parser_version'] =
            RECIPE_QUANTITY_MODEL_PROMPT_VERSION;
        $punctuatedDecoded['provenance'] = 'model_proposal';
        $punctuatedDecoded['evidence_spans'] = [
            [
                'field' => 'ingredient',
                'source' => 'text',
                'start' => 0,
                'end' => 7,
                'text' => 'vitamin',
            ],
            [
                'field' => 'quantity',
                'source' => 'text',
                'start' => $punctuatedDecodedQuantityStart,
                'end' => $punctuatedDecodedQuantityStart + 2,
                'text' => '12',
            ],
            [
                'field' => 'unit',
                'source' => 'text',
                'start' => $punctuatedDecodedUnitStart,
                'end' => $punctuatedDecodedUnitStart + 1,
                'text' => 'g',
            ],
        ];
        quantityTestAssert(
            recipeQuantityDecodeResult(
                recipeQuantityEncodeResult($punctuatedDecoded)
            ) === null,
            'Persisted model semantics must reject identifier evidence: '
                . $punctuatedDecodedSource
        );
    }
    foreach (['-', '–', '—'] as $rangeDash) {
        $rangeText = 'spinach: 2' . $rangeDash . '3 g';
        $rangeBuilt = recipeQuantityBuildModelPrompt(
            $rangeText,
            'en-US',
            'manual'
        );
        $rangePayload = $validPayload;
        $rangePayload['input_hash'] = $rangeBuilt['manifest']['input_hash'];
        $rangePayload['result']['quantity_max'] = 3;
        $rangeQuantityMaxStart = strpos($rangeText, '3');
        $rangeUnitStart = strrpos($rangeText, 'g');
        $rangePayload['result']['evidence_spans'] = [
            [
                'field' => 'ingredient',
                'start' => 0,
                'end' => 7,
                'text' => 'spinach',
            ],
            [
                'field' => 'quantity',
                'start' => 9,
                'end' => 10,
                'text' => '2',
            ],
            [
                'field' => 'quantity_max',
                'start' => $rangeQuantityMaxStart,
                'end' => $rangeQuantityMaxStart + 1,
                'text' => '3',
            ],
            [
                'field' => 'unit',
                'start' => $rangeUnitStart,
                'end' => $rangeUnitStart + 1,
                'text' => 'g',
            ],
        ];
        quantityTestAssert(
            recipeQuantityValidateModelProposal(
                $rangePayload,
                $rangeBuilt['manifest']
            )['quantity_max'] === 3.0,
            'Standalone numeric range must remain valid: ' . $rangeText
        );
    }
    $implausibleBuilt = recipeQuantityBuildModelPrompt(
        'vitamin 12 powder g',
        'en-US',
        'manual'
    );
    $implausiblePayload = $identifierPayload;
    $implausiblePayload['input_hash'] =
        $implausibleBuilt['manifest']['input_hash'];
    $implausiblePayload['result']['evidence_spans'][1] = [
        'field' => 'quantity',
        'start' => 8,
        'end' => 10,
        'text' => '12',
    ];
    $implausiblePayload['result']['evidence_spans'][2] = [
        'field' => 'unit',
        'start' => 18,
        'end' => 19,
        'text' => 'g',
    ];
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $implausiblePayload,
            $implausibleBuilt['manifest']
        ),
        'Model validation must reject implausible number/unit layout'
    );
    $reusedBuilt = recipeQuantityBuildModelPrompt(
        '2 g',
        'en-US',
        'manual'
    );
    $reusedPayload = $validPayload;
    $reusedPayload['input_hash'] = $reusedBuilt['manifest']['input_hash'];
    $reusedPayload['result']['ingredient'] = '2';
    $reusedPayload['result']['evidence_spans'] = [
        [
            'field' => 'ingredient',
            'start' => 0,
            'end' => 1,
            'text' => '2',
        ],
        [
            'field' => 'quantity',
            'start' => 0,
            'end' => 1,
            'text' => '2',
        ],
        [
            'field' => 'unit',
            'start' => 2,
            'end' => 3,
            'text' => 'g',
        ],
    ];
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $reusedPayload,
            $reusedBuilt['manifest']
        ),
        'Model validation must reject reused quantity/ingredient evidence'
    );
    $partialNumberBuilt = recipeQuantityBuildModelPrompt(
        'spinach: 20 g',
        'en-US',
        'manual'
    );
    $partialNumber = $validPayload;
    $partialNumber['input_hash'] =
        $partialNumberBuilt['manifest']['input_hash'];
    $partialNumber['result']['evidence_spans'][1] = [
        'field' => 'quantity',
        'start' => 9,
        'end' => 10,
        'text' => '2',
    ];
    $partialNumber['result']['evidence_spans'][2] = [
        'field' => 'unit',
        'start' => 12,
        'end' => 13,
        'text' => 'g',
    ];
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $partialNumber,
            $partialNumberBuilt['manifest']
        ),
        'Model validation must reject a numeric span cut from a larger number'
    );
    $partialUnitBuilt = recipeQuantityBuildModelPrompt(
        'spinach: 2 kg',
        'en-US',
        'manual'
    );
    $partialUnit = $validPayload;
    $partialUnit['input_hash'] = $partialUnitBuilt['manifest']['input_hash'];
    $partialUnit['result']['evidence_spans'][2] = [
        'field' => 'unit',
        'start' => 12,
        'end' => 13,
        'text' => 'g',
    ];
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $partialUnit,
            $partialUnitBuilt['manifest']
        ),
        'Model validation must reject a unit span cut from a larger unit'
    );
    $invalidIngredient = $validPayload;
    $invalidIngredient['result']['ingredient'] = 'spinach leaves';
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $invalidIngredient,
            $built['manifest']
        ),
        'Model validation must reject invented ingredient identity'
    );
    $partialIngredient = $validPayload;
    $partialIngredient['result']['ingredient'] = 'pinach';
    $partialIngredient['result']['evidence_spans'][0] = [
        'field' => 'ingredient',
        'start' => 1,
        'end' => 7,
        'text' => 'pinach',
    ];
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $partialIngredient,
            $built['manifest']
        ),
        'Model validation must reject ingredient spans that split a token'
    );
    $missingEvidence = $validPayload;
    array_pop($missingEvidence['result']['evidence_spans']);
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $missingEvidence,
            $built['manifest']
        ),
        'Model validation must reject missing evidence'
    );
    $numericString = $validPayload;
    $numericString['result']['quantity'] = '2';
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $numericString,
            $built['manifest']
        ),
        'Model validation must reject numeric strings'
    );
    $unknownKey = $validPayload;
    $unknownKey['result']['confidence'] = 1;
    quantityTestThrows(
        static fn(): array => recipeQuantityValidateModelProposal(
            $unknownKey,
            $built['manifest']
        ),
        'Model validation must reject open-schema keys'
    );

    $staged = recipeQuantityStageModelProposal(
        $db,
        $validPayload,
        $built['manifest']
    );
    quantityTestAssert(
        $staged['review_status'] === 'pending'
            && $staged['source_connector'] === 'manual'
            && (float)$staged['proposed_result']['quantity'] === 2.0,
        'Validated model output must remain a pending staging record'
    );
    $reviewed = recipeQuantityReviewModelProposal(
        $db,
        $staged['id'],
        'approved',
        'quantity-test',
        'Evidence reviewed for staging only'
    );
    quantityTestAssert(
        $reviewed['review_status'] === 'approved'
            && (int)$db->query("
                SELECT COUNT(*) FROM recipe_ingredients
                WHERE quantity = 2 AND unit = 'g'
            ")->fetchColumn() === 0,
        'Explicit review must not activate proposals or alter ranking data'
    );
    quantityTestThrows(
        static fn(): array => recipeQuantityReviewModelProposal(
            $db,
            $staged['id'],
            'approved',
            'quantity-test',
            'Second review must fail'
        ),
        'Reviewed quantity proposals must be immutable'
    );

    $invalidSourceRejected = false;
    try {
        $db->exec("
            INSERT INTO recipe_quantity_parse_proposals (
                input_hash, source_connector, source_locale, source_text,
                parser_version, prompt_version, prompt_hash, model_name,
                result_hash, proposed_result_json, raw_response_json
            )
            VALUES (
                '" . str_repeat('a', 64) . "', 'cookidoo', 'en-US', 'x',
                'p', 'q', '" . str_repeat('b', 64) . "', 'm',
                '" . str_repeat('c', 64) . "', '{}', '{}'
            )
        ");
    } catch (PDOException $error) {
        $invalidSourceRejected = true;
    }
    quantityTestAssert(
        $invalidSourceRejected,
        'Proposal persistence must reject Cookidoo source text'
    );

    echo 'Recipe quantity parser tests passed: '
        . number_format($quantityTestAssertions)
        . " assertions\n";
} finally {
    $db = null;
    foreach ($cleanupPaths as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}
