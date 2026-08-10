<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

/**
 * Metadata for one slash command, used by the "/" popup and the Ctrl+P
 * palette to display and filter the command list. Pure display data - it
 * does not affect {@see \SugarCraft\Crush\Chat::submit()}'s own dispatch
 * chain, which stays the single source of truth for what a command
 * actually does.
 */
final class CommandSpec
{
    public function __construct(
        /** Command name without the leading "/", e.g. "compact". */
        public readonly string $name,
        /** One-line human-readable description shown in the popup/palette. */
        public readonly string $description,
        /** Grouping label shown in the Ctrl+P palette, e.g. "Session". */
        public readonly string $category,
    ) {}

    public static function new(string $name, string $description, string $category): self
    {
        return new self($name, $description, $category);
    }
}
