<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * A column definition for the grid.
 */
final class Column
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?int $minWidth = null,
        public readonly ?int $maxWidth = null,
        public readonly bool $sortable = false,
        public readonly bool $filterable = false,
        public readonly ?\Closure $renderer = null,
    ) {}

    /**
     * Create a column with minimum width constraint.
     */
    public function withMinWidth(int $width): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            minWidth: $width,
            maxWidth: $this->maxWidth,
            sortable: $this->sortable,
            filterable: $this->filterable,
            renderer: $this->renderer,
        );
    }

    /**
     * Create a column with maximum width constraint.
     */
    public function withMaxWidth(int $width): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            minWidth: $this->minWidth,
            maxWidth: $width,
            sortable: $this->sortable,
            filterable: $this->filterable,
            renderer: $this->renderer,
        );
    }

    /**
     * Create a sortable column.
     */
    public function sortable(): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            minWidth: $this->minWidth,
            maxWidth: $this->maxWidth,
            sortable: true,
            filterable: $this->filterable,
            renderer: $this->renderer,
        );
    }

    /**
     * Create a filterable column.
     */
    public function filterable(): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            minWidth: $this->minWidth,
            maxWidth: $this->maxWidth,
            sortable: $this->sortable,
            filterable: true,
            renderer: $this->renderer,
        );
    }

    /**
     * Create a column with a custom renderer.
     *
     * @param callable(mixed): string $renderer
     */
    public function withRenderer(callable $renderer): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            minWidth: $this->minWidth,
            maxWidth: $this->maxWidth,
            sortable: $this->sortable,
            filterable: $this->filterable,
            renderer: $renderer,
        );
    }
}
