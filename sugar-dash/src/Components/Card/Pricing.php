<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A pricing table component.
 *
 * Displays pricing plans in a structured table format with support for:
 * - Plan name, price, billing period
 * - Feature lists with check/x markers
 * - Highlighted/recommended plan option
 * - Custom colors for headers and borders
 *
 * Mirrors pricing table concepts adapted to PHP with wither-style immutable setters.
 */
final class Pricing implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param array<int, array{
     *   name: string,
     *   price: string,
     *   period: string,
     *   description: string,
     *   features: array<int, string>,
     *   highlighted: bool,
     *   buttonText: string
     * }> $plans
     */
    public function __construct(
        private readonly array $plans = [],
        private readonly ?Color $headerColor = null,
        private readonly ?Color $priceColor = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $highlightColor = null,
        private readonly ?Color $featureColor = null,
        private readonly bool $showBorders = true,
    ) {}

    /**
     * Create a new pricing table with default styling.
     *
     * @param array<int, array{
     *   name: string,
     *   price: string,
     *   period: string,
     *   description?: string,
     *   features?: array<int, string>,
     *   highlighted?: bool,
     *   buttonText?: string
     * }> $plans
     */
    public static function new(array $plans): self
    {
        return new self(
            plans: array_map(function (array $plan): array {
                return [
                    'name' => $plan['name'] ?? '',
                    'price' => $plan['price'] ?? '',
                    'period' => $plan['period'] ?? '/mo',
                    'description' => $plan['description'] ?? '',
                    'features' => $plan['features'] ?? [],
                    'highlighted' => $plan['highlighted'] ?? false,
                    'buttonText' => $plan['buttonText'] ?? 'Get Started',
                ];
            }, $plans),
            headerColor: Color::hex('#3F3F46'),
            priceColor: Color::hex('#FAFAFA'),
            borderColor: Color::hex('#3F3F46'),
            highlightColor: Color::hex('#874BFD'),
            featureColor: Color::hex('#A1A1AA'),
            showBorders: true,
        );
    }

    /**
     * Create a compact pricing display.
     *
     * @param array<int, array{name: string, price: string, period?: string}> $plans
     */
    public static function compact(array $plans): self
    {
        return new self(
            plans: array_map(function (array $plan): array {
                return [
                    'name' => $plan['name'] ?? '',
                    'price' => $plan['price'] ?? '',
                    'period' => $plan['period'] ?? '',
                    'description' => '',
                    'features' => [],
                    'highlighted' => false,
                    'buttonText' => '',
                ];
            }, $plans),
            headerColor: Color::hex('#71717A'),
            priceColor: Color::hex('#FAFAFA'),
            borderColor: Color::hex('#27272A'),
            highlightColor: Color::hex('#874BFD'),
            featureColor: Color::hex('#71717A'),
            showBorders: true,
        );
    }

    /**
     * Set the allocated dimensions for this pricing table.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the pricing table as a string.
     */
    public function render(): string
    {
        if (empty($this->plans)) {
            return '';
        }

        $useWidth = $this->getWidth();
        $lines = [];

        // Calculate column widths
        $columnWidth = (int) floor($useWidth / count($this->plans));
        $remainder = $useWidth - ($columnWidth * count($this->plans));

        // Render header row (plan names)
        $lines[] = $this->renderHeaderRow($columnWidth, $remainder);

        // Render price row
        $lines[] = $this->renderPriceRow($columnWidth, $remainder);

        // Render description row
        $lines[] = $this->renderDescriptionRow($columnWidth, $remainder);

        // Render separator
        if ($this->showBorders) {
            $lines[] = $this->renderSeparator($useWidth, $columnWidth, $remainder);
        }

        // Render feature rows
        $maxFeatures = $this->getMaxFeatures();
        for ($i = 0; $i < $maxFeatures; $i++) {
            $lines[] = $this->renderFeatureRow($i, $columnWidth, $remainder);
        }

        // Render bottom border
        if ($this->showBorders) {
            $lines[] = $this->renderBottomBorder($useWidth, $columnWidth, $remainder);
        }

        return implode("\n", $lines);
    }

    /**
     * Render the header row with plan names.
     */
    private function renderHeaderRow(int $columnWidth, int $remainder): string
    {
        $result = '';

        foreach ($this->plans as $i => $plan) {
            $extraWidth = ($i === count($this->plans) - 1) ? $remainder : 0;
            $cellWidth = $columnWidth + $extraWidth;
            $nameWidth = Width::string($plan['name']);
            $padding = max(0, $cellWidth - $nameWidth);
            $leftPad = (int) floor($padding / 2);
            $rightPad = $padding - $leftPad;

            if ($this->showBorders) {
                $result .= '│';
            }

            $color = $plan['highlighted'] ? $this->highlightColor : $this->headerColor;
            if ($color !== null) {
                $result .= $color->toFg(ColorProfile::TrueColor);
            }

            $result .= str_repeat(' ', $leftPad);
            $result .= $plan['name'];
            $result .= str_repeat(' ', $rightPad);
            $result .= Ansi::reset();
        }

        if ($this->showBorders) {
            $result .= '│';
        }

        return $result;
    }

    /**
     * Render the price row.
     */
    private function renderPriceRow(int $columnWidth, int $remainder): string
    {
        $result = '';

        foreach ($this->plans as $i => $plan) {
            $extraWidth = ($i === count($this->plans) - 1) ? $remainder : 0;
            $cellWidth = $columnWidth + $extraWidth;

            if ($this->showBorders) {
                $result .= '│';
            }

            // Price + period
            $priceText = $plan['price'];
            if ($plan['period']) {
                $priceText .= $plan['period'];
            }

            $priceWidth = Width::string($priceText);
            $padding = max(0, $cellWidth - $priceWidth);
            $leftPad = (int) floor($padding / 2);
            $rightPad = $padding - $leftPad;

            $result .= str_repeat(' ', $leftPad);

            if ($this->priceColor !== null) {
                $result .= $this->priceColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $priceText;
            $result .= Ansi::reset();

            $result .= str_repeat(' ', $rightPad);
        }

        if ($this->showBorders) {
            $result .= '│';
        }

        return $result;
    }

    /**
     * Render the description row.
     */
    private function renderDescriptionRow(int $columnWidth, int $remainder): string
    {
        $result = '';

        foreach ($this->plans as $i => $plan) {
            $extraWidth = ($i === count($this->plans) - 1) ? $remainder : 0;
            $cellWidth = $columnWidth + $extraWidth;

            if ($this->showBorders) {
                $result .= '│';
            }

            $descText = $plan['description'];
            $descWidth = Width::string($descText);
            $padding = max(0, $cellWidth - $descWidth);
            $leftPad = (int) floor($padding / 2);
            $rightPad = $padding - $leftPad;

            $result .= str_repeat(' ', $leftPad);

            if ($this->featureColor !== null) {
                $result .= $this->featureColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $descText;
            $result .= Ansi::reset();

            $result .= str_repeat(' ', $rightPad);
        }

        if ($this->showBorders) {
            $result .= '│';
        }

        return $result;
    }

    /**
     * Render the separator line between header and features.
     */
    private function renderSeparator(int $totalWidth, int $columnWidth, int $remainder): string
    {
        $result = '';

        foreach ($this->plans as $i => $plan) {
            $extraWidth = ($i === count($this->plans) - 1) ? $remainder : 0;
            $cellWidth = $columnWidth + $extraWidth;

            if ($this->showBorders) {
                $result .= '├';
            }

            $borderChar = $plan['highlighted'] ? '─' : '─';
            if ($this->borderColor !== null) {
                $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
            }
            $result .= str_repeat($borderChar, $cellWidth);
            $result .= Ansi::reset();
        }

        if ($this->showBorders) {
            $result .= '┤';
        }

        return $result;
    }

    /**
     * Render a single feature row.
     */
    private function renderFeatureRow(int $featureIndex, int $columnWidth, int $remainder): string
    {
        $result = '';

        foreach ($this->plans as $i => $plan) {
            $extraWidth = ($i === count($this->plans) - 1) ? $remainder : 0;
            $cellWidth = $columnWidth + $extraWidth;

            if ($this->showBorders) {
                $result .= '│';
            }

            $feature = $plan['features'][$featureIndex] ?? '';
            $checkMark = '';
            $featureText = '';

            if ($feature !== '') {
                // Check if feature is marked as included (starts with +) or excluded (starts with -)
                if (str_starts_with($feature, '+')) {
                    $checkMark = '✓ ';
                    $featureText = substr($feature, 1);
                } elseif (str_starts_with($feature, '-')) {
                    $checkMark = '✗ ';
                    $featureText = substr($feature, 1);
                } else {
                    $checkMark = '• ';
                    $featureText = $feature;
                }
            }

            $contentWidth = Width::string($checkMark) + Width::string($featureText);
            $padding = max(0, $cellWidth - $contentWidth);
            $leftPad = (int) floor($padding / 2);
            $rightPad = $padding - $leftPad;

            $result .= str_repeat(' ', $leftPad);

            // Check mark color
            if ($checkMark === '✓ ' && $this->highlightColor !== null) {
                $result .= $this->highlightColor->toFg(ColorProfile::TrueColor);
            } elseif ($checkMark === '✗ ' && $this->borderColor !== null) {
                $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
            } elseif ($this->featureColor !== null) {
                $result .= $this->featureColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $checkMark;
            $result .= Ansi::reset();

            if ($this->featureColor !== null) {
                $result .= $this->featureColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $featureText;
            $result .= Ansi::reset();

            $result .= str_repeat(' ', $rightPad);
        }

        if ($this->showBorders) {
            $result .= '│';
        }

        return $result;
    }

    /**
     * Render the bottom border.
     */
    private function renderBottomBorder(int $totalWidth, int $columnWidth, int $remainder): string
    {
        $result = '';

        if ($this->showBorders) {
            $result .= '└';
        }

        foreach ($this->plans as $i => $plan) {
            $extraWidth = ($i === count($this->plans) - 1) ? $remainder : 0;
            $cellWidth = $columnWidth + $extraWidth;

            if ($this->borderColor !== null) {
                $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
            }
            $result .= str_repeat('─', $cellWidth);
            $result .= Ansi::reset();

            if ($i < count($this->plans) - 1) {
                if ($this->showBorders) {
                    $result .= '┴';
                }
            }
        }

        if ($this->showBorders) {
            $result .= '┘';
        }

        return $result;
    }

    /**
     * Get the maximum number of features across all plans.
     */
    private function getMaxFeatures(): int
    {
        $max = 0;
        foreach ($this->plans as $plan) {
            $max = max($max, count($plan['features']));
        }
        return $max;
    }

    /**
     * Calculate the natural dimensions of this pricing table.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();

        // Header + price + description + separator + features + bottom border
        $height = 1; // header
        $height += 1; // price
        if ($this->hasDescriptions()) {
            $height += 1; // description
        }
        if ($this->showBorders) {
            $height += 1; // separator
        }
        $height += $this->getMaxFeatures();
        if ($this->showBorders) {
            $height += 1; // bottom border
        }

        return [$width, $height];
    }

    /**
     * Check if any plan has a description.
     */
    private function hasDescriptions(): bool
    {
        foreach ($this->plans as $plan) {
            if ($plan['description'] !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the width to use for this pricing table.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }

        // Calculate minimum width from content
        $minWidth = 0;
        foreach ($this->plans as $plan) {
            $planWidth = Width::string($plan['name']);
            $planWidth = max($planWidth, Width::string($plan['price'] . $plan['period']));
            if ($plan['description']) {
                $planWidth = max($planWidth, Width::string($plan['description']));
            }
            foreach ($plan['features'] as $feature) {
                $featureText = str_starts_with($feature, '+') || str_starts_with($feature, '-')
                    ? substr($feature, 1)
                    : $feature;
                $planWidth = max($planWidth, Width::string('• ' . $featureText));
            }
            $minWidth += $planWidth;
        }

        // Add border overhead
        $borderOverhead = $this->showBorders ? count($this->plans) + 1 : 0;

        return max(1, $minWidth + $borderOverhead);
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the pricing plans.
     *
     * @param array<int, array{
     *   name: string,
     *   price: string,
     *   period: string,
     *   description: string,
     *   features: array<int, string>,
     *   highlighted: bool,
     *   buttonText: string
     * }> $plans
     */
    public function withPlans(array $plans): self
    {
        return new self(
            plans: array_map(function (array $plan): array {
                return [
                    'name' => $plan['name'] ?? '',
                    'price' => $plan['price'] ?? '',
                    'period' => $plan['period'] ?? '/mo',
                    'description' => $plan['description'] ?? '',
                    'features' => $plan['features'] ?? [],
                    'highlighted' => $plan['highlighted'] ?? false,
                    'buttonText' => $plan['buttonText'] ?? 'Get Started',
                ];
            }, $plans),
            headerColor: $this->headerColor,
            priceColor: $this->priceColor,
            borderColor: $this->borderColor,
            highlightColor: $this->highlightColor,
            featureColor: $this->featureColor,
            showBorders: $this->showBorders,
        );
    }

    /**
     * Set the header color.
     */
    public function withHeaderColor(?Color $color): self
    {
        return new self(
            plans: $this->plans,
            headerColor: $color,
            priceColor: $this->priceColor,
            borderColor: $this->borderColor,
            highlightColor: $this->highlightColor,
            featureColor: $this->featureColor,
            showBorders: $this->showBorders,
        );
    }

    /**
     * Set the price color.
     */
    public function withPriceColor(?Color $color): self
    {
        return new self(
            plans: $this->plans,
            headerColor: $this->headerColor,
            priceColor: $color,
            borderColor: $this->borderColor,
            highlightColor: $this->highlightColor,
            featureColor: $this->featureColor,
            showBorders: $this->showBorders,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            plans: $this->plans,
            headerColor: $this->headerColor,
            priceColor: $this->priceColor,
            borderColor: $color,
            highlightColor: $this->highlightColor,
            featureColor: $this->featureColor,
            showBorders: $this->showBorders,
        );
    }

    /**
     * Set the highlight color (for recommended plan).
     */
    public function withHighlightColor(?Color $color): self
    {
        return new self(
            plans: $this->plans,
            headerColor: $this->headerColor,
            priceColor: $this->priceColor,
            borderColor: $this->borderColor,
            highlightColor: $color,
            featureColor: $this->featureColor,
            showBorders: $this->showBorders,
        );
    }

    /**
     * Set the feature text color.
     */
    public function withFeatureColor(?Color $color): self
    {
        return new self(
            plans: $this->plans,
            headerColor: $this->headerColor,
            priceColor: $this->priceColor,
            borderColor: $this->borderColor,
            highlightColor: $this->highlightColor,
            featureColor: $color,
            showBorders: $this->showBorders,
        );
    }

    /**
     * Toggle border visibility.
     */
    public function withBorders(bool $show): self
    {
        return new self(
            plans: $this->plans,
            headerColor: $this->headerColor,
            priceColor: $this->priceColor,
            borderColor: $this->borderColor,
            highlightColor: $this->highlightColor,
            featureColor: $this->featureColor,
            showBorders: $show,
        );
    }
}
