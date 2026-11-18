<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Support;

/**
 * Throwaway directories and files for the loader and manifest tests.
 *
 * Every tree is made under the system temp directory with a unique name, and
 * {@see remove()} takes it back down, so a failed test leaves nothing that a
 * later test could mistake for a plugin.
 */
final class Scratch
{
    public static function dir(string $label): string
    {
        $dir = \sys_get_temp_dir() . '/sugar-plugins-' . $label . '-' . \bin2hex(\random_bytes(6));
        if (!\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new \RuntimeException('could not create ' . $dir);
        }

        return $dir;
    }

    public static function put(string $path, string $contents): string
    {
        $parent = \dirname($path);
        if (!\is_dir($parent) && !\mkdir($parent, 0755, true) && !\is_dir($parent)) {
            throw new \RuntimeException('could not create ' . $parent);
        }
        \file_put_contents($path, $contents);

        return $path;
    }

    public static function remove(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\chmod($path, 0644);
            @\unlink($path);

            return;
        }

        if (!\is_dir($path)) {
            return;
        }

        @\chmod($path, 0755);
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::remove($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
