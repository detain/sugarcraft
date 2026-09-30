<?php

declare(strict_types=1);

namespace SugarCraft\Charts\OHLC;

use SugarCraft\Charts\Support\Finite;

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
    ) {
        // Audit F7: a non-finite price poisons the chart's range scan exactly
        // like non-finite data anywhere else — rejected at this sole door.
        Finite::assert($open);
        Finite::assert($high);
        Finite::assert($low);
        Finite::assert($close);
        // Audit F8: a wick whose high sits below its low cannot be drawn —
        // the former behaviour was a silently blank bar. Throwing matches the
        // sibling ingestion-door style (BarChart\Bar, HeatPoint). Body
        // containment (low<=min(o,c)<=max(o,c)<=high) is deliberately NOT
        // enforced: coarse real-world feeds legitimately emit bodies poking
        // past recorded wicks, and the renderer clamps those anyway.
        if ($high < $low) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid OHLC bar: high (%s) must not be below low (%s)',
                (string) $high,
                (string) $low,
            ));
        }
    }

    public function isBullish(): bool { return $this->close > $this->open; }
    public function isBearish(): bool { return $this->close < $this->open; }

    public function bodyTop(): float    { return max($this->open, $this->close); }
    public function bodyBottom(): float { return min($this->open, $this->close); }
}
