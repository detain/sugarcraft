<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source;

/**
 * The snapshots one {@see SourceSet} sample produced, by source name.
 */
final class Samples
{
    /** @param array<string, object> $snapshots */
    public function __construct(
        public readonly array $snapshots,
    ) {
    }

    public function get(string $name): ?object
    {
        return $this->snapshots[$name] ?? null;
    }
}
