<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Support;

/**
 * Scalar coercion at the JSON trust boundary.
 *
 * `json_decode(..., true)` yields `mixed` throughout a cassette's shape,
 * and level-max static analysis refuses blind casts of `mixed`. These
 * helpers reproduce PHP's cast table exactly for `null|bool|int|float|
 * string` inputs — the only shapes a well-formed tape ever carries — and
 * fail loud on containers instead of silently coercing an array to `1`.
 */
final class Scalars
{
    private function __construct()
    {
    }

    /**
     * `(int)`-identical for every scalar and null; throws on array/object.
     *
     * @param string $where Human-readable field path for the error message.
     *
     * @throws \InvalidArgumentException When $value is an array or object.
     */
    public static function int(mixed $value, string $where): int
    {
        if (\is_scalar($value)) {
            return (int) $value;
        }
        if ($value === null) {
            return 0;
        }

        self::reject($where, 'int', $value);
    }

    /**
     * `(string)`-identical for every scalar and null; throws on array/object.
     *
     * @throws \InvalidArgumentException When $value is an array or object.
     */
    public static function string(mixed $value, string $where): string
    {
        if (\is_scalar($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return '';
        }

        self::reject($where, 'string', $value);
    }

    /**
     * `(float)`-identical for every scalar and null; throws on array/object.
     *
     * @throws \InvalidArgumentException When $value is an array or object.
     */
    public static function float(mixed $value, string $where): float
    {
        if (\is_scalar($value)) {
            return (float) $value;
        }
        if ($value === null) {
            return 0.0;
        }

        self::reject($where, 'float', $value);
    }

    /**
     * `(bool)`-identical for every scalar and null; throws on array/object.
     *
     * @throws \InvalidArgumentException When $value is an array or object.
     */
    public static function bool(mixed $value, string $where): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_scalar($value) || $value === null) {
            return (bool) $value;
        }

        self::reject($where, 'bool', $value);
    }

    /**
     * @throws \InvalidArgumentException Always — containers cannot be scalars.
     */
    private static function reject(string $where, string $want, mixed $value): never
    {
        throw new \InvalidArgumentException(sprintf(
            'candy-vcr: %s must be %s, %s given',
            $where,
            $want,
            get_debug_type($value),
        ));
    }
}
