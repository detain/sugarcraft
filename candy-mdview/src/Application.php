<?php

declare(strict_types=1);

namespace SugarCraft\MdView;

use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * `candy-mdview` entry point. Registers a single default command so users
 * can invoke `candy-mdview <file>` without a subcommand name.
 */
final class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('candy-mdview', '0.1.0');
        $command = new RenderCommand();
        $this->add($command);
        $this->setDefaultCommand($command->getName(), true);
    }
}
