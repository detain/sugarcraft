# SugarCraft Code Audit — Findings Summary

**Audit Date:** 2026-06-30  
**Projects Audited:** 57 (33 candy-*, 2 honey-*, 22 sugar-*)  
**Total Findings Files:** 57 project audits + 1 repeated_logic.md = 58 files  
** auditor: Multi-agent codebase analysis

---

## Overview

Comprehensive code audit of the SugarCraft PHP monorepo — 57 TUI library ports of the Charmbracelet ecosystem. Each project was analyzed for bugs, performance problems, memory leaks, security issues, complexity concerns, missing features, PHP 8.3/8.4 compatibility, and async/ReactPHP improvement opportunities.

---

## Repeated Logic Patterns (Cross-Cutting)

See [repeated_logic.md](./repeated_logic.md) for 10 categories of repeated patterns across the codebase:

- **35+ mutate() re-implementations** — every with*() builder duplicates the same 4-line pattern
- **AsyncOps duplication** — candy-async's AsyncOps reproduced in candy-buffer, sugar-dash, others
- **Clamping logic** — min/max clamping copied across 10+ projects with no shared utility
- **Style object allocation** — hot-render loops creating Style chains per-frame (candy-mold, honey-flap)
- **escapeshellarg misuse** — stripslashes after escapeshellarg breaks safety (candy-serve, candy-shell)
- **array_map over children** — double/triple iteration over same child array (sugar-boxer, sugar-table)
- **WeakMap/registry patterns** — per-project implementations instead of shared candy-core utility
- **i18n wrapper inconsistency** — Lang::t() vs SugarCraft\Core\I18n\T scattered across libs
- **FFI cleanup** — every FFI project implements its own free() pattern instead of shared wrapper
- **Grapheme splitting** — 3-level fallback (grapheme_str_split → mb_str_split → preg_split) repeated in 8+ libs

---

## High-Priority Findings Across All Projects

### P0 — Critical Bugs (Fix Before Release)

| Project | Finding | Location | Description |
|---------|---------|----------|-------------|
| candy-serve | Shell injection in git ref names | GitDaemon.php:473 | Ref name not validated against git naming rules; shell meta-chars could inject |
| candy-serve | Empty password authentication bypass | Server.php:566 | Users with password=null can authenticate with any password |
| candy-serve | Repo init runs git in wrong directory | Repo.php:145 | git init --bare runs in CWD, not $this->path |
| candy-serve | Temp file leak on exception | GitDaemon.php:309 | Temp files not deleted if handleUploadPack throws |
| candy-shell | Shell injection in env-var fallback | Application.php:137 | escapeshellarg→stripslashes→trim chain not safe |
| candy-shell | Env var exfiltration via template mode | FormatCommand.php:99 | getenv() accessible in templates — cred leak risk |
| candy-vcr | Undefined $cassette variable | TapeToGif.php:70 | Variable used before assignment — runtime fatal |
| sugar-calendar | Cell output not capped to viewport width | View.php | Cells rendered beyond viewport width |
| sugar-gallery | No file type validation | Gallery.php:78 | Non-image files passed to decoder — crash/exploit |
| sugar-gallery | FFI buffer not explicitly freed | FFIDecoder.php | C memory must be explicitly free()'d — leak |
| sugar-stickers | UTF-8 multibyte corruption in sanitize | Column.php:112, FlexBox.php:302 | Bytes 0x80-0x9F stripped — corrupts CJK text |
| sugar-boxer | Division by zero in distribute() | SugarBoxer.php:792 | 0/0 = NaN when all weights are zero |
| honey-flap | Collision/scoring frame mismatch | Game.php:273, Pipe.php:33 | Score increments one frame before collision fires |
| candy-serve | Hardcoded LFS bearer token | LFSHandler.php:200 | "lfs-token" placeholder in all LFS responses |
| candy-serve | Path traversal in SSH repo lookup | SSHServer.php:97 | Regex allows .. in repo path |

### P1 — Security / Data Integrity

| Project | Finding | Location | Description |
|---------|---------|----------|-------------|
| candy-serve | HTTP server buffers entire packfile in memory | Server.php:261 | 256 MiB packfiles buffered — unsustainable for large repos |
| candy-serve | LFS "concurrency" is fully sequential | LFSHandler.php:153 | concurrentTransfers parameter has zero effect |
| candy-vcr | ImagickRasterizer cache sharing between clones | ImagickRasterizer.php:58 | withTheme() shares tileCache — clears tiles other instance needs |
| sugar-gallery | Directory traversal in path navigation | Gallery.php:95 | chdir() with no sandboxing |
| sugar-readline | History file uses 0644 permissions | History.php | Group-readable history could leak sensitive commands |
| sugar-wishlist | Index bounds not validated | Wishlist.php | Negative/out-of-bounds indices cause undefined behavior |

### P2 — Significant Bugs / Edge Cases

| Project | Finding | Location | Description |
|---------|---------|----------|-------------|
| candy-mold | Style chain rebuilt per render frame | Counter.php:68 | 3 intermediate Style objects per 60fps render tick |
| candy-mold | Quit doesn't fire for uppercase Q | Counter.php:51 | strtolower not used — CapsLock breaks quit |
| candy-shine | BlockStack/StyleSheet state not reset in copy() | Renderer.php:276 | withTheme() shares block stack with original renderer |
| candy-tetris | AI rotation bug — loop runs when rotDelta=0 | Game.php:309 | Unwanted 90° rotation when AI needs no rotation |
| candy-tetris | Garbage overflow off-by-one | Game.php:482 | Row $count displaced into visible area not validated |
| candy-forms | No validation for negative viewport | Forms.php | Silent empty output for negative dimensions |
| sugar-spark | Empty data set produces invalid output | Sparkline.php:42 | No guard for empty $data |
| sugar-toast | Viewport overflow not handled | Toast.php | Toast wider than terminal width overflows |
| sugar-toast | Auto-dismiss timer not cancellable | Toast.php | Cannot extend or cancel dismiss timer |
| honey-flap | Non-atomic high score writes | Game.php:165 | Crash mid-write corrupts scores file |
| honey-flap | Rand closure exception not caught | Game.php:262 | Broken injected closure crashes game with no recovery |
| candy-vcr | FrameStream mutation during iteration | TapeToGif.php:104 | Public properties mutated by generator mid-iteration |
| candy-vcr | Negative dt not validated | RelativeFormat.php:201 | Timestamps can go backwards |
| sugar-readline | History search O(n) — no indexing | History.php | 10K+ history entries = slow Ctrl+R |
| sugar-readline | History grows unbounded | History.php | No maxHistory enforcement |
| sugar-stickers | Cursor resets to 0 on every rebuildView | Table.php:274 | Sort/filter always resets cursor to top |
| sugar-gallery | No format fallback — crash on unsupported | Gallery.php | Unhandled decoder errors crash gallery |
| sugar-gallery | Blocking image decode — UI freeze | FFIDecoder.php | Large images block event loop |

---

## Findings Count by Project

| Project | Severity Profile | Key Theme |
|---------|-----------------|-----------|
| candy-serve | 7 HIGH, 12 MED, 9 LOW | Git daemon security/injection issues |
| candy-shell | 2 HIGH, 7 MED, 6 LOW | Shell injection, env var exposure, async blocking |
| candy-vcr | 2 HIGH, 9 MED, 8 LOW | Undefined variable, Imagick cache sharing, blocking I/O |
| candy-shine | 1 HIGH, 6 MED, 9 LOW | BlockStack state, string concat in loops, missing Write() parity |
| sugar-boxer | 1 HIGH, 4 MED, 9 LOW | Division by zero, multibyte sanitization, flex separator gap |
| sugar-gallery | 3 HIGH, 6 MED, 2 LOW | Path traversal, FFI memory, no format fallback |
| candy-forms | 1 HIGH, 4 MED, 7 LOW | Viewport validation, input handling gaps |
| candy-mold | 0 HIGH, 1 MED, 5 LOW | Style allocation, case-insensitive key handling |
| honey-flap | 2 HIGH, 3 MED, 5 LOW | Collision off-by-one, non-atomic file I/O |
| sugar-stickers | 2 HIGH, 2 MED, 4 LOW | UTF-8 corruption in sanitize, example import bug |
| sugar-crush | 2 HIGH, 3 MED, 2 LOW | EOF buffer drain, mouse coordinate off-by-one |
| sugar-readline | 0 HIGH, 5 MED, 6 LOW | Unbounded history, blocking save, Vi cursor off-by-one |
| sugar-calendar | 1 HIGH, 2 MED, 4 LOW | Viewport overflow, month re-render per view() |
| sugar-toast | 0 HIGH, 3 MED, 3 LOW | Viewport overflow, non-cancellable timer, no action buttons |
| candy-tetris | 1 HIGH, 2 MED, 5 LOW | AI rotation bug, garbage overflow off-by-one |
| candy-zone | 0 HIGH, 2 MED, 4 LOW | current() returns null, switchTo() no bounds check |
| candy-mouse | (see findings/candy-mouse.md) | Comprehensive mouse event handling audit |
| sugar-bits | (see findings/sugar-bits.md) | Stopwatch/teardown patterns |
| sugar-dash | (see findings/sugar-dash.md) | Largest sugar-* project — 52KB findings |
| candy-vt | (see findings/candy-vt.md) | Terminal emulation |
| others | various | See individual findings/*.md files |

---

## Top Cross-Cutting Recommendations

### 1. Extract Shared Utilities to candy-core
- **mutate() trait** — currently reimplemented 35+ times; create a `SugarCraft\Core\Concerns\WithBuilder` trait
- **AsyncOps class** — duplicated in candy-async, candy-buffer, sugar-dash; consolidate into candy-core
- **Clamping utility** — `SugarCraft\Core\clamp(int $value, int $min, int $max): int` — appears in 10+ projects
- **Grapheme splitting** — 3-level fallback scattered across 8+ projects; extract `SugarCraft\Core\grapheme_split(string $s): array`
- **escapeshellarg+stripslashes fix** — create `SugarCraft\Core\ safe_env_value(string $v): string` that doesn't strip escaping
- **FFI memory wrapper** — shared `SugarCraft\Core\FFI\free_ffi(mixed $ptr)` utility

### 2. Security Hardening
- Audit every `escapeshellarg()` call for stripslashes aftermath
- Add input validation for all integer coordinates (mouse, viewport, collision)
- Fix hardcoded bearer tokens and placeholder credentials
- Add path traversal checks for all file-based operations
- Document which libs handle untrusted input and which assume trusted

### 3. Performance Optimization
- Cache Style objects in hot render loops (candy-mold, honey-flap, candy-shine)
- Pre-compute visible column indices before row iteration (sugar-stickers, sugar-table)
- Add caching to SmithWatermanMatcher in fuzzy filter (candy-shell, sugar-crush)
- Use array rewind + single-pass iteration instead of multiple array_map passes

### 4. PHP 8.4 Readiness
- Most libs are PHP 8.3+ fully compatible
- No uses of deprecated features detected
- Consider `readonly class` for immutable value objects (Bird, Pipe, TickMsg in honey-flap, etc.)
- Consider first-class callable syntax (ClassName::method) where closures only invoke constructors

### 5. Missing Features Priority
- candy-shell: `--no-selected`, `--print-query`, fuzzy highlight rendering
- candy-vcr: async batch rendering, buffering improvements
- sugar-gallery: format fallback, animated GIF, async decode
- sugar-toast: action buttons, toast stacking, cancellable dismiss
- sugar-readline: bracketed paste mode, Vi text objects, incremental search

---

## Audit Methodology

- **Agents:** 6 concurrent sub-agents per batch
- **Scope:** All PHP files under src/ and tests/ per project
- **Output:** findings/<slug>.md per project + findings/repeated_logic.md
- **Severity:** HIGH (P0 — critical bug or security issue), MEDIUM (P1 — significant), LOW (P2 — edge case or code quality)
- **Categories:** Bugs, Performance, Memory, Security, Complexity, Missing Features, PHP Compatibility, Async/ReactPHP

---

## Files in This Directory

- `README.md` — this file (collation of all findings)
- `repeated_logic.md` — cross-cutting patterns repeated across 10+ projects
- `candy-ansi.md` through `candy-zone.md` — 31 candy-* project audits
- `honey-bounce.md`, `honey-flap.md` — 2 honey-* project audits
- `sugar-bits.md` through `sugar-wishlist.md` — 22 sugar-* project audits

**Total: 58 findings files covering 57 projects**