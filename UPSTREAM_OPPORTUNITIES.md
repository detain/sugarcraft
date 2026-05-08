# Upstream investigation — opportunities & port-applicability

A snapshot of recent merged PRs and high-comment open issues across
the major upstream Charmbracelet repos that SugarCraft tracks. Each
entry includes whether the upstream change applies to our port, the
benefit of porting, the downside / cost, and an effort estimate.

Snapshot date: 2026-05-07. Refresh quarterly or when planning a port wave.

Status legend:

- ✅ already present in SugarCraft (no action needed)
- 🟡 partially present / variation of upstream landed
- 🔴 missing — candidate for a port
- ⚪ not applicable (PHP/Go semantics differ; doesn't translate)

---

## charmbracelet/bubbletea (= candy-core)

### Recent merged PRs

| Upstream | Title | Status in candy-core | Notes |
|---|---|:--:|---|
| #1626 (2026-04-07) | feat: support extended keyboard enhancements | ✅ | Kitty keyboard protocol push/pop/request escape sequences shipped (`Ansi::pushKittyKeyboard`, `popKittyKeyboard`, `requestKittyKeyboard`). KeyboardEnhancementsMsg + KeyType enum already covers the parity surface. |
| #1680 (2026-04-20) | fix: avoid Exec restore panic with WithInput(nil) | 🟡 | `Program::executeRequest()` already routes nil via `STDIN` default, but should add explicit guard for the case where `$input === null` *and* the program was launched without a TTY (would currently throw). **Action:** add a 1-liner null-guard in `Program::executeRequest`; ~15 min. |
| #1677 (2026-04-13) | fix(renderer): restore tab stops if hard tabs are enabled | ⚪ | candy-core doesn't currently support hard tabs in the renderer (we always emit literal spaces). Mark as future feature alongside hard-tab support. |
| #1674 (2026-04-13) | fix: missing signal.Stop in suspendProcess (channel leak) | ⚪ | PHP doesn't use Go-style channels. `pcntl` signal handlers auto-tear-down at process exit. Bug class doesn't translate. |
| #1611 (2026-04-13) | Change KeyMsg to KeyPressMsg in Update function | ⚪ | Upstream's v2 split of KeyMsg into KeyPressMsg / KeyReleaseMsg / KeyAutoRepeatMsg. SugarCraft tracks v1 parity; v2 is a separate roadmap item (see CONVERSION.md Phase 9+). |

### High-comment open issues (potential bugs)

| Upstream | Title | Applicability | Recommendation |
|---|---|---|---|
| #874 | IME input in wrong position | 🔴 Likely affects candy-core's TextInput cursor positioning when IME composition is active (CJK input). Reproducing this in PHP TUI requires a real terminal w/ IME and we lack reproducible test infrastructure. | Defer until we have a CJK-using contributor + a dev who can repro. |
| #573 | Altscreen rendering artifacts on resize | 🟡 candy-core has `Renderer::onResize()` but the cell-diff path may exhibit similar artifacts. Worth a focused test that drives `WindowSizeMsg` through the renderer with a SIGWINCH burst and snapshots the frame buffer. | Effort: 1-2 hours. |
| #197 | Cyrillic / Windows-1251 rune decode errors | ✅ candy-core uses `Util\Width::graphemes()` which uses `\IntlBreakIterator` (proper Unicode segmentation). Should not affect us; would be worth a confirming test. |

---

## charmbracelet/lipgloss (= candy-sprinkles)

### Recent merged PRs

| Upstream | Title | Status in candy-sprinkles | Notes |
|---|---|:--:|---|
| #636 (2026-04-13) | fix: avoid background color query hang | 🟡 | candy-core's bg-query path returns immediately via `Cmd::requestBackgroundColor()` and processes the reply via `BackgroundColorMsg`. We don't block on a sync query the way old lipgloss did, so the specific hang doesn't apply. **However**, if we ever add a sync `Renderer::detectDarkBackground()` that waits on STDIN, it should adopt the upstream's timeout pattern. |

(lipgloss has been mostly in maintenance mode — only chore/deps recently.)

---

## charmbracelet/bubbles (= sugar-bits)

### Recent merged PRs

| Upstream | Title | Status in sugar-bits | Notes |
|---|---|:--:|---|
| #910 (2026-03-25) | feat(textarea): dynamic height | 🟡 | sugar-bits TextArea has `withMaxHeight(int)` but not the *expanding-as-you-type* dynamic-height behavior upstream just added. **Port candidate** — would let TextArea grow with content up to `maxHeight`. **Effort:** ~2 hours (track line count after each edit, return new height in `view()`). **Benefit:** matches huh's WriteCommand UX expectation. **Downside:** snapshot tests need updating. |

### High-comment open issues / feature requests

| Upstream | Title | Applicability | Recommendation |
|---|---|---|---|
| #361 (16c) | textinput + pinyin IME | 🟡 Same IME-positioning concern as bubbletea #874. CJK contributor needed to repro. | Defer. |
| #472 (15c) | Table cell padding broken (post-1ba1200) | 🔴 Worth checking sugar-bits Table padding behavior in the row-render path. Add a test with mixed padded / non-padded rows. | Effort: 1 hour. |
| #479 (9c) | Viewport may not scroll to end of large text | 🔴 sugar-bits Viewport's `scrollDown()` clamps to `totalLines - height`; verify the clamp uses the post-wrap line count rather than raw line count. | Effort: 30 min. |
| **#233 (9c)** | **Feature request: Tree model** | 🔴 **High-leverage missing component.** A Tree component would slot naturally into sugar-bits alongside ItemList/Table. Lipgloss already has Tree (we ship it via candy-sprinkles `Tree\Tree`); the missing piece is the *interactive* tree (cursor / expand-collapse / filter). **Effort:** 1-2 days. **Benefit:** opens up file-browser, JSON-explorer, and config-editor use cases. **Downside:** a non-trivial new component; lots of edge cases (lazy-load children, custom node renderers). |
| **#246 (8c)** | **Feature: Style table rows/cells individually** | 🔴 sugar-bits Table currently styles header / body / selected globally. Adding a `StyleFunc(int $row, int $col): Style` slot (which candy-sprinkles Table already has) would unify the two surfaces and let users do striped rows / per-cell highlighting / conditional formatting. **Effort:** half day. **Benefit:** much more flexible Table; closes the gap between sugar-bits Table and candy-sprinkles Table (which is presentational, not interactive). **Downside:** API surface widens. |
| #124 (8c) | Filter underline misaligned | 🟡 ItemList filter rendering is independent of upstream's; we use our own substring-highlight path. Worth a snapshot test with a filter active. |
| #157 | feat: add search functionality | 🟡 ItemList already supports filter; "search" (jump-to-substring) would be a thin wrapper. **Effort:** 1 hour. |

---

## charmbracelet/huh (= sugar-prompt)

### Recent merged PRs

| Upstream | Title | Status in sugar-prompt | Notes |
|---|---|:--:|---|
| #749 (2026-03-10) | fix(select): cursor visibility navigating multiline options | 🟡 Worth checking — sugar-prompt Select uses sugar-bits ItemList, which scrolls a single-line-per-item viewport. If users put `\n` in option labels we may have the same cursor-visibility issue. **Action:** add a snapshot test with multiline option strings; fix if visible. **Effort:** 1 hour. |

### High-comment open issues

| Upstream | Title | Applicability | Recommendation |
|---|---|---|---|
| #679 (5c) | Select doesn't render elements before selected when Value is set | 🟡 If Select::new()->withValue('xyz') (programmatic default) is called and 'xyz' is mid-list, do we render the rows above it? Worth a test. **Effort:** 30 min. |
| #286 (4c) | Windows: Tab/Enter not handled correctly | ⚪ Windows console quirks; sugar-prompt isn't Windows-tested. Mark as known-gap in README. |
| #272 (4c) | Overriding KeyMaps and KeyBinds | 🔴 Form-level keymap override is a missing public surface (`Form::withKeyMap()` doesn't exist). Several huh users explicitly asked for this. **Effort:** half day (define KeyMap struct + thread through update()). **Benefit:** lets users rebind nav keys without forking. |
| #168 (4c) | GetValue return type | 🟡 sugar-prompt has typed `getString` / `getInt` / `getBool` / `getArray` (via PR #213-era), so we already solved this. Mention it in the comparison doc. |
| #676 | Feature: Select Details + Customizable Search | 🔴 Adding a description/details panel below the selected option would be a nice Select enhancement. **Effort:** 2-4 hours. |

---

## charmbracelet/glamour (= candy-shine), charmbracelet/log (= candy-log)

Both are quiet upstream — only deps + CI in recent merges. No applicable changes to port.

---

## NimbleMarkets/ntcharts (= sugar-charts)

Upstream very quiet (no merges since 2025-01). Our port is ahead of upstream
on several axes (Streamline, Wavelinechart, BrailleGrid). No port-back items.

---

## charmbracelet/x — experimental subpackages

Living in `charmbracelet/x/`, these are small reusable utilities that
the rest of the ecosystem leans on. SugarCraft picks them up as helpers
under `candy-core/src/Util/` rather than stand-alone libs.

| Upstream | Title | Status in SugarCraft | Notes |
|---|---|:--:|---|
| [`x/exp/open`](https://github.com/charmbracelet/x/tree/main/exp/open) | Cross-platform `open` for URLs / files | 🟢 | Ported to `candy-core/src/Util/Open.php` — Linux/BSD/Solaris (`xdg-open`), macOS (`open`), Windows (`cmd /c start`), WSL (`wslview` w/ `cmd.exe` fallback), scheme allowlist (`http(s)`, `file`, `ftp`, `ssh`, `mailto`). |
| [`x/editor`](https://github.com/charmbracelet/x/tree/main/editor) | Spawn `$EDITOR` on a temp file and round-trip the contents | 🟢 | Ported to `candy-core/src/Util/Editor.php`. Discovery walks `$VISUAL` → `$EDITOR` → platform defaults (`vi`/`nano` on POSIX, `notepad` on Windows); splits the env-var so `EDITOR='vim -p'` keeps its flags. Wired into `sugar-bits/TextArea` via `Ctrl+O` (Cmd::exec → temp file round-trip → `TextAreaEditedMsg`); `:cq`-style non-zero exits preserve the pre-edit value. |
| [`x/mosaic`](https://github.com/charmbracelet/x/tree/main/mosaic) | Image-to-terminal cell renderer (Sixel, Kitty, iTerm2, Unicode half-block) | 🟡 | Scaffolded in `candy-mosaic/` (PR1). v1 ships four backends: HalfBlock (▀ + 24-bit fg/bg), Sixel, Kitty graphics, iTerm2 OSC 1337. Detection via env vars + optional DA1 query. Aspect-ratio handling, resize, Picker facade (probe-once). |

---

## Recommended next-port wave

If we run a feature-port wave next, **prioritize**:

1. **bubbles #233 — Tree component on sugar-bits** (1-2 days; opens many use cases)
2. **bubbles #246 — Per-row/cell `StyleFunc` on sugar-bits Table** (half day; consistency with candy-sprinkles Table)
3. **bubbles #910 — Dynamic-height TextArea** (2 hours; visible UX win in sugar-prompt's Text field)
4. **huh #272 — Form-level KeyMap override on sugar-prompt** (half day; long-requested upstream)

Smaller fixes worth bundling into a single PR:

- bubbletea #1680 null-input null-guard in `Program::executeRequest`
- bubbles #472 sugar-bits Table cell-padding regression test (and fix if it repros)
- bubbles #479 Viewport scroll-to-end test against post-wrap line count
- huh #749 sugar-prompt Select multiline-option cursor-visibility test
- huh #679 Select::withValue('mid-list-id') programmatic-default rendering test

Total bundled bug-fix wave: ~half day. Total feature wave: ~3-4 days.

---

## How to refresh this doc

```sh
unset GITHUB_TOKEN
gh api 'repos/charmbracelet/bubbletea/pulls?state=closed&per_page=10&sort=updated&direction=desc' \
  --jq '.[] | select(.merged_at != null) | "\(.number)  \(.title)  (\(.merged_at[:10]))"'
```

Repeat for `bubbles`, `huh`, `lipgloss`, `glamour`, `gum`, `harmonica`,
`wish`, `colorprofile`, `log`, `freeze`, `pop`, `skate`, `soft-serve`,
`fang`, `wishlist`, and `NimbleMarkets/ntcharts` — plus the smaller-port
upstreams listed in [`MATCHUPS.md`](./MATCHUPS.md).

For each, scan: (a) merged PRs not titled `chore`/`docs`/`deps`,
(b) open issues sorted by comment-count. Map findings against
SugarCraft's current state (✅/🟡/🔴/⚪) and assign benefit/downside/effort.
