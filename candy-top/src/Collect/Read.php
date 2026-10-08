<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Race-tolerant file primitives shared by the collectors.
 *
 * WHY every read is suppressed and folded to null: /proc and /sys entries
 * vanish between a directory listing and the open (a pid exits, a USB
 * battery is pulled), and some sysfs attributes exist but fail on read
 * (EINVAL from `carrier` on a downed link). btop wraps each of these in
 * try/catch and moves on; here the same tolerance is one null check at the
 * call site. Never throws.
 *
 * @internal collectors only — not part of candy-top's public surface.
 */
final class Read
{
    private function __construct()
    {
    }

    public static function file(string $path): ?string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /** First line, trimmed; null when unreadable or empty. */
    public static function line(string $path): ?string
    {
        $contents = self::file($path);
        if ($contents === null) {
            return null;
        }
        // Not strtok(...) ?: '' — a sysfs flag reading "0" is falsy.
        $line = trim(explode("\n", $contents, 2)[0]);

        return $line === '' ? null : $line;
    }

    public static function int(string $path): ?int
    {
        $line = self::line($path);

        return $line !== null && preg_match('/^-?\d+$/', $line) === 1 ? (int) $line : null;
    }

    public static function float(string $path): ?float
    {
        $line = self::line($path);

        return $line !== null && is_numeric($line) ? (float) $line : null;
    }

    /**
     * Directory entries without dot entries, natural-sorted so hwmon10
     * follows hwmon9 and output order is deterministic across hosts.
     *
     * @return list<string>
     */
    public static function entries(string $dir): array
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }
        $entries = array_values(array_filter($entries, static fn (string $e): bool => $e !== '.' && $e !== '..'));
        natsort($entries);

        return array_values($entries);
    }
}
