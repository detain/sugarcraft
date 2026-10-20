<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * F-01: one resolved terminal identity from the {@see TermDb} table.
 *
 * Carries the chained result of matching the declarative rows against an
 * environment snapshot: which pixel protocols the (possibly nested) terminal
 * speaks, and whether each protocol must ride the multiplexer's passthrough
 * wrapper to reach the screen. Mirrors chafa's `ChafaTermInfo` role
 * (chafa/chafa-term-db.c `detect_capabilities()` chains the per-type matches;
 * chafa/chafa-term-info.c:1662 `chafa_term_info_chain()` merges them) minus
 * the escape-sequence payloads — SugarCraft renderers already emit their own
 * bytes; only the capability + passthrough semantics were portable.
 *
 * The object is pure data built by {@see TermDb::matchChain()}; protocol
 * names are the class's own constants, so a row typo cannot smuggle a bogus
 * key into the passthrough map (Fail Fast at construction).
 */
final class TermInfo
{
    public const PROTOCOL_SIXEL = 'sixel';
    public const PROTOCOL_KITTY = 'kitty';
    public const PROTOCOL_ITERM2 = 'iterm2';

    /** Every protocol the table can speak, in Detect precedence order. */
    public const PROTOCOLS = [self::PROTOCOL_KITTY, self::PROTOCOL_ITERM2, self::PROTOCOL_SIXEL];

    /**
     * @param list<self::PROTOCOL_*>  $claims      protocols the chain claims
     * @param array<string, bool>     $passthrough per-protocol "needs the mux passthrough wrapper";
     *                                             absent key = native (no wrapper)
     * @param string|null             $mux         label of the matched multiplexer row, if any
     */
    public function __construct(
        /** Human-readable match chain, innermost first: "tmux(3.4):xterm". */
        public readonly string $chain,
        public readonly array $claims,
        public readonly array $passthrough,
        public readonly ?string $mux,
    ) {
        foreach ($claims as $protocol) {
            if (!in_array($protocol, self::PROTOCOLS, true)) {
                throw new \InvalidArgumentException("unknown protocol claim: {$protocol}");
            }
        }
        foreach ($passthrough as $protocol => $needed) {
            if (!in_array($protocol, self::PROTOCOLS, true)) {
                throw new \InvalidArgumentException("unknown passthrough protocol: {$protocol}");
            }
        }
    }

    /** No row matched at all — the environment says nothing about pixels. */
    public static function unknown(): self
    {
        return new self('unknown', [], [], null);
    }

    public function supports(string $protocol): bool
    {
        return in_array($protocol, $this->claims, true);
    }

    /**
     * Whether $protocol only reaches the screen wrapped in the mux passthrough
     * sequence (\e Ptmux;…\e \\ or \e P…\e \\). False = the chain renders it
     * natively — chicle's `pixel_passthrough_needed` table (chafa-term-db.c
     * :474-490: tmux 3.4+ flips the SIXELS entry to FALSE because tmux decodes
     * sixels itself).
     */
    public function needsPassthrough(string $protocol): bool
    {
        if (!$this->supports($protocol)) {
            return false;
        }

        return ($this->passthrough[$protocol] ?? false) === true;
    }

    public function hasPixelProtocol(): bool
    {
        return $this->claims !== [];
    }
}
