<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Arrow direction directive — Up, Down, Left, or Right.
 * Mirrors charmbracelet/vhs Up/Down/Left/Right commands.
 */
final readonly class ArrowDirective implements Directive
{
    public function __construct(
        public string $direction,
    ) {
    }
}
