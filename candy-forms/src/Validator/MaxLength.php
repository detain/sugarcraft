<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Validator;

use SugarCraft\Forms\Lang;

/**
 * Validates that input does not exceed a maximum length.
 */
final class MaxLength implements Validator
{
    public function __construct(
        public readonly int $max,
    ) {}

    public function validate(string $input): true|string
    {
        if (mb_strlen($input, 'UTF-8') > $this->max) {
            return Lang::t('validator.max_length', ['max' => (string) $this->max]);
        }
        return true;
    }
}
