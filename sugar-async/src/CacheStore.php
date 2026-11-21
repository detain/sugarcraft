<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Shared mutable storage behind every {@see AsyncCache} handle.
 *
 * WHY an object and not plain arrays on AsyncCache: withTtl()/
 * withServeStaleOnError() return new handles viewing the SAME data (house
 * immutable-options law), and a settled loader's result must land in that
 * shared store even when the options were re-cloned mid-flight. Object
 * identity carries the storage across clones; settle handlers capture the
 * store, never the handle.
 *
 * @internal  Library plumbing — not part of the public API.
 */
final class CacheStore
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, float>  monotonic clock stamp per key, never the value's own time. */
    public array $storedAt = [];

    /**
     * Adoption order as sequence number => key. Every adopt takes a fresh,
     * strictly increasing sequence, so the live slots read oldest-first by
     * number. Slots of keys that were re-adopted, invalidated or evicted are
     * unset and left as holes.
     *
     * @var array<int, string>
     */
    public array $order = [];

    /** @var array<string, int>  key => its live sequence number in $order. */
    public array $seqOf = [];

    /** Next sequence number to hand out; never reused, so holes never collide. */
    public int $nextSeq = 0;

    /**
     * Lowest sequence number that can still be live. Only ever advances, so
     * the holes below it are passed over once in total: eviction is amortized
     * O(1) however many entries churn through the cap.
     */
    public int $head = 0;

    /** Epoch bumped by every invalidate() and clear(); see AsyncCache::generation(). */
    public int $generation = 0;

    /**
     * Monotonic-seconds clock shared by every handle on this store, so stamps
     * written through one clone are judged by the same clock through another.
     * hrtime() rather than wall time: a backward NTP step must not be able to
     * resurrect stale entries or freeze freshness.
     */
    public readonly \Closure $clock;

    public InFlightMap $flights;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock === null
            ? static fn(): float => Clock::monotonicSeconds()
            : \Closure::fromCallable($clock);
        $this->flights = new InFlightMap();
    }

    /** Record $key as the most recently stored, displacing any earlier slot for it. */
    public function touchOrder(string $key): void
    {
        $this->forgetOrder($key);
        $this->order[$this->nextSeq] = $key;
        $this->seqOf[$key] = $this->nextSeq;
        $this->nextSeq++;
    }

    /** Drop $key's slot in the adoption order; a no-op when it has none. */
    public function forgetOrder(string $key): void
    {
        if (isset($this->seqOf[$key])) {
            unset($this->order[$this->seqOf[$key]], $this->seqOf[$key]);
        }
    }

    /** Remove the least recently stored value. The caller ensures one exists. */
    public function evictOldest(): void
    {
        while (!isset($this->order[$this->head])) {
            $this->head++;
        }
        $oldest = $this->order[$this->head];
        $this->forgetOrder($oldest);
        unset($this->values[$oldest], $this->storedAt[$oldest]);
    }

    /** Drop every stored value and the adoption order with them. */
    public function clearValues(): void
    {
        $this->values = [];
        $this->storedAt = [];
        $this->order = [];
        $this->seqOf = [];
        $this->head = $this->nextSeq;
    }
}
