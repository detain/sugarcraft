# Tutorial: first run to first commit

This walks one session from a cold start to a committed change. The first
four steps work offline, with no API key, so you can see the whole loop before
you connect a model. Each step names the key or command to use and the source
that implements it, so you can check a behavior rather than take the page's
word for it.

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
nothing leaves the machine. `/exit` quits (`src/Commands/CommandRegistry.php:74`).

On a machine where nothing is chosen or configured, a first-run wizard opens
once. Its code is in `src/Tui/FirstRunWizard/`. You can skip it, and
`candy-code login` reopens the same door from the command line.

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
`gpt-4o-mini` (`src/Providers/ProviderFactory.php:108`); set `CANDY_CODE_MODEL`
to override it.

Exporting variables in every shell gets tedious. `candy-code login openai`
pastes the key into the `providers` block of `~/.candy-code/config.json`
instead. That file keeps the key as plain text, so read
[SECURITY.md](SECURITY.md#credentials-on-disk) before you store one there.

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
turn pauses on a prompt. The keys are bound in `src/Commands/KeyBindingRegistry.php:1308-1325`:

| Key | Reply | What it does |
|---|---|---|
| `y` | `Once` | Runs this one call. |
| `a` | `Always` | Allows calls like this one for the rest of the session. |
| `p` | persist | Saves the allowance as a rule, through `Chat::persistPermissionRules()`. |
| `n` | `Reject` | Refuses this call. The turn ends without running it. |

The reply enum is `src/Permissions/PermissionReply.php`. Esc stops the running
turn (`chat.stop`, `src/Commands/KeyBindingRegistry.php:1132`).

The TUI starts in `default` mode (`src/Permissions/PermissionMode.php:13`). There
are six modes in all. [PERMISSIONS.md](PERMISSIONS.md#the-six-modes) describes
each one. Switch with `/permissions mode <name>`
([COMMANDS.md](COMMANDS.md)).

## 5. Look at the change, then take it back

Ask the agent to make a small edit, then:

- `/diff` shows what changed in the files since a checkpoint. Its form is
  `/diff [n] [--print]`.
- `/undo` takes back the last turn, or reverts the last auto-commit.
- `/rewind [n] [--chat|--files|--both]` restores an earlier checkpoint, either
  the conversation, the files, or both. `/redo` steps forward again over what
  `/rewind` or `/undo` took back.

Checkpoints are built with git plumbing: `git stash create`, then `write-tree`
and `commit-tree` (`src/Workspace/WorkspaceCheckpointer.php:18-27`). A project
that is not in a git repository still gets its files written. It has no
step-back point, so `/undo` and `/rewind` have nothing to restore.

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

Real output, captured with no provider configured. The run also writes one
notice to stderr that names the missing provider and the offline echo fallback,
so the answer below is an echo. The `session_id` is an example and differs on
each run:

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
