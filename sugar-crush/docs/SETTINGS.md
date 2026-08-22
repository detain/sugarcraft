# Settings

Four files can contribute a setting. Two of them belong to you, two of them
arrive with a `git clone` — and that split is what every rule on this page is
about.

Everything here is implemented by `SugarCraft\Crush\Config\LayeredSettings`
and read through `Bootstrap::readUserConfig()`.

## The four layers

Lowest precedence first. On a key present in more than one file, the **highest
listed** file wins.

| # | File | Whose | Read when |
|---|---|---|---|
| 1 | `<root>/.sugar-crush/settings.json` | the project's, meant to be committed | the root is listed in `trustedProjectSettings` **and** an entry point named it (see below) |
| 2 | `<root>/.sugar-crush/settings.local.json` | the project's, meant to be `.gitignore`d | same gate as #1 |
| 3 | `~/.sugar-crush/settings.json` | yours, hand-authored | whenever `$HOME` resolves and passes the ownership check |
| 4 | `~/.sugar-crush/config.json` | yours, partly **written by the CLI** | always — but see the two qualifications below |

**Layers 1 and 2 need a project root to have been *named*, not just trusted.**
`readUserConfig()` takes no `$root` argument and deliberately does not fall
back to `getcwd()` — the root is remembered by whichever entry point resolved
it (`chat()`, `app()`, `backend()`, `backendFor()`, via
`Bootstrap::useProjectRootForSettings()`). A subcommand that never resolves a
root — `sugarcrush models`, say — is **user-tier only even inside a trusted
checkout**. That is deliberate: deriving the root from the working directory
would take settings from wherever you were standing rather than from the
repository `--root` named.

**Layer 4 is not always `~/.sugar-crush/config.json`.** Two things move it:

- `--config <path>` repoints it (`Bootstrap::userConfigPath()`), and it moves
  **that file only** — layer 3 stays in `~/.sugar-crush`, deliberately, so a
  repository shipping a `crush.json` and a README saying
  `sugarcrush --config ./crush.json` cannot hand itself the user tier.
- when the home directory cannot be established at all, `HomeDirectory::path()`
  falls back to the system temp directory, so layer 4 silently becomes
  `<tmpdir>/.sugar-crush/config.json`. A real launch refuses before reaching
  that (`trustedConfigDirPath()` throws), so it only bites direct
  `readUserConfig()` callers such as the per-turn read.

Layer 4 is also only *partly* CLI-written: exactly two keys are ever written
there (`provider` and `theme` — see below). Everything else in it, including
`trustedProjectSettings`, you hand-author.

Two orderings on that table are deliberate and both cost something:

**Your files outrank the project's** — the reverse of the convention most
editors use. Layer 1 arrived with a clone. If it outranked you, a checkout
could change a setting you had already made for yourself, and your own file
would look broken. A project can only fill in what you left unsaid.

**`config.json` outranks `settings.json`.** `config.json` is the *older* of
the two names — nothing in `src/` marks it deprecated and it is still the only
file the CLI writes back to. It has to be the file read back. The other way
round, a `settings.json` naming `theme` would outrank what `/theme` just
wrote: the theme would repaint immediately (`/theme` mutates the live `Chat`)
and then silently revert on the next launch, with no error and nothing
pointing at the file responsible. What breaks is *persistence*, not the
visible command.

Exactly two keys are ever written there, and **each has two producers — a
palette row and a slash command**: `provider` (the Ctrl+P palette's "Switch
Model" row, and `/model <name>`) and `theme` (the palette's "Switch Theme" row,
and `/theme <name>`). Those are the only two values `Chat`'s `onConfigChange`
callback is invoked with, and `Bootstrap` wires that callback to
`writeUserConfig()` at one site.

The `/model` half is easy to miss and this page missed it: `Chat`'s
`handleModelCommand()` ends in `selectPaletteProvider()`, which is the *sole*
site that invokes `onConfigChange('provider', …)`. The palette row and the
command are not two writers — they are one writer with two doors, which is also
why a `/model` choice persists exactly as a palette choice does.

**`settings.local.json` gets the same gate as its tracked sibling.** The name
says "local" and `.gitignore` says it is not committed, but neither is a
property of a repository *someone else* wrote: `.gitignore` is advice to
whoever commits, `git add -f` overrides it, and a hostile checkout ships a
`settings.local.json` as readily as a `settings.json`. The two differ in
precedence only.

## Opting a project in

Layers 1 and 2 are ignored entirely until you list the project root in your
own layer-4 config:

```json
{ "trustedProjectSettings": ["/home/you/src/that-project"] }
```

**Put it in the file `--config` actually names.** The trust list is read
through `Bootstrap::permissionConfigLayers()`, which honours
`$configPathOverride` — so under `--config alt.json` a
`trustedProjectSettings` sitting in `~/.sugar-crush/config.json` is never
consulted, and the project layer contributes nothing. Measured both ways.

It will **not** work from `~/.sugar-crush/settings.json` either:
`permissionSettingsLayer()` filters that file down to `permissionMode` and
`permissionRules` before the merge, so a trust key there is dropped. (The one
exception is `--config` pointed *at* your own `settings.json`, which collapses
the two layers and reads the whole file.)

This is the fourth of the four `trustedProject*` grants; the shared
properties — absolute paths only, read once per process and frozen, refused
loudly — are documented once, in
[`PERMISSIONS.md`](PERMISSIONS.md#the-four-trustedproject-keys), along with the
one behaviour this key does **not** share with the other three.

## Which keys are layered at all

A key not in this list is answered by layer 4 alone **through this stack**,
exactly as it was before layering existed. This is a security boundary, not a
scoping shortcut: it is what stops a project file from writing
`permissionMode`, `permissionRules`, any `trustedProject*` key — or
`trustedProjectSettings` itself, so a project cannot add itself to the list of
trusted projects.

"Through this stack" is load-bearing rather than hedging. `permissionMode` and
`permissionRules` are answered by layers 3 **and** 4 — just not by
`LayeredSettings`. They travel `Bootstrap::permissionConfigLayers()`, which
stacks `~/.sugar-crush/settings.json` beneath `config.json` with the same
later-wins ordering. Measured: `{"permissionMode":"plan"}` in
`~/.sugar-crush/settings.json` with **no `config.json` at all** resolves the
gate to `plan`. What no lower layer can reach is the *project* tier, which is
the property this section is really about.

**An empty value does not override.** `""` and `null` are how a key is spelled
when nothing is being set there, so `permissionConfigLayers()` drops such a
value out of the later layer *before* the merge rather than letting it displace
what an earlier layer set. Measured: `{"permissionMode":"plan"}` in
`~/.sugar-crush/settings.json` with `{"permissionMode":""}` in `config.json`
resolves the gate to `plan`, and the ignored key is reported on stderr naming
both files. Before that filter existed the same pair resolved to the built-in
default, silently — and the same shape dropped a `permissionRules` `deny` that
`settings.json` had configured.

Only those two spellings count as empty, and the narrowness is the point:
`"permissionMode": "  "` names no mode and still refuses the launch by name,
and `"permissionRules": []` is a well-formed empty list that still outranks
`settings.json` under the later-wins rule above.

| Key | Read by | Project may set |
|---|---|---|
| `provider` | `Bootstrap::selectedProviderName()`, `backend()` | **no** |
| `instructions` | `Bootstrap::forcedInstructions()` | **no** |
| `allowedTools` | `Bootstrap::tools()` → `filterToolSet()` | **no** |
| `theme` | `Bootstrap::chat()` | yes |
| `titleModel` | `Bootstrap::titleBackend()` | yes |
| `summaryModel` | `Bootstrap::summaryBackend()` | yes |
| `disabledSkills` | `Bootstrap::chat()` → `skillRegistry()` | yes |
| `disabledTools` | `Bootstrap::tools()` → `filterToolSet()` | yes |
| `parallelToolCalls` | `EngineBackend::complete()` | yes |
| `parallelToolDeadlineSeconds` | `EngineBackend::complete()` | yes |
| `statusLine` | `Bootstrap::chat()` → `StatusLineCommand::fromSettings()` | **no** |

Every key in that table has a real reader named beside it, and the table is
COMPLETE — `LayeredSettings::LAYERED_KEYS` is exactly these eleven, and the
"Project may set" column is exactly `PROJECT_TIER_KEYS`. Both halves are
asserted by `TrustKeyDocumentationDriftTest`, so a key added to either constant
without a row here reds rather than drifting. A key nothing reads is worse than
a missing one, because it looks configurable.

Where a row names two methods, the first is the public entry point and the
second is the method that does the read — cited because that is the one to
grep for. It is a private method on `Bootstrap` in every row but `statusLine`,
where the read lives in another class (`StatusLineCommand::fromSettings()`,
public because the runner is testable without a launch). The previous revision
of this row named `StatusLineCommand::fromSettings()` first and
`Renderer::renderStatusBar()` second, and neither half fitted the convention:
nothing calls `fromSettings()` on a launch except `Bootstrap::chat()`, and
`renderStatusBar()` does not read the settings key at all — it reads the
already-cached process line.

**`statusLine` runs a command, which is why it is user-tier only.** The shape
is Claude Code's, so a settings file written for that tool carries over:

```json
{"statusLine": {"type": "command", "command": "git branch --show-current"}}
```

The command's stdout becomes one extra segment of the status bar. A project's
`.sugar-crush/settings.json` may **not** set it, at any trust level — that
would be arbitrary code execution on clone-and-launch, on a timer, with no tool
call and no permission gate in the path. It is the strongest case of the
argument `provider` and `instructions` already rest on.

What it costs, stated because the command runs on the TUI's own thread:
`StatusLineCommand::REFRESH_SECONDS` (2.0) between runs, and a hard
`StatusLineCommand::TIMEOUT_SECONDS` budget per run — derived as half the
refresh period, so two runs can never overlap — after which the child is
SIGTERMed and then SIGKILLed. A run that times out, exits non-zero, prints
nothing, or writes more than `StatusLineCommand::MAX_OUTPUT_BYTES` (16 KiB)
blanks the segment rather than leaving stale text up — note that the byte cap
BLANKS, it does not paint the first 16 KiB. (This paragraph previously said
output was "capped at `MAX_OUTPUT_BYTES`" and then clipped, which described a
pipeline that does not exist: `exec printf "%020000d" 0` exits 0 and paints
nothing, while the same command at 16000 bytes paints all 16000. Measured at
db20c568 on PHP 8.3.6.)

What a run that does finish gets: its stdout stripped of ANSI and control
bytes, made valid UTF-8 (malformed bytes become U+FFFD), collapsed to one line
(a newline would make the bar wrap, and the bar is the one row that must not),
and clipped to the terminal width by a grapheme-aware measure. stderr is
drained so it cannot deadlock the read, and discarded.

**There is no top-level `model` key**, and it is the one name people look for.
Nothing reads one. The two model-shaped keys that exist are `titleModel` and
`summaryModel`.

**No model is persisted anywhere.** The session's model comes from the provider
(and from `--model`), and it does not survive a restart: the Ctrl+P action
called "Switch Model" writes `provider`, not a model. Only `provider` and
`theme` are ever written to `config.json`. If you want a specific model on
every launch, name it on the command line or in the provider's own config —
there is no settings key that will do it.

**`permissionMode` and `permissionRules` are readable from
`~/.sugar-crush/settings.json`**, but not through this stack. They go through
`Bootstrap::permissionSettingsLayer()`, which uses the STRICT reader — the one
that refuses to start on a policy file it cannot parse — while this class's
reader is tolerant by contract, treating a malformed file as an absent layer.
Routing the permission keys through the tolerant reader would have handed a
stray comma the power to silently downgrade a session to the permissive
default.

### Why `allowedTools` is user-tier only when `disabledTools` is not

On capability alone the two look equally safe: both only ever shrink the tool
set, neither can add anything. The difference is shape.

A whitelist is defined by what it OMITS, so it is the one form in which a
small, innocuous-looking value deletes almost everything.
`allowedTools: ["Bash"]` removes `Read`, `Edit`, `Write`, `Grep`, `Glob`,
`WebFetch`, `WebSearch`, `doctor`, `Skill` and `Lsp` in one line — and what the
model does next is not less work, it is the *same* work through `Bash`, which
reaches the permission gate as opaque shell text instead of as a reviewable
path. Strictly fewer tools, strictly coarser review.

**A previous version of this page claimed `disabledTools` can only express that
attack "by naming every tool it removes — a value you can see when you read the
file". That is false.** `Bootstrap::filterToolSet()` matches names with
`PermissionRule::matchesToolName()`, which is bare `fnmatch()`, and `fnmatch()`
honours negated character classes. Measured end-to-end **on PHP 8.3.6,
2026-08-22** — and **PHP 8.4 was not exercised**, because this box has only
8.3.6 while CI runs both. The version and the date matter here only as
provenance: `fnmatch()`'s handling of `[!…]` is not version-sensitive, negated
classes are not a recent addition, and no ICU is involved. They are recorded
anyway because an undated figure is how a PHP-8.3-only defect once got written
down as unconditional. Both claims on this page also have live generators
rather than only a date, and they are **two different generators** because they
are two different claims: `Tests\Config\ReadmeSettingsTierClaimTest` re-derives
the *tool set* the glob leaves, and `Tests\Config\GlobFigureDriftTest` re-derives
every *character count* below — including the ones inside the retraction — from
`strlen()` of the glob this page quotes. (That sentence used to name the first
test alone as the generator for both. It is not: it holds the glob as a class
constant and never measures its length, so the count it was credited with
keeping fresh was in fact unpinned on this page and in
`src/Config/LayeredSettings.php` at the same time. The retraction is kept
because "there is a generator" is exactly the claim a reader stops checking.)
In a project you have listed
under `trustedProjectSettings` (an untrusted project's `disabledTools` never
reaches the merge at all, and all eleven tools survive):

```json
{ "disabledTools": ["[!B]*"] }
```

Five characters of glob, in a key a project **is** allowed to set, leaving
exactly `Bash` and removing everything else — the same tool set
`allowedTools: ["Bash"]` produces, and the same degradation to opaque shell
text. (This line said "eight characters" until the count was re-derived:
`[!B]*` is five, `"[!B]*"` seven, `["[!B]*"]` nine, and nothing here is eight.
The point the figure was making — that the value names none of the ten tools it
removes — is what survives, so the sentence stays and the number is corrected.
This line once continued
"`src/Config/LayeredSettings.php` and `Bootstrap::reportProjectTierToolRemovals()`
still carry the old figure in their doc-blocks", and half of that is no longer
true: `LayeredSettings` was corrected in round 43 and now carries the retraction
rather than the figure. **`Bootstrap::reportProjectTierToolRemovals()` is the one
remaining site**, in the sentence about refusing negated character classes. The
naming is kept rather than dropped because a list of known-stale copies is the
only thing that makes the next one findable — and it is now asserted rather
than believed: `Tests\Config\GlobFigureDriftTest` censuses `src/` for paragraphs
that spell the old count without retracting it, and reds both if a new one
appears and if this sentence names a file that has since been fixed. A THIRD
occurrence was in this file, in the paragraph beginning "The report is the
*effect*, not the pattern" — located by its words rather than by "sixty lines
below", because an offset in prose is the same rot one level up — and is
corrected; a page that re-derives a number and then contradicts itself further
down is worse than one that never re-derived it.)

**Two things narrow this, and both are measured.** An *untrusted* project's
`disabledTools` never reaches the merge — all eleven tools survive — so this
needs a `trustedProjectSettings` grant you made yourself. And the layers merge
**key by key, not as a union**: if *you* name any `disabledTools` at all, yours
replaces the project's entirely. Measured: your `["Read"]` against a trusted
project's `["[!B]*"]` removes exactly `Read` and leaves everything the
project's glob named. The gap is open only for an operator who trusted a
repository and set no `disabledTools` of their own.

So the *shape* argument does not hold on its own; the ceiling argument below is
what the split actually rests on. **A trusted project's `disabledTools` can
choose your tool set, and that has not changed** — do not trust
`trustedProjectSettings` on a repository you would not trust with
`allowedTools`.

**What has changed is that it can no longer do so unnoticed.** A trusted
project's tool removals are reported at launch, naming the file, the tools it
took and the tools it left:

```
sugarcrush: /repo/.sugar-crush/settings.json (disabledTools) disabled 10 of the
11 tools your own settings left — Read, Edit, Glob, Grep, Write, WebFetch,
WebSearch, doctor, Skill, Lsp — leaving: Bash.
```

That is the stderr form, byte for byte. The `sugarcrush: ` prefix and the
trailing full stop are added by `Bootstrap::warnPermissionConfig()`, not by
`Bootstrap::reportProjectTierToolRemovals()`, so the **transcript** row two
paragraphs down carries the same sentence WITHOUT either of them — the message
the reporter builds ends at `leaving: Bash`. (This block used to be printed
here without the full stop, which made it neither form.)

**In two places, because one of them you cannot read.** The line above goes to
stderr, which is the right channel for `-p` and for the scrollback you get back
after quitting — and which an interactive session cannot show you. Measured on
a real launch under a pty: it printed **0.47 s** before the terminal entered the
alternate screen, and replaying the captured byte stream through a virtual
terminal found no trace of it on the visible screen. The alternate buffer had
painted over it, and the primary buffer does not come back until the session
ENDS — so the warning that your tools had been cut to `Bash` arrived after the
`Bash`-only session was over.

So the same sentence is also seeded into the **transcript**, as a system row,
before the first frame paints. That is the copy an interactive operator reads;
the stderr copy is unchanged and is not going away. Note that the transcript
copy is part of the conversation, so the model is told as well — which is the
honest state of affairs, since the model is the party whose tools were taken.

The three warnings this paragraph used to name as still-stderr-only — an
unusable provider, a skipped hook file, a rejected permission pattern — have
since migrated through the same seam, along with the agent-preset degradations,
the refused project directories, the skipped skill files and the empty tool set:
**fifteen** call sites in total (`grep -c 'self::warnPermissionConfigInTranscript('
src/Cli/Bootstrap.php`). The rule that decided the split is on
`Bootstrap::warnPermissionConfigInTranscript()` — a warning earns a transcript
row iff it names something **the session can no longer do**. Warnings that
report a malformed config entry without the session being diminished
(`trustedProjectHooks[2] is not a project path`, `permissionMode in config.json
is empty so it was ignored`) stay on stderr, because a transcript row per bad
config entry is how a useful notice becomes a wall you scroll past.

**What this paragraph used to say, and what changed.** The count above read
*fourteen*, and the stderr-only list above named a third example: `retention
removed 3 sessions`. Both are now wrong, and the second one was the more
interesting mistake — it was the one entry on that list whose subject was data
the launch had **destroyed** rather than a setting it had declined to honour.
Read against the rule the list is decided by, a prune does name something the
session can no longer do: those conversations cannot be resumed, branched,
renamed or rewound, and `/resume` and `sugarcrush session list` come back
shorter than the user left them. So the **summary** migrated in round 42 and is
now the fifteenth call site. The reason the example still earns a mention is the
*half* of it that did not move: the **per-session ids** stay on stderr alone,
because one row per deleted session is exactly the per-entry fan-out into a list
re-sent to the model every turn that the cap below exists to refuse. The
transcript gets `retention removed 3 unnamed sessions untouched for 30+ days
(ids on stderr)`; stderr gets that line and the ids.

The transcript copy is capped — 24 rows and 400 characters per row, see
`Bootstrap::LAUNCH_NOTICE_LIMIT`. The stderr copy is never clipped and never
capped, so an overflowed launch says so in the transcript and points at the
channel that has the rest.

The report is the *effect*, not the pattern, and that is deliberate. Refusing
negated classes at the project tier would close the five-character version and
nothing else: `["[C-Z]*", "[a-z]*"]` uses no negation, is barely longer, and
also leaves only `Bash` — measured. Restricting the tier to literal names would
close it, at the cost of the use the key was admitted for (a checkout saying
"there is no git server here, stop offering `mcp__git__*`"), and at the cost of
a capability the *operator* granted rather than one an attacker took.

Only the removals a project **actually made** are reported, which follows from
the key-by-key merge above: if your own `disabledTools` displaced the project's
list, the project removed nothing and nothing is said. Re-matching the
project's patterns instead would announce removals that never happened, in
exactly the case where you had already protected yourself.

**There is no floor either, and that is also unchanged.**
`disabledTools: ["*"]` leaves zero tools. The direction is fail-safe, and
`Bootstrap::filterToolSet()`'s doc-block names that spelling as the supported
way to ask for a toolless agent (it is the stated alternative to reading
`allowedTools: []` that way), so it is reported rather than refused — but it is
reported, not handed over in silence.

**That sentence is written about YOU, and the code applies it to a trusted
project as well.** `disabledTools` is a project-tier key, so `["*"]` in a
checkout's `settings.json` reaches the same branch and yields the same empty
tool set — a "supported way to ask for a toolless agent" that the repository,
not the operator, asked for. It is deliberately left that way: both warnings
fire (the removal report names the file, and the empty-set report follows), and
reaching the branch at all needs your own `trustedProjectSettings` grant. But
the justification's authority comes from it being *your* choice, so do not read
it as covering the project tier by itself — the trust grant is what covers that.

And a whitelist is what you reach for when you want a *ceiling*; a ceiling a
checkout can rewrite is not one. That holds by conjunction rather than by
ordering: `Bootstrap::filterToolSet()` keeps a tool if and only if the
allow-list admits it AND the deny-list does not name it, in one expression, so
there is no later stage at which a project's `disabledTools` could re-admit
what your `allowedTools` excluded.

## When a change takes effect

The settings files are **re-read every turn** — `EngineBackend::complete()`
calls `readUserConfig()` once per turn, so all four are opened again each time.

**Re-read is not the same as re-applied**, and only two keys actually change
behaviour mid-session. That per-turn read feeds exactly two settings —
`parallelToolCalls` and `parallelToolDeadlineSeconds` — so writing
`.sugar-crush/settings.local.json` mid-session changes those on the next turn
and nothing else. Every other key is consumed once, while `Bootstrap` builds
the session: `disabledSkills` is read by `Bootstrap::skillRegistry()` at launch
(and again on a Ctrl+P skill switch), `theme` and `provider` when the `Chat` is
constructed, `allowedTools`/`disabledTools` when the tool set is assembled.
Changing any of those means restarting.

The **trust list is not**: it is frozen for the life of the process, so a
project cannot become trusted mid-session. The asymmetry is deliberate — you
opted the repository in, and the project tier cannot reach `provider`,
`instructions` or any permission key — but "trusted, frozen" would otherwise
read as covering both halves.

## When a file is ignored

Every one of these is silent **as a settings read**, because a settings file is
not a permission policy and the tolerant reader is what lets a half-written
file cost you a setting rather than your session. Your OWN two files — layers
3 and 4 — are the exception, and it is a big one: see "the loud half" below.

- the file does not exist, cannot be read, is not valid JSON, or is valid JSON
  that is not an object;
- the project root is not listed in `trustedProjectSettings`;
- `$HOME` cannot be resolved, or the home directory fails the ownership check —
  and this drops **layers 1, 2 and 3**, not layer 3 alone. `readUserConfig()`
  loses layer 3 because `userSettingsDirOrNull()` returns `null`, and it loses
  the project layers too because `projectSettingsTrusted()` reaches
  `trustedConfigDirPath()` to find the trust list, catches the same throw, and
  returns `false` — which makes `projectLayer()` return `[]`. Only layer 4
  survives, since `--config` may have pointed it somewhere else entirely;
- the project's `.sugar-crush` directory is not actually inside the project
  root (a symlink pointing out of the checkout, say) — containment is checked
  on the directory and again on each file;
- for a **project** file: the key is not in the layered list, or not in the
  project-tier subset. (For layer 4 an unlisted key is *not* ignored —
  `merge()` passes your own config through unfiltered, which is exactly what
  "answered by layer 4 alone" above means.)

### The loud half

Both of your own files are read a **second** time, by a second reader.
`Bootstrap::permissionConfigLayers()` builds the permission stack from
`~/.sugar-crush/config.json` *and* `~/.sugar-crush/settings.json`, routing both
through `readPolicyFile()` — the STRICT reader. So the bullets above describe
layers 1 and 2 completely, and layers 3 and 4 only as far as the *settings*
read goes.

Every one of these **refuses the launch** with `PermissionConfigException`,
for either of your two files:

- it is not valid JSON, or is valid JSON that is not an object (a top-level
  list refuses);
- it is world-writable, or owned by another uid — and the same check runs on
  `~/.sugar-crush` itself, so a world-writable config directory refuses too;
- it exists but cannot be read; it is a directory or a dangling symlink; an
  ancestor directory is unsearchable.

One file, two readers, and they disagree on purpose: a stray comma in
`~/.sugar-crush/settings.json` costs you a `theme` **silently** and refuses the
session **loudly**, because the second reader is the one carrying
`permissionMode` and `permissionRules` — and a permission mode that silently
falls back to the permissive default is the one failure this project will not
take quietly. `readUserConfig()` itself stays tolerant throughout; it is the
launch that refuses. See [`PERMISSIONS.md`](PERMISSIONS.md) and
[`TROUBLESHOOTING.md`](TROUBLESHOOTING.md).

## See also

- [`PERMISSIONS.md`](PERMISSIONS.md) — permission modes, rule patterns, and
  all four `trustedProject*` grants.
- [`MEMORY.md`](MEMORY.md) — the rest of the `~/.sugar-crush/` layout.
- [`ENVIRONMENT.md`](ENVIRONMENT.md) — the environment variables that sit above
  this stack. They do not cover it: only five of the ten layered keys have an
  env override (`provider`, `titleModel`, `summaryModel`, `parallelToolCalls`,
  `parallelToolDeadlineSeconds`). `theme`, `instructions`, `disabledSkills`,
  `allowedTools` and `disabledTools` have none.
