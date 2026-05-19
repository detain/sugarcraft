<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Fixtures\Command;

use SugarCraft\Shell\Attribute\Alias;
use SugarCraft\Shell\Attribute\Command;
use SugarCraft\Shell\Attribute\Example;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'demo', description: 'A demo command with examples and aliases.')]
#[Command(name: 'demo', description: 'A demo command with examples and aliases.')]
#[Alias('dm')]
#[Alias('dem')]
#[Example('demo --verbose', 'Run with verbose output.')]
#[Example('demo --quiet', 'Run quietly.')]
final class DemoCommand extends SymfonyCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
