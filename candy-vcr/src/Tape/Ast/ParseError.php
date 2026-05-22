<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

use InvalidArgumentException;

/**
 * Represents a parse error at a specific line.
 */
final readonly class ParseError
{
    public function __construct(
        public int $line,
        public string $message,
    ) {
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function __toString(): string
    {
        return "Parse error on line {$this->line}: {$this->message}";
    }
}
