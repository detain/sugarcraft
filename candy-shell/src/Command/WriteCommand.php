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
            ->addOption('placeholder',        null, InputOption::VALUE_REQUIRED, 'Hint shown when empty.', '')
            ->addOption('width',              null, InputOption::VALUE_REQUIRED, 'Editor width in cells.',   0)
            ->addOption('height',             null, InputOption::VALUE_REQUIRED, 'Editor height in rows.',   0)
            ->addOption('value',              null, InputOption::VALUE_REQUIRED, 'Pre-fill the editor with this text.', '')
            ->addOption('char-limit',         null, InputOption::VALUE_REQUIRED, 'Max character count (0 = unlimited).', 0)
            ->addOption('max-lines',          null, InputOption::VALUE_REQUIRED, 'Max line count (0 = unlimited).', 0)
            ->addOption('prompt',             null, InputOption::VALUE_REQUIRED, 'Static prefix on every line.', '')
            ->addOption('show-line-numbers',  null, InputOption::VALUE_NONE,    'Show 1-based line numbers in a left gutter.')
            ->addOption('header',             null, InputOption::VALUE_REQUIRED, 'Header text rendered above the editor.', '')
            ->addOption('style', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                "Per-element style: '<elem>.<prop>=<value>'. Elements: cursor, prompt, header, placeholder, lineNumber.",
                []
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $model   = WriteModel::newPrompt(
            placeholder:     (string) $input->getOption('placeholder'),
            width:           (int)    $input->getOption('width'),
            height:          (int)    $input->getOption('height'),
            value:           (string) $input->getOption('value'),
            charLimit:       (int)    $input->getOption('char-limit'),
            maxLines:        (int)    $input->getOption('max-lines'),
            prompt:          (string) $input->getOption('prompt'),
            showLineNumbers: (bool)   $input->getOption('show-line-numbers'),
            header:          (string) $input->getOption('header'),
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
