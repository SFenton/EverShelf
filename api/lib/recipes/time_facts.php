<?php
declare(strict_types=1);

const RECIPE_TIME_MAX_SOURCE_LENGTH = 80;
const RECIPE_TIME_MODEL_PROMPT_VERSION = 'recipe-time-model-prompt-v1';
const RECIPE_TIME_MODEL_SCHEMA_VERSION = 'recipe_time_proposal_v1';

function recipeTimeBoundedSourceText(mixed $value): ?string {
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        return null;
    }
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (
        $value === ''
        || mb_strlen($value, 'UTF-8') > RECIPE_TIME_MAX_SOURCE_LENGTH
        || preg_match('/[\x00-\x1F\x7F]/u', $value)
    ) {
        return null;
    }
    return $value;
}

function recipeTimeBoundedSeconds(int $seconds): ?int {
    return $seconds >= 0 && $seconds <= RECIPE_MAX_FACTUAL_DURATION_SECONDS
        ? $seconds
        : null;
}

function recipeTimeParseIso8601Duration(string $value): ?int {
    $value = strtoupper($value);
    if (preg_match('/^P(\d+)W$/D', $value, $matches)) {
        if (strlen($matches[1]) > 8) {
            return null;
        }
        return recipeTimeBoundedSeconds((int)$matches[1] * 7 * 86400);
    }
    if (!preg_match(
        '/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/D',
        $value,
        $matches
    )) {
        return null;
    }
    $components = array_slice(array_pad($matches, 5, ''), 1, 4);
    if (!array_filter($components, static fn(string $part): bool => $part !== '')) {
        return null;
    }
    if (
        str_contains($value, 'T')
        && !array_filter(
            array_slice($components, 1),
            static fn(string $part): bool => $part !== ''
        )
    ) {
        return null;
    }
    if (array_filter(
        $components,
        static fn(string $part): bool => strlen($part) > 8
    )) {
        return null;
    }
    $seconds = ((int)$components[0] * 86400)
        + ((int)$components[1] * 3600)
        + ((int)$components[2] * 60)
        + (int)$components[3];
    return recipeTimeBoundedSeconds($seconds);
}

function recipeTimeUnitSeconds(): array {
    return [
        's' => 1,
        'sec' => 1,
        'secs' => 1,
        'second' => 1,
        'seconds' => 1,
        'secondo' => 1,
        'secondi' => 1,
        'sek' => 1,
        'sekunde' => 1,
        'sekunden' => 1,
        'm' => 60,
        'min' => 60,
        'mins' => 60,
        'minute' => 60,
        'minutes' => 60,
        'minuto' => 60,
        'minuti' => 60,
        'minuten' => 60,
        'h' => 3600,
        'hr' => 3600,
        'hrs' => 3600,
        'hour' => 3600,
        'hours' => 3600,
        'ora' => 3600,
        'ore' => 3600,
        'std' => 3600,
        'stunde' => 3600,
        'stunden' => 3600,
        'd' => 86400,
        'day' => 86400,
        'days' => 86400,
        'giorno' => 86400,
        'giorni' => 86400,
        'tag' => 86400,
        'tage' => 86400,
    ];
}

function recipeTimeParseLocalizedDuration(string $value): ?int {
    $normalized = mb_strtolower($value, 'UTF-8');
    $normalized = preg_replace(
        '/\b(?:and|e|und)\b/u',
        ' ',
        $normalized
    ) ?? '';
    $normalized = trim(preg_replace('/[\s,]+/u', ' ', $normalized) ?? '');
    if (
        $normalized === ''
        || !preg_match_all(
            '/(\d+)\s*([\p{L}]+\.?)/u',
            $normalized,
            $matches,
            PREG_SET_ORDER
        )
        || count($matches) > 3
    ) {
        return null;
    }
    $matched = '';
    $seconds = 0;
    $seenUnits = [];
    $units = recipeTimeUnitSeconds();
    foreach ($matches as $match) {
        $unit = rtrim($match[2], '.');
        if (
            strlen($match[1]) > 8
            || !isset($units[$unit])
            || isset($seenUnits[$units[$unit]])
        ) {
            return null;
        }
        $seenUnits[$units[$unit]] = true;
        $matched .= $match[1] . $match[2];
        $seconds += (int)$match[1] * $units[$unit];
        if ($seconds > RECIPE_MAX_FACTUAL_DURATION_SECONDS) {
            return null;
        }
    }
    if (
        preg_replace('/\s+/u', '', $normalized)
        !== preg_replace('/\s+/u', '', $matched)
    ) {
        return null;
    }
    return recipeTimeBoundedSeconds($seconds);
}

function recipeTimeParseDurationSeconds(
    mixed $value,
    string $locale = 'und'
): ?int {
    $text = recipeTimeBoundedSourceText($value);
    if ($text === null) {
        return null;
    }
    recipeQuantityNormalizeLocale($locale);
    return recipeTimeParseIso8601Duration($text)
        ?? recipeTimeParseLocalizedDuration($text);
}

function recipeTimeDeriveInactiveSeconds(
    ?int $activeSeconds,
    ?int $totalSeconds,
    ?int $prepSeconds,
    ?int $cookSeconds,
    ?int $inactiveSeconds
): ?int {
    if ($inactiveSeconds !== null) {
        return $inactiveSeconds;
    }
    if (
        $prepSeconds !== null
        || $cookSeconds !== null
        || $activeSeconds === null
        || $totalSeconds === null
    ) {
        return null;
    }
    return max($totalSeconds - $activeSeconds, 0);
}

function recipeTimeBuildModelProposal(
    string $field,
    mixed $value,
    string $locale = 'und',
    string $source = 'manual',
    array $options = []
): array {
    if (!in_array($field, ['prep_time', 'cook_time'], true)) {
        throw new InvalidArgumentException(
            'time model proposals accept only prep_time or cook_time'
        );
    }
    $source = strtolower(trim($source));
    if (
        $source === ''
        || $source === 'cookidoo'
        || strlen($source) > 40
        || !preg_match('/^[a-z][a-z0-9_-]*$/D', $source)
    ) {
        throw new InvalidArgumentException(
            'time model proposals require a non-Cookidoo source'
        );
    }
    $text = recipeTimeBoundedSourceText($value);
    if ($text === null) {
        throw new InvalidArgumentException(
            'time model proposal source text is invalid'
        );
    }
    $locale = recipeQuantityNormalizeLocale($locale);
    if (recipeTimeParseDurationSeconds($text, $locale) !== null) {
        throw new InvalidArgumentException(
            'time model proposals accept only unresolved deterministic parses'
        );
    }
    $model = trim((string)($options['model'] ?? 'unconfigured'));
    if (
        $model === ''
        || strlen($model) > 100
        || preg_match('/[\x00-\x1F\x7F]/', $model)
    ) {
        throw new InvalidArgumentException('time proposal model is invalid');
    }
    $input = [
        'source_connector' => $source,
        'locale' => $locale,
        'field' => $field,
        'text' => $text,
        'prompt_version' => RECIPE_TIME_MODEL_PROMPT_VERSION,
    ];
    $inputJson = json_encode(
        $input,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($inputJson === false) {
        throw new RuntimeException('time proposal input could not be encoded');
    }
    $inputHash = hash('sha256', $inputJson);
    $prompt = implode("\n", [
        'Propose a review-only duration for one recipe time field.',
        'Return exactly one JSON object and no prose. Do not call tools.',
        'The result is staging-only and cannot update a recipe.',
        'Use only an exact UTF-8 byte span from the supplied field text.',
        'If the duration is not explicit, return status "unparsed".',
        '',
        'OUTPUT SCHEMA:',
        '{"schema_version":"' . RECIPE_TIME_MODEL_SCHEMA_VERSION . '",'
            . '"input_hash":"' . $inputHash . '","result":{'
            . '"status":"proposed|unparsed","seconds":integer|null,'
            . '"evidence_span":{"start":integer,"end":integer,'
            . '"text":"exact source substring"}}}',
        'Use null seconds and a null evidence_span when status is unparsed.',
        '',
        '<untrusted_data>',
        json_encode(
            $input,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG
                | JSON_HEX_AMP
        ),
        '</untrusted_data>',
    ]);
    return [
        'prompt' => $prompt,
        'manifest' => [
            'schema_version' => RECIPE_TIME_MODEL_SCHEMA_VERSION,
            'prompt_version' => RECIPE_TIME_MODEL_PROMPT_VERSION,
            'prompt_hash' => hash('sha256', $prompt),
            'input_hash' => $inputHash,
            'source_connector' => $source,
            'locale' => $locale,
            'field' => $field,
            'text' => $text,
            'model' => $model,
            'staging_only' => true,
        ],
    ];
}
