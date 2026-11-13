<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime;

/**
 * The dashboard palette — every colour the Renderer paints.
 *
 * Immutable value object per repo law: a private constructor with public
 * readonly properties and a `::default()` factory, so a built Theme can
 * never drift under a live frame. The defaults are EXACTLY the hexes the
 * Renderer shipped since the rename, which is what keeps every existing
 * golden byte-identical across the extraction (CT-S33); named palettes
 * hang off this class as further factories (CT-S34).
 */
final class Theme
{
    private function __construct(
        public readonly string $title = '#ff5f87',
        public readonly string $range = '#a78bfa',
        public readonly string $total = '#6ee7b7',
        public readonly string $projects = '#fde68a',
        public readonly string $ranking = '#7dd3fc',
        public readonly string $muted = '#7d6e98',
        public readonly string $error = '#f87171',
        public readonly string $note = '#fca5a5',
    ) {
    }

    /** The shipped palette: the only one that predates theme selection. */
    public static function default(): self
    {
        return new self();
    }
}
