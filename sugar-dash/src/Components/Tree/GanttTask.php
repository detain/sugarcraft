<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

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
