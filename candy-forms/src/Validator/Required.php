<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Validator;

use SugarCraft\Forms\Lang;

/**
 * Validates that input is not empty.
 */
final class Required implements Validator
{
    public function validate(string $input): true|string
    {
        if ($input === '') {
            return Lang::t('validator.required');
        }
        return true;
    }
}
