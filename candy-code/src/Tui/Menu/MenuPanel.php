<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Menu;

use SugarCraft\Core\Util\Width;

/**
 * One rendered menu box: its painted lines (top border first, bottom border
 * last — a windowed panel's ellipsis and filler rows live inside the box as
 * ordinary lines) and where it sits. Position is screen truth: `col` is the
 * 1-based column of the left border, `row` the 0-based screen line of the
 * top border line, so the caller paints `$panel->lines[$i]` at line
 * `$panel->row + $i` and composites later panels over earlier ones.
 *
 * `nodes` carries the DISPLAYED menu rows in order — no borders, ellipsis or
 * filler among them; the panel's content lines are [top ellipsis?] + one
 * line per node + [filler?] + [bottom ellipsis?]. The j-th node is full row
 * `firstRowIndex + j` of the panel's list, so a caller mapping mouse clicks
 * can rebuild the row's `menuitem:<dot-path>` zone (1-based
 * `[...path, firstRowIndex + j]`) exactly as {@see MenuCascade} painted it.
 *
 * @see MenuCascade::layout() produces these; nothing else should.
 */
final class MenuPanel
{
    /**
     * @param list<string>    $lines rendered panel lines, borders included
     * @param list<MenuNode>  $nodes the menu rows displayed between the borders
     * @param list<int>       $path  0-based dropdown indexes of the panels
     *                               above this one ([] for the menu bar panel)
     */
    private function __construct(
        public readonly int $col,
        public readonly int $row,
        public readonly array $lines,
        public readonly array $nodes,
        public readonly array $path,
        public readonly int $firstRowIndex,
    ) {
    }

    /**
     * @param list<string>   $lines
     * @param list<MenuNode> $nodes
     * @param list<int>      $path
     */
    public static function new(int $col, int $row, array $lines, array $nodes, array $path, int $firstRowIndex): self
    {
        return new self($col, $row, $lines, $nodes, $path, $firstRowIndex);
    }

    /**
     * VISIBLE width of the box — every line shares it, and the width law
     * caps it at cols. Mark::zone sentinels and their id bytes are click
     * metadata, not screen cells, so the marked twin measures the same
     * width as the painted one (child placement depends on it).
     *
     * The sentinel pattern is deliberately spelled `{0,}` rather than with
     * a star: a glob-shaped literal here would drift the PathGlob corpus
     * guard (plan §1.9).
     */
    public function width(): int
    {
        if ($this->lines === []) {
            return 0;
        }

        $visible = preg_replace('/\x{e000}[^\x{e000}\x{e001}]{0,}\x{e001}/u', '', $this->lines[0]) ?? $this->lines[0];

        return Width::string($visible);
    }
}
