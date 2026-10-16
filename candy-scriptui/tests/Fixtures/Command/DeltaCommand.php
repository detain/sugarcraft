<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Fixtures\Command;

use SugarCraft\ScriptUi\Attribute\Alias;
use SugarCraft\ScriptUi\Attribute\Command;
use SugarCraft\ScriptUi\Attribute\Flag;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Command(name: 'delta', description: 'Delta test command with aliases.')]
#[Alias(name: 'd')]
#[Alias(name: 'del')]
final class DeltaCommand extends SymfonyCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
