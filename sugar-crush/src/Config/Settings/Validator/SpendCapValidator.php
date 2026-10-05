<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Validator;

use SugarCraft\Crush\Config\Settings\SettingValidator;
use SugarCraft\Crush\Host\SpendLedger;

/**
 * A spend ceiling in US dollars: a finite number above zero
 * ({@see SpendLedger::isUsableCap()}), the test `/budget` and
 * `$SUGARCRUSH_MAX_COST` already apply. Zero and negative are the opposite
 * request from "no cap", and an infinite one is a cap that never triggers;
 * unset (null) is how "no cap" is spelled.
 */
final class SpendCapValidator implements SettingValidator
{
    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    public function validate(mixed $value, array $all = []): ?string
    {
        if ($value === null) {
            return null;
        }

        if ((!\is_int($value) && !\is_float($value)) || !SpendLedger::isUsableCap((float) $value)) {
            return 'must be a positive number of US dollars (unset it for no cap)';
        }

        return null;
    }
}
