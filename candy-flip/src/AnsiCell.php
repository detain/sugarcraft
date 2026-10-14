<?php

declare(strict_types=1);

namespace SugarCraft\Flip;

/**
 * One rendered terminal cell of a parsed `.ansi` artwork: the glyph plus
 * the raw SGR escape prefix that was active for it ('' when the artwork
 * leaves the cell at terminal default).
 *
 * The prefix is kept verbatim rather than decoded into colours on purpose:
 * replaying art byte-faithfully only requires re-emitting what the source
 * emitted, and decoding would drop the exact reset/attribute combinations
 * the logo renderer produced.
 */
final class AnsiCell
{
    private function __construct(
        public readonly string $glyph,
        public readonly string $sgr,
    ) {}

    /**
     * House factory convention (`::new()`), mirroring the candy-sprinkles
     * immutable value-object shape.
     */
    public static function new(string $glyph, string $sgr = ''): self
    {
        return new self($glyph, $sgr);
    }
}
