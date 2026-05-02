<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Table;

use CandyCore\Core\Util\Width;
use CandyCore\Sprinkles\Align;
use CandyCore\Sprinkles\Border;

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
    /** @var list<string> */
    private array $headers = [];
    /** @var list<list<string>> */
    private array $rows = [];
    private ?Border $border = null;
    private Align $headerAlign = Align::Left;
    private Align $rowAlign    = Align::Left;

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

    public function border(?Border $b): self
    {
        $clone = clone $this;
        $clone->border = $b;
        return $clone;
    }

    public function headerAlign(Align $a): self { $c = clone $this; $c->headerAlign = $a; return $c; }
    public function rowAlign(Align $a): self    { $c = clone $this; $c->rowAlign    = $a; return $c; }

    public function render(): string
    {
        $colCount = max(
            count($this->headers),
            ...array_map('count', $this->rows ?: [[]]),
        );
        if ($colCount === 0) {
            return '';
        }

        // Column widths.
        $widths = array_fill(0, $colCount, 0);
        foreach (array_merge([$this->headers], $this->rows) as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], Width::string($cell));
            }
        }

        $hasBorder = $this->border !== null;
        $hasHeaders = $this->headers !== [];

        $lines = [];
        if ($hasBorder) {
            $lines[] = $this->borderRow($widths, top: true,  bottom: false);
        }
        if ($hasHeaders) {
            $lines[] = $this->dataRow($this->padRow($this->headers, $colCount), $widths, $this->headerAlign);
            if ($hasBorder) {
                $lines[] = $this->separatorRow($widths);
            }
        }
        foreach ($this->rows as $row) {
            $lines[] = $this->dataRow($this->padRow($row, $colCount), $widths, $this->rowAlign);
        }
        if ($hasBorder) {
            $lines[] = $this->borderRow($widths, top: false, bottom: true);
        }

        return implode("\n", $lines);
    }

    public function __toString(): string
    {
        return $this->render();
    }

    /** @param list<int> $widths */
    private function borderRow(array $widths, bool $top, bool $bottom): string
    {
        $b = $this->border;
        assert($b !== null);

        $left  = $top ? $b->topLeft  : $b->bottomLeft;
        $right = $top ? $b->topRight : $b->bottomRight;
        $mid   = $top ? $b->middleTop : $b->middleBottom;
        $rune  = $top ? $b->top : $b->bottom;

        $segments = [];
        foreach ($widths as $w) {
            $segments[] = str_repeat($rune, $w + 2); // +2 for cell padding
        }
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
        return $b->middleLeft . implode($b->middle, $segments) . $b->middleRight;
    }

    /**
     * @param list<string> $row
     * @param list<int>    $widths
     */
    private function dataRow(array $row, array $widths, Align $align): string
    {
        if ($this->border !== null) {
            $cells = [];
            foreach ($row as $i => $cell) {
                $cells[] = ' ' . $this->align($cell, $widths[$i], $align) . ' ';
            }
            return $this->border->left . implode($this->border->left, $cells) . $this->border->right;
        }
        $cells = [];
        foreach ($row as $i => $cell) {
            $cells[] = $this->align($cell, $widths[$i], $align);
        }
        return implode('  ', $cells);
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
