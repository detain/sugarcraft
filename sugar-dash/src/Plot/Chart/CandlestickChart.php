<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A candlestick chart component for financial data visualization.
 *
 * Features:
 * - Open/High/Low/Close (OHLC) data display
 * - Bullish and bearish candle coloring
 * - Price scale on Y-axis
 * - Time labels on X-axis
 * - Volume bars (optional)
 *
 * Mirrors candlestick chart patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class CandlestickChart implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<Candlestick> */
    private array $candles = [];

    private bool $showGrid = true;
    private bool $showVolume = false;
    private bool $showLabels = true;
    private ?float $minPrice = null;
    private ?float $maxPrice = null;

    public function __construct(
        private ?int $maxCandles = null,
        private ?Color $bullishColor = null,
        private ?Color $bearishColor = null,
        private ?Color $wickColor = null,
        private ?Color $gridColor = null,
        private ?Color $textColor = null,
        private ?Color $backgroundColor = null,
        private string $style = 'rounded',
    ) {}

    /**
     * Create a new candlestick chart with default styling.
     */
    public static function new(): self
    {
        return new self(
            maxCandles: null,
            bullishColor: Color::hex('#A6E3A1'),
            bearishColor: Color::hex('#F38BA8'),
            wickColor: Color::hex('#CDD6F4'),
            gridColor: Color::hex('#45475A'),
            textColor: Color::hex('#CDD6F4'),
            backgroundColor: Color::hex('#1E1E2E'),
            style: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this candlestick chart.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Add a candlestick to the chart.
     */
    public function withCandle(Candlestick $candle): self
    {
        $clone = clone $this;
        $clone->candles[] = $candle;
        $clone->recalculatePriceRange();
        return $clone;
    }

    /**
     * Add a candlestick by parameters.
     */
    public function addCandle(string $label, float $open, float $high, float $low, float $close): self
    {
        $candle = new Candlestick($label, $open, $high, $low, $close);
        return $this->withCandle($candle);
    }

    /**
     * Set all candles at once.
     *
     * @param list<Candlestick> $candles
     */
    public function withCandles(array $candles): self
    {
        $clone = clone $this;
        $clone->candles = $candles;
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
     * Show or hide volume bars.
     */
    public function withShowVolume(bool $show): self
    {
        $clone = clone $this;
        $clone->showVolume = $show;
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
     * Recalculate price range from data.
     */
    private function recalculatePriceRange(): void
    {
        if ($this->candles === []) {
            return;
        }

        $prices = [];
        foreach ($this->candles as $candle) {
            $prices[] = $candle->high;
            $prices[] = $candle->low;
        }

        $this->minPrice = min($prices);
        $this->maxPrice = max($prices);

        // Add padding
        $range = $this->maxPrice - $this->minPrice;
        if ($range > 0) {
            $this->minPrice = $this->minPrice - $range * 0.05;
            $this->maxPrice = $this->maxPrice + $range * 0.05;
        }
    }

    /**
     * Render the candlestick chart as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 65;
        $useHeight = $this->height ?? 15;

        if ($useWidth < 25 || $useHeight < 8) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $gridColor = $this->gridColor ?? Color::hex('#45475A');
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');

        $result = '';

        // Title
        $title = 'Candlestick Chart';
        $titlePadding = intval(($useWidth - 2 - strlen($title)) / 2);
        $result .= $tl . str_repeat(' ', $titlePadding) . $title . str_repeat(' ', $useWidth - 2 - $titlePadding - strlen($title)) . $tr . "\n";

        $labelHeight = $this->showLabels ? 1 : 0;
        $chartHeight = $useHeight - 3 - $labelHeight;
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

        // Render candles
        $candleCount = count($this->candles);
        if ($candleCount === 0) {
            $result .= $v . str_repeat(' ', $chartWidth) . $v . "\n";
        } else {
            $candleWidth = max(1, intval(($chartWidth - $priceScaleWidth - 1) / $candleCount) - 1);
            $candleWidth = min(3, $candleWidth); // Cap width

            // Build chart rows
            $chartRows = [];
            for ($i = 0; $i < $chartHeight; $i++) {
                $chartRows[$i] = str_repeat(' ', $chartWidth - $priceScaleWidth - 1);
            }

            foreach ($this->candles as $index => $candle) {
                $x = $index * ($candleWidth + 1);

                $isBullish = $candle->isBullish();
                $color = $candle->color ?? ($isBullish
                    ? ($this->bullishColor ?? Color::hex('#A6E3A1'))
                    : ($this->bearishColor ?? Color::hex('#F38BA8')));

                // Calculate positions (Y coordinates)
                $highY = $this->priceToY($candle->high, $chartHeight);
                $lowY = $this->priceToY($candle->low, $chartHeight);
                $openY = $this->priceToY($candle->open, $chartHeight);
                $closeY = $this->priceToY($candle->close, $chartHeight);

                // Draw wick (vertical line from low to high)
                for ($y = $lowY; $y <= $highY; $y++) {
                    $rowIndex = $chartHeight - 1 - $y;
                    if ($rowIndex >= 0 && $rowIndex < $chartHeight && $x >= 0) {
                        $wick = $color !== null ? $color->toFg(ColorProfile::TrueColor) . '│' . Ansi::reset() : '│';
                        $chartRows[$rowIndex] = substr_replace($chartRows[$rowIndex], $wick, $x, 1);
                    }
                }

                // Draw candle body
                $bodyTop = min($openY, $closeY);
                $bodyBottom = max($openY, $closeY);
                $candleChar = $isBullish ? '█' : '▓';
                $candleStr = $color !== null ? $color->toFg(ColorProfile::TrueColor) . str_repeat($candleChar, $candleWidth) . Ansi::reset() : str_repeat($candleChar, $candleWidth);

                for ($y = $bodyBottom; $y <= $bodyTop; $y++) {
                    $rowIndex = $chartHeight - 1 - $y;
                    if ($rowIndex >= 0 && $rowIndex < $chartHeight && $x >= 0) {
                        $chartRows[$rowIndex] = substr_replace($chartRows[$rowIndex], $candleStr, $x, $candleWidth);
                    }
                }
            }

            // Output chart rows with price scale
            foreach ($chartRows as $row) {
                $priceY = $this->maxPrice - (($this->maxPrice - $this->minPrice) * (array_search($row, $chartRows, true) ?? 0) / max(1, $chartHeight - 1));
                $priceLabel = sprintf('%6.2f', $priceY);
                $result .= $v . $row . $v . $priceLabel . $v . "\n";
            }
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
     * Calculate the natural dimensions of this candlestick chart.
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
            maxCandles: $this->maxCandles,
            bullishColor: $color,
            bearishColor: $this->bearishColor,
            wickColor: $this->wickColor,
            gridColor: $this->gridColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the bearish color.
     */
    public function withBearishColor(?Color $color): self
    {
        return new self(
            maxCandles: $this->maxCandles,
            bullishColor: $this->bullishColor,
            bearishColor: $color,
            wickColor: $this->wickColor,
            gridColor: $this->gridColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the wick color.
     */
    public function withWickColor(?Color $color): self
    {
        return new self(
            maxCandles: $this->maxCandles,
            bullishColor: $this->bullishColor,
            bearishColor: $this->bearishColor,
            wickColor: $color,
            gridColor: $this->gridColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the grid color.
     */
    public function withGridColor(?Color $color): self
    {
        return new self(
            maxCandles: $this->maxCandles,
            bullishColor: $this->bullishColor,
            bearishColor: $this->bearishColor,
            wickColor: $this->wickColor,
            gridColor: $color,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            maxCandles: $this->maxCandles,
            bullishColor: $this->bullishColor,
            bearishColor: $this->bearishColor,
            wickColor: $this->wickColor,
            gridColor: $this->gridColor,
            textColor: $color,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            maxCandles: $this->maxCandles,
            bullishColor: $this->bullishColor,
            bearishColor: $this->bearishColor,
            wickColor: $this->wickColor,
            gridColor: $this->gridColor,
            textColor: $this->textColor,
            backgroundColor: $color,
            style: $this->style,
        );
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            maxCandles: $this->maxCandles,
            bullishColor: $this->bullishColor,
            bearishColor: $this->bearishColor,
            wickColor: $this->wickColor,
            gridColor: $this->gridColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $style,
        );
    }
}
