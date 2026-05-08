<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\Renderer;

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

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        return $this->wrap($this->inner->render($image, $width, $height));
    }

    public function name(): string
    {
        return 'tmux(' . $this->inner->name() . ')';
    }

    public function supportsAlpha(): bool
    {
        return $this->inner->supportsAlpha();
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
