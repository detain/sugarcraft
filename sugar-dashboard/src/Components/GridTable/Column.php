<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\GridTable;

use Closure;
use SugarCraft\Core\Concerns\Mutable;

/**
 * A column definition for the grid.
 *
 * Seven positional fields is exactly where the house law asks carriers to
 * stop hand-rolling clones (AGENTS: >=7 positional fields use
 * sugar-core Concerns\Mutable), and the constructor is private so the
 * only way in is the named-ergonomic Column::new() factory — the old
 * public 7-arg constructor let two bools and two nullable widths be
 * silently swapped positionally (D-141).
 */
final class Column
{
    use Mutable;

    /**
     * @param callable(mixed): string|null $renderer plain callables are
     *                                               accepted and stored as Closures
     */
    public static function new(
        string $key,
        string $label,
        ?int $minWidth = null,
        ?int $maxWidth = null,
        bool $sortable = false,
        bool $filterable = false,
        ?callable $renderer = null,
    ): self {
        return new self(
            key: $key,
            label: $label,
            minWidth: $minWidth,
            maxWidth: $maxWidth,
            sortable: $sortable,
            filterable: $filterable,
            renderer: $renderer === null ? null : Closure::fromCallable($renderer),
        );
    }

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?int $minWidth = null,
        public readonly ?int $maxWidth = null,
        public readonly bool $sortable = false,
        public readonly bool $filterable = false,
        public readonly ?Closure $renderer = null,
    ) {}

    /**
     * Create a column with minimum width constraint.
     */
    public function withMinWidth(int $width): self
    {
        return $this->mutate(['minWidth' => $width]);
    }

    /**
     * Create a column with maximum width constraint.
     */
    public function withMaxWidth(int $width): self
    {
        return $this->mutate(['maxWidth' => $width]);
    }

    /**
     * Create a sortable column.
     */
    public function sortable(): self
    {
        return $this->mutate(['sortable' => true]);
    }

    /**
     * Create a filterable column.
     */
    public function filterable(): self
    {
        return $this->mutate(['filterable' => true]);
    }

    /**
     * Create a column with a custom renderer.
     *
     * The renderer keeps the house `callable` parameter type; the property
     * is a Closure, and plain callables ('strtoupper', [$obj, 'method'])
     * ride in through Closure::fromCallable instead of fataling at
     * construction the way the old ?\Closure-only seam did (D-141).
     *
     * @param callable(mixed): string $renderer
     */
    public function withRenderer(callable $renderer): self
    {
        return $this->mutate(['renderer' => Closure::fromCallable($renderer)]);
    }
}
