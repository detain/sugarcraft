# Memory and instruction files

Two separate mechanisms put standing knowledge in front of the model. They are
often confused, so they are documented side by side:

| | **Memory store** | **Instruction files** |
|---|---|---|
| Written by | `/memory add`, as UUID-named markdown files | you, by hand |
| Lives in | `~/.sugar-crush/memory/<scope>/<uuid>.md` | `CLAUDE.md` / `AGENTS.md` in the repo |
| Reaches the prompt as | a `<project-memory>` block | full documents |
| Scope that reaches the prompt | **`project` only** | root files always; nested ones on touch |

---

## The memory store

`MemoryStore` (`src/Memory/MemoryStore.php`) keeps each entry as its own
markdown file with YAML frontmatter, partitioned **by directory** rather than
only by a `scope:` field:

```
~/.sugar-crush/memory/
├── user/     <uuid>.md …  MEMORY.md
├── project/  <uuid>.md …  MEMORY.md
└── agent/    <uuid>.md …  MEMORY.md
```

Directory partitioning is the point: `project`, `user` and `agent` entries live
at genuinely different physical paths. The `MEMORY.md` index is per-scope for
the same reason — a single shared index could only ever represent one scope, and
every mutation of scope A would silently overwrite what the index last said about
scope B. Touching one scope never reads, writes or deletes another's index.

The index is regenerated on every mutation and is bounded at
`MAX_INDEX_LINES = 200` and `MAX_INDEX_BYTES = 25 * 1024`.

### A naming mismatch worth knowing

The `MemoryScope` enum's cases are `User`, `Project`, `Local` — but every
string-based caller says `user`, `project`, `agent`, and `local` appears nowhere
else in the codebase. `MemoryStore::normalizeScope()` therefore maps
`MemoryScope::Local` **onto the string `agent`** so the two vocabularies name one
physical scope. Without that mapping, a caller passing `MemoryScope::Local` would
write into a `local/` directory no string-based caller ever looks at.

Every public method that takes a scope accepts `string|MemoryScope`.
`search()` and `get()` take no scope at all — they glob across every scope
subdirectory.

### `/memory`

```
/memory list [scope]                      list one scope (default: user)
/memory add <content> [--scope <scope>]    scope: user | project | agent
/memory search <query>                    substring match, across every scope
/memory delete <id>
/memory edit <id> <new_content>
/memory clear --scope <scope> --confirm
```

`--scope` may come before or after the content. `add` creates the entry with
`type: pattern` and no tags; `MemoryEntry` supports `pattern`, `convention`,
`decision` and `preference`, but the chat command only ever writes the first.

If no store was wired, `/memory` answers "Memory store not configured" rather
than failing. `Bootstrap::memoryStoreOrNull()` exists for the same asymmetry:
`MemoryStore`'s constructor throws when `~/.sugar-crush/memory` cannot be
created or is not writable, which is a real reason for `/memory` to report a
failure and *not* a reason to refuse to launch. A broken optional input costs
the feature, never the turn.

### What reaches the prompt — `project` scope, and only that

`Runtime::buildSystemPrompt()` folds in a `MemoryBlock`
(`src/Context/MemoryBlock.php`), captured once per `Runtime` from
`MemoryStore::list(MemoryScope::Project)`. **User-scope and agent-scope entries
never reach the prompt.** `/memory add` defaults to `user`, so an entry you want
the model to see needs `--scope project` explicitly.

The block is bounded, because it is part of the system prompt and therefore paid
for on every step of the agentic loop:

Three bounds, not two — all three are `public const` on
`src/Context/MemoryBlock.php`:

| Bound | Value | Domain |
|---|---|---|
| `MAX_ENTRIES` | 12 | notes rendered, newest first; the rest are dropped and the block says so |
| `MAX_BYTES` | 4096 | the summed **rendered note lines** — `- `, the `[type]`, the content and the `(tags: …)` suffix. Not the `<project-memory>` fence, the header sentence, or the joining newlines. |
| `MAX_ENTRY_BYTES` | 512 | one note's **whole rendered line** — the same span `MAX_BYTES` sums, for a single note. Over it, the line is **truncated with a visible ` […truncated]` marker**, not dropped. |

`MAX_ENTRY_BYTES` is the one that makes the other two honest, in two separate
ways, and it is worth reading the reasons because both were bugs first.

It applies to the **assembled line**, not to `content()` alone. The first version
of the class clipped only the content, so a note carrying a long `type` or many
tags rendered unbounded — the docblock cites a measured case of one entry with
400 tags.

And `MAX_ENTRY_BYTES <= MAX_BYTES` is what makes `MAX_BYTES` a real ceiling
**with no first-entry exemption**: without a per-note cap, the first note has to
be admitted whole or the block can render empty, so the total bound would be
"4096, or one note, whichever is larger". The relation is asserted in the source
rather than assumed. The truncation marker is also paid for *out of* the 512
rather than added on top. Measured — one project note of 5000 `X`s, rendered —
the note line comes back at **exactly 512 bytes** and ends
`XXXXX […truncated]`, not at the 527 it would be if the marker were added on
top of the ceiling.

Truncated rather than dropped is a deliberate choice between three options: a
dropped note is invisible, a note silently cut mid-sentence is actively dangerous
because half an instruction can read as a whole one, and a visibly marked
truncation is something the model can see and discount.

Everything is frozen at `capture()`; `render()` reads no filesystem. So a note
written mid-turn reaches the prompt on the **next** `Runtime`, not the next step.

**Recall is `list()`, not `search()`, and that is deliberate.**
`MemoryStore::search()` is a case-insensitive *substring* match, so passing a
whole user turn as the query asks "does this entire sentence appear verbatim
inside a memory entry" — essentially never true. Recall built that way would be
permanently and silently empty: a wired feature that never fires, which is worse
than an unwired one, because nothing looks broken.

### Importing another tool's memory

`ForeignMemoryImporter` reads Claude Code's `~/.claude/projects/<slug>/memory/`
tree and opencode's `.opencode/memory`, and writes into SugarCrush's own store
tagged `source:<skill-source>` — the same `SkillSource` vocabulary that badges
imported skills and agent presets. It is **read-only by design**: the foreign
tree is harness-managed, so there is no export direction.

**Nothing in `src/` or `bin/` constructs it.** The trigger point — a
`/memory import claude|opencode` subcommand — is not implemented, so importing a
foreign memory tree has no runtime effect today. Imports are also **not
idempotent** (`MemoryStore::add()` mints a fresh UUID per call), which is why
de-duplication is designed to live at the trigger point, in a sentinel file the
caller writes, rather than in the importer: only the caller knows whether a
re-import was intentional.

Dormant is not ungated: `{projectRoot}/.opencode/memory` is a path a *cloned
repository* chooses, so the directory is contained against the checkout and each
`*.md` against the directory it was listed from.

---

## Instruction files

`InstructionFileLoader` (`src/Context/InstructionFileLoader.php`) loads
`CLAUDE.md` and `AGENTS.md`:

- **root files** — always, at session start;
- **forced patterns** from config — glob-resolved, loaded every session;
- **nested files** — a `CLAUDE.md`/`AGENTS.md` in a subdirectory is injected when
  a tool touches a path under it, at most once per session.

`Bootstrap::tools()` threads **one** loader into `Read`, `Edit`, `Glob` and
`Write` so the engine's root reads and the tools' on-touch reads share one
dedup map. Handing them separate loaders would emit the same bytes twice.

### `@import`

Both file types support `@path` imports, expanded by `ImportResolver`:

- `~/...` resolves against the home directory;
- `./...`, `../...` and bare paths resolve against the importing file's
  directory;
- depth is capped at 4, after which the unresolved `@ref` is left as written;
- a reference inside a fenced or inline code span is **skipped**, so documenting
  the syntax does not trigger it;
- a reference to a file that does not exist is left as written.

Only `.md` targets are matched.

One shared "already emitted" set covers all four routes — root, forced,
`@import` inlining and on-touch — which is what stops the same bytes occupying
the context window twice on every turn. This repo is the motivating case: its
root `CLAUDE.md` contains `@./AGENTS.md`, so the resolver inlines `AGENTS.md`
into the `CLAUDE.md` document, and without the shared set `loadRoot()` would
also emit `AGENTS.md` as a second top-level document. It doubles as cycle
protection — a file that imports itself is marked before its own expansion runs.

### Containment

Every read is bounded by the repo root through `ContainedPath` — five call
sites, one per read decision: `loadRoot()`'s root entry, `loadForced()`'s glob
match, `loadForPath()`'s starting directory and its per-level candidate, and
`expandImports()`'s gate closure. The gate closure is threaded through **every**
recursion level, so an allowed file that imports something that imports
something disallowed is still refused.

A refusal is skipped rather than raised — this class's callers are tool results
and it has no channel to the user — but it is **recorded**, and
`refusedPaths()` is the pull-based seam for reading them back.

## On-disk layout summary

```
~/.sugar-crush/
├── config.json        settings, permissions, the four trustedProject* keys
├── settings.json      hand-authored settings   → SETTINGS.md
├── session.db         SQLite session store
├── memory/<scope>/    the memory store
├── agents/*.md        agent presets            → AGENTS_AUTHORING.md
├── skills/*/SKILL.md  skills                   → SKILLS.md
├── commands/*.md      custom slash commands    → COMMANDS.md
├── workflows/         *.php and *.yaml          → WORKFLOWS.md
├── hooks.yaml         hooks                     → HOOKS.md
└── teams/             team state
```

`--config <file>` moves **only** `config.json`. Agents, skills, workflows,
sessions and memory stay in `~/.sugar-crush`.

## See also

- [`ENVIRONMENT.md`](ENVIRONMENT.md) — every variable, including the config
  keys these mechanisms read.
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — where the system prompt is assembled.
