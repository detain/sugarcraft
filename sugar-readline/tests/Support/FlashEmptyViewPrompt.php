<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Support;

/**
 * Prompt double whose view() reports an empty string on a scheduled
 * repaint (finding 031 asymmetry probe). Readline must suppress the
 * frame yet PRESERVE the painted-row count, so the next real frame
 * still climbs over the stale one.
 *
 * @param-out never — see methods; state forwards to the inner prompt.
 */
final class FlashEmptyViewPrompt
{
    private int $viewCalls = 0;

    public function __construct(
        private object $inner,
        private int $emptyOnCall,
    ) {
    }

    public function view(): string
    {
        $this->viewCalls++;
        if ($this->viewCalls === $this->emptyOnCall) {
            return '';
        }
        return (string) $this->inner->view();
    }

    public function handleChar(string $char): self
    {
        $this->inner = $this->inner->handleChar($char);
        return $this;
    }

    public function handleKey(string $key): self
    {
        $this->inner = $this->inner->handleKey($key);
        return $this;
    }

    public function isSubmitted(): bool
    {
        return $this->inner->isSubmitted();
    }

    public function isAborted(): bool
    {
        return $this->inner->isAborted();
    }

    public function value(): ?string
    {
        return $this->inner->value();
    }
}
