<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Snapshot;

use SugarCraft\Testing\Lang;

/**
 * Load/save helper for golden ANSI snapshot files.
 *
 * Golden files store expected output bytes for regression testing.
 * They use the `.golden` extension convention and live under
 * a `tests/fixtures/` directory relative to the test file.
 *
 * @see Mirrors charmbracelet/bubbletea — golden file pattern (issue #1654)
 */
final class GoldenFile
{
    /**
     * Load a golden file's contents.
     *
     * @param string $path Absolute or relative path to the golden file
     * @return string|null The file contents, or null if the file does not exist
     */
    public static function load(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = \file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Save content to a golden file.
     *
     * Creates parent directories if they don't exist.
     *
     * @param string $path    Absolute or relative path to the golden file
     * @param string $content The bytes to write
     * @return void
     */
    public static function save(string $path, string $content): void
    {
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0755, true);
        }

        $result = file_put_contents($path, $content);

        if ($result === false || $result === 0) {
            throw new \RuntimeException(Lang::t('golden.write_failed', ['path' => $path]));
        }
    }

    /**
     * Resolve a fixture-relative path to an absolute path.
     *
     * @param string $baseDir  Directory the test file lives in
     * @param string $relative Relative path within fixtures/
     * @return string Resolved absolute path
     */
    public static function resolve(string $baseDir, string $relative): string
    {
        return $baseDir . '/fixtures/' . ltrim($relative, '/');
    }
}
