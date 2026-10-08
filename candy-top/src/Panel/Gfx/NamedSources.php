<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gfx;

use SugarCraft\Top\Source\Samples;
use SugarCraft\Top\Source\Source;

/**
 * A {@see Source} over several named sources that a panel can still take
 * apart afterwards — {@see \SugarCraft\Top\Source\SourceSet} with
 * accessors. The cpu panel retunes its freq source at collect time
 * (show_core_freq) and hands its battery source to the P-D badge, so it
 * needs each member back from the `next` of a sample.
 */
final class NamedSources implements Source
{
    /** @param array<string, Source> $sources */
    private function __construct(
        private readonly array $sources,
    ) {
    }

    /** @param array<string, ?Source> $sources null members are left out */
    public static function of(array $sources): self
    {
        return new self(array_filter($sources, static fn (?Source $s): bool => $s !== null));
    }

    public function get(string $name): ?Source
    {
        return $this->sources[$name] ?? null;
    }

    public function with(string $name, ?Source $source): self
    {
        $sources = $this->sources;
        if ($source === null) {
            unset($sources[$name]);
        } else {
            $sources[$name] = $source;
        }

        return new self($sources);
    }

    /** Only the named members (sampling a subset, e.g. no gpu while show_gpu_info is Off). */
    public function only(array $names): self
    {
        return new self(array_intersect_key($this->sources, array_flip($names)));
    }

    /** This set with every member of `$other` replacing its namesake. */
    public function merge(self $other): self
    {
        return new self(array_replace($this->sources, $other->sources));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->sources);
    }

    /** @return array{0: Samples, 1: self} */
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
