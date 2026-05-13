<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A single OHLC data point.
 */
final readonly class OHLCPoint
{
    public function __construct(
        public string $label,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public ?Color $color = null,
    ) {}

    /**
     * Create a bullish (up) point.
     */
    public static function bullish(string $label, float $open, float $high, float $low, float $close): self
    {
        return new self($label, $open, $high, $low, $close, Color::hex('#A6E3A1'));
    }

    /**
     * Create a bearish (down) point.
     */
    public static function bearish(string $label, float $open, float $high, float $low, float $close): self
    {
        return new self($label, $open, $high, $low, $close, Color::hex('#F38BA8'));
    }

    /**
     * Check if this is a bullish candle.
     */
    public function isBullish(): bool
    {
        return $this->close >= $this->open;
    }

    /**
     * Get the range (high - low).
     */
    public function getRange(): float
    {
        return $this->high - $this->low;
    }

    /**
     * Get the body size (absolute difference between open and close).
     */
    public function getBodySize(): float
    {
        return abs($this->close - $this->open);
    }
}

/**
 * An OHLC (Open-High-Low-Close) chart component for financial data.
 *
 * Features:
 * - Classic OHLC bar display with Wick markers
 * - Bullish/bearish coloring
 * - Time labels on X-axis
 * - Price scale on Y-axis
 * - Grid lines for readability
 * - Support for multiple display styles
 *
 * Mirrors OHLC chart patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class OHLC implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<OHLCPoint> */
    private array $points = [];

    private bool $showGrid = true;
    private bool $showLabels = true;
    private bool $showValues = true;
    private ?float $minPrice = null;
    private ?float $maxPrice = null;
    private string $style = 'rounded';

    public function __construct(
        private readonly ?Color $bullishColor = null,
        private readonly ?Color $bearishColor = null,
        private readonly ?Color $gridColor = null,
        private readonly ?Color $textColor = null,
        private readonly ?Color $backgroundColor = null,
    ) {}

    /**
     * Create a new OHLC chart with default styling.
     */
    public static function new(): self
    {
        return new self(
            bullishColor: Color::hex('#A6E3A1'),
            bearishColor: Color::hex('#F38BA8'),
            gridColor: Color::hex('#45475A'),
            textColor: Color::hex('#CDD6F4'),
            backgroundColor: Color::hex('#1E1E2E'),
        );
    }

    /**
     * Set the allocated dimensions for this OHLC chart.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Add an OHLC point.
     */
    public function withPoint(OHLCPoint $point): self
    {
        $clone = clone $this;
        $clone->points[] = $point;
        $clone->recalculatePriceRange();
        return $clone;
    }

    /**
     * Add an OHLC point by parameters.
     */
    public function addPoint(string $label, float $open, float $high, float $low, float $close): self
    {
        return $this->withPoint(new OHLCPoint($label, $open, $high, $low, $close));
    }

    /**
     * Set all points at once.
     *
     * @param list<OHLCPoint> $points
     */
    public function withPoints(array $points): self
    {
        $clone = clone $this;
        $clone->points = $points;
        $clone->recalculatePriceRange();
        return $clone;
    }

    /**
     * Show or hide grid.
     */
    public function withShowGrid(bool $show): self
    {
        $clone = clone $this;
        $clone->showGrid = $show;
        return $clone;
    }

    /**
     * Show or hide labels.
     */
    public function withShowLabels(bool $show): self
    {
        $clone = clone $this;
        $clone->showLabels = $show;
        return $clone;
    }

    /**
     * Show or hide values.
     */
    public function withShowValues(bool $show): self
    {
        $clone = clone $this;
        $clone->showValues = $show;
        return $clone;
    }

    /**
     * Set fixed price range.
     */
    public function withPriceRange(float $min, float $max): self
    {
        $clone = clone $this;
        $clone->minPrice = $min;
        $clone->maxPrice = $max;
        return $clone;
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        $clone = clone $this;
        $clone->style = $style;
        return $clone;
    }

    /**
     * Recalculate price range from data.
     */
    private function recalculatePriceRange(): void
    {
        if ($this->points === []) {
            return;
        }

        $prices = [];
        foreach ($this->points as $point) {
            $prices[] = $point->high;
            $prices[] = $point->low;
        }

        $this->minPrice = min($prices);
        $this->maxPrice = max($prices);

        $range = $this->maxPrice - $this->minPrice;
        if ($range > 0) {
            $this->minPrice = $this->minPrice - $range * 0.05;
            $this->maxPrice = $this->maxPrice + $range * 0.05;
        }
    }

    /**
     * Render the OHLC chart as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 65;
        $useHeight = $this->height ?? 15;

        if ($useWidth < 30 || $useHeight < 8) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $gridColor = $this->gridColor ?? Color::hex('#45475A');
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');
        $bullishColor = $this->bullishColor ?? Color::hex('#A6E3A1');
        $bearishColor = $this->bearishColor ?? Color::hex('#F38BA8');

        $result = '';

        // Title
        $title = 'OHLC Chart';
        $titlePadding = intval(($useWidth - 2 - strlen($title)) / 2);
        $result .= $tl . str_repeat(' ', $titlePadding) . $title . str_repeat(' ', $useWidth - 2 - $titlePadding - strlen($title)) . $tr . "\n";

        $chartHeight = $useHeight - 4;
        $chartWidth = $useWidth - 2;

        // Price scale on the right
        $priceScaleWidth = 8;

        // Grid lines
        if ($this->showGrid) {
            $gridLines = min($chartHeight - 1, 5);
            for ($i = 0; $i < $gridLines; $i++) {
                $y = intval($i * ($chartHeight - 1) / max(1, $gridLines - 1));
                $price = $this->maxPrice - (($this->maxPrice - $this->minPrice) * $i / max(1, $gridLines - 1));
                $priceLabel = sprintf('%6.2f', $price);
                $gridLine = str_repeat('·', $chartWidth - $priceScaleWidth - 1);
                $result .= $v . $gridLine . $v . $priceLabel . $v . "\n";
            }
        }

        // Render OHLC bars
        $pointCount = count($this->points);
        if ($pointCount === 0) {
            $result .= $v . str_repeat(' ', $chartWidth) . $v . "\n";
        } else {
            $barWidth = 2;
            $spacing = max(0, intval(($chartWidth - $priceScaleWidth - 1 - $pointCount * $barWidth) / max(1, $pointCount - 1)));

            foreach ($this->points as $index => $point) {
                $isBullish = $point->isBullish();
                $color = $point->color ?? ($isBullish ? $bullishColor : $bearishColor);

                // Calculate Y positions
                $highY = $this->priceToY($point->high, $chartHeight);
                $lowY = $this->priceToY($point->low, $chartHeight);
                $openY = $this->priceToY($point->open, $chartHeight);
                $closeY = $this->priceToY($point->close, $chartHeight);

                // Draw the OHLC bar
                $x = $index * ($barWidth + $spacing);

                // Wick (vertical line from low to high)
                $wickLeft = $x + intval($barWidth / 2);
                for ($y = $chartHeight - 1 - $lowY; $y <= $chartHeight - 1 - $highY; $y--) {
                    if ($y >= 0 && $y < $chartHeight) {
                        // Draw wick character at proper position
                        $lineIndex = $chartHeight - 1 - $y;
                        $resultLine = str_repeat(' ', $x) . $v;
                    }
                }

                // Build bar representation
                $barTop = min($openY, $closeY);
                $barBottom = max($openY, $closeY);

                // Draw vertical structure
                for ($row = 0; $row < $chartHeight; $row++) {
                    $yCoord = $chartHeight - 1 - $row;

                    // Determine if this row is part of the bar
                    $inHighWick = ($yCoord <= $highY);
                    $inLowWick = ($yCoord >= $lowY);
                    $inBody = ($yCoord >= $barBottom && $yCoord <= $barTop);

                    $barChars = str_repeat(' ', $barWidth);

                    if ($inHighWick || $inLowWick) {
                        // Wick line
                        $barChars = str_repeat('─', $barWidth);
                    }

                    if ($inBody) {
                        // Body - use block characters
                        $openChar = 'O';
                        $closeChar = 'C';
                        if ($isBullish) {
                            $barChars = '╂'; // Bullish marker
                        } else {
                            $barChars = '╋'; // Bearish marker
                        }
                    }

                    // Color and output
                    if ($color !== null) {
                        $result .= $color->toFg(ColorProfile::TrueColor);
                    }
                    $result .= $v . str_repeat(' ', $x) . $barChars . str_repeat(' ', $chartWidth - $x - $barWidth - $priceScaleWidth - 1) . $v . "\n";
                    if ($color !== null) {
                        $result .= Ansi::reset();
                    }
                }
            }
        }

        // Labels row
        if ($this->showLabels && $pointCount > 0) {
            $labelLine = $v . ' ';
            foreach ($this->points as $index => $point) {
                $label = mb_substr($point->label, 0, 2, 'UTF-8');
                $barWidth = 2;
                $spacing = max(0, intval(($chartWidth - $priceScaleWidth - 1 - $pointCount * $barWidth) / max(1, $pointCount - 1)));
                $x = $index * ($barWidth + $spacing);
                $labelLine .= str_repeat(' ', $x) . str_pad($label, $barWidth, ' ', STR_PAD_BOTH);
            }
            $labelLine .= str_repeat(' ', $chartWidth - $priceScaleWidth - 1 - $x - $barWidth) . $v;
            $result .= $labelLine . "\n";
        }

        // Bottom border
        $result .= $bl . str_repeat($h, $useWidth - 2) . $br;

        return $result;
    }

    /**
     * Convert a price to Y coordinate.
     */
    private function priceToY(float $price, int $height): int
    {
        $range = $this->maxPrice - $this->minPrice;
        if ($range == 0) {
            return intval($height / 2);
        }
        $normalized = ($price - $this->minPrice) / $range;
        return intval($normalized * ($height - 1));
    }

    /**
     * Get the style characters for the border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string}
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['╭', '╮', '╰', '╯', '─', '│'],
        };
    }

    /**
     * Calculate the natural dimensions of this OHLC chart.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 65;
        $height = $this->height ?? 15;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the bullish color.
     */
    public function withBullishColor(?Color $color): self
    {
        return new self(
            bullishColor: $color,
            bearishColor: $this->bearishColor,
            gridColor: $this->gridColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
        );
    }

    /**
     * Set the bearish color.
     */
    public function withBearishColor(?Color $color): self
    {
        return new self(
            bullishColor: $this->bullishColor,
            bearishColor: $color,
            gridColor: $this->gridColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
        );
    }

    /**
     * Set the grid color.
     */
    public function withGridColor(?Color $color): self
    {
        return new self(
            bullishColor: $this->bullishColor,
            bearishColor: $this->bearishColor,
            gridColor: $color,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            bullishColor: $this->bullishColor,
            bearishColor: $this->bearishColor,
            gridColor: $this->gridColor,
            textColor: $color,
            backgroundColor: $this->backgroundColor,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            bullishColor: $this->bullishColor,
            bearishColor: $this->bearishColor,
            gridColor: $this->gridColor,
            textColor: $this->textColor,
            backgroundColor: $color,
        );
    }
}
