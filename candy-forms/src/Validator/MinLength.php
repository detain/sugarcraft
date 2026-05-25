<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Validator;

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
            return "Must be at least {$this->min} characters";
        }
        return true;
    }
}