<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Instrument;

use SugarCraft\Metrics\Registry;

/**
 * A histogram — a distribution of observed values (latencies, body
 * sizes) recorded into buckets. Symmetry for the most-used kinds
 * (sugar-metrics-021): hold it once with its static tags and call
 * observe() everywhere instead of restating the tag array per call site.
 *
 * Mirrors opentelemetry.io/api/metrics#Histogram.
 */
final class Histogram
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
     * @param array<string,string> $tags Static tags applied on every observe()
     *                                   call; per-call tags win on key conflicts.
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly string $name,
        private readonly string $help = '',
        private readonly array $tags = [],
    ) {}

    /**
     * Record one observation. The verb-level negative guard
     * (Registry::histogram) refuses samples below every bucket
     * boundary; the instrument does not fork a second check.
     *
     * The instrument's static tags are emitted on every call; the
     * `$tags` argument adds (or overrides) per-call tags on top.
     *
     * @param array<string,string> $tags
     */
    public function observe(float $value, array $tags = []): void
    {
        $merged = $this->tags === [] ? $tags : \array_merge($this->tags, $tags);
        $this->registry->histogram($this->name, $value, $merged);
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
