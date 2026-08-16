<?php
/**
 * Category-refinement cache and Gemini response validation.
 */

require_once __DIR__ . '/constants.php';

function evershelfCategoryRefinementCategories(): array {
    static $categories = [
        'latticini', 'carne', 'pesce', 'frutta', 'verdura', 'pasta', 'pane',
        'surgelati', 'bevande', 'condimenti', 'snack', 'conserve', 'cereali',
        'igiene', 'pulizia', 'altro',
    ];
    return $categories;
}

function evershelfCategoryRefinementCacheKey(string $name): string {
    return CATEGORY_REFINEMENT_CACHE_NAMESPACE
        . md5(mb_strtolower(trim($name)));
}

function evershelfCategoryRefinementResponseObject(
    array $result
): ?stdClass {
    $raw = $result['body'] ?? null;
    if (!is_string($raw)) {
        if (!array_key_exists('data', $result)) {
            return null;
        }
        $raw = json_encode($result['data']);
        if (!is_string($raw)) {
            return null;
        }
    }
    if (trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw);
    return json_last_error() === JSON_ERROR_NONE
        && $decoded instanceof stdClass
        ? $decoded
        : null;
}

function evershelfParseCategoryRefinementResult(array $result): ?string {
    if ((int)($result['http_code'] ?? 0) !== 200) {
        return null;
    }

    $data = evershelfCategoryRefinementResponseObject($result);
    if (!$data instanceof stdClass) {
        return null;
    }

    if (property_exists($data, 'promptFeedback')) {
        $promptFeedback = $data->promptFeedback;
        if (!$promptFeedback instanceof stdClass) {
            return null;
        }
        $blockReason = property_exists($promptFeedback, 'blockReason')
            ? $promptFeedback->blockReason
            : '';
        if (
            !is_string($blockReason)
            || (
                $blockReason !== ''
                && $blockReason !== 'BLOCK_REASON_UNSPECIFIED'
            )
        ) {
            return null;
        }
        $promptSafety = evershelfCategoryRefinementSafetyState(
            $promptFeedback
        );
        if ($promptSafety !== false) {
            return null;
        }
    }

    $candidates = $data->candidates ?? null;
    if (
        !is_array($candidates)
        || !array_is_list($candidates)
        || count($candidates) !== 1
        || !$candidates[0] instanceof stdClass
    ) {
        return null;
    }
    $candidate = $candidates[0];
    if (($candidate->finishReason ?? null) !== 'STOP') {
        return null;
    }
    $candidateSafety = evershelfCategoryRefinementSafetyState($candidate);
    if ($candidateSafety !== false) {
        return null;
    }

    $content = $candidate->content ?? null;
    if (!$content instanceof stdClass) {
        return null;
    }
    $parts = $content->parts ?? null;
    if (
        !is_array($parts)
        || !array_is_list($parts)
        || $parts === []
    ) {
        return null;
    }

    $raw = '';
    $visibleParts = 0;
    foreach ($parts as $part) {
        if (!$part instanceof stdClass) {
            return null;
        }
        foreach (array_keys(get_object_vars($part)) as $field) {
            if (!in_array(
                $field,
                ['text', 'thought', 'thoughtSignature'],
                true
            )) {
                return null;
            }
        }
        $thought = $part->thought ?? false;
        if (!is_bool($thought)) {
            return null;
        }
        if (
            property_exists($part, 'thoughtSignature')
            && !is_string($part->thoughtSignature)
        ) {
            return null;
        }
        if ($thought) {
            continue;
        }
        $text = $part->text ?? null;
        if (!is_string($text)) {
            return null;
        }
        $raw .= $text;
        $visibleParts++;
    }
    if ($visibleParts === 0) {
        return null;
    }

    $normalized = strtolower(trim($raw));
    return in_array(
        $normalized,
        evershelfCategoryRefinementCategories(),
        true
    ) ? $normalized : null;
}

/**
 * Return true for an explicit block, false for safe/absent ratings, and null
 * for malformed safety metadata.
 */
function evershelfCategoryRefinementSafetyState(
    stdClass $container
): ?bool {
    if (!property_exists($container, 'safetyRatings')) {
        return false;
    }
    $ratings = $container->safetyRatings;
    if (!is_array($ratings) || !array_is_list($ratings)) {
        return null;
    }
    foreach ($ratings as $rating) {
        if (!$rating instanceof stdClass) {
            return null;
        }
        $recognized = false;
        foreach ([
            'category',
            'probability',
            'severity',
        ] as $field) {
            if (!property_exists($rating, $field)) {
                continue;
            }
            if (
                !is_string($rating->{$field})
                || trim($rating->{$field}) === ''
            ) {
                return null;
            }
            $recognized = true;
        }
        foreach (['probabilityScore', 'severityScore'] as $field) {
            if (!property_exists($rating, $field)) {
                continue;
            }
            if (!is_int($rating->{$field})
                && !is_float($rating->{$field})) {
                return null;
            }
            $recognized = true;
        }
        if (property_exists($rating, 'blocked')) {
            if (!is_bool($rating->blocked)) {
                return null;
            }
            $recognized = true;
            if ($rating->blocked) {
                return true;
            }
        }
        if (!$recognized) {
            return null;
        }
    }
    return false;
}

function evershelfLoadCategoryRefinementCache(string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function evershelfStoreCategoryRefinementEntry(
    string $path,
    string $key,
    string $category
): bool {
    if (!in_array(
        $category,
        evershelfCategoryRefinementCategories(),
        true
    )) {
        return false;
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0770, true)) {
        return false;
    }
    $lock = @fopen($path . '.lock', 'c+');
    if (!is_resource($lock)) {
        return false;
    }

    $temporaryPath = '';
    try {
        if (!flock($lock, LOCK_EX)) {
            return false;
        }
        $cache = evershelfLoadCategoryRefinementCache($path);
        $cache[$key] = $category;
        ksort($cache, SORT_STRING);
        $encoded = json_encode(
            $cache,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
        if (!is_string($encoded)) {
            return false;
        }
        $temporaryPath = tempnam(
            $directory,
            basename($path) . '.write.'
        );
        if ($temporaryPath === false) {
            $temporaryPath = '';
            return false;
        }
        if (@file_put_contents(
            $temporaryPath,
            $encoded . "\n",
            LOCK_EX
        ) === false) {
            return false;
        }
        $mode = is_file($path)
            ? (fileperms($path) & 0777)
            : 0660;
        @chmod($temporaryPath, $mode);
        if (!@rename($temporaryPath, $path)) {
            return false;
        }
        $temporaryPath = '';
        return true;
    } finally {
        if ($temporaryPath !== '' && is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * @return array{category:?string,cached:bool}
 */
function evershelfApplyCategoryRefinementResult(
    string $path,
    string $key,
    array $result
): array {
    $category = evershelfParseCategoryRefinementResult($result);
    if ($category === null) {
        return ['category' => null, 'cached' => false];
    }
    return [
        'category' => $category,
        'cached' => evershelfStoreCategoryRefinementEntry(
            $path,
            $key,
            $category
        ),
    ];
}
