<?php
declare(strict_types=1);

function recipeCliCanonicalPath(string $path): string {
    if (!str_starts_with($path, '/')) {
        $path = getcwd() . '/' . $path;
    }
    $directory = realpath(dirname($path));
    if ($directory === false) {
        throw new InvalidArgumentException(
            'path directory does not exist'
        );
    }
    return $directory . '/' . basename($path);
}

function recipeCliSameFile(string $left, string $right): bool {
    if (!file_exists($left) || !file_exists($right)) {
        return false;
    }
    $leftStat = @stat($left);
    $rightStat = @stat($right);
    return is_array($leftStat)
        && is_array($rightStat)
        && (int)$leftStat['dev'] === (int)$rightStat['dev']
        && (int)$leftStat['ino'] === (int)$rightStat['ino'];
}

function recipeCliDatabaseSidecars(string $path): array {
    return [
        $path,
        $path . '-wal',
        $path . '-shm',
        $path . '-journal',
    ];
}

function recipeCliAssertDatabaseInputSafe(
    string $databasePath,
    bool $allowActive
): string {
    $databasePath = recipeCliCanonicalPath($databasePath);
    if (!is_file($databasePath)) {
        throw new InvalidArgumentException(
            'database copy does not exist'
        );
    }
    $activePath = recipeCliCanonicalPath(DB_PATH);
    if (
        !$allowActive
        && (
            $databasePath === $activePath
            || recipeCliSameFile($databasePath, $activePath)
        )
    ) {
        throw new RuntimeException(
            'the active database requires --allow-active-db'
        );
    }
    return $databasePath;
}

function recipeCliAssertOutputPathSafe(
    string $outputPath,
    string $databasePath
): string {
    $outputPath = recipeCliCanonicalPath($outputPath);
    if (file_exists($outputPath) && !is_file($outputPath)) {
        throw new InvalidArgumentException(
            'output path must be a regular file'
        );
    }
    $activePath = recipeCliCanonicalPath(DB_PATH);
    $forbidden = array_merge(
        recipeCliDatabaseSidecars($databasePath),
        recipeCliDatabaseSidecars($activePath)
    );
    foreach ($forbidden as $path) {
        if (
            $outputPath === $path
            || recipeCliSameFile($outputPath, $path)
        ) {
            throw new InvalidArgumentException(
                'output path collides with a database file'
            );
        }
    }
    return $outputPath;
}

function recipeCliWriteFileAtomically(
    string $path,
    string $content
): void {
    $path = recipeCliCanonicalPath($path);
    if (file_exists($path) && !is_file($path)) {
        throw new InvalidArgumentException(
            'output path must be a regular file'
        );
    }
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
    $handle = @fopen($temporary, 'xb');
    if ($handle === false) {
        throw new RuntimeException(
            'output temporary file could not be created'
        );
    }
    $written = 0;
    try {
        $length = strlen($content);
        while ($written < $length) {
            $count = fwrite(
                $handle,
                substr($content, $written)
            );
            if ($count === false || $count === 0) {
                throw new RuntimeException(
                    'output file could not be written completely'
                );
            }
            $written += $count;
        }
        if (!fflush($handle)) {
            throw new RuntimeException(
                'output file could not be flushed'
            );
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException(
                'output file could not be synchronized'
            );
        }
    } catch (Throwable $e) {
        fclose($handle);
        @unlink($temporary);
        throw $e;
    }
    fclose($handle);
    if (
        $written !== strlen($content)
        || !hash_equals(
            hash('sha256', $content),
            (string)hash_file('sha256', $temporary)
        )
    ) {
        @unlink($temporary);
        throw new RuntimeException(
            'output file verification failed'
        );
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException(
            'output file could not be published atomically'
        );
    }
    $directory = @fopen(dirname($path), 'r');
    if (is_resource($directory)) {
        if (function_exists('fsync')) {
            @fsync($directory);
        }
        fclose($directory);
    }
}
