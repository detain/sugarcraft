<?php

declare(strict_types=1);

namespace CandyCore\Glow;

use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Core\Util\Tty;
use CandyCore\Shine\Renderer;
use CandyCore\Shine\Theme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Default `sugarglow` command. Reads Markdown from a file argument or
 * stdin, renders it via {@see Renderer}, and either prints it to the
 * terminal (default) or opens a fullscreen pager via {@see GlowModel}
 * when `-p` / `--pager` is set.
 */
#[AsCommand(name: 'render', description: 'Render Markdown and print or page it.')]
final class RenderCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('file',  InputArgument::OPTIONAL, 'Markdown file. Default: stdin.')
            ->addOption('pager', 'p', InputOption::VALUE_NONE,     'Open the rendered output in a fullscreen pager.')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'ansi | plain', 'ansi');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = self::loadInput((string) ($input->getArgument('file') ?? ''));
        if ($raw === null) {
            $output->writeln('<error>no input</error>');
            return Command::FAILURE;
        }

        $theme    = self::pickTheme((string) $input->getOption('theme'));
        $rendered = (new Renderer($theme))->render($raw);

        if (!$input->getOption('pager')) {
            $output->writeln($rendered);
            return Command::SUCCESS;
        }

        // Pager mode: drop into a Program with a Viewport-backed Model.
        $size  = (new Tty())->size();
        $model = GlowModel::fromContent($rendered, $size['cols'], $size['rows']);
        $program = new Program($model, new ProgramOptions(
            useAltScreen:    true,
            hideCursor:      true,
            catchInterrupts: true,
        ));
        $program->run();
        return Command::SUCCESS;
    }

    public static function pickTheme(string $name): Theme
    {
        return match (strtolower($name)) {
            'ansi'        => Theme::ansi(),
            'plain', 'no' => Theme::plain(),
            default       => throw new \InvalidArgumentException("unknown theme: $name"),
        };
    }

    /** @return string|null */
    public static function loadInput(string $file): ?string
    {
        if ($file !== '') {
            $contents = @file_get_contents($file);
            return is_string($contents) ? $contents : null;
        }
        if (!defined('STDIN') || !is_resource(STDIN) || @stream_isatty(STDIN)) {
            return null;
        }
        $raw = stream_get_contents(STDIN);
        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
