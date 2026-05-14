<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Table;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Layout\HAlign;

/**
 * A table component with box-drawing character borders.
 *
 * Renders tabular data with:
 * - Configurable column definitions (width, header, alignment)
 * - Box-drawing character borders (top, bottom, sides, corners, header separator)
 * - Optional header row with distinct styling
 * - Cell content alignment per column
 * - Configurable border colors
 *
 * Mirrors table rendering from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class TableBordered implements \SugarCraft\Dash\Foundation\Sizer
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
        private readonly ?Color $borderColor = null,
        private readonly ?Color $headerColor = null,
        private readonly ?Color $headerBackgroundColor = null,
        private readonly bool $showHeader = true,
    ) {}

    /**
     * Create a new bordered table with default styling.
     *
     * @param list<array{header:string, width?:int, align?:HAlign}> $columns
     * @param list<list<string>> $rows
     */
    public static function new(array $columns, array $rows = []): self
    {
        return new self(
            columns: $columns,
            rows: $rows,
            borderColor: Color::hex('#45475A'),
            headerColor: Color::hex('#CDD6F4'),
            headerBackgroundColor: Color::hex('#313244'),
            showHeader: true,
        );
    }

    /**
     * Set the allocated dimensions for this table.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
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
        $totalWidth = array_sum($colWidths) + count($colWidths) + 1; // +1 for border

        $lines = [];

        // Top border
        $lines[] = $this->renderTopBorder($colWidths);

        // Header
        if ($this->showHeader) {
            $lines[] = $this->renderHeaderRow($colWidths);
            $lines[] = $this->renderHeaderSeparator($colWidths);
        }

        // Data rows
        foreach ($this->rows as $row) {
            $lines[] = $this->renderDataRow($row, $colWidths);
        }

        // Bottom border
        $lines[] = $this->renderBottomBorder($colWidths);

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
            if (isset($this->rows[$index])) {
                foreach ($this->rows as $row) {
                    if (isset($row[$index])) {
                        $maxContent = max($maxContent, Width::string($row[$index]));
                    }
                }
            }

            $widths[] = max($colWidth, $maxContent);
        }

        // Adjust for allocated width if set
        if ($this->width !== null && $this->width > 0) {
            $naturalWidth = array_sum($widths) + count($widths) + 1;
            if ($this->width > $naturalWidth) {
                // Distribute extra space proportionally
                $extra = $this->width - $naturalWidth;
                $count = count($widths);
                for ($i = 0; $i < $count; $i++) {
                    $widths[$i] += (int) floor($extra / $count);
                }
                // Give remainder to last column
                $distributed = (int) floor($extra / $count) * $count;
                $widths[$count - 1] += $extra - $distributed;
            }
        }

        return $widths;
    }

    /**
     * Render the top border line.
     *
     * @param list<int> $colWidths
     */
    private function renderTopBorder(array $colWidths): string
    {
        $parts = ['┌'];
        foreach ($colWidths as $width) {
            $parts[] = str_repeat('─', $width);
            $parts[] = '┬';
        }
        array_pop($parts); // Remove last '┬'
        $parts[] = '┐';

        $line = implode('', $parts);
        if ($this->borderColor !== null) {
            return $this->borderColor->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
        }
        return $line;
    }

    /**
     * Render the header separator line.
     *
     * @param list<int> $colWidths
     */
    private function renderHeaderSeparator(array $colWidths): string
    {
        $parts = ['├'];
        foreach ($colWidths as $width) {
            $parts[] = str_repeat('─', $width);
            $parts[] = '┼';
        }
        array_pop($parts);
        $parts[] = '┤';

        $line = implode('', $parts);
        if ($this->borderColor !== null) {
            return $this->borderColor->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
        }
        return $line;
    }

    /**
     * Render the bottom border line.
     *
     * @param list<int> $colWidths
     */
    private function renderBottomBorder(array $colWidths): string
    {
        $parts = ['└'];
        foreach ($colWidths as $width) {
            $parts[] = str_repeat('─', $width);
            $parts[] = '┴';
        }
        array_pop($parts);
        $parts[] = '┘';

        $line = implode('', $parts);
        if ($this->borderColor !== null) {
            return $this->borderColor->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
        }
        return $line;
    }

    /**
     * Render a header row.
     *
     * @param list<int> $colWidths
     */
    private function renderHeaderRow(array $colWidths): string
    {
        $cells = [];

        foreach ($this->columns as $index => $col) {
            $header = $col['header'] ?? '';
            $width = $colWidths[$index];
            $align = $col['align'] ?? HAlign::Center;

            $padded = $this->alignCell($header, $width, $align);
            $cells[] = '│' . $padded;
        }

        $line = implode('', $cells) . '│';

        if ($this->headerBackgroundColor !== null) {
            $line = $this->headerBackgroundColor->toBg(ColorProfile::TrueColor) . $line . Ansi::reset();
        }
        if ($this->headerColor !== null) {
            $line = $this->headerColor->toFg(ColorProfile::TrueColor) . $line;
            if ($this->headerBackgroundColor === null) {
                $line .= Ansi::reset();
            } else {
                $line .= Ansi::reset();
            }
        }

        return $line;
    }

    /**
     * Render a data row.
     *
     * @param list<string> $row
     * @param list<int> $colWidths
     */
    private function renderDataRow(array $row, array $colWidths): string
    {
        $cells = [];

        foreach ($this->columns as $index => $col) {
            $content = $row[$index] ?? '';
            $width = $colWidths[$index];
            $align = $col['align'] ?? HAlign::Left;

            $padded = $this->alignCell($content, $width, $align);
            $cells[] = '│' . $padded;
        }

        $line = implode('', $cells) . '│';

        if ($this->borderColor !== null) {
            return $this->borderColor->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
        }
        return $line;
    }

    /**
     * Align cell content within the given width.
     */
    private function alignCell(string $content, int $width, HAlign $align): string
    {
        $contentWidth = Width::string($content);

        if ($contentWidth >= $width) {
            // Truncate if too wide
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
        $width = array_sum($colWidths) + count($colWidths) + 1; // +1 for left border

        $height = 0;
        if ($this->showHeader) {
            $height += 1; // Header row
            if ($this->rows !== []) {
                $height += 1; // Header separator
            }
        }
        $height += count($this->rows); // Data rows
        $height += 2; // Top and bottom borders

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
            borderColor: $this->borderColor,
            headerColor: $this->headerColor,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $this->showHeader,
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
            borderColor: $this->borderColor,
            headerColor: $this->headerColor,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $this->showHeader,
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
            borderColor: $this->borderColor,
            headerColor: $this->headerColor,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $this->showHeader,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            columns: $this->columns,
            rows: $this->rows,
            borderColor: $color,
            headerColor: $this->headerColor,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $this->showHeader,
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
            borderColor: $this->borderColor,
            headerColor: $color,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $this->showHeader,
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
            borderColor: $this->borderColor,
            headerColor: $this->headerColor,
            headerBackgroundColor: $color,
            showHeader: $this->showHeader,
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
            borderColor: $this->borderColor,
            headerColor: $this->headerColor,
            headerBackgroundColor: $this->headerBackgroundColor,
            showHeader: $show,
        );
    }
}