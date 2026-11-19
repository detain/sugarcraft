<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller;

/**
 * The `extra.sugar-plugin-installer` section of the root project's
 * composer.json. Only the root project writes it; plugin packages never do.
 *
 * `scope` defaults to `user` when the section or the key is absent. `root` is
 * the base directory for `project` scope; it is ignored under `user` scope.
 */
final class InstallerConfig
{
    public const KEY = 'sugar-plugin-installer';

    private const KNOWN_KEYS = ['scope', 'root'];

    private function __construct(
        public readonly Scope $scope,
        public readonly ?string $root,
    ) {
    }

    /**
     * @param mixed $extra the root package's `extra` map, as Composer returns it
     *
     * @throws \InvalidArgumentException when the section is malformed or names an unknown key
     */
    public static function fromExtra(mixed $extra): self
    {
        if (!is_array($extra) || !array_key_exists(self::KEY, $extra)) {
            return new self(Scope::User, null);
        }

        $section = $extra[self::KEY];
        if (!is_array($section) || ($section !== [] && array_is_list($section))) {
            throw new \InvalidArgumentException(
                'sugar-plugin-installer: extra.sugar-plugin-installer must be an object with "scope" and optional "root".',
            );
        }

        foreach (array_keys($section) as $key) {
            if (!in_array($key, self::KNOWN_KEYS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'sugar-plugin-installer: extra.sugar-plugin-installer has an unknown key "%s" (expected "scope" or "root").',
                    (string) $key,
                ));
            }
        }

        $scope = array_key_exists('scope', $section) ? Scope::fromValue($section['scope']) : Scope::User;

        $root = null;
        if (array_key_exists('root', $section)) {
            $value = $section['root'];
            if (!is_string($value) || $value === '') {
                throw new \InvalidArgumentException(
                    'sugar-plugin-installer: extra.sugar-plugin-installer.root must be a non-empty path string.',
                );
            }
            $root = $value;
        }

        return new self($scope, $root);
    }
}
