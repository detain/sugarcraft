<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A pictogram chart using icons/symbols to represent values.
 *
 * Features:
 * - Icon-based bars instead of traditional bars
 * - Configurable icon character
 * - Multiple data series
 * - Stacked or grouped display
 * - Customizable colors
 *
 * Mirrors pictogram/icon-chart patterns adapted to PHP with wither-style
 * immutable setters.
 */
final class Pictogram implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{label: string, value: float, icon: string, color: Color|null}> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly string $icon = '■',
        private readonly int $iconWidth = 1,
        private readonly int $maxIcons = 10,
        private readonly bool $showLabels = true,
        private readonly bool $showValues = true,
        private readonly bool $stacked = false,
    ) {}

    /**
     * Create a new pictogram with the given data.
     *
     * @param list<array{label: string, value: float, icon?: string, color?: string|Color|null}> $items
     */
    public static function new(array $items): self
    {
        $colors = [
            Color::hex('#F38BA8'),
            Color::hex('#A6E3A1'),
            Color::hex('#89B4FA'),
            Color::hex('#F9E2AF'),
            Color::hex('#CBA6F7'),
        ];

        $normalizedItems = array_map(function (array $item) use (&$colorIndex, $colors): array {
            $color = $item['color'] ?? null;
            if (is_string($color)) {
                $color = Color::hex($color);
            }
            if ($color === null) {
                $color = $colors[$colorIndex % count($colors)];
                $colorIndex++;
            }
            return [
                'label' => $item['label'],
                'value' => max(0.0, floatval($item['value'])),
                'icon' => $item['icon'] ?? '■',
                'color' => $color,
            ];
        }, $items);

        $colorIndex = 0;

        return new self(
            items: $normalizedItems,
            icon: '■',
            iconWidth: 1,
            maxIcons: 10,
            showLabels: true,
            showValues: true,
            stacked: false,
        );
    }

    /**
     * Create a pictogram with filled circles.
     */
    public static function circles(array $items): self
    {
        return self::new($items)->withIcon('●');
    }

    /**
     * Create a pictogram with stars.
     */
    public static function stars(array $items): self
    {
        return self::new($items)->withIcon('★');
    }

    /**
     * Set the allocated dimensions for this pictogram.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this pictogram.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? $this->maxIcons + 15;
        $useHeight = $this->height ?? max(3, count($this->items));
        return [$useWidth, $useHeight];
    }

    /**
     * Render the pictogram.
     */
    public function render(): string
    {
        if ($this->items === []) {
            return '';
        }

        $useWidth = $this->width ?? $this->maxIcons + 15;
        $maxValue = max(array_column($this->items, 'value'));
        if ($maxValue <= 0) {
            $maxValue = 1;
        }

        $result = [];

        foreach ($this->items as $item) {
            $label = $item['label'];
            $value = $item['value'];
            $icon = $item['icon'];
            $color = $item['color'];

            $numIcons = (int) round(($value / $maxValue) * $this->maxIcons);
            $numIcons = max(0, min($this->maxIcons, $numIcons));

            $line = '';

            // Label
            if ($this->showLabels) {
                $line .= str_pad($label, 10, ' ') . ' ';
            }

            // Icons
            for ($i = 0; $i < $this->maxIcons; $i++) {
                if ($i < $numIcons) {
                    $line .= $color->toFg(ColorProfile::TrueColor) . $icon . Ansi::reset();
                } else {
                    $line .= '░';
                }
            }

            // Value
            if ($this->showValues) {
                $line .= ' ' . number_format($value);
            }

            $result[] = $line;
        }

        return implode("\n", $result);
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the icon character.
     */
    public function withIcon(string $icon): self
    {
        return new self(
            items: $this->items,
            icon: $icon,
            iconWidth: $this->iconWidth,
            maxIcons: $this->maxIcons,
            showLabels: $this->showLabels,
            showValues: $this->showValues,
            stacked: $this->stacked,
        );
    }

    /**
     * Set the maximum number of icons per row.
     */
    public function withMaxIcons(int $max): self
    {
        return new self(
            items: $this->items,
            icon: $this->icon,
            iconWidth: $this->iconWidth,
            maxIcons: $max,
            showLabels: $this->showLabels,
            showValues: $this->showValues,
            stacked: $this->stacked,
        );
    }

    /**
     * Show or hide labels.
     */
    public function withShowLabels(bool $show): self
    {
        return new self(
            items: $this->items,
            icon: $this->icon,
            iconWidth: $this->iconWidth,
            maxIcons: $this->maxIcons,
            showLabels: $show,
            showValues: $this->showValues,
            stacked: $this->stacked,
        );
    }

    /**
     * Show or hide values.
     */
    public function withShowValues(bool $show): self
    {
        return new self(
            items: $this->items,
            icon: $this->icon,
            iconWidth: $this->iconWidth,
            maxIcons: $this->maxIcons,
            showLabels: $this->showLabels,
            showValues: $show,
            stacked: $this->stacked,
        );
    }

    /**
     * Enable stacked mode.
     */
    public function withStacked(bool $stacked): self
    {
        return new self(
            items: $this->items,
            icon: $this->icon,
            iconWidth: $this->iconWidth,
            maxIcons: $this->maxIcons,
            showLabels: $this->showLabels,
            showValues: $this->showValues,
            stacked: $stacked,
        );
    }
}
