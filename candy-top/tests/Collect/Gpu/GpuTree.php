<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Gpu;

use SugarCraft\Top\Collect\Paths;

/**
 * A disposable root built from tests/fixtures/gpu/<vendor> trees (merged
 * when several are named) plus a writable /proc for DRM fdinfo: each
 * fd() call makes a real `/proc/<pid>/fd/<n>` symlink to a /dev/dri node
 * (dangling on purpose — the scanner only reads the link text) and its
 * fdinfo file, so rate tests can rewrite counters between samples.
 */
final class GpuTree
{
    private function __construct(
        public readonly string $root,
    ) {
    }

    public static function of(string ...$vendors): self
    {
        $root = sys_get_temp_dir() . '/candy-top-gpu-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        foreach ($vendors as $vendor) {
            self::copyDir(self::fixtures() . '/' . $vendor, $root);
        }

        return new self($root);
    }

    public static function fixtures(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/gpu';
    }

    public static function sample(string $name): string
    {
        return (string) file_get_contents(self::fixtures() . '/fdinfo/' . $name);
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

    /** An fd of `$pid` open on `$target` whose fdinfo is `$fdinfo` (text or a fixtures/gpu/fdinfo sample name). */
    public function fd(int $pid, int $fd, string $fdinfo, string $target = '/dev/dri/renderD128'): void
    {
        $text = str_ends_with($fdinfo, '.txt') ? self::sample($fdinfo) : $fdinfo;
        $this->write("proc/$pid/fdinfo/$fd", $text);
        @mkdir("{$this->root}/proc/$pid/fd", 0777, true);
        @unlink("{$this->root}/proc/$pid/fd/$fd");
        symlink($target, "{$this->root}/proc/$pid/fd/$fd");
    }

    public function destroy(): void
    {
        self::removeDir($this->root);
    }

    private static function copyDir(string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0777, true);
        }
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
