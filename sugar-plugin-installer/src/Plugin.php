<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * Composer entry point, named by `extra.class`. Registers the installer and
 * nothing else: no event listeners, no commands, no files written at activation.
 */
final class Plugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        $composer->getInstallationManager()->addInstaller(new PluginInstaller($io, $composer));
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }
}
