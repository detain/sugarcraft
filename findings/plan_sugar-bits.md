---
status: residue-built (round 86 lane v3: 5 built, 12 pre-landed verified, 4 ruled, 2 reported for ruling)
phase: 1
updated: 2026-06-30
---

# Implementation Plan: sugar-bits Code Review Findings

## Goal

Address all 25 findings from the sugar-bits code review, organized into phased implementation groups by severity, ensuring test compatibility throughout.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Static $nextId in Timer/Stopwatch is a test isolation issue | Monotonic counter pollutes test state across test suite runs | `findings/sugar-bits.md:13-35` |
| C0 sanitization is security-critical | Terminal control sequence injection is a real TUI attack vector | `findings/sugar-bits.md:265-271` |
| DRY violations in sanitizeCell() should be centralized to candy-core | Single point for security auditing; eliminating 3 duplicate implementations | `findings/sugar-bits.md:41-58` |
| Deprecated aliases (8 files) are transitional architecture | sugar-bits is a re-export layer; candy-forms is canonical home | `findings/sugar-bits.md:159-174` |
| Performance issues (sorting, shrinking, visibleRows caching) matter for large tables | O(N×M×log M) sort and O(budget×cols) shrink are measurable at scale | `findings/sugar-bits.md:60-106` |

## Phase 1: Critical Issues [PENDING]

- [x] **1.1** Static State in Timer and Stopwatch — Add `resetIdCounter(): void` static method to both classes; call via Reflection in test tearDown(); document intentional monotonic ID scheme
  - **r86-v3:** ✅ landed (pre-v3, verified by read): `resetIdCounter()` src/Timer/Timer.php:55 + src/Stopwatch/Stopwatch.php:51; Reflection tearDown tests/Timer/TimerTest.php:15-21 + tests/Stopwatch/StopwatchTest.php:14-20.
  - **Files:** `src/Timer/Timer.php:23-34`, `src/Stopwatch/Stopwatch.php:20-30`, `tests/Timer/TimerTest.php`, `tests/Stopwatch/StopwatchTest.php`
  - **Verification:** `vendor/bin/phpunit` passes; testIdAccessor tests pass in any order
  - **Severity:** critical
  - **Notes:** Both use identical `private static int $nextId = 0` + `++self::$nextId` pattern. First instance gets ID=1. Explicit `$id` param preserves ID in `withInterval()`.

## Phase 2: High Priority Issues [PENDING]

- [x] **2.1** Extract Shared `sanitizeCell()` to `candy-core` — Create `candy-core/src/Util/Sanitize.php` with `public static function controlChars(string $s): string`; update Tabs, Tree, Table to use it
  - **r86-v3:** ✅ landed (pre-v3, verified by read): canonical `candy-core/src/Util/Sanitize.php::controlChars()`; call sites Tabs.php:193, Tree.php:169, Table.php:643, Progress.php:75-76/118-119, Help.php:252-253.
  - **Files:** `src/Tabs/Tabs.php:462-466`, `src/Tree/Tree.php:366-372`, `src/Table/Table.php:580-584`, `candy-core/src/Util/Sanitize.php` (new)
  - **Verification:** All three components render identically before/after; unit test verifies C0 stripping
  - **Severity:** high
  - **Notes:** Method replaces `\n\r\t` with space; strips `\x00-\x08\x0b\x0c\x0e-\x1f`. CORRECTED: the original note claimed it "preserves ESC for SGR" — it does not, ESC (0x1b) sits inside the stripped range and is deleted, while DEL/C1 pass through. Styled output must therefore be built by sanitizing plain text and applying SGR afterwards. All three implementations are byte-identical.

- [x] **2.2** `Table::sortedRows()` — Multiple Full Array Sorts — Refactor to single-pass `usort()` with comparator evaluating all criteria in priority order
  - **r86-v3:** ✅ landed (pre-v3, verified by read): single `usort` + `strnatcasecmp` pass in `Table::sortedRows()`; SortTest green.
  - **Files:** `src/Table/Table.php:506-526`, `tests/Table/SortTest.php`
  - **Verification:** Existing sort tests pass; multi-criteria sort results identical to multi-pass
  - **Severity:** high
  - **Notes:** O(N×M×log M) where N=criteria, M=rows. Current does N full sorts. Single-pass comparator returns immediately on non-zero comparison.

- [x] **2.3** `Table::columnWidths()` — Inefficient Round-Robin Shrinking — Replace iterative O(budget×cols) with mathematical proportional distribution
  - **r86-v3:** ✅ BUILT r86-v3: proportional floor(excess×w/total) single O(cols) pass + right-to-left remainder in `columnWidths()`; pins tests/Table/TableShrinkTest.php (6T). Disclosed deviation from "byte-identical": the old loop zeroed trailing columns first on skewed widths; proportional distribution (this row's own prescription) is the new contract.
  - **Files:** `src/Table/Table.php:612-632`, `tests/Table/TableTest.php`, `tests/Table/TablePaddingRegressionTest.php`
  - **Verification:** All table tests pass; output byte-identical
  - **Severity:** high
  - **Notes:** Current loop: 100 cell excess × 10 columns = up to 1000 iterations. Proportional: `floor($excess * $width[$i] / $totalWidth)` per column.

- [x] **2.4** `Tabs::view()` and `Tabs::computeScrollEnd()` — Duplicated Scroll Logic — Refactor `view()` to use stored `scrollEnd` instead of recomputing — DONE r87/w2 (E736 build ruling): computeScrollEnd() now IS view()'s reservation-aware walk (sanitised widths + right-ellipsis reserve); view() consumes the stored window + final width guard. Render-dump proof 0 byte diffs on shared keys; intended flush-width fixes pinned by 4 new tests
  - **r86-v3:** ⚠ UNBUILT — verified NOT landed: view() still self-walks (Tabs.php ~212-246, with ellipsis-variant logic) instead of consuming stored scrollEnd (:83). Outside v3 buildable set; reported to orchestrator for ruling.
  - **Files:** `src/Tabs/Tabs.php:82,212-246,432-455`, `tests/Tabs/TabsTest.php`
  - **Verification:** All tabs tests pass; rendered output identical
  - **Severity:** high
  - **Notes:** `view()` at lines 212-246 recomputes visibleEnd identically to `computeScrollEnd()` at lines 432-455. `scrollEnd` stored at line 82 is used for navigation but ignored in view().

## Phase 3: Medium Priority Issues [PENDING]

- [.] **3.1** `Progress::view()` — Duplicated Width/Percent Calculations — Extract common calculations to `private function computeBarLayout(): array`; all three render modes call helper
  - **r86-v3:** ⚠ UNBUILT — verified NOT landed: no `computeBarLayout` in Progress.php. Structural refactor outside v3 scope; reported for ruling.
  - **Files:** `src/Progress/Progress.php:210-319`, `tests/Progress/ProgressTest.php`
  - **Verification:** All progress tests pass; output byte-identical
  - **Severity:** medium
  - **Notes:** ~80 lines of similar logic across Line/Slim/Block branches computing suffixCells, showSuffix, barWidth, filledCells, emptyCells.

- [x] **3.2** Tree::visibleRows() — Not Cached — Add `private ?array $visibleRowsCache = null`; cache result; invalidate in `copy()` when roots or expansion state changes
  - **r86-v3:** ✅ BUILT r86-v3: `Tree::$visibleRowsCache ??= collectVisibleRows()`; copy() mints fresh null-cache instances. Pins tests/Tree/TreeVisibleRowsTest.php.
  - **Files:** `src/Tree/Tree.php:232-239,338-359`, `tests/Tree/TreeTest.php`
  - **Verification:** All tree tests pass; cache properly invalidated
  - **Severity:** medium
  - **Notes:** `visibleRows()` called in 5 places per update/render cycle: view, selectedNode, visibleCount, setExpandedAtCursor, clamp.

- [x] **3.3** Table::visibleRows() — Not Cached — Add `private ?array $visibleRowsCache = null`; cache sorted+filtered chain; invalidate in `mutate()` when rows/sortState/filter change
  - **r86-v3:** ✅ BUILT r86-v3: `Table::$visibleRowsCache`; `view()` now consumes the memo instead of re-running sort+filter inline. Pins tests/Table/VisibleRowsCacheTest.php (5T).
  - **Files:** `src/Table/Table.php:570-573,709-728`, `tests/Table/TableTest.php`, `tests/Table/SortTest.php`, `tests/Table/FilterTest.php`, `tests/Table/PaginationTest.php`
  - **Verification:** All table tests pass
  - **Severity:** medium
  - **Notes:** `visibleRows()` called in 4 places: view, selectedRow, moveCursor, getPaginator. Re-executes full sort+filter on every call.

- [x] **3.4** Deprecated Components Are Non-Functional Aliases — Do NOT modify aliases. Update `README.md` and `CALIBER_LEARNINGS.md` to document transitional architecture
  - **r86-v3:** ✅ landed (pre-v3, verified by read): README.md:19,37 + CALIBER_LEARNINGS.md:14 — aliases are class_alias shims, consumers migrate to canonical FQNs.
  - **Files:** `src/Viewport/Viewport.php` (all 8 identical), `sugar-bits/README.md`, `sugar-bits/CALIBER_LEARNINGS.md`, `tests/ShortAliasesTest.php`
  - **Verification:** ShortAliasesTest passes; documentation clearly explains transitional nature
  - **Severity:** medium
  - **Notes:** 8 files are `class_alias()` shims pointing to candy-forms. External consumers using `SugarCraft\Bits\*` get candy-forms implementations.

- [x] **3.5** SortState — Readonly Array Property Still Mutable Internally — Add test verifying `$state->criteria[] = [...]` after construction doesn't affect original; document safety in code
  - **r86-v3:** ✅ landed (pre-v3, verified by read): chain-copy pins tests/Table/SortStateTest.php:37 + :79 (withCriterion returns new instance; original untouched).
  - **Files:** `src/Table/SortState.php:11-28`, `tests/Table/SortTest.php`
  - **Verification:** New test passes; existing sort tests continue
  - **Severity:** medium
  - **Notes:** Class is safe because `withCriterion()` copies before appending. Protection is implicit — make it explicit and test-verified.

- [x] **3.6** AnimatedProgress — Creates New Spring Instance Every Tick — Store `Spring` as private readonly; recreate only when fps/angularFrequency/dampingRatio change
  - **r86-v3:** ✅ landed (pre-v3, verified by read): `AnimatedProgress` stores the Spring (src/Progress/AnimatedProgress.php:37-38, lazy getSpring ~:98-100).
  - **Files:** `src/Progress/AnimatedProgress.php:79,153-161`, `tests/Progress/AnimatedProgressTest.php`
  - **Verification:** All AnimatedProgress tests pass; spring behavior identical
  - **Severity:** medium
  - **Notes:** At 60fps, 60 Spring objects/second. `withSpringOptions()` and `withFps()` already create new instances — cache Spring there.

- [x] **3.7** Progress and Help Do Not Sanitize Input — Add C0 sanitization for `fullChar`/`emptyChar` in Progress and for Binding key/desc in Help
  - **r86-v3:** ✅ landed (pre-v3, verified by read): Progress.php:75-76/118-119 + Help.php:252-253 route through Sanitize (see 2.1).
  - **Files:** `src/Progress/Progress.php:38-62,303,309`, `src/Help/Help.php:245-258`, `candy-core/src/Util/Sanitize.php` (after 2.1), `tests/Progress/ProgressTest.php`, `tests/Help/HelpTest.php`
  - **Verification:** C0 characters stripped; existing tests pass
  - **Severity:** medium (security-relevant)
  - **Notes:** Use `Sanitize::controlChars()` after Phase 2.1 complete. Consistent guarantees across all components.

## Phase 4: Low Priority Issues [PENDING]

- [x] **4.1** Tree::updateAt() — Reference Parameter Mutation — Refactor to return modified tree: `private function updateAt(array $tree, array $path, \Closure $mut): array`
  - **r86-v3:** ✅ BUILT r86-v3: `updateAt(array $tree, ...): array` — pure return, no `&$tree`; sole caller rewired. Original-intact pin in TreeVisibleRowsTest.
  - **Files:** `src/Tree/Tree.php:302-315,292-294`, `tests/Tree/TreeTest.php`
  - **Verification:** All tree tests pass
  - **Severity:** low
  - **Notes:** All other mutations use immutable patterns. Reference mutation is confusing in this context.

- [x] **4.2** Tree::collectVisible() — Array Spread on Every Recursion — Replace `...[$$path, $i]` with non-allocating path building
  - **r86-v3:** ✅ BUILT r86-v3: shared `&$path` push/pop buffer; rows store COW snapshots. Pin testPathsSnapshotEachDepth (exact path list).
  - **Files:** `src/Tree/Tree.php:259,245-261`, `tests/Tree/TreeTest.php`
  - **Verification:** All tree tests pass
  - **Severity:** low
  - **Notes:** `...$path` creates new array on every recursive call. O(depth) allocation per node. Options: array_push/pop or pass by reference.

- [.] **4.3** Tabs::tabIndexFromZoneId() — Fragile String Parsing — Replace `strpos() + substr()` with `preg_match('/^tab-(\d+)$/', $id, $m)`
  - **r86-v3:** ⏭ ruled — intentional behavior, do NOT "fix": the substring contract is documented at Tabs.php:391-394 (zone ids like `tab-3` must match `prefixtab-3` style ids by design). Zero code change; stamp per r86-v3 brief.
  - **Files:** `src/Tabs/Tabs.php:393-402`, `tests/Tabs/TabsTest.php`
  - **Verification:** All tabs tests pass; edge cases ("my-tab-3" → null) handled correctly
  - **Severity:** low
  - **Notes:** Current parsing finds "tab-" anywhere. "my-tab-3" would incorrectly return 3.

- [.] **4.4** Table::columnWidths() — Empty Rows Edge Case — Remove dead `?: [[]]` fallback at line 591 (never meaningfully used)
  - **r86-v3:** ⏭ false prescription — do NOT remove: `?: [[]]` at Table.php:~588 is load-bearing (max(...[]) ValueError when rows AND headers empty; the early return sits after the max call).
  - **Files:** `src/Table/Table.php:589-595`, `tests/Table/TableTest.php`
  - **Verification:** All table tests pass
  - **Severity:** low
  - **Notes:** Fallback suggests a use case that is already handled by early return at lines 593-595. Dead code removal.

## Phase 5: Security Issues [PENDING]

- [x] **5.1** C0 Control Character Sanitization — Inconsistent Coverage — Addressed by Phase 2.1 (centralized Sanitize) and Phase 3.7 (Progress/Help). Verify all 5 component types sanitize C0. Add security integration test.
  - **r86-v3:** ✅ landed (pre-v3, verified by read): C0/control-char coverage in ProgressRenderModeTest, ProgressTest, HelpTest, TableTest, TableStyleFuncTest (+ Tabs/Tree x1b census).
  - **Verification:** Security test passes: C0 injection attempts neutralized in all components
  - **Severity:** security
  - **Notes:** Terminal control sequence injection is a real TUI security concern. After Phases 2.1 and 3.7 complete, all user-supplied text fields should be covered.

- [x] **5.2** No Input Validation on Public Constructor Parameters — Add validation for `Column::$width < 0` (throws InvalidArgumentException). Evaluate Binding empty keys.
  - **r86-v3:** ✅ landed (pre-v3, verified by read): Column.php:23 throws Lang `column.width_nonneg`; pin tests/Table/ColumnTest.php:37-38.
  - **Files:** `src/Table/Column.php`, `src/Key/Binding.php:22-26`, `lang/en.php:14`
  - **Verification:** Column throws on negative width; all tests pass
  - **Severity:** security (medium)
  - **Notes:** `lang/en.php` already has `column.width_nonneg` key — validation was planned but not implemented.

## Phase 6: Compatibility Issues [PENDING]

- [x] **6.1** Class Alias Deprecation Creates Confusing Consumer API — Addressed by Phase 3.4 (documentation update). Ensure README.md and CALIBER_LEARNINGS.md clearly document transitional architecture.
  - **r86-v3:** ✅ landed (pre-v3, verified by read): see 3.4 citations.
  - **Severity:** compatibility
  - **Notes:** Consumers using `SugarCraft\Bits\*` may not realize they're using candy-forms with no independent versioning.

- [x] **6.2** ReactPHP/Async Integration — subscriptions() Always Returns Null — Add note to CALIBER_LEARNINGS.md explaining Timer/Stopwatch/AnimatedProgress use `Cmd::tick()` not `subscriptions()`.
  - **r86-v3:** ✅ landed (pre-v3, verified by read): CALIBER_LEARNINGS.md:13 documents subscriptions()/Cmd::tick integration.
  - **Files:** `sugar-bits/CALIBER_LEARNINGS.md`
  - **Severity:** compatibility
  - **Notes:** Pattern is correct but undocumented. No code changes needed.

## Phase 7: Improvements [PENDING]

- [.] **7.1** Consider `array_multisort()` for Multi-Column Sort — After Phase 2.2, evaluate whether `array_multisort()` (C implementation) could improve performance
  - **r86-v3:** ⏭ ruled DECLINED (correctness): PHP 8.3.6 has no SORT_FLAG_CASE_INSENSITIVE (measured) and SORT_NATURAL is case-sensitive — bare SORT_NATURAL reverses the pinned strnatcasecmp order (alpha,Beta,beta vs Beta,alpha,beta on [Beta,alpha,beta]); SORT_NATURAL|SORT_FLAG_CASE matched in probes but the named constant SORT_FLAG_CASE_INSENSITIVE does not exist on this PHP — verdict DECLINED unchanged (r86 weld: categorical "cannot reproduce" softened to measured truth). Recorded in CALIBER_LEARNINGS.md.
  - **Severity:** improvement
  - **Notes:** Performance improvement for large tables with many sort criteria.

- [x] **7.2** Document Static `$nextId` Behavior — After Phase 1.1, add doc comment explaining: IDs monotonic within process (intentional for debugging), first=1, use `resetIdCounter()` for testing
  - **r86-v3:** ✅ landed (pre-v3, verified by read): monotonic-id doc comments Timer.php:28-33 / Stopwatch.php:25-29.
  - **Files:** `src/Timer/Timer.php`, `src/Stopwatch/Stopwatch.php`
  - **Severity:** improvement
  - **Notes:** Behavior was accidental. Documentation makes it intentional design decision.

- [x] **7.3** Missing Translation Keys — Audit all components for missing translation keys. Add keys for any undiscovered gaps (Tree already has `tree.dim_nonneg`, Column width key `column.width_nonneg` exists but validation missing).
  - **r86-v3:** ✅ landed (pre-v3, verified by read): keys wired — Column.php:23, Tree.php:91, Table.php:67/288/388, Help.php:82, Progress.php:71.
  - **Files:** `lang/en.php`, `src/Tree/Tree.php`, `src/Table/Column.php`, `src/Table/Table.php`
  - **Verification:** All error messages use Lang::t() where keys exist
  - **Severity:** improvement
  - **Notes:** Inconsistent Lang::t() usage means some error messages may be non-translatable.

---

## Notes

- **Phase ordering:** Phases 1 (critical) and 2 (high) should be completed first. Phases 3-7 can be done in any order or parallelized by separate implementers.
- **Backward compatibility:** All changes must maintain existing test behavior. No breaking changes to public APIs.
- **Testing:** Run `vendor/bin/phpunit` for sugar-bits after each phase to verify nothing broken.
- **Golden files:** If golden render files exist in `tests/fixtures/`, update with `UPDATE_GOLDENS=1 vendor/bin/phpunit` after intentional output changes.
- **Dependencies:** Phase 3.7 (Progress/Help sanitization) depends on Phase 2.1 (Sanitize utility). Phase 5.1 depends on Phases 2.1 and 3.7.
