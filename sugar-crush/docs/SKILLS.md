# Authoring a Skill

A skill is a directory containing a `SKILL.md` file: YAML frontmatter, then a
markdown body. The frontmatter is read at launch. The body is read when the
model asks for it through the `Skill` tool — and also at launch, for the
handful of skills the user names in the `enabledSkills` config key, whose bodies
ride in the system prompt every turn (see
[How a skill reaches the model](#how-a-skill-reaches-the-model)).

Every claim below was checked against the code in this checkout. Where a
frontmatter field is parsed but nothing reads it, that is stated in the field's
own row rather than left to be discovered. Which keys the live `bin/sugarcrush`
path acts on is answered per row, not by a count in this paragraph: a cardinality
in prose is stale the moment one more reader lands, and the readers have been
moving.

---

## Where a skill goes

`SkillLoader` walks **three** native locations; a separate discovery class walks
**four** foreign ones. The three are the three calls
`SkillLoader::loadAllManifests()` makes —
`builtInSkillsDir()`, `userSkillsDir()`, `projectSkillsDir()` — and they are
merged lowest-priority-first with `array_merge`, so a later tier's skill with
the same name replaces an earlier one:

| Tier | Directory | Notes |
|---|---|---|
| built-in | `src/Skills/BuiltIn/` | Ships with the package — twelve directories in this checkout (`api-design`, `composer-wizard`, `explore-codebase`, `laravel-best-practices`, `matchups-sync`, `mcp-authoring`, `php-best-practices`, `phpunit-master`, `security-audit`, `symfony-best-practices`, `testing-strategies`, `worktree-workflow`). |
| user | `~/.sugar-crush/skills/` | Yours. Symlinks inside it may resolve anywhere under `$HOME`. |
| project | `<root>/.sugar-crush/skills/` | Confined to the checkout; see [Containment](#containment). |

The **foreign** trees are other tools' conventions, imported read-only and
badged with a `SkillSource` (`src/Skills/ForeignSkillDiscovery.php`):
`<root>/.claude/skills`, `~/.claude/skills`, `<root>/.opencode/skills`,
`~/.config/opencode/skills`.

**Native always wins a name collision.** `SkillManager::loadAll()` registers
the foreign trees first and lays the native manifests over the top, so cloning
a repository that ships `.claude/skills/db-query` cannot re-point a `db-query`
you already had. Within one foreign convention the precedence is the *other*
way round — project loses to user — for the same reason stated from the other
side: a project's foreign skill arrived with somebody's repository.

Between the two foreign conventions, opencode wins over Claude. That pair has
no principled winner; what matters is that the order is fixed in
`SkillManager::loadAll()` rather than decided by scan order.

### The registry key is the path, not the directory name

`SkillLoader::skillKeyFor()` keys a skill by its path *relative to the tier
root* when it is nested more than one level down. So
`~/.sugar-crush/skills/db/query/SKILL.md` registers as `db/query`, not as
`query`, and two skills whose leaf directory happens to share a name do not
collide.

---

## Frontmatter

```yaml
---
description: Writes MySQL queries using the db_abstraction layer.
user-invocable: true
disable-model-invocation: false
paths:
  - "src/**/*.sql"
  - "include/**/*.php"
allowed-tools: Read, Grep
disallowed-tools: Bash
model: claude-sonnet-4-6
effort: high
context: thread
---

Everything after the closing `---` is the body.
```

`Skill::fromFile()` **requires** a frontmatter block — a `SKILL.md` with no
`---` fence is refused, recorded on `SkillLoader::skipped()`, and skipped. The
skill's default name is its parent directory's name, not a `name:` field.

| Key | Default | Read by | Effect today |
|---|---|---|---|
| `description` | `Skill: <name>` | `SkillMatcher::listForPrompt()` | Live. This one line is what the model sees at session start; it is the whole basis on which the model decides to invoke the skill. |
| `user-invocable` | `true` | `SkillRegistry::isUserInvocable()` → `App::userInvocableSkills()` | Live on the App shell's skill picker. `false` hides the skill from the picker while leaving it model-invocable. |
| `disable-model-invocation` | `false` | `SkillRegistry::isAutoInvocable()` | Live. `true` keeps the skill out of the prompt listing **and** makes `SkillTool` refuse it by name — the check is re-done in the tool so a skill added to a registry by some other route still cannot be reached. |
| `paths` | `[]` | `SkillRegistry::getForPaths()`, `SkillPathNudge` | Live. Glob patterns (see [What a `paths:` glob matches](#what-a-paths-glob-matches) for the semantics — they are not `FNM_PATHNAME`); touching a matching file nudges the skill into view once per session. Read from the Stage-1 manifest, so it costs no body read. The nudge is bounded (E66): at most 8 entries, each at most 300 bytes, and where it is spent depends on the tool: `Grep` and `Glob` subtract it from their own `maxOutputBytes`, so it is spent INSIDE the cap; `Read` takes an eighth BESIDE its cap (hence its stated 1.375x `maxBytes` total); `Edit` and `Write` have no output cap at all, so the class ceiling of 2,636 bytes is the whole bound there. A `description` too long for an entry is clipped and marked, and a skill held back is announced by a later call rather than dropped. Only model-invocable skills are ever nudged — a `disable-model-invocation: true` skill is filtered out of the nudge (E72), because telling the model to open a skill it may not invoke is a dead instruction. |
| `allowed-tools` | `null` | nothing | **Inert.** Parsed, carried on the `Skill` object, copied by `ForeignSkillDiscovery`, and read by no tool-scoping code in `src/`. Writing it does not restrict anything. |
| `disallowed-tools` | `null` | nothing | **Inert**, same as above. |
| `model` | `null` | `App::dispatchSkill()` only | **Not reachable on any live path.** `App::dispatchSkill()` reads it (`$skill->model ?? $this->model`) and that method has no production caller; `ForeignSkillDiscovery` merely copies the value onto the imported object. See [`context: fork`](#the-context-field-today). |
| `effort` | `medium` | nothing acts on it | **Inert.** Parsed and carried; the only read of `Skill::$effort` in `src/` is `ForeignSkillDiscovery` copying it onto the imported object. No execution path consults it. |
| `context` | `thread` | `SkillRegistry::isContextFork()` via `App::applySkillsToSystemPrompt()`, `App::dispatchSkill()`, `App::handleSelectSkill()` | See below — `fork` is implemented nowhere; the one live consulter only words a status message, and the standing-body splice does not consult the field at all. |

## How a skill reaches the model

There is one wired path that puts a skill's **body** in front of the model on
every turn — the canonical one, described below — and two that put a skill in
front of it another way: as a **line to choose from**
(`SkillMatcher::listForPrompt()`) or **on demand during a turn** (`SkillTool`).
They are deliberately different mechanisms. The page keeps saying which is which
rather than letting "skills go into the prompt" blur three behaviours.

### The canonical path: the `enabledSkills` key

The key is `enabledSkills`, a list of skill names in the persisted user config —
`Bootstrap::userConfigPath()`, which is `~/.sugar-crush/config.json` unless
`--config` names another file. **Its default is the empty list**:
`Bootstrap::promptEnabledSkills()` returns nothing at all when the key is absent,
so the standing prompt of an existing user changed by nothing on the day this
shipped. A body enters every turn only where the user asked for it by name.

`Bootstrap::promptEnabledSkills()` resolves the names at composition time and is
called at **both** composition sites — `Bootstrap::backend()` and
`Bootstrap::backendFor()` — each threading the result through
`EngineBackend::withSkills()` into `App::$enabledSkills`, so a provider switch
mid-session cannot drop what the first launch put there. Three properties of the
resolution are load-bearing, and all three are stated in its own doc-block:

- **Each name counts once.** The list is deduplicated strictly before it is
  resolved, because `Runtime::buildSystemPrompt()` renders one section per entry
  it is handed — the exactly-once guarantee every body carries is decided here,
  not downstream.
- **Opting out beats opting in.** A name that `disabledSkills` also lists
  resolves to null (`SkillRegistry::get()` honours the disable) and stays out of
  the prompt; a stale or unknown name stays out the same way. And unlike
  `disabledSkills`, `enabledSkills` is not in `LayeredSettings::LAYERED_KEYS`, so
  the user config file is its only contributor — no `settings.json` tier and no
  project tier, at any trust level. Growing that roster is a documented-roster
  change belonging to a settings-surface step, not to this wiring one.
- **A bad skill is a bounded notice, never a launch crash.** A non-list value, a
  non-string element, a disabled or unknown name, or a source file that vanished
  between discovery and launch each produce one notice through
  `Bootstrap::warnPermissionConfigInTranscript()` — the same bounded
  transcript-and-stderr channel every other config warning uses — and the launch
  continues. Entries fail safe; a wrong-shaped key is said, not swallowed.

Registry entries are **stage-1 manifests** — `SkillManager::loadAll()` never
reads a body off disk — so each resolved name gets a full `Skill::fromFile()`
here, the same source-of-truth load the `Skill` tool performs at invocation. A
body-less heading would inject a fact about nothing, which is why an unreadable
file drops to a notice instead.

At prompt build, `Runtime::buildSystemPrompt()` splices each enabled body as its
own section (`Skill::systemPromptContribution()` — a `## Skill:` heading with the
body under it) and hands the enabled names to `SkillMatcher::listForPrompt()` as
exclusions, so an enabled skill is **removed from the one-line listing**. A skill
is presented exactly once per turn: as a body where you enabled it, as a
description line where you did not. There is no double-presentation.

Two widenings are deliberately **not** shipped, and are named here so that
nobody infers them from the shape of the code:

- **`rules paths:` scoping is not applied at the splice.** `Rule::buildTriggers()`
  (reached from `Rule::new()`) builds a `PathTrigger` from a rule's `paths:`, and
  `PathTrigger` matches — but nothing
  in `Runtime::buildSystemPrompt()` consults any path predicate when it splices.
  Path-conditional splicing is a deferred step (P6.S5b). Until it lands, a skill
  named in `enabledSkills` is in every prompt turn, whichever files the session
  touches.
- **`context:` is not consulted at the splice** — see
  [The `context:` field today](#the-context-field-today).

### The TUI picker is not the canonical path

The skill picker (opened from the TUI via `SourceSkillCmd` →
`OpenSkillPickerMsg` → `App::handleSelectSkill()`) enables the chosen skill **in
memory for the running session only** — it writes no config key, so it does not
survive a restart — and what it appends to `App::$enabledSkills` is the
**registry** object, not a body-loaded one. Because registry entries are
stage-1 manifests with empty content, the section that splice produces is a
`## Skill:` heading with no body under it; the skill's description leaves the
listing line, and the model's access to the actual text remains the `Skill` tool.
Enabling through the picker today is a session-scoped change to what the
interface shows, not a delivery of instructions.

### Auto-matching a skill into the prompt is deliberately dormant

`Skill::matchesPrompt()` and `SkillRegistry::findForPrompt()` exist, are tested,
and **reach no production path.** Their only callers are the wrappers
`SkillManager::getSkillsForTask()` and `App::findSkillsForTask()`, and those have
zero production call sites, so the chain is unreachable end-to-end. They are the second skill→prompt
seam: naively wiring them would emit every enabled skill's body twice, once from
the canonical path above and once from an automatic match, so which path is
canonical was decided before any of it shipped.

The reason the automatic match is unwired is measured, not stylistic. Scored
against a labelled corpus of prompt/skill pairs —
`prompt_kit/findings/P7.S4/measure.php`, re-run at this checkout on PHP 8.3.6 —
the substring matching in `matchesPrompt()` lands at **0.162 precision** (recall
1.000; 24 of 25 boundary cases false-positive). Rewriting the needle test as
whole-word matching raises precision only to **0.214**. A matcher that is wrong
on five of six candidate mentions does not spare the user from naming their
skills; it injects unrelated instructions into every prompt and bills for them
every turn. The substring wiring was falsified by its own numbers.

The revival design, recorded in the methods' own doc-blocks, is curated
frontmatter keywords fed to `KeywordTrigger` — an opt-in signal the skill author
types, rather than a guess mined from prose. That design is not implemented; the
dormancy stands until it is. The matcher is kept, its dormancy pinned by tests,
and described here as dormant — not deleted, not stubbed, not silently unwired.

## The `context:` field today

`context: fork` is meant to run a skill in a spawned sub-agent instead of
inlining its body into the conversation. `SkillRegistry::isContextFork()`
implements the test and three methods consult it:
`App::applySkillsToSystemPrompt()` and `App::dispatchSkill()` — **neither has a
caller in `src/` or `bin/`**; each is a seam waiting for its executor, not dead
code — and `App::handleSelectSkill()`, which is live but does nothing
fork-shaped with the answer: it words a different status line ("declares
context: fork — enabled, but not inlined, and no fork dispatch is wired yet") so
the interface stops claiming an effect that does not happen.

What a real `bin/sugarcrush` run does instead: `Runtime::buildSystemPrompt()`
appends `SkillMatcher::listForPrompt()` (name and description for every
auto-invocable skill), the enabled bodies splice in through the canonical path
above, and the on-demand body arrives through `SkillTool`. None of those three
consults `context:`. So on today's binary a `context: fork` skill behaves exactly
like a `context: thread` one — including on the canonical path, where a
fork-declaring skill named in `enabledSkills` will have its body spliced like any
other, because the splice has no `context:` predicate. If you want a skill kept
out of the standing prompt, leave it out of the key; declaring `fork` does not.

This is written down rather than removed because the payload is finished and
waiting for an executor; it is a seam, not dead code.

## What a `paths:` glob matches

`SkillRegistry::pathMatches()` answers `fnmatch()`-style globs, and it is
`fnmatch()` **without `FNM_PATHNAME`** — which is the clause most people get
wrong, because almost every other glob dialect they have met sets it.

There is **one dialect and one compiler**. `src/Util/PathGlob.php` translates a
pattern to an anchored PCRE once and every matcher answers from the translation.
Both production globbers route through it —
`SkillRegistry::compilePathPattern()` and `SkillRegistry::pathMatches()` for a
skill's `paths:` nudge, `PathTrigger::pattern()` for the `paths:` a markdown rule
declares — so the same pattern means the same thing on both channels. Before that
unification they did not: `PathTrigger`'s `*` was segment-scoped and would not
cross a `/`, the skill channel's crossed it freely, and "which one am I writing
for?" had no answer. `SkillRegistry::legacyPathMatch()` stays reachable as the
fallback for patterns the translation will not compile, and the strict dialect (a
`*` that never crosses `/`) was measured against real frontmatter patterns and
rejected over `GlobDialectDifferentialTest`, not ignored.

- A single `*` **crosses `/`**. `*.php` claims `src/a/b/foo.php`, not only
  `foo.php`. So does `?`, which will match a `/` like any other character.
  Be precise about what that costs: `src/*/foo.php` is not "exactly one level
  down". It requires **at least** one — it will not claim `src/foo.php` — but
  puts no ceiling on how many, so it claims `src/a/b/foo.php` too. "Exactly one
  level down" is not expressible in this dialect. Write `src/**/foo.php` when
  you mean "at any depth including none".
- `**` means **zero or more directory levels, at any position — including the
  first**. `src/**/*.php` claims `src/foo.php` as well as `src/a/b/foo.php`,
  and `**/*.php` claims `foo.php` at the tree root as well as `a/foo.php`.
- Paths are matched as the tool reports them, relative to the project root, so
  anchor with a leading directory (`src/**/*.sql`) when you mean a subtree and
  with `**/` when you do not care where the file lives.

MEASURED on PHP 8.3.6, through `SkillRegistry::pathMatches()`: `*.php` vs
`src/foo.php` → true; `**/*.php` vs `foo.php` → true; `src/**/*.php` vs
`src/foo.php` → true; `a/**` vs `a` → true.

**A leading `**` began matching tree-root files** when `pathMatches()` stopped
rewriting `**` with `str_replace()` and started translating the whole pattern
to an anchored PCRE. Before that, a pattern starting with `**` matched none of
the three rewrites and fell through to a bare `fnmatch()`, which reads `**/` as
"some characters, then a literal slash" — so `**/*.php` did **not** claim
`a.php`. MEASURED on PHP 8.3.6, old predicate versus new: `**/*.php` vs
`foo.php` was false and is true; `**/*Test.php` vs `FooTest.php` was false and
is true. (The old predicate is still in the file as
`SkillRegistry::legacyPathMatch()`, which is where those "was" figures come
from — it is the answer for patterns the translation cannot compile, so it is
reachable rather than historical.)

Three shipped built-in skills declare a leading `**` and are affected:
`security-audit` and `php-best-practices` (`paths: ["**/*.php"]`) and
`phpunit-master` (`paths: ["**/*Test.php"]`). All three used to stay silent on
a file at the tree root and now nudge on one. That is what their authors
intended, which is why the change shipped as a fix — but if you noticed the old
behaviour and built a workaround on it, this is the note saying it is gone.

---

## The three loading stages

`SkillLoader` is deliberately staged, and the staging is the reason a 50-skill
roster is cheap:

1. **Manifest** (`loadSkillManifest()`) — name, description, the two invocation
   flags, `context`, `paths`, and the `SKILL.md` path. This is all that runs at
   launch, and it is what `SkillManager::loadAll()` registers.
2. **Body** (`loadSkillBody()`) — everything after the frontmatter, trimmed.
   Read on demand, when the model calls the `Skill` tool; read at composition
   through `Skill::fromFile()` for the skills named in `enabledSkills` (the
   canonical path above), and at launch for every imported foreign skill, since
   the foreign path parses the whole file.
3. **Assets** (`loadSkillAsset()`) — one file from `scripts/`, `references/` or
   `assets/` beside the `SKILL.md`. Any other first path component is refused,
   and the resolved path must be contained by the skill directory.

**The foreign trees do not get stage 1.** `ForeignSkillDiscovery` goes through
`loadFromDirectory()`, which parses the whole file, because the `SkillSource`
provenance tag rides on a `Skill` object and the manifest arrays have nowhere to
carry it. An imported skill's body is therefore read at launch even if it is
never used.

## Invoking a skill

The model calls the built-in `Skill` tool
(`src/Tools/BuiltIn/SkillTool.php`), which takes `name` and an optional
`args` string, and returns the on-disk body. It refuses with an error — not an
empty success — when the name is unknown or not model-invocable.

`Bootstrap::tools()` and `EngineBackend` are handed the *same* `SkillRegistry`
instance, so a skill disabled on one is not reachable through the other.

The parallel carrier is the sub-agent path: an agent definition may list
`skillNames` (`Agent::$skillNames`) and `AgentManager::executeSubAgent()` would
apply them — but nothing in `src/` or `bin/` calls `executeSubAgent()`, so that
carrier is a seam with no production caller, in the same standing as
`App::applySkillsToSystemPrompt()` and `App::dispatchSkill()` above. On today's
binary the on-demand body arrives only through the `Skill` tool, and the standing
body only through the canonical `enabledSkills` path.

---

## Containment

Everything under a skills directory is user- or repository-controlled, so the
walk is bounded in four separate ways (`SkillLoader::skillFilesIn()`):

- **Symlinks are followed** — that is the point. Linking skills in from a shared
  checkout is how the tools this loader imports from are commonly laid out; a
  walk that skipped links found none of them.
- **But confined.** A project tree's links must resolve inside the checkout; a
  user tree's may reach anywhere under `$HOME`. The *directory itself* is also
  anchored, so a committed `.sugar-crush/skills -> /elsewhere` is refused
  wholesale and recorded on `SkillLoader::refusedDirectories()`.
- **Depth is capped at 7** and **breadth at 2000 directories**, because one
  symlink can graft a tree of any size on. A real skills tree is two or three
  levels and tens of directories.
- **The user tier of the *foreign* trees is dropped entirely** when
  `HomeDirectory::owned()` cannot establish that `$HOME` is this user's. The
  project tier survives, because it is anchored to the checkout and needs no
  home.

## Diagnostics

Skill-load failures are **quiet by default**. They are other tools' files, so
"fix your SKILL.md" is often not advice you can act on, and the TUI owns stdout
under an alt screen — a stray stderr line lands inside a frame the renderer
believes it owns.

Nothing is lost: every skip is readable from `SkillManager::skipped()`, every
refused directory from `refusedDirectories()`, the launch prints one bounded
summary line (to stderr **and** to the session transcript, so it survives the
alt screen), and `SUGARCRUSH_DEBUG_SKILLS=1` puts the per-file lines back on
stderr. See [`ENVIRONMENT.md`](ENVIRONMENT.md).

## See also

- [`AGENTS_AUTHORING.md`](AGENTS_AUTHORING.md) — the sibling format, and the
  fields that do *not* survive the trip into the agent roster.
- [`COMMANDS.md`](COMMANDS.md) — file-based slash commands, the other
  markdown-plus-frontmatter surface.
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — where the registry sits.
