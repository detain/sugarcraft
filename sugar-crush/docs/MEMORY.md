# Memory and instruction files

Two separate mechanisms put standing knowledge in front of the model. They are
often confused, so they are documented side by side:

| | **Memory store** | **Instruction files** |
|---|---|---|
| Written by | `/memory add`, as UUID-named markdown files | you, by hand |
| Lives in | `~/.sugar-crush/memory/<scope>/<uuid>.md` (`project/<key>/` per project) | `CLAUDE.md` / `AGENTS.md` (or an alias) in the repo; `~/.sugar-crush/AGENTS.md` |
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
├── project/
│   └── <key>/  <uuid>.md …  MEMORY.md   (one directory per project root)
└── agent/    <uuid>.md …  MEMORY.md

<repo>/.sugar-crush/memory/
└── project/  <uuid>.md …            (no index file — derived on read)
```

Directory partitioning is the point: `project`, `user` and `agent` entries live
at genuinely different physical paths. The `MEMORY.md` index is per-scope for
the same reason — a single shared index could only ever represent one scope, and
every mutation of scope A would silently overwrite what the index last said about
scope B. Touching one scope never reads, writes or deletes another's index.

The index is regenerated on every mutation and is bounded at
`MAX_INDEX_LINES = 200` and `MAX_INDEX_BYTES = 25 * 1024`. It carries no
timestamp: its bytes depend on the scope's notes alone, and an index whose bytes
would not change is not rewritten (audit 15d-23).

**A repo's store writes no index file** (audit N2). The repo-local store
(`<repo>/.sugar-crush/memory/`, below) is git-visible, and an index file there is
a second file that every note change edits: two branches that each add a note
both rewrite `MEMORY.md` and conflict on it, although the notes themselves merge
cleanly. The index is derivable, so that store (`MemoryStore::forRepository()`)
keeps only the notes, and `loadIndex()` derives the same bytes from them on read.
A `MEMORY.md` an earlier build generated in a repo scope is removed on the next
change to that scope, so it does not sit in the tree going stale; one written by
hand (anything not opening with the generated `# Memory Index (` header) is left
alone. The home store under `~/.sugar-crush/memory/` is in no repository and
keeps its index file.

**The home store's `project` scope is keyed by project** (audit 15d-05). It used
to be one directory for every repository, so a note written with `--scope
project` where the repository could not host `.sugar-crush/memory` (a read-only
checkout, a `.sugar-crush` symlinked out of the tree) — and every note older than
the repo store — was rendered into the `<project-memory>` block of *every*
repository, under a header calling it a note recorded for this project. A launch
now builds the home store with `MemoryStore::forProject()`, which keeps the scope
at `project/<key>/`: `<key>` is the project root's last path segment plus a
16-hex hash of its canonical path (`MemoryStore::projectKeyFor()`), so two
checkouts both named `app` never share one. The root is the one every
`.sugar-crush/*` lookup uses — the repository, on a launch from one of its
subdirectories ([`SETTINGS.md`](SETTINGS.md)). Listing, search, `get`, the index
and the unreadable-notes scan all see this project's directory alone; the
`user` and `agent` scopes are not project-shaped and stay shared.

**Notes written before keying bind to the first project that reads them.** Any
`*.md` left directly in `~/.sugar-crush/memory/project/` is moved into the
keyed directory of the first launch that touches the scope
(`MemoryStore::bindLegacyProjectNotes()`; `rename()`, so two launches racing in
different roots each take a file at most once, and a name the keyed directory
already holds is left in place). The launch says so once, in both channels — one
row giving the count, the project root and the directory the notes now live in.
A `-p` run that does the moving leaves the record
for the next interactive launch to report. If a note landed in the wrong project,
move its file into the right project's directory beside it.

### Note ids

A note's id is its **file name without `.md`**. That is the id `/memory list`
prints and the one `/memory edit` and `/memory delete` take. `/memory add` mints
a 32-hex UUID, but a hand-written `deploy-notes.md` is the note `deploy-notes`,
and a copied file (`cp <id>.md deploy-variant.md`) is its own note,
`deploy-variant`. The frontmatter `id:` is a copy the store writes and never
reads: it may be missing, and a stale one, such as a copied file's, is rewritten
from the file name on the next edit. Before audit 15d-23 the listing showed the
frontmatter id while the commands looked the file up by name, so such notes were
listed under ids nothing could edit or delete.

An id is letters, digits, `.`, `_` and `-`, at most 64 characters, not starting
with `.`, and never `MEMORY` (the index's name, in any case). The id becomes the
note's path, so the commands refuse anything else, including a path separator
and `..`. A note file whose name is not a valid id (`my notes.md`) is skipped
and reported, like an unreadable note, instead of being listed under an id no
command accepts.

### Hand-edited notes

Notes are plain files and a repo's `.sugar-crush/memory/` is git-visible, so the
reader tolerates the edits people actually make: an unquoted date
(`createdAt: 2024-01-01`, which YAML returns as an int timestamp), a bare
`tags: x` (read as `[x]`), omitted `createdAt`/`modifiedAt` (the file's mtime
stands in), and a `---` inside a value (the frontmatter ends only at a whole
`---` line). A note it cannot read — no frontmatter, a frontmatter that is not a
`key: value` mapping, a missing or non-string `type`/`scope`, a date it
cannot parse, a `tags:` that is not a list of strings — is **skipped**, never
fatal: the rest of the store, and every turn's `<project-memory>` block, keep
working. `MemoryStore::skipped()` returns the skipped files as path => reason
(audit 15d-04), and the `<project-memory>` block reads it: a project note that
could not be read is announced in one bounded line under the listed notes —
naming at most three files, each with its reason, fence-escaped and clipped like
a note — so a missing note is visible to the model instead of silently absent.

The person who wrote the note is told too, outside the prompt. At launch,
`MemoryStore::unreadable()` reads every scope of both stores the session uses
(`~/.sugar-crush/memory` and the repo's `.sugar-crush/memory`), and one aggregate
row reaches the transcript and stderr (a `-p` run, which has no transcript to
show, prints it on stderr), worded by `UnreadableNotes::notice()`:

```text
2 memory notes could not be read and were skipped (project: 1, user: 1); they are not in the prompt — `/memory list <scope>` names each file and why
```

`/memory list <scope>` ends with an **Unreadable notes** section naming each
skipped file of that scope and the reason, and `/memory search` (which reads
every scope) names all of them. A store with nothing unreadable adds nothing to
either answer.

A note written in a legacy encoding is **not** skipped: bytes that are not valid
UTF-8 are replaced with `?` when the note is read (audit 15d-08). Before that,
the YAML reader refused such a note outright, and a body that slipped through
reached the system prompt raw and failed every provider request's JSON encode.

### A naming mismatch worth knowing

The `MemoryScope` enum's cases are `User`, `Project`, `Local` — but every
string-based caller says `user`, `project`, `agent`, and `local` appears nowhere
else in the codebase as a memory spelling. (The only other live `'local'` in
`src/` is the MCP transport alias — `McpClient::TYPE_ALIASES`, E708 — the same
word naming an opencode stdio server, a different vocabulary entirely.)
`MemoryStore::normalizeScope()` therefore maps
`MemoryScope::Local` **onto the string `agent`** so the two vocabularies name one
physical scope. Without that mapping, a caller passing `MemoryScope::Local` would
write into a `local/` directory no string-based caller ever looks at.

Every public method that takes a scope accepts `string|MemoryScope`.
`search()` and `get()` take no scope at all — they read every scope
subdirectory.

### `/memory`

```
/memory list [scope]                      list one scope (default: project)
/memory add <content> [--scope <scope>]    scope: project (default) | user | agent
/memory search <query>                    ranked keyword search, across every scope
/memory delete <id>
/memory edit <id> <new_content>
/memory clear --scope <scope> --confirm
/memory import claude|opencode            one-shot import of a foreign tree
```

`--scope` may come before or after the content, and `list` and `add` both
default to `project`. An `add --scope agent` reply says that agent-scope notes
are listable but never reach the prompt. `add` creates the entry with
`type: pattern` and no tags; `MemoryEntry` supports `pattern`, `convention`,
`decision` and `preference`, but the chat command only ever writes the first
(the `Memory` tool's `save` takes any of the four, and tags).

`search` is a keyword search ranked by BM25 (roadmap 5.3-1). Every word of the
query must appear in a note's content, type or tags, in any order; a word also
matches as a prefix and through the porter stemmer, so `deploy` finds
`deploying`. The best match is listed first, and a word in a note's tags counts
for more than the same word in its body. A note that contains the query only as
a substring (`oy-sta` inside `deploy-staging`) is still found and follows the
ranked ones, so nothing the earlier substring scan found is lost. Query syntax
is never interpreted: `OR`, `NEAR`, `-` and `:` are searched as words.

The ranking comes from `Memory\MemorySearchIndex`, a SQLite FTS5 index that is
only a cache of the note files. Every search re-syncs it first: a note whose
size or modification time changed since it was indexed (a hand edit included)
is read again, and a deleted note leaves it. The home store keeps the cache
beside its scopes as `~/.sugar-crush/memory/.search-<key>.sqlite` (owner-only,
a dot-file no listing reads; deleting it loses nothing). A repository store
keeps its index in memory, so no cache file ever lands in the checkout. A PHP
build whose SQLite lacks FTS5, or a cache that cannot be opened, falls back to
the case-insensitive substring scan, in file order; a damaged cache file is
deleted and rebuilt by the next search.

If no store was wired, `/memory` answers "Memory store not configured" rather
than failing. `Bootstrap::memoryStoreOrNull()` exists for the same asymmetry:
`MemoryStore`'s constructor throws when `~/.sugar-crush/memory` cannot be
created or is not writable, which is a real reason for `/memory` to report a
failure and *not* a reason to refuse to launch. A broken optional input costs
the feature, never the turn.

### The `Memory` tool

The model reads and writes the same notes through the `Memory` tool
(`src/Tools/BuiltIn/MemoryTool.php`, roadmap 5.1-2). One `action` per call:

| Action | Arguments | Does |
|---|---|---|
| `view` | `id` (optional) | the note in full, with its type, scope and store; without an `id`, the whole index grouped by scope and store |
| `save` | `content`, `scope` (`project` default, or `user`), `type` (`pattern` default, `convention`, `decision`, `preference`), `tags` | a new note; content over `ProjectMemoryWriter::MAX_CONTENT_BYTES` (8192) is refused |
| `str_replace` | `id`, `old_str`, `new_str` | replaces the one occurrence of `old_str`; zero or several occurrences are refused |
| `delete` | `id` | removes the note |
| `recall` | `query` | the notes whose content, type or tags match the query, best first (the `/memory search` ranking), at most 20 |

Every write goes through `Memory\MemoryWriter`, the router `/memory add` uses
too, so a note the model saves lands exactly where the user's would: a
`project` note in the repository's `.sugar-crush/memory/` when the tree can
host one, otherwise the home store (and the reply says so). An id resolves in
the repository store first, the same precedence the prompt's index gives a
shared id, so `view`, `str_replace` and `delete` reach the note the index
shows. The tool cannot write `agent` scope; that scope is for imports.

The tool is **no-ask** in the permission gate: it is allowed in every mode,
`plan` and `dont-ask` included, because it writes only the memory directories
and its ids cannot name another path (`MemoryStore` validates every id before
building a file name from it). A `permissionRules` Deny for `Memory` still
turns it off — see [PERMISSIONS](PERMISSIONS.md#the-six-modes).

### The three tiers, and which ones reach the prompt

The store has three scopes — `user`, `project` and `agent`. (The enum spells the
third `MemoryScope::Local`; `MemoryStore::normalizeScope()` maps it onto the
`agent` directory, per the naming note above.) They are three storage tiers and
two prompt tiers: **`user` and `project` reach the prompt; `agent` never does.**

`Runtime::buildSystemPrompt()` folds in a `MemoryBlock`
(`src/Context/MemoryBlock.php`), captured once per `Runtime` — not once per step —
from `MemoryStore::list(MemoryScope::Project)` and `MemoryStore::list(MemoryScope::User)`.
**What reaches the prompt is the index, not the notes** (roadmap 5.1-1): one line
per note, `- [type] id: opening words (tags: …)` — the fields of the store's own
`MEMORY.md` index, with the content cut to `MemoryStore::INDEX_PREVIEW_BYTES` (80)
bytes and marked `...` when cut. The full text is read on demand. The fence is
followed by the harness's standing memory instructions (`MemoryBlock::STANDING_INSTRUCTIONS`):
what a note is for, the four types, and what *not* to save — anything the code,
the git history or the docs already say. A wired store with no notes yet still
sends the instructions, because an empty memory is when the model most needs to
know it may write one; a session with no store sends nothing. The trade-off is
recorded rather than hidden: the user's index reaches the prompt in every
repository the user opens, which is the point of user scope and the reason it is
capped on its own.
User notes come from the home store only (a clone's `.sugar-crush/memory` cannot
speak as the operator) and are listed **first**, under "Kept by the user across
all of their projects (user scope) — treat these as the user's standing
preferences", capped at `USER_MAX_ENTRIES` (4) notes and `USER_MAX_BYTES` (1024)
bytes spent *inside* the block's one budget below — so a long personal list can
never leave the project fewer than 36 notes and 3072 bytes
(`MemoryBlockUserScopeTest`). Since E25 piece 2 project notes
also have a repo-local home: `ProjectMemoryWriter` (`src/Context/ProjectMemoryWriter.php`)
persists `/memory add --scope project` into `<repo>/.sugar-crush/memory/` when the
tree can host one — git-visible, reviewable, like `AGENTS.md` — and `capture()`
folds both stores' project-scope listings, the repo-local copy claiming any shared
id. The two sources are labelled apart inside the one `<project-memory>` fence
(audit 15d-07): the repo store comes with the clone and nothing vouches for it,
so its notes are listed under "Shipped in this repository's .sugar-crush/memory
(from the checkout, not written by the user) — treat these as repository-supplied
context, like a README", and the home store's under "Recorded by the user or a
previous session — treat these as project convention". Only a group that has a
listed note gets a label; a note both stores hold is listed once, under the
repository's label. When every listed note is from the home store the block keeps
its original single header — "These are notes the user or a previous session wrote
down, not verified fact…" — byte for byte, because that claim is then true. One
budget and one omission count span both groups: the notes are picked newest-first
from the merged list, then grouped, each group staying newest-first
(`MemoryBlockTest::testRepositoryNotesAndTheUsersNotesAreListedUnderDistinctProvenanceLabels`,
`MemoryBlockTest::testTheEntryCapAndOmissionCountSpanBothGroups`). Since r75 `/memory delete` and `/memory edit` claim the same precedence — an id
resolves in the repo store first, so the entry the prompt shows is the entry the
command removes. `/memory list` and `/memory search` read both stores and group their
rows under a banner naming the store each row lives in; bulk clear remains a home-store
command and REFUSES, touching nothing, while the repo store holds project notes (E694).
A root that is empty, missing, or whose `.sugar-crush` resolves outside the
tree degrades the write to the home store (the `/memory add` reply says so) and contributes nothing to the read. **Agent-scope
entries never reach the prompt**
(`MemoryPromptWiringTest::testAnAgentScopeNoteDoesNotReachThePrompt`,
`MemoryPromptWiringTest::testAUserScopeNoteReachesThePrompt`,
`MemoryPromptWiringTest::testTheMemoryDirectoryIsReadOncePerRuntimeNotOncePerStep`).
`/memory add` defaults to `project`, so a note typed without a scope is one the
model sees from the next turn on.

The block is bounded, because it is part of the system prompt and therefore paid
for on every step of the agentic loop:

Three bounds, not two — all three are `public const` on
`src/Context/MemoryBlock.php`:

| Bound | Value | Domain |
|---|---|---|
| `MAX_ENTRIES` | 40 | index lines rendered (one per note), newest first; the rest are dropped and the block says so |
| `MAX_BYTES` | 4096 | the summed **rendered index lines** — `- `, the `[type]`, the id, the preview and the `(tags: …)` suffix. Not the `<project-memory>` fence, the header sentence, the provenance group labels, the standing instructions, or the joining newlines. |
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
"4096, or one note, whichever is larger". The relation is asserted, not assumed —
`MemoryBlockTest::testThePerNoteCeilingFitsInsideTheTotalBudget` goes red if it
ever stops holding. The truncation marker is also paid for *out of* the 512
rather than added on top. Measured — one project note tagged with 5000 `X`s
(the content is already cut to its preview, so the tags are what still
scales), rendered — the note line comes back at **exactly 512 bytes** and ends
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

### The `<project-memory>` fence — and why every note line is escaped

`MemoryBlock::render()` wraps the notes in a `<project-memory> … </project-memory>`
fence, and `MemoryBlock::fence()` names that tag for the prompt's section
machinery. Memory entries are untrusted, user-authored bytes that reach the prompt
verbatim, so a note carrying its own `</project-memory>` would otherwise close the
block early and forge whatever follows it — the same trust boundary the injected
`<system-reminder>` fence exists to hold.

So `MemoryBlock::renderEntry()` runs every assembled note line through
`PromptFence::escape()` before the per-entry clip. `PromptFence` is the single
authority owning the prompt's fence-tag roster — `env`, `project-memory`,
`repo-map`, `project-instructions`, `system-reminder`, `user-rules`,
`prior-summary`, `harness-injected`, `available-skills`, `turn-context`, `system-notice` — and its `escape()` rewrites only the leading
`<` of a recognised open/close tag to `&lt;`. A tag carrying attributes counts,
however it is spelled — `<system-reminder priority="high">`, an attribute list broken
across lines, an opener with no `>` at all — because a model reads each as the
channel opening. The same rewrite defangs chat-template control tokens (audit
15d-10): the `<` of `<|` or `<｜` (the fullwidth U+FF5C bar), with an optional
`/`, so `<|im_start|>` and `<｜User｜>` reach the prompt as `&lt;|im_start|>` and
`&lt;｜User｜>` — a `&lt;|` in a note's prompt rendering is this rewrite. Beyond those it touches
nothing else (`3 < 4` stays as written), it is idempotent, and the promise it makes is body-level: a clean note body passes
through byte-for-byte unchanged before the line prefix is applied, while a note
forging a fence arrives as `&lt;/project-memory>` and cannot close the block it
lives in (`MemoryBlockTest::testANoteForgingItsOwnClosingFenceRendersOneBalancedFence`,
`MemoryBlockTest::testACleanNoteIsRenderedByteIdenticalToTheEscapeAuthorityTransparencyPromise`).

The escape runs *before* the clip on purpose, so `MAX_ENTRY_BYTES` bounds the
already-escaped line: an entry of nothing but fence tags still fits inside the 512
ceiling rather than bloating past it once each `<` costs four bytes
(`MemoryBlockTest::testAnEntryOfNothingButFenceTagsStillStaysInsideThePerNoteCeiling`).

### Importing another tool's memory

`ForeignMemoryImporter` reads Claude Code's `~/.claude/projects/<slug>/memory/`
tree and opencode's `.opencode/memory`, and writes into SugarCrush's own store
tagged `source:<skill-source>` — the same `SkillSource` vocabulary that badges
imported skills and agent presets. It is **read-only by design**: the foreign
tree is harness-managed, so there is no export direction.

**`Chat::memoryImport()` constructs it behind `/memory import claude|opencode`**
(wired in P7.S6). The importer writes every entry with `MemoryScope::Local`, which
`MemoryStore::normalizeScope()` lands in the `agent` directory — so imported
entries reach `/memory list agent` and `/memory search`, and, per the
project-scope-only policy above, are **deliberately never folded into the prompt**.
That is the real reason an import cannot crowd the prompt block: it is a *scope
separation*, not a size limit. `MemoryBlock::capture()` reads the `project` list
alone, so however many entries pile into `agent` the captured block is unchanged
(`MemoryImportCommandTest::testImportBeyondTwelveSucceedsAndCannotTouchThePromptBlock`,
`MemoryImportCommandTest::testRenderCapBoundsOnlyTheProjectScopeThePromptFolds`).
There is accordingly **no entry cap on `/memory import`** and no prompt-cap clamp
on its response — none is needed once imports and the prompt share no scope.

A project-scope write is stricter than a home one: the project store REFUSES an
oversized note outright, while the same note filed to the home store would have
landed — a deliberate, loud asymmetry rather than a silent truncation.

Imports are **not idempotent** (`MemoryStore::add()` mints a fresh UUID per call),
which is why de-duplication lives at the trigger point rather than in the importer:
the command writes a sentinel at `.sugar-crush/memory/.imported-<target>` in the
project, at its repository root (the same root every other `.sugar-crush/*` lookup
uses, so a subdirectory launch and a launch from the top share it), after a non-empty import, and refuses to import again while that file
exists — delete it to re-import. Only the caller knows whether a re-import was
intentional.

The gate is not the wiring: `{projectRoot}/.opencode/memory` is a path a *cloned
repository* chooses, so the directory is contained against the checkout and each
`*.md` against the directory it was listed from.

Claude Code's own relocations are followed (audit R11): `CLAUDE_CONFIG_DIR`
replaces `~/.claude`, and while it is set `CLAUDE_CODE_PROJECT_DIR_NAME` replaces
`<slug>` — under Claude Code's rules for both, which
[`ENVIRONMENT.md`](ENVIRONMENT.md#claude-code-variables) tabulates. A relative,
world-writable or foreign-owned `CLAUDE_CONFIG_DIR` is refused, like the derived
home, and the refusal is named in the reply.

---

## Instruction files

`InstructionFileLoader` (`src/Context/InstructionFileLoader.php`) loads
`CLAUDE.md` and `AGENTS.md`, plus three other agents' spellings of the same
convention — `GEMINI.md`, `.cursorrules` and `.clinerules` — in that precedence
order (`InstructionFileLoader::FILENAMES`):

- **your personal file** — `~/.sugar-crush/AGENTS.md`, always, at session start,
  in every project. It is priced first under the same instruction budget and
  framed in your own voice (the `<user-rules>` fence and preamble your
  `~/.sugar-crush/rules` use), ahead of anything a repository ships. Only an
  owned home is consulted (`HomeDirectory::owned()`);
- **root files** — always, at session start;
- **forced patterns** from config — glob-resolved, loaded every session;
- **nested files** — a `CLAUDE.md`/`AGENTS.md` (or alias) in a subdirectory is
  injected when a tool touches a path under it, at most once per session.

`/init` asks the agent to write one: it studies the checkout and writes (or
improves) `AGENTS.md` at the root, which the loader reads from the next session
on — see [`COMMANDS.md`](COMMANDS.md#the-built-in-commands).

An alias is only another candidate name: it passes the same containment gate,
size ceiling, UTF-8 scrub, `@import` expansion and dedup set as `CLAUDE.md`. A
`.clinerules` *directory* (Cline's folder form) is not read.

`Bootstrap::tools()` threads **one** loader into `Read`, `Edit`, `Glob`, `Grep`
and `Write` so the engine's root reads and the tools' on-touch reads share one
dedup map. Handing them separate loaders would emit the same bytes twice.

Every one of those reads, and every `@import`, is scrubbed to valid UTF-8 as it
is loaded (`Utf8Scrub`, audit 15d-08): each invalid byte sequence becomes `?`,
and the document gains a trailing `[encoding: N byte sequence(s) of <file> were
not valid UTF-8 and were replaced with "?".]` line naming the file. The system
prompt is JSON-encoded into every request, so one Latin-1 byte in a `CLAUDE.md`
used to fail every turn of the session.

### `@import`

Both file types support `@path` imports, expanded by `ImportResolver`:

- `~/...` resolves against the home directory as `HomeDirectory::owned()`
  answers it — with no home this user owns (unset, relative or world-writable
  `$HOME`) the reference is left as written — **and is then refused**: the
  containment gate below judges it like every other import, so a `~/` import
  renders `<import-blocked reason="outside-repo-root">` unless the home directory
  lies inside the importing file's checkout. In practice `@~/my-conventions.md`
  in a project `CLAUDE.md` is always blocked (audit 15d-11;
  `ImportResolverTest::testATildeImportThroughTheLoaderIsBlockedAsTheDocsSay`);
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

Every read is bounded by its own root through `ContainedPath` — seven call
sites, one per read decision: `loadPersonal()`'s personal entry (bounded by
`~/.sugar-crush`), `loadRoot()`'s root entry,
`loadAncestorRoots()`'s ancestor entry, `loadForced()`'s glob match,
`loadForPath()`'s starting directory and its per-level candidate, and
`expandImports()`'s gate closure. The gate closure is threaded through **every**
recursion level, so an allowed file that imports something that imports
something disallowed is still refused.

A refusal is skipped rather than raised — this class's callers are tool results —
but it is **recorded**, and `refusedPaths()` is the pull-based seam for reading
them back. The user hears about them twice over: the launch names the root,
ancestor, forced and imported files the prompt leaves out, and a nested
`CLAUDE.md`/`AGENTS.md` refused or deferred when a tool touches a path under it
(a link out of the checkout, a file over the 60 KiB ceiling) gets one
transcript notice the first time it happens.

## On-disk layout summary

```
~/.sugar-crush/
├── config.json        settings, permissions, the four trustedProject* keys
├── AGENTS.md          your personal instruction file (every project)
├── settings.json      hand-authored settings   → SETTINGS.md
├── session.db         SQLite session store
├── memory/<scope>/    the memory store (project/<key>/ per project)
├── memory/.search-<key>.sqlite  the search cache (derived; safe to delete)
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
