<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Named-key directive from upstream's measured command roster:
 * Delete, Insert, PageUp, PageDown, End, ScrollUp, ScrollDown.
 * The Compiler maps each to its xterm sequence (the same shapes
 * sugar-core InputReader decodes back into KeyType cases).
 */
final readonly class KeyDirective implements Directive
{
    public function __construct(
        public string $key,
    ) {
    }
}
