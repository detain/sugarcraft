<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\Installer\InstallationManager;
use Composer\IO\NullIO;
use PHPUnit\Framework\TestCase;
use SugarCraft\PluginInstaller\Plugin;
use SugarCraft\PluginInstaller\PluginInstaller;

final class PluginTest extends TestCase
{
    public function testActivateRegistersTheSugarPluginInstaller(): void
    {
        $manager = $this->createMock(InstallationManager::class);
        $manager->expects($this->once())
            ->method('addInstaller')
            ->with($this->isInstanceOf(PluginInstaller::class));

        $composer = new Composer();
        $composer->setConfig(new Config(false, sys_get_temp_dir()));
        $composer->setDownloadManager(new DownloadManager(new NullIO()));
        $composer->setInstallationManager($manager);

        (new Plugin())->activate($composer, new NullIO());
    }

    public function testDeactivateAndUninstallDoNothing(): void
    {
        $manager = $this->createMock(InstallationManager::class);
        $manager->expects($this->never())->method('addInstaller');

        $composer = new Composer();
        $composer->setConfig(new Config(false, sys_get_temp_dir()));
        $composer->setDownloadManager(new DownloadManager(new NullIO()));
        $composer->setInstallationManager($manager);

        $plugin = new Plugin();
        $plugin->deactivate($composer, new NullIO());
        $plugin->uninstall($composer, new NullIO());

        $this->addToAssertionCount(1);
    }
}
