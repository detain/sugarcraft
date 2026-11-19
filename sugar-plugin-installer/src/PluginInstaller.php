<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;

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
     * @throws \RuntimeException when the home directory or working directory cannot be resolved
     */
    public function getInstallPath(PackageInterface $package)
    {
        $config = InstallerConfig::fromExtra($this->composer->getPackage()->getExtra());

        $projectDir = getcwd();
        if ($projectDir === false) {
            throw new \RuntimeException('sugar-plugin-installer: cannot resolve the project directory.');
        }

        $bundleRoot = StagingPath::bundleRoot($config, $projectDir, StagingPath::userHome());

        return StagingPath::forPackage($bundleRoot, $package->getPrettyName());
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
}
