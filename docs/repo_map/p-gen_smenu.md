# p-gen/smenu

## Metadata
- **URL:** https://github.com/p-gen/smenu
- **Language:** C
- **Stars:** ~900+ (estimated based on repo age and activity)
- **License:** Mozilla Public License 2.0 (MPL 2.0)
- **Version:** 1.5.0
- **Description:** A terminal-based selection filter that reads words from stdin or files and presents them in a scrolling window with cursor-based selection. Designed for menu creation and general text selection in terminal UIs.

## Feature List
- **Input Modes:** Read from stdin or file; supports word/token parsing from structured or unstructured text
- **Display Modes:**
  - Line mode (`-l`) — horizontal single-line items
  - Column mode (`-c`) — tabular grid layout
  - Tabulate mode (`-t`) — aligned columnar output
- **Selection Methods:**
  - Keyboard navigation (arrow keys, Home/End, Page Up/Down)
  - Mouse support (click to position, scroll wheel)
  - Direct access by index (`-N` option)
- **Search/Filter:**
  - Prefix search (natural TST behavior)
  - Fuzzy search (non-consecutive, case-insensitive)
  - Substring search
  - Incremental or forgetful search modes
- **Tagging & Pinning:** Multi-select via tagging; pinned items persist and are output in order
- **Text Transformation:**
  - sed-like substitutions (`-s` option)
  - Left/right/center alignment
  - Output width constraints (`-w`)
- **Visual Features:**
  - Scrollbars (horizontal/vertical)
  - Color/attribute customization (bold, dim, underline, reverse, italic, etc.)
  - Custom gutters and separators
  - UTF-8 double-width character support
  - Extended grapheme cluster support (experimental)
- **Timeout Modes (`-x`/`-X`):** Auto-select or quit after N seconds
- **Cursor Behavior:** Does NOT clear screen on start/end; overlays at cursor position
- **Context-based Options:** Uses `ctxopt` framework for context-sensitive option handling

## Key Classes and Methods

### Core Data Structures (smenu.h)

**word_t** — Represents a selectable word/item:
- `start`, `end` — screen position bounds
- `mb` — number of UTF-8 glyphs
- `len` — byte length of original string
- `str` — display string (may be truncated)
- `orig` — original full string
- `bitmap` — matched character positions for search highlighting
- `tag_order`, `tag_id` — tagging metadata
- `is_matching`, `is_last`, `is_selectable`, `is_numbered` — flags

**win_t** — Window/display state:
- `start`, `end` — first/last word indices visible
- `max_lines`, `max_cols` — dimensions
- `cur_line` — cursor line (1+ relative)
- `tab_mode`, `col_mode`, `line_mode` — display mode flags
- `cursor_attr`, `marked_attr`, `bar_attr`, `message_attr` — attribute groups

**term_s** — Terminal capabilities (terminfo-derived):
- `ncolumns`, `nlines` — terminal dimensions
- `has_*` boolean flags for capabilities (cuu1, cud1, cub1, cup, sc, rc, setf, setaF, etc.)
- `color_method` — CLASSIC (0-7) vs ANSI color interpretation

**search_data_s** — Search state:
- `buf` — current search buffer
- `found` — match position tracking
- `bitmap_affinity` — START/END/NONE affinity for fuzzy matching

### Core Modules

**index.c / index.h** — Ternary Search Tree (TST) implementation:
- `tst_insert()` — Insert word with data pointer into TST
- `tst_search()` — Exact search in TST
- `tst_prefix_search()` — Prefix-based search (used by `/` command)
- `tst_fuzzy_traverse()` — Fuzzy search traversal
- `tst_substring_traverse()` — Substring search traversal
- `tst_search_in_word()` — Search within a single word

**list.c / list.h** — Doubly-linked list:
- `ll_new()`, `ll_init()` — list creation
- `ll_append()` — append node
- `ll_sort()` — sort with comparator
- `ll_find()`, `ll_delete()` — search/delete
- `ll_free()`, `ll_destroy()` — memory cleanup

**utf8.c / utf8.h** — UTF-8 text handling:
- `langinfo_t` — locale information structure
- Double-width character width calculation
- Extended grapheme cluster handling

**xmalloc.c / xmalloc.h** — Memory allocation wrappers with fatal-on-failure

**ctxopt.c / ctxopt.h** — Contextual option parsing system (v0.9.15+)

**tinybuf.h** — Dynamic array macros

**smenu.c** — Main entry (~17,477 lines):
- Terminal initialization and capability probing
- Word reading and parsing from input
- TST indexing of all words
- Main event loop (keyboard/mouse/timeout input)
- Window rendering with scrolling calculations
- Search mode handling with bitmap tracking
- Signal handlers (WINCH, ALRM for timers)

## Notable Algorithms / Named Patterns

### Ternary Search Tree (TST)
Used for word indexing and search. Each terminal node stores a linked list of positions where that word occurs in the input array. This enables:
- O(k) prefix search where k = key length
- Efficient fuzzy/substring search via tree traversal
- Multiple occurrences of same word tracked via position lists

### Bitmap Tracking for Search Highlighting
Each `word_t` contains a `bitmap` field where each bit corresponds to a glyph position that matches the current search. This allows efficient rendering of highlighted matches without re-scanning.

### Fuzzy Search Algorithm
Uses a search list of `sub_tst_t` nodes. Each node stores an array of TST nodes representing potential matches. As each glyph is entered:
1. First glyph searched from TST root
2. First children of matches added to array in search list node
3. Next glyph searched in sub-TST arrays from previous node
4. Process repeats, building match candidate sets

### Bit Array Macros (public domain, by Scott Dudley, Auke Reitsma, Bob Stout)
```c
#define BIT_OFF(a, x) ((void)((a)[(x) >> SHIFT] &= ~(1 << ((x) & MASK))))
#define BIT_ON(a, x) ((void)((a)[(x) >> SHIFT] |= (1 << ((x) & MASK))))
#define BIT_FLIP(a, x) ((void)((a)[(x) >> SHIFT] ^= ^= (1 << ((x) & MASK))))
#define BIT_ISSET(a, x) ((a)[(x) >> SHIFT] & (1 << ((x) & MASK))))
```
Supports CHAR_BIT of 8, 16, or 32 via SHIFT calculation.

### Timer/Timeout System
Uses `setitimer()` with `SECOND/FREQ` granularity (100ms ticks via `TCK`). Multiple independent timers for search, forgotten-help, window-resize, direct-access.

## Strengths
- **Non-destructive:** Does not clear terminal; overlays at cursor position
- **Flexible display:** Line, column, and tabulate modes in one tool
- **Powerful search:** Three search modes (prefix, fuzzy, substring) with incremental/forgetful variants
- **Mouse support:** Full VT200, SGR, and URXVT mouse protocol support
- **UTF-8 complete:** Handles double-width chars and grapheme clusters
- **Tagging system:** Multi-select with pinned output ordering
- **Timeout features:** Useful for automated scripts and kiosks
- **Portable:** Uses standard terminfo; works on all Unix-like systems
- **Extensive test suite:** 38 test directories covering all features

## Weaknesses
- **Single-user focus:** No multi-user or server-oriented features
- **No async I/O:** Blocking read architecture limits use in event-driven apps
- **C-only:** No library bindings for other languages
- **Complex codebase:** ~17,500 lines in single main file; harder to fork/modify
- **Bounded word limits:** Max word length 512 bytes, max 16M words (compile-time limits)
- **RTL languages incomplete:** Right-to-left alignment not properly supported
- **Ctxopt migration:** Post v0.9.15 option system changes may break existing scripts
- **No built-in caching:** Re-parses input on every invocation

## SugarCraft Mapping

### sugar-bits (Low-level components)
The TST implementation in `index.c` maps to tree-based data structures. The bitmap tracking for search matches is a notable pattern. However, `smenu` is a full application, not a library, so this is only a partial conceptual match.

**Relevant components:**
- Ternary Search Tree index structure
- Bit array utilities
- Linked list implementation

### sugar-prompt / candy-shine (TUI elements)
`smenu` is itself a TUI selection widget. SugarCraft has no direct equivalent yet for terminal-based selection menus. A port would be a significant undertaking requiring:
- Terminal capability detection
- Keyboard/mouse event handling
- Multiple display layout algorithms
- Search with highlighting

**What would map:**
- Word/tag selection model pattern
- Scrollable window rendering
- Search with real-time highlighting

### honey-bounce (Animation/motion)
The scrolling behavior and cursor movement in `smenu` could inform animation timing, but there's no direct SugarCraft equivalent.

### Overall Assessment
`smenu` is a **standalone terminal application**, not a reusable library. SugarCraft currently focuses on TUI *libraries* (components for building apps), not finished applications. A SugarCraft port would likely be a `sugar-select` or `candy-pick` component that provides the selection filtering capability for use within other PHP TUI applications — essentially extracting the core algorithm (TST-based filtering with fuzzy search) into a reusable library.

**SugarCraft gap identified:** No existing lib handles TST-based prefix/fuzzy filtering of large word lists — this would be a novel contribution.

## Analysis

`smenu` is a terminal-based selection filter that fills a specific niche between simple `grep` filtering and full-fledged menu libraries. Its core innovation is the use of a Ternary Search Tree for indexing input words, which enables extremely fast prefix, fuzzy, and substring search even on large word lists (millions of items). The search highlighting via bitmap tracking is particularly elegant — each word stores a bitmap of matched positions, so rendering just iterates the bitmap rather than re-evaluating matches.

The application is remarkably feature-complete for its purpose: it handles UTF-8 properly (including double-width and grapheme clusters), supports mouse input in multiple protocols, provides flexible display modes (line/column/tabulate), and includes tagging/pinning for multi-select scenarios. The non-destructive overlay behavior (no screen clear) makes it pleasant for interactive shell use.

The architecture is monolithic — all ~17,500 lines live in `smenu.c` with headers for each module. This makes it easy to understand the whole system but harder to extract components as libraries. The TST and search algorithms are the most reusable parts; the terminal I/O and display logic are highly application-specific.

For SugarCraft, the most valuable port would be the **search algorithm foundation** (TST-based prefix/fuzzy search with bitmap highlighting) rather than the full application. This could become `sugar-sift` or similar for fast in-memory text filtering with fuzzy matching capabilities.
