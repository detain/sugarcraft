<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Fixtures\Command;

use SugarCraft\ScriptUi\Attribute\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A #[Command] whose constructor requires a non-nullable CLASS-typed
 * dependency — exactly the shape CommandScanner cannot instantiate, so
 * scan() must report it as skipped (loudly) instead of vanishing it.
 */
final class SkippedDependency
{
}

#[Command(name: 'never-registers', description: 'Unconstructible fixture command.')]
final class NeedsDependencyCommand extends SymfonyCommand
{
    public function __construct(private SkippedDependency $dependency)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
