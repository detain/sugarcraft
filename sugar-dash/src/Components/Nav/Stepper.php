<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Nav;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A stepper / step indicator component for multi-step wizards.
 *
 * Features:
 * - Display multiple steps with labels
 * - Mark current, completed, and pending steps
 * - Optional step numbers or icons
 * - Customizable colors for each state
 * - Horizontal layout with connectors
 *
 * Mirrors stepper/wizard UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Stepper implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{label: string, status: 'pending'|'active'|'completed'}> $steps
     */
    public function __construct(
        private readonly array $steps,
        private readonly bool $showNumbers = true,
        private readonly ?Color $completedColor = null,
        private readonly ?Color $activeColor = null,
        private readonly ?Color $pendingColor = null,
        private readonly ?Color $connectorColor = null,
    ) {}

    /**
     * Create a new stepper with default styling.
     *
     * Default: purple active state, green completed, gray pending.
     */
    public static function new(array $steps): self
    {
        return new self(
            steps: $steps,
            showNumbers: true,
            completedColor: Color::hex('#22C55E'),
            activeColor: Color::hex('#874BFD'),
            pendingColor: Color::hex('#6B7280'),
            connectorColor: Color::hex('#6B7280'),
        );
    }

    /**
     * Create a stepper with specific step labels and current step index.
     *
     * @param list<string> $stepLabels
     */
    public static function fromLabels(array $stepLabels, int $currentStep = 0): self
    {
        $steps = array_map(function (string $label, int $index) use ($currentStep): array {
            $status = match (true) {
                $index < $currentStep => 'completed',
                $index === $currentStep => 'active',
                default => 'pending',
            };
            return ['label' => $label, 'status' => $status];
        }, $stepLabels, array_keys($stepLabels));

        return new self(
            steps: $steps,
            showNumbers: true,
            completedColor: Color::hex('#22C55E'),
            activeColor: Color::hex('#874BFD'),
            pendingColor: Color::hex('#6B7280'),
            connectorColor: Color::hex('#6B7280'),
        );
    }

    /**
     * Set the allocated dimensions for this stepper.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the stepper as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useWidth = max($useWidth, 10);

        if (count($this->steps) === 0) {
            return str_repeat(' ', $useWidth);
        }

        $result = '';

        // Determine the width per step segment (including connector)
        $totalStepWidth = 0;
        foreach ($this->steps as $step) {
            $labelWidth = Width::string($step['label']);
            $stepDisplayWidth = $this->showNumbers ? $labelWidth + 4 : $labelWidth + 2; // +2 for padding, +2 for circle if numbered
            $totalStepWidth += $stepDisplayWidth;
        }
        $totalConnectorWidth = max(0, count($this->steps) - 1) * 3; // " → " connectors
        $totalContentWidth = $totalStepWidth + $totalConnectorWidth;

        // Calculate scaling if needed
        $scale = 1.0;
        if ($totalContentWidth > $useWidth && $totalContentWidth > 0) {
            $scale = $useWidth / $totalContentWidth;
        }

        $isFirst = true;
        foreach ($this->steps as $index => $step) {
            // Add connector before each step except the first
            if (!$isFirst) {
                if ($this->connectorColor !== null) {
                    $result .= $this->connectorColor->toFg(ColorProfile::TrueColor);
                }
                $result .= ' ';
                if ($this->connectorColor !== null) {
                    $result .= Ansi::reset();
                }
            }
            $isFirst = false;

            // Render the step
            $stepStr = $this->renderStep($step, $index);
            $result .= $stepStr;
        }

        // Reset ANSI
        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Render a single step.
     */
    private function renderStep(array $step, int $index): string
    {
        $status = $step['status'];
        $label = $step['label'];

        $color = match ($status) {
            'completed' => $this->completedColor,
            'active' => $this->activeColor,
            default => $this->pendingColor,
        };

        $symbol = match ($status) {
            'completed' => '✓',
            'active' => $this->showNumbers ? (string) ($index + 1) : '●',
            'pending' => '○',
        };

        $result = '';

        if ($color !== null) {
            $result .= $color->toFg(ColorProfile::TrueColor);
        }

        if ($this->showNumbers || $status !== 'completed') {
            $result .= '[' . $symbol . ']';
        } else {
            $result .= '[' . $symbol . ']';
        }

        $result .= ' ' . $label;

        if ($color !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Calculate the natural width based on step labels.
     */
    private function calculateNaturalWidth(): int
    {
        if (count($this->steps) === 0) {
            return 10;
        }

        $width = 0;
        foreach ($this->steps as $index => $step) {
            $labelWidth = Width::string($step['label']);
            $stepWidth = $labelWidth + 6; // [n] + label + padding
            $width += $stepWidth;

            if ($index < count($this->steps) - 1) {
                $width += 3; // connector
            }
        }

        return max($width, 10);
    }

    /**
     * Calculate the natural dimensions of this stepper.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? $this->calculateNaturalWidth();
        return [$width, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the steps.
     *
     * @param list<array{label: string, status: 'pending'|'active'|'completed'}> $steps
     */
    public function withSteps(array $steps): self
    {
        return new self(
            steps: $steps,
            showNumbers: $this->showNumbers,
            completedColor: $this->completedColor,
            activeColor: $this->activeColor,
            pendingColor: $this->pendingColor,
            connectorColor: $this->connectorColor,
        );
    }

    /**
     * Set the current active step.
     */
    public function withActiveStep(int $step): self
    {
        $steps = array_map(function (array $s, int $index) use ($step): array {
            if ($index < $step) {
                $s['status'] = 'completed';
            } elseif ($index === $step) {
                $s['status'] = 'active';
            } else {
                $s['status'] = 'pending';
            }
            return $s;
        }, $this->steps, array_keys($this->steps));

        return new self(
            steps: $steps,
            showNumbers: $this->showNumbers,
            completedColor: $this->completedColor,
            activeColor: $this->activeColor,
            pendingColor: $this->pendingColor,
            connectorColor: $this->connectorColor,
        );
    }

    /**
     * Show or hide step numbers.
     */
    public function withShowNumbers(bool $show): self
    {
        return new self(
            steps: $this->steps,
            showNumbers: $show,
            completedColor: $this->completedColor,
            activeColor: $this->activeColor,
            pendingColor: $this->pendingColor,
            connectorColor: $this->connectorColor,
        );
    }

    /**
     * Set the completed step color.
     */
    public function withCompletedColor(?Color $color): self
    {
        return new self(
            steps: $this->steps,
            showNumbers: $this->showNumbers,
            completedColor: $color,
            activeColor: $this->activeColor,
            pendingColor: $this->pendingColor,
            connectorColor: $this->connectorColor,
        );
    }

    /**
     * Set the active step color.
     */
    public function withActiveColor(?Color $color): self
    {
        return new self(
            steps: $this->steps,
            showNumbers: $this->showNumbers,
            completedColor: $this->completedColor,
            activeColor: $color,
            pendingColor: $this->pendingColor,
            connectorColor: $this->connectorColor,
        );
    }

    /**
     * Set the pending step color.
     */
    public function withPendingColor(?Color $color): self
    {
        return new self(
            steps: $this->steps,
            showNumbers: $this->showNumbers,
            completedColor: $this->completedColor,
            activeColor: $this->activeColor,
            pendingColor: $color,
            connectorColor: $this->connectorColor,
        );
    }

    /**
     * Set the connector color.
     */
    public function withConnectorColor(?Color $color): self
    {
        return new self(
            steps: $this->steps,
            showNumbers: $this->showNumbers,
            completedColor: $this->completedColor,
            activeColor: $this->activeColor,
            pendingColor: $this->pendingColor,
            connectorColor: $color,
        );
    }
}