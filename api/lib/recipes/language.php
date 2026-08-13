<?php
declare(strict_types=1);

const RECIPE_COOKIDOO_LANGUAGE_DETECTOR_VERSION =
    'cookidoo-content-language-v2';
const RECIPE_COOKIDOO_LANGUAGE_MAX_SCRIPT_HITS = 10000;

class RecipeCookidooLanguageRejectedException
    extends RuntimeException {
}

function recipeCookidooLanguageFoldMap(): array {
    return [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'ď' => 'd', 'đ' => 'd',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e',
        'ē' => 'e', 'ę' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
        'ł' => 'l', 'ĺ' => 'l', 'ľ' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ō' => 'o',
        'ř' => 'r',
        'ś' => 's', 'š' => 's', 'ș' => 's', 'ş' => 's',
        'ť' => 't', 'ț' => 't', 'ţ' => 't',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ū' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
    ];
}

function recipeCookidooLanguageRules(): array {
    return [
        'english' => [
            'water', 'salt', 'sugar', 'flour', 'milk', 'egg', 'eggs',
            'onion', 'garlic', 'oil', 'pepper', 'cream', 'cheese',
            'butter', 'chicken', 'beef', 'pork', 'tomato', 'potato',
            'carrot', 'tablespoon', 'tablespoons', 'teaspoon',
            'teaspoons',
        ],
        'foreign' => [
            'de' => [
                'wasser', 'salz', 'zucker', 'mehl', 'milch', 'eier',
                'zwiebel', 'knoblauch', 'oel', 'pfeffer', 'sahne',
                'kaese', 'haehnchen', 'huehnchen', 'gemuese',
                'kartoffeln', 'karotten', 'essloeffel', 'teeloeffel',
            ],
            'fr' => [
                'eau', 'sucre', 'farine', 'lait', 'oeuf', 'oeufs',
                'oignon', 'ail', 'huile', 'poivre', 'creme', 'fromage',
                'beurre', 'poulet', 'legumes', 'carottes', 'cuillere',
            ],
            'it' => [
                'acqua', 'zucchero', 'farina', 'latte', 'uovo', 'uova',
                'cipolla', 'aglio', 'olio', 'pepe', 'panna', 'formaggio',
                'burro', 'pollo', 'verdure', 'patate', 'carote',
                'cucchiaio', 'cucchiaino',
            ],
            'es' => [
                'agua', 'azucar', 'harina', 'leche', 'huevo', 'huevos',
                'cebolla', 'ajo', 'aceite', 'pimienta', 'nata', 'queso',
                'mantequilla', 'pollo', 'verduras', 'patatas',
                'zanahoria', 'cucharada', 'cucharadita',
            ],
            'pt' => [
                'agua', 'acucar', 'farinha', 'leite', 'ovo', 'ovos',
                'cebola', 'alho', 'azeite', 'oleo', 'pimenta', 'natas',
                'queijo', 'manteiga', 'frango', 'legumes', 'batata',
                'cenoura', 'colher',
            ],
            'nl' => [
                'zout', 'suiker', 'bloem', 'melk', 'eieren', 'knoflook',
                'olie', 'peper', 'room', 'kaas', 'boter', 'kip',
                'groenten', 'aardappel', 'wortel', 'eetlepel',
                'theelepel',
            ],
            'ro' => [
                'apa', 'sare', 'zahar', 'faina', 'lapte', 'oua',
                'ceapa', 'usturoi', 'ulei', 'piper', 'smantana',
                'branza', 'unt', 'pui', 'legume', 'cartofi', 'morcov',
            ],
            'pl' => [
                'woda', 'sol', 'cukier', 'maka', 'mleko', 'jajko',
                'jajka', 'cebula', 'czosnek', 'olej', 'pieprz',
                'smietana', 'ser', 'maslo', 'kurczak', 'warzywa',
                'ziemniaki', 'marchew',
            ],
            'vi' => [
                'nuoc', 'muoi', 'duong', 'bot', 'sua', 'trung', 'hanh',
                'toi', 'dau', 'tieu', 'kem', 'pho', 'mai', 'bo', 'ga',
                'rau', 'khoai',
            ],
        ],
    ];
}

function recipeCookidooLanguageRulesHash(): string {
    return hash(
        'sha256',
        json_encode(
            [
                'rules' => recipeCookidooLanguageRules(),
                'fold' => recipeCookidooLanguageFoldMap(),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: ''
    );
}

function recipeCookidooLanguageAscii(string $value): string {
    $value = mb_strtolower($value, 'UTF-8');
    $value = strtr($value, recipeCookidooLanguageFoldMap());
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function recipeCookidooLanguageScriptStats(string $value): array {
    $scriptHits = preg_match_all(
        '/[\x{0370}-\x{03FF}\x{0400}-\x{052F}'
            . '\x{0590}-\x{08FF}\x{0E00}-\x{0E7F}'
            . '\x{3040}-\x{30FF}\x{3400}-\x{9FFF}'
            . '\x{AC00}-\x{D7AF}]/u',
        $value,
        $matches
    );
    $letterCount = preg_match_all('/\p{L}/u', $value, $letters);
    $scriptHits = is_int($scriptHits) ? $scriptHits : 0;
    $letterCount = is_int($letterCount) ? $letterCount : 0;
    return [
        'hits' => $scriptHits,
        'ratio' => $letterCount > 0
            ? $scriptHits / $letterCount
            : 0.0,
    ];
}

function recipeCookidooLanguageIngredientText(mixed $ingredient): string {
    if (is_string($ingredient)) {
        return mb_substr(trim($ingredient), 0, 240, 'UTF-8');
    }
    if (!is_array($ingredient)) {
        return '';
    }
    foreach ([
        'name',
        'source_text',
        'raw_text',
        'normalized_name',
        'source_default_title',
    ] as $key) {
        $value = trim((string)($ingredient[$key] ?? ''));
        if ($value !== '') {
            return mb_substr($value, 0, 240, 'UTF-8');
        }
    }
    return '';
}

function recipeCookidooContentLanguageAssessment(
    string $title,
    array $ingredients
): array {
    $parts = [mb_substr(trim($title), 0, 400, 'UTF-8')];
    foreach (array_slice($ingredients, 0, 200) as $ingredient) {
        $value = recipeCookidooLanguageIngredientText($ingredient);
        if ($value !== '') {
            $parts[] = $value;
        }
    }
    $sourceText = implode("\n", $parts);
    $contentHash = hash('sha256', $sourceText);
    $script = recipeCookidooLanguageScriptStats($sourceText);
    $scriptHits = (int)$script['hits'];
    $tokens = array_values(array_filter(explode(
        ' ',
        recipeCookidooLanguageAscii($sourceText)
    )));
    $tokenSet = array_fill_keys($tokens, true);
    $rules = recipeCookidooLanguageRules();
    $englishHits = 0;
    foreach ($rules['english'] as $marker) {
        if (isset($tokenSet[$marker])) {
            $englishHits++;
        }
    }
    $foreignHits = 0;
    $foreignLanguage = null;
    foreach ($rules['foreign'] as $language => $markers) {
        $hits = 0;
        foreach ($markers as $marker) {
            if (isset($tokenSet[$marker])) {
                $hits++;
            }
        }
        if ($hits > $foreignHits) {
            $foreignHits = $hits;
            $foreignLanguage = $language;
        }
    }
    if ($scriptHits >= 2 && (float)$script['ratio'] >= 0.15) {
        $verdict = 'non_english';
        $reason = 'foreign_script';
    } elseif (
        $foreignHits >= 2
        && $foreignHits > $englishHits
    ) {
        $verdict = 'non_english';
        $reason = 'foreign_markers';
    } elseif ($englishHits >= 2 && $foreignHits === 0) {
        $verdict = 'english';
        $reason = 'english_markers';
    } else {
        $verdict = 'undetermined';
        $reason = 'insufficient_evidence';
    }
    return [
        'content_hash' => $contentHash,
        'verdict' => $verdict,
        'reason' => $reason,
        'foreign_language' => $foreignLanguage,
        'english_hits' => $englishHits,
        'foreign_hits' => $foreignHits,
        'script_hits' => min(
            $scriptHits,
            RECIPE_COOKIDOO_LANGUAGE_MAX_SCRIPT_HITS
        ),
        'token_count' => count($tokens),
        'detector_version' =>
            RECIPE_COOKIDOO_LANGUAGE_DETECTOR_VERSION,
        'rules_hash' => recipeCookidooLanguageRulesHash(),
    ];
}

function recipeCookidooLanguagePolicy(): string {
    $configured = $GLOBALS['RECIPE_COOKIDOO_CONFIG'][
        'COOKIDOO_INGEST_LANGUAGE_POLICY'
    ] ?? env('COOKIDOO_INGEST_LANGUAGE_POLICY', 'enforce');
    $policy = strtolower(trim((string)$configured));
    return in_array($policy, ['off', 'observe', 'enforce'], true)
        ? $policy
        : 'enforce';
}

function recipeCookidooLanguageEnforce(array $assessment): void {
    if (
        recipeCookidooLanguagePolicy() === 'enforce'
        && ($assessment['verdict'] ?? '') === 'non_english'
    ) {
        throw new RecipeCookidooLanguageRejectedException(
            'Cookidoo recipe content language is not English'
        );
    }
}

function recipeCookidooLanguageDefaultDisposition(
    array $assessment
): string {
    return ($assessment['verdict'] ?? '') === 'english'
        ? 'allow'
        : 'review';
}

function recipeCookidooLanguageAssessmentStore(
    PDO $db,
    int $recipeId,
    array $assessment,
    ?string $disposition = null,
    bool $manualOverride = false
): array {
    if ($recipeId <= 0) {
        throw new InvalidArgumentException(
            'recipe language assessment identity is invalid'
        );
    }
    $disposition ??=
        recipeCookidooLanguageDefaultDisposition($assessment);
    if (!in_array(
        $disposition,
        ['allow', 'review', 'quarantine'],
        true
    )) {
        throw new InvalidArgumentException(
            'recipe language disposition is invalid'
        );
    }
    $previous = recipeCookidooLanguageAssessmentRow(
        $db,
        $recipeId
    );
    $effectiveDisposition = $disposition;
    if (
        (int)($previous['manual_override'] ?? 0) === 1
        || (
            ($previous['disposition'] ?? null) === 'quarantine'
            && $disposition !== 'quarantine'
        )
    ) {
        $effectiveDisposition =
            (string)$previous['disposition'];
    }
    $effectiveManualOverride = $manualOverride
        || (int)($previous['manual_override'] ?? 0) === 1;
    if ($previous !== null) {
        $same = hash_equals(
            (string)$previous['content_hash'],
            (string)$assessment['content_hash']
        )
            && (string)$previous['verdict']
                === (string)$assessment['verdict']
            && (string)$previous['disposition']
                === $effectiveDisposition
            && (string)$previous['reason']
                === (string)$assessment['reason']
            && ($previous['foreign_language'] ?? null)
                === ($assessment['foreign_language'] ?? null)
            && (int)$previous['english_hits']
                === (int)$assessment['english_hits']
            && (int)$previous['foreign_hits']
                === (int)$assessment['foreign_hits']
            && (int)$previous['script_hits']
                === (int)$assessment['script_hits']
            && (int)$previous['token_count']
                === (int)$assessment['token_count']
            && (string)$previous['detector_version']
                === (string)$assessment['detector_version']
            && hash_equals(
                (string)$previous['rules_hash'],
                (string)$assessment['rules_hash']
            )
            && (int)$previous['manual_override']
                === ($effectiveManualOverride ? 1 : 0);
        if ($same) {
            return [
                'previous' => $previous,
                'current' => $previous,
                'disposition_changed' => false,
                'visibility_changed' => false,
            ];
        }
    }
    $stmt = $db->prepare("
        INSERT INTO recipe_cookidoo_language_assessments (
            recipe_id, connector, content_hash, verdict, disposition,
            reason, foreign_language, english_hits, foreign_hits,
            script_hits, token_count, detector_version, rules_hash,
            manual_override, evaluated_at, updated_at
        )
        VALUES (?, 'cookidoo', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON CONFLICT(recipe_id) DO UPDATE SET
            content_hash = excluded.content_hash,
            verdict = excluded.verdict,
            disposition = CASE
                WHEN recipe_cookidoo_language_assessments.manual_override = 1
                THEN recipe_cookidoo_language_assessments.disposition
                WHEN recipe_cookidoo_language_assessments.disposition =
                    'quarantine'
                 AND excluded.disposition <> 'quarantine'
                THEN recipe_cookidoo_language_assessments.disposition
                ELSE excluded.disposition
            END,
            reason = excluded.reason,
            foreign_language = excluded.foreign_language,
            english_hits = excluded.english_hits,
            foreign_hits = excluded.foreign_hits,
            script_hits = excluded.script_hits,
            token_count = excluded.token_count,
            detector_version = excluded.detector_version,
            rules_hash = excluded.rules_hash,
            manual_override = CASE
                WHEN excluded.manual_override = 1 THEN 1
                ELSE recipe_cookidoo_language_assessments.manual_override
            END,
            evaluated_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $recipeId,
        (string)$assessment['content_hash'],
        (string)$assessment['verdict'],
        $disposition,
        (string)$assessment['reason'],
        $assessment['foreign_language'] ?? null,
        (int)$assessment['english_hits'],
        (int)$assessment['foreign_hits'],
        (int)$assessment['script_hits'],
        (int)$assessment['token_count'],
        (string)$assessment['detector_version'],
        (string)$assessment['rules_hash'],
        $effectiveManualOverride ? 1 : 0,
    ]);
    $current = recipeCookidooLanguageAssessmentRow(
        $db,
        $recipeId
    );
    $wasQuarantined =
        ($previous['disposition'] ?? null) === 'quarantine';
    $isQuarantined =
        ($current['disposition'] ?? null) === 'quarantine';
    return [
        'previous' => $previous,
        'current' => $current,
        'disposition_changed' =>
            ($previous['disposition'] ?? null)
                !== ($current['disposition'] ?? null),
        'visibility_changed' =>
            $wasQuarantined !== $isQuarantined,
    ];
}

function recipeCookidooLanguageAssessmentRow(
    PDO $db,
    int $recipeId
): ?array {
    $stmt = $db->prepare("
        SELECT *
        FROM recipe_cookidoo_language_assessments
        WHERE recipe_id = ?
    ");
    $stmt->execute([$recipeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function recipeCookidooLanguageAssessmentRestore(
    PDO $db,
    int $recipeId,
    ?array $previous
): void {
    if ($previous === null) {
        $db->prepare("
            DELETE FROM recipe_cookidoo_language_assessments
            WHERE recipe_id = ?
        ")->execute([$recipeId]);
        return;
    }
    $columns = [
        'recipe_id', 'connector', 'content_hash', 'verdict',
        'disposition', 'reason', 'foreign_language', 'english_hits',
        'foreign_hits', 'script_hits', 'token_count',
        'detector_version', 'rules_hash', 'manual_override',
        'evaluated_at', 'updated_at',
    ];
    $quoted = implode(', ', $columns);
    $placeholders = implode(
        ', ',
        array_fill(0, count($columns), '?')
    );
    $updates = implode(
        ', ',
        array_map(
            static fn(string $column): string =>
                "{$column} = excluded.{$column}",
            array_slice($columns, 1)
        )
    );
    $stmt = $db->prepare("
        INSERT INTO recipe_cookidoo_language_assessments ({$quoted})
        VALUES ({$placeholders})
        ON CONFLICT(recipe_id) DO UPDATE SET {$updates}
    ");
    $stmt->execute(array_map(
        static fn(string $column): mixed =>
            $column === 'recipe_id'
                ? $recipeId
                : ($previous[$column] ?? null),
        $columns
    ));
}

function recipeCookidooLanguageVisibilitySql(string $catalogAlias): string {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $catalogAlias)) {
        throw new InvalidArgumentException(
            'recipe catalog alias is invalid'
        );
    }
    return "
        AND NOT EXISTS (
            SELECT 1
            FROM recipe_cookidoo_language_assessments language_visibility
            WHERE language_visibility.recipe_id = {$catalogAlias}.id
              AND language_visibility.connector = 'cookidoo'
              AND language_visibility.disposition = 'quarantine'
        )
    ";
}

function recipeCookidooLanguageRecipeAssessment(
    PDO $db,
    int $recipeId
): array {
    $recipe = $db->prepare("
        SELECT id, title, primary_connector
        FROM recipe_catalog
        WHERE id = ?
        LIMIT 1
    ");
    $recipe->execute([$recipeId]);
    $recipe = $recipe->fetch(PDO::FETCH_ASSOC);
    if (
        !$recipe
        || (string)$recipe['primary_connector']
            !== RECIPE_COOKIDOO_CONNECTOR
    ) {
        throw new InvalidArgumentException(
            'Cookidoo recipe language assessment target is invalid'
        );
    }
    $ingredients = $db->prepare("
        SELECT name AS raw_text, normalized_name
        FROM recipe_source_ingredients
        WHERE recipe_id = ?
        ORDER BY position
        LIMIT 201
    ");
    $ingredients->execute([$recipeId]);
    $rows = $ingredients->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $ingredients = $db->prepare("
            SELECT raw_text, normalized_name
            FROM recipe_ingredients
            WHERE recipe_id = ?
            ORDER BY position
            LIMIT 201
        ");
        $ingredients->execute([$recipeId]);
        $rows = $ingredients->fetchAll(PDO::FETCH_ASSOC);
    }
    return recipeCookidooContentLanguageAssessment(
        (string)$recipe['title'],
        $rows
    );
}
