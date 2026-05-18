<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Weather;

/**
 * Readonly DTO holding a single weather snapshot.
 *
 * Mirrors the lattice weather data model. Used for both live-fetched
 * and cached weather data.
 */
final class WeatherSnapshot
{
    public function __construct(
        public readonly float $tempC,
        public readonly string $condition,
        public readonly string $location,
        public readonly \DateTimeImmutable $fetchedAt,
    ) {}

    /**
     * Deserialize from cache JSON array.
     *
     * @param array{tempC: float, condition: string, location: string, fetchedAt: string}
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tempC: (float) $data['tempC'],
            condition: (string) $data['condition'],
            location: (string) $data['location'],
            fetchedAt: new \DateTimeImmutable($data['fetchedAt']),
        );
    }

    /**
     * Serialize for cache JSON.
     *
     * @return array{tempC: float, condition: string, location: string, fetchedAt: string}
     */
    public function toArray(): array
    {
        return [
            'tempC' => $this->tempC,
            'condition' => $this->condition,
            'location' => $this->location,
            'fetchedAt' => $this->fetchedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
