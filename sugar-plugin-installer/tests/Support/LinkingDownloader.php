<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller\Tests\Support;

use Composer\Downloader\DownloaderInterface;
use Composer\Package\PackageInterface;
use React\Promise\PromiseInterface;

/**
 * A downloader that stages a Composer `path` repository the way PathDownloader
 * does under the default symlink strategy: the staged directory is a symbolic
 * link to the developer's checkout, which already holds the package's files.
 */
final class LinkingDownloader implements DownloaderInterface
{
    public function __construct(private readonly string $checkout)
    {
    }

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
        return $this->link($path);
    }

    public function update(PackageInterface $initial, PackageInterface $target, string $path): PromiseInterface
    {
        return $this->link($path);
    }

    public function remove(PackageInterface $package, string $path): PromiseInterface
    {
        return \React\Promise\resolve(null);
    }

    public function cleanup(string $type, PackageInterface $package, string $path, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        return \React\Promise\resolve(null);
    }

    private function link(string $path): PromiseInterface
    {
        $parent = dirname($path);
        if (!is_dir($parent)) {
            mkdir($parent, 0700, true);
        }
        symlink($this->checkout, $path);

        return \React\Promise\resolve(null);
    }
}
