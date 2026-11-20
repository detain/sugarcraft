<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller\Tests\Support;

use Composer\Downloader\DownloaderInterface;
use Composer\Package\PackageInterface;
use React\Promise\PromiseInterface;

/**
 * A downloader that stages a tree the way a real download does: it creates the
 * target directory and writes a composer.json naming the package. Install and
 * update tests need the directory to exist, because the provenance record is
 * written into it once the download settles.
 */
final class StagingDownloader implements DownloaderInterface
{
    public function getInstallationSource(): string
    {
        return 'dist';
    }

    public function download(PackageInterface $package, string $path, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        return \React\Promise\resolve(null);
    }

    public function prepare(string $type, PackageInterface $package, string $path, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        return \React\Promise\resolve(null);
    }

    public function install(PackageInterface $package, string $path): PromiseInterface
    {
        return $this->stage($package, $path);
    }

    public function update(PackageInterface $initial, PackageInterface $target, string $path): PromiseInterface
    {
        return $this->stage($target, $path);
    }

    public function remove(PackageInterface $package, string $path): PromiseInterface
    {
        return \React\Promise\resolve(null);
    }

    public function cleanup(string $type, PackageInterface $package, string $path, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        return \React\Promise\resolve(null);
    }

    private function stage(PackageInterface $package, string $path): PromiseInterface
    {
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        Scratch::write($path . '/composer.json', (string) json_encode(['name' => $package->getName()]));

        return \React\Promise\resolve(null);
    }
}
