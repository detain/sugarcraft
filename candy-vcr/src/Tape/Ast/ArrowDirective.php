<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Arrow direction directive — Up, Down, Left, or Right.
 */
final readonly class ArrowDirective implements Directive
{
    public function __construct(
        public string $direction,
    ) {
    }
}
