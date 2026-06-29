<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Command;

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Shell\Model\PagerModel;
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
            ->addOption('file',               'f',  InputOption::VALUE_REQUIRED, 'Input file. Default: stdin.')
            ->addOption('width',              null, InputOption::VALUE_REQUIRED, 'Pager width.',  80)
            ->addOption('height',             null, InputOption::VALUE_REQUIRED, 'Pager height.', 20)
            ->addOption('show-line-numbers',  null, InputOption::VALUE_NONE,     'Prefix every line with a 1-based line number.')
            ->addOption('match',              null, InputOption::VALUE_REQUIRED, 'Highlight every case-insensitive occurrence of the substring.', '')
            ->addOption('soft-wrap',          null, InputOption::VALUE_NONE,     'Wrap long lines at the pager width instead of clipping.')
            ->addOption('show-help',          null, InputOption::VALUE_NONE,     'Alias for --help (gum compat).')
            ->addOption('timeout',            null, InputOption::VALUE_REQUIRED, 'Auto-exit after N seconds (0 = none).', 0);
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

        // --soft-wrap pre-wraps lines at the pager width before
        // feeding the viewport, so long source lines stay visible
        // without horizontal scroll.
        if ((bool) $input->getOption('soft-wrap')) {
            $width = (int) $input->getOption('width');
            $raw = \SugarCraft\Core\Util\Width::wrap($raw, max(1, $width));
        }

        $model   = PagerModel::fromContent(
            $raw,
            (int)    $input->getOption('width'),
            (int)    $input->getOption('height'),
            (bool)   $input->getOption('show-line-numbers'),
            (string) $input->getOption('match'),
        );
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
