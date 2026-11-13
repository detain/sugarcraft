<?php

declare(strict_types=1);

namespace SugarCraft\Sql;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

/**
 * Renders a SQL result set as a formatted table with:
 *
 *   - horizontal scrolling for wide row sets (via offset/visibleWidth)
 *   - JSON values pretty-printed (2-space indent, sorted keys)
 *   - NULL values shown as a styled `NULL` token (not the literal string)
 *   - column auto-sizing to the widest value in the set
 *
 * Value semantics — all configuration via constructor or clone-based
 * `with*()` builders. The paging window (`rowOffset`, `viewportRows`,
 * `cursorRow`) are private fields assigned inside the constructor and
 * inside those builders' fresh clones, so external callers still only
 * ever observe immutable transitions (no class-level @readonly: that
 * would lie about those three).
 */
final class ResultTable
{
    /** Default NULL display token. */
    public const NULL_TOKEN = 'NULL';

    /** Default maximum cell width before truncation. */
    public const DEFAULT_MAX_CELL = 40;

    /** Default number of rows shown per page. */
    public const DEFAULT_PAGE_SIZE = 25;

    /**
     * @readonly
     * @var list<array<string,mixed>>
     */
    public readonly array $rows;

    /** @readonly */
    public readonly int $offset;

    /** @readonly */
    public readonly int $visibleWidth;

    /** @readonly */
    public readonly int $maxCellWidth;

    /** @readonly */
    public readonly bool $jsonPretty;

    /** @readonly */
    public readonly string $nullToken;

    /** @readonly */
    public readonly int $colSpacing;

    /** First row index of the rendered window (D-C2 vertical paging). */
    private int $rowOffset = 0;

    /** Total-line render budget in rows (D-C06); 0 = unbounded legacy page. */
    private int $viewportRows = 0;

    /** Row index the cursor sits on (D-C06); -1 = unknown → rowOffset rules. */
    private int $cursorRow = -1;

    /** Column the cell cursor highlights in the cursor row (SQL-O03); -1 = no highlight. */
    private int $cursorCol = -1;

    /** Derived: column names from the first row. */
    private readonly array $columns;

    /** Derived: computed column widths. */
    private readonly array $colWidths;

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,int>|null $precomputedColWidths Optional pre-computed column
     *        widths from an existing instance (used by with* builders that don't change
     *        rows to avoid recomputation). When provided, bypasses computeColWidths().
     */
    public function __construct(
        array $rows = [],
        int $offset = 0,
        int $visibleWidth = 120,
        int $maxCellWidth = self::DEFAULT_MAX_CELL,
        bool $jsonPretty = true,
        string $nullToken = self::NULL_TOKEN,
        int $colSpacing = 2,
        ?array $precomputedColWidths = null,
        int $rowOffset = 0,
    ) {
        $this->rows = $rows;
        $this->offset = $offset;
        $this->visibleWidth = $visibleWidth;
        $this->maxCellWidth = $maxCellWidth;
        $this->jsonPretty = $jsonPretty;
        $this->nullToken = $nullToken;
        $this->colSpacing = $colSpacing;
        $this->rowOffset = max(0, $rowOffset);

        // Stringify column names: a numeric column label (e.g. `SELECT 1`)
        // becomes an integer PHP array key, which would break the string ops
        // below. Casting keeps width/pad/lookup uniform ($row["1"] still hits
        // the int key 1 thanks to PHP key normalization).
        $this->columns = $rows === [] ? [] : array_map('strval', array_keys($rows[0]));
        // Use pre-computed widths when passed (memoization via with* builders that
        // don't change rows), otherwise compute fresh (bounded to rendered page size).
        $this->colWidths = $precomputedColWidths ?? $this->computeColWidths();
    }

    // ── Factories ──────────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $rows
     */
    public static function fromRows(array $rows): self
    {
        return new self(rows: $rows);
    }

    // ── Fluents ───────────────────────────────────────────────────────────────

    /** Scroll left by one column. */
    public function scrollLeft(): self
    {
        return new self(
            rows: $this->rows,
            offset: max(0, $this->offset - 1),
            visibleWidth: $this->visibleWidth,
            maxCellWidth: $this->maxCellWidth,
            jsonPretty: $this->jsonPretty,
            nullToken: $this->nullToken,
            colSpacing: $this->colSpacing,
            precomputedColWidths: $this->colWidths,
            rowOffset: $this->rowOffset,
        );
    }

    /** Scroll right by one column. */
    public function scrollRight(): self
    {
        $maxOffset = max(0, count($this->columns) - $this->visibleColCount());
        return new self(
            rows: $this->rows,
            offset: min($maxOffset, $this->offset + 1),
            visibleWidth: $this->visibleWidth,
            maxCellWidth: $this->maxCellWidth,
            jsonPretty: $this->jsonPretty,
            nullToken: $this->nullToken,
            colSpacing: $this->colSpacing,
            precomputedColWidths: $this->colWidths,
            rowOffset: $this->rowOffset,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function withRows(array $rows): self
    {
        return new self(
            rows: $rows,
            offset: $this->offset,
            visibleWidth: $this->visibleWidth,
            maxCellWidth: $this->maxCellWidth,
            jsonPretty: $this->jsonPretty,
            nullToken: $this->nullToken,
            colSpacing: $this->colSpacing,
            // rows changed — must recompute colWidths
        );
    }

    /**
     * Move the vertical render window (D-C2). The window always spans at
     * most DEFAULT_PAGE_SIZE rows (or the viewport budget from
     * {@see self::withViewport()}, whichever is tighter). D-C06: the live
     * caller no longer page-aligns the offset — it hands the cursor to
     * withViewport() so the window centres on it; a plain rowOffset still
     * windows from the top of that page when no cursor is given.
     */
    public function withRowOffset(int $rowOffset): self
    {
        return new self(
            rows: $this->rows,
            offset: $this->offset,
            visibleWidth: $this->visibleWidth,
            maxCellWidth: $this->maxCellWidth,
            jsonPretty: $this->jsonPretty,
            nullToken: $this->nullToken,
            colSpacing: $this->colSpacing,
            precomputedColWidths: $this->colWidths,
            rowOffset: max(0, $rowOffset),
        );
    }

    /**
     * Hand the renderer the pane's height budget and the cursor's row index
     * (D-C06). The unconditional 25-row page overflowed short panes: the
     * sugar-cli Frame clipped everything below the budget away — the row
     * counter, the help line and the query pane went dark, and the
     * page-aligned window could park the cursor itself inside the clipped
     * band. With a budget the window shrinks to fit (header, scroll hint and
     * row counter all live inside the envelope) and slides to keep the cursor
     * centred.
     */
    public function withViewport(int $viewportRows, int $cursorRow = -1): self
    {
        $new = new self(
            rows: $this->rows,
            offset: $this->offset,
            visibleWidth: $this->visibleWidth,
            maxCellWidth: $this->maxCellWidth,
            jsonPretty: $this->jsonPretty,
            nullToken: $this->nullToken,
            colSpacing: $this->colSpacing,
            precomputedColWidths: $this->colWidths,
            rowOffset: $this->rowOffset,
        );
        $new->viewportRows = max(0, $viewportRows);
        $new->cursorRow = $cursorRow;
        return $new;
    }

    /**
     * Scroll just far enough that column $col is inside the visible window
     * (SQL-O03: the cell cursor drives the horizontal scroll). A no-op when
     * the column is already shown; the offset never overshoots it.
     */
    public function revealColumn(int $col): self
    {
        $col = max(0, min($col, count($this->columns) - 1));
        $table = $this;
        if ($col < $table->offset) {
            return $table->withOffset($col);
        }
        while ($col >= $table->offset + $table->visibleColCount() && $table->offset < $col) {
            $table = $table->withOffset($table->offset + 1);
        }

        return $table;
    }

    /**
     * Highlight column $col of the cursor row as the cell cursor (reverse
     * video) and scroll it into view. Pass -1 for no highlight. Render-time
     * state like the viewport: the other withers do not carry it, so the
     * renderer re-applies it each frame from the browse state.
     */
    public function withCursorCol(int $col): self
    {
        $base = $col >= 0 ? $this->revealColumn($col) : $this;
        $new = new self(
            rows: $base->rows,
            offset: $base->offset,
            visibleWidth: $base->visibleWidth,
            maxCellWidth: $base->maxCellWidth,
            jsonPretty: $base->jsonPretty,
            nullToken: $base->nullToken,
            colSpacing: $base->colSpacing,
            precomputedColWidths: $base->colWidths,
            rowOffset: $base->rowOffset,
        );
        $new->viewportRows = $this->viewportRows;
        $new->cursorRow = $this->cursorRow;
        $new->cursorCol = $col;

        return $new;
    }

    public function withOffset(int $offset): self
    {
        return new self(
            rows: $this->rows,
            offset: max(0, $offset),
            visibleWidth: $this->visibleWidth,
            maxCellWidth: $this->maxCellWidth,
            jsonPretty: $this->jsonPretty,
            nullToken: $this->nullToken,
            colSpacing: $this->colSpacing,
            precomputedColWidths: $this->colWidths,
            rowOffset: $this->rowOffset,
        );
    }

    public function withVisibleWidth(int $width): self
    {
        return new self(
            rows: $this->rows,
            // D-M23: keep the offset inside the column list when the pane
            // width (and with it the fitting window) shrinks — a stranded
            // offset used to render an empty tail window.
            offset: min($this->offset, max(0, count($this->columns) - 1)),
            visibleWidth: max(10, $width),
            maxCellWidth: $this->maxCellWidth,
            jsonPretty: $this->jsonPretty,
            nullToken: $this->nullToken,
            colSpacing: $this->colSpacing,
            precomputedColWidths: $this->colWidths,
            rowOffset: $this->rowOffset,
        );
    }

    public function withJsonPretty(bool $pretty): self
    {
        return new self(
            rows: $this->rows,
            offset: $this->offset,
            visibleWidth: $this->visibleWidth,
            maxCellWidth: $this->maxCellWidth,
            jsonPretty: $pretty,
            nullToken: $this->nullToken,
            colSpacing: $this->colSpacing,
            precomputedColWidths: $this->colWidths,
            rowOffset: $this->rowOffset,
        );
    }

    public function withNullToken(string $token): self
    {
        return new self(
            rows: $this->rows,
            offset: $this->offset,
            visibleWidth: $this->visibleWidth,
            maxCellWidth: $this->maxCellWidth,
            jsonPretty: $this->jsonPretty,
            nullToken: $token,
            colSpacing: $this->colSpacing,
            precomputedColWidths: $this->colWidths,
            rowOffset: $this->rowOffset,
        );
    }

    // ── Queries ─────────────────────────────────────────────────────────────

    /** Number of visible columns given current offset and width. */
    public function visibleColCount(): int
    {
        if ($this->columns === []) {
            return 0;
        }
        // D-M23: the old estimate divided the pane by the AVERAGE column
        // width, so an uneven table (one wide column among narrow ones)
        // rendered past the pane it was measured for. Fit cumulatively:
        // take columns from the offset until the next one no longer fits.
        // The column at the offset is always shown (clipped by the pane if
        // wider than it), so the result is never below 1.
        $used = 0;
        $count = 0;
        $total = count($this->columns);
        for ($i = $this->offset; $i < $total; $i++) {
            $width = ($this->colWidths[$this->columns[$i]] ?? 12) + ($count > 0 ? $this->colSpacing : 0);
            if ($count > 0 && $used + $width > $this->visibleWidth) {
                break;
            }
            $used += $width;
            $count++;
        }
        return max(1, $count);
    }

    /** Whether left-scroll is possible. */
    public function canScrollLeft(): bool
    {
        return $this->offset > 0;
    }

    /** Whether right-scroll is possible. */
    public function canScrollRight(): bool
    {
        return ($this->offset + $this->visibleColCount()) < count($this->columns);
    }

    /** All column names (full set, not just visible). */
    public function columns(): array
    {
        return $this->columns;
    }

    /** Width of each column in characters. */
    public function colWidths(): array
    {
        return $this->colWidths;
    }

    /**
     * Visible columns given current scroll offset.
     *
     * @return list<string>
     */
    public function visibleColumns(): array
    {
        return array_slice($this->columns, $this->offset, $this->visibleColCount());
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    /**
     * Render the table as ANSI-coloured string.
     *
     * Produces a header row followed by the row window (at most
     * {@see DEFAULT_PAGE_SIZE} rows, tightened by the viewport budget of
     * {@see self::withViewport()} — D-C06). Long cells are truncated with `…`.
     */
    public function render(): string
    {
        if ($this->rows === []) {
            return Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('(empty result set)');
        }

        $budget = $this->rowBudget();
        $windowStart = $this->windowStart($budget);
        $window = array_slice($this->rows, $windowStart, $budget);

        $lines = [];
        $lines[] = $this->renderHeader();

        foreach ($window as $i => $row) {
            $lines[] = $this->renderRow($row, $windowStart + $i === $this->cursorRow);
        }

        if ($this->canScrollLeft() || $this->canScrollRight()) {
            $lines[] = $this->renderScrollHint();
        }

        $totalRows = count($this->rows);
        if ($totalRows > count($window) || $windowStart > 0) {
            $start = $windowStart + 1;
            $end = $windowStart + count($window);
            $lines[] = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render("rows {$start}–{$end} of {$totalRows}");
        }

        return implode("\n", $lines);
    }

    /**
     * Data-row lines renderable inside the viewport (D-C06): three lines are
     * reserved for the header, the scroll hint and the row counter so the
     * whole render fits the pane envelope; unbounded (legacy 25-row page)
     * when no viewport was set.
     */
    private function rowBudget(): int
    {
        if ($this->viewportRows <= 0) {
            return self::DEFAULT_PAGE_SIZE;
        }
        return min(self::DEFAULT_PAGE_SIZE, max(1, $this->viewportRows - 3));
    }

    /**
     * First row index of the render window (D-C06): a known cursor is
     * centred — the old page-aligned `intdiv(rowCursor, 25) * 25` window
     * could park the cursor inside the clipped band — otherwise the caller's
     * {@see self::withRowOffset()} page stands.
     */
    private function windowStart(int $budget): int
    {
        if ($this->cursorRow >= 0) {
            $maxStart = max(0, count($this->rows) - $budget);
            return max(0, min($this->cursorRow - intdiv($budget - 1, 2), $maxStart));
        }
        return max(0, $this->rowOffset);
    }

    /**
     * Render the table to a plain-text string (no ANSI) for export/copy.
     */
    public function renderPlain(): string
    {
        if ($this->rows === []) {
            return '(empty result set)';
        }

        $lines = [];
        $lines[] = $this->renderHeaderPlain();

        foreach (array_slice($this->rows, 0, self::DEFAULT_PAGE_SIZE) as $row) {
            $lines[] = $this->renderRowPlain($row);
        }

        $totalRows = count($this->rows);
        if ($totalRows > self::DEFAULT_PAGE_SIZE) {
            $start = 1;
            $end = min(self::DEFAULT_PAGE_SIZE, $totalRows);
            $lines[] = "rows {$start}–{$end} of {$totalRows}";
        }

        return implode("\n", $lines);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function renderHeader(): string
    {
        $cells = [];
        foreach ($this->visibleColumns() as $col) {
            $width = $this->colWidths[$col] ?? 12;
            // Header names arrive from the result-set wire — sanitize the label.
            $cells[] = Style::new()->bold()->foreground(Color::hex('#fde68a'))
                ->render($this->pad(CellValue::sanitize($col), $width));
        }
        return implode(str_repeat(' ', $this->colSpacing), $cells);
    }

    private function renderRow(array $row, bool $isCursorRow = false): string
    {
        $cells = [];
        $colIndex = $this->offset;
        foreach ($this->visibleColumns() as $col) {
            $width = $this->colWidths[$col] ?? 12;
            if ($isCursorRow && $colIndex === $this->cursorCol) {
                // The cell cursor is plain padded text under reverse video:
                // nesting it inside the NULL/JSON colour styling would end the
                // reverse run at the inner reset (D-C10 padding law).
                $cells[] = Style::new()->reverse()->render($this->pad($this->formatValuePlain($row[$col] ?? null), $width));
            } else {
                $cells[] = $this->styledCell($row[$col] ?? null, $width);
            }
            $colIndex++;
        }
        return implode(str_repeat(' ', $this->colSpacing), $cells);
    }

    private function renderHeaderPlain(): string
    {
        $cells = [];
        foreach ($this->visibleColumns() as $col) {
            $width = $this->colWidths[$col] ?? 12;
            $cells[] = $this->pad(CellValue::sanitize($col), $width);
        }
        return implode(str_repeat(' ', $this->colSpacing), $cells);
    }

    private function renderRowPlain(array $row): string
    {
        $cells = [];
        foreach ($this->visibleColumns() as $col) {
            $val = $this->formatValuePlain($row[$col] ?? null);
            $width = $this->colWidths[$col] ?? 12;
            $cells[] = $this->pad($val, $width);
        }
        return implode(str_repeat(' ', $this->colSpacing), $cells);
    }

    private function renderScrollHint(): string
    {
        $left  = $this->canScrollLeft() ? '◀' : ' ';
        $right = $this->canScrollRight() ? '▶' : ' ';
        $total = count($this->columns);
        $start = $this->offset + 1;
        $end   = min($total, $this->offset + $this->visibleColCount());
        $label = "cols {$start}–{$end} of {$total}";

        return Style::new()->foreground(Color::hex('#7d6e98'))
            ->render("{$left}  {$label}  {$right}");
    }

    /**
     * Format a cell value for ANSI display: plain text is truncated and
     * padded to the measured column width FIRST, styling wraps the padded
     * run (D-C10 — padding the styled string counted the SGR bytes as
     * cells, so NULL/JSON columns desynced every row below them).
     */
    private function styledCell(mixed $val, int $width): string
    {
        if ($val === null) {
            return Style::new()->foreground(Color::hex('#f9a8d4'))
                ->italic()
                ->render($this->pad($this->nullToken, $width));
        }

        if (is_scalar($val)) {
            return $this->pad($this->maybeTruncate(CellValue::sanitize((string) $val)), $width);
        }

        // Array / object → JSON.
        $encoded = CellValue::sanitize($this->encodeJson($val));
        return Style::new()->foreground(Color::hex('#6ee7b7'))
            ->render($this->pad($this->maybeTruncate($encoded), $width));
    }

    /**
     * Format a cell value as plain text (no ANSI).
     */
    private function formatValuePlain(mixed $val): string
    {
        if ($val === null) {
            return $this->nullToken;
        }

        if (is_scalar($val)) {
            return $this->maybeTruncate(CellValue::sanitize((string) $val));
        }

        return $this->maybeTruncate(CellValue::sanitize($this->encodeJson($val)));
    }

    /**
     * Encode a value to JSON with optional pretty-print.
     */
    private function encodeJson(mixed $val): string
    {
        $flags = JSON_THROW_ON_ERROR;
        if ($this->jsonPretty) {
            $flags |= JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        }

        $json = json_encode($val, $flags);

        // Collapse multi-line pretty-printed JSON to single line for
        // narrow columns (or expand if wide enough).
        if ($this->jsonPretty && $this->visibleWidth >= 80) {
            return $json;
        }

        // For narrow widths, collapse to one line.
        return preg_replace('/\s+/', ' ', $json) ?: $json;
    }

    /**
     * Truncate $str to at most $this->maxCellWidth DISPLAY CELLS, adding `…`.
     * C07: measured in cells, not codepoints — CJK/emoji no longer count 1.
     */
    private function maybeTruncate(string $str): string
    {
        if (Width::of($str) <= $this->maxCellWidth) {
            return $str;
        }
        return Width::truncate($str, $this->maxCellWidth - 1) . '…';
    }

    /**
     * Lay $str on exactly $width display cells (C07): right-pad with spaces,
     * and left-clip with a truncation when the value is wider than the
     * column — the old code only right-padded, so an oversized cell
     * overflowed the grid and the frame desynced on wide glyphs.
     */
    private function pad(string $str, int $width): string
    {
        if (Width::of($str) > $width) {
            return Width::truncate($str, $width);
        }
        return Width::padRight($str, $width);
    }

    /**
     * Compute per-column widths over the FULL row set.
     *
     * C07: measurement used to sample page 0 only while render() slices its
     * window from the cursor/rowOffset — rows on page 2 spilled outside the
     * column they were painted in. maxCellWidth still clamps, so a single
     * blob-sized value cannot blow the grid up.
     *
     * @return array<string, int>
     */
    private function computeColWidths(): array
    {
        $widths = [];
        foreach ($this->columns as $col) {
            // Measure the sanitized label so widths match what is painted.
            $max = Width::of(CellValue::sanitize($col));
            foreach ($this->rows as $row) {
                $val = $this->formatValuePlain($row[$col] ?? null);
                $len = Width::of($val);
                if ($len > $max) {
                    $max = $len;
                }
            }
            // Clamp to maxCellWidth.
            $widths[$col] = min($max, $this->maxCellWidth);
        }
        return $widths;
    }

    // D-M23: the old private cellWidth() (average column width) is gone with
    // its only consumer; visibleColCount() now fits columns cumulatively.
}
