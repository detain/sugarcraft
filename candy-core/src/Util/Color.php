<?php

declare(strict_types=1);

namespace CandyCore\Core\Util;

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

    public static function rgb(int $r, int $g, int $b): self
    {
        foreach ([$r, $g, $b] as $v) {
            if ($v < 0 || $v > 255) {
                throw new \InvalidArgumentException("rgb component out of range [0,255]: $v");
            }
        }
        return new self($r, $g, $b);
    }

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

    public function toHex(): string
    {
        return sprintf('#%02x%02x%02x', $this->r, $this->g, $this->b);
    }

    public function toFg(ColorProfile $profile): string
    {
        return $this->toSgr($profile, fg: true);
    }

    public function toBg(ColorProfile $profile): string
    {
        return $this->toSgr($profile, fg: false);
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
        $q = static fn(int $v): int => match (true) {
            $v < 48  => 0,
            $v < 115 => 1,
            default  => intdiv($v - 35, 40),
        };
        return 16 + 36 * $q($this->r) + 6 * $q($this->g) + $q($this->b);
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
