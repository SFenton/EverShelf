<?php
declare(strict_types=1);

const INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION =
    'ingredient-ontology-corpus-projection-v2';
const INGREDIENT_ONTOLOGY_CORPUS_ANNEX_SCOPE_BASE =
    'sealed_corpus_projection_checkpoint_v2';
const INGREDIENT_ONTOLOGY_CORPUS_ANNEX_SCOPE_MUTABLE =
    'mutable_aggregate_projection_v2';
const INGREDIENT_ONTOLOGY_CORPUS_ANNEX_SCOPE_PENDING =
    'mutable_aggregate_projection_pending_v2';
const INGREDIENT_ONTOLOGY_CORPUS_ANNEX_RESOLVER_VERSION =
    'corpus-projection-snapshot-v2';
const INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_DEPTH = 256;
const INGREDIENT_ONTOLOGY_CORPUS_ANNEX_COMPACTION_DEPTH = 64;
const INGREDIENT_ONTOLOGY_CORPUS_ANNEX_COMPACTION_ENTRY_LIMIT = 100000;
const INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS = 512;
const INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_SCOPE_ROWS = 800;
const INGREDIENT_ONTOLOGY_CORPUS_ANNEX_CACHE_SCHEMA_VERSION = 4;

function ingredientOntologyV3CorpusAnnexCompactionDepth(): int {
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && isset(
            $GLOBALS[
                'INGREDIENT_ONTOLOGY_CORPUS_ANNEX_COMPACTION_DEPTH_OVERRIDE'
            ]
        )
    ) {
        return max(
            1,
            min(
                INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_DEPTH - 1,
                (int)$GLOBALS[
                    'INGREDIENT_ONTOLOGY_CORPUS_ANNEX_COMPACTION_DEPTH_OVERRIDE'
                ]
            )
        );
    }
    return INGREDIENT_ONTOLOGY_CORPUS_ANNEX_COMPACTION_DEPTH;
}

function ingredientOntologyV3CorpusAnnexCompactionEntryLimit(): int {
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && isset(
            $GLOBALS[
                'INGREDIENT_ONTOLOGY_CORPUS_ANNEX_COMPACTION_ENTRY_OVERRIDE'
            ]
        )
    ) {
        return max(
            1,
            min(
                1000000,
                (int)$GLOBALS[
                    'INGREDIENT_ONTOLOGY_CORPUS_ANNEX_COMPACTION_ENTRY_OVERRIDE'
                ]
            )
        );
    }
    return INGREDIENT_ONTOLOGY_CORPUS_ANNEX_COMPACTION_ENTRY_LIMIT;
}

function ingredientOntologyV3CorpusAnnexZeroHash(): string {
    return str_repeat('0', 64);
}

function ingredientOntologyV3CorpusAnnexTableExists(PDO $db): bool {
    foreach ([
        'ingredient_ontology_corpus_annex_revisions',
        'ingredient_ontology_corpus_annex_entries',
        'ingredient_ontology_corpus_annex_effective_aggregates',
        'ingredient_ontology_corpus_annex_effective_members',
        'ingredient_ontology_corpus_annex_effective_entities',
        'ingredient_ontology_corpus_annex_projection_state',
    ] as $table) {
        if (!ingredientOntologyV3TableExists($db, $table)) {
            return false;
        }
    }
    return true;
}

function ingredientOntologyV3CorpusAnnexRevision(
    PDO $db,
    int $revisionId
): ?array {
    if (
        $revisionId <= 0
        || !ingredientOntologyV3CorpusAnnexTableExists($db)
    ) {
        return null;
    }
    $stmt = $db->prepare("
        SELECT *
        FROM ingredient_ontology_corpus_annex_revisions
        WHERE id = ?
    ");
    $stmt->execute([$revisionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    foreach ([
        'id',
        'hash_version',
        'ontology_version_id',
        'parent_revision_id',
        'base_products_max_id',
        'base_recipe_catalog_max_id',
        'base_recipe_origins_max_id',
        'base_recipe_ingredients_max_id',
        'base_recipe_source_ingredients_max_id',
        'captured_ontology_source_revision',
        'covered_ontology_source_revision',
        'identity_extension_revision',
        'covered_identity_extension_revision',
        'entry_count',
        'aggregate_count',
    ] as $key) {
        if (array_key_exists($key, $row)) {
            $row[$key] = $row[$key] !== null
                ? (int)$row[$key]
                : null;
        }
    }
    return $row;
}

function ingredientOntologyV3CorpusAnnexMaxima(PDO $db): array {
    $maxima = [];
    foreach ([
        'products',
        'recipe_catalog',
        'recipe_origins',
        'recipe_ingredients',
        'recipe_source_ingredients',
    ] as $table) {
        $maxima[$table] = ingredientOntologyV3TableExists($db, $table)
            ? (int)$db->query(
                "SELECT COALESCE(MAX(id), 0) FROM {$table}"
            )->fetchColumn()
            : 0;
    }
    return $maxima;
}

function ingredientOntologyV3CorpusAnnexResolutionInputHash(
    array $version
): string {
    return ingredientOntologyV3Hash([
        'algorithm' =>
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                . ':resolution-input',
        'ontology_version_id' => (int)$version['id'],
        'ontology_content_hash' => (string)$version['content_hash'],
        'ontology_seal_hash' => (string)$version['seal_hash'],
        'product_resolver_version' =>
            ingredientOntologyV3ProductIdentityResolverVersion(),
        'recipe_resolver_version' =>
            ingredientOntologyV3RecipeIdentityResolverVersion(),
        'review_manifest_hash' =>
            ingredientOntologyV3IdentityAnnexReviewManifestHash(),
    ]);
}

function ingredientOntologyV3CorpusAnnexRevisionHash(
    array $revision
): string {
    $payload = [
        'algorithm' => INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION,
        'ontology_content_hash' =>
            (string)$revision['ontology_content_hash'],
        'ontology_seal_hash' =>
            (string)$revision['ontology_seal_hash'],
        'parent_revision_hash' =>
            (string)$revision['parent_revision_hash'],
        'base_corpus_hash' =>
            (string)$revision['base_corpus_hash'],
        'captured_corpus_hash' =>
            (string)$revision['captured_corpus_hash'],
        'base_maxima' => [
            'products' => (int)$revision['base_products_max_id'],
            'recipe_catalog' =>
                (int)$revision['base_recipe_catalog_max_id'],
            'recipe_origins' =>
                (int)$revision['base_recipe_origins_max_id'],
            'recipe_ingredients' =>
                (int)$revision['base_recipe_ingredients_max_id'],
            'recipe_source_ingredients' =>
                (int)$revision[
                    'base_recipe_source_ingredients_max_id'
                ],
        ],
        'captured_ontology_source_revision' =>
            (int)$revision['captured_ontology_source_revision'],
        'covered_ontology_source_revision' =>
            (int)$revision['covered_ontology_source_revision'],
        'mutation_manifest_hash' =>
            (string)$revision['mutation_manifest_hash'],
        'entry_set_hash' => (string)$revision['entry_set_hash'],
        'projection_root_hash' =>
            (string)$revision['projection_root_hash'],
        'resolution_input_hash' =>
            (string)$revision['resolution_input_hash'],
        'identity_extension_revision' =>
            (int)$revision['identity_extension_revision'],
        'identity_extension_hash' =>
            (string)$revision['identity_extension_hash'],
        'entry_count' => (int)$revision['entry_count'],
        'aggregate_count' => (int)$revision['aggregate_count'],
        'reconciliation_mode' =>
            (string)$revision['reconciliation_mode'],
    ];
    if ((int)($revision['hash_version'] ?? 1) >= 2) {
        $payload['covered_identity_extension_revision'] =
            (int)$revision['covered_identity_extension_revision'];
        $payload['covered_identity_extension_hash'] =
            (string)$revision['covered_identity_extension_hash'];
    }
    return ingredientOntologyV3Hash($payload);
}

function ingredientOntologyV3CorpusAnnexEntryHash(
    array $entry
): string {
    $payload = $entry['payload'] ?? json_decode(
        (string)($entry['payload_json'] ?? '{}'),
        true
    );
    $identity = $entry['identity'] ?? json_decode(
        (string)($entry['identity_json'] ?? '{}'),
        true
    );
    return ingredientOntologyV3Hash([
        'algorithm' =>
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION . ':entry',
        'ordinal' => (int)$entry['ordinal'],
        'entry_type' => (string)$entry['entry_type'],
        'operation' => (string)$entry['operation'],
        'owner_type' => (string)$entry['owner_type'],
        'owner_id' => (int)$entry['owner_id'],
        'recipe_id' => ($entry['recipe_id'] ?? null) !== null
            ? (int)$entry['recipe_id']
            : null,
        'owner_fingerprint' =>
            (string)$entry['owner_fingerprint'],
        'identity_status' =>
            (string)$entry['identity_status'],
        'identity_disposition' =>
            (string)$entry['identity_disposition'],
        'satisfies_required' =>
            (int)$entry['satisfies_required'],
        'native_entity_slug' =>
            $entry['native_entity_slug'] ?? null,
        'identity_extension_key_hash' =>
            $entry['identity_extension_key_hash'] ?? null,
        'resolver_version' =>
            (string)$entry['resolver_version'],
        'review_manifest_hash' =>
            (string)$entry['review_manifest_hash'],
        'evidence_hash' => (string)$entry['evidence_hash'],
        'aggregate_source_hash' =>
            (string)$entry['aggregate_source_hash'],
        'resolution_input_hash' =>
            (string)$entry['resolution_input_hash'],
        'aggregate_hash' => (string)$entry['aggregate_hash'],
        'member_count' => (int)$entry['member_count'],
        'identity' => is_array($identity) ? $identity : [],
        'payload' => is_array($payload) ? $payload : [],
    ]);
}

function ingredientOntologyV3CorpusAnnexEntrySetHash(
    array $entries
): string {
    $hash = hash_init('sha256');
    hash_update(
        $hash,
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
            . ":entry-set\n"
    );
    foreach ($entries as $entry) {
        hash_update($hash, (string)$entry['row_hash'] . "\n");
    }
    return hash_final($hash);
}

function ingredientOntologyV3CorpusAnnexEntryRows(
    PDO $db,
    int $revisionId,
    int $pageSize = 1000
): Generator {
    $pageSize = max(1, min(5000, $pageSize));
    $afterOrdinal = 0;
    $stmt = $db->prepare("
        SELECT *
        FROM ingredient_ontology_corpus_annex_entries
        WHERE corpus_annex_revision_id = ?
          AND ordinal > ?
        ORDER BY ordinal
        LIMIT {$pageSize}
    ");
    do {
        $stmt->execute([$revisionId, $afterOrdinal]);
        $rowCount = 0;
        try {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rowCount++;
                $afterOrdinal = (int)$row['ordinal'];
                yield $row;
            }
        } finally {
            $stmt->closeCursor();
        }
    } while ($rowCount === $pageSize);
}

function ingredientOntologyV3CorpusAnnexStoredEntrySetHash(
    PDO $db,
    int $revisionId
): string {
    $hash = hash_init('sha256');
    hash_update(
        $hash,
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
            . ":entry-set\n"
    );
    $afterOrdinal = 0;
    $pageSize = 2000;
    $stmt = $db->prepare("
        SELECT ordinal, row_hash
        FROM ingredient_ontology_corpus_annex_entries
        WHERE corpus_annex_revision_id = ?
          AND ordinal > ?
        ORDER BY ordinal
        LIMIT {$pageSize}
    ");
    do {
        $stmt->execute([$revisionId, $afterOrdinal]);
        $rowCount = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rowCount++;
            $afterOrdinal = (int)$row['ordinal'];
            hash_update($hash, (string)$row['row_hash'] . "\n");
        }
        $stmt->closeCursor();
    } while ($rowCount === $pageSize);
    return hash_final($hash);
}

function ingredientOntologyV3CorpusAnnexMutationManifest(
    int $fromRevision,
    int $throughRevision,
    array $events,
    string $mode = 'journal',
    array $aggregateKeys = [],
    ?array $continuation = null
): array {
    $manifest = [];
    foreach ($events as $event) {
        $scopes = [];
        foreach ((array)($event['scopes'] ?? []) as $scope) {
            $scopes[] = [
                'aggregate_type' =>
                    (string)$scope['aggregate_type'],
                'aggregate_id' =>
                    $scope['aggregate_id'] !== null
                        ? (int)$scope['aggregate_id']
                        : null,
                'scope_role' => (string)$scope['scope_role'],
                'source_table' => (string)$scope['source_table'],
                'source_row_id' =>
                    $scope['source_row_id'] !== null
                        ? (int)$scope['source_row_id']
                        : null,
                'source_key' => (string)$scope['source_key'],
                'metadata' => json_decode(
                    (string)$scope['metadata_json'],
                    true
                ) ?: [],
            ];
        }
        $manifest[] = [
            'revision' => (int)$event['revision'],
            'lane' => (string)$event['lane'],
            'owner_type' => (string)$event['owner_type'],
            'owner_id' => $event['owner_id'] !== null
                ? (int)$event['owner_id']
                : null,
            'operation' => (string)$event['operation'],
            'source_table' => (string)($event['source_table'] ?? ''),
            'source_row_id' =>
                ($event['source_row_id'] ?? null) !== null
                    ? (int)$event['source_row_id']
                    : null,
            'reason' => (string)$event['reason'],
            'scopes' => $scopes,
        ];
    }
    sort($aggregateKeys, SORT_STRING);
    $payload = [
        'mode' => $mode,
        'from_revision' => $fromRevision,
        'through_revision' => $throughRevision,
        'aggregate_keys' => $aggregateKeys,
        'events' => $manifest,
    ];
    if ($continuation !== null) {
        $payload['continuation'] = [
            'target_revision' =>
                (int)($continuation['target_revision'] ?? 0),
            'after_aggregate_key' => (string)(
                $continuation['after_aggregate_key'] ?? ''
            ),
        ];
    }
    return [
        'rows' => $manifest,
        'json' => ingredientOntologyV3Json($payload),
        'hash' => ingredientOntologyV3Hash([
            'algorithm' =>
                INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                    . ':mutation-manifest',
            'payload' => $payload,
        ]),
    ];
}

function ingredientOntologyV3CorpusAnnexChain(
    PDO $db,
    int $revisionId
): array {
    $chain = [];
    $seen = [];
    while ($revisionId > 0) {
        if (
            isset($seen[$revisionId])
            || count($chain)
                >= INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_DEPTH
        ) {
            throw new RuntimeException(
                'corpus projection revision lineage is invalid'
            );
        }
        $seen[$revisionId] = true;
        $revision = ingredientOntologyV3CorpusAnnexRevision(
            $db,
            $revisionId
        );
        if ($revision === null) {
            throw new RuntimeException(
                'corpus projection revision lineage is incomplete'
            );
        }
        $chain[] = $revision;
        $revisionId = (int)($revision['parent_revision_id'] ?? 0);
    }
    return array_reverse($chain);
}

function ingredientOntologyV3CorpusAnnexBaseCorpusHash(
    PDO $db,
    array $root
): string {
    $hash = hash_init('sha256');
    hash_update($hash, "products\n");
    $products = $db->prepare("
        SELECT id, name, brand, category, prepared_food
        FROM products
        WHERE id <= ?
        ORDER BY id
    ");
    $products->execute([(int)$root['base_products_max_id']]);
    while ($row = $products->fetch(PDO::FETCH_ASSOC)) {
        hash_update(
            $hash,
            ingredientOntologyV3Json([
                'owner_type' => 'product',
                'owner_id' => (int)$row['id'],
                'fingerprint' =>
                    ingredientOntologyV3ProductOwnerFingerprint(
                        $row
                    ),
            ]) . "\n"
        );
    }
    hash_update($hash, "recipe_ingredients\n");
    $ranking = $db->prepare("
        SELECT ingredient.*,
               COALESCE(
                   NULLIF(ingredient.raw_text, ''),
                   ingredient.normalized_name
               ) AS source_label,
               recipe.language, recipe.primary_connector,
               COALESCE(origin.external_id, '')
                   AS origin_external_id,
               COALESCE(origin.locale, '') AS origin_locale,
               COALESCE(origin.content_language, '')
                   AS origin_content_language
        FROM recipe_ingredients ingredient
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
        WHERE ingredient.id <= ?
          AND recipe.id <= ?
        ORDER BY ingredient.id
    ");
    $ranking->execute([
        (int)$root['base_recipe_ingredients_max_id'],
        (int)$root['base_recipe_catalog_max_id'],
    ]);
    while ($row = $ranking->fetch(PDO::FETCH_ASSOC)) {
        hash_update(
            $hash,
            ingredientOntologyV3Json([
                'owner_type' => 'recipe_ingredient',
                'owner_id' => (int)$row['id'],
                'fingerprint' =>
                    ingredientOntologyV3RecipeOwnerFingerprint(
                        'recipe_ingredient',
                        $row
                    ),
            ]) . "\n"
        );
    }
    hash_update($hash, "recipe_source_ingredients\n");
    $source = $db->prepare("
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
                   AS metadata_schema_version,
               COALESCE(origin.external_id, '')
                   AS origin_external_id,
               COALESCE(origin.locale, '') AS origin_locale,
               COALESCE(origin.content_language, '')
                   AS origin_content_language
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
        WHERE ingredient.id <= ?
          AND recipe.id <= ?
        ORDER BY ingredient.id
    ");
    $source->execute([
        (int)$root[
            'base_recipe_source_ingredients_max_id'
        ],
        (int)$root['base_recipe_catalog_max_id'],
    ]);
    while ($row = $source->fetch(PDO::FETCH_ASSOC)) {
        hash_update(
            $hash,
            ingredientOntologyV3Json([
                'owner_type' => 'recipe_source_ingredient',
                'owner_id' => (int)$row['id'],
                'fingerprint' =>
                    ingredientOntologyV3RecipeOwnerFingerprint(
                        'recipe_source_ingredient',
                        $row
                    ),
            ]) . "\n"
        );
    }
    return hash_final($hash);
}

function ingredientOntologyV3CorpusAnnexScopeFingerprint(
    string $entryType,
    array $payload
): string {
    return ingredientOntologyV3Hash([
        'algorithm' =>
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION . ':member',
        'entry_type' => $entryType,
        'payload' => $payload,
    ]);
}

function ingredientOntologyV3CorpusAnnexCanonicalRows(
    PDO $db,
    array $canonicalIds,
    int $versionId
): array {
    $canonicalIds = array_values(array_unique(array_filter(
        array_map('intval', $canonicalIds),
        static fn(int $id): bool => $id > 0
    )));
    if (
        !$canonicalIds
        || !ingredientOntologyV3TableExists(
            $db,
            'canonical_ingredients'
        )
    ) {
        return [];
    }
    $placeholders = implode(
        ',',
        array_fill(0, count($canonicalIds), '?')
    );
    $stmt = $db->prepare("
        SELECT canonical.id, canonical.slug, canonical.name,
               canonical.parent_slug, canonical.category,
               canonical.source, canonical.external_ids_json,
               entity.slug AS frozen_entity_slug
        FROM canonical_ingredients canonical
        LEFT JOIN ingredient_ontology_entities entity
          ON entity.ontology_version_id = ?
         AND entity.legacy_canonical_ingredient_id = canonical.id
         AND entity.active = 1
        WHERE canonical.id IN ({$placeholders})
        ORDER BY canonical.id
    ");
    $stmt->execute(array_merge([$versionId], $canonicalIds));
    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'id' => (int)$row['id'],
            'slug' => (string)$row['slug'],
            'name' => (string)$row['name'],
            'parent_slug' => $row['parent_slug'] !== null
                ? (string)$row['parent_slug']
                : null,
            'category' => (string)($row['category'] ?? ''),
            'source' => (string)($row['source'] ?? ''),
            'external_ids_json' =>
                (string)($row['external_ids_json'] ?? ''),
            'frozen_entity_slug' =>
                $row['frozen_entity_slug'] !== null
                    ? (string)$row['frozen_entity_slug']
                    : null,
        ];
    }
    return $rows;
}

function ingredientOntologyV3CorpusAnnexGeminiAliases(
    PDO $db,
    array $labels
): array {
    if (!ingredientOntologyV3TableExists($db, 'taxonomy_aliases')) {
        return [];
    }
    $normalized = [];
    foreach ($labels as $label) {
        $value = ingredientOntologyV3NormalizeLabel((string)$label);
        if ($value !== '') {
            $normalized[$value] = true;
        }
    }
    if (!$normalized) {
        return [];
    }
    $values = array_keys($normalized);
    sort($values, SORT_STRING);
    $placeholders = implode(',', array_fill(0, count($values), '?'));
    $stmt = $db->prepare("
        SELECT id, tree_id, node_id, alias, normalized_alias,
               source, active
        FROM taxonomy_aliases
        WHERE active = 1
          AND lower(source) LIKE '%gemini%'
          AND normalized_alias IN ({$placeholders})
        ORDER BY normalized_alias, node_id, id
    ");
    $stmt->execute($values);
    return array_map(
        static fn(array $row): array => [
            'id' => (int)$row['id'],
            'tree_id' => (int)$row['tree_id'],
            'node_id' => (int)$row['node_id'],
            'alias' => (string)$row['alias'],
            'normalized_alias' => (string)$row['normalized_alias'],
            'source' => (string)$row['source'],
            'active' => (int)$row['active'],
        ],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

function ingredientOntologyV3CorpusAnnexProductPayload(
    PDO $db,
    int $productId,
    ?int $versionId = null
): ?array {
    $stmt = $db->prepare("
        SELECT id, name, brand, category, prepared_food
        FROM products
        WHERE id = ?
    ");
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $links = [];
    $canonicalIds = [];
    if (ingredientOntologyV3TableExists($db, 'product_ingredients')) {
        $link = $db->prepare("
            SELECT id, ingredient_id, role, confidence,
                   source, evidence
            FROM product_ingredients
            WHERE product_id = ?
            ORDER BY
                CASE role WHEN 'primary' THEN 0 ELSE 1 END,
                ingredient_id, role, id
        ");
        $link->execute([$productId]);
        while ($value = $link->fetch(PDO::FETCH_ASSOC)) {
            $canonicalIds[] = (int)$value['ingredient_id'];
            $links[] = [
                'id' => (int)$value['id'],
                'ingredient_id' => (int)$value['ingredient_id'],
                'role' => (string)$value['role'],
                'confidence' =>
                    round((float)$value['confidence'], 6),
                'source' => (string)$value['source'],
                'evidence' => (string)($value['evidence'] ?? ''),
            ];
        }
    }
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'brand' => (string)($row['brand'] ?? ''),
        'category' => (string)($row['category'] ?? ''),
        'prepared_food' => (int)($row['prepared_food'] ?? 0),
        'product_ingredients' => $links,
        'canonical_dependencies' =>
            $versionId !== null
                ? ingredientOntologyV3CorpusAnnexCanonicalRows(
                    $db,
                    $canonicalIds,
                    $versionId
                )
                : [],
        'gemini_aliases' =>
            ingredientOntologyV3CorpusAnnexGeminiAliases(
                $db,
                [(string)$row['name']]
            ),
    ];
}

function ingredientOntologyV3CorpusAnnexRecipeScopePayload(
    PDO $db,
    int $recipeId
): ?array {
    $stmt = $db->prepare("
        SELECT id, primary_connector, language, deleted_at
        FROM recipe_catalog
        WHERE id = ?
    ");
    $stmt->execute([$recipeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['deleted_at'] !== null) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'primary_connector' =>
            (string)$row['primary_connector'],
        'language' => (string)$row['language'],
    ];
}

function ingredientOntologyV3CorpusAnnexOriginPayload(
    PDO $db,
    int $originId
): ?array {
    $stmt = $db->prepare("
        SELECT id, recipe_id, connector, external_id, locale,
               content_language, metadata_version,
               metadata_schema_version
        FROM recipe_origins
        WHERE id = ?
    ");
    $stmt->execute([$originId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'recipe_id' => (int)$row['recipe_id'],
        'connector' => (string)$row['connector'],
        'external_id' => $row['external_id'] !== null
            ? (string)$row['external_id']
            : null,
        'locale' => $row['locale'] !== null
            ? (string)$row['locale']
            : null,
        'content_language' =>
            $row['content_language'] !== null
                ? (string)$row['content_language']
                : null,
        'metadata_version' =>
            $row['metadata_version'] !== null
                ? (string)$row['metadata_version']
                : null,
        'metadata_schema_version' =>
            $row['metadata_schema_version'] !== null
                ? (string)$row['metadata_schema_version']
                : null,
    ];
}

function ingredientOntologyV3CorpusAnnexIngredientPayload(
    PDO $db,
    string $entryType,
    int $ownerId
): ?array {
    $source = $entryType === 'recipe_source_ingredient';
    $table = $source
        ? 'recipe_source_ingredients'
        : 'recipe_ingredients';
    $label = $source
        ? "COALESCE(NULLIF(ingredient.name, ''), "
            . "ingredient.normalized_name)"
        : "COALESCE(NULLIF(ingredient.raw_text, ''), "
            . "ingredient.normalized_name)";
    $stmt = $db->prepare("
        SELECT ingredient.*, {$label} AS source_label,
               recipe.language, recipe.primary_connector,
               COALESCE(
                   NULLIF(origin.connector, ''),
                   NULLIF(recipe.primary_connector, ''),
                   'unknown_legacy_adapter'
               ) AS connector,
               COALESCE(origin.external_id, '')
                   AS origin_external_id,
               COALESCE(origin.locale, '') AS origin_locale,
               COALESCE(origin.content_language, '')
                   AS origin_content_language,
               COALESCE(origin.metadata_version, '')
                   AS metadata_version,
               COALESCE(origin.metadata_schema_version, '')
                   AS metadata_schema_version
        FROM {$table} ingredient
        JOIN recipe_catalog recipe
          ON recipe.id = ingredient.recipe_id
         AND recipe.deleted_at IS NULL
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
        WHERE ingredient.id = ?
    ");
    $stmt->execute([$ownerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $payload = [
        'id' => (int)$row['id'],
        'recipe_id' => (int)$row['recipe_id'],
        'position' => (int)$row['position'],
        'source_label' => (string)$row['source_label'],
        'normalized_name' => (string)$row['normalized_name'],
        'language' =>
            ingredientOntologyV3RecipeIdentityLanguage($row),
        'connector' => (string)$row['connector'],
        'origin_external_id' =>
            (string)$row['origin_external_id'],
        'origin_locale' => (string)$row['origin_locale'],
        'origin_content_language' =>
            (string)$row['origin_content_language'],
        'canonical_ingredient_id' =>
            $row['canonical_ingredient_id'] !== null
                ? (int)$row['canonical_ingredient_id']
                : null,
        'taxonomy_node_id' =>
            $row['taxonomy_node_id'] !== null
                ? (int)$row['taxonomy_node_id']
                : null,
        'mapping_confidence' =>
            round((float)$row['mapping_confidence'], 6),
        'mapping_source' => (string)$row['mapping_source'],
    ];
    if ($source) {
        $payload += [
            'source_optional' =>
                $row['source_optional'] !== null
                    ? (int)$row['source_optional']
                    : null,
            'source_ingredient_ref' =>
                $row['source_ingredient_ref'] !== null
                    ? (string)$row['source_ingredient_ref']
                    : null,
            'source_default_title' =>
                $row['source_default_title'] !== null
                    ? (string)$row['source_default_title']
                    : null,
            'metadata_version' =>
                (string)$row['metadata_version'],
            'metadata_schema_version' =>
                (string)$row['metadata_schema_version'],
        ];
    } else {
        $payload += [
            'source_is_required' =>
                $row['source_is_required'] !== null
                    ? (int)$row['source_is_required']
                    : null,
            'source_is_optional' =>
                $row['source_is_optional'] !== null
                    ? (int)$row['source_is_optional']
                    : null,
            'requiredness_source' =>
                (string)$row['requiredness_source'],
        ];
    }
    return $payload;
}

function ingredientOntologyV3CorpusAnnexRowsForRecipe(
    PDO $db,
    int $recipeId,
    ?int $versionId = null
): array {
    $scope = ingredientOntologyV3CorpusAnnexRecipeScopePayload(
        $db,
        $recipeId
    );
    if ($scope === null) {
        return [];
    }
    $origins = [];
    $stmt = $db->prepare("
        SELECT id
        FROM recipe_origins
        WHERE recipe_id = ?
        ORDER BY id
    ");
    $stmt->execute([$recipeId]);
    while (($id = $stmt->fetchColumn()) !== false) {
        $payload = ingredientOntologyV3CorpusAnnexOriginPayload(
            $db,
            (int)$id
        );
        if ($payload !== null) {
            $origins[] = $payload;
        }
    }
    $ranking = [];
    $stmt = $db->prepare("
        SELECT id
        FROM recipe_ingredients
        WHERE recipe_id = ?
        ORDER BY position, id
    ");
    $stmt->execute([$recipeId]);
    while (($id = $stmt->fetchColumn()) !== false) {
        $payload = ingredientOntologyV3CorpusAnnexIngredientPayload(
            $db,
            'recipe_ingredient',
            (int)$id
        );
        if ($payload !== null) {
            $ranking[] = $payload;
        }
    }
    $source = [];
    $stmt = $db->prepare("
        SELECT id
        FROM recipe_source_ingredients
        WHERE recipe_id = ?
        ORDER BY position, id
    ");
    $stmt->execute([$recipeId]);
    while (($id = $stmt->fetchColumn()) !== false) {
        $payload = ingredientOntologyV3CorpusAnnexIngredientPayload(
            $db,
            'recipe_source_ingredient',
            (int)$id
        );
        if ($payload !== null) {
            $source[] = $payload;
        }
    }
    $labels = [];
    $canonicalIds = [];
    foreach (array_merge($ranking, $source) as $ingredient) {
        $labels[] = (string)$ingredient['source_label'];
        if ($ingredient['canonical_ingredient_id'] !== null) {
            $canonicalIds[] =
                (int)$ingredient['canonical_ingredient_id'];
        }
    }
    $cohort = null;
    if (
        $versionId !== null
        && ingredientOntologyV3TableExists(
            $db,
            'ingredient_ontology_recipe_cohorts'
        )
    ) {
        $cohortStmt = $db->prepare("
            SELECT cohort, winner_votes, runner_up_votes, margin,
                   conflict_count, votes_json, recipe_fingerprint,
                   algorithm_hash
            FROM ingredient_ontology_recipe_cohorts
            WHERE ontology_version_id = ? AND recipe_id = ?
        ");
        $cohortStmt->execute([$versionId, $recipeId]);
        $cohort = $cohortStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($cohort !== null) {
            foreach ([
                'winner_votes',
                'runner_up_votes',
                'margin',
                'conflict_count',
            ] as $column) {
                $cohort[$column] = (int)$cohort[$column];
            }
        }
    }
    return [
        'scope' => $scope,
        'origins' => $origins,
        'ranking' => $ranking,
        'source' => $source,
        'canonical_dependencies' =>
            $versionId !== null
                ? ingredientOntologyV3CorpusAnnexCanonicalRows(
                    $db,
                    $canonicalIds,
                    $versionId
                )
                : [],
        'gemini_aliases' =>
            ingredientOntologyV3CorpusAnnexGeminiAliases(
                $db,
                $labels
            ),
        'cohort_context' => [
            'normalized_labels' => array_map(
                'ingredientOntologyV3NormalizeLabel',
                $labels
            ),
            'sealed_cohort' => $cohort,
        ],
        'primary_origin_id' => (function () use (
            $origins,
            $scope
        ): ?int {
            foreach ($origins as $origin) {
                if (
                    (string)$origin['connector']
                        === (string)$scope['primary_connector']
                ) {
                    return (int)$origin['id'];
                }
            }
            return null;
        })(),
    ];
}

function ingredientOntologyV3CorpusAnnexRecipeIdentityDependencies(
    PDO $db,
    int $versionId,
    int $recipeId,
    int $identityExtensionRevision
): array {
    if (
        $identityExtensionRevision <= 0
        || !ingredientOntologyV3ExactSelfIdentityEnabled()
        || !ingredientOntologyV3TableExists(
            $db,
            'ingredient_ontology_identity_extension_entities'
        )
    ) {
        return [];
    }
    $ingredientIds = $db->prepare("
        SELECT id
        FROM recipe_ingredients
        WHERE recipe_id = ?
        ORDER BY id
    ");
    $ingredientIds->execute([$recipeId]);
    $ingredientIds = array_map(
        'intval',
        $ingredientIds->fetchAll(PDO::FETCH_COLUMN)
    );
    if (!$ingredientIds) {
        return [];
    }
    $placeholders = implode(
        ',',
        array_fill(0, count($ingredientIds), '?')
    );
    $identityKeys = [];
    $addIdentityKey = static function (
        string $normalizedLabel,
        string $language
    ) use (&$identityKeys): void {
        $normalizedLabel =
            ingredientOntologyV3NormalizeLabel($normalizedLabel);
        if ($normalizedLabel === '') {
            return;
        }
        $identityKeys[
            $normalizedLabel . "\0"
                . ingredientOntologyV3IdentityLanguageKey($language)
        ] = true;
    };
    $mapping = $db->prepare("
        SELECT normalized_label, language
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
          AND owner_type = 'recipe_ingredient'
          AND owner_id IN ({$placeholders})
    ");
    $mapping->execute([$versionId, ...$ingredientIds]);
    while ($row = $mapping->fetch(PDO::FETCH_ASSOC)) {
        $addIdentityKey(
            (string)$row['normalized_label'],
            (string)$row['language']
        );
    }
    $annex = $db->prepare("
        SELECT normalized_label, language
        FROM ingredient_ontology_recipe_identity_annex
        WHERE ontology_version_id = ?
          AND recipe_ingredient_id IN ({$placeholders})
          AND resolver_version = ?
          AND review_manifest_hash = ?
    ");
    $annex->execute([
        $versionId,
        ...$ingredientIds,
        ingredientOntologyV3RecipeIdentityResolverVersion(),
        ingredientOntologyV3IdentityAnnexReviewManifestHash(),
    ]);
    while ($row = $annex->fetch(PDO::FETCH_ASSOC)) {
        $addIdentityKey(
            (string)$row['normalized_label'],
            (string)$row['language']
        );
    }
    if (!$identityKeys) {
        return [];
    }
    $normalizedLabels = [];
    foreach (array_keys($identityKeys) as $key) {
        $normalizedLabels[explode("\0", $key, 2)[0]] = true;
    }
    $labelPlaceholders = implode(
        ',',
        array_fill(0, count($normalizedLabels), '?')
    );
    $extension = $db->prepare("
        SELECT id, created_revision, content_hash,
               identity_key_hash, normalized_label, language,
               context_signature
        FROM ingredient_ontology_identity_extension_entities
        WHERE ontology_version_id = ?
          AND identity_domain = 'food'
          AND normalized_label IN ({$labelPlaceholders})
          AND status = 'active'
          AND created_revision <= ?
        ORDER BY id
    ");
    $extension->execute([
        $versionId,
        ...array_keys($normalizedLabels),
        $identityExtensionRevision,
    ]);
    $extensions = [];
    while ($row = $extension->fetch(PDO::FETCH_ASSOC)) {
        $key = (string)$row['normalized_label'] . "\0"
            . ingredientOntologyV3IdentityLanguageKey(
                (string)$row['language']
            );
        if (isset($identityKeys[$key])) {
            $extensions[(int)$row['id']] = $row;
        }
    }
    if (!$extensions) {
        return [];
    }
    $extensionPlaceholders = implode(
        ',',
        array_fill(0, count($extensions), '?')
    );
    $products = $db->prepare("
        SELECT product_id, owner_fingerprint, evidence_hash,
               extension_entity_id
        FROM ingredient_ontology_identity_annex
        WHERE ontology_version_id = ?
          AND extension_entity_id IN ({$extensionPlaceholders})
          AND status = 'accepted'
          AND resolver_version = ?
          AND review_manifest_hash = ?
        ORDER BY product_id, extension_entity_id
    ");
    $products->execute([
        $versionId,
        ...array_keys($extensions),
        ingredientOntologyV3ProductIdentityResolverVersion(),
        ingredientOntologyV3IdentityAnnexReviewManifestHash(),
    ]);
    $dependencies = [];
    while ($product = $products->fetch(PDO::FETCH_ASSOC)) {
        $extension = $extensions[
            (int)$product['extension_entity_id']
        ] ?? null;
        if (!is_array($extension)) {
            continue;
        }
        $dependency = [
            'product_id' => (int)$product['product_id'],
            'owner_fingerprint' =>
                (string)$product['owner_fingerprint'],
            'evidence_hash' => (string)$product['evidence_hash'],
            'extension_entity_id' => (int)$extension['id'],
            'created_revision' =>
                (int)$extension['created_revision'],
            'content_hash' => (string)$extension['content_hash'],
            'identity_key_hash' =>
                (string)$extension['identity_key_hash'],
            'normalized_label' =>
                (string)$extension['normalized_label'],
            'language' => (string)$extension['language'],
            'context_signature' =>
                (string)$extension['context_signature'],
        ];
        $dependencies[
            $dependency['product_id'] . ':'
                . $dependency['identity_key_hash']
        ] = $dependency;
    }
    ksort($dependencies, SORT_STRING);
    return array_values($dependencies);
}

function ingredientOntologyV3CorpusAnnexScopeIdentity(
    string $entryType,
    string $ownerFingerprint,
    array $payload
): array {
    $reviewHash =
        ingredientOntologyV3IdentityAnnexReviewManifestHash();
    return [
        'identity_status' => 'not_applicable',
        'identity_disposition' => 'scope_evidence',
        'satisfies_required' => 0,
        'native_entity_slug' => null,
        'identity_extension_key_hash' => null,
        'entity_key' => null,
        'resolver_version' =>
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_RESOLVER_VERSION,
        'review_manifest_hash' => $reviewHash,
        'evidence_hash' => ingredientOntologyV3Hash([
            'entry_type' => $entryType,
            'owner_fingerprint' => $ownerFingerprint,
            'payload' => $payload,
        ]),
        'source_kind' => 'scope',
        'mapping_id' => null,
        'subject_id' => null,
        'attributes' => [],
        'relations' => [],
    ];
}

function ingredientOntologyV3CorpusAnnexIdentityEvidenceStatements(
    PDO $db
): array {
    return [
        'product' => $db->prepare("
            SELECT id AS source_id, owner_fingerprint, status,
                   entity_id, extension_entity_id, label_id,
                   resolver_version, review_manifest_hash,
                   evidence_hash, reason, admission_source,
                   source_label, normalized_label, language,
                   0 AS confidence, attributes_json
            FROM ingredient_ontology_identity_annex
            WHERE product_id = ?
              AND ontology_version_id = ?
              AND ontology_content_hash = ?
              AND ontology_seal_hash = ?
        "),
        'recipe' => $db->prepare("
            SELECT recipe_ingredient_id AS source_id,
                   owner_fingerprint, status,
                   entity_id, extension_entity_id, label_id,
                   resolver_version, review_manifest_hash,
                   evidence_hash, reason, admission_source,
                   source_label, normalized_label, language,
                   confidence, attributes_json
            FROM ingredient_ontology_recipe_identity_annex
            WHERE recipe_ingredient_id = ?
              AND ontology_version_id = ?
              AND ontology_content_hash = ?
              AND ontology_seal_hash = ?
        "),
        'mapping' => $db->prepare("
            SELECT mapping.id AS source_id,
                   mapping.owner_fingerprint, mapping.status,
                   mapping.entity_id, NULL AS extension_entity_id,
                   NULL AS label_id,
                   'sealed-ontology-v3' AS resolver_version,
                   ? AS review_manifest_hash,
                   '' AS evidence_hash,
                   COALESCE(disposition.disposition_code,
                            mapping.mapping_source) AS reason,
                   mapping.mapping_source AS admission_source,
                   mapping.source_label, mapping.normalized_label,
                   mapping.language, mapping.confidence,
                   mapping.attributes_json, mapping.evidence_json,
                   COALESCE(disposition.disposition_code, '')
                       AS disposition_code
            FROM ingredient_ontology_mappings mapping
            LEFT JOIN ingredient_ontology_terminal_dispositions
                disposition
              ON disposition.id = mapping.terminal_disposition_id
            WHERE mapping.ontology_version_id = ?
              AND mapping.owner_type = ?
              AND mapping.owner_id = ?
              AND mapping.owner_fingerprint = ?
        "),
        'extension' => $db->prepare("
            SELECT identity_key_hash, slug, canonical_name
            FROM ingredient_ontology_identity_extension_entities
            WHERE id = ?
              AND ontology_version_id = ?
              AND ontology_content_hash = ?
              AND ontology_seal_hash = ?
              AND created_revision <= ?
              AND status = 'active'
        "),
        'entity' => $db->prepare("
            SELECT slug
            FROM ingredient_ontology_entities
            WHERE id = ?
              AND ontology_version_id = ?
              AND active = 1
        "),
        'attributes' => $db->prepare("
            SELECT facet.facet_key, value.value_key,
                   attribute.is_defining, attribute.provenance
            FROM ingredient_ontology_mapping_attributes attribute
            JOIN ingredient_ontology_facets facet
              ON facet.id = attribute.facet_id
            JOIN ingredient_ontology_facet_values value
              ON value.id = attribute.facet_value_id
            WHERE attribute.mapping_id = ?
            ORDER BY facet.facet_key, value.value_key
        "),
        'relations' => $db->prepare("
            SELECT entity.slug AS to_entity_slug,
                   relation.relation, relation.direction,
                   relation.confidence, relation.provenance,
                   relation.review_state
            FROM ingredient_ontology_mapping_relations relation
            JOIN ingredient_ontology_entities entity
              ON entity.id = relation.to_entity_id
            WHERE relation.mapping_id = ?
            ORDER BY entity.slug, relation.relation,
                     relation.direction, relation.id
        "),
        'occurrence' => ingredientOntologyV3TableExists(
            $db,
            'ontology_subject_occurrences'
        ) ? $db->prepare("
            SELECT subject_id
            FROM ontology_subject_occurrences
            WHERE owner_type = ?
              AND owner_id = ?
              AND owner_fingerprint = ?
              AND active = 1
            ORDER BY id DESC
            LIMIT 1
        ") : null,
    ];
}

function ingredientOntologyV3CorpusAnnexIdentityEvidence(
    PDO $db,
    array $version,
    string $ownerType,
    int $ownerId,
    string $ownerFingerprint,
    int $identityExtensionRevision,
    ?array $statements = null
): array {
    $statements = $statements
        ?? ingredientOntologyV3CorpusAnnexIdentityEvidenceStatements(
            $db
        );
    $reviewHash =
        ingredientOntologyV3IdentityAnnexReviewManifestHash();
    $row = null;
    $sourceKind = '';
    if ($ownerType === 'product') {
        $stmt = $statements['product'];
        $stmt->execute([
            $ownerId,
            (int)$version['id'],
            (string)$version['content_hash'],
            (string)$version['seal_hash'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        $sourceKind = 'product_identity_annex';
    } elseif ($ownerType === 'recipe_ingredient') {
        $stmt = $statements['recipe'];
        $stmt->execute([
            $ownerId,
            (int)$version['id'],
            (string)$version['content_hash'],
            (string)$version['seal_hash'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        $sourceKind = 'recipe_identity_annex';
    }
    if (
        $row !== null
        && (
            !hash_equals(
                $ownerFingerprint,
                (string)$row['owner_fingerprint']
            )
            || (string)$row['resolver_version'] === ''
            || !hash_equals(
                $reviewHash,
                (string)$row['review_manifest_hash']
            )
        )
    ) {
        $row = null;
    }
    $mappingId = null;
    $dispositionCode = '';
    $rawEvidence = [];
    if ($row === null) {
        $mapping = $statements['mapping'];
        $mapping->execute([
            $reviewHash,
            (int)$version['id'],
            $ownerType,
            $ownerId,
            $ownerFingerprint,
        ]);
        $row = $mapping->fetch(PDO::FETCH_ASSOC) ?: null;
        $mapping->closeCursor();
        if ($row !== null) {
            $sourceKind = 'sealed_mapping';
            $mappingId = (int)$row['source_id'];
            $dispositionCode =
                (string)($row['disposition_code'] ?? '');
            $decoded = json_decode(
                (string)($row['evidence_json'] ?? '{}'),
                true
            );
            $rawEvidence = is_array($decoded) ? $decoded : [];
        }
    }
    if ($row === null) {
        $row = [
            'source_id' => null,
            'owner_fingerprint' => $ownerFingerprint,
            'status' => 'unresolved',
            'entity_id' => null,
            'extension_entity_id' => null,
            'label_id' => null,
            'resolver_version' =>
                INGREDIENT_ONTOLOGY_CORPUS_ANNEX_RESOLVER_VERSION,
            'review_manifest_hash' => $reviewHash,
            'evidence_hash' => '',
            'reason' => 'no_terminal_identity_evidence',
            'admission_source' => 'none',
            'source_label' => '',
            'normalized_label' => '',
            'language' => 'und',
            'confidence' => 0,
            'attributes_json' => '{}',
        ];
        $sourceKind = 'explicit_unresolved';
    }
    $status = (string)$row['status'];
    if (!in_array($status, ['accepted', 'unresolved', 'rejected'], true)) {
        $rawEvidence['sealed_nonterminal_status'] = $status;
        $status = 'unresolved';
    }
    $nativeSlug = null;
    $extensionKeyHash = null;
    $entityId = $row['entity_id'] !== null
        ? (int)$row['entity_id']
        : null;
    $extensionEntityId =
        $row['extension_entity_id'] !== null
            ? (int)$row['extension_entity_id']
            : null;
    if ($status === 'accepted' && $extensionEntityId !== null) {
        $extension = $statements['extension'];
        $extension->execute([
            $extensionEntityId,
            (int)$version['id'],
            (string)$version['content_hash'],
            (string)$version['seal_hash'],
            $identityExtensionRevision,
        ]);
        $extensionRow = $extension->fetch(PDO::FETCH_ASSOC);
        $extension->closeCursor();
        if (!$extensionRow) {
            $status = 'unresolved';
            $extensionEntityId = null;
            $row['reason'] = 'identity_extension_fence_missing';
        } else {
            $extensionKeyHash =
                (string)$extensionRow['identity_key_hash'];
        }
    } elseif ($status === 'accepted' && $entityId !== null) {
        $entity = $statements['entity'];
        $entity->execute([$entityId, (int)$version['id']]);
        $nativeSlug = $entity->fetchColumn();
        $entity->closeCursor();
        if ($nativeSlug === false) {
            $status = 'unresolved';
            $entityId = null;
            $row['reason'] = 'native_entity_fence_missing';
        } else {
            $nativeSlug = (string)$nativeSlug;
        }
    } elseif ($status === 'accepted') {
        $status = 'unresolved';
        $row['reason'] = 'accepted_identity_target_missing';
    }
    $attributes = json_decode(
        (string)($row['attributes_json'] ?? '{}'),
        true
    );
    $attributes = is_array($attributes) ? $attributes : [];
    if ($mappingId !== null) {
        $attributeStmt = $statements['attributes'];
        $attributeStmt->execute([$mappingId]);
        $attributes = [];
        while ($attribute = $attributeStmt->fetch(PDO::FETCH_ASSOC)) {
            $attributes[(string)$attribute['facet_key']] = [
                'value' => (string)$attribute['value_key'],
                'is_defining' =>
                    (int)$attribute['is_defining'],
                'provenance' =>
                    (string)$attribute['provenance'],
            ];
        }
        $attributeStmt->closeCursor();
    }
    ksort($attributes, SORT_STRING);
    $relations = [];
    if ($mappingId !== null) {
        $relationStmt = $statements['relations'];
        $relationStmt->execute([$mappingId]);
        while ($relation = $relationStmt->fetch(PDO::FETCH_ASSOC)) {
            $relations[] = [
                'to_entity_slug' =>
                    (string)$relation['to_entity_slug'],
                'relation' => (string)$relation['relation'],
                'direction' => (string)$relation['direction'],
                'confidence' =>
                    round((float)$relation['confidence'], 6),
                'provenance' =>
                    (string)$relation['provenance'],
                'review_state' =>
                    (string)$relation['review_state'],
            ];
        }
        $relationStmt->closeCursor();
    }
    $subjectId = null;
    $occurrence = $statements['occurrence'] ?? null;
    if ($occurrence instanceof PDOStatement) {
        $occurrence->execute([
            $ownerType,
            $ownerId,
            $ownerFingerprint,
        ]);
        $value = $occurrence->fetchColumn();
        $occurrence->closeCursor();
        $subjectId = $value !== false ? (int)$value : null;
    }
    $identity = [
        'source_kind' => $sourceKind,
        'source_id' => $row['source_id'] !== null
            ? (int)$row['source_id']
            : null,
        'mapping_id' => $mappingId,
        'subject_id' => $subjectId,
        'owner_fingerprint' => $ownerFingerprint,
        'status' => $status,
        'reason' => (string)$row['reason'],
        'admission_source' =>
            (string)($row['admission_source'] ?? 'none'),
        'source_label' => (string)($row['source_label'] ?? ''),
        'normalized_label' =>
            (string)($row['normalized_label'] ?? ''),
        'language' => (string)($row['language'] ?? 'und'),
        'confidence' => round((float)($row['confidence'] ?? 0), 6),
        'label_id' => $row['label_id'] !== null
            ? (int)$row['label_id']
            : null,
        'entity_id' => $entityId,
        'extension_entity_id' => $extensionEntityId,
        'native_entity_slug' => $nativeSlug,
        'identity_extension_key_hash' => $extensionKeyHash,
        'entity_key' => $nativeSlug !== null
            ? 'native:' . $nativeSlug
            : (
                $extensionKeyHash !== null
                    ? 'extension:' . $extensionKeyHash
                    : null
            ),
        'attributes' => $attributes,
        'relations' => $relations,
        'terminal_disposition' => $dispositionCode,
        'raw_evidence' => $rawEvidence,
        'resolver_version' =>
            (string)$row['resolver_version'],
        'review_manifest_hash' =>
            (string)$row['review_manifest_hash'],
    ];
    $identity['evidence_hash'] =
        strlen((string)$row['evidence_hash']) === 64
            ? (string)$row['evidence_hash']
            : ingredientOntologyV3Hash($identity);
    return [
        'identity_status' => $status,
        'identity_disposition' => mb_substr(
            trim((string)$row['reason']) ?: $status,
            0,
            80,
            'UTF-8'
        ),
        'satisfies_required' => $status === 'accepted' ? 1 : 0,
        'native_entity_slug' => $nativeSlug,
        'identity_extension_key_hash' => $extensionKeyHash,
        'entity_key' => $identity['entity_key'],
        'resolver_version' =>
            (string)$row['resolver_version'],
        'review_manifest_hash' =>
            (string)$row['review_manifest_hash'],
        'evidence_hash' => (string)$identity['evidence_hash'],
        'source_kind' => $sourceKind,
        'mapping_id' => $mappingId,
        'subject_id' => $subjectId,
        'attributes' => $attributes,
        'relations' => $relations,
        'identity' => $identity,
    ];
}

function ingredientOntologyV3CorpusAnnexEntry(
    int $ordinal,
    string $entryType,
    string $operation,
    string $ownerType,
    int $ownerId,
    ?int $recipeId,
    string $ownerFingerprint,
    array $identity,
    array $payload,
    string $aggregateSourceHash,
    string $resolutionInputHash,
    string $aggregateHash,
    int $memberCount
): array {
    $entry = [
        'ordinal' => $ordinal,
        'entry_type' => $entryType,
        'operation' => $operation,
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
        'recipe_id' => $recipeId,
        'owner_fingerprint' => $ownerFingerprint,
        'identity_status' =>
            (string)$identity['identity_status'],
        'identity_disposition' =>
            (string)$identity['identity_disposition'],
        'satisfies_required' =>
            (int)$identity['satisfies_required'],
        'native_entity_slug' =>
            $identity['native_entity_slug'] ?? null,
        'identity_extension_key_hash' =>
            $identity['identity_extension_key_hash'] ?? null,
        'resolver_version' =>
            (string)$identity['resolver_version'],
        'review_manifest_hash' =>
            (string)$identity['review_manifest_hash'],
        'evidence_hash' =>
            (string)$identity['evidence_hash'],
        'aggregate_source_hash' => $aggregateSourceHash,
        'resolution_input_hash' => $resolutionInputHash,
        'aggregate_hash' => $aggregateHash,
        'member_count' => $memberCount,
        'identity' => (array)($identity['identity'] ?? $identity),
        'identity_json' => ingredientOntologyV3Json(
            (array)($identity['identity'] ?? $identity)
        ),
        'payload' => $payload,
        'payload_json' => ingredientOntologyV3Json($payload),
    ];
    $entry['row_hash'] =
        ingredientOntologyV3CorpusAnnexEntryHash($entry);
    return $entry;
}

function ingredientOntologyV3CorpusAnnexAggregateSnapshot(
    PDO $db,
    array $version,
    string $aggregateType,
    int $aggregateId,
    int $identityExtensionRevision,
    bool $includeIdentity = true,
    ?array $identityStatements = null
): array {
    if (!in_array($aggregateType, ['product', 'recipe'], true)) {
        throw new InvalidArgumentException(
            'corpus projection aggregate type is invalid'
        );
    }
    $environmentHash =
        ingredientOntologyV3CorpusAnnexResolutionInputHash($version);
    $members = [];
    $resolutionDependencies = [];
    if ($aggregateType === 'product') {
        $source = ingredientOntologyV3CorpusAnnexProductPayload(
            $db,
            $aggregateId,
            (int)$version['id']
        );
        if ($source === null) {
            $operation = 'delete';
            $source = [
                'id' => $aggregateId,
                'presence' => 'absent',
            ];
        } else {
            $operation = 'replace';
            $fingerprint =
                ingredientOntologyV3ProductOwnerFingerprint($source);
            $members[] = [
                'entry_type' => 'product',
                'owner_type' => 'product',
                'owner_id' => $aggregateId,
                'recipe_id' => null,
                'owner_fingerprint' => $fingerprint,
                'identity' => $includeIdentity
                    ? ingredientOntologyV3CorpusAnnexIdentityEvidence(
                        $db,
                        $version,
                        'product',
                        $aggregateId,
                        $fingerprint,
                        $identityExtensionRevision,
                        $identityStatements
                    )
                    : ingredientOntologyV3CorpusAnnexScopeIdentity(
                        'product',
                        $fingerprint,
                        $source
                    ),
                'payload' => $source,
            ];
        }
    } else {
        $source = ingredientOntologyV3CorpusAnnexRowsForRecipe(
            $db,
            $aggregateId,
            (int)$version['id']
        );
        if (!$source) {
            $operation = 'delete';
            $source = [
                'id' => $aggregateId,
                'presence' => 'absent',
            ];
        } else {
            $operation = 'replace';
            $scope = (array)$source['scope'];
            if ($includeIdentity) {
                $resolutionDependencies =
                    ingredientOntologyV3CorpusAnnexRecipeIdentityDependencies(
                        $db,
                        (int)$version['id'],
                        $aggregateId,
                        $identityExtensionRevision
                    );
                if ($resolutionDependencies) {
                    $scope['identity_extension_dependencies'] =
                        $resolutionDependencies;
                }
            }
            $scopeFingerprint =
                ingredientOntologyV3CorpusAnnexScopeFingerprint(
                    'recipe_scope',
                    $scope
                );
            $members[] = [
                'entry_type' => 'recipe_scope',
                'owner_type' => 'recipe',
                'owner_id' => $aggregateId,
                'recipe_id' => $aggregateId,
                'owner_fingerprint' => $scopeFingerprint,
                'identity' =>
                    ingredientOntologyV3CorpusAnnexScopeIdentity(
                        'recipe_scope',
                        $scopeFingerprint,
                        $scope
                    ),
                'payload' => $scope,
            ];
            foreach ((array)$source['origins'] as $origin) {
                $fingerprint =
                    ingredientOntologyV3CorpusAnnexScopeFingerprint(
                        'recipe_origin',
                        $origin
                    );
                $members[] = [
                    'entry_type' => 'recipe_origin',
                    'owner_type' => 'recipe_origin',
                    'owner_id' => (int)$origin['id'],
                    'recipe_id' => $aggregateId,
                    'owner_fingerprint' => $fingerprint,
                    'identity' =>
                        ingredientOntologyV3CorpusAnnexScopeIdentity(
                            'recipe_origin',
                            $fingerprint,
                            $origin
                        ),
                    'payload' => $origin,
                ];
            }
            foreach ([
                'ranking' => [
                    'recipe_ingredient',
                    'recipe_ingredient',
                ],
                'source' => [
                    'recipe_source_ingredient',
                    'recipe_source_ingredient',
                ],
            ] as $key => [$entryType, $ownerType]) {
                foreach ((array)$source[$key] as $payload) {
                    $ownerId = (int)$payload['id'];
                    $fingerprint =
                        ingredientOntologyV3RecipeOwnerFingerprint(
                            $ownerType,
                            $payload
                        );
                    $members[] = [
                        'entry_type' => $entryType,
                        'owner_type' => $ownerType,
                        'owner_id' => $ownerId,
                        'recipe_id' => $aggregateId,
                        'owner_fingerprint' => $fingerprint,
                        'identity' => $includeIdentity
                            ? ingredientOntologyV3CorpusAnnexIdentityEvidence(
                                $db,
                                $version,
                                $ownerType,
                                $ownerId,
                                $fingerprint,
                                $identityExtensionRevision,
                                $identityStatements
                            )
                            : ingredientOntologyV3CorpusAnnexScopeIdentity(
                                $entryType,
                                $fingerprint,
                                $payload
                            ),
                        'payload' => $payload,
                    ];
                }
            }
        }
    }
    $sourceHash = ingredientOntologyV3Hash([
        'algorithm' =>
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                . ':aggregate-source',
        'aggregate_type' => $aggregateType,
        'aggregate_id' => $aggregateId,
        'operation' => $operation,
        'source' => $source,
    ]);
    $resolutionInputHash = ingredientOntologyV3Hash([
        'algorithm' =>
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                . ':aggregate-resolution-input',
        'environment_hash' => $environmentHash,
        'aggregate_source_hash' => $sourceHash,
        'identity_extension_dependencies' =>
            $resolutionDependencies,
    ]);
    $memberEvidence = [];
    foreach ($members as $member) {
        $memberEvidence[] = [
            'entry_type' => (string)$member['entry_type'],
            'owner_type' => (string)$member['owner_type'],
            'owner_id' => (int)$member['owner_id'],
            'owner_fingerprint' =>
                (string)$member['owner_fingerprint'],
            'identity' => (array)$member['identity'],
            'payload' => (array)$member['payload'],
        ];
    }
    $aggregateHash = ingredientOntologyV3Hash([
        'algorithm' =>
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION . ':aggregate',
        'aggregate_type' => $aggregateType,
        'aggregate_id' => $aggregateId,
        'operation' => $operation,
        'source_hash' => $sourceHash,
        'resolution_input_hash' => $resolutionInputHash,
        'members' => $memberEvidence,
    ]);
    if ($operation === 'delete') {
        $fingerprint =
            ingredientOntologyV3CorpusAnnexScopeFingerprint(
                $aggregateType,
                $source
            );
        $members = [[
            'entry_type' => $aggregateType === 'product'
                ? 'product'
                : 'recipe_scope',
            'owner_type' => $aggregateType,
            'owner_id' => $aggregateId,
            'recipe_id' => $aggregateType === 'recipe'
                ? $aggregateId
                : null,
            'owner_fingerprint' => $fingerprint,
            'identity' =>
                ingredientOntologyV3CorpusAnnexScopeIdentity(
                    $aggregateType . '_delete',
                    $fingerprint,
                    $source
                ),
            'payload' => $source,
        ]];
    }
    return [
        'aggregate_type' => $aggregateType,
        'aggregate_id' => $aggregateId,
        'operation' => $operation,
        'aggregate_source_hash' => $sourceHash,
        'resolution_input_hash' => $resolutionInputHash,
        'aggregate_hash' => $aggregateHash,
        'member_count' => $operation !== 'delete'
            ? count($members)
            : 0,
        'source' => $source,
        'members' => $members,
    ];
}

function ingredientOntologyV3CorpusAnnexAggregateEntries(
    array $aggregate,
    int &$ordinal
): array {
    $entries = [];
    foreach ((array)$aggregate['members'] as $member) {
        $entries[] = ingredientOntologyV3CorpusAnnexEntry(
            ++$ordinal,
            (string)$member['entry_type'],
            (string)$aggregate['operation'],
            (string)$member['owner_type'],
            (int)$member['owner_id'],
            $member['recipe_id'] !== null
                ? (int)$member['recipe_id']
                : null,
            (string)$member['owner_fingerprint'],
            (array)$member['identity'],
            (array)$member['payload'],
            (string)$aggregate['aggregate_source_hash'],
            (string)$aggregate['resolution_input_hash'],
            (string)$aggregate['aggregate_hash'],
            (int)$aggregate['member_count']
        );
    }
    return $entries;
}

function ingredientOntologyV3CorpusAnnexAggregateKey(
    string $aggregateType,
    int $aggregateId
): string {
    return $aggregateType . ':' . $aggregateId;
}

function ingredientOntologyV3CorpusAnnexAggregateKeyCompare(
    string $left,
    string $right
): int {
    [$leftType, $leftId] = array_pad(
        explode(':', $left, 2),
        2,
        '0'
    );
    [$rightType, $rightId] = array_pad(
        explode(':', $right, 2),
        2,
        '0'
    );
    $typeOrder = ['product' => 0, 'recipe' => 1];
    $typeComparison =
        ($typeOrder[$leftType] ?? 99)
        <=> ($typeOrder[$rightType] ?? 99);
    return $typeComparison !== 0
        ? $typeComparison
        : ((int)$leftId <=> (int)$rightId);
}

function ingredientOntologyV3CorpusAnnexContinuation(
    array $pin,
    int $fromRevision,
    int $currentRevision
): ?array {
    $manifest = json_decode(
        (string)($pin['mutation_manifest_json'] ?? ''),
        true
    );
    $continuation = is_array($manifest)
        ? ($manifest['continuation'] ?? null)
        : null;
    if (!is_array($continuation)) {
        return null;
    }
    $target = (int)($continuation['target_revision'] ?? 0);
    $after = (string)(
        $continuation['after_aggregate_key'] ?? ''
    );
    if (
        $target <= $fromRevision
        || $target > $currentRevision
        || !preg_match(
            '/^(product|recipe):[1-9][0-9]*$/D',
            $after
        )
    ) {
        return null;
    }
    return [
        'target_revision' => $target,
        'after_aggregate_key' => $after,
        'mode' => (string)($manifest['mode'] ?? 'authoritative'),
    ];
}

function ingredientOntologyV3CorpusAnnexCheckpointSource(
    array $revision
): ?array {
    if (
        $revision['parent_revision_id'] !== null
        || (string)($revision['reconciliation_mode'] ?? '')
            !== 'checkpoint'
    ) {
        return null;
    }
    $manifest = json_decode(
        (string)($revision['mutation_manifest_json'] ?? ''),
        true
    );
    $source = is_array($manifest)
        ? ($manifest['checkpoint_source'] ?? null)
        : null;
    if (
        !is_array($source)
        || (int)($source['revision_id'] ?? 0) <= 0
        || strlen((string)($source['revision_hash'] ?? '')) !== 64
    ) {
        return null;
    }
    return [
        'revision_id' => (int)$source['revision_id'],
        'revision_hash' => (string)$source['revision_hash'],
    ];
}

function ingredientOntologyV3CorpusAnnexMaterializationChain(
    PDO $db,
    int $revisionId,
    array &$seen = []
): array {
    $segments = [];
    $currentRevisionId = $revisionId;
    while (true) {
        $chain = ingredientOntologyV3CorpusAnnexChain(
            $db,
            $currentRevisionId
        );
        foreach ($chain as $revision) {
            $chainRevisionId = (int)$revision['id'];
            if (isset($seen[$chainRevisionId])) {
                throw new RuntimeException(
                    'corpus projection checkpoint references are invalid'
                );
            }
            $seen[$chainRevisionId] = true;
        }
        $root = $chain[0] ?? null;
        if (!is_array($root)) {
            throw new RuntimeException(
                'corpus projection materialization root is unavailable'
            );
        }
        $segments[] = $chain;
        $source =
            ingredientOntologyV3CorpusAnnexCheckpointSource($root);
        if ($source === null) {
            break;
        }
        $sourceRevision = ingredientOntologyV3CorpusAnnexRevision(
            $db,
            (int)$source['revision_id']
        );
        if (
            $sourceRevision === null
            || (string)$sourceRevision['status'] !== 'ready'
            || !hash_equals(
                (string)$sourceRevision['revision_hash'],
                (string)$source['revision_hash']
            )
        ) {
            throw new RuntimeException(
                'corpus projection checkpoint source is unavailable'
            );
        }
        $currentRevisionId = (int)$sourceRevision['id'];
    }
    $materialization = [];
    foreach (array_reverse($segments) as $segment) {
        array_push($materialization, ...$segment);
    }
    return $materialization;
}

function ingredientOntologyV3CorpusAnnexProjectionReady(
    PDO $db,
    array $revision
): bool {
    $stmt = $db->prepare("
        SELECT materialized_revision_id,
               materialized_revision_hash,
               projection_root_hash, cache_schema_version
        FROM ingredient_ontology_corpus_annex_projection_state
        WHERE ontology_version_id = ?
    ");
    $stmt->execute([(int)$revision['ontology_version_id']]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC);
    return $state
        && (int)$state['materialized_revision_id']
            === (int)$revision['id']
        && hash_equals(
            (string)$state['materialized_revision_hash'],
            (string)$revision['revision_hash']
        )
        && hash_equals(
            (string)$state['projection_root_hash'],
            (string)$revision['projection_root_hash']
        )
        && (int)$state['cache_schema_version']
            === INGREDIENT_ONTOLOGY_CORPUS_ANNEX_CACHE_SCHEMA_VERSION;
}

function ingredientOntologyV3CorpusAnnexIndexesRelation(
    string $relation
): bool {
    return in_array(
        $relation,
        [
            'equivalent_to',
            'variant_of',
            'substitutes_for',
        ],
        true
    );
}

function ingredientOntologyV3CorpusAnnexApplyRevisionEntries(
    PDO $db,
    array $revision,
    bool $verifyEntrySet = true
): array {
    if (
        (string)($revision['status'] ?? '') !== 'ready'
        || !hash_equals(
            ingredientOntologyV3CorpusAnnexRevisionHash($revision),
            (string)($revision['revision_hash'] ?? '')
        )
        || (
            $verifyEntrySet
            && !hash_equals(
                ingredientOntologyV3CorpusAnnexStoredEntrySetHash(
                    $db,
                    (int)($revision['id'] ?? 0)
                ),
                (string)($revision['entry_set_hash'] ?? '')
            )
        )
    ) {
        throw new RuntimeException(
            'corpus projection materialization requires a valid ready revision'
        );
    }
    $readyGuardWasEnabled =
        ingredientOntologyV3ReadyMutationGuardEnabled($db);
    ingredientOntologyV3SetReadyMutationGuard($db, true);
    $aggregateDelta = 0;
    $memberDelta = 0;
    try {
    $currentHead = $db->prepare("
        SELECT member_count
        FROM ingredient_ontology_corpus_annex_effective_aggregates
        WHERE ontology_version_id = ?
          AND aggregate_type = ?
          AND aggregate_id = ?
    ");
    $deleteMembers = $db->prepare("
        DELETE FROM ingredient_ontology_corpus_annex_effective_members
        WHERE ontology_version_id = ?
          AND aggregate_type = ?
          AND aggregate_id = ?
    ");
    $deleteEntities = $db->prepare("
        DELETE FROM ingredient_ontology_corpus_annex_effective_entities
        WHERE ontology_version_id = ?
          AND aggregate_type = ?
          AND aggregate_id = ?
    ");
    $upsertHead = $db->prepare("
        INSERT INTO ingredient_ontology_corpus_annex_effective_aggregates (
            ontology_version_id, aggregate_type, aggregate_id,
            operation, source_hash, resolution_input_hash,
            aggregate_hash, member_count, head_revision_id,
            head_revision_hash, head_entry_id, payload_json,
            updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(
            ontology_version_id, aggregate_type, aggregate_id
        ) DO UPDATE SET
            operation = excluded.operation,
            source_hash = excluded.source_hash,
            resolution_input_hash = excluded.resolution_input_hash,
            aggregate_hash = excluded.aggregate_hash,
            member_count = excluded.member_count,
            head_revision_id = excluded.head_revision_id,
            head_revision_hash = excluded.head_revision_hash,
            head_entry_id = excluded.head_entry_id,
            payload_json = excluded.payload_json,
            updated_at = CURRENT_TIMESTAMP
    ");
    $insertMember = $db->prepare("
        INSERT INTO ingredient_ontology_corpus_annex_effective_members (
            ontology_version_id, aggregate_type, aggregate_id,
            entry_type, owner_type, owner_id, recipe_id,
            owner_fingerprint, identity_status,
            satisfies_required, entity_key, native_entity_slug,
            identity_extension_key_hash, attributes_json,
            relations_json, evidence_hash,
            normalized_source_label, member_hash,
            head_revision_id, head_entry_id, payload_json,
            updated_at
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, CURRENT_TIMESTAMP
        )
    ");
    $insertEntity = $db->prepare("
        INSERT OR IGNORE INTO
            ingredient_ontology_corpus_annex_effective_entities (
            ontology_version_id, entity_key,
            aggregate_type, aggregate_id,
            owner_type, owner_id, head_revision_id
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $flush = static function (?array $group) use (
        $revision,
        $currentHead,
        $deleteMembers,
        $deleteEntities,
        $upsertHead,
        $insertMember,
        $insertEntity,
        &$aggregateDelta,
        &$memberDelta
    ): void {
        if ($group === null) {
            return;
        }
        $head = $group['head'] ?? null;
        if (!is_array($head)) {
            throw new RuntimeException(
                'corpus projection aggregate header is missing'
            );
        }
        $aggregateType = (string)$group['type'];
        $aggregateId = (int)$group['id'];
        $versionId = (int)$revision['ontology_version_id'];
        $currentHead->execute([
            $versionId,
            $aggregateType,
            $aggregateId,
        ]);
        $previousMemberCount = $currentHead->fetchColumn();
        $currentHead->closeCursor();
        if ($previousMemberCount === false) {
            $aggregateDelta++;
            $previousMemberCount = 0;
        }
        $memberDelta +=
            (int)$head['member_count'] - (int)$previousMemberCount;
        $deleteEntities->execute([
            $versionId,
            $aggregateType,
            $aggregateId,
        ]);
        $deleteMembers->execute([
            $versionId,
            $aggregateType,
            $aggregateId,
        ]);
        $upsertHead->execute([
            $versionId,
            $aggregateType,
            $aggregateId,
            (string)$head['operation'],
            (string)$head['aggregate_source_hash'],
            (string)$head['resolution_input_hash'],
            (string)$head['aggregate_hash'],
            (int)$head['member_count'],
            (int)$revision['id'],
            (string)$revision['revision_hash'],
            (int)$head['id'],
            (string)$head['payload_json'],
        ]);
        if ((string)$head['operation'] === 'delete') {
            return;
        }
        foreach ((array)$group['entries'] as $entry) {
            $identity = json_decode(
                (string)$entry['identity_json'],
                true
            );
            $identity = is_array($identity) ? $identity : [];
            $attributes = (array)($identity['attributes'] ?? []);
            $relations = (array)($identity['relations'] ?? []);
            $entityKey = $identity['entity_key'] ?? null;
            $payload = json_decode(
                (string)$entry['payload_json'],
                true
            );
            $payload = is_array($payload) ? $payload : [];
            $sourceLabel = (string)(
                $payload['source_label']
                    ?? $payload['name']
                    ?? ''
            );
            $insertMember->execute([
                $versionId,
                $aggregateType,
                $aggregateId,
                (string)$entry['entry_type'],
                (string)$entry['owner_type'],
                (int)$entry['owner_id'],
                $entry['recipe_id'] !== null
                    ? (int)$entry['recipe_id']
                    : null,
                (string)$entry['owner_fingerprint'],
                (string)$entry['identity_status'],
                (int)$entry['satisfies_required'],
                $entityKey,
                $entry['native_entity_slug'],
                $entry['identity_extension_key_hash'],
                ingredientOntologyV3Json($attributes),
                ingredientOntologyV3Json($relations),
                (string)$entry['evidence_hash'],
                mb_substr(
                    ingredientOntologyV3NormalizeLabel($sourceLabel),
                    0,
                    320,
                    'UTF-8'
                ),
                (string)$entry['row_hash'],
                (int)$revision['id'],
                (int)$entry['id'],
                (string)$entry['payload_json'],
            ]);
            if (is_string($entityKey) && $entityKey !== '') {
                $insertEntity->execute([
                    $versionId,
                    $entityKey,
                    $aggregateType,
                    $aggregateId,
                    (string)$entry['owner_type'],
                    (int)$entry['owner_id'],
                    (int)$revision['id'],
                ]);
            }
            foreach ($relations as $relation) {
                if (!ingredientOntologyV3CorpusAnnexIndexesRelation(
                    (string)($relation['relation'] ?? '')
                )) {
                    continue;
                }
                $slug = trim((string)(
                    $relation['to_entity_slug'] ?? ''
                ));
                if ($slug === '') {
                    continue;
                }
                $insertEntity->execute([
                    $versionId,
                    'native:' . $slug,
                    $aggregateType,
                    $aggregateId,
                    (string)$entry['owner_type'],
                    (int)$entry['owner_id'],
                    (int)$revision['id'],
                ]);
            }
        }
    };
    $group = null;
    $groupKey = null;
    foreach (
        ingredientOntologyV3CorpusAnnexEntryRows(
            $db,
            (int)$revision['id']
        ) as $entry
    ) {
        $aggregateType =
            (string)$entry['entry_type'] === 'product'
                ? 'product'
                : 'recipe';
        $aggregateId = $aggregateType === 'product'
            ? (int)$entry['owner_id']
            : (int)$entry['recipe_id'];
        $key = ingredientOntologyV3CorpusAnnexAggregateKey(
            $aggregateType,
            $aggregateId
        );
        if ($groupKey !== null && $key !== $groupKey) {
            $flush($group);
            $group = null;
        }
        if ($group === null) {
            $groupKey = $key;
            $group = [
                'type' => $aggregateType,
                'id' => $aggregateId,
                'entries' => [],
            ];
        }
        $group['entries'][] = $entry;
        if (
            (string)$entry['entry_type'] === 'product'
            || (string)$entry['entry_type'] === 'recipe_scope'
        ) {
            $group['head'] = $entry;
        }
    }
    $flush($group);
    } finally {
        ingredientOntologyV3SetReadyMutationGuard(
            $db,
            $readyGuardWasEnabled
        );
    }
    return [
        'aggregate_delta' => $aggregateDelta,
        'member_delta' => $memberDelta,
    ];
}

function ingredientOntologyV3CorpusAnnexProjectionCounts(
    PDO $db,
    int $versionId
): array {
    ingredientOntologyV3TrackCorpusOperation(
        'effective_projection_counts',
        true
    );
    $aggregate = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_corpus_annex_effective_aggregates
        WHERE ontology_version_id = ?
    ");
    $aggregate->execute([$versionId]);
    $member = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_corpus_annex_effective_members
        WHERE ontology_version_id = ?
    ");
    $member->execute([$versionId]);
    return [
        'aggregate_count' => (int)$aggregate->fetchColumn(),
        'member_count' => (int)$member->fetchColumn(),
    ];
}

function ingredientOntologyV3CorpusAnnexProjectionCountsAfterDelta(
    PDO $db,
    int $versionId,
    array $delta
): ?array {
    $stmt = $db->prepare("
        SELECT aggregate_count, member_count
        FROM ingredient_ontology_corpus_annex_projection_state
        WHERE ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        return null;
    }
    $aggregateCount =
        (int)$current['aggregate_count']
        + (int)($delta['aggregate_delta'] ?? 0);
    $memberCount =
        (int)$current['member_count']
        + (int)($delta['member_delta'] ?? 0);
    if ($aggregateCount < 0 || $memberCount < 0) {
        throw new RuntimeException(
            'corpus projection count delta is invalid'
        );
    }
    return [
        'aggregate_count' => $aggregateCount,
        'member_count' => $memberCount,
    ];
}

function ingredientOntologyV3CorpusAnnexEffectiveProjectionHash(
    PDO $db,
    int $versionId
): string {
    ingredientOntologyV3TrackCorpusOperation(
        'effective_projection_hash',
        true
    );
    $hash = hash_init('sha256');
    hash_update(
        $hash,
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
            . ":effective-projection\n"
    );
    foreach ([
        'aggregates' => [
            'sql' => "
                SELECT ontology_version_id, aggregate_type,
                       aggregate_id, operation, source_hash,
                       resolution_input_hash, aggregate_hash,
                       member_count, head_revision_id,
                       head_revision_hash, head_entry_id, payload_json
                FROM ingredient_ontology_corpus_annex_effective_aggregates
                WHERE ontology_version_id = ?
                ORDER BY aggregate_type, aggregate_id
            ",
        ],
        'members' => [
            'sql' => "
                SELECT ontology_version_id, aggregate_type,
                       aggregate_id, entry_type, owner_type, owner_id,
                       recipe_id, owner_fingerprint, identity_status,
                       satisfies_required, entity_key,
                       native_entity_slug,
                       identity_extension_key_hash, attributes_json,
                       relations_json, evidence_hash,
                       normalized_source_label, member_hash,
                       head_revision_id, head_entry_id, payload_json
                FROM ingredient_ontology_corpus_annex_effective_members
                WHERE ontology_version_id = ?
                ORDER BY aggregate_type, aggregate_id,
                         entry_type, owner_id
            ",
        ],
        'entities' => [
            'sql' => "
                SELECT ontology_version_id, entity_key,
                       aggregate_type, aggregate_id,
                       owner_type, owner_id, head_revision_id
                FROM ingredient_ontology_corpus_annex_effective_entities
                WHERE ontology_version_id = ?
                ORDER BY entity_key, aggregate_type, aggregate_id,
                         owner_type, owner_id
            ",
        ],
        'state' => [
            'sql' => "
                SELECT ontology_version_id, materialized_revision_id,
                       materialized_revision_hash,
                       projection_root_hash,
                       aggregate_count, member_count,
                       cache_schema_version
                FROM ingredient_ontology_corpus_annex_projection_state
                WHERE ontology_version_id = ?
                ORDER BY ontology_version_id
            ",
        ],
    ] as $name => $definition) {
        hash_update($hash, $name . "\n");
        $stmt = $db->prepare((string)$definition['sql']);
        $stmt->execute([$versionId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            hash_update(
                $hash,
                ingredientOntologyV3Json($row) . "\n"
            );
        }
    }
    return hash_final($hash);
}

function ingredientOntologyV3CorpusAnnexEffectiveContentHash(
    PDO $db,
    int $versionId
): string {
    ingredientOntologyV3TrackCorpusOperation(
        'effective_content_hash',
        true
    );
    $hash = hash_init('sha256');
    hash_update(
        $hash,
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
            . ":effective-content\n"
    );
    foreach ([
        'aggregates' => "
            SELECT aggregate_type, aggregate_id, operation,
                   source_hash, resolution_input_hash,
                   aggregate_hash, member_count, payload_json
            FROM ingredient_ontology_corpus_annex_effective_aggregates
            WHERE ontology_version_id = ?
            ORDER BY aggregate_type, aggregate_id
        ",
        'members' => "
            SELECT aggregate_type, aggregate_id, entry_type,
                   owner_type, owner_id, recipe_id,
                   owner_fingerprint, identity_status,
                   satisfies_required, entity_key,
                   native_entity_slug,
                   identity_extension_key_hash, attributes_json,
                   relations_json, evidence_hash,
                   normalized_source_label,
                   payload_json
            FROM ingredient_ontology_corpus_annex_effective_members
            WHERE ontology_version_id = ?
            ORDER BY aggregate_type, aggregate_id,
                     entry_type, owner_id
        ",
        'entities' => "
            SELECT entity_key, aggregate_type, aggregate_id,
                   owner_type, owner_id
            FROM ingredient_ontology_corpus_annex_effective_entities
            WHERE ontology_version_id = ?
            ORDER BY entity_key, aggregate_type, aggregate_id,
                     owner_type, owner_id
        ",
    ] as $name => $sql) {
        hash_update($hash, $name . "\n");
        $stmt = $db->prepare($sql);
        $stmt->execute([$versionId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            hash_update(
                $hash,
                ingredientOntologyV3Json($row) . "\n"
            );
        }
    }
    return hash_final($hash);
}

function ingredientOntologyV3CorpusAnnexSetProjectionState(
    PDO $db,
    array $revision,
    ?array $counts = null
): void {
    if (
        (string)($revision['status'] ?? '') !== 'ready'
        || !hash_equals(
            ingredientOntologyV3CorpusAnnexRevisionHash($revision),
            (string)($revision['revision_hash'] ?? '')
        )
    ) {
        throw new RuntimeException(
            'corpus projection state requires a valid ready revision'
        );
    }
    $readyGuardWasEnabled =
        ingredientOntologyV3ReadyMutationGuardEnabled($db);
    ingredientOntologyV3SetReadyMutationGuard($db, true);
    try {
    $counts = $counts
        ?? ingredientOntologyV3CorpusAnnexProjectionCounts(
            $db,
            (int)$revision['ontology_version_id']
        );
    $db->prepare("
        INSERT INTO ingredient_ontology_corpus_annex_projection_state (
            ontology_version_id, materialized_revision_id,
            materialized_revision_hash, projection_root_hash,
            aggregate_count, member_count, cache_schema_version,
            updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(ontology_version_id) DO UPDATE SET
            materialized_revision_id =
                excluded.materialized_revision_id,
            materialized_revision_hash =
                excluded.materialized_revision_hash,
            projection_root_hash = excluded.projection_root_hash,
            aggregate_count = excluded.aggregate_count,
            member_count = excluded.member_count,
            cache_schema_version = excluded.cache_schema_version,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([
        (int)$revision['ontology_version_id'],
        (int)$revision['id'],
        (string)$revision['revision_hash'],
        (string)$revision['projection_root_hash'],
        (int)$counts['aggregate_count'],
        (int)$counts['member_count'],
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_CACHE_SCHEMA_VERSION,
    ]);
    } finally {
        ingredientOntologyV3SetReadyMutationGuard(
            $db,
            $readyGuardWasEnabled
        );
    }
}

function ingredientOntologyV3CorpusAnnexRebuildEffectiveProjection(
    PDO $db,
    array $revision
): array {
    ingredientOntologyV3TrackCorpusOperation(
        'effective_projection_rebuild',
        true
    );
    $readyGuardWasEnabled =
        ingredientOntologyV3ReadyMutationGuardEnabled($db);
    ingredientOntologyV3SetReadyMutationGuard($db, true);
    try {
    $seen = [];
    $chain = ingredientOntologyV3CorpusAnnexMaterializationChain(
        $db,
        (int)$revision['id'],
        $seen
    );
    $versionId = (int)$revision['ontology_version_id'];
    $db->prepare("
        DELETE FROM ingredient_ontology_corpus_annex_effective_entities
        WHERE ontology_version_id = ?
    ")->execute([$versionId]);
    $db->prepare("
        DELETE FROM ingredient_ontology_corpus_annex_effective_members
        WHERE ontology_version_id = ?
    ")->execute([$versionId]);
    $db->prepare("
        DELETE FROM ingredient_ontology_corpus_annex_effective_aggregates
        WHERE ontology_version_id = ?
    ")->execute([$versionId]);
    $counts = [
        'aggregate_count' => 0,
        'member_count' => 0,
    ];
    foreach ($chain as $item) {
        $delta = ingredientOntologyV3CorpusAnnexApplyRevisionEntries(
            $db,
            $item,
            false
        );
        $counts['aggregate_count'] +=
            (int)$delta['aggregate_delta'];
        $counts['member_count'] +=
            (int)$delta['member_delta'];
    }
    ingredientOntologyV3CorpusAnnexSetProjectionState(
        $db,
        $revision,
        $counts
    );
    return $counts;
    } finally {
        ingredientOntologyV3SetReadyMutationGuard(
            $db,
            $readyGuardWasEnabled
        );
    }
}

function ingredientOntologyV3CorpusAnnexEnsureProjection(
    PDO $db,
    array $revision
): void {
    if (ingredientOntologyV3CorpusAnnexProjectionReady(
        $db,
        $revision
    )) {
        return;
    }
    $ownsTransaction = !databaseTransactionIsActive($db);
    if ($ownsTransaction) {
        dbBeginImmediateWithRetry($db);
    }
    try {
        $audit = ingredientOntologyV3CorpusProjectionIntegrityAuditV2(
            $db,
            (int)$revision['id'],
            (string)$revision['revision_hash'],
            false
        );
        if (empty($audit['valid'])) {
            throw new RuntimeException(
                'corpus projection repair refused corrupted evidence: '
                    . implode('; ', (array)$audit['errors'])
            );
        }
        ingredientOntologyV3CorpusAnnexRebuildEffectiveProjection(
            $db,
            $revision
        );
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
    } catch (Throwable $error) {
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $error;
    }
}

function ingredientOntologyV3CorpusAnnexEffectiveHead(
    PDO $db,
    int $versionId,
    string $aggregateType,
    int $aggregateId
): ?array {
    $stmt = $db->prepare("
        SELECT *
        FROM ingredient_ontology_corpus_annex_effective_aggregates
        WHERE ontology_version_id = ?
          AND aggregate_type = ?
          AND aggregate_id = ?
    ");
    $stmt->execute([$versionId, $aggregateType, $aggregateId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ingredientOntologyV3CorpusAnnexAggregateChanged(
    PDO $db,
    int $versionId,
    array $aggregate,
    bool $compareResolvedEvidence = true
): bool {
    $head = ingredientOntologyV3CorpusAnnexEffectiveHead(
        $db,
        $versionId,
        (string)$aggregate['aggregate_type'],
        (int)$aggregate['aggregate_id']
    );
    return $head === null
        || (string)$head['operation']
            !== (string)$aggregate['operation']
        || !hash_equals(
            (string)$head['source_hash'],
            (string)$aggregate['aggregate_source_hash']
        )
        || (
            $compareResolvedEvidence
            && (
                !hash_equals(
                    (string)$head['resolution_input_hash'],
                    (string)$aggregate['resolution_input_hash']
                )
                || !hash_equals(
                    (string)$head['aggregate_hash'],
                    (string)$aggregate['aggregate_hash']
                )
            )
        );
}

function ingredientOntologyV3CorpusAnnexLoadEvents(
    PDO $db,
    array $eventRows
): array {
    if (!$eventRows) {
        return [];
    }
    $events = [];
    $eventIds = [];
    foreach ($eventRows as $row) {
        $eventId = (int)$row['id'];
        $eventIds[] = $eventId;
        $events[$eventId] = [
            'id' => $eventId,
            'revision' => (int)$row['revision'],
            'lane' => (string)$row['lane'],
            'owner_type' => (string)$row['owner_type'],
            'owner_id' => $row['owner_id'] !== null
                ? (int)$row['owner_id']
                : null,
            'operation' => (string)$row['operation'],
            'source_table' => (string)$row['source_table'],
            'source_row_id' => $row['source_row_id'] !== null
                ? (int)$row['source_row_id']
                : null,
            'reason' => (string)$row['reason'],
            'scopes' => [],
        ];
    }
    $placeholders = implode(
        ',',
        array_fill(0, count($eventIds), '?')
    );
    $scope = $db->prepare("
        SELECT mutation_id, aggregate_type, aggregate_id, scope_role,
               source_table, source_row_id, source_key,
               metadata_json
        FROM recipe_score_mutation_scopes
        WHERE mutation_id IN ({$placeholders})
        ORDER BY mutation_id, ordinal
    ");
    $scope->execute($eventIds);
    while ($row = $scope->fetch(PDO::FETCH_ASSOC)) {
        $eventId = (int)$row['mutation_id'];
        if (!isset($events[$eventId])) {
            continue;
        }
        $events[$eventId]['scopes'][] = [
            'aggregate_type' => (string)$row['aggregate_type'],
            'aggregate_id' => $row['aggregate_id'] !== null
                ? (int)$row['aggregate_id']
                : null,
            'scope_role' => (string)$row['scope_role'],
            'source_table' => (string)$row['source_table'],
            'source_row_id' => $row['source_row_id'] !== null
                ? (int)$row['source_row_id']
                : null,
            'source_key' => (string)$row['source_key'],
            'metadata_json' => (string)$row['metadata_json'],
        ];
    }
    return array_values($events);
}

function ingredientOntologyV3CorpusAnnexJournalWindow(
    PDO $db,
    int $from,
    int $through,
    int $maximumEvents,
    int $maximumScopeRows =
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_SCOPE_ROWS
): array {
    if ($through <= $from) {
        return [
            'dense' => true,
            'complete' => true,
            'has_more' => false,
            'oversized' => false,
            'event_count' => 0,
            'scope_row_count' => 0,
            'through_revision' => $from,
            'events' => [],
        ];
    }
    $maximumEvents = max(1, $maximumEvents);
    $maximumScopeRows = max(1, $maximumScopeRows);
    $rowLimit = $maximumEvents + 1;
    $stmt = $db->prepare("
        SELECT mutation.id, mutation.revision, mutation.lane,
               mutation.owner_type, mutation.owner_id,
               mutation.operation, mutation.source_table,
               mutation.source_row_id, mutation.reason,
               (
                   SELECT COUNT(*)
                   FROM recipe_score_mutation_scopes scope
                   WHERE scope.mutation_id = mutation.id
               ) AS scope_count
        FROM recipe_score_mutations mutation
        WHERE mutation.domain = 'source'
          AND mutation.revision > ?
          AND mutation.revision <= ?
        ORDER BY mutation.revision
        LIMIT {$rowLimit}
    ");
    $stmt->execute([$from, $through]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $selected = [];
    $expectedRevision = $from + 1;
    $scopeRows = 0;
    $oversized = false;
    foreach ($rows as $row) {
        if ((int)$row['revision'] !== $expectedRevision) {
            break;
        }
        $eventScopeRows = (int)$row['scope_count'];
        if ($eventScopeRows > $maximumScopeRows) {
            $oversized = !$selected;
            break;
        }
        if (
            count($selected) >= $maximumEvents
            || $scopeRows + $eventScopeRows > $maximumScopeRows
        ) {
            break;
        }
        $selected[] = $row;
        $scopeRows += $eventScopeRows;
        $expectedRevision++;
    }
    $pageThrough = $selected
        ? (int)$selected[array_key_last($selected)]['revision']
        : $from;
    $dense = $pageThrough > $from;
    $complete = $dense && $pageThrough === $through;
    return [
        'dense' => $dense,
        'complete' => $complete,
        'has_more' => $pageThrough < $through,
        'oversized' => $oversized,
        'event_count' => count($selected),
        'scope_row_count' => $scopeRows,
        'through_revision' => $pageThrough,
        'events' => $dense
            ? ingredientOntologyV3CorpusAnnexLoadEvents(
                $db,
                $selected
            )
            : [],
    ];
}

function ingredientOntologyV3CorpusAnnexDurableScopeWindow(
    PDO $db,
    int $from,
    int $through,
    int $maximumEvents =
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS,
    int $maximumScopeRows =
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_SCOPE_ROWS
): array {
    if (
        $through <= $from
        || !ingredientOntologyV3TableExists(
            $db,
            'recipe_score_source_reconciliation_events'
        )
    ) {
        return [
            'available' => $through <= $from,
            'complete' => $through <= $from,
            'has_more' => false,
            'oversized' => false,
            'event_count' => 0,
            'scope_row_count' => 0,
            'through_revision' => $from,
            'events' => [],
        ];
    }
    $maximumEvents = max(1, $maximumEvents);
    $maximumScopeRows = max(1, $maximumScopeRows);
    $rowLimit = $maximumEvents + 1;
    $groups = $db->prepare("
        SELECT event.source_revision,
               COUNT(scope.ordinal) AS scope_count
        FROM recipe_score_source_reconciliation_events event
        LEFT JOIN recipe_score_source_reconciliation_scopes scope
          ON scope.source_revision = event.source_revision
        WHERE event.source_revision > ?
          AND event.source_revision <= ?
        GROUP BY event.source_revision
        ORDER BY event.source_revision
        LIMIT {$rowLimit}
    ");
    $groups->execute([$from, $through]);
    $selectedRevisions = [];
    $expectedRevision = $from + 1;
    $scopeRows = 0;
    $oversized = false;
    while ($row = $groups->fetch(PDO::FETCH_ASSOC)) {
        $revision = (int)$row['source_revision'];
        if ($revision !== $expectedRevision) {
            break;
        }
        $eventScopeRows = (int)$row['scope_count'];
        if ($eventScopeRows > $maximumScopeRows) {
            $oversized = !$selectedRevisions;
            break;
        }
        if (
            count($selectedRevisions) >= $maximumEvents
            || $scopeRows + $eventScopeRows > $maximumScopeRows
        ) {
            break;
        }
        $selectedRevisions[] = $revision;
        $scopeRows += $eventScopeRows;
        $expectedRevision++;
    }
    $pageThrough = $selectedRevisions
        ? $selectedRevisions[array_key_last($selectedRevisions)]
        : $from;
    if (!$selectedRevisions) {
        return [
            'available' => false,
            'complete' => false,
            'has_more' => $through > $from,
            'oversized' => $oversized,
            'event_count' => 0,
            'scope_row_count' => 0,
            'through_revision' => $from,
            'events' => [],
        ];
    }
    $stmt = $db->prepare("
        SELECT event.source_revision, event.event_lane,
               event.event_owner_type, event.event_owner_id,
               event.event_operation, event.event_reason,
               event.expected_scope_count,
               event.source_table AS event_source_table,
               event.source_row_id AS event_source_row_id,
               scope.ordinal, scope.aggregate_type,
               scope.aggregate_id, scope.scope_role,
               scope.source_table, scope.source_row_id,
               scope.source_key, scope.metadata_json
        FROM recipe_score_source_reconciliation_events event
        LEFT JOIN recipe_score_source_reconciliation_scopes scope
          ON scope.source_revision = event.source_revision
        WHERE event.source_revision > ?
          AND event.source_revision <= ?
        ORDER BY event.source_revision, scope.ordinal
    ");
    $stmt->execute([$from, $pageThrough]);
    $events = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $revision = (int)$row['source_revision'];
        if (!isset($events[$revision])) {
            $events[$revision] = [
                'id' => 0,
                'revision' => $revision,
                'lane' => (string)$row['event_lane'],
                'owner_type' =>
                    (string)$row['event_owner_type'],
                'owner_id' =>
                    $row['event_owner_id'] !== null
                        ? (int)$row['event_owner_id']
                        : null,
                'operation' =>
                    (string)$row['event_operation'],
                'source_table' =>
                    (string)$row['event_source_table'],
                'source_row_id' =>
                    $row['event_source_row_id'] !== null
                        ? (int)$row['event_source_row_id']
                        : null,
                'reason' => (string)$row['event_reason'],
                'scopes' => [],
                'expected_scope_count' =>
                    (int)$row['expected_scope_count'],
                'durable_scope_evidence_missing' => false,
            ];
        }
        if ($row['ordinal'] === null) {
            $events[$revision][
                'durable_scope_evidence_missing'
            ] = true;
            continue;
        }
        $events[$revision]['scopes'][] = [
            'aggregate_type' => (string)$row['aggregate_type'],
            'aggregate_id' =>
                $row['aggregate_id'] !== null
                    ? (int)$row['aggregate_id']
                    : null,
            'scope_role' => (string)$row['scope_role'],
            'source_table' => (string)$row['source_table'],
            'source_row_id' =>
                $row['source_row_id'] !== null
                    ? (int)$row['source_row_id']
                    : null,
            'source_key' => (string)$row['source_key'],
            'metadata_json' => (string)$row['metadata_json'],
        ];
    }
    foreach ($events as &$event) {
        $event['durable_scope_evidence_missing'] =
            count((array)$event['scopes']) === 0
            || count((array)$event['scopes'])
                < (int)$event['expected_scope_count'];
    }
    unset($event);
    return [
        'available' => count($events) === count($selectedRevisions),
        'complete' => $pageThrough === $through,
        'has_more' => $pageThrough < $through,
        'oversized' => false,
        'event_count' => count($events),
        'scope_row_count' => $scopeRows,
        'through_revision' => $pageThrough,
        'events' => array_values($events),
    ];
}

function ingredientOntologyV3CorpusAnnexReconciliationBackfill(
    PDO $db,
    int $limit = 500
): array {
    if (
        !ingredientOntologyV3TableExists(
            $db,
            'recipe_score_source_reconciliation_backfill'
        )
        || !ingredientOntologyV3TableExists(
            $db,
            'recipe_score_source_reconciliation_events'
        )
    ) {
        return ['complete' => true, 'processed' => 0];
    }
    $limit = max(1, min(5000, $limit));
    $ownsTransaction = !databaseTransactionIsActive($db);
    if ($ownsTransaction) {
        dbBeginImmediateWithRetry($db);
    }
    try {
        $state = $db->query("
            SELECT last_mutation_id, complete,
                   scope_backfill_version,
                   scope_backfill_started
            FROM recipe_score_source_reconciliation_backfill
            WHERE id = 1
        ")->fetch(PDO::FETCH_ASSOC) ?: [
            'last_mutation_id' => 0,
            'complete' => 0,
            'scope_backfill_version' => 0,
            'scope_backfill_started' => 0,
        ];
        if (
            (int)$state['scope_backfill_version'] < 1
            && empty($state['scope_backfill_started'])
        ) {
            $db->exec("
                UPDATE recipe_score_source_reconciliation_backfill
                SET last_mutation_id = 0,
                    complete = 0,
                    scope_backfill_started = 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = 1
            ");
            $state['last_mutation_id'] = 0;
            $state['complete'] = 0;
            $state['scope_backfill_started'] = 1;
        }
        if (
            !empty($state['complete'])
            && (int)$state['scope_backfill_version'] >= 1
        ) {
            if ($ownsTransaction) {
                $db->exec('COMMIT');
            }
            return ['complete' => true, 'processed' => 0];
        }
        $rows = $db->prepare("
            SELECT id, revision, lane, owner_type, owner_id,
                   operation, reason, source_table, source_row_id,
                   created_at
            FROM recipe_score_mutations
            WHERE domain = 'source' AND id > ?
            ORDER BY id
            LIMIT ?
        ");
        $rows->bindValue(
            1,
            (int)$state['last_mutation_id'],
            PDO::PARAM_INT
        );
        $rows->bindValue(2, $limit, PDO::PARAM_INT);
        $rows->execute();
        $events = $rows->fetchAll(PDO::FETCH_ASSOC);
        $insert = $db->prepare("
            INSERT OR IGNORE INTO
                recipe_score_source_reconciliation_events (
                source_revision, event_lane, event_owner_type,
                event_owner_id, event_operation, event_reason,
                source_table, source_row_id, created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $lastMutationId = (int)$state['last_mutation_id'];
        foreach ($events as $event) {
            $insert->execute([
                (int)$event['revision'],
                (string)$event['lane'],
                (string)$event['owner_type'],
                $event['owner_id'] !== null
                    ? (int)$event['owner_id']
                    : null,
                (string)$event['operation'],
                (string)$event['reason'],
                (string)$event['source_table'],
                $event['source_row_id'] !== null
                    ? (int)$event['source_row_id']
                    : null,
                (string)$event['created_at'],
            ]);
            $lastMutationId = (int)$event['id'];
        }
        if ($events) {
            $eventIds = array_map(
                static fn(array $event): int => (int)$event['id'],
                $events
            );
            $placeholders = implode(
                ',',
                array_fill(0, count($eventIds), '?')
            );
            $scopeInsert = $db->prepare("
                INSERT INTO recipe_score_source_reconciliation_scopes (
                    source_revision, ordinal, event_lane,
                    event_owner_type, event_owner_id,
                    event_operation, event_reason,
                    aggregate_type, aggregate_id, scope_role,
                    source_table, source_row_id, source_key,
                    metadata_json, created_at
                )
                SELECT mutation.revision, scope.ordinal,
                       mutation.lane, mutation.owner_type,
                       mutation.owner_id, mutation.operation,
                       mutation.reason, scope.aggregate_type,
                       scope.aggregate_id, scope.scope_role,
                       scope.source_table, scope.source_row_id,
                       scope.source_key, scope.metadata_json,
                       scope.created_at
                FROM recipe_score_mutation_scopes scope
                JOIN recipe_score_mutations mutation
                  ON mutation.id = scope.mutation_id
                 AND mutation.domain = 'source'
                WHERE mutation.id IN ({$placeholders})
                ON CONFLICT(source_revision, ordinal) DO UPDATE SET
                    event_lane = excluded.event_lane,
                    event_owner_type = excluded.event_owner_type,
                    event_owner_id = excluded.event_owner_id,
                    event_operation = excluded.event_operation,
                    event_reason = excluded.event_reason,
                    aggregate_type = excluded.aggregate_type,
                    aggregate_id = excluded.aggregate_id,
                    scope_role = excluded.scope_role,
                    source_table = excluded.source_table,
                    source_row_id = excluded.source_row_id,
                    source_key = excluded.source_key,
                    metadata_json = excluded.metadata_json
            ");
            $scopeInsert->execute($eventIds);
            $scopeCountUpdate = $db->prepare("
                UPDATE recipe_score_source_reconciliation_events
                SET expected_scope_count = (
                    SELECT COUNT(*)
                    FROM recipe_score_mutation_scopes scope
                    JOIN recipe_score_mutations mutation
                      ON mutation.id = scope.mutation_id
                     AND mutation.domain = 'source'
                    WHERE mutation.revision =
                        recipe_score_source_reconciliation_events
                            .source_revision
                )
                WHERE source_revision IN (
                    SELECT revision
                    FROM recipe_score_mutations
                    WHERE id IN ({$placeholders})
                )
            ");
            $scopeCountUpdate->execute($eventIds);
        }
        $processed = count($events);
        $remaining = max(0, $limit - $processed);
        if ($remaining > 0) {
            $scopeEvents = $db->prepare("
                SELECT scope.source_revision,
                       MIN(scope.event_lane) AS event_lane,
                       MIN(scope.event_owner_type)
                           AS event_owner_type,
                       MIN(scope.event_owner_id) AS event_owner_id,
                       MIN(scope.event_operation) AS event_operation,
                       MIN(scope.event_reason) AS event_reason,
                       MIN(scope.source_table) AS source_table,
                       MIN(scope.source_row_id) AS source_row_id,
                       MIN(scope.created_at) AS created_at
                FROM recipe_score_source_reconciliation_scopes scope
                LEFT JOIN recipe_score_source_reconciliation_events event
                  ON event.source_revision = scope.source_revision
                WHERE event.source_revision IS NULL
                GROUP BY scope.source_revision
                ORDER BY scope.source_revision
                LIMIT ?
            ");
            $scopeEvents->bindValue(
                1,
                $remaining,
                PDO::PARAM_INT
            );
            $scopeEvents->execute();
            $scopeEventCount = $db->prepare("
                UPDATE recipe_score_source_reconciliation_events
                SET expected_scope_count = (
                    SELECT COUNT(*)
                    FROM recipe_score_source_reconciliation_scopes scope
                    WHERE scope.source_revision = ?
                )
                WHERE source_revision = ?
            ");
            foreach (
                $scopeEvents->fetchAll(PDO::FETCH_ASSOC)
                as $event
            ) {
                $insert->execute([
                    (int)$event['source_revision'],
                    (string)$event['event_lane'],
                    (string)$event['event_owner_type'],
                    $event['event_owner_id'] !== null
                        ? (int)$event['event_owner_id']
                        : null,
                    (string)$event['event_operation'],
                    (string)$event['event_reason'],
                    (string)$event['source_table'],
                    $event['source_row_id'] !== null
                        ? (int)$event['source_row_id']
                        : null,
                    (string)$event['created_at'],
                ]);
                $scopeEventCount->execute([
                    (int)$event['source_revision'],
                    (int)$event['source_revision'],
                ]);
                $processed++;
            }
        }
        $complete = $processed < $limit;
        $db->prepare("
            UPDATE recipe_score_source_reconciliation_backfill
            SET last_mutation_id = ?,
                complete = ?,
                scope_backfill_version = ?,
                scope_backfill_started = 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1
        ")->execute([
            $lastMutationId,
            $complete ? 1 : 0,
            $complete ? 1 : 0,
        ]);
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
        return [
            'complete' => $complete,
            'processed' => $processed,
            'last_mutation_id' => $lastMutationId,
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $error;
    }
}

function ingredientOntologyV3CorpusAnnexCanonicalDependencyScopes(
    PDO $db,
    array $canonicalIds,
    int $versionId,
    ?int $limit = null,
    string $afterAggregateKey = ''
): array {
    $canonicalIds = array_values(array_unique(array_filter(
        array_map('intval', $canonicalIds),
        static fn(int $id): bool => $id > 0
    )));
    $limit = max(
        1,
        $limit ?? (
            function_exists('ingredientOntologyV3IncrementalProductLimit')
                ? ingredientOntologyV3IncrementalProductLimit()
                : 250
        )
    );
    $rowLimit = $limit + 1;
    $candidates = [];
    if (!$canonicalIds) {
        return [
            'product' => [],
            'recipe' => [],
            'has_more' => false,
            'last_key' => $afterAggregateKey,
        ];
    }
    [$afterType, $afterId] = array_pad(
        explode(':', $afterAggregateKey, 2),
        2,
        '0'
    );
    $afterId = (int)$afterId;
    $add = static function (
        string $type,
        int $id
    ) use (&$candidates, $afterAggregateKey): void {
        if ($id <= 0) {
            return;
        }
        $key = ingredientOntologyV3CorpusAnnexAggregateKey(
            $type,
            $id
        );
        if (
            $afterAggregateKey !== ''
            && ingredientOntologyV3CorpusAnnexAggregateKeyCompare(
                $key,
                $afterAggregateKey
            ) <= 0
        ) {
            return;
        }
        $candidates[$key] = true;
    };
    $placeholders = implode(
        ',',
        array_fill(0, count($canonicalIds), '?')
    );
    $sourceHasMore = false;
    if ($afterType !== 'recipe') {
        $stmt = $db->prepare("
            SELECT DISTINCT product_id
            FROM product_ingredients
            WHERE ingredient_id IN ({$placeholders})
              AND product_id > ?
            ORDER BY product_id
            LIMIT {$rowLimit}
        ");
        $stmt->execute([
            ...$canonicalIds,
            $afterType === 'product' ? $afterId : 0,
        ]);
        $rowCount = 0;
        while (($id = $stmt->fetchColumn()) !== false) {
            $rowCount++;
            $add('product', (int)$id);
        }
        $sourceHasMore = $sourceHasMore || $rowCount >= $rowLimit;
    }
    $recipeAfter = $afterType === 'recipe' ? $afterId : 0;
    foreach ([
        'recipe_ingredients',
        'recipe_source_ingredients',
    ] as $table) {
        $stmt = $db->prepare("
            SELECT DISTINCT recipe_id
            FROM {$table}
            WHERE canonical_ingredient_id IN ({$placeholders})
              AND recipe_id > ?
            ORDER BY recipe_id
            LIMIT {$rowLimit}
        ");
        $stmt->execute([...$canonicalIds, $recipeAfter]);
        $rowCount = 0;
        while (($id = $stmt->fetchColumn()) !== false) {
            $rowCount++;
            $add('recipe', (int)$id);
        }
        $sourceHasMore =
            $sourceHasMore || $rowCount >= $rowLimit;
    }
    foreach (['product', 'recipe'] as $type) {
        if ($type === 'product' && $afterType === 'recipe') {
            continue;
        }
        $minimumId = $afterType === $type ? $afterId : 0;
        $historical = $db->prepare("
            SELECT DISTINCT aggregate_id
            FROM ingredient_ontology_corpus_annex_effective_members
            WHERE ontology_version_id = ?
              AND aggregate_type = ?
              AND json_extract(
                payload_json,
                '$.canonical_ingredient_id'
              ) IN ({$placeholders})
              AND aggregate_id > CAST(? AS INTEGER)
            ORDER BY aggregate_id
            LIMIT {$rowLimit}
        ");
        $historical->execute([
            $versionId,
            $type,
            ...$canonicalIds,
            $minimumId,
        ]);
        $rowCount = 0;
        while (($id = $historical->fetchColumn()) !== false) {
            $rowCount++;
            $add($type, (int)$id);
        }
        $sourceHasMore =
            $sourceHasMore || $rowCount >= $rowLimit;
    }
    $keys = array_keys($candidates);
    usort(
        $keys,
        'ingredientOntologyV3CorpusAnnexAggregateKeyCompare'
    );
    $hasMore = $sourceHasMore || count($keys) > $limit;
    $keys = array_slice($keys, 0, $limit);
    $result = [
        'product' => [],
        'recipe' => [],
        'has_more' => $hasMore,
        'last_key' => $keys
            ? $keys[array_key_last($keys)]
            : $afterAggregateKey,
    ];
    foreach ($keys as $key) {
        [$type, $id] = explode(':', $key, 2);
        $result[$type][(int)$id] = true;
    }
    return $result;
}

function ingredientOntologyV3CorpusAnnexAliasDependencyScopes(
    PDO $db,
    array $normalizedAliases,
    int $versionId,
    ?int $limit = null,
    string $afterAggregateKey = ''
): array {
    $normalizedAliases = array_values(array_unique(array_filter(
        array_map(
            static fn(string $alias): string =>
                ingredientOntologyV3NormalizeLabel($alias),
            array_map('strval', $normalizedAliases)
        ),
        static fn(string $alias): bool => $alias !== ''
    )));
    $limit = max(
        1,
        $limit ?? (
            function_exists('ingredientOntologyV3IncrementalProductLimit')
                ? ingredientOntologyV3IncrementalProductLimit()
                : 250
        )
    );
    $rowLimit = $limit + 1;
    $candidates = [];
    if (!$normalizedAliases) {
        return [
            'product' => [],
            'recipe' => [],
            'has_more' => false,
            'last_key' => $afterAggregateKey,
        ];
    }
    [$afterType, $afterId] = array_pad(
        explode(':', $afterAggregateKey, 2),
        2,
        '0'
    );
    $afterId = (int)$afterId;
    $add = static function (
        string $type,
        int $id
    ) use (&$candidates, $afterAggregateKey): void {
        if ($id <= 0) {
            return;
        }
        $key = ingredientOntologyV3CorpusAnnexAggregateKey(
            $type,
            $id
        );
        if (
            $afterAggregateKey !== ''
            && ingredientOntologyV3CorpusAnnexAggregateKeyCompare(
                $key,
                $afterAggregateKey
            ) <= 0
        ) {
            return;
        }
        $candidates[$key] = true;
    };
    sort($normalizedAliases, SORT_STRING);
    $placeholders = implode(
        ',',
        array_fill(0, count($normalizedAliases), '?')
    );
    $sourceHasMore = false;
    $recipeAfter = $afterType === 'recipe' ? $afterId : 0;
    foreach (['recipe_ingredients', 'recipe_source_ingredients'] as $table) {
        $stmt = $db->prepare("
            SELECT DISTINCT recipe_id
            FROM {$table}
            WHERE normalized_name IN ({$placeholders})
              AND recipe_id > ?
            ORDER BY recipe_id
            LIMIT {$rowLimit}
        ");
        $stmt->execute([...$normalizedAliases, $recipeAfter]);
        $rowCount = 0;
        while (($recipeId = $stmt->fetchColumn()) !== false) {
            $rowCount++;
            $add('recipe', (int)$recipeId);
        }
        $sourceHasMore =
            $sourceHasMore || $rowCount >= $rowLimit;
    }
    foreach (['product', 'recipe'] as $type) {
        if ($type === 'product' && $afterType === 'recipe') {
            continue;
        }
        $minimumId = $afterType === $type ? $afterId : 0;
        $historical = $db->prepare("
            SELECT DISTINCT aggregate_id
            FROM ingredient_ontology_corpus_annex_effective_members
            WHERE ontology_version_id = ?
              AND aggregate_type = ?
              AND normalized_source_label IN ({$placeholders})
              AND aggregate_id > ?
            ORDER BY aggregate_id
            LIMIT {$rowLimit}
        ");
        $historical->execute([
            $versionId,
            $type,
            ...$normalizedAliases,
            $minimumId,
        ]);
        $rowCount = 0;
        while (($id = $historical->fetchColumn()) !== false) {
            $rowCount++;
            $add($type, (int)$id);
        }
        $sourceHasMore =
            $sourceHasMore || $rowCount >= $rowLimit;
    }
    if ($afterType !== 'recipe') {
        $products = $db->prepare("
            SELECT DISTINCT product_id
            FROM ingredient_ontology_identity_annex
            WHERE ontology_version_id = ?
              AND normalized_label IN ({$placeholders})
              AND product_id > ?
            ORDER BY product_id
            LIMIT {$rowLimit}
        ");
        $products->execute([
            $versionId,
            ...$normalizedAliases,
            $afterType === 'product' ? $afterId : 0,
        ]);
        $rowCount = 0;
        while (($productId = $products->fetchColumn()) !== false) {
            $rowCount++;
            $add('product', (int)$productId);
        }
        $sourceHasMore =
            $sourceHasMore || $rowCount >= $rowLimit;
        $liveProducts = $db->prepare("
            SELECT id
            FROM products
            WHERE lower(trim(name)) IN ({$placeholders})
              AND id > ?
            ORDER BY id
            LIMIT {$rowLimit}
        ");
        $liveProducts->execute([
            ...$normalizedAliases,
            $afterType === 'product' ? $afterId : 0,
        ]);
        $rowCount = 0;
        while (($productId = $liveProducts->fetchColumn()) !== false) {
            $rowCount++;
            $add('product', (int)$productId);
        }
        $sourceHasMore =
            $sourceHasMore || $rowCount >= $rowLimit;
    }
    $keys = array_keys($candidates);
    usort(
        $keys,
        'ingredientOntologyV3CorpusAnnexAggregateKeyCompare'
    );
    $hasMore = $sourceHasMore || count($keys) > $limit;
    $keys = array_slice($keys, 0, $limit);
    $result = [
        'product' => [],
        'recipe' => [],
        'has_more' => $hasMore,
        'last_key' => $keys
            ? $keys[array_key_last($keys)]
            : $afterAggregateKey,
    ];
    foreach ($keys as $key) {
        [$type, $id] = explode(':', $key, 2);
        $result[$type][(int)$id] = true;
    }
    return $result;
}

function ingredientOntologyV3CorpusAnnexEventScopes(
    PDO $db,
    array $events,
    int $versionId,
    ?int $limit = null,
    string $afterAggregateKey = ''
): array {
    $result = [
        'product' => [],
        'recipe' => [],
        'authoritative' => false,
        'semantic' => false,
        'has_more' => false,
        'last_key' => $afterAggregateKey,
    ];
    $limit = max(
        1,
        $limit ?? (
            function_exists('ingredientOntologyV3IncrementalProductLimit')
                ? ingredientOntologyV3IncrementalProductLimit()
                : 250
        )
    );
    $candidateKeys = [];
    $addCandidate = static function (
        string $type,
        int $id
    ) use (&$candidateKeys, $afterAggregateKey): void {
        if ($id <= 0) {
            return;
        }
        $key = ingredientOntologyV3CorpusAnnexAggregateKey(
            $type,
            $id
        );
        if (
            $afterAggregateKey !== ''
            && ingredientOntologyV3CorpusAnnexAggregateKeyCompare(
                $key,
                $afterAggregateKey
            ) <= 0
        ) {
            return;
        }
        $candidateKeys[$key] = true;
    };
    $canonicalIds = [];
    $aliasLabels = [];
    foreach ($events as $event) {
        if (!empty($event['durable_scope_evidence_missing'])) {
            if (
                (string)$event['reason']
                    === 'semantic_policy_changed'
            ) {
                $result['semantic'] = true;
            } else {
                $result['authoritative'] = true;
            }
            continue;
        }
        $scopes = (array)($event['scopes'] ?? []);
        if (!$scopes) {
            if ((string)$event['owner_type'] === 'global') {
                if (
                    in_array(
                        (string)$event['reason'],
                        [
                            'semantic_policy_changed',
                        ],
                        true
                    )
                ) {
                    $result['semantic'] = true;
                } else {
                    $result['authoritative'] = true;
                }
                continue;
            }
            if (
                in_array(
                    (string)$event['owner_type'],
                    ['product', 'recipe'],
                    true
                )
                && (int)($event['owner_id'] ?? 0) > 0
            ) {
                $addCandidate(
                    (string)$event['owner_type'],
                    (int)$event['owner_id']
                );
                continue;
            }
            $result['authoritative'] = true;
            continue;
        }
        foreach ($scopes as $scope) {
            $type = (string)$scope['aggregate_type'];
            $id = (int)($scope['aggregate_id'] ?? 0);
            if (in_array($type, ['product', 'recipe'], true)) {
                if ($id > 0) {
                    $addCandidate($type, $id);
                }
                continue;
            }
            $table = (string)$scope['source_table'];
            if ($table === 'canonical_ingredients' && $id > 0) {
                $canonicalIds[$id] = true;
            } elseif ($table === 'taxonomy_aliases') {
                $metadata = json_decode(
                    (string)($scope['metadata_json'] ?? ''),
                    true
                );
                if (!is_array($metadata)) {
                    $result['authoritative'] = true;
                    continue;
                }
                $expected = [
                    'tree_id',
                    'node_id',
                    'normalized_alias',
                    'source',
                    'active',
                ];
                foreach ($expected as $key) {
                    if (!array_key_exists($key, $metadata)) {
                        $result['authoritative'] = true;
                        continue 2;
                    }
                }
                $normalized = ingredientOntologyV3NormalizeLabel(
                    (string)$metadata['normalized_alias']
                );
                if (
                    (int)$metadata['tree_id'] <= 0
                    || (int)$metadata['node_id'] <= 0
                    || $normalized === ''
                    || trim((string)$metadata['source']) === ''
                    || !in_array(
                        $metadata['active'],
                        [0, 1, '0', '1'],
                        true
                    )
                ) {
                    $result['authoritative'] = true;
                    continue;
                }
                if (
                    (int)($metadata['active'] ?? 0) === 1
                    && str_contains(
                        strtolower((string)($metadata['source'] ?? '')),
                        'gemini'
                    )
                ) {
                    $aliasLabels[$normalized] = true;
                }
            } else {
                $result['authoritative'] = true;
            }
        }
    }
    if ($canonicalIds) {
        $dependencies =
            ingredientOntologyV3CorpusAnnexCanonicalDependencyScopes(
                $db,
                array_keys($canonicalIds),
                $versionId,
                $limit + 1,
                $afterAggregateKey
            );
        $result['has_more'] =
            $result['has_more']
            || !empty($dependencies['has_more']);
        foreach (['product', 'recipe'] as $type) {
            foreach (array_keys($dependencies[$type]) as $id) {
                $addCandidate($type, (int)$id);
            }
        }
    }
    if ($aliasLabels) {
        $dependencies =
            ingredientOntologyV3CorpusAnnexAliasDependencyScopes(
                $db,
                array_keys($aliasLabels),
                $versionId,
                $limit + 1,
                $afterAggregateKey
            );
        $result['has_more'] =
            $result['has_more']
            || !empty($dependencies['has_more']);
        foreach (['product', 'recipe'] as $type) {
            foreach (array_keys($dependencies[$type]) as $id) {
                $addCandidate($type, (int)$id);
            }
        }
    }
    $keys = array_keys($candidateKeys);
    usort(
        $keys,
        'ingredientOntologyV3CorpusAnnexAggregateKeyCompare'
    );
    if (count($keys) > $limit) {
        $result['has_more'] = true;
        $keys = array_slice($keys, 0, $limit);
    }
    foreach ($keys as $key) {
        [$type, $id] = explode(':', $key, 2);
        $result[$type][(int)$id] = true;
    }
    if ($keys) {
        $result['last_key'] =
            $keys[array_key_last($keys)];
    }
    return $result;
}

function ingredientOntologyV3CorpusAnnexAuthoritativeMismatches(
    PDO $db,
    array $version,
    int $identityExtensionRevision,
    int $limit
): array {
    $limit = max(1, $limit);
    $mismatches = [];
    foreach ([
        'product' => "
            SELECT id FROM products ORDER BY id
        ",
        'recipe' => "
            SELECT id FROM recipe_catalog
            WHERE deleted_at IS NULL
            ORDER BY id
        ",
    ] as $type => $sql) {
        $stmt = $db->query($sql);
        while (($id = $stmt->fetchColumn()) !== false) {
            $id = (int)$id;
            $aggregate =
                ingredientOntologyV3CorpusAnnexAggregateSnapshot(
                    $db,
                    $version,
                    $type,
                    $id,
                    $identityExtensionRevision,
                    false
                );
            if (ingredientOntologyV3CorpusAnnexAggregateChanged(
                $db,
                (int)$version['id'],
                $aggregate,
                false
            )) {
                $mismatches[] = [$type, $id];
                if (count($mismatches) > $limit) {
                    return [
                        'keys' => $mismatches,
                        'has_more' => true,
                    ];
                }
            }
        }
    }
    $remaining = $limit - count($mismatches) + 1;
    $heads = $db->prepare("
        SELECT head.aggregate_type, head.aggregate_id
        FROM ingredient_ontology_corpus_annex_effective_aggregates head
        LEFT JOIN products product
          ON head.aggregate_type = 'product'
         AND product.id = head.aggregate_id
        LEFT JOIN recipe_catalog recipe
          ON head.aggregate_type = 'recipe'
         AND recipe.id = head.aggregate_id
         AND recipe.deleted_at IS NULL
        WHERE head.ontology_version_id = ?
          AND head.operation <> 'delete'
          AND (
              (head.aggregate_type = 'product' AND product.id IS NULL)
              OR
              (head.aggregate_type = 'recipe' AND recipe.id IS NULL)
          )
        ORDER BY head.aggregate_type, head.aggregate_id
        LIMIT ?
    ");
    $heads->bindValue(1, (int)$version['id'], PDO::PARAM_INT);
    $heads->bindValue(2, $remaining, PDO::PARAM_INT);
    $heads->execute();
    while ($head = $heads->fetch(PDO::FETCH_ASSOC)) {
        $mismatches[] = [
            (string)$head['aggregate_type'],
            (int)$head['aggregate_id'],
        ];
        if (count($mismatches) > $limit) {
            return [
                'keys' => $mismatches,
                'has_more' => true,
            ];
        }
    }
    return ['keys' => $mismatches, 'has_more' => false];
}

function ingredientOntologyV3CorpusAnnexAuthoritativeCandidatePage(
    PDO $db,
    int $versionId,
    int $limit,
    string $afterAggregateKey = ''
): array {
    ingredientOntologyV3TrackCorpusOperation(
        'authoritative_candidate_page'
    );
    $limit = max(1, $limit);
    $rowLimit = $limit + 1;
    [$afterType, $afterId] = array_pad(
        explode(':', $afterAggregateKey, 2),
        2,
        '0'
    );
    $afterId = (int)$afterId;
    $keys = [];
    $hasMore = false;
    if ($afterType !== 'recipe') {
        $products = $db->prepare("
            SELECT aggregate_id
            FROM (
                SELECT id AS aggregate_id
                FROM products
                UNION
                SELECT head.aggregate_id
                FROM ingredient_ontology_corpus_annex_effective_aggregates
                    head
                LEFT JOIN products product
                  ON product.id = head.aggregate_id
                WHERE head.ontology_version_id = ?
                  AND head.aggregate_type = 'product'
                  AND product.id IS NULL
            )
            WHERE aggregate_id > ?
            ORDER BY aggregate_id
            LIMIT {$rowLimit}
        ");
        $products->execute([
            $versionId,
            $afterType === 'product' ? $afterId : 0,
        ]);
        while (($id = $products->fetchColumn()) !== false) {
            $keys[] = ingredientOntologyV3CorpusAnnexAggregateKey(
                'product',
                (int)$id
            );
            if (count($keys) > $limit) {
                $hasMore = true;
                break;
            }
        }
    }
    if (count($keys) <= $limit) {
        $recipeLimit = $limit - count($keys) + 1;
        $recipes = $db->prepare("
            SELECT aggregate_id
            FROM (
                SELECT id AS aggregate_id
                FROM recipe_catalog
                WHERE deleted_at IS NULL
                UNION
                SELECT head.aggregate_id
                FROM ingredient_ontology_corpus_annex_effective_aggregates
                    head
                LEFT JOIN recipe_catalog recipe
                  ON recipe.id = head.aggregate_id
                 AND recipe.deleted_at IS NULL
                WHERE head.ontology_version_id = ?
                  AND head.aggregate_type = 'recipe'
                  AND recipe.id IS NULL
            )
            WHERE aggregate_id > ?
            ORDER BY aggregate_id
            LIMIT {$recipeLimit}
        ");
        $recipes->execute([
            $versionId,
            $afterType === 'recipe' ? $afterId : 0,
        ]);
        while (($id = $recipes->fetchColumn()) !== false) {
            $keys[] = ingredientOntologyV3CorpusAnnexAggregateKey(
                'recipe',
                (int)$id
            );
            if (count($keys) > $limit) {
                $hasMore = true;
                break;
            }
        }
    }
    if (count($keys) > $limit) {
        $keys = array_slice($keys, 0, $limit);
    }
    $result = [
        'product' => [],
        'recipe' => [],
        'has_more' => $hasMore,
        'last_key' => $keys
            ? $keys[array_key_last($keys)]
            : $afterAggregateKey,
    ];
    foreach ($keys as $key) {
        [$type, $id] = explode(':', $key, 2);
        $result[$type][(int)$id] = true;
    }
    return $result;
}

function ingredientOntologyV3CorpusAnnexClassifyMutable(
    PDO $db,
    array $parentScore,
    array $state,
    bool $requireIdentity = true,
    ?array $selectedAggregateKeys = null,
    ?bool $selectedHasMore = null,
    array $additionalAggregateKeys = [],
    ?array $identityExtensionSnapshot = null
): array {
    $pin = ingredientOntologyV3CorpusAnnexForScore(
        $db,
        $parentScore
    );
    if ($pin === null) {
        return [
            'eligible' => false,
            'errors' => ['corpus_projection_root_unavailable'],
        ];
    }
    $audit = ingredientOntologyV3CorpusProjectionLineageAudit(
        $db,
        (int)$pin['id'],
        (string)$pin['revision_hash']
    );
    if (empty($audit['valid'])) {
        return [
            'eligible' => false,
            'errors' => array_merge(
                ['corpus_projection_parent_invalid'],
                (array)$audit['errors']
            ),
        ];
    }
    if (
        (int)($parentScore['covered_ontology_source_revision']
            ?? $parentScore['ontology_source_revision'])
            !== (int)$pin['covered_ontology_source_revision']
        || (int)($parentScore['ontology_source_revision'] ?? -1)
            !== (int)$pin['captured_ontology_source_revision']
        || (int)($parentScore['identity_extension_revision'] ?? -1)
            !== (int)$pin['identity_extension_revision']
        || !hash_equals(
            (string)($parentScore['identity_extension_hash'] ?? ''),
            (string)$pin['identity_extension_hash']
        )
        || (int)($parentScore[
            'covered_identity_extension_revision'
        ] ?? -1) !== (int)$pin[
            'covered_identity_extension_revision'
        ]
        || !hash_equals(
            (string)($parentScore[
                'covered_identity_extension_hash'
            ] ?? ''),
            (string)$pin['covered_identity_extension_hash']
        )
    ) {
        return [
            'eligible' => false,
            'errors' => ['corpus_projection_score_fence_changed'],
        ];
    }
    ingredientOntologyV3CorpusAnnexEnsureProjection($db, $pin);
    $version = ingredientOntologyV3Version(
        $db,
        (int)$parentScore['ontology_version_id']
    );
    if (
        $version === null
        || (string)$version['status'] !== 'ready'
        || !hash_equals(
            (string)$pin['ontology_content_hash'],
            (string)$version['content_hash']
        )
        || !hash_equals(
            (string)$pin['ontology_seal_hash'],
            (string)$version['seal_hash']
        )
        || !hash_equals(
            (string)$pin['resolution_input_hash'],
            ingredientOntologyV3CorpusAnnexResolutionInputHash($version)
        )
    ) {
        return [
            'eligible' => false,
            'errors' => ['corpus_projection_semantic_fence_changed'],
        ];
    }
    $identityExtension = $identityExtensionSnapshot
        ?? ingredientOntologyV3IdentityExtensionSnapshot(
            $db,
            (int)$version['id']
        );
    $identitySnapshotValid =
        ingredientOntologyV3IdentityExtensionSnapshotMatches(
            $db,
            (int)$version['id'],
            $identityExtension
        );
    if (
        !$identitySnapshotValid
        || (int)$identityExtension['revision']
            < (int)$pin['identity_extension_revision']
    ) {
        return [
            'eligible' => false,
            'errors' => ['identity_extension_snapshot_invalid'],
        ];
    }
    $from = (int)$pin['covered_ontology_source_revision'];
    $captured = (int)$state['ontology_source_revision'];
    if ($captured < $from) {
        return [
            'eligible' => false,
            'errors' => ['ontology_source_revision_regressed'],
        ];
    }
    $limit = function_exists(
        'ingredientOntologyV3IncrementalProductLimit'
    ) ? ingredientOntologyV3IncrementalProductLimit() : 250;
    $normalizedAdditionalKeys = [];
    foreach ($additionalAggregateKeys as $key) {
        if (
            is_string($key)
            && preg_match(
                '/^(product|recipe):([1-9][0-9]*)$/D',
                $key
            )
        ) {
            $normalizedAdditionalKeys[$key] = true;
        }
    }
    $additionalKeys = array_keys($normalizedAdditionalKeys);
    usort(
        $additionalKeys,
        'ingredientOntologyV3CorpusAnnexAggregateKeyCompare'
    );
    $additionalOverflow = count($additionalKeys) > $limit;
    $additionalKeys = array_slice($additionalKeys, 0, $limit);
    $candidateKeys = array_fill_keys($additionalKeys, true);
    $processedAdditionalKeys = $additionalKeys;
    $sourceBudget = max(0, $limit - count($candidateKeys));
    $events = [];
    $mode = 'journal';
    $through = $from;
    $sourceHasMore = false;
    $journalComplete = $captured <= $from;
    $scopeDirectedReconciliation = false;
    $continuation = ingredientOntologyV3CorpusAnnexContinuation(
        $pin,
        $from,
        $captured
    );
    $afterAggregateKey = (string)(
        $continuation['after_aggregate_key'] ?? ''
    );
    if ($captured > $from) {
        $target = (int)(
            $continuation['target_revision'] ?? $captured
        );
        $journal = ingredientOntologyV3CorpusAnnexJournalWindow(
            $db,
            $from,
            $target,
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS
        );
        if (!empty($journal['dense'])) {
            $events = (array)$journal['events'];
            $through = (int)$journal['through_revision'];
            $journalComplete =
                !empty($journal['complete'])
                && $through === $captured;
        } else {
            $durable =
                ingredientOntologyV3CorpusAnnexDurableScopeWindow(
                    $db,
                    $from,
                    $target
                );
            if (empty($durable['available'])) {
                return [
                    'eligible' => false,
                    'errors' => [
                        !empty($journal['oversized'])
                        || !empty($durable['oversized'])
                            ? 'source_reconciliation_event_oversized'
                            : 'source_reconciliation_evidence_missing',
                    ],
                ];
            }
            $events = (array)$durable['events'];
            $through = (int)$durable['through_revision'];
            $mode = 'authoritative';
            $scopeDirectedReconciliation = true;
        }
        $scopeLimit = max(1, $sourceBudget);
        $scopes = ingredientOntologyV3CorpusAnnexEventScopes(
            $db,
            $events,
            (int)$version['id'],
            $scopeLimit,
            $afterAggregateKey
        );
        if (!empty($scopes['semantic'])) {
            return [
                'eligible' => false,
                'errors' => ['semantic_policy_changed'],
            ];
        }
        if (!empty($scopes['authoritative'])) {
            $mode = 'authoritative';
            $scopeDirectedReconciliation = false;
            $scopes = $sourceBudget > 0
                ? ingredientOntologyV3CorpusAnnexAuthoritativeCandidatePage(
                    $db,
                    (int)$version['id'],
                    $sourceBudget,
                    $afterAggregateKey
                )
                : [
                    'product' => [],
                    'recipe' => [],
                    'has_more' => true,
                    'last_key' => $afterAggregateKey,
                ];
        }
        if ($sourceBudget > 0) {
            foreach (['product', 'recipe'] as $type) {
                foreach (array_keys($scopes[$type]) as $id) {
                    $key =
                        ingredientOntologyV3CorpusAnnexAggregateKey(
                            $type,
                            (int)$id
                        );
                    if (isset($candidateKeys[$key])) {
                        continue;
                    }
                    if (count($candidateKeys) >= $limit) {
                        $sourceHasMore = true;
                        break 2;
                    }
                    $candidateKeys[$key] = true;
                }
            }
        } elseif (
            (array)$scopes['product']
            || (array)$scopes['recipe']
            || !empty($scopes['has_more'])
        ) {
            $sourceHasMore = true;
        }
        $sourceHasMore =
            $sourceHasMore
            || !empty($scopes['has_more']);
        if ($sourceHasMore && $sourceBudget > 0) {
            $afterAggregateKey = (string)$scopes['last_key'];
        } elseif (!$sourceHasMore) {
            $afterAggregateKey = '';
        }
    }
    if ($selectedAggregateKeys !== null) {
        $candidateKeys = array_fill_keys($additionalKeys, true);
        foreach ($selectedAggregateKeys as $key) {
            if (
                is_string($key)
                && preg_match(
                    '/^(product|recipe):([1-9][0-9]*)$/D',
                    $key
                )
            ) {
                if (count($candidateKeys) >= $limit) {
                    $sourceHasMore = true;
                    break;
                }
                $candidateKeys[$key] = true;
            }
        }
        if ($selectedHasMore !== null) {
            $sourceHasMore = $selectedHasMore;
        }
    }
    $aggregates = [];
    $changedKeys = [];
    $identityStatements = $requireIdentity && $candidateKeys
        ? ingredientOntologyV3CorpusAnnexIdentityEvidenceStatements(
            $db
        )
        : null;
    foreach (array_keys($candidateKeys) as $key) {
        [$type, $id] = explode(':', $key, 2);
        $aggregate =
            ingredientOntologyV3CorpusAnnexAggregateSnapshot(
                $db,
                $version,
                $type,
                (int)$id,
                (int)$identityExtension['revision'],
                $requireIdentity,
                $identityStatements
            );
        if (!ingredientOntologyV3CorpusAnnexAggregateChanged(
            $db,
            (int)$version['id'],
            $aggregate,
            $requireIdentity
        )) {
            continue;
        }
        $aggregates[] = $aggregate;
        $changedKeys[] = $key;
    }
    $ordinal = 0;
    $entries = [];
    $productIds = [];
    $recipeIds = [];
    $recipeOperations = [];
    foreach ($aggregates as $aggregate) {
        array_push(
            $entries,
            ...ingredientOntologyV3CorpusAnnexAggregateEntries(
                $aggregate,
                $ordinal
            )
        );
        if ((string)$aggregate['aggregate_type'] === 'product') {
            $productIds[] = (int)$aggregate['aggregate_id'];
        } else {
            $recipeId = (int)$aggregate['aggregate_id'];
            $recipeIds[] = $recipeId;
            $recipeOperations[$recipeId] =
                (string)$aggregate['operation'] === 'delete'
                    ? 'delete'
                    : 'replace';
        }
    }
    $covered = $sourceHasMore ? $from : $through;
    $sourceBacklog = $covered < $captured;
    $manifestContinuation = $sourceHasMore
        ? [
            'target_revision' => $through,
            'after_aggregate_key' => $afterAggregateKey,
        ]
        : null;
    $manifest = ingredientOntologyV3CorpusAnnexMutationManifest(
        $from,
        $through,
        $events,
        $mode,
        $changedKeys,
        $manifestContinuation
    );
    if (strlen((string)$manifest['json']) > 1048576) {
        $mode = 'authoritative';
        $manifest = ingredientOntologyV3CorpusAnnexMutationManifest(
            $from,
            $through,
            [],
            $mode,
            $changedKeys,
            $manifestContinuation
        );
    }
    $entrySetHash =
        ingredientOntologyV3CorpusAnnexEntrySetHash($entries);
    return [
        'eligible' => true,
        'errors' => [],
        'parent' => $pin,
        'root' => (array)$audit['root'],
        'from_revision' => $from,
        'through_revision' => $through,
        'covered_revision' => $covered,
        'events' => $events,
        'reconciliation_mode' => $mode,
        'mutation_manifest_hash' => (string)$manifest['hash'],
        'mutation_manifest_json' => (string)$manifest['json'],
        'entries' => $entries,
        'entry_set_hash' => $entrySetHash,
        'captured_corpus_hash' => ingredientOntologyV3Hash([
            'algorithm' =>
                INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                    . ':source-lineage',
            'parent' => (string)$pin['captured_corpus_hash'],
            'from_revision' => $from,
            'through_revision' => $through,
            'captured_revision' => $captured,
            'covered_revision' => $covered,
            'manifest_hash' => (string)$manifest['hash'],
            'entry_set_hash' => $entrySetHash,
        ]),
        'projection_root_hash' => ingredientOntologyV3Hash([
            'algorithm' =>
                INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                    . ':projection-lineage',
            'parent' => (string)$pin['projection_root_hash'],
            'manifest_hash' => (string)$manifest['hash'],
            'entry_set_hash' => $entrySetHash,
        ]),
        'resolution_input_hash' =>
            ingredientOntologyV3CorpusAnnexResolutionInputHash(
                $version
            ),
        'identity_extension' => $identityExtension,
        'covered_identity_extension' => [
            'revision' => (int)$pin[
                'covered_identity_extension_revision'
            ],
            'hash' => (string)$pin[
                'covered_identity_extension_hash'
            ],
        ],
        'product_ids' => $productIds,
        'recipe_ids' => $recipeIds,
        'recipe_operations' => $recipeOperations,
        'aggregate_keys' => $changedKeys,
        'additional_aggregate_keys' =>
            $additionalKeys,
        'processed_additional_aggregate_keys' =>
            $processedAdditionalKeys,
        'aggregate_count' => count($aggregates),
        'has_more' =>
            $sourceBacklog || $additionalOverflow,
        'journal_complete' => $journalComplete,
        'scope_reconciliation_complete' =>
            $scopeDirectedReconciliation,
        'selection_complete' =>
            $selectedAggregateKeys === null
            && !$additionalOverflow,
        'captured_revision' => $captured,
        'source_continuation' => $manifestContinuation,
    ];
}

function ingredientOntologyV3CorpusAnnexClassifySuffix(
    PDO $db,
    array $parentScore,
    array $state,
    bool $requireIdentity = true,
    ?array $selectedAggregateKeys = null,
    ?bool $selectedHasMore = null,
    array $additionalAggregateKeys = [],
    ?array $identityExtensionSnapshot = null
): array {
    return ingredientOntologyV3CorpusAnnexClassifyMutable(
        $db,
        $parentScore,
        $state,
        $requireIdentity,
        $selectedAggregateKeys,
        $selectedHasMore,
        $additionalAggregateKeys,
        $identityExtensionSnapshot
    );
}

function ingredientOntologyV3CorpusAnnexForScore(
    PDO $db,
    array $score
): ?array {
    $revisionId = (int)(
        $score['corpus_annex_revision_id'] ?? 0
    );
    $expectedHash = (string)(
        $score['corpus_annex_hash'] ?? ''
    );
    if ($revisionId <= 0 || strlen($expectedHash) !== 64) {
        return null;
    }
    $revision = ingredientOntologyV3CorpusAnnexRevision(
        $db,
        $revisionId
    );
    $version = $revision !== null
        ? ingredientOntologyV3Version(
            $db,
            (int)$revision['ontology_version_id']
        )
        : null;
    if (
        $revision === null
        || $version === null
        || (string)$version['status'] !== 'ready'
        || (string)$revision['status'] !== 'ready'
        || !hash_equals(
            (string)$revision['revision_hash'],
            $expectedHash
        )
        || (int)$revision['ontology_version_id']
            !== (int)($score['ontology_version_id'] ?? 0)
        || !hash_equals(
            (string)$revision['ontology_content_hash'],
            (string)($score['ontology_content_hash'] ?? '')
        )
        || !hash_equals(
            (string)$revision['ontology_seal_hash'],
            (string)($score['ontology_seal_hash'] ?? '')
        )
        || !hash_equals(
            (string)$revision['resolution_input_hash'],
            ingredientOntologyV3CorpusAnnexResolutionInputHash(
                $version
            )
        )
        || (int)$revision['covered_ontology_source_revision']
            !== (int)($score[
                'covered_ontology_source_revision'
            ] ?? $score['ontology_source_revision'] ?? -1)
        || (int)$revision['captured_ontology_source_revision']
            !== (int)($score['ontology_source_revision'] ?? -1)
        || (int)$revision['identity_extension_revision']
            !== (int)($score['identity_extension_revision'] ?? -1)
        || !hash_equals(
            (string)$revision['identity_extension_hash'],
            (string)($score['identity_extension_hash'] ?? '')
        )
        || (int)$revision['covered_identity_extension_revision']
            !== (int)($score[
                'covered_identity_extension_revision'
            ] ?? -1)
        || !hash_equals(
            (string)$revision['covered_identity_extension_hash'],
            (string)($score[
                'covered_identity_extension_hash'
            ] ?? '')
        )
    ) {
        return null;
    }
    return $revision;
}

function ingredientOntologyV3CorpusAnnexPinnedPlan(
    array $pin,
    array $score
): array {
    $covered = (int)$pin['covered_ontology_source_revision'];
    return [
        'eligible' => true,
        'errors' => [],
        'parent' => $pin,
        'from_revision' => $covered,
        'through_revision' => $covered,
        'covered_revision' => $covered,
        'events' => [],
        'reconciliation_mode' => 'journal',
        'mutation_manifest_hash' =>
            (string)$pin['mutation_manifest_hash'],
        'mutation_manifest_json' =>
            (string)$pin['mutation_manifest_json'],
        'entries' => [],
        'entry_set_hash' => (string)$pin['entry_set_hash'],
        'captured_corpus_hash' =>
            (string)$pin['captured_corpus_hash'],
        'projection_root_hash' =>
            (string)$pin['projection_root_hash'],
        'resolution_input_hash' =>
            (string)$pin['resolution_input_hash'],
        'identity_extension' => [
            'revision' =>
                (int)($score['identity_extension_revision'] ?? 0),
            'hash' => (string)(
                $score['identity_extension_hash']
                    ?? ingredientOntologyV3IdentityExtensionZeroHash()
            ),
        ],
        'covered_identity_extension' => [
            'revision' => (int)($score[
                'covered_identity_extension_revision'
            ] ?? 0),
            'hash' => (string)($score[
                'covered_identity_extension_hash'
            ] ?? ingredientOntologyV3IdentityExtensionZeroHash()),
        ],
        'product_ids' => [],
        'recipe_ids' => [],
        'recipe_operations' => [],
        'aggregate_keys' => [],
        'additional_aggregate_keys' => [],
        'processed_additional_aggregate_keys' => [],
        'aggregate_count' => 0,
        'has_more' => false,
        'journal_complete' => true,
        'scope_reconciliation_complete' => false,
        'selection_complete' => true,
        'captured_revision' => (int)$pin[
            'captured_ontology_source_revision'
        ],
        'source_continuation' => null,
    ];
}

function ingredientOntologyV3CorpusAnnexEntryInsertStatement(
    PDO $db
): PDOStatement {
    return $db->prepare("
        INSERT INTO ingredient_ontology_corpus_annex_entries (
            corpus_annex_revision_id, ordinal, entry_type,
            operation, owner_type, owner_id, recipe_id,
            owner_fingerprint, identity_status,
            identity_disposition, satisfies_required,
            native_entity_slug, identity_extension_key_hash,
            resolver_version, review_manifest_hash,
            evidence_hash, aggregate_source_hash,
            resolution_input_hash, aggregate_hash, member_count,
            identity_json, payload_json, row_hash
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?
        )
    ");
}

function ingredientOntologyV3CorpusAnnexInsertEntry(
    PDOStatement $insert,
    int $revisionId,
    array $entry
): void {
    $insert->execute([
        $revisionId,
        (int)$entry['ordinal'],
        (string)$entry['entry_type'],
        (string)$entry['operation'],
        (string)$entry['owner_type'],
        (int)$entry['owner_id'],
        $entry['recipe_id'],
        (string)$entry['owner_fingerprint'],
        (string)$entry['identity_status'],
        (string)$entry['identity_disposition'],
        (int)$entry['satisfies_required'],
        $entry['native_entity_slug'],
        $entry['identity_extension_key_hash'],
        (string)$entry['resolver_version'],
        (string)$entry['review_manifest_hash'],
        (string)$entry['evidence_hash'],
        (string)$entry['aggregate_source_hash'],
        (string)$entry['resolution_input_hash'],
        (string)$entry['aggregate_hash'],
        (int)$entry['member_count'],
        (string)$entry['identity_json'],
        (string)$entry['payload_json'],
        (string)$entry['row_hash'],
    ]);
}

function ingredientOntologyV3CorpusAnnexInsertEntries(
    PDO $db,
    int $revisionId,
    array $entries
): void {
    $insert =
        ingredientOntologyV3CorpusAnnexEntryInsertStatement($db);
    foreach ($entries as $entry) {
        ingredientOntologyV3CorpusAnnexInsertEntry(
            $insert,
            $revisionId,
            $entry
        );
    }
}

function ingredientOntologyV3CorpusAnnexCreateCheckpointRoot(
    PDO $db,
    array $score
): ?array {
    $version = ingredientOntologyV3Version(
        $db,
        (int)($score['ontology_version_id'] ?? 0)
    );
    if (
        $version === null
        || (string)$version['status'] !== 'ready'
    ) {
        return null;
    }
    $state = recipeScoreState($db);
    if (
        (int)$state['ontology_source_revision']
            !== (int)$score['ontology_source_revision']
        || (int)($score['covered_ontology_source_revision']
            ?? $score['ontology_source_revision'])
            !== (int)$score['ontology_source_revision']
    ) {
        return null;
    }
    $currentCorpusHash = ingredientOntologyV3CorpusHash($db);
    $testFixture = defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE;
    $scoreHasSealedSource =
        strlen((string)$score['ontology_source_hash']) === 64
        && hash_equals(
            (string)$score['ontology_source_hash'],
            $currentCorpusHash
        );
    $pinnedProjection = !$scoreHasSealedSource
        ? ingredientOntologyV3CorpusAnnexForScore($db, $score)
        : null;
    $pinnedProjectionValid =
        $pinnedProjection !== null
        && (int)$pinnedProjection[
            'covered_ontology_source_revision'
        ] === (int)$state['ontology_source_revision']
        && !empty(
            ingredientOntologyV3CorpusProjectionLineageAudit(
                $db,
                (int)$pinnedProjection['id'],
                (string)$pinnedProjection['revision_hash']
            )['valid']
        );
    if (
        !$testFixture
        && !$scoreHasSealedSource
        && !$pinnedProjectionValid
    ) {
        return null;
    }
    $identityExtension = [
        'revision' =>
            (int)($score['identity_extension_revision'] ?? 0),
        'hash' => (string)(
            $score['identity_extension_hash']
                ?? ingredientOntologyV3IdentityExtensionZeroHash()
        ),
    ];
    if (!ingredientOntologyV3IdentityExtensionSnapshotMatches(
        $db,
        (int)$version['id'],
        $identityExtension
    )) {
        return null;
    }
    $maxima = ingredientOntologyV3CorpusAnnexMaxima($db);
    $existing = $db->prepare("
        SELECT id
        FROM ingredient_ontology_corpus_annex_revisions
        WHERE parent_revision_id IS NULL
          AND ontology_version_id = ?
          AND captured_ontology_source_revision = ?
          AND reconciliation_mode = 'checkpoint'
          AND status = 'ready'
        ORDER BY id DESC
        LIMIT 1
    ");
    $existing->execute([
        (int)$version['id'],
        (int)$score['ontology_source_revision'],
    ]);
    $existingId = (int)($existing->fetchColumn() ?: 0);
    if ($existingId > 0) {
        $existingRevision = ingredientOntologyV3CorpusAnnexRevision(
            $db,
            $existingId
        );
        if (
            $existingRevision !== null
            && hash_equals(
                $currentCorpusHash,
                (string)$existingRevision['base_corpus_hash']
            )
            && hash_equals(
                (string)$version['content_hash'],
                (string)$existingRevision['ontology_content_hash']
            )
            && hash_equals(
                (string)$version['seal_hash'],
                (string)$existingRevision['ontology_seal_hash']
            )
            && (int)$existingRevision[
                'identity_extension_revision'
            ] === (int)$identityExtension['revision']
            && hash_equals(
                (string)$identityExtension['hash'],
                (string)$existingRevision[
                    'identity_extension_hash'
                ]
            )
            && (int)$existingRevision[
                'covered_identity_extension_revision'
            ] === (int)($score[
                'covered_identity_extension_revision'
            ] ?? $identityExtension['revision'])
            && hash_equals(
                (string)$existingRevision[
                    'covered_identity_extension_hash'
                ],
                (string)($score[
                    'covered_identity_extension_hash'
                ] ?? $identityExtension['hash'])
            )
            && (int)$existingRevision['base_products_max_id']
                === (int)$maxima['products']
            && (int)$existingRevision[
                'base_recipe_catalog_max_id'
            ] === (int)$maxima['recipe_catalog']
            && (int)$existingRevision[
                'base_recipe_origins_max_id'
            ] === (int)$maxima['recipe_origins']
            && (int)$existingRevision[
                'base_recipe_ingredients_max_id'
            ] === (int)$maxima['recipe_ingredients']
            && (int)$existingRevision[
                'base_recipe_source_ingredients_max_id'
            ] === (int)$maxima['recipe_source_ingredients']
        ) {
            $existingAudit =
                ingredientOntologyV3CorpusProjectionIntegrityAuditV2(
                    $db,
                    $existingId,
                    (string)$existingRevision['revision_hash'],
                    false
                );
            if (!empty($existingAudit['valid'])) {
                return $existingRevision;
            }
        }
    }
    $manifest = ingredientOntologyV3CorpusAnnexMutationManifest(
        (int)$score['ontology_source_revision'],
        (int)$score['ontology_source_revision'],
        [],
        'checkpoint',
        []
    );
    $environmentHash =
        ingredientOntologyV3CorpusAnnexResolutionInputHash($version);
    $zero = ingredientOntologyV3CorpusAnnexZeroHash();
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_corpus_annex_revisions (
            ontology_version_id, ontology_content_hash,
            ontology_seal_hash, parent_revision_id,
            parent_revision_hash, hash_version, revision_hash,
            base_corpus_hash, captured_corpus_hash,
            base_products_max_id,
            base_recipe_catalog_max_id,
            base_recipe_origins_max_id,
            base_recipe_ingredients_max_id,
            base_recipe_source_ingredients_max_id,
            captured_ontology_source_revision,
            covered_ontology_source_revision,
            mutation_manifest_hash, mutation_manifest_json,
            entry_set_hash, projection_root_hash,
            resolution_input_hash,
            identity_extension_revision,
            identity_extension_hash,
            covered_identity_extension_revision,
            covered_identity_extension_hash, entry_count,
            aggregate_count, reconciliation_mode, status
        )
        VALUES (
            ?, ?, ?, NULL, ?, 2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, 0, 0, 'checkpoint', 'building'
        )
    ");
    $insert->execute([
        (int)$version['id'],
        (string)$version['content_hash'],
        (string)$version['seal_hash'],
        $zero,
        $zero,
        $currentCorpusHash,
        $zero,
        (int)$maxima['products'],
        (int)$maxima['recipe_catalog'],
        (int)$maxima['recipe_origins'],
        (int)$maxima['recipe_ingredients'],
        (int)$maxima['recipe_source_ingredients'],
        (int)$score['ontology_source_revision'],
        (int)$score['ontology_source_revision'],
        (string)$manifest['hash'],
        (string)$manifest['json'],
        $zero,
        $zero,
        $environmentHash,
        (int)$identityExtension['revision'],
        (string)$identityExtension['hash'],
        (int)($score[
            'covered_identity_extension_revision'
        ] ?? $identityExtension['revision']),
        (string)($score[
            'covered_identity_extension_hash'
        ] ?? $identityExtension['hash']),
    ]);
    $revisionId = (int)$db->lastInsertId();
    $entryHash = hash_init('sha256');
    $sourceHash = hash_init('sha256');
    $projectionHash = hash_init('sha256');
    hash_update(
        $entryHash,
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION . ":entry-set\n"
    );
    hash_update(
        $sourceHash,
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
            . ":aggregate-source-set\n"
    );
    hash_update(
        $projectionHash,
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
            . ":aggregate-state-set\n"
    );
    $ordinal = 0;
    $aggregateCount = 0;
    $identityStatements =
        ingredientOntologyV3CorpusAnnexIdentityEvidenceStatements(
            $db
        );
    try {
        foreach ([
            'product' => 'SELECT id FROM products ORDER BY id',
            'recipe' => "
                SELECT id
                FROM recipe_catalog
                WHERE deleted_at IS NULL
                ORDER BY id
            ",
        ] as $type => $sql) {
            $aggregateIds = $db->query($sql);
            while (($id = $aggregateIds->fetchColumn()) !== false) {
                $id = (int)$id;
                $aggregate =
                    ingredientOntologyV3CorpusAnnexAggregateSnapshot(
                        $db,
                        $version,
                        $type,
                        $id,
                        (int)$identityExtension['revision'],
                        true,
                        $identityStatements
                    );
                $entries =
                    ingredientOntologyV3CorpusAnnexAggregateEntries(
                        $aggregate,
                        $ordinal
                    );
                ingredientOntologyV3CorpusAnnexInsertEntries(
                    $db,
                    $revisionId,
                    $entries
                );
                foreach ($entries as $entry) {
                    hash_update(
                        $entryHash,
                        (string)$entry['row_hash'] . "\n"
                    );
                }
                $key = ingredientOntologyV3CorpusAnnexAggregateKey(
                    $type,
                    $id
                );
                hash_update(
                    $sourceHash,
                    $key . "\n"
                        . (string)$aggregate['aggregate_source_hash']
                        . "\n"
                );
                hash_update(
                    $projectionHash,
                    $key . "\n"
                        . (string)$aggregate['aggregate_hash']
                        . "\n"
                );
                $aggregateCount++;
            }
        }
        $entrySetHash = hash_final($entryHash);
        $capturedCorpusHash = hash_final($sourceHash);
        $projectionRootHash = hash_final($projectionHash);
        $root = [
            'hash_version' => 2,
            'ontology_version_id' => (int)$version['id'],
            'ontology_content_hash' =>
                (string)$version['content_hash'],
            'ontology_seal_hash' =>
                (string)$version['seal_hash'],
            'parent_revision_id' => null,
            'parent_revision_hash' => $zero,
            'base_corpus_hash' => $currentCorpusHash,
            'captured_corpus_hash' => $capturedCorpusHash,
            'base_products_max_id' => (int)$maxima['products'],
            'base_recipe_catalog_max_id' =>
                (int)$maxima['recipe_catalog'],
            'base_recipe_origins_max_id' =>
                (int)$maxima['recipe_origins'],
            'base_recipe_ingredients_max_id' =>
                (int)$maxima['recipe_ingredients'],
            'base_recipe_source_ingredients_max_id' =>
                (int)$maxima['recipe_source_ingredients'],
            'captured_ontology_source_revision' =>
                (int)$score['ontology_source_revision'],
            'covered_ontology_source_revision' =>
                (int)$score['ontology_source_revision'],
            'mutation_manifest_hash' => (string)$manifest['hash'],
            'mutation_manifest_json' => (string)$manifest['json'],
            'entry_set_hash' => $entrySetHash,
            'projection_root_hash' => $projectionRootHash,
            'resolution_input_hash' => $environmentHash,
            'identity_extension_revision' =>
                (int)$identityExtension['revision'],
            'identity_extension_hash' =>
                (string)$identityExtension['hash'],
            'covered_identity_extension_revision' =>
                (int)($score[
                    'covered_identity_extension_revision'
                ] ?? $identityExtension['revision']),
            'covered_identity_extension_hash' =>
                (string)($score[
                    'covered_identity_extension_hash'
                ] ?? $identityExtension['hash']),
            'entry_count' => $ordinal,
            'aggregate_count' => $aggregateCount,
            'reconciliation_mode' => 'checkpoint',
        ];
        $root['revision_hash'] =
            ingredientOntologyV3CorpusAnnexRevisionHash($root);
        if (
            (int)recipeScoreState($db)['ontology_source_revision']
                !== (int)$score['ontology_source_revision']
            || !hash_equals(
                $currentCorpusHash,
                ingredientOntologyV3CorpusHash($db)
            )
        ) {
            throw new RuntimeException(
                'corpus projection checkpoint source changed'
            );
        }
        $db->prepare("
            UPDATE ingredient_ontology_corpus_annex_revisions
            SET revision_hash = ?,
                captured_corpus_hash = ?,
                entry_set_hash = ?,
                projection_root_hash = ?,
                entry_count = ?,
                aggregate_count = ?
            WHERE id = ? AND status = 'building'
        ")->execute([
            (string)$root['revision_hash'],
            $capturedCorpusHash,
            $entrySetHash,
            $projectionRootHash,
            $ordinal,
            $aggregateCount,
            $revisionId,
        ]);
        $root['id'] = $revisionId;
        $root['status'] = 'building';
        return $root;
    } catch (Throwable $error) {
        $db->prepare("
            UPDATE ingredient_ontology_corpus_annex_revisions
            SET status = 'failed', last_error = ?,
                failed_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status = 'building'
        ")->execute([
            mb_substr($error->getMessage(), 0, 1000, 'UTF-8'),
            $revisionId,
        ]);
        throw $error;
    }
}

function ingredientOntologyV3CorpusAnnexCreateRolloverCheckpoint(
    PDO $db,
    array $pin
): array {
    if (!databaseTransactionIsActive($db)) {
        throw new RuntimeException(
            'corpus projection rollover requires an active transaction'
        );
    }
    $parentManifest = json_decode(
        (string)$pin['mutation_manifest_json'],
        true
    );
    $continuation = is_array($parentManifest)
        && is_array($parentManifest['continuation'] ?? null)
            ? $parentManifest['continuation']
            : null;
    $from = (int)$pin['covered_ontology_source_revision'];
    $through = $continuation !== null
        ? (int)($continuation['target_revision'] ?? $from)
        : $from;
    $payload = [
        'mode' => 'checkpoint',
        'delta_mode' => in_array(
            (string)(
                $parentManifest['delta_mode']
                    ?? $parentManifest['mode']
                    ?? ''
            ),
            ['journal', 'authoritative'],
            true
        ) ? (string)(
            $parentManifest['delta_mode']
                ?? $parentManifest['mode']
        ) : 'journal',
        'from_revision' => $from,
        'through_revision' => max($from, $through),
        'aggregate_keys' => [],
        'events' => [],
        'checkpoint_source' => [
            'revision_id' => (int)$pin['id'],
            'revision_hash' => (string)$pin['revision_hash'],
        ],
    ];
    if ($continuation !== null) {
        $payload['continuation'] = [
            'target_revision' =>
                (int)($continuation['target_revision'] ?? $from),
            'after_aggregate_key' => (string)(
                $continuation['after_aggregate_key'] ?? ''
            ),
        ];
    }
    $manifestJson = ingredientOntologyV3Json($payload);
    $manifestHash = ingredientOntologyV3Hash([
        'algorithm' =>
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                . ':mutation-manifest',
        'payload' => $payload,
    ]);
    $entrySetHash = ingredientOntologyV3CorpusAnnexEntrySetHash([]);
    $capturedHash = ingredientOntologyV3Hash([
        'algorithm' =>
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                . ':source-lineage',
        'parent' => (string)$pin['captured_corpus_hash'],
        'from_revision' => $from,
        'through_revision' => max($from, $through),
        'captured_revision' =>
            (int)$pin['captured_ontology_source_revision'],
        'covered_revision' => $from,
        'manifest_hash' => $manifestHash,
        'entry_set_hash' => $entrySetHash,
    ]);
    $projectionHash = ingredientOntologyV3Hash([
        'algorithm' =>
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                . ':projection-lineage',
        'parent' => (string)$pin['projection_root_hash'],
        'manifest_hash' => $manifestHash,
        'entry_set_hash' => $entrySetHash,
    ]);
    $zero = ingredientOntologyV3CorpusAnnexZeroHash();
    $revision = [
        'hash_version' => 2,
        'ontology_version_id' => (int)$pin['ontology_version_id'],
        'ontology_content_hash' =>
            (string)$pin['ontology_content_hash'],
        'ontology_seal_hash' =>
            (string)$pin['ontology_seal_hash'],
        'parent_revision_id' => null,
        'parent_revision_hash' => $zero,
        'base_corpus_hash' => (string)$pin['base_corpus_hash'],
        'captured_corpus_hash' => $capturedHash,
        'base_products_max_id' => (int)$pin['base_products_max_id'],
        'base_recipe_catalog_max_id' =>
            (int)$pin['base_recipe_catalog_max_id'],
        'base_recipe_origins_max_id' =>
            (int)$pin['base_recipe_origins_max_id'],
        'base_recipe_ingredients_max_id' =>
            (int)$pin['base_recipe_ingredients_max_id'],
        'base_recipe_source_ingredients_max_id' =>
            (int)$pin['base_recipe_source_ingredients_max_id'],
        'captured_ontology_source_revision' =>
            (int)$pin['captured_ontology_source_revision'],
        'covered_ontology_source_revision' => $from,
        'mutation_manifest_hash' => $manifestHash,
        'mutation_manifest_json' => $manifestJson,
        'entry_set_hash' => $entrySetHash,
        'projection_root_hash' => $projectionHash,
        'resolution_input_hash' =>
            (string)$pin['resolution_input_hash'],
        'identity_extension_revision' =>
            (int)$pin['identity_extension_revision'],
        'identity_extension_hash' =>
            (string)$pin['identity_extension_hash'],
        'covered_identity_extension_revision' =>
            (int)$pin['covered_identity_extension_revision'],
        'covered_identity_extension_hash' =>
            (string)$pin['covered_identity_extension_hash'],
        'entry_count' => 0,
        'aggregate_count' => 0,
        'reconciliation_mode' => 'checkpoint',
    ];
    $revision['revision_hash'] =
        ingredientOntologyV3CorpusAnnexRevisionHash($revision);
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_corpus_annex_revisions (
            ontology_version_id, ontology_content_hash,
            ontology_seal_hash, parent_revision_id,
            parent_revision_hash, hash_version, revision_hash,
            base_corpus_hash, captured_corpus_hash,
            base_products_max_id, base_recipe_catalog_max_id,
            base_recipe_origins_max_id,
            base_recipe_ingredients_max_id,
            base_recipe_source_ingredients_max_id,
            captured_ontology_source_revision,
            covered_ontology_source_revision,
            mutation_manifest_hash, mutation_manifest_json,
            entry_set_hash, projection_root_hash,
            resolution_input_hash,
            identity_extension_revision, identity_extension_hash,
            covered_identity_extension_revision,
            covered_identity_extension_hash,
            entry_count, aggregate_count,
            reconciliation_mode, status
        )
        VALUES (
            ?, ?, ?, NULL, ?, 2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, 0, 0, 'checkpoint', 'building'
        )
    ");
    $insert->execute([
        (int)$revision['ontology_version_id'],
        (string)$revision['ontology_content_hash'],
        (string)$revision['ontology_seal_hash'],
        $zero,
        (string)$revision['revision_hash'],
        (string)$revision['base_corpus_hash'],
        (string)$revision['captured_corpus_hash'],
        (int)$revision['base_products_max_id'],
        (int)$revision['base_recipe_catalog_max_id'],
        (int)$revision['base_recipe_origins_max_id'],
        (int)$revision['base_recipe_ingredients_max_id'],
        (int)$revision['base_recipe_source_ingredients_max_id'],
        (int)$revision['captured_ontology_source_revision'],
        (int)$revision['covered_ontology_source_revision'],
        (string)$revision['mutation_manifest_hash'],
        (string)$revision['mutation_manifest_json'],
        (string)$revision['entry_set_hash'],
        (string)$revision['projection_root_hash'],
        (string)$revision['resolution_input_hash'],
        (int)$revision['identity_extension_revision'],
        (string)$revision['identity_extension_hash'],
        (int)$revision['covered_identity_extension_revision'],
        (string)$revision['covered_identity_extension_hash'],
    ]);
    $revision['id'] = (int)$db->lastInsertId();
    $revision['status'] = 'building';
    return $revision;
}

function ingredientOntologyV3CorpusAnnexCreateEffectiveCheckpoint(
    PDO $db,
    array $score,
    array $pin
): array {
    ingredientOntologyV3TrackCorpusOperation(
        'effective_checkpoint_build',
        true
    );
    if (!databaseTransactionIsActive($db)) {
        throw new RuntimeException(
            'corpus projection compaction requires an active transaction'
        );
    }
    $version = ingredientOntologyV3Version(
        $db,
        (int)($score['ontology_version_id'] ?? 0)
    );
    $state = recipeScoreState($db);
    if (
        $version === null
        || (string)$version['status'] !== 'ready'
        || (int)$pin['ontology_version_id'] !== (int)$version['id']
        || (int)$pin['covered_ontology_source_revision']
            !== (int)$state['ontology_source_revision']
        || (int)($score['covered_ontology_source_revision']
            ?? $score['ontology_source_revision'])
            !== (int)$state['ontology_source_revision']
        || !ingredientOntologyV3CorpusAnnexProjectionReady($db, $pin)
    ) {
        throw new RuntimeException(
            'corpus projection compaction fence changed'
        );
    }
    $identityExtension = [
        'revision' =>
            (int)($score['identity_extension_revision'] ?? 0),
        'hash' => (string)(
            $score['identity_extension_hash']
                ?? ingredientOntologyV3IdentityExtensionZeroHash()
        ),
    ];
    if (!ingredientOntologyV3IdentityExtensionSnapshotMatches(
        $db,
        (int)$version['id'],
        $identityExtension
    )
        || (int)$pin['identity_extension_revision']
            !== (int)$identityExtension['revision']
        || !hash_equals(
            (string)$pin['identity_extension_hash'],
            (string)$identityExtension['hash']
        )
    ) {
        throw new RuntimeException(
            'corpus projection compaction identity fence changed'
        );
    }
    $sourceRevision = (int)$state['ontology_source_revision'];
    $currentCorpusHash = ingredientOntologyV3CorpusHash($db);
    $maxima = ingredientOntologyV3CorpusAnnexMaxima($db);
    $manifest = ingredientOntologyV3CorpusAnnexMutationManifest(
        $sourceRevision,
        $sourceRevision,
        [],
        'checkpoint',
        []
    );
    $environmentHash =
        ingredientOntologyV3CorpusAnnexResolutionInputHash($version);
    $zero = ingredientOntologyV3CorpusAnnexZeroHash();
    $insertRevision = $db->prepare("
        INSERT INTO ingredient_ontology_corpus_annex_revisions (
            ontology_version_id, ontology_content_hash,
            ontology_seal_hash, parent_revision_id,
            parent_revision_hash, hash_version, revision_hash,
            base_corpus_hash, captured_corpus_hash,
            base_products_max_id,
            base_recipe_catalog_max_id,
            base_recipe_origins_max_id,
            base_recipe_ingredients_max_id,
            base_recipe_source_ingredients_max_id,
            captured_ontology_source_revision,
            covered_ontology_source_revision,
            mutation_manifest_hash, mutation_manifest_json,
            entry_set_hash, projection_root_hash,
            resolution_input_hash,
            identity_extension_revision,
            identity_extension_hash,
            covered_identity_extension_revision,
            covered_identity_extension_hash, entry_count,
            aggregate_count, reconciliation_mode, status
        )
        VALUES (
            ?, ?, ?, NULL, ?, 2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, 0, 0, 'checkpoint', 'building'
        )
    ");
    $insertRevision->execute([
        (int)$version['id'],
        (string)$version['content_hash'],
        (string)$version['seal_hash'],
        $zero,
        $zero,
        $currentCorpusHash,
        $zero,
        (int)$maxima['products'],
        (int)$maxima['recipe_catalog'],
        (int)$maxima['recipe_origins'],
        (int)$maxima['recipe_ingredients'],
        (int)$maxima['recipe_source_ingredients'],
        $sourceRevision,
        $sourceRevision,
        (string)$manifest['hash'],
        (string)$manifest['json'],
        $zero,
        $zero,
        $environmentHash,
        (int)$identityExtension['revision'],
        (string)$identityExtension['hash'],
        (int)$pin['covered_identity_extension_revision'],
        (string)$pin['covered_identity_extension_hash'],
    ]);
    $revisionId = (int)$db->lastInsertId();
    $insertEntry =
        ingredientOntologyV3CorpusAnnexEntryInsertStatement($db);
    $members = $db->prepare("
        SELECT entry.*
        FROM ingredient_ontology_corpus_annex_effective_members member
        JOIN ingredient_ontology_corpus_annex_entries entry
          ON entry.id = member.head_entry_id
        WHERE member.ontology_version_id = ?
          AND member.aggregate_type = ?
          AND member.aggregate_id = ?
        ORDER BY
            CASE entry.entry_type
                WHEN 'product' THEN 0
                WHEN 'recipe_scope' THEN 0
                WHEN 'recipe_origin' THEN 1
                WHEN 'recipe_ingredient' THEN 2
                ELSE 3
            END,
            entry.owner_id
    ");
    $headEntry = $db->prepare("
        SELECT *
        FROM ingredient_ontology_corpus_annex_entries
        WHERE id = ?
    ");
    $heads = $db->prepare("
        SELECT *
        FROM ingredient_ontology_corpus_annex_effective_aggregates
        WHERE ontology_version_id = ?
        ORDER BY aggregate_type, aggregate_id
    ");
    $heads->execute([(int)$version['id']]);
    $entryHash = hash_init('sha256');
    $sourceHash = hash_init('sha256');
    $projectionHash = hash_init('sha256');
    hash_update(
        $entryHash,
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION . ":entry-set\n"
    );
    hash_update(
        $sourceHash,
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
            . ":aggregate-source-set\n"
    );
    hash_update(
        $projectionHash,
        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
            . ":aggregate-state-set\n"
    );
    $ordinal = 0;
    $aggregateCount = 0;
    while ($head = $heads->fetch(PDO::FETCH_ASSOC)) {
        $aggregateType = (string)$head['aggregate_type'];
        $aggregateId = (int)$head['aggregate_id'];
        $operation = (string)$head['operation'];
        $rows = [];
        if ($operation === 'delete') {
            $headEntry->execute([(int)$head['head_entry_id']]);
            $entry = $headEntry->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new RuntimeException(
                    'corpus projection tombstone evidence is missing'
                );
            }
            $rows[] = $entry;
        } else {
            $members->execute([
                (int)$version['id'],
                $aggregateType,
                $aggregateId,
            ]);
            while ($entry = $members->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = $entry;
            }
        }
        $expectedEntries = $operation === 'delete'
            ? 1
            : (int)$head['member_count'];
        $headerCount = 0;
        if (count($rows) !== $expectedEntries) {
            throw new RuntimeException(
                'corpus projection compaction membership changed'
            );
        }
        foreach ($rows as $entry) {
            $isHeader =
                (string)$entry['entry_type'] === 'product'
                || (string)$entry['entry_type'] === 'recipe_scope';
            $headerCount += $isHeader ? 1 : 0;
            if (
                !hash_equals(
                    ingredientOntologyV3CorpusAnnexEntryHash($entry),
                    (string)$entry['row_hash']
                )
                || (string)$entry['operation'] !== $operation
                || !hash_equals(
                    (string)$entry['aggregate_source_hash'],
                    (string)$head['source_hash']
                )
                || !hash_equals(
                    (string)$entry['resolution_input_hash'],
                    (string)$head['resolution_input_hash']
                )
                || !hash_equals(
                    (string)$entry['aggregate_hash'],
                    (string)$head['aggregate_hash']
                )
                || (int)$entry['member_count']
                    !== (int)$head['member_count']
            ) {
                throw new RuntimeException(
                    'corpus projection compaction evidence changed'
                );
            }
            $entry['ordinal'] = ++$ordinal;
            $entry['row_hash'] =
                ingredientOntologyV3CorpusAnnexEntryHash($entry);
            ingredientOntologyV3CorpusAnnexInsertEntry(
                $insertEntry,
                $revisionId,
                $entry
            );
            hash_update(
                $entryHash,
                (string)$entry['row_hash'] . "\n"
            );
        }
        if ($headerCount !== 1) {
            throw new RuntimeException(
                'corpus projection compaction header changed'
            );
        }
        $key = ingredientOntologyV3CorpusAnnexAggregateKey(
            $aggregateType,
            $aggregateId
        );
        hash_update(
            $sourceHash,
            $key . "\n" . (string)$head['source_hash'] . "\n"
        );
        hash_update(
            $projectionHash,
            $key . "\n" . (string)$head['aggregate_hash'] . "\n"
        );
        $aggregateCount++;
    }
    $entrySetHash = hash_final($entryHash);
    $capturedCorpusHash = hash_final($sourceHash);
    $projectionRootHash = hash_final($projectionHash);
    $root = [
        'hash_version' => 2,
        'ontology_version_id' => (int)$version['id'],
        'ontology_content_hash' => (string)$version['content_hash'],
        'ontology_seal_hash' => (string)$version['seal_hash'],
        'parent_revision_id' => null,
        'parent_revision_hash' => $zero,
        'base_corpus_hash' => $currentCorpusHash,
        'captured_corpus_hash' => $capturedCorpusHash,
        'base_products_max_id' => (int)$maxima['products'],
        'base_recipe_catalog_max_id' =>
            (int)$maxima['recipe_catalog'],
        'base_recipe_origins_max_id' =>
            (int)$maxima['recipe_origins'],
        'base_recipe_ingredients_max_id' =>
            (int)$maxima['recipe_ingredients'],
        'base_recipe_source_ingredients_max_id' =>
            (int)$maxima['recipe_source_ingredients'],
        'captured_ontology_source_revision' => $sourceRevision,
        'covered_ontology_source_revision' => $sourceRevision,
        'mutation_manifest_hash' => (string)$manifest['hash'],
        'mutation_manifest_json' => (string)$manifest['json'],
        'entry_set_hash' => $entrySetHash,
        'projection_root_hash' => $projectionRootHash,
        'resolution_input_hash' => $environmentHash,
        'identity_extension_revision' =>
            (int)$identityExtension['revision'],
        'identity_extension_hash' =>
            (string)$identityExtension['hash'],
        'covered_identity_extension_revision' =>
            (int)$pin['covered_identity_extension_revision'],
        'covered_identity_extension_hash' =>
            (string)$pin['covered_identity_extension_hash'],
        'entry_count' => $ordinal,
        'aggregate_count' => $aggregateCount,
        'reconciliation_mode' => 'checkpoint',
    ];
    $root['revision_hash'] =
        ingredientOntologyV3CorpusAnnexRevisionHash($root);
    if (
        (int)recipeScoreState($db)['ontology_source_revision']
            !== $sourceRevision
        || !hash_equals(
            $currentCorpusHash,
            ingredientOntologyV3CorpusHash($db)
        )
    ) {
        throw new RuntimeException(
            'corpus projection compaction source changed'
        );
    }
    $db->prepare("
        UPDATE ingredient_ontology_corpus_annex_revisions
        SET revision_hash = ?,
            captured_corpus_hash = ?,
            entry_set_hash = ?,
            projection_root_hash = ?,
            entry_count = ?,
            aggregate_count = ?
        WHERE id = ? AND status = 'building'
    ")->execute([
        (string)$root['revision_hash'],
        $capturedCorpusHash,
        $entrySetHash,
        $projectionRootHash,
        $ordinal,
        $aggregateCount,
        $revisionId,
    ]);
    $root['id'] = $revisionId;
    $root['status'] = 'building';
    return $root;
}

function ingredientOntologyV3CorpusAnnexZeroScoreChild(
    PDO $db,
    array $parent,
    array $root
): array {
    if (!function_exists(
        'ingredientOntologyV3IncrementalInsertRevision'
    )) {
        throw new RuntimeException(
            'incremental score support is unavailable'
        );
    }
    recipeScoreEnsureEffectiveProjection($db, $parent);
    $state = recipeScoreState($db);
    $childState = $state;
    $childState['inventory_revision'] =
        (int)$parent['inventory_revision'];
    $childState['catalog_revision'] =
        (int)$parent['catalog_revision'];
    $childState['ontology_source_revision'] =
        (int)$parent['ontology_source_revision'];
    $revisionId = ingredientOntologyV3IncrementalInsertRevision(
        $db,
        $parent,
        $childState,
        (string)$parent['inventory_fingerprint'],
        (string)$parent['ontology_source_hash'],
        [
            'revision' =>
                (int)$root['identity_extension_revision'],
            'hash' => (string)$root['identity_extension_hash'],
        ],
        false,
        (int)$parent['covered_catalog_revision'],
        (int)$parent['covered_ontology_source_revision'],
        (string)$parent['score_date'],
        $root
    );
    $hashes = ingredientOntologyV3IncrementalValueHashes(
        $db,
        $revisionId,
        $parent
    );
    $idSetHashes = ingredientOntologyV3IncrementalIdSetHashes(
        $db,
        $parent,
        [],
        []
    );
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
    ) {
        $db->prepare("
            UPDATE recipe_score_revisions
            SET ontology_content_hash = ?,
                ontology_seal_hash = ?
            WHERE id = ? AND status = 'building'
        ")->execute([
            (string)$root['ontology_content_hash'],
            (string)$root['ontology_seal_hash'],
            $revisionId,
        ]);
    }
    $parentReport = recipeScoreRevisionReport($parent);
    $report = $parentReport;
    $report['materialized_hash_algorithm'] = 'parent-delta-v2';
    $report['materialized_id_set_algorithm'] = 'parent-delta-v2';
    $report['incremental'] = true;
    $report['zero_score_delta'] = true;
    $report['incremental_chain_depth'] =
        (int)($parentReport['incremental_chain_depth'] ?? 0) + 1;
    $report['active_score_revision_id_before'] = (int)$parent['id'];
    $report['ontology_source_scope'] =
        (int)$root['covered_ontology_source_revision']
            < (int)$root['captured_ontology_source_revision']
                ? INGREDIENT_ONTOLOGY_CORPUS_ANNEX_SCOPE_PENDING
                : INGREDIENT_ONTOLOGY_CORPUS_ANNEX_SCOPE_MUTABLE;
    $report['physical_rows'] = [
        'score' => 0,
        'match' => 0,
        'operations' => 0,
    ];
    $report['materialized_values'] = [
        'valid' => true,
        'current' => $hashes,
    ];
    $report['materialized_id_sets'] = [
        'valid' => true,
        'current_hashes' => $idSetHashes,
    ];
    $report['corpus_annex'] = [
        'revision_id' => (int)$root['id'],
        'revision_hash' => (string)$root['revision_hash'],
        'published_with_score' => true,
        'entry_count' => (int)$root['entry_count'],
        'aggregate_count' => (int)$root['aggregate_count'],
        'covered_ontology_source_revision' =>
            (int)$root['covered_ontology_source_revision'],
        'reconciliation_mode' => 'checkpoint',
    ];
    $db->prepare("
        UPDATE recipe_score_revisions
        SET status = 'ready',
            recipe_count = ?,
            catalog_lineage_hash = ?,
            ontology_source_lineage_hash = ?,
            catalog_id_set_hash = ?,
            ingredient_id_set_hash = ?,
            score_rows_hash = ?,
            match_rows_hash = ?,
            materialization_hash = ?,
            validation_report_json = ?,
            completed_at = CURRENT_TIMESTAMP,
            last_error = ''
        WHERE id = ? AND status = 'building'
    ")->execute([
        (int)$parent['recipe_count'],
        (string)($parent['catalog_lineage_hash'] ?? ''),
        (string)($parent['ontology_source_lineage_hash'] ?? ''),
        (string)$idSetHashes['catalog_id_set_hash'],
        (string)$idSetHashes['ingredient_id_set_hash'],
        (string)$hashes['score_rows_hash'],
        (string)$hashes['match_rows_hash'],
        (string)$hashes['materialization_hash'],
        ingredientOntologyV3Json($report),
        $revisionId,
    ]);
    recipeScoreApplyDeltaProjection(
        $db,
        (int)$parent['id'],
        $revisionId
    );
    $db->prepare("
        UPDATE recipe_score_state
        SET active_score_revision_id = ?,
            active_score_overlay_revision_id = NULL,
            cursor_revision = cursor_revision + 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1
          AND active_score_revision_id = ?
          AND active_score_projection_revision_id = ?
          AND ontology_source_revision = ?
    ")->execute([
        $revisionId,
        (int)$parent['id'],
        $revisionId,
        (int)$state['ontology_source_revision'],
    ]);
    $active = recipeScoreActiveRevision($db);
    if ($active === null || (int)$active['id'] !== $revisionId) {
        throw new RuntimeException(
            'corpus projection migration score CAS failed'
        );
    }
    return $active;
}

function ingredientOntologyV3CorpusAnnexEnsureScoreRoot(
    PDO $db,
    array $score
): ?array {
    $existing = ingredientOntologyV3CorpusAnnexForScore($db, $score);
    if ($existing !== null) {
        $existingAudit =
            ingredientOntologyV3CorpusProjectionLineageAudit(
                $db,
                (int)$existing['id'],
                (string)$existing['revision_hash']
            );
        if (!empty($existingAudit['valid'])) {
            return $existing;
        }
    }
    if (
        recipeScoreRevisionIsSparseDelta($score)
        && (int)($score['parent_score_revision_id'] ?? 0) > 0
    ) {
        $parent = recipeScoreRevision(
            $db,
            (int)$score['parent_score_revision_id']
        );
        $parentProjection = $parent !== null
            ? ingredientOntologyV3CorpusAnnexForScore($db, $parent)
            : null;
        if (
            $parentProjection !== null
            && (int)($score['covered_ontology_source_revision']
                ?? $score['ontology_source_revision'])
                === (int)$parentProjection[
                    'covered_ontology_source_revision'
                ]
            && (int)$score['ontology_source_revision']
                === (int)$parentProjection[
                    'captured_ontology_source_revision'
                ]
            && (int)$score['identity_extension_revision']
                === (int)$parentProjection[
                    'identity_extension_revision'
                ]
            && hash_equals(
                (string)$score['identity_extension_hash'],
                (string)$parentProjection[
                    'identity_extension_hash'
                ]
            )
            && (int)$score[
                'covered_identity_extension_revision'
            ] === (int)$parentProjection[
                'covered_identity_extension_revision'
            ]
            && hash_equals(
                (string)$score[
                    'covered_identity_extension_hash'
                ],
                (string)$parentProjection[
                    'covered_identity_extension_hash'
                ]
            )
            && (string)$score['status'] === 'building'
        ) {
            $inherit = $db->prepare("
                UPDATE recipe_score_revisions
                SET corpus_annex_revision_id = ?,
                    corpus_annex_hash = ?
                WHERE id = ? AND status = 'building'
                  AND corpus_annex_revision_id IS NULL
                  AND corpus_annex_hash IS NULL
            ");
            $inherit->execute([
                (int)$parentProjection['id'],
                (string)$parentProjection['revision_hash'],
                (int)$score['id'],
            ]);
            if ($inherit->rowCount() !== 1) {
                $current = recipeScoreRevision(
                    $db,
                    (int)$score['id']
                );
                if (
                    $current === null
                    || (int)($current[
                        'corpus_annex_revision_id'
                    ] ?? 0) !== (int)$parentProjection['id']
                    || !hash_equals(
                        (string)($current[
                            'corpus_annex_hash'
                        ] ?? ''),
                        (string)$parentProjection['revision_hash']
                    )
                ) {
                    return null;
                }
            }
            return $parentProjection;
        }
    }
    if (
        !in_array(
            (string)($score['status'] ?? ''),
            ['building', 'ready'],
            true
        )
        || (string)($score['scoring_model'] ?? '')
            !== INGREDIENT_ONTOLOGY_V3_SCORING_MODEL
        || (int)($score['ontology_version_id'] ?? 0) <= 0
    ) {
        return null;
    }
    $ownsTransaction = !databaseTransactionIsActive($db);
    if ($ownsTransaction) {
        dbBeginImmediateWithRetry($db);
    }
    $publicationGuard =
        ingredientOntologyV3PublicationGuardEnabled($db);
    try {
        ingredientOntologyV3SetPublicationGuard($db, true);
        $root = ingredientOntologyV3CorpusAnnexCreateCheckpointRoot(
            $db,
            $score
        );
        if ($root === null) {
            if ($ownsTransaction) {
                $db->exec('ROLLBACK');
            }
            return null;
        }
        $db->prepare("
            UPDATE ingredient_ontology_corpus_annex_revisions
            SET status = 'ready', ready_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status = 'building'
        ")->execute([(int)$root['id']]);
        $root = ingredientOntologyV3CorpusAnnexRevision(
            $db,
            (int)$root['id']
        );
        if ($root === null || (string)$root['status'] !== 'ready') {
            throw new RuntimeException(
                'corpus projection root publication failed'
            );
        }
        if ((string)$score['status'] === 'building') {
            $db->prepare("
                UPDATE recipe_score_revisions
                SET corpus_annex_revision_id = ?,
                    corpus_annex_hash = ?
                WHERE id = ? AND status = 'building'
            ")->execute([
                (int)$root['id'],
                (string)$root['revision_hash'],
                (int)$score['id'],
            ]);
        } else {
            $state = recipeScoreState($db);
            if (
                (int)($state['active_score_revision_id'] ?? 0)
                    !== (int)$score['id']
            ) {
                throw new RuntimeException(
                    'ready score corpus projection migration requires '
                    . 'the active score'
                );
            }
            ingredientOntologyV3CorpusAnnexRebuildEffectiveProjection(
                $db,
                $root
            );
            ingredientOntologyV3CorpusAnnexZeroScoreChild(
                $db,
                $score,
                $root
            );
        }
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
        recipeScoreReadRevisionCacheClear();
        return $root;
    } catch (Throwable $error) {
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $error;
    } finally {
        ingredientOntologyV3SetPublicationGuard(
            $db,
            $publicationGuard
        );
    }
}

function ingredientOntologyV3CorpusAnnexSeedActiveRoot(
    PDO $db
): ?array {
    if (
        databaseTransactionIsActive($db)
        ||
        !ingredientOntologyV3CorpusAnnexTableExists($db)
        || !ingredientOntologyV3TableExists(
            $db,
            'recipe_score_state'
        )
    ) {
        return null;
    }
    $score = recipeScoreActiveRevision($db);
    if ($score === null) {
        return null;
    }
    return ingredientOntologyV3CorpusAnnexEnsureScoreRoot(
        $db,
        $score
    );
}

function ingredientOntologyV3CompactCorpusProjection(
    PDO $db,
    bool $force = false
): array {
    $started = hrtime(true);
    $lock = recipeScoreAcquireLock($db);
    if ($lock === false) {
        return ['compacted' => false, 'reason' => 'locked'];
    }
    $transactionStarted = false;
    $publicationGuard =
        ingredientOntologyV3PublicationGuardEnabled($db);
    try {
        $parent = recipeScoreActiveRevision($db);
        if (
            $parent === null
            || (int)($parent['ontology_version_id'] ?? 0) <= 0
        ) {
            return [
                'compacted' => false,
                'reason' => 'active_revision_missing',
            ];
        }
        $pin = ingredientOntologyV3CorpusAnnexForScore($db, $parent);
        if ($pin === null) {
            return [
                'compacted' => false,
                'reason' => 'corpus_annex_repair_required',
            ];
        }
        $lineage = ingredientOntologyV3CorpusProjectionLineageAudit(
            $db,
            (int)$pin['id'],
            (string)$pin['revision_hash']
        );
        if (empty($lineage['valid'])) {
            return [
                'compacted' => false,
                'reason' => 'corpus_annex_repair_required',
                'errors' => (array)$lineage['errors'],
            ];
        }
        $deltaEntryCount = max(
            0,
            (int)$lineage['entry_count']
                - (int)($lineage['root']['entry_count'] ?? 0)
        );
        if (
            !$force
            && (int)$lineage['depth']
                < ingredientOntologyV3CorpusAnnexCompactionDepth()
            && $deltaEntryCount
                < ingredientOntologyV3CorpusAnnexCompactionEntryLimit()
        ) {
            return [
                'compacted' => false,
                'reason' => 'not_due',
                'depth' => (int)$lineage['depth'],
                'delta_entry_count' => $deltaEntryCount,
            ];
        }
        dbBeginImmediateWithRetry($db);
        $transactionStarted = true;
        ingredientOntologyV3SetPublicationGuard($db, true);
        $lockedParent = recipeScoreActiveRevision($db);
        $lockedState = recipeScoreState($db);
        $lockedPin = $lockedParent !== null
            ? ingredientOntologyV3CorpusAnnexForScore(
                $db,
                $lockedParent
            )
            : null;
        if (
            $lockedParent === null
            || (int)$lockedParent['id'] !== (int)$parent['id']
            || $lockedPin === null
            || (int)$lockedPin['id'] !== (int)$pin['id']
            || !hash_equals(
                (string)$lockedPin['revision_hash'],
                (string)$pin['revision_hash']
            )
        ) {
            throw new RuntimeException(
                'corpus projection compaction publication fence changed'
            );
        }
        ingredientOntologyV3CorpusAnnexEnsureProjection(
            $db,
            $lockedPin
        );
        $deepAudit =
            ingredientOntologyV3CorpusProjectionIntegrityAuditV2(
            $db,
            (int)$lockedPin['id'],
            (string)$lockedPin['revision_hash'],
            false,
            [],
            false
        );
        if (empty($deepAudit['valid'])) {
            throw new RuntimeException(
                'corpus projection compaction refused invalid evidence: '
                    . implode('; ', (array)$deepAudit['errors'])
            );
        }
        $root =
            ingredientOntologyV3CorpusAnnexCreateRolloverCheckpoint(
                $db,
                $lockedPin
            );
        $db->prepare("
            UPDATE ingredient_ontology_corpus_annex_revisions
            SET status = 'ready',
                ready_at = CURRENT_TIMESTAMP,
                last_error = ''
            WHERE id = ? AND status = 'building'
        ")->execute([(int)$root['id']]);
        $root = ingredientOntologyV3CorpusAnnexRevision(
            $db,
            (int)$root['id']
        );
        if ($root === null || (string)$root['status'] !== 'ready') {
            throw new RuntimeException(
                'corpus projection compaction root publication failed'
            );
        }
        $rootAudit =
            ingredientOntologyV3CorpusProjectionIntegrityAuditV2(
            $db,
            (int)$root['id'],
            (string)$root['revision_hash'],
            false,
            [],
            false
        );
        if (empty($rootAudit['valid'])) {
            throw new RuntimeException(
                'corpus projection compaction root is invalid: '
                    . implode('; ', (array)$rootAudit['errors'])
            );
        }
        $projectionDelta =
            ingredientOntologyV3CorpusAnnexApplyRevisionEntries(
            $db,
            $root,
            false
        );
        $projectionCounts =
            ingredientOntologyV3CorpusAnnexProjectionCountsAfterDelta(
                $db,
                (int)$root['ontology_version_id'],
                $projectionDelta
            );
        ingredientOntologyV3CorpusAnnexSetProjectionState(
            $db,
            $root,
            $projectionCounts
        );
        $child = ingredientOntologyV3CorpusAnnexZeroScoreChild(
            $db,
            $lockedParent,
            $root
        );
        if (function_exists(
            'ingredientOntologyV3IdentityProjectionRebaseHead'
        )) {
            ingredientOntologyV3IdentityProjectionRebaseHead(
                $db,
                $lockedPin,
                $root
            );
        }
        $db->exec('COMMIT');
        $transactionStarted = false;
        recipeScoreReadRevisionCacheClear();
        $workStateWarning = null;
        try {
            recipeScoreReconcileWorkState($db);
        } catch (Throwable $error) {
            $workStateWarning = mb_substr(
                $error->getMessage(),
                0,
                1000,
                'UTF-8'
            );
        }
        $result = [
            'compacted' => true,
            'score_revision_id' => (int)$child['id'],
            'parent_score_revision_id' => (int)$lockedParent['id'],
            'corpus_annex_revision_id' => (int)$root['id'],
            'corpus_annex_hash' => (string)$root['revision_hash'],
            'previous_corpus_annex_revision_id' =>
                (int)$lockedPin['id'],
            'previous_depth' => (int)$lineage['depth'],
            'previous_delta_entry_count' => $deltaEntryCount,
            'entry_count' => (int)$root['entry_count'],
            'aggregate_count' => (int)$root['aggregate_count'],
            'effective_projection_hash' =>
                (string)$root['projection_root_hash'],
            'elapsed_ms' => round(
                (hrtime(true) - $started) / 1000000,
                3
            ),
        ];
        if ($workStateWarning !== null) {
            $result['cleanup_warning'] = $workStateWarning;
        }
        return $result;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        return [
            'compacted' => false,
            'reason' => 'failed',
            'error' => mb_substr(
                $error->getMessage(),
                0,
                1000,
                'UTF-8'
            ),
        ];
    } finally {
        ingredientOntologyV3SetPublicationGuard(
            $db,
            $publicationGuard
        );
        recipeScoreReleaseLock($lock);
    }
}

function ingredientOntologyV3CorpusProjectionLineageAudit(
    PDO $db,
    int $revisionId,
    string $expectedHash = ''
): array {
    try {
        $chain = ingredientOntologyV3CorpusAnnexChain(
            $db,
            $revisionId
        );
    } catch (Throwable $error) {
        return [
            'valid' => false,
            'errors' => [$error->getMessage()],
            'chain' => [],
            'depth' => 0,
            'entry_count' => 0,
            'aggregate_count' => 0,
            'head' => null,
            'root' => null,
        ];
    }
    $errors = [];
    $root = $chain[0] ?? null;
    $previous = null;
    $entryCount = 0;
    $aggregateCount = 0;
    foreach ($chain as $revision) {
        $checkpointSource =
            $previous === null
                ? ingredientOntologyV3CorpusAnnexCheckpointSource(
                    $revision
                )
                : null;
        if (
            (string)$revision['status'] !== 'ready'
            || !hash_equals(
                ingredientOntologyV3CorpusAnnexRevisionHash(
                    $revision
                ),
                (string)$revision['revision_hash']
            )
        ) {
            $errors[] = 'corpus projection revision hash changed';
            break;
        }
        $mode = (string)$revision['reconciliation_mode'];
        if ($previous === null) {
            if (
                $revision['parent_revision_id'] !== null
                || !hash_equals(
                    ingredientOntologyV3CorpusAnnexZeroHash(),
                    (string)$revision['parent_revision_hash']
                )
                || $mode !== 'checkpoint'
                || (
                    $checkpointSource === null
                    && (int)$revision[
                        'captured_ontology_source_revision'
                    ] !== (int)$revision[
                        'covered_ontology_source_revision'
                    ]
                )
            ) {
                $errors[] = 'corpus projection checkpoint is invalid';
                break;
            }
        } elseif (
            (int)$revision['parent_revision_id']
                !== (int)$previous['id']
            || !hash_equals(
                (string)$revision['parent_revision_hash'],
                (string)$previous['revision_hash']
            )
            || (int)$revision['ontology_version_id']
                !== (int)$root['ontology_version_id']
            || !hash_equals(
                (string)$revision['ontology_content_hash'],
                (string)$root['ontology_content_hash']
            )
            || !hash_equals(
                (string)$revision['ontology_seal_hash'],
                (string)$root['ontology_seal_hash']
            )
            || !hash_equals(
                (string)$revision['resolution_input_hash'],
                (string)$root['resolution_input_hash']
            )
            || !hash_equals(
                (string)$revision['base_corpus_hash'],
                (string)$root['base_corpus_hash']
            )
            || (int)$revision['base_products_max_id']
                !== (int)$root['base_products_max_id']
            || (int)$revision['base_recipe_catalog_max_id']
                !== (int)$root['base_recipe_catalog_max_id']
            || (int)$revision['base_recipe_origins_max_id']
                !== (int)$root['base_recipe_origins_max_id']
            || (int)$revision['base_recipe_ingredients_max_id']
                !== (int)$root['base_recipe_ingredients_max_id']
            || (int)$revision[
                'base_recipe_source_ingredients_max_id'
            ] !== (int)$root[
                'base_recipe_source_ingredients_max_id'
            ]
            || (int)$revision[
                'captured_ontology_source_revision'
            ] < (int)$previous[
                'captured_ontology_source_revision'
            ]
            || (int)$revision[
                'covered_ontology_source_revision'
            ] < (int)$previous[
                'covered_ontology_source_revision'
            ]
            || (int)$revision[
                'covered_ontology_source_revision'
            ] > (int)$revision[
                'captured_ontology_source_revision'
            ]
            || !in_array($mode, ['journal', 'authoritative'], true)
            || (int)$revision['identity_extension_revision']
                < (int)$previous['identity_extension_revision']
            || (int)$revision[
                'covered_identity_extension_revision'
            ] < (int)$previous[
                'covered_identity_extension_revision'
            ]
            || (int)$revision[
                'covered_identity_extension_revision'
            ] > (int)$revision['identity_extension_revision']
        ) {
            $errors[] = 'corpus projection parent chain changed';
            break;
        }
        $manifest = json_decode(
            (string)$revision['mutation_manifest_json'],
            true
        );
        if (
            !is_array($manifest)
            || !hash_equals(
                ingredientOntologyV3Hash([
                    'algorithm' =>
                        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                            . ':mutation-manifest',
                    'payload' => $manifest,
                ]),
                (string)$revision['mutation_manifest_hash']
            )
        ) {
            $errors[] =
                'corpus projection mutation manifest changed';
            break;
        }
        $manifestMode = (string)($manifest['mode'] ?? '');
        $manifestFrom = (int)($manifest['from_revision'] ?? -1);
        $manifestThrough =
            (int)($manifest['through_revision'] ?? -1);
        $manifestEvents = (array)($manifest['events'] ?? []);
        $manifestKeys = array_values(array_filter(
            (array)($manifest['aggregate_keys'] ?? []),
            'is_string'
        ));
        $sortedKeys = $manifestKeys;
        sort($sortedKeys, SORT_STRING);
        if (
            count($manifestKeys)
                !== count(array_unique($manifestKeys))
            || $manifestKeys !== $sortedKeys
            || (
                ($previous !== null || $checkpointSource !== null)
                && count($manifestKeys)
                    !== (int)$revision['aggregate_count']
            )
        ) {
            $errors[] =
                'corpus projection aggregate manifest changed';
            break;
        }
        if ($previous === null && $checkpointSource === null) {
            if (
                $manifestMode !== 'checkpoint'
                || $manifestFrom !== (int)$revision[
                    'covered_ontology_source_revision'
                ]
                || $manifestThrough !== $manifestFrom
                || $manifestEvents
                || $manifestKeys
            ) {
                $errors[] =
                    'corpus projection checkpoint manifest changed';
                break;
            }
        } elseif ($previous === null) {
            $source = ingredientOntologyV3CorpusAnnexRevision(
                $db,
                (int)$checkpointSource['revision_id']
            );
            $deltaMode = (string)($manifest['delta_mode'] ?? '');
            $manifestContinuation =
                $manifest['continuation'] ?? null;
            if (
                $source === null
                || (string)$source['status'] !== 'ready'
                || !hash_equals(
                    ingredientOntologyV3CorpusAnnexRevisionHash(
                        $source
                    ),
                    (string)$source['revision_hash']
                )
                || !hash_equals(
                    (string)$source['revision_hash'],
                    (string)$checkpointSource['revision_hash']
                )
                || $manifestMode !== 'checkpoint'
                || !in_array(
                    $deltaMode,
                    ['journal', 'authoritative'],
                    true
                )
                || $manifestFrom !== (int)$source[
                    'covered_ontology_source_revision'
                ]
                || $manifestThrough < $manifestFrom
                || $manifestThrough > (int)$revision[
                    'captured_ontology_source_revision'
                ]
                || !in_array(
                    (int)$revision[
                        'covered_ontology_source_revision'
                    ],
                    [$manifestFrom, $manifestThrough],
                    true
                )
                || (
                    (int)$revision[
                        'covered_ontology_source_revision'
                    ] === $manifestFrom
                    && $manifestThrough > $manifestFrom
                    && !is_array($manifestContinuation)
                )
                || (
                    (int)$revision[
                        'covered_ontology_source_revision'
                    ] === $manifestThrough
                    && is_array($manifestContinuation)
                )
                || (int)$source['ontology_version_id']
                    !== (int)$revision['ontology_version_id']
                || !hash_equals(
                    (string)$source['ontology_content_hash'],
                    (string)$revision['ontology_content_hash']
                )
                || !hash_equals(
                    (string)$source['ontology_seal_hash'],
                    (string)$revision['ontology_seal_hash']
                )
                || !hash_equals(
                    (string)$source['resolution_input_hash'],
                    (string)$revision['resolution_input_hash']
                )
                || !hash_equals(
                    (string)$source['base_corpus_hash'],
                    (string)$revision['base_corpus_hash']
                )
                || (int)$source['base_products_max_id']
                    !== (int)$revision['base_products_max_id']
                || (int)$source['base_recipe_catalog_max_id']
                    !== (int)$revision['base_recipe_catalog_max_id']
                || (int)$source['base_recipe_origins_max_id']
                    !== (int)$revision['base_recipe_origins_max_id']
                || (int)$source['base_recipe_ingredients_max_id']
                    !== (int)$revision['base_recipe_ingredients_max_id']
                || (int)$source[
                    'base_recipe_source_ingredients_max_id'
                ] !== (int)$revision[
                    'base_recipe_source_ingredients_max_id'
                ]
            ) {
                $errors[] =
                    'corpus projection rollover checkpoint changed';
                break;
            }
            $capturedHash = ingredientOntologyV3Hash([
                'algorithm' =>
                    INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                        . ':source-lineage',
                'parent' => (string)$source['captured_corpus_hash'],
                'from_revision' => $manifestFrom,
                'through_revision' => $manifestThrough,
                'captured_revision' => (int)$revision[
                    'captured_ontology_source_revision'
                ],
                'covered_revision' => (int)$revision[
                    'covered_ontology_source_revision'
                ],
                'manifest_hash' =>
                    (string)$revision['mutation_manifest_hash'],
                'entry_set_hash' =>
                    (string)$revision['entry_set_hash'],
            ]);
            $projectionRootHash = ingredientOntologyV3Hash([
                'algorithm' =>
                    INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                        . ':projection-lineage',
                'parent' => (string)$source['projection_root_hash'],
                'manifest_hash' =>
                    (string)$revision['mutation_manifest_hash'],
                'entry_set_hash' =>
                    (string)$revision['entry_set_hash'],
            ]);
            if (
                !hash_equals(
                    $capturedHash,
                    (string)$revision['captured_corpus_hash']
                )
                || !hash_equals(
                    $projectionRootHash,
                    (string)$revision['projection_root_hash']
                )
            ) {
                $errors[] =
                    'corpus projection rollover lineage changed';
                break;
            }
        } else {
            $manifestContinuation =
                $manifest['continuation'] ?? null;
            if (
                $manifestMode !== $mode
                || $manifestFrom !== (int)$previous[
                    'covered_ontology_source_revision'
                ]
                || $manifestThrough < $manifestFrom
                || $manifestThrough > (int)$revision[
                    'captured_ontology_source_revision'
                ]
                || !in_array(
                    (int)$revision[
                        'covered_ontology_source_revision'
                    ],
                    [$manifestFrom, $manifestThrough],
                    true
                )
                || (
                    (int)$revision[
                        'covered_ontology_source_revision'
                    ] === $manifestFrom
                    && $manifestThrough > $manifestFrom
                    && !is_array($manifestContinuation)
                )
                || (
                    (int)$revision[
                        'covered_ontology_source_revision'
                    ] === $manifestThrough
                    && is_array($manifestContinuation)
                )
            ) {
                $errors[] =
                    'corpus projection mutation coverage changed';
                break;
            }
            if ($manifestEvents) {
                $expectedEventRevision = $manifestFrom + 1;
                foreach ($manifestEvents as $event) {
                    if (
                        !is_array($event)
                        || (int)($event['revision'] ?? -1)
                            !== $expectedEventRevision++
                    ) {
                        $errors[] =
                            'corpus projection journal sequence changed';
                        break 2;
                    }
                }
                if (
                    count($manifestEvents)
                        !== $manifestThrough - $manifestFrom
                ) {
                    $errors[] =
                        'corpus projection journal coverage changed';
                    break;
                }
            }
        }
        $entryCount += (int)$revision['entry_count'];
        $aggregateCount += (int)$revision['aggregate_count'];
        $previous = $revision;
    }
    $head = $chain[count($chain) - 1] ?? null;
    if (
        !$errors
        && $expectedHash !== ''
        && (
            !is_array($head)
            || !hash_equals(
                $expectedHash,
                (string)$head['revision_hash']
            )
        )
    ) {
        $errors[] = 'corpus projection pinned hash changed';
    }
    if (!$errors && is_array($head)) {
        if (!ingredientOntologyV3IdentityExtensionSnapshotMatches(
            $db,
            (int)$head['ontology_version_id'],
            [
                'revision' =>
                    (int)$head['identity_extension_revision'],
                'hash' => (string)$head['identity_extension_hash'],
            ]
        )) {
            $errors[] =
                'corpus projection identity extension changed';
        }
    }
    return [
        'valid' => !$errors,
        'errors' => $errors,
        'chain' => $chain,
        'depth' => max(0, count($chain) - 1),
        'entry_count' => $entryCount,
        'aggregate_count' => $aggregateCount,
        'head' => $head,
        'root' => $root,
    ];
}

function ingredientOntologyV3CorpusAnnexIntegrityAudit(
    PDO $db,
    int $revisionId,
    string $expectedHash = '',
    bool $verifyCurrentRows = false
): array {
    return ingredientOntologyV3CorpusProjectionIntegrityAuditV2(
        $db,
        $revisionId,
        $expectedHash,
        $verifyCurrentRows
    );
}

function ingredientOntologyV3CorpusProjectionIntegrityAuditV2(
    PDO $db,
    int $revisionId,
    string $expectedHash = '',
    bool $verifyCurrentRows = false,
    array $checkpointSeen = [],
    bool $followCheckpointSources = true
): array {
    if (!$checkpointSeen && $followCheckpointSources) {
        ingredientOntologyV3TrackCorpusOperation(
            'corpus_annex_deep_audit',
            true
        );
    } elseif (!$checkpointSeen) {
        ingredientOntologyV3TrackCorpusOperation(
            'corpus_annex_segment_audit'
        );
    }
    $checkpointEntryCount = 0;
    $checkpointAggregateCount = 0;
    $materializationDepth = 0;
    if ($followCheckpointSources) {
        try {
            $materializationSeen = [];
            $materialization =
                ingredientOntologyV3CorpusAnnexMaterializationChain(
                    $db,
                    $revisionId,
                    $materializationSeen
                );
        } catch (Throwable $error) {
            return [
                'valid' => false,
                'errors' => [$error->getMessage()],
                'chain' => [],
                'depth' => 0,
                'entry_count' => 0,
                'aggregate_count' => 0,
            ];
        }
        $materializationDepth = max(
            0,
            count($materialization) - 1
        );
        $identityAuditRevision = 0;
        $identityAuditHash =
            ingredientOntologyV3IdentityExtensionZeroHash();
        $identityAuditVersionId = 0;
        foreach ($materialization as $revision) {
            if (
                (int)$revision['identity_extension_revision']
                    >= $identityAuditRevision
            ) {
                $identityAuditRevision =
                    (int)$revision['identity_extension_revision'];
                $identityAuditHash =
                    (string)$revision['identity_extension_hash'];
                $identityAuditVersionId =
                    (int)$revision['ontology_version_id'];
            }
        }
        $identityAudit =
            ingredientOntologyV3IdentityExtensionIntegrityAudit(
                $db,
                $identityAuditVersionId,
                $identityAuditRevision,
                $identityAuditHash
            );
        if (empty($identityAudit['valid'])) {
            return [
                'valid' => false,
                'errors' => [
                    'corpus projection identity extension changed',
                ],
                'chain' => [],
                'depth' => 0,
                'entry_count' => 0,
                'aggregate_count' => 0,
            ];
        }
        $segmentHeads = [];
        foreach ($materialization as $index => $revision) {
            $next = $materialization[$index + 1] ?? null;
            if (
                $next === null
                || $next['parent_revision_id'] === null
            ) {
                $segmentHeads[] = $revision;
            }
        }
        foreach ($segmentHeads as $segmentHead) {
            if ((int)$segmentHead['id'] === $revisionId) {
                continue;
            }
            $segmentAudit =
                ingredientOntologyV3CorpusProjectionIntegrityAuditV2(
                    $db,
                    (int)$segmentHead['id'],
                    (string)$segmentHead['revision_hash'],
                    false,
                    [],
                    false
                );
            if (empty($segmentAudit['valid'])) {
                return [
                    'valid' => false,
                    'errors' => array_map(
                        static fn(string $error): string =>
                            'checkpoint source: ' . $error,
                        (array)$segmentAudit['errors']
                    ),
                    'chain' => [],
                    'depth' => 0,
                    'entry_count' => 0,
                    'aggregate_count' => 0,
                ];
            }
            $checkpointEntryCount +=
                (int)$segmentAudit['entry_count'];
            $checkpointAggregateCount +=
                (int)$segmentAudit['aggregate_count'];
        }
    }
    try {
        $chain = ingredientOntologyV3CorpusAnnexChain(
            $db,
            $revisionId
        );
    } catch (Throwable $error) {
        return [
            'valid' => false,
            'errors' => [$error->getMessage()],
            'chain' => [],
            'depth' => 0,
            'entry_count' => 0,
            'aggregate_count' => 0,
        ];
    }
    foreach ($chain as $revision) {
        $chainRevisionId = (int)$revision['id'];
        if (isset($checkpointSeen[$chainRevisionId])) {
            return [
                'valid' => false,
                'errors' => [
                    'corpus projection checkpoint references are invalid',
                ],
                'chain' => $chain,
                'depth' => max(0, count($chain) - 1),
                'entry_count' => 0,
                'aggregate_count' => 0,
            ];
        }
        $checkpointSeen[$chainRevisionId] = true;
    }
    $errors = [];
    $root = $chain[0] ?? null;
    $previous = null;
    $totalEntries = 0;
    $totalAggregates = 0;
    foreach ($chain as $revision) {
        $checkpointSource =
            $previous === null
                ? ingredientOntologyV3CorpusAnnexCheckpointSource(
                    $revision
                )
                : null;
        if (
            (string)$revision['status'] !== 'ready'
            || !hash_equals(
                ingredientOntologyV3CorpusAnnexRevisionHash(
                    $revision
                ),
                (string)$revision['revision_hash']
            )
        ) {
            $errors[] = 'corpus projection revision hash changed';
            break;
        }
        $mode = (string)$revision['reconciliation_mode'];
        if ($previous === null) {
            if (
                $revision['parent_revision_id'] !== null
                || !hash_equals(
                    ingredientOntologyV3CorpusAnnexZeroHash(),
                    (string)$revision['parent_revision_hash']
                )
                || $mode !== 'checkpoint'
                || (
                    $checkpointSource === null
                    && (int)$revision[
                        'captured_ontology_source_revision'
                    ] !== (int)$revision[
                        'covered_ontology_source_revision'
                    ]
                )
            ) {
                $errors[] = 'corpus projection checkpoint is invalid';
                break;
            }
        } elseif (
            (int)$revision['parent_revision_id']
                !== (int)$previous['id']
            || !hash_equals(
                (string)$revision['parent_revision_hash'],
                (string)$previous['revision_hash']
            )
            || (int)$revision['ontology_version_id']
                !== (int)$root['ontology_version_id']
            || !hash_equals(
                (string)$revision['ontology_content_hash'],
                (string)$root['ontology_content_hash']
            )
            || !hash_equals(
                (string)$revision['ontology_seal_hash'],
                (string)$root['ontology_seal_hash']
            )
            || !hash_equals(
                (string)$revision['resolution_input_hash'],
                (string)$root['resolution_input_hash']
            )
            || !hash_equals(
                (string)$revision['base_corpus_hash'],
                (string)$root['base_corpus_hash']
            )
            || (int)$revision['base_products_max_id']
                !== (int)$root['base_products_max_id']
            || (int)$revision['base_recipe_catalog_max_id']
                !== (int)$root['base_recipe_catalog_max_id']
            || (int)$revision['base_recipe_origins_max_id']
                !== (int)$root['base_recipe_origins_max_id']
            || (int)$revision['base_recipe_ingredients_max_id']
                !== (int)$root['base_recipe_ingredients_max_id']
            || (int)$revision[
                'base_recipe_source_ingredients_max_id'
            ] !== (int)$root[
                'base_recipe_source_ingredients_max_id'
            ]
            || (int)$revision[
                'captured_ontology_source_revision'
            ] < (int)$previous[
                'captured_ontology_source_revision'
            ]
            || (int)$revision[
                'covered_ontology_source_revision'
            ] < (int)$previous[
                'covered_ontology_source_revision'
            ]
            || (int)$revision[
                'covered_ontology_source_revision'
            ] > (int)$revision[
                'captured_ontology_source_revision'
            ]
            || !in_array($mode, ['journal', 'authoritative'], true)
            || (int)$revision['identity_extension_revision']
                    < (int)$previous['identity_extension_revision']
            || (int)$revision[
                    'covered_identity_extension_revision'
            ] < (int)$previous[
                    'covered_identity_extension_revision'
            ]
            || (int)$revision[
                    'covered_identity_extension_revision'
            ] > (int)$revision['identity_extension_revision']
        ) {
            $errors[] = 'corpus projection parent chain changed';
            break;
        }

        $count = 0;
        $expectedOrdinal = 1;
        $aggregateCount = 0;
        $groupKeys = [];
        $group = null;
        $sourceHash = null;
        $projectionHash = null;
        $entrySetHash = hash_init('sha256');
        hash_update(
            $entrySetHash,
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                . ":entry-set\n"
        );
        if ($previous === null && $checkpointSource === null) {
            $sourceHash = hash_init('sha256');
            $projectionHash = hash_init('sha256');
            hash_update(
                $sourceHash,
                INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                    . ":aggregate-source-set\n"
            );
            hash_update(
                $projectionHash,
                INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                    . ":aggregate-state-set\n"
            );
        }
        $finishGroup = static function (?array $current) use (
            &$errors,
            &$aggregateCount,
            &$groupKeys,
            $previous,
            $checkpointSource,
            $sourceHash,
            $projectionHash
        ): bool {
            if ($current === null) {
                return true;
            }
            if (
                (int)$current['header_count'] !== 1
                || (
                    $current['operation'] === 'delete'
                    && (
                        (int)$current['entry_count'] !== 1
                        || (int)$current['member_count'] !== 0
                    )
                )
                || (
                    $current['operation'] === 'replace'
                    && (int)$current['entry_count']
                        !== (int)$current['member_count']
                )
            ) {
                $errors[] =
                    'corpus projection aggregate membership changed';
                return false;
            }
            $aggregateCount++;
            if ($previous === null && $checkpointSource === null) {
                hash_update(
                    $sourceHash,
                    (string)$current['key'] . "\n"
                        . (string)$current['source_hash']
                        . "\n"
                );
                hash_update(
                    $projectionHash,
                    (string)$current['key'] . "\n"
                        . (string)$current['aggregate_hash']
                        . "\n"
                );
            } else {
                $groupKeys[] = (string)$current['key'];
            }
            return true;
        };
        foreach (
            ingredientOntologyV3CorpusAnnexEntryRows(
                $db,
                (int)$revision['id']
            ) as $entry
        ) {
            $count++;
                hash_update(
                    $entrySetHash,
                    (string)$entry['row_hash'] . "\n"
                );
                $operation = (string)$entry['operation'];
            if (
                (int)$entry['ordinal'] !== $expectedOrdinal++
                || !in_array(
                    $operation,
                    ['replace', 'delete'],
                    true
                )
                || !hash_equals(
                    ingredientOntologyV3CorpusAnnexEntryHash(
                        $entry
                    ),
                    (string)$entry['row_hash']
                )
            ) {
                $errors[] = 'corpus projection entry hash changed';
                break 2;
            }
            if (
                (string)$entry['identity_status'] !== 'accepted'
                && (int)$entry['satisfies_required'] !== 0
            ) {
                $errors[] =
                    'nonaccepted corpus projection identity satisfies';
                break 2;
            }
            $aggregateType =
                (string)$entry['entry_type'] === 'product'
                    ? 'product'
                    : 'recipe';
            $aggregateId = $aggregateType === 'product'
                ? (int)$entry['owner_id']
                : (int)($entry['recipe_id'] ?? 0);
            if ($aggregateId <= 0) {
                $errors[] =
                    'corpus projection aggregate ownership changed';
                break 2;
            }
            $key = ingredientOntologyV3CorpusAnnexAggregateKey(
                $aggregateType,
                $aggregateId
            );
            if (
                $group !== null
                && (string)$group['key'] !== $key
            ) {
                if (!$finishGroup($group)) {
                    break 2;
                }
                $group = null;
            }
            if ($group === null) {
                $group = [
                    'key' => $key,
                    'operation' => $operation,
                    'source_hash' =>
                        (string)$entry['aggregate_source_hash'],
                    'resolution_input_hash' =>
                        (string)$entry['resolution_input_hash'],
                    'aggregate_hash' =>
                        (string)$entry['aggregate_hash'],
                    'member_count' => (int)$entry['member_count'],
                    'entry_count' => 0,
                    'header_count' => 0,
                ];
            }
            if (
                $group['operation'] !== $operation
                || !hash_equals(
                    $group['source_hash'],
                    (string)$entry['aggregate_source_hash']
                )
                || !hash_equals(
                    $group['resolution_input_hash'],
                    (string)$entry['resolution_input_hash']
                )
                || !hash_equals(
                    $group['aggregate_hash'],
                    (string)$entry['aggregate_hash']
                )
                || $group['member_count']
                    !== (int)$entry['member_count']
            ) {
                $errors[] =
                    'corpus projection aggregate evidence changed';
                break 2;
            }
            $group['entry_count']++;
            $isHeader =
                (string)$entry['entry_type'] === 'product'
                || (string)$entry['entry_type'] === 'recipe_scope';
            if ($isHeader) {
                $group['header_count']++;
            }
        }
        if (!$finishGroup($group)) {
            break;
        }
        if (
            $count !== (int)$revision['entry_count']
            || $aggregateCount !== (int)$revision['aggregate_count']
            || !hash_equals(
                hash_final($entrySetHash),
                (string)$revision['entry_set_hash']
            )
        ) {
            $errors[] = 'corpus projection entry set changed';
            break;
        }

        $manifest = json_decode(
            (string)$revision['mutation_manifest_json'],
            true
        );
        if (
            !is_array($manifest)
            || !hash_equals(
                ingredientOntologyV3Hash([
                    'algorithm' =>
                        INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                            . ':mutation-manifest',
                    'payload' => $manifest,
                ]),
                (string)$revision['mutation_manifest_hash']
            )
        ) {
            $errors[] =
                'corpus projection mutation manifest changed';
            break;
        }
        $manifestMode = (string)($manifest['mode'] ?? '');
        $manifestFrom = (int)($manifest['from_revision'] ?? -1);
        $manifestThrough =
            (int)($manifest['through_revision'] ?? -1);
        $manifestEvents = (array)($manifest['events'] ?? []);
        $rawManifestKeys =
            (array)($manifest['aggregate_keys'] ?? []);
        $manifestKeys = array_values(array_filter(
            $rawManifestKeys,
            'is_string'
        ));
        $sortedManifestKeys = $manifestKeys;
        sort($sortedManifestKeys, SORT_STRING);
        if (
            count($manifestKeys) !== count($rawManifestKeys)
            || count($manifestKeys)
                !== count(array_unique($manifestKeys))
            || $manifestKeys !== $sortedManifestKeys
        ) {
            $errors[] =
                'corpus projection aggregate manifest changed';
            break;
        }
        sort($groupKeys, SORT_STRING);
        if ($previous === null && $checkpointSource === null) {
            if (
                $manifestMode !== 'checkpoint'
                || $manifestFrom !== (int)$revision[
                    'covered_ontology_source_revision'
                ]
                || $manifestThrough !== $manifestFrom
                || $manifestEvents
                || $manifestKeys
                || !hash_equals(
                    hash_final($sourceHash),
                    (string)$revision['captured_corpus_hash']
                )
                || !hash_equals(
                    hash_final($projectionHash),
                    (string)$revision['projection_root_hash']
                )
            ) {
                $errors[] =
                    'corpus projection checkpoint evidence changed';
                break;
            }
        } elseif ($previous === null) {
            $source = ingredientOntologyV3CorpusAnnexRevision(
                $db,
                (int)$checkpointSource['revision_id']
            );
            $deltaMode = (string)($manifest['delta_mode'] ?? '');
            $manifestContinuation =
                $manifest['continuation'] ?? null;
            if (
                $source === null
                || (string)$source['status'] !== 'ready'
                || !hash_equals(
                    ingredientOntologyV3CorpusAnnexRevisionHash(
                        $source
                    ),
                    (string)$source['revision_hash']
                )
                || !hash_equals(
                    (string)$source['revision_hash'],
                    (string)$checkpointSource['revision_hash']
                )
                || $manifestMode !== 'checkpoint'
                || !in_array(
                    $deltaMode,
                    ['journal', 'authoritative'],
                    true
                )
                || $manifestFrom !== (int)$source[
                    'covered_ontology_source_revision'
                ]
                || $manifestThrough < $manifestFrom
                || $manifestThrough > (int)$revision[
                    'captured_ontology_source_revision'
                ]
                || !in_array(
                    (int)$revision[
                        'covered_ontology_source_revision'
                    ],
                    [$manifestFrom, $manifestThrough],
                    true
                )
                || (
                    (int)$revision[
                        'covered_ontology_source_revision'
                    ] === $manifestFrom
                    && $manifestThrough > $manifestFrom
                    && !is_array($manifestContinuation)
                )
                || (
                    (int)$revision[
                        'covered_ontology_source_revision'
                    ] === $manifestThrough
                    && is_array($manifestContinuation)
                )
                || $manifestKeys !== $groupKeys
                || (int)$source['ontology_version_id']
                    !== (int)$revision['ontology_version_id']
                || !hash_equals(
                    (string)$source['ontology_content_hash'],
                    (string)$revision['ontology_content_hash']
                )
                || !hash_equals(
                    (string)$source['ontology_seal_hash'],
                    (string)$revision['ontology_seal_hash']
                )
                || !hash_equals(
                    (string)$source['resolution_input_hash'],
                    (string)$revision['resolution_input_hash']
                )
                || !hash_equals(
                    (string)$source['base_corpus_hash'],
                    (string)$revision['base_corpus_hash']
                )
                || (int)$source['base_products_max_id']
                    !== (int)$revision['base_products_max_id']
                || (int)$source['base_recipe_catalog_max_id']
                    !== (int)$revision['base_recipe_catalog_max_id']
                || (int)$source['base_recipe_origins_max_id']
                    !== (int)$revision['base_recipe_origins_max_id']
                || (int)$source['base_recipe_ingredients_max_id']
                    !== (int)$revision['base_recipe_ingredients_max_id']
                || (int)$source[
                    'base_recipe_source_ingredients_max_id'
                ] !== (int)$revision[
                    'base_recipe_source_ingredients_max_id'
                ]
            ) {
                $errors[] =
                    'corpus projection rollover checkpoint evidence changed';
                break;
            }
            $capturedHash = ingredientOntologyV3Hash([
                'algorithm' =>
                    INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                        . ':source-lineage',
                'parent' => (string)$source['captured_corpus_hash'],
                'from_revision' => $manifestFrom,
                'through_revision' => $manifestThrough,
                'captured_revision' => (int)$revision[
                    'captured_ontology_source_revision'
                ],
                'covered_revision' => (int)$revision[
                    'covered_ontology_source_revision'
                ],
                'manifest_hash' =>
                    (string)$revision['mutation_manifest_hash'],
                'entry_set_hash' =>
                    (string)$revision['entry_set_hash'],
            ]);
            $projectionRootHash = ingredientOntologyV3Hash([
                'algorithm' =>
                    INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                        . ':projection-lineage',
                'parent' => (string)$source['projection_root_hash'],
                'manifest_hash' =>
                    (string)$revision['mutation_manifest_hash'],
                'entry_set_hash' =>
                    (string)$revision['entry_set_hash'],
            ]);
            if (
                !hash_equals(
                    $capturedHash,
                    (string)$revision['captured_corpus_hash']
                )
                || !hash_equals(
                    $projectionRootHash,
                    (string)$revision['projection_root_hash']
                )
            ) {
                $errors[] =
                    'corpus projection rollover lineage evidence changed';
                break;
            }
        } else {
            $manifestContinuation =
                $manifest['continuation'] ?? null;
            if (
                $manifestMode !== $mode
                || $manifestFrom !== (int)$previous[
                    'covered_ontology_source_revision'
                ]
                || $manifestThrough < $manifestFrom
                || $manifestThrough > (int)$revision[
                    'captured_ontology_source_revision'
                ]
                || !in_array(
                    (int)$revision[
                        'covered_ontology_source_revision'
                    ],
                    [$manifestFrom, $manifestThrough],
                    true
                )
                || (
                    (int)$revision[
                        'covered_ontology_source_revision'
                    ] === $manifestFrom
                    && $manifestThrough > $manifestFrom
                    && !is_array($manifestContinuation)
                )
                || (
                    (int)$revision[
                        'covered_ontology_source_revision'
                    ] === $manifestThrough
                    && is_array($manifestContinuation)
                )
                || $manifestKeys !== $groupKeys
            ) {
                $errors[] =
                    'corpus projection mutation coverage changed';
                break;
            }
            if ($manifestEvents) {
                $expectedEventRevision = $manifestFrom + 1;
                foreach ($manifestEvents as $event) {
                    if (
                        !is_array($event)
                        || (int)($event['revision'] ?? -1)
                            !== $expectedEventRevision++
                    ) {
                        $errors[] =
                            'corpus projection journal sequence changed';
                        break 2;
                    }
                }
                if (
                    count($manifestEvents)
                        !== $manifestThrough - $manifestFrom
                ) {
                    $errors[] =
                        'corpus projection journal coverage changed';
                    break;
                }
            }
            $capturedHash = ingredientOntologyV3Hash([
                'algorithm' =>
                    INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                        . ':source-lineage',
                'parent' =>
                    (string)$previous['captured_corpus_hash'],
                'from_revision' => $manifestFrom,
                'through_revision' => $manifestThrough,
                'captured_revision' => (int)$revision[
                    'captured_ontology_source_revision'
                ],
                'covered_revision' => (int)$revision[
                    'covered_ontology_source_revision'
                ],
                'manifest_hash' =>
                    (string)$revision['mutation_manifest_hash'],
                'entry_set_hash' =>
                    (string)$revision['entry_set_hash'],
            ]);
            $projectionRootHash = ingredientOntologyV3Hash([
                'algorithm' =>
                    INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                        . ':projection-lineage',
                'parent' =>
                    (string)$previous['projection_root_hash'],
                'manifest_hash' =>
                    (string)$revision['mutation_manifest_hash'],
                'entry_set_hash' =>
                    (string)$revision['entry_set_hash'],
            ]);
            if (
                !hash_equals(
                    $capturedHash,
                    (string)$revision['captured_corpus_hash']
                )
                || !hash_equals(
                    $projectionRootHash,
                    (string)$revision['projection_root_hash']
                )
            ) {
                $errors[] =
                    'corpus projection lineage evidence changed';
                break;
            }
        }
        if (!ingredientOntologyV3IdentityExtensionSnapshotMatches(
            $db,
            (int)$revision['ontology_version_id'],
            [
                'revision' =>
                    (int)$revision['identity_extension_revision'],
                'hash' =>
                    (string)$revision['identity_extension_hash'],
            ]
        )) {
            $errors[] =
                'corpus projection identity extension changed';
            break;
        }
        if (!ingredientOntologyV3IdentityExtensionSnapshotMatches(
            $db,
            (int)$revision['ontology_version_id'],
            [
                'revision' => (int)$revision[
                    'covered_identity_extension_revision'
                ],
                'hash' => (string)$revision[
                    'covered_identity_extension_hash'
                ],
            ]
        )) {
            $errors[] =
                'corpus projection covered identity extension changed';
            break;
        }
        $totalEntries += $count;
        $totalAggregates += $aggregateCount;
        $previous = $revision;
    }
    $head = $chain[count($chain) - 1] ?? null;
    if (
        !$errors
        && $expectedHash !== ''
        && (
            !is_array($head)
            || !hash_equals(
                $expectedHash,
                (string)$head['revision_hash']
            )
        )
    ) {
        $errors[] = 'corpus projection pinned hash changed';
    }
    if (
        !$errors
        && $followCheckpointSources
        && is_array($head)
        && ingredientOntologyV3CorpusAnnexProjectionReady(
            $db,
            $head
        )
    ) {
        $projectionState = $db->prepare("
            SELECT aggregate_count, member_count
            FROM ingredient_ontology_corpus_annex_projection_state
            WHERE ontology_version_id = ?
        ");
        $projectionState->execute([
            (int)$head['ontology_version_id'],
        ]);
        $projectionState =
            $projectionState->fetch(PDO::FETCH_ASSOC);
        $projectionCounts =
            ingredientOntologyV3CorpusAnnexProjectionCounts(
                $db,
                (int)$head['ontology_version_id']
            );
        if (
            !is_array($projectionState)
            || (int)$projectionState['aggregate_count']
                !== (int)$projectionCounts['aggregate_count']
            || (int)$projectionState['member_count']
                !== (int)$projectionCounts['member_count']
        ) {
            $errors[] =
                'corpus projection materialized counts changed';
        }
    }
    if (
        !$errors
        && $verifyCurrentRows
        && is_array($head)
    ) {
        $active = recipeScoreActiveRevision($db);
        if (
            $active !== null
            && (int)($active['corpus_annex_revision_id'] ?? 0)
                === (int)$head['id']
        ) {
            $ownsTransaction = !databaseTransactionIsActive($db);
            $savepoint = 'corpus_projection_audit_'
                . (int)$head['id'];
            $reservationStarted = false;
            try {
                if ($ownsTransaction) {
                    dbBeginImmediateWithRetry($db);
                } else {
                    $db->exec('SAVEPOINT ' . $savepoint);
                }
                $reservationStarted = true;
                $beforeHash =
                    ingredientOntologyV3CorpusAnnexEffectiveProjectionHash(
                        $db,
                        (int)$head['ontology_version_id']
                    );
                ingredientOntologyV3CorpusAnnexRebuildEffectiveProjection(
                    $db,
                    $head
                );
                $afterHash =
                    ingredientOntologyV3CorpusAnnexEffectiveProjectionHash(
                        $db,
                        (int)$head['ontology_version_id']
                    );
                if ($ownsTransaction) {
                    $db->exec('ROLLBACK');
                } else {
                    $db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $db->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
                $reservationStarted = false;
                if (!hash_equals($beforeHash, $afterHash)) {
                    $errors[] =
                        'active corpus projection materialization is stale';
                }
            } catch (Throwable $error) {
                if ($reservationStarted) {
                    try {
                        if ($ownsTransaction) {
                            $db->exec('ROLLBACK');
                        } else {
                            $db->exec(
                                'ROLLBACK TO SAVEPOINT ' . $savepoint
                            );
                            $db->exec(
                                'RELEASE SAVEPOINT ' . $savepoint
                            );
                        }
                    } catch (Throwable $ignored) {
                    }
                }
                $errors[] = 'active corpus projection verification failed: '
                    . $error->getMessage();
            }
        }
    }
    return [
        'valid' => !$errors,
        'errors' => $errors,
        'chain' => $chain,
        'depth' => max(0, count($chain) - 1),
        'entry_count' => $totalEntries,
        'aggregate_count' => $totalAggregates,
        'transitive_entry_count' =>
            $checkpointEntryCount + $totalEntries,
        'transitive_aggregate_count' =>
            $checkpointAggregateCount + $totalAggregates,
        'materialization_depth' => $followCheckpointSources
            ? $materializationDepth
            : max(0, count($chain) - 1),
        'head' => $head,
        'root' => $root,
    ];
}

function ingredientOntologyV3CorpusAnnexCreateChild(
    PDO $db,
    array $parentScore,
    array $state,
    ?array $preparedPlan = null
): array {
    $plan = $preparedPlan
        ?? ingredientOntologyV3CorpusAnnexClassifySuffix(
            $db,
            $parentScore,
            $state,
            true
        );
    if (empty($plan['eligible'])) {
        throw new RuntimeException(
            'corpus projection classification failed: '
            . implode('; ', (array)($plan['errors'] ?? []))
        );
    }
    if (
        (int)$plan['covered_revision']
            > (int)$plan['from_revision']
        && empty($plan['selection_complete'])
    ) {
        throw new RuntimeException(
            'corpus projection cannot advance coverage from a partial selection'
        );
    }
    $parent = (array)$plan['parent'];
    if (
        (int)$plan['captured_revision']
            === (int)$parent[
                'captured_ontology_source_revision'
            ]
        && (int)$plan['covered_revision']
            === (int)$parent[
                'covered_ontology_source_revision'
            ]
        && !(array)$plan['entries']
        && (int)$plan['identity_extension']['revision']
            === (int)$parent['identity_extension_revision']
        && hash_equals(
            (string)$plan['identity_extension']['hash'],
            (string)$parent['identity_extension_hash']
        )
        && (int)$plan['covered_identity_extension']['revision']
            === (int)$parent[
                'covered_identity_extension_revision'
            ]
        && hash_equals(
            (string)$plan['covered_identity_extension']['hash'],
            (string)$parent['covered_identity_extension_hash']
        )
        && hash_equals(
            (string)$plan['mutation_manifest_hash'],
            (string)$parent['mutation_manifest_hash']
        )
    ) {
        return [
            'created' => false,
            'revision' => $parent,
            'plan' => $plan,
        ];
    }
    $root = (array)$plan['root'];
    $parentChain = ingredientOntologyV3CorpusAnnexChain(
        $db,
        (int)$parent['id']
    );
    $rollover =
        max(0, count($parentChain) - 1)
            >= ingredientOntologyV3CorpusAnnexCompactionDepth();
    if ($rollover) {
        $plan['rollover_source_plan'] = array_intersect_key(
            $plan,
            array_fill_keys([
                'mutation_manifest_hash',
                'entry_set_hash',
                'captured_corpus_hash',
                'projection_root_hash',
                'resolution_input_hash',
            ], true)
        );
        $manifestPayload = json_decode(
            (string)$plan['mutation_manifest_json'],
            true
        );
        if (!is_array($manifestPayload)) {
            throw new RuntimeException(
                'corpus projection rollover manifest is invalid'
            );
        }
        $manifestPayload['delta_mode'] =
            (string)$plan['reconciliation_mode'];
        $manifestPayload['mode'] = 'checkpoint';
        $manifestPayload['checkpoint_source'] = [
            'revision_id' => (int)$parent['id'],
            'revision_hash' => (string)$parent['revision_hash'],
        ];
        $plan['mutation_manifest_json'] =
            ingredientOntologyV3Json($manifestPayload);
        $plan['mutation_manifest_hash'] =
            ingredientOntologyV3Hash([
                'algorithm' =>
                    INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                        . ':mutation-manifest',
                'payload' => $manifestPayload,
            ]);
        $plan['captured_corpus_hash'] =
            ingredientOntologyV3Hash([
                'algorithm' =>
                    INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                        . ':source-lineage',
                'parent' => (string)$parent['captured_corpus_hash'],
                'from_revision' => (int)$plan['from_revision'],
                'through_revision' => (int)$plan['through_revision'],
                'captured_revision' =>
                    (int)$plan['captured_revision'],
                'covered_revision' =>
                    (int)$plan['covered_revision'],
                'manifest_hash' =>
                    (string)$plan['mutation_manifest_hash'],
                'entry_set_hash' =>
                    (string)$plan['entry_set_hash'],
            ]);
        $plan['projection_root_hash'] =
            ingredientOntologyV3Hash([
                'algorithm' =>
                    INGREDIENT_ONTOLOGY_CORPUS_ANNEX_VERSION
                        . ':projection-lineage',
                'parent' => (string)$parent['projection_root_hash'],
                'manifest_hash' =>
                    (string)$plan['mutation_manifest_hash'],
                'entry_set_hash' =>
                    (string)$plan['entry_set_hash'],
            ]);
    }
    $revision = [
        'hash_version' => 2,
        'ontology_version_id' =>
            (int)$parent['ontology_version_id'],
        'ontology_content_hash' =>
            (string)$parent['ontology_content_hash'],
        'ontology_seal_hash' =>
            (string)$parent['ontology_seal_hash'],
        'parent_revision_id' =>
            $rollover ? null : (int)$parent['id'],
        'parent_revision_hash' => $rollover
            ? ingredientOntologyV3CorpusAnnexZeroHash()
            : (string)$parent['revision_hash'],
        'base_corpus_hash' => (string)$root['base_corpus_hash'],
        'captured_corpus_hash' =>
            (string)$plan['captured_corpus_hash'],
        'base_products_max_id' =>
            (int)$root['base_products_max_id'],
        'base_recipe_catalog_max_id' =>
            (int)$root['base_recipe_catalog_max_id'],
        'base_recipe_origins_max_id' =>
            (int)$root['base_recipe_origins_max_id'],
        'base_recipe_ingredients_max_id' =>
            (int)$root['base_recipe_ingredients_max_id'],
        'base_recipe_source_ingredients_max_id' =>
            (int)$root[
                'base_recipe_source_ingredients_max_id'
            ],
        'captured_ontology_source_revision' =>
            (int)$plan['captured_revision'],
        'covered_ontology_source_revision' =>
            (int)$plan['covered_revision'],
        'mutation_manifest_hash' =>
            (string)$plan['mutation_manifest_hash'],
        'mutation_manifest_json' =>
            (string)$plan['mutation_manifest_json'],
        'entry_set_hash' => (string)$plan['entry_set_hash'],
        'projection_root_hash' =>
            (string)$plan['projection_root_hash'],
        'resolution_input_hash' =>
            (string)$plan['resolution_input_hash'],
        'identity_extension_revision' =>
            (int)$plan['identity_extension']['revision'],
        'identity_extension_hash' =>
            (string)$plan['identity_extension']['hash'],
        'covered_identity_extension_revision' =>
            (int)$plan['covered_identity_extension']['revision'],
        'covered_identity_extension_hash' =>
            (string)$plan['covered_identity_extension']['hash'],
        'entry_count' => count($plan['entries']),
        'aggregate_count' => (int)$plan['aggregate_count'],
        'reconciliation_mode' => $rollover
            ? 'checkpoint'
            : (string)$plan['reconciliation_mode'],
    ];
    $revision['revision_hash'] =
        ingredientOntologyV3CorpusAnnexRevisionHash($revision);
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_corpus_annex_revisions (
            ontology_version_id, ontology_content_hash,
            ontology_seal_hash, parent_revision_id,
            parent_revision_hash, hash_version, revision_hash,
            base_corpus_hash, captured_corpus_hash,
            base_products_max_id,
            base_recipe_catalog_max_id,
            base_recipe_origins_max_id,
            base_recipe_ingredients_max_id,
            base_recipe_source_ingredients_max_id,
            captured_ontology_source_revision,
            covered_ontology_source_revision,
            mutation_manifest_hash, mutation_manifest_json,
            entry_set_hash, projection_root_hash,
            resolution_input_hash,
            identity_extension_revision,
            identity_extension_hash,
            covered_identity_extension_revision,
            covered_identity_extension_hash, entry_count,
            aggregate_count, reconciliation_mode, status
        )
        VALUES (
            ?, ?, ?, ?, ?, 2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, 'building'
        )
    ");
    $insert->execute([
        (int)$revision['ontology_version_id'],
        (string)$revision['ontology_content_hash'],
        (string)$revision['ontology_seal_hash'],
        $revision['parent_revision_id'],
        (string)$revision['parent_revision_hash'],
        (string)$revision['revision_hash'],
        (string)$revision['base_corpus_hash'],
        (string)$revision['captured_corpus_hash'],
        (int)$revision['base_products_max_id'],
        (int)$revision['base_recipe_catalog_max_id'],
        (int)$revision['base_recipe_origins_max_id'],
        (int)$revision['base_recipe_ingredients_max_id'],
        (int)$revision[
            'base_recipe_source_ingredients_max_id'
        ],
        (int)$revision['captured_ontology_source_revision'],
        (int)$revision['covered_ontology_source_revision'],
        (string)$revision['mutation_manifest_hash'],
        (string)$revision['mutation_manifest_json'],
        (string)$revision['entry_set_hash'],
        (string)$revision['projection_root_hash'],
        (string)$revision['resolution_input_hash'],
        (int)$revision['identity_extension_revision'],
        (string)$revision['identity_extension_hash'],
        (int)$revision['covered_identity_extension_revision'],
        (string)$revision['covered_identity_extension_hash'],
        (int)$revision['entry_count'],
        (int)$revision['aggregate_count'],
        (string)$revision['reconciliation_mode'],
    ]);
    $revisionId = (int)$db->lastInsertId();
    ingredientOntologyV3CorpusAnnexInsertEntries(
        $db,
        $revisionId,
        (array)$plan['entries']
    );
    $revision['id'] = $revisionId;
    $revision['status'] = 'building';
    return [
        'created' => true,
        'revision' => $revision,
        'plan' => $plan,
    ];
}

function ingredientOntologyV3CorpusAnnexPublishPrepared(
    PDO $db,
    array $prepared,
    array $parentScore,
    array $lockedState,
    bool $allowNewerSourceRevision = false
): array {
    $revision = (array)$prepared['revision'];
    if (empty($prepared['created'])) {
        return $revision;
    }
    if (!databaseTransactionIsActive($db)) {
        throw new RuntimeException(
            'corpus projection publication requires an active transaction'
        );
    }
    $sourceRevisionChanged =
        (int)$lockedState['ontology_source_revision']
            !== (int)$revision[
                'captured_ontology_source_revision'
            ];
    if (
        (
            $sourceRevisionChanged
            && (
                !$allowNewerSourceRevision
                || (int)$lockedState['ontology_source_revision']
                    < (int)$revision[
                        'captured_ontology_source_revision'
                    ]
            )
        )
        || (int)($lockedState['active_score_revision_id'] ?? 0)
            !== (int)$parentScore['id']
    ) {
        throw new RuntimeException(
            'corpus projection source revision changed before publication'
        );
    }
    if (!ingredientOntologyV3IdentityExtensionSnapshotMatches(
        $db,
        (int)$revision['ontology_version_id'],
        [
            'revision' =>
                (int)$revision['identity_extension_revision'],
            'hash' => (string)$revision['identity_extension_hash'],
        ]
    )) {
        throw new RuntimeException(
            'corpus projection publication fence changed'
        );
    }
    $stored = ingredientOntologyV3CorpusAnnexRevision(
        $db,
        (int)$revision['id']
    );
    if (
        $stored === null
        || (string)$stored['status'] !== 'building'
        || !hash_equals(
            ingredientOntologyV3CorpusAnnexRevisionHash($stored),
            (string)$stored['revision_hash']
        )
        || !hash_equals(
            ingredientOntologyV3CorpusAnnexStoredEntrySetHash(
                $db,
                (int)$stored['id']
            ),
            (string)$stored['entry_set_hash']
        )
    ) {
        throw new RuntimeException(
            'corpus projection building revision changed'
        );
    }
    $db->prepare("
        UPDATE ingredient_ontology_corpus_annex_revisions
        SET status = 'ready',
            ready_at = CURRENT_TIMESTAMP,
            last_error = ''
        WHERE id = ? AND status = 'building'
    ")->execute([(int)$revision['id']]);
    $ready = ingredientOntologyV3CorpusAnnexRevision(
        $db,
        (int)$revision['id']
    );
    if ($ready === null || (string)$ready['status'] !== 'ready') {
        throw new RuntimeException(
            'corpus projection publication was lost'
        );
    }
    $projectionDelta =
        ingredientOntologyV3CorpusAnnexApplyRevisionEntries(
        $db,
        $ready,
        false
    );
    $projectionCounts =
        ingredientOntologyV3CorpusAnnexProjectionCountsAfterDelta(
            $db,
            (int)$ready['ontology_version_id'],
            $projectionDelta
        );
    ingredientOntologyV3CorpusAnnexSetProjectionState(
        $db,
        $ready,
        $projectionCounts
    );
    return $ready;
}

function ingredientOntologyV3CorpusAnnexFailPrepared(
    PDO $db,
    ?array $prepared,
    string $error
): void {
    if (
        !$prepared
        || empty($prepared['created'])
        || (int)($prepared['revision']['id'] ?? 0) <= 0
    ) {
        return;
    }
    $db->prepare("
        UPDATE ingredient_ontology_corpus_annex_revisions
        SET status = 'failed',
            last_error = ?,
            failed_at = CURRENT_TIMESTAMP
        WHERE id = ? AND status = 'building'
    ")->execute([
        mb_substr($error, 0, 1000, 'UTF-8'),
        (int)$prepared['revision']['id'],
    ]);
}

function ingredientOntologyV3CorpusAnnexCleanupNonReady(
    PDO $db,
    int $minimumAgeSeconds = 3600,
    int $limit = 25
): array {
    if (!ingredientOntologyV3CorpusAnnexTableExists($db)) {
        return ['deleted_revision_ids' => [], 'deleted_entry_count' => 0];
    }
    $minimumAgeSeconds = max(0, $minimumAgeSeconds);
    $limit = max(1, min(250, $limit));
    $ownsTransaction = !databaseTransactionIsActive($db);
    if ($ownsTransaction) {
        dbBeginImmediateWithRetry($db);
    }
    $guardWasEnabled =
        ingredientOntologyV3RequirementPruneGuardEnabled($db);
    try {
        ingredientOntologyV3SetRequirementPruneGuard($db, true);
        $cutoff = '-' . $minimumAgeSeconds . ' seconds';
        $candidates = $db->prepare("
            SELECT revision.id
            FROM ingredient_ontology_corpus_annex_revisions revision
            WHERE revision.status IN ('building', 'failed')
              AND revision.created_at <= datetime('now', ?)
              AND NOT EXISTS (
                  SELECT 1
                  FROM recipe_score_revisions score
                  WHERE score.corpus_annex_revision_id = revision.id
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM ingredient_ontology_corpus_annex_revisions child
                  WHERE child.parent_revision_id = revision.id
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM ingredient_ontology_corpus_annex_effective_aggregates
                  WHERE head_revision_id = revision.id
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM ingredient_ontology_corpus_annex_effective_members
                  WHERE head_revision_id = revision.id
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM ingredient_ontology_corpus_annex_effective_entities
                  WHERE head_revision_id = revision.id
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM ingredient_ontology_corpus_annex_projection_state
                  WHERE materialized_revision_id = revision.id
              )
            ORDER BY revision.id
            LIMIT ?
        ");
        $candidates->bindValue(1, $cutoff, PDO::PARAM_STR);
        $candidates->bindValue(2, $limit, PDO::PARAM_INT);
        $candidates->execute();
        $revisionIds = array_map(
            'intval',
            $candidates->fetchAll(PDO::FETCH_COLUMN)
        );
        $deletedEntries = 0;
        $deleteEntries = $db->prepare("
            DELETE FROM ingredient_ontology_corpus_annex_entries
            WHERE corpus_annex_revision_id = ?
        ");
        $deleteRevision = $db->prepare("
            DELETE FROM ingredient_ontology_corpus_annex_revisions
            WHERE id = ? AND status IN ('building', 'failed')
        ");
        foreach ($revisionIds as $revisionId) {
            $deleteEntries->execute([$revisionId]);
            $deletedEntries += $deleteEntries->rowCount();
            $deleteRevision->execute([$revisionId]);
            if ($deleteRevision->rowCount() !== 1) {
                throw new RuntimeException(
                    'corpus projection cleanup fence was lost'
                );
            }
        }
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
        return [
            'deleted_revision_ids' => $revisionIds,
            'deleted_entry_count' => $deletedEntries,
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $error;
    } finally {
        ingredientOntologyV3SetRequirementPruneGuard(
            $db,
            $guardWasEnabled
        );
    }
}

function ingredientOntologyV3CorpusAnnexReconciliationGc(
    PDO $db,
    int $limit = 500
): array {
    if (
        !ingredientOntologyV3TableExists(
            $db,
            'recipe_score_source_reconciliation_events'
        )
        || !ingredientOntologyV3TableExists(
            $db,
            'recipe_score_source_reconciliation_scopes'
        )
    ) {
        return [
            'safe_revision' => 0,
            'deleted_event_count' => 0,
            'deleted_scope_count' => 0,
        ];
    }
    $limit = max(1, min(5000, $limit));
    $ownsTransaction = !databaseTransactionIsActive($db);
    if ($ownsTransaction) {
        dbBeginImmediateWithRetry($db);
    }
    try {
    if (
        ingredientOntologyV3TableExists(
            $db,
            'recipe_score_source_reconciliation_backfill'
        )
        && !(bool)$db->query("
            SELECT complete = 1 AND scope_backfill_version >= 1
            FROM recipe_score_source_reconciliation_backfill
            WHERE id = 1
        ")->fetchColumn()
    ) {
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
        return [
            'safe_revision' => 0,
            'deleted_event_count' => 0,
            'deleted_scope_count' => 0,
        ];
    }
    $recoverableMinimum = $db->query("
        SELECT MIN(covered_revision)
        FROM (
            SELECT annex.covered_ontology_source_revision
                       AS covered_revision
            FROM recipe_score_revisions score
            JOIN ingredient_ontology_corpus_annex_revisions annex
              ON annex.id = score.corpus_annex_revision_id
             AND annex.revision_hash = score.corpus_annex_hash
            WHERE score.status IN ('building', 'ready')
            UNION ALL
            SELECT annex.covered_ontology_source_revision
            FROM ingredient_ontology_corpus_annex_projection_state state
            JOIN ingredient_ontology_corpus_annex_revisions annex
              ON annex.id = state.materialized_revision_id
             AND annex.revision_hash =
                 state.materialized_revision_hash
        )
    ")->fetchColumn();
    $safeRevision = $recoverableMinimum !== false
        && $recoverableMinimum !== null
            ? (int)$recoverableMinimum
            : 0;
    if ($safeRevision <= 0) {
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
        return [
            'safe_revision' => 0,
            'deleted_event_count' => 0,
            'deleted_scope_count' => 0,
        ];
    }
    $candidates = $db->prepare("
        SELECT source_revision
        FROM recipe_score_source_reconciliation_events
        WHERE source_revision <= ?
        ORDER BY source_revision
        LIMIT ?
    ");
    $candidates->bindValue(1, $safeRevision, PDO::PARAM_INT);
    $candidates->bindValue(2, $limit, PDO::PARAM_INT);
    $candidates->execute();
    $revisions = array_map(
        'intval',
        $candidates->fetchAll(PDO::FETCH_COLUMN)
    );
    if (!$revisions) {
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
        return [
            'safe_revision' => $safeRevision,
            'deleted_event_count' => 0,
            'deleted_scope_count' => 0,
        ];
    }
    $placeholders = implode(
        ',',
        array_fill(0, count($revisions), '?')
    );
        $deleteScopes = $db->prepare("
            DELETE FROM recipe_score_source_reconciliation_scopes
            WHERE source_revision IN ({$placeholders})
        ");
        $deleteScopes->execute($revisions);
        $deletedScopes = $deleteScopes->rowCount();
        $deleteEvents = $db->prepare("
            DELETE FROM recipe_score_source_reconciliation_events
            WHERE source_revision IN ({$placeholders})
              AND source_revision <= ?
        ");
        $deleteEvents->execute([
            ...$revisions,
            $safeRevision,
        ]);
        $deletedEvents = $deleteEvents->rowCount();
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
        return [
            'safe_revision' => $safeRevision,
            'deleted_event_count' => $deletedEvents,
            'deleted_scope_count' => $deletedScopes,
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $error;
    }
}

function ingredientOntologyV3CorpusProjectionDriftDecision(
    PDO $db,
    ?array $activeScore = null
): array {
    $activeScore ??= recipeScoreActiveRevision($db);
    if (
        $activeScore === null
        || (int)($activeScore['ontology_version_id'] ?? 0) <= 0
    ) {
        return [
            'handled' => false,
            'requires_full_seal' => false,
            'reason' => 'no_active_ontology_score',
        ];
    }
    $pin = ingredientOntologyV3CorpusAnnexForScore(
        $db,
        $activeScore
    );
    if ($pin === null) {
        $pinId = (int)($activeScore[
            'corpus_annex_revision_id'
        ] ?? 0);
        $pinHash = trim((string)($activeScore[
            'corpus_annex_hash'
        ] ?? ''));
        if ($pinId === 0 && $pinHash === '') {
            return [
                'handled' => true,
                'requires_full_seal' => false,
                'reason' => 'projection_bootstrap_pending',
                'pending_suffix' => true,
                'journal_complete' => false,
                'product_ids' => [],
                'recipe_ids' => [],
                'entry_count' => 0,
                'aggregate_count' => 0,
                'chain_depth' => 0,
                'compaction_due' => false,
            ];
        }
        return [
            'handled' => false,
            'requires_full_seal' => true,
            'reason' => 'corpus_projection_pin_missing',
        ];
    }
    $audit = ingredientOntologyV3CorpusProjectionLineageAudit(
        $db,
        (int)$pin['id'],
        (string)$pin['revision_hash']
    );
    if (empty($audit['valid'])) {
        return [
            'handled' => false,
            'requires_full_seal' => true,
            'reason' => 'corpus_projection_integrity_failed',
            'errors' => (array)$audit['errors'],
        ];
    }
    $state = recipeScoreState($db);
    $covered = (int)$pin['covered_ontology_source_revision'];
    $current = (int)$state['ontology_source_revision'];
    if ($current < $covered) {
        return [
            'handled' => false,
            'requires_full_seal' => true,
            'reason' => 'ontology_source_revision_regressed',
        ];
    }
    $projectionReady =
        ingredientOntologyV3CorpusAnnexProjectionReady($db, $pin);
    $identity = ingredientOntologyV3IdentityExtensionSnapshot(
        $db,
        (int)$pin['ontology_version_id']
    );
    $coveredIdentity = (int)$pin[
        'covered_identity_extension_revision'
    ];
    $identityPending =
        (int)$identity['revision'] > $coveredIdentity
        || ingredientOntologyV3IdentityProjectionPendingCount(
            $db,
            (int)$pin['ontology_version_id']
        ) > 0;
    $pending = $current > $covered || $identityPending;
    $journalComplete = true;
    $reconciliationMode = 'journal';
    $scopeReconciliationComplete = false;
    $productIds = [];
    $recipeIds = [];
    if ($current > $covered) {
        $semantic = $db->prepare("
            SELECT 1
            FROM recipe_score_mutations
            WHERE domain = 'source'
              AND revision > ?
              AND revision <= ?
              AND owner_type = 'global'
              AND reason = 'semantic_policy_changed'
            LIMIT 1
        ");
        $semantic->execute([$covered, $current]);
        if ($semantic->fetchColumn() !== false) {
            return [
                'handled' => false,
                'requires_full_seal' => true,
                'reason' => 'semantic_generation_transition_required',
                'errors' => ['semantic_policy_changed'],
            ];
        }
        $limit = function_exists(
            'ingredientOntologyV3IncrementalProductLimit'
        ) ? ingredientOntologyV3IncrementalProductLimit() : 250;
        $journal = ingredientOntologyV3CorpusAnnexJournalWindow(
            $db,
            $covered,
            $current,
            INGREDIENT_ONTOLOGY_CORPUS_ANNEX_MAX_JOURNAL_EVENTS
        );
        $journalComplete = !empty($journal['complete']);
        if (
            empty($journal['dense'])
            || !empty($journal['oversized'])
        ) {
            $reconciliationMode = 'authoritative';
            $durable =
                ingredientOntologyV3CorpusAnnexDurableScopeWindow(
                    $db,
                    $covered,
                    $current
                );
            if (empty($durable['available'])) {
                return [
                    'handled' => false,
                    'requires_full_seal' => true,
                    'reason' =>
                        'source_reconciliation_evidence_missing',
                    'errors' => [
                        'source_reconciliation_evidence_missing',
                    ],
                ];
            }
            if (!empty($durable['available'])) {
                $scopes =
                    ingredientOntologyV3CorpusAnnexEventScopes(
                        $db,
                        (array)$durable['events'],
                        (int)$pin['ontology_version_id']
                    );
                if (
                    empty($scopes['semantic'])
                    && empty($scopes['authoritative'])
                ) {
                    $scopeReconciliationComplete = true;
                    $productIds = array_map(
                        'intval',
                        array_keys((array)$scopes['product'])
                    );
                    $recipeIds = array_map(
                        'intval',
                        array_keys((array)$scopes['recipe'])
                    );
                    sort($productIds, SORT_NUMERIC);
                    sort($recipeIds, SORT_NUMERIC);
                } elseif (!empty($scopes['semantic'])) {
                    return [
                        'handled' => false,
                        'requires_full_seal' => true,
                        'reason' =>
                            'semantic_generation_transition_required',
                        'errors' => ['semantic_policy_changed'],
                    ];
                } else {
                    $reconciliationMode = 'authoritative';
                }
            }
        } else {
            $scopes = ingredientOntologyV3CorpusAnnexEventScopes(
                $db,
                (array)$journal['events'],
                (int)$pin['ontology_version_id']
            );
            if (!empty($scopes['semantic'])) {
                return [
                    'handled' => false,
                    'requires_full_seal' => true,
                    'reason' =>
                        'semantic_generation_transition_required',
                    'errors' => ['semantic_policy_changed'],
                ];
            }
            if (!empty($scopes['authoritative'])) {
                $reconciliationMode = 'authoritative';
                $productIds = [];
                $recipeIds = [];
            }
            $productIds = array_map(
                'intval',
                array_keys((array)$scopes['product'])
            );
            $recipeIds = array_map(
                'intval',
                array_keys((array)$scopes['recipe'])
            );
            sort($productIds, SORT_NUMERIC);
            sort($recipeIds, SORT_NUMERIC);
            $productIds = array_slice($productIds, 0, $limit);
            $recipeIds = array_slice($recipeIds, 0, $limit);
        }
    }
    $projectionCounts = $db->prepare("
        SELECT aggregate_count, member_count
        FROM ingredient_ontology_corpus_annex_projection_state
        WHERE ontology_version_id = ?
    ");
    $projectionCounts->execute([(int)$pin['ontology_version_id']]);
    $projectionCounts =
        $projectionCounts->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'handled' => true,
        'requires_full_seal' => false,
        'reason' => !$projectionReady
            ? 'projection_repair_required'
            : (
                !$pending
                    ? 'active_projection_covers_corpus'
                    : (
                        $current <= $covered && $identityPending
                            ? 'identity_projection_pending'
                            : (
                        $reconciliationMode === 'authoritative'
                            ? 'authoritative_reconciliation_pending'
                            : 'aggregate_projection_pending'
                            )
                    )
            ),
        'current_corpus_hash' =>
            (string)$pin['captured_corpus_hash'],
        'active_annex_revision_id' => (int)$pin['id'],
        'active_annex_hash' => (string)$pin['revision_hash'],
        'covered_ontology_source_revision' => $covered,
        'current_ontology_source_revision' => $current,
        'captured_ontology_source_revision' =>
            (int)$pin['captured_ontology_source_revision'],
        'covered_identity_extension_revision' =>
            $coveredIdentity,
        'captured_identity_extension_revision' =>
            (int)$pin['identity_extension_revision'],
        'current_identity_extension_revision' =>
            (int)$identity['revision'],
        'pending_suffix' => $pending || !$projectionReady,
        'repair_needed' => !$projectionReady,
        'journal_complete' => $journalComplete,
        'scope_reconciliation_complete' =>
            $scopeReconciliationComplete,
        'reconciliation_mode' => $reconciliationMode,
        'product_ids' => array_map(
            'intval',
            $productIds
        ),
        'recipe_ids' => array_map(
            'intval',
            $recipeIds
        ),
        'entry_count' => (int)(
            $projectionCounts['member_count']
                ?? $audit['entry_count']
        ),
        'aggregate_count' => (int)(
            $projectionCounts['aggregate_count']
                ?? $audit['aggregate_count']
        ),
        'chain_depth' => (int)$audit['depth'],
        'base_maxima' => [
            'products' =>
                (int)$audit['root']['base_products_max_id'],
            'recipe_catalog' =>
                (int)$audit['root']['base_recipe_catalog_max_id'],
            'recipe_origins' =>
                (int)$audit['root']['base_recipe_origins_max_id'],
            'recipe_ingredients' =>
                (int)$audit['root']['base_recipe_ingredients_max_id'],
            'recipe_source_ingredients' =>
                (int)$audit['root'][
                    'base_recipe_source_ingredients_max_id'
                ],
        ],
        'compaction_due' =>
            (int)$audit['depth']
                >= ingredientOntologyV3CorpusAnnexCompactionDepth()
            || max(
                0,
                (int)$audit['entry_count']
                    - (int)($audit['root']['entry_count'] ?? 0)
            ) >= ingredientOntologyV3CorpusAnnexCompactionEntryLimit(),
    ];
}

function ingredientOntologyV3CorpusProjectionStatus(
    PDO $db
): array {
    if (!ingredientOntologyV3TableExists(
        $db,
        'recipe_score_projection_status'
    )) {
        return [
            'available' => false,
            'active_revision_id' => null,
            'active_hash' => null,
            'entry_count' => 0,
            'aggregate_count' => 0,
            'covered_ontology_source_revision' => null,
            'captured_ontology_source_revision' => null,
            'current_ontology_source_revision' => null,
            'covered_identity_extension_revision' => null,
            'captured_identity_extension_revision' => null,
            'current_identity_extension_revision' => null,
            'drift_reason' => 'unavailable',
            'requires_full_seal' => false,
            'pending_suffix' => false,
            'repair_needed' => false,
            'journal_complete' => false,
            'scope_reconciliation_complete' => false,
            'compaction_due' => false,
            'computed_at' => null,
            'fresh' => false,
        ];
    }
    $row = $db->query("
        SELECT status.*,
               score_state.active_score_revision_id
                   AS current_active_score_revision_id,
               score_state.active_score_projection_revision_id
                   AS current_score_projection_revision_id,
               score_state.ontology_source_revision
                   AS current_source_revision,
               current_score.corpus_annex_revision_id
                   AS current_annex_revision_id,
               current_score.corpus_annex_hash
                   AS current_annex_hash,
               current_annex.covered_ontology_source_revision
                   AS current_annex_covered_source_revision,
               current_annex.captured_ontology_source_revision
                   AS current_annex_captured_source_revision,
               current_annex.covered_identity_extension_revision
                   AS current_annex_covered_identity_revision,
               current_annex.covered_identity_extension_hash
                   AS current_annex_covered_identity_hash,
               current_annex.identity_extension_revision
                   AS current_annex_captured_identity_revision,
               current_annex.identity_extension_hash
                   AS current_annex_captured_identity_hash,
               identity_state.head_revision
                   AS current_identity_revision,
               identity_state.head_hash AS current_identity_hash,
               projection_state.materialized_revision_id,
               projection_state.materialized_revision_hash
        FROM recipe_score_projection_status status
        LEFT JOIN recipe_score_state score_state
          ON score_state.id = 1
        LEFT JOIN recipe_score_revisions current_score
          ON current_score.id =
             score_state.active_score_revision_id
        LEFT JOIN ingredient_ontology_corpus_annex_revisions
            current_annex
          ON current_annex.id =
             current_score.corpus_annex_revision_id
        LEFT JOIN ingredient_ontology_identity_extension_state
            identity_state
          ON identity_state.ontology_version_id =
             current_score.ontology_version_id
        LEFT JOIN ingredient_ontology_corpus_annex_projection_state
            projection_state
          ON projection_state.ontology_version_id =
             current_annex.ontology_version_id
        WHERE status.id = 1
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$row) {
        return [
            'available' => false,
            'drift_reason' => 'unavailable',
            'fresh' => false,
        ];
    }
    $activeScoreId = (int)(
        $row['current_active_score_revision_id'] ?? 0
    );
    $currentSource = (int)($row['current_source_revision'] ?? 0);
    $currentIdentity = (int)(
        $row['current_identity_revision'] ?? 0
    );
    $currentIdentityHash = (string)(
        $row['current_identity_hash']
            ?? ingredientOntologyV3IdentityExtensionZeroHash()
    );
    $currentAnnexId = (int)(
        $row['current_annex_revision_id'] ?? 0
    );
    $currentAnnexHash = (string)(
        $row['current_annex_hash'] ?? ''
    );
    $fresh =
        $row['computed_at'] !== null
        && (int)($row['active_score_revision_id'] ?? 0)
            === $activeScoreId
        && (int)($row['active_annex_revision_id'] ?? 0)
            === $currentAnnexId
        && hash_equals(
            (string)($row['active_annex_hash'] ?? ''),
            $currentAnnexHash
        )
        && (int)($row['observed_ontology_source_revision'] ?? -1)
            === $currentSource
        && (int)($row['observed_identity_extension_revision'] ?? -1)
            === $currentIdentity
        && hash_equals(
            (string)($row[
                'observed_identity_extension_hash'
            ] ?? ''),
            $currentIdentityHash
        );
    $scoreProjectionRepair =
        $activeScoreId > 0
        && (int)($row[
            'current_score_projection_revision_id'
        ] ?? 0) !== $activeScoreId;
    $annexProjectionRepair =
        $currentAnnexId > 0
        && (
            (int)($row['materialized_revision_id'] ?? 0)
                !== $currentAnnexId
            || !hash_equals(
                (string)($row[
                    'materialized_revision_hash'
                ] ?? ''),
                $currentAnnexHash
            )
        );
    $repairNeeded =
        !empty($row['repair_needed'])
        || $scoreProjectionRepair
        || $annexProjectionRepair;
    $pending =
        !empty($row['pending_suffix'])
        || !$fresh
        || $repairNeeded;
    $reason = (string)($row['verdict'] ?? 'unavailable');
    if ($scoreProjectionRepair) {
        $reason = 'score_projection_repair_pending';
    } elseif ($annexProjectionRepair) {
        $reason = 'projection_repair_required';
    } elseif (!$fresh) {
        $reason = 'materialized_status_stale';
    }
    $baseMaxima = json_decode(
        (string)($row['base_maxima_json'] ?? '{}'),
        true
    );
    return [
        'available' => ingredientOntologyV3CorpusAnnexTableExists($db),
        'active_revision_id' =>
            $currentAnnexId > 0
                ? $currentAnnexId
                : null,
        'active_hash' => $currentAnnexHash !== ''
            ? $currentAnnexHash
            : null,
        'entry_count' => (int)($row['entry_count'] ?? 0),
        'aggregate_count' =>
            (int)($row['aggregate_count'] ?? 0),
        'covered_ontology_source_revision' =>
            ($row['current_annex_covered_source_revision'] ?? null)
                !== null
                ? (int)$row[
                    'current_annex_covered_source_revision'
                ]
                : null,
        'captured_ontology_source_revision' =>
            ($row['current_annex_captured_source_revision'] ?? null)
                !== null
                ? (int)$row[
                    'current_annex_captured_source_revision'
                ]
                : null,
        'current_ontology_source_revision' => $currentSource,
        'covered_identity_extension_revision' =>
            ($row['current_annex_covered_identity_revision'] ?? null)
                !== null
                ? (int)$row[
                    'current_annex_covered_identity_revision'
                ]
                : null,
        'covered_identity_extension_hash' =>
            $row['current_annex_covered_identity_hash'] ?? null,
        'captured_identity_extension_revision' =>
            ($row['current_annex_captured_identity_revision'] ?? null)
                !== null
                ? (int)$row[
                    'current_annex_captured_identity_revision'
                ]
                : null,
        'captured_identity_extension_hash' =>
            $row['current_annex_captured_identity_hash'] ?? null,
        'current_identity_extension_revision' => $currentIdentity,
        'current_identity_extension_hash' => $currentIdentityHash,
        'pending_identity_recipe_count' =>
            (int)($row['pending_identity_recipe_count'] ?? 0),
        'base_maxima' => is_array($baseMaxima)
            ? $baseMaxima
            : null,
        'drift_reason' => $reason,
        'requires_full_seal' =>
            !empty($row['requires_full_seal']),
        'pending_suffix' => $pending,
        'repair_needed' => $repairNeeded,
        'score_projection_repair_pending' =>
            $scoreProjectionRepair,
        'journal_complete' =>
            $fresh && !empty($row['journal_complete']),
        'scope_reconciliation_complete' => !empty(
            $row['scope_reconciliation_complete']
        ),
        'reconciliation_mode' => (string)(
            $row['reconciliation_mode'] ?? 'unavailable'
        ),
        'compaction_due' => !empty($row['compaction_due']),
        'computed_at' => $row['computed_at'] ?? null,
        'fresh' => $fresh,
        'last_error' => trim(
            (string)($row['last_error'] ?? '')
        ) ?: null,
    ];
}

function ingredientOntologyV3CorpusProjectionRefreshStatus(
    PDO $db,
    string $lastError = ''
): array {
    if (!ingredientOntologyV3TableExists(
        $db,
        'recipe_score_projection_status'
    )) {
        return ingredientOntologyV3CorpusProjectionStatus($db);
    }
    $active = recipeScoreActiveRevision($db);
    $decision = ingredientOntologyV3CorpusProjectionDriftDecision(
        $db,
        $active
    );
    $state = $db->query("
        SELECT active_score_revision_id,
               active_score_projection_revision_id,
               ontology_source_revision
        FROM recipe_score_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $pin = $active !== null
        ? ingredientOntologyV3CorpusAnnexForScore($db, $active)
        : null;
    $versionId = $pin !== null
        ? (int)$pin['ontology_version_id']
        : (int)($active['ontology_version_id'] ?? 0);
    $identity = $versionId > 0
        ? ingredientOntologyV3IdentityExtensionSnapshot(
            $db,
            $versionId
        )
        : [
            'revision' => 0,
            'hash' => ingredientOntologyV3IdentityExtensionZeroHash(),
        ];
    $scoreProjectionRepair =
        (int)($state['active_score_revision_id'] ?? 0) > 0
        && (int)($state[
            'active_score_projection_revision_id'
        ] ?? 0) !== (int)$state['active_score_revision_id'];
    $baseMaxima = $decision['base_maxima'] ?? [];
    $db->prepare("
        INSERT INTO recipe_score_projection_status (
            id, active_score_revision_id,
            active_annex_revision_id, active_annex_hash,
            ontology_version_id, verdict, requires_full_seal,
            pending_suffix, repair_needed,
            score_projection_repair_pending,
            journal_complete, scope_reconciliation_complete,
            reconciliation_mode,
            covered_ontology_source_revision,
            captured_ontology_source_revision,
            observed_ontology_source_revision,
            covered_identity_extension_revision,
            covered_identity_extension_hash,
            captured_identity_extension_revision,
            captured_identity_extension_hash,
            observed_identity_extension_revision,
            observed_identity_extension_hash,
            pending_identity_recipe_count,
            entry_count, aggregate_count, chain_depth,
            compaction_due, base_maxima_json, last_error,
            computed_at, updated_at
        )
        VALUES (
            1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        ON CONFLICT(id) DO UPDATE SET
            active_score_revision_id =
                excluded.active_score_revision_id,
            active_annex_revision_id =
                excluded.active_annex_revision_id,
            active_annex_hash = excluded.active_annex_hash,
            ontology_version_id = excluded.ontology_version_id,
            verdict = excluded.verdict,
            requires_full_seal = excluded.requires_full_seal,
            pending_suffix = excluded.pending_suffix,
            repair_needed = excluded.repair_needed,
            score_projection_repair_pending =
                excluded.score_projection_repair_pending,
            journal_complete = excluded.journal_complete,
            scope_reconciliation_complete =
                excluded.scope_reconciliation_complete,
            reconciliation_mode = excluded.reconciliation_mode,
            covered_ontology_source_revision =
                excluded.covered_ontology_source_revision,
            captured_ontology_source_revision =
                excluded.captured_ontology_source_revision,
            observed_ontology_source_revision =
                excluded.observed_ontology_source_revision,
            covered_identity_extension_revision =
                excluded.covered_identity_extension_revision,
            covered_identity_extension_hash =
                excluded.covered_identity_extension_hash,
            captured_identity_extension_revision =
                excluded.captured_identity_extension_revision,
            captured_identity_extension_hash =
                excluded.captured_identity_extension_hash,
            observed_identity_extension_revision =
                excluded.observed_identity_extension_revision,
            observed_identity_extension_hash =
                excluded.observed_identity_extension_hash,
            pending_identity_recipe_count =
                excluded.pending_identity_recipe_count,
            entry_count = excluded.entry_count,
            aggregate_count = excluded.aggregate_count,
            chain_depth = excluded.chain_depth,
            compaction_due = excluded.compaction_due,
            base_maxima_json = excluded.base_maxima_json,
            last_error = excluded.last_error,
            computed_at = excluded.computed_at,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([
        $active !== null ? (int)$active['id'] : null,
        $pin !== null ? (int)$pin['id'] : null,
        $pin['revision_hash'] ?? null,
        $versionId > 0 ? $versionId : null,
        (string)($decision['reason'] ?? 'unavailable'),
        !empty($decision['requires_full_seal']) ? 1 : 0,
        !empty($decision['pending_suffix']) ? 1 : 0,
        !empty($decision['repair_needed']) ? 1 : 0,
        $scoreProjectionRepair ? 1 : 0,
        !empty($decision['journal_complete']) ? 1 : 0,
        !empty($decision['scope_reconciliation_complete']) ? 1 : 0,
        (string)($decision[
            'reconciliation_mode'
        ] ?? 'unavailable'),
        $pin['covered_ontology_source_revision'] ?? null,
        $pin['captured_ontology_source_revision'] ?? null,
        $state['ontology_source_revision'] ?? null,
        $pin['covered_identity_extension_revision'] ?? null,
        $pin['covered_identity_extension_hash'] ?? null,
        $pin['identity_extension_revision'] ?? null,
        $pin['identity_extension_hash'] ?? null,
        (int)$identity['revision'],
        (string)$identity['hash'],
        $versionId > 0
            ? ingredientOntologyV3IdentityProjectionPendingCount(
                $db,
                $versionId
            )
            : 0,
        (int)($decision['entry_count'] ?? 0),
        (int)($decision['aggregate_count'] ?? 0),
        (int)($decision['chain_depth'] ?? 0),
        !empty($decision['compaction_due']) ? 1 : 0,
        ingredientOntologyV3Json(
            is_array($baseMaxima) ? $baseMaxima : []
        ),
        mb_substr($lastError, 0, 1000, 'UTF-8'),
    ]);
    return ingredientOntologyV3CorpusProjectionStatus($db);
}

function ingredientOntologyV3CorpusProjectionMaterializedDecision(
    PDO $db,
    ?array $activeScore = null
): array {
    $activeScore ??= recipeScoreActiveRevision($db);
    if (
        $activeScore === null
        || (int)($activeScore['ontology_version_id'] ?? 0) <= 0
    ) {
        return [
            'handled' => false,
            'requires_full_seal' => false,
            'reason' => 'no_active_ontology_score',
        ];
    }
    $pin = ingredientOntologyV3CorpusAnnexForScore(
        $db,
        $activeScore
    );
    if ($pin === null) {
        $pinId = (int)($activeScore[
            'corpus_annex_revision_id'
        ] ?? 0);
        $pinHash = trim((string)($activeScore[
            'corpus_annex_hash'
        ] ?? ''));
        return $pinId === 0 && $pinHash === ''
            ? [
                'handled' => true,
                'requires_full_seal' => false,
                'reason' => 'projection_bootstrap_pending',
                'pending_suffix' => true,
            ]
            : [
                'handled' => false,
                'requires_full_seal' => true,
                'reason' => 'corpus_projection_pin_missing',
            ];
    }
    $status = ingredientOntologyV3CorpusProjectionStatus($db);
    $current = (int)(
        $status['current_ontology_source_revision']
            ?? $pin['captured_ontology_source_revision']
    );
    $dynamicReason = null;
    $dynamicScopeComplete = false;
    if ($current > (int)$pin['covered_ontology_source_revision']) {
        $semantic = $db->prepare("
            SELECT 1
            FROM recipe_score_mutations
            WHERE domain = 'source'
              AND revision > ?
              AND revision <= ?
              AND owner_type = 'global'
              AND reason = 'semantic_policy_changed'
            LIMIT 1
        ");
        $semantic->execute([
            (int)$pin['covered_ontology_source_revision'],
            $current,
        ]);
        if ($semantic->fetchColumn() !== false) {
            return [
                'handled' => false,
                'requires_full_seal' => true,
                'reason' =>
                    'semantic_generation_transition_required',
                'errors' => ['semantic_policy_changed'],
            ];
        }
        $firstJournal = $db->prepare("
            SELECT revision
            FROM recipe_score_mutations
            WHERE domain = 'source'
              AND revision > ?
              AND revision <= ?
            ORDER BY revision
            LIMIT 1
        ");
        $firstJournal->execute([
            (int)$pin['covered_ontology_source_revision'],
            $current,
        ]);
        $firstRevision = (int)(
            $firstJournal->fetchColumn() ?: 0
        );
        if (
            $firstRevision
                !== (int)$pin[
                    'covered_ontology_source_revision'
                ] + 1
        ) {
            $firstDurable = $db->prepare("
                SELECT 1
                FROM recipe_score_source_reconciliation_events
                WHERE source_revision = ?
                LIMIT 1
            ");
            $firstDurable->execute([
                (int)$pin[
                    'covered_ontology_source_revision'
                ] + 1,
            ]);
            if ($firstDurable->fetchColumn() !== false) {
                $dynamicReason =
                    'authoritative_reconciliation_pending';
                $dynamicScopeComplete = true;
            } else {
                return [
                    'handled' => false,
                    'requires_full_seal' => true,
                    'reason' =>
                        'source_reconciliation_evidence_missing',
                    'errors' => [
                        'source_reconciliation_evidence_missing',
                    ],
                ];
            }
        } else {
            $dynamicReason = 'aggregate_projection_pending';
        }
    }
    return [
        'handled' => empty($status['requires_full_seal']),
        'requires_full_seal' =>
            !empty($status['requires_full_seal']),
        'reason' => $dynamicReason ?? (string)($status[
            'drift_reason'
        ] ?? 'materialized_status_stale'),
        'pending_suffix' => !empty($status['pending_suffix']),
        'repair_needed' => !empty($status['repair_needed']),
        'journal_complete' => !empty($status['journal_complete']),
        'scope_reconciliation_complete' =>
            $dynamicScopeComplete
            || !empty($status['scope_reconciliation_complete']),
        'active_annex_revision_id' =>
            $status['active_revision_id'] ?? null,
        'active_annex_hash' => $status['active_hash'] ?? null,
        'covered_ontology_source_revision' =>
            $status['covered_ontology_source_revision'] ?? null,
        'current_ontology_source_revision' =>
            $status['current_ontology_source_revision'] ?? null,
        'covered_identity_extension_revision' => $status[
            'covered_identity_extension_revision'
        ] ?? null,
        'current_identity_extension_revision' => $status[
            'current_identity_extension_revision'
        ] ?? null,
        'product_ids' => [],
        'recipe_ids' => [],
        'entry_count' => (int)($status['entry_count'] ?? 0),
        'aggregate_count' =>
            (int)($status['aggregate_count'] ?? 0),
        'chain_depth' => 0,
        'compaction_due' => !empty($status['compaction_due']),
    ];
}

function ingredientOntologyV3CorpusProjectionIntegrityAudit(
    PDO $db,
    int $revisionId,
    string $expectedHash = '',
    bool $verifyProjection = false
): array {
    $audit = ingredientOntologyV3CorpusAnnexIntegrityAudit(
        $db,
        $revisionId,
        $expectedHash,
        false
    );
    if (
        !empty($audit['valid'])
        && $verifyProjection
        && is_array($audit['head'] ?? null)
    ) {
        $active = recipeScoreActiveRevision($db);
        if (
            $active !== null
            && (int)($active['corpus_annex_revision_id'] ?? 0)
                === $revisionId
            && !ingredientOntologyV3CorpusAnnexProjectionReady(
                $db,
                (array)$audit['head']
            )
        ) {
            $audit['valid'] = false;
            $audit['errors'][] =
                'active corpus projection materialization is stale';
        }
    }
    return $audit;
}

function ingredientOntologyV3CorpusAnnexDriftDecision(
    PDO $db,
    ?array $activeScore = null
): array {
    return ingredientOntologyV3CorpusProjectionDriftDecision(
        $db,
        $activeScore
    );
}

function ingredientOntologyV3CorpusAnnexStatus(
    PDO $db
): array {
    return ingredientOntologyV3CorpusProjectionStatus($db);
}

function ingredientOntologyV3CorpusProjectionEnsureScoreRoot(
    PDO $db,
    array $score
): ?array {
    return ingredientOntologyV3CorpusAnnexEnsureScoreRoot($db, $score);
}

function ingredientOntologyV3CorpusProjectionSeedActiveRoot(
    PDO $db
): ?array {
    if (!ingredientOntologyV3CorpusAnnexTableExists($db)) {
        return null;
    }
    $score = recipeScoreActiveRevision($db);
    return $score !== null
        ? ingredientOntologyV3CorpusProjectionEnsureScoreRoot(
            $db,
            $score
        )
        : null;
}

function ingredientOntologyV3CorpusAnnexEntityRecipeIds(
    PDO $db,
    int $versionId,
    array $entityIds,
    ?array $pin = null,
    int $afterRecipeId = 0,
    ?int $limit = null
): array {
    $entityIds = array_values(array_unique(array_filter(
        array_map('intval', $entityIds),
        static fn(int $entityId): bool => $entityId !== 0
    )));
    if (!$entityIds) {
        return [];
    }
    if ($pin === null) {
        $active = recipeScoreActiveRevision($db);
        if (
            $active === null
            || (int)($active['ontology_version_id'] ?? 0) !== $versionId
        ) {
            return [];
        }
        $pin = ingredientOntologyV3CorpusAnnexForScore($db, $active);
        if ($pin === null) {
            return [];
        }
    } elseif ((int)$pin['ontology_version_id'] !== $versionId) {
        return [];
    }
    ingredientOntologyV3CorpusAnnexEnsureProjection($db, $pin);
    $keys = [];
    foreach ($entityIds as $entityId) {
        if ($entityId > 0) {
            $stmt = $db->prepare("
                SELECT slug
                FROM ingredient_ontology_entities
                WHERE id = ? AND ontology_version_id = ?
            ");
            $stmt->execute([$entityId, $versionId]);
            $slug = $stmt->fetchColumn();
            if ($slug !== false) {
                $keys[] = 'native:' . (string)$slug;
            }
        } elseif ($entityId < 0) {
            $extensionId = -$entityId;
            $stmt = $db->prepare("
                SELECT identity_key_hash
                FROM ingredient_ontology_identity_extension_entities
                WHERE id = ? AND ontology_version_id = ?
            ");
            $stmt->execute([$extensionId, $versionId]);
            $hash = $stmt->fetchColumn();
            if ($hash !== false) {
                $keys[] = 'extension:' . (string)$hash;
            }
        }
    }
    if (!$keys) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $limitSql = $limit !== null
        ? ' LIMIT ' . max(1, $limit)
        : '';
    $stmt = $db->prepare("
        SELECT DISTINCT aggregate_id
        FROM ingredient_ontology_corpus_annex_effective_entities
        WHERE ontology_version_id = ?
          AND aggregate_type = 'recipe'
          AND entity_key IN ({$placeholders})
          AND aggregate_id > ?
        ORDER BY aggregate_id
        {$limitSql}
    ");
    $stmt->execute(array_merge(
        [$versionId],
        $keys,
        [max(0, $afterRecipeId)]
    ));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
