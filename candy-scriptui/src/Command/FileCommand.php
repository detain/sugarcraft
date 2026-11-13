<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use SugarCraft\Core\Program;
use SugarCraft\ScriptUi\Attribute\Example;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Model\FileModel;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;
use SugarCraft\ScriptUi\Runtime\StyledFrame;
use SugarCraft\ScriptUi\Runtime\StyleOptions;
use SugarCraft\ScriptUi\Runtime\OptionList;
use SugarCraft\ScriptUi\Runtime\SignalExit;
use SugarCraft\ScriptUi\Runtime\TimeoutGuard;
use SugarCraft\ScriptUi\Runtime\PositiveInt;
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
            ->addOption('extensions', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only show files with these extensions; repeatable and comma-split (.png,.jpg).', [])
            ->addOption('no-alt-screen', null, InputOption::VALUE_NONE, 'Render inline instead of the alternate screen (keeps output in scrollback).')
            ->addOption('timeout',   null, InputOption::VALUE_REQUIRED, 'Auto-abort after N seconds (0 = none, max 86400).', 0);

        // scriptui-26: themed frames (border/margin/padding/colour/decoration).
        StyleOptions::register($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timeoutSeconds = 0.0;
        if (!TimeoutGuard::tryParseSeconds($input->getOption('timeout'), $timeoutSeconds)) {
            // A typo'd deadline must not silently disable itself (fail-open).
            fwrite(STDERR, Lang::t('runtime.bad_timeout', ['value' => (string) $input->getOption('timeout')]) . "\n");
            return Command::FAILURE;
        }

        $cwd = $input->getArgument('cwd');
        $cwd = is_string($cwd) && $cwd !== '' ? $cwd : null;

        $directoryFlag = (bool) $input->getOption('directory');
        $fileFlag      = (bool) $input->getOption('file');
        // Match gum: --directory alone implies dirs-only; --file alone
        // means files-only (the default); both flags = both kinds; neither
        // = files-only.
        $allowDirs  = $directoryFlag;
        $allowFiles = $fileFlag || !$directoryFlag;

        try {
            $height = PositiveInt::parse('height', $input->getOption('height'));
        } catch (\InvalidArgumentException $e) {
            // Refuse a typo'd height BEFORE painting anything.
            fwrite(STDERR, $e->getMessage() . "\n");
            return Command::FAILURE;
        }

        $header = (string) $input->getOption('header');
        if ($header !== '') {
            // UI chrome, not result data — rides the paint stream (CC2).
            InteractiveRuntime::paint(\SugarCraft\Core\Util\Sanitize::cellValue($header) . "\n");
        }

        // scriptui-45 (finding 063): repeatable AND comma-split, merged via
        // the escape-aware OptionList splitter shared with --selected (-76),
        // so a token list like `.png,.jpg` and two flags are equal spellings.
        /** @var list<string> $extensionsRaw */
        $extensionsRaw = (array) $input->getOption('extensions');
        $allowedExtensions = OptionList::expand($extensionsRaw);

        try {
            // scriptui-26: the shared lipgloss surface gum embeds everywhere.
            $frame = StyleOptions::frame(StyleOptions::flags($input));
        } catch (\InvalidArgumentException $e) {
            // A bad theme refuses before any paint.
            fwrite(STDERR, $e->getMessage() . "\n");
            return Command::FAILURE;
        }
                $model   = FileModel::newPrompt(
            cwd:        $cwd,
            height:     $height,
            allowDirs:  $allowDirs,
            allowFiles: $allowFiles,
            showHidden: (bool) $input->getOption('all'),
            showSize:   (bool) $input->getOption('show-size'),
            allowedExtensions: $allowedExtensions,
        );
        $loop    = \React\EventLoop\Loop::get();
        $options = InteractiveRuntime::programOptions(loop: $loop, 
            useAltScreen: ! (bool) $input->getOption('no-alt-screen'),
            hideCursor:   true,
        );
        if ($options === null) {
            fwrite(STDERR, Lang::t('runtime.no_terminal', ['command' => 'file']) . "\n");
            return Command::FAILURE;
        }
        $program = new Program($frame !== null ? new StyledFrame($model, $frame) : $model, $options);
        // Oversized deadlines clamp to MAX_TIMEOUT_SECONDS (the help text
        // discloses the cap); the parse above already refused non-numerics
        // instead of silently casting them to 0 (= no deadline).
        $seconds = min($timeoutSeconds, self::MAX_TIMEOUT_SECONDS);
        $guard = TimeoutGuard::arm($loop, $seconds, fn () => $program->kill());
        /** @var FileModel $final */
        $final = $program->run();
        $final = $final instanceof StyledFrame ? $final->inner : $final;
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
        // Typed Ctrl+C (KeyMsg — raw mode clears ISIG) exits 130 here too;
        // the Esc abort below keeps exit 1.
        if ($final->isInterrupted()) {
            return SignalExit::EXIT_INTERRUPTED;
        }
        if ($final->isAborted() || !$final->isSubmitted()) {
            return Command::FAILURE;
        }
        // LF-pinned trailer: write(..., true) emits PHP_EOL (CRLF on Windows).
        $output->write((string) $final->selected() . "\n", false, OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }
}
