<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context;

/**
 * A token budget plus whether it was measured or assumed.
 *
 * `assumed` is true when the backend could not report its window and the
 * budget is {@see ContextWindow::FALLBACK_TOKENS}. Renderers must label that
 * case: the same 100,000 shown as a plain number reads as a fact about the
 * model.
 */
final class WindowSize
{
    public function __construct(
        public readonly int $tokens,
        public readonly bool $assumed,
    ) {
    }
}
