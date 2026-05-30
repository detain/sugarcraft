<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Fixtures\Command;

use SugarCraft\Shell\Attribute\Command;
use SugarCraft\Shell\Attribute\Flag;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

enum OutputFormat: string
{
    case text = 'text';
    case json = 'json';
}

#[Command(name: 'zeta', description: 'Zeta test command with enum flag.')]
#[Flag(name: 'format', short: 'f', description: 'Output format.', enum: OutputFormat::class)]
final class ZetaCommand extends SymfonyCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
