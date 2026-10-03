<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Validator;

use SugarCraft\Crush\Config\Settings\SettingValidator;

/**
 * An absolute or `~/`-rooted path — or, for a list key, a list of them.
 *
 * The trust lists are the reason: a relative entry such as `"."` would trust
 * every repository the CLI is ever run from, which is why
 * `Bootstrap::trustedProjectRoots()` refuses one at launch. Refusing it here
 * too keeps a UI from writing the entry the launch would then report.
 */
final class AbsolutePathValidator implements SettingValidator
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

        foreach (\is_array($value) ? $value : [$value] as $entry) {
            if (!\is_string($entry) || !(str_starts_with($entry, '/') || str_starts_with($entry, '~/'))) {
                return 'must be an absolute (or ~/-rooted) path';
            }
        }

        return null;
    }
}
