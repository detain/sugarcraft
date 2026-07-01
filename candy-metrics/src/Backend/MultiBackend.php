<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Backend;

use SugarCraft\Metrics\Backend;
use SugarCraft\Metrics\Descriptor;

/**
 * Fanout to multiple backends. Useful for emitting to the live
 * StatsD/Prometheus pipeline AND keeping a JSON audit trail at
 * the same time, or for combining an in-memory tally with a
 * production sink in tests.
 *
 * Each call delegates to every child backend in order. By default,
 * a failure in one child propagates as an exception (fail-fast).
 * Use {@see withContinueOnError()} to switch to an error-isolating
 * mode where every child receives the emit even if others throw;
 * failures are collected and rethrown as an aggregated exception
 * after all children have been attempted.
 */
final class MultiBackend implements Backend
{
    /** @var list<Backend> */
    private array $children;

    /** If true, catch throwables from children and rethrow after all attempts. */
    private bool $continueOnError;

    public function __construct(Backend ...$children)
    {
        $this->children = array_values($children);
        $this->continueOnError = false;
    }

    /**
     * Factory: create a new MultiBackend with error isolation enabled.
     * When enabled, every child receives the emit call even if a prior
     * child throws; all failures are collected and rethrown as one
     * RuntimeException after the fanout completes.
     *
     * @param Backend ...$children The backends to fan out to.
     */
    public static function withContinueOnError(Backend ...$children): self
    {
        $inst = new self(...$children);
        $inst->continueOnError = true;
        return $inst;
    }

    public function counter(string $name, float $value, array $tags = []): void
    {
        $this->fanout(fn(Backend $b) => $b->counter($name, $value, $tags));
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        $this->fanout(fn(Backend $b) => $b->gauge($name, $value, $tags));
    }

    public function histogram(string $name, float $value, array $tags = []): void
    {
        $this->fanout(fn(Backend $b) => $b->histogram($name, $value, $tags));
    }

    public function upDownCounter(string $name, float $amount, array $tags = []): void
    {
        $this->fanout(fn(Backend $b) => $b->upDownCounter($name, $amount, $tags));
    }

    public function asyncCounter(string $name, float $value, array $tags = []): void
    {
        $this->fanout(fn(Backend $b) => $b->asyncCounter($name, $value, $tags));
    }

    public function asyncGauge(string $name, float $value, array $tags = []): void
    {
        $this->fanout(fn(Backend $b) => $b->asyncGauge($name, $value, $tags));
    }

    public function describe(Descriptor $descriptor): void
    {
        $this->fanout(fn(Backend $b) => $b->describe($descriptor));
    }

    public function flush(): void
    {
        $this->fanout(fn(Backend $b) => $b->flush());
    }

    /**
     * Fan out an operation to all children, with optional error isolation.
     *
     * @param callable(Backend): void $op
     */
    private function fanout(callable $op): void
    {
        if (!$this->continueOnError) {
            foreach ($this->children as $b) {
                $op($b);
            }
            return;
        }
        // Error-isolating mode: try every child, collect failures, rethrow at the end.
        $errors = [];
        foreach ($this->children as $b) {
            try {
                $op($b);
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException(
                'MultiBackend: ' . count($errors) . ' child backend(s) failed. First: ' . $errors[0]->getMessage(),
                previous: $errors[0]
            );
        }
    }
}
