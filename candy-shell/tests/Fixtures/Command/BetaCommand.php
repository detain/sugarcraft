<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Fixtures\Command;

use SugarCraft\Shell\Attribute\Command;
use SugarCraft\Shell\Attribute\Flag;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Command(name: 'beta', description: 'Beta test command.')]
#[Flag(name: 'verbose', short: 'v', description: 'Enable verbose output.', isFlag: true)]
#[Flag(name: 'format', short: 'f', description: 'Output format.', enum: BetaFormat::class)]
final class BetaCommand extends SymfonyCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}

enum BetaFormat: string
{
    case json = 'json';
    case yaml = 'yaml';
    case toml = 'toml';
}
