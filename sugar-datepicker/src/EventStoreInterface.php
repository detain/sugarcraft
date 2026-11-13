<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker;

/**
 * Interface for event stores used in DI-friendly event sourcing.
 */
interface EventStoreInterface
{
    /**
     * @param PickerEvent::* $type event kind from the {@see PickerEvent}
     *                             vocabulary; runtime stays permissive — the
     *                             constants are documentation and typo
     *                             protection, not validation
     */
    public function record(string $type, array $payload = []): void;

    /**
     * @return list<array{type: string, payload: array, time: int}>
     */
    public function release(): array;

    public function hasEvents(): bool;

    /** Number of events currently held (before the next drain). */
    public function count(): int;
}
