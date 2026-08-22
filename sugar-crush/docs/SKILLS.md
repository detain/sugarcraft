# Authoring a Skill

A skill is a directory containing a `SKILL.md` file: YAML frontmatter, then a
markdown body. The frontmatter is read at launch; the body is read only if the
model actually asks for it.

Every claim below was checked against the code in this checkout. Where a
frontmatter field is parsed but nothing reads it, that is stated in the field's
own row rather than left to be discovered — `src/Skills/Skill.php` accepts nine
keys and the live `bin/sugarcrush` path acts on four of them.

---

## Where a skill goes

`SkillLoader` walks **three** native locations; a separate discovery class walks
**four** foreign ones. The three are the three calls
`SkillLoader::loadAllManifests()` (lines 716-734) makes —
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
| `paths` | `[]` | `SkillRegistry::getForPaths()`, `SkillPathNudge` | Live. Glob patterns (see [What a `paths:` glob matches](#what-a-paths-glob-matches) for the semantics — they are not `FNM_PATHNAME`); touching a matching file nudges the skill into view once per session. Read from the Stage-1 manifest, so it costs no body read. The nudge is bounded (E66): at most 8 entries, each at most 300 bytes, and where it is spent depends on the tool: `Grep` and `Glob` subtract it from their own `maxOutputBytes`, so it is spent INSIDE the cap; `Read` takes an eighth BESIDE its cap (hence its stated 1.375x `maxBytes` total); `Edit` and `Write` have no output cap at all, so the class ceiling of 2,636 bytes is the whole bound there. A `description` too long for an entry is clipped and marked, and a skill held back is announced by a later call rather than dropped. |
| `allowed-tools` | `null` | nothing | **Inert.** Parsed, carried on the `Skill` object, copied by `ForeignSkillDiscovery`, and read by no tool-scoping code in `src/`. Writing it does not restrict anything. |
| `disallowed-tools` | `null` | nothing | **Inert**, same as above. |
| `model` | `null` | `App::dispatchSkill()` only | Reachable only through a method with no production caller — see [`context: fork`](#the-context-field-is-not-live-on-the-cli-path). |
| `effort` | `medium` | nothing | **Inert.** Parsed and carried; nothing in `src/` reads `Skill::$effort`. |
| `context` | `thread` | `App::applySkillsToSystemPrompt()`, `App::dispatchSkill()` | See below — neither reader is on the live path. |

### The `context:` field is not live on the CLI path

`context: fork` is meant to run a skill in a spawned sub-agent instead of
inlining its body into the conversation. `SkillRegistry::isContextFork()`
implements the test, and two methods consult it: `App::applySkillsToSystemPrompt()`
and `App::dispatchSkill()`. **Neither has a caller in `src/` or `bin/`** —
`dispatchSkill()`'s own doc-block says so, and `applySkillsToSystemPrompt()` is
referenced only from other doc-blocks.

What a real `bin/sugarcrush` run does instead:
`Runtime::buildSystemPrompt()` appends `SkillMatcher::listForPrompt()` (name +
description for every auto-invocable skill), and the body arrives later through
`SkillTool`. That path does not consult `context:` at all. So on today's binary
a `context: fork` skill behaves exactly like a `context: thread` one.

This is written down rather than removed because the payload is finished and
waiting for an executor; it is a seam, not dead code.

### What a `paths:` glob matches

`SkillRegistry::pathMatches()` answers `fnmatch()`-style globs, and it is
`fnmatch()` **without `FNM_PATHNAME`** — which is the clause most people get
wrong, because almost every other glob dialect they have met sets it.

- A single `*` **crosses `/`**. `*.php` claims `src/a/b/foo.php`, not only
  `foo.php`. So does `?`, which will match a `/` like any other character.
  If you want a single directory level, spell it out: `src/*/foo.php` does not
  restrict anything by itself — write `src/**/foo.php` if you meant "at any
  depth" and accept that "exactly one level down" is not expressible here.
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
   Read on demand, when the model calls the `Skill` tool.
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
- **Depth is capped at 6** and **breadth at 2000 directories**, because one
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
