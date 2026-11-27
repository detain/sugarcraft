<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Menu;

use SugarCraft\Core\Concerns\Mutable;

/**
 * The position of a {@see Menu} cursor as an immutable path of indices: the
 * first entry is the index on the root level, each further entry the index
 * inside the cascade opened from the entry before it. The last entry is the
 * active level, the one the up and down keys move in.
 *
 * The path is never empty: {@see new()} starts on the first root item, and
 * {@see ascend()} at the root returns the same instance. The cursor does not
 * know the tree; {@see Menu} keeps its indices in range.
 *
 * Immutable: {@see withIndex()}, {@see descend()} and {@see ascend()} return a
 * new instance through `mutate()` from sugar-core `Concerns\Mutable`, so a
 * cursor held by an older Menu never moves.
 *
 * Original SugarCraft work: charmbracelet/bubbles has no menu component,
 * so there is no upstream Mirrors citation.
 */
final class MenuCursor
{
    use Mutable;

    /**
     * @param non-empty-list<int> $path
     */
    private function __construct(
        public readonly array $path,
    ) {
    }

    /** On the first root item, with no cascade open. */
    public static function new(): self
    {
        return new self([0]);
    }

    /** @return non-empty-list<int> */
    public function path(): array
    {
        return $this->path;
    }

    /** 0 on the root level, 1 inside the first cascade, and so on. */
    public function depth(): int
    {
        return count($this->path) - 1;
    }

    /** The index on the active level. */
    public function index(): int
    {
        return $this->path[count($this->path) - 1];
    }

    /** Moves on the active level; a negative index is raised to 0. */
    public function withIndex(int $index): self
    {
        $path = $this->path;
        $path[count($path) - 1] = max(0, $index);
        return $this->mutate(['path' => $path]);
    }

    /** One level deeper, on the first item of the cascade. */
    public function descend(): self
    {
        return $this->mutate(['path' => [...$this->path, 0]]);
    }

    /** Back to the parent level, keeping its index; the root stays as it is. */
    public function ascend(): self
    {
        if (count($this->path) === 1) {
            return $this;
        }
        return $this->mutate(['path' => array_slice($this->path, 0, -1)]);
    }
}
