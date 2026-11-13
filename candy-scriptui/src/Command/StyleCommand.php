<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use SugarCraft\ScriptUi\Style\StyleBuilder;
use SugarCraft\ScriptUi\Runtime\PositiveInt;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Attribute\Example;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Apply ANSI styling to its argument text (or stdin when no
 * arguments are given) and print the result.
 *
 * Non-interactive — no Program loop required.
 */
#[Example('candy-scriptui style --bold --foreground 213 hi', 'Bold pink text.')]
#[AsCommand(name: 'style', description: 'Apply ANSI styling to text and print it.')]
final class StyleCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('text', InputArgument::IS_ARRAY, 'Text to style. Empty = read from stdin.')
            ->addOption('foreground',         null, InputOption::VALUE_REQUIRED, 'Foreground colour (name / hex / 0-255).')
            ->addOption('background',         null, InputOption::VALUE_REQUIRED, 'Background colour (name / hex / 0-255).')
            ->addOption('bold',               null, InputOption::VALUE_NONE,     'Bold.')
            ->addOption('italic',             null, InputOption::VALUE_NONE,     'Italic.')
            ->addOption('underline',          null, InputOption::VALUE_NONE,     'Underline.')
            ->addOption('strikethrough',      null, InputOption::VALUE_NONE,     'Strikethrough.')
            ->addOption('faint',              null, InputOption::VALUE_NONE,     'Faint.')
            ->addOption('padding',            null, InputOption::VALUE_REQUIRED, 'Padding (1, 2, or 4 ints).')
            ->addOption('margin',             null, InputOption::VALUE_REQUIRED, 'Margin (1, 2, or 4 ints).')
            ->addOption('width',              null, InputOption::VALUE_REQUIRED, 'Fixed width (cells).')
            ->addOption('height',             null, InputOption::VALUE_REQUIRED, 'Fixed height (rows).')
            ->addOption('align',              null, InputOption::VALUE_REQUIRED, 'Horizontal alignment: left|center|right.')
            ->addOption('border',             null, InputOption::VALUE_REQUIRED, 'Border preset: normal|rounded|thick|double|block|hidden.')
            ->addOption('border-foreground',  null, InputOption::VALUE_REQUIRED, 'Border foreground colour.')
            ->addOption('border-background',  null, InputOption::VALUE_REQUIRED, 'Border background colour.')
            ->addOption('trim',               null, InputOption::VALUE_NONE,     'Trim trailing whitespace from each line.')
            ->addOption('strip-ansi',         null, InputOption::VALUE_NONE,     'Strip ANSI escapes from input before styling.')
            ->addOption('show-help',          null, InputOption::VALUE_NONE,     'Alias for --help (gum compat).')
            ->addOption('timeout',            null, InputOption::VALUE_REQUIRED, 'Auto-abort after N seconds (0 = none, no-op for non-interactive style).', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $argv = $input->getArgument('text') ?: [];
        $text = $argv === [] ? self::readStdin() : implode(' ', $argv);

        // --strip-ansi clears any inline SGR sequences before applying
        // styling, mirroring gum's behaviour for piped output.
        if ($input->getOption('strip-ansi')) {
            $text = \SugarCraft\Core\Util\Ansi::strip($text);
        }

        // An unknown border preset used to fall through to a silent
        // no-border render (exit 0, no decoration) while the sibling
        // `table` command refuses the same spelling. Refuse loudly now.
        $borderRaw = strtolower((string) $input->getOption('border'));
        if ($borderRaw !== '' && !in_array($borderRaw, ['normal', '1', 'rounded', 'thick', 'double', 'block', 'hidden', '0', 'none'], true)) {
            fwrite(STDERR, Lang::t('runtime.unknown_choice', [
                'option' => '--border',
                'value' => (string) $input->getOption('border'),
                'allowed' => 'normal|rounded|thick|double|block|hidden',
            ]) . "\n");

            return Command::FAILURE;
        }

        // StyleBuilder fails closed on unparseable values (bad colours,
        // non-numeric padding) with InvalidArgumentException. Unguarded,
        // those escaped as an uncaught trace (or, through the top-level
        // Symfony renderer, a formatted error block on the caller's
        // stream). Refuse like the --border door above: the translated
        // message goes to STDERR as diagnostics, the data stream stays
        // empty, exit is FAILURE.
        try {
            $style = StyleBuilder::fromFlags([
                'foreground'         => $input->getOption('foreground'),
                'background'         => $input->getOption('background'),
                'bold'               => $input->getOption('bold'),
                'italic'             => $input->getOption('italic'),
                'underline'          => $input->getOption('underline'),
                'strikethrough'      => $input->getOption('strikethrough'),
                'faint'              => $input->getOption('faint'),
                'padding'            => $input->getOption('padding'),
                'margin'             => $input->getOption('margin'),
                'width'              => PositiveInt::optional('width', $input->getOption('width')),
                'height'             => PositiveInt::optional('height', $input->getOption('height')),
                'align'              => $input->getOption('align'),
                'border'             => $input->getOption('border'),
                'border-foreground'  => $input->getOption('border-foreground'),
                'border-background'  => $input->getOption('border-background'),
            ]);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return Command::FAILURE;
        }

        $rendered = $style->render($text);
        if ($input->getOption('trim')) {
            $rendered = implode("\n", array_map(static fn(string $l) => rtrim($l), explode("\n", $rendered)));
        }
        // LF-pinned trailer: write(..., true) emits PHP_EOL (CRLF on Windows).
        $output->write($rendered . "\n", false, OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }

    private static function readStdin(): string
    {
        // From a real terminal with no piped data this would block forever;
        // an empty stdin is the honest answer (same guard as pager/log/table).
        if (!defined('STDIN') || !is_resource(STDIN) || @stream_isatty(STDIN)) {
            return '';
        }
        $data = '';
        while (!feof(STDIN)) {
            $chunk = fread(STDIN, 4096);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
        }
        return rtrim($data, "\n");
    }
}
