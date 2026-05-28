# Second-Stage Ecosystem Intelligence Report: php-school/cli-menu

## 1. Repository Overview

- **URL:** https://github.com/php-school/cli-menu
- **Language:** PHP >=8.2
- **Stars:** ~1,944
- **License:** MIT
- **Status:** Deprecated/maintenance-mode (last commits in 2025 are PHP version bumps and CS fixes; no new features)
- **Key characteristic:** Mature, feature-complete library with stable API; active development has stalled but critical bugs still get fixes

The library provides interactive TTY menus with colors, borders, checkboxes, radio items, submenus, input dialogs, and ASCII art. It uses a blocking event loop with non-canonical terminal mode for character-by-character input.

---

## 2. Existing SugarCraft Mapping

From the first-pass analysis, php-school/cli-menu maps to:

| php-school/cli-menu Class | SugarCraft Equivalent | Notes |
|---|---|---|
| `CliMenu` | `SugarCraft\Shell\Menu` | Main menu runtime + event loop |
| `MenuStyle` | `SugarCraft\Core\Style` | Box-model styling |
| `CliMenuBuilder` | `SugarCraft\Shell\MenuBuilder` | Fluent builder |
| `SplitItem` | `SugarCraft\Bits\SplitRow` | Horizontal layout |
| `SelectableItem` | `SugarCraft\Bits\SelectableItem` | Basic selectable row |
| `CheckboxItem` | `SugarCraft\Bits\CheckboxItem` | Toggle checkbox |
| `RadioItem` | `SugarCraft\Bits\RadioItem` | Mutually-exclusive radio |
| `Flash`/`Confirm` | `SugarCraft\Shell\Dialogue` | Overlay prompts |
| `Text`/`Number`/`Password` | `SugarCraft\Bits\Input` | Input rows |
| `ColourUtil` | `SugarCraft\Core\Ansi\ColourUtil` | ANSI color generation |
| `NonCanonicalReader` | `SugarCraft\Core\Terminal\RawMode` | Raw TTY input |

**Tier mapping:** Primarily `candy-shell` (framework/system) and partially `sugar-bits` (components).

---

## 3. Previously Identified Gaps

The first-pass analysis identified these gaps:

- No async/stream support — entirely synchronous blocking I/O
- No Windows native support — relies on `ext-posix`
- No menu search/filter — no type-ahead filtering
- No mouse support — purely keyboard-driven
- Monolithic event loop — `while` loops in `CliMenu::display()` and `InputIO`
- Limited accessibility — no screen reader/aria support
- Tight coupling to `php-school/terminal` package
- No built-in internationalization
- Deprecated/inactive maintenance

---

## 4. High-Signal Open Issues

### Issue #281: "Present menu inline; without clearing terminal screen" (1 comment)
**Signal:** Moderate — represents a real architectural constraint.
- User wants to embed menus within larger CLI scripts without clearing the screen
- **Root cause:** The library always calls `clear()` (TTY control) before drawing, which is hardcoded behavior
- **Engineering insight:** This is a fundamental design assumption — the menu claims sole ownership of the terminal. SugarCraft should provide an `embedded` or `inline` mode that appends to existing output rather than claiming the full terminal.

### Issue #280: "Add option to change the newlines before and after the menu is drawn" (8 comments)
**Signal:** High — 8 comments suggests widespread frustration with vertical margin control.
- Users want to control vertical positioning of menus
- Affects small terminal windows and custom layouts
- **Engineering insight:** The library hardcodes newline bytes before/after the menu frame. SugarCraft should expose margin/padding as style properties with configurable top/bottom values.

### Issue #277: "Ability to add sub-menu from addItem's callable" (9 comments)
**Signal:** High — 9 comments and a well-articulated feature request.
- Users want **dynamic submenu generation** — build submenus on-demand when an item is selected
- Current workaround requires pre-building all submenus at menu construction time
- **Engineering insight:** This is a **lazy-building** pattern request. The menu system needs to support deferred item construction. SugarCraft should support callable-based item factories that are invoked when the menu is opened or when a parent item is activated.

### Issue #266: "Pressing backspace in empty text input crashes" (8 comments)
**Signal:** High — this is a **crash bug**, not a feature gap.
- Backspace on empty input in `Text`/`Password` prompts causes `TypeError: Argument 2 passed to InputIO::drawInput() must be of type string, bool given`
- Terminal also stops echoing input after crash (corrupted TTY state)
- **Engineering insight:** The `InputIO` class doesn't handle the edge case where buffer is empty. This is a **defensive coding failure**. SugarCraft must validate buffer bounds in all input handlers and ensure TTY state is restored on any exit path (error, cancel, completion).

### Issue #255: "Terminal overflow and menu size" (2 comments, labeled enhancement)
**Signal:** Moderate — shows terminal awareness limitations.
- Menu overflows when it exceeds terminal dimensions
- User had to write custom `stty size` wrapper to detect terminal dimensions
- **Engineering insight:** The width shrinkage algorithm (`maybeShrinkWidth()`) only handles horizontal shrinking. There's no vertical scrolling or pagination for tall menus. SugarCraft should implement **content-aware pagination** for menus that exceed terminal height.

### Issue #217: "Grouped items" (2 comments, open since 2019)
**Signal:** Very high — a **5+ year-old unfixed feature gap**.
- Users cannot group RadioItems or CheckboxItems into independent selection groups at the same menu level
- SplitItem works around this but requires horizontal layout
- **Engineering insight:** The radio/checkbox toggle logic operates at the menu level, not at a group level. A `GroupItem` container was proposed but never implemented. SugarCraft should design a proper **selection group** abstraction.

### Issue #278: "How do I capture CTRL+C event?" (2 comments)
**Signal:** Moderate — shows missing interrupt handling.
- Users want to intercept SIGINT/Ctrl+C to perform cleanup
- Currently not supported
- **Engineering insight:** The non-canonical reader doesn't expose signal events. SugarCraft should provide optional signal handlers that can clean up TTY state and invoke user callbacks.

### Issue #279: "Preserving selection index as you move up/down" (0 comments)
**Signal:** Low signal but relevant — motion preservation preference.
- User wants selection state to persist when navigating between same-level items
- **Engineering insight:** This is a minor UX preference but suggests the need for configurable navigation behavior.

---

## 5. Important Closed Issues

### Issue #275: "Accept any callable for addSubMenu and addSplitItem" (4 comments, merged)
- `addSubMenu()` only accepted `Closure` type, not other callable types (invokable objects, etc.)
- **Fixed by:** Changed parameter type hint to `callable` union type
- **Engineering lesson:** Type hints should be as loose as the underlying semantics require. SugarCraft should use `callable` types for action parameters, not specific closure types.

### Issue #274: "Support full-width unicode characters" (2 comments, merged)
- `mb_strlen()` used instead of `mb_strwidth()` for width calculation
- CJK characters would break UI alignment because they are full-width (2 cells) but `mb_strlen` counts them as 1
- **Fixed by:** Replacing all `mb_strlen` with `mb_strwidth` for visual width calculation
- **Engineering lesson:** For any text rendering involving non-ASCII, always use `mb_strwidth` for display width and `mb_strlen` for character counts. This is a critical bug for internationalization. SugarCraft must use `mb_strwidth` consistently.

### Issue #263: "Don't work setWidth() for MenuStyle" (4 comments)
- `MenuStyle::setWidth(200)` had no effect on actual menu width
- **Root cause:** The width was being overridden or not applied at the right stage
- **Engineering lesson:** Style application order matters. SugarCraft should validate that style properties propagate correctly through the builder → style → renderer pipeline.

### Issue #259: "setPromptText multiline support" (5 comments)
- Multiline prompt text breaks the menu layout
- **Root cause:** The prompt text wasn't word-wrapped to fit within menu content width
- **Engineering lesson:** Any user-provided text that can exceed content width needs to be word-wrapped. SugarCraft should wrap all user text (prompts, item text, messages) at content width boundaries.

### Issue #257: "Windows compatibility: replace posix_isatty() with stream_isatty()" (5 comments)
- `posix_isatty()` doesn't work on Windows
- `stream_isatty()` is the cross-platform replacement
- **Engineering lesson:** `stream_isatty()` should be used instead of `posix_isatty()` for PHP 7.2+. SugarCraft should use `stream_isatty()` exclusively.

### Issue #246: "Custom and auto mappings can't override default" (18 comments — highest comment count)
- `M` and `L` keys are hardcoded in default mappings, preventing use as item shortcuts
- `addCustomControlMapping('M', $callback)` was silently ignored
- **Root cause:** The default control map was checked first, preventing overrides
- **Engineering lesson:** Default behaviors should be overridable. SugarCraft should implement a proper **keybinding precedence**: explicit custom mappings > auto-detected shortcuts > default mappings.

### Issue #244/#243: "Dynamically regenerate sub-menu" / "Completely rebuild Menu" (6 comments each)
- Two different issues but the same root problem: **menu content is fixed at build time**
- Users want to rebuild/reload menu items each time a submenu is opened
- **Engineering lesson:** The builder creates a static structure. SugarCraft needs a **lazy rebuild** or **item factory** pattern where items can be regenerated on each menu open.

### Issue #247/#248: "Menu Dialog" / "Updated Confirmation Dialog" (12/7 comments)
- The Confirm dialog had no way to cancel/escape
- It was effectively a **blocking alert**, not a true confirmation
- **Fixed by:** Adding `CancellableConfirm` dialog type
- **Engineering lesson:** All dialogs should be explicitly cancellable. SugarCraft should ensure every dialogue has a clear cancellation path (Escape key, Ctrl+C, or explicit Cancel button).

### Issue #235: "Password custom length" (7 comments)
- Password input had hardcoded 16-character maximum
- No way to set custom password length without writing a custom validator
- **Fixed by:** Adding `setValidator()` to Input interface and `setPasswordLength()` to Password input
- **Engineering lesson:** Hardcoded limits that affect UX should be configurable. SugarCraft should expose all behavioral limits as configuration options.

### Issue #236: "Improve formatting of disabled menu items" (6 comments)
- `dim` ANSI code doesn't work in PhpStorm's terminal or many Windows terminals
- Disabled items appeared identical to enabled items
- **Fixed by:** Using `bright` color (color code + 60) combined with `dim` for better cross-terminal compatibility
- **Engineering lesson:** Never rely on a single ANSI escape sequence for critical formatting. Use **layered fallbacks** (e.g., bright + dim together) for cross-terminal compatibility.

### Issue #241: "Add multiple checkbox/radio items at once" (8 comments)
- Could only add checkbox/radio items one at a time
- **Fixed by:** Adding `addCheckboxItems()` and `addRadioItems()` batch methods
- **Engineering lesson:** APIs that operate on collections should have both singular and plural forms. SugarCraft should provide `addItem()` and `addItems()` for all item types.

### Issue #219: "Columns < 100 results in broken submenu layouts/colors" (9 comments)
- Very narrow terminals (<100 cols) broke submenu styling
- **Root cause:** Integer division rounding issues in border/color generation for small widths
- **Engineering lesson:** Width calculations should use integer math with floor/ceil consistently and be tested at boundary values (0, 1, 80, 100, max).

### Issue #237: "Allow callable to determine if item is disabled" (2 comments)
- Disabled state was static (boolean at construction time)
- **Fixed by:** Allowing callable `$disabled` parameter that evaluates on each render
- **Engineering lesson:** State that affects rendering should be re-evaluable, not just static boolean flags. SugarCraft should support callable predicates for dynamic disabled state.

### Issue #201: "Style reset after 2nd submenu" (6 comments)
- Styles applied correctly to first submenu but lost in deeper nesting
- **Root cause:** `addSubMenu()` only applied style propagation when `hasChangedFromDefaults()` returned false — but if a submenu explicitly set any style, the full propagation was skipped
- **Engineering lesson:** Style propagation logic was too coarse-grained. SugarCraft should propagate individual style properties rather than treating them as all-or-nothing.

### Issue #200: "Item-specific styling" (10 comments)
- User wanted per-item styling rather than per-item-type styling
- SplitItem can hold different item types, each with different styles
- **Engineering lesson:** The style system had two levels (menu-level and item-type-level) but users wanted a third level (individual item). SugarCraft should support **item-level style overrides** as a first-class concept.

### Issue #258: "Menu items and cp1251" (3 comments)
- Windows-1251 (Cyrillic) encoding caused menu items to overflow terminal
- `mb_strlen` was being used instead of `mb_strwidth` for CJK/non-ASCII width calculation
- **Same root cause as #274.** Confirms the Unicode width bug affected real users.

### Issue #262: "PHPUnit problem if first item is not SelectableItem" (2 comments)
- If the first item doesn't invoke `close()`, the menu hangs in an infinite loop during testing
- **Root cause:** The event loop relies on item execution to change state and terminate
- **Engineering lesson:** The menu needs a **fallback exit condition** that doesn't depend on items closing the menu. SugarCraft should ensure menus can be exited via explicit close, Escape, or q/quit key even without item actions.

---

## 6. Recurring Pain Points

### Pain Point 1: Menu is Static After Build
**Frequency:** Appears in at least 3 separate issues (#277, #244, #243)
**Description:** Menu items cannot be regenerated or rebuilt after the builder creates the menu. Dynamic content (loading from DB, file system, etc.) must be pre-loaded at construction time.
**Impact:** Users who want dynamic menus must completely rebuild and reopen the menu tree.
**SugarCraft risk:** HIGH — This is a fundamental architectural limitation. SugarCraft should implement **item factories** (callables that generate items on demand) and support **menu refresh/redraw** without rebuilding.

### Pain Point 2: Terminal Compatibility Issues
**Frequency:** Appears in at least 4 issues (#274, #257, #236, #258, #219)
**Description:** ANSI escape sequences work differently across terminals (PhpStorm, Windows Terminal, PuTTY, Linux tty). `dim` doesn't work in PhpStorm. Colors work differently. Width calculation fails for non-ASCII.
**Impact:** Users on non-standard terminals get broken experiences.
**SugarCraft risk:** HIGH — SugarCraft must implement **terminal capability detection** and **graceful degradation**. The same layered fallback approach used in #236 should be standard practice: detect capability and apply multiple compatible styling layers.

### Pain Point 3: Hardcoded Behaviors That Should Be Configurable
**Frequency:** Appears in issues #280 (newlines), #235 (password length), #246 (key bindings), #263 (width)
**Description:** Various behavioral constants are hardcoded: vertical margins, password max length, key mappings, etc.
**Impact:** Users must fork the library or use workarounds for common customizations.
**SugarCraft risk:** MEDIUM — SugarCraft should make every behavioral constant a configurable property with sensible defaults.

### Pain Point 4: Submenu Style Propagation Failures
**Frequency:** Appears in at least 2 major issues (#201, #233) plus related discussions
**Description:** Styles set at the parent level don't reliably propagate to nested submenus. Particularly after the 2nd level of nesting.
**Impact:** Users with deep menu hierarchies get inconsistent styling.
**SugarCraft risk:** HIGH — Style propagation should be explicit and robust. SugarCraft should test propagation at multiple nesting depths.

### Pain Point 5: Radio/Checkbox Groups at Same Level
**Frequency:** Issue #217 (open since 2019), never resolved
**Description:** RadioItems and CheckboxItems at the same menu level all toggle each other. There's no way to have two independent radio groups at the same menu depth without using SplitItem (horizontal layout).
**Impact:** Users who want vertical grouped radio items cannot achieve this.
**SugarCraft risk:** MEDIUM — SugarCraft should implement a proper **SelectionGroup** container that manages mutual exclusion within a group while allowing multiple groups at the same menu level.

---

## 7. Frequently Requested Features

### F1: Dynamic/Lazy Menu Building (Issue #277, #244, #243)
**Priority:** HIGH
**Description:** Ability to generate menu items on-demand when a menu or submenu is opened, rather than at construction time.
**Proposed solution pattern:**
```php
$builder->addItem('Load Options', function(CliMenu $menu) {
    $items = $this->loadFromDb();
    foreach ($items as $item) {
        $menu->addItem($item->name, fn() => $this->select($item));
    }
});
```
**SugarCraft opportunity:** Implement a **MenuRebuilder** interface or `ItemFactory` callable that SugarCraft invokes each time a submenu is opened. This could be a `RebuildableMenu` trait or interface.

### F2: Inline/Embedded Menu Mode (Issue #281)
**Priority:** MEDIUM-HIGH
**Description:** Render menu without clearing the terminal first, allowing menus to be embedded in larger CLI scripts.
**SugarCraft opportunity:** Add an `EmbeddedMenu` variant that appends output rather than claiming full terminal. Would require disabling the clear-screen behavior and adjusting cursor positioning.

### F3: Vertical Radio/Checkbox Groups (Issue #217)
**Priority:** MEDIUM
**Description:** Group multiple RadioItems or CheckboxItems into independent selection groups at the same menu level, with vertical layout.
**SugarCraft opportunity:** Implement a `SelectionGroup` item type (or `RadioGroup`/`CheckboxGroup`) that contains multiple items and manages mutual exclusion internally.

### F4: Configurable Vertical Margins (Issue #280)
**Priority:** LOW-MEDIUM
**Description:** Control newlines before and after the menu for custom vertical positioning.
**SugarCraft opportunity:** Expose top/bottom margin as separate `MenuStyle` properties rather than hardcoding the output format.

### F5: Terminal Background Auto-Detection (Issue #233)
**Priority:** LOW
**Description:** Automatically set foreground/background colors based on current terminal theme (light/dark).
**SugarCraft opportunity:** SugarCraft could implement terminal background detection using heuristic queries to the terminal.

### F6: Type-ahead Search/Filter (implied by absence)
**Priority:** MEDIUM
**Description:** For long menus, type-ahead filtering to quickly jump to items.
**SugarCraft opportunity:** Implement a `FilterableMenu` trait or mode that activates when the user starts typing.

### F7: Mouse Support (implied by absence)
**Priority:** MEDIUM-HIGH
**Description:** Click-to-select menus, hover effects.
**SugarCraft opportunity:** This would require a significant architectural change (mouse event handling in the input loop). The Charmbracelet BubbleTea library supports this in Go, which could serve as upstream reference.

---

## 8. Important PRs

### PR #275: "Accept any callable for addSubMenu and addSplitItem" (merged)
- Changed parameter type from `Closure` to `callable`
- Made API more flexible without breaking existing code

### PR #274: "Support full-width unicode characters" (merged)
- Critical bug fix for CJK/international users
- Replaced `mb_strlen` with `mb_strwidth` throughout

### PR #241: "Add multiple checkbox/radio items at once" (merged)
- Added `addCheckboxItems()` and `addRadioItems()` batch methods
- Improved builder ergonomics for multi-item addition

### PR #237: "Allow callable to determine if item is disabled" (merged)
- Enabled dynamic disabled state evaluation
- Minor API change (accept callable in addition to bool for disabled param)

### PR #233 fix: "Auto detect current background" (closed as wontfix, but informative)
- Discussed terminal theme detection
- **Maintainer decision:** Not implemented — too complex and unreliable across platforms
- **Lesson:** Some features are rejected because they can't be done reliably, not because they're not wanted

### PR #246 fix (implied): Keybinding override fix (closed)
- Custom control mappings can now override defaults
- Changed the lookup order so custom mappings take precedence

---

## 9. Architectural Changes

### Change: Style System Refactor (Dec 2019)
Multiple PRs (#200–#215) refactored the style system:
- Separated `ItemStyle` into per-type classes (`SelectableStyle`, `CheckboxStyle`, `RadioStyle`)
- Introduced `Locator` to map item classes to style classes
- Added `PropagatesStyles` interface for composite items
- Changed from a single style object to a style registry
- **Impact:** This was a major breaking change that fixed long-standing style propagation issues
- **Lesson for SugarCraft:** Style systems should use a registry/locator pattern, not a single global style. Plan for this early.

### Change: Checkbox/Radio Refactor (Dec 2019)
PRs #185–#194 introduced:
- `ToggableItemInterface` (later removed in #226)
- `ToggableTrait` for shared toggle logic
- Separate `CheckboxItem` and `RadioItem` classes
- Auto-toggle behavior when selecting items
- **Lesson for SugarCraft:** Shared behavior between similar item types should use traits or composition, not inheritance chains.

### Change: Input System Changes
- Added `CancellableConfirm` (issue #248)
- Added `setValidator` to Input interface (issue #235)
- Fixed backspace handling (issues #266, #260)
- **Lesson for SugarCraft:** Input validation should be pluggable via a `ValidatorInterface` rather than hardcoded.

---

## 10. Performance Discussions

No explicit performance issues were found in the issue tracker. The library uses:
- Simple string concatenation for ANSI output
- No caching mechanism (menus are rebuilt each open)
- Minimal object allocation per frame

**Potential performance concerns for SugarCraft:**
- The monolithic `while` input loop prevents any concurrent processing
- `Frame` accumulation uses array append, which is O(n) per row
- No lazy evaluation — all items are always rendered even if off-screen

---

## 11. Extensibility Discussions

### Extensibility via Style Locator
The `Locator` class allows registering custom styles per item class. This is the primary extension point for visual customization.

### Extensibility via Custom Control Mappings
`addCustomControlMapping()` allows rebinding keypresses to custom handlers.

### Extensibility Limitations
- **No plugin system** — all customization requires code changes
- **No theme system** — styles are set per-menu, not globally
- **No item renderer override** — rendering is hardcoded per item type via `getRows()`
- **No event hooks** — no before/after hooks for item selection, menu open/close

**SugarCraft opportunity:** SugarCraft should consider:
1. A **RendererInterface** that allows replacing the entire rendering pipeline
2. A **ThemeProvider** for shared style presets
3. **EventDispatcher** integration for menu lifecycle hooks
4. A lightweight **plugin/extension** mechanism

---

## 12. API/UX Complaints

### Complaint: API is too builder-centric
- The `CliMenuBuilder` is the only way to construct menus
- Direct construction of `CliMenu` with items requires knowledge of internal dependencies (Terminal, Style)
- **SugarCraft risk:** Builder pattern is good for construction ergonomics but creates a "walls around the internals" problem. SugarCraft should expose both builder API and direct construction for advanced users.

### Complaint: Menu is a "black box" during `open()`
- Once `open()` is called, the menu takes over the terminal
- No way to introspect menu state from outside
- **SugarCraft risk:** HIGH — SugarCraft should provide state accessors and event hooks so external code can observe menu activity without blocking the event loop.

### Complaint: No cancel/escape for confirm dialog
- Original `Confirm` dialog had no way to cancel (issue #247)
- Fixed by adding `CancellableConfirm` but original `Confirm` remained
- **SugarCraft lesson:** Every interactive prompt should have an explicit cancel/escape path. The original design was a "roach motel" — you could enter but not leave.

### Complaint: Auto-shortcut collision with vim keys
- Default `j/k` for up/down conflicts with vim users' muscle memory for other libraries
- Cannot override `M` and `L` keys for item shortcuts (issue #246)
- **SugarCraft lesson:** Key defaults should be non-intrusive and fully overridable. SugarCraft should ship with minimal defaults (arrows only) and let users opt into vim-style bindings.

### Complaint: `getSelectedItem()` returns wrong thing for split/radio
- When using radio items or split items, `getSelectedItem()` returns confusing results (issue #188)
- **SugarCraft lesson:** For composite items, selection semantics are ambiguous. SugarCraft should clarify selection behavior in composite contexts.

---

## 13. Migration Problems

### Problem: Upgrading from v3 to v4
The v4 release changed the API significantly:
- Builder methods changed names
- Style system was refactored
- Item class hierarchy changed

### Problem: PHP Version Upgrades
- PHP 8.1 deprecations required fixes (issues #250, #252)
- PHP 8.4 nullable parameter deprecations required fixes (issue #284)
- `ctype_digit` behavior change required `is_numeric` replacement (issue #270)
- `mb_strlen` vs `mb_strwidth` inconsistency (issue #274)

### Problem: Terminal Dependency Changes
- The library depends on `php-school/terminal` which has its own breaking changes
- Changes to `NonCanonicalReader` affect input handling

### SugarCraft defensive lesson:
- Pin PHP compatibility loosely but test all supported versions
- Use `mb_strwidth` for all visual width calculations from the start
- Avoid nullable parameter declarations without explicit nullable type
- Abstract terminal I/O so breaking changes in the terminal package don't cascade

---

## 14. Clever Fixes & Workarounds

### Workaround: Dynamic submenu using callable-based item factory (Issue #244, #243)
Users work around the "menu is static after build" by:
1. Closing and reopening the entire menu tree
2. Rebuilding the menu with fresh data on each open
3. Storing state outside the menu and rebuilding when needed

**Code pattern observed:**
```php
$menu = buildMenu(); // rebuild each time
$menu->open();
// on item select, close and rebuild
```

### Workaround: Test without hanging (Issue #262)
For PHPUnit testing without a selectable item that closes the menu:
```php
// Patch the terminal mock to return 'q' (quit) after N iterations
$this->terminal
    ->expects($this->any())
    ->method('read')
    ->willReturn("\n"); // but first item must call ->close()
```

### Workaround: Manual terminal size detection (Issue #255)
Users wrote their own `stty size` wrapper because the library didn't expose terminal dimensions:
```php
exec('stty size', $output);
preg_match('#(?<rows>[0-9]+) (?<columns>[0-9]+)#', $output[0], $matches);
```

### Workaround: Custom key for auto-shortcut (Issue #246)
Users avoided using `M` or `L` as shortcuts because they couldn't override defaults.

### Workaround: Multi-byte text width (Issue #274, #258)
Users worked around CJK width issues by manually padding strings or avoiding non-ASCII text.

---

## 15. Community Workarounds

### Workaround: Terminal colors on Windows
Users reported `posix_isatty()` returns false on Windows even when in a real TTY. Workaround was using `stream_isatty()` which works cross-platform.

### Workaround: Disabled items using callable (Issue #237)
Before this was supported natively, users used checkbox/radio state to simulate dynamic disabled state by checking state at selection time:
```php
$item->setDisabled($someCondition); // but this was static
// Workaround: check condition in the callable
->addItem('Item', function($menu) use ($someCondition) {
    if ($someCondition) { /* do nothing or flash */ return; }
    /* actual action */
});
```

### Workaround: Menu redraw for state updates (Issue #237)
Users called `$menu->redraw()` after state-changing operations to refresh disabled states:
```php
$checkboxSelected = function ($menu) use ($checkbox) {
    $checkbox->toggle();
    $menu->redraw(); // refresh to show new disabled states
};
```

---

## 16. Maintainer Guidance Patterns

### Pattern: Reject complex features, accept simple fixes
**Examples:**
- Issue #233 (auto-detect terminal background): Rejected — "too complex and unreliable"
- Issue #281 (inline menu): No conclusion yet, but likely needs API rethink
- Issue #217 (grouped items): Never implemented despite being open since 2019

**Pattern interpretation:** Maintainers prefer small, targeted fixes over large architectural changes. SugarCraft should scope PRs narrowly.

### Pattern: Close issues that require fundamental redesign
**Examples:**
- Issue #243 (rebuild menu): Closed as wontfix with reference to workarounds
- Issue #281 (inline menu): Open but marked as "style choice" (screen clearing is necessary)

### Pattern: Merge bug fixes quickly, feature PRs slowly
Bug fixes (especially crash bugs like #266, #274) get merged. Features require more consensus and longer discussion.

### Pattern: Keep backward compatibility
All the PRs that changed APIs (like #275, #237) maintained backward compatibility. Breaking changes were avoided.

### Pattern: Rely on community for Windows testing
Windows compatibility issues (#257, #245) were often reported by community members who then helped test fixes. CI had AppVeyor for Windows but issues still slipped through.

---

## 17. Rejected Ideas Worth Revisiting

### Idea: Auto-detect terminal background color (Issue #233)
**Rejected:** Too complex/unreliable across platforms
**Why worth revisiting for SugarCraft:** Modern terminals support/querying background color via ANSI escape sequences (`OSC 10/11/110/111`). SugarCraft could implement optional auto-detection with a fallback to user-configured colors, especially since Charmbracelet upstream libraries in Go have explored this.

### Idea: Inline/embedded menu (Issue #281)
**Not explicitly rejected, but treated as "hard to change"**
**Why worth revisiting for SugarCraft:** This is a legitimate use case (menus embedded in larger scripts). SugarCraft could design this from the start as a menu mode parameter rather than trying to bolt it onto php-school's architecture.

### Idea: Terminal mouse support
**Never formally requested but implied by absence**
**Why worth revisiting for SugarCraft:** Charmbracelet's BubbleTea (Go) has mature mouse support. SugarCraft could leverage ANSI mouse escape sequences (SGR 1000/1002/1003) for click-to-select and hover detection.

### Idea: Grouped items without SplitItem (Issue #217)
**Open since 2019, never implemented**
**Why worth revisiting for SugarCraft:** This is a clean vertical equivalent of SplitItem for radio/checkbox groups. SugarCraft could implement a `RadioGroup` container that provides independent selection groups.

---

## 18. Problems Likely Relevant To SugarCraft

### Problem: Backspace crash in empty input (Issue #266)
**DIRECT RISK:** HIGH — Any text input component with backspace handling could have this exact bug.
**SugarCraft action:** Ensure `InputReader` validates buffer bounds before every operation. All delete/backspace operations must check `strlen($buffer) > 0`.

### Problem: Unicode width calculation (Issues #274, #258)
**DIRECT RISK:** HIGH — Any text rendering using `strlen` or `mb_strlen` instead of `mb_strwidth` will break for CJK and full-width characters.
**SugarCraft action:** Use `mb_strwidth` for all visual width calculations. This should be enforced via static analysis (PHPStan rule) or a dedicated `StringWidth` utility.

### Problem: Style propagation in nested containers (Issue #201)
**DIRECT RISK:** MEDIUM-HIGH — Any style system with composite items (SplitItem equivalent) needs to propagate styles correctly to children.
**SugarCraft action:** Design a style propagation algorithm that handles arbitrary nesting depth, with explicit tests at depth 0, 1, 2, 3+.

### Problem: Monolithic input loop (Issue #266, #277)
**DIRECT RISK:** MEDIUM — Having the entire event loop in one `while` block makes it impossible to extend with async, parallel, or event-driven patterns.
**SugarCraft action:** Design the input loop as a state machine with clear transition methods, making it possible to inject custom event handlers or async dispatchers.

### Problem: Terminal state corruption on crash (Issue #266)
**DIRECT RISK:** MEDIUM — When the menu crashes, the terminal can be left in raw mode with echo disabled.
**SugarCraft action:** Use a try/finally or register_shutdown_function to ensure TTY state is restored even on fatal errors.

### Problem: Radio/checkbox mutual exclusion at wrong scope (Issue #217)
**DIRECT RISK:** MEDIUM — If all radio items in a menu share one exclusion group, users can't have independent groups.
**SugarCraft action:** Design radio items to belong to a named group. Default group is the menu itself. Groups can be explicitly named for multiple groups at same level.

### Problem: Default key bindings blocking user shortcuts (Issue #246)
**DIRECT RISK:** MEDIUM — If SugarCraft defaults include letters that users might want for shortcuts, they can't override them.
**SugarCraft action:** Start with minimal defaults (arrows only). Make ALL key bindings overridable with explicit priority: custom > auto-detected > default.

### Problem: Missing cancel path in dialogs (Issue #247)
**DIRECT RISK:** LOW-MEDIUM — If SugarCraft dialogs don't have escape/cancel, they become "roach motels."
**SugarCraft action:** Every dialog must have a clear cancel path. Default to Escape key canceling input.

---

## 19. Features SugarCraft Should Consider

### Feature: Item Factory / Lazy Building
Support callable item factories that are invoked each time a menu is opened:
```php
->addItemFactory('Options', fn() => $this->buildOptionsItems())
```
This directly addresses the most frequent pain point from the issue tracker.

### Feature: Embedded/Inline Menu Mode
A menu mode that doesn't clear the screen, allowing menus to be embedded in larger scripts:
```php
$menu = (new MenuBuilder)->setMode(MenuMode::Embedded)->build();
```
This addresses issue #281 which has no workaround in php-school.

### Feature: Selection Group Container
A `RadioGroup` / `CheckboxGroup` container for independent selection groups:
```php
->addRadioGroup('Payment Method', [
    ['Credit Card', $ccAction],
    ['PayPal', $ppAction],
    ['Bank Transfer', $btAction],
])
```
This addresses issue #217 which has been open since 2019.

### Feature: Terminal Capability Detection
Detect terminal support for various ANSI features (dim, bright, 256-color, mouse) and apply appropriate fallbacks:
```php
$terminal->supports(Feature::DimText); // bool
$terminal->supports(Feature::MouseClick); // bool
```

### Feature: Configurable Exit Keys
Allow customization of which keys can exit menus or close dialogs:
```php
->setExitKeys([Key::Escape, Key::CtrlC, Key::Q])
```

### Feature: Event Hooks
Lifecycle hooks for menu operations:
```php
$menu->onOpen(fn(Menu $menu) => $this->onMenuOpen($menu));
$menu->onSelect(fn(Item $item) => $this->onItemSelect($item));
$menu->onClose(fn() => $this->cleanup());
```

### Feature: Soft Rebuild
Ability to rebuild a menu's items without closing/reopening the terminal:
```php
$menu->rebuildItems(fn(MenuBuilder $b) => $b->addItems($freshItems));
```

---

## 20. Architectural Lessons

### Lesson: Separate Menu Construction from Menu Runtime
php-school's `CliMenuBuilder` and `CliMenu` are separate classes, which is good. However, the builder creates a static structure that can't be modified after `build()`.
**Better pattern:** Builder creates a **menu specification** (immutable description), and the runtime **interprets** that specification. This allows re-interpretation (rebuilding) without rebuilding the builder.

### Lesson: Style Propagation Should Be Explicit, Not Implicit
php-school's `propagateStyles()` had bugs where explicit submenu styles prevented further propagation (#201). The logic was also all-or-nothing.
**Better pattern:** Each style property should propagate independently. Submenu explicitly setting `foregroundColour` shouldn't block `backgroundColour` from propagating.

### Lesson: Input Loop Should Be a State Machine
The monolithic `while` loop in `CliMenu::display()` makes testing difficult and extension nearly impossible (#262).
**Better pattern:** States (`Idle`, `ReadingInput`, `Processing`, `Rendering`) with explicit transitions. This makes the loop testable in isolation and allows injection of custom state handlers.

### Lesson: TTY State Must Be Protected by a Guard
When the menu crashes (e.g., issue #266), the terminal can be left in a corrupted state.
**Better pattern:** Use a `TerminalGuard` object that:
1. Saves TTY state on construction
2. Registers shutdown function to restore state
3. Restores state on any exit path (normal, error, exception)

### Lesson: Dialogs Must Always Have a Cancel Path
Original `Confirm` dialog (issue #247) had no escape.
**Better pattern:** Design dialogs from the start to be cancellable. Every input can be cancelled; the cancel is the default behavior if the user doesn't confirm.

### Lesson: Build a Theme System Early
php-school has no theme system — every menu is styled individually. This leads to repetitive configuration code.
**Better pattern:** A `ThemeProvider` that returns pre-configured `MenuStyle` objects:
```php
$theme = Theme::TokyoNight();
$menu = (new MenuBuilder)->setStyle($theme['dark'])->build();
```

---

## 21. Defensive Design Lessons

1. **Validate buffer bounds before delete operations** — Issue #266 crash happened because backspace wasn't bounds-checked against an empty buffer.

2. **Use `mb_strwidth` for all visual width calculations** — `mb_strlen` counts characters, not display cells. CJK characters break layouts when `mb_strlen` is used.

3. **Restore TTY state on ALL exit paths** — normal completion, errors, exceptions, and signals. Use a cleanup guard or shutdown function.

4. **Make defaults minimal and overrides complete** — Start with arrows-only navigation defaults. Vim-style `j/k` bindings should be opt-in. ALL defaults must be overridable.

5. **Design for graceful degradation** — Don't rely on single ANSI escape sequences for important formatting. Layer `dim` + `bright` for disabled text. Fall back to simpler sequences when advanced ones aren't supported.

6. **Test at boundary values** — Issue #219 showed that width=99 breaks but width=100 works. Test at 0, 1, 80, 100, and max terminal sizes.

7. **Use `stream_isatty()` not `posix_isatty()`** — The latter doesn't work on Windows and is deprecated behavior.

8. **Every user-provided string must be word-wrapped** — Prompt text (#259), item text with long content, and any user-facing text should be wrapped at content width.

9. **Provide both singular and plural collection methods** — `addItem()` and `addItems()` both needed (#241).

10. **State that affects rendering should be re-evaluable** — Callable disabled predicates (#237) are better than static booleans for dynamic content.

---

## 22. Ecosystem Trends

### Trend: Terminal UI as a first-class concern
The PHP ecosystem is seeing renewed interest in CLI tools (Laravel Artisan, Symfony Console, php-school ecosystem). Terminal UI libraries are moving from novelty to expected capability.

### Trend: Internationalization in terminal apps
CJK and full-width Unicode support (issue #274) indicates terminal apps are used in international contexts. `mb_strwidth` should be standard, not a bug fix.

### Trend: Async/phasing out blocking I/O
php-school's synchronous blocking loop is a known limitation. Modern terminal libraries (Charmbracelet's BubbleTea) use event-driven patterns. SugarCraft could pioneer async TUI in PHP via ReactPHP/Swoole integration.

### Trend: Cross-terminal compatibility
Different terminals (PhpStorm, Windows Terminal, iTerm2, macOS Terminal, Linux TTY) have varying ANSI support. Auto-detection with graceful degradation is increasingly important.

### Trend: Keyboard shortcut customization
Users expect vim-style, Emacs-style, or custom keybindings. Hardcoded defaults that can't be overridden create friction (issue #246).

### Trend: Embedded/streamable output
Users want to embed TUI components within larger scripts (issue #281). The "clear screen and own terminal" model is too restrictive for modern script composition.

---

## 23. Strategic Opportunities

### Opportunity 1: SugarCraft Can Lead on Async TUI in PHP
php-school is effectively abandoned. No PHP TUI library currently supports async/event-driven input loops. SugarCraft could be the first to:
- Integrate with ReactPHP event loop for non-blocking I/O
- Support async input handlers and parallel command execution
- Enable streaming output within menus

**This is a genuine competitive advantage** — being the only async-capable PHP TUI library.

### Opportunity 2: SugarCraft Can Fix the "Static Menu" Problem
The most requested feature (#277, #244, #243) is dynamic menu rebuilding. Since php-school won't implement it, SugarCraft can differentiate by designing lazy-building from day one.

### Opportunity 3: SugarCraft Can Be the "Native PHP" Alternative
php-school doesn't support Windows well. SugarCraft using `stream_isatty()` and having proper Windows CI could become the de facto PHP TUI library for cross-platform tools.

### Opportunity 4: SugarCraft Can Own the "Proper Grouping" Feature
Issue #217 (grouped items) has been open since 2019 and never implemented. SugarCraft can implement a proper `SelectionGroup` abstraction that handles both vertical layout and independent radio groups.

### Opportunity 5: SugarCraft Can Build a Plugin Ecosystem
php-school has no extension system. SugarCraft could develop:
- Standard interfaces for custom items, renderers, validators
- A registry for community-contributed items/renderers
- Theme marketplace or shared theme repository

---

## 24. Cross-Ecosystem Pattern Matches

### Charmbracelet BubbleTea (Go)
- Full async/event-driven architecture
- Mouse support via SGR 1000/1002/1003 escape sequences
- Embedded mode available via `雀` (Squirrel) rendered to a sub-region
- Theme system with named themes
- Filterable list with type-ahead

**SugarCraft alignment:** SugarCraft should mirror BubbleTea where it makes sense for PHP, especially the event-driven model and mouse support.

### Blessed (JavaScript)
- Terminal capability detection
- Graceful degradation for unsupported features
- Programmatic theming

**SugarCraft alignment:** The layered fallback approach for ANSI features matches blessed's philosophy.

### TextFunk (PHP, abandoned)
Similar TUI goals, abandoned in 2014. php-school may have been partially inspired by it.

**Lesson:** Many PHP TUI libraries have been attempted and abandoned. SugarCraft needs a sustainable architecture and community to avoid the same fate.

---

## 25. High ROI Recommendations

### Priority 1: Defensive Fixes (Do First)
1. **`mb_strwidth` everywhere** — Audit all string width calculations, replace `mb_strlen` with `mb_strwidth`. Add PHPStan rule to catch this.
2. **Input buffer bounds checking** — Validate all buffer operations (delete, backspace, cursor movement) against buffer length.
3. **TTY state guard** — Ensure TTY is restored on any exit path. Use a finally block or shutdown function.
4. **`stream_isatty()` everywhere** — Replace all `posix_isatty()` calls.

### Priority 2: Differentiating Features (Do Second)
1. **Item factory / lazy building** — Solve the #277/#244/#243 problem. This is the highest-requested feature that php-school never implemented.
2. **Selection groups** — Solve the #217 problem. Provide proper vertical radio/checkbox grouping.
3. **Embedded/inline menu mode** — Solve the #281 problem. Allow menus within larger scripts.

### Priority 3: Modernization (Do Third)
1. **Event-driven input loop** — Refactor monolithic `while` loop into a proper state machine. Enable async hooks.
2. **Terminal capability detection** — Detect `dim`, `bright`, 256-color, and mouse support. Apply layered fallbacks.
3. **Theme system** — Implement named themes with shared style presets.

### Priority 4: Ecosystem Building (Do When Stable)
1. **Plugin interfaces** — Define `ItemInterface`, `RendererInterface`, `ValidatorInterface` for community extension.
2. **Mouse support** — Implement ANSI mouse sequence handling for click-to-select.
3. **Async integration** — Add optional ReactPHP event loop support for non-blocking input handlers.

---

## Summary

php-school/cli-menu is a well-architected but deprecated library. Its issue tracker reveals a consistent set of gaps:

1. **Menu content is static after build** — the #1 pain point, never fixed
2. **Terminal compatibility failures** — ANSI edge cases, Windows issues, Unicode width
3. **Hardcoded behaviors that should be configurable** — key bindings, margins, password length
4. **Submenu style propagation bugs** — lost styles at depth 2+
5. **Missing cancellation paths** — dialogs that trap users

SugarCraft's highest-value opportunities are:
- **Async event loop** — the only differentiating architectural feature no PHP TUI library has
- **Lazy item building** — solve the static-menu problem that php-school never could
- **Cross-terminal resilience** — proper capability detection and graceful degradation
- **Selection groups** — vertical grouped radio/checkbox items, never implemented in php-school

The defensive lessons are clear: validate all input, use `mb_strwidth`, protect TTY state, make defaults minimal and overridable, and test at boundary values.
