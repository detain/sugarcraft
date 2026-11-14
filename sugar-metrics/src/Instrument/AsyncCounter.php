<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Instrument;

use SugarCraft\Metrics\Registry;

/**
 * An asynchronous instrument that reports a monotonically
 * increasing sum. The supplied callback is invoked when you
 * call {@see observe()} — the library arms no collection
 * timer of its own, so a periodic tick (or your scrape/mirror
 * loop) is what samples the external value.
 *
 * Use this when the metric value is owned by an external
 * system that is updated independently of the metrics library
 * and only ever rises — JVM garbage collection counts, bytes
 * served, a process' cumulative CPU time. Values that can fall
 * (a DB connection pool size, queue depth) belong on
 * {@see AsyncGauge}.
 *
 * Mirrors opentelemetry.io/api/metrics#AsyncCounter.
 */
final class AsyncCounter
{
    /**
     * @param \Closure(): float $callback Called by each observe() to obtain the sampled value.
     * @param array<string,string>     $tags Static tags attached to this instrument.
     */
    /**
     * House-idiom factory (sugar-metrics-014): forward-to-ctor while the
     * constructor stays public (privatization is the gated MET-PRIVATECTOR).
     */
    public static function new(Registry $registry, string $name, string $help, \Closure $callback, array $tags = []): self
    {
        return new self($registry, $name, $help, $callback, $tags);
    }

    public function __construct(
        private readonly Registry $registry,
        private readonly string $name,
        private readonly string $help,
        private readonly \Closure $callback,
        private readonly array $tags = [],
    ) {}

    /**
     * Sample the callback once and record the returned sum.
     *
     * Per-call tags merge over the instrument's static tags
     * (a same-key per-call value wins), so one instrument can
     * stamp observation-site detail without cloning.
     *
     * @param array<string,string> $tags
     */
    public function observe(array $tags = []): void
    {
        $this->registry->asyncCounter(
            $this->name,
            ($this->callback)(),
            $tags === [] ? $this->tags : array_merge($this->tags, $tags),
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function help(): string
    {
        return $this->help;
    }
}
