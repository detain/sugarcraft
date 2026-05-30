<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Fixtures\Command;

use SugarCraft\Shell\Attribute\Command;
use SugarCraft\Shell\Attribute\Flag;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Command(name: 'epsilon', description: 'Epsilon test command with required options.')]
#[Flag(name: 'required-opt', short: 'r', description: 'A required option.', required: true)]
#[Flag(name: 'optional-opt', short: 'o', description: 'An optional option.', required: false)]
final class EpsilonCommand extends SymfonyCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
