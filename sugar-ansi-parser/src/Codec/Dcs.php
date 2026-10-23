<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Codec;

/**
 * F-16: structured DCS decoder — the shared codec home for the sequence
 * semantics a byte-stream inspector needs. candy-ansi-inspect STILL carries
 * these branches inline in `Inspector::describeDcs()` (measured at this
 * release); this class is the canonical home they are migrating TO — the
 * migration is an open seam (README "Decoders"), not a landed change.
 *
 * It takes the exact five fields {@see \SugarCraft\AnsiParser\Parser\Handler::dcsDispatch()}
 * delivers, so the final byte is never lost: an inspector that only has a
 * concatenated payload string is exactly how the streaming sixel-detection
 * gap arose (the payload alone cannot say whether the sequence was `ESC P q`
 * or a generic DCS). Callers that own a Parser should pass the dispatch
 * arguments straight through.
 *
 * Truncation semantics (deterministic, pinned by DcsTruncationTest):
 * - `final === 0` means the sequence was truncated — no final byte
 *   (0x40-0x7E) ever arrived. {@see kind()} then reports `truncated` and
 *   {@see describe()} says so, rather than mislabelling the fragment. Zero
 *   is not a legal DCS final byte, so the sentinel cannot collide with a
 *   real sequence.
 * - A DCS that HAS a final byte but no ST is NOT truncated: the parser
 *   dispatches the bytes that arrived at end-of-stream (the same
 *   "flush the open run at end" rule as sugar-image's
 *   ScreenPacketizer::finish()), and `final` is the real final byte — which
 *   is what keeps sixel detection working for a stream that ends mid-payload.
 * - A bare ESC inside the DCS body re-syncs: the collected payload is
 *   dispatched, then the interrupting escape sequence parses normally.
 *   This is NOT park-until-ST; a terminal that parked would swallow the
 *   following escape sequence (the VT500 table's behaviour is authoritative).
 *   A bare ESC inside the PRELUDE aborts the sequence outright (no dispatch);
 *   both edges are pinned in DcsTruncationTest.
 */
final class Dcs
{
    public const KIND_TRUNCATED = 'truncated';
    public const KIND_SIXEL = 'sixel';
    public const KIND_XTVERSION = 'xtversion';
    public const KIND_DECRQSS = 'decrqss';
    public const KIND_DECRPSS = 'decrpss';
    public const KIND_TMUX = 'tmux';
    public const KIND_DCS = 'dcs';

    /** Longest data excerpt `describe()` will quote, in bytes. */
    private const LABEL_BYTES = 80;

    /**
     * @param list<int> $params
     */
    private function __construct(
        private readonly int $final,
        private readonly array $params,
        private readonly int $prefix,
        private readonly int $intermediate,
        private readonly string $data,
    ) {
    }

    /**
     * Decode one DCS dispatch.
     *
     * @param int       $final        Final byte (0x40-0x7E); 0 marks a truncated sequence.
     * @param list<int> $params       Numeric params; -1 marks a missing/default param.
     * @param int       $prefix       Private-marker byte; 0 if none.
     * @param int       $intermediate Intermediate byte; 0 if none.
     * @param string    $data         Payload between the final byte and the terminator.
     */
    public static function parse(
        int $final,
        array $params = [],
        int $prefix = 0,
        int $intermediate = 0,
        string $data = '',
    ): self {
        return new self($final, array_values($params), $prefix, $intermediate, $data);
    }

    public function final(): int
    {
        return $this->final;
    }

    /** @return list<int> */
    public function params(): array
    {
        return $this->params;
    }

    public function prefix(): int
    {
        return $this->prefix;
    }

    public function intermediate(): int
    {
        return $this->intermediate;
    }

    public function data(): string
    {
        return $this->data;
    }

    /**
     * True when no final byte ever arrived (the `final === 0` sentinel).
     */
    public function isTruncated(): bool
    {
        return $this->final === 0;
    }

    public function isSixel(): bool
    {
        return $this->kind() === self::KIND_SIXEL;
    }

    /**
     * One of the KIND_* constants. The DECRQSS check runs before the sixel
     * check because both share the final byte `q` — the `$` intermediate is
     * what separates the DECRQSS request from a DECSIXEL image.
     */
    public function kind(): string
    {
        if ($this->final === 0) {
            return self::KIND_TRUNCATED;
        }
        if ($this->final === \ord('q')) {
            return $this->intermediate === \ord('$') ? self::KIND_DECRQSS : self::KIND_SIXEL;
        }
        if ($this->final === \ord('|') && $this->prefix === \ord('>')) {
            return self::KIND_XTVERSION;
        }
        if ($this->final === \ord('r') && $this->intermediate === \ord('$')) {
            return self::KIND_DECRPSS;
        }
        if ($this->final === \ord('t') && str_starts_with($this->data, 'mux;')) {
            return self::KIND_TMUX;
        }

        return self::KIND_DCS;
    }

    /**
     * One-line, control-byte-safe summary. Payload bytes are attacker-
     * controlled on an untrusted stream, so they are escaped rather than
     * copied verbatim (same discipline as the inspector's label sanitizer).
     */
    public function describe(): string
    {
        return match ($this->kind()) {
            self::KIND_TRUNCATED => 'DCS truncated (no final byte; '
                . \count($this->params) . ' param slot(s), '
                . \strlen($this->data) . ' data bytes)',
            self::KIND_SIXEL => 'sixel image (' . \strlen($this->data) . ' bytes)',
            self::KIND_XTVERSION => 'terminal version (XTVERSION reply): ' . $this->safe($this->data),
            self::KIND_DECRQSS => 'DECRQSS request ' . $this->safe($this->data),
            self::KIND_DECRPSS => 'DECRPSS reply ' . ($this->params[0] ?? -1) . '$r' . $this->safe($this->data),
            self::KIND_TMUX => 'tmux passthrough (' . \strlen($this->data) . ' bytes)',
            default => 'DCS ' . \chr($this->final) . $this->safe($this->data),
        };
    }

    private function safe(string $bytes): string
    {
        $clip = \strlen($bytes) > self::LABEL_BYTES;
        if ($clip) {
            $bytes = substr($bytes, 0, self::LABEL_BYTES);
        }
        $out = '';
        $len = \strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $byte = \ord($bytes[$i]);
            $out .= ($byte < 0x20 || $byte > 0x7E) ? \sprintf('\\x%02X', $byte) : $bytes[$i];
        }

        return $clip ? $out . '…' : $out;
    }
}
