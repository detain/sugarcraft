<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Gfx;

use SugarCraft\Top\Source\Source;

/**
 * A Source that plays back fixed snapshots in order and then repeats the
 * last one; counts how often it was sampled.
 */
final class ScriptedSource implements Source
{
    /** @param list<object> $snapshots */
    public function __construct(
        public readonly array $snapshots,
        public readonly int $at = 0,
    ) {
    }

    public function sample(): array
    {
        $snapshot = $this->snapshots[min($this->at, count($this->snapshots) - 1)];

        return [$snapshot, new self($this->snapshots, $this->at + 1)];
    }
}
