<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\ScriptUi\Attribute\Example;
use SugarCraft\ScriptUi\Model\FileModel;
use SugarCraft\ScriptUi\Runtime\SignalExit;
use SugarCraft\ScriptUi\Runtime\TimeoutGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Browse the filesystem and print the chosen file path. ↑/↓ navigate;
 * Enter descends into directories or selects files; Backspace ascends
 * to the parent. Esc aborts with exit 1; Ctrl-C exits 130 and a
 * `--timeout` deadline exits 124.
 */
#[AsCommand(name: 'file', description: 'Pick a file from a directory tree.')]
#[Example('candy-scriptui file src/ --show-size', 'Browse src/ with a size column.')]
final class FileCommand extends Command
{
    /** Upper bound for --timeout (one day); larger values clamp to it. */
    public const MAX_TIMEOUT_SECONDS = 86400.0;
    protected function configure(): void
    {
        $this
            ->addArgument('cwd',    InputArgument::OPTIONAL, 'Starting directory. Default: current.')
            ->addOption('height',    null, InputOption::VALUE_REQUIRED, 'Visible rows.', 10)
            ->addOption('header',    null, InputOption::VALUE_REQUIRED, 'Header banner rendered above the picker.', '')
            ->addOption('all',       'a',  InputOption::VALUE_NONE,     'Show hidden (dot) files.')
            ->addOption('directory', null, InputOption::VALUE_NONE,     'Allow selecting directories.')
            ->addOption('file',      null, InputOption::VALUE_NONE,     'Allow selecting files (default).')
            ->addOption('show-size', null, InputOption::VALUE_NONE,     'Render the file-size column.')
            ->addOption('show-help', null, InputOption::VALUE_NONE,     'Alias for --help (gum compat).')
            ->addOption('timeout',   null, InputOption::VALUE_REQUIRED, 'Auto-abort after N seconds (0 = none, max 86400).', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = $input->getArgument('cwd');
        $cwd = is_string($cwd) && $cwd !== '' ? $cwd : null;

        $directoryFlag = (bool) $input->getOption('directory');
        $fileFlag      = (bool) $input->getOption('file');
        // Match gum: --directory alone implies dirs-only; --file alone
        // means files-only (the default); both flags = both kinds; neither
        // = files-only.
        $allowDirs  = $directoryFlag;
        $allowFiles = $fileFlag || !$directoryFlag;

        $header = (string) $input->getOption('header');
        if ($header !== '') {
            $output->writeln($header);
        }

        $model   = FileModel::newPrompt(
            cwd:        $cwd,
            height:     (int) $input->getOption('height'),
            allowDirs:  $allowDirs,
            allowFiles: $allowFiles,
            showHidden: (bool) $input->getOption('all'),
            showSize:   (bool) $input->getOption('show-size'),
        );
        $loop    = \React\EventLoop\Loop::get();
        $program = new Program($model, new ProgramOptions(
            useAltScreen:    true,
            hideCursor:      true,
            catchInterrupts: true,
            loop:            $loop,
        ));
        $seconds = min((float) $input->getOption('timeout'), self::MAX_TIMEOUT_SECONDS);
        $guard = TimeoutGuard::arm($loop, $seconds, fn () => $program->kill());
        /** @var FileModel $final */
        $final = $program->run();
        $guard->disarm();

        if ($guard->fired()) {
            return TimeoutGuard::EXIT_TIMEOUT;
        }
        // Ctrl-C / SIGTERM -> the conventional 128+signum exit (130/143),
        // distinguishable from an Esc abort (exit 1 below).
        $signalExit = SignalExit::code($program->stopSignal());
        if ($signalExit !== null) {
            return $signalExit;
        }
        if ($final->isAborted() || !$final->isSubmitted()) {
            return Command::FAILURE;
        }
        $output->writeln((string) $final->selected());
        return Command::SUCCESS;
    }
}
