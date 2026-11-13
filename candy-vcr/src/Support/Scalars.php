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
     * Timestamp/duration coercion for the trust doors (findings 001/002):
     * numeric literals and numeric strings only — no silent PHP cast table
     * behind a garbage value. `float("abc") === 0.0` would rewrite a corrupt
     * timestamp into a valid frame at t=0, the exact laundering this door
     * forbids; a non-numeric scalar is rejected by name instead.
     *
     * Bounds mirror {@see \SugarCraft\Vcr\Event::MAX_TIMESTAMP_SECONDS}:
     * finite, non-negative, within the 24 h cassette ceiling.
     *
     * @throws \InvalidArgumentException When $value is not a numeric scalar
     *   within the seconds bounds, or is a container.
     */
    public static function seconds(mixed $value, string $where): float
    {
        $reject = static function () use ($where, $value): never {
            if (\is_string($value)) {
                $shown = "'" . (strlen($value) > 40 ? substr($value, 0, 40) . '…' : $value) . "'";
            } elseif (\is_scalar($value) || $value === null) {
                $shown = var_export($value, true);
            } else {
                $shown = get_debug_type($value);
            }
            throw new \InvalidArgumentException(sprintf(
                '%s must be a finite non-negative number of seconds at most %s, %s given',
                $where,
                (string) \SugarCraft\Vcr\Event::MAX_TIMESTAMP_SECONDS,
                $shown,
            ));
        };

        if (\is_int($value)) {
            $seconds = (float) $value;
        } elseif (\is_float($value)) {
            $seconds = $value;
        } elseif (\is_string($value) && \is_numeric($value)) {
            $seconds = (float) $value;
        } else {
            $reject();
        }

        if (!\is_finite($seconds) || $seconds < 0.0 || $seconds > \SugarCraft\Vcr\Event::MAX_TIMESTAMP_SECONDS) {
            $reject();
        }

        return $seconds;
    }

    /**
     * @throws \InvalidArgumentException Always — containers cannot be scalars.
     */
    private static function reject(string $where, string $want, mixed $value): never
    {
        throw new \InvalidArgumentException(sprintf(
            '%s must be %s, %s given',
            $where,
            $want,
            get_debug_type($value),
        ));
    }
}
