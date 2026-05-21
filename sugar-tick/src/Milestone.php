<?php

declare(strict_types=1);

namespace SugarCraft\Tick;

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

    public static function fromArray(array $row): self
    {
        return new self(
            name: (string) ($row['name'] ?? ''),
            time: (int) ($row['time'] ?? 0),
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
