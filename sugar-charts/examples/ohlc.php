<?php

declare(strict_types=1);

/**
 * OHLC — candlestick chart from a hand-rolled price series.
 *
 *   php examples/ohlc.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Charts\OHLC\Candle;
use SugarCraft\Charts\OHLC\OHLCChart;

// 12 days of synthetic price action.
$bars = [
    new Candle(open: 100, high: 105, low:  98, close: 103),
    new Candle(open: 103, high: 110, low: 102, close: 108),
    new Candle(open: 108, high: 109, low: 100, close: 101),
    new Candle(open: 101, high: 104, low:  95, close:  97),
    new Candle(open:  97, high: 100, low:  93, close:  99),
    new Candle(open:  99, high: 106, low:  98, close: 105),
    new Candle(open: 105, high: 112, low: 104, close: 110),
    new Candle(open: 110, high: 115, low: 108, close: 109),
    new Candle(open: 109, high: 111, low: 100, close: 102),
    new Candle(open: 102, high: 108, low: 101, close: 107),
    new Candle(open: 107, high: 113, low: 106, close: 112),
    new Candle(open: 112, high: 118, low: 110, close: 116),
];

echo OHLCChart::new($bars, width: 50, height: 14)->view() . "\n";
