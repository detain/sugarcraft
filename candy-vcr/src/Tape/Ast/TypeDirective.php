<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Type "..." directive — types a string of characters.
 */
final readonly class TypeDirective implements Directive
{
    public function __construct(
        public string $text,
    ) {
    }
}
