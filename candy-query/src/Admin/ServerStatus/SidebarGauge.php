<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Foundation\Threshold;
use SugarCraft\Dash\Plot\Chart\Gauge;
use SugarCraft\Dash\Plot\Chart\GaugeCircle;

/**
 * Gauge types supported in the sidebar.
 */
enum GaugeType: string
{
    case Cpu           = 'cpu';
    case Connections   = 'connections';
    case Traffic       = 'traffic';
    case KeyEfficiency = 'key_efficiency';
    case Qps           = 'qps';
    case InnoDB        = 'innodb';
}

/**
 * A single sidebar gauge displaying a metric with threshold coloring.
 *
 * Renders CPU as a circular GaugeCircle; all others as horizontal Gauge.
 * Color transitions: green (0.0-0.6) → yellow (0.6-0.8) → red (0.8-1.0).
 *
 * @see Mirrors mysql-workbench sidebar gauge components
 */
final class SidebarGauge
{
    private function __construct(
        private readonly GaugeType $type,
        private readonly float $ratio,
    ) {}

    /**
     * Create a new sidebar gauge.
     */
    public static function new(GaugeType $type, float $ratio): self
    {
        return new self($type, self::clampRatio($ratio));
    }

    /**
     * Render the gauge as an ANSI string.
     */
    public function view(): string
    {
        $color = self::thresholdColor($this->ratio);
        $label = $this->label();

        // CPU uses circular gauge; others use horizontal
        if ($this->type === GaugeType::Cpu) {
            $gauge = GaugeCircle::new($this->ratio)
                ->withArcColor($color)
                ->withNeedleColor($color)
                ->withRadius(5);

            return $gauge->render();
        }

        $gauge = Gauge::new($this->ratio)
            ->withFilledColor($color)
            ->withWidth(20);

        return $label . ' ' . $gauge->render();
    }

    /**
     * Get the human-readable label for this gauge type.
     */
    public function label(): string
    {
        return match ($this->type) {
            GaugeType::Cpu           => 'CPU',
            GaugeType::Connections   => 'Connections',
            GaugeType::Traffic       => 'Traffic',
            GaugeType::KeyEfficiency => 'Key Eff',
            GaugeType::Qps           => 'QPS',
            GaugeType::InnoDB        => 'InnoDB',
        };
    }

    /**
     * Determine the threshold color based on ratio.
     *
     * Green:   0.0 ≤ ratio < 0.6
     * Yellow:  0.6 ≤ ratio < 0.8
     * Red:     0.8 ≤ ratio ≤ 1.0
     *
     * Delegates to the shared {@see Threshold::health()} ramp in sugar-dash so
     * every gauge/indicator shares one definition of "healthy/warn/critical".
     */
    public static function thresholdColor(float $ratio): Color
    {
        return Threshold::health()->colorFor(self::clampRatio($ratio));
    }

    /**
     * Return a new instance with an updated ratio.
     */
    public function withRatio(float $ratio): self
    {
        return new self($this->type, self::clampRatio($ratio));
    }

    /**
     * Accessor for the gauge type.
     */
    public function type(): GaugeType
    {
        return $this->type;
    }

    /**
     * Accessor for the current ratio.
     */
    public function ratio(): float
    {
        return $this->ratio;
    }

    /**
     * Clamp ratio to [0.0, 1.0].
     */
    private static function clampRatio(float $ratio): float
    {
        return max(0.0, min(1.0, $ratio));
    }
}
