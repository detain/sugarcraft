# candy-serve — Interactive SSH TUI milestone plan

**Status:** plan-only · **Owner:** product call → phase-1 implementer · **Branch:** `ai/plan-candy-serve-tui`

**Origin:** `plans/leftover_updates_later.md` §6 strategic-decision items ·
`docs/research/libraries/candy-serve-research.md` §3.1 + §5 ·
`plans/leftover/phase-11-strategic-plans/step-02-candy-serve-tui.md`

> This document does **not** ship code. It is a milestone scope memo. Once
> the user authorizes phase 1, an implementer drafts `plans/leftover/
> phase-12-candy-serve-tui/` step files drawing from §5 below.

---

## TL;DR

`candy-serve` is a faithful port of
[`charmbracelet/soft-serve`](https://github.com/charmbracelet/soft-serve)
plumbing — SSH transport (`SSH/SSHServer.php`, 232 LOC),
Git wire protocol (`Git/UploadPack.php` 176 LOC + `Git/ReceivePack.php`
171 LOC), LFS batch (`LFS/LFSHandler.php` 242 LOC), access control
(`AccessControl.php` 97 LOC), users (`User.php` 196 LOC), repos
(`Repo.php` 284 LOC), YAML config (`Config.php` 295 LOC). **What is
missing is soft-serve's marquee feature: the interactive SSH TUI that
lets a user `ssh user@host` and browse repos, files, and commits.**

This plan partitions the work into **five phases**:

1. **TUI session manager + repo list view** — entry point + list screen.
2. **Repo detail view** — branches / tags / collaborators / clone-cmd.
3. **File browser + file viewer** — tree navigation + syntax-highlighted
   blob display.
4. **Commit log view** — `git log` browser with diff preview.
5. **Polish** — README markdown rendering, OSC 52 clipboard, help
   panel, theming.

Estimated effort: **6–9 weeks** of focused work for phases 1–5. Phases
1–3 are the MVP that justifies the "soft-serve port" label; phases 4–5
are polish that brings parity with upstream.

**Out of scope** for the TUI plan: HTTP smart protocol server,
`git://` daemon, real daemon mode (signals / PID files), OAuth /
LDAP / OIDC auth, GitHub-style issues / PRs / wikis (those are
non-features of upstream soft-serve, never planned).

---

## 1. Goal

Replicate upstream soft-serve's interactive SSH session: a user runs
`ssh user@host` (no command), gets dropped into a full-screen TUI, and
can:

- See a list of repos they have read access to (public + collaborator).
- Drill into a single repo to see branches, tags, collaborators, and
  the clone URL.
- Browse the repo file tree at HEAD (or pick a branch / tag).
- View any file with line numbers + syntax highlighting.
- Browse the commit log with author / message / hash; pop into a
  per-commit diff.
- Press `c` on a repo to copy the clone command (OSC 52 → host
  clipboard).
- Press `?` for a help panel.
- Press `q` / `Ctrl-c` to exit.

The TUI is **read-only**. Writes (push, repo create) stay on the
existing Git wire-protocol paths (`UploadPack`, `ReceivePack`) — adding
write surface to the TUI would mean a commit editor and an auth-prompt
flow that is well out of upstream soft-serve's scope.

---

## 2. Architecture

```
SSH client
    │
    │  ssh user@host          ← no command
    ▼
candy-wish  Server               (existing — SSH listener + auth)
    │  PasswordAuth / PublicKeyAuth
    ▼
candy-wish  Channel              (existing — pty-req, shell, window-change)
    │  Shell request
    ▼
candy-serve  TuiSession         (NEW — phase 1)
    │  builds context (user, accessible repos)
    ▼
candy-core  Program             (existing — Bubble Tea runtime)
    │  Model / update / view
    ▼
candy-serve  RepoListModel ←→ ChildModels (NEW — phases 1–5)
    │
    ├── candy-sprinkles  Style          (border, fg, bg, padding)
    ├── sugar-boxer      SugarBoxer     (titled boxes / split panes)
    ├── sugar-stickers   Viewport       (scrolling lists, scrollbar)
    ├── candy-shine      Renderer       (markdown → ANSI for README)
    ├── candy-shine      SyntaxHighlighter (file viewer colourisation)
    ├── sugar-stash      Git            (`log`, `show`, `cat-file`, `ls-tree`)
    ├── candy-core       Cmd::setClipboard()   (OSC 52 emitter, phase 5)
    └── candy-vt         (terminal-state model used by candy-core writer)
```

### 2.1 SSH → TUI plumbing (candy-wish handles it)

candy-wish already has the pieces we need:

| candy-wish surface | What it gives us |
|---|---|
| `SugarCraft\Wish\Server` | accepts a connection, runs middleware chain |
| `SugarCraft\Wish\Channel\ChannelHandler` interface | per-channel events (shell / exec / pty-req / window-change) |
| `SugarCraft\Wish\Channel\DefaultChannelHandler` | default impl we wrap |
| `SugarCraft\Wish\Transport\InProcessTransport` | runs in this PHP process (no host sshd) |
| `SugarCraft\Wish\Transport\HostSshdTransport` | opt-in: shell out to host sshd |

candy-serve's current `SSH/SSHServer.php` uses `ext-ssh2`'s sftp-style
session model and only routes Git wire-protocol exec'd commands. The
TUI plan **replaces this with candy-wish**:

- Connection arrives.
- candy-wish authenticates against `User::keys` (existing model).
- If client sent `git-upload-pack` / `git-receive-pack` as the exec
  command, route to `UploadPack` / `ReceivePack` (unchanged behaviour).
- If client sent **no exec command** (interactive `ssh user@host`),
  start a TUI session.

This consolidates SSH on candy-wish and unblocks Window-change /
PTY-resize handling for free (candy-wish ships those Msgs since step
06.09).

> **Dependency note:** phase 1 implementer **must** evaluate whether to
> keep `ext-ssh2`'s `SSHServer.php` for Git wire and only use candy-wish
> for the TUI shell path, or rip out `ext-ssh2` entirely. The latter is
> cleaner but ports of the wire-protocol handlers across two
> transports doubles the surface area. Recommendation: **keep ext-ssh2
> for `git-upload-pack` / `git-receive-pack`; introduce candy-wish
> alongside it for the TUI path.** Both listeners can share the
> same `User` / `AccessControl` registry.

### 2.2 TUI runtime (candy-core)

candy-core ships `Program` (event loop) + `Model` (interface) + `Msg`
hierarchy. The TUI's root model holds a stack of child models, one per
"screen". The ScreenStack helper landed in step 06.02
(`candy-core/src/ScreenStack.php`, plus
`candy-core/src/ScreenStackCapable.php`,
`candy-core/src/RootModelWithScreenStack.php`, and the
`candy-core/src/Msg/ScreenStackPushedMsg.php` event) — TUI navigation
uses `ScreenStack::push()` / `pop()` rather than re-implementing nav.

### 2.3 Git access (sugar-stash)

sugar-stash already shells out to `git` and parses its output into PHP
DTOs (`sugar-stash/src/Git.php` implements `GitDriver`). candy-serve
will use sugar-stash's `Git` driver for `log` / `branch` / `tag` /
`show` / `ls-tree` / `cat-file`. **We do not invent a second Git
shell-out path**; if a helper is missing in sugar-stash it gets added
upstream there and consumed here.

`Repo.php` already exposes the bare-repo metadata the TUI needs:
`Repo::$isPublic` (readonly bool property), `Repo::withDescription()`
(immutable+fluent setter — there is no `setDescription()`), and
`Repo::readme()` (method, `candy-serve/src/Repo.php:L251`). Those stay
in place; the TUI just composes them.

### 2.4 Layout primitives

- **sugar-boxer** — titled bordered boxes via `SugarBoxer`
  (`sugar-boxer/src/SugarBoxer.php`); we use it for the outer frame
  around each pane.
- **sugar-stickers** — `Viewport` for the scrollable lists (repo list,
  file tree, commit log) and `Scrollbar` to render the indicator.
- **candy-sprinkles** — `Style` for inline runs (highlight selected
  row, dim metadata text, brand colour for branch names).
- **candy-shine** — `Renderer` for README markdown, `SyntaxHighlighter`
  for file blob view.

### 2.5 Theming

Phase-5 polish only. Default theme = candy-sprinkles `Theme::dracula()`
(or similar dark theme). User-side override deferred until upstream
soft-serve ships theming primitives (it does not at time of writing).

---

## 3. User stories

| # | As a … | I want to … | So that … |
|---|---|---|---|
| US-1 | reader with SSH access | `ssh git@host` and see all public repos plus my private ones | I have one place to discover what is hosted |
| US-2 | reader | move with arrows / `j` / `k` and press `Enter` to open a repo | navigation feels native |
| US-3 | reader | press `q` or `Esc` to back out one screen at a time | I am not trapped |
| US-4 | reader | see a repo's branches, tags, collaborators, and clone command | I can locate the right ref + know how to clone |
| US-5 | reader | press `c` on the repo detail view to copy the clone command to my host clipboard | I do not have to retype the URL |
| US-6 | reader | browse the repo's file tree at HEAD (or any branch / tag) | I do not have to clone to see what is inside |
| US-7 | reader | open any file and see it line-numbered + syntax-highlighted | code review without cloning |
| US-8 | reader | scroll the commit log and pick a commit to see its diff | I can audit history over SSH |
| US-9 | reader | press `?` from any screen to see the keyboard shortcuts | discoverability without docs |
| US-10 | reader of a repo with a README | see the README rendered (not raw markdown) on the repo detail screen | the landing experience matches the web |
| US-11 | admin | see private repos owned by other users in the list | per-user access control still applies |
| US-12 | reader on a slow link | not have the TUI block on file reads | the UI stays responsive |

Non-stories (deliberately out of scope):

- "I want to comment on a commit" — soft-serve has no issue tracker.
- "I want to file an issue / open a PR" — out of scope (separate lib if
  ever); see §7.
- "I want to edit a file" — write-side TUI is not in upstream.
- "I want web-based browsing" — covered by `gitea` / `cgit`; candy-serve
  stays SSH-only per upstream.

---

## 4. Layout sketch — main screens

Mock-ups are 80×24 (the smallest size soft-serve targets). Boxes are
sugar-boxer; arrow keys + `j`/`k` move; `Enter` activates; `Esc`/`q`
goes back; `?` opens help.

### 4.1 Repo list view (phase 1)

```
┌────────────────────────── CandyServe · git@host ────────────────────────────┐
│                                                                              │
│  ▸ sugarcraft         PHP port of the Charm ecosystem        public  2d ago  │
│    dotfiles           my personal config                     private 3w ago  │
│    notes              notes and scratchpad                   private 4d ago  │
│    candy-recipe       new recipe-tracker TUI                 public  1h ago  │
│                                                                              │
│                                                                              │
│                                                                              │
│                                                                              │
│  ▒ 1 / 4                                                                     │
│                                                                              │
│  ↑/↓ select  enter open  c copy-clone  ? help  q quit                        │
└──────────────────────────────────────────────────────────────────────────────┘
```

- Left gutter chevron (`▸`) marks selection.
- Right column shows visibility + last-activity (relative).
- Footer is the keymap (sugar-stickers shows scrollbar at right when
  list exceeds height; here it does not).

### 4.2 Repo detail view (phase 2)

```
┌──────────────────── sugarcraft · CandyServe / sugarcraft ───────────────────┐
│  Description  PHP port of the Charm ecosystem (TUI libraries)               │
│  Default      master                                                         │
│  Clone        ssh://git@host:23231/sugarcraft                               │
│                                                                              │
│  ┌── Branches ──────────────┐  ┌── Tags ──────────────┐  ┌── Collabs ───┐  │
│  │ ▸ master                  │  │ v0.4.0                │  │ joe (admin)  │  │
│  │   feat/tui-detail-view    │  │ v0.3.2                │  │ alice (rw)   │  │
│  │   ai/plan-candy-serve-tui │  │ v0.3.1                │  │ bob   (r)    │  │
│  │   …                       │  │ …                     │  │              │  │
│  └───────────────────────────┘  └───────────────────────┘  └──────────────┘  │
│                                                                              │
│  README (rendered, via candy-shine)                                          │
│  ─────────────────────────────────────────────                               │
│  # SugarCraft                                                                │
│  PHP monorepo of 45+ TUI library ports …                                    │
│                                                                              │
│  tab next-pane  enter open  f files  l log  c copy-clone  esc back  ? help  │
└──────────────────────────────────────────────────────────────────────────────┘
```

- Top metadata block is static text.
- Three side-by-side panes for branches / tags / collaborators (use
  sugar-stickers `FlexBox` to lay them out).
- README rendered through `candy-shine\Renderer`.
- `tab` cycles focus between the three list panes.
- `f` jumps to file browser, `l` jumps to commit log.

### 4.3 File browser view (phase 3)

```
┌─────────────── sugarcraft · master · /src/ ──────────────────────────────┐
│  ▸ Foundation/                                                            │
│    Components/                                                            │
│    Plot/                                                                  │
│    Events/                                                                │
│    Keys/                                                                  │
│    State/                                                                 │
│    Module.php             3.1 KB                                          │
│    Dashboard.php          7.4 KB                                          │
│    Lang.php               412 B                                           │
│                                                                           │
│  ▒ 2 / 9                                                                  │
│  enter open  ← parent  → enter dir  b branch/tag  esc back  ? help        │
└───────────────────────────────────────────────────────────────────────────┘
```

- Selected ref shown in title (default `master`); `b` pops a small
  modal to pick a branch/tag (sugar-bits `Selector`).
- Dirs sort first (alpha), then files (alpha).
- Sizes for files only.

### 4.4 File viewer (phase 3)

```
┌────────── sugarcraft · master · src/Foundation/Buffer.php ───────────────┐
│   1  <?php                                                                │
│   2                                                                       │
│   3  declare(strict_types=1);                                             │
│   4                                                                       │
│   5  namespace SugarCraft\Dash\Foundation;                                │
│   6                                                                       │
│   7  /**                                                                  │
│   8   * Cell grid for ANSI rendering.                                     │
│   9   */                                                                  │
│  10  final class Buffer                                                   │
│  11  {                                                                    │
│  12      public function __construct(                                     │
│  13          public readonly int $width,                                  │
│  ▒ 13 / 244                                                               │
│  ↑/↓/PgUp/PgDn scroll  g/G start/end  /search  esc back  ? help           │
└───────────────────────────────────────────────────────────────────────────┘
```

- Lines wrapped to viewport width (no horizontal scroll in v1; line
  too long → soft wrap with continuation indicator).
- Line numbers gutter (4-char right-aligned).
- Body coloured via `candy-shine\SyntaxHighlighter`, language detected
  by extension.

### 4.5 Commit log (phase 4)

```
┌──────────── sugarcraft · master · log ───────────────────────────────────┐
│  ▸ e9667b5f  Joe Huss     2d ago   updates.md: log docs PR for step 07. │
│    c30dda36  Joe Huss     2d ago   Merge pull request #648 from detain… │
│    48168ed2  Joe Huss     2d ago   docs: candy-mosaic HalfBlock transp… │
│    5d3f8aef  Joe Huss     2d ago   tests-ci for step 07.14 · clean      │
│    24e68248  Joe Huss     2d ago   updates.md: add step 07.14 done log… │
│    …                                                                     │
│  ▒ 1 / 1284                                                              │
│  enter show-diff  /search  b branch  esc back  ? help                    │
└───────────────────────────────────────────────────────────────────────────┘
```

`enter` opens a sub-screen showing the commit's diff (text only —
syntax highlighting per file, with `+`/`-` colourisation via
candy-sprinkles).

### 4.6 Help panel (phase 5)

Modal overlay (sugar-bits `Toast`-style) showing the global keymap +
per-screen extras. Press `?` again or `Esc` to dismiss.

---

## 5. Phases

### Phase 1 — TUI session + repo list (≈ 2 weeks)

Smallest shippable unit. Lands the candy-wish ↔ candy-serve handshake
and the repo-list screen.

**New files:**

- `candy-serve/src/Tui/TuiSession.php` — bridge from candy-wish channel
  to candy-core `Program`; resolves the authed user and starts a
  `RepoListModel`.
- `candy-serve/src/Tui/RepoListModel.php` — Bubble Tea Model returning
  `[Model, ?Cmd]` from `update()`.
- `candy-serve/src/Tui/RepoListView.php` — pure render function called
  by `RepoListModel::view()`.
- `candy-serve/src/Tui/Theme.php` — palette wrapper around
  candy-sprinkles `Theme`.
- `candy-serve/bin/soft-serve-tui` — TUI entry-point script that opens
  the TUI on stdin/stdout (used by candy-wish channel handler and as a
  standalone debug command). Lives under `bin/` next to the existing
  `bin/soft-serve` per current candy-serve precedent. (The original
  draft proposed a new top-level `cmd/` directory; review folded it
  back into `bin/` to avoid introducing a new convention. A future
  step may also expose this as a `soft-serve tui` subcommand inside
  the existing `bin/soft-serve` dispatcher — either path is fine as
  long as both live under `bin/`.)
- `candy-serve/src/SSH/TuiChannelHandler.php` — `ChannelHandler` that
  delegates exec to existing `UploadPack`/`ReceivePack` and otherwise
  pipes the candy-core Program over the channel.

**Changed files:**

- `candy-serve/composer.json` — add `sugarcraft/candy-wish`,
  `sugarcraft/candy-core`, `sugarcraft/candy-sprinkles`,
  `sugarcraft/sugar-stash`, `sugarcraft/sugar-boxer`,
  `sugarcraft/sugar-stickers`, `sugarcraft/candy-shine` as `@dev` plus
  matching path-repo entries (closure via `tools/check-path-repos.php
  --fix`).
- `candy-serve/bin/soft-serve` — add `tui` subcommand.
- `candy-serve/src/SSH/SSHServer.php` — branch on incoming exec
  command; non-exec sessions instantiate `TuiChannelHandler`.
- `candy-serve/lang/en.php` (+ 15 other locales) — keymap labels and
  list headers.

**Tests (≥ 30):**

- `TuiSessionTest` — given a user with N accessible repos, the resolved
  list matches `AccessControl::repos($user)`.
- `RepoListModelTest` — arrow keys move selection; Enter emits a
  navigation Msg; `c` emits a clipboard Cmd; `q` returns the
  quit Cmd; `?` toggles help.
- `RepoListViewTest` — snapshot of the rendered 80×24 string for
  0-repo / 1-repo / 4-repo / overflow (cursor parking) cases.

**Acceptance:**

- `ssh user@host` (with no command) starts the TUI and shows the repo
  list rendered through candy-sprinkles + sugar-boxer.
- `git clone ssh://user@host:23231/<repo>` still works (existing
  path).
- `vendor/bin/phpunit` green in candy-serve + all four consuming libs
  (candy-wish, candy-core, sugar-stash, candy-shine).
- `cd candy-serve && composer validate` clean (drop `--strict`).

### Phase 2 — Repo detail view (≈ 1.5 weeks)

**New files:**

- `candy-serve/src/Tui/RepoDetailModel.php`
- `candy-serve/src/Tui/RepoDetailView.php`
- `candy-serve/src/Tui/PaneFocus.php` — small enum cycling
  `Branches → Tags → Collaborators` for `tab`.

**Changed files:**

- `RepoListModel::update()` — `Enter` pushes `RepoDetailModel` on
  ScreenStack.
- `candy-serve/lang/*.php` — strings for branches / tags / collabs /
  clone-cmd labels.

**Dependencies (hard preconditions — see §6.1):**

- sugar-stash `Git::branches()` ✅ already ships.
- sugar-stash `Git::tags()` — **NOT YET SHIPPED**. Phase 2 cannot open
  until the sugar-stash `tags()` PR has merged. Do **not** start
  phase-2 step files until §6.1 reports that row green.

**Tests (≥ 20):** snapshot tests for the three sub-panes; behaviour
test for `tab` focus rotation; behaviour test for `c` emitting the
clone-URL OSC-52 Cmd (via candy-core `Cmd::setClipboard()`).

**Acceptance:** drilling into any repo shows branches / tags /
collaborators panes; `c` copies clone URL; `Esc` returns to list with
selection preserved.

### Phase 3 — File browser + file viewer (≈ 2 weeks)

The chunkiest phase — two new screens plus syntax highlighting.

**New files:**

- `candy-serve/src/Tui/FileTreeModel.php`
- `candy-serve/src/Tui/FileTreeView.php`
- `candy-serve/src/Tui/FileViewerModel.php`
- `candy-serve/src/Tui/FileViewerView.php`
- `candy-serve/src/Tui/RefSelectorModal.php` — small overlay listing
  branches + tags, returned-on-pick.
- `candy-serve/src/Tui/LanguageDetector.php` — map extension → language
  string consumed by candy-shine `SyntaxHighlighter`.

**Dependencies (hard preconditions — see §6.1):**

- sugar-stash `Git::lsTree($ref, $path)` — **NOT YET SHIPPED**.
- sugar-stash `Git::catFile($ref, $path)` — **NOT YET SHIPPED**.
  Phase 3 cannot open until **both** sugar-stash patches have merged.
- candy-shine `SyntaxHighlighter::highlight($source, $lang)` — already
  shipped; verify per-language fallback (returns plain text if `$lang`
  unknown).

**Tests (≥ 30):**

- `FileTreeModelTest` — navigation up/down/enter/left/right; dirs sort
  before files; selection state when leaving and re-entering.
- `FileViewerModelTest` — scroll Msgs (`j`/`k`/PgUp/PgDn/`g`/`G`);
  search Msg (`/` → input modal → match navigation `n`/`N`).
- Snapshot tests for both views at 80×24 and 120×40.
- Coercion tests: empty dir, binary file (skip highlight, show "binary
  file" placeholder), file >1 MB (refuse — show size guard).

**Acceptance:** can walk `/`, open any subdir, open any file, see it
syntax-highlighted; binary files refuse to render (skip
syntax-highlight, show placeholder); files >1 MB refuse to load.

### Phase 4 — Commit log + diff view (≈ 1.5 weeks)

**New files:**

- `candy-serve/src/Tui/CommitLogModel.php`
- `candy-serve/src/Tui/CommitLogView.php`
- `candy-serve/src/Tui/DiffModel.php`
- `candy-serve/src/Tui/DiffView.php`

**Dependencies (hard preconditions — see §6.1):**

- sugar-stash `Git::log($ref, $skip, $limit)` — **NOT YET SHIPPED**.
  The current signature is `log(int $limit = 25)`; it needs to broaden
  to accept `$ref` + `$skip` (backwards compatible). Required so the
  TUI does not load all 10k commits up front.
- sugar-stash `Git::show($sha)` — **NOT YET SHIPPED** (the existing
  `Git.php` has no `show()` method). Required for `DiffView`.
  Phase 4 cannot open until both have merged.

**Tests (≥ 25):**

- `CommitLogModelTest` — j/k/PgUp/PgDn paging triggers `LoadMoreMsg`
  when within 20 rows of the loaded tail.
- `DiffModelTest` — split view (file list left, hunk right) with `tab`
  to switch focus.
- Snapshot tests for log + diff at 80×24 and 120×40.

**Acceptance:** can scroll the log of a repo with thousands of commits
without freezing the event loop (paged); open any commit and see the
diff with file-level colourisation.

### Phase 5 — Polish (≈ 1 week)

**Includes:**

- README markdown rendering on `RepoDetailView` via candy-shine
  `Renderer` (the `README` block in the §4.2 mock-up).
- OSC 52 clipboard for the `c` keybind on both `RepoListView` and
  `RepoDetailView` — consume candy-core's existing
  `Cmd::setClipboard($url, 'c')` (`candy-core/src/Cmd.php:L267`,
  shipped). No candy-vt prerequisite (candy-vt has OSC 52 *read*
  support; emit lives in candy-core via `Util/Ansi::setClipboard`).
- HelpPanel modal (`?` keybind from any screen).
- Theme polish — pick default theme; map keyboard cues consistently
  across all screens (current Charm convention: dim grey for hints,
  brand colour for selection).

**Tests (≥ 20):** modal lifecycle (push / dismiss / Esc),
README-rendering snapshot, OSC-52 byte-level emission test.

**Acceptance:** README renders on detail view; `c` actually writes
clone URL to host clipboard on Kitty / iTerm2 / WezTerm / xterm.js;
`?` brings up help on every screen.

---

## 6. Dependencies — cross-link to other plan steps

This plan does **not** create new step files. Phase-1 starts by drafting
`plans/leftover/phase-12-candy-serve-tui/step-{01..15}.md` under user
authorization.

Pre-conditions that **must** be true before phase-1 ships:

| Dep | Status | Where it lives | Notes |
|---|---|---|---|
| candy-wish ChannelHandler API | ✅ landed step 06.09 (PR #607) | `candy-wish/src/Channel/` | needed for TUI vs exec routing |
| candy-wish Session metadata | ✅ landed step 06.12 (PR #613) | `candy-wish/src/Session.php` | user-id, key-fingerprint surface |
| candy-wish async Middleware | ✅ landed step 06.13 (PR #615) | `candy-wish/src/Middleware.php` | TUI session is long-lived |
| candy-core ScreenStack | ✅ landed step 06.02 (PR #583) | `candy-core/src/ScreenStack.php` | TUI nav stack |
| candy-core Component lifecycle | ✅ landed step 06.03 (PR #587) | `candy-core/src/Component.php` | onMount / onUnmount for modals |
| candy-core OSC 52 emitter | ✅ shipped | `candy-core/src/Cmd.php` (`Cmd::setClipboard()`, L267) | clipboard for `c` keybind (phase 5) |
| candy-shine Renderer | ✅ shipped | `candy-shine/src/Renderer.php` | README block |
| candy-shine SyntaxHighlighter | ✅ shipped | `candy-shine/src/SyntaxHighlighter.php` | file viewer |
| sugar-stickers Viewport | ✅ shipped | `sugar-stickers/src/Viewport.php` | scrollable lists |
| sugar-stickers FlexBox | ✅ shipped | `sugar-stickers/src/Flex/FlexBox.php` | 3-pane layout on RepoDetailView |
| sugar-boxer SugarBoxer | ✅ shipped | `sugar-boxer/src/SugarBoxer.php` | bordered panes |
| sugar-stash Git | 🟡 partial — phases 2–4 blocked on patches | `sugar-stash/src/Git.php` | only `status()`, `branches()`, `log(int $limit)`, `stage()`, `unstage()` shipped. Missing `tags()`, `show()`, `lsTree()`, `catFile()` and the `log($ref, $skip, $limit)` windowed signature — see §6.1 sugar-stash blockers below |

### 6.1 sugar-stash blockers (hard preconditions for phases 2–4)

The sugar-stash `Git` driver (`sugar-stash/src/Git.php`) and its
interface (`sugar-stash/src/GitDriver.php`) currently expose only
`status()`, `branches()`, `log(int $limit = 25)`, `stage()`, and
`unstage()`. Each of the following methods is a **hard precondition**
for the phase it gates — phase work cannot start until the matching
sugar-stash patch has shipped (separate PR, landed before candy-serve
phase-N branch opens). Do **not** invent a parallel `git`-shell-out
path inside candy-serve.

| sugar-stash API | Phase blocked | What it must do |
|---|---|---|
| `GitDriver::tags(): array` + `Git::tags()` | **Phase 2** | mirror `branches()` shape, returning `[['name' => …, 'sha' => …, …]]` from `for-each-ref refs/tags`. |
| `GitDriver::show(string $sha): array` + `Git::show()` | **Phase 4** | invoke `git show $sha` and return a structured diff (file list + hunks). Powers `DiffView`. |
| `GitDriver::lsTree(string $ref, string $path = ''): array` + `Git::lsTree()` | **Phase 3** | run `git ls-tree -l $ref -- $path`, return rows with type (`blob`/`tree`), mode, size, name. Drives `FileTreeModel`. |
| `GitDriver::catFile(string $ref, string $path): string` + `Git::catFile()` | **Phase 3** | `git cat-file blob $ref:$path` (or `--filters` for text), return raw body. Drives `FileViewerModel`. |
| `GitDriver::log()` — broadened signature `log(string $ref = 'HEAD', int $skip = 0, int $limit = 25)` | **Phase 4** | extend existing `log(int $limit)` to accept `$ref` + `$skip` for windowed paging. Drives `CommitLogModel` paging. **Backwards compatible** — keep `$limit` as the third positional and default the first two. |

Each of these is a separate sugar-stash PR with its own tests. Phase-1
of candy-serve does **not** depend on any of them (only `branches()`
which already ships). Phases 2–4 are gated:

- **Phase 2 cannot open** until `tags()` ships in sugar-stash.
- **Phase 3 cannot open** until `lsTree()` + `catFile()` both ship.
- **Phase 4 cannot open** until `show()` ships *and* `log()` accepts
  `$ref` + `$skip`.

Recommend a single sugar-stash umbrella step (e.g. "sugar-stash Git
driver: tags / show / lsTree / catFile / windowed log") with five
sub-PRs, scheduled ahead of candy-serve phase-2 kickoff. The
candy-serve phase-1 plan files should reference that step ID once
authored.

**candy-core OSC 52 emitter — already shipped:**

`candy-core/src/Cmd.php:L267` already exposes
`Cmd::setClipboard($text, $selection = 'c')`, which emits the OSC 52
escape (via `candy-core/src/Util/Ansi.php:L400` `Ansi::setClipboard`).
Phase 5 consumes this directly — there is **no candy-vt prerequisite**
for the clipboard emitter. (candy-vt has OSC 52 *read* support; emit
lives in candy-core.)

---

## 7. Out of scope

Items deliberately excluded — do **not** drag these into the TUI phases:

- **Issues / pull requests / wiki.** Upstream soft-serve does not ship
  these. If we ever want a forge-style surface, it belongs in a
  separate lib (working name candidate: `candy-forge`), depending on
  candy-serve for the SSH-Git substrate.
- **Push surface via TUI.** Upstream is read-only TUI; writes go
  through `git push`. We match that.
- **Web / HTTP browser UI.** That is `gitea` / `cgit` territory.
- **HTTP smart protocol server** (`/git-upload-pack` over HTTPS).
  Covered separately in `docs/research/libraries/candy-serve-research.md`
  §3.2; not part of the TUI plan.
- **`git://` daemon.** Same as above, research §3.3.
- **Real daemon mode** (signal handling, PID file, log rotation).
  Research §3.4.
- **OAuth / LDAP / OIDC auth.** Upstream soft-serve is SSH-key-only.
- **Multi-tenant access tokens / app passwords.** Same.
- **Repo mirroring / federation.** Not in upstream.
- **Editing files / editing config from the TUI.** Read-only.
- **GitHub Actions / runner integration.** Out of scope.
- **In-TUI commit creation / signing UX.** Read-only.

---

## 8. Acceptance per phase

A phase is "done" when:

1. The new screen(s) render correctly at **80×24** and **120×40**.
2. **All** existing candy-serve tests still pass (no regression in
   wire-protocol path).
3. **New tests** for the phase ship green: the per-phase test counts in
   §5 are floor estimates, not ceilings.
4. The phase's user stories (US-N) are demonstrably satisfied via a
   manual `ssh user@host` walkthrough.
5. Every user-facing string flows through `Lang::t()` and lands in all
   16 locale files (placeholder English fallback is fine; real
   translations are a separate i18n carry-forward).
6. `vendor/bin/phpunit` in **every** consumer lib that candy-serve
   pulls in (candy-wish, candy-core, sugar-stash, candy-shine,
   candy-sprinkles, sugar-boxer, sugar-stickers) still green.
7. `php tools/check-path-repos.php` reports closure clean.

---

## 9. Risks + open questions

### 9.1 Will candy-core handle this scale?

candy-core's `Program` event loop has been tested on smaller TUIs
(sugar-bits demos, sugar-dash dashboard). A repo-browser TUI is not
unreasonably bigger — five screens, single-user, single-program. The
main load point is the commit log on a large repo (10k+ commits);
phase 4 addresses that with windowed loading. **No expected
architectural blocker.**

### 9.2 What about `ext-ssh2` vs candy-wish overlap?

candy-serve currently embeds `ext-ssh2`. candy-wish ships its own SSH
listener. Running both side-by-side (two listeners, two ports? Or two
modes on one port?) is brittle.

**Recommended resolution:** during phase-1 design, decide between:

- **Option A — Dual-listener.** ext-ssh2 keeps port 23231 for
  Git-over-SSH; candy-wish exposes a second port (e.g. 23232) for the
  TUI. Cleaner separation, but users hit a different port for clone vs
  TUI, which is ugly.
- **Option B — Single candy-wish listener.** Port to candy-wish for
  both Git wire and TUI. candy-wish exposes the channel; the channel
  handler branches on exec-command-or-not. Less code paths, but
  doubles the candy-wish exec-routing surface area.
- **Option C — Keep ext-ssh2 for Git wire; candy-wish for TUI only.**
  ext-ssh2 already routes `git-upload-pack` / `git-receive-pack`
  correctly. Leave it alone. candy-wish handles only the non-exec
  shell path on the same port (the two listen on the same socket via
  shared accept loop, or just plain side-by-side).

**Provisional pick:** **Option B** (single candy-wish listener) — for
maintainability. Phase 1 should formally validate this before writing
code. If candy-wish can't carry the Git wire (it should be able to —
the channel can write whatever bytes you want), fall back to Option C.

Either way the user-facing port stays the same: `ssh -p 23231
user@host` works for both clone and TUI.

### 9.3 What about Windows?

soft-serve runs on Linux / macOS. candy-serve inherits that. Windows
support for the SSH-server path is out of scope (the existing
SSH/SSHServer.php uses POSIX-style ext-ssh2). The TUI itself runs in
any client terminal that supports ANSI; the server side is the gate.

### 9.4 Performance — paged commit log

A 50k-commit repo can't render in one shot. Phase 4 already plans
windowed loading. Confirm sugar-stash `Git::log` supports
skip/limit during phase-2 scoping; if not, ship a sugar-stash patch
first.

### 9.5 Security — shell injection in tree / log paths

`Repo::path` (existing) shells out to `git`. The TUI sends user-picked
path components into commands like `git cat-file blob HEAD:src/Foo.php`.
**Every path** must flow through `escapeshellarg()` before composing
the command, **and** be validated against `Repo::path` to prevent
`../` escape. This is identical to the existing
`UploadPack`/`ReceivePack` discipline — no new attack surface, but
phase-1 implementer must inherit the pattern explicitly.

---

## 10. Estimated total effort

| Phase | Effort | Notes |
|---|---|---|
| 1 | ≈ 2 weeks | wires candy-wish ↔ candy-core, repo list |
| 2 | ≈ 1.5 weeks | detail view |
| 3 | ≈ 2 weeks | tree + viewer (chunkiest) |
| 4 | ≈ 1.5 weeks | commit log + diff |
| 5 | ≈ 1 week | polish + clipboard + help |
| **Total** | **≈ 8 weeks** | single full-time implementer |

The candy-serve MATCHUPS.md row is currently 🟢 (v1 ready per
research-doc-counted surface). After phase 5 ships it can credibly
move to 🚀 (i.e. matches upstream feature surface). Today's 🟢 reflects
the wire-protocol port — the marquee TUI feature has not been built.

---

## 11. After this plan lands

1. User reviews + authorizes phase 1.
2. Author drafts `plans/leftover/phase-12-candy-serve-tui/step-{01..06}.md`
   (one step per phase-1 deliverable per §5.1 — TuiSession,
   RepoListModel/View, candy-wish wiring, lang files, tests, docs).
3. Phase-1 step files ship ship-as-you-go per CLAUDE.md cadence (2–4
   related items per PR).
4. After phase 5, update `MATCHUPS.md` row to 🚀 and
   `docs/research/libraries/candy-serve-research.md` to mark §3.1
   shipped.

---

## 12. References

### Upstream
- soft-serve repo: <https://github.com/charmbracelet/soft-serve>
- soft-serve docs: <https://pkg.go.dev/github.com/charmbracelet/soft-serve>

### Research
- `docs/research/libraries/candy-serve-research.md` — full survey;
  §3.1 (HIGH PRIORITY — Interactive SSH TUI) is the direct input for
  this plan.

### Existing candy-serve code
- `candy-serve/src/SSH/SSHServer.php:L1–L232` — current SSH listener
  (ext-ssh2)
- `candy-serve/src/Repo.php:L1–L284` — bare-repo model, `readme()`,
  `description()`
- `candy-serve/src/User.php:L1–L196` — user + SSH keys
- `candy-serve/src/AccessControl.php:L1–L97` — read / write / admin
  permission model
- `candy-serve/src/Git/UploadPack.php:L1–L176` — clone / fetch path
- `candy-serve/src/Git/ReceivePack.php:L1–L171` — push path
- `candy-serve/bin/soft-serve` — current CLI entry point

### Monorepo libs consumed by this plan
- `candy-wish/src/Server.php` + `candy-wish/src/Channel/` —
  SSH server + channel API.
- `candy-core/src/Program.php` + `candy-core/src/Model.php` — Bubble
  Tea runtime.
- `candy-core/src/ScreenStack.php` (+ `ScreenStackCapable.php`,
  `RootModelWithScreenStack.php`, `Msg/ScreenStackPushedMsg.php`) —
  nav stack (step 06.02, PR #583).
- `candy-core/src/Cmd.php` (`Cmd::setClipboard()`, L267) +
  `candy-core/src/Util/Ansi.php` (`Ansi::setClipboard()`, L400) —
  OSC 52 emitter helper used by phase-5 clipboard keybind.
- `candy-sprinkles/src/Style.php` + `candy-sprinkles/src/Theme.php` —
  styling.
- `sugar-boxer/src/SugarBoxer.php` — bordered titled boxes.
- `sugar-stickers/src/Viewport.php` + `sugar-stickers/src/Scrollbar.php`
  + `sugar-stickers/src/Flex/FlexBox.php` — scrolling lists + layout.
- `candy-shine/src/Renderer.php` — README markdown.
- `candy-shine/src/SyntaxHighlighter.php` — file viewer colouring.
- `sugar-stash/src/Git.php` (`GitDriver` impl) — git shell-out.

### This plan's own step file
- `plans/leftover/phase-11-strategic-plans/step-02-candy-serve-tui.md`

---

**End of plan. Awaiting user authorization to draft `plans/leftover/
phase-12-candy-serve-tui/` step files for phase 1.**
