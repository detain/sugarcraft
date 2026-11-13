<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use SugarCraft\Core\Program;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Model\ChooseModel;
use SugarCraft\ScriptUi\Runtime\OptionList;
use SugarCraft\ScriptUi\Runtime\PositiveInt;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;
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
 * Pick a single item from an interactive list. Prints the chosen text on
 * stdout; exits 1 when the user aborts.
 */
#[Example('candy-scriptui choose --limit 2 Pizza Burger Salad', 'Pick up to two items.')]
#[AsCommand(name: 'choose', description: 'Pick one option from a list.')]
final class ChooseCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('options', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Options to pick from.')
            ->addOption('height',   null, InputOption::VALUE_REQUIRED, 'Visible item count.', 10)
            ->addOption('limit',    null, InputOption::VALUE_REQUIRED, 'Maximum selections (1 = single, >1 = multi).', 1)
            ->addOption('no-limit', null, InputOption::VALUE_NONE,    'Allow unlimited multi-select.')
            ->addOption('ordered',  null, InputOption::VALUE_NONE,    'Output multi-select in selection order.')
            ->addOption('header',   null, InputOption::VALUE_REQUIRED, 'Header text rendered above the list.', '')
            ->addOption('selected', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Pre-selected option(s) (multi mode); repeatable, comma-separated lists are split.', [])
            ->addOption('select-if-one', null, InputOption::VALUE_NONE, 'Auto-pick when exactly one option is supplied.')
            ->addOption('output-delimiter', null, InputOption::VALUE_REQUIRED, 'Separator for multi-select output.', "\n")
            ->addOption('cursor', null, InputOption::VALUE_REQUIRED, 'Glyph rendered before the highlighted item.', '> ')
            ->addOption('cursor-prefix', null, InputOption::VALUE_REQUIRED, 'Alias for --cursor.', null)
            ->addOption('unselected-prefix', null, InputOption::VALUE_REQUIRED, 'Glyph rendered before non-cursor items.', null)
            ->addOption('show-help', null, InputOption::VALUE_NONE,    'Alias for --help (gum compat).')
            ->addOption('no-selected', null, InputOption::VALUE_REQUIRED, 'Message printed (with a non-zero exit) when the user exits without a selection.', null)
            ->addOption('width', null, InputOption::VALUE_REQUIRED, 'List width in cells (0 = auto).', 0)
            ->addOption('no-alt-screen', null, InputOption::VALUE_NONE, 'Render inline instead of the alternate screen (keeps output in scrollback).')
            ->addOption('timeout',   null, InputOption::VALUE_REQUIRED, 'Auto-abort after N seconds (0 = none).', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timeoutSeconds = 0.0;
        if (!TimeoutGuard::tryParseSeconds($input->getOption('timeout'), $timeoutSeconds)) {
            // A typo'd deadline must not silently disable itself (fail-open).
            fwrite(STDERR, Lang::t('runtime.bad_timeout', ['value' => (string) $input->getOption('timeout')]) . "\n");
            return Command::FAILURE;
        }
        /** @var list<string> $options */
        $options = $input->getArgument('options');
        try {
            $height = PositiveInt::parse('height', $input->getOption('height'));
            $limit  = PositiveInt::parse('limit', $input->getOption('limit'));
            $width  = PositiveInt::parse('width', $input->getOption('width'));
        } catch (\InvalidArgumentException $e) {
            // A typo'd number must not silently relocate the mode.
            fwrite(STDERR, $e->getMessage() . "\n");
            return Command::FAILURE;
        }
        $noLimit = (bool) $input->getOption('no-limit');
        $ordered = (bool) $input->getOption('ordered');
        $header  = (string) $input->getOption('header');
        /** @var list<string> $preselected */
        $preselected = OptionList::expand((array) $input->getOption('selected'));
        $selectIfOne = (bool) $input->getOption('select-if-one');
        $delim   = (string) $input->getOption('output-delimiter');

        // Output contract, deliberate (gum-parity raw capture): the
        // interactive selection below writes the operator's own bytes
        // VERBATIM because the chosen item IS the data channel — a pipe
        // downstream must receive it untouched. Program-echoed values
        // (this auto-pick included) pass through Sanitize::cellValue.
        // Auto-pick when exactly one option is supplied.
        if ($selectIfOne && count($options) === 1) {
            $output->write(\SugarCraft\Core\Util\Sanitize::cellValue($options[0]) . "\n", false, OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        // --cursor-prefix is an alias for --cursor; user-supplied wins.
        $cursor = $input->getOption('cursor-prefix') ?? $input->getOption('cursor');
        $unselected = $input->getOption('unselected-prefix');

        $model   = ChooseModel::fromOptions(
            $options,
            $height,
            $limit,
            $noLimit,
            $header,
            $preselected,
            $ordered,
            cursorPrefix:     is_string($cursor) ? $cursor : null,
            unselectedPrefix: is_string($unselected) ? $unselected : null,
            width:            $width,
        );
        $loop    = \React\EventLoop\Loop::get();
        $options = InteractiveRuntime::programOptions(
            useAltScreen: ! (bool) $input->getOption('no-alt-screen'),
            hideCursor:   true,
        );
        if ($options === null) {
            fwrite(STDERR, Lang::t('runtime.no_terminal', ['command' => 'choose']) . "\n");
            return Command::FAILURE;
        }
        $program = new Program($model, $options);
        $guard = TimeoutGuard::arm($loop, $timeoutSeconds, fn () => $program->kill());
        /** @var ChooseModel $final */
        $final = $program->run();
        $guard->disarm();

        // Deadline elapsed before the user submitted — abort with the
        // timeout exit code and print nothing.
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
        // cancellation; renderResult below would otherwise fold it into
        // the generic Esc-failure exit.
        if ($final->isInterrupted()) {
            return SignalExit::EXIT_INTERRUPTED;
        }

        $noSelected = $input->getOption('no-selected');

        return self::renderResult($final, $output, $delim, is_string($noSelected) ? $noSelected : null);
    }

    /**
     * Map the finished model to process output + exit code. Static and
     * separate from execute() so the contract is unit-testable without the
     * live TTY that Program::run() needs.
     */
    public static function renderResult(
        ChooseModel $final,
        OutputInterface $output,
        string $delim,
        ?string $noSelectedMessage,
    ): int {
        $empty = $final->isMulti() ? $final->selectedAll() === [] : $final->selected() === null;
        // Without --no-selected an empty-but-submitted selection keeps the
        // historical behavior (blank line, exit 0) so existing pipelines
        // don't suddenly start failing.
        // Verbatim by contract: selectedAll()/selected() are the data
        // channel (see the note at the auto-pick above), so they print
        // raw; only app-composed diagnostics are sanitised. Trailers are
        // pinned to "\n" — write(..., true) emits PHP_EOL, which is CRLF
        // on Windows, while the raw-capture contract is LF on every
        // platform.
        if ($final->isAborted() || !$final->isSubmitted() || ($noSelectedMessage !== null && $empty)) {
            if ($noSelectedMessage !== null) {
                $output->write($noSelectedMessage . "\n", false, OutputInterface::OUTPUT_RAW);
            }
            return Command::FAILURE;
        }
        if ($final->isMulti()) {
            $output->write(implode($delim, $final->selectedAll()) . "\n", false, OutputInterface::OUTPUT_RAW);
        } else {
            $output->write((string) $final->selected() . "\n", false, OutputInterface::OUTPUT_RAW);
        }
        return Command::SUCCESS;
    }
}
