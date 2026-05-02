<?php

declare(strict_types=1);

namespace CandyCore\Metrics\Backend;

use CandyCore\Metrics\Backend;

/**
 * Fanout to multiple backends. Useful for emitting to the live
 * StatsD/Prometheus pipeline AND keeping a JSON audit trail at
 * the same time, or for combining an in-memory tally with a
 * production sink in tests.
 *
 * Each call delegates to every child backend in order. A failure
 * in one backend propagates as an exception — wrap in a
 * try/catch in the caller if you'd rather log+continue.
 */
final class MultiBackend implements Backend
{
    /** @var list<Backend> */
    private array $children;

    public function __construct(Backend ...$children)
    {
        $this->children = array_values($children);
    }

    public function counter(string $name, float $value, array $tags = []): void
    {
        foreach ($this->children as $b) {
            $b->counter($name, $value, $tags);
        }
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        foreach ($this->children as $b) {
            $b->gauge($name, $value, $tags);
        }
    }

    public function histogram(string $name, float $value, array $tags = []): void
    {
        foreach ($this->children as $b) {
            $b->histogram($name, $value, $tags);
        }
    }
}
