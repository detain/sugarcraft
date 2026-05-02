<?php

declare(strict_types=1);

namespace CandyCore\Charts\OHLC;

use CandyCore\Charts\Canvas\Canvas;
use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Style;

/**
 * OHLC / candlestick chart drawn onto a {@see Canvas}. Each bar gets
 * one column: a vertical wick spanning low → high, with a thicker
 * body covering open ↔ close. Bullish bars (close > open) and bearish
 * bars (close < open) use distinct glyphs and colours so the
 * direction of motion is obvious at a glance.
 *
 * Mirrors ntcharts' `linechart/timeseries/candlestick` and the canvas/graph
 * `drawCandlestick` helper.
 *
 * ```php
 * $bars = [
 *     new Bar(open: 100.0, high: 110.0, low: 95.0,  close: 108.0),
 *     new Bar(open: 108.0, high: 112.0, low: 100.0, close: 102.0),
 *     // ...
 * ];
 * echo OHLCChart::new($bars, 30, 10)->view();
 * ```
 */
final class OHLCChart
{
    /** @param list<Bar> $bars */
    private function __construct(
        public readonly array $bars,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly string $bodyBullish,
        public readonly string $bodyBearish,
        public readonly string $wick,
        public readonly ?Color $bullishColor,
        public readonly ?Color $bearishColor,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('OHLC chart width/height must be >= 0');
        }
    }

    /** @param list<Bar> $bars */
    public static function new(array $bars = [], int $width = 40, int $height = 12): self
    {
        return new self(
            bars:          array_values($bars),
            width:         $width,
            height:        $height,
            min:           null,
            max:           null,
            bodyBullish:   '█',
            bodyBearish:   '▒',
            wick:          '│',
            bullishColor:  Color::ansi(10),  // bright green
            bearishColor:  Color::ansi(9),   // bright red
        );
    }

    /** @param list<Bar> $bars */
    public function withBars(array $bars): self
    {
        return $this->copy(bars: array_values($bars));
    }

    public function push(Bar $bar): self
    {
        return $this->copy(bars: [...$this->bars, $bar]);
    }

    public function withSize(int $w, int $h): self
    {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException('OHLC chart width/height must be >= 0');
        }
        return $this->copy(width: $w, height: $h);
    }

    public function withMin(?float $m): self  { return $this->copy(min: $m, minSet: true); }
    public function withMax(?float $m): self  { return $this->copy(max: $m, maxSet: true); }
    public function withBodyRunes(string $bull, string $bear): self
    {
        return $this->copy(bodyBullish: $bull, bodyBearish: $bear);
    }
    public function withWickRune(string $rune): self
    {
        return $this->copy(wick: $rune);
    }
    public function withColors(?Color $bullish, ?Color $bearish): self
    {
        return $this->copy(bullishColor: $bullish, bullishColorSet: true, bearishColor: $bearish, bearishColorSet: true);
    }

    public function view(): string
    {
        if ($this->bars === [] || $this->width === 0 || $this->height === 0) {
            return (new Canvas($this->width, $this->height))->view();
        }
        $bars = $this->bars;
        if (count($bars) > $this->width) {
            $bars = array_slice($bars, -$this->width);
        }
        $count = count($bars);

        // Compute global range across all OHLC values.
        $values = [];
        foreach ($bars as $b) {
            $values[] = $b->high;
            $values[] = $b->low;
        }
        $min = $this->min ?? min($values);
        $max = $this->max ?? max($values);
        if ($max == $min) { $max = $min + 1.0; }

        $canvas = new Canvas($this->width, $this->height);

        $rowFor = function (float $v) use ($min, $max): int {
            $norm = ((float) $v - $min) / ($max - $min);
            $norm = max(0.0, min(1.0, $norm));
            return (int) round((1.0 - $norm) * ($this->height - 1));
        };

        foreach ($bars as $i => $bar) {
            $col = $count <= 1
                ? 0
                : (int) round($i * ($this->width - 1) / ($count - 1));
            $highRow  = $rowFor($bar->high);
            $lowRow   = $rowFor($bar->low);
            $bodyTop  = $rowFor($bar->bodyTop());
            $bodyBot  = $rowFor($bar->bodyBottom());

            $bull = $bar->isBullish();
            $color = $bull ? $this->bullishColor : $this->bearishColor;
            $body  = $bull ? $this->bodyBullish  : $this->bodyBearish;
            $style = $color !== null ? Style::new()->foreground($color) : null;

            // Wick: high → low, with body cells overwritten.
            for ($r = $highRow; $r <= $lowRow; $r++) {
                $rune = ($r >= $bodyTop && $r <= $bodyBot) ? $body : $this->wick;
                $canvas->setCell($col, $r, $rune, $style);
            }
        }
        return $canvas->view();
    }

    public function __toString(): string { return $this->view(); }

    private function copy(
        ?array $bars = null,
        ?int $width = null,
        ?int $height = null,
        ?float $min = null, bool $minSet = false,
        ?float $max = null, bool $maxSet = false,
        ?string $bodyBullish = null,
        ?string $bodyBearish = null,
        ?string $wick = null,
        ?Color $bullishColor = null, bool $bullishColorSet = false,
        ?Color $bearishColor = null, bool $bearishColorSet = false,
    ): self {
        return new self(
            bars:         $bars         ?? $this->bars,
            width:        $width        ?? $this->width,
            height:       $height       ?? $this->height,
            min:          $minSet ? $min : $this->min,
            max:          $maxSet ? $max : $this->max,
            bodyBullish:  $bodyBullish  ?? $this->bodyBullish,
            bodyBearish:  $bodyBearish  ?? $this->bodyBearish,
            wick:         $wick         ?? $this->wick,
            bullishColor: $bullishColorSet ? $bullishColor : $this->bullishColor,
            bearishColor: $bearishColorSet ? $bearishColor : $this->bearishColor,
        );
    }
}
