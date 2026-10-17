<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

use SugarCraft\Core\Lang;

/**
 * Shared validation helpers to eliminate repeated InvalidArgumentException patterns.
 * Each method throws with a translatable message when validation fails.
 */
final class Validation
{
    /**
     * Validate that a value is non-negative (>= 0).
     *
     * @throws \InvalidArgumentException
     */
    public static function nonNeg(int $value, string $name = 'value'): int
    {
        if ($value < 0) {
            throw new \InvalidArgumentException(Lang::t('errors.negative_not_allowed', ['name' => $name]));
        }
        return $value;
    }

    /**
     * Validate that a value is strictly positive (> 0).
     *
     * @throws \InvalidArgumentException
     */
    public static function positive(int $value, string $name = 'value'): int
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException(Lang::t('errors.value_must_be_positive', [
                'name' => $name,
                'value' => (string) $value,
            ]));
        }
        return $value;
    }

    /**
     * Validate that a value is within the closed interval [min, max].
     *
     * @throws \InvalidArgumentException
     */
    public static function range(int $value, int $min, int $max, string $name = 'value'): int
    {
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(Lang::t('errors.value_out_of_range', [
                'name' => $name,
                'value' => (string) $value,
                'min' => (string) $min,
                'max' => (string) $max,
            ]));
        }
        return $value;
    }
}
