<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Instrument;

use SugarCraft\Metrics\Registry;

/**
 * An asynchronous instrument that reports a non-monotonic
 * instantaneous value — observed at collection time by calling
 * the supplied callback.
 *
 * Unlike AsyncCounter, the observed value is not expected to be
 * monotonically increasing; it represents a point-in-time snapshot
 * (e.g., current memory usage, queue depth, temperature).
 *
 * Mirrors opentelemetry.io/api/metrics#AsyncGauge.
 */
final class AsyncGauge
{
    /**
     * @param \Closure(): float $callback Called at collection time to obtain the observed value.
     * @param array<string,string>     $tags Static tags attached to this instrument.
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly string $name,
        private readonly string $help,
        private readonly \Closure $callback,
        private readonly array $tags = [],
    ) {}

    /**
     * Observe the current value by invoking the callback and
     * recording the returned gauge reading.
     */
    public function observe(): void
    {
        $this->registry->asyncGauge($this->name, ($this->callback)(), $this->tags);
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
