#!/usr/bin/env php
<?php
/** Reopen wrongly closed feature issues; close resolved auto-report bugs (English). */
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/lib/github.php';
require_once __DIR__ . '/../api/lib/constants.php';

$token = _ghToken();
if ($token === '') {
    fwrite(STDERR, "ERROR: GH_ISSUE_TOKEN not configured\n");
    exit(1);
}

function ghApi(string $token, string $method, string $url, array $payload = []): array {
    $ch = curl_init($url);
    $headers = [
        'Authorization: token ' . $token,
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: EverShelf-Triage/1.0',
        'Content-Type: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http_code' => $code, 'body' => json_decode($raw ?: '{}', true) ?: []];
}

function comment(string $token, int $num, string $body): void {
    $r = ghApi($token, 'POST', 'https://api.github.com/repos/' . GH_REPO . "/issues/$num/comments", ['body' => $body]);
    echo $r['http_code'] >= 200 && $r['http_code'] < 300 ? "OK comment #$num\n" : "FAIL comment #$num\n";
}

function closeIssue(string $token, int $num): void {
    $r = ghApi($token, 'PATCH', 'https://api.github.com/repos/' . GH_REPO . "/issues/$num", ['state' => 'closed']);
    echo $r['http_code'] >= 200 && $r['http_code'] < 300 ? "OK close #$num\n" : "FAIL close #$num\n";
}

function reopenIssue(string $token, int $num): void {
    $r = ghApi($token, 'PATCH', 'https://api.github.com/repos/' . GH_REPO . "/issues/$num", ['state' => 'open']);
    echo $r['http_code'] >= 200 && $r['http_code'] < 300 ? "OK reopen #$num\n" : "FAIL reopen #$num\n";
}

$reopen = [
    125 => "Reopened: **voice commands in cooking mode** are not implemented yet (only TTS readout exists). This was closed by mistake during bulk triage — the feature backlog should stay open until hands-free step navigation ships.",
    98  => "Reopened: **pin favourite products to the top of inventory** is not implemented yet (recipe favourites #124 are done, but product pinning is a separate request). Closed by mistake — keeping on the backlog.",
];

foreach ($reopen as $num => $msg) {
    comment($token, $num, $msg);
    reopenIssue($token, $num);
}

$bugs = [
    201 => 'Fixed in latest develop: `inventory_use` and `shopping_add` now retry on `SQLITE_BUSY` via `dbWithRetry()` (same pattern as #198).',
    202 => 'Fixed: Bring/internal `shopping_add` wrapped in `dbWithRetry()` to survive cron + PWA concurrent writes.',
    203 => 'Fixed: `smartShopping()` / `smartShoppingCached()` now call `set_time_limit(120)` so large pantries no longer hit the 30s PHP fatal.',
    204 => 'Fixed: same as #203 — smart shopping timeout caused HTTP 500; extended execution limit resolves the crash.',
];

foreach ($bugs as $num => $msg) {
    comment($token, $num, $msg . "\n\n_Closed after triage — fix shipped in develop._");
    closeIssue($token, $num);
}

echo "Done.\n";
