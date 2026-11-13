<?php

declare(strict_types=1);

namespace SugarCraft\Image;

use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Passthrough\PassthroughDecorator;
use SugarCraft\Image\Renderer\Renderer;
use SugarCraft\Image\Renderer\SixelRenderer;

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
 * The option itself is managed too: when {@see Mosaic} mints a
 * capability that runs under tmux with a pixel protocol, {@see
 * TmuxPassthrough::ensure()} flips `allow-passthrough` on (pane-scoped)
 * for the process and restores the prior value at retire/shutdown.
 * Manual configuration (`set -g allow-passthrough on`) remains the
 * fallback when the transaction is opted out via {@see
 * Detect::setAutoPassthrough(false)} or tmux refuses the toggle.
 *
 * GNU Screen has its own envelope with different rules — payload
 * packetized at 200 bytes, no ESC-doubling, and a byte-wise ST workaround
 * for sixel terminators — implemented by {@see
 * \SugarCraft\Image\Passthrough\ScreenPassthrough}. Screen needs no
 * option transaction like the one above: its parser accepts `ESC P … ESC \`
 * unconditionally, so this class's tmux-only scope stays exactly as
 * shipped it.
 *
 * @see https://github.com/tmux/tmux/wiki/Passthrough
 *
 * Mirrors the tmux DCS passthrough envelope convention
 * (allow-passthrough-gated), the same documented ground as
 * TmuxPassthrough; the decorator discipline — wrap one renderer's bytes,
 * never re-encode — is SugarCraft's.
 */
final class TmuxPassthroughDecorator implements Renderer, PassthroughDecorator
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

    public function decorate(Renderer $inner): Renderer
    {
        return new self($inner);
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
                    // Open the envelope flag before delivering the introducer, so a
                    // consumer that writes then throws on the first chunk still gets
                    // the guaranteed `finally` close. An orphan ST is inert; an
                    // unterminated `\x1bPtmux;` swallows the rest of the session.
                    $opened = true;
                    $write("\x1bPtmux;");
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
     *
     * The walk (SI-41) indexes the original buffer and the scans return
     * absolute offsets: the only copies are output spans and the wrapped
     * sequences themselves — never a tail slice per escape.
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

            // Drain any plain bytes before this escape, then park the flush
            // mark AT the escape byte. Every span is therefore copied exactly
            // once: a matched arm below overwrites $flush with the end of the
            // sequence it just emitted, and an unmatched one leaves the flush
            // here so the next drain (or the final drain) carries the ESC and
            // its parameters unchanged. Leaving $flush behind re-appended the
            // stale prefix at every later escape — quadratic blow-up.
            if ($i > $flush) {
                $out .= substr($ansi, $flush, $i - $flush);
            }
            $flush = $i;

            // SI-41: the introducer is INDEXED, not sliced, and the scans
            // below work absolute offsets into the original buffer. The old
            // per-escape substr($ansi, $i) tail copy moved O(n x escapes)
            // transient bytes — ~10 GiB for a hostile 2 MiB poster with 10k
            // leading escapes — to answer a question two indexes can ask.
            $introducer = $i + 1 < $len ? $ansi[$i + 1] : '';

            if ($introducer === 'P' || $introducer === '_') {
                // DCS / APC: \x1bP … \x1b\\ and \x1b_ … \x1b\\ — the
                // historic two-pass scan lets ST win over BEL no matter
                // where either sits; the merge of the two arms keeps that
                // law (and every other byte of behaviour) identical.
                $end = $this->scanSt($ansi, $i, 2);
            } elseif ($introducer === ']') {
                // OSC: \x1b] … \x07 or \x1b\\ — first terminator wins.
                $end = $this->scanOsc($ansi, $i, 2);
            } else {
                continue; // non-passthrough escape: passes through as-is
            }

            $out   .= "\x1bPtmux;" . $this->escapeInner(substr($ansi, $i, $end - $i)) . "\x1b\\";
            $i      = $end - 1;
            $flush  = $end;
        }

        // Drain remaining bytes after the last escape.
        if ($flush < $len) {
            $out .= substr($ansi, $flush);
        }

        return $out;
    }

    /**
     * Scan for ST (String Terminator): \x1b\\ or \x07.
     * Returns the ABSOLUTE exclusive end offset of the sequence (SI-41:
     * the walk stays on the caller's buffer — no tail copy per escape).
     * ST beats BEL globally: the historic arm scanned the whole remaining
     * string for ST before ever consulting BEL, and that precedence is
     * pinned by testTheStBeatsAnEarlierBelQuirkIsCarriedIntoTheZeroCopyWalk.
     *
     * @param string $ansi  the full buffer
     * @param int    $start absolute offset of the introducing ESC
     * @param int    $skip  bytes to skip at the start (past the ESC + introducer)
     */
    private function scanSt(string $ansi, int $start, int $skip): int
    {
        $len  = strlen($ansi);
        $from = $start + $skip;
        for ($i = $from; $i < $len - 1; $i++) {
            if ($ansi[$i] === "\x1b" && $ansi[$i + 1] === '\\') {
                return $i + 2; // include \x1b\\
            }
        }
        // No ST found — check for BEL terminator.
        for ($i = $from; $i < $len; $i++) {
            if ($ansi[$i] === "\x07") {
                return $i + 1;
            }
        }
        return $len; // treat entire remaining string as the sequence
    }

    /**
     * Scan OSC terminator: \x07 (BEL) or \x1b\\ (ST), whichever comes
     * FIRST positionally. Returns the absolute exclusive end offset.
     */
    private function scanOsc(string $ansi, int $start, int $skip): int
    {
        $len = strlen($ansi);
        for ($i = $start + $skip; $i < $len; $i++) {
            if ($ansi[$i] === "\x07") {
                return $i + 1;
            }
            if ($ansi[$i] === "\x1b" && $i + 1 < $len && $ansi[$i + 1] === '\\') {
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
