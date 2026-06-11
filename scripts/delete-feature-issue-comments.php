#!/usr/bin/env php
<?php
/** Delete all comments on open feature/enhancement backlog issues (English-only tracker policy). */
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

function ghRequest(string $token, string $method, string $url, ?array $body = null): array {
    $ch = curl_init($url);
    $headers = [
        'Authorization: token ' . $token,
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: EverShelf-Triage/1.0',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    } elseif ($method === 'GET') {
        // default
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $raw];
}

$issues = [122, 121, 120, 119, 118, 117, 116, 115, 114, 106, 105, 104, 103, 102, 101, 97, 93, 81, 80, 79, 69, 67, 65];
$deleted = 0;

foreach ($issues as $num) {
    $page = 1;
    while (true) {
        $url = 'https://api.github.com/repos/' . GH_REPO . "/issues/$num/comments?per_page=100&page=$page";
        $r = ghRequest($token, 'GET', $url);
        if ($r['code'] !== 200) {
            fwrite(STDERR, "#$num list comments HTTP {$r['code']}\n");
            break;
        }
        $comments = json_decode($r['body'], true);
        if (!is_array($comments) || empty($comments)) {
            break;
        }
        foreach ($comments as $c) {
            $id = (int)($c['id'] ?? 0);
            if ($id <= 0) continue;
            $dr = ghRequest($token, 'DELETE', 'https://api.github.com/repos/' . GH_REPO . "/issues/comments/$id");
            if ($dr['code'] === 204) {
                $deleted++;
                echo "deleted comment $id on #$num\n";
            } else {
                fwrite(STDERR, "FAIL delete comment $id on #$num HTTP {$dr['code']}\n");
            }
            usleep(200000);
        }
        if (count($comments) < 100) break;
        $page++;
    }
}

echo "Done. Deleted $deleted comments.\n";
