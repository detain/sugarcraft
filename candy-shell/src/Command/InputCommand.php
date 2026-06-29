<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Command;

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Shell\Model\InputModel;
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
            ->addOption('strip-ansi',  null, InputOption::VALUE_NONE,     'Strip ANSI escapes from the printed result.')
            ->addOption('cursor-mode', null, InputOption::VALUE_REQUIRED, 'Cursor mode: blink (default) | static | hidden.', 'blink')
            ->addOption('show-help',   null, InputOption::VALUE_NONE,     'Alias for --help (gum compat).')
            ->addOption('timeout',     null, InputOption::VALUE_REQUIRED, 'Auto-abort after N seconds (0 = none).', 0);
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
        $value = $final->value();
        if ($input->getOption('strip-ansi')) {
            $value = \SugarCraft\Core\Util\Ansi::strip($value);
        }
        $output->writeln($value);
        return Command::SUCCESS;
    }
}
