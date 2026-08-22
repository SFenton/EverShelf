<?php
declare(strict_types=1);

const INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION =
    'full-resolution-v3';
const INGREDIENT_ONTOLOGY_V3_RESOLUTION_BASE_MANIFEST_VERSION =
    'full-resolution-v2';
const INGREDIENT_ONTOLOGY_V3_RESOLUTION_REVIEWER =
    'operator-corrective-review-v3';
const INGREDIENT_ONTOLOGY_V3_RESOLUTION_BATCH =
    'full-ontology-resolution-v3';
const INGREDIENT_ONTOLOGY_V3_COHORT_ALGORITHM =
    'accepted-language-alias-votes-v1';
const INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_FILENAME =
    'resolution-gold-adjudicated.csv';
const INGREDIENT_ONTOLOGY_V3_GOLD_RETIREMENTS_FILENAME =
    'gold-prior-case-retirements.csv';
const INGREDIENT_ONTOLOGY_V3_GOLD_RETIREMENTS_SHA256 =
    '225eafe3c67c15bc1afd2af311eb4a5b0ff77e344d68cf45515a4be994f712e4';
const INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_SHA256 =
    '976833b562c3e0f87dcb3299b0477f8f9736f91fcc358638ae67a9df993168ea';

function ingredientOntologyV3ResolutionDataDirectory(): string {
    return __DIR__ . '/data/' .
        INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION;
}

function ingredientOntologyV3ResolutionBaseDataDirectory(): string {
    return __DIR__ . '/data/' .
        INGREDIENT_ONTOLOGY_V3_RESOLUTION_BASE_MANIFEST_VERSION;
}

function ingredientOntologyV3ResolutionManifestPath(): string {
    return ingredientOntologyV3ResolutionDataDirectory() . '/manifest.json';
}

function ingredientOntologyV3ResolutionFilePath(string $filename): string {
    $filename = basename($filename);
    $overlay = ingredientOntologyV3ResolutionDataDirectory()
        . '/' . $filename;
    if (is_file($overlay)) {
        return $overlay;
    }
    return ingredientOntologyV3ResolutionBaseDataDirectory()
        . '/' . $filename;
}

function ingredientOntologyV3ResolutionReadJsonFile(string $path): array {
    $raw = file_get_contents($path);
    if ($raw === false || strlen($raw) > 1048576) {
        throw new RuntimeException('ontology resolution JSON is unavailable');
    }
    $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException('ontology resolution JSON is invalid');
    }
    return $value;
}

function ingredientOntologyV3ResolutionReadCsvPath(
    string $path,
    string $filename
): array {
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException(
            "ontology resolution CSV is unavailable: {$filename}"
        );
    }
    try {
        $header = fgetcsv($stream);
        if (!is_array($header) || !$header) {
            throw new RuntimeException(
                "ontology resolution CSV has no header: {$filename}"
            );
        }
        $header = array_map(
            static fn(mixed $value): string => trim((string)$value),
            $header
        );
        $rows = [];
        while (($values = fgetcsv($stream)) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }
            $values = array_pad($values, count($header), '');
            $row = [];
            foreach ($header as $index => $column) {
                $row[$column] = trim((string)($values[$index] ?? ''));
            }
            $rows[] = $row;
        }
        return $rows;
    } finally {
        fclose($stream);
    }
}

function ingredientOntologyV3ResolutionCsvOverrideKey(
    string $filename,
    array $row
): ?string {
    $parts = match ($filename) {
        'prior-accepted-label-transitions.csv' => [
            $row['normalized_label'] ?? '',
        ],
        'provider-local-reviews.csv' => [
            $row['review_key'] ?? '',
        ],
        'provider-terms.csv' => [
            $row['connector'] ?? '',
            $row['metadata_schema_version'] ?? '',
            $row['namespace'] ?? '',
            $row['provider_ref'] ?? '',
        ],
        'product-dispositions.csv' => [
            $row['product_id'] ?? '',
        ],
        'aliases.csv' => [
            $row['language'] ?? '',
            ingredientOntologyV3NormalizeLabel(
                (string)($row['label'] ?? '')
            ),
        ],
        'duplicate-identities.csv' => [
            $row['duplicate_slug'] ?? '',
        ],
        'entity-roles.csv' => [
            $row['slug'] ?? '',
        ],
        'primary-edges.csv', 'edge-reviews.csv' => [
            $row['child_slug'] ?? '',
        ],
        'entity-facet-policies.csv' => [
            $row['entity_slug'] ?? '',
            $row['facet_key'] ?? '',
        ],
        'recipe-semantic-dispositions.csv' => [
            $row['normalized_label'] ?? '',
            $row['language'] ?? '',
            $row['required_cohort'] ?? '',
        ],
        'rule-adjudications.csv' => [
            $row['rule_id'] ?? '',
        ],
        'gold-prior-case-retirements.csv' => [
            $row['prior_case_id'] ?? '',
        ],
        'transition-facet-waivers.csv' => [
            $row['normalized_label'] ?? '',
            $row['facet_key'] ?? '',
            $row['hint_value'] ?? '',
        ],
        'provider-term-facet-waivers.csv' => [
            $row['provider_ref'] ?? '',
            $row['facet_key'] ?? '',
            $row['hint_value'] ?? '',
        ],
        'generic-identity-rationales.csv' => [
            $row['entity_slug'] ?? '',
        ],
        default => [],
    };
    if (!$parts || in_array('', $parts, true)) {
        return null;
    }
    return implode("\n", array_map('strval', $parts));
}

function ingredientOntologyV3ResolutionCsvRows(
    string $filename
): Generator {
    $filename = basename($filename);
    $basePath = ingredientOntologyV3ResolutionBaseDataDirectory()
        . '/' . $filename;
    $overlayPath = ingredientOntologyV3ResolutionDataDirectory()
        . '/' . $filename;
    if (!is_file($basePath) || !is_file($overlayPath)) {
        $path = is_file($overlayPath) ? $overlayPath : $basePath;
        foreach (
            ingredientOntologyV3ResolutionReadCsvPath($path, $filename)
            as $row
        ) {
            yield $row;
        }
        return;
    }
    $baseRows = ingredientOntologyV3ResolutionReadCsvPath(
        $basePath,
        $filename
    );
    $overlayRows = ingredientOntologyV3ResolutionReadCsvPath(
        $overlayPath,
        $filename
    );
    $overrides = [];
    foreach ($overlayRows as $row) {
        $key = ingredientOntologyV3ResolutionCsvOverrideKey(
            $filename,
            $row
        );
        if ($key === null || isset($overrides[$key])) {
            throw new RuntimeException(
                "invalid ontology resolution CSV override: {$filename}"
            );
        }
        $overrides[$key] = $row;
    }
    foreach ($baseRows as $row) {
        $key = ingredientOntologyV3ResolutionCsvOverrideKey(
            $filename,
            $row
        );
        if ($key !== null && isset($overrides[$key])) {
            yield $overrides[$key];
            unset($overrides[$key]);
            continue;
        }
        yield $row;
    }
    foreach ($overrides as $row) {
        yield $row;
    }
}

function ingredientOntologyV3ResolutionManifest(): array {
    $manifest = ingredientOntologyV3ResolutionReadJsonFile(
        ingredientOntologyV3ResolutionManifestPath()
    );
    $goldReview = $manifest['gold_review_metadata'] ?? null;
    $matcherGold = $manifest['matcher_gold'] ?? null;
    $reviewedSubjects = $manifest['reviewed_subjects'] ?? null;
    $corpusProfiles = $manifest['corpus_profiles'] ?? null;
    $priorGoldLineage = $manifest['prior_gold_lineage'] ?? null;
    $matcherCaseIds = ingredientOntologyV3MatcherGoldCaseIds();
    if (
        (string)($manifest['manifest_version'] ?? '')
            !== INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION
        || (string)($manifest['reviewer'] ?? '') === ''
        || (string)($manifest['review_batch'] ?? '') === ''
        || (string)($manifest['corrective_version'] ?? '') !== 'v3.17'
        || (string)($manifest['activation_policy'] ?? '')
            !== 'manual_review'
        || trim((string)($manifest['activation_block_reason'] ?? '')) === ''
        || !is_array($manifest['files'] ?? null)
        || !is_array($goldReview)
        || (string)($goldReview['status'] ?? '')
            !== 'maintainer_adjudicated'
        || (string)($goldReview['reviewer'] ?? '') === ''
        || (string)($goldReview['reviewed_at'] ?? '') === ''
        || (string)($goldReview['confidence_limitation'] ?? '') === ''
        || (int)($goldReview['base_positive_case_count'] ?? 0) !== 60
        || (int)($goldReview['base_negative_case_count'] ?? 0) !== 50
        || !is_array($matcherGold)
        || !hash_equals(
            INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_SHA256,
            (string)($matcherGold['sha256'] ?? '')
        )
        || !hash_equals(
            INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_IDS_SHA256,
            (string)($matcherGold['case_ids_sha256'] ?? '')
        )
        || (int)($matcherGold['case_count'] ?? 0)
            !== INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_COUNT
        || ($matcherGold['case_ids'] ?? null) !== $matcherCaseIds
        || !hash_equals(
            INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_IDS_SHA256,
            ingredientOntologyV3Hash($matcherCaseIds)
        )
        || !is_array($reviewedSubjects)
        || (int)($reviewedSubjects['product_count'] ?? 0) !== 174
        || (int)($reviewedSubjects['provider_term_review_count'] ?? 0)
            !== 646
        || !preg_match(
            '/^[a-f0-9]{64}$/',
            (string)(
                $reviewedSubjects['product_fingerprint_set_hash'] ?? ''
            )
        )
        || !preg_match(
            '/^[a-f0-9]{64}$/',
            (string)($reviewedSubjects['provider_term_set_hash'] ?? '')
        )
        || !is_array($corpusProfiles)
        || array_keys($corpusProfiles) !== ['eval', 'provider', 'production']
        || !is_array($priorGoldLineage)
        || (int)($priorGoldLineage['prior_case_count'] ?? 0) !== 465
        || (int)($priorGoldLineage['retained_case_count'] ?? 0) !== 68
        || (int)($priorGoldLineage['superseded_case_count'] ?? 0) !== 35
        || (int)($priorGoldLineage['retired_case_count'] ?? 0) !== 362
        || !hash_equals(
            INGREDIENT_ONTOLOGY_V3_GOLD_RETIREMENTS_SHA256,
            (string)(
                $priorGoldLineage['retirements_sha256'] ?? ''
            )
        )
    ) {
        throw new RuntimeException('ontology resolution manifest is invalid');
    }
    foreach (
        [
            'eval' => [174, 33852, 402284, 0, 0, true],
            'provider' => [174, 33852, 402284, 3100, 646, false],
            'production' => [174, 34526, 409839, 0, 0, true],
        ] as $profile => $expected
    ) {
        $definition = $corpusProfiles[$profile] ?? null;
        if (
            !is_array($definition)
            || !preg_match(
                '/^[a-f0-9]{64}$/',
                (string)($definition['frozen_corpus_hash'] ?? '')
            )
            || !preg_match(
                '/^[a-f0-9]{64}$/',
                (string)($definition['provider_term_set_hash'] ?? '')
            )
            || [
                (int)($definition['product_count'] ?? -1),
                (int)($definition['recipe_count'] ?? -1),
                (int)($definition['recipe_ingredient_count'] ?? -1),
                (int)(
                    $definition['recipe_source_ingredient_count'] ?? -1
                ),
                (int)($definition['provider_term_count'] ?? -1),
                (bool)(
                    $definition['unused_provider_reviews_allowed'] ?? null
                ),
            ] !== $expected
        ) {
            throw new RuntimeException(
                "ontology corpus profile is invalid: {$profile}"
            );
        }
    }
    $matcherGoldPath = dirname(__DIR__, 3)
        . '/tests/fixtures/ingredient_ontology_v3_gold.json';
    $matcherGoldHash = is_file($matcherGoldPath)
        ? hash_file('sha256', $matcherGoldPath)
        : false;
    if (
        !is_string($matcherGoldHash)
        || !hash_equals(
            INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_SHA256,
            $matcherGoldHash
        )
    ) {
        throw new RuntimeException(
            'matcher gold fixture hash changed'
        );
    }
    $baseManifestPath = ingredientOntologyV3ResolutionBaseDataDirectory()
        . '/manifest.json';
    $baseManifestHash = hash_file('sha256', $baseManifestPath);
    if (
        !is_string($baseManifestHash)
        || !hash_equals(
            (string)($manifest['base_manifest_sha256'] ?? ''),
            $baseManifestHash
        )
    ) {
        throw new RuntimeException(
            'ontology resolution base manifest hash changed'
        );
    }
    $baseManifest = ingredientOntologyV3ResolutionReadJsonFile(
        $baseManifestPath
    );
    if (
        (string)($baseManifest['manifest_version'] ?? '')
            !== INGREDIENT_ONTOLOGY_V3_RESOLUTION_BASE_MANIFEST_VERSION
        || !is_array($baseManifest['files'] ?? null)
    ) {
        throw new RuntimeException(
            'ontology resolution base manifest is invalid'
        );
    }
    $baseFileHashes = [];
    foreach ($baseManifest['files'] as $filename => $expectedHash) {
        $filename = basename((string)$filename);
        $path = ingredientOntologyV3ResolutionBaseDataDirectory()
            . '/' . $filename;
        $actualHash = is_file($path)
            ? hash_file('sha256', $path)
            : false;
        if (
            !is_string($actualHash)
            || !is_string($expectedHash)
            || !hash_equals($expectedHash, $actualHash)
        ) {
            throw new RuntimeException(
                "ontology resolution base file hash changed: {$filename}"
            );
        }
        $baseFileHashes[$filename] = $actualHash;
    }
    ksort($baseFileHashes, SORT_STRING);
    $fileHashes = [];
    foreach ($manifest['files'] as $filename => $expectedHash) {
        $filename = basename((string)$filename);
        $path = ingredientOntologyV3ResolutionFilePath($filename);
        if (!is_file($path)) {
            throw new RuntimeException(
                "ontology resolution manifest file is missing: {$filename}"
            );
        }
        $actualHash = hash_file('sha256', $path);
        if (!is_string($actualHash) || strlen($actualHash) !== 64) {
            throw new RuntimeException(
                "ontology resolution manifest hash failed: {$filename}"
            );
        }
        if (
            !is_string($expectedHash)
            || strlen($expectedHash) !== 64
            || !hash_equals($expectedHash, $actualHash)
        ) {
            throw new RuntimeException(
                "ontology resolution manifest hash changed: {$filename}"
            );
        }
        $fileHashes[$filename] = $actualHash;
    }
    ksort($fileHashes, SORT_STRING);
    $manifest['file_hashes'] = $fileHashes;
    $manifest['base_manifest_hash'] = $baseManifestHash;
    $manifest['base_file_hashes'] = $baseFileHashes;
    $manifest['content_hash'] = ingredientOntologyV3Hash([
        'base_manifest_hash' => $baseManifestHash,
        'base_file_hashes' => $baseFileHashes,
        'overlay_file_hashes' => $fileHashes,
    ]);
    $manifest['manifest_hash'] = hash_file(
        'sha256',
        ingredientOntologyV3ResolutionManifestPath()
    );
    return $manifest;
}

function ingredientOntologyV3ResolutionReviewerLineageAllowed(
    string $reviewer
): bool {
    return in_array($reviewer, [
        INGREDIENT_ONTOLOGY_V3_RESOLUTION_REVIEWER,
        'operator-corrective-review-v2',
    ], true);
}

function ingredientOntologyV3ResolveCorpusProfile(
    array $options
): string {
    $profile = trim((string)($options['corpus_profile'] ?? ''));
    if ($profile === '') {
        if (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
        ) {
            return 'test';
        }
        throw new InvalidArgumentException(
            'corpus_profile must explicitly select eval, provider, or production'
        );
    }
    if (!in_array(
        $profile,
        ['eval', 'provider', 'production', 'test'],
        true
    )) {
        throw new InvalidArgumentException(
            'corpus_profile must be eval, provider, or production'
        );
    }
    if (
        $profile === 'test'
        && !(
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
        )
    ) {
        throw new InvalidArgumentException(
            'the test corpus profile is test-only'
        );
    }
    return $profile;
}

function ingredientOntologyV3FrozenCorpusProfile(
    string $profile
): array {
    if ($profile === 'test') {
        return [
            'profile' => 'test',
            'enforced' => false,
        ];
    }
    $manifest = ingredientOntologyV3ResolutionManifest();
    $definition = $manifest['corpus_profiles'][$profile] ?? null;
    if (!is_array($definition)) {
        throw new RuntimeException(
            "ontology corpus profile is unavailable: {$profile}"
        );
    }
    return ['profile' => $profile, 'enforced' => true] + $definition;
}

function ingredientOntologyV3FrozenProductFingerprint(
    array $row
): string {
    return ingredientOntologyV3Hash([
        'id' => (int)$row['id'],
        'name' => trim((string)($row['name'] ?? '')),
        'brand' => trim((string)($row['brand'] ?? '')),
        'category' => trim((string)($row['category'] ?? '')),
        'prepared_food' => (int)($row['prepared_food'] ?? 0),
    ]);
}

function ingredientOntologyV3FrozenRecipeFingerprint(
    string $ownerType,
    array $row
): string {
    $sourceText = $ownerType === 'recipe_source_ingredient'
        ? (string)(
            $row['source_label']
                ?? $row['name']
                ?? $row['normalized_name']
                ?? ''
        )
        : (string)(
            $row['source_label']
                ?? $row['raw_text']
                ?? $row['normalized_name']
                ?? ''
        );
    $fingerprint = [
        'id' => (int)$row['id'],
        'recipe_id' => (int)$row['recipe_id'],
        'position' => (int)$row['position'],
        'language' => ingredientOntologyV3NormalizeLanguage(
            (string)($row['language'] ?? 'und')
        ),
        'source_text' => trim($sourceText),
        'normalized_name' =>
            trim((string)($row['normalized_name'] ?? '')),
    ];
    if ($ownerType === 'recipe_source_ingredient') {
        $fingerprint['source_optional'] =
            ($row['source_optional'] ?? null) !== null
                ? (int)$row['source_optional']
                : null;
        $fingerprint['source_ingredient_ref'] = trim(
            (string)($row['source_ingredient_ref'] ?? '')
        );
        $fingerprint['connector'] = trim(
            (string)($row['connector'] ?? 'unknown_legacy_adapter')
        ) ?: 'unknown_legacy_adapter';
        $fingerprint['source_default_title'] = trim(
            (string)($row['source_default_title'] ?? '')
        );
        $fingerprint['metadata_version'] = trim(
            (string)($row['metadata_version'] ?? '')
        );
        $fingerprint['metadata_schema_version'] = trim(
            (string)($row['metadata_schema_version'] ?? '')
        );
        $fingerprint['provider_namespace'] =
            ingredientOntologyV3ProviderNamespace(
                $fingerprint['source_ingredient_ref']
            );
        $fingerprint['source_ref_provenance'] =
            $fingerprint['source_ingredient_ref'] !== ''
                ? 'persisted_source_ingredient_ref'
                : 'unknown_legacy_adapter';
        return ingredientOntologyV3Hash($fingerprint);
    }
    $fingerprint['source_is_required'] =
        ($row['source_is_required'] ?? null) !== null
            ? (int)$row['source_is_required']
            : null;
    $fingerprint['source_is_optional'] =
        ($row['source_is_optional'] ?? null) !== null
            ? (int)$row['source_is_optional']
            : null;
    $fingerprint['requiredness_source'] = trim(
        (string)($row['requiredness_source'] ?? '')
    );
    return ingredientOntologyV3Hash($fingerprint);
}

function ingredientOntologyV3FrozenCorpusHash(
    PDO $db,
    string $profile
): string {
    if ($profile === 'test') {
        return ingredientOntologyV3CorpusHash($db);
    }
    $hash = hash_init('sha256');
    $sources = [
        'products' => [
            'owner_type' => 'product',
            'sql' => "
                SELECT id, name, brand, category, prepared_food
                FROM products
                ORDER BY id
            ",
        ],
        'recipe_ingredients' => [
            'owner_type' => 'recipe_ingredient',
            'sql' => "
                SELECT ingredient.*,
                       COALESCE(
                           NULLIF(ingredient.raw_text, ''),
                           ingredient.normalized_name
                       ) AS source_label,
                       recipe.language
                FROM recipe_ingredients ingredient
                JOIN recipe_catalog recipe
                  ON recipe.id = ingredient.recipe_id
                ORDER BY ingredient.id
            ",
        ],
    ];
    $sources['recipe_source_ingredients'] = [
            'owner_type' => 'recipe_source_ingredient',
            'sql' => "
                SELECT ingredient.*,
                       COALESCE(
                           NULLIF(ingredient.name, ''),
                           ingredient.normalized_name
                       ) AS source_label,
                       recipe.language,
                       COALESCE(
                           NULLIF(origin.connector, ''),
                           NULLIF(recipe.primary_connector, ''),
                           'unknown_legacy_adapter'
                       ) AS connector,
                       COALESCE(origin.metadata_version, '')
                           AS metadata_version,
                       COALESCE(origin.metadata_schema_version, '')
                           AS metadata_schema_version
                FROM recipe_source_ingredients ingredient
                JOIN recipe_catalog recipe
                  ON recipe.id = ingredient.recipe_id
                LEFT JOIN recipe_origins origin
                  ON origin.id = (
                      SELECT candidate.id
                      FROM recipe_origins candidate
                      WHERE candidate.recipe_id = ingredient.recipe_id
                        AND candidate.connector =
                            recipe.primary_connector
                      ORDER BY candidate.id
                      LIMIT 1
                  )
                ORDER BY ingredient.id
            ",
        ];
    foreach ($sources as $table => $source) {
        hash_update($hash, $table . "\n");
        $stmt = $db->query($source['sql']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $fingerprint = $source['owner_type'] === 'product'
                ? ingredientOntologyV3FrozenProductFingerprint($row)
                : ingredientOntologyV3FrozenRecipeFingerprint(
                    $source['owner_type'],
                    $row
                );
            hash_update(
                $hash,
                ingredientOntologyV3Json([
                    'owner_type' => $source['owner_type'],
                    'owner_id' => (int)$row['id'],
                    'fingerprint' => $fingerprint,
                ]) . "\n"
            );
        }
    }
    return hash_final($hash);
}

function ingredientOntologyV3FrozenCorpusAudit(
    PDO $db,
    string $profile
): array {
    $definition = ingredientOntologyV3FrozenCorpusProfile($profile);
    $counts = [
        'product_count' => (int)$db->query(
            'SELECT COUNT(*) FROM products'
        )->fetchColumn(),
        'recipe_count' => (int)$db->query("
            SELECT COUNT(*) FROM recipe_catalog
            WHERE deleted_at IS NULL
        ")->fetchColumn(),
        'recipe_ingredient_count' => (int)$db->query(
            'SELECT COUNT(*) FROM recipe_ingredients'
        )->fetchColumn(),
        'recipe_source_ingredient_count' =>
            ingredientOntologyV3TableExists(
                $db,
                'recipe_source_ingredients'
            ) ? (int)$db->query(
                'SELECT COUNT(*) FROM recipe_source_ingredients'
            )->fetchColumn() : 0,
    ];
    $actualHash = ingredientOntologyV3FrozenCorpusHash(
        $db,
        $profile
    );
    if (empty($definition['enforced'])) {
        return [
            'valid' => true,
            'profile' => $profile,
            'enforced' => false,
            'actual_hash' => $actualHash,
            'expected_hash' => $actualHash,
            'counts' => $counts,
            'expected_counts' => $counts,
        ];
    }
    $expectedCounts = [
        'product_count' => (int)$definition['product_count'],
        'recipe_count' => (int)$definition['recipe_count'],
        'recipe_ingredient_count' =>
            (int)$definition['recipe_ingredient_count'],
        'recipe_source_ingredient_count' =>
            (int)$definition['recipe_source_ingredient_count'],
    ];
    return [
        'valid' => hash_equals(
            (string)$definition['frozen_corpus_hash'],
            $actualHash
        ) && $counts === $expectedCounts,
        'profile' => $profile,
        'enforced' => true,
        'actual_hash' => $actualHash,
        'expected_hash' =>
            (string)$definition['frozen_corpus_hash'],
        'counts' => $counts,
        'expected_counts' => $expectedCounts,
    ];
}

function ingredientOntologyV3ReviewedSubjectSetHashes(): array {
    $manifest = ingredientOntologyV3ResolutionManifest();
    return [
        'product_fingerprint_set_hash' => (string)(
            $manifest['reviewed_subjects']
                ['product_fingerprint_set_hash']
        ),
        'provider_term_set_hash' => (string)(
            $manifest['reviewed_subjects']['provider_term_set_hash']
        ),
    ];
}

function ingredientOntologyV3SubjectUniverseHash(
    string $profile
): string {
    $manifest = ingredientOntologyV3ResolutionManifest();
    return ingredientOntologyV3Hash([
        'profile' => $profile,
        'reviewed_subjects' => $manifest['reviewed_subjects'],
        'profile_definition' =>
            $profile === 'test'
                ? ['enforced' => false]
                : $manifest['corpus_profiles'][$profile],
    ]);
}

function ingredientOntologyV3SubjectUniverseAudit(
    PDO $db,
    int $versionId,
    string $profile
): array {
    if ($profile === 'test') {
        return [
            'valid' => true,
            'profile' => 'test',
            'enforced' => false,
            'product_missing_count' => 0,
            'product_extra_count' => 0,
            'provider_term_missing_count' => 0,
            'provider_term_extra_count' => 0,
            'unused_provider_review_count' => 0,
            'subject_universe_hash' =>
                ingredientOntologyV3SubjectUniverseHash('test'),
        ];
    }
    $manifest = ingredientOntologyV3ResolutionManifest();
    $profileDefinition = $manifest['corpus_profiles'][$profile];
    $reviewProducts = ingredientOntologyV3ResolutionProductReviewMap();
    $reviewProductFingerprints = array_keys($reviewProducts);
    sort($reviewProductFingerprints, SORT_STRING);
    $sourceProductFingerprints = [];
    $products = $db->query("
        SELECT id, name, brand, category, prepared_food
        FROM products
        ORDER BY id
    ");
    while ($product = $products->fetch(PDO::FETCH_ASSOC)) {
        $sourceProductFingerprints[] =
            ingredientOntologyV3ProductOwnerFingerprint($product);
    }
    sort($sourceProductFingerprints, SORT_STRING);
    $productMissing = array_values(array_diff(
        $reviewProductFingerprints,
        $sourceProductFingerprints
    ));
    $productExtra = array_values(array_diff(
        $sourceProductFingerprints,
        $reviewProductFingerprints
    ));
    $reviewTerms = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows('provider-terms.csv')
        as $term
    ) {
        $reviewTerms[] = implode('|', [
            (string)$term['connector'],
            (string)$term['metadata_schema_version'],
            (string)$term['namespace'],
            (string)$term['provider_ref'],
            (string)$term['term_fingerprint'],
        ]);
    }
    sort($reviewTerms, SORT_STRING);
    $sourceTerms = [];
    if (
        ingredientOntologyV3TableExists(
            $db,
            'ingredient_ontology_provider_terms'
        )
    ) {
        $terms = $db->prepare("
            SELECT connector, metadata_schema_version, namespace,
                   provider_ref, title_hash, consistency_state
            FROM ingredient_ontology_provider_terms
            WHERE ontology_version_id = ?
            ORDER BY connector, metadata_schema_version, namespace,
                     provider_ref
        ");
        $terms->execute([$versionId]);
        while ($term = $terms->fetch(PDO::FETCH_ASSOC)) {
            $termFingerprint = ingredientOntologyV3Hash([
                'connector' => (string)$term['connector'],
                'metadata_schema_version' =>
                    (string)$term['metadata_schema_version'],
                'namespace' => (string)$term['namespace'],
                'provider_ref' => (string)$term['provider_ref'],
                'title_hash' => (string)$term['title_hash'],
                'consistency_state' =>
                    (string)$term['consistency_state'],
            ]);
            $sourceTerms[] = implode('|', [
                (string)$term['connector'],
                (string)$term['metadata_schema_version'],
                (string)$term['namespace'],
                (string)$term['provider_ref'],
                $termFingerprint,
            ]);
        }
    }
    sort($sourceTerms, SORT_STRING);
    $expectedTerms = $profile === 'provider' ? $reviewTerms : [];
    $providerMissing = array_values(array_diff(
        $expectedTerms,
        $sourceTerms
    ));
    $providerExtra = array_values(array_diff(
        $sourceTerms,
        $expectedTerms
    ));
    $unusedProviderReviews = $profile === 'provider'
        ? $providerMissing
        : $reviewTerms;
    $actualProductHash =
        ingredientOntologyV3Hash($sourceProductFingerprints);
    $actualProviderHash = ingredientOntologyV3Hash($sourceTerms);
    $unusedAllowed = !empty(
        $profileDefinition['unused_provider_reviews_allowed']
    );
    return [
        'valid' => !$productMissing
            && !$productExtra
            && !$providerMissing
            && !$providerExtra
            && ($unusedAllowed || !$unusedProviderReviews)
            && count($sourceProductFingerprints)
                === (int)$profileDefinition['product_count']
            && count($sourceTerms)
                === (int)$profileDefinition['provider_term_count']
            && hash_equals(
                (string)$manifest['reviewed_subjects']
                    ['product_fingerprint_set_hash'],
                $actualProductHash
            )
            && hash_equals(
                (string)$profileDefinition['provider_term_set_hash'],
                $actualProviderHash
            ),
        'profile' => $profile,
        'enforced' => true,
        'product_count' => count($sourceProductFingerprints),
        'product_set_hash' => $actualProductHash,
        'product_missing_count' => count($productMissing),
        'product_extra_count' => count($productExtra),
        'product_missing_sample' => array_slice($productMissing, 0, 20),
        'product_extra_sample' => array_slice($productExtra, 0, 20),
        'provider_term_count' => count($sourceTerms),
        'provider_term_set_hash' => $actualProviderHash,
        'provider_term_missing_count' => count($providerMissing),
        'provider_term_extra_count' => count($providerExtra),
        'unused_provider_review_count' =>
            count($unusedProviderReviews),
        'unused_provider_reviews_allowed' => $unusedAllowed,
        'provider_term_missing_sample' =>
            array_slice($providerMissing, 0, 20),
        'provider_term_extra_sample' =>
            array_slice($providerExtra, 0, 20),
        'subject_universe_hash' =>
            ingredientOntologyV3SubjectUniverseHash($profile),
    ];
}

function ingredientOntologyV3VersionPolicyHash(
    string $profile,
    string $activationPolicy,
    string $activationBlockReason
): string {
    $manifest = ingredientOntologyV3ResolutionManifest();
    return ingredientOntologyV3Hash([
        'corpus_profile' => $profile,
        'corpus_profile_definition' =>
            $profile === 'test'
                ? ['enforced' => false]
                : $manifest['corpus_profiles'][$profile],
        'reviewed_subjects' => $manifest['reviewed_subjects'],
        'matcher_gold' => $manifest['matcher_gold'],
        'activation_policy' => $activationPolicy,
        'activation_block_reason' => $activationBlockReason,
    ]);
}

function ingredientOntologyV3RegisterResolutionManifest(
    PDO $db,
    int $versionId,
    string $sourceCorpusHash
): array {
    $manifest = ingredientOntologyV3ResolutionManifest();
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_resolution_manifests (
            ontology_version_id, manifest_key, manifest_version,
            manifest_hash, content_hash, source_corpus_hash, reviewer,
            review_batch, metadata_json
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $versionId,
        (string)$manifest['manifest_key'],
        (string)$manifest['manifest_version'],
        (string)$manifest['manifest_hash'],
        (string)$manifest['content_hash'],
        $sourceCorpusHash,
        (string)$manifest['reviewer'],
        (string)$manifest['review_batch'],
        ingredientOntologyV3Json([
            'activation_policy' =>
                (string)($manifest['activation_policy'] ?? 'blocked'),
            'activation_block_reason' => (string)(
                $manifest['activation_block_reason']
                    ?? 'Full ontology resolution remains shadow-only.'
            ),
            'file_hashes' => $manifest['file_hashes'],
            'frozen_sources' => $manifest['frozen_sources'] ?? [],
            'gold_review_metadata' =>
                $manifest['gold_review_metadata'] ?? [],
            'matcher_gold' => $manifest['matcher_gold'] ?? [],
            'reviewed_subjects' =>
                $manifest['reviewed_subjects'] ?? [],
            'corpus_profiles' => $manifest['corpus_profiles'] ?? [],
            'prior_gold_lineage' =>
                $manifest['prior_gold_lineage'] ?? [],
        ]),
    ]);
    $manifest['id'] = (int)$db->lastInsertId();
    return $manifest;
}

function ingredientOntologyV3InsertEvidenceSource(
    PDO $db,
    int $versionId,
    ?int $manifestId,
    string $kind,
    string $key,
    array $payload,
    string $algorithm,
    string $reviewer = INGREDIENT_ONTOLOGY_V3_RESOLUTION_REVIEWER,
    string $batch = INGREDIENT_ONTOLOGY_V3_RESOLUTION_BATCH,
    array $owner = []
): int {
    $payload = ingredientOntologyV3StableValue($payload);
    $scopeHash = ingredientOntologyV3Hash([
        'kind' => $kind,
        'key' => $key,
        'evidence_scope' => (string)(
            $owner['evidence_scope'] ?? 'global_review'
        ),
        'owner_fingerprint' => $owner['owner_fingerprint'] ?? null,
        'connector' => $owner['connector'] ?? null,
        'metadata_schema_version' =>
            $owner['metadata_schema_version'] ?? null,
        'provider_ref' => $owner['provider_ref'] ?? null,
        'title_hash' => $owner['title_hash'] ?? null,
        'observation_hash' => $owner['observation_hash'] ?? null,
    ]);
    $payloadHash = ingredientOntologyV3Hash($payload);
    $algorithmHash = strlen($algorithm) === 64
        && preg_match('/^[a-f0-9]{64}$/', $algorithm)
            ? $algorithm
            : hash('sha256', $algorithm);
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_evidence_sources (
            ontology_version_id, manifest_id, evidence_kind, evidence_key,
            evidence_scope, owner_fingerprint, connector,
            metadata_schema_version, provider_ref, title_hash,
            observation_hash,
            scope_hash, payload_hash, payload_json, algorithm_hash,
            reviewer, review_batch
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $versionId,
        $manifestId,
        $kind,
        mb_substr($key, 0, 240, 'UTF-8'),
        (string)($owner['evidence_scope'] ?? 'global_review'),
        $owner['owner_fingerprint'] ?? null,
        $owner['connector'] ?? null,
        $owner['metadata_schema_version'] ?? null,
        $owner['provider_ref'] ?? null,
        $owner['title_hash'] ?? null,
        $owner['observation_hash'] ?? null,
        $scopeHash,
        $payloadHash,
        ingredientOntologyV3Json($payload),
        $algorithmHash,
        $reviewer,
        $batch,
    ]);
    return (int)$db->lastInsertId();
}

function ingredientOntologyV3SeedManifestEvidence(
    PDO $db,
    int $versionId,
    array $manifest
): array {
    $count = 0;
    $keys = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows('evidence.csv')
        as $row
    ) {
        $payload = json_decode(
            (string)$row['payload_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($payload)) {
            throw new RuntimeException('manifest evidence payload is invalid');
        }
        $kind = (string)$row['evidence_kind'];
        $key = (string)$row['evidence_key'];
        $id = ingredientOntologyV3InsertEvidenceSource(
            $db,
            $versionId,
            (int)$manifest['id'],
            $kind,
            $key,
            [
                'payload' => $payload,
                'rationale' => (string)$row['rationale'],
                'source_manifest_hash' => $manifest['manifest_hash'],
            ],
            'reviewed-frozen-evidence-v1'
        );
        $keys[$kind][$key] = $id;
        $count++;
    }
    return ['count' => $count, 'keys' => $keys];
}

function ingredientOntologyV3ResolutionEntityRole(
    string $kind,
    string $slug
): string {
    $structural = [
        'food', 'ingredient', 'plant-derived', 'animal-derived',
        'prepared-food', 'composite-food', 'herb', 'spice', 'nut',
        'seed', 'legume', 'grain', 'flour-starch', 'sweetener',
        'oil-fat', 'meat', 'poultry', 'seafood', 'dairy',
        'egg-category', 'condiment', 'leavening', 'leavening-agent',
        'thickener', 'sauce', 'stock-broth', 'beverage', 'bakery',
        'snack', 'meal', 'prepared-meal', 'soup', 'salad', 'pizza',
        'dessert', 'vegetable', 'fruit', 'berry', 'leafy-vegetable',
        'nuts', 'tree-nuts', 'meat-alternative',
        'plant-based-meat-alternative',
    ];
    if (in_array($slug, $structural, true)) {
        return 'structural_category';
    }
    if ($slug === 'staple') {
        return 'staple_class';
    }
    return match ($kind) {
        'prepared_food' => 'prepared_identity',
        'composite_food' => 'composite_identity',
        default => 'identity_leaf',
    };
}

function ingredientOntologyV3ResolutionParentSnapshot(
    PDO $db,
    int $versionId
): array {
    $stmt = $db->prepare("
        SELECT c.id AS child_id, p.id AS parent_id, p.slug AS parent_slug
        FROM ingredient_ontology_entities c
        LEFT JOIN ingredient_ontology_relations r
          ON r.ontology_version_id = c.ontology_version_id
         AND r.from_entity_id = c.id
         AND r.relation = 'is_a'
         AND r.is_primary = 1
         AND r.review_state = 'accepted'
        LEFT JOIN ingredient_ontology_entities p ON p.id = r.to_entity_id
        WHERE c.ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result[(int)$row['child_id']] = $row['parent_id'] !== null
            ? (int)$row['parent_id']
            : null;
    }
    return $result;
}

function ingredientOntologyV3ResolutionPreviousVersionParents(
    PDO $db,
    int $versionId,
    array $currentEntities
): ?array {
    $parentVersion = $db->prepare("
        SELECT parent_version_id
        FROM ingredient_ontology_versions
        WHERE id = ?
    ");
    $parentVersion->execute([$versionId]);
    $parentVersionId = (int)($parentVersion->fetchColumn() ?: 0);
    if ($parentVersionId <= 0) {
        return null;
    }
    $currentBySlug = [];
    foreach ($currentEntities as $entity) {
        $currentBySlug[(string)$entity['slug']] = (int)$entity['id'];
    }
    $result = array_fill_keys(array_values($currentBySlug), null);
    $stmt = $db->prepare("
        SELECT child.slug AS child_slug, parent.slug AS parent_slug
        FROM ingredient_ontology_entities child
        LEFT JOIN ingredient_ontology_relations r
          ON r.ontology_version_id = child.ontology_version_id
         AND r.from_entity_id = child.id
         AND r.relation = 'is_a'
         AND r.is_primary = 1
         AND r.review_state = 'accepted'
        LEFT JOIN ingredient_ontology_entities parent
          ON parent.id = r.to_entity_id
        WHERE child.ontology_version_id = ?
    ");
    $stmt->execute([$parentVersionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $childId = $currentBySlug[(string)$row['child_slug']] ?? null;
        if ($childId === null) {
            continue;
        }
        $result[$childId] = $row['parent_slug'] !== null
            ? ($currentBySlug[(string)$row['parent_slug']] ?? null)
            : null;
    }
    return $result;
}

function ingredientOntologyV3ResolutionFallbackParent(
    array $entity
): string {
    $slug = (string)$entity['slug'];
    $herbs = [
        'basil', 'bay-leaf', 'chives', 'cilantro', 'coriander', 'dill',
        'mint', 'oregano', 'parsley', 'rosemary', 'thyme',
    ];
    $spices = [
        'cardamom', 'cayenne-pepper', 'cinnamon', 'clove-spice',
        'cumin', 'curry-powder', 'garam-masala', 'ginger', 'nutmeg',
        'paprika', 'piper-pepper', 'turmeric',
    ];
    $nuts = [
        'almond', 'hazelnut', 'pine-nut', 'walnut',
    ];
    $seeds = [
        'coriander-seed', 'sesame', 'sesame-seed', 'sesame-seeds',
        'sunflower-seed',
    ];
    $legumes = [
        'bean', 'beans', 'black-bean', 'black-beans', 'peanut',
        'peanuts', 'soybean',
    ];
    $fruits = [
        'apple', 'avocado', 'banana', 'blueberry', 'blueberries',
        'cherry', 'coconut', 'jack-fruit', 'lemon', 'lemons', 'lime',
        'mango', 'orange', 'pear', 'pineapple', 'strawberry',
    ];
    $vegetables = [
        'bell-pepper', 'broccoli', 'capsicum', 'carrot', 'cauliflower',
        'celery', 'chilli-pepper', 'cucumber', 'garlic',
        'garlic-cloves', 'green-onion', 'jalapeno-pepper', 'leek',
        'mushroom', 'onion', 'potato', 'shallot', 'spinach',
        'spring-onion', 'sweet-potato', 'tomato', 'tomatoes',
        'vegetables', 'zucchini',
    ];
    $dairy = [
        'brie', 'buttermilk', 'cheddar', 'cheese', 'condensed-milk',
        'cream', 'cream-cheese', 'creamer', 'creme-fraiche',
        'evaporated-milk', 'feta', 'half-and-half', 'heavy-cream',
        'mascarpone', 'milk', 'mozzarella', 'parmesan',
        'pepper-jack-cheese', 'sour-cream', 'swiss-cheese', 'yogurt',
    ];
    if (in_array($slug, $herbs, true)) {
        return 'herb';
    }
    if (in_array($slug, $spices, true)) {
        return 'spice';
    }
    if (in_array($slug, $nuts, true)) {
        return 'nut';
    }
    if (in_array($slug, $seeds, true)) {
        return 'seed';
    }
    if (in_array($slug, $legumes, true)) {
        return 'legume';
    }
    if (in_array($slug, $fruits, true)) {
        return 'fruit';
    }
    if (in_array($slug, $vegetables, true)) {
        return 'vegetable';
    }
    if (in_array($slug, $dairy, true)) {
        return 'dairy';
    }
    return match ((string)$entity['identity_role']) {
        'prepared_identity' => 'prepared-food',
        'composite_identity' => 'composite-food',
        'structural_category' => (
            (string)$entity['entity_kind'] === 'ingredient'
                ? 'ingredient'
                : 'prepared-food'
        ),
        'staple_class' => 'ingredient',
        default => 'ingredient',
    };
}

function ingredientOntologyV3ResolutionSetPrimaryParent(
    PDO $db,
    int $versionId,
    int $childId,
    int $parentId,
    string $provenance,
    string $rationale
): void {
    $db->prepare("
        UPDATE ingredient_ontology_relations
        SET is_primary = 0,
            review_state = CASE
                WHEN review_state = 'accepted' THEN 'quarantined'
                ELSE review_state
            END,
            semantics_json = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE ontology_version_id = ?
          AND from_entity_id = ?
          AND relation = 'is_a'
          AND is_primary = 1
          AND review_state = 'accepted'
          AND to_entity_id <> ?
    ")->execute([
        ingredientOntologyV3Json([
            'superseded_by_resolution_manifest' => true,
            'rationale' => $rationale,
        ]),
        $versionId,
        $childId,
        $parentId,
    ]);
    ingredientOntologyV3InsertRelation(
        $db,
        $versionId,
        $childId,
        $parentId,
        'is_a',
        true,
        false,
        1.0,
        $provenance,
        'accepted',
        'forward',
        [
            'identity_satisfaction_denied' => true,
            'rationale' => $rationale,
        ]
    );
}

function ingredientOntologyV3ResolutionSeedFacetPolicies(
    PDO $db,
    int $versionId,
    array $facetMap,
    int $evidenceSourceId
): int {
    $entities = $db->prepare("
        SELECT id, slug, identity_role
        FROM ingredient_ontology_entities
        WHERE ontology_version_id = ? AND active = 1
        ORDER BY id
    ");
    $entities->execute([$versionId]);
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_entity_facet_policies (
            ontology_version_id, entity_id, facet_id, allowed, defining,
            evidence_source_id, policy_hash, provenance
        )
        VALUES (?, ?, ?, 1, ?, ?, ?, ?)
    ");
    $count = 0;
    while ($entity = $entities->fetch(PDO::FETCH_ASSOC)) {
        $role = (string)$entity['identity_role'];
        if (in_array($role, ['structural_category', 'staple_class'], true)) {
            continue;
        }
        $slug = (string)$entity['slug'];
        foreach ($facetMap as $facet => $definition) {
            $allowed = in_array($facet, [
                'form', 'processing', 'preservation', 'preparation',
                'package_form', 'state', 'size',
            ], true);
            if ($facet === 'plant_part') {
                $allowed = $role === 'identity_leaf';
            } elseif ($facet === 'variety') {
                $allowed = in_array($slug, [
                    'oil', 'rice', 'tomato', 'tomato-paste',
                    'stock', 'vinegar',
                    'chilli-pepper', 'bell-pepper', 'capsicum',
                    'jalapeno-pepper', 'piper-pepper', 'onion',
                    'noodle', 'noodle-soup', 'stock-paste', 'stock-base',
                    'tortilla-chips',
                    'milk-alternative', 'coconut-milk', 'wine',
                ], true);
            } elseif (in_array($facet, ['cut', 'bone', 'skin'], true)) {
                $allowed = in_array($slug, [
                    'chicken', 'beef', 'pork', 'duck', 'lamb', 'turkey',
                ], true);
            } elseif ($facet === 'species') {
                $allowed = in_array($slug, [
                    'chicken', 'beef', 'pork', 'duck', 'lamb', 'turkey',
                    'foie-gras', 'stock', 'stock-paste', 'stock-base',
                    'noodle-soup',
                ], true);
            } elseif ($facet === 'refinement') {
                $allowed = in_array($slug, [
                    'flour', 'sugar', 'starch', 'cornstarch',
                ], true);
            } elseif ($facet === 'filtration') {
                $allowed = in_array($slug, [
                    'milk', 'cream', 'milk-alternative',
                ], true);
            } elseif (in_array(
                $facet,
                ['fat_content', 'cream_class'],
                true
            )) {
                $allowed = in_array($slug, [
                    'milk', 'cream', 'sour-cream', 'creme-fraiche',
                    'yogurt', 'buttermilk', 'brie', 'milk-alternative',
                ], true);
            } elseif ($facet === 'egg_part') {
                $allowed = in_array($slug, [
                    'egg', 'egg-white', 'egg-yolk',
                ], true);
            } elseif ($facet === 'wine_color' || $facet === 'wine_sweetness') {
                $allowed = in_array($slug, ['wine', 'cooking-wine'], true);
            } elseif ($facet === 'chocolate_class') {
                $allowed = $slug === 'chocolate';
            } elseif ($facet === 'salt_class') {
                $allowed = $slug === 'salt';
            } elseif ($facet === 'saltedness') {
                $allowed = in_array($slug, [
                    'butter', 'almond', 'peanut', 'walnut',
                ], true);
            } elseif ($facet === 'sweetening') {
                $allowed = in_array($slug, [
                    'milk', 'condensed-milk', 'coconut-milk', 'chocolate',
                ], true);
            }
            if (!$allowed) {
                continue;
            }
            $defining = !empty($definition['hard']) ? 1 : 0;
            $policy = [
                'entity_id' => (int)$entity['id'],
                'facet' => $facet,
                'allowed' => true,
                'defining' => (bool)$defining,
            ];
            $insert->execute([
                $versionId,
                (int)$entity['id'],
                (int)$definition['id'],
                $defining,
                $evidenceSourceId,
                ingredientOntologyV3Hash($policy),
                INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION,
            ]);
            $count++;
        }
    }
    return $count;
}

function ingredientOntologyV3ApplyResolutionEntities(
    PDO $db,
    int $versionId,
    array $facetMap,
    array $manifest,
    int $policyEvidenceSourceId
): array {
    $roleRows = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows('entity-roles.csv')
        as $row
    ) {
        $slug = ingredientOntologyV3Slug((string)$row['slug']);
        if (isset($roleRows[$slug])) {
            throw new RuntimeException(
                "duplicate explicit entity role: {$slug}"
            );
        }
        $kind = (string)$row['entity_kind'];
        $role = (string)$row['identity_role'];
        if (
            !in_array($kind, [
                'ingredient', 'prepared_food', 'composite_food',
            ], true)
            || !in_array($role, [
                'structural_category', 'identity_leaf',
                'prepared_identity', 'composite_identity', 'staple_class',
            ], true)
            || !ingredientOntologyV3ResolutionReviewerLineageAllowed(
                (string)$row['reviewer']
            )
        ) {
            throw new RuntimeException(
                "invalid explicit entity role review: {$slug}"
            );
        }
        ingredientOntologyV3UpsertEntity(
            $db,
            $versionId,
            'resolution:' . $slug,
            $slug,
            (string)$row['canonical_name'],
            $kind,
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION,
            null,
            null,
            (int)$row['active'] === 1
        );
        $db->prepare("
            UPDATE ingredient_ontology_entities
            SET canonical_name = ?, entity_kind = ?, identity_role = ?,
                active = ?, provenance = ?, updated_at = CURRENT_TIMESTAMP
            WHERE ontology_version_id = ? AND slug = ?
        ")->execute([
            (string)$row['canonical_name'],
            $kind,
            $role,
            (int)$row['active'] === 1 ? 1 : 0,
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION,
            $versionId,
            $slug,
        ]);
        $roleRows[$slug] = $row;
    }
    if (count($roleRows) !== 305) {
        throw new RuntimeException(
            'explicit entity-role manifest must contain 305 rows'
        );
    }
    $actualSlugs = $db->prepare("
        SELECT slug
        FROM ingredient_ontology_entities
        WHERE ontology_version_id = ?
        ORDER BY slug
    ");
    $actualSlugs->execute([$versionId]);
    $actualSlugs = array_map(
        'strval',
        $actualSlugs->fetchAll(PDO::FETCH_COLUMN)
    );
    $manifestSlugs = array_keys($roleRows);
    sort($manifestSlugs, SORT_STRING);
    $missingRoleSlugs = array_values(array_diff(
        $manifestSlugs,
        $actualSlugs
    ));
    $extraRoleSlugs = array_values(array_diff(
        $actualSlugs,
        $manifestSlugs
    ));
    $dynamicVersion = ingredientOntologyV3Version($db, $versionId);
    $dynamicController = $dynamicVersion !== null
        && function_exists(
            'ingredientOntologyControllerUsesDynamicPins'
        )
        && ingredientOntologyControllerUsesDynamicPins($dynamicVersion);
    if ($missingRoleSlugs || ($extraRoleSlugs && !$dynamicController)) {
        throw new RuntimeException(
            'entity-role manifest does not exactly cover the entity set: '
            . ingredientOntologyV3Json([
                'missing' => $missingRoleSlugs,
                'extra' => $extraRoleSlugs,
            ])
        );
    }
    if ($extraRoleSlugs) {
        $placeholders = implode(
            ',',
            array_fill(0, count($extraRoleSlugs), '?')
        );
        $extraRows = $db->prepare("
            SELECT slug, provenance
            FROM ingredient_ontology_entities
            WHERE ontology_version_id = ?
              AND slug IN ({$placeholders})
            ORDER BY slug
        ");
        $extraRows->execute(array_merge(
            [$versionId],
            $extraRoleSlugs
        ));
        $extraRows = $extraRows->fetchAll(PDO::FETCH_ASSOC);
        $unsafeExtras = array_values(array_filter(
            $extraRows,
            static fn(array $row): bool => !in_array(
                (string)$row['provenance'],
                ['legacy_canonical', 'legacy_taxonomy'],
                true
            )
        ));
        if (
            count($extraRows) !== count($extraRoleSlugs)
            || $unsafeExtras
        ) {
            throw new RuntimeException(
                'dynamic entity-role extras are not safe legacy placeholders: '
                . ingredientOntologyV3Json([
                    'expected' => $extraRoleSlugs,
                    'unsafe' => $unsafeExtras,
                ])
            );
        }
        $db->prepare("
            UPDATE ingredient_ontology_entities
            SET identity_role = 'structural_category',
                provenance = 'autonomous_controller',
                updated_at = CURRENT_TIMESTAMP
            WHERE ontology_version_id = ?
              AND slug IN ({$placeholders})
        ")->execute(array_merge(
            [$versionId],
            $extraRoleSlugs
        ));
    }
    $entities = ingredientOntologyV3EntityMap($db, $versionId)['by_slug'];

    $duplicates = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows('duplicate-identities.csv')
        as $row
    ) {
        $duplicate = ingredientOntologyV3Slug(
            (string)$row['duplicate_slug']
        );
        $canonical = ingredientOntologyV3Slug(
            (string)$row['canonical_slug']
        );
        $attributes = json_decode(
            (string)$row['attributes_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        ) ?: [];
        $duplicates[$duplicate] = [
            'canonical' => $canonical,
            'attributes' => $attributes,
            'rationale' => (string)$row['rationale'],
        ];
        if (!isset($entities[$duplicate], $entities[$canonical])) {
            continue;
        }
        $labels = $db->prepare("
            SELECT l.id, l.language, l.label, l.kind,
                   f.facet_key, fv.value_key
            FROM ingredient_ontology_labels l
            LEFT JOIN ingredient_ontology_label_attributes la
              ON la.label_id = l.id
            LEFT JOIN ingredient_ontology_facets f ON f.id = la.facet_id
            LEFT JOIN ingredient_ontology_facet_values fv
              ON fv.id = la.facet_value_id
            WHERE l.ontology_version_id = ?
              AND l.entity_id = ?
              AND l.review_state = 'accepted'
              AND l.kind IN ('exact_alias', 'attribute_alias')
            ORDER BY l.id, f.id
        ");
        $labels->execute([
            $versionId,
            (int)$entities[$duplicate]['id'],
        ]);
        $byLabel = [];
        while ($label = $labels->fetch(PDO::FETCH_ASSOC)) {
            $labelId = (int)$label['id'];
            $byLabel[$labelId] ??= [
                'language' => (string)$label['language'],
                'label' => (string)$label['label'],
                'attributes' => [],
            ];
            if (
                $label['facet_key'] !== null
                && $label['value_key'] !== null
            ) {
                $byLabel[$labelId]['attributes'][
                    (string)$label['facet_key']
                ] = (string)$label['value_key'];
            }
        }
        $db->prepare("
            UPDATE ingredient_ontology_labels
            SET review_state = 'quarantined',
                provenance = 'full_resolution_duplicate_quarantine',
                updated_at = CURRENT_TIMESTAMP
            WHERE ontology_version_id = ? AND entity_id = ?
              AND review_state = 'accepted'
        ")->execute([
            $versionId,
            (int)$entities[$duplicate]['id'],
        ]);
        foreach ($byLabel as $label) {
            $normalized = ingredientOntologyV3NormalizeLabel(
                (string)$label['label']
            );
            if ($duplicate === 'pepper' && $normalized === 'pepper') {
                continue;
            }
            $merged = array_replace(
                $attributes,
                (array)$label['attributes']
            );
            $labelId = ingredientOntologyV3UpsertLabel(
                $db,
                $versionId,
                (int)$entities[$canonical]['id'],
                (string)$label['language'],
                (string)$label['label'],
                $merged ? 'attribute_alias' : 'exact_alias',
                'accepted',
                INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION,
                'duplicate-canonical:' . $duplicate,
                $merged,
                $facetMap
            );
            $accepted = $db->prepare("
                SELECT COUNT(*)
                FROM ingredient_ontology_labels
                WHERE id = ? AND entity_id = ?
                  AND review_state = 'accepted'
            ");
            $accepted->execute([
                $labelId,
                (int)$entities[$canonical]['id'],
            ]);
            if ((int)$accepted->fetchColumn() !== 1) {
                throw new RuntimeException(
                    "duplicate alias transfer silently demoted: "
                    . (string)$label['label']
                );
            }
        }
    }

    $db->prepare("
        DELETE FROM ingredient_ontology_entity_facet_policies
        WHERE ontology_version_id = ?
    ")->execute([$versionId]);
    $policyInsert = $db->prepare("
        INSERT INTO ingredient_ontology_entity_facet_policies (
            ontology_version_id, entity_id, facet_id, allowed, defining,
            evidence_source_id, policy_hash, provenance
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $policyCount = 0;
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'entity-facet-policies.csv'
        ) as $row
    ) {
        $slug = ingredientOntologyV3Slug(
            (string)$row['entity_slug']
        );
        $facet = (string)$row['facet_key'];
        if (
            !isset($entities[$slug], $facetMap[$facet])
            || !ingredientOntologyV3ResolutionReviewerLineageAllowed(
                (string)$row['reviewer']
            )
        ) {
            throw new RuntimeException(
                "invalid explicit entity/facet policy: {$slug}/{$facet}"
            );
        }
        $payload = [
            'entity_slug' => $slug,
            'facet_key' => $facet,
            'allowed' => (int)$row['allowed'],
            'defining' => (int)$row['defining'],
        ];
        $policyInsert->execute([
            $versionId,
            (int)$entities[$slug]['id'],
            (int)$facetMap[$facet]['id'],
            (int)$row['allowed'] === 1 ? 1 : 0,
            (int)$row['defining'] === 1 ? 1 : 0,
            $policyEvidenceSourceId,
            ingredientOntologyV3Hash($payload),
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION,
        ]);
        $policyCount++;
    }

    $parents = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows('primary-edges.csv')
        as $row
    ) {
        $child = ingredientOntologyV3Slug(
            (string)$row['child_slug']
        );
        $parent = ingredientOntologyV3Slug(
            (string)$row['parent_slug']
        );
        if (
            isset($parents[$child])
            || !isset($entities[$child])
            || ($parent !== '' && !isset($entities[$parent]))
        ) {
            throw new RuntimeException(
                "invalid explicit primary-edge review: {$child}"
            );
        }
        $parents[$child] = $parent !== '' ? $parent : null;
    }
    if (count($parents) !== 305) {
        throw new RuntimeException(
            'primary-edge manifest must contain 305 rows'
        );
    }
    $db->prepare("
        UPDATE ingredient_ontology_relations
        SET is_primary = 0,
            review_state = CASE
                WHEN review_state = 'accepted' THEN 'quarantined'
                ELSE review_state
            END,
            semantics_json = '{\"superseded_by_explicit_v2_edge\":true}',
            updated_at = CURRENT_TIMESTAMP
        WHERE ontology_version_id = ?
          AND relation = 'is_a'
          AND is_primary = 1
    ")->execute([$versionId]);
    foreach ($parents as $child => $parent) {
        if ($parent === null) {
            continue;
        }
        ingredientOntologyV3InsertRelation(
            $db,
            $versionId,
            (int)$entities[$child]['id'],
            (int)$entities[$parent]['id'],
            'is_a',
            true,
            false,
            1.0,
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION,
            'accepted',
            'forward',
            [
                'explicit_review_manifest' => true,
                'identity_satisfaction_denied' => true,
            ]
        );
    }
    if ($extraRoleSlugs) {
        if (!isset($entities['ingredient'])) {
            throw new RuntimeException(
                'dynamic structural placeholder parent is unavailable'
            );
        }
        foreach ($extraRoleSlugs as $slug) {
            if (!isset($entities[$slug])) {
                throw new RuntimeException(
                    "dynamic structural placeholder is unavailable: {$slug}"
                );
            }
            ingredientOntologyV3ResolutionSetPrimaryParent(
                $db,
                $versionId,
                (int)$entities[$slug]['id'],
                (int)$entities['ingredient']['id'],
                'autonomous_controller',
                'Unreviewed dynamic legacy entity remains a non-satisfying '
                    . 'structural placeholder.'
            );
        }
    }
    $secondaryRelationCount = 0;
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'secondary-relations.csv'
        ) as $row
    ) {
        $child = ingredientOntologyV3Slug(
            (string)$row['child_slug']
        );
        $target = ingredientOntologyV3Slug(
            (string)$row['target_slug']
        );
        $relation = (string)$row['relation'];
        $direction = (string)$row['direction'];
        if (
            !isset($entities[$child], $entities[$target])
            || !in_array($relation, [
                'equivalent_to', 'variant_of', 'substitutes_for',
                'derived_from', 'component_of',
            ], true)
            || !in_array($direction, ['forward', 'bidirectional'], true)
            || (string)$row['reviewer']
                !== INGREDIENT_ONTOLOGY_V3_RESOLUTION_REVIEWER
            || trim((string)$row['rationale']) === ''
            || trim((string)$row['source_citation']) === ''
        ) {
            throw new RuntimeException(
                "invalid reviewed secondary relation: {$child}/{$target}"
            );
        }
        ingredientOntologyV3InsertRelation(
            $db,
            $versionId,
            (int)$entities[$child]['id'],
            (int)$entities[$target]['id'],
            $relation,
            false,
            false,
            (float)$row['confidence'],
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION,
            'accepted',
            $direction,
            [
                'explicit_review_manifest' => true,
                'identity_satisfaction_denied' => true,
                'rationale' => (string)$row['rationale'],
                'source_citation' => (string)$row['source_citation'],
            ]
        );
        $secondaryRelationCount++;
    }

    $reviewRows = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows('edge-reviews.csv')
        as $row
    ) {
        $child = ingredientOntologyV3Slug(
            (string)$row['child_slug']
        );
        if (
            isset($reviewRows[$child])
            || !array_key_exists($child, $parents)
        ) {
            throw new RuntimeException(
                "invalid explicit edge-diff review: {$child}"
            );
        }
        $previous = ingredientOntologyV3Slug(
            (string)$row['previous_parent_slug']
        );
        $next = ingredientOntologyV3Slug(
            (string)$row['new_parent_slug']
        );
        $previous = $previous !== '' ? $previous : null;
        $next = $next !== '' ? $next : null;
        if (
            $next !== $parents[$child]
            || ($previous !== null && !isset($entities[$previous]))
            || (string)$row['review_state'] !== 'reviewed'
        ) {
            throw new RuntimeException(
                "edge review does not match explicit parent: {$child}"
            );
        }
        $reviewRows[$child] = [
            'previous' => $previous,
            'next' => $next,
            'change_kind' => (string)$row['change_kind'],
            'rationale' => (string)$row['rationale'],
        ];
    }
    if (count($reviewRows) !== 305) {
        throw new RuntimeException(
            'edge-review manifest must contain 305 rows'
        );
    }
    $insertReview = $db->prepare("
        INSERT INTO ingredient_ontology_primary_edge_reviews (
            ontology_version_id, child_entity_id,
            previous_parent_entity_id, new_parent_entity_id,
            change_kind, disposition, rationale, manifest_id,
            content_hash, reviewer, review_batch
        )
        VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)
    ");
    $reviewUpdate = $db->prepare("
        UPDATE ingredient_ontology_primary_edge_reviews
        SET disposition = 'reviewed'
        WHERE id = ?
          AND disposition = 'pending'
    ");
    $edgeCounts = [
        'added' => 0, 'changed' => 0, 'removed' => 0,
        'restored' => 0, 'unchanged' => 0,
    ];
    foreach ($reviewRows as $child => $review) {
        $stable = [
            'child_slug' => $child,
            'previous_parent_slug' => $review['previous'],
            'new_parent_slug' => $review['next'],
            'change_kind' => $review['change_kind'],
            'manifest_hash' => $manifest['manifest_hash'],
        ];
        $contentHash = ingredientOntologyV3Hash($stable);
        $previousId = $review['previous'] !== null
            ? (int)$entities[$review['previous']]['id']
            : null;
        $nextId = $review['next'] !== null
            ? (int)$entities[$review['next']]['id']
            : null;
        $insertReview->execute([
            $versionId,
            (int)$entities[$child]['id'],
            $previousId,
            $nextId,
            $review['change_kind'],
            $review['rationale'],
            (int)$manifest['id'],
            $contentHash,
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_REVIEWER,
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_BATCH,
        ]);
        $reviewId = (int)$db->lastInsertId();
        $insertedReview = $db->prepare("
            SELECT ontology_version_id, child_entity_id,
                   previous_parent_entity_id, new_parent_entity_id,
                   change_kind, content_hash
            FROM ingredient_ontology_primary_edge_reviews
            WHERE id = ?
        ");
        $insertedReview->execute([$reviewId]);
        $insertedReview = $insertedReview->fetch(PDO::FETCH_ASSOC);
        $expectedReview = [
            'ontology_version_id' => $versionId,
            'child_entity_id' => (int)$entities[$child]['id'],
            'previous_parent_entity_id' => $previousId,
            'new_parent_entity_id' => $nextId,
            'change_kind' => $review['change_kind'],
            'content_hash' => $contentHash,
        ];
        foreach ($expectedReview as $field => $expectedValue) {
            $actualValue = $insertedReview[$field] ?? null;
            if (
                $field !== 'change_kind'
                && $field !== 'content_hash'
                && $actualValue !== null
            ) {
                $actualValue = (int)$actualValue;
            }
            if ($actualValue !== $expectedValue) {
                throw new RuntimeException(
                    "explicit edge review tuple mismatch: {$child}"
                );
            }
        }
        $reviewUpdate->execute([$reviewId]);
        $reviewed = $db->prepare("
            SELECT COUNT(*)
            FROM ingredient_ontology_primary_edge_reviews
            WHERE id = ? AND disposition = 'reviewed'
        ");
        $reviewed->execute([$reviewId]);
        if ((int)$reviewed->fetchColumn() !== 1) {
            throw new RuntimeException(
                "explicit edge review failed to apply: {$child}"
            );
        }
        $edgeCounts[$review['change_kind']]++;
    }
    $graph = ingredientOntologyV3GraphValidate($db, $versionId);
    if (!$graph['valid'] || $graph['root_count'] !== 1) {
        throw new RuntimeException(
            'explicit primary-edge manifest produced an invalid graph'
        );
    }
    $edgeSemanticAudit = ingredientOntologyV3EdgeSemanticAudit(
        $db,
        $versionId
    );
    if (!$edgeSemanticAudit['valid']) {
        throw new RuntimeException(
            'primary-edge semantic review failed: '
            . ingredientOntologyV3Json($edgeSemanticAudit)
        );
    }
    return [
        'entity_rows' => count($roleRows),
        'canonical_aliases' => [],
        'duplicates' => $duplicates,
        'edge_reviews' => $edgeCounts,
        'facet_policy_count' => $policyCount,
        'explicit_role_coverage' => count($roleRows),
        'explicit_primary_edge_coverage' => count($parents),
        'explicit_edge_review_coverage' => count($reviewRows),
        'secondary_relation_count' => $secondaryRelationCount,
        'dynamic_placeholder_count' => count($extraRoleSlugs),
        'dynamic_placeholder_slugs' => $extraRoleSlugs,
        'edge_semantic_audit' => $edgeSemanticAudit,
    ];
}

function ingredientOntologyV3EdgeSemanticAudit(
    PDO $db,
    int $versionId
): array {
    $entities = ingredientOntologyV3EntityMap($db, $versionId)['by_slug'];
    $primary = [];
    $stmt = $db->prepare("
        SELECT child.slug AS child_slug, parent.slug AS parent_slug
        FROM ingredient_ontology_relations relation
        JOIN ingredient_ontology_entities child
          ON child.id = relation.from_entity_id
        JOIN ingredient_ontology_entities parent
          ON parent.id = relation.to_entity_id
        WHERE relation.ontology_version_id = ?
          AND relation.relation = 'is_a'
          AND relation.is_primary = 1
          AND relation.review_state = 'accepted'
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $primary[(string)$row['child_slug']] =
            (string)$row['parent_slug'];
    }
    $semanticReviews = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'edge-semantic-reviews.csv'
        ) as $row
    ) {
        $child = ingredientOntologyV3Slug(
            (string)$row['child_slug']
        );
        $parent = ingredientOntologyV3Slug(
            (string)$row['parent_slug']
        );
        if (
            isset($semanticReviews[$child])
            || !isset($entities[$child], $entities[$parent])
            || ($primary[$child] ?? null) !== $parent
            || (string)$row['semantic_kind'] !== 'subtype'
            || (string)$row['reviewer']
                !== INGREDIENT_ONTOLOGY_V3_RESOLUTION_REVIEWER
            || trim((string)$row['rationale']) === ''
            || trim((string)$row['source_citation']) === ''
        ) {
            throw new RuntimeException(
                "invalid edge semantic review: {$child}"
            );
        }
        $semanticReviews[$child] = $row;
    }
    $missingIdentityParentReviews = [];
    $structuralMembershipCount = 0;
    foreach ($primary as $child => $parent) {
        $parentRole = (string)(
            $entities[$parent]['identity_role'] ?? ''
        );
        if (
            in_array(
                $parentRole,
                ['structural_category', 'staple_class'],
                true
            )
        ) {
            $structuralMembershipCount++;
            continue;
        }
        if (!isset($semanticReviews[$child])) {
            $missingIdentityParentReviews[] = [
                'child_slug' => $child,
                'parent_slug' => $parent,
            ];
        }
    }
    $fixtureFailures = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'edge-semantic-fixture.csv'
        ) as $row
    ) {
        $child = ingredientOntologyV3Slug(
            (string)$row['child_slug']
        );
        $expectedParent = ingredientOntologyV3Slug(
            (string)$row['expected_parent_slug']
        );
        $forbiddenParent = ingredientOntologyV3Slug(
            (string)$row['forbidden_parent_slug']
        );
        $expectedParent = $expectedParent !== ''
            ? $expectedParent
            : null;
        $forbiddenParent = $forbiddenParent !== ''
            ? $forbiddenParent
            : null;
        $actualParent = $primary[$child] ?? null;
        $valid = $actualParent === $expectedParent
            && (
                $forbiddenParent === null
                || $actualParent !== $forbiddenParent
            );
        $secondaryRelation = trim(
            (string)$row['expected_secondary_relation']
        );
        $secondaryTarget = ingredientOntologyV3Slug(
            (string)$row['expected_secondary_target_slug']
        );
        if ($valid && $secondaryRelation !== '') {
            $secondary = $db->prepare("
                SELECT COUNT(*)
                FROM ingredient_ontology_relations relation
                JOIN ingredient_ontology_entities child
                  ON child.id = relation.from_entity_id
                JOIN ingredient_ontology_entities target
                  ON target.id = relation.to_entity_id
                WHERE relation.ontology_version_id = ?
                  AND child.slug = ?
                  AND target.slug = ?
                  AND relation.relation = ?
                  AND relation.is_primary = 0
                  AND relation.satisfies_required = 0
                  AND relation.review_state = 'accepted'
            ");
            $secondary->execute([
                $versionId,
                $child,
                $secondaryTarget,
                $secondaryRelation,
            ]);
            $valid = (int)$secondary->fetchColumn() === 1;
        }
        if (!$valid && count($fixtureFailures) < 100) {
            $fixtureFailures[] = [
                'child_slug' => $child,
                'expected_parent_slug' => $expectedParent,
                'actual_parent_slug' => $actualParent,
                'expected_secondary_relation' => $secondaryRelation,
                'expected_secondary_target_slug' =>
                    $secondaryTarget ?: null,
            ];
        }
    }
    return [
        'valid' => !$missingIdentityParentReviews
            && !$fixtureFailures,
        'primary_edge_count' => count($primary),
        'structural_membership_count' => $structuralMembershipCount,
        'explicit_identity_parent_review_count' =>
            count($semanticReviews),
        'missing_identity_parent_review_count' =>
            count($missingIdentityParentReviews),
        'missing_identity_parent_review_sample' =>
            array_slice($missingIdentityParentReviews, 0, 100),
        'edge_semantic_fixture_failure_count' =>
            count($fixtureFailures),
        'edge_semantic_fixture_failure_sample' => $fixtureFailures,
    ];
}

function ingredientOntologyV3ResolutionEntityFacetPolicyMap(
    PDO $db,
    int $versionId
): array {
    $stmt = $db->prepare("
        SELECT p.entity_id, f.facet_key, p.defining
        FROM ingredient_ontology_entity_facet_policies p
        JOIN ingredient_ontology_facets f ON f.id = p.facet_id
        WHERE p.ontology_version_id = ? AND p.allowed = 1
    ");
    $stmt->execute([$versionId]);
    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result[(int)$row['entity_id']][(string)$row['facet_key']] = [
            'defining' => !empty($row['defining']),
        ];
    }
    return $result;
}

function ingredientOntologyV3ResolutionFilterAttributes(
    array $policies,
    int $entityId,
    array $attributes
): array {
    $allowed = $policies[$entityId] ?? [];
    $accepted = [];
    $blocked = [];
    foreach ($attributes as $facet => $value) {
        if (isset($allowed[$facet])) {
            $accepted[$facet] = $value;
        } else {
            $blocked[$facet] = $value;
        }
    }
    ksort($accepted, SORT_STRING);
    ksort($blocked, SORT_STRING);
    return ['accepted' => $accepted, 'blocked' => $blocked];
}

function ingredientOntologyV3ResolutionValidateAttributes(
    array $policies,
    array $facetMap,
    int $entityId,
    array $attributes
): array {
    ksort($attributes, SORT_STRING);
    $invalid = [];
    foreach ($attributes as $facet => $value) {
        if (
            !isset($facetMap[$facet])
            || !isset($facetMap[$facet]['values'][(string)$value])
        ) {
            $invalid[(string)$facet] = (string)$value;
        }
    }
    $filtered = ingredientOntologyV3ResolutionFilterAttributes(
        $policies,
        $entityId,
        $attributes
    );
    $filtered['invalid'] = $invalid;
    $filtered['valid'] = !$invalid
        && !$filtered['blocked']
        && $filtered['accepted'] === $attributes;
    return $filtered;
}

function ingredientOntologyV3ApplyResolutionAliases(
    PDO $db,
    int $versionId,
    array $facetMap,
    array $manifest,
    array $entityResult
): array {
    $entities = ingredientOntologyV3EntityMap($db, $versionId)['by_slug'];
    $policies = ingredientOntologyV3ResolutionEntityFacetPolicyMap(
        $db,
        $versionId
    );
    $db->prepare("
        UPDATE ingredient_ontology_labels
        SET review_state = 'quarantined',
            provenance = 'prior_label_review_pending',
            updated_at = CURRENT_TIMESTAMP
        WHERE ontology_version_id = ?
          AND review_state = 'accepted'
          AND provenance IN (
              'legacy_taxonomy_name',
              'legacy_taxonomy_slug',
              'legacy_canonical_name'
          )
    ")->execute([$versionId]);
    $insertPolicy = $db->prepare("
        INSERT INTO ingredient_ontology_label_context_policies (
            ontology_version_id, label_id, required_cohort,
            required_evidence_kind, required_evidence_key, policy_hash,
            provenance
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $inserted = 0;
    foreach (
        ingredientOntologyV3ResolutionCsvRows('aliases.csv')
        as $row
    ) {
        if (
            (string)$row['required_evidence_kind'] !== ''
            || (string)$row['required_evidence_key'] !== ''
        ) {
            continue;
        }
        $slug = ingredientOntologyV3Slug((string)$row['slug']);
        if (!isset($entities[$slug])) {
            throw new RuntimeException(
                "resolution alias target is unavailable: {$slug}"
            );
        }
        $attributes = json_decode(
            (string)$row['attributes_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($attributes)) {
            throw new RuntimeException(
                "resolution alias attributes are invalid: {$slug}"
            );
        }
        ksort($attributes, SORT_STRING);
        $validated = ingredientOntologyV3ResolutionValidateAttributes(
            $policies,
            $facetMap,
            (int)$entities[$slug]['id'],
            $attributes
        );
        if (!$validated['valid']) {
            throw new RuntimeException(
                "resolution alias facets are invalid: {$slug} "
                . ingredientOntologyV3Json($validated)
            );
        }
        $labelId = ingredientOntologyV3UpsertLabel(
            $db,
            $versionId,
            (int)$entities[$slug]['id'],
            (string)$row['language'],
            (string)$row['label'],
            $attributes ? 'attribute_alias' : 'exact_alias',
            'accepted',
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION,
            'manifest-alias:' . hash(
                'sha256',
                (string)$row['language'] . "\n" . (string)$row['label']
            ),
            $attributes,
            $facetMap
        );
        if ($labelId <= 0) {
            continue;
        }
        $cohort = (string)$row['required_cohort'];
        $evidenceKind = (string)$row['required_evidence_kind'];
        $evidenceKey = (string)$row['required_evidence_key'];
        if ($cohort !== '' || $evidenceKind !== '' || $evidenceKey !== '') {
            $policy = [
                'required_cohort' => $cohort !== '' ? $cohort : null,
                'required_evidence_kind' =>
                    $evidenceKind !== '' ? $evidenceKind : null,
                'required_evidence_key' =>
                    $evidenceKey !== '' ? $evidenceKey : null,
                'rationale' => (string)$row['rationale'],
            ];
            $insertPolicy->execute([
                $versionId,
                $labelId,
                $policy['required_cohort'],
                $policy['required_evidence_kind'],
                $policy['required_evidence_key'],
                ingredientOntologyV3Hash($policy),
                INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION,
            ]);
        }
        $inserted++;
    }

    $transitionCount = 0;
    $demotionCount = 0;
    $transitionSeen = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'prior-accepted-label-transitions.csv'
        ) as $row
    ) {
        $normalized = (string)$row['normalized_label'];
        if (isset($transitionSeen[$normalized])) {
            throw new RuntimeException(
                "duplicate prior-label transition: {$normalized}"
            );
        }
        $transitionSeen[$normalized] = true;
        $decision = (string)$row['decision'];
        ingredientOntologyV3InsertEvidenceSource(
            $db,
            $versionId,
            (int)$manifest['id'],
            'curated_manifest',
            'prior-label-transition:' . hash('sha256', $normalized),
            [
                'normalized_label' => $normalized,
                'decision' => $decision,
                'disposition_code' =>
                    (string)$row['disposition_code'],
                'entity_slug' => (string)$row['entity_slug'],
                'rationale' => (string)$row['rationale'],
                'source_citation' =>
                    (string)$row['source_citation'],
            ],
            'prior-accepted-label-transition-v3'
        );
        if ($decision === 'demote') {
            $db->prepare("
                UPDATE ingredient_ontology_labels
                SET review_state = 'quarantined',
                    provenance = 'explicit_prior_label_demotion_v3',
                    updated_at = CURRENT_TIMESTAMP
                WHERE ontology_version_id = ?
                  AND normalized_label = ?
                  AND review_state = 'accepted'
            ")->execute([$versionId, $normalized]);
            $demotionCount++;
            $transitionCount++;
            continue;
        }
        if (!in_array($decision, ['retain', 'retarget'], true)) {
            throw new RuntimeException(
                "invalid prior-label transition: {$normalized}"
            );
        }
        $slug = ingredientOntologyV3Slug((string)$row['entity_slug']);
        if (
            !isset($entities[$slug])
            || empty($entities[$slug]['active'])
            || in_array(
                (string)$entities[$slug]['identity_role'],
                ['structural_category', 'staple_class'],
                true
            )
        ) {
            throw new RuntimeException(
                "prior-label transition target is ineligible: {$normalized}"
            );
        }
        $attributes = json_decode(
            (string)$row['attributes_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        ) ?: [];
        ksort($attributes, SORT_STRING);
        $validated = ingredientOntologyV3ResolutionValidateAttributes(
            $policies,
            $facetMap,
            (int)$entities[$slug]['id'],
            $attributes
        );
        if (!$validated['valid']) {
            throw new RuntimeException(
                "prior-label transition facets are invalid: {$normalized} "
                . ingredientOntologyV3Json($validated)
            );
        }
        $transitionLanguage = ingredientOntologyV3NormalizeLanguage(
            (string)$row['language']
        );
        $db->prepare("
            UPDATE ingredient_ontology_labels
            SET review_state = 'quarantined',
                provenance = 'superseded_by_prior_label_transition_v3',
                updated_at = CURRENT_TIMESTAMP
            WHERE ontology_version_id = ?
              AND normalized_label = ?
              AND language = ?
              AND review_state = 'accepted'
        ")->execute([
            $versionId,
            $normalized,
            $transitionLanguage,
        ]);
        $labelId = ingredientOntologyV3UpsertLabel(
            $db,
            $versionId,
            (int)$entities[$slug]['id'],
            $transitionLanguage,
            (string)$row['label'],
            $attributes ? 'attribute_alias' : 'exact_alias',
            'accepted',
            'prior-label-transition-v3',
            'prior-label:' . hash('sha256', $normalized),
            $attributes,
            $facetMap
        );
        if ((string)$row['required_cohort'] !== '') {
            $policy = [
                'required_cohort' => (string)$row['required_cohort'],
                'required_evidence_kind' => null,
                'required_evidence_key' => null,
                'rationale' => (string)$row['rationale'],
            ];
            $insertPolicy->execute([
                $versionId,
                $labelId,
                $policy['required_cohort'],
                null,
                null,
                ingredientOntologyV3Hash($policy),
                'prior-label-transition-v3',
            ]);
        }
        $accepted = $db->prepare("
            SELECT COUNT(*)
            FROM ingredient_ontology_labels
            WHERE id = ? AND review_state = 'accepted'
        ");
        $accepted->execute([$labelId]);
        if ((int)$accepted->fetchColumn() !== 1) {
            throw new RuntimeException(
                "reviewed prior label silently demoted: {$normalized}"
            );
        }
        $inserted++;
        $transitionCount++;
    }
    if ($transitionCount !== 522) {
        throw new RuntimeException(
            'prior accepted label transitions must cover 522 labels'
        );
    }

    $providerReviewCount = 0;
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'provider-local-reviews.csv'
        ) as $row
    ) {
        $providerReviewCount++;
        ingredientOntologyV3InsertEvidenceSource(
            $db,
            $versionId,
            (int)$manifest['id'],
            'provider_review',
            'provider-local-review:' . (string)$row['review_key'],
            [
                'review_key' => (string)$row['review_key'],
                'normalized_local_label' =>
                    (string)$row['normalized_local_label'],
                'provider_ref' => (string)$row['provider_ref'],
                'title_hash' => (string)$row['title_hash'],
                'disposition_code' =>
                    (string)$row['disposition_code'],
                'entity_slug' => (string)$row['entity_slug'],
                'legacy_occurrences' =>
                    (int)$row['legacy_occurrences'],
                'rationale' => (string)$row['rationale'],
            ],
            'provider-local-review-manifest-v3'
        );
        if (
            !in_array(
                (string)$row['disposition_code'],
                ['D1', 'D2'],
                true
            )
        ) {
            continue;
        }
        $slug = ingredientOntologyV3Slug((string)$row['entity_slug']);
        if (!isset($entities[$slug])) {
            throw new RuntimeException(
                "provider local review target missing: {$slug}"
            );
        }
        $attributes = json_decode(
            (string)$row['attributes_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        ) ?: [];
        ksort($attributes, SORT_STRING);
        $validated = ingredientOntologyV3ResolutionValidateAttributes(
            $policies,
            $facetMap,
            (int)$entities[$slug]['id'],
            $attributes
        );
        if (!$validated['valid']) {
            throw new RuntimeException(
                'provider local review facets are invalid: '
                . (string)$row['normalized_local_label'] . ' '
                . ingredientOntologyV3Json($validated)
            );
        }
        $labelId = ingredientOntologyV3UpsertLabel(
            $db,
            $versionId,
            (int)$entities[$slug]['id'],
            'und',
            (string)$row['normalized_local_label'],
            $attributes ? 'attribute_alias' : 'exact_alias',
            'accepted',
            'provider-local-review-v3',
            (string)$row['review_key'],
            $attributes,
            $facetMap
        );
        $policy = [
            'required_cohort' =>
                (string)$row['required_cohort'] !== ''
                    ? (string)$row['required_cohort']
                    : null,
            'required_evidence_kind' => 'provider_owner_review',
            'required_evidence_key' => (string)$row['review_key'],
            'rationale' => (string)$row['rationale'],
        ];
        $insertPolicy->execute([
            $versionId,
            $labelId,
            $policy['required_cohort'],
            $policy['required_evidence_kind'],
            $policy['required_evidence_key'],
            ingredientOntologyV3Hash($policy),
            'provider-local-review-v3',
        ]);
        $inserted++;
    }
    if ($providerReviewCount < 99) {
        throw new RuntimeException(
            'provider local review manifest must contain at least 99 rows'
        );
    }
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'context-dispositions.csv'
        ) as $row
    ) {
        ingredientOntologyV3InsertEvidenceSource(
            $db,
            $versionId,
            (int)$manifest['id'],
            'curated_manifest',
            'context-disposition:' . ingredientOntologyV3Hash([
                'normalized_label' =>
                    (string)$row['normalized_label'],
                'language' => (string)$row['language'],
                'required_cohort' =>
                    (string)$row['required_cohort'],
                'required_evidence_key' =>
                    (string)$row['required_evidence_key'],
            ]),
            [
                'normalized_label' =>
                    (string)$row['normalized_label'],
                'language' => (string)$row['language'],
                'required_cohort' =>
                    (string)$row['required_cohort'],
                'required_evidence_kind' =>
                    (string)$row['required_evidence_kind'],
                'required_evidence_key' =>
                    (string)$row['required_evidence_key'],
                'disposition_code' =>
                    (string)$row['disposition_code'],
                'meaning_entity_slug' =>
                    (string)$row['meaning_entity_slug'],
                'rationale' => (string)$row['rationale'],
            ],
            'explicit-context-disposition-v3'
        );
    }
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'recipe-semantic-dispositions.csv'
        ) as $row
    ) {
        ingredientOntologyV3InsertEvidenceSource(
            $db,
            $versionId,
            (int)$manifest['id'],
            'curated_manifest',
            'recipe-semantic-disposition:' . ingredientOntologyV3Hash([
                'normalized_label' =>
                    (string)$row['normalized_label'],
                'language' => (string)$row['language'],
                'required_cohort' =>
                    (string)$row['required_cohort'],
            ]),
            [
                'normalized_label' =>
                    (string)$row['normalized_label'],
                'language' => (string)$row['language'],
                'required_cohort' =>
                    (string)$row['required_cohort'],
                'disposition_code' =>
                    (string)$row['disposition_code'],
                'rationale' => (string)$row['rationale'],
            ],
            'explicit-recipe-semantic-disposition-v3'
        );
    }
    ingredientOntologyV3ResolutionQuarantineUnsafeLabels($db, $versionId);
    ingredientOntologyV3ResolutionQuarantinePolicyInvalidLabels(
        $db,
        $versionId
    );
    $transitionOutcomeAudit =
        ingredientOntologyV3PriorTransitionOutcomeAudit(
            $db,
            $versionId
        );
    if (!$transitionOutcomeAudit['valid']) {
        throw new RuntimeException(
            'prior accepted transition outcomes did not survive: '
            . ingredientOntologyV3Json($transitionOutcomeAudit)
        );
    }
    return [
        'inserted' => $inserted,
        'prior_label_transition_count' => $transitionCount,
        'prior_label_demotion_count' => $demotionCount,
        'provider_local_review_count' => $providerReviewCount,
        'transition_outcomes' => $transitionOutcomeAudit,
        'collision_audit' =>
            ingredientOntologyV3AcceptedAliasCollisionAudit(
                $db,
                $versionId
            ),
    ];
}

function ingredientOntologyV3ResolutionQuarantinePolicyInvalidLabels(
    PDO $db,
    int $versionId
): void {
    $db->prepare("
        UPDATE ingredient_ontology_labels
        SET review_state = 'quarantined',
            provenance = 'full_resolution_facet_policy_block',
            updated_at = CURRENT_TIMESTAMP
        WHERE ontology_version_id = ?
          AND review_state = 'accepted'
          AND EXISTS (
              SELECT 1
              FROM ingredient_ontology_label_attributes a
              WHERE a.label_id = ingredient_ontology_labels.id
                AND NOT EXISTS (
                    SELECT 1
                    FROM ingredient_ontology_entity_facet_policies p
                    WHERE p.ontology_version_id = ?
                      AND p.entity_id =
                          ingredient_ontology_labels.entity_id
                      AND p.facet_id = a.facet_id
                      AND p.allowed = 1
                )
          )
    ")->execute([$versionId, $versionId]);
}

function ingredientOntologyV3ResolutionQuarantineUnsafeLabels(
    PDO $db,
    int $versionId
): void {
    $db->prepare("
        UPDATE ingredient_ontology_labels
        SET review_state = 'quarantined',
            provenance = 'full_resolution_identity_ineligible',
            updated_at = CURRENT_TIMESTAMP
        WHERE ontology_version_id = ?
          AND review_state = 'accepted'
          AND entity_id IN (
              SELECT id
              FROM ingredient_ontology_entities
              WHERE ontology_version_id = ?
                AND (
                    active = 0
                    OR identity_role IN (
                        'structural_category', 'staple_class'
                    )
                )
          )
    ")->execute([$versionId, $versionId]);
}

function ingredientOntologyV3PriorTransitionOutcomeAudit(
    PDO $db,
    int $versionId,
    ?array $overrideIndex = null,
    bool $verifyTerminalDispositions = false
): array {
    $index = $overrideIndex
        ?? ingredientOntologyV3LabelIndex($db, $versionId);
    $mismatches = [];
    $retainedLabels = 0;
    $demoted = 0;
    $terminalChecked = 0;
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'prior-accepted-label-transitions.csv'
        ) as $row
    ) {
        $normalized = (string)$row['normalized_label'];
        $language = ingredientOntologyV3NormalizeLanguage(
            (string)$row['language']
        );
        $context = [];
        if ((string)($row['required_cohort'] ?? '') !== '') {
            $context['cohort'] = (string)$row['required_cohort'];
        }
        if (
            (string)($row['required_evidence_kind'] ?? '') !== ''
            && (string)($row['required_evidence_key'] ?? '') !== ''
        ) {
            $context['evidence'][
                (string)$row['required_evidence_kind']
            ][(string)$row['required_evidence_key']] = true;
        }
        $actual = ingredientOntologyV3ResolveLabel(
            $index,
            (string)$row['label'],
            $language,
            $context
        );
        $decision = (string)$row['decision'];
        if (in_array($decision, ['retain', 'retarget'], true)) {
            $expectedAttributes = json_decode(
                (string)$row['attributes_json'],
                true,
                32,
                JSON_THROW_ON_ERROR
            ) ?: [];
            ksort($expectedAttributes, SORT_STRING);
            $valid = (string)$actual['status'] === 'accepted'
                && (string)($actual['entity_slug'] ?? '')
                    === ingredientOntologyV3Slug(
                        (string)$row['entity_slug']
                    )
                && (array)($actual['attributes'] ?? [])
                    === $expectedAttributes;
            if (
                $valid
                && (string)($row['required_cohort'] ?? '') !== ''
            ) {
                $withoutContext = ingredientOntologyV3ResolveLabel(
                    $index,
                    (string)$row['label'],
                    $language
                );
                $valid = (string)$withoutContext['status'] !== 'accepted';
            }
            if ($valid) {
                $retainedLabels++;
                continue;
            }
        } else {
            $valid = (string)$actual['status'] !== 'accepted';
            if ($valid && $verifyTerminalDispositions) {
                $stmt = $db->prepare("
                    SELECT d.disposition_code, d.mechanism,
                           cohort.cohort, COUNT(*) AS outcome_count
                    FROM ingredient_ontology_mappings m
                    JOIN ingredient_ontology_terminal_dispositions d
                      ON d.id = m.terminal_disposition_id
                    LEFT JOIN recipe_ingredients ingredient
                      ON m.owner_type = 'recipe_ingredient'
                     AND ingredient.id = m.owner_id
                    LEFT JOIN ingredient_ontology_recipe_cohorts cohort
                      ON cohort.ontology_version_id =
                         m.ontology_version_id
                     AND cohort.recipe_id = ingredient.recipe_id
                    WHERE m.ontology_version_id = ?
                      AND m.owner_type = 'recipe_ingredient'
                      AND m.normalized_label = ?
                    GROUP BY d.disposition_code, d.mechanism,
                             cohort.cohort
                ");
                $stmt->execute([$versionId, $normalized]);
                $relevant = 0;
                $invalidCodes = [];
                $requiredCohort = (string)(
                    $row['required_cohort'] ?? ''
                );
                while ($codeRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (
                        $requiredCohort !== ''
                        && (string)($codeRow['cohort'] ?? '')
                            !== $requiredCohort
                    ) {
                        continue;
                    }
                    if (
                        $requiredCohort === ''
                        && in_array(
                            (string)$codeRow['mechanism'],
                            [
                                'explicit_context_manifest',
                                'explicit_provider_context_manifest',
                            ],
                            true
                        )
                    ) {
                        continue;
                    }
                    $relevant += (int)$codeRow['outcome_count'];
                    if (
                        (string)$codeRow['disposition_code']
                            !== (string)$row['disposition_code']
                    ) {
                        $invalidCodes[] = [
                            'code' =>
                                (string)$codeRow['disposition_code'],
                            'mechanism' =>
                                (string)$codeRow['mechanism'],
                            'cohort' => $codeRow['cohort'],
                        ];
                    }
                }
                if ($relevant > 0) {
                    $terminalChecked++;
                    $valid = !$invalidCodes;
                }
            }
            if ($valid) {
                $demoted++;
                continue;
            }
        }
        if (count($mismatches) < 100) {
            $mismatches[] = [
                'normalized_label' => $normalized,
                'decision' => $decision,
                'expected_disposition' =>
                    (string)$row['disposition_code'],
                'expected_entity_slug' =>
                    (string)$row['entity_slug'] ?: null,
                'expected_attributes' => json_decode(
                    (string)$row['attributes_json'],
                    true
                ) ?: [],
                'actual' => $actual,
            ];
        }
    }
    $expectedCount = (int)(
        ingredientOntologyV3ResolutionManifest()['frozen_sources']
            ['prior_accepted_label_count'] ?? 522
    );
    return [
        'valid' => !$mismatches
            && $retainedLabels + $demoted === $expectedCount,
        'expected_count' => $expectedCount,
        'reviewed_retain_retarget_label_count' => $retainedLabels,
        'reviewed_demotion_label_count' => $demoted,
        'terminal_demotion_label_count_checked' => $terminalChecked,
        'label_outcome_mismatch_count' => count($mismatches),
        'label_outcome_mismatch_sample' => $mismatches,
    ];
}

function ingredientOntologyV3TransitionFacetWaiverMap(): array {
    $waivers = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'transition-facet-waivers.csv'
        ) as $row
    ) {
        $key = implode('|', [
            (string)$row['normalized_label'],
            (string)$row['facet_key'],
            (string)$row['hint_value'],
        ]);
        if (
            isset($waivers[$key])
            || trim((string)$row['rationale']) === ''
            || trim((string)$row['reviewer']) === ''
            || trim((string)$row['source_citation']) === ''
        ) {
            throw new RuntimeException(
                'invalid transition facet waiver: ' . $key
            );
        }
        $waivers[$key] = $row;
    }
    return $waivers;
}

function ingredientOntologyV3PriorTransitionOwnerOutcomeAudit(
    PDO $db,
    int $versionId
): array {
    $transitionsByLabel = [];
    $manifestOccurrenceCount = 0;
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'prior-accepted-label-transitions.csv'
        ) as $row
    ) {
        $normalized = (string)$row['normalized_label'];
        $row['expected_attributes'] = json_decode(
            (string)$row['attributes_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        ) ?: [];
        ksort($row['expected_attributes'], SORT_STRING);
        $transitionsByLabel[$normalized][] = $row;
        $manifestOccurrenceCount += (int)$row['prior_occurrences'];
    }
    $waivers = ingredientOntologyV3TransitionFacetWaiverMap();
    $usedWaivers = [];
    $evidence = [];
    $evidenceRows = $db->prepare("
        SELECT owner_fingerprint, evidence_kind, evidence_key
        FROM ingredient_ontology_evidence_sources
        WHERE ontology_version_id = ?
          AND evidence_scope = 'owner_observation'
          AND owner_fingerprint IS NOT NULL
    ");
    $evidenceRows->execute([$versionId]);
    while ($row = $evidenceRows->fetch(PDO::FETCH_ASSOC)) {
        $evidence[(string)$row['owner_fingerprint']]
            [(string)$row['evidence_kind']]
            [(string)$row['evidence_key']] = true;
    }
    $placeholders = implode(
        ',',
        array_fill(0, count($transitionsByLabel), '?')
    );
    $owners = $db->prepare("
        SELECT m.owner_id, m.owner_fingerprint, m.source_label,
               m.normalized_label, m.language, m.status,
               m.mapping_source, m.attributes_json, m.evidence_json,
               entity.slug AS entity_slug,
               disposition.disposition_code,
               disposition.mechanism,
               cohort.cohort
        FROM ingredient_ontology_mappings m
        LEFT JOIN ingredient_ontology_entities entity
          ON entity.id = m.entity_id
        LEFT JOIN ingredient_ontology_terminal_dispositions disposition
          ON disposition.id = m.terminal_disposition_id
        LEFT JOIN recipe_ingredients ingredient
          ON ingredient.id = m.owner_id
         AND m.owner_type = 'recipe_ingredient'
        LEFT JOIN ingredient_ontology_recipe_cohorts cohort
          ON cohort.ontology_version_id = m.ontology_version_id
         AND cohort.recipe_id = ingredient.recipe_id
        WHERE m.ontology_version_id = ?
          AND m.owner_type = 'recipe_ingredient'
          AND m.normalized_label IN ({$placeholders})
        ORDER BY m.owner_id
    ");
    $owners->execute(array_merge(
        [$versionId],
        array_keys($transitionsByLabel)
    ));
    $ownerCount = 0;
    $acceptedUnderContext = 0;
    $terminalContextMissing = 0;
    $reviewedDemotions = 0;
    $demotionContextMissing = 0;
    $mismatchCount = 0;
    $mismatches = [];
    $acceptedFacetOwnerCount = 0;
    $facetOmissionCount = 0;
    $facetOmissionScopes = [];
    $waivedFacetOccurrenceCount = 0;
    $hintCache = [];
    while ($owner = $owners->fetch(PDO::FETCH_ASSOC)) {
        $ownerCount++;
        $normalized = (string)$owner['normalized_label'];
        $requestedLanguage = ingredientOntologyV3NormalizeLanguage(
            (string)$owner['language']
        );
        $candidates = $transitionsByLabel[$normalized] ?? [];
        $transition = null;
        $languageMatches = false;
        foreach ($candidates as $candidate) {
            if (
                ingredientOntologyV3LanguageMatches(
                    (string)$candidate['language'],
                    $requestedLanguage
                )
            ) {
                $transition = $candidate;
                $languageMatches = true;
                if (
                    ingredientOntologyV3NormalizeLanguage(
                        (string)$candidate['language']
                    ) === $requestedLanguage
                ) {
                    break;
                }
            }
        }
        if ($transition === null) {
            $transition = $candidates[0] ?? null;
        }
        if ($transition === null) {
            $mismatchCount++;
            continue;
        }
        $requiredCohort = (string)(
            $transition['required_cohort'] ?? ''
        );
        $requiredKind = (string)(
            $transition['required_evidence_kind'] ?? ''
        );
        $requiredKey = (string)(
            $transition['required_evidence_key'] ?? ''
        );
        $contextPresent = $languageMatches
            && (
                $requiredCohort === ''
                || $requiredCohort === (string)($owner['cohort'] ?? '')
            )
            && (
                $requiredKind === ''
                || $requiredKey === ''
                || !empty(
                    $evidence[(string)$owner['owner_fingerprint']]
                        [$requiredKind][$requiredKey]
                )
            );
        $decision = (string)$transition['decision'];
        $actualAttributes = json_decode(
            (string)$owner['attributes_json'],
            true
        ) ?: [];
        ksort($actualAttributes, SORT_STRING);
        $expectedAttributes = $transition['expected_attributes'];
        $actualCode = (string)($owner['disposition_code'] ?? '');
        $actualMechanism = (string)($owner['mechanism'] ?? '');
        $valid = false;
        $expected = [];
        if (
            in_array($decision, ['retain', 'retarget'], true)
            && $contextPresent
        ) {
            $expected = [
                'status' => 'accepted',
                'entity_slug' => ingredientOntologyV3Slug(
                    (string)$transition['entity_slug']
                ),
                'attributes' => $expectedAttributes,
                'disposition_code' =>
                    (string)$transition['disposition_code'],
                'mechanism' => 'reviewed_exact_label_identity',
            ];
            $valid = (string)$owner['status'] === 'accepted'
                && (string)($owner['entity_slug'] ?? '')
                    === $expected['entity_slug']
                && $actualAttributes === $expectedAttributes
                && $actualCode === $expected['disposition_code']
                && $actualMechanism === $expected['mechanism'];
            if ($valid) {
                $acceptedUnderContext++;
                $acceptedFacetOwnerCount++;
                $sourceLabel = (string)$owner['source_label'];
                if (!isset($hintCache[$sourceLabel])) {
                    $hintCache[$sourceLabel] =
                        ingredientOntologyV3DefiningAttributeHints(
                            $sourceLabel
                        );
                }
                foreach ($hintCache[$sourceLabel] as $facet => $value) {
                    if (
                        ($expectedAttributes[$facet] ?? null)
                            === $value
                    ) {
                        continue;
                    }
                    $waiverKey = implode('|', [
                        $normalized,
                        $facet,
                        $value,
                    ]);
                    if (isset($waivers[$waiverKey])) {
                        $usedWaivers[$waiverKey] = true;
                        $waivedFacetOccurrenceCount++;
                        continue;
                    }
                    $facetOmissionCount++;
                    $facetOmissionScopes[$waiverKey] =
                        ($facetOmissionScopes[$waiverKey] ?? 0) + 1;
                }
            }
        } elseif (
            in_array($decision, ['retain', 'retarget'], true)
            && !$contextPresent
        ) {
            $expected = [
                'status' => 'unresolved',
                'disposition_code' => 'D9',
                'mechanism' =>
                    'reviewed_transition_context_missing',
            ];
            $valid = (string)$owner['status'] !== 'accepted'
                && $actualCode === 'D9'
                && $actualMechanism
                    === 'reviewed_transition_context_missing';
            if ($valid) {
                $terminalContextMissing++;
            }
        } else {
            $baseLanguage = $requestedLanguage === 'und'
                ? 'und'
                : explode('-', $requestedLanguage)[0];
            $explicitContext = ingredientOntologyV3ContextDispositionMap()[
                implode('|', [
                    $normalized,
                    $baseLanguage,
                    (string)($owner['cohort'] ?? ''),
                ])
            ] ?? null;
            if (!$contextPresent) {
                $expectedCode = 'D9';
                $expectedMechanism =
                    'reviewed_transition_context_missing';
            } elseif ($explicitContext !== null) {
                $expectedCode = 'D3';
                $expectedMechanism = 'explicit_context_manifest';
            } else {
                $expectedCode =
                    (string)$transition['disposition_code'];
                $expectedMechanism = in_array(
                    $expectedCode,
                    ['D4', 'D5', 'D6'],
                    true
                )
                    ? 'explicit_recipe_semantic_manifest'
                    : (
                        $expectedCode === 'D3'
                            ? 'explicit_context_manifest'
                            : 'deterministic_evidence_exhausted'
                    );
            }
            $expected = [
                'status' => 'nonaccepted',
                'disposition_code' => $expectedCode,
                'mechanism' => $expectedMechanism,
            ];
            $valid = (string)$owner['status'] !== 'accepted'
                && $actualCode === $expectedCode
                && $actualMechanism === $expectedMechanism;
            if ($valid) {
                if (!$contextPresent) {
                    $demotionContextMissing++;
                }
                $reviewedDemotions++;
            }
        }
        if (!$valid) {
            $mismatchCount++;
            if (count($mismatches) < 100) {
                $mismatches[] = [
                    'owner_id' => (int)$owner['owner_id'],
                    'owner_fingerprint' =>
                        (string)$owner['owner_fingerprint'],
                    'source_label' => (string)$owner['source_label'],
                    'language' => $requestedLanguage,
                    'cohort' => $owner['cohort'] ?? null,
                    'context_present' => $contextPresent,
                    'expected' => $expected,
                    'actual' => [
                        'status' => (string)$owner['status'],
                        'entity_slug' => $owner['entity_slug'],
                        'attributes' => $actualAttributes,
                        'disposition_code' => $actualCode,
                        'mechanism' => $actualMechanism,
                        'mapping_source' =>
                            (string)$owner['mapping_source'],
                    ],
                ];
            }
        }
    }
    $unusedWaivers = array_values(array_diff(
        array_keys($waivers),
        array_keys($usedWaivers)
    ));
    $fullCorpusGate = !(
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
    );
    $version = ingredientOntologyV3Version($db, $versionId);
    $corpusProfile = (string)($version['corpus_profile'] ?? 'test');
    $manifestOccurrenceGate = in_array(
        $corpusProfile,
        ['eval', 'provider'],
        true
    );
    $accounted = $acceptedUnderContext
        + $terminalContextMissing
        + $reviewedDemotions;
    return [
        'valid' => (!$fullCorpusGate
                || !$manifestOccurrenceGate
                || $ownerCount === $manifestOccurrenceCount)
            && $accounted === $ownerCount
            && $mismatchCount === 0
            && $facetOmissionCount === 0
            && (!$fullCorpusGate || !$unusedWaivers),
        'full_corpus_gate_applied' => $fullCorpusGate,
        'corpus_profile' => $corpusProfile,
        'manifest_occurrence_gate_applied' =>
            $manifestOccurrenceGate,
        'prior_accepted_occurrences_total' => $ownerCount,
        'manifest_prior_accepted_occurrences_total' =>
            $manifestOccurrenceCount,
        'accepted_under_reviewed_context' => $acceptedUnderContext,
        'terminal_context_missing' => $terminalContextMissing,
        'reviewed_demotion_occurrences' => $reviewedDemotions,
        'demotion_context_missing_occurrences' =>
            $demotionContextMissing,
        'owner_outcome_mismatch_count' => $mismatchCount,
        'owner_outcome_mismatch_sample' => $mismatches,
        'accepted_transition_owner_count' => $acceptedFacetOwnerCount,
        'accepted_transition_hard_facet_omission_count' =>
            $facetOmissionCount,
        'accepted_transition_hard_facet_omission_scope_count' =>
            count($facetOmissionScopes),
        'accepted_transition_hard_facet_omission_sample' =>
            array_slice($facetOmissionScopes, 0, 100, true),
        'reviewed_facet_waiver_count' => count($waivers),
        'waived_facet_occurrence_count' =>
            $waivedFacetOccurrenceCount,
        'unused_facet_waiver_count' => count($unusedWaivers),
        'unused_facet_waiver_sample' =>
            array_slice($unusedWaivers, 0, 100),
    ];
}

function ingredientOntologyV3AcceptedAliasCollisionAudit(
    PDO $db,
    int $versionId
): array {
    $stmt = $db->prepare("
        SELECT normalized_label, language, entity_id
        FROM ingredient_ontology_labels
        WHERE ontology_version_id = ?
          AND review_state = 'accepted'
          AND kind IN ('exact_alias', 'attribute_alias')
        ORDER BY normalized_label, language, entity_id
    ");
    $stmt->execute([$versionId]);
    $index = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $language = ingredientOntologyV3NormalizeLanguage(
            (string)$row['language']
        );
        $base = $language === 'und'
            ? 'und'
            : explode('-', $language)[0];
        $index[(string)$row['normalized_label']][$base][
            (int)$row['entity_id']
        ] = true;
    }
    $rows = [];
    foreach ($index as $label => $languages) {
        $und = array_keys($languages['und'] ?? []);
        foreach ($languages as $language => $entities) {
            if ($language === 'und') {
                continue;
            }
            $effective = array_values(array_unique(array_merge(
                $und,
                array_keys($entities)
            )));
            if (count($effective) > 1) {
                $rows[] = [
                    'normalized_label' => $label,
                    'language' => $language,
                    'entity_ids' => $effective,
                ];
            }
        }
        if (count($und) > 1) {
            $rows[] = [
                'normalized_label' => $label,
                'language' => 'und',
                'entity_ids' => $und,
            ];
        }
    }
    return [
        'valid' => !$rows,
        'count' => count($rows),
        'sample' => array_slice($rows, 0, 20),
    ];
}

function ingredientOntologyV3ResolutionEvidenceKeyMap(
    PDO $db,
    int $versionId,
    ?string $ownerFingerprint = null
): array {
    $stmt = $db->prepare("
        SELECT evidence_kind, evidence_key, id
        FROM ingredient_ontology_evidence_sources
        WHERE ontology_version_id = ?
          AND evidence_scope = 'owner_observation'
          AND owner_fingerprint = ?
    ");
    $stmt->execute([
        $versionId,
        $ownerFingerprint ?? '',
    ]);
    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result[(string)$row['evidence_kind']][
            (string)$row['evidence_key']
        ] = (int)$row['id'];
    }
    return $result;
}

function ingredientOntologyV3ProviderLocalReviewMap(): array {
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $map = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'provider-local-reviews.csv'
        ) as $row
    ) {
        $key = ingredientOntologyV3Hash([
            'connector' => (string)$row['connector'],
            'metadata_schema_version' =>
                (string)$row['metadata_schema_version'],
            'namespace' => (string)$row['namespace'],
            'provider_ref' => (string)$row['provider_ref'],
            'title_hash' => (string)$row['title_hash'],
            'normalized_local_label' =>
                (string)$row['normalized_local_label'],
        ]);
        $map[$key] = $row;
    }
    return $map;
}

function ingredientOntologyV3ProviderFacetWaiverMap(): array {
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $map = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'provider-facet-waivers.csv'
        ) as $row
    ) {
        $key = implode("\n", [
            (string)$row['review_key'],
            (string)$row['source'],
            (string)$row['facet_key'],
            (string)$row['value_key'],
        ]);
        if (
            isset($map[$key])
            || !in_array(
                (string)$row['source'],
                ['local_label', 'provider_title'],
                true
            )
            || (string)$row['rationale'] === ''
            || (string)$row['reviewer']
                !== INGREDIENT_ONTOLOGY_V3_RESOLUTION_REVIEWER
        ) {
            throw new RuntimeException(
                'provider facet waiver manifest is invalid'
            );
        }
        $map[$key] = $row;
    }
    return $map;
}

function ingredientOntologyV3ProviderTermFacetWaiverMap(): array {
    $map = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'provider-term-facet-waivers.csv'
        ) as $row
    ) {
        $key = implode('|', [
            (string)$row['provider_ref'],
            (string)$row['facet_key'],
            (string)$row['hint_value'],
        ]);
        if (
            isset($map[$key])
            || trim((string)$row['entity_slug']) === ''
            || trim((string)$row['rationale']) === ''
            || (string)$row['reviewer']
                !== INGREDIENT_ONTOLOGY_V3_RESOLUTION_REVIEWER
            || trim((string)$row['source_citation']) === ''
        ) {
            throw new RuntimeException(
                'provider term facet waiver manifest is invalid'
            );
        }
        $map[$key] = $row;
    }
    return $map;
}

function ingredientOntologyV3GoldProviderOwnerEvidenceMap(): array {
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $map = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'gold-provider-owner-evidence.csv'
        ) as $row
    ) {
        $caseId = (string)$row['case_id'];
        if (
            isset($map[$caseId])
            || !preg_match(
                '/^[a-f0-9]{64}$/',
                (string)$row['owner_fingerprint']
            )
            || !in_array((string)$row['evidence_allowed'], ['0', '1'], true)
            || (string)$row['reviewer']
                !== INGREDIENT_ONTOLOGY_V3_RESOLUTION_REVIEWER
            || trim((string)$row['source_citation']) === ''
        ) {
            throw new RuntimeException(
                'gold provider owner evidence is invalid'
            );
        }
        $map[$caseId] = $row;
    }
    return $map;
}

function ingredientOntologyV3GoldSupersessionAudit(
    array $currentCases,
    ?array $retirementRowsOverride = null
): array {
    $prior = [];
    foreach (
        ingredientOntologyV3ResolutionReadCsvPath(
            ingredientOntologyV3ResolutionBaseDataDirectory()
                . '/resolution-gold-reviewed.csv',
            'resolution-gold-reviewed.csv'
        ) as $row
    ) {
        $prior[(string)$row['id']] = $row;
    }
    $current = [];
    $currentByDecisionKey = [];
    $canonicalContext = static function (array $context): string {
        unset($context['owner_fingerprint']);
        foreach ($context as $key => $value) {
            if ($value === '' || $value === null || $value === []) {
                unset($context[$key]);
            }
        }
        ksort($context, SORT_STRING);
        return ingredientOntologyV3Json($context);
    };
    $outcome = static function (
        string $status,
        string $entitySlug,
        array $attributes
    ): string {
        ksort($attributes, SORT_STRING);
        return ingredientOntologyV3Json([
            'status' => $status,
            'entity_slug' => $entitySlug,
            'attributes' => $attributes,
        ]);
    };
    foreach ($currentCases as $case) {
        $caseId = (string)$case['case_id'];
        $current[$caseId] = $case;
        $key = ingredientOntologyV3NormalizeLabel(
            (string)$case['original_label']
        ) . '|' . $canonicalContext(
            (array)($case['resolver_context'] ?? [])
        );
        $currentByDecisionKey[$key][] = $case;
    }
    $expectedChanged = [];
    $expectedRetired = [];
    $retained = [];
    foreach ($prior as $priorCaseId => $priorCase) {
        $priorContext = json_decode(
            (string)$priorCase['context'],
            true,
            32,
            JSON_THROW_ON_ERROR
        ) ?: [];
        $key = ingredientOntologyV3NormalizeLabel(
            (string)$priorCase['label']
        ) . '|' . $canonicalContext($priorContext);
        $candidates = $currentByDecisionKey[$key] ?? [];
        if (!$candidates) {
            $expectedRetired[$priorCaseId] = $priorCase;
            continue;
        }
        $priorAttributes = json_decode(
            (string)$priorCase['expected_attributes'],
            true,
            32,
            JSON_THROW_ON_ERROR
        ) ?: [];
        $priorOutcome = $outcome(
            (string)$priorCase['expected_status'],
            (string)$priorCase['expected_entity_slug'],
            $priorAttributes
        );
        $sameOutcome = false;
        $changedCandidates = [];
        foreach ($candidates as $candidate) {
            $candidateOutcome = $outcome(
                (string)$candidate['expected_status'],
                (string)$candidate['expected_entity_slug'],
                (array)$candidate['expected_attributes']
            );
            if ($candidateOutcome === $priorOutcome) {
                $sameOutcome = true;
                $retained[$priorCaseId] = true;
                break;
            }
            $changedCandidates[
                (string)$candidate['case_id']
            ] = $candidateOutcome;
        }
        if (!$sameOutcome && $changedCandidates) {
            $expectedChanged[$priorCaseId] = $changedCandidates;
        }
    }
    $errors = [];
    $provided = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'gold-prior-decision-supersessions.csv'
        ) as $row
    ) {
        $priorCaseId = (string)$row['prior_case_id'];
        $newCaseId = (string)$row['new_case_id'];
        $priorCase = $prior[$priorCaseId] ?? null;
        $newCase = $current[(string)$row['new_case_id']] ?? null;
        $newAttributes = json_decode(
            (string)$row['new_attributes_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        ) ?: [];
        ksort($newAttributes, SORT_STRING);
        $expectedAttributes = is_array(
            $newCase['expected_attributes'] ?? null
        ) ? $newCase['expected_attributes'] : [];
        ksort($expectedAttributes, SORT_STRING);
        $valid = $priorCase !== null
            && $newCase !== null
            && !isset($provided[$priorCaseId])
            && isset(
                $expectedChanged[$priorCaseId][$newCaseId]
            )
            && ingredientOntologyV3NormalizeLabel(
                (string)$priorCase['label']
            ) === ingredientOntologyV3NormalizeLabel(
                (string)$row['label']
            )
            && ingredientOntologyV3NormalizeLabel(
                (string)$newCase['original_label']
            ) === ingredientOntologyV3NormalizeLabel(
                (string)$row['label']
            )
            && (string)$priorCase['expected_status']
                === (string)$row['prior_status']
            && (string)$newCase['expected_status']
                === (string)$row['new_status']
            && (string)$newCase['expected_entity_slug']
                === (string)$row['new_entity_slug']
            && $expectedAttributes === $newAttributes
            && (string)$row['reviewer']
                === INGREDIENT_ONTOLOGY_V3_RESOLUTION_REVIEWER
            && trim((string)$row['rationale']) !== ''
            && trim((string)$row['source_citation']) !== '';
        $provided[$priorCaseId] = $newCaseId;
        if (!$valid && count($errors) < 100) {
            $errors[] = [
                'prior_case_id' => (string)$row['prior_case_id'],
                'new_case_id' => (string)$row['new_case_id'],
            ];
        }
    }
    $missing = array_values(array_diff(
        array_keys($expectedChanged),
        array_keys($provided)
    ));
    $extra = array_values(array_diff(
        array_keys($provided),
        array_keys($expectedChanged)
    ));
    $retirementPath = ingredientOntologyV3ResolutionFilePath(
        INGREDIENT_ONTOLOGY_V3_GOLD_RETIREMENTS_FILENAME
    );
    $retirementHash = is_file($retirementPath)
        ? hash_file('sha256', $retirementPath)
        : false;
    $retirements = [];
    $retirementErrors = [];
    $retirementRows = $retirementRowsOverride
        ?? iterator_to_array(ingredientOntologyV3ResolutionCsvRows(
            INGREDIENT_ONTOLOGY_V3_GOLD_RETIREMENTS_FILENAME
        ));
    foreach ($retirementRows as $row) {
        $priorCaseId = (string)$row['prior_case_id'];
        $priorCase = $prior[$priorCaseId] ?? null;
        $valid = $priorCase !== null
            && !isset($retirements[$priorCaseId])
            && isset($expectedRetired[$priorCaseId])
            && (string)$row['disposition'] === 'retired'
            && trim((string)$row['rationale_code']) !== ''
            && trim((string)$row['source_citation']) !== '';
        if (!$valid && count($retirementErrors) < 100) {
            $retirementErrors[] = $priorCaseId ?: '(missing)';
        }
        $retirements[$priorCaseId] = true;
    }
    $missingRetirements = array_values(array_diff(
        array_keys($expectedRetired),
        array_keys($retirements)
    ));
    $extraRetirements = array_values(array_diff(
        array_keys($retirements),
        array_keys($expectedRetired)
    ));
    $exclusiveOverlap = array_values(array_unique(array_merge(
        array_intersect(
            array_keys($retirements),
            array_keys($provided)
        ),
        array_intersect(
            array_keys($retirements),
            array_keys($retained)
        ),
        array_intersect(
            array_keys($provided),
            array_keys($retained)
        )
    )));
    $lineageTotal = count($retained)
        + count($expectedChanged)
        + count($expectedRetired);
    return [
        'valid' => !$errors && !$missing && !$extra
            && count($provided) === count($expectedChanged)
            && (
                $retirementRowsOverride !== null
                || (
                    is_string($retirementHash)
                    && hash_equals(
                        INGREDIENT_ONTOLOGY_V3_GOLD_RETIREMENTS_SHA256,
                        $retirementHash
                    )
                )
            )
            && !$retirementErrors
            && !$missingRetirements
            && !$extraRetirements
            && !$exclusiveOverlap
            && $lineageTotal === count($prior),
        'prior_case_count' => count($prior),
        'retained_case_count' => count($retained),
        'computed_changed_decision_count' => count($expectedChanged),
        'supersession_row_count' => count($provided),
        'count' => count($provided),
        'missing_count' => count($missing),
        'missing_sample' => array_slice($missing, 0, 100),
        'extra_count' => count($extra),
        'extra_sample' => array_slice($extra, 0, 100),
        'error_count' => count($errors),
        'error_sample' => $errors,
        'retirement_fixture_hash' => $retirementHash,
        'retirement_fixture_hash_matches_pin' =>
            is_string($retirementHash)
            && hash_equals(
                INGREDIENT_ONTOLOGY_V3_GOLD_RETIREMENTS_SHA256,
                $retirementHash
            ),
        'computed_retired_case_count' => count($expectedRetired),
        'retirement_row_count' => count($retirements),
        'retirement_error_count' => count($retirementErrors),
        'retirement_error_sample' => $retirementErrors,
        'missing_retirement_count' => count($missingRetirements),
        'missing_retirement_sample' =>
            array_slice($missingRetirements, 0, 100),
        'extra_retirement_count' => count($extraRetirements),
        'extra_retirement_sample' =>
            array_slice($extraRetirements, 0, 100),
        'lineage_overlap_count' => count($exclusiveOverlap),
        'lineage_overlap_sample' =>
            array_slice($exclusiveOverlap, 0, 100),
        'lineage_accounted_count' => $lineageTotal,
    ];
}

function ingredientOntologyV3DefiningAttributeHints(
    string $label
): array {
    $hints = ingredientOntologyV3ExtractAttributes($label);
    foreach (array_keys($hints) as $facet) {
        if (!ingredientOntologyV3FacetIsDefining((string)$facet)) {
            unset($hints[$facet]);
        }
    }
    ksort($hints, SORT_STRING);
    return $hints;
}

function ingredientOntologyV3ProviderFacetAudit(
    PDO $db,
    int $versionId
): array {
    $version = ingredientOntologyV3Version($db, $versionId);
    $dynamicController = $version !== null
        && function_exists(
            'ingredientOntologyControllerUsesDynamicPins'
        )
        && ingredientOntologyControllerUsesDynamicPins($version);
    $terms = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows('provider-terms.csv')
        as $term
    ) {
        $terms[(string)$term['provider_ref']] = $term;
    }
    $waivers = ingredientOntologyV3ProviderFacetWaiverMap();
    $facetMap = ingredientOntologyV3FacetMap($db, $versionId);
    $policies = ingredientOntologyV3ResolutionEntityFacetPolicyMap(
        $db,
        $versionId
    );
    $entities = ingredientOntologyV3EntityMap($db, $versionId)['by_slug'];
    $label = $db->prepare("
        SELECT l.id, l.review_state, l.provenance, e.slug,
               f.facet_key, fv.value_key
        FROM ingredient_ontology_labels l
        JOIN ingredient_ontology_entities e ON e.id = l.entity_id
        LEFT JOIN ingredient_ontology_label_attributes a
          ON a.label_id = l.id
        LEFT JOIN ingredient_ontology_facets f ON f.id = a.facet_id
        LEFT JOIN ingredient_ontology_facet_values fv
          ON fv.id = a.facet_value_id
        WHERE l.ontology_version_id = ? AND l.source_ref = ?
        ORDER BY l.id, f.facet_key
    ");
    $mapping = $db->prepare("
        SELECT m.id, m.status, m.attributes_json
        FROM ingredient_ontology_mappings m
        JOIN ingredient_ontology_label_context_policies policy
          ON policy.label_id = CAST(
              json_extract(m.evidence_json, '$.label_id') AS INTEGER
          )
         AND policy.required_evidence_kind = 'provider_owner_review'
        JOIN ingredient_ontology_evidence_sources evidence
          ON evidence.ontology_version_id = m.ontology_version_id
         AND evidence.evidence_scope = 'owner_observation'
         AND evidence.owner_fingerprint = m.owner_fingerprint
         AND json_extract(evidence.payload_json, '$.review_key')
             = policy.required_evidence_key
        WHERE m.ontology_version_id = ?
          AND m.owner_type = 'recipe_source_ingredient'
          AND m.status = 'accepted'
          AND CAST(json_extract(m.evidence_json, '$.label_id') AS INTEGER) = ?
          AND COALESCE(
              CAST(
                  json_extract(
                      m.evidence_json,
                      '$.context_gate_missing'
                  ) AS INTEGER
              ),
              0
          ) = 0
        ORDER BY m.id
    ");
    $mismatches = [];
    $unreviewedHints = [];
    $acceptedReviewCount = 0;
    $mappingCount = 0;
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'provider-local-reviews.csv'
        ) as $row
    ) {
        $acceptedReview = in_array(
            (string)$row['disposition_code'],
            ['D1', 'D2'],
            true
        );
        if (!$acceptedReview) {
            continue;
        }
        $acceptedReviewCount++;
        $expected = json_decode(
            (string)$row['attributes_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        ) ?: [];
        ksort($expected, SORT_STRING);
        $slug = ingredientOntologyV3Slug((string)$row['entity_slug']);
        $entityId = (int)($entities[$slug]['id'] ?? 0);
        $validated = $entityId > 0
            ? ingredientOntologyV3ResolutionValidateAttributes(
                $policies,
                $facetMap,
                $entityId,
                $expected
            )
            : ['valid' => false];
        $label->execute([$versionId, (string)$row['review_key']]);
        $labelRows = $label->fetchAll(PDO::FETCH_ASSOC);
        $actualAttributes = [];
        $actualSlug = null;
        $labelId = 0;
        $acceptedLabels = [];
        foreach ($labelRows as $labelRow) {
            $labelId = (int)$labelRow['id'];
            if (
                (string)$labelRow['review_state'] === 'accepted'
                && (string)$labelRow['provenance']
                    === 'provider-local-review-v3'
            ) {
                $acceptedLabels[$labelId] = true;
                $actualSlug = (string)$labelRow['slug'];
                if (
                    $labelRow['facet_key'] !== null
                    && $labelRow['value_key'] !== null
                ) {
                    $actualAttributes[(string)$labelRow['facet_key']] =
                        (string)$labelRow['value_key'];
                }
            }
        }
        ksort($actualAttributes, SORT_STRING);
        $expectedCode = $expected ? 'D2' : 'D1';
        if (
            count($acceptedLabels) !== 1
            || $actualSlug !== $slug
            || $actualAttributes !== $expected
            || (string)$row['disposition_code'] !== $expectedCode
            || empty($validated['valid'])
        ) {
            if (count($mismatches) < 100) {
                $mismatches[] = [
                    'review_key' => (string)$row['review_key'],
                    'normalized_local_label' =>
                        (string)$row['normalized_local_label'],
                    'expected_code' => $expectedCode,
                    'manifest_code' =>
                        (string)$row['disposition_code'],
                    'expected_slug' => $slug,
                    'actual_slug' => $actualSlug,
                    'expected_attributes' => $expected,
                    'actual_attributes' => $actualAttributes,
                    'attribute_validation' => $validated,
                ];
            }
        }
        $providerTitle = (string)(
            $terms[(string)$row['provider_ref']]['default_title']
                ?? ''
        );
        foreach ([
            'local_label' => (string)$row['normalized_local_label'],
            'provider_title' => $providerTitle,
        ] as $source => $text) {
            foreach (
                ingredientOntologyV3DefiningAttributeHints($text)
                as $facet => $value
            ) {
                if (($expected[$facet] ?? null) === $value) {
                    continue;
                }
                $waiverKey = implode("\n", [
                    (string)$row['review_key'],
                    $source,
                    (string)$facet,
                    (string)$value,
                ]);
                if (!isset($waivers[$waiverKey])) {
                    if (count($unreviewedHints) < 100) {
                        $unreviewedHints[] = [
                            'review_key' => (string)$row['review_key'],
                            'source' => $source,
                            'label' => $text,
                            'facet_key' => (string)$facet,
                            'value_key' => (string)$value,
                            'expected_attributes' => $expected,
                        ];
                    }
                }
            }
        }
        foreach (array_keys($acceptedLabels) as $acceptedLabelId) {
            $mapping->execute([$versionId, $acceptedLabelId]);
            while ($mappingRow = $mapping->fetch(PDO::FETCH_ASSOC)) {
                $mappingCount++;
                $mappingAttributes = json_decode(
                    (string)$mappingRow['attributes_json'],
                    true
                ) ?: [];
                ksort($mappingAttributes, SORT_STRING);
                if (
                    (string)$mappingRow['status'] !== 'accepted'
                    || $mappingAttributes !== $expected
                ) {
                    if (count($mismatches) < 100) {
                        $mismatches[] = [
                            'review_key' => (string)$row['review_key'],
                            'mapping_id' => (int)$mappingRow['id'],
                            'expected_attributes' => $expected,
                            'actual_attributes' => $mappingAttributes,
                            'actual_status' =>
                                (string)$mappingRow['status'],
                        ];
                    }
                }
            }
        }
    }
    $termWaivers = ingredientOntologyV3ProviderTermFacetWaiverMap();
    $usedTermWaivers = [];
    $termFacetFailures = [];
    $termSignatureFailures = [];
    $termRowFailures = [];
    $acceptedTermCount = 0;
    $acceptedTermTitleSignatureCount = 0;
    $labelIndex = ingredientOntologyV3LabelIndex($db, $versionId);
    $goldSignatures = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_FILENAME
        ) as $gold
    ) {
        if ((string)$gold['expected_status'] !== 'accepted') {
            continue;
        }
        $context = json_decode(
            (string)$gold['resolver_context_json'],
            true
        ) ?: [];
        unset($context['owner_fingerprint']);
        $context = array_filter(
            $context,
            static fn(mixed $value): bool =>
                $value !== '' && $value !== null && $value !== []
        );
        if ($context) {
            continue;
        }
        $attributes = json_decode(
            (string)$gold['expected_attributes_json'],
            true
        ) ?: [];
        ksort($attributes, SORT_STRING);
        $signature = ingredientOntologyV3Json([
            'entity_slug' =>
                (string)$gold['expected_entity_slug'],
            'attributes' => $attributes,
        ]);
        $goldSignatures[
            ingredientOntologyV3NormalizeLabel(
                (string)$gold['original_label']
            )
        ][$signature] = true;
    }
    $storedTerms = [];
    $stored = $db->prepare("
        SELECT term.provider_ref, term.mapping_status,
               term.attributes_json, entity.slug AS entity_slug,
               disposition.disposition_code
        FROM ingredient_ontology_provider_terms term
        LEFT JOIN ingredient_ontology_entities entity
          ON entity.id = term.entity_id
        LEFT JOIN ingredient_ontology_terminal_dispositions disposition
          ON disposition.id = term.terminal_disposition_id
        WHERE term.ontology_version_id = ?
    ");
    $stored->execute([$versionId]);
    while ($storedTerm = $stored->fetch(PDO::FETCH_ASSOC)) {
        $storedTerms[(string)$storedTerm['provider_ref']] =
            $storedTerm;
    }
    foreach ($terms as $providerRef => $term) {
        $code = (string)$term['disposition_code'];
        if (!in_array($code, ['D1', 'D2'], true)) {
            continue;
        }
        $acceptedTermCount++;
        $expected = json_decode(
            (string)$term['attributes_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        ) ?: [];
        ksort($expected, SORT_STRING);
        $slug = ingredientOntologyV3Slug(
            (string)$term['entity_slug']
        );
        $entityId = (int)($entities[$slug]['id'] ?? 0);
        $validated = $entityId > 0
            ? ingredientOntologyV3ResolutionValidateAttributes(
                $policies,
                $facetMap,
                $entityId,
                $expected
            )
            : ['valid' => false];
        $expectedCode = $expected ? 'D2' : 'D1';
        if ($code !== $expectedCode || empty($validated['valid'])) {
            if (count($termRowFailures) < 100) {
                $termRowFailures[] = [
                    'provider_ref' => $providerRef,
                    'manifest_code' => $code,
                    'expected_code' => $expectedCode,
                    'entity_slug' => $slug,
                    'attributes' => $expected,
                    'attribute_validation' => $validated,
                ];
            }
        }
        $storedTerm = $storedTerms[$providerRef] ?? null;
        if ($storedTerm !== null) {
            $storedAttributes = json_decode(
                (string)$storedTerm['attributes_json'],
                true
            ) ?: [];
            ksort($storedAttributes, SORT_STRING);
            if (
                (string)$storedTerm['mapping_status'] !== 'accepted'
                || (string)$storedTerm['entity_slug'] !== $slug
                || $storedAttributes !== $expected
                || (string)$storedTerm['disposition_code'] !== $code
            ) {
                if (count($termRowFailures) < 100) {
                    $termRowFailures[] = [
                        'provider_ref' => $providerRef,
                        'expected' => [
                            'status' => 'accepted',
                            'entity_slug' => $slug,
                            'attributes' => $expected,
                            'disposition_code' => $code,
                        ],
                        'actual' => $storedTerm,
                    ];
                }
            }
        }
        foreach (
            ingredientOntologyV3DefiningAttributeHints(
                (string)$term['default_title']
            ) as $facet => $value
        ) {
            if (($expected[$facet] ?? null) === $value) {
                continue;
            }
            $waiverKey = implode('|', [
                $providerRef,
                (string)$facet,
                (string)$value,
            ]);
            if (
                isset($termWaivers[$waiverKey])
                && ingredientOntologyV3Slug(
                    (string)$termWaivers[$waiverKey]['entity_slug']
                ) === $slug
            ) {
                $usedTermWaivers[$waiverKey] = true;
                continue;
            }
            if (count($termFacetFailures) < 100) {
                $termFacetFailures[] = [
                    'provider_ref' => $providerRef,
                    'title' => (string)$term['default_title'],
                    'facet_key' => (string)$facet,
                    'hint_value' => (string)$value,
                    'expected_attributes' => $expected,
                ];
            }
        }
        $expectedSignature = ingredientOntologyV3Json([
            'entity_slug' => $slug,
            'attributes' => $expected,
        ]);
        $global = ingredientOntologyV3ResolveLabel(
            $labelIndex,
            (string)$term['default_title'],
            'en'
        );
        $comparisonSignatures = [];
        if ((string)$global['status'] === 'accepted') {
            $globalAttributes = (array)($global['attributes'] ?? []);
            ksort($globalAttributes, SORT_STRING);
            $comparisonSignatures['global_alias'] =
                ingredientOntologyV3Json([
                    'entity_slug' =>
                        (string)($global['entity_slug'] ?? ''),
                    'attributes' => $globalAttributes,
                ]);
        }
        $normalizedTitle = ingredientOntologyV3NormalizeLabel(
            (string)$term['default_title']
        );
        foreach (
            array_keys($goldSignatures[$normalizedTitle] ?? [])
            as $goldSignature
        ) {
            $comparisonSignatures['gold:' . substr(
                hash('sha256', $goldSignature),
                0,
                12
            )] = $goldSignature;
        }
        if ($comparisonSignatures) {
            $acceptedTermTitleSignatureCount++;
        }
        foreach ($comparisonSignatures as $source => $signature) {
            if ($signature === $expectedSignature) {
                continue;
            }
            if (count($termSignatureFailures) < 100) {
                $termSignatureFailures[] = [
                    'provider_ref' => $providerRef,
                    'title' => (string)$term['default_title'],
                    'source' => $source,
                    'expected_signature' => json_decode(
                        $expectedSignature,
                        true
                    ),
                    'reviewed_signature' => json_decode(
                        $signature,
                        true
                    ),
                ];
            }
        }
    }
    $unusedTermWaivers = array_values(array_diff(
        array_keys($termWaivers),
        array_keys($usedTermWaivers)
    ));
    $storedTermCount = count($storedTerms);
    $dynamicTermFailures = [];
    if ($dynamicController) {
        foreach ($storedTerms as $providerRef => $storedTerm) {
            if (isset($terms[$providerRef])) {
                continue;
            }
            $attributes = json_decode(
                (string)$storedTerm['attributes_json'],
                true
            ) ?: [];
            if (
                (string)$storedTerm['mapping_status'] !== 'unresolved'
                || $storedTerm['entity_slug'] !== null
                || $attributes !== []
                || (string)$storedTerm['disposition_code'] !== 'D8'
            ) {
                if (count($dynamicTermFailures) < 100) {
                    $dynamicTermFailures[] = [
                        'provider_ref' => $providerRef,
                        'actual' => $storedTerm,
                    ];
                }
            }
        }
    }
    $storedTermCountValid = $dynamicController
        ? !$dynamicTermFailures
            && (
                $storedTermCount === 0
                || $storedTermCount >= count($terms)
            )
        : (
            (
                defined('RECIPE_BACKEND_TEST_MODE')
                && RECIPE_BACKEND_TEST_MODE
            )
            || in_array($storedTermCount, [0, 646], true)
        );
    return [
        'valid' => !$mismatches
            && !$unreviewedHints
            && count($terms) === 646
            && $storedTermCountValid
            && !$termRowFailures
            && !$termFacetFailures
            && !$termSignatureFailures
            && !$unusedTermWaivers,
        'accepted_review_count' => $acceptedReviewCount,
        'observed_mapping_count' => $mappingCount,
        'provider_expected_attribute_mismatch_count' =>
            count($mismatches),
        'provider_expected_attribute_mismatch_sample' => $mismatches,
        'provider_parsed_hard_facet_unreviewed_count' =>
            count($unreviewedHints),
        'provider_parsed_hard_facet_unreviewed_sample' =>
            $unreviewedHints,
        'waiver_count' => count($waivers),
        'provider_term_review_count' => count($terms),
        'stored_provider_term_count' => $storedTermCount,
        'dynamic_provider_term_failure_count' =>
            count($dynamicTermFailures),
        'dynamic_provider_term_failure_sample' =>
            $dynamicTermFailures,
        'accepted_provider_term_count' => $acceptedTermCount,
        'accepted_provider_title_signature_count' =>
            $acceptedTermTitleSignatureCount,
        'provider_term_row_mismatch_count' =>
            count($termRowFailures),
        'provider_term_row_mismatch_sample' => $termRowFailures,
        'provider_term_hard_facet_unreviewed_count' =>
            count($termFacetFailures),
        'provider_term_hard_facet_unreviewed_sample' =>
            $termFacetFailures,
        'provider_term_signature_disagreement_count' =>
            count($termSignatureFailures),
        'provider_term_signature_disagreement_sample' =>
            $termSignatureFailures,
        'provider_term_facet_waiver_count' => count($termWaivers),
        'unused_provider_term_facet_waiver_count' =>
            count($unusedTermWaivers),
        'unused_provider_term_facet_waiver_sample' =>
            array_slice($unusedTermWaivers, 0, 100),
    ];
}

function ingredientOntologyV3GenericIdentityRationaleAudit(
    PDO $db,
    int $versionId
): array {
    $reviewed = [];
    $errors = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'generic-identity-rationales.csv'
        ) as $row
    ) {
        $slug = ingredientOntologyV3Slug((string)$row['entity_slug']);
        if (
            isset($reviewed[$slug])
            || (string)$row['decision'] !== 'retain_identity'
            || (string)$row['contrast_policy'] !== 'same_entity_only'
            || trim((string)$row['rationale']) === ''
            || (string)$row['reviewer']
                !== INGREDIENT_ONTOLOGY_V3_RESOLUTION_REVIEWER
            || trim((string)$row['source_citation']) === ''
        ) {
            $errors[] = $slug ?: '(missing)';
            continue;
        }
        $reviewed[$slug] = $row;
    }
    $required = [];
    $stmt = $db->prepare("
        SELECT DISTINCT parent.slug
        FROM ingredient_ontology_relations relation
        JOIN ingredient_ontology_entities child
          ON child.id = relation.from_entity_id
         AND child.active = 1
        JOIN ingredient_ontology_entities parent
          ON parent.id = relation.to_entity_id
         AND parent.active = 1
        WHERE relation.ontology_version_id = ?
          AND relation.relation = 'is_a'
          AND relation.is_primary = 1
          AND parent.identity_role IN (
              'identity_leaf', 'prepared_identity',
              'composite_identity', 'staple_class'
          )
        ORDER BY parent.slug
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $required[(string)$row['slug']] = true;
    }
    $chocolate = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_entities entity
        WHERE entity.ontology_version_id = ?
          AND entity.slug = 'chocolate'
          AND entity.active = 1
          AND entity.identity_role = 'identity_leaf'
    ");
    $chocolate->execute([$versionId]);
    if ((int)$chocolate->fetchColumn() === 1) {
        $required['chocolate'] = true;
    }
    $missing = array_values(array_diff(
        array_keys($required),
        array_keys($reviewed)
    ));
    $extra = array_values(array_diff(
        array_keys($reviewed),
        array_keys($required)
    ));
    return [
        'valid' => !$errors && !$missing && !$extra,
        'required_generic_identity_count' => count($required),
        'reviewed_generic_identity_count' => count($reviewed),
        'missing_count' => count($missing),
        'missing_sample' => array_slice($missing, 0, 100),
        'extra_count' => count($extra),
        'extra_sample' => array_slice($extra, 0, 100),
        'invalid_row_count' => count($errors),
        'invalid_row_sample' => array_slice($errors, 0, 100),
    ];
}

function ingredientOntologyV3OwnerEvidenceContext(
    PDO $db,
    int $versionId,
    array $row,
    string $ownerFingerprint
): array {
    $providerRef = trim(
        (string)($row['source_ingredient_ref'] ?? '')
    );
    $title = trim((string)($row['source_default_title'] ?? ''));
    if ($providerRef === '' || $title === '') {
        return ['evidence' => [], 'provider_review' => null];
    }
    $connector = trim((string)($row['connector'] ?? ''));
    $schema = trim(
        (string)($row['metadata_schema_version'] ?? '')
    );
    $namespace = ingredientOntologyV3ProviderNamespace($providerRef);
    $titleHash = hash('sha256', $title);
    $local = ingredientOntologyV3NormalizeProviderLabel(
        (string)($row['source_label'] ?? '')
    )['normalized'];
    $lookup = ingredientOntologyV3Hash([
        'connector' => $connector,
        'metadata_schema_version' => $schema,
        'namespace' => $namespace,
        'provider_ref' => $providerRef,
        'title_hash' => $titleHash,
        'normalized_local_label' => $local,
    ]);
    $review = ingredientOntologyV3ProviderLocalReviewMap()[$lookup]
        ?? null;
    if ($review === null) {
        return ['evidence' => [], 'provider_review' => null];
    }
    $manifestIdStmt = $db->prepare("
        SELECT id
        FROM ingredient_ontology_resolution_manifests
        WHERE ontology_version_id = ?
          AND manifest_version = ?
    ");
    $manifestIdStmt->execute([
        $versionId,
        INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION,
    ]);
    $manifestId = (int)$manifestIdStmt->fetchColumn();
    $reviewKey = (string)$review['review_key'];
    $evidenceKey = $reviewKey . ':' . substr(
        $ownerFingerprint,
        0,
        24
    );
    ingredientOntologyV3InsertEvidenceSource(
        $db,
        $versionId,
        $manifestId > 0 ? $manifestId : null,
        'provider_ref_cluster',
        $evidenceKey,
        [
            'review_key' => $reviewKey,
            'normalized_local_label' => $local,
            'provider_ref' => $providerRef,
            'title_hash' => $titleHash,
            'owner_fingerprint' => $ownerFingerprint,
            'identity_effect' => 'owner_scoped_review_only',
        ],
        'provider-owner-review-v3',
        INGREDIENT_ONTOLOGY_V3_RESOLUTION_REVIEWER,
        INGREDIENT_ONTOLOGY_V3_RESOLUTION_BATCH,
        [
            'evidence_scope' => 'owner_observation',
            'owner_fingerprint' => $ownerFingerprint,
            'connector' => $connector,
            'metadata_schema_version' => $schema,
            'provider_ref' => $providerRef,
            'title_hash' => $titleHash,
            'observation_hash' => ingredientOntologyV3Hash([
                'owner_fingerprint' => $ownerFingerprint,
                'connector' => $connector,
                'metadata_schema_version' => $schema,
                'provider_ref' => $providerRef,
                'title_hash' => $titleHash,
                'normalized_local_label' => $local,
            ]),
        ]
    );
    return [
        'evidence' => [
            'provider_owner_review' => [$reviewKey => true],
        ],
        'provider_review' => $review,
    ];
}

function ingredientOntologyV3BuildRecipeCohorts(
    PDO $db,
    int $versionId,
    int $manifestId
): array {
    $algorithmHash = ingredientOntologyV3Hash([
        'algorithm' => INGREDIENT_ONTOLOGY_V3_COHORT_ALGORITHM,
        'minimum_winner_votes' => 2,
        'unique_winner' => true,
        'gated_aliases_do_not_vote' => true,
        'reviewed_cohort_anchor_count' => 322,
        'english_requires_zero_nonenglish_conflicts' => true,
        'und_does_not_vote' => true,
    ]);
    $evidenceId = ingredientOntologyV3InsertEvidenceSource(
        $db,
        $versionId,
        $manifestId,
        'recipe_cohort',
        'recipe-cohort-algorithm',
        [
            'algorithm' => INGREDIENT_ONTOLOGY_V3_COHORT_ALGORITHM,
            'minimum_winner_votes' => 2,
            'unique_winner' => true,
            'english_requires_zero_nonenglish_conflicts' => true,
            'context_gate_only' => true,
        ],
        $algorithmHash
    );
    $voteMap = [];
    $reviewedAliases = [];
    foreach (ingredientOntologyV3MultilingualStapleAliases() as $alias) {
        $reviewedAliases[] = [(string)$alias[1], (string)$alias[2]];
    }
    foreach (ingredientOntologyV3CuratedAliases() as $alias) {
        $reviewedAliases[] = [(string)$alias[1], (string)$alias[2]];
    }
    $reviewedAliases[] = ['Extra Virgin Olive Oil', 'en'];
    if (count($reviewedAliases) !== 322) {
        throw new RuntimeException(
            'recipe cohort reviewed alias seed changed unexpectedly'
        );
    }
    foreach ($reviewedAliases as [$label, $language]) {
        $language = explode(
            '-',
            ingredientOntologyV3NormalizeLanguage($language)
        )[0];
        if ($language === 'und') {
            continue;
        }
        $voteMap[
            ingredientOntologyV3NormalizeLabel($label)
        ][$language] = true;
    }
    foreach ($voteMap as $label => $languages) {
        if (count($languages) !== 1) {
            unset($voteMap[$label]);
            continue;
        }
        $voteMap[$label] = (string)array_key_first($languages);
    }
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_recipe_cohorts (
            ontology_version_id, recipe_id, cohort, winner_votes,
            runner_up_votes, margin, conflict_count, votes_json,
            recipe_fingerprint, algorithm_hash, evidence_source_id
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt = $db->query("
        SELECT ingredient.recipe_id,
               COALESCE(
                   NULLIF(ingredient.raw_text, ''),
                   ingredient.normalized_name
               ) AS label,
               catalog.primary_connector,
               COALESCE(origin.external_id, '') AS origin_external_id,
               COALESCE(origin.locale, '') AS origin_locale
        FROM recipe_ingredients ingredient
        JOIN recipe_catalog catalog ON catalog.id = ingredient.recipe_id
        LEFT JOIN recipe_origins origin
          ON origin.id = (
              SELECT candidate.id
              FROM recipe_origins candidate
              WHERE candidate.recipe_id = ingredient.recipe_id
                AND candidate.connector = catalog.primary_connector
              ORDER BY candidate.id
              LIMIT 1
          )
        ORDER BY ingredient.recipe_id, ingredient.id
    ");
    $currentRecipe = null;
    $currentRecipeKey = null;
    $votes = [];
    $counts = [];
    $uniqueWinner = 0;
    $flush = static function () use (
        &$currentRecipe,
        &$currentRecipeKey,
        &$votes,
        &$counts,
        &$uniqueWinner,
        $insert,
        $versionId,
        $algorithmHash,
        $evidenceId
    ): void {
        if ($currentRecipe === null) {
            return;
        }
        arsort($votes, SORT_NUMERIC);
        $languages = array_keys($votes);
        $winner = $languages[0] ?? null;
        $winnerVotes = $winner !== null ? (int)$votes[$winner] : 0;
        $runnerUp = isset($languages[1])
            ? (int)$votes[$languages[1]]
            : 0;
        $tied = $winner !== null
            && count(array_filter(
                $votes,
                static fn(int $value): bool => $value === $winnerVotes
            )) > 1;
        $cohort = !$tied
            && $winnerVotes >= 2
            && (
                $winner !== 'en'
                || array_sum($votes) === $winnerVotes
            )
                ? $winner
                : null;
        if ($cohort !== null) {
            $uniqueWinner++;
            $counts[$cohort] = ($counts[$cohort] ?? 0) + 1;
        } else {
            $counts['none'] = ($counts['none'] ?? 0) + 1;
        }
        ksort($votes, SORT_STRING);
        $payload = [
            'recipe_key' => $currentRecipeKey,
            'votes' => $votes,
            'cohort' => $cohort,
        ];
        $insert->execute([
            $versionId,
            $currentRecipe,
            $cohort,
            $winnerVotes,
            $runnerUp,
            max(0, $winnerVotes - $runnerUp),
            max(0, count($votes) - ($winner !== null ? 1 : 0)),
            ingredientOntologyV3Json($votes),
            ingredientOntologyV3Hash($payload),
            $algorithmHash,
            $evidenceId,
        ]);
    };
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $recipeId = (int)$row['recipe_id'];
        if ($currentRecipe !== null && $recipeId !== $currentRecipe) {
            $flush();
            $votes = [];
        }
        $currentRecipe = $recipeId;
        $currentRecipeKey = [
            'connector' => (string)$row['primary_connector'],
            'external_id' => (string)$row['origin_external_id'],
            'locale' => (string)$row['origin_locale'],
        ];
        $normalized = ingredientOntologyV3NormalizeLabel(
            (string)$row['label']
        );
        $language = $voteMap[$normalized] ?? null;
        if ($language !== null) {
            $votes[$language] = ($votes[$language] ?? 0) + 1;
        }
    }
    $flush();
    ksort($counts, SORT_STRING);
    return [
        'algorithm_hash' => $algorithmHash,
        'recipe_count' => array_sum($counts),
        'unique_winner_count' => $uniqueWinner,
        'by_cohort' => $counts,
        'context_gate_only' => true,
    ];
}

function ingredientOntologyV3RecipeCohortMap(
    PDO $db,
    int $versionId
): array {
    $stmt = $db->prepare("
        SELECT recipe_id, cohort
        FROM ingredient_ontology_recipe_cohorts
        WHERE ontology_version_id = ? AND cohort IS NOT NULL
    ");
    $stmt->execute([$versionId]);
    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result[(int)$row['recipe_id']] = (string)$row['cohort'];
    }
    return $result;
}

function ingredientOntologyV3EvaluateResolutionGold(
    PDO $db,
    int $versionId,
    bool $verifyVersionHash = true,
    ?array $overrideIndex = null
): array {
    $path = ingredientOntologyV3ResolutionFilePath(
        INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_FILENAME
    );
    $fixtureHash = hash_file('sha256', $path);
    $version = ingredientOntologyV3Version($db, $versionId);
    $errors = [];
    if (
        !is_string($fixtureHash)
        || !preg_match(
            '/^[a-f0-9]{64}$/',
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_SHA256
        )
        || !hash_equals(
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_SHA256,
            $fixtureHash
        )
    ) {
        $errors[] = 'resolution gold does not match the pinned adjudicated hash';
    }
    if (
        $verifyVersionHash
        && (
            $version === null
            || !hash_equals(
                (string)($version['resolution_gold_hash'] ?? ''),
                $fixtureHash
            )
        )
    ) {
        $errors[] = 'resolution gold hash is not sealed to the version';
    }
    $goldReview = ingredientOntologyV3ResolutionManifest()[
        'gold_review_metadata'
    ] ?? [];
    $cases = [];
    $caseIds = [];
    $sourceKeys = [];
    $positiveCount = 0;
    $negativeCount = 0;
    $structuralErrors = [];
    $allowedEvidenceCategories = [
        'culinary_identity',
        'provider_ref_title_hash',
        'product_fingerprint',
        'known_homograph_negative',
        'reviewed_negative',
    ];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_FILENAME
        ) as $row
    ) {
        $id = (string)$row['case_id'];
        $polarity = (string)$row['polarity'];
        $label = (string)$row['original_label'];
        $sourceKey = (string)$row['source_record_key'];
        $attributes = json_decode(
            (string)$row['expected_attributes_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $context = json_decode(
            (string)$row['resolver_context_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $expectedEvidence = json_decode(
            (string)$row['expected_evidence_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $validStructure = preg_match(
            '/^[a-z0-9][a-z0-9._:-]{3,119}$/',
            $id
        )
            && !isset($caseIds[$id])
            && in_array(
                $polarity,
                ['positive', 'critical_negative'],
                true
            )
            && trim($label) !== ''
            && !preg_match('/^\([^()]+\)$/u', trim($label))
            && $sourceKey !== ''
            && !isset($sourceKeys[$sourceKey])
            && is_array($attributes)
            && is_array($context)
            && is_array($expectedEvidence)
            && $expectedEvidence !== []
            && in_array(
                (string)$row['evidence_category'],
                $allowedEvidenceCategories,
                true
            )
            && trim((string)$row['primary_evidence_citation']) !== ''
            && trim((string)$row['rationale']) !== ''
            && trim((string)$row['adjudicator']) !== ''
            && trim((string)$row['adjudicated_at']) !== '';
        if (!$validStructure) {
            if (count($structuralErrors) < 100) {
                $structuralErrors[] = $id ?: '(missing case id)';
            }
            continue;
        }
        $caseIds[$id] = true;
        $sourceKeys[$sourceKey] = true;
        ksort($attributes, SORT_STRING);
        $row['expected_attributes'] = $attributes;
        $row['resolver_context'] = $context;
        $row['expected_evidence'] = $expectedEvidence;
        $cases[] = $row;
        if ($polarity === 'positive') {
            $positiveCount++;
        } else {
            $negativeCount++;
        }
    }
    if ($positiveCount < 50 || $negativeCount < 40) {
        $structuralErrors[] =
            'adjudicated gold lacks broad positive/negative coverage';
    }
    if ($structuralErrors) {
        $errors[] = 'resolution gold structure is invalid';
    }
    $supersessionAudit =
        ingredientOntologyV3GoldSupersessionAudit($cases);
    if (!$supersessionAudit['valid']) {
        $errors[] = 'resolution gold supersession audit failed';
    }
    $index = $overrideIndex
        ?? ingredientOntologyV3LabelIndex($db, $versionId);
    $providerReviews = ingredientOntologyV3ProviderLocalReviewMap();
    $providerTerms = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows('provider-terms.csv')
        as $providerTerm
    ) {
        $providerTerms[(string)$providerTerm['provider_ref']] =
            $providerTerm;
    }
    $goldProviderOwners =
        ingredientOntologyV3GoldProviderOwnerEvidenceMap();
    $failed = [];
    $sourceEvidenceFailures = [];
    $testOnlySkippedProductCases = 0;
    foreach ($cases as $case) {
        $context = $case['resolver_context'];
        $sourceEvidenceValid = true;
        if (
            (string)$case['source_corpus']
                === 'frozen_eval_recipe_ingredient'
            && !(
                defined('RECIPE_BACKEND_TEST_MODE')
                && RECIPE_BACKEND_TEST_MODE
            )
        ) {
            $sourceId = preg_match(
                '/^eval-ri-([1-9][0-9]*)$/',
                (string)$case['source_record_key'],
                $sourceMatch
            ) ? (int)$sourceMatch[1] : 0;
            $source = $db->prepare("
                SELECT raw_text, normalized_name
                FROM recipe_ingredients
                WHERE id = ?
            ");
            $source->execute([$sourceId]);
            $source = $source->fetch(PDO::FETCH_ASSOC);
            $sourceEvidenceValid = $source
                && ingredientOntologyV3NormalizeLabel(
                    (string)$case['original_label']
                ) === ingredientOntologyV3NormalizeLabel(
                    (string)(
                        trim((string)$source['raw_text']) !== ''
                            ? $source['raw_text']
                            : $source['normalized_name']
                    )
                );
            if (!$sourceEvidenceValid) {
                $sourceEvidenceFailures[] = (string)$case['case_id'];
            }
        }
        $resolverContext = [];
        if (trim((string)($context['cohort'] ?? '')) !== '') {
            $resolverContext['cohort'] =
                trim((string)$context['cohort']);
        }
        $reviewKey = trim(
            (string)($context['provider_review_key'] ?? '')
        );
        if ($reviewKey !== '') {
            $matchingReview = null;
            foreach ($providerReviews as $review) {
                if (
                    (string)$review['review_key'] === $reviewKey
                    && (string)$review['provider_ref']
                        === (string)($context['provider_ref'] ?? '')
                    && (string)$review['title_hash']
                        === (string)($context['title_hash'] ?? '')
                ) {
                    $matchingReview = $review;
                    break;
                }
            }
            $ownerEvidence = $goldProviderOwners[
                (string)$case['case_id']
            ] ?? null;
            $ownerMatches = $ownerEvidence !== null
                && hash_equals(
                    (string)$ownerEvidence['owner_fingerprint'],
                    (string)($context['owner_fingerprint'] ?? '')
                )
                && (string)$ownerEvidence['review_key'] === $reviewKey
                && (string)$ownerEvidence['provider_ref']
                    === (string)($context['provider_ref'] ?? '')
                && (string)$ownerEvidence['title_hash']
                    === (string)($context['title_hash'] ?? '')
                && (string)$ownerEvidence['normalized_local_label']
                    === ingredientOntologyV3NormalizeProviderLabel(
                        (string)$case['original_label']
                    )['normalized'];
            if (
                $matchingReview !== null
                && $ownerMatches
                && (string)$ownerEvidence['evidence_allowed'] === '1'
            ) {
                $resolverContext['evidence'][
                    'provider_owner_review'
                ][$reviewKey] = true;
            }
        }
        $providerTermRef = trim(
            (string)($context['provider_term_ref'] ?? '')
        );
        if ($providerTermRef !== '') {
            $providerTerm = $providerTerms[$providerTermRef] ?? null;
            $sourceEvidenceValid = $sourceEvidenceValid
                && $providerTerm !== null
                && hash_equals(
                    (string)($providerTerm['title_hash'] ?? ''),
                    (string)($context['title_hash'] ?? '')
                )
                && ingredientOntologyV3NormalizeLabel(
                    (string)($providerTerm['default_title'] ?? '')
                ) === ingredientOntologyV3NormalizeLabel(
                    (string)$case['original_label']
                );
            $storedTerm = $db->prepare("
                SELECT term.mapping_status, term.attributes_json,
                       entity.slug AS entity_slug,
                       disposition.mechanism,
                       disposition.disposition_code
                FROM ingredient_ontology_provider_terms term
                LEFT JOIN ingredient_ontology_entities entity
                  ON entity.id = term.entity_id
                LEFT JOIN ingredient_ontology_terminal_dispositions
                    disposition
                  ON disposition.id = term.terminal_disposition_id
                WHERE term.ontology_version_id = ?
                  AND term.provider_ref = ?
            ");
            $storedTerm->execute([$versionId, $providerTermRef]);
            $storedTerm = $storedTerm->fetch(PDO::FETCH_ASSOC);
            if ($storedTerm) {
                $actual = [
                    'status' => (string)$storedTerm['mapping_status'],
                    'entity_slug' => $storedTerm['entity_slug'] !== null
                        ? (string)$storedTerm['entity_slug']
                        : null,
                    'attributes' => json_decode(
                        (string)$storedTerm['attributes_json'],
                        true
                    ) ?: [],
                    'mapping_source' =>
                        (string)$storedTerm['mechanism'],
                    'disposition_code' =>
                        (string)$storedTerm['disposition_code'],
                ];
            } elseif ($providerTerm !== null) {
                $providerCode = (string)$providerTerm[
                    'disposition_code'
                ];
                $actual = [
                    'status' => in_array(
                        $providerCode,
                        ['D1', 'D2'],
                        true
                    ) ? 'accepted' : 'unresolved',
                    'entity_slug' => in_array(
                        $providerCode,
                        ['D1', 'D2'],
                        true
                    ) ? ingredientOntologyV3Slug(
                        (string)$providerTerm['entity_slug']
                    ) : null,
                    'attributes' => json_decode(
                        (string)$providerTerm['attributes_json'],
                        true
                    ) ?: [],
                    'mapping_source' => in_array(
                        $providerCode,
                        ['D1', 'D2'],
                        true
                    )
                        ? 'explicit_provider_term_manifest'
                        : 'explicit_provider_term_unresolved_manifest',
                    'disposition_code' => $providerCode,
                ];
            } else {
                $actual = [
                    'status' => 'unresolved',
                    'entity_slug' => null,
                    'attributes' => [],
                    'mapping_source' =>
                        'missing_provider_term_review',
                    'disposition_code' => null,
                ];
            }
        } elseif (
            trim((string)($context['product_fingerprint'] ?? '')) !== ''
        ) {
            $product = $db->prepare("
                SELECT assertion.product_name, assertion.status,
                       assertion.attributes_json,
                       entity.slug AS entity_slug,
                       disposition.mechanism,
                       disposition.disposition_code
                FROM ingredient_ontology_curated_product_assertions assertion
                LEFT JOIN ingredient_ontology_entities entity
                  ON entity.id = assertion.entity_id
                LEFT JOIN ingredient_ontology_terminal_dispositions disposition
                  ON disposition.id = assertion.terminal_disposition_id
                WHERE assertion.ontology_version_id = ?
                  AND assertion.product_fingerprint = ?
            ");
            $product->execute([
                $versionId,
                (string)$context['product_fingerprint'],
            ]);
            $product = $product->fetch(PDO::FETCH_ASSOC);
            if (
                !$product
                && defined('RECIPE_BACKEND_TEST_MODE')
                && RECIPE_BACKEND_TEST_MODE
            ) {
                $testOnlySkippedProductCases++;
                continue;
            }
            $productLabelMatches = $product
                && ingredientOntologyV3NormalizeLabel(
                    (string)$product['product_name']
                ) === ingredientOntologyV3NormalizeLabel(
                    (string)$case['original_label']
                );
            $actual = $productLabelMatches ? [
                'status' => (string)$product['status'],
                'entity_slug' => $product['entity_slug'] !== null
                    ? (string)$product['entity_slug']
                    : null,
                'attributes' => json_decode(
                    (string)$product['attributes_json'],
                    true
                ) ?: [],
                'mapping_source' => (string)$product['mechanism'],
                'disposition_code' =>
                    (string)$product['disposition_code'],
            ] : [
                'status' => 'unresolved',
                'entity_slug' => null,
                'attributes' => [],
                'mapping_source' => $product
                    ? 'product_fingerprint_label_mismatch'
                    : 'missing_product_fingerprint',
                'disposition_code' => null,
            ];
        } else {
            $actual = ingredientOntologyV3ResolveLabel(
                $index,
                (string)$case['original_label'],
                (string)$case['language'],
                $resolverContext
            );
        }
        $expectedAttributes = $case['expected_attributes'];
        $actualAttributes = is_array($actual['attributes'] ?? null)
            ? $actual['attributes']
            : [];
        ksort($actualAttributes, SORT_STRING);
        $expectedEntity = trim(
            (string)($case['expected_entity_slug'] ?? '')
        );
        $actualEvidence = [
            'mapping_source' =>
                (string)($actual['mapping_source'] ?? ''),
            'label_provenance' =>
                $actual['label_provenance'] ?? null,
            'label_source_ref' =>
                $actual['label_source_ref'] ?? null,
            'required_cohort' =>
                $actual['required_cohort'] ?? null,
            'required_evidence_kind' =>
                $actual['required_evidence_kind'] ?? null,
            'required_evidence_key' =>
                $actual['required_evidence_key'] ?? null,
            'disposition_code' =>
                $actual['disposition_code'] ?? null,
        ];
        $evidenceMatches = true;
        foreach ($case['expected_evidence'] as $key => $value) {
            if (($actualEvidence[$key] ?? null) !== $value) {
                $evidenceMatches = false;
                break;
            }
        }
        $valid = (string)$actual['status']
                === (string)$case['expected_status']
            && $sourceEvidenceValid
            && (
                $expectedEntity === ''
                    ? ($actual['entity_slug'] ?? null) === null
                    : (string)($actual['entity_slug'] ?? '')
                        === $expectedEntity
            )
            && $actualAttributes === $expectedAttributes
            && $evidenceMatches;
        if (!$valid && count($failed) < 100) {
            $failed[] = [
                'id' => (string)$case['case_id'],
                'expected' => [
                    'status' => $case['expected_status'],
                    'entity_slug' => $expectedEntity ?: null,
                    'attributes' => $expectedAttributes,
                    'evidence' => $case['expected_evidence'],
                ],
                'actual' => [
                    'resolution' => $actual,
                    'evidence' => $actualEvidence,
                ],
            ];
        }
    }
    return [
        'valid' => !$errors && !$failed,
        'fixture_hash' => $fixtureHash,
        'pinned_hash_matches' => is_string($fixtureHash)
            && preg_match(
                '/^[a-f0-9]{64}$/',
                INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_SHA256
            )
            && hash_equals(
                INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_SHA256,
                $fixtureHash
            ),
        'positive_count' => $positiveCount,
        'critical_negative_count' => $negativeCount,
        'failed_count' => count($failed),
        'failures' => $failed,
        'errors' => $errors,
        'structural_error_count' => count($structuralErrors),
        'structural_error_sample' => $structuralErrors,
        'maintainer_review_metadata_valid' =>
            (string)($goldReview['status'] ?? '')
                === 'maintainer_adjudicated'
            && trim((string)($goldReview['reviewer'] ?? '')) !== ''
            && trim((string)($goldReview['reviewed_at'] ?? '')) !== ''
            && trim(
                (string)($goldReview['confidence_limitation'] ?? '')
            ) !== '',
        'source_evidence_failure_count' =>
            count($sourceEvidenceFailures),
        'source_evidence_failure_sample' =>
            array_slice($sourceEvidenceFailures, 0, 100),
        'supersessions' => $supersessionAudit,
        'test_only_skipped_product_cases' =>
            $testOnlySkippedProductCases,
        'source_file' => INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_FILENAME,
        'confidence' => (string)(
            $goldReview['confidence_limitation']
                ?? 'Adjudicated deterministic regression coverage only.'
        ),
    ];
}

function ingredientOntologyV3DispositionDefinitions(): array {
    return [
        'D1' => [
            'name' => 'accepted_identity',
            'legacy_status' => 'accepted',
        ],
        'D2' => [
            'name' => 'accepted_identity_with_facets',
            'legacy_status' => 'accepted',
        ],
        'D3' => [
            'name' => 'reviewed_contextual',
            'legacy_status' => 'unresolved',
        ],
        'D4' => [
            'name' => 'reviewed_ambiguous',
            'legacy_status' => 'ambiguous',
        ],
        'D5' => [
            'name' => 'reviewed_composite_or_prepared',
            'legacy_status' => 'unresolved',
        ],
        'D6' => [
            'name' => 'reviewed_non_identity_modifier',
            'legacy_status' => 'unresolved',
        ],
        'D7' => [
            'name' => 'rejected_non_food_or_noise',
            'legacy_status' => 'rejected',
        ],
        'D8' => [
            'name' => 'provider_specific_unresolved',
            'legacy_status' => 'unresolved',
        ],
        'D9' => [
            'name' => 'evidence_needed_terminal',
            'legacy_status' => 'unresolved',
        ],
    ];
}

function ingredientOntologyV3ResolutionProductReviewMap(): array {
    $result = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows('product-dispositions.csv')
        as $row
    ) {
        $fingerprint = (string)$row['product_fingerprint'];
        if (
            !preg_match('/^[a-f0-9]{64}$/', $fingerprint)
            || !isset(
                ingredientOntologyV3DispositionDefinitions()[
                    (string)$row['disposition_code']
                ]
            )
        ) {
            throw new RuntimeException(
                'product disposition manifest row is invalid'
            );
        }
        $row['attributes'] = json_decode(
            (string)$row['attributes_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($row['attributes'])) {
            throw new RuntimeException(
                'product disposition attributes are invalid'
            );
        }
        $result[$fingerprint] = $row;
    }
    if (count($result) !== 174) {
        throw new RuntimeException(
            'product disposition manifest must contain 174 rows'
        );
    }
    return $result;
}

function ingredientOntologyV3ApplyResolutionProductReviews(
    PDO $db,
    int $versionId,
    array $duplicateMap
): array {
    $entities = [];
    $stmt = $db->prepare("
        SELECT id, slug, identity_role, active
        FROM ingredient_ontology_entities
        WHERE ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $entities[(string)$row['slug']] = [
            'id' => (int)$row['id'],
            'identity_role' => (string)$row['identity_role'],
            'active' => !empty($row['active']),
        ];
    }
    $reviews = ingredientOntologyV3ResolutionProductReviewMap();
    $version = ingredientOntologyV3Version($db, $versionId);
    $dynamicController = $version !== null
        && function_exists(
            'ingredientOntologyControllerUsesDynamicPins'
        )
        && ingredientOntologyControllerUsesDynamicPins($version);
    $products = $db->prepare("
        SELECT a.id AS assertion_id, a.product_id, a.entity_id,
               a.status, a.attributes_json, e.slug AS entity_slug,
               e.identity_role, p.id, p.name, p.brand, p.category,
               p.prepared_food
        FROM ingredient_ontology_curated_product_assertions a
        JOIN products p ON p.id = a.product_id
        LEFT JOIN ingredient_ontology_entities e ON e.id = a.entity_id
        WHERE a.ontology_version_id = ?
        ORDER BY a.product_id
    ");
    $products->execute([$versionId]);
    $update = $db->prepare("
        UPDATE ingredient_ontology_curated_product_assertions
        SET entity_id = ?, status = ?, confidence = ?,
            attributes_json = ?, rationale = ?, provenance = ?,
            review_state = 'accepted'
        WHERE id = ?
    ");
    $counts = [];
    $matchedSupplemental = 0;
    $dynamicUnreviewed = 0;
    while ($product = $products->fetch(PDO::FETCH_ASSOC)) {
        $attributes = json_decode(
            (string)$product['attributes_json'],
            true
        ) ?: [];
        $slug = (string)($product['entity_slug'] ?? '');
        if (isset($duplicateMap[$slug])) {
            $canonical = $duplicateMap[$slug];
            $slug = (string)$canonical['canonical'];
            $attributes = array_replace(
                (array)$canonical['attributes'],
                $attributes
            );
        }
        if (
            $slug === 'cooking-wine'
            && ($attributes['variety'] ?? null) === 'white_wine'
        ) {
            unset($attributes['variety']);
            $attributes['wine_color'] = 'white';
        } elseif (
            $slug === 'cooking-wine'
            && ($attributes['variety'] ?? null) === 'red_wine'
        ) {
            unset($attributes['variety']);
            $attributes['wine_color'] = 'red';
        }
        $ownerFingerprint = ingredientOntologyV3ProductOwnerFingerprint(
            $product
        );
        $review = $reviews[$ownerFingerprint] ?? null;
        if ($review === null) {
            if ($dynamicController) {
                $review = [
                    'product_id' => (int)$product['product_id'],
                    'disposition_code' => 'D9',
                    'entity_slug' => '',
                    'attributes' => [],
                    'rationale' =>
                        'Dynamic product awaits explicit reviewed disposition',
                ];
                $dynamicUnreviewed++;
            } elseif (
                defined('RECIPE_BACKEND_TEST_MODE')
                && RECIPE_BACKEND_TEST_MODE
            ) {
                $review = [
                    'product_id' => (int)$product['product_id'],
                    'disposition_code' => 'D9',
                    'entity_slug' => '',
                    'attributes' => [],
                    'rationale' =>
                        'Synthetic test-only unmanifested product',
                ];
            } else {
                throw new RuntimeException(
                    'product fingerprint lacks explicit disposition: '
                    . (int)$product['product_id']
                );
            }
        }
        if ((int)$review['product_id'] !== (int)$product['product_id']) {
            throw new RuntimeException(
                'product disposition fingerprint reused by another ID'
            );
        }
        $matchedSupplemental++;
        $code = (string)$review['disposition_code'];
        $slug = (string)$review['entity_slug'];
        $attributes = (array)$review['attributes'];
        $rationale = (string)$review['rationale'];
        $definition = ingredientOntologyV3DispositionDefinitions()[$code];
        $target = $slug !== '' ? ($entities[$slug] ?? null) : null;
        if (
            in_array($code, ['D1', 'D2'], true)
            && (
                $target === null
                || !$target['active']
                || in_array(
                    $target['identity_role'],
                    ['structural_category', 'staple_class'],
                    true
                )
            )
        ) {
            throw new RuntimeException(
                "accepted product target is identity-ineligible: {$slug}"
            );
        }
        if (!in_array($code, ['D1', 'D2'], true)) {
            $target = null;
            $attributes = [];
        }
        ksort($attributes, SORT_STRING);
        $update->execute([
            $target['id'] ?? null,
            $definition['legacy_status'],
            in_array($code, ['D1', 'D2'], true) ? 1.0 : 0.0,
            ingredientOntologyV3Json($attributes),
            $rationale,
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION
                . ':' . $code,
            (int)$product['assertion_id'],
        ]);
        $counts[$code] = ($counts[$code] ?? 0) + 1;
    }
    ksort($counts, SORT_STRING);
    return [
        'reviewed_count' => array_sum($counts),
        'explicit_manifest_matches' => $matchedSupplemental,
        'dynamic_unreviewed_count' => $dynamicUnreviewed,
        'by_disposition' => $counts,
    ];
}

function ingredientOntologyV3BuildProviderClusterEvidence(
    PDO $db,
    int $versionId,
    int $manifestId
): array {
    $legacyOccurrences = [];
    $legacy = $db->query("
        SELECT normalized_name, COUNT(*)
        FROM recipe_ingredients
        GROUP BY normalized_name
    ");
    while ($row = $legacy->fetch(PDO::FETCH_NUM)) {
        $legacyOccurrences[(string)$row[0]] = (int)$row[1];
    }
    $stmt = $db->prepare("
        SELECT o.normalized_local_label,
               COUNT(DISTINCT o.provider_ref) AS ref_count,
               COUNT(*) AS observation_count,
               MIN(o.provider_ref) AS provider_ref,
               MIN(t.default_title) AS default_title,
               MAX(t.is_generic) AS any_generic,
               GROUP_CONCAT(DISTINCT t.consistency_state)
                   AS consistency_states,
               GROUP_CONCAT(DISTINCT t.mapping_status)
                   AS term_statuses,
               MAX(
                   CASE WHEN m.identity_basis = 'provider_local_conflict'
                        THEN 1 ELSE 0 END
               ) AS any_conflict,
               MAX(
                   CASE WHEN t.mapping_status = 'accepted'
                        THEN 1 ELSE 0 END
               ) AS linked_accepted_term
        FROM ingredient_ontology_provider_observations o
        LEFT JOIN ingredient_ontology_provider_terms t
          ON t.id = o.provider_term_id
        LEFT JOIN ingredient_ontology_mappings m ON m.id = o.mapping_id
        WHERE o.ontology_version_id = ?
          AND o.provider_ref IS NOT NULL
        GROUP BY o.normalized_local_label
        ORDER BY o.normalized_local_label
    ");
    $stmt->execute([$versionId]);
    $counts = [
        'local_labels' => 0,
        'inverse_ambiguous_labels' => 0,
        'ref_unique_labels' => 0,
        'mechanically_unambiguous_labels' => 0,
        'mechanically_unambiguous_occurrences' => 0,
        'linked_accepted_term_labels' => 0,
        'linked_accepted_term_occurrences' => 0,
        'excluded_generic_variant_conflict' => 0,
    ];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts['local_labels']++;
        $label = (string)$row['normalized_local_label'];
        $occurrences = $legacyOccurrences[$label] ?? 0;
        if ((int)$row['ref_count'] !== 1) {
            $counts['inverse_ambiguous_labels']++;
            continue;
        }
        $counts['ref_unique_labels']++;
        $consistent = (string)$row['consistency_states'] === 'consistent';
        if (
            !empty($row['any_generic'])
            || !$consistent
            || !empty($row['any_conflict'])
        ) {
            $counts['excluded_generic_variant_conflict']++;
            continue;
        }
        $counts['mechanically_unambiguous_labels']++;
        $counts['mechanically_unambiguous_occurrences'] += $occurrences;
        if (!empty($row['linked_accepted_term'])) {
            $counts['linked_accepted_term_labels']++;
            $counts['linked_accepted_term_occurrences'] += $occurrences;
        }
        ingredientOntologyV3InsertEvidenceSource(
            $db,
            $versionId,
            $manifestId,
            'provider_ref_cluster',
            'provider-cluster-label:' . hash('sha256', $label),
            [
                'normalized_local_label' => $label,
                'provider_ref' => (string)$row['provider_ref'],
                'default_title_hash' => hash(
                    'sha256',
                    (string)$row['default_title']
                ),
                'observation_count' => (int)$row['observation_count'],
                'legacy_occurrences' => $occurrences,
                'linked_accepted_term' =>
                    !empty($row['linked_accepted_term']),
                'inverse_ambiguous' => false,
                'identity_effect' => 'review_cluster_only',
            ],
            'provider-ref-cluster-v1'
        );
    }
    $counts['review_cluster_only'] = true;
    $counts['direct_identity_allowed'] = false;
    $manifest = ingredientOntologyV3ResolutionManifest();
    $confirmed = [
        'reviewed_labels' => (int)(
            $manifest['frozen_sources'][
                'provider_frontier_labels'
            ] ?? 0
        ),
        'reviewed_occurrences' => (int)(
            $manifest['frozen_sources'][
                'provider_frontier_occurrences'
            ] ?? 0
        ),
    ];
    $counts['explicit_reviewed_frontier'] = $confirmed;
    $counts['confirmed_source_shape_matches'] =
        $counts['local_labels'] === 1132
        && $counts['inverse_ambiguous_labels'] === 19
        && $confirmed['reviewed_labels'] === 99
        && $confirmed['reviewed_occurrences'] === 6854;
    return $counts;
}

function ingredientOntologyV3ClosedModifierEvidence(
    array $labelIndex,
    string $label,
    string $language
): ?array {
    $trimmed = trim($label);
    $candidate = null;
    $tail = '';
    $kind = null;
    if (str_contains($trimmed, ',')) {
        [$candidate, $tail] = array_pad(
            explode(',', $trimmed, 2),
            2,
            ''
        );
        $kind = 'comma_segment';
    } elseif (
        preg_match('/^(.*?)\s*\(([^()]*)\)\s*$/u', $trimmed, $match)
    ) {
        $candidate = (string)$match[1];
        $tail = (string)$match[2];
        $kind = 'parenthetical_segment';
    }
    if ($candidate === null) {
        return null;
    }
    $candidate = trim($candidate);
    $tailNormalized = ingredientOntologyV3NormalizeLabel($tail);
    $closed = [
        'fresh', 'dried', 'frozen', 'smoked', 'pickled', 'roasted',
        'baked', 'blanched', 'fermented', 'cooked', 'raw',
        'sliced', 'shredded', 'ground', 'chopped', 'diced', 'minced',
        'peeled', 'crushed', 'grated', 'whole', 'halves', 'flakes',
        'for garnish', 'to taste', 'as needed', 'optional',
    ];
    $knownTail = $tailNormalized === ''
        || in_array($tailNormalized, $closed, true);
    $resolution = ingredientOntologyV3ResolveLabel(
        $labelIndex,
        $candidate,
        $language
    );
    return [
        'kind' => $kind,
        'candidate_span' => $candidate,
        'tail' => $tail,
        'tail_normalized' => $tailNormalized,
        'tail_closed' => $knownTail,
        'candidate_status' => $resolution['status'],
        'candidate_entity_id' => $resolution['entity_id'] ?? null,
        'candidate_attributes' => $resolution['attributes'] ?? [],
        'unknown_residue_blocks' => !$knownTail,
        'identity_effect' => 'evidence_only',
    ];
}

function ingredientOntologyV3BuildModifierEvidence(
    PDO $db,
    int $versionId,
    int $manifestId
): array {
    $labelIndex = ingredientOntologyV3LabelIndex($db, $versionId);
    $stmt = $db->prepare("
        SELECT normalized_label, language, MIN(source_label) AS source_label,
               COUNT(*) AS occurrences
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
          AND owner_type IN (
              'recipe_ingredient', 'recipe_source_ingredient'
          )
          AND status <> 'accepted'
        GROUP BY normalized_label, language
        ORDER BY normalized_label, language
    ");
    $stmt->execute([$versionId]);
    $counts = [
        'labels_checked' => 0,
        'evidence_rows' => 0,
        'closed_tail_rows' => 0,
        'unknown_residue_rows' => 0,
    ];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts['labels_checked']++;
        $evidence = ingredientOntologyV3ClosedModifierEvidence(
            $labelIndex,
            (string)$row['source_label'],
            (string)$row['language']
        );
        if ($evidence === null) {
            continue;
        }
        $evidence['normalized_label'] = (string)$row['normalized_label'];
        $evidence['occurrences'] = (int)$row['occurrences'];
        ingredientOntologyV3InsertEvidenceSource(
            $db,
            $versionId,
            $manifestId,
            (string)$evidence['kind'],
            (string)$evidence['kind'] . ':'
                . hash(
                    'sha256',
                    (string)$row['language'] . "\n"
                        . (string)$row['normalized_label']
                ),
            $evidence,
            'closed-exact-span-modifier-v1'
        );
        $counts['evidence_rows']++;
        if (!empty($evidence['tail_closed'])) {
            $counts['closed_tail_rows']++;
        } else {
            $counts['unknown_residue_rows']++;
        }
    }
    $counts['identity_auto_accept'] = false;
    return $counts;
}

function ingredientOntologyV3SeedRuleAdjudications(
    PDO $db,
    int $versionId,
    int $manifestId
): array {
    $count = 0;
    $ids = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows('rule-adjudications.csv')
        as $row
    ) {
        $ruleId = (int)$row['rule_id'];
        if ($ruleId <= 0 || (string)$row['disposition'] !== 'deny_identity') {
            throw new RuntimeException('rule adjudication row is invalid');
        }
        ingredientOntologyV3InsertEvidenceSource(
            $db,
            $versionId,
            $manifestId,
            'rule_adjudication',
            'taxonomy-rule:' . $ruleId,
            [
                'rule_id' => $ruleId,
                'disposition' => 'deny_identity',
                'rationale' => (string)$row['rationale'],
                'legacy_rule_unchanged' => true,
                'v3_identity_allowed' => false,
            ],
            'v3-rule-adjudication-v1'
        );
        $ids[] = $ruleId;
        $count++;
    }
    sort($ids, SORT_NUMERIC);
    return ['count' => $count, 'denied_rule_ids' => $ids];
}

function ingredientOntologyV3SnapshotCandidateAssertions(
    PDO $db,
    int $versionId,
    string $phase
): array {
    $stmt = $db->prepare("
        SELECT m.id, m.owner_type, m.owner_fingerprint, m.status,
               m.confidence, m.mapping_source, m.attributes_json,
               m.evidence_json, e.slug AS entity_slug
        FROM ingredient_ontology_mappings m
        LEFT JOIN ingredient_ontology_entities e ON e.id = m.entity_id
        WHERE m.ontology_version_id = ?
          AND m.status = 'candidate'
        ORDER BY m.id
    ");
    $stmt->execute([$versionId]);
    $relations = $db->prepare("
        SELECT target.slug, relation.relation, relation.direction,
               relation.confidence, relation.provenance,
               relation.review_state
        FROM ingredient_ontology_mapping_relations relation
        JOIN ingredient_ontology_entities target
          ON target.id = relation.to_entity_id
        WHERE relation.mapping_id = ?
        ORDER BY target.slug, relation.relation
    ");
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_mapping_assertion_history (
            ontology_version_id, mapping_id, owner_type,
            owner_fingerprint, phase, prior_status,
            proposed_entity_slug, proposed_confidence,
            proposed_attributes_json, proposed_relations_json,
            mapping_source, legacy_target_json,
            denied_provenance_json, evidence_hash, content_hash
        )
        VALUES (?, ?, ?, ?, ?, 'candidate', ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $attributes = json_decode(
            (string)$row['attributes_json'],
            true
        ) ?: [];
        ksort($attributes, SORT_STRING);
        $relations->execute([(int)$row['id']]);
        $relationRows = [];
        while ($relation = $relations->fetch(PDO::FETCH_ASSOC)) {
            $relationRows[] = [
                'target_slug' => (string)$relation['slug'],
                'relation' => (string)$relation['relation'],
                'direction' => (string)$relation['direction'],
                'confidence' => (float)$relation['confidence'],
                'provenance' => (string)$relation['provenance'],
                'review_state' => (string)$relation['review_state'],
            ];
        }
        $mappingEvidence = json_decode(
            (string)$row['evidence_json'],
            true
        ) ?: [];
        $legacyTarget = [
            'legacy_source' => $mappingEvidence['legacy_source'] ?? null,
            'candidates' => $mappingEvidence['candidates'] ?? null,
            'proposed_entity_slug' =>
                $mappingEvidence['proposed_entity_slug']
                    ?? $row['entity_slug'],
            'proposed_entity_name' =>
                $mappingEvidence['proposed_entity_name'] ?? null,
        ];
        $denied = [
            'mapping_source' => (string)$row['mapping_source'],
            'label_provenance' =>
                $mappingEvidence['label_provenance'] ?? null,
            'model_evidence_allowed' => false,
            'rule_evidence_allowed' => false,
            'history_alone_allowed' => false,
        ];
        $evidence = [
            'owner_fingerprint' => (string)$row['owner_fingerprint'],
            'phase' => $phase,
            'entity_slug' => $row['entity_slug'],
            'confidence' => (float)$row['confidence'],
            'attributes' => $attributes,
            'relations' => $relationRows,
            'mapping_source' => (string)$row['mapping_source'],
            'legacy_target' => $legacyTarget,
            'denied_provenance' => $denied,
        ];
        $evidenceHash = ingredientOntologyV3Hash($evidence);
        $content = [
            'ontology_version' =>
                INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION,
            'owner_type' => (string)$row['owner_type'],
            'owner_fingerprint' => (string)$row['owner_fingerprint'],
            'phase' => $phase,
            'evidence_hash' => $evidenceHash,
        ];
        $insert->execute([
            $versionId,
            (int)$row['id'],
            (string)$row['owner_type'],
            (string)$row['owner_fingerprint'],
            $phase,
            $row['entity_slug'] !== null
                ? (string)$row['entity_slug']
                : null,
            (float)$row['confidence'],
            ingredientOntologyV3Json($attributes),
            ingredientOntologyV3Json($relationRows),
            (string)$row['mapping_source'],
            ingredientOntologyV3Json($legacyTarget),
            ingredientOntologyV3Json($denied),
            $evidenceHash,
            ingredientOntologyV3Hash($content),
        ]);
        $count++;
    }
    return [
        'phase' => $phase,
        'candidate_count' => $count,
        'history_count' => $count,
        'complete' => true,
    ];
}

function ingredientOntologyV3PortableDispositionScopeContext(
    array $context
): array {
    unset($context['classification'], $context['mechanism']);
    $context = array_filter(
        $context,
        static fn(mixed $value): bool =>
            $value !== null && $value !== '' && $value !== []
    );
    return ingredientOntologyV3StableValue($context);
}

function ingredientOntologyV3PortableDispositionEvidenceKey(
    string $scopeKey,
    array $evidence
): string {
    return (string)(
        $evidence['explicit_disposition_evidence']['review']['review_key']
            ?? $evidence['provider_review']['review_key']
            ?? $evidence['product_fingerprint']
            ?? $evidence['provider_ref']
            ?? $evidence['label_source_ref']
            ?? $evidence['label_provenance']
            ?? $scopeKey
    );
}

function ingredientOntologyV3PortableDispositionOwnerFingerprint(
    array $context,
    array $evidence
): string {
    return (string)(
        $context['owner_fingerprint']
            ?? $context['provider_fingerprint']
            ?? $evidence['owner_fingerprint']
            ?? $evidence['product_fingerprint']
            ?? ''
    );
}

function ingredientOntologyV3PortableDispositionHash(
    string $portableScopeHash,
    string $scopeKey,
    string $code,
    ?string $entitySlug,
    array $attributes,
    string $mechanism,
    array $context,
    array $evidence
): string {
    ksort($attributes, SORT_STRING);
    return ingredientOntologyV3Hash([
        'portable_scope_hash' => $portableScopeHash,
        'disposition_code' => $code,
        'entity_slug' => $entitySlug,
        'attributes' => $attributes,
        'mechanism' => $mechanism,
        'review_evidence_key' =>
            ingredientOntologyV3PortableDispositionEvidenceKey(
                $scopeKey,
                $evidence
            ),
        'owner_or_source_fingerprint' =>
            ingredientOntologyV3PortableDispositionOwnerFingerprint(
                $context,
                $evidence
            ),
    ]);
}

function ingredientOntologyV3TerminalDisposition(
    PDO $db,
    int $versionId,
    array $manifest,
    array &$cache,
    string $scopeType,
    string $scopeKey,
    string $normalizedLabel,
    string $language,
    array $context,
    string $code,
    ?int $entityId,
    array $attributes,
    string $mechanism,
    array $evidence
): int {
    $definitions = ingredientOntologyV3DispositionDefinitions();
    if (!isset($definitions[$code])) {
        throw new InvalidArgumentException('terminal disposition is invalid');
    }
    ksort($attributes, SORT_STRING);
    $entitySlug = null;
    if ($entityId !== null) {
        $entitySlugStmt = $db->prepare("
            SELECT slug
            FROM ingredient_ontology_entities
            WHERE ontology_version_id = ? AND id = ?
        ");
        $entitySlugStmt->execute([$versionId, $entityId]);
        $value = $entitySlugStmt->fetchColumn();
        $entitySlug = $value !== false ? (string)$value : null;
    }
    $normalizedLanguage = ingredientOntologyV3NormalizeLanguage($language);
    $context = ingredientOntologyV3StableValue($context);
    $portableContext =
        ingredientOntologyV3PortableDispositionScopeContext($context);
    $portableScopeHash = ingredientOntologyV3Hash([
        'scope_type' => $scopeType,
        'scope_key' => $scopeKey,
        'normalized_label' => $normalizedLabel,
        'language' => $normalizedLanguage,
        'context' => $portableContext,
    ]);
    $scopeFingerprint = $portableScopeHash;
    $evidence = ingredientOntologyV3StableValue($evidence);
    $evidenceHash = ingredientOntologyV3Hash($evidence);
    $portableDispositionHash =
        ingredientOntologyV3PortableDispositionHash(
            $portableScopeHash,
            $scopeKey,
            $code,
            $entitySlug,
            $attributes,
            $mechanism,
            $context,
            $evidence
        );
    $cacheKey = $scopeType . ':' . $portableScopeHash;
    if (isset($cache[$cacheKey])) {
        $existing = $db->prepare("
            SELECT portable_disposition_hash
            FROM ingredient_ontology_terminal_dispositions
            WHERE id = ?
        ");
        $existing->execute([(int)$cache[$cacheKey]]);
        $existingHash = (string)$existing->fetchColumn();
        if (!hash_equals($existingHash, $portableDispositionHash)) {
            throw new RuntimeException(
                'one stable disposition scope produced outcome drift'
            );
        }
        return $cache[$cacheKey];
    }
    $scopeContent = [
        'scope_type' => $scopeType,
        'scope_fingerprint' => $scopeFingerprint,
        'portable_scope_hash' => $portableScopeHash,
        'normalized_label' => $normalizedLabel,
        'language' => $normalizedLanguage,
        'context' => $context,
    ];
    $insertScope = $db->prepare("
        INSERT INTO ingredient_ontology_disposition_scopes (
            ontology_version_id, scope_type, scope_key,
            scope_fingerprint, portable_scope_hash,
            normalized_label, language,
            context_json, content_hash
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insertScope->execute([
        $versionId,
        $scopeType,
        mb_substr($scopeKey, 0, 240, 'UTF-8'),
        $scopeFingerprint,
        $portableScopeHash,
        mb_substr($normalizedLabel, 0, 200, 'UTF-8'),
        $normalizedLanguage,
        ingredientOntologyV3Json($context),
        ingredientOntologyV3Hash($scopeContent),
    ]);
    $scopeId = (int)$db->lastInsertId();
    $batchHash = ingredientOntologyV3Hash([
        'manifest_hash' => $manifest['manifest_hash'],
        'review_batch' => $manifest['review_batch'],
    ]);
    $content = [
        'scope_fingerprint' => $scopeFingerprint,
        'disposition_code' => $code,
        'disposition_name' => $definitions[$code]['name'],
        'entity_slug' => $entitySlug,
        'attributes' => $attributes,
        'mechanism' => $mechanism,
        'evidence_hash' => $evidenceHash,
        'reviewer' => $manifest['reviewer'],
        'review_batch' => $manifest['review_batch'],
        'batch_hash' => $batchHash,
        'portable_disposition_hash' => $portableDispositionHash,
    ];
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_terminal_dispositions (
            ontology_version_id, scope_id, disposition_code,
            disposition_name, entity_id, attributes_json, mechanism,
            evidence_json, evidence_hash, reviewer, review_batch,
            batch_hash, portable_disposition_hash, content_hash
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $versionId,
        $scopeId,
        $code,
        $definitions[$code]['name'],
        $entityId,
        ingredientOntologyV3Json($attributes),
        mb_substr($mechanism, 0, 120, 'UTF-8'),
        ingredientOntologyV3Json($evidence),
        $evidenceHash,
        (string)$manifest['reviewer'],
        (string)$manifest['review_batch'],
        $batchHash,
        $portableDispositionHash,
        ingredientOntologyV3Hash($content),
    ]);
    $id = (int)$db->lastInsertId();
    $cache[$cacheKey] = $id;
    return $id;
}

function ingredientOntologyV3AcceptedLabelProvenanceAllowed(
    ?string $provenance
): bool {
    $provenance = (string)$provenance;
    return in_array($provenance, [
        'canonical_name',
        'multilingual_staple_seed',
        'semantic_seed',
        INGREDIENT_ONTOLOGY_V3_CURATED_VERSION,
        INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION,
        'prior-label-transition-v3',
        'provider-local-review-v3',
    ], true);
}

function ingredientOntologyV3ContextDispositionMap(): array {
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $map = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'context-dispositions.csv'
        ) as $row
    ) {
        $key = implode('|', [
            (string)$row['normalized_label'],
            ingredientOntologyV3NormalizeLanguage(
                (string)$row['language']
            ),
            (string)$row['required_cohort'],
        ]);
        $map[$key] = $row;
    }
    return $map;
}

function ingredientOntologyV3RecipeSemanticDispositionMap(): array {
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $map = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows(
            'recipe-semantic-dispositions.csv'
        ) as $row
    ) {
        $language = ingredientOntologyV3NormalizeLanguage(
            (string)$row['language']
        );
        $key = (string)$row['normalized_label'] . '|' . $language;
        $cohort = (string)($row['required_cohort'] ?? '');
        $evidenceKind = (string)(
            $row['required_evidence_kind'] ?? ''
        );
        $evidenceKey = (string)(
            $row['required_evidence_key'] ?? ''
        );
        $row['semantic_scope_key'] = implode('|', [
            (string)$row['normalized_label'],
            $language,
            $cohort,
            $evidenceKind,
            $evidenceKey,
        ]);
        $map[$key][] = $row;
    }
    return $map;
}

function ingredientOntologyV3PriorTransitionForOwner(
    string $normalizedLabel,
    string $language
): ?array {
    static $byLabel = null;
    if (!is_array($byLabel)) {
        $byLabel = [];
        foreach (
            ingredientOntologyV3ResolutionCsvRows(
                'prior-accepted-label-transitions.csv'
            ) as $row
        ) {
            $byLabel[(string)$row['normalized_label']][] = $row;
        }
    }
    $requested = ingredientOntologyV3NormalizeLanguage($language);
    $fallback = null;
    foreach ($byLabel[$normalizedLabel] ?? [] as $row) {
        $fallback ??= $row;
        if (
            ingredientOntologyV3LanguageMatches(
                (string)$row['language'],
                $requested
            )
        ) {
            return $row;
        }
    }
    return $fallback;
}

function ingredientOntologyV3NonAcceptedDisposition(array $row): array {
    $normalized = (string)($row['normalized_label'] ?? '');
    $mappingEvidence = json_decode(
        (string)($row['evidence_json'] ?? '{}'),
        true
    ) ?: [];
    if (!empty($mappingEvidence['context_gate_missing'])) {
        $contextProvenance = (string)(
            $mappingEvidence['context_gate_provenance'] ?? ''
        );
        $isTransitionContext = in_array(
            $contextProvenance,
            ['prior-label-transition-v2', 'prior-label-transition-v3'],
            true
        );
        $contextManifest = $isTransitionContext
                ? 'prior-accepted-label-transitions.csv'
                : (
                    in_array(
                        $contextProvenance,
                        [
                            'provider-local-review-v2',
                            'provider-local-review-v3',
                        ],
                        true
                    )
                        ? 'provider-local-reviews.csv'
                        : 'aliases.csv'
                );
        return [
            'D9',
            $isTransitionContext
                ? 'reviewed_transition_context_missing'
                : 'reviewed_alias_context_missing',
            [
                'explicit_manifest' => $contextManifest,
                'context_gate_provenance' => $contextProvenance,
                'context_gate_source_ref' =>
                    $mappingEvidence['context_gate_source_ref'] ?? null,
                'required_language' =>
                    $mappingEvidence['required_language'] ?? null,
                'required_cohort' =>
                    $mappingEvidence['required_cohort'] ?? null,
                'required_evidence_kind' =>
                    $mappingEvidence['required_evidence_kind'] ?? null,
                'required_evidence_key' =>
                    $mappingEvidence['required_evidence_key'] ?? null,
                'proposed_entity_slug' =>
                    $mappingEvidence['proposed_entity_slug'] ?? null,
                'proposed_attributes' =>
                    $mappingEvidence['proposed_attributes'] ?? [],
                'attribute_hints' =>
                    $mappingEvidence['attribute_hints'] ?? [],
            ],
        ];
    }
    $language = ingredientOntologyV3NormalizeLanguage(
        (string)($row['language'] ?? 'und')
    );
    $baseLanguage = $language === 'und'
        ? 'und'
        : explode('-', $language)[0];
    $cohort = (string)($row['cohort'] ?? '');
    $transition = ingredientOntologyV3PriorTransitionForOwner(
        $normalized,
        $language
    );
    if ($transition !== null) {
        $requiredCohort = (string)(
            $transition['required_cohort'] ?? ''
        );
        $requiredKind = (string)(
            $transition['required_evidence_kind'] ?? ''
        );
        $requiredKey = (string)(
            $transition['required_evidence_key'] ?? ''
        );
        $ownerEvidence = $mappingEvidence['owner_evidence_keys'] ?? [];
        $missingTransitionContext = (
            $requiredCohort !== ''
            && $cohort !== $requiredCohort
        ) || (
            $requiredKind !== ''
            && $requiredKey !== ''
            && !in_array(
                $requiredKey,
                (array)($ownerEvidence[$requiredKind] ?? []),
                true
            )
        );
        if ($missingTransitionContext) {
            return [
                'D9',
                'reviewed_transition_context_missing',
                [
                    'explicit_manifest' =>
                        'prior-accepted-label-transitions.csv',
                    'required_cohort' => $requiredCohort ?: null,
                    'required_evidence_kind' =>
                        $requiredKind ?: null,
                    'required_evidence_key' =>
                        $requiredKey ?: null,
                    'review' => $transition,
                ],
            ];
        }
    }
    $contextKey = implode('|', [
        $normalized,
        $baseLanguage,
        $cohort,
    ]);
    $context = ingredientOntologyV3ContextDispositionMap()[
        $contextKey
    ] ?? null;
    if ($context !== null) {
        return [
            'D3',
            'explicit_context_manifest',
            [
                'explicit_manifest' => 'context-dispositions.csv',
                'review' => $context,
            ],
        ];
    }
    $providerReview = $mappingEvidence['provider_review'] ?? null;
    if (
        is_array($providerReview)
        && (string)($providerReview['disposition_code'] ?? '') === 'D3'
    ) {
        return [
            'D3',
            'explicit_provider_context_manifest',
            [
                'explicit_manifest' => 'provider-local-reviews.csv',
                'review' => $providerReview,
            ],
        ];
    }
    foreach ([$baseLanguage, 'und'] as $candidateLanguage) {
        foreach (
            ingredientOntologyV3RecipeSemanticDispositionMap()[
                $normalized . '|' . $candidateLanguage
            ] ?? []
            as $semantic
        ) {
            $requiredCohort = (string)(
                $semantic['required_cohort'] ?? ''
            );
            $requiredKind = (string)(
                $semantic['required_evidence_kind'] ?? ''
            );
            $requiredKey = (string)(
                $semantic['required_evidence_key'] ?? ''
            );
            if (
                ($requiredCohort !== '' && $requiredCohort !== $cohort)
                || (
                    $requiredKind !== ''
                    && $requiredKey !== ''
                    && !in_array(
                        $requiredKey,
                        (array)(
                            $mappingEvidence['owner_evidence_keys']
                                [$requiredKind] ?? []
                        ),
                        true
                    )
                )
            ) {
                continue;
            }
            return [
                (string)$semantic['disposition_code'],
                'explicit_recipe_semantic_manifest',
                [
                    'explicit_manifest' =>
                        'recipe-semantic-dispositions.csv',
                    'review' => $semantic,
                ],
            ];
        }
    }
    if (($row['provider_term_id'] ?? null) !== null) {
        return [
            'D8',
            'provider_specific_unresolved',
            ['explicit_manifest' => 'provider-terms.csv'],
        ];
    }
    return [
        'D9',
        'deterministic_evidence_exhausted',
        ['explicit_manifest' => null],
    ];
}

function ingredientOntologyV3ResolveProviderTermReview(
    array $term,
    ?array $review,
    bool $dynamicController
): array {
    $fingerprint = ingredientOntologyV3Hash([
        'connector' => (string)$term['connector'],
        'metadata_schema_version' =>
            (string)$term['metadata_schema_version'],
        'namespace' => (string)$term['namespace'],
        'provider_ref' => (string)$term['provider_ref'],
        'title_hash' => (string)($term['title_hash'] ?? ''),
        'consistency_state' => (string)$term['consistency_state'],
    ]);
    $reviewIsStale = $review !== null
        && (
            !hash_equals(
                (string)$review['term_fingerprint'],
                $fingerprint
            )
            || !hash_equals(
                (string)$review['title_hash'],
                (string)($term['title_hash'] ?? '')
            )
        );
    $dynamicUnreviewed = false;
    if ($review === null || ($dynamicController && $reviewIsStale)) {
        if ($dynamicController) {
            $review = [
                'term_fingerprint' => $fingerprint,
                'title_hash' => (string)($term['title_hash'] ?? ''),
                'disposition_code' => 'D8',
                'entity_slug' => '',
                'attributes_json' => '{}',
                'rationale' => $reviewIsStale
                    ? 'Dynamic provider term review is stale and awaits renewed explicit disposition'
                    : 'Dynamic provider term awaits explicit reviewed disposition',
                'reviewer' => 'autonomous-controller',
            ];
            $dynamicUnreviewed = true;
        } elseif (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
        ) {
            $acceptedSynthetic =
                (string)$term['mapping_status'] === 'accepted'
                && (string)$term['review_state'] === 'accepted';
            $review = [
                'term_fingerprint' => $fingerprint,
                'title_hash' => (string)($term['title_hash'] ?? ''),
                'disposition_code' =>
                    $acceptedSynthetic ? 'D1' : 'D8',
                'entity_slug' =>
                    $acceptedSynthetic
                        ? (string)$term['entity_slug']
                        : '',
                'attributes_json' =>
                    $acceptedSynthetic
                        ? (string)$term['attributes_json']
                        : '{}',
                'rationale' =>
                    'Synthetic test-only provider disposition',
                'reviewer' => 'synthetic-test',
            ];
        } else {
            throw new RuntimeException(
                'provider term lacks explicit review: '
                . (string)$term['provider_ref']
            );
        }
    }
    if (
        !hash_equals(
            (string)$review['term_fingerprint'],
            $fingerprint
        )
        || !hash_equals(
            (string)$review['title_hash'],
            (string)($term['title_hash'] ?? '')
        )
    ) {
        throw new RuntimeException(
            'provider term review is stale: '
            . (string)$term['provider_ref']
        );
    }
    return [
        'review' => $review,
        'fingerprint' => $fingerprint,
        'dynamic_unreviewed' => $dynamicUnreviewed,
        'dynamic_stale' => $dynamicUnreviewed && $reviewIsStale,
    ];
}

function ingredientOntologyV3FinalizeTerminalDispositions(
    PDO $db,
    int $versionId,
    array $manifest
): array {
    $version = ingredientOntologyV3Version($db, $versionId);
    $dynamicController = $version !== null
        && function_exists(
            'ingredientOntologyControllerUsesDynamicPins'
        )
        && ingredientOntologyControllerUsesDynamicPins($version);
    $definitions = ingredientOntologyV3DispositionDefinitions();
    $policies = ingredientOntologyV3ResolutionEntityFacetPolicyMap(
        $db,
        $versionId
    );
    $entities = [];
    $stmt = $db->prepare("
        SELECT id, slug, identity_role, active
        FROM ingredient_ontology_entities
        WHERE ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $entities[(int)$row['id']] = [
            'slug' => (string)$row['slug'],
            'identity_role' => (string)$row['identity_role'],
            'active' => !empty($row['active']),
        ];
    }
    $cache = [];
    $providerTermReviews = [];
    foreach (
        ingredientOntologyV3ResolutionCsvRows('provider-terms.csv')
        as $review
    ) {
        $key = implode('|', [
            (string)$review['connector'],
            (string)$review['metadata_schema_version'],
            (string)$review['namespace'],
            (string)$review['provider_ref'],
        ]);
        $providerTermReviews[$key] = $review;
    }
    if (count($providerTermReviews) !== 646) {
        throw new RuntimeException(
            'provider term review manifest must contain 646 rows'
        );
    }

    $providerDispositionIds = [];
    $provider = $db->prepare("
        SELECT t.*, e.slug AS entity_slug,
               e.identity_role, e.active AS entity_active
        FROM ingredient_ontology_provider_terms t
        LEFT JOIN ingredient_ontology_entities e ON e.id = t.entity_id
        WHERE t.ontology_version_id = ?
        ORDER BY t.id
    ");
    $provider->execute([$versionId]);
    $updateProvider = $db->prepare("
        UPDATE ingredient_ontology_provider_terms
        SET mapping_status = ?, review_state = ?, entity_id = ?,
            attributes_json = ?, terminal_disposition_id = ?,
            provenance = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $providerCounts = [];
    $dynamicUnreviewedProviderTerms = 0;
    $dynamicStaleProviderTerms = 0;
    while ($term = $provider->fetch(PDO::FETCH_ASSOC)) {
        $reviewKey = implode('|', [
            (string)$term['connector'],
            (string)$term['metadata_schema_version'],
            (string)$term['namespace'],
            (string)$term['provider_ref'],
        ]);
        $review = $providerTermReviews[$reviewKey] ?? null;
        $reviewResolution =
            ingredientOntologyV3ResolveProviderTermReview(
                $term,
                $review,
                $dynamicController
            );
        $review = $reviewResolution['review'];
        $fingerprint = (string)$reviewResolution['fingerprint'];
        if (!empty($reviewResolution['dynamic_unreviewed'])) {
            $dynamicUnreviewedProviderTerms++;
        }
        if (!empty($reviewResolution['dynamic_stale'])) {
            $dynamicStaleProviderTerms++;
        }
        $code = (string)$review['disposition_code'];
        $attributes = json_decode(
            (string)$review['attributes_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        ) ?: [];
        $entityId = null;
        $accepted = in_array($code, ['D1', 'D2'], true);
        if ($accepted) {
            $slug = ingredientOntologyV3Slug(
                (string)$review['entity_slug']
            );
            $entity = $db->prepare("
                SELECT id, identity_role, active
                FROM ingredient_ontology_entities
                WHERE ontology_version_id = ? AND slug = ?
            ");
            $entity->execute([$versionId, $slug]);
            $entity = $entity->fetch(PDO::FETCH_ASSOC);
            if (
                !$entity
                || empty($entity['active'])
                || in_array(
                    (string)$entity['identity_role'],
                    ['structural_category', 'staple_class'],
                    true
                )
            ) {
                throw new RuntimeException(
                    "provider review target is ineligible: {$slug}"
                );
            }
            $entityId = (int)$entity['id'];
            $filtered = ingredientOntologyV3ResolutionFilterAttributes(
                $policies,
                $entityId,
                $attributes
            );
            if ($filtered['blocked']) {
                throw new RuntimeException(
                    "provider review facets are ineligible: {$slug}"
                );
            }
            $attributes = $filtered['accepted'];
            $code = $attributes ? 'D2' : 'D1';
        } else {
            $code = 'D8';
            $attributes = [];
        }
        $dispositionId = ingredientOntologyV3TerminalDisposition(
            $db,
            $versionId,
            $manifest,
            $cache,
            'provider_term',
            'provider:' . (string)$term['provider_ref'],
            (string)($term['normalized_default_title'] ?? ''),
            'und',
            [
                'provider_fingerprint' => $fingerprint,
                'connector' => (string)$term['connector'],
                'namespace' => (string)$term['namespace'],
            ],
            $code,
            $entityId,
            $attributes,
            $accepted
                ? 'explicit_provider_term_manifest'
                : 'explicit_provider_term_unresolved_manifest',
            [
                'provider_ref' => (string)$term['provider_ref'],
                'consistency_state' =>
                    (string)$term['consistency_state'],
                'is_generic' => !empty($term['is_generic']),
                'original_mapping_status' =>
                    (string)$term['mapping_status'],
                'original_review_state' =>
                    (string)$term['review_state'],
                'provider_ref_direct_identity_allowed' => false,
                'review_rationale' => (string)$review['rationale'],
                'reviewer' => (string)$review['reviewer'],
            ]
        );
        $updateProvider->execute([
            $definitions[$code]['legacy_status'],
            $accepted ? 'accepted' : 'quarantined',
            $entityId,
            ingredientOntologyV3Json($attributes),
            $dispositionId,
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION
                . ':' . $code,
            (int)$term['id'],
        ]);
        $providerDispositionIds[(int)$term['id']] = $dispositionId;
        $providerCounts[$code] = ($providerCounts[$code] ?? 0) + 1;
    }

    $productDispositionIds = [];
    $products = $db->prepare("
        SELECT a.*, p.id AS current_product_id,
               p.name, p.brand, p.category, p.prepared_food,
               e.identity_role, e.active AS entity_active
        FROM ingredient_ontology_curated_product_assertions a
        JOIN products p ON p.id = a.product_id
        LEFT JOIN ingredient_ontology_entities e ON e.id = a.entity_id
        WHERE a.ontology_version_id = ?
        ORDER BY a.product_id
    ");
    $products->execute([$versionId]);
    $updateProduct = $db->prepare("
        UPDATE ingredient_ontology_curated_product_assertions
        SET entity_id = ?, status = ?, confidence = ?,
            attributes_json = ?, terminal_disposition_id = ?,
            review_state = 'accepted'
        WHERE id = ?
    ");
    $productCounts = [];
    while ($product = $products->fetch(PDO::FETCH_ASSOC)) {
        $code = null;
        if (
            preg_match(
                '/:(D[1-9])$/',
                (string)$product['provenance'],
                $match
            )
        ) {
            $code = (string)$match[1];
        }
        if (!isset($definitions[$code])) {
            $code = (string)$product['status'] === 'accepted'
                ? 'D1'
                : 'D9';
        }
        $attributes = json_decode(
            (string)$product['attributes_json'],
            true
        ) ?: [];
        $entityId = $product['entity_id'] !== null
            ? (int)$product['entity_id']
            : null;
        if (in_array($code, ['D1', 'D2'], true)) {
            $eligible = $entityId !== null
                && !empty($product['entity_active'])
                && !in_array(
                    (string)($product['identity_role'] ?? ''),
                    ['structural_category', 'staple_class'],
                    true
                );
            $filtered = $eligible
                ? ingredientOntologyV3ResolutionFilterAttributes(
                    $policies,
                    $entityId,
                    $attributes
                )
                : ['accepted' => [], 'blocked' => $attributes];
            if (!$eligible || $filtered['blocked']) {
                $code = 'D9';
                $entityId = null;
                $attributes = [];
            } else {
                $attributes = $filtered['accepted'];
                $code = $attributes ? 'D2' : 'D1';
            }
        } else {
            $entityId = null;
            $attributes = [];
        }
        $ownerFingerprint = ingredientOntologyV3ProductOwnerFingerprint(
            [
                'id' => (int)$product['current_product_id'],
                'name' => $product['name'],
                'brand' => $product['brand'],
                'category' => $product['category'],
                'prepared_food' => $product['prepared_food'],
            ]
        );
        $dispositionId = ingredientOntologyV3TerminalDisposition(
            $db,
            $versionId,
            $manifest,
            $cache,
            'product_fingerprint',
            'product:' . $ownerFingerprint,
            (string)$product['normalized_product_name'],
            'und',
            [
                'owner_fingerprint' => $ownerFingerprint,
            ],
            $code,
            $entityId,
            $attributes,
            'fingerprint_bound_product_review',
            [
                'product_fingerprint' => $ownerFingerprint,
                'product_name_hash' => hash(
                    'sha256',
                    (string)$product['product_name']
                ),
                'rationale' => (string)$product['rationale'],
                'review_provenance' => (string)$product['provenance'],
            ]
        );
        $updateProduct->execute([
            $entityId,
            $definitions[$code]['legacy_status'],
            in_array($code, ['D1', 'D2'], true) ? 1.0 : 0.0,
            ingredientOntologyV3Json($attributes),
            $dispositionId,
            (int)$product['id'],
        ]);
        $productDispositionIds[(int)$product['product_id']] = [
            'id' => $dispositionId,
            'code' => $code,
            'entity_id' => $entityId,
            'attributes' => $attributes,
        ];
        $productCounts[$code] = ($productCounts[$code] ?? 0) + 1;
    }

    $mappings = $db->prepare("
        SELECT m.*,
               e.identity_role, e.active AS entity_active,
               COALESCE(ri.recipe_id, rsi.recipe_id) AS recipe_id,
               cohort.cohort,
               a.terminal_disposition_id AS product_disposition_id,
               a.status AS product_status,
               a.entity_id AS product_entity_id,
               a.attributes_json AS product_attributes_json,
               term.connector AS provider_connector,
               term.metadata_schema_version AS provider_schema,
               term.namespace AS provider_namespace,
               term.provider_ref AS provider_ref_stable,
               term.title_hash AS provider_title_hash,
               history.id AS prior_history_id,
               history.proposed_entity_slug AS prior_entity_slug,
               history.proposed_confidence AS prior_confidence,
               history.proposed_attributes_json AS prior_attributes_json,
               history.proposed_relations_json AS prior_relations_json,
               history.mapping_source AS prior_mapping_source,
               history.legacy_target_json AS prior_legacy_target_json,
               history.denied_provenance_json
                   AS prior_denied_provenance_json,
               json_extract(m.evidence_json, '$.label_provenance')
                   AS label_provenance,
               label.source_ref AS label_source_ref
        FROM ingredient_ontology_mappings m
        LEFT JOIN ingredient_ontology_entities e ON e.id = m.entity_id
        LEFT JOIN recipe_ingredients ri
          ON m.owner_type = 'recipe_ingredient' AND ri.id = m.owner_id
        LEFT JOIN recipe_source_ingredients rsi
          ON m.owner_type = 'recipe_source_ingredient'
         AND rsi.id = m.owner_id
        LEFT JOIN ingredient_ontology_recipe_cohorts cohort
          ON cohort.ontology_version_id = m.ontology_version_id
         AND cohort.recipe_id = COALESCE(ri.recipe_id, rsi.recipe_id)
        LEFT JOIN ingredient_ontology_labels label
          ON label.id = CAST(
              json_extract(m.evidence_json, '$.label_id') AS INTEGER
          )
        LEFT JOIN ingredient_ontology_curated_product_assertions a
          ON m.owner_type = 'product'
         AND a.ontology_version_id = m.ontology_version_id
         AND a.product_id = m.owner_id
        LEFT JOIN ingredient_ontology_provider_terms term
          ON term.id = m.provider_term_id
        LEFT JOIN ingredient_ontology_mapping_assertion_history history
          ON history.mapping_id = m.id
         AND history.phase = 'post_mapping'
        WHERE m.ontology_version_id = ?
        ORDER BY m.id
    ");
    $mappings->execute([$versionId]);
    $updateMapping = $db->prepare("
        UPDATE ingredient_ontology_mappings
        SET entity_id = ?, status = ?, confidence = ?,
            mapping_source = ?, attributes_json = ?,
            terminal_disposition_id = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $mappingCounts = [];
    while ($mapping = $mappings->fetch(PDO::FETCH_ASSOC)) {
        $mappingId = (int)$mapping['id'];
        if ((string)$mapping['owner_type'] === 'product') {
            $product = $productDispositionIds[(int)$mapping['owner_id']]
                ?? null;
            if ($product === null) {
                throw new RuntimeException(
                    'product mapping has no terminal disposition'
                );
            }
            $code = $product['code'];
            $entityId = $product['entity_id'];
            $attributes = $product['attributes'];
            $dispositionId = $product['id'];
            $mechanism = 'fingerprint_bound_product_review';
        } else {
            $attributes = json_decode(
                (string)$mapping['attributes_json'],
                true
            ) ?: [];
            $entityId = $mapping['entity_id'] !== null
                ? (int)$mapping['entity_id']
                : null;
            $eligible = $entityId !== null
                && !empty($mapping['entity_active'])
                && !in_array(
                    (string)($mapping['identity_role'] ?? ''),
                    ['structural_category'],
                    true
                )
                && (
                    (string)($mapping['identity_role'] ?? '')
                        !== 'staple_class'
                    || !empty($mapping['is_staple'])
                );
            $filtered = $eligible
                ? ingredientOntologyV3ResolutionFilterAttributes(
                    $policies,
                    $entityId,
                    $attributes
                )
                : ['accepted' => [], 'blocked' => $attributes];
            $provenanceAllowed =
                ingredientOntologyV3AcceptedLabelProvenanceAllowed(
                    $mapping['label_provenance'] !== null
                        ? (string)$mapping['label_provenance']
                        : null
                );
            $accepted = (string)$mapping['status'] === 'accepted'
                && $eligible
                && !$filtered['blocked']
                && $provenanceAllowed
                && !in_array(
                    (string)$mapping['mapping_source'],
                    [
                        'taxonomy_rule', 'taxonomy_rule_evidence',
                        'quarantined_model_evidence', 'model',
                        'model_proposal', 'lexical', 'normalized_name',
                        'foodon_hierarchy',
                    ],
                    true
                );
            if ($accepted) {
                $attributes = $filtered['accepted'];
                $code = $attributes ? 'D2' : 'D1';
                $mechanism = 'reviewed_exact_label_identity';
                $dispositionEntityId = $entityId;
                $explicitDispositionEvidence = [];
            } else {
                [$code, $mechanism, $explicitDispositionEvidence] =
                    ingredientOntologyV3NonAcceptedDisposition($mapping);
                $dispositionEntityId = null;
                $meaningSlug = (string)(
                    $explicitDispositionEvidence['review']
                        ['meaning_entity_slug']
                        ?? $explicitDispositionEvidence['review']
                        ['entity_slug']
                        ?? ''
                );
                if ($code === 'D3' && $meaningSlug !== '') {
                    $meaning = $db->prepare("
                        SELECT id
                        FROM ingredient_ontology_entities
                        WHERE ontology_version_id = ? AND slug = ?
                    ");
                    $meaning->execute([$versionId, $meaningSlug]);
                    $meaningId = (int)$meaning->fetchColumn();
                    $dispositionEntityId = $meaningId > 0
                        ? $meaningId
                        : null;
                }
                $entityId = null;
                $attributes = [];
            }
            $providerScoped =
                (string)$mapping['owner_type']
                    === 'recipe_source_ingredient'
                && trim((string)($mapping['provider_ref_stable'] ?? ''))
                    !== '';
            $semanticReview = is_array(
                $explicitDispositionEvidence['review'] ?? null
            ) ? $explicitDispositionEvidence['review'] : [];
            $semanticContextBound = in_array(
                $code,
                ['D4', 'D5', 'D6'],
                true
            )
                && $mechanism === 'explicit_recipe_semantic_manifest'
                && (
                    (string)(
                        $semanticReview['required_cohort'] ?? ''
                    ) !== ''
                    || (string)(
                        $semanticReview['required_evidence_kind'] ?? ''
                    ) !== ''
                    || (string)(
                        $semanticReview['required_evidence_key'] ?? ''
                    ) !== ''
                );
            $scopeType = match (true) {
                $providerScoped => 'provider_term',
                $code === 'D3' || $semanticContextBound =>
                    'cohort_context',
                $code === 'D8' => 'provider_term',
                $code === 'D7' => 'owner_fingerprint',
                default => 'global_label',
            };
            $mappingLanguage = ingredientOntologyV3NormalizeLanguage(
                (string)$mapping['language']
            );
            $scopeKey = match ($scopeType) {
                'cohort_context' => 'cohort:'
                    . (string)$mapping['cohort'] . ':'
                    . hash('sha256', implode("\n", [
                        (string)$mapping['normalized_label'],
                        $mappingLanguage,
                        (string)(
                            $semanticReview[
                                'required_evidence_kind'
                            ] ?? ''
                        ),
                        (string)(
                            $semanticReview[
                                'required_evidence_key'
                            ] ?? ''
                        ),
                    ])),
                'provider_term' => 'provider-local:'
                    . hash('sha256', implode('|', [
                        (string)($mapping['provider_connector'] ?? ''),
                        (string)($mapping['provider_schema'] ?? ''),
                        (string)($mapping['provider_namespace'] ?? ''),
                        (string)($mapping['provider_ref_stable'] ?? ''),
                        (string)($mapping['provider_title_hash'] ?? ''),
                        (string)$mapping['owner_fingerprint'],
                        (string)$mapping['normalized_label'],
                        $mappingLanguage,
                    ])),
                'owner_fingerprint' => 'owner:'
                    . (string)$mapping['owner_fingerprint'],
                default => 'label:'
                    . hash(
                        'sha256',
                        (string)$mapping['normalized_label'] . "\n"
                            . $mappingLanguage
                    ),
            };
            $scopeContext = match ($scopeType) {
                'cohort_context' => [
                    'cohort' => $mapping['cohort'],
                    'required_evidence_kind' =>
                        $semanticReview[
                            'required_evidence_kind'
                        ] ?? null,
                    'required_evidence_key' =>
                        $semanticReview[
                            'required_evidence_key'
                        ] ?? null,
                ],
                'provider_term' => [
                    'owner_fingerprint' =>
                        (string)$mapping['owner_fingerprint'],
                    'provider_key' => [
                        'connector' =>
                            $mapping['provider_connector'],
                        'metadata_schema_version' =>
                            $mapping['provider_schema'],
                        'namespace' =>
                            $mapping['provider_namespace'],
                        'provider_ref' =>
                            $mapping['provider_ref_stable'],
                        'title_hash' =>
                            $mapping['provider_title_hash'],
                    ],
                ],
                'owner_fingerprint' => [
                    'owner_fingerprint' =>
                        (string)$mapping['owner_fingerprint'],
                ],
                default => [],
            };
            $dispositionId = ingredientOntologyV3TerminalDisposition(
                $db,
                $versionId,
                $manifest,
                $cache,
                $scopeType,
                $scopeKey,
                (string)$mapping['normalized_label'],
                (string)$mapping['language'],
                $scopeContext,
                $code,
                $dispositionEntityId,
                $attributes,
                $mechanism,
                [
                    'original_status' => (string)$mapping['status'],
                    'original_mapping_source' =>
                        (string)$mapping['mapping_source'],
                    'label_provenance' => $mapping['label_provenance'],
                    'label_source_ref' => $mapping['label_source_ref'],
                    'blocked_attributes' => $filtered['blocked'],
                    'explicit_disposition_evidence' =>
                        $explicitDispositionEvidence,
                    'prior_candidate_assertion' =>
                        $mapping['prior_history_id'] !== null
                            ? [
                                'entity_slug' =>
                                    $mapping['prior_entity_slug'],
                                'confidence' =>
                                    (float)$mapping['prior_confidence'],
                                'attributes' => json_decode(
                                    (string)$mapping[
                                        'prior_attributes_json'
                                    ],
                                    true
                                ) ?: [],
                                'relations' => json_decode(
                                    (string)$mapping[
                                        'prior_relations_json'
                                    ],
                                    true
                                ) ?: [],
                                'mapping_source' =>
                                    $mapping['prior_mapping_source'],
                                'legacy_target' => json_decode(
                                    (string)$mapping[
                                        'prior_legacy_target_json'
                                    ],
                                    true
                                ) ?: [],
                                'denied_provenance' => json_decode(
                                    (string)$mapping[
                                        'prior_denied_provenance_json'
                                    ],
                                    true
                                ) ?: [],
                            ]
                            : null,
                    'classification_hints' => [
                        'raw_alternative_hint' => (bool)preg_match(
                            '/(?:\\bor\\b|\\/|;)/iu',
                            (string)$mapping['source_label']
                        ),
                        'raw_composite_hint' => (bool)preg_match(
                            '/\\b(homemade|meal|soup|salad|pizza|cake|'
                                . 'sandwich|taco|nachos|pie|leftovers)\\b/iu',
                            (string)$mapping['source_label']
                        ),
                        'raw_modifier_hint' => (bool)preg_match(
                            '/\\b(to taste|for garnish|as needed|optional)\\b/iu',
                            (string)$mapping['source_label']
                        ),
                        'numeric_or_percent_hint' => (bool)preg_match(
                            '/[0-9%]/u',
                            (string)$mapping['source_label']
                        ),
                    ],
                    'exact_alias_checked' => true,
                    'provider_cluster_checked' => true,
                    'cohort_checked' => true,
                    'modifier_grammar_checked' => true,
                    'unsafe_rules_denied' => [19, 27, 57, 71, 98, 99],
                    'model_evidence_allowed' => false,
                    'category_or_ancestry_identity_allowed' => false,
                ]
            );
        }
        $updateMapping->execute([
            $entityId,
            $definitions[$code]['legacy_status'],
            in_array($code, ['D1', 'D2'], true)
                ? (float)$mapping['confidence']
                : 0.0,
            in_array($code, ['D1', 'D2'], true)
                ? (string)$mapping['mapping_source']
                : mb_substr($mechanism, 0, 80, 'UTF-8'),
            ingredientOntologyV3Json($attributes),
            $dispositionId,
            $mappingId,
        ]);
        $mappingCounts[$code] = ($mappingCounts[$code] ?? 0) + 1;
    }
    $db->prepare("
        DELETE FROM ingredient_ontology_mapping_attributes
        WHERE mapping_id IN (
            SELECT id FROM ingredient_ontology_mappings
            WHERE ontology_version_id = ? AND status <> 'accepted'
        )
    ")->execute([$versionId]);
    $db->prepare("
        DELETE FROM ingredient_ontology_mapping_relations
        WHERE mapping_id IN (
            SELECT id FROM ingredient_ontology_mappings
            WHERE ontology_version_id = ? AND status <> 'accepted'
        )
    ")->execute([$versionId]);
    foreach ([$providerCounts, $productCounts, $mappingCounts] as &$counts) {
        ksort($counts, SORT_STRING);
    }
    unset($counts);
    return [
        'provider_terms' => $providerCounts,
        'dynamic_unreviewed_provider_term_count' =>
            $dynamicUnreviewedProviderTerms,
        'dynamic_stale_provider_term_count' =>
            $dynamicStaleProviderTerms,
        'products' => $productCounts,
        'mappings' => $mappingCounts,
        'scope_count' => count($cache),
    ];
}

function ingredientOntologyV3DispositionAudit(
    PDO $db,
    int $versionId
): array {
    $group = static function (
        PDO $db,
        string $sql,
        array $params
    ): array {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $result[(string)$row[0]] = (int)$row[1];
        }
        return $result;
    };
    $terminal = $group(
        $db,
        "SELECT disposition_code, COUNT(*)
         FROM ingredient_ontology_terminal_dispositions
         WHERE ontology_version_id = ?
         GROUP BY disposition_code ORDER BY disposition_code",
        [$versionId]
    );
    $mappingTerminal = $group(
        $db,
        "SELECT d.disposition_code, COUNT(*)
         FROM ingredient_ontology_mappings m
         JOIN ingredient_ontology_terminal_dispositions d
           ON d.id = m.terminal_disposition_id
         WHERE m.ontology_version_id = ?
         GROUP BY d.disposition_code ORDER BY d.disposition_code",
        [$versionId]
    );
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
          AND terminal_disposition_id IS NULL
    ");
    $stmt->execute([$versionId]);
    $undispositionedMappings = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_curated_product_assertions
        WHERE ontology_version_id = ?
          AND terminal_disposition_id IS NULL
    ");
    $stmt->execute([$versionId]);
    $undispositionedProducts = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_provider_terms
        WHERE ontology_version_id = ?
          AND terminal_disposition_id IS NULL
    ");
    $stmt->execute([$versionId]);
    $undispositionedProviderTerms = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_mappings m
        JOIN ingredient_ontology_entities e ON e.id = m.entity_id
        WHERE m.ontology_version_id = ?
          AND m.status = 'accepted'
          AND e.identity_role IN ('structural_category', 'staple_class')
    ");
    $stmt->execute([$versionId]);
    $structuralAccepted = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
          AND status = 'accepted'
          AND mapping_source IN (
              'taxonomy_rule', 'taxonomy_rule_evidence',
              'quarantined_model_evidence', 'model', 'model_proposal',
              'lexical', 'normalized_name', 'foodon_hierarchy'
          )
    ");
    $stmt->execute([$versionId]);
    $deniedAccepted = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
          AND status = 'accepted'
          AND owner_type <> 'product'
          AND (
              json_extract(evidence_json, '$.label_provenance') IS NULL
              OR (
                  json_extract(evidence_json, '$.label_provenance')
                      NOT IN (
                          'canonical_name',
                          'multilingual_staple_seed',
                          'semantic_seed',
                          'curated-review-v3',
                          'full-resolution-v3',
                          'prior-label-transition-v3',
                          'provider-local-review-v3',
                          'full-resolution-v2',
                          'prior-label-transition-v2',
                          'provider-local-review-v2'
                      )
              )
          )
    ");
    $stmt->execute([$versionId]);
    $acceptedNonmanifest = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_primary_edge_reviews
        WHERE ontology_version_id = ?
          AND change_kind IN (
              'added', 'changed', 'removed', 'restored'
          )
          AND disposition <> 'reviewed'
    ");
    $stmt->execute([$versionId]);
    $unreviewedEdgeDiffs = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_primary_edge_reviews
        WHERE ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    $edgeReviewCount = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_mappings m
        JOIN ingredient_ontology_terminal_dispositions d
          ON d.id = m.terminal_disposition_id
        WHERE m.ontology_version_id = ?
          AND d.disposition_code = 'D3'
          AND d.mechanism NOT IN (
              'explicit_context_manifest',
              'explicit_provider_context_manifest'
          )
    ");
    $stmt->execute([$versionId]);
    $implicitD3 = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_mappings m
        JOIN ingredient_ontology_terminal_dispositions d
          ON d.id = m.terminal_disposition_id
        WHERE m.ontology_version_id = ?
          AND m.owner_type IN (
              'recipe_ingredient', 'recipe_source_ingredient'
          )
          AND d.disposition_code IN ('D4', 'D5', 'D6')
          AND d.mechanism <> 'explicit_recipe_semantic_manifest'
    ");
    $stmt->execute([$versionId]);
    $generatedRecipeSemantic = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_mapping_assertion_history
        WHERE ontology_version_id = ? AND phase = 'post_mapping'
          AND prior_status = 'candidate'
    ");
    $stmt->execute([$versionId]);
    $candidateHistoryCount = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_evidence_sources
        WHERE ontology_version_id = ?
          AND evidence_kind = 'curated_manifest'
          AND evidence_key LIKE 'prior-label-transition:%'
    ");
    $stmt->execute([$versionId]);
    $priorLabelTransitionCount = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_evidence_sources
        WHERE ontology_version_id = ?
          AND evidence_kind = 'provider_review'
          AND evidence_key LIKE 'provider-local-review:%'
    ");
    $stmt->execute([$versionId]);
    $providerLocalReviewCount = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT json_extract(
            payload_json,
            '$.review_key'
        ))
        FROM ingredient_ontology_evidence_sources
        WHERE ontology_version_id = ?
          AND evidence_scope = 'owner_observation'
          AND json_extract(payload_json, '$.review_key') IS NOT NULL
    ");
    $stmt->execute([$versionId]);
    $providerLocalObservedReviewCount = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_provider_terms
        WHERE ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    $providerTermSourceCount = (int)$stmt->fetchColumn();
    $providerSourceRowCount = ingredientOntologyV3TableExists(
        $db,
        'recipe_source_ingredients'
    ) ? (int)$db->query("
        SELECT COUNT(*) FROM recipe_source_ingredients
    ")->fetchColumn() : 0;
    $providerVersion = ingredientOntologyV3Version($db, $versionId);
    $dynamicProviderCorpus = $providerVersion !== null
        && function_exists(
            'ingredientOntologyControllerUsesDynamicPins'
        )
        && ingredientOntologyControllerUsesDynamicPins($providerVersion);
    $providerCorpusGate = $providerSourceRowCount === 0
        || (
            $dynamicProviderCorpus
            && $providerLocalObservedReviewCount
                <= $providerLocalReviewCount
        )
        || (
            $providerSourceRowCount === 3100
            && $providerTermSourceCount === 646
            && $providerLocalObservedReviewCount
                === $providerLocalReviewCount
        )
        || (
            defined('RECIPE_BACKEND_TEST_MODE')
            && RECIPE_BACKEND_TEST_MODE
        );
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_mappings m
        JOIN ingredient_ontology_labels l
          ON l.id = CAST(
              json_extract(m.evidence_json, '$.label_id') AS INTEGER
          )
        JOIN ingredient_ontology_label_context_policies policy
          ON policy.label_id = l.id
         AND policy.required_evidence_kind = 'provider_owner_review'
        LEFT JOIN ingredient_ontology_evidence_sources evidence
          ON evidence.ontology_version_id = m.ontology_version_id
         AND evidence.evidence_scope = 'owner_observation'
         AND evidence.owner_fingerprint = m.owner_fingerprint
         AND json_extract(evidence.payload_json, '$.review_key')
             = policy.required_evidence_key
        WHERE m.ontology_version_id = ?
          AND m.status = 'accepted'
          AND (
              m.owner_type <> 'recipe_source_ingredient'
              OR evidence.id IS NULL
          )
    ");
    $stmt->execute([$versionId]);
    $providerOwnerLeakage = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_provider_observations observation
        JOIN ingredient_ontology_mappings mapping
          ON mapping.id = observation.mapping_id
        JOIN (
            SELECT normalized_local_label
            FROM ingredient_ontology_provider_observations
            WHERE ontology_version_id = ?
              AND provider_ref IS NOT NULL
            GROUP BY normalized_local_label
            HAVING COUNT(DISTINCT provider_ref) > 1
        ) inverse
          ON inverse.normalized_local_label =
             observation.normalized_local_label
        WHERE observation.ontology_version_id = ?
          AND mapping.status = 'accepted'
    ");
    $stmt->execute([$versionId, $versionId]);
    $inverseAmbiguousAcceptedLeakage = (int)$stmt->fetchColumn();
    $candidateCounts = [
        'mappings' => 0,
        'products' => 0,
        'provider_terms' => 0,
    ];
    foreach ([
        'mappings' => [
            'ingredient_ontology_mappings', 'status',
        ],
        'products' => [
            'ingredient_ontology_curated_product_assertions', 'status',
        ],
        'provider_terms' => [
            'ingredient_ontology_provider_terms', 'mapping_status',
        ],
    ] as $key => [$table, $column]) {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM {$table}
            WHERE ontology_version_id = ? AND {$column} = 'candidate'
        ");
        $stmt->execute([$versionId]);
        $candidateCounts[$key] = (int)$stmt->fetchColumn();
    }
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_provider_observations o
        JOIN (
            SELECT normalized_local_label
            FROM ingredient_ontology_provider_observations
            WHERE ontology_version_id = ?
            GROUP BY normalized_local_label
            HAVING COUNT(DISTINCT provider_ref) > 1
        ) inverse
          ON inverse.normalized_local_label = o.normalized_local_label
        WHERE o.ontology_version_id = ?
    ");
    $stmt->execute([$versionId, $versionId]);
    $providerInverseAmbiguousObservations = (int)$stmt->fetchColumn();
    $cohorts = $group(
        $db,
        "SELECT COALESCE(cohort, 'none'), COUNT(*)
         FROM ingredient_ontology_recipe_cohorts
         WHERE ontology_version_id = ?
         GROUP BY cohort ORDER BY cohort",
        [$versionId]
    );
    $edgeDiffs = $group(
        $db,
        "SELECT change_kind, COUNT(*)
         FROM ingredient_ontology_primary_edge_reviews
         WHERE ontology_version_id = ?
         GROUP BY change_kind ORDER BY change_kind",
        [$versionId]
    );
    $graph = ingredientOntologyV3GraphValidate($db, $versionId);
    $version = ingredientOntologyV3Version($db, $versionId);
    $corpusProfile = (string)(
        $version['corpus_profile'] ?? 'test'
    );
    $dynamicPins = $version !== null
        && function_exists(
            'ingredientOntologyControllerUsesDynamicPins'
        )
        && ingredientOntologyControllerUsesDynamicPins($version)
        && function_exists(
            'ingredientOntologyControllerDynamicVersionPins'
        )
            ? ingredientOntologyControllerDynamicVersionPins(
                $db,
                $versionId,
                $version
            )
            : null;
    $frozenCorpusAudit = $dynamicPins !== null
        ? $dynamicPins['corpus']
        : ingredientOntologyV3FrozenCorpusAudit(
            $db,
            $corpusProfile
        );
    $subjectUniverseAudit = $dynamicPins !== null
        ? $dynamicPins['subjects']
        : ingredientOntologyV3SubjectUniverseAudit(
            $db,
            $versionId,
            $corpusProfile
        );
    $mappingAttributeIntegrity =
        ingredientOntologyV3MappingAttributeIntegrityAudit(
            $db,
            $versionId
        );
    $transitionOutcomes =
        ingredientOntologyV3PriorTransitionOutcomeAudit(
            $db,
            $versionId,
            null,
            true
        );
    $transitionOwnerOutcomes =
        ingredientOntologyV3PriorTransitionOwnerOutcomeAudit(
            $db,
            $versionId
        );
    $providerFacetAudit = ingredientOntologyV3ProviderFacetAudit(
        $db,
        $versionId
    );
    $genericIdentityAudit =
        ingredientOntologyV3GenericIdentityRationaleAudit(
            $db,
            $versionId
        );
    $edgeSemanticAudit = ingredientOntologyV3EdgeSemanticAudit(
        $db,
        $versionId
    );
    $manifest = ingredientOntologyV3ResolutionManifest();
    $expectedTransitions = (int)(
        $manifest['frozen_sources']['prior_accepted_label_count'] ?? 522
    );
    $expectedEdges = (int)(
        $manifest['frozen_sources']['entity_count'] ?? 305
    );
    $expectedProviderReviews = (int)(
        $manifest['frozen_sources']['provider_local_review_count'] ?? 100
    );
    $undispositioned = $undispositionedMappings
        + $undispositionedProviderTerms;
    $candidateTotal = array_sum($candidateCounts);
    return [
        'valid' => $undispositioned === 0
            && $undispositionedProducts === 0
            && $candidateTotal === 0
            && $structuralAccepted === 0
            && $deniedAccepted === 0
            && $acceptedNonmanifest === 0
            && $unreviewedEdgeDiffs === 0
            && $implicitD3 === 0
            && $generatedRecipeSemantic === 0
            && $priorLabelTransitionCount === $expectedTransitions
            && $providerLocalReviewCount === $expectedProviderReviews
            && $providerCorpusGate
            && $providerOwnerLeakage === 0
            && $inverseAmbiguousAcceptedLeakage === 0
            && $edgeReviewCount === $expectedEdges
            && $transitionOutcomes['valid']
            && $transitionOwnerOutcomes['valid']
            && $providerFacetAudit['valid']
            && $genericIdentityAudit['valid']
            && $edgeSemanticAudit['valid']
            && $frozenCorpusAudit['valid']
            && $subjectUniverseAudit['valid']
            && $mappingAttributeIntegrity['valid']
            && $graph['valid']
            && $graph['root_count'] === 1,
        'terminal_records' => $terminal,
        'mapping_terminal_cross_tab' => $mappingTerminal,
        'undispositioned_count' => $undispositioned,
        'undispositioned' => [
            'mappings' => $undispositionedMappings,
            'provider_terms' => $undispositionedProviderTerms,
            'curated_assertion_coverage' =>
                $undispositionedProducts,
        ],
        'subject_accounting' => [
            'mapping_subjects' => array_sum($mappingTerminal),
            'provider_term_subjects' => array_sum(
                $group(
                    $db,
                    "SELECT d.disposition_code, COUNT(*)
                     FROM ingredient_ontology_provider_terms term
                     JOIN ingredient_ontology_terminal_dispositions d
                       ON d.id = term.terminal_disposition_id
                     WHERE term.ontology_version_id = ?
                     GROUP BY d.disposition_code",
                    [$versionId]
                )
            ),
            'curated_assertions_reported_separately' => true,
        ],
        'candidate_count' => $candidateTotal,
        'candidates' => $candidateCounts,
        'structural_accepted_identity_count' => $structuralAccepted,
        'denied_accepted_mechanism_count' => $deniedAccepted,
        'accepted_nonmanifest_count' => $acceptedNonmanifest,
        'unreviewed_edge_diff_count' => $unreviewedEdgeDiffs,
        'explicit_edge_review_count' => $edgeReviewCount,
        'D3_without_explicit_context_evidence' => $implicitD3,
        'generated_recipe_D4_D5_D6_count' =>
            $generatedRecipeSemantic,
        'candidate_evidence_retained_count' =>
            $candidateHistoryCount,
        'prior_accepted_transition_review_count' =>
            $priorLabelTransitionCount,
        'lost_prior_accepted_without_explicit_disposition' =>
            max(0, $expectedTransitions - $priorLabelTransitionCount),
        'transition_outcomes' => $transitionOutcomes,
        'transition_label_outcome_mismatch_count' =>
            $transitionOutcomes['label_outcome_mismatch_count'],
        'transition_owner_outcomes' => $transitionOwnerOutcomes,
        'prior_accepted_occurrences_total' =>
            $transitionOwnerOutcomes[
                'prior_accepted_occurrences_total'
            ],
        'accepted_under_reviewed_context' =>
            $transitionOwnerOutcomes[
                'accepted_under_reviewed_context'
            ],
        'terminal_context_missing' =>
            $transitionOwnerOutcomes['terminal_context_missing'],
        'owner_outcome_mismatch_count' =>
            $transitionOwnerOutcomes['owner_outcome_mismatch_count'],
        'accepted_transition_hard_facet_omission_count' =>
            $transitionOwnerOutcomes[
                'accepted_transition_hard_facet_omission_count'
            ],
        'provider_local_review_manifest_count' =>
            $providerLocalReviewCount,
        'provider_local_observed_review_count' =>
            $providerLocalObservedReviewCount,
        'provider_term_source_count' => $providerTermSourceCount,
        'provider_source_row_count' => $providerSourceRowCount,
        'provider_corpus_gate' => $providerCorpusGate,
        'provider_evidence_owner_leakage' =>
            $providerOwnerLeakage,
        'provider_facets' => $providerFacetAudit,
        'generic_identity_rationales' => $genericIdentityAudit,
        'provider_expected_attribute_mismatch_count' =>
            $providerFacetAudit[
                'provider_expected_attribute_mismatch_count'
            ],
        'provider_parsed_hard_facet_unreviewed_count' =>
            $providerFacetAudit[
                'provider_parsed_hard_facet_unreviewed_count'
            ],
        'inverse_ambiguous_accepted_leakage' =>
            $inverseAmbiguousAcceptedLeakage,
        'primary_edge_diffs' => $edgeDiffs,
        'edge_semantics' => $edgeSemanticAudit,
        'frozen_corpus' => $frozenCorpusAudit,
        'subject_universe' => $subjectUniverseAudit,
        'mapping_attribute_integrity' =>
            $mappingAttributeIntegrity,
        'edge_semantic_fixture_failure_count' =>
            $edgeSemanticAudit['edge_semantic_fixture_failure_count'],
        'provider_inverse_ambiguous_observation_count' =>
            $providerInverseAmbiguousObservations,
        'cohorts' => $cohorts,
        'graph' => $graph,
        'accepted_count_is_not_a_gate' => true,
        'cookable_count_is_not_a_gate' => true,
        'confidence_limitations' =>
            'Terminal review records prove disposition completeness, not '
            . 'corpus-wide identity recall or activation readiness.',
    ];
}

function ingredientOntologyV3MappingDispositionSummary(
    PDO $db,
    int $versionId
): array {
    $owners = [
        'product' => 0,
        'recipe_ingredient' => 0,
        'recipe_source_ingredient' => 0,
    ];
    $stmt = $db->prepare("
        SELECT owner_type, COUNT(*)
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
        GROUP BY owner_type ORDER BY owner_type
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $owners[(string)$row[0]] = (int)$row[1];
    }
    $statuses = [];
    $stmt = $db->prepare("
        SELECT status, COUNT(*)
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
        GROUP BY status ORDER BY status
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $statuses[(string)$row[0]] = (int)$row[1];
    }
    return ['owners' => $owners, 'statuses' => $statuses];
}

function ingredientOntologyV3HashIntegrityAudit(
    PDO $db,
    int $versionId,
    bool $verifyVersionSeal = true
): array {
    $errors = [];
    $checked = 0;
    $manifest = ingredientOntologyV3ResolutionManifest();
    $stmt = $db->prepare("
        SELECT *
        FROM ingredient_ontology_resolution_manifests
        WHERE ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $checked++;
        if (
            !hash_equals(
                (string)$row['manifest_hash'],
                (string)$manifest['manifest_hash']
            )
            || !hash_equals(
                (string)$row['content_hash'],
                (string)$manifest['content_hash']
            )
        ) {
            $errors[] = 'resolution manifest hash mismatch';
        }
    }
    $stmt = $db->prepare("
        SELECT *
        FROM ingredient_ontology_evidence_sources
        WHERE ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $checked++;
        $payload = json_decode(
            (string)$row['payload_json'],
            true
        );
        $scope = [
            'kind' => (string)$row['evidence_kind'],
            'key' => (string)$row['evidence_key'],
            'evidence_scope' => (string)$row['evidence_scope'],
            'owner_fingerprint' => $row['owner_fingerprint'],
            'connector' => $row['connector'],
            'metadata_schema_version' =>
                $row['metadata_schema_version'],
            'provider_ref' => $row['provider_ref'],
            'title_hash' => $row['title_hash'],
            'observation_hash' => $row['observation_hash'],
        ];
        if (
            !is_array($payload)
            || !hash_equals(
                (string)$row['payload_hash'],
                ingredientOntologyV3Hash($payload)
            )
            || !hash_equals(
                (string)$row['scope_hash'],
                ingredientOntologyV3Hash($scope)
            )
        ) {
            $errors[] = 'evidence source hash mismatch:' . (int)$row['id'];
        }
    }
    $stmt = $db->prepare("
        SELECT policy.*, entity.slug, facet.facet_key
        FROM ingredient_ontology_entity_facet_policies policy
        JOIN ingredient_ontology_entities entity
          ON entity.id = policy.entity_id
        JOIN ingredient_ontology_facets facet
          ON facet.id = policy.facet_id
        WHERE policy.ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $checked++;
        $payload = [
            'entity_slug' => (string)$row['slug'],
            'facet_key' => (string)$row['facet_key'],
            'allowed' => (int)$row['allowed'],
            'defining' => (int)$row['defining'],
        ];
        if (!hash_equals(
            (string)$row['policy_hash'],
            ingredientOntologyV3Hash($payload)
        )) {
            $errors[] = 'entity/facet policy hash mismatch:'
                . (int)$row['id'];
        }
    }
    $stmt = $db->prepare("
        SELECT review.*, child.slug child_slug,
               previous.slug previous_slug, next.slug next_slug,
               manifest.manifest_hash
        FROM ingredient_ontology_primary_edge_reviews review
        JOIN ingredient_ontology_entities child
          ON child.id = review.child_entity_id
        LEFT JOIN ingredient_ontology_entities previous
          ON previous.id = review.previous_parent_entity_id
        LEFT JOIN ingredient_ontology_entities next
          ON next.id = review.new_parent_entity_id
        JOIN ingredient_ontology_resolution_manifests manifest
          ON manifest.id = review.manifest_id
        WHERE review.ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $checked++;
        $payload = [
            'child_slug' => (string)$row['child_slug'],
            'previous_parent_slug' => $row['previous_slug'],
            'new_parent_slug' => $row['next_slug'],
            'change_kind' => (string)$row['change_kind'],
            'manifest_hash' => (string)$row['manifest_hash'],
        ];
        if (!hash_equals(
            (string)$row['content_hash'],
            ingredientOntologyV3Hash($payload)
        )) {
            $errors[] = 'edge review hash mismatch:' . (int)$row['id'];
        }
    }
    $stmt = $db->prepare("
        SELECT *
        FROM ingredient_ontology_disposition_scopes
        WHERE ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $checked++;
        $context = json_decode(
            (string)$row['context_json'],
            true
        );
        $scope = [
            'scope_type' => (string)$row['scope_type'],
            'scope_fingerprint' => (string)$row['scope_fingerprint'],
            'portable_scope_hash' =>
                (string)$row['portable_scope_hash'],
            'normalized_label' => (string)$row['normalized_label'],
            'language' => (string)$row['language'],
            'context' => $context,
        ];
        $portableScopeHash = ingredientOntologyV3Hash([
            'scope_type' => (string)$row['scope_type'],
            'scope_key' => (string)$row['scope_key'],
            'normalized_label' => (string)$row['normalized_label'],
            'language' => (string)$row['language'],
            'context' =>
                ingredientOntologyV3PortableDispositionScopeContext(
                    is_array($context) ? $context : []
                ),
        ]);
        if (
            !is_array($context)
            || !hash_equals(
                (string)$row['scope_fingerprint'],
                $portableScopeHash
            )
            || !hash_equals(
                (string)$row['portable_scope_hash'],
                $portableScopeHash
            )
            || !hash_equals(
                (string)$row['content_hash'],
                ingredientOntologyV3Hash($scope)
            )
        ) {
            $errors[] = 'disposition scope hash mismatch:' . (int)$row['id'];
        }
    }
    $stmt = $db->prepare("
        SELECT disposition.*, scope.scope_fingerprint,
               scope.portable_scope_hash, scope.scope_key,
               scope.context_json, entity.slug
        FROM ingredient_ontology_terminal_dispositions disposition
        JOIN ingredient_ontology_disposition_scopes scope
          ON scope.id = disposition.scope_id
        LEFT JOIN ingredient_ontology_entities entity
          ON entity.id = disposition.entity_id
        WHERE disposition.ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $checked++;
        $attributes = json_decode(
            (string)$row['attributes_json'],
            true
        );
        $evidence = json_decode(
            (string)$row['evidence_json'],
            true
        );
        $content = [
            'scope_fingerprint' => (string)$row['scope_fingerprint'],
            'disposition_code' => (string)$row['disposition_code'],
            'disposition_name' => (string)$row['disposition_name'],
            'entity_slug' => $row['slug'],
            'attributes' => $attributes,
            'mechanism' => (string)$row['mechanism'],
            'evidence_hash' => (string)$row['evidence_hash'],
            'reviewer' => (string)$row['reviewer'],
            'review_batch' => (string)$row['review_batch'],
            'batch_hash' => (string)$row['batch_hash'],
            'portable_disposition_hash' =>
                (string)$row['portable_disposition_hash'],
        ];
        $context = json_decode(
            (string)$row['context_json'],
            true
        );
        $portableDispositionHash =
            ingredientOntologyV3PortableDispositionHash(
                (string)$row['portable_scope_hash'],
                (string)$row['scope_key'],
                (string)$row['disposition_code'],
                $row['slug'] !== null ? (string)$row['slug'] : null,
                is_array($attributes) ? $attributes : [],
                (string)$row['mechanism'],
                is_array($context) ? $context : [],
                is_array($evidence) ? $evidence : []
            );
        if (
            !is_array($attributes)
            || !is_array($evidence)
            || !is_array($context)
            || !hash_equals(
                (string)$row['evidence_hash'],
                ingredientOntologyV3Hash($evidence)
            )
            || !hash_equals(
                (string)$row['portable_disposition_hash'],
                $portableDispositionHash
            )
            || !hash_equals(
                (string)$row['content_hash'],
                ingredientOntologyV3Hash($content)
            )
        ) {
            $errors[] = 'terminal disposition hash mismatch:'
                . (int)$row['id'];
        }
    }
    $stmt = $db->prepare("
        SELECT *
        FROM ingredient_ontology_mapping_assertion_history
        WHERE ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $checked++;
        $attributes = json_decode(
            (string)$row['proposed_attributes_json'],
            true
        );
        $relations = json_decode(
            (string)$row['proposed_relations_json'],
            true
        );
        $legacy = json_decode(
            (string)$row['legacy_target_json'],
            true
        );
        $denied = json_decode(
            (string)$row['denied_provenance_json'],
            true
        );
        $evidence = [
            'owner_fingerprint' => (string)$row['owner_fingerprint'],
            'phase' => (string)$row['phase'],
            'entity_slug' => $row['proposed_entity_slug'],
            'confidence' => (float)$row['proposed_confidence'],
            'attributes' => $attributes,
            'relations' => $relations,
            'mapping_source' => (string)$row['mapping_source'],
            'legacy_target' => $legacy,
            'denied_provenance' => $denied,
        ];
        $content = [
            'ontology_version' =>
                INGREDIENT_ONTOLOGY_V3_RESOLUTION_MANIFEST_VERSION,
            'owner_type' => (string)$row['owner_type'],
            'owner_fingerprint' => (string)$row['owner_fingerprint'],
            'phase' => (string)$row['phase'],
            'evidence_hash' => (string)$row['evidence_hash'],
        ];
        if (
            !is_array($attributes)
            || !is_array($relations)
            || !is_array($legacy)
            || !is_array($denied)
            || !hash_equals(
                (string)$row['evidence_hash'],
                ingredientOntologyV3Hash($evidence)
            )
            || !hash_equals(
                (string)$row['content_hash'],
                ingredientOntologyV3Hash($content)
            )
        ) {
            $errors[] = 'candidate assertion history hash mismatch:'
                . (int)$row['id'];
        }
    }
    $mappingAttributeIntegrity =
        ingredientOntologyV3MappingAttributeIntegrityAudit(
            $db,
            $versionId
        );
    if (!$mappingAttributeIntegrity['valid']) {
        $errors[] = 'mapping attribute companion integrity mismatch';
    }
    if ($verifyVersionSeal) {
        $version = ingredientOntologyV3Version($db, $versionId);
        if ($version === null) {
            $errors[] = 'ontology version is missing';
        } else {
            $portable = ingredientOntologyV3PortableContentHash(
                $db,
                $versionId
            );
            $content = ingredientOntologyV3ContentHash($db, $versionId);
            $profile = (string)($version['corpus_profile'] ?? '');
            $dynamicPins = function_exists(
                'ingredientOntologyControllerUsesDynamicPins'
            ) && ingredientOntologyControllerUsesDynamicPins($version)
                ? ingredientOntologyControllerDynamicVersionPins(
                    $db,
                    $versionId,
                    $version
                )
                : null;
            $frozenCorpus = $dynamicPins !== null
                ? $dynamicPins['corpus']
                : ingredientOntologyV3FrozenCorpusAudit(
                    $db,
                    $profile
                );
            $subjects = $dynamicPins !== null
                ? $dynamicPins['subjects']
                : ingredientOntologyV3SubjectUniverseAudit(
                    $db,
                    $versionId,
                    $profile
                );
            $policyHash = $dynamicPins !== null
                ? (string)$dynamicPins['policy_hash']
                : ingredientOntologyV3VersionPolicyHash(
                    $profile,
                    (string)$version['activation_policy'],
                    (string)$version['activation_block_reason']
                );
            $seal = ingredientOntologyV3Hash([
                'schema_hash' => (string)$version['schema_hash'],
                'prompt_hash' => (string)$version['prompt_hash'],
                'model_hash' => (string)$version['model_hash'],
                'corpus_hash' => (string)$version['corpus_hash'],
                'content_hash' => $content,
                'portable_content_hash' => $portable,
                'review_manifest_hash' =>
                    (string)$version['review_manifest_hash'],
                'resolution_gold_hash' =>
                    (string)$version['resolution_gold_hash'],
                'matcher_gold_hash' =>
                    INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_SHA256,
                'matcher_gold_case_ids_hash' =>
                    INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_IDS_SHA256,
                'matcher_gold_case_count' =>
                    INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_COUNT,
                'corpus_profile' => $profile,
                'frozen_corpus_hash' =>
                    (string)($frozenCorpus['actual_hash'] ?? ''),
                'frozen_subjects_hash' =>
                    (string)($subjects['subject_universe_hash'] ?? ''),
                'activation_policy' =>
                    (string)$version['activation_policy'],
                'activation_block_reason' =>
                    (string)$version['activation_block_reason'],
                'policy_hash' => $policyHash,
            ]);
            foreach ([
                'content_hash' => $content,
                'portable_content_hash' => $portable,
                'review_manifest_hash' =>
                    (string)$manifest['manifest_hash'],
                'resolution_gold_hash' => (string)(
                    $manifest['file_hashes'][
                        INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_FILENAME
                    ] ?? ''
                ),
                'frozen_corpus_hash' =>
                    (string)($frozenCorpus['actual_hash'] ?? ''),
                'frozen_subjects_hash' =>
                    (string)($subjects['subject_universe_hash'] ?? ''),
                'policy_hash' => $policyHash,
                'seal_hash' => $seal,
            ] as $column => $expected) {
                if (!hash_equals(
                    (string)($version[$column] ?? ''),
                    $expected
                )) {
                    $errors[] = "version {$column} mismatch";
                }
            }
        }
    }
    return [
        'valid' => !$errors,
        'checked_rows' => $checked,
        'errors' => array_slice($errors, 0, 100),
    ];
}

function ingredientOntologyV3ResealVersionForTest(
    PDO $db,
    int $versionId
): void {
    if (
        !defined('RECIPE_BACKEND_TEST_MODE')
        || !RECIPE_BACKEND_TEST_MODE
    ) {
        throw new RuntimeException(
            'ontology reseal helper is test-only'
        );
    }
    $version = ingredientOntologyV3Version($db, $versionId);
    if ($version === null) {
        throw new InvalidArgumentException('ontology version not found');
    }
    $manifest = ingredientOntologyV3ResolutionManifest();
    $portable = ingredientOntologyV3PortableContentHash($db, $versionId);
    $content = ingredientOntologyV3ContentHash($db, $versionId);
    $gold = (string)(
        $manifest['file_hashes'][
            INGREDIENT_ONTOLOGY_V3_RESOLUTION_GOLD_FILENAME
        ]
            ?? ''
    );
    $profile = (string)($version['corpus_profile'] ?? 'test');
    $frozenCorpus = ingredientOntologyV3FrozenCorpusAudit(
        $db,
        $profile
    );
    $subjects = ingredientOntologyV3SubjectUniverseAudit(
        $db,
        $versionId,
        $profile
    );
    $policyHash = ingredientOntologyV3VersionPolicyHash(
        $profile,
        (string)$version['activation_policy'],
        (string)$version['activation_block_reason']
    );
    $seal = ingredientOntologyV3Hash([
        'schema_hash' => ingredientOntologyV3SchemaHash(),
        'prompt_hash' => ingredientOntologyV3PromptHash(),
        'model_hash' => ingredientOntologyV3ModelHash(
            (string)$version['model_name']
        ),
        'corpus_hash' => ingredientOntologyV3CorpusHash($db),
        'content_hash' => $content,
        'portable_content_hash' => $portable,
        'review_manifest_hash' => $manifest['manifest_hash'],
        'resolution_gold_hash' => $gold,
        'matcher_gold_hash' =>
            INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_SHA256,
        'matcher_gold_case_ids_hash' =>
            INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_IDS_SHA256,
        'matcher_gold_case_count' =>
            INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_COUNT,
        'corpus_profile' => $profile,
        'frozen_corpus_hash' =>
            (string)$frozenCorpus['actual_hash'],
        'frozen_subjects_hash' =>
            (string)$subjects['subject_universe_hash'],
        'activation_policy' => (string)$version['activation_policy'],
        'activation_block_reason' =>
            (string)$version['activation_block_reason'],
        'policy_hash' => $policyHash,
    ]);
    $guardKey = 'ingredient_ontology_ready_mutation_guard:'
        . spl_object_id($db);
    $guardWasEnabled = !empty($GLOBALS[$guardKey]);
    ingredientOntologyV3SetReadyMutationGuard($db, true);
    try {
        $db->prepare("
            UPDATE ingredient_ontology_versions
            SET schema_hash = ?, prompt_hash = ?, model_hash = ?,
                corpus_hash = ?, content_hash = ?,
                portable_content_hash = ?, review_manifest_hash = ?,
                resolution_gold_hash = ?, frozen_corpus_hash = ?,
                frozen_subjects_hash = ?, policy_hash = ?, seal_hash = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([
            ingredientOntologyV3SchemaHash(),
            ingredientOntologyV3PromptHash(),
            ingredientOntologyV3ModelHash((string)$version['model_name']),
            ingredientOntologyV3CorpusHash($db),
            $content,
            $portable,
            $manifest['manifest_hash'],
            $gold,
            (string)$frozenCorpus['actual_hash'],
            (string)$subjects['subject_universe_hash'],
            $policyHash,
            $seal,
            $versionId,
        ]);
    } finally {
        ingredientOntologyV3SetReadyMutationGuard(
            $db,
            $guardWasEnabled
        );
    }
}

function ingredientOntologyV3WriteDispositionCsv(
    PDO $db,
    int $versionId,
    mixed $stream
): void {
    if (!is_resource($stream)) {
        throw new InvalidArgumentException(
            'disposition export stream is invalid'
        );
    }
    fputcsv($stream, [
        'owner_type',
        'owner_id',
        'owner_fingerprint',
        'scope_fingerprint',
        'scope_type',
        'source_label',
        'normalized_label',
        'language',
        'disposition_code',
        'disposition_name',
        'entity_slug',
        'attributes_json',
        'mechanism',
        'evidence_hash',
        'disposition_evidence_json',
        'reviewer',
        'review_batch',
        'content_hash',
        'prior_candidate_entity_slug',
        'prior_candidate_confidence',
        'prior_candidate_attributes_json',
        'prior_candidate_relations_json',
        'prior_candidate_mapping_source',
        'prior_legacy_target_json',
        'prior_denied_provenance_json',
        'curated_assertion_covered',
    ]);
    $stmt = $db->prepare("
        SELECT mapping.owner_type, mapping.owner_id,
               mapping.owner_fingerprint, s.scope_fingerprint,
               s.scope_type, mapping.source_label,
               mapping.normalized_label, mapping.language,
               d.disposition_code,
               d.disposition_name, e.slug AS entity_slug,
               d.attributes_json, d.mechanism, d.evidence_hash,
               d.evidence_json, d.reviewer, d.review_batch,
               d.content_hash,
               history.proposed_entity_slug,
               history.proposed_confidence,
               history.proposed_attributes_json,
               history.proposed_relations_json,
               history.mapping_source,
               history.legacy_target_json,
               history.denied_provenance_json,
               CASE WHEN assertion.id IS NULL THEN 0 ELSE 1 END
                   AS curated_assertion_covered
        FROM ingredient_ontology_mappings mapping
        JOIN ingredient_ontology_terminal_dispositions d
          ON d.id = mapping.terminal_disposition_id
        JOIN ingredient_ontology_disposition_scopes s ON s.id = d.scope_id
        LEFT JOIN ingredient_ontology_entities e ON e.id = d.entity_id
        LEFT JOIN ingredient_ontology_mapping_assertion_history history
          ON history.mapping_id = mapping.id
         AND history.phase = 'post_mapping'
        LEFT JOIN ingredient_ontology_curated_product_assertions assertion
          ON mapping.owner_type = 'product'
         AND assertion.ontology_version_id = mapping.ontology_version_id
         AND assertion.product_id = mapping.owner_id
        WHERE mapping.ontology_version_id = ?
        ORDER BY mapping.owner_type, mapping.owner_id
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($stream, array_values($row));
    }
}

function ingredientOntologyV3WriteProviderWorkbookCsv(
    PDO $db,
    int $versionId,
    mixed $stream
): void {
    if (!is_resource($stream)) {
        throw new InvalidArgumentException(
            'provider workbook stream is invalid'
        );
    }

    fputcsv($stream, [
        'provider_term_id',
        'scope_fingerprint',
        'connector',
        'metadata_schema_version',
        'namespace',
        'provider_ref',
        'default_title',
        'title_hash',
        'consistency_state',
        'is_generic',
        'observed_row_count',
        'disposition_code',
        'entity_slug',
        'attributes_json',
        'evidence_hash',
        'content_hash',
        'prior_mapping_status',
        'reviewer',
        'review_rationale',
    ]);
    $stmt = $db->prepare("
        SELECT t.id AS provider_term_id, s.scope_fingerprint,
               t.connector, t.metadata_schema_version, t.namespace,
               t.provider_ref, t.default_title, t.title_hash,
               t.consistency_state, t.is_generic, t.observed_row_count,
               d.disposition_code, e.slug AS entity_slug,
               d.attributes_json, d.evidence_hash, d.content_hash,
               json_extract(
                   d.evidence_json,
                   '$.original_mapping_status'
               ) AS prior_mapping_status,
               d.reviewer,
               json_extract(
                   d.evidence_json,
                   '$.review_rationale'
               ) AS review_rationale
        FROM ingredient_ontology_provider_terms t
        JOIN ingredient_ontology_terminal_dispositions d
          ON d.id = t.terminal_disposition_id
        JOIN ingredient_ontology_disposition_scopes s ON s.id = d.scope_id
        LEFT JOIN ingredient_ontology_entities e ON e.id = d.entity_id
        WHERE t.ontology_version_id = ?
        ORDER BY t.connector, t.namespace, t.provider_ref
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($stream, array_values($row));
    }
}

function ingredientOntologyV3CrossCopyHashAudit(
    PDO $left,
    int $leftVersionId,
    PDO $right,
    int $rightVersionId
): array {
    $leftPortable = ingredientOntologyV3PortableContentHash(
        $left,
        $leftVersionId
    );
    $rightPortable = ingredientOntologyV3PortableContentHash(
        $right,
        $rightVersionId
    );
    $scopeStatement = static function (
        PDO $db,
        int $versionId
    ): PDOStatement {
        $stmt = $db->prepare("
            SELECT scope.scope_type, scope.scope_fingerprint,
                   scope.portable_scope_hash,
                   disposition.disposition_code,
                   disposition.mechanism,
                   disposition.attributes_json,
                   disposition.portable_disposition_hash,
                   entity.slug AS entity_slug,
                   COALESCE(group_concat(
                       DISTINCT mapping.owner_type
                   ), '') AS mapping_owner_types,
                   COUNT(DISTINCT term.id) AS provider_term_owners
            FROM ingredient_ontology_disposition_scopes scope
            LEFT JOIN ingredient_ontology_terminal_dispositions disposition
              ON disposition.scope_id = scope.id
            LEFT JOIN ingredient_ontology_entities entity
              ON entity.id = disposition.entity_id
            LEFT JOIN ingredient_ontology_mappings mapping
              ON mapping.terminal_disposition_id = disposition.id
            LEFT JOIN ingredient_ontology_provider_terms term
              ON term.terminal_disposition_id = disposition.id
            WHERE scope.ontology_version_id = ?
            GROUP BY scope.id
            ORDER BY scope.portable_scope_hash
        ");
        $stmt->execute([$versionId]);
        return $stmt;
    };
    $normalizeScope = static function (mixed $row): ?array {
        if ($row === null || $row === false) {
            return null;
        }
        return [
            'fingerprint' => (string)$row['scope_fingerprint'],
            'portable_scope_hash' =>
                (string)$row['portable_scope_hash'],
            'portable_disposition_hash' =>
                (string)$row['portable_disposition_hash'],
            'outcome' => [
                'disposition_code' =>
                    (string)$row['disposition_code'],
                'entity_slug' => $row['entity_slug'] !== null
                    ? (string)$row['entity_slug']
                    : null,
                'attributes' => json_decode(
                    (string)$row['attributes_json'],
                    true
                ) ?: [],
                'mechanism' => (string)$row['mechanism'],
            ],
            'scope_type' => (string)$row['scope_type'],
            'mapping_owner_types' => array_values(array_filter(
                explode(',', (string)$row['mapping_owner_types'])
            )),
            'provider_term_owners' =>
                (int)$row['provider_term_owners'],
        ];
    };
    $rightOnlyAllowed = static function (array $scope): bool {
        if ($scope['provider_term_owners'] > 0) {
            return true;
        }
        return $scope['mapping_owner_types'] !== []
            && array_values(array_unique(
                $scope['mapping_owner_types']
            )) === ['recipe_source_ingredient'];
    };
    $leftStmt = $scopeStatement($left, $leftVersionId);
    $rightStmt = $scopeStatement($right, $rightVersionId);
    $leftScope = $normalizeScope($leftStmt->fetch(PDO::FETCH_ASSOC));
    $rightScope = $normalizeScope($rightStmt->fetch(PDO::FETCH_ASSOC));
    $leftCount = 0;
    $rightCount = 0;
    $commonCount = 0;
    $leftOnlyCount = 0;
    $rightOnlyCount = 0;
    $allowedRightOnlyCount = 0;
    $disallowedRightOnlyCount = 0;
    $leftOnlySample = [];
    $rightOnlySample = [];
    $disallowedRightOnlySample = [];
    $mismatches = [];
    $scopeMismatchCount = 0;
    $dispositionMismatches = [];
    $dispositionMismatchCount = 0;
    while ($leftScope !== null || $rightScope !== null) {
        if (
            $rightScope === null
            || (
                $leftScope !== null
                && strcmp(
                    $leftScope['portable_scope_hash'],
                    $rightScope['portable_scope_hash']
                ) < 0
            )
        ) {
            $leftCount++;
            $leftOnlyCount++;
            if (count($leftOnlySample) < 100) {
                $leftOnlySample[] =
                    $leftScope['portable_scope_hash'];
            }
            $leftScope = $normalizeScope(
                $leftStmt->fetch(PDO::FETCH_ASSOC)
            );
            continue;
        }
        if (
            $leftScope === null
            || strcmp(
                $rightScope['portable_scope_hash'],
                $leftScope['portable_scope_hash']
            ) < 0
        ) {
            $rightCount++;
            $rightOnlyCount++;
            $allowed = $rightOnlyAllowed($rightScope);
            if ($allowed) {
                $allowedRightOnlyCount++;
            } else {
                $disallowedRightOnlyCount++;
                if (count($disallowedRightOnlySample) < 100) {
                    $disallowedRightOnlySample[] =
                        $rightScope['portable_scope_hash'];
                }
            }
            if (count($rightOnlySample) < 100) {
                $rightOnlySample[] =
                    $rightScope['portable_scope_hash'];
            }
            $rightScope = $normalizeScope(
                $rightStmt->fetch(PDO::FETCH_ASSOC)
            );
            continue;
        }
        $leftCount++;
        $rightCount++;
        $commonCount++;
        $key = $leftScope['portable_scope_hash'];
        if (!hash_equals(
            $leftScope['fingerprint'],
            $rightScope['fingerprint']
        )) {
            $scopeMismatchCount++;
            if (count($mismatches) < 100) {
                $mismatches[] = $key;
            }
        }
        if (!hash_equals(
            $leftScope['portable_disposition_hash'],
            $rightScope['portable_disposition_hash']
        )) {
            $dispositionMismatchCount++;
            if (count($dispositionMismatches) < 100) {
                $dispositionMismatches[] = [
                    'portable_scope_hash' => $key,
                    'left' => $leftScope['outcome'],
                    'right' => $rightScope['outcome'],
                ];
            }
        }
        $leftScope = $normalizeScope(
            $leftStmt->fetch(PDO::FETCH_ASSOC)
        );
        $rightScope = $normalizeScope(
            $rightStmt->fetch(PDO::FETCH_ASSOC)
        );
    }
    $ownerStatement = static function (
        PDO $db,
        int $versionId
    ): PDOStatement {
        $stmt = $db->prepare("
            SELECT mapping.owner_type, mapping.owner_fingerprint,
                   disposition.disposition_code,
                   disposition.mechanism,
                   disposition.attributes_json,
                   entity.slug AS entity_slug
            FROM ingredient_ontology_mappings mapping
            JOIN ingredient_ontology_terminal_dispositions disposition
              ON disposition.id = mapping.terminal_disposition_id
            LEFT JOIN ingredient_ontology_entities entity
              ON entity.id = disposition.entity_id
            WHERE mapping.ontology_version_id = ?
              AND mapping.owner_type IN (
                  'product', 'recipe_ingredient'
              )
            ORDER BY mapping.owner_type, mapping.owner_fingerprint
        ");
        $stmt->execute([$versionId]);
        return $stmt;
    };
    $normalizeOwner = static function (mixed $row): ?array {
        if ($row === null || $row === false) {
            return null;
        }
        $attributes = json_decode(
            (string)$row['attributes_json'],
            true
        ) ?: [];
        ksort($attributes, SORT_STRING);
        $ownerType = (string)$row['owner_type'];
        $ownerFingerprint = (string)$row['owner_fingerprint'];
        $outcome = [
            'disposition_code' =>
                (string)$row['disposition_code'],
            'entity_slug' => $row['entity_slug'] !== null
                ? (string)$row['entity_slug']
                : null,
            'attributes' => $attributes,
            'mechanism' => (string)$row['mechanism'],
        ];
        return [
            'key' => $ownerType . '|' . $ownerFingerprint,
            'owner_type' => $ownerType,
            'owner_fingerprint' => $ownerFingerprint,
            'outcome' => $outcome,
            'digest' => ingredientOntologyV3Hash([
                'owner_type' => $ownerType,
                'owner_fingerprint' => $ownerFingerprint,
                'outcome' => $outcome,
            ]),
        ];
    };
    $leftOwnerStmt = $ownerStatement($left, $leftVersionId);
    $rightOwnerStmt = $ownerStatement($right, $rightVersionId);
    $leftOwner = $normalizeOwner(
        $leftOwnerStmt->fetch(PDO::FETCH_ASSOC)
    );
    $rightOwner = $normalizeOwner(
        $rightOwnerStmt->fetch(PDO::FETCH_ASSOC)
    );
    $leftOwnerCount = 0;
    $rightOwnerCount = 0;
    $commonOwnerCount = 0;
    $leftOnlyOwnerCount = 0;
    $rightOnlyOwnerCount = 0;
    $ownerOutcomeMismatchCount = 0;
    $leftOnlyOwnerSample = [];
    $rightOnlyOwnerSample = [];
    $ownerOutcomeMismatchSample = [];
    while ($leftOwner !== null || $rightOwner !== null) {
        if (
            $rightOwner === null
            || (
                $leftOwner !== null
                && strcmp($leftOwner['key'], $rightOwner['key']) < 0
            )
        ) {
            $leftOwnerCount++;
            $leftOnlyOwnerCount++;
            if (count($leftOnlyOwnerSample) < 100) {
                $leftOnlyOwnerSample[] = $leftOwner['key'];
            }
            $leftOwner = $normalizeOwner(
                $leftOwnerStmt->fetch(PDO::FETCH_ASSOC)
            );
            continue;
        }
        if (
            $leftOwner === null
            || strcmp($rightOwner['key'], $leftOwner['key']) < 0
        ) {
            $rightOwnerCount++;
            $rightOnlyOwnerCount++;
            if (count($rightOnlyOwnerSample) < 100) {
                $rightOnlyOwnerSample[] = $rightOwner['key'];
            }
            $rightOwner = $normalizeOwner(
                $rightOwnerStmt->fetch(PDO::FETCH_ASSOC)
            );
            continue;
        }
        $leftOwnerCount++;
        $rightOwnerCount++;
        $commonOwnerCount++;
        if (!hash_equals($leftOwner['digest'], $rightOwner['digest'])) {
            $ownerOutcomeMismatchCount++;
            if (count($ownerOutcomeMismatchSample) < 100) {
                $ownerOutcomeMismatchSample[] = [
                    'owner_type' => $leftOwner['owner_type'],
                    'owner_fingerprint' =>
                        $leftOwner['owner_fingerprint'],
                    'left' => $leftOwner['outcome'],
                    'right' => $rightOwner['outcome'],
                ];
            }
        }
        $leftOwner = $normalizeOwner(
            $leftOwnerStmt->fetch(PDO::FETCH_ASSOC)
        );
        $rightOwner = $normalizeOwner(
            $rightOwnerStmt->fetch(PDO::FETCH_ASSOC)
        );
    }
    return [
        'valid' => hash_equals($leftPortable, $rightPortable)
            && $scopeMismatchCount === 0
            && $dispositionMismatchCount === 0
            && $leftOnlyCount === 0
            && $disallowedRightOnlyCount === 0
            && $leftOnlyOwnerCount === 0
            && $rightOnlyOwnerCount === 0
            && $ownerOutcomeMismatchCount === 0,
        'left_portable_content_hash' => $leftPortable,
        'right_portable_content_hash' => $rightPortable,
        'portable_content_hash_matches' =>
            hash_equals($leftPortable, $rightPortable),
        'left_scope_count' => $leftCount,
        'right_scope_count' => $rightCount,
        'common_scope_count' => $commonCount,
        'scope_fingerprint_mismatch_count' => $scopeMismatchCount,
        'scope_fingerprint_mismatch_sample' => $mismatches,
        'common_scope_disposition_mismatch_count' =>
            $dispositionMismatchCount,
        'common_scope_disposition_mismatch_sample' =>
            $dispositionMismatches,
        'left_only_scope_count' => $leftOnlyCount,
        'right_only_scope_count' => $rightOnlyCount,
        'allowed_provider_source_only_scope_count' =>
            $allowedRightOnlyCount,
        'disallowed_right_only_scope_count' =>
            $disallowedRightOnlyCount,
        'left_only_scope_sample' => $leftOnlySample,
        'right_only_scope_sample' => $rightOnlySample,
        'disallowed_right_only_scope_sample' =>
            $disallowedRightOnlySample,
        'left_legacy_owner_count' => $leftOwnerCount,
        'right_legacy_owner_count' => $rightOwnerCount,
        'common_legacy_owner_count' => $commonOwnerCount,
        'left_only_legacy_owner_count' => $leftOnlyOwnerCount,
        'right_only_legacy_owner_count' => $rightOnlyOwnerCount,
        'common_owner_outcome_mismatch_count' =>
            $ownerOutcomeMismatchCount,
        'common_owner_outcome_mismatch_sample' =>
            $ownerOutcomeMismatchSample,
        'left_only_legacy_owner_sample' => $leftOnlyOwnerSample,
        'right_only_legacy_owner_sample' => $rightOnlyOwnerSample,
    ];
}

function ingredientOntologyV3ImportReviewWorkbook(
    PDO $db,
    int $versionId,
    string $path,
    string $kind,
    string $reviewer,
    string $batch
): array {
    if (!in_array($kind, ['disposition', 'provider_workbook'], true)) {
        throw new InvalidArgumentException('review import kind is invalid');
    }
    $version = ingredientOntologyV3Version($db, $versionId);
    if ($version === null) {
        throw new InvalidArgumentException('ontology version not found');
    }
    if ((string)$version['status'] !== 'building') {
        throw new RuntimeException(
            'review workbooks may only stage into a building copy version'
        );
    }
    if (!is_file($path)) {
        throw new InvalidArgumentException('review workbook is unavailable');
    }
    $inputHash = hash_file('sha256', $path);
    if (!is_string($inputHash) || strlen($inputHash) !== 64) {
        throw new RuntimeException('review workbook could not be hashed');
    }
    $reviewer = trim($reviewer);
    $batch = trim($batch);
    if (
        $reviewer === ''
        || $batch === ''
        || strlen($reviewer) > 120
        || strlen($batch) > 120
    ) {
        throw new InvalidArgumentException(
            'reviewer and batch are required'
        );
    }
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException('review workbook could not be opened');
    }
    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO ingredient_ontology_review_imports (
                ontology_version_id, import_kind, input_hash,
                manifest_hash, row_count, reviewer, review_batch,
                payload_json
            )
            VALUES (?, ?, ?, ?, 0, ?, ?, ?)
        ")->execute([
            $versionId,
            $kind,
            $inputHash,
            (string)$version['content_hash'],
            $reviewer,
            $batch,
            ingredientOntologyV3Json([
                'staging_only' => true,
                'ready_content_is_immutable' => true,
            ]),
        ]);
        $importId = (int)$db->lastInsertId();
        $header = fgetcsv($stream);
        if (!is_array($header)) {
            throw new RuntimeException('review workbook has no header');
        }
        $header = array_map(
            static fn(mixed $value): string => trim((string)$value),
            $header
        );
        foreach ([
            'scope_fingerprint', 'disposition_code',
            'entity_slug', 'attributes_json', 'evidence_hash',
        ] as $required) {
            if (!in_array($required, $header, true)) {
                throw new RuntimeException(
                    "review workbook is missing {$required}"
                );
            }
        }
        $scopeLookup = $db->prepare("
            SELECT COUNT(*)
            FROM ingredient_ontology_disposition_scopes
            WHERE ontology_version_id = ? AND scope_fingerprint = ?
        ");
        $insert = $db->prepare("
            INSERT INTO ingredient_ontology_review_import_rows (
                import_id, ontology_version_id, row_number,
                scope_fingerprint, owner_fingerprint, disposition_code,
                entity_slug, attributes_json, evidence_hash, row_hash
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $rowNumber = 0;
        $definitions = ingredientOntologyV3DispositionDefinitions();
        while (($values = fgetcsv($stream)) !== false) {
            $rowNumber++;
            if ($rowNumber > 100000) {
                throw new RuntimeException(
                    'review workbook row limit exceeded'
                );
            }
            $values = array_pad($values, count($header), '');
            $row = [];
            foreach ($header as $index => $column) {
                $row[$column] = trim((string)($values[$index] ?? ''));
            }
            $scopeFingerprint = (string)$row['scope_fingerprint'];
            $code = (string)$row['disposition_code'];
            $evidenceHash = (string)$row['evidence_hash'];
            if (
                !preg_match('/^[a-f0-9]{64}$/', $scopeFingerprint)
                || !isset($definitions[$code])
                || !preg_match('/^[a-f0-9]{64}$/', $evidenceHash)
            ) {
                throw new RuntimeException(
                    "review workbook row {$rowNumber} is invalid"
                );
            }
            $scopeLookup->execute([$versionId, $scopeFingerprint]);
            if ((int)$scopeLookup->fetchColumn() !== 1) {
                throw new RuntimeException(
                    "review workbook row {$rowNumber} is stale"
                );
            }
            $attributes = json_decode(
                (string)$row['attributes_json'],
                true,
                32,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($attributes)) {
                throw new RuntimeException(
                    "review workbook row {$rowNumber} attributes are invalid"
                );
            }
            $ownerFingerprint = (string)(
                $row['owner_fingerprint'] ?? ''
            );
            if (
                $ownerFingerprint !== ''
                && !preg_match('/^[a-f0-9]{64}$/', $ownerFingerprint)
            ) {
                throw new RuntimeException(
                    "review workbook row {$rowNumber} owner is invalid"
                );
            }
            $normalized = [
                'scope_fingerprint' => $scopeFingerprint,
                'owner_fingerprint' =>
                    $ownerFingerprint !== '' ? $ownerFingerprint : null,
                'disposition_code' => $code,
                'entity_slug' => (string)$row['entity_slug'],
                'attributes' => ingredientOntologyV3StableValue($attributes),
                'evidence_hash' => $evidenceHash,
            ];
            $insert->execute([
                $importId,
                $versionId,
                $rowNumber,
                $scopeFingerprint,
                $normalized['owner_fingerprint'],
                $code,
                $normalized['entity_slug'] !== ''
                    ? $normalized['entity_slug']
                    : null,
                ingredientOntologyV3Json($normalized['attributes']),
                $evidenceHash,
                ingredientOntologyV3Hash($normalized),
            ]);
        }
        $db->prepare("
            UPDATE ingredient_ontology_review_imports
            SET row_count = ?
            WHERE id = ?
        ")->execute([$rowNumber, $importId]);
        $db->commit();
        return [
            'import_id' => $importId,
            'row_count' => $rowNumber,
            'input_hash' => $inputHash,
            'staging_only' => true,
            'ready_content_mutated' => false,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    } finally {
        fclose($stream);
    }
}
