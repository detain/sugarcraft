# daltonsw/bubbleup

## Metadata
- URL: https://github.com/daltonsw/bubbleup
- Language: Go
- Stars: Unknown (GitHub credentials unavailable)
- License: MIT (Copyright (c) 2024 Dalton Williams)
- Description: Float your alerts to the top of your TUI like a bubble in a soda. Integrates with BubbleTea applications seamlessly to render your status updates in style.

## Feature List

- **6-screen positions**: TopLeft, TopCenter, TopRight, BottomLeft, BottomCenter, BottomRight
- **4 built-in alert types**: Info, Warning, Error, Debug — each with distinct colors and symbols
- **Custom alert registration**: `RegisterNewAlertType()` for user-defined alert types with custom key, foreground color, style, and prefix
- **Dynamic width**: Fixed width mode vs dynamic width mode (minWidth → maxWidth based on message length)
- **3 font/symbol options**: NerdFonts (icons), Unicode symbols, ASCII fallback
- **Auto-dismiss timer**: Duration-based alert expiration with tick-based animation
- **Keyboard dismiss**: ESC key to close active alert early
- **Content overlay rendering**: `Render()` composites alerts over any background view string
- **Method chaining**: All `With*()` configuration methods return modified model (immutable pattern)
- **BubbleTea Model interface**: Fully implements TEA pattern with `Init()`, `Update()`, `View()`

## Key Classes and Methods

- **AlertModel** (struct): The main model — stores alertTypes map, activeAlert, width/minWidth, duration, position, font preferences
- **NewAlertModel(width int, useNerdFont bool, duration time.Duration) *AlertModel**: Factory — creates model with default alert types registered
- **WithPosition(pos Position) AlertModel**: Configure screen position (immutable)
- **WithMinWidth(min int) AlertModel**: Enable dynamic width with minimum bound (immutable)
- **WithUnicodePrefix() AlertModel**: Switch to Unicode font symbols (immutable)
- **WithAllowEscToClose() AlertModel**: Enable ESC key to dismiss alerts (immutable)
- **NewAlertCmd(alertType, message string) tea.Cmd**: Create command to trigger an alert
- **RegisterNewAlertType(definition AlertDefinition)**: Register custom alert type
- **Render(content string) string**: Overlay active alert(s) onto content string
- **HasActiveAlert() bool**: Check if alert is currently displayed
- **alert** (struct): Individual alert instance — message, deathTime, prefix, foreColor, style, width, minWidth, curLerpStep, position
- **Position** (type): Enum-like with constants TopLeftPosition, TopCenterPosition, TopRightPosition, BottomLeftPosition, BottomCenterPosition, BottomRightPosition
- **AlertDefinition** (struct): Alert type registration — Key (string), ForeColor (hex), Style (lipgloss.Style), Prefix (string)
- **alertMsg**: Internal tea.Msg for alert activation — alertKey, msg, dur
- **tickMsg**: Internal tea.Msg for timer ticks — drives animation and auto-dismiss

## Notable Algorithms / Named Patterns

- **Elm Architecture (TEA)**: Full implementation of BubbleTea Model interface — `Init()`, `Update(msg) (Model, Cmd)`, `View() string`
- **Fluent/Method Chaining**: All configuration methods (`With*()`) return modified copy, enabling builder pattern
- **LAB Color Blending**: `backColor.BlendLab(foreColor, curLerpStep)` for smooth color fade animation
- **Hanging Indent Wrapping**: `hangingWrap()` applies prefix on first line, indents subsequent lines to match
- **ANSI-Safe String Cutting**: `cutLeft()` and `cutRight()` strip printable characters while preserving ANSI escape sequences
- **Position-Based Content Overlay**: `buildLineForPosition()` handles 6 different screen positions for alert placement
- **Tick-Based Animation**: 100ms tick interval to drive `curLerpStep` increment (DefaultLerpIncrement = 0.18)

## Strengths

- **Clean API surface**: Minimal, focused library with clear separation between AlertModel (logic) and alert (data)
- **Immutable patterns**: All `With*()` methods return copies; activeAlert is a pointer that gets replaced
- **Comprehensive positioning**: All 6 screen corner/edge positions supported
- **Multiple font options**: NerdFont (best visuals), Unicode (portable), ASCII (maximum compatibility)
- **Dynamic width**: Handles varying message lengths elegantly with min/max bounds
- **BubbleTea integration**: Seamlessly composes with existing BubbleTea applications via standard TEA pattern
- **Custom alert types**: Extensible via AlertDefinition struct with validation
- **Good documentation**: README includes basic example, configuration options, and integration guide
- **Escaped content handling**: Properly handles ANSI escape sequences when cutting/truncating strings

## Weaknesses

- **Single alert only**: Only one active alert at a time (no queue/concurrency)
- **No fade animation implementation**: TODO comment for animation, but `curLerpStep` only drives color blending, not position/size transitions
- **Limited customization**: No border style options, no padding control beyond lipgloss defaults
- **100ms tick overhead**: Continuous ticking even when alert is fully faded (curLerpStep >= 1.0)
- **No progress bar support**: No progress toast capability (unlike SugarToast PHP port)
- **No action buttons**: Cannot attach clickable actions to alerts (unlike SugarToast PHP port)
- **No history log**: No record of dismissed alerts
- **Documentation gaps**: No godoc examples; package path `go.dalton.dog/bubbleup` not hosted on pkg.go.dev visible in README

## SugarCraft Mapping

| Upstream (Go) | SugarCraft Port | Subdir | Composer Package | Namespace | Status |
|---|---|---|---|---|---|
| daltonsw/bubbleup | SugarToast | sugar-toast/ | sugarcraft/sugar-toast | SugarCraft\Toast | 🟢 |

**Many-to-many mapping notes**:
- `AlertModel` → `Toast` (main model class)
- `alert` struct → `Alert` (individual alert data)
- `Position` type → `Position` enum (9 positions in SugarToast vs 6 in upstream)
- `AlertDefinition` → `ToastType` + `SymbolSet` (alert type definitions)
- `NewAlertCmd()` → `alert()` (trigger alerts)
- `Render()` → `View()` (render to string)
- `WithUnicodePrefix()` → `withSymbolSet()` (font selection)
- `WithMinWidth()` → `withMinWidth()` (dynamic width)
- `WithPosition()` → `withPosition()` (positioning)
- `WithAllowEscToClose()` → `withAllowEscToClose()` (keyboard dismiss)
- **Enhancements in SugarToast**: Multiple concurrent alerts (queue), progress toasts, action buttons, history log, 9 positions (adds middle variants)

## Analysis

**daltonsw/bubbleup** is a focused, single-purpose Go library that brings floating notification alerts to BubbleTea TUI applications. The library implements the Elm Architecture (TEA) pattern fully, integrating as a sub-model within a host BubbleTea application. Its core design centers on the `AlertModel` struct which holds alert type definitions and the currently active alert, with configuration handled through a fluent builder pattern via `With*()` methods that return modified copies for immutability.

The rendering pipeline is particularly well-designed: the `Render()` method takes a string of background content and composites the active alert on top using position-aware logic. The position system supports 6 corners/edges, and the overlay logic carefully handles ANSI escape sequences when truncating content lines to make room for the alert. Color animation uses LAB color space blending (`backColor.BlendLab()`) to fade from black toward the alert's foreground color as the `curLerpStep` increments from 0.3 to 1.0.

**SugarToast** (the PHP port in SugarCraft) has significantly enhanced the upstream with multiple concurrent alerts via a queue system, progress bar support, action buttons with callbacks, a history log of dismissed alerts, and 9 screen positions (adding middle-left, middle-center, middle-right). The port follows the SugarCraft immutable + fluent pattern with `with*()` setters and `readonly` properties. The upstream could benefit from queue/multiple alerts support and a proper fade animation implementation (currently only color blending exists as a stub).

The library's narrow scope makes it easy to understand and integrate, while the built-in alert types (Info, Warn, Error, Debug) with distinct colors and font options cover most use cases. The design decision to make `View()` return an empty string (consumers must call `Render()` instead) is a clever way to signal the composite rendering pattern while remaining compatible with the BubbleTea Model interface.
