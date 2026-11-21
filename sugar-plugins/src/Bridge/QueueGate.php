<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Bridge;

/**
 * The collect-mode coalescing gate: plain queued text for one conversation
 * waits a short debounce window and flushes as one prompt. The router only
 * pushes; the gate owns the timers.
 */
interface QueueGate
{
    /**
     * Queue text for a conversation key. The flush closure receives every
     * item gathered by the time the window closes.
     *
     * @param callable(list<string>): void $flush
     * @return int how many earlier items the gate dropped to stay bounded
     */
    public function push(string $key, string $text, callable $flush): int;
}
