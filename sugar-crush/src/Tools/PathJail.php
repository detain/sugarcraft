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