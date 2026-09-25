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

    /** @var list<array{string,RatePerSecond}> alias => single-key rate */
    private array $aliased = [];

    /** @var list<array{string,list<RatePerSecond>}> alias => summed rates */
    private array $sums = [];

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
     * Add a rate under a display alias, decoupling the series name from the
     * status key (e.g. 'select' for Com_select) so dashboard legends read like
     * Workbench's 'select,insert,update,delete,… x/s' labels.
     */
    public function addRateAs(string $alias, string $key): self
    {
        $this->aliased[] = [$alias, new RatePerSecond($key)];
        return $this;
    }

    /**
     * Add one summed series over several keys (e.g. 'create' folding every
     * Com_create_* command into a single DDL line, as Workbench does).
     * Missing keys contribute 0.0 (RatePerSecond's contract), so a version
     * lacking one member command simply plots the rest.
     */
    public function addRateSum(string $alias, string ...$keys): self
    {
        $rates = [];
        foreach ($keys as $key) {
            $rates[] = new RatePerSecond($key);
        }
        $this->sums[] = [$alias, $rates];
        return $this;
    }

    /**
     * Compute all rates from a current and previous snapshot.
     *
     * Series order is the declaration order across groups: bare rates, then
     * aliased rates, then summed aliases, then tuple rates — MultiSeriesCell
     * assigns palette colors positionally, so catalogs rely on this.
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
        foreach ($this->aliased as [$alias, $rate]) {
            $out[$alias] = $rate->compute($current, $previous, $elapsed);
        }
        foreach ($this->sums as [$alias, $rates]) {
            $sum = 0.0;
            foreach ($rates as $rate) {
                $sum += $rate->compute($current, $previous, $elapsed);
            }
            $out[$alias] = $sum;
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
        foreach ($this->aliased as [, $rate]) {
            $rate->updateLast($snapshot);
        }
        foreach ($this->sums as [, $rates]) {
            foreach ($rates as $rate) {
                $rate->updateLast($snapshot);
            }
        }
        foreach ($this->tupleRates as $tupleRate) {
            $tupleRate->updateLast($snapshot);
        }
    }
}
