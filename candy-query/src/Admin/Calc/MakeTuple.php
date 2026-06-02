<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Calc;

/**
 * Factory for creating tuple rate computations.
 *
 * Provides a fluent interface for building multi-key rate computations
 * over status variable snapshots.
 */
final class MakeTuple
{
    /** @var list<RatePerSecond> */
    private array $rates = [];

    /** @var list<TupleRatePerSecond> */
    private array $tupleRates = [];

    public function __construct(
        private readonly string $separator = ',',
    ) {}

    /**
     * Add a simple rate computation.
     */
    public function addRate(string $key): self
    {
        $this->rates[] = new RatePerSecond($key);
        return $this;
    }

    /**
     * Add a tuple rate computation.
     */
    public function addTupleRate(string $key): self
    {
        $this->tupleRates[] = new TupleRatePerSecond($key, $this->separator);
        return $this;
    }

    /**
     * Compute all rates from a current and previous snapshot.
     *
     * @param array<string, string> $current
     * @param array<string, string> $previous
     * @param float $elapsed
     * @return array<string, float>
     */
    public function compute(array $current, array $previous, float $elapsed): array
    {
        $out = [];
        foreach ($this->rates as $rate) {
            $out[$rate->key] = $rate->compute($current, $previous, $elapsed);
        }
        foreach ($this->tupleRates as $tupleRate) {
            foreach ($tupleRate->compute($current, $previous, $elapsed) as $k => $v) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Update internal state from a snapshot.
     *
     * @param array<string, string> $snapshot
     */
    public function updateLast(array $snapshot): void
    {
        foreach ($this->rates as $rate) {
            $rate->updateLast($snapshot);
        }
        foreach ($this->tupleRates as $tupleRate) {
            $tupleRate->updateLast($snapshot);
        }
    }
}
