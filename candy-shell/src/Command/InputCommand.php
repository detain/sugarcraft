<?php

declare(strict_types=1);

namespace CandyCore\Shell\Command;

use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Shell\Model\InputModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read a single line from the user. Prints the entered value on stdout;
 * exits 1 when aborted.
 */
#[AsCommand(name: 'input', description: 'Prompt for a single line of input.')]
final class InputCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('placeholder', null, InputOption::VALUE_REQUIRED, 'Hint shown when empty.', '')
            ->addOption('password',    null, InputOption::VALUE_NONE,     'Mask the entered text.')
            ->addOption('prompt',      null, InputOption::VALUE_REQUIRED, 'Prompt prefix.',   '> ')
            ->addOption('value',       null, InputOption::VALUE_REQUIRED, 'Pre-filled value.', '')
            ->addOption('char-limit',  null, InputOption::VALUE_REQUIRED, 'Max input length (0 = unlimited).', 0)
            ->addOption('width',       null, InputOption::VALUE_REQUIRED, 'Visible width (0 = full line).', 0)
            ->addOption('header',      null, InputOption::VALUE_REQUIRED, 'Header text rendered above the prompt.', '')
            ->addOption('style', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                "Per-element style: '<elem>.<prop>=<value>'. Elements: cursor, prompt, header, placeholder.",
                []
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $model   = InputModel::newPrompt(
            placeholder: (string) $input->getOption('placeholder'),
            password:    (bool)   $input->getOption('password'),
            prompt:      (string) $input->getOption('prompt'),
            value:       (string) $input->getOption('value'),
            charLimit:   (int)    $input->getOption('char-limit'),
            width:       (int)    $input->getOption('width'),
            header:      (string) $input->getOption('header'),
        );
        $program = new Program($model, new ProgramOptions(
            useAltScreen:    false,
            hideCursor:      false,
            catchInterrupts: true,
        ));
        /** @var InputModel $final */
        $final = $program->run();

        if ($final->isAborted() || !$final->isSubmitted()) {
            return Command::FAILURE;
        }
        $output->writeln($final->value());
        return Command::SUCCESS;
    }
}
