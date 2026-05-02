<?php

declare(strict_types=1);

namespace CandyCore\Shell\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Concatenate every argument with a separator, optionally with horizontal
 * (per-line) joining. Lipgloss's `JoinHorizontal` / `JoinVertical` ship
 * here as `--horizontal` / `--vertical` modes. Reads from stdin when no
 * positional arguments are given.
 */
#[AsCommand(name: 'join', description: 'Join the given strings with a separator (vertical or horizontal).')]
final class JoinCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('parts', InputArgument::IS_ARRAY, 'Strings to join. Empty = read from stdin (one per line).')
            ->addOption('horizontal', null, InputOption::VALUE_NONE, 'Join side-by-side per line.')
            ->addOption('vertical',   null, InputOption::VALUE_NONE, 'Join with newlines (default).')
            ->addOption('separator',  null, InputOption::VALUE_REQUIRED, 'Custom separator (default: "\n" vertical, "" horizontal).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $parts = $input->getArgument('parts') ?: self::stdinLines();
        if ($parts === []) {
            return Command::SUCCESS;
        }

        $horizontal = (bool) $input->getOption('horizontal');
        $separator  = $input->getOption('separator');

        if ($horizontal) {
            $output->writeln(self::joinHorizontal($parts, is_string($separator) ? $separator : ''));
            return Command::SUCCESS;
        }
        $output->writeln(implode(is_string($separator) ? $separator : "\n", $parts));
        return Command::SUCCESS;
    }

    /**
     * Stitch each $parts string into the same row layout, padding shorter
     * blocks to the tallest one and joining each row with $separator.
     *
     * @param list<string> $parts
     */
    public static function joinHorizontal(array $parts, string $separator = ''): string
    {
        if ($parts === []) {
            return '';
        }
        $blocks = array_map(static fn(string $p) => explode("\n", $p), $parts);
        $maxRow = max(array_map('count', $blocks));

        $rows = [];
        for ($r = 0; $r < $maxRow; $r++) {
            $cells = [];
            foreach ($blocks as $b) {
                $cells[] = $b[$r] ?? '';
            }
            $rows[] = implode($separator, $cells);
        }
        return implode("\n", $rows);
    }

    /** @return list<string> */
    private static function stdinLines(): array
    {
        if (defined('STDIN') && is_resource(STDIN) && !@stream_isatty(STDIN)) {
            $raw = stream_get_contents(STDIN) ?: '';
            $raw = rtrim($raw, "\n");
            return $raw === '' ? [] : explode("\n", $raw);
        }
        return [];
    }
}
