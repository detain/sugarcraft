<?php

declare(strict_types=1);

namespace SugarCraft\Calendar;

/**
 * Simple in-memory event store for calendar state changes.
 */
final class EventStore implements EventStoreInterface
{
    /** @var list<array{type: string, payload: array, time: int}> */
    private array $events = [];

    public function record(string $type, array $payload = []): void
    {
        $this->events[] = ['type' => $type, 'payload' => $payload, 'time' => time()];
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
