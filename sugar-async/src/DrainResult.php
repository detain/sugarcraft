<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * What {@see ChildPump::drain()} read and why it stopped.
 */
final class DrainResult
{
    /** Every pipe reached EOF. */
    public const CLOSED = 'closed';

    /** $stopAtCap was set and a retained stream reached $maxBytes. */
    public const STOPPED = 'stopped';

    /** The deadline passed with pipes still open. */
    public const EXPIRED = 'expired';

    /** Too many consecutive select() failures in a row: the descriptors are the problem. */
    public const RETRY_BUDGET_GONE = 'retry-budget-gone';

    /**
     * @param array<int|string, string> $buffers  Bytes kept per slot (head of the stream, at most maxBytes)
     * @param array<int|string, int> $dropped  Bytes refused per slot because its buffer was full
     */
    public function __construct(
        public readonly array $buffers,
        public readonly array $dropped,
        public readonly string $reason,
    ) {
    }

    public function buffer(int|string $slot): string
    {
        return $this->buffers[$slot] ?? '';
    }

    /** Bytes refused for $slot (0 when none, or the slot was never retained). */
    public function droppedBytes(int|string $slot): int
    {
        return $this->dropped[$slot] ?? 0;
    }

    /** Whether any retained stream lost bytes to the ceiling. */
    public function truncated(): bool
    {
        return array_sum($this->dropped) > 0;
    }

    /** The deadline ran out — a caller that owns the child kills it on this. */
    public function timedOut(): bool
    {
        return $this->reason === self::EXPIRED;
    }

    public function closed(): bool
    {
        return $this->reason === self::CLOSED;
    }
}
