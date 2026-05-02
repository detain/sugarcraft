<?php

declare(strict_types=1);

namespace CandyCore\Shell\Command;

use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Shell\Model\FileModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Browse the filesystem and print the chosen file path. ↑/↓ navigate;
 * Enter descends into directories or selects files; Backspace ascends
 * to the parent. Esc / Ctrl-C abort with exit 1.
 */
#[AsCommand(name: 'file', description: 'Pick a file from a directory tree.')]
final class FileCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('cwd',    InputArgument::OPTIONAL, 'Starting directory. Default: current.')
            ->addOption('height', null, InputOption::VALUE_REQUIRED, 'Visible rows.', 10);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd     = $input->getArgument('cwd');
        $cwd     = is_string($cwd) && $cwd !== '' ? $cwd : null;
        $model   = FileModel::newPrompt($cwd, (int) $input->getOption('height'));
        $program = new Program($model, new ProgramOptions(
            useAltScreen:    true,
            hideCursor:      true,
            catchInterrupts: true,
        ));
        /** @var FileModel $final */
        $final = $program->run();

        if ($final->isAborted() || !$final->isSubmitted()) {
            return Command::FAILURE;
        }
        $output->writeln((string) $final->selected());
        return Command::SUCCESS;
    }
}
