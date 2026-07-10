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
     * A leading slash on $relative is treated as fixtures-relative (stripped),
     * NOT as an absolute filesystem path. Any $relative that would escape the
     * `<baseDir>/fixtures` base via `..` traversal is rejected — golden files
     * must live under the base dir, so a test (or a poisoned relative path)
     * cannot read or overwrite files elsewhere on disk.
     *
     * @param string $baseDir  Directory the test file lives in
     * @param string $relative Relative path within fixtures/
     * @return string Resolved absolute path
     * @throws \RuntimeException if $relative escapes the fixtures base dir
     */
    public static function resolve(string $baseDir, string $relative): string
    {
        $base = \rtrim($baseDir, '/') . '/fixtures';
        $full = $base . '/' . \ltrim($relative, '/');

        // realpath() can't be used here: golden files may not exist yet (they
        // are resolved for both reads and first-time writes), so normalize the
        // '..'/'.' segments lexically and confirm the result stays under base.
        $normBase = self::normalizePath($base);
        $normFull = self::normalizePath($full);

        if ($normFull !== $normBase && !\str_starts_with($normFull, $normBase . '/')) {
            throw new \RuntimeException(Lang::t('golden.path_traversal', ['path' => $relative]));
        }

        return $full;
    }

    /**
     * Lexically collapse `.`/`..`/empty segments in a path.
     *
     * Absolute inputs stay anchored at `/` and a `..` at the root is dropped
     * (you cannot climb above `/`). Purely lexical — the path need not exist.
     *
     * @param string $path
     * @return string
     */
    private static function normalizePath(string $path): string
    {
        $isAbsolute = \str_starts_with($path, '/');
        $out = [];

        foreach (\explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($out !== [] && \end($out) !== '..') {
                    \array_pop($out);
                } elseif (!$isAbsolute) {
                    $out[] = '..';
                }
                continue;
            }

            $out[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . \implode('/', $out);
    }
}
