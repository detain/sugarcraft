# Audit: sugar-gallery

**Library:** SugarCraft/sugar-gallery
**Date:** 2026-06-30 — **RETRACTED 2026-10-23 (docs-fix-1023 / dfix/gallery), see Erratum**

---

## Erratum (2026-10-23)

The audit below was written against a library that **does not exist**. Its
cited files — `src/Gallery.php`, `src/FFIDecoder.php` — have never existed in
this repo at any commit, and its premise that sugar-gallery "is a port of
`charmbracelet/bubbles/gallery`" is false: `docs/MATCHUPS.md` records sugar-gallery
as an **original SugarCraft component** (no 1:1 Go counterpart), and upstream has
no `gallery` bubble at all. Every "Finding" (1–15: magic-byte validation,
`chdir()` traversal, FFI buffer frees, blocking decode, …) describes phantom code
and is **withdrawn in full**. `findings/plan_sugar-gallery.md` already recorded
this contradiction ("Gallery.php and FFIDecoder.php do not exist"); this file was
the stale half.

## What the library actually is

Three widgets plus one guard, all immutable, all renderer-agnostic:

- `src/PosterGrid.php` — 2-D virtualized, sparse, owner-paged poster grid
  (absolute-indexed cards, `visibleRange()`/`needsFetch()` need-range pattern).
- `src/Rail.php` — horizontal carousel (browse rows).
- `src/PosterCard.php` — one tile (plain/styled title, inline ANSI poster or an
  overlay image + marker id, progress).
- `src/AnsiGuard.php` — the SGR-only trust boundary for verbatim-echoed bytes.
- `src/Lang.php` + `lang/*.php` — i18n facade, 16-locale family (one key: `rail.empty`).

There is no file-system browsing, no `chdir()`, and no FFI anywhere in the lib.

## Live findings ledger — docs-review-1023 (2026-10-23, persona: gallery review)

Verdict 0 BLOCKER / 5 MAJOR / 11 minor / 4 nit — all fixed by the `dfix/gallery`
lane (this commit); pins named per row.

| # | Finding | Disposition |
|---|---------|-------------|
| M1 | README `ImageOverlay::MAX_IMAGES` cited as 6400 | fixed — 6398 (const truth, sugar-core) |
| M2 | `lang/en.php` dormant phantom: zero `Lang::t` callers | **wired** — `Rail` empty state via new `src/Lang.php` + 16-locale backfill + `tests/LocaleParityTest.php` |
| M3 | en.php docblock claimed "charmbracelet/gum i18n entry points" | fixed — truthful origin notes |
| M4 | `Rail` heading emitted `title` verbatim (terminal injection through the network pipeline) | **fixed** — `AnsiGuard::stripControls()` on the heading + per-line rail-width clamp; adversarial pins in `tests/RailGuardTest.php` |
| M5 | Poster bytes (`withPoster`) enter the frame ungarded | **fixed** — documented caller-law + new coercing `withSafePoster()` (per-line SGR sweep); pins in `tests/PosterCardPosterSweepTest.php` |
| m1 | README "← → move within a row" false at row edges | fixed (doc truth + `tests/PosterGridTest.php` walk pin) |
| m2 | `anyInBounds` example annotated with the string id, not the `Zone` object | fixed |
| m3 | Rail head unbounded to rail width | fixed with M4 (`fitHead()` + pins) |
| m4 | empty grid `render()` returning `''` undocumented | fixed (README + docblock + pin) |
| m5 | API doc gap (nav set, accessors, geometry clamps, caches) | fixed — README "API surface" section |
| m6 | `PosterCard` five hand-rolled positional `new self(...)` carriers (state-drop hazard) | fixed — one `carrying()` choke via `get_object_vars` spread; per-field + census pins in `tests/PosterCardCarriersTest.php` |
| m7 | zero in-repo consumers (informational) | noted — lib ships examples + tests only; first consumer expected in a media app (candy-phlix era); no action |
| m8 | Rail `moveCursor` docblock advice non-actionable | fixed — construct-with-`$scroll` guidance (docblock + README) |
| m9 | stale findings file auditing phantom files (this file) | fixed — erratum + ledger above |
| m10 | Rail/PosterGrid `focused` asymmetry undocumented | fixed — README API section states it + rationale |
| nit×4 | dead local in `PosterGrid::new()`; twin scroll-clamp math (Rail); README `ESC [ 1 m` spacing; GD note on mosaic example | all fixed (inline removal; shared `windowedScroll()`; reworded; prereq note + composer `suggest` block) |

**Consumer-surface note (accessibility persona):** the chrome glyphs `●` `○` `▸`
`░` `▓` are East-Asian-Ambiguous; documented in README, unchanged by design.
