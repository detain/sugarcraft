<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\Renderer;
use SugarCraft\Mosaic\Renderer\SixelRenderer;

/**
 * Renderer decorator that wraps output in tmux's passthrough protocol.
 *
 * When running inside tmux, the terminal cannot directly interpret DCS/APC/OSC
 * sequences — they must be forwarded through tmux's passthrough envelope:
 *
 *   \x1bPtmux; <escaped-inner> \x1b\\
 *
 * where any lone \x1b inside the payload is doubled (\x1b\x1b).
 *
 * tmux must have `allow-passthrough on` set (default: off in tmux 3.3+):
 *
 *   set -g allow-passthrough on
 *
 * @see https://github.com/tmux/tmux/wiki/Passthrough
 */
final class TmuxPassthroughDecorator implements Renderer
{
    public function __construct(
        private readonly Renderer $inner,
    ) {}

    /**
     * The wrapped renderer — lets callers reach protocol-specific behaviour
     * (dither, palette size) through the passthrough envelope.
     */
    public function inner(): Renderer
    {
        return $this->inner;
    }

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        return $this->wrap($this->inner->render($image, $width, $height));
    }

    /**
     * Stream a Sixel render through the tmux passthrough envelope.
     *
     * A Sixel image is exactly ONE Device Control String
     * (`\x1bP … \x1b\\`) with no lone ESC in its printable payload, so tmux's
     * only requirement — double every inner ESC — is a context-free, per-byte
     * transform. That lets the envelope be opened before the first band and
     * closed after the last without ever buffering the image: the concatenation
     * of `$write` calls is byte-for-byte identical to
     * {@see render()}'s wrapped output. This is the streaming branch the
     * Sixel encode (#17) needed, chosen over refusing to stream under tmux
     * because it is both cheap (a per-chunk `str_replace`) and provably exact.
     *
     * @param callable(string):void $write
     * @throws \LogicException  if the wrapped renderer is not Sixel (only Sixel
     *                          exposes a band-stream encoder; other protocols
     *                          are single-shot buffers with no banding to stream)
     */
    public function encodeBandStream(ImageSource $image, int $width, callable $write, ?int $height = null): void
    {
        $inner = $this->inner;
        if (!$inner instanceof SixelRenderer) {
            throw new \LogicException(
                Lang::t('tmux.stream_not_sixel', ['name' => $inner->name()])
            );
        }

        // Open the envelope lazily, on the first inner chunk: if the encode fails
        // before emitting anything (a GD load error, an over-ceiling frame) the TTY
        // is left untouched rather than stranded inside an unterminated
        // `\x1bPtmux;` passthrough. The outer terminator is then guaranteed via
        // finally, so a mid-stream consumer failure still closes the envelope.
        $opened = false;
        $closed = false;
        try {
            $inner->encodeBandStream($image, $width, static function (string $chunk) use ($write, &$opened): void {
                if (!$opened) {
                    $write("\x1bPtmux;");
                    $opened = true;
                }
                $write(str_replace("\x1b", "\x1b\x1b", $chunk));
            }, $height);
            $write("\x1b\\");
            $closed = true;
        } finally {
            if ($opened && !$closed) {
                try {
                    $write("\x1b\\");
                } catch (\Throwable) {
                    // The consumer is gone; the envelope cannot be closed for it.
                }
            }
        }
    }

    public function name(): string
    {
        return 'tmux(' . $this->inner->name() . ')';
    }

    public function supportsAlpha(): bool
    {
        return $this->inner->supportsAlpha();
    }

    public function isInline(): bool
    {
        return $this->inner->isInline();
    }

    public function delete(string $imageId): string
    {
        return $this->wrap($this->inner->delete($imageId));
    }

    /**
     * Wrap raw ANSI bytes in the tmux passthrough envelope.
     *
     * Scans for DCS (ESC P), APC (ESC _), and OSC (ESC ]) sequences and
     * re-encodes them inside the tmux DCS envelope:
     *
     *   \x1bPtmux;  <content with \x1b doubled>  \x1b\\
     *
     * Other bytes are returned unchanged.
     */
    public function wrap(string $ansi): string
    {
        if ($ansi === '') {
            return '';
        }

        $out   = '';
        $flush = 0; // position we have scanned up to

        $len = strlen($ansi);
        for ($i = 0; $i < $len; $i++) {
            // Fast path: skip ahead when not at an escape byte.
            if ($ansi[$i] !== "\x1b") {
                continue;
            }

            // Drain any plain bytes before this escape.
            if ($i > $flush) {
                $out .= substr($ansi, $flush, $i - $flush);
            }

            $remaining = substr($ansi, $i);

            if (str_starts_with($remaining, "\x1bP")) {
                // DCS: \x1bP … \x1b\\
                $end = $this->scanSt($remaining, 2); // skip ESC P
                $seq = substr($remaining, 0, $end);
                $out .= "\x1bPtmux;" . $this->escapeInner($seq) . "\x1b\\";
                $i   = $i + $end - 1;
                $flush = $i + 1;
            } elseif (str_starts_with($remaining, "\x1b_")) {
                // APC: \x1b_ … \x1b\\
                $end = $this->scanSt($remaining, 2); // skip ESC _
                $seq = substr($remaining, 0, $end);
                $out .= "\x1bPtmux;" . $this->escapeInner($seq) . "\x1b\\";
                $i   = $i + $end - 1;
                $flush = $i + 1;
            } elseif (str_starts_with($remaining, "\x1b]")) {
                // OSC: \x1b] … \x07 or \x1b\\
                $end = $this->scanOsc($remaining, 2); // skip ESC ]
                $seq = substr($remaining, 0, $end);
                $out .= "\x1bPtmux;" . $this->escapeInner($seq) . "\x1b\\";
                $i   = $i + $end - 1;
                $flush = $i + 1;
            }
            // Other escape sequences (non-passthrough): pass through as-is.
        }

        // Drain remaining bytes after the last escape.
        if ($flush < $len) {
            $out .= substr($ansi, $flush);
        }

        return $out;
    }

    /**
     * Scan for ST (String Terminator): \x1b\\ or \x07.
     * Returns the total byte length of the sequence including the terminator.
     *
     * @param string $s    Full string starting at offset 0
     * @param int    $skip Bytes to skip at the start (past the introducing ESC)
     */
    private function scanSt(string $s, int $skip): int
    {
        $len = strlen($s);
        for ($i = $skip; $i < $len - 1; $i++) {
            if ($s[$i] === "\x1b" && ($s[$i + 1] ?? '') === '\\') {
                return $i + 2; // include \x1b\\
            }
        }
        // No ST found — check for BEL terminator.
        for ($i = $skip; $i < $len; $i++) {
            if ($s[$i] === "\x07") {
                return $i + 1;
            }
        }
        return $len; // treat entire remaining string as the sequence
    }

    /**
     * Scan OSC terminator: \x07 (BEL) or \x1b\\ (ST).
     * Returns total byte length including terminator.
     */
    private function scanOsc(string $s, int $skip): int
    {
        $len = strlen($s);
        // Look for BEL or ST.
        for ($i = $skip; $i < $len; $i++) {
            if ($s[$i] === "\x07") {
                return $i + 1;
            }
            if ($s[$i] === "\x1b" && ($s[$i + 1] ?? '') === '\\') {
                return $i + 2;
            }
        }
        return $len;
    }

    /**
     * Escape lone \x1b bytes inside a sequence as \x1b\x1b (tmux requirement).
     * The surrounding \x1bP…\x1b\\ envelope is added by the caller.
     */
    private function escapeInner(string $seq): string
    {
        return str_replace("\x1b", "\x1b\x1b", $seq);
    }
}
