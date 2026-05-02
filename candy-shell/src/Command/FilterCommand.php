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
            ->addOption('height', null, InputOption::VALUE_REQUIRED, 'Visible item count.', 10);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lines = self::stdinLines();
        if ($lines === []) {
            return Command::FAILURE;
        }

        $model   = FilterModel::fromOptions($lines, (int) $input->getOption('height'));
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
        $output->writeln((string) $final->selected());
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
