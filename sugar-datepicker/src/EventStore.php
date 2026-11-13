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

    /** @var list<array{type: string, payload: array, time: int}> */
    private array $events = [];

    public function __construct(
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * @param PickerEvent::* $type event kind (see {@see PickerEvent}); permissive
     *                             at runtime by design — constants are
     *                             documentation + typo protection
     */
    public function record(string $type, array $payload = []): void
    {
        $time = $this->clock !== null ? ($this->clock)() : time();
        $this->events[] = ['type' => $type, 'payload' => $payload, 'time' => $time];

        if (\count($this->events) > self::MAX_EVENTS) {
            \array_shift($this->events);
        }
    }

    /**
     * @return list<array{type: string, payload: array, time: int}>
     */
    public function release(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    public function hasEvents(): bool
    {
        return count($this->events) > 0;
    }

    public function count(): int
    {
        return count($this->events);
    }
}
