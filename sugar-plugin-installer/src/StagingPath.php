<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller;

/**
 * Maps a package to the directory it is staged into. Pure string work: nothing
 * here touches the filesystem, so the same answer comes back for install,
 * update and remove.
 */
final class StagingPath
{
    /** Relative location of the bundle tree under a user home or a project root. */
    public const BUNDLES_RELATIVE = '.candy-code/bundles';

    private const NAME_PATTERN = '/\A[a-z0-9][a-z0-9._-]*\z/i';

    private function __construct()
    {
    }

    /**
     * `user` scope stages under the home directory. `project` scope stages
     * under the configured root, or the project directory when no root is set.
     */
    public static function bundleRoot(InstallerConfig $config, string $projectDir, string $userHome): string
    {
        if ($config->scope === Scope::User) {
            return self::userBundleRoot($userHome);
        }

        return self::projectBundleRoot($config, $projectDir);
    }

    /**
     * Split from bundleRoot so the installer resolves the home directory only
     * under user scope: project scope must not fail on a missing HOME.
     */
    public static function userBundleRoot(string $userHome): string
    {
        return self::join($userHome, self::BUNDLES_RELATIVE);
    }

    /**
     * The project-scope tree. `$config->root` is honoured here whatever its
     * scope, so callers pass the config only when it is project scope.
     */
    public static function projectBundleRoot(InstallerConfig $config, string $projectDir): string
    {
        $base = $config->root === null ? $projectDir : self::resolve($config->root, $projectDir);

        return self::join($base, self::BUNDLES_RELATIVE);
    }

    /**
     * The staged directory for one package. The directory is named by the
     * package's own name, the part after the vendor slash, so
     * `sugarcraft/candy-discord` stages to `<bundleRoot>/candy-discord`.
     *
     * @throws \InvalidArgumentException when the name cannot be a directory name
     */
    public static function forPackage(string $bundleRoot, string $packageName): string
    {
        $slash = strrpos($packageName, '/');
        $name = $slash === false ? $packageName : substr($packageName, $slash + 1);

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'sugar-plugin-installer: package name "%s" cannot name a staging directory.',
                $packageName,
            ));
        }

        return self::join($bundleRoot, $name);
    }

    /**
     * @throws \RuntimeException when neither HOME nor USERPROFILE is an absolute path
     */
    public static function userHome(): string
    {
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            $home = getenv('USERPROFILE');
        }

        if (!is_string($home) || $home === '' || !self::isAbsolute($home)) {
            throw new \RuntimeException(
                'sugar-plugin-installer: cannot resolve the user home directory; HOME (or USERPROFILE) must be an absolute path.',
            );
        }

        return $home;
    }

    private static function resolve(string $root, string $projectDir): string
    {
        return self::isAbsolute($root) ? $root : self::join($projectDir, $root);
    }

    private static function join(string $base, string $relative): string
    {
        return rtrim($base, '/\\') . '/' . $relative;
    }

    /**
     * A leading double separator is a UNC share on Windows (`\\nas\sugar`), so
     * it is absolute even though it has no drive letter.
     */
    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~\A(?:[\\\\/]{2}|[A-Za-z]:[\\\\/])~', $path) === 1;
    }
}
