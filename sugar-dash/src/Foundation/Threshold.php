<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

use SugarCraft\Core\Util\Color;

/**
 * Maps a numeric value (typically a 0..1 ratio) to a {@see Color} via ordered
 * stops — the "green below 60%, yellow below 80%, red above" pattern that
 * gauges, meters, and status indicators reinvent everywhere.
 *
 * A value maps to the color of the first stop whose `limit` it is strictly
 * below; values at or above the highest limit take that highest stop's color
 * (use `INF` as the top limit to make it the catch-all, as {@see health()}
 * does).
 */
final class Threshold
{
    /**
     * @param list<array{limit:float, color:Color}> $stops ascending by limit
     */
    private function __construct(
        private readonly array $stops,
    ) {}

    /**
     * Build from `[limit, Color]` pairs in any order; they are sorted ascending
     * by limit.
     *
     * @param list<array{0:float|int, 1:Color}> $stops
     */
    public static function of(array $stops): self
    {
        $normalized = array_map(
            static fn(array $s): array => ['limit' => (float) $s[0], 'color' => $s[1]],
            $stops,
        );
        usort($normalized, static fn(array $a, array $b): int => $a['limit'] <=> $b['limit']);
        return new self($normalized);
    }

    /**
     * The canonical health ramp: green for `< 0.6`, yellow for `< 0.8`, red at
     * or above `0.8`. Colors are overridable for theming.
     */
    public static function health(
        ?Color $ok = null,
        ?Color $warn = null,
        ?Color $critical = null,
    ): self {
        return self::of([
            [0.6, $ok ?? Color::hex('#4ade80')],
            [0.8, $warn ?? Color::hex('#facc15')],
            [INF, $critical ?? Color::hex('#f87171')],
        ]);
    }

    /**
     * Resolve the color for a value.
     */
    public function colorFor(float $value): Color
    {
        foreach ($this->stops as $stop) {
            if ($value < $stop['limit']) {
                return $stop['color'];
            }
        }
        // Reached only when every limit is finite and $value exceeds them all.
        return $this->stops[array_key_last($this->stops)]['color'];
    }

    /**
     * @return list<array{limit:float, color:Color}>
     */
    public function stops(): array
    {
        return $this->stops;
    }
}
