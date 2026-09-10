<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Wait directive — a timeout or a condition.
 *
 * `Wait 500ms` sets {@see $seconds}; `Wait /regexp/` sets
 * {@see $pattern} (the delimited source, delimiters included, exactly as
 * the lexer read it — E668). Mirrors charmbracelet/vhs Wait command.
 * Timeouts are honoured by the recorder clock; the condition form has no
 * live output stream on that side, so the compiler carries the pattern in
 * the AST and warns, leaving render-side matching as the filed seam.
 */
final readonly class WaitDirective implements Directive
{
    public function __construct(
        public float $seconds,
        public string $pattern = '',
    ) {
    }
}
