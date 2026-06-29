<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Command;

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Shell\Model\FilterModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pick one line from stdin via incremental fuzzy filter. Designed for
 * pipelines:
 *
 *   $ ls | candyshell filter
 *
 * Enter submits the highlighted match; Esc / Ctrl-C aborts.
 */
#[AsCommand(name: 'filter', description: 'Incremental filter over stdin lines.')]
final class FilterCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('height',           null, InputOption::VALUE_REQUIRED, 'Visible item count.', 10)
            ->addOption('limit',            null, InputOption::VALUE_REQUIRED, 'Maximum selections (>1 enables multi).', 1)
            ->addOption('no-limit',         null, InputOption::VALUE_NONE,    'Allow unlimited multi-select.')
            ->addOption('header',           null, InputOption::VALUE_REQUIRED, 'Header text rendered above the list.', '')
            ->addOption('value',            null, InputOption::VALUE_REQUIRED, 'Pre-fill the filter buffer.', '')
            ->addOption('placeholder',      null, InputOption::VALUE_REQUIRED, 'Placeholder text shown when filter is empty.', '')
            ->addOption('selected',         null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Pre-selected option (multi mode).', [])
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
            ->addOption('show-help', null, InputOption::VALUE_NONE, 'Alias for --help (gum compat).')
            ->addOption('timeout',   null, InputOption::VALUE_REQUIRED, 'Auto-abort after N seconds (0 = none).', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lines = self::stdinLines();
        if ($lines === []) {
            return Command::FAILURE;
        }
        if ($input->getOption('select-if-one') && count($lines) === 1) {
            $output->writeln($lines[0]);
            return Command::SUCCESS;
        }

        // --indicator is an alias for --cursor; user-supplied wins.
        $cursor = $input->getOption('indicator') ?? $input->getOption('cursor');
        $unselected = $input->getOption('unselected-prefix');

        $model   = FilterModel::fromOptions(
            options:          $lines,
            height:           (int)    $input->getOption('height'),
            limit:            (int)    $input->getOption('limit'),
            noLimit:          (bool)   $input->getOption('no-limit'),
            header:           (string) $input->getOption('header'),
            preselected:      $input->getOption('selected'),
            reverse:          (bool)   $input->getOption('reverse'),
            value:            (string) $input->getOption('value'),
            cursorPrefix:     is_string($cursor) ? $cursor : null,
            unselectedPrefix: is_string($unselected) ? $unselected : null,
            fuzzy:            (bool) $input->getOption('fuzzy'),
        );
        $program = new Program($model, new ProgramOptions(
            useAltScreen:    true,
            hideCursor:      true,
            catchInterrupts: true,
        ));
        /** @var FilterModel $final */
        $final = $program->run();

        if ($final->isAborted() || !$final->isSubmitted()) {
            return Command::FAILURE;
        }
        if ($final->isMulti()) {
            $output->writeln(implode((string) $input->getOption('output-delimiter'), $final->selectedAll()));
        } else {
            $output->writeln((string) $final->selected());
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
        $raw = rtrim($raw, "\n");
        return $raw === '' ? [] : explode("\n", $raw);
    }
}
