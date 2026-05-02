<?php

declare(strict_types=1);

namespace CandyCore\Charts\Picture;

use CandyCore\Core\Util\Color;

/**
 * Inline-image renderer for SugarCharts. Accepts a 2D RGB pixel grid
 * and emits the appropriate terminal escape sequence for the active
 * (or explicitly-selected) image protocol.
 *
 * Mirrors ntcharts' `picture` chart slot in CONVERSION.md Phase 6.
 *
 * Pure-PHP Sixel encoding ships in `Picture\Sixel`. Kitty and iTerm2
 * encodings additionally need PNG bytes — provide them externally
 * via {@see fromPng()} (an `ext-gd` / Imagick wrapper that produces
 * the PNG buffer for you can feed it directly).
 *
 * Usage:
 *
 * ```php
 * // Programmatic pixel grid:
 * $pixels = [];
 * for ($y = 0; $y < 32; $y++) {
 *     $row = [];
 *     for ($x = 0; $x < 32; $x++) {
 *         $row[] = Color::rgb(($x * 8) & 0xff, ($y * 8) & 0xff, 128);
 *     }
 *     $pixels[] = $row;
 * }
 * echo Picture::fromGrid($pixels)->withProtocol(Protocol::Sixel)->view();
 * ```
 */
final class Picture
{
    /**
     * @param ?list<list<Color>>      $pixels  raw RGB grid (optional)
     * @param string                  $png     pre-encoded PNG bytes (optional)
     * @param ?Protocol               $protocol  explicit choice; null = auto-detect
     * @param int                     $paletteSize  Sixel palette cap (default 16)
     */
    private function __construct(
        public readonly ?array $pixels,
        public readonly string $png,
        public readonly ?Protocol $protocol,
        public readonly int $paletteSize,
    ) {}

    /** @param list<list<Color>> $pixels */
    public static function fromGrid(array $pixels): self
    {
        return new self($pixels, '', null, 16);
    }

    /**
     * Build from pre-encoded PNG bytes — caller produces these via
     * `imagepng()` (`ext-gd`) or Imagick. Required for the Kitty /
     * iTerm2 protocols since both wrap base64'd PNG.
     */
    public static function fromPng(string $pngBytes): self
    {
        return new self(null, $pngBytes, null, 16);
    }

    public function withProtocol(?Protocol $p): self
    {
        return new self($this->pixels, $this->png, $p, $this->paletteSize);
    }

    public function withPaletteSize(int $n): self
    {
        return new self($this->pixels, $this->png, $this->protocol, max(2, min(256, $n)));
    }

    /**
     * Pick a protocol based on the running terminal's capabilities.
     * Honours `$TERM_PROGRAM` (iTerm2) and a few common terminals
     * known to speak Kitty graphics or Sixel. Returns null when no
     * supported protocol is detected.
     *
     * The detection is best-effort — terminals don't reliably
     * advertise these capabilities. Pass an explicit
     * {@see withProtocol()} when you know better.
     *
     * @param array<string,string>|null $env  defaults to getenv()
     */
    public static function detect(?array $env = null): ?Protocol
    {
        $env ??= self::defaultEnv();
        $program = strtolower($env['TERM_PROGRAM'] ?? '');
        $term    = strtolower($env['TERM']         ?? '');

        if (str_contains($program, 'iterm')) {
            return Protocol::ITerm2;
        }
        if (str_contains($program, 'wezterm')) {
            return Protocol::Sixel;
        }
        if (str_contains($term, 'kitty')) {
            return Protocol::Kitty;
        }
        if (str_contains($term, 'foot') || str_contains($term, 'mlterm')) {
            return Protocol::Sixel;
        }
        // xterm sometimes ships with sixel support, but only when
        // built with --enable-sixel. No reliable env signal — skip.
        return null;
    }

    public function view(): string
    {
        $protocol = $this->protocol ?? self::detect();
        if ($protocol === null) {
            return $this->fallbackText();
        }
        return match ($protocol) {
            Protocol::Sixel  => $this->encodeSixel(),
            Protocol::Kitty  => $this->encodeKitty(),
            Protocol::ITerm2 => $this->encodeITerm2(),
        };
    }

    public function __toString(): string
    {
        return $this->view();
    }

    private function encodeSixel(): string
    {
        if ($this->pixels === null) {
            return $this->fallbackText('Sixel needs a pixel grid (use Picture::fromGrid).');
        }
        return Sixel::encode($this->pixels, $this->paletteSize);
    }

    private function encodeKitty(): string
    {
        if ($this->png === '') {
            return $this->fallbackText('Kitty graphics needs PNG bytes (use Picture::fromPng).');
        }
        // Kitty: APC G f=100,a=T;<base64 PNG>... ESC \
        // f=100 = PNG, a=T = transmit + display, m=1 = chunked.
        $b64 = base64_encode($this->png);
        $chunks = str_split($b64, 4096);
        $out = '';
        $count = count($chunks);
        foreach ($chunks as $i => $chunk) {
            $more = $i === $count - 1 ? 0 : 1;
            $header = $i === 0
                ? "f=100,a=T,m={$more}"
                : "m={$more}";
            $out .= "\x1b_G{$header};{$chunk}\x1b\\";
        }
        return $out;
    }

    private function encodeITerm2(): string
    {
        if ($this->png === '') {
            return $this->fallbackText('iTerm2 inline image needs PNG bytes (use Picture::fromPng).');
        }
        $b64 = base64_encode($this->png);
        $size = strlen($this->png);
        return "\x1b]1337;File=inline=1;size={$size}:" . $b64 . "\x07";
    }

    private function fallbackText(string $msg = '[image — terminal protocol not detected]'): string
    {
        return $msg;
    }

    /** @return array<string,string> */
    private static function defaultEnv(): array
    {
        $out = [];
        foreach (['TERM', 'TERM_PROGRAM'] as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
