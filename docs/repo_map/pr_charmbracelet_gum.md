# Second-Stage Ecosystem Intelligence: charmbracelet/gum

## Metadata
- **Report Date**: 2026-05-27
- **Upstream URL**: https://github.com/charmbracelet/gum
- **Upstream Language**: Go
- **Upstream Stars**: ~23.2k (2026)
- **License**: MIT
- **SugarCraft Port**: `candy-shell` (🟡 in progress)
- **Primary Maintainers**: @caarlos0, @meowgorithm, @maaslalani, @aymanbagabas

---

## 1. Repository Overview

**charmbracelet/gum** is a CLI-first TUI building toolkit that wraps the Bubble Tea ecosystem into composable shell commands. It provides 15 subcommands (`input`, `write`, `filter`, `choose`, `confirm`, `file`, `pager`, `spin`, `style`, `table`, `format`, `join`, `log`, `completion`, `version`) that expose interactive TUI elements as simple shell utilities.

The project exemplifies the "batteries included" philosophy — instead of writing Go to use Bubbles, users write shell scripts. Each command is a self-contained package following a consistent `cmd/options.go + cmd/command.go + cmd/<model>.go` pattern.

**Key architectural insight**: Gum is NOT a library — it's purely a binary. This is the fundamental gap SugarCraft's `candy-shell` fills.

---

## 2. Existing SugarCraft Mapping

| gum command | SugarCraft lib | Status | Notes |
|---|---|---|---|
| `gum input` | `sugar-prompt` / `candy-forms` | 🟢 / 🟡 | Text input via TextInput field |
| `gum write` | `sugar-prompt` / `candy-forms` | 🟢 / 🟡 | Multi-line textarea via TextArea field |
| `gum filter` | `sugar-filter` (planned) | 🔴 | Fuzzy list filter, same fuzzy algorithm (`sahilm/fuzzy`) |
| `gum choose` | `sugar-choose` (planned) | 🔴 | Item selector grid with vim-style navigation |
| `gum confirm` | `candy-forms` | 🟡 | Yes/no confirmation via Confirm field |
| `gum file` | `candy-forms` (FilePicker) | 🟡 | File tree browser |
| `gum pager` | `sugar-pager` (planned) | 🔴 | Viewport scroll |
| `gum spin` | `sugar-spin` (planned) | 🔴 | Spinner + PTY command execution |
| `gum style` | `candy-shine` | 🟡 | Lipgloss wrapper for styling |
| `gum format` | `sugar-format` (planned) | 🔴 | Markdown/code/template/emoji via glamour |
| `gum table` | `sugar-table` | 🟢 | Table with row selection |
| `gum log` | `candy-log` | 🟢 | Structured logging |
| `gum join` | `candy-shine` | 🟡 | Layout joining via lipgloss |
| Bubbles dep | `candy-sprinkles` + `sugar-bits` | 🟢 / 🟡 | TUI component primitives |
| Lipgloss dep | `candy-shine` / `sugar-shine` | 🟡 | Styling/rendering engine |

**Key insight from MATCHUPS.md**: `candy-shell` (the gum port) is 🟡 in progress. The underlying foundation (`candy-sprinkles`, `candy-forms`, `sugar-bits`) is what enables the shell commands.

---

## 3. Previously Identified Gaps

From `repo_map/charmbracelet_gum.md`:

1. **Not a library** — Cannot be used as a Go package without exec'ing subprocess
2. **No async input streaming** — stdin read upfront, not incrementally (critical for `filter` with large datasets)
3. **PTY fragility** — `spin` has platform-specific PTY handling
4. **Limited layout engine** — `join` is basic horizontal/vertical only
5. **No state persistence** — Each invocation stateless; no multi-step wizards
6. **Fuzzy only** — Filter uses fuzzy by default; no exact-match-first without `--no-fuzzy`
7. **Tied to Bubble Tea** — Cannot embed as library

---

## 4. High-Signal Open Issues

### Issue #12: Choose and filter don't work well with long lines (Bug, 2022)
**Signal**: Open since 2022, labeled `blocked`, many upvotes
**Problem**: Long lines (>80 chars) truncate without horizontal scrolling
**Attempts**: Maintainers acknowledged "viewport scroll" would fix, blocked on `bubbles` PR #240
**Relevance**: 🔴 HIGH — SugarCraft must implement horizontal scrolling for `filter` and `choose`

### Issue #226: choose & filter to support display of multiple lines per item (Enhancement)
**Signal**: 1+ reaction, active discussion
**Problem**: Cannot display multi-line descriptions in choose/filter
**Discussion**: Could use structured data (CSV/JSON) with field selection
**Relevance**: 🟡 MEDIUM — Would need multi-line item renderer

### Issue #434: Add command to tail n lines of output (Enhancement)
**Signal**: 6+ reactions, long discussion thread
**Problem**: Users want streaming output display (like `docker build`)
**Alternatives discussed**: `gum pager` with `F` key like `less`, new `gum stream` command
**User workaround**: Pure bash implementation at https://github.com/amancevice/spin
**Relevance**: 🟡 MEDIUM — Streaming output is a gap

### Issue #688: Extended search syntax like fzf (Enhancement, blocked)
**Signal**: 2+ reactions, labeled `blocked`
**Problem**: Want fzf-style extended search (`'exact`, multiple terms, `+prefix`)
**Status**: Blocked on `sahilm/fuzzy` library limitations
**Maintainer note**: "filtering algorithm should be improved first, long play"
**Relevance**: 🟡 MEDIUM — Advanced filtering is a different/optional use case

### Issue #990: gum choose: autoselect option under cursor when none is selected
**Signal**: 2+ reactions
**Problem**: First-time users expect Enter to select highlighted item
**Suggestion**: `--required` flag that auto-selects cursor if nothing selected
**Relevance**: 🟡 MEDIUM — Good UX pattern for confirm/choose

### Issue #937: Allow to set delimiter for `--selected` in gum choose
**Signal**: Active discussion
**Problem**: Comma in option values conflicts with `--selected` delimiter
**Requested**: `--selected-delimiter` flag
**Workaround**: Remove commas from values
**Relevance**: 🟡 MEDIUM — Parser flexibility needed

### Issue #970: gum write cursor position option
**Signal**: Active discussion
**Problem**: Want cursor at beginning of initial value (for commit message review)
**Suggestion**: Bool flag for cursor position
**Note**: Default value behavior is unpredictable for multi-line input
**Relevance**: 🟡 MEDIUM — Textarea cursor positioning is a common need

### Issue #1001: Crash on filter when wrapping after reflow
**Signal**: Open (2026)
**Problem**: Crash when pressing up arrow on top item after terminal resize
**Relevance**: 🟡 MEDIUM — Terminal resize edge cases

### Issue #1022: Bug on gum file after upgrading to 0.17
**Signal**: Multiple reports, 1 reaction
**Problem**: `gum file` clears terminal in v0.17
**Regression**: Worked in v0.16.2
**Relevance**: 🟡 MEDIUM — Terminal clearing is a serious UX regression

### Issue #1025: Gum file removes first line
**Signal**: Open
**Problem**: First file/directory not displayed, can still be selected
**Root cause identified**: `filepicker.SetHeight(height)` is off by 1
**Relevance**: 🟡 MEDIUM — Height calculation edge cases

### Issue #1055: gum spin --spinner flag doesn't recognize options
**Signal**: Open (2026)
**Problem**: `gum spin --spinner monkey sleep 5` fails
**Fix in progress**: PR #1059
**Relevance**: 🟡 MEDIUM — Spinner argument parsing

---

## 5. Important Closed Issues

### Issue #785: --no-strip-ansi prints ANSI codes and garbles output (Bug)
**Signal**: closed (completed) in v0.15.0
**Problem**: Stripping ANSI then re-adding broke highlighting
**Root cause**: Byte positions in fuzzy match need conversion to visible char positions
**Fix**: PR #799 — Use `bytePosToVisibleCharPos()` with `rivo/uniseg` for grapheme clusters
**Lesson**: ANSI handling is notoriously tricky; must strip THEN match THEN reapply

### Issue #339: Since version 0.10 `gum choose --no-limit` selection behavior changed (Bug)
**Signal**: 2+ reactions, closed (completed)
**Problem**: Enter no longer selects highlighted item; must press Space first
**Breaking change**: Intended behavior change in v0.10
**Discussion**: Users wanted both behaviors; maintainers kept new behavior
**Suggestion**: Add flag for old behavior
**Status**: Still not resolved — behavior differs for `--no-limit`
**Relevance**: 🟡 MEDIUM — Default selection behavior is contentious

### Issue #689: color codes break gum filter match foreground (Bug)
**Signal**: closed (completed) in v0.15.0
**Problem**: ANSI color codes broke match highlighting
**Related**: Issue #785 (same root cause)
**Relevance**: 🟡 MEDIUM — ANSI + fuzzy matching intersection

### Issue #580: choose crashes with spacebar twice in filter mode (Bug)
**Signal**: closed (completed) in v0.14.2
**Problem**: Index out of bounds panic
**Root cause**: Conflict between select and filter modes
**Relevance**: 🟡 MEDIUM — State machine edge case

### Issue #823: memory leak in pager (Bug)
**Signal**: closed (completed)
**Problem**: `gum pager` as $PAGER caused 100% CPU + RAM exhaustion
**Root cause**: Soft-wrap made default in v0.15; infinite render loop
**Workaround**: `PAGER="gum pager --no-soft-wrap"`
**Fix**: PR #827 + PR #791
**Relevance**: 🟢 HIGH — Soft-wrap edge case caused severe performance issue

### Issue #119: ENABLE_VIRTUAL_TERMINAL_INPUT flag remains set on Windows (Bug)
**Signal**: 6+ reactions, closed (completed)
**Problem**: After gum runs, arrow keys return ANSI sequences instead of navigating
**Root cause**: `SetConsoleMode` sets flag but doesn't restore it
**Fix**: Updated `bubbletea` dependency to get fix from PR #565
**Relevance**: 🟡 MEDIUM — Windows terminal state management

### Issue #957: gum filter --no-strict --value returns wrong value (Bug)
**Signal**: Open
**Problem**: Default value not returned when user accepts as-is
**Related**: Issue #938
**Fix in PR**: #989
**Relevance**: 🟡 MEDIUM — Strict mode edge case

### Issue #938: gum choose --no-limit behaves inconsistently on Enter (Bug)
**Signal**: 3+ reactions, open
**Problem**: Enter outputs empty line with exit 0 vs. selecting highlighted or error
**Expected**: Either error exit 1 OR select highlighted item
**Relevance**: 🟡 MEDIUM — Consistent selection behavior

### Issue #895: gum input doubles prompt output (Bug)
**Signal**: 3+ reactions, open
**Problem**: Since v0.15, prompt displays twice in macOS Terminal
**Root cause**: `bubbles` `placeholderView` creates slice `size m.Width+1` initially 0
**Fix identified**: PR in bubbles #816 by community member
**Relevance**: 🟡 MEDIUM — Two-phase render edge case

### Issue #574: gum log -l fatal no longer returns error status code (Bug)
**Signal**: closed (completed)
**Problem**: `fatal` log level doesn't exit with code 1
**Maintainer clarification**: This never worked as user expected; scripts need `set -e`
**Lesson**: Exit codes work per-process; `gum log` can only `exit 1`, not kill parent
**Relevance**: 🟡 MEDIUM — User expectation vs. process model mismatch

---

## 6. Recurring Pain Points

### A. ANSI/Grapheme Handling (High Frequency)
**Pattern**: Issues #689, #785, #799 all stem from same root: matching against ANSI-stripped strings produces byte-offset mismatches when re-applying highlighting.
**Evolution**:
1. Strip all ANSI → loses color info
2. Match against stripped → get byte positions
3. Apply highlighting at byte positions → off by grapheme clusters
**Solution pattern**: Use `rivo/uniseg` for grapheme iteration; convert byte positions to char positions before calling `ansi.Cut`.

**Defensive lesson for SugarCraft**: Any time we strip ANSI, match, then re-apply, we need grapheme-aware position mapping.

### B. Terminal Size / Viewport Calculations (High Frequency)
**Pattern**: Issues #12, #346, #766, #823, #895, #1001, #1022, #1025 all involve viewport sizing.
**Common failures**:
- Initial render with `Width=0` causes truncated placeholder/viewport
- `SetHeight(height)` off-by-one errors
- Soft-wrap changing effective line count
- Resize events not properly propagated

**Defensive lesson for SugarCraft**: Initialize with minimum viable dimensions; always handle resize events; validate viewport bounds after any content change.

### C. Performance at Scale (Medium Frequency)
**Pattern**: Issues #346 (big files), #408 (large paste), #848 (log performance)
**Root causes**:
- `gum log`: 100 invocations = 10-16 seconds vs 0.05s for `/bin/echo` (1356 voluntary context switches)
- `gum filter/choose` with 1M rows: 1-2 minute delay
- Large paste into `write`: hang

**Workaround for log**: Use `set -euo pipefail` in scripts; avoid subprocess overhead for hot paths
**Workaround for large data**: Stream/process in chunks

**Defensive lesson for SugarCraft**: Minimize subprocess spawn overhead; consider native PHP TTY handling without process spawn.

### D. Timeout Edge Cases (Medium Frequency)
**Pattern**: Issues #260, #618, #632, #697, #718, #724, #747, #888, #917, #918, #951, #997
**Common failures**:
- Timeout not applied to command execution (only spinner)
- Ctrl+C treated as timeout instead of abort
- Default unit inconsistency (seconds vs. milliseconds)
- Timeout printed to output on expiry

**Defensive lesson for SugarCraft**: Context propagation must reach actual operation; interrupt check order matters (ErrInterrupted before ErrProgramKilled).

---

## 7. Frequently Requested Features

### A. Configuration File Support
**Issue**: #991, PR #1024 (open)
**Request**: YAML config file at `~/.config/gum/config.yaml` with command defaults
**Precedence**: config file < env vars < CLI flags
**Implementation**: `internal/config` package, XDG locations, wire `envprefix` into Kong
**Relevance**: 🟢 HIGH — SugarCraft should implement config file support early

### B. Padding Everywhere
**PR**: #960 (merged in v0.17.0)
**Feature**: `--padding` flag on most commands (1-4 values like CSS)
**Implementation**: `ParsePadding` function, apply in View via `lipgloss.NewStyle().Padding()`
**Relevance**: 🟢 DONE — SugarCraft has this in candy-shine

### C. Horizontal Scrolling
**PR**: #791 (open, blocked on bubbletea v2)
**Feature**: Horizontal scroll for filter and pager with long lines
**Blocks**: Awaiting `bubbles` viewport improvements
**Relevance**: 🟡 MEDIUM — Important for long-line handling

### D. Fuzzy Find Mode for File Picker
**Issue**: #603, PR #1006 (open)
**Request**: Press `/` to fuzzy filter files in `gum file`
**Status**: Community PR in progress
**Relevance**: 🟡 MEDIUM — Nice-to-have for file picker

### E. Timeout Display
**Issue**: #951 (open)
**Request**: Show elapsed time during `gum spin`
```
⣽  Running ... [3s]
⣽  Running ... [4s]
```
**Relevance**: 🟡 MEDIUM — Useful for long-running operations

### F. Positional Indicators
**Discussion**: #869
**Request**: Position indicator like `fzf`: `[pos]/[filtered]/[total]`
**Consideration**: Filter wants `[pos]/[filtered]` only (query affects denominator)
**Relevance**: 🟡 MEDIUM — Good UX for navigation

### G. Grid Menu Selection
**Discussion**: #864
**Request**: 2D grid selection (like whiptail but interactive)
**Maintainer response**: "Not planned short-term; fairly trivial in Bubble Tea"
**Relevance**: 🔴 LOW — Not a shell script use case

### H. Key/Value Selection
**PR**: #598 (merged, then reverted, then re-merged)
**Feature**: `gum choose` with `--label-delimiter` for key:value pairs
**Status**: Working in v0.14+
**Relevance**: 🟢 DONE — sugar-prompt/sugar-choose should support this

### I. Select All / Deselect All
**PR**: #769 (merged in v0.15.0)
**Feature**: `--selected="*"` to select all items by default
**Relevance**: 🟢 DONE — sugar-choose should support this

### J. Input/Output Delimiters
**PR**: #779 (merged in v0.15.0)
**Feature**: `--input-delimiter` and `--output-delimiter` for pipe-friendly parsing
**Relevance**: 🟢 DONE — sugar-filter/choose should support this

### K. File Extension for Editor Tempfile
**Discussion**: #963
**Request**: `--file-extension` flag for `gum write` so Ctrl+E opens editor with correct syntax
**Relevance**: 🟡 MEDIUM — Important for developer workflows

### L. File Type Filtering
**Discussion**: #1012
**Request**: `--file-extension` filter for `gum file` to disable non-matching types
**Status**: Implemented in community fork, needs upstream review
**Limitation**: Directory selection stops working with filter
**Relevance**: 🟡 MEDIUM — File picker filtering

---

## 8. Important PRs

### PR #742: refactor: removing huh as a dep (Merged)
**Impact**: Major architectural change
**Changes**:
- Removed dependency on `charmbracelet/huh` form library
- Previously: `gum confirm`, `gum file` used `huh`
- Now: Direct `bubbles` usage
- 35 commits, +1374 -292 lines
**Lesson**: Tight coupling with `huh` created maintenance burden; direct `bubbles` usage cleaner

### PR #960: feat: adding --padding to most commands (Merged)
**Impact**: CSS-like padding system
**Changes**:
- `ParsePadding` function exported from `style/spacing.go`
- Padding applied in View via `lipgloss.NewStyle().Padding()`
- 28 files changed, +132 -81 lines
**Pattern**: Consistent flag across commands; padding as first-class citizen

### PR #791: horizontal scrolls and other improvements (Open)
**Impact**: Would fix long-standing issue #12
**Status**: Blocked on bubbletea v2
**Components**:
- `pager`: horizontal scroll, search input width
- `filter`: horizontal scroll

### PR #827: fix(pager): memory/cpu usage when using soft-wrap (Merged)
**Impact**: Fixed critical performance regression
**Root cause**: Soft-wrap default created render loop
**Changes**: 1-line fix but critical

### PR #799: fix(filter): wrong highlight when option has grapheme clusters (Merged)
**Impact**: Fixed ANSI handling for grapheme clusters
**Key changes**:
- Added `rivo/uniseg` dependency
- New `bytePosToVisibleCharPos()` function
- Switched to `lipgloss.StyleRanges()` from string building
**Pattern**: Byte→char position conversion is essential for ANSI-aware matching

### PR #747: feat: improve handling ctrl+c and timeouts (Merged)
**Impact**: Proper interrupt propagation
**Changes**:
- `ctrl+c` exits properly
- Timeout uses `context.Context` throughout
- Exit code handling clarified

### PR #918: fix: treating SIGINT(ctrl+c) as timeout (Merged)
**Impact**: Fixed interrupt vs. timeout ordering
**Fix**: Check `ErrInterrupted` before `ErrProgramKilled` in main.go

### PR #1024: feat: configuration file support for gum (Open)
**Impact**: Would enable persistent defaults
**Implementation**:
- XDG config locations
- YAML parsing
- Precedence: config < env < CLI flags
- `GUM_DEBUG_CONFIG=1` for debugging

### PR #1006: feat(file): add fuzzy find mode to file picker (Open)
**Impact**: File picker enhancement
**Status**: Community contribution

---

## 9. Architectural Changes

### v0.10.0: Truecolor Support
- Shifted from ANSI256 to truecolor
- Terminal color capability detection now critical

### v0.14.0-v0.15.0: Huh Removal
- Previously: `gum confirm`, `gum file` used `huh` library
- Now: Direct `bubbles` usage
- Reduced coupling; easier to maintain

### v0.15.0: Major Refactor
- 30+ PRs merged
- Timeout standardization across commands
- ANSI handling overhaul (issues #689, #785)
- Context-based interrupt handling

### v0.17.0: Padding
- `--padding` flag standardized
- `ParsePadding` utility created
- Consistent spacing API

---

## 10. Performance Discussions

### Issue #848: gum log performance prohibitively slow
**Finding**: 100 invocations = 10-16 seconds vs 0.05s for `/bin/echo`
**Root cause**: 1356 voluntary context switches per invocation; Go runtime init overhead
**Platform variance**: Native Windows: 2.7s; WSL2: 15s
**Workaround**: Use native binaries; avoid in hot script loops
**Suggestion**: Consider gum as "setup once" not "per-operation" overhead

**SugarCraft implication**: PHP FFI overhead may be similar; caching/interleaving important.

### Issue #346: Too slow on big files (1M rows)
**Finding**: 1-2 minutes for first action on large datasets
**Root cause**: Full data in memory; no virtualization
**Workaround**: Chunked processing

**SugarCraft implication**: Virtual scrolling essential for large datasets.

### Issue #331: Binary size ~20MB
**Finding**: After goldmark-emoji fix, binary ~5MB compressed
**Root cause**: `goldmark-emoji` included full emoji definition (14MB)
**Fix**: Updated `goldmark-emoji` dependency (PR #686)

**SugarCraft implication**: PHP binary distribution is different; attention to extension/dependency size.

---

## 11. Extensibility Discussions

### Issue #224: Package as lib (2022, closed)
**Request**: Export as `gum.so` for FFI usage from Node.js/Deno
**Status**: Closed as out-of-scope
**Maintainer position**: "We just wouldn't have the time to maintain it"
**Alternative**: Embed gum binary in project

**Strategic lesson**: SugarCraft's approach (library-first) fills this exact gap.

### Discussion #38: Node.js API
**Request**: npm package wrapping gum binary
**Maintainer**: "Full support if you want to make it"
**Status**: Community solution (not maintained by charm)

### Discussion #545: JSON/YAML structured input to filter
**Request**: Pass structured data, display field, output selected
**Status**: Not implemented; would need major design work
**Workaround**: jq post-processing

**SugarCraft opportunity**: Native PHP object passing between components.

### Discussion #863: Use as library from any language via FFI
**Request**: Compile gum as shared library
**Status**: Not planned

**SugarCraft strategic position**: PHP native library fills this gap perfectly.

---

## 12. API/UX Complaints

### Issue #941: gum choose needs enter twice from pipe
**Signal**: 2+ reactions
**Problem**: `echo 1 | gum choose` requires double Enter
**Status**: Open, confirmed on Windows PowerShell
**Relevance**: 🟡 MEDIUM — stdin/input handling inconsistency

### Issue #988: Weird screen rendering when invoked via npx zx
**Signal**: Open
**Problem**: Up/down duplicates first option; Ctrl+C dumps output
**Status**: Terminal detection issue
**Workaround**: Pass flag to force TTY recognition
**Relevance**: 🟡 MEDIUM — Non-TTY invocation edge cases

### Issue #695 / #737: filter --no-strict behavior changed
**Signal**: Multiple issues, ongoing
**Problem**: Default value behavior when accepted as-is is wrong
**Fix**: PR #989 in progress
**Relevance**: 🟡 MEDIUM — Default value handling edge case

### Issue #926: filter --placeholder only displays first character
**Signal**: Closed with #919
**Root cause**: Text input width initialization
**Relevance**: 🟡 MEDIUM — Placeholder sizing edge case

---

## 13. Migration Problems

### v0.10.0 Breaking Change: Selection behavior
**Problem**: Enter no longer selects highlighted item for `--no-limit`
**User impact**: Scripts that relied on old behavior broke
**Maintainer position**: Intended change; suggest using `set -e`

### v0.15.0: Soft-wrap default broke pager as $PAGER
**Problem**: `gum pager` became unusable as system pager
**Workaround**: `PAGER="gum pager --no-soft-wrap"`
**Fix**: PR #827

### Version Compatibility Matrix
- v0.14: Works with `bubbletea` < v1.0
- v0.15: Requires `bubbletea` v1.1+; removed `huh`
- v0.16: Bug fixes; timeout refinement
- v0.17: Padding; regression in `gum file`

---

## 14. Clever Fixes & Workarounds

### Workaround: Horizontal scrolling for long lines
**Problem**: No native horizontal scroll
**Workaround**: `gum format` with line wrapping provides alternative view
**Partial solution**: Left/right arrows move viewport in `filter` (PR #791)

### Workaround: Streaming output with gum spin
**Problem**: No streaming; only final output
**Workaround**: Background process + output capture
**Example**: `gum spin -- spin sleep 30 &` (background subprocess)

### Workaround: Interactive confirm without blocking
**Problem**: `gum confirm` blocks until answer
**Workaround**: `gum confirm && action || alternative`

### Workaround: Multi-step wizards
**Problem**: Gum is stateless
**Pattern**: Chain commands; pass values via shell variables
```bash
choice=$(gum choose a b c)
result=$(gum input --value "$choice")
```

### Community workaround: spin equivalent in bash
**Repo**: https://github.com/amancevice/spin
**Implementation**: Pure bash streaming spinner
**Lesson**: Users build missing functionality in shell

### Community workaround: Node.js wrapper for gum
**Pattern**: Spawn gum as subprocess; parse output
**Example**: `gum.choose("Foo", "Bar")` in Node.js

---

## 15. Community Workarounds

| Problem | Workaround | Source |
|---|---|---|
| Streaming output | Background process + separate viewer | Issue #434 |
| Log performance | Use `set -euo pipefail` + builtins | Issue #848 |
| Large datasets | Pre-filter with `jq` or shell | Issue #346 |
| Config file | Wrapper script + env vars | Issue #991 |
| Non-TTY invocation | Force TTY flag | Issue #988 |
| Mouse support | Not implemented; use keyboard | Issue #107 |
| Grapheme clusters | Emoji-aware filtering | PR #799 |

---

## 16. Maintainer Guidance Patterns

### "Not planned short-term"
Used for: Grid menu (#864), package-as-lib (#224), complex features
**Implication**: SugarCraft can differentiate by implementing these

### "Fairly trivial in Bubble Tea"
Used for: Grid menu, custom keybindings
**Implication**: Bubble Tea makes these easy; SugarCraft should enable same

### "Long play" / "blocked"
Used for: Extended search syntax (#688), horizontal scrolling (#791)
**Implication**: Good opportunities for SugarCraft to implement first

### "We can't kill the parent PID"
Used for: `gum log fatal` behavior (#574)
**Implication**: Process model limitations are real; explain to users

### "set -euo pipefail"
Frequently recommended for script reliability
**Implication**: SugarCraft examples should recommend this

### "Works on main"
Used for: Many reported bugs that are fixed in HEAD
**Implication**: Rolling release hides some issues

---

## 17. Rejected Ideas Worth Revisiting

### Package as Library (Issue #224)
**Request**: Export `gum.so` for FFI
**Rejection reason**: Maintenance burden
**Value**: SugarCraft's core value proposition

### Grid Menu Selection (Discussion #864)
**Request**: 2D grid with multiple columns
**Rejection reason**: Not planned short-term
**Alternative**: Build in Bubble Tea (trivial)
**SugarCraft opportunity**: Implement as `SugarDash` (already exists as grid library)

### Extended Search Syntax (Issue #688)
**Request**: fzf-style `'+prefix` `'exact` etc.
**Rejection reason**: `sahilm/fuzzy` library limitation
**Alternative**: Use different fuzzy library
**SugarCraft opportunity**: Use alternative algorithm that supports extended syntax

### Streaming/Background Spinner
**Request**: Stream output while running
**Rejection reason**: Complex to implement correctly
**SugarCraft opportunity**: Implement with PHP async/process handling

### Mouse Support
**Request**: Mouse clicks in filter/choose
**Mentioned**: Possible with Bubble Tea; requested
**Status**: Not implemented
**SugarCraft opportunity**: Implement with mouse event handling

---

## 18. Problems Likely Relevant To SugarCraft

### A. ANSI/Grapheme Aware Matching (🔴 HIGH)
All fuzzy matching that involves highlighting MUST:
1. Strip ANSI → match → get byte positions
2. Convert byte positions to grapheme positions
3. Apply highlighting using grapheme positions

**SugarCraft implementation**: Use `rivo/uniseg` or similar for grapheme iteration.

### B. Viewport Bounds After Content Change (🔴 HIGH)
Every time:
- Terminal resizes
- Content changes (filtering, selection)
- Viewport must be re-validated

**SugarCraft implementation**: Clamp cursor/index after any content mutation; handle resize events explicitly.

### C. Soft-wrap Edge Cases (🟡 MEDIUM)
When soft-wrap is enabled:
- Line count changes dynamically
- Viewport calculations break
- Performance degrades

**SugarCraft implementation**: Profile soft-wrap heavily; consider caching line measurements.

### D. Process Spawn Overhead (🟡 MEDIUM)
Each `gum` invocation:
- ~150ms on fast systems
- 1000+ context switches
- Go runtime initialization

**SugarCraft advantage**: PHP FFI call is cheaper than process spawn.
**Caveat**: PHP runtime still has overhead.

### E. Interrupt/Timeout Priority (🟡 MEDIUM)
Error checking order must be:
1. `ErrInterrupted` (Ctrl+C)
2. `ErrProgramKilled` (timeout)
3. Other errors

**SugarCraft implementation**: Ensure PHP signal handling matches this order.

### F. Stdin/TTY Detection (🟡 MEDIUM)
Different code paths for:
- Pipe input (`echo 1 | gum choose`)
- TTY input (`gum choose 1`)
- Non-TTY invocation (`npx zx`)

**SugarCraft implementation**: Detect mode explicitly; force TTY mode available as flag.

### G. Binary Size / Distribution (🟡 MEDIUM)
Go binary: ~5-20MB
**Pain point**: Embedding in other projects is heavy
**PHP advantage**: `composer require` is lighter
**PHP concern**: PHP extension compilation complexity

---

## 19. Features SugarCraft Should Consider

### A. Configuration File Support (🔴 HIGH PRIORITY)
**Implementation**:
- XDG locations: `~/.config/candy-shell/config.yaml`
- YAML parsing
- Precedence: config < environment < constructor options
- Debug flag: `CANDY_SHELL_DEBUG_CONFIG=1`

**SugarCraft advantage**: Native PHP config array; no YAML parsing overhead.

### B. Horizontal Scrolling (🔴 HIGH PRIORITY)
**Why**: Issue #12 open since 2022; blocks real-world use cases
**Implementation**: Viewport with left/right arrow handling
**References**: PR #791 (upstream), bubbles PR #240

### C. Proper Cursor Positioning for Textarea (🟡 MEDIUM)
**Why**: Issue #970 shows need for cursor control
**Implementation**: Options for `--cursor-position start|end`
**Edge case**: Multi-line initial value behavior is unpredictable

### D. Timeout with Elapsed Display (🟡 MEDIUM)
**Why**: Issue #951 requests elapsed time display
**Implementation**: Optional timer display during spin

### E. Fuzzy Find for File Picker (🟡 MEDIUM)
**Why**: Issue #603 open since 2024; community PR exists
**Implementation**: Press `/` to activate fuzzy filter within file picker

### F. File Extension Filtering (🟡 MEDIUM)
**Why**: Discussion #1012 shows need for file type filtering
**Implementation**: `--extensions .go,.php,.md` flag
**Edge case**: Directories should remain selectable

### G. Positional Indicators (🟡 MEDIUM)
**Why**: Discussion #869; fzf-style `[n/m/total]`
**For filter**: `[cursor]/[filtered]/[total]`
**For choose**: `[cursor]/[total]`

### H. Extended Search Syntax (🟡 LOW)
**Why**: Issue #688 blocked on fuzzy library
**Implementation**: Use `symfony/unicode-cluster` or similar for proper grapheme-aware fuzzy
**Alternative**: Porter stemmer + phrase match

### I. Multi-line Item Display (🟡 LOW)
**Why**: Issue #226 requested
**Implementation**: Support for collapsed/expanded multi-line items

### J. Plugin/Extension System (🟡 LOW)
**Why**: Issue #224 (library request) shows demand
**Implementation**: Not for v1; keep eye on demand

---

## 20. Architectural Lessons

### Lesson 1: Huh Coupling Was a Mistake
**Problem**: `gum confirm`, `gum file` used `huh` library
**Impact**: Tight coupling; maintenance burden; difficult for contributors
**Fix**: Direct `bubbles` usage
**SugarCraft principle**: Depend on primitives (bubbles/lipgloss), not composites (huh)

### Lesson 2: ANSI Handling Requires Grapheme Awareness
**Problem**: Byte-offset matching breaks on emoji/grapheme clusters
**Fix**: PR #799 introduced `bytePosToVisibleCharPos()` + `rivo/uniseg`
**SugarCraft principle**: Always use grapheme-aware string operations for terminal output

### Lesson 3: Viewport Initialization Order Matters
**Problem**: Issue #895 (prompt doubling) caused by `m.Width=0` on first render
**Fix**: Use `max(len(placeholder), m.Width+1)` for buffer sizing
**SugarCraft principle**: Initialize components with actual dimensions before first render

### Lesson 4: Timeout Context Must Flow to Actual Operation
**Problem**: PR #997 showed timeout only applied to spinner, not command
**Fix**: Context must wrap actual `exec.Command` call
**SugarCraft principle**: Timeout/ticket/cancellation must reach the actual I/O operation

### Lesson 5: Soft-wrap Default Caused Memory Issues
**Problem**: v0.15 made soft-wrap default; caused infinite render loop
**Fix**: Workaround flag `--no-soft-wrap`; better fix in PR #791
**SugarCraft principle**: Default to simpler behavior; advanced features opt-in

### Lesson 6: Interrupt Order Matters
**Problem**: PR #918 showed Ctrl+C treated as timeout
**Fix**: Check `ErrInterrupted` before `ErrProgramKilled`
**SugarCraft principle**: User abort always takes priority over timeout

---

## 21. Defensive Design Lessons

### A. Bounds Clamping After Every Mutation
**Pattern**: After any filter/index/cursor change, always clamp
```go
m.cursor = ordered.Clamp(m.cursor, 0, len(m.matches)-1)
```
**Alternative**: Custom `clamp()` function replaced with `ordered.Clamp()` from `charmbracelet/x/exp/ordered`

### B. Resize Handler Must Recalculate Everything
**Pattern**:
```go
case tea.WindowSizeMsg:
    m.viewport.Width = msg.Width
    m.viewport.Height = msg.Height
    // Also recalculate cursor bounds
```

### C. ANSI Strip → Match → Reapply Pattern
**Pattern**:
```go
// 1. Strip for matching
stripped := ansi.Strip(option)
// 2. Match against stripped
matches := fuzzy.Find(query, []string{stripped})
// 3. Get byte positions from match
// 4. Convert to char positions
start, stop := bytePosToVisibleCharPos(option, rng)
// 5. Reapply with correct positions
ranges = append(ranges, lipgloss.NewRange(start, stop+1, style))
// 6. Render with StyleRanges
lipgloss.StyleRanges(option, ranges...)
```

### D. Initial Render Guard
**Pattern**: Check all dimensions are non-zero before rendering
```go
if m.Width == 0 {
    return "" // or minimal placeholder
}
```

### E. Error Type Ordering in Main
**Pattern**:
```go
if errors.Is(err, tea.ErrInterrupted) {
    os.Exit(exit.StatusAborted)  // Ctrl+C first
}
if errors.Is(err, tea.ErrProgramKilled) {
    os.Exit(exit.StatusTimeout)   // Then timeout
}
```

---

## 22. Ecosystem Trends

### Trend 1: Config File Support
**Observation**: Multiple requests (#991, PR #1024); users want persistent defaults
**Direction**: XDG config + YAML + precedence rules
**SugarCraft**: Implement early; fits PHP's config ecosystem

### Trend 2: Terminal Capability Detection
**Observation**: Truecolor (v0.10), OSC 8 hyperlinks, color profiles
**Direction**: Progressive enhancement based on terminal capabilities
**SugarCraft**: `candy-palette` provides this; integrate into shell commands

### Trend 3: Horizontal Navigation
**Observation**: Issue #12 (2022) still open; horizontal scroll in PR #791
**Direction**: Long-line handling is a known gap
**SugarCraft**: Implement with left/right arrows; fzf-style truncation

### Trend 4: Streaming Output
**Observation**: Issue #434 (streaming tail) + issue #149 (spin output while running)
**Direction**: Users want real-time output display
**SugarCraft**: PHP's async capabilities could enable this

### Trend 5: Structured Input/Output
**Observation**: JSON/YAML input (discussion #545), file extension filtering (#1012)
**Direction**: Commands become more programmable
**SugarCraft**: Typed input/output is natural in PHP

---

## 23. Strategic Opportunities

### A. Library-First Architecture (Core Differentiator)
**Opportunity**: SugarCraft IS the library that gum refuses to be
**Positioning**: "Use gum-style commands as PHP objects, not shell subprocesses"
**Execution**: Every candy-shell command should be embeddable in any PHP application

### B. Extended Search Implementation
**Opportunity**: Upstream blocked on fuzzy library limitations
**Positioning**: Implement with grapheme-aware algorithm supporting extended syntax
**Execution**: Use `symfony/unicode-string` or similar for proper Unicode handling

### C. Configuration System
**Opportunity**: Upstream just now implementing (PR #1024)
**Positioning**: SugarCraft can have this from day one
**Execution**: PHP-native config (array/YAML) with easy override API

### D. Streaming/Async Patterns
**Opportunity**: Upstream has no streaming; PHP has ReactPHP
**Positioning**: "Real streaming output, not just final result"
**Execution**: SugarSpin with async output buffering

### E. Mouse Support
**Opportunity**: Upstream mentioned but never implemented
**Positioning**: "Full mouse interaction for all interactive commands"
**Execution**: Bubble Tea mouse events; sugar-bits viewport handles this

### F. Horizontal Scrolling
**Opportunity**: Upstream issue #12 open since 2022
**Positioning**: "Handle any line length, no truncation"
**Execution**: sugar-filter and sugar-choose with proper viewport

---

## 24. Cross-Ecosystem Pattern Matches

### Pattern: fzf Extended Search
**Source**: Issue #688
**Match**: Users expect fzf-style `+'prefix` `'exact` `!exclude`
**SugarCraft**: Implement with proper Unicode/grapheme handling

### Pattern: less + F (streaming)
**Source**: Issue #434
**Match**: `less` with `F` key streams new output
**SugarCraft**: SugarPager with real-time output streaming

### Pattern: vim-style Navigation
**Source**: gum choose/filter
**Match**: `hjkl` / arrows for movement
**SugarCraft**: Standard; already in sugar-bits

### Pattern: fzf Position Indicator
**Source**: Discussion #869
**Match**: `[12/45/100]` style indicator
**SugarCraft**: Implement in sugar-filter (shows filtered count too)

### Pattern: fish-shell Auto-layout
**Source**: Issue #966 (padding for join)
**Match**: Fish's layout system with automatic padding
**SugarCraft**: sugar-shine already has padding; extend to join

---

## 25. High ROI Recommendations

### 1. Implement Grapheme-Aware Fuzzy Matching (🔴 IMMEDIATE)
**Why**: Issues #689, #785, #799 all stem from same root
**Implementation**:
- Add `rivo/uniseg` or `symfony/unicode-string` for grapheme iteration
- Implement `bytePosToVisibleCharPos()` for fuzzy highlighting
- Use `lipgloss.StyleRanges()` for rendering

**ROI**: Fixes multiple high-impact bugs; enables emoji in filter items

### 2. Add Horizontal Scrolling to sugar-filter (🔴 IMMEDIATE)
**Why**: Issue #12 blocks real-world use; upstream hasn't fixed since 2022
**Implementation**:
- Detect line width vs terminal width
- Add left/right viewport scrolling
- Match fzf truncation UI (`...` prefix/suffix)

**ROI**: Unblocks `ps axuw | sugar-filter` and similar use cases

### 3. Implement Config File Support (🟡 NEXT)
**Why**: Issue #991 shows demand; PR #1024 shows upstream direction
**Implementation**:
- XDG config paths
- YAML or PHP-native config
- Precedence: config → env → constructor args
- Debug flag for loading diagnostics

**ROI**: Enterprise/team usability; reduces per-command flag repetition

### 4. Fix Terminal Resize Handling (🟡 NEXT)
**Why**: Multiple issues (#1001, #1022, #1025) stem from resize edge cases
**Implementation**:
- Validate all bounds after WindowSizeMsg
- Add panic recovery for bounds issues
- Test with rapid resize sequences

**ROI**: Stability for real-world terminal environments

### 5. Implement Timeout Context Propagation (🟡 NEXT)
**Why**: Issues #260, #618, #997 show recurring timeout problems
**Implementation**:
- Context must wrap actual exec/operation
- Display elapsed time option
- Match error priority: interrupt > timeout > other

**ROI**: Reliable timeout behavior; enables scripted automation

### 6. Build sugar-choose Auto-Select Feature (🟡 NEXT)
**Why**: Issue #990 shows first-time user friction
**Implementation**:
- `--required` flag that auto-selects cursor if nothing selected
- Or `--auto-select` behavior when exactly one match

**ROI**: Reduces user friction; familiar from vim-easyMotion

### 7. Add Fuzzy Find to sugar-file Picker (🟡 FUTURE)
**Why**: Issue #603 open since 2024; community PR exists
**Implementation**:
- `/` key enters fuzzy filter mode
- Filter current directory listing
- Return selected path

**ROI**: Bridges file picker + filter functionality

### 8. Implement Positional Indicators (🟡 FUTURE)
**Why**: Discussion #869; improves navigation feedback
**Implementation**:
- For filter: `[cursor]/[filtered]/[total]`
- For choose: `[cursor]/[total]`
- Update on every cursor/filter change

**ROI**: UX polish; low effort, high impact

---

## Appendix: Key Files Referenced

- `repo_map/charmbracelet_gum.md` — First-stage analysis
- `MATCHUPS.md` — Current library mapping status
- `PROJECT_NAMES.md` — Naming conventions and history
- `AGENTS.md` — Contributor playbook

---

## Appendix: Upstream Issue/PR Quick Reference

| ID | Type | Priority | Status | Topic |
|---|---|---|---|---|
| #12 | issue | 🔴 | open (blocked) | Long line handling |
| #224 | issue | 🟡 | closed | Package as lib |
| #339 | issue | 🟡 | closed | Selection behavior change |
| #434 | issue | 🟡 | open | Streaming output |
| #580 | issue | 🟡 | closed | Crash on spacebar |
| #603 | issue | 🟡 | open | Fuzzy file picker |
| #688 | issue | 🟡 | open (blocked) | Extended search syntax |
| #689 | issue | 🟡 | closed | ANSI color break matching |
| #785 | issue | 🟡 | closed | ANSI garbling |
| #823 | issue | 🟢 | closed | Memory leak in pager |
| #848 | issue | 🟡 | open | Log performance |
| #895 | issue | 🟡 | open | Input prompt doubling |
| #937 | issue | 🟡 | open | Selected delimiter |
| #938 | issue | 🟡 | open | Choose inconsistent Enter |
| #951 | issue | 🟡 | open | Spin elapsed time |
| #970 | issue | 🟡 | open | Write cursor position |
| #990 | issue | 🟡 | open | Auto-select under cursor |
| #991 | issue | 🟢 | open | Config file support |
| #1001 | issue | 🟡 | open | Crash on resize |
| #1022 | issue | 🟡 | open | File terminal clearing |
| #1025 | issue | 🟡 | open | File missing first line |
| #1055 | issue | 🟡 | open | Spin spinner flag |
| #742 | pr | 🟢 | merged | Remove huh dependency |
| #791 | pr | 🟡 | open (blocked) | Horizontal scrolling |
| #799 | pr | 🟢 | merged | Grapheme cluster fix |
| #827 | pr | 🟢 | merged | Pager memory fix |
| #960 | pr | 🟢 | merged | Padding everywhere |
| #1024 | pr | 🟢 | open | Config file support |
