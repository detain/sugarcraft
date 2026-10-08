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
**seven** foreign ones. The three are the three calls
`SkillLoader::manifestTiers()` makes —
`builtInSkillsDir()`, `projectSkillsDir()`, `userSkillsDir()` — and
`SkillLoader::loadAllManifests()` merges them lowest-priority-first by
`mergeTier()`, in the order `SkillOrigin::precedence()` states, so a later
tier's skill with the same name replaces an earlier one (and the replaced one
is reported — see below):

| Tier | Directory | Notes |
|---|---|---|
| built-in | `src/Skills/BuiltIn/` | Ships with the package — eight directories in this checkout (`api-design`, `composer-wizard`, `laravel-best-practices`, `php-best-practices`, `phpunit-master`, `security-audit`, `symfony-best-practices`, `testing-strategies`). Lowest precedence. |
| project | `<root>/.sugar-crush/skills/` | Confined to the checkout; see [Containment](#containment). Beats a built-in. |
| user | `~/.sugar-crush/skills/` | Yours. Symlinks inside it may resolve anywhere under `$HOME`. Highest precedence. |

The SugarCraft monorepo's own skills — `explore-codebase`, `matchups-sync`,
`mcp-authoring` and `worktree-workflow` — are **not** built-ins. They live in
the monorepo's project tier, `.sugar-crush/skills/` at the repository root, so
they load only when sugar-crush runs with that checkout as its project root.
They used to ship under `src/Skills/BuiltIn/`, which listed them in every
project's system prompt: a user in an unrelated repository who said "open a
PR" matched `worktree-workflow`, whose body then told the model to run
`git checkout -- . && git clean -fd` on a dirty tree — discarding uncommitted
work — and to self-merge the PR (audit 15d-21). That instruction is gone too:
a tree that is not clean now means stop and report.

The **foreign** trees are other tools' conventions, imported read-only and
badged with a `SkillSource` (`src/Skills/ForeignSkillDiscovery.php`), lowest
cross-convention priority first:
`<root>/.agents/skills`, `~/.agents/skills`, `<root>/.claude/skills`,
`~/.claude/skills`, `<root>/.opencode/skills`, `~/.opencode/skills`,
`~/.config/opencode/skills`.

### Which skill keeps a name

**The user beats the project.** On a name collision the tier decides first —
built-in < project < user — whatever format each file is in, and the format
only breaks a tie *inside* one tier:

```text
built-in  <  project: spec < claude < opencode < native  <  user: spec < claude < opencode < native
```

`SkillManager::loadAll()` registers the tiers in that order into a
last-write-wins registry. So cloning a repository that ships
`.sugar-crush/skills/deploy` or `.claude/skills/db-query` cannot re-point a
`deploy` or `db-query` you already had — in `~/.sugar-crush/skills`,
`~/.agents/skills`, `~/.claude/skills` or `~/.config/opencode/skills` alike. A repository may
still *add* a skill, and may replace a built-in (the tier below it); it may
not re-point one you wrote. Until audit 15d-03(b) the native order was
built-in < user < project, so a repository's `.sugar-crush/skills/deploy`
silently replaced yours, and "native always wins" let it replace your
`~/.claude/skills` copy too.

Inside one tier, **native wins**: a repository carrying both
`.claude/skills/deploy` and `.sugar-crush/skills/deploy` gets the native one,
and installing another CLI cannot re-point a skill in your own
`~/.sugar-crush/skills`. Among the foreign conventions the fixed order is
spec < claude < opencode — the least tool-specific convention loses a name to
the more tool-specific ones, since a tree a named tool wrote carries that
tool's assumptions. That ordering's bottom pair has no principled winner; what
matters is that the order is fixed in `SkillManager::loadAll()` rather than
decided by scan order. Inside one convention with two user trees — opencode's
legacy `~/.opencode/skills` and its XDG `~/.config/opencode/skills` — the XDG
tree is the later registration and wins.

**Every shadowing is reported.** Whichever tier wins, the skill that lost is
not dropped silently: each one a later tier (or a native skill, or opencode
over Claude over spec, or your copy over a repository's inside one foreign convention)
replaces is recorded in `SkillLoader::skipped()` / `SkillManager::skipped()`
under the losing file's path, with a reason naming the winner — `shadowed by
[user] skill <path> (same name 'deploy'); this [project] skill was not
loaded`. It is counted in the launch notice and listed by
`SUGARCRUSH_DEBUG_SKILLS=1`, like an unreadable file; the notice's one line
counts both kinds and names both (`2 skill files were not loaded (unreadable,
or shadowed by a same-named skill)`), so a skill that lost a collision is not
reported as a file you need to fix. A loser that is the
winner's own file or a byte-identical copy of it (one skill synced into several
tools' trees) loses nothing and is not reported.

### The listing badges every skill with its tier

Each walker stamps the skills it reads with a `SkillOrigin` — `built-in`,
`user` or `project`, by the directory it was reading, never by matching the
path afterwards — and every line of the system-prompt skill listing opens with
it, plus the foreign format when there is one:

```text
- [built-in] security-audit: …
- [user] deploy: …
- [project, foreign: claude] helper: …
```

The listing itself is fenced `<available-skills>`, under a preamble that says
the list is assembled by the harness but every name and description is its
skill author's text, and explains the badge (audit 15d-02). Directly beneath
the preamble rides a one-line proactive-use mandate — when a listed skill's
description covers the task, the model must load and follow it through the
`Skill` tool before starting — balanced by the preamble's statement that the
listing is advisory metadata, not an instruction that outranks the harness.
The fence is assembled by `SkillListingSection`, which owns both lines.
Each line names a skill by its display name — the last `/`-separated
segment of the registry key — and exact-duplicate lines (same display name and
description, the shape synced bundles produce) collapse to the stronger-tier
entry, so the model sees one line per distinct offer. The lines are grouped by
tier — built-in, then project, then user, the reading order
`SkillOrigin::precedence()` states — and alphabetical by display name within a
tier. A skill registered
by some other route, with no tier stated, is badged `project` — the least
trusted tier, so an unknown origin is never presented as the operator's or
the harness's. The path nudge's lines (`SkillPathNudge`) carry no badge; its
`<system-reminder>` header does not explain one.

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

A `SKILL.md` in a legacy encoding is not skipped: every reader scrubs it to valid
UTF-8 first, replacing each invalid byte sequence with `?`, and the body gains a
trailing `[encoding: …]` line saying so (audit 15d-08).

**A mistyped field skips the skill, never the launch.** Both readers — the
Stage-1 `SkillLoader::loadSkillManifest()` and the eager `Skill::parse()` — type
the frontmatter through one class, `SkillFrontmatter`. A field of the wrong type
throws an error naming the field, the skill is recorded on
`SkillLoader::skipped()` like any unreadable file, and the launch notice reports
it; the other skills load as usual. What is coerced and what is refused:

- `paths:` written as one glob (`paths: src/**/*.php`) is read as a one-element
  list. A list entry that is not a string (`- 2024`) is refused.
- `user-invocable` and `disable-model-invocation` accept `true`/`false` and the
  YAML 1.1 words `yes`/`no`/`on`/`off`, which the YAML 1.2 parser hands back as
  strings. Anything else is refused.
- `allowed-tools` / `disallowed-tools` accept a string or a list of strings.
- `description`, `context`, `model` and `effort` must be strings. A number or
  an unquoted date (`description: 2024-01-01`, which YAML reads as an integer
  timestamp) is refused rather than turned into text. Quote it.
- A frontmatter block that is not a mapping of fields is refused.
- `requires` must be a mapping of `bins` / `anyBins` (or `any-bins`) / `env`,
  each a string or a list of non-empty strings. Any other key under it is
  refused, so a typo cannot silently drop a requirement. `os` is a string or a
  list of platform names (`linux`, `darwin` or `macos`, `win32` or `windows`,
  `freebsd`, `openbsd`, `netbsd`, `sunos`). An unknown platform is refused.

**A skill can say what the host must provide, and is left out when it is
missing.**

```yaml
---
description: Opens GitHub pull requests with gh.
requires:
  bins: [gh]              # every one must be on PATH
  anyBins: [rg, grep]     # at least one must be on PATH
  env: [GITHUB_TOKEN]     # each must be set and non-empty
os: [linux, darwin]       # the platform must be listed
---
```

- **When it is checked.** The check runs when the skill is read, once per
  launch, and only for a skill that declares requirements.
- **What happens when it fails.** A skill whose requirements this host does
  not meet is not registered, so it is neither listed to the model nor
  loadable by name. It is recorded on `SkillManager::skipped()` with every
  missing piece named, for example
  `skill "gh-pr" is unavailable on this host: needs CLI gh; needs env GITHUB_TOKEN`
  (see [Diagnostics](#diagnostics)).
- **How binaries are found.** The `PATH` lookup is a stat walk, never a
  subprocess: a file must exist and be executable.
- **Other agents' formats.** SKILL.md files written for OpenClaw or nanobot
  carry the same block under `metadata.openclaw` / `metadata.nanobot`. That is
  read when the top-level `requires` and `os` are both absent. There, keys this
  reader does not evaluate (OpenClaw's `requires.config`) are ignored rather
  than refused, because they belong to the other agent's format.
- **Order of checks.** A mistyped field is reported before a missing binary,
  so the error you see is the one you can fix in the file.

| Key | Default | Read by | Effect today |
|---|---|---|---|
| `description` | `Skill: <name>` | `SkillMatcher::listForPrompt()` | Live. This one line is what the model sees at session start; it is the whole basis on which the model decides to invoke the skill. It is repository text, so `SkillPromptLine` renders it — and the skill's display name, the last `/`-separated segment of the registry key — collapsed to one line, `PromptFence::escape()`d and clipped to `SkillPromptLine::LISTING_MAX_BYTES` (audit 15d-02): a multi-line description cannot start a line of its own, and a fence tag in it arrives as inert `&lt;` text. The line sits inside the `<available-skills>` fence behind its tier badge — see [The listing badges every skill with its tier](#the-listing-badges-every-skill-with-its-tier). |
| `user-invocable` | `true` | `SkillRegistry::isUserInvocable()` → `App::userInvocableSkills()` | Live on the App shell's skill picker. `false` hides the skill from the picker while leaving it model-invocable. |
| `disable-model-invocation` | `false` | `SkillRegistry::isAutoInvocable()` | Live. `true` keeps the skill out of the prompt listing **and** makes `SkillTool` refuse it by name — the check is re-done in the tool so a skill added to a registry by some other route still cannot be reached. |
| `paths` | `[]` | `SkillRegistry::getForPaths()`, `SkillPathNudge` | Live. Glob patterns (see [What a `paths:` glob matches](#what-a-paths-glob-matches) for the semantics — they are not `FNM_PATHNAME`); touching a matching file nudges the skill into view once per session. Read from the Stage-1 manifest, so it costs no body read. The nudge is bounded (E66): at most 8 entries, each at most 300 bytes, and where it is spent depends on the tool: `Grep` and `Glob` subtract it from their own `maxOutputBytes`, so it is spent INSIDE the cap; `Read` takes an eighth BESIDE its cap (hence its stated 1.375x `maxBytes` total, a ceiling: the page `Read` returns is itself at most `maxBytes`, and 50 KiB by default, while the eighth is still figured from `maxBytes`); `Edit` and `Write` have no output cap at all, so the class ceiling of 2,636 bytes is the whole bound there. A `description` too long for an entry is clipped and marked, and a skill held back is announced by a later call rather than dropped. Only model-invocable skills are ever nudged — a `disable-model-invocation: true` skill is filtered out of the nudge (E72), because telling the model to open a skill it may not invoke is a dead instruction. |
| `allowed-tools` | `null` | nothing | **Inert.** Parsed, carried on the `Skill` object, copied by `ForeignSkillDiscovery`, and read by no tool-scoping code in `src/`. Writing it does not restrict anything. |
| `disallowed-tools` | `null` | nothing | **Inert**, same as above. |
| `model` | `null` | `App::dispatchSkill()` only | **Inert.** Not reachable on any live path: `App::dispatchSkill()` reads it (`$skill->model ?? $this->model`) and that method has no production caller; `ForeignSkillDiscovery` merely copies the value onto the imported object. See [`context: fork`](#the-context-field-today). |
| `effort` | `medium` | nothing acts on it | **Inert.** Parsed and carried; the only read of `Skill::$effort` in `src/` is `ForeignSkillDiscovery` copying it onto the imported object. No execution path consults it. |
| `requires` | none | `SkillRegistry::unmetRequirements()` via `SkillFrontmatter::fromParsed()` and `SkillRegistry::register()` | Live. An unmet requirement keeps the skill out of the registry, with the reason on `SkillManager::skipped()`. See above. |
| `os` | any platform | same | Live, same as `requires`. |
| `context` | `thread` | `SkillRegistry::isContextFork()` via `App::applySkillsToSystemPrompt()`, `App::dispatchSkill()`, `App::handleSelectSkill()` | **Inert** for `fork` (`thread` is what happens anyway). See below — `fork` is implemented nowhere; the one live consulter only words a status message, and the standing-body splice does not consult the field at all. |

The **Inert** rows are not a judgement written once and left: they are
`FrontmatterKeyAudit::INERT`, which `InertFrontmatterDocumentationDriftTest`
holds this table to, and a launch names every skill that declares one — see
[Diagnostics](#diagnostics). Keys other than the ones above (and the
agentskills.io spec's descriptive `name`, `license`, `compatibility` and
`metadata`) are not read at all.

## How a skill reaches the model

There is one wired path that puts a skill's **body** in front of the model on
every turn — the canonical one, described below — and two that put a skill in
front of it another way: as a **line to choose from**
(`SkillMatcher::listForPrompt()`) or **on demand during a turn** (`SkillTool`).
They are deliberately different mechanisms. The page keeps saying which is which
rather than letting "skills go into the prompt" blur three behaviours.

### The canonical path: the `enabledSkills` key

The key is `enabledSkills`, a list of skill names in your own settings —
`Bootstrap::userConfigPath()` (`~/.sugar-crush/config.json` unless `--config`
names another file) or `~/.sugar-crush/settings.json`, the former winning when
both set it. **Its default is the empty list**:
`Bootstrap::promptEnabledSkills()` returns nothing at all when the key is absent,
so the standing prompt of an existing user changed by nothing on the day this
shipped. A body enters every turn only where the user asked for it by name.

`Bootstrap::promptEnabledSkills()` resolves the names at composition time and is
called at **both** composition sites — `Bootstrap::backend()` and
`Bootstrap::backendFor()` — each threading the result through
`EngineBackend::withSkills()` into `App::$enabledSkills`, so a provider switch
mid-session cannot drop what the first launch put there. The switch itself
(`/model <provider>`, Ctrl+P → **Switch model**) builds its engine through the
factory `Bootstrap::chat()` hands `Chat`, which reuses the launch's skill
registry, `/rules` set and agent manager, so the switched engine keeps the same
skills, the session's rule toggles and the `Task` tool. Three properties of the
resolution are load-bearing, and all three are stated in its own doc-block:

- **Each name counts once.** The list is deduplicated strictly before it is
  resolved, because `Runtime::buildSystemPrompt()` renders one section per entry
  it is handed — the exactly-once guarantee every body carries is decided here,
  not downstream.
- **Opting out beats opting in.** A name that `disabledSkills` also lists
  resolves to null (`SkillRegistry::get()` honours the disable) and stays out of
  the prompt; a stale or unknown name stays out the same way. Like
  `disabledSkills`, `enabledSkills` is in `LayeredSettings::LAYERED_KEYS`, so
  `~/.sugar-crush/settings.json` and `config.json` both contribute (the latter
  wins) — but unlike `disabledSkills` it is **never** taken from a project file,
  at any trust level: a name here makes a skill's body standing system-prompt
  text, which is the `instructions` argument, whereas a project disabling a
  skill only ever removes one. (Until settings step N-DOC-2 this bullet said the
  key was `config.json`-only; that exception is gone.)
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
skill's display name, the same base-directory line the `Skill` tool result opens
with, and the body under it) and hands the enabled names to
`SkillMatcher::listForPrompt()` as
exclusions, so an enabled skill is **removed from the one-line listing**. A skill
is presented exactly once per turn: as a body where you enabled it, as a
description line where you did not. There is no double-presentation.

Two widenings are deliberately **not** shipped, and are named here so that
nobody infers them from the shape of the code:

- **A skill's `paths:` does not gate its enabled body.** A skill named in
  `enabledSkills` is in every prompt turn, whichever files the session touches;
  its `paths:` drives only the `SkillPathNudge` described above. Rules are
  different, and this is where the two used to be confused: a rule's `paths:`
  IS applied (P6.S5b). `Rule::buildTriggers()` (reached from `Rule::new()`)
  builds a `PathTrigger` from it, the splice in
  `Runtime::systemPromptSections()` skips every rule
  `RulePathNudge::isPathScoped()` claims, and `Bootstrap` wires `RulePathNudge`
  into Read, Edit, Write, ApplyPatch, Glob and Grep, which deliver the rule in their tool
  output on the first touch of a matching file — re-walking the rules on every
  consult, so a scoped rule written mid-session is delivered too. A rule's
  `keywords:` and `description:` are still not applied: `KeywordTrigger` and
  `IntentTrigger` have no consumer in `src/`.
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

**Every read is bounded** (`SkillFileReader`, audit 15d-27). A `SKILL.md` or an
asset over **1 MiB** (`SkillFileReader::MAX_FILE_BYTES`) is refused from a
`stat()`, before any of it is read, and the read itself stops one byte past the
ceiling in case the file grows in between. The manifest stage reads only the
first **64 KiB** (`MAX_FRONTMATTER_BYTES`), which is where the frontmatter has
to close; a block still open at that point is refused. A refused skill is not
listed — its body could never be loaded, so offering it would promise the model
something the `Skill` tool must then refuse — and is recorded on
`SkillLoader::skipped()` with the size, so the launch notice counts it. These
are memory guards, not prompt budgets: what an enabled skill's body may cost in
the system prompt is the separate, much smaller per-skill budget.

## Invoking a skill

The model calls the built-in `Skill` tool
(`src/Tools/BuiltIn/SkillTool.php`), which takes `name` and an optional
`args` string, and returns the on-disk body. `name` resolves against the exact
registry key first; failing that, a unique display name — the last
`/`-separated segment the listing shows — resolves to its skill, and a display
name several skills share refuses with an error naming every colliding full key
so the model can retry by one of them. It also refuses with an error — not an
empty success — when the name matches nothing, is not model-invocable, or when
`name` or `args` is not a string. The result opens with `## Skill: <display
name>`, then a `> Base directory for this skill: <dir>` line (the directory of
the skill's `SKILL.md`, fence-escaped), so a body that references
`scripts/helper.php` relative to itself has the anchor to resolve it against,
then the body. `Skill` is classified read-only by the permission gate — loading
a text file into context is the same risk class as `Read` — so no mode prompts
or denies for the call itself; what the loaded body then tempts the model to
*do* still passes every later call's own gate.

`args` reaches the body the way Claude Code's skills receive theirs
(`SkillTool::substituteArguments()`): every `$ARGUMENTS` in the body is replaced
by the trimmed `args` string; a body that names no `$ARGUMENTS` gets a final
`ARGUMENTS: <args>` line appended; with no `args` a placeholder-free body is
returned unchanged and a `$ARGUMENTS` expands to nothing. Only `$ARGUMENTS` is
recognised — unlike a [custom command template](COMMANDS.md), `$1`…`$9`, `$$`,
`` !`…` `` and `@path` are left as written, because skill bodies carry shell
snippets that use them. The append is the other deliberate difference from
custom commands, which send a placeholder-free template unchanged: here the
arguments come from the model, which passed them for the skill to act on.

`Bootstrap::tools()` and `EngineBackend` are handed the *same* `SkillRegistry`
instance, so a skill disabled on one is not reachable through the other.

The parallel carrier is the sub-agent path: an agent definition may list
`skillNames` (`Agent::$skillNames`) and `AgentManager::executeSubAgent()` would
apply them — but nothing in `src/` or `bin/` calls `executeSubAgent()`, so that
carrier is a seam with no production caller, in the same standing as
`App::applySkillsToSystemPrompt()` and `App::dispatchSkill()` above. On today's
binary the on-demand body arrives through the `Skill` tool when the model asks
for it, or through a `$name` mention (below) when you do; the standing body only
through the canonical `enabledSkills` path.

### `$name` in a prompt: one skill for one turn

Type `$name` in a prompt — `$security-audit the auth module` — and that skill's
body rides **this turn only**, attached to your message the way an `@file`
mention attaches a file (`Skills\SkillMentions`, resolved by
`Host\TurnController::userTurnMessage()` against the launch's registry). It sits
between the other doors: `enabledSkills` puts a body in every turn's system
prompt, the Ctrl+S picker enables a skill for the session, and the model loads
one on demand with the `Skill` tool.

- **The registry is the gate, not the `$`.** A token is a mention only when
  `SkillRegistry::userInvocable()` answers it — registered, not disabled, and
  `user-invocable` — so `$HOME`, `$1` or `$PATH` in a pasted shell line stay
  plain text. A name that *is* a skill but is disabled, or marked
  `user-invocable: false`, gets a notice saying so instead. A `$` inside a
  `` `code span` `` or a fenced block is never a mention, and neither is one in
  a command file's expansion (text you did not type).
- **What is attached** is the skill's `SKILL.md` body, frontmatter stripped,
  read by `SkillLoader::loadSkillBody()` — the same bounded read the `Skill`
  tool does — as a file snapshot on your row, so it survives a resume. A body
  that names `$ARGUMENTS` gets the rest of your prompt (the mentions taken out)
  there, as the tool substitutes its `args`; any other body is attached
  unchanged, because your prompt already says what to do with it.
- **At most three** skills per prompt; each one past that gets a notice.
- **`Tab` completes the name** when the cursor ends a `$` word in the TUI
  draft, from the skills you can invoke (`SkillMentions::complete()`): a unique
  name comes back whole with a trailing space, several their common prefix. A
  `$` word no such name starts with (`$HOME`) is left alone, and `Tab` does
  what it would otherwise do.

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

**A skill that loads but declares a key nothing acts on is named too.** The
launch adds one aggregated row (stderr and transcript) for every skill that
sets an inert field — `allowed-tools`, `disallowed-tools`, `model`, `effort`,
`context: fork` — or a key this reader does not know, with a did-you-mean for a
near miss:

```text
4 skills declare frontmatter sugar-crush ignores: `allowed-tools` is not acted on (pdf, review, gh-pr); `user_invocable` is not a skill field (did you mean `user-invocable`?) (gh-pr); `context: fork` is not acted on (deep-dive)
```

The skill is never refused for it: an imported Claude Code skill keeps its
`allowed-tools`, and the row is how you learn that the restriction it asks for
is not applied here. Built-in skills are not audited.

A skill left out because the host lacks what its `requires` or `os` names is a
skip like any other. Its reason starts with `is unavailable on this host` and
lists every missing CLI, environment variable and platform, so the summary
line counts it and `SUGARCRUSH_DEBUG_SKILLS=1` prints it. Installing the tool
or setting the variable brings the skill back on the next launch. A skill
handed to the registry by code rather than read from a SKILL.md gets the same
check, and its reason is on `SkillRegistry::unavailable()`.

## Proposed skills

The [dream pass](MEMORY.md#dream-pass) may **propose** a skill, but never write
or edit a live one. Proposals are off unless `"memory.dreamProposeSkills": true`
is in `~/.sugar-crush/config.json`; with it on, a pass that sees a procedure
repeated across the compaction journal may write up to three drafts to
`~/.sugar-crush/skills-proposed/<name>/SKILL.md`. That directory is not a skill
tier — no loader walks it, so a draft is never listed to the model, matched or
invoked. How a draft is checked on the way in (sanitised name, redacted
secrets, size caps, owner-only modes) is in
[MEMORY.md](MEMORY.md#dream-pass).

A draft becomes a skill only when you say so, with `/skills`
(`Host\Commands\SkillsHostCommand`):

| Command | What it does |
|---|---|
| `/skills` or `/skills proposed` | Lists the waiting drafts: name, size, date and description (or why it does not load). |
| `/skills accept <name>` | Validates the draft with the skill loader — frontmatter through `SkillLoader::loadSkillManifest()`, the whole file through `Skill::fromFile()` — then writes it to `~/.sugar-crush/skills/<name>/SKILL.md` and deletes the draft. Refused when a live skill of that name exists in any native tier (`SkillLoader::loadAllManifests()`) or its user-tier directory exists, unless you add `--replace`, which rewrites only that directory's `SKILL.md` (a built-in or project skill of the name is then shadowed by yours, the usual user-beats-project order). |
| `/skills reject <name>` | Deletes the draft. |

`<name>` must already be a draft name (lower-case letters, digits and dashes),
so `../x` or an absolute path is refused, never resolved. An accepted skill is
read from the next launch, like any other file in the user tier. You can edit a
draft by hand before accepting it.

**Only you can promote.** `/skills accept` is a slash command — typed in the
TUI, or sent by a protocol client as `command.exec` — and no tool calls
`ProposedSkills::accept()`; the dream pass's own turn has only read-only tools.
An agent's `Write`, `Edit` or `Bash` touching `.sugar-crush/skills-proposed`
is asked about in every permission mode, the same as one touching
`.sugar-crush/skills` ([PERMISSIONS.md](PERMISSIONS.md)), so a draft the agent
planted cannot pass for one the dream proposed.

## See also

- [`AGENTS_AUTHORING.md`](AGENTS_AUTHORING.md) — the sibling format, and the
  fields that do *not* survive the trip into the agent roster.
- [`COMMANDS.md`](COMMANDS.md) — file-based slash commands, the other
  markdown-plus-frontmatter surface.
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — where the registry sits.
- The [README](../README.md#documentation-index) — every other page.
