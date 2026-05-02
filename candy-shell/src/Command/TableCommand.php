<?php

declare(strict_types=1);

namespace CandyCore\Shell\Command;

use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Table\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Render CSV / TSV from a file or stdin as a styled
 * {@see \CandyCore\Sprinkles\Table\Table}.
 *
 *   $ ps -axo pid,comm | candyshell table --header --separator $'\t'
 *
 * The first row is treated as the header when `--header` is set. The
 * border style is selectable via `--border`; pass `none` to skip the
 * border entirely.
 */
#[AsCommand(name: 'table', description: 'Render CSV / TSV input as a styled table.')]
final class TableCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('file',      'f', InputOption::VALUE_REQUIRED, 'Input file. Default: stdin.')
            ->addOption('separator', 's', InputOption::VALUE_REQUIRED, 'Column separator. Default: comma.', ',')
            ->addOption('header',     null, InputOption::VALUE_NONE, 'Treat the first row as a header.')
            ->addOption('border',     null, InputOption::VALUE_REQUIRED,
                'normal|rounded|thick|double|ascii|hidden|none', 'rounded');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getOption('file');
        $raw  = is_string($file) && $file !== ''
            ? @file_get_contents($file)
            : self::readStdin();
        if (!is_string($raw) || $raw === '') {
            return Command::SUCCESS;
        }

        $sep = (string) $input->getOption('separator');
        $rows = self::parseRows($raw, $sep);
        if ($rows === []) {
            return Command::SUCCESS;
        }

        $hasHeader = (bool) $input->getOption('header');
        $headers   = $hasHeader ? array_shift($rows) : [];

        $border = self::parseBorder((string) $input->getOption('border'));

        $table = Table::new()->headers(...$headers);
        if ($border !== null) {
            $table = $table->border($border);
        }
        foreach ($rows as $row) {
            $table = $table->row(...$row);
        }
        $output->writeln($table->render());
        return Command::SUCCESS;
    }

    /**
     * Parse a CSV/TSV blob into rows.
     *
     * Single-character separators are handled via {@see fgetcsv()} over
     * an in-memory stream so records with newlines inside quoted fields
     * stay together (e.g. exported description columns containing line
     * breaks). Multi-character separators fall back to a literal-split
     * implementation — there is no quoting convention for those, so each
     * physical line is one row.
     *
     * @return list<list<string>>
     */
    public static function parseRows(string $raw, string $separator): array
    {
        $raw = rtrim($raw, "\n");
        if ($raw === '') {
            return [];
        }

        if (strlen($separator) !== 1) {
            $rows = [];
            foreach (explode("\n", $raw) as $line) {
                if ($line === '') {
                    continue;
                }
                $rows[] = explode($separator, $line);
            }
            return $rows;
        }

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return [];
        }
        try {
            fwrite($stream, $raw);
            rewind($stream);
            $rows = [];
            while (($row = fgetcsv($stream, 0, $separator, '"', '\\')) !== false) {
                if ($row === [null]) {
                    // fgetcsv returns [null] for blank lines.
                    continue;
                }
                $rows[] = array_map(static fn($c) => (string) ($c ?? ''), $row);
            }
            return $rows;
        } finally {
            fclose($stream);
        }
    }

    public static function parseBorder(string $name): ?Border
    {
        return match (strtolower($name)) {
            'none', 'off' => null,
            'normal'      => Border::normal(),
            'rounded'     => Border::rounded(),
            'thick'       => Border::thick(),
            'double'      => Border::double(),
            'ascii'       => Border::ascii(),
            'hidden'      => Border::hidden(),
            default       => throw new \InvalidArgumentException("unknown border style: $name"),
        };
    }

    private static function readStdin(): string
    {
        if (!defined('STDIN') || !is_resource(STDIN) || @stream_isatty(STDIN)) {
            return '';
        }
        return (string) stream_get_contents(STDIN);
    }
}
