<?php

declare(strict_types=1);

/**
 * English source-of-truth catalogue for sugar-crush's `crush` namespace
 * ({@see \SugarCraft\Crush\Lang}). Every other `lang/<locale>.php` carries
 * exactly these keys with the same `{placeholders}` (tests/LangParityTest.php).
 *
 * The settings tabs are seeded first because their keys already exist:
 * {@see \SugarCraft\Crush\Config\Settings\SettingCategory::labelKey()} names
 * each one, and LangParityTest pins every value here to the literal
 * {@see \SugarCraft\Crush\Config\Settings\SettingCategory::label()} still
 * returns (decision D7), so swapping that method onto Lang::t() changes
 * nothing in English.
 */
return [
    'settings.category.model' => 'Model & Provider',
    'settings.category.loop' => 'Agent loop',
    'settings.category.context' => 'Context & Compaction',
    'settings.category.permissions' => 'Permissions',
    'settings.category.tools' => 'Tools',
    'settings.category.memory' => 'Memory & Rules',
    'settings.category.skills' => 'Skills',
    'settings.category.subagents' => 'Sub-agents',
    'settings.category.git' => 'Git & Automation',
    'settings.category.interface' => 'Interface',
    'settings.category.hooks' => 'Hooks & MCP',
    'settings.category.server' => 'Server',
    'settings.category.advanced' => 'Advanced',
    // --- cli (W11-a) ---
    'cli.acp.json_does_not_apply' => 'sugarcrush acp: speaks JSON-RPC on stdout, which is its only output; --output-format json does not apply',
    'cli.acp.stdin_closed' => 'sugarcrush acp: stdin is closed; an editor starts this agent with a pipe on stdin and stdout',
    'cli.acp.unexpected_operand' => 'sugarcrush acp {operand}: unexpected operand',
    'cli.acp.usage' => 'Usage: sugarcrush acp — run as an Agent Client Protocol agent on stdin/stdout (started by an editor)',
    'cli.argv.config.empty' => '--config expects a file path, but the value is empty',
    'cli.argv.config.flag' => '--config expects a file path, but the next argument is the option {option}',
    'cli.argv.config.missing' => '--config expects a file path, but the argument list ended',
    'cli.argv.config.no_such_file' => '--config {path}: no such file',
    'cli.argv.config.not_readable' => '--config {path}: not readable',
    'cli.argv.continue_with_resume' => '--continue and --resume both pick the session to open; use one of them',
    'cli.argv.hint.config_value' => 'Write it as --config=<file> if the path begins with "-"; it may not be omitted.',
    'cli.argv.hint.continue_or_resume' => 'Use --continue for the most recent session, or --resume <id|name> for a specific one.',
    'cli.argv.hint.drop_prompt' => 'Drop -p to continue the conversation in the TUI.',
    'cli.argv.hint.flag_alone' => 'Write it as {flag} alone.',
    'cli.argv.hint.flag_value' => 'Write it as {flag}=<value>; it may not be omitted.',
    'cli.argv.hint.model_value' => 'Write it as --model=<name> if the model name begins with "-"; it may not be omitted.',
    'cli.argv.hint.output_formats' => 'Valid formats are: {formats} (lowercase).',
    'cli.argv.hint.prompt_dash' => 'To pass a prompt that begins with "-", use --prompt=<text>.',
    'cli.argv.hint.quote_prompt' => 'Quote the whole prompt as one argument: -p "<prompt>".',
    'cli.argv.hint.root_once' => 'Name the project directory once: as a bare argument or with --root <dir>, not both.',
    'cli.argv.hint.root_value' => 'Write it as --root=<dir> if the directory begins with "-"; it may not be omitted.',
    'cli.argv.hint.valid_modes' => 'Valid modes are: {modes}.',
    'cli.argv.hint.words_open_tui' => 'Words after the options open the TUI with them as the first prompt; to run a one-shot prompt, use -p "<prompt>". A subcommand takes no prompt, and an empty argument is not one.',
    'cli.argv.mode_flag.empty' => '--permission-mode expects a mode, but the value is empty',
    'cli.argv.mode_flag.flag' => '--permission-mode expects a mode, but the next argument is the option {option}',
    'cli.argv.mode_flag.missing' => '--permission-mode expects a mode, but the argument list ended',
    'cli.argv.model.empty' => '--model expects a model name, but the value is empty',
    'cli.argv.model.flag' => '--model expects a model name, but the next argument is the option {option}',
    'cli.argv.model.missing' => '--model expects a model name, but the argument list ended',
    'cli.argv.output_format.unsupported' => '--output-format {format}: unsupported output format',
    'cli.argv.prompt_is_flag' => '{option} expects a prompt, but the next argument is the option {value}',
    'cli.argv.resume.not_found' => '--resume: no stored session has the id or name "{target}"',
    'cli.argv.resume.store_unavailable' => '--resume: cannot open the session store: {error}',
    'cli.argv.root.flag' => '--root expects a directory, but the next argument is the option {option}',
    'cli.argv.root.missing' => '--root expects a directory, but the argument list ended',
    'cli.argv.root.no_such_directory' => '--root {root}: no such directory',
    'cli.argv.root_twice' => 'the project root is already {root}, but the argument {operand} also names one',
    'cli.argv.session_with_prompt' => '{flag} reopens an interactive session and cannot be combined with -p/run',
    'cli.argv.unexpected.many' => 'unexpected arguments: {operands}',
    'cli.argv.unexpected.one' => 'unexpected argument: {operands}',
    'cli.argv.unexpected_after_prompt.many' => 'unexpected arguments after the prompt: {operands}',
    'cli.argv.unexpected_after_prompt.one' => 'unexpected argument after the prompt: {operands}',
    'cli.argv.verb_flag.empty' => '{verb} {flag} expects a value, but the value is empty',
    'cli.argv.verb_flag.flag' => '{verb} {flag} expects a value, but the next argument is the option {option}',
    'cli.argv.verb_flag.missing' => '{verb} {flag} expects a value, but the argument list ended',
    'cli.argv.verb_flag.no_value' => '{verb} {flag} takes no value',
    'cli.attach.bad_url' => 'sugarcrush attach: --url {url} is not an http(s):// or ws(s):// address',
    'cli.attach.closed_during_handshake' => 'the server closed the connection during the handshake',
    'cli.attach.flag_does_not_apply' => 'sugarcrush attach: {flag} does not apply to attach',
    'cli.attach.handshake_incomplete' => 'the server did not complete a {subprotocol} WebSocket handshake',
    'cli.attach.json_does_not_apply' => 'sugarcrush attach: runs the TUI, which prints no document; --output-format json does not apply',
    'cli.attach.lock_holder_offer' => 'The sugarcrush server at {url} has it open: `sugarcrush attach {session}` drives it from this terminal.',
    'cli.attach.no_answer' => 'the server did not answer within {seconds} s',
    'cli.attach.no_server' => 'no server is running; start one with: sugarcrush serve --detach',
    'cli.attach.no_server_from' => 'no server is running from {dir}; start one with: sugarcrush serve --detach',
    'cli.attach.no_token' => 'no server token: set SUGARCRUSH_SERVER_TOKEN, or run `sugarcrush serve token` as the user the server runs as',
    'cli.attach.not_http' => 'the server answered the upgrade with something that is not HTTP',
    'cli.attach.refused' => 'the server refused the connection ({status} {phrase})',
    'cli.attach.refused_because' => 'the server refused the connection ({status} {phrase}): {reason}',
    'cli.attach.unexpected_operand' => 'sugarcrush attach {operand}: unexpected operand',
    'cli.attach.unreachable' => 'cannot reach the server: {error}',
    'cli.attach.usage' => 'Usage: sugarcrush attach [<session>] [--url <url>]',
    'cli.completion.no_shell' => 'completion: no shell given',
    'cli.completion.option.config' => 'Read settings and permissions from <file>',
    'cli.completion.option.continue' => 'Continue the most recent session',
    'cli.completion.option.help' => 'Show the help screen',
    'cli.completion.option.mode' => 'Permission mode to run under',
    'cli.completion.option.model' => 'Conversation model name (not a provider)',
    'cli.completion.option.output_format' => 'Output format: text or json',
    'cli.completion.option.prompt' => 'Run a single prompt and exit (one-shot mode)',
    'cli.completion.option.resume' => 'Resume a stored session by id or name',
    'cli.completion.option.root' => 'Use <dir> as the project root',
    'cli.completion.option.version' => 'Show the installed version',
    'cli.completion.unsupported_shell' => 'completion {shell}: unsupported shell',
    'cli.completion.usage' => 'Usage: sugarcrush completion {shells}',
    'cli.completion.verb.acp' => 'Run as an Agent Client Protocol agent for an editor',
    'cli.completion.verb.attach' => 'Run the TUI on a session of a running server',
    'cli.completion.verb.completion' => 'Emit a shell completion script',
    'cli.completion.verb.doctor' => 'Report on this installation and exit',
    'cli.completion.verb.mcp' => 'Inspect the project MCP configuration',
    'cli.completion.verb.models' => 'List the providers this install can select',
    'cli.completion.verb.run' => 'Run a single prompt and exit (alias for --prompt)',
    'cli.completion.verb.serve' => 'Run the WebSocket server for the web UI',
    'cli.completion.verb.session' => 'Manage stored sessions',
    'cli.completion.verb_argument' => '{verb} argument',
    'cli.doctor.absent' => 'absent',
    'cli.doctor.config_absent' => '{path} (absent — defaults apply)',
    'cli.doctor.config_invalid' => '{path}: invalid JSON ({error})',
    'cli.doctor.config_unreadable' => '{path} is not readable',
    'cli.doctor.curl_missing' => 'missing — the HTTP providers (openai, anthropic, sglang, custom) will fail',
    'cli.doctor.failed.many' => '{count} checks failed.',
    'cli.doctor.failed.one' => '{count} check failed.',
    'cli.doctor.loaded' => 'loaded',
    'cli.doctor.mcp_absent' => 'no {file} in this project',
    'cli.doctor.mcp_outside' => '{path} resolves outside the project tree; it is ignored',
    'cli.doctor.mcp_servers' => '{count} server(s) declared in {path}',
    'cli.doctor.mcp_untrusted' => '{path} is present but this root is not trusted; its servers are not started',
    'cli.doctor.mode' => 'mode {mode}',
    'cli.doctor.mode_split' => 'mode {tui} in the TUI, {headless} for -p and background sessions (built-in defaults)',
    'cli.doctor.no_problems' => 'No problems detected.',
    'cli.doctor.pdo' => 'pdo_{driver} {state}; ext-sqlite3 (declared in composer.json, unused by the code) {sqlite3}',
    'cli.doctor.pdo_unusable' => 'UNUSABLE — the session store cannot open its database: {error}',
    'cli.doctor.pdo_usable' => 'usable',
    'cli.doctor.php' => '{version} at {binary}',
    'cli.doctor.php_too_old' => '{version} at {binary} (sugarcrush requires PHP 8.3+)',
    'cli.doctor.provider' => '{provider} (model {model})',
    'cli.doctor.provider_none' => 'none selected — the offline echo backend will answer (set SUGARCRUSH_PROVIDER)',
    'cli.doctor.provider_shell' => 'shell-out backend ($SUGARCRUSH_BACKEND_CMD tier)',
    'cli.doctor.provider_unknown' => '{provider} is selected but is not a known provider — see `sugarcrush models`',
    'cli.doctor.server_ready' => 'pcntl, posix and ffi available — `serve` can start',
    'cli.doctor.server_refuses' => '`serve` will refuse to start: {problems}',
    'cli.doctor.sessions' => '{count} session(s) stored',
    'cli.doctor.unknown' => 'unknown',
    'cli.help.screen' => <<<'TXT'
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
terminal; each answers and exits except serve, which runs until stopped — and
attach, which is the exception to both: it runs the TUI on a running server's
session; acp runs turns for an editor, until the editor disconnects):
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
  serve [--host <ip>] [--port <n>] [--allow-remote] [--allowed-host <list>]
        [--allowed-origin <list>] [--web-root <dir>] [--no-web]
        [--allow-bypass] [--allow-root] [--detach] [--parent-pid <pid>]
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
                         TLS: put a reverse proxy in front of it. On 0.0.0.0
                         or :: the server answers to this machine's own
                         addresses, and the sign-in URL names them.
      --allowed-host <list>
                         Comma-separated extra host names (or host:port) the
                         server answers to, e.g. a DNS name for this machine.
                         Repeatable.
      --allowed-origin <list>
                         Comma-separated extra browser origins
                         (http(s)://host[:port]) allowed beside the server's
                         own. Repeatable.
      --web-root <dir>   Serve the web UI from <dir> instead of the installed
                         sugarcraft/sugar-crush-web package.
      --no-web           Serve the API and WebSocket only, no UI files.
      --allow-bypass     Let sessions run in bypass-permissions or dont-ask
                         (refused by default; the server's sessions start in
                         default unless --permission-mode says otherwise).
      --allow-root       Permit running as root (refused by default).
      --detach           Run in the background: print the URL and pid once
                         the port is bound, then exit; the server logs to
                         server.log in its state directory.
      --parent-pid <pid> Stop the server when process <pid> exits (for an
                         editor or TUI that starts a private server).
                         Refuses to start without ext-pcntl, ext-posix and
                         ext-ffi (see doctor). Exits 1 if the port is taken or
                         a server already runs from the same state directory.
  serve status           Report the running server: pid, URL, root, uptime and
                         whether GET /api/health answers. Exits 1 when none
                         runs.
  serve stop [--force]   Stop it: SIGTERM, up to 30 s to drain, then SIGKILL.
      --force            SIGKILL at once.
  serve logs [-f]        Print the end of a detached server's log.
  -f, --follow           Keep printing what it appends until it stops.
  serve url              Print a sign-in URL with a fresh one-time code.
  serve token [--rotate] Print the owner token bearer clients send.
      --rotate           Replace it; a running server keeps the old one until
                         it restarts.
  attach [<session>] [--url <url>]
                         Run the TUI on a session of a running sugarcrush
                         serve: the server runs every turn and its tools, and
                         this terminal shows them and answers the permission
                         questions they raise. <session> is an id, a name or a
                         unique id prefix of one of the server's sessions;
                         without it a new one is created. The server is the
                         one serve status reports, its token taken from
                         SUGARCRUSH_SERVER_TOKEN or the state directory's
                         token file. Quitting detaches; a running turn goes
                         on. Exits 1 if no server answers.
      --url <url>        Attach to the server at <url> (the address serve
                         prints) instead.
  acp                    Run as an Agent Client Protocol agent: the editor
                         (Zed, JetBrains, Neovim) starts it and speaks
                         JSON-RPC on its stdin and stdout. Sessions open in
                         the editor's project root, turns run here with this
                         install's provider and tools, and the editor answers
                         their permission questions. Not for a terminal: stdout
                         carries only the protocol (everything else goes to
                         stderr). Exits 0 when the editor closes stdin.
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
   SUGARCRUSH_CONTEXT_PRUNING
                          auto, manual or off: how a session prunes its
                          context when /pruning has not chosen (default auto).
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
                          saving durable facts as notes after a turn, and
                          the dream pass folding the compaction journal
                          into them.
   SUGARCRUSH_DISABLE_MODEL_METADATA
                          Any value other than empty or 0 stops reading and
                          refreshing the LiteLLM model database that sizes and
                          prices models with no built-in figure.
   SUGARCRUSH_DISABLE_SYMBOL_MAP
                          Any value other than empty or 0 keeps the ranked
                          symbol map out of the system prompt.
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
   SUGARCRUSH_SERVER_ALLOWED_HOSTS
                          Comma-separated extra host names `serve` answers to
                          (--allowed-host wins).
   SUGARCRUSH_SERVER_WEB_ROOT
                          Directory `serve` serves the web UI from (--web-root
                          wins).
   SUGARCRUSH_SERVER_TOKEN
                          The token `serve` requires, instead of the one in
                          its state directory (at least 32 characters; for
                          containers).
   SUGARCRUSH_SERVER_DIR  The directory `serve` keeps its token, lock,
                          discovery record and log in (default
                          ~/.sugar-crush/server; created 0700).
   SUGARCRUSH_SERVER_PARENT_PID
                          A process `serve` stops with (--parent-pid wins).
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

TXT,
    'cli.launch.agent_preset_skip' => 'skipped %d agent preset file%s: %s',
    'cli.launch.clip_suffix' => '… (clipped; full text on stderr)',
    'cli.launch.command_skip' => '%d command file%s could not be read and %s skipped; set %s=1 to list %s',
    'cli.launch.instruction_deferral' => '%d instruction file%s %s left out of the system prompt — %s',
    'cli.launch.mcp_partial_start' => 'MCP tools from %s are incomplete: the server list could not be fully started (%s); this session has only the tools that did load',
    'cli.launch.mcp_partial_start_log' => 'sugarcrush: MCP config %s could not be fully started (%s: %s); continuing without it.',
    'cli.launch.mcp_server_added' => '"%s" is new (%s)',
    'cli.launch.mcp_server_changed' => '"%s" changed (was: %s; now: %s)',
    'cli.launch.mcp_server_refused' => 'MCP servers in %s not started — they differ from what you trusted: %s. Review the file, then run "sugarcrush mcp trust" in the project to approve the current entries',
    'cli.launch.memory_legacy_bound' => '%d project memory note%s written before notes were kept per project %s now bound to this project (%s) and shown nowhere else, in %s; move a file out if it belongs to another project',
    'cli.launch.narrowed_grant' => '%d agent tool grant%s %s narrowed by this session\'s allowedTools/disabledTools',
    'cli.launch.narrowed_grant_overflow' => '…and %d more narrowed grant%s this transcript could not fit; the full list is on stderr',
    'cli.launch.nonsense_limit' => '%s is %s, which is not %s; %s',
    'cli.launch.nonsense_steps_consequence' => 'the shipped per-turn step ceiling applies',
    'cli.launch.nonsense_steps_expected' => 'a positive whole number',
    'cli.launch.nonsense_tokens_consequence' => 'no output ceiling is sent, so the provider\'s own default applies',
    'cli.launch.nonsense_tokens_expected' => 'a positive token count',
    'cli.launch.overflow' => '…and %d more launch warning%s this transcript could not fit; the full list is on stderr',
    'cli.launch.project_tier_refusal' => 'ignoring %s — %s',
    'cli.launch.retention_detail' => <<<'TXT'
sugarcrush:   %s (last used %s UTC, %d %s)

TXT,
    'cli.launch.retention_summary' => 'retention removed %d unnamed %s untouched for %d+ days (ids on stderr)',
    'cli.launch.skill_budget_deferral' => '%d enabled skill%s %s over the skill budget, so only %s heading and a pointer reach the system prompt — %s',
    'cli.launch.skill_skip' => '%d skill file%s %s not loaded (unreadable, or shadowed by a same-named skill); set %s=1 to list %s',
    'cli.launch.tool_removal' => '%s (disabledTools) disabled %d of the %d tools your own settings left — %s — %s',
    'cli.launch.tool_removal_leaving' => 'leaving: ',
    'cli.launch.tool_removal_leaving_none' => 'leaving no tools at all',
    'cli.launch.tui_error_log_discarded' => '%s could not be written and no private log file could be prepared either, so this session\'s diagnostics are discarded',
    'cli.launch.tui_error_log_fallback' => '%s could not be written, so this session\'s diagnostics go to %s',
    'cli.launch_source.built_in_default' => 'the built-in default',
    'cli.launch_source.preset_mode' => 'this agent preset\'s permissionMode',
    'cli.mcp.auth.bad_timeout' => 'mcp auth login: --timeout needs a positive number of seconds',
    'cli.mcp.auth.interactive' => 'mcp auth login: login is interactive by design',
    'cli.mcp.auth.interactive_hint' => 'Run it as a plain command: it prints a URL, waits for your browser, and stores the tokens.',
    'cli.mcp.auth.no_action' => 'mcp auth: no action given',
    'cli.mcp.auth.no_server' => 'mcp auth login: no server URL given',
    'cli.mcp.auth.unknown_action' => 'mcp auth {action}: unknown action',
    'cli.mcp.auth.usage' => 'Usage: sugarcrush mcp auth login <server> [token-url] [authorize-url] [registration-url] [-- --timeout N]',
    'cli.mcp.config.invalid_json' => 'is not valid JSON ({error})',
    'cli.mcp.config.no_servers_object' => 'has no "mcpServers" object',
    'cli.mcp.config.not_a_config' => 'is not valid JSON with an "mcpServers" object',
    'cli.mcp.config.pins_unwritable' => 'the trust record {path} could not be written ({error})',
    'cli.mcp.config.unreadable' => 'could not be read',
    'cli.mcp.import.invalid_json' => 'is not valid JSON: {error}',
    'cli.mcp.import.no_file' => 'mcp import: no file given',
    'cli.mcp.import.no_source' => 'mcp import: no source given',
    'cli.mcp.import.not_an_object' => 'the file is not a JSON object',
    'cli.mcp.import.nothing_written' => 'nothing was written — paste the block into {file} and add the root to "trustedProjectMcp" in {config} to start these servers',
    'cli.mcp.import.unexpected_operand' => 'mcp import: unexpected operand {operand}',
    'cli.mcp.import.unknown_source' => 'mcp import {source}: unknown source',
    'cli.mcp.import.unreadable' => 'mcp import: cannot read {path}',
    'cli.mcp.import.unreadable_hint' => 'Check the path; nothing has been translated or written.',
    'cli.mcp.import.usage' => 'Usage: sugarcrush mcp import claude|opencode <path>',
    'cli.mcp.import.valid_sources' => 'Valid sources are: {sources}.',
    'cli.mcp.list.absent' => 'No {file} in this project (looked for {path}).',
    'cli.mcp.list.empty' => '{path} declares no servers.',
    'cli.mcp.list.outside' => '{path} resolves outside the project tree and is ignored.',
    'cli.mcp.list.untrusted' => <<<'TXT'
{path} is present but this project root is not trusted,
so its servers are not started and are not listed. Add the root to
"trustedProjectMcp" in {config} to opt in.
TXT,
    'cli.mcp.list.wire_name' => '  server "{server}" is written {prefix} on the wire (permission rules match: {prefix}<tool>)',
    'cli.mcp.no_action' => 'mcp: no action given',
    'cli.mcp.trust.absent' => 'No {file} in this project (looked for {path}); nothing to trust.',
    'cli.mcp.trust.heading' => 'Trusting the MCP servers in {path}:',
    'cli.mcp.trust.malformed' => '(not recorded: the entry is malformed)',
    'cli.mcp.trust.not_granted' => 'mcp trust: could not add {root} to "trustedProjectMcp" in {config}; nothing was recorded',
    'cli.mcp.trust.not_recorded' => 'mcp trust: {error}',
    'cli.mcp.trust.outside' => '{path} resolves outside the project tree and cannot be trusted.',
    'cli.mcp.trust.recorded.many' => '{count} servers recorded; the next launch starts these and refuses any later change to them.',
    'cli.mcp.trust.recorded.one' => '{count} server recorded; the next launch starts these and refuses any later change to them.',
    'cli.mcp.trust.unexpected_operand' => 'sugarcrush mcp trust: unexpected operand {operand}',
    'cli.mcp.trust.usage' => 'Usage: sugarcrush mcp trust (run it in the project, or pass the project directory first)',
    'cli.mcp.unknown_action' => 'mcp {action}: unknown action',
    'cli.mcp.usage' => 'Usage: sugarcrush mcp list | sugarcrush mcp trust | sugarcrush mcp auth login <server> | sugarcrush mcp import claude|opencode <path>',
    'cli.models.none_configured' => 'No providers are configured.',
    'cli.models.none_selected' => 'No provider is selected; set SUGARCRUSH_PROVIDER or use Ctrl+P "Switch model".',
    'cli.models.selected_legend' => '* = selected (SUGARCRUSH_PROVIDER or {path}).',
    'cli.noninteractive.answer_not_json' => 'the answer could not be encoded as JSON: {error}',
    'cli.noninteractive.no_prompt' => 'no prompt given - pass -p "<prompt>" or `sugarcrush run "<prompt>"`',
    'cli.noninteractive.no_reason_given' => 'no reason given',
    'cli.noninteractive.offline_default' => 'no provider configured (SUGARCRUSH_PROVIDER, SUGARCRUSH_BACKEND_CMD and SUGARCRUSH_BACKEND_CMD_STREAM unset, none persisted); answering from the offline echo provider.',
    'cli.noninteractive.provider_refusing' => 'refusing to silently answer from a different backend on a one-shot run — fix the provider configuration, or {remedy}.',
    'cli.noninteractive.provider_remedy_config' => 'remove the "provider" entry from {path} — the persisted Ctrl+P "Switch model" choice this run selected it from — to select the fallback deliberately',
    'cli.noninteractive.provider_remedy_env' => 'unset SUGARCRUSH_PROVIDER to select the fallback deliberately',
    'cli.noninteractive.provider_unusable' => 'provider \'{provider}\' is unusable: {error}',
    'cli.noninteractive.refusal' => '[{kind}] {tool} was not run - {reason}',
    'cli.noninteractive.session_end_hook_refused' => 'SessionEnd hook "{hook}" refused: {reason}',
    'cli.noninteractive.session_end_refused' => 'SessionEnd hook refused: {reason}',
    'cli.noninteractive.stdin_truncated' => 'piped stdin exceeds 10MB cap; truncating.',
    'cli.permission.arguments_truncated' => '… (truncated — {bytes} more bytes NOT shown)',
    'cli.permission.confirm' => 'Run it? [y/N] ',
    'cli.permission.no_tty' => 'a tool call needs your permission, and stdin is not a terminal, so there is nobody to ask — refusing it.',
    'cli.permission.no_tty_remedy' => <<<'TXT'
  Run this from a terminal to be prompted, or give the run a policy that decides
  without asking: --permission-mode <mode> (bypass-permissions runs everything),
  or a permissionRules entry for {tool} in .sugar-crush/config.json.
TXT,
    'cli.permission.question' => 'a tool call needs your permission.',
    'cli.permission.refused' => 'refused {tool}.',
    'cli.permission.stdin_ended' => 'stdin ended before the question was answered — refusing {tool}.',
    'cli.permission.unrenderable_arguments' => '<arguments could not be rendered>',
    'cli.refuse.bad_mode' => '{source} is \'{value}\', which is not a permission mode (expected one of: {valid}). Refusing to start rather than fall back to the permissive default.',
    'cli.refuse.claude_mcp_arg_entry' => 'claudeMcpArgs carries a non-scalar entry; argv strings only',
    'cli.refuse.claude_mcp_args' => 'claudeMcpArgs must be a JSON array of scalars in the user config',
    'cli.refuse.claude_mcp_binary' => 'claudeMcpBinary must be a string path in the user config',
    'cli.refuse.claude_mcp_env' => 'claudeMcpEnv must be a JSON object in the user config',
    'cli.refuse.claude_mcp_env_entry' => 'claudeMcpEnv must map string keys to string values',
    'cli.refuse.config_bom' => 'it starts with a UTF-8 byte-order mark, which JSON does not permit — re-save the file as UTF-8 without a BOM',
    'cli.refuse.config_dangling' => '{path} exists but is not a readable file (it is a symlink that does not resolve to one). Refusing to start rather than run with an unknown permission policy.',
    'cli.refuse.config_is_directory' => '{path} exists but is not a readable file (it is a directory). Refusing to start rather than run with an unknown permission policy.',
    'cli.refuse.config_not_json' => '{path} is not usable JSON ({error}). Refusing to start rather than run with an unknown permission policy.',
    'cli.refuse.config_not_object' => 'the top level is not a JSON object',
    'cli.refuse.config_unreachable' => '{path} cannot be reached: {reason}, so whether a permission policy is configured there is unknowable. Refusing to start rather than run with an unknown permission policy.',
    'cli.refuse.config_unreadable' => '{path} exists but could not be read (check its permissions). Refusing to start rather than run with an unknown permission policy.',
    'cli.refuse.dangling_directory' => '{dir} is a symlink that does not resolve to a directory this process can search',
    'cli.refuse.foreign_owner' => '{path} belongs to uid {uid}, not to the account this session is running as, so the permission policy and hook chain it carries are somebody else\'s. Refusing to start rather than run another account\'s policy.',
    'cli.refuse.hook_chain' => '{error} Refusing to start rather than run with a hook chain that is not the one configured.',
    'cli.refuse.hooks_unreachable' => '{path} cannot be reached: {reason}, so whether hooks are configured there is unknowable. Refusing to start rather than run with an unknown hook chain.',
    'cli.refuse.max_cost_env' => '$SUGARCRUSH_MAX_COST is \'{value}\', which is not a spend ceiling. Expected a positive number of US dollars (fractional allowed, a leading $ accepted), for example 5 or $2.50. Zero and negative are refused rather than read as "no cap" because they are the opposite request; a figure too large to represent (1e309, i.e. infinity) is refused because it would install a cap that never triggers. Unset the variable for no cap. Refusing to start rather than run uncapped with a ceiling you asked for.',
    'cli.refuse.max_cost_setting' => 'maxCostUsd in your settings is {value}, which is not a spend ceiling. Expected a positive number of US dollars, for example 5 or 2.5. Remove the key for no cap. Refusing to start rather than run uncapped with a ceiling you asked for.',
    'cli.refuse.no_home' => 'this process cannot determine which home directory is yours ($HOME is unset, $USERPROFILE is unset, and there is no passwd entry for its uid), so the permission policy and hook chain in ~/.sugar-crush cannot be located. Refusing to start rather than read either of them out of a world-writable fallback directory — export HOME to the account this session belongs to.',
    'cli.refuse.no_project_root' => 'cannot determine a project root — the process working directory is unavailable (deleted or unreadable). Pass --root <dir>.',
    'cli.refuse.not_searchable' => '{dir} is not searchable by this process',
    'cli.refuse.world_writable' => '{path} is writable by every account on this machine, so the permission policy and hook chain it carries are not this user\'s. Refusing to start rather than run policy anyone could have written — `chmod o-w {path}`.',
    'cli.serve.already_running' => 'a server is already running from {dir} — stop it with: sugarcrush serve stop',
    'cli.serve.already_running_pid' => 'a server is already running from {dir} (pid {pid}, {url}) — stop it with: sugarcrush serve stop',
    'cli.serve.also' => 'Also: {problems}.',
    'cli.serve.announce.background' => 'sugarcrush serve: running in the background on {url} (pid {pid})',
    'cli.serve.announce.code_detached' => '                   (one-time code, valid {seconds} s; a fresh one: sugarcrush serve url)',
    'cli.serve.announce.code_foreground' => '                   (one-time code, valid {seconds} s; Ctrl+C stops the server)',
    'cli.serve.announce.listening' => 'sugarcrush serve: listening on {url} (pid {pid})',
    'cli.serve.announce.log' => '  log:             {log}',
    'cli.serve.announce.mode' => '  permission mode: {mode}',
    'cli.serve.announce.mode_bypass' => '  permission mode: {mode} (clients may choose bypass)',
    'cli.serve.announce.no_interface' => '                   (no interface address of this machine was found: use the one other machines reach it at)',
    'cli.serve.announce.remote' => '  !! Plain HTTP on {host}: the token, sign-in code and session cookie cross the network in cleartext — use an SSH tunnel or a TLS reverse proxy (docs/SERVER.md, "Remote access").',
    'cli.serve.announce.root' => '  root:            {root}',
    'cli.serve.announce.sign_in' => '  sign in:         {url}',
    'cli.serve.announce.stop' => '  stop:            sugarcrush serve stop',
    'cli.serve.announce.web' => '  web UI:          {dir}',
    'cli.serve.announce.web_missing' => '  web UI:          not installed (composer require sugarcraft/sugar-crush-web)',
    'cli.serve.draining.many' => 'sugarcrush serve: draining ({reason}) — waiting up to {seconds} s for {count} running turns; signal again to stop now',
    'cli.serve.draining.one' => 'sugarcrush serve: draining ({reason}) — waiting up to {seconds} s for {count} running turn; signal again to stop now',
    'cli.serve.failed_to_start' => 'the background server failed to start',
    'cli.serve.flag_not_for_action' => 'sugarcrush serve {action}: {flag} does not apply to this action',
    'cli.serve.flag_not_for_start' => 'sugarcrush serve: {flag} does not apply to starting a server',
    'cli.serve.logs.follow_json' => 'sugarcrush serve logs: -f streams text; it does not combine with --output-format json',
    'cli.serve.logs.no_log' => 'no log',
    'cli.serve.logs.no_log_at' => 'sugarcrush serve logs: no log at {path} (only a detached server writes one)',
    'cli.serve.no_home' => 'cannot determine a home directory this user owns for the server state; set SUGARCRUSH_SERVER_DIR',
    'cli.serve.no_report' => 'the background server did not report within {seconds} s; see {log}',
    'cli.serve.no_report_channel' => 'cannot create the channel the background server reports on',
    'cli.serve.no_server' => 'no server is running',
    'cli.serve.none' => '(none)',
    'cli.serve.reason_see_log' => '{reason}; see {log}',
    'cli.serve.status.locked' => 'running: a server holds the lock in {dir} but has not published its record yet',
    'cli.serve.status.mode_background' => '  mode:    background, log {log}',
    'cli.serve.status.mode_foreground' => '  mode:    foreground',
    'cli.serve.status.not_running' => 'not running',
    'cli.serve.status.root' => '  root:    {root}',
    'cli.serve.status.running' => 'running: pid {pid}, {url} (health: {health})',
    'cli.serve.status.stale' => 'not running (stale record for pid {pid} left in {path})',
    'cli.serve.status.started' => '  started: {started} (up {uptime})',
    'cli.serve.status.version' => '  version: {version}',
    'cli.serve.stop.did_not_exit' => 'the server did not exit',
    'cli.serve.stop.failed' => 'sugarcrush serve stop: {reason}',
    'cli.serve.stop.killed' => 'stopped the server (pid {pid}) with SIGKILL',
    'cli.serve.stop.no_posix' => 'sugarcrush serve stop: ext-posix is missing, so the server cannot be signalled',
    'cli.serve.stop.pid_did_not_exit' => 'sugarcrush serve stop: pid {pid} did not exit',
    'cli.serve.stop.stopped' => 'stopped the server (pid {pid})',
    'cli.serve.stop.unidentified' => 'a server holds the lock in {dir} but published no record, so it cannot be identified to stop',
    'cli.serve.stopping' => 'sugarcrush serve: stopping ({reason})',
    'cli.serve.token.env_print' => 'sugarcrush serve token: the server token comes from SUGARCRUSH_SERVER_TOKEN here; it is not printed',
    'cli.serve.token.env_rotate' => 'sugarcrush serve token: the server token comes from SUGARCRUSH_SERVER_TOKEN here; unset it to rotate the stored one',
    'cli.serve.token.not_reloaded' => 'sugarcrush serve token: the running server (pid {pid}) could not be told and keeps accepting the old token until it restarts: sugarcrush serve stop && sugarcrush serve --detach',
    'cli.serve.token.reloaded.many' => 'sugarcrush serve token: the running server (pid {pid}) now accepts only the new token; every client was signed out ({count} connections closed)',
    'cli.serve.token.reloaded.one' => 'sugarcrush serve token: the running server (pid {pid}) now accepts only the new token; every client was signed out ({count} connection closed)',
    'cli.serve.unexpected_operand' => 'sugarcrush serve {action} {operand}: unexpected operand',
    'cli.serve.unknown_action' => 'sugarcrush serve {action}: unknown action',
    'cli.serve.url.failed' => 'sugarcrush serve url: {reason}',
    'cli.serve.url.no_answer' => 'the server did not answer on {path}',
    'cli.serve.url.one_time_code' => '(one-time code, valid {seconds} s)',
    'cli.serve.url.token_refused' => 'the server refused this token (was it started with a different SUGARCRUSH_SERVER_TOKEN?)',
    'cli.serve.usage' => 'Usage: sugarcrush serve [--host <ip>] [--port <n>] [--allow-remote] [--allowed-host <hosts>] [--allowed-origin <origins>] [--web-root <dir>] [--no-web] [--allow-bypass] [--allow-root] [--detach] [--parent-pid <pid>] | serve status | serve stop [--force] | serve logs [-f] | serve url | serve token [--rotate]',
    'cli.serve.workspace_unavailable' => 'cannot open the workspace: {error}',
    'cli.session.accepts' => 'session {action} accepts: {flags}.',
    'cli.session.ambiguous' => 'session {action} {target}: ambiguous id prefix, {count} sessions match',
    'cli.session.archived_tag' => '[archived]',
    'cli.session.bad_limit' => 'session list --limit {limit}: not a positive whole number',
    'cli.session.candidates' => 'Candidates: {candidates}. Type more of the id.',
    'cli.session.delete.done' => 'Deleted session {id}',
    'cli.session.delete.done_with_children' => 'Deleted session {id} and {count} child session(s)',
    'cli.session.flag.already_archived' => 'Session {id} is already archived',
    'cli.session.flag.already_pinned' => 'Session {id} is already pinned',
    'cli.session.flag.already_unarchived' => 'Session {id} is already unarchived',
    'cli.session.flag.already_unpinned' => 'Session {id} is already unpinned',
    'cli.session.flag.archived' => 'Archived session {id}',
    'cli.session.flag.pinned' => 'Pinned session {id}',
    'cli.session.flag.unarchived' => 'Unarchived session {id}',
    'cli.session.flag.unpinned' => 'Unpinned session {id}',
    'cli.session.flag_does_not_apply' => 'session {action}: {flag} does not apply to this action',
    'cli.session.limit_usage' => 'Usage: sugarcrush session list --limit <N>, where N is 1 or more.',
    'cli.session.no_action' => 'session: no action given',
    'cli.session.no_target' => 'session {action}: no session id given',
    'cli.session.none_stored' => 'No sessions stored.',
    'cli.session.not_found' => 'session {target}: no such session',
    'cli.session.not_found_message' => 'no such session: {target}',
    'cli.session.rename.done' => 'Renamed session {id} to "{title}"',
    'cli.session.rename.no_title' => 'session rename: no title given',
    'cli.session.rename.usage' => 'Usage: sugarcrush session rename <target> <title…>',
    'cli.session.show.archived' => 'archived {at}',
    'cli.session.show.flags' => '- flags: {flags}',
    'cli.session.show.id' => '- id: {id}',
    'cli.session.show.kind' => '- kind: {kind}',
    'cli.session.show.kind_child' => '- kind: {kind} (parent {parent})',
    'cli.session.show.model' => '- model: {model}',
    'cli.session.show.no_transcript' => '(no transcript stored)',
    'cli.session.show.pinned' => 'pinned',
    'cli.session.show.updated' => '- updated: {updated} · {turns} turn(s)',
    'cli.session.takes_no_options' => 'session {action} takes no options.',
    'cli.session.target_usage' => 'Usage: sugarcrush session {action} <id|prefix|name> — run `sugarcrush session list --all` for the ids.',
    'cli.session.unexpected_operand' => 'session {action} {operand}: unexpected operand',
    'cli.session.unknown_action' => 'session {action}: unknown action',
    'cli.session.unnamed' => '(unnamed)',
    'cli.session.usage' => 'Usage: sugarcrush session list [--all|--archived|--children] [--limit N] | show <target> | rename <target> <title…> | delete <target> [--with-children] | pin|unpin|archive|unarchive <target> — <target> is an id, a name or a unique id prefix.',
    'cli.sub.takes_no_arguments' => 'Usage: sugarcrush {verb} — this subcommand takes no arguments.',
    'cli.sub.unexpected_operand' => '{verb} {operand}: unexpected operand',
    'cli.sub.unknown_subcommand' => '{verb}: unknown subcommand',
    'cli.sub.valid_subcommands' => 'Valid subcommands are: {verbs}.',
    'cli.warn.auto_review_no_provider' => 'autoReview is on, but no provider is configured to review with; auto mode denies flagged calls as before.',
    'cli.warn.auto_review_not_bool' => 'autoReview in your config.json or settings.json must be true or false; the auto exec reviewer stays off.',
    'cli.warn.empty_setting_ignored' => '{key} in {path} is empty, so it was ignored rather than allowed to discard the {key} configured in {carried}',
    'cli.warn.enabled_skill_disabled' => 'enabled skill \'{skill}\' is disabled by configuration; it stays out of the system prompt',
    'cli.warn.enabled_skill_missing' => 'enabled skill \'{skill}\' was not found; it stays out of the system prompt',
    'cli.warn.enabled_skill_not_name' => 'enabledSkills[{index}] is not a skill name; entry skipped',
    'cli.warn.enabled_skill_unreadable' => 'enabled skill \'{skill}\' could not be read ({error}); it stays out of the system prompt',
    'cli.warn.enabled_skills_not_list' => 'enabledSkills is not a list of skill names; no skill bodies are enabled in the system prompt',
    'cli.warn.foreign_presets_unavailable' => 'foreign agent presets unavailable ({error}); continuing without them',
    'cli.warn.hook_file_untrusted' => '{file} was NOT loaded: honouring a project hook file means running shell this repository\'s author wrote, every time you open it. Add "{root}" to "{key}" in {config} to opt in',
    'cli.warn.no_tools_left' => 'allowedTools/disabledTools left no tools at all, so the model will be given an empty tool set and can do nothing but talk',
    'cli.warn.persisted_provider_unavailable' => 'persisted provider \'{provider}\' unavailable ({error}); falling back to echo',
    'cli.warn.presets_unavailable' => 'agent presets unavailable ({error}); continuing with the built-in agents',
    'cli.warn.provider_unavailable' => 'provider \'{provider}\' unavailable ({error}); falling back to echo',
    'cli.warn.rule_bad_pattern' => '{key}[{index}] (\'{pattern}\') {reason}, so it is not a Tool or Tool(argument-pattern) pattern; rule skipped rather than loaded as a pattern that would match nothing',
    'cli.warn.rule_no_action' => '{key}[{index}] (\'{pattern}\') has no valid \'action\' (expected allow, deny or ask); rule skipped rather than coerced',
    'cli.warn.rule_no_pattern' => '{key}[{index}] has no string \'pattern\'; rule skipped',
    'cli.warn.rules_not_list' => '{key} is not a list of rules; no rules were loaded',
    'cli.warn.rules_null' => '{key} is present but null rather than a list of rules; no rules were loaded',
    'cli.warn.trust_entry_not_path' => '{key}[{index}] is not a project path; entry skipped',
    'cli.warn.trust_entry_relative' => '{key}[{index}] is \'{entry}\', which is relative to whatever directory sugarcrush was started in — it would trust EVERY repository you run it from, not one. Write the absolute path (or a ~/-rooted one); entry skipped',
    'cli.warn.trust_list_not_list' => '{key} is not a list of project paths; {consequence}',
    'cli.warn.untrusted_commands' => 'no project command file may run a shell',
    'cli.warn.untrusted_hooks' => 'no project hook file was trusted',
    'cli.warn.untrusted_mcp' => 'no project MCP config was trusted',
    'cli.warn.untrusted_settings' => 'no project settings file may contribute a setting',
    // --- end cli (W11-a) ---
    // --- registries (W11-b) ---
    'cmd.agents.cell.active' => '● active',
    'cmd.agents.cell.inactive' => '○ inactive',
    'cmd.agents.column.agent' => 'Agent',
    'cmd.agents.column.description' => 'Description',
    'cmd.agents.column.status' => 'Status',
    'cmd.agents.description' => 'List active agents, inspect one by name, or open a running one\'s Agent View',
    'cmd.agents.detail.agent' => 'Agent: {name}',
    'cmd.agents.detail.description' => 'Description: {description}',
    'cmd.agents.detail.hooks' => 'Hooks:       {hooks}',
    'cmd.agents.detail.model' => 'Model:       {model}',
    'cmd.agents.detail.provider' => 'Provider:     {provider}',
    'cmd.agents.detail.skills' => 'Skills:      {skills}',
    'cmd.agents.detail.status' => 'Status:       {status}',
    'cmd.agents.detail.tools' => 'Tools:       {tools}',
    'cmd.agents.heading' => 'Active Agents:',
    'cmd.agents.idle' => '{count} agent(s) registered and idle — use /agent <name> for details.',
    'cmd.agents.label' => 'Switch agent',
    'cmd.agents.none-configured' => 'No active agents configured.',
    'cmd.agents.none-configured-hint' => 'Use the agents configuration file to define agents.',
    'cmd.agents.none-working' => 'No agents are working right now.',
    'cmd.agents.status.active' => 'active',
    'cmd.agents.status.inactive' => 'inactive',
    'cmd.agents.unknown' => 'Unknown agent: {name}',
    'cmd.agents.unknown-hint' => 'Use /agents to see available agents.',
    'cmd.bang.refused.plan' => 'plan mode runs only commands it can prove read-only',
    'cmd.bang.refused.rule' => 'a permission rule denies Bash for it',
    'cmd.bg.description' => 'Run a task in a background session',
    'cmd.bg.hint' => '<task>',
    'cmd.branch.description' => 'Fork the current session into a new branch',
    'cmd.branch.label' => 'Branch session',
    'cmd.btw.description' => 'Ask the title model a side question about this conversation, kept out of it',
    'cmd.btw.hint' => '<question>',
    'cmd.budget.description' => 'Show this session\'s reported spend, or cap it',
    'cmd.budget.hint' => '[amount|off]',
    'cmd.category.agents' => 'Agents',
    'cmd.category.app' => 'App',
    'cmd.category.appearance' => 'Appearance',
    'cmd.category.custom' => 'Custom',
    'cmd.category.layout' => 'Layout',
    'cmd.category.mcp' => 'MCP',
    'cmd.category.memory' => 'Memory',
    'cmd.category.model' => 'Model',
    'cmd.category.rules' => 'Rules',
    'cmd.category.session' => 'Session',
    'cmd.category.tools' => 'Tools',
    'cmd.category.workflow' => 'Workflow',
    'cmd.clear.description' => 'Clear the transcript, keeping this session',
    'cmd.compact.description' => 'Manually compact chat history to save context',
    'cmd.compact.hint' => '[--self] [focus]',
    'cmd.compress.description' => 'Ask the model to compress a closed part of the conversation into a summary',
    'cmd.compress.hint' => '[focus]',
    'cmd.context.breaks' => 'Cache breaks: {breaks} this session{newest}. One after a prune or a compression is that rewrite\'s price; two in a row mean a rewrite is not byte-stable.',
    'cmd.context.breaks.newest' => ' — the newest read {to}% of its prompt from cache, after {from}% on the request before',
    'cmd.context.breaks.none' => 'Cache breaks: none this session.',
    'cmd.context.breaks.unmeasured' => 'Cache breaks: not measured — this backend does not track its requests\' cache reuse.',
    'cmd.context.cache' => 'Prompt cache: {last}% of the last prompt was read from cache; {session}% across {replies}.',
    'cmd.context.cache.none' => 'Prompt cache: no reply has reported a cache split yet.',
    'cmd.context.description' => 'Show what fills the context window: prompt layers, tools, history, cache',
    'cmd.context.free' => 'Free: ~{tokens}',
    'cmd.context.history' => 'History: ~{tokens} ({messages} sent to the model; {rows} never sent)',
    'cmd.context.largest' => 'Largest messages:',
    'cmd.context.none' => 'none',
    'cmd.context.pruned' => 'Pruned: ~{tokens} out of what the model is sent ({mode}) — {parts}. The transcript keeps every row.',
    'cmd.context.pruned.files' => '; files: {files}',
    'cmd.context.pruned.mode' => 'mode {mode}, {source}',
    'cmd.context.pruned.mode.configured' => 'configured',
    'cmd.context.pruned.mode.session' => 'set for this session',
    'cmd.context.pruned.nothing' => 'Pruned: nothing ({mode}) — /sweep prunes the last turn\'s tool outputs.',
    'cmd.context.pruned.row' => '{reason} by {by}',
    'cmd.context.pruned.summary' => 'summary b{id} (~{compressed} → ~{summary})',
    'cmd.context.system' => 'System prompt: ~{tokens} ({bytes}, {sections})',
    'cmd.context.system.unmeasured' => 'System prompt: not measured — this backend does not report the prompt it sends.',
    'cmd.context.tools' => 'Tool schemas: ~{tokens} ({tools})',
    'cmd.context.tools.unmeasured' => 'Tool schemas: not measured — this backend does not say which tools it sends.',
    'cmd.context.total' => 'Context: ~{used} of {window} tokens ({percent}%) for the next request — estimates, script-weighted.',
    'cmd.context.unit.message' => 'message',
    'cmd.context.unit.messages' => 'messages',
    'cmd.context.unit.reporting-replies' => 'reporting replies',
    'cmd.context.unit.reporting-reply' => 'reporting reply',
    'cmd.context.unit.section' => 'section',
    'cmd.context.unit.sections' => 'sections',
    'cmd.context.unit.state-row' => 'superseded state row',
    'cmd.context.unit.state-rows' => 'superseded state rows',
    'cmd.context.unit.tool' => 'tool',
    'cmd.context.unit.tool-output' => 'tool output',
    'cmd.context.unit.tool-outputs' => 'tool outputs',
    'cmd.context.unit.tools' => 'tools',
    'cmd.context.unit.ui-row' => 'UI-only row',
    'cmd.context.unit.ui-rows' => 'UI-only rows',
    'cmd.custom.description' => 'Custom command: {name}',
    'cmd.decompress.description' => 'Send a compressed section in full again (no argument: list the sections)',
    'cmd.decompress.hint' => '[bN]',
    'cmd.diff.description' => 'Show what changed in the files since a checkpoint',
    'cmd.diff.hint' => '[n]',
    'cmd.docs.description' => 'Open the documentation',
    'cmd.docs.label' => 'Open docs',
    'cmd.editor.description' => 'Compose the prompt in $VISUAL or $EDITOR',
    'cmd.editor.empty' => 'editor: nothing was written, so the draft is unchanged',
    'cmd.editor.failed' => 'editor: {editor} exited with status {status}; the draft is unchanged',
    'cmd.editor.hint' => '[text]',
    'cmd.editor.no-temp-file' => 'editor: could not create a temporary file in {dir} to edit the prompt in',
    'cmd.editor.not-started' => 'editor: {editor} could not be started ({error}); the draft is unchanged',
    'cmd.exit.description' => 'Quit the app',
    'cmd.exit.label' => 'Exit',
    'cmd.file.empty' => 'Command file has an empty template body: {path}',
    'cmd.file.malformed' => 'Malformed frontmatter in {path}: {error}',
    'cmd.file.not-bool' => 'Frontmatter \'{key}\' must be a boolean in {path}',
    'cmd.file.not-found' => 'Command file not found: {path}',
    'cmd.file.not-mapping' => 'Frontmatter must be a YAML mapping in {path}',
    'cmd.file.not-string' => 'Frontmatter \'{key}\' must be a string in {path}',
    'cmd.file.unreadable' => 'Failed to read command file: {path}',
    'cmd.file.unsafe-name' => 'Unsafe command name: {name}',
    'cmd.fork.description' => 'Clone this conversation into a background session',
    'cmd.fork.hint' => '<prompt>',
    'cmd.goal.description' => 'Work until a condition is met, judged by the title model after every turn',
    'cmd.goal.hint' => '[<condition>|clear]',
    'cmd.grind.description' => 'Like /goal, with a much longer budget of follow-up rounds',
    'cmd.grind.hint' => '[<condition>|clear]',
    'cmd.handoff.description' => 'Continue in a new session that starts from a state summary of this one',
    'cmd.handoff.hint' => '[focus]',
    'cmd.help.description' => 'List every slash command',
    'cmd.init.description' => 'Study this project and write or improve its AGENTS.md',
    'cmd.init.hint' => '[focus]',
    'cmd.keys.description' => 'Show the keyboard shortcut reference (or press ?)',
    'cmd.layout.description' => 'Reset the pane layout to the launch default',
    'cmd.layout.hint' => 'reset',
    'cmd.layout.label' => 'Reset layout',
    'cmd.loader.control-plane' => 'Refusing file-based command /{name}: it is a control-plane command ({reserved}) and a command file cannot take one over. The built-in still runs; rename the file to use it.',
    'cmd.loader.dir-is-anchor' => 'Skipping commands directory {dir}: resolves to {real}, which is exactly the tree it was anchored to ({anchor})',
    'cmd.loader.dir-outside' => 'Skipping commands directory {dir}: resolves to {real}, outside the tree it was anchored to ({anchor})',
    'cmd.loader.file-failed' => 'Failed to load command from {file}: {error}',
    'cmd.loader.file-outside' => 'Skipping command file outside {dir}: {file}',
    'cmd.loader.no-home' => 'Skipping user commands: this process cannot establish that $HOME is this user\'s own directory (see HomeDirectory::owned()), so there is no anchor to hold ~/.sugar-crush/commands inside.',
    'cmd.mcp-auth.add-usage' => 'Usage: mcp auth add <server> [registration-url] [token-url]',
    'cmd.mcp-auth.column.expires' => 'Expires',
    'cmd.mcp-auth.column.scopes' => 'Scopes',
    'cmd.mcp-auth.column.server' => 'Server',
    'cmd.mcp-auth.column.status' => 'Status',
    'cmd.mcp-auth.empty' => "No stored MCP credentials.\n\nCredentials are only for servers that demand OAuth login; a\ndeclared http server that needs none runs from its \"url\" alone.\nServers themselves are declared under \"mcpServers\" in\n<project>/.mcp.json — recipe: docs/MCP.md, \"{section}\".",
    'cmd.mcp-auth.failed' => '✗ Registration failed: {error}',
    'cmd.mcp-auth.heading' => '**MCP Servers**',
    'cmd.mcp-auth.login-guidance' => "Interactive login is a shell command, not a chat turn:\n\n  sugarcrush mcp auth login <server> [token-url] [authorize-url] [registration-url]\n\nIt runs the OAuth authorization-code flow with PKCE: your browser\nreturns the code to a loopback listener in the shell, and the stored\ntokens are attached to matching http servers from the next launch.",
    'cmd.mcp-auth.not-found' => '! No credentials found for `{server}`.',
    'cmd.mcp-auth.registered' => "✓ Successfully registered `{server}`\nClient ID: `{client}`\nRequests to an http MCP server with this exact URL now carry this\ntoken automatically, refreshed before expiry; servers started before\nthis command pick it up on the next launch.",
    'cmd.mcp-auth.remove-usage' => 'Usage: mcp auth remove <server>',
    'cmd.mcp-auth.removed' => '✓ Removed credentials for `{server}`',
    'cmd.mcp-auth.status.active' => '● active',
    'cmd.mcp-auth.status.expired' => '○ expired',
    'cmd.mcp-auth.status.expiring' => '● expiring soon',
    'cmd.mcp-auth.status.none' => '○ no credentials',
    'cmd.mcp-auth.undiscovered' => "! OAuth endpoints could not be discovered for `{server}`.\n\nPlease provide them explicitly:\n  `mcp auth add {server}` *<registration-url>* *<token-url>*\n\nNothing to discover usually means nothing to register: a\nserver that needs no login runs from its \"url\" alone once\ndeclared in .mcp.json (docs/MCP.md, \"{section}\").",
    'cmd.mcp-auth.unknown' => 'Unknown sub-command \'{sub}\'. Use: list, add, remove, login',
    'cmd.mcp-auth.usage' => "Usage:\n  mcp auth list                    — list registered servers\n  mcp auth add <server> [reg-url] [token-url]  — store OAuth credentials for a server\n  mcp auth remove <server>         — remove a server's credentials\n  mcp auth login <server>          — print the shell command for interactive login",
    'cmd.mcp.description' => 'Manage MCP server auth (list/add/remove; login prints the CLI command)',
    'cmd.mcp.hint' => '<list|add|remove|login> [server]',
    'cmd.mcp.label' => 'List MCP servers',
    'cmd.memory.description' => 'Add, list, search, edit, import, clear, or restore memory entries, and show their history',
    'cmd.memory.empty' => 'Memory history is empty: nothing has been saved to {dir} yet.',
    'cmd.memory.log-failed' => 'Memory history failed: {error}',
    'cmd.memory.log-heading' => '**Memory history** ({count}, newest first) — `/memory restore <commit>` puts memory back as it stood at one:',
    'cmd.memory.log-usage' => 'Usage: /memory log [count] — count is a whole number of commits, at most {max}.',
    'cmd.memory.no-git' => 'Memory history needs `git` on PATH; none was found.',
    'cmd.memory.no-history' => 'Memory history covers the home memory directory (~/.sugar-crush/memory) only, and this session has no home memory store.',
    'cmd.memory.record-failed' => 'Memory history could not record this change: {error}',
    'cmd.memory.restore-failed' => 'Memory restore failed: {error}',
    'cmd.memory.restore-refused' => 'Cannot restore: {error}.',
    'cmd.memory.restore-usage' => 'Usage: /memory restore <commit> — a commit id `/memory log` lists.',
    'cmd.memory.restored' => 'Memory restored to `{revision}` as commit `{commit}`. The notes it replaced are still in the history: `/memory log`, then `/memory restore` the commit before this one.',
    'cmd.memory.unchanged' => 'Memory already matches `{revision}`; nothing changed.',
    'cmd.model.description' => 'Switch the active provider, or a provider and its model',
    'cmd.model.hint' => '[provider [model]]',
    'cmd.model.label' => 'Switch model',
    'cmd.new.description' => 'Start a fresh session',
    'cmd.new.label' => 'New session',
    'cmd.newrule.description' => 'Have the agent draft a project rule from this conversation',
    'cmd.newrule.hint' => '[focus]',
    'cmd.notices.description' => 'Show every warning this launch raised, un-capped and un-aggregated',
    'cmd.notices.dropped' => 'Dropped past the transcript cap ({count}) — each still went to stderr whole:',
    'cmd.notices.grants' => 'Narrowed agent tool grants ({count}) — each compares an agent\'s declared tools to this session\'s ceiling:',
    'cmd.notices.grants.unavailable' => 'Narrowed agent tool grants: unavailable — no agent manager is wired into this session.',
    'cmd.notices.launch' => 'Launch notices ({count}):',
    'cmd.notices.none' => 'none',
    'cmd.notices.subtitle' => 'The transcript seeds capped and clipped rows; this list restates none of them short.',
    'cmd.notices.title' => '/notices — every warning this launch raised, whole and un-capped.',
    'cmd.pane-dock-left.description' => 'Dock the focused pane to the left',
    'cmd.pane-dock-left.label' => 'Dock pane left',
    'cmd.pane-dock-right.description' => 'Dock the focused pane to the right',
    'cmd.pane-dock-right.label' => 'Dock pane right',
    'cmd.pane.description' => 'Dock a pane to a side, or toggle its docked state',
    'cmd.pane.hint' => 'dock <left|right>|toggle [name]',
    'cmd.permissions.description' => 'Show this session\'s permission mode, its source, and the rules it decides by',
    'cmd.pruning.default' => 'This session now follows the configured mode.',
    'cmd.pruning.description' => 'Show or set how this session prunes its context: auto, manual or off',
    'cmd.pruning.hint' => '[auto|manual|off|default]',
    'cmd.pruning.meaning.auto' => 'superseded rows are pruned at each turn start, the model may prune its own tool outputs (Prune), and tool results carry their ref tags',
    'cmd.pruning.meaning.manual' => 'nothing is pruned on its own, by the strategies or the model; /sweep prunes by hand, and tool results carry their ref tags',
    'cmd.pruning.meaning.off' => 'no strategies and no ref tags, and /sweep is refused; an over-full request is still relieved',
    'cmd.pruning.source.configured' => 'The configured mode (`{setting}` / `{env}`)',
    'cmd.pruning.source.session' => 'Set for this session with /pruning',
    'cmd.pruning.status' => 'Context pruning: {mode} — {meaning}. {source}.',
    'cmd.pruning.usage' => 'Usage: /pruning [auto|manual|off|default]',
    'cmd.recompress.description' => 'Restore the summary of a section /decompress took back',
    'cmd.recompress.hint' => '[bN]',
    'cmd.redo.description' => 'Step forward again over what /rewind or /undo took back',
    'cmd.rename.description' => 'Rename the current session',
    'cmd.rename.hint' => '[<name>|--auto]',
    'cmd.rename.label' => 'Rename session…',
    'cmd.rewind.description' => 'Restore an earlier checkpoint: the conversation, the files, or both',
    'cmd.rewind.hint' => '[n] [--chat|--files|--both]',
    'cmd.rules.arity' => '/rules takes one pack name; read it as "{args}".',
    'cmd.rules.available' => 'Available packs: {names}',
    'cmd.rules.collision' => ' (this name matches {count} packs — both toggled)',
    'cmd.rules.column.pack' => 'Pack',
    'cmd.rules.column.source' => 'Source',
    'cmd.rules.column.state' => 'State',
    'cmd.rules.description' => 'List the rule packs, or toggle one for this session',
    'cmd.rules.effect.in' => 'It will be in the prompt from the next turn onward.',
    'cmd.rules.effect.out' => 'It will be out of the prompt from the next turn onward.',
    'cmd.rules.effect.self-disabled' => 'Its own frontmatter says enabled: false, so it stays out of the prompt until that line changes - the toggle could not override a file that disabled itself.',
    'cmd.rules.empty' => "No rule packs found.\nA pack is one markdown file in ~/.sugar-crush/rulebooks/ (or ~/.sugar-crush/rules/),\nnamed by its filename: terse.md is toggled with /rules terse.\n/newrule has the agent draft a project rule from this conversation.",
    'cmd.rules.footer' => "/rules <name> toggles one. A pack marked frontmatter is disabled by its\nown file and stays out of the prompt either way. /newrule has the agent\ndraft a project rule (.sugar-crush/rules/) from this conversation.",
    'cmd.rules.heading' => 'Rule packs (session only — nothing here is written to config):',
    'cmd.rules.hint' => '[name]',
    'cmd.rules.only-pack' => 'The only pack here is: {names}',
    'cmd.rules.state.off-frontmatter' => 'off (frontmatter)',
    'cmd.rules.state.off-session' => 'off (session)',
    'cmd.rules.state.on' => 'on',
    'cmd.rules.toggled' => 'Pack {name}: {state} for this session.{note}',
    'cmd.rules.toggled.off' => 'OFF',
    'cmd.rules.toggled.on' => 'ON',
    'cmd.rules.unknown' => 'Unknown rule pack: {name}',
    'cmd.rules.untoggled' => 'Nothing was toggled.',
    'cmd.session-delete.description' => 'Open the session list to delete a session',
    'cmd.session-delete.label' => 'Delete session…',
    'cmd.session-pin.description' => 'Pin the current session to the front of the list, or unpin it',
    'cmd.session-pin.label' => 'Pin or unpin session',
    'cmd.sessions.description' => 'List, search and manage sessions',
    'cmd.sessions.hint' => '[<query>]',
    'cmd.sessions.label' => 'Switch session',
    'cmd.settings.description' => 'Show every setting, its value, where it came from and when it applies',
    'cmd.settings.hint' => '[search]',
    'cmd.settings.label' => 'View settings',
    'cmd.share.bad-format' => 'Invalid format \'{format}\'. Supported: md, html, json, text',
    'cmd.share.description' => 'Export the session to a file',
    'cmd.share.exported' => 'Exported {count} message(s) as {format} to `{target}`.',
    'cmd.share.hint' => '[md|html|json] [path]',
    'cmd.share.label' => 'Share session',
    'cmd.share.no-home' => 'Cannot determine a home directory this user owns, so there is no safe default export location. Pass a path inside the project instead: /share md exports/session.md',
    'cmd.share.outside-root' => 'Refusing to write \'{path}\': an export path must stay inside the project root{root}. Omit the path to write to ~/{exports}.',
    'cmd.share.too-many' => 'Too many arguments.',
    'cmd.share.upload-failed' => 'Upload to {url} did not happen: {error} The local file above is the export.',
    'cmd.share.usage' => 'Usage: /share [md|html|json|text] [path]',
    'cmd.share.usage-hint' => 'Without a path the export goes to ~/{exports}/; a path must stay inside the project.',
    'cmd.skills.description' => 'List the skill drafts the dream pass proposed, or accept or reject one',
    'cmd.skills.hint' => '[proposed|accept <name> [--replace]|reject <name>]',
    'cmd.sweep.description' => 'Prune the tool outputs since your last prompt (or the last n) from what the model sees',
    'cmd.sweep.files' => '; files: {files}',
    'cmd.sweep.hint' => '[n]',
    'cmd.sweep.none' => 'Nothing was swept.',
    'cmd.sweep.nothing-at-all' => 'Nothing to sweep: this conversation has no tool outputs a prune can name.',
    'cmd.sweep.nothing-since-prompt' => 'Nothing to sweep: no tool has answered since your last prompt. `/sweep n` sweeps the last n outputs of any turn.',
    'cmd.sweep.off' => 'Context pruning is off for this session, so nothing was swept. `/pruning manual` or `/pruning auto` turns it back on.',
    'cmd.sweep.skipped' => ' Skipped: {parts}.',
    'cmd.sweep.skipped.protected' => 'protected {tools}',
    'cmd.sweep.skipped.pruned' => '{count} already pruned',
    'cmd.sweep.skipped.small' => '{count} too small to be worth a placeholder',
    'cmd.sweep.swept.many' => 'Swept {count} tool outputs (~{tokens} tokens): {tools}{files}. The model now sees a one-line placeholder for each; the transcript keeps them.',
    'cmd.sweep.swept.one' => 'Swept {count} tool output (~{tokens} tokens): {tools}{files}. The model now sees a one-line placeholder for each; the transcript keeps them.',
    'cmd.sweep.usage' => 'Usage: /sweep [n] — prune every tool output since your last prompt, or the last n tool outputs.',
    'cmd.theme.description' => 'Switch the color theme',
    'cmd.theme.label' => 'Switch theme',
    'cmd.undo.description' => 'Take back the last turn, or revert the last auto-commit',
    'cmd.websearch.bad-safesearch' => 'Invalid safesearch value \'{value}\'. Must be 0, 1, or 2.',
    'cmd.websearch.bad-time-range' => 'Invalid time-range \'{value}\'. Must be day, month, or year.',
    'cmd.websearch.description' => 'Search the web via SearXNG',
    'cmd.websearch.help.body' => "Usage: /websearch <query> [options]\n\nOptions:\n  --safesearch 0|1|2   Safe search (0=none, 1=moderate, 2=strict)\n  --time-range day|month|year  Limit results to time period\n  --help, -h           Show this help message\n\nExamples:\n  /websearch \"php tutorial\"\n  /websearch \"news\" --safesearch 2 --time-range month\n  /websearch --time-range year \"rust\"",
    'cmd.websearch.help.title' => '/websearch — Search the web via SearXNG',
    'cmd.websearch.hint' => '<query> [--safesearch 0|1|2] [--time-range day|month|year]',
    'cmd.websearch.searching' => 'Searching...',
    'cmd.websearch.too-long' => 'Query exceeds maximum length of {max} characters',
    'cmd.websearch.unknown-flag' => 'Unknown flag \'{flag}\'. Valid flags: --safesearch, --time-range, --help',
    'cmd.websearch.usage' => "Usage: /websearch <query> [--safesearch 0|1|2] [--time-range day|month|year]\nUse /websearch --help for full options.",
    'cmd.workflow.description' => 'Run, pause, resume, or inspect a workflow',
    'keys.agents.attach' => 'Open that agent\'s transcript (in the peek)',
    'keys.agents.back' => 'Drop the selection, then leave the view',
    'keys.agents.cancel' => 'Cancel the selected agent; again to stop now',
    'keys.agents.move' => 'Move the selection (or k / j)',
    'keys.agents.peek' => 'Look at the selected agent (or Space)',
    'keys.agents.quit' => 'Leave the view and any open agent transcript',
    'keys.agents.resume' => 'Resume the selected agent, or continue it',
    'keys.agents.slot' => 'Jump to that numbered dashboard row',
    'keys.agents.stop-all' => 'Stop every running agent',
    'keys.agentview.back' => 'Back to the main transcript (or Alt+↑)',
    'keys.agentview.background' => 'Send this agent to the background',
    'keys.agentview.cancel' => 'Cancel this agent; again to stop it now',
    'keys.agentview.next' => 'Open the next agent of the same batch',
    'keys.agentview.open-session' => 'Open this agent as a session',
    'keys.agentview.pause' => 'Pause this agent, or let it go on',
    'keys.agentview.prev' => 'Open the previous agent of the same batch',
    'keys.agentview.send' => 'Send the draft to the agent on screen',
    'keys.agentview.stop-all' => 'Stop every running agent',
    'keys.chat.accept-suggestion' => 'Take the grayed suggestion (empty input box)',
    'keys.chat.agents' => 'List the active agents (runs /agents)',
    'keys.chat.agents-strip' => 'Focus the live agents strip',
    'keys.chat.backspace' => 'Delete the previous character',
    'keys.chat.cancel' => 'Cancel the turn in flight — twice, quickly',
    'keys.chat.cursor' => 'Move the cursor one character',
    'keys.chat.delete-forward' => 'Delete the character under the cursor',
    'keys.chat.draft-rows' => 'Move between the rows of a multi-line draft',
    'keys.chat.keys' => 'Show this reference (empty input box)',
    'keys.chat.line-ends' => 'Jump to the first or last column',
    'keys.chat.mention-complete' => 'Complete the @file or $skill name at the cursor',
    'keys.chat.newline' => 'Insert a newline instead of sending',
    'keys.chat.page' => 'Scroll the transcript by a screenful',
    'keys.chat.palette' => 'Open the command palette',
    'keys.chat.paste-image' => 'Attach the clipboard image as an @ mention',
    'keys.chat.plan-mode' => 'Toggle plan mode (between turns)',
    'keys.chat.queue' => 'Mid-turn: queue the draft for after this turn',
    'keys.chat.quit' => 'Quit SugarCrush',
    'keys.chat.recall' => 'Walk back through past prompts (empty box)',
    'keys.chat.recall-next' => 'Walk forward again, then back to your draft',
    'keys.chat.send' => 'Send, or accept the highlighted "/" command',
    'keys.chat.session-cycle' => 'Switch to the next session',
    'keys.chat.session-cycle-prev' => 'Switch to the previous session',
    'keys.chat.session-picker' => 'Open the session picker',
    'keys.chat.slash-complete' => 'Complete the highlighted "/" command',
    'keys.chat.slash-menu' => 'Move through the "/" command popup',
    'keys.chat.space' => 'Insert a blank character (modifier ignored)',
    'keys.chat.steer' => 'Mid-turn: steer the agent at its next step',
    'keys.chat.stop' => 'Stop the running tool, then the turn',
    'keys.chat.tool-output' => 'Expand or collapse newest tool output/thought',
    'keys.chat.word-delete' => 'Delete the previous word (or Alt+Backspace)',
    'keys.chat.word-delete-back' => 'Delete the previous word',
    'keys.chat.word-delete-forward' => 'Delete the word after the cursor',
    'keys.chat.word-motion' => 'Move one word (or Ctrl+← / Ctrl+→)',
    'keys.context.agent-strip' => 'Agents strip',
    'keys.context.agent-transcript' => 'Agent transcript',
    'keys.context.agents' => 'Agent view',
    'keys.context.chat' => 'Chat',
    'keys.context.menu' => 'Menu bar',
    'keys.context.mouse' => 'Mouse',
    'keys.context.palette' => 'Command palette',
    'keys.context.permission' => 'Permission prompt',
    'keys.context.picker' => 'Session picker',
    'keys.context.settings' => 'Settings view',
    'keys.context.shell' => 'Panes & windows',
    'keys.context.skills' => 'Skill picker',
    'keys.menu.close' => 'Close the menu (or q)',
    'keys.menu.move' => 'Move the highlighted row (or k / j)',
    'keys.menu.run' => 'Run the row (or o)',
    'keys.menu.switch' => 'Switch menu (or h / l)',
    'keys.mouse.agent' => 'Open that agent\'s transcript (a Task line)',
    'keys.mouse.palette-row' => 'Run that palette row',
    'keys.mouse.pane' => 'Open the pane menu (palette)',
    'keys.mouse.session-action' => 'Rename, pin or delete the picker session',
    'keys.mouse.side-row' => 'Expand or collapse that Tools or Agents pane row',
    'keys.mouse.tab' => 'Switch to that session',
    'keys.mouse.tool-call' => 'Expand or collapse that call\'s output',
    'keys.mouse.wheel' => 'Scroll the transcript, or the pane under it',
    'keys.palette.close' => 'Close the palette (or Ctrl+P)',
    'keys.palette.erase' => 'Erase the last character of the filter',
    'keys.palette.filter' => 'Filter the list as you type',
    'keys.palette.move' => 'Move the highlighted row',
    'keys.palette.run' => 'Run the highlighted command',
    'keys.permission.always' => 'Ask to allow calls like this one for the session',
    'keys.permission.choice' => 'Pick that choice when the agent asks a question',
    'keys.permission.deny' => 'Refuse the call (or Esc)',
    'keys.permission.note' => 'Refuse with a note the agent reads',
    'keys.permission.once' => 'Allow this one call',
    'keys.permission.rearm' => 'Make the answer keys live again',
    'keys.permission.stop' => 'Refuse the call and stop the turn',
    'keys.picker.archive' => 'Archive the session',
    'keys.picker.archived' => 'Show or hide archived sessions',
    'keys.picker.branch' => 'Filter to the current git branch, or all',
    'keys.picker.children' => 'Show or hide sub-agent sessions',
    'keys.picker.close' => 'Close the picker, or clear the filter first',
    'keys.picker.delete' => 'Delete the session, pressed twice (or Ctrl+D)',
    'keys.picker.delete-children' => 'Confirm a delete, its branch sessions too',
    'keys.picker.filter' => 'Filter the sessions as you type',
    'keys.picker.fork' => 'Fork the session and switch to the copy',
    'keys.picker.move' => 'Move the highlighted session (or k / j)',
    'keys.picker.pin' => 'Pin or unpin the session (or Ctrl+F)',
    'keys.picker.preview' => 'Preview the last messages of the session',
    'keys.picker.rename' => 'Rename the session in place (or Ctrl+E)',
    'keys.picker.resume' => 'Resume the highlighted session',
    'keys.picker.unarchive' => 'Bring an archived session back',
    'keys.settings.back' => 'Leave the preview unsaved (or Esc)',
    'keys.settings.cancel-edit' => 'Drop the value being edited',
    'keys.settings.category' => 'Switch category (or h / l)',
    'keys.settings.close' => 'Clear the search, then close the view',
    'keys.settings.confirm' => 'Save the previewed changes (or Enter)',
    'keys.settings.details' => 'Details in the list\'s place (narrow view)',
    'keys.settings.discard' => 'Discard unsaved changes and close',
    'keys.settings.edit' => 'Edit the highlighted setting',
    'keys.settings.erase' => 'Erase the last character of the search',
    'keys.settings.export' => 'Export a settings profile to a file you name',
    'keys.settings.import' => 'Import a settings profile as staged changes',
    'keys.settings.move' => 'Move between settings (or k / j)',
    'keys.settings.open-file' => 'Open the target file or Files row in $EDITOR',
    'keys.settings.preview-scroll' => 'Scroll the save preview (or k / j)',
    'keys.settings.profile-go' => 'Export or import at the profile path typed',
    'keys.settings.reset' => 'Stage a reset to the default',
    'keys.settings.save' => 'Preview the save of what is staged',
    'keys.settings.search' => 'Search every category as you type',
    'keys.settings.search-keep' => 'Stop typing the search, keep its matches',
    'keys.settings.stage' => 'Stage the value being edited',
    'keys.settings.tier' => 'Switch the file a save writes',
    'keys.settings.trust' => 'Confirm a project trust grant',
    'keys.shell.group-input' => 'Message every running agent at once',
    'keys.shell.menu' => 'Open the menu bar',
    'keys.shell.new-session' => 'Start a fresh session',
    'keys.shell.palette' => 'Open the command palette',
    'keys.shell.pane-chat' => 'Leave the pane, back to the chat',
    'keys.shell.pane-next' => 'Focus the next docked pane',
    'keys.shell.pane-palette' => 'Open the palette from a list pane (empty draft)',
    'keys.shell.pane-prev' => 'Focus the previous docked pane',
    'keys.shell.settings' => 'Focus the settings pane; again to open the view',
    'keys.shell.settings-open' => 'Open settings view from its pane (empty draft)',
    'keys.shell.skills' => 'Open the skill picker',
    'keys.skills.close' => 'Dismiss the picker',
    'keys.skills.move' => 'Move the highlighted skill (or k / j)',
    'keys.skills.select' => 'Enable the highlighted skill',
    'keys.strip.back' => 'Back to the input box (or Alt+↑)',
    'keys.strip.cancel' => 'Stop the focused agent',
    'keys.strip.dismiss' => 'Dismiss if finished, else stop it',
    'keys.strip.move' => 'Move along the strip (or ↑ / ↓)',
    'keys.strip.open' => 'Open the focused agent\'s transcript',
    'settings.allowedTools.label' => 'Allowed tools',
    'settings.attribution.label' => 'Attribution',
    'settings.autoCommit.label' => 'Auto-commit',
    'settings.autoReview.label' => 'Auto mode reviewer',
    'settings.autoTest.label' => 'Auto-test',
    'settings.bashInteractiveIdleSeconds.label' => 'Interactive Bash idle (s)',
    'settings.bashMaxTimeoutSeconds.label' => 'Bash timeout ceiling (s)',
    'settings.bashSandbox.label' => 'Bash sandbox',
    'settings.bashTimeoutSeconds.label' => 'Bash timeout (s)',
    'settings.chatToolTimeoutSeconds.label' => 'Chat-native tool timeout (s)',
    'settings.claudeMcpArgs.label' => 'Claude MCP arguments',
    'settings.claudeMcpBinary.label' => 'Claude MCP binary',
    'settings.claudeMcpEnv.label' => 'Claude MCP environment',
    'settings.compaction.autoPercent.label' => 'Auto-compact at (%)',
    'settings.compaction.autoTokens.label' => 'Auto-compact cap (tokens)',
    'settings.compaction.blockPercent.label' => 'Block input at (%)',
    'settings.compaction.blockTokens.label' => 'Block cap (tokens)',
    'settings.compaction.idleOfferSeconds.label' => 'Offer /compact after idle (s)',
    'settings.compaction.keepRecent.label' => 'Keep recent exchanges',
    'settings.compaction.mode.label' => 'Compaction mode',
    'settings.compaction.modelTokenCaps.label' => 'Per-model caps',
    'settings.compaction.refillLimit.label' => 'Thrash breaker limit',
    'settings.compaction.reminderPercent.label' => 'Reminder at (%)',
    'settings.compaction.reminderTokens.label' => 'Reminder cap (tokens)',
    'settings.compaction.summaryAssistantChars.label' => 'Summary: assistant chars',
    'settings.compaction.summaryUserChars.label' => 'Summary: user chars',
    'settings.compaction.toolOutputChars.label' => 'Summary: tool output chars',
    'settings.connectTimeoutSeconds.label' => 'Connect timeout (s)',
    'settings.contextPruning.compress.label' => 'Model compression',
    'settings.contextPruning.iterationNudgeThreshold.label' => 'Reminder after tool results',
    'settings.contextPruning.maxContextTokens.label' => 'Hard reminder at (tokens)',
    'settings.contextPruning.minContextTokens.label' => 'Reminders from (tokens)',
    'settings.contextPruning.mode.label' => 'Context pruning',
    'settings.contextPruning.nudgeFrequency.label' => 'Rows between reminders',
    'settings.contextWindow.label' => 'Context window',
    'settings.debug.commands.label' => 'Debug: command loading',
    'settings.debug.rules.label' => 'Debug: rule loading',
    'settings.debug.skills.label' => 'Debug: skill loading',
    'settings.debug.stream.label' => 'Debug: token observers',
    'settings.diffPreviewRows.label' => 'Diff preview rows',
    'settings.disabledMcpServers.label' => 'Disabled MCP servers',
    'settings.disabledRules.label' => 'Disabled rule packs',
    'settings.disabledSkills.label' => 'Disabled skills',
    'settings.disabledTools.label' => 'Disabled tools',
    'settings.doubleEscSeconds.label' => 'Esc Esc window (s)',
    'settings.embeddingModel.label' => 'Embedding model',
    'settings.enabledSkills.label' => 'Enabled skills',
    'settings.env.diffMaxBytes.label' => 'Git diff: bytes per section',
    'settings.env.gitDiffAfterWrites.label' => 'Git diff after writes',
    'settings.expandToolOutput.label' => 'Expand tool output',
    'settings.extraBody.label' => 'Extra request body',
    'settings.globMaxMatches.label' => 'Glob match cap',
    'settings.hooksDefaultTimeoutSeconds.label' => 'Hook timeout (s)',
    'settings.includeGitInstructions.label' => 'Git instructions',
    'settings.instructions.label' => 'Forced instructions',
    'settings.layout.label' => 'Pane layout',
    'settings.lintCommands.label' => 'Lint commands',
    'settings.lsp.label' => 'Language servers',
    'settings.maxCheckpoints.label' => 'Checkpoints kept per session',
    'settings.maxCostUsd.label' => 'Spend cap (USD)',
    'settings.maxOutputTokens.label' => 'Max output tokens',
    'settings.maxToolSteps.label' => 'Max tool steps',
    'settings.mcp.enabled.label' => 'Project MCP servers',
    'settings.mcpResultCapBytes.label' => 'MCP result cap (bytes)',
    'settings.memory.autoConsolidate.label' => 'Auto-memory',
    'settings.memory.dreamIntervalSeconds.label' => 'Dream pass interval (s)',
    'settings.memory.dreamProposeSkills.label' => 'Dream pass: propose skills',
    'settings.memory.entryMaxBytes.label' => 'Memory index: bytes per note',
    'settings.memory.projectNoteMaxBytes.label' => 'Project note: max bytes',
    'settings.memory.promptMaxBytes.label' => 'Memory index: bytes',
    'settings.memory.promptMaxEntries.label' => 'Memory index: notes',
    'settings.memory.userMaxBytes.label' => 'Memory index: user-note bytes',
    'settings.memory.userMaxEntries.label' => 'Memory index: user notes',
    'settings.modelPrices.label' => 'Model prices',
    'settings.models.label' => 'Model',
    'settings.mouse.label' => 'Mouse',
    'settings.mouseClicks.label' => 'Mouse clicks',
    'settings.notices.transcriptLimit.label' => 'Launch notices in transcript',
    'settings.notify.label' => 'Notifications',
    'settings.paletteMru.label' => 'Palette recent items',
    'settings.parallelToolCalls.label' => 'Parallel tool calls',
    'settings.parallelToolDeadlineSeconds.label' => 'Parallel tool deadline (s)',
    'settings.permissionMode.label' => 'Permission mode',
    'settings.permissionRules.label' => 'Permission rules',
    'settings.permissions.autoStrikeLimit.label' => 'Auto breaker: blocks in a row',
    'settings.permissions.autoTotalLimit.label' => 'Auto breaker: blocks in total',
    'settings.promptCache.label' => 'Prompt cache',
    'settings.promptSuggestionHistory.label' => 'Prompt suggestion history',
    'settings.promptSuggestions.label' => 'Prompt suggestions',
    'settings.provider.label' => 'Provider',
    'settings.providerRetryAttempts.label' => 'Provider attempts',
    'settings.providerRetryBaseBackoffMs.label' => 'Retry backoff (ms)',
    'settings.queueMode.label' => 'Enter while a turn runs',
    'settings.readMaxBytes.label' => 'Read bound (bytes)',
    'settings.readPageBytes.label' => 'Read page (bytes)',
    'settings.readPageLines.label' => 'Read page (lines)',
    'settings.repoMap.enabled.label' => 'Repo map',
    'settings.repoMap.maxBytes.label' => 'Repo map: bytes per section',
    'settings.rules.standingMaxBytes.label' => 'Standing rules: bytes',
    'settings.scrollWheelLines.label' => 'Wheel step (lines)',
    'settings.secretEnvAllowlist.label' => 'Secret env allowlist',
    'settings.server.allowBypass.label' => 'Server allows bypass modes',
    'settings.server.allowedHosts.label' => 'Server allowed hosts',
    'settings.server.allowedOrigins.label' => 'Server allowed origins',
    'settings.server.askTimeoutSeconds.label' => 'Server permission-question timeout',
    'settings.server.drainSeconds.label' => 'Server drain time',
    'settings.server.host.label' => 'Server bind address',
    'settings.server.maxConcurrentTurns.label' => 'Server concurrent turns',
    'settings.server.maxOpenSessions.label' => 'Server open sessions',
    'settings.server.port.label' => 'Server port',
    'settings.server.trustedProxies.label' => 'Server trusted proxies',
    'settings.sessionRetentionDays.label' => 'Session retention (days)',
    'settings.sessions.autoTitle.label' => 'Auto-title sessions',
    'settings.skills.pathNudges.label' => 'Skill path nudges',
    'settings.statusLine.label' => 'Status line command',
    'settings.streamIdleTimeoutSeconds.label' => 'Stream read idle (s)',
    'settings.subagentMaxActive.label' => 'Sub-agents running at once',
    'settings.subagentMaxConcurrent.label' => 'Sub-agent fan-out',
    'settings.subagentMaxDepth.label' => 'Delegation depth',
    'settings.subagentMaxTurns.label' => 'Sub-agent max turns',
    'settings.subagentModel.label' => 'Sub-agent model',
    'settings.summaryModel.label' => 'Summary model',
    'settings.symbolMap.enabled.label' => 'Symbol map',
    'settings.temperature.label' => 'Temperature (custom)',
    'settings.terminalBackground.label' => 'Terminal background',
    'settings.testCommand.label' => 'Test command',
    'settings.theme.label' => 'Theme',
    'settings.thinkingBudget.label' => 'Thinking budget',
    'settings.titleModel.label' => 'Title model',
    'settings.toolInstructionCapBytes.label' => 'Nested instruction cap (bytes)',
    'settings.toolOutputCapBytes.label' => 'Tool output cap (bytes)',
    'settings.toolOutputPreviewLines.label' => 'Tool output preview lines',
    'settings.toolSpillCaptureBytes.label' => 'Spill capture (bytes)',
    'settings.toolSpillMinCapBytes.label' => 'Spill floor (bytes)',
    'settings.toolSpillWindowPercent.label' => 'Tool result window share (%)',
    'settings.trustedProjectCommands.label' => 'Trusted project commands',
    'settings.trustedProjectHooks.label' => 'Trusted project hooks',
    'settings.trustedProjectMcp.label' => 'Trusted project MCP',
    'settings.trustedProjectSettings.label' => 'Trusted project settings',
    'settings.turnIdleTimeoutSeconds.label' => 'Turn idle timeout (s)',
    'settings.watchFiles.label' => 'Watch files for AI comments',
    'settings.webFetchMaxBytes.label' => 'WebFetch body bound (bytes)',
    'settings.webFetchTimeoutSeconds.label' => 'WebFetch timeout (s)',
    'settings.webSearchEndpoint.label' => 'WebSearch endpoint',
    'settings.webSearchMaxResults.label' => 'WebSearch results',
    'settings.webSearchTimeoutSeconds.label' => 'WebSearch timeout (s)',
    // --- end registries (W11-b) ---

    // --- chat + host (W11-c) ---
    'chat.action.fork_session' => 'Fork session',
    'chat.action.resume_session' => 'Resume session',
    'chat.action.switch_session' => 'Switch session',
    'chat.agents.followup.badge' => '(follow-up)',
    'chat.agents.followup.failed' => 'the follow-up failed',
    'chat.agents.followup.needs_engine' => 'continuing a run needs the engine backend and a session',
    'chat.agents.followup.no_resume_id' => 'this run cannot be continued (it kept no resume id)',
    'chat.agents.followup.no_task_tool' => 'this session has no Task tool to continue the run with',
    'chat.agents.no_mailboxes' => 'this session keeps no agent mailboxes',
    'chat.agents.not_delegated' => 'not a delegated run of this session',
    'chat.agents.problem.already_finished' => '{agent} has already finished, so there is nothing to move to the background',
    'chat.agents.problem.nested' => '{agent} is a nested run; only a run this conversation delegated can move to the background',
    'chat.agents.problem.no_sessions' => 'this chat keeps no sessions to switch to',
    'chat.agents.problem.not_delegated' => '"{id}" is not a delegated run of this session',
    'chat.agents.problem.not_stored' => '{agent} opens as a session once it has finished and been stored',
    'chat.agents.problem.turn_running' => 'a turn is running; open {agent} as a session once it ends',
    'chat.agents.problem.unreachable' => '{agent} cannot be reached: this session keeps no agent mailboxes',
    'chat.agents.session_name' => '{agent} (agent)',
    'chat.background.after_turn.one' => 'Its result goes to the agent as soon as this turn finishes.',
    'chat.background.after_turn.other' => 'Their {count} results go to the agent as soon as this turn finishes.',
    'chat.background.readopted' => 'Background session {id} (\'{name}\') was re-adopted from an earlier run and is {status}.',
    'chat.background.reply.one' => 'A sub-agent sent the agent a message; it goes to the agent now.',
    'chat.background.reply.other' => 'Sub-agents sent the agent {count} messages; they go to the agent now.',
    'chat.background.status' => 'Background session {id} (\'{name}\') is now {status}.',
    'chat.btw.spend_cap' => 'The spend cap is reached, so the side question was not asked. `/budget` shows or raises the cap.',
    'chat.clipboard.clipped' => 'Clipboard copy clipped to {max} of {chars} characters.',
    'chat.clipboard.no_image' => 'No image on the clipboard to attach. Ctrl+V reads an image through pngpaste (macOS), wl-paste (Wayland) or xclip (X11); paste text with your terminal\'s own paste key, or attach a file with @path.',
    'chat.command.error' => '**Error:** {error}',
    'chat.compact.idle_advisory' => 'This session has been idle for over an hour and has grown to ~{tokens} estimated tokens, past its {limit}-token context window. Run /compact to shrink the context before continuing. Sending another message instead will not send it as-is: the next turn is over the automatic-compaction tier, so older exchanges are summarized first, and the turn is refused outright if that does not free enough.',
    'chat.docs.pointer' => 'Docs: see README.md in this project, or {url}',
    'chat.goal.check_stopped' => 'Goal check stopped: {reason}. The goal is no longer live; `{command} <condition>` starts it again.',
    'chat.goal.cleared' => 'Goal cleared: {condition}',
    'chat.goal.met' => 'Goal met{score}: {condition}',
    'chat.goal.needs_title_model' => '{command} needs a title model to judge the goal, and none is configured: set `titleModel` (or `SUGARCRUSH_TITLE_MODEL`). The main model is never asked to judge its own work.',
    'chat.goal.none_set' => 'No goal is set. `{command} <condition>` sets one: the agent starts on it at once, and after every turn the title model checks the transcript for evidence that it is met, sending the agent back to work until it is (up to {rounds} follow-up rounds).',
    'chat.goal.not_met' => 'Goal not met after {rounds} follow-up rounds{score}: {condition}.{missing} The loop stopped; `{command} <condition>` starts it again.',
    'chat.goal.nothing_to_clear' => 'No goal is set, so there is nothing to clear.',
    'chat.goal.set' => 'Goal set: {condition}. After every turn the title model checks the transcript for evidence that it is met; until it is, the agent is sent back to work, up to {rounds} follow-up rounds. `{command} clear` stops it.',
    'chat.goal.status' => 'Goal ({command}, {round} of {rounds} follow-up rounds used): {condition}. `{command} clear` stops it.',
    'chat.goal.still_missing' => ' Still missing: {missing}.',
    'chat.goal.stop.no_title_model' => 'no title model is configured to judge it',
    'chat.goal.stop.no_verdict' => 'the judge gave no verdict',
    'chat.goal.stop.spend_cap' => 'the spend cap is reached',
    'chat.handoff.landed' => '_{report} The agent reads the summary above on its next turn._',
    'chat.handoff.stayed' => ' A turn is running here, so this window stayed: open it from /sessions.',
    'chat.help.heading' => 'Slash commands ({count}):',
    'chat.help.keys_hint' => 'Press ? or type /keys for the keyboard shortcut reference.',
    'chat.layout.usage' => 'usage: /layout reset',
    'chat.mode.back_unsent' => 'Back to `{mode}` before anything was sent, so the agent is not told about the switch.',
    'chat.mode.no_gate' => 'This session runs without a permission gate, so there is no mode to switch.',
    'chat.mode.turn_running' => 'The permission mode does not change while a turn runs: the turn keeps the mode it started with. Switch after it ends, or Esc Esc to cancel it now.',
    'chat.model.usage' => 'Usage: /model [provider [model]]. Available: {available}',
    'chat.notify.awaiting_approval' => 'waiting for approval: {tool}{origin}',
    'chat.notify.awaiting_approval_origin' => ' (sub-agent {agent})',
    'chat.notify.goal_ended' => 'goal {status}',
    'chat.notify.turn_finished' => 'turn finished',
    'chat.pane.usage' => 'usage: /pane dock <left|right> [pane name] | /pane toggle [pane name]',
    'chat.pane.usage_toggle' => 'usage: /pane toggle [pane name]',
    'chat.permission.answer.always' => 'allowed for this session',
    'chat.permission.answer.once' => 'allowed once',
    'chat.permission.answer.refused' => 'refused',
    'chat.permission.answer.refused_note' => 'refused ({note})',
    'chat.permission.subagent_answered' => 'sub-agent {agent} asked to run {tool}: {answer}',
    'chat.picker.archived' => 'Archived. Press a to show archived sessions, u to bring one back.',
    'chat.picker.delete_current' => 'This is the session on screen; switch to another before deleting it.',
    'chat.picker.delete_hint' => 'Highlight a session and press d twice to delete it; the session on screen cannot be deleted.',
    'chat.picker.deleted' => 'Deleted the session.',
    'chat.picker.deleted_with_children' => 'Deleted the session and {sessions} under it.',
    'chat.picker.error' => 'Error: {error}',
    'chat.picker.name_cleared' => 'Session name cleared.',
    'chat.picker.preview.ai' => 'ai:  ',
    'chat.picker.preview.empty' => '(no saved messages to preview)',
    'chat.picker.preview.you' => 'you: ',
    'chat.picker.sessions.one' => '{count} session',
    'chat.picker.sessions.other' => '{count} sessions',
    'chat.pin.no_session' => 'No active session to pin. Start a new conversation first.',
    'chat.pin.pinned' => 'Pinned this session: it lists first in the session picker and the tab strip.',
    'chat.pin.unpinned' => 'Unpinned this session.',
    'chat.provider.model_not_savable' => 'for this session only: nothing here saves a model choice',
    'chat.provider.model_not_saved' => 'not saved: {error}',
    'chat.provider.model_saved' => 'saved as models.{provider} in {path}',
    'chat.provider.no_model_choice' => 'this provider does not take a model choice',
    'chat.provider.saved_note' => ' (model \'{model}\' was {saved})',
    'chat.provider.switch_failed' => 'Could not switch to provider \'{provider}\': {error}{note}',
    'chat.provider.switched' => 'Switched to provider \'{provider}\'.',
    'chat.provider.switched_model' => 'Switched to provider \'{provider}\', model \'{model}\'.',
    'chat.provider.switched_model_saved' => 'Switched to provider \'{provider}\', model \'{model}\' ({saved}).',
    'chat.request.cancelled' => '_Request cancelled._',
    'chat.session.lock_holder' => ' (pid {pid})',
    'chat.session.read_only' => 'Session {session} is open in another sugarcrush{holder}, so this window is read-only: nothing typed here is sent to the model or saved to that session. Type /branch to fork it into a new session this window owns and carry on there, or close the other window and this one becomes writable by itself.',
    'chat.session.resumed' => '_Resumed session {session}._',
    'chat.session.writable' => 'The other sugarcrush has closed session {session}, so this window can write to it now. The transcript was reloaded to include what it saved.',
    'chat.session_help.branch' => '`/branch` — Fork the current session into a new copy',
    'chat.session_help.heading' => '**Available /session commands:**',
    'chat.session_help.rename' => '`/rename <name>` — Name the current session for easy resume',
    'chat.session_help.rewind' => '`/rewind [n]` — Rewind n steps (default: 1) to a previous checkpoint',
    'chat.session_help.session' => '`/session` — Show this help text',
    'chat.sessions.created' => 'New session created: {session}',
    'chat.sessions.no_store' => 'Session store not configured. Set a SessionStore to use /sessions.',
    'chat.sessions.no_store_new' => 'Session store not configured. Set a SessionStore to create sessions.',
    'chat.sessions.none' => 'No sessions recorded yet.',
    'chat.sessions.picker_open' => 'Session picker open — ↑/↓ or wheel browse, click selects, ↵ resume, / filter, r rename, d delete, p pin, f fork, esc close.',
    'chat.settings.applies_now.one' => '{count} applies now',
    'chat.settings.applies_now.other' => '{count} apply now',
    'chat.settings.needs_restart.one' => '{count} needs a restart ({keys})',
    'chat.settings.needs_restart.other' => '{count} need a restart ({keys})',
    'chat.settings.next_turn' => '{count} next turn',
    'chat.settings.not_a_spend_cap' => '{keys} is not a spend ceiling, so the cap stays as it was',
    'chat.settings.saved.one' => 'Saved {count} setting',
    'chat.settings.saved.other' => 'Saved {count} settings',
    'chat.settings.saved_to' => ' to {path}',
    'chat.settings.turn_ended.one' => 'The turn ended, so {keys} now applies.',
    'chat.settings.turn_ended.other' => 'The turn ended, so {keys} now apply.',
    'chat.settings.when_turn_ends' => '{count} when this turn ends',
    'chat.spend.crossed_by_summarization' => 'The summarization this turn was parked behind is what reached the cap; that call went out before the cap was met and is billed. Your prompt is in the transcript above, unsent.',
    'chat.theme.current' => 'Current theme: {theme}. Available: {available}.',
    'chat.theme.set' => 'Theme set to \'{theme}\'.',
    'chat.title.error' => 'Error: {error}',
    'chat.turn.loop_guard' => 'This turn was ended by the repeat-call loop guard: the model called {tool} {count} times with the same arguments and got the same result each time. The reply above describes where it stopped. Point it at a different approach, or say "continue" once something has changed.',
    'chat.turn.output_limit' => 'The provider stopped this reply at its output limit, so the text above may end mid-thought. Raise "maxOutputTokens" in ~/.sugar-crush/config.json for a longer single reply, or ask for the remainder in your next message.',
    'chat.turn.steps_exhausted' => 'This turn used all of its tool steps before the work was done, so the reply above describes where it stopped — what is finished and what remains — not a finished answer. Say "continue" to pick it up from there, or raise "maxToolSteps" in ~/.sugar-crush/config.json for longer agentic turns.',
    'chat.turn.unpriced_model' => 'This app has no price on file for model "{model}", so this turn billed $0.00 as a lower bound, not as a free call — spend totals and the spend cap are under-counted until a rate exists. Declare one under "modelPrices" in ~/.sugar-crush/config.json (USD per 1M tokens, keys "input" and "output") to price it.',
    'chat.workflow.cancelled' => '_Workflow cancelled: stopping its agents. Its report follows._',
    'host.agents.not_configured' => 'Agent manager not configured. Set an AgentManager to use /agents commands.',
    'host.bg.active' => 'Active background sessions: {sessions}',
    'host.bg.backgrounded' => 'Backgrounded as {id} (\'{name}\') — use /agents to check status, /bg stop {id} to cancel.',
    'host.bg.default_name' => 'Background task',
    'host.bg.forked' => 'Forked into background session {id} (\'{name}\') — use /agents to check status, /bg stop {id} to cancel.',
    'host.bg.none_active' => 'No active background sessions.',
    'host.bg.not_configured' => 'Background sessions not configured. Set a BackgroundSupervisor to use /bg and /fork.',
    'host.bg.start_failed' => 'Could not start background session \'{name}\': {error}',
    'host.bg.stop.already_finished' => 'Background session {session} had already finished; nothing to stop.',
    'host.bg.stop.failed' => 'Could not stop background session {session}: {error}',
    'host.bg.stop.signalled' => 'Stopped background session {session} (its control socket was gone, so the daemon was signalled).',
    'host.bg.stop.stopped' => 'Stopped background session {session}.',
    'host.bg.stop.unknown' => 'No background session {id} in this run — /bg stop lists the active ones.',
    'host.bg.stop.unreachable' => 'Could not stop background session {session} — its daemon could not be reached or safely signalled.',
    'host.bg.stop_usage' => 'Usage: /bg stop <session-id>',
    'host.bg.usage' => 'Usage: /bg <task>',
    'host.bg.worktree' => ' It works in its own git worktree at {path}; its changes stay there for you to review and merge.',
    'host.branch.created' => 'Branch created: {session}',
    'host.branch.read_only_moved' => ' — this window now writes to the branch; the original stays with the other sugarcrush.',
    'host.branch.usage' => 'Usage: /branch (takes no arguments)',
    'host.btw.answer_heading' => '**btw** — not sent to the agent',
    'host.btw.failed' => '_The side question failed: {reason}_',
    'host.btw.needs_title_model' => '/btw needs a title model to answer, and none is configured: set `titleModel` (or `SUGARCRUSH_TITLE_MODEL`). The main model is never used for a side question.',
    'host.btw.no_answer' => '_No answer._',
    'host.btw.no_reason' => 'no reason given',
    'host.btw.usage' => 'Usage: /btw <question> — ask the title model about this conversation; neither the question nor the answer is sent to the agent.',
    'host.checkpoint.error' => 'Error during rewind: {error}',
    'host.checkpoint.files_already_match' => 'The files already match checkpoint {index}; nothing was restored.',
    'host.checkpoint.files_differ.one' => ' Your files were left as they are; {count} file differs from that checkpoint — `/rewind --files` puts it back too.',
    'host.checkpoint.files_differ.other' => ' Your files were left as they are; {count} files differ from that checkpoint — `/rewind --files` puts them back too.',
    'host.checkpoint.files_left' => ' The files were left as they are: {reason}.',
    'host.checkpoint.files_match' => 'The files already match that checkpoint.',
    'host.checkpoint.files_restored' => 'Restored the files: {written} rewritten, {deleted} deleted.',
    'host.checkpoint.files_restored_to' => 'Restored the files to checkpoint {index}: {written} rewritten, {deleted} deleted. The conversation was left as it is.',
    'host.checkpoint.files_unrestorable' => 'Nothing was rewound: the files cannot be restored to checkpoint {index} — {reason}. `/rewind {steps} --chat` rewinds the conversation alone.',
    'host.checkpoint.files_unrestored' => 'The files could not be restored: {reason}.',
    'host.checkpoint.no_file_snapshot' => 'Nothing was restored: checkpoint {index} has no file snapshot — {reason}.',
    'host.checkpoint.no_session' => 'No active session. Start a new conversation first.',
    'host.checkpoint.no_snapshot' => 'no snapshot was taken for that checkpoint',
    'host.checkpoint.no_snapshot_because' => 'no snapshot was taken for that checkpoint ({reason})',
    'host.checkpoint.no_store' => 'Session store not configured.',
    'host.checkpoint.none' => 'No checkpoints available to rewind to.',
    'host.checkpoint.not_found' => 'Checkpoint {index} not found.',
    'host.checkpoint.nothing_restored' => 'Nothing was restored: {reason}.',
    'host.checkpoint.redo_hint' => ' /redo steps forward again until you send another prompt.',
    'host.checkpoint.rewound' => 'Rewound {count} messages to checkpoint {index}.',
    'host.checkpoint.unsupported_store' => 'Session store does not support checkpoints. Use an EnhancedSessionStore.',
    'host.command.client_only' => '/{name} runs in the TUI client only; nothing was run in this session.',
    'host.command.error' => '**Error:** {error}',
    'host.command.failed_silently' => 'Command failed with exit code {code} and produced no output.',
    'host.command.unknown' => '/{name} is not a built-in command.',
    'host.compact.blocked' => 'Compaction skipped: PreCompact hook blocked it ({reason}). ',
    'host.compact.blocked.parked' => 'Your prompt goes out against the uncompacted history.',
    'host.compact.blocked.unchanged' => 'The history is unchanged.',
    'host.compact.blocking_cap' => 'the {cap}-token blocking cap',
    'host.compact.blocking_tier' => 'the {percent}% blocking tier',
    'host.compact.done' => 'Context compacted: was {before} messages, now {after} messages (saved {saved}% tokens)',
    'host.compact.hook_ask' => 'a PreCompact hook asked for a decision no compaction path can present',
    'host.compact.hook_no_reason' => 'a PreCompact hook refused without giving a reason',
    'host.compact.hooks_pending' => 'Running PreCompact hooks — the transcript will compact when they answer.',
    'host.compact.model_empty' => 'The model returned no usable summaries — compacted with the local heuristic instead. ',
    'host.compact.model_failed' => 'Model summarisation failed ({error}) — compacted with the local heuristic instead. ',
    'host.compact.nothing' => 'Nothing to compact: chat history is empty.',
    'host.compact.spend_cap' => 'Spend cap reached (${spent} of ${cap}), so the model was not asked to summarise — compacted with the local heuristic instead. ',
    'host.compact.spend_cap.command' => 'Raise the cap with /budget {raise} and run /compact again for model-written summaries. ',
    'host.compact.spend_cap.parked' => 'Your prompt goes out against that rewrite; raise the cap with /budget {raise} for model-written summaries. ',
    'host.compact.summarising.one' => 'Summarising {count} earlier exchange with the model — the transcript will compact when they arrive.',
    'host.compact.summarising.other' => 'Summarising {count} earlier exchanges with the model — the transcript will compact when they arrive.',
    'host.compact.thrash' => 'Context compaction has run {times} times in a row and the transcript came straight back over the limit each time, so this prompt was not sent and no further compaction was attempted. The recent exchanges the rewrite keeps in full are what will not fit — trim the largest of them (tool output is usually the bulk), or start over with /rewind or /clear. /model with a larger context window also resolves this.',
    'host.compact.tier_report' => 'Context reached the automatic-compaction tier, so older exchanges were summarized: {before} messages -> {after} messages, ~{saved}% of the estimated token count freed (~{tokens} estimated tokens now, against a {limit}-token context window).',
    'host.compact.truncated.one' => '{count} message reached {tier} on its own, so it was truncated to fit the context window rather than the turn being refused: ~{tokens} estimated tokens now, against a {limit}-token context window. The dropped text is marked inline in that message.',
    'host.compact.truncated.other' => '{count} messages reached {tier} on their own, so they were truncated to fit the context window rather than the turn being refused: ~{tokens} estimated tokens now, against a {limit}-token context window. The dropped text is marked inline in those messages.',
    'host.decompress.already' => 'b{id} is already decompressed; /recompress b{id} restores it.',
    'host.decompress.done' => 'Decompressed b{id} ({topic}): {from}…{to} are sent in full again from the next turn (~{tokens} tokens). /recompress b{id} restores the summary.',
    'host.decompress.heading' => 'Compressed sections:',
    'host.decompress.hint' => '/decompress bN takes one back; /recompress bN restores it.',
    'host.decompress.inactive' => 'b{id} is not active: the rows it named are no longer in the conversation.',
    'host.decompress.inside' => 'b{id} is inside b{outer}. Restore b{outer} first: /decompress b{outer}.',
    'host.decompress.none' => 'No compressed sections in this session. /compress [focus] asks the model to make one.',
    'host.decompress.state.active' => 'active',
    'host.decompress.state.decompressed' => 'decompressed',
    'host.decompress.state.inside' => 'inside b{outer}',
    'host.decompress.step_summary' => 'b{id} is a step summary the harness wrote, not a section the model compressed; only Compress sections can be taken back.',
    'host.decompress.unknown' => 'No compressed section b{id} in this session.',
    'host.decompress.usage' => 'Usage: /decompress bN — name a compressed section as listed by /decompress.',
    'host.diff.changed.one' => '{count} file changed since checkpoint {index} (`/rewind {steps} --files` puts it back):',
    'host.diff.changed.other' => '{count} files changed since checkpoint {index} (`/rewind {steps} --files` puts them back):',
    'host.diff.error' => 'Error during diff: {error}',
    'host.diff.no_snapshot' => 'Checkpoint {index} has no file snapshot: {reason}.',
    'host.diff.none' => 'No checkpoints available to diff against.',
    'host.diff.omitted' => '{lines} more lines of the patch are not shown.',
    'host.diff.unavailable' => 'No diff against checkpoint {index}: {reason}.',
    'host.diff.unchanged' => 'The files match checkpoint {index}: nothing has changed since.',
    'host.diff.usage' => 'Usage: /diff [n] - show what changed in the files since checkpoint n, n a positive whole number (default 1: the one taken before your last prompt).',
    'host.fork.no_store' => 'Session store not configured. Set a SessionStore to use /fork.',
    'host.fork.usage' => 'Usage: /fork <prompt>',
    'host.handoff.done.mechanical' => 'Handed off to session {id}, a branch of {from} that starts from a mechanical state summary of it',
    'host.handoff.done.model' => 'Handed off to session {id}, a branch of {from} that starts from the summary model\'s state summary of it',
    'host.handoff.done.note' => ' — {note}.',
    'host.handoff.failed' => 'Handoff failed: {reason}.',
    'host.handoff.model_empty' => 'the summary model wrote no state block, so the summary was written from the transcript',
    'host.handoff.model_failed' => 'the summary model failed ({reason}), so the summary was written from the transcript',
    'host.handoff.no_reason' => 'no reason given',
    'host.handoff.no_session' => 'No active session. Start a conversation first.',
    'host.handoff.no_session_opened' => 'no session was opened',
    'host.handoff.no_store' => 'Session store not configured. Set a SessionStore to use /handoff.',
    'host.handoff.nothing_yet' => 'Nothing to hand off yet: the agent has not been sent anything in this session.',
    'host.handoff.open_failed' => 'the new session could not be opened: {reason}',
    'host.handoff.opening' => 'Handing off: opening a new session from a state summary of this one…',
    'host.handoff.summarising' => 'Handing off: the summary model is writing the state summary the new session starts from…',
    'host.hub.locked' => 'Session {session} is open in another sugarcrush{holder}; only one process may write it.',
    'host.memory.agent_scope_note' => 'Agent-scope notes are listable but never reach the prompt; use `--scope project` or `--scope user` for a note the model should see.',
    'host.memory.banner.home' => '*In your home store:*',
    'host.memory.banner.repo' => '*In this repository (`{dir}`):*',
    'host.memory.clear.refused' => '**Not cleared:** bulk clear never reaches the repository — this tree holds project-scope notes under `{dir}` that only the per-id commands touch. Clearing the home half alone would silently leave "project memory" half-wiped, so nothing moved. Remove repo notes by id with `/memory delete <id>` (list them with `/memory list project`).',
    'host.memory.cleared' => 'All memories cleared for scope `{scope}`.',
    'host.memory.created' => 'Memory created with ID: `{id}` (scope: {scope})',
    'host.memory.deleted' => 'Memory `{id}` deleted.',
    'host.memory.error' => '**Error:** {error}',
    'host.memory.fell_back_home' => 'Saved in the home store, not this repository: its `.sugar-crush/memory/` could not be created or written, or it resolves outside the repository, so the note is kept on this machine only and is not part of the checkout.',
    'host.memory.help.add' => '`/memory add <content> [--scope <scope>]` — Add a new memory entry (default: project)',
    'host.memory.help.clear' => '`/memory clear --scope <scope> --confirm` — Clear all memories for a scope',
    'host.memory.help.delete' => '`/memory delete <id>` — Delete a memory by ID',
    'host.memory.help.edit' => '`/memory edit <id> <new_content>` — Edit an existing memory',
    'host.memory.help.heading' => '**Available /memory commands:**',
    'host.memory.help.history' => 'History: every change to the home memory directory is a git commit (when `git` is on PATH); a restore is a new commit, so it can be undone the same way.',
    'host.memory.help.import' => '`/memory import claude|opencode` — Import foreign memory files (one-shot per tool)',
    'host.memory.help.list' => '`/memory list [scope]` — List all memories for a scope (default: project)',
    'host.memory.help.log' => '`/memory log [count]` — List the memory history, newest first (default {count})',
    'host.memory.help.restore' => '`/memory restore <commit>` — Put memory back as it stood at a commit `/memory log` lists',
    'host.memory.help.scopes' => 'Scopes: `project` (default), `user`, `agent`. Project and user notes reach the prompt (user notes first, at most {max}); agent-scope notes are listable but never reach the prompt.',
    'host.memory.help.search' => '`/memory search <query>` — Search memories by content',
    'host.memory.help.self' => '`/memory` — Show this help text',
    'host.memory.import.already' => 'Already imported (sentinel `{sentinel}`; delete it to re-import).',
    'host.memory.import.done' => '**Imported {count}** `{target}` memories into the `agent` scope.',
    'host.memory.import.failed' => '**Import failed** — entries the importer had already written stay in the `agent` scope and no sentinel was written, so re-running may duplicate them. Run `/memory list agent` before re-running. Error: {error}',
    'host.memory.import.no_root' => '**Nothing imported:** no project root could be determined, so this command has no project to read `{target}` memory against or record the `.imported-{target}` sentinel in.',
    'host.memory.import.none' => 'Nothing imported — no readable `{target}` memory files were found for this project.',
    'host.memory.import.none_refused' => 'Nothing imported — no readable `{target}` memory files were found, and every candidate directory was refused.',
    'host.memory.import.refused_heading' => '**Directories not read:**',
    'host.memory.import.unknown_target' => 'Unknown import target \'{target}\'. Use `claude` or `opencode`.',
    'host.memory.list.heading' => '**Memories ({scope}):**',
    'host.memory.list.none' => 'No memories found for scope `{scope}`.',
    'host.memory.not_configured' => 'Memory store not configured. Set a MemoryStore to use /memory commands.',
    'host.memory.not_found' => 'Memory `{id}` not found.',
    'host.memory.search.heading.one' => '**Search results for `{query}` ({count} match):**',
    'host.memory.search.heading.other' => '**Search results for `{query}` ({count} matches):**',
    'host.memory.search.none' => 'No memories found matching `{query}`.',
    'host.memory.sentinel.escaped' => ' **Warning:** the sentinel directory resolved outside this project after directory creation, so no sentinel was written and re-running the import WILL duplicate these entries.',
    'host.memory.sentinel.no_dir' => ' **Warning:** the sentinel directory could not be created, so re-running the import WILL duplicate these entries.',
    'host.memory.sentinel.outside' => ' **Warning:** the sentinel directory does not resolve inside this project, so no sentinel was written and re-running the import WILL duplicate these entries.',
    'host.memory.sentinel.unwritten' => ' **Warning:** the sentinel could not be written, so re-running the import WILL duplicate these entries.',
    'host.memory.sentinel.written' => ' Sentinel: `{sentinel}` (delete it to re-import).',
    'host.memory.unknown_command' => 'Unknown command \'{command}\'.',
    'host.memory.updated' => 'Memory `{id}` updated.',
    'host.memory.usage.add' => 'Usage: /memory add <content> [--scope <scope>]',
    'host.memory.usage.clear' => 'Usage: /memory clear --scope <scope> --confirm',
    'host.memory.usage.delete' => 'Usage: /memory delete <id>',
    'host.memory.usage.edit' => 'Usage: /memory edit <id> <new_content>',
    'host.memory.usage.import' => 'Usage: /memory import claude|opencode',
    'host.memory.usage.search' => 'Usage: /memory search <query>',
    'host.permissions.breaker' => 'Auto-mode circuit breaker: {consecutive} of {strikes} consecutive blocks ({last}), {total} of {limit} blocks this session. Reaching either threshold turns the next block into a prompt instead of a refusal.',
    'host.permissions.breaker_idle' => 'Auto-mode circuit breaker: idle. It only counts under `{auto}`, and this session is `{mode}`.',
    'host.permissions.breaker_last' => 'last category: {category}',
    'host.permissions.breaker_nothing_blocked' => 'nothing blocked yet',
    'host.permissions.mode' => 'Permission mode: {mode} — from {source}',
    'host.permissions.no_gate' => 'No permission gate is attached to this session, so no mode and no rule are deciding anything here. That is what an embedder gets, and a Chat built without a hook chain and without an engine backend; a `sugarcrush` launch always builds one. Any hooks that are installed still run.',
    'host.permissions.no_rules' => 'Rules: none configured, so every decision above is the mode\'s own. A `permissionRules` array in ~/.sugar-crush/config.json or in ~/.sugar-crush/settings.json is where they go; config.json wins where both set a key.',
    'host.permissions.rules' => 'Rules ({count}), tried in this order — the first one that matches decides, ahead of the mode:',
    'host.permissions.unrecorded_source' => 'a source this gate did not record',
    'host.recompress.already' => 'b{id} is already compressed.',
    'host.recompress.done' => 'Recompressed b{id} ({topic}): {from}…{to} are sent as its summary again from the next turn.',
    'host.recompress.usage' => 'Usage: /recompress bN — name a decompressed section as listed by /recompress.',
    'host.redo.again' => ' /redo again to go further.',
    'host.redo.error' => 'Error during redo: {error}',
    'host.redo.files_drifted' => ' The files were left as they are: they do not match the checkpoint the conversation was at, so moving them would overwrite changes.',
    'host.redo.nothing' => 'Nothing to redo: /redo steps forward over what /rewind or /undo set aside, until the next prompt is sent.',
    'host.redo.to_checkpoint' => 'Redid {count} messages, to checkpoint {index}.',
    'host.redo.to_tip' => 'Redid {count} messages: back where you were before the rewind.',
    'host.remote.ambiguous' => '"{prefix}" starts {count} session ids on the server: {ids}',
    'host.remote.bad_frame' => 'the server sent a frame that could not be read: {error}',
    'host.remote.closed' => 'the connection to the server closed',
    'host.remote.failed' => 'the connection to the server failed: {error}',
    'host.remote.no_session' => 'the server has no session "{session}"',
    'host.remote.refused' => 'the server refused the request',
    'host.remote.server_closed' => 'the server closed the connection ({code}{reason})',
    'host.rename.asking' => 'Asking the title model for a new session name…',
    'host.rename.blank' => 'A session name needs at least one printable character.',
    'host.rename.cleared' => 'Session name cleared; the first reply will name it.',
    'host.rename.error' => 'Error: {error}',
    'host.rename.no_session' => 'No active session. Start a new conversation first.',
    'host.rename.no_store' => 'Session store not configured. Set a SessionStore to use /branch and /rename commands.',
    'host.rename.no_title_model' => 'No title model is configured, so the session name is unchanged.',
    'host.rename.renamed' => 'Session renamed to \'{title}\'',
    'host.rewind.usage' => 'Usage: /rewind [n] [--chat|--files|--both] - step back n checkpoints, n a positive whole number (default 1). --chat (the default) restores the conversation, --files the project\'s files, --both both.',
    'host.skills.accepted' => 'Accepted the skill draft `{name}`: it is now `{path}`, loaded from the next launch.',
    'host.skills.does_not_load' => 'does not load as a skill: {error}',
    'host.skills.error' => 'Skills: {error}',
    'host.skills.no_home' => 'No skill drafts: there is no home directory this user owns to keep them in.',
    'host.skills.none' => 'No skill drafts are waiting in `{root}`. The dream pass proposes them only with `{setting}` on.',
    'host.skills.rejected' => 'Rejected and deleted the skill draft `{name}`.',
    'host.skills.review_hint' => 'Read one before accepting it. `/skills accept <name>` makes it live in `~/{dir}/<name>/` (from the next launch); `/skills reject <name>` deletes it.',
    'host.skills.usage' => 'Usage: /skills [proposed] · /skills accept <name> [--replace] · /skills reject <name>',
    'host.skills.waiting.one' => '{count} skill draft waiting in `{root}`:',
    'host.skills.waiting.other' => '{count} skill drafts waiting in `{root}`:',
    'host.spend.cap' => 'cap ${cap}',
    'host.spend.cleared' => 'Spend cap cleared. ',
    'host.spend.crossed_by_previous_turn' => 'The turn that crossed the cap ran to completion; the cap refuses the NEXT turn rather than aborting one in flight.',
    'host.spend.lower_bound' => ' At least one model this session used has no price on file, so this is a LOWER BOUND: declare rates under "modelPrices" in ~/.sugar-crush/config.json to bill them.',
    'host.spend.mid_turn' => '_Spend cap reached mid-turn: aborted after provider call {calls} — ${spent} of the ${cap} cap spent. No further calls were made this turn; /budget raises the cap._',
    'host.spend.no_cap' => 'no cap',
    'host.spend.no_cap_was_set' => 'No spend cap was set. ',
    'host.spend.refused' => 'Spend cap reached — this turn was not sent. ${spent} of the ${cap} cap has been reported spent. {crossing} Raise it with /budget {raise}, clear it with /budget off, or restart without $SUGARCRUSH_MAX_COST.',
    'host.spend.set' => 'Spend cap set to ${cap}. ',
    'host.spend.status' => 'Spend so far: ${spent} ({cap}). {summary}',
    'host.spend.status_unreported' => 'Spend so far: not reported by this provider ({cap}). Streamed turns and self-hosted providers commonly report no usage at all, and an unreported session is never refused by the cap.',
    'host.spend.usage' => 'Usage: /budget <amount> to cap this session\'s spend (e.g. /budget 5 or /budget $2.50), /budget off to clear it, /budget on its own to see where you are. The amount must be a real number greater than zero — a cap of 0 and no cap are opposite requests, so `0` is refused rather than guessed at, and a figure too large to represent (`1e309`, which is infinity) is refused rather than accepted as a cap that would then never trigger.',
    'host.turn.bang_refused' => 'Did not run `{command}`: {reason}.',
    'host.turn.bang_running' => 'Running `{command}` — its output joins the conversation when it finishes.',
    'host.turn.empty_custom_command' => '{draft} is a command file whose template expanded to nothing — most often a body that is only $ARGUMENTS or $1, invoked with no arguments. Nothing was sent: an empty prompt costs a turn and tells the model nothing. Pass arguments, or give the file a body that stands on its own.',
    'host.turn.empty_prompt' => 'Nothing was sent: the prompt is empty.',
    'host.turn.expansion_failed' => '{draft} was not sent: expanding it ended without a result. {box}',
    'host.turn.expansion_failed.box_occupied' => 'The box keeps the draft you typed while it ran.',
    'host.turn.expansion_failed.in_box' => 'It is still in the box.',
    'host.turn.hook_blocked.box_occupied' => ' Your prompt (“{draft}”) was not sent; the box keeps the draft you typed while the hook ran.',
    'host.turn.hook_blocked.in_box' => ' Your prompt was not sent and is still in the box.',
    'host.turn.host_command_in_flight' => '{draft} is a command, and commands do not run while a turn is in flight — it would rewrite history this turn is about to append to. Send it again once the turn finishes, or cancel the turn.',
    'host.turn.in_flight_action' => '"{action}" does not run while a turn is in flight — it would change state this turn is about to write. Wait for the turn to finish, or Esc Esc to cancel it now.',
    'host.turn.in_flight_command' => '{draft} is a command, and commands do not run while a turn is in flight — it would rewrite history this turn is about to append to. Your draft is still in the box: press Enter again once the turn finishes, or Esc Esc to cancel the turn now.',
    'host.turn.no_backend' => 'Nothing was sent: this session has no model backend configured.',
    'host.turn.queued' => 'Queued ({waiting} waiting) — sent as soon as this turn finishes: {draft}',
    'host.turn.read_only' => '"{draft}" was not sent: session {session} is open in another sugarcrush, so this window is read-only. Type /branch to fork it into a session of your own, and this draft comes back in the box there.',
    'host.turn.run_command_in_flight' => '{draft} was not run: commands do not run while a turn is in flight — it would rewrite history this turn is about to append to. Your draft was not touched. Run it again once the turn finishes, or Esc Esc to cancel the turn now.',
    'host.turn.session_hook_note_discarded' => ' The hook\'s context note was discarded and the session continues.',
    'host.turn.steering' => 'Steering — the agent reads this at its next step (sent as the next prompt if the turn ends first): {draft}',
    'host.undo.error' => 'Error during undo: {error}',
    'host.undo.refused' => 'Nothing was undone: {reason}.',
    'host.undo.reverted' => 'Reverted {sha} ({subject}): {files} went back to the previous commit.',
    'host.undo.rewind_hint' => ' `/rewind --both` still restores the conversation and files to the last checkpoint.',
    'host.undo.uncommitted' => 'Un-committed {sha} ({subject}): your changes to {files} are back to uncommitted, as they were.',
    'host.workflow.error' => '**Error:** {error}',
    'host.workflow.help.heading' => '**Available /workflow commands:**',
    'host.workflow.help.list' => '`/workflow list` — List available workflows',
    'host.workflow.help.note' => 'Note: pause/resume granularity is per-whole-stage only. A real interrupt (Ctrl-C/SIGTERM) captures whatever stages have genuinely finished so far, but if it lands while a \'parallel\' stage is mid-flight, that stage\'s individual in-progress agent results are NOT captured — the stage is simply re-run from scratch on resume. There is no partial-credit resume for a parallel sub-stage.',
    'host.workflow.help.pause' => '`/workflow pause <workflowId>` — Pause a running workflow',
    'host.workflow.help.resume' => '`/workflow resume <workflowId>` — Resume a paused workflow',
    'host.workflow.help.run' => '`/workflow run <name> [key=val ...]` — Run a workflow by name with optional context',
    'host.workflow.help.self' => '`/workflow` — Show this help text',
    'host.workflow.help.status' => '`/workflow status <workflowId>` — Check workflow status',
    'host.workflow.list_heading' => '**Available workflows:**',
    'host.workflow.none' => 'No workflows found. They are read from `.sugar-crush/workflows/*.yaml` (project, YAML only — skipped entirely if that directory resolves outside the checkout, which the launch reports on stderr) or `~/.sugar-crush/workflows/*.{yaml,php}`.',
    'host.workflow.not_configured' => 'Workflow engine not configured. Set a WorkflowEngine to use /workflow commands.',
    'host.workflow.outcome.cancelled' => 'cancelled',
    'host.workflow.outcome.completed' => 'completed',
    'host.workflow.outcome.failed' => 'failed',
    'host.workflow.outcome.paused' => 'paused',
    'host.workflow.pause_requested' => 'Pause requested for workflow `{id}`: the stage in flight finishes, then the run stops before the next one. `/workflow resume {id}` continues it.',
    'host.workflow.paused' => 'Workflow `{id}` has been paused.',
    'host.workflow.report.attempts' => 'Stage \'{stage}\': {attempts} attempts',
    'host.workflow.report.cancelled' => 'Cancelled with Esc Esc: the stage in flight had its agents stopped, and no later stage ran. The totals above include what the stopped stage had already spent.',
    'host.workflow.report.continue' => 'Continue it with `/workflow resume {id}`.',
    'host.workflow.report.cost' => 'Total cost: ${cost}',
    'host.workflow.report.failure' => 'Stage \'{stage}\': {error}',
    'host.workflow.report.heading' => '**Workflow \'{name}\' {outcome}**',
    'host.workflow.report.id' => 'ID: `{id}`',
    'host.workflow.report.resumed' => '**Workflow \'{name}\' resumed and {outcome}**',
    'host.workflow.report.stages' => 'Stages completed: {count}',
    'host.workflow.report.status' => 'Status: {status}',
    'host.workflow.report.tokens' => 'Total tokens: {tokens}',
    'host.workflow.status' => 'Workflow `{id}` status: **{status}**',
    'host.workflow.unknown_command' => 'Unknown command \'{command}\'.',
    'host.workflow.usage.pause' => 'Usage: /workflow pause <workflowId>',
    'host.workflow.usage.resume' => 'Usage: /workflow resume <workflowId>',
    'host.workflow.usage.run' => 'Usage: /workflow run <name> [key=val ...]',
    'host.workflow.usage.status' => 'Usage: /workflow status <workflowId>',
    // --- end chat + host (W11-c) ---
    // --- tui/palette (W11-d) ---
    'tui.agent.backgrounding' => 'Moving {name} to the background at its next step — the turn goes on without it.',
    'tui.agent.context_tokens' => '{count} ctx',
    'tui.agent.line_count' => 'lines: {count}',
    'tui.agent.more_lines' => '+ {count} more line(s)…',
    'tui.agent.outcome.cancelled' => 'cancelled',
    'tui.agent.outcome.done' => 'done',
    'tui.agent.outcome.empty' => 'stopped without a report',
    'tui.agent.outcome.failed' => 'failed',
    'tui.agent.outcome.failed_because' => 'failed: {reason}',
    'tui.agent.outcome.in_background' => 'in the background',
    'tui.agent.outcome.moved_to_background' => 'moved to the background',
    'tui.agent.queued' => 'queued',
    'tui.agent.resumable' => 'resumable',
    'tui.agent.running' => 'running',
    'tui.agent.showing_last' => 'showing last {count}',
    'tui.agent.starting' => 'starting…',
    'tui.agent.status.completed' => 'completed',
    'tui.agent.status.failed' => 'failed',
    'tui.agent.status.pending' => 'pending',
    'tui.agent.status.stopped' => 'stopped',
    'tui.agent.status.streaming' => 'streaming',
    'tui.agent.status.waiting' => 'waiting',
    'tui.agent.status.working' => 'working',
    'tui.agent.thinking' => 'thinking…',
    'tui.agent.tokens' => '{count} tok',
    'tui.agent.tool_one' => '1 tool',
    'tui.agent.tools' => '{count} tools',
    'tui.agent.unnamed' => 'agent',
    'tui.agent.writing' => 'writing…',
    'tui.agent_strip.focused_hint' => '(←/→ · enter · c · x · esc)',
    'tui.agent_strip.hint' => '(alt+↓)',
    'tui.agent_strip.label' => 'agents:',
    'tui.agent_strip.more' => '+{count} more',
    'tui.agent_view.counter' => '{at} of {count}',
    'tui.agent_view.crumb' => 'main',
    'tui.agent_view.hint' => 'esc back',
    'tui.agent_view.no_transcript' => 'No transcript was recorded for this run — live transcripts need ext-pcntl and a saved session.',
    'tui.agent_view.step' => 'step {step}',
    'tui.agent_view.step_of' => 'step {step}/{max}',
    'tui.agent_view.waiting' => 'Waiting for the agent\'s first step…',
    'tui.agents.earlier' => '↑ {count} earlier agent(s)',
    'tui.agents.more_agents' => '+ {count} more agent(s)…',
    'tui.agents.more_hidden' => '… {count} more',
    'tui.agents.no_activity' => '(no activity yet)',
    'tui.agents.none_active' => '(no active agents)',
    'tui.agents.stalled' => 'stalled',
    'tui.chat.welcome' => 'Welcome to SugarCrush! Start typing to chat...',
    'tui.dashboard.group.completed' => 'Completed',
    'tui.dashboard.group.needs_input' => 'Needs input',
    'tui.dashboard.group.ready' => 'Ready',
    'tui.dashboard.group.working' => 'Working',
    'tui.dock.label_left' => 'dock {pane} left',
    'tui.dock.label_right' => 'dock {pane} right',
    'tui.dock.release_to_cancel' => 'release here to cancel',
    'tui.files.empty' => '(no files attached)',
    'tui.input.placeholder' => 'Type your message... (Enter to send, Ctrl+G for group)',
    'tui.mcp.config_changed' => 'Config: changed since launch — restart sugar-crush to apply (reload is not implemented)',
    'tui.mcp.config_error' => 'Config {error}.',
    'tui.mcp.guidance_add' => 'Add servers: declare them under "mcpServers" in the .mcp.json above.',
    'tui.mcp.guidance_recipe' => 'No-auth http remotes just work — recipe: docs/MCP.md, "{section}".',
    'tui.mcp.live' => 'Live in this process: {started} of {declared} declared (other sessions\' servers are not visible here)',
    'tui.mcp.liveness.exited' => 'exited',
    'tui.mcp.liveness.not_up' => 'not up',
    'tui.mcp.liveness.ready' => 'ready',
    'tui.mcp.liveness.ready_in_process' => 'ready (in-process)',
    'tui.mcp.liveness.state_unknown' => 'state unknown',
    'tui.mcp.liveness.tools' => '{count} tools',
    'tui.mcp.liveness.up' => 'up',
    'tui.mcp.path' => 'Path: {path}',
    'tui.mcp.servers_count' => 'Servers ({count}):',
    'tui.mcp.servers_none' => 'Servers: none declared.',
    'tui.mcp.servers_not_listed' => 'Servers: not listed (discovery refused before parsing).',
    'tui.mcp.status' => 'Status: {status}',
    'tui.mcp.status.absent' => 'none — this project declares no .mcp.json.',
    'tui.mcp.status.outside_tree' => 'IGNORED — the config resolves outside the checkout tree.',
    'tui.mcp.status.trusted' => 'trusted — the servers below would start on launch.',
    'tui.mcp.status.untrusted' => 'present but NOT TRUSTED — servers stay hidden until this root is opted in.',
    'tui.mcp.title' => '**MCP Project Config**',
    'tui.mcp.undeclared' => 'Started but no longer declared: {name} ({tools} tools)',
    'tui.menu.currently' => 'Currently: {pane}',
    'tui.menu.empty' => '(empty)',
    'tui.multiplexer.iterm2' => 'iTerm2 (macOS)',
    'tui.multiplexer.none' => 'No multiplexer (in-process rendering)',
    'tui.multiplexer.tmux' => 'tmux multiplexer',
    'tui.pane.label.agents' => 'Agents',
    'tui.pane.label.chat' => 'Chat',
    'tui.pane.label.files' => 'Files',
    'tui.pane.label.help' => 'Help',
    'tui.pane.label.input' => 'Input',
    'tui.pane.label.menu' => 'Menu',
    'tui.pane.label.settings' => 'Settings',
    'tui.pane.label.skills' => 'Skills',
    'tui.pane.label.todo' => 'Todo',
    'tui.pane.label.tools' => 'Tools',
    'tui.pane.more_below' => '… +{count} more',
    'tui.pane.scrolled_more' => '↑ {count} more',
    'tui.pane.title.agents' => 'agents',
    'tui.pane.title.chat' => 'chat',
    'tui.pane.title.files' => 'files',
    'tui.pane.title.input' => 'input',
    'tui.pane.title.settings' => 'settings',
    'tui.pane.title.skills' => 'skills',
    'tui.pane.title.todo' => 'todo',
    'tui.pane.title.tools' => 'tools',
    'tui.picker.age.days' => '{count}d',
    'tui.picker.age.hours' => '{count}h',
    'tui.picker.age.minutes' => '{count}m',
    'tui.picker.age.now' => 'now',
    'tui.picker.badge.background' => 'bg',
    'tui.picker.badge.live' => 'live',
    'tui.picker.badge.subagents' => '+{count} ag',
    'tui.picker.box_title' => 'sessions',
    'tui.picker.confirm_delete' => 'press d again to delete "{name}"',
    'tui.picker.confirm_delete_branch' => 'D also deletes {count} branch',
    'tui.picker.confirm_delete_branches' => 'D also deletes {count} branches',
    'tui.picker.confirm_delete_subagents' => '(+{count} sub-agent)',
    'tui.picker.empty' => '(no sessions)',
    'tui.picker.empty_match' => '(no sessions match)',
    'tui.picker.group.archived' => 'Archived',
    'tui.picker.group.earlier' => 'Earlier',
    'tui.picker.group.pinned' => 'Pinned',
    'tui.picker.group.today' => 'Today',
    'tui.picker.group.yesterday' => 'Yesterday',
    'tui.picker.hints.armed_delete' => 'd delete · D with children · any other key cancels',
    'tui.picker.hints.browse' => '↑↓ browse · ↵ open · esc close · / filter · r rename · d delete · p pin · f fork · x archive · a archived · ⇥ children · space preview',
    'tui.picker.hints.filtering' => 'type to filter · ↑↓ move · ↵ open · ^E rename · ^D delete · ^F pin · esc clear',
    'tui.picker.hints.rename' => '↵ save · esc cancel',
    'tui.picker.no_prompt' => '(no prompt yet)',
    'tui.picker.notice.archive_current' => 'This is the session on screen; switch to another before archiving it.',
    'tui.picker.notice.delete_current' => 'This is the session on screen; switch to another before deleting it.',
    'tui.picker.notice.delete_running' => 'That session is still running; stop it before deleting it.',
    'tui.picker.notice.no_branch' => 'Not on a git branch here, so there is nothing to filter by.',
    'tui.picker.rename_label' => 'rename:',
    'tui.picker.title' => 'session picker · {count} shown',
    'tui.picker.title_archived' => '+archived',
    'tui.picker.title_branch' => 'branch: {branch}',
    'tui.picker.turn' => '{count} turn',
    'tui.picker.turns' => '{count} turns',
    'tui.settings_pane.footer' => 'Enter or /settings: all settings',
    'tui.settings_pane.model' => 'Model',
    'tui.settings_pane.mouse' => 'Mouse',
    'tui.settings_pane.mouse_clicks' => 'Mouse clicks',
    'tui.settings_pane.off' => 'off',
    'tui.settings_pane.on' => 'on',
    'tui.settings_pane.provider' => 'Provider',
    'tui.settings_pane.root' => 'Root',
    'tui.settings_pane.session' => 'Session',
    'tui.settings_pane.streaming' => 'Streaming',
    'tui.settings_pane.theme' => 'Theme',
    'tui.settings_pane.unknown' => '(none)',
    'tui.skills.none_enabled' => '(no skills enabled)',
    'tui.skills.select_title' => 'select a skill',
    'tui.status.error' => 'error: {error}',
    'tui.status.switch_pane' => '[Tab] Switch Pane',
    'tui.todo.empty' => '(no todo list yet)',
    'tui.tools.empty' => '(tool history empty)',
    'tui.tools.more_lines' => '… +{count} lines',
    'tui.tools.newer' => '↑ {count} newer',
    'tui.tools.no_output' => '(no output)',
    // --- end tui/palette (W11-d) ---
];
