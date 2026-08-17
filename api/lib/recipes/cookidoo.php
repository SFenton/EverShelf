<?php

const RECIPE_COOKIDOO_STORAGE_POLICY = 'metadata_only';
const RECIPE_COOKIDOO_RIGHTS_BASIS = 'cookidoo_metadata_operator_approved';
const RECIPE_COOKIDOO_CONNECTOR = 'cookidoo';
const RECIPE_COOKIDOO_METADATA_VERSION = 'metadata-v2';
const RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION = 'ingredient-topology-v1';
const RECIPE_COOKIDOO_METADATA_FAILURE_SCHEMA_VERSION = 'metadata-parser-v10-complete-list';
const RECIPE_COOKIDOO_DETAIL_POLICY_VERSION = 'metadata-v2-detail-disabled';
const RECIPE_COOKIDOO_DETAIL_POLICY_REASON = 'provider_detail_policy_disabled';
const RECIPE_COOKIDOO_MAX_RESPONSE_BYTES = 1048576;
const RECIPE_COOKIDOO_MAX_RECIPE_SECONDS =
    RECIPE_MAX_FACTUAL_DURATION_SECONDS;
const RECIPE_COOKIDOO_CRAWL_REFRESH_FIELD = '_crawl_refresh';
const RECIPE_COOKIDOO_POLICY_FIELD = '_detail_policy_version';
const RECIPE_COOKIDOO_NOT_FOUND_TTL_SECONDS = 2592000;
const RECIPE_COOKIDOO_INVALID_METADATA_BASE_TTL_SECONDS = 86400;
const RECIPE_COOKIDOO_INVALID_METADATA_MAX_TTL_SECONDS = 2592000;
const RECIPE_COOKIDOO_METADATA_FAILURE_KINDS = [
    'invalid_id',
    'invalid_metadata',
    'locale_mismatch',
    'not_found',
    'content_language_rejected',
];

class RecipeCookidooCircuitBreakException extends RuntimeException {
}

function recipeCookidooOfficialHosts(): array {
    return [
        'cookidoo.at',
        'cookidoo.be',
        'cookidoo.ca',
        'cookidoo.ch',
        'cookidoo.co.uk',
        'cookidoo.com.au',
        'cookidoo.com.cn',
        'cookidoo.com.tr',
        'cookidoo.cz',
        'cookidoo.de',
        'cookidoo.es',
        'cookidoo.fr',
        'cookidoo.international',
        'cookidoo.it',
        'cookidoo.mx',
        'cookidoo.pl',
        'cookidoo.pt',
        'cookidoo.thermomix.com',
    ];
}

function recipeCookidooConfigValue(string $key, string $default = ''): string {
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && isset($GLOBALS['RECIPE_COOKIDOO_CONFIG'])
        && is_array($GLOBALS['RECIPE_COOKIDOO_CONFIG'])
        && array_key_exists($key, $GLOBALS['RECIPE_COOKIDOO_CONFIG'])
    ) {
        return (string)$GLOBALS['RECIPE_COOKIDOO_CONFIG'][$key];
    }
    return env($key, $default);
}

function recipeCookidooEnvBool(string $key, bool $default = false): bool {
    $raw = strtolower(trim(recipeCookidooConfigValue(
        $key,
        $default ? 'true' : 'false'
    )));
    return match ($raw) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off', '' => false,
        default => $default,
    };
}

function recipeCookidooResultLimit(): int {
    return max(1, min(20, (int)recipeCookidooConfigValue('COOKIDOO_RESULT_LIMIT', '20')));
}

function recipeCookidooBridgeTimeoutSeconds(): int {
    return max(3, min(120, (int)recipeCookidooConfigValue(
        'COOKIDOO_BRIDGE_TIMEOUT_SECONDS',
        '50'
    )));
}

function recipeCookidooMetadataRefreshDays(): int {
    return max(1, min(365, (int)recipeCookidooConfigValue(
        'COOKIDOO_METADATA_REFRESH_DAYS',
        '14'
    )));
}

function recipeCookidooDetailHydrationPolicyAllows(): bool {
    return defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && !empty($GLOBALS['RECIPE_COOKIDOO_POLICY_TEST_OVERRIDE']);
}

function recipeCookidooMetadataBackfillConfigured(): bool {
    return recipeCookidooEnvBool('COOKIDOO_METADATA_BACKFILL_ENABLED', false);
}

function recipeCookidooMetadataBackfillEnabled(): bool {
    return recipeCookidooMetadataBackfillConfigured()
        && recipeCookidooDetailHydrationPolicyAllows();
}

function recipeCookidooMetadataBackfillBatchSize(): int {
    return max(1, min(20, (int)recipeCookidooConfigValue(
        'COOKIDOO_METADATA_BACKFILL_BATCH_SIZE',
        '20'
    )));
}

function recipeCookidooMetadataBackfillIntervalSeconds(): int {
    return max(60, min(900, (int)recipeCookidooConfigValue(
        'COOKIDOO_METADATA_BACKFILL_INTERVAL_SECONDS',
        '120'
    )));
}

function recipeCookidooMetadataBackfillJitterSeconds(): int {
    return max(0, min(120, (int)recipeCookidooConfigValue(
        'COOKIDOO_METADATA_BACKFILL_JITTER_SECONDS',
        '20'
    )));
}

function recipeCookidooMetadataPilotControls(): array {
    $enabled = recipeCookidooDetailHydrationPolicyAllows();
    $controls = [
        'detail_hydration' => $enabled,
        'reason' => $enabled
            ? null
            : RECIPE_COOKIDOO_DETAIL_POLICY_REASON,
        'policy_version' => RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
    ];
    if (!$enabled) {
        return $controls;
    }
    return $controls + [
        'ladder' => [1, 5, 10, 20, 200],
        'max_recipes' => 200,
        'max_ids_per_job' => 20,
        'detail_concurrency' => 1,
        'interval_seconds' =>
            recipeCookidooMetadataBackfillIntervalSeconds(),
        'jitter_seconds' =>
            recipeCookidooMetadataBackfillJitterSeconds(),
        'nightly_window_required' => true,
        'circuit_break_statuses' => [403, 429, 'challenge'],
    ];
}

function recipeCookidooMetadataBackfillScheduleAt(
    string $idempotencyKey,
    int $batchIndex,
    ?int $baseTimestamp = null
): ?string {
    if ($batchIndex <= 0) {
        return null;
    }
    $jitter = recipeCookidooMetadataBackfillJitterSeconds();
    $signedJitter = 0;
    if ($jitter > 0) {
        $seed = (int)hexdec(substr(hash('sha256', $idempotencyKey), 0, 8));
        $signedJitter = ($seed % (($jitter * 2) + 1)) - $jitter;
    }
    $delay = max(
        60,
        ($batchIndex * recipeCookidooMetadataBackfillIntervalSeconds())
            + $signedJitter
    );
    return gmdate('Y-m-d H:i:s', ($baseTimestamp ?? time()) + $delay);
}

function recipeCookidooQueueCadenceMinutes(): int {
    return max(1, min(1440, (int)recipeCookidooConfigValue(
        'COOKIDOO_QUEUE_CADENCE_MINUTES',
        '5'
    )));
}

function recipeCookidooPeriodicRefreshLimit(): int {
    return max(0, min(20, (int)recipeCookidooConfigValue(
        'COOKIDOO_REFRESH_ENQUEUE_LIMIT',
        '2'
    )));
}

function recipeCookidooDiscoveryLocale(): string {
    return recipeCookidooNormalizeLocale(recipeCookidooConfigValue(
        'COOKIDOO_DISCOVERY_LOCALE',
        'en-US'
    ));
}

function recipeCookidooBridgeUrl(): string {
    return rtrim(trim(recipeCookidooConfigValue(
        'COOKIDOO_BRIDGE_URL',
        'http://cookidoo-bridge:8081'
    )), '/');
}

function recipeCookidooBridgeConfigured(): bool {
    return recipeCookidooBridgeUrl() !== ''
        && trim(recipeCookidooConfigValue('COOKIDOO_BRIDGE_TOKEN', '')) !== '';
}

function recipeCookidooCleanText(
    mixed $value,
    string $field,
    int $maxLength,
    bool $required = false
): string {
    if (!is_string($value)) {
        throw new InvalidArgumentException($field . ' must be a string');
    }
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($required && $value === '') {
        throw new InvalidArgumentException($field . ' is required');
    }
    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        throw new InvalidArgumentException($field . ' is too long');
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new InvalidArgumentException($field . ' contains control characters');
    }
    return $value;
}

function recipeCookidooNormalizeLocale(mixed $value): string {
    $locale = str_replace('_', '-', recipeCookidooCleanText($value, 'locale', 16, true));
    if (!preg_match(
        '/^([A-Za-z]{2,3})(?:-([A-Za-z]{4}))?(?:-([A-Za-z]{2}|[0-9]{3}))?$/',
        $locale,
        $match
    )) {
        throw new InvalidArgumentException('locale is invalid');
    }
    $normalized = strtolower($match[1]);
    if (!empty($match[2])) {
        $normalized .= '-' . ucfirst(strtolower($match[2]));
    }
    if (!empty($match[3])) {
        $normalized .= '-' . (ctype_digit($match[3]) ? $match[3] : strtoupper($match[3]));
    }
    return $normalized;
}

function recipeCookidooNormalizeProviderLanguage(
    mixed $value
): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $language = recipeCookidooNormalizeLocale($value);
    if (mb_strlen($language, 'UTF-8') > 20) {
        throw new InvalidArgumentException(
            'provider_language is too long'
        );
    }
    return $language;
}

function recipeCookidooProviderLanguageIsEnglish(
    string $language
): bool {
    return strtolower(explode('-', $language, 2)[0]) === 'en';
}

function recipeCookidooLocaleIsLanguageOnly(mixed $value): bool {
    return !str_contains(recipeCookidooNormalizeLocale($value), '-');
}

function recipeCookidooDiscoveryLocaleMatches(
    string $requestedLocale,
    string $effectiveLocale
): bool {
    $requestedLocale = recipeCookidooNormalizeLocale($requestedLocale);
    $effectiveLocale = recipeCookidooNormalizeLocale($effectiveLocale);
    return strtolower(explode('-', $requestedLocale, 2)[0])
        === strtolower(explode('-', $effectiveLocale, 2)[0]);
}

function recipeCookidooNormalizeNameList(
    mixed $value,
    string $field,
    int $maxItems = 25
): array {
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || !recipeArrayIsList($value)) {
        throw new InvalidArgumentException($field . ' must be an array');
    }
    if (count($value) > $maxItems) {
        throw new InvalidArgumentException($field . ' has too many entries');
    }
    $names = [];
    foreach ($value as $item) {
        $name = recipeCookidooCleanText($item, $field, 200, true);
        $key = recipeIngredientNormalizeName($name);
        if ($key === '') {
            throw new InvalidArgumentException($field . ' contains an invalid name');
        }
        $names[$key] ??= $name;
    }
    ksort($names, SORT_STRING);
    return array_values($names);
}

function recipeCookidooNormalizeOptionalNumber(mixed $value, string $field): ?float {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_bool($value) || (!is_int($value) && !is_float($value))) {
        throw new InvalidArgumentException($field . ' must be numeric');
    }
    $number = (float)$value;
    if (!is_finite($number) || $number < 0 || $number > 1000000000) {
        throw new InvalidArgumentException($field . ' is out of range');
    }
    return $number;
}

function recipeCookidooNormalizeOptionalSeconds(mixed $value, string $field): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_bool($value) || !is_int($value)) {
        throw new InvalidArgumentException($field . ' must be an integer');
    }
    if ($value < 0 || $value > RECIPE_COOKIDOO_MAX_RECIPE_SECONDS) {
        throw new InvalidArgumentException($field . ' is out of range');
    }
    return $value;
}

function recipeCookidooNormalizeFactNameList(
    mixed $value,
    string $field
): array {
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || !recipeArrayIsList($value)) {
        throw new InvalidArgumentException($field . ' must be an array');
    }
    if (count($value) > 50) {
        throw new InvalidArgumentException($field . ' has too many entries');
    }
    $names = [];
    $seen = [];
    foreach ($value as $item) {
        $name = recipeCookidooCleanText(
            $item,
            $field,
            120,
            true
        );
        $key = mb_strtolower($name, 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $names[] = $name;
    }
    return $names;
}

function recipeCookidooNormalizeOrderedIngredients(mixed $value): array {
    if (!is_array($value) || !recipeArrayIsList($value)) {
        throw new InvalidArgumentException('ingredients must be an array');
    }
    if (count($value) > 200) {
        throw new InvalidArgumentException('ingredients has too many entries');
    }
    $ingredients = [];
    foreach ($value as $position => $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('ingredients contains an invalid entry');
        }
        $quantity = recipeCookidooNormalizeOptionalNumber(
            $item['source_quantity'] ?? null,
            'source_quantity'
        );
        $quantityMax = recipeCookidooNormalizeOptionalNumber(
            $item['source_quantity_max'] ?? null,
            'source_quantity_max'
        );
        if ($quantity === null && $quantityMax !== null) {
            throw new InvalidArgumentException(
                'source_quantity_max requires source_quantity'
            );
        }
        if ($quantity !== null && $quantityMax !== null && $quantityMax < $quantity) {
            throw new InvalidArgumentException(
                'source_quantity_max must not be less than source_quantity'
            );
        }
        $unit = recipeCookidooCleanText(
            $item['source_unit'] ?? '',
            'source_unit',
            80
        );
        $amountText = recipeCookidooCleanText(
            $item['source_amount_text'] ?? '',
            'source_amount_text',
            160
        );
        $amountText = recipeIngredientValidateSourceAmountText(
            $amountText,
            $quantity,
            $quantityMax,
            $unit !== '' ? $unit : null
        );
        $groupTitle = recipeCookidooCleanText(
            $item['source_group_title'] ?? '',
            'source_group_title',
            160
        );
        $defaultTitle = recipeCookidooCleanText(
            $item['source_default_title'] ?? '',
            'source_default_title',
            200
        );
        $ingredients[] = [
            'name' => recipeCookidooCleanText(
                $item['name'] ?? null,
                'ingredient_name',
                200,
                true
            ),
            'source_quantity' => $quantity,
            'source_quantity_max' => $quantityMax,
            'source_unit' => $unit !== '' ? $unit : null,
            'source_amount_text' => $amountText,
            'source_group_index' => recipeIngredientNormalizeSourceOrdinal(
                $item['source_group_index'] ?? null,
                'source_group_index',
                0
            ),
            'source_group_position' => recipeIngredientNormalizeSourceOrdinal(
                $item['source_group_position'] ?? null,
                'source_group_position',
                (int)$position
            ),
            'source_group_title' =>
                $groupTitle !== '' ? $groupTitle : null,
            'source_ingredient_ref' =>
                recipeIngredientNormalizeSourceReference(
                    $item['source_ingredient_ref'] ?? null,
                    'source_ingredient_ref'
                ),
            'source_default_title' =>
                $defaultTitle !== '' ? $defaultTitle : null,
            'source_unit_ref' => recipeIngredientNormalizeSourceReference(
                $item['source_unit_ref'] ?? null,
                'source_unit_ref'
            ),
            'source_optional' => recipeIngredientNormalizeSourceOptional(
                $item['source_optional'] ?? null
            ),
            'source_shopping_category_ref' =>
                recipeIngredientNormalizeSourceReference(
                    $item['source_shopping_category_ref'] ?? null,
                    'source_shopping_category_ref'
                ),
        ];
    }
    return recipeIngredientValidateSourceGrouping($ingredients);
}

function recipeCookidooNormalizeTopologyMetrics(
    mixed $value,
    array $ingredients
): array {
    if (!is_array($value) || ($value !== [] && recipeArrayIsList($value))) {
        throw new InvalidArgumentException(
            'topology_metrics must be an object'
        );
    }
    $fields = [
        'group_count',
        'group_title_key_count',
        'group_title_nonempty_count',
        'group_title_length_total',
        'group_title_length_max',
        'ingredient_count',
        'ingredient_ref_key_count',
        'ingredient_ref_nonempty_count',
        'default_title_key_count',
        'default_title_nonempty_count',
        'unit_ref_key_count',
        'unit_ref_nonempty_count',
        'optional_key_count',
        'optional_true_count',
        'optional_false_count',
        'optional_null_count',
        'shopping_category_ref_key_count',
        'shopping_category_ref_nonempty_count',
    ];
    if (array_diff(array_keys($value), $fields)) {
        throw new InvalidArgumentException(
            'topology_metrics contains an invalid field'
        );
    }
    $metrics = [];
    foreach ($fields as $field) {
        $raw = $value[$field] ?? null;
        if (is_bool($raw) || !is_int($raw) || $raw < 0 || $raw > 100000) {
            throw new InvalidArgumentException(
                'topology_metrics field is invalid'
            );
        }
        $metrics[$field] = $raw;
    }
    $ingredientCount = count($ingredients);
    $groupCount = $ingredientCount > 0
        ? count(array_unique(array_column(
            $ingredients,
            'source_group_index'
        )))
        : 0;
    $groupTitles = [];
    $actual = [
        'group_title_nonempty_count' => 0,
        'group_title_length_total' => 0,
        'group_title_length_max' => 0,
        'ingredient_ref_nonempty_count' => 0,
        'default_title_nonempty_count' => 0,
        'unit_ref_nonempty_count' => 0,
        'optional_true_count' => 0,
        'optional_false_count' => 0,
        'optional_null_count' => 0,
        'shopping_category_ref_nonempty_count' => 0,
    ];
    foreach ($ingredients as $ingredient) {
        $groupIndex = (int)$ingredient['source_group_index'];
        if (!array_key_exists($groupIndex, $groupTitles)) {
            $groupTitle = $ingredient['source_group_title'];
            $groupTitles[$groupIndex] = $groupTitle;
            if ($groupTitle !== null) {
                $length = mb_strlen((string)$groupTitle, 'UTF-8');
                $actual['group_title_nonempty_count']++;
                $actual['group_title_length_total'] += $length;
                $actual['group_title_length_max'] = max(
                    $actual['group_title_length_max'],
                    $length
                );
            }
        }
        foreach ([
            'source_ingredient_ref' => 'ingredient_ref_nonempty_count',
            'source_default_title' => 'default_title_nonempty_count',
            'source_unit_ref' => 'unit_ref_nonempty_count',
            'source_shopping_category_ref' =>
                'shopping_category_ref_nonempty_count',
        ] as $field => $counter) {
            if ($ingredient[$field] !== null) {
                $actual[$counter]++;
            }
        }
        if ($ingredient['source_optional'] === 1) {
            $actual['optional_true_count']++;
        } elseif ($ingredient['source_optional'] === 0) {
            $actual['optional_false_count']++;
        } else {
            $actual['optional_null_count']++;
        }
    }
    if (
        $metrics['ingredient_count'] !== $ingredientCount
        || $metrics['group_count'] !== $groupCount
        || $metrics['group_count'] > 40
        || $metrics['group_title_key_count'] > $metrics['group_count']
        || $metrics['group_title_nonempty_count']
            > $metrics['group_title_key_count']
        || $metrics['group_title_length_max'] > 160
        || $metrics['group_title_length_total']
            > $metrics['group_title_nonempty_count'] * 160
        || $metrics['group_title_length_max']
            > $metrics['group_title_length_total']
        || $metrics['ingredient_ref_key_count'] > $ingredientCount
        || $metrics['ingredient_ref_nonempty_count']
            > $metrics['ingredient_ref_key_count']
        || $metrics['default_title_key_count'] > $ingredientCount
        || $metrics['default_title_nonempty_count']
            > $metrics['default_title_key_count']
        || $metrics['unit_ref_key_count'] > $ingredientCount
        || $metrics['unit_ref_nonempty_count']
            > $metrics['unit_ref_key_count']
        || $metrics['optional_key_count'] > $ingredientCount
        || $metrics['optional_true_count']
            + $metrics['optional_false_count']
            + $metrics['optional_null_count'] !== $ingredientCount
        || $metrics['optional_true_count']
            + $metrics['optional_false_count']
            > $metrics['optional_key_count']
        || $metrics['shopping_category_ref_key_count'] > $ingredientCount
        || $metrics['shopping_category_ref_nonempty_count']
            > $metrics['shopping_category_ref_key_count']
        || array_filter(
            $actual,
            static fn(int $count, string $field): bool =>
                $metrics[$field] !== $count,
            ARRAY_FILTER_USE_BOTH
        )
    ) {
        throw new InvalidArgumentException(
            'topology_metrics is inconsistent'
        );
    }
    return $metrics;
}

function recipeCookidooNormalizeGeneral(mixed $value): array {
    if ($value === null) {
        $value = [];
    }
    if (!is_array($value) || ($value !== [] && recipeArrayIsList($value))) {
        throw new InvalidArgumentException('general must be an object');
    }
    $yieldQuantity = recipeCookidooNormalizeOptionalNumber(
        $value['yield_quantity'] ?? null,
        'yield_quantity'
    );
    $yieldUnit = recipeCookidooCleanText(
        $value['yield_unit'] ?? '',
        'yield_unit',
        80
    );
    if ($yieldQuantity === null || $yieldQuantity <= 0 || $yieldUnit === '') {
        $yieldQuantity = null;
        $yieldUnit = '';
    }
    $equipmentInput = $value['equipment'] ?? [];
    if (!is_array($equipmentInput) || !recipeArrayIsList($equipmentInput)) {
        throw new InvalidArgumentException('equipment must be an array');
    }
    if (count($equipmentInput) > 50) {
        throw new InvalidArgumentException('equipment has too many entries');
    }
    $equipment = [];
    foreach ($equipmentInput as $item) {
        $equipment[] = recipeCookidooCleanText(
            $item,
            'equipment',
            120,
            true
        );
    }
    $difficulty = recipeCookidooCleanText(
        $value['difficulty'] ?? '',
        'difficulty',
        80
    );
    $primaryCategory = recipeCookidooCleanText(
        $value['primary_category'] ?? '',
        'primary_category',
        160
    );
    $prepTimeSeconds = recipeCookidooNormalizeOptionalSeconds(
        $value['prep_time_seconds'] ?? null,
        'prep_time_seconds'
    );
    $cookTimeSeconds = recipeCookidooNormalizeOptionalSeconds(
        $value['cook_time_seconds'] ?? null,
        'cook_time_seconds'
    );
    $activeTimeSeconds = recipeCookidooNormalizeOptionalSeconds(
        $value['active_time_seconds'] ?? null,
        'active_time_seconds'
    );
    $inactiveTimeSeconds = recipeCookidooNormalizeOptionalSeconds(
        $value['inactive_time_seconds'] ?? null,
        'inactive_time_seconds'
    );
    $totalTimeSeconds = recipeCookidooNormalizeOptionalSeconds(
        $value['total_time_seconds'] ?? null,
        'total_time_seconds'
    );
    $inactiveTimeSeconds = recipeTimeDeriveInactiveSeconds(
        $activeTimeSeconds,
        $totalTimeSeconds,
        $prepTimeSeconds,
        $cookTimeSeconds,
        $inactiveTimeSeconds
    );
    $devices = recipeCookidooNormalizeFactNameList(
        $value['devices'] ?? [],
        'devices'
    );
    $requiredDeviceKeys = array_fill_keys(
        array_map(
            static fn(string $name): string =>
                mb_strtolower($name, 'UTF-8'),
            $devices
        ),
        true
    );
    $optionalDevices = array_values(array_filter(
        recipeCookidooNormalizeFactNameList(
            $value['optional_devices'] ?? [],
            'optional_devices'
        ),
        static fn(string $name): bool =>
            !isset($requiredDeviceKeys[mb_strtolower($name, 'UTF-8')])
    ));
    return [
        'yield_quantity' => $yieldQuantity,
        'yield_unit' => $yieldUnit !== '' ? $yieldUnit : null,
        'prep_time_seconds' => $prepTimeSeconds,
        'cook_time_seconds' => $cookTimeSeconds,
        'active_time_seconds' => $activeTimeSeconds,
        'inactive_time_seconds' => $inactiveTimeSeconds,
        'total_time_seconds' => $totalTimeSeconds,
        'difficulty' => $difficulty !== '' ? $difficulty : null,
        'primary_category' => $primaryCategory !== '' ? $primaryCategory : null,
        'devices' => $devices,
        'optional_devices' => $optionalDevices,
        'equipment' => $equipment,
    ];
}

function recipeCookidooNormalizeIdList(mixed $value): array {
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || !recipeArrayIsList($value)) {
        throw new InvalidArgumentException('exclude_ids must be an array');
    }
    if (count($value) > 100000) {
        throw new InvalidArgumentException('exclude_ids has too many entries');
    }
    $ids = [];
    foreach ($value as $item) {
        $id = recipeCookidooCleanText($item, 'exclude_ids', 160, true);
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $id)) {
            throw new InvalidArgumentException('exclude_ids contains an invalid ID');
        }
        $ids[$id] = true;
    }
    return array_keys($ids);
}

function recipeCookidooNormalizeMetadataRefreshInput(array $input): array {
    $locale = recipeCookidooNormalizeLocale($input['locale'] ?? null);
    $items = $input['recipes'] ?? $input['items'] ?? null;
    if (!is_array($items) || !recipeArrayIsList($items)) {
        throw new InvalidArgumentException('metadata refresh recipes must be an array');
    }
    if (count($items) < 1 || count($items) > 20) {
        throw new InvalidArgumentException(
            'metadata refresh recipes must contain between 1 and 20 entries'
        );
    }
    $normalized = [];
    $seenRecipeIds = [];
    $seenOriginIds = [];
    $seenExternalIds = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException(
                'metadata refresh recipes contains an invalid entry'
            );
        }
        $recipeId = $item['recipe_id'] ?? null;
        $originId = $item['origin_id'] ?? null;
        if (is_bool($recipeId) || !is_int($recipeId) || $recipeId <= 0) {
            throw new InvalidArgumentException('metadata refresh recipe_id is invalid');
        }
        if (is_bool($originId) || !is_int($originId) || $originId <= 0) {
            throw new InvalidArgumentException('metadata refresh origin_id is invalid');
        }
        $externalId = recipeCookidooCleanText(
            $item['external_id'] ?? null,
            'external_id',
            160,
            true
        );
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $externalId)) {
            throw new InvalidArgumentException('external_id is invalid');
        }
        if (
            isset($seenRecipeIds[$recipeId])
            || isset($seenOriginIds[$originId])
            || isset($seenExternalIds[$externalId])
        ) {
            throw new InvalidArgumentException(
                'metadata refresh recipes must be unique'
            );
        }
        $seenRecipeIds[$recipeId] = true;
        $seenOriginIds[$originId] = true;
        $seenExternalIds[$externalId] = true;
        $normalized[] = [
            'recipe_id' => $recipeId,
            'origin_id' => $originId,
            'external_id' => $externalId,
        ];
    }
    return ['locale' => $locale, 'recipes' => $normalized];
}

function recipeCookidooMetadataRefreshIdempotencyKey(array $input): string {
    $input = recipeCookidooNormalizeMetadataRefreshInput($input);
    return 'recipe_metadata_refresh:cookidoo:'
        . hash(
            'sha256',
            recipeCatalogJsonEncode(recipeCatalogStableValue([
                'metadata_version' => RECIPE_COOKIDOO_METADATA_VERSION,
                'metadata_schema_version' =>
                    RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
                'locale' => strtolower($input['locale']),
                'recipes' => $input['recipes'],
            ]))
        );
}

function recipeCookidooEnqueueMetadataRefreshJob(
    PDO $db,
    array $input,
    bool $requireEnabled = true
): array {
    if ($requireEnabled && !recipeCookidooDetailHydrationPolicyAllows()) {
        throw new RuntimeException(RECIPE_COOKIDOO_DETAIL_POLICY_REASON);
    }
    if ($requireEnabled && !recipeCookidooMetadataBackfillConfigured()) {
        throw new RuntimeException('cookidoo_metadata_backfill_disabled');
    }
    $input = recipeCookidooNormalizeMetadataRefreshInput($input);
    $key = recipeCookidooMetadataRefreshIdempotencyKey($input);
    $scope = [
        'scope' => 'metadata-v2:'
            . RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION
            . ':' . strtolower($input['locale']),
        'connector' => RECIPE_COOKIDOO_CONNECTOR,
    ];
    $existing = recipeJobGet($db, null, $key);
    if (
        $existing !== null
        && in_array(
            (string)$existing['status'],
            ['done', 'failed', 'skipped'],
            true
        )
    ) {
        return [
            'job' => recipeJobEnqueue(
                $db,
                'recipe_metadata_refresh',
                $scope,
                $input,
                $key,
                3,
                0
            ),
            'created' => false,
            'requeued' => true,
        ];
    }
    $enqueue = recipeJobEnqueueOnce(
        $db,
        'recipe_metadata_refresh',
        $scope,
        $input,
        $key,
        3,
        0
    );
    return $enqueue + ['requeued' => false];
}

function recipeCookidooMetadataBackfillCursorKey(string $locale): string {
    $locale = recipeCookidooNormalizeLocale($locale);
    return 'cookidoo_metadata_v2_cursor:' . strtolower($locale);
}

function recipeCookidooMetadataBackfillCursor(PDO $db, string $locale): int {
    $stmt = $db->prepare("SELECT value FROM app_settings WHERE key = ?");
    $stmt->execute([recipeCookidooMetadataBackfillCursorKey($locale)]);
    return max(0, (int)($stmt->fetchColumn() ?: 0));
}

function recipeCookidooSetMetadataBackfillCursor(
    PDO $db,
    string $locale,
    int $cursor
): void {
    $db->prepare("
        INSERT INTO app_settings (key, value, updated_at)
        VALUES (?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(key) DO UPDATE SET
            value = excluded.value,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([
        recipeCookidooMetadataBackfillCursorKey($locale),
        (string)max(0, $cursor),
    ]);
}

function recipeCookidooMetadataFailureIsPermanent(string $errorKind): bool {
    return in_array(
        $errorKind,
        [
            'invalid_id',
            'locale_mismatch',
            'content_language_rejected',
        ],
        true
    );
}

function recipeCookidooMetadataFailureNextProbeAt(
    string $errorKind,
    int $failureCount,
    ?int $now = null
): ?string {
    if (recipeCookidooMetadataFailureIsPermanent($errorKind)) {
        return null;
    }
    $failureCount = max(1, min(255, $failureCount));
    if ($errorKind === 'not_found') {
        $ttl = RECIPE_COOKIDOO_NOT_FOUND_TTL_SECONDS;
    } elseif ($errorKind === 'invalid_metadata') {
        $ttl = min(
            RECIPE_COOKIDOO_INVALID_METADATA_MAX_TTL_SECONDS,
            RECIPE_COOKIDOO_INVALID_METADATA_BASE_TTL_SECONDS
                * (2 ** min(5, $failureCount - 1))
        );
    } else {
        throw new InvalidArgumentException(
            'metadata refresh failure kind is invalid'
        );
    }
    return gmdate('Y-m-d H:i:s', ($now ?? time()) + $ttl);
}

function recipeCookidooMetadataFailureBlocks(
    array $row,
    ?int $now = null
): bool {
    if (
        (string)($row['metadata_failure_version'] ?? '')
            !== RECIPE_COOKIDOO_METADATA_VERSION
    ) {
        return false;
    }
    $kind = (string)($row['metadata_failure_kind'] ?? '');
    if (!in_array($kind, RECIPE_COOKIDOO_METADATA_FAILURE_KINDS, true)) {
        return false;
    }
    if (recipeCookidooMetadataFailureIsPermanent($kind)) {
        return true;
    }
    if (
        $kind === 'invalid_metadata'
        && (string)($row['metadata_failure_schema_version'] ?? '')
            !== RECIPE_COOKIDOO_METADATA_FAILURE_SCHEMA_VERSION
    ) {
        return false;
    }
    $nextProbeAt = trim((string)($row['metadata_next_probe_at'] ?? ''));
    if ($nextProbeAt === '') {
        return true;
    }
    $timestamp = strtotime($nextProbeAt . ' UTC');
    return $timestamp === false || $timestamp > ($now ?? time());
}

function recipeCookidooResetMetadataFailure(PDO $db, int $originId): bool {
    if ($originId <= 0) {
        throw new InvalidArgumentException('metadata origin ID is invalid');
    }
    $stmt = $db->prepare("
        UPDATE recipe_origins SET
            metadata_failure_version = NULL,
            metadata_failure_kind = NULL,
            metadata_failure_at = NULL,
            metadata_failure_count = 0,
            metadata_next_probe_at = NULL,
            metadata_failure_schema_version = NULL
        WHERE id = ? AND connector = ?
    ");
    $stmt->execute([$originId, RECIPE_COOKIDOO_CONNECTOR]);
    return $stmt->rowCount() > 0;
}

function recipeCookidooMetadataBackfillCandidates(
    PDO $db,
    string $locale,
    int $afterOriginId,
    int $limit
): array {
    if (!recipeCookidooDetailHydrationPolicyAllows()) {
        return [];
    }
    $locale = recipeCookidooNormalizeLocale($locale);
    if (recipeCookidooLocaleIsLanguageOnly($locale)) {
        return [];
    }
    $afterOriginId = max(0, $afterOriginId);
    $limit = max(1, min(200, $limit));
    $stmt = $db->prepare("
        SELECT o.id AS origin_id, o.recipe_id, o.external_id
        FROM recipe_origins o
        JOIN recipe_catalog c ON c.id = o.recipe_id
        WHERE o.id > ?
          AND o.connector = ?
          AND o.external_id IS NOT NULL
          AND TRIM(o.external_id) <> ''
          AND lower(o.locale) = lower(?)
          AND (
              o.metadata_version IS NULL
              OR o.metadata_version <> ?
              OR o.metadata_schema_version IS NULL
              OR o.metadata_schema_version <> ?
          )
          AND (
              o.metadata_failure_version IS NULL
              OR o.metadata_failure_version <> ?
              OR o.metadata_failure_kind IS NULL
              OR (
                  o.metadata_failure_kind IN ('not_found', 'invalid_metadata')
                  AND (
                      (
                          o.metadata_next_probe_at IS NOT NULL
                          AND o.metadata_next_probe_at <= CURRENT_TIMESTAMP
                      )
                      OR (
                          o.metadata_failure_kind = 'invalid_metadata'
                          AND COALESCE(
                              o.metadata_failure_schema_version,
                              ''
                          ) <> ?
                      )
                  )
              )
          )
          AND c.primary_connector = ?
          AND c.deleted_at IS NULL
        ORDER BY o.id ASC
        LIMIT {$limit}
    ");
    $stmt->execute([
        $afterOriginId,
        RECIPE_COOKIDOO_CONNECTOR,
        $locale,
        RECIPE_COOKIDOO_METADATA_VERSION,
        RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        RECIPE_COOKIDOO_METADATA_VERSION,
        RECIPE_COOKIDOO_METADATA_FAILURE_SCHEMA_VERSION,
        RECIPE_COOKIDOO_CONNECTOR,
    ]);
    return array_map(
        static fn(array $row): array => [
            'origin_id' => (int)$row['origin_id'],
            'recipe_id' => (int)$row['recipe_id'],
            'external_id' => (string)$row['external_id'],
        ],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

function recipeCookidooMetadataBackfillPlan(
    PDO $db,
    string $locale,
    ?int $batchSize = null,
    int $maxRecipes = 200
): array {
    $locale = recipeCookidooNormalizeLocale($locale);
    $batchSize ??= recipeCookidooMetadataBackfillBatchSize();
    $batchSize = max(1, min(20, $batchSize));
    $maxRecipes = max(1, min(200, $maxRecipes));
    $cursor = recipeCookidooMetadataBackfillCursor($db, $locale);
    $policyAllowed = recipeCookidooDetailHydrationPolicyAllows();
    $refreshable = $policyAllowed
        && !recipeCookidooLocaleIsLanguageOnly($locale);
    if (!$refreshable) {
        return [
            'metadata_version' => RECIPE_COOKIDOO_METADATA_VERSION,
            'metadata_schema_version' =>
                RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
            'mapping_version' => recipeIngredientActiveMappingVersion(),
            'failure_schema_version' =>
                RECIPE_COOKIDOO_METADATA_FAILURE_SCHEMA_VERSION,
            'locale' => $locale,
            'refreshable' => false,
            'unrefreshable_reason' => !$policyAllowed
                ? RECIPE_COOKIDOO_DETAIL_POLICY_REASON
                : 'language_only_locale',
            'cursor' => $cursor,
            'wrapped' => false,
            'batch_size' => $batchSize,
            'max_recipes' => $maxRecipes,
            'recipe_count' => 0,
            'batch_count' => 0,
            'batches' => [],
            'pilot_controls' => recipeCookidooMetadataPilotControls(),
        ];
    }
    $rows = recipeCookidooMetadataBackfillCandidates(
        $db,
        $locale,
        $cursor,
        $maxRecipes
    );
    $wrapped = false;
    if (!$rows && $cursor > 0) {
        $rows = recipeCookidooMetadataBackfillCandidates(
            $db,
            $locale,
            0,
            $maxRecipes
        );
        $wrapped = true;
    }
    $batches = [];
    foreach (array_chunk($rows, $batchSize) as $batch) {
        $input = ['locale' => $locale, 'recipes' => $batch];
        $batches[] = [
            'input' => $input,
            'idempotency_key' => recipeCookidooMetadataRefreshIdempotencyKey(
                $input
            ),
            'last_origin_id' => (int)$batch[count($batch) - 1]['origin_id'],
        ];
    }
    return [
        'metadata_version' => RECIPE_COOKIDOO_METADATA_VERSION,
        'metadata_schema_version' =>
            RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        'mapping_version' => recipeIngredientActiveMappingVersion(),
        'failure_schema_version' =>
            RECIPE_COOKIDOO_METADATA_FAILURE_SCHEMA_VERSION,
        'locale' => $locale,
        'refreshable' => true,
        'unrefreshable_reason' => null,
        'cursor' => $cursor,
        'wrapped' => $wrapped,
        'batch_size' => $batchSize,
        'max_recipes' => $maxRecipes,
        'recipe_count' => count($rows),
        'batch_count' => count($batches),
        'batches' => $batches,
        'pilot_controls' => recipeCookidooMetadataPilotControls(),
    ];
}

function recipeCookidooMetadataBackfillStatus(
    PDO $db,
    string $locale
): array {
    $locale = recipeCookidooNormalizeLocale($locale);
    $policyAllowed = recipeCookidooDetailHydrationPolicyAllows();
    $languageOnly = recipeCookidooLocaleIsLanguageOnly($locale);
    $refreshable = $policyAllowed && !$languageOnly;
    $counts = $db->prepare("
        WITH scoped AS (
            SELECT
                CASE
                    WHEN o.metadata_version = ?
                     AND o.metadata_schema_version = ?
                    THEN 0 ELSE 1
                END AS needs_refresh,
                CASE
                    WHEN o.metadata_failure_version = ?
                     AND (
                         o.metadata_failure_kind IN (
                             'invalid_id', 'locale_mismatch'
                         )
                         OR (
                             o.metadata_failure_kind = 'not_found'
                             AND (
                                 o.metadata_next_probe_at IS NULL
                                 OR o.metadata_next_probe_at
                                    > CURRENT_TIMESTAMP
                             )
                         )
                         OR (
                             o.metadata_failure_kind = 'invalid_metadata'
                             AND COALESCE(
                                 o.metadata_failure_schema_version,
                                 ''
                             ) = ?
                             AND (
                                 o.metadata_next_probe_at IS NULL
                                 OR o.metadata_next_probe_at
                                    > CURRENT_TIMESTAMP
                             )
                         )
                     )
                    THEN 1 ELSE 0
                END AS blocked,
                CASE
                    WHEN o.metadata_failure_version = ?
                     AND o.metadata_failure_kind IN (
                         'invalid_id', 'invalid_metadata',
                         'locale_mismatch', 'not_found'
                     )
                    THEN 1 ELSE 0
                END AS has_failure
            FROM recipe_origins o
            JOIN recipe_catalog c ON c.id = o.recipe_id
            WHERE o.connector = ?
              AND o.external_id IS NOT NULL
              AND TRIM(o.external_id) <> ''
              AND lower(o.locale) = lower(?)
              AND c.primary_connector = ?
              AND c.deleted_at IS NULL
        )
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN needs_refresh = 0 THEN 1 ELSE 0 END)
                   AS current,
               SUM(CASE
                   WHEN needs_refresh = 1 AND blocked = 1 THEN 1 ELSE 0
               END) AS failed,
               SUM(CASE
                   WHEN needs_refresh = 1 AND blocked = 0 THEN 1 ELSE 0
               END) AS remaining,
               SUM(CASE
                   WHEN needs_refresh = 1
                    AND blocked = 0
                    AND has_failure = 1
                   THEN 1 ELSE 0
               END) AS probe_due
        FROM scoped
    ");
    $counts->execute([
        RECIPE_COOKIDOO_METADATA_VERSION,
        RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        RECIPE_COOKIDOO_METADATA_VERSION,
        RECIPE_COOKIDOO_METADATA_FAILURE_SCHEMA_VERSION,
        RECIPE_COOKIDOO_METADATA_VERSION,
        RECIPE_COOKIDOO_CONNECTOR,
        $locale,
        RECIPE_COOKIDOO_CONNECTOR,
    ]);
    $counts = $counts->fetch(PDO::FETCH_ASSOC) ?: [];
    $pending = (int)($counts['remaining'] ?? 0);
    $jobs = $db->prepare("
        SELECT
            SUM(CASE WHEN status IN ('pending', 'retry') THEN 1 ELSE 0 END)
                AS queued,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END)
                AS running,
            SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END)
                AS done,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END)
                AS failed,
            SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END)
                AS skipped
        FROM recipe_jobs
        WHERE job_type = 'recipe_metadata_refresh'
          AND connector = ?
          AND lower(json_extract(payload_json, '$.locale')) = lower(?)
    ");
    $jobs->execute([RECIPE_COOKIDOO_CONNECTOR, $locale]);
    $jobs = $jobs->fetch(PDO::FETCH_ASSOC) ?: [];

    $ingredientMetrics = $db->prepare("
        SELECT COUNT(rsi.id) AS ingredient_count,
               COUNT(DISTINCT CASE
                   WHEN rsi.id IS NOT NULL
                   THEN CAST(rsi.recipe_id AS TEXT) || ':' ||
                        CAST(COALESCE(rsi.source_group_index, 0) AS TEXT)
                   ELSE NULL
               END) AS group_count,
               COUNT(DISTINCT CASE
                   WHEN rsi.id IS NOT NULL THEN rsi.recipe_id ELSE NULL
               END) AS recipes_with_ingredients,
               SUM(CASE
                   WHEN rsi.id IS NOT NULL
                    AND rsi.source_quantity IS NULL
                   THEN 1 ELSE 0
               END) AS null_quantity_count,
               SUM(CASE
                   WHEN rsi.id IS NOT NULL
                    AND rsi.source_quantity_max IS NOT NULL
                   THEN 1 ELSE 0
               END) AS range_quantity_count,
               SUM(CASE
                   WHEN rsi.source_ingredient_ref IS NOT NULL
                   THEN 1 ELSE 0
               END) AS ingredient_ref_count,
               SUM(CASE
                   WHEN rsi.source_default_title IS NOT NULL
                   THEN 1 ELSE 0
               END) AS default_title_count,
               SUM(CASE
                   WHEN rsi.source_unit_ref IS NOT NULL
                   THEN 1 ELSE 0
               END) AS unit_ref_count,
               SUM(CASE
                   WHEN rsi.id IS NOT NULL
                    AND rsi.source_optional IS NOT NULL
                   THEN 1 ELSE 0
               END) AS optional_present_count,
               SUM(CASE
                   WHEN rsi.source_optional = 1 THEN 1 ELSE 0
               END) AS optional_true_count,
               SUM(CASE
                   WHEN rsi.source_optional = 0 THEN 1 ELSE 0
               END) AS optional_false_count,
               SUM(CASE
                   WHEN rsi.id IS NOT NULL
                    AND rsi.source_optional IS NULL
                   THEN 1 ELSE 0
               END) AS optional_null_count,
               SUM(CASE
                   WHEN rsi.source_shopping_category_ref IS NOT NULL
                   THEN 1 ELSE 0
               END) AS shopping_category_ref_count,
               COUNT(DISTINCT CASE
                   WHEN TRIM(COALESCE(rsi.source_group_title, '')) <> ''
                   THEN CAST(rsi.recipe_id AS TEXT) || ':' ||
                        CAST(COALESCE(rsi.source_group_index, 0) AS TEXT)
                   ELSE NULL
               END) AS labeled_group_count,
               MAX(length(COALESCE(rsi.source_group_title, '')))
                   AS group_title_length_max,
               COUNT(DISTINCT CASE
                   WHEN TRIM(COALESCE(rsi.source_unit, '')) <> ''
                   THEN rsi.source_unit ELSE NULL
               END) AS distinct_unit_count
        FROM recipe_origins o
        JOIN recipe_catalog c ON c.id = o.recipe_id
        LEFT JOIN recipe_source_ingredients rsi
          ON rsi.recipe_id = o.recipe_id
        WHERE o.connector = ?
          AND lower(o.locale) = lower(?)
          AND c.primary_connector = ?
          AND c.deleted_at IS NULL
    ");
    $ingredientMetrics->execute([
        RECIPE_COOKIDOO_CONNECTOR,
        $locale,
        RECIPE_COOKIDOO_CONNECTOR,
    ]);
    $ingredientMetrics = $ingredientMetrics->fetch(PDO::FETCH_ASSOC) ?: [];
    $storedIngredientCount = (int)(
        $ingredientMetrics['ingredient_count'] ?? 0
    );
    $storedGroupCount = (int)($ingredientMetrics['group_count'] ?? 0);
    $storedRate = static function (
        int $numerator,
        int $denominator
    ): ?float {
        return $denominator > 0
            ? round($numerator / $denominator, 6)
            : null;
    };

    $unitStmt = $db->prepare("
        SELECT DISTINCT substr(rsi.source_unit, 1, 80) AS unit
        FROM recipe_origins o
        JOIN recipe_catalog c ON c.id = o.recipe_id
        JOIN recipe_source_ingredients rsi ON rsi.recipe_id = o.recipe_id
        WHERE o.connector = ?
          AND lower(o.locale) = lower(?)
          AND c.primary_connector = ?
          AND c.deleted_at IS NULL
          AND TRIM(COALESCE(rsi.source_unit, '')) <> ''
        ORDER BY unit COLLATE NOCASE ASC
        LIMIT 101
    ");
    $unitStmt->execute([
        RECIPE_COOKIDOO_CONNECTOR,
        $locale,
        RECIPE_COOKIDOO_CONNECTOR,
    ]);
    $unitStrings = array_map(
        static fn(array $row): string => (string)$row['unit'],
        $unitStmt->fetchAll(PDO::FETCH_ASSOC)
    );
    $unitStringsTruncated = count($unitStrings) > 100;
    $unitStrings = array_slice($unitStrings, 0, 100);

    $mappingStmt = $db->prepare("
        SELECT COALESCE(NULLIF(rsi.mapping_version, ''), 'unversioned')
                   AS mapping_version,
               COUNT(*) AS ingredient_count
        FROM recipe_origins o
        JOIN recipe_catalog c ON c.id = o.recipe_id
        JOIN recipe_source_ingredients rsi ON rsi.recipe_id = o.recipe_id
        WHERE o.connector = ?
          AND lower(o.locale) = lower(?)
          AND c.primary_connector = ?
          AND c.deleted_at IS NULL
        GROUP BY COALESCE(NULLIF(rsi.mapping_version, ''), 'unversioned')
        ORDER BY ingredient_count DESC, mapping_version ASC
        LIMIT 20
    ");
    $mappingStmt->execute([
        RECIPE_COOKIDOO_CONNECTOR,
        $locale,
        RECIPE_COOKIDOO_CONNECTOR,
    ]);
    $mappingVersions = [];
    foreach ($mappingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mappingVersions[(string)$row['mapping_version']] =
            (int)$row['ingredient_count'];
    }

    $failureStmt = $db->prepare("
        SELECT o.id AS origin_id, o.recipe_id,
               substr(o.external_id, 1, 160) AS external_id,
               o.metadata_failure_version,
               substr(o.metadata_failure_kind, 1, 40)
                   AS metadata_failure_kind,
               o.metadata_failure_count,
               o.metadata_next_probe_at,
               substr(o.metadata_failure_schema_version, 1, 40)
                   AS metadata_failure_schema_version
        FROM recipe_origins o
        JOIN recipe_catalog c ON c.id = o.recipe_id
        WHERE o.connector = ?
          AND lower(o.locale) = lower(?)
          AND o.metadata_failure_kind IS NOT NULL
          AND c.primary_connector = ?
          AND c.deleted_at IS NULL
        ORDER BY o.metadata_failure_at DESC, o.id DESC
        LIMIT 50
    ");
    $failureStmt->execute([
        RECIPE_COOKIDOO_CONNECTOR,
        $locale,
        RECIPE_COOKIDOO_CONNECTOR,
    ]);
    $failureKinds = [];
    $failureItems = [];
    foreach ($failureStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $kind = (string)$row['metadata_failure_kind'];
        $failureKinds[$kind] = ($failureKinds[$kind] ?? 0) + 1;
        $failureItems[] = [
            'origin_id' => (int)$row['origin_id'],
            'recipe_id' => (int)$row['recipe_id'],
            'external_id' => (string)$row['external_id'],
            'error_kind' => $kind,
            'failure_count' => min(
                255,
                max(0, (int)$row['metadata_failure_count'])
            ),
            'next_probe_at' => $row['metadata_next_probe_at'],
            'blocked' => recipeCookidooMetadataFailureBlocks($row),
        ];
    }
    ksort($failureKinds, SORT_STRING);

    $observabilityStmt = $db->prepare("
        WITH recent AS (
            SELECT last_result_json
            FROM recipe_jobs
            WHERE job_type = 'recipe_metadata_refresh'
              AND connector = ?
              AND lower(json_extract(payload_json, '$.locale')) = lower(?)
              AND last_result_json IS NOT NULL
              AND json_valid(last_result_json)
            ORDER BY id DESC
            LIMIT 50
        )
        SELECT COUNT(*) AS job_count,
               COALESCE(SUM(CAST(json_extract(
                   last_result_json, '$.response_bytes'
               ) AS INTEGER)), 0) AS response_bytes,
               COALESCE(ROUND(AVG(CAST(json_extract(
                   last_result_json, '$.latency_ms'
               ) AS REAL))), 0) AS average_latency_ms,
               COALESCE(SUM(CASE
                   WHEN json_extract(
                       last_result_json,
                       '$.revision_invariants.preserved'
                   ) = 0
                   THEN 1 ELSE 0
               END), 0) AS revision_invariant_failures,
               COALESCE(SUM(CAST(json_extract(
                   last_result_json,
                   '$.topology_metrics.group_title_key_count'
               ) AS INTEGER)), 0) AS group_title_key_count,
               COALESCE(SUM(CAST(json_extract(
                   last_result_json,
                   '$.topology_metrics.group_title_nonempty_count'
               ) AS INTEGER)), 0) AS group_title_nonempty_count,
               COALESCE(MAX(CAST(json_extract(
                   last_result_json,
                   '$.topology_metrics.group_title_length_max'
               ) AS INTEGER)), 0) AS group_title_length_max,
               COALESCE(SUM(CAST(json_extract(
                   last_result_json,
                   '$.topology_metrics.unit_ref_nonempty_count'
               ) AS INTEGER)), 0) AS unit_ref_nonempty_count,
               COALESCE(SUM(CAST(json_extract(
                   last_result_json,
                   '$.topology_metrics.default_title_nonempty_count'
               ) AS INTEGER)), 0) AS default_title_nonempty_count,
               COALESCE(SUM(CAST(json_extract(
                   last_result_json,
                   '$.topology_metrics.optional_true_count'
               ) AS INTEGER)), 0) AS optional_true_count,
               COALESCE(SUM(CAST(json_extract(
                   last_result_json,
                   '$.topology_metrics.optional_false_count'
               ) AS INTEGER)), 0) AS optional_false_count,
               COALESCE(SUM(CAST(json_extract(
                   last_result_json,
                   '$.topology_metrics.optional_null_count'
               ) AS INTEGER)), 0) AS optional_null_count
        FROM recent
    ");
    $observabilityStmt->execute([RECIPE_COOKIDOO_CONNECTOR, $locale]);
    $observability = $observabilityStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $scoreState = recipeScoreState($db);
    return [
        'enabled' => recipeCookidooMetadataBackfillEnabled(),
        'configured_enabled' =>
            recipeCookidooMetadataBackfillConfigured(),
        'detail_hydration' => $policyAllowed,
        'detail_hydration_reason' => $policyAllowed
            ? null
            : RECIPE_COOKIDOO_DETAIL_POLICY_REASON,
        'detail_policy_version' =>
            RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
        'metadata_version' => RECIPE_COOKIDOO_METADATA_VERSION,
        'metadata_schema_version' =>
            RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        'mapping_version' => recipeIngredientActiveMappingVersion(),
        'failure_schema_version' =>
            RECIPE_COOKIDOO_METADATA_FAILURE_SCHEMA_VERSION,
        'locale' => $locale,
        'refreshable' => $refreshable,
        'unrefreshable_reason' => $refreshable
            ? null
            : (!$policyAllowed
                ? RECIPE_COOKIDOO_DETAIL_POLICY_REASON
                : 'language_only_locale'),
        'cursor' => recipeCookidooMetadataBackfillCursor($db, $locale),
        'origins' => [
            'total' => (int)($counts['total'] ?? 0),
            'current' => (int)($counts['current'] ?? 0),
            'failed' => (int)($counts['failed'] ?? 0),
            'probe_due' => (int)($counts['probe_due'] ?? 0),
            'remaining' => $refreshable ? $pending : 0,
            'invalid_locale' => $refreshable
                || !$languageOnly
                ? 0
                : (int)($counts['total'] ?? 0),
            'policy_disabled' => $policyAllowed
                ? 0
                : (int)($counts['total'] ?? 0),
            'unrefreshable' => $refreshable
                ? 0
                : (int)($counts['total'] ?? 0),
        ],
        'jobs' => [
            'queued' => (int)($jobs['queued'] ?? 0),
            'running' => (int)($jobs['running'] ?? 0),
            'done' => (int)($jobs['done'] ?? 0),
            'failed' => (int)($jobs['failed'] ?? 0),
            'skipped' => (int)($jobs['skipped'] ?? 0),
        ],
        'source_metrics' => [
            'ingredient_count' => (int)(
                $ingredientMetrics['ingredient_count'] ?? 0
            ),
            'group_count' => (int)($ingredientMetrics['group_count'] ?? 0),
            'recipes_with_ingredients' => (int)(
                $ingredientMetrics['recipes_with_ingredients'] ?? 0
            ),
            'null_quantity_count' => (int)(
                $ingredientMetrics['null_quantity_count'] ?? 0
            ),
            'range_quantity_count' => (int)(
                $ingredientMetrics['range_quantity_count'] ?? 0
            ),
            'distinct_unit_count' => (int)(
                $ingredientMetrics['distinct_unit_count'] ?? 0
            ),
            'distinct_unit_strings' => $unitStrings,
            'distinct_unit_strings_truncated' => $unitStringsTruncated,
            'mapping_versions' => $mappingVersions,
            'topology' => [
                'labeled_group_count' => (int)(
                    $ingredientMetrics['labeled_group_count'] ?? 0
                ),
                'group_title_length_max' => (int)(
                    $ingredientMetrics['group_title_length_max'] ?? 0
                ),
                'ingredient_ref_count' => (int)(
                    $ingredientMetrics['ingredient_ref_count'] ?? 0
                ),
                'default_title_count' => (int)(
                    $ingredientMetrics['default_title_count'] ?? 0
                ),
                'unit_ref_count' => (int)(
                    $ingredientMetrics['unit_ref_count'] ?? 0
                ),
                'optional_present_count' => (int)(
                    $ingredientMetrics['optional_present_count'] ?? 0
                ),
                'optional_true_count' => (int)(
                    $ingredientMetrics['optional_true_count'] ?? 0
                ),
                'optional_false_count' => (int)(
                    $ingredientMetrics['optional_false_count'] ?? 0
                ),
                'optional_null_count' => (int)(
                    $ingredientMetrics['optional_null_count'] ?? 0
                ),
                'shopping_category_ref_count' => (int)(
                    $ingredientMetrics['shopping_category_ref_count'] ?? 0
                ),
                'labeled_group_rate' => $storedRate(
                    (int)($ingredientMetrics['labeled_group_count'] ?? 0),
                    $storedGroupCount
                ),
                'ingredient_ref_rate' => $storedRate(
                    (int)($ingredientMetrics['ingredient_ref_count'] ?? 0),
                    $storedIngredientCount
                ),
                'default_title_rate' => $storedRate(
                    (int)($ingredientMetrics['default_title_count'] ?? 0),
                    $storedIngredientCount
                ),
                'unit_ref_rate' => $storedRate(
                    (int)($ingredientMetrics['unit_ref_count'] ?? 0),
                    $storedIngredientCount
                ),
                'optional_present_rate' => $storedRate(
                    (int)($ingredientMetrics['optional_present_count'] ?? 0),
                    $storedIngredientCount
                ),
                'optional_true_rate' => $storedRate(
                    (int)($ingredientMetrics['optional_true_count'] ?? 0),
                    $storedIngredientCount
                ),
                'optional_false_rate' => $storedRate(
                    (int)($ingredientMetrics['optional_false_count'] ?? 0),
                    $storedIngredientCount
                ),
                'optional_null_rate' => $storedRate(
                    (int)($ingredientMetrics['optional_null_count'] ?? 0),
                    $storedIngredientCount
                ),
                'shopping_category_ref_rate' => $storedRate(
                    (int)(
                        $ingredientMetrics['shopping_category_ref_count']
                        ?? 0
                    ),
                    $storedIngredientCount
                ),
            ],
        ],
        'failures' => [
            'kind_counts' => $failureKinds,
            'items' => $failureItems,
            'items_truncated' => count($failureItems) >= 50,
        ],
        'recent_job_observability' => [
            'job_count' => (int)($observability['job_count'] ?? 0),
            'response_bytes' => (int)($observability['response_bytes'] ?? 0),
            'average_latency_ms' => (int)(
                $observability['average_latency_ms'] ?? 0
            ),
            'revision_invariant_failures' => (int)(
                $observability['revision_invariant_failures'] ?? 0
            ),
            'topology' => [
                'group_title_key_count' => (int)(
                    $observability['group_title_key_count'] ?? 0
                ),
                'group_title_nonempty_count' => (int)(
                    $observability['group_title_nonempty_count'] ?? 0
                ),
                'group_title_length_max' => (int)(
                    $observability['group_title_length_max'] ?? 0
                ),
                'unit_ref_nonempty_count' => (int)(
                    $observability['unit_ref_nonempty_count'] ?? 0
                ),
                'default_title_nonempty_count' => (int)(
                    $observability['default_title_nonempty_count'] ?? 0
                ),
                'optional_true_count' => (int)(
                    $observability['optional_true_count'] ?? 0
                ),
                'optional_false_count' => (int)(
                    $observability['optional_false_count'] ?? 0
                ),
                'optional_null_count' => (int)(
                    $observability['optional_null_count'] ?? 0
                ),
            ],
        ],
        'revision_snapshot' => [
            'inventory' => (int)$scoreState['inventory_revision'],
            'catalog' => (int)$scoreState['catalog_revision'],
            'cursor' => (int)$scoreState['cursor_revision'],
            'ranking' => $scoreState['active_score_revision_id'] !== null
                ? (int)$scoreState['active_score_revision_id']
                : null,
        ],
        'pilot_controls' => recipeCookidooMetadataPilotControls(),
    ];
}

function recipeCookidooEnqueueMetadataBackfill(
    PDO $db,
    string $locale,
    ?int $batchSize = null,
    int $maxRecipes = 200
): array {
    if (!recipeCookidooDetailHydrationPolicyAllows()) {
        throw new RuntimeException(RECIPE_COOKIDOO_DETAIL_POLICY_REASON);
    }
    if (!recipeCookidooMetadataBackfillConfigured()) {
        throw new RuntimeException('cookidoo_metadata_backfill_disabled');
    }
    $plan = recipeCookidooMetadataBackfillPlan(
        $db,
        $locale,
        $batchSize,
        $maxRecipes
    );
    if (!$plan['refreshable']) {
        throw new InvalidArgumentException(
            'cookidoo_metadata_backfill_requires_regional_or_script_locale'
        );
    }
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $jobs = [];
        $scheduleBase = time();
        foreach ($plan['batches'] as $batchIndex => $batch) {
            $enqueue = recipeCookidooEnqueueMetadataRefreshJob(
                $db,
                $batch['input']
            );
            $scheduledAt = recipeCookidooMetadataBackfillScheduleAt(
                (string)$enqueue['job']['idempotency_key'],
                (int)$batchIndex,
                $scheduleBase
            );
            $db->prepare("
                UPDATE recipe_jobs
                SET next_retry_at = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([
                $scheduledAt,
                (int)$enqueue['job']['id'],
            ]);
            $jobs[] = [
                'id' => (int)$enqueue['job']['id'],
                'idempotency_key' => (string)$enqueue['job']['idempotency_key'],
                'status' => (string)$enqueue['job']['status'],
                'created' => !empty($enqueue['created']),
                'requeued' => !empty($enqueue['requeued']),
                'recipe_count' => count($batch['input']['recipes']),
                'scheduled_at' => $scheduledAt,
            ];
            recipeCookidooSetMetadataBackfillCursor(
                $db,
                $plan['locale'],
                (int)$batch['last_origin_id']
            );
        }
        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return $plan + [
        'queued_jobs' => count($jobs),
        'jobs' => $jobs,
        'next_cursor' => $jobs
            ? (int)$plan['batches'][count($plan['batches']) - 1]['last_origin_id']
            : (int)$plan['cursor'],
    ];
}

function recipeCookidooNormalizeBoolean(
    mixed $value,
    string $field,
    bool $default = false
): bool {
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) && ($value === 0 || $value === 1)) {
        return $value === 1;
    }
    if (is_string($value)) {
        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off', '' => false,
            default => throw new InvalidArgumentException($field . ' must be a boolean'),
        };
    }
    throw new InvalidArgumentException($field . ' must be a boolean');
}

function recipeCookidooNormalizeDiscoveryInput(array $input): array {
    $query = recipeCookidooCleanText($input['query'] ?? '', 'query', 240);
    $ingredients = recipeCookidooNormalizeNameList(
        $input['ingredients'] ?? $input['ingredient_names'] ?? [],
        'ingredients'
    );
    $excludeIngredients = recipeCookidooNormalizeNameList(
        $input['exclude_ingredients'] ?? $input['exclude_names'] ?? [],
        'exclude_ingredients'
    );
    if ($query === '' && !$ingredients) {
        throw new InvalidArgumentException('query or ingredients is required');
    }
    $interactive = recipeCookidooNormalizeBoolean(
        $input['interactive'] ?? false,
        'interactive'
    );
    $force = recipeCookidooNormalizeBoolean(
        $input['force'] ?? false,
        'force'
    );
    $includeLocalResults = recipeCookidooNormalizeBoolean(
        $input['include_local_results'] ?? true,
        'include_local_results'
    );
    if ($interactive && !$ingredients && $query !== '') {
        // Mirror the existing automatic taxonomy path: search both the
        // ingredient-filtered lane and Cookidoo's broader text lane.
        $ingredients = [$query];
    }

    $ingredientKeys = array_map('recipeIngredientNormalizeName', $ingredients);
    $excludeKeys = array_map('recipeIngredientNormalizeName', $excludeIngredients);
    if (array_intersect($ingredientKeys, $excludeKeys)) {
        throw new InvalidArgumentException(
            'ingredients and exclude_ingredients must not overlap'
        );
    }

    $localeInput = trim((string)($input['locale'] ?? ''));
    $locale = $localeInput !== '' ? recipeCookidooNormalizeLocale($localeInput) : '';
    $languages = ['en'];
    $tmv = strtoupper(recipeCookidooCleanText($input['tmv'] ?? 'TM6', 'tmv', 8, true));
    if (!in_array($tmv, ['TM31', 'TM5', 'TM6', 'TM7'], true)) {
        throw new InvalidArgumentException('tmv is unsupported');
    }

    $crawlAll = recipeCookidooNormalizeBoolean(
        $input['crawl_all'] ?? false,
        'crawl_all'
    );
    $limitRaw = $input['limit'] ?? ($crawlAll ? 20 : recipeCookidooResultLimit());
    if (
        is_bool($limitRaw)
        || (!is_int($limitRaw) && !(is_string($limitRaw) && ctype_digit($limitRaw)))
    ) {
        throw new InvalidArgumentException('limit must be an integer');
    }
    $limit = (int)$limitRaw;
    $maximum = $crawlAll ? 20 : recipeCookidooResultLimit();
    if ($limit < 1 || $limit > $maximum) {
        throw new InvalidArgumentException('limit must be between 1 and ' . $maximum);
    }
    if ($crawlAll) {
        $limit = 20;
    }
    $pageRaw = $input['page'] ?? 0;
    if (
        is_bool($pageRaw)
        || (!is_int($pageRaw) && !(is_string($pageRaw) && ctype_digit($pageRaw)))
    ) {
        throw new InvalidArgumentException('page must be an integer');
    }
    $page = (int)$pageRaw;
    if ($page < 0 || $page > 50) {
        throw new InvalidArgumentException('page must be between 0 and 50');
    }
    $excludeCached = recipeCookidooNormalizeBoolean(
        $input['exclude_cached'] ?? false,
        'exclude_cached'
    );
    $excludeIds = recipeCookidooNormalizeIdList($input['exclude_ids'] ?? []);
    $maxPagesRaw = $input['max_pages'] ?? 1;
    if (
        is_bool($maxPagesRaw)
        || (!is_int($maxPagesRaw) && !(is_string($maxPagesRaw) && ctype_digit($maxPagesRaw)))
    ) {
        throw new InvalidArgumentException('max_pages must be an integer');
    }
    $maxPages = (int)$maxPagesRaw;
    if ($maxPages < 1 || $maxPages > 50) {
        throw new InvalidArgumentException('max_pages must be between 1 and 50');
    }
    if ($crawlAll) {
        $excludeCached = true;
        $maxPages = 1;
    }

    return [
        'query' => $query,
        'ingredients' => $ingredients,
        'exclude_ingredients' => $excludeIngredients,
        'locale' => $locale,
        'languages' => $languages,
        'tmv' => $tmv,
        'limit' => $limit,
        'page' => $page,
        'exclude_cached' => $excludeCached,
        'exclude_ids' => $excludeIds,
        'max_pages' => $maxPages,
        'crawl_all' => $crawlAll,
        'interactive' => $interactive,
        'force' => $force,
        'include_local_results' => $includeLocalResults,
    ];
}

function recipeCookidooDiscoveryIdentity(array $request): array {
    $ingredients = array_map('recipeIngredientNormalizeName', $request['ingredients']);
    $excludeIngredients = array_map(
        'recipeIngredientNormalizeName',
        $request['exclude_ingredients']
    );
    sort($ingredients, SORT_STRING);
    sort($excludeIngredients, SORT_STRING);
    $excludeIds = recipeCookidooNormalizeIdList(
        $request['exclude_ids'] ?? []
    );
    sort($excludeIds, SORT_STRING);
    $identity = [
        'connector' => RECIPE_COOKIDOO_CONNECTOR,
        'locale' => strtolower((string)$request['locale']),
        'languages' => ['en'],
        'query' => recipeIngredientNormalizeName((string)$request['query']),
        'ingredients' => array_values(array_unique($ingredients)),
        'exclude_ingredients' => array_values(array_unique($excludeIngredients)),
        'tmv' => (string)$request['tmv'],
        'limit' => (int)$request['limit'],
        'page' => (int)$request['page'],
        'max_pages' => (int)$request['max_pages'],
        'crawl_all' => !empty($request['crawl_all']),
        'exclude_ids_count' => count($excludeIds),
        'exclude_ids_digest' => hash(
            'sha256',
            recipeCatalogJsonEncode($excludeIds)
        ),
    ];
    if (!empty($request['exclude_cached'])) {
        $identity['exclude_cached'] = true;
    }
    return $identity;
}

function recipeCookidooDiscoveryHash(array $request): string {
    return hash(
        'sha256',
        recipeCatalogJsonEncode(recipeCatalogStableValue(
            recipeCookidooDiscoveryIdentity($request)
        ))
    );
}

function recipeCookidooDiscoveryIdempotencyKey(array $request): string {
    return 'connector_discovery:cookidoo:' . recipeCookidooDiscoveryHash($request);
}

function recipeCookidooSearchId(array $request): string {
    $identity = recipeCookidooDiscoveryIdentity($request);
    unset($identity['page']);
    return 'cookidoo:' . substr(hash(
        'sha256',
        recipeCatalogJsonEncode(recipeCatalogStableValue($identity))
    ), 0, 24);
}

function recipeCookidooDiscoveryJobIsCurrent(
    array $job,
    array $request
): bool {
    return (string)($job['scope'] ?? '')
            === recipeCookidooSearchId($request)
        && (string)($job['payload'][
            RECIPE_COOKIDOO_POLICY_FIELD
        ] ?? '') === RECIPE_COOKIDOO_DETAIL_POLICY_VERSION;
}

function recipeCookidooMigrateDiscoveryJob(
    PDO $db,
    array $job,
    array $request
): array {
    if ((int)($job['id'] ?? 0) <= 0) {
        return $job;
    }
    if (recipeCookidooDiscoveryJobIsCurrent($job, $request)) {
        return $job;
    }
    $payload = $request;
    $payload[RECIPE_COOKIDOO_POLICY_FIELD] =
        RECIPE_COOKIDOO_DETAIL_POLICY_VERSION;
    if (!empty($job['payload'][RECIPE_COOKIDOO_CRAWL_REFRESH_FIELD])) {
        $payload[RECIPE_COOKIDOO_CRAWL_REFRESH_FIELD] = true;
    }
    $db->prepare("
        UPDATE recipe_jobs
        SET scope = ?,
            payload_json = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND connector = ?
          AND job_type = 'connector_discovery'
    ")->execute([
        recipeCookidooSearchId($request),
        recipeCatalogJsonEncode($payload),
        (int)$job['id'],
        RECIPE_COOKIDOO_CONNECTOR,
    ]);
    return recipeJobGet($db, (int)$job['id']) ?? $job;
}

function recipeCookidooEnqueueDiscoveryJob(
    PDO $db,
    array $request,
    bool $resetExisting,
    bool $refreshChain = false,
    int $maxAttempts = 3
): array {
    if (!recipeCookidooDetailHydrationPolicyAllows()) {
        throw new RuntimeException(RECIPE_COOKIDOO_DETAIL_POLICY_REASON);
    }
    $request = recipeCookidooNormalizeDiscoveryInput($request);
    $payload = $request;
    $payload[RECIPE_COOKIDOO_POLICY_FIELD] =
        RECIPE_COOKIDOO_DETAIL_POLICY_VERSION;
    if ($refreshChain && !empty($request['crawl_all'])) {
        $payload[RECIPE_COOKIDOO_CRAWL_REFRESH_FIELD] = true;
    }
    $scope = [
        'scope' => recipeCookidooSearchId($request),
        'connector' => RECIPE_COOKIDOO_CONNECTOR,
        'query' => $request['query'],
    ];
    $key = recipeCookidooDiscoveryIdempotencyKey($request);
    $priority = !empty($request['interactive']) ? 100 : 0;
    $existing = recipeJobGet($db, null, $key);
    if (
        $existing !== null
        && !recipeCookidooDiscoveryJobIsCurrent(
            $existing,
            $request
        )
    ) {
        return [
            'job' => recipeJobEnqueue(
                $db,
                'connector_discovery',
                $scope,
                $payload,
                $key,
                $maxAttempts,
                $priority
            ),
            'created' => false,
            'requeued' => true,
            'migrated' => true,
        ];
    }
    if (!$resetExisting) {
        if ($existing !== null && (string)$existing['status'] === 'failed') {
            return [
                'job' => recipeJobEnqueue(
                    $db,
                    'connector_discovery',
                    $scope,
                    $payload,
                    $key,
                    $maxAttempts,
                    $priority
                ),
                'created' => false,
                'requeued' => true,
            ];
        }
        return recipeJobEnqueueOnce(
            $db,
            'connector_discovery',
            $scope,
            $payload,
            $key,
            $maxAttempts,
            $priority
        );
    }
    return [
        'job' => recipeJobEnqueue(
            $db,
            'connector_discovery',
            $scope,
            $payload,
            $key,
            $maxAttempts,
            $priority
        ),
        'created' => $existing === null,
        'requeued' => $existing !== null,
    ];
}

function recipeCookidooDiscover(PDO $db, array $input): array {
    $request = recipeCookidooNormalizeDiscoveryInput($input);
    $searchId = recipeCookidooSearchId($request);
    $localQuery = $request['query'] !== ''
        ? $request['query']
        : implode(' ', $request['ingredients']);
    $local = null;
    if (!empty($request['include_local_results'])) {
        try {
            $local = recipeCatalogSearchResult($db, [
                'query' => $localQuery,
                'mode' => 'stocked',
                'limit' => min(20, $request['limit']),
                'offset' => 0,
                'explain' => false,
            ]);
        } catch (RecipeScoreUnavailableException) {
            $local = [
                'kind' => 'browse',
                'query' => $localQuery,
                'ranking_status' => 'building',
                'total' => 0,
                'items' => [],
                'results' => [],
                'next_cursor' => null,
                'has_more' => false,
            ];
        }
    }
    if (!recipeCookidooDetailHydrationPolicyAllows()) {
        return [
            'search_id' => $searchId,
            'connector_enabled' => recipeConnectorIsEnabled(
                $db,
                RECIPE_COOKIDOO_CONNECTOR
            ),
            'detail_hydration' => false,
            'unrefreshable_reason' =>
                RECIPE_COOKIDOO_DETAIL_POLICY_REASON,
            'policy_version' =>
                RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
            'job' => null,
            'job_created' => false,
            'job_reused' => false,
            'local_results' => $local,
        ];
    }
    if (!recipeConnectorIsEnabled($db, RECIPE_COOKIDOO_CONNECTOR)) {
        return [
            'search_id' => $searchId,
            'connector_enabled' => false,
            'job' => null,
            'job_created' => false,
            'job_reused' => false,
            'local_results' => $local,
        ];
    }
    $resetExisting = empty($request['crawl_all']);
    if (!empty($request['interactive']) && empty($request['force'])) {
        $existing = recipeJobGet(
            $db,
            null,
            recipeCookidooDiscoveryIdempotencyKey($request)
        );
        if (
            $existing !== null
            && recipeCookidooDiscoveryJobIsCurrent(
                $existing,
                $request
            )
        ) {
            $freshHours = max(
                1,
                (int)recipeCookidooConfigValue(
                    'RECIPE_INTERACTIVE_DISCOVERY_FRESH_HOURS',
                    '24'
                )
            );
            $updatedAt = strtotime((string)$existing['updated_at']) ?: 0;
            $fresh = $updatedAt >= time() - ($freshHours * 3600);
            if (
                in_array(
                    (string)$existing['status'],
                    ['pending', 'retry', 'in_progress'],
                    true
                )
                || ($fresh && in_array((string)$existing['status'], ['done', 'skipped'], true))
            ) {
                if ((int)$existing['priority'] < 100) {
                    $db->prepare("
                        UPDATE recipe_jobs SET priority = 100, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ")->execute([(int)$existing['id']]);
                    $existing = recipeJobGet($db, (int)$existing['id']) ?? $existing;
                }
                $enqueue = ['job' => $existing, 'created' => false, 'reused' => true];
                $resetExisting = false;
            }
        }
    }
    if (!isset($enqueue)) {
        $enqueue = recipeCookidooEnqueueDiscoveryJob(
            $db,
            $request,
            $resetExisting
        );
    }
    return [
        'search_id' => $searchId,
        'connector_enabled' => recipeConnectorIsEnabled($db, RECIPE_COOKIDOO_CONNECTOR),
        'job' => $enqueue['job'],
        'job_created' => $enqueue['created'],
        'job_reused' => !empty($enqueue['reused']),
        'local_results' => $local,
    ];
}

function recipeCookidooHydrationStatus(PDO $db, string $searchId): array {
    $searchId = trim($searchId);
    if ($searchId === '' || !str_starts_with($searchId, 'cookidoo:')) {
        throw new InvalidArgumentException('Invalid Cookidoo search ID');
    }
    $stmt = $db->prepare("
        SELECT *
        FROM recipe_jobs
        WHERE scope = ? AND connector = 'cookidoo'
        ORDER BY created_at ASC, id ASC
    ");
    $stmt->execute([$searchId]);
    $jobs = array_map('recipeJobDecodeRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
    if (!$jobs) {
        throw new InvalidArgumentException('Cookidoo search ID was not found');
    }
    $importedIds = [];
    $updatedIds = [];
    $pagesScanned = 0;
    $remoteHasMore = false;
    $remoteExhausted = false;
    $hasPending = false;
    $hasRunning = false;
    $hasFailed = false;
    $skippedError = '';
    foreach ($jobs as $job) {
        $status = (string)$job['status'];
        $hasPending = $hasPending || in_array($status, ['pending', 'retry'], true);
        $hasRunning = $hasRunning || $status === 'in_progress';
        $hasFailed = $hasFailed || $status === 'failed';
        $result = is_array($job['result'] ?? null) ? $job['result'] : [];
        if ($status === 'skipped' && (string)($result['reason'] ?? '') !== '') {
            $hasFailed = true;
            $skippedError = (string)$result['reason'];
        }
        $importedIds = array_merge($importedIds, array_map(
            'intval',
            is_array($result['imported_ids'] ?? null) ? $result['imported_ids'] : []
        ));
        $updatedIds = array_merge($updatedIds, array_map(
            'intval',
            is_array($result['updated_ids'] ?? null) ? $result['updated_ids'] : []
        ));
        $pagesScanned += (int)($result['pages_scanned'] ?? 0);
        if (!empty($result['last_page_had_raw_hits'])) {
            $remoteHasMore = (int)($result['next_page'] ?? 51) <= 50;
        }
        if (
            !empty($result['crawl_complete'])
            || in_array(
                (string)($result['stop_reason'] ?? ''),
                ['empty_page', 'page_limit'],
                true
            )
        ) {
            $remoteExhausted = true;
        }
    }
    $status = $hasRunning
        ? 'running'
        : ($hasPending ? 'queued' : ($hasFailed ? 'failed' : 'complete'));
    $firstJob = $jobs[0];
    $queuePosition = null;
    if (in_array($status, ['queued', 'running'], true)) {
        $position = $db->prepare("
            SELECT COUNT(*)
            FROM recipe_jobs
            WHERE status IN ('pending', 'retry')
              AND (
                  priority > ?
                  OR (priority = ? AND (created_at < ? OR (created_at = ? AND id <= ?)))
              )
        ");
        $position->execute([
            (int)$firstJob['priority'],
            (int)$firstJob['priority'],
            (string)$firstJob['created_at'],
            (string)$firstJob['created_at'],
            (int)$firstJob['id'],
        ]);
        $queuePosition = max(1, (int)$position->fetchColumn());
    }
    $allIds = array_values(array_unique(array_merge($importedIds, $updatedIds)));
    $newItems = [];
    if ($allIds) {
        try {
            $newItems = recipeCatalogCardsByIds($db, $allIds);
        } catch (RecipeScoreUnavailableException) {
            $newItems = [];
        }
    }
    $lastError = $skippedError;
    foreach ($jobs as $job) {
        if ((string)($job['last_error'] ?? '') !== '') {
            $lastError = (string)$job['last_error'];
        }
    }
    return [
        'search_id' => $searchId,
        'status' => $status,
        'imported_count' => count(array_unique($importedIds)),
        'updated_count' => count(array_unique($updatedIds)),
        'pages_scanned' => $pagesScanned,
        'remote_has_more' => $remoteHasMore,
        'remote_exhausted' => $remoteExhausted,
        'queue_position' => $queuePosition,
        'next_poll_ms' => match ($status) {
            'queued' => 15000,
            'running' => 2500,
            default => 0,
        },
        'new_items' => $newItems,
        'error' => $lastError !== '' ? $lastError : null,
    ];
}

function recipeCookidooTaxonomyTermIsEligible(string $slug, string $name): bool {
    $slug = strtolower(trim(str_replace('_', '-', $slug)));
    $name = recipeIngredientNormalizeName($name);
    if ($slug === '' || $name === '') {
        return false;
    }
    $excluded = [
        'food' => true,
        'foods' => true,
        'ingredient' => true,
        'ingredients' => true,
        'prepared-food' => true,
        'prepared-foods' => true,
        'prepared-meal' => true,
        'prepared-meals' => true,
    ];
    return !isset($excluded[$slug])
        && !isset($excluded[str_replace(' ', '-', $name)]);
}

function recipeCookidooAutoDiscoveryTermsForProduct(PDO $db, int $productId): array {
    $candidates = recipeInventoryCandidates($db, [
        'exclude_expired' => true,
        'product_id' => $productId,
    ]);
    if (!$candidates) {
        return [];
    }

    $terms = [];
    $primaryMappings = array_values(array_filter(
        $candidates[0]['mappings'] ?? [],
        static fn(array $mapping): bool => $mapping['role'] === 'primary'
    ));

    $ancestorStmt = $db->prepare("
        SELECT a.slug, a.name, tc.depth
        FROM taxonomy_closure tc
        JOIN taxonomy_nodes a ON a.id = tc.ancestor_node_id
        WHERE tc.descendant_node_id = ?
          AND tc.depth >= 1
          AND a.active = 1
        ORDER BY tc.depth ASC, a.name ASC
    ");
    foreach ($primaryMappings as $mapping) {
        $slug = trim((string)($mapping['slug'] ?? ''));
        $name = trim((string)($mapping['name'] ?? ''));
        if (recipeCookidooTaxonomyTermIsEligible($slug, $name)) {
            $terms['direct:' . $slug] = [
                'name' => $name,
                'slug' => $slug,
                'kind' => 'direct',
                'depth' => 0,
            ];
        }
        $nodeId = (int)($mapping['taxonomy_node_id'] ?? 0);
        if ($nodeId <= 0) {
            continue;
        }
        $ancestorStmt->execute([$nodeId]);
        foreach ($ancestorStmt->fetchAll(PDO::FETCH_ASSOC) as $ancestor) {
            $ancestorSlug = trim((string)$ancestor['slug']);
            $ancestorName = trim((string)$ancestor['name']);
            if (
                !recipeCookidooTaxonomyTermIsEligible(
                    $ancestorSlug,
                    $ancestorName
                )
            ) {
                continue;
            }
            $terms['ancestor:' . $ancestorSlug] ??= [
                'name' => $ancestorName,
                'slug' => $ancestorSlug,
                'kind' => 'ancestor',
                'depth' => (int)$ancestor['depth'],
            ];
        }
    }
    return array_values($terms);
}

function recipeCookidooAutoDiscoverProduct(
    PDO $db,
    int $productId,
    string $reason
): array {
    if (!recipeCookidooDetailHydrationPolicyAllows()) {
        return [
            'queued' => 0,
            'skipped' => 0,
            'reason' => RECIPE_COOKIDOO_DETAIL_POLICY_REASON,
        ];
    }
    if (
        !recipeConnectorIsEnabled($db, RECIPE_COOKIDOO_CONNECTOR)
        || !recipeCookidooBridgeConfigured()
    ) {
        return ['queued' => 0, 'skipped' => 0, 'reason' => 'connector_unavailable'];
    }

    $queued = 0;
    $skipped = 0;
    $jobs = [];
    $refreshSeconds = recipeCookidooMetadataRefreshDays() * 86400;
    foreach (recipeCookidooAutoDiscoveryTermsForProduct($db, $productId) as $term) {
        foreach ([
            ['lane' => 'ingredient', 'ingredients' => [$term['name']]],
            ['lane' => 'text', 'ingredients' => []],
        ] as $lane) {
            $request = recipeCookidooNormalizeDiscoveryInput([
                'query' => $term['name'],
                'ingredients' => $lane['ingredients'],
                'exclude_ingredients' => [],
                'locale' => recipeCookidooDiscoveryLocale(),
                'tmv' => 'TM6',
                'crawl_all' => true,
            ]);
            $key = recipeCookidooDiscoveryIdempotencyKey($request);
            $existing = recipeJobGet($db, null, $key);
            if ($existing !== null) {
                $status = (string)$existing['status'];
                $updatedAt = strtotime((string)($existing['updated_at'] ?? '')) ?: 0;
                if (
                    recipeCookidooDiscoveryJobIsCurrent(
                        $existing,
                        $request
                    )
                    && (
                        in_array($status, ['pending', 'in_progress', 'retry'], true)
                        || ($status === 'done' && $updatedAt >= time() - $refreshSeconds)
                    )
                ) {
                    $skipped++;
                    continue;
                }
            }
            $enqueue = recipeCookidooEnqueueDiscoveryJob(
                $db,
                $request,
                $existing !== null,
                $existing !== null
            );
            $jobs[] = [
                'id' => (int)$enqueue['job']['id'],
                'term' => $term['name'],
                'kind' => $term['kind'],
                'depth' => $term['depth'],
                'lane' => $lane['lane'],
                'page' => 0,
                'crawl_all' => true,
            ];
            $queued++;
        }
    }
    return [
        'queued' => $queued,
        'skipped' => $skipped,
        'reason' => $reason,
        'jobs' => $jobs,
    ];
}

function recipeCookidooEligibleStockedProductIds(PDO $db): array {
    $ids = [];
    foreach (recipeInventoryCandidates($db, ['exclude_expired' => true]) as $candidate) {
        $productId = (int)($candidate['product_id'] ?? 0);
        if ($productId > 0) {
            $ids[$productId] = true;
        }
    }
    $productIds = array_keys($ids);
    sort($productIds, SORT_NUMERIC);
    return $productIds;
}

function recipeCookidooSeedTaxonomyCrawls(PDO $db, array $options = []): array {
    if (!recipeCookidooDetailHydrationPolicyAllows()) {
        return [
            'dry_run' => !empty($options['dry_run']),
            'eligible_products' => 0,
            'terms' => 0,
            'planned' => 0,
            'queued' => 0,
            'would_queue' => 0,
            'skipped' => 0,
            'jobs' => [],
            'reason' => RECIPE_COOKIDOO_DETAIL_POLICY_REASON,
        ];
    }
    $locale = recipeCookidooNormalizeLocale(
        $options['locale'] ?? recipeCookidooDiscoveryLocale()
    );
    $tmv = strtoupper(recipeCookidooCleanText(
        $options['tmv'] ?? 'TM6',
        'tmv',
        8,
        true
    ));
    if (!in_array($tmv, ['TM31', 'TM5', 'TM6', 'TM7'], true)) {
        throw new InvalidArgumentException('tmv is unsupported');
    }
    $dryRun = recipeCookidooNormalizeBoolean(
        $options['dry_run'] ?? false,
        'dry_run'
    );
    $force = recipeCookidooNormalizeBoolean(
        $options['force'] ?? false,
        'force'
    );
    if (
        !$dryRun
        && (
            !recipeConnectorIsEnabled($db, RECIPE_COOKIDOO_CONNECTOR)
            || !recipeCookidooBridgeConfigured()
        )
    ) {
        return [
            'dry_run' => false,
            'force' => $force,
            'locale' => $locale,
            'tmv' => $tmv,
            'eligible_products' => 0,
            'terms' => 0,
            'planned' => 0,
            'would_queue' => 0,
            'queued' => 0,
            'skipped' => 0,
            'jobs' => [],
            'reason' => 'connector_unavailable',
        ];
    }

    $productIds = recipeCookidooEligibleStockedProductIds($db);
    $eligibleProducts = [];
    $termKeys = [];
    $plannedKeys = [];
    $jobs = [];
    $planned = 0;
    $queued = 0;
    $skipped = 0;
    $refreshSeconds = recipeCookidooMetadataRefreshDays() * 86400;

    foreach ($productIds as $productId) {
        $terms = recipeCookidooAutoDiscoveryTermsForProduct($db, $productId);
        if (!$terms) {
            continue;
        }
        $eligibleProducts[$productId] = true;
        foreach ($terms as $term) {
            $termKeys[$term['kind'] . ':' . $term['slug']] = true;
            foreach ([
                ['lane' => 'ingredient', 'ingredients' => [$term['name']]],
                ['lane' => 'text', 'ingredients' => []],
            ] as $lane) {
                $request = recipeCookidooNormalizeDiscoveryInput([
                    'query' => $term['name'],
                    'ingredients' => $lane['ingredients'],
                    'exclude_ingredients' => [],
                    'locale' => $locale,
                    'tmv' => $tmv,
                    'crawl_all' => true,
                ]);
                $key = recipeCookidooDiscoveryIdempotencyKey($request);
                if (isset($plannedKeys[$key])) {
                    continue;
                }
                $plannedKeys[$key] = true;
                $planned++;
                $existing = recipeJobGet($db, null, $key);
                if ($existing !== null && !$force) {
                    $updatedAt = strtotime((string)($existing['updated_at'] ?? '')) ?: 0;
                    $skipReason = in_array(
                        (string)$existing['status'],
                        ['pending', 'in_progress', 'retry'],
                        true
                    )
                        ? 'active_page_job'
                        : ($updatedAt >= time() - $refreshSeconds
                            ? 'cooldown'
                            : 'existing_page_job');
                    $jobs[] = [
                        'id' => (int)$existing['id'],
                        'term' => $term['name'],
                        'kind' => $term['kind'],
                        'depth' => $term['depth'],
                        'lane' => $lane['lane'],
                        'page' => 0,
                        'action' => 'skipped',
                        'reason' => $skipReason,
                    ];
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $jobs[] = [
                        'id' => $existing !== null ? (int)$existing['id'] : null,
                        'term' => $term['name'],
                        'kind' => $term['kind'],
                        'depth' => $term['depth'],
                        'lane' => $lane['lane'],
                        'page' => 0,
                        'action' => $existing !== null ? 'would_requeue' : 'would_enqueue',
                    ];
                    continue;
                }

                $enqueue = recipeCookidooEnqueueDiscoveryJob(
                    $db,
                    $request,
                    $force && $existing !== null,
                    $force
                );
                $jobs[] = [
                    'id' => (int)$enqueue['job']['id'],
                    'term' => $term['name'],
                    'kind' => $term['kind'],
                    'depth' => $term['depth'],
                    'lane' => $lane['lane'],
                    'page' => 0,
                    'action' => $existing !== null ? 'requeued' : 'queued',
                ];
                $queued++;
            }
        }
    }

    return [
        'dry_run' => $dryRun,
        'force' => $force,
        'locale' => $locale,
        'tmv' => $tmv,
        'eligible_products' => count($eligibleProducts),
        'terms' => count($termKeys),
        'planned' => $planned,
        'would_queue' => $dryRun ? max(0, $planned - $skipped) : 0,
        'queued' => $queued,
        'skipped' => $skipped,
        'jobs' => $jobs,
    ];
}

function recipeCookidooEnqueuePeriodicRefreshes(PDO $db, ?int $limit = null): array {
    if (!recipeCookidooDetailHydrationPolicyAllows()) {
        return [
            'queued' => 0,
            'jobs' => [],
            'reason' => RECIPE_COOKIDOO_DETAIL_POLICY_REASON,
        ];
    }
    if (!recipeCookidooEnvBool(
        'COOKIDOO_PERIODIC_REFRESH_ENABLED',
        false
    )) {
        return ['queued' => 0, 'jobs' => [], 'reason' => 'disabled'];
    }
    $limit ??= recipeCookidooPeriodicRefreshLimit();
    $limit = max(0, min(20, $limit));
    if ($limit === 0) {
        return ['queued' => 0, 'reason' => 'disabled'];
    }
    if (
        !recipeConnectorIsEnabled($db, RECIPE_COOKIDOO_CONNECTOR)
        || !recipeCookidooBridgeConfigured()
    ) {
        return ['queued' => 0, 'reason' => 'connector_unavailable'];
    }

    $cutoff = '-' . recipeCookidooMetadataRefreshDays() . ' days';
    $legacyRefreshEnabled = recipeCookidooEnvBool(
        'COOKIDOO_LEGACY_REFRESH_ENABLED',
        false
    );
    $stmt = $db->prepare("
        SELECT *
        FROM recipe_jobs
        WHERE connector = ?
          AND job_type = 'connector_discovery'
          AND (
              (
                  json_extract(
                      payload_json,
                      '$." . RECIPE_COOKIDOO_POLICY_FIELD . "'
                  ) = ?
                  AND (
                      (
                          status = 'done'
                          AND updated_at <= datetime('now', ?)
                      )
                      OR (
                          status = 'failed'
                          AND updated_at <= datetime('now', '-1 hour')
                      )
                  )
              )
              OR (
                  CAST(? AS INTEGER) = 1
                  AND COALESCE(
                      json_extract(
                          payload_json,
                          '$." . RECIPE_COOKIDOO_POLICY_FIELD . "'
                      ),
                      ''
                  ) <> ?
                  AND status IN ('done', 'skipped', 'failed')
              )
          )
          AND (
              COALESCE(json_extract(payload_json, '$.crawl_all'), 0) = 0
              OR COALESCE(json_extract(payload_json, '$.page'), 0) = 0
          )
        ORDER BY updated_at ASC, id ASC
        LIMIT {$limit}
    ");
    $stmt->execute([
        RECIPE_COOKIDOO_CONNECTOR,
        RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
        $cutoff,
        $legacyRefreshEnabled ? 1 : 0,
        RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
    ]);
    $queued = 0;
    $jobs = [];
    $legacyMigrated = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $job = recipeJobDecodeRow($row);
        $request = recipeCookidooNormalizeDiscoveryInput($job['payload']);
        $legacy = !recipeCookidooDiscoveryJobIsCurrent(
            $job,
            $request
        );
        if ($legacy) {
            $job = recipeCookidooMigrateDiscoveryJob(
                $db,
                $job,
                $request
            );
        }
        $request['page'] = 0;
        $request['max_pages'] = 1;
        $request['crawl_all'] = false;
        $request['exclude_cached'] = false;
        $request = recipeCookidooNormalizeDiscoveryInput($request);
        $enqueue = recipeCookidooEnqueueDiscoveryJob(
            $db,
            $request,
            true,
            false,
            (int)$job['max_attempts']
        );
        if ($legacy) {
            $legacyMigrated++;
        }
        $db->prepare("
            UPDATE recipe_jobs
            SET updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([(int)$job['id']]);
        $jobs[] = (int)$enqueue['job']['id'];
        $queued++;
    }
    return [
        'queued' => $queued,
        'jobs' => $jobs,
        'legacy_refresh_enabled' => $legacyRefreshEnabled,
        'legacy_migrated' => $legacyMigrated,
        'crawl_refresh_strategy' => 'page_zero_only',
    ];
}

function recipeCookidooValidateHttpsUrl(
    mixed $value,
    string $field,
    bool $required,
    bool $cookidooOnly,
    bool $assetOnly = false
): string {
    $url = recipeCookidooCleanText($value, $field, 2048, $required);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (
        $parts === false
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        throw new InvalidArgumentException($field . ' must be a safe HTTPS URL');
    }
    $host = strtolower((string)$parts['host']);
    if ($cookidooOnly && !in_array($host, recipeCookidooOfficialHosts(), true)) {
        throw new InvalidArgumentException($field . ' must be a Cookidoo URL');
    }
    if (
        $assetOnly
        && $host !== 'assets.tmecosys.com'
        && !str_ends_with($host, '.tmecosys.com')
    ) {
        throw new InvalidArgumentException($field . ' must use the Cookidoo image host');
    }
    return $url;
}

function recipeCookidooCanonicalUrlLocale(string $url): string {
    $parts = parse_url($url);
    $path = is_array($parts) ? (string)($parts['path'] ?? '') : '';
    if (!preg_match(
        '#^/recipes/recipe/([^/]+)/[^/]+/?$#',
        $path,
        $match
    )) {
        throw new InvalidArgumentException(
            'canonical_url must contain a recipe locale'
        );
    }
    return recipeCookidooNormalizeLocale(rawurldecode($match[1]));
}

function recipeCookidooNormalizeBridgeRecipe(
    mixed $item,
    string $requestedLocale = '',
    bool $requireCanonicalLocale = false
): array {
    if (!is_array($item)) {
        throw new RuntimeException('Cookidoo bridge recipe is invalid');
    }
    try {
        $externalId = recipeCookidooCleanText(
            $item['external_id'] ?? null,
            'external_id',
            160,
            true
        );
        $title = recipeCookidooCleanText(
            $item['title'] ?? null,
            'title',
            400,
            true
        );
        $metadataSchemaVersion = recipeCookidooCleanText(
            $item['metadata_schema_version'] ?? null,
            'metadata_schema_version',
            40,
            true
        );
        if (
            $metadataSchemaVersion
                !== RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION
        ) {
            throw new InvalidArgumentException(
                'metadata_schema_version is unsupported'
            );
        }
        $ingredients = recipeCookidooNormalizeOrderedIngredients(
            $item['ingredients'] ?? []
        );
        if (!$ingredients) {
            throw new InvalidArgumentException(
                'ingredients must contain a complete nonempty list'
            );
        }
        $providerLanguage =
            recipeCookidooNormalizeProviderLanguage(
                $item['provider_language'] ?? null
            );
        if (
            $providerLanguage !== null
            && !recipeCookidooProviderLanguageIsEnglish(
                $providerLanguage
            )
        ) {
            throw new RecipeCookidooLanguageRejectedException(
                'Cookidoo provider language is explicitly non-English'
            );
        }
        $languageAssessment =
            recipeCookidooContentLanguageAssessment(
                $title,
                $ingredients
            );
        $languageAssessment['provider_language'] =
            $providerLanguage;
        $languageAssessment['request_languages'] = ['en'];
        recipeCookidooLanguageEnforce($languageAssessment);
        $topologyMetrics = recipeCookidooNormalizeTopologyMetrics(
            $item['topology_metrics'] ?? null,
            $ingredients
        );
        $general = recipeCookidooNormalizeGeneral($item['general'] ?? []);
        $imageUrl = recipeCookidooValidateHttpsUrl(
            $item['image_url'] ?? '',
            'image_url',
            false,
            false,
            true
        );
        $canonicalUrl = recipeCookidooValidateHttpsUrl(
            $item['canonical_url'] ?? null,
            'canonical_url',
            true,
            true
        );
        $locale = recipeCookidooNormalizeLocale($item['locale'] ?? null);
    } catch (InvalidArgumentException $e) {
        throw new RuntimeException('Cookidoo bridge recipe is invalid', 0, $e);
    }
    if (
        $requestedLocale !== ''
        && strtolower($locale) !== strtolower(
            recipeCookidooNormalizeLocale($requestedLocale)
        )
    ) {
        throw new RuntimeException('Cookidoo bridge recipe locale is invalid');
    }
    if ($requireCanonicalLocale) {
        try {
            $canonicalLocale = recipeCookidooCanonicalUrlLocale($canonicalUrl);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(
                'Cookidoo bridge recipe canonical locale is invalid',
                0,
                $e
            );
        }
        if (strtolower($canonicalLocale) !== strtolower($locale)) {
            throw new RuntimeException(
                'Cookidoo bridge recipe canonical locale is invalid'
            );
        }
    }
    return [
        'external_id' => $externalId,
        'title' => $title,
        'metadata_schema_version' => $metadataSchemaVersion,
        'general' => $general,
        'ingredients' => $ingredients,
        'topology_metrics' => $topologyMetrics,
        'image_url' => $imageUrl,
        'canonical_url' => $canonicalUrl,
        'locale' => $locale,
        'provider_language' => $providerLanguage,
        '_language_assessment' => $languageAssessment,
    ];
}

function recipeCookidooNormalizeBridgeResponse(
    mixed $decoded,
    array $request
): array {
    if (!is_array($decoded) || !isset($decoded['recipes']) || !is_array($decoded['recipes'])) {
        throw new RuntimeException('Cookidoo bridge response is invalid');
    }
    $request = recipeCookidooNormalizeDiscoveryInput($request);
    $limit = (int)$request['limit'];
    if (!recipeArrayIsList($decoded['recipes']) || count($decoded['recipes']) > $limit) {
        throw new RuntimeException('Cookidoo bridge response exceeds the requested limit');
    }
    if (
        !array_key_exists('count', $decoded)
        || !is_int($decoded['count'])
        || $decoded['count'] < 0
        || $decoded['count'] > $limit
        || $decoded['count'] !== count($decoded['recipes'])
    ) {
        throw new RuntimeException('Cookidoo bridge response count is invalid');
    }
    foreach (['pages_scanned', 'last_page', 'next_page'] as $field) {
        if (!array_key_exists($field, $decoded) || !is_int($decoded[$field])) {
            throw new RuntimeException('Cookidoo bridge progress is invalid');
        }
    }
    if (
        !array_key_exists('last_page_had_raw_hits', $decoded)
        || !is_bool($decoded['last_page_had_raw_hits'])
    ) {
        throw new RuntimeException('Cookidoo bridge progress is invalid');
    }
    $pagesScanned = (int)$decoded['pages_scanned'];
    $lastPage = (int)$decoded['last_page'];
    $nextPage = (int)$decoded['next_page'];
    $maximumPages = min(
        (int)$request['max_pages'],
        51 - (int)$request['page']
    );
    if (
        $pagesScanned < 1
        || $pagesScanned > $maximumPages
        || $lastPage !== (int)$request['page'] + $pagesScanned - 1
        || $lastPage < 0
        || $lastPage > 50
        || $nextPage !== $lastPage + 1
        || $nextPage < 1
        || $nextPage > 51
    ) {
        throw new RuntimeException('Cookidoo bridge progress is invalid');
    }
    $requestedLocale = trim((string)$request['locale']);
    if ($requestedLocale !== '') {
        $requestedLocale = recipeCookidooNormalizeLocale($requestedLocale);
    }
    $recipes = [];
    $seen = [];
    $languageRejectedIds = [];
    foreach ($decoded['recipes'] as $item) {
        try {
            $recipe = recipeCookidooNormalizeBridgeRecipe(
                $item,
                '',
                true
            );
        } catch (RecipeCookidooLanguageRejectedException $e) {
            $externalId = trim((string)(
                is_array($item)
                    ? ($item['external_id'] ?? '')
                    : ''
            ));
            if ($externalId !== '') {
                $languageRejectedIds[] = mb_substr(
                    $externalId,
                    0,
                    160,
                    'UTF-8'
                );
            }
            continue;
        }
        if (
            $requestedLocale !== ''
            && !recipeCookidooDiscoveryLocaleMatches(
                $requestedLocale,
                $recipe['locale']
            )
        ) {
            throw new RuntimeException(
                'Cookidoo bridge recipe locale is invalid'
            );
        }
        $externalId = $recipe['external_id'];
        if (isset($seen[$externalId])) {
            throw new RuntimeException('Cookidoo bridge recipe IDs must be unique');
        }
        $seen[$externalId] = true;
        $recipes[] = $recipe;
    }
    return [
        'recipes' => $recipes,
        'count' => count($recipes),
        'pages_scanned' => $pagesScanned,
        'last_page' => $lastPage,
        'next_page' => $nextPage,
        'last_page_had_raw_hits' => $decoded['last_page_had_raw_hits'],
        'language_rejected_count' => count($languageRejectedIds),
        'language_rejected_ids' =>
            array_slice($languageRejectedIds, 0, 100),
    ];
}

function recipeCookidooNormalizeMetadataBridgeResponse(
    mixed $decoded,
    array $request
): array {
    $request = recipeCookidooNormalizeMetadataRefreshInput($request);
    if (
        !is_array($decoded)
        || !isset($decoded['outcomes'])
        || !is_array($decoded['outcomes'])
        || !recipeArrayIsList($decoded['outcomes'])
        || !array_key_exists('count', $decoded)
        || !is_int($decoded['count'])
        || !array_key_exists('succeeded_count', $decoded)
        || !is_int($decoded['succeeded_count'])
        || !array_key_exists('failed_count', $decoded)
        || !is_int($decoded['failed_count'])
        || !array_key_exists('locale', $decoded)
        || !array_key_exists('metadata_schema_version', $decoded)
    ) {
        throw new RuntimeException('Cookidoo metadata bridge response is invalid');
    }
    $expectedCount = count($request['recipes']);
    if (
        $decoded['count'] !== $expectedCount
        || count($decoded['outcomes']) !== $expectedCount
        || $decoded['succeeded_count'] < 0
        || $decoded['failed_count'] < 0
        || $decoded['succeeded_count'] + $decoded['failed_count']
            !== $expectedCount
    ) {
        throw new RuntimeException('Cookidoo metadata bridge response count is invalid');
    }
    try {
        $responseLocale = recipeCookidooNormalizeLocale($decoded['locale']);
    } catch (InvalidArgumentException $e) {
        throw new RuntimeException(
            'Cookidoo metadata bridge response locale is invalid',
            0,
            $e
        );
    }
    if (strtolower($responseLocale) !== strtolower($request['locale'])) {
        throw new RuntimeException('Cookidoo metadata bridge response locale is invalid');
    }
    try {
        $responseSchemaVersion = recipeCookidooCleanText(
            $decoded['metadata_schema_version'],
            'metadata_schema_version',
            40,
            true
        );
    } catch (InvalidArgumentException $e) {
        throw new RuntimeException(
            'Cookidoo metadata bridge schema is invalid',
            0,
            $e
        );
    }
    if (
        $responseSchemaVersion
            !== RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION
    ) {
        throw new RuntimeException(
            'Cookidoo metadata bridge schema is invalid'
        );
    }
    $outcomes = [];
    $succeededCount = 0;
    $failedCount = 0;
    foreach ($decoded['outcomes'] as $index => $item) {
        if (!is_array($item)) {
            throw new RuntimeException(
                'Cookidoo metadata bridge outcome is invalid'
            );
        }
        try {
            $externalId = recipeCookidooCleanText(
                $item['external_id'] ?? null,
                'external_id',
                160,
                true
            );
            $status = recipeCookidooCleanText(
                $item['status'] ?? null,
                'status',
                20,
                true
            );
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(
                'Cookidoo metadata bridge outcome is invalid',
                0,
                $e
            );
        }
        if ($externalId !== $request['recipes'][$index]['external_id']) {
            throw new RuntimeException(
                'Cookidoo metadata bridge response IDs are invalid'
            );
        }
        if ($status === 'succeeded') {
            if (
                !array_key_exists('recipe', $item)
                || array_diff(
                    array_keys($item),
                    ['external_id', 'status', 'recipe']
                )
            ) {
                throw new RuntimeException(
                    'Cookidoo metadata bridge success outcome is invalid'
                );
            }
            try {
                $recipe = recipeCookidooNormalizeBridgeRecipe(
                    $item['recipe'] ?? null,
                    $request['locale'],
                    true
                );
            } catch (RecipeCookidooLanguageRejectedException $e) {
                $outcomes[] = [
                    'external_id' => $externalId,
                    'status' => 'failed',
                    'error_kind' =>
                        'content_language_rejected',
                ];
                $failedCount++;
                continue;
            }
            if ($recipe['external_id'] !== $externalId) {
                throw new RuntimeException(
                    'Cookidoo metadata bridge response IDs are invalid'
                );
            }
            $outcomes[] = [
                'external_id' => $externalId,
                'status' => 'succeeded',
                'recipe' => $recipe,
            ];
            $succeededCount++;
            continue;
        }
        if ($status !== 'failed') {
            throw new RuntimeException(
                'Cookidoo metadata bridge outcome status is invalid'
            );
        }
        if (
            !array_key_exists('error_kind', $item)
            || array_diff(
                array_keys($item),
                ['external_id', 'status', 'error_kind']
            )
        ) {
            throw new RuntimeException(
                'Cookidoo metadata bridge failure outcome is invalid'
            );
        }
        try {
            $errorKind = recipeCookidooCleanText(
                $item['error_kind'] ?? null,
                'error_kind',
                40,
                true
            );
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(
                'Cookidoo metadata bridge failure outcome is invalid',
                0,
                $e
            );
        }
        if (!in_array(
            $errorKind,
            RECIPE_COOKIDOO_METADATA_FAILURE_KINDS,
            true
        )) {
            throw new RuntimeException(
                'Cookidoo metadata bridge failure kind is invalid'
            );
        }
        $outcomes[] = [
            'external_id' => $externalId,
            'status' => 'failed',
            'error_kind' => $errorKind,
        ];
        $failedCount++;
    }
    if ($succeededCount + $failedCount !== $expectedCount) {
        throw new RuntimeException(
            'Cookidoo metadata bridge response count is invalid'
        );
    }
    return [
        'outcomes' => $outcomes,
        'count' => count($outcomes),
        'succeeded_count' => $succeededCount,
        'failed_count' => $failedCount,
        'locale' => $responseLocale,
        'metadata_schema_version' => $responseSchemaVersion,
    ];
}

function recipeCookidooNormalizeRecipeForPersistence(array $recipe): array {
    $title = recipeCookidooCleanText(
        $recipe['title'] ?? $recipe['name'] ?? null,
        'title',
        400,
        true
    );
    $imageUrl = recipeCookidooValidateHttpsUrl(
        $recipe['image_url'] ?? $recipe['image'] ?? '',
        'image_url',
        false,
        false,
        true
    );
    $sourceIngredients = recipeCookidooNormalizeOrderedIngredients(
        $recipe['source_ingredients'] ?? $recipe['ingredients'] ?? []
    );
    $general = recipeCookidooNormalizeGeneral($recipe['general'] ?? [
        'yield_quantity' => $recipe['yield_quantity'] ?? null,
        'yield_unit' => $recipe['yield_unit'] ?? null,
        'prep_time_seconds' => $recipe['prep_time_seconds'] ?? null,
        'cook_time_seconds' => $recipe['cook_time_seconds'] ?? null,
        'active_time_seconds' => $recipe['active_time_seconds'] ?? null,
        'inactive_time_seconds' => $recipe['inactive_time_seconds'] ?? null,
        'total_time_seconds' => $recipe['total_time_seconds'] ?? null,
        'difficulty' => $recipe['difficulty'] ?? null,
        'primary_category' => $recipe['primary_category'] ?? null,
        'devices' => $recipe['devices'] ?? [],
        'optional_devices' => $recipe['optional_devices'] ?? [],
        'equipment' => $recipe['equipment'] ?? [],
    ]);
    $names = [];
    foreach ($sourceIngredients as $ingredient) {
        $name = (string)$ingredient['name'];
        $key = recipeIngredientNormalizeName(
            recipeIngredientIdentityCandidate($name)
        );
        if ($key === '') {
            throw new InvalidArgumentException('ingredients contains an invalid name');
        }
        $names[$key] ??= $name;
    }
    ksort($names, SORT_STRING);
    return [
        'title' => $title,
        'image_url' => $imageUrl,
        'yield_quantity' => $general['yield_quantity'],
        'yield_unit' => $general['yield_unit'],
        'prep_time_seconds' => $general['prep_time_seconds'],
        'cook_time_seconds' => $general['cook_time_seconds'],
        'active_time_seconds' => $general['active_time_seconds'],
        'inactive_time_seconds' => $general['inactive_time_seconds'],
        'total_time_seconds' => $general['total_time_seconds'],
        'difficulty' => $general['difficulty'],
        'primary_category' => $general['primary_category'],
        'devices' => $general['devices'],
        'optional_devices' => $general['optional_devices'],
        'equipment' => $general['equipment'],
        'ingredients' => array_map(
            static fn(string $name): array => ['name' => $name, 'raw_text' => $name],
            array_values($names)
        ),
        'source_ingredients' => $sourceIngredients,
    ];
}

function recipeCookidooNormalizeRetrievedAt(mixed $value): string {
    if ($value === null || trim((string)$value) === '') {
        return gmdate('Y-m-d H:i:s');
    }
    $date = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        (string)$value,
        new DateTimeZone('UTC')
    );
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
    ) {
        throw new InvalidArgumentException('retrieved_at is invalid');
    }
    if (abs($date->getTimestamp() - time()) > 300) {
        throw new InvalidArgumentException('retrieved_at is outside the allowed clock skew');
    }
    return $date->format('Y-m-d H:i:s');
}

function recipeCookidooMetadataStaleAt(string $retrievedAt): string {
    $retrieved = new DateTimeImmutable($retrievedAt, new DateTimeZone('UTC'));
    return $retrieved
        ->modify('+' . recipeCookidooMetadataRefreshDays() . ' days')
        ->format('Y-m-d H:i:s');
}

function recipeCookidooNormalizeOrigin(array $origin): array {
    $origin['external_id'] = recipeCookidooCleanText(
        $origin['external_id'] ?? null,
        'external_id',
        160,
        true
    );
    $origin['canonical_url'] = recipeCookidooValidateHttpsUrl(
        $origin['canonical_url'] ?? null,
        'canonical_url',
        true,
        true
    );
    $origin['locale'] = recipeCookidooNormalizeLocale($origin['locale'] ?? null);
    $origin['content_language'] =
        recipeCookidooNormalizeProviderLanguage(
            $origin['content_language'] ?? null
        );
    $origin['attribution'] = '';
    $origin['license'] = '';
    $origin['metadata_version'] = RECIPE_COOKIDOO_METADATA_VERSION;
    $origin['metadata_schema_version'] =
        RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION;
    $origin['availability'] = 'available';
    return $origin;
}

function recipeCookidooOntologySourceIdentityHash(array $rows): string {
    $hash = hash_init('sha256');
    foreach (array_slice($rows, 0, 201) as $row) {
        hash_update(
            $hash,
            recipeCatalogJsonEncode([
                'position' => (int)($row['position'] ?? 0),
                'source_text' => trim((string)($row['name'] ?? '')),
                'normalized_name' => trim(
                    (string)($row['normalized_name'] ?? '')
                ),
                'source_optional' =>
                    ($row['source_optional'] ?? null) !== null
                        ? (int)$row['source_optional']
                        : null,
                'source_ingredient_ref' => trim(
                    (string)($row['source_ingredient_ref'] ?? '')
                ),
                'source_default_title' => trim(
                    (string)($row['source_default_title'] ?? '')
                ),
            ]) . "\n"
        );
    }
    return hash_final($hash);
}

function recipeCookidooApplyMetadataV2(
    PDO $db,
    int $recipeId,
    int $originId,
    array $item,
    ?string $retrievedAt = null
): array {
    if ($recipeId <= 0 || $originId <= 0) {
        throw new InvalidArgumentException('metadata refresh identity is invalid');
    }
    $requestedLocale = recipeCookidooNormalizeLocale($item['locale'] ?? null);
    $item = recipeCookidooNormalizeBridgeRecipe(
        $item,
        $requestedLocale,
        true
    );
    $retrievedAt = recipeCookidooNormalizeRetrievedAt($retrievedAt);
    $staleAt = recipeCookidooMetadataStaleAt($retrievedAt);
    $sourceIngredients = [];
    foreach ($item['ingredients'] as $position => $ingredient) {
        $sourceIngredients[] = recipeIngredientNormalizeSourceRow(
            $db,
            $ingredient,
            (int)$position
        );
    }
    $sourceIngredients = recipeIngredientValidateSourceGrouping(
        $sourceIngredients
    );
    $incomingSourceIdentityHash =
        recipeCookidooOntologySourceIdentityHash($sourceIngredients);
    $controllerObservation = null;

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $origin = $db->prepare("
            SELECT o.id, o.recipe_id, o.external_id, o.locale,
                   c.primary_connector, c.deleted_at,
                   CASE
                       WHEN COALESCE(us.hidden, 0) = 0
                        AND (
                            (
                                (
                                    c.cache_expires_at IS NULL
                                    OR c.cache_expires_at >= CURRENT_TIMESTAMP
                                )
                                AND (
                                    c.stale_at IS NULL
                                    OR c.stale_at >= CURRENT_TIMESTAMP
                                )
                            )
                            OR COALESCE(us.favorite, 0) = 1
                        )
                       THEN 1 ELSE 0
                   END AS was_visible,
                   CASE
                       WHEN COALESCE(us.hidden, 0) = 0
                        AND (
                            (
                                (
                                    c.cache_expires_at IS NULL
                                    OR c.cache_expires_at >= CURRENT_TIMESTAMP
                                )
                                AND ? >= CURRENT_TIMESTAMP
                            )
                            OR COALESCE(us.favorite, 0) = 1
                        )
                       THEN 1 ELSE 0
                   END AS will_be_visible
            FROM recipe_origins o
            JOIN recipe_catalog c ON c.id = o.recipe_id
            LEFT JOIN recipe_user_state us ON us.recipe_id = c.id
            WHERE o.id = ? AND o.recipe_id = ? AND o.connector = ?
            LIMIT 1
        ");
        $origin->execute([
            $staleAt,
            $originId,
            $recipeId,
            RECIPE_COOKIDOO_CONNECTOR,
        ]);
        $origin = $origin->fetch(PDO::FETCH_ASSOC);
        if (
            !$origin
            || $origin['deleted_at'] !== null
            || (string)$origin['primary_connector'] !== RECIPE_COOKIDOO_CONNECTOR
            || (string)$origin['external_id'] !== $item['external_id']
            || strtolower(recipeCookidooNormalizeLocale($origin['locale'] ?? null))
                !== strtolower($requestedLocale)
        ) {
            throw new InvalidArgumentException(
                'metadata refresh origin does not match the stored recipe'
            );
        }
        $visibilityChanged = (int)$origin['was_visible']
            !== (int)$origin['will_be_visible'];
        $currentSource = $db->prepare("
            SELECT position, name, normalized_name, source_optional,
                   source_ingredient_ref, source_default_title
            FROM recipe_source_ingredients
            WHERE recipe_id = ?
            ORDER BY position
            LIMIT 201
        ");
        $currentSource->execute([$recipeId]);
        $sourceIdentityChanged = !hash_equals(
            recipeCookidooOntologySourceIdentityHash(
                $currentSource->fetchAll(PDO::FETCH_ASSOC)
            ),
            $incomingSourceIdentityHash
        );
        $scoreCatalogDirtyRequired = false;

        $general = $item['general'];
        $update = $db->prepare("
            UPDATE recipe_catalog SET
                yield_quantity = ?,
                yield_unit = ?,
                prep_time_seconds = ?,
                cook_time_seconds = ?,
                active_time_seconds = ?,
                inactive_time_seconds = ?,
                total_time_seconds = ?,
                difficulty = ?,
                primary_category = ?,
                devices_json = ?,
                optional_devices_json = ?,
                equipment_json = ?,
                retrieved_at = ?,
                stale_at = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND primary_connector = ?
              AND deleted_at IS NULL
        ");
        $update->execute([
            $general['yield_quantity'],
            $general['yield_unit'],
            $general['prep_time_seconds'],
            $general['cook_time_seconds'],
            $general['active_time_seconds'],
            $general['inactive_time_seconds'],
            $general['total_time_seconds'],
            $general['difficulty'],
            $general['primary_category'],
            recipeCatalogJsonEncode($general['devices']),
            recipeCatalogJsonEncode($general['optional_devices']),
            recipeCatalogJsonEncode($general['equipment']),
            $retrievedAt,
            $staleAt,
            $recipeId,
            RECIPE_COOKIDOO_CONNECTOR,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('metadata refresh catalog update failed');
        }

        $insert = $db->prepare("
            INSERT INTO recipe_source_ingredients (
                recipe_id, position, name, normalized_name, source_quantity,
                source_quantity_max, source_unit, source_amount_text,
                source_group_index, source_group_position,
                source_group_title, source_ingredient_ref,
                source_default_title, source_unit_ref, source_optional,
                source_shopping_category_ref,
                canonical_ingredient_id, taxonomy_node_id, mapping_confidence,
                mapping_source, mapping_version
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(recipe_id, position) DO UPDATE SET
                name = excluded.name,
                normalized_name = excluded.normalized_name,
                source_quantity = excluded.source_quantity,
                source_quantity_max = excluded.source_quantity_max,
                source_unit = excluded.source_unit,
                source_amount_text = excluded.source_amount_text,
                source_group_index = excluded.source_group_index,
                source_group_position = excluded.source_group_position,
                source_group_title = excluded.source_group_title,
                source_ingredient_ref = excluded.source_ingredient_ref,
                source_default_title = excluded.source_default_title,
                source_unit_ref = excluded.source_unit_ref,
                source_optional = excluded.source_optional,
                source_shopping_category_ref =
                    excluded.source_shopping_category_ref,
                canonical_ingredient_id = excluded.canonical_ingredient_id,
                taxonomy_node_id = excluded.taxonomy_node_id,
                mapping_confidence = excluded.mapping_confidence,
                mapping_source = excluded.mapping_source,
                mapping_version = excluded.mapping_version,
                updated_at = CURRENT_TIMESTAMP
        ");
        foreach ($sourceIngredients as $ingredient) {
            $insert->execute([
                $recipeId,
                $ingredient['position'],
                $ingredient['name'],
                $ingredient['normalized_name'],
                $ingredient['source_quantity'],
                $ingredient['source_quantity_max'],
                $ingredient['source_unit'],
                $ingredient['source_amount_text'],
                $ingredient['source_group_index'],
                $ingredient['source_group_position'],
                $ingredient['source_group_title'],
                $ingredient['source_ingredient_ref'],
                $ingredient['source_default_title'],
                $ingredient['source_unit_ref'],
                $ingredient['source_optional'],
                $ingredient['source_shopping_category_ref'],
                $ingredient['canonical_ingredient_id'],
                $ingredient['taxonomy_node_id'],
                $ingredient['mapping_confidence'],
                $ingredient['mapping_source'],
                $ingredient['mapping_version'],
            ]);
        }
        $db->prepare("
            DELETE FROM recipe_source_ingredients
            WHERE recipe_id = ? AND position >= ?
        ")->execute([$recipeId, count($sourceIngredients)]);

        if (function_exists(
            'ingredientOntologyControllerObserveRecipeSafely'
        )) {
            $controllerObservation =
                ingredientOntologyControllerObserveRecipeSafely(
                    $db,
                    $recipeId
                );
        }

        $versionUpdate = $db->prepare("
            UPDATE recipe_origins SET
                metadata_version = ?,
                metadata_schema_version = ?,
                content_language = ?,
                metadata_failure_version = NULL,
                metadata_failure_kind = NULL,
                metadata_failure_at = NULL,
                metadata_failure_count = 0,
                metadata_next_probe_at = NULL,
                metadata_failure_schema_version = NULL,
                last_seen_at = CURRENT_TIMESTAMP
            WHERE id = ? AND recipe_id = ? AND connector = ?
        ");
        $versionUpdate->execute([
            RECIPE_COOKIDOO_METADATA_VERSION,
            RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
            $item['provider_language'],
            $originId,
            $recipeId,
            RECIPE_COOKIDOO_CONNECTOR,
        ]);
        if ($versionUpdate->rowCount() !== 1) {
            throw new RuntimeException('metadata refresh version update failed');
        }
        $languageChange =
            recipeCookidooLanguageAssessmentStore(
            $db,
            $recipeId,
            $item['_language_assessment']
        );
        $visibilityChanged = $visibilityChanged
            || !empty($languageChange['visibility_changed']);
        if ($visibilityChanged && $ownsTransaction) {
            recipeScoreInvalidateCursors($db);
        }
        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    if (
        !empty($controllerObservation['observed'])
        && function_exists('ingredientOntologyControllerWake')
    ) {
        ingredientOntologyControllerWake();
    }
    return [
        'recipe_id' => $recipeId,
        'origin_id' => $originId,
        'external_id' => $item['external_id'],
        'metadata_version' => RECIPE_COOKIDOO_METADATA_VERSION,
        'metadata_schema_version' =>
            RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
        'mapping_version' => recipeIngredientActiveMappingVersion(),
        'source_ingredient_count' => count($sourceIngredients),
        'source_group_count' => $sourceIngredients
            ? 1 + max(array_column($sourceIngredients, 'source_group_index'))
            : 0,
        'retrieved_at' => $retrievedAt,
        'stale_at' => $staleAt,
        'visibility_changed' => $visibilityChanged,
        'ontology_source_changed' => $sourceIdentityChanged,
        'score_catalog_dirty_required' => $scoreCatalogDirtyRequired,
        'ontology_observation' => is_array($controllerObservation)
            ? [
                'observed' =>
                    !empty($controllerObservation['observed']),
                'disabled' =>
                    !empty($controllerObservation['disabled']),
                'degraded' =>
                    !empty($controllerObservation['degraded']),
                'occurrence_count' => (int)(
                    $controllerObservation['occurrence_count'] ?? 0
                ),
                'created_subject_count' => (int)(
                    $controllerObservation['created_subject_count'] ?? 0
                ),
                'queued_job_count' => (int)(
                    $controllerObservation['queued_job_count'] ?? 0
                ),
            ]
            : null,
    ];
}

function recipeCookidooRecordMetadataFailure(
    PDO $db,
    int $recipeId,
    int $originId,
    string $externalId,
    string $locale,
    string $errorKind
): array {
    if (
        $recipeId <= 0
        || $originId <= 0
        || !in_array(
            $errorKind,
            RECIPE_COOKIDOO_METADATA_FAILURE_KINDS,
            true
        )
    ) {
        throw new InvalidArgumentException(
            'metadata refresh failure is invalid'
        );
    }
    $externalId = recipeCookidooCleanText(
        $externalId,
        'external_id',
        160,
        true
    );
    $locale = recipeCookidooNormalizeLocale($locale);
    $current = $db->prepare("
        SELECT metadata_failure_count
        FROM recipe_origins
        WHERE id = ?
          AND recipe_id = ?
          AND connector = ?
          AND external_id = ?
          AND lower(locale) = lower(?)
          AND (
              metadata_version IS NULL
              OR metadata_version <> ?
              OR metadata_schema_version IS NULL
              OR metadata_schema_version <> ?
          )
        LIMIT 1
    ");
    $current->execute([
        $originId,
        $recipeId,
        RECIPE_COOKIDOO_CONNECTOR,
        $externalId,
        $locale,
        RECIPE_COOKIDOO_METADATA_VERSION,
        RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
    ]);
    $existingCount = $current->fetchColumn();
    if ($existingCount === false) {
        throw new RuntimeException(
            'metadata refresh failure record was not applied'
        );
    }
    $failureCount = min(255, max(0, (int)$existingCount) + 1);
    $nextProbeAt = recipeCookidooMetadataFailureNextProbeAt(
        $errorKind,
        $failureCount
    );
    $stmt = $db->prepare("
        UPDATE recipe_origins SET
            metadata_failure_version = ?,
            metadata_failure_kind = ?,
            metadata_failure_at = CURRENT_TIMESTAMP,
            metadata_failure_count = ?,
            metadata_next_probe_at = ?,
            metadata_failure_schema_version = ?
        WHERE id = ?
          AND recipe_id = ?
          AND connector = ?
          AND external_id = ?
          AND lower(locale) = lower(?)
          AND (
              metadata_version IS NULL
              OR metadata_version <> ?
              OR metadata_schema_version IS NULL
              OR metadata_schema_version <> ?
          )
    ");
    $stmt->execute([
        RECIPE_COOKIDOO_METADATA_VERSION,
        $errorKind,
        $failureCount,
        $nextProbeAt,
        RECIPE_COOKIDOO_METADATA_FAILURE_SCHEMA_VERSION,
        $originId,
        $recipeId,
        RECIPE_COOKIDOO_CONNECTOR,
        $externalId,
        $locale,
        RECIPE_COOKIDOO_METADATA_VERSION,
        RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'metadata refresh failure record was not applied'
        );
    }
    return [
        'recipe_id' => $recipeId,
        'origin_id' => $originId,
        'external_id' => $externalId,
        'error_kind' => $errorKind,
        'failure_count' => $failureCount,
        'next_probe_at' => $nextProbeAt,
        'failure_schema_version' =>
            RECIPE_COOKIDOO_METADATA_FAILURE_SCHEMA_VERSION,
    ];
}

function recipeCookidooBridgeEndpointFor(string $path): string {
    $base = recipeCookidooBridgeUrl();
    $parts = parse_url($base);
    if (
        $base === ''
        || $parts === false
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
    ) {
        throw new RuntimeException('Cookidoo bridge URL is invalid');
    }
    if (!preg_match('#^/v1/[a-z0-9_-]+$#', $path)) {
        throw new RuntimeException('Cookidoo bridge endpoint is invalid');
    }
    return $base . $path;
}

function recipeCookidooBridgeEndpoint(): string {
    return recipeCookidooBridgeEndpointFor('/v1/search');
}

function recipeCookidooMetadataBridgeEndpoint(): string {
    return recipeCookidooBridgeEndpointFor('/v1/metadata');
}

function recipeCookidooCurlPost(
    string $url,
    string $token,
    array $payload,
    int $timeoutSeconds
): array {
    if (!extension_loaded('curl')) {
        throw new RuntimeException('cURL is required for the Cookidoo bridge');
    }
    $body = recipeCatalogJsonEncode($payload);
    $responseBody = '';
    $responseTooLarge = false;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Cookidoo bridge request could not be initialized');
    }
    $options = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutSeconds * 1000),
        CURLOPT_TIMEOUT_MS => $timeoutSeconds * 1000,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
        CURLOPT_USERAGENT => 'EverShelf-Cookidoo-Connector/1',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (
            &$responseBody,
            &$responseTooLarge
        ): int {
            if (
                strlen($responseBody) + strlen($chunk)
                > RECIPE_COOKIDOO_MAX_RESPONSE_BYTES
            ) {
                $responseTooLarge = true;
                return 0;
            }
            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    curl_setopt_array($ch, $options);
    try {
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
    } finally {
        curl_close($ch);
    }
    if ($responseTooLarge) {
        throw new RuntimeException('Cookidoo bridge response is too large');
    }
    if ($ok === false || $errno !== 0) {
        throw new RuntimeException('Cookidoo bridge request failed (cURL ' . $errno . ')');
    }
    return ['status' => $status, 'body' => $responseBody];
}

function recipeCookidooTestTransport(): ?callable {
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && isset($GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'])
        && is_callable($GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'])
    ) {
        return $GLOBALS['RECIPE_COOKIDOO_BRIDGE_TRANSPORT'];
    }
    return null;
}

function recipeCookidooBridgeJsonPostObserved(
    string $url,
    array $payload
): array {
    $token = trim(recipeCookidooConfigValue('COOKIDOO_BRIDGE_TOKEN', ''));
    if ($token === '') {
        throw new RuntimeException('Cookidoo bridge token is not configured');
    }
    $timeout = recipeCookidooBridgeTimeoutSeconds();
    $transport = recipeCookidooTestTransport();
    $startedAt = hrtime(true);
    $response = $transport !== null
        ? $transport($url, $token, $payload, $timeout)
        : recipeCookidooCurlPost($url, $token, $payload, $timeout);
    if (
        !is_array($response)
        || !isset($response['status'])
        || !array_key_exists('body', $response)
        || !is_string($response['body'])
    ) {
        throw new RuntimeException('Cookidoo bridge transport returned an invalid response');
    }
    if (strlen($response['body']) > RECIPE_COOKIDOO_MAX_RESPONSE_BYTES) {
        throw new RuntimeException('Cookidoo bridge response is too large');
    }
    $status = (int)$response['status'];
    if ($status === 401) {
        throw new RuntimeException('Cookidoo bridge authentication failed');
    }
    if ($status === 403) {
        throw new RecipeCookidooCircuitBreakException(
            'Cookidoo metadata pilot circuit break: forbidden'
        );
    }
    if ($status === 429) {
        throw new RecipeCookidooCircuitBreakException(
            'Cookidoo metadata pilot circuit break: rate limited'
        );
    }
    if ($status !== 200) {
        $safeError = json_decode($response['body'], true);
        if (
            is_array($safeError)
            && in_array(
                (string)($safeError['error'] ?? ''),
                [
                    'cookidoo_auth_failed',
                    'cookidoo_upstream_forbidden',
                    'cookidoo_upstream_rate_limited',
                ],
                true
            )
        ) {
            throw new RecipeCookidooCircuitBreakException(
                'Cookidoo metadata pilot circuit break: upstream challenge'
            );
        }
        throw new RuntimeException('Cookidoo bridge returned HTTP ' . $status);
    }
    try {
        $decoded = json_decode($response['body'], true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Cookidoo bridge returned invalid JSON', 0, $e);
    }
    return [
        'payload' => $decoded,
        'response_bytes' => strlen($response['body']),
        'latency_ms' => max(
            0,
            (int)round((hrtime(true) - $startedAt) / 1000000)
        ),
    ];
}

function recipeCookidooBridgeJsonPost(string $url, array $payload): mixed {
    return recipeCookidooBridgeJsonPostObserved($url, $payload)['payload'];
}

function recipeCookidooBridgeSearch(array $request): array {
    if (!recipeCookidooDetailHydrationPolicyAllows()) {
        throw new RuntimeException(RECIPE_COOKIDOO_DETAIL_POLICY_REASON);
    }
    $request = recipeCookidooNormalizeDiscoveryInput($request);
    $bridgePayload = [
        'query' => $request['query'],
        'ingredients' => $request['ingredients'],
        'exclude_ingredients' => $request['exclude_ingredients'],
        'locale' => $request['locale'],
        'languages' => ['en'],
        'tmv' => $request['tmv'],
        'limit' => $request['limit'],
        'page' => $request['page'],
        'exclude_ids' => $request['exclude_ids'],
        'max_pages' => $request['max_pages'],
    ];
    if ($bridgePayload['locale'] === '') {
        unset($bridgePayload['locale']);
    }
    return recipeCookidooNormalizeBridgeResponse(
        recipeCookidooBridgeJsonPost(
            recipeCookidooBridgeEndpoint(),
            $bridgePayload
        ),
        $request
    );
}

function recipeCookidooBridgeMetadataBatch(array $request): array {
    if (!recipeCookidooDetailHydrationPolicyAllows()) {
        throw new RuntimeException(RECIPE_COOKIDOO_DETAIL_POLICY_REASON);
    }
    $request = recipeCookidooNormalizeMetadataRefreshInput($request);
    $payload = [
        'locale' => $request['locale'],
        'external_ids' => array_column($request['recipes'], 'external_id'),
    ];
    $observed = recipeCookidooBridgeJsonPostObserved(
        recipeCookidooMetadataBridgeEndpoint(),
        $payload
    );
    return recipeCookidooNormalizeMetadataBridgeResponse(
        $observed['payload'],
        $request
    ) + [
        'response_bytes' => (int)$observed['response_bytes'],
        'latency_ms' => (int)$observed['latency_ms'],
    ];
}

function recipeCookidooEnqueueNextCrawlPage(
    PDO $db,
    array $request,
    array $progress,
    bool $refreshChain
): array {
    if (empty($request['crawl_all'])) {
        return [
            'crawl_complete' => false,
            'next_page_enqueued' => false,
            'stop_reason' => 'single_request',
        ];
    }
    if (empty($progress['last_page_had_raw_hits'])) {
        return [
            'crawl_complete' => true,
            'next_page_enqueued' => false,
            'stop_reason' => 'empty_page',
        ];
    }
    $nextPage = (int)$progress['next_page'];
    if ($nextPage > 50) {
        return [
            'crawl_complete' => true,
            'next_page_enqueued' => false,
            'stop_reason' => 'page_limit',
        ];
    }

    $nextRequest = $request;
    $nextRequest['page'] = $nextPage;
    $nextRequest = recipeCookidooNormalizeDiscoveryInput($nextRequest);
    $key = recipeCookidooDiscoveryIdempotencyKey($nextRequest);
    $existing = recipeJobGet($db, null, $key);
    if ($existing !== null) {
        if (!recipeCookidooDiscoveryJobIsCurrent(
            $existing,
            $nextRequest
        )) {
            $enqueue = recipeCookidooEnqueueDiscoveryJob(
                $db,
                $nextRequest,
                true,
                $refreshChain,
                (int)$existing['max_attempts']
            );
            return [
                'crawl_complete' => false,
                'next_page_enqueued' => true,
                'next_page_requeued' => true,
                'next_page_migrated' => true,
                'next_job_id' => (int)$enqueue['job']['id'],
                'next_job_status' =>
                    (string)$enqueue['job']['status'],
                'stop_reason' => null,
            ];
        }
        $status = (string)$existing['status'];
        if (
            $refreshChain
            && in_array($status, ['done', 'skipped', 'failed'], true)
        ) {
            $enqueue = recipeCookidooEnqueueDiscoveryJob(
                $db,
                $nextRequest,
                true,
                true,
                (int)$existing['max_attempts']
            );
            return [
                'crawl_complete' => false,
                'next_page_enqueued' => true,
                'next_page_requeued' => true,
                'next_job_id' => (int)$enqueue['job']['id'],
                'next_job_status' => (string)$enqueue['job']['status'],
                'stop_reason' => null,
            ];
        }
        return [
            'crawl_complete' => false,
            'next_page_enqueued' => false,
            'next_page_requeued' => false,
            'next_job_id' => (int)$existing['id'],
            'next_job_status' => $status,
            'stop_reason' => 'next_page_exists',
        ];
    }

    $enqueue = recipeCookidooEnqueueDiscoveryJob(
        $db,
        $nextRequest,
        false,
        $refreshChain
    );
    return [
        'crawl_complete' => false,
        'next_page_enqueued' => (bool)$enqueue['created'],
        'next_page_requeued' => false,
        'next_job_id' => (int)$enqueue['job']['id'],
        'next_job_status' => (string)$enqueue['job']['status'],
        'stop_reason' => $enqueue['created'] ? null : 'next_page_exists',
    ];
}

function recipeCookidooDiscoveryCatalogLock(PDO $db): mixed {
    if ($db->inTransaction()) {
        throw new RuntimeException(
            'Cookidoo discovery must acquire the catalog lock before a write transaction'
        );
    }
    return recipeCatalogSaveLock();
}

function recipeCookidooDispatchDiscovery(PDO $db, array $job, array $payload): array {
    if (!recipeCookidooDetailHydrationPolicyAllows()) {
        return [
            'status' => 'skipped',
            'result' => [
                'reason' => RECIPE_COOKIDOO_DETAIL_POLICY_REASON,
                'policy_version' =>
                    RECIPE_COOKIDOO_DETAIL_POLICY_VERSION,
            ],
        ];
    }
    $request = recipeCookidooNormalizeDiscoveryInput($payload);
    $job = recipeCookidooMigrateDiscoveryJob(
        $db,
        $job,
        $request
    );
    $refreshChain = !empty($payload[RECIPE_COOKIDOO_CRAWL_REFRESH_FIELD])
        && !empty($request['crawl_all']);
    $bridgeRequest = $request;
    unset(
        $bridgeRequest['interactive'],
        $bridgeRequest['force'],
        $bridgeRequest['include_local_results']
    );
    if ($refreshChain) {
        $bridgeRequest['exclude_cached'] = false;
        $bridgeRequest['exclude_ids'] = [];
    }
    if (!empty($bridgeRequest['exclude_cached'])) {
        $cachedIds = $db->query("
            SELECT external_id
            FROM recipe_origins
            WHERE connector = 'cookidoo'
              AND external_id IS NOT NULL
              AND TRIM(external_id) <> ''
            ORDER BY id DESC
        ")->fetchAll(PDO::FETCH_COLUMN);
        $bridgeRequest['exclude_ids'] = array_values(array_unique(array_merge(
            $bridgeRequest['exclude_ids'],
            array_map('strval', $cachedIds)
        )));
    }
    $bridgeResult = recipeCookidooBridgeSearch($bridgeRequest);
    $recipes = $bridgeResult['recipes'];
    $retrievedAt = gmdate('Y-m-d H:i:s');
    $importedIds = [];
    $updatedIds = [];
    $cursorInvalidationRequired = false;
    $existing = $db->prepare("
        SELECT o.id AS origin_id, o.recipe_id, c.deleted_at
        FROM recipe_origins o
        JOIN recipe_catalog c ON c.id = o.recipe_id
        WHERE o.connector = ? AND o.external_id = ?
        LIMIT 1
    ");
    $saveLock = recipeCookidooDiscoveryCatalogLock($db);
    try {
        $db->beginTransaction();
        foreach ($recipes as $item) {
            $existing->execute([RECIPE_COOKIDOO_CONNECTOR, $item['external_id']]);
            $existingOrigin = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($existingOrigin !== null && $existingOrigin['deleted_at'] !== null) {
                continue;
            }
            $existingRecipeId = (int)($existingOrigin['recipe_id'] ?? 0);
            if ($existingRecipeId > 0) {
                $applied = recipeCookidooApplyMetadataV2(
                    $db,
                    $existingRecipeId,
                    (int)$existingOrigin['origin_id'],
                    $item,
                    $retrievedAt
                );
                $cursorInvalidationRequired = $cursorInvalidationRequired
                    || !empty($applied['visibility_changed']);
                $updatedIds[] = $existingRecipeId;
            } else {
                $saved = recipeCatalogSaveVariant($db, [
                    'title' => $item['title'],
                    'image_url' => $item['image_url'],
                    'general' => $item['general'],
                    'source_ingredients' => $item['ingredients'],
                ], [
                    'connector' => RECIPE_COOKIDOO_CONNECTOR,
                    'external_id' => $item['external_id'],
                    'canonical_url' => $item['canonical_url'],
                    'locale' => $item['locale'],
                    'content_language' =>
                        $item['provider_language'],
                    'language' =>
                        $item['_language_assessment']['verdict']
                            === 'english'
                            ? 'en'
                            : 'und',
                    'storage_policy' => RECIPE_COOKIDOO_STORAGE_POLICY,
                    'rights_basis' => RECIPE_COOKIDOO_RIGHTS_BASIS,
                    'retrieved_at' => $retrievedAt,
                    'metadata_version' => RECIPE_COOKIDOO_METADATA_VERSION,
                    'metadata_schema_version' =>
                        RECIPE_COOKIDOO_METADATA_SCHEMA_VERSION,
                    'store_source_payload' => false,
                ]);
                $savedId = (int)$saved['id'];
                recipeCookidooLanguageAssessmentStore(
                    $db,
                    $savedId,
                    $item['_language_assessment']
                );
                $importedIds[] = $savedId;
            }
        }
        if ($cursorInvalidationRequired) {
            recipeScoreInvalidateCursors($db);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    } finally {
        recipeCatalogSaveUnlock($saveLock);
    }
    $crawl = recipeCookidooEnqueueNextCrawlPage(
        $db,
        $request,
        $bridgeResult,
        $refreshChain
    );
    return [
        'status' => 'done',
        'result' => [
            'search_id' => recipeCookidooSearchId($request),
            'imported_ids' => array_values(array_unique($importedIds)),
            'updated_ids' => array_values(array_unique($updatedIds)),
            'crawl_all' => !empty($request['crawl_all']),
            'page' => (int)$request['page'],
            'pages_scanned' => (int)$bridgeResult['pages_scanned'],
            'last_page' => (int)$bridgeResult['last_page'],
            'next_page' => (int)$bridgeResult['next_page'],
            'last_page_had_raw_hits' => (bool)$bridgeResult['last_page_had_raw_hits'],
            'language_rejected_count' =>
                (int)($bridgeResult['language_rejected_count'] ?? 0),
            'crawl_refresh' => $refreshChain,
        ] + $crawl,
    ];
}

function recipeCookidooQueueCadenceDue(?int $timestamp = null): bool {
    $timestamp ??= time();
    $minutes = recipeCookidooQueueCadenceMinutes();
    return intdiv($timestamp, 60) % $minutes === 0;
}
