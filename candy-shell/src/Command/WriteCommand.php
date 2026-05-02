<?php

declare(strict_types=1);

namespace CandyCore\Shell\Command;

use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Shell\Model\WriteModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Multi-line text editor. Press Ctrl+D to submit, Esc / Ctrl+C to
 * abort. Submitted text is printed verbatim on stdout.
 */
#[AsCommand(name: 'write', description: 'Open a multi-line editor; print the result on submit (Ctrl+D).')]
final class WriteCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('placeholder', null, InputOption::VALUE_REQUIRED, 'Hint shown when empty.', '')
            ->addOption('width',       null, InputOption::VALUE_REQUIRED, 'Editor width in cells.',   0)
            ->addOption('height',      null, InputOption::VALUE_REQUIRED, 'Editor height in rows.',   0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $model   = WriteModel::newPrompt(
            (string) $input->getOption('placeholder'),
            (int)    $input->getOption('width'),
            (int)    $input->getOption('height'),
        );
        $program = new Program($model, new ProgramOptions(
            useAltScreen:    true,
            hideCursor:      false,
            catchInterrupts: true,
        ));
        /** @var WriteModel $final */
        $final = $program->run();

        if ($final->isAborted() || !$final->isSubmitted()) {
            return Command::FAILURE;
        }
        $output->writeln($final->value());
        return Command::SUCCESS;
    }
}
