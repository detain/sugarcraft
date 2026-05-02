<?php

declare(strict_types=1);

namespace CandyCore\Glow;

use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * `sugarglow` entry point. Registers a single default command so users
 * can invoke `sugarglow <file>` without a subcommand name.
 */
final class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('sugarglow', '0.1.0');
        $command = new RenderCommand();
        $this->add($command);
        $this->setDefaultCommand($command->getName(), true);
    }
}
