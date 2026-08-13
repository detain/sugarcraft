<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

/**
 * Static factory for the one-shot (non-interactive) CLI's --help output.
 *
 * Mirrors the plain-text help pattern from sugar-post/bin/pop's help().
 *
 * Production reachability: wired in W1.E3 via ArgvParser::parse() →
 *   if $args->help { fwrite(STDOUT, Help::screen()); exit(0); }
 */
final class Help
{
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
      --                 End of options: every later argument is positional,
                         never a flag

Environment variables:
  SUGARCRUSH_PROVIDER    Provider to use: openai, anthropic, claude-code,
                         sglang, bedrock, vertex, custom
  SUGARCRUSH_MODEL       Model name (overrides provider default)
  SUGARCRUSH_BACKEND_CMD Shell command for a custom backend adapter.
                         Receives JSON history on stdin, writes JSON reply to stdout.

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
}
