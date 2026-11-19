<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller;

use Composer\Factory;
use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use React\Promise\PromiseInterface;

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
     * Composer drops every installed package that fails this check before a
     * command runs, and then skips its uninstall or update. A tree staged before a
     * scope change sits in another tier, so the configured path alone would make
     * the package look uninstalled and orphan the tree.
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (parent::isInstalled($repo, $package)) {
            return true;
        }

        return $repo->hasPackage($package) && $this->existingPath($package) !== null;
    }

    /**
     * Composer calls this for every installed sugar-plugin package, on
     * dump-autoload, show, validate and remove, not only on install. So a
     * malformed section must not throw here: the tree already on disk answers
     * instead. The section is enforced where a tree is written (download,
     * install, update), so a bad scope still refuses before anything is staged.
     *
     * @throws \RuntimeException when the home directory or project directory cannot be resolved under the valid section
     */
    public function getInstallPath(PackageInterface $package)
    {
        try {
            return $this->configuredPath($package);
        } catch (\InvalidArgumentException $malformed) {
            return $this->fallbackPath($package, $malformed);
        }
    }

    /**
     * @throws \InvalidArgumentException when the root's installer section is malformed
     * @throws \RuntimeException when the home directory or project directory cannot be resolved
     */
    private function configuredPath(PackageInterface $package): string
    {
        $config = InstallerConfig::fromExtra($this->composer->getPackage()->getExtra());

        $bundleRoot = $config->scope === Scope::User
            ? StagingPath::userBundleRoot(StagingPath::userHome())
            : StagingPath::projectBundleRoot($config, $this->projectDir());

        return StagingPath::forPackage($bundleRoot, $package->getPrettyName());
    }

    /**
     * With the section unreadable, the scope is unknown, so the answer is the
     * staged tree that exists: the configured-looking root, then the project
     * tier, then the user tier. Every candidate that cannot be resolved is
     * skipped; nothing is written from here.
     *
     * @throws \InvalidArgumentException the malformed-section error, when no candidate resolves at all
     */
    private function fallbackPath(PackageInterface $package, \InvalidArgumentException $malformed): string
    {
        $name = $package->getPrettyName();
        $roots = $this->bundleRoots();

        if ($roots === []) {
            throw $malformed;
        }

        $paths = array_map(static fn (string $root): string => StagingPath::forPackage($root, $name), $roots);
        foreach ($paths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        return $paths[0];
    }

    /**
     * Every bundle root a tree can sit in, the project tier first. A root that
     * cannot be resolved is skipped; nothing is written from here.
     *
     * @return list<string>
     */
    private function bundleRoots(): array
    {
        $roots = [];

        try {
            $projectDir = $this->projectDir();
            $root = $this->rawSection()['root'] ?? null;
            if (is_string($root) && $root !== '') {
                $roots[] = StagingPath::projectBundleRootAt($projectDir, $root);
            }
            $roots[] = StagingPath::projectBundleRootAt($projectDir, null);
        } catch (\RuntimeException) {
        }

        try {
            $roots[] = StagingPath::userBundleRoot(StagingPath::userHome());
        } catch (\RuntimeException) {
        }

        return $roots;
    }

    /**
     * The directory that already holds this package's tree, in whichever tier
     * it was staged. The configured path is checked first, so a tree in the
     * selected tier wins. The other tiers matter only after a scope or root
     * change, which leaves the tree where the old section put it.
     */
    private function existingPath(PackageInterface $package): ?string
    {
        try {
            $paths = [$this->getInstallPath($package)];
            foreach ($this->bundleRoots() as $root) {
                $paths[] = StagingPath::forPackage($root, $package->getPrettyName());
            }
        } catch (\InvalidArgumentException) {
            return null;
        }

        foreach (array_unique($paths) as $path) {
            if (is_dir($path) && $this->holdsPackage($path, $package)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * A tree belongs to the package only when its composer.json names it. Another
     * package whose name ends in the same segment can stage to the same directory
     * name in a tier this project no longer selects, and removing that tree would
     * delete a plugin the package never installed.
     */
    private function holdsPackage(string $path, PackageInterface $package): bool
    {
        $manifest = $path . '/composer.json';
        if (!is_file($manifest)) {
            return false;
        }

        try {
            $decoded = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return is_array($decoded)
            && is_string($decoded['name'] ?? null)
            && strtolower($decoded['name']) === strtolower($package->getName());
    }

    /**
     * The raw `extra.sugar-plugin-installer` map, or an empty one. Used only
     * when InstallerConfig refuses the section and the `root` must still be
     * looked at for the read-only paths.
     *
     * @return array<array-key, mixed>
     */
    private function rawSection(): array
    {
        $extra = $this->composer->getPackage()->getExtra();
        $section = is_array($extra) ? ($extra[InstallerConfig::KEY] ?? null) : null;

        return is_array($section) ? $section : [];
    }

    /**
     * Composer downloads a package before it installs or updates it, so the
     * section is enforced here as well as in install(): a refused section must
     * stop the download, not leave a staged tree behind.
     *
     * @throws \InvalidArgumentException when the root's installer section is malformed
     */
    public function download(PackageInterface $package, ?PackageInterface $prevPackage = null)
    {
        $this->configuredPath($package);

        return parent::download($package, $prevPackage);
    }

    /**
     * @throws \InvalidArgumentException when the root's installer section is malformed
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $this->configuredPath($target);

        return parent::update($repo, $initial, $target);
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
     * @throws \InvalidArgumentException when the root's installer section is malformed
     * @throws \RuntimeException when another installed package stages to the same directory
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $path = $this->configuredPath($package);
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
     *
     * Removal reads the tree where it sits, not where the current scope would
     * stage it, so a scope change after install does not orphan the tree.
     */
    protected function getPackageBasePath(PackageInterface $package)
    {
        return $this->existingPath($package) ?? $this->getInstallPath($package);
    }

    /**
     * LibraryInstaller compares the initial and target paths, both taken from the
     * configured tier. After a scope or root change they match, so the parent
     * would keep the old tree and stage a second copy. Remove the old tree where
     * it is, then stage the new one.
     */
    protected function updateCode(PackageInterface $initial, PackageInterface $target)
    {
        $stale = $this->existingPath($initial);
        if ($stale === null || $stale === $this->getInstallPath($target)) {
            return parent::updateCode($initial, $target);
        }

        $promise = $this->removeCode($initial);
        if (!$promise instanceof PromiseInterface) {
            $promise = \React\Promise\resolve(null);
        }

        return $promise->then(function () use ($target): PromiseInterface {
            $promise = $this->installCode($target);
            if ($promise instanceof PromiseInterface) {
                return $promise;
            }

            return \React\Promise\resolve(null);
        });
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
