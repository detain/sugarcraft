<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use SugarCraft\Core\Program;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Model\ConfirmModel;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;
use SugarCraft\ScriptUi\Runtime\StyledFrame;
use SugarCraft\ScriptUi\Runtime\StyleOptions;
use SugarCraft\ScriptUi\Runtime\SignalExit;
use SugarCraft\ScriptUi\Runtime\TimeoutGuard;
use SugarCraft\ScriptUi\Attribute\Example;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mirrors charmbracelet/gum.confirm.
 * Confirmation prompt. Exit code reflects the answer: 0 for yes, 1 for
 * no, 2 for an Esc abort, 130 for a Ctrl+C cancellation (typed or
 * kill -INT), 124 when a --timeout deadline elapses, and 128+signum for
 * an OS signal that stopped the loop (e.g. SIGTERM -> 143).
 */
#[Example('candy-scriptui confirm --default=yes "Deploy?"', 'Ask with Yes pre-selected.')]
#[AsCommand(name: 'confirm', description: 'Prompt for a yes/no answer; exit code reflects the choice.')]
final class ConfirmCommand extends Command
{
    public const EXIT_YES    = 0;
    public const EXIT_NO     = 1;
    public const EXIT_ABORT  = 2;

    protected function configure(): void
    {
        $this
            ->addArgument('question', InputArgument::OPTIONAL, 'Prompt text.', '')
            ->addOption('default-yes', null, InputOption::VALUE_NONE,     'Default to yes (deprecated — use --default=yes|no).')
            ->addOption('default',     null, InputOption::VALUE_REQUIRED, 'Default answer (yes|no).', '')
            ->addOption('affirmative', null, InputOption::VALUE_REQUIRED, 'Label for the yes option.',  'Yes')
            ->addOption('negative',    null, InputOption::VALUE_REQUIRED, 'Label for the no option.',   'No')
            ->addOption('show-output', null, InputOption::VALUE_NONE,     'Print the chosen label on stdout.')
            ->addOption('header',      null, InputOption::VALUE_REQUIRED, 'Header banner rendered above the prompt.', '')
            ->addOption('show-help',   null, InputOption::VALUE_NONE,     'Alias for --help (candy extension).')
            ->addOption('timeout',     null, InputOption::VALUE_REQUIRED, 'Auto-abort after N seconds (0 = none).', 0);

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

        $defaultRaw = strtolower((string) $input->getOption('default'));
        if ($defaultRaw !== '' && !in_array($defaultRaw, ['yes', 'y', 'no', 'n'], true)) {
            // A typo'd default must not silently flip to "no" — refusing is
            // the only honest reading of an unknown answer word.
            fwrite(STDERR, Lang::t('runtime.unknown_choice', [
                'option' => '--default',
                'value' => $defaultRaw,
                'allowed' => 'yes|no',
            ]) . "\n");
            return Command::FAILURE;
        }
        $defaultYes = (bool) $input->getOption('default-yes')
            || $defaultRaw === 'yes' || $defaultRaw === 'y';

        $affirm = (string) $input->getOption('affirmative');
        $negate = (string) $input->getOption('negative');

        $header = (string) $input->getOption('header');
        if ($header !== '') {
            // UI chrome, not result data — rides the paint stream (CC2).
            InteractiveRuntime::paint(\SugarCraft\Core\Util\Sanitize::cellValue($header) . "\n");
        }

        try {
            // scriptui-26: the shared lipgloss surface gum embeds everywhere.
            $frame = StyleOptions::frame(StyleOptions::flags($input));
        } catch (\InvalidArgumentException $e) {
            // A bad theme refuses before any paint.
            fwrite(STDERR, $e->getMessage() . "\n");
            return Command::FAILURE;
        }
                $model   = ConfirmModel::newPrompt(
            title:       (string) $input->getArgument('question'),
            default:     $defaultYes,
            affirmative: $affirm,
            negative:    $negate,
        );
        $loop    = \React\EventLoop\Loop::get();
        $options = InteractiveRuntime::programOptions(loop: $loop, useAltScreen: false, hideCursor: false);
        if ($options === null) {
            fwrite(STDERR, Lang::t('runtime.no_terminal', ['command' => 'confirm']) . "\n");
            return Command::FAILURE;
        }
        $program = new Program($frame !== null ? new StyledFrame($model, $frame) : $model, $options);
        $guard = TimeoutGuard::arm($loop, $timeoutSeconds, fn () => $program->kill());
        /** @var ConfirmModel $final */
        $final = $program->run();
        $final = $final instanceof StyledFrame ? $final->inner : $final;
        $guard->disarm();

        // Deadline elapsed with no answer — surface the timeout exit code
        // rather than a yes/no/abort verdict.
        if ($guard->fired()) {
            return TimeoutGuard::EXIT_TIMEOUT;
        }
        // Ctrl-C / SIGTERM -> the conventional 128+signum exit (130/143),
        // distinguishable from an Esc abort and from the 124 deadline.
        $signalExit = SignalExit::code($program->stopSignal());
        if ($signalExit !== null) {
            return $signalExit;
        }

        // Typed Ctrl+C lands as a KeyMsg (raw mode clears ISIG), not as an
        // OS signal — the model records it so Esc-abort (2) stays
        // distinguishable from Ctrl+C cancellation (130).
        if ($final->isInterrupted()) {
            return SignalExit::EXIT_INTERRUPTED;
        }
        if ($final->isAborted()) {
            return self::EXIT_ABORT;
        }
        if ($input->getOption('show-output')) {
            // LF-pinned trailer (parenthesised: ternary must not lose the
            // affirmative branch): write(..., true) emits PHP_EOL (CRLF on Windows).
            $output->write(($final->answer() ? $affirm : $negate) . "\n", false, OutputInterface::OUTPUT_RAW);
        }
        return $final->answer() ? self::EXIT_YES : self::EXIT_NO;
    }
}
