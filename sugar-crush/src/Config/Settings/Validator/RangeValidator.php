<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Validator;

use SugarCraft\Crush\Config\Settings\SettingValidator;

/**
 * A number inside an inclusive range. Either bound may be open.
 *
 * A NUMERIC STRING IS REFUSED, deliberately stricter than PHP's `is_numeric`:
 * the readers this guards (`maxToolSteps`, `parallelToolDeadlineSeconds`) take
 * JSON numbers, and a quoted `"90"` written by a UI would be a value the next
 * reader has to guess about.
 */
final class RangeValidator implements SettingValidator
{
    private function __construct(
        public readonly int|float|null $min,
        public readonly int|float|null $max,
        public readonly bool $integer,
    ) {
    }

    public static function new(int|float|null $min = null, int|float|null $max = null, bool $integer = true): self
    {
        if ($min !== null && $max !== null && $min > $max) {
            throw new \InvalidArgumentException("range minimum {$min} exceeds maximum {$max}");
        }

        return new self($min, $max, $integer);
    }

    public function validate(mixed $value, array $all = []): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($this->integer ? !\is_int($value) : !(\is_int($value) || \is_float($value))) {
            return $this->integer ? 'must be a whole number' : 'must be a number';
        }

        if ($this->min !== null && $value < $this->min) {
            return "must be at least {$this->min}";
        }

        if ($this->max !== null && $value > $this->max) {
            return "must be at most {$this->max}";
        }

        return null;
    }
}
