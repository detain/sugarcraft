<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker;

/**
 * Simple in-memory event store for calendar state changes.
 *
 * An optional clock closure (returns int seconds) makes timestamps
 * deterministic in tests; the default is real wall-clock time().
 *
 * The ring is bounded: a host that never drains via {@see release()} cannot
 * grow this without limit — once MAX_EVENTS are held, the oldest event is
 * evicted on each new record.
 */
final class EventStore implements EventStoreInterface
{
    /** Largest number of undrained events kept before the oldest is evicted. */
    public const MAX_EVENTS = 1000;

    /**
     * Ring slots, occupied indices 0 .. MAX_EVENTS-1 only once full; while
     * not yet full entries live at the same indices the head walk visits.
     *
     * @var array<int, array{type: string, payload: array, time: int}>
     */
    private array $events = [];

    /** @var int Index the NEXT record() writes at. */
    private int $head = 0;

    /** @var int Number of events currently held (<= MAX_EVENTS). */
    private int $len = 0;

    public function __construct(
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * House factory mirroring the constructor; the public ctor stays legal as
     * the declared store exception (CALIBER_LEARNINGS) pending a v2 flip.
     */
    public static function new(?\Closure $clock = null): self
    {
        return new self($clock);
    }

    /**
     * @param PickerEvent::* $type event kind (see {@see PickerEvent}); permissive
     *                             at runtime by design — constants are
     *                             documentation + typo protection
     */
    public function record(string $type, array $payload = []): void
    {
        $time = $this->clock !== null ? ($this->clock)() : time();
        // Head-index circular write: eviction overwrites the oldest slot in
        // O(1) instead of array_shift's O(n) memmove of up to 1000 records
        // per event once the ring is full.
        $this->events[$this->head] = ['type' => $type, 'payload' => $payload, 'time' => $time];
        $this->head = ($this->head + 1) % self::MAX_EVENTS;

        if ($this->len < self::MAX_EVENTS) {
            $this->len++;
        }
    }

    /**
     * @return list<array{type: string, payload: array, time: int}> oldest first
     */
    public function release(): array
    {
        // Re-derive the oldest→newest list from the ring (index contract:
        // callers receive a plain 0..n-1 list, identical in shape and order
        // to the pre-ring array_shift implementation).
        $released = [];
        $start = ($this->head - $this->len + self::MAX_EVENTS) % self::MAX_EVENTS;
        for ($i = 0; $i < $this->len; $i++) {
            $released[] = $this->events[($start + $i) % self::MAX_EVENTS];
        }

        $this->events = [];
        $this->head = 0;
        $this->len = 0;

        return $released;
    }

    public function hasEvents(): bool
    {
        return $this->len > 0;
    }

    public function count(): int
    {
        return $this->len;
    }
}
