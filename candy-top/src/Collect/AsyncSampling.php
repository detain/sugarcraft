<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

use React\Promise\PromiseInterface;

/**
 * A collector whose `sample()` forks base-system children and can do the
 * same work without blocking the loop (S-P02): {@see FreeBsd\ReplayProbe}
 * answers the collector's reads from loop-delivered child output instead
 * of fork-and-wait. The Source layer asks {@see asyncSampling()} before
 * choosing the promise path, so a collector over a fixture or a plain
 * live probe keeps its blocking `sample()` byte for byte.
 */
interface AsyncSampling
{
    /** True only when {@see sampleAsync()} really avoids blocking children. */
    public function asyncSampling(): bool;

    /**
     * What `sample()` returns, delivered on the loop; never rejects.
     *
     * @return PromiseInterface<array{0: object, 1: object}>
     */
    public function sampleAsync(): PromiseInterface;
}
