<?php

declare(strict_types=1);

namespace CandyCore\Shell\Command;

use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Shell\Model\PagerModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scroll long input. Reads from stdin or `--file` and lets the user
 * navigate with the standard pager keys (↑/↓ / PgUp / PgDn / Home / End
 * / `q` to quit).
 */
#[AsCommand(name: 'pager', description: 'Scroll long input in a fullscreen pager.')]
final class PagerCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('file',   'f', InputOption::VALUE_REQUIRED, 'Input file. Default: stdin.')
            ->addOption('width',  null, InputOption::VALUE_REQUIRED, 'Pager width.',  80)
            ->addOption('height', null, InputOption::VALUE_REQUIRED, 'Pager height.', 20);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getOption('file');
        $raw  = is_string($file) && $file !== ''
            ? @file_get_contents($file)
            : self::readStdin();
        if (!is_string($raw)) {
            return Command::FAILURE;
        }

        $model   = PagerModel::fromContent($raw, (int) $input->getOption('width'), (int) $input->getOption('height'));
        $program = new Program($model, new ProgramOptions(
            useAltScreen:    true,
            hideCursor:      true,
            catchInterrupts: true,
        ));
        $program->run();
        return Command::SUCCESS;
    }

    private static function readStdin(): string
    {
        if (!defined('STDIN') || !is_resource(STDIN) || @stream_isatty(STDIN)) {
            return '';
        }
        return (string) stream_get_contents(STDIN);
    }
}
