<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Palette;

/**
 * Immutable state for the Ctrl+P command palette. `mode` selects which item
 * list is being browsed ('root' → {@see PaletteAction::all()}, 'providers'
 * → provider names, 'themes' → theme names) - this class carries only the
 * mode tag, not the items themselves, so it stays a plain data triple; the
 * mode's actual item list is resolved on demand by
 * {@see \SugarCraft\Crush\Chat::paletteMatches()}.
 */
final class PaletteState
{
    public function __construct(
        public readonly string $mode,
        public readonly string $query,
        public readonly int $selectedIndex,
    ) {}

    public static function root(): self
    {
        return new self('root', '', 0);
    }

    public function withQuery(string $query): self
    {
        return new self($this->mode, $query, 0);
    }

    public function withSelectedIndex(int $selectedIndex): self
    {
        return new self($this->mode, $this->query, $selectedIndex);
    }

    public function withMode(string $mode): self
    {
        return new self($mode, '', 0);
    }
}
