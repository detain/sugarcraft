<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Gantt chart time scale.
 */
enum TimeScale: string
{
    case Hours = 'hours';
    case Days = 'days';
    case Weeks = 'weeks';
    case Months = 'months';
}

/**
 * A Gantt chart task with start time and duration.
 */
final class GanttTask
{
    public function __construct(
        public readonly string $name,
        public readonly int $startDay,    // Day offset from project start
        public readonly int $duration,    // Duration in days
        public readonly ?Color $color = null,
        public readonly ?float $progress = null, // 0.0 to 1.0
        public readonly bool $milestone = false,
    ) {}

    /**
     * Create a copy with progress.
     */
    public function withProgress(float $progress): self
    {
        return new self(
            $this->name,
            $this->startDay,
            $this->duration,
            $this->color,
            $progress,
            $this->milestone,
        );
    }

    /**
     * Create a milestone variant.
     */
    public function asMilestone(): self
    {
        return new self(
            $this->name,
            $this->startDay,
            0,
            $this->color,
            1.0,
            true,
        );
    }
}

/**
 * A Gantt chart component for project scheduling visualization.
 *
 * Features:
 * - Task bars with duration visualization
 * - Milestone markers
 * - Progress indication
 * - Multiple time scales
 * - Task dependencies display
 *
 * Mirrors Gantt chart patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Gantt implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<GanttTask> */
    private array $tasks = [];

    private int $startDay = 0;
    private int $totalDays = 30;
    private TimeScale $timeScale = TimeScale::Days;
    private bool $showProgress = true;
    private bool $showToday = true;

    public function __construct(
        private ?int $maxTasks = null,
        private ?Color $barColor = null,
        private ?Color $milestoneColor = null,
        private ?Color $gridColor = null,
        private ?Color $textColor = null,
        private ?Color $backgroundColor = null,
        private string $style = 'rounded',
    ) {}

    /**
     * Create a new Gantt chart with default styling.
     */
    public static function new(): self
    {
        return new self(
            maxTasks: null,
            barColor: Color::hex('#89B4FA'),
            milestoneColor: Color::hex('#F38BA8'),
            gridColor: Color::hex('#45475A'),
            textColor: Color::hex('#CDD6F4'),
            backgroundColor: Color::hex('#1E1E2E'),
            style: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this Gantt chart.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Add a task to the chart.
     */
    public function withTask(GanttTask $task): self
    {
        $clone = clone $this;
        $clone->tasks[] = $task;
        return $clone;
    }

    /**
     * Add a task by parameters.
     */
    public function addTask(string $name, int $startDay, int $duration, ?Color $color = null): self
    {
        $task = new GanttTask($name, $startDay, $duration, $color ?? $this->barColor);
        return $this->withTask($task);
    }

    /**
     * Set all tasks at once.
     *
     * @param list<GanttTask> $tasks
     */
    public function withTasks(array $tasks): self
    {
        $clone = clone $this;
        $clone->tasks = $tasks;
        return $clone;
    }

    /**
     * Set the project time range.
     */
    public function withTimeRange(int $startDay, int $totalDays): self
    {
        $clone = clone $this;
        $clone->startDay = $startDay;
        $clone->totalDays = $totalDays;
        return $clone;
    }

    /**
     * Set the time scale.
     */
    public function withTimeScale(TimeScale $scale): self
    {
        $clone = clone $this;
        $clone->timeScale = $scale;
        return $clone;
    }

    /**
     * Show or hide progress.
     */
    public function withShowProgress(bool $show): self
    {
        $clone = clone $this;
        $clone->showProgress = $show;
        return $clone;
    }

    /**
     * Show or hide today marker.
     */
    public function withShowToday(bool $show): self
    {
        $clone = clone $this;
        $clone->showToday = $show;
        return $clone;
    }

    /**
     * Render the Gantt chart as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 70;
        $useHeight = $this->height ?? 12;

        if ($useWidth < 30 || $useHeight < 4) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $gridColor = $this->gridColor ?? Color::hex('#45475A');
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');

        $result = '';

        // Header row with time markers
        $result .= $tl . str_repeat($h, $useWidth - 2) . $tr . "\n";

        // Task names column
        $nameWidth = 15;
        $chartWidth = $useWidth - 2 - $nameWidth - 1;

        // Header with day numbers
        $result .= $v . str_pad('Tasks', $nameWidth);
        $result .= $v . $this->renderTimeHeader($chartWidth) . $v . "\n";

        // Separator
        $result .= $v . str_repeat('─', $nameWidth);
        $result .= $v . str_repeat('─', $chartWidth) . $v . "\n";

        // Task rows
        $visibleTasks = array_slice($this->tasks, 0, $useHeight - 4);
        foreach ($visibleTasks as $task) {
            $result .= $v . str_pad(mb_substr($task->name, 0, $nameWidth - 1), $nameWidth);
            $result .= $v . $this->renderTaskBar($task, $chartWidth) . $v . "\n";
        }

        // Bottom border
        $result .= $bl . str_repeat($h, $useWidth - 2) . $br;

        return $result;
    }

    /**
     * Render the time header.
     */
    private function renderTimeHeader(int $width): string
    {
        $result = '';
        $dayWidth = max(1, intval($width / $this->totalDays));

        for ($day = 0; $day < min($this->totalDays, $width); $day++) {
            $showMarker = $day % max(1, intval($this->totalDays / $width * 5)) === 0;
            if ($day === 0 || $showMarker) {
                $result .= mb_chr(ord('0') + min(9, $day % 10));
            } else {
                $result .= '·';
            }
        }

        return mb_substr($result, 0, $width);
    }

    /**
     * Render a task bar.
     */
    private function renderTaskBar(GanttTask $task, int $width): string
    {
        if ($task->milestone) {
            // Milestone is a diamond
            $pos = $this->dayToPosition($task->startDay, $width);
            $result = str_repeat(' ', max(0, $pos - 1)) . '◆';
            return mb_substr($result, 0, $width);
        }

        $startPos = $this->dayToPosition($task->startDay, $width);
        $endPos = $this->dayToPosition($task->startDay + $task->duration, $width);

        $barLength = max(1, $endPos - $startPos);
        $progressLength = $task->progress !== null ? intval($barLength * $task->progress) : $barLength;

        $result = str_repeat(' ', max(0, $startPos));

        if ($this->showProgress && $task->progress !== null) {
            $result .= str_repeat('█', $progressLength);
            $result .= str_repeat('░', $barLength - $progressLength);
        } else {
            $result .= str_repeat('█', $barLength);
        }

        return mb_substr($result, 0, $width);
    }

    /**
     * Convert a day number to a position in the chart.
     */
    private function dayToPosition(int $day, int $width): int
    {
        $ratio = $day / max(1, $this->totalDays);
        return intval($ratio * $width);
    }

    /**
     * Get the style characters for the border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string}
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['╭', '╮', '╰', '╯', '─', '│'],
        };
    }

    /**
     * Calculate the natural dimensions of this Gantt chart.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 70;
        $height = $this->height ?? max(8, count($this->tasks) + 4);

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the bar color.
     */
    public function withBarColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->barColor = $color;
        return $clone;
    }

    /**
     * Set the milestone color.
     */
    public function withMilestoneColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->milestoneColor = $color;
        return $clone;
    }

    /**
     * Set the grid color.
     */
    public function withGridColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->gridColor = $color;
        return $clone;
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->textColor = $color;
        return $clone;
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->backgroundColor = $color;
        return $clone;
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        $clone = clone $this;
        $clone->style = $style;
        return $clone;
    }
}
