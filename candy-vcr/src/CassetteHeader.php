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

    /**
     * @param 'absolute'|'relative'   $timestampMode
     * @param array<string, string>   $env
     *   Filtered environment captured at record time. Empty (default) when
     *   the recorder was started without `--env` — `record` is opt-in for
     *   env capture to avoid leaking the caller's full shell environment.
     *   `RecordCommand::SECRET_KEY_REGEX` strips any key matching the
     *   conservative secret regex (`/(SECRET|TOKEN|KEY|PASSWORD|API)/i`)
     *   before the env reaches the cassette.
     */
    public function __construct(
        public int $version,
        public string $createdAt,
        public int $cols,
        public int $rows,
        public string $runtime,
        public string $timestampMode = self::TIMESTAMP_MODE_ABSOLUTE,
        public array $env = [],
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
        foreach ($env as $key => $value) {
            if (!\is_string($key) || $key === '') {
                throw new \InvalidArgumentException('CassetteHeader env keys must be non-empty strings');
            }
            if (!\is_string($value)) {
                throw new \InvalidArgumentException("CassetteHeader env['{$key}'] must be a string, got " . \get_debug_type($value));
            }
        }
    }
}
