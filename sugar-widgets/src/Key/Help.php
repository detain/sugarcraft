<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Key;

/**
 * Human-readable label + description for a {@see Binding}, used by the
 * Help component.
 *
 * Not to be confused with {@see \SugarCraft\Widgets\Help\Help} — the
 * footer component that renders a {@see KeyMap}. Files importing both
 * must alias one: `use SugarCraft\Widgets\Help\Help as HelpFooter;`.
 */
final class Help
{
    public function __construct(
        public readonly string $key  = '',
        public readonly string $desc = '',
    ) {}
}
