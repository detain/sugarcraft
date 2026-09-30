<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Represents a parse error at a specific line.
 *
 * Accessors follow the house bare-name law (F7, round 90); the public
 * readonly props are the primary read surface — Compiler formats nodes
 * via `$node->line`/`$node->message` directly.
 */
final readonly class ParseError
{
    public function __construct(
        public int $line,
        public string $message,
    ) {
    }

    public function line(): int
    {
        return $this->line;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function __toString(): string
    {
        return "Parse error on line {$this->line}: {$this->message}";
    }
}
