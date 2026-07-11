<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Support;

use SugarCraft\Charts\Lang;

/**
 * Numeric ingestion guard shared by every chart type.
 *
 * A NaN or ±INF slipping into the data set silently corrupts downstream
 * maths: `$max == $min` is *false* when either operand is NaN, so the
 * divide-by-zero guards every chart relies on are defeated and a `NaN`
 * range flows into `(int) round(...)` / `(int) floor(...)`, collapsing to
 * `0` or indexing a colour palette out of bounds. Rejecting non-finite
 * values at the point they first enter a chart keeps those invariants
 * intact.
 *
 * Follows the SugarCraft "no silent failures" convention — a non-finite
 * value is a caller error, so it throws rather than coercing to zero.
 */
final class Finite
{
    private function __construct() {}

    /**
     * Assert a single value is finite and return it as a float.
     *
     * @throws \InvalidArgumentException when $value is NaN or ±INF
     */
    public static function assert(int|float $value): float
    {
        $f = (float) $value;
        if (!is_finite($f)) {
            throw new \InvalidArgumentException(Lang::t('finite.non_finite', ['value' => self::describe($f)]));
        }
        return $f;
    }

    /**
     * Assert every value in a flat list is finite.
     *
     * @param iterable<int|float> $values
     *
     * @throws \InvalidArgumentException on the first non-finite value
     */
    public static function assertAll(iterable $values): void
    {
        foreach ($values as $v) {
            self::assert($v);
        }
    }

    private static function describe(float $f): string
    {
        if (is_nan($f)) {
            return 'NaN';
        }

        return $f > 0 ? 'INF' : '-INF';
    }
}
