<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Runtime\PositiveInt;
use SugarCraft\Style\Align;
use SugarCraft\Style\Border;
use SugarCraft\Style\Table\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Render CSV / TSV from a file or stdin as a styled
 * {@see \SugarCraft\Style\Table\Table}.
 *
 *   $ ps -axo pid,comm | candy-scriptui table --has-header --delimiter $'\t'
 *
 * The first row is treated as the header when `--header` (gum alias
 * `--has-header`) is set. Column separator is `--separator` (gum alias
 * `--delimiter`). The border style is selectable via `--border`; pass
 * `none` to skip the border entirely.
 */
#[AsCommand(name: 'table', description: 'Render CSV / TSV input as a styled table.')]
final class TableCommand extends Command
{
    /** @var list<string> every spelling parseBorder() resolves (incl. aliases). */
    public const BORDER_SPELLINGS = ['none', 'off', 'normal', 'rounded', 'thick', 'double', 'ascii', 'hidden'];

    protected function configure(): void
    {
        $this
            ->addOption('file',      'f', InputOption::VALUE_REQUIRED, 'Input file. Default: stdin.')
            ->addOption('separator', 's', InputOption::VALUE_REQUIRED, 'Column separator (gum alias: --delimiter). Default: comma.', ',')
            ->addOption('delimiter', null, InputOption::VALUE_REQUIRED, 'gum-compat alias for --separator.')
            ->addOption('header',     null, InputOption::VALUE_NONE, 'Treat the first row as a header (gum alias: --has-header).')
            ->addOption('has-header', null, InputOption::VALUE_NONE, 'gum-compat alias for --header.')
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
        try {
            $height = PositiveInt::parse('height', $input->getOption('height'));
        } catch (\InvalidArgumentException $e) {
            // Refuse a typo'd height BEFORE reading the input.
            fwrite(STDERR, $e->getMessage() . "\n");
            return Command::FAILURE;
        }

        $file = $input->getOption('file');
        if (is_string($file) && $file !== '') {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                fwrite(STDERR, Lang::t('io.read_failed', ['path' => $file]) . "\n");
                return Command::FAILURE;
            }
        } else {
            $raw = self::readStdin();
        }
        if ($raw === '') {
            // Opposite polarity to `filter`, same cause: an empty table
            // renders nothing and is not an error — but it is announced.
            fwrite(STDERR, Lang::t('runtime.empty_input') . "\n");

            return Command::SUCCESS;
        }

        // scriptui-28: gum spells these --delimiter/--has-header; both
        // spellings parse, the gum names being pure aliases. Explicit
        // --separator plus a different --delimiter is refused rather
        // than silently last-wins.
        $sep = (string) $input->getOption('separator');
        $delim = $input->getOption('delimiter');
        if (is_string($delim) && $delim !== '') {
            if (
                $input->hasParameterOption(['--separator', '-s'], true)
                && $sep !== $delim
            ) {
                fwrite(STDERR, Lang::t('runtime.conflicting_flags', ['first' => '--separator', 'second' => '--delimiter']) . "\n");
                return Command::FAILURE;
            }
            $sep = $delim;
        }
        $rows = self::parseRows($raw, $sep);
        if ($rows === []) {
            return Command::SUCCESS;
        }

        $hasHeader = (bool) $input->getOption('header') || (bool) $input->getOption('has-header');
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

        $borderName = strtolower((string) $input->getOption('border'));
        if (!in_array($borderName, self::BORDER_SPELLINGS, true)) {
            fwrite(STDERR, Lang::t('runtime.unknown_choice', [
                'option' => '--border',
                'value' => (string) $input->getOption('border'),
                'allowed' => 'none|normal|rounded|thick|double|ascii|hidden',
            ]) . "\n");
            return Command::FAILURE;
        }
        $border = self::parseBorder($borderName);

        $table = Table::new()->headers(...$headers);
        if ($border !== null) {
            $table = $table->border($border);
        }
        foreach ($rows as $row) {
            $table = $table->row(...$row);
        }
        $rendered = $table->render();

        // --height caps the rendered output to N rows.
        if ($height > 0) {
            $lines = explode("\n", $rendered);
            if (count($lines) > $height) {
                $rendered = implode("\n", array_slice($lines, 0, $height));
            }
        }

        // LF-pinned trailer: write(..., true) emits PHP_EOL (CRLF on Windows).
        $output->write($rendered . "\n", false, OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }

    /**
     * Parse a CSV/TSV blob into rows.
     *
     * Single-character separators run through {@see parseCsv()}, a
     * hand-rolled RFC-4180 reader with NO escape character (scriptui-30:
     * byte-constant across PHP versions where fgetcsv's escape folds
     * `\"` away) so records with newlines inside quoted fields stay
     * together (e.g. exported description columns containing line
     * breaks). Multi-character separators fall back to a literal-split
     * implementation — there is no quoting convention for those, so each
     * physical line is one row.
     *
     * @return list<list<string>>
     */
    public static function parseRows(string $raw, string $separator): array
    {
        $raw = rtrim($raw, "\r\n");
        if ($raw === '') {
            return [];
        }

        if (strlen($separator) !== 1) {
            // scriptui-38 (finding 056): split on \R, not bare LF —
            // Windows-authored files used to keep a literal CR inside the
            // last cell of every row (od-proven `b \r │`). FilterCommand::
            // stdinLines establishes this posture repo-wide.
            $rows = [];
            foreach (preg_split('/\R/', $raw) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                $rows[] = explode($separator, $line);
            }
            return $rows;
        }

        // scriptui-30 (finding 040): the RFC lane used to run through
        // fgetcsv(escape:'\\') — a NON-RFC fold: PHP turns `"a\"b"` into
        // `a"b` while Go's encoding/csv (gum's compatibility bar) treats
        // backslash as ordinary data, and PHP 8.4 deprecates the custom
        // escape (removal in 9.0) — with failOnWarning="true" that is a
        // standing CI red waiting for the matrix move. Hand-rolled here so
        // the reading is BYTE-CONSTANT across every PHP the manifest
        // admits: doubled quotes escape a quote, a lone backslash is just
        // data, and after a closing quote the remainder of the segment
        // rides verbatim (the lenient arm — Go would reject the input; a
        // CLI printer must not).
        return self::parseCsv($raw, $separator);
    }

    /**
     * @return list<list<string>>
     */
    private static function parseCsv(string $raw, string $sep): array
    {
        $rows  = [];
        $row   = [];
        $field = '';
        $quoted = false;
        // Once a quoted field has CLOSED, further quotes inside the same
        // segment are literal data (no silent re-open).
        $closed = false;
        $n = strlen($raw);
        $i = 0;

        $out = [];
        while ($i < $n) {
            $c = $raw[$i];
            if ($quoted) {
                if ($c === '"') {
                    if ($i + 1 < $n && $raw[$i + 1] === '"') {
                        $field .= '"';
                        $i += 2;
                        continue;
                    }
                    $quoted = false;
                    $closed = true;
                    $i++;
                    continue;
                }
                $field .= $c;
                $i++;
                continue;
            }
            if ($c === '"' && !$closed && $field === '') {
                $quoted = true;
                $i++;
                continue;
            }
            if ($c === $sep) {
                $row[]  = $field;
                $field  = '';
                $closed = false;
                $i++;
                continue;
            }
            if ($c === "\r" || $c === "\n") {
                $i += ($c === "\r" && $i + 1 < $n && $raw[$i + 1] === "\n") ? 2 : 1;
                $row[]  = $field;
                $field  = '';
                $closed = false;
                if ($row !== ['']) {
                    $out[] = $row;
                }
                $row = [];
                continue;
            }
            $field .= $c;
            $i++;
        }
        if ($field !== '' || $row !== []) {
            $row[] = $field;
            if ($row !== ['']) {
                $out[] = $row;
            }
        }
        return $out;
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
