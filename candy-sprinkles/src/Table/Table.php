<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Table;

use CandyCore\Core\Util\Width;
use CandyCore\Sprinkles\Align;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Style;

/**
 * Sentinel returned by a column-header `StyleFunc` to mark a row as
 * the header row. `$row === Table::HEADER_ROW` inside the callback
 * means "this is the header"; any other int is a 0-based body-row
 * index.
 */

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
    /** Row index passed to a {@see styleFunc()} callback for the header row. */
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
    /** @var ?\Closure(string $cell): list<string> */
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
     * Cap the rendered table width to `$cells` columns. Long cells
     * truncate via the configured `wrap` callback (or {@see Width::truncate})
     * if no wrap is set. Pass null to remove the cap.
     */
    public function width(?int $cells): self
    {
        if ($cells !== null && $cells < 0) {
            throw new \InvalidArgumentException('width must be >= 0');
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
     * Cell-overflow wrap callback. Receives the raw cell value and
     * returns a list of lines. By default cells are not wrapped — use
     * a closure that calls `Width::wrap($cell, $col_width)` to get
     * lipgloss-equivalent behaviour, or supply your own algorithm.
     *
     * @param ?\Closure(string $cell): list<string> $fn
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

        $hasBorder = $this->border !== null;
        $hasHeaders = $this->headers !== [];

        $lines = [];
        if ($hasBorder && $this->borderSides[0]) {
            $lines[] = $this->topBorderRow($widths);
        }
        if ($hasHeaders) {
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

        $rendered = implode("\n", $lines);
        if ($this->widthCap !== null) {
            $rendered = implode("\n", array_map(
                fn(string $l) => Width::truncateAnsi($l, $this->widthCap),
                explode("\n", $rendered),
            ));
        }
        return $rendered;
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
        $left  = $this->borderSides[3] ? $b->topLeft  : '';
        $right = $this->borderSides[1] ? $b->topRight : '';
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
        $left  = $this->borderSides[3] ? $b->bottomLeft  : '';
        $right = $this->borderSides[1] ? $b->bottomRight : '';
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
        $left  = $this->borderSides[3] ? $b->middleLeft  : '';
        $right = $this->borderSides[1] ? $b->middleRight : '';
        $mid   = $this->borderColumn   ? $b->middle      : '';
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

        $cells = [];
        foreach ($row as $i => $cell) {
            $aligned = $this->align($cell, $widths[$i], $align);
            // Apply per-cell style.
            if ($this->styleFunc !== null) {
                $style = ($this->styleFunc)($rowIdx, $i);
                $aligned = $style->render($aligned);
            }
            $cells[] = $hasBorder
                ? ' ' . $aligned . ' '
                : $aligned;
        }
        return $left . implode($colSep, $cells) . $right;
    }

    /** @param list<string> $row @return list<string> */
    private function padRow(array $row, int $colCount): array
    {
        while (count($row) < $colCount) {
            $row[] = '';
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
