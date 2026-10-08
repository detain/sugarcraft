<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Support;

use SugarCraft\Top\Collect\Paths;

/**
 * A disposable, writable copy of tests/fixtures/linux so delta tests can
 * rewrite counters between samples and race tests can delete a pid
 * mid-scan without touching the committed fixtures.
 */
final class FixtureTree
{
    private function __construct(
        public readonly string $root,
    ) {
    }

    public static function copy(): self
    {
        $root = sys_get_temp_dir() . '/candy-top-fixture-' . bin2hex(random_bytes(6));
        self::copyDir(dirname(__DIR__, 2) . '/fixtures/linux', $root);

        return new self($root);
    }

    /** An empty tree: every collector should degrade to its sentinels. */
    public static function empty(): self
    {
        $root = sys_get_temp_dir() . '/candy-top-empty-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);

        return new self($root);
    }

    public static function committed(): Paths
    {
        return Paths::under(dirname(__DIR__, 2) . '/fixtures/linux');
    }

    public function paths(): Paths
    {
        return Paths::under($this->root);
    }

    public function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    public function remove(string $relative): void
    {
        $path = $this->root . '/' . $relative;
        is_dir($path) ? self::removeDir($path) : @unlink($path);
    }

    public function destroy(): void
    {
        self::removeDir($this->root);
    }

    private static function copyDir(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            is_dir("$from/$entry") ? self::copyDir("$from/$entry", "$to/$entry") : copy("$from/$entry", "$to/$entry");
        }
    }

    private static function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            is_dir("$dir/$entry") && !is_link("$dir/$entry") ? self::removeDir("$dir/$entry") : @unlink("$dir/$entry");
        }
        @rmdir($dir);
    }
}
