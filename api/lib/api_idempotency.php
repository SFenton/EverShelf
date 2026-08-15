<?php
declare(strict_types=1);

const API_IDEMPOTENCY_KEY_MAX_LENGTH = 128;
const API_IDEMPOTENCY_RECEIPT_TTL_DAYS = 30;

final class ApiIdempotencyConflict extends RuntimeException {
}

function apiIdempotencyKey(array $input): ?string {
    if (!array_key_exists('idempotency_key', $input)) {
        return null;
    }
    $key = trim((string)$input['idempotency_key']);
    if (
        $key === ''
        || strlen($key) > API_IDEMPOTENCY_KEY_MAX_LENGTH
        || !preg_match('/^[A-Za-z0-9._:-]+$/D', $key)
    ) {
        throw new InvalidArgumentException('invalid_idempotency_key');
    }
    return $key;
}

function apiIdempotencyRequestHash(array $payload): string {
    return hash(
        'sha256',
        ingredientOntologyControllerStableJson($payload)
    );
}

function apiIdempotencyReceipt(
    PDO $db,
    string $action,
    string $key,
    string $requestHash
): ?array {
    $stmt = $db->prepare("
        SELECT request_hash, response_json, expires_at
        FROM api_idempotency_receipts
        WHERE action = ? AND idempotency_key = ?
        LIMIT 1
    ");
    $stmt->execute([$action, $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $expiresAt = strtotime((string)$row['expires_at']);
    if ($expiresAt !== false && $expiresAt <= time()) {
        $db->prepare("
            DELETE FROM api_idempotency_receipts
            WHERE action = ? AND idempotency_key = ?
        ")->execute([$action, $key]);
        return null;
    }
    if (!hash_equals((string)$row['request_hash'], $requestHash)) {
        throw new ApiIdempotencyConflict('idempotency_key_reused');
    }
    try {
        $response = json_decode(
            (string)$row['response_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $error) {
        throw new RuntimeException(
            'idempotency_receipt_corrupt',
            0,
            $error
        );
    }
    if (!is_array($response)) {
        throw new RuntimeException('idempotency_receipt_corrupt');
    }
    $response['replayed'] = true;
    return $response;
}

function apiIdempotencyStoreReceipt(
    PDO $db,
    string $action,
    string $key,
    string $requestHash,
    array $response
): void {
    $encoded = json_encode(
        $response,
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
    );
    $stmt = $db->prepare("
        INSERT INTO api_idempotency_receipts (
            action, idempotency_key, request_hash, response_json,
            created_at, updated_at, expires_at
        )
        VALUES (
            ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
            datetime('now', ?)
        )
        ON CONFLICT(action, idempotency_key) DO UPDATE SET
            request_hash = excluded.request_hash,
            response_json = excluded.response_json,
            updated_at = CURRENT_TIMESTAMP,
            expires_at = excluded.expires_at
    ");
    $stmt->execute([
        $action,
        $key,
        $requestHash,
        $encoded,
        '+' . API_IDEMPOTENCY_RECEIPT_TTL_DAYS . ' days',
    ]);
}
