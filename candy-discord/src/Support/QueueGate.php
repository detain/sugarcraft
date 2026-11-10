<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\TimerInterface;

/**
 * The "collect" queue mode from the §9.2 vocabulary: prompts that arrive
 * while a turn is running are coalesced into ONE follow-up instead of
 * N separate queued sends.
 *
 * Debounce semantics (§9.2: 500 ms window, cap 20): the window opens when
 * the first item lands and does NOT extend on later pushes — a burst that
 * keeps talking must still reach the model eventually. Over-cap pushes
 * drop the OLDEST text and report the drop count so the caller can tell
 * the user loudly which pieces are gone (disclosed-drop law, same posture
 * as the §7.8 media down-select).
 *
 * The one timer here rides the caller's loop and cancelAll() unwires it —
 * the P1 dispose law, and E646-clean by construction: this is prompt
 * plumbing, no wall-clock bound on any model answer.
 */
final class QueueGate
{
    /** @var array<string, list<string>> */
    private array $pending = [];

    /** @var array<string, TimerInterface> */
    private array $timers = [];

    /** @var array<string, callable(list<string>):void> */
    private array $flushes = [];

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly int $debounceMs = 500,
        private readonly int $maxPending = 20,
    ) {
    }

    /**
     * Append one prompt to the conversation's window. $flush receives the
     * coalesced list (joined by the caller's chosen separator) when the
     * window closes. Returns how many older prompts this push dropped.
     *
     * @param callable(list<string>):void $flush
     */
    public function push(string $key, string $text, callable $flush): int
    {
        $list = $this->pending[$key] ?? [];
        $list[] = $text;

        $dropped = 0;
        while (count($list) > $this->maxPending) {
            array_shift($list);
            $dropped++;
        }

        $this->pending[$key] = $list;
        $this->flushes[$key] = $flush;

        if (!isset($this->timers[$key])) {
            $this->timers[$key] = $this->loop->addTimer($this->debounceMs / 1000.0, function () use ($key): void {
                unset($this->timers[$key]);
                $list = $this->pending[$key] ?? [];
                $flush = $this->flushes[$key] ?? null;
                unset($this->pending[$key], $this->flushes[$key]);
                if ($list === [] || $flush === null) {
                    return;
                }

                $flush($list);
            });
        }

        return $dropped;
    }

    /** Number of prompts currently waiting in $key's open window. */
    public function waiting(string $key): int
    {
        return count($this->pending[$key] ?? []);
    }

    /** @return list<string> */
    public function pendingKeys(): array
    {
        return array_keys($this->pending);
    }

    /**
     * Unwire every armed timer and forget all windows (dispose path).
     * In-flight text is deliberately dropped: the serve connection is
     * going away with it, so there is nothing left to flush into.
     */
    public function cancelAll(): void
    {
        foreach ($this->timers as $timer) {
            $this->loop->cancelTimer($timer);
        }

        $this->timers = [];
        $this->pending = [];
        $this->flushes = [];
    }
}
