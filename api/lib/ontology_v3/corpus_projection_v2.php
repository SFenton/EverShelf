<?php
declare(strict_types=1);

/*
 * Stable projection-v2 entry points. Authority and validation remain in
 * corpus_annex.php while existing callers migrate to projection terminology.
 */

function ingredientOntologyV3CorpusProjectionV2Classify(
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

function ingredientOntologyV3CorpusProjectionV2CreateChild(
    PDO $db,
    array $parentScore,
    array $state,
    array $plan
): array {
    return ingredientOntologyV3CorpusAnnexCreateChild(
        $db,
        $parentScore,
        $state,
        $plan
    );
}

function ingredientOntologyV3CorpusProjectionV2PublishPrepared(
    PDO $db,
    array $prepared,
    array $parentScore,
    array $lockedState,
    bool $allowNewerSourceRevision = false
): array {
    return ingredientOntologyV3CorpusAnnexPublishPrepared(
        $db,
        $prepared,
        $parentScore,
        $lockedState,
        $allowNewerSourceRevision
    );
}

function ingredientOntologyV3CorpusProjectionV2IntegrityAudit(
    PDO $db,
    int $revisionId,
    string $expectedHash = '',
    bool $verifyProjection = false
): array {
    return ingredientOntologyV3CorpusAnnexIntegrityAudit(
        $db,
        $revisionId,
        $expectedHash,
        $verifyProjection
    );
}

function ingredientOntologyV3CorpusProjectionV2DriftDecision(
    PDO $db,
    ?array $activeScore = null
): array {
    return ingredientOntologyV3CorpusProjectionMaterializedDecision(
        $db,
        $activeScore
    );
}

function ingredientOntologyV3CorpusProjectionV2RefreshStatus(
    PDO $db,
    string $lastError = ''
): array {
    return ingredientOntologyV3CorpusProjectionRefreshStatus(
        $db,
        $lastError
    );
}

function ingredientOntologyV3CorpusProjectionV2EnsureScoreRoot(
    PDO $db,
    array $score
): ?array {
    return ingredientOntologyV3CorpusAnnexEnsureScoreRoot($db, $score);
}

function ingredientOntologyV3CorpusProjectionV2Status(
    PDO $db
): array {
    return ingredientOntologyV3CorpusProjectionStatus($db);
}

function ingredientOntologyV3CorpusProjectionV2Compact(
    PDO $db,
    bool $force = false
): array {
    return ingredientOntologyV3CompactCorpusProjection($db, $force);
}
