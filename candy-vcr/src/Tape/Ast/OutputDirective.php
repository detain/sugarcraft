<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Output <path> directive — sets the output GIF path for rendering.
 */
final readonly class OutputDirective implements Directive
{
    public function __construct(
        public string $path,
    ) {
    }
}
