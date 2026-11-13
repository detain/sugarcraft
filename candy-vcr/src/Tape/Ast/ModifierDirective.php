<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Bare modifier keyword (Alt / Shift). Upstream's lexer carries these as
 * first-class keywords; here they arm a pending modifier that merges into
 * the NEXT keystroke directive's bytes (Alt = the ESC meta lead sugar-core
 * documents, Shift = the xterm `;2` parameter). A tape that ends with a
 * modifier never applied is reported, never silent (finding 005).
 */
final readonly class ModifierDirective implements Directive
{
    public function __construct(
        public string $modifier,
    ) {
    }
}
