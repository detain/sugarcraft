<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

/**
 * A shared, mutable cancel flag threaded from {@see \SugarCraft\Crush\Chat}
 * into a {@see \SugarCraft\Crush\Backend}'s {@see Backend::completeAsync()}
 * call for one turn. Deliberately NOT one of Chat's immutable `with*()`
 * value-object fields — the whole point is a single shared instance both
 * sides can see mutate, so Chat's later double-Escape handler can flip it
 * after the async Cmd closure has already captured it.
 */
final class CancellationToken
{
    private bool $cancelled = false;

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
