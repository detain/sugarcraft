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
}
