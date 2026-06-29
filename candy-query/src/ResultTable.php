<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;

/**
 * Renders a SQL result set as a formatted table with:
 *
 *   - horizontal scrolling for wide row sets (via offset/visibleWidth)
 *   - JSON values pretty-printed (2-space indent, sorted keys)
 *   - NULL values shown as a styled `NULL` token (not the literal string)
 *   - column auto-sizing to the widest value in the set
 *
 * Immutable — all configuration via constructor or `with*()` builders.
 *
 * @readonly
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

    /** Derived: column names from the first row. */
    private readonly array $columns;

    /** Derived: computed column widths. */
    private readonly array $colWidths;

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string,int>|null $precomputedColWidths Optional pre-computed column
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
    ) {
        $this->rows = $rows;
        $this->offset = $offset;
        $this->visibleWidth = $visibleWidth;
        $this->maxCellWidth = $maxCellWidth;
        $this->jsonPretty = $jsonPretty;
        $this->nullToken = $nullToken;
        $this->colSpacing = $colSpacing;

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
        );
    }

    public function withVisibleWidth(int $width): self
    {
        return new self(
            rows: $this->rows,
            offset: $this->offset,
            visibleWidth: max(10, $width),
            maxCellWidth: $this->maxCellWidth,
            jsonPretty: $this->jsonPretty,
            nullToken: $this->nullToken,
            colSpacing: $this->colSpacing,
            precomputedColWidths: $this->colWidths,
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
        );
    }

    // ── Queries ─────────────────────────────────────────────────────────────

    /** Number of visible columns given current offset and width. */
    public function visibleColCount(): int
    {
        if ($this->columns === []) {
            return 0;
        }
        $totalCols = count($this->columns);
        $cellWidth = $this->cellWidth();
        return max(1, (int) floor($this->visibleWidth / $cellWidth));
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
     * Produces a header row followed by data rows (up to
     * {@see DEFAULT_PAGE_SIZE}). Long cells are truncated with `…`.
     */
    public function render(): string
    {
        if ($this->rows === []) {
            return Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('(empty result set)');
        }

        $lines = [];
        $lines[] = $this->renderHeader();

        foreach (array_slice($this->rows, 0, self::DEFAULT_PAGE_SIZE) as $row) {
            $lines[] = $this->renderRow($row);
        }

        if ($this->canScrollLeft() || $this->canScrollRight()) {
            $lines[] = $this->renderScrollHint();
        }

        return implode("\n", $lines);
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

        return implode("\n", $lines);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function renderHeader(): string
    {
        $cells = [];
        foreach ($this->visibleColumns() as $col) {
            $width = $this->colWidths[$col] ?? 12;
            $cells[] = Style::new()->bold()->foreground(Color::hex('#fde68a'))
                ->render($this->pad($col, $width));
        }
        return implode(str_repeat(' ', $this->colSpacing), $cells);
    }

    private function renderRow(array $row): string
    {
        $cells = [];
        foreach ($this->visibleColumns() as $col) {
            $val = $this->formatValue($row[$col] ?? null);
            $width = $this->colWidths[$col] ?? 12;
            $cells[] = $this->pad($val, $width);
        }
        return implode(str_repeat(' ', $this->colSpacing), $cells);
    }

    private function renderHeaderPlain(): string
    {
        $cells = [];
        foreach ($this->visibleColumns() as $col) {
            $width = $this->colWidths[$col] ?? 12;
            $cells[] = $this->pad($col, $width);
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
     * Format a cell value for ANSI display.
     */
    private function formatValue(mixed $val): string
    {
        if ($val === null) {
            return Style::new()->foreground(Color::hex('#f9a8d4'))
                ->italic()
                ->render($this->nullToken);
        }

        if (is_scalar($val)) {
            return $this->maybeTruncate(CellValue::sanitize((string) $val));
        }

        // Array / object → JSON.
        $encoded = CellValue::sanitize($this->encodeJson($val));
        return Style::new()->foreground(Color::hex('#6ee7b7'))
            ->render($this->maybeTruncate($encoded));
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
     * Truncate $str to at most $this->maxCellWidth chars, adding `…`.
     */
    private function maybeTruncate(string $str): string
    {
        if (mb_strlen($str) <= $this->maxCellWidth) {
            return $str;
        }
        return mb_substr($str, 0, $this->maxCellWidth - 1) . '…';
    }

    /**
     * Right-pad $str to exactly $width characters.
     */
    private function pad(string $str, int $width): string
    {
        $diff = $width - mb_strlen($str);
        return $diff > 0 ? $str . str_repeat(' ', $diff) : $str;
    }

    /**
     * Compute per-column widths based on the full row set.
     *
     * @return array<string, int>
     */
    private function computeColWidths(): array
    {
        $widths = [];
        // Bound measurement to the rows that can actually be rendered (page size).
        // Wide values beyond row 25 no longer influence column widths.
        $renderedRows = array_slice($this->rows, 0, self::DEFAULT_PAGE_SIZE);
        foreach ($this->columns as $col) {
            $max = mb_strlen($col);
            foreach ($renderedRows as $row) {
                $val = $this->formatValuePlain($row[$col] ?? null);
                $len = mb_strlen($val);
                if ($len > $max) {
                    $max = $len;
                }
            }
            // Clamp to maxCellWidth.
            $widths[$col] = min($max, $this->maxCellWidth);
        }
        return $widths;
    }

    /** Width of a single table cell including spacing. */
    private function cellWidth(): int
    {
        // Average column width + spacing — used for visibleColCount estimate.
        if ($this->columns === []) {
            return 12 + $this->colSpacing;
        }
        return (int) (((int) array_sum($this->colWidths) / count($this->columns)) + $this->colSpacing);
    }
}
