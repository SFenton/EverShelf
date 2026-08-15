<?php

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/index.php';

$scanAiAssertions = 0;
function assertSameValue($expected, $actual, string $message): void {
    global $scanAiAssertions;
    $scanAiAssertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assertScanTrue(bool $condition, string $message): void {
    global $scanAiAssertions;
    $scanAiAssertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function callLocationSuggestion(PDO $db, array $input): array {
    $GLOBALS['LOCATION_SUGGESTION_INPUT'] = $input;
    ob_start();
    suggestLocation($db);
    $result = json_decode((string)ob_get_clean(), true);
    if (!is_array($result)) {
        throw new RuntimeException('Location suggestion returned invalid JSON');
    }
    return $result;
}

function scanTestImageBase64(): string {
    $image = imagecreatetruecolor(160, 80);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefilledrectangle($image, 0, 0, 159, 79, $white);
    imagestring($image, 5, 8, 28, 'EXP 15/09/2027', $black);
    ob_start();
    imagepng($image);
    $png = (string)ob_get_clean();
    imagedestroy($image);
    return base64_encode($png);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("
    CREATE TABLE products (
        id INTEGER PRIMARY KEY,
        barcode TEXT,
        name TEXT NOT NULL,
        last_location TEXT,
        last_location_at DATETIME,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        quantity REAL NOT NULL,
        location TEXT NOT NULL,
        notes TEXT DEFAULT '',
        undone INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE inventory (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        location TEXT NOT NULL,
        quantity REAL NOT NULL,
        added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE location_suggestion_cache (
        cache_key TEXT PRIMARY KEY,
        location TEXT NOT NULL,
        confidence REAL NOT NULL DEFAULT 0,
        reason TEXT DEFAULT '',
        model TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE product_location_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        location TEXT NOT NULL,
        source TEXT NOT NULL,
        transaction_id INTEGER UNIQUE,
        occurred_at DATETIME NOT NULL,
        undone INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE barcode_cache (
        barcode TEXT PRIMARY KEY,
        found INTEGER NOT NULL DEFAULT 0,
        source TEXT,
        payload TEXT,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

$insertProduct = $db->prepare("
    INSERT INTO products (id, barcode, name, last_location, last_location_at)
    VALUES (?, ?, ?, ?, ?)
");
$insertProduct->execute([1, '111', 'Milk', 'frigo', '2026-01-01 10:00:00']);
$insertProduct->execute([2, '222', 'MILK', 'freezer', '2026-02-01 10:00:00']);
$insertProduct->execute([3, '333', 'Café Beans', 'dispensa', '2026-03-01 10:00:00']);
$insertProduct->execute([4, '444', 'Soup', 'cabinet', '2025-01-01 10:00:00']);
$insertProduct->execute([5, '555', 'Bread', null, null]);
$insertProduct->execute([6, '666', 'Preserved', 'spice_rack', '2024-01-01 10:00:00']);
$insertProduct->execute([7, '777', 'Moved item', null, null]);

assertSameValue('freezer', manualNameLocationHistory($db, ' milk ')['location'], 'Latest exact name wins');
assertSameValue(2, manualNameLocationHistory($db, 'milk')['product_id'], 'Name tie-break uses latest product');
assertSameValue('dispensa', manualNameLocationHistory($db, 'CAFÉ BEANS')['location'], 'Unicode case folding works');
assertSameValue(null, manualNameLocationHistory($db, 'Milks'), 'Name lookup remains exact');

$db->exec("INSERT INTO transactions (product_id, type, quantity, location, undone, created_at) VALUES (4, 'in', 1, 'dispensa', 0, '2026-01-01 10:00:00')");
$firstTransactionId = (int)$db->lastInsertId();
recordProductLocation($db, 4, 'dispensa', 'inventory_add', '2026-01-01 10:00:00', $firstTransactionId);
$db->exec("INSERT INTO transactions (product_id, type, quantity, location, undone, created_at) VALUES (4, 'in', 1, 'frigo', 1, '2026-02-01 10:00:00')");
$undoneTransactionId = (int)$db->lastInsertId();
recordProductLocation($db, 4, 'frigo', 'inventory_add', '2026-02-01 10:00:00', $undoneTransactionId);
undoProductLocationTransaction($db, $undoneTransactionId);
assertSameValue('dispensa', refreshProductLastLocation($db, 4)['location'], 'Undone inbound is ignored');

$db->exec("
    INSERT INTO inventory (product_id, location, quantity, added_at)
    VALUES (5, 'frigo', 1, '2026-04-01 10:00:00')
");
assertSameValue('frigo', refreshProductLastLocation($db, 5)['location'], 'Inventory backfills when ledger is empty');
assertSameValue('spice_rack', refreshProductLastLocation($db, 6)['location'], 'Missing evidence preserves durable history');
recordProductLocation($db, 7, 'dispensa', 'inventory_add', '2026-01-01 10:00:00', 7001);
recordProductLocation($db, 7, 'frigo', 'inventory_move', '2026-03-01 10:00:00');
assertSameValue('frigo', refreshProductLastLocation($db, 7)['location'], 'A later move stays ahead of an older add');
recordProductLocation($db, 7, 'freezer', 'inventory_add', '2026-04-01 10:00:00', 7002);
undoProductLocationTransaction($db, 7002);
assertSameValue('frigo', refreshProductLastLocation($db, 7)['location'], 'Undo restores the prior location event');

$parsed = parseLocationSuggestionModelText('{"location":"frigo","confidence":0.9,"reason":"Fresh dairy"}');
assertSameValue('frigo', $parsed['location'], 'Valid model location is accepted');
$lowConfidence = parseLocationSuggestionModelText('{"location":"frigo","confidence":0.2,"reason":""}');
assertSameValue('unknown', $lowConfidence['location'], 'Low-confidence answer becomes unknown');
assertSameValue(null, parseLocationSuggestionModelText('{"location":"altro","confidence":1,"reason":""}'), 'AI cannot choose altro');
assertSameValue(null, parseLocationSuggestionModelText('{"location":"frigo","reason":""}'), 'Missing confidence triggers fallback');
assertSameValue(
    null,
    parseLocationSuggestionModelText('{"location":"frigo","confidence":1}'),
    'Missing strict reason triggers fallback'
);

$cacheKey = locationSuggestionCacheKey('Milk', 'Dairy', ['gemini-3.7-flash']);
cacheLocationSuggestion($db, $cacheKey, [
    'location' => 'frigo',
    'confidence' => 0.95,
    'reason' => 'Fresh dairy',
    'model' => 'gemini-3.7-flash',
]);
$cached = cachedLocationSuggestion($db, $cacheKey);
assertSameValue('frigo', $cached['location'], 'Cached location round-trips');
assertSameValue('copilot_cache', $cached['source'], 'Cache source is explicit');
assertSameValue(
    ['gemini-3.7-flash'],
    locationSuggestionModels(),
    'Location suggestions use one authorized Copilot Gemini model'
);
assertSameValue(1, LOCATION_SUGGESTION_MAX_ATTEMPTS, 'Interactive location AI never retries the same model');
assertSameValue(10, LOCATION_SUGGESTION_TIMEOUT_SECONDS, 'Interactive location AI timeout stays bounded');

$locationTransportCalls = 0;
$GLOBALS['LOCATION_AI_ENABLED'] = true;
$GLOBALS['LOCATION_SUGGESTION_TRANSPORT'] =
    static function () use (&$locationTransportCalls): array {
        $locationTransportCalls++;
        throw new RuntimeException('history_must_precede_transport');
    };
$historyResult = callLocationSuggestion($db, [
    'mode' => 'manual',
    'name' => 'milk',
    'category' => 'Dairy',
]);
assertScanTrue(
    $historyResult['location'] === 'freezer'
    && $historyResult['source'] === 'history_name'
    && $locationTransportCalls === 0,
    'Location history must precede cache and Copilot transport'
);

$cachedScanKey = locationSuggestionCacheKey(
    'Cached Scan',
    'Shelf stable',
    locationSuggestionModels()
);
cacheLocationSuggestion($db, $cachedScanKey, [
    'location' => 'dispensa',
    'confidence' => 0.91,
    'reason' => 'Cached answer',
    'model' => LOCATION_SUGGESTION_MODEL,
]);
$GLOBALS['LOCATION_AI_ENABLED'] = false;
$cachedScanResult = callLocationSuggestion($db, [
    'mode' => 'manual',
    'name' => 'Cached Scan',
    'category' => 'Shelf stable',
]);
assertScanTrue(
    $cachedScanResult['location'] === 'dispensa'
    && $cachedScanResult['source'] === 'copilot_cache'
    && $locationTransportCalls === 0,
    'DB location cache must precede the AI-enabled gate'
);

$GLOBALS['LOCATION_AI_ENABLED'] = true;
$capturedLocationRequest = null;
$capturedLocationRemaining = null;
$GLOBALS['LOCATION_SUGGESTION_TRANSPORT'] =
    static function (
        array $request,
        float $deadline
    ) use (
        &$locationTransportCalls,
        &$capturedLocationRequest,
        &$capturedLocationRemaining
    ): array {
        $locationTransportCalls++;
        $capturedLocationRequest = $request;
        $capturedLocationRemaining = $deadline - microtime(true);
        return [
            'source' => 'copilot_socket',
            'envelope' => [
                'location' => 'frigo',
                'confidence' => 0.93,
                'reason' => 'Fresh refrigerated product',
            ],
        ];
    };
$copilotLocation = callLocationSuggestion($db, [
    'mode' => 'manual',
    'name' => 'Fresh Scan Product',
    'category' => 'Fresh foods',
]);
$cachedCopilotLocation = callLocationSuggestion($db, [
    'mode' => 'manual',
    'name' => 'Fresh Scan Product',
    'category' => 'Fresh foods',
]);
assertScanTrue(
    $copilotLocation['location'] === 'frigo'
    && $copilotLocation['source'] === 'copilot_socket'
    && $cachedCopilotLocation['source'] === 'copilot_cache'
    && $locationTransportCalls === 1
    && ($capturedLocationRequest['protocol_version'] ?? '')
        === 'evershelf-ontology-copilot-v1'
    && ($capturedLocationRequest['model'] ?? '')
        === 'gemini-3.7-flash'
    && ($capturedLocationRequest['role'] ?? '') === 'proposer'
    && ($capturedLocationRequest['schema']['additionalProperties'] ?? null)
        === false
    && ($capturedLocationRequest['schema']['required'] ?? [])
        === ['location', 'confidence', 'reason']
    && is_float($capturedLocationRemaining)
    && $capturedLocationRemaining > 0
    && $capturedLocationRemaining <= 10.0,
    'Location Copilot request must be strict, bounded, cached, and use authorized Gemini 3.7'
);

$GLOBALS['LOCATION_SUGGESTION_TRANSPORT'] =
    static function (): array {
        throw new RuntimeException('controller_copilot_socket_timeout');
    };
$locationUnavailable = callLocationSuggestion($db, [
    'mode' => 'manual',
    'name' => 'Timeout Scan Product',
    'category' => 'Unknown',
]);
assertScanTrue(
    $locationUnavailable['success'] === true
    && $locationUnavailable['location'] === 'unknown'
    && $locationUnavailable['available'] === false
    && $locationUnavailable['nonfatal'] === true
    && str_contains(
        (string)$locationUnavailable['reason'],
        'socket_timeout'
    ),
    'Location timeout must return a structured nonfatal unknown result'
);

$indexSource = file_get_contents(__DIR__ . '/../api/index.php');
$locationHelperSource = file_get_contents(
    __DIR__ . '/../api/lib/location_suggestions.php'
);
$locationStart = strpos((string)$indexSource, 'function suggestLocation(');
$locationEnd = strpos(
    (string)$indexSource,
    'function stockForName(',
    (int)$locationStart
);
$locationSource = (
    is_int($locationStart)
    && is_int($locationEnd)
    && $locationEnd > $locationStart
)
    ? substr(
        (string)$indexSource,
        $locationStart,
        $locationEnd - $locationStart
    )
    : '';
assertScanTrue(
    $locationSource !== ''
    && !str_contains($locationSource, 'GEMINI_API_KEY')
    && !str_contains($locationSource, 'callGeminiWithFallback')
    && is_string($locationHelperSource)
    && !str_contains($locationHelperSource, 'GEMINI_API_KEY')
    && !str_contains(
        $locationHelperSource,
        'callGeminiWithFallback'
    )
    && str_contains($locationSource, 'locationSuggestionCopilotRequest'),
    'Location suggestion action must not use the direct Gemini key API'
);

$imageBase64 = scanTestImageBase64();
$processOrder = [];
$copilotExpiryCalls = 0;
$capturedProcessRemaining = null;
$GLOBALS['EXPIRY_TESSERACT_BINARY'] = '/mock/tesseract';
$GLOBALS['EXPIRY_OCR_PROCESS_TRANSPORT'] =
    static function (
        array $command,
        float $deadline
    ) use (
        &$processOrder,
        &$capturedProcessRemaining
    ): array {
        $processOrder[] = 'process';
        $capturedProcessRemaining = $deadline - microtime(true);
        assertScanTrue(
            $command[0] === '/mock/tesseract'
            && $command[2] === 'stdout'
            && str_starts_with(
                (string)$command[1],
                dirname(__DIR__) . '/data/'
            ),
            'Tesseract must use an argv process without a shell'
        );
        return [
            'exit_code' => 0,
            'stdout' => 'EXP 15/09/2027',
            'stderr' => '',
            'timed_out' => false,
        ];
    };
$GLOBALS['EXPIRY_COPILOT_TRANSPORT'] =
    static function () use (&$copilotExpiryCalls): array {
        $copilotExpiryCalls++;
        throw new RuntimeException('copilot_must_not_run');
    };
$localExpiry = readExpiryFromImage($imageBase64);
assertScanTrue(
    $localExpiry['found'] === true
    && $localExpiry['expiry_date'] === '2027-09-15'
    && $localExpiry['source'] === 'tesseract'
    && $processOrder === ['process']
    && $copilotExpiryCalls === 0
    && is_float($capturedProcessRemaining)
    && $capturedProcessRemaining > 0
    && $capturedProcessRemaining <= 4.0,
    'Local bounded Tesseract must run first and return a valid deterministic date'
);

$processOrder = [];
$capturedExpiryRequest = null;
$capturedExpiryRemaining = null;
$longOcrText = 'BEST BEFORE code unclear near cap '
    . str_repeat('X', 2000);
$GLOBALS['EXPIRY_OCR_PROCESS_TRANSPORT'] =
    static function () use (&$processOrder, $longOcrText): array {
        $processOrder[] = 'process';
        return [
            'exit_code' => 0,
            'stdout' => $longOcrText,
            'stderr' => '',
            'timed_out' => false,
        ];
    };
$GLOBALS['EXPIRY_COPILOT_TRANSPORT'] =
    static function (
        array $request,
        float $deadline
    ) use (
        &$processOrder,
        &$copilotExpiryCalls,
        &$capturedExpiryRequest,
        &$capturedExpiryRemaining
    ): array {
        $processOrder[] = 'copilot';
        $copilotExpiryCalls++;
        $capturedExpiryRequest = $request;
        $capturedExpiryRemaining = $deadline - microtime(true);
        return [
            'source' => 'copilot_socket',
            'envelope' => [
                'found' => true,
                'date' => '2027-10-01',
            ],
        ];
    };
$copilotExpiry = readExpiryFromImage($imageBase64);
assertScanTrue(
    $copilotExpiry['found'] === true
    && $copilotExpiry['expiry_date'] === '2027-10-01'
    && $copilotExpiry['source'] === 'copilot_ocr_text'
    && $processOrder === ['process', 'copilot']
    && ($capturedExpiryRequest['model'] ?? '')
        === 'gemini-3.7-flash'
    && ($capturedExpiryRequest['schema']['additionalProperties'] ?? null)
        === false
    && ($capturedExpiryRequest['schema']['required'] ?? [])
        === ['found', 'date']
    && str_contains(
        (string)$capturedExpiryRequest['prompt'],
        'BEST BEFORE code unclear near cap'
    )
    && mb_strlen(
        (string)$copilotExpiry['raw_text'],
        'UTF-8'
    ) === EXPIRY_OCR_MAX_TEXT_CHARS
    && !str_contains(
        (string)$capturedExpiryRequest['prompt'],
        $imageBase64
    )
    && is_float($capturedExpiryRemaining)
    && $capturedExpiryRemaining > 0
    && $capturedExpiryRemaining <= 10.0
    && (int)$copilotExpiry['elapsed_ms'] <= 14000,
    'Inconclusive OCR must send only bounded text to strict Copilot Gemini under the total deadline'
);

$processOrder = [];
$GLOBALS['EXPIRY_OCR_PROCESS_TRANSPORT'] =
    static function () use (&$processOrder): array {
        $processOrder[] = 'process';
        return [
            'exit_code' => -1,
            'stdout' => '',
            'stderr' => '',
            'timed_out' => true,
        ];
    };
$GLOBALS['EXPIRY_COPILOT_TRANSPORT'] =
    static function () use (&$processOrder): array {
        $processOrder[] = 'copilot';
        throw new RuntimeException('copilot_must_not_run_without_text');
    };
$localTimeout = readExpiryFromImage($imageBase64);
assertScanTrue(
    $localTimeout['found'] === false
    && $localTimeout['nonfatal'] === true
    && $localTimeout['can_continue'] === true
    && $localTimeout['reason'] === 'tesseract_timeout'
    && $processOrder === ['process'],
    'Tesseract timeout must remain nonfatal and must not send an image to Copilot'
);

$processOrder = [];
$GLOBALS['EXPIRY_OCR_PROCESS_TRANSPORT'] =
    static function () use (&$processOrder): array {
        $processOrder[] = 'process';
        return [
            'exit_code' => 0,
            'stdout' => 'EXPIRY TEXT WITHOUT A PARSEABLE DATE',
            'stderr' => '',
            'timed_out' => false,
        ];
    };
$GLOBALS['EXPIRY_COPILOT_TRANSPORT'] =
    static function () use (&$processOrder): array {
        $processOrder[] = 'copilot';
        throw new RuntimeException('controller_copilot_socket_timeout');
    };
$copilotTimeout = readExpiryFromImage($imageBase64);
assertScanTrue(
    $copilotTimeout['found'] === false
    && $copilotTimeout['nonfatal'] === true
    && $copilotTimeout['can_continue'] === true
    && str_contains((string)$copilotTimeout['reason'], 'socket_timeout')
    && $processOrder === ['process', 'copilot']
    && (int)$copilotTimeout['elapsed_ms'] <= 14000,
    'Copilot timeout after OCR text must return a structured nonfatal result'
);

$leapDate = parseExpiryOcrText(
    'EXP 29/02/2028',
    new DateTimeImmutable('2026-01-01')
);
$invalidDate = parseExpiryOcrText(
    'EXP 31/02/2027',
    new DateTimeImmutable('2026-01-01')
);
$usDate = parseExpiryOcrText(
    'BEST BY 08/26/2027',
    new DateTimeImmutable('2026-01-01')
);
assertScanTrue(
    $leapDate['found'] === true
    && $leapDate['date'] === '2028-02-29'
    && $usDate['found'] === true
    && $usDate['date'] === '2027-08-26'
    && $invalidDate['found'] === false
    && parseExpiryCopilotEnvelope([
        'found' => true,
        'date' => '2027-02-31',
    ]) === null,
    'Expiry parsing must support US retail dates, valid leap dates, and reject invalid dates'
);

$expiryStart = strpos(
    (string)$indexSource,
    'function evershelfRunBoundedProcess('
);
$expiryEnd = strpos(
    (string)$indexSource,
    '// ===== GEMINI CHAT =====',
    (int)$expiryStart
);
$expirySource = (
    is_int($expiryStart)
    && is_int($expiryEnd)
    && $expiryEnd > $expiryStart
)
    ? substr(
        (string)$indexSource,
        $expiryStart,
        $expiryEnd - $expiryStart
    )
    : '';
$appSource = file_get_contents(__DIR__ . '/../assets/js/app.js');
$expiryUiStart = strpos(
    (string)$appSource,
    'async function scanExpiryWithAI()'
);
$expiryUiEnd = strpos(
    (string)$appSource,
    'function stripHtml(',
    (int)$expiryUiStart
);
$expiryUiSource = (
    is_int($expiryUiStart)
    && is_int($expiryUiEnd)
    && $expiryUiEnd > $expiryUiStart
)
    ? substr(
        (string)$appSource,
        $expiryUiStart,
        $expiryUiEnd - $expiryUiStart
    )
    : '';
$authPosition = strpos(
    (string)$indexSource,
    'evershelfRequireApiAuth($action, $method);'
);
$earlyExpiryPosition = strpos(
    (string)$indexSource,
    "if (\$action === 'gemini_expiry')"
);
$databaseOpenPosition = strpos(
    (string)$indexSource,
    '$db = getDB();',
    (int)$authPosition
);
assertScanTrue(
    $expirySource !== ''
    && !str_contains($expirySource, 'GEMINI_API_KEY')
    && !str_contains($expirySource, 'callGeminiWithFallback')
    && !str_contains($expirySource, 'inline_data')
    && !str_contains($expirySource, 'shell_exec')
    && !str_contains($expirySource, 'sys_get_temp_dir')
    && EXPIRY_OCR_PROCESS_TIMEOUT_SECONDS <= 4.0
    && EXPIRY_COPILOT_TIMEOUT_SECONDS <= 10.0
    && EXPIRY_INTERACTIVE_TIMEOUT_SECONDS <= 14.0
    && $expiryUiSource !== ''
    && !str_contains($expiryUiSource, '_requireGemini')
    && !str_contains($expiryUiSource, "result.error === 'no_api_key'")
    && is_int($authPosition)
    && is_int($earlyExpiryPosition)
    && is_int($databaseOpenPosition)
    && $authPosition < $earlyExpiryPosition
    && $earlyExpiryPosition < $databaseOpenPosition
    && glob(
        dirname(__DIR__) . '/data/.expiry-ocr-*.lock'
    ) === [],
    'Expiry scan path must authenticate before a DB-independent, local-first, bounded text-only handler'
);

$barcodeAiCalls = 0;
$GLOBALS['BARCODE_HTTP_TRANSPORT'] =
    static function (array $requests): array {
        return array_fill_keys(array_keys($requests), null);
    };
$GLOBALS['BARCODE_AI_API_KEY'] = 'test-key';
$GLOBALS['BARCODE_AI_TRANSPORT'] =
    static function (
        string $barcode,
        string $apiKey
    ) use (&$barcodeAiCalls): ?array {
        $barcodeAiCalls++;
        assertSameValue('test-key', $apiKey, 'Barcode test key is isolated');
        return [
            'name' => 'Gated Legacy Result ' . $barcode,
            'brand' => 'Test',
            'category' => 'Test',
        ];
    };
$GLOBALS['BARCODE_AI_FALLBACK'] = false;
$barcodeMiss = barcodeResolveExternal($db, '9780201379624');
assertScanTrue(
    $barcodeMiss === null && $barcodeAiCalls === 0,
    'Default-false barcode AI gate must prevent legacy Gemini lookup on external misses'
);
$GLOBALS['BARCODE_AI_FALLBACK'] = true;
$barcodeGated = barcodeResolveExternal($db, '9780201379631');
assertScanTrue(
    $barcodeGated !== null
    && $barcodeGated['source'] === 'gemini'
    && $barcodeAiCalls === 1,
    'Explicit barcode AI opt-in must remain the only path to the legacy lookup'
);

$barcodeStart = strpos(
    (string)$indexSource,
    'function barcodeAiFallbackEnabled('
);
$barcodeEnd = strpos(
    (string)$indexSource,
    'function resolveBarcode(',
    (int)$barcodeStart
);
$barcodeSource = (
    is_int($barcodeStart)
    && is_int($barcodeEnd)
    && $barcodeEnd > $barcodeStart
)
    ? substr(
        (string)$indexSource,
        $barcodeStart,
        $barcodeEnd - $barcodeStart
    )
    : '';
assertScanTrue(
    $barcodeSource !== ''
    && str_contains(
        $barcodeSource,
        "env('BARCODE_AI_FALLBACK', 'false')"
    )
    && strpos($barcodeSource, 'barcodeAiFallbackEnabled()')
        < strpos($barcodeSource, 'barcodeAiFallbackApiKey()')
    && !str_contains($barcodeSource, 'evershelfCopilot')
    && !str_contains($barcodeSource, 'locationSuggestionCopilot'),
    'Barcode resolution must check the explicit gate before reading the legacy API key and must not add Copilot hallucination fallback'
);

unset(
    $GLOBALS['LOCATION_AI_ENABLED'],
    $GLOBALS['LOCATION_SUGGESTION_INPUT'],
    $GLOBALS['LOCATION_SUGGESTION_TRANSPORT'],
    $GLOBALS['EXPIRY_TESSERACT_BINARY'],
    $GLOBALS['EXPIRY_OCR_PROCESS_TRANSPORT'],
    $GLOBALS['EXPIRY_COPILOT_TRANSPORT'],
    $GLOBALS['BARCODE_HTTP_TRANSPORT'],
    $GLOBALS['BARCODE_AI_API_KEY'],
    $GLOBALS['BARCODE_AI_TRANSPORT'],
    $GLOBALS['BARCODE_AI_FALLBACK']
);

echo "Scan AI tests passed: {$scanAiAssertions} assertions.\n";
