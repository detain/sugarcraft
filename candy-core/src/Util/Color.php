<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * A color value, expressed as RGB internally.
 *
 * Construct via {@see Color::rgb()}, {@see Color::hex()}, {@see Color::ansi()},
 * or {@see Color::ansi256()}. Render to an SGR escape via {@see toFg()} /
 * {@see toBg()}, downsampling automatically to fit the supplied
 * {@see ColorProfile}.
 */
final class Color
{
    /**
     * Standard 16-color ANSI palette as 24-bit RGB triples (xterm defaults).
     *
     * @var array<int,array{int,int,int}>
     */
    private const ANSI16 = [
         0 => [  0,   0,   0],   1 => [205,   0,   0],   2 => [  0, 205,   0],   3 => [205, 205,   0],
         4 => [  0,   0, 238],   5 => [205,   0, 205],   6 => [  0, 205, 205],   7 => [229, 229, 229],
         8 => [127, 127, 127],   9 => [255,   0,   0],  10 => [  0, 255,   0],  11 => [255, 255,   0],
        12 => [ 92,  92, 255],  13 => [255,   0, 255],  14 => [  0, 255, 255],  15 => [255, 255, 255],
    ];

    private function __construct(
        public readonly int $r,
        public readonly int $g,
        public readonly int $b,
    ) {}

    /**
     * Construct from 24-bit RGB. Each component must be in `[0, 255]`
     * — out-of-range values throw {@see \InvalidArgumentException}.
     */
    public static function rgb(int $r, int $g, int $b): self
    {
        foreach ([$r, $g, $b] as $v) {
            if ($v < 0 || $v > 255) {
                throw new \InvalidArgumentException("rgb component out of range [0,255]: $v");
            }
        }
        return new self($r, $g, $b);
    }

    /**
     * Construct from a CSS-style hex string. Accepts 3- or 6-digit forms,
     * with or without leading `#`: `'#ff5'`, `'ff5'`, `'#ff5500'`,
     * `'ff5500'`. Invalid input throws {@see \InvalidArgumentException}.
     */
    public static function hex(string $hex): self
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        if (strlen($h) !== 6 || !ctype_xdigit($h)) {
            throw new \InvalidArgumentException("invalid hex color: $hex");
        }
        return new self(
            hexdec(substr($h, 0, 2)),
            hexdec(substr($h, 2, 2)),
            hexdec(substr($h, 4, 2)),
        );
    }

    /** Construct from a standard ANSI-16 index (0-15). */
    public static function ansi(int $index): self
    {
        if (!isset(self::ANSI16[$index])) {
            throw new \InvalidArgumentException("ansi index out of range [0,15]: $index");
        }
        [$r, $g, $b] = self::ANSI16[$index];
        return new self($r, $g, $b);
    }

    /** Construct from an xterm-256 palette index (0-255). */
    public static function ansi256(int $index): self
    {
        if ($index < 0 || $index > 255) {
            throw new \InvalidArgumentException("ansi256 index out of range [0,255]: $index");
        }
        if ($index < 16) {
            return self::ansi($index);
        }
        if ($index < 232) {
            $i = $index - 16;
            $levels = [0, 95, 135, 175, 215, 255];
            return new self(
                $levels[intdiv($i, 36)],
                $levels[intdiv($i, 6) % 6],
                $levels[$i % 6],
            );
        }
        $g = 8 + ($index - 232) * 10;
        return new self($g, $g, $g);
    }

    /**
     * Construct from HSL values: hue 0-360, saturation 0-1, lightness 0-1.
     * Translates to RGB via the standard HSL→RGB conversion.
     */
    public static function hsl(float $h, float $s, float $l): self
    {
        $h = fmod($h, 360.0);
        if ($h < 0) {
            $h += 360.0;
        }
        $s = max(0.0, min(1.0, $s));
        $l = max(0.0, min(1.0, $l));

        $c = (1.0 - abs(2.0 * $l - 1.0)) * $s;
        $x = $c * (1.0 - abs(fmod($h / 60.0, 2.0) - 1.0));
        $m = $l - $c / 2.0;
        $rp = 0.0; $gp = 0.0; $bp = 0.0;
        if    ($h <  60) { $rp = $c; $gp = $x; }
        elseif ($h < 120) { $rp = $x; $gp = $c; }
        elseif ($h < 180) { $gp = $c; $bp = $x; }
        elseif ($h < 240) { $gp = $x; $bp = $c; }
        elseif ($h < 300) { $rp = $x; $bp = $c; }
        else              { $rp = $c; $bp = $x; }
        return new self(
            (int) round(($rp + $m) * 255.0),
            (int) round(($gp + $m) * 255.0),
            (int) round(($bp + $m) * 255.0),
        );
    }

    /**
     * Construct from HSV values: hue 0-360, saturation 0-1, value 0-1.
     */
    public static function hsv(float $h, float $s, float $v): self
    {
        $h = fmod($h, 360.0);
        if ($h < 0) {
            $h += 360.0;
        }
        $s = max(0.0, min(1.0, $s));
        $v = max(0.0, min(1.0, $v));

        $c = $v * $s;
        $x = $c * (1.0 - abs(fmod($h / 60.0, 2.0) - 1.0));
        $m = $v - $c;
        $rp = 0.0; $gp = 0.0; $bp = 0.0;
        if    ($h <  60) { $rp = $c; $gp = $x; }
        elseif ($h < 120) { $rp = $x; $gp = $c; }
        elseif ($h < 180) { $gp = $c; $bp = $x; }
        elseif ($h < 240) { $gp = $x; $bp = $c; }
        elseif ($h < 300) { $rp = $x; $bp = $c; }
        else              { $rp = $c; $bp = $x; }
        return new self(
            (int) round(($rp + $m) * 255.0),
            (int) round(($gp + $m) * 255.0),
            (int) round(($bp + $m) * 255.0),
        );
    }

    /** Round-trip back to 6-digit lowercase hex string (with leading `#`). */
    public function toHex(): string
    {
        return sprintf('#%02x%02x%02x', $this->r, $this->g, $this->b);
    }

    /**
     * Decompose to HSL. Returns `[hue 0-360, saturation 0-1, lightness 0-1]`.
     *
     * @return array{0:float,1:float,2:float}
     */
    public function toHsl(): array
    {
        $r = $this->r / 255.0;
        $g = $this->g / 255.0;
        $b = $this->b / 255.0;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2.0;
        if ($max === $min) {
            return [0.0, 0.0, $l];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2.0 - $max - $min) : $d / ($max + $min);
        $h = match (true) {
            $max === $r => ($g - $b) / $d + ($g < $b ? 6.0 : 0.0),
            $max === $g => ($b - $r) / $d + 2.0,
            default     => ($r - $g) / $d + 4.0,
        };
        return [$h * 60.0, $s, $l];
    }

    /**
     * Lighten by `$amount` (0-1) in HSL space. `0.1` = 10% lighter.
     * Saturation stays put; lightness clamps at 1.0.
     */
    public function lighten(float $amount): self
    {
        [$h, $s, $l] = $this->toHsl();
        return self::hsl($h, $s, max(0.0, min(1.0, $l + $amount)));
    }

    /**
     * Darken by `$amount` (0-1) in HSL space. `0.1` = 10% darker.
     */
    public function darken(float $amount): self
    {
        [$h, $s, $l] = $this->toHsl();
        return self::hsl($h, $s, max(0.0, min(1.0, $l - $amount)));
    }

    /**
     * Composite this colour over `$bg` at opacity `$alpha` (0-1).
     * Standard alpha-over: result = α·this + (1-α)·bg. With `bg=null`
     * the composite is over solid black.
     */
    public function alpha(float $alpha, ?Color $bg = null): self
    {
        $alpha = max(0.0, min(1.0, $alpha));
        $bg ??= self::rgb(0, 0, 0);
        $inv = 1.0 - $alpha;
        return new self(
            (int) round($this->r * $alpha + $bg->r * $inv),
            (int) round($this->g * $alpha + $bg->g * $inv),
            (int) round($this->b * $alpha + $bg->b * $inv),
        );
    }

    /**
     * Linearly blend between this colour and `$other`. `$t = 0` returns
     * this, `$t = 1` returns `$other`. Clamps `$t` to `[0,1]`.
     */
    public function blend(Color $other, float $t): self
    {
        $t = max(0.0, min(1.0, $t));
        $inv = 1.0 - $t;
        return new self(
            (int) round($this->r * $inv + $other->r * $t),
            (int) round($this->g * $inv + $other->g * $t),
            (int) round($this->b * $inv + $other->b * $t),
        );
    }

    /**
     * Build an N-stop linear gradient between this colour and `$other`.
     * Returns `$steps` colours including endpoints (so `steps=2` yields
     * `[this, other]`, `steps=3` yields `[this, midpoint, other]`).
     *
     * @return list<Color>
     */
    public function blend1d(Color $other, int $steps): array
    {
        if ($steps <= 0) {
            return [];
        }
        if ($steps === 1) {
            return [$this];
        }
        $out = [];
        for ($i = 0; $i < $steps; $i++) {
            $out[] = $this->blend($other, $i / ($steps - 1));
        }
        return $out;
    }

    /**
     * Build a 2D bilinear gradient as an `$rows × $cols` grid. The four
     * corners are `[topLeft=this, topRight, bottomLeft, bottomRight]`.
     *
     * @return list<list<Color>>  rows top→bottom, cols left→right
     */
    public function blend2d(Color $topRight, Color $bottomLeft, Color $bottomRight, int $rows, int $cols): array
    {
        if ($rows <= 0 || $cols <= 0) {
            return [];
        }
        $grid = [];
        for ($r = 0; $r < $rows; $r++) {
            $tr = $rows === 1 ? 0.0 : $r / ($rows - 1);
            $rowLeft  = $this->blend($bottomLeft, $tr);
            $rowRight = $topRight->blend($bottomRight, $tr);
            $row = [];
            for ($c = 0; $c < $cols; $c++) {
                $tc = $cols === 1 ? 0.0 : $c / ($cols - 1);
                $row[] = $rowLeft->blend($rowRight, $tc);
            }
            $grid[] = $row;
        }
        return $grid;
    }

    /**
     * Hue-rotate by 180° to get the diametrically opposite colour.
     */
    public function complementary(): self
    {
        [$h, $s, $l] = $this->toHsl();
        return self::hsl($h + 180.0, $s, $l);
    }

    /**
     * Relative luminance per WCAG 2.1 (Y in CIE XYZ on linearised sRGB).
     * 0 = black, 1 = white. Used by {@see isDark()}.
     */
    public function luminance(): float
    {
        $linearise = static function (float $c): float {
            $c /= 255.0;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $linearise((float) $this->r)
             + 0.7152 * $linearise((float) $this->g)
             + 0.0722 * $linearise((float) $this->b);
    }

    /**
     * True when this colour is "dark" — luminance below 0.5. Use this to
     * pick a contrasting foreground when displaying over a sampled
     * background (`Cmd::requestBackgroundColor()` reply).
     */
    public function isDark(): bool
    {
        return $this->luminance() < 0.5;
    }

    /**
     * Render as a foreground SGR escape (e.g. `"\x1b[38;2;255;128;0m"`)
     * for the given {@see ColorProfile}. Quantises the 24-bit color
     * down to the profile's palette (TrueColor → 256 → ANSI16 →
     * empty-string for `NoTTY`).
     */
    public function toFg(ColorProfile $profile): string
    {
        return $this->toSgr($profile, fg: true);
    }

    /** Background variant of {@see toFg()}. */
    public function toBg(ColorProfile $profile): string
    {
        return $this->toSgr($profile, fg: false);
    }

    /**
     * SGR 58 — coloured underline (xterm-256 extension). Pairs with
     * SGR 4 (underline) to render an underline whose colour is
     * independent of the foreground. Returns the empty string when
     * the colour profile doesn't support ANSI.
     */
    public function toUnderline(ColorProfile $profile): string
    {
        if (!$profile->supportsAnsi()) {
            return '';
        }
        if ($profile->supportsTrueColor()) {
            return Ansi::CSI . '58;2;' . $this->r . ';' . $this->g . ';' . $this->b . 'm';
        }
        if ($profile->supports256()) {
            return Ansi::CSI . '58;5;' . $this->nearest256() . 'm';
        }
        // 16-color terminals don't support 58; fall back to 16-color
        // 38 fg slot — still renders, just colours the text rather
        // than the underline. Better than dropping the colour.
        $idx = $this->nearestAnsi16();
        $code = $idx < 8 ? 30 + $idx : 90 + ($idx - 8);
        return Ansi::CSI . $code . 'm';
    }

    private function toSgr(ColorProfile $profile, bool $fg): string
    {
        if (!$profile->supportsAnsi()) {
            return '';
        }
        if ($profile->supportsTrueColor()) {
            return $fg
                ? Ansi::fgRgb($this->r, $this->g, $this->b)
                : Ansi::bgRgb($this->r, $this->g, $this->b);
        }
        if ($profile->supports256()) {
            $idx = $this->nearest256();
            return $fg ? Ansi::fg256($idx) : Ansi::bg256($idx);
        }
        $idx = $this->nearestAnsi16();
        $code = $fg ? ($idx < 8 ? 30 + $idx : 90 + ($idx - 8))
                    : ($idx < 8 ? 40 + $idx : 100 + ($idx - 8));
        return Ansi::CSI . $code . 'm';
    }

    private function nearest256(): int
    {
        // Cube candidate (indices 16-231).
        $q = static fn(int $v): int => match (true) {
            $v < 48  => 0,
            $v < 115 => 1,
            default  => intdiv($v - 35, 40),
        };
        $cubeIdx = 16 + 36 * $q($this->r) + 6 * $q($this->g) + $q($this->b);

        // Grayscale candidate (indices 232-255). Levels: 8, 18, ..., 238.
        $avg     = ($this->r + $this->g + $this->b) / 3.0;
        $grayBin = (int) round(($avg - 8.0) / 10.0);
        $grayBin = max(0, min(23, $grayBin));
        $grayIdx = 232 + $grayBin;

        return self::distToIndex($cubeIdx) <= self::distToIndex($grayIdx)
            ? $cubeIdx
            : $grayIdx;
    }

    /** Squared RGB distance from this color to the palette color at $idx. */
    private function distToIndex(int $idx): int
    {
        $c  = self::ansi256($idx);
        $dr = $c->r - $this->r;
        $dg = $c->g - $this->g;
        $db = $c->b - $this->b;
        return $dr * $dr + $dg * $dg + $db * $db;
    }

    private function nearestAnsi16(): int
    {
        $best = 0;
        $bestDist = PHP_INT_MAX;
        foreach (self::ANSI16 as $idx => [$r, $g, $b]) {
            $dr = $r - $this->r;
            $dg = $g - $this->g;
            $db = $b - $this->b;
            $d = $dr * $dr + $dg * $dg + $db * $db;
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = $idx;
            }
        }
        return $best;
    }
}
