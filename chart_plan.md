# Chart Glyph Enhancement Plan (chart_plan.md)

> Origin: 2026-09-01 glyph audit of `sugar-charts/` (ntcharts port) and `sugar-dash/` (termui/Homedash-family ports).
> Goal: close the gaps between our render glyph palette and the "ideal terminal chart" palette (quadrant blocks, aspect correction, eighth-block precision, rounded/arc line styles, wireframe circles, background-SGR fills).
> Companion files: `chart_worklog.md` (progress ledger — append-only), `chart_prompt.md` (kickoff/resume prompt).
> Execution contract: every step = fresh `coder` build agent → `reviewer`/`fixer` loop until zero findings → tests green → commit to **master, no push**. Never two steps touching the same file concurrently.

## Ground rules (apply to EVERY step)

1. **Code style**: `declare(strict_types=1)`, PSR-12, `final` classes, immutable fluent `with*()` returning new instances via `mutate()` (trait `candy-core/src/Concerns/Mutable.php`). Doc-comment on each new public method citing `Mirrors ...` where applicable and WHY, not WHAT.
2. **New long-form setters get a short alias** (sugar-charts only — sugar-dash has no short-alias convention; do NOT invent one; dash withers stay long-form) per the existing sugar-charts alias table (`README.md:41-45`, mirrors the pattern spot-checked by `sugar-charts/tests/ShortAliasesTest.php`).
3. **Tests**: ≥1 new/updated PHPUnit 10 test per behavior change; snapshot assertions must compare exact runes (see `sugar-charts/tests/BarChart/BarChartTest.php` for the style). **TWO golden systems — do not conflate:** (a) sugar-charts: `sugar-charts/tests/fixtures/*.golden` via `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi`, re-record with `UPDATE_GOLDENS=1 vendor/bin/phpunit`; (b) sugar-dash: `sugar-dash/tests/golden/<dim>/*.golden` (80x24, 120x40) driven by `sugar-dash/tests/Golden/GoldenSnapshotTest.php`, re-record via `--regenerate` argv or `php sugar-dash/tools/generate-goldens.php`. Only re-record when the step INTENDS an output change; the re-recorded diff must be shown to the reviewer and read as content. Any step whose intended output change hits a NON-SKIPPED example (see `GoldenSnapshotTest::SKIPPED`) MUST list the affected `.golden` files in its write-set.
4. **Verification commands** (run from lib root; `composer install` first if `vendor/` missing):
   - `cd /home/sites/sugarcraft/sugar-charts && vendor/bin/phpunit --filter '<RelevantSuite>'` then full `vendor/bin/phpunit`
   - `cd /home/sites/sugarcraft/sugar-dash && vendor/bin/phpunit --filter '<RelevantSuite>'` then full `vendor/bin/phpunit`
5. **Commit** (dedicated commit-agent after the review loop is clean — a `coder` task with bash; builds/fixes are separate agents, commits are theirs): stage only the step's write-set files; check hook first: `grep -q "caliber" .git/hooks/pre-commit && echo hook-active || echo no-hook` — if `no-hook`, run `caliber refresh` and stage its outputs too. Then `git commit` on master (author Joe Huss; never `--no-verify`, never push). Message format:
   ```
   <slug>: <step N summary>

   - bullet of each concrete change (files + behaviour)
   - test summary: "<n> assertions added, <lib> suite <total> tests green"
   - Plan: chart_plan.md step <Sx>
   ```
   The commit agent also ticks the Step status board row in `chart_worklog.md` (only commit agents ever edit the board). The full commit-agent contract (lane-scoped gate, caliber policy, message template) lives in `chart_prompt.md` and is authoritative over this summary.
6. **Worklog**: append a row for EVERY agent completion (build, each review, each fix, commit) to `chart_worklog.md` immediately — format defined there.
7. **Feature flags default OFF** (or legacy-equivalent) unless the step explicitly says default ON — no silent visual regressions to existing consumers.
8. **Large-file discipline**: `Donut.php`/`BarChart.php`/`LineChart.php` are the largest chart files — grep for symbols first, then read narrow windows; never whole-file read of a >800-line file (sizes decay; check `wc -l` if it matters).
9. **Write-sets are ceilings, not guesses**: the commit gate compares against the build agent's ACTUAL reported changed-file list; the plan's write-set is the ceiling. A step may commit fewer files, never more — needing more means STOP-and-report (plan-drift), not silent scope growth.
10. **Test-count arithmetic**: each step's Verify declares an expected delta; commit-row deltas must reconcile — unexplained count movement is a finding. Neither lib's `phpunit.xml` sets `failOnWarning=true` today (despite AGENTS.md): the reviewer must treat ANY PHPUnit warning in output as a finding; the config fix itself is Backlog BL-3.
11. **Reverse-deps**: sugar-dash chart files have none within this run's scope; if a step ever must touch `candy-core`/`candy-sprinkles`/`candy-buffer`, re-run THEIR suites too before commit.
12. **REMOVAL IS NOT AN OUTCOME**: dead tables/properties get wired or parked in Backlog — never delete/stub/comment-out mid-step.

## Concurrency map

Two lanes run in parallel (file-disjoint); lane B's tail is strictly serial because S5-S7 all edit `Donut.php`.

```
Lane A (sugar-charts): S2 ──▶ S3 ─────────────▶ (done)
Lane B (sugar-dash):   S1 ──▶ S4 ──▶ S5 ──▶ S6 ──▶ S7
                         └ S1 ∥ S2 may start together; S3 ∥ S4 may run together.
```
Scheduling rule: a step starts only when (a) its intra-lane predecessor is committed, and (b) every file in its write-set is free of any in-flight step. Commits are serialized by the orchestrator even when builds finish concurrently.

---

## S1 — sugar-dash: Bubble bottom-right arc corner fix (AMENDED 2026-09-01 → geometry fix per Option A)

**Why**: `CIRCLE_CHARS` maps bottom-right to `◠` (U+25E0 LOWER HALF CIRCLE — a wide flat arc, not a corner). The correct bottom-right quarter arc is `◞` (U+25DE). ◜◝◟ are right; only BR is wrong. 1-glyph correctness fix, visible rounder bubbles.

> **Amendment note (2026-09-01)**: the original rationale was measured **dead-on-arrival** — `drawBubbleOnGrid`'s disk test `dx*dx + dy*dy <= r*r` (`Bubble.php:396`) never admits corner cells (`|dx|=|dy|=r ⇒ 2r²>r²`), so `CIRCLE_CHARS` and the four corner branches of `getCircleChar()` are unreachable from render and NO `◜◝◟◠`/`◞` appears in ANY rendered bubble (measured: r=5 → 5-cell plus; r=8/10 → 13-cell diamond). A table-only `◠`→`◞` is a provable no-op and the original "assert rendered `◞` at bottom-right offset" acceptance test was unsatisfiable. Plan-drift STOP recorded 2026-09-01 (worklog `s1-build`); human ruling **OPTION A** (2026-09-01) amends S1 into a geometry fix: classify the ring boundary so the four corner arc glyphs from `CIRCLE_CHARS` ARE emitted at the diagonal extremes of each bubble, making the constant genuinely drive output. This is an EXPLICIT APPROVED default-output change scoped to sugar-dash `Bubble`.

**Design** (amended contract):
- `CIRCLE_CHARS` (`Bubble.php:49-55`): change `'bottom-right' => '◠'` → `'◞'` (U+25DE). Other entries untouched.
- `getCircleChar()` (`Bubble.php:417`): look up `self::CIRCLE_CHARS` (the four corner keys + `'full'`) instead of the hardcoded literals at lines 421-439 (note the BR branch itself hardcodes the wrong `◠` at line 436).
- `drawBubbleOnGrid()` (`Bubble.php:392`, **NOW in scope**): replace the pure-disk edge emission with a boundary classification such that for radius r ≥ 2 —
  - the four diagonal extreme cells `(±r, ±r)` relative to center are emitted with the matching quadrant-arc glyph from `CIRCLE_CHARS` (BR = `◞`);
  - cardinal-extreme cells `(0,±r)`, `(±r,0)` keep `●` (`CIRCLE_CHARS['full']`);
  - interior/edge fills remain `●` per existing behavior;
  - the ring must be 4-fold symmetric and connected (no diagonal-only touching that splits the ring at r=2).
  The exact classification rule (e.g., emit corner glyph when `|dx|==|dy|==r`; choose fill rule for edge cells so small radii render as coherent rounded boxes) is the builder's choice — the acceptance criteria below are binding.
- **APPROVED default-output change** (human ruling 2026-09-01, Option A): bubbles render corner arcs where before they rendered none; small-r shapes change (measured legacy: r=5 plus → ringed). Golden-safe: `'bubble'` ∈ `GoldenSnapshotTest::SKIPPED` and the bubble example is time-nondeterministic anyway — **zero golden churn; do NOT touch `tests/golden/**`** (no `.golden` file belongs in this step's write-set).

**Write-set**:
- `sugar-dash/src/Plot/Chart/Bubble.php` (symbols: `CIRCLE_CHARS`, `getCircleChar`, `drawBubbleOnGrid`)
- `sugar-dash/tests/Plot/Chart/BubbleTest.php` — update the char-class regex (`BubbleTest.php:101`) from `/[●◜◝◟◠]/` to `/[●◜◝◟◞]/`. Tests MUST pin:
  - (a) a rendered bubble (ANSI-stripped via `preg_replace('/\x1b\[[0-9;]*m/', '', $rendered)` — `Bubble::render` wraps every cell glyph in `toFg(...)`/`Ansi::reset()`) contains `◞` at the exact bottom-right diagonal offset (assert grid position, not just presence);
  - (b) all four corner glyphs render at their diagonals for r ≥ 2;
  - (c) output equals a table lookup — assert the rendered BR cell === `CIRCLE_CHARS['bottom-right']` resolved through a test-local copy of the table (const is `private`; via reflection or mirrored map). Hardcoding the expected glyph is forbidden: mutating the `'bottom-right'` entry in the test-local copy must change what the test expects, proving the table drives render;
  - (d) default-shape regression tests that currently assert the legacy plus/diamond shapes must be UPDATED to the new ringed shapes — that IS the declared change. List every existing test you had to update in the build report.

**Verify**: `cd /home/sites/sugarcraft/sugar-dash && vendor/bin/phpunit --filter Bubble > /tmp/s1-bubble.log 2>&1; echo rc=$?` then the FULL sugar-dash suite. Baseline at e2d17a4c9: 5853 tests / 9154 assertions / 1 skipped / rc=0 with exactly 1 pre-existing warning — the `GenericModule.php:98` `proc_open` env noise, NOT a finding. Expected delta: +2..4 tests (builder reports actual).

---

## S2 — sugar-charts: rounded line-style set + LineChart axis passthrough

**Why**: `Graph` offers LINE_THIN/LINE_THICK/LINE_DOUBLE (`Graph.php:22-43`) — no rounded-corner variant, so chart frames are always sharp `┌┐└┘` while sugar-dash defaults to `╭╮╯╰` everywhere else in the org.

**Design**:
- `Graph.php`: add `public const LINE_ROUNDED = ['h'=>'─','v'=>'│','tl'=>'╭','tr'=>'╮','bl'=>'╰','br'=>'╯','cross'=>'┼','tee_up'=>'┴','tee_down'=>'┬','tee_left'=>'┤','tee_right'=>'├'];` — keys MUST match the live `LINE_THIN` key names exactly (`tee_up`/`tee_down`/`tee_left`/`tee_right`, NOT `bt`/`tp`/`rt`/`lt`). NOTE: `drawXYAxis` indexes only `h`/`v`/`bl` today; `cross`/`tee_*` are upstream-parity keys with no live read site yet — they must still be present so every LINE_* preset shares an identical key set (the GraphTest assertion below pins this).
- `Graph::drawXYAxis(... array $runes = self::LINE_THIN)` — already rune-param'd; confirm/extend so any set works.
- `LineChart.php`: new `withLineStyle(array $runes)` storing the set, passed into `drawXYAxis` call site (line ~494); default `LINE_THIN` (no output change unless opted in). Short alias `lineStyle`.

**Write-set**:
- `sugar-charts/src/Canvas/Graph.php`
- `sugar-charts/src/LineChart/LineChart.php`
- `sugar-charts/tests/Canvas/GraphTest.php` — extend this existing file: assert every LINE_* constant has identical key sets.
- `sugar-charts/tests/LineChart/LineChartTest.php` — snapshot: `->withAxes()->withLineStyle(Graph::LINE_ROUNDED)` output contains `╰` at axis corner, `─`/`│` unchanged.
- `sugar-charts/tests/ShortAliasesTest.php` — add `lineStyle` alias.

**Verify**: `cd sugar-charts && vendor/bin/phpunit` (full — canvas is shared foundation). Expected delta: +3 tests.

---

## S3 — sugar-charts: BarChart eighth-block fractional caps + doc drift fix

**Why**: bar heights snap to whole `█` cells (`BarChart.php:349,356`) — a 0.9/1.0 pair and a 0.51/0.52 pair look identical at low heights. Library has ZERO sub-block precision despite the README example (line 32) showing a `▏` the code cannot produce. The eighth tables exist elsewhere (`sugar-dash/src/Output/RenderBar.php:93`, dead `sugar-dash/src/Plot/Chart/Chart.php:90`) — port the concept.

**Design**:
- `BarChart.php`: new `withFractionalHeights(bool $on = true)` (default **off**). When on, BOTH orientations get a partial top/edge cap cell (whole rows stay `█`; `frac` = fractional part of the exact scaled length):
  - **Vertical bars**: top partial row uses the bottom-eighths ramp `U+2581 + (int)round(frac*8) - 1` for frac in (0,1) rounding to 1..7 (round-to-8 promotes to a full `█` row, round-to-0 leaves the cell blank) — fill hugs the bottom edge, adjacent to the bar body.
  - **Horizontal bars**: rightmost partial cell uses LEFT-flush eighths, fill hugging the left edge against the `█` body, fill order 1/8→7/8 = `▏`(U+258F) `▎`(U+258E) `▍`(U+258D) `▌`(U+258C) `▋`(U+258B) `▊`(U+258A) `▉`(U+2589) — i.e. codepoint `U+2590 - (int)round(frac*8)`. Do NOT copy `sugar-dash/src/Output/RenderBar.php:93`'s leading `▕` — U+2595 is RIGHT-flush (would open a gap at the cap); that bug goes to Backlog BL-2.
- Fix docblock `BarChart.php:144`: claims `┴` for horizontal top axis but code emits `├` — correct the comment to `├`.
- `README.md`: change the BarChart sample block (lines 31-36) to real current output of `BarChart::new([['cpu',0.7],['mem',0.4],['disk',0.9]],20,5)->view()`; then append a 2nd sample WITH `->withFractionalHeights()`. Run the snippet to capture truth — do not hand-wave.

**Write-set**:
- `sugar-charts/src/BarChart/BarChart.php`
- `sugar-charts/tests/BarChart/BarChartTest.php` — new tests: frac exactly .5 → `▄` top cap; frac→0 rounds to blank (no `▁` ghost); default OFF keeps old byte-exact tests passing; horizontal-mode `▌` cap; horizontal 1/8 cap renders `▏` FLUSH against the preceding `█` run (assert no space/`▕` between body and cap).
- `sugar-charts/tests/ShortAliasesTest.php` — alias `fractional`.
- `sugar-charts/README.md`

**Verify**: full sugar-charts suite; diff must show zero changes to existing golden fixtures (flag default off). Expected delta: +4 tests.

---

## S4 — sugar-dash: Donut aspect-ratio correction (`withAspect`)

**Why**: `Donut.php:165-170` computes `sqrt(dx²+dy²)` in raw CELL units; cells are ~1:2 (w:h), so every "circle" renders as a vertically stretched ellipse (the exact distortion the palette doc warns about). This is a bug-class fix affecting Donut and the basis for S5/S7 geometry.

**Design**:
- `Donut.php`: new `withAspect(float $ratio = 2.0)` (paired sentinel `?float`, immutable). Distance becomes `sqrt($dx*$dx + ($dy*$ratio)**2)`; keep `$radius` in horizontal-cell units so the horizontal diameter is unchanged. `withAspect(1.0)` reproduces legacy output byte-exactly (escape hatch + test oracle).
- DEFAULT: `2.0` (visually round; step explicitly declares this an intended output change — existing `DonutTest` assertions are non-empty/structure checks, expected to survive; reviewer must re-run full dash suite).
- Also normalize: `atan2($dy*$ratio, $dx)` for angle→sector mapping so segments aren't angle-skewed by the squash.
- **Geometry completeness**: `renderEmpty()` has its OWN duplicated `sqrt($dx*$dx + $dy*$dy)` ring loop — apply the same aspect-scaled distance there too (else the empty `░` ring stays a stretched ellipse while the filled ring squashes round). `getInnerSize()` stays square (cell count); no change needed unless the wither changes size semantics.
- **Threading**: `withAspect` (new aspect field) must be passed through EVERY `new self(...)` construction site: `new()`, `mocha()`, `withSize()`, `withCenterLabel()`, `withCenterValue()`, `withShowPercentage()`, `withStartAngle()` — and the constructor signature gains the new promoted param. (`setSize()` is `clone $this`-based and carries the field automatically — verify, don't convert.)

**Write-set**:
- `sugar-dash/src/Plot/Chart/Donut.php` (symbols: `__construct`, `new`, `mocha`, `render` ring loop, `renderEmpty`, `setSize`/`getInnerSize`, every `with*` wither)
- `sugar-dash/tests/Plot/Chart/DonutTest.php` — new tests: aspect(1.0) == legacy geometry (compare against recorded fixture of current code BEFORE edit — capture first!), default aspect makes horizontal cell-diameter ≈ 2× vertical cell-diameter (count `█` per widest row vs widest column), withAspect immutability, renderEmpty ring also aspect-corrected.
- `sugar-dash/tests/golden/80x24/donut.golden` — RE-RECORD (donut is NOT in `GoldenSnapshotTest::SKIPPED`; intended output change).
- `sugar-dash/tests/golden/120x40/donut.golden` — RE-RECORD (same).
- Regen via `vendor/bin/phpunit -- --regenerate` (argv flag, `GoldenSnapshotTest::setUpBeforeClass`) or `php sugar-dash/tools/generate-goldens.php`; show the diffs to the reviewer. Note: `bubble.golden` is safe — `bubble` IS in SKIPPED.

**Verify**: full sugar-dash suite. Expected delta: +4 tests.

---

## S5 — sugar-dash: Donut quadrant-rim smoothing (`withSmoothRim`)

**Why**: rim is binary `█`/space (`Donut.php:188`) — one-cell staircase. Quadrant blocks give 2× perceived resolution while staying solid (braille looks dotty; that's the palette doc's Option-1 argument). Verified codepoints: quadrants ▖U+2596 ▗U+2597 ▘U+2598 ▝U+259D; halves ▀U+2580 ▄U+2584 ▌U+258C ▐U+2590; 3-of-4 ▙U+2599 ▚U+259A ▛U+259B ▜U+259C ▞U+259E ▟U+259F.

**Design**:
- `Donut.php`: `withSmoothRim(bool $on = true)` default **off** (composes with S4 aspect). For cells where the annulus boundary passes (|dist−r| < threshold at cell's four quadrant sample points): sample the ring test at the 4 sub-quadrant centers (±0.25 cell offsets, post-aspect), build coverage set → pick rune: all 4 `█`; TL `▘` TR `▝` BL `▖` BR `▗`; top pair `▀`; bottom `▄`; left `▌`; right `▐`; TL+BR `▚`; TR+BL `▞`; 3-of-4 → `▜▙▛▟` counterpart set; 0 → space (existing logic). Segment color applies fg SGR exactly as today (202-206) — quadrant cell takes the color of the segment covering its majority quadrant.
- Applies to BOTH outer rim and inner-hole rim.

**Write-set**:
- `sugar-dash/src/Plot/Chart/Donut.php`
- `sugar-dash/tests/Plot/Chart/DonutTest.php` — new tests: flag on produces at least one quadrant rune for a mid-size donut (size 12); flag off byte-identical to S4 output; every emitted quadrant rune ∈ declared set; quadrant cells NEVER overwrite hole-interior cells (cells with dist < innerRadius stay blank — there is no center label to protect: `render()` emits no center text; the `centerLabel`/`centerValue`/`showPercentage` properties are never read, see Backlog BL-1).

**Verify**: full sugar-dash suite. Expected delta: +4 tests.

---

## S6 — sugar-dash: Donut background-SGR fill mode (`withFillStyle`)

**Why**: fg `█` leaves hairline gaps on some fonts/line-spacings; the gapless standard trick is space + SGR background (`toBg`). sugar-dash already does this in `Plot/Canvas/Canvas.php:266` and `Bar.php:102` — Donut never adopted it (palette doc's "solid, color-filled" option).

**Design**:
- `Donut.php`: `withFillStyle(string $style = 'foreground')` accepting `'foreground'|'background'` (or a tiny enum if dash already has a fill-style enum — reuse). Background mode: run-length encode consecutive same-segment cells on a row → emit `toBg(color)` ONCE, spaces, `Ansi::reset()` ONCE at run end. Quadrant-rim cells (S5) force fg `█`-family even in bg mode (mixing sub-cell bg is impossible) — document that interaction.
- Default `'foreground'` — no behavior change unless opted in.

**Write-set**:
- `sugar-dash/src/Plot/Chart/Donut.php`
- `sugar-dash/tests/Plot/Chart/DonutTest.php` — bg mode: output contains `48;2;`-class bg SGR sequences and no `█` in ring rows; run-encoding: one reset per color run (count `Ansi::reset()` occurrences); fg default byte-identical.

**Verify**: full sugar-dash suite. Expected delta: +4 tests.

---

## S7 — sugar-dash: wireframe/outlined donut mode (rim + radial dividers + hub)

**Why**: the palette doc's "Option 2 — segmented outline circle" has no implementation anywhere: `GaugeCircle::ARC_CHARS` (`sugar-dash/src/Plot/Chart/GaugeCircle.php`, private const) and `Sunburst::ARC_CHARS` (`sugar-dash/src/Components/Tree/Sunburst.php`, private const) are declared-but-unused dead tables. An outlined mode reads well at small sizes and on B/W terminals (no color needed).

**Design**:
- `Donut.php`: `withRenderMode(string $mode = 'filled')` with `'filled'` (default, all S4-S6 behavior) and `'wireframe'`.
- Wireframe render:
  - Rim: walk the ellipse (post-aspect) in cell steps; per cell pick the rune from the LOCAL TANGENT direction bucket: near-horizontal `─`, near-vertical `│`, 45°-ish `╱`/`╲`, cardinal quadrant corners `◜◝◟◞` (outer ring quadrants) / `╭╮╯╰` when the sweep aligns with a box corner. `GaugeCircle::ARC_CHARS` is PRIVATE (`['╭','─','╮','│','╯','╰']`) — do not reference or rewire it; COPY the 6-glyph arc table into Donut as a private const for the axis-aligned runs (GaugeCircle dead-code removal stays in Backlog).
  - Dividers: one Bresenham-ish line from hub to rim at each segment boundary angle (reuse `sugar-dash/src/Plot/Braille/Bresenham.php` in cell space, aspect-scaled), cell rune = dominant direction `─│╱╲`.
  - Hub cell: `╳` when two diagonal-ish dividers cross, else `┼` when orthogonal pair, else `●`.
  - Text surface: Donut renders NO legend and NO center text today (`centerLabel`/`centerValue`/`showPercentage` are dead properties — Backlog BL-1); wireframe mode must likewise emit none. Do not "preserve" a legend that does not exist.
- Wireframe is rune-only (shape carries info); colors optional per-divider when segments have distinct colors — emit same fg-SGR wrap as filled mode.

**Write-set**:
- `sugar-dash/src/Plot/Chart/Donut.php`
- `sugar-dash/tests/Plot/Chart/DonutTest.php` — filled mode byte-identical (default unchanged); empty data → `''`-safe path preserved.
- `sugar-dash/tests/Plot/Chart/DonutWireframeTest.php` — NEW file: rim runes from `─│╱╲◜◝◟◞` set + a hub `╳|┼|●`; exactly N divider paths for N segments (corner case N=2 → 2 boundary angles); no legend/center-text emitted.
- `sugar-dash/examples/donut-wireframe.php` — NEW demo (`sugar-dash/examples/` confirmed to exist; model on `examples/donut.php`).

**Verify**: full sugar-dash suite. Expected delta: +5 tests.

---

## Backlog (explicitly OUT of this plan — candidates for a follow-up round)

- **BL-1** Wire Donut `centerLabel`/`centerValue`/`showPercentage` — advertised in the class docblock ("Optional center text"), constructed and threaded by withers, but never read in `render()`/`renderEmpty()` (measured 2026-09-01). Prerequisite for any S5/S7-era center-text or legend feature.
- **BL-2** `sugar-dash/src/Output/RenderBar.php` `$blocks` ramp bug: first fractional entry is `▕` U+2595 (RIGHT-flush) — a 1/8-filled cell on a left-growing bar must be `▏` U+258F (left-flush); current table opens a gap at the cap.
- **BL-3** `phpunit.xml` `failOnWarning="true"` per AGENTS.md skeleton — absent in sugar-charts and sugar-dash (measured 2026-09-01; the candy-core reference lacks it too, verify each before editing).
- **BL-4** Reflection-based alias-completeness test for sugar-charts: enumerate public `with*()` on chart classes and assert each has a registered short alias (today `ShortAliasesTest` only spot-checks).
- `sugar-dash/src/Plot/Chart/Chart.php` `bufferFromOutput()` byte-indexes `$line[$col]` then `mb_substr` — multibyte mis-slice in diff re-render path (real bug, unrelated to glyphs).
- Dead tables (wire or delete — removal is Backlog-only per ground rule 12): `ProgressRing::CHARS_FULL/EMPTY` (`sugar-dash/src/Plot/Chart/ProgressRing.php:28-29`), `Plot::MARKER_DOT` never read (`sugar-dash/src/Plot/Plot.php:31`), `Chart::BAR_CHARS` never read (`sugar-dash/src/Plot/Chart/Chart.php:90`), `GaugeCircle::ARC_CHARS` (`sugar-dash/src/Plot/Chart/GaugeCircle.php`) + `Sunburst::ARC_CHARS` (`sugar-dash/src/Components/Tree/Sunburst.php`) — S7 copies the arc glyphs into Donut; deleting/rewiring the originals is its own follow-up.
- `GaugeCircle` arc could reuse S4 aspect math + S5 quadrant sampling for rounder gauges.
- `sugar-charts/src/MarkLine.php` solid/dashed/dotted are name-strings only, rendering unwired.
- sugar-charts `LineChart` ASCII connectors (`| - / \`, `LineChart.php:643-672`) could upgrade to `│─╱╲` like Waveline when UTF-8 locale detected.

## Known caveats

(resolved ambiguities land here so successor sessions don't relitigate — keep empty until one is earned)

- 2026-09-01: `UPDATE_GOLDENS=1` (candy-testing `Assertions::assertGoldenAnsi`) only CREATES missing goldens; it NEVER overwrites an existing differing golden. To re-record sugar-charts goldens: delete the `.golden` first, run suite, keep the new file.
- 2026-09-01: PHPUnit 10.5.64 rejects `--regenerate` in BOTH documented positions (`-- --regenerate` → "Test file not found"; bare → "Unknown option"); sugar-dash golden re-record requires either `php tools/generate-goldens.php --dimensions <dim>` (rewrites tool-style header `PHP 8.3.x...`) or a reflection replay of `GoldenSnapshotTest::runExample` + its regenerate branch (writes header `PHP (test)`; S4 used this). Comparison strips all header lines above `---` so both header styles pass.
- 2026-09-01: 'bubble'/'calendar'/'clock'/'timer'/etc ∈ `GoldenSnapshotTest::SKIPPED`; 'donut' is NOT — S4-class changes re-record donut goldens only.
- 2026-09-01: S1 amended to geometry fix per human ruling Option A after plan-drift stop (disk test dx²+dy²≤r² never emitted corner cells; table-only ◠→◞ was a provable no-op). Approved default-output change scoped to Bubble only.

## Completion criteria (whole plan)

All 7 steps committed to master; both lib suites green; `chart_worklog.md` shows each step's review loop terminated with zero findings; README/ShortAliases coverage consistent; zero golden drift outside steps that declared intended output change (S4 only).
