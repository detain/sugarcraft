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
     * F-06: symbol classes — the subset of chafa's `ChafaSymbolTags`
     * (chafa/chafa-symbol-map.h:34-58) whose glyphs SugarCraft renderers
     * actually emit, narrowed to one bit per drawn class. Chicle tags each
     * symbol with a set; SugarCraft tags each *terminal* with the set of
     * classes it can draw, and consumers ask {@see symbolIsSafe()} before
     * picking a glyph tier (chicle's `safe_symbol_tags` row field,
     * chafa-term-db.c:156/:1071, consulted at symbol-map build time).
     */
    public const SYMBOL_ASCII = 1 << 0;
    public const SYMBOL_BLOCK = 1 << 1;
    public const SYMBOL_BORDER = 1 << 2;
    public const SYMBOL_BRAILLE = 1 << 3;

    /** Full set — what a modern Unicode terminal draws. The table default. */
    public const ALL_SYMBOLS = self::SYMBOL_ASCII | self::SYMBOL_BLOCK | self::SYMBOL_BORDER | self::SYMBOL_BRAILLE;

    /**
     * chicle's `LINUX_CONSOLE_SYMS` (chafa-term-db.c:86): the Linux console
     * and similar plain-vt seats draw no Unicode drawing glyphs at all.
     */
    public const ASCII_ONLY = self::SYMBOL_ASCII;

    /**
     * chicle's `WIN_TERMINAL_SYMS` (chafa-term-db.c:88): cmd.exe-era Windows
     * consoles draw the block and border families but not the dot-pattern
     * (braille) glyphs.
     */
    public const BLOCK_BORDER_ONLY = self::SYMBOL_BLOCK | self::SYMBOL_BORDER;

    /**
     * @param list<self::PROTOCOL_*>  $claims      protocols the chain claims
     * @param array<string, bool>     $passthrough per-protocol "needs the mux passthrough wrapper";
     *                                             absent key = native (no wrapper)
     * @param string|null             $mux         label of the matched multiplexer row, if any
     * @param int                     $safeSymbols F-06 bitmask of the symbol classes the chain
     *                                             draws; the table default is the full set —
     *                                             SC diverges from chicle's clueless-fallback
     *                                             "dumb" row (chafa-term-db.c:1169, ASCII-only),
     *                                             because "the table says nothing" is not
     *                                             evidence of an ancient seat.
     */
    public function __construct(
        /** Human-readable match chain, innermost first: "tmux(3.4):xterm". */
        public readonly string $chain,
        public readonly array $claims,
        public readonly array $passthrough,
        public readonly ?string $mux,
        public readonly int $safeSymbols = self::ALL_SYMBOLS,
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
     * F-06: whether every symbol class in $classes is safe to draw on this
     * chain. Pass one `SYMBOL_*` constant, or a union to require all of them.
     * ASCII is always safe on any SugarCraft output path (7-bit bytes reach
     * every seat the table knows about); the bit exists so the restricted
     * presets can be spelled exactly like chicle's (`LINUX_CONSOLE_SYMS`).
     */
    public function symbolIsSafe(int $classes): bool
    {
        if ($classes === self::SYMBOL_ASCII) {
            return true;
        }

        return ($this->safeSymbols & $classes) === $classes;
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
