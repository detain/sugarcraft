<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Table;

use SugarCraft\Sprinkles\Lang;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Align;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;

/**
 * Tabular data renderer. Builds a string with column-aligned cells,
 * an optional header row, and an optional border (using the middle-*
 * runes from {@see Border}).
 *
 * ```php
 * echo Table::new()
 *     ->headers('Name', 'Age')
 *     ->row('Alice', '30')
 *     ->row('Bob',   '25')
 *     ->border(Border::rounded())
 *     ->render();
 * ```
 *
 * Column widths auto-fit to the widest cell. Each cell gets one space of
 * padding on either side. Per-column alignment defaults to {@see Align::Left}.
 */
final class Table
{
    /**
     * Row index passed to a {@see styleFunc()} callback for the header
     * row. `$row === Table::HEADER_ROW` inside the callback means "this is
     * the header"; any other int is a 0-based body-row index.
     */
    public const HEADER_ROW = -1;

    /** @var list<string> */
    private array $headers = [];
    /** @var list<list<string>> */
    private array $rows = [];
    private ?Border $border = null;
    /** @var array{bool,bool,bool,bool} top/right/bottom/left */
    private array $borderSides = [true, true, true, true];
    private bool $borderHeader = true;
    private bool $borderRow    = false;
    private bool $borderColumn = true;
    private Align $headerAlign = Align::Left;
    private Align $rowAlign    = Align::Left;
    /** @var ?\Closure(int, int): Style */
    private ?\Closure $styleFunc = null;
    private ?int $widthCap = null;
    /** @var ?\Closure(string $cell, int $colWidth): list<string> */
    private ?\Closure $wrap = null;
    private int $offset = 0;

    public static function new(): self
    {
        return new self();
    }

    public function headers(string ...$headers): self
    {
        $clone = clone $this;
        $clone->headers = array_values($headers);
        return $clone;
    }

    public function row(string ...$cells): self
    {
        $clone = clone $this;
        $clone->rows = [...$this->rows, array_values($cells)];
        return $clone;
    }

    /** @param iterable<array<int,string>> $rows */
    public function rows(iterable $rows): self
    {
        $clone = clone $this;
        $clone->rows = $this->rows;
        foreach ($rows as $r) {
            $clone->rows[] = array_values($r);
        }
        return $clone;
    }

    /**
     * Drop every body row but keep the headers + every other setting
     * intact. Mirrors lipgloss's `ClearRows()`. Useful when you want
     * to refill a static-headers table from a streaming data source.
     */
    public function clearRows(): self
    {
        $clone = clone $this;
        $clone->rows = [];
        return $clone;
    }

    /**
     * Bulk-replace body rows from any {@see Data} source. Mirrors
     * lipgloss's `Data(StringData)` setter — pass {@see StringData}
     * (or any custom Data implementation) for streaming ingest.
     */
    public function data(Data $data): self
    {
        $clone = clone $this;
        $clone->rows = [];
        $rowCount = $data->rows();
        $colCount = $data->columns();
        for ($r = 0; $r < $rowCount; $r++) {
            $row = [];
            for ($c = 0; $c < $colCount; $c++) {
                $row[] = $data->at($r, $c);
            }
            $clone->rows[] = $row;
        }
        return $clone;
    }

    public function border(?Border $b): self
    {
        $clone = clone $this;
        $clone->border = $b;
        return $clone;
    }

    public function headerAlign(Align $a): self { $c = clone $this; $c->headerAlign = $a; return $c; }
    public function rowAlign(Align $a): self    { $c = clone $this; $c->rowAlign    = $a; return $c; }

    /**
     * Per-cell style callback. `$fn($row, $col)` is called for every
     * data cell (including the header — row index === Table::HEADER_ROW
     * for the header). Return a {@see Style}; the cell content is
     * wrapped in `Style::render()` before alignment / border join.
     *
     * Mirrors lipgloss's `StyleFunc(row, col) => Style`. Use this for
     * stripe colouring, conditional highlight on a "winner" row, etc.
     *
     * @param ?\Closure(int $row, int $col): Style $fn  pass null to clear
     */
    public function styleFunc(?\Closure $fn): self
    {
        $c = clone $this;
        $c->styleFunc = $fn;
        return $c;
    }

    /**
     * Toggle borders per side. Each is on by default. Mirrors
     * lipgloss's `Border() / BorderTop() / BorderRight() / …`.
     */
    public function borderTop(bool $on = true): self    { $c = clone $this; $c->borderSides[0] = $on; return $c; }
    public function borderRight(bool $on = true): self  { $c = clone $this; $c->borderSides[1] = $on; return $c; }
    public function borderBottom(bool $on = true): self { $c = clone $this; $c->borderSides[2] = $on; return $c; }
    public function borderLeft(bool $on = true): self   { $c = clone $this; $c->borderSides[3] = $on; return $c; }

    /** Toggle the header / body separator row. Default on. */
    public function borderHeader(bool $on = true): self
    {
        $c = clone $this;
        $c->borderHeader = $on;
        return $c;
    }

    /** Draw a separator row between every body row. Default off. */
    public function borderRow(bool $on = true): self
    {
        $c = clone $this;
        $c->borderRow = $on;
        return $c;
    }

    /**
     * Draw vertical separators between columns. Default on.
     * Off → cells join with a single space separator.
     */
    public function borderColumn(bool $on = true): self
    {
        $c = clone $this;
        $c->borderColumn = $on;
        return $c;
    }

    /**
     * Cap the rendered table width to `$cells` columns. When set, columns
     * are shrunk proportionally (lipgloss-style) to fit within the cap, with
     * a minimum of 1 cell content per column. Cell content that still exceeds
     * the shrunken column width is truncated via {@see Width::truncateAnsi}
     * (unless a {@see wrap()} callback is set, which then receives the
     * shrunken width and may spread the cell over several lines).
     * The cap is best-effort: border runes, the one-space cell padding and
     * the 1-cell column floor are never shrunk, so a cap smaller than that
     * fixed overhead (e.g. on all-empty rows) renders wider than requested.
     * Pass null to remove the cap.
     */
    public function width(?int $cells): self
    {
        if ($cells !== null && $cells < 0) {
            throw new \InvalidArgumentException(Lang::t('table.width_nonneg'));
        }
        $c = clone $this;
        $c->widthCap = $cells;
        return $c;
    }

    /**
     * Skip the first `$n` body rows when rendering (after header).
     * Useful for pagination. Default 0.
     */
    public function offset(int $n): self
    {
        $c = clone $this;
        $c->offset = max(0, $n);
        return $c;
    }

    /**
     * Cell-overflow wrap callback. Receives the raw cell value and the
     * column's content width (after any {@see width()} cap shrink) and
     * returns the cell's lines. When set, the callback is invoked per-cell
     * in place of the default {@see Width::truncateAnsi} truncation, and
     * EVERY returned line is rendered: a row whose cells wrap to N lines
     * occupies N physical lines, shorter cells padded with blank lines.
     * A returned line still wider than the column is truncated with
     * {@see Width::truncateAnsi} so the grid never misaligns; an empty
     * list renders as one blank line.
     *
     * For lipgloss-equivalent behaviour:
     * `->wrap(fn(string $cell, int $w) => explode("\n", Width::wrapAnsi($cell, $w)))`.
     * A one-parameter closure is accepted too (PHP ignores the extra
     * width argument).
     *
     * @param ?\Closure(string $cell, int $colWidth): list<string> $fn
     */
    public function wrap(?\Closure $fn): self
    {
        $c = clone $this;
        $c->wrap = $fn;
        return $c;
    }

    public function render(): string
    {
        $bodyRows = $this->offset > 0 ? array_slice($this->rows, $this->offset) : $this->rows;
        $colCount = max(
            count($this->headers),
            ...array_map('count', $bodyRows ?: [[]]),
        );
        if ($colCount === 0) {
            return '';
        }

        // Column widths.
        $widths = array_fill(0, $colCount, 0);
        foreach (array_merge([$this->headers], $bodyRows) as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], Width::string($cell));
            }
        }

        // Width-cap-aware column shrinking: if widthCap is set and total rendered
        // width exceeds it, shrink columns proportionally (lipgloss-style) down
        // to a floor of 1 cell content.
        if ($this->widthCap !== null) {
            $hasBorder = $this->border !== null;
            $colOverhead = $hasBorder === true ? ($colCount * 2) : 0;
            $borderOverhead = 0;
            if ($hasBorder) {
                // Left + right corner characters
                $borderOverhead += ($this->borderSides[3] ? 1 : 0) + ($this->borderSides[1] ? 1 : 0);
                // Middle separators between columns
                if ($this->borderColumn) {
                    $borderOverhead += $colCount - 1;
                }
            } else {
                // Without border, columns are separated by 2 spaces
                $borderOverhead = max(0, ($colCount - 1) * 2);
            }
            $naturalTotal = array_sum($widths) + $colOverhead + $borderOverhead;
            if ($naturalTotal > $this->widthCap) {
                $available = $this->widthCap - $colOverhead - $borderOverhead;
                if ($available < $colCount) {
                    $available = $colCount; // minimum 1 per column
                }
                // All-empty cells measure 0, so there is no content width to
                // scale down — the overflow is pure border/padding overhead,
                // which shrinking columns cannot reclaim. Leave the widths be
                // rather than divide by the zero total.
                $contentTotal = array_sum($widths);
                if ($contentTotal > 0) {
                    $scale = $available / $contentTotal;
                    foreach ($widths as $i => $w) {
                        $widths[$i] = max(1, (int) floor($w * $scale));
                    }
                }
            }
        }

        $hasBorder = $this->border !== null;
        $hasHeaders = $this->headers !== [];

        $lines = [];
        if ($hasBorder && $this->borderSides[0]) {
            $lines[] = $this->topBorderRow($widths);
        }
        if ($hasHeaders === true) {
            $lines[] = $this->dataRow(
                $this->padRow($this->headers, $colCount),
                $widths,
                $this->headerAlign,
                self::HEADER_ROW,
            );
            if ($hasBorder && $this->borderHeader) {
                $lines[] = $this->separatorRow($widths);
            }
        }
        foreach ($bodyRows as $rowIdx => $row) {
            $lines[] = $this->dataRow(
                $this->padRow($row, $colCount),
                $widths,
                $this->rowAlign,
                $rowIdx,
            );
            $isLast = $rowIdx === array_key_last($bodyRows);
            if ($hasBorder && $this->borderRow && !$isLast) {
                $lines[] = $this->separatorRow($widths);
            }
        }
        if ($hasBorder && $this->borderSides[2]) {
            $lines[] = $this->bottomBorderRow($widths);
        }

        return implode("\n", $lines);
    }

    public function __toString(): string
    {
        return $this->render();
    }

    /** @param list<int> $widths */
    private function topBorderRow(array $widths): string
    {
        $b = $this->border;
        assert($b !== null);
        $segments = [];
        foreach ($widths as $w) {
            $segments[] = str_repeat($b->top, $w + 2);
        }
        $left  = $this->borderSides[3] === true ? $b->topLeft  : '';
        $right = $this->borderSides[1] === true ? $b->topRight : '';
        $mid   = $this->borderColumn   ? $b->middleTop : str_repeat($b->top, 0);
        return $left . implode($mid, $segments) . $right;
    }

    /** @param list<int> $widths */
    private function bottomBorderRow(array $widths): string
    {
        $b = $this->border;
        assert($b !== null);
        $segments = [];
        foreach ($widths as $w) {
            $segments[] = str_repeat($b->bottom, $w + 2);
        }
        $left  = $this->borderSides[3] === true ? $b->bottomLeft  : '';
        $right = $this->borderSides[1] === true ? $b->bottomRight : '';
        $mid   = $this->borderColumn   ? $b->middleBottom : str_repeat($b->bottom, 0);
        return $left . implode($mid, $segments) . $right;
    }

    /** @param list<int> $widths */
    private function separatorRow(array $widths): string
    {
        $b = $this->border;
        assert($b !== null);

        $segments = [];
        foreach ($widths as $w) {
            $segments[] = str_repeat($b->top, $w + 2);
        }
        $left  = $this->borderSides[3] === true ? $b->middleLeft  : '';
        $right = $this->borderSides[1] === true ? $b->middleRight : '';
        $mid   = $this->borderColumn === true ? $b->middle : '';
        return $left . implode($mid, $segments) . $right;
    }

    /**
     * @param list<string> $row
     * @param list<int>    $widths
     */
    private function dataRow(array $row, array $widths, Align $align, int $rowIdx): string
    {
        $hasBorder = $this->border !== null;
        $left = $hasBorder && $this->borderSides[3] ? $this->border->left : '';
        $right = $hasBorder && $this->borderSides[1] ? $this->border->right : '';
        $colSep = $hasBorder
            ? ($this->borderColumn ? $this->border->left : '')
            : '  ';

        // Each cell becomes a column of physical lines: one line by default
        // (truncated to the column), or every line the wrap callback returns.
        $cellLines = [];
        $height = 1;
        foreach ($row as $i => $cell) {
            $colWidth = $widths[$i];
            if ($this->wrap !== null) {
                $lines = array_values(($this->wrap)($cell, $colWidth));
                if ($lines === []) {
                    $lines = [''];
                }
            } else {
                $lines = [$cell];
            }
            foreach ($lines as $k => $line) {
                if (Width::string($line) > $colWidth) {
                    $lines[$k] = Width::truncateAnsi($line, $colWidth);
                }
            }
            $cellLines[$i] = $lines;
            $height = max($height, count($lines));
        }

        // Per-cell style, resolved once per cell (not once per physical line).
        $styles = [];
        if ($this->styleFunc !== null) {
            foreach (array_keys($cellLines) as $i) {
                $styles[$i] = ($this->styleFunc)($rowIdx, $i);
            }
        }

        $physical = [];
        for ($ln = 0; $ln < $height; $ln++) {
            $cells = [];
            foreach ($cellLines as $i => $lines) {
                $aligned = $this->align($lines[$ln] ?? '', $widths[$i], $align);
                if (isset($styles[$i])) {
                    $aligned = $styles[$i]->render($aligned);
                }
                $cells[] = $hasBorder
                    ? ' ' . $aligned . ' '
                    : $aligned;
            }
            $physical[] = $left . implode($colSep, $cells) . $right;
        }
        return implode("\n", $physical);
    }

    /** @param list<string> $row @return list<string> */
    private function padRow(array $row, int $colCount): array
    {
        $rowCount = count($row);
        while ($rowCount < $colCount) {
            $row[] = '';
            $rowCount++;
        }
        return $row;
    }

    private function align(string $cell, int $width, Align $align): string
    {
        $w = Width::string($cell);
        $extra = $width - $w;
        if ($extra <= 0) {
            return $cell;
        }
        return match ($align) {
            Align::Left   => $cell . str_repeat(' ', $extra),
            Align::Right  => str_repeat(' ', $extra) . $cell,
            Align::Center => str_repeat(' ', intdiv($extra, 2)) . $cell . str_repeat(' ', $extra - intdiv($extra, 2)),
        };
    }
}
