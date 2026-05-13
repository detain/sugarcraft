<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A list component with progress indicators for each item.
 *
 * Features:
 * - Collection of items with individual progress values (0.0 to 1.0)
 * - Optional progress bar for each item
 * - Customizable progress bar colors
 * - Percentage display option
 * - Scroll handling when list exceeds height
 *
 * Mirrors progress list concepts adapted to PHP with wither-style immutable setters.
 */
final class ProgressList implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{label: string, progress: float, color: Color|null}> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly bool $showProgressBars = true,
        private readonly bool $showPercentages = true,
        private readonly int $progressBarWidth = 20,
        private readonly HAlign $labelAlign = HAlign::Left,
    ) {}

    /**
     * Create a new progress list with the given items.
     *
     * @param list<array{label: string, progress?: float, color?: Color|null}> $items
     */
    public static function new(array $items): self
    {
        $normalizedItems = array_map(function (array $item): array {
            return [
                'label' => $item['label'],
                'progress' => max(0.0, min(1.0, $item['progress'] ?? 0.0)),
                'color' => $item['color'] ?? Color::hex('#874BFD'),
            ];
        }, $items);

        return new self(
            items: $normalizedItems,
            showProgressBars: true,
            showPercentages: true,
            progressBarWidth: 20,
            labelAlign: HAlign::Left,
        );
    }

    /**
     * Set the allocated dimensions for this list.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the progress list.
     */
    public function render(): string
    {
        $items = $this->items;

        if ($items === []) {
            return '';
        }

        $useWidth = $this->width ?? $this->calculateMaxWidth($items);
        $useHeight = $this->height ?? count($items);

        $result = [];
        $visibleItems = array_slice($items, 0, $useHeight);

        foreach ($visibleItems as $item) {
            $line = $this->renderItem($item, $useWidth);
            $result[] = $line;
        }

        // Pad with empty lines if needed
        while (count($result) < $useHeight) {
            $result[] = str_repeat(' ', $useWidth);
        }

        return implode("\n", array_slice($result, 0, $useHeight));
    }

    /**
     * Render a single progress list item.
     */
    private function renderItem(array $item, int $width): string
    {
        $label = $item['label'];
        $progress = $item['progress'];
        $color = $item['color'];

        if ($this->showProgressBars) {
            $progressStr = $this->renderProgressBar($progress, $color);
            $percentageStr = $this->showPercentages
                ? sprintf(' %d%%', (int) round($progress * 100))
                : '';

            $labelWidth = Width::string($label);
            $progressWidth = $this->progressBarWidth;
            $percentageWidth = Width::string($percentageStr);
            $availableForLabel = $width - $progressWidth - $percentageWidth - 2; // -2 for space between

            if ($availableForLabel < $labelWidth && $availableForLabel > 3) {
                $label = mb_substr($label, 0, $availableForLabel - 1, 'UTF-8') . '…';
                $labelWidth = Width::string($label);
            } elseif ($availableForLabel <= 3) {
                $label = '';
                $labelWidth = 0;
            }

            $labelPad = match ($this->labelAlign) {
                HAlign::Left => '',
                HAlign::Right => str_repeat(' ', max(0, $availableForLabel - $labelWidth)),
                HAlign::Center => str_repeat(' ', (int) max(0, floor(($availableForLabel - $labelWidth) / 2))),
            };

            $line = $labelPad . $label . ' ' . $progressStr . $percentageStr;

            // Pad to width
            $lineWidth = Width::string($line);
            if ($lineWidth < $width) {
                $line .= str_repeat(' ', $width - $lineWidth);
            }

            return $line;
        }

        // No progress bars - just label with percentage
        $percentageStr = $this->showPercentages
            ? sprintf(' (%d%%)', (int) round($progress * 100))
            : '';

        $line = $label . $percentageStr;

        // Align the line
        $lineWidth = Width::string($line);
        if ($lineWidth < $width) {
            $padding = $width - $lineWidth;
            $line = match ($this->labelAlign) {
                HAlign::Left => $line . str_repeat(' ', $padding),
                HAlign::Right => str_repeat(' ', $padding) . $line,
                HAlign::Center => str_repeat(' ', (int) floor($padding / 2)) . $line . str_repeat(' ', (int) ceil($padding / 2)),
            };
        } elseif ($lineWidth > $width) {
            $line = mb_substr($line, 0, $width - 1, 'UTF-8') . '…';
        }

        return $line;
    }

    /**
     * Render a progress bar.
     */
    private function renderProgressBar(float $progress, ?Color $color): string
    {
        $filledWidth = (int) round($progress * $this->progressBarWidth);
        $emptyWidth = $this->progressBarWidth - $filledWidth;

        $result = '[';

        if ($color !== null && $filledWidth > 0) {
            $result .= $color->toFg(ColorProfile::TrueColor);
        }

        $result .= str_repeat('█', max(0, $filledWidth));

        if ($color !== null) {
            $result .= Ansi::reset();
        }

        $result .= str_repeat('░', max(0, $emptyWidth));
        $result .= ']';

        return $result;
    }

    /**
     * Calculate the maximum width among all items.
     */
    private function calculateMaxWidth(array $items): int
    {
        $maxWidth = 0;

        foreach ($items as $item) {
            $label = $item['label'];
            $progress = $item['progress'];
            $percentageStr = $this->showPercentages
                ? sprintf(' %d%%', (int) round($progress * 100))
                : '';

            $labelWidth = Width::string($label);
            $progressWidth = $this->showProgressBars ? $this->progressBarWidth + 2 : 0; // +2 for brackets
            $percentageWidth = Width::string($percentageStr);

            $totalWidth = $labelWidth + $progressWidth + $percentageWidth + 2; // +2 for space between

            if ($totalWidth > $maxWidth) {
                $maxWidth = $totalWidth;
            }
        }

        return $maxWidth;
    }

    /**
     * Calculate the natural dimensions of this list.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $w = $this->width ?? $this->calculateMaxWidth($this->items);
        $h = count($this->items);

        if ($this->height !== null && $this->height > 0) {
            $h = $this->height;
        }

        return [$w, $h];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the items for this list.
     *
     * @param list<array{label: string, progress?: float, color?: Color|null}> $items
     */
    public function withItems(array $items): self
    {
        $normalizedItems = array_map(function (array $item): array {
            return [
                'label' => $item['label'],
                'progress' => max(0.0, min(1.0, $item['progress'] ?? 0.0)),
                'color' => $item['color'] ?? $this->getItemColor($item),
            ];
        }, $items);

        $clone = new self(
            items: $normalizedItems,
            showProgressBars: $this->showProgressBars,
            showPercentages: $this->showPercentages,
            progressBarWidth: $this->progressBarWidth,
            labelAlign: $this->labelAlign,
        );
        $clone->width = $this->width;
        $clone->height = $this->height;
        return $clone;
    }

    /**
     * Get the default color for an item.
     */
    private function getItemColor(array $item): Color
    {
        $progress = $item['progress'] ?? 0.0;

        if ($progress >= 1.0) {
            return Color::hex('#22C55E'); // Green for complete
        } elseif ($progress >= 0.7) {
            return Color::hex('#3B82F6'); // Blue for nearly there
        } elseif ($progress >= 0.4) {
            return Color::hex('#F59E0B'); // Yellow for halfway
        } else {
            return Color::hex('#874BFD'); // Purple for just started
        }
    }

    /**
     * Show or hide progress bars.
     */
    public function withShowProgressBars(bool $show): self
    {
        $clone = clone $this;
        $clone->showProgressBars = $show;
        return $clone;
    }

    /**
     * Show or hide percentages.
     */
    public function withShowPercentages(bool $show): self
    {
        $clone = clone $this;
        $clone->showPercentages = $show;
        return $clone;
    }

    /**
     * Set the progress bar width.
     */
    public function withProgressBarWidth(int $width): self
    {
        $clone = clone $this;
        $clone->progressBarWidth = max(1, $width);
        return $clone;
    }

    /**
     * Set the label alignment.
     */
    public function withLabelAlign(HAlign $align): self
    {
        $clone = clone $this;
        $clone->labelAlign = $align;
        return $clone;
    }
}
