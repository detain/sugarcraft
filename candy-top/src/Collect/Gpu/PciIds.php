<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use SugarCraft\Top\Collect\Paths;

/**
 * Device names from the system's pci.ids database (the hwdata / pciutils
 * file) — #1888's Intel naming source and #1854's second AMD fallback.
 *
 * The file is ~1.4 MB, so it is never loaded whole: name() streams it
 * line by line, stops at the end of the vendor's block, and backends call
 * it once per device at discovery, never per sample. Nothing is shipped:
 * the file is found at run time in SEARCH order.
 */
final class PciIds
{
    public const array SEARCH = [
        '/usr/share/hwdata/pci.ids',
        '/usr/share/misc/pci.ids',
        '/usr/share/pci.ids',
        '/usr/local/share/hwdata/pci.ids',
        '/usr/local/share/pciids/pci.ids',
    ];

    private function __construct(
        public readonly ?string $file,
    ) {
    }

    /**
     * The first readable SEARCH entry, resolved under `$paths` (a fixture
     * root in tests).
     *
     * @param list<string>|null $search null = SEARCH
     */
    public static function locate(?Paths $paths = null, ?array $search = null): self
    {
        $paths ??= Paths::system();
        foreach ($search ?? self::SEARCH as $candidate) {
            $file = $paths->path($candidate);
            if (is_file($file) && is_readable($file)) {
                return new self($file);
            }
        }

        return new self(null);
    }

    /** A specific file (or none). */
    public static function at(?string $file): self
    {
        return new self($file);
    }

    /** The device name for vendor:device, or null when unknown / no database. */
    public function name(int $vendor, int $device): ?string
    {
        if ($this->file === null) {
            return null;
        }
        $fh = @fopen($this->file, 'r');
        if ($fh === false) {
            return null;
        }
        $vendorHex = sprintf('%04x', $vendor);
        $deviceHex = sprintf('%04x', $device);
        $inVendor = false;
        $name = null;
        try {
            while (($line = fgets($fh)) !== false) {
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if ($line[0] !== "\t") {
                    if ($inVendor) {
                        break; // past the vendor's block
                    }
                    if (str_starts_with($line, 'C ')) {
                        break; // device classes follow the vendor list
                    }
                    $inVendor = strncasecmp($line, $vendorHex . '  ', 6) === 0;

                    continue;
                }
                if ($inVendor && ($line[1] ?? '') !== "\t" && strncasecmp(substr($line, 1), $deviceHex . '  ', 6) === 0) {
                    $name = self::clean(substr($line, 7));

                    break;
                }
            }
        } finally {
            fclose($fh);
        }

        return $name === '' ? null : $name;
    }

    /**
     * Free text printed to the terminal, whitelisted to printable ASCII
     * (the same law as the nvidia-smi parser: no control bytes reach the
     * renderer from a data file).
     */
    public static function clean(string $v): string
    {
        return trim((string) preg_replace('/[^\x20-\x7e]/', '', $v));
    }
}
