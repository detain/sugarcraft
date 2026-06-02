<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

use SugarCraft\Charts\LineChart\Streamline;
use SugarCraft\Query\Admin\StatusSnapshot;

/**
 * Renders a timeline (time-series) widget as a sparkline using Streamline.
 *
 * Maintains a sliding window of rate values and renders them as a braille-style
 * sparkline with auto-scale "nice ceiling" (rounds up to the next round number).
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard DBTimeLineGraph
 */
final class TimeSeriesCell
{
    /** @var Streamline */
    private Streamline $chart;

    private int $windowSize;

    /** @var list<float> */
    private array $values = [];

    /** @var list<float> */
    private array $timestamps = [];

    private float $ceiling = 100.0;

    private float $minSeen = 0.0;

    private float $maxSeen = 0.0;

    public function __construct(
        private readonly Widget $widget,
        int $windowSize = 160,
        int $width = 40,
        int $height = 8,
    ) {
        $this->windowSize = $windowSize;
        $this->chart = Streamline::new($width, $height);
    }

    /**
     * Ingest a new data point computed from the current and previous snapshots.
     *
     * @param array<string, string> $current Current status variables
     * @param array<string, string> $previous Previous status variables
     * @param float $elapsed Seconds elapsed since previous snapshot
     * @return $this
     */
    public function ingest(array $current, array $previous, float $elapsed): self
    {
        if ($elapsed <= 0) {
            return $this;
        }

        $value = $this->widget->compute($current, $previous, $elapsed);

        if (is_array($value)) {
            $value = array_sum($value);
        }

        $value = (float) $value;

        if ($value <= 0) {
            return $this;
        }

        $now = microtime(true);

        $this->values[] = $value;
        $this->timestamps[] = $now;

        while (count($this->values) > $this->windowSize) {
            array_shift($this->values);
            array_shift($this->timestamps);
        }

        $this->maxSeen = max($this->maxSeen, $value);
        $this->ceiling = $this->niceCeiling($this->maxSeen);
        $this->minSeen = count($this->values) > 0 ? min($this->values) : 0.0;

        return $this;
    }

    /**
     * Ingest from a StatusSnapshot and the previous one.
     */
    public function ingestFromSnapshot(
        \SugarCraft\Query\Admin\StatusSnapshot $current,
        \SugarCraft\Query\Admin\StatusSnapshot $previous,
    ): self {
        if ($previous->ts <= 0) {
            return $this;
        }
        return $this->ingest(
            $current->variables,
            $previous->variables,
            $current->elapsedSince($previous),
        );
    }

    /**
     * Reset all history (call on server restart).
     */
    public function reset(): self
    {
        $this->values = [];
        $this->timestamps = [];
        $this->minSeen = 0.0;
        $this->maxSeen = 0.0;
        $this->ceiling = 100.0;
        $this->chart = $this->chart->clear();
        return $this;
    }

    /**
     * Get the current ceiling value (auto-scaled "nice ceiling").
     */
    public function ceiling(): float
    {
        return $this->ceiling;
    }

    /**
     * Get the max seen value.
     */
    public function maxSeen(): float
    {
        return $this->maxSeen;
    }

    /**
     * Check if the cell has any data.
     */
    public function isEmpty(): bool
    {
        return count($this->values) === 0;
    }

    /**
     * Get the number of data points in the window.
     */
    public function count(): int
    {
        return count($this->values);
    }

    /**
     * Render the timeline as a string.
     */
    public function view(): string
    {
        if ($this->isEmpty()) {
            return (string) $this->chart;
        }

        $chart = $this->chart->clear();

        foreach ($this->values as $v) {
            $chart = $chart->push($v);
        }

        return (string) $chart->withMin($this->minSeen)->withMax($this->ceiling);
    }

    /**
     * Render as string (magic method).
     */
    public function __toString(): string
    {
        return $this->view();
    }

    /**
     * Compute a "nice ceiling" for a given max value.
     *
     * Takes the max as a decimal string, increments the first digit,
     * zeros the rest (e.g. 4500 → 5000), with a floor of 100.
     *
     * @see Mirrors mysql-workbench/charting.py DBTimeLineGraph.auto_scale ceiling logic
     */
    private function niceCeiling(float $max): float
    {
        if ($max <= 0) {
            return 100.0;
        }

        $s = (string) (int) $max;
        $firstDigit = (int) $s[0] + 1;
        if ($firstDigit > 9) {
            $firstDigit = 10;
        }
        $scale = (int) ($firstDigit . str_repeat('0', strlen($s) - 1));

        return max($scale, 100);
    }

    /**
     * Get the associated widget.
     */
    public function widget(): Widget
    {
        return $this->widget;
    }
}
