<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Tests\Support;

/**
 * A throwaway directory per test, removed recursively afterwards. Filesystem
 * tests need real modes, symlinks and owners, which a stream wrapper cannot
 * fake.
 */
final class TempTree
{
    private function __construct()
    {
    }

    public static function make(): string
    {
        $dir = \sys_get_temp_dir() . '/sugar-serve-protocol-' . \bin2hex(\random_bytes(6));
        if (!\mkdir($dir, 0o755) && !\is_dir($dir)) {
            throw new \RuntimeException('could not create temp dir ' . $dir);
        }

        return $dir;
    }

    public static function remove(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);

            return;
        }
        if (!\is_dir($path)) {
            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        @\rmdir($path);
    }
}
