<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source;

use React\Promise\PromiseInterface;
use SugarCraft\Top\Collect\AsyncSampling;

use function React\Promise\resolve;

/**
 * Adapts any `Collect\*` collector — they share the `sample(): [snapshot,
 * next]` shape but no interface — to {@see Source}.
 */
final class CollectorSource implements AsyncSource
{
    private function __construct(
        private readonly object $collector,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when `$collector` has no sample() method
     */
    public static function of(object $collector): self
    {
        if (!method_exists($collector, 'sample')) {
            throw new \InvalidArgumentException(sprintf('%s has no sample() method', $collector::class));
        }

        return new self($collector);
    }

    public function sample(): array
    {
        /** @var array{0: object, 1: object} $result */
        $result = $this->collector->sample();

        return [$result[0], new self($result[1])];
    }

    /** True when the collector can sample without blocking the loop ({@see AsyncSampling}). */
    public function isAsync(): bool
    {
        return $this->collector instanceof AsyncSampling && $this->collector->asyncSampling();
    }

    /**
     * {@see sample()} on the loop: promise of the same `[snapshot, next]`.
     * A collector that is not async answers with its blocking sample.
     *
     * @return PromiseInterface<array{0: object, 1: Source}>
     */
    public function sampleAsync(): PromiseInterface
    {
        if (!$this->isAsync()) {
            return resolve($this->sample());
        }

        return $this->collector->sampleAsync()->then(static fn (array $r): array => [$r[0], new self($r[1])]);
    }

    public function collector(): object
    {
        return $this->collector;
    }
}
