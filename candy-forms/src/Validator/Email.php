<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Validator;

use SugarCraft\Forms\Lang;

/**
 * Validates that input is a valid email address.
 */
final class Email implements Validator
{
    public function validate(string $input): true|string
    {
        if ($input === '') {
            return true;
        }
        if (!filter_var($input, FILTER_VALIDATE_EMAIL)) {
            return Lang::t('validator.email');
        }
        return true;
    }
}
