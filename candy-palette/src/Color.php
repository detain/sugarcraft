<?php

declare(strict_types=1);

namespace SugarCraft\Palette;

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
        $this->r = self::clamp($r);
        $this->g = self::clamp($g);
        $this->b = self::clamp($b);
        $this->a = self::clamp($a);
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

    private static function clamp(int $v): int
    {
        return \max(0, \min(255, $v));
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
     * Convert to 256-color ANSI palette index (0-255).
     *
     * 0-15   : standard colors (same as ANSI 16-color)
     * 16-231 : 6×6×6 color cube (216 colors)
     * 232-255: 24-step grey ramp
     */
    public function toAnsi256Index(): int
    {
        if ($this->r === 0 && $this->g === 0 && $this->b === 0) {
            return 16;
        }
        if ($this->r === 255 && $this->g === 255 && $this->b === 255) {
            return 231;
        }

        // Greyscale: map to the 24-step grey ramp (232-255)
        if ($this->isGreyscale()) {
            $grey = (int) \round($this->luminance() / 255 * 23);
            return 232 + $grey;
        }

        // 6×6×6 color cube
        $r = (int) \round($this->r / 255 * 5);
        $g = (int) \round($this->g / 255 * 5);
        $b = (int) \round($this->b / 255 * 5);

        return 16 + ($r * 36) + ($g * 6) + $b;
    }

    /**
     * Convert to ANSI 16-color palette index (0-15).
     *
     * Basic colors:   0=black, 1=red, 2=green, 3=yellow, 4=blue, 5=magenta, 6=cyan, 7=white
     * Bright colors:  8-15 (same hues, higher intensity)
     */
    public function toAnsi16Index(): int
    {
        $index = $this->closestAnsi16();
        // If original color is "bright" (high luminance), add 8
        if ($this->perceivedBrightness() > 128) {
            $index += 8;
        }
        return $index;
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
     * @see https://github.com/charmbracelet/colorprofile.Color.FromANSI256Index
     */
    public static function fromAnsi256Index(int $idx): self
    {
        if ($idx >= 232) {
            $grey = ($idx - 232) * 10 + 8;
            return new self($grey, $grey, $grey);
        }
        $r = (int) \round((($idx - 16) / 36) * 255 / 5);
        $g = (int) \round(((($idx - 16) % 36) / 6) * 255 / 5);
        $b = (int) \round((($idx - 16) % 6) * 255 / 5);
        return new self($r, $g, $b);
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
        // Decode 256-color index back to RGB
        if ($idx >= 232) {
            $grey = ($idx - 232) * 10 + 8;
            return new self($grey, $grey, $grey, $this->a);
        }
        $r = (($idx - 16) / 36) * 255 / 5;
        $g = ((($idx - 16) % 36) / 6) * 255 / 5;
        $b = (($idx - 16) % 6) * 255 / 5;
        return new self((int) \round($r), (int) \round($g), (int) \round($b), $this->a);
    }

    private function toAnsi16(): self
    {
        $idx = $this->closestAnsi16();
        static $palette = [
            [0x00, 0x00, 0x00], // 0 black
            [0xcd, 0x00, 0x00], // 1 red
            [0x00, 0xcd, 0x00], // 2 green
            [0xcd, 0xcd, 0x00], // 3 yellow
            [0x00, 0x00, 0xcd], // 4 blue
            [0xcd, 0x00, 0xcd], // 5 magenta
            [0x00, 0xcd, 0xcd], // 6 cyan
            [0xe5, 0xe5, 0xe5], // 7 white
            [0x7f, 0x7f, 0x7f], // 8 bright black
            [0xff, 0x00, 0x00], // 9 bright red
            [0x00, 0xff, 0x00], // 10 bright green
            [0xff, 0xff, 0x00], // 11 bright yellow
            [0x00, 0x00, 0xff], // 12 bright blue
            [0xff, 0x00, 0xff], // 13 bright magenta
            [0x00, 0xff, 0xff], // 14 bright cyan
            [0xff, 0xff, 0xff], // 15 bright white
        ];

        [$r, $g, $b] = $palette[$idx];
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

    private function isGreyscale(): bool
    {
        $r = $this->r;
        $g = $this->g;
        $b = $this->b;
        return \max($r, $g, $b) - \min($r, $g, $b) <= 10;
    }

    /** Perceived luminance (0-255). */
    private function luminance(): float
    {
        return 0.299 * $this->r + 0.587 * $this->g + 0.114 * $this->b;
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

    /** Index into the 8 basic ANSI 16-color palette. */
    private function closestAnsi16(): int
    {
        $minDist = PHP_INT_MAX;
        $closest = 0;

        static $palette = [
            [0x00, 0x00, 0x00],
            [0xcd, 0x00, 0x00],
            [0x00, 0xcd, 0x00],
            [0xcd, 0xcd, 0x00],
            [0x00, 0x00, 0xcd],
            [0xcd, 0x00, 0xcd],
            [0x00, 0xcd, 0xcd],
            [0xe5, 0xe5, 0xe5],
        ];

        foreach ($palette as $i => [$pr, $pg, $pb]) {
            $dist = ($this->r - $pr) ** 2 + ($this->g - $pg) ** 2 + ($this->b - $pb) ** 2;
            if ($dist < $minDist) {
                $minDist = $dist;
                $closest = $i;
            }
        }

        return $closest;
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
