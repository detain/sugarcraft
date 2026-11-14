<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Inlines and compiles another .tape file at this point.
 * Mirrors charmbracelet/vhs Source command.
 */
final readonly class SourceDirective implements Directive
{
    public function __construct(
        public string $path,
    ) {
    }
}
