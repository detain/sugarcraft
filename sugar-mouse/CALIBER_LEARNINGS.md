# Caliber Learnings — sugar-mouse

> Accumulated patterns and gotchas discovered while building and using
> sugar-mouse. New contributors: read this before touching the code.

## Sentinel codepoints

- Sentinel triple uses Unicode Private Use Area codepoints U+E000
  (open) and U+E001 (close).
- **Do not** collapse U+E000+ during text normalisation** — the
  sentinel codepoints would be lost and zones would fail to parse.
- These codepoints are guaranteed not to appear in any ANSI escape
  sequence (CSI starts with ESC [, OSC with ESC ]) and are invisible
  in terminal emulators.

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
zone sentinel markers. These codepoints are guaranteed safe from ANSI SGR
sequence clobbering — they never appear in CSI (ESC [) or OSC (ESC ])
 byte ranges.
Anti-pattern: Do not normalise or collapse U+E000+ during text
processing — the sentinel codepoints would be lost and zones would fail to
parse.
Source: step-05 ai/sugar-mouse-new

## Synchronous-only design (async gap)

- sugar-mouse is **synchronous-only** — no ReactPHP async integration.
- `ZoneClickTracker::track()` and `Scanner::hit()` are blocking operations.
- If async TUI use cases emerge, consider an async variant of the scanner.
Source: plan_sugar-mouse.md — Item 5.1

## Scanner::hit() — grid-bucket spatial index

- `Scanner::hit()` is backed by a lazily built grid-bucket index
  (`GRID_BUCKET` = 32 cells): each zone is hashed into every bucket its
  bounding box overlaps, so a lookup scans only the (usually tiny)
  candidate list of the queried bucket — sub-linear for the dense
  table/list/grid UIs bubblezone targets.
- Buckets are populated in zone insertion order, so among overlapping
  zones the earliest-registered still wins — identical result to the old
  O(n) linear scan.
- The index is invalidated on every `scan()`/`clear()` and rebuilt on the
  next `hit()` (`Scanner.php:115-162`).
- **Stale-warning for this file's history:** an earlier entry claimed
  hit() was an unindexed O(n) scan citing `Scanner.php:83-86`; that was
  superseded when the index landed (index-invalidation tests included).
Source: plan_sugar-mouse.md — Item 5.2 (closed: index shipped)

## Memoization opportunity for repeated scans

- `Scan::parse()` processes the entire rendered string in one pass.
- For static UIs where the terminal buffer rarely changes, caching or
  memoizing scan results could avoid redundant parsing.
- A `ScanIterator` (implementing `IteratorAggregate`) could also yield
  zones incrementally to support streaming renderers.
Source: plan_sugar-mouse.md — Item 5.3

## From candy-zone <!-- @rename-keep historical provenance heading -->

- **[env]** `unset GITHUB_TOKEN` before every `gh` invocation (`gh pr create`, `gh pr merge`, etc.) in this repo. The repo-level GITHUB_TOKEN clashes with the gh CLI's stored credentials and produces auth failures or wrong-account commits — used consistently across phase PRs to land cleanly.
- **[pattern]** When changes touch `sugar-core`, regression-check the downstream packages before opening the PR: run `vendor/bin/phpunit` in `sugar-widgets`, `sugar-forms`, and `candy-scriptui` (chain them: `cd ... && vendor/bin/phpunit && cd ... && vendor/bin/phpunit`). They consume the runtime and break silently if a Cmd/Msg surface shifts.
- **[gotcha]** Don't bottom-stack a second `final class` inside an existing PSR-4 file (e.g. defining `Point` at the end of `src/Vector.php`). Composer autoloading expects one class per file matching the class name, so `use SugarCraft\Spring\Point` fails with `Class X not found` even though tests in the same namespace see other classes fine — extract each class into its own file matching the class name.
- **[pattern]** For Bubble-Tea-style runtime ports, when adding a new sentinel Msg type that the Program intercepts (e.g. `SequenceMsg`, `ExecRequest`, `SuspendMsg`), the dispatch order matters: handle the sentinel branches in `Program::dispatch()` BEFORE the `WithFilter` pre-processor and BEFORE `model->update()`, otherwise filters can drop runtime sentinels and the Program will hang waiting for a teardown that never fires.
- **[pattern:click-counter-state-machine]** `ClickCounter` uses the `SugarCraft\Core\Concerns\Mutable` trait with a custom `mutate()` override: the trait provides `use Mutable;` declaration and the array-merge pattern (`new static(...array_merge(get_object_vars($this), $changes))`), but because `ClickCounter`'s mutable private fields (`clickCount`, `lastClickTime`, `lastClickZoneId`) are not constructor parameters, the override manually creates the instance and assigns private fields after construction. This hybrid approach keeps the mutable state separate from the readonly constructor config (`manager`, `clickIntervalMs`). See `sugar-core/src/Concerns/Mutable.php` for the standard trait.
- **[arch:facade-over-candy-mouse]** CandyMouse is a TEA-facing façade over `candy-mouse` (the SSOT low-level hit-test primitive). `Manager::mark()` → `SugarCraft\Mouse\Mark::wrap(prefix.id, content)`; `Manager::scan()` → `SugarCraft\Mouse\Scan::parse()` for bounds, then `stripMarkers()` removes the tags and returns the display-ready frame. `Zone\Zone` is a BC adapter: it keeps its public readonly bounds + `MouseMsg`-based `inBounds()`/`pos()`/`contains()`, but delegates the point-in-rect / width / height math to a wrapped `SugarCraft\Mouse\Zone` built from the same bounds (bridging `MouseMsg`→`Mouse\MouseEvent`). The marker WIRE FORMAT changed APC (`ESC _ candyzone:S:<id> ESC \`) <!-- @rename-keep historical wire token --> → PUA sentinels (`U+E000 <id> U+E001 … U+E000 /<id> U+E001`) — behaviour (bounds, get/all/anyInBounds, DragTracker/HoverTracker/ClickCounter) is byte-identical because both scanners use the same close-marker union-rectangle math + candy-core `Width`.
- **[gotcha:id-charset]** `Mark::wrap()` (PR #1272) validates the marked id against `^[A-Za-z0-9._:-]+$` and throws `\InvalidArgumentException` otherwise, so `Manager::mark()` now throws on an out-of-charset `prefix().id` (only when enabled — disabled managers still pass content through unvalidated). Real consumer ids (`tab-N`, `cell:N`, `item:N`) and auto-prefixes (`1-`) already comply. Re-baseline any test asserting raw APC bytes to the PUA sentinels (`SugarCraft\Mouse\Sentinel::OPEN`/`CLOSE`); keep behavioural assertions as-is.
- **[gotcha:consumer-wire-format-test]** `sugar-bits` `TabsTest::testViewContainsZoneMarkersWhenManagerIsSet` asserts the OLD APC bytes (`\x1b_`, `candyzone:S:testtab-0`) in `Tabs::view()`. The APC→PUA façade change makes that assertion fail — it must be re-baselined to PUA sentinels inside sugar-bits (out of candy-mouse's edit scope). The behavioural sugar-bits path (`testMsgZoneInBoundsActivatesMatchingTab`, mark→scan→`MsgZoneInBounds->zone->id`) stays green. <!-- @rename-keep historical wire token -->
- **[gotcha:strip-scan-lockstep]** `Manager::stripMarkers()` must apply the SAME tag rule as sugar-mouse `Scan::parse()` (15a7f0c9f): a `U+E000 [/] <id> U+E001` span is a tag only when `<id>` is empty or passes `Mark::isValidId()` (length-checked against `Mark::MAX_ID_BYTES` before substr); otherwise only the 3 sentinel bytes go and the following text stays. Stripping "everything up to the next U+E001" desynced the cleaned frame from the scanned bounds — zones landed on cells they never painted. Pinned by `tests/ManagerStripMarkersTest.php` (`cellOf()` cross-checks bounds against the cleaned frame).
