<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Picture;

use SugarCraft\Core\Util\Color;

/**
 * Inline-image renderer for SugarCharts. Accepts a 2D RGB pixel grid
 * and emits the appropriate terminal escape sequence for the active
 * (or explicitly-selected) image protocol.
 *
 * Mirrors ntcharts' `picture` chart slot.
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

    // Short-form aliases.
    /** Short-form alias for {@see withProtocol()}. */
    public function protocol(?Protocol $p): self { return $this->withProtocol($p); }
    /** Short-form alias for {@see withPaletteSize()}. */
    public function paletteSize(int $n): self    { return $this->withPaletteSize($n); }

    /**
     * Pick a protocol based on the running terminal's capabilities.
     * Honours `$TERM_PROGRAM` (iTerm2, WezTerm), `$TERM` (kitty, foot,
     * mlterm), `$KITTY_WINDOW_ID`, and the Windows Terminal pair
     * `$WT_SESSION`/`$WT_PROFILE_ID`.
     *
     * Auto-detected: foot, mlterm, WezTerm, iTerm2, kitty, Windows
     * Terminal. Everything else — xterm included, whose sixel support
     * depends on a compile-time flag with no env signal — is only
     * reachable through an explicit {@see withProtocol()}
     * (sugar-charts-115).
     *
     * Multiplexer state is first-class (sugar-charts-012): under TMUX or
     * ZELLIJ the inherited `$TERM`/`$TERM_PROGRAM` pair lies in both
     * directions — iTerm2-in-tmux keeps `TERM_PROGRAM` and would emit
     * OSC 1337 the pane never forwards (silently invisible image), while
     * kitty-in-tmux loses `TERM=xterm-kitty` and would detect as nothing.
     * A pane therefore returns null (the placeholder path) unless the
     * caller opts into raw escape forwarding with
     * `CHARTS_PICTURE_PASSTHROUGH=1`, which re-enables the table below
     * against the outer terminal's surviving env (kitty's own
     * `KITTY_WINDOW_ID` rides through, hence that arm too).
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

        if (self::muxed($env) && !self::passthroughRequested($env)) {
            return null;
        }

        if (str_contains($program, 'iterm')) {
            return Protocol::ITerm2;
        }
        if (str_contains($program, 'wezterm')) {
            return Protocol::Sixel;
        }
        // kitty's TERM arm clobbers under a multiplexer; the window-id
        // export survives and catches the passthrough case.
        if (str_contains($term, 'kitty') || isset($env['KITTY_WINDOW_ID'])) {
            return Protocol::Kitty;
        }
        if (str_contains($term, 'foot') || str_contains($term, 'mlterm')) {
            return Protocol::Sixel;
        }
        // Windows Terminal: sixel is preview-gated since 1.18 but renders
        // inside the modern atlas; before the pair had no arm at all and
        // WT users only ever saw the placeholder (sugar-charts-043).
        if (isset($env['WT_SESSION']) || isset($env['WT_PROFILE_ID'])) {
            return Protocol::Sixel;
        }
        // xterm sometimes ships with sixel support, but only when
        // built with --enable-sixel. No reliable env signal — skip.
        return null;
    }

    /** Under a terminal multiplexer whose pane may not forward escapes? */
    private static function muxed(array $env): bool
    {
        return ($env['TMUX'] ?? '') !== ''
            || ($env['ZELLIJ'] ?? '') !== ''
            || ($env['ZELLIJ_SESSION_NAME'] ?? '') !== '';
    }

    /** Opt-in raw-escape forwarding through the multiplexer. */
    private static function passthroughRequested(array $env): bool
    {
        $flag = $env['CHARTS_PICTURE_PASSTHROUGH'] ?? '';
        return $flag !== '' && $flag !== '0';
    }

    /**
     * Honest image frame size [w, h] in PIXELS (sugar-charts-010).
     *
     * Picture emits a protocol stream, not a cell grid, so its frame is
     * the pixel matrix — from the grid directly, or read out of the PNG
     * IHDR header. An instance with neither reports [0, 0] (its view()
     * is placeholder text whose size the terminal invents). Fit modes
     * (contain/stretch to a cell box) are not implemented — the image
     * renders at its pixel size.
     *
     * @return array{0: int, 1: int}
     */
    public function intrinsicSize(): array
    {
        if ($this->pixels !== null) {
            $rows = count($this->pixels);
            return [$rows === 0 ? 0 : count($this->pixels[0]), $rows];
        }
        if (strlen($this->png) >= 24 && str_starts_with($this->png, "\x89PNG\r\n\x1a\n")) {
            $dims = unpack('Nw/Nh', substr($this->png, 16, 8));
            if ($dims !== false) {
                return [(int) $dims['w'], (int) $dims['h']];
            }
        }

        return [0, 0];
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
        $encoded = Sixel::encode($this->pixels, $this->paletteSize);
        if ($encoded === '') {
            // Always-visible placeholder law (sugar-charts-102): an empty or
            // zero-column grid encodes to nothing — the frame must not be a
            // silent void on a Sixel-capable terminal.
            return $this->fallbackText('Sixel: the pixel grid is empty — nothing to encode.');
        }
        return $encoded;
    }

    /**
     * The kitty graphics protocol caps any single APC frame at 4 KiB —
     * a longer frame is truncated by the terminal, corrupting the image.
     * This is the doctrine sugar-image enforces as
     * {@see \SugarCraft\Image\KittyOptions::MAX_FRAME_BYTES} (read-only
     * reference — charts carries no dependency on sugar-image, so the
     * cap is mirrored here; CH-2, docs-reviews-1104).
     */
    private const MAX_FRAME_BYTES = 4096;

    /**
     * Base64 payload bytes per chunk: the frame cap minus the WORST-CASE
     * envelope, floored for margin. Envelope bytes are the APC opener
     * {@see APC_OPEN} (3) + transport header (13 for the opening
     * `f=100,a=T,m=1`, 3 for continuations) + `;` (1) + string
     * terminator {@see ST} (2) = 19 worst-case. 4096 - 19 = 4077, floored
     * to 4072 for a 5-byte safety margin — the same shape as sugar-image's
     * DEFAULT_CHUNK_BYTES reserving parameter room above its raw envelope.
     */
    private const CHUNK_PAYLOAD_BYTES = 4072;

    /** APC opener `ESC _ G` and string terminator `ESC \` around a frame. */
    private const APC_OPEN = "\x1b_G";
    private const ST = "\x1b\\";

    private function encodeKitty(): string
    {
        if ($this->png === '') {
            return $this->fallbackText('Kitty graphics needs PNG bytes (use Picture::fromPng).');
        }
        // Kitty: APC G f=100,a=T;<base64 PNG>... ESC \
        // f=100 = PNG, a=T = transmit + display, m=1 = chunked.
        $b64 = base64_encode($this->png);
        // CH-2 (M18): the split must be envelope-aware — chunking the
        // raw base64 at 4096 produced frames of 4114-4115 bytes, above
        // the protocol cap the terminal measures on the WHOLE frame.
        $chunks = str_split($b64, self::CHUNK_PAYLOAD_BYTES);
        $out = '';
        $count = count($chunks);
        foreach ($chunks as $i => $chunk) {
            $more = $i === $count - 1 ? 0 : 1;
            $header = $i === 0
                ? "f=100,a=T,m={$more}"
                : "m={$more}";
            $out .= self::APC_OPEN . $header . ';' . $chunk . self::ST;
        }
        return $out;
    }

    /**
     * iTerm2 inline image: OSC 1337 with base64 PNG.
     *
     * The payload rides in {@see CHUNK_PAYLOAD_BYTES}-sized frames — each a
     * complete `ESC ] 1337 ; File = inline = 1 ; size = N : chunk BEL` with
     * the same arguments (sugar-charts-095). `size` announces the pre-base64
     * byte total so an accumulator knows when the image is complete; a
     * single-frame transfer (the common small case) is byte-identical to the
     * historic unbounded emit. Even after chunking the caller owns the total
     * payload: a 40 MB PNG is still 40 MB of escape bytes through the pane,
     * Sixel-style — no input-size floor exists.
     */
    private function encodeITerm2(): string
    {
        if ($this->png === '') {
            return $this->fallbackText('iTerm2 inline image needs PNG bytes (use Picture::fromPng).');
        }
        $b64  = base64_encode($this->png);
        $size = strlen($this->png);
        $out  = '';
        foreach (str_split($b64, self::CHUNK_PAYLOAD_BYTES) as $chunk) {
            $out .= "\x1b]1337;File=inline=1;size={$size}:" . $chunk . "\x07";
        }
        return $out;
    }

    private function fallbackText(string $msg = '[image — terminal protocol not detected]'): string
    {
        return $msg;
    }

    /** @return array<string,string> */
    private static function defaultEnv(): array
    {
        $out = [];
        foreach (
            [
                'TERM',
                'TERM_PROGRAM',
                // The arms detect() gained in CH-23 must be readable through
                // the real-environment path too, or a live tmux pane never
                // trips the multiplexer gate.
                'TMUX',
                'ZELLIJ',
                'ZELLIJ_SESSION_NAME',
                'CHARTS_PICTURE_PASSTHROUGH',
                'KITTY_WINDOW_ID',
                'WT_SESSION',
                'WT_PROFILE_ID',
            ] as $k
        ) {
            $v = getenv($k);
            if ($v !== false) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
