<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker;

/**
 * Simple in-memory event store for calendar state changes.
 *
 * An optional clock closure (returns int seconds) makes timestamps
 * deterministic in tests; the default is real wall-clock time().
 */
final class EventStore implements EventStoreInterface
{
    /** @var list<array{type: string, payload: array, time: int}> */
    private array $events = [];

    public function __construct(
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function record(string $type, array $payload = []): void
    {
        $time = $this->clock !== null ? ($this->clock)() : time();
        $this->events[] = ['type' => $type, 'payload' => $payload, 'time' => $time];
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
