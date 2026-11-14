<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Instrument;

use SugarCraft\Metrics\Registry;

/**
 * An asynchronous instrument that reports a non-monotonic
 * instantaneous value. The supplied callback is invoked when
 * you call {@see observe()} — the library arms no collection
 * timer of its own, so a periodic tick (or your scrape/mirror
 * loop) is what samples the external value.
 *
 * Unlike AsyncCounter, the observed value is not expected to be
 * monotonically increasing; it represents a point-in-time snapshot
 * (e.g., current memory usage, queue depth, DB connection pool size,
 * temperature).
 *
 * Mirrors opentelemetry.io/api/metrics#AsyncGauge.
 */
final class AsyncGauge
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
     * Sample the callback once and record the returned gauge reading.
     *
     * Per-call tags merge over the instrument's static tags
     * (a same-key per-call value wins), so one instrument can
     * stamp observation-site detail without cloning.
     *
     * @param array<string,string> $tags
     */
    public function observe(array $tags = []): void
    {
        $this->registry->asyncGauge(
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
