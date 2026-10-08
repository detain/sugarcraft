<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source;

/**
 * Several named sources sampled together — a panel that needs more than one
 * collector (btop's cpu box reads cpu, freq, temp and battery each tick).
 * The snapshot is a {@see Samples} keyed like the set.
 */
final class SourceSet implements Source
{
    /** @param array<string, Source> $sources */
    private function __construct(
        private readonly array $sources,
    ) {
    }

    /** @param array<string, Source> $sources */
    public static function of(array $sources): self
    {
        return new self($sources);
    }

    public function sample(): array
    {
        $snapshots = [];
        $next = [];
        foreach ($this->sources as $name => $source) {
            [$snapshots[$name], $next[$name]] = $source->sample();
        }

        return [new Samples($snapshots), new self($next)];
    }
}
