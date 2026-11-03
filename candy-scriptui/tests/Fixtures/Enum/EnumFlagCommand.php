<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Fixtures\Enum;

use SugarCraft\ScriptUi\Attribute\Command;
use SugarCraft\ScriptUi\Attribute\Flag;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A scanned command whose option carries `enum:` — pins review 2026-11-02
 * M10 (the scanner must forward the constraint to the application, which
 * then refuses an out-of-set value before execute() runs).
 */
#[Command(name: 'enum-fixture', description: 'Scanned command with an enum-constrained option.')]
#[Flag(name: 'suit', enum: SuitType::class, description: 'Card suit.')]
final class EnumFlagCommand extends SymfonyCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln((string) $input->getOption('suit'));

        return self::SUCCESS;
    }
}
