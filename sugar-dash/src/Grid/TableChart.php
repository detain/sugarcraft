<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A data table component with customizable columns, rows, and styling.
 *
 * Features:
 * - Configurable column widths and alignments
 * - Row striping (zebra coloring)
 * - Sortable columns
 * - Header and cell formatting
 * - Borders and dividers
 *
 * Mirrors table/grid patterns adapted to PHP with wither-style immutable setters.
 */
final class TableChart implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<list<string>> $rows
     * @param list<string> $columnHeaders
     * @param list<int>|null $columnWidths
     * @param list<HAlign>|null $columnAlignments
     */
    public function __construct(
        private readonly array $columnHeaders = [],
        private readonly array $rows = [],
        private readonly ?array $columnWidths = null,
        private readonly ?array $columnAlignments = null,
        private readonly bool $showHeader = true,
        private readonly bool $zebraStriping = true,
        private readonly ?Color $headerColor = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $zebraColor = null,
        private readonly string $borderStyle = 'rounded',
    ) {}

    /**
     * Create a new table chart.
     *
     * @param list<string> $columnHeaders
     * @param list<list<string>> $rows
     */
    public static function new(array $columnHeaders, array $rows = []): self
    {
        return new self(
            columnHeaders: $columnHeaders,
            rows: $rows,
            columnWidths: null,
            columnAlignments: null,
            showHeader: true,
            zebraStriping: true,
            headerColor: Color::hex('#89B4FA'),
            borderColor: Color::hex('#45475A'),
            zebraColor: Color::hex('#1E1E2E'),
            borderStyle: 'rounded',
        );
    }

    /**
     * Create a sample table for demonstration.
     */
    public static function sample(): self
    {
        return self::new(
            ['Name', 'Value', 'Status'],
            [
                ['Alpha', '1,234', 'Active'],
                ['Beta', '5,678', 'Pending'],
                ['Gamma', '9,012', 'Active'],
                ['Delta', '3,456', 'Inactive'],
            ]
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
     * Calculate column widths based on content.
     *
     * @return list<int>
     */
    private function calculateColumnWidths(): array
    {
        $numCols = count($this->columnHeaders);
        if ($numCols === 0) {
            return [];
        }

        $widths = array_map(fn(string $h): int => mb_strlen($h, 'UTF-8'), $this->columnHeaders);

        foreach ($this->rows as $row) {
            for ($i = 0; $i < $numCols; $i++) {
                if (isset($row[$i])) {
                    $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $row[$i], 'UTF-8'));
                }
            }
        }

        // Apply custom widths if set
        if ($this->columnWidths !== null) {
            for ($i = 0; $i < $numCols; $i++) {
                if (isset($this->columnWidths[$i])) {
                    $widths[$i] = $this->columnWidths[$i];
                }
            }
        }

        // Add padding
        return array_map(fn(int $w): int => $w + 2, $widths);
    }

    /**
     * Render the table.
     */
    public function render(): string
    {
        if (empty($this->columnHeaders) && empty($this->rows)) {
            return '';
        }

        $numCols = max(count($this->columnHeaders), count($this->rows[0] ?? []));
        if ($numCols === 0) {
            return '';
        }

        $widths = $this->calculateColumnWidths();
        $totalWidth = array_sum($widths) + $numCols + 1;

        $useWidth = $this->width ?? $totalWidth;
        $scale = $useWidth > $totalWidth ? ($useWidth - $numCols - 1) / $totalWidth : 1.0;

        // Scale widths if needed
        if ($scale > 1.0) {
            $extra = intval(($useWidth - $totalWidth) / $numCols);
            $widths = array_map(fn(int $w) => $w + $extra, $widths);
        }

        $result = '';
        $alignments = $this->columnAlignments ?? array_fill(0, $numCols, HAlign::Left);

        // Top border
        $result .= $this->renderBorder('top', $widths);
        $result .= "\n";

        // Header
        if ($this->showHeader && !empty($this->columnHeaders)) {
            $result .= $this->renderRow($this->columnHeaders, $widths, $alignments, true);
            $result .= "\n";
            $result .= $this->renderBorder('header', $widths);
            $result .= "\n";
        }

        // Rows
        foreach ($this->rows as $rowIndex => $row) {
            $isZebra = $this->zebraStriping && ($rowIndex % 2 === 1);
            $result .= $this->renderRow($row, $widths, $alignments, false, $isZebra);
            if ($rowIndex < count($this->rows) - 1) {
                $result .= "\n";
            }
        }

        // Bottom border
        if (!empty($this->rows)) {
            $result .= "\n";
            $result .= $this->renderBorder('bottom', $widths);
        }

        return $result;
    }

    /**
     * Render a single row.
     *
     * @param list<string> $cells
     * @param list<int> $widths
     * @param list<HAlign> $alignments
     */
    private function renderRow(array $cells, array $widths, array $alignments, bool $isHeader = false, bool $isZebra = false): string
    {
        $numCols = count($widths);
        $line = '';

        // Left border
        $borderChar = $this->getBorderChar('left');
        if ($this->borderColor !== null) {
            $line .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }
        $line .= $borderChar;
        if ($this->borderColor !== null) {
            $line .= Ansi::reset();
        }

        for ($i = 0; $i < $numCols; $i++) {
            $cell = $cells[$i] ?? '';
            $width = $widths[$i];
            $align = $alignments[$i] ?? HAlign::Left;

            // Pad or truncate cell
            $cellStr = $this->formatCell((string) $cell, $width - 2, $align);

            // Apply coloring
            if ($isHeader && $this->headerColor !== null) {
                $cellStr = $this->headerColor->toFg(ColorProfile::TrueColor) . $cellStr . Ansi::reset();
            } elseif ($isZebra && $this->zebraColor !== null) {
                $cellStr = $this->zebraColor->toFg(ColorProfile::TrueColor) . $cellStr . Ansi::reset();
            }

            $line .= ' ' . $cellStr . ' ';

            // Divider
            if ($i < $numCols - 1) {
                $divider = $this->getBorderChar('divider');
                if ($this->borderColor !== null) {
                    $line .= $this->borderColor->toFg(ColorProfile::TrueColor);
                }
                $line .= $divider;
                if ($this->borderColor !== null) {
                    $line .= Ansi::reset();
                }
            }
        }

        // Right border
        $borderChar = $this->getBorderChar('right');
        if ($this->borderColor !== null) {
            $line .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }
        $line .= $borderChar;
        if ($this->borderColor !== null) {
            $line .= Ansi::reset();
        }

        return $line;
    }

    /**
     * Render a border line.
     *
     * @param list<int> $widths
     */
    private function renderBorder(string $position, array $widths): string
    {
        $line = '';
        $left = $this->getBorderChar("{$position}_left");
        $right = $this->getBorderChar("{$position}_right");
        $h = $this->getBorderChar('horizontal');
        $divider = $this->getBorderChar('divider');

        if ($this->borderColor !== null) {
            $line .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }

        $line .= $left;
        foreach ($widths as $i => $width) {
            $line .= str_repeat($h, $width);
            if ($i < count($widths) - 1) {
                $line .= $divider;
            }
        }
        $line .= $right;

        if ($this->borderColor !== null) {
            $line .= Ansi::reset();
        }

        return $line;
    }

    /**
     * Get a border character for the current style.
     */
    private function getBorderChar(string $type): string
    {
        return match ($this->borderStyle) {
            'rounded' => match ($type) {
                'top' => '┌', 'top_left' => '┌', 'top_right' => '┐',
                'bottom' => '└', 'bottom_left' => '└', 'bottom_right' => '┘',
                'left' => '│', 'right' => '│',
                'divider' => '│',
                'header' => '├', 'header_left' => '├', 'header_right' => '┤',
                'horizontal' => '─',
                default => '│',
            },
            'bold' => match ($type) {
                'top' => '┏', 'top_left' => '┏', 'top_right' => '┓',
                'bottom' => '┗', 'bottom_left' => '┗', 'bottom_right' => '┛',
                'left' => '┃', 'right' => '┃',
                'divider' => '┃',
                'header' => '┣', 'header_left' => '┣', 'header_right' => '┫',
                'horizontal' => '━',
                default => '┃',
            },
            'single' => match ($type) {
                'top' => '┌', 'top_left' => '┌', 'top_right' => '┐',
                'bottom' => '└', 'bottom_left' => '└', 'bottom_right' => '┘',
                'left' => '│', 'right' => '│',
                'divider' => '│',
                'header' => '├', 'header_left' => '├', 'header_right' => '┤',
                'horizontal' => '─',
                default => '│',
            },
            'double' => match ($type) {
                'top' => '╔', 'top_left' => '╔', 'top_right' => '╗',
                'bottom' => '╚', 'bottom_left' => '╚', 'bottom_right' => '╝',
                'left' => '║', 'right' => '║',
                'divider' => '║',
                'header' => '╟', 'header_left' => '╟', 'header_right' => '╢',
                'horizontal' => '═',
                default => '║',
            },
            'markdown' => match ($type) {
                'top' => '|', 'top_left' => '|', 'top_right' => '|',
                'bottom' => '|', 'bottom_left' => '|', 'bottom_right' => '|',
                'left' => '|', 'right' => '|',
                'divider' => '|',
                'header' => '|', 'header_left' => '|', 'header_right' => '|',
                'horizontal' => '-',
                default => '|',
            },
            default => match ($type) {
                'top' => '┌', 'top_left' => '┌', 'top_right' => '┐',
                'bottom' => '└', 'bottom_left' => '└', 'bottom_right' => '┘',
                'left' => '│', 'right' => '│',
                'divider' => '│',
                'header' => '├', 'header_left' => '├', 'header_right' => '┤',
                'horizontal' => '─',
                default => '│',
            },
        };
    }

    /**
     * Format a cell value to fit the column width.
     */
    private function formatCell(string $value, int $width, HAlign $align): string
    {
        $len = mb_strlen($value, 'UTF-8');

        if ($len > $width) {
            return mb_substr($value, 0, $width - 1, 'UTF-8') . '…';
        }

        $padding = $width - $len;
        return match ($align) {
            HAlign::Left => $value . str_repeat(' ', $padding),
            HAlign::Right => str_repeat(' ', $padding) . $value,
            HAlign::Center => str_repeat(' ', intval($padding / 2)) . $value . str_repeat(' ', $padding - intval($padding / 2)),
        };
    }

    /**
     * Calculate the natural dimensions of this table.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if (empty($this->columnHeaders) && empty($this->rows)) {
            return [0, 0];
        }

        $widths = $this->calculateColumnWidths();
        $width = array_sum($widths) + count($widths) + 1;
        $height = 1;

        if ($this->showHeader && !empty($this->columnHeaders)) {
            $height++; // header row
            $height++; // header divider
        }

        $height += count($this->rows);

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the rows.
     *
     * @param list<list<string>> $rows
     */
    public function withRows(array $rows): self
    {
        return new self(
            columnHeaders: $this->columnHeaders,
            rows: $rows,
            columnWidths: $this->columnWidths,
            columnAlignments: $this->columnAlignments,
            showHeader: $this->showHeader,
            zebraStriping: $this->zebraStriping,
            headerColor: $this->headerColor,
            borderColor: $this->borderColor,
            zebraColor: $this->zebraColor,
            borderStyle: $this->borderStyle,
        );
    }

    /**
     * Set the header visibility.
     */
    public function withShowHeader(bool $show): self
    {
        return new self(
            columnHeaders: $this->columnHeaders,
            rows: $this->rows,
            columnWidths: $this->columnWidths,
            columnAlignments: $this->columnAlignments,
            showHeader: $show,
            zebraStriping: $this->zebraStriping,
            headerColor: $this->headerColor,
            borderColor: $this->borderColor,
            zebraColor: $this->zebraColor,
            borderStyle: $this->borderStyle,
        );
    }

    /**
     * Set zebra striping.
     */
    public function withZebraStriping(bool $zebra): self
    {
        return new self(
            columnHeaders: $this->columnHeaders,
            rows: $this->rows,
            columnWidths: $this->columnWidths,
            columnAlignments: $this->columnAlignments,
            showHeader: $this->showHeader,
            zebraStriping: $zebra,
            headerColor: $this->headerColor,
            borderColor: $this->borderColor,
            zebraColor: $this->zebraColor,
            borderStyle: $this->borderStyle,
        );
    }

    /**
     * Set the border style.
     */
    public function withBorderStyle(string $style): self
    {
        return new self(
            columnHeaders: $this->columnHeaders,
            rows: $this->rows,
            columnWidths: $this->columnWidths,
            columnAlignments: $this->columnAlignments,
            showHeader: $this->showHeader,
            zebraStriping: $this->zebraStriping,
            headerColor: $this->headerColor,
            borderColor: $this->borderColor,
            zebraColor: $this->zebraColor,
            borderStyle: $style,
        );
    }

    /**
     * Set the header color.
     */
    public function withHeaderColor(?Color $color): self
    {
        return new self(
            columnHeaders: $this->columnHeaders,
            rows: $this->rows,
            columnWidths: $this->columnWidths,
            columnAlignments: $this->columnAlignments,
            showHeader: $this->showHeader,
            zebraStriping: $this->zebraStriping,
            headerColor: $color,
            borderColor: $this->borderColor,
            zebraColor: $this->zebraColor,
            borderStyle: $this->borderStyle,
        );
    }
}
