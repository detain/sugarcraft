<?php

declare(strict_types=1);

namespace SugarCraft\Palette;

use SugarCraft\Core\Util\Clamp;

/**
 * RGBA color value object.
 *
 * All components are 0-255. The alpha channel follows the CSS convention
 * (0 = fully transparent, 255 = fully opaque).
 *
 * Mirrors the Go color.RGBA model used in charmbracelet/colorprofile.
 */
final class Color
{
    public readonly int $r;
    public readonly int $g;
    public readonly int $b;
    public readonly int $a;

    public function __construct(
        int $r,
        int $g,
        int $b,
        int $a = 255,
    ) {
        $this->r = Clamp::byte($r);
        $this->g = Clamp::byte($g);
        $this->b = Clamp::byte($b);
        $this->a = Clamp::byte($a);
    }

    /**
     * Construct from a 24-bit hex integer (0xRRGGBB).
     *
     * @param int $hex e.g. 0x6b50ff
     */
    public static function fromHex(int $hex, int $a = 255): self
    {
        return new self(
            ($hex >> 16) & 0xff,
            ($hex >> 8) & 0xff,
            $hex & 0xff,
            $a,
        );
    }

    /**
     * Parse from CSS hex string ("#rrggbb" or "#rgb").
     */
    public static function parse(string $hex, int $a = 255): self
    {
        $hex = \ltrim($hex, '#');
        if (\strlen($hex) !== 3 && \strlen($hex) !== 6) {
            throw new \InvalidArgumentException("invalid hex color: {$hex}");
        }
        if (!\ctype_xdigit($hex)) {
            throw new \InvalidArgumentException("invalid hex color: {$hex}");
        }
        if (\strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return self::fromHex((int) \hexdec($hex), $a);
    }

    // -------------------------------------------------------------------------
    // Conversion
    // -------------------------------------------------------------------------

    /**
     * Convert this color to the closest approximation representable in $profile.
     *
     * - TrueColor: returns a copy unchanged (already at max fidelity)
     * - ANSI256:  rounds to the nearest 216-color cube or 24-step grey ramp
     * - ANSI:     rounds to one of 16 standard terminal colors
     * - Ascii:    returns black or white based on perceived luminance
     * - NoTTY:    returns a near-black or near-white for legibility
     */
    public function convert(Profile $profile): self
    {
        return match ($profile) {
            Profile::TrueColor => $this,
            Profile::ANSI256   => $this->toAnsi256(),
            Profile::ANSI      => $this->toAnsi16(),
            Profile::Ascii,
            Profile::NoTTY     => $this->toAscii(),
        };
    }

    /**
     * The six levels of each axis of the xterm 6×6×6 color cube.
     *
     * These are fixed values documented for xterm-256color; they are NOT an
     * even 0-255 spread (0,51,102,...) — an off-ramp quantization shifts every
     * mid-tone by up to 40/255.
     */
    public const CUBE_LEVELS = [0, 95, 135, 175, 215, 255];

    /**
     * The reference RGB this library uses for palette slots 0-15.
     *
     * These are xterm's COMPILED-IN defaults for slots 0-15 — the very table
     * {@see \SugarCraft\Core\Util\Color::ANSI16_RGB} publishes as the monorepo
     * canon, kept element-wise identical by a cross-lib equality test
     * (candy-palette/tests/Ansi16TableParityTest.php) so the two can never
     * silently drift again. Slots 4 and 12 are xterm's `DEF_COLOR4 "blue2"`
     * (#0000EE) and `DEF_COLOR12 "rgb:5c/5c/ff"` (#5C5CFF); xterm's shipped
     * `XTerm-col.ad` records that these replaced the earlier
     * `blue3`/`blue` (#0000CD / #0000FF) precisely because "blue3 is not
     * readable on a black background".
     *
     * CORRECTION OF A PRIOR CLAIM. An earlier revision of this constant
     * labelled those old blues "VGA/common-terminal", but that attribution was
     * wrong at primary source: real VGA/EGA text-mode DAC blue is #0000AA with
     * bright #5555FF, and #0000CD/#0000FF were never VGA at all — they were
     * xterm's own abandoned pre-2009 defaults (X11 `blue3`/`blue`). The
     * divergence from candy-core was thus justified by a myth; unifying both
     * libs on the live xterm defaults removes it.
     *
     * The property this table always relied on is preserved: slots 0-15 are
     * THEMEABLE (a terminal owner's config, not these bytes, decides what the
     * user sees; the 16-colour writers emit only the SGR index for them — see
     * the writer-scope note below), so these values serve distance maths and
     * slot decoding alone. Within this
     * lib the 16-COLOUR chain is exact — the SAME table feeds decode
     * (fromAnsi256Index), quantise (toAnsi16Index) and render
     * (toAnsi16Foreground/toAnsi16Background) — so every slot 0-15 round-trips
     * back to its own index through it and no byte disagrees with another
     * 16-colour API. Composing with the 256-COLOUR path is, however, LOSSY by
     * construction for the slots that do not lie on the fixed 6×6×6 cube
     * (indices 16-231 over {@see CUBE_LEVELS}): slots 1-8 and 12 are off-cube,
     * e.g. slot 4 #0000EE → toAnsi256Index() 21 → fromAnsi256Index(21)
     * #0000FF, and slot 12 #5C5CFF → 63 → #5F5FFF. Only slots 0,9,10,11,13,14,15
     * — precisely those whose R,G,B are all members of CUBE_LEVELS — survive a
     * 256 round-trip byte-exact; the move of slot 12 from #0000FF (exactly cube
     * index 21) to xterm's #5C5CFF made that slot newly lossy on this path. It
     * does not affect what a user sees FOR THE 16-COLOUR WRITERS: those emit
     * the 4-bit SGR index ({@see toAnsi16Foreground()}/
     * toAnsi16Background() — slot 4 renders as `ESC [ 34 m`), so the terminal
     * supplies its own theme for slots 0-15. Use the wider writers knowing what
     * they send: {@see toAnsiForeground()} emits the reference RGB itself
     * (`ESC [ 38 ; 2 ; 0 ; 0 ; 238 m` for slot 4) and {@see
     * toAnsi256Foreground()} emits the nearest cube index (21) rather than the
     * themeable slot — both bypass the user's own slot-4 theme.
     *
     * @var array<int,array{int,int,int}>
     */
    public const ANSI16_RGB = [
        [0x00, 0x00, 0x00], //  0 black
        [0xcd, 0x00, 0x00], //  1 red
        [0x00, 0xcd, 0x00], //  2 green
        [0xcd, 0xcd, 0x00], //  3 yellow
        [0x00, 0x00, 0xee], //  4 blue  (xterm blue2)
        [0xcd, 0x00, 0xcd], //  5 magenta
        [0x00, 0xcd, 0xcd], //  6 cyan
        [0xe5, 0xe5, 0xe5], //  7 white
        [0x7f, 0x7f, 0x7f], //  8 bright black
        [0xff, 0x00, 0x00], //  9 bright red
        [0x00, 0xff, 0x00], // 10 bright green
        [0xff, 0xff, 0x00], // 11 bright yellow
        [0x5c, 0x5c, 0xff], // 12 bright blue  (xterm rgb:5c/5c/ff)
        [0xff, 0x00, 0xff], // 13 bright magenta
        [0x00, 0xff, 0xff], // 14 bright cyan
        [0xff, 0xff, 0xff], // 15 bright white
    ];

    /**
     * Convert to 256-color ANSI palette index (0-255).
     *
     * 16-231 : 6×6×6 color cube (216 colors, axes R·36 + G·6 + B)
     * 232-255: 24-step grey ramp (8, 18, …, 238)
     *
     * Picks whichever of the two candidates is nearer in squared RGB, the
     * same rule candy-core's Color::nearest256() uses, so the cube/grey
     * overlap region is stable across the monorepo. Truecolor red maps to
     * 196, not to the themeable basic slot 1: RGB is only ever quantised
     * onto the fixed part of the palette (16-255).
     */
    public function toAnsi256Index(): int
    {
        // Cube candidate (16-231): nearest level on each axis.
        $cubeIdx = 16
            + 36 * self::nearestCubeLevel($this->r)
            + 6 * self::nearestCubeLevel($this->g)
            + self::nearestCubeLevel($this->b);

        // Greyscale candidate (232-255): levels 8, 18, …, 238.
        $avg = ($this->r + $this->g + $this->b) / 3.0;
        $greyBin = max(0, min(23, (int) \round(($avg - 8.0) / 10.0)));
        $greyIdx = 232 + $greyBin;

        return self::distToCubeIndex($this, $cubeIdx) <= self::distToCubeIndex($this, $greyIdx)
            ? $cubeIdx
            : $greyIdx;
    }

    /**
     * Convert to ANSI 16-color palette index (0-15).
     *
     * Nearest-by-squared-RGB over ALL 16 slots (basic 0-7 and bright 8-15 in
     * one search). Searching only the basic 8 and bolting on a luminance
     * "+8 if bright" hack mis-splits the palette: xterm's slot-2 green
     * (0,205,0) is dark enough to be itself yet bright enough to be pushed
     * to slot 10, and any basic slot above the threshold collapses upward.
     * Distance over the 16 entry points is the documented mapping and makes
     * every exact slot value round-trip to itself.
     */
    public function toAnsi16Index(): int
    {
        $best = 0;
        $bestDist = PHP_INT_MAX;
        foreach (self::ANSI16_RGB as $idx => [$pr, $pg, $pb]) {
            $dist = ($this->r - $pr) ** 2 + ($this->g - $pg) ** 2 + ($this->b - $pb) ** 2;
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $idx;
            }
        }
        return $best;
    }

    /**
     * Index of the nearest cube level for one channel (0-5).
     *
     * Level midpoints are 48/115/155/195/235; exact midpoints round UP, the
     * same convention as candy-core's Color::nearest256().
     */
    private static function nearestCubeLevel(int $v): int
    {
        return match (true) {
            $v < 48  => 0,
            $v < 115 => 1,
            default  => intdiv($v - 35, 40),
        };
    }

    /**
     * Render as a 24-bit SGR foreground escape: \x1b[38;2;R;G;Bm
     */
    public function toAnsiForeground(): string
    {
        return "\x1b[38;2;{$this->r};{$this->g};{$this->b}m";
    }

    /**
     * Render as a 24-bit SGR background escape: \x1b[48;2;R;G;Bm
     */
    public function toAnsiBackground(): string
    {
        return "\x1b[48;2;{$this->r};{$this->g};{$this->b}m";
    }

    /**
     * Emit a 256-color ANSI foreground escape.
     */
    public function toAnsi256Foreground(): string
    {
        $idx = $this->toAnsi256Index();
        return "\x1b[38;5;{$idx}m";
    }

    /**
     * Emit a 16-color ANSI foreground escape using 4-bit SGR.
     *
     * @see https://github.com/charmbracelet/colorprofile.Color.ToANSI16Foreground
     */
    public function toAnsi16Foreground(): string
    {
        return "\x1b[" . self::ansi16Sgr($this->toAnsi16Index(), false) . "m";
    }

    /**
     * Emit a 16-color ANSI background escape using 4-bit SGR.
     */
    public function toAnsi16Background(): string
    {
        return "\x1b[" . self::ansi16Sgr($this->toAnsi16Index(), true) . "m";
    }

    /**
     * Map an ANSI 16-color index (0–15) to a 4-bit SGR code.
     *
     * Foreground: 0–7 → 30–37, 8–15 → 90–97
     * Background: 0–7 → 40–47, 8–15 → 100–107
     *
     * @see https://github.com/charmbracelet/colorprofile.Color.ANSI16SGR
     */
    public static function ansi16Sgr(int $idx, bool $background): int
    {
        $base = $background ? 40 : 30;
        if ($idx < 8) {
            return $base + $idx;
        }
        $offsetBase = $background ? 100 : 90;
        return $offsetBase + ($idx - 8);
    }

    /**
     * Decode an ANSI 256-color index back to an RGB color.
     *
     * Slots 0-15 resolve to the terminal's palette entries (xterm defaults
     * here — the 16 basic/bright slots are themeable, so any RGB this library
     * produces for them is a reference value, never what the user sees).
     * 16-231 is the fixed 6×6×6 cube, 232-255 the grey ramp; indices below 0
     * or above 255 clamp.
     *
     * @see https://github.com/charmbracelet/colorprofile.Color.FromANSI256Index
     */
    public static function fromAnsi256Index(int $idx): self
    {
        if ($idx < 0) {
            $idx = 0;
        } elseif ($idx > 255) {
            $idx = 255;
        }
        if ($idx < 16) {
            [$r, $g, $b] = self::ANSI16_RGB[$idx];
            return new self($r, $g, $b);
        }
        if ($idx >= 232) {
            $grey = ($idx - 232) * 10 + 8;
            return new self($grey, $grey, $grey);
        }
        $i = $idx - 16;
        return new self(
            self::CUBE_LEVELS[intdiv($i, 36)],
            self::CUBE_LEVELS[intdiv($i, 6) % 6],
            self::CUBE_LEVELS[$i % 6],
        );
    }

    /**
     * CIE L*a*b* (D65) coordinates of this color.
     *
     * @see ColorMath::toLab() — the underlying sRGB -> linear -> XYZ -> Lab chain
     *
     * @return array{l: float, a: float, b: float}
     */
    public function toLab(): array
    {
        return ColorMath::toLab($this->r, $this->g, $this->b);
    }

    /**
     * Return "#rrggbb" hex string.
     */
    public function toHex(): string
    {
        return \sprintf('#%02x%02x%02x', $this->r, $this->g, $this->b);
    }

    // -------------------------------------------------------------------------
    // Private conversion helpers
    // -------------------------------------------------------------------------

    private function toAnsi256(): self
    {
        $idx = $this->toAnsi256Index();
        $quantised = self::fromAnsi256Index($idx);
        return new self($quantised->r, $quantised->g, $quantised->b, $this->a);
    }

    private function toAnsi16(): self
    {
        [$r, $g, $b] = self::ANSI16_RGB[$this->toAnsi16Index()];
        return new self($r, $g, $b, $this->a);
    }

    private function toAscii(): self
    {
        // Map to near-black or near-white for legibility
        $brightness = $this->perceivedBrightness();
        if ($brightness > 128) {
            return new self(0xff, 0xff, 0xff, $this->a);
        }
        return new self(0, 0, 0, $this->a);
    }

    /**
     * Squared RGB distance from $c to the palette color at fixed-range
     * index $idx (16-255 — the non-themeable cube + grey ramp).
     */
    private static function distToCubeIndex(self $c, int $idx): int
    {
        $p = self::fromAnsi256Index($idx);
        $dr = $c->r - $p->r;
        $dg = $c->g - $p->g;
        $db = $c->b - $p->b;
        return $dr * $dr + $dg * $dg + $db * $db;
    }

    /** Perceived brightness (0-255). */
    private function perceivedBrightness(): float
    {
        return \sqrt(
            0.299 * ($this->r ** 2) +
            0.587 * ($this->g ** 2) +
            0.114 * ($this->b ** 2)
        );
    }

    /**
     * Enumerate the names of the standard named colors.
     *
     * Each entry resolves to a {@see Color} via the matching static property
     * on {@see StandardColors} (e.g. `'brightRed'` → `StandardColors::$brightRed`).
     * Delegates to {@see StandardColors::catalog()} so the list never drifts.
     * Enables programmatic discovery (e.g. a `--list-colors` command).
     *
     * @return list<string>
     */
    public static function namedColors(): array
    {
        return StandardColors::catalog();
    }

    public function equals(Color $other): bool
    {
        return $this->r === $other->r
            && $this->g === $other->g
            && $this->b === $other->b
            && $this->a === $other->a;
    }
}
