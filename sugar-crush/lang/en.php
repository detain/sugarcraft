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
];
