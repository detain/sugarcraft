<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

use SugarCraft\Dash\Plot\Chart\Gauge;
use SugarCraft\Dash\Plot\Chart\GaugeCircle;
use SugarCraft\Dash\Plot\Chart\Meter;
use SugarCraft\Dash\Foundation\Color;

/**
 * Renders round and level meter widgets using sugar-dash chart components.
 *
 * - "round" kind: uses GaugeCircle (circular gauge) for efficiency metrics
 * - "level" kind: uses Gauge (horizontal) for usage vs max metrics
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard DBRoundMeter, DBLevelMeter
 */
final class MeterCell
{
    private float $ratio = 0.0;

    private bool $hasValue = false;

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

        $value = (float) $value;

        $max = $this->resolveMax($current, $serverVars);

        if ($max > 0) {
            $this->ratio = min(1.0, $value / $max);
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
    public function withRatio(float $ratio): self
    {
        $clone = clone $this;
        $clone->ratio = max(0.0, min(1.0, $ratio));
        $clone->hasValue = true;
        return $clone;
    }

    /**
     * Reset the meter (call on server restart).
     */
    public function reset(): self
    {
        $this->ratio = 0.0;
        $this->hasValue = false;
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
     * Render as a round meter (GaugeCircle).
     */
    public function viewRound(): string
    {
        if (!$this->hasValue) {
            return GaugeCircle::new(0.0)->render();
        }

        $color = $this->widget->color;
        $gauge = GaugeCircle::new($this->ratio)
            ->withArcColor(Color::rgb($color['r'], $color['g'], $color['b']));

        return $gauge->render();
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

        return $gauge->render();
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
