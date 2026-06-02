<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

use SugarCraft\Query\Admin\Format;

/**
 * Renders a counter widget with K/M/G scaling.
 *
 * Used for widgets of kind "counter" (timeline companion counters,
 * and standalone counter widgets like SELECT/s, INSERT/s, etc.).
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard DBSimpleCounter
 */
final class CounterCell
{
    private float $lastValue = 0.0;

    private bool $hasValue = false;

    public function __construct(
        private readonly Widget $widget,
        private readonly int $decimals = 1,
    ) {}

    /**
     * Ingest a new value from the widget's calc.
     *
     * @param array<string, string> $current
     * @param array<string, string> $previous
     * @param float $elapsed
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

        if ($value < 0) {
            return $this;
        }

        $this->lastValue = $value;
        $this->hasValue = true;

        return $this;
    }

    /**
     * Ingest from status snapshots.
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
     * Reset the counter (call on server restart).
     */
    public function reset(): self
    {
        $this->lastValue = 0.0;
        $this->hasValue = false;
        return $this;
    }

    /**
     * Check if the counter has a value.
     */
    public function hasValue(): bool
    {
        return $this->hasValue;
    }

    /**
     * Get the last ingested value.
     */
    public function lastValue(): float
    {
        return $this->lastValue;
    }

    /**
     * Get the raw formatted value using the widget's format.
     */
    public function rawFormatted(): string
    {
        if (!$this->hasValue) {
            return '';
        }
        return sprintf($this->widget->format, $this->lastValue);
    }

    /**
     * Format the value with K/M/G scaling applied.
     *
     * @param string $unit Optional unit suffix (e.g. "B/s", "s")
     */
    public function scaledFormatted(string $unit = ''): string
    {
        if (!$this->hasValue) {
            return '';
        }

        $scaled = Format::scaleValue($this->lastValue, $this->decimals);

        if ($unit !== '') {
            return $scaled . ' ' . $unit;
        }

        return $scaled;
    }

    /**
     * Render the counter as a string (alias for scaledFormatted).
     */
    public function view(): string
    {
        return $this->scaledFormatted();
    }

    /**
     * Render as string.
     */
    public function __toString(): string
    {
        return $this->view();
    }

    /**
     * Get the associated widget.
     */
    public function widget(): Widget
    {
        return $this->widget;
    }
}
