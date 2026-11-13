<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use SugarCraft\Core\Program;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Model\FilterModel;
use SugarCraft\ScriptUi\Runtime\OptionList;
use SugarCraft\ScriptUi\Runtime\ItemSource;
use Symfony\Component\Console\Input\InputArgument;
use SugarCraft\ScriptUi\Runtime\PositiveInt;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;
use SugarCraft\ScriptUi\Runtime\SignalExit;
use SugarCraft\ScriptUi\Runtime\TimeoutGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pick one line from stdin via incremental fuzzy filter. Designed for
 * pipelines:
 *
 *   $ ls | candy-scriptui filter
 *
 * Enter submits the highlighted match; Esc aborts (exit 1), Ctrl+C
 * cancels (exit 130), a --timeout deadline exits 124, and an OS signal
 * exits 128+signum.
 */
#[AsCommand(name: 'filter', description: 'Incremental filter over stdin lines.')]
final class FilterCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('options', InputArgument::IS_ARRAY, 'Items to filter; when empty, lines are read from stdin.')
            ->addOption('item', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Item to filter; repeatable, comma-split (\\, stays a literal comma).', [])
            ->addOption('height',           null, InputOption::VALUE_REQUIRED, 'Visible item count.', 10)
            ->addOption('limit',            null, InputOption::VALUE_REQUIRED, 'Maximum selections (>1 enables multi).', 1)
            ->addOption('no-limit',         null, InputOption::VALUE_NONE,    'Allow unlimited multi-select.')
            ->addOption('header',           null, InputOption::VALUE_REQUIRED, 'Header text rendered above the list.', '')
            ->addOption('value',            null, InputOption::VALUE_REQUIRED, 'Pre-fill the filter buffer.', '')
            ->addOption('placeholder',      null, InputOption::VALUE_REQUIRED, 'Placeholder text shown when filter is empty.', '')
            ->addOption('selected',         null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Pre-selected option(s) (multi mode); repeatable, comma-separated lists are split.', [])
            ->addOption('reverse',          null, InputOption::VALUE_NONE,    'Reverse the multi-select output order.')
            ->addOption('select-if-one',    null, InputOption::VALUE_NONE,    'Auto-pick when the input has exactly one line.')
            ->addOption('output-delimiter', null, InputOption::VALUE_REQUIRED, 'Separator for multi-select output.', "\n")
            ->addOption('cursor', null, InputOption::VALUE_REQUIRED, 'Glyph rendered before the highlighted item.', '> ')
            ->addOption('indicator', null, InputOption::VALUE_REQUIRED, 'Alias for --cursor.', null)
            ->addOption('unselected-prefix', null, InputOption::VALUE_REQUIRED, 'Glyph rendered before non-cursor items.', null)
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Substring-only match (the default — flag accepted for gum compat).')
            ->addOption('fuzzy', null, InputOption::VALUE_NONE, 'Fuzzy match using Smith-Waterman scoring with highlight indices.')
            ->addOption('no-fuzzy', null, InputOption::VALUE_NONE, 'Disable fuzzy matching (default — gum compat).')
            ->addOption('width', null, InputOption::VALUE_REQUIRED, 'Cap rendered width in cells (0 = auto).', 0)
            ->addOption('print-query', null, InputOption::VALUE_NONE, 'Print the final filter text on its own line before the result (gum compat).')
            ->addOption('show-help', null, InputOption::VALUE_NONE, 'Alias for --help (gum compat).')
            ->addOption('no-alt-screen', null, InputOption::VALUE_NONE, 'Render inline instead of the alternate screen (keeps output in scrollback).')
            ->addOption('timeout',   null, InputOption::VALUE_REQUIRED, 'Auto-abort after N seconds (0 = none).', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timeoutSeconds = 0.0;
        if (!TimeoutGuard::tryParseSeconds($input->getOption('timeout'), $timeoutSeconds)) {
            // Refuse a typo'd deadline BEFORE consuming stdin — and never
            // let it silently disable itself (fail-open).
            fwrite(STDERR, Lang::t('runtime.bad_timeout', ['value' => (string) $input->getOption('timeout')]) . "\n");
            return Command::FAILURE;
        }

        // scriptui-46 (finding 064): positionals AND repeatable --item feed
        // the list directly; stdin remains the fallback source only.
        /** @var list<string> $positionalOptions */
        $positionalOptions = (array) $input->getArgument('options');
        /** @var list<string> $itemFlags */
        $itemFlags = (array) $input->getOption('item');
        $lines = ItemSource::collect($positionalOptions, $itemFlags, self::stdinLines(...));
        if ($lines === []) {
            // Silence on empty stdin read as a crash to shell scripts;
            // the FAILURE polarity stays (gum parity), the reason is said.
            // scriptui-33 (candy-scriptui-052): filter's refusal names the
            // filter's own silence — the shared copy read as an error about
            // the sibling `input` subcommand.
            fwrite(STDERR, Lang::t('runtime.empty_input_filter') . "\n");

            return Command::FAILURE;
        }
        if ($input->getOption('select-if-one') && count($lines) === 1) {
            // scriptui-15: the auto-pick IS the selection — it joins the
            // verbatim data channel, folded bytes would name a file that
            // does not exist.
            $output->write($lines[0] . "\n", false, OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        // --indicator is an alias for --cursor; user-supplied wins.
        $cursor = $input->getOption('indicator') ?? $input->getOption('cursor');
        $unselected = $input->getOption('unselected-prefix');

        try {
            $height = PositiveInt::parse('height', $input->getOption('height'));
            $limit  = PositiveInt::parse('limit', $input->getOption('limit'));
            $width  = PositiveInt::parse('width', $input->getOption('width'));
        } catch (\InvalidArgumentException $e) {
            // A typo'd number must not silently relocate the mode.
            fwrite(STDERR, $e->getMessage() . "\n");
            return Command::FAILURE;
        }

        $model   = FilterModel::fromOptions(
            options:          $lines,
            height:           $height,
            limit:            $limit,
            noLimit:          (bool)   $input->getOption('no-limit'),
            header:           (string) $input->getOption('header'),
            preselected:      OptionList::expand((array) $input->getOption('selected')),
            reverse:          (bool)   $input->getOption('reverse'),
            value:            (string) $input->getOption('value'),
            cursorPrefix:     is_string($cursor) ? $cursor : null,
            unselectedPrefix: is_string($unselected) ? $unselected : null,
            fuzzy:            (bool) $input->getOption('fuzzy'),
            width:            $width,
            placeholder:      (string) $input->getOption('placeholder'),
        );
        $loop    = \React\EventLoop\Loop::get();
        $options = InteractiveRuntime::programOptions(loop: $loop, 
            useAltScreen: ! (bool) $input->getOption('no-alt-screen'),
            hideCursor:   true,
        );
        if ($options === null) {
            fwrite(STDERR, Lang::t('runtime.no_terminal', ['command' => 'filter']) . "\n");
            return Command::FAILURE;
        }
        $program = new Program($model, $options);
        $guard = TimeoutGuard::arm($loop, $timeoutSeconds, fn () => $program->kill());
        /** @var FilterModel $final */
        $final = $program->run();
        $guard->disarm();

        // Deadline elapsed before submit — emit nothing, exit timeout.
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

        return self::renderResult(
            $final,
            $output,
            (string) $input->getOption('output-delimiter'),
            (bool) $input->getOption('print-query'),
        );
    }

    /**
     * Map the finished model to process output + exit code. Static and
     * separate from execute() so the contract is unit-testable without the
     * live TTY that Program::run() needs.
     */
    public static function renderResult(
        FilterModel $final,
        OutputInterface $output,
        string $delim,
        bool $printQuery,
    ): int {
        // Output contract, deliberate (gum-parity raw capture): the query
        // echo and the selection results below are the DATA channel and
        // print VERBATIM; app-emitted refusals elsewhere in this command
        // pass through Sanitize::cellValue. Trailers are pinned to "\n" —
        // write(..., true) emits PHP_EOL, CRLF on Windows, while the
        // raw-capture contract is LF on every platform.
        if ($final->isAborted() || !$final->isSubmitted()) {
            return Command::FAILURE;
        }
        if ($printQuery) {
            $output->write($final->list->filterValue() . "\n", false, OutputInterface::OUTPUT_RAW);
        }
        if ($final->isMulti()) {
            $output->write(implode($delim, $final->selectedAll()) . "\n", false, OutputInterface::OUTPUT_RAW);
        } else {
            $output->write((string) $final->selected() . "\n", false, OutputInterface::OUTPUT_RAW);
        }
        return Command::SUCCESS;
    }

    /** @return list<string> */
    private static function stdinLines(): array
    {
        if (!defined('STDIN') || !is_resource(STDIN) || @stream_isatty(STDIN)) {
            return [];
        }
        $raw = stream_get_contents(STDIN) ?: '';
        // Split on any newline shape (\R): a CRLF stream (Windows console pipes)
        // otherwise carries a stray "\r" into every line and corrupts the raw
        // output channel. rtrim covers both trailer shapes.
        $raw = rtrim($raw, "\r\n");
        if ($raw === '') {
            return [];
        }
        $lines = preg_split('/\R/', $raw);
        return $lines === false ? [] : $lines;
    }
}
