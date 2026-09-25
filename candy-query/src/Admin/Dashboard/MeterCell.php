<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

use SugarCraft\Dash\Plot\Chart\Donut;
use SugarCraft\Dash\Plot\Chart\Gauge;
use SugarCraft\Dash\Plot\Chart\Meter;
use SugarCraft\Dash\Foundation\Color;

/**
 * Renders round and level meter widgets using sugar-dash chart components.
 *
 * - "round" kind: uses Donut (Workbench's DBRoundMeter is a ring gauge: the
 *   filled arc is the used share, the hole carries the percentage readout)
 * - "level" kind: uses Gauge (horizontal) for usage vs max metrics
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard DBRoundMeter, DBLevelMeter
 */
final class MeterCell
{
    private float $ratio = 0.0;

    private bool $hasValue = false;

    private float $value = 0.0;

    private float $max = 0.0;

    public function __construct(
        private readonly Widget $widget,
        private readonly ?float $maxOverride = null,
    ) {}

    /**
     * Ingest a new value, computing ratio if max is available.
     *
     * @param array<string, string> $current
     * @param array<string, string> $previous
     * @param float $elapsed
     * @param array<string, string>|null $serverVars Optional server variables for max_connections lookup
     * @return $this
     */
    public function ingest(array $current, array $previous, float $elapsed, ?array $serverVars = null): self
    {
        if ($elapsed <= 0) {
            return $this;
        }

        $value = $this->widget->compute($current, $previous, $elapsed);

        if (is_array($value)) {
            $value = array_sum($value);
        }

        $this->value = (float) $value;
        $this->max = $this->resolveMax($current, $serverVars);

        if ($this->max > 0) {
            $this->ratio = min(1.0, $this->value / $this->max);
        } elseif ($this->widget->kind === WidgetRegistry::KIND_ROUND) {
            // Round widgets (Table Open Cache, Buffer Pool Usage, PG Cache Hit
            // Rate) compute a 0-100 PERCENT directly and carry no serverVars
            // max, so value/100 is the ring fill. Without this the old code
            // fell to ratio 0 and the gauge rendered empty forever.
            $this->ratio = max(0.0, min(1.0, $this->value / 100.0));
            $this->max = 100.0;
        } else {
            $this->ratio = 0.0;
        }

        $this->hasValue = true;

        return $this;
    }

    /**
     * Ingest from status snapshots with server variables.
     */
    public function ingestFromSnapshot(
        \SugarCraft\Query\Admin\StatusSnapshot $current,
        \SugarCraft\Query\Admin\StatusSnapshot $previous,
        ?array $serverVars = null,
    ): self {
        if ($previous->ts <= 0) {
            return $this;
        }
        return $this->ingest(
            $current->variables,
            $previous->variables,
            $current->elapsedSince($previous),
            $serverVars,
        );
    }

    /**
     * Set the ratio directly (for level meters that get ratio from a different source).
     */
    public function withRatio(float $ratio, ?float $value = null, ?float $max = null): self
    {
        $clone = clone $this;
        $clone->ratio = max(0.0, min(1.0, $ratio));
        $clone->hasValue = true;
        if ($value !== null) {
            $clone->value = $value;
        }
        if ($max !== null) {
            $clone->max = $max;
        }
        return $clone;
    }

    /**
     * Reset the meter (call on server restart).
     */
    public function reset(): self
    {
        $this->ratio = 0.0;
        $this->hasValue = false;
        $this->value = 0.0;
        $this->max = 0.0;
        return $this;
    }

    /**
     * Check if the meter has a value.
     */
    public function hasValue(): bool
    {
        return $this->hasValue;
    }

    /**
     * Get the current ratio (0.0 to 1.0).
     */
    public function ratio(): float
    {
        return $this->ratio;
    }

    /**
     * Get the percentage (0 to 100).
     */
    public function percentage(): int
    {
        return (int) round($this->ratio * 100);
    }

    /**
     * Get the current raw value.
     */
    public function value(): float
    {
        return $this->value;
    }

    /**
     * Get the resolved maximum.
     */
    public function max(): float
    {
        return $this->max;
    }

    /**
     * Render as a round meter (donut ring with the percentage in the hole).
     *
     * Workbench's DBRoundMeter is exactly this shape: an annulus whose filled
     * sweep is the used share and whose center prints the numeric readout, so
     * sugar-dash Donut replaces the earlier GaugeCircle here. Two segments
     * (used vs free) keep the ring total constant at 100 so Donut never hits
     * its empty-render path, and the free segment carries a muted grey so the
     * fill reads as progress against a visible track.
     *
     * Size 14 is the smallest ring whose hole fits a four-character readout
     * ("100%"): Donut truncates center text to the hole span, and at the
     * former size 12 the live page clipped "85%" to "85". The center always
     * prints %.0f%% because KIND_ROUND widgets are percent metrics by
     * contract (hit-rate / usage), so the widget's own %llf-format precision
     * would not survive the hole anyway.
     */
    public function viewRound(int $size = 14): string
    {
        $color = $this->widget->color;
        $used = max(0.0, min(100.0, $this->ratio * 100.0));

        $donut = new Donut(
            [
                ['label' => 'used', 'value' => $used, 'color' => Color::rgb($color['r'], $color['g'], $color['b'])],
                ['label' => 'free', 'value' => 100.0 - $used, 'color' => Color::rgb(107, 114, 128)],
            ],
            $size,
        );

        if ($this->hasValue) {
            $donut = $donut->withCenterValue(sprintf('%.0f%%', $this->value));
        }

        return $donut->render();
    }

    /**
     * Render as a level/horizontal meter (Gauge).
     */
    public function viewLevel(int $width = 20): string
    {
        if (!$this->hasValue) {
            return Gauge::new(0.0)->withWidth($width)->render();
        }

        $color = $this->widget->color;
        $gauge = Gauge::new($this->ratio)
            ->withWidth($width)
            ->withFilledColor(Color::rgb($color['r'], $color['g'], $color['b']));

        $gaugeStr = $gauge->render();

        // Render value/max readout using the widget's format string.
        // Connections level meter has format '%d / %d'.
        $format = $this->widget->format;
        if ($format !== '' && $format !== '%s') {
            $readout = sprintf($format, (int) $this->value, (int) $this->max);
            return $gaugeStr . ' ' . $readout;
        }

        return $gaugeStr;
    }

    /**
     * Render as a vertical meter (Meter).
     */
    public function viewMeter(int $height = 12): string
    {
        if (!$this->hasValue) {
            return Meter::new(0.0)->withHeight($height)->render();
        }

        $color = $this->widget->color;
        $meter = Meter::new($this->ratio)
            ->withHeight($height)
            ->withMeterColor(Color::rgb($color['r'], $color['g'], $color['b']));

        return $meter->render();
    }

    /**
     * Render using the appropriate method based on widget kind.
     */
    public function view(): string
    {
        return match ($this->widget->kind) {
            WidgetRegistry::KIND_ROUND => $this->viewRound(),
            WidgetRegistry::KIND_LEVEL => $this->viewLevel(),
            default => $this->viewRound(),
        };
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

    /**
     * Resolve the maximum value for ratio calculation.
     *
     * @param array<string, string> $currentVars Current status variables
     * @param array<string, string>|null $serverVars Server variables (for max_connections)
     */
    private function resolveMax(array $currentVars, ?array $serverVars): float
    {
        if ($this->maxOverride !== null) {
            return $this->maxOverride;
        }

        $serverVarsKeys = $this->widget->serverVarsKeys;
        if ($serverVarsKeys === null) {
            return 0.0;
        }

        $maxKey = $serverVarsKeys['max'] ?? null;
        if ($maxKey === null) {
            return 0.0;
        }

        if ($serverVars !== null && isset($serverVars[$maxKey])) {
            return (float) $serverVars[$maxKey];
        }

        if (isset($currentVars[$maxKey])) {
            return (float) $currentVars[$maxKey];
        }

        return 0.0;
    }
}
