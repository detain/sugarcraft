<?php

declare(strict_types=1);

namespace SugarCraft\Shell;

use SugarCraft\Shell\Completion\BashCompletion;
use SugarCraft\Shell\Completion\FishCompletion;
use SugarCraft\Shell\Completion\ZshCompletion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Built-in command that emits shell completion scripts for bash, zsh, and fish.
 */
#[AsCommand(
    name: 'completion',
    description: 'Emit shell completion script for the specified shell.',
)]
final class CompletionCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'shell',
                's',
                InputOption::VALUE_REQUIRED,
                'Shell type: bash, zsh, or fish.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string */
        $shell = $input->getOption('shell') ?? 'bash';

        $application = $this->getApplication();
        if ($application === null) {
            $output->writeln('# No application available');
            return Command::SUCCESS;
        }

        $completion = match ($shell) {
            'bash' => (new BashCompletion())->generate($application),
            'zsh' => (new ZshCompletion())->generate($application),
            'fish' => (new FishCompletion())->generate($application),
            default => $this->unsupportedShell($shell),
        };

        $output->writeln($completion);
        return Command::SUCCESS;
    }

    private function unsupportedShell(string $shell): string
    {
        $valid = implode(', ', BashCompletion::validShells());
        return "# Unsupported shell: {$shell}. Supported: {$valid}.";
    }
}
