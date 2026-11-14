<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Instrument;

use SugarCraft\Metrics\Registry;

/**
 * A monotonic counter — a value that only ever goes up (requests
 * served, jobs completed). Symmetry for the most-used kind
 * (sugar-metrics-021): hold it once with its static tags and call
 * inc() everywhere instead of restating the tag array per call site.
 *
 * Mirrors opentelemetry.io/api/metrics#Counter.
 */
final class Counter
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
     * @param array<string,string> $tags Static tags applied on every inc() call;
     *                                   per-call tags win on key conflicts.
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly string $name,
        private readonly string $help = '',
        private readonly array $tags = [],
    ) {}

    /**
     * Add `$by` to the counter. The verb-level monotonic guard
     * (Registry::counter) refuses negative amounts at the door; the
     * instrument does not fork a second check.
     *
     * The instrument's static tags are emitted on every call; the
     * `$tags` argument adds (or overrides) per-call tags on top.
     *
     * @param array<string,string> $tags
     */
    public function inc(float $by = 1.0, array $tags = []): void
    {
        $merged = $this->tags === [] ? $tags : \array_merge($this->tags, $tags);
        $this->registry->counter($this->name, $by, $merged);
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
