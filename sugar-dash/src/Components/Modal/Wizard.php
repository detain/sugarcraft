<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Modal;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A multi-step wizard component.
 *
 * Features:
 * - Multiple steps with titles
 * - Progress indicator
 * - Current step highlighting
 * - Optional step descriptions
 * - Customizable connector styles
 * - Step states (completed, current, upcoming)
 *
 * Mirrors wizard/stepper UI patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Wizard implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var WizardStep[] */
    private array $steps;

    public function __construct(
        array $steps = [],
        private readonly int $currentStep = 0,
        private readonly ?Color $completedColor = null,
        private readonly ?Color $currentColor = null,
        private readonly ?Color $upcomingColor = null,
        private readonly string $completedChar = '✓',
        private readonly string $currentChar = '●',
        private readonly string $upcomingChar = '○',
        private readonly string $connectorChar = '─',
    ) {
        $this->steps = $steps;
    }

    /**
     * Create a new wizard with the given step titles.
     *
     * @param string[] $stepTitles
     */
    public static function fromSteps(array $stepTitles): self
    {
        $steps = array_map(
            fn(string $title) => WizardStep::create($title),
            $stepTitles
        );

        return new self(
            steps: $steps,
            currentStep: 0,
            completedColor: Color::hex('#22C55E'),
            currentColor: Color::hex('#3B82F6'),
            upcomingColor: Color::hex('#9CA3AF'),
            completedChar: '✓',
            currentChar: '●',
            upcomingChar: '○',
            connectorChar: '─',
        );
    }

    /**
     * Set the allocated dimensions for this wizard.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the wizard as a string.
     */
    public function render(): string
    {
        if ($this->steps === []) {
            return '';
        }

        $maxWidth = $this->width ?? 80;
        $lines = [];

        // Calculate available width per step
        $stepCount = count($this->steps);
        if ($stepCount === 0) {
            return '';
        }

        // Render the progress indicator line
        $progressLine = $this->renderProgressLine($maxWidth);
        $lines[] = $progressLine;

        // Render the step labels line
        $labelsLine = $this->renderLabelsLine($maxWidth);
        $lines[] = $labelsLine;

        // Render step descriptions if any
        foreach ($this->steps as $index => $step) {
            if ($step->description !== null && $step->description !== '') {
                $descLine = $this->renderDescriptionLine($step->description, $index, $maxWidth);
                $lines[] = $descLine;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Render the progress indicator line with step indicators and connectors.
     */
    private function renderProgressLine(int $maxWidth): string
    {
        $stepCount = count($this->steps);

        // Calculate segment width: each step indicator + connector
        // We allocate: char + space for indicator, then connector fills rest
        $result = '';

        for ($i = 0; $i < $stepCount; $i++) {
            $step = $this->steps[$i];
            $isCompleted = $i < $this->currentStep;
            $isCurrent = $i === $this->currentStep;
            $isUpcoming = $i > $this->currentStep;

            // Select colors and characters based on state
            if ($isCompleted) {
                $color = $this->completedColor;
                $char = $this->completedChar;
            } elseif ($isCurrent) {
                $color = $this->currentColor;
                $char = $this->currentChar;
            } else {
                $color = $this->upcomingColor;
                $char = $this->upcomingChar;
            }

            // Apply color
            if ($color !== null) {
                $result .= $color->toFg(ColorProfile::TrueColor);
            }

            // Add step indicator
            $result .= $char;

            // Reset color
            if ($color !== null) {
                $result .= Ansi::reset();
            }

            // Add connector to next step (except for last step)
            if ($i < $stepCount - 1) {
                // Calculate connector length based on remaining width
                $connectorLen = $this->calculateConnectorLength($maxWidth, $stepCount, $i);
                if ($connectorLen > 0) {
                    if ($isCompleted || $isCurrent) {
                        $connectorColor = $isCompleted ? $this->completedColor : $this->currentColor;
                    } else {
                        $connectorColor = $this->upcomingColor;
                    }

                    if ($connectorColor !== null) {
                        $result .= $connectorColor->toFg(ColorProfile::TrueColor);
                    }
                    $result .= str_repeat($this->connectorChar, $connectorLen);
                    if ($connectorColor !== null) {
                        $result .= Ansi::reset();
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Calculate connector length based on position and available width.
     */
    private function calculateConnectorLength(int $maxWidth, int $stepCount, int $stepIndex): int
    {
        // Base calculation: total width minus step indicators, divided among connectors
        $minStepWidth = 3; // Minimum space for each step indicator
        $totalStepIndicators = $stepCount * 1; // Each indicator is 1 char
        $availableForConnectors = $maxWidth - $totalStepIndicators;
        $connectorCount = $stepCount - 1;

        if ($connectorCount <= 0 || $availableForConnectors <= 0) {
            return max(0, $availableForConnectors);
        }

        // Distribute remaining space among connectors
        $baseLength = (int) floor($availableForConnectors / $connectorCount);

        // Give extra space to earlier connectors for better visual balance
        return max(1, $baseLength);
    }

    /**
     * Render the labels line with step numbers/titles.
     */
    private function renderLabelsLine(int $maxWidth): string
    {
        $stepCount = count($this->steps);
        $result = '';

        for ($i = 0; $i < $stepCount; $i++) {
            $step = $this->steps[$i];
            $isCurrent = $i === $this->currentStep;
            $isUpcoming = $i > $this->currentStep;

            // Build label (step number or title)
            $label = (string) ($i + 1);
            if ($step->title !== '') {
                // Truncate title if needed
                $label = $this->truncateCenter($step->title, 10);
            }

            // Apply color
            if ($isCurrent) {
                if ($this->currentColor !== null) {
                    $result .= $this->currentColor->toFg(ColorProfile::TrueColor);
                }
            } elseif ($isUpcoming) {
                if ($this->upcomingColor !== null) {
                    $result .= $this->upcomingColor->toFg(ColorProfile::TrueColor);
                }
            }

            $result .= $label;

            // Reset color
            if ($isCurrent && $this->currentColor !== null) {
                $result .= Ansi::reset();
            } elseif ($isUpcoming && $this->upcomingColor !== null) {
                $result .= Ansi::reset();
            }

            // Add spacing for next step
            if ($i < $stepCount - 1) {
                $connectorLen = $this->calculateConnectorLength($maxWidth, $stepCount, $i);
                $spacing = max(1, $connectorLen - strlen((string)($i + 1)) + 1);
                $result .= str_repeat(' ', $spacing);
            }
        }

        return $result;
    }

    /**
     * Render a description line for a step.
     */
    private function renderDescriptionLine(string $description, int $stepIndex, int $maxWidth): string
    {
        $stepCount = count($this->steps);
        $isCurrent = $stepIndex === $this->currentStep;
        $isUpcoming = $stepIndex > $this->currentStep;

        // Calculate offset to align description under its step
        $offset = 0;
        for ($i = 0; $i < $stepIndex; $i++) {
            $stepLen = 1; // step number
            $connectorLen = $this->calculateConnectorLength($maxWidth, $stepCount, $i);
            $offset += $stepLen + $connectorLen;
        }

        // Build result with leading spaces
        $result = str_repeat(' ', $offset);

        // Apply color if current or upcoming
        if ($isCurrent && $this->currentColor !== null) {
            $result = $this->currentColor->toFg(ColorProfile::TrueColor) . $result;
            $description .= Ansi::reset();
        } elseif ($isUpcoming && $this->upcomingColor !== null) {
            $result = $this->upcomingColor->toFg(ColorProfile::TrueColor) . $result;
            $description .= Ansi::reset();
        }

        // Truncate description if needed
        $availableWidth = $maxWidth - $offset;
        if (Width::string($description) > $availableWidth) {
            $description = mb_substr($description, 0, $availableWidth - 3, 'UTF-8') . '...';
        }

        return $result . $description;
    }

    /**
     * Truncate a string from the center, keeping both ends.
     */
    private function truncateCenter(string $text, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        $length = mb_strlen($text, 'UTF-8');
        if ($length <= $maxLength) {
            return $text;
        }

        $half = (int) floor(($maxLength - 3) / 2);
        $rest = $maxLength - 3 - $half;

        return mb_substr($text, 0, $half, 'UTF-8') . '...' . mb_substr($text, -$rest, $rest, 'UTF-8');
    }

    /**
     * Calculate the natural dimensions of this wizard.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->steps === []) {
            return [0, 0];
        }

        $maxWidth = $this->width ?? 80;

        // Height = progress line + labels + any descriptions
        $height = 2; // Always have progress and labels
        foreach ($this->steps as $step) {
            if ($step->description !== null && $step->description !== '') {
                $height++;
            }
        }

        return [$maxWidth, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the wizard steps.
     *
     * @param WizardStep[] $steps
     */
    public function withSteps(array $steps): self
    {
        return new self(
            steps: $steps,
            currentStep: $this->currentStep,
            completedColor: $this->completedColor,
            currentColor: $this->currentColor,
            upcomingColor: $this->upcomingColor,
            completedChar: $this->completedChar,
            currentChar: $this->currentChar,
            upcomingChar: $this->upcomingChar,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Add a step to the wizard.
     */
    public function addStep(WizardStep $step): self
    {
        return new self(
            steps: [...$this->steps, $step],
            currentStep: $this->currentStep,
            completedColor: $this->completedColor,
            currentColor: $this->currentColor,
            upcomingColor: $this->upcomingColor,
            completedChar: $this->completedChar,
            currentChar: $this->currentChar,
            upcomingChar: $this->upcomingChar,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Set the current step index.
     */
    public function withCurrentStep(int $step): self
    {
        $validStep = max(0, min($step, count($this->steps) - 1));
        return new self(
            steps: $this->steps,
            currentStep: $validStep,
            completedColor: $this->completedColor,
            currentColor: $this->currentColor,
            upcomingColor: $this->upcomingColor,
            completedChar: $this->completedChar,
            currentChar: $this->currentChar,
            upcomingChar: $this->upcomingChar,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Set the completed color.
     */
    public function withCompletedColor(?Color $color): self
    {
        return new self(
            steps: $this->steps,
            currentStep: $this->currentStep,
            completedColor: $color,
            currentColor: $this->currentColor,
            upcomingColor: $this->upcomingColor,
            completedChar: $this->completedChar,
            currentChar: $this->currentChar,
            upcomingChar: $this->upcomingChar,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Set the current step color.
     */
    public function withCurrentColor(?Color $color): self
    {
        return new self(
            steps: $this->steps,
            currentStep: $this->currentStep,
            completedColor: $this->completedColor,
            currentColor: $color,
            upcomingColor: $this->upcomingColor,
            completedChar: $this->completedChar,
            currentChar: $this->currentChar,
            upcomingChar: $this->upcomingChar,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Set the upcoming step color.
     */
    public function withUpcomingColor(?Color $color): self
    {
        return new self(
            steps: $this->steps,
            currentStep: $this->currentStep,
            completedColor: $this->completedColor,
            currentColor: $this->currentColor,
            upcomingColor: $color,
            completedChar: $this->completedChar,
            currentChar: $this->currentChar,
            upcomingChar: $this->upcomingChar,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Set the completed character.
     */
    public function withCompletedChar(string $char): self
    {
        return new self(
            steps: $this->steps,
            currentStep: $this->currentStep,
            completedColor: $this->completedColor,
            currentColor: $this->currentColor,
            upcomingColor: $this->upcomingColor,
            completedChar: $char,
            currentChar: $this->currentChar,
            upcomingChar: $this->upcomingChar,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Set the current character.
     */
    public function withCurrentChar(string $char): self
    {
        return new self(
            steps: $this->steps,
            currentStep: $this->currentStep,
            completedColor: $this->completedColor,
            currentColor: $this->currentColor,
            upcomingColor: $this->upcomingColor,
            completedChar: $this->completedChar,
            currentChar: $char,
            upcomingChar: $this->upcomingChar,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Set the upcoming character.
     */
    public function withUpcomingChar(string $char): self
    {
        return new self(
            steps: $this->steps,
            currentStep: $this->currentStep,
            completedColor: $this->completedColor,
            currentColor: $this->currentColor,
            upcomingColor: $this->upcomingColor,
            completedChar: $this->completedChar,
            currentChar: $this->currentChar,
            upcomingChar: $char,
            connectorChar: $this->connectorChar,
        );
    }

    /**
     * Set the connector character.
     */
    public function withConnectorChar(string $char): self
    {
        return new self(
            steps: $this->steps,
            currentStep: $this->currentStep,
            completedColor: $this->completedColor,
            currentColor: $this->currentColor,
            upcomingColor: $this->upcomingColor,
            completedChar: $this->completedChar,
            currentChar: $this->currentChar,
            upcomingChar: $this->upcomingChar,
            connectorChar: $char,
        );
    }
}
