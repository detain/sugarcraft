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

    /**
     * Highest format version this build's readers understand. The recorder
     * still stamps CURRENT_VERSION (v1); v2 exists as the migration target
     * only. A cassette above this ceiling is refused at construction rather
     * than half-parsed — a future reader, not this one, owns its semantics.
     */
    public const MAX_READABLE_VERSION = 2;

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
     *   conservative secret regex (`/(SECRET|TOKEN|KEY|PASSWORD|API|CRED|AUTH|PRIV)/i`)
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
        if ($version > self::MAX_READABLE_VERSION) {
            throw new \InvalidArgumentException("cassette version {$version} is newer than this build can read (max v" . self::MAX_READABLE_VERSION . ') — upgrade candy-vcr');
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
        // Finding 004: header strings are printed verbatim by inspect/stats/
        // diff. A hostile cassette used to gain arbitrary terminal control
        // (SGR smear, OSC 52 clipboard write, title set) through them, so
        // control characters are refused right here — every format builds
        // its header through this door, so all consumers inherit it.
        self::rejectControlChars('createdAt', $createdAt);
        self::rejectControlChars('runtime', $runtime);
        if ($theme !== null) {
            self::rejectControlChars('theme', $theme);
        }
        if ($fontFamily !== null) {
            self::rejectControlChars('fontFamily', $fontFamily);
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

    /**
     * Reject control characters in header metadata (finding 004).
     *
     * C0 (including ESC/NUL/CR/LF) and DEL match as raw bytes — they can
     * never occur inside a valid UTF-8 multibyte sequence. C1 (U+0080
     * ..U+009F) is matched as a code point class: raw bytes in that range
     * ARE legitimate UTF-8 continuations (U+2028 encodes as E2 80 A8), so
     * only the decoded characters count. Strings that are not valid UTF-8
     * at all cannot be audited and are refused with the same named error.
     */
    private static function rejectControlChars(string $field, string $value): void
    {
        if (
            \preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || \preg_match('/[\x{80}-\x{9f}]/u', $value) !== 0
        ) {
            throw new \InvalidArgumentException("CassetteHeader {$field} must not contain control characters");
        }
    }

    /**
     * Canonical wire representation of every header field, defaults omitted.
     *
     * Single source of truth for all encoders (Recorder streaming path,
     * JsonlFormat, RelativeFormat, YamlFormat) so a field added to this class
     * cannot be silently dropped by one format while another carries it —
     * the defect class that made `diff` blind to font/theme/env drift.
     *
     * @return array<string, mixed>
     */
    public function toWireArray(): array
    {
        $data = [
            'v' => $this->version,
            'created' => $this->createdAt,
            'cols' => $this->cols,
            'rows' => $this->rows,
            'runtime' => $this->runtime,
        ];
        if ($this->timestampMode !== self::TIMESTAMP_MODE_ABSOLUTE) {
            $data['timestampMode'] = $this->timestampMode;
        }
        if ($this->env !== []) {
            $data['env'] = $this->env;
        }
        if ($this->typingSpeed !== null) {
            $data['typingSpeed'] = $this->typingSpeed;
        }
        if ($this->theme !== null) {
            $data['theme'] = $this->theme;
        }
        if ($this->playbackSpeed !== null) {
            $data['playbackSpeed'] = $this->playbackSpeed;
        }
        if ($this->fontSize !== null) {
            $data['fontSize'] = $this->fontSize;
        }
        if ($this->fontFamily !== null) {
            $data['fontFamily'] = $this->fontFamily;
        }
        if ($this->widthPx !== null) {
            $data['widthPx'] = $this->widthPx;
        }
        if ($this->heightPx !== null) {
            $data['heightPx'] = $this->heightPx;
        }
        return $data;
    }

    /**
     * Derivation changing ONLY the version stamp; every other field
     * (timestampMode, env, theme, typing speed, font and pixel geometry
     * included) rides through untouched. Migration must not forget where a
     * recording came from.
     */
    public function withVersion(int $version): self
    {
        return new self(
            version: $version,
            createdAt: $this->createdAt,
            cols: $this->cols,
            rows: $this->rows,
            runtime: $this->runtime,
            timestampMode: $this->timestampMode,
            env: $this->env,
            typingSpeed: $this->typingSpeed,
            theme: $this->theme,
            playbackSpeed: $this->playbackSpeed,
            fontSize: $this->fontSize,
            fontFamily: $this->fontFamily,
            widthPx: $this->widthPx,
            heightPx: $this->heightPx,
        );
    }
}
