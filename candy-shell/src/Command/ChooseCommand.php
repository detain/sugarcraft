<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Command;

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Shell\Model\ChooseModel;
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
            ->addOption('selected', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Pre-selected option(s) (multi mode).', [])
            ->addOption('select-if-one', null, InputOption::VALUE_NONE, 'Auto-pick when exactly one option is supplied.')
            ->addOption('output-delimiter', null, InputOption::VALUE_REQUIRED, 'Separator for multi-select output.', "\n")
            ->addOption('cursor', null, InputOption::VALUE_REQUIRED, 'Glyph rendered before the highlighted item.', '> ')
            ->addOption('cursor-prefix', null, InputOption::VALUE_REQUIRED, 'Alias for --cursor.', null)
            ->addOption('unselected-prefix', null, InputOption::VALUE_REQUIRED, 'Glyph rendered before non-cursor items.', null)
            ->addOption('show-help', null, InputOption::VALUE_NONE,    'Alias for --help (gum compat).')
            ->addOption('timeout',   null, InputOption::VALUE_REQUIRED, 'Auto-abort after N seconds (0 = none).', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $options */
        $options = $input->getArgument('options');
        $height  = (int) $input->getOption('height');
        $limit   = (int) $input->getOption('limit');
        $noLimit = (bool) $input->getOption('no-limit');
        $ordered = (bool) $input->getOption('ordered');
        $header  = (string) $input->getOption('header');
        /** @var list<string> $preselected */
        $preselected = $input->getOption('selected');
        $selectIfOne = (bool) $input->getOption('select-if-one');
        $delim   = (string) $input->getOption('output-delimiter');

        // Auto-pick when exactly one option is supplied.
        if ($selectIfOne && count($options) === 1) {
            $output->writeln($options[0]);
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
        );
        $program = new Program($model, new ProgramOptions(
            useAltScreen:    true,
            hideCursor:      true,
            catchInterrupts: true,
        ));
        /** @var ChooseModel $final */
        $final = $program->run();

        if ($final->isAborted() || !$final->isSubmitted()) {
            return Command::FAILURE;
        }
        if ($final->isMulti()) {
            $output->writeln(implode($delim, $final->selectedAll()));
        } else {
            $output->writeln((string) $final->selected());
        }
        return Command::SUCCESS;
    }
}
