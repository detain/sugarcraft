<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use SugarCraft\Core\Util\Width;
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
            ->addOption('separator',  null, InputOption::VALUE_REQUIRED, 'Custom separator (default: "\n" vertical, "" horizontal).')
            ->addOption('raw',        null, InputOption::VALUE_NONE, 'Emit data bytes verbatim, skipping the control-byte display policy (trusted pipelines only).')
            ->addOption('align',      null, InputOption::VALUE_REQUIRED,
                "Block alignment.\n"
              . "Horizontal mode: top|middle|bottom (vertical alignment of blocks of unequal height).\n"
              . "Vertical mode:   left|center|right (horizontal alignment of lines of unequal width).",
                'top'
            )
            ->addOption('show-help',  null, InputOption::VALUE_NONE, 'Alias for --help (gum compat).')
            ->addOption('timeout',    null, InputOption::VALUE_REQUIRED, 'Auto-abort after N seconds (0 = none, no-op for non-interactive join).', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $parts = $input->getArgument('parts') ?: self::stdinLines();
        if ($parts === []) {
            return Command::SUCCESS;
        }

        // scriptui-24 (finding 019): joined parts are the rendered data
        // channel — apply the house untrusted-display policy at intake
        // (pager doctrine) so control/escape bytes cannot ride through the
        // join into the operator's terminal, and alignment measures the
        // bytes that actually render. --raw opts out for byte-exact use.
        if (!$input->getOption('raw')) {
            $parts = array_map(
                static fn (string $part): string => \SugarCraft\Core\Util\Sanitize::untrustedForDisplay($part),
                $parts,
            );
        }

        $horizontal = (bool) $input->getOption('horizontal');
        $separator  = $input->getOption('separator');
        $align      = strtolower((string) $input->getOption('align'));

        // LF-pinned trailers: write(..., true) emits PHP_EOL (CRLF on Windows).
        if ($horizontal) {
            $output->write(self::joinHorizontal(
                $parts,
                is_string($separator) ? $separator : '',
                $align,
            ) . "\n", false, OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }
        $output->write(self::joinVertical(
            $parts,
            is_string($separator) ? $separator : "\n",
            $align,
        ) . "\n", false, OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }

    /**
     * Stitch each $parts string into the same row layout, padding shorter
     * blocks to the tallest one and joining each row with $separator.
     *
     * @param list<string> $parts
     * @param string $valign top | middle | bottom
     */
    public static function joinHorizontal(
        array $parts,
        string $separator = '',
        string $valign = 'top',
    ): string {
        if ($parts === []) {
            return '';
        }
        $blocks = array_map(static fn(string $p) => explode("\n", $p), $parts);
        $maxRow = max(array_map('count', $blocks));
        // Pre-pad each block to $maxRow lines according to $valign.
        $blocks = array_map(
            static fn(array $b) => self::padBlock($b, $maxRow, $valign),
            $blocks,
        );

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

    /**
     * Vertical join with optional horizontal alignment of each block's
     * lines against the widest line across all blocks.
     *
     * @param list<string> $parts
     * @param string $halign left | center | right
     */
    public static function joinVertical(
        array $parts,
        string $separator = "\n",
        string $halign = 'left',
    ): string {
        if ($parts === []) {
            return '';
        }
        $halign = $halign === '' ? 'left' : $halign;
        if ($halign === 'left') {
            return implode($separator, $parts);
        }
        // Compute the widest visible line across every block.
        $maxW = 0;
        $allLines = [];
        foreach ($parts as $p) {
            $lines = explode("\n", $p);
            $allLines[] = $lines;
            foreach ($lines as $l) {
                $w = Width::string($l);
                if ($w > $maxW) {
                    $maxW = $w;
                }
            }
        }
        $aligned = [];
        foreach ($allLines as $lines) {
            $aligned[] = implode("\n", array_map(
                static fn(string $l) => match ($halign) {
                    'right'  => Width::padLeft($l, $maxW),
                    'center' => Width::padCenter($l, $maxW),
                    default  => $l,
                },
                $lines,
            ));
        }
        return implode($separator, $aligned);
    }

    /**
     * Pad a block of lines to $target rows using $valign placement.
     *
     * @param list<string> $block
     * @return list<string>
     */
    private static function padBlock(array $block, int $target, string $valign): array
    {
        $missing = $target - count($block);
        if ($missing <= 0) {
            return $block;
        }
        $top    = match ($valign) {
            'middle' => intdiv($missing, 2),
            'bottom' => $missing,
            default  => 0,
        };
        $bottom = $missing - $top;
        return array_merge(
            array_fill(0, $top, ''),
            $block,
            array_fill(0, $bottom, ''),
        );
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
