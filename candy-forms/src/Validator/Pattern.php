<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Validator;

use SugarCraft\Forms\Lang;

/**
 * Validates that input matches a given regex pattern.
 */
final class Pattern implements Validator
{
    public function __construct(
        public readonly string $pattern,
        public readonly ?string $message = null,
    ) {
        if (@preg_match($this->pattern, '') === false) {
            throw new \InvalidArgumentException('Invalid regex pattern: ' . $this->pattern);
        }
    }

    public function validate(string $input): true|string
    {
        if ($input === '') {
            return true;
        }
        if (preg_match($this->pattern, $input) !== 1) {
            return $this->message ?? Lang::t('validator.pattern');
        }
        return true;
    }
}