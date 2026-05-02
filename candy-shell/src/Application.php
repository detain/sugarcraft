<?php

declare(strict_types=1);

namespace CandyCore\Shell;

use CandyCore\Shell\Command\ChooseCommand;
use CandyCore\Shell\Command\ConfirmCommand;
use CandyCore\Shell\Command\InputCommand;
use CandyCore\Shell\Command\StyleCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Top-level Symfony Console application registering each MVP subcommand.
 */
final class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('candyshell', '0.1.0');
        $this->addCommands([
            new StyleCommand(),
            new ChooseCommand(),
            new InputCommand(),
            new ConfirmCommand(),
        ]);
    }
}
