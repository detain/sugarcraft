<?php

declare(strict_types=1);

namespace SugarCraft\Image\Passthrough;

/**
 * Incremental Screen packetizer — the single source of framing truth
 * behind {@see ScreenPassthrough}.
 *
 * wrap() and encodeBandStream() both drive one instance of this machine
 * (wrap = feed(whole string)+finish; stream = feed(chunk)+…+finish), so
 * the two paths cannot drift: the concatenation of streamed writes is
 * byte-identical to wrapping the complete render() bytes no matter where
 * chunk boundaries fall.
 *
 * Framing rules mirrored from chicle's passthrough encoder
 * (internal/chafa-passthrough-encoder.c, SCREEN branch):
 * - payload split into packets of at most
 *   {@see ScreenPassthrough::MAX_PACKET_BYTES} bytes, each
 *   `ESC P` … `ESC \`, boundaries placed purely by payload position
 *   (append_packetized keeps one logical packet open across appends and
 *   rolls to a fresh begin/end pair exactly when full);
 * - no ESC-doubling anywhere on the Screen path (that is tmux-only —
 *   append_escaped sits in the TMUX branch);
 * - a run's String Terminator is emitted byte-wise, one self-contained
 *   packet per ST byte (chafa-sixel-renderer.c end_sixels).
 *
 * Recorded divergence: chafa additionally re-sizes the KITTY
 * payload itself under Screen — build_image_chunks emits 63-byte image
 * chunks when the encoder is in SCREEN mode and 510 otherwise
 * (internal/chafa-kitty-renderer.c:276). We do not re-chunk: the chunk
 * sizes ride into this packetizer unchanged and the 200-byte begin/end
 * wrapping is placed purely by payload position, so Screen reassembly of
 * the envelope stays byte-identical either way; the smaller in-Screen chunk
 * is a chafa emitter detail, not a framing requirement.
 *
 * @internal Not part of the public API.
 */
final class ScreenPacketizer
{
    /** chafa-term-db.c:492 begin_screen_passthrough "\033P". */
    private const BEGIN = "\x1b" . 'P';

    /** chafa-term-db.c:493 end_screen_passthrough "\033\\". */
    private const END = "\x1b\\";

    /** Bytes that open a wrapped control-sequence run after a leading ESC. */
    private const RUN_OPENERS = ['P', ']', '_'];

    private string $pending = '';

    /** True while inside a control-sequence run (payload being packetized). */
    private bool $inRun = false;

    /** True when the current run was opened by ESC ] (OSC BEL rule). */
    private bool $oscRun = false;

    /** Payload bytes in the currently open packet (0 = no open packet). */
    private int $packetSize = 0;

    private string $out = '';

    public function feed(string $bytes): void
    {
        $this->pending .= $bytes;
        $this->pump();
    }

    /**
     * Feed one streaming chunk and take the bytes this step produced, so
     * a streaming caller forwards to its consumer incrementally instead of
     * buffering the whole poster (the packetizer is the only structure in
     * the path).
     */
    public function capture(string $bytes): string
    {
        $this->feed($bytes);
        $emitted = $this->out;
        $this->out = '';
        return $emitted;
    }

    /** Emit everything still buffered and return the accumulated output. */
    public function finish(): string
    {
        if ($this->inRun) {
            // Truncated run: chicle's encoder_end flushes the open packet.
            $this->emitPacketBytes($this->pending);
            $this->closePacket();
            $this->inRun = false;
        } else {
            $this->out .= $this->pending;
        }
        $this->pending = '';
        $result = $this->out;
        $this->out = '';
        return $result;
    }

    private function pump(): void
    {
        while ($this->pending !== '') {
            $advanced = $this->inRun ? $this->stepInside() : $this->stepOutside();
            if (!$advanced) {
                return;
            }
        }
    }

    /**
     * Raw mode: copy bytes verbatim until the next run opener. Returns
     * false when the buffer holds only an undecided trailing ESC (one
     * more byte is needed to tell opener from ordinary CSI).
     */
    private function stepOutside(): bool
    {
        $esc = strpos($this->pending, "\x1b");
        if ($esc === false) {
            $this->out .= $this->pending;
            $this->pending = '';
            return true;
        }
        if ($esc > 0) {
            $this->out .= substr($this->pending, 0, $esc);
            $this->pending = substr($this->pending, $esc);
        }
        // pending now starts with ESC
        if (strlen($this->pending) < 2) {
            return false;
        }
        $second = $this->pending[1];
        if (!in_array($second, self::RUN_OPENERS, true)) {
            // Lone ESC opening nothing ordinary (CSI, SGR …): raw-copy the
            // single byte and rescan — an opener may start at the next ESC.
            $this->out .= "\x1b";
            $this->pending = substr($this->pending, 1);
            return true;
        }
        // Run starts. The 2-byte introducer rides INSIDE the packet
        // payload: chicle appends the whole emitted sequence, envelope
        // begin/end added by the encoder, inner ESC kept undoubled.
        $this->inRun = true;
        $this->oscRun = $second === ']';
        $this->emitPacketBytes(substr($this->pending, 0, 2));
        $this->pending = substr($this->pending, 2);
        return true;
    }

    /**
     * Run mode: packetize payload bytes, stop at the run's terminator.
     * Returns false when more input is needed (trailing undecided ESC may
     * begin the run's ST).
     */
    private function stepInside(): bool
    {
        $buf = $this->pending;
        $len = strlen($buf);

        $stPos = strpos($buf, "\x1b\\");
        $belPos = $this->oscRun ? strpos($buf, "\x07") : false;

        if ($belPos !== false && ($stPos === false || $belPos < $stPos)) {
            // OSC terminated by BEL: the BEL itself is payload — it rides
            // inside the packet (Screen forwards it to the outer terminal,
            // which reads the OSC) — and the packet closes at run end.
            $this->emitPacketBytes(substr($buf, 0, $belPos + 1));
            $this->pending = substr($buf, $belPos + 1);
            $this->closePacket();
            $this->inRun = false;
            return true;
        }

        if ($stPos !== false) {
            // Payload before the ST rides the (possibly open) packet.
            if ($stPos > 0) {
                $this->emitPacketBytes(substr($buf, 0, $stPos));
            }
            $this->pending = substr($buf, $stPos + 2);
            $this->closePacket();
            // The byte-wise ST workaround — chicle end_sixels comment
            // spells the shape: "\\e P \\e \\e \\\\ \\e P \\\\ \\e \\\\"
            // i.e. begin, ESC, end, begin, backslash, end. Screen's parser
            // loses a run whose terminator is glued to the payload tail;
            // one byte per self-contained packet survives it.
            $this->out .= self::BEGIN . "\x1b" . self::END;
            $this->out .= self::BEGIN . '\\' . self::END;
            $this->inRun = false;
            return true;
        }

        // No terminator yet. Hold a trailing lone ESC — the run's ST may be
        // split across the next chunk.
        $emittable = $len;
        if ($buf[$len - 1] === "\x1b") {
            $emittable = $len - 1;
        }
        if ($emittable > 0) {
            $this->emitPacketBytes(substr($buf, 0, $emittable));
            $this->pending = substr($buf, $emittable);
        }
        return false;
    }

    /**
     * chicle append_packetized: fill the open packet to the cap, roll to a
     * fresh begin/end pair exactly when full, open lazily on first byte.
     */
    private function emitPacketBytes(string $bytes): void
    {
        $offset = 0;
        $len = strlen($bytes);
        while ($offset < $len) {
            if ($this->packetSize === 0) {
                $this->out .= self::BEGIN;
            }
            $room = ScreenPassthrough::MAX_PACKET_BYTES - $this->packetSize;
            $n = min($room, $len - $offset);
            $this->out .= substr($bytes, $offset, $n);
            $this->packetSize += $n;
            $offset += $n;
            if ($this->packetSize === ScreenPassthrough::MAX_PACKET_BYTES) {
                $this->closePacket();
            }
        }
    }

    private function closePacket(): void
    {
        if ($this->packetSize > 0) {
            $this->out .= self::END;
            $this->packetSize = 0;
        }
    }
}
