<?php

declare(strict_types=1);

namespace SugarCraft\MdView;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\TtyDetect;
use SugarCraft\MdView\Lang;
use SugarCraft\Core\MouseMode;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Util\Tty;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\TerminalProbe;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Default `candy-mdview` command. Reads Markdown from a file argument or
 * stdin, renders it via {@see Renderer}, and either prints it to the
 * terminal (default) or opens a fullscreen pager via {@see MdViewModel}
 * when `-p` / `--pager` is set.
 *
 * Failure contract: usage faults (bad flag value, unreadable/directory/
 * binary/oversized input, unknown theme) exit {@see Command::INVALID} (2)
 * with a named message on STDERR; the process never writes an error into
 * a document stream. A successfully read document goes to STDOUT raw.
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
    private const MAX_THEME_CONFIG_BYTES = GlamourTheme::MAX_CONFIG_BYTES;

    /** @var callable|null */
    private static $colorProbeCallback = null;

    /** @var callable|null */
    private static $terminalCheckCallback = null;

    /** @var callable|null */
    private static $stdinTtyCallback = null;

    /**
     * @deprecated test seam only — see the note at setStdinTtyCallback().
     *
     * @var callable|null
     */
    private static $pagerDevTtyCallback = null;

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

    /**
     * @deprecated test seam only — decides whether STDIN is an interactive
     * terminal, the branch between "no input" messages.
     */
    public static function setStdinTtyCallback(?callable $callback): void
    {
        self::$stdinTtyCallback = $callback;
    }

    /**
     * @deprecated test seam only — decides the /dev/tty fallback of the
     * pager-surface guard without probing the real controlling terminal.
     */
    public static function setPagerDevTtyCallback(?callable $callback): void
    {
        self::$pagerDevTtyCallback = $callback;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file',     InputArgument::OPTIONAL, 'Markdown file. Default: stdin.')
            ->addOption('pager',         'p', InputOption::VALUE_NONE,     'Open the rendered output in a fullscreen pager.')
            ->addOption('theme',         null, InputOption::VALUE_REQUIRED, 'ansi | ascii | plain | dark | light | notty | dracula | tokyo-night | pink | solarized | monokai | github. Aliases: no, auto-no, tokyonight. Default (omitted): ansi on a color-capable terminal, ascii when color is off, plain when the stream is undecorated (--no-ansi, or a non-terminal without forced decoration).', null)
            ->addOption('style',         's',  InputOption::VALUE_REQUIRED, 'Alias for --theme (glamour-compat).', null)
            ->addOption('theme-config',  null, InputOption::VALUE_REQUIRED, 'Load a custom JSON theme file (overrides --theme).', '')
            ->addOption('width',         'w',  InputOption::VALUE_REQUIRED, 'Wrap text at this column count (1-9999). 0 = no wrap: longer lines are truncated by the pager, not wrapped.', 0)
            ->addOption('no-hyperlinks', null, InputOption::VALUE_NONE,    'Disable OSC 8 hyperlinks; render links as text + (url) instead.')
            ->setHelp(<<<'TXT'
                Pager keys (-p / --pager):
                  j / Down, k / Up        scroll one line
                  space, f / PageDown     scroll one page    b / PageUp   back a page
                  g / Home, G / End       jump to top / bottom
                  Ctrl+U / Ctrl+D         half page up / down
                  mouse wheel             3 lines per notch, either direction
                  h / Left, l / Right     scroll long lines sideways
                  r                       force a reload of the watched file
                  q, Esc, Ctrl+C          quit (q only unmodified)

                Exit codes:
                  0   rendered successfully
                  1   generic failure
                  2   usage error (bad flag, missing/unreadable/directory/binary input)

                Environment:
                  NO_COLOR           any non-empty value disables color (bold survives in ascii mode)
                  CLICOLOR=0         disables color (BSD convention)
                  CLICOLOR_FORCE     any non-empty value other than 0 forces color on (pipes included)
                  FORCE_COLOR        any non-empty value other than 0 forces color on (pipes included)
                  TERM=dumb          treated as colorless
                TXT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fileArg = (string) ($input->getArgument('file') ?? '');

        // Usage faults are decided BEFORE anything is rendered and leave via
        // STDERR with Command::INVALID: `--theme=ZORG missingfile.md` names
        // the theme (the flag was the first thing wrong), and an error never
        // lands inside a redirected document stream.
        try {
            $width      = self::resolveWidth($input);
            $onTerminal = self::decoratedOutput($input, $output);
            $theme      = self::resolveTheme($input, $onTerminal);
            $raw        = self::readDocument($fileArg);
            if ($input->getOption('pager') === true) {
                // Refuse before any painting: the escape-soup capture and
                // the no-terminal hang both happen INSIDE the Program, so
                // the surface question is answered up front like every
                // mainstream pager answers it.
                self::requirePagerSurface();
            }
        } catch (\InvalidArgumentException $usage) {
            self::writeError($output, $usage->getMessage());
            return Command::INVALID;
        }

        $renderer = (new Renderer($theme))
            ->withWordWrap($width > 0 ? $width : null)
            // The sanitizer is pinned ON at the construction site (the
            // explicit opposite of the opt-out switch) instead of riding the
            // constructor default: stripControls is the render-safety NET
            // under the DocumentGate's contract, and that dependency must
            // not hinge on a default a future release could flip. The gate
            // already refused the bytes that matter.
            ->withRawControlChars(false)
            // OSC 8 envelopes ride the decoration decision: skipped on an
            // undecorated stream and over an explicit --no-hyperlinks, but
            // forced decoration (--ansi / CLICOLOR_FORCE / FORCE_COLOR) emits
            // them even into a pipe or file — forcing decoration is forcing
            // all of it.
            ->withHyperlinks($onTerminal && $input->getOption('no-hyperlinks') === false);
        $rendered = $renderer->render($raw);

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
        // A file argument also arms auto-reload AND a status header: the
        // pager re-reads and re-renders the SAME file through the SAME
        // renderer — theme, width and hyperlink choices survive every
        // reload frame. Stdin input has nothing to watch and never arms
        // the pump. openTty re-opens /dev/tty when STDIN was a pipe, so
        // the keyboard lives even for `git log | candy-mdview -p`.
        $size  = (new Tty())->size();
        $model = self::buildPagerModel($rendered, $size['cols'], $size['rows'], $fileArg === '' ? '' : basename($fileArg));
        if ($fileArg !== '') {
            $model = self::armPagerWatch($model, $fileArg, $renderer);
        }
        $program = new Program($model, new ProgramOptions(
            useAltScreen:    true,
            hideCursor:      true,
            catchInterrupts: true,
            mouseMode:       MouseMode::CellMotion,
            openTty:         true,
        ));
        $program->run();
        return Command::SUCCESS;
    }

    /**
     * `--width` must be a non-negative integer up to four digits. The old
     * `(int)` cast turned `--width=abc` and negatives into a silent 0
     * (= no wrap), so a typo quietly changed layout instead of refusing.
     */
    private static function resolveWidth(InputInterface $input): int
    {
        $value = (string) ($input->getOption('width') ?? '0');
        if (preg_match('/^\d{1,4}$/', $value) !== 1) {
            throw new \InvalidArgumentException(Lang::t('render.width_invalid', ['value' => $value]));
        }
        return (int) $value;
    }

    /**
     * Theme selection: --theme-config (JSON) wins over --theme/--style.
     *
     * An explicit choice means the user NAMED a theme. The --theme default
     * is null precisely so that `--theme ansi` counts as a choice: 'ansi'
     * names a real theme and must render its SGR even where the implicit
     * default would downgrade.
     */
    private static function resolveTheme(InputInterface $input, bool $onTerminal): Theme
    {
        $configPath = (string) $input->getOption('theme-config');
        $style      = $input->getOption('style');
        $theme      = $input->getOption('theme');

        if ($configPath !== '') {
            if (is_readable($configPath) === false) {
                throw new \InvalidArgumentException(Lang::t('render.theme_config_unreadable', ['path' => $configPath]));
            }
            return self::loadThemeConfig($configPath);
        }

        $explicitTheme = $style !== null || $theme !== null;
        if ($explicitTheme === false) {
            // Implicit choice, three tiers: a pipe/--no-ansi stream gets raw
            // bytes (plain); a real terminal that cannot color gets the ASCII
            // theme (bold/underline survive, hue does not — fleet convention
            // maps color-off to the ASCII tier); otherwise ansi.
            return match (true) {
                $onTerminal === false => Theme::plain(),
                self::terminalSupportsColor() => Theme::ansi(),
                default => Theme::ascii(),
            };
        }

        return self::pickTheme((string) ($style ?? $theme));
    }

    /**
     * Read the document for this run, naming every failure. A directory or
     * missing path used to collapse into the same one-word "no input" (and
     * a directory once rendered as an empty document with a SUCCESS code —
     * an exit-code lie); binary payloads used to vomit raw control bytes
     * onto the terminal.
     *
     * @param resource|null $pipedStream test seam: when given, this stream
     *        IS the piped input (the STDIN terminal question is skipped), so
     *        the empty/binary stdin lanes stay hermetic on any runner.
     */
    private static function readDocument(string $fileArg, mixed $pipedStream = null): string
    {
        if ($fileArg !== '') {
            return self::readDocumentFile($fileArg);
        }

        if ($pipedStream === null && (self::stdinIsTerminal() === true || defined('STDIN') === false)) {
            throw new \InvalidArgumentException(Lang::t('render.no_input_tty'));
        }

        $stream = $pipedStream ?? STDIN;

        return DocumentGate::readStream($stream, 'standard input');
    }

    private static function readDocumentFile(string $path): string
    {
        return DocumentGate::readFile($path);
    }

    /** Whether STDIN is an interactive terminal (no document was piped). */
    private static function stdinIsTerminal(): bool
    {
        if (self::$stdinTtyCallback !== null) {
            return (bool) (self::$stdinTtyCallback)();
        }
        return defined('STDIN') === true && TtyDetect::isAtty(STDIN) === true;
    }

    /**
     * `-p` requires an interactive surface. The Program arms the alt-screen,
     * hides the cursor and enables mouse tracking unconditionally, so a run
     * with neither a tty STDOUT nor an openable /dev/tty writes escape-sequence
     * soup into whatever stdout points at (measured: 2,320 bytes beginning
     * ^[[?1049h captured into the user's redirect target — anyone later
     * cat-ing that file gets a hijacked terminal) and starves the input pump
     * forever (observed rc=124 twice, zero stderr, no refusal). STDOUT tty
     * first: the plain interactive run. Else /dev/tty: the documented
     * `cmd | candy-mdview -p` lane, where the keyboard lives on the real
     * terminal even though stdin carries the pipe.
     *
     * @throws \InvalidArgumentException naming the replacement workflow
     */
    private static function requirePagerSurface(): void
    {
        if (self::stdoutIsTerminal() === true) {
            return;
        }

        $devTtyReachable = self::$pagerDevTtyCallback !== null
            ? (bool) (self::$pagerDevTtyCallback)()
            : self::devTtyOpenable();

        if ($devTtyReachable === true) {
            return;
        }

        throw new \InvalidArgumentException(Lang::t('render.pager_needs_tty'));
    }

    /** Whether a controlling terminal can be opened for the pager keyboard. */
    private static function devTtyOpenable(): bool
    {
        $tty = @fopen('/dev/tty', 'r+b');
        if ($tty === false) {
            return false;
        }
        fclose($tty);

        return true;
    }

    /**
     * One-line usage refusal on STDERR. Two hostile channels are closed at
     * this choke: control bytes from argv ({@see Sanitize::cellValue()}
     * flattens every remaining escape to a middle dot, so a crafted
     * `--theme=$'\e[31mB'` cannot restyle the terminal from inside the
     * error box) and formatter tags ({@see OutputFormatter::escape()}
     * backslash-neutralises `<…>` so a path containing `<info>` cannot
     * restyle or fake a block of its own).
     */
    private static function writeError(OutputInterface $output, string $message): void
    {
        $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $target->writeln('<error>' . OutputFormatter::escape(Sanitize::cellValue($message)) . '</error>');
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
     * Legacy input reader kept for integrators: null on every failure mode,
     * without naming which one.
     *
     * @deprecated kept for the package's own tests and integrators, not
     *             advertised API. It carries NONE of the {@see DocumentGate}
     *             guards that every real input door enforces (is_file, the
     *             size cap, the binary refusal) — bytes read through this
     *             helper go unjudged, and it has no refusal channel to name
     *             what it rejected. Successors: the file lane
     *             {@see self::readDocumentFile()} and the stdin lane of
     *             {@see self::readDocument()}, which share the one gate.
     *
     * @param resource|null $stream Defaults to STDIN; allows unit-testing stdin paths.
     *
     * @return string|null Returns null when:
     *   - file path provided but file does not exist or is unreadable
     *   - no file path and STDIN is a TTY (interactive terminal)
     *   - no file path and STDIN is empty
     *   - no file path and the stream exceeds the document cap
     * Note: It is not possible to distinguish these failure modes from the return value.
     */
    public static function loadInput(string $file, $stream = null): ?string
    {
        if ($file !== '') {
            $contents = @file_get_contents($file);
            return is_string($contents) ? $contents : null;
        }
        // defined('STDIN') is checked BEFORE touching the constant: the old
        // `$stream ?? STDIN` line evaluated the constant first, so under a
        // runner without STDIN defined it fataled instead of answering null.
        if ($stream === null) {
            if (defined('STDIN') === false) {
                return null;
            }
            $stream = STDIN;
        }
        if (is_resource($stream) === false || TtyDetect::isAtty($stream) === true) {
            return null;
        }
        // Color decision lives entirely in execute(); loadInput just reads —
        // bounded at the document cap (an unbounded read here would make the
        // deprecated door the one that ignores the invariant).
        $raw = stream_get_contents($stream, DocumentGate::MAX_INPUT_BYTES + 1);
        if (is_string($raw) && strlen($raw) > DocumentGate::MAX_INPUT_BYTES) {
            return null;
        }

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * Read a `--theme-config` JSON file under a byte cap, then decode it.
     *
     * {@see Theme::fromJson()} slurps the whole file unbounded; reading at
     * most {@see self::MAX_THEME_CONFIG_BYTES}+1 bytes here bounds memory and
     * rejects an oversized config instead of letting it through to the decoder.
     *
     * Two JSON schemas are accepted: a top-level `document` OBJECT marks a
     * glamour style file and routes through
     * {@see GlamourTheme::toMarkdownTheme()} via the sniff below; anything
     * else keeps the historic flat per-element shape. The signatures cannot
     * collide — the flat schema has no `document` key.
     */
    private static function loadThemeConfig(string $path): Theme
    {
        // The door speaks before it reads (finding 015): is_readable()
        // passes for directories, FIFOs and devices, @filesize() returns
        // false exactly when the size is unknowable (skipping the cap), and
        // the windowed read of a FIFO with no writer blocks forever
        // (FUZZ-proved rc=124). A regular file or a named refusal — the
        // same law DocumentGate brought to the document doors.
        if (is_file($path) === false) {
            throw new \InvalidArgumentException(Lang::t('render.theme_config_unreadable', ['path' => $path]));
        }

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

        // Explicit means explicit (finding 020): an object that feeds
        // NEITHER schema — a typo'd container like {"documnet": …} — used
        // to render the fully stock theme at exit 0, silently losing the
        // whole look the user just asked for. The flat reader below already
        // refuses scalars and JSON lists; a schema-less object gets the
        // same fail-fast its neighbours practice.
        if (is_array($decoded) && array_is_list($decoded) === false && array_intersect_key($decoded, array_flip(self::themeSlotNames())) === []) {
            throw new \InvalidArgumentException(Lang::t('render.theme_config_unrecognized', ['path' => $path]));
        }

        return Theme::fromJsonString($raw);
    }

    /**
     * Names the flat `--theme-config` schema may consume: the sugar-markdown
     * Theme constructor's own parameter roster, read by reflection so this
     * door can never drift from what the library actually accepts.
     *
     * @return list<string>
     */
    private static function themeSlotNames(): array
    {
        static $names = null;

        return $names ??= array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(Theme::class, '__construct'))->getParameters(),
        );
    }

    /**
     * Build the MdViewModel for pager mode from rendered content and terminal
     * size. A document name switches on the status header, which reserves its
     * own row inside withDocument() — the viewport is never pre-shrunk here.
     *
     * The name arrives from argv, so it is sanitized before display with the
     * same single-line cellValue policy the error channel applies to path
     * echoes: invalid UTF-8 repaired, newlines collapsed to the ↵ glyph, and
     * every remaining control byte — ESC included, killing forged SGR/OSC-8
     * envelopes — replaced by a visible dot.
     */
    private static function buildPagerModel(string $rendered, int $cols, int $rows, string $document = ''): MdViewModel
    {
        $model = MdViewModel::fromContent($rendered, $cols, $rows);

        return $document === '' ? $model : $model->withDocument(Sanitize::cellValue($document));
    }

    /**
     * Arm the pager's file-watch pump. The reload closure re-renders fresh
     * Markdown with the live {@see Renderer}, so a save while paging shows
     * the updated document with identical styling.
     */
    private static function armPagerWatch(MdViewModel $model, string $path, Renderer $renderer): MdViewModel
    {
        return $model->withWatch($path, static fn(string $markdown): string => $renderer->render($markdown));
    }
}
