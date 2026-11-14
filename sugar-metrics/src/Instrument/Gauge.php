<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Instrument;

use SugarCraft\Metrics\Registry;

/**
 * A gauge — a value that can go up and down freely (queue depth,
 * temperature, memory in use). Symmetry for the most-used kinds
 * (sugar-metrics-021): hold it once with its static tags and call
 * set() everywhere instead of restating the tag array per call site.
 *
 * Mirrors opentelemetry.io/api/metrics#Gauge.
 */
final class Gauge
{
    /**
     * House-idiom factory (sugar-metrics-014): forward-to-ctor while the
     * constructor stays public (privatization is the gated MET-PRIVATECTOR).
     */
    public static function new(Registry $registry, string $name, string $help = '', array $tags = []): self
    {
        return new self($registry, $name, $help, $tags);
    }

    /**
     * @param array<string,string> $tags Static tags applied on every set() call;
     *                                   per-call tags win on key conflicts.
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly string $name,
        private readonly string $help = '',
        private readonly array $tags = [],
    ) {}

    /**
     * Set the gauge to `$value` (last write wins). Negative values are
     * legitimate for gauges — no monotonic guard applies.
     *
     * The instrument's static tags are emitted on every call; the
     * `$tags` argument adds (or overrides) per-call tags on top.
     *
     * @param array<string,string> $tags
     */
    public function set(float $value, array $tags = []): void
    {
        $merged = $this->tags === [] ? $tags : \array_merge($this->tags, $tags);
        $this->registry->gauge($this->name, $value, $merged);
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
