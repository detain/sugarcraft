<?php

declare(strict_types=1);

namespace CandyCore\Shell;

use CandyCore\Shell\Command\ChooseCommand;
use CandyCore\Shell\Command\ConfirmCommand;
use CandyCore\Shell\Command\FileCommand;
use CandyCore\Shell\Command\FilterCommand;
use CandyCore\Shell\Command\InputCommand;
use CandyCore\Shell\Command\JoinCommand;
use CandyCore\Shell\Command\LogCommand;
use CandyCore\Shell\Command\PagerCommand;
use CandyCore\Shell\Command\StyleCommand;
use CandyCore\Shell\Command\TableCommand;
use CandyCore\Shell\Command\WriteCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Top-level Symfony Console application registering each subcommand.
 */
final class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('candyshell', '0.2.0');
        $this->addCommands([
            new StyleCommand(),
            new ChooseCommand(),
            new InputCommand(),
            new ConfirmCommand(),
            new JoinCommand(),
            new LogCommand(),
            new TableCommand(),
            new FilterCommand(),
            new WriteCommand(),
            new FileCommand(),
            new PagerCommand(),
        ]);
    }
}
