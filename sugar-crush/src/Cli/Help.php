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
  sugarcrush <dir>                 Start the TUI rooted at <dir>, which must
                                   exist (only as the first argument)
  sugarcrush [<dir>] <words…>      Start the TUI and send the words as its
                                   first prompt, e.g. sugarcrush fix the bug
  sugarcrush -c                    Continue the most recent session
  sugarcrush --resume [<id>]       Resume a stored session (picker if no id)
  sugarcrush -p <prompt>           Run a single prompt and exit (one-shot)
  sugarcrush run "<prompt>"        Alias for -p "<prompt>" (one-shot mode)
  sugarcrush --output-format json  Output machine-readable JSON (one-shot)

Subcommands (none of them opens the TUI or needs a provider, an API key or a
terminal; each answers and exits except serve, which runs until stopped):
  doctor                 Check this installation and report every problem it
                         finds: PHP version, the extensions the session store
                         and serve need, the config file, the permission
                         policy, the selected provider, the session database
                         and the project MCP config. Exits 1 if any check
                         FAILS.
                         Distinct from the model-callable "doctor" tool, which
                         reports the terminal's image protocol to the model.
  models                 List the providers this install can select and the
                         model each one defaults to; "*" marks the selected
                         one.
  session list [--all|--archived|--children] [--limit N]
                         List stored sessions, pinned first then newest
                         first: id, last update, kind, turns, provider/model
                         and name (★ marks a pinned row). --children adds
                         sub-agent and background rows, --archived adds
                         archived ones, --all adds both; 20 rows unless
                         --limit says otherwise.
  session show <target>  Print one session's details and transcript.
  session rename <target> <title…>
                         Rename a session; the words after the target are
                         the title.
  session delete <target> [--with-children]
                         Delete a session. Sub-agent children go with it;
                         branches are kept unless --with-children.
  session pin|unpin|archive|unarchive <target>
                         Pin a session (listed first, never pruned), or
                         archive it (hidden from the default list, kept).
                         <target> is an id, a name or a unique id prefix.
                         Exits 1 if nothing matches, 2 if a prefix is
                         ambiguous (the candidates are listed).
  mcp list               List the MCP servers .mcp.json declares, WITHOUT
                         starting any of them. Reports instead when the file
                         is absent, resolves outside the project tree, or is
                         present but the project root is not trusted.
  mcp trust              Approve this project's .mcp.json as it is now: add
                         the root to "trustedProjectMcp" and record each
                         server's command, args and env, so a later change to
                         any of them is refused at launch until re-approved.
                         Starts nothing.
  mcp import claude|opencode <path>
                         Translate a foreign MCP config and print the
                         equivalent .mcp.json block to stdout. Prints only
                         the document and writes no file; the renames the
                         translation made are listed on stderr.
  serve [--host <ip>] [--port <n>] [--allow-remote] [--allowed-origin <list>]
        [--web-root <dir>] [--no-web] [--allow-bypass] [--allow-root]
                         Run the WebSocket + HTTP server the web UI talks to,
                         in the foreground until Ctrl+C. Binds 127.0.0.1:7420
                         by default and prints a sign-in URL whose one-time
                         code is exchanged for a browser cookie; every client
                         needs it or the token in ~/.sugar-crush/server/token,
                         loopback included. Options:
      --host <ip>        Address to bind (default 127.0.0.1; localhost and ::1
                         also count as loopback). Any other address is refused
                         unless --allow-remote is given too.
      --port <n>         Port to bind (default 7420; 0 picks a free one).
      --allow-remote     Permit a non-loopback --host. There is no built-in
                         TLS: put a reverse proxy in front of it.
      --allowed-origin <list>
                         Comma-separated extra browser origins
                         (http(s)://host[:port]) allowed beside the server's
                         own.
      --web-root <dir>   Serve the web UI from <dir> instead of the installed
                         sugarcraft/sugar-crush-web package.
      --no-web           Serve the API and WebSocket only, no UI files.
      --allow-bypass     Let sessions run in bypass-permissions or dont-ask
                         (refused by default; the server's sessions start in
                         default unless --permission-mode says otherwise).
      --allow-root       Permit running as root (refused by default).
                         Refuses to start without ext-pcntl, ext-posix and
                         ext-ffi (see doctor). Exits 1 if the port is taken.
  completion bash|zsh|fish
                         Write a shell completion script to stdout, e.g.
                         eval "$(sugarcrush completion bash)".

Options:
  -p, --prompt <text>    Provide a prompt on the command line (one-shot mode)
      --output-format <format>
                         Output format: "text" (default) or "json"
      --root <dir>       Use <dir> as the project root instead of the current
                         directory. Also accepts --root=<dir> (the form to use
                         when <dir> begins with "-"); it may not be omitted.
                         Naming a root here AND as a positional argument is
                         refused rather than one silently winning.
      --config <file>    Read settings, permissions and trusted hook roots from
                         <file> instead of ~/.sugar-crush/config.json. Also
                         accepts --config=<file>. Must already exist, be
                         readable, be owned by you, and neither it nor its
                         directory may be world-writable — it carries the
                         permission policy, so it is held to the same standard
                         as ~/.sugar-crush/config.json (a file under /etc or
                         /tmp is normally refused for one of those reasons).
                         Only the FILE moves: agents, skills, workflows,
                         sessions and memory stay in ~/.sugar-crush.
      --model <name>     Use <name> as the conversation model, overriding
                         $SUGARCRUSH_MODEL and the provider's own default. Also
                         accepts --model=<name>. Selects a model, not a provider
                         — the provider still comes from $SUGARCRUSH_PROVIDER or
                         the persisted `provider` setting, and the two are
                         independent axes.
      --permission-mode <mode>
                         Run under <mode> instead of the mode in the config
                         file. Also accepts --permission-mode=<mode>. Highest
                         precedence: it beats $SUGARCRUSH_PERMISSION_MODE and
                         the permissionMode config key. One of: default,
                         accept-edits, plan, auto, dont-ask,
                         bypass-permissions. With none set, the TUI starts
                         in default (it asks before writes and shell
                         commands); -p and background sessions start in
                         bypass-permissions.
  -c, --continue         Reopen the most recently used session, transcript
                         and all, instead of starting a new one. Without it
                         every launch opens a new session; Up in an empty
                         input box still recalls prompts from earlier ones.
      --resume [<id|name>]
                         Reopen a stored session by id, unique id prefix or
                         name (see `session list`). With no value, open the
                         session picker at launch. Also accepts
                         --resume=<id>. Not combinable with --continue or -p.
  -h, --help             Show this help message
  -v, --version          Show the installed version and exit
      --                 End of options: every later argument is positional,
                         never a flag

Environment variables:
   SUGARCRUSH_PROVIDER    Provider to use: openai, anthropic, claude-code,
                          sglang, bedrock, vertex, custom
   SUGARCRUSH_MODEL       Model name (overrides provider default; --model wins)
   SUGARCRUSH_PERMISSION_MODE
                          Permission mode to start in, overriding the
                          permissionMode config key (--permission-mode wins)
   SUGARCRUSH_BACKEND_CMD Shell command for a custom backend adapter, under the
                          PROSE contract: receives JSON history on stdin, writes
                          the reply text to stdout, which is used verbatim
                          (trimmed at the two ends only, so every newline, blank
                          line and indent inside survives).
   SUGARCRUSH_BACKEND_CMD_STREAM
                          The same shell-out under the other contract, a TOKEN
                          STREAM: one token per line, the newline between two
                          tokens is framing and is dropped, and a BLANK line is
                          a literal newline in the answer. The callback fires per
                          token as the command produces them, but the read loop
                          is synchronous, so the screen repaints once at the end
                          rather than token by token. Not interchangeable with
                          SUGARCRUSH_BACKEND_CMD in either direction — a prose
                          wrapper run through this variable comes back with every
                          newline gone and each blank line collapsed to one.
                          SUGARCRUSH_BACKEND_CMD outranks it when both are set.
                          For both, unset, empty and whitespace-only all count
                          as absent.
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
   SUGARCRUSH_TITLE_MODEL The cheap model used to auto-name a session after
                          its first exchange; defaults to the provider's.
   SUGARCRUSH_SUMMARY_MODEL
                          The model that writes compaction summaries;
                          defaults to the conversation's own, which reuses
                          the prompt cache.
   SUGARCRUSH_MAX_COST    A spend ceiling for this launch, in US dollars
                          (fractional allowed; a leading "$" is accepted).
                          A turn that crosses the ceiling is refused.
   SUGARCRUSH_CONNECT_TIMEOUT
                          Connect-phase bound, in seconds (fractional
                          allowed; default 15.0), for provider HTTP
                          transports. It bounds establishing the connection
                          only — it is not a total request timeout.
   SUGARCRUSH_SEARCH_ENDPOINT
                          Search API the built-in WebSearch tool queries.
   SUGARCRUSH_SESSION_RETENTION_DAYS
                          A positive whole number of days: each launch drops
                          sessions untouched for at least that long.
   SUGARCRUSH_SHARE_UPLOAD_URL
                          Base URL /share uploads to; point it at a private
                          host to keep transcripts off the public default.
   SUGARCRUSH_WORKTREES_DIR
                          Base directory under which per-teammate git
                          worktrees are created.
   SUGARCRUSH_DISABLE_MOUSE
                          Any value other than empty or 0 turns mouse
                          tracking off entirely.
   SUGARCRUSH_DISABLE_MOUSE_CLICKS
                          Any value other than empty or 0 ignores click
                          gestures while keeping wheel scrolling.
   SUGARCRUSH_DISABLE_PROMPT_CACHE
                          Any value other than empty or 0 switches off the
                          provider prompt-cache breakpoints.
   SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS
                          Any value other than empty or 0 stops the grayed
                          next-message suggestion (→ takes it) after each turn.
   SUGARCRUSH_DISABLE_AUTO_MEMORY
                          Any value other than empty or 0 stops auto-memory
                          saving durable facts as notes after a turn.
   SUGARCRUSH_DISABLE_MODEL_METADATA
                          Any value other than empty or 0 stops reading and
                          refreshing the LiteLLM model database that sizes and
                          prices models with no built-in figure.
   SUGARCRUSH_BACKGROUND  light or dark — forces what the adaptive theme
                          believes about the terminal background, skipping
                          the OSC 11 probe and COLORFGBG.
   SUGARCRUSH_DEBUG_SKILLS
                          Any value other than empty or 0 puts SkillLoader's
                          per-skip and per-refused-directory lines back on
                          stderr.
   SUGARCRUSH_DEBUG_COMMANDS
                          Any value other than empty or 0 puts CommandLoader's
                          discovery-refusal lines back on stderr.
   SUGARCRUSH_DEBUG_RULES Any value other than empty or 0 puts RuleLoader's
                          discovery-refusal lines back on stderr.
   SUGARCRUSH_DEBUG_STREAM
                          Any value other than empty or 0 keeps Chat's
                          "onToken observer threw" line on stderr. The
                          detach itself happens either way.
   SUGARCRUSH_SERVER_HOST The address `serve` binds (--host wins).
   SUGARCRUSH_SERVER_PORT The port `serve` binds (--port wins).
   SUGARCRUSH_SERVER_ALLOWED_ORIGINS
                          Comma-separated extra origins `serve` accepts
                          (--allowed-origin wins).
   SUGARCRUSH_SERVER_WEB_ROOT
                          Directory `serve` serves the web UI from (--web-root
                          wins).
   SUGARCRUSH_SERVER_TOKEN
                          The token `serve` requires, instead of the one in
                          its state directory (at least 32 characters; for
                          containers).
   SUGARCRUSH_SERVER_DIR  The directory `serve` keeps its token in (default
                          ~/.sugar-crush/server; created 0700).
   SUGARCRUSH_MCP_DISABLE
                          1, true or yes (case-insensitive) silences project
                          MCP entirely: .mcp.json is treated as absent, no
                          server starts. Unset, 0 or any other value keeps
                          MCP enabled — unlike the flag-style variables,
                          only these three words count.

   docs/ENVIRONMENT.md tabulates every variable this build reads, with its
   full contract.

Exit codes (one-shot mode and every subcommand):
  0                      The prompt ran and produced an answer, or the
                         subcommand answered
  1                      Ran and failed: the backend threw (unreachable host,
                         rejected key, model error) — a retry may help — or a
                         subcommand ran and failed (a doctor check came back
                         FAIL, a `session` verb found no such session, an
                         .mcp.json that is trusted could not be parsed)
  2                      Usage or configuration error, nothing was attempted
                         and a retry will not help: no prompt given, an
                         unrecognized flag, a bare argument that names no
                         existing directory (a prompt needs -p), a --root
                         naming no directory, a missing composer
                         autoload.php, or a selected provider
                         that cannot be constructed — either
                         $SUGARCRUSH_PROVIDER or the provider persisted in
                         ~/.sugar-crush/config.json by Ctrl+P "Switch model".
                         A one-shot run never falls back to the offline echo
                         provider when a provider was explicitly selected.
                         A subcommand given a missing or unknown operand
                         (`session`, `mcp bogus`, `completion tcsh`) reports
                         here too.

Examples:
  sugarcrush -p "Explain the difference between require and include"
  SUGARCRUSH_PROVIDER=anthropic SUGARCRUSH_MODEL=claude-sonnet-4-20250514 \
    sugarcrush -p "Write a hello world in Go"
  sugarcrush run "Write a hello world in Go"
  sugarcrush doctor
  sugarcrush models --output-format json | jq '.result.providers'
  eval "$(sugarcrush completion bash)"
  sugarcrush serve --port 7420

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

        return self::versionStringFor($pretty, $reference);
    }

    /**
     * The decoration rule on its own, given metadata rather than reading it.
     *
     * Split out because the rule has two arms and no single environment can run
     * both: Composer guesses the root package's reference from VCS only when
     * `COMPOSER_ROOT_VERSION` is unset, so a developer checkout ALWAYS has one
     * and CI — which sets that variable workflow-wide — NEVER does. Measured
     * with `composer show --self`: without the variable the source reference is
     * the real commit, with it the reference is empty. A test calling
     * {@see versionString()} therefore only ever exercises whichever arm its own
     * environment permits, which is how a bare `dev-master` reached CI while
     * every local run stayed green.
     *
     * @param ?string $pretty    Composer's pretty version, or null when absent.
     * @param ?string $reference The install's commit, or null when Composer did
     *                           not resolve one.
     */
    public static function versionStringFor(?string $pretty, ?string $reference): string
    {
        if ($pretty === null || $pretty === '') {
            return 'unknown';
        }

        if ($reference !== null && \str_contains($pretty, 'dev')) {
            return $pretty . ' (' . \substr($reference, 0, 7) . ')';
        }

        return $pretty;
    }
}
