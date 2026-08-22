<?php
declare(strict_types=1);

function foodIdentityNormalizePossessiveOrthography(
    string $text
): string {
    return preg_replace(
        "~([\p{L}\p{N}])(?:['`\x{2018}\x{2019}\x{02BC}])s\b~iu",
        '$1s',
        $text
    ) ?? $text;
}
