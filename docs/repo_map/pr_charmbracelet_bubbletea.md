# Second-Stage Ecosystem Intelligence Report: charmbracelet/bubbletea

## Metadata
- **Report Date:** 2026-05-27
- **Repository:** charmbracelet/bubbletea (41K stars)
- **Language:** Go
- **Source:** GitHub Issues, Pull Requests, Discussions, Releases

---

## 1. Repository Overview

Bubble Tea is a mature TUI framework (v2.0.0 released February 2026) implementing The Elm Architecture in Go. It powers 25,000+ open-source applications including tools at NVIDIA, GitHub, Slack, Microsoft Azure, and AWS. The v2 release represents a major architectural shift from imperative commands to declarative View properties, with a new Cursed Renderer optimized for performance over SSH and modern terminals.

Key v2 themes:
- **Declarative Views**: All terminal features now declared as View struct fields vs imperative commands
- **Cursed Renderer**: New ncurses-inspired renderer with cell-based delta updates
- **Terminal Capabilities**: Native support for synchronized output (mode 2026), Unicode core (mode 2027), Kitty keyboard protocol
- **Breaking Changes**: Import path changes, key message refactoring, removed program options/commands

---

## 2. Existing SugarCraft Mapping

From the first-stage analysis, SugarCraft maps to Bubble Tea ecosystem as:

| bubbletea | SugarCraft | Status |
|---|---|---|
| `bubbletea` | `candy-core` | Core runtime, Model/Program/Update |
| `lipgloss` | `candy-sprinkles` | Declarative styling |
| `harmonica` | `honey-bounce` | Spring animations |
| `bubblezone` | `candy-zone` | Mouse zone tracking |
| `bubbles` | `sugar-bits` | 14 prebuilt components |
| `huh` | `sugar-prompt` | Interactive forms |
| `glamour` | `candy-shine` | Markdown rendering |
| `colorprofile` | `candy-palette` | Color detection |

---

## 3. Previously Identified Gaps

The first-stage analysis identified these known gaps:
- **Concurrency Model**: Go goroutines vs ReactPHP callbacks
- **Tuple Return Types**: `[Model, Cmd]` becomes `array{0: Model, 1: ?Closure}`
- **No Native Tuples in PHP**
- **Single-threaded PHP** vs Go's lightweight goroutines
- **Complexity for Simple TUIs**: Full Elm pattern overhead for simple scripts

---

## 4. High-Signal Open Issues

### Issue #1655: Proposal: DevTools Inspector (charm-devtools)
**Author:** junhinhow (2026-04-01) | **Reactions:** Active discussion

A community proposal for an official DevTools middleware that wraps any Bubble Tea model and provides an F12-toggleable inspector panel showing:
- Message log (ring buffer of last 100 messages with timestamps)
- State viewer (`fmt.Sprintf("%+v")` dump)
- Component tree (auto-detected via reflection on nested `tea.Model` fields)

**Strategic Note:** This is a direct response to the lack of visibility into running TUI applications. SugarCraft should consider building similar introspection capabilities into candy-core from the start.

### Issue #1654: Proposal: Testing Framework (charm-test)
**Author:** junhinhow (2026-04-01) | **State:** Open

A testing framework proposal providing:
- `Simulator` - drives Model synchronously without goroutines
- Input simulation - `SendKey()`, `Type()`, `Resize()`
- Snapshot testing with golden files
- Deterministic, ANSI-aware assertions

**Existing Counterpart:** The official `github.com/charmbracelet/x/exp/teatest` exists but is marked experimental and has known limitations (consecutive golden calls overwrite, color profile issues).

**Implication for SugarCraft:** This confirms a critical gap in TUI testing tooling. SugarCraft should design its testing infrastructure early, potentially as a first-class citizen rather than an afterthought.

### Issue #1627: Terminal Escape Sequence Leak in Short-Lived Programs
**Author:** nonchan7720 (2026-03-15) | **State:** Closed (completed)

Bubble Tea v2 queries for terminal capabilities (mode 2026, mode 2027) asynchronously during init. In programs that exit quickly, responses arrive after process exit and leak raw escape sequences (`^[[?2026;2$y`) to the shell prompt.

**Root Cause:** No mechanism to cancel/drain pending capability queries on exit.

**Proposed Solutions (not implemented):**
1. `ProgramOption` like `tea.WithoutCapabilityQueries()`
2. Documented way to inhibit probes
3. Attempt to drain pending responses during terminal restoration

**Direct Risk to SugarCraft:** YES - If candy-core queries terminal capabilities asynchronously, same leak could occur. Must handle capability responses synchronously or provide opt-out.

### Issue #1571: Scrollback Lost on tea.Quit (WezTerm-specific)
**Author:** tj-smith47 (2026-01-20) | **State:** Open | **Reactions:** 👍 1

When exiting a TUI on WezTerm, scrollback is corrupted with random characters. Does not occur on kitty. Likely related to alt screen mode handling differences between terminals.

**Direct Risk to SugarCraft:** YES - Terminal restoration after exit is a cross-platform concern. Must properly restore terminal state regardless of duration.

### Issue #1522: Nested Model Fails to Exit/Switch State via Custom Command
**Author:** WhipMeHarder (2025-10-25) | **State:** Open

Complex nested model architecture experiences reliability issues when sub-models try to signal parent to switch state. The user found a workaround: **stacking multiple case arguments in switch statements** rather than using fallthrough.

```go
// WORKAROUND: Use multiple arguments in single case
case "vote_results", "view_assets":
    if (actionKey == "vote_results") { ... }
    if (actionKey == "view_assets") { ... }
```

**Root Cause Investigation:** The issue traced to switch statement handling in Update function, specifically when more than ~12-15 sub-models are involved. The `tea.Model` interface doesn't guarantee any particular message routing behavior.

**Strategic Note:** This reveals that the Elm Architecture's "messages flow down" metaphor breaks down when child models need to communicate state changes back to parents. SugarCraft should provide clear patterns for:
1. Parent-child message routing
2. State synchronization between models
3. Model composition patterns

### Issue #775: Viewport SoftWrap Scrolling Broken
**Author:** kamiheku (2025-04-10) | **State:** Open

When a single logical line wraps to 6 visual lines with `SoftWrap` enabled, the user must press `j` 5 times to achieve any scrolling. The viewport scrolls by visual lines, not logical lines.

**Expected:** Pressing `j` once should immediately scroll the topmost wrapped line out of view.

---

## 5. Important Closed Issues

### Issue #1395: v2 Render Position Shifted
**Author:** rsteube | **State:** Closed (reverted)

After running v2, rendering position shifted down one line. This was traced to intentional behavior in v2-exp that was later reverted after community feedback.

**Lesson:** The v2 experiment revealed that changing rendering position behavior breaks user expectations. SugarCraft should maintain consistent cursor positioning on exit.

### Issue #1167: Windows First Character Lost on Successive Programs
**Author:** jtackaberry (2024-09-24) | **State:** Closed (completed)

Windows-specific issue where running multiple Tea programs in succession within the same process causes first keypress to be silently eaten. Root cause was `CancelIo` not properly unblocking reads across threads.

**Fix Required:** `CancelIoEx` instead of `CancelIo` on Windows.

**Direct Risk to SugarCraft:** YES - Windows input handling is notoriously tricky. Any PTY/TTY implementation must handle cancellation properly across threads.

### Issue #831: Textarea Slow When Pasting Clipboard Content
**Author:** cruizba (2023-10-04) | **State:** Closed (fixed)

Textarea performance was dominated by `wrap()` calls during cursor position calculation. Profiling revealed:
- `go-runewidth.StringWidth` was 3x slower than `uniseg` equivalent
- Memoization cache was added to improve performance

**Performance Fix Pattern:**
```go
// Memoization cache for wrap() results
// Swap go-runewidth for uniseg package
// Benchmark improvement: 325152 ns/op → 101341 ns/op (3x faster)
```

**Direct Risk to SugarCraft:** YES - Any text input component (sugar-bits textinput) must memoize expensive operations like width calculation.

### Issue #1673: Signal Channel Leak in suspendProcess()
**Author:** kuishou68 (2026-04-13) | **State:** Closed (fixed)

`suspendProcess()` registers a channel with `signal.Notify` but never calls `signal.Stop` to deregister it. Each Ctrl+Z suspend/resume cycle leaks a channel.

**Pattern to Follow:**
```go
func suspendProcess() {
    c := make(chan os.Signal, 1)
    signal.Notify(c, syscall.SIGCONT)
    defer signal.Stop(c)  // MISSING in original
    _ = syscall.Kill(0, syscall.SIGTSTP)
    <-c
}
```

**Direct Risk to SugarCraft:** YES - Signal handling in candy-pty must properly deregister channels to prevent leaks.

### Issue #1520: Carriage Return Rendering Issues
**Author:** (linked from PR #1523) | **State:** Closed (fixed)

Carriage return (`\r`) characters in frames caused rendering issues. Fixed by stripping them before rendering.

**Direct Risk to SugarCraft:** YES - Rendering pipeline must sanitize control characters.

### Issue #1599: Data Race Between cancelreader.Close() and cancelreader.wait()
**Author:** julez-dev (2026-02-26) | **State:** Open

Running with `-race` reveals a data race during shutdown. The inner goroutine in `ultraviolet.StreamEvents()` doesn't have a happens-before relationship with the cleanup sequence.

**Race Condition:**
1. `shutdown()` calls `p.cancel()` - context cancelled
2. `StreamEvents` returns on `ctx.Done()` - inner goroutine still blocked
3. `cancelReader.Cancel()` wakes inner goroutine
4. `cancelReader.Close()` begins closing while goroutine still accesses `Fd()`

**Direct Risk to SugarCraft:** YES - Concurrency between reader cancellation and cleanup is a common source of races. Must ensure proper synchronization.

### Issue #1471: View Wraps on macOS Terminal.app Despite Calculating Widths
**Author:** | **State:** Closed (fixed)

Cursor position reset was using backward cursor movement by terminal width, which fails when renderer doesn't know terminal width yet.

**Fix:** Use carriage return (`\r`) instead of moving cursor backward by width.

**Direct Risk to SugarCraft:** YES - Cursor positioning during render must be robust to unknown dimensions.

### Issue #1690: Data Race Between Mouse Events and Cursed Renderer
**Author:** lrstanley (2026-05-04) | **State:** Open

`cursedRenderer.lastView` is written in `flush()` (with mutex) but read in `onMouse()` **without** acquiring the mutex, causing a data race when mouse events arrive during rendering.

**Race:**
```go
// flush() - correct
s.mu.Lock()
s.lastView = &view  // WRITE
s.mu.Unlock()

// onMouse() - BUG: no lock
if s.lastView != nil && s.lastView.OnMouse != nil {  // READ without lock
    return s.lastView.OnMouse(m)
}
```

**Direct Risk to SugarCraft:** YES - Any shared state between render loop and input handlers must be protected by mutexes.

---

## 6. Recurring Pain Points

### 6.1 Multi-Model Message Routing

**Discussion #751** (long-running) reveals the fundamental tension in Elm Architecture for complex applications:

> "I have an application that displays multiple models at once. What I want to do is deliver the message to the same model that sent the message."

**The Problem:** Messages flow down from parent to child, but returning messages from child to parent requires manual routing in the parent's `Update` function.

**User's Desired Pattern:**
```go
// Intercept model commands and annotate their messages with return route
// Then route messages back to the correct destination
```

**Why It's Hard:** `sequenceMsg` is private, preventing users from implementing custom routing. `BatchMsg` is public but `sequenceMsg` is not.

**Maintainer Guidance:**
> "So technically speaking the 'canonical' solve for this would be to add some logic in your top level update to route messages to the correct children, and altering them if necessary along the way as data in The Elm Architecture naturally flows down."

**SugarCraft Opportunity:** Provide a first-class composition system that handles parent-child message routing automatically. Consider:
- Tagged messages with source/destination
- A `Router` or `Coordinator` pattern
- Public `Sequence` equivalent (if private in Go, PHP can make it public)

### 6.2 Sequence Cmd Infinite Loop

**Issue #1494** (linked from PR #958) shows that `tea.Sequence` can cause infinite loops with 100% CPU when commands contain only nil returns.

**Root Cause:** `Sequence` didn't handle the "all nils" case like `Batch` does.

**Community Finding:**
> "Swapping in `tea.Batch` for `tea.Sequence` fixed the CPU usage"

**Pattern to Implement in SugarCraft:** Both `Batch` and `Sequence` must handle:
1. Empty command lists
2. All-nil commands
3. Single command optimization

### 6.3 Windows Input Handling Complexity

Multiple Windows-specific issues:
- **#1313:** Can't select text when inside program (mouse mode enabled even when not requested)
- **#1391:** Mouse doesn't work in AltScreen on Windows
- **#923:** External process loses first character of input

**Root Cause:** Windows console API differs significantly from Unix. The cancelreader pattern that works on Unix doesn't translate directly.

**Maintainer Effort:** Significant engineering went into Windows input overhaul (PR #878), including support for Windows Console API input events.

**SugarCraft Risk:** HIGH - Windows support requires dedicated platform-specific code. PHP's stream-based I/O may mask these issues or make them harder to debug.

### 6.4 Timer/Command Cleanup

**Issue #993** (PR) revealed that timers were not being stopped/drained properly, causing memory leaks and double-firing of commands.

**Pattern to Follow:**
```go
// Timers must be stopped AND drained to prevent:
// 1. Memory leaks from abandoned timers
// 2. Commands firing twice
// 3. RAM bloat in long-running apps
```

**SugarCraft Implication:** Any timer/subscription system must:
1. Track active timers
2. Stop them on shutdown
3. Drain pending events

---

## 7. Frequently Requested Features

### 7.1 First-Class Testing Infrastructure

**Status:** Multiple competing efforts
- Official: `github.com/charmbracelet/x/exp/teatest` (experimental)
- Community: `knz/catwalk` (datadriven test framework)
- Community: `junhinhow/charm-test` (simulator-based, newest)

**Community Ask (Discussion #1528):**
> "I find it strange that it is rare or non existent to find good tools for testing CLI apps or TUI apps... I would like to suggest the creators of Bubble Tea to create a Go package that is the equivalent of Microsoft tui-test but in Go."

**Key Features Requested:**
1. Terminal input simulation (`stdin`)
2. Output capture (`stdout`/`stderr`)
3. TUI interaction (buttons, checkboxes)
4. Snapshot testing
5. Cross-platform (works on all shells/devices)
6. Can test compiled apps in any language

**SugarCraft Recommendation:** PRIORITY 1 - Design testing infrastructure from day one. Provide:
- `Program::withInput()` / `Program::withOutput()` for I/O redirection
- `Program::withoutRenderer()` for headless testing
- Snapshot testing utilities
- Message injection helpers

### 7.2 DevTools / Introspection

**Proposal #1655** requests:
- Message flow visualization
- Model state inspection
- Component hierarchy tree

**Existing Workaround:** `tea.LogToFile()` for debug logging, but no interactive inspection.

**SugarCraft Recommendation:** Consider building a web-based inspector (like React DevTools) that can connect to a running TUI via a Unix socket or HTTP.

### 7.3 Text Selection with Mouse Enabled

**Issue #162** (open since 2021, 14 reactions): When mouse mode is enabled, native text selection is disabled. This is a terminal limitation, but tmux and Claude Code work around it.

**Maintainer Response:**
> "The plan is to implement text selection in Bubble Tea to overcome this limitation (similar to how it's done in tmux)"

**Progress:** No implementation yet, though Crush (Charm's AI coding agent) has custom text selection.

**SugarCraft Recommendation:** Consider this a v2 feature. Implement inline text selection component for when mouse is active.

### 7.4 Multi-Model Composition Primitives

**Discussion #751** and **Issue #371** both request better primitives for combining multiple components:

> "Would love to have this built into the bubble models. Right now I declare wrappers around every component type I want to use just to add an Init() and an Update that returns a tea.Model"

**Current Gap:** Bubbles components don't implement `tea.Model` interface directly.

**SugarCraft Recommendation:** sugar-bits components MUST implement the `SugarCraft\Core\Model` interface directly. No wrapper required.

---

## 8. Important PRs

### PR #1500: Declarative View API (v2)
**Author:** aymanbagabas | **Status:** Merged (v2.0.0)

Transforms Bubble Tea from imperative to declarative:

```go
// BEFORE (v1): Imperative commands
p := tea.NewProgram(m,
    tea.WithAltScreen(),
    tea.WithMouseCellMotion(),
)
return m, tea.EnterAltScreen

// AFTER (v2): Declarative View
func (m Model) View() tea.View {
    return tea.NewView(content).
        WithAltScreen(true).
        WithMouseMode(tea.MouseModeCellMotion)
}
```

**Breaking Changes:**
- `View() string` → `View() tea.View`
- All `tea.WithX()` options → `View.X` fields
- All `tea.EnterAltScreen` etc. commands → `View.AltScreen` field

**SugarCraft Recommendation:** The declarative approach is superior. candy-core should follow v2's lead - make View properties the single source of truth for terminal state.

### PR #1568: Combine Event and Render Loops
**Author:** aymanbagabas | **Status:** Merged

Merges event handling and rendering into a single loop for improved performance and reduced complexity.

**SugarCraft Consideration:** PHP's event-driven model may benefit from similar consolidation.

### PR #1499: Terminal Progress Bars
**Author:** aymanbagabas | **Status:** Merged

Adds support for terminal-native progress bars (Windows Terminal, Ghostty).

**SugarCraft Status:** Already mapped in sugar-bits as `View::progressBar`.

### PR #958: Fix Sequence/Empty Commands
**Author:** jdhenke | **Status:** Merged

Makes `Sequence` handle empty/all-nil commands like `Batch` does, preventing 100% CPU infinite loops.

**SugarCraft Pattern:**
```php
// Both must handle edge cases
public static function batch(Closure ...$cmds): Closure { ... }
public static function sequence(Closure ...$cmds): Closure { ... }
// Handle: [], [null], [null, null], [cmd, null, cmd]
```

### PR #1340: Windows Mouse Mode on Demand
**Author:** aymanbagabas | **Status:** Merged

Fixes regression where mouse mode was always enabled on Windows, breaking text selection. Now enabled only when program requests it.

**SugarCraft Consideration:** Mouse mode activation must be explicit, not automatic.

---

## 9. Architectural Changes

### v2 Module Path Change
```
github.com/charmbracelet/bubbletea/v2 → charm.land/bubbletea/v2
```

**Implication:** This is a vanity domain redirect, but signals the project's evolution beyond GitHub org.

### Message Type Changes

| v1 | v2 | Change Type |
|---|---|---|
| `tea.KeyMsg` (struct) | `tea.KeyMsg` (interface) | Breaking |
| `tea.MouseMsg` (struct) | `tea.MouseMsg` (interface) | Breaking |
| `msg.Type` | `msg.Code` | Field rename |
| `msg.Runes` | `msg.Text` (now string not []rune) | Breaking |
| `msg.Alt` | removed | Breaking |
| Space key `" "` | `"space"` | Breaking |

**SugarCraft Approach:** PHP's type system doesn't have the same ergonomics, but we should:
1. Use interfaces for Msg types
2. Keep field names consistent with upstream
3. Document breaking differences

### Renderer Architecture

The v2 Cursed Renderer is a complete rewrite modeled on ncurses:
- Cell-based buffer tracking
- Delta rendering (only changed cells sent)
- Synchronized output mode (ANSI 2026)
- Debounced resize handling

**SugarCraft Implication:** Rendering is where most complexity lives. candy-core's renderer must be carefully designed.

---

## 10. Performance Discussions

### Textarea Performance (Issue #831)

**Problem:** Pasting large text into textarea caused severe lag.

**Root Cause:** `wrap()` called repeatedly in cursor position calculation. `StringWidth` from `go-runewidth` was 3x slower than `uniseg`.

**Solution Patterns:**
1. Memoization cache for expensive computations
2. Profiling before optimization
3. Package selection (`uniseg` vs `go-runewidth`)

**SugarCraft Recommendation:** Implement LRU cache for text width calculations. Profile before optimizing.

### Performance Best Practices (from docs)

| Technique | Impact |
|---|---|
| Minimize View complexity | Cache expensive operations |
| Cache rendered content | Mark dirty flags, rebuild only when needed |
| Reduce update frequency | Throttle high-frequency updates |
| Appropriate FPS | 30 FPS sufficient for status displays |

**SugarCraft Consideration:** Implement `WithFPS()` option to allow throttling.

### Synchronized Output (Mode 2026)

Reduces tearing and cursor flickering by atomically updating the terminal. Enabled by default in v2.

**SugarCraft Status:** Terminal mode 2026 support should be part of candy-palette or candy-vt.

---

## 11. Extensibility Discussions

### Model Interface Should Be Universal

**Issue #371:** Bubbles components don't implement `tea.Model`, requiring wrapper types:

```go
// Current (tedious)
type myModel struct {
    textinput.Model  // must wrap to add Init()
}
func (m myModel) Init() tea.Cmd { return m.textinput.Init() }
func (m myModel) Update(msg tea.Msg) (tea.Model, tea.Cmd) {
    m.textinput, cmd = m.textinput.Update(msg)
    return m, cmd
}
```

**Requested Pattern:**
```go
// Desired (all components just work)
type myModel struct {
    textinput.Model  // already implements tea.Model
}
```

**SugarCraft Requirement:** All sugar-bits components MUST implement `SugarCraft\Core\Model` interface directly. No wrappers.

### View Callback Refactoring

v2 changed from general `View.Callback` to specific `View.OnMouse`:

```go
// v1: General callback
View.Callback func(Msg) Cmd

// v2: Specific handler
View.OnMouse func(MouseMsg) Cmd
```

**Rationale:** More type-safe, better separation of concerns.

**SugarCraft Pattern:** Follow v2's approach - specific handlers per concern.

### Filter/Interceptor Pattern

The `WithFilter` option allows intercepting messages before `Update`:

```go
tea.NewProgram(m,
    tea.WithFilter(func(model Model, msg Msg) Msg {
        // Transform or block messages
        return msg
    }),
)
```

**Use Cases:**
- Message validation
- Logging
- Rate limiting
- Conditional blocking

**SugarCraft Consideration:** Implement as middleware/decorator pattern.

---

## 12. API/UX Complaints

### v2 Migration Complexity

The v2 upgrade guide is 200+ lines covering:
- Import path changes
- View signature changes
- Key message field changes
- Mouse message type changes
- Program option removals
- Command removals

**Community Feedback:** Multiple issues (#1395, #1471, #1571) filed during beta were about migration confusion.

**SugarCraft Principle:** Avoid breaking changes until v1.0. After that, provide migration guides and codemods.

### Message Type Surprises

1. **`tea.KeyMsg` is now an interface** - requires `tea.KeyPressMsg` for most cases
2. **Space key changed** - returns `"space"` instead of `" "`
3. **Mouse events split** - `MouseMsg` is now `MouseClickMsg`, `MouseReleaseMsg`, etc.

**SugarCraft Approach:** Use plain PHP types (strings, arrays) rather than classes/interfaces where possible to reduce cognitive load.

### Example Quality Issues

**Issue #1337:** Autocomplete example didn't work on macOS due to confusing UX:
- No visible feedback when no matches
- Keybindings hidden when input empty

**Maintainer Response:**
> "It's a pretty confusing example (which we'll fix). When there's no text entered the keybindings should be hidden."

**SugarCraft Principle:** Examples must work out-of-the-box and provide clear feedback for all states.

---

## 13. Migration Problems

### v1 → v2 Breaking Changes Summary

**Program Options Removed:**
| Old | Replacement |
|---|---|
| `WithAltScreen()` | `View.AltScreen = true` |
| `WithMouseCellMotion()` | `View.MouseMode = MouseModeCellMotion` |
| `WithReportFocus()` | `View.ReportFocus = true` |
| `WithoutBracketedPaste()` | `View.DisableBracketedPasteMode = true` |
| `WithInputTTY()` | Remove entirely (automatic) |
| `WithANSICompressor()` | Remove (automatic) |

**Commands Removed:**
| Old | Replacement |
|---|---|
| `EnterAltScreen`/`ExitAltScreen` | `View.AltScreen` |
| `SetBackgroundColor()` | `View.BackgroundColor` |
| `SetWindowTitle()` | `View.WindowTitle` |
| `EnableMouseCellMotion` | `View.MouseMode` |

**Program Methods Removed:**
- `tea.WindowSize()` → `tea.RequestWindowSize`

**SugarCraft Warning:** Once we reach v1.0, avoid these kinds of sweeping changes. Use deprecation warnings first.

### Import Path Changes

All v2 packages moved from `github.com/charmbracelet/*` to `charm.land/*`:
- `github.com/charmbracelet/bubbletea/v2` → `charm.land/bubbletea/v2`
- `github.com/charmbracelet/lipgloss/v2` → `charm.land/lipgloss/v2`

**SugarCraft Note:** Module naming matters. Choose a stable vanity domain early.

---

## 14. Clever Fixes & Workarounds

### Workaround: Stacking Switch Cases

**Issue #1522:** When using many sub-models (>12-15), switch statements with single case arguments fail to match properly.

**User-Discovered Fix:**
```go
// FAILING pattern
case "vote_results":
    m.activeContentType = "vote_results"
    ...

// WORKING pattern
case "vote_results", "view_assets":
    if (actionKey == "vote_results") {
        ...
    }
    if (actionKey == "view_assets") {
        ...
    }
```

**SugarCraft Note:** Document this edge case. Possibly a compiler/interpreter bug in Go's switch statement handling.

### Workaround: External Border Rendering

**Issue #1643:** Double border when textarea has placeholder and Base style has border.

**User-Provided Workaround:**
```go
// Don't put border on Base style
styles.Focused.Base = lipgloss.NewStyle()

// Wrap output yourself
borderStyle := lipgloss.NewStyle().
    Border(lipgloss.RoundedBorder()).
    BorderForeground(lipgloss.Color("63")).
    Padding(0, 1)

borderStyle.Render(m.ta.View())
```

**SugarCraft Implication:** Provide clear documentation when built-in styles interact unexpectedly.

### Workaround: Use Batch Instead of Sequence

When `Sequence` causes infinite loops or 100% CPU, swapping to `Batch` fixes it (at cost of losing ordering guarantee).

**SugarCraft Implementation:** `Sequence` should handle the same edge cases as `Batch`.

### External Workaround: Drain Capability Responses

**Issue #1627:** Terminal capability queries leak escape sequences on short-lived programs.

**Community Workaround:** Third-party fix in `gh-infra` project involves draining pending responses.

**SugarCraft Recommendation:** Handle capability queries synchronously or provide opt-out.

---

## 15. Community Workarounds

### Third-Party Testing Tools

1. **knz/catwalk** - datadriven test framework using test files
2. **junhinhow/charm-test** - simulator-based testing
3. **github.com/gkampitakis/go-snaps** - snapshot testing patterns

### Community Mouse State Fixes

Multiple issues about mouse state not being restored after `ExecProcess`. Community has patched locally.

**SugarCraft Recommendation:** Ensure `Exec`/`ExecProcess` saves and restores ALL terminal state (mouse mode, bracketed paste, focus reporting, alt screen).

### LRU Memoization for Text Operations

**Issue #831:** Users implemented their own memoization for text width calculations.

```go
// User's LRU cache for wrap() results
// github.com/go-go-golems/clay/blob/main/pkg/memoization/memoization.go
```

**SugarCraft Recommendation:** Bundle memoization utilities.

---

## 16. Maintainer Guidance Patterns

### "It's a Limitation of the Terminal"

Used for:
- Native text selection when mouse enabled (Issue #162)
- Mouse events beyond column 95 on Windows (Issue #359)

**Tone:** Honest acknowledgment but suggests future workarounds.

### "We'll Fix the UX"

Used when examples are confusing or have poor feedback.

**Pattern:** Acknowledge problem quickly, prioritize fix.

### "This Is Intentional, But..."

Used when behavior changed but breaks existing usage (Issue #1395).

**Response:** Revert if community pushback is strong.

### "Use the Experimental Package"

For testing framework (`teatest`), redirect to `github.com/charmbracelet/x/exp/teatest`.

**Implication:** Some features are explicitly marked as not ready for production.

### "We'll Look Into It" (No Timeline)

For complex features like text selection with mouse enabled.

**SugarCraft Approach:** Be explicit about roadmap priorities rather than indefinite deferral.

---

## 17. Rejected Ideas Worth Revisiting

### 1. Public sequenceMsg

**Discussion #751:** Users wanted `sequenceMsg` made public like `BatchMsg` to enable custom routing. Maintainer acknowledged it's a reasonable feature request but hasn't prioritized it.

**SugarCraft Opportunity:** Since PHP doesn't have private types, we CAN make routing primitives public and well-documented.

### 2. Built-In Text Selection (tmux-style)

**Issue #162:** Request for Bubble Tea to implement its own text selection when mouse is enabled, like tmux does.

**Status:** Acknowledged as "plan" but not implemented.

**SugarCraft Opportunity:** Could be a differentiator. Consider implementing a text selection component for mouse-enabled contexts.

### 3. Comprehensive Testing Framework

**Discussion #1528:** User requested an official testing package.

**Status:** Experimental package exists, but no first-class solution.

**SugarCraft Opportunity:** Building a robust testing framework could be a key differentiator, especially if it has better DX than Go's teatest.

### 4. Middleware/Interceptor Pattern

**Issue:** No way to intercept message flow globally except `WithFilter`.

**Discussion #751:**
> "intercept model commands and annotate their messages with return route"

**SugarCraft Opportunity:** Implement a proper middleware system for message interception.

---

## 18. Problems Likely Relevant To SugarCraft

### HIGH RISK - Direct Mapping

1. **Terminal Escape Sequence Leak** (#1627)
   - Asynchronous capability queries leak on short-lived programs
   - Must handle capability detection synchronously or provide opt-out

2. **Windows Input Handling** (#1167, #923, #1313, #1340)
   - First character lost on successive programs
   - External process loses input characters
   - Mouse mode enabled even when not requested
   - Requires deep platform-specific code

3. **Signal Channel Leak** (#1673)
   - `signal.Notify` without `signal.Stop` causes leaks
   - Must properly deregister signal handlers

4. **Data Races in Renderer** (#1690, #1599)
   - `lastView` read without mutex in `onMouse`
   - Race between `cancelreader.Close()` and `wait()`
   - All shared state must be protected

5. **Textarea Performance** (#831)
   - Expensive width calculations on every input
   - Requires memoization

6. **Multi-Model Message Routing** (#751, #1522)
   - No good pattern for parent-child communication
   - Nested models have reliability issues
   - Need explicit composition primitives

### MEDIUM RISK - Architectural

7. **Sequence vs Batch Edge Cases** (#958)
   - Empty/all-nil commands cause issues
   - Must handle same edge cases

8. **Cursor Positioning on Unknown Width** (#1471)
   - Can't use width-based cursor reset before dimensions known
   - Use `\r` instead

9. **Render Position After Exit** (#1395)
   - Cursor position can shift after program exit
   - Must restore prompt position

10. **Timer/Command Cleanup** (#993)
    - Timers not stopped/drained on shutdown
    - Causes memory leaks and double-firing

### LOW RISK - Ergonomics

11. **Testing Infrastructure Gap**
    - No first-class testing solution
    - Design early

12. **DevTools Absence** (#1655)
    - No way to inspect running application
    - Consider web-based inspector

---

## 19. Features SugarCraft Should Consider

### Priority 1 (Foundational)

1. **First-Class Testing Infrastructure**
   - `Program::withInput()` / `Program::withOutput()`
   - `Program::withoutRenderer()` for headless
   - Snapshot testing utilities
   - Message injection helpers

2. **Proper Signal Handling**
   - `signal.Stop()` on all registered channels
   - Proper cleanup on shutdown
   - No orphaned goroutines/channels

3. **Timer/Subscription Lifecycle**
   - Track active timers
   - Stop and drain on shutdown
   - No memory leaks

4. **Mutex-Protected Shared State**
   - All renderer state accessed under mutex
   - No data races between input and render loops

### Priority 2 (Composition)

5. **Multi-Model Composition Primitives**
   - Parent-child message routing
   - Model composition without wrappers
   - Clear patterns for nested architectures

6. **Synchronous Capability Queries** (or opt-out)
   - Don't leak escape sequences on short-lived programs
   - Provide `WithoutCapabilityQueries()` option

7. **Full Terminal State Save/Restore**
   - Mouse mode
   - Bracketed paste
   - Focus reporting
   - Alt screen
   - Cursor style (via DECRQSS)

### Priority 3 (Differentiators)

8. **Web-Based DevTools Inspector**
   - Message flow visualization
   - Model state inspection
   - Component hierarchy

9. **Text Selection Component**
   - When mouse is enabled
   - tmux-like selection mode

10. **LRU Memoization Utilities**
    - Bundled with sugar-bits
    - For text width calculations

---

## 20. Architectural Lessons

### Lesson 1: Declarative > Imperative

v2's shift from imperative commands to declarative View properties eliminates:
- Race conditions between commands and rendering
- State synchronization complexity
- Unexpected timing issues

**SugarCraft Design:** Make View struct the single source of truth for all terminal state. Never use imperative commands for visual properties.

### Lesson 2: Concurrency Complexity Is Fatal

Issues like #1599 and #1690 show that concurrency bugs (data races) can hide in production for years. Only exposed by race detectors.

**SugarCraft Design:** 
- Use PHP's single-threaded model to advantage (no goroutine races)
- But be careful with ReactPHP async operations
- Run tests with strict mode

### Lesson 3: Platform Differences Are Expensive

Windows handling consumed significant engineering time (#878, #1313, #1340, #1391).

**SugarCraft Design:** 
- Minimize platform-specific code paths
- Use established libraries (ReactPHP) for cross-platform consistency
- Document platform limitations clearly

### Lesson 4: Testing Must Be Designed In

The lack of testing infrastructure is a known gap after 6+ years.

**SugarCraft Design:** Design testing infrastructure before v1.0. Make every component testable in isolation.

### Lesson 5: Elm Architecture Has Limits

Multi-model composition reveals that "messages flow down" doesn't scale to complex UIs.

**SugarCraft Design:** Provide explicit patterns for:
- Parent-child communication
- Model coordination
- State synchronization

Consider something more flexible than pure Elm for complex cases.

---

## 21. Defensive Design Lessons

### Hardening Principles

1. **Never Assume Synchronous Completion**
   - Capability queries can arrive after process exit
   - Always drain or disable queries in short-lived programs

2. **Always Protect Shared State**
   - Any state accessed by both input and render loops needs mutex
   - Use `defer` for unlock to prevent missing unlocks

3. **Validate Before Use**
   - Message types from external sources (terminal) must be validated
   - Don't assume well-formed input

4. **Fail Safe on Resource Cleanup**
   - If cleanup might fail, log and continue
   - Never leave terminal in broken state

5. **Defensive Timer Management**
   - Track all active timers
   - Stop ALL timers on shutdown
   - Drain any pending events

### Code Patterns to Emulate

```go
// PATTERN: Proper signal handler cleanup
func suspendProcess() {
    c := make(chan os.Signal, 1)
    signal.Notify(c, syscall.SIGCONT)
    defer signal.Stop(c)  // ALWAYS deregister
    _ = syscall.Kill(0, syscall.SIGTSTP)
    <-c
}

// PATTERN: Protected shared state
func (s *cursedRenderer) onMouse(m MouseMsg) Cmd {
    s.mu.Lock()
    defer s.mu.Unlock()  // ALWAYS hold lock when reading lastView
    if s.lastView != nil && s.lastView.OnMouse != nil {
        return s.lastView.OnMouse(m)
    }
    return nil
}

// PATTERN: Timer cleanup
func (p *Program) shutdown() {
    for _, t := range p.timers {
        t.Stop()
        <-t.C  // Drain
    }
}
```

### Code Patterns to Avoid

```go
// AVOID: Orphaned signal registration
func brokenSuspendProcess() {
    c := make(chan os.Signal, 1)
    signal.Notify(c, syscall.SIGCONT)
    // Missing defer signal.Stop(c)!
    _ = syscall.Kill(0, syscall.SIGTSTP)
    <-c
}

// AVOID: Unprotected shared state
func (s *cursedRenderer) onMouse(m MouseMsg) Cmd {
    // No mutex! Data race with flush()
    if s.lastView != nil && s.lastView.OnMouse != nil {
        return s.lastView.OnMouse(m)
    }
    return nil
}

// AVOID: Unchecked timer creation
func brokenTick(d time.Duration) {
    ticker := time.NewTicker(d)
    // Never stored, never stopped, leaks!
}
```

---

## 22. Ecosystem Trends

### AI Coding Agents Driving TUI Revival

The announcement explicitly mentions AI agents as a driver for v2:
> "AI agents moved into the terminal... The terminal, which was previously somewhat of a niche preference, became a primary platform"

**Implication:** TUI frameworks are now competing for AI agent integration (Crush, Claude Code terminal mode). SugarCraft should consider AI agent use cases.

### Performance Over SSH

v2's Cursed Renderer optimization was motivated by:
> "For applications running over SSH, the changes are monetarily quantifiable."

**Implication:** Terminal rendering optimization is economically significant for cloud/remote workflows.

### Modern Terminal Capabilities

New terminal features being embraced:
- Synchronized output (mode 2026)
- Unicode core (mode 2027)
- Kitty keyboard protocol
- SGR extended mouse
- Terminal progress bars

**Implication:** SugarCraft should detect and degrade gracefully when these aren't available.

### Declarative Everything

v2's declarative View approach is part of a broader trend:
- React's declarative UI
- Terraform's declarative infrastructure
- Preference for "describe what, not how"

**SugarCraft Alignment:** Good fit - PHP's array-based config maps well to declarative patterns.

---

## 23. Strategic Opportunities

### For SugarCraft vs Bubble Tea

1. **First-Class Testing** - Build testing infrastructure from day one, not as afterthought
2. **Simpler Concurrency** - PHP's single-threaded model eliminates goroutine races
3. **Composition Primitives** - Design multi-model composition before complex apps break
4. **DevTools** - Consider web-based inspector as differentiator
5. **Synchronous by Default** - Avoid async capability queries that leak

### Areas to Differentiate

| Bubble Tea Pain Point | SugarCraft Opportunity |
|---|---|
| No official testing framework | First-class testing with snapshots |
| Concurrency races | Simpler async model via ReactPHP |
| Complex Windows handling | Cross-platform via PHP streams |
| Private sequenceMsg | Public, documented composition |
| Complex v2 migration | Incremental, backward-compatible changes |

### Areas to Learn From

| Bubble Tea Strength | SugarCraft Approach |
|---|---|
| Battle-tested renderer | Use existing terminal libraries |
| Excellent examples | Provide comprehensive examples |
| Community tooling (teatest) | Consider contributing to or wrapping |
| Performance optimization | Profile before optimizing |

---

## 24. Cross-Ecosystem Pattern Matches

### React DevTools → SugarCraft DevTools

The proposal for charm-devtools mirrors React's inspector:
- Message log = React's action history
- State viewer = React's component inspector
- Component tree = React's component hierarchy

**SugarCraft Recommendation:** Build similar inspector as separate package that wraps any candy-core program.

### Jest/Vitest Snapshot Testing → SugarCraft Snapshots

The `CHARM_TEST_UPDATE=1` pattern for golden file updates matches Jest's `--updateSnapshot`.

**SugarCraft Implementation:**
```php
// Snapshot testing
public function testView(): void {
    $sim = new Simulator(new MyModel());
    $sim->sendKey('enter');
    
    // First run creates golden file
    // Subsequent runs compare
    Snapshot::assert($sim, 'expected-view.txt');
    
    // Update with UPDATE_SNAPSHOTS=1
}
```

### tmux Selection Mode → SugarCraft Selection

tmux implements text selection when mouse is enabled. Same approach needed for SugarCraft.

### Go's `defer` Pattern → PHP `finally`

Bubble Tea's `defer signal.Stop(c)` pattern should map to PHP's `finally`:

```php
// PHP equivalent of proper signal cleanup
function suspendProcess(): void {
    $ch = [SIGCONT];
    pcntl_signal($ch, function() {});
    try {
        posix_kill(0, SIGTSTP);
        pcntl_signal_dispatch();
    } finally {
        pcntl_signal(SIGCONT, SIG_DFL);
    }
}
```

---

## 25. High ROI Recommendations

### Immediate (Do First)

1. **Design Testing Infrastructure**
   - `Program::withInput()` / `withOutput()` for I/O redirection
   - `Program::withoutRenderer()` for headless testing
   - `Snapshot` class for golden file testing
   - `Simulator` class for deterministic input simulation

2. **Implement Proper Signal Cleanup**
   - All `signal.Notify` calls must have `defer signal.Stop`
   - Timer cleanup on shutdown
   - No orphaned resources

3. **Mutex-Protect Renderer State**
   - Any state shared between input and render loops needs mutex
   - Verify with async stress testing

### Short-Term (v0.x)

4. **Add Composition Primitives**
   - Public `Sequence` and `Batch` with proper edge case handling
   - Model router/coordinator for nested models
   - Document parent-child messaging patterns

5. **Synchronous Capability Detection** (or opt-out)
   - Provide `WithoutCapabilityQueries()` option
   - Or detect synchronously without async queries
   - Don't leak escape sequences on short-lived programs

6. **Full Terminal State Save/Restore**
   - Save/restore mouse mode, bracketed paste, focus, alt screen
   - Consider cursor style via DECRQSS

### Medium-Term (v1.0)

7. **Web-Based DevTools**
   - Separate package wrapping any candy-core program
   - Inspect message flow, state, component hierarchy
   - Toggle with key combination or signal

8. **Text Selection Component**
   - When mouse is enabled
   - Fallback for terminals that need it
   - Consider as sugar-bits component

9. **Documentation & Examples**
   - Every example works out-of-box
   - Clear feedback for all states
   - Migrate guide for v2 when applicable

### Long-Term (Post-v1.0)

10. **AI Agent Integration**
    - Consider AI coding agent use cases
    - Performance over SSH
    - Structured output capabilities

---

## Appendix: Key Issue Reference

| Issue | Title | Priority | Status |
|---|---|---|---|
| #1655 | DevTools proposal | HIGH | Open |
| #1654 | Testing framework proposal | HIGH | Open |
| #1627 | Escape sequence leak | HIGH | Closed |
| #1571 | Scrollback lost on exit | MEDIUM | Open |
| #1522 | Nested model fails | HIGH | Open |
| #1690 | Data race onMouse | HIGH | Open |
| #1599 | Data race shutdown | HIGH | Open |
| #1673 | Signal channel leak | HIGH | Closed |
| #1313 | Windows can't select text | MEDIUM | Closed |
| #162 | Text selection + mouse | MEDIUM | Open |
| #751 | Multi-model routing | HIGH | Open |
| #371 | Components should implement Model | HIGH | Open |
| #831 | Textarea slow paste | MEDIUM | Closed |
| #958 | Sequence infinite loop | HIGH | Closed |

---

*Report generated from analysis of GitHub Issues, Pull Requests, Discussions, and Releases for charmbracelet/bubbletea*
