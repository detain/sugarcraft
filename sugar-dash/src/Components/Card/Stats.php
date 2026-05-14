<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A statistics display component.
 *
 * Displays a row of key metrics with labels, commonly used in dashboards
 * to show important numbers at a glance. Supports custom colors,
 * alignment, and separators between stats.
 *
 * Mirrors stats/metric-row concepts adapted to PHP with wither-style immutable setters.
 */
final class Stats implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param array<int, array{label: string, value: string, color?: Color|null}> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly ?Color $labelColor = null,
        private readonly ?Color $valueColor = null,
        private readonly ?Color $separatorColor = null,
        private readonly string $separator = '│',
        private readonly string $alignment = 'left',
    ) {}

    /**
     * Create a new stats row with default styling.
     *
     * @param array<int, array{label: string, value: string}> $stats
     */
    public static function new(array $stats): self
    {
        return new self(
            items: array_map(fn($s) => [
                'label' => $s['label'] ?? '',
                'value' => $s['value'] ?? '',
                'color' => $s['color'] ?? null,
            ], $stats),
            labelColor: Color::hex('#71717A'),
            valueColor: Color::hex('#FAFAFA'),
            separatorColor: Color::hex('#3F3F46'),
            separator: '│',
            alignment: 'left',
        );
    }

    /**
     * Create a horizontal divider-style stats row.
     *
     * @param array<int, array{label: string, value: string}> $stats
     */
    public static function horizontal(array $stats): self
    {
        return new self(
            items: array_map(fn($s) => [
                'label' => $s['label'] ?? '',
                'value' => $s['value'] ?? '',
                'color' => $s['color'] ?? null,
            ], $stats),
            labelColor: Color::hex('#71717A'),
            valueColor: Color::hex('#FAFAFA'),
            separatorColor: Color::hex('#3F3F46'),
            separator: '─',
            alignment: 'center',
        );
    }

    /**
     * Set the allocated dimensions for this stats row.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the stats row as a string.
     */
    public function render(): string
    {
        if (empty($this->items)) {
            return '';
        }

        $useWidth = $this->getWidth();
        $result = '';

        if ($this->separator === '─') {
            // Horizontal separator style
            $result .= $this->renderHorizontalStyle($useWidth);
        } else {
            // Vertical separator style (default)
            $result .= $this->renderVerticalStyle($useWidth);
        }

        return $result;
    }

    /**
     * Render with vertical separators.
     */
    private function renderVerticalStyle(int $width): string
    {
        $labelWidth = 0;
        $valueWidth = 0;

        foreach ($this->items as $item) {
            $labelWidth = max($labelWidth, Width::string($item['label']));
            $valueWidth = max($valueWidth, Width::string($item['value']));
        }

        $segmentWidth = $labelWidth + $valueWidth + 2; // +2 for spacing
        $totalSegments = count($this->items);
        $availableWidth = $width - ($totalSegments - 1) * Width::string($this->separator) - ($totalSegments * $segmentWidth);

        // Distribute extra space
        $extraPerSegment = $totalSegments > 0 ? (int) floor($availableWidth / $totalSegments) : 0;
        $segmentWidth += max(0, $extraPerSegment);

        $lines = [];

        // Label line
        $labelLine = '';
        foreach ($this->items as $i => $item) {
            if ($i > 0) {
                if ($this->separatorColor !== null) {
                    $labelLine .= $this->separatorColor->toFg(ColorProfile::TrueColor);
                }
                $labelLine .= ' ' . $this->separator . ' ';
                $labelLine .= Ansi::reset();
            }

            $segment = str_pad($item['label'], $segmentWidth - 1);
            if ($this->labelColor !== null) {
                $labelLine .= $this->labelColor->toFg(ColorProfile::TrueColor);
            }
            $labelLine .= $segment;
            $labelLine .= Ansi::reset();
        }
        $lines[] = $labelLine;

        // Value line
        $valueLine = '';
        foreach ($this->items as $i => $item) {
            if ($i > 0) {
                if ($this->separatorColor !== null) {
                    $valueLine .= $this->separatorColor->toFg(ColorProfile::TrueColor);
                }
                $valueLine .= ' ' . $this->separator . ' ';
                $valueLine .= Ansi::reset();
            }

            $color = $item['color'] ?? $this->valueColor;
            $segment = str_pad($item['value'], $segmentWidth - 1);
            if ($color !== null) {
                $valueLine .= $color->toFg(ColorProfile::TrueColor);
            }
            $valueLine .= $segment;
            $valueLine .= Ansi::reset();
        }
        $lines[] = $valueLine;

        return implode("\n", $lines);
    }

    /**
     * Render with horizontal separators between segments.
     */
    private function renderHorizontalStyle(int $width): string
    {
        $labelWidth = 0;
        $valueWidth = 0;

        foreach ($this->items as $item) {
            $labelWidth = max($labelWidth, Width::string($item['label']));
            $valueWidth = max($valueWidth, Width::string($item['value']));
        }

        $segmentWidth = max($width, $labelWidth + $valueWidth + 3);

        $lines = [];

        // Separator line
        if ($this->separatorColor !== null) {
            $sepLine = $this->separatorColor->toFg(ColorProfile::TrueColor);
        } else {
            $sepLine = '';
        }
        $sepLine .= str_repeat('─', $width);
        $sepLine .= Ansi::reset();
        $lines[] = $sepLine;

        // Content line (label + value)
        $content = '';
        foreach ($this->items as $i => $item) {
            if ($i > 0) {
                if ($this->separatorColor !== null) {
                    $content .= $this->separatorColor->toFg(ColorProfile::TrueColor);
                }
                $content .= ' ' . $this->separator . ' ';
                $content .= Ansi::reset();
            }

            if ($this->labelColor !== null) {
                $content .= $this->labelColor->toFg(ColorProfile::TrueColor);
            }
            $content .= str_pad($item['label'], $labelWidth) . ' ';
            $content .= Ansi::reset();

            $color = $item['color'] ?? $this->valueColor;
            if ($color !== null) {
                $content .= $color->toFg(ColorProfile::TrueColor);
            }
            $content .= $item['value'];
            $content .= Ansi::reset();
        }
        $lines[] = $content;

        // Bottom separator line
        if ($this->separatorColor !== null) {
            $sepLine2 = $this->separatorColor->toFg(ColorProfile::TrueColor);
        } else {
            $sepLine2 = '';
        }
        $sepLine2 .= str_repeat('─', $width);
        $sepLine2 .= Ansi::reset();
        $lines[] = $sepLine2;

        return implode("\n", $lines);
    }

    /**
     * Calculate the natural dimensions of this stats row.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();

        // Height is 2 for vertical style (label + value), 3 for horizontal (sep + content + sep)
        $height = $this->separator === '─' ? 3 : 2;

        return [$width, $height];
    }

    /**
     * Get the width to use for this stats row.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }

        // Calculate natural width
        $maxWidth = 0;
        foreach ($this->items as $item) {
            $itemWidth = Width::string($item['label']) + Width::string($item['value']) + 2;
            $maxWidth = max($maxWidth, $itemWidth);
        }

        // Multiply by number of items and add separator space
        $count = count($this->items);
        if ($count > 1) {
            $sepWidth = $this->separator === '─' ? 1 : Width::string($this->separator) + 2;
            return ($maxWidth * $count) + ($sepWidth * ($count - 1));
        }

        return $maxWidth;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the stats items.
     *
     * @param array<int, array{label: string, value: string, color?: Color|null}> $items
     */
    public function withItems(array $items): self
    {
        return new self(
            items: array_map(fn($s) => [
                'label' => $s['label'] ?? '',
                'value' => $s['value'] ?? '',
                'color' => $s['color'] ?? null,
            ], $items),
            labelColor: $this->labelColor,
            valueColor: $this->valueColor,
            separatorColor: $this->separatorColor,
            separator: $this->separator,
            alignment: $this->alignment,
        );
    }

    /**
     * Set the label color.
     */
    public function withLabelColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            labelColor: $color,
            valueColor: $this->valueColor,
            separatorColor: $this->separatorColor,
            separator: $this->separator,
            alignment: $this->alignment,
        );
    }

    /**
     * Set the value color.
     */
    public function withValueColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            labelColor: $this->labelColor,
            valueColor: $color,
            separatorColor: $this->separatorColor,
            separator: $this->separator,
            alignment: $this->alignment,
        );
    }

    /**
     * Set the separator color.
     */
    public function withSeparatorColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            labelColor: $this->labelColor,
            valueColor: $this->valueColor,
            separatorColor: $color,
            separator: $this->separator,
            alignment: $this->alignment,
        );
    }

    /**
     * Set the separator character.
     */
    public function withSeparator(string $separator): self
    {
        return new self(
            items: $this->items,
            labelColor: $this->labelColor,
            valueColor: $this->valueColor,
            separatorColor: $this->separatorColor,
            separator: $separator,
            alignment: $this->alignment,
        );
    }

    /**
     * Set the alignment.
     */
    public function withAlignment(string $alignment): self
    {
        return new self(
            items: $this->items,
            labelColor: $this->labelColor,
            valueColor: $this->valueColor,
            separatorColor: $this->separatorColor,
            separator: $this->separator,
            alignment: $alignment,
        );
    }
}
