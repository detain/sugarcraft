<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Filesystem root every collector resolves /proc and /sys against.
 *
 * WHY a root prefix rather than per-file overrides (HostLoadSampler takes
 * one path per file): candy-top reads dozens of files across two pseudo
 * filesystems, and a test wants to swap the whole tree at once. Prefixing
 * "/proc/stat" with a fixture dir keeps every collector's path literals
 * identical to the kernel ABI they mirror, so a reader can grep btop's
 * paths and find ours.
 */
final class Paths
{
    private function __construct(
        public readonly string $root,
    ) {
    }

    /** The live host: paths resolve to the real /proc and /sys. */
    public static function system(): self
    {
        return new self('');
    }

    /** A fixture tree laid out like / (contains proc/, sys/, ...). */
    public static function under(string $root): self
    {
        return new self(rtrim($root, '/'));
    }

    /** Resolve an absolute kernel path ("/proc/stat") under the root. */
    public function path(string $absolute): string
    {
        return $this->root . '/' . ltrim($absolute, '/');
    }

    public function proc(string $relative = ''): string
    {
        return $this->path('/proc' . ($relative === '' ? '' : '/' . ltrim($relative, '/')));
    }

    public function sys(string $relative = ''): string
    {
        return $this->path('/sys' . ($relative === '' ? '' : '/' . ltrim($relative, '/')));
    }
}
