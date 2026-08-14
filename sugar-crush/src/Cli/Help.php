<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use Composer\InstalledVersions;

/**
 * Static factory for the one-shot (non-interactive) CLI's --help and
 * --version output.
 *
 * Mirrors the plain-text help pattern from sugar-post/bin/pop's help().
 *
 * `--version` lives here rather than in a class of its own because it is the
 * same kind of thing as `--help`: a plain string the binary writes straight to
 * STDOUT before any TUI or backend wiring exists, produced by no state.
 *
 * Production reachability: wired in W1.E3 via ArgvParser::parse() →
 *   if $args->help { fwrite(STDOUT, Help::screen()); exit(0); }
 *   if $args->version { fwrite(STDOUT, Help::version()); exit(0); }
 */
final class Help
{
    /**
     * The Composer package whose installed version IS sugarcrush's version.
     */
    private const PACKAGE = 'sugarcraft/sugar-crush';
    /**
     * Return the --help text for the sugarcrush binary.
     *
     * This is the plain-text help screen for the one-shot / scripted /
     * CI-friendly invocation mode. It is NOT a TUI component — it is a plain
     * string that gets written directly to STDOUT.
     */
    public static function screen(): string
    {
        return <<<'HELP'
SugarCrush — AI coding assistant for the terminal.

Usage:
  sugarcrush                       Start the interactive TUI (default)
  sugarcrush <dir>                 Start the TUI rooted at <dir>
  sugarcrush -p <prompt>           Run a single prompt and exit (one-shot)
  sugarcrush run "<prompt>"        Alias for -p "<prompt>" (one-shot mode)
  sugarcrush --output-format json  Output machine-readable JSON (one-shot)

Options:
  -p, --prompt <text>    Provide a prompt on the command line (one-shot mode)
      --output-format <format>
                         Output format: "text" (default) or "json"
      --root <dir>       Use <dir> as the project root instead of the current
                         directory. Also accepts --root=<dir>, and wins over a
                         path-shaped positional argument.
  -h, --help             Show this help message
  -v, --version          Show the installed version and exit
      --                 End of options: every later argument is positional,
                         never a flag

Environment variables:
  SUGARCRUSH_PROVIDER    Provider to use: openai, anthropic, claude-code,
                         sglang, bedrock, vertex, custom
  SUGARCRUSH_MODEL       Model name (overrides provider default)
  SUGARCRUSH_BACKEND_CMD Shell command for a custom backend adapter.
                         Receives JSON history on stdin, writes JSON reply to stdout.
  SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS
                         Run a turn's tool calls strictly one at a time.
                         Concurrent dispatch is the default and only ever
                         groups non-mutating tools; this is the escape hatch.
                         Persist it as "parallelToolCalls": false in
                         ~/.sugar-crush/config.json.
  SUGARCRUSH_PARALLEL_TOOL_DEADLINE
                         Seconds one concurrent group may run before its
                         stragglers are killed and reported as failed calls
                         (default 90, must be 1-119; a fraction is truncated).
                         Persist it as "parallelToolDeadlineSeconds".
                         Precedence: this variable, then the persisted key,
                         then the default. A value outside 1-119, or one that
                         is not a number at all, does not count as set — it
                         falls through to the next source rather than
                         discarding it.

Exit codes (one-shot mode):
  0                      The prompt ran and produced an answer
  1                      Ran and failed: the backend threw (unreachable host,
                         rejected key, model error) — a retry may help
  2                      Usage or configuration error, nothing was attempted
                         and a retry will not help: no prompt given, an
                         unrecognized flag, a --root naming no directory, a
                         missing composer autoload.php, or a selected provider
                         that cannot be constructed — either
                         $SUGARCRUSH_PROVIDER or the provider persisted in
                         ~/.sugar-crush/config.json by Ctrl+P "Switch model".
                         A one-shot run never falls back to the offline echo
                         provider when a provider was explicitly selected.

Examples:
  sugarcrush -p "Explain the difference between require and include"
  SUGARCRUSH_PROVIDER=anthropic SUGARCRUSH_MODEL=claude-sonnet-4-20250514 \
    sugarcrush -p "Write a hello world in Go"
  sugarcrush run "Write a hello world in Go"

For more information, see the README:
  https://github.com/detain/sugarcraft/tree/master/sugar-crush

HELP;
    }

    /**
     * Return the --version line for the sugarcrush binary, newline-terminated.
     *
     * Plain text on STDOUT, exactly like {@see screen()}: `--version` is the
     * flag a packaging script or a bug report runs first, so it must work on a
     * machine with no provider, no config and no TTY.
     */
    public static function version(): string
    {
        return 'sugarcrush ' . self::versionString() . "\n";
    }

    /**
     * The installed version of this package, e.g. `v1.2.0`, or
     * `dev-master (3f9eac2)` for a source checkout.
     *
     * Read from Composer's install metadata rather than declared as a literal
     * anywhere in this repo, because a literal is exactly the thing that rots:
     * sugar-crush ships from a monorepo whose per-lib package is split out and
     * tagged downstream, so nothing in this directory ever learns its own
     * release number — a hardcoded `const VERSION = '0.1.0'` would still say
     * 0.1.0 three tags later, and the bug reports quoting it would be wrong.
     * `InstalledVersions` is derived from whatever actually got installed, so
     * it reports the real tag the moment there is one and stays honest until
     * then. It is also always present: `bin/sugarcrush` cannot start without
     * the Composer autoloader that defines it.
     *
     * The commit reference is appended for dev versions only. On a dev
     * checkout `dev-master` alone identifies nothing — every checkout since
     * the branch existed says `dev-master` — whereas a tag is already exact
     * and does not need decorating.
     */
    public static function versionString(): string
    {
        // Guarded rather than assumed: a consumer vendoring this package under
        // a non-Composer autoloader (PSR-4 map, phar) gets "unknown" instead of
        // a fatal error on the one command that exists to be diagnostic.
        if (!\class_exists(InstalledVersions::class)) {
            return 'unknown';
        }

        try {
            $pretty = InstalledVersions::getPrettyVersion(self::PACKAGE);
            $reference = InstalledVersions::getReference(self::PACKAGE);
        } catch (\OutOfBoundsException) {
            return 'unknown';
        }

        if ($pretty === null || $pretty === '') {
            return 'unknown';
        }

        if ($reference !== null && \str_contains($pretty, 'dev')) {
            return $pretty . ' (' . \substr($reference, 0, 7) . ')';
        }

        return $pretty;
    }
}
