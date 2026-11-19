<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller\Tests\Support;

use Composer\Downloader\DownloaderInterface;
use Composer\Package\PackageInterface;
use React\Promise\PromiseInterface;

/**
 * A downloader that deletes nothing and records each directory it was asked to
 * remove. The removal tests assert on those paths, so they never touch disk and
 * never wait on the process executor that the real file downloader uses.
 */
final class RecordingDownloader implements DownloaderInterface
{
    /** @var list<string> */
    public array $removed = [];

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
        return \React\Promise\resolve(null);
    }

    public function update(PackageInterface $initial, PackageInterface $target, string $path): PromiseInterface
    {
        return \React\Promise\resolve(null);
    }

    public function remove(PackageInterface $package, string $path): PromiseInterface
    {
        $this->removed[] = $path;

        return \React\Promise\resolve(null);
    }

    public function cleanup(string $type, PackageInterface $package, string $path, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        return \React\Promise\resolve(null);
    }
}
