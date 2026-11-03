<?php

declare(strict_types=1);

namespace SugarCraft\MdView;

use SugarCraft\Core\Util\TtyDetect;
use SugarCraft\MdView\Lang;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Util\Tty;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\TerminalProbe;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Default `candy-mdview` command. Reads Markdown from a file argument or
 * stdin, renders it via {@see Renderer}, and either prints it to the
 * terminal (default) or opens a fullscreen pager via {@see MdViewModel}
 * when `-p` / `--pager` is set.
 */
#[AsCommand(name: 'render', description: 'Render Markdown and print or page it.')]
final class RenderCommand extends Command
{
    /**
     * Hard cap on `--theme-config` bytes read before the JSON decode. A
     * theme config is a few KB in practice; this ceiling stops a malicious
     * or runaway file (or an attacker-controlled path) from being slurped
     * whole into memory. Mirrors sugar-markdown's MAX_HIGHLIGHT_BYTES.
     */
    private const MAX_THEME_CONFIG_BYTES = 1_000_000;

    /** @var callable|null */
    private static $colorProbeCallback = null;

    /** @var callable|null */
    private static $terminalCheckCallback = null;

    /** @deprecated test seam only */
    public static function setColorProbeCallback(?callable $callback): void
    {
        self::$colorProbeCallback = $callback;
    }

    /**
     * @deprecated test seam only — the terminal check is the other half of
     * the auto-theme decision and was previously unpinable.
     */
    public static function setTerminalCheckCallback(?callable $callback): void
    {
        self::$terminalCheckCallback = $callback;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file',     InputArgument::OPTIONAL, 'Markdown file. Default: stdin.')
            ->addOption('pager',         'p', InputOption::VALUE_NONE,     'Open the rendered output in a fullscreen pager.')
            ->addOption('theme',         null, InputOption::VALUE_REQUIRED, 'ansi | ascii | plain | dark | light | notty | dracula | tokyo-night | pink | solarized | monokai | github. Aliases: no, auto-no, tokyonight. Default (omitted): ansi on a color-capable terminal, ascii when color is off, plain when piped.', null)
            ->addOption('style',         's',  InputOption::VALUE_REQUIRED, 'Alias for --theme (glamour-compat).', null)
            ->addOption('theme-config',  null, InputOption::VALUE_REQUIRED, 'Load a custom JSON theme file (overrides --theme).', '')
            ->addOption('width',         'w',  InputOption::VALUE_REQUIRED, 'Wrap text at this column count. 0 = no wrap.', 0)
            ->addOption('no-hyperlinks', null, InputOption::VALUE_NONE,    'Disable OSC 8 hyperlinks; render links as text + (url) instead.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fileArg = (string) ($input->getArgument('file') ?? '');
        $raw = self::loadInput($fileArg);
        if ($raw === null) {
            $output->writeln('<error>no input</error>');
            return Command::FAILURE;
        }

        // Theme selection: --theme-config (JSON) wins over --theme/--style.
        $configPath = (string) $input->getOption('theme-config');
        $themeName  = (string) ($input->getOption('style') ?? $input->getOption('theme') ?? 'ansi');

        // An explicit choice means the user NAMED a theme. The --theme default
        // is null precisely so that `--theme ansi` counts as a choice: 'ansi'
        // names a real theme and must render its SGR even where the implicit
        // default would downgrade.
        $explicitTheme = $configPath !== ''
            || $input->getOption('style') !== null
            || $input->getOption('theme') !== null;

        // Explicit --theme-config/--theme/--style always wins; otherwise the
        // tier chain below decides (KM8: the option default is null precisely
        // so `--theme ansi` is recognised as a choice, not "no choice").
        $onTerminal = self::decoratedOutput($input, $output);

        if ($configPath !== '') {
            if (is_readable($configPath) === false) {
                throw new \InvalidArgumentException(Lang::t('render.theme_config_unreadable', ['path' => $configPath]));
            }
            $theme = self::loadThemeConfig($configPath);
        } elseif ($explicitTheme === false) {
            // Implicit choice, three tiers: a pipe/--no-ansi stream gets raw
            // bytes (plain); a real terminal that cannot color gets the ASCII
            // theme (bold/underline survive, hue does not — fleet convention
            // maps color-off to the ASCII tier); otherwise ansi.
            $theme = match (true) {
                $onTerminal === false => Theme::plain(),
                self::terminalSupportsColor() => Theme::ansi(),
                default => Theme::ascii(),
            };
        } else {
            $theme = self::pickTheme($themeName);
        }

        $width      = (int) $input->getOption('width');
        $renderer   = (new Renderer($theme))
            ->withWordWrap($width > 0 ? $width : null)
            // OSC 8 envelopes are a terminal capability: never emit them into
            // a pipe or file, and never over an explicit --no-hyperlinks.
            ->withHyperlinks($onTerminal && $input->getOption('no-hyperlinks') === false);
        $rendered   = $renderer->render($raw);

        if ($input->getOption('pager') === false) {
            // OUTPUT_RAW: the rendered document is DATA, never markup. Passing
            // it through the formatter would EAT <info>/<href=…> tags —
            // corrupting the displayed text and letting hostile inline HTML in
            // a document smuggle live OSC 8 links past sugar-markdown's
            // refused-scheme gate. The newline is appended only when the
            // render lacks one, so redirection never gains a stray blank line.
            $output->write($rendered, str_ends_with($rendered, "\n") === false, OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        // Pager mode: drop into a Program with a Viewport-backed Model.
        // A file argument also arms auto-reload (E735 step 10.25): the pager
        // re-reads and re-renders the SAME file through the SAME renderer —
        // theme, width and hyperlink choices survive every reload frame.
        // Stdin input has nothing to watch and never arms the pump.
        $size  = (new Tty())->size();
        $model = self::buildPagerModel($rendered, $size['cols'], $size['rows']);
        if ($fileArg !== '') {
            $model = self::armPagerWatch($model, $fileArg, $renderer);
        }
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
            'ascii'            => Theme::ascii(),
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
     * Should this stream receive terminal decoration (SGR, OSC 8)?
     *
     * --no-ansi vetoes, --ansi asserts, CLICOLOR_FORCE/FORCE_COLOR decorate
     * even a pipe (house ladder step 5). Otherwise a plain `stream_isatty`
     * answers — deliberately NOT {@see OutputInterface::isDecorated()},
     * because Symfony folds NO_COLOR and TERM=dumb into decoration (see
     * StreamOutput::hasColorSupport), which would collapse the color
     * question into the terminal question and cost a NO_COLOR user even
     * their bold text.
     */
    private static function decoratedOutput(InputInterface $input, OutputInterface $output): bool
    {
        if ($input->hasOption('no-ansi') && $input->getOption('no-ansi') === true) {
            return false;
        }
        if ($input->hasOption('ansi') && $input->getOption('ansi') === true) {
            return true;
        }
        if (self::colorDecisionFromEnvironment() === true) {
            return true; // *FORCE means decoration regardless of stream kind.
        }
        // Symfony's isDecorated() is false under NO_COLOR/TERM=dumb on a real
        // tty; stream_isatty() keeps the terminal question separate, while a
        // decorated-but-unclaimed stream (--ansi handled upstream, wrappers)
        // still counts as decoration-capable.
        return self::stdoutIsTerminal() === true || $output->isDecorated() === true;
    }

    /** Raw stdout tty-ness, behind a test seam (the sibling of the color-probe seam). */
    private static function stdoutIsTerminal(): bool
    {
        if (self::$terminalCheckCallback !== null) {
            return (bool) (self::$terminalCheckCallback)();
        }
        return defined('STDOUT') === true && @stream_isatty(STDOUT) === true;
    }

    /**
     * The standard color environment conventions, decided BEFORE any probe.
     *
     * Returns true (force color), false (refuse color), or null (the
     * environment has no opinion — consult the terminal probe).
     *
     * NO_COLOR follows the no-color.org spec: set to ANY non-empty value —
     * including "0" — disables. Unset {@see getenv()} returns FALSE, not the
     * empty string, so the classic `!== ''` comparison reads "unset" as
     * "disable" and silently inverts the whole chain; that inversion is the
     * reason the shipped default rendered monochrome for every user.
     * CLICOLOR=0 disables (BSD convention). CLICOLOR_FORCE=1 / FORCE_COLOR=1
     * re-enable over everything, mirrors sugar-core ColorProfile::detect().
     */
    private static function colorDecisionFromEnvironment(): ?bool
    {
        foreach (['CLICOLOR_FORCE', 'FORCE_COLOR'] as $forceVar) {
            $force = getenv($forceVar);
            if ($force !== false && $force !== '' && $force !== '0') {
                return true;
            }
        }
        $noColor = getenv('NO_COLOR');
        if ($noColor !== false && $noColor !== '') {
            return false;
        }
        if (getenv('CLICOLOR') === '0') {
            return false;
        }
        return null;
    }

    /**
     * Probe the terminal for ANSI color capability.
     *
     * Order: test seam, then the environment verdict (NO_COLOR / CLICOLOR=0 /
     * *FORCE), then the sugar-colorprofile terminfo probe. TERM=dumb reaches
     * the probe and reports NoColor there. Falls back to true (assume color
     * capable) on any probe error so we never lose output to a broken probe.
     */
    private static function terminalSupportsColor(): bool
    {
        try {
            if (self::$colorProbeCallback !== null) {
                return (self::$colorProbeCallback)();
            }
            $verdict = self::colorDecisionFromEnvironment();
            if ($verdict !== null) {
                return $verdict;
            }
            // An EMPTY NO_COLOR takes no position (no-color.org: "if present
            // and not empty"), but sugar-colorprofile's probe disables on ANY
            // value including empty. Hide the empty value from the probe so
            // the spec-correct "no opinion" answer reaches it, then restore.
            $noColor = getenv('NO_COLOR');
            if ($noColor === false || $noColor !== '') {
                $report = TerminalProbe::run();
                return !$report->has(Capability::NoColor);
            }
            putenv('NO_COLOR');
            try {
                $report = TerminalProbe::run();
                return !$report->has(Capability::NoColor);
            } finally {
                putenv('NO_COLOR=');
            }
        } catch (\Throwable) {
            return true; // Fall back to assuming color is available.
        }
    }

    /**
     * @param resource|null $stream Defaults to STDIN; allows unit-testing stdin paths.
     *
     * @return string|null Returns null when:
     *   - file path provided but file does not exist or is unreadable
     *   - no file path and STDIN is a TTY (interactive terminal)
     *   - no file path and STDIN is empty
     * Note: It is not possible to distinguish these failure modes from the return value.
     */
    public static function loadInput(string $file, $stream = null): ?string
    {
        if ($file !== '') {
            $contents = @file_get_contents($file);
            return is_string($contents) ? $contents : null;
        }
        $stream = $stream ?? STDIN;
        if (defined('STDIN') === false || is_resource($stream) === false || TtyDetect::isAtty($stream) === true) {
            return null;
        }
        // Color decision now lives entirely in execute(); loadInput just reads stdin.
        $raw = stream_get_contents($stream);
        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * Read a `--theme-config` JSON file under a byte cap, then decode it.
     *
     * {@see Theme::fromJson()} slurps the whole file unbounded; reading at
     * most {@see self::MAX_THEME_CONFIG_BYTES}+1 bytes here bounds memory and
     * rejects an oversized config instead of letting it through to the decoder.
     *
     * Two JSON schemas are accepted (E735 step 10.25): a top-level
     * `document` OBJECT marks a glamour style file and routes through
     * {@see GlamourTheme::toMarkdownTheme()} via the sniff below; anything else
     * keeps the historic flat per-element shape. The signatures cannot
     * collide — the flat schema has no `document` key.
     */
    private static function loadThemeConfig(string $path): Theme
    {
        $raw = @file_get_contents($path, false, null, 0, self::MAX_THEME_CONFIG_BYTES + 1);
        if ($raw === false) {
            throw new \InvalidArgumentException(Lang::t('render.theme_config_unreadable', ['path' => $path]));
        }
        if (strlen($raw) > self::MAX_THEME_CONFIG_BYTES) {
            throw new \InvalidArgumentException(Lang::t('render.theme_config_too_large', [
                'path'  => $path,
                'limit' => self::MAX_THEME_CONFIG_BYTES,
            ]));
        }

        $decoded = json_decode($raw, associative: true);
        if (is_array($decoded) && GlamourTheme::isGlamourSchema($decoded)) {
            return GlamourTheme::fromDecoded($decoded)->toMarkdownTheme();
        }

        return Theme::fromJsonString($raw);
    }

    /**
     * Build the MdViewModel for pager mode from rendered content and terminal size.
     */
    private static function buildPagerModel(string $rendered, int $cols, int $rows): MdViewModel
    {
        return MdViewModel::fromContent($rendered, $cols, $rows);
    }

    /**
     * Arm the pager's file-watch pump (E735 step 10.25). The reload closure
     * re-renders fresh Markdown with the live {@see Renderer}, so a save
     * while paging shows the updated document with identical styling.
     */
    private static function armPagerWatch(MdViewModel $model, string $path, Renderer $renderer): MdViewModel
    {
        return $model->withWatch($path, static fn(string $markdown): string => $renderer->render($markdown));
    }
}
