<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Validator;

use SugarCraft\Crush\Config\Settings\SettingValidator;

/** An absolute `http://` or `https://` URL with a host. */
final class UrlValidator implements SettingValidator
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

        if (!\is_string($value) || filter_var($value, \FILTER_VALIDATE_URL) === false) {
            return 'must be an absolute URL';
        }

        $scheme = strtolower((string) parse_url($value, \PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'must be an http:// or https:// URL';
        }

        return null;
    }
}
