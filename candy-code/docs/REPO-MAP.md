# The repo map: `RepoMap`, the symbol map, and the package map

candy-code gives the model a map of your code in three different ways. They
have different costs and different switches, so this page keeps them apart:

| Name in this page | What it is | When it is used | Switch |
|---|---|---|---|
| **Package map** | Where the code lives: the packages and PSR-4 source directories | Put into the system prompt, every turn | `repoMap.enabled` (default `true`), `repoMap.maxBytes` |
| **Symbol map** | The definitions the rest of the workspace references most, ranked | Captured once per session, at its first turn, into the system prompt | `symbolMap.enabled` (default `true`), or the environment variable `CANDY_CODE_DISABLE_SYMBOL_MAP` |
| **`RepoMap` tool** | The same ranking as the symbol map, drawn on demand and steered by what the model is working on | Called by the model, or by you asking for it | On by default; read-only. The `disabledTools` setting can remove it |

The first two are about what the model sees before it starts. The tool is about
what the model asks for mid-task. The symbol map and the `RepoMap` tool read
`git ls-files --cached --others --exclude-standard`, so ignored files and files
outside the repository are not mapped there, while untracked files that git
does not ignore are. The package
map reads each `composer.json` and the directory tree, and does not consult git.

## The package map

The package map is a short, fixed section in the system prompt that names the
packages and PSR-4 source directories of the project. It is cheap, and on most
projects it is what lets the model find the right directory without a search.

- `repoMap.enabled` turns it off. The default is `true`.
- `repoMap.maxBytes` caps each of its two sections. The default is `8192`
  bytes. Entries past the budget are counted, not listed.
- Both settings are read at the start of every turn, so a change applies to the
  next turn. `repoMap.enabled` can also be set in a project's settings file.

The full setting tables are in [SETTINGS.md](SETTINGS.md).

## The symbol map

The symbol map is the ranked list of definitions that the rest of the code uses
most. It is ranked by PageRank over which file references which definition. It
is a larger block than the package map, and it is the part of the prompt that
costs the most to build on a large checkout, so it has explicit limits:

- It is captured once per session, at the session's first turn, and frozen
  until `/clear` or a rewritten history. It does not change mid-conversation.
- Its budget is 1024 tokens.
- A checkout with more than 3,000 code files gets no symbol map. So does a
  process without enough memory for the ranking.
- Tag extraction has a 4-second budget. If it runs out, the session gets no
  map, and the tags it did read are cached so the next session starts faster.
- The finished map is cached per project under `~/.candy-code/cache/repomap/`.
  On an unchanged tree the cost is one `git ls-files` and a stat per file.
- If the tree changes, the session is served the previous map while a
  background process builds the new one, and the next session uses it. The
  turn does not wait when that detached rebuild can start. When no worker can
  start (no `pcntl`, or an in-memory cache), or there is no previous map yet,
  the build runs in-line under the 4-second budget and the turn waits for it.

To keep it out of the prompt entirely:

```sh
export CANDY_CODE_DISABLE_SYMBOL_MAP=1
```

Any value other than empty or `0` opts out. The `RepoMap` tool still works with
the variable set, so a checkout where you want to skip the prompt cost keeps
the model's ability to ask for a map.

The setting is `symbolMap.enabled` in your user `~/.candy-code/config.json`. A
project settings file cannot set it, and a value there is ignored. It takes
effect when the session starts.

## The `RepoMap` tool

The tool is `RepoMap`, in the read-only permission class. Its one write is the tag cache under
`~/.candy-code/cache/repomap/`.
It runs only fixed read-only helpers: `git ls-files` through `GitRunner`, and,
when Universal Ctags is present, `ctags` too: a `--version` probe, then batched
tag extraction over the non-PHP files. The model calls it when
it wants an outline of the code that matters for the task at hand.

| Argument | Type | Effect |
|---|---|---|
| `max_tokens` | integer | The token budget for the map. Default `1024`, clamped to `256`–`8192` |
| `focus_files` | array of paths | Project-relative files the model is working in. The map ranks what they reference and leaves them out |
| `mentioned_files` | array of paths | Project-relative files the task names. Ranked up, but still shown |
| `mentioned_idents` | array of identifiers | Class, function or method names the task is about. Ranked up |

The reply says how many code files it mapped and the budget it used. For
languages other than PHP, the reply notes whether Universal Ctags is available. A project with more than
10,000 code files is mapped only up to that cap, and the reply header says only
the first N files that git lists were mapped.

### Languages

- **PHP** is parsed by the engine's own tokenizer, with no extra install.
- **Every other language** needs Universal Ctags built with `+json` on `PATH`.
  Without it, the map covers PHP only, and the reply says so.
- The `doctor` tool reports whether Universal Ctags is found.

Files come from `git ls-files --cached --others --exclude-standard`. Ignored
files and files outside the repository are not mapped. The tool needs `git` on
`PATH`.

### Cache

The first map of a checkout parses every file, which can take a while on a
large project. The tags are cached in a per-project SQLite file under
`~/.candy-code/cache/repomap/`, so later calls, and later sessions, pay only
for the files that changed.

## Choosing what to turn off

- **A small project, or a model that already finds its way:** leave all three on.
- **A large checkout where session start is slow:** set
  `CANDY_CODE_DISABLE_SYMBOL_MAP=1`. The tool stays available.
- **A prompt that is too large:** lower `repoMap.maxBytes`, or set
  `repoMap.enabled` to `false`. The model can still ask for a map with the tool.
- **A repository that is not git:** there is no file list, so the tool has
  nothing to map. Use `Glob` and `Grep` instead.

## See also

- [SETTINGS.md](SETTINGS.md): `repoMap.*` and `symbolMap.*` in full
- [ENVIRONMENT.md](ENVIRONMENT.md): `CANDY_CODE_DISABLE_SYMBOL_MAP`
- [CONTEXT.md](CONTEXT.md): how the map fits the context budget
- [PROMPT_ENGINEERING.md](PROMPT_ENGINEERING.md): where each block sits in the system prompt
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md): what the maps do when git is missing
