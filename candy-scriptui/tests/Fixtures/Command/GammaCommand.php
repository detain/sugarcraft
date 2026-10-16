<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Fixtures\Command;

use SugarCraft\ScriptUi\Attribute\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Command(name: 'gamma', description: 'Gamma test command discovered via autoload.')]
final class GammaCommand extends SymfonyCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
