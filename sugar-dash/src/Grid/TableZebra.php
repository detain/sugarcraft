<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A table component with alternating row colors (zebra striping).
 *
 * Renders tabular data with:
 * - Alternating row background colors for readability
 * - Optional header row styling
 * - Cell content alignment per column
 * - Customizable stripe colors
 * - Optional separator lines between rows
 *
 * Mirrors zebra table styling adapted to PHP with
 * wither-style immutable setters.
 */
final class TableZebra implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{header:string, width?:int, align?:HAlign}> $columns
     * @param list<list<string>> $rows
     */
    public function __construct(
        private readonly array $columns,
        private readonly array $rows,
        private readonly ?Color $oddRowColor = null,
        private readonly ?Color $evenRowColor = null,
        private readonly ?Color $headerColor = null,
        private readonly ?Color $headerBackgroundColor = null,
        private readonly bool $showHeader = true,
        private readonly bool $showSeparators = false,
    ) {}

    /**
     * Create a new zebra-striped table with default styling (Catppuccin Mocha).
     *
     * @param list<array{header:string, width?:int, align?:HAlign}> $columns
     * @param list<list<string>> $rows
     */
    public static function new(array $columns, array $rows = []): self
    {
        return new self(
            columns: $columns,
            rows: $rows,
            oddRowColor: Color::hex('#313244'),
            evenRowColor: Color::hex('#1E1E2E'),
            headerColor: Color::hex('#CDD6F4'),
            headerBackgroundColor: Color::hex('#45475A'),
            showHeader: true,
            showSeparators: false,
        );
    }

    /**
     * Create a zebra table with no row colors (plain).
     *
     * @param list<array{header:string, width?:int, align?:HAlign}> $columns
     * @param list<list<string>> $rows
     */
    public static function plain(array $columns, array $rows = []): self
    {
        return new self(
            columns: $columns,
            rows: $rows,
            oddRowColor: null,
            evenRowColor: null,
            headerColor: null,
            headerBackgroundColor: null,
            showHeader: true,
            showSeparators: false,
        );
    }

    /**
     * Set the allocated dimensions for this table.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the table as a string.
     */
    public function render(): string
    {
        if ($this->columns === []) {
            return '';
        }

        $colWidths = $this->computeColumnWidths();
        $lines = [];

        // Header
        if ($this->showHeader) {
            $lines[] = $this->renderRow($this->columns, $colWidths, true, 0);
        }

        // Data rows
        foreach ($this->rows as $index => $row) {
            $lines[] = $this->renderRow($row, $colWidths, false, $index);

            if ($this->showSeparators && $index < count($this->rows) - 1) {
                $lines[] = $this->renderSeparator($colWidths);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Compute the width of each column.
     *
     * @return list<int>
     */
    private function computeColumnWidths(): array
    {
        $widths = [];

        foreach ($this->columns as $index => $col) {
            $colWidth = $col['width'] ?? 10;
            $headerLen = Width::string($col['header'] ?? '');

            // Find max content width in this column
            $maxContent = $headerLen;
            foreach ($this->rows as $row) {
                if (isset($row[$index])) {
                    $maxContent = max($maxContent, Width::string($row[$index]));
                }
            }

            $widths[] = max($colWidth, $maxContent);
        }

        // Adjust for allocated width if set
        if ($this->width !== null && $this->width > 0) {
            $naturalWidth = array_sum($widths);
            if ($this->width > $naturalWidth) {
                $extra = $this->width - $naturalWidth;
                $count = count($widths);
                for ($i = 0; $i < $count - 1; $i++) {
                    $widths[$i] += (int) floor($extra / $count);
                }
                $widths[$count - 1] += $extra - array_sum(array_map(fn($w) => $w - ($col['width'] ?? 10), $this->columns));
            }
        }

        return $widths;
    }

    /**
     * Render a single row (header or data).
     *
     * @param array<array{header:string, width?:int, align?:HAlign}|list<string>> $row
     * @param list<int> $colWidths
     * @param bool $isHeader
     * @param int $rowIndex
     */
    private function renderRow(array $row, array $colWidths, bool $isHeader, int $rowIndex): string
    {
        $cells = [];

        foreach ($this->columns as $colIndex => $col) {
            if ($isHeader) {
                $content = $col['header'] ?? '';
            } else {
                $content = $row[$colIndex] ?? '';
            }

            $width = $colWidths[$colIndex];
            $align = $col['align'] ?? HAlign::Left;

            $padded = $this->alignCell($content, $width, $align);
            $cells[] = $padded;
        }

        $line = implode('│', $cells);

        // Apply colors
        if ($isHeader) {
            if ($this->headerBackgroundColor !== null) {
                $line = $this->headerBackgroundColor->toBg(ColorProfile::TrueColor) . $line . Ansi::reset();
            }
            if ($this->headerColor !== null) {
                $line = $this->headerColor->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
            }
        } else {
            $bgColor = ($rowIndex % 2 === 0) ? $this->oddRowColor : $this->evenRowColor;
            if ($bgColor !== null) {
                $line = $bgColor->toBg(ColorProfile::TrueColor) . $line . Ansi::reset();
            }
        }

        return $line;
    }

    /**
     * Render a separator line between rows.
     *
     * @param list<int> $colWidths
     */
    private function renderSeparator(array $colWidths): string
    {
        $parts = [];
        foreach ($colWidths as $width) {
            $parts[] = str_repeat('─', $width);
        }
        return implode('┼', $parts);
    }

    /**
     * Align cell content within the given width.
     */
    private function alignCell(string $content, int $width, HAlign $align): string
    {
        $contentWidth = Width::string($content);

        if ($contentWidth >= $width) {
            return mb_substr($content, 0, $width, 'UTF-8');
        }

        $padding = $width - $contentWidth;

        return match ($align) {
            HAlign::Left => $content . str_repeat(' ', $padding),
            HAlign::Right => str_repeat(' ', $padding) . $content,
            HAlign::Center => $this->centerAlign($content, $contentWidth, $width),
        };
    }

    /**
     * Center-align content within the given width.
     */
    private function centerAlign(string $content, int $contentWidth, int $width): string
    {
        $padding = $width - $contentWidth;
        $left = (int) floor($padding / 2);
        $right = $padding - $left;
        return str_repeat(' ', $left) . $content . str_repeat(' ', $right);
    }

    /**
     * Calculate the natural dimensions of this table.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->columns === []) {
            return [0, 0];
        }

        $colWidths = $this->computeColumnWidths();
        $width = array_sum($colWidths) + max(0, count($colWidths) - 1);

        $height = 0;
        if ($this->showHeader) {
            $height += 1;
        }
        $height += count($this->rows);
        if ($this->showSeparators) {
            $height += max(0, count($this->rows) - 1);
        }

        return [$width, max(1, $height)];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the column definitions.
     *
     * @param list<array{header:string, width?:int, align?:HAlign}> $columns
     */
    public function withColumns(array $columns): self
    {
        return new self(
            columns: $columns,
            rows: $this->rows,
            oddRowColor: $this->oddRowColor,
            evenRowColor: $this->evenRowColor,
            headerColor: $this->headerColor,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $this->showHeader,
            showSeparators: $this->showSeparators,
        );
    }

    /**
     * Set the data rows.
     *
     * @param list<list<string>> $rows
     */
    public function withRows(array $rows): self
    {
        return new self(
            columns: $this->columns,
            rows: $rows,
            oddRowColor: $this->oddRowColor,
            evenRowColor: $this->evenRowColor,
            headerColor: $this->headerColor,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $this->showHeader,
            showSeparators: $this->showSeparators,
        );
    }

    /**
     * Add a data row.
     *
     * @param list<string> $row
     */
    public function withAddedRow(array $row): self
    {
        return new self(
            columns: $this->columns,
            rows: [...$this->rows, $row],
            oddRowColor: $this->oddRowColor,
            evenRowColor: $this->evenRowColor,
            headerColor: $this->headerColor,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $this->showHeader,
            showSeparators: $this->showSeparators,
        );
    }

    /**
     * Set the odd row background color.
     */
    public function withOddRowColor(?Color $color): self
    {
        return new self(
            columns: $this->columns,
            rows: $this->rows,
            oddRowColor: $color,
            evenRowColor: $this->evenRowColor,
            headerColor: $this->headerColor,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $this->showHeader,
            showSeparators: $this->showSeparators,
        );
    }

    /**
     * Set the even row background color.
     */
    public function withEvenRowColor(?Color $color): self
    {
        return new self(
            columns: $this->columns,
            rows: $this->rows,
            oddRowColor: $this->oddRowColor,
            evenRowColor: $color,
            headerColor: $this->headerColor,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $this->showHeader,
            showSeparators: $this->showSeparators,
        );
    }

    /**
     * Set the header text color.
     */
    public function withHeaderColor(?Color $color): self
    {
        return new self(
            columns: $this->columns,
            rows: $this->rows,
            oddRowColor: $this->oddRowColor,
            evenRowColor: $this->evenRowColor,
            headerColor: $color,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $this->showHeader,
            showSeparators: $this->showSeparators,
        );
    }

    /**
     * Set the header background color.
     */
    public function withHeaderBackgroundColor(?Color $color): self
    {
        return new self(
            columns: $this->columns,
            rows: $this->rows,
            oddRowColor: $this->oddRowColor,
            evenRowColor: $this->evenRowColor,
            headerColor: $this->headerColor,
            headerBackgroundColor: $color,
            showHeader: $this->showHeader,
            showSeparators: $this->showSeparators,
        );
    }

    /**
     * Show or hide the header row.
     */
    public function withShowHeader(bool $show): self
    {
        return new self(
            columns: $this->columns,
            rows: $this->rows,
            oddRowColor: $this->oddRowColor,
            evenRowColor: $this->evenRowColor,
            headerColor: $this->headerColor,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $show,
            showSeparators: $this->showSeparators,
        );
    }

    /**
     * Show or hide row separators.
     */
    public function withShowSeparators(bool $show): self
    {
        return new self(
            columns: $this->columns,
            rows: $this->rows,
            oddRowColor: $this->oddRowColor,
            evenRowColor: $this->evenRowColor,
            headerColor: $this->headerColor,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $this->showHeader,
            showSeparators: $show,
        );
    }
}
