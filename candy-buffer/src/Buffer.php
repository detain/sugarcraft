<?php

declare(strict_types=1);

namespace SugarCraft\Buffer;

/**
 * A 2-D grid of terminal cells with immutable mutation semantics.
 *
 * Buffer is the core data model for rendering: it represents a rectangular
 * slice of a terminal with per-cell rune, style, hyperlink, and width
 * information. All mutation happens through fluent with*() methods that
 * return new Buffer instances — the original is never modified.
 *
 * Mirrors charmbracelet/vte's Buffer and charmbracelet/lipgloss's
 * Style as the foundation for terminal rendering.
 */
final class Buffer
{
    /**
     * @param list<Cell> $grid Flat grid: index = $row * $width + $col
     */
    private function __construct(
        private readonly int $width,
        private readonly int $height,
        private readonly array $grid,
    ) {}

    /**
     * Default factory — creates a width×height grid of blank cells.
     */
    public static function new(int $width, int $height): self
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException(
                'Buffer dimensions must be positive'
            );
        }
        $size = $width * $height;
        $blank = Cell::new();
        $grid = array_fill(0, $size, $blank);

        return new self($width, $height, $grid);
    }

    // ─── Accessors ─────────────────────────────────────────────────────

    /** Width in cells. */
    public function width(): int { return $this->width; }

    /** Height in cells. */
    public function height(): int { return $this->height; }

    /** Bounding region of the entire buffer. */
    public function region(): Region
    {
        return new Region(Position::new(0, 0), $this->width, $this->height);
    }

    /**
     * Bounds-checked cell accessor.
     *
     * @throws \OutOfRangeException when $col or $row is outside the grid
     */
    public function cellAt(int $col, int $row): Cell
    {
        $this->assertInBounds($col, $row);

        return $this->grid[$row * $this->width + $col];
    }

    // ─── Immutable mutations ─────────────────────────────────────────────

    /**
     * Return a new Buffer with $cell placed at ($col, $row).
     *
     * @throws \OutOfRangeException when coordinates are outside the grid
     */
    public function withCellAt(int $col, int $row, Cell $cell): self
    {
        $this->assertInBounds($col, $row);

        $grid = $this->grid;
        $grid[$row * $this->width + $col] = $cell;

        return $this->mutate(['grid' => $grid]);
    }

    /**
     * Blit (composite) $source into this buffer's $region.
     *
     * Cells from $source are copied into the region defined by
     * $region->origin and $region->size, clipped to buffer edges.
     *
     * @todo step-26 — add dirty tracking for SGR-transition optimisation
     */
    public function withRegion(Region $region, Buffer $source): self
    {
        $grid = $this->grid;
        $srcW = $source->width;
        $srcH = $source->height;

        for ($dy = 0; $dy < $region->height; $dy++) {
            for ($dx = 0; $dx < $region->width; $dx++) {
                $srcCol = $dx;
                $srcRow = $dy;

                if ($srcCol >= $srcW || $srcRow >= $srcH) {
                    continue;
                }

                $dstCol = $region->origin->col + $dx;
                $dstRow = $region->origin->row + $dy;

                if ($dstCol >= $this->width || $dstRow >= $this->height) {
                    continue;
                }

                $grid[$dstRow * $this->width + $dstCol] = $source->cellAt($srcCol, $srcRow);
            }
        }

        return $this->mutate(['grid' => $grid]);
    }

    // ─── Diff ───────────────────────────────────────────────────────────

    /**
     * Compare this buffer against $previous and return the list of
     * operations needed to transform $previous into this buffer.
     *
     * @todo step-26 — implement the delta-ANSI emitter (ECH/REP/ICH/DCH)
     * @return list<DiffOp>
     */
    public function diff(Buffer $previous): array
    {
        return [];
    }

    // ─── Internals ─────────────────────────────────────────────────────

    /**
     * @return static
     */
    private function mutate(array $changes): static
    {
        return new static(...array_merge(
            ['width' => $this->width, 'height' => $this->height, 'grid' => $this->grid],
            $changes,
        ));
    }

    /**
     * @throws \OutOfRangeException
     */
    private function assertInBounds(int $col, int $row): void
    {
        if ($col < 0 || $col >= $this->width || $row < 0 || $row >= $this->height) {
            throw new \OutOfRangeException(
                "Cell ({$col}, {$row}) is out of buffer bounds ({$this->width}x{$this->height})"
            );
        }
    }
}
