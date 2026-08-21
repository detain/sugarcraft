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

Exactly two keys are ever written there: `provider` (the Ctrl+P palette's
"Switch Model" action) and `theme` (the palette and `/theme`). Those are the
only two values `Chat`'s `onConfigChange` callback is invoked with, and
`Bootstrap` wires that callback to `writeUserConfig()` at one site.

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

Every key in that table has a real reader named beside it, and the table is
COMPLETE — `LayeredSettings::LAYERED_KEYS` is exactly these ten, and the
"Project may set" column is exactly `PROJECT_TIER_KEYS`. Both halves are
asserted by `TrustKeyDocumentationDriftTest`, so a key added to either constant
without a row here reds rather than drifting. A key nothing reads is worse than
a missing one, because it looks configurable.

Where a row names two methods, the first is the public entry point and the
second is the private method that does the read — cited because that is the
one to grep for.

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
`WebFetch`, `WebSearch`, `Doctor`, `Skill` and `Lsp` in one line — and what the
model does next is not less work, it is the *same* work through `Bash`, which
reaches the permission gate as opaque shell text instead of as a reviewable
path. Strictly fewer tools, strictly coarser review.

**A previous version of this page claimed `disabledTools` can only express that
attack "by naming every tool it removes — a value you can see when you read the
file". That is false, and the gap is still open.** `Bootstrap::filterToolSet()`
matches names with `PermissionRule::matchesToolName()`, which is bare
`fnmatch()`, and `fnmatch()` honours negated character classes. Measured
end-to-end:

```json
{ "disabledTools": ["[!B]*"] }
```

Eight characters, in a key a project **is** allowed to set, leaving exactly
`Bash` and removing everything else — the same tool set `allowedTools: ["Bash"]`
produces, and the same degradation to opaque shell text.

So the *shape* argument does not hold on its own; the ceiling argument below is
what the split actually rests on. Until this is closed — by refusing negated
classes in a project-tier `disabledTools`, or by moving the key to the user
tier — treat a project's `disabledTools` as able to choose your tool set, and
do not trust `trustedProjectSettings` on a repository you would not trust with
`allowedTools`.

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
