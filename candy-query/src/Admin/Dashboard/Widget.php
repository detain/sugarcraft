<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

/**
 * Immutable dashboard widget descriptor.
 *
 * Holds the configuration for a single dashboard cell (timeline, counter,
 * round meter, or level meter). The Calc object drives the value computation;
 * rendering is handled by the appropriate Cell class in later steps.
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard Widget
 */
final readonly class Widget
{
    /**
     * @param string $caption Display label for the widget
     * @param string $kind One of: timeline, counter, round, level
     * @param object $calc Calc instance (RatePerSecond|RawValue|TupleRatePerSecond|MakeTuple)
     * @param string $format sprintf format string for display (e.g. "%s/s", "%.1f%%")
     * @param array{r:int,g:int,b:int} $color RGB color for the widget
     * @param string $tooltip Optional tooltip template string
     * @param array<string,string>|null $serverVarsKeys Map of format tokens to SHOW VARIABLES keys (for max_connections etc.)
     */
    public function __construct(
        public string $caption,
        public string $kind,
        public object $calc,
        public string $format,
        public array $color,
        public string $tooltip = '',
        public ?array $serverVarsKeys = null,
    ) {}

    /**
     * Compute the widget's current value.
     *
     * @param array<string, string> $current Current status variables snapshot
     * @param array<string, string> $previous Previous status variables snapshot
     * @param float $elapsed Seconds elapsed since previous snapshot
     * @return float|array<string,float>|string Returns float for rate calcs, array for tuple calcs, string for raw status var
     */
    public function compute(array $current, array $previous, float $elapsed): float|array|string
    {
        return $this->calc->compute($current, $previous, $elapsed);
    }

    /**
     * Check if this widget's calc is a tuple (multi-series) type.
     */
    public function isTuple(): bool
    {
        return $this->calc instanceof \SugarCraft\Query\Admin\Calc\TupleRatePerSecond
            || $this->calc instanceof \SugarCraft\Query\Admin\Calc\MakeTuple;
    }

    /**
     * Format a value using the widget's format string.
     *
     * @param float|array<string,float> $value
     * @return string
     */
    public function formatValue(float|array $value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = sprintf($this->format, $v, $k);
            }
            return implode(' ', $parts);
        }
        return sprintf($this->format, $value);
    }
}
