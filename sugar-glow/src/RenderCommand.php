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
            ->addOption('theme',         null, InputOption::VALUE_REQUIRED, 'ansi | plain | dark | light | notty | dracula | tokyo-night | pink | solarized | monokai | github', 'ansi')
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

        // Determine whether the user explicitly chose a theme (vs. accepting the 'ansi' default).
        // 'ansi' default from configure() means "no explicit choice" — auto-downgrade when
        // the terminal has no color capability.
        $explicitTheme = $configPath !== ''
            || $input->getOption('style') !== null
            || (string) $input->getOption('theme') !== 'ansi';

        if ($configPath !== '') {
            $theme = Theme::fromJson($configPath);
        } elseif (!$explicitTheme && !self::terminalSupportsColor()) {
            // No explicit theme AND terminal cannot render color → use notty.
            $theme = Theme::notty();
        } else {
            $theme = self::pickTheme($themeName);
        }

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
     * Honors NO_COLOR and CLICOLOR=0 env vars before probing.
     * Falls back to true (assume color capable) on any probe error
     * so we never incorrectly refuse input due to a broken probe.
     */
    private static function terminalSupportsColor(): bool
    {
        try {
            if (self::$colorProbeCallback !== null) {
                return (self::$colorProbeCallback)();
            }
            // Honor standard color-disabling env vars before probing the terminal.
            if (getenv('NO_COLOR') !== '' || getenv('CLICOLOR') === '0') {
                return false;
            }
            $report = TerminalProbe::run();
            return !$report->has(Capability::NoColor);
        } catch (\Throwable) {
            return true; // Fall back to assuming color is available.
        }
    }

    /**
     * @param resource|null $stream Defaults to STDIN; allows unit-testing stdin paths.
     */
    public static function loadInput(string $file, $stream = null): ?string
    {
        if ($file !== '') {
            $contents = @file_get_contents($file);
            return is_string($contents) ? $contents : null;
        }
        $stream = $stream ?? STDIN;
        if (!defined('STDIN') || !is_resource($stream) || TtyDetect::isAtty($stream)) {
            return null;
        }
        // Color decision now lives entirely in execute(); loadInput just reads stdin.
        $raw = stream_get_contents($stream);
        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
