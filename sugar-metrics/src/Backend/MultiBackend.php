<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Backend;

use SugarCraft\Metrics\Lang;
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
 *
 * The rule holds for every operation, including the idempotent
 * remove/clear cleanup: nothing is silently swallowed in either mode.
 *
 * Partial-fanout contract (sugar-metrics-040): in fail-fast mode the
 * loop aborts at the throwing child — children ordered after it are
 * NOT attempted for that call, so one operation can land on some
 * sinks and not others. In continue-on-error mode every child is
 * always attempted and the aggregate failure rethrows only after the
 * full fanout completes.
 */
final class MultiBackend implements Backend
{
    /**
     * Fanout discriminators (sugar-metrics-027): the operation travels
     * through fanout() as one of these strings plus its positional args,
     * never as a freshly allocated per-call closure. Private consts keep
     * the vocabulary inside the class — no public surface, no enum file.
     */
    private const OP_COUNTER = 'counter';
    private const OP_GAUGE = 'gauge';
    private const OP_HISTOGRAM = 'histogram';
    private const OP_UP_DOWN_COUNTER = 'upDownCounter';
    private const OP_ASYNC_COUNTER = 'asyncCounter';
    private const OP_ASYNC_GAUGE = 'asyncGauge';
    private const OP_DESCRIBE = 'describe';
    private const OP_REMOVE = 'remove';
    private const OP_CLEAR = 'clear';
    private const OP_FLUSH = 'flush';

    /** @var list<Backend> */
    private array $children;

    /** If true, catch throwables from children and rethrow after all attempts. */
    private bool $continueOnError;

    /**
     * House-idiom factory (sugar-metrics-014): `MultiBackend::new($a, $b)`.
     * The continue-on-error posture is still flipped with the immutable
     * `withContinueOnError()` — `new()` builds, `with*()` adjusts.
     */
    public static function new(Backend ...$children): self
    {
        return new self(...$children);
    }

    public function __construct(Backend ...$children)
    {
        if ($children === []) {
            // A zero-child fanout swallows every emit silently — worse than
            // a loud failure at the wiring site where the mistake happened.
            throw new \InvalidArgumentException(Lang::t('multibackend.no_children'));
        }
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
        $this->fanout(self::OP_COUNTER, [$name, $value, $tags]);
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        $this->fanout(self::OP_GAUGE, [$name, $value, $tags]);
    }

    public function histogram(string $name, float $value, array $tags = []): void
    {
        $this->fanout(self::OP_HISTOGRAM, [$name, $value, $tags]);
    }

    public function upDownCounter(string $name, float $amount, array $tags = []): void
    {
        $this->fanout(self::OP_UP_DOWN_COUNTER, [$name, $amount, $tags]);
    }

    public function asyncCounter(string $name, float $value, array $tags = []): void
    {
        $this->fanout(self::OP_ASYNC_COUNTER, [$name, $value, $tags]);
    }

    public function asyncGauge(string $name, float $value, array $tags = []): void
    {
        $this->fanout(self::OP_ASYNC_GAUGE, [$name, $value, $tags]);
    }

    public function describe(Descriptor $descriptor): void
    {
        $this->fanout(self::OP_DESCRIBE, [$descriptor]);
    }

    public function remove(string $name, array $tags = []): void
    {
        $this->fanout(self::OP_REMOVE, [$name, $tags]);
    }

    public function clear(): void
    {
        $this->fanout(self::OP_CLEAR);
    }

    public function flush(): void
    {
        $this->fanout(self::OP_FLUSH);
    }

    /**
     * Fan out an operation to all children, with optional error isolation.
     *
     * The operation travels as a discriminator + positional args, never as
     * a per-call closure (sugar-metrics-027): every emit through a fanned
     * out registry used to allocate an arrow closure (with its bound-variable
     * zvals) before the first child saw the call. dispatch() is a match over
     * the discriminator instead, so the hot path allocates nothing that out-
     * lives the argument list. Zero behavior change: every verb byte-
     * delegates the same calls and throws to each child.
     *
     * @param string $op One of the self::OP_* discriminators.
     * @param list<mixed> $args Positional arguments for the child method.
     */
    private function fanout(string $op, array $args = []): void
    {
        if ($this->continueOnError === false) {
            foreach ($this->children as $b) {
                $this->dispatch($b, $op, $args);
            }
            return;
        }
        // Error-isolating mode: try every child, collect failures, rethrow at the end.
        $errors = [];
        foreach ($this->children as $b) {
            try {
                $this->dispatch($b, $op, $args);
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }
        if ($errors !== []) {
            throw new MultiBackendException($errors);
        }
    }

    /**
     * Invoke one child's method named by the discriminator. An unknown
     * discriminator is a programming error inside this class, not a runtime
     * input: no public path can construct one.
     *
     * @param list<mixed> $args
     */
    private function dispatch(Backend $b, string $op, array $args): void
    {
        match ($op) {
            self::OP_COUNTER => $b->counter($args[0], $args[1], $args[2]),
            self::OP_GAUGE => $b->gauge($args[0], $args[1], $args[2]),
            self::OP_HISTOGRAM => $b->histogram($args[0], $args[1], $args[2]),
            self::OP_UP_DOWN_COUNTER => $b->upDownCounter($args[0], $args[1], $args[2]),
            self::OP_ASYNC_COUNTER => $b->asyncCounter($args[0], $args[1], $args[2]),
            self::OP_ASYNC_GAUGE => $b->asyncGauge($args[0], $args[1], $args[2]),
            self::OP_DESCRIBE => $b->describe($args[0]),
            self::OP_REMOVE => $b->remove($args[0], $args[1]),
            self::OP_CLEAR => $b->clear(),
            self::OP_FLUSH => $b->flush(),
            default => throw new \LogicException('unknown fanout operation: ' . $op),
        };
    }
}
