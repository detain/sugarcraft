<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Status of a PERT task.
 */
enum PertStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Delayed = 'delayed';
}

/**
 * A task node in a PERT chart.
 */
final class PertTask
{
    /** @var list<string> */
    public array $dependencies = [];

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $duration,
        public readonly ?Color $color = null,
        public PertStatus $status = PertStatus::Pending,
    ) {}

    /**
     * Create a copy with dependencies.
     *
     * @param list<string> $dependencies
     */
    public function withDependencies(array $dependencies): self
    {
        $clone = clone $this;
        $clone->dependencies = $dependencies;
        return $clone;
    }

    /**
     * Create a copy with a different color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            duration: $this->duration,
            color: $color,
            status: $this->status,
        );
    }

    /**
     * Create a copy with a different status.
     */
    public function withStatus(PertStatus $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    /**
     * Create a copy with a different duration.
     */
    public function withDuration(int $duration): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            duration: $duration,
            color: $this->color,
            status: $this->status,
        );
    }
}

/**
 * A PERT (Program Evaluation and Review Technique) chart component.
 *
 * Features:
 * - Task nodes with duration display
 * - Dependency arrows between tasks
 * - Critical path highlighting
 * - Status indicators (pending, in-progress, completed, delayed)
 * - Early/late start and finish times
 *
 * Mirrors PERT chart patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class PERT implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<PertTask> */
    private array $tasks = [];

    private bool $showDuration = true;
    private bool $showStatus = true;
    private bool $showCriticalPath = true;
    private bool $showGrid = true;
    private string $style = 'rounded';

    public function __construct(
        private ?Color $nodeColor = null,
        private ?Color $arrowColor = null,
        private ?Color $textColor = null,
        private ?Color $criticalColor = null,
        private ?Color $pendingColor = null,
        private ?Color $inProgressColor = null,
        private ?Color $completedColor = null,
        private ?Color $delayedColor = null,
    ) {}

    /**
     * Create a new PERT chart with default styling.
     */
    public static function new(): self
    {
        return new self(
            nodeColor: Color::hex('#89B4FA'),
            arrowColor: Color::hex('#45475A'),
            textColor: Color::hex('#CDD6F4'),
            criticalColor: Color::hex('#F38BA8'),
            pendingColor: Color::hex('#6C7086'),
            inProgressColor: Color::hex('#F9E2AF'),
            completedColor: Color::hex('#A6E3A1'),
            delayedColor: Color::hex('#F38BA8'),
        );
    }

    /**
     * Create a sample PERT chart for demonstration.
     */
    public static function sample(): self
    {
        $tasks = [
            (new PertTask('A', 'Planning', 3))->withDependencies([]),
            (new PertTask('B', 'Design', 5))->withDependencies(['A']),
            (new PertTask('C', 'Development', 8))->withDependencies(['B']),
            (new PertTask('D', 'Testing', 3))->withDependencies(['C']),
            (new PertTask('E', 'Deployment', 2))->withDependencies(['D']),
        ];

        return self::new()
            ->withTasks($tasks)
            ->withCriticalPath(true);
    }

    /**
     * Set the allocated dimensions for this PERT chart.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Set all tasks at once.
     *
     * @param list<PertTask> $tasks
     */
    public function withTasks(array $tasks): self
    {
        $clone = clone $this;
        $clone->tasks = $tasks;
        return $clone;
    }

    /**
     * Add a task.
     */
    public function withTask(PertTask $task): self
    {
        $clone = clone $this;
        $clone->tasks[] = $task;
        return $clone;
    }

    /**
     * Add a task by parameters.
     */
    public function addTask(string $id, string $name, int $duration, array $dependencies = []): self
    {
        return $this->withTask(
            (new PertTask($id, $name, $duration))->withDependencies($dependencies)
        );
    }

    /**
     * Show or hide duration.
     */
    public function withShowDuration(bool $show): self
    {
        $clone = clone $this;
        $clone->showDuration = $show;
        return $clone;
    }

    /**
     * Show or hide status.
     */
    public function withShowStatus(bool $show): self
    {
        $clone = clone $this;
        $clone->showStatus = $show;
        return $clone;
    }

    /**
     * Show or hide critical path.
     */
    public function withCriticalPath(bool $show): self
    {
        $clone = clone $this;
        $clone->showCriticalPath = $show;
        return $clone;
    }

    /**
     * Show or hide grid.
     */
    public function withShowGrid(bool $show): self
    {
        $clone = clone $this;
        $clone->showGrid = $show;
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

    /**
     * Find a task by ID.
     */
    private function findTask(string $id): ?PertTask
    {
        foreach ($this->tasks as $task) {
            if ($task->id === $id) {
                return $task;
            }
        }
        return null;
    }

    /**
     * Calculate topological order for rendering.
     *
     * @return list<PertTask>
     */
    private function getTopologicalOrder(): array
    {
        $inDegree = [];
        $adjList = [];

        foreach ($this->tasks as $task) {
            $inDegree[$task->id] = count($task->dependencies);
            $adjList[$task->id] = [];
        }

        foreach ($this->tasks as $task) {
            foreach ($task->dependencies as $depId) {
                if (isset($adjList[$depId])) {
                    $adjList[$depId][] = $task->id;
                }
            }
        }

        $queue = [];
        foreach ($this->tasks as $task) {
            if ($inDegree[$task->id] === 0) {
                $queue[] = $task;
            }
        }

        $result = [];
        while (!empty($queue)) {
            $task = array_shift($queue);
            $result[] = $task;

            foreach ($adjList[$task->id] as $dependentId) {
                $inDegree[$dependentId]--;
                if ($inDegree[$dependentId] === 0) {
                    $depTask = $this->findTask($dependentId);
                    if ($depTask !== null) {
                        $queue[] = $depTask;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Identify the critical path (longest path through the graph).
     *
     * @return list<string>
     */
    private function findCriticalPath(): array
    {
        $order = $this->getTopologicalOrder();
        $earliestStart = [];
        $earliestFinish = [];

        foreach ($order as $task) {
            $earliestStart[$task->id] = 0;
            foreach ($task->dependencies as $depId) {
                $earliestStart[$task->id] = max($earliestStart[$task->id], $earliestFinish[$depId] ?? 0);
            }
            $earliestFinish[$task->id] = $earliestStart[$task->id] + $task->duration;
        }

        $maxFinish = max(array_values($earliestFinish));
        $criticalPath = [];

        // Backtrack to find critical path
        $currentId = null;
        foreach ($order as $task) {
            if (($earliestFinish[$task->id] ?? 0) === $maxFinish) {
                $currentId = $task->id;
                break;
            }
        }

        while ($currentId !== null) {
            array_unshift($criticalPath, $currentId);
            $task = $this->findTask($currentId);
            if ($task === null) {
                break;
            }

            $earliestStart[$task->id] = 0;
            foreach ($task->dependencies as $depId) {
                if (isset($earliestFinish[$depId]) && $earliestFinish[$depId] === $earliestStart[$task->id]) {
                    $currentId = $depId;
                    continue 2;
                }
            }
            break;
        }

        return $criticalPath;
    }

    /**
     * Render the PERT chart as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 70;
        $useHeight = $this->height ?? 20;

        if ($useWidth < 40 || $useHeight < 10 || empty($this->tasks)) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $nodeColor = $this->nodeColor ?? Color::hex('#89B4FA');
        $arrowColor = $this->arrowColor ?? Color::hex('#45475A');
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');

        $result = '';

        // Title
        $title = 'PERT Chart';
        $titlePadding = intval(($useWidth - 2 - strlen($title)) / 2);
        $result .= $tl . str_repeat('─', $titlePadding) . $title . str_repeat('─', $useWidth - 2 - $titlePadding - strlen($title)) . $tr . "\n";

        // Get rendering order and critical path
        $order = $this->getTopologicalOrder();
        $criticalPath = $this->showCriticalPath ? $this->findCriticalPath() : [];
        $criticalSet = array_flip($criticalPath);

        $chartHeight = $useHeight - 4;
        $chartWidth = $useWidth - 4;

        // Calculate spacing
        $taskCount = count($order);
        $nodeWidth = 10;
        $nodeHeight = 5;
        $horizontalSpacing = max(3, intval(($chartWidth - $taskCount * $nodeWidth) / max(1, $taskCount + 1)));
        $verticalSpacing = max(2, intval(($chartHeight - $nodeHeight) / 2));

        // Draw tasks from left to right
        foreach ($order as $index => $task) {
            $x = 2 + $horizontalSpacing + $index * ($nodeWidth + $horizontalSpacing);
            $y = 2 + $verticalSpacing;

            $isCritical = isset($criticalSet[$task->id]);
            $taskColor = $this->getStatusColor($task->status, $isCritical);
            $useColor = $task->color ?? ($isCritical ? ($this->criticalColor ?? $nodeColor) : $nodeColor);

            // Draw task node
            $this->renderTaskNode($result, $task, $x, $nodeWidth, $y, $nodeHeight, $useColor, $textColor);

            // Draw arrow from dependencies
            foreach ($task->dependencies as $depId) {
                $depIndex = array_search($this->findTask($depId), $order, true);
                if ($depIndex !== false) {
                    $depX = 2 + $horizontalSpacing + $depIndex * ($nodeWidth + $horizontalSpacing);
                    $depY = $y + intval($nodeHeight / 2);

                    $arrowStartX = $depX + $nodeWidth;
                    $arrowStartY = $depY;
                    $arrowEndX = $x;
                    $arrowEndY = $depY;

                    if ($arrowColor !== null) {
                        $result .= $arrowColor->toFg(ColorProfile::TrueColor);
                    }

                    // Draw horizontal arrow line
                    if ($arrowStartX < $arrowEndX) {
                        $result .= str_repeat('─', intval($arrowEndX - $arrowStartX - 1)) . '►';
                    }

                    if ($arrowColor !== null) {
                        $result .= Ansi::reset();
                    }
                    $result .= "\n";
                }
            }
        }

        // Legend
        if ($this->showCriticalPath) {
            $legendLine = $v . ' ';
            $legendLine .= 'Critical Path: ';
            if ($this->criticalColor !== null) {
                $legendLine .= $this->criticalColor->toFg(ColorProfile::TrueColor);
            }
            $legendLine .= implode(' → ', $criticalPath);
            if ($this->criticalColor !== null) {
                $legendLine .= Ansi::reset();
            }
            $result .= str_repeat(' ', $useWidth - mb_strlen($legendLine, 'UTF-8') - 2) . $v . "\n";
        }

        // Bottom border
        $result .= $bl . str_repeat('─', $useWidth - 2) . $br;

        return $result;
    }

    /**
     * Get color for task status.
     */
    private function getStatusColor(PertStatus $status, bool $isCritical): Color
    {
        if ($isCritical) {
            return $this->criticalColor ?? Color::hex('#F38BA8');
        }

        return match ($status) {
            PertStatus::Pending => $this->pendingColor ?? Color::hex('#6C7086'),
            PertStatus::InProgress => $this->inProgressColor ?? Color::hex('#F9E2AF'),
            PertStatus::Completed => $this->completedColor ?? Color::hex('#A6E3A1'),
            PertStatus::Delayed => $this->delayedColor ?? Color::hex('#F38BA8'),
        };
    }

    /**
     * Render a task node.
     */
    private function renderTaskNode(
        string &$result,
        PertTask $task,
        int $x,
        int $width,
        int $y,
        int $height,
        Color $color,
        Color $textColor,
    ): void {
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        // Top border
        $result .= str_repeat(' ', $x);
        if ($color !== null) {
            $result .= $color->toFg(ColorProfile::TrueColor);
        }
        $result .= $tl . str_repeat('─', $width - 2) . $tr . "\n";

        // Task ID row
        $result .= str_repeat(' ', $x) . $v;
        $idPadding = intval(($width - 2 - strlen($task->id)) / 2);
        $result .= str_repeat(' ', $idPadding) . $task->id . str_repeat(' ', $width - 2 - $idPadding - strlen($task->id)) . $v . "\n";

        // Task name row
        $result .= str_repeat(' ', $x) . $v;
        $name = mb_substr($task->name, 0, $width - 2, 'UTF-8');
        $namePadding = intval(($width - 2 - mb_strlen($name, 'UTF-8')) / 2);
        $result .= str_repeat(' ', $namePadding) . $name . str_repeat(' ', $width - 2 - $namePadding - mb_strlen($name, 'UTF-8')) . $v . "\n";

        // Duration row
        if ($this->showDuration) {
            $result .= str_repeat(' ', $x) . $v;
            $duration = $this->showStatus
                ? $task->duration . 'd ' . $this->getStatusIndicator($task->status)
                : $task->duration . 'd';
            $duration = mb_substr($duration, 0, $width - 2, 'UTF-8');
            $durationPadding = intval(($width - 2 - mb_strlen($duration, 'UTF-8')) / 2);
            $result .= str_repeat(' ', $durationPadding) . $duration;
            $result .= str_repeat(' ', $width - 2 - $durationPadding - mb_strlen($duration, 'UTF-8')) . $v . "\n";
        }

        // Bottom border
        $result .= str_repeat(' ', $x);
        $result .= $bl . str_repeat('─', $width - 2) . $br . "\n";

        if ($color !== null) {
            $result .= Ansi::reset();
        }
    }

    /**
     * Get status indicator character.
     */
    private function getStatusIndicator(PertStatus $status): string
    {
        return match ($status) {
            PertStatus::Pending => '○',
            PertStatus::InProgress => '◐',
            PertStatus::Completed => '●',
            PertStatus::Delayed => '◌',
        };
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
     * Calculate the natural dimensions of this PERT chart.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 70;
        $height = $this->height ?? 20;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the node color.
     */
    public function withNodeColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->nodeColor = $color;
        return $clone;
    }

    /**
     * Set the arrow color.
     */
    public function withArrowColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->arrowColor = $color;
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
     * Set the critical path color.
     */
    public function withCriticalColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->criticalColor = $color;
        return $clone;
    }
}
