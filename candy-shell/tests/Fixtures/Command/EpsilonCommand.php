<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Fixtures\Command;

/**
 * A command with a required string ctor parameter.
 * The scanner should fill it with '' (empty string) and register the command.
 */
use SugarCraft\Shell\Attribute\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'required-string-command', description: 'Command with required string param.')]
#[Command(name: 'required-string-command', description: 'Command with required string param.')]
final class EpsilonCommand extends SymfonyCommand
{
    public function __construct(private string $requiredString)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
