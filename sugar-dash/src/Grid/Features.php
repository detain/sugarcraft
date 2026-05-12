<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A features grid display component.
 *
 * Displays a grid of feature items with icons, titles, and descriptions.
 * Supports multiple layouts and customizable styling.
 *
 * Mirrors feature-grid concepts adapted to PHP with wither-style immutable setters.
 */
final class Features implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param array<int, array{icon: string, title: string, description: string, color?: Color|null}> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly int $columns = 3,
        private readonly ?Color $iconColor = null,
        private readonly ?Color $titleColor = null,
        private readonly ?Color $descriptionColor = null,
        private readonly ?Color $borderColor = null,
        private readonly string $borderChar = '│',
    ) {}

    /**
     * Create a new features grid with default styling.
     *
     * @param array<int, array{icon: string, title: string, description: string}> $features
     */
    public static function new(array $features): self
    {
        return new self(
            items: array_map(fn($f) => [
                'icon' => $f['icon'] ?? '•',
                'title' => $f['title'] ?? '',
                'description' => $f['description'] ?? '',
                'color' => $f['color'] ?? null,
            ], $features),
            columns: 3,
            iconColor: Color::hex('#A78BFA'),
            titleColor: Color::hex('#FAFAFA'),
            descriptionColor: Color::hex('#A1A1AA'),
            borderColor: Color::hex('#27272A'),
            borderChar: '│',
        );
    }

    /**
     * Create a compact features grid with 2 columns.
     *
     * @param array<int, array{icon: string, title: string, description: string}> $features
     */
    public static function compact(array $features): self
    {
        return new self(
            items: array_map(fn($f) => [
                'icon' => $f['icon'] ?? '•',
                'title' => $f['title'] ?? '',
                'description' => $f['description'] ?? '',
                'color' => $f['color'] ?? null,
            ], $features),
            columns: 2,
            iconColor: Color::hex('#34D399'),
            titleColor: Color::hex('#FAFAFA'),
            descriptionColor: Color::hex('#A1A1AA'),
            borderColor: Color::hex('#27272A'),
            borderChar: '│',
        );
    }

    /**
     * Set the allocated dimensions for this features grid.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the features grid as a string.
     */
    public function render(): string
    {
        if (empty($this->items)) {
            return '';
        }

        $useWidth = $this->getWidth();
        $rows = $this->layoutItems();
        $lines = [];

        foreach ($rows as $rowIndex => $rowItems) {
            // Render icon line
            $iconLine = '';
            // Render title line
            $titleLine = '';
            // Render description line
            $descLine = '';
            // Render separator line (between feature cells)
            $sepLine = '';

            foreach ($rowItems as $colIndex => $item) {
                $cellWidth = $this->getCellWidth($useWidth);

                if ($colIndex > 0) {
                    if ($this->borderColor !== null) {
                        $sep = $this->borderColor->toFg(ColorProfile::TrueColor) . ' ' . $this->borderChar . ' ';
                        $iconLine .= $sep;
                        $titleLine .= $sep;
                        $descLine .= $sep;
                    }
                }

                $icon = $item['icon'] ?? '•';
                $title = $item['title'] ?? '';
                $desc = $item['description'] ?? '';
                $color = $item['color'] ?? $this->iconColor;

                // Icon
                if ($color !== null) {
                    $iconLine .= $color->toFg(ColorProfile::TrueColor);
                }
                $iconLine .= str_pad($icon, (int) floor($cellWidth / 2) - 1) . ' ';

                // Title
                if ($this->titleColor !== null) {
                    $titleLine .= $this->titleColor->toFg(ColorProfile::TrueColor);
                }
                $titleLine .= str_pad($title, $cellWidth - 1);

                // Description
                if ($this->descriptionColor !== null) {
                    $descLine .= $this->descriptionColor->toFg(ColorProfile::TrueColor);
                }
                $descLine .= str_pad($desc, $cellWidth - 1);

                $iconLine .= Ansi::reset();
                $titleLine .= Ansi::reset();
                $descLine .= Ansi::reset();
            }

            $lines[] = $iconLine;
            $lines[] = $titleLine;
            $lines[] = $descLine;

            // Add separator between rows
            if ($rowIndex < count($rows) - 1) {
                $rowSep = '';
                foreach ($rowItems as $colIndex => $item) {
                    $cellWidth = $this->getCellWidth($useWidth);
                    if ($colIndex > 0) {
                        if ($this->borderColor !== null) {
                            $rowSep .= $this->borderColor->toFg(ColorProfile::TrueColor) . ' ' . $this->borderChar . ' ';
                        }
                    }
                    $rowSep .= str_repeat('─', $cellWidth - 1) . ' ';
                    $rowSep .= Ansi::reset();
                }
                $lines[] = $rowSep;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Layout items into rows based on column count.
     *
     * @return array<int, array<int, array{icon: string, title: string, description: string, color?: Color|null}>>
     */
    private function layoutItems(): array
    {
        $rows = [];
        $cols = $this->columns;

        foreach (array_chunk($this->items, $cols) as $chunk) {
            $rows[] = $chunk;
        }

        return $rows;
    }

    /**
     * Get the width of a single cell.
     */
    private function getCellWidth(int $totalWidth): int
    {
        $colWidth = (int) floor($totalWidth / $this->columns);
        return max(20, $colWidth);
    }

    /**
     * Calculate the natural dimensions of this features grid.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();
        $rows = count(array_chunk($this->items, $this->columns));
        $height = $rows * 3 + max(0, $rows - 1); // 3 lines per row + separator between rows

        return [$width, $height];
    }

    /**
     * Get the width to use for this features grid.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }

        // Calculate max widths
        $maxTitleLen = 0;
        $maxDescLen = 0;

        foreach ($this->items as $item) {
            $maxTitleLen = max($maxTitleLen, Width::string($item['title'] ?? ''));
            $maxDescLen = max($maxDescLen, Width::string($item['description'] ?? ''));
        }

        $cellWidth = max($maxTitleLen, $maxDescLen) + 10;
        return $cellWidth * $this->columns;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the feature items.
     *
     * @param array<int, array{icon: string, title: string, description: string, color?: Color|null}> $items
     */
    public function withItems(array $items): self
    {
        return new self(
            items: array_map(fn($f) => [
                'icon' => $f['icon'] ?? '•',
                'title' => $f['title'] ?? '',
                'description' => $f['description'] ?? '',
                'color' => $f['color'] ?? null,
            ], $items),
            columns: $this->columns,
            iconColor: $this->iconColor,
            titleColor: $this->titleColor,
            descriptionColor: $this->descriptionColor,
            borderColor: $this->borderColor,
            borderChar: $this->borderChar,
        );
    }

    /**
     * Set the number of columns.
     */
    public function withColumns(int $columns): self
    {
        return new self(
            items: $this->items,
            columns: $columns,
            iconColor: $this->iconColor,
            titleColor: $this->titleColor,
            descriptionColor: $this->descriptionColor,
            borderColor: $this->borderColor,
            borderChar: $this->borderChar,
        );
    }

    /**
     * Set the icon color.
     */
    public function withIconColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            columns: $this->columns,
            iconColor: $color,
            titleColor: $this->titleColor,
            descriptionColor: $this->descriptionColor,
            borderColor: $this->borderColor,
            borderChar: $this->borderChar,
        );
    }

    /**
     * Set the title color.
     */
    public function withTitleColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            columns: $this->columns,
            iconColor: $this->iconColor,
            titleColor: $color,
            descriptionColor: $this->descriptionColor,
            borderColor: $this->borderColor,
            borderChar: $this->borderChar,
        );
    }

    /**
     * Set the description color.
     */
    public function withDescriptionColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            columns: $this->columns,
            iconColor: $this->iconColor,
            titleColor: $this->titleColor,
            descriptionColor: $color,
            borderColor: $this->borderColor,
            borderChar: $this->borderChar,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            columns: $this->columns,
            iconColor: $this->iconColor,
            titleColor: $this->titleColor,
            descriptionColor: $this->descriptionColor,
            borderColor: $color,
            borderChar: $this->borderChar,
        );
    }

    /**
     * Set the border character.
     */
    public function withBorderChar(string $char): self
    {
        return new self(
            items: $this->items,
            columns: $this->columns,
            iconColor: $this->iconColor,
            titleColor: $this->titleColor,
            descriptionColor: $this->descriptionColor,
            borderColor: $this->borderColor,
            borderChar: $char,
        );
    }
}
