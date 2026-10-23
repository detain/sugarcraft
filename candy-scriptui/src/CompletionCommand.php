<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi;

use SugarCraft\ScriptUi\Completion\BashCompletion;
use SugarCraft\ScriptUi\Completion\CompletionEscaper;
use SugarCraft\ScriptUi\Completion\FishCompletion;
use SugarCraft\ScriptUi\Completion\ZshCompletion;
use SugarCraft\ScriptUi\Lang;
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
    /**
     * The shells this command can emit scripts for. Note this is broader
     * than any single generator's {@see BashCompletion::validShells()} —
     * that accessor names the shell ONE generator targets, not the
     * command-wide accepted set.
     *
     * @var list<string>
     */
    public const SUPPORTED_SHELLS = ['bash', 'zsh', 'fish'];

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

        if (!in_array($shell, self::SUPPORTED_SHELLS, true)) {
            // The output of this command is meant to be eval'd by the shell.
            // An unsupported --shell value must NEVER be interpolated into
            // it verbatim: the name is echoed through the shipped escaper,
            // which admits only [A-Za-z0-9_-], so a crafted value carrying
            // newlines or script text cannot forge extra output lines.
            $safeName = CompletionEscaper::safeName($shell);
            $shown    = $safeName ?? 'invalid-shell-name';
            $output->writeln('<error>' . Lang::t('completion.unknown_shell', ['shell' => $shown]) . '</error>');
            return Command::FAILURE;
        }

        $application = $this->getApplication();
        if ($application === null) {
            $output->writeln('# No application available');
            return Command::SUCCESS;
        }

        $completion = match ($shell) {
            'bash' => (new BashCompletion())->generate($application),
            'zsh' => (new ZshCompletion())->generate($application),
            'fish' => (new FishCompletion())->generate($application),
        };

        $output->writeln($completion);
        return Command::SUCCESS;
    }
}
