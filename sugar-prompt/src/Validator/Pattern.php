<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Validator;

/**
 * Validates that input matches a given regex pattern.
 */
final class Pattern implements Validator
{
    public function __construct(
        public readonly string $pattern,
        public readonly string $message = 'Input does not match required format',
    ) {}

    public function validate(string $input): true|string
    {
        if ($input === '') {
            return true;
        }
        if (preg_match($this->pattern, $input) !== 1) {
            return $this->message;
        }
        return true;
    }
}
