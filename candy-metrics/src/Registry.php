<?php

declare(strict_types=1);

namespace CandyCore\Metrics;

/**
 * Application-facing facade. Hand a {@see Backend} in, then call
 * {@see counter()} / {@see gauge()} / {@see histogram()} from
 * application code; the registry forwards each call to the
 * backend.
 *
 * The split is intentional — backend swap is a config concern,
 * call sites stay backend-agnostic. This is the standard
 * Prometheus-client / StatsD-client shape.
 *
 * The registry also offers a {@see time()} helper that returns a
 * closure to call when the timed operation finishes, recording
 * the elapsed seconds as a histogram.
 *
 * `withTags()` returns a new registry whose every emit is
 * pre-tagged — useful for SSH session middleware that wants to
 * stamp every metric with `user`/`client_addr` without threading
 * those tags through every call site.
 */
final class Registry
{
    /** @var array<string,string> */
    private array $defaultTags;

    /**
     * @param array<string,string> $defaultTags
     */
    public function __construct(
        private readonly Backend $backend,
        array $defaultTags = [],
    ) {
        $this->defaultTags = $defaultTags;
    }

    public function counter(string $name, float $value = 1.0, array $tags = []): void
    {
        $this->backend->counter($name, $value, $this->mergeTags($tags));
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        $this->backend->gauge($name, $value, $this->mergeTags($tags));
    }

    public function histogram(string $name, float $value, array $tags = []): void
    {
        $this->backend->histogram($name, $value, $this->mergeTags($tags));
    }

    /**
     * Start a wall-clock timer. Returns a closure — when invoked,
     * it records the elapsed seconds as a histogram under `$name`.
     *
     * ```php
     * $stop = $registry->time('handler.duration');
     * doExpensiveThing();
     * $stop();
     * ```
     *
     * Or capture the closure to record once on success:
     *
     * ```php
     * $stop = $registry->time('handler.duration', ['route' => '/x']);
     * try { ... } finally { $stop(); }
     * ```
     *
     * @param array<string,string> $tags
     * @return callable(): float
     */
    public function time(string $name, array $tags = []): callable
    {
        $start = microtime(true);
        return function () use ($name, $tags, $start): float {
            $elapsed = microtime(true) - $start;
            $this->histogram($name, $elapsed, $tags);
            return $elapsed;
        };
    }

    /**
     * Returns a child registry whose every emit is pre-tagged
     * with `$tags` (merged on top of the existing defaults).
     *
     * @param array<string,string> $tags
     */
    public function withTags(array $tags): self
    {
        return new self($this->backend, array_merge($this->defaultTags, $tags));
    }

    public function backend(): Backend
    {
        return $this->backend;
    }

    /**
     * @param array<string,string> $tags
     * @return array<string,string>
     */
    private function mergeTags(array $tags): array
    {
        return $tags === [] ? $this->defaultTags : array_merge($this->defaultTags, $tags);
    }
}
