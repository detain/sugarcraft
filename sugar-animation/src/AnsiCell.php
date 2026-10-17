<?php

declare(strict_types=1);

namespace SugarCraft\Animation;

/**
 * One rendered terminal cell of a parsed `.ansi` artwork: the glyph plus
 * the CANONICAL RESOLVED SGR state active for it — the full cumulative
 * ECMA-48 style (attributes + foreground + background) a real terminal
 * would be showing at that point, as a single `ESC[…m` run in the fixed
 * order documented on `AnsiArt::canonicalSgr()`; '' when every component
 * is at terminal default.
 *
 * The whole-state form (not the raw source run) is deliberate: consumers
 * blit cells standalone into cell grids whose `put()` sets a cell's entire
 * style at once, so an inherited background or attribute a source run
 * omitted would otherwise silently vanish from the painted cell.
 */
final class AnsiCell
{
    private function __construct(
        public readonly string $glyph,
        public readonly string $sgr,
    ) {}

    /**
     * House factory convention (`::new()`), mirroring the sugar-style
     * immutable value-object shape.
     */
    public static function new(string $glyph, string $sgr = ''): self
    {
        return new self($glyph, $sgr);
    }
}
