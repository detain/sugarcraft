<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller;

/**
 * Where a `sugar-plugin` package is staged. The two values match the tiers
 * candy-code's bundle discovery reads: `user` under the home directory and
 * `project` under the project root (plan_plugin.md 5.1).
 */
enum Scope: string
{
    case User = 'user';
    case Project = 'project';

    /**
     * Refuses anything but the two spellings. A typo must fail the install
     * loudly rather than quietly fall back to the user tier.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromValue(mixed $value): self
    {
        $scope = is_string($value) ? self::tryFrom($value) : null;
        if ($scope === null) {
            throw new \InvalidArgumentException(sprintf(
                'sugar-plugin-installer: extra.sugar-plugin-installer.scope must be "user" or "project", got %s.',
                is_scalar($value) ? var_export($value, true) : get_debug_type($value),
            ));
        }

        return $scope;
    }
}
