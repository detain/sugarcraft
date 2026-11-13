<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Command;

use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Runtime\IntakeTimedOut;
use SugarCraft\ScriptUi\Runtime\IntakeReader;
use SugarCraft\ScriptUi\Runtime\TimeoutGuard;
use SugarCraft\ScriptUi\Style\StyleBuilder;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mirrors charmbracelet/gum.format.
 * Render a Markdown file (or stdin) as styled ANSI text using
 * {@see \SugarCraft\Markdown\Renderer}. Non-interactive — produces output
 * straight to stdout so it pipelines naturally:
 *
 *   $ candy-scriptui format README.md
 *   $ git log -1 --pretty=%B | candy-scriptui format
 */
#[AsCommand(name: 'format', description: 'Render Markdown as styled ANSI text.')]
final class FormatCommand extends Command
{
    /** @var list<string> selectable --type values */
    private const TYPES = ['markdown', 'code', 'template', 'emoji'];

    /** @var list<string> theme names Theme::byName() resolves */
    private const THEMES = ['ansi', 'plain', 'dark', 'light', 'dracula', 'tokyo-night', 'pink', 'notty', 'ascii'];

    protected function configure(): void
    {
        $this
            ->addArgument('file',  InputArgument::OPTIONAL, 'A file to read, or — when not an existing file — the text itself. Default: stdin.')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'ansi | plain | dark | light | dracula | tokyo-night | pink | notty | ascii', 'ansi')
            ->addOption('type',  't',  InputOption::VALUE_REQUIRED, 'Render type: markdown | code | template | emoji. Default markdown.', 'markdown')
            ->addOption('code', null, InputOption::VALUE_NONE, 'Shorthand for `--type code`: render the input as a fenced code block.')
            ->addOption('foreground', null, InputOption::VALUE_REQUIRED, 'Base text colour layered under the rendered output (hex, 0-255 index, or colour name).', '')
            ->addOption('background', null, InputOption::VALUE_REQUIRED, 'Base background colour layered under the rendered output (hex, 0-255 index, or colour name).', '')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED, 'Source language for `--type code`.', '')
            ->addOption('allow-env', null, InputOption::VALUE_REQUIRED, 'Comma-separated allowlist of env var names permitted for {{VAR}} expansion in --type template. Empty = expand none; "*" = expand all (opt-in, unsafe with untrusted input).', '')
            ->addOption('strip-ansi', null, InputOption::VALUE_NONE, 'Strip ANSI escapes from the rendered output.')
            ->addOption('show-help',  null, InputOption::VALUE_NONE, 'Alias for --help (candy extension).')
            ->addOption('timeout',    null, InputOption::VALUE_REQUIRED, 'Abort with exit 124 if piped input has not finished arriving after N seconds (0 = none).', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Selection flags are validated BEFORE any input is touched: a bad
        // --type must never shadow a file read (and never surface as an
        // exception box). Refusals are plain sentences on STDERR; the data
        // stream stays byte-clean for pipelines.
        $type = strtolower((string) $input->getOption('type'));
        if ($input->getOption('code')) {
            // `--code` is `--type code`; an explicit different --type is a
            // contradiction, not something to pick a winner for.
            if ($input->hasParameterOption(['--type', '-t']) && $type !== 'code') {
                fwrite(STDERR, Lang::t('runtime.conflicting_flags', ['first' => '--code', 'second' => '--type ' . $type]) . "\n");
                return Command::FAILURE;
            }
            $type = 'code';
        }
        try {
            // Colours route through the clamped builder so NO_COLOR / --no-ansi
            // / a narrow TERM tier drop or requantise them like every other flag.
            $baseStyle = StyleBuilder::fromFlags([
                'foreground' => (string) $input->getOption('foreground'),
                'background' => (string) $input->getOption('background'),
            ]);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return Command::FAILURE;
        }
        if ($type !== '' && !in_array($type, self::TYPES, true)) {
            fwrite(STDERR, Lang::t('runtime.unknown_choice', [
                'option' => '--type',
                'value' => $type,
                'allowed' => implode('|', self::TYPES),
            ]) . "\n");
            return Command::FAILURE;
        }
        $themeName = (string) $input->getOption('theme');
        if (Theme::byName($themeName) === null) {
            fwrite(STDERR, Lang::t('runtime.unknown_choice', [
                'option' => '--theme',
                'value' => $themeName,
                'allowed' => implode('|', self::THEMES),
            ]) . "\n");
            return Command::FAILURE;
        }
        $timeoutSeconds = 0.0;
        if (!TimeoutGuard::tryParseSeconds($input->getOption('timeout'), $timeoutSeconds)) {
            // Refuse a typo'd deadline BEFORE consuming stdin — and never
            // let it silently disable itself (fail-open).
            fwrite(STDERR, Lang::t('runtime.bad_timeout', ['value' => (string) $input->getOption('timeout')]) . "\n");
            return Command::FAILURE;
        }

        $file = $input->getArgument('file');
        if (is_string($file) && $file !== '' && is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                fwrite(STDERR, Lang::t('io.read_failed', ['path' => $file]) . "\n");
                return Command::FAILURE;
            }
        } elseif (is_string($file) && $file !== '') {
            // gum muscle memory: `format "# hi"` is the TEXT, not a path.
            // Anything that is not an existing file renders as content.
            $raw = $file;
        } else {
            try {
                $raw = self::readStdin($timeoutSeconds);
            } catch (IntakeTimedOut) {
                return TimeoutGuard::EXIT_TIMEOUT;
            }
        }

        $allowEnv = array_values(array_filter(
            array_map('trim', explode(',', (string) $input->getOption('allow-env'))),
            static fn (string $s): bool => $s !== '',
        ));

        $unresolved = [];
        $rendered = match ($type) {
            'markdown', '' => (new Renderer(FormatCommand::pickTheme($themeName)))->render($raw),
            'code'         => self::renderCode($raw, (string) $input->getOption('language'), $themeName),
            'template'     => self::renderTemplate($raw, $allowEnv, $unresolved),
            'emoji'        => self::renderEmoji($raw),
            // Unreachable by construction: the validation door above admits
            // only '' and self::TYPES, and each carries its own arm. A hit
            // here means the door and this match drifted apart — a wiring
            // bug, never user input, so it fails fast as a logic error.
            default        => throw new \LogicException("unreachable: --type '{$type}' cleared the door but has no renderer"),
        };

        if ($unresolved !== []) {
            // A placeholder that vanished is the bug class, not the value:
            // announce every name that expanded to empty.
            fwrite(STDERR, Lang::t('format.template_unresolved', [
                'count' => (string) count($unresolved),
                'names' => implode(', ', array_slice(array_keys($unresolved), 0, 10)),
            ]) . "\n");
        }

        if ((string) $input->getOption('foreground') !== '' || (string) $input->getOption('background') !== '') {
            $rendered = self::applyBase($rendered, $baseStyle);
        }

        if ($input->getOption('strip-ansi')) {
            $rendered = \SugarCraft\Core\Util\Ansi::strip($rendered);
        }

        // Verbatim by contract (n22, the note ef501148c deferred): the
        // rendered result below is the data channel of a non-interactive
        // pipe, so OUTPUT_RAW passes the bytes through untouched — SGR and
        // all — exactly as downstream consumers expect. Every app-composed
        // word (the refusals at the door checks, the template_unresolved
        // announce above) is written to STDERR, never here, keeping the
        // stdout stream a pipeline reads precisely the formatted bytes.
        // LF-pinned trailer: write(..., true) emits PHP_EOL (CRLF on Windows).
        $output->write($rendered . "\n", false, OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }

    /**
     * Layer a base colour under already-styled output. The renderer ends
     * spans with a full reset, which would drop an outer colour after the
     * first styled word — so the opener is re-asserted after every reset
     * and each line is self-contained (opener ... closer).
     */
    private static function applyBase(string $rendered, \SugarCraft\Style\Style $style): string
    {
        $probe = $style->render("\u{1}");
        $at = strpos($probe, "\u{1}");
        if ($at === false || $at === 0) {
            // No escapes under this tier (NO_COLOR, --no-ansi): nothing to layer.
            return $rendered;
        }
        $open = substr($probe, 0, $at);
        $close = substr($probe, $at + 1);
        $lines = explode("\n", $rendered);
        foreach ($lines as $i => $line) {
            if ($line === '') {
                continue;
            }
            $line = str_replace(["\x1b[0m", "\x1b[m"], ["\x1b[0m" . $open, "\x1b[m" . $open], $line);
            $lines[$i] = $open . $line . $close;
        }
        return implode("\n", $lines);
    }

    public static function pickTheme(string $name): Theme
    {
        // Lean on Theme::byName() (case-insensitive, hyphen/underscore-tolerant)
        // so callers stay aligned with SugarMarkdown's full theme set.
        $theme = Theme::byName($name);
        if ($theme === null) {
            throw new \InvalidArgumentException(Lang::t('format.unknown_theme', ['name' => $name]));
        }
        return $theme;
    }

    /**
     * Render `--type code <body>` as a fenced code block of the given
     * language. Equivalent to wrapping the input in ``` fences and
     * piping through the markdown pipeline.
     */
    private static function renderCode(string $raw, string $lang, string $themeName): string
    {
        $body = "```$lang\n" . rtrim($raw, "\n") . "\n```\n";
        return (new Renderer(self::pickTheme($themeName)))->render($body);
    }

    /**
     * `--type template` expands `{{var}}` placeholders from environment
     * variables and emits the result. Mirrors gum's lightweight
     * template mode (no Go template-function support).
     *
     * Secure-by-default: expansion is gated by an explicit allowlist so
     * an attacker-influenced template body (an untrusted markdown file
     * or piped stdin) cannot exfiltrate arbitrary secrets — a bare
     * `{{AWS_SECRET_ACCESS_KEY}}` or `{{DB_PASSWORD}}` would otherwise
     * leak ANY env var. A `{{NAME}}` is only substituted from the
     * environment when NAME is in $allowlist (or the allowlist is `*`);
     * every other placeholder renders empty — the same result an
     * undefined var already produced, so no leak and no error. The
     * default (no --allow-env) is an empty allowlist: nothing expands.
     *
     * @param list<string> $allowlist Env var names permitted for
     *                                expansion; `['*']` opts every var in
     *                                (explicit, unsafe with untrusted
     *                                input); `[]` expands none.
     * @param array<string,true> $unresolved  Out-param: distinct placeholder
     *                                names that expanded to empty (not
     *                                allowlisted, or unset), first-seen
     *                                order — callers announce them so a
     *                                vanishing {{VAR}} is never silent.
     */
    private static function renderTemplate(string $raw, array $allowlist, array &$unresolved): string
    {
        $allowAll = in_array('*', $allowlist, true);
        $set = array_flip($allowlist);
        $unresolved = [];
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/',
            static function (array $m) use ($allowAll, $set, &$unresolved): string {
                $name = $m[1];
                if ($allowAll || isset($set[$name])) {
                    // getenv() returns '0' for a set-but-zero variable:
                    // truthiness would erase it. Only unset (false) is empty.
                    $value = getenv($name);
                    if ($value === false || $value === '') {
                        $unresolved[$name] = true;
                        return '';
                    }
                    return $value;
                }
                $unresolved[$name] = true;
                return '';
            },
            $raw,
        );
    }

    /**
     * `--type emoji` expands `:smile:` shortcodes via the small
     * built-in mapping. Unknown shortcodes pass through verbatim
     * so callers can mix emoji + text without breakage.
     */
    private static function renderEmoji(string $raw): string
    {
        $map = [
            'smile'        => '😄', 'grin'        => '😁',
            'heart'        => '❤️', 'fire'        => '🔥',
            'rocket'       => '🚀', 'star'        => '⭐',
            'thumbsup'     => '👍', 'thumbsdown'  => '👎',
            'check'        => '✅', 'x'           => '❌',
            'warning'      => '⚠️',  'info'        => 'ℹ️',
            'tada'         => '🎉', 'sparkles'    => '✨',
            'candy'        => '🍬', 'sugar'       => '🍭',
            'honey'        => '🍯',
        ];
        return (string) preg_replace_callback(
            '/:([a-z0-9_+-]+):/i',
            static fn (array $m) => $map[strtolower($m[1])] ?? $m[0],
            $raw,
        );
    }

    private static function readStdin(float $timeoutSeconds = 0.0): string
    {
        if (!defined('STDIN') || !is_resource(STDIN) || @stream_isatty(STDIN)) {
            return '';
        }
        return IntakeReader::read(STDIN, $timeoutSeconds);
    }
}
