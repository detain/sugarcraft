<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

/**
 * Manual argv-parsing, dependency-free — no CLI-flag-parsing lib exists
 * elsewhere in the monorepo, so this follows the precedent set by
 * sugar-post/bin/pop's hand-rolled getopt()-style loop rather than mirroring
 * any single upstream charmbracelet method.
 *
 * Supports: -p/--prompt <value>, --help/-h, the `run "<prompt>"` positional
 * subcommand form (an alias for -p "<prompt>", advertised by
 * {@see Help::screen()} and {@see NonInteractive::run()}'s own usage
 * message), --output-format/--output-format=<value> (advertised by
 * {@see Help::screen()}, consumed by {@see NonInteractive::format()}), the
 * POSIX `--` end-of-options separator, and positional root-path extraction
 * (--root/--root=<value> take precedence over a bare positional).
 *
 * Unrecognised `-`-prefixed arguments are NOT applied, but they are recorded
 * in {@see ParsedArgs::$unknownFlags} rather than dropped: dropping them made
 * `sugarcrush --version` (and every other typo) fall all the way through
 * `bin/sugarcrush` into the blocking full-screen TUI instead of failing fast
 * — the same bug class as the already-fixed "`--help` opens the TUI"
 * (crush_code.md Phase 0 item 3). The binary turns a non-empty
 * `$unknownFlags` into a usage error (exit 2) before it can reach `Program`.
 */
final class ArgvParser
{
    /**
     * Parse $argv into a structured ParsedArgs value object.
     *
     * Flag precedence (same flag, different forms):
     *   -p <value>       -> prompt = <value>
     *   --prompt <value> -> prompt = <value>
     *   run <value>      -> prompt = <value> (only as the very first argument;
     *                       see below)
     *   --help           -> help = true
     *   -h               -> help = true
     *   --output-format <value>       -> outputFormat = <value>
     *   --output-format=<value>       -> outputFormat = <value>
     *
     * `outputFormat` defaults to `NonInteractive::FORMAT_TEXT` ('text') when
     * the flag is absent; no validation is done here — an unrecognised value
     * falls back to text rendering in {@see NonInteractive::format()}.
     *
     * A bare `--` is the POSIX end-of-options separator: it is consumed, and
     * every argument after it is an operand, never a flag — so
     * `sugarcrush -- --version` opens the TUI rather than reporting an
     * unrecognised option. Note that operands still go through the same
     * path-shape heuristic below, so `--` guarantees "not a flag", not
     * "definitely the root".
     *
     * Positional arguments (those not consumed by any flag) are collected.
     * If a positional argument looks like a path (starts with / or . or
     * contains a path separator), it is assigned to root. The first such
     * positional wins; subsequent ones are discarded.
     *
     * `promptRequested` records whether the user asked for one-shot mode at
     * all (`-p` / `--prompt` / `--prompt=` / `run`), independently of whether
     * a prompt VALUE followed. `bin/sugarcrush` dispatches on that flag, not
     * on `prompt !== null`, so a bare `sugarcrush -p` reaches
     * {@see NonInteractive::run()}'s "no prompt given" error instead of
     * silently opening the TUI.
     *
     * @param array<int, string> $argv Command-line arguments, as received by
     *   a PHP CLI entry point ($argv[0] = script name is expected and
     *   skipped — parsing starts at $argv[1]).
     * @return ParsedArgs
     */
    public static function parse(array $argv): ParsedArgs
    {
        $help = false;
        $prompt = null;
        $root = null;
        $outputFormat = ParsedArgs::DEFAULT_OUTPUT_FORMAT;
        $positional = [];
        $unknownFlags = [];
        $promptRequested = false;
        $endOfOptions = false;

        $i = 1; // skip $argv[0] (script name)
        while ($i < \count($argv)) {
            $arg = $argv[$i];

            // Both `--` branches come first so that no later branch — the
            // `run` subcommand, any flag, and above all the unknown-flag
            // recorder — can claim a token the user has explicitly moved out
            // of flag space.
            if ($endOfOptions) {
                $positional[] = $arg;
                ++$i;
                continue;
            }

            // The POSIX end-of-options separator itself: consumed, not kept.
            if ($arg === '--') {
                $endOfOptions = true;
                ++$i;
                continue;
            }

            // `run "<prompt>"` — the positional-subcommand alias for
            // -p "<prompt>" that Help::screen() and NonInteractive::run()'s
            // own usage message both advertise. Only recognised as the very
            // first argument (immediately after the script name), matching
            // the documented `sugarcrush run "<prompt>"` invocation form and
            // leaving a `run` appearing anywhere else (e.g. as a -p value)
            // untouched.
            if ($i === 1 && $arg === 'run') {
                $prompt = $argv[$i + 1] ?? null;
                $promptRequested = true;
                $i += 2;
                continue;
            }

            // --help or -h
            if ($arg === '--help' || $arg === '-h') {
                $help = true;
                ++$i;
                continue;
            }

            // -p <value>
            if ($arg === '-p') {
                $prompt = $argv[++$i] ?? null;
                $promptRequested = true;
                ++$i;
                continue;
            }

            // --prompt=<value>  (no space)
            if (\str_starts_with($arg, '--prompt=')) {
                $prompt = \substr($arg, 9); // length of "--prompt="
                $promptRequested = true;
                ++$i;
                continue;
            }

            // --prompt <value>
            if ($arg === '--prompt') {
                $prompt = $argv[++$i] ?? null;
                $promptRequested = true;
                ++$i;
                continue;
            }

            // --output-format=<value>  (no space)
            if (\str_starts_with($arg, '--output-format=')) {
                $outputFormat = \substr($arg, 16); // length of "--output-format="
                ++$i;
                continue;
            }

            // --output-format <value>
            if ($arg === '--output-format') {
                $outputFormat = $argv[++$i] ?? $outputFormat;
                ++$i;
                continue;
            }

            // --root=<value>  (no space)
            if (\str_starts_with($arg, '--root=')) {
                $root = \substr($arg, 7); // length of "--root="
                ++$i;
                continue;
            }

            // --root <value>
            if ($arg === '--root') {
                $root = $argv[++$i] ?? null;
                ++$i;
                continue;
            }

            // Unrecognised flag — recorded, never applied. bin/sugarcrush
            // turns a non-empty list into a usage error (exit 2); dropping it
            // here is what let `--version` boot the TUI.
            if (\str_starts_with($arg, '-')) {
                $unknownFlags[] = $arg;
                ++$i;
                continue;
            }

            // Positional argument
            $positional[] = $arg;
            ++$i;
        }

        // Assign first positional that looks like a path to root
        if ($root === null) {
            foreach ($positional as $pos) {
                if (self::looksLikePath($pos)) {
                    $root = $pos;
                    break;
                }
            }
        }

        return ParsedArgs::from($help, $prompt, $root, $outputFormat, $unknownFlags, $promptRequested);
    }

    /**
     * Heuristic: does this string look like a filesystem path?
     */
    private static function looksLikePath(string $s): bool
    {
        return $s !== '' && ($s[0] === '/' || $s[0] === '.' || \strpos($s, '/') !== false);
    }
}

/**
 * Immutable value-object returned by {@see ArgvParser::parse()}.
 * All fields are readonly — use ArgvParser::parse() to construct.
 */
final readonly class ParsedArgs
{
    /**
     * Mirrors {@see NonInteractive::FORMAT_TEXT} — duplicated as a literal
     * (rather than referencing the constant directly) so this file has no
     * compile-time dependency on NonInteractive; the values are kept in
     * sync by {@see ArgvParserTest::testOutputFormatDefaultsToText()}.
     */
    public const DEFAULT_OUTPUT_FORMAT = 'text';

    /**
     * @param list<string> $unknownFlags Unrecognised `-`-prefixed arguments,
     *   in the order given. Non-empty means the invocation is a usage error.
     * @param bool $promptRequested True when `-p`/`--prompt`/`--prompt=`/`run`
     *   appeared at all, even without a value — distinct from
     *   `$prompt !== null`, which is only true when a value followed.
     */
    private function __construct(
        public bool $help,
        public ?string $prompt,
        public ?string $root,
        public string $outputFormat = self::DEFAULT_OUTPUT_FORMAT,
        public array $unknownFlags = [],
        public bool $promptRequested = false,
    ) {
    }

    /**
     * Construct a ParsedArgs instance.
     *
     * @param list<string> $unknownFlags
     *
     * @internal
     */
    public static function from(
        bool $help,
        ?string $prompt,
        ?string $root,
        string $outputFormat = self::DEFAULT_OUTPUT_FORMAT,
        array $unknownFlags = [],
        bool $promptRequested = false,
    ): self {
        return new self($help, $prompt, $root, $outputFormat, $unknownFlags, $promptRequested);
    }
}
