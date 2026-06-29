<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Table;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * Sortable, filterable data table renderer.
 *
 * Renders a list of rows (each row is a list of string values) into a
 * formatted table with column headers, alignment, sorting, and filtering.
 *
 * Port of 76creates/stickers Table.
 *
 * @see https://github.com/76creates/stickers
 *
 * NOTE: Diff-based emission has moved to TableRenderer. Call TableRenderer::render()
 * for bandwidth-efficient delta output. Table::render() always returns the full frame.
 */
final class Table
{
    /** @var list<Column> */
    private array $columns = [];

    /**
     * Canonical, unfiltered, original-insertion-order rows.
     * Source of truth — only mutated by addRow().
     *
     * @var list<list<string>>
     */
    private array $allRows = [];

    /**
     * Visible row view: $allRows with the current sort + filter applied.
     * Rebuilt by rebuildView() whenever sort or filter state changes.
     *
     * @var list<list<string>>
     */
    private array $rows = [];

    private int $sortColIndex = -1;
    private bool $sortAscending = true;
    private string $filterText = '';
    private int $cursorRow = 0;

    /** Style for cursor row (ANSI string). */
    private string $cursorStyle = '';

    /** Style for header row (ANSI string). */
    private string $headerStyle = '';

    /** Separator character between cells. */
    private string $separator = ' │ ';

    private int $totalWidth = 0;

    public function addColumn(Column $col): self
    {
        $clone = clone $this;
        $clone->columns[] = $col;
        $clone->totalWidth = $clone->computeTotalWidth();
        return $clone;
    }

    /** Add a row (values are matched to columns by index). */
    public function addRow(array $values): self
    {
        $clone = clone $this;
        $clone->allRows[] = \array_values(\array_map('strval', $values));
        $clone->rebuildView();
        return $clone;
    }

    public function sortBy(int $colIndex, bool $ascending = true): self
    {
        $clone = clone $this;
        $clone->sortColIndex = $colIndex;
        $clone->sortAscending = $ascending;
        $clone->rebuildView();
        return $clone;
    }

    public function sortByNext(int $colIndex): self
    {
        if ($this->sortColIndex === $colIndex) {
            $clone = $this->sortBy($colIndex, !$this->sortAscending);
        } else {
            $clone = $this->sortBy($colIndex, true);
        }
        return $clone;
    }

    public function filter(string $text): self
    {
        $clone = clone $this;
        $clone->filterText = $text;
        $clone->rebuildView();
        return $clone;
    }

    public function clearFilter(): self
    {
        return $this->filter('');
    }

    public function setCursor(int $rowIndex): self
    {
        $clone = clone $this;
        $clone->cursorRow = $rowIndex;
        return $clone;
    }

    public function withCursorStyle(string $ansiStyle): self
    {
        $clone = clone $this;
        $clone->cursorStyle = $ansiStyle;
        return $clone;
    }

    public function withHeaderStyle(string $ansiStyle): self
    {
        $clone = clone $this;
        $clone->headerStyle = $ansiStyle;
        return $clone;
    }

    public function withSeparator(string $s): self
    {
        $clone = clone $this;
        $clone->separator = $s;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    public function rowCount(): int
    {
        return \count($this->rows);
    }

    public function colCount(): int
    {
        return \count($this->columns);
    }

    public function currentRow(): ?array
    {
        $idx = $this->cursorRow;
        if ($idx < 0 || $idx >= \count($this->rows)) {
            return null;
        }
        return $this->rows[$idx];
    }

    public function currentCell(int $colIndex): ?string
    {
        $row = $this->currentRow();
        return $row[$colIndex] ?? null;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render the table to a full string (always full frame, never delta).
     *
     * For bandwidth-efficient delta emission, use TableRenderer::render()
     * which tracks diff state across frames.
     */
    public function render(): string
    {
        return \implode("\n", $this->buildLines());
    }

    /**
     * Build all rendered lines (header, separator, data rows).
     *
     * @return list<string>
     */
    public function buildLines(): array
    {
        if ($this->columns === []) {
            return [];
        }

        $lines = [];

        // Header
        $headerCols = [];
        foreach ($this->columns as $ci => $col) {
            $title = $col->sortDir() !== 0
                ? ($col->sortDir() > 0 ? '▲ ' : '▼ ') . $col->title
                : $col->title;

            $titleStr = $this->padHeader($title, $col->width, $col->align);
            if ($this->headerStyle !== '') {
                $titleStr = $this->applyStyle($titleStr, $this->headerStyle);
            }
            $headerCols[] = $titleStr;
        }
        $lines[] = \implode($this->separator, $headerCols);

        // Separator line
        $sepParts = [];
        foreach ($this->columns as $col) {
            $sepParts[] = \str_repeat('─', $col->width);
        }
        $lines[] = \implode('─┬─', $sepParts);

        // Data rows
        foreach ($this->rows as $ri => $row) {
            $isCursor = ($ri === $this->cursorRow);
            $rowCols  = [];

            foreach ($this->columns as $ci => $col) {
                $val = $row[$ci] ?? '';
                $cellStr = $col->padded($val, $ri);

                if ($isCursor && $this->cursorStyle !== '') {
                    $cellStr = $this->applyStyle($cellStr, $this->cursorStyle);
                }

                $rowCols[] = $cellStr;
            }

            $lines[] = \implode($this->separator, $rowCols);
        }

        return $lines;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Rebuild the visible $rows view from $allRows by applying the current
     * sort and filter state. Called whenever rows, sort, or filter change so
     * that filter('') (clearFilter) restores the full set instead of operating
     * on already-shrunk data.
     */
    private function rebuildView(): void
    {
        $rows = $this->allRows;

        if ($this->sortColIndex >= 0 && $this->sortColIndex < \count($this->columns)) {
            $colIdx = $this->sortColIndex;
            $asc    = $this->sortAscending;

            \usort($rows, function (array $a, array $b) use ($colIdx, $asc): int {
                $va = $a[$colIdx] ?? '';
                $vb = $b[$colIdx] ?? '';

                $na = \is_numeric($va) ? (float) $va : null;
                $nb = \is_numeric($vb) ? (float) $vb : null;

                if ($na !== null && $nb !== null) {
                    $cmp = $na <=> $nb;
                } else {
                    $cmp = \strcasecmp((string) $va, (string) $vb);
                }

                return $asc ? $cmp : -$cmp;
            });
        }

        $this->rows = $this->applyFilter($rows);
        $this->cursorRow = 0;
    }

    /**
     * @param list<list<string>> $rows
     * @return list<list<string>>
     */
    private function applyFilter(array $rows): array
    {
        if ($this->filterText === '') {
            return $rows;
        }
        $lower = \strtolower($this->filterText);
        return \array_values(
            \array_filter($rows, function (array $row) use ($lower): bool {
                foreach ($row as $cell) {
                    if (\str_contains(\strtolower($cell), $lower)) {
                        return true;
                    }
                }
                return false;
            })
        );
    }

    private function padHeader(string $text, int $width, string $align): string
    {
        $len = \strlen($text);
        if ($len >= $width) {
            return \substr($text, 0, $width);
        }
        $pad = $width - $len;
        return match ($align) {
            'right'  => \str_repeat(' ', $pad) . $text,
            'center' => \str_repeat(' ', (int) \floor($pad / 2)) . $text . \str_repeat(' ', (int) \ceil($pad / 2)),
            default  => $text . \str_repeat(' ', $pad),
        };
    }

    public function computeTotalWidth(): int
    {
        $colWidths = \array_sum(\array_map(fn(Column $c) => $c->width, $this->columns));
        $sepWidth  = \strlen($this->separator) * (\count($this->columns) - 1);
        return $colWidths + $sepWidth;
    }

    private function applyStyle(string $s, string $style): string
    {
        if ($style === '') return $s;
        return Ansi::CSI . $style . 'm' . $s . Ansi::reset();
    }
}
