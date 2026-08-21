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
unrecognised flag; `-p`/`--prompt` handed a flag instead of text; `--root`
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

That puts `SkillLoader`'s per-skip and per-refused-directory lines back on
stderr. They are off by default because the TUI renders to stdout under an alt
screen and a skill scan also runs mid-session on the Ctrl+P provider switch, so a
stray stderr line lands inside a frame the renderer believes it owns.

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
5. **The walk hit a cap.** Depth 6, or 2000 directories. A `skills/x -> /usr/share`
   link cost 8.29s on one measured launch, which is why the caps exist.
6. **A name collision.** Native always beats foreign; within the native tiers,
   project beats user beats built-in.

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

If an `error_log` line says the config **could not be fully started**, one entry
has an unknown `type`. That throw is ordering-dependent: servers listed *before*
the bad entry are up, servers listed *after* it were never reached. Move or fix
the bad entry.

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

**My hook script cannot find its interpreter, or `$HOME` is empty.** Expected:
`ScriptHook::execute()` **replaces** the environment with six `CRUSH_*`
variables. Nothing from your shell survives — `sh` supplies a default `PATH` and
that is all. See
[`HOOKS.md`](HOOKS.md#environment-handed-to-the-script) for the measured
environment.

**My `exit 4` rewrite was denied.** Its stdout was not a JSON **object**. A list
or a scalar is rejected, and the test is on the opening brace of the text.

**The CLI hangs during a hook.** There is no timeout on a hook. Keep them fast.

---

## A permission rule has no effect

Almost always because the pattern is argument-shaped. `ruleMatches()` compares
the pattern to the tool **name**: exact, or prefix if it ends in `*`. Measured:
`Bash(rm *)` against `Bash{command: "rm -rf build"}` → **Allow**, the rule never
matched; `Bash` and `Bash*` → Deny. See
[`PERMISSIONS.md`](PERMISSIONS.md#pattern-matching-is-name-only--measured).

A malformed entry is skipped **item-wise** and reported on stderr with its index
— `permissionRules[2] ('Write') has no valid 'action' … rule skipped rather than
coerced`. A `permissionRules` that is not a list loads zero rules and says so.

**Everything I do is allowed.** The shipped default mode is
`bypass-permissions`. With no rules configured, that is identical to having no
gate at all except for `ProtectFilesHook`, `ConfirmRemoveHook` and the
`rm -rf /` breaker. Set `permissionMode` in `config.json`.

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

`MemoryBlock` folds **`project` scope only** into the system prompt, and
`/memory add` defaults to `user`. Use `--scope project`.

Two more bounds: 12 entries, newest first, and 4096 bytes of rendered note
lines. And the block is frozen at capture, so a note written mid-turn lands on
the **next** `Runtime`, not the next step.

`/memory` answering "Memory store not configured" means
`~/.sugar-crush/memory` could not be created or is not writable — deliberately
not a launch failure.

`/memory import` does not exist: `ForeignMemoryImporter` has no runtime caller.

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
- **A shell-out backend lost all my paragraph breaks.** You used
  `SUGARCRUSH_BACKEND_CMD_STREAM` with a wrapper written for
  `SUGARCRUSH_BACKEND_CMD`. They are two different stdout protocols and neither
  substitutes for the other.
- **A completion was killed mid-flight.** There is deliberately **no total
  request timeout** on a provider call. What exists is a *per-frame idle*
  ceiling on a forked completion child: every frame the child streams resets it,
  so a turn making visible progress stays alive indefinitely.
  `SUGARCRUSH_CONNECT_TIMEOUT` bounds the connect phase only.

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

## Terminal and display problems

| Symptom | Try |
|---|---|
| the theme is wrong on a light terminal | `SUGARCRUSH_BACKGROUND=light`, which outranks both OSC 11 and `COLORFGBG` |
| my terminal's own text selection stopped working | `SUGARCRUSH_DISABLE_MOUSE=1`, or `SUGARCRUSH_DISABLE_MOUSE_CLICKS=1` to keep wheel scrolling |
| a stderr line is painted inside a frame | a construction-time notice landed after the alt screen came up; the content is also readable from `Bootstrap::projectTierRefusals()` / `skillSkips()` |
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
