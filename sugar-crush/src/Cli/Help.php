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
  sugarcrush -p <prompt>           Run a single prompt and exit (one-shot)
  sugarcrush run "<prompt>"        Alias for -p "<prompt>" (one-shot mode)
  sugarcrush --output-format json  Output machine-readable JSON (one-shot)

Options:
  -p, --prompt <text>    Provide a prompt on the command line (one-shot mode)
      --output-format   Output format: "text" (default) or "json"
  -h, --help             Show this help message

Environment variables:
  SUGARCRUSH_PROVIDER    Provider to use: openai, anthropic, claude-code,
                         sglang, bedrock, vertex, custom
  SUGARCRUSH_MODEL       Model name (overrides provider default)
  SUGARCRUSH_BACKEND_CMD Shell command for a custom backend adapter.
                         Receives JSON history on stdin, writes JSON reply to stdout.

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
