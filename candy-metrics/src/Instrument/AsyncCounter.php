<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Instrument;

use SugarCraft\Metrics\Registry;

/**
 * An asynchronous instrument that reports a monotonically
 * increasing sum — observed at collection time by calling
 * the supplied callback.
 *
 * Use this when the metric value is owned by an external
 * system (e.g., DB connection pool size, JVM garbage collection
 * counts) that is updated independently of the metrics library.
 *
 * Mirrors opentelemetry.io/api/metrics#AsyncCounter.
 */
final class AsyncCounter
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
     * recording the returned sum.
     */
    public function observe(): void
    {
        $this->registry->asyncCounter($this->name, ($this->callback)(), $this->tags);
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
