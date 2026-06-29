<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Command;

use SugarCraft\Shell\Lang;
use SugarCraft\Sprinkles\Align;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Table\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Render CSV / TSV from a file or stdin as a styled
 * {@see \SugarCraft\Sprinkles\Table\Table}.
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
                'normal|rounded|thick|double|ascii|hidden|none', 'rounded')
            ->addOption('columns',    'c',  InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                "Override header titles (one per column). Repeat: --columns=Name --columns=Age.",
                [])
            ->addOption('widths',     'w',  InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                "Per-column max widths in cells. Repeat: --widths=20 --widths=10.",
                [])
            ->addOption('height',     null, InputOption::VALUE_REQUIRED, 'Cap rendered height in rows. 0 = unlimited.', 0)
            ->addOption('print',      'p',  InputOption::VALUE_NONE, 'Alias for the default print-and-exit behaviour (gum-compat).')
            ->addOption('show-help',  null, InputOption::VALUE_NONE, 'Alias for --help (gum-compat).')
            ->addOption('timeout',    null, InputOption::VALUE_REQUIRED, 'Seconds to wait before auto-exiting (no-op for table — gum-compat).', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getOption('file');
        if (is_string($file) && $file !== '') {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                $output->writeln('<error>' . Lang::t('io.read_failed', ['path' => $file]) . '</error>');
                return Command::FAILURE;
            }
        } else {
            $raw = self::readStdin();
        }
        if ($raw === '') {
            return Command::SUCCESS;
        }

        $sep = (string) $input->getOption('separator');
        $rows = self::parseRows($raw, $sep);
        if ($rows === []) {
            return Command::SUCCESS;
        }

        $hasHeader = (bool) $input->getOption('header');
        $headers   = $hasHeader ? array_shift($rows) : [];

        // --columns overrides header titles entirely.
        $colOverride = $input->getOption('columns');
        if (is_array($colOverride) && $colOverride !== []) {
            $headers = array_values(array_map('strval', $colOverride));
        }

        // --widths truncates each cell to the corresponding budget.
        /** @var list<int> $widths */
        $rawWidths = $input->getOption('widths');
        $widths = array_map('intval', is_array($rawWidths) ? $rawWidths : []);
        if ($widths !== []) {
            $rows = array_map(
                static fn (array $row) => array_map(
                    static function (string $cell, int $i) use ($widths): string {
                        $cap = $widths[$i] ?? 0;
                        if ($cap > 0 && mb_strlen($cell, 'UTF-8') > $cap) {
                            return mb_substr($cell, 0, $cap, 'UTF-8');
                        }
                        return $cell;
                    },
                    $row,
                    array_keys($row),
                ),
                $rows,
            );
            if ($headers !== []) {
                $headers = array_map(
                    static function (string $h, int $i) use ($widths): string {
                        $cap = $widths[$i] ?? 0;
                        return $cap > 0 && mb_strlen($h, 'UTF-8') > $cap
                            ? mb_substr($h, 0, $cap, 'UTF-8')
                            : $h;
                    },
                    $headers,
                    array_keys($headers),
                );
            }
        }

        $border = self::parseBorder((string) $input->getOption('border'));

        $table = Table::new()->headers(...$headers);
        if ($border !== null) {
            $table = $table->border($border);
        }
        foreach ($rows as $row) {
            $table = $table->row(...$row);
        }
        $rendered = $table->render();

        // --height caps the rendered output to N rows.
        $height = (int) $input->getOption('height');
        if ($height > 0) {
            $lines = explode("\n", $rendered);
            if (count($lines) > $height) {
                $rendered = implode("\n", array_slice($lines, 0, $height));
            }
        }

        $output->writeln($rendered);
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
            default       => throw new \InvalidArgumentException(Lang::t('border.unknown', ['name' => $name])),
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
