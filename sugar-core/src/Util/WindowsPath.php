<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Pure Windows path normalisation behind {@see PathJail}.
 *
 * Nothing here touches the filesystem, so every rule is testable on any
 * platform with Windows-style fixtures. The canonical form is an ANCHOR plus
 * segments, rendered as `C:\a\b` (drive letter upper-cased) or `\\server\share\a\b`
 * (UNC). Every method answers `null` for a path it cannot normalise with
 * certainty, and a caller treats `null` as a hard refusal.
 *
 * Refused, because the lexical answer could differ from what Windows opens:
 *
 *   - rooted paths without a drive (`\x`) and drive-relative paths (`C:x`, `C:`),
 *     whose meaning depends on the process's current drive or directory;
 *   - device and extended namespaces (`\\?\...`, `\\.\...`);
 *   - a UNC path without both a server and a share;
 *   - segments carrying `<>:"|?*` or a control character, which covers alternate
 *     data streams (`file:stream`);
 *   - segments ending in a dot or a space, which Windows strips, so two names
 *     would reach one file;
 *   - reserved device names (`CON`, `NUL.txt`, `COM1`, ...), which open a device
 *     rather than a file under the root;
 *   - 8.3 short-name forms (`PROGRA~1`), which can alias a long name;
 *   - a `..` that climbs above the drive root or the share.
 *
 * Drive letters compare case-insensitively (the anchor is upper-cased). Segments
 * compare case-sensitively: NTFS usually ignores case, so a case-sensitive match
 * can only refuse a path that is inside, never admit one that is outside.
 */
final class WindowsPath
{
    /**
     * The canonical absolute form of $path, with `..` kept, or null.
     *
     * A relative $path is joined onto $root, which must be absolute. An absolute
     * $path is validated on its own and $root is then ignored.
     */
    public static function absolutise(string $root, string $path): ?string
    {
        $parts = self::resolve($root, $path);

        return $parts === null ? null : self::render($parts[0], $parts[1]);
    }

    /**
     * The unnormalised [anchor, segments] of $path under $root, keeping any `..`,
     * or null.
     *
     * $root is trusted: a jail root is canonical (realpath() answers with long
     * names), so its names are not checked for 8.3 short-name aliases. Only the
     * path the caller typed is checked for them, because only that can alias.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function resolve(string $root, string $path): ?array
    {
        $rootParts = self::split($root, trusted: true);
        if ($rootParts === null) {
            return null;
        }
        [$anchor, $rootSegments] = $rootParts;

        if (self::isAbsolute($path)) {
            $typed = self::split($path, trusted: true);
            if ($typed === null) {
                return null;
            }
            [$typedAnchor, $typedSegments] = $typed;
            if ($typedAnchor !== $anchor || array_slice($typedSegments, 0, count($rootSegments)) !== $rootSegments) {
                return self::parts($path);
            }

            // The leading segments spell the root itself, so only the rest is the
            // caller's own spelling and gets the short-name check.
            foreach (array_slice($typedSegments, count($rootSegments)) as $segment) {
                if ($segment !== '..' && !self::validName($segment, trusted: false)) {
                    return null;
                }
            }

            return [$anchor, $typedSegments];
        }

        // Rooted (`\x`, `/x`) and drive-relative (`C:x`) paths name a location that
        // depends on the process, so they cannot be placed under $root with certainty.
        if (preg_match('#^[\\\\/]#', $path) === 1 || preg_match('#^[A-Za-z]:#', $path) === 1) {
            return null;
        }

        $relative = self::segments($path, trusted: false);

        return $relative === null ? null : [$anchor, [...$rootSegments, ...$relative]];
    }

    /**
     * The canonical form of an absolute $path with every `.` and `..` collapsed,
     * or null when it cannot be collapsed (a `..` above the anchor, or a name
     * that fails validation).
     */
    public static function collapse(string $absolute): ?string
    {
        $parts = self::parts($absolute);
        if ($parts === null) {
            return null;
        }

        $normal = self::normalise($parts);

        return $normal === null ? null : self::render($normal[0], $normal[1]);
    }

    /**
     * [anchor, segments] with every `..` applied, or null when one climbs above
     * the anchor. The input segments must already be validated.
     *
     * @param array{0: string, 1: list<string>} $parts
     * @return array{0: string, 1: list<string>}|null
     */
    public static function normalise(array $parts): ?array
    {
        [$anchor, $segments] = $parts;

        $stack = [];
        foreach ($segments as $segment) {
            if ($segment !== '..') {
                $stack[] = $segment;
                continue;
            }
            if ($stack === []) {
                return null;
            }
            array_pop($stack);
        }

        return [$anchor, $stack];
    }

    /**
     * The collapsed candidate for $path under $root, when it lies at or below
     * $root; null otherwise.
     *
     * This is the lexical verdict only. It does not follow symbolic links or
     * junctions, so a caller on a real filesystem must also rule out a `..`
     * that follows a reparse point before it trusts the answer.
     */
    public static function within(string $root, string $path): ?string
    {
        $rootParts = self::split($root, trusted: true);
        $rootCanonical = $rootParts === null ? null : self::normalise($rootParts);
        if ($rootCanonical === null) {
            return null;
        }
        [$rootAnchor, $rootSegments] = $rootCanonical;

        $resolved = self::resolve(self::render($rootAnchor, $rootSegments), $path);
        $candidate = $resolved === null ? null : self::normalise($resolved);
        if ($candidate === null) {
            return null;
        }
        [$anchor, $segments] = $candidate;

        if ($anchor !== $rootAnchor) {
            return null;
        }

        return array_slice($segments, 0, count($rootSegments)) === $rootSegments
            ? self::render($anchor, $segments)
            : null;
    }

    /**
     * Whether $path starts with a drive letter and separator, or with a UNC
     * prefix. A bare `C:` or a rooted `\x` is not absolute.
     */
    public static function isAbsolute(string $path): bool
    {
        return preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            || preg_match('#^[\\\\/]{2}#', $path) === 1;
    }

    /**
     * Split an absolute path into [anchor, segments], or null. The segments keep
     * any `..`. Used by {@see PathJail} to walk a path one component at a time.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function parts(string $path): ?array
    {
        return self::split($path, trusted: false);
    }

    /**
     * Render an anchor and segments in the canonical form.
     *
     * @param list<string> $segments
     */
    public static function render(string $anchor, array $segments): string
    {
        return $anchor . '\\' . implode('\\', $segments);
    }

    /**
     * {@see parts()}, with $trusted passed down to the 8.3 short-name rule.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private static function split(string $path, bool $trusted): ?array
    {
        if (str_contains($path, "\0")) {
            return null;
        }

        if (preg_match('#^([A-Za-z]):[\\\\/](.*)$#s', $path, $match) === 1) {
            $anchor = strtoupper($match[1]) . ':';
            $tail = $match[2];
        } elseif (preg_match('#^[\\\\/]{2}([^\\\\/]+)[\\\\/]+([^\\\\/]+)(?:[\\\\/]+(.*))?$#s', $path, $match) === 1) {
            if (!self::validName($match[1], $trusted) || !self::validName($match[2], $trusted)) {
                return null;
            }
            $anchor = '\\\\' . $match[1] . '\\' . $match[2];
            $tail = $match[3] ?? '';
        } else {
            return null;
        }

        $segments = self::segments($tail, $trusted);

        return $segments === null ? null : [$anchor, $segments];
    }

    /**
     * Split a tail on any run of separators, dropping empty and `.` parts and
     * keeping `..`. Null when a real name fails validation.
     *
     * @return list<string>|null
     */
    private static function segments(string $tail, bool $trusted): ?array
    {
        $out = [];
        foreach (preg_split('#[\\\\/]+#', $tail) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $out[] = $segment;
                continue;
            }
            if (!self::validName($segment, $trusted)) {
                return null;
            }
            $out[] = $segment;
        }

        return $out;
    }

    /**
     * Whether one name is safe to treat as exactly one file or directory.
     *
     * $trusted skips only the 8.3 short-name rule, for a canonical root. Every
     * other rule applies to a root too.
     */
    private static function validName(string $name, bool $trusted): bool
    {
        if ($name === '' || preg_match('/[\x00-\x1F<>:"|?*]/', $name) === 1) {
            return false;
        }

        // Windows strips trailing dots and spaces, and a leading space is not
        // something a caller means by a name, so all three are refused.
        if (str_ends_with($name, '.') || str_ends_with($name, ' ') || str_starts_with($name, ' ')) {
            return false;
        }

        // A short-name alias such as PROGRA~1 opens a different long name.
        if (!$trusted && preg_match('/~[0-9]/', $name) === 1) {
            return false;
        }

        $base = strtoupper(rtrim(explode('.', $name, 2)[0], ' '));
        if (in_array($base, ['CON', 'PRN', 'AUX', 'NUL', 'CONIN$', 'CONOUT$'], true)) {
            return false;
        }

        return preg_match('/^(COM|LPT)[0-9\x{00B9}\x{00B2}\x{00B3}]$/u', $base) !== 1;
    }
}
