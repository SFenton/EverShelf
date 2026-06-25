<?php
/**
 * EverShelf — environment variable loader (.env).
 */

function loadEnv(bool $forceReload = false): array {
    static $cache = null;
    if ($forceReload) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }
    $envFile = dirname(__DIR__, 2) . '/.env';
    $cache = [];
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            [$key, $val] = explode('=', $line, 2);
            $cache[trim($key)] = trim($val);
        }
    }
    return $cache;
}

function env(string $key, string $default = ''): string {
    $vars = loadEnv();
    return $vars[$key] ?? $default;
}

function envCacheClear(): void {
    loadEnv(true);
}

/** Push a single key into the in-memory env cache (after .env write). */
function envCacheSet(string $key, string $value): void {
    envCacheClear();
    loadEnv();
    // Force reload on next call - callers should use loadEnv() return for batch updates.
}

function evershelfFormatEnvVars(array $envVars, ?string $existingFile = null): string {
    if ($existingFile !== null && file_exists($existingFile)) {
        $lines = file($existingFile, FILE_IGNORE_NEW_LINES);
        if ($lines !== false) {
            $out = [];
            $remaining = $envVars;

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#') || strpos($line, '=') === false) {
                    $out[] = $line;
                    continue;
                }

                [$key] = explode('=', $line, 2);
                $key = trim($key);
                if (array_key_exists($key, $envVars)) {
                    $out[] = "{$key}={$envVars[$key]}";
                    unset($remaining[$key]);
                } else {
                    $out[] = $line;
                }
            }

            foreach ($remaining as $key => $val) {
                $out[] = "{$key}={$val}";
            }

            return implode("\n", $out) . "\n";
        }
    }

    $lines = [];
    foreach ($envVars as $key => $val) {
        $lines[] = "{$key}={$val}";
    }
    return implode("\n", $lines) . "\n";
}

function evershelfWriteFileInPlace(string $path, string $contents): int|false {
    if (!file_exists($path)) {
        return file_put_contents($path, $contents, LOCK_EX);
    }

    $handle = @fopen($path, 'r+b');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }

        rewind($handle);
        $length = strlen($contents);
        $written = 0;
        while ($written < $length) {
            $chunk = fwrite($handle, substr($contents, $written));
            if ($chunk === false || $chunk === 0) {
                return false;
            }
            $written += $chunk;
        }

        if (!ftruncate($handle, $written)) {
            return false;
        }
        if (!fflush($handle)) {
            return false;
        }

        return $written;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
