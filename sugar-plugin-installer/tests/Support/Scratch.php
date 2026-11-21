<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller\Tests\Support;

/**
 * Throwaway directory trees for the installer tests. Everything lives under the
 * system temp directory and is removed by the test that made it.
 */
final class Scratch
{
    private function __construct()
    {
    }

    public static function dir(string $label): string
    {
        $path = sys_get_temp_dir() . '/sugar-plugin-installer-' . $label . '-' . bin2hex(random_bytes(6));
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException("cannot create scratch directory $path");
        }

        return $path;
    }

    public static function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create $dir");
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("cannot write $path");
        }
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    /**
     * Maps every regular file under $dir to its sha1, skipping the top-level
     * entries named in $skip. Two snapshots compare equal only when the same
     * files hold the same bytes.
     *
     * @param list<string> $skip
     *
     * @return array<string, string>
     */
    public static function snapshot(string $dir, array $skip = []): array
    {
        $out = [];
        $walk = static function (string $current, string $prefix) use (&$walk, &$out, $skip): void {
            foreach (scandir($current) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if ($prefix === '' && in_array($entry, $skip, true)) {
                    continue;
                }
                $full = $current . '/' . $entry;
                $rel = $prefix === '' ? $entry : $prefix . '/' . $entry;
                if (is_dir($full)) {
                    $walk($full, $rel);
                } else {
                    $out[$rel] = (string) sha1_file($full);
                }
            }
        };
        $walk($dir, '');
        ksort($out);

        return $out;
    }
}
