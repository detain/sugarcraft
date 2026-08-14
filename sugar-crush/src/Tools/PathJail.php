<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

final class PathJail
{
    public static function resolve(string $root, string $path): ?string
    {
        $rootReal = realpath($root);
        if ($rootReal === false) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            $fullPath = $path;
        } else {
            $fullPath = $rootReal . '/' . ltrim($path, '/');
        }
        $resolved = realpath($fullPath);

        if ($resolved === false) {
            $resolvedDir = realpath(dirname($fullPath));
            if ($resolvedDir !== false && (str_starts_with($resolvedDir, $rootReal . '/') || $resolvedDir === $rootReal)) {
                return $fullPath;
            }
            return null;
        }

        // The jail root itself is a valid in-jail path — accept an exact match
        // as well as any descendant. Requiring only the `$rootReal . '/'` prefix
        // rejected the root, which is an off-by-one against the jail boundary.
        if ($resolved !== $rootReal && !str_starts_with($resolved, $rootReal . '/')) {
            return null;
        }

        return $resolved;
    }

    /**
     * Resolve a path that is allowed NOT TO EXIST YET, including any number of
     * missing parent directories (crush_code.md Phase 8 item 12).
     *
     * {@see resolve()} accepts a missing FILE, but only when its immediate
     * parent already exists — `realpath(dirname($fullPath))` is its containment
     * anchor. That is exactly right for {@see \SugarCraft\Crush\Tools\BuiltIn\Edit},
     * which can only ever touch a file that is already there, and exactly wrong
     * for {@see \SugarCraft\Crush\Tools\BuiltIn\Write}: creating `docs/new/x.md`
     * would be reported as "outside workspace root" when it is plainly inside it.
     *
     * Containment is settled by normalizing `.`/`..` LEXICALLY first (so
     * `../../etc/passwd` cannot be laundered through a directory that does not
     * exist) and then realpath()ing the nearest ancestor that DOES exist. The
     * segments below that ancestor are safe to trust unresolved for the simple
     * reason that they do not exist, and a path that does not exist cannot be a
     * symlink pointing out of the jail.
     *
     * @return string|null The absolute path to write, or null if it escapes $root.
     */
    public static function resolveForCreate(string $root, string $path): ?string
    {
        $rootReal = realpath($root);
        if ($rootReal === false || $path === '') {
            return null;
        }

        $fullPath = str_starts_with($path, '/')
            ? $path
            : $rootReal . '/' . ltrim($path, '/');

        $resolved = realpath($fullPath);
        if ($resolved !== false) {
            // Already exists: settle it with the strict, fully-resolved check.
            return $resolved === $rootReal || str_starts_with($resolved, $rootReal . '/')
                ? $resolved
                : null;
        }

        $normalized = self::normalizeLexically($fullPath);

        $ancestor = \dirname($normalized);
        while (!is_dir($ancestor) && $ancestor !== \dirname($ancestor)) {
            $ancestor = \dirname($ancestor);
        }

        $ancestorReal = realpath($ancestor);
        if ($ancestorReal === false) {
            return null;
        }
        if ($ancestorReal !== $rootReal && !str_starts_with($ancestorReal, $rootReal . '/')) {
            return null;
        }

        $tail = ltrim(substr($normalized, strlen(rtrim($ancestor, '/'))), '/');
        if ($tail === '') {
            return null;
        }

        return rtrim($ancestorReal, '/') . '/' . $tail;
    }

    /**
     * Collapse `.` and `..` segments without touching the filesystem, so a
     * traversal through a not-yet-existing directory still lands where it
     * really points. `..` at the top is dropped rather than escaping `/`,
     * matching how the kernel treats `/..`.
     */
    private static function normalizeLexically(string $absolutePath): string
    {
        $out = [];
        foreach (explode('/', $absolutePath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }

    public static function resolveDir(string $root, string $path): ?string
    {
        $rootReal = realpath($root);
        if ($rootReal === false) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            $fullPath = $path;
        } else {
            $fullPath = $rootReal . '/' . ltrim($path, '/');
        }
        $resolved = realpath($fullPath);

        if ($resolved === false) {
            return null;
        }

        // Same jail-boundary rule as resolve(): the root itself is in-jail.
        if ($resolved !== $rootReal && !str_starts_with($resolved, $rootReal . '/')) {
            return null;
        }

        return $resolved;
    }
}