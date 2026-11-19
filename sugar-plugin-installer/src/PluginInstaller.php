<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller;

use Composer\Factory;
use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;

/**
 * Stages `sugar-plugin` packages to the scope the root project selects.
 *
 * It only chooses where the package's files land. Composer's downloader fills
 * that directory, and remove deletes it. The one write outside it is the bin
 * link Composer makes for a declared `bin` entry, which LibraryInstaller owns.
 * It never runs plugin code, never
 * edits the bundle trust list, and never writes the channel ledger. Those
 * stay with candy-code, which reads the staged tree on its own terms.
 */
final class PluginInstaller extends LibraryInstaller
{
    public const PACKAGE_TYPE = 'sugar-plugin';

    public function supports(string $packageType): bool
    {
        return $packageType === self::PACKAGE_TYPE;
    }

    /**
     * Read per call, not at activation: a malformed root `extra` should fail
     * the install of a sugar-plugin package, not every Composer command.
     *
     * @throws \InvalidArgumentException when the root's installer section is malformed
     * @throws \RuntimeException when the home directory or project directory cannot be resolved
     */
    public function getInstallPath(PackageInterface $package)
    {
        $config = InstallerConfig::fromExtra($this->composer->getPackage()->getExtra());

        $bundleRoot = $config->scope === Scope::User
            ? StagingPath::userBundleRoot(StagingPath::userHome())
            : StagingPath::projectBundleRoot($config, $this->projectDir());

        return StagingPath::forPackage($bundleRoot, $package->getPrettyName());
    }

    /**
     * Refuses an install whose staging directory belongs to another installed
     * sugar-plugin package. The directory is named after the part of the name
     * past the vendor slash, so `acme/candy-x` and `sugarcraft/candy-x` share one;
     * the second install would overwrite the first, and remove would delete both.
     * Checked on install only, so removing a legacy collided pair still works.
     * This sees packages installed in this project, not trees another project
     * staged under user scope.
     *
     * @throws \RuntimeException when another installed package stages to the same directory
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $path = $this->getInstallPath($package);
        $bundleRoot = dirname($path);

        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $installed) {
            if ($installed->getType() !== self::PACKAGE_TYPE || $installed->getName() === $package->getName()) {
                continue;
            }
            if (StagingPath::forPackage($bundleRoot, $installed->getPrettyName()) !== $path) {
                continue;
            }

            throw new \RuntimeException(sprintf(
                'sugar-plugin-installer: %s and %s both stage to %s. Their names differ only by vendor, so they cannot both be installed.',
                $installed->getPrettyName(),
                $package->getPrettyName(),
                $path,
            ));
        }

        return parent::install($repo, $package);
    }

    /**
     * LibraryInstaller derives this by stripping a trailing `/<target-dir>` from
     * the install path. Our install path is already the package's own staging
     * directory, so a target-dir that matches the package name would strip it
     * back to the bundles root, and remove would delete every staged plugin.
     */
    protected function getPackageBasePath(PackageInterface $package)
    {
        return $this->getInstallPath($package);
    }

    /**
     * The directory Composer loaded the root composer.json from. It is not
     * always getcwd(): COMPOSER can point at a file elsewhere, and Composer
     * does not chdir for it.
     *
     * @throws \RuntimeException when that directory cannot be resolved
     */
    private function projectDir(): string
    {
        $dir = realpath(dirname(Factory::getComposerFile()));
        if ($dir === false) {
            throw new \RuntimeException('sugar-plugin-installer: cannot resolve the project directory.');
        }

        return $dir;
    }
}
