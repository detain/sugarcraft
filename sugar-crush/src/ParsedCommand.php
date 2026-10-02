<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

/**
 * Result of parsing a slash-command input.
 *
 * @readonly
 * @immutable
 */
final class ParsedCommand
{
    /**
     * @param non-empty-string           $name  Lowercase command name without the leading /
     * @param list<string>               $args  Positional arguments, shell-quoted and split (an empty quoted "" is kept as "")
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args = [],
    ) {}

    public function withArgs(array $args): self
    {
        return new self($this->name, $args);
    }
}
