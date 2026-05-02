<?php

declare(strict_types=1);

namespace CandyCore\Charts\OHLC;

/**
 * One Open / High / Low / Close bar for {@see OHLCChart}. Immutable.
 *
 * `$open` and `$close` define the body; `$high` and `$low` extend the
 * wick. A bullish bar (`$close > $open`) renders with one glyph; a
 * bearish bar (`$close < $open`) with another.
 */
final class Bar
{
    public function __construct(
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
    ) {}

    public function isBullish(): bool { return $this->close > $this->open; }
    public function isBearish(): bool { return $this->close < $this->open; }

    public function bodyTop(): float    { return max($this->open, $this->close); }
    public function bodyBottom(): float { return min($this->open, $this->close); }
}
