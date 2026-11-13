# Caliber Learnings — sugar-mouse

> Accumulated patterns and gotchas discovered while building and using
> sugar-mouse. New contributors: read this before touching the code.

## Sentinel codepoints

- The sentinel pair uses Unicode Private Use Area codepoints U+E000
  (open) and U+E001 (close).
- **Do not** collapse U+E000+ during text normalization — the
  sentinel codepoints would be lost and zones would fail to parse.
- These codepoints never appear in ANSI escape-sequence payloads (CSI/OSC
  bodies are printable ASCII, U+E000/U+E001 are not) — that much is
  guaranteed. What is NOT guaranteed: invisibility on a host. PUA codepoints
  may render as boxes or Dotted Circles, and the lib's guarantee is only
  that its OWN scanner treats tags as zero-width. Foreign U+E002+ glyphs
  (Nerd Font, image-overlay markers) are visible cells; each one before a
  zone drifts its measured geometry one cell on hosts that do not mask them
  (sweep/mark hygiene is the consumer's lane — `Mark::untrusted()` is the
  shipped sweep tool for U+E000/U+E001 framing bytes).

## CJK column accounting

- `Width::string()` from sugar-core is used during scan to account for
  wide East-Asian characters (2 cell columns each).
- Always use `Width::string()` for column arithmetic; do not use
  `strlen()` or `mb_strlen()`.

## ZoneClickTracker state machine

- Pending state is stored **per button** so multi-button mice work
  correctly.
- A null zone at press time (click outside any zone) clears the state
  on release without emitting a click.
- `setPressZone()` must be called by the caller immediately after
  `track(Press)` when the scanner is available — `ZoneClickTracker`
  cannot resolve the zone itself without a reference to the scanner.

### 2026-05-28 — Sentinel codepoints are PUA, not OSC 1337

Pattern: sugar-mouse uses private-use Unicode PUA U+E000/U+E001 for
zone sentinel markers. These codepoints cannot be clobbered by ANSI SGR
sequence bytes — they never appear in CSI (ESC [) or OSC (ESC ]) ranges.
(Zero-width-to-the-scanner, not host-invisible — see "Sentinel
codepoints".)
Anti-pattern: Do not normalize or collapse U+E000+ during text
processing — the sentinel codepoints would be lost and zones would fail to
parse.
Source: findings/sugar-mouse.md

## Synchronous-only design (async gap)

- sugar-mouse is **synchronous-only** — no ReactPHP async integration.
- `ZoneClickTracker::track()` and `Scanner::hit()` are synchronous pure
  computations: no I/O, no timers, nothing that can block — "blocking" was
  always the wrong word. Async integration, if it comes, is about feeding
  them from an event loop, not about making them async.
- If async TUI use cases emerge, consider an async variant of the scanner.
Source: findings/plan_sugar-mouse.md — Item 5.1

## Scanner::hit() — grid-bucket spatial index

- `Scanner::hit()` is backed by a lazily built grid-bucket index
  (`GRID_BUCKET` = 32 cells): each zone is hashed into every bucket its
  bounding box overlaps, so a lookup scans only the (usually tiny)
  candidate list of the queried bucket — sub-linear for the dense
  table/list/grid UIs bubblezone targets.
- Buckets are populated in zone insertion order, so among overlapping
  zones the earliest-registered still wins — identical result to the old
  O(n) linear scan (the Zones Manager layer's lookup is different:
  smallest-area wins, ties go to the first recorded — see
  src/Zones/Manager.php).
- The index is invalidated on every `scan()`/`clear()` and rebuilt on the
  next `hit()` (`Scanner.php:115-162`).
- **Stale-warning for this file's history:** an earlier entry claimed
  hit() was an unindexed O(n) scan citing `Scanner.php:83-86`; that was
  superseded when the index landed (index-invalidation tests included).
Source: findings/plan_sugar-mouse.md — Item 5.2 (closed: index shipped)

## Memoization opportunity for repeated scans

- `Scan::parse()` processes the entire rendered string in one pass.
- For static UIs where the terminal buffer rarely changes, caching or
  memoizing scan results could avoid redundant parsing.
- A `ScanIterator` (implementing `IteratorAggregate`) could also yield
  zones incrementally to support streaming renderers.
Source: findings/plan_sugar-mouse.md — Item 5.3

## From candy-zone <!-- @rename-keep historical provenance heading -->

- **[env]** `unset GITHUB_TOKEN` before every `gh` invocation (`gh pr create`, `gh pr merge`, etc.) in this repo. The repo-level GITHUB_TOKEN clashes with the gh CLI's stored credentials and produces auth failures or wrong-account commits — used consistently across phase PRs to land cleanly.
- **[pattern]** When changes touch `sugar-core`, regression-check the downstream packages before opening the PR: run `vendor/bin/phpunit` in `sugar-widgets`, `sugar-forms`, and `candy-scriptui` (chain them: `cd ... && vendor/bin/phpunit && cd ... && vendor/bin/phpunit`). They consume the runtime and break silently if a Cmd/Msg surface shifts.
- **[gotcha]** Don't bottom-stack a second `final class` inside an existing PSR-4 file (e.g. defining `Point` at the end of `src/Vector.php`). Composer autoloading expects one class per file matching the class name, so `use SugarCraft\Spring\Point` fails with `Class X not found` even though tests in the same namespace see other classes fine — extract each class into its own file matching the class name.
- **[pattern]** For Bubble-Tea-style runtime ports, when adding a new sentinel Msg type that the Program intercepts (e.g. `SequenceMsg`, `ExecRequest`, `SuspendMsg`), the dispatch order matters: handle the sentinel branches in `Program::dispatch()` BEFORE the `WithFilter` pre-processor and BEFORE `model->update()`, otherwise filters can drop runtime sentinels and the Program will hang waiting for a teardown that never fires.
- **[pattern:click-counter-state-machine]** `MultiClickTracker` (renamed from `ClickCounter`, SM-S30; the old name is a deprecated `class_alias`) does NOT use the `SugarCraft\Core\Concerns\Mutable` trait — this entry previously claimed it did, which was false (the class has no `use Mutable;` and never did). The real shape: readonly constructor config (`manager`, `clickIntervalMs`, optional `?callable $now` clock seam), mutable private streak fields (`clickCount`, `lastClickTime`, `lastClickZoneId`), and a hand-rolled private `withState(int $clickCount, float $lastClickTime, ?string $lastClickZoneId): self` that constructs the clone and threads the named private fields — no `get_object_vars()` array-merge, no dynamic property writes. `withManager()` carries the streak across rebinding (a silent reset there once lost users' double-click state). The `$now` seam lets timing tests step a virtual clock instead of sleeping.
- **[arch:facade-over-candy-mouse]** Historical: the TEA-facing Manager layer once shipped as a separate package (`CandyMouse`, over the primitive `candy-mouse` hit-tester). The fleet rename merged BOTH layers into this single `sugar-mouse` package — the primitives and the `Zones\` Manager now live under one manifest, so docblocks and prose must speak of one package with two layers, not a façade over another package.
  - Mark/scan mapping: `Manager::mark()` → `SugarCraft\Mouse\Mark::wrap(prefix.id, content)`; `Manager::scan()` → `SugarCraft\Mouse\Scan::parse()` for bounds, then the (now public) `Scan::strip()` removes the tags and returns the display-ready frame.
  - `Zones\Zone` is a backwards-compatible (BC) adapter: it keeps its public readonly bounds + `MouseMsg`-based `inBounds()`/`pos()`/`contains()`, but delegates the point-in-rect / width / height math to a wrapped `SugarCraft\Mouse\Zone` built from the same bounds. `Zones\Zone::toMouseEvent()` is the single public bridge between the three action vocabularies (core `Press|Release|Motion` + wheel message type, primitive `Press|Release|Drag|Scroll`, wire `b`-field).
  - The marker WIRE FORMAT changed from APC (`ESC _ candyzone:S:<id> ESC \`) <!-- @rename-keep historical wire token --> to PUA sentinels (`U+E000 <id> U+E001 … U+E000 /<id> U+E001`) — bounds/registry behavior was preserved because both scanners use the same close-marker union-rectangle math + sugar-core `Width`.
- **[gotcha:id-charset]** `Mark::wrap()` (PR #1272) validates the marked id against `/\A[A-Za-z0-9._:-]+\z/` (byte-wise anchors — no newline-allowed `$`, no `u` flag needed for this charset) and throws `\InvalidArgumentException` otherwise. `Manager::mark()` validates the id EVEN ON A DISABLED MANAGER — the previous "only when enabled; disabled managers still pass content through unvalidated" carve-out was removed: `Mark::wrap()` validates unconditionally and merely skips the tag emission when disabled (an invalid id must fail the same way whether or not the frame carries markers). `Mark::MAX_ID_BYTES`/`Mark::MAX_CONTENT_BYTES` bound id and content lengths. The APC-era instruction to re-baseline raw-byte tests to PUA sentinels (`SugarCraft\Mouse\Sentinel::OPEN`/`CLOSE`) is done history; keep behavioral assertions as-is.
- **[gotcha:consumer-wire-format-test]** The `TabsTest::testViewContainsZoneMarkersWhenManagerIsSet` that asserted the OLD APC bytes (`\x1b_`, `candyzone:S:testtab-0`) in `Tabs::view()` lives in **sugar-widgets** (`sugar-widgets/tests/Tabs/TabsTest.php` — an earlier draft of this entry misfiled it under sugar-bits). The APC→PUA change makes that assertion fail; it was re-baselined to PUA sentinels on the consumer side (outside this lib's edit scope). The behavioral sugar-widgets path (`testMsgZoneInBoundsActivatesMatchingTab`, mark→scan→`MsgZoneInBounds->zone->id`) stays green. <!-- @rename-keep historical wire token -->
- **[gotcha:strip-scan-lockstep]** `Scan::strip()` (public since the Manager refactor — `Manager::scan()` delegates to it; it used to be a private `Manager::stripMarkers()`) must apply the SAME tag rule as `Scan::parse()`: a `U+E000 [/] <id> U+E001` span is a tag only when `<id>` is empty or passes `Mark::isValidId()` (length-checked against `Mark::MAX_ID_BYTES` before substr); otherwise only the 3 sentinel bytes go and the following text stays. Stripping "everything up to the next U+E001" desynced the cleaned frame from the scanned bounds — zones landed on cells they never painted. Pinned by `tests/Zones/ManagerStripMarkersTest.php` (`cellOf()` cross-checks bounds against the cleaned frame). Lock-step is byte-wise-strip vs grid-walking-parse: they diverge only for well-formed tags embedded inside OSC/CSI payloads (parse skips them, strip removes them) — a known, documented edge; sweep hostile input with `Mark::untrusted()` instead of relying on either.

## Lineage vocabulary (ruling 2026-11-13, SM-S04)

- Hover, drag and multi-click do NOT exist upstream: lrstanley/bubblezone
  ships no hover/drag/click-count machinery (its own gap table in
  `docs/research/libraries/sugar-mouse-zones-research.md` records them as
  manual or not built-in). The three trackers and the seven zone event
  messages therefore cite "Extends bubblezone — upstream ships no …",
  never "Mirrors".
- "Mirrors bubblezone's `X`" stays reserved for surfaces with verified
  upstream parity: `Mark`/`Scan`/`Get`, the `Manager` methods
  (`SetEnabled`, `NewPrefix`, `SetMotionTracking`, `Close`,
  `AnyInBounds`, `AnyInBoundsAndUpdate`), and the package-level facade.
  Cites must be self-contained — always name bubblezone, never a bare
  "Mirrors `SetEnabled`" without the upstream qualifier.
- **[shared-index-absorption:SM-S30]** The `ClickCounter` -> `MultiClickTracker` rename (2026-11-13) landed across two sibling sugar-panels commits by shared-index absorption: `e3eac6252` carried the two `git mv` path moves (R100 pairs), `4def79268` carried the final content of all six paths (shim, renamed class, flipped test + alias pin, README/CALIBER/research-doc). Both verified byte-intact; the lane owns the content, the siblings own the commit objects. The SM-S28 MarkTest static-flip fixup is `21a298ce2`.
