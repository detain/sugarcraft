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

    public const PLAYBACK_SPEED_DEFAULT = 1.0;

    /**
     * @param 'absolute'|'relative'   $timestampMode
     * @param array<string, string>   $env
     *   Filtered environment captured at record time. Empty (default) when
     *   the recorder was started without `--env` — `record` is opt-in for
     *   env capture to avoid leaking the caller's full shell environment.
     *   `RecordCommand::SECRET_KEY_REGEX` strips any key matching the
     *   conservative secret regex (`/(SECRET|TOKEN|KEY|PASSWORD|API)/i`)
     *   before the env reaches the cassette.
     * @param float|null $typingSpeed Milliseconds between keystrokes for Type directives.
     *   Defaults to null (not set) for backward compatibility with older cassettes.
     * @param string|null $theme Theme name resolved at compile time (e.g. from a `.tape`
     *   `Set Theme "TokyoNight"` directive). null when the cassette was produced by the
     *   PTY recorder (which has no theme concept). Renderers should treat null as their
     *   default theme.
     * @param float|null $playbackSpeed Speed multiplier (e.g. 2.0 = 2x speed, 0.5 = half speed).
     *   Defaults to null (not set) for backward compatibility with older cassettes.
     * @param int|null $widthPx Output image WIDTH in pixels, as requested by a VHS-style
     *   `Set Width <px>` directive (VHS default 1200). The terminal grid `cols` above is
     *   DERIVED from this via the font cell width — pixels are NOT terminal columns. Null
     *   for cassettes with no pixel-dim concept (e.g. the PTY recorder, which sets
     *   `cols`/`rows` directly). Carried on the header so {@see \SugarCraft\Vcr\Tape\Decompiler}
     *   can regenerate the original `Set Width` directive losslessly (deriving `cols` back
     *   from pixels is not invertible).
     * @param int|null $heightPx Output image HEIGHT in pixels (VHS `Set Height`, default 600).
     *   `rows` is derived from this via the font cell height. See {@see $widthPx}.
     */
    public function __construct(
        public int $version,
        public string $createdAt,
        public int $cols,
        public int $rows,
        public string $runtime,
        public string $timestampMode = self::TIMESTAMP_MODE_ABSOLUTE,
        public array $env = [],
        public ?float $typingSpeed = null,
        public ?string $theme = null,
        public ?float $playbackSpeed = null,
        public ?int $fontSize = null,
        public ?string $fontFamily = null,
        public ?int $widthPx = null,
        public ?int $heightPx = null,
    ) {
        if ($version < 1) {
            throw new \InvalidArgumentException("CassetteHeader version must be >= 1, got {$version}");
        }
        if ($cols <= 0 || $rows <= 0) {
            throw new \InvalidArgumentException("CassetteHeader dimensions must be positive, got {$cols}x{$rows}");
        }
        if ($widthPx !== null && $widthPx < 1) {
            throw new \InvalidArgumentException("CassetteHeader widthPx must be >= 1 when set, got {$widthPx}");
        }
        if ($heightPx !== null && $heightPx < 1) {
            throw new \InvalidArgumentException("CassetteHeader heightPx must be >= 1 when set, got {$heightPx}");
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
