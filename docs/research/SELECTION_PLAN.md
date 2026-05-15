# SugarCraft Implementation Selection Plan

> Use this document to select which improvements to implement.
> Check the boxes for items you want, then use the linked research doc for details.

---

## HOW TO SELECT

1. Find items you want to implement
2. Check the `[ ]` box
3. Note the effort and priority
4. Reference the linked research doc for full details

---

## SUGAR-DASH (TUI Dashboard) - Your Starting Point

### [ ] Constraint-based Layout (like Ratatui)
- **Priority**: P1 - High
- **Effort**: 2-3 weeks
- **Impact**: Major layout system improvement
- **Research**: `libraries/sugar-dash-research.md`

### [ ] StatefulWidget Separation (external state for selection)
- **Priority**: P1 - High
- **Effort**: 1-2 weeks
- **Impact**: Better state management for tables/lists
- **Research**: `libraries/sugar-dash-research.md`

### [ ] Reactive Properties (watch pattern)
- **Priority**: P1 - High
- **Effort**: 1 week
- **Impact**: Auto-calling watchers on property change
- **Research**: `libraries/sugar-dash-research.md`

### [ ] Block Wrapper (composable borders/padding)
- **Priority**: P2 - Medium
- **Effort**: 3-5 days
- **Impact**: Cleaner component composition
- **Research**: `libraries/sugar-dash-research.md`

### [ ] Improved Spinner (explicit tick, not time-based)
- **Priority**: P3 - Low
- **Effort**: 1 day
- **Impact**: Better animation control
- **Research**: `libraries/sugar-dash-research.md`

### [ ] Lifecycle Interface (mount/unmount hooks)
- **Priority**: P2 - Medium
- **Effort**: 2-3 days
- **Impact**: Better component lifecycle
- **Research**: `libraries/sugar-dash-research.md`

### [ ] FlexBox Layout API (justifyContent/alignItems)
- **Priority**: P2 - Medium
- **Effort**: 1-2 weeks
- **Impact**: CSS-like flexbox for TUIs
- **Research**: `libraries/sugar-dash-research.md`

---

## QUICK WINS (Low Effort, High Impact)

### candy-log

### [ ] NO_COLOR / FORCE_COLOR environment support
- **Effort**: 1-2 hours
- **Research**: `libraries/candy-log-research.md`

### [ ] PadLevelText (align level labels)
- **Effort**: 1 hour
- **Research**: `libraries/candy-log-research.md`

### [ ] Fix Level Values to match upstream (-4, 0, 4, 8, 12)
- **Effort**: 30 minutes
- **Research**: `libraries/candy-log-research.md`

### candy-palette

### [ ] CLICOLOR / CLICOLOR_FORCE support
- **Effort**: 2-4 hours
- **Research**: `libraries/candy-palette-research.md`

### [ ] Fix tmux/screen COLORTERM ignoring
- **Effort**: 2-4 hours
- **Research**: `libraries/candy-palette-research.md`

### [ ] TERM=dumb special case handling
- **Effort**: 1-2 hours
- **Research**: `libraries/candy-palette-research.md`

### sugar-toast

### [ ] ESC dismiss support (`withAllowEscToClose`)
- **Effort**: 1 hour
- **Research**: `libraries/sugar-toast-research.md`

### [ ] Fix stack offset bug (yOffset receives 0)
- **Effort**: 1 hour
- **Research**: `libraries/sugar-toast-research.md`

### [ ] Add 9 positions (add MiddleLeft, MiddleRight, MiddleCenter)
- **Effort**: 2-3 hours
- **Research**: `libraries/sugar-toast-research.md`

### sugar-spark

### [ ] Add SGR underline styles (4;1-4;5)
- **Effort**: 1 hour
- **Research**: `libraries/sugar-spark-research.md`

### [ ] Add C0/C1 control code support
- **Effort**: 1-2 hours
- **Research**: `libraries/sugar-spark-research.md`

### candy-mosaic

### [ ] Fix WezTerm detection (TERM_PROGRAM=WezTerm)
- **Effort**: 30 minutes
- **Research**: `libraries/candy-mosaic-research.md`

### candy-vt

### [ ] Add DECSTBM scroll margins
- **Effort**: 2-3 hours
- **Research**: `libraries/candy-vt-research.md`

### [ ] Add Auto-wrap (DECAWM)
- **Effort**: 2 hours
- **Research**: `libraries/candy-vt-research.md`

### [ ] Add subparameter parsing (CSI 38:2::186:93:0m)
- **Effort**: 2-3 hours
- **Research**: `libraries/candy-vt-research.md`

### sugar-skate

### [ ] Add CSV/JSON export
- **Effort**: 1-2 hours
- **Research**: `libraries/sugar-skate-research.md`

### [ ] Add Levenshtein typo suggestions
- **Effort**: 1 hour
- **Research**: `libraries/sugar-skate-research.md`

### candy-flip

### [ ] Replace temp-file I/O with `imagecreatefromstring()`
- **Effort**: 1-2 hours
- **Research**: `libraries/candy-flip-research.md`

### sugar-crumbs

### [ ] Fix broken examples (view/filter/pushDirectory)
- **Effort**: 1 hour
- **Research**: `libraries/sugar-crumbs-research.md`

### [ ] Add separator escaping for titles with ›
- **Effort**: 1 hour
- **Research**: `libraries/sugar-crumbs-research.md`

### candy-hermit

### [ ] Add Item interface + Numbered Items
- **Effort**: 2-3 hours
- **Research**: `libraries/candy-hermit-research.md`

### candy-freeze

### [ ] Add background color support
- **Effort**: 2 hours
- **Research**: `libraries/candy-freeze-research.md`

### [ ] Add ligature flag
- **Effort**: 1 hour
- **Research**: `libraries/candy-freeze-research.md`

### candy-mines

### [ ] Add chord clicks (middle-click on revealed numbers)
- **Effort**: 2-3 hours
- **Research**: `libraries/candy-mines-research.md`

---

## CORE LIBRARY IMPROVEMENTS

### candy-core

### [ ] Elm-Style Subscriptions
- **Priority**: P1 - High
- **Effort**: 3-5 sessions
- **Research**: `libraries/candy-core-research.md`

### [ ] Screen Stack (pushScreen/popScreen)
- **Priority**: P1 - High
- **Effort**: 4-6 sessions
- **Research**: `libraries/candy-core-research.md`

### [ ] Component Composition Framework
- **Priority**: P2 - Medium
- **Effort**: 3-4 sessions
- **Research**: `libraries/candy-core-research.md`

### candy-sprinkles

### [ ] Theme class (Rich-style named style registry)
- **Priority**: P1 - High
- **Effort**: 6-8 hours
- **Research**: `libraries/candy-sprinkles-research.md`

### [ ] String color parsing (`Color::parse("cyan")`)
- **Priority**: P1 - High
- **Effort**: 2-3 hours
- **Research**: `libraries/candy-sprinkles-research.md`

### candy-pty

### [ ] Add `openpty()` FFI binding
- **Priority**: P1 - High
- **Effort**: 1-2 days
- **Research**: `libraries/candy-pty-research.md`

### [ ] Add `waitpid()` FFI binding
- **Priority**: P1 - High
- **Effort**: 1 day
- **Research**: `libraries/candy-pty-research.md`

### candy-wish

### [ ] Context propagation (Context class + update Middleware)
- **Priority**: P1 - High
- **Effort**: Medium
- **Research**: `libraries/candy-wish-research.md`

### [ ] Channel event middleware (ChannelHandler interface)
- **Priority**: P1 - High
- **Effort**: Medium-High
- **Research**: `libraries/candy-wish-research.md`

---

## COMPONENT IMPROVEMENTS

### sugar-bits

### [ ] TextInput `validate_on` timing (blur/changed/submit)
- **Priority**: P1 - High
- **Effort**: 2-3 hours
- **Research**: `libraries/sugar-bits-research.md`

### [ ] TextInput `restrict` regex
- **Priority**: P1 - High
- **Effort**: 1-2 hours
- **Research**: `libraries/sugar-bits-research.md`

### [ ] Table sorting (SortByDesc/SortByAsc)
- **Priority**: P2 - Medium
- **Effort**: 3-4 hours
- **Research**: `libraries/sugar-bits-research.md`

### [ ] Table filtering (WithFiltered per-column)
- **Priority**: P2 - Medium
- **Effort**: 2-3 hours
- **Research**: `libraries/sugar-bits-research.md`

### sugar-prompt

### [ ] Vim keys in MultiSelect (j/k/g/G)
- **Priority**: P1 - High
- **Effort**: 1 day
- **Research**: `libraries/sugar-prompt-research.md`

### [ ] Built-in validators (Required, MinLength, Pattern, Email)
- **Priority**: P1 - High
- **Effort**: 1 day
- **Research**: `libraries/sugar-prompt-research.md`

### [ ] Enhanced KeyMap defaults (j/k nav)
- **Priority**: P1 - High
- **Effort**: 4 hours
- **Research**: `libraries/sugar-prompt-research.md`

### sugar-table

### [ ] StyleFunc for dynamic cell styling
- **Priority**: P1 - High
- **Effort**: 1-2 hours
- **Research**: `libraries/sugar-table-research.md`

### [ ] Flex/Grow column support
- **Priority**: P2 - Medium
- **Effort**: 2-3 hours
- **Research**: `libraries/sugar-table-research.md`

### [ ] Viewport virtualization (large datasets)
- **Priority**: P2 - Medium
- **Effort**: 3-4 hours
- **Research**: `libraries/sugar-table-research.md`

### sugar-charts

### [ ] "Nice numbers" axis labeling
- **Priority**: P1 - High
- **Effort**: Medium
- **Research**: `libraries/sugar-charts-research.md`

### [ ] Sliding window streaming to LineChart
- **Priority**: P1 - High
- **Effort**: Low
- **Research**: `libraries/sugar-charts-research.md`

### [ ] Area fill option to LineChart
- **Priority**: P1 - High
- **Effort**: Low
- **Research**: `libraries/sugar-charts-research.md`

### sugar-veil

### [ ] Backdrop/Dimming
- **Priority**: P1 - High
- **Effort**: 2-3 days
- **Research**: `libraries/sugar-veil-research.md`

### [ ] Animation System (slide-in, easing)
- **Priority**: P1 - High
- **Effort**: 3-4 days
- **Research**: `libraries/sugar-veil-research.md`

### [ ] Border Chrome/Frame wrapper
- **Priority**: P1 - High
- **Effort**: 1-2 days
- **Research**: `libraries/sugar-veil-research.md`

---

## GAMES

### candy-tetris

### [ ] Full SRS with kick tables
- **Priority**: P1 - High
- **Effort**: 4-6 hours
- **Research**: `libraries/candy-tetris-research.md`

### [ ] T-Spin detection
- **Priority**: P2 - Medium
- **Effort**: 3-4 hours
- **Research**: `libraries/candy-tetris-research.md`

### honey-bounce

### [ ] SpringConfig presets (gentle, wobbly, stiff, slow)
- **Priority**: P1 - High
- **Effort**: 2 days
- **Research**: `libraries/honey-bounce-research.md`

### [ ] CubicBezier easing
- **Priority**: P1 - High
- **Effort**: 1.5 days
- **Research**: `libraries/honey-bounce-research.md`

---

## FULL LIBRARY LIST

All 45 research documents available at:
- `/home/sites/sugarcraft/docs/research/libraries/`

### Libraries with Research:

| Library | File Size |
|---------|-----------|
| candy-core | 17KB |
| candy-flip | 16KB |
| candy-freeze | 23KB |
| candy-hermit | 21KB |
| candy-lister | 15KB |
| candy-log | 17KB |
| candy-metrics | 20KB |
| candy-mines | 15KB |
| candy-mold | 18KB |
| candy-mosaic | 24KB |
| candy-palette | 15KB |
| candy-pty | 24KB |
| candy-query | 11KB |
| candy-serve | 12KB |
| candy-shell | 18KB |
| candy-sprinkles | 15KB |
| candy-tetris | 18KB |
| candy-vcr | 23KB |
| candy-vt | 26KB |
| candy-wish | 24KB |
| candy-zone | 14KB |
| honey-bounce | 25KB |
| honey-flap | 11KB |
| sugar-bits | 26KB |
| sugar-boxer | 16KB |
| sugar-calendar | 19KB |
| sugar-charts | 23KB |
| sugar-crumbs | 14KB |
| sugar-crush | 15KB |
| sugar-dash | 25KB |
| sugar-glow | 17KB |
| sugar-post | 25KB |
| sugar-prompt | 20KB |
| sugar-readline | 18KB |
| sugar-skate | 21KB |
| sugar-spark | 13KB |
| sugar-stash | 23KB |
| sugar-stickers | 14KB |
| sugar-table | 19KB |
| sugar-tick | 19KB |
| sugar-toast | 20KB |
| sugar-veil | 21KB |
| sugar-wishlist | 16KB |
| super-candy | 18KB |

---

## NOTES

- **Effort Key**: hours (h), days (d), weeks (w), sessions (s)
- **Priority**: P1 (Critical) > P2 (Important) > P3 (Nice-to-have)
- All research documents contain: detailed findings, code examples, effort estimates, and specific implementation guidance
