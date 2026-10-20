<?php

declare(strict_types=1);

namespace SugarCraft\Image\Passthrough;

use SugarCraft\Core\I18n\Lang;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\Renderer;
use SugarCraft\Image\Renderer\SixelRenderer;
use LogicException;

/**
 * F-12: GNU Screen passthrough envelope for a nested renderer.
 *
 * chafa's passthrough encoder (internal/chafa-passthrough-encoder.c)
 * distinguishes tmux from Screen in both framing and escaping:
 *
 * - Screen packets are `ESC P` … `ESC \` with at most 200 bytes of payload
 *   per packet (packet_size_max[CHAFA_PASSTHROUGH_SCREEN] = 200). The
 *   encoder comment records why the cap survives today: Screen's OSC
 *   buffer was raised to 2560 in bfb05c34ba1f961a15ccea04c5, but that
 *   change "still hasn't made its way into some of the important OS
 *   distributions" — so 200 stays, conservatively.
 * - The payload is NOT ESC-doubled. chafa-term-seq-def.h's
 *   begin_screen_passthrough docblock still claims inner ESCs "must be
 *   escaped by turning \\033 into \\033\\033", but the encoder's SCREEN
 *   branch (append_len → append_packetized, no append_escaped) proves the
 *   doubling is tmux-only. This port mirrors the encoder, not the stale
 *   docblock.
 * - A sixel DCS ends with String Terminator `ESC \` — the very bytes that
 *   close a Screen packet. Screen's parser loses a run whose terminator
 *   is glued to the payload tail, so chicle emits each ST byte in its own
 *   one-byte packet (internal/chafa-sixel-renderer.c end_sixels, whose
 *   comment spells the shape `\\e P \\e \\e \\\\ \\e P \\\\ \\e \\\\`
 *   — begin, ESC, end, begin, backslash, end).
 *
 * Unlike the tmux decorator — whose whole-stream ESC-doubling makes its
 * streaming path byte-equal to wrapping the same bytes at once — Screen's
 * content-position packet boundaries demand a single incremental state
 * machine shared by BOTH paths. {@see wrap()} is one call to
 * {@see ScreenPacketizer}; {@see encodeBandStream()} feeds the same
 * machine across band chunks, so the concatenation of streamed writes is
 * identical to wrapping the complete render() bytes regardless of where
 * chunk boundaries fall.
 *
 * Runs recognised (identical set to the tmux decorator): `ESC P` (DCS —
 * sixel), `ESC ]` (OSC — kitty/iTerm2/colour), `ESC _` (APC). DCS/APC runs
 * end at ST; OSC runs end at BEL or ST, whichever comes first. A BEL
 * inside a DCS/APC payload is data, not a terminator — chicle's scanSt
 * BEL fallback cannot be honoured incrementally without look-ahead, and
 * no real DCS uses it.
 */
final class ScreenPassthrough implements Renderer, PassthroughDecorator
{
    /** chicle packet_size_max[CHAFA_PASSTHROUGH_SCREEN]. */
    public const MAX_PACKET_BYTES = 200;

    public function __construct(
        private readonly Renderer $inner,
    ) {}

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

    public function delete(string $imageId): string
    {
        return $this->wrap($this->inner->delete($imageId));
    }

    public function name(): string
    {
        return 'screen(' . $this->inner->name() . ')';
    }

    public function supportsAlpha(): bool
    {
        return $this->inner->supportsAlpha();
    }

    public function isInline(): bool
    {
        return $this->inner->isInline();
    }

    /**
     * Frame a complete ANSI string into Screen passthrough packets.
     *
     * Bytes outside recognised runs are copied verbatim — under Screen the
     * mux is the display layer, so ordinary text and cursor movement
     * belong to Screen itself and only outer-terminal sequences need the
     * envelope. This mirrors chicle, which routes only the graphics
     * protocol's emitted sequences through the encoder (chafa.c writes
     * plain text around it).
     */
    public function wrap(string $ansi): string
    {
        $machine = new ScreenPacketizer();
        $machine->feed($ansi);
        return $machine->finish();
    }

    /**
     * Stream band chunks through the same packetizer state machine wrap()
     * uses, so the concatenation of `$write` bytes is byte-for-byte equal
     * to {@see render()}'s wrapped output no matter where the chunk
     * boundaries fall (the SixelStreamTest twin law, inherited here).
     * Only a nested SixelRenderer can stream; any other inner renderer
     * under a streaming request is a programming error, same posture as
     * the tmux decorator.
     *
     * @param callable(string):void $write
     * @throws LogicException if the wrapped renderer is not Sixel
     */
    public function encodeBandStream(ImageSource $image, int $width, callable $write, ?int $height = null): void
    {
        $inner = $this->inner;
        if (!$inner instanceof SixelRenderer) {
            throw new LogicException(
                Lang::t('screen.stream_not_sixel', ['name' => $inner->name()])
            );
        }

        // The packetizer opens its first `ESC P` lazily, on the first
        // payload byte, so a GD failure before any chunk lands leaves the
        // TTY untouched — same posture as the tmux decorator's lazy open.
        // finish() runs even on failure so a half-open packet never leaks
        // an unterminated `ESC P` into Screen's parser (an unterminated
        // passthrough swallows the rest of the session).
        $machine = new ScreenPacketizer();
        $closed = false;
        try {
            $inner->encodeBandStream($image, $width, static function (string $chunk) use ($write, $machine): void {
                $write($machine->capture($chunk));
            }, $height);
            $write($machine->finish());
            $closed = true;
        } finally {
            if (!$closed) {
                try {
                    $write($machine->finish());
                } catch (\Throwable) {
                    // The consumer is gone; the envelope cannot be closed for it.
                }
            }
        }
    }
}
