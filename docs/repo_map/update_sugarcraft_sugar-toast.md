# SugarToast — Innovation & Update Report

## Metadata

- **Package**: `sugarcraft/sugar-toast`
- **Source**: `sugar-toast/` directory in SugarCraft monorepo
- **Composer**: `sugarcraft/sugar-toast`
- **Namespace**: `SugarCraft\Toast`
- **PHP**: `^8.3`
- **Dependencies**: `sugarcraft/candy-core` (dev-master)
- **Status**: 🟢 v1 ready
- **Upstream Primary**: [daltonsw/bubbleup](https://github.com/daltonsw/bubbleup) (Go, ~43 stars, MIT)
- **Upstream Secondary**: [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles) (progress bar reference)
- **Documentation**: `sugar-toast/README.md`, `sugar-toast/CALIBER_LEARNINGS.md`
- **Tests**: 13 test files, all passing

---

# Overview

SugarToast is a floating alert notification component for terminal UIs, ported from Go's `daltonsw/bubbleup` and significantly enhanced beyond the upstream. It provides toast-style notifications that "float" to screen edges with features like queue-based multi-alert management, progress bars, action buttons, history logging, and internationalization support. The biggest opportunities lie in completing the animation system (fade animations with CubicBezier easing), adding custom alert type registration (upstream feature missing in SugarToast), and implementing markdown rendering support for toast messages.

**Biggest Missing Capabilities:**
1. Real fade animations (stub only, CubicBezier deferred)
2. Custom alert type registration (present in upstream bubbleup, absent in SugarToast)
3. Interactive action button keyboard navigation (buttons render but no auto-trigger)
4. Programmatic individual alert removal (only bulk operations exist)
5. TEA model integration (pure renderer architecture vs Elm architecture)

---

# Internal Capability Summary

## Current Architecture

SugarToast follows a pure renderer pattern where `Toast::View()` accepts any background string and returns a composited ANSI string. This differs from upstream bubbleup's TEA (The Elm Architecture) sub-model approach:

```
Toast::View($background, $viewportWidth, $viewportHeight)
  ├── Filter expired alerts
  ├── Two-pass height computation (totalAlertLines)
  └── Per-alert compositeLines():
        ├── x = position->xOffset(alertWidth, viewportWidth)
        ├── y = position->yOffset(alertHeight, viewportHeight, cumulativeHeight)
        ├── composite by display-cell slicing (Width::truncateAnsi, Width::dropAnsi)
        └── ANSI-safe (no mid-grapheme cuts)
```

## Source Files (8 PHP files)

| File | Lines | Role |
|------|-------|------|
| `src/Toast.php` | 528 | Main model — queue, rendering, alert lifecycle |
| `src/Alert.php` | 56 | Individual alert data — type, message, expiry, progress, actions |
| `src/ToastType.php` | 90 | Enum — Error/Warning/Info/Success with icons and colors |
| `src/Position.php` | 54 | Enum — 9 screen positions (Top/Middle/Bottom × Left/Center/Right) |
| `src/Action.php` | 34 | Value object — `readonly string $label` + `\Closure(): void $callback` |
| `src/SymbolSet.php` | 15 | Enum — NerdFont / Unicode / ASCII |
| `src/Overflow.php` | 20 | Enum — DropOldest / DropNewest / Enqueue |
| `src/HistoryLog.php` | 47 | Immutable log — `list<Alert>` with `push()` and `all()` |
| `src/Lang.php` | 22 | i18n facade extending `SugarCraft\Core\I18n\Lang` |

## Current Features

- **4 Alert Types**: Error (red 31), Warning (yellow 33), Info (blue 34), Success (green 32)
- **9 Screen Positions**: Top/Middle/Bottom × Left/Center/Right (vs 6 in upstream)
- **Dynamic Width**: minWidth/maxWidth with automatic sizing
- **Symbol Sets**: NerdFont, Unicode, ASCII
- **Auto-dismiss**: Duration-based expiry with timestamp checking
- **Queue System**: Unlimited concurrent alerts with overflow strategies (DropOldest/DropNewest/Enqueue)
- **Progress Toasts**: Inline progress bar (0–100%) with Unicode block characters
- **Action Buttons**: `[Label]` buttons with Closure callbacks
- **History Log**: Immutable record of dismissed alerts
- **i18n Support**: `Lang::t()` facade with `lang/en.php`
- **Display-cell slicing**: Proper UTF-8 multibyte handling via `Width::truncateAnsi()`

## Strengths

1. Clean immutable + fluent API matching SugarCraft conventions
2. Proper UTF-8 multibyte handling throughout (display-cell slicing vs byte slicing)
3. Queue overflow strategies are thoughtful and well-tested
4. i18n wiring with `LangCoverageTest` prevents translation drift
5. Pure renderer works with any TUI framework
6. 9 position options vs 6 in upstream

## Weaknesses

1. **Animation stub** (`withAnimationDuration()`) is a no-op — honey-bounce integration deferred
2. **Action button callbacks** stored but never auto-triggered — consumer must call manually
3. **No custom alert type registration** (upstream has `RegisterNewAlertType()`)
4. **`allowEscToClose` flag** stored but actual key handling is consumer's responsibility
5. **No programmatic individual alert removal** (only `clear()`, `dismiss()`, `pruneExpired()`)
6. **No fade animation** despite field existing
7. **Debug type missing** from upstream (was replaced with Success)

---

# Relevant External Repositories

| Repo | Relevance | Major Applicable Concepts | Priority |
|------|----------|-------------------------|----------|
| `daltonsw/bubbleup` | Primary upstream | TEA sub-model, single alert, custom type registration, fade animation | P0 |
| `charmbracelet/bubbletea` | Framework reference | TEA pattern, message routing, viewport handling | P1 |
| `charmbracelet/lipgloss` | Styling reference | Style composition, color handling, border rendering | P1 |
| `charmbracelet/harmonica` | Animation reference | Spring physics, CubicBezier easing, animation timing | P1 |
| `charmbracelet/bubbles` (progress) | Progress bar reference | Half-block Unicode, 2x color resolution, gradient fills | P2 |
| `rmhubbert/bubbletea-overlay` | Compositing reference | Line-by-line overlay compositing, positioning system | P2 |
| `charmbracelet/glow` | Rendering reference | Markdown rendering, word wrap initialization | P2 |
| `textualize/textual` | Alternative TUI | Layout system, widget composition, async updates | P2 |
| `lrstanley/bubblezone` | Mouse tracking | Zone-based hit detection, ANSI markers | P3 |
| `treilik/bubblelister` | List patterns | Viewport management, cursor tracking | P3 |

---

# Feature Gap Analysis

## Critical

### 1. Fade Animation — CubicBezier Spring Easing

**Description**: The `withAnimationDuration()` field exists but produces no visual effect. CALIBER_LEARNINGS.md notes CubicBezier animation was "deferred step 09.17".

**Why It Matters**: Fade animations provide visual polish that signals "modern TUI" quality. Without them, toasts appear/disappear abruptly, reducing perceived quality.

**Source**: `docs/repo_map/sugarcraft_sugar-toast.md` (internal), `pr_charmbracelet_harmonica.md`

**Implementation Ideas**:
- Integrate `honey-bounce` `CubicBezier` class for timing curves
- Use `harmonica` LAB color blending concept for fade transitions
- Implement character-reveal animation during `animationDuration > 0`

**Estimated Complexity**: Medium — requires animation timing system integration

**Expected Impact**: High — fills the animation gap that upstream bubbleup addresses with LAB color lerp

---

### 2. Custom Alert Type Registration

**Description**: Upstream bubbleup has `RegisterNewAlertType(AlertDefinition{Key, ForeColor, Style, Prefix})` for runtime alert type registration. SugarToast only has 4 fixed types.

**Why It Matters**: Extensibility — users may need custom alert types (Debug, Verbose, Tip, etc.) beyond the built-in four.

**Source**: `docs/repo_map/sugarcraft_sugar-toast.md`

**Implementation Ideas**:
- Add static registry: `ToastType::register(string $key, AlertDefinition $def): void`
- Store custom types in static array accessible via `ToastType::fromKey(string $key)`
- Support i18n keys for custom type labels

**Estimated Complexity**: Low — similar pattern to existing enum resolution

**Expected Impact**: Medium — enables extensibility but few users currently request it

---

## High Value

### 3. Action Button Keyboard Navigation

**Description**: Action buttons render as `[Label]` but callbacks are never automatically invoked. Consumer must implement keyboard handling and call `$action->callback()` manually.

**Why It Matters**: The current UX requires consumers to implement their own key handling for action buttons, which is not obvious from the README.

**Source**: `docs/repo_map/sugarcraft_sugar-toast.md`

**Implementation Ideas**:
- Add `Toast::withSelectedAction(int $index)` to track focused action
- Add `Toast::keyPress(KeyMsg $msg): ?Action` to process key events
- Support Tab/Shift+Tab to cycle through actions, Enter to trigger

**Estimated Complexity**: Medium — requires key event processing integration

**Expected Impact**: High — makes action buttons actually usable without consumer boilerplate

---

### 4. LAB Color Blending for Fade Animation

**Description**: Upstream bubbleup uses `backColor.BlendLab(foreColor, curLerpStep)` for fade animation. SugarToast has no color blending.

**Why It Matters**: Smooth fade transitions between alert states require color interpolation in a perceptually uniform space.

**Source**: `pr_charmbracelet_bubbletea.md`, `pr_charmbracelet_harmonica.md`

**Implementation Ideas**:
- Implement LAB color space conversion in `candy-core` or `candy-palette`
- Use `SugarCraft\Core\Color\Lab` value object with `blend(Lab $other, float $t)` method
- Apply to background/border colors during fade animation

**Estimated Complexity**: Medium — requires color science implementation

**Expected Impact**: Medium — enables professional fade animations

---

### 5. Programmatic Individual Alert Removal

**Description**: No way to remove a specific alert by ID — only `clear()` (all), `dismiss()` (record all to history, clear), or `pruneExpired()` (remove expired only).

**Why It Matters**: Consumer may want to dismiss a specific alert (e.g., user clicked "dismiss" on a specific toast, not all).

**Source**: `docs/repo_map/sugarcraft_sugar-toast.md`

**Implementation Ideas**:
- Add `Alert::$id: string` with UUID generation in constructor
- Add `Toast::dismissById(string $id): Toast` (returns clone)
- Add `Toast::removeById(string $id): Toast` (returns clone, doesn't record to history)

**Estimated Complexity**: Low — straightforward ID tracking

**Expected Impact**: Medium — improves UX for queue management

---

## Medium Priority

### 6. Progress Bar Gradient Fill

**Description**: SugarToast uses solid `█`/`░` fill. Upstream reference (charmbracelet/bubbles) supports gradient fills with multiple color stops.

**Why It Matters**: Visual richness — gradient fills communicate progress state change more effectively than solid colors.

**Source**: `pr_charmbracelet_bubbles.md`

**Implementation Ideas**:
- Add `Alert::withGradient(array $colors)` for multi-stop gradient
- Use `SugarCraft\Core\Color\Gradient` class to compute color at each position
- Render partial blocks with interpolated colors

**Estimated Complexity**: Medium — requires gradient calculation

**Expected Impact**: Medium — differentiates from basic implementations

---

### 7. Partial Block Progress Smoothness

**Description**: Progress bar uses full blocks. charmbracelet/bubbles supports partial blocks (`▌`, `▎`, etc.) for smoother progress on small widths.

**Why It Matters**: Small toast widths (e.g., 20 cells) can't represent fine-grained progress with full blocks.

**Source**: `pr_charmbracelet_bubbles.md` (Issue #395)

**Implementation Ideas**:
- Calculate remainder after filling width with whole blocks
- Use partial block characters to represent fractional progress
- Add `ProgressBar::usePartialBlocks(bool)` option

**Estimated Complexity**: Low — just Unicode character selection

**Expected Impact**: Low-Medium — edge case improvement

---

### 8. TTY Detection for Output Modes

**Description**: Toast renders ANSI unconditionally. Non-TTY contexts (pipes, CI) may want stripped output.

**Why It Matters**: Common pain point in TUI tools — outputting ANSI to `less` or redirected output produces garbage.

**Source**: `pr_charmbracelet_lipgloss.md` (Issue #593)

**Implementation Ideas**:
- Add `Toast::withColorProfile(ColorProfile $profile)` option
- Use `SugarCraft\Core\Terminal\ColorProfile` detection
- Strip ANSI when profile is `NoColor` or output is non-TTY

**Estimated Complexity**: Medium — requires terminal detection integration

**Expected Impact**: Medium — prevents CI/test environment issues

---

### 9. Toast Stacking Animation

**Description**: When multiple toasts appear at same position, they stack. No animation for the stacking order.

**Why It Matters**: Makes multi-toast appearance/disappearance jarring vs animated.

**Source**: `pr_charmbracelet_harmonica.md`

**Implementation Ideas**:
- Animate each toast's y-position from off-screen or overlapping position
- Use `honey-bounce` spring animation for natural easing
- Stagger animation start times for visual appeal

**Estimated Complexity**: Medium — requires animation timing

**Expected Impact**: Medium — improves perceived polish

---

## Low Priority

### 10. Markdown Message Rendering

**Description**: Alert message is plain text only. Users may want inline markdown (bold, italic, code).

**Why It Matters**: Glow (charmbracelet) renders markdown in terminal. Toasts could similarly support inline styling.

**Source**: `pr_charmbracelet_glow.md`

**Implementation Ideas**:
- Add `Toast::withMarkdown(bool $enabled)` option
- Use `candy-shine` for markdown→ANSI rendering
- Support bold, italic, code, and links in message body

**Estimated Complexity**: Medium — requires markdown parsing integration

**Expected Impact**: Low — niche use case for toast messages

---

### 11. OSC8 Hyperlink Support

**Description**: Terminal hyperlinks (OSC 8) allow clickable URLs in toasts.

**Why It Matters**: Interactive toasts with clickable links improve UX for documentation/help systems.

**Source**: `pr_charmbracelet_glow.md` (Issue #237)

**Implementation Ideas**:
- Add `Alert::withLink(string $url, string $label)` method
- Render using OSC 8 sequences: `\x1b]8;;URL\x1b\\Label\x1b]8;;\x1b\\`
- Require terminal support detection

**Estimated Complexity**: Low — string formatting

**Expected Impact**: Low — terminal support inconsistent

---

### 12. Auto Width Detection Without tput

**Description**: Current max width is manually configured. Users may want automatic terminal width detection.

**Why It Matters**: Reduces boilerplate — user shouldn't need to call `tput cols` manually.

**Source**: `pr_charmbracelet_glow.md` (Issue #942)

**Implementation Ideas**:
- Use `candy-vt` terminal dimension detection
- Default maxWidth to terminal width minus margins
- Provide `withMaxWidth(null)` for auto-detection

**Estimated Complexity**: Low — integration with existing terminal detection

**Expected Impact**: Low — cosmetic improvement

---

# Algorithm / Performance Opportunities

## Current Approach

SugarToast uses Unix timestamps (`microtime(true)`) for expiry checking and renders all queued alerts on every `View()` call. The rendering pipeline is:

1. Filter expired alerts
2. Two-pass height computation
3. Per-alert composite with display-cell slicing

## External Approach (bubbleup)

Bubbleup uses 100ms tick intervals with `tickCmd()` → `tickMsg` and increments `curLerpStep` for LAB color animation during fade.

**Why External Is Better**:
- Tick-based animation allows smooth frame interpolation
- Timestamp-based expiry only checks on render, not continuously

**Tradeoffs**:
- Tick-based requires running timer/command
- SugarToast's pure renderer architecture intentionally avoids this complexity

## Applicable Opportunities

1. **Memoize width calculations**: Same strings rendered repeatedly should cache display-cell measurements
2. **Short-circuit for no-op renders**: If no alerts exist, skip expensive composite operations
3. **Lazy history log pruning**: HistoryLog grows indefinitely; consider size limit with eviction
4. **Partial re-render on expiry change**: Only re-composite affected alerts, not entire queue

---

# Architecture Improvements

1. **Decouple rendering from timing**: Animation timing should be injectable, not tied to render cycle
2. **Add Model interface**: Implement `SugarCraft\Core\Model` for TEA integration (matching sugar-bits components)
3. **Separate display-cell utilities**: Extract `Width` and `Ansi` helpers to reusable interfaces in candy-core
4. **Plugin system for custom alert types**: Registry pattern for user-defined ToastType extensions

---

# API / Developer Experience Improvements

1. **Consistent factory naming**: Use `Toast::new(int $maxWidth)` instead of potential `create()` variants
2. **Simplify action callback triggering**: Provide `Toast::triggerAction(int $index)` helper for consumers
3. **Add debug helpers**: `Toast::toArray(): array` for inspection, `Alert::getId(): string` for tracking
4. **Comprehensive exception types**: Specific exceptions for `OverflowException`, `InvalidPositionException`, etc.
5. **Fluent alert creation**: `Toast::info(string $msg): Toast` convenience shortcuts already exist, but document them prominently

---

# Documentation / Cookbook Opportunities

1. **Animation cookbook**: Document how to wire honey-bounce for fade animations
2. **Queue management guide**: Explain overflow strategies with visual examples
3. **Integration patterns**: Show how to integrate with candy-core, sugar-veil for complex overlays
4. **i18n guide**: How to add new locales beyond English
5. **Keyboard handling reference**: How to wire action buttons in a Bubble Tea program

---

# UX / TUI Improvements

1. **Visual feedback on queue overflow**: When DropOldest triggers, briefly flash the removed toast
2. **Progress bar percentage text**: Show `65%` text inside the progress bar (configurable)
3. **Toast grouping**: Support grouping related toasts (e.g., "3 notifications") with expand
4. **Sound hints**: Terminal bell character ( `\a` ) as optional audio feedback for errors
5. **Position-aware stacking**: TopLeft stacks left-to-right; currently all stack vertically

---

# Testing / Reliability Improvements

1. **Snapshot tests for all 9 positions**: Visual regression testing for each position combination
2. **Animation timing tests**: Verify fade animation produces correct frame sequence
3. **Concurrency tests**: Verify immutable pattern works correctly under parallel rendering
4. **Edge case coverage**: Expand tests for width=0, empty message, max concurrent limits
5. **Integration with candy's Timer**: Test auto-dismiss with ReactPHP timer integration

---

# Ecosystem / Integration Opportunities

1. **sugar-veil integration**: SugarToast toasts could render via sugar-veil's overlay compositor for complex layouts
2. **sugar-charts integration**: Progress toasts could display sparkline charts instead of simple bars
3. **candy-shell integration**: Toast notifications for shell command completion
4. **candy-metrics integration**: Performance metric notifications
5. **candy-log integration**: Log entries as toast notifications in real-time

---

# Notable PRs / Issues / Discussions

## From bubbleup (primary upstream)

### Issue: Custom Alert Type Registration
**Finding**: bubbleup has `RegisterNewAlertType()` allowing runtime alert type registration with custom colors and icons. SugarToast dropped this feature, replacing Debug with Success.

**Lesson**: Custom alert types are an extensibility feature some users need. SugarToast should re-add this capability.

### Issue: Fade Animation via LAB Color Blending
**Finding**: bubbleup implements fade animation using LAB color space blending with 100ms tick intervals.

**Lesson**: The animation approach in bubbleup works but requires timer-based updates. SugarToast's pure renderer approach intentionally avoids this, but the fade animation itself should still be implemented using honey-bounce's CubicBezier curves.

## From charmbracelet ecosystem

### Issue #593 (lipgloss): TTY Detection for Color Output
**Finding**: v2 architecture separates render (always full ANSI) from output (downsample based on TTY). Non-TTY output must strip ANSI codes.

**Lesson**: SugarToast should follow v2 pattern — render always emits full ANSI; output layer handles TTY detection and color stripping. `docs/repo_map/pr_charmbracelet_lipgloss.md`

### Issue #1655 (bubbletea): DevTools Proposal
**Finding**: Proposal for interactive inspector showing message log, state viewer, component tree.

**Lesson**: For complex TUI apps using SugarToast, debugging visibility is critical. Consider SugarToast-specific introspection helpers.

### Issue #810 (bubbles): String Concatenation Performance
**Finding**: List paginator with 8000+ items lagged due to O(n) string concatenation. Fixed with `strings.Builder`.

**Lesson**: Any string building in loops must use builders. SugarToast's `View()` loop should use `SugarCraft\Core\Util\StringBuilder` if PHP's string concatenation proves slow. `docs/repo_map/pr_charmbracelet_bubbles.md`

### Issue #1652 (bubbles): TextArea Infinite Loop
**Finding**: `wordLeft()` on empty textarea spins forever due to missing boundary check.

**Lesson**: Always validate boundary conditions before entering navigation loops. SugarToast's action button navigation should include similar guards. `docs/repo_map/pr_charmbracelet_bubbles.md`

### Issue #85 (lipgloss): Text Wrap Loses Styles
**Finding**: 3+ year old bug — wrapped text loses color/style after first line.

**Lesson**: Implement wrapping that preserves style state across line breaks. If SugarToast adds word-wrapping to messages, must follow this pattern. `docs/repo_map/pr_charmbracelet_lipgloss.md`

---

# Recommended Roadmap

## Immediate Wins

1. **Add `Alert::$id` and dismissal by ID** — Low effort, high utility for queue management
2. **Document animation stub status** — Clarify that `withAnimationDuration()` is deferred in README
3. **Add `Toast::withSelectedAction()` and key handling** — Makes action buttons actually usable

## Medium-Term Improvements

4. **Implement fade animation with honey-bounce CubicBezier** — Completes the animation gap
5. **Add custom alert type registry** — Restores upstream feature lost in port
6. **Add TTY detection and color stripping** — Follows lipgloss v2 architecture
7. **Implement LAB color blending** — Enables smooth fade transitions
8. **Add progress bar gradient fills** — Matches charmbracelet/bubbles quality

## Major Architectural Upgrades

9. **TEA model integration** — Add optional TEA model wrapper for Bubble Tea apps
10. **Plugin system for alert renderers** — Allow custom rendering per alert type
11. **Async expiry checking** — Use ReactPHP timers for accurate expiry without render polling

## Experimental Ideas

12. **Markdown message rendering** — Optional inline markdown in messages
13. **Toast grouping** — Collapse multiple similar notifications
14. **Interactive form toasts** — Toasts with text input or checkbox actions
15. **Remote toast notifications** — Toast across SSH via sugar-wish integration

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|------------|--------|-----------|------|----------|
| Alert ID + dismissal by ID | Medium | Low | Low | P1 |
| Action button keyboard nav | High | Medium | Low | P1 |
| Animation completion | High | Medium | Medium | P1 |
| Custom alert type registry | Medium | Low | Low | P2 |
| TTY detection + color stripping | Medium | Medium | Low | P2 |
| LAB color blending | Medium | Medium | Medium | P2 |
| Progress bar gradients | Medium | Medium | Low | P2 |
| Toast stacking animation | Medium | Medium | Medium | P2 |
| Markdown messages | Low | Medium | Medium | P3 |
| TEA model integration | High | High | High | P3 |
| Partial block progress | Low-Medium | Low | Low | P3 |
| OSC8 hyperlink support | Low | Low | Low | P3 |
| Auto width detection | Low | Low | Low | P3 |
| Plugin system | High | High | High | P4 |
| Toast grouping | Medium | Medium | Medium | P4 |
| Interactive form toasts | High | High | High | P5 |

---

# Final Strategic Assessment

SugarToast represents a **significant enhancement** over its upstream bubbleup, not merely a port. The most important additions are the queue-based multi-alert system (vs single-alert in bubbleup), progress bar support, action buttons with callbacks, the history log, and the 9-position layout (vs 6 in upstream). The package is production-ready with solid test coverage and well-documented patterns.

**Strategic Position**: SugarToast fills a niche in the SugarCraft ecosystem as a pure notification/rendering component that can be embedded in any TUI framework. Unlike sugar-bits components which implement full TEA models, SugarToast intentionally remains a pure renderer, making it framework-agnostic.

**Key Differentiators from Competitors**:
1. Display-cell aware slicing (fixes UTF-8 multibyte bugs present in Go implementations)
2. i18n support from day one
3. Queue overflow strategies (DropOldest/DropNewest/Enqueue)
4. Immutable + fluent API following SugarCraft conventions

**Key Gaps to Address**:
1. **Animation is the #1 gap** — The stub must become real using honey-bounce
2. **Custom alert types** — Restoring upstream's extensibility feature
3. **Action button keyboard handling** — Making buttons actually usable without consumer boilerplate

**Competitive Threat**: bubbleup (Go) remains more feature-complete with real fade animation and custom type registration. SugarCraft's PHP advantage is Unicode correctness and framework-agnostic pure renderer simplicity.

**Long-term Vision**: SugarToast should become the notification layer for the entire SugarCraft ecosystem, with integrations into sugar-veil (overlay compositor), sugar-charts (progress charts), and candy-shell (shell notifications). The animation system connecting to honey-bounce is the critical path to making this vision compelling.
