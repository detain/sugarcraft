<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

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
