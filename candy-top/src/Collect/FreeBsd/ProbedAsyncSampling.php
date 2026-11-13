<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

use React\Promise\PromiseInterface;

/**
 * {@see \SugarCraft\Top\Collect\AsyncSampling} for a FreeBSD collector that
 * keeps its {@see Probe} in `$this->probe` and whose `sample()` is a pure
 * function of what the probe answers (every collector here is immutable:
 * `sample()` returns the next collector instead of mutating).
 */
trait ProbedAsyncSampling
{
    public function asyncSampling(): bool
    {
        return $this->probe instanceof ReplayProbe;
    }

    public function sampleAsync(): PromiseInterface
    {
        return ReplayProbe::pump($this->probe, fn (): array => $this->sample());
    }
}
