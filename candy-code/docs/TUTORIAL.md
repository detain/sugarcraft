# Tutorial: first run to first commit

This walks one session from a cold start to a committed change. Step 1 runs
offline, with no API key. Step 2 needs a provider key or a vendor sign-in. Steps
4 and 5 need the model to ask for tool calls. Without a model you can script
those calls with `::tool` lines instead, because the offline echo provider makes
a tool call only from them (`src/Providers/EchoProvider.php:23-30`). Each step
names the key or command to use and the source that implements it, so you can
check a behavior rather than take the page's word for it.

Before you start, check the installation:

```sh
candy-code doctor
```

`doctor` reports the PHP version, the extensions the session store needs, the
config file, the selected provider and the session database. It exits `1` if
any check FAILs (`candy-code --help`, the `doctor` subcommand). Before you have
set a provider it prints `WARN provider`. That is expected for step 1.

## 1. Launch with no configuration

From a project directory:

```sh
cd ~/code/my-project
candy-code
```

With no provider configured, the TUI answers from the offline `EchoProvider`.
`LaunchContext::provider()` falls back to it whenever a run has no provider, or
the provider cannot be built (`src/Cli/LaunchContext.php:2198`). Type
`say hello` and press Enter. The reply is your own words quoted back, and
nothing leaves the machine. `/exit` quits, and it still quits while a reply is
streaming (`src/Host/TurnController.php:309-310`, `src/Chat.php:9595`).

On a machine where nothing is chosen or configured, a first-run wizard can open
(its code is in `src/Tui/FirstRunWizard/`). If you skip it, the status line names
`candy-code login <provider>` as the way to finish setup (`src/Cli/Bootstrap.php:2116`).

## 2. Connect a real model

`CANDY_CODE_PROVIDER` selects a provider by type key. The full list, with the
credential variable for each, is in
[ENVIRONMENT.md](ENVIRONMENT.md) under `CANDY_CODE_PROVIDER`. For OpenAI:

```sh
export CANDY_CODE_PROVIDER=openai
export OPENAI_API_KEY=sk-...
candy-code models
candy-code
```

`candy-code models` lists every provider this install can select, with the model
each one defaults to. The selected one is marked `*`. OpenAI defaults to
`gpt-4o` (`src/Providers/ProviderFactory.php:581`), the same model `models`
prints for it; set `CANDY_CODE_MODEL` to override it.

Exporting variables in every shell gets tedious. `candy-code login openai`
stores the key in the `providers` block of `~/.candy-code/config.json` instead
(`src/Config/ProviderKeyStore.php:87-99`). That file keeps the key as plain text,
so read [SECURITY.md](SECURITY.md#credentials-on-disk) before you store one there.
Pass `--keychain` to keep the secret in the operating system's keychain instead
(`secret-tool` on Linux, `security` on macOS). The config then holds only a
`${keychain:<provider>}` reference (`src/Support/Keychain.php`).

For `claude-code` and `codex`, `login` hands the terminal to the vendor's own
CLI, and the token never passes through candy-code. Those two need no API key,
only a vendor sign-in (`candy-code --help`).

## 3. Start in a project with a first prompt

```sh
candy-code src fix the failing login test
```

A first argument that names an existing directory becomes the project root. The
remaining words are sent as the first prompt (`candy-code --help`, the
`[<dir>] <words…>` form). The root is the workspace the file and shell tools
operate in. See [PERMISSIONS.md](PERMISSIONS.md) for how that workspace is
enforced.

## 4. Answer a permission prompt

When the agent asks to run a tool that the gate does not allow on its own, the
turn pauses on a prompt. To reach a prompt without a model, start a turn with a scripted call. Each
`::tool` line becomes a real call, and the engine runs it through the same gate,
hooks and tool as a model's call (`src/Providers/EchoProvider.php:23-30`):

```text
::tool Write {"file_path":"notes.txt","content":"hello\n","description":"write a test note"}
```

In `default` mode a `Write` waits for your reply. The keys are bound in
`src/Commands/KeyBindingRegistry.php:1308-1329`:

| Key | Reply | What it does |
|---|---|---|
| `y` | `Once` | Runs this one call. |
| `a` | `Always` | Allows calls like this one for the rest of the session. |
| `p` | persist | Saves the allowance as a rule in your user `permissionRules`, so the next launch answers it too. Offered only where a rule can reproduce the grant: a pipeline's per-part grant and an exact-call grant have no `p` (`src/Commands/KeyBindingRegistry.php:1318-1320`). |
| `n` | `Reject` | Refuses this call, so it does not run (`src/Chat.php:3595`). |
| `r` | `Reject` with a note | Refuses this call with words the model reads (`src/Chat.php:3565`, `src/Commands/KeyBindingRegistry.php:1328`). |
| `x` | `Reject` with a fixed note | Refuses this call and stops the turn at the next step boundary. The note reads "The user refused this call and stopped the turn." (`src/Chat.php:3578`, `src/Commands/KeyBindingRegistry.php:1329`). |

The reply enum is `src/Permissions/PermissionReply.php`. Esc stops the running
turn (`chat.stop`, `src/Commands/KeyBindingRegistry.php:1132`).

The TUI starts in `default` mode (`src/Permissions/PermissionMode.php:13`). There
are six modes in all. [PERMISSIONS.md](PERMISSIONS.md#the-six-modes) describes
each one. Switch with `/permissions mode <name>`
([COMMANDS.md](COMMANDS.md)).

## 5. Look at the change, then take it back

Ask the agent to make a small edit (offline, send the scripted `Write` from
step 4 and approve it), then:

- `/diff` shows what changed in the files since a checkpoint. Its form is
  `/diff [n] [--print]`.
- `/undo` takes back the last turn, or reverts the last auto-commit.
- `/rewind [n] [--chat|--files|--both]` restores an earlier checkpoint, either
  the conversation, the files, or both. `/redo` steps forward again over what
  `/rewind` or `/undo` took back.

In a git repository, checkpoints are built with git plumbing: `git stash create`,
then `write-tree` and `commit-tree` (`src/Workspace/WorkspaceCheckpointer.php:18-27`).
A project that is not a git repository is snapshotted into a shadow repository
beside `session.db` instead (`src/Workspace/WorkspaceCheckpointer.php:30-33`), so
`/rewind --files` still has a point to restore. Two limits apply. Untracked
files that are too large, too many, or match the excluded patterns are never
snapshotted, so a restore does not touch them either
(`src/Workspace/WorkspaceCheckpointer.php:34-40`). Your home directory and the
Desktop, Documents and Downloads folders directly inside it are refused outright
(`:42-46`, `:176-202`). When a capture is
refused or fails, the checkpoint row records why, and `/rewind --files` reports
it (`:48-53`).

## 6. Run your own commands

Typing `!` followed by a command runs that command in your shell. Its output
goes into the transcript, where the model reads it on the next turn. The
command starts no turn and costs nothing until your next prompt. It asks no
permission, because you typed it yourself. A policy you configured still
applies (`src/Commands/BangShell.php`, class doc).

```text
!git status
```

## 7. Commit the change

```text
/commit fix the login test
```

`/commit <message>` commits all changed files. With no message it drafts one
(`docs/COMMANDS.md`, `src/Host/Commands/CommitHostCommand.php`). It takes every
changed file, not only the ones the agent touched, so run `/diff` first.

## 8. Cap the spend

```sh
candy-code --max-cost 5
```

Inside a session, `/budget 5` caps the reported spend. `/budget` with no
argument shows the spend so far, and `/budget off` removes the cap
([COMMANDS.md](COMMANDS.md), `/budget`).

## 9. Script it with one-shot mode

`-p` runs one prompt and exits. The JSON form is the one to parse:

```sh
candy-code -p "summarize src/Chat.php in three bullets" --output-format json
```

An unattended run refuses every tool call that would ask. The refusal is
written by `src/Cli/HeadlessPermissionPrompt.php:483`. Pass `--permission-mode`
to change that. The `bypass-permissions` mode runs everything without asking,
so use it only where you trust the prompt.

This output was captured from a run with no provider configured. To reproduce
it, give the run an empty home directory so no saved provider is read, and clear
the environment (`env -i`) so no `CANDY_CODE_PROVIDER` is set:

```sh
env -i HOME="$(mktemp -d)" PATH="$PATH" candy-code -p "say hello" --output-format json
```

The JSON envelope goes to stdout. Two lines go to stderr: a note that `-p` starts
in `default` mode, and a notice that no provider is configured, so the answer is
an echo (`src/Cli/LaunchContext.php:2198`). The `session_id` differs on each run:

```text
{"schema":1,"result":"You said:\n\n> say hello","refusals":[],"session_id":"dd45608ae21ddba9"}
```

The JSON envelope is one document on the last line, with `schema: 1`
(`src/Cli/NonInteractive.php:84`). The exit codes are `0` for an answer,
`1` when the backend ran and failed, and `2` for a usage or pre-flight error
(`src/Cli/NonInteractive.php:108`, `:147`, `:187`).

## 10. Come back to it later

- `candy-code -c` continues the most recent session.
- `candy-code --resume` opens a picker. `candy-code --resume <id>` reopens one
  directly.
- `candy-code session list` lists stored sessions. `session pin <target>` keeps
  one at the top and out of pruning (`candy-code --help`).

## 11. Where things live

| Path | What it holds |
|---|---|
| `~/.candy-code/config.json` | The user config. The CLI writes it, and `Bootstrap::userConfigPath()` names it (`src/Cli/Bootstrap.php:4166`). |
| `~/.candy-code/session.db` | Sessions, transcripts and checkpoints (`src/Cli/Bootstrap.php:10345`). |
| `~/.candy-code/logs/candy-code.log` | The TUI's private error log (`src/Diagnostics/TuiErrorLog.php:73`). |
| `<project>/.candy-code/settings.json` | Project settings. They can only fill in what your own files leave unset. |

Settings come from five layers, lowest precedence first: the project's
`settings.json` and `settings.local.json`, the user's `settings.json` and
`config.json`, then the machine's `/etc/candy-code/config.json`. Your files
outrank the project's, so a clone cannot change a setting you already made.
The reasoning is in the class doc of `src/Config/LayeredSettings.php`, and the
key-by-key reference is [SETTINGS.md](SETTINGS.md).

## Where to go next

- [FAQ.md](FAQ.md) for the questions that come up after the first session.
- [GLOSSARY.md](GLOSSARY.md) for the terms this page uses.
- [PERMISSIONS.md](PERMISSIONS.md) for the modes and rules behind step 4.
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) when a step does not behave as shown.
- [SECURITY.md](SECURITY.md) before you put a real key or a shared project into
  it.
