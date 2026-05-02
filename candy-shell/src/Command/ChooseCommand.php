<?php

declare(strict_types=1);

namespace CandyCore\Shell\Command;

use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Shell\Model\ChooseModel;
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
            ->addOption('height', null, InputOption::VALUE_REQUIRED, 'Visible item count.', 10);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $options */
        $options = $input->getArgument('options');
        $height  = (int) $input->getOption('height');

        $model   = ChooseModel::fromOptions($options, $height);
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
        $output->writeln((string) $final->selected());
        return Command::SUCCESS;
    }
}
