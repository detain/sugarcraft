<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Nav;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A ladder diagram for displaying process steps and timeline.
 *
 * Features:
 * - Sequential steps with connecting lines
 * - Optional status for each step (complete, current, pending)
 * - Different orientations (vertical/horizontal)
 * - Customizable colors
 * - Optional description for each step
 *
 * Mirrors ladder/gantt-style diagram patterns adapted to PHP with wither-style
 * immutable setters.
 */
final class Ladder implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const StatusComplete = 'complete';
    public const StatusCurrent = 'current';
    public const StatusPending = 'pending';

    /**
     * @param list<array{label: string, description?: string, status?: string}> $steps
     */
    public function __construct(
        private readonly array $steps,
        private readonly bool $horizontal = false,
        private readonly ?Color $completeColor = null,
        private readonly ?Color $currentColor = null,
        private readonly ?Color $pendingColor = null,
    ) {}

    /**
     * Create a new ladder diagram.
     *
     * @param list<array{label: string, description?: string, status?: string}> $steps
     */
    public static function new(array $steps): self
    {
        return new self(
            steps: $steps,
            horizontal: false,
            completeColor: Color::hex('#A6E3A1'),
            currentColor: Color::hex('#F9E2AF'),
            pendingColor: Color::hex('#6C7086'),
        );
    }

    /**
     * Create a horizontal ladder.
     */
    public static function horizontal(array $steps): self
    {
        return new self(
            steps: $steps,
            horizontal: true,
            completeColor: Color::hex('#A6E3A1'),
            currentColor: Color::hex('#F9E2AF'),
            pendingColor: Color::hex('#6C7086'),
        );
    }

    /**
     * Set the allocated dimensions for this ladder.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this ladder.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->horizontal) {
            $width = count($this->steps) * 15 + 10;
            $height = 5;
        } else {
            $width = 40;
            $height = count($this->steps) * 3 + 2;
        }
        return [$width, $height];
    }

    /**
     * Render the ladder diagram.
     */
    public function render(): string
    {
        if ($this->steps === []) {
            return '';
        }

        if ($this->horizontal) {
            return $this->renderHorizontal();
        }
        return $this->renderVertical();
    }

    /**
     * Render vertical ladder.
     */
    private function renderVertical(): string
    {
        $result = [];
        $vLine = '│';

        for ($i = 0; $i < count($this->steps); $i++) {
            $step = $this->steps[$i];
            $status = $step['status'] ?? self::StatusPending;
            $color = $this->getColorForStatus($status);

            $colorStr = $color->toFg(ColorProfile::TrueColor);
            $nodeChar = $status === self::StatusComplete ? '●' : ($status === self::StatusCurrent ? '◉' : '○');
            $label = $step['label'];
            $description = $step['description'] ?? '';

            // Node line
            $result[] = $colorStr . $vLine . ' ' . $nodeChar . ' ' . $label . Ansi::reset();
            if ($description !== '') {
                $result[] = $colorStr . $vLine . '   ' . $description . Ansi::reset();
            }

            // Connector line (except for last step)
            if ($i < count($this->steps) - 1) {
                $result[] = $colorStr . $vLine . ' │' . Ansi::reset();
            }
        }

        return implode("\n", $result);
    }

    /**
     * Render horizontal ladder.
     */
    private function renderHorizontal(): string
    {
        $result = [];
        $hLine = '───';

        // Top line with nodes
        $topLine = '';
        for ($i = 0; $i < count($this->steps); $i++) {
            $step = $this->steps[$i];
            $status = $step['status'] ?? self::StatusPending;
            $color = $this->getColorForStatus($status);
            $nodeChar = $status === self::StatusComplete ? '●' : ($status === self::StatusCurrent ? '◉' : '○');

            $topLine .= ' ' . $color->toFg(ColorProfile::TrueColor) . $nodeChar . ' ' . $step['label'] . Ansi::reset();

            if ($i < count($this->steps) - 1) {
                $topLine .= $hLine;
            }
        }
        $result[] = $topLine;

        // Bottom line with connectors
        $bottomLine = '';
        for ($i = 0; $i < count($this->steps) - 1; $i++) {
            $bottomLine .= '   ' . $hLine;
        }
        $result[] = $bottomLine;

        return implode("\n", $result);
    }

    /**
     * Get color for status.
     */
    private function getColorForStatus(string $status): Color
    {
        return match ($status) {
            self::StatusComplete => $this->completeColor,
            self::StatusCurrent => $this->currentColor,
            default => $this->pendingColor,
        };
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set horizontal orientation.
     */
    public function withHorizontal(bool $horizontal): self
    {
        return new self(
            steps: $this->steps,
            horizontal: $horizontal,
            completeColor: $this->completeColor,
            currentColor: $this->currentColor,
            pendingColor: $this->pendingColor,
        );
    }

    /**
     * Set complete color.
     */
    public function withCompleteColor(?Color $color): self
    {
        return new self(
            steps: $this->steps,
            horizontal: $this->horizontal,
            completeColor: $color,
            currentColor: $this->currentColor,
            pendingColor: $this->pendingColor,
        );
    }

    /**
     * Set current color.
     */
    public function withCurrentColor(?Color $color): self
    {
        return new self(
            steps: $this->steps,
            horizontal: $this->horizontal,
            completeColor: $this->completeColor,
            currentColor: $color,
            pendingColor: $this->pendingColor,
        );
    }

    /**
     * Set pending color.
     */
    public function withPendingColor(?Color $color): self
    {
        return new self(
            steps: $this->steps,
            horizontal: $this->horizontal,
            completeColor: $this->completeColor,
            currentColor: $this->currentColor,
            pendingColor: $color,
        );
    }
}