<img src=".assets/icon.png" alt="sugar-crush" width="160" align="right">

# SugarCrush

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-crush)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-crush)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-crush?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-crush)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/chat.gif)

A terminal AI coding agent — PHP port of [`charmbracelet/crush`](https://github.com/charmbracelet/crush). It is a candy-core TEA program (a real `Model`/`Program` render loop with buffer-diffed output and Markdown-rendered replies) wrapped around a full agent engine: **multiple LLM providers**, model-driven **tool calling** gated by **hooks**, prompt-injecting **skills**, **sub-agents**, an **MCP** client/server, and **SQLite** session history.

```
┌─ SugarCrush ───────────────────────────────────────┐
│ user> add a test for the Width helper              │
│                                                    │
│ assistant                                          │
│ I'll read the helper first, then write the test.   │
│   ⚙ Read  src/Util/Width.php                       │
│   ⚙ Edit  tests/Util/WidthTest.php                 │
│ Done — added 4 cases covering the clamp edges.     │
└────────────────────────────────────────────────────┘
┌────────────────────────────────────────────────────┐
│ > run them█                                        │
└────────────────────────────────────────────────────┘
 Enter to send · Ctrl+P menu · /exit or ^C to quit
```

> **History:** SugarCrush absorbed the former experimental `candy-crush` port. There is now a single `SugarCraft\Crush` library.

## Run it

```bash
composer install
./bin/sugarcrush
```

With no configuration the binary runs the **offline `EchoProvider`** through the full engine, so it launches with zero network and zero keys. Point it at a real model with environment variables:

```bash
# OpenAI
export SUGARCRUSH_PROVIDER=openai
export OPENAI_API_KEY=sk-...
export SUGARCRUSH_MODEL=gpt-4o          # optional; provider default otherwise
./bin/sugarcrush
```

`SUGARCRUSH_PROVIDER` accepts `openai`, `anthropic`, `claude-code`, `sglang`, `bedrock`, `vertex`, or `custom`. Each reads its own credentials from the environment (e.g. `ANTHROPIC_API_KEY`, AWS ambient creds for Bedrock, `GOOGLE_APPLICATION_CREDENTIALS` for Vertex). When a real provider is active, the binary wires the built-in coding tools (Bash/Read/Edit/Write/Glob/Grep/WebFetch/WebSearch/`doctor`/Skill/Lsp) and the safety hooks automatically. These are **runtime tool names**, and `doctor` is lower-case: `allowedTools`, `disabledTools` and every `permissionRules` pattern match them with case-sensitive `fnmatch()`, so a rule written `Doctor` matches no tool at all. (The class is `Tools\BuiltIn\Doctor`, and the two spellings are worth keeping straight rather than conflating. This sentence used to end — correctly, when it was written — by exempting the Capabilities section below, whose roster spelled the tool `Doctor` "because that is the class and not this name". The exemption is withdrawn, and not because it was untrue: the roster it exempted also listed `Skill` and `Lsp`, whose classes are `SkillTool` and `LspTool`, so it was a class list with two entries that were not class names. A reader copying a name out of it had no way to tell which kind of name they had. That roster now spells all eleven the way the runtime does, and names the three class files beside them.)

Every environment variable SugarCrush reads is documented in [`docs/ENVIRONMENT.md`](docs/ENVIRONMENT.md).

**Where the interactive TUI's diagnostics go.** Parser, provider and runtime warnings are written through PHP's `error_log()`, and in the TUI stderr is the screen being drawn on — so before the TUI starts, `error_log` is pointed at `~/.sugar-crush/logs/sugarcrush.log` (directory 0700, file 0600; a log over 5 MiB at launch is rotated to `sugarcrush.log.1`, and each launch writes a `session start pid=…` header). The ones meant for you also show up in the transcript as system rows. PHP's own warnings and notices go to the same file instead of being displayed, and if PHP hits a fatal error the screen gets one line saying so and naming the log. If your PHP ini already sets `error_log` to something other than stderr, that is left alone. `-p`/`run` and the subcommands are unaffected: they keep writing to stderr. If the log can't be set up (no home directory this process can confirm is yours, or a log directory it can't create), the TUI still launches. In that case the warnings that also have a transcript row are shown only there, and are not written to the screen. Any other `error_log()` line can still appear on the screen, as it did before this change.

### Non-interactive (one-shot) mode

`bin/sugarcrush` parses `argv` *before* it constructs a `Program`, so the
scriptable paths never attach to the TTY or enter the alt-screen:

```bash
sugarcrush -p "explain the Width helper"        # one prompt, print, exit
sugarcrush run "explain the Width helper"       # same thing
sugarcrush -p "audit this" --output-format json # machine-readable envelope
sugarcrush --output-format json run "audit this" # `run` works after flags too
sugarcrush doctor                               # check the install (see Subcommands)
sugarcrush --root /path/to/project              # set the project root explicitly
sugarcrush src                                  # an existing directory as the only argument is the root too
sugarcrush --config ~/policies/crush.json       # read settings/permissions from this file
sugarcrush --model gpt-5 -p "audit this"        # pick the model (not the provider)
sugarcrush --permission-mode plan -p "audit this" # pick the permission mode for this run
sugarcrush --help                               # prints and exits (never opens the TUI)
sugarcrush --version                            # prints the installed version and exits
sugarcrush -- --not-a-flag                      # `--` ends options; everything after is positional
```

`--model <name>` (also `--model=<name>`) names the conversation MODEL and
overrides `$SUGARCRUSH_MODEL`, the model persisted for the provider (the
`models` setting) and the provider's own default. It does not pick a
provider — that still comes from `$SUGARCRUSH_PROVIDER` or the persisted
`provider` setting, and the two are independent axes. The Ctrl+P palette entry
labelled "Switch model" switches the PROVIDER, which is why the distinction is
worth stating twice.

`--permission-mode <mode>` (also `--permission-mode=<mode>`) runs this launch
under one of `default`, `accept-edits`, `plan`, `auto`, `dont-ask`,
`bypass-permissions`. It is the highest-precedence source, beating
`$SUGARCRUSH_PERMISSION_MODE` and the `permissionMode` config key. With none of
the three set, the **TUI starts in `default`** — every write and shell command
asks through the y/n/a modal — while `-p` and background sessions start in
`bypass-permissions`, because their console approver refuses whenever no
terminal is attached (see *Permission modes* below).

A **non-empty** value that is not one of those modes refuses the launch with
exit 2 rather than falling back to the default — the same refusal,
with the same message shape, that the environment variable and the config key
already produce; only the named source differs. (`sugarcrush doctor` is not a
launch: it reports the same bad value as a failed `permission policy` check and
exits 1.)

An **empty** value — `--permission-mode=` or `--permission-mode ""` — is a
usage error, also exit 2, but raised by the argument parser before any of that.
Here the flag is deliberately **stricter than the other two sources**: an empty
`$SUGARCRUSH_PERMISSION_MODE` or `"permissionMode": ""` is read as *absent* and
the run proceeds on the next source down, whereas an empty flag refuses. The
asymmetry is intentional. An unset variable is a normal state of an environment,
but typing the flag is an explicit act, and `sugarcrush --permission-mode="$MODE"`
with `$MODE` unset otherwise leaves the operator believing a mode is in force
when none is — succeeding silently at exit 0 under whatever the config said.
`--config` refuses an empty value for exactly this reason; this flag now follows
that precedent instead of half of it. `--model`/`--model=` is refused the same
way.

Both flags apply to the TUI and to `-p`/`run` alike.

The project root can also be given as a bare argument: a **first** argument
that looks like a path (`sugarcrush ../other-project`, `./app`, `/srv/app`) or
names an existing directory (`sugarcrush src`). It is what the
Bash/Read/Edit/Glob tools are jailed to and where `CLAUDE.md`/`AGENTS.md` and
`.sugar-crush/skills` are looked for.

Any other bare words open the TUI with them as its **first prompt**, the way
`claude "<prompt>"` does: `sugarcrush fix the login bug` starts a session and
sends `fix the login bug` as soon as it is up, and `sugarcrush src fix the
login bug` does the same rooted at `src`. Only the first argument can be the
root, so a directory or file named later is part of the prompt
(`sugarcrush explain src/Chat.php`). Words are joined with single spaces; quote
the prompt to keep its own spacing. With `-c`/`--resume <id>` the prompt goes
to the reopened session (a bare `--resume` puts it in the box once you have
picked one). On a `-p`/`run` one-shot run, where the prompt was already given,
a word left over is still a usage error (exit 2) that asks you to quote the
whole prompt (`-p hi extra`, `-p hi -- extra`), and so is a word before a
subcommand. A lone second directory — another bare one, or one beside
`--root` — is refused rather than letting one of them win. `--root` itself
needs a value that is not an option: `--root --model x` is a usage error rather
than a root named `--model` (use `--root=<dir>` for a directory whose name
begins with `-`). Subcommand operands (`session delete <id>`) are not affected.

### Sessions: new, continue, resume

Every launch opens a **new** session. The conversation is saved as it changes —
every message, tool call, tool result and thought — so any session can be picked
up again later, with the model seeing the whole earlier exchange. Saves are
batched: a change is written within half a second, and at once when you switch
sessions, `/branch`, `/fork` or quit (Ctrl+C included), so only a hard kill
(`kill -9`, a power cut) can lose the last half-second:

```sh
sugarcrush                  # a new session (Up still recalls prompts from earlier ones)
sugarcrush --continue       # reopen the most recently used session (short: -c)
sugarcrush --resume 3f9a    # reopen one by id, unique id prefix, or name
sugarcrush --resume         # open the session picker at launch
```

`--resume <id>` also accepts `--resume=<id>`; ids come from `sugarcrush session
list` or the picker. A target that names no stored session is a usage error
(exit 2) before the TUI starts. `--continue` and `--resume` cannot be combined
with each other or with `-p`/`run`. Inside the TUI, `Ctrl+R` (or `/sessions`)
opens the same picker and `Enter` loads the chosen session's transcript;
`Ctrl+Tab` and the tab strip switch the same way. A tool call that was still
running when its session was last saved comes back marked interrupted.
`--continue` only considers your own conversations: it skips sub-agent
sessions and sessions you have archived. The picker and the tab strip leave
them out too.

A session is named after its first reply by a cheap title model, and a name
you give it always wins over a generated one — even one still in flight when
you typed yours. `/rename <title>` names the current session; a bare `/rename`
(or **Rename session…** in `Ctrl+P`, or a double-click on the current tab)
opens an inline title row above the input box — `Enter` saves, `Esc` cancels,
and saving it empty clears the name and asks the title model for a new one,
which is also what `/rename --auto` does. Pinned sessions (`p` in the picker,
or **Pin or unpin session** in `Ctrl+P`) lead the tab strip with a `★`.

**One window writes a session at a time.** A launch that opens a session
another sugarcrush already has open — `--continue` in a second terminal, the
same `--resume` twice, or picking it in the picker — opens it **read-only**: the
transcript is shown, but prompts and the commands that would change the session
(`/clear`, `/compact`, `/rename`, `/rewind`, `/undo`, `/redo`,
`/workflow run|resume`, any custom command) are refused and nothing is saved, and the status bar leads with
`read-only: /branch to fork` (narrowing to `RO` on a small terminal). `/branch` forks the session into a
new one this window owns and carries on there, and the refused draft comes back
in the box;
commands that only read or change the window itself (`/help`, `/sessions`,
`/theme`, `/model`, …) still work, and so does switching to another session.
Close the other window and this one notices within a second: it takes the
session over, reloads the transcript (so whatever the other window saved is
kept), says so, and puts a refused draft back in the box.
The guard is a `flock()` on `~/.sugar-crush/sessions/<id>.lock`, so it is
released the moment the holding process exits, however it exits. Where the lock
cannot be taken at all (a home directory that refuses the file), the session
opens writable, as before.

Launches that were quit without typing leave empty sessions behind; the next
launch deletes the ones older than an hour (unnamed, with no transcript and no
checkpoint — nothing that could be wanted back).

**Each turn also snapshots your files.** Just before a prompt is sent, the
project's files are recorded with the turn's checkpoint, so a turn's edits can
be undone later. In a git repository the snapshot is a hidden commit under
`refs/sugar-crush/checkpoints/<session>/<n>`. Your branch, index and stash list
are not touched, and `git stash list` does not show it. Outside a repository it
goes into a private git directory under `~/.sugar-crush/checkpoints/`, and
nothing is written into the project. Untracked files over 2 MiB, dependency and
build directories (`node_modules/`, `vendor/`, `dist/`, …), media, archives,
binaries, databases, logs and `.env*` files are left out. No snapshot is taken
in your home directory itself, a directory above it, or `~/Desktop`,
`~/Documents` and `~/Downloads`. A snapshot that cannot be taken never holds up
the turn, but the first time it is refused or fails in a directory the
transcript says why, once.

**Taking a turn back.** `/undo` restores the conversation to the checkpoint
before your last prompt, puts that prompt back in the box, and puts the files
back the way that turn found them. `/redo` steps forward again, one checkpoint
at a time, until you send another prompt. `/rewind [n]` steps back `n`
checkpoints and restores the conversation only (`--chat`, the default); it
tells you when the files differ from that checkpoint, and `/rewind --files`
then puts them back too. `--files` alone restores the files and leaves the
conversation, `--both` does both. `/diff [n]` shows what changed in the files
since checkpoint `n` (`1`, the one before your last prompt, by default), as the
list of files and the patch. A file restore is refused once HEAD has moved since
the checkpoint (it would undo those commits): `--both` and `/undo` then change
nothing, and `--chat` still works. Files a snapshot leaves out are never
touched by a restore. `/redo` moves the files only when they still match the
checkpoint the conversation is at, so edits you made after a rewind are kept.
The refs are deleted with their checkpoints: old ones past the per-session
limit, a deleted session's, and the ones a rewind set aside once the next
prompt is sent. `/branch` keeps its own copy. Unused snapshots in the private
directories are cleaned up by a `git gc` at most once a day.

### Settings files

Four files are read, and the **highest one that mentions a key wins** for that
key:

| # | File | Who wrote it | Wins over |
|---|------|--------------|-----------|
| 4 | `~/.sugar-crush/config.json` | you, and the CLI itself — Ctrl+P and `/theme` write `theme` here, `/model` writes `provider`, and the settings view's save writes the keys it changes (never `provider` or `theme`) | everything |
| 3 | `~/.sugar-crush/settings.json` | you, by hand | the project's two |
| 2 | `<project>/.sugar-crush/settings.local.json` | whoever wrote the repository (`.gitignore`d **by convention**, which is not a trust signal — see below) | the shared project file |
| 1 | `<project>/.sugar-crush/settings.json` | whoever wrote the repository | nothing |

Two things about that order are deliberate and the reverse of what most editors
do. **Your files beat the project's**, because a project file arrived with a
`git clone` — a repository can fill in what you left unsaid and never overrule
a choice you made. And **`config.json` beats `settings.json`**, because it is
the file the CLI *writes*: ranked the other way, a `settings.json` naming
`theme` would outrank what Ctrl+P "Switch theme" **or `/theme <name>`** had just
written, and the choice would fail to stick with no error anywhere and nothing
pointing at the file responsible. (This sentence credited the palette alone
until round 43 — the same omission the `provider` row of the table above once
had, and it matters for the same reason: a reader who reached for `/theme`
cannot tell whether the sentence is about them.)

> **This paragraph used to say `config.json` was "the deprecated name".** It is
> not, and the word was doing real damage in the file most likely to be read:
> it told you to migrate off the only settings file this app ever writes back
> to. What is true is that `config.json` is the *older* of the two names —
> nothing in `src/` marks it deprecated, `Bootstrap::writeUserConfig()` writes
> it (via `Bootstrap::userConfigPath()`), and every persisted `theme` and
> `provider` lands there. The sentence still earns its place because the
> ranking genuinely is surprising and still needs explaining; only its reason
> was wrong. `config.json` keeps working indefinitely, and there is nothing to
> migrate *to*: `settings.json` is never written.

<!-- settings:layered:begin -->
Only these twenty-six keys are layered — `provider`, `models`, `titleModel`,
`summaryModel`, `maxOutputTokens`, `modelPrices`, `extraBody`, `thinkingBudget`,
`promptCache`, `parallelToolCalls`, `parallelToolDeadlineSeconds`,
`maxToolSteps`, `contextWindow`, `secretEnvAllowlist`, `allowedTools`,
`disabledTools`, `instructions`, `disabledRules`, `disabledSkills`,
`enabledSkills`, `includeGitInstructions`, `attribution`, `theme`, `statusLine`,
`layout`, `lintCommands`.
<!-- settings:layered:end -->

That roster (and its count) is generated from `SettingsSchema` by
`php tools/gen-settings-doc.php --write`. The `trustedProject*` lists are read
from `~/.sugar-crush/config.json` **alone**, so no lower layer can grant itself
trust.

`permissionMode` and `permissionRules` are the one pair that is neither: they
are read from `~/.sugar-crush/settings.json` **and** `config.json` (the latter
wins), and from **no project file at any trust level** — a checked-in
`bypass-permissions` would be a sandbox escape delivered by `git clone`. They
also do not go through the layered reader, because that reader is *tolerant* by
design (a malformed file just contributes nothing) and a permission policy may
not be: a `settings.json` that exists and cannot be parsed now **stops the
launch**, exactly as such a `config.json` already did. That is the same bargain,
one file wider — and it is louder rather than newly broken, since such a file
already cost you your theme and provider without saying so.

**A project's settings files are ignored until you opt that project in.**
`<project>/.sugar-crush/settings.json` was written by whoever wrote the
repository, so honouring it out of the box would let `git clone <repo> && cd
<repo> && sugarcrush` pick your model and turn off your skills. Same gate shape
as project hooks and `.mcp.json`, separate key — list the project in
`trustedProjectSettings` in your own `~/.sugar-crush/config.json`:

```json
{ "trustedProjectSettings": ["/home/you/src/that-project"] }
```

Absolute (or `~/`-rooted) paths only; a relative entry like `"."` would trust
every repository you ever run from, so it is refused and reported. And
`settings.local.json` gets **the same gate** as its tracked sibling: `.gitignore`
is advice to whoever commits, not a property of a repo someone else wrote, so a
`git add -f`'d "local" file arrives with a clone just as readily. The two differ
in precedence only.

Even for a trusted project, twenty keys are **never** taken from a project file:
`statusLine`, because its value is a shell command this app runs on a timer —
a project-tier one would be arbitrary code execution on clone-and-launch, with
no tool call and no permission gate anywhere in the path; `lintCommands`, for
the same reason — each value is a lint command the post-edit hook runs after an
edit;
`provider`, because it decides which host every prompt in the session is sent
to; `instructions`, because it decides which files become authoritative
system-prompt text; `disabledRules`, because its value is a list of names
pointing at the operator's own rule packs, so a project-tier one would let a
checkout choose which of the operator's instructions go silent — a different
power from the `disabledSkills` a project *may* set, because a rule pack is
prompt prose the operator wrote, not a capability the harness enforces (see
`RulesState`); `maxOutputTokens`, because it is the one layered key whose
meaningful direction is UP — every raise sends bigger paid requests on the
operator's credential, and a spend ceiling a checkout can lift is a bill a
clone can run up; `modelPrices`, because it sets the rate every billed token
converts at — the mirrored direction on the same money axis, where a
project-supplied map could zero a rate and silently blind the spend cap and the
`/budget` totals, the exact failure the unpriced-model notice exists to make
loud; `models`, `titleModel` and `summaryModel`, because they choose the model
the session itself and every title, prompt suggestion and `/compact` summary
run on with the operator's key — within one provider the price spread is over 100×, and a model with no
price on file bills as $0, so a project-chosen one blinds the spend cap the same
way a zeroed rate would; `layout`, because it records where the operator chose to put
their own windows — frame geometry is a personal habit, not a property of the
checked-out code, and a project that moves your panes behind your back is
answering to the wrong owner; `maxToolSteps`, because it multiplies how many
billed provider round-trips one turn may fan out — the `maxOutputTokens` money
axis counted in calls instead of tokens, and a ceiling a checkout can raise is
still a bill a clone can run up on the operator's credential;
`secretEnvAllowlist`, because it names which of the operator's credentials
Bash, Grep and script hooks may still inherit after the scrub that keeps them
out of model-visible output — a project-tier `["*"]` would read every key in
the operator's shell back through one `env` call; `contextWindow`,
`extraBody`, `thinkingBudget` and `promptCache`, the four provider-shaping
keys, because each moves the bill — an inflated window switches
auto-compaction off so requests grow until the server refuses them, an extra
body field such as `n` multiplies every request, a thinking budget is billed as
output, and switching prompt caching off bills every prompt in full;
`attribution`, because it is the trailer the model is told to stamp on every
commit it makes under the operator's git identity, and a checkout choosing that
text would be a repository writing into the operator's own history (its sibling
`includeGitInstructions` only removes prompt text, so a trusted project *may*
set that one); `enabledSkills`, because it names skills whose full bodies
ride the system prompt every turn — the `instructions` argument applied to
skills, where a checkout could make any skill it ships standing,
authoritative prompt text (the `disabledSkills` a project *may* set only ever
removes one); and
`allowedTools`, for a reason worth spelling
out because on capability alone it looks harmless. A whitelist is an intersection — it
cannot add a tool that `Bootstrap::tools()` did not build — but its effect is
defined by what it *omits*, so `allowedTools: ["Bash"]` deletes <!-- tools:others:begin -->all twelve of the others — `Read`, `Edit`, `Glob`, `Grep`, `Write`, `WebFetch`, `WebSearch`, `doctor`, `Skill`, `Lsp`, `Memory` and `RepoMap` —<!-- tools:others:end --> in one line, and what the model does next is the
same work through `Bash`, which reaches the permission gate as opaque shell text
instead of as a reviewable path. Strictly fewer tools, strictly coarser review.

Its sibling `disabledTools` *is* available to a trusted project — but not for
the reason this section used to give.

> **This paragraph used to say that expressing the same attack through
> `disabledTools` "means naming every tool it removes — a value you can see".
> That is false**, and it is corrected here rather than deleted because it was
> the stated reason for the tiering, and because it is the sentence you would
> lean on when deciding whether a cloned repository's settings need reading at
> all. `Bootstrap::filterToolSet()` matches names through
> `PermissionRule::matchesToolName()`, which is bare `fnmatch()`, and
> `fnmatch()` honours negated character classes. Measured end to end on PHP
> 8.3.6, in a project you have listed under `trustedProjectSettings`:
>
> ```json
> { "disabledTools": ["[!B]*"] }
> ```
>
> leaves exactly `Bash` out of the thirteen built-in tools the project tier can filter.
> `Task`, the concrete `Tool` class wired outside that ceiling since E675, is
> appended after `filterToolSet()` and gated on the launch holding an
> `AgentManager` — outside every project glob's reach — so on a manager-bound
> chat launch it stands there too. The glob is five
> characters and names none of the twelve it removes. The negation is not the
> trick either: `["[C-Z]*", "[a-z]*"]` leaves exactly `Bash` too, measured the
> same way, so no restriction on *pattern shape* could make the old sentence
> true again. What earns the paragraph its place is the shape argument for
> `allowedTools` above, which does hold; what it lost is the claim that
> `disabledTools` cannot express the same thing.

**Two things narrow it, and both are measured.** An *untrusted* project's
`disabledTools` never reaches the merge at all — all thirteen filterable tools survive, and `Task`, being post-filter, was never in this setting's reach to lose — so
this needs a `trustedProjectSettings` grant you made yourself. And the layers
merge key by key rather than as a union: if *you* name any `disabledTools`,
yours replaces the project's outright — your `["Read"]` against a trusted
project's `["[!B]*"]` removes exactly `Read` and leaves everything the
project's glob named. The gap is open only for an operator who trusted a
repository and set no `disabledTools` of their own.

So **a trusted project's `disabledTools` can choose your tool set** — do not
grant `trustedProjectSettings` to a repository you would not trust with
`allowedTools`. What it can no longer do is choose it *unnoticed*: a trusted
project's tool removals are reported at launch, naming the file, the tools it
took and the tools it left.

```text
sugarcrush: /repo/.sugar-crush/settings.json (disabledTools) disabled 12 of the
13 tools your own settings left — Read, Edit, Glob, Grep, Write, WebFetch,
WebSearch, doctor, Skill, Lsp, Memory, RepoMap — leaving: Bash.
```

The two keys are combined as one condition rather than as two passes — a
tool survives only if your allow-list admits it *and* no deny entry names it —
so there is no later step in which a project's `disabledTools` could re-admit
something your `allowedTools` left out. Put the whitelist in your own file where
you can see it.

`--config <file>` (also `--config=<file>`) replaces the per-user
`~/.sugar-crush/config.json` for this run: the theme, the persisted provider,
the `instructions` globs, the `permissionMode`, the `permissionRules` and the
`trustedProjectHooks` list all come out of the named file, and the discovered
one is not merged in. It names one **file**, not a config directory — agents,
skills, workflows, sessions and memory still live under `~/.sugar-crush`, and
`--config` does not relax the "is this home directory yours" check that guards
them. **`settings.json` is one of the things it does not move**: layer 3 above
is always `~/.sugar-crush/settings.json`, never a `settings.json` sitting next
to the file you named. Otherwise `--config ./anything.json` would hand a
directory nobody vetted the user tier — the tier that may set `provider` and
`instructions`. One consequence of that, worth stating because "replaces the
per-user config" reads like a clean substitution and this is not one: since
`~/.sugar-crush/settings.json` may now carry `permissionMode`, it is a policy
file, and a policy file that exists and cannot be parsed stops the launch —
including when you named a perfectly good file with `--config`. `--config` is
not an escape hatch from a broken home config, and deliberately so: it is
documented above as *not* disarming the gate, and letting it suppress an
unreadable policy file would be exactly that. Move or fix the broken
`settings.json`. The file must already exist and be readable; naming one that does not is
a usage error (exit `2`) rather than a fall-back to discovery, for the same
reason `--root /typo` is one — silently running the DEFAULT permission policy
while the operator believes a restrictive one is in force is worse than not
starting. So is a `--config` with no value at all, or one followed by another
option (`--config -p "hi"`, which used to eat the `-p` as the file name): a
missing value is indistinguishable from an absent flag once parsed, so
accepting it is the same silent fall-back to discovery.

Because the named file carries the permission policy, it is held to the **same
standard as `~/.sugar-crush/config.json`**: it must be owned by you, and
neither it nor its directory may be world-writable. That rules out the two
paths people reach for first — a file under `/tmp` is refused for the
directory's `o+w` bit, and a root-owned `/etc/crush.json` for its ownership —
and the refusal is a launch-time `PermissionConfigException` (exit `2`) from
`Bootstrap`, worded about the file rather than about the flag. The check is
deliberately not duplicated in `ArgvParser::configError()`, which validates
existence and readability only: two copies of an ownership/mode rule is how the
two drift apart.

`--output-format` accepts exactly `text` (the default) and `json`, matched
case-sensitively; anything else is a usage error (exit `2`) on both the
one-shot and the TUI path. It used to be accepted verbatim and then compared
for equality against `json` at each consumer, so `--output-format xml` printed
plain text and exited `0` — a `| jq` caller got an empty pipe with a success
status.

**One-shot mode never falls back to the offline echo provider.** If this run
selected a provider — via `$SUGARCRUSH_PROVIDER` or a persisted Ctrl+P "Switch
model" choice — and that provider cannot be constructed (unknown name, missing
credential), `-p`/`run` prints the reason to stderr and exits **2** rather than
returning a canned reply at exit 0. The stderr line names the source it came
from, so a persisted choice sends you to `~/.sugar-crush/config.json` rather
than to a `$SUGARCRUSH_PROVIDER` nothing ever set. The interactive TUI keeps
the opposite, lenient behaviour: it warns and opens an offline session, because
refusing to launch an editor over a missing API key is worse than an offline
one.

The same three exit codes govern every subcommand below.

| exit | meaning |
| --- | --- |
| `0` | the prompt ran and produced an answer, or the subcommand answered |
| `1` | ran and failed: the backend threw (unreachable host, rejected key, model error), the answer could not be encoded in the requested format, a `doctor` check came back `FAIL`, `session delete` found no such session, or a trusted `.mcp.json` could not be parsed — retrying may help. `error.type`: `backend`, `encoding`, `mcp-config`, `not-found` |
| `2` | usage/configuration error, nothing was attempted: no prompt given, unrecognized flag, a word left over after `-p`/`run`'s prompt or before a subcommand, a second project directory, an `--output-format` value that is neither `text` nor `json`, `--config` naming no readable file, `--root` naming no directory, a missing `vendor/autoload.php`, a **permission policy that is present but unusable** (see [Permission modes](#capabilities) — an unreadable/unreachable/unparseable `~/.sugar-crush/config.json`, or a `permissionMode` naming no real mode), or a provider (from `$SUGARCRUSH_PROVIDER` **or** the persisted Ctrl+P choice) that cannot be constructed — retrying will not help. `error.type`: `usage`, `provider_configuration`, `installation` — the last one is the missing `vendor/autoload.php`, and it is what tells a consumer which kind of `2` it got |

`2` covers "no prompt given" (`sugarcrush -p`, `sugarcrush run`) deliberately:
the invocation is malformed, no backend is ever selected, and a CI gate that
retries on `1` would otherwise retry it forever. It also covers a subcommand
handed a missing or unknown operand (`sugarcrush session`, `sugarcrush mcp
bogus`, `sugarcrush completion tcsh`).

### Subcommands

```sh
sugarcrush doctor                    # check this installation, exit 1 if anything FAILs
sugarcrush models                    # providers this install can select; * marks the selected one
sugarcrush session list              # stored sessions, pinned then newest first
                                     #   [--children] [--archived] [--all] [--limit N]
sugarcrush session show <target>     # one session's details + transcript (Markdown)
sugarcrush session rename <target> <title…>
sugarcrush session delete <target>   # sub-agent children go too; [--with-children] takes branches
sugarcrush session pin|unpin|archive|unarchive <target>
sugarcrush mcp list                  # what .mcp.json declares — without starting anything
sugarcrush mcp trust                 # approve .mcp.json as it is now (command/args/env pinned per server)
sugarcrush mcp import claude|opencode <path>
                                     # translate a foreign MCP config, print the block — writes nothing
sugarcrush serve                     # WebSocket + HTTP server for the web UI, until Ctrl+C
                                     #   [--host IP] [--port N] [--allow-remote] [--allowed-origin LIST]
                                     #   [--web-root DIR] [--no-web] [--allow-bypass] [--allow-root]
sugarcrush completion bash|zsh|fish  # a shell completion script on stdout
```

Every one of these is dispatched in the same pre-flight place `--help` and
`--version` are, **before** `Program` is constructed: they answer on a machine
with no provider, no API key and no TTY, and none of them enters the
alt-screen. `serve` is the one that keeps running: it binds `127.0.0.1:7420`,
prints a one-time sign-in URL, and answers until `Ctrl+C` or `SIGTERM`. Every
client must authenticate — loopback is not trusted on its own — and a
non-loopback `--host`, the bypass permission modes and running as root are each
refused unless their `--allow-*` flag is given; it also refuses to start
without `ext-pcntl`, `ext-posix` and `ext-ffi` (`doctor` reports all three).
The flags belong to `serve` (before it they are unknown options). Its
transport, auth flow and security model are in
[docs/SERVER.md](docs/SERVER.md). `doctor` is the sharpest case — it is a health check for an install
that may be broken, so it must not require the thing it is diagnosing. A
config whose `permissionMode` is unusable makes the launch refuse to start
(exit `2`, above); `doctor` still runs, names that as the failing check, and
exits `1`.

A session `<target>` is an id, a name or a unique id prefix — the resolver
`--resume` uses, over every kind and archived rows too. Nothing matching exits
`1` (`not-found`); an ambiguous prefix exits `2` and lists the candidates. The
flags after `session list` and `session delete` belong to that verb: before it
they are unknown options. `list` prints `★ id updated kind turns
provider/model name` (★ = pinned, `[archived]` after an archived name), and its
JSON rows carry every stored column (`kind`, `parent_id`, `pinned`,
`archived_at`, `turns`, …). A pinned session lists first and is never pruned;
an archived one leaves the default list, the tab strip and `--continue` but
keeps its transcript.

`doctor` is **read-only**: it counts the rows in the session database through
`Bootstrap::sessionStore(prune: false)` rather than the plain accessor, so the
opt-in `SUGARCRUSH_SESSION_RETENTION_DAYS` sweep that a *launch* applies cannot
delete conversations on the way to a health check. `doctor` and `models` take
no operands and reject one at exit `2` rather than ignoring it.

`sugarcrush doctor` is **not** the model-callable `doctor` tool. That one is
registered in `Bootstrap::tools()`, advertised to the LLM, and answers a
completely different question — which image protocol this terminal speaks, with
a PNG capability swatch attached. The CLI subcommand reports PHP, the
extensions the session store and `serve` need, the config file, the permission policy, the
selected provider, the session database and the project MCP config, takes no
model, and cannot be reached by a tool call.

`mcp list` **reads and never runs**. `Bootstrap::mcpClient()` starts every
configured server as a side effect of being asked for the client, so routing a
listing through it would `proc_open()` every program the repository names — the
exact act the trust gate exists to make deliberate, performed by the command
you run *because* you do not yet trust the file. It shares its path,
containment and trust decision with `mcpClient()` (both go through
`Bootstrap::mcpConfigDecision()`), so it can never report a verdict the launch
disagrees with; an untrusted or out-of-tree config is reported rather than
enumerated.

`--output-format json` applies to `doctor`, `models`, every `session` verb,
`mcp list` and `mcp import`, producing the same `{"result": …}` envelope the
one-shot path does, and
the same `{"result":null,"error":{"type":…,"message":…}}` document on any
failure — an operand error, an unknown session id, or an unreadable trusted
`.mcp.json`. **The exit code never depends on the format**: `sugarcrush mcp
list` and `sugarcrush --output-format json mcp list` return the same code for
the same install, so a CI gate can be written either way. A refused config
(absent, out of tree, untrusted) is an answer and exits `0` in both. `completion` is the exception, and deliberately: its output is a shell
script you `eval`, and JSON-quoting it would produce something no shell can
source — the same reasoning that keeps `--help` and `--version` plain text.

A `1` from the engine already had its retries. Transient provider failures — a
connect failure, a 5xx, a 408/429, an Anthropic `overloaded_error` — are retried
inside the provider call with exponential backoff before the run gives up, so
for a provider-selected run (and for the offline default, which is the engine
too) `1` means "every attempt failed", not "one attempt failed". An outer retry
still helps for an outage longer than the couple of seconds of backoff that
spends; it is just not the first one.

The retry lives in the provider call, so it covers the engine and nothing else.
A run whose backend is either `$SUGARCRUSH_BACKEND_CMD` variable's external command delegates
instead of calling a provider, and its `1` is a first attempt — retrying it from
outside is the only retry it gets.

With `--output-format json`, stdout is always exactly one JSON object: either
`{"result": <the answer>}` or `{"result": null, "error": {"type": "usage" |
"provider_configuration" | "installation" | "backend" | "encoding" |
"not-found" | "mcp-config", "message":
"...", "provider": "..."}}` (`provider` present only when a selection is to blame), so
a `| jq` consumer never sees an empty pipe. That holds for the flag, `--config`
and `--root` usage errors too, which `bin/sugarcrush` catches before the one-shot
path is entered, and for a reply or an error message carrying bytes that are
not valid UTF-8 (they are substituted, not dropped along with the whole
document). `error.type` is not the exit code renamed — `usage`,
`provider_configuration` and `installation` are all `2`, `backend`,
`encoding`, `not-found` and `mcp-config` are all `1` — it is how a consumer
that kept the code tells apart the kinds of each. The set's spellings are
mixed by history, not by mistake: five names are single words or
`snake_case`, while `not-found` and `mcp-config` are the two `kebab-case`
names `src/Cli/Subcommands.php` added when its subcommand errors joined the
contract. Renaming them to `snake_case` was weighed and declined (E116);
match exact strings, never an inferred alphabet.

One key is **conditional**, and it is the only one: `refusals`. A turn that
blocked a tool call — a permission ASK that nothing could answer, an explicit
`n` at the prompt, a hook that denied the call outright — adds
`"refusals": [{"tool": "<name>", "kind": "<which of the three>", "reason":
"<why it was stopped>"}, …]` to whichever document it emits, the answer and
the error one alike. A row for an ASK that the run answered by refusing it
because there was no terminal to ask at carries one extra key,
`"unattended": true`; a refusal a human answered — with `n`, or an EOF at a
live prompt — never does. One `kind` covers both nobody-being-there and a
person-saying-no, whose reason texts are byte-identical, so this key is a
qualifier on the row, not a fourth `kind`. `kind` is one of exactly three tokens and says which of
those three things happened: `hook` (a hook denied the call outright),
`refused` (an approver was asked and answered no) and `unanswered` (the call
needed permission and there was nobody to ask). It is there so a consumer does
not have to re-match the prefix `reason` opens with — that prefix is still
present and unchanged, so a script written against the older document keeps
working. Before this,
the document read `{"result": "<the answer>"}` for a turn in which a tool was
quietly not run, and the model — which *did* see the refusal — had simply
answered around it. `refusals` is **absent, not empty**, on a run that refused
nothing, so the ordinary document is unchanged and `jq '.refusals // []'` is
the way to read it unconditionally. It is not a list of tool calls that
*failed*: a tool that ran and returned an error is a result the model acted
on, whereas a refusal is a call that never happened. That distinction is carried on
the result by whoever refused the call, never read off its text, so a tool
that ran and printed a line opening `Permission denied:` before failing — a
shell script, an MCP server — is not listed (audit F-P8).

`--output-format text` carries no such list on stdout, and **this paragraph
has been wrong about that twice.** It first said the format needed none
because "every refusal is already on stderr, in front of the person reading
the terminal" — not true, and least true for the commonest refusal there is,
since only the console permission prompt wrote to stderr and it is only ever
reached for a hook verdict of ASK. It then said the gap was real and that
"the fix is a stderr line on the deny path". The diagnosis was right; the
owner was not. `Runtime` is the engine, and the TUI forks into the same
`Runtime` with descriptor 2 pointing at a terminal that is in the alternate
screen — a refusal line written there would paint over a live frame on every
denied call.

**What is true now: on the `-p` one-shot path, every refused tool call is
announced on stderr,** one line per refusal, naming the tool and quoting the
reason the model was given — which opens with `Hook denied:`,
`Permission denied:` or `Permission required:` depending on whether a hook
objected, you answered no, or nothing was attached to ask. It is written from
the one-shot path itself, which owns its console, and it appears on **both**
formats: stdout under `text` stays the answer and nothing else, and under
`json` it stays exactly one object, so a shell pipeline and `jq` are both
unaffected.

The scope in that sentence is deliberate. The interactive TUI has never had
this gap — it draws a refused call struck through — but a **background
session** still does: `BackgroundSessionRunner` calls the backend's
`complete()` with a token callback and no event callback at all, so nothing
downstream ever sees the refusal to announce it. That one is recorded, not
fixed here.

Three of the seven are **not** `NonInteractive::emitErrorDocument()`'s, and
that is the thing to know if you are reading the source to find where a type
comes from. `installation` is hand-rolled by `bin/sugarcrush`'s autoload guard
— see the one exception below for why it has to be. `not-found` and
`mcp-config` come from the subcommands, which build their own documents
through `Subcommands::emitDocument()`: `session delete <id>` on an id the store
does not hold, and `mcp list` on a **trusted** `.mcp.json` that could not be
read or decoded. Both exit `1` — the store and the file were opened, so
something was attempted; an absent, out-of-tree or untrusted `.mcp.json` is an
answer rather than a failure and exits `0` with a `status` field saying so.

Two shapes of that contract are easy to over-read, so both are stated
plainly. **`result` is not always a string.** It is one on the one-shot path
(`-p`/`run`), where the answer *is* text; every subcommand puts an object
there — `{"result":{"checks":[…],"failed":2}}`, `{"result":{"sessions":[…]}}`.
And **a failure does not always carry an `error` object.** `doctor` exits `1`
when any check came back `FAIL`, and its document is still
`{"result":{…}}` with no `error` key at all, because the failing checks *are*
the answer and naming one of them `error.type` would say less than the report
already does. (MEASURED: `sugarcrush doctor --output-format json` on an
install with a failing check → rc 1, one `result` object, no `error`.) So
branch on the **exit code** first and on `error.type` second; a consumer that
reads `.error.type` to decide whether the run failed will read `null` on that
one.

There is exactly one exception, and it is not a case of the JSON renderer
being unavailable — it is the caller asking for a rendering nothing implements:
an **`--output-format` value that is not `text` or `json`**. That run exits `2`
with an **empty stdout** and its message on stderr, because the requested
rendering is the thing being rejected, so there is no format left to honour —
emitting the JSON document anyway would mean guessing that `--output-format
xml` meant `json`, and `text` is what an unrecognised value has always fallen
back to. (MEASURED: `sugarcrush -p hi --output-format xml` → rc 2, stdout 0
bytes.) Note the scope: it is the **invalid value** that is exempt, not the
flag — a *valid* `--output-format json` alongside any other usage error, such
as `--output-format json --config /nonexistent`, still emits the document.

**A checkout with no `vendor/autoload.php` is no longer an exception**, and this
section used to say it was.

> **What this used to say.** That there were *exactly two exceptions*, the second
> being a checkout with no `vendor/autoload.php`, which "exits `2` with an empty
> stdout, because the class that owns the JSON document shape is precisely the
> one that could not be loaded, and hand-rolling a second copy of the shape in
> `bin/sugarcrush` to cover it would be the drift that having one definition
> prevents".
>
> **What is true now.** That branch emits the document, and has since the
> autoload guard was given one. On a checkout with no `vendor/autoload.php`,
> `sugarcrush --output-format json -p hi` prints
> `{"result":null,"error":{"type":"installation","message":"sugarcrush: cannot
> find composer autoload.php"}}` and a newline on stdout, the same message on
> stderr, and exits `2`. The old reasoning had the wrong half of the problem:
> the shape's **owner** is unreachable there, the shape itself never was.
> `json_encode()` is a core function and needs no autoloader, so the choice was
> never "document or no document" — it was "one duplicated document, or an
> empty pipe", and an empty pipe is the worse of the two by this contract's own
> argument, because a consumer cannot tell "empty because the binary died before
> it could speak" from "empty because there was nothing to say".
>
> **Why the old worry still earns its place.** Hand-rolling a second copy of the
> shape really is the drift that one definition exists to prevent, so it is paid
> for rather than waved away: the guard in `bin/sugarcrush` and
> `NonInteractive::emitErrorDocument()` each name the other, and
> `BinSugarcrushAutoloadGuardTest` asserts the two documents key-for-key **and**
> the two `json_encode()` flag expressions token-for-token — a key comparison
> alone cannot see an encode flag. Run `composer install` all the same: the
> document reports a broken checkout, it does not repair one.
>
> The guard also honours `--output-format` only when this invocation actually
> asked for `json`, read straight out of raw `argv` because the option parser is
> behind the same missing autoloader. Any other value leaves stdout empty, which
> is what the working binary does on that same input.

With no provider configured at all, one-shot mode still answers offline from
the `EchoProvider` and exits 0 — nothing was substituted for anything — but
says so on stderr.

### Dependency-free shell-out

To avoid PHP SDKs entirely, set `SUGARCRUSH_BACKEND_CMD` to a command that reads JSON history on stdin and writes the reply to stdout:

```bash
export SUGARCRUSH_BACKEND_CMD=~/bin/anthropic.sh
./bin/sugarcrush
```

```bash
#!/usr/bin/env bash
# ~/bin/anthropic.sh — keeps PHP network-dep-free, swap models by editing this file
payload=$(jq -nc --argjson h "$(cat)" '{model:"claude-opus-4-8", max_tokens:4096, messages:$h}')
curl -sN https://api.anthropic.com/v1/messages \
  -H "x-api-key: $ANTHROPIC_API_KEY" -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" -d "$payload" | jq -r '.content[0].text'
```

There is a second variable for the *streaming* shell-out,
`SUGARCRUSH_BACKEND_CMD_STREAM`, and it is deliberately not the same one. It is
a **token-stream** protocol, not a prose one: the wrapper writes **one token per
line**, the newline *between* two tokens is framing and is dropped, and a
**blank line means a literal newline** in the answer (an unterminated empty
remainder at EOF means nothing). That blank-line rule is the only way the
protocol can express a line break at all, and it is what makes it able to
express any string.

So the two contracts are mutually exclusive in both directions. Run the prose
wrapper above through the streaming variable and every newline it emitted is
gone while each blank line it emitted comes back as *one* newline rather than
two — a paragraph break, a list and a code fence do not survive the trip. Run a
token-per-line wrapper through `SUGARCRUSH_BACKEND_CMD` and you get its framing
newlines verbatim, one word per line. That is why the streaming backend has its
own variable instead of inheriting this one. `SUGARCRUSH_BACKEND_CMD` wins if
both are set; for either variable, unset, empty and whitespace-only all count as
absent. Neither path imposes any completion deadline.

**"Streaming" buys you a live screen, not just the callback.** The backend
invokes its per-token callback as each token's newline lands on the pipe, *and*
the TUI repaints between the tokens. The second half used to be false and is
worth recording because it was measured both ways: the read loop ran to
completion inside one ReactPHP `futureTick`, so the event loop was blocked for
the duration of the completion and the TUI repainted once, when the answer
resolved. Measured against a wrapper emitting six tokens 300ms apart, with a
50ms periodic timer standing in for the render tick:

| | before | after |
|---|---|---|
| callbacks | 6, at 0.005s/0.304s/0.608s/0.907s/1.210s/1.514s | 6, at 0.010s/0.309s/0.609s/0.914s/1.214s/1.515s |
| loop ticks during the stream | **0** | **36** |

The read loop was not rewritten, it was hoisted: one implementation of the
stdout protocol, driven either by the blocking path's own loop or by a periodic
timer on the event loop. `SUGARCRUSH_BACKEND_CMD` had the same defect in a worse
form — its promise executor ran the blocking call *immediately*, so the freeze
started before the promise was even returned — and is drained from a timer now
too. The one-shot `-p` path passes no callback at all and blocks deliberately.
See [`docs/ENVIRONMENT.md`](docs/ENVIRONMENT.md#the-two-shell-out-variables) for
the byte-level comparison and for the Windows `bypass_shell` note.

```bash
export SUGARCRUSH_BACKEND_CMD_STREAM=~/bin/ollama-stream.sh
./bin/sugarcrush
```

```bash
#!/usr/bin/env bash
# ~/bin/ollama-stream.sh — satisfies the TOKEN protocol, not the prose one:
# `jq -r` prints one line per streamed content chunk, and prints an EMPTY line
# for a chunk that is just "\n" — which is exactly how this protocol spells a
# line break. Do not point this variable at the prose wrapper above.
payload=$(jq -nc --argjson h "$(cat)" '{model:"llama3", stream:true, messages:$h}')
curl -sN http://localhost:11434/api/chat -d "$payload" | jq -r '.message.content'
```

### Choosing a backend without editing anything

Three ways to get off the offline `EchoProvider`, from quickest to most permanent:

1. **One-off, this run only:** `SUGARCRUSH_PROVIDER=dev-sglang ./bin/sugarcrush` — `dev-sglang` is sugar-crush's own dev/test SGLang endpoint (declared in the `.sugar-crush/config.dev.json` that ships inside the sugar-crush package — not a file in the project you run it in), useful for trying a real (if smaller) model with zero API keys.
2. **From inside the TUI:** press **Ctrl+P**, choose **Switch model**, pick any provider from the list (built-in types plus every name declared in the package's own `.sugar-crush/config.dev.json`, e.g. `dev-sglang`) — switches immediately, no restart. `/model` opens the same picker and `/model dev-sglang` skips it; `/model dev-sglang <model id>` switches to that model on it as well. **Switch theme** works the same way for color themes.
3. **Persisted across restarts:** either of the above choices — the palette's, or `/model`'s, which goes through the same code path — is written to `~/.sugar-crush/config.json` and read back on the next launch — so picking `dev-sglang` once via Ctrl+P means every future `./bin/sugarcrush` (with no env vars set at all) uses it automatically. `$SUGARCRUSH_PROVIDER`/`$SUGARCRUSH_BACKEND_CMD`/`$SUGARCRUSH_BACKEND_CMD_STREAM` still take priority over the persisted choice when set, for scripting/CI overrides. The **model** persists too, separately and per provider: `"models": {"dev-sglang": "<model id>"}` in `config.json` (or your `settings.json`) is the model that provider runs whenever it is selected — at launch and after a switch — unless `--model` or `$SUGARCRUSH_MODEL` names one; keyed by provider because a model id means nothing to any other provider. `/model <provider> <model>` writes that entry for you (through the settings view's writer, carrying the other providers' entries along) and switches to it at once — a session started with `--model` or `$SUGARCRUSH_MODEL` still runs the model you just picked, and keeps outranking the saved entry at the next launch. There is no top-level `model` key.

## Using the TUI

The interactive binary boots a **pane shell** (`App`) that hosts the chat
model, so the menu bar, pane strip, session tabs and the chat transcript are
all one candy-core `Model` tree — not two parallel UIs.

### Keys

| Key | Does |
|-----|------|
| `?` (blank input) | Show the in-app keyboard reference. Blank means `trim()`-empty, i.e. empty or made only of the six bytes `trim()` strips — space, tab, newline, carriage return, `NUL` and vertical tab. Not the same as "whitespace-only", in both directions: a line of non-breaking spaces (`U+00A0`), ideographic spaces (`U+3000`) or a form feed is *not* blank, so `?` types a character there, while `NUL` **is** blank without being whitespace. Only two of those six bytes can be typed — space, and `NUL` via `Ctrl`+`Space`. A newline draft is *composed*, with `Alt`+`Enter` (or `Shift`+`Enter` / `Ctrl`+`Enter`, on terminals that report those distinguishably). The other three (tab, carriage return, vertical tab) have no key at all, and no route you can exercise on purpose: sending a message never puts one in your history either, because `Enter` trims the draft before it is sent. They reach the input box exactly one way, and not in the shipped binary — **`/rewind` to a checkpoint whose transcript contains a tool result, in a `Chat` embedded without a prompt-history file.** Restoring a checkpoint revives every non-`assistant` row as a `user` message with its content unchanged, and a tool row's output is full of tabs; with no prompt file wired, `↑` recalls from the transcript's user rows and so returns that revived message verbatim. `bin/sugarcrush` always wires `~/.sugar-crush/prompt_history.jsonl`, whose entries are prompts you typed and sent (trimmed), so there `↑` never produces such a draft. Same for the form feed in the non-blank list above — `Ctrl`+`L` types the letter `l`, not `U+000C`. The draft is left untouched behind the overlay. `Esc`/`Enter`/`q` close it, and so does a second `?` (see the next row); `↑`/`↓`, `PgUp`/`PgDn` and the wheel scroll it (and the transcript behind it is left alone) |
| `?` `?` | Type a literal `?`. The second `?` closes the reference **and** puts the character in the input box, which is how a message that starts with `?` gets typed — the box has no cursor movement, so `?` on a blank line would otherwise make one impossible. Works after leading whitespace too: `␣??` leaves `␣?` |
| `/keys` | The same reference, by **name**: typing `/k` surfaces it in the `/` popup, which is where you find it if you do not already know about `?`. (`/help` was a second spelling of this and is now the **slash-command list** instead.) It is *not* an escape hatch for a half-typed draft — the command is matched against the whole trimmed input, so with `why` already in the box, `why/keys` + `Enter` is sent to the model as a prompt. Typing `/keys` onto a draft opens the reference exactly when `?` on that draft would — which is the sense in which it is not a hatch. It is *not* interchangeable with `?` more generally: a draft that **is** the command modulo surrounding whitespace (`␣/keys`, `/keys␣`) opens the reference on `Enter`, where `?` would type a character, and on a blank line `?` opens it while `Enter` sends nothing. Submitting `/keys` also clears the input line and `?` does not. Clear the line and either route works |
| `Enter` | Send |
| `Enter` (mid-turn) | **Steer** the running turn: the agent reads the message at its next step, and the step's tool calls that have not started yet are skipped (`Skipped to process an incoming message.`) so it reads it before doing more. If the turn ends before its next step, the message is sent as the next prompt instead — never lost |
| `Tab` (mid-turn, draft in the box) | **Queue** the draft for after the running turn instead of steering it in; it is sent when the turn ends, like any queued message |
| `Enter` (docked pane focused, empty draft) | Open the command palette — the door from a read-only pane to the commands that change things; on the **Settings** pane it opens the settings view instead (see `Ctrl+,`). A non-empty draft sends exactly as before, from any pane |
| `Ctrl+,` | Focus the Settings pane; press it again there (or `Enter` on an empty draft, or type `/settings` — alias `/config` — or pick **View settings** from `Ctrl+P` or the `F10` App menu) to open the **settings view**: every setting, by category, with the value this launch runs with, where it came from (default, a project file, your `settings.json` or `config.json`, the environment, a flag), whether an environment variable or flag locks it, and when a change would apply (live, next turn, restart, next launch). `/` searches every category (`/settings compaction` opens with the search filled in), `←`/`→` switch category, `↑`/`↓` move, and `Esc` clears the search, then closes the view. The last tab, **Files**, lists the settings files and whether this launch reads them — including a project's own `.sugar-crush/config.json`, which is *not* a settings layer. It also edits: `Enter` opens the highlighted setting in a field (`Enter` stages the value, `Esc` drops it), `r` stages a reset to the default, `t` switches the file a save writes (**You**, your `config.json`, or **This project (local)**), `s` shows the save preview — the target file, a diff of it and when each change applies — and `y` (or `Enter`) writes it, `n` (or `Esc`) goes back with everything still staged. `Enter` on one of the `trustedProject*` lists asks whether to trust this project for it and `y` adds the project to that list in your `config.json` (from the next launch). `Esc` with changes staged asks first: `d` discards them, `k` keeps editing, `s` previews the save. Nothing is written without that preview, so the view opens mid-turn too (except as a typed `/settings`, refused mid-turn like every slash command). Many terminals cannot send `Ctrl+,`; the slash command, the palette row and the menu row always work |
| `Esc` | On an engine turn that reports its steps: stop the running tool, then the turn after the current step. The running call is cancelled, not waited for: a parallel member's process is killed, and a lone `Task` stops at its sub-agent's next tool or step and stays resumable. A sequential tool such as `Bash` still runs to its end. The cancelled call reads `Cancelled by the user (Esc) while it was running.` and the turn's other results are kept. The status bar then reads `stopping after step N · Esc to cancel now`, and any later `Esc` cancels hard |
| `Esc` `Esc` | Cancel the in-flight turn — press **twice** within 0.6s. On a turn that reports no steps a single `Esc` only arms the cancel, which is why the status bar reads `Esc Esc to cancel` while thinking |
| `Esc` | Close the palette or the session picker (a filter typed into the picker is cleared first) |
| `Ctrl+C` | Quit — unless the draft has a selection: then the first press copies it (OSC 52, clipped to 64 KiB with a notice) and the next press quits |
| `Ctrl+P` | Command palette (fuzzy, grouped by category, biased by most-recently-used) |
| `Ctrl+O` | Expand/collapse the most recent tool call's output and thought |
| `Ctrl+V` | Attach the **image** on the system clipboard (a screenshot): it is read through `pngpaste` (macOS), `wl-paste` (Wayland) or `xclip` (X11), saved to a private temp directory and inserted into the draft as an `@` mention, so it is attached when you send. Text pastes with your terminal's own paste key as before. No image (or no tool) leaves a notice and the draft untouched. See [Attachments](#attachments) |
| `Ctrl+R` | Session picker (persisted across turns; `/sessions <query>` opens it already filtered). Rows are grouped Pinned / Today / Yesterday / by date and show the title, when it was last used, its turns, provider/model and a badge (`⠋ live`, `⧗ bg`, `+N ag` sub-agents, `⑂` branch); the footer names where it was opened, its branch and the last prompt. With the picker up the wheel browses, a click selects, and `Enter` resumes; browsing onto the last loaded row fetches the next page. `/` filters (fuzzy, over title, last prompt, agent, id and branch — `k`/`j` keep moving outside it), `Space` previews the last messages, `r` renames in place, `d` deletes after a second `d` (sub-agent sessions go with it, branches are kept unless you confirm with `D`), `p` pins, `f` forks and switches to the copy, `x` archives, `a` shows archived sessions and `u` brings one back, `Tab` shows sub-agent sessions under their parent, and `Ctrl+B` keeps the current git branch's sessions. Inside the filter `Ctrl+E` / `Ctrl+D` / `Ctrl+F` rename, delete and pin. The highlighted row also carries a clickable `✎ ★ ✕` (rename, pin, delete). The session on screen cannot be deleted or archived |
| `Ctrl+A` | Same dispatch as typing `/agents` |
| `Alt+↓` | Focus the **live agents strip**, the one row above the input box that lists the delegated runs that are live, plus finished ones for 30 s: `agents: ⠋ explore · ✗ reviewer   (alt+↓)`. Then `←`/`→` (or `↑`/`↓`) move, `Enter` opens the run on the agent dashboard with its peek up, `c` stops it (only that run's `Task` call; the turn goes on), `x` dismisses a finished run or stops a running one, and `Esc` or `Alt+↑` gives the keyboard back. Any other key gives it back too, and lands in the input box. A click on a run on the strip opens it the same way. While the strip shows, it replaces the agent list below the input |
| `Ctrl+W` / `Alt+Backspace` | Delete the previous word |
| `Up` (empty input) | Recall the last prompt you sent — press again to walk further back, into earlier sessions too (a fresh launch's first `Up` is the previous session's last prompt). History lives in `~/.sugar-crush/prompt_history.jsonl`; editing a recalled prompt ends the walk |
| `Down` (while recalling) | Step forward through recalled prompts; past the newest one, the draft you were typing comes back |
| `Right` (empty input) | Take the grayed suggestion — after each turn the empty box shows a guess at your next message (`SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS=1` turns it off) |
| `Page Up` / `Page Down` | Scroll the transcript a screenful |
| `Tab` | Cycle focus over the **docked** panes — left column first top-to-bottom, then the right column, chat always first. While chat itself holds focus with the `/` popup open, `Tab` completes the highlighted command instead, and with the cursor at the end of an `@path` mention it completes the path the way a shell does (a unique file gets a trailing space, a directory its `/`, several matches their common prefix; no match leaves the draft as it is): completion answers to chat's focus, so a `Tab` from a docked pane cycles even with the popup open |
| `Shift+Tab` | Cycle pane focus backwards (same docked list) |
| `Ctrl+Tab` / `Ctrl+Shift+Tab` | Cycle sessions |
| `F10` | Open the menu bar |
| `y` / `n` / `a` / `r` / `x` | Answer a permission prompt: once / refuse / ask to allow for the session / refuse **with a note** — type why (it goes into the draft box and the modal shows it), `Enter` sends the refusal with your words for the agent to read, `Esc` goes back to the question / refuse **and stop** the turn after the current step. **While a prompt is up it owns the keyboard** — nothing reaches the input box — but it only answers to a letter while it is *armed*, and the first key you press that is not an answer disarms it. So typing a slash command at a live prompt now does nothing at all: measured, `/keys`, `/init`, `/agents`, `/branch main`, `/compact` and `/new` are all swallowed whole. `Enter` re-arms (and answers nothing), `Esc` refuses in any state, and the modal says which state it is in. The one thing that still answers on the first keystroke is a message that *begins* with `y` or `n` — those are the answers. And `a` no longer grants on its own: it asks whether to allow calls like this one for the rest of the session — a pattern for calls like this one, not every later call to the tool — which one `y` confirms and any other key cancels, so the session-wide grant costs two deliberate keystrokes |

`Ctrl+P`, `Ctrl+O`, `Ctrl+V`, `Ctrl+A`, `Ctrl+W` and `Ctrl+C` always belong to the chat
content model — the shell never claims them, in any pane, so hosting chat
inside the shell cannot silently steal a binding. `Ctrl+R` belongs to the chat
too, with one declared exception: while a shell view is itself driving the
keyboard (the agent dashboard, an open skill picker, an open `F10` menu) the
shell keeps it, because the picker it opens is painted by the chat those views
cover and moved by the `↑`/`↓`/`Enter` those views claim. Leave the view and
`Ctrl+R` works as usual.

The table above is a summary of the chat pane; **the reference `?` opens is the
authority**, and it covers the overlays too (palette, session picker, permission
prompt, agent view, agents strip, skill picker, menu bar, mouse). It is generated from
`Commands\KeyBindingRegistry`, which is also what `Tui\KeyboardHandler` reads its
claimed-chord sets from — so the screen cannot describe a keyboard the app does
not have. `tests/Commands/KeyBindingDriftTest.php` presses every row it lists
(and every "or …" alternate a row's description promises) through the real
handlers, so a binding that stops working fails the suite instead of quietly
staying in the docs. A chord that some handler claims but nothing acts on yet is
marked dormant in the registry and deliberately left OUT of the reference —
still claimed, so it cannot regress into typing its own letter into the input
box, but not advertised either.

### Mouse

Mouse mode is on by default (`SUGARCRUSH_DISABLE_MOUSE=1` turns it off). Zones
are registered during the render pass, so clicks land on what you see: wheel
scrolls the transcript, clicking a tool call or a `💭 Thought` row
expands/collapses it, clicking a session tab switches sessions, clicking a docked pane's header focuses that
pane, clicking a pane tab on the menu bar toggles its docking (see below),
clicking a palette/picker row selects it, clicking the menu bar opens a
menu, and in the settings view a click on a category tab switches to it, a
click on a row highlights it and the wheel moves the highlight. Click-vs-drag is
discriminated so a text-selection drag does not fire the zone underneath it.

Dragging across the transcript selects text: the covered rows highlight as
you drag, and releasing copies the selection — OSC 52 to the terminal, plus
the host clipboard tool (`tmux load-buffer -w` inside tmux, where an app's own
OSC 52 is ignored by default; otherwise `pbcopy`, `wl-copy`, `xclip` or
`xsel`). The status bar confirms `✓ copied N chars`, and the highlight stays up
until the next click, key, wheel notch or resize (Ctrl+C over it just dismisses
it). Only the transcript's text column is selectable, so borders and padding
never land on the clipboard.

### Pane docking

Five panes dock — **Files** and **Tools** to the left, **Skills**, **Agents**
and **Settings** to the right — and the chat owns whatever is left over. The
menu bar's right end carries a tab for chat plus each dockable pane, and the
tab tells you the whole state at a glance: muted when the pane is undocked,
full foreground when it is docked, bold-underlined when it also holds focus.
Clicking a tab toggles docking — docking lands the pane on its home side and
focuses it; undocking the focused pane hands focus back to chat. Clicking the
**Chat** tab never hides anything (the center pane is always up); it just
returns focus. `/pane dock left|right` and `/pane toggle [name]` drive the
same state from the keyboard. Dragging either header of a docked pane — the
frame's title row, or the menu-bar tab standing for it — carries the pane to
the side under the release: the side band itself, or the outer third of the
chat facing it (so an empty side, which paints no band, is still a target);
dropping in the chat's middle third cancels, and the release row picks the
slot within the destination stack. While the drag is live, a heavy `┏━┓`
outline marks the region the release would dock into (labelled with the pane
and side), or a "release here to cancel" hint shows over the middle third. A pane may dock on either side, not only
its home side. To resize a side, press on the border between it and the chat —
the side box's edge, the divider, or the chat's own edge, anywhere down the
full height — and drag; no modifier key is needed (Esc mid-drag cancels).

Focus decides who answers `Tab`, `Shift+Tab` and `Enter`; typing a printable
character always reaches the chat draft regardless of focus, as do `Ctrl+O`
(expand/collapse the newest tool output and thought) and the other always-chat chords.
`Tab`/`Shift+Tab` walk the docked frame — chat, then the left column
top-to-bottom, then the right — and wrap; `Esc` from any docked pane falls
back to chat; `Enter` on an empty draft from a docked pane opens the command
palette (see the keys table). What a focused pane then does with the keys the
shell leaves alone varies, and is by design, because L2 adopted the panes that
existed rather than building new bodies: **Chat** is the full editor, the `/`
popup completing as you type; **Agents** is a real dashboard (`c`/`r`/`s`/`q`,
`Alt+1…9`, enter to peek or attach); **Skills** drives its picker (arrows and
enter) whenever the picker is open — `Ctrl+S` opens it; **Files** and **Tools**
are read-only listings — their focus buys you the divider-resized view, the
`Ctrl+O` peek, and the `Enter` palette door; **Settings** is a read-out panel
summarising the live configuration — its footer names its door, and `Enter`
from that pane (or `/settings`) opens the full settings view, every key with its
value, source and apply mode; theme and model still change through `/theme`,
`/model` and the palette.

### Attachments

Mention a file with `@` and it is attached to that prompt: `explain @src/Chat.php`
or, for a path with spaces, `@"design notes.md"`. A mention is `@` at the start of
the draft or after whitespace (so `me@example.com` stays text); relative paths
resolve against the project root, `~/` against your home, and an absolute path is
taken as written. `Tab` completes the path under the cursor.

- **Files** are read once, when you press `Enter`, and that snapshot rides on the
  message — so a later edit does not change what an earlier turn showed the model,
  and the request's cached prefix stays stable. The model receives the text inline
  as a `<file path="…">` block after your prompt, on every provider. Text over
  256 KiB is truncated (and the transcript says so); a binary file that is not an
  image is refused with a notice.
- **Images** (PNG, JPEG, GIF, WebP, by their magic bytes, up to 5 MiB) are sent as
  the provider's own image part — `image_url` on OpenAI, SGLang, Custom and the
  `anthropic` type; `image` blocks on Claude-on-Vertex and Bedrock; `inlineData` on
  Gemini. Attach one with `@shot.png`, by dropping the file onto the terminal (a
  paste that is nothing but an image's path becomes a mention), or with `Ctrl+V`,
  which reads the clipboard's image.
- **A model without vision never drops an image silently.** Whether a provider may
  be sent one is its `supportsVision()`: OpenAI's vision families (`gpt-4o`,
  `gpt-4.1`, `gpt-4-turbo`, `gpt-4.5`, `gpt-5`, `o1`, `o3`, `o4-mini` — not
  `gpt-4`, `gpt-3.5-turbo` or `o1-mini`/`o3-mini`), `anthropic`, Claude and Gemini on
  Vertex, and Claude 3+/Nova Lite/Pro/Premier on Bedrock answer yes; SGLang asks
  the server (`/model_info`'s `has_image_understanding`); `custom`, `claude-code`,
  an OpenAI model outside that list and the offline echo provider answer no. For a
  no, the image goes to the model as a one-line text placeholder naming it, and a
  notice after the reply tells you it was not seen. The `openai`, `sglang` and
  `custom` provider blocks accept `"supportsVision": true|false` to override that
  answer.
- Under each prompt the transcript shows a `📎` row naming what it attached; a
  mention that looks like a path but matches nothing, a directory, or the
  21st file of one prompt gets a notice instead of an attachment.
- `@` forms inside a [file-based command](#your-own-slash-commands) are that
  command's own include syntax, resolved (or refused) by its trust tier — they are
  never read as attachments.
- **Keyword mentions** attach context that is not a file, as a
  `<context source="…">` block (at most five per prompt, each capped at 256 KiB):
  - `@diff` — your staged and unstaged changes to tracked files against `HEAD`
    (untracked files are not in it); `@diff:<ref>` against a branch, tag or commit.
    Read with plumbing `git diff-index`, so it never rewrites your index, bounded
    at 5 s.
  - `@session:<id>` — another session's conversation, by id, name or a unique id
    prefix (the `--resume` rule); a long one keeps its most recent 256 KiB.
  - `@https://…` / `@http://…` — a web page, fetched through `WebFetch`'s guards
    (loopback, private, link-local and metadata addresses are refused before any
    connection, redirects and status codes are checked) and refused outright when a
    `Deny WebFetch(domain:…)` rule names its host; the model is told the page is
    untrusted data, and fence tags inside it are escaped.

  The keywords are reserved **before** any path lookup: `@diff` is the diff even
  when the project has a file named `diff`, which is still `@./diff` or `@"diff"`.
  Like files, they are read once, on `Enter`, and survive a resume.

### Shell commands (`!`)

Start a line with `!` to run it as a shell command yourself: `!git status`,
`!vendor/bin/phpunit tests/Foo.php`. It runs the way the agent's `Bash` tool
runs, as `bash -c` in the project root, in the background so the TUI stays live.
It is bounded at 600 s and at the tool's output cap. When it finishes, its exit
code and output land in the transcript as context the model reads on your next
prompt. It starts no turn and costs nothing until then. Escape sequences are
stripped from the output.

Nothing asks permission, because you typed it. Policy you configured still
applies: a `Deny` rule that matches the command refuses it in every mode, plan
mode runs only a command it can prove read-only, and a read-only window refuses
it like any prompt. Typed mid-turn, it waits in the queue and runs when the turn
finishes. A lone `!` is an ordinary prompt.

### Slash commands

<!-- commands:roster:begin -->
`/agents` (`/agent`) `/bg` (`/background`) `/branch` `/budget` `/clear`
`/compact` `/context` (`/tokens`) `/diff` `/editor` `/exit` (`/quit`) `/fork`
`/help` `/init` `/keys` `/layout` `/mcp` `/memory` `/model` `/notices` `/pane`
`/permissions` `/redo` `/rename` `/rewind` `/rules` `/sessions`
`/settings` (`/config`) `/share` `/theme` `/undo` `/websearch` `/workflow`.
<!-- commands:roster:end -->

The parenthesised spellings are aliases: they dispatch, but they have no
`CommandRegistry` row of their own, so no surface advertises them. The roster is
generated from the one-file-per-command specs under `builtin-commands/` by
`php tools/gen-command-docs.php --write`.

`/permissions` answers, in the transcript, what this session is actually gated
by: the mode, the source it came from (`--permission-mode`, the env var, or the
file — named), the rules in the order they are tried, and where the Auto-mode
circuit breaker stands. Every line is read off the launch's live
`PermissionGate` rather than re-derived from config, because a permission
screen that disagrees with the gate is worse than no screen. It is READ-ONLY in
the strong sense — `PermissionGate::evaluate()` moves the Auto strike counters,
so opening this must not, and does not, go anywhere near it. To CHANGE the
mode, relaunch with `--permission-mode`, set `$SUGARCRUSH_PERMISSION_MODE`, or
edit `permissionMode` in `~/.sugar-crush/config.json` — or in
`~/.sugar-crush/settings.json`, which is read for `permissionMode` and
`permissionRules` too and which `config.json` outranks where both set a key.
Naming only one of the two files is how this paragraph, and the report itself,
read for one round: rules written in the file that was not named still load,
so a reader who followed the sentence and saw no change had been sent to edit
the wrong file. Every spelling is answered locally — `/permissions rules` and
`/permissions --help` get the same screen, because the report has no sub-views
and there is nothing for an argument to select. Unlike `/keys`, it is never
handed to the model: a question about the local gate answered by the one
participant that cannot see it comes back fluent and wrong.

`/notices` is the launch's warning record with the caps taken off. The
transcript seeds launch warnings as clipped rows on a 24-slot shelf, and the
narrowed agent-tool-grant sentences arrive pair-packed into at most two
aggregate rows — enough to say "something was configured quietly", not enough
to read. `/notices` restates the whole shelf, the sentences the shelf overflowed
past its cap, and the full grant list from the live collector, one numbered
line per fact. Nothing new is stored: it reads the same sources stderr printed,
which is the point — the panel exists so truncation ends somewhere inside the
app rather than only in a scrollback. It takes no argument; the record is
already total.

`/context` (or `/tokens`) answers "what is filling my context window?". The
status bar's `~81K / 131K` is the history alone; every request also carries the
system prompt and the tool schemas. `/context` prints the next request's
estimated size split into the system prompt **per layer** (base, maxims, repo
map, rules, project instructions, memory, skills, `<env>` — each with its
stability), the tool schemas, the history (and how many UI-only rows were never
sent), the five largest messages, and the share of each prompt the provider
served from its cache — for the last reply and across the session. Token
figures are script-weighted estimates (`~`); the cache figures are the
provider's own. A part a backend cannot report — a command backend assembles no
prompt of its own — prints as "not measured", never as zero. It is read-only,
local and calls no model.

Typing `/` opens a live popup of the matches, which fuzzy-ranks as you type
(`/rwd` finds `/rewind`), **highlights the characters you typed** and shows each
command's **argument hint** (`/rename [<name>|--auto]`) — fitting the whole row to the
terminal rather than letting it run off the edge: the description gives up
columns first, then the hint, and the name (the row's identity) last.

`/help` lists every command the registry advertises, with its argument hint —
that is the list above without the five aliases: `/agents`, `/bg`, `/context`,
`/exit` and `/settings` appear, `/agent`, `/background`, `/tokens`, `/quit` and
`/config` do not. `tests/Commands/SlashDispatchTest.php` fails if a sixth unadvertised alias
turns up without a reason written next to it. `/model` on its own opens the
same provider picker `Ctrl+P` → **Switch model** opens; `/model <provider>`
switches straight to one, and an unknown name says so in the transcript instead
of failing silently. `/clear` empties the transcript and **keeps** the session —
its id, its name on disk and its checkpoints all survive, so `/rewind` still
reaches the turns it cleared. That is the opposite trade to **New session**,
which mints a fresh id and leaves the conversation where it was.

**New session**, **Pin or unpin session**, **Delete session…** and **Open docs**
are palette-only actions (`Ctrl+P`) — they
have no slash spelling, so `CommandRegistry` keeps them out of the `/` popup and
`tests/Commands/SlashDispatchTest.php` fails if a row gains a popup entry
without gaining a dispatch handler.

**File-based custom commands** (`.sugar-crush/commands/*.md`) are loaded and
dispatched: `bin/sugarcrush` builds a `Commands\CommandLoader` per launch, and a
`*.md` under either commands directory is listed in the "/" popup and in `/help`
and runs when you type it. See [Your own slash commands](#your-own-slash-commands)
for the template syntax and for what a command file is and is not allowed to do.

`/bg` really does run the work: it dispatches onto a `BackgroundSupervisor`
that `bin/sugarcrush` constructs per launch, and the result comes back into
the transcript. When the session settles — completed, failed or timed out —
its answer arrives as a user-role message the model reads: a
`[Background session <id> ('<name>') completed]` header, the task, the output
(up to 16,000 characters, the clip announced), and a stats line with the
runtime, the tokens and cost once the daemon reports them, and for a `/fork`
session the `sugarcrush --resume <id>` that reopens its transcript. If no turn
is running it is sent at once as the next turn; otherwise it waits in the queue
and goes out when the running turn finishes, exactly like a prompt typed
mid-turn, and the half-typed draft in the box is kept either way. Sessions that
settle together share one turn, and a session you ended with `/bg stop` starts
none. The daemon outlives the TUI: quit and relaunch in the same project, and
the new launch re-adopts it from a per-uid session index and reports its result
the same way, even one that finished while sugarcrush was closed. `/fork`
branches the current session.

`/budget` reports what this launch has spent, as the **provider** counted it,
and optionally caps it. `/budget 5` sets a $5 ceiling, `/budget off` clears one,
and `/budget` on its own prints the running total plus the token breakdown that
does not fit on the status bar. `$SUGARCRUSH_MAX_COST` sets the same ceiling at
launch, and refuses to start at all if what you set is not a ceiling (`5USD`,
`0`, `1e309`) rather than running uncapped without saying so. Four things about
it are worth knowing before you rely on it:

- It refuses the **next** turn once the reported spend has reached the cap; it
  does not abort a turn in flight (the work happens in a forked child, whose
  figures only reach the parent when the turn settles). So the final total
  overshoots by at most the one turn that crossed the line, and the refusal
  message says so.
- It only ever refuses on numbers a provider actually reported. A streamed turn
  commonly reports **no** usage at all, and a self-hosted provider genuinely
  costs nothing — so a session nothing has been reported for is never refused.
  That is a budget guard, not a spending control.
- It governs every provider call this app makes on your key, not only the turns
  you type. `/compact`'s model-written summaries are the other one — a capped
  session still compacts, on the local heuristic, and the transcript says the cap
  is why. The session titler needs no separate gate: it only ever rides along
  with a turn the cap already let through.
- The cap lives for the launch. It is deliberately not persisted, so it cannot
  silently refuse turns in a later session whose spend you never looked at.

### Your own slash commands

Drop a markdown file in `~/.sugar-crush/commands/` (yours) or
`<project>/.sugar-crush/commands/` (the checkout's) and its name becomes a slash
command: `review.md` gives `/review`, `deploy/staging.md` gives
`/deploy/staging`. Optional YAML frontmatter (`description`, `argument-hint`,
`model`, `subtask`) sets what the "/" popup and `/help` show; everything after it
is the prompt that gets sent.

```markdown
---
description: Review a diff and be blunt
argument-hint: <path>
---
Review $1 for correctness bugs. Focus on: $ARGUMENTS
```

- `$ARGUMENTS` is everything typed after the command name, verbatim apart from
  the surrounding whitespace: interior quotes and doubled spaces are kept,
  leading and trailing whitespace is trimmed.
- `$1` … `$9` are the same text split on whitespace, with shell quotes honoured
  and stripped — `/deploy "us east" prod` puts `us east` in `$1`.
- A placeholder with no argument becomes the empty string; it is not left in the
  prompt for the model to puzzle over.
- `$$` is a literal `$`. A `$` that is not a placeholder (`$PATH`, `$(date)`) is
  left alone, so shell snippets inside a template survive.
- Substitution is one pass over the whole body, so text that came *from* an
  argument is never re-expanded.
- A project file overrides a built-in of the same name, in both the popup and
  dispatch — and in both spellings, `/compact` and `/compact:arg`.
- **Except the control plane.** `budget`, `clear`, `exit`, `help`, `model`,
  `permissions` and `quit` are reserved: a file with one of those names is
  ignored, the built-in keeps running, and the refusal is printed at launch with
  the path of the file. These are how you drive and leave the app, so a clone
  cannot redefine them.
- A command whose template expands to nothing — a body of just `$ARGUMENTS`,
  invoked with no arguments — is refused with a note rather than sent as an empty
  prompt.

The project directory is resolved and checked against the checkout before
anything under it is read: a committed `.sugar-crush/commands` symlink pointing
outside is refused, with the reason printed at launch, rather than turning an
outside file's contents into a prompt. Commands are discovered once, at launch —
add a file, restart.

#### Running a command and including a file

Two template forms leave the string. Both are gated, and they are gated
differently, because they are not the same risk.

``!`cmd` `` runs `cmd` and substitutes what it printed:

```markdown
Current branch: !`git rev-parse --abbrev-ref HEAD`
```

- **A command file in your home** (`~/.sugar-crush/commands/`) is yours, as much
  as `~/.bashrc` is, and its ``!`cmd` `` runs.
- **A command file in the checkout** (`<project>/.sugar-crush/commands/`) arrived
  with the repository. Its ``!`cmd` `` does **not** run unless you have listed
  that project under `trustedProjectCommands` in `~/.sugar-crush/config.json` —
  the same shape of opt-in as `trustedProjectHooks` and `trustedProjectMcp`, and
  a separate key so trusting one thing does not trust the others. Untrusted, the
  form is replaced by a note saying so and the rest of the template is still
  sent. Clone a hostile repo, type its innocuous-looking `/review`, and nothing
  runs.
- Your permission rules apply on top of the above: an explicit `Deny Bash` or
  `Deny Bash(rm *)` refuses the substitution. A mode that would *ask* proceeds
  instead — there is no prompt to show mid-expansion, and the file was already
  authorised by the two rules above.
- Arguments can never become part of a command. Substitution is a single pass, so
  a ``!`…` `` you *type* as an argument is prose, and a `$ARGUMENTS` written
  *inside* ``!`…` `` is not substituted.
- All the ``!`…` `` forms in one command share **10 seconds** of wall clock
  between them, not 10 seconds each; whatever is left is what the next one gets,
  and a form that arrives with nothing left says so. The app is single-threaded,
  so that budget is how long the terminal can freeze. It is not configurable, and
  deliberately not settable from frontmatter — that file may be the repository's.
- Output is capped at 16 KB per substitution and the clip announces itself.
  stderr joins the prompt only when the command failed, along with its exit code.

`@path` splices in a file:

```markdown
Follow the conventions in @CONVENTIONS.md when you answer.
```

- The path is **relative to the checkout and confined to it**, for command files
  from either directory. `@../../.ssh/id_rsa.pub`, and a symlink under the
  checkout pointing at the same, are refused with a note. An absolute
  `@/etc/passwd` is not an include at all — it stays literal and nothing is read.
- It must end in an extension, and the extension has to be on the LAST segment,
  so an `@name` mention, an email address, and an extensionless
  `@../../.ssh/id_rsa` are all left alone — the last of those is not refused with
  a note, it is simply never treated as an include.
- 16 KB per substitution, same cap and same announced clip.
- Unlike ``!`cmd` ``, an include needs no trust opt-in: it is a bounded read
  inside a checkout you already opened. If you want a file from *outside* the
  checkout, say ``!`cat ~/notes.md` `` and let the permission gate see it.

### What you see while a turn runs

Tool calls stream into the transcript **as they happen** — the forked child
emits lifecycle events rather than buffering until the turn ends — each with a
human-readable description and the command it actually ran, then a
running→done transition. `Edit`/`Write` results render a real unified diff. A
no-op edit reports as a no-op instead of success. Denied and interrupted calls
get their own visual state. Tool results that carry images are labelled and
rendered inline via candy-mosaic. Successful tool bodies are hidden by default
(`Ctrl+O` or a click opens them); an expanded call shows the invocation above
its output — `$ <command>` for shell calls, `key: value` for other tools. The
model's thinking streams in full while it thinks, then folds into a collapsed
`💭 Thought` row once the reply (or a tool call) starts; click the row to open
or close it (`Ctrl+O` toggles the newest one). Context usage shows as both a token count and
a percentage, and the budget it is measured against is the **live model's own
context window** as its provider reports it (a backend with no model behind it,
such as the offline echo default, falls back to 100,000 estimated tokens). That
budget also drives compaction, per turn and without an idle gate: at 70% a
system-role reminder rides along with the turn, at 85% older exchanges are
summarized first and the rewrite is reported in the transcript, and at 95% the
turn is refused rather than spent on a request the provider would reject. Each
tier can also carry an **absolute** token cap beside its percentage, firing at
whichever is lower, so a 1M-token window need not reach 700,000 tokens before
the first reminder: `CompactorConfig::withReminderTokens()` and its two
siblings set them, `withModelTokenOverride()` sets them per model (`model` or
`provider/model`), and `CompactorConfig::smartZone()` is DCP's 50,000 / 100,000
pair. The caps are unset by default and no settings key reaches them yet. A
refusal is not a dead end — each attempt drops the oldest preserved exchange,
and `/clear` frees the whole context at once. Those tiers judge at submit; inside
a turn the engine also measures every step's request before sending it — system
prompt and tool schemas included, anchored on the provider's own count for the
step before — against a step budget of the smaller of 80% of the window and the
window less the output ceiling and a reserve. A request over that budget is
pruned before it goes out: older tool output — outside the last two prompts and
the newest 40k tokens of it, and never a `Task` or `Skill` result — is sent as
a one-line placeholder naming the call (`[Read src/Foo.php — output pruned to save
context; re-run the tool if you need it]`), along with superseded copies of the
harness's own state row, but only when that frees at least 20k tokens. If the
request is still over, the agent's own model summarises what it has already been
sent — same system prompt and tools, so the request reuses the provider's cache,
plus an instruction not to call tools (`SUGARCRUSH_SUMMARY_MODEL` picks another
model) — and the summary stands in for those rows while the step in progress
goes out whole. Your transcript keeps every output and every row. A request the
provider still refuses as too long for its window gets the same relief at full
strength — every older tool output becomes its placeholder, then the summary —
and is sent once more before the turn fails. A reply the provider cuts off at
its output limit without calling a tool is continued where it stopped, up to
three times, and reads as one answer: the cut reply goes back as the start of
the model's own message where the provider allows that (`sglang`, and Claude on
`vertex` or `bedrock`), and with a request to continue elsewhere. Only a reply
still cut off after that carries the notice that it stopped at the output
limit. A stream that drops after the reply has started showing is continued the
same way ("Continue where you left off") rather than restarted, so what you have
already read stays and the rest is appended; one that drops before anything
showed is simply retried. While the turn runs, the status
bar names the step it is on, adds that step's context figure once the request
is over budget, and moves the spend readout as each step is billed. On such a
turn the first `Esc` stops it after the current step's tools finish, with its
reply kept, and a further `Esc` cancels it at once. A message you send while it
runs **steers** it: the agent reads it at its next step (calls of the current
step that have not started are skipped so it does), while `Tab` queues the draft
for after the turn instead.

A `Task` call gets a **live line** under its row for the sub-agent it runs —
`└ ⠋ Grep "LoginController" routes/ · 7 tools · 0:12 · 4.1K tok` — naming
the call the agent is in right now (or its last finished call with `✓`/`✗`,
`thinking…`, or the newest fragment of what it is writing), its tool count,
elapsed time, tokens and spend. Parallel `Task` calls each get their own line.
The spinner turns while the agent works; when it ends the glyph becomes `✓`
(done), `✗` (failed, with the reason), `⏹` (cancelled) or `⏸` (stopped without
a report), and the line stays under the settled row. A `Task` call still
waiting for a free delegation slot says `◌ queued:` on its row instead of
`running:`. The line never wraps: on a narrow terminal the spend goes first,
then the tokens, then the tool count, and the item is shortened in the middle.
Without `ext-pcntl` the turn runs in-process, so the lines appear filled in
when it ends rather than live (see TROUBLESHOOTING).

Beside the context readout, a **spend** readout appears once the provider has
reported something to show — dollars, and the cap if one is set. It is a
separate segment from the context figure on purpose: the context number is a
script-weighted **estimate** (about four characters a token for English and
code, about one for CJK, more for emoji) and wears a `~`, while the spend is
the provider's own **count** and wears a `$`. They are never summed. A session
under a cap that nothing has been reported for reads `$?` rather than `$0.0000`,
because the two are different claims and the cap is inert in that state.

When you type `/compact` and a provider is configured, the older exchanges are
summarized **by a model** rather than by the local truncate-and-placeholder
heuristic — on the same kind of tool-less backend the session titler uses, so a
compaction can never call a tool or raise a permission prompt. Tool-less is where
the resemblance ends: the titler runs on a deliberately cheap small model, while
the summarizer defaults to the provider's own default model, because a bad
compaction summary is permanent context loss. It is the largest single call this
app makes, which is why the spend cap gates it. The request
goes out off the render loop, so nothing freezes: `/compact` answers immediately
that it is summarizing and the transcript compacts when the summaries arrive. If
the call fails, or the model answers with something unusable, the compaction
still happens on the heuristic and the transcript says which one did it. The
automatic 85% tier is the heuristic's, not the model's — it fires inline as a
turn is dispatched, where waiting on a completion would stall the keystroke that
triggered it.

Sessions get a name automatically: after the first exchange a **cheap
small-model backend** (supplied separately from the conversation backend, so
naming never costs a second tool-capable agent turn) generates a title, which
is what `/sessions`, the tab strip and `Ctrl+R` list.

## Providers

`SugarCraft\Crush\Providers\ProviderInterface` is the single LLM abstraction (capability introspection, batch + `\Generator` streaming, function calling, embeddings, per-model cost). Build one directly or from config via `ProviderFactory` (which resolves `${VAR}` / `${VAR:-default}` from the environment):

```php
use SugarCraft\Crush\Providers\ProviderFactory;

$factory  = new ProviderFactory();
$provider = $factory->create(['type' => 'openai', 'apiKey' => '${OPENAI_API_KEY}', 'model' => 'gpt-4o']);
```

| Provider        | Type key      | Notes                                                            |
|-----------------|---------------|------------------------------------------------------------------|
| OpenAI          | `openai`      | `openai-php/client`; function calling, embeddings, cost table    |
| Anthropic       | `anthropic`   | `x-api-key` + `anthropic-version` auth, OpenAI-shaped `/v1/chat/completions` body with tool calling — see below |
| Claude Code CLI | `claude-code` | drives the `claude` binary headless; native cost; JSON schema    |
| SGLang          | `sglang`      | OpenAI-compatible self-hosted endpoints (Guzzle)                 |
| AWS Bedrock     | `bedrock`     | Converse API via `aws/aws-sdk-php`; per-model pricing            |
| GCP Vertex      | `vertex`      | Anthropic-on-Vertex via an injectable predictor seam             |
| Custom          | `custom`      | any OpenAI-compatible HTTP endpoint                              |
| Echo            | —             | `EchoProvider`: offline, echoes the last turn; default + tests   |

The `anthropic` type key is **not** a native Messages API client. `ProviderFactory::createAnthropic()` builds a `CustomProvider` with Anthropic's `x-api-key`/`anthropic-version` headers, and that class POSTs an OpenAI-shaped body to Anthropic's OpenAI-compatibility endpoint, `<ANTHROPIC_BASE_URL>/v1/chat/completions`. Tools go out as OpenAI `tools` and come back as `tool_calls`, streamed or not. For an Anthropic-native path, use `claude-code` (which drives the `claude` binary) or point `SUGARCRUSH_BACKEND_CMD` at a shell script.

Context windows and prices for models a provider has no built-in figure for come from a **model database**: LiteLLM's `model_prices_and_context_window.json`, cached at `~/.sugar-crush/cache/` for 24 hours and refreshed in a detached background process, so it never holds the terminal (`ModelMetadata`). The `openai`, `anthropic` and `custom` types consult it, in this order: your own `contextWindow` / `modelPrices` (the provider block's keys, else the user-tier settings), then the database, then the provider's built-in table. That is what sizes a Claude model behind `anthropic` (no longer a fixed 128,000 tokens) and prices it (no longer $0), and what sizes a hosted model behind a `custom` gateway. A `custom` model the database does not list — a self-hosted id — keeps 128,000 tokens and a real $0; give it a `contextWindow` to size it. Matching is exact (case-insensitive, or LiteLLM's own `provider/id` form), never fuzzy, so a self-hosted model is not billed at a hosted model's price because their names overlap. Offline, a failed refresh keeps the old file and waits a day; `SUGARCRUSH_DISABLE_MODEL_METADATA=1` turns the database off entirely — see [`ENVIRONMENT.md`](docs/ENVIRONMENT.md).

The `sglang` type accepts an optional `toolCallParser` key, with three values:

- `'openai'` — read the server's parsed `tool_calls[]` array, and nothing else.
- `'minimax-xml-fallback'` — the same, but when that array is absent, recover
  MiniMax's raw `<minimax:tool_call>` XML out of the message content.
- `'dsml'` — the same, but recover DeepSeek-V4's native DSML markup
  (`<｜DSML｜tool_calls>`) instead.

All three read `tool_calls[]` first, so the two fallbacks cost one `isset` on a
correctly configured server. They matter only if your SGLang deployment was
launched *without* `--tool-call-parser`, which leaves the model's native
tool-call syntax unparsed in the content — and the DeepSeek-V4 model card's own
documented launch command omits that flag, which is why `dsml` exists.

**Omit the key** and the parser is derived from the model: `dsml` for the
DeepSeek-V4 family, `openai` for everything else. That is why
`defaultConfig('sglang')` reports `toolCallParser: null` rather than a name — a
stamped literal would keep applying after you edited `model` to another family.
This differs from `reasoningEffort` in no way; both are model-derived for the
same reason.

The setting applies to **both** the batch `complete()` path and the streaming
path. On the streaming path the fallback runs over the reassembled content once
the response ends, and only when the structured `delta.tool_calls[]` route
produced nothing, so it cannot duplicate a call. A recovered envelope is cut
out of the assistant's text on both paths, so the markup is neither shown nor
sent back to the model next to the structured call. To make that possible on
the streaming path, `minimax-xml-fallback` and `dsml` hold text back from the
screen from the first bytes that could open an envelope: a chunk ending in a
partial marker or a line break waits for the next one, and anything after a
complete marker waits for the end of the response. Text that turns out not to
be a recovered call is then shown unchanged. `openai` holds nothing back.

It also accepts an optional `reasoningEffort` key — SGLang's top-level
`reasoning_effort` request field. One of `none`, `minimal`, `low`, `medium`,
`high`, `xhigh`, `max`, or a float. Omit it and the value is derived from the
model: `max` for the DeepSeek-V4 family, and nothing at all for any other
model.

A misspelled **level name** is refused when the provider is built, so a typo in
a config file fails immediately. A **number** is not: the float range is the
server's (`0.0`–`0.99` inclusive when measured, `le: 0.99`) and is deliberately
not re-checked locally, since a later SGLang may widen it. The catch is that
JSON has no float/int distinction to lean on here — a whole number is read as a
float, so `"reasoningEffort": 1` becomes `1.0`, the one value just outside that
bound, and every request then fails with an HTTP 400 from the server rather than
at startup. Write `0.99`, not `1`.

Configure the **real model id**, not a `--served-model-name` alias. The
DeepSeek-V4 defaults above are selected by matching `deepseek-v4` in the `model`
string, so a server launched as `--served-model-name default` reports `default`
from `/v1/models`, and copying that into `model` silently gets you the legacy
`temperature = 0.7`, no `top_p`, no `reasoning_effort` and a 196,608-token
context window while you are in fact talking to DeepSeek-V4. There is no way to
detect that from the id alone — but the server can say, see below.

The provider also asks the server about itself: once per session it reads
`GET /model_info` and `GET /server_info` from the server root (`baseUrl` minus
`/v1`), with a 3-second bound, and fails soft. From them it takes the context
window (`min(context_length, max_req_input_len − 4096)`, so the compaction
tiers follow the deployment rather than a transcription of it) and the default
`max_tokens` (`min(262144, the room the prompt leaves)`; `maxOutputTokens`
still wins). When the reads fail, per-family figures stand in: 262144 output
tokens for DeepSeek-V4 and Qwen3.8, 4096 for anything else. The same read
names the served model and its `--tool-call-parser`: a served model of another
family than `model`, or a server with no tool-call parser while no textual
fallback is armed, raises a one-time transcript notice. The chat's status bar
ends with the served model's id (the configured `model` until the server has
named one) whenever the row has room left over — it is the bar's
lowest-priority piece, shortened to the part after the last `/` and then
ellipsised before it is dropped. Set
`"discoverServerInfo": false` in the provider block to skip the reads.

That default exists because an *absent* `reasoning_effort` is not neutral. On
DeepSeek-V4-Flash, a request without it comes back with `reasoning_content:
null` and the model's thinking written straight into `content` — so the
reasoning ends up in the reply the user reads instead of the collapsible
thinking pane. Sending a level moves it back to `reasoning_content`, which is
where `CompleteResponse::$reasoning` reads from.

The default `sglang` model is **whatever the server serves**. With no model
named (the generic `sglang` config, no `--model`, no `$SUGARCRUSH_MODEL`), the
provider reads `served_model_name` from `/model_info` and addresses every
request to it, picking that model's sampling, reasoning-effort and tool-call
parser defaults; the served-model notice above does not fire, since there is no
configured model to contradict. Only when the server cannot be asked does it
fall back to `Qwen/Qwen3.8-Flash-Next-FP8`, the model skynet2 serves as of
2026-10-02 (it served `deepseek-ai/DeepSeek-V4-Flash-0731` before that, and the
default still named it — so every default launch asked for a model the server
no longer had). A model you do name is sent as named. Once the served name is
known — read by the TUI process itself, or reported back by the first turn —
the Settings pane's `Model` row shows it instead of the fallback id.

The DeepSeek-V4 family also gets `temperature = 1.0` plus `top_p = 0.95` when
the request offers tools / `1.0` when it does not — the model card's own figures
for agentic and non-agentic use. MiniMax-M2.x is unaffected by all of the above: name it as the
`model` and the provider keeps its previous `temperature = 0.7`, sends no
`top_p`, and sends no `reasoning_effort` unless you set one explicitly. A
per-request override lives on `CompleteRequest::$reasoningEffort`.

## The agent loop

`EngineBackend` bridges the chat-shell `Backend` seam to the engine. Each user turn runs a **bounded agentic loop**: call the provider through the `Runtime`, execute any tool calls through the hook gate, feed the results back, and repeat until the model answers without calling tools — or a `maxSteps` ceiling is hit (default 1000, `maxToolSteps` in `~/.sugar-crush/config.json`; `Task` sub-agents default to 200). A **repeat-call loop guard** watches the same tool called with the same arguments and getting the same result: the 3rd such call is warned, the 5th refused, the 8th ends the turn — the brake the spend cap cannot be on a provider that reports $0. A turn the ceiling or the guard stops gets one final request with tools disabled, asking the model to summarise what is done, what remains and what comes next. A reply that calls no tool and says nothing is not taken as the answer: one that only reasoned gets a single nudge asking for an answer or a tool call, and a fully empty one is re-requested up to twice — each extra call only while a step is left and the spend cap is not reached.

```php
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\{HookManager, HookRegistry};
use SugarCraft\Crush\Tools\BuiltIn\{Bash, Read, Edit, Glob, Grep, WebFetch};

$hooks = new HookManager(new HookRegistry());
$hooks->registerBuiltIns();                       // audit + confirm-rm + protect-files

$backend = (EngineBackend::new($provider, 'gpt-4o'))
    ->withTools([new Bash(), new Read(), new Edit(), new Glob(), new Grep(), new WebFetch()])
    ->withHooks($hooks);

(new Program(new Chat(backend: $backend)))->run();
```

## Capabilities

- **Tools** — `Tools\BuiltIn\*`: <!-- tools:roster:begin -->`Bash`, `Read`, `Edit`, `Glob`, `Grep`, `Write`, `WebFetch`, `WebSearch` (against `$SUGARCRUSH_SEARCH_ENDPOINT`), `doctor` (a capability probe the model can call to report what this build/deployment actually supports), `Skill` (level 2 of the progressive-disclosure design below), `Lsp` (definitions/references/hover/symbols/code-actions/diagnostics from a language server), `Memory` (view, save, edit, delete and recall the persistent memory notes indexed in the system prompt), and `RepoMap` (a ranked outline of the definitions the rest of the project references most, sized to a token budget). These are **runtime tool names**, the same spelling the launch report and every `allowedTools`/`disabledTools`/`permissionRules` pattern uses; five of them differ from their class file, which is why the list is not a directory listing — `doctor` is `Doctor.php`, `Skill` is `SkillTool.php`, `Lsp` is `LspTool.php`, `Memory` is `MemoryTool.php`, `RepoMap` is `RepoMapTool.php`. Thirteen classes ship on every launch, and `Bootstrap::tools()` ships all thirteen; `src/Tools/BuiltIn/` also holds `TaskTool.php`, whose runtime name `Task`<!-- tools:roster:end --> joins the set only when the launcher threads an `AgentManager` into the build (the E675 task feed) — every live `chat()` threads one, so `Task` is present in every real run and absent only from a bare `tools()` call built without a manager. **Be precise about what "ships" buys for `Lsp`:** it is REACHABLE, not yet USEFUL — there is no settings key for language servers anywhere in `src/`, so every launch today builds it with no servers and every call returns an *error* naming the language it could not ask. That refusal is the design, not an oversight: an empty success would read to the model as "this symbol has no references", which is a fabricated fact about your code. The launcher (a per-language server command, `LspConnection::connect()` + `initialize()`, a `publishDiagnostics` subscriber, a shutdown hook, and the same project-trust gate `.mcp.json` gets — starting a server is code execution) is the next step, and until it lands `src/LSP/` is a documented dormant seam rather than dead code. The launcher can start each server once, in the TUI parent: `LspConnection` is already fork-safe, so forked turns and sub-agents share it — request ids are process-unique, every exchange is serialised under a cross-process lock that also carries unread output, a frame half-written or half-read by a killed process is repaired by the next caller, server notifications reach every sharer, and only the starting process can shut the server down. `Lsp` is classified read-only by the permission gate, so `plan` mode allows it without prompting: every one of its operations is a query, and the mutating half of LSP (rename, formatting, applying a code action's edit) is absent from the tool by construction. `RepoMap` is Aider's repo map as a tool: it ranks the files `git ls-files` lists by PageRank over which files reference which definitions and outlines the winners to a token budget, mapping PHP with the engine's own tokenizer and every other language only when Universal Ctags built with `+json` is on `PATH` (`doctor` says which); symbols are cached per project under `~/.sugar-crush/cache/repomap/`, so only the first call in a checkout pays for the parse. `Write` was the odd one out until recently: it was written, tested and referenced from this README, but never listed in that array, so no real run could reach it and `Edit`'s `file_exists()` precondition left `Bash` as the model's only way to create a file. `Bash` takes an optional `timeout` in seconds (default 120, max 600; out-of-range values are clamped, never unbounded): past it the command and its whole process group are killed, and the result keeps the output produced so far plus a line saying it timed out — `interactive: true` runs take the same number as their wall bound beside the idle ceiling. `Edit` matches `old_string` exactly first and then through a chain of looser stages — one indentation shift, whitespace-trimmed lines, collapsed runs of spaces, a block anchored by its first and last lines, folded curly quotes and dashes — each of which must find exactly one place; the result names the stage that matched, a loose match far out of proportion to `old_string` is refused, and further replacements in `edits` are applied in order, all or nothing. Implement `Tools\Tool` for your own.
- **Hooks** — `Hooks\*`: pre/post-tool-use guards (allow / deny / **modify** the input / **ask** the user). `HookManager::registerBuiltIns()` registers `AuditHook`, `ConfirmRemoveHook` and `ProtectFilesHook`; `BashEscapeDenyHook` is registered separately by `EngineBackend::withWorktreeRoot()`, since it needs the worktree root to decide what counts as an escape; and every engine turn registers a fresh `RepeatCallGuardHook`/`RepeatCallCountHook` pair (the repeat-call loop guard, see [The agent loop](#the-agent-loop)) on its own copy of the chain. YAML config and external `ScriptHook` supported: a hook script's exit code selects the outcome — `0` allow, `1` deny, `2` hard block, `3` ask (stdout becomes the question), `4` modify (stdout must be a JSON object replacing the tool input, or the call is denied rather than run unmodified). Hook files are read from `~/.sugar-crush/hooks.yaml` and — **only if you have opted that project in** — `<project>/.sugar-crush/hooks.yaml`, after the built-ins and before the permission gate. Both files are **additive**: a hook may not reuse the name of a built-in guard, of the permission gate, or of a hook the other file already declared — a config file may add to the chain, never replace what is in it. Note the flip side of refusing rather than overriding: if a project file you have trusted declares a hook `name:` your own file already uses, `sugarcrush` stops with exit 2 in that directory until one of the two names changes. A hook that rewrites the tool input (exit `4`) has its rewrite **re-judged by the whole chain** before anything runs — so a rewrite to `rm -rf /` is caught by `ConfirmRemoveHook` on the next pass rather than executed. Read that as "a rewrite gets no privilege the original call would not have had", **not** as a safety net: the re-scan is only as wide as the hooks in the chain, and a rewrite to something the built-ins have no opinion about (`curl … | sh`) re-scans clean and runs.

  **A project hook file is code execution, so it is off by default.** A hook entry is a shell command and a `matcher: '.*'` entry runs it on the model's first tool call — so honouring `<project>/.sugar-crush/hooks.yaml` means that `git clone <repo> && cd <repo> && sugarcrush` runs shell **that repository's author wrote**, with no prompt and nothing in the transcript. No permission mode protects you from it (`plan` included): config hooks are registered before the permission gate, and a scan stops at the first refusal, so the payload has already run by the time the gate would have refused. SugarCrush therefore ignores a project hook file unless *your* `~/.sugar-crush/config.json` — a file no repository can write — names that project in `trustedProjectHooks`:

  ```json
  { "trustedProjectHooks": ["/home/you/work/my-repo", "~/src/other-repo"] }
  ```

  Paths are matched by real path, so a symlinked or trailing-slash spelling of a trusted root still matches. The match is **exact, not a subtree**: trusting `~/src` does not trust `~/src/anything` — list each repository you mean, and note that a trusted root's sibling sharing its spelling (`my-repo-evil` beside `my-repo`) is never trusted by accident. An entry must also be **absolute** (or `~/`-rooted): a relative one like `"."` is resolved fresh against the current directory on every launch, exactly as the project root is, so it would always agree and turn a per-path allowlist into "trust every repository I `cd` into". Such an entry is refused and reported rather than honoured. When a project hook file is present and *not* trusted, the launch says so on stderr — once per launch, naming the canonical absolute path to add — rather than dropping it silently. Your own `~/.sugar-crush/hooks.yaml` is never gated: you wrote it, and that premise is enforced rather than assumed — if this process cannot determine which home directory is yours (`$HOME` unset, `$USERPROFILE` unset, and no passwd entry for its uid) the launch **stops** rather than reading a hook chain or a permission policy out of a world-writable fallback directory.
- **Permission modes** — `Permissions\*`: `PermissionGate` evaluates a tool call against one of six `PermissionMode`s (`default`, `accept-edits`, `plan`, `auto`, `dont-ask`, `bypass-permissions`), with a mode-independent rm-rf circuit breaker and a fail-closed `auto` classifier when no `SafetyClassifier` is configured. It reaches the main loop as `PermissionGateHook`, registered *after* the built-ins so a narrow, specific hazard ("Bash path outside the workspace root") reports before the broad policy one ("mode `plan` does not allow Edit"). Set the mode with `$SUGARCRUSH_PERMISSION_MODE` or a `permissionMode` key in `~/.sugar-crush/config.json` (or `settings.json`, which it outranks); add `permissionRules` entries like `{"pattern": "Bash*", "action": "deny"}` for per-pattern overrides. A pattern is `Tool` or `Tool(argument-glob)`, both halves `fnmatch()` — so `Bash(rm -rf *)`, `Read(./.env)`, `Read(./secrets/*)`, `mcp__*__push`. **Argument-scoped patterns matched nothing at all before this release**: the matcher compared the tool name only, so `Deny Bash(rm -rf *)` evaluated to `allow` while the documentation said otherwise. They work now, and two things about them are worth knowing rather than discovering. **Every argument-scoped `Deny` is advisory**, shell and path alike. A shell one survives whitespace runs and a command hidden behind `&&`/`;`/`|`/newline, and does *not* survive `/bin/rm`, `$(echo rm)`, `bash -c '…'` or `find -delete`, because no pattern over shell text can. A path one survives the `./` prefix, `//` runs and `.`/`..` segments — all normalised away on both sides, so `Read(./.env)` also covers `.env`, `.//.env` and `./foo/../.env`, none of which it caught in the first cut of this feature — and a *restrictive* path pattern spelled relatively additionally reads as "at any depth", so it covers `/home/you/proj/.env` too (a permissive one does not, or `Allow Read(.env)` would grant `/etc/.env`). It does *not* survive a symlinked spelling of the same file, and nothing here touches the filesystem: resolving would make the decision depend on the process's cwd and race the tool being gated. So treat any `Tool(...)` deny as a guard rail against the model doing something by *accident*, not as containment. The boundaries that do not depend on a spelling are `plan` mode, which refuses whole tool kinds, and the path jails, which resolve. The mode-independent `rm -rf /` breaker reads like a third and is not one: unswitchable is not unevadable — it reads `arguments['command']` and tokenises it without expanding anything, so while `/bin/rm -rf /` and `rm '-rf' ~` are caught, `bash -c 'rm -rf /'` and `$(echo rm) -rf /` are past it. (Its own newline-chain hole *was* real and is fixed here: `echo hi⏎rm -rf /` was **allowed** under `bypass-permissions` while `echo hi && rm -rf /` was denied.) And an argument-scoped rule does **not** refuse a *declaration* (a workflow stage's `tools: [Bash]`), only a real call: one `Deny Bash(rm -rf *)` should not make every stage that declares `Bash` unusable. A pattern the grammar cannot parse is reported on stderr — naming the reason it actually gave, which is either an unterminated `(`, a `)` that never opened, an empty pattern, or a missing tool-name half (`(rm *)`) — and skipped rather than loaded as a rule that would match nothing. **The default depends on the path.** The **TUI starts in `default`**: reads run, and every write, shell command and network call asks through the y/n/a modal — on `Chat`'s own tool path and, through the turn's two-way frame channel, on the engine path too — with `/rewind`'s workspace checkpoints behind whatever you allowed. It used to start in `bypass-permissions`, because an ASK on the engine path could not reach the screen and an asking default would have refused every edit; that is fixed (see *Permission prompts* below). One gap remains: a `Task` sub-agent run in a **parallel** batch cannot ask yet, so its question is refused with a reason the model reads — run it alone, allow it by rule, or pick `accept-edits`/`bypass-permissions` if you delegate edits in parallel. **`-p` and background sessions start in `bypass-permissions`**: their approver asks on stderr at a terminal and *refuses* without one, so an asking default would turn an unattended CI run's first edit into a refusal. Be clear about what that costs: with no `permissionRules` configured, `bypass-permissions` is *identical* to having no gate — every destructive `rm` its circuit breaker refuses is already refused, earlier and more broadly, by `ConfirmRemoveHook`. Any mode you configure (flag, variable or key) applies on every path alike.

A permission setting that is **present but unusable stops the launch** (exit 2 — see the exit-code table above) instead of falling back to the permissive default: a `~/.sugar-crush/config.json` that is unreadable, unparseable, whose top level is a JSON *list* rather than an object, or that is not a regular file at all (a directory or a dangling symlink of that name); a config directory this process cannot search (so whether a policy is configured there is unknowable); or a `permissionMode` — in either source — that names no real mode. A **hook file** is held to the same standard, for the same reason: an unreadable or unparseable `hooks.yaml`, a top level that is a YAML *list* rather than a mapping, a top-level key that is not `hooks:` (a typo'd `hook:` used to install zero guards and say nothing), a key on a hook *entry* that is not one of `name` / `matcher` / `command` / `description` / `disabled` / `timeout` (`mather:` used to fall back to the `.*` default and run the hook on every call; `enabled: false` was accepted and ignored, and so was `timeout:` back when this format had no such key — it has one now, and it is honoured), an unknown event name, a matcher that is not a valid regular expression, a `disabled` that is not `true`/`false`, a `timeout` that is not a positive *finite* number of seconds (`0`, `-1`, `.inf`, `1e400` and `.nan` are all refused rather than read as “no timeout”, and so is a `timeout:` written with no value after it), a hook with no command, or a name collision all stop the launch rather than quietly leaving the chain a guard short. `disabled: true` keeps that entry out of the chain while still validating everything else about it; a matcher may contain `/` (the delimiter is chosen to avoid whatever the pattern uses). Absence is not an error: a fresh install with no config, no hook file, and a zero-byte config all get the default. Individual malformed `permissionRules` entries are skipped (never coerced to `allow`) and reported on stderr, because the list is item-wise in a way a JSON syntax error is not; so is a `"permissionRules": null` that is *present* rather than absent, since someone who typed the key believes they configured rules. An error message also names the file the bad value came from, `settings.json` or `config.json`, rather than always naming the one the CLI writes.
- **Skills** — `Skills\*`: frontmatter `SKILL.md` files inject prompt context, matched by keyword/path. Discovered from built-ins (`src/Skills/BuiltIn/`), `<project>/.sugar-crush/skills`, and `~/.sugar-crush/skills` (lowest to highest: **your own skill beats a project's** of the same name, and a project's beats a built-in; every shadowed skill is reported through `SkillManager::skipped()`, the launch notice and `SUGARCRUSH_DEBUG_SKILLS=1`). Ships 8 built-ins spanning language/framework conventions (`php-best-practices`, `laravel-best-practices`, `symfony-best-practices`), testing (`phpunit-master`, `testing-strategies`), `api-design`, `security-audit` and `composer-wizard`. The SugarCraft monorepo's own skills (`explore-codebase`, `worktree-workflow`, `mcp-authoring`, `matchups-sync`) live in the monorepo's project tier (`.sugar-crush/skills/` at its repository root), so they load only inside that checkout — as built-ins they were listed to every project, and one told the model to discard a dirty tree's uncommitted work. `disable-model-invocation` and `user-invocable` frontmatter flags are enforced, not decorative. `context: fork`, `allowed-tools`, `disallowed-tools`, `model` and `effort` are parsed and **not acted on** — there is no fork executor, so a `context: fork` skill behaves exactly like a `thread` one — and the launch says so: one aggregated row names every skill, agent preset, command or rule that declares an inert field or an unknown key (with a did-you-mean for a near miss such as `permisionMode`), and never refuses the file for it ([`docs/SKILLS.md`](docs/SKILLS.md#diagnostics)). Loading is **progressive**: the system prompt carries only each skill's name + description, and the model pulls the full `SKILL.md` body through the `Skill` tool when it decides one is relevant. Path-scoped skills self-announce — the first time `Read`/`Edit`/`Glob` touches a file a skill's `paths:` covers, that skill is surfaced (once per session, via one shared announce-set across the three tools). Those `paths:` globs are `fnmatch()` semantics **without `FNM_PATHNAME`**, so a single `*` crosses `/`, and `**` means zero or more directory levels **at any position, the first included** — which is a behaviour change worth knowing about if you wrote a skill against the older matcher: a leading `**` used not to claim files at the tree root and now does, so the three shipped skills scoped `**/*.php` or `**/*Test.php` (`security-audit`, `php-best-practices`, `phpunit-master`) now fire on a root-level file where they used to stay silent. [`docs/SKILLS.md`](docs/SKILLS.md) has the measured table. Skills authored for other CLIs are imported rather than ignored — `~/.claude/skills`, `<project>/.claude/skills`, `~/.config/opencode/skills` and `<project>/.opencode/skills` are all scanned, and the picker shows a provenance badge for where each one came from (symlinked skill directories are followed, which is how those trees are commonly laid out — but a link is **confined**: one in your own `~/.claude/skills` may point anywhere else in your home, while one in a cloned repository's `<project>/.claude/skills` may not leave that skills directory. That confinement is enforced on the *directory* as well as on each entry in it, and both halves are needed: an entry is judged against the skills directory's real path, so committing `.claude/skills` **itself** as a link used to relocate the boundary rather than trip it — every `SKILL.md` under the target was read, and a skill body is prompt context. The directory is therefore held inside the checkout it came from, which is the one path in the pair a repository cannot have forged; a link that stays inside the checkout (`.claude/skills -> shared/skills`) is still honoured, and a refused tree is dropped without disturbing your own or the built-ins. Be precise about what that buys, though: "inside the checkout" is not the same as "committed", since a checkout also holds untracked and gitignored files — so what is closed is a repo reading files from *outside* the tree you cloned, not every conceivable in-tree misdirection. A refused directory is named on stderr at launch rather than silently skipped. Both directory-level checks — this one and the workflow tier's — are the single predicate in `Support\ContainedPath`, which is also where the difference between them is written down: an entry resolving *onto* its boundary is fine, a directory resolving onto its trust anchor is not. The walk is also depth- and breadth-bounded, so a link to a huge tree cannot cost seconds per launch). Collisions resolve so that **nothing you did not write can re-point a name you already use**: the tier decides first — built-in < project < user, whatever the format — so a cloned repository's `.sugar-crush/skills` or `.claude/skills` copy cannot replace a skill in your `~/.sugar-crush/skills`, `~/.claude/skills` or `~/.config/opencode/skills`, because a project's skill arrives with whatever you cloned (it used to: native project skills outranked yours, audit 15d-03). Inside one tier a native skill wins over an imported one, and between the two foreign tools, opencode wins over Claude. An imported `SKILL.md` that will not parse is another tool's file and not something you can fix, so it is skipped quietly rather than logged to stderr on every launch; the launch prints **one** line saying how many were skipped, and `SUGARCRUSH_DEBUG_SKILLS=1` lists them (they are also readable from `SkillManager::skipped()` and, for the launch as a whole, `Bootstrap::skillSkips()`).
- **Agents** — `Agents\*`: 6 sub-agent presets (coder/reviewer/debugger/architect/tester/devops) with their own model, tools, skills, and a streaming lifecycle, dispatched through `AgentWorkerPool` (`pcntl_fork`-based, with a synchronous fallback + warning when `pcntl` is unavailable).
- **Teams & worktrees** — `Agents\{Team,TeamManager,Teammate,TaskList,Mailbox}`: a lead agent spawns a capped team of teammates that atomically claim `TaskList` tasks (SQLite `flock`-backed, contention-tested) and exchange append-only JSON-lines mailbox messages. `Agents\{WorktreeConfig,WorktreeManager,PathJail}` give each teammate an isolated git worktree (`.worktreeinclude`-aware, swept for staleness) sandboxed by a path jail.
- **Workflows** — `Workflows\*`: `WorkflowBuilder`/`WorkflowRegistry`/`WorkflowEngine` run multi-stage agent pipelines — sequential `stage()`, fan-out `parallel()`, chained `pipeline()`, and task-then-verifier `withVerification()` — defined as PHP DSL files or YAML (`WorkflowRegistry::loadYaml()`). SIGINT/SIGTERM during `run()` captures a real pause file for later resumption at stage granularity. Discovered from `~/.sugar-crush/workflows` and `<project>/.sugar-crush/workflows` (project wins), and driven from the chat with `/workflow run|pause|resume|status|list`. The two tiers are **not** equally capable: a `.php` workflow is loaded by `require`ing it, so only the user tier honours one — a `.php` file in a project's directory is neither listed nor loaded, because a workflow you cloned should not be able to run its own code the moment you name it. Symlinks in a project's workflow directory are **confined** to it: a committed `deploy.yaml -> ~/.ssh/id_rsa` is neither listed nor loaded, so a repo cannot ship a link that reads your files — not even into an error message (a rejected file used to be reported with the YAML parser's message, which quotes the line it choked on). That confinement is enforced on the *directory* as well as on each entry in it, and the two are separate checks for a reason worth knowing: an entry is judged against the workflows directory's real path, which is an answer that moves with the directory, so committing `.sugar-crush/workflows` **itself** as a link used to relocate the boundary rather than trip it — every `*.yaml` basename in the target was listed and every one that parsed was loaded. The directory is therefore held inside the checkout it came from, which is the one path in the pair a repository cannot have forged. A link that stays inside the checkout (`.sugar-crush/workflows -> tools/workflows`) is still honoured: that is repo content pointing at repo content, the same trust as a committed `.yaml`. A link inside your own `~/.sugar-crush/workflows` is still followed; that directory is yours. A *dangling* link is refused rather than granted — it names no workflows, and a committed link to a path that does not exist yet is a request to read whatever appears there later — while a directory you simply have not created is still named in the "not found" message. And note the boundary is the checkout, not the workflows directory, for the directory-level check, which has a residual worth stating: a checkout also holds untracked, gitignored, developer-local files, so a repo committing `.sugar-crush/workflows -> <some other directory in the checkout>` can still disclose that directory's `*.yaml` basenames and descriptions. What the check refuses is the version of that worth having — a link resolving **onto the checkout root**, which is where local files like `local-secrets.yaml` actually sit and which `-> ..` reached in one committed line. Reduction, not elimination. Whichever tier refuses a directory, the launch says so on stderr, naming the path and where it resolved to. The same boundary and the same predicate (`Support\ContainedPath`) hold a cloned repository's `.claude` / `.opencode` / `.sugar-crush` **skills** directories — see the Skills bullet above; the two tiers ran separate copies of the idiom until the skills one turned out to be missing the directory-level half entirely. A project's `.yaml` workflow is declarative and does run: its tasks are dispatched as sub-agents carrying the launch's `PermissionGate`, and a stage that **declares** a tool the session's permission mode denies (`tools: [Bash]` under `dont-ask`, say) is refused before the workflow's *first* stage is dispatched, not when that stage is reached. Be precise about the limit of that, though, because "denied tools are refused" reads as more than it is. Which modes can refuse a *declaration* is a per-mode answer: `dont-ask` refuses every non-read-only tool; `plan` refuses `Edit`/`Write`/`mcp__*` but **not** `Bash`, because Plan judges each Bash call by its command (read-only commands run, everything else is denied) and a declaration has none; `auto` refuses **nothing** through its mode logic, since its judgement is `SafetyClassifier`'s and the classifier reads the command out of the arguments too — under `auto` only an explicit `Deny` rule refuses a declaration. `default`/`accept-edits` *ask*, and an `Ask` is deliberately not a refusal (settling one needs the blocking prompt, which the engine has no channel to). Nor is the declared list a capability *boundary*: a parallel stage's agents are all handed the first task's `tools`, so the list is a request that gets permission-checked, not a sandbox. Per-*call* gating does happen now: on a launch with a provider, every stage agent is a real tool loop through the chat's own engine (`Agents\EngineExecutor`, run in the pool's forked child), so each tool call a stage makes passes the session's hook chain and `PermissionGate` at the moment the model asks for it — the declaration check above is the up-front half, the per-call gate the second. (`/workflow run` does not block the TUI while it runs — see *Limitations* below.) See [`examples/workflows/lint-then-fix.yaml`](examples/workflows/lint-then-fix.yaml) for a runnable YAML example and [`workflows/deep-research.php`](workflows/deep-research.php) for the PHP DSL form.
- **MCP** — `MCP\*`: multi-server client (stdio + HTTP, `.mcp.json`, `${VAR}` interpolation) and stdio/HTTP servers to host your own tools. Per-agent-preset `mcpServers` allowlists are enforced at grant-resolution: `AgentManager::resolveGrantedTools()` narrows a sub-agent's roster to the bridges of the servers its preset names, consulting the same law `McpRouter` applies (`McpRouter::serverAllowed`) — an empty or absent list means allow-all, and the main interactive chat carries no preset, so it stays unrestricted as recorded (E696). The `McpClient`-side `AgentPreset` arm remains the tested fail-closed mechanism for a routed client; deny-patterns are still minted and unwired — no settings producer feeds them (E696). A configured server's tools reach the model as ordinary tools named `mcp__<server>__<tool>` (`Tools\McpToolBridge`), on the same PreToolUse chain `Bash` rides; a call is addressed to the server the bridge belongs to, so two servers each exposing `search` do not collide. The chain is shared, but the *decision* is not always the same one: measured across all six permission modes, an unhinted `mcp__*` name and `Bash` agree in four and diverge under `plan`, where `Bash` is allowed for exploration and the `mcp__*` name is denied as a write tool, and under `auto`, where the `mcp__*` call asks — the conservative direction, since a server-side tool's effects are unknowable from here. `dont-ask` denies every unhinted MCP call outright. A tool whose trusted server declares `annotations.readOnlyHint: true` (and not `openWorldHint: true`) is classified as a read instead, so it runs unasked wherever `Read` does ([`docs/PERMISSIONS.md`](docs/PERMISSIONS.md)). Note also that the wire name is the *sanitised* one, so a permission rule for the `.mcp.json` key `github.com/foo` must be written `mcp__github_com_foo__*`; the permission prompt shows you the sanitised name at the moment it asks. Configs copied from other tools are read verbatim — `type: local`/`remote`, a whole-argv `command` array, `environment`, and `enabled` are normalised at launch — and a no-auth `http` remote runs from its `url` alone with nothing to register; [`docs/MCP.md`](docs/MCP.md) works both spellings through under "Adding servers".

  **A project `.mcp.json` is code execution too, so it is off by default — same gate, separate key.** Starting an MCP server means `proc_open()`ing a program the *repository* chose, at launch, before any tool call and in **every** permission mode including `plan` — the permission gate sees tool calls, and this happens earlier. It is not even conditional on the server working: a bogus entry's command runs, the handshake fails, and the server is discarded. So `<project>/.mcp.json` is honoured only when *your* `~/.sugar-crush/config.json` names that project in `trustedProjectMcp`:

  ```json
  { "trustedProjectMcp": ["/home/you/work/my-repo"] }
  ```

  **The grant covers the servers you trusted, not whatever the file says next** (audit MCP-5). Each server's command, args, env (as written, `${VAR}` unresolved), url, headers and type are fingerprinted per project in `~/.sugar-crush/mcp-trust.json`: the first launch under a grant records what it starts, and from then on a server whose fingerprint changed — a `git pull`, a branch switch, a contributor's checkout — or that was added is **not started**, and the launch names it with the command line it was recorded as and the one it has now. `sugarcrush mcp trust`, run in the project, shows every entry as `new`/`changed`/`unchanged`, adds the root to `trustedProjectMcp` if it is missing, and records the file as it is now.

  Everything said above about `trustedProjectHooks` — real-path matching, exact roots rather than subtrees, absolute-or-`~/` entries only, the list frozen for the process — holds here identically, because it is the same parser. It is a **separate** key on purpose: trusting a repo's `hooks.yaml` is not the same decision as letting it start long-lived server processes, and reusing the hooks list would have widened an existing grant on upgrade rather than at the user's request. An untrusted `.mcp.json` is reported on stderr, naming the file, the reason and the key to add, rather than dropped silently — but be clear about the limit of that, because it covers refusals and not breakage: a *trusted* config that is malformed mostly degrades in silence. Of the five shapes a broken `.mcp.json` takes, only an unknown server `type` says anything; a `command` that is misspelled or not installed (the common case), invalid JSON, and valid JSON under the wrong top-level key all cost you every tool on that server with nothing printed anywhere. That is a `McpClient` defect — it swallows a failing `start()` and treats unparseable JSON as an empty config — and it is on the hardening backlog rather than fixed here. A server's handshake (`initialize` + `tools/list`) is bounded by a 60s wall clock — generous because a first-run `npx` server fetches a package tree before it answers — overridable per server with `"startTimeout": <seconds>`; a `tools/call` is **not** bounded by default, since a tool call is somebody else's work and may legitimately run for minutes — a call running alone sends the turn a heartbeat while it waits, so the turn's 120 s idle watchdog does not kill it, and a server that should be cut off opts in with `"toolTimeout": <seconds>`, which abandons the wait at the bound and sends the server `notifications/cancelled`. Every MCP answer is capped at 65,536 bytes, with a marker naming what was cut.
- **Sessions** — `Session\SessionStore`: SQLite (WAL) persistence of sessions/messages/tool-calls with FK-enforced cascade. **Retention is opt-in and off by default**: set `$SUGARCRUSH_SESSION_RETENTION_DAYS` to a positive number of days and each launch drops sessions untouched for that long, reporting on stderr exactly what it removed. **A session you have named or pinned is never pruned, whatever its age** — a name is the signal you meant to keep it, and a pin is the explicit form of it — and neither is the session the launch is about to resume. A sub-agent's session is pruned only with the session that spawned it, and a branch outlives its pruned or deleted source (it is detached, not deleted). Each row records its kind (`main`, `branch`, `subagent`, `background`), its parent, whether it is pinned or archived, its turn count and the last prompt you sent; `Session\SessionQuery` filters on them. `Session\EnhancedSessionStore` adds the per-turn `/rewind` checkpoints on top; message bodies are content-addressed and stored once each, so the conversation itself costs storage proportional to its length rather than to its length squared (the per-checkpoint list of message references is still proportional to the length).
- **Tokens & export** — `Util\TokenTracker` (token + cost accumulation, fed one entry per settled turn from the provider-counted figures on `Usage`, and read by `/budget` and the status bar's spend readout) and `Util\Exporter` (Markdown / JSON / text transcripts).
- **Messages** — typed `Messages\{System,User,Assistant,ToolResult}Message`; `UserMessage` carries file/image attachments; `AssistantMessage` carries tool calls + reasoning.
- **Context files** — `CLAUDE.md`/`AGENTS.md` at the project root (or another agent's `GEMINI.md`/`.cursorrules`/`.clinerules`) are loaded into the system prompt, after your personal `~/.sugar-crush/AGENTS.md`, with `@import` expansion (cycle- and traversal-guarded, and de-duplicated so an imported doc is not injected twice). `Forced` instructions come from user config. An `EnvironmentBlock` (cwd, platform, model, date) closes the system prompt so the model is not guessing at its surroundings; the live git state (branch, status, recent log, diffs after a write) and the files the agent touched ride a `<turn-context>` row at the end of each request instead, so the prompt itself stays byte-stable for the provider's prefix cache.
- **Permission prompts** — the blocking request/reply flow is wired end to end: `Chat` runs the whole batch of a turn's tool calls through the `PreToolUse` chain *before* forking any of them, and a `HookResult::ask()` suspends the turn on a `PermissionRequestMsg` rendered as a Veil modal over the transcript. `y`/`n`/`a` settles the paused call rather than being advisory, and `a` — once its confirm is answered with a second `y` — records a session-scoped grant so that the same call (same tool, same arguments) stops asking. The grant answers only the permission gate's own question: when one of your hooks asks, "always" counts as "once", and that hook's question is put again on the next call. The prompt is *armed* when it goes up and any non-answer keystroke disarms it (`Enter` re-arms), so an ordinary slash command typed at a live prompt is swallowed instead of answering it. The TUI's default mode, `default`, asks for every write, shell command and network call; `accept-edits` and `auto` ask for less, and `bypass-permissions` and `dont-ask` never prompt (a hook that returns `ask()` still does). The **engine** path asks through the same modal: an engine turn runs in a forked child, and each ASK it raises crosses the turn's two-way frame channel as an `ask` frame, goes up as the same y/n/a prompt, and the answer goes back down as `ask_reply` while the child waits (its idle ceiling is paused for as long as the question is open). There, `n`/`Esc` hands the model a `Permission denied:` result and the turn carries on, and `a` + `y` remembers a **pattern** for the rest of the session rather than one exact call — `git status` grants `Bash(git status)` and `Bash(git status *)`, an `Edit` grants that path, a `WebFetch` that host, an MCP tool that tool; a chained, piped or redirected `Bash` line, or one behind a launcher such as `bash -c`/`sudo`/`xargs`, is remembered exactly. A remembered pattern only ever answers a question: it never lifts a configured `Deny`, Plan mode's refusals or the `rm -rf /` breaker, and it is never written to a settings file. A question the turn ends underneath (the child gone, the stream corrupt) reaches the model as `Permission required:` — nobody answered it — not as a refusal. The one-shot `-p` path is different: it attaches a console approver, prompts on stderr at a terminal, and refuses with a reason when there is no terminal.

## Architecture

SugarCrush keeps the proven sugar-crush **chassis** (the `Chat` candy-core `Model`, buffer-diff `Renderer`) and runs the ported **engine** behind it. The interactive binary boots the **pane shell** that hosts that chassis:

```
bin/sugarcrush
  └─ Bootstrap::app()
       └─ Program → App (root candy-core Model: menu bar, pane focus, session tabs)
            ├─ Tui\Renderer → ChatPane ─┐
            │                            └→ Renderer (the live buffer-diff chat renderer)
            └─ Chat (Model: input, scrollback, inFlight gate, permissions, zones)
                 └─ Backend  ── EchoBackend / CommandBackend (simple)
                             └─ EngineBackend (agent loop, emits tool-lifecycle events)
                                  └─ Runtime → ProviderInterface  (+ Tools · Hooks · Skills via App)
```

`App` plays two roles that are easy to confuse: it is the **engine's state object** (`Runtime::run(App $app, …)` and `EngineBackend` both take it, carrying tools/hooks/skills) *and* the root TUI `Model` the pane shell renders. `Chat` is untouched by the shell — it is still a standalone `Model` you can run directly with `new Program(new Chat(...))`.

The chassis speaks the root `Message` value object; the engine speaks the typed `Messages\*` hierarchy; `EngineBackend` converts at the seam.

## Limitations

Things that are genuinely not finished, stated plainly rather than left for you to discover:

- **`toolCallParser` reaches only one of the three providers.** It is closed for `SglangProvider`, which now consults the selected parser on the streaming path as well as the batch one. `CustomProvider` and `OpenAIProvider` accept no `toolCallParser` argument at all and read only the structured `tool_calls[]` shape (streamed fragments included), so on those two a deployment launched without `--tool-call-parser` still loses every tool call, with no setting that would recover it.
- **An ASK on the engine path is answered everywhere — with one limit.** It used to fail closed everywhere, because **nothing anywhere attached an approver**. The **one-shot `-p` path** now attaches `HeadlessPermissionPrompt`, which puts the question on **stderr** (never stdout — `--output-format json` promises exactly one JSON object there), reads the answer from stdin, and grants only on a literal `y`/`yes`; with no terminal on stdin it does not read at all and refuses, naming the tool, the mode and what to change (`--permission-mode`, or a `permissionRules` entry). The **TUI** answers through its modal: `Chat` starts every engine turn through `completeInteractive()`, the forked child puts each question up the turn's socket, and the answer goes back the same way (see *Permission prompts* above). What is still missing:

  - **There is no way to type rejection feedback in the TUI yet.** The channel carries it — a reply's note reaches the model as `the user said: …` beside the refusal — but the modal has no key that opens a note.

  The TUI's default mode is `default`, which asks; `-p` and background sessions keep `bypass-permissions` (see *Permission modes*).
- **The `anthropic` provider type key is OpenAI-shaped.** It authenticates as Anthropic but posts to `chat/completions` with `supportsFunctionCalling: false`, so it cannot call tools. Use `claude-code` or `SUGARCRUSH_BACKEND_CMD` for a native Anthropic path.
- **Five shell commands are still inert**: `GroupInputCmd`, `CancelAgentCmd`, `ResumeAgentCmd`, `StopAllAgentsCmd`, `QuitAgentViewCmd`. The first has no counterpart in the live app; the agent four would need to reach into a worker pool the shell does not hold. Their pane/selection half *is* applied — only the action half is missing.
- **Workflow resume granularity is per whole stage.** An interrupted *parallel* sub-stage cannot be resumed with partial credit.
- **Workflow stages run the real tool loop — on a launch with a provider.** `Bootstrap::workflowEngine()` gives the pool an `Agents\EngineExecutor` as its forked executor, and `Chat`'s constructor binds the chat's current backend into it (re-bound on a provider switch). Each stage agent — sequential, pipeline, verification or parallel — then runs through that engine's bounded loop in a forked child: same provider, hook chain, permission gate and root as the chat, narrowed to the stage's `tools:`, with `Task` withheld and a 200-step cap. With no provider, the pool keeps `ProcessExecutor`'s worker, which fails closed naming the absence, and a provider that fails to build (the chat then degrades to echo) is refused by the executor itself — echoed text is never reported as a stage's work.
- **`/workflow run` keeps the TUI alive, with two limits worth knowing.** It used to freeze it outright: `Chat::update()` called `WorkflowEngine::run()` synchronously on the ReactPHP loop, so a multi-stage workflow meant no repaint, no keystrokes and no spinner until the last stage. It no longer does. `Chat::workflowRun()` hands the run to a `\Fiber` that a periodic timer on the loop steps, suspending at `AgentWorkerPool::idle()` — the one point where the parent is idle while forked workers run — so the spinner turns, keystrokes land, and the live-agent split pane paints tiles while the workflow is still going. (The `stream_select` this bullet used to blame is in the CHILD, and never was the obstacle. And do not "fix" this with the fork-plus-socket pattern `EngineBackend::completeAsync()` uses, which this bullet used to recommend: `AgentManager::liveOutputs()` reads an object graph in the PARENT, so forking the workflow would put every sub-agent somewhere the renderer cannot see and repaint the pane promptly and blank. A fiber suspends the whole call stack in-process, which is why it is the right shape here and `completeAsync()`'s is not.) There was never an issue #79 for any of this — detain/sugarcraft #79 is a merged CandyMetrics pull request.
  - **Double-Escape stops the run.** It releases the turn at once and cancels the run's `CancellationToken`, which `WorkflowEngine` answers by calling `AgentWorkerPool::cancelAll()` on the stage's live pool — the stage's forked agents are killed (asynchronously, with a grace) — and by starting no further stage. The partial report still lands, headed **cancelled**, with the stages that ran and what they spent; it is only appended to the transcript, so a turn you started after the cancel is not disturbed. An engine double that ignores the token simply finishes. A second `/workflow run` is refused while one is live (including while a cancelled one winds down) rather than started alongside it.
  - **Only forked workers publish live output.** The pane is fed by `AgentWorkerPool::pumpProgress()`, which mirrors what a forked child writes to its progress file. An engine-run stage (the bullet above) streams while it works: its prose as it arrives and a `▸ Tool(args)` line per tool call, coalesced to at most one append per 250ms, so a long stage's tile fills in live. Because the progress file is append-only it reads as an activity log ("said this, then called that"); the stage's result, and what `{{stage.output}}` interpolates, is the final answer alone. The three paths that do NOT fork — an injected `ExecutorInterface` (see the bullet above), a build without `pcntl`, and a failed `fork()` — run `execute()` synchronously in the parent, which blocks the loop for its duration: no progress, and the fiber cannot help because nothing yields. Injecting your own executor to reach a real model therefore costs you the live pane.
- **`pcntl` is required for real parallelism.** Without it `AgentWorkerPool` falls back to sequential execution and logs a one-time visible warning rather than pretending to fan out.
- **Providers are unit-tested against mocked transports.** No test in this suite makes a live API call, so wire-format drift at a real endpoint is caught by the `doctor` tool (model-invocable; there is no `/doctor` slash command) and by using it, not by CI.
- **The `doctor` tool reports capabilities, it does not repair them.**
- **A background session is re-adopted only by a launch of the same project.** Its result is announced into the conversation that adopts it, so a relaunch elsewhere leaves it for the project it was started for. The index lives in the system temp directory, so a reboot (which ends the daemons anyway) or a temp cleaner forgets it.

## Custom provider

```php
use SugarCraft\Crush\Providers\{ProviderInterface, CompleteRequest, CompleteResponse, EmbeddingsRequest, EmbeddingsResponse};

final class MyProvider implements ProviderInterface
{
    public function name(): string { return 'mine'; }
    public function supportsStreaming(): bool { return false; }
    public function supportsFunctionCalling(): bool { return true; }
    public function supportsVision(): bool { return false; }
    public function supportsJsonSchema(): bool { return false; }
    public function contextWindow(): int { return 128_000; }
    public function costPer1kTokens(string $model, string $direction): float { return 0.0; }
    public function complete(CompleteRequest $r): CompleteResponse { /* ... */ }
    public function completeStream(CompleteRequest $r): \Generator { /* yield CompleteResponse chunks */ }
    public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { /* ... */ }
}
```

## Tests

```bash
cd sugar-crush && composer install && vendor/bin/phpunit
```

**16,416 tests / 316,514 assertions, 0 failures, 1 skipped** — the whole of
`sugar-crush/tests/` (that suite only, not the monorepo) in one
`vendor/bin/phpunit` run from the monorepo root with linked siblings, on PHP 8.3.6,
21m07s. Measured 2026-10-03, after the second library-fix round against
`crush_libs.md` (slash commands admitted on any anchored full-query alignment,
MCP error codes, `/proc` starttime reads, the LSP note journal, and ending the
HTTP MCP session when a start fails after the handshake) added 12 tests. Before
that, 16,404/316,385 earlier on 2026-10-03, after the first library-fix round
and its sugar-crush follow-ups (full-query palette and slash-command
matching, `TextSelection` on candy-mouse, malformed zone markup in `scanRoot`,
the terminal-background agreement with candy-sprinkles, MCP/LSP exchange-lock
hardening with the `tools/list` gate and liveness probes, and posix-less
fallbacks for the home directory and background-session liveness) added 54
tests, in 20m49s; 16,350/292,129 later on 2026-10-02, after audit waves w7 to
w11 (provider gating and MCP OAuth, permission modes, the scrubbed spawn
environment, the SGLang server limits, the session lock and read-only second
window, prompt cache marks and pricing, Esc Esc for workflows, and
`@file`/image attachments) added 1,075 tests, in 20m02s; 15,229/275,875 earlier on 2026-10-02, after
audit wave w6 (textual tool-call markup held
back and cut from the assistant text, schema-typed MiniMax parameters, malformed
tool-call JSON refused, a working claude-code stream path, menu commands that
keep the draft, `/bg stop` and released background IPC files, a locked and
symlink-safe settings write, int-range step and token ceilings, a bounded
WebFetch with non-2xx status, a redirect-refusing WebSearch, and filename-keyed,
provenance-labelled memory notes) added 308 tests; 14,921/273,937
earlier on 2026-10-02, after audit wave w5 added 556
tests; 14,365/188,431 after audit wave w4
added 336 tests; 14,029/186,454 after audit wave w3 added 366
tests; 13,663/184,048 after audit wave w2 added 419
tests; 13,244/181,861 after audit wave w1 added 111 tests;
13,133/180,827 on 2026-10-01, after the audit hotfix wave added 650 tests;
12,483/177,317 on 2026-09-30. The pane-docking feature re-pinned the figure in stages,
one commit each — the five `Dock*` suites (`3c90855aa`), the drag-gesture test pair
(`37c50e389`), the review-fix round (`b4a5a11e7`), the docking crash/resize fix
lane, the menu-bar pane tabs with their click-toggle, dock-scoped focus cycle
and palette door (L2, `3c4db713d`..`b9ea9b386`), and the live-gesture fix (full-height resize
seam, empty-side drop target, cross-side painting, drop-target outline) — each adding its own tests and
assertions, so the running arithmetic lives in
`git log` rather than in this sentence (a hard-coded "+N over M" chain went stale here
the moment the next stage landed). The
re-pin before that: the three `WebSearch` tests that had been asserting against a
live SearXNG endpoint were made hermetic — the day
the endpoint stopped answering they took `Test PHP 8.3 · sugar-crush`,
`… 8.4` and `Coverage · sugar-crush` red, which is a decoder of that host's
uptime and not of this suite. Stubbing the tool's one network call moved zero
tests and +27 assertions (170,810 → 170,837), and took roughly 150s of connect
timeouts out of the sharded run. The figures before that: 12,061/170,810 on
2026-09-17 — the r88 sibling builds (candy-forms Date/Slider/Color + mouse/clipboard, candy-vt row-shift) moved zero crush tests and retree the tree-scan censuses into a few-dozen-assertion re-count; measured on the round-89 wave-2 weld, where the E744 widget-Cmd
relay and session-picker pins raised the tests figure +34 over the campaign
floor of 12,027 (itself the round-83/84 floor, +18 pins over 12,009/172,461 on
2026-09-15), while the assertions re-derive: upstream's wave-8 determinism tails rode the merge, so
`AssertionSwallowingCatchTest`'s scan now throws instead of recording an assertion
per `try`/`catch` construct (the suite figure is no longer wired to how many blocks
the tree happens to contain) and the two chain-budget `HookRegistryTest` cases count
their pump on an injected clock instead of the wall. The figures before this merge:
12,027/170,422 (2026-09-17); the
figure that stood here before, 7,276/76,239 in
2m38s (2026-08-19), was behind the suite by some four thousand tests — rounds
44 through 61 each shipped guards — and the figure before that, 6,424/51,767
in 1m52s, was behind the suite by 852 tests and 24,472 assertions. The
skip is `MCP\McpClientTest::testLoadConfigReturnsEmptyArrayWhenFileGetContentsFails`,
which `markTestSkipped`s itself with "would require mocking built-in functions"
— reaching `loadConfig()`'s `file_get_contents` failure branch needs a
`.mcp.json` that `file_exists()` but cannot be read. It is the only skip, and
`failOnWarning="true"` means the run is also warning-free.

**The tests figure above is pinned, not promised.** For three rounds this
paragraph confessed the figure was "stale by construction" and rested on "the
command above is the authority" — true, and it still let the number rot: the
last figure was behind the suite the day it landed. Now
{@see sugar-crush/tests/Config/ReadmeSuiteFigureDriftTest} re-enumerates the
suite live on every run (`phpunit --list-tests`, about three seconds) and reds
if this line's test count differs from what PHPUnit collects. The assertions
figure cannot be re-derived without the full run — a guard that re-ran the
suite inside itself could never see its own count — so it is a
*junit-derived maintained artifact*
(`tests/Config/Support/suite-figure.json`, regenerated with
`tests/Config/Support/refresh-suite-figure.php` after
`--log-junit`), kept from silent age by the same live recount: add a test
without regenerating, and both the README pin and the artifact pin go red on
the next run of anything. The figures for scale that used to open this
paragraph — first revision 4,337/12,587, over 2,000 tests low — are history
now, not an acceptable failure mode.

**Sharded local runs.** The serial run above is ~9.4 minutes; from the
monorepo root, `scripts/parallel-tests.sh` shards the same suite by committed
per-file durations and gates on conservation — the per-shard JUnit sums must
equal `tests/Config/Support/suite-figure.json` exactly:

```bash
bash scripts/parallel-tests.sh \
  --durations scripts/parallel-tests-durations.tsv \
  --against-json sugar-crush/tests/Config/Support/suite-figure.json
```

K defaults to min(nproc, 8): measured on this tree, serial 565s, K=4 159s,
K=8 ~65-73s wall — the largest LPT bucket (~62s, one ProcessExecutorTest-class
file) is the hard floor, so past K=8 more shards stop paying. CI pins
K=min(nproc, 4) because its 2-4 vCPU runners must not be oversubscribed. The
conservation gate is fail-closed by design: a test file added without a
`scripts/parallel-tests-durations.tsv` row lands in zero shards, the shard sum
falls short of the pinned figure, and the run goes RED. To refresh the
manifest after adding tests, take one serial baseline with `--log-junit` and
feed it back once — `bash scripts/parallel-tests.sh --junit <xml> --out <dir>`
writes `<dir>/durations.tsv`; copy it over
`scripts/parallel-tests-durations.tsv` and re-pin the figure with
`tests/Config/Support/refresh-suite-figure.php`, in the same step as the
README headline.

Coverage spans every subsystem: typed messages + attachments, all 11 built-in
tools (the whole of `src/Tools/BuiltIn/`, which is exactly the built-in half of
the array `Bootstrap::tools()` hands the engine — the array itself is longer
whenever a trusted project's MCP servers advertise anything), all 7
`Providers\ProviderFactory` type
keys (unit-tested with mocked transports — no live calls), the hook framework, permission-mode gating (incl. `pcntl_fork` concurrency stress tests for atomic task claiming), skills discovery + flag enforcement, sub-agents/teams/worktrees, workflow execution (sequential/parallel/pipeline/verification, PHP + YAML loading), the MCP client/servers (incl. per-agent routing enforcement), the SQLite store, token tracking, export, the TUI components, the `Runtime` orchestration (streaming accumulation, tool-result correlation, MODIFY hooks), the shell-out `CommandBackend` / `StreamingCommandBackend`, and the `EngineBackend` agentic loop (incl. the `maxSteps` guard).

A dedicated `tests/Integration/` tier asserts **reachability** rather than behaviour: that the session store, session tabs, background sessions, the skills subsystem, mouse mode, the environment block and root context-file loading are actually reached from `bin/sugarcrush` → `Bootstrap::app()`, not merely implemented somewhere in `src/`. That tier exists because the audit recorded in the monorepo root's `crush_code_update.md` found well-tested subsystems that no real run could ever touch — the `Write` tool being the most recent: a full suite of its own, and one missing line in `Bootstrap::tools()`. The tier now also pins the whole built-in tool set by count and by name, since an omission from a literal array is not something a per-tool test can see.

See [`CHANGELOG.md`](CHANGELOG.md) for how the suite got here.
