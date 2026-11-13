<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Bare `Paste` — upstream's clipboard-read command. The record-side
 * compiler has no clipboard to read, so it reports the seam
 * (warn/strict-abort via reportCompileProblem) instead of vanishing like
 * the old UNKNOWN drop (finding 005).
 */
final readonly class PasteDirective implements Directive
{
}
