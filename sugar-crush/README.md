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
sugarcrush --config ~/policies/crush.json       # read settings/permissions from this file
sugarcrush --model gpt-5 -p "audit this"        # pick the model (not the provider)
sugarcrush --permission-mode plan -p "audit this" # pick the permission mode for this run
sugarcrush --help                               # prints and exits (never opens the TUI)
sugarcrush --version                            # prints the installed version and exits
sugarcrush -- --not-a-flag                      # `--` ends options; everything after is positional
```

`--model <name>` (also `--model=<name>`) names the conversation MODEL and
overrides `$SUGARCRUSH_MODEL` and the provider's own default. It does not pick a
provider — that still comes from `$SUGARCRUSH_PROVIDER` or the persisted
`provider` setting, and the two are independent axes. The Ctrl+P palette entry
labelled "Switch model" switches the PROVIDER, which is why the distinction is
worth stating twice.

`--permission-mode <mode>` (also `--permission-mode=<mode>`) runs this launch
under one of `default`, `accept-edits`, `plan`, `auto`, `dont-ask`,
`bypass-permissions`. It is the highest-precedence source, beating
`$SUGARCRUSH_PERMISSION_MODE` and the `permissionMode` config key.

A **non-empty** value that is not one of those modes refuses the launch with
exit 2 rather than falling back to the permissive default — the same refusal,
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

`--root` also accepts the first positional argument that looks like a path, so
`sugarcrush ../other-project` works. It is what the Bash/Read/Edit/Glob tools
are jailed to and where `CLAUDE.md`/`AGENTS.md` and `.sugar-crush/skills` are
looked for.

### Settings files

Four files are read, and the **highest one that mentions a key wins** for that
key:

| # | File | Who wrote it | Wins over |
|---|------|--------------|-----------|
| 4 | `~/.sugar-crush/config.json` | you, and the CLI itself — Ctrl+P and `/theme` write `theme` here, `/model` writes `provider` | everything |
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

Only these keys are layered — `provider`, `theme`, `titleModel`,
`summaryModel`, `instructions`, `disabledSkills`, `parallelToolCalls`,
`parallelToolDeadlineSeconds`, `allowedTools`, `disabledTools`, `statusLine`. The
`trustedProject*` lists are read from `~/.sugar-crush/config.json` **alone**, so
no lower layer can grant itself trust.

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

Even for a trusted project, four keys are **never** taken from a project file:
`statusLine`, because its value is a shell command this app runs on a timer —
a project-tier one would be arbitrary code execution on clone-and-launch, with
no tool call and no permission gate anywhere in the path;
`provider`, because it decides which host every prompt in the session is sent
to; `instructions`, because it decides which files become authoritative
system-prompt text; and `allowedTools`, for a reason worth spelling out because
on capability alone it looks harmless. A whitelist is an intersection — it
cannot add a tool that `Bootstrap::tools()` did not build — but its effect is
defined by what it *omits*, so `allowedTools: ["Bash"]` deletes all ten of the
others — `Read`, `Edit`, `Glob`, `Grep`, `Write`, `WebFetch`, `WebSearch`,
`doctor`, `Skill` and `Lsp` — in one line, and what the model does next is the
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
> leaves exactly `Bash` out of the eleven built-in tools. The glob is five
> characters and names none of the ten it removes. The negation is not the
> trick either: `["[C-Z]*", "[a-z]*"]` leaves exactly `Bash` too, measured the
> same way, so no restriction on *pattern shape* could make the old sentence
> true again. What earns the paragraph its place is the shape argument for
> `allowedTools` above, which does hold; what it lost is the claim that
> `disabledTools` cannot express the same thing.

**Two things narrow it, and both are measured.** An *untrusted* project's
`disabledTools` never reaches the merge at all — all eleven tools survive — so
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
sugarcrush: /repo/.sugar-crush/settings.json (disabledTools) disabled 10 of the
11 tools your own settings left — Read, Edit, Glob, Grep, Write, WebFetch,
WebSearch, doctor, Skill, Lsp — leaving: Bash.
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
| `2` | usage/configuration error, nothing was attempted: no prompt given, unrecognized flag, an `--output-format` value that is neither `text` nor `json`, `--config` naming no readable file, `--root` naming no directory, a missing `vendor/autoload.php`, a **permission policy that is present but unusable** (see [Permission modes](#capabilities) — an unreadable/unreachable/unparseable `~/.sugar-crush/config.json`, or a `permissionMode` naming no real mode), or a provider (from `$SUGARCRUSH_PROVIDER` **or** the persisted Ctrl+P choice) that cannot be constructed — retrying will not help. `error.type`: `usage`, `provider_configuration`, `installation` — the last one is the missing `vendor/autoload.php`, and it is what tells a consumer which kind of `2` it got |

`2` covers "no prompt given" (`sugarcrush -p`, `sugarcrush run`) deliberately:
the invocation is malformed, no backend is ever selected, and a CI gate that
retries on `1` would otherwise retry it forever. It also covers a subcommand
handed a missing or unknown operand (`sugarcrush session`, `sugarcrush mcp
bogus`, `sugarcrush completion tcsh`).

### Subcommands

```sh
sugarcrush doctor                    # check this installation, exit 1 if anything FAILs
sugarcrush models                    # providers this install can select; * marks the selected one
sugarcrush session list              # stored sessions, newest first
sugarcrush session delete <id>       # delete one stored session
sugarcrush mcp list                  # what .mcp.json declares — without starting anything
sugarcrush completion bash|zsh|fish  # a shell completion script on stdout
```

Every one of these is dispatched in the same pre-flight place `--help` and
`--version` are, **before** `Program` is constructed: they answer on a machine
with no provider, no API key and no TTY, and none of them enters the
alt-screen. `doctor` is the sharpest case — it is a health check for an install
that may be broken, so it must not require the thing it is diagnosing. A
config whose `permissionMode` is unusable makes the launch refuse to start
(exit `2`, above); `doctor` still runs, names that as the failing check, and
exits `1`.

`doctor` is **read-only**: it counts the rows in the session database through
`Bootstrap::sessionStore(prune: false)` rather than the plain accessor, so the
opt-in `SUGARCRUSH_SESSION_RETENTION_DAYS` sweep that a *launch* applies cannot
delete conversations on the way to a health check. `doctor` and `models` take
no operands and reject one at exit `2` rather than ignoring it.

`sugarcrush doctor` is **not** the model-callable `doctor` tool. That one is
registered in `Bootstrap::tools()`, advertised to the LLM, and answers a
completely different question — which image protocol this terminal speaks, with
a PNG capability swatch attached. The CLI subcommand reports PHP, the
extensions the session store needs, the config file, the permission policy, the
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

`--output-format json` applies to `doctor`, `models`, `session list` and `mcp
list`, producing the same `{"result": …}` envelope the one-shot path does, and
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
that kept the code tells apart the kinds of each.

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

1. **One-off, this run only:** `SUGARCRUSH_PROVIDER=dev-sglang ./bin/sugarcrush` — `dev-sglang` is the project's own dev/test SGLang endpoint (declared in `.sugar-crush/config.dev.json`, checked into the repo), useful for trying a real (if smaller) model with zero API keys.
2. **From inside the TUI:** press **Ctrl+P**, choose **Switch model**, pick any provider from the list (built-in types plus every name declared in `.sugar-crush/config.dev.json`, e.g. `dev-sglang`) — switches immediately, no restart. `/model` opens the same picker and `/model dev-sglang` skips it. **Switch theme** works the same way for color themes.
3. **Persisted across restarts:** either of the above choices — the palette's, or `/model`'s, which goes through the same code path — is written to `~/.sugar-crush/config.json` and read back on the next launch — so picking `dev-sglang` once via Ctrl+P means every future `./bin/sugarcrush` (with no env vars set at all) uses it automatically. `$SUGARCRUSH_PROVIDER`/`$SUGARCRUSH_BACKEND_CMD`/`$SUGARCRUSH_BACKEND_CMD_STREAM` still take priority over the persisted choice when set, for scripting/CI overrides.

## Using the TUI

The interactive binary boots a **pane shell** (`App`) that hosts the chat
model, so the menu bar, pane strip, session tabs and the chat transcript are
all one candy-core `Model` tree — not two parallel UIs.

### Keys

| Key | Does |
|-----|------|
| `?` (blank input) | Show the in-app keyboard reference. Blank means `trim()`-empty, i.e. empty or made only of the six bytes `trim()` strips — space, tab, newline, carriage return, `NUL` and vertical tab. Not the same as "whitespace-only", in both directions: a line of non-breaking spaces (`U+00A0`), ideographic spaces (`U+3000`) or a form feed is *not* blank, so `?` types a character there, while `NUL` **is** blank without being whitespace. Only two of those six bytes can be typed — space, and `NUL` via `Ctrl`+`Space`. A newline draft is *composed*, with `Alt`+`Enter` (or `Shift`+`Enter` / `Ctrl`+`Enter`, on terminals that report those distinguishably). The other three (tab, carriage return, vertical tab) have no key at all, and no route you can exercise on purpose: sending a message never puts one in your history either, because `Enter` trims the draft before it is sent. They reach the input box exactly one way — **`/rewind` to a checkpoint whose transcript contains a tool result.** Restoring a checkpoint revives every non-`assistant` row as a `user` message with its content unchanged, and a tool row's output is full of tabs; `↑` then recalls that revived message verbatim. That is the whole mechanism, and it is the only one: after a `/rewind` a draft can hold a byte no key emits. Same for the form feed in the non-blank list above — `Ctrl`+`L` types the letter `l`, not `U+000C`. The draft is left untouched behind the overlay. `Esc`/`Enter`/`q` close it, and so does a second `?` (see the next row); `↑`/`↓`, `PgUp`/`PgDn` and the wheel scroll it (and the transcript behind it is left alone) |
| `?` `?` | Type a literal `?`. The second `?` closes the reference **and** puts the character in the input box, which is how a message that starts with `?` gets typed — the box has no cursor movement, so `?` on a blank line would otherwise make one impossible. Works after leading whitespace too: `␣??` leaves `␣?` |
| `/keys` | The same reference, by **name**: typing `/k` surfaces it in the `/` popup, which is where you find it if you do not already know about `?`. (`/help` was a second spelling of this and is now the **slash-command list** instead.) It is *not* an escape hatch for a half-typed draft — the command is matched against the whole trimmed input, so with `why` already in the box, `why/keys` + `Enter` is sent to the model as a prompt. Typing `/keys` onto a draft opens the reference exactly when `?` on that draft would — which is the sense in which it is not a hatch. It is *not* interchangeable with `?` more generally: a draft that **is** the command modulo surrounding whitespace (`␣/keys`, `/keys␣`) opens the reference on `Enter`, where `?` would type a character, and on a blank line `?` opens it while `Enter` sends nothing. Submitting `/keys` also clears the input line and `?` does not. Clear the line and either route works |
| `Enter` | Send |
| `Esc` `Esc` | Cancel the in-flight turn — press **twice** within 0.6s (a single `Esc` is a no-op, which is why the status bar reads `Esc Esc to cancel` while thinking) |
| `Esc` | Close the palette or the session picker |
| `Ctrl+C` | Quit |
| `Ctrl+P` | Command palette (fuzzy, grouped by category, biased by most-recently-used) |
| `Ctrl+O` | Expand/collapse the most recent tool call's output |
| `Ctrl+R` | Session picker (persisted across turns) |
| `Ctrl+A` | Same dispatch as typing `/agents` |
| `Ctrl+W` / `Alt+Backspace` | Delete the previous word |
| `Up` (empty input) | Recall the last message you sent |
| `Page Up` / `Page Down` | Scroll the transcript a screenful |
| `Tab` | Cycle panes |
| `Ctrl+Tab` / `Ctrl+Shift+Tab` | Cycle sessions |
| `F10` | Open the menu bar |
| `y` / `n` / `a` | Answer a permission prompt: once / refuse / ask to allow for the session. **While a prompt is up it owns the keyboard** — nothing reaches the input box — but it only answers to a letter while it is *armed*, and the first key you press that is not an answer disarms it. So typing a slash command at a live prompt now does nothing at all: measured, `/keys`, `/init`, `/agents`, `/branch main`, `/compact` and `/new` are all swallowed whole. `Enter` re-arms (and answers nothing), `Esc` refuses in any state, and the modal says which state it is in. The one thing that still answers on the first keystroke is a message that *begins* with `y` or `n` — those are the answers. And `a` no longer grants on its own: it asks "allow every later call this session?", which one `y` confirms and any other key cancels, so the session-wide grant costs two deliberate keystrokes |

`Ctrl+P`, `Ctrl+O`, `Ctrl+A`, `Ctrl+W` and `Ctrl+C` always belong to the chat
content model — the shell never claims them, in any pane, so hosting chat
inside the shell cannot silently steal a binding. `Ctrl+R` belongs to the chat
too, with one declared exception: while a shell view is itself driving the
keyboard (the agent dashboard, an open skill picker, an open `F10` menu) the
shell keeps it, because the picker it opens is painted by the chat those views
cover and moved by the `↑`/`↓`/`Enter` those views claim. Leave the view and
`Ctrl+R` works as usual.

The table above is a summary of the chat pane; **the reference `?` opens is the
authority**, and it covers the overlays too (palette, session picker, permission
prompt, skill picker, agent view, menu bar, mouse). It is generated from
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
scrolls the transcript, clicking a tool call expands/collapses it, clicking a
session tab or a pane label switches to it, clicking a palette/picker row
selects it, and clicking the menu bar opens a menu. Click-vs-drag is
discriminated so a text-selection drag does not fire the zone underneath it.

### Slash commands

`/agents` (`/agent`) `/bg` (`/background`) `/branch` `/budget` `/clear`
`/compact` `/fork` `/help` `/keys` `/mcp` `/memory` `/model` `/permissions`
`/rename` `/rewind` `/sessions` `/share` `/theme` `/websearch` `/workflow`
`/exit` (`/quit`).

The parenthesised spellings are aliases: they dispatch, but they have no
`CommandRegistry` row of their own, so no surface advertises them.

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

Typing `/` opens a live popup of the matches, which fuzzy-ranks as you type
(`/rwd` finds `/rewind`), **highlights the characters you typed** and shows each
command's **argument hint** (`/rename <name>`) — fitting the whole row to the
terminal rather than letting it run off the edge: the description gives up
columns first, then the hint, and the name (the row's identity) last.

`/help` lists every command the registry advertises, with its argument hint —
that is the list above without the three aliases: `/agents`, `/bg` and `/exit`
appear, `/agent`, `/background` and `/quit` do not.
`tests/Commands/SlashDispatchTest.php` fails if a fourth unadvertised alias
turns up without a reason written next to it. `/model` on its own opens the
same provider picker `Ctrl+P` → **Switch model** opens; `/model <provider>`
switches straight to one, and an unknown name says so in the transcript instead
of failing silently. `/clear` empties the transcript and **keeps** the session —
its id, its name on disk and its checkpoints all survive, so `/rewind` still
reaches the turns it cleared. That is the opposite trade to **New session**,
which mints a fresh id and leaves the conversation where it was.

**New session** and **Open docs** are palette-only actions (`Ctrl+P`) — they
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
the transcript. `/fork` branches the current session.

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
(`Ctrl+O` or a click opens them). Context usage shows as both a token count and
a percentage, and the budget it is measured against is the **live model's own
context window** as its provider reports it (a backend with no model behind it,
such as the offline echo default, falls back to 100,000 estimated tokens). That
budget also drives compaction, per turn and without an idle gate: at 70% a
system-role reminder rides along with the turn, at 85% older exchanges are
summarized first and the rewrite is reported in the transcript, and at 95% the
turn is refused rather than spent on a request the provider would reject. A
refusal is not a dead end — each attempt drops the oldest preserved exchange,
and `/clear` frees the whole context at once.

Beside the context readout, a **spend** readout appears once the provider has
reported something to show — dollars, and the cap if one is set. It is a
separate segment from the context figure on purpose: the context number is a
chars/4 **estimate** and wears a `~`, while the spend is the provider's own
**count** and wears a `$`. They are never summed. A session under a cap that
nothing has been reported for reads `$?` rather than `$0.0000`, because the two
are different claims and the cap is inert in that state.

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
| Anthropic       | `anthropic`   | `x-api-key` + `anthropic-version` auth, but an OpenAI-shaped `chat/completions` body — see below |
| Claude Code CLI | `claude-code` | drives the `claude` binary headless; native cost; JSON schema    |
| SGLang          | `sglang`      | OpenAI-compatible self-hosted endpoints (Guzzle)                 |
| AWS Bedrock     | `bedrock`     | Converse API via `aws/aws-sdk-php`; per-model pricing            |
| GCP Vertex      | `vertex`      | Anthropic-on-Vertex via an injectable predictor seam             |
| Custom          | `custom`      | any OpenAI-compatible HTTP endpoint                              |
| Echo            | —             | `EchoProvider`: offline, echoes the last turn; default + tests   |

The `anthropic` type key is **not** a native Messages API client. `ProviderFactory::createAnthropic()` builds a `CustomProvider` with Anthropic's `x-api-key`/`anthropic-version` headers, but that class POSTs an OpenAI-shaped body to `chat/completions`, and it is constructed with `supportsFunctionCalling: false` — so this type key cannot do tool calling. For a real Anthropic-native path today, use `claude-code` (which drives the `claude` binary) or point `SUGARCRUSH_BACKEND_CMD` at a shell script. Fixing this is tracked as a known gap.

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
produced nothing, so it can neither duplicate a call nor delay a streamed
token.

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
detect that from the id alone.

That default exists because an *absent* `reasoning_effort` is not neutral. On
DeepSeek-V4-Flash, a request without it comes back with `reasoning_content:
null` and the model's thinking written straight into `content` — so the
reasoning ends up in the reply the user reads instead of the collapsible
thinking pane. Sending a level moves it back to `reasoning_content`, which is
where `CompleteResponse::$reasoning` reads from.

The default `sglang` model is `deepseek-ai/DeepSeek-V4-Flash-0731`, and it also
sets `temperature = 1.0` plus `top_p = 0.95` when the request offers tools /
`1.0` when it does not — the model card's own figures for agentic and
non-agentic use. MiniMax-M2.x is unaffected by all of the above: name it as the
`model` and the provider keeps its previous `temperature = 0.7`, sends no
`top_p`, and sends no `reasoning_effort` unless you set one explicitly. A
per-request override lives on `CompleteRequest::$reasoningEffort`.

## The agent loop

`EngineBackend` bridges the chat-shell `Backend` seam to the engine. Each user turn runs a **bounded agentic loop**: call the provider through the `Runtime`, execute any tool calls through the hook gate, feed the results back, and repeat until the model answers without calling tools — or a `maxSteps` ceiling is hit.

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

- **Tools** — `Tools\BuiltIn\*`: `Bash`, `Read`, `Edit`, `Write`, `Glob`, `Grep`, `WebFetch`, `WebSearch` (against `$SUGARCRUSH_SEARCH_ENDPOINT`), `doctor` (a capability probe the model can call to report what this build/deployment actually supports), `Skill` (level 2 of the progressive-disclosure design below), and `Lsp` (definitions/references/hover/symbols/code-actions/diagnostics from a language server). These are **runtime tool names**, the same spelling the launch report and every `allowedTools`/`disabledTools`/`permissionRules` pattern uses; three of them differ from their class file, which is why the list is not a directory listing — `doctor` is `Doctor.php`, `Skill` is `SkillTool.php`, `Lsp` is `LspTool.php`. Eleven classes, and `Bootstrap::tools()` ships all eleven. **Be precise about what "ships" buys for `Lsp`:** it is REACHABLE, not yet USEFUL — there is no settings key for language servers anywhere in `src/`, so every launch today builds it with no servers and every call returns an *error* naming the language it could not ask. That refusal is the design, not an oversight: an empty success would read to the model as "this symbol has no references", which is a fabricated fact about your code. The launcher (a per-language server command, `LspConnection::connect()` + `initialize()`, a `publishDiagnostics` subscriber, a shutdown hook, and the same project-trust gate `.mcp.json` gets — starting a server is code execution) is the next step, and until it lands `src/LSP/` is a documented dormant seam rather than dead code. `Lsp` is classified read-only by the permission gate, so `plan` mode allows it without prompting: every one of its operations is a query, and the mutating half of LSP (rename, formatting, applying a code action's edit) is absent from the tool by construction. `Write` was the odd one out until recently: it was written, tested and referenced from this README, but never listed in that array, so no real run could reach it and `Edit`'s `file_exists()` precondition left `Bash` as the model's only way to create a file. Implement `Tools\Tool` for your own.
- **Hooks** — `Hooks\*`: pre/post-tool-use guards (allow / deny / **modify** the input / **ask** the user). `HookManager::registerBuiltIns()` registers `AuditHook`, `ConfirmRemoveHook` and `ProtectFilesHook`; `BashEscapeDenyHook` is registered separately by `EngineBackend::withWorktreeRoot()`, since it needs the worktree root to decide what counts as an escape. YAML config and external `ScriptHook` supported: a hook script's exit code selects the outcome — `0` allow, `1` deny, `2` hard block, `3` ask (stdout becomes the question), `4` modify (stdout must be a JSON object replacing the tool input, or the call is denied rather than run unmodified). Hook files are read from `~/.sugar-crush/hooks.yaml` and — **only if you have opted that project in** — `<project>/.sugar-crush/hooks.yaml`, after the built-ins and before the permission gate. Both files are **additive**: a hook may not reuse the name of a built-in guard, of the permission gate, or of a hook the other file already declared — a config file may add to the chain, never replace what is in it. Note the flip side of refusing rather than overriding: if a project file you have trusted declares a hook `name:` your own file already uses, `sugarcrush` stops with exit 2 in that directory until one of the two names changes. A hook that rewrites the tool input (exit `4`) has its rewrite **re-judged by the whole chain** before anything runs — so a rewrite to `rm -rf /` is caught by `ConfirmRemoveHook` on the next pass rather than executed. Read that as "a rewrite gets no privilege the original call would not have had", **not** as a safety net: the re-scan is only as wide as the hooks in the chain, and a rewrite to something the built-ins have no opinion about (`curl … | sh`) re-scans clean and runs.

  **A project hook file is code execution, so it is off by default.** A hook entry is a shell command and a `matcher: '.*'` entry runs it on the model's first tool call — so honouring `<project>/.sugar-crush/hooks.yaml` means that `git clone <repo> && cd <repo> && sugarcrush` runs shell **that repository's author wrote**, with no prompt and nothing in the transcript. No permission mode protects you from it (`plan` included): config hooks are registered before the permission gate, and a scan stops at the first refusal, so the payload has already run by the time the gate would have refused. SugarCrush therefore ignores a project hook file unless *your* `~/.sugar-crush/config.json` — a file no repository can write — names that project in `trustedProjectHooks`:

  ```json
  { "trustedProjectHooks": ["/home/you/work/my-repo", "~/src/other-repo"] }
  ```

  Paths are matched by real path, so a symlinked or trailing-slash spelling of a trusted root still matches. The match is **exact, not a subtree**: trusting `~/src` does not trust `~/src/anything` — list each repository you mean, and note that a trusted root's sibling sharing its spelling (`my-repo-evil` beside `my-repo`) is never trusted by accident. An entry must also be **absolute** (or `~/`-rooted): a relative one like `"."` is resolved fresh against the current directory on every launch, exactly as the project root is, so it would always agree and turn a per-path allowlist into "trust every repository I `cd` into". Such an entry is refused and reported rather than honoured. When a project hook file is present and *not* trusted, the launch says so on stderr — once per launch, naming the canonical absolute path to add — rather than dropping it silently. Your own `~/.sugar-crush/hooks.yaml` is never gated: you wrote it, and that premise is enforced rather than assumed — if this process cannot determine which home directory is yours (`$HOME` unset, `$USERPROFILE` unset, and no passwd entry for its uid) the launch **stops** rather than reading a hook chain or a permission policy out of a world-writable fallback directory.
- **Permission modes** — `Permissions\*`: `PermissionGate` evaluates a tool call against one of six `PermissionMode`s (`default`, `accept-edits`, `plan`, `auto`, `dont-ask`, `bypass-permissions`), with a mode-independent rm-rf circuit breaker and a fail-closed `auto` classifier when no `SafetyClassifier` is configured. It reaches the main loop as `PermissionGateHook`, registered *after* the built-ins so a narrow, specific hazard ("Bash path outside the workspace root") reports before the broad policy one ("mode `plan` does not allow Edit"). Set the mode with `$SUGARCRUSH_PERMISSION_MODE` or a `permissionMode` key in `~/.sugar-crush/config.json` (or `settings.json`, which it outranks); add `permissionRules` entries like `{"pattern": "Bash*", "action": "deny"}` for per-pattern overrides. A pattern is `Tool` or `Tool(argument-glob)`, both halves `fnmatch()` — so `Bash(rm -rf *)`, `Read(./.env)`, `Read(./secrets/*)`, `mcp__*__push`. **Argument-scoped patterns matched nothing at all before this release**: the matcher compared the tool name only, so `Deny Bash(rm -rf *)` evaluated to `allow` while the documentation said otherwise. They work now, and two things about them are worth knowing rather than discovering. **Every argument-scoped `Deny` is advisory**, shell and path alike. A shell one survives whitespace runs and a command hidden behind `&&`/`;`/`|`/newline, and does *not* survive `/bin/rm`, `$(echo rm)`, `bash -c '…'` or `find -delete`, because no pattern over shell text can. A path one survives the `./` prefix, `//` runs and `.`/`..` segments — all normalised away on both sides, so `Read(./.env)` also covers `.env`, `.//.env` and `./foo/../.env`, none of which it caught in the first cut of this feature — and a *restrictive* path pattern spelled relatively additionally reads as "at any depth", so it covers `/home/you/proj/.env` too (a permissive one does not, or `Allow Read(.env)` would grant `/etc/.env`). It does *not* survive a symlinked spelling of the same file, and nothing here touches the filesystem: resolving would make the decision depend on the process's cwd and race the tool being gated. So treat any `Tool(...)` deny as a guard rail against the model doing something by *accident*, not as containment. The boundaries that do not depend on a spelling are `plan` mode, which refuses whole tool kinds, and the path jails, which resolve. The mode-independent `rm -rf /` breaker reads like a third and is not one: unswitchable is not unevadable — it reads `arguments['command']` and tokenises it, so `/bin/rm -rf /` is past it. (Its own newline-chain hole *was* real and is fixed here: `echo hi⏎rm -rf /` was **allowed** under `bypass-permissions` while `echo hi && rm -rf /` was denied.) And an argument-scoped rule does **not** refuse a *declaration* (a workflow stage's `tools: [Bash]`), only a real call: one `Deny Bash(rm -rf *)` should not make every stage that declares `Bash` unusable. A pattern the grammar cannot parse is reported on stderr — naming the reason it actually gave, which is either an unterminated `(`, a `)` that never opened, an empty pattern, or a missing tool-name half (`(rm *)`) — and skipped rather than loaded as a rule that would match nothing. **The default is `bypass-permissions`, and that is a stopgap, not the intended end state** — the main loop had no gate at all before this, and every mode that answers Ask failed closed on the engine path with nothing anywhere attaching an approver, so a stricter default would have refused edits on upgrade rather than prompting for them. Half of that is fixed: the one-shot `-p` path now attaches one (see the known gap below), and the interactive path still does not — which is the path a default mode would be judged on. Be clear about what the default costs: with no `permissionRules` configured, `bypass-permissions` is *identical* to having no gate — every destructive `rm` its circuit breaker refuses is already refused, earlier and more broadly, by `ConfirmRemoveHook`. What the default buys is a gate that is reachable and configurable; it starts deciding things the moment you set a mode or a rule. The permissive default goes away once an ASK can reach the TUI from the engine path.

A permission setting that is **present but unusable stops the launch** (exit 2 — see the exit-code table above) instead of falling back to the permissive default: a `~/.sugar-crush/config.json` that is unreadable, unparseable, whose top level is a JSON *list* rather than an object, or that is not a regular file at all (a directory or a dangling symlink of that name); a config directory this process cannot search (so whether a policy is configured there is unknowable); or a `permissionMode` — in either source — that names no real mode. A **hook file** is held to the same standard, for the same reason: an unreadable or unparseable `hooks.yaml`, a top level that is a YAML *list* rather than a mapping, a top-level key that is not `hooks:` (a typo'd `hook:` used to install zero guards and say nothing), a key on a hook *entry* that is not one of `name` / `matcher` / `command` / `description` / `disabled` / `timeout` (`mather:` used to fall back to the `.*` default and run the hook on every call; `enabled: false` was accepted and ignored, and so was `timeout:` back when this format had no such key — it has one now, and it is honoured), an unknown event name, a matcher that is not a valid regular expression, a `disabled` that is not `true`/`false`, a `timeout` that is not a positive *finite* number of seconds (`0`, `-1`, `.inf`, `1e400` and `.nan` are all refused rather than read as “no timeout”, and so is a `timeout:` written with no value after it), a hook with no command, or a name collision all stop the launch rather than quietly leaving the chain a guard short. `disabled: true` keeps that entry out of the chain while still validating everything else about it; a matcher may contain `/` (the delimiter is chosen to avoid whatever the pattern uses). Absence is not an error: a fresh install with no config, no hook file, and a zero-byte config all get the default. Individual malformed `permissionRules` entries are skipped (never coerced to `allow`) and reported on stderr, because the list is item-wise in a way a JSON syntax error is not; so is a `"permissionRules": null` that is *present* rather than absent, since someone who typed the key believes they configured rules. An error message also names the file the bad value came from, `settings.json` or `config.json`, rather than always naming the one the CLI writes.
- **Skills** — `Skills\*`: frontmatter `SKILL.md` files inject prompt context, matched by keyword/path. Discovered from built-ins (`src/Skills/BuiltIn/`), `~/.sugar-crush/skills`, and `<project>/.sugar-crush/skills` (project wins). Ships 12 built-ins spanning language/framework conventions (`php-best-practices`, `laravel-best-practices`, `symfony-best-practices`), workflow (`testing-strategies`, `api-design`, `explore-codebase`, `worktree-workflow`, `mcp-authoring`, `matchups-sync`) and the original four (`security-audit`, `phpunit-master`, `composer-wizard`). `disable-model-invocation`, `user-invocable`, and `context: fork` frontmatter flags are enforced, not decorative — a fork-context skill runs through `AgentWorkerPool` as an isolated sub-agent. Loading is **progressive**: the system prompt carries only each skill's name + description, and the model pulls the full `SKILL.md` body through the `Skill` tool when it decides one is relevant. Path-scoped skills self-announce — the first time `Read`/`Edit`/`Glob` touches a file a skill's `paths:` covers, that skill is surfaced (once per session, via one shared announce-set across the three tools). Those `paths:` globs are `fnmatch()` semantics **without `FNM_PATHNAME`**, so a single `*` crosses `/`, and `**` means zero or more directory levels **at any position, the first included** — which is a behaviour change worth knowing about if you wrote a skill against the older matcher: a leading `**` used not to claim files at the tree root and now does, so the three shipped skills scoped `**/*.php` or `**/*Test.php` (`security-audit`, `php-best-practices`, `phpunit-master`) now fire on a root-level file where they used to stay silent. [`docs/SKILLS.md`](docs/SKILLS.md) has the measured table. Skills authored for other CLIs are imported rather than ignored — `~/.claude/skills`, `<project>/.claude/skills`, `~/.config/opencode/skills` and `<project>/.opencode/skills` are all scanned, and the picker shows a provenance badge for where each one came from (symlinked skill directories are followed, which is how those trees are commonly laid out — but a link is **confined**: one in your own `~/.claude/skills` may point anywhere else in your home, while one in a cloned repository's `<project>/.claude/skills` may not leave that skills directory. That confinement is enforced on the *directory* as well as on each entry in it, and both halves are needed: an entry is judged against the skills directory's real path, so committing `.claude/skills` **itself** as a link used to relocate the boundary rather than trip it — every `SKILL.md` under the target was read, and a skill body is prompt context. The directory is therefore held inside the checkout it came from, which is the one path in the pair a repository cannot have forged; a link that stays inside the checkout (`.claude/skills -> shared/skills`) is still honoured, and a refused tree is dropped without disturbing your own or the built-ins. Be precise about what that buys, though: "inside the checkout" is not the same as "committed", since a checkout also holds untracked and gitignored files — so what is closed is a repo reading files from *outside* the tree you cloned, not every conceivable in-tree misdirection. A refused directory is named on stderr at launch rather than silently skipped. Both directory-level checks — this one and the workflow tier's — are the single predicate in `Support\ContainedPath`, which is also where the difference between them is written down: an entry resolving *onto* its boundary is fine, a directory resolving onto its trust anchor is not. The walk is also depth- and breadth-bounded, so a link to a huge tree cannot cost seconds per launch). Collisions resolve so that **nothing you did not write can re-point a name you already use**: a native skill wins over any imported one, and within an imported tree your own `~/.claude` / `~/.config/opencode` copy wins over a cloned repository's `<project>/.claude` / `<project>/.opencode` copy — the reverse of the project-wins order native skills use, because a project's *foreign* skill arrives with whatever you cloned. Between the two foreign tools, opencode wins over Claude. An imported `SKILL.md` that will not parse is another tool's file and not something you can fix, so it is skipped quietly rather than logged to stderr on every launch; the launch prints **one** line saying how many were skipped, and `SUGARCRUSH_DEBUG_SKILLS=1` lists them (they are also readable from `SkillManager::skipped()` and, for the launch as a whole, `Bootstrap::skillSkips()`).
- **Agents** — `Agents\*`: 6 sub-agent presets (coder/reviewer/debugger/architect/tester/devops) with their own model, tools, skills, and a streaming lifecycle, dispatched through `AgentWorkerPool` (`pcntl_fork`-based, with a synchronous fallback + warning when `pcntl` is unavailable).
- **Teams & worktrees** — `Agents\{Team,TeamManager,Teammate,TaskList,Mailbox}`: a lead agent spawns a capped team of teammates that atomically claim `TaskList` tasks (SQLite `flock`-backed, contention-tested) and exchange append-only JSON-lines mailbox messages. `Agents\{WorktreeConfig,WorktreeManager,PathJail}` give each teammate an isolated git worktree (`.worktreeinclude`-aware, swept for staleness) sandboxed by a path jail.
- **Workflows** — `Workflows\*`: `WorkflowBuilder`/`WorkflowRegistry`/`WorkflowEngine` run multi-stage agent pipelines — sequential `stage()`, fan-out `parallel()`, chained `pipeline()`, and task-then-verifier `withVerification()` — defined as PHP DSL files or YAML (`WorkflowRegistry::loadYaml()`). SIGINT/SIGTERM during `run()` captures a real pause file for later resumption at stage granularity. Discovered from `~/.sugar-crush/workflows` and `<project>/.sugar-crush/workflows` (project wins), and driven from the chat with `/workflow run|pause|resume|status|list`. The two tiers are **not** equally capable: a `.php` workflow is loaded by `require`ing it, so only the user tier honours one — a `.php` file in a project's directory is neither listed nor loaded, because a workflow you cloned should not be able to run its own code the moment you name it. Symlinks in a project's workflow directory are **confined** to it: a committed `deploy.yaml -> ~/.ssh/id_rsa` is neither listed nor loaded, so a repo cannot ship a link that reads your files — not even into an error message (a rejected file used to be reported with the YAML parser's message, which quotes the line it choked on). That confinement is enforced on the *directory* as well as on each entry in it, and the two are separate checks for a reason worth knowing: an entry is judged against the workflows directory's real path, which is an answer that moves with the directory, so committing `.sugar-crush/workflows` **itself** as a link used to relocate the boundary rather than trip it — every `*.yaml` basename in the target was listed and every one that parsed was loaded. The directory is therefore held inside the checkout it came from, which is the one path in the pair a repository cannot have forged. A link that stays inside the checkout (`.sugar-crush/workflows -> tools/workflows`) is still honoured: that is repo content pointing at repo content, the same trust as a committed `.yaml`. A link inside your own `~/.sugar-crush/workflows` is still followed; that directory is yours. A *dangling* link is refused rather than granted — it names no workflows, and a committed link to a path that does not exist yet is a request to read whatever appears there later — while a directory you simply have not created is still named in the "not found" message. And note the boundary is the checkout, not the workflows directory, for the directory-level check, which has a residual worth stating: a checkout also holds untracked, gitignored, developer-local files, so a repo committing `.sugar-crush/workflows -> <some other directory in the checkout>` can still disclose that directory's `*.yaml` basenames and descriptions. What the check refuses is the version of that worth having — a link resolving **onto the checkout root**, which is where local files like `local-secrets.yaml` actually sit and which `-> ..` reached in one committed line. Reduction, not elimination. Whichever tier refuses a directory, the launch says so on stderr, naming the path and where it resolved to. The same boundary and the same predicate (`Support\ContainedPath`) hold a cloned repository's `.claude` / `.opencode` / `.sugar-crush` **skills** directories — see the Skills bullet above; the two tiers ran separate copies of the idiom until the skills one turned out to be missing the directory-level half entirely. A project's `.yaml` workflow is declarative and does run: its tasks are dispatched as sub-agents carrying the launch's `PermissionGate`, and a stage that **declares** a tool the session's permission mode denies (`tools: [Bash]` under `dont-ask`, say) is refused before the workflow's *first* stage is dispatched, not when that stage is reached. Be precise about the limit of that, though, because "denied tools are refused" reads as more than it is. Which modes can refuse a *declaration* is a per-mode answer: `dont-ask` refuses every non-read-only tool; `plan` refuses `Edit`/`Write`/`mcp__*` but **not** `Bash`, because what makes a Bash call a write under Plan is a redirection in its arguments and a declaration has none; `auto` refuses **nothing** through its mode logic, since its judgement is `SafetyClassifier`'s and the classifier reads the command out of the arguments too — under `auto` only an explicit `Deny` rule refuses a declaration. `default`/`accept-edits` *ask*, and an `Ask` is deliberately not a refusal (settling one needs the blocking prompt, which the engine has no channel to). Nor is the declared list a capability *boundary*: a parallel stage's agents are all handed the first task's `tools`, so the list is a request that gets permission-checked, not a sandbox. And per-*call* gating — a gate deciding one tool call at the moment a model asks for it — does not happen on the workflow path at all, because nothing there issues a tool call yet. `ProcessExecutor`'s worker is still the P1.S5 simulation, so a workflow stage makes no provider request; what the gate protects today is the declared capability, not each use of it. (`/workflow run` no longer blocks the TUI while it runs, though its stages still do not reach a live model — both under *Limitations* below.) See [`examples/workflows/lint-then-fix.yaml`](examples/workflows/lint-then-fix.yaml) for a runnable YAML example and [`workflows/deep-research.php`](workflows/deep-research.php) for the PHP DSL form.
- **MCP** — `MCP\*`: multi-server client (stdio + HTTP, `.mcp.json`, `${VAR}` interpolation) and stdio/HTTP servers to host your own tools. Per-agent-preset `mcpServers` allowlists are enforced by `McpClient` against `McpRouter`, not just decorative config. A configured server's tools reach the model as ordinary tools named `mcp__<server>__<tool>` (`Tools\McpToolBridge`), on the same PreToolUse chain `Bash` rides; a call is addressed to the server the bridge belongs to, so two servers each exposing `search` do not collide. The chain is shared, but the *decision* is not always the same one: measured across all six permission modes, an `mcp__*` name and `Bash` agree in five and diverge under `plan`, where `Bash` is allowed for exploration and every `mcp__*` name is denied as a write tool — the conservative direction, since a server-side tool's effects are unknowable from here. `dont-ask` denies every MCP call outright. Note also that the wire name is the *sanitised* one, so a permission rule for the `.mcp.json` key `github.com/foo` must be written `mcp__github_com_foo__*`; the permission prompt shows you the sanitised name at the moment it asks.

  **A project `.mcp.json` is code execution too, so it is off by default — same gate, separate key.** Starting an MCP server means `proc_open()`ing a program the *repository* chose, at launch, before any tool call and in **every** permission mode including `plan` — the permission gate sees tool calls, and this happens earlier. It is not even conditional on the server working: a bogus entry's command runs, the handshake fails, and the server is discarded. So `<project>/.mcp.json` is honoured only when *your* `~/.sugar-crush/config.json` names that project in `trustedProjectMcp`:

  ```json
  { "trustedProjectMcp": ["/home/you/work/my-repo"] }
  ```

  Everything said above about `trustedProjectHooks` — real-path matching, exact roots rather than subtrees, absolute-or-`~/` entries only, the list frozen for the process — holds here identically, because it is the same parser. It is a **separate** key on purpose: trusting a repo's `hooks.yaml` is not the same decision as letting it start long-lived server processes, and reusing the hooks list would have widened an existing grant on upgrade rather than at the user's request. An untrusted `.mcp.json` is reported on stderr, naming the file, the reason and the key to add, rather than dropped silently — but be clear about the limit of that, because it covers refusals and not breakage: a *trusted* config that is malformed mostly degrades in silence. Of the five shapes a broken `.mcp.json` takes, only an unknown server `type` says anything; a `command` that is misspelled or not installed (the common case), invalid JSON, and valid JSON under the wrong top-level key all cost you every tool on that server with nothing printed anywhere. That is a `McpClient` defect — it swallows a failing `start()` and treats unparseable JSON as an empty config — and it is on the hardening backlog rather than fixed here. A server's handshake (`initialize` + `tools/list`) is bounded by a 60s wall clock — generous because a first-run `npx` server fetches a package tree before it answers — overridable per server with `"startTimeout": <seconds>`; a `tools/call` is deliberately **not** bounded, since a tool call is somebody else's work and may legitimately run for minutes.
- **Sessions** — `Session\SessionStore`: SQLite (WAL) persistence of sessions/messages/tool-calls with FK-enforced cascade. **Retention is opt-in and off by default**: set `$SUGARCRUSH_SESSION_RETENTION_DAYS` to a positive number of days and each launch drops sessions untouched for that long, reporting on stderr exactly what it removed. **A session you have named is never pruned, whatever its age** — a name is the signal you meant to keep it — and neither is the session the launch is about to resume. `Session\EnhancedSessionStore` adds the per-turn `/rewind` checkpoints on top; message bodies are content-addressed and stored once each, so the conversation itself costs storage proportional to its length rather than to its length squared (the per-checkpoint list of message references is still proportional to the length).
- **Tokens & export** — `Util\TokenTracker` (token + cost accumulation, fed one entry per settled turn from the provider-counted figures on `Usage`, and read by `/budget` and the status bar's spend readout) and `Util\Exporter` (Markdown / JSON / text transcripts).
- **Messages** — typed `Messages\{System,User,Assistant,ToolResult}Message`; `UserMessage` carries file/image attachments; `AssistantMessage` carries tool calls + reasoning.
- **Context files** — `CLAUDE.md`/`AGENTS.md` at the project root are loaded into the system prompt, with `@import` expansion (cycle- and traversal-guarded, and de-duplicated so an imported doc is not injected twice). `Forced` instructions come from user config. An `EnvironmentBlock` (cwd, platform, git state, date) is prepended so the model is not guessing at its surroundings.
- **Permission prompts** — the blocking request/reply flow is wired end to end: `Chat` runs the whole batch of a turn's tool calls through the `PreToolUse` chain *before* forking any of them, and a `HookResult::ask()` suspends the turn on a `PermissionRequestMsg` rendered as a Veil modal over the transcript. `y`/`n`/`a` settles the paused call rather than being advisory, and `a` — once its confirm is answered with a second `y` — records a session-scoped grant so that tool stops asking. The prompt is *armed* when it goes up and any non-answer keystroke disarms it (`Enter` re-arms), so an ordinary slash command typed at a live prompt is swallowed instead of answering it. Because the shipped default mode is `bypass-permissions`, you will not see a prompt until you select a mode that asks (`default`, `accept-edits`, `auto`) or register a hook that returns `ask()`. Note that this is `Chat`'s own tool path. On the **engine** path an ASK still fails closed *in the TUI*, so an asking mode refuses those calls there rather than prompting — see the known gap below for why. The one-shot `-p` path is different: it attaches a console approver, prompts on stderr at a terminal, and refuses with a reason when there is no terminal.

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

- **`toolCallParser` reaches only one of the three providers.** It is closed for `SglangProvider`, which now consults the selected parser on the streaming path as well as the batch one. `CustomProvider` and `OpenAIProvider` accept no `toolCallParser` argument at all and carry their own hardcoded `tool_calls[]` walks — `OpenAIProvider::parseChunk()` returns `toolCalls: null` unconditionally — so on those two a deployment launched without `--tool-call-parser` still loses every tool call, with no setting that would recover it.
- **An ASK decision on the engine path is answered headless, and still fails closed in the TUI.** The blocking modal works for `Chat`'s own tool calls. On the engine path it used to fail closed everywhere, for a simpler reason than the plumbing suggests: **nothing anywhere attached an approver** — `EngineBackend::withPermissionApprover()` had no caller in `src/` or `bin/` at all — so every ASK settled as "permission required and no approver is attached to this run".

  The **one-shot `-p` path** now attaches one. `NonInteractive` builds its backend with `HeadlessPermissionPrompt`, which puts the question on **stderr** (never stdout — `--output-format json` promises exactly one JSON object there), reads the answer from stdin, and grants only on a literal `y`/`yes`. With no terminal on stdin it does not read at all: it refuses, and says which tool, which mode, and what to change (`--permission-mode`, or a `permissionRules` entry). A CI run that would have blocked forever on a pipe nobody types into gets a clean refusal instead.

  The **TUI path is still closed**, and attaching the same closure there would not open it. `Chat`'s prompt is a `Deferred` settled by a later `Msg`, not a function that returns a verdict; and `completeAsync()` runs the turn in a `pcntl_fork()`ed child whose only channel back to the parent is a one-way frame stream, so an approver in there cannot put a question on screen at all until that socket becomes request/response. `Bootstrap::backend()`/`backendFor()` therefore leave the approver off unless a caller asks for it, so a blocking `fgets(STDIN)` can never end up competing with the render loop for keystrokes. Until that lands, an interactive session under a mode that answers Ask refuses those calls instead of prompting — which is why the shipped default mode is still `bypass-permissions` rather than `default`.
- **The `anthropic` provider type key is OpenAI-shaped.** It authenticates as Anthropic but posts to `chat/completions` with `supportsFunctionCalling: false`, so it cannot call tools. Use `claude-code` or `SUGARCRUSH_BACKEND_CMD` for a native Anthropic path.
- **Five shell commands are still inert**: `GroupInputCmd`, `CancelAgentCmd`, `ResumeAgentCmd`, `StopAllAgentsCmd`, `QuitAgentViewCmd`. The first has no counterpart in the live app; the agent four would need to reach into a worker pool the shell does not hold. Their pane/selection half *is* applied — only the action half is missing.
- **Workflow resume granularity is per whole stage.** An interrupted *parallel* sub-stage cannot be resumed with partial credit.
- **A workflow stage does not reach a live model.** `AgentWorkerPool`'s default executor is `ProcessExecutor`, whose worker script is still the P1.S5 simulation: it echoes the task back as `[name] Task finished: …` without making a provider request. So `/workflow run` genuinely exercises loading, stage sequencing, interpolation, fan-out, pausing and resumption — and genuinely does not do the agents' work. Inject your own `ExecutorInterface` to change that.
- **`/workflow run` keeps the TUI alive, with two limits worth knowing.** It used to freeze it outright: `Chat::update()` called `WorkflowEngine::run()` synchronously on the ReactPHP loop, so a multi-stage workflow meant no repaint, no keystrokes and no spinner until the last stage. It no longer does. `Chat::workflowRun()` hands the run to a `\Fiber` that a periodic timer on the loop steps, suspending at `AgentWorkerPool::idle()` — the one point where the parent is idle while forked workers run — so the spinner turns, keystrokes land, and the live-agent split pane paints tiles while the workflow is still going. (The `stream_select` this bullet used to blame is in the CHILD, and never was the obstacle. And do not "fix" this with the fork-plus-socket pattern `EngineBackend::completeAsync()` uses, which this bullet used to recommend: `AgentManager::liveOutputs()` reads an object graph in the PARENT, so forking the workflow would put every sub-agent somewhere the renderer cannot see and repaint the pane promptly and blank. A fiber suspends the whole call stack in-process, which is why it is the right shape here and `completeAsync()`'s is not.) There was never an issue #79 for any of this — detain/sugarcraft #79 is a merged CandyMetrics pull request.
  - **Escape releases the turn; it does not stop the run.** Double-Escape clears `inFlight` and the report still lands when the workflow finishes. Cancelling mid-run means threading a `CancellationToken` down to `AgentWorkerPool::cancelAll()`, which is not done. A second `/workflow run` is refused while one is live rather than started alongside it.
  - **Only forked workers publish live output.** The pane is fed by `AgentWorkerPool::pumpProgress()`, which mirrors what a forked child writes to its progress file. The three paths that do NOT fork — an injected `ExecutorInterface` (see the bullet above), a build without `pcntl`, and a failed `fork()` — run `execute()` synchronously in the parent, which blocks the loop for its duration: no progress, and the fiber cannot help because nothing yields. Injecting your own executor to reach a real model therefore costs you the live pane.
- **`pcntl` is required for real parallelism.** Without it `AgentWorkerPool` falls back to sequential execution and logs a one-time visible warning rather than pretending to fan out.
- **Providers are unit-tested against mocked transports.** No test in this suite makes a live API call, so wire-format drift at a real endpoint is caught by the `doctor` tool (model-invocable; there is no `/doctor` slash command) and by using it, not by CI.
- **The `doctor` tool reports capabilities, it does not repair them.**

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

**7,276 tests / 76,239 assertions, 0 failures, 1 skipped** — the whole of
`sugar-crush/tests/` (that suite only, not the monorepo) in one
`vendor/bin/phpunit` run on PHP 8.3.6, 2m38s. Measured 2026-08-19; the figure
that stood here before, 6,424/51,767 in 1m52s, was behind the suite by 852 tests
and 24,472 assertions. The
skip is `MCP\McpClientTest::testLoadConfigReturnsEmptyArrayWhenFileGetContentsFails`,
which `markTestSkipped`s itself with "would require mocking built-in functions"
— reaching `loadConfig()`'s `file_get_contents` failure branch needs a
`.mcp.json` that `file_exists()` but cannot be read. It is the only skip, and
`failOnWarning="true"` means the run is also warning-free.

**That figure is a point-in-time measurement, and it is stale by
construction** — any commit that adds a test invalidates it, and review found
this one already behind the very commit that wrote it. It is
recorded because reproducing a number is how you check it, not because it is
maintained: **the command above is the authority, the figure is not.** An earlier
revision of this paragraph promised the opposite — that the count "is re-measured
whenever a change adds tests rather than left to age" — which is a guarantee no
README can keep and which read as freshness for three rounds while the number
drifted. For scale rather than for accuracy: the first figure to stand here,
4,337/12,587, understated the suite by over 2,000 tests.

Coverage spans every subsystem: typed messages + attachments, all 11 built-in
tools (the whole of `src/Tools/BuiltIn/`, which is exactly the built-in half of
the array `Bootstrap::tools()` hands the engine — the array itself is longer
whenever a trusted project's MCP servers advertise anything), all 7
`Providers\ProviderFactory` type
keys (unit-tested with mocked transports — no live calls), the hook framework, permission-mode gating (incl. `pcntl_fork` concurrency stress tests for atomic task claiming), skills discovery + flag enforcement, sub-agents/teams/worktrees, workflow execution (sequential/parallel/pipeline/verification, PHP + YAML loading), the MCP client/servers (incl. per-agent routing enforcement), the SQLite store, token tracking, export, the TUI components, the `Runtime` orchestration (streaming accumulation, tool-result correlation, MODIFY hooks), the shell-out `CommandBackend` / `StreamingCommandBackend`, and the `EngineBackend` agentic loop (incl. the `maxSteps` guard).

A dedicated `tests/Integration/` tier asserts **reachability** rather than behaviour: that the session store, session tabs, background sessions, the skills subsystem, mouse mode, the environment block and root context-file loading are actually reached from `bin/sugarcrush` → `Bootstrap::app()`, not merely implemented somewhere in `src/`. That tier exists because the audit recorded in the monorepo root's `crush_code_update.md` found well-tested subsystems that no real run could ever touch — the `Write` tool being the most recent: a full suite of its own, and one missing line in `Bootstrap::tools()`. The tier now also pins the whole built-in tool set by count and by name, since an omission from a literal array is not something a per-tool test can see.

See [`CHANGELOG.md`](CHANGELOG.md) for how the suite got here.
