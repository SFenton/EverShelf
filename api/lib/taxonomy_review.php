<?php
/**
 * EverShelf — taxonomy history reuse + disabled legacy Gemini compatibility.
 *
 * Sits between the heuristic rule matcher (canonicalIngredientInferProduct) and the
 * database write performed by canonicalIngredientSyncProduct:
 *
 *   1. history reuse  — if we have classified this item before, replay that decision
 *                       verbatim and skip the model entirely
 *   2. legacy request — record an ontology-controller observation and retain
 *                       the deterministic heuristic result
 *
 * Legacy model calls and unversioned topology writes are permanently disabled.
 */
declare(strict_types=1);

const TAXONOMY_REVIEW_VERSION = 'gemini_taxonomy_review_v1';
const TAXONOMY_REVIEW_SOURCE = 'gemini_taxonomy_review';
const TAXONOMY_HISTORY_SOURCE = 'taxonomy_history';
const TAXONOMY_PREPARED_SOURCE = 'prepared_food_flag';

function taxonomyReviewEnabled(): bool {
    // Legacy model output must never mutate unversioned taxonomy topology.
    // The compatibility flag is retained only so an attempted enablement can
    // be recorded as controller evidence.
    return false;
}

function taxonomyReviewRequested(): bool {
    return canonicalIngredientEnvBool('TAXONOMY_AI_REVIEW', false);
}

function taxonomyReviewCachePath(): string {
    return __DIR__ . '/../../data/taxonomy_review_cache.json';
}

// ─────────────────────────────────────────────────────────────────────────────
// History reuse
// ─────────────────────────────────────────────────────────────────────────────

/** Identity key used to decide "have we classified this exact item before?". */
function taxonomyHistoryKey(string $name, string $brand = ''): string {
    $name = canonicalIngredientNormalizeText($name);
    $brand = canonicalIngredientNormalizeText($brand);
    return $brand === '' ? $name : $name . '|' . $brand;
}

/**
 * Rebuild the mapping array (the shape canonicalIngredientPut produces) from the terms
 * already stored against a product.
 */
function taxonomyMappingsFromProduct(PDO $db, int $productId, string $source, bool $excludeManual = false): array {
    $stmt = $db->prepare("
        SELECT ci.slug, ci.name, ci.parent_slug, ci.category, ci.external_ids_json,
               pi.role, pi.confidence, pi.evidence
        FROM product_ingredients pi
        JOIN canonical_ingredients ci ON ci.id = pi.ingredient_id
        WHERE pi.product_id = ?" . ($excludeManual ? " AND pi.source != 'manual'" : '') . "
        ORDER BY CASE pi.role WHEN 'primary' THEN 0 WHEN 'contains' THEN 1 ELSE 2 END, pi.confidence DESC
    ");
    $stmt->execute([$productId]);

    $mappings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $externalIds = json_decode((string)($row['external_ids_json'] ?? ''), true);
        $mappings[] = [
            'slug' => (string)$row['slug'],
            'name' => (string)$row['name'],
            'role' => (string)$row['role'],
            'confidence' => (float)$row['confidence'],
            'source' => $source,
            'evidence' => mb_substr((string)($row['evidence'] ?? ''), 0, 300, 'UTF-8'),
            'category' => (string)($row['category'] ?? ''),
            'parent_slug' => $row['parent_slug'] !== null ? (string)$row['parent_slug'] : null,
            'external_ids' => is_array($externalIds) ? $externalIds : [],
        ];
    }
    return $mappings;
}

/** Build a mapping chain (primary + broader ancestors) from a taxonomy node. */
function taxonomyMappingsFromNode(PDO $db, int $treeId, int $nodeId, string $evidence): array {
    $stmt = $db->prepare("
        SELECT n.slug, n.name, n.category, c.depth
        FROM taxonomy_closure c
        JOIN taxonomy_nodes n ON n.id = c.ancestor_node_id
        WHERE c.tree_id = ? AND c.descendant_node_id = ? AND n.active = 1
        ORDER BY c.depth ASC
    ");
    $stmt->execute([$treeId, $nodeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return [];
    }

    // Ordered nearest-first, so the next row up the chain is this term's parent.
    $names = [];
    foreach ($rows as $row) {
        $names[] = (string)$row['name'];
    }

    $mappings = [];
    foreach ($rows as $idx => $row) {
        canonicalIngredientPut(
            $mappings,
            (string)$row['name'],
            $idx === 0 ? 'primary' : 'broader',
            max(0.1, 0.95 - ($idx * 0.04)),
            TAXONOMY_HISTORY_SOURCE,
            $evidence,
            (string)($row['category'] ?? ''),
            $names[$idx + 1] ?? null
        );
    }
    return array_values($mappings);
}

/**
 * Look for a previous classification of the same item.
 *
 * Order of preference: same barcode → same name+brand → same name → known alias.
 * Returns null when this is genuinely a new item (which is what triggers the Gemini call).
 */
function taxonomyHistoryMatch(PDO $db, array $product): ?array {
    $productId = (int)($product['id'] ?? 0);
    $name = trim((string)($product['name'] ?? ''));
    $brand = trim((string)($product['brand'] ?? ''));
    $barcode = trim((string)($product['barcode'] ?? ''));
    if ($name === '') {
        return null;
    }

    // Barcode is an exact column match; name/brand need normalization so they are compared in PHP.
    if ($barcode !== '') {
        $stmt = $db->prepare("
            SELECT p.id FROM products p
            WHERE p.barcode = ? AND p.id != ?
              AND EXISTS (SELECT 1 FROM product_ingredients pi WHERE pi.product_id = p.id)
            LIMIT 1
        ");
        $stmt->execute([$barcode, $productId]);
        $priorId = (int)($stmt->fetchColumn() ?: 0);
        if ($priorId > 0) {
            return [
                'match' => 'barcode',
                'product_id' => $priorId,
                'mappings' => taxonomyMappingsFromProduct($db, $priorId, TAXONOMY_HISTORY_SOURCE),
            ];
        }
    }

    $targetNameBrand = taxonomyHistoryKey($name, $brand);
    $targetName = taxonomyHistoryKey($name);
    $stmt = $db->prepare("
        SELECT p.id, p.name, p.brand
        FROM products p
        WHERE p.id != ?
          AND EXISTS (SELECT 1 FROM product_ingredients pi WHERE pi.product_id = p.id)
        ORDER BY p.updated_at DESC, p.id DESC
    ");
    $stmt->execute([$productId]);
    $nameOnlyHit = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowId = (int)$row['id'];
        if (taxonomyHistoryKey((string)$row['name'], (string)$row['brand']) === $targetNameBrand) {
            return [
                'match' => 'name_brand',
                'product_id' => $rowId,
                'mappings' => taxonomyMappingsFromProduct($db, $rowId, TAXONOMY_HISTORY_SOURCE),
            ];
        }
        if ($nameOnlyHit === null && taxonomyHistoryKey((string)$row['name']) === $targetName) {
            $nameOnlyHit = $rowId;
        }
    }
    if ($nameOnlyHit !== null) {
        return [
            'match' => 'name',
            'product_id' => $nameOnlyHit,
            'mappings' => taxonomyMappingsFromProduct($db, $nameOnlyHit, TAXONOMY_HISTORY_SOURCE),
        ];
    }

    // Alias table: records every placement a previous review asserted, so a reworded
    // variant of a known item still avoids the model.
    $tree = taxonomyDefaultTree($db);
    $treeId = (int)($tree['id'] ?? 0);
    if ($treeId > 0) {
        $aliasStmt = $db->prepare("
            SELECT node_id FROM taxonomy_aliases
            WHERE tree_id = ? AND normalized_alias = ? AND active = 1
            LIMIT 1
        ");
        $aliasStmt->execute([$treeId, canonicalIngredientNormalizeText($name)]);
        $nodeId = (int)($aliasStmt->fetchColumn() ?: 0);

        // This product classified before under the same name: replay its own terms rather
        // than rebuilding from the tree. The tree only stores the node hierarchy, so an
        // alias replay would silently drop this product's `contains` terms every time it
        // is re-queued. Those terms are product-specific, so they can only come from here.
        if ($nodeId > 0 && $productId > 0) {
            $ownMappings = taxonomyMappingsFromProduct($db, $productId, TAXONOMY_HISTORY_SOURCE, true);
            if (!empty($ownMappings)) {
                return ['match' => 'self', 'product_id' => $productId, 'mappings' => $ownMappings];
            }
        }

        if ($nodeId > 0) {
            $mappings = taxonomyMappingsFromNode($db, $treeId, $nodeId, 'previously reviewed alias for "' . $name . '"');
            if (!empty($mappings)) {
                return ['match' => 'alias', 'product_id' => 0, 'mappings' => $mappings];
            }
        }
    }

    return null;
}

/** Remember the asserted placement so the next equivalent item is a history hit. */
function taxonomyRecordAlias(PDO $db, int $treeId, string $alias, int $nodeId): void {
    $normalized = canonicalIngredientNormalizeText($alias);
    if ($treeId <= 0 || $nodeId <= 0 || $normalized === '') {
        return;
    }
    $db->prepare("
        INSERT INTO taxonomy_aliases (tree_id, node_id, alias, normalized_alias, source, active, updated_at)
        VALUES (?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)
        ON CONFLICT(tree_id, normalized_alias, node_id) DO UPDATE SET
            active = 1,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([$treeId, $nodeId, mb_substr($alias, 0, 200, 'UTF-8'), $normalized, TAXONOMY_REVIEW_SOURCE]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Gemini review
// ─────────────────────────────────────────────────────────────────────────────

/** Compact snapshot of the whole tree: every active node with its primary parent. */
function taxonomyTreeSnapshot(PDO $db): array {
    $tree = taxonomyDefaultTree($db);
    $treeId = (int)($tree['id'] ?? 0);
    if ($treeId <= 0) {
        return ['tree_id' => 0, 'nodes' => []];
    }
    $stmt = $db->prepare("
        SELECT n.slug, n.name, n.category, parent.slug AS parent_slug
        FROM taxonomy_nodes n
        LEFT JOIN taxonomy_edges e ON e.child_node_id = n.id AND e.tree_id = n.tree_id AND e.active = 1
        LEFT JOIN taxonomy_nodes parent ON parent.id = e.parent_node_id AND parent.active = 1
        WHERE n.tree_id = ? AND n.active = 1
        ORDER BY COALESCE(parent.name, n.name), n.name
    ");
    $stmt->execute([$treeId]);

    $nodes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slug = (string)$row['slug'];
        if (!isset($nodes[$slug])) {
            $nodes[$slug] = [
                'slug' => $slug,
                'name' => (string)$row['name'],
                'category' => (string)($row['category'] ?? ''),
                'parent' => $row['parent_slug'] !== null ? (string)$row['parent_slug'] : '',
            ];
        }
    }
    return ['tree_id' => $treeId, 'nodes' => array_values($nodes)];
}

/** Render the tree as indented lines so the model can see its actual shape and conventions. */
function taxonomyTreeOutline(array $snapshot): string {
    $nodes = $snapshot['nodes'] ?? [];
    $byParent = [];
    foreach ($nodes as $node) {
        $byParent[$node['parent']][] = $node;
    }
    $lines = [];
    $walk = function (string $parent, int $depth) use (&$walk, &$lines, $byParent): void {
        foreach ($byParent[$parent] ?? [] as $node) {
            $lines[] = str_repeat('  ', $depth) . '- ' . $node['name'] . ' [' . $node['slug'] . ']';
            if ($depth < 6) {
                $walk($node['slug'], $depth + 1);
            }
        }
    };
    $walk('', 0);
    return implode("\n", $lines);
}

function taxonomyProposalSummary(array $mappings): string {
    $lines = [];
    foreach ($mappings as $mapping) {
        $lines[] = sprintf(
            '- %s (role=%s, confidence=%.2f, source=%s)',
            $mapping['name'],
            $mapping['role'],
            (float)$mapping['confidence'],
            $mapping['source']
        );
    }
    return $lines ? implode("\n", $lines) : '- (nothing matched)';
}

function taxonomyReviewSignature(array $product, array $mappings, int $nodeCount): string {
    $terms = array_map(static fn(array $m): string => $m['slug'] . ':' . $m['role'], $mappings);
    sort($terms);
    return md5(implode('|', [
        TAXONOMY_REVIEW_VERSION,
        canonicalIngredientNormalizeText((string)($product['name'] ?? '')),
        canonicalIngredientNormalizeText((string)($product['brand'] ?? '')),
        canonicalIngredientNormalizeText((string)($product['category'] ?? '')),
        implode(',', $terms),
        (string)$nodeCount,
    ]));
}

function taxonomyReviewCacheLoad(): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];
    $path = taxonomyReviewCachePath();
    if (is_file($path)) {
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (is_array($decoded)) {
            $cache = $decoded;
        }
    }
    return $cache;
}

function taxonomyReviewCacheStore(string $key, array $entry): void {
    $cache = taxonomyReviewCacheLoad();
    $cache[$key] = $entry;
    $path = taxonomyReviewCachePath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $path . '.tmp';
    @file_put_contents($tmp, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    if (is_file($tmp)) {
        @rename($tmp, $path);
    }
}

/**
 * Ask Gemini to validate/adjust the heuristic placement against the whole tree.
 *
 * Returns null when the model is unavailable or unusable, in which case the caller keeps
 * the heuristic result — taxonomy must never regress just because the model was down.
 */
function taxonomyGeminiReview(PDO $db, array $product, array $mappings): ?array {
    if (!taxonomyReviewEnabled() || !function_exists('callGeminiWithFallback') || !function_exists('env')) {
        return null;
    }
    $apiKey = env('GEMINI_API_KEY');
    if ($apiKey === '') {
        return null;
    }

    $snapshot = taxonomyTreeSnapshot($db);
    if (empty($snapshot['nodes'])) {
        return null;
    }

    $signature = taxonomyReviewSignature($product, $mappings, count($snapshot['nodes']));
    $cache = taxonomyReviewCacheLoad();
    if (isset($cache[$signature]['review'])) {
        $cached = $cache[$signature]['review'];
        $cached['cached'] = true;
        return $cached;
    }

    $ingredients = trim((string)($product['ingredients_text'] ?? ''));
    $prompt = "You maintain a food taxonomy tree for a home inventory app.\n\n"
        . "EXISTING TAXONOMY TREE (indented = child of the line above, [slug] is the stable id):\n"
        . taxonomyTreeOutline($snapshot) . "\n\n"
        . "PRODUCT TO CLASSIFY:\n"
        . '- name: ' . (string)($product['name'] ?? '') . "\n"
        . '- brand: ' . (string)($product['brand'] ?? '') . "\n"
        . '- category: ' . (string)($product['category'] ?? '') . "\n"
        . ($ingredients !== '' ? '- ingredients: ' . mb_substr($ingredients, 0, 400, 'UTF-8') . "\n" : '')
        . "\nHEURISTIC PROPOSAL (regex rules; may be wrong, or a low-confidence guess from the product name):\n"
        . taxonomyProposalSummary($mappings) . "\n\n"
        . "YOUR TASK:\n"
        . "1. Decide the correct PRIMARY term: the generic ingredient/food this product IS, never the brand and never packaging.\n"
        . "2. Give its ancestors from nearest to broadest, following the conventions already visible in the tree.\n"
        . "3. Reuse existing nodes wherever one fits — prefer an existing [slug] over inventing a near-duplicate.\n"
        . "4. Only introduce a new term when the tree genuinely lacks it, and place it under a sensible existing parent.\n"
        . "5. Judge whether the heuristic proposal was correct, and say so via 'verdict'.\n\n"
        . "RULES:\n"
        . "- Never rename, move or delete an existing node. You may only add new ones.\n"
        . "- Use singular, generic, title-case names consistent with the tree (e.g. 'Black beans', not 'Kroger Black Beans 15oz').\n"
        . "- 'contains' is for notable component ingredients, not the primary term or its ancestors.\n"
        . "- Set confidence below 0.5 if the product is too ambiguous to place.";

    $payload = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature' => 0,
            'maxOutputTokens' => 700,
            'responseMimeType' => 'application/json',
            // Without this the 2.5-flash thinking pass consumes the output budget and the
            // response comes back truncated with an empty text part.
            'thinkingConfig' => ['thinkingBudget' => 0],
            'responseSchema' => [
                'type' => 'object',
                'properties' => [
                    'primary' => ['type' => 'string'],
                    'ancestors' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'contains' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'category' => ['type' => 'string'],
                    'verdict' => ['type' => 'string', 'enum' => ['confirmed', 'adjusted', 'replaced']],
                    'confidence' => ['type' => 'number'],
                    'reason' => ['type' => 'string'],
                ],
                'required' => ['primary', 'ancestors', 'verdict', 'confidence'],
            ],
        ],
    ];

    $result = callGeminiWithFallback($apiKey, $payload, 30, 'taxonomy_review');
    if (($result['http_code'] ?? 0) !== 200) {
        if (class_exists('EverLog', false)) {
            EverLog::warn('taxonomy review failed', [
                'product' => (string)($product['name'] ?? ''),
                'http_code' => $result['http_code'] ?? 0,
                'error' => $result['data']['error']['message'] ?? '',
            ]);
        }
        return null;
    }

    $text = (string)($result['data']['candidates'][0]['content']['parts'][0]['text'] ?? '');
    $text = preg_replace('/^```json\s*/i', '', $text) ?? $text;
    $text = preg_replace('/\s*```$/i', '', $text) ?? $text;
    $parsed = json_decode(trim($text), true);
    if (!is_array($parsed) || trim((string)($parsed['primary'] ?? '')) === '') {
        return null;
    }

    $review = [
        'primary' => trim((string)$parsed['primary']),
        'ancestors' => array_values(array_filter(array_map('trim', (array)($parsed['ancestors'] ?? [])))),
        'contains' => array_values(array_filter(array_map('trim', (array)($parsed['contains'] ?? [])))),
        'category' => trim((string)($parsed['category'] ?? '')),
        'verdict' => (string)($parsed['verdict'] ?? 'adjusted'),
        'confidence' => max(0.0, min(1.0, (float)($parsed['confidence'] ?? 0.7))),
        'reason' => mb_substr(trim((string)($parsed['reason'] ?? '')), 0, 240, 'UTF-8'),
        'cached' => false,
    ];
    taxonomyReviewCacheStore($signature, ['review' => $review, 'stored_at' => date('c')]);
    return $review;
}

/**
 * Turn an accepted review into mappings.
 *
 * The guardrail lives here: a term whose slug already exists keeps the stored name and
 * keeps its stored parent (parent_slug is left null so the upsert's COALESCE preserves
 * it). Only genuinely new slugs are created with the parent the review proposed.
 */
function taxonomyMappingsFromReview(PDO $db, array $review, array $existingSlugs): array {
    $path = array_merge([$review['primary']], $review['ancestors']);
    $path = array_values(array_filter(array_map('trim', $path)));
    if (empty($path)) {
        return [];
    }

    $confidence = (float)$review['confidence'];
    $category = (string)($review['category'] ?? '');
    $evidence = 'gemini taxonomy review (' . $review['verdict'] . ')'
        . ($review['reason'] !== '' ? ': ' . $review['reason'] : '');

    $mappings = [];
    foreach ($path as $idx => $name) {
        $slug = canonicalIngredientSlug($name);
        if ($slug === '') {
            continue;
        }
        $isExisting = isset($existingSlugs[$slug]);
        canonicalIngredientPut(
            $mappings,
            $isExisting ? $existingSlugs[$slug]['name'] : $name,
            $idx === 0 ? 'primary' : 'broader',
            max(0.1, $confidence - ($idx * 0.04)),
            TAXONOMY_REVIEW_SOURCE,
            $evidence,
            $category,
            // Existing nodes keep whatever parent they already have.
            $isExisting ? null : ($path[$idx + 1] ?? null)
        );
    }
    foreach ($review['contains'] as $name) {
        $slug = canonicalIngredientSlug($name);
        if ($slug === '' || isset($mappings[$slug])) {
            continue;
        }
        canonicalIngredientPut(
            $mappings,
            isset($existingSlugs[$slug]) ? $existingSlugs[$slug]['name'] : $name,
            'contains',
            max(0.1, $confidence - 0.15),
            TAXONOMY_REVIEW_SOURCE,
            $evidence,
            $category
        );
    }
    return array_values($mappings);
}

/**
 * Persist any genuinely new terms into the tree so taxonomy search can reach them.
 * Existing nodes are left completely untouched: no rename, no new parent edge.
 */
function taxonomyRegisterNewNodes(PDO $db, array $mappings, array $existingSlugs, string $aliasFor = ''): int {
    $tree = taxonomyDefaultTree($db);
    $treeId = (int)($tree['id'] ?? 0);
    if ($treeId <= 0) {
        return 0;
    }

    $added = 0;
    $nodeIds = [];
    foreach ($mappings as $mapping) {
        $slug = (string)$mapping['slug'];
        if (isset($existingSlugs[$slug])) {
            continue;
        }
        $nodeId = taxonomyUpsertNode($db, $treeId, (string)$mapping['name'], (string)($mapping['category'] ?? ''), TAXONOMY_REVIEW_SOURCE);
        if ($nodeId > 0) {
            $nodeIds[$slug] = $nodeId;
            $added++;
        }
    }

    // Edges are only ever created for brand-new children, so no existing node is reparented.
    foreach ($mappings as $mapping) {
        $slug = (string)$mapping['slug'];
        $parentSlug = $mapping['parent_slug'] ?? null;
        if (!isset($nodeIds[$slug]) || $parentSlug === null || $parentSlug === '') {
            continue;
        }
        $parentId = $existingSlugs[$parentSlug]['id']
            ?? $nodeIds[$parentSlug]
            ?? 0;
        if ($parentId > 0) {
            taxonomyUpsertEdge($db, $treeId, (int)$parentId, $nodeIds[$slug], TAXONOMY_REVIEW_SOURCE);
        }
    }

    if ($added > 0) {
        taxonomyRebuildClosure($db, $treeId);
    }

    if ($aliasFor !== '') {
        $primarySlug = '';
        foreach ($mappings as $mapping) {
            if (($mapping['role'] ?? '') === 'primary') {
                $primarySlug = (string)$mapping['slug'];
                break;
            }
        }
        if ($primarySlug !== '') {
            $primaryNodeId = $nodeIds[$primarySlug] ?? ($existingSlugs[$primarySlug]['id'] ?? 0);
            taxonomyRecordAlias($db, $treeId, $aliasFor, (int)$primaryNodeId);
        }
    }

    return $added;
}

/** Slug → {id, name} for every active node, used as the "do not touch" set. */
function taxonomyExistingSlugs(PDO $db): array {
    $tree = taxonomyDefaultTree($db);
    $treeId = (int)($tree['id'] ?? 0);
    if ($treeId <= 0) {
        return [];
    }
    $stmt = $db->prepare("SELECT id, slug, name FROM taxonomy_nodes WHERE tree_id = ? AND active = 1");
    $stmt->execute([$treeId]);
    $slugs = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slugs[(string)$row['slug']] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
    }
    return $slugs;
}

/**
 * Placement for items the user flagged as prepared food.
 *
 * These are finished dishes/meals, so per-ingredient classification is meaningless. They
 * are grouped under whichever bucket node already exists rather than minting a new one,
 * and they deliberately skip both the history lookup and the model.
 */
function taxonomyPreparedFoodMappings(PDO $db): array {
    $tree = taxonomyDefaultTree($db);
    $treeId = (int)($tree['id'] ?? 0);
    if ($treeId <= 0) {
        return [];
    }

    $node = null;
    foreach (['prepared-meal', 'prepared-food'] as $slug) {
        $stmt = $db->prepare("SELECT id, slug, name, category FROM taxonomy_nodes WHERE tree_id = ? AND slug = ? AND active = 1");
        $stmt->execute([$treeId, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $node = $row;
            break;
        }
    }

    $name = $node['name'] ?? 'Prepared meal';
    $category = (string)($node['category'] ?? '');
    $mappings = [];
    canonicalIngredientPut(
        $mappings,
        $name,
        'primary',
        0.99,
        TAXONOMY_PREPARED_SOURCE,
        'flagged as a prepared food item at add time',
        $category
    );
    return array_values($mappings);
}

/**
 * Full resolution pipeline for one product.
 *
 * Returns ['mappings' => array, 'decision' => string, 'detail' => array].
 */
function taxonomyResolveForProduct(PDO $db, array $product, array $heuristicMappings, bool $allowAi = true): array {
    if (!empty($product['prepared_food'])) {
        $prepared = taxonomyPreparedFoodMappings($db);
        if (!empty($prepared)) {
            return [
                'mappings' => $prepared,
                'decision' => 'prepared_food',
                'detail' => ['primary' => $prepared[0]['name']],
            ];
        }
    }

    $history = taxonomyHistoryMatch($db, $product);
    if ($history !== null && !empty($history['mappings'])) {
        return [
            'mappings' => $history['mappings'],
            'decision' => 'history:' . $history['match'],
            'detail' => ['prior_product_id' => $history['product_id'], 'terms' => count($history['mappings'])],
        ];
    }

    if (!$allowAi) {
        return ['mappings' => $heuristicMappings, 'decision' => 'heuristic', 'detail' => ['reason' => 'ai_disabled']];
    }

    if (taxonomyReviewRequested()) {
        if (
            function_exists(
                'ingredientOntologyControllerObserveProductSafely'
            )
            && (int)($product['id'] ?? 0) > 0
        ) {
            try {
                $observed =
                    ingredientOntologyControllerObserveProductSafely(
                    $db,
                    (int)$product['id'],
                    $product,
                    'product_ingestion'
                );
                if (empty($observed['observed'])) {
                    throw new RuntimeException(
                        'controller observation unavailable'
                    );
                }
                ingredientOntologyControllerInsertObservation(
                    $db,
                    'legacy-ai-suppressed:'
                        . (int)$product['id']
                        . ':'
                        . (string)$observed['subject'][
                            'subject_fingerprint'
                        ],
                    'legacy_ai_suppressed',
                    [
                        'product_id' => (int)$product['id'],
                        'reason' =>
                            'legacy_unversioned_topology_mutation_disabled',
                    ],
                    (int)$observed['subject']['id']
                );
            } catch (Throwable $ignored) {
                // Canonical fallback must remain available even if optional
                // controller evidence cannot be recorded.
            }
        }
        return [
            'mappings' => $heuristicMappings,
            'decision' => 'heuristic',
            'detail' => [
                'reason' => 'legacy_ai_observation_only',
            ],
        ];
    }

    $review = taxonomyGeminiReview($db, $product, $heuristicMappings);
    if ($review === null) {
        return ['mappings' => $heuristicMappings, 'decision' => 'heuristic', 'detail' => ['reason' => 'review_unavailable']];
    }
    if ($review['confidence'] < 0.5) {
        return [
            'mappings' => $heuristicMappings,
            'decision' => 'heuristic',
            'detail' => ['reason' => 'low_review_confidence', 'confidence' => $review['confidence']],
        ];
    }

    $existingSlugs = taxonomyExistingSlugs($db);
    $mappings = taxonomyMappingsFromReview($db, $review, $existingSlugs);
    if (empty($mappings)) {
        return ['mappings' => $heuristicMappings, 'decision' => 'heuristic', 'detail' => ['reason' => 'empty_review_mappings']];
    }

    taxonomyRegisterNewNodes($db, $mappings, $existingSlugs, (string)($product['name'] ?? ''));

    return [
        'mappings' => $mappings,
        'decision' => 'review:' . $review['verdict'],
        'detail' => [
            'primary' => $review['primary'],
            'confidence' => $review['confidence'],
            'reason' => $review['reason'],
            'cached' => !empty($review['cached']),
        ],
    ];
}
