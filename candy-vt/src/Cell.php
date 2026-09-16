<?php

declare(strict_types=1);

namespace SugarCraft\Vt;

use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Hyperlink\Hyperlink;
use SugarCraft\Vt\Sgr\Sgr;

/**
 * A single cell in the terminal grid — the one Cell for BOTH engines.
 *
 * Historically candy-vt carried two Cell classes: a palette-only value
 * object for the vcr renderer path ({@see SugarCraft\Vt\Cell}, char/fg/bg/
 * attrs) and a full SGR-backed one for the emulator
 * ({@see SugarCraft\Vt\Cell\Cell}, grapheme/sgr/hyperlink/combining). They
 * have been unified into this class; the emulator's name is now a
 * {@see class_alias} shim onto it. The constructor accepts BOTH vocabularies
 * (`char` and `grapheme`, which resolve to the same stored value), so every
 * former construction site keeps working unchanged.
 *
 * Two colour representations coexist on purpose:
 *  - the renderer pen writes resolved palette slots in {@see $fg}/{@see $bg}
 *    plus, when SGR 38;2/48;2 carries it, an exact 24-bit value in
 *    {@see $fgTruecolor}/{@see $bgTruecolor};
 *  - the emulator stores everything on {@see $sgr} as a {@see Color}.
 * {@see equals()} is representation-aware: it compares the SGR when either
 * side carries one, otherwise the palette/truecolor slots, so each engine
 * keeps its historical notion of cell identity. {@see fgRgb()}/
 * {@see bgRgb()} and {@see colorSgr()} bridge the two so a truecolour value
 * is observable from either representation without parsing an SGR string.
 *
 * Readonly value object — `with*()` always returns a new instance.
 *
 * Mirrors charmbracelet/x/vt Cell.
 */
final readonly class Cell
{
    public const ATTR_BOLD = 1 << 0;
    public const ATTR_ITALIC = 1 << 1;
    public const ATTR_UNDERLINE = 1 << 2;
    public const ATTR_INVERSE = 1 << 3;
    public const ATTR_STRIKETHROUGH = 1 << 4;

    public string $char;

    /** Emulator-facing alias of {@see $char} — the base grapheme cluster. */
    public string $grapheme;

    /**
     * @param int                $fg          Resolved foreground palette slot (renderer pen).
     * @param int                $bg          Resolved background palette slot (renderer pen).
     * @param int                $attrs       Attribute bitfield — see the ATTR_* constants.
     * @param Sgr|null           $sgr         Full rendition (emulator); null on the renderer path.
     * @param int|null           $fgTruecolor 24-bit packed 0xRRGGBB foreground, or null when palette.
     * @param int|null           $bgTruecolor 24-bit packed 0xRRGGBB background, or null when palette.
     * @param Rendition          $rendition   Line width/height state stamped by `ESC # 3`–`ESC # 6`.
     * @param bool               $continuation Second column of a wide glyph.
     * @param Hyperlink|null     $hyperlink   Active OSC 8 hyperlink.
     * @param string             $combining    Trailing combining marks (U+0300–U+036F) for the base grapheme.
     * @param string|null        $grapheme     Emulator-named constructor of {@see $char}.
     */
    public function __construct(
        string $char = ' ',
        public int $fg = 7,
        public int $bg = 0,
        public int $attrs = 0,
        public ?Sgr $sgr = null,
        public ?int $fgTruecolor = null,
        public ?int $bgTruecolor = null,
        public Rendition $rendition = Rendition::None,
        public bool $continuation = false,
        public ?Hyperlink $hyperlink = null,
        public string $combining = '',
        ?string $grapheme = null,
    ) {
        $resolved = $grapheme ?? $char;
        $this->char = $resolved;
        $this->grapheme = $resolved;
    }

    /**
     * The shared empty cell — one memoised instance per process.
     *
     * Emulator `Buffer` fills and blank-scrolling lean on this being identity
     * stable (AllocationTest pins "all empty slots are one Cell" and the
     * resize/feed heap ceilings depend on it). The immutable value carries
     * default palette slots, no SGR, no truecolour and a {@see Rendition::None}
     * line state, so a single shared instance is safe to hand to every consumer.
     * The vcr `CellGrid` deliberately does NOT use this for its pristine fill —
     * it allocates one fresh value per slot (see {@see CellGrid::makeGrid()}).
     */
    public static function empty(): self
    {
        static $empty = null;

        return $empty ??= new self();
    }

    /**
     * Second cell of a wide character — carries no grapheme, marks
     * continuation, and inherits the leading cell's full rendition so the
     * phantom column paints with the same colours/attributes/line state.
     *
     * Mirrors charmbracelet/x/vt Cell::WithContinuation.
     */
    public static function continuation(self $prev): self
    {
        return new self(
            char: '',
            fg: $prev->fg,
            bg: $prev->bg,
            attrs: $prev->attrs,
            sgr: $prev->sgr,
            fgTruecolor: $prev->fgTruecolor,
            bgTruecolor: $prev->bgTruecolor,
            rendition: $prev->rendition,
            continuation: true,
            hyperlink: $prev->hyperlink,
        );
    }

    /**
     * @param array{char?:string, fg?:int, bg?:int, attrs?:int, sgr?:Sgr|null, fgTruecolor?:int|null, bgTruecolor?:int|null, rendition?:Rendition, continuation?:bool, hyperlink?:Hyperlink|null, combining?:string} $changes
     */
    private function mutate(array $changes): self
    {
        return new self(
            char: $changes['char'] ?? $this->char,
            fg: $changes['fg'] ?? $this->fg,
            bg: $changes['bg'] ?? $this->bg,
            attrs: $changes['attrs'] ?? $this->attrs,
            sgr: array_key_exists('sgr', $changes) ? $changes['sgr'] : $this->sgr,
            fgTruecolor: array_key_exists('fgTruecolor', $changes) ? $changes['fgTruecolor'] : $this->fgTruecolor,
            bgTruecolor: array_key_exists('bgTruecolor', $changes) ? $changes['bgTruecolor'] : $this->bgTruecolor,
            rendition: $changes['rendition'] ?? $this->rendition,
            continuation: $changes['continuation'] ?? $this->continuation,
            hyperlink: array_key_exists('hyperlink', $changes) ? $changes['hyperlink'] : $this->hyperlink,
            combining: $changes['combining'] ?? $this->combining,
        );
    }

    public function withFg(int $c): self
    {
        return $this->mutate(['fg' => $c]);
    }

    public function withBg(int $c): self
    {
        return $this->mutate(['bg' => $c]);
    }

    public function withAttrs(int $flags): self
    {
        return $this->mutate(['attrs' => $flags]);
    }

    /** Foreground as an exact 24-bit packed value (or null when not truecolour). */
    public function withFgTruecolor(?int $rgb): self
    {
        return $this->mutate(['fgTruecolor' => $rgb]);
    }

    /** Background as an exact 24-bit packed value (or null when not truecolour). */
    public function withBgTruecolor(?int $rgb): self
    {
        return $this->mutate(['bgTruecolor' => $rgb]);
    }

    public function withRendition(Rendition $rendition): self
    {
        return $this->mutate(['rendition' => $rendition]);
    }

    /**
     * Return a new Cell with additional combining marks appended.
     * Mirrors charmbracelet/x/vt Cell::WithCombining.
     */
    public function withCombining(string $combining): self
    {
        return $this->mutate(['combining' => $this->combining . $combining]);
    }

    public function sgr(): Sgr
    {
        return $this->sgr ?? Sgr::empty();
    }

    public function foreground(): ?Color
    {
        return $this->sgr()?->foreground;
    }

    public function background(): ?Color
    {
        return $this->sgr()?->background;
    }

    /**
     * Exact 24-bit foreground as `[r, g, b]`, from either the renderer's
     * {@see $fgTruecolor} slot or an emulator SGR carrying a kind-3
     * {@see Color}. Null when the foreground is a palette/default colour —
     * this is the representation-agnostic truecolour exposure the renderer
     * path previously lacked.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    public function fgRgb(): ?array
    {
        return self::rgbOf($this->fgTruecolor, $this->sgr?->foreground);
    }

    /**
     * Exact 24-bit background as `[r, g, b]` — the {@see fgRgb()} twin for
     * the background colour.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    public function bgRgb(): ?array
    {
        return self::rgbOf($this->bgTruecolor, $this->sgr?->background);
    }

    /**
     * Re-emit the cell's colours as an SGR sequence — the cell→SGR direction
     * that makes a stored truecolour value recoverable as bytes (the round-trip
     * the parity work pinned; image rasterizers may consume it in future).
     * Emits `38;2;R;G;B`/`48;2;R;G;B` for a truecolour slot and `38;5;n`/
     * `48;5;n` for a palette slot; a default (unset) colour contributes
     * nothing. Returns '' when neither colour is set. The caller wraps this in
     * no extra `CSI m` — the string is already a complete `ESC [ … m`.
     */
    public function colorSgr(): string
    {
        $parts = [];
        $fg = $this->fgSgr();
        if ($fg !== null) {
            $parts[] = $fg;
        }
        $bg = $this->bgSgr();
        if ($bg !== null) {
            $parts[] = $bg;
        }
        if ($parts === []) {
            return '';
        }

        return "\x1b[" . implode(';', $parts) . 'm';
    }

    public function equals(self $other): bool
    {
        if ($this->char !== $other->char) {
            return false;
        }
        if ($this->continuation !== $other->continuation) {
            return false;
        }
        if ($this->combining !== $other->combining) {
            return false;
        }
        if ($this->rendition !== $other->rendition) {
            return false;
        }
        if (($this->hyperlink?->id ?? '') !== ($other->hyperlink?->id ?? '')) {
            return false;
        }

        if ($this->sgr !== null || $other->sgr !== null) {
            return $this->sgrEquals($other);
        }

        return $this->fg === $other->fg
            && $this->bg === $other->bg
            && $this->attrs === $other->attrs
            && $this->fgTruecolor === $other->fgTruecolor
            && $this->bgTruecolor === $other->bgTruecolor;
    }

    /**
     * @param array{0: int, 1: int, 2: int}|null $rgb
     */
    private static function rgbOf(?int $packed, ?Color $color): ?array
    {
        if ($packed !== null) {
            return [($packed >> 16) & 0xFF, ($packed >> 8) & 0xFF, $packed & 0xFF];
        }
        if ($color !== null && $color->kind === 3) {
            return [$color->red(), $color->green(), $color->blue()];
        }

        return null;
    }

    private function fgSgr(): ?string
    {
        $rgb = $this->fgRgb();
        if ($rgb !== null) {
            return "38;2;{$rgb[0]};{$rgb[1]};{$rgb[2]}";
        }
        $index = $this->fgPalette();

        return $index === null ? null : "38;5;{$index}";
    }

    private function bgSgr(): ?string
    {
        $rgb = $this->bgRgb();
        if ($rgb !== null) {
            return "48;2;{$rgb[0]};{$rgb[1]};{$rgb[2]}";
        }
        $index = $this->bgPalette();

        return $index === null ? null : "48;5;{$index}";
    }

    /** Palette index of the foreground, or null when it is a default/truecolour colour. */
    private function fgPalette(): ?int
    {
        if ($this->sgr !== null) {
            $color = $this->sgr->foreground;

            return $color === null || $color->kind === 0 ? null : $color->value;
        }

        return $this->fgTruecolor === null ? $this->fg : null;
    }

    /** Palette index of the background, or null when it is a default/truecolour colour. */
    private function bgPalette(): ?int
    {
        if ($this->sgr !== null) {
            $color = $this->sgr->background;

            return $color === null || $color->kind === 0 ? null : $color->value;
        }

        return $this->bgTruecolor === null ? $this->bg : null;
    }

    /** Emulator-representation equality: full SGR colours and flags. */
    private function sgrEquals(self $other): bool
    {
        $a = $this->sgr();
        $b = $other->sgr();

        return self::colorEquals($a->foreground, $b->foreground)
            && self::colorEquals($a->background, $b->background)
            && $a->bold === $b->bold
            && $a->italic === $b->italic
            && $a->underline === $b->underline
            && $a->underlineStyle === $b->underlineStyle
            && $a->strikethrough === $b->strikethrough
            && $a->blink === $b->blink
            && $a->reverse === $b->reverse
            && $a->dim === $b->dim
            && $a->hidden === $b->hidden
            && $a->invisible === $b->invisible;
    }

    private static function colorEquals(?Color $a, ?Color $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }

        return $a->equals($b);
    }
}
