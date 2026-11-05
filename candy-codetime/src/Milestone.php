<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime;

/**
 * A named time-point in the activity timeline — e.g. "shipped v1.0 here".
 */
final readonly class Milestone
{
    public function __construct(
        public string $name,
        public int $time,
        public string $description = '',
    ) {}

    /**
     * @param array<string,mixed> $row  One sqlite milestone row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            name: (string) ($row['name'] ?? ''),
            // Same coercion law as Heartbeat::fromArray(): a hand-edited row
            // must not carry a negative or far-future time into the listing,
            // where date() would render an absurd year.
            time:     min(Heartbeat::MAX_TIME_SECONDS, max(0, (int)($row['time'] ?? 0))),
            description: (string) ($row['description'] ?? ''),
        );
    }

    /**
     * @return array{name: string, time: int, description: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'time' => $this->time,
            'description' => $this->description,
        ];
    }
}
