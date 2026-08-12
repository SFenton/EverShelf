<?php
declare(strict_types=1);

const RECIPE_QUANTITY_PARSER_VERSION = 'recipe-quantity-deterministic-v5';
const RECIPE_QUANTITY_STRUCTURED_VERSION = 'cookidoo-quantity-structured-v1';
const RECIPE_QUANTITY_MAX_TEXT_LENGTH = 500;
const RECIPE_QUANTITY_MAX_INGREDIENT_LENGTH = 200;
const RECIPE_QUANTITY_MAX_NOTE_LENGTH = 160;
const RECIPE_QUANTITY_MAX_RESULT_BYTES = 8192;

function recipeQuantityNormalizeLocale(string $locale): string {
    $locale = trim(str_replace('_', '-', $locale));
    if (
        $locale === ''
        || strlen($locale) > 35
        || !preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $locale)
    ) {
        return 'und';
    }
    $parts = explode('-', $locale);
    $parts[0] = strtolower($parts[0]);
    for ($index = 1; $index < count($parts); $index++) {
        $part = $parts[$index];
        if (preg_match('/^[A-Za-z]{4}$/', $part)) {
            $parts[$index] = ucfirst(strtolower($part));
        } elseif (preg_match('/^[A-Za-z]{2}$/', $part)) {
            $parts[$index] = strtoupper($part);
        } else {
            $parts[$index] = strtolower($part);
        }
    }
    return implode('-', $parts);
}

function recipeQuantityResult(
    ?string $sourceText = null,
    ?string $ingredient = null,
    ?string $locale = null
): array {
    if ($ingredient !== null) {
        $ingredient = trim(mb_substr(
            $ingredient,
            0,
            RECIPE_QUANTITY_MAX_INGREDIENT_LENGTH,
            'UTF-8'
        ));
    }
    return [
        'status' => 'unparsed',
        'quantity' => null,
        'quantity_max' => null,
        'unit' => null,
        'unit_raw' => null,
        'ingredient' => $ingredient,
        'package_quantity' => null,
        'package_unit' => null,
        'approximate' => false,
        'qualifier' => null,
        'note' => null,
        'parser_version' => RECIPE_QUANTITY_PARSER_VERSION,
        'provenance' => 'deterministic_grammar',
        'source_text' => $sourceText,
        'locale' => $locale !== null
            ? recipeQuantityNormalizeLocale($locale)
            : null,
        'evidence_spans' => [],
        'ranking_eligible' => false,
    ];
}

function recipeQuantityBoundedText(mixed $value, int $maximum): ?string {
    if (
        !is_string($value)
        || !mb_check_encoding($value, 'UTF-8')
    ) {
        return null;
    }
    $value = trim($value);
    if (
        $value === ''
        || mb_strlen($value, 'UTF-8') > $maximum
        || preg_match('/[\x00-\x1F\x7F]/u', $value)
    ) {
        return null;
    }
    return $value;
}

function recipeQuantityLocaleLanguage(string $locale): string {
    $locale = strtolower(recipeQuantityNormalizeLocale($locale));
    return explode('-', $locale, 2)[0];
}

function recipeQuantityNumberPattern(): string {
    $vulgar = '[¼½¾⅐⅑⅒⅓⅔⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞]';
    $grouped = '\d{1,3}(?:[.,\'’ \x{00A0}\x{202F}]\d{3})+'
        . '(?:[.,]\d+)?';
    return '(?:'
        . '\d+\s+\d+\s*\/\s*\d+'
        . '|\d+\s*' . $vulgar
        . '|\d+\s*\/\s*\d+'
        . '|' . $grouped
        . '|\d+[.,]\d+'
        . '|\d+'
        . '|' . $vulgar
        . ')';
}

function recipeQuantitySeparatorProfile(string $locale): ?array {
    $locale = strtolower(recipeQuantityNormalizeLocale($locale));
    static $profiles = [
        'en-us' => ['decimal' => '.', 'groups' => [',']],
        'en-ca' => ['decimal' => '.', 'groups' => [',']],
        'en-gb' => ['decimal' => '.', 'groups' => [',']],
        'en-au' => ['decimal' => '.', 'groups' => [',']],
        'en-nz' => ['decimal' => '.', 'groups' => [',']],
        'es-mx' => ['decimal' => '.', 'groups' => [',']],
        'es-us' => ['decimal' => '.', 'groups' => [',']],
        'de-de' => [
            'decimal' => ',',
            'groups' => ['.', ' ', "\u{00A0}", "\u{202F}"],
        ],
        'de-at' => [
            'decimal' => ',',
            'groups' => ['.', ' ', "\u{00A0}", "\u{202F}"],
        ],
        'es-es' => [
            'decimal' => ',',
            'groups' => ['.', ' ', "\u{00A0}", "\u{202F}"],
        ],
        'it-it' => [
            'decimal' => ',',
            'groups' => ['.', ' ', "\u{00A0}", "\u{202F}"],
        ],
        'pt-pt' => [
            'decimal' => ',',
            'groups' => ['.', ' ', "\u{00A0}", "\u{202F}"],
        ],
        'pt-br' => [
            'decimal' => ',',
            'groups' => ['.', ' ', "\u{00A0}", "\u{202F}"],
        ],
        'pl-pl' => [
            'decimal' => ',',
            'groups' => ['.', ' ', "\u{00A0}", "\u{202F}"],
        ],
        'fr-fr' => [
            'decimal' => ',',
            'groups' => [' ', "\u{00A0}", "\u{202F}"],
        ],
        'fr-be' => [
            'decimal' => ',',
            'groups' => [' ', "\u{00A0}", "\u{202F}"],
        ],
        'de-ch' => [
            'decimal' => '.',
            'groups' => ["'", '’', ' ', "\u{00A0}", "\u{202F}"],
        ],
        'fr-ch' => [
            'decimal' => ',',
            'groups' => [' ', "\u{00A0}", "\u{202F}"],
        ],
        'it-ch' => [
            'decimal' => '.',
            'groups' => ["'", '’', ' ', "\u{00A0}", "\u{202F}"],
        ],
    ];
    return $profiles[$locale] ?? null;
}

function recipeQuantityVulgarFraction(string $value): ?float {
    return match ($value) {
        '¼' => 1 / 4,
        '½' => 1 / 2,
        '¾' => 3 / 4,
        '⅐' => 1 / 7,
        '⅑' => 1 / 9,
        '⅒' => 1 / 10,
        '⅓' => 1 / 3,
        '⅔' => 2 / 3,
        '⅕' => 1 / 5,
        '⅖' => 2 / 5,
        '⅗' => 3 / 5,
        '⅘' => 4 / 5,
        '⅙' => 1 / 6,
        '⅚' => 5 / 6,
        '⅛' => 1 / 8,
        '⅜' => 3 / 8,
        '⅝' => 5 / 8,
        '⅞' => 7 / 8,
        default => null,
    };
}

function recipeQuantityParseNumberToken(
    string $token,
    string $locale = 'und'
): ?float {
    if (!mb_check_encoding($token, 'UTF-8')) {
        return null;
    }
    $token = preg_replace('/^\s+|\s+$/u', '', $token) ?? trim($token);
    $value = null;
    if (preg_match(
        '/^(\d+)\s+(\d+)\s*\/\s*(\d+)$/u',
        $token,
        $match
    )) {
        $denominator = (int)$match[3];
        if ($denominator > 0) {
            $value = (float)$match[1] + ((float)$match[2] / $denominator);
        }
    } elseif (preg_match(
        '/^(\d+)\s*([¼½¾⅐⅑⅒⅓⅔⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞])$/u',
        $token,
        $match
    )) {
        $fraction = recipeQuantityVulgarFraction($match[2]);
        if ($fraction !== null) {
            $value = (float)$match[1] + $fraction;
        }
    } elseif (preg_match('/^(\d+)\s*\/\s*(\d+)$/u', $token, $match)) {
        $denominator = (int)$match[2];
        if ($denominator > 0) {
            $value = (float)$match[1] / $denominator;
        }
    } else {
        $fraction = recipeQuantityVulgarFraction($token);
        if ($fraction !== null) {
            $value = $fraction;
        } elseif (preg_match(
            '/^\d+(?:[.,\'’ \x{00A0}\x{202F}]\d+)*$/u',
            $token
        )) {
            $profile = recipeQuantitySeparatorProfile($locale);
            if ($profile === null) {
                if (preg_match('/^\d+$/', $token)) {
                    $value = (float)$token;
                } elseif (preg_match(
                    '/^\d+([.,])(\d+)$/',
                    $token,
                    $decimalMatch
                )) {
                    if (strlen($decimalMatch[2]) !== 3) {
                        $value = (float)str_replace(
                            $decimalMatch[1],
                            '.',
                            $token
                        );
                    }
                }
            } else {
                $decimalSeparator = $profile['decimal'];
                $decimalQuoted = preg_quote($decimalSeparator, '/');
                $groupAlternation = implode('|', array_map(
                    static fn(string $separator): string =>
                        preg_quote($separator, '/'),
                    $profile['groups']
                ));
                if (preg_match('/^\d+$/', $token)) {
                    $value = (float)$token;
                } elseif (preg_match(
                    '/^\d{1,3}(?<group>' . $groupAlternation . ')\d{3}'
                        . '(?:\k<group>\d{3})*$/u',
                    $token,
                    $groupMatch
                )) {
                    $value = (float)str_replace(
                        $groupMatch['group'],
                        '',
                        $token
                    );
                } elseif (preg_match(
                    '/^\d+' . $decimalQuoted . '\d+$/',
                    $token
                )) {
                    $value = (float)str_replace(
                        $decimalSeparator,
                        '.',
                        $token
                    );
                } elseif (preg_match(
                    '/^\d{1,3}(?<group>' . $groupAlternation . ')\d{3}'
                        . '(?:\k<group>\d{3})*'
                        . $decimalQuoted . '\d+$/u',
                    $token,
                    $groupMatch
                )) {
                    $normalized = str_replace(
                        $groupMatch['group'],
                        '',
                        $token
                    );
                    $value = (float)str_replace(
                        $decimalSeparator,
                        '.',
                        $normalized
                    );
                }
            }
        }
    }
    if ($value === null || !is_finite($value) || $value <= 0 || $value > 1e9) {
        return null;
    }
    return round($value, 7);
}

function recipeQuantityUnitOntology(): array {
    static $ontology = null;
    if ($ontology !== null) {
        return $ontology;
    }
    $ontology = [
        'mg' => [
            'dimension' => 'mass',
            'aliases' => ['mg', 'milligram', 'milligrams', 'milligramme', 'milligrammes', 'milligrammo', 'milligrammi'],
        ],
        'g' => [
            'dimension' => 'mass',
            'aliases' => ['g', 'gr', 'gram', 'grams', 'gramme', 'grammes', 'gramm', 'grammi', 'grammo', 'gramo', 'gramos'],
        ],
        'kg' => [
            'dimension' => 'mass',
            'aliases' => ['kg', 'kilogram', 'kilograms', 'kilogramme', 'kilogrammes', 'kilogramm', 'chilogrammo', 'chilogrammi', 'kilogramo', 'kilogramos'],
        ],
        'ml' => [
            'dimension' => 'volume',
            'aliases' => ['ml', 'milliliter', 'milliliters', 'millilitre', 'millilitres', 'millilitro', 'millilitri', 'mililitro', 'mililitros'],
        ],
        'cl' => [
            'dimension' => 'volume',
            'aliases' => ['cl', 'centiliter', 'centiliters', 'centilitre', 'centilitres', 'centilitro', 'centilitri'],
        ],
        'dl' => [
            'dimension' => 'volume',
            'aliases' => ['dl', 'deciliter', 'deciliters', 'decilitre', 'decilitres', 'decilitro', 'decilitri'],
        ],
        'l' => [
            'dimension' => 'volume',
            'aliases' => ['l', 'liter', 'liters', 'litre', 'litres', 'litro', 'litri', 'litros'],
        ],
        'tsp' => [
            'dimension' => 'volume',
            'aliases' => [
                'tsp', 'teaspoon', 'teaspoons',
                'tl', 'teelöffel', 'teeloeffel',
                'c. à café', 'c à café', 'cuillère à café', 'cuillere à cafe',
                'cucchiaino', 'cucchiaini',
                'cucharadita', 'cucharaditas',
                'c. chá', 'c chá', 'colher de chá', 'colheres de chá',
                'łyżeczka', 'łyżeczki', 'lyzeczka', 'lyzeczki',
            ],
        ],
        'tbsp' => [
            'dimension' => 'volume',
            'aliases' => [
                'tbsp', 'tablespoon', 'tablespoons',
                'el', 'esslöffel', 'essloeffel',
                'c. à soupe', 'c à soupe', 'cuillère à soupe', 'cuillere à soupe',
                'cucchiaio', 'cucchiai',
                'cucharada', 'cucharadas',
                'c. sopa', 'c sopa', 'colher de sopa', 'colheres de sopa',
                'łyżka', 'łyżki', 'lyzka', 'lyzki',
            ],
        ],
        'cup' => [
            'dimension' => 'volume',
            'aliases' => [
                'cup', 'cups', 'tasse', 'tasses', 'tazza', 'tazze',
                'taza', 'tazas', 'chávena', 'chávenas', 'chavena', 'chavenas',
                'xícara', 'xícaras', 'xicara', 'xicaras',
                'szklanka', 'szklanki',
            ],
        ],
        'oz' => [
            'dimension' => 'mass',
            'aliases' => ['oz', 'ounce', 'ounces', 'oncia', 'once', 'onzas'],
        ],
        'lb' => [
            'dimension' => 'mass',
            'aliases' => ['lb', 'lbs', 'pound', 'pounds', 'libbra', 'libbre', 'livre', 'livres'],
        ],
        'piece' => [
            'dimension' => 'count',
            'aliases' => [
                'piece', 'pieces', 'pc', 'pcs', 'pz', 'pezzo', 'pezzi',
                'stück', 'stücke', 'stuck', 'stucke', 'stk',
                'pièce', 'pièces', 'piece', 'piezas', 'pieza',
                'peça', 'peças', 'peca', 'pecas',
                'sztuka', 'sztuki',
            ],
        ],
        'clove' => [
            'dimension' => 'count',
            'aliases' => [
                'clove', 'cloves', 'zehe', 'zehen', 'gousse', 'gousses',
                'spicchio', 'spicchi', 'diente', 'dientes',
                'ząbek', 'ząbki', 'zabek', 'zabki',
            ],
        ],
        'bunch' => [
            'dimension' => 'count',
            'aliases' => [
                'bunch', 'bunches', 'bund', 'bünde', 'bunde',
                'bouquet', 'bouquets', 'mazzo', 'mazzi',
                'manojo', 'manojos', 'molho', 'molhos',
                'pęczek', 'pęczki', 'peczek', 'peczki',
            ],
        ],
        'pinch' => [
            'dimension' => 'count',
            'aliases' => [
                'pinch', 'pinches', 'prise', 'prisen', 'pincée', 'pincées',
                'pincee', 'pincees', 'pizzico', 'pizzichi',
                'pizca', 'pizcas', 'pitada', 'pitadas',
                'szczypta', 'szczypty',
            ],
        ],
        'sprig' => [
            'dimension' => 'count',
            'aliases' => [
                'sprig', 'sprigs', 'zweig', 'zweige', 'zweigen',
                'brin', 'brins', 'rametto', 'rametti',
                'ramita', 'ramitas', 'ramo', 'ramos',
                'gałązka', 'gałązki', 'galazka', 'galazki',
            ],
        ],
        'handful' => [
            'dimension' => 'count',
            'aliases' => [
                'handful', 'handfuls', 'poignée', 'poignées', 'poignee',
                'poignees', 'manciata', 'manciate', 'puñado', 'puñados',
                'punado', 'punados', 'garść', 'garści', 'garsc', 'garsci',
            ],
        ],
        'can' => [
            'dimension' => 'package',
            'aliases' => [
                'can', 'cans', 'tin', 'tins', 'dose', 'dosen',
                'boîte', 'boîtes', 'boite', 'boites',
                'lattina', 'lattine', 'lata', 'latas',
                'puszka', 'puszki',
            ],
        ],
        'jar' => [
            'dimension' => 'package',
            'aliases' => [
                'jar', 'jars', 'glas', 'gläser', 'glaser',
                'pot', 'pots', 'barattolo', 'barattoli',
                'frasco', 'frascos', 'słoik', 'słoiki', 'sloik', 'sloiki',
            ],
        ],
        'bottle' => [
            'dimension' => 'package',
            'aliases' => [
                'bottle', 'bottles', 'flasche', 'flaschen',
                'bouteille', 'bouteilles', 'bottiglia', 'bottiglie',
                'botella', 'botellas', 'garrafa', 'garrafas',
                'butelka', 'butelki',
            ],
        ],
        'package' => [
            'dimension' => 'package',
            'aliases' => [
                'package', 'packages', 'pack', 'packs', 'packet', 'packets',
                'pkg', 'conf', 'confezione', 'confezioni',
                'paquet', 'paquets', 'paquete', 'paquetes',
                'embalagem', 'embalagens', 'packung', 'packungen',
                'opakowanie', 'opakowania',
            ],
        ],
    ];
    return $ontology;
}

function recipeQuantityFoldUnitAlias(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = str_replace(['.', '’'], ['', "'"], $value);
    $value = preg_replace('/[^\p{L}\p{N}\']+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function recipeQuantityCanonicalUnit(string $value): ?string {
    static $aliases = null;
    if ($aliases === null) {
        $aliases = [];
        foreach (recipeQuantityUnitOntology() as $canonical => $definition) {
            foreach ($definition['aliases'] as $alias) {
                $aliases[recipeQuantityFoldUnitAlias($alias)] = $canonical;
            }
        }
    }
    return $aliases[recipeQuantityFoldUnitAlias($value)] ?? null;
}

function recipeQuantityUnitPattern(?array $canonicalUnits = null): string {
    $aliases = [];
    $allowed = $canonicalUnits !== null
        ? array_fill_keys($canonicalUnits, true)
        : null;
    foreach (recipeQuantityUnitOntology() as $canonical => $definition) {
        if ($allowed !== null && !isset($allowed[$canonical])) {
            continue;
        }
        foreach ($definition['aliases'] as $alias) {
            $quoted = preg_quote($alias, '/');
            $quoted = str_replace('\ ', '\s+', $quoted);
            $aliases[$quoted] = strlen($alias);
        }
    }
    arsort($aliases, SORT_NUMERIC);
    return '(?:' . implode('|', array_keys($aliases)) . ')';
}

function recipeQuantityParseClosedAmountText(
    mixed $value,
    string $locale = 'und'
): ?array {
    $text = recipeQuantityBoundedText($value, 160);
    if ($text === null) {
        return null;
    }
    $number = recipeQuantityNumberPattern();
    $unit = recipeQuantityUnitPattern();
    if (!preg_match(
        '/^(?<quantity>' . $number . ')'
            . '(?:\s*(?:-|–|—)\s*'
            . '(?<quantity_max>' . $number . '))?'
            . '(?:\s*(?<unit>' . $unit . '))?$/iu',
        $text,
        $match
    )) {
        return null;
    }
    $quantity = recipeQuantityParseNumberToken(
        (string)$match['quantity'],
        $locale
    );
    $quantityMax = isset($match['quantity_max'])
        && trim((string)$match['quantity_max']) !== ''
        ? recipeQuantityParseNumberToken(
            (string)$match['quantity_max'],
            $locale
        )
        : null;
    $unitValue = isset($match['unit'])
        && trim((string)$match['unit']) !== ''
        ? recipeQuantityCanonicalUnit((string)$match['unit'])
        : null;
    if (
        $quantity === null
        || (
            $quantityMax !== null
            && $quantityMax < $quantity
        )
        || (
            isset($match['unit'])
            && trim((string)$match['unit']) !== ''
            && $unitValue === null
        )
    ) {
        return null;
    }
    return [
        'text' => $text,
        'quantity' => $quantity,
        'quantity_max' => $quantityMax,
        'unit' => $unitValue,
    ];
}

function recipeQuantityAddTextEvidence(
    array &$result,
    string $field,
    string $text,
    int $start
): void {
    $result['evidence_spans'][] = [
        'field' => $field,
        'source' => 'text',
        'start' => $start,
        'end' => $start + strlen($text),
        'text' => $text,
    ];
}

function recipeQuantityAddCapturedEvidence(
    array &$result,
    array $match,
    string $capture,
    string $field,
    int $baseOffset = 0
): void {
    if (
        !isset($match[$capture])
        || !is_array($match[$capture])
        || $match[$capture][0] === null
        || (int)$match[$capture][1] < 0
    ) {
        return;
    }
    recipeQuantityAddTextEvidence(
        $result,
        $field,
        (string)$match[$capture][0],
        $baseOffset + (int)$match[$capture][1]
    );
}

function recipeQuantityCleanIngredient(
    string $ingredient,
    string $locale,
    bool $removeGlue
): string {
    $ingredient = trim($ingredient);
    if ($removeGlue) {
        $language = recipeQuantityLocaleLanguage($locale);
        $pattern = match ($language) {
            'en' => '/^of\s+/iu',
            'de' => '/^(?:von|an)\s+/iu',
            'fr' => '/^(?:d[\'’]|de\s+l[\'’]|de\s+|du\s+|des\s+)/iu',
            'it' => '/^(?:d[\'’]|dell[\'’]|di\s+|del\s+|dello\s+|della\s+|dei\s+|degli\s+|delle\s+)/iu',
            'es' => '/^(?:de\s+|del\s+)/iu',
            'pt' => '/^(?:de\s+|da\s+|das\s+|do\s+|dos\s+)/iu',
            default => null,
        };
        if ($pattern !== null) {
            $ingredient = preg_replace($pattern, '', $ingredient, 1)
                ?? $ingredient;
        }
    }
    $ingredient = trim($ingredient, " \t\n\r\0\x0B,;:");
    $ingredient = trim(
        preg_replace('/\s+/u', ' ', $ingredient) ?? $ingredient
    );
    return mb_substr(
        $ingredient,
        0,
        RECIPE_QUANTITY_MAX_INGREDIENT_LENGTH,
        'UTF-8'
    );
}

function recipeQuantityExtractPreparationNote(string $ingredient): array {
    $notes = [
        'zest and juice',
        'juice and zest',
        'zeste et jus',
        'jus et zeste',
        'scorza e succo',
        'succo e scorza',
        'ralladura y zumo',
        'zumo y ralladura',
        'raspa e sumo',
        'sumo e raspa',
    ];
    $pattern = implode('|', array_map(
        static fn(string $note): string => preg_quote($note, '/'),
        $notes
    ));
    if (preg_match(
        '/^(?<ingredient>.+?)\s*,\s*(?<note>' . $pattern . ')\s*$/iu',
        $ingredient,
        $match
    )) {
        return [
            recipeQuantityCleanIngredient($match['ingredient'], 'und', false),
            trim($match['note']),
        ];
    }
    return [$ingredient, null];
}

function recipeQuantityQualifierMatch(string $text): ?array {
    $patterns = [
        'to_taste' => [
            'to taste',
            'nach geschmack',
            'au goût',
            'au gout',
            'al gusto',
            'a gusto',
            'quanto basta',
            'q. b.',
            'q.b.',
            'a gosto',
            'do smaku',
        ],
        'as_needed' => [
            'as needed',
            'as required',
            'nach bedarf',
            'au besoin',
            'selon besoin',
            'quanto necessario',
            'se necessario',
            'según sea necesario',
            'segun sea necesario',
            'conforme necessário',
            'conforme necessario',
            'w razie potrzeby',
        ],
    ];
    foreach ($patterns as $qualifier => $values) {
        $pattern = implode('|', array_map(
            static fn(string $value): string => preg_quote($value, '/'),
            $values
        ));
        if (preg_match(
            '/^(?<ingredient>.+?)\s*(?:,\s*)?(?<qualifier>'
                . $pattern . ')\s*$/iu',
            $text,
            $match,
            PREG_OFFSET_CAPTURE
        )) {
            return [
                'qualifier' => $qualifier,
                'ingredient' => trim((string)$match['ingredient'][0]),
                'text' => (string)$match['qualifier'][0],
                'start' => (int)$match['qualifier'][1],
            ];
        }
    }
    return null;
}

function recipeQuantityApproximatePrefix(string $text): ?array {
    $values = [
        'approximately',
        'approx.',
        'approx',
        'about',
        'around',
        'circa',
        'ca.',
        'etwa',
        'ungefähr',
        'ungefahr',
        'environ',
        'aproximadamente',
        'cerca de',
        'aprox.',
        'aprox',
        'około',
        'okolo',
    ];
    $pattern = implode('|', array_map(
        static fn(string $value): string => preg_quote($value, '/'),
        $values
    ));
    if (!preg_match(
        '/^(?<prefix>' . $pattern . ')\s+/iu',
        $text,
        $match,
        PREG_OFFSET_CAPTURE
    )) {
        return null;
    }
    return [
        'text' => (string)$match['prefix'][0],
        'start' => (int)$match['prefix'][1],
        'consumed' => strlen((string)$match[0][0]),
    ];
}

function recipeQuantityProtectedNumericIdentity(string $text): bool {
    return (bool)preg_match(
        '/^(?:'
            . '7[\s-]*up\b'
            . '|1000\s+island\b'
            . '|00\s+flour\b'
            . '|\d+\s*%'
            . '|\d+\s+(?:spice|grain|seasoning|booster)\b'
            . '|\d+\s*-(?!\s*\d)[\p{L}]'
            . ')/iu',
        $text
    );
}

function recipeQuantityImplicitPieceIsSafe(
    string $ingredient,
    string $quantityToken,
    ?string $quantityMaxToken,
    string $locale
): bool {
    $quantityToken = trim($quantityToken);
    $quantityMaxToken = $quantityMaxToken !== null
        ? trim($quantityMaxToken)
        : null;
    if (
        !preg_match('/^\d+$/', $quantityToken)
        || (
            $quantityMaxToken !== null
            && !preg_match('/^\d+$/', $quantityMaxToken)
        )
    ) {
        return false;
    }
    $language = recipeQuantityLocaleLanguage($locale);
    $descriptors = [
        'en' => 'large|medium|small|whole|ripe|fresh|baby',
        'de' => 'groß|grosse|große|gross|mittel|klein|ganze|ganzes|frisch',
        'fr' => 'grand|grande|gros|grosse|moyen|moyenne|petit|petite|entier|entière|frais|fraîche',
        'it' => 'grande|medio|media|piccolo|piccola|intero|intera|fresco|fresca',
        'es' => 'grande|mediano|mediana|pequeño|pequeña|entero|entera|fresco|fresca',
        'pt' => 'grande|médio|média|medio|media|pequeno|pequena|inteiro|inteira|fresco|fresca',
        'pl' => 'duży|duża|duze|duże|średni|średnia|sredni|srednia|mały|mała|maly|mala|cały|cała|caly|cala|świeży|świeża|swiezy|swieza',
    ];
    $nouns = [
        'en' => 'eggs?|lemons?|limes?|oranges?|apples?|onions?|potato(?:es)?|tomato(?:es)?|carrots?|(?:bell\s+)?peppers?|chill?i(?:es|s)?|bananas?|avocados?|cucumbers?|zucchinis?|courgettes?|chicken\s+(?:breasts?|thighs?)|garlic\s+bulbs?',
        'de' => 'ei(?:er)?|zitronen?|limetten?|orangen?|äpfel|apfel|zwiebeln?|kartoffeln?|tomaten?|karotten?|paprika|bananen?|avocados?|gurken?',
        'fr' => 'œufs?|oeufs?|citrons?|citrons?\s+verts?|oranges?|pommes?|oignons?|pommes?\s+de\s+terre|tomates?|carottes?|poivrons?|bananes?|avocats?|concombres?|courgettes?',
        'it' => 'uov[oa]|limon[ei]|lime|aranc[ei]|mel[ae]|cipoll[ae]|patat[ae]|pomodor[oi]|carot[ae]|peperon[ei]|banan[ae]|avocad[oi]|cetriol[oi]|zucchin[ae]',
        'es' => 'huevos?|limones?|limas?|naranjas?|manzanas?|cebollas?|patatas?|papas?|tomates?|zanahorias?|pimientos?|bananas?|plátanos?|platanos?|aguacates?|pepinos?|calabacines?',
        'pt' => 'ovos?|limões?|limoes?|limas?|laranjas?|maçãs?|macas?|cebolas?|batatas?|tomates?|cenouras?|pimentos?|bananas?|abacates?|pepinos?|curgetes?',
        'pl' => 'jajk(?:o|a|ek)|cytryn(?:a|y)|limonk(?:a|i)|pomarańcz(?:a|e)|pomarancz(?:a|e)|jabłk(?:o|a)|jablk(?:o|a)|cebul(?:a|e|i)|ziemniak(?:i|ów|ow)?|pomidor(?:y|ów|ow)?|marchewk(?:a|i)|papryk(?:a|i)|banan(?:y|ów|ow)?|awokado|ogórk(?:i|ów|ow)|ogork(?:i|ow)?',
    ];
    $descriptorPattern = $descriptors[$language] ?? $descriptors['en'];
    $nounPattern = $nouns[$language] ?? $nouns['en'];
    return (bool)preg_match(
        '/^(?:(?:' . $descriptorPattern . ')\s+)*(?:'
            . $nounPattern . ')(?!\p{L})/iu',
        trim($ingredient)
    );
}

function recipeQuantityParseJuiceOf(
    string $text,
    string $locale,
    int $baseOffset,
    array $baseResult
): ?array {
    $number = recipeQuantityNumberPattern();
    $language = recipeQuantityLocaleLanguage($locale);
    $definitions = [
        'en' => [
            '/^(?:(?:the\s+)?juice\s+of)\s*(?<quantity>'
                . $number . ')\s+(?<ingredient>.+)$/iu',
            static fn(string $ingredient): string => $ingredient . ' juice',
        ],
        'fr' => [
            '/^(?:(?:le\s+)?jus\s+d(?:e\s+|[\'’]\s*))(?<quantity>'
                . $number . ')\s+(?<ingredient>.+)$/iu',
            static fn(string $ingredient): string => 'jus de ' . $ingredient,
        ],
        'it' => [
            '/^(?:il\s+)?succo\s+di\s*(?<quantity>'
                . $number . ')\s+(?<ingredient>.+)$/iu',
            static fn(string $ingredient): string => 'succo di ' . $ingredient,
        ],
        'es' => [
            '/^(?:el\s+)?(?:zumo|jugo)\s+de\s*(?<quantity>'
                . $number . ')\s+(?<ingredient>.+)$/iu',
            static fn(string $ingredient): string => 'zumo de ' . $ingredient,
        ],
        'pt' => [
            '/^(?:o\s+)?sumo\s+de\s*(?<quantity>'
                . $number . ')\s+(?<ingredient>.+)$/iu',
            static fn(string $ingredient): string => 'sumo de ' . $ingredient,
        ],
        'de' => [
            '/^(?:der\s+)?saft\s+(?:von|aus)\s*(?<quantity>'
                . $number . ')\s+(?<ingredient>.+)$/iu',
            static fn(string $ingredient): string => $ingredient . 'saft',
        ],
        'pl' => [
            '/^(?:sok\s+z)\s*(?<quantity>'
                . $number . ')\s+(?<ingredient>.+)$/iu',
            static fn(string $ingredient): string => 'sok z ' . $ingredient,
        ],
    ];
    if (!isset($definitions[$language])) {
        $definitions = ['en' => $definitions['en'], 'fr' => $definitions['fr']];
    } else {
        $definitions = [$language => $definitions[$language]];
    }
    foreach ($definitions as [$pattern, $identity]) {
        if (!preg_match(
            $pattern,
            $text,
            $match,
            PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL
        )) {
            continue;
        }
        $quantity = recipeQuantityParseNumberToken(
            (string)$match['quantity'][0],
            $locale
        );
        $ingredient = recipeQuantityCleanIngredient(
            (string)$match['ingredient'][0],
            $locale,
            false
        );
        if ($quantity === null || $ingredient === '') {
            return null;
        }
        $baseResult['status'] = 'ambiguous';
        $baseResult['quantity'] = $quantity;
        $baseResult['unit'] = 'piece';
        $baseResult['unit_raw'] = 'piece';
        $baseResult['ingredient'] = recipeQuantityCleanIngredient(
            $identity($ingredient),
            $locale,
            false
        );
        recipeQuantityAddCapturedEvidence(
            $baseResult,
            $match,
            'quantity',
            'quantity',
            $baseOffset
        );
        return $baseResult;
    }
    return null;
}

function recipeQuantityPopulateMatch(
    array $result,
    array $match,
    string $locale,
    int $baseOffset,
    bool $package
): ?array {
    $quantity = recipeQuantityParseNumberToken(
        (string)$match['quantity'][0],
        $locale
    );
    $hasQuantityMax = isset($match['quantity_max'][0])
        && $match['quantity_max'][0] !== null;
    $quantityMax = $hasQuantityMax
            ? recipeQuantityParseNumberToken(
                (string)$match['quantity_max'][0],
                $locale
            )
            : null;
    if (
        $quantity === null
        || (
            $hasQuantityMax
            && (
                $quantityMax === null
                || $quantityMax < $quantity
            )
        )
    ) {
        return null;
    }
    $unitRaw = trim((string)$match['unit'][0]);
    $unit = recipeQuantityCanonicalUnit($unitRaw);
    if ($unit === null) {
        return null;
    }
    $ingredient = recipeQuantityCleanIngredient(
        (string)$match['ingredient'][0],
        $locale,
        true
    );
    [$ingredient, $note] = recipeQuantityExtractPreparationNote($ingredient);
    if ($ingredient === '') {
        return null;
    }
    $result['status'] = 'parsed';
    $result['quantity'] = $quantity;
    $result['quantity_max'] = $quantityMax;
    $result['unit'] = $unit;
    $result['unit_raw'] = $unitRaw;
    $result['ingredient'] = $ingredient;
    $result['note'] = $note;
    recipeQuantityAddCapturedEvidence(
        $result,
        $match,
        'quantity',
        'quantity',
        $baseOffset
    );
    recipeQuantityAddCapturedEvidence(
        $result,
        $match,
        'quantity_max',
        'quantity_max',
        $baseOffset
    );
    if ($package) {
        $packageQuantity = recipeQuantityParseNumberToken(
            (string)$match['package_quantity'][0],
            $locale
        );
        $packageUnitRaw = trim((string)$match['package_unit'][0]);
        $packageUnit = recipeQuantityCanonicalUnit($packageUnitRaw);
        if (
            $packageQuantity === null
            || $packageUnit === null
            || !in_array(
                recipeQuantityUnitOntology()[$packageUnit]['dimension'],
                ['mass', 'volume'],
                true
            )
        ) {
            return null;
        }
        $result['package_quantity'] = $packageQuantity;
        $result['package_unit'] = $packageUnit;
        recipeQuantityAddCapturedEvidence(
            $result,
            $match,
            'package_quantity',
            'package_quantity',
            $baseOffset
        );
    }
    return $result;
}

function recipeQuantityParseText(mixed $value, string $locale = 'und'): array {
    $text = recipeQuantityBoundedText(
        $value,
        RECIPE_QUANTITY_MAX_TEXT_LENGTH
    );
    $locale = recipeQuantityNormalizeLocale($locale);
    $result = recipeQuantityResult($text, $text, $locale);
    if ($text === null) {
        return $result;
    }
    $parseText = $text;

    $number = recipeQuantityNumberPattern();
    if (preg_match(
        '/^(?<ingredient>.+?)\s*\(\s*(?<note>'
            . $number . '\s*[x×]\s*' . $number
            . '\s*(?:mm|cm|m|in|inch|inches|"))\s*\)\s*$/iu',
        $text,
        $dimension
    )) {
        $ingredient = recipeQuantityCleanIngredient(
            $dimension['ingredient'],
            $locale,
            false
        );
        if ($ingredient !== '') {
            $result['status'] = 'not_present';
            $result['ingredient'] = $ingredient;
            $result['note'] = mb_substr(
                trim($dimension['note']),
                0,
                RECIPE_QUANTITY_MAX_NOTE_LENGTH,
                'UTF-8'
            );
            return $result;
        }
    }

    $qualifier = recipeQuantityQualifierMatch($parseText);
    if ($qualifier !== null && $qualifier['ingredient'] !== '') {
        $qualifiedSubject = trim((string)$qualifier['ingredient']);
        if (
            !preg_match(
                '/(?:\d|[¼½¾⅐⅑⅒⅓⅔⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞])/u',
                $qualifiedSubject
            )
        ) {
            $result['status'] = 'not_present';
            $result['ingredient'] = $qualifiedSubject;
            $result['qualifier'] = $qualifier['qualifier'];
            return $result;
        }
        $result['qualifier'] = $qualifier['qualifier'];
        $parseText = $qualifiedSubject;
    }

    $baseOffset = 0;
    $subject = $parseText;
    $approximate = recipeQuantityApproximatePrefix($subject);
    if ($approximate !== null) {
        $result['approximate'] = true;
        $baseOffset = $approximate['consumed'];
        $subject = substr($subject, $baseOffset);
    }

    $juice = recipeQuantityParseJuiceOf(
        $subject,
        $locale,
        $baseOffset,
        $result
    );
    if ($juice !== null) {
        return $juice;
    }

    $sizeUnits = recipeQuantityUnitPattern([
        'mg', 'g', 'kg', 'ml', 'cl', 'dl', 'l', 'oz', 'lb',
    ]);
    $packageUnits = recipeQuantityUnitPattern([
        'can', 'jar', 'bottle', 'package',
    ]);
    $allUnits = recipeQuantityUnitPattern();
    $rangeSeparator = '(?:-|–|—|to)';

    $patterns = [
        [
            '/^(?<quantity>' . $number . ')\s*[x×]\s*'
                . '(?<package_quantity>' . $number . ')\s*'
                . '(?<package_unit>' . $sizeUnits . ')(?![\p{L}\p{N}])'
                . '\.?\s+(?<unit>' . $packageUnits . ')'
                . '(?![\p{L}\p{N}])\.?\s+(?<ingredient>.+)$/iu',
            true,
        ],
        [
            '/^(?<quantity>' . $number . ')\s*\(\s*'
                . '(?<package_quantity>' . $number . ')\s*'
                . '(?<package_unit>' . $sizeUnits . ')(?![\p{L}\p{N}])'
                . '\.?\s*\)\s*(?<unit>' . $packageUnits . ')'
                . '(?![\p{L}\p{N}])\.?\s+(?<ingredient>.+)$/iu',
            true,
        ],
        [
            '/^(?<quantity>' . $number . ')'
                . '(?:\s*' . $rangeSeparator . '\s*'
                . '(?<quantity_max>' . $number . '))?'
                . '\s*(?<unit>' . $allUnits . ')'
                . '(?![\p{L}\p{N}])\.?\s+(?<ingredient>.+)$/iu',
            false,
        ],
    ];
    foreach ($patterns as [$pattern, $package]) {
        if (!preg_match(
            $pattern,
            $subject,
            $match,
            PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL
        )) {
            continue;
        }
        $parsed = recipeQuantityPopulateMatch(
            $result,
            $match,
            $locale,
            $baseOffset,
            $package
        );
        if ($parsed !== null) {
            return $parsed;
        }
    }

    if (preg_match(
        '/^(?<quantity>' . $number . ')'
            . '(?:\s*' . $rangeSeparator . '\s*'
            . '(?<quantity_max>' . $number . '))?'
            . '\s+(?<ingredient>[\p{L}].*)$/iu',
        $subject,
        $match,
        PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL
    )) {
        $token = trim((string)$match['quantity'][0]);
        $quantity = recipeQuantityParseNumberToken($token, $locale);
        $hasQuantityMax = isset($match['quantity_max'][0])
            && $match['quantity_max'][0] !== null;
        $quantityMax = $hasQuantityMax
                ? recipeQuantityParseNumberToken(
                    (string)$match['quantity_max'][0],
                    $locale
                )
                : null;
        $quantityMaxToken = isset($match['quantity_max'][0])
            && $match['quantity_max'][0] !== null
                ? (string)$match['quantity_max'][0]
                : null;
        $integerToken = preg_replace('/\s+/u', '', $token) ?? $token;
        $leadingZeroIdentity = preg_match('/^0\d+$/', $integerToken);
        $ingredient = recipeQuantityCleanIngredient(
            (string)$match['ingredient'][0],
            $locale,
            false
        );
        [$ingredient, $note] =
            recipeQuantityExtractPreparationNote($ingredient);
        if (
            $quantity !== null
            && $quantity <= 99
            && (
                !$hasQuantityMax
                || (
                    $quantityMax !== null
                    && $quantityMax >= $quantity
                    && $quantityMax <= 99
                )
            )
            && !$leadingZeroIdentity
            && !recipeQuantityProtectedNumericIdentity($subject)
            && recipeQuantityImplicitPieceIsSafe(
                $ingredient,
                $token,
                $quantityMaxToken,
                $locale
            )
        ) {
            if ($ingredient !== '') {
                $result['status'] = 'parsed';
                $result['quantity'] = $quantity;
                $result['quantity_max'] = $quantityMax;
                $result['unit'] = 'piece';
                $result['unit_raw'] = 'piece';
                $result['ingredient'] = $ingredient;
                $result['note'] = $note;
                recipeQuantityAddCapturedEvidence(
                    $result,
                    $match,
                    'quantity',
                    'quantity',
                    $baseOffset
                );
                recipeQuantityAddCapturedEvidence(
                    $result,
                    $match,
                    'quantity_max',
                    'quantity_max',
                    $baseOffset
                );
                return $result;
            }
        }
    }

    if (recipeQuantityProtectedNumericIdentity($text)) {
        $result['status'] = 'not_present';
        $result['ingredient'] = recipeQuantityCleanIngredient(
            $text,
            $locale,
            false
        );
        return $result;
    }
    if (preg_match(
        '/(?:\d|[¼½¾⅐⅑⅒⅓⅔⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞])/u',
        $text
    )) {
        $result['status'] = 'unparsed';
        return $result;
    }
    $result['status'] = 'not_present';
    return $result;
}

function recipeQuantityStructuredNumber(mixed $value): ?float {
    if (
        is_bool($value)
        || (!is_int($value) && !is_float($value))
    ) {
        return null;
    }
    $value = (float)$value;
    if (!is_finite($value) || $value < 0 || $value > 1e9) {
        return null;
    }
    return $value;
}

function recipeQuantityFormatNumber(float $value): string {
    if (floor($value) === $value) {
        return number_format($value, 0, '.', '');
    }
    return rtrim(rtrim(sprintf('%.7F', $value), '0'), '.');
}

function recipeQuantityAddStructuredEvidence(
    array &$result,
    string $field,
    string $path,
    float $value
): void {
    $result['evidence_spans'][] = [
        'field' => $field,
        'source' => 'structured',
        'path' => $path,
        'start' => null,
        'end' => null,
        'text' => recipeQuantityFormatNumber($value),
    ];
}

function recipeQuantityParseStructuredCookidoo(array $input): array {
    $ingredient = recipeQuantityBoundedText(
        $input['ingredient'] ?? $input['name'] ?? null,
        200
    );
    $structuredLocale = is_string($input['locale'] ?? null)
        ? recipeQuantityNormalizeLocale($input['locale'])
        : null;
    $result = recipeQuantityResult(
        null,
        $ingredient,
        $structuredLocale
    );
    $result['parser_version'] = RECIPE_QUANTITY_STRUCTURED_VERSION;
    $result['provenance'] = 'cookidoo_structured';
    if (strtolower(trim((string)($input['source'] ?? ''))) !== 'cookidoo') {
        return $result;
    }
    if (
        !array_key_exists('quantity', $input)
        || !array_key_exists('unit_ref', $input)
        || !array_key_exists('unit_notation', $input)
    ) {
        return $result;
    }

    $quantityShape = $input['quantity'];
    $unitRef = $input['unit_ref'];
    $unitNotation = $input['unit_notation'];
    if ($quantityShape === null) {
        if (
            !($unitRef === null || $unitRef === '')
            || !($unitNotation === null || $unitNotation === '')
        ) {
            return $result;
        }
        $result['status'] = 'not_present';
        return $result;
    }
    if (!is_array($quantityShape)) {
        return $result;
    }
    $keys = array_keys($quantityShape);
    sort($keys, SORT_STRING);
    if ($keys !== ['from', 'to', 'value']) {
        return $result;
    }

    $normalizedUnitRef = $unitRef === null
        ? null
        : recipeQuantityBoundedText($unitRef, 200);
    $normalizedNotation = $unitNotation === null
        ? null
        : recipeQuantityBoundedText($unitNotation, 80);
    if (
        ($unitRef !== null && $normalizedUnitRef === null)
        || ($unitNotation !== null && $normalizedNotation === null)
        || (($normalizedUnitRef === null) !== ($normalizedNotation === null))
        || (
            $normalizedUnitRef !== null
            && !preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
                $normalizedUnitRef
            )
        )
    ) {
        return $result;
    }

    $value = $quantityShape['value'];
    $from = $quantityShape['from'];
    $to = $quantityShape['to'];
    if ($value !== null && $from === null && $to === null) {
        $quantity = recipeQuantityStructuredNumber($value);
        if ($quantity === null) {
            return $result;
        }
        $result['quantity'] = $quantity;
        recipeQuantityAddStructuredEvidence(
            $result,
            'quantity',
            'quantity.value',
            $quantity
        );
    } elseif ($value === null && $from !== null && $to !== null) {
        $quantity = recipeQuantityStructuredNumber($from);
        $quantityMax = recipeQuantityStructuredNumber($to);
        if (
            $quantity === null
            || $quantityMax === null
            || $quantityMax < $quantity
        ) {
            return $result;
        }
        $result['quantity'] = $quantity;
        $result['quantity_max'] = $quantityMax;
        recipeQuantityAddStructuredEvidence(
            $result,
            'quantity',
            'quantity.from',
            $quantity
        );
        recipeQuantityAddStructuredEvidence(
            $result,
            'quantity_max',
            'quantity.to',
            $quantityMax
        );
    } else {
        return $result;
    }

    $result['status'] = 'structured';
    $result['unit_raw'] = $normalizedNotation;
    $result['unit'] = $normalizedNotation !== null
        ? recipeQuantityCanonicalUnit($normalizedNotation)
        : null;
    return $result;
}

function recipeQuantityParse(
    mixed $input,
    string $locale = 'und',
    string $source = 'manual'
): array {
    if (strtolower(trim($source)) === 'cookidoo') {
        if (!is_array($input)) {
            $result = recipeQuantityResult(null, null, $locale);
            $result['parser_version'] = RECIPE_QUANTITY_STRUCTURED_VERSION;
            $result['provenance'] = 'cookidoo_structured';
            return $result;
        }
        $input['source'] = 'cookidoo';
        if (!array_key_exists('locale', $input)) {
            $input['locale'] = $locale;
        }
        return recipeQuantityParseStructuredCookidoo($input);
    }
    return recipeQuantityParseText($input, $locale);
}

function recipeQuantityEncodeResult(array $result): ?string {
    $json = json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (
        $json === false
        || strlen($json) > RECIPE_QUANTITY_MAX_RESULT_BYTES
    ) {
        return null;
    }
    return $json;
}

function recipeQuantityTextHasExactNumberSpan(
    string $sourceText,
    int $start,
    int $end,
    string $locale
): bool {
    if (!preg_match_all(
        '/(?<![\p{L}\p{N}])' . recipeQuantityNumberPattern() . '/u',
        $sourceText,
        $matches,
        PREG_OFFSET_CAPTURE
    )) {
        return false;
    }
    foreach ($matches[0] as [$text, $offset]) {
        if (
            $offset === $start
            && $offset + strlen($text) === $end
            && !recipeQuantityNumberSpanHasIdentifierPrefix(
                $sourceText,
                $start
            )
            && recipeQuantityParseNumberToken($text, $locale) !== null
        ) {
            return true;
        }
    }
    return false;
}

function recipeQuantityNumberSpanHasIdentifierPrefix(
    string $sourceText,
    int $start
): bool {
    if ($start <= 0) {
        return false;
    }
    $prefix = substr($sourceText, 0, $start);
    if (preg_match(
        '/([\p{L}\p{N}]+)[\p{Pd}\p{Pc}.\/+\x{2212}]+$/u',
        $prefix,
        $match
    )) {
        return (bool)preg_match('/\p{L}/u', $match[1]);
    }
    if (!preg_match(
        '/(?:^|[^\p{L}\p{N}])(\p{L})\s+$/u',
        $prefix,
        $match
    )) {
        return false;
    }
    return mb_strtolower($match[1], 'UTF-8') !== 'x';
}

function recipeQuantityTextHasExactUnitSpan(
    string $sourceText,
    int $start,
    int $end
): bool {
    if (!preg_match_all(
        '/(?<!\p{L})' . recipeQuantityUnitPattern()
            . '(?![\p{L}\p{N}])/iu',
        $sourceText,
        $matches,
        PREG_OFFSET_CAPTURE
    )) {
        return false;
    }
    foreach ($matches[0] as [$text, $offset]) {
        if ($offset === $start && $offset + strlen($text) === $end) {
            return true;
        }
    }
    return false;
}

function recipeQuantityResultsSemanticallyEqual(
    array $left,
    array $right
): bool {
    foreach (['quantity', 'quantity_max', 'package_quantity'] as $field) {
        if (($left[$field] ?? null) === null || ($right[$field] ?? null) === null) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                return false;
            }
            continue;
        }
        if (
            abs((float)$left[$field] - (float)$right[$field])
                > 0.0000001
        ) {
            return false;
        }
    }
    foreach ([
        'status',
        'unit',
        'unit_raw',
        'ingredient',
        'package_unit',
        'approximate',
        'qualifier',
        'note',
        'parser_version',
        'provenance',
        'source_text',
        'locale',
        'ranking_eligible',
    ] as $field) {
        if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
            return false;
        }
    }
    $sortEvidence = static function (array $spans): array {
        usort($spans, static function (array $left, array $right): int {
            return [
                (string)($left['field'] ?? ''),
                (int)($left['start'] ?? -1),
                (string)($left['path'] ?? ''),
            ] <=> [
                (string)($right['field'] ?? ''),
                (int)($right['start'] ?? -1),
                (string)($right['path'] ?? ''),
            ];
        });
        return $spans;
    };
    return $sortEvidence($left['evidence_spans'] ?? [])
        == $sortEvidence($right['evidence_spans'] ?? []);
}

function recipeQuantityRangeEvidenceLayoutIsValid(
    string $sourceText,
    array $evidence,
    mixed $quantityMax
): bool {
    if ($quantityMax === null) {
        return true;
    }
    if (
        !isset($evidence['quantity'], $evidence['quantity_max'])
        || $evidence['quantity']['end']
            > $evidence['quantity_max']['start']
    ) {
        return false;
    }
    return (bool)preg_match(
        '/^\s*(?:-|–|—|to)\s*$/iu',
        substr(
            $sourceText,
            $evidence['quantity']['end'],
            $evidence['quantity_max']['start']
                - $evidence['quantity']['end']
        )
    );
}

function recipeQuantityModelResultSemanticsAreValid(array $result): bool {
    if (
        !defined('RECIPE_QUANTITY_MODEL_PROMPT_VERSION')
        || ($result['parser_version'] ?? null)
            !== RECIPE_QUANTITY_MODEL_PROMPT_VERSION
        || ($result['provenance'] ?? null) !== 'model_proposal'
        || !is_string($result['source_text'] ?? null)
        || !is_string($result['locale'] ?? null)
        || !is_string($result['ingredient'] ?? null)
        || !preg_match('/\p{L}/u', $result['ingredient'])
        || !is_array($result['evidence_spans'] ?? null)
        || ($result['status'] ?? null) === 'structured'
    ) {
        return false;
    }
    if (
        (($result['unit'] ?? null) === null)
            !== (($result['unit_raw'] ?? null) === null)
        || (
            ($result['unit'] ?? null) !== null
            && recipeQuantityCanonicalUnit((string)$result['unit_raw'])
                !== $result['unit']
        )
    ) {
        return false;
    }
    $sourceText = $result['source_text'];
    $locale = $result['locale'];
    $evidence = [];
    $ranges = [];
    foreach ($result['evidence_spans'] as $span) {
        if (
            !is_array($span)
            || ($span['source'] ?? null) !== 'text'
            || !is_string($span['field'] ?? null)
            || isset($evidence[$span['field']])
            || !is_int($span['start'] ?? null)
            || !is_int($span['end'] ?? null)
            || !is_string($span['text'] ?? null)
            || $span['start'] < 0
            || $span['end'] <= $span['start']
            || $span['end'] > strlen($sourceText)
            || substr(
                $sourceText,
                $span['start'],
                $span['end'] - $span['start']
            ) !== $span['text']
        ) {
            return false;
        }
        foreach ($ranges as [$start, $end]) {
            if ($span['start'] < $end && $span['end'] > $start) {
                return false;
            }
        }
        $ranges[] = [$span['start'], $span['end']];
        $evidence[$span['field']] = $span;
    }
    $required = ['ingredient' => $result['ingredient']];
    foreach ([
        'quantity',
        'quantity_max',
        'unit',
        'package_quantity',
        'package_unit',
        'qualifier',
        'note',
    ] as $field) {
        if (($result[$field] ?? null) !== null) {
            $required[$field] = $result[$field];
        }
    }
    if (
        array_diff_key($required, $evidence)
        || array_diff_key($evidence, $required)
        || $evidence['ingredient']['text'] !== $result['ingredient']
    ) {
        return false;
    }
    $ingredientSpan = $evidence['ingredient'];
    if (
        recipeQuantityParseNumberToken(
            $ingredientSpan['text'],
            $locale
        ) !== null
        || preg_match(
            '/[\p{L}\p{N}]$/u',
            substr($sourceText, 0, $ingredientSpan['start'])
        )
        || preg_match(
            '/^[\p{L}\p{N}]/u',
            substr($sourceText, $ingredientSpan['end'])
        )
    ) {
        return false;
    }
    foreach ([
        'quantity',
        'quantity_max',
        'package_quantity',
    ] as $field) {
        if (($result[$field] ?? null) === null) {
            continue;
        }
        $span = $evidence[$field];
        if (
            !recipeQuantityTextHasExactNumberSpan(
                $sourceText,
                $span['start'],
                $span['end'],
                $locale
            )
            || abs(
                (float)recipeQuantityParseNumberToken(
                    $span['text'],
                    $locale
                ) - (float)$result[$field]
            ) > 0.0000001
        ) {
            return false;
        }
    }
    foreach (['unit', 'package_unit'] as $field) {
        if (($result[$field] ?? null) === null) {
            continue;
        }
        $span = $evidence[$field];
        if (
            !recipeQuantityTextHasExactUnitSpan(
                $sourceText,
                $span['start'],
                $span['end']
            )
            || recipeQuantityCanonicalUnit($span['text'])
                !== $result[$field]
        ) {
            return false;
        }
    }
    if (
        ($result['unit'] ?? null) !== null
        && ($result['unit_raw'] ?? null) !== $evidence['unit']['text']
    ) {
        return false;
    }
    if (
        ($result['qualifier'] ?? null) !== null
        && (
            (recipeQuantityQualifierMatch($sourceText)['qualifier'] ?? null)
                !== $result['qualifier']
            || $evidence['qualifier']['text']
                !== recipeQuantityQualifierMatch($sourceText)['text']
        )
    ) {
        return false;
    }
    if (
        ($result['note'] ?? null) !== null
        && $evidence['note']['text'] !== $result['note']
    ) {
        return false;
    }
    if (
        (bool)$result['approximate']
        !== (recipeQuantityApproximatePrefix($sourceText) !== null)
    ) {
        return false;
    }
    $quantity = $result['quantity'] ?? null;
    $quantityMax = $result['quantity_max'] ?? null;
    $unit = $result['unit'] ?? null;
    $packageQuantity = $result['package_quantity'] ?? null;
    if (
        in_array($result['status'], ['not_present', 'unparsed'], true)
        && (
            $quantity !== null
            || $quantityMax !== null
            || $unit !== null
            || $packageQuantity !== null
            || ($result['package_unit'] ?? null) !== null
        )
    ) {
        return false;
    }
    if ($result['status'] === 'parsed' && ($quantity === null || $unit === null)) {
        return false;
    }
    if ($quantity === null) {
        return $unit === null && $packageQuantity === null;
    }
    if (
        !recipeQuantityRangeEvidenceLayoutIsValid(
            $sourceText,
            $evidence,
            $quantityMax
        )
    ) {
        return false;
    }
    if ($unit === null) {
        return $packageQuantity === null
            && ($result['package_unit'] ?? null) === null
            && $result['status'] === 'ambiguous'
            && recipeQuantityParseText($sourceText, $locale)['status']
                === 'ambiguous';
    }
    if ($packageQuantity === null) {
        $amountSpan = $quantityMax !== null
            ? $evidence['quantity_max']
            : $evidence['quantity'];
        if (
            $amountSpan['end'] > $evidence['unit']['start']
            || !preg_match(
                '/^\s*$/u',
                substr(
                    $sourceText,
                    $amountSpan['end'],
                    $evidence['unit']['start'] - $amountSpan['end']
                )
            )
        ) {
            return false;
        }
        return true;
    }
    if (
        $quantityMax !== null
        || recipeQuantityUnitOntology()[$unit]['dimension'] !== 'package'
    ) {
        return false;
    }
    if (
        $evidence['quantity']['end'] > $evidence['package_quantity']['start']
        || $evidence['package_quantity']['end']
            > $evidence['package_unit']['start']
        || $evidence['package_unit']['end'] > $evidence['unit']['start']
    ) {
        return false;
    }
    $quantityToPackage = substr(
        $sourceText,
        $evidence['quantity']['end'],
        $evidence['package_quantity']['start']
            - $evidence['quantity']['end']
    );
    $packageToSizeUnit = substr(
        $sourceText,
        $evidence['package_quantity']['end'],
        $evidence['package_unit']['start']
            - $evidence['package_quantity']['end']
    );
    $sizeUnitToUnit = substr(
        $sourceText,
        $evidence['package_unit']['end'],
        $evidence['unit']['start'] - $evidence['package_unit']['end']
    );
    return preg_match('/^\s*$/u', $packageToSizeUnit)
        && (
            (
                preg_match('/^\s*[x×]\s*$/u', $quantityToPackage)
                && preg_match('/^\s*$/u', $sizeUnitToUnit)
            )
            || (
                preg_match('/^\s*\(\s*$/u', $quantityToPackage)
                && preg_match('/^\s*\)\s*$/u', $sizeUnitToUnit)
            )
        );
}

function recipeQuantityDecodeResult(mixed $value): ?array {
    if (
        !is_string($value)
        || $value === ''
        || strlen($value) > RECIPE_QUANTITY_MAX_RESULT_BYTES
    ) {
        return null;
    }
    $result = json_decode($value, true);
    if (!is_array($result)) {
        return null;
    }
    $keys = array_keys($result);
    $expectedKeys = [
        'status',
        'quantity',
        'quantity_max',
        'unit',
        'unit_raw',
        'ingredient',
        'package_quantity',
        'package_unit',
        'approximate',
        'qualifier',
        'note',
        'parser_version',
        'provenance',
        'source_text',
        'locale',
        'evidence_spans',
        'ranking_eligible',
    ];
    sort($keys, SORT_STRING);
    sort($expectedKeys, SORT_STRING);
    if ($keys !== $expectedKeys) {
        return null;
    }
    if (
        !in_array(
            $result['status'] ?? null,
            ['structured', 'parsed', 'not_present', 'ambiguous', 'unparsed'],
            true
        )
        || !is_string($result['parser_version'] ?? null)
        || strlen($result['parser_version']) > 80
        || !is_string($result['provenance'] ?? null)
        || strlen($result['provenance']) > 80
        || !is_array($result['evidence_spans'] ?? null)
        || count($result['evidence_spans']) > 8
        || !is_bool($result['approximate'] ?? null)
        || ($result['ranking_eligible'] ?? null) !== false
        || !in_array(
            $result['qualifier'] ?? null,
            [null, 'to_taste', 'as_needed'],
            true
        )
    ) {
        return null;
    }
    if (
        ($result['locale'] ?? null) !== null
        && (
            !is_string($result['locale'])
            || recipeQuantityNormalizeLocale($result['locale'])
                !== $result['locale']
        )
    ) {
        return null;
    }
    foreach ([
        'quantity',
        'quantity_max',
        'package_quantity',
    ] as $field) {
        $number = $result[$field] ?? null;
        if (
            $number !== null
            && (
                is_bool($number)
                || (!is_int($number) && !is_float($number))
                || !is_finite((float)$number)
                || (float)$number < 0
                || (float)$number > 1e9
            )
        ) {
            return null;
        }
    }
    if (
        $result['quantity_max'] !== null
        && (
            $result['quantity'] === null
            || (float)$result['quantity_max'] < (float)$result['quantity']
        )
    ) {
        return null;
    }
    if (
        ($result['package_quantity'] === null)
            !== ($result['package_unit'] === null)
        || (
            $result['package_quantity'] !== null
            && $result['quantity'] === null
        )
    ) {
        return null;
    }
    if (
        $result['unit'] !== null
        && (
            !is_string($result['unit'])
            || !isset(recipeQuantityUnitOntology()[$result['unit']])
        )
    ) {
        return null;
    }
    if (
        $result['package_unit'] !== null
        && (
            !is_string($result['package_unit'])
            || !isset(recipeQuantityUnitOntology()[$result['package_unit']])
            || !in_array(
                recipeQuantityUnitOntology()[$result['package_unit']]
                    ['dimension'],
                ['mass', 'volume'],
                true
            )
        )
    ) {
        return null;
    }
    if (
        in_array(
            $result['provenance'],
            ['deterministic_grammar', 'model_proposal'],
            true
        )
        && (
            (($result['unit'] ?? null) === null)
                !== (($result['unit_raw'] ?? null) === null)
            || (
                ($result['unit'] ?? null) !== null
                && recipeQuantityCanonicalUnit(
                    (string)$result['unit_raw']
                ) !== $result['unit']
            )
        )
    ) {
        return null;
    }
    foreach ([
        'unit' => 40,
        'unit_raw' => 80,
        'ingredient' => RECIPE_QUANTITY_MAX_INGREDIENT_LENGTH,
        'package_unit' => 40,
        'note' => RECIPE_QUANTITY_MAX_NOTE_LENGTH,
        'source_text' => RECIPE_QUANTITY_MAX_TEXT_LENGTH,
    ] as $field => $maximum) {
        $text = $result[$field] ?? null;
        if (
            $text !== null
            && (
                !is_string($text)
                || mb_strlen($text, 'UTF-8') > $maximum
                || preg_match('/[\x00-\x1F\x7F]/u', $text)
            )
        ) {
            return null;
        }
    }
    $numericEvidence = [];
    $seenEvidence = [];
    foreach ($result['evidence_spans'] as $span) {
        if (
            !is_array($span)
            || !is_string($span['field'] ?? null)
            || !in_array(
                $span['field'],
                [
                    'quantity',
                    'quantity_max',
                    'unit',
                    'ingredient',
                    'package_quantity',
                    'package_unit',
                    'qualifier',
                    'note',
                ],
                true
            )
            || isset($seenEvidence[$span['field']])
            || !is_string($span['source'] ?? null)
            || !is_string($span['text'] ?? null)
        ) {
            return null;
        }
        if ($span['source'] === 'text') {
            if (
                !is_string($result['source_text'] ?? null)
                || !is_int($span['start'] ?? null)
                || !is_int($span['end'] ?? null)
                || $span['start'] < 0
                || $span['end'] <= $span['start']
                || $span['end'] > strlen($result['source_text'])
                || substr(
                    $result['source_text'],
                    $span['start'],
                    $span['end'] - $span['start']
                ) !== $span['text']
            ) {
                return null;
            }
        } elseif (
            $span['source'] !== 'structured'
            || !in_array(
                $span['field'],
                ['quantity', 'quantity_max', 'package_quantity'],
                true
            )
            || !is_string($span['path'] ?? null)
            || !str_starts_with($span['path'], 'quantity.')
            || ($span['start'] ?? null) !== null
            || ($span['end'] ?? null) !== null
        ) {
            return null;
        }
        $seenEvidence[$span['field']] = $span;
        if (in_array(
            $span['field'],
            ['quantity', 'quantity_max', 'package_quantity'],
            true
        )) {
            $numericEvidence[$span['field']] = true;
        }
    }
    foreach (['quantity', 'quantity_max', 'package_quantity'] as $field) {
        if (
            (($result[$field] ?? null) !== null)
            !== isset($numericEvidence[$field])
        ) {
            return null;
        }
        if (($result[$field] ?? null) === null) {
            continue;
        }
        $span = $seenEvidence[$field];
        if ($span['source'] === 'text') {
            $proven = recipeQuantityParseNumberToken(
                $span['text'],
                (string)($result['locale'] ?? 'und')
            );
            if (
                $proven === null
                || abs($proven - (float)$result[$field]) > 0.0000001
            ) {
                return null;
            }
        } elseif (
            !is_numeric($span['text'])
            || abs((float)$span['text'] - (float)$result[$field])
                > 0.0000001
        ) {
            return null;
        }
    }
    foreach (['unit', 'package_unit'] as $field) {
        if (!isset($seenEvidence[$field])) {
            continue;
        }
        if (
            $result[$field] === null
            || recipeQuantityCanonicalUnit(
                $seenEvidence[$field]['text']
            ) !== $result[$field]
        ) {
            return null;
        }
    }
    if ($result['provenance'] === 'deterministic_grammar') {
        if (
            $result['parser_version'] !== RECIPE_QUANTITY_PARSER_VERSION
            || !is_string($result['source_text'])
            || !is_string($result['locale'])
            || $result['status'] === 'structured'
        ) {
            return null;
        }
        $expected = recipeQuantityParseText(
            $result['source_text'],
            $result['locale']
        );
        return recipeQuantityResultsSemanticallyEqual($result, $expected)
            ? $result
            : null;
    }
    if ($result['provenance'] === 'model_proposal') {
        return recipeQuantityModelResultSemanticsAreValid($result)
            ? $result
            : null;
    }
    if ($result['provenance'] !== 'cookidoo_structured') {
        return null;
    }
    if (
        $result['parser_version'] !== RECIPE_QUANTITY_STRUCTURED_VERSION
        || $result['source_text'] !== null
        || $result['package_quantity'] !== null
        || $result['package_unit'] !== null
        || $result['approximate'] !== false
        || $result['qualifier'] !== null
        || $result['note'] !== null
        || !in_array(
            $result['status'],
            ['structured', 'not_present', 'unparsed'],
            true
        )
    ) {
        return null;
    }
    if ($result['status'] !== 'structured') {
        return (
            $result['quantity'] === null
            && $result['quantity_max'] === null
            && $result['unit'] === null
            && $result['unit_raw'] === null
            && $result['evidence_spans'] === []
        ) ? $result : null;
    }
    if ($result['quantity'] === null) {
        return null;
    }
    $canonicalStructuredUnit = $result['unit_raw'] !== null
        ? recipeQuantityCanonicalUnit($result['unit_raw'])
        : null;
    if ($canonicalStructuredUnit !== $result['unit']) {
        return null;
    }
    if ($result['quantity_max'] === null) {
        return (
            count($seenEvidence) === 1
            && ($seenEvidence['quantity']['source'] ?? null)
                === 'structured'
            && ($seenEvidence['quantity']['path'] ?? null)
                === 'quantity.value'
            && ($seenEvidence['quantity']['text'] ?? null)
                === recipeQuantityFormatNumber(
                    (float)$result['quantity']
                )
        ) ? $result : null;
    }
    return (
        count($seenEvidence) === 2
        && ($seenEvidence['quantity']['source'] ?? null) === 'structured'
        && ($seenEvidence['quantity']['path'] ?? null) === 'quantity.from'
        && ($seenEvidence['quantity']['text'] ?? null)
            === recipeQuantityFormatNumber((float)$result['quantity'])
        && ($seenEvidence['quantity_max']['source'] ?? null)
            === 'structured'
        && ($seenEvidence['quantity_max']['path'] ?? null) === 'quantity.to'
        && ($seenEvidence['quantity_max']['text'] ?? null)
            === recipeQuantityFormatNumber((float)$result['quantity_max'])
    ) ? $result : null;
}

function recipeQuantityDecodePersistedResult(
    mixed $value,
    string $rawText,
    string $locale,
    mixed $storedVersion
): ?array {
    $rawText = recipeQuantityBoundedText(
        $rawText,
        RECIPE_QUANTITY_MAX_TEXT_LENGTH
    );
    if ($rawText === null) {
        return null;
    }
    $locale = recipeQuantityNormalizeLocale($locale);
    $decoded = recipeQuantityDecodeResult($value);
    if (
        $decoded !== null
        && $decoded['provenance'] === 'deterministic_grammar'
    ) {
        if (
            $decoded['parser_version'] === RECIPE_QUANTITY_PARSER_VERSION
            && $storedVersion === RECIPE_QUANTITY_PARSER_VERSION
            && $decoded['source_text'] === $rawText
        ) {
            return $decoded;
        }
        return recipeQuantityParseText(
            $rawText,
            is_string($decoded['locale'] ?? null)
                ? $decoded['locale']
                : $locale
        );
    }
    if (
        !is_string($value)
        || $value === ''
        || strlen($value) > RECIPE_QUANTITY_MAX_RESULT_BYTES
    ) {
        return null;
    }
    $stale = json_decode($value, true);
    if (
        !is_array($stale)
        || ($stale['provenance'] ?? null) !== 'deterministic_grammar'
        || !is_string($stale['parser_version'] ?? null)
        || $stale['parser_version'] === RECIPE_QUANTITY_PARSER_VERSION
    ) {
        return null;
    }
    $staleLocale = is_string($stale['locale'] ?? null)
        && recipeQuantityNormalizeLocale($stale['locale'])
            === $stale['locale']
        && $stale['locale'] !== 'und'
        ? $stale['locale']
        : $locale;
    return recipeQuantityParseText($rawText, $staleLocale);
}

function recipeIngredientParseQuantity(
    mixed $quantity,
    ?string $unit = null
): array {
    $unit = $unit !== null ? trim($unit) : null;
    if (is_bool($quantity)) {
        throw new InvalidArgumentException(
            'recipe ingredient quantity must be a positive finite number'
        );
    }
    if (is_int($quantity) || is_float($quantity)) {
        $number = (float)$quantity;
        if (
            !is_finite($number)
            || $number <= 0
            || $number > 1e9
        ) {
            throw new InvalidArgumentException(
                'recipe ingredient quantity must be a positive finite number'
            );
        }
        return [
            'quantity' => $number,
            'quantity_max' => null,
            'quantity_text' => (string)$quantity,
            'unit' => $unit !== '' ? $unit : null,
        ];
    }
    if ($quantity !== null && !is_string($quantity)) {
        throw new InvalidArgumentException(
            'recipe ingredient quantity must be numeric text'
        );
    }
    $text = trim((string)($quantity ?? ''));
    if ($text === '') {
        return [
            'quantity' => null,
            'quantity_max' => null,
            'quantity_text' => null,
            'unit' => $unit !== '' ? $unit : null,
        ];
    }
    $number = recipeQuantityNumberPattern();
    $unitPattern = recipeQuantityUnitPattern();
    $parsedQuantity = null;
    $parsedQuantityMax = null;
    $parsedUnit = null;
    if (preg_match(
        '/^(?<quantity>' . $number . ')'
            . '(?:\s*(?:-|–|—|to)\s*'
            . '(?<quantity_max>' . $number . '))?'
            . '(?:\s*(?<unit>' . $unitPattern . ')'
            . '(?![\p{L}\p{N}])\.?)?\s*$/iu',
        $text,
        $match,
        PREG_UNMATCHED_AS_NULL
    )) {
        $parsedQuantity = recipeQuantityParseNumberToken(
            (string)$match['quantity']
        );
        $parsedQuantityMax = $match['quantity_max'] !== null
            ? recipeQuantityParseNumberToken(
                (string)$match['quantity_max'],
                'und'
            )
            : null;
        if (
            $parsedQuantity === null
            || (
                $match['quantity_max'] !== null
                && (
                    $parsedQuantityMax === null
                    || $parsedQuantityMax < $parsedQuantity
                )
            )
        ) {
            $parsedQuantity = null;
            $parsedQuantityMax = null;
        } elseif ($match['unit'] !== null) {
            $parsedUnit = recipeQuantityCanonicalUnit(
                (string)$match['unit']
            );
        }
    }
    if ($parsedQuantity === null && is_numeric($text)) {
        throw new InvalidArgumentException(
            'recipe ingredient quantity is invalid or out of range'
        );
    }
    return [
        'quantity' => $parsedQuantity,
        'quantity_max' => $parsedQuantityMax,
        'quantity_text' => $text,
        'unit' => $unit !== null && $unit !== ''
            ? $unit
            : $parsedUnit,
    ];
}
