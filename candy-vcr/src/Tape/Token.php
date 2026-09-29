<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape;

/**
 * A single token from the lexer.
 */
final readonly class Token
{
    public function __construct(
        public string $type,
        public string $value,
        public int $line,
    ) {
    }
}
