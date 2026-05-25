<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Validator;

/**
 * Input validator for Field\Input.
 *
 * Returns true on valid input, an error string on invalid input.
 */
interface Validator
{
    /**
     * @return true|string True if $input is valid, error message string otherwise
     */
    public function validate(string $input): true|string;
}