# FAQ

Short answers to the questions that come up after the first session. Each
answer names the page or source file that owns the behavior. When an answer
stops working, the symptom-first fixes are in
[TROUBLESHOOTING.md](TROUBLESHOOTING.md).

## Setup and providers

### Do I need an API key to try it?

No. With no provider configured, the TUI and `-p` both answer from the offline
`EchoProvider`, which quotes your prompt back (`src/Cli/LaunchContext.php:2198`).
[TUTORIAL.md](TUTORIAL.md) walks that first run.

### Which providers can I use?

Eleven built-in type keys, listed under `CANDY_CODE_PROVIDER` in
[ENVIRONMENT.md](ENVIRONMENT.md), plus any provider the shipped
`.candy-code/config.dev.json` declares (`dev-sglang` in this repository's copy).
`candy-code models` prints the same providers with each one's default model,
and marks the one this install has selected.
Every provider is built in `src/Providers/ProviderFactory.php`.

### The TUI says "echo" and my model never answers. Why?

No provider is selected for that run. `candy-code doctor` reports
`WARN provider` in that state. The selection order is the environment variable
first, then the provider saved by a previous model switch
(`src/Cli/LaunchContext.php:2268`). If `CANDY_CODE_PROVIDER` is set but the
provider cannot be constructed, the run also falls back to echo
(`src/Cli/LaunchContext.php:2198`). `candy-code models` shows which provider
the run selected.

### Where does my code go?

To the provider you select, and nowhere else. The echo provider contains no
HTTP client code, so a run without a configured provider sends no code or prompts.
Phar installs do make one background GET a day to the GitHub release list for the
"update available" notice; set `CANDY_CODE_NO_UPDATE_CHECK=1` to stop it. The shipped
`dev-sglang` block in `config.dev.json` sends no API key, and this repository does not
say who operates its endpoint, so do not use it for private code. The details are in
[ENVIRONMENT.md](ENVIRONMENT.md#the-shipped-configdevjson-block).

### Where does candy-code keep its state?

Under `~/.candy-code/`. `config.json` holds settings and any saved key,
`session.db` holds sessions, transcripts and checkpoints
(`src/Cli/Bootstrap.php:10345`), and `logs/candy-code.log` holds the TUI's
error log (`src/Diagnostics/TuiErrorLog.php:73`). Project settings live in
`<project>/.candy-code/`.

### Can I use my own model server?

Yes. Implement `src/Providers/ProviderInterface.php` or use `custom`, and see
[ADDING_A_PROVIDER.md](ADDING_A_PROVIDER.md) for the contract. `sglang` is the
type for a model server you run yourself.

## Permissions and safety

### Why did it ask me before running a command?

The TUI starts in `default` mode, where a tool call that the gate does not
already allow waits for your reply (`src/Permissions/PermissionMode.php:13`).
The six modes and the rules that override them are in
[PERMISSIONS.md](PERMISSIONS.md#the-six-modes).

### Why was my tool call refused in `-p` mode?

An unattended run has no one to answer a prompt. Each call that would ask is
refused, and the refusal is written to stderr
(`src/Cli/HeadlessPermissionPrompt.php:483`). Pass `--permission-mode` to
change the mode. Use `bypass-permissions` only for a prompt you trust, because
it runs everything.

### Which permission mode should I use?

Start with `default`. `accept-edits` runs `Edit`, `Write` and `NotebookEdit`
without asking, but only for a file strictly inside the project root and not
under `.git`, `.candy-code` or `.mcp.json` (`Permissions\WritePathScope`, as
[PERMISSIONS.md](PERMISSIONS.md#accept-edits) describes). `auto` hands risky
calls to `SafetyClassifier` before they run. The full table, with the cost of
each mode, is in [PERMISSIONS.md](PERMISSIONS.md#the-six-modes).

### What happens if I run a command with `!`?

It runs at once, without a prompt, and its output goes into the transcript. The
command is yours, so it starts no turn and costs nothing until your next prompt
(`src/Commands/BangShell.php`, class doc). The policy you configured still
applies.

### Why did `/commit` commit a file I did not change?

`/commit` commits every changed file, not only the ones the agent touched
([COMMANDS.md](COMMANDS.md), `/commit`). Run `/diff` before you commit.

### Why is my project's `.mcp.json` not running?

A project's MCP servers start only after you trust that project. The trust
decision is per project root, through `trustedProjectMcp`, and it records each
server's command, arguments and environment. `candy-code mcp list` shows the
servers without starting any of them, and `candy-code mcp trust` approves the
file as it stands (`candy-code --help`). A later change to a server's command,
arguments or environment is refused until you approve it again
(`src/MCP/McpTrustPins.php:44-48`). The full model is in [MCP.md](MCP.md).

### Why do my project's hooks not run?

Hooks in `<root>/.candy-code/hooks.yaml` load only when you have trusted that
project. The `trustedProjectHooks` key controls it (`src/Config/LayeredSettings.php:112`,
`src/Hooks/HookManager.php:141`). Your own `~/.candy-code/hooks.yaml` is read
without that check. The hook events and exit codes are in [HOOKS.md](HOOKS.md).

### Can a cloned repository change my settings?

Not your settings. A project's settings file can fill in only what your own
files leave unset, so a clone cannot override a value you chose
(`src/Config/LayeredSettings.php`, class doc). The trust keys
(`trustedProject*`) cannot be written from a lower layer at all
(`src/Config/LayeredSettings.php:109-118`).

### Where are my API keys stored, and how safe are they?

In `~/.candy-code/config.json`, as plain text, unless you saved it with
`candy-code login --keychain`, which keeps it in the OS keychain and writes only a
reference. The file is written at mode `0600`, so only your user can read it
(`src/Cli/Bootstrap.php:5159`).
Anything that can read files as you can read the key too, including backups and
dotfile sync. The full list of stored secrets is in
[SECURITY.md](SECURITY.md#credentials-on-disk).

## Sessions, changes and cost

### How do I undo what the agent changed?

`/undo` takes back the last turn, or reverts the last auto-commit. `/rewind`
restores an earlier checkpoint, and `/redo` steps forward again
([COMMANDS.md](COMMANDS.md)). In a git repository, checkpoints are built with git
(`src/Workspace/WorkspaceCheckpointer.php:18-27`). A project outside one is
snapshotted into a shadow repository beside `session.db`
(`src/Workspace/WorkspaceCheckpointer.php:30-33`), so `/rewind --files` still has
a point to restore. Two cases have none. Your home directory and its Desktop,
Documents and Downloads folders are refused (`src/Workspace/WorkspaceCheckpointer.php:42-46`,
`:176-202`), and a session store kept only in memory keeps no shadow repository
(`src/Session/EnhancedSessionStore.php:1361-1363`, `src/Workspace/WorkspaceCheckpointer.php:539-541`).
`/rewind --files` says why there is nothing to restore in either case
(`src/Workspace/WorkspaceCheckpointer.php:48-53`). The agent's edits are written
to disk either way.

### How do I find an old session?

`candy-code session list` lists the stored sessions, pinned first. `/sessions`
opens the same list inside the TUI. `candy-code -c` continues the most recent
one, and `candy-code --resume <id>` reopens a named one (`candy-code --help`).

### How do I keep a session from being pruned?

`candy-code session pin <target>`. A pinned session is listed first and never
pruned (`candy-code --help`). `candy-code gc` inspects and reclaims session
storage. Run it with no action to list the actions it offers (`candy-code --help`).

### How do I cap the cost?

Use `--max-cost <amount>` at launch, or `/budget <amount>` inside a session.
`/budget off` removes the cap, and `/budget` alone shows the reported spend
([COMMANDS.md](COMMANDS.md), `/budget`).

## Running it elsewhere

### Can I run it in CI?

Yes, with `-p` and `--output-format json`. The JSON envelope is one document on
the last line, with `schema: 1` (`src/Cli/NonInteractive.php:84`). The exit
codes are `0`, `1` and `2`, and the CI page covers the fork-PR and
prompt-injection rules ([CI.md](CI.md)).

### Can I expose `serve` on my network?

Only with `--allow-remote`. The server binds `127.0.0.1` by default
(`src/Server/ServerConfig.php:27`). Any other bind address is refused unless
`--allow-remote` is given (`src/Server/ServerConfig.php:753`). Put TLS in front
of it, as the refusal message says. Also set `--allowed-ips` to narrow who may
connect, because an empty list admits every address beside loopback
(`src/Server/ServerConfig.php:115`). The full model is in [SERVER.md](SERVER.md).

### Why does `serve` refuse `bypass-permissions` and `dont-ask`?

Because both modes answer every permission ask without a human: `bypass-permissions`
approves it, and `dont-ask` refuses it. The server refuses either mode unless you
pass `--allow-bypass` or set `server.allowBypass`
(`src/Server/ServerConfig.php:262-305`, and the check at `:677`).

### How do I update it?

`candy-code self-update` downloads the phar, checks it against the pinned
Ed25519 key (`src/Cli/SelfUpdate.php:75`), and refuses a payload that does not
verify. A build with an empty key constant is keyless, and binds its bytes only
through the SHA-256 sidecar (`src/Cli/SelfUpdate.php:70-75`). The root policy
describes the release channels in
[`SECURITY.md`](https://github.com/detain/sugarcraft/blob/master/SECURITY.md).

## Where to go next

- [GLOSSARY.md](GLOSSARY.md) for the terms used above.
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for error messages and their fixes.
- [SECURITY.md](SECURITY.md) for the trust model behind the permission answers.
