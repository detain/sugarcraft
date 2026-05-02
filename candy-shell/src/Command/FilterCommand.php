<?php

declare(strict_types=1);

namespace CandyCore\Shell\Command;

use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Shell\Model\FilterModel;
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
            ->addOption('output-delimiter', null, InputOption::VALUE_REQUIRED, 'Separator for multi-select output.', "\n");
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

        $model   = FilterModel::fromOptions(
            options:     $lines,
            height:      (int)    $input->getOption('height'),
            limit:       (int)    $input->getOption('limit'),
            noLimit:     (bool)   $input->getOption('no-limit'),
            header:      (string) $input->getOption('header'),
            preselected: $input->getOption('selected'),
            reverse:     (bool)   $input->getOption('reverse'),
            value:       (string) $input->getOption('value'),
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
