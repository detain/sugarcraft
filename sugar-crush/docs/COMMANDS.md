# Custom slash commands

A custom command is a markdown file whose body becomes a prompt. Drop
`~/.sugar-crush/commands/review.md` in place and `/review` sends its body as
your turn. The body can interpolate your arguments, splice in a file, and run a
shell command.

That last one is why this page spends as much space on a trust gate as on
syntax: a markdown file in a repository you cloned can name a shell command, and
it does not run unless you opted that project in.

> Not in the bundle's original file list. Added because the feature is live and
> its gate needed documenting somewhere, and none of the seven named guides was
> its natural home.

---

## Where a command goes

Three tiers, merged by name, later overriding earlier
(`CommandLoader::loadAll()`):

| Tier | Source | `` !`cmd` `` allowed? |
|---|---|---|
| built-in | `CommandRegistry::all()` — 24 rows | n/a, they are PHP |
| user | `~/.sugar-crush/commands/*.md` | yes |
| project | `<root>/.sugar-crush/commands/*.md` | **only if this root is trusted** |

**A file's path under the commands directory is its name.** `test.md` gives
`/test`; `deploy/staging.md` gives `/deploy/staging`. Subdirectories namespace,
and the walk is capped at 4 levels deep — which also bounds a symlink cycle
inside the directory.

A project file **may override a built-in** by name, and does: `Chat` keeps the
merged map minus everything that is still a built-in row, so a file-based
override survives the filter while the built-ins stay reachable through their own
dispatch arms (keeping both would list every built-in twice in the "/" popup).

### Except the control plane

Seven names are taken back whatever a file says
(`CommandRegistry::CONTROL_PLANE`): `budget`, `clear`, `exit`, `help`, `model`,
`permissions`, `quit`. These are how you drive and leave the application, and a
cloned repository redefining `/exit` is not a thing that should be possible. A
refused file is recorded on `CommandLoader::refusedCommands()` and reported at
launch — keyed by command *name*, deliberately separate from the directory
refusals, whose keys are paths a reader prints for you to go and open.

(One of the seven, `permissions`, is reserved but not implemented: it is not a
row in `CommandRegistry::all()` and has no arm in `Chat::dispatchCommand()`. The
reservation holds a name for later; typing `/permissions` today does nothing.)

---

## Frontmatter

```markdown
---
description: Review the current diff for correctness.
argument-hint: "[path]"
model: claude-sonnet-4-6
subtask: true
---

Review the change at $1 for correctness issues.
```

Four keys, all optional. Frontmatter itself is optional — a bare markdown file
is a valid command whose body is the whole prompt.

| Key | Effect |
|---|---|
| `description` | shown in the "/" popup and `/help`; defaults to `Custom command: <name>` |
| `argument-hint` | placeholder shown after the name in the popup |
| `model` | pins this command to one model |
| `subtask` | `true` runs it in an isolated subagent |

`CommandSpec::fromFile()` **fails closed**: an unreadable file, unparseable
YAML, a wrongly typed frontmatter value, a frontmatter block that is not a
mapping, an unsafe command name, or an empty template body all raise. The loader
catches and skips, so one bad file cannot take the whole directory down with it.

Command names are restricted to `[A-Za-z0-9][A-Za-z0-9_-]*` per segment with `/`
as the only separator, and a leading, trailing or doubled separator is refused —
which is what keeps a traversal-shaped filename (`../../etc/passwd.md`) from ever
becoming a command name.

**`tier` is not a frontmatter field.** It is stamped by the loader that knows
which directory the file came out of, because the tier is exactly what decides
whether the file's `` !`…` `` forms may run a shell. A `tier: user` line in a
cloned repository's `*.md` would otherwise be a one-line self-promotion.

---

## Template forms

**Five** substitutions, applied in **one pass** over the body. They come from the
*three* alternation branches of `CommandSpec::TEMPLATE_PATTERN` (line 112) — the
first branch, `$(\$|ARGUMENTS|[1-9])`, spells three of the five on its own — so
neither "three" nor "four" describes this table. (`CommandSpec`'s own docblock at
line 50 says "all four template forms"; that count is wrong in the source too.)

Measured — this template, expanded with the arguments `one two`:

```
A=[$ARGUMENTS] 1=[$1] 9=[$9] D=[$$] S=[!`echo SHELLOUT`] F=[@NOTES.md]
```

came back as:

```
A=[one two] 1=[one] 9=[] D=[$] S=[shell:echo SHELLOUT->…] F=[include:NOTES.md->…]
```

Five distinct behaviours, and note `$9` → the empty string rather than a literal
`$9`:

| Form | Becomes |
|---|---|
| `$ARGUMENTS` | everything you typed after the command name |
| `$1` … `$9` | one positional argument; an absent one is the empty string |
| `$$` | a literal `$` |
| `` !`cmd` `` | the command's stdout |
| `@path/to/file.ext` | the file's contents |

The single-pass property is load-bearing rather than tidy. Text one pass
substitutes is invisible to the matcher, so:

- an **argument** whose value is `` !`rm -rf ~` `` is prose in the prompt and
  never a command;
- a `$ARGUMENTS` written **inside** `` !`…` `` is consumed by the shell branch
  before the `$` branch can see it.

Your keystrokes cannot become part of a command line at all.

### `` !`cmd` ``

The command may not contain a backtick or a newline — a template wanting either
wants a script file — and an unterminated `` !` `` stays literal rather than
swallowing the rest of the body.

- Runs under `['bash', '-c', $command]`, cwd = the project root.
- **One 10-second budget is shared by ALL of an expansion's forms**, not per
  command. A per-command bound multiplies: sixty `` !`sleep 30` `` forms with a
  ten-second per-command timeout wedges the single-threaded TUI for ten minutes,
  and "wedged" means the frame does not repaint and Ctrl+C is not read. Forms
  arriving after the budget is spent are refused with a notice naming the
  budget, not silently dropped.
- The budget is **not operator-configurable**, deliberately, and that is the
  opposite of the rule for a provider HTTP call: a completion legitimately runs
  for tens of minutes; this is a local command holding the terminal.
- Output is capped at `MAX_SUBSTITUTION_BYTES` = 16384 **per substitution**, and
  the clip announces itself so a truncated substitution reads as truncated to
  both you and the model. Overflow is counted **per fd**: on a zero exit the
  whole stderr buffer is discarded, so counting both together once reported a
  discarded buffer's overflow as the delivered text's drop count.

### `@file`

Root-**relative** references only, and only ones ending in a `.extension`:

- `@/etc/passwd` — absolute, does not match the pattern at all, stays literal,
  no read attempted.
- `@alice` — no extension, stays literal, so an ordinary mention is safe.
- `@../../.ssh/id_rsa` — no final `.extension` segment, stays literal.
- the `@` is only recognised at the start of the body or after whitespace, `(`
  or `[`, so an email address never resolves as a file.

A resolved reference is still containment-checked against the checkout
(`ContainedPath::within()`) for **both** tiers, because an included file becomes
prompt text. It is capped at the same 16384 bytes.

### Fenced code blocks are NOT exempt

Unlike `@`-imports in instruction files, a `` !` `` or `@file` form inside a
triple-backtick fence **is** expanded. Exempting inline backtick spans would
exempt the `` !` `` syntax from itself, and exempting only triple fences would
make a template's meaning depend on whether the author indented an example.

The consequence: a command file that *documents* this syntax inside a fence has
that example run. It is a presentational surprise, not a privilege one — the
trust gate does not care where in the body the form sat.

### If the scanner gives up, nothing is sent

A body large enough to exhaust PCRE's JIT stack makes `preg_replace_callback()`
return null, and the command **fails closed** with a notice naming the byte
length and the PCRE error, rather than falling back to the raw body. An unscanned
body's `` !`…` `` and `@…` forms would otherwise reach the model as literal
instructions.

(The pattern's directory component is a flat `[\w.\-\/]+` rather than a nested
`(?:[\w.\-]+\/)*` for exactly this reason: measured on this host, `@` followed by
`"a/"` × 25000 exhausted the JIT stack under the nested spelling and scans
cleanly under the flat one. One measured narrowing came with it — `@a.b/c` used
to match its `a.b` prefix and is now left literal.)

---

## The project-tier trust gate

`` !`cmd` `` is gated by tier; `@file` is not:

- **`@file`** is a bounded read confined to the checkout, for both tiers. Same
  boundary as the `*.md` walk, for the same reason.
- **`` !`cmd` ``** runs a shell, so the tier decides. A **user**-tier command is
  your own file — as much yours as `~/.bashrc` — and runs subject only to the
  launch's `PermissionGate`. A **project**-tier one arrived in a `git clone`, so
  it additionally requires:

```json
{ "trustedProjectCommands": ["/home/you/src/myproject"] }
```

Untrusted, the form is replaced by a refusal notice quoting the command (clipped
to `MAX_QUOTED_FORM_BYTES` = 200, so a refusal cannot cost more context than the
substitution it declined). It is not silently dropped: the model is told a form
was refused rather than shown a prompt with a hole in it.

`Chat::refuseCommandShell()` checks the **tier first, the gate second**, and the
order is substantive: `PermissionGate::evaluate()` mutates `auto` mode's
circuit-breaker counters, and a command refused by the tier rule was never going
to run, so it must not move a counter a real call is judged by.

**Only `Deny` refuses; an `Ask` proceeds.** Template expansion happens inside
`submit()` with no prompt available, and a caller that cannot show the blocking
prompt must not turn "would have asked" into "no". The cost, stated rather than
hidden: in the shipped default mode, which answers `Ask` for `Bash`, a
`` !`…` `` in an authorised command file runs **without a prompt**. What makes
that acceptable is that authorisation is the first check — it is either your own
file or a checkout you explicitly trusted.

See [`PERMISSIONS.md`](PERMISSIONS.md#the-four-trustedproject-keys) for the
properties this key shares with `trustedProjectHooks`, `trustedProjectMcp` and
`trustedProjectSettings`.

---

## The 22 built-in commands

For reference, since a custom command can override any of them but the seven
control-plane names:

`new` `sessions` `model` `share` `docs` `exit` `theme` `agents` `mcp` `keys`
`help` `compact` `clear` `budget` `workflow` `memory` `branch` `rename`
`rewind` `bg` `fork` `websearch`

`new` and `docs` are palette-only (`slashVisible: false`) — they are reachable
from Ctrl+P, not from the "/" popup. `/agent` is a second spelling of `/agents`.
`/quit` is a second spelling of `/exit` with no registry row of its own.

## See also

- [`SKILLS.md`](SKILLS.md) — the other markdown-plus-frontmatter surface, for
  content the *model* invokes rather than you.
- [`HOOKS.md`](HOOKS.md) — the other place a config file names a shell command.
