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
| built-in | `CommandRegistry::all()` | n/a, they are PHP |
| user | `~/.sugar-crush/commands/*.md` | yes |
| project | `<root>/.sugar-crush/commands/*.md` | **only if this root is trusted** |

**A file's path under the commands directory is its name.** `test.md` gives
`/test`; `deploy/staging.md` gives `/deploy/staging`. Subdirectories namespace,
and the walk is capped at `CommandLoader::MAX_DEPTH` levels deep — which also
bounds a symlink cycle inside the directory.

A project file **may override a built-in** by name, and does: `Chat` keeps the
merged map minus everything that is still a built-in row, so a file-based
override survives the filter while the built-ins stay reachable through their own
dispatch arms (keeping both would list every built-in twice in the "/" popup).

### Except the control plane

Every name in `CommandRegistry::CONTROL_PLANE` is taken back whatever a file
says: `budget`, `clear`, `exit`, `help`, `model`,
`permissions`, `quit`. These are how you drive and leave the application, and a
cloned repository redefining `/exit` is not a thing that should be possible. A
refused file is recorded on `CommandLoader::refusedCommands()` and reported at
launch — keyed by command *name*, deliberately separate from the directory
refusals, whose keys are paths a reader prints for you to go and open.

(`permissions` is the one name on that list this page used to call reserved but
unbuilt — "not a row in `CommandRegistry::all()`", "typing `/permissions` today
does nothing". That is no longer the shipped state: the name now has both, and
typing it prints the session's permission mode, where that mode came from, and
every rule the gate decides by, in evaluation order. The argument is not parsed
— the report is total, so each spelling gets a superset of what it asked for
rather than a "no such subcommand", which is why `permissions` sits below the
bare-name commands in `Chat::dispatchCommand()` and not with them. The
reservation earns its keep now for exactly the reason it was made: that report
is how you check what a cloned repository has made possible, and a file that
could replace it could falsify the check.

`quit` is the asymmetry now. It is reserved and it is dispatched — an arm in
`Chat::dispatchCommand()` — but it has no row of its own, so nothing advertises
it. A file named `quit.md` still loses the name: `CommandLoader::loadAll()`
takes back every reserved name, restoring the built-in row where one exists and
unsetting the name where none does.)

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
*three* alternation branches of `CommandSpec::TEMPLATE_PATTERN` — the
first branch, `$(\$|ARGUMENTS|[1-9])`, spells three of the five on its own — so
neither "three" nor "four" describes this table. (That same constant's own
docblock says "all four template forms"; that count is wrong in the source too.)

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
- **One `CommandSpec::SHELL_BUDGET_SECONDS` budget is shared by ALL of an
  expansion's forms**, not per command. A per-command bound multiplies: sixty
  `` !`sleep 30` `` forms with a ten-second per-command timeout wedges the
  single-threaded TUI for ten minutes, and "wedged" means the frame does not
  repaint and Ctrl+C is not read. Forms arriving after the budget is spent are
  refused with a notice naming the budget, not silently dropped.
- The budget is **not operator-configurable**, deliberately, and that is the
  opposite of the rule for a provider HTTP call: a completion legitimately runs
  for tens of minutes; this is a local command holding the terminal.
- Output is capped at `CommandSpec::MAX_SUBSTITUTION_BYTES` **per substitution**,
  and the clip announces itself so a truncated substitution reads as truncated to
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
prompt text. It is capped at the same `CommandSpec::MAX_SUBSTITUTION_BYTES`.

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
to `CommandSpec::MAX_QUOTED_FORM_BYTES`, so a refusal cannot cost more context than the
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

## The built-in commands

For reference, since a custom command can override any built-in except the names
`CommandRegistry::CONTROL_PLANE` reserves. The rows below are
`CommandRegistry::all()` in that method's order — a command appears here because
the registry defines it and not because anyone counted them — and the two marker
columns are the same two derivations read off the same source: **S** marks a row
`CommandRegistry::slashCommands()` advertises, **CP** marks a reserved name.
*Takes* is the row's own `argumentHint`, verbatim where it has one and `—` where
it does not; the *What the row says* column is its `description`.

| Command | S | CP | Takes | What the row says |
|---|---|---|---|---|
| `/new` | | | — | Start a fresh session |
| `/sessions` | ✓ | | — | List all sessions |
| `/model` | ✓ | ✓ | `[provider]` | Switch the active model provider |
| `/share` | ✓ | | `[format] [expiry]` | Share the current session |
| `/docs` | | | — | Open the documentation |
| `/exit` | ✓ | ✓ | — | Quit the app |
| `/theme` | ✓ | | — | Switch the color theme |
| `/agents` | ✓ | | — | List active agents, or inspect one by name |
| `/mcp` | ✓ | | `<list\|add\|remove> [server]` | Manage MCP server auth (list/add/remove) |
| `/keys` | ✓ | | — | Show the keyboard shortcut reference (or press ?) |
| `/help` | ✓ | ✓ | — | List every slash command |
| `/permissions` | ✓ | ✓ | — | Show this session's permission mode, its source, and the rules it decides by |
| `/notices` | ✓ | | — | Show every warning this launch raised, un-capped and un-aggregated |
| `/rules` | ✓ | | `[name]` | List the rule packs, or toggle one for this session |
| `/compact` | ✓ | | — | Manually compact chat history to save context |
| `/clear` | ✓ | ✓ | — | Clear the transcript, keeping this session |
| `/budget` | ✓ | ✓ | `[amount\|off]` | Show this session's reported spend, or cap it |
| `/workflow` | ✓ | | — | Run, pause, resume, or inspect a workflow |
| `/memory` | ✓ | | — | Add, list, search, edit, import, or clear memory entries |
| `/branch` | ✓ | | — | Fork the current session into a new branch |
| `/rename` | ✓ | | `<name>` | Rename the current session |
| `/rewind` | ✓ | | — | Restore chat state from an earlier checkpoint |
| `/bg` | ✓ | | `<task>` | Run a task in a background session |
| `/fork` | ✓ | | `<prompt>` | Clone this conversation into a background session |
| `/websearch` | ✓ | | `<query> [--safesearch 0\|1\|2] [--time-range day\|month\|year]` | Search the web via SearXNG |

**S** is blank on `new` and `docs` alone: they are palette-only
(`slashVisible: false`), reachable from Ctrl+P and from no "/" popup. They have
the other asymmetry too — `Chat::dispatchCommand()` carries no arm for either, so
a typed `/new` is a prompt to the model. The Ctrl+P palette and the draft box are
two different surfaces over one registry, and only one of them dispatches by
match arm.

One row's description understates its handler. `/memory`'s text names every
sub-action but one: `Chat::handleMemoryCommand()` also answers `delete`. The
table quotes the row rather than the handler because the row is what `/help` and
the "/" popup show you — but the command you can type is the handler's list, and
[`MEMORY.md`](MEMORY.md) documents that surface, `import` included.

Three spellings dispatch with no row of their own, so nothing advertises them:
`/agent` for `/agents`, `/background` for `/bg`, and `/quit` for `/exit`. The
first two are second names the old prefix chain had already made reachable and
that stay reachable; `quit` is the control-plane case above.

Two guards apply to every arm, and both fall through to the model rather than
guess. A draft must begin with the canonical spelling verbatim, so `/KEYS` is
prose and `/compactfoo` is a prompt about foo rather than a mistyped `/compact`.
And five commands — `exit`, `quit`, `keys`, `help`, `clear` — fire only when the
draft is exactly `/name`, because `/help me name this variable` is a request and
not a command list. `permissions` sits below that block deliberately despite
looking like its neighbour, and `rules` too: mis-routing those two is worse than
answering a question loosely, because `/permissions rules` would ask the model
about a gate it cannot see and answer plausibly, and `/rules terse` would never
reach the toggle.

One name reaches a handler with no leading slash at all: a draft starting
`mcp auth` is routed to `Chat::handleMcpAuthCommand()` ahead of the parse, because
that spelling predates the discoverable `/mcp` row and the palette's MCP toggle
still uses it.

What keeps the table honest is not this page — no guard counts the rows here. It
is `Commands\SlashDispatchTest::testEverySlashVisibleRegistryRowHasALiveDispatchHandler()`,
which fails if a row `CommandRegistry::slashCommands()` advertises has no arm to
answer it. The reverse direction, an arm with no row, is exactly the three
aliases above and is deliberately allowed.

---

## The `/rules` command

`/rules` is the built-in that edits what the model is told rather than what the
transcript shows, and it has the smallest surface that does that:

| Spelling | Effect |
|---|---|
| `/rules` | lists every toggleable pack, in `Pack` / `State` / `Source` columns |
| `/rules <name>` | flips that pack for the rest of the session and reports `ON` or `OFF` |

There is no `list`, `enable` or `disable` sub-action. A bare `/rules` *is* the
listing and `/rules <name>` *is* the enable-or-disable, the direction decided by
the pack's current state — `RulesState::toggle()` returns which way it went, so
the command never has to ask twice and cannot report the wrong word for the
action it took. Only the first token is read, and a second one is an error rather
than something to ignore: quietly dropping the stray would make `/rules terse r`
report a successful toggle of a pack called `terse r`. An unknown name says
`Unknown rule pack:` and prints what is available.

**A pack is a file, and its name is the filename.** Every `*.md` under
`~/.sugar-crush/rules/` and every `*.md` under `~/.sugar-crush/rulebooks/` is a
pack, identified by the filename minus its extension (`RuleLoader::ruleKeyFor()`
— `focus.md` is `focus`, `style/terse.md` is `style/terse`). There is no index or
registry to keep in step: a file that appears is a pack the next listing shows.
`Source` tells you which of the two directories a name came out of, which you
want because the same stem in both is two packs under one name — one
`/rules <name>` flips both, and the report says so rather than printing a
singular sentence about an action that just silenced two files. The listing is
built from `RuleLoader::loadUserRules()` and `RuleLoader::loadUserRulebooks()`
rather than from `load()`, on purpose: `load()` returns enabled rules only, so a
pack that is off would vanish from the one command whose job is to switch it back
on.

**Only the user tier is toggleable** (`RulesState::TOGGLEABLE_TIER`). Both
directories above are ones the operator of this machine chose, so `/rules` may
silence either. `<root>/.sugar-crush/rules` and `<root>/RULES.md` are the
repository's voice, and a session-scoped set of names is not a place to grant or
withhold a repository's authority — that decision belongs to the trust gate on
this page. The cost is symmetric and you should expect it: a project rule whose
filename collides with a pack name is not silenced by `/rules` with that name.

**Frontmatter wins.** A pack is in the prompt when its own frontmatter says it is
enabled *and* this session has not turned it off, and that conjunction is
computed in one place, `RulesState::effectiveRule()`, so a listing can never
promise bytes the prompt does not deliver. Hence the three states the listing
prints: `on`, `off (session)`, `off (frontmatter)`. Toggling the third kind flips
the session bit and leaves the pack out of the prompt, and says as much.

**The effect is session-scoped: `/rules` writes nothing.** No `config.json`, no
settings file, no config-change callback anywhere on the path — the listing's own
header is "Rule packs (session only — nothing here is written to config)", and
`RulesCommandTest::testTogglingAPackLeavesTheConfigFileByteIdentical()` fails if
that ever stops being true. A restart therefore restores every pack to what its
frontmatter says. Within a session the change takes effect from the next turn,
which is what the report line means by "from the next turn onward".

For the persisted side of the same surface — the user-tier `disabledRules` key
that starts a session with named packs already off, and the shape its value has
to take — see
[`SETTINGS.md`](SETTINGS.md#which-keys-are-layered-at-all), which is also where
the rule that `disabledRules` is not layered like most keys is written down. This
page documents the command; that one documents the config. Nothing here is
restated from it.

## See also

- [`SKILLS.md`](SKILLS.md) — the other markdown-plus-frontmatter surface, for
  content the *model* invokes rather than you.
- [`HOOKS.md`](HOOKS.md) — the other place a config file names a shell command.
- [`SETTINGS.md`](SETTINGS.md) — `disabledRules`, the persisted counterpart to a
  `/rules` toggle.
- [`MEMORY.md`](MEMORY.md) — `/memory`'s sub-actions, including `import`.
- [`PERMISSIONS.md`](PERMISSIONS.md) — the gate `/permissions` reports on.
