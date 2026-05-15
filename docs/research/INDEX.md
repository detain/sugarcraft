# SugarCraft Research Index

> Master index of all research findings across the SugarCraft monorepo.
> Each section links to detailed research documents with prioritized recommendations.

---

## Quick Start

To implement an item:
1. Find the feature you want in the index below
2. Click the link to read the full research document
3. Look for the **Priority** and **Effort** columns
4. Check the **Specific Code Patterns** section for implementation guidance

---

## Library Research Documents

### Core Libraries

| Library | Research Doc | Top Priority Items |
|---------|--------------|-------------------|
| **candy-core** | [research/libraries/candy-core-research.md](libraries/candy-core-research.md) | 1. Elm-Style Subscriptions, 2. Screen Stack, 3. Component Composition |
| **candy-sprinkles** | [research/libraries/candy-sprinkles-research.md](libraries/candy-sprinkles-research.md) | 1. Theme class, 2. String color parsing, 3. Layout spacing |
| **candy-pty** | [research/libraries/candy-pty-research.md](libraries/candy-pty-research.md) | 1. openpty() FFI, 2. waitpid() FFI, 3. Raw mode support |
| **candy-vt** | [research/libraries/candy-vt-research.md](libraries/candy-vt-research.md) | 1. DECSTBM margins, 2. Auto-wrap, 3. Subparam parsing |
| **candy-vcr** | [research/libraries/candy-vcr-research.md](libraries/candy-vcr-research.md) | 1. stats CLI, 2. RegexAssertion, 3. Gzip compression |

### Dashboard & Components

| Library | Research Doc | Top Priority Items |
|---------|--------------|-------------------|
| **sugar-dash** | [research/libraries/sugar-dash-research.md](libraries/sugar-dash-research.md) | 1. Constraint layout, 2. StatefulWidget separation, 3. Reactive props |
| **sugar-bits** | [research/libraries/sugar-bits-research.md](libraries/sugar-bits-research.md) | 1. validate_on timing, 2. restrict regex, 3. Table sorting/filtering |
| **sugar-prompt** | [research/libraries/sugar-prompt-research.md](libraries/sugar-prompt-research.md) | 1. Vim keys, 2. Built-in validators, 3. Enhanced KeyMap |
| **sugar-table** | [research/libraries/sugar-table-research.md](libraries/sugar-table-research.md) | 1. StyleFunc, 2. Flex columns, 3. Viewport virtualization |
| **sugar-charts** | [research/libraries/sugar-charts-research.md](libraries/sugar-charts-research.md) | 1. Nice ticks, 2. Streaming, 3. Area fill |
| **sugar-veil** | [research/libraries/sugar-veil-research.md](libraries/sugar-veil-research.md) | 1. Backdrop, 2. Animation, 3. Border chrome |
| **sugar-stickers** | [research/libraries/sugar-stickers-research.md](libraries/sugar-stickers-research.md) | 1. Viewport, 2. Sticky via FlexBox, 3. Scrollbar |
| **sugar-boxer** | [research/libraries/sugar-boxer-research.md](libraries/sugar-boxer-research.md) | 1. Box styles enum, 2. Text alignment, 3. Box titles |
| **sugar-crumbs** | [research/libraries/sugar-crumbs-research.md](libraries/sugar-crumbs-research.md) | 1. Fix examples, 2. Closable interfaces, 3. Click regions |

### Input & Readline

| Library | Research Doc | Top Priority Items |
|---------|--------------|-------------------|
| **sugar-readline** | [research/libraries/sugar-readline-research.md](libraries/sugar-readline-research.md) | 1. History interface, 2. ↑/↓ navigation, 3. Vi/Emacs mode |
| **sugar-calendar** | [research/libraries/sugar-calendar-research.md](libraries/sugar-calendar-research.md) | 1. Date range, 2. Focus nav, 3. Event store |
| **sugar-toast** | [research/libraries/sugar-toast-research.md](libraries/sugar-toast-research.md) | 1. ESC dismiss, 2. Stack fix, 3. Progress toasts |
| **sugar-spark** | [research/libraries/sugar-spark-research.md](libraries/sugar-spark-research.md) | 1. C0/C1 codes, 2. SGR styles, 3. JSON output |

### Shell & Apps

| Library | Research Doc | Top Priority Items |
|---------|--------------|-------------------|
| **candy-shell** | [research/libraries/candy-shell-research.md](libraries/candy-shell-research.md) | 1. Auto-discovery, 2. ValueEnum options, 3. FlagSpec trait |
| **candy-log** | [research/libraries/candy-log-research.md](libraries/candy-log-research.md) | 1. NO_COLOR env, 2. PadLevelText, 3. Level values |
| **candy-wish** | [research/libraries/candy-wish-research.md](libraries/candy-wish-research.md) | 1. Context propagation, 2. Channel events, 3. Session metadata |
| **sugar-wishlist** | [research/libraries/sugar-wishlist-research.md](libraries/sugar-wishlist-research.md) | 1. proxy_jump, 2. SSH config import, 3. Identity files |
| **candy-hermit** | [research/libraries/candy-hermit-research.md](libraries/candy-hermit-research.md) | 1. Item interface, 2. Filter injection, 3. Persistent history |

### Rendering & Media

| Library | Research Doc | Top Priority Items |
|---------|--------------|-------------------|
| **sugar-glow** | [research/libraries/sugar-glow-research.md](libraries/sugar-glow-research.md) | 1. Pygments highlighting, 2. Enhanced themes, 3. File watching |
| **candy-freeze** | [research/libraries/candy-freeze-research.md](libraries/candy-freeze-research.md) | 1. Bg colors, 2. Lang detection, 3. VS Code themes |
| **candy-mosaic** | [research/libraries/candy-mosaic-research.md](libraries/candy-mosaic-research.md) | 1. WezTerm fix, 2. Image deletion, 3. Quarter-block |
| **candy-zone** | [research/libraries/candy-zone-research.md](libraries/candy-zone-research.md) | 1. Hover tracking, 2. Drag detection, 3. Click count |
| **candy-palette** | [research/libraries/candy-palette-research.md](libraries/candy-palette-research.md) | 1. CLICOLOR, 2. Terminfo Tc, 3. tmux handling |
| **candy-metrics** | [research/libraries/candy-metrics-research.md](libraries/candy-metrics-research.md) | 1. Histogram buckets, 2. Metric describe, 3. UpDownCounter |

### Games & Fun

| Library | Research Doc | Top Priority Items |
|---------|--------------|-------------------|
| **candy-tetris** | [research/libraries/candy-tetris-research.md](libraries/candy-tetris-research.md) | 1. Full SRS, 2. T-Spin detection, 3. B2B bonus |
| **candy-mines** | [research/libraries/candy-mines-research.md](libraries/candy-mines-research.md) | 1. Chord clicks, 2. Win detection, 3. Timer precision |
| **honey-bounce** | [research/libraries/honey-bounce-research.md](libraries/honey-bounce-research.md) | 1. SpringConfig presets, 2. CubicBezier, 3. SpringChain |
| **honey-flap** | [research/libraries/honey-flap-research.md](libraries/honey-flap-research.md) | 1. Bird rotation, 2. Collision tests, 3. Variable gap |
| **candy-flip** | [research/libraries/candy-flip-research.md](libraries/candy-flip-research.md) | 1. imagecreatefromstring, 2. Frame timing, 3. Floyd-Steinberg |

### Utilities

| Library | Research Doc | Top Priority Items |
|---------|--------------|-------------------|
| **sugar-skate** | [research/libraries/sugar-skate-research.md](libraries/sugar-skate-research.md) | 1. JSON export, 2. Levenshtein, 3. TTL |
| **sugar-stash** | [research/libraries/sugar-stash-research.md](libraries/sugar-stash-research.md) | 1. Context help, 2. Branch ops, 3. Diff panel |
| **sugar-crush** | [research/libraries/sugar-crush-research.md](libraries/sugar-crush-research.md) | 1. Session persistence, 2. Streaming UI, 3. Context compaction |
| **sugar-post** | [research/libraries/sugar-post-research.md](libraries/sugar-post-research.md) | 1. TUI infra, 2. Vim keys, 3. Mastodon API |
| **candy-query** | [research/libraries/candy-query-research.md](libraries/candy-query-research.md) | 1. Schema columns, 2. Pagination, 3. Cell editing |
| **candy-lister** | [research/libraries/candy-lister-research.md](libraries/candy-lister-research.md) | 1. FilterFunc interface, 2. FuzzyMatch, 3. Match indices |
| **super-candy** | [research/libraries/super-candy-research.md](libraries/super-candy-research.md) | 1. Copy/move, 2. Bookmarks, 3. Preview |
| **candy-serve** | [research/libraries/candy-serve-research.md](libraries/candy-serve-research.md) | 1. Interactive TUI, 2. Repo browser, 3. HTTP server |
| **candy-mold** | [research/libraries/candy-mold-research.md](libraries/candy-mold-research.md) | 1. editorconfig, 2. CI workflow, 3. configure.php |
| **sugar-tick** | [research/libraries/sugar-tick-research.md](libraries/sugar-tick-research.md) | 1. CSV export, 2. Tags, 3. Gaps detection |

---

## Top 20 Quick Wins (Low Effort, High Impact)

| # | Library | Feature | Effort | Research Link |
|---|---------|---------|--------|---------------|
| 1 | candy-log | NO_COLOR env support | 1-2h | [link](libraries/candy-log-research.md) |
| 2 | candy-log | PadLevelText | 1h | [link](libraries/candy-log-research.md) |
| 3 | candy-palette | CLICOLOR env support | 2-4h | [link](libraries/candy-palette-research.md) |
| 4 | sugar-toast | ESC dismiss | 1h | [link](libraries/sugar-toast-research.md) |
| 5 | sugar-toast | Stack offset fix | 1h | [link](libraries/sugar-toast-research.md) |
| 6 | sugar-spark | SGR underline styles | 1h | [link](libraries/sugar-spark-research.md) |
| 7 | sugar-spark | C0/C1 codes | 1-2h | [link](libraries/sugar-spark-research.md) |
| 8 | candy-hermit | Item interface | 2-3h | [link](libraries/candy-hermit-research.md) |
| 9 | sugar-crumbs | Fix examples | 1h | [link](libraries/sugar-crumbs-research.md) |
| 10 | sugar-crumbs | Separator escaping | 1h | [link](libraries/sugar-crumbs-research.md) |
| 11 | candy-freeze | Background colors | 2h | [link](libraries/candy-freeze-research.md) |
| 12 | candy-freeze | Ligature flag | 1h | [link](libraries/candy-freeze-research.md) |
| 13 | candy-mosaic | WezTerm detection fix | 0.5h | [link](libraries/candy-mosaic-research.md) |
| 14 | candy-vt | DECSTBM margins | 2-3h | [link](libraries/candy-vt-research.md) |
| 15 | candy-vt | Auto-wrap DECAWM | 2h | [link](libraries/candy-vt-research.md) |
| 16 | sugar-skate | CSV/JSON export | 1-2h | [link](libraries/sugar-skate-research.md) |
| 17 | sugar-skate | Levenshtein suggestions | 1h | [link](libraries/sugar-skate-research.md) |
| 18 | candy-flip | imagecreatefromstring | 1-2h | [link](libraries/candy-flip-research.md) |
| 19 | candy-flip | Per-frame timing | 3-4h | [link](libraries/candy-flip-research.md) |
| 20 | sugar-mines | Chord clicks | 2-3h | [link](libraries/candy-mines-research.md) |

---

## Feature Categories

### Layout & Compositing
- **sugar-dash**: Constraint layout, StatefulWidget separation
- **sugar-veil**: Backdrop, animation, overlay stacking
- **sugar-boxer**: Multiple box styles, text alignment, titles
- **sugar-stickers**: Viewport component, sticky positioning

### Data Visualization
- **sugar-charts**: Nice ticks, streaming, area fills
- **sugar-table**: StyleFunc, flex columns, virtualization
- **sugar-toast**: Progress toasts, max concurrent

### Input & Forms
- **sugar-prompt**: Built-in validators, vim keys, fuzzy suggestions
- **sugar-readline**: History persistence, auto-suggest, vi mode
- **sugar-bits**: validate_on timing, restrict regex, table sorting
- **sugar-calendar**: Date range picker, event store, localization

### Terminal Capabilities
- **candy-vt**: Scrollback buffer, subparam parsing, cursor shapes
- **candy-pty**: openpty FFI, waitpid FFI, raw mode
- **candy-zone**: Hover tracking, drag detection, click count
- **candy-palette**: CLICOLOR, terminfo Tc, color space handling

### App-Level
- **candy-shell**: Auto-discovery, ValueEnum, FlagSpec trait
- **sugar-stash**: Diff panel, branch ops, context help
- **sugar-crush**: Session persistence, streaming UI, context compaction
- **super-candy**: Copy/move, bookmarks, preview

### Games
- **candy-tetris**: Full SRS, T-Spin detection, B2B bonus
- **candy-mines**: Chord clicks, improved win detection
- **honey-bounce**: SpringConfig presets, CubicBezier, SpringChain
- **honey-flap**: Bird rotation, collision tests

---

## How to Use This Document

1. **Browse by Category**: Find the category that matches your interest
2. **Check Quick Wins**: Low effort items at the top of this doc
3. **Read Full Research**: Click any link to see detailed analysis
4. **Implement**: Each research doc has specific code patterns and effort estimates

---

*Last updated: 2026-05-13*
*Generated by: OpenCode researcher subagents*
