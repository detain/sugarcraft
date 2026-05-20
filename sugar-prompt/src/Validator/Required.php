<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Validator;

/**
 * Validates that input is not empty.
 */
final class Required implements Validator
{
    public function validate(string $input): true|string
    {
        if ($input === '') {
            return 'Value is required';
        }
        return true;
    }
}
