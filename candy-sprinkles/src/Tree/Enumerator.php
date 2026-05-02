<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Tree;

/**
 * Connector character set used by {@see Tree}. Each preset bundles four
 * runes: branch (intermediate child), lastBranch (terminal child),
 * indent (continuation prefix for an intermediate ancestor), and
 * lastIndent (continuation prefix when the ancestor was the last child).
 *
 * Defaults match lipgloss's `DefaultEnumerator` (Unicode box-drawing).
 * `rounded()` swaps the corner rune to ╰; `ascii()` falls back to
 * 7-bit ASCII for terminals without box-drawing fonts.
 */
final class Enumerator
{
    public function __construct(
        public readonly string $branch,
        public readonly string $lastBranch,
        public readonly string $indent,
        public readonly string $lastIndent,
    ) {}

    public static function default(): self
    {
        return new self('├── ', '└── ', '│   ', '    ');
    }

    public static function rounded(): self
    {
        return new self('├── ', '╰── ', '│   ', '    ');
    }

    public static function ascii(): self
    {
        return new self('|-- ', '`-- ', '|   ', '    ');
    }
}
