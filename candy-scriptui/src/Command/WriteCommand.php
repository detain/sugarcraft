<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use SugarCraft\Core\Program;
use SugarCraft\ScriptUi\Model\WriteModel;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Runtime\CursorMode;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;
use SugarCraft\ScriptUi\Runtime\StyledFrame;
use SugarCraft\ScriptUi\Runtime\StyleOptions;
use SugarCraft\ScriptUi\Runtime\SignalExit;
use SugarCraft\ScriptUi\Runtime\TimeoutGuard;
use SugarCraft\ScriptUi\Runtime\PositiveInt;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Multi-line text editor. Press Ctrl+D to submit; Esc aborts (exit 1),
 * Ctrl+C cancels (exit 130), a --timeout deadline exits 124, and an OS
 * signal exits 128+signum. Submitted text is printed verbatim on stdout.
 */
#[AsCommand(name: 'write', description: 'Open a multi-line editor; print the result on submit (Ctrl+D).')]
final class WriteCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('placeholder',        null, InputOption::VALUE_REQUIRED, 'Hint shown when empty.', '')
            ->addOption('width',              null, InputOption::VALUE_REQUIRED, 'Editor width in cells.',   0)
            ->addOption('height',             null, InputOption::VALUE_REQUIRED, 'Editor height in rows.',   0)
            ->addOption('value',              null, InputOption::VALUE_REQUIRED, 'Pre-fill the editor with this text.', '')
            ->addOption('char-limit',         null, InputOption::VALUE_REQUIRED, 'Max character count (0 = unlimited).', 0)
            ->addOption('max-lines',          null, InputOption::VALUE_REQUIRED, 'Max line count (0 = unlimited).', 0)
            ->addOption('prompt',             null, InputOption::VALUE_REQUIRED, 'Static prefix on every line.', '')
            ->addOption('show-line-numbers',  null, InputOption::VALUE_NONE,    'Show 1-based line numbers in a left gutter.')
            ->addOption('header',             null, InputOption::VALUE_REQUIRED, 'Header text rendered above the editor.', '')
            ->addOption('end-of-buffer-character', null, InputOption::VALUE_REQUIRED, "Glyph rendered on lines past the buffer end (default '~').")
            ->addOption('cursor-mode',        null, InputOption::VALUE_REQUIRED, 'Cursor mode: blink (default) | static | hidden.', 'blink')
            ->addOption('show-help',          null, InputOption::VALUE_NONE,    'Alias for --help (gum compat).')
            ->addOption('no-alt-screen',      null, InputOption::VALUE_NONE,     'Render inline instead of the alternate screen (keeps output in scrollback).')
            ->addOption('timeout',            null, InputOption::VALUE_REQUIRED, 'Auto-abort after N seconds (0 = none).', 0);

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

        $cursorMode = strtolower((string) $input->getOption('cursor-mode'));
        if (!CursorMode::isKnown($cursorMode)) {
            fwrite(STDERR, Lang::t('runtime.unknown_choice', [
                    'option' => '--cursor-mode',
                    'value' => $cursorMode,
                    'allowed' => implode('|', CursorMode::KNOWN),
                ]) . "\n");
            return Command::FAILURE;
        }

        try {
            $width     = PositiveInt::parse('width', $input->getOption('width'));
            $height    = PositiveInt::parse('height', $input->getOption('height'));
            $charLimit = PositiveInt::parse('char-limit', $input->getOption('char-limit'));
            $maxLines  = PositiveInt::parse('max-lines', $input->getOption('max-lines'));
        } catch (\InvalidArgumentException $e) {
            // A typo'd number must not silently relocate the mode.
            fwrite(STDERR, $e->getMessage() . "\n");
            return Command::FAILURE;
        }

        try {
            // scriptui-26: the shared lipgloss surface gum embeds everywhere.
            $frame = StyleOptions::frame(StyleOptions::flags($input));
        } catch (\InvalidArgumentException $e) {
            // A bad theme refuses before any paint.
            fwrite(STDERR, $e->getMessage() . "\n");
            return Command::FAILURE;
        }
                $model   = WriteModel::newPrompt(
            placeholder:     (string) $input->getOption('placeholder'),
            width:           $width,
            height:          $height,
            value:           (string) $input->getOption('value'),
            charLimit:       $charLimit,
            maxLines:        $maxLines,
            prompt:          (string) $input->getOption('prompt'),
            showLineNumbers: (bool)   $input->getOption('show-line-numbers'),
            header:          (string) $input->getOption('header'),
            endOfBufferCharacter: $input->getOption('end-of-buffer-character') === null
                ? null
                : (string) $input->getOption('end-of-buffer-character'),
        );
        $loop    = \React\EventLoop\Loop::get();
        $options = InteractiveRuntime::programOptions(loop: $loop, 
            useAltScreen: ! (bool) $input->getOption('no-alt-screen'),
            hideCursor:   CursorMode::hidesCursor($cursorMode),
        );
        if ($options === null) {
            fwrite(STDERR, Lang::t('runtime.no_terminal', ['command' => 'write']) . "\n");
            return Command::FAILURE;
        }
        $program = new Program($frame !== null ? new StyledFrame($model, $frame) : $model, $options);
        $guard = TimeoutGuard::arm($loop, $timeoutSeconds, fn () => $program->kill());
        /** @var WriteModel $final */
        $final = $program->run();
        $final = $final instanceof StyledFrame ? $final->inner : $final;
        $guard->disarm();

        // Deadline elapsed before submit — discard the buffer, exit timeout.
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
        // cancellation, distinct from the Esc abort below.
        if ($final->isInterrupted()) {
            return SignalExit::EXIT_INTERRUPTED;
        }

        if ($final->isAborted() || !$final->isSubmitted()) {
            return Command::FAILURE;
        }
        // LF-pinned trailer: write(..., true) emits PHP_EOL (CRLF on Windows).
        $output->write($final->value() . "\n", false, OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }
}
