<?php

function recipeApiJsonInput(): array {
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && isset($GLOBALS['RECIPE_API_JSON_INPUT'])
        && is_array($GLOBALS['RECIPE_API_JSON_INPUT'])
    ) {
        return $GLOBALS['RECIPE_API_JSON_INPUT'];
    }
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

function recipeApiJsonObjectInput(): ?array {
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && isset($GLOBALS['RECIPE_API_JSON_INPUT'])
        && is_array($GLOBALS['RECIPE_API_JSON_INPUT'])
    ) {
        $input = $GLOBALS['RECIPE_API_JSON_INPUT'];
        return $input === [] || !recipeArrayIsList($input)
            ? $input
            : null;
    }
    $raw = file_get_contents('php://input');
    $shape = json_decode((string)$raw);
    if (!is_object($shape)) {
        return null;
    }
    $input = json_decode((string)$raw, true);
    return is_array($input) ? $input : null;
}

function recipeApiRequireQueryPositiveInt(
    mixed $value,
    string $field
): int {
    if (is_int($value)) {
        return recipeCatalogRequirePositiveInt($value, $field);
    }
    if (
        !is_string($value)
        || !preg_match('/^[1-9]\d*$/D', $value)
    ) {
        throw new InvalidArgumentException('invalid_' . $field);
    }
    $id = (int)$value;
    if ((string)$id !== $value) {
        throw new InvalidArgumentException('invalid_' . $field);
    }
    return recipeCatalogRequirePositiveInt($id, $field);
}

function recipeApiBodyRecipeId(
    array $input,
    bool $required
): ?int {
    $ids = [];
    foreach (['id', 'recipe_id'] as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $ids[] = recipeCatalogRequirePositiveInt(
            $input[$field],
            'recipe_id'
        );
    }
    if (!$ids) {
        if ($required) {
            throw new InvalidArgumentException('invalid_recipe_id');
        }
        return null;
    }
    if (count(array_unique($ids)) !== 1) {
        throw new InvalidArgumentException('invalid_recipe_id');
    }
    return $ids[0];
}

function recipeApiRequirePost(): bool {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        return true;
    }
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    return false;
}

function recipeApiPagination(): array {
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    if (isset($_GET['offset'])) {
        $offset = max(0, (int)$_GET['offset']);
        $page = intdiv($offset, $limit) + 1;
    } else {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $offset = ($page - 1) * $limit;
    }
    return [$limit, $offset, $page];
}

function recipeApiBrowseOptions(): array {
    [$limit, $offset, $page] = recipeApiPagination();
    return [[
        'query' => (string)($_GET['query'] ?? $_GET['q'] ?? ''),
        'mode' => (string)($_GET['mode'] ?? 'stocked'),
        'sort' => (string)($_GET['sort'] ?? ''),
        'source' => (string)($_GET['source'] ?? ''),
        'locale' => (string)($_GET['locale'] ?? ''),
        'availability_weight' => $_GET['availability_weight'] ?? null,
        'expiry_weight' => $_GET['expiry_weight'] ?? null,
        'minimum_coverage' => $_GET['minimum_coverage'] ?? null,
        'expiring_within_days' => $_GET['expiring_within_days'] ?? null,
        'limit' => $limit,
        'offset' => $offset,
        'cursor' => (string)($_GET['cursor'] ?? ''),
        'fields' => (string)($_GET['fields'] ?? 'full'),
        'explain' => !isset($_GET['explain'])
            || filter_var($_GET['explain'], FILTER_VALIDATE_BOOLEAN),
    ], $page];
}

function recipeApiWriteCatalogResult(callable $callback): void {
    try {
        $result = $callback();
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    } catch (RecipeScoreUnavailableException $e) {
        http_response_code(503);
        header('Retry-After: 30');
        echo json_encode([
            'success' => false,
            'error' => 'recipe_scores_building',
            'message' => $e->getMessage(),
        ]);
        return;
    }
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
}

function recipeCatalogApiSearch(PDO $db): void {
    [$options, $page] = recipeApiBrowseOptions();
    recipeApiWriteCatalogResult(static function () use ($db, $options, $page): array {
        $result = recipeCatalogSearchResult($db, $options);
        recipeCookidooDemandRefresh(
            $db,
            array_column($result['items'] ?? [], 'id'),
            'search'
        );
        $result['page'] = $page;
        return $result;
    });
}

function recipeCatalogApiSuggest(PDO $db): void {
    [$options, $page] = recipeApiBrowseOptions();
    $options['query'] = '';
    recipeApiWriteCatalogResult(static function () use ($db, $options, $page): array {
        $result = recipeCatalogSuggestionResult($db, $options);
        recipeCookidooDemandRefresh(
            $db,
            array_column($result['items'] ?? [], 'id'),
            'suggest'
        );
        $result['page'] = $page;
        return $result;
    });
}

function recipeCatalogApiRecommendations(PDO $db): void {
    recipeApiWriteCatalogResult(static function () use ($db): array {
        $result = recipeCatalogRecommendationResult($db, [
            'source' => (string)($_GET['source'] ?? ''),
            'locale' => (string)($_GET['locale'] ?? ''),
            'limit' => max(5, min(100, (int)($_GET['limit'] ?? 30))),
        ]);
        recipeCookidooDemandRefresh(
            $db,
            array_column($result['items'] ?? [], 'id'),
            'recommendations'
        );
        return $result;
    });
}

function recipeCatalogApiGet(PDO $db): void {
    try {
        $id = recipeApiRequireQueryPositiveInt(
            $_GET['id'] ?? null,
            'recipe_id'
        );
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_recipe_id']);
        return;
    }
    $recipe = recipeCatalogGetById($db, $id);
    if ($recipe === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'recipe_not_found']);
        return;
    }
    recipeCookidooDemandRefresh($db, [$id], 'get');
    echo json_encode(['success' => true, 'recipe' => $recipe], JSON_UNESCAPED_UNICODE);
}

function recipeCatalogApiDetail(PDO $db): void {
    try {
        $id = recipeApiRequireQueryPositiveInt(
            $_GET['id'] ?? $_GET['recipe_id'] ?? null,
            'recipe_id'
        );
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_recipe_id']);
        return;
    }
    $detail = recipeCatalogDetail($db, $id);
    if ($detail === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'recipe_not_found']);
        return;
    }
    recipeCookidooDemandRefresh($db, [$id], 'detail');
    echo json_encode(
        ['success' => true, 'detail' => $detail],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function recipeCatalogApiGroceryAdd(PDO $db): void {
    if (!recipeApiRequirePost()) {
        return;
    }
    try {
        $result = recipeGroceryAddMissing($db, recipeApiJsonInput());
    } catch (OutOfBoundsException $e) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'recipe_not_found']);
        return;
    } catch (RecipeGroceryConflictException $e) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    }
    echo json_encode(
        ['success' => true] + $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function recipeCatalogApiIngredientOverride(PDO $db): void {
    if (!recipeApiRequirePost()) {
        return;
    }
    try {
        $result = recipeIngredientOverrideSet(
            $db,
            recipeApiJsonInput()
        );
    } catch (OutOfBoundsException $e) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'recipe_not_found',
        ]);
        return;
    } catch (RecipeIngredientFeedbackConflictException $e) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
        return;
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
        return;
    }
    echo json_encode(
        ['success' => true] + $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function recipeCatalogApiIdentityFeedback(PDO $db): void {
    if (!recipeApiRequirePost()) {
        return;
    }
    try {
        $result = recipeIngredientIdentityFeedbackRecord(
            $db,
            recipeApiJsonInput()
        );
    } catch (OutOfBoundsException $e) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'recipe_not_found',
        ]);
        return;
    } catch (RecipeIngredientFeedbackConflictException $e) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
        return;
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
        return;
    }
    echo json_encode(
        ['success' => true] + $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function recipeCatalogApiIngredientDecision(PDO $db): void {
    if (!recipeApiRequirePost()) {
        return;
    }
    try {
        $result = recipeIngredientDecision(
            $db,
            recipeApiJsonInput()
        );
    } catch (OutOfBoundsException $e) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'recipe_not_found',
        ]);
        return;
    } catch (RecipeIngredientFeedbackConflictException $e) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
        return;
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
        return;
    }
    echo json_encode(
        ['success' => true] + $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function recipeCatalogApiPlannerAdd(PDO $db): void {
    if (!recipeApiRequirePost()) {
        return;
    }
    try {
        $result = recipePlannerAdd(
            $db,
            recipeApiJsonInput()
        );
    } catch (OutOfBoundsException $e) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
        return;
    } catch (RecipePlannerConflictException $e) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
        return;
    } catch (RecipePlannerUnavailableException $e) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
        return;
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
        return;
    }
    echo json_encode(
        ['success' => true] + $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function recipeCatalogApiJobsStatus(PDO $db): void {
    $id = null;
    if (array_key_exists('id', $_GET)) {
        try {
            $id = recipeApiRequireQueryPositiveInt(
                $_GET['id'],
                'job_id'
            );
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'invalid_job_id',
            ]);
            return;
        }
    }
    $searchId = trim((string)($_GET['search_id'] ?? ''));
    if ($searchId !== '') {
        recipeApiWriteCatalogResult(
            static fn(): array => recipeCookidooHydrationStatus($db, $searchId)
        );
        return;
    }
    $key = isset($_GET['idempotency_key']) ? trim((string)$_GET['idempotency_key']) : null;
    if (($id !== null && $id > 0) || ($key !== null && $key !== '')) {
        $job = recipeJobGet($db, $id, $key);
        if ($job === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'recipe_job_not_found']);
            return;
        }
        echo json_encode(['success' => true, 'job' => $job], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo json_encode([
        'success' => true,
        'jobs' => recipeJobsRecent($db, (int)($_GET['limit'] ?? 50)),
    ], JSON_UNESCAPED_UNICODE);
}

function recipeCatalogApiConnectors(PDO $db): void {
    echo json_encode([
        'success' => true,
        'connectors' => recipeConnectorsWithState($db),
    ], JSON_UNESCAPED_UNICODE);
}

function recipeCatalogApiDiscover(PDO $db): void {
    if (!recipeApiRequirePost()) {
        return;
    }
    try {
        $input = recipeApiJsonInput();
        if (!array_key_exists('interactive', $input)) {
            $input['interactive'] = true;
        }
        $result = recipeCookidooDiscover($db, $input);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    }
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
}

function recipeCatalogApiSave(PDO $db): void {
    if (!recipeApiRequirePost()) {
        return;
    }
    $input = recipeApiJsonObjectInput();
    if ($input === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_request']);
        return;
    }
    $recipe = $input['recipe'] ?? $input;
    if (!is_array($recipe)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_recipe']);
        return;
    }
    $metadata = is_array($input['origin'] ?? null) ? $input['origin'] : [];
    foreach ([
        'recipe_id', 'connector', 'external_id', 'canonical_url', 'locale',
        'language', 'content_language', 'attribution', 'license',
        'storage_policy', 'rights_basis',
        'cache_expires_at', 'stale_at', 'retrieved_at', 'availability',
    ] as $key) {
        if (array_key_exists($key, $input)) {
            $metadata[$key] = $input[$key];
        }
    }
    if (!isset($metadata['connector'])) {
        $metadata['connector'] = 'manual';
    }
    if (array_key_exists('recipe_id', $metadata)) {
        try {
            $metadata['recipe_id'] = recipeCatalogRequirePositiveInt(
                $metadata['recipe_id'],
                'recipe_id'
            );
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'invalid_recipe_id',
            ]);
            return;
        }
    }
    $connector = trim((string)$metadata['connector']);
    $connectorMetadata = recipeConnectorRegistry()[$connector] ?? null;
    if (
        $connectorMetadata === null
        || !in_array('save', $connectorMetadata['capabilities'] ?? [], true)
    ) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'connector_save_unsupported']);
        return;
    }
    try {
        $saved = recipeCatalogSaveVariant($db, $recipe, $metadata);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    }
    echo json_encode(['success' => true, 'recipe' => $saved], JSON_UNESCAPED_UNICODE);
}

function recipeCatalogApiDelete(PDO $db): void {
    if (!recipeApiRequirePost()) {
        return;
    }
    $input = recipeApiJsonObjectInput();
    if ($input === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_request']);
        return;
    }
    try {
        $id = recipeApiBodyRecipeId($input, true);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_recipe_id']);
        return;
    }
    $deleted = recipeCatalogDelete($db, $id);
    if (!$deleted) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'recipe_not_found']);
        return;
    }
    echo json_encode(['success' => true, 'id' => $id]);
}

function recipeCatalogApiFavorite(PDO $db): void {
    if (!recipeApiRequirePost()) {
        return;
    }
    $input = recipeApiJsonObjectInput();
    if ($input === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_request']);
        return;
    }
    try {
        $id = recipeApiBodyRecipeId($input, true);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_recipe_id']);
        return;
    }
    $favorite = array_key_exists('favorite', $input)
        ? (bool)filter_var($input['favorite'], FILTER_VALIDATE_BOOLEAN)
        : null;
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $value = recipeCatalogSetFavorite($db, $id, $favorite);
    } catch (OutOfBoundsException $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'recipe_not_found']);
        return;
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    $db->prepare("
        UPDATE recipes
        SET is_favorite = ?
        WHERE catalog_recipe_id = ?
    ")->execute([$value ? 1 : 0, $id]);
    if ($ownsTransaction) {
        $db->commit();
    }
    echo json_encode(['success' => true, 'id' => $id, 'favorite' => $value]);
}

function recipeCatalogApiRefresh(PDO $db): void {
    if (!recipeApiRequirePost()) {
        return;
    }
    $input = recipeApiJsonObjectInput();
    if ($input === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_request']);
        return;
    }
    try {
        $recipeId = recipeApiBodyRecipeId($input, false);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_recipe_id']);
        return;
    }
    $connector = trim((string)($input['connector'] ?? 'local')) ?: 'local';
    if (!recipeConnectorExists($connector)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_connector']);
        return;
    }
    if (!empty(recipeConnectorRegistry()[$connector]['network'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'connector_refresh_unsupported',
            'message' => 'Run connector discovery again to refresh network metadata',
        ]);
        return;
    }
    if ($recipeId !== null && recipeCatalogGetById($db, $recipeId) === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'recipe_not_found']);
        return;
    }
    $scope = $recipeId !== null ? 'recipe:' . $recipeId : 'catalog';
    $job = recipeJobEnqueue(
        $db,
        'recipe_refresh',
        ['scope' => $scope, 'connector' => $connector],
        ['recipe_id' => $recipeId],
        'recipe_refresh:' . $connector . ':' . $scope
    );
    echo json_encode(['success' => true, 'job' => $job], JSON_UNESCAPED_UNICODE);
}
