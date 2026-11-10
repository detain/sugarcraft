<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use SugarCraft\Core\Program;
use SugarCraft\ScriptUi\Model\InputModel;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Runtime\CursorMode;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;
use SugarCraft\ScriptUi\Runtime\SignalExit;
use SugarCraft\ScriptUi\Runtime\TimeoutGuard;
use SugarCraft\ScriptUi\Attribute\Example;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read a single line from the user. Prints the entered value on stdout;
 * exits 1 when aborted (Esc), 130 on Ctrl+C cancellation, 124 when a
 * --timeout deadline elapses, and 128+signum on an OS signal.
 */
#[Example('candy-scriptui input --password', 'Read a secret without echoing it.')]
#[AsCommand(name: 'input', description: 'Prompt for a single line of input.')]
final class InputCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('placeholder', null, InputOption::VALUE_REQUIRED, 'Hint shown when empty.', '')
            ->addOption('password',    null, InputOption::VALUE_NONE,     'Mask the entered text.')
            ->addOption('prompt',      null, InputOption::VALUE_REQUIRED, 'Prompt prefix.',   '> ')
            ->addOption('value',       null, InputOption::VALUE_REQUIRED, 'Pre-filled value.', '')
            ->addOption('char-limit',  null, InputOption::VALUE_REQUIRED, 'Max input length (0 = unlimited).', 0)
            ->addOption('width',       null, InputOption::VALUE_REQUIRED, 'Visible width (0 = full line).', 0)
            ->addOption('header',      null, InputOption::VALUE_REQUIRED, 'Header text rendered above the prompt.', '')
            ->addOption('strip-ansi',  null, InputOption::VALUE_NONE,     'Strip ANSI escapes from the printed result.')
            ->addOption('cursor-mode', null, InputOption::VALUE_REQUIRED, 'Cursor mode: blink (default) | static | hidden.', 'blink')
            ->addOption('show-help',   null, InputOption::VALUE_NONE,     'Alias for --help (gum compat).')
            ->addOption('timeout',     null, InputOption::VALUE_REQUIRED, 'Auto-abort after N seconds (0 = none).', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timeoutSeconds = 0.0;
        if (!TimeoutGuard::tryParseSeconds($input->getOption('timeout'), $timeoutSeconds)) {
            // A typo'd deadline must not silently disable itself (fail-open).
            fwrite(STDERR, Lang::t('runtime.bad_timeout', ['value' => (string) $input->getOption('timeout')]) . "\n");
            return Command::FAILURE;
        }

        $cursorMode = strtolower((string) $input->getOption('cursor-mode'));
        if (!CursorMode::isKnown($cursorMode)) {
            fwrite(STDERR, Lang::t('runtime.unknown_choice', [
                    'option' => '--cursor-mode',
                    'value' => $cursorMode,
                    'allowed' => implode('|', CursorMode::KNOWN),
                ]) . "\n");
            return Command::FAILURE;
        }

        $model   = InputModel::newPrompt(
            placeholder: (string) $input->getOption('placeholder'),
            password:    (bool)   $input->getOption('password'),
            prompt:      (string) $input->getOption('prompt'),
            value:       (string) $input->getOption('value'),
            charLimit:   (int)    $input->getOption('char-limit'),
            width:       (int)    $input->getOption('width'),
            header:      (string) $input->getOption('header'),
        );
        $loop    = \React\EventLoop\Loop::get();
        $options = InteractiveRuntime::programOptions(useAltScreen: false, hideCursor: CursorMode::hidesCursor($cursorMode));
        if ($options === null) {
            fwrite(STDERR, Lang::t('runtime.no_terminal', ['command' => 'input']) . "\n");
            return Command::FAILURE;
        }
        $program = new Program($model, $options);
        $guard = TimeoutGuard::arm($loop, $timeoutSeconds, fn () => $program->kill());
        /** @var InputModel $final */
        $final = $program->run();
        $guard->disarm();

        // Deadline elapsed before submit — discard input, exit timeout.
        if ($guard->fired()) {
            return TimeoutGuard::EXIT_TIMEOUT;
        }
        // Ctrl-C / SIGTERM -> the conventional 128+signum exit (130/143),
        // distinguishable from an Esc abort and from the 124 deadline.
        $signalExit = SignalExit::code($program->stopSignal());
        if ($signalExit !== null) {
            return $signalExit;
        }

        // Typed Ctrl+C (KeyMsg — raw mode clears ISIG) reads as a 130
        // cancellation, distinct from an Esc abort below.
        if ($final->isInterrupted()) {
            return SignalExit::EXIT_INTERRUPTED;
        }

        if ($final->isAborted() || !$final->isSubmitted()) {
            return Command::FAILURE;
        }
        $value = $final->value();
        if ($input->getOption('strip-ansi')) {
            $value = \SugarCraft\Core\Util\Ansi::strip($value);
        }
        // LF-pinned trailer: write(..., true) emits PHP_EOL (CRLF on Windows).
        $output->write($value . "\n", false, OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }
}
