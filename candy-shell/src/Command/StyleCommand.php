<?php

declare(strict_types=1);

namespace CandyCore\Shell\Command;

use CandyCore\Shell\Style\StyleBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Apply Sprinkles styling to its argument text (or stdin when no
 * arguments are given) and print the result.
 *
 * Non-interactive — no Program loop required.
 */
#[AsCommand(name: 'style', description: 'Apply Sprinkles styling to text and print it.')]
final class StyleCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('text', InputArgument::IS_ARRAY, 'Text to style. Empty = read from stdin.')
            ->addOption('foreground',         null, InputOption::VALUE_REQUIRED, 'Foreground colour (hex / 0-15 / 0-255).')
            ->addOption('background',         null, InputOption::VALUE_REQUIRED, 'Background colour.')
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
            ->addOption('trim',               null, InputOption::VALUE_NONE,     'Trim trailing whitespace from each line.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $argv = $input->getArgument('text') ?: [];
        $text = $argv === [] ? self::readStdin() : implode(' ', $argv);

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
            'width'              => $input->getOption('width'),
            'height'             => $input->getOption('height'),
            'align'              => $input->getOption('align'),
            'border'             => $input->getOption('border'),
            'border-foreground'  => $input->getOption('border-foreground'),
            'border-background'  => $input->getOption('border-background'),
        ]);

        $rendered = $style->render($text);
        if ($input->getOption('trim')) {
            $rendered = implode("\n", array_map(static fn(string $l) => rtrim($l), explode("\n", $rendered)));
        }
        $output->writeln($rendered);
        return Command::SUCCESS;
    }

    private static function readStdin(): string
    {
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
