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

`SUGARCRUSH_PROVIDER` accepts `openai`, `anthropic`, `claude-code`, `sglang`, `bedrock`, `vertex`, or `custom`. Each reads its own credentials from the environment (e.g. `ANTHROPIC_API_KEY`, AWS ambient creds for Bedrock, `GOOGLE_APPLICATION_CREDENTIALS` for Vertex). When a real provider is active, the binary wires the built-in coding tools (Bash/Read/Edit/Write/Glob/Grep/WebFetch/WebSearch/Doctor/Skill) and the safety hooks automatically.

Every environment variable SugarCrush reads is documented in [`docs/ENVIRONMENT.md`](docs/ENVIRONMENT.md).

### Non-interactive (one-shot) mode

`bin/sugarcrush` parses `argv` *before* it constructs a `Program`, so the
scriptable paths never attach to the TTY or enter the alt-screen:

```bash
sugarcrush -p "explain the Width helper"        # one prompt, print, exit
sugarcrush run "explain the Width helper"       # same thing
sugarcrush -p "audit this" --output-format json # machine-readable envelope
sugarcrush --output-format json run "audit this" # `run` works after flags too
sugarcrush --root /path/to/project              # set the project root explicitly
sugarcrush --help                               # prints and exits (never opens the TUI)
sugarcrush --version                            # prints the installed version and exits
sugarcrush -- --not-a-flag                      # `--` ends options; everything after is positional
```

`--root` also accepts the first positional argument that looks like a path, so
`sugarcrush ../other-project` works. It is what the Bash/Read/Edit/Glob tools
are jailed to and where `CLAUDE.md`/`AGENTS.md` and `.sugar-crush/skills` are
looked for.

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

| exit | meaning |
| --- | --- |
| `0` | the prompt ran and produced an answer |
| `1` | ran and failed: the backend threw (unreachable host, rejected key, model error), or the answer could not be encoded in the requested format — retrying may help |
| `2` | usage/configuration error, nothing was attempted: no prompt given, unrecognized flag, `--root` naming no directory, a missing `vendor/autoload.php`, a **permission policy that is present but unusable** (see [Permission modes](#capabilities) — an unreadable/unreachable/unparseable `~/.sugar-crush/config.json`, or a `permissionMode` naming no real mode), or a provider (from `$SUGARCRUSH_PROVIDER` **or** the persisted Ctrl+P choice) that cannot be constructed — retrying will not help |

`2` covers "no prompt given" (`sugarcrush -p`, `sugarcrush run`) deliberately:
the invocation is malformed, no backend is ever selected, and a CI gate that
retries on `1` would otherwise retry it forever.

With `--output-format json`, stdout is always exactly one JSON object: either
`{"result": "..."}` or `{"result": null, "error": {"type": "usage" |
"provider_configuration" | "backend" | "encoding", "message": "...",
"provider": "..."}}` (`provider` present only when a selection is to blame), so
a `| jq` consumer never sees an empty pipe. That holds for the flag and
`--root` usage errors too, which `bin/sugarcrush` catches before the one-shot
path is entered, and for a reply or an error message carrying bytes that are
not valid UTF-8 (they are substituted, not dropped along with the whole
document). `error.type` is not the exit code renamed — `usage` and
`provider_configuration` are both `2`, `backend` and `encoding` are both `1` —
it is how a consumer that kept the code tells apart the two kinds of each.

The single exception is a checkout with no `vendor/autoload.php`: that exits
`2` with an empty stdout, because the class that owns the JSON document shape
is precisely the one that could not be loaded, and hand-rolling a second copy
of the shape in `bin/sugarcrush` to cover it would be the drift that having one
definition prevents. Run `composer install`.

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

### Choosing a backend without editing anything

Three ways to get off the offline `EchoProvider`, from quickest to most permanent:

1. **One-off, this run only:** `SUGARCRUSH_PROVIDER=dev-sglang ./bin/sugarcrush` — `dev-sglang` is the project's own dev/test SGLang endpoint (declared in `.sugar-crush/config.dev.json`, checked into the repo), useful for trying a real (if smaller) model with zero API keys.
2. **From inside the TUI:** press **Ctrl+P**, choose **Switch model**, pick any provider from the list (built-in types plus every name declared in `.sugar-crush/config.dev.json`, e.g. `dev-sglang`) — switches immediately, no restart. **Switch theme** works the same way for color themes.
3. **Persisted across restarts:** either of the above choices made via the palette is written to `~/.sugar-crush/config.json` and read back on the next launch — so picking `dev-sglang` once via Ctrl+P means every future `./bin/sugarcrush` (with no env vars set at all) uses it automatically. `$SUGARCRUSH_PROVIDER`/`$SUGARCRUSH_BACKEND_CMD` still take priority over the persisted choice when set, for scripting/CI overrides.

## Using the TUI

The interactive binary boots a **pane shell** (`App`) that hosts the chat
model, so the menu bar, pane strip, session tabs and the chat transcript are
all one candy-core `Model` tree — not two parallel UIs.

### Keys

| Key | Does |
|-----|------|
| `?` (blank input) | Show the in-app keyboard reference. Blank means `trim()`-empty, i.e. empty or made only of the six bytes `trim()` strips — space, tab, newline, carriage return, `NUL` and vertical tab. Not the same as "whitespace-only", in both directions: a line of non-breaking spaces (`U+00A0`), ideographic spaces (`U+3000`) or a form feed is *not* blank, so `?` types a character there, while `NUL` **is** blank without being whitespace. Only two of those six bytes can be typed — space, and `NUL` via `Ctrl`+`Space`. A newline draft is *composed*, with `Alt`+`Enter` (or `Shift`+`Enter` / `Ctrl`+`Enter`, on terminals that report those distinguishably). The other three (tab, carriage return, vertical tab) have no key at all, and no route you can exercise on purpose: sending a message never puts one in your history either, because `Enter` trims the draft before it is sent. They reach the input box exactly one way — **`/rewind` to a checkpoint whose transcript contains a tool result.** Restoring a checkpoint revives every non-`assistant` row as a `user` message with its content unchanged, and a tool row's output is full of tabs; `↑` then recalls that revived message verbatim. That is the whole mechanism, and it is the only one: after a `/rewind` a draft can hold a byte no key emits. Same for the form feed in the non-blank list above — `Ctrl`+`L` types the letter `l`, not `U+000C`. The draft is left untouched behind the overlay. `Esc`/`Enter`/`q` close it, and so does a second `?` (see the next row); `↑`/`↓`, `PgUp`/`PgDn` and the wheel scroll it (and the transcript behind it is left alone) |
| `?` `?` | Type a literal `?`. The second `?` closes the reference **and** puts the character in the input box, which is how a message that starts with `?` gets typed — the box has no cursor movement, so `?` on a blank line would otherwise make one impossible. Works after leading whitespace too: `␣??` leaves `␣?` |
| `/keys`, `/help` | The same reference, by **name**: typing `/k` or `/h` surfaces it in the `/` popup, which is where you find it if you do not already know about `?`. It is *not* an escape hatch for a half-typed draft — the command is matched against the whole trimmed input, so with `why` already in the box, `why/keys` + `Enter` is sent to the model as a prompt. Typing `/keys` onto a draft opens the reference exactly when `?` on that draft would — which is the sense in which it is not a hatch. It is *not* interchangeable with `?` more generally: a draft that **is** the command modulo surrounding whitespace (`␣/keys`, `/keys␣`, `␣␣/help␣␣`) opens the reference on `Enter`, where `?` would type a character, and on a blank line `?` opens it while `Enter` sends nothing. Submitting `/keys` also clears the input line and `?` does not. Clear the line and either route works |
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

`/agents` `/agent` `/bg` `/background` `/fork` `/branch` `/compact` `/mcp`
`/memory` `/rename` `/rewind` `/sessions` `/share` `/theme` `/websearch`
`/workflow` `/exit` (`/quit`) — plus any **file-based custom command** found on
disk.
Typing `/` opens a live popup of the matches.

**New session**, **Switch model** and **Open docs** are palette-only actions
(`Ctrl+P`) — they have no slash spelling, so `CommandRegistry` keeps them out
of the `/` popup.

`/bg` really does run the work: it dispatches onto a `BackgroundSupervisor`
that `bin/sugarcrush` constructs per launch, and the result comes back into
the transcript. `/fork` branches the current session.

### What you see while a turn runs

Tool calls stream into the transcript **as they happen** — the forked child
emits lifecycle events rather than buffering until the turn ends — each with a
human-readable description and the command it actually ran, then a
running→done transition. `Edit`/`Write` results render a real unified diff. A
no-op edit reports as a no-op instead of success. Denied and interrupted calls
get their own visual state. Tool results that carry images are labelled and
rendered inline via candy-mosaic. Successful tool bodies are hidden by default
(`Ctrl+O` or a click opens them). Context usage shows as both a token count and
a percentage.

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

The `sglang` type accepts an optional `toolCallParser` key: `'openai'` (the
default — read the server's parsed `tool_calls[]` array) or
`'minimax-xml-fallback'` (same, but when the array is absent, recover MiniMax's
raw `<tool_call>` XML out of the message content). Switch it only if your SGLang
deployment was launched *without* `--tool-call-parser`, which leaves the model's
tool-call XML unparsed in the content. Note this currently applies to the batch
`complete()` path only — the streaming path reassembles tool calls itself and
does not yet consult the setting.

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

- **Tools** — `Tools\BuiltIn\*`: `Bash`, `Read`, `Edit`, `Write`, `Glob`, `Grep`, `WebFetch`, `WebSearch` (against `$SUGARCRUSH_SEARCH_ENDPOINT`), `Doctor` (a capability probe the model can call to report what this build/deployment actually supports), and `Skill` (level 2 of the progressive-disclosure design below). Ten classes, and `Bootstrap::tools()` ships all ten — `Write` was the odd one out until recently: it was written, tested and referenced from this README, but never listed in that array, so no real run could reach it and `Edit`'s `file_exists()` precondition left `Bash` as the model's only way to create a file. Implement `Tools\Tool` for your own.
- **Hooks** — `Hooks\*`: pre/post-tool-use guards (allow / deny / **modify** the input / **ask** the user). `HookManager::registerBuiltIns()` registers `AuditHook`, `ConfirmRemoveHook` and `ProtectFilesHook`; `BashEscapeDenyHook` is registered separately by `EngineBackend::withWorktreeRoot()`, since it needs the worktree root to decide what counts as an escape. YAML config and external `ScriptHook` supported: a hook script's exit code selects the outcome — `0` allow, `1` deny, `2` hard block, `3` ask (stdout becomes the question), `4` modify (stdout must be a JSON object replacing the tool input, or the call is denied rather than run unmodified). Hook files are read from `~/.sugar-crush/hooks.yaml` and — **only if you have opted that project in** — `<project>/.sugar-crush/hooks.yaml`, after the built-ins and before the permission gate. Both files are **additive**: a hook may not reuse the name of a built-in guard, of the permission gate, or of a hook the other file already declared — a config file may add to the chain, never replace what is in it. Note the flip side of refusing rather than overriding: if a project file you have trusted declares a hook `name:` your own file already uses, `sugarcrush` stops with exit 2 in that directory until one of the two names changes. A hook that rewrites the tool input (exit `4`) has its rewrite **re-judged by the whole chain** before anything runs — so a rewrite to `rm -rf /` is caught by `ConfirmRemoveHook` on the next pass rather than executed. Read that as "a rewrite gets no privilege the original call would not have had", **not** as a safety net: the re-scan is only as wide as the hooks in the chain, and a rewrite to something the built-ins have no opinion about (`curl … | sh`) re-scans clean and runs.

  **A project hook file is code execution, so it is off by default.** A hook entry is a shell command and a `matcher: '.*'` entry runs it on the model's first tool call — so honouring `<project>/.sugar-crush/hooks.yaml` means that `git clone <repo> && cd <repo> && sugarcrush` runs shell **that repository's author wrote**, with no prompt and nothing in the transcript. No permission mode protects you from it (`plan` included): config hooks are registered before the permission gate, and a scan stops at the first refusal, so the payload has already run by the time the gate would have refused. SugarCrush therefore ignores a project hook file unless *your* `~/.sugar-crush/config.json` — a file no repository can write — names that project in `trustedProjectHooks`:

  ```json
  { "trustedProjectHooks": ["/home/you/work/my-repo", "~/src/other-repo"] }
  ```

  Paths are matched by real path, so a symlinked or trailing-slash spelling of a trusted root still matches. The match is **exact, not a subtree**: trusting `~/src` does not trust `~/src/anything` — list each repository you mean, and note that a trusted root's sibling sharing its spelling (`my-repo-evil` beside `my-repo`) is never trusted by accident. An entry must also be **absolute** (or `~/`-rooted): a relative one like `"."` is resolved fresh against the current directory on every launch, exactly as the project root is, so it would always agree and turn a per-path allowlist into "trust every repository I `cd` into". Such an entry is refused and reported rather than honoured. When a project hook file is present and *not* trusted, the launch says so on stderr — once per launch, naming the canonical absolute path to add — rather than dropping it silently. Your own `~/.sugar-crush/hooks.yaml` is never gated: you wrote it, and that premise is enforced rather than assumed — if this process cannot determine which home directory is yours (`$HOME` unset, `$USERPROFILE` unset, and no passwd entry for its uid) the launch **stops** rather than reading a hook chain or a permission policy out of a world-writable fallback directory.
- **Permission modes** — `Permissions\*`: `PermissionGate` evaluates a tool call against one of six `PermissionMode`s (`default`, `accept-edits`, `plan`, `auto`, `dont-ask`, `bypass-permissions`), with a mode-independent rm-rf circuit breaker and a fail-closed `auto` classifier when no `SafetyClassifier` is configured. It reaches the main loop as `PermissionGateHook`, registered *after* the built-ins so a narrow, specific hazard ("Bash path outside the workspace root") reports before the broad policy one ("mode `plan` does not allow Edit"). Set the mode with `$SUGARCRUSH_PERMISSION_MODE` or a `permissionMode` key in `~/.sugar-crush/config.json`; add `permissionRules` entries like `{"pattern": "Bash*", "action": "deny"}` for per-pattern overrides. **The default is `bypass-permissions`, and that is a stopgap, not the intended end state** — the main loop had no gate at all before this, and every mode that answers Ask fails closed on the engine path (nothing attaches an approver yet), so a stricter default would have refused edits on upgrade rather than prompting for them. Be clear about what the default costs: with no `permissionRules` configured, `bypass-permissions` is *identical* to having no gate — every destructive `rm` its circuit breaker refuses is already refused, earlier and more broadly, by `ConfirmRemoveHook`. What the default buys is a gate that is reachable and configurable; it starts deciding things the moment you set a mode or a rule. The permissive default goes away once an ASK can reach the TUI from the engine path.

A permission setting that is **present but unusable stops the launch** (exit 2 — see the exit-code table above) instead of falling back to the permissive default: a `~/.sugar-crush/config.json` that is unreadable, unparseable, whose top level is a JSON *list* rather than an object, or that is not a regular file at all (a directory or a dangling symlink of that name); a config directory this process cannot search (so whether a policy is configured there is unknowable); or a `permissionMode` — in either source — that names no real mode. A **hook file** is held to the same standard, for the same reason: an unreadable or unparseable `hooks.yaml`, a top level that is a YAML *list* rather than a mapping, a top-level key that is not `hooks:` (a typo'd `hook:` used to install zero guards and say nothing), a key on a hook *entry* that is not one of `name` / `matcher` / `command` / `description` / `disabled` (`mather:` used to fall back to the `.*` default and run the hook on every call; `enabled: false` and `timeout:` were accepted and ignored), an unknown event name, a matcher that is not a valid regular expression, a `disabled` that is not `true`/`false`, a hook with no command, or a name collision all stop the launch rather than quietly leaving the chain a guard short. `disabled: true` keeps that entry out of the chain while still validating everything else about it; a matcher may contain `/` (the delimiter is chosen to avoid whatever the pattern uses). Absence is not an error: a fresh install with no config, no hook file, and a zero-byte config all get the default. Individual malformed `permissionRules` entries are skipped (never coerced to `allow`) and reported on stderr, because the list is item-wise in a way a JSON syntax error is not.
- **Skills** — `Skills\*`: frontmatter `SKILL.md` files inject prompt context, matched by keyword/path. Discovered from built-ins (`src/Skills/BuiltIn/`), `~/.sugar-crush/skills`, and `<project>/.sugar-crush/skills` (project wins). Ships 12 built-ins spanning language/framework conventions (`php-best-practices`, `laravel-best-practices`, `symfony-best-practices`), workflow (`testing-strategies`, `api-design`, `explore-codebase`, `worktree-workflow`, `mcp-authoring`, `matchups-sync`) and the original four (`security-audit`, `phpunit-master`, `composer-wizard`). `disable-model-invocation`, `user-invocable`, and `context: fork` frontmatter flags are enforced, not decorative — a fork-context skill runs through `AgentWorkerPool` as an isolated sub-agent. Loading is **progressive**: the system prompt carries only each skill's name + description, and the model pulls the full `SKILL.md` body through the `Skill` tool when it decides one is relevant. Path-scoped skills self-announce — the first time `Read`/`Edit`/`Glob` touches a file a skill's `paths:` covers, that skill is surfaced (once per session, via one shared announce-set across the three tools). Skills authored for other CLIs are imported rather than ignored — `~/.claude/skills`, `<project>/.claude/skills`, `~/.config/opencode/skills` and `<project>/.opencode/skills` are all scanned, and the picker shows a provenance badge for where each one came from (symlinked skill directories are followed, which is how those trees are commonly laid out — but a link is **confined**: one in your own `~/.claude/skills` may point anywhere else in your home, while one in a cloned repository's `<project>/.claude/skills` may not leave that skills directory. That confinement is enforced on the *directory* as well as on each entry in it, and both halves are needed: an entry is judged against the skills directory's real path, so committing `.claude/skills` **itself** as a link used to relocate the boundary rather than trip it — every `SKILL.md` under the target was read, and a skill body is prompt context. The directory is therefore held inside the checkout it came from, which is the one path in the pair a repository cannot have forged; a link that stays inside the checkout (`.claude/skills -> shared/skills`) is still honoured, and a refused tree is dropped without disturbing your own or the built-ins. Be precise about what that buys, though: "inside the checkout" is not the same as "committed", since a checkout also holds untracked and gitignored files — so what is closed is a repo reading files from *outside* the tree you cloned, not every conceivable in-tree misdirection. A refused directory is named on stderr at launch rather than silently skipped. Both directory-level checks — this one and the workflow tier's — are the single predicate in `Support\ContainedPath`, which is also where the difference between them is written down: an entry resolving *onto* its boundary is fine, a directory resolving onto its trust anchor is not. The walk is also depth- and breadth-bounded, so a link to a huge tree cannot cost seconds per launch). Collisions resolve so that **nothing you did not write can re-point a name you already use**: a native skill wins over any imported one, and within an imported tree your own `~/.claude` / `~/.config/opencode` copy wins over a cloned repository's `<project>/.claude` / `<project>/.opencode` copy — the reverse of the project-wins order native skills use, because a project's *foreign* skill arrives with whatever you cloned. Between the two foreign tools, opencode wins over Claude. An imported `SKILL.md` that will not parse is another tool's file and not something you can fix, so it is skipped quietly rather than logged to stderr on every launch; the launch prints **one** line saying how many were skipped, and `SUGARCRUSH_DEBUG_SKILLS=1` lists them (they are also readable from `SkillManager::skipped()` and, for the launch as a whole, `Bootstrap::skillSkips()`).
- **Agents** — `Agents\*`: 6 sub-agent presets (coder/reviewer/debugger/architect/tester/devops) with their own model, tools, skills, and a streaming lifecycle, dispatched through `AgentWorkerPool` (`pcntl_fork`-based, with a synchronous fallback + warning when `pcntl` is unavailable).
- **Teams & worktrees** — `Agents\{Team,TeamManager,Teammate,TaskList,Mailbox}`: a lead agent spawns a capped team of teammates that atomically claim `TaskList` tasks (SQLite `flock`-backed, contention-tested) and exchange append-only JSON-lines mailbox messages. `Agents\{WorktreeConfig,WorktreeManager,PathJail}` give each teammate an isolated git worktree (`.worktreeinclude`-aware, swept for staleness) sandboxed by a path jail.
- **Workflows** — `Workflows\*`: `WorkflowBuilder`/`WorkflowRegistry`/`WorkflowEngine` run multi-stage agent pipelines — sequential `stage()`, fan-out `parallel()`, chained `pipeline()`, and task-then-verifier `withVerification()` — defined as PHP DSL files or YAML (`WorkflowRegistry::loadYaml()`). SIGINT/SIGTERM during `run()` captures a real pause file for later resumption at stage granularity. Discovered from `~/.sugar-crush/workflows` and `<project>/.sugar-crush/workflows` (project wins), and driven from the chat with `/workflow run|pause|resume|status|list`. The two tiers are **not** equally capable: a `.php` workflow is loaded by `require`ing it, so only the user tier honours one — a `.php` file in a project's directory is neither listed nor loaded, because a workflow you cloned should not be able to run its own code the moment you name it. Symlinks in a project's workflow directory are **confined** to it: a committed `deploy.yaml -> ~/.ssh/id_rsa` is neither listed nor loaded, so a repo cannot ship a link that reads your files — not even into an error message (a rejected file used to be reported with the YAML parser's message, which quotes the line it choked on). That confinement is enforced on the *directory* as well as on each entry in it, and the two are separate checks for a reason worth knowing: an entry is judged against the workflows directory's real path, which is an answer that moves with the directory, so committing `.sugar-crush/workflows` **itself** as a link used to relocate the boundary rather than trip it — every `*.yaml` basename in the target was listed and every one that parsed was loaded. The directory is therefore held inside the checkout it came from, which is the one path in the pair a repository cannot have forged. A link that stays inside the checkout (`.sugar-crush/workflows -> tools/workflows`) is still honoured: that is repo content pointing at repo content, the same trust as a committed `.yaml`. A link inside your own `~/.sugar-crush/workflows` is still followed; that directory is yours. A *dangling* link is refused rather than granted — it names no workflows, and a committed link to a path that does not exist yet is a request to read whatever appears there later — while a directory you simply have not created is still named in the "not found" message. And note the boundary is the checkout, not the workflows directory, for the directory-level check, which has a residual worth stating: a checkout also holds untracked, gitignored, developer-local files, so a repo committing `.sugar-crush/workflows -> <some other directory in the checkout>` can still disclose that directory's `*.yaml` basenames and descriptions. What the check refuses is the version of that worth having — a link resolving **onto the checkout root**, which is where local files like `local-secrets.yaml` actually sit and which `-> ..` reached in one committed line. Reduction, not elimination. Whichever tier refuses a directory, the launch says so on stderr, naming the path and where it resolved to. The same boundary and the same predicate (`Support\ContainedPath`) hold a cloned repository's `.claude` / `.opencode` / `.sugar-crush` **skills** directories — see the Skills bullet above; the two tiers ran separate copies of the idiom until the skills one turned out to be missing the directory-level half entirely. A project's `.yaml` workflow is declarative and does run: its tasks are dispatched as sub-agents carrying the launch's `PermissionGate`, and a stage that **declares** a tool the session's permission mode denies (`tools: [Bash]` under `dont-ask`, say) is refused before the workflow's *first* stage is dispatched, not when that stage is reached. Be precise about the limit of that, though, because "denied tools are refused" reads as more than it is. Which modes can refuse a *declaration* is a per-mode answer: `dont-ask` refuses every non-read-only tool; `plan` refuses `Edit`/`Write`/`mcp__*` but **not** `Bash`, because what makes a Bash call a write under Plan is a redirection in its arguments and a declaration has none; `auto` refuses **nothing** through its mode logic, since its judgement is `SafetyClassifier`'s and the classifier reads the command out of the arguments too — under `auto` only an explicit `Deny` rule refuses a declaration. `default`/`accept-edits` *ask*, and an `Ask` is deliberately not a refusal (settling one needs the blocking prompt, which the engine has no channel to). Nor is the declared list a capability *boundary*: a parallel stage's agents are all handed the first task's `tools`, so the list is a request that gets permission-checked, not a sandbox. And per-*call* gating — a gate deciding one tool call at the moment a model asks for it — does not happen on the workflow path at all, because nothing there issues a tool call yet. `ProcessExecutor`'s worker is still the P1.S5 simulation, so a workflow stage makes no provider request; what the gate protects today is the declared capability, not each use of it. (`/workflow run` also blocks the TUI while it runs, and its stages do not reach a live model yet — both under *Limitations* below.) See [`examples/workflows/lint-then-fix.yaml`](examples/workflows/lint-then-fix.yaml) for a runnable YAML example and [`workflows/deep-research.php`](workflows/deep-research.php) for the PHP DSL form.
- **MCP** — `MCP\*`: multi-server client (stdio + HTTP, `.mcp.json`, `${VAR}` interpolation) and stdio/HTTP servers to host your own tools. Per-agent-preset `mcpServers` allowlists are enforced by `McpClient` against `McpRouter`, not just decorative config.
- **Sessions** — `Session\SessionStore`: SQLite (WAL) persistence of sessions/messages/tool-calls with FK-enforced cascade. **Retention is opt-in and off by default**: set `$SUGARCRUSH_SESSION_RETENTION_DAYS` to a positive number of days and each launch drops sessions untouched for that long, reporting on stderr exactly what it removed. **A session you have named is never pruned, whatever its age** — a name is the signal you meant to keep it — and neither is the session the launch is about to resume. `Session\EnhancedSessionStore` adds the per-turn `/rewind` checkpoints on top; message bodies are content-addressed and stored once each, so the conversation itself costs storage proportional to its length rather than to its length squared (the per-checkpoint list of message references is still proportional to the length).
- **Tokens & export** — `Util\TokenTracker` (token + cost accumulation) and `Util\Exporter` (Markdown / JSON / text transcripts).
- **Messages** — typed `Messages\{System,User,Assistant,ToolResult}Message`; `UserMessage` carries file/image attachments; `AssistantMessage` carries tool calls + reasoning.
- **Context files** — `CLAUDE.md`/`AGENTS.md` at the project root are loaded into the system prompt, with `@import` expansion (cycle- and traversal-guarded, and de-duplicated so an imported doc is not injected twice). `Forced` instructions come from user config. An `EnvironmentBlock` (cwd, platform, git state, date) is prepended so the model is not guessing at its surroundings.
- **Permission prompts** — the blocking request/reply flow is wired end to end: `Chat` runs the whole batch of a turn's tool calls through the `PreToolUse` chain *before* forking any of them, and a `HookResult::ask()` suspends the turn on a `PermissionRequestMsg` rendered as a Veil modal over the transcript. `y`/`n`/`a` settles the paused call rather than being advisory, and `a` — once its confirm is answered with a second `y` — records a session-scoped grant so that tool stops asking. The prompt is *armed* when it goes up and any non-answer keystroke disarms it (`Enter` re-arms), so an ordinary slash command typed at a live prompt is swallowed instead of answering it. Because the shipped default mode is `bypass-permissions`, you will not see a prompt until you select a mode that asks (`default`, `accept-edits`, `auto`) or register a hook that returns `ask()`. Note that on the **engine** path an ASK currently fails closed, so an asking mode refuses those calls rather than prompting — see the known gap below for why.

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

- **`SglangProvider`'s `toolCallParser` applies to the batch `complete()` path only.** The streaming path reassembles tool calls itself and does not consult the setting.
- **An ASK decision fails closed on the engine path.** The blocking modal works for `Chat`'s own tool calls. On the engine path it does not, for a simpler reason than the plumbing suggests: **nothing anywhere attaches an approver** — `EngineBackend::withPermissionApprover()` has no caller outside its own test — so every ASK settles as "permission required and no approver is attached to this run". Attaching one is necessary but not sufficient: `completeAsync()` runs the turn in a `pcntl_fork()`ed child whose only channel back to the parent is a one-way frame stream, so an approver would also need that socket to become request/response before it could put a question on screen. Until both land, a mode that answers Ask refuses those calls instead of prompting — which is why the shipped default mode is `bypass-permissions` rather than `default`.
- **The `anthropic` provider type key is OpenAI-shaped.** It authenticates as Anthropic but posts to `chat/completions` with `supportsFunctionCalling: false`, so it cannot call tools. Use `claude-code` or `SUGARCRUSH_BACKEND_CMD` for a native Anthropic path.
- **Five shell commands are still inert**: `GroupInputCmd`, `CancelAgentCmd`, `ResumeAgentCmd`, `StopAllAgentsCmd`, `QuitAgentViewCmd`. The first has no counterpart in the live app; the agent four would need to reach into a worker pool the shell does not hold. Their pane/selection half *is* applied — only the action half is missing.
- **Workflow resume granularity is per whole stage.** An interrupted *parallel* sub-stage cannot be resumed with partial credit.
- **A workflow stage does not reach a live model.** `AgentWorkerPool`'s default executor is `ProcessExecutor`, whose worker script is still the P1.S5 simulation: it echoes the task back as `[name] Task finished: …` without making a provider request. So `/workflow run` genuinely exercises loading, stage sequencing, interpolation, fan-out, pausing and resumption — and genuinely does not do the agents' work. Inject your own `ExecutorInterface` to change that.
- **`/workflow run` freezes the TUI until it finishes.** `Chat::update()` calls `WorkflowEngine::run()` synchronously on the ReactPHP loop, and each stage blocks in `stream_select` until its worker returns or `ProcessExecutor`'s own 300s timeout expires (the executor enforces that one, not the sub-agent's) — so a multi-stage workflow means no repaint, no keystrokes, and no spinner for the duration. Tracked as issue #79; the fix follows `EngineBackend::completeAsync()`'s fork-plus-socket pattern.
- **`pcntl` is required for real parallelism.** Without it `AgentWorkerPool` falls back to sequential execution and logs a one-time visible warning rather than pretending to fan out.
- **Providers are unit-tested against mocked transports.** No test in this suite makes a live API call, so wire-format drift at a real endpoint is caught by the `Doctor` tool (model-invocable; there is no `/doctor` slash command) and by using it, not by CI.
- **The `Doctor` tool reports capabilities, it does not repair them.**

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

**6,424 tests / 51,767 assertions, 0 failures, 1 skipped** — the whole of
`sugar-crush/tests/` in one `vendor/bin/phpunit` run on PHP 8.3.6, 1m52s. The
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

Coverage spans every subsystem: typed messages + attachments, all 10 built-in
tools (the whole of `src/Tools/BuiltIn/`, which is exactly the array
`Bootstrap::tools()` hands the engine), all 7 `Providers\ProviderFactory` type
keys (unit-tested with mocked transports — no live calls), the hook framework, permission-mode gating (incl. `pcntl_fork` concurrency stress tests for atomic task claiming), skills discovery + flag enforcement, sub-agents/teams/worktrees, workflow execution (sequential/parallel/pipeline/verification, PHP + YAML loading), the MCP client/servers (incl. per-agent routing enforcement), the SQLite store, token tracking, export, the TUI components, the `Runtime` orchestration (streaming accumulation, tool-result correlation, MODIFY hooks), the shell-out `CommandBackend` / `StreamingCommandBackend`, and the `EngineBackend` agentic loop (incl. the `maxSteps` guard).

A dedicated `tests/Integration/` tier asserts **reachability** rather than behaviour: that the session store, session tabs, background sessions, the skills subsystem, mouse mode, the environment block and root context-file loading are actually reached from `bin/sugarcrush` → `Bootstrap::app()`, not merely implemented somewhere in `src/`. That tier exists because the audit recorded in the monorepo root's `crush_code_update.md` found well-tested subsystems that no real run could ever touch — the `Write` tool being the most recent: a full suite of its own, and one missing line in `Bootstrap::tools()`. The tier now also pins the whole built-in tool set by count and by name, since an omission from a literal array is not something a per-tool test can see.

See [`CHANGELOG.md`](CHANGELOG.md) for how the suite got here.
