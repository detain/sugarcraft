<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use SugarCraft\Top\Collect\Paths;

/**
 * libdrm's amdgpu.ids marketing-name table (btop #1854): Strix Halo /
 * Strix Point and other APUs share one PCI device id across tiers, so
 * pci.ids gives "[Radeon Graphics / 8050S / 8060S]" and only the
 * (device, revision) pair names the part. Lines are
 * `DEVICE,\tREVISION,\tNAME` in hex; the first non-comment line is the
 * table version.
 *
 * Located at run time (SEARCH, then /opt/rocm* installs) — the ~23 KB
 * table is not shipped. Parsed once into a map by {@see locate()}; the
 * AMD backend asks it once per device at discovery.
 */
final class AmdGpuIds
{
    public const array SEARCH = [
        '/usr/share/libdrm/amdgpu.ids',
        '/usr/local/share/libdrm/amdgpu.ids',
        '/opt/amdgpu/share/libdrm/amdgpu.ids',
    ];

    /** Globs tried after SEARCH (ROCm installs carry their own libdrm copy). */
    public const array ROCM_GLOBS = [
        '/opt/rocm*/share/libdrm/amdgpu.ids',
        '/opt/rocm*/lib/libdrm/amdgpu.ids',
    ];

    /**
     * @param array<string, string> $names "DEVICE,REV" (upper hex) → name
     */
    private function __construct(
        public readonly ?string $file,
        private readonly array $names,
    ) {
    }

    /**
     * @param list<string>|null $search null = SEARCH + ROCM_GLOBS
     */
    public static function locate(?Paths $paths = null, ?array $search = null): self
    {
        $paths ??= Paths::system();
        $candidates = [];
        foreach ($search ?? self::SEARCH as $candidate) {
            $candidates[] = $paths->path($candidate);
        }
        if ($search === null) {
            foreach (self::ROCM_GLOBS as $glob) {
                foreach (glob($paths->path($glob)) ?: [] as $hit) {
                    $candidates[] = $hit;
                }
            }
        }
        foreach ($candidates as $file) {
            if (is_file($file) && is_readable($file)) {
                return self::at($file);
            }
        }

        return new self(null, []);
    }

    public static function at(?string $file): self
    {
        $contents = $file === null ? false : @file_get_contents($file);
        if ($contents === false) {
            return new self(null, []);
        }
        $names = [];
        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^([0-9a-f]{1,4}),\s*([0-9a-f]{1,2}),\s*(.+)$/i', trim($line), $m) === 1) {
                $names[sprintf('%04X,%02X', hexdec($m[1]), hexdec($m[2]))] = PciIds::clean($m[3]);
            }
        }

        return new self($file, $names);
    }

    public function name(int $device, int $revision): ?string
    {
        $name = $this->names[sprintf('%04X,%02X', $device, $revision)] ?? null;

        return $name === '' ? null : $name;
    }

    public function count(): int
    {
        return count($this->names);
    }
}
