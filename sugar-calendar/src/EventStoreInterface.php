<?php

declare(strict_types=1);

namespace SugarCraft\Calendar;

/**
 * Interface for event stores used in DI-friendly event sourcing.
 */
interface EventStoreInterface
{
    public function record(string $type, array $payload = []): void;

    /**
     * @return list<array{type: string, payload: array, time: int}>
     */
    public function release(): array;

    public function hasEvents(): bool;
}
