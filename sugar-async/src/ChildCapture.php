<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * The per-slot byte ledger shared by the select arm, the Windows poll arm and
 * the loop-driven {@see ChildRun::launch()}: keeps the HEAD of each retained
 * slot up to a ceiling, counts what the ceiling refused, and offers every
 * chunk to the live feed before any of that.
 *
 * Not a result type: {@see result()} is the only thing a caller reads.
 */
final class ChildCapture
{
    /** @var array<int|string, string> */
    private array $buffers = [];

    /** @var array<int|string, int> */
    private array $dropped = [];

    /**
     * @param list<int|string> $slots every slot the pump reads
     * @param list<int|string> $discard slots drained but never retained
     * @param null|\Closure(int|string, string): void $onChunk  live feed, offered
     *        every chunk of every slot (retained or discarded), before retention
     */
    public function __construct(
        array $slots,
        array $discard,
        private readonly int $maxBytes,
        private readonly ?\Closure $onChunk = null,
    ) {
        foreach ($slots as $slot) {
            if (!in_array($slot, $discard, true)) {
                $this->buffers[$slot] = '';
                $this->dropped[$slot] = 0;
            }
        }
    }

    public function offer(int|string $slot, string $chunk): void
    {
        if ($this->onChunk !== null) {
            ($this->onChunk)($slot, $chunk);
        }
        if (!isset($this->buffers[$slot])) {
            return;
        }

        $room = $this->maxBytes - strlen($this->buffers[$slot]);
        if ($room > 0) {
            $this->buffers[$slot] .= substr($chunk, 0, $room);
        }
        $this->dropped[$slot] += strlen($chunk) - max(0, min($room, strlen($chunk)));
    }

    /**
     * Records that bytes were left unread on $slot. The count is a floor, not a
     * measurement: one byte, so {@see DrainResult::truncated()} reports the loss
     * without claiming to know its size. A discarded slot is never retained, so
     * it has no count to raise and nothing to report.
     */
    public function unread(int|string $slot): void
    {
        if (isset($this->dropped[$slot])) {
            $this->dropped[$slot]++;
        }
    }

    /** Whether any retained slot has reached the ceiling. */
    public function anyFull(): bool
    {
        foreach ($this->buffers as $kept) {
            if (strlen($kept) >= $this->maxBytes) {
                return true;
            }
        }

        return false;
    }

    public function result(string $reason): DrainResult
    {
        return new DrainResult($this->buffers, $this->dropped, $reason);
    }
}
