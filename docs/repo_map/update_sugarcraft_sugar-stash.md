# Overview

**sugar-stash** is the SugarCraft monorepo's most complete application — a three-pane git TUI port of `jesseduffield/lazygit` built on the SugarCraft stack (candy-core + candy-sprinkles). It shells out to the system `git` binary, preserving user aliases, hooks, and signing config, while implementing an Elm-architecture state machine with comprehensive git operations (stage/unstage/commit/amend/branch/merge/stash/rebase/worktrees/cherry-pick).

**Biggest opportunity areas:**
- Commit graph visualization (colored branch topology like lazygit's `--graph`)
- Search/filter functionality in all panels (`/` key)
- Remote operations (push/pull/fetch)
- Custom commands and keybinding extensibility
- Mouse support for pane navigation and actions
- Spring animations for smoother transitions

**Biggest missing capabilities:**
- Bisect integration
- Submodule support
- Tags management
- Blame view
- Interactive diff with side-by-side rendering
- Fuzzy search in filter/choose patterns from gum/filter

---

# Internal Capability Summary

## Architecture

**Pluggable GitDriver Interface Pattern:**
- `GitDriver` interface (`src/GitDriver.php` — 117 lines) defines all git operations as contract
- `Git` class (`src/Git.php` — 246 lines) shells out via `proc_open()` with `-C <cwd>` for all commands
- `FixtureGit` test doubles injected in tests via the interface

**Three-Pane Layout:**
```
┌─────────────────┬──────────────────────┐
│                 │     branches         │
│    status       ├──────────────────────┤
│   (left pane)   │        log           │
│                 │   (bottom right)     │
└─────────────────┴──────────────────────┘
```

**App Model (State Machine):**
- `App` class (`src/App.php` — 1201 lines) implements `Model` interface
- All state is `readonly` — every `update()` returns fresh `App`
- Tracks: three pane lists + cursors, active pane, overlays (diff/stash/cherry-pick/worktrees/interactive-rebase)

## Current Features

| Feature | Status |
|---------|--------|
| Three-pane layout (status/branches/log) | ✅ Complete |
| Stage/unstage single file | ✅ Complete |
| Stage all files | ✅ Complete |
| Discard changes | ✅ Complete |
| Commit with message | ✅ Complete |
| Amend commit | ✅ Complete |
| Branch checkout/create/delete | ✅ Complete |
| Merge | ✅ Complete |
| Diff viewer (per-file) with hunk staging | ✅ Complete |
| Stash list/apply/drop | ✅ Complete |
| Cherry-pick | ✅ Complete |
| Worktrees | ✅ Complete |
| Interactive rebase (todo list) | ✅ Complete |
| Undo/redo with HistoryManager | ✅ Complete |
| Context-sensitive help | ✅ Complete |
| i18n (16 locales) | ✅ Complete |
| VHS demo recordings | ✅ Complete |
| Comprehensive behavior tests | ✅ Complete |

## Strengths

1. **Clean GitDriver interface** — enables testable architecture with fixture doubles
2. **Immutable state with readonly properties** — every mutation returns new instance
3. **Comprehensive test coverage** — 700+ lines of behavior tests via `FixtureGit`
4. **i18n via Lang::t() facade** — 16 locales with proper lookup chain
5. **Pluggable architecture** — GitDriver allows alternative backends
6. **Gated input collection pattern** — clean separation of normal key handling vs. text input
7. **Undo/redo with explicit inverseOp** — each HistoryEntry knows how to undo itself

## Weaknesses

1. **No mouse support** — all navigation is keyboard-only
2. **No commit graph visualization** — flat log view vs. lazygit's `--graph` topology
3. **No search/filter in panels** — cannot filter files/branches/log entries
4. **No remote operations** — push/pull/fetch not implemented
5. **No animation** — instant state transitions vs. spring-based smooth motion
6. **Refresh-on-keypress model** — no background refresh or async git operations
7. **No fuzzy search** — filter/choose use substring matching, not fuzzy

---

# Relevant External Repositories

| Repo | Relevance | Major Applicable Concepts | Priority |
|------|-----------|-------------------------|---------|
| `charmbracelet/bubbletea` | 🔴 Primary upstream TUI framework | Elm architecture, command pattern, batch/sequence, synchronized output (ANSI 2026), cell-based renderer | Critical |
| `charmbracelet/lipgloss` | 🔴 Primary styling upstream | Pure-value Style type, CSS shorthand, CIELAB color blending, layer compositor, gradient borders | Critical |
| `jesseduffield/lazygit` | 🔴 Primary upstream application | Full libgit2 bindings, commit graph, bisect, custom commands, filter/search, side-by-side diff | Critical |
| `textualize/textual` | 🟡 Python TUI reference | Reactive state, CSS layout, message pump, spatial map for hit testing, async/await workers | High |
| `charmbracelet/gum` | 🟡 Shell TUI toolkit reference | Fuzzy filter algorithm (`sahilm/fuzzy`), confirm/spinner/input patterns, exit code semantics | High |
| `charmbracelet/harmonica` | 🟡 Animation library | Damped spring physics, analytically exact per-step spring update, constant-time updates after setup | Medium |
| `lrstanley/bubblezone` | 🟢 Mouse zone tracking | Zero-width ANSI markers for hit detection, state-machine scanner, concurrent-safe zone storage | Medium |
| `treilik/bubblelister` | 🟢 List component reference | Concurrent search with goroutines, pluggable prefixer/suffixer interfaces, word wrapping | Medium |
| `charmbracelet/x/term` | 🟢 Terminal utilities | Raw mode, password input, window size detection, TTY state management | Low |
| `charmbracelet/x/teatest` | 🟢 Testing utilities | Fixture-based assertions for TUI programs, golden file testing | Medium |

---

# Feature Gap Analysis

## Critical Priority

### 1. Commit Graph Visualization
**Title:** Colored branch topology in log pane  
**Description:** Replace flat `git log` output with `git log --graph --oneline --decorate --all` rendering showing branch/merge history as ASCII art tree  
**Why it matters:** Git users rely heavily on visual branch topology to understand history, especially in complex multi-branch repos. This is lazygit's signature feature.  
**Source:** `docs/repo_map/sugarcraft_sugar-stash.md` (feature parity matrix)  
**Implementation ideas:**
- Parse `git log --graph --oneline --decorate --all --format="%h%d%s"` 
- Render graph characters (`*`, `|`, `/`, `\`, `m`) with branch name highlighting
- Use candy-sprinkles Color for commit hash/branch coloring
- Add `--all` flag toggle for showing remote branches  
**Estimated complexity:** Medium (parsing and rendering ASCII graph)  
**Expected impact:** High — fills major visual gap vs. lazygit

### 2. Panel Search/Filter (`/` key)
**Title:** Fuzzy search in status/branches/log panels  
**Description:** Add `/` keybinding that opens inline filter input, filtering panel content in real-time via fuzzy matching  
**Why it matters:** In large repos, scrolling through dozens of branches or files is slow. Filter enables rapid navigation.  
**Source:** `docs/repo_map/charmbracelet_gum.md` — fuzzy filter algorithm  
**Implementation ideas:**
- Use `sahilm/fuzzy` port or PHP equivalent for matching
- Filter status entries by path substring/regex
- Filter branches by name
- Filter log by commit message/author/SHA
- Show filter input bar above panel, Esc to clear
- Matched characters could be highlighted with reverse/underline style  
**Estimated complexity:** Medium — requires new overlay state + fuzzy library  
**Expected impact:** High — major usability improvement for large repos

### 3. Mouse Support
**Title:** Clickable panes, entries, and actions  
**Description:** Enable mouse clicks for cursor movement, action triggering, and pane switching  
**Why it matters:** Many users expect mouse support in TUIs. lazygit has full mouse integration.  
**Source:** `docs/repo_map/lrstanley_bubblezone.md`, `docs/repo_map/charmbracelet_bubbletea.md` (mouse input)  
**Implementation ideas:**
- Wire `MouseMsg` handler in `App::update()` alongside `KeyMsg`
- Use `candy-zone` for zone-based hit detection if available
- Click on pane border to switch focus
- Click on entry to move cursor + optional action trigger
- Mouse wheel for scrolling in long lists
- Right-click for context menu (future)  
**Estimated complexity:** Medium — requires MouseMsg handling + zone tracking  
**Expected impact:** High — matches user expectations for modern TUIs

### 4. Push/Pull Remote Operations
**Title:** Remote sync commands  
**Description:** Add push, pull, and fetch operations bound to keys  
**Why it matters:** Core git workflow for collaborating with remotes is missing.  
**Source:** `docs/repo_map/sugarcraft_sugar-stash.md` (feature parity gap)  
**Implementation ideas:**
- Add `push()`, `pull()`, `fetch()` to `GitDriver` interface
- Implement in `Git` class: `git push`, `git pull`, `git fetch`
- Add keybindings: `P` (push), `p` (pull), `f` (fetch)
- Show progress output in temporary overlay
- Handle authentication (SSH key, credential helper) gracefully  
**Estimated complexity:** Low — straightforward git command wrappers  
**Expected impact:** High — completes core git workflow

---

## High Priority

### 5. Spring Animations
**Title:** Smooth transitions between states  
**Description:** Add harmonica-style spring animations for cursor movement, pane switching, overlay appearance  
**Why it matters:** SugarCraft has `honey-bounce` (harmonica port) available. Animations make the TUI feel polished and responsive.  
**Source:** `docs/repo_map/charmbracelet_harmonica.md`, `docs/repo_map/sugarcraft_sugar-stash.md` (animation gap)  
**Implementation ideas:**
- Animate cursor movement (smooth scroll to new position)
- Animate pane focus change (border color transition)
- Animate overlay appearance (slide/fade in)
- Use `honey-bounce` Spring + SpringConfig for physics-based animation
- Add `REDUCE_MOTION` support for accessibility  
**Estimated complexity:** Medium — requires subscription tick + animation state  
**Expected impact:** Medium — polish but not core functionality

### 6. Fuzzy Search in Filter/Choose
**Title:** Real-time fuzzy filtering  
**Description:** Replace substring match with fuzzy matching for panel filtering  
**Why it matters:** Fuzzy matching handles typos and partial matches better. gum's filter uses this effectively.  
**Source:** `docs/repo_map/charmbracelet_gum.md` — fuzzy algorithm reference  
**Implementation ideas:**
- Port `sahilm/fuzzy` to PHP or use existing PHP fuzzy library
- Apply fuzzy score ranking to filtered results
- Highlight matched characters in results
- Preserve ranking order (best match first)  
**Estimated complexity:** Medium — requires PHP fuzzy library + integration  
**Expected impact:** High — better UX than substring match

### 7. Interactive Diff Improvements
**Title:** Side-by-side diff view and syntax highlighting  
**Description:** Enhance diff viewer with line-level understanding and optional side-by-side layout  
**Why it matters:** Unified diff is readable but side-by-side is easier for large changes.  
**Source:** `docs/repo_map/sugarcraft_sugar-stash.md` (diff viewer description)  
**Implementation ideas:**
- Parse diff output into structured `DiffLine` objects with change type (+/-/context)
- Add syntax highlighting for common file types using candy-shine
- Implement side-by-side layout mode via `Layout::joinHorizontal()`
- Add `|` toggle between unified/side-by-side views  
**Estimated complexity:** Medium — new diff parsing + layout modes  
**Expected impact:** Medium — improves code review workflow

---

## Medium Priority

### 8. Bisect Integration
**Title:** `git bisect` automation  
**Description:** UI workflow for binary search through commits to find buggy one  
**Why it matters:** Powerful but underutilized git feature, implemented in lazygit  
**Source:** `docs/repo_map/sugarcraft_sugar-stash.md` (feature parity gap)  
**Estimated complexity:** Medium — requires bisect start/skip/reset workflow  
**Expected impact:** Medium — advanced users only

### 9. Tags Management
**Title:** List/create/delete annotated tags  
**Description:** Add tags panel alongside branches  
**Why it matters:** Missing from feature parity matrix  
**Estimated complexity:** Low — similar to branch operations  
**Expected impact:** Low — niche use case

### 10. Blame View
**Title:** Per-line commit attribution  
**Description:** Show which commit last modified each line  
**Why it matters:** Not implemented, listed in gaps  
**Estimated complexity:** Medium — requires `git blame --porcelain` parsing  
**Expected impact:** Low — specialized for code review

### 11. Submodule Support
**Title:** Submodule list and update  
**Description:** Handle nested git repositories  
**Why it matters:** Listed as gap in feature parity  
**Estimated complexity:** High — complex git feature  
**Expected impact:** Low — rare use case

### 12. Rebase Magic / Patch Application
**Title:** Custom patch application during rebase  
**Description:** Apply arbitrary patches during interactive rebase  
**Estimated complexity:** High  
**Expected impact:** Low — advanced users only

### 13. Amend Old Commits
**Title:** Amend any commit not just HEAD  
**Description:** Via rebase-based workflow  
**Estimated complexity:** Medium  
**Expected impact:** Low — niche workflow

### 14. Custom Commands
**Title:** User-defined commands bound to keys  
**Description:** Extensible keybinding system for custom git workflows  
**Why it matters:** Power user feature in lazygit  
**Source:** `docs/repo_map/sugarcraft_sugar-stash.md` (feature parity gap)  
**Estimated complexity:** High — requires configuration system + command execution  
**Expected impact:** Medium — power users only

---

## Low Priority

### 15. Command Palette
**Title:** Fuzzy command search interface  
**Description:** Type command name to invoke rather than remembering all keybindings  
**Why it matters:** Modern TUI pattern from textual/gum  
**Source:** `docs/repo_map/textualize_textual.md` (command palette)  
**Estimated complexity:** Medium — requires fuzzy matching + action dispatch  
**Expected impact:** Low — keyboard power users already know bindings

### 16. Syntax Highlighting in Diff
**Title:** Language-aware diff coloring  
**Description:** Highlight code syntax in diff viewer  
**Estimated complexity:** Low — integrate candy-shine  
**Expected impact:** Low — nice to have

---

# Algorithm / Performance Opportunities

## Current Approach vs. External

### Git Integration: proc_open() vs. libgit2
**Current (sugar-stash):** Shells out to `git` binary via `proc_open()` with piped stdout/stderr. Each operation spawns a new process.

**External (lazygit):** Uses libgit2 bindings for direct in-memory git operations without process spawning.

**Tradeoffs:**
- sugar-stash approach: Respects user git aliases, hooks, signing config automatically; simpler implementation; slower for high-frequency ops; no in-memory state
- lazygit approach: Faster high-frequency ops; full git state in memory; must re-implement alias/hook handling; complex C bindings

**Applicability:** The proc_open() approach is pragmatic and correct for sugar-stash's use case. Consider caching git output for the lifetime of a render cycle to avoid redundant spawns on rapid keypresses.

### Fuzzy Matching: Substring vs. Fuzzy
**Current (sugar-stash):** No fuzzy search implemented.

**External (gum filter):** Uses `sahilm/fuzzy` library with real-time matching and matched range highlighting.

**Applicability:** Add fuzzy search library (PHP port of fuzzy or `gipht/fuzzy`) to enable typo-tolerant filtering.

### Rendering: Full Redraw vs. Delta
**Current (sugar-stash):** `Renderer::render()` produces complete output on every call.

**External (bubbletea):** Cell-based renderer with delta updates, only redraws changed lines, synchronized output mode (ANSI 2026).

**Applicability:** Consider integrating `candy-vt` or candy-core's renderer improvements for partial redraws. The current approach is simpler but may flicker on some terminals.

### Spring Animations: Instant vs. Spring-based
**Current:** No animations — state transitions are instantaneous.

**External (harmonica):** Analytically exact damped spring physics per time step, constant-time after setup.

**Applicability:** `honey-bounce` is available in SugarCraft. Add animation subscriptions for cursor/overlay transitions.

---

# Architecture Improvements

1. **Async Git Operations:** Consider `ReactPHP\Promise` for non-blocking git operations that don't freeze the TUI during slow operations (e.g., large rebase, remote fetch). Current synchronous `proc_open()` blocks the event loop.

2. **GitDriver Caching:** Cache git output for the current render cycle to avoid redundant spawning when multiple keypresses happen rapidly before a refresh.

3. **Structured Diff Parsing:** Improve `DiffViewer` to parse diff into structured `DiffLine` objects (added/removed/context) rather than raw string processing. Enables side-by-side view, syntax highlighting, and line-level navigation.

4. **Command Dispatcher Pattern:** Extract key action dispatch from `App::update()` into a separate `KeyDispatcher` class that maps `(Pane, KeyType, ?string rune)` to `Closure` handlers. Improves testability and reduces App.php size.

5. **Plugin Architecture for GitDriver:** Allow registering custom git operations or wrapping existing ones (e.g., for GitHub CLI integration).

---

# API / Developer Experience Improvements

1. **Public App Builder:** Add `App::configure()` fluent builder for setting options (custom GitDriver, initial pane, theme override) rather than requiring direct property access.

2. **GitDriver Test Utilities:** Export `FixtureGit` and helper factories for users who want to test their code against a fixture git backend without spawning real repos.

3. **Event Hooks:** Add optional callbacks for actions (e.g., `onCommit`, `onStage`) so external systems can react to git operations without modifying App.

4. **Structured Exit Codes:** Define constants for exit codes (success, error, not-a-repo, git-failed) rather than using generic 0/1.

5. **App::run() Static Entry:** Add `App::run(string $cwd): int` static method that instantiates GitDriver and Program, handles the not-a-repo error gracefully with user-friendly message.

---

# Documentation / Cookbook Opportunities

1. **Usage Examples:** Add `examples/` directory with:
   - `examples/custom-keybindings.php` — modifying default keymap
   - `examples/gitdriver-fixture.php` — testing with FixtureGit
   - `examples/minimal.php` — bare-minimum sugar-stash usage

2. **Architecture Deep-Dive:** Document the GitDriver interface pattern and how it enables testability. This pattern could be a model for other SugarCraft apps.

3. **i18n Guide:** Expand internationalization documentation with:
   - How to add a new locale
   - How translation keys are resolved (exact → base → en → raw)
   - Contribution workflow for translations

4. **VHS Demo Cookbook:** Document how to create VHS demos with TokyoNight theme, including timing tips for keypresses and sleep durations.

---

# UX / TUI Improvements

1. **Success Messages:** Currently sugar-stash shows transient success messages (e.g., "hunk staged"). Consider:
   - Auto-dismiss after 2 seconds with fade animation
   - Stack multiple messages if rapid actions occur

2. **Branch Summary Enhancement:** The branch summary shows ahead/behind. Consider:
   - Color coding (green=ahead, red=behind, yellow=diverged)
   - Remote tracking branch name display

3. **Status Line Colors:** Currently fixed hex colors. Consider:
   - Respecting user's terminal color profile (candy-palette integration)
   - Dark/light theme variants

4. **Tab Cycling Direction:** Currently Tab cycles Status→Branches→Log→Status. Consider Shift+Tab for reverse cycling.

5. **Empty State Messages:** Each pane shows empty state. Consider:
   - Helpful hints in empty panes ("No changes — stage with 'a'")
   - Different messages per pane context

---

# Testing / Reliability Improvements

1. **Golden Snapshot Tests:** Add snapshot tests for:
   - Three-pane layout rendering with specific git states
   - Diff viewer overlay rendering
   - All overlay types (stash, worktree, rebase, etc.)
   - i18n rendering with different locales

2. **FixtureGit Comprehensive Coverage:** Ensure `FixtureGit` covers all `GitDriver` operations with realistic test data.

3. **Error Handling Tests:** Add tests for:
   - Invalid git repository directory
   - Git operations that fail (e.g., commit on dirty index)
   - Non-zero exit code propagation from git commands

4. **Performance Testing:** Add tests that verify refresh operations complete within reasonable time (<100ms for typical repos).

5. **Concurrent Input Handling:** Test rapid keypress sequences don't cause race conditions.

---

# Ecosystem / Integration Opportunities

1. **GitHub CLI Integration:** Create `GitHubDriver` implementing `GitDriver` that wraps `gh` CLI for GitHub-specific operations (pr checkout, pr view, issue references).

2. **Editor Integration:** Add keybinding (`e`) to open files in `$EDITOR` for editing, similar to how gum's write command opens vim.

3. **Integration with sugar-prompt:** Use sugar-prompt for inline text collection instead of the gated input pattern, for a more flexible input experience.

4. **Candy-vcr Recording:** Use `candy-vcr` to record git operations for playback in tests, enabling integration tests without fixture git repos.

5. **Git Credential Helper:** Wire in PHP's git credential helper support for authenticated remote operations.

---

# Notable PRs / Issues / Discussions

1. **bubbletea ANSI 2026 Synchronized Output:** `charmbracelet/bubbletea` added synchronized output mode (ANSI 2026) for flicker-free rendering. Source: `docs/repo_map/charmbracelet_bubbletea.md` (lines 185-201). This is applicable to sugar-stash via candy-core's renderer.

2. **lipgloss Layer Compositing:** `charmbracelet/lipgloss` layer system (`Layer`/`Compositor`/`Canvas`) enables overlay UIs and mouse hit-testing. Source: `docs/repo_map/charmbracelet_lipgloss.md` (lines 58-62, 217-218). SugarCraft's candy-sprinkles could adopt this pattern.

3. **gum Fuzzy Filter:** `charmbracelet/gum` filter uses `sahilm/fuzzy` with matched ranges for highlighting. Source: `docs/repo_map/charmbracelet_gum.md` (line 60). PHP port of this library would benefit sugar-stash and other SugarCraft libs.

4. **bubblezone Zero-Width Markers:** `lrstanley/bubblezone` uses zero-width ANSI markers (`\x1B[<number>z`) for mouse zone detection. Source: `docs/repo_map/lrstanley_bubblezone.md` (lines 11, 56-57). This pattern is directly applicable to sugar-stash for mouse support.

5. **teatest Fixture Testing:** `charmbracelet/x/teatest` provides fixture-based TUI testing. Source: `docs/repo_map/charmbracelet_x.md` (line 34). This could inform sugar-stash's test patterns.

---

# Recommended Roadmap

## Immediate Wins (0-2 sprints)

1. **Add push/pull/fetch** — Low complexity, high impact, adds missing core workflow
2. **Add panel search/filter (`/` key)** — Medium complexity, high usability improvement
3. **Add mouse support** — Medium complexity, major UX improvement for mouse users

## Medium-Term Improvements (2-4 sprints)

4. **Commit graph visualization** — Medium complexity, fills major visual gap
5. **Spring animations for cursor/overlay transitions** — Medium complexity, polish
6. **Interactive diff improvements (side-by-side, syntax highlighting)** — Medium complexity
7. **Fuzzy search algorithm** — Medium complexity, better matching than substring

## Architectural Upgrades (4-6 sprints)

8. **Async git operations via ReactPHP** — High complexity, improves responsiveness
9. **GitDriver caching layer** — Medium complexity, performance improvement
10. **Structured diff parsing** — Medium complexity, enables advanced diff features
11. **Command dispatcher extraction** — Medium complexity, improves testability

## Experimental Ideas (future)

12. **GitHub CLI GitDriver** — High complexity, GitHub-specific workflow
13. **Bisect integration** — Medium complexity, advanced feature
14. **Custom commands/keybinding extensibility** — High complexity, power user feature
15. **Side-by-side diff view** — Medium complexity, code review improvement

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|-------------|--------|-------------|------|---------------------|
| Push/pull/fetch | High | Low | Low | **Immediate** |
| Panel search/filter | High | Medium | Low | **Immediate** |
| Mouse support | High | Medium | Medium | **Immediate** |
| Commit graph visualization | High | Medium | Low | Medium-term |
| Spring animations | Medium | Medium | Low | Medium-term |
| Side-by-side diff | Medium | Medium | Low | Medium-term |
| Fuzzy search algorithm | High | Medium | Low | Medium-term |
| Async git operations | Medium | High | High | Architectural |
| GitDriver caching | Medium | Medium | Medium | Architectural |
| Structured diff parsing | Medium | Medium | Low | Architectural |
| Command dispatcher | Medium | Medium | Low | Architectural |
| GitHub CLI driver | Medium | High | Medium | Experimental |
| Bisect integration | Medium | Medium | Low | Experimental |
| Custom commands | Medium | High | Medium | Experimental |
| Blame view | Low | Medium | Low | Low priority |
| Tags management | Low | Low | Low | Low priority |
| Submodule support | Low | High | Medium | Low priority |
| Syntax highlighting | Low | Low | Low | Low priority |
| Command palette | Low | Medium | Low | Low priority |

---

# Final Strategic Assessment

**sugar-stash** is a well-architected, fully functional git TUI that implements ~60% of lazygit's features with 10x less code. The Elm-architecture port to PHP via candy-core is clean and demonstrates the SugarCraft stack's capability for building real-world applications. The pluggable `GitDriver` interface pattern is exemplary and could serve as a model for other SugarCraft apps requiring backend abstraction.

**Key strategic priorities:**

1. **Complete the core git workflow** (push/pull/fetch) — these are fundamental operations missing from the current implementation and should be addressed immediately.

2. **Add search/filter and mouse support** — these UX improvements address the two most common user complaints about terminal applications (slow navigation, lack of mouse support).

3. **Invest in visual differentiation** — the commit graph visualization would be a major visual differentiator that showcases what's possible with the SugarCraft styling system.

4. **Address performance** — async git operations and GitDriver caching would make the TUI feel more responsive, especially on slower systems or large repositories.

5. **Consider ecosystem leverage** — `honey-bounce` (spring animations), `candy-zone` (mouse zones), and fuzzy search libraries could all be integrated to improve the TUI without significant new development.

**Competitive position:** sugar-stash competes directly with lazygit (Go) and offers a PHP-native alternative that integrates well with PHP tooling and workflows. The TypeScript/JS ecosystem has no direct equivalent, making sugar-stash a unique offering for PHP developers who prefer git TUI applications.

**Long-term vision:** sugar-stash could become the foundation for a broader PHP-based git tooling ecosystem, with potential for GitHub/GitLab integration, editor plugins (VS Code, PhpStorm), and CI/CD pipeline visualization.
