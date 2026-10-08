<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source;

/**
 * Adapts any `Collect\*` collector — they share the `sample(): [snapshot,
 * next]` shape but no interface — to {@see Source}.
 */
final class CollectorSource implements Source
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

    public function collector(): object
    {
        return $this->collector;
    }
}
