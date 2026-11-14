<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Ctrl+<letter> directive — emits a control character (char code & 0x1F).
 * Mirrors charmbracelet/vhs Ctrl command.
 */
final readonly class CtrlDirective implements Directive
{
    public function __construct(
        public string $letter,
    ) {
    }
}
