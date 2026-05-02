<?php

declare(strict_types=1);

namespace CandyCore\Shell\Command;

use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Shell\Model\ConfirmModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Confirmation prompt. Exit code reflects the answer: 0 for yes, 1 for
 * no, 2 for abort (Esc / Ctrl-C).
 */
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
            ->addOption('default-yes', null, InputOption::VALUE_NONE,     'Default to yes.')
            ->addOption('default',     null, InputOption::VALUE_REQUIRED, 'Default answer (yes|no).', '')
            ->addOption('affirmative', null, InputOption::VALUE_REQUIRED, 'Label for the yes option.',  'Yes')
            ->addOption('negative',    null, InputOption::VALUE_REQUIRED, 'Label for the no option.',   'No')
            ->addOption('show-output', null, InputOption::VALUE_NONE,     'Print the chosen label on stdout.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $defaultRaw = strtolower((string) $input->getOption('default'));
        $defaultYes = (bool) $input->getOption('default-yes')
            || $defaultRaw === 'yes' || $defaultRaw === 'y';

        $affirm = (string) $input->getOption('affirmative');
        $negate = (string) $input->getOption('negative');

        $model   = ConfirmModel::newPrompt(
            title:       (string) $input->getArgument('question'),
            default:     $defaultYes,
            affirmative: $affirm,
            negative:    $negate,
        );
        $program = new Program($model, new ProgramOptions(
            useAltScreen:    false,
            hideCursor:      false,
            catchInterrupts: true,
        ));
        /** @var ConfirmModel $final */
        $final = $program->run();

        if ($final->isAborted()) {
            return self::EXIT_ABORT;
        }
        if ($input->getOption('show-output')) {
            $output->writeln($final->answer() ? $affirm : $negate);
        }
        return $final->answer() ? self::EXIT_YES : self::EXIT_NO;
    }
}
