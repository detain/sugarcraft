# Troubleshooting

Symptoms first, in roughly the order they are met. Every diagnosis names the
class or file that produces the behaviour, so you can read the source rather than
trust this page.

Start here:

```sh
sugarcrush doctor                       # nine checks; exits 1 if any FAILs
sugarcrush doctor --output-format json  # the same, machine-readable
```

---

## Exit codes

Three, and the distinction is load-bearing:

| Code | Meaning |
|---|---|
| `0` | ran and succeeded |
| `1` | **ran and failed** — retryable in principle |
| `2` | **nothing was attempted and a retry cannot help** — a usage error or an unusable config |

Exit 2 causes, all reported through `NonInteractive::failUsage()`: an
unrecognised flag; `-p`/`--prompt` handed a flag instead of text; a word left
over after a `-p`/`run` prompt (quote the whole prompt) or before a subcommand,
or a second project directory; `--root`
naming no directory; `--config` naming no readable file; an unusable permission
policy; an unusable explicitly-selected provider; an unusable hook file; a
missing `vendor/autoload.php`.

Under `--output-format json` each of those emits exactly one JSON error document
on stdout. **Two exceptions** where stdout is empty: a checkout with no
`vendor/autoload.php` (the class that owns the document shape is what is
missing), and an invalid `--output-format` **value** (the requested rendering is
one nothing implements, so the failure renders as text). A *valid*
`--output-format json` beside any other usage error still emits the document.

---

## `sugarcrush` exits 2 immediately and will not start

The launch refuses rather than degrades whenever gating policy is present but
unusable. Read the stderr line; it names the file and the value.

| Message mentions | Cause | Fix |
|---|---|---|
| invalid JSON in `config.json` | a stray comma | fix the file; `doctor`'s `config file` check reports the parser's own message |
| an unrecognised permission mode | a typo in `permissionMode`, `SUGARCRUSH_PERMISSION_MODE` or `--permission-mode` | one of `default`, `accept-edits`, `plan`, `auto`, `dont-ask`, `bypass-permissions` |
| `--permission-mode expects a mode, but the value is empty` (or the same for `--model`) | usually `--permission-mode="$MODE"` with `$MODE` unset or misspelled in the calling script | quote-check the variable. The flag refuses an empty value on purpose — it used to accept one, apply no mode at all and exit 0, so a script could believe a mode was in force when none was. Note the deliberate asymmetry: an **empty** `SUGARCRUSH_PERMISSION_MODE` or `"permissionMode": ""` is read as *absent* and falls through to the next source, because an unset variable is a normal state of an environment while typing the flag is an explicit act |
| `hooks.yaml` … refusing to start | YAML syntax error, unknown event name, uncompilable matcher, or an unrecognised entry key | see [`HOOKS.md`](HOOKS.md) |
| a hook would displace an already-registered hook | your `hooks.yaml` reuses a built-in's name **on that built-in's own event** — `protect-files` or `confirm-rm` on `PreToolUse`, `audit` on `PostToolUse` | rename the entry; see the name/event table in [`HOOKS.md`](HOOKS.md#a-loaded-hook-may-only-add-to-the-chain) |
| `cannot be reached: … so whether hooks are configured there is unknowable` | a directory on the way to the hook file cannot be searched | fix the permissions on the ancestor directory |
| `SUGARCRUSH_MAX_COST` present but unusable | `5USD`, `0`, `-5`, `1e309` | a positive finite number, or unset it |

The pattern behind all of these: a guard silently missing from the chain is the
one failure mode a guard must not have, and it is invisible exactly when it
matters. Absence is a no-op; **present but unusable is a refusal**.

---

## `--version` reports a commit that is not my checkout's HEAD

Expected. `Help::versionString()` reads Composer's install metadata
(`InstalledVersions::getReference()`), which is the reference recorded at
`composer install` time — not the working tree's current HEAD.

Measured in this checkout: `git rev-parse --short HEAD` said `7d873a15` while
`sugarcrush --version` said `dev-master (a4be826)`, and `a4be8263` is a real but
older commit in the same repository. Re-run `composer install` to refresh it.

`unknown` instead of a version means `Composer\InstalledVersions` is not
available — a phar or a hand-rolled PSR-4 autoloader rather than a Composer
install.

---

## My skills are not showing up

Skill-load failures are **quiet by default**, so nothing has told you yet.

```sh
SUGARCRUSH_DEBUG_SKILLS=1 sugarcrush
```

That puts `SkillLoader`'s per-skip and per-refused-directory lines back into the
`error_log()` stream: in the TUI that is the log file (see
[Where diagnostics go](#where-diagnostics-go) — `~/.sugar-crush/logs/sugarcrush.log`
by default), elsewhere stderr. They are off by default because many of the
scanned files belong to other tools (`~/.claude/skills`,
`~/.config/opencode/skills`), and a line on every launch about a file this CLI's
user cannot fix is noise.

Then work down this list:

1. **No frontmatter.** `Skill::fromFile()` requires a `---` fenced block. A
   `SKILL.md` without one is refused, not defaulted.
2. **Wrong filename.** It must be exactly `SKILL.md` inside a directory; the
   directory's name becomes the skill's name.
3. **The whole directory was refused.** A committed
   `.sugar-crush/skills -> /elsewhere` is refused wholesale, and the launch prints
   `ignoring <path> — <reason>`. Same for `.claude/skills`, `.opencode/skills`,
   `.sugar-crush/agents`, `.claude/agents`, `.opencode/agents`,
   `.sugar-crush/workflows`.
4. **The foreign user tier is gone.** If `$HOME` is unresolvable,
   world-writable, or owned by somebody else, `~/.claude/skills` and
   `~/.config/opencode/skills` are dropped entirely — project trees survive.
5. **The walk hit a cap.** Depth 7, or 2000 directories. A `skills/x -> /usr/share`
   link cost 8.29s on one measured launch, which is why the caps exist.
6. **A name collision.** The tier decides first — user beats project beats
   built-in, whatever the format — and inside one tier native beats foreign
   (opencode beats Claude). The loser is listed by `SUGARCRUSH_DEBUG_SKILLS=1`.
7. **The file is too big.** A `SKILL.md` over 1 MiB, or one whose frontmatter
   does not close within its first 64 KiB, is refused and listed by
   `SUGARCRUSH_DEBUG_SKILLS=1`.

**My skill loads but its `allowed-tools` / `effort` / `context: fork` does
nothing.** Correct — those fields are parsed and carried but not acted on by the
live CLI path. See [`SKILLS.md`](SKILLS.md#frontmatter) for the field-by-field
table.

---

## My MCP tools are missing

```sh
sugarcrush mcp list        # reads .mcp.json; starts nothing
```

Four answers, matching `Bootstrap::mcpConfigDecision()`:

- **"No `.mcp.json` in this project"** — it is looked for at the project root
  only. There is no user-level fallback.
- **resolves outside the project tree** — `.mcp.json` is a symlink out of the
  checkout. Refused; not an opt-in situation.
- **present but this root is not trusted** — add the canonical root to
  `trustedProjectMcp`. The refusal line prints the exact path.
- **N servers declared** — the config is live. If tools are still missing, a
  server failed to start. `mcp list` cannot tell you that: it contains no
  `proc_open()` by design, so `enabled` reflects the config and not liveness.

If an `error_log` line (in the TUI's log file, or on stderr for `-p` and the
subcommands — see [Where diagnostics go](#where-diagnostics-go)) says the config
**could not be fully started**, at least
one entry could not be built (an unknown `type`, such as `sse`, or a malformed
entry). It does not matter where that entry sits in the file: every entry is
attempted, and the line names each one that could not be built. Every other
server is up. Fix or remove the named entries; moving them changes nothing.

Bridged tool names are `mcp__<server>__<tool>`. Under `plan` mode every `mcp__*`
name is denied as a write tool, which is the one mode where a bridge and `Bash`
diverge.

---

## My hook never fires

1. **Was the file loaded at all?** A project `hooks.yaml` needs
   `trustedProjectHooks`. The launch prints one line saying it was not loaded and
   naming the path to add — but it prints it **once per path per process**, at
   construction time, before the alt screen, so it may already be scrolled off.
2. **Does the matcher match?** It is matched case-insensitively against the
   **tool name** only. Write `^Bash$`, not `Bash(rm *)`.
3. **Wrong event?** A `PreToolUse` hook cannot see output; a `PostToolUse` one
   cannot stop anything.
4. **`disabled: true`** keeps the entry out of the chain.
5. **A narrower hook denied first.** `ProtectFilesHook`, `ConfirmRemoveHook` and
   `AuditHook` are registered ahead of everything from a file, and a `Deny` wins
   outright.

**My hook (or a Bash command) cannot see `GITHUB_TOKEN`, an `AWS_*` variable or
an API key.** Expected: `ScriptHook::execute()` sets eight `CRUSH_*` variables over
your launch environment, but every variable named like a credential
(`*_API_KEY`, `*_TOKEN`, `*_SECRET`, `AWS_*`) is removed first, because what a
hook, Bash or Grep prints reaches the model. Name the ones a hook or command
genuinely needs in `secretEnvAllowlist` in `~/.sugar-crush/settings.json` (see
[`SETTINGS.md`](SETTINGS.md)). `PATH`, `HOME` and the rest of your shell are
inherited. See
[`HOOKS.md`](HOOKS.md#environment-handed-to-the-script) for the measured
environment.

**My `exit 4` rewrite was denied.** Its stdout was not a JSON **object**. A list
or a scalar is rejected, and the test is on the opening brace of the text.

**The CLI hangs during a hook.** It should not any more — a hook run is bounded,
drain and reap together, at 60 seconds by default. `timeout:` on the entry
overrides it, and anything that is not a positive **finite** number is refused
at load (`0`, `-1`, `.inf`, `1e400`, `.nan`), because every one of those is how
somebody asks for the unbounded wait that froze the CLI. A whole **chain** is
bounded too, at the sum of its entries' timeouts, held across the re-scan passes.
An expired hook is reported as a **DENY** with whatever it managed to write
(clipped to 16 KiB), not as an allow. See
[`HOOKS.md`](HOOKS.md#the-timeout). Keep hooks fast anyway: a hook runs on the
TUI's own thread, so every second of one is a second the terminal is frozen.

---

## A permission rule has no effect

Argument-scoped patterns do match — `Deny Bash(rm *)` against
`Bash{command: "rm -rf build"}` is **Deny** — but they match a *spelling*, and
the usual reasons one seems to do nothing are: a different spelling of the same
command (`/bin/rm -rf build` is not `rm *`); a case mismatch in the tool name
(`fnmatch()` is case-sensitive — `doctor`, not `Doctor`); an `allow` rule whose
pattern spans a shell separator (`Allow Bash(cd x && make)` never fires — every
segment must match on its own); or an earlier rule that matched first (first
match wins). See
[`PERMISSIONS.md`](PERMISSIONS.md#pattern-matching-a-tool-name-plus-an-optional-argument-glob).

A malformed entry is skipped **item-wise** and reported with its index —
`permissionRules[2] ('Write') has no valid 'action' … rule skipped rather than
coerced`. A `permissionRules` that is not a list loads zero rules and says so.
Both go to stderr **and** to the session transcript as a system row, so an
interactive session shows them after the alt screen has opened.

**Everything I do is allowed.** In the TUI the shipped default mode is
`default`, which asks before every write and shell command — so either a mode
was configured (`sugarcrush doctor` names it on its `permission policy` line,
and `/permissions` shows which source set it) or this is a `-p` or background
run, whose default is `bypass-permissions`. With no rules configured, that is
identical to having no gate at all except for `ProtectFilesHook`,
`ConfirmRemoveHook` and the `rm -rf /` breaker. Set `permissionMode` in
`config.json`, or pass `--permission-mode default`.

**Every edit asks.** That is the TUI's default mode, `default`. `a` then `y`
on the prompt remembers a pattern for the rest of the session; for a
standing choice set `permissionMode` to `accept-edits` (edits inside the
project run unprompted, shell commands still ask) or add `permissionRules`
allow entries. A sub-agent's questions come up in the same modal, naming the
agent that asked (a parallel member's are relayed one at a time).

**`Allow Edit(...)` does not cover `ApplyPatch`.** A restrictive `Edit` or
`Write` rule binds a patch, but an allow does not carry over, because a patch
can also delete and move files. Add `Allow ApplyPatch(...)` for the same paths.
See [`PERMISSIONS.md`](PERMISSIONS.md).

**`auto` asks about a call instead of refusing it.** That is a security
finding — fetched code into a shell, data sent to an endpoint, credentials,
permissions, or a write to `.git`/`.sugar-crush` policy files — which `auto`
always puts to you; only an explicit `permissionRules` entry settles one
without asking. With `autoReview` on, the reviewer can also answer *ask*. See
[`PERMISSIONS.md`](PERMISSIONS.md#what-auto-classifies).

---

## `/workflow run` says "not found", or takes the whole TUI down

- **Not found** — check the directory was not refused. Both tiers are anchored
  (project to the checkout, user to `$HOME`), and the refusal is reported at
  launch, not at `/workflow list`. A `~/.sugar-crush/workflows -> /opt/shared`
  link is refused now.
- **A `.php` workflow with a syntax error kills the session.** It is reached by
  `require`, so the error is a compile fatal — uncatchable, and `Chat`'s
  `catch (\Throwable)` cannot survive it. YAML errors are one transcript line.
- **A stage ran the wrong prompt.** `agent:` is a label, not a preset reference;
  `WorkflowEngine` never reads `AgentPreset`. See
  [`WORKFLOWS.md`](WORKFLOWS.md#three-limits-to-know-before-you-design-around-this).
- **`pipeline` or verification stages are ignored in my YAML.** There is no YAML
  spelling for them; they are PHP-only.

---

## `/memory add` worked but the model does not know

`MemoryBlock` folds an **index** of the `user` and `project` scopes into the
system prompt — one line per note, not the note text — and never the `agent`
scope, where `/memory import` lands. `/memory add` defaults to `project`.

Two more bounds: 40 entries, newest first, and 4096 bytes of rendered note
lines. And the block is frozen at capture, so a note written mid-turn lands on
the **next** `Runtime`, not the next step.

`/memory` answering "Memory store not configured" means
`~/.sugar-crush/memory` could not be created or is not writable — deliberately
not a launch failure.

`/memory import claude|opencode` is wired (`Host\Commands\MemoryCommand::import()`): it writes
the foreign tree into the `agent` scope — which the prompt never folds: only
`user` and `project` notes reach the `<project-memory>` block (see above) — and
then records a
`.sugar-crush/memory/.imported-<target>` sentinel; while that sentinel exists a
re-run answers "already imported" instead of duplicating every entry, so delete
it to re-import deliberately.

---

## Custom command problems

- **The command is not listed.** Empty body, malformed frontmatter, frontmatter
  that is not a mapping, or an unsafe name — all fail closed and are skipped.
- **It appears but is not mine.** A tier collision (project beats user beats
  built-in), or a control-plane name was taken back: `budget`, `clear`, `exit`,
  `help`, `model`, `permissions`, `quit`.
- **`` !`cmd` `` was refused.** A project-tier command needs
  `trustedProjectCommands`. Or the 10-second budget — shared by *all* forms in
  one expansion — was already spent.
- **`@file` came back as a notice.** It must be root-relative and end in a
  `.extension`; `@/abs/path`, `@alice` and `@../x` do not match the pattern at
  all and stay literal.
- **The example in my fenced code block ran.** Fences are not exempt. See
  [`COMMANDS.md`](COMMANDS.md#fenced-code-blocks-are-not-exempt).

---

## Provider and model problems

```sh
sugarcrush models          # every selectable provider, "*" marks the selected one
```

- **`doctor` says provider `echo` (WARN).** No provider is selected, so the
  offline `EchoProvider` is in use. Set `SUGARCRUSH_PROVIDER`, or pick one with
  Ctrl+P (which persists to `config.json`).
- **`doctor` FAILs on provider.** The configured name is not one this install
  knows. `models` lists the valid ones.
- **Streaming shows nothing until the end.** Fixed — if you still see it, the
  install predates the fix. The streaming backend's read loop used to run to
  completion inside one ReactPHP `futureTick`, so the event loop was blocked for
  the duration and the render tick could not run; you got a per-token callback
  plus one repaint at the end. It is driven from a periodic timer on the loop
  now. Before/after measurements in
  [`ENVIRONMENT.md`](ENVIRONMENT.md#the-two-shell-out-variables).
- **A shell-out backend hung on a long conversation.** Fixed — if you still see
  it, the install predates the fix. The history used to go out in one blocking
  write before anything was read back, which deadlocks past ~64K against a
  command that echoes its input, and neither shell-out path has a completion
  deadline that would end it. Both now interleave the write with the reads.
- **A shell-out backend lost all my paragraph breaks.** You used
  `SUGARCRUSH_BACKEND_CMD_STREAM` with a wrapper written for
  `SUGARCRUSH_BACKEND_CMD`. They are two different stdout protocols and neither
  substitutes for the other.
- **A completion was killed mid-flight.** There is deliberately **no total
  request timeout** on a provider call. What exists is a *per-frame idle*
  ceiling on a forked completion child: every frame the child streams resets it,
  so a turn making visible progress stays alive indefinitely.
  `SUGARCRUSH_CONNECT_TIMEOUT` bounds the connect phase only.
- **An `sglang` reply stops mid-thought with `finish_reason: length`, or the
  context gauge looks wrong for the model.** The provider reads the server's
  own limits from `GET /model_info` and `GET /server_info` at the server root
  (the configured `baseUrl` minus `/v1`), once per session, on the first frame
  or the first request. The context window is then
  `min(context_length, max_req_input_len − 4096)` and the default `max_tokens`
  is `min(262144, the room the prompt leaves)`. If those reads fail — a proxy
  that only forwards `/v1`, a 401, a server slower than 3 seconds to answer —
  the provider falls back to transcribed per-family figures, which can be out
  of date. Check them directly:
  `curl -s <root>/server_info | jq '{context_length, max_req_input_len, served_model_name, tool_call_parser}'`.
  `"discoverServerInfo": false` in the provider block turns the reads off; a
  `maxOutputTokens` setting replaces the derived `max_tokens` either way.
- **A turn fails with "Context window exceeded", "maximum context length",
  "prompt is too long" or "is longer than the model's context length".** The
  conversation no longer fits the model's window. Every provider's wording of
  this — an HTTP 400 or 413, an error frame inside a streamed 200, an error
  response — is recognised as one failure, `Providers\ContextOverflow`, and
  never retried as is, because the identical request cannot fit the second
  time either. The providers that report a failure as a response (`custom`,
  `anthropic`, `vertex`) put `Context window exceeded:` in front of the
  server's own message. A rate limit that mentions tokens ("tokens per min"),
  or a `max_tokens` larger than the model allows, is not this: dropping history
  would not fix either. The turn first recovers on its own: every older tool
  output in the request becomes a one-line placeholder, what the model was
  already sent is summarised on the turn's own model, and the step is sent
  once more — the transcript keeps the full rows; only the request shrinks.
  You see this error only when that retry is refused too, or when there was
  nothing to take out (a prompt that is too big on its own). Make room
  yourself: `/compact` (or `/clear`), then send the message again — smaller,
  if it was the message itself that did not fit.
- **A transcript notice says the SGLang server "serves" a different model.**
  The server's `served_model_name` is of another model family than your
  configured `model`. Sampling, reasoning effort, the default tool-call parser
  and the fallback window all follow the configured id, so they are the wrong
  family's. Set `model` to the served id, or drop it: a launch that names no
  model adopts the served one and never raises this. A spelling difference
  inside one family (`…-Flash-Next` against `…-Flash-Next-FP8`) does not raise
  it either.
- **A transcript notice says the server "was launched without
  --tool-call-parser".** `/model_info` reported `tool_call_parser: null`, so
  the model's tool calls arrive as raw text in the reply. Relaunch SGLang with
  the parser for its model (`qwen3_coder` for Qwen3.8), or set
  `toolCallParser` to a textual fallback (`dsml`, `minimax-xml-fallback`) that
  matches the model's markup.

---

## `Lsp` always returns an error

Expected on every launch today. `LspTool` is registered and reachable, but
**nothing in `src/` reads a language-server command**, so no server is ever
started and the tool has no client. Every call returns an error naming the
language it could not ask.

That refusal is the design. An empty *success* would read to the model as "this
symbol has no references" — a confident, fabricated claim about your code that
it would then act on. An error reads as "I could not look", which is true.

`diagnostics` carries the same caveat from the other side: it reads the map
filled from the server's `publishDiagnostics` notifications, and nothing pumps
one, so an empty map is not "this file is clean".

---

## Where diagnostics go

In the TUI, stderr is the terminal the renderer draws on, so before the
full-screen frame opens `bin/sugarcrush` points PHP's `error_log` at a private
file instead (`Diagnostics\TuiErrorLog`). Everything that calls `error_log()` —
runtime notices, the tool-call parsers, provider warnings, PHP's own logged
warnings, and the forked turn child, which inherits the setting — writes to the
first of these that can be prepared safely:

1. `~/.sugar-crush/logs/sugarcrush.log` — directory `0700`, file `0600`, a
   symlinked file refused, one `sugarcrush TUI session start pid=N` header per
   launch, and one older generation (`sugarcrush.log.1`) kept when it grows large;
2. `<tmp>/sugarcrush-<uid>/sugarcrush.log` — when there is no owned home, or its
   `.sugar-crush/logs` cannot be created or is unsafe. The directory must be
   owned by you and closed to everyone else, since anyone can create names in
   the temp dir;
3. the null device — the last resort. Nothing is kept, but nothing is painted
   over the frame either; a fatal error then prints its own message when the
   session ends, instead of pointing at a log.

An `error_log` you chose yourself (`php.ini`, `-d error_log=…`, `syslog`) is left
exactly as it is. `-p`/`run` and the subcommands never redirect: stderr is their
channel, and their diagnostics stay on it.

Runtime notices are also shown in the transcript, clipped and capped per turn so
they cannot flood the conversation. A clipped row, or the "and N more" row that
closes a flooded turn, names where the complete text went —
`full text in ~/.sugar-crush/logs/sugarcrush.log`, `full text on stderr` — or
says `full text not kept` when it went to the null device.

---

## "This turn was NOT sent" — the context is full

The block tier refused the prompt: even after compacting, the history is over
`compaction.blockPercent` (95%) of the model's window. Send it again — each
attempt keeps one fewer recent exchange — or free the context: `/compact`,
`/sweep`, `/clear` (the session and its checkpoints survive), or a new session.
`/context` shows what is filling the window. A notice saying the session
compacted several times in a row and is still over the tier is the thrash
breaker: compaction cannot help (one huge exchange, or huge tool output the
compactor must keep), so clear or start over. Moving the tiers is
[`SETTINGS.md`](SETTINGS.md#compaction-thresholds); the mechanics are in
[`CONTEXT.md`](CONTEXT.md).

## A sub-agent call is refused or stops early

- **"N sub-agents are already running in this session"** — the session-wide
  cap (`subagentMaxActive`, 8) is full. The call is refused rather than queued,
  because a parent waiting on its children already holds a seat; the model is
  told not to retry at once.
- **The agent has no `Task`** — it is at the deepest level
  (`subagentMaxDepth`, 3), or its preset's `tools:` list leaves `Task` out.
- **Stopped at its step cap** — `subagentMaxTurns` (200) or the preset's
  `maxTurns`; the result carries a resume id that continues it.
- **Stopped at 90% of its window** — its own context filled; the result
  carries the partial output and a resume id.
- **An `isolation: worktree` run is refused** — the project is not a git
  checkout, or `git worktree add` failed; the reason is in the result.

See [`AGENTS.md`](AGENTS.md).

## An `AI!` comment does nothing

- `watchFiles` is off by default and read only from **your own** settings; a
  project file cannot turn it on. It is read at launch, so restart after
  setting it.
- Only a comment saved **after launch** fires; an `AI!` already in the tree when
  the TUI started does not. Each one fires once until it is removed from its
  file.
- The watcher sends only while the chat is idle — no turn running, an empty
  input box, nothing queued, no modal open.
- Files under dot-directories, `vendor/` and `node_modules/`, symlinks, files
  over 256 KiB, and anything past the first 5,000 files are not watched.
- It must be a comment (`#`, `//`, `--`, `;`, `/*` or `<!--`, after code on
  the line is fine) whose text ends (or starts) with `AI!` or `AI?`.

See the README's
[`AI!` comments](../README.md#prompts-from-your-editor-ai-comments).

## Every `Bash` call is refused

With `bashSandbox` set to `on` or `no-network`, a command runs only inside
bubblewrap, and when the sandbox cannot start — `bwrap` missing, a host that is
not Linux, or a kernel policy that blocks unprivileged user namespaces (Ubuntu
24.04's AppArmor default: `setting up uid map: Permission denied`) — every
`Bash` call is refused with the reason rather than run unsandboxed. Install
bubblewrap, allow its user namespaces, or set `bashSandbox` back to `off`. See
[`PERMISSIONS.md`](PERMISSIONS.md#sandbox).

## Terminal and display problems

| Symptom | Try |
|---|---|
| the theme is wrong on a light terminal | `SUGARCRUSH_BACKGROUND=light`, which outranks both OSC 11 and `COLORFGBG` |
| my terminal's own text selection stopped working | drag inside the transcript — sugar-crush selects and copies on release itself; most terminals also bypass app mouse mode with Shift+drag. `SUGARCRUSH_DISABLE_MOUSE=1` hands the mouse back entirely, or `SUGARCRUSH_DISABLE_MOUSE_CLICKS=1` to keep wheel scrolling |
| a drag-copy highlights but the clipboard is empty | inside tmux the copy goes through `tmux load-buffer -w`, which reaches the outer terminal only when that terminal accepts OSC 52 (the text is still in tmux's paste buffer: `prefix ]`); outside tmux install `wl-copy`/`xclip`/`xsel`, or enable OSC 52 clipboard writes in the terminal |
| a stray line is painted inside a frame | not an `error_log()` line — in the TUI those go to the log file, or the null device as the last resort, never the terminal (see [Where diagnostics go](#where-diagnostics-go)). A direct stderr write can still do it: a construction-time notice that landed after the alt screen came up; the content is also readable from `Bootstrap::projectTierRefusals()` / `skillSkips()` |
| a delegated `Task` shows nothing until it is done (live agent updates need ext-pcntl) | check `php -m \| grep pcntl`. Without ext-pcntl the whole turn runs on the UI thread (`EngineBackend::completeAsync()` falls back to a blocking call), so a sub-agent's dashboard row and the live line under its `Task` row fill in only when the turn returns (with the run's real outcome; nothing is shown as live that was not), and parallel `Task` calls run one after another. With ext-pcntl, parallel members report live through a per-member datagram relay; see "Sub-agent activity relay" in [ARCHITECTURE](ARCHITECTURE.md) |
| `--help`, a subcommand, or a one-shot opened the TUI | it should not — `--help`, `--version`, all five subcommands **and** the `-p`/`run` one-shot are dispatched before `Program::run()`. This has been a real bug before, and flag order was enough to cause it: `--output-format json run` once parsed to `promptRequested=false` and fell through into the blocking full-screen TUI (`Cli\ArgvParser` line 175 records it). File a bug with the **exact** argv, order included |

## Session store problems

`~/.sugar-crush/session.db`, SQLite via PDO. `doctor`'s `pdo_sqlite` probe opens
`sqlite::memory:` — the same **scheme** `SessionStore` builds — touching no file.
Note that `ext-sqlite3` is declared in `composer.json` and called by nothing in
`src/`; probing *that* would report a green install on a box where every session
write fatals.

```sh
sugarcrush session list          # newest first
sugarcrush session delete <id>   # exits 1 if no session has that id
```

Sessions are pruned only if `SUGARCRUSH_SESSION_RETENTION_DAYS` is a positive
integer; the default `0` prunes nothing. A named session is never pruned, nor is
the one about to be resumed.

## See also

- [`ENVIRONMENT.md`](ENVIRONMENT.md) — every variable and its unset behaviour.
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — what runs where, when something makes
  no sense at all.
- [`CONTEXT.md`](CONTEXT.md) — "This turn was NOT sent", compaction and
  pruning.
- [`AGENTS.md`](AGENTS.md) — sub-agents that are refused, queued or stopped.
- [`SERVER.md`](SERVER.md) — `serve` refusals and their exit codes.
- The [README](../README.md#documentation-index) — every other page.
