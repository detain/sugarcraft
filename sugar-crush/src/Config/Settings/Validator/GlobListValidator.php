<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Validator;

use SugarCraft\Crush\Config\Settings\SettingValidator;

/**
 * A JSON list of non-empty strings — names or `fnmatch()` globs.
 *
 * A JSON OBJECT IS REFUSED even though it decodes to a PHP array: the list
 * readers (`disabledTools`, `instructions`, …) iterate values, so
 * `{"a": "Bash"}` would silently act like `["Bash"]` with a key nobody reads.
 */
final class GlobListValidator implements SettingValidator
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

        if (!\is_array($value) || !array_is_list($value)) {
            return 'must be a list';
        }

        foreach ($value as $index => $entry) {
            if (!\is_string($entry) || trim($entry) === '') {
                return "entry {$index} must be a non-empty string";
            }
        }

        return null;
    }
}
