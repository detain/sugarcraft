<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Validator;

use SugarCraft\Forms\Lang;

/**
 * Validates that input meets a minimum length.
 */
final class MinLength implements Validator
{
    public function __construct(
        public readonly int $min,
    ) {}

    public function validate(string $input): true|string
    {
        if (mb_strlen($input, 'UTF-8') < $this->min) {
            return Lang::t('validator.min_length', ['min' => (string) $this->min]);
        }
        return true;
    }
}
