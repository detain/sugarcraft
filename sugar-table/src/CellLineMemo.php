<?php

declare(strict_types=1);

namespace SugarCraft\Table;

/**
 * Per-render memo for wrapped cell lines (audit ST-32).
 *
 * A multiline render measures each row's height and then paints it in two
 * passes, and both passes call {@see Column::renderCell()} with the same
 * sanitized text and the same column width: every wrapped cell was computed
 * twice per frame. This store lets the first pass record its output and the
 * second pass reuse it byte-identically.
 *
 * The lifetime is strictly ONE {@see Table::view()} call — the memo is created
 * at the top of the render pass and goes out of scope when the pass returns.
 * Nothing is cached across frames, so a cell value, a column width or a
 * marked-frame sanitization change takes effect on the very next render; there
 * is no stale-across-frames state to invalidate.
 *
 * Keys are the Column instance (by id) and the effective width; the value is
 * the per-row lines in rendered order. Wrapping is the column's behavior and
 * the two passes iterate the same `$rows` viewport slice, so row position —
 * not row identity — is the pairing contract: a replayed group cannot be
 * mispaired, and storing the column by id with a strong anchor rules out an
 * id recycled mid-pass.
 *
 * @internal Layout plumbing for {@see Table::view()}; not public API.
 */
final class CellLineMemo
{
    /** @var array<int, array<int, list<list<string>>>> spl_object_id(Column) => width => per-row lines (rendered order) */
    private array $entries = [];

    /** @var array<int, array<int, bool>> (column, width) groups the first pass closed out */
    private array $complete = [];

    /** @var array<int, Column> strong anchors so stored ids cannot be recycled mid-pass */
    private array $columnAnchors = [];

    /** @var array<int, array<int, int>> per-group replay cursor (meaningful only once the group is complete) */
    private array $cursors = [];

    /**
     * Record one cell's wrapped lines under (column, width), appended in
     * rendered-row order. The group may be replayed only after
     * {@see self::complete()} closed it out.
     *
     * @param list<string> $lines
     */
    public function store(Column $column, int $width, array $lines): void
    {
        $cid = \spl_object_id($column);
        $this->columnAnchors[$cid] ??= $column;
        $this->entries[$cid][$width][] = $lines;
    }

    /** Close out a (column, width) group whose per-row recording is finished. */
    public function complete(Column $column, int $width): void
    {
        $this->complete[\spl_object_id($column)][$width] = true;
    }

    /**
     * Return the next recorded line-group for this (column, width) group in
     * store order, or null when the group is not complete or exhausted.
     *
     * The paint pass consumes exactly the rows the height pass recorded, in
     * the same column-major visit order (renderToBuffer routes expanded rows
     * to fillExpandedRow on both passes), so each replayNext() hands back the
     * lines belonging to the cell being painted.
     *
     * @return list<string>|null
     */
    public function replayNext(Column $column, int $width): ?array
    {
        $cid = \spl_object_id($column);
        if (($this->complete[$cid][$width] ?? false) !== true) {
            return null;
        }

        $pos = $this->cursors[$cid][$width] ?? 0;
        $entries = $this->entries[$cid][$width] ?? [];
        if ($pos >= \count($entries)) {
            return null; // exhausted: the caller wraps afresh (symmetry means never)
        }

        $this->cursors[$cid][$width] = $pos + 1;

        return $entries[$pos];
    }
}
