#!/usr/bin/env php
<?php
/**
 * Triage resolved auto-report bugs only (English comments).
 * Feature/enhancement backlog issues are never bulk-closed here.
 * Usage: php scripts/triage-open-issues.php [--dry-run]
 */
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/lib/github.php';
require_once __DIR__ . '/../api/lib/constants.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$repo   = GH_REPO;
$token  = _ghToken();

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

function commentIssue(string $token, string $repo, int $num, string $body, bool $dryRun): bool {
    if ($dryRun) {
        echo "[dry-run] comment #$num\n";
        return true;
    }
    $r = ghApi($token, 'POST', "https://api.github.com/repos/$repo/issues/$num/comments", ['body' => $body]);
    if ($r['http_code'] >= 200 && $r['http_code'] < 300) {
        echo "OK comment #$num\n";
        return true;
    }
    fwrite(STDERR, "FAIL comment #$num HTTP {$r['http_code']}: " . json_encode($r['body']) . "\n");
    return false;
}

function closeIssue(string $token, string $repo, int $num, bool $dryRun): bool {
    if ($dryRun) {
        echo "[dry-run] close #$num\n";
        return true;
    }
    $r = ghApi($token, 'PATCH', "https://api.github.com/repos/$repo/issues/$num", ['state' => 'closed']);
    if ($r['http_code'] >= 200 && $r['http_code'] < 300) {
        echo "OK close #$num\n";
        return true;
    }
    fwrite(STDERR, "FAIL close #$num HTTP {$r['http_code']}: " . json_encode($r['body']) . "\n");
    return false;
}

$bugs = [
    198 => 'Fixed in develop: `PRAGMA busy_timeout` raised to 10s and `dbWithRetry()` on `updateInventory` retries SQLITE_BUSY when cron and PWA write in parallel.',
    199 => 'Duplicate of #198 — same event (`inventory_update` → database locked). Fix: retry + longer busy_timeout.',
    196 => 'Fixed in v1.7.38+: `saveProduct` handles duplicate barcodes (merge or 409 JSON) instead of HTTP 500.',
    197 => 'PWA side-effect of PHP crash #196 — fixed with duplicate barcode handling in `saveProduct`.',
    195 => 'Fixed: `EverLog::request()` always receives strings — `(string)($_SERVER[\'REQUEST_METHOD\'] ?? \'GET\')`.',
    193 => 'Same root cause as #195 (TypeError when method was null from CLI).',
    194 => 'Fixed: `_applySpesaScanUI` referenced `currentPage` → corrected to `_currentPageId`.',
    192 => 'Fixed: TDZ on `enriched` in `renderShoppingItems`.',
    191 => 'Fixed: TDZ on `setProgress` / `barEl` in `_runStartupCheck`.',
    134 => 'Auto-report for non-writable Docker volume. Mitigations: `_ensureDataDir()`, `_ensureDbWritable()`, Dockerfile chown.',
    184 => 'Related to #134: SQLite readonly when `data/` is not writable.',
];

foreach ($bugs as $num => $msg) {
    commentIssue($token, $repo, $num, $msg . "\n\n_Closed after triage — fix shipped in develop._", $dryRun);
    closeIssue($token, $repo, $num, $dryRun);
}

echo "Done.\n";
