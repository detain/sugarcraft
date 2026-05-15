<?php

declare(strict_types=1);

namespace SugarCraft\Vcr;

/**
 * First line of a cassette. Carries the format version, recording dimensions,
 * the runtime that produced the cassette, and the wall-clock creation time
 * (informational — Player ignores it).
 *
 * Mirrors charmbracelet/x/vcr CassetteHeader.
 */
final readonly class CassetteHeader
{
    public const CURRENT_VERSION = 1;

    public const TIMESTAMP_MODE_ABSOLUTE = 'absolute';
    public const TIMESTAMP_MODE_RELATIVE = 'relative';

    /** @param 'absolute'|'relative' $timestampMode */
    public function __construct(
        public int $version,
        public string $createdAt,
        public int $cols,
        public int $rows,
        public string $runtime,
        public string $timestampMode = self::TIMESTAMP_MODE_ABSOLUTE,
    ) {
        if ($version < 1) {
            throw new \InvalidArgumentException("CassetteHeader version must be >= 1, got {$version}");
        }
        if ($cols <= 0 || $rows <= 0) {
            throw new \InvalidArgumentException("CassetteHeader dimensions must be positive, got {$cols}x{$rows}");
        }
        if ($timestampMode !== self::TIMESTAMP_MODE_ABSOLUTE && $timestampMode !== self::TIMESTAMP_MODE_RELATIVE) {
            throw new \InvalidArgumentException(
                "CassetteHeader timestampMode must be 'absolute' or 'relative', got '{$timestampMode}'",
            );
        }
    }
}
