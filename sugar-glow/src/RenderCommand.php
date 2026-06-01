<?php

declare(strict_types=1);

namespace SugarCraft\Glow;

use SugarCraft\Core\Util\TtyDetect;
use SugarCraft\Glow\Lang;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Util\Tty;
use SugarCraft\Palette\Probe\Capability;
use SugarCraft\Palette\Probe\TerminalProbe;
use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;
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
    /** @var callable|null */
    private static $colorProbeCallback = null;

    /** @deprecated test seam only */
    public static function setColorProbeCallback(?callable $callback): void
    {
        self::$colorProbeCallback = $callback;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file',     InputArgument::OPTIONAL, 'Markdown file. Default: stdin.')
            ->addOption('pager',         'p', InputOption::VALUE_NONE,     'Open the rendered output in a fullscreen pager.')
            ->addOption('theme',         null, InputOption::VALUE_REQUIRED, 'ansi | plain | dark | light | notty | dracula | tokyo-night | pink', 'ansi')
            ->addOption('style',         's',  InputOption::VALUE_REQUIRED, 'Alias for --theme (glamour-compat).', null)
            ->addOption('theme-config',  null, InputOption::VALUE_REQUIRED, 'Load a custom JSON theme file (overrides --theme).', '')
            ->addOption('width',         'w',  InputOption::VALUE_REQUIRED, 'Wrap text at this column count. 0 = no wrap.', 0)
            ->addOption('no-hyperlinks', null, InputOption::VALUE_NONE,    'Disable OSC 8 hyperlinks; render links as text + (url) instead.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = self::loadInput((string) ($input->getArgument('file') ?? ''));
        if ($raw === null) {
            $output->writeln('<error>no input</error>');
            return Command::FAILURE;
        }

        // Theme selection: --theme-config (JSON) wins over --theme/--style.
        $configPath = (string) $input->getOption('theme-config');
        $themeName  = (string) ($input->getOption('style') ?? $input->getOption('theme'));
        $theme      = $configPath !== ''
            ? Theme::fromJson($configPath)
            : self::pickTheme($themeName);

        $width      = (int) $input->getOption('width');
        $renderer   = (new Renderer($theme))
            ->withWordWrap($width > 0 ? $width : null)
            ->withHyperlinks(!$input->getOption('no-hyperlinks'));
        $rendered   = $renderer->render($raw);

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
        return match (strtolower(str_replace('_', '-', $name))) {
            '', 'ansi'         => Theme::ansi(),
            'plain', 'no'      => Theme::plain(),
            'dark'             => Theme::dark(),
            'light'            => Theme::light(),
            'notty', 'auto-no' => Theme::notty(),
            'dracula'          => Theme::dracula(),
            'tokyo-night',
            'tokyonight'       => Theme::tokyoNight(),
            'pink'             => Theme::pink(),
            'solarized'        => Theme::fromJson(__DIR__ . '/../themes/solarized.json'),
            'monokai'          => Theme::fromJson(__DIR__ . '/../themes/monokai.json'),
            'github'           => Theme::fromJson(__DIR__ . '/../themes/github.json'),
            default            => throw new \InvalidArgumentException(Lang::t('render.unknown_theme', ['name' => $name])),
        };
    }

    /**
     * Probe the terminal for ANSI color capability.
     *
     * Falls back to true (assume color capable) on any probe error
     * so we never incorrectly refuse input due to a broken probe.
     */
    private static function terminalSupportsColor(): bool
    {
        try {
            if (self::$colorProbeCallback !== null) {
                return (self::$colorProbeCallback)();
            }
            $report = TerminalProbe::run();
            return !$report->has(Capability::NoColor);
        } catch (\Throwable) {
            return true; // Fall back to assuming color is available.
        }
    }

    /** @return string|null */
    public static function loadInput(string $file): ?string
    {
        if ($file !== '') {
            $contents = @file_get_contents($file);
            return is_string($contents) ? $contents : null;
        }
        if (!defined('STDIN') || !is_resource(STDIN) || TtyDetect::isAtty(STDIN)) {
            return null;
        }
        // Guard: if the terminal doesn't support color (NO_COLOR set or
        // no-color capability detected), still read stdin but the caller
        // will render with a non-color theme.  On probe failure, assume
        // color is available (graceful degradation per step-29 brief).
        if (!self::terminalSupportsColor()) {
            $raw = stream_get_contents(STDIN);
            return is_string($raw) && $raw !== '' ? $raw : null;
        }
        $raw = stream_get_contents(STDIN);
        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
