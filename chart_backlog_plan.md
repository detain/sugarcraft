# Chart BACKLOG Plan (chart_backlog_plan.md)

> v3 round. Companion: chart_plan.md (v2 plan, complete), chart_worklog.md (ledger), chart_prompt.md (protocol v3). HEAD at intake: 78d581732. Ground rules carry over UNCHANGED from chart_plan.md §§1-12 (style, flags-default-off, two golden systems, write-sets-are-ceilings, test-count arithmetic, GR-12 with B12 as the sanctioned sweep exception). Every implementer brief goes BY PATH: "read chart_backlog_plan.md step B<n> + Ground rules".

## Intake verdicts (2026-09-02, premises re-derived live at 78d581732)

All line numbers below re-verified at intake; where the recon pass at issue-time had drifted, the live number is used.

| Item | Verdict | Evidence (one line) |
|---|---|---|
| BL-1 | TRUE | Donut centerLabel/centerValue/showPercentage constructed + threaded through 9 withers, zero reads in render()/renderEmpty()/renderWireframe(); docblock:17 advertises "Optional center text"; no text helper in Donut; ctor has 12 params, 11 `new self(` sites (verified at :165,211,715,736,757,778,799,825,858,906,945); Donut.php:548 already carries an inline comment conceding "centerLabel/centerValue remain unwired, Backlog BL-1". |
| BL-2 | TRUE w/ correction | `▕` U+2595 is $blocks index 1 (index 0 is `░`), RenderBar.php:93; docblock:72-76 advertises left-flush `▏`-led ramp (contradicts code); class reachable ONLY from own test (census: RenderBar.php + RenderBarTest.php only); no existing byte-test breaks on fix, but RenderBarTest.php:104 regex encodes the wrong alphabet; repo-wide `▕` census = exactly 2 occurrences (:93 + test :104), both in this step's write-set. |
| BL-3 | TRUE w/ rationale-correction | no failOnWarning in sugar-charts/phpunit.xml or sugar-dash/phpunit.xml, BUT the AGENTS.md "canonical reference" candy-core/phpunit.xml also lacks it; repo-wide exactly 1 of 58 non-vendor phpunit.xml has it (sugar-crush/phpunit.xml:6). Rationale "align with reference" VOIDED; keep as own merit (AGENTS.md text stands, docs are outlier source). |
| BL-4 | TRUE w/ gaps measured | ShortAliasesTest 80L hand-written, no reflection; 123 with* across src (verified), 73 aliases (verified), 27 with* have NO alias (Streamline 5, Waveline 6, TimeSeries 4, Picture 2, Legend 4, Chart withAnimationProgress/Duration, BarChart withTitlePosition/withNoAutoBarWidth, Heatmap withColorProfile/withAutoValueRange — every named wither verified present, every candidate alias verified absent); convention deviations to honor: legendPos, dataLabelFormat, fractional (all verified in src); no registry; Chart is `abstract` (:21), LineChart `final extends Chart` (:27) and aliases inherited via parent (inheritance semantics must be defined in test). — AMENDED: 36 gaps, not 27 (see B1) |
| BL-5 | TRUE | withAspect (Donut.php:823) no guard; house precedent: numeric setters CLAMP (ProgressRing:161/188, GaugeCircle:243/318, Meter:204/223/293, Plot:89, RenderBar:~92); string-enum setters THROW (withFillStyle :895, withRenderMode :934); zero throws on numeric args today. Decision → Rulings Q2. |
| BL-6 | TRUE | "// 3x3 bubble" comment still at Bubble.php:379 (plotBubble size===2 branch → r=1 five-cell plus). |
| BL-7 | measured | r=2 renders FULL solid 5x5 box (4 corner arcs ◜◝◟◞ + 21 ●) pinned by BubbleTest.php testMediumBubbleRendersConnectedBoxWithFullDotFill :500-524; r=1 legacy plus pinned by testSmallBubbleKeepsPlusShapeWithoutCornerArcs :526-547; mapSize bins 1..4 (verified max(1, intval(1+ratio*3))) but plotBubble only distinguishes ≤1/==2/else (verified :370/:378/:381) → sizes 3 AND 4 both → r=2 box; `withSizeRange(x,x)` div-by-zero in mapSize:472 (render defends X/Y ranges only, Bubble.php:257-262). Semantics → Rulings Q4. |
| BL-8 | TRUE | examples/donut.php:12 and examples/bubble.php:9 discard setSize() clone; BONUS: examples/chart.php:12 same bug (all three verified verbatim). Donut default size=20 (ctor verified) → current donut.goldens are 20x20 body; sugar-dash/tests/Golden/GoldenSnapshotTest.php:147-151 patches setSize args to canvas dims but patch is inert while return discarded. donut NOT in SKIPPED, bubble IS; chart NOT in SKIPPED. ⚠ nuance: bubble.golden ×2 exists on disk (stale since cf8d1ab37) but is never compared because bubble ∈ SKIPPED — treat as dead data, not in any write-set. Fix ⇒ re-record donut.golden ×2 dims (+chart.golden ×2 if chart.php included — Rulings Q3). |
| BL-9 | CHANGED | segmentAt() EXISTS (Donut.php:458-475, extracted S5); remaining dup = ONE inline copy in render() non-smooth path :307-323 (char-for-character segmentAt body, incl. identical comments — verified); S7 renderWireframe forward-angle sites (~:566-572 ellipseCell, ~:607-610 corner atan2→point, ~:631-638 divider fmod+ellipseCell) are forward math (deg→point), NOT sector lookup — leave them, optionally cross-ref comment (note: rim :590 and corners :616 already call segmentAt). Work = collapse inline copy to `$this->segmentAt($dx, $dy, $total)`; oracles (PRE_S5 954349b3…/f3569493…, PRE_S6 46616bb0…/f3569493…, pinned in DonutTest.php:78/:95/:115/:180) must stay green. |
| bufferFromOutput | TRUE w/ correction | bug at Chart.php:791 (`isset($line[$col])` byte probe + `mb_substr($line,$col,1)` codepoint slice); NOT dead: called from public render() (defined :155) diff path Chart.php:195/203; live via examples/chart.php + ChartTest (36 renders). ANSI escape bytes also land in buffer cells — OUT of scope for minimal fix (park w/ quote). |
| Dead tables | measured | ProgressRing CHARS_FULL/CHARS_EMPTY :28-29 READ=0 (renders ●/○ literals :105/:113); Plot::MARKER_DOT :31 — CHANGED: referenced in PlotTest :112/:131/:293 + examples/plot-braille.php:20 BUT property write-only (never consulted; only write is :75; braille always renders) → the KNOB is dead, not the symbol; Chart::BAR_CHARS :90 READ=0 (literal █ at :254); GaugeCircle ARC_CHARS+TICK_SMALL+TICK_LARGE+NEEDLE+CENTER :29-33 all READ=0 + dead method calculateArcPosition :79 READ=0 (literals hand-inlined at :133-183); Donut has own LIVE private ARC_CHARS :102 read at :618/:699/:702 (byte-identical duplicate — S7 design, keep; its docblock already cites GaugeCircle); Sunburst ARC_CHARS :94-100 READ=0 (octant inline box-drawing getArcChar :387, match arms :401-411). ⚠ intake-verify B12(d): GaugeCircle tick sites inline `'┬'/'│'` (:155) and `'┴'/'│'` (:164) which are NOT the values of TICK_SMALL='·'/TICK_LARGE='┼' — byte-identical wiring is IMPOSSIBLE at tick sites; only NEEDLE (:178 '❮') and CENTER (:183 '◆') match their consts. Escalated into Q5. |
| GaugeCircle aspect/quadrant upgrade | PARKED → v4 | scope measured (270° forward sweep :112-140, no aspect knob, single-cell writes). Rulings Q6. |
| Sunburst center-label byte-index hazard | SIDE FINDING, PARKED → v4 | Sunburst.php:292-293 (`$this->centerLabel[$labelIndex]` + `strlen` bound — same multibyte hazard class as B11). |
| BL-10 | VOIDED (no code step) | protocol/record-hygiene lesson already enforced by chart_prompt.md v3 text (no verdict before artifact; inputs pre-exported). |

## Rulings

> RULINGS RECEIVED 2026-09-02 — build spawns authorized. Answers verbatim from orchestrator bundle; this section is authoritative for gated steps B5-B13.

Q1 BL-1 center-text contract: **APPROVED as proposed** — filled render() draws up to two lines centered in the hole; primary = centerValue, else when showPercentage && centerValue===null: first-segment share number_format(share,0).'%'; optional centerLabel line below; mb-safe truncation to inner-diameter minus 2 cells; label omitted when hole too narrow; text never overwrites ring cells; wireframe stays shape-only. **Binds B6.**

Q2 BL-5 guard: **STRICT THROW** (InvalidArgumentException on <=0 / non-finite, mirroring withFillStyle/withRenderMode). **Binds B5.**

Q3 bug-class output batch: **APPROVED ALL THREE; chart.php INCLUDED in B10** — (a) B8 RenderBar ▕→▏ approved; (b) B11 bufferFromOutput mb fix approved incl. conditional chart.golden re-record IF measurement shows bytes move; (c) B10 examples donut+bubble+chart setSize-clone assignment approved, donut.golden ×2 AND chart.golden ×2 re-record declared. **Binds B8, B10, B11.**

Q4 BL-7 bubble semantics: **KEEP SOLID 5x5 BOX** — B9 scope reduces to: (1) stale '3x3 bubble' comment fix + sibling stale wording; (2) mapSize div-by-zero guard for withSizeRange(min==max) (contract: degenerate range ⇒ ratio 1, all points bin to largest size) + ≥1 test; NO shape change, NO test rewrites. **Binds B9.**

Q5 dead tables: **APPROVED ALL AS RECOMMENDED** — ProgressRing CHARS_FULL/CHARS_EMPTY DELETE; Plot MARKER_DOT const + $marker property + withMarker() + 4 refs (PlotTest ×3, examples/plot-braille.php) DELETE; Chart::BAR_CHARS DELETE; GaugeCircle NEEDLE+CENTER WIRE byte-identically, ARC_CHARS + calculateArcPosition + TICK_SMALL/TICK_LARGE DELETE (ticking-glyph mismatch ⇒ wiring would change output; deletion ruled); Sunburst ARC_CHARS DELETE. **Binds B12.**

Q6 scope: **B2 MarkLine rendering AND B3 Unicode connectors BOTH IN this round.** **Binds lane A.**

Q7 BL-3 dash: **LEGITIMIZE + FLIP** — B13 = #[WithoutErrorHandler] on GenericModuleTest::testFailedProcOpenFailsGracefully + doc-comment, empirically verify Warnings: 0, then flip sugar-dash/phpunit.xml failOnWarning="true"; if attribute fails to silence, STOP and park dash half (no src edits, no test deletion). **Binds B4 (unconditional) + B13.**

## Steps

Baselines for ALL steps: charts@78d581732: 554/1338/0Sk rc0; dash@78d581732: 5894/9389/3Sk rc0 W1(GenericModule.php:98).
Donut threading rule: ANY new ctor param must thread all 11 new-self sites + setSize clone verified; oracles (PRE_S5 954349b3…/f3569493…, PRE_S6 46616bb0…/f3569493…) must stay green.

### B1 (Lane A) sugar-charts: alias-completeness — add 36 missing short aliases + reflection pin [BL-4]

**Why**: 36/123 with* lack aliases — the 27 enumerated at Intake verdicts BL-4 (Streamline 5, Waveline 6, TimeSeries 4, Picture 2, Legend 4, Chart withAnimationProgress/Duration, BarChart withTitlePosition/withNoAutoBarWidth, Heatmap withColorProfile/withAutoValueRange) PLUS 9 measured gaps: Chart withTitle/withCanvas/withTheme, LineChart withXLabelFormatter/withYLabelFormatter/withDataset/withDatasetPoint, Sparkline withStyle/withNoAutoMaxValue (amended post-build-1 drift-stop @04:38:23Z: reflection census found 41 roster unmapped of 123; 5 of them are LineChart overrides of Chart-base withers that self-resolve by inheritance once Chart gains aliases; 9 were unenumerated at intake; the 73-alias intake figure was a census artifact — ~82 mapped pre-step); ShortAliasesTest spot-checks only; no registry.

**Design**: add plain one-line delegators per existing block convention (`/** Short-form alias for {@see withX()} */ public function x(...): self { return $this->withX(...); }`) for ALL 36 gaps named in **Why** (the Intake verdicts BL-4 27 + the 9 measured drift-stop additions: Chart withTitle/withCanvas/withTheme, LineChart withXLabelFormatter/withYLabelFormatter/withDataset/withDatasetPoint, Sparkline withStyle/withNoAutoMaxValue); new names follow convention `withFoo→foo` EXCEPT Position-suffix → Pos (titlePos, mirroring legendPos) and animationProgress/animationDuration/colorProfile/autoValueRange straight lcfirst-drop-with; new test (extend ShortAliasesTest or new tests/AliasCompletenessTest.php): reflection over every final concrete chart class (Chart abstract base: test its own declared with* too), for each public instance with* method assert method_exists of the mapped alias (exception map for the 3 historic deviations: withLegendPosition→legendPos, withDataLabelFormatter→dataLabelFormat, withFractionalHeights→fractional); inherited resolution counts (method_exists on instance covers Chart::legend() for LineChart); refresh README.md alias blurb (~lines 49-53) to current set. Design caveat (amended): `withTitle→title` alias on Chart base MUST signature-match the leaf `title()` methods already present on Scatter/BarChart/OHLCChart exactly (inspect each first) so no LSP break; where LineChart re-declares with* via lineChartCopy, confirm the inherited alias still dispatches to the override ($this late binding) — one extra LineChart alias method only if signatures diverge.

**Write-set ceiling**: sugar-charts/src/{Chart/Chart.php, Sparkline/Sparkline.php, BarChart/BarChart.php, LineChart/LineChart.php, LineChart/Streamline.php, LineChart/Waveline.php, LineChart/TimeSeries.php, Heatmap/Heatmap.php, OHLC/OHLCChart.php, Scatter/Scatter.php, Picture/Picture.php, Legend/Legend.php}, sugar-charts/tests/ShortAliasesTest.php (+ optional NEW sugar-charts/tests/AliasCompletenessTest.php), sugar-charts/README.md.

**Verify**: `cd /home/sites/sugarcraft/sugar-charts && vendor/bin/phpunit > /tmp/b1-charts.log 2>&1; echo rc=$?` — expect rc0, +1..4 tests vs 554/1338, ZERO changes to existing tests/fixtures (aliases are additive; goldens untouched). All existing tests must pass UNCHANGED; alias additions are pure delegators.

**Declared-output-change flag**: NO output change.

### B2 (Lane A, GATED on Q6) sugar-charts: wire MarkLine rendering into LineChart

**Why**: MarkLine VO complete (src/MarkLine.php, 95L) but zero consumers (census: only MarkLineTest); docblock:12-13 admits "Rendering integration … not yet wired".

**Design**: LineChart gains `withMarkLines(array $marks)` (list of MarkLine; default []) threaded via lineChartCopy + short alias `markLines` + ShortAliasesTest case; render pass after series/before frame: for each mark whose value ∈ [minY,maxY]: row = existing value→row mapping; paint plot-width run with style glyph solid `─`(U+2500) / dashed `╌`(U+254C) / dotted `┄`(U+2504); if label !== '' and plot width > label mb-width + 2, overwrite the rightmost label-width cells of that row with the label (mb-safe); existing point/connector cells on that row are replaced by the mark line (marks are annotations ON TOP). Default empty array → byte-identical output.

**Write-set ceiling**: sugar-charts/src/LineChart/LineChart.php, NEW sugar-charts/tests/LineChart/LineChartMarkLineTest.php, sugar-charts/tests/ShortAliasesTest.php, sugar-charts/README.md (optional mention).

- sugar-charts/tests/AliasCompletenessTest.php (census pin 123→124 + any roster touch REQUIRED by GR-2 coupling — AMENDED at B2-review-c1 prep: ceiling pre-dated the B1 completeness-test coupling; new withMarkLines() forces re-pin or suite is red)

**Verify**: full charts suite rc0; +4..7 tests vs post-B1 count; existing fixtures porcelain EMPTY; existing tests zero edits.

**Declared-output-change flag**: NO output change (opt-in only).

### B3 (Lane A, GATED on Q6) sugar-charts: opt-in Unicode connectors for LineChart

**Why**: LineChart::drawConnector (private static, ~:668) hardcodes ASCII `| - \ /` while sibling Waveline::connectorRune (:175-183) already emits `│─╱╲`; dead unused `string $point` param in drawConnector (verified: name absent from body).

**Design**: `withUnicodeConnectors(bool $on = true)` + alias `unicodeConnectors` threaded via lineChartCopy; drawConnector gains explicit `bool $unicode` param (drop the dead $point param — internal static, no external callers; verify with /usr/bin/grep census first); Unicode path uses the Waveline slope mapping exactly; ASCII stays default (GR-7; auto-detect via T::detect is language-only, not terminal-charset — invented-capability idea PARKED). Interaction note: lineStyle (S2) scopes to axis frame only; connectors independent.

**Write-set ceiling**: sugar-charts/src/LineChart/LineChart.php, sugar-charts/tests/LineChart/LineChartTest.php, sugar-charts/tests/ShortAliasesTest.php, sugar-charts/README.md.

**Verify**: full charts rc0; +2..4 tests; existing goldens/tests byte-unchanged (flag default off).

**Declared-output-change flag**: NO output change (opt-in).

### B4 (Lane A LAST) sugar-charts: flip failOnWarning [BL-3 charts half]

**Why**: AGENTS.md canonical PHPUnit XML prescribes `failOnWarning="true"`; charts lacks it. Rationale note (intake-corrected): only precedent in repo is sugar-crush (:6); candy-core lacks it — docs are the outlier source, step proceeds on own merit.

**Design**: add `failOnWarning="true"` to sugar-charts/phpunit.xml attributes block (match sugar-crush/phpunit.xml:6 formatting).

**Write-set ceiling**: sugar-charts/phpunit.xml ONLY.

**Verify**: full charts rc0 — baseline shows 0 warnings at 78d581732 so flip must be a no-op green (if rc≠0, the flip EXPOSED a warning: STOP, do not silence, report finding). Delta 0 tests.

**Declared-output-change flag**: config-only.

### B5 (Lane B, Donut-serial 1) sugar-dash: withAspect guard [BL-5]

**Why**: withAspect (:823) accepts any float incl. 0/negative/NaN → degenerate or inverted geometry; every sibling numeric setter clamps, every string-enum setter throws; aspect sits between the two families.

**Design**: per Rulings Q2 outcome. If THROW (recommended, matches Donut's own withFillStyle (:895)/withRenderMode (:934) precedent): `if (!is_finite($ratio) || $ratio <= 0.0) { throw new \InvalidArgumentException(sprintf(...)) }` mirroring S6 message shape; @throws in docblock. If CLAMP: max(0.1,$ratio) floor documented + NaN→default. Tests: reject 0.0/-2.0/NAN (or clamp-equivalent behavior pins), 1.0/3.0 still accepted (existing tests must stay green).

**Write-set ceiling**: sugar-dash/src/Plot/Chart/Donut.php (withAspect + docblock), sugar-dash/tests/Plot/Chart/DonutTest.php.

**Verify**: `cd /home/sites/sugarcraft/sugar-dash && vendor/bin/phpunit > /tmp/b5-dash.log 2>&1; echo rc=$?` — expect rc0 W1-baseline, +2..6 tests vs 5894/9389 (delivered 6: rejects 0.0/-2.0/NAN/+INF/-INF + accept 0.01-pin; orchestrator brief specified the ±INF pins), goldens porcelain EMPTY, oracles green.

**Declared-output-change flag**: NO default-path output change.

### B6 (Lane B, Donut-serial 2) sugar-dash: wire center text [BL-1] — contract per Rulings Q1

**Why**: three center-text knobs (centerLabel/centerValue/showPercentage) have been constructed and threaded through 9 withers since forever, are advertised in the class docblock (:17), and are read by nothing.

**Design** summary (Q1 final wording is BINDING): filled render() draws up to two centered lines inside the hole — primary line = centerValue, or when showPercentage && centerValue===null: percentage string per Q1 semantics (contract default: first-segment share, number_format(share,0).'%'); optional centerLabel second line below when hole height allows; mb-aware widths; truncate primary/label to fit inner diameter minus 2-cell breathing (prefer truncation over omission; omit label if hole too narrow); never paint over ring cells; wireframe mode per Q1 (contract default: unchanged, shape-only); defaults null/false → ZERO byte change on default path; also fix the class docblock indent glitch (~:19-21 stray under-closed `*` — confirmed at :19-21) and update Features list wording to match what is now true.

**Write-set ceiling**: sugar-dash/src/Plot/Chart/Donut.php, sugar-dash/tests/Plot/Chart/DonutTest.php.

**Verify**: full dash rc0 W1, +4..7 tests, goldens porcelain EMPTY (default off — if a donut golden moves, it is a finding), PRE_S5+PRE_S6 oracles green.

**Declared-output-change flag**: NO default output change (opt-in via already-existing withers).

### B7 (Lane B, Donut-serial 3) sugar-dash: collapse angle-math duplicate into segmentAt [BL-9]

**Why**: segmentAt (:458-475) and the inline block in render()'s non-smooth ring test (:307-323) are char-for-character identical (incl. comments) — drift hazard on the sector-boundary math the oracles pin.

**Design**: replace the inline atan2/normalize/startAngle block in render() non-smooth path (~:305-323) with `$this->segmentAt($dx, $dy, $total)`; do NOT fmod-normalize (single-correction ≥360 edge stays as-is; note it in a doc-comment); optionally cross-ref comment at the renderWireframe forward-angle sites (ellipseCell ~:566-572, corner atan2 ~:607-610, divider ~:631-638) stating they are intentionally forward (deg→point) math, not duplicates.

**Write-set ceiling**: sugar-dash/src/Plot/Chart/Donut.php (render() branch + comments), sugar-dash/tests/Plot/Chart/DonutTest.php (optional 1 regression pin of current startAngle≥360 semantics — if it locks a wart, prefer documenting over pinning; builder judgment, explain in report).

**Verify**: full dash rc0 W1, delta +0..1; BOTH raw oracle pairs green; goldens EMPTY.

**Declared-output-change flag**: NO output change (byte-identity is the acceptance criterion).

### B8 (Lane B disjoint) sugar-dash: RenderBar ramp fix [BL-2]

**Why**: $blocks index 1 is `▕` (right-flush U+2595) inside an otherwise left-flush eighths ramp — visually a glitch at the 1/8 step; docblock (:72-76) promises the correct `▏`-led ramp; class is reachable only from its own test so the fix is cheap.

**Design**: $blocks index 1 `▕`→`▏` (U+2595→U+258F, RenderBar.php:93); fix docblock :72-76 to describe the 9-entry table honestly (shade `░` + left-flush eighths `▏▎▍▌▋▊▉` + full `█`); RenderBarTest.php:104 regex alphabet → include `░` and `▏` (drop `▕`); add byte-pinned test reaching index 1 exactly (find $percentage/$width combo where blockIndex==1, e.g. derive from fullBlocks math at :92-100) asserting `▏` flush after/before correct neighbors; census `▕` across sugar-dash src/tests/examples (/usr/bin/grep) — intake census = exactly 2 (:93, test :104); must end at zero occurrences after the step.

**Write-set ceiling**: sugar-dash/src/Output/RenderBar.php, sugar-dash/tests/Output/RenderBarTest.php.

**Verify**: full dash rc0 W1, +1..3 tests, goldens EMPTY (class is test-only-reachable).

**Declared-output-change flag**: declared bug-class output change for external consumers — GATED on Rulings Q3.

### B9 (Lane B disjoint) sugar-dash: Bubble comment + shape semantics + sizeRange guard [BL-6/BL-7]

**Why**: "// 3x3 bubble" (:379) mislabels an r=1 five-cell plus; the r=2 5x5 is a FULL solid box (pinned by test :500-524) which reads as a block not a bubble; mapSize (bins 1..4) collapses sizes 3+4 to the same glyph and `withSizeRange(x,x)` divides by zero at mapSize:472 (render defends X/Y only, :257-262).

**Design**: (1) fix "// 3x3 bubble" → accurate wording (r=1 five-cell plus within 3x3 extent; Bubble.php:379) and any sibling stale comments in plotBubble; (2) per Rulings Q4: KEEP-SOLID (void shape change; update callee docblock wording only) OR RING-ONLY (outer boundary cells of the (2r+1) box get glyphs — corners from CIRCLE_CHARS arcs at |dx|==|dy|==r, other boundary cells `●`, interior blank except optionally center per Q4 sub-choice; update testMediumBubbleRendersConnectedBoxWithFullDotFill + any test whose assertions the ruling flips — declare each as an intended test change, NOT weakening); r=1 plus stays unless Q4 says otherwise; (3) mapSize div-by-zero guard: withSizeRange(min==max) must not divide by zero (contract default: ratio=1 when range degenerate); +1 test.

**Write-set ceiling**: sugar-dash/src/Plot/Chart/Bubble.php, sugar-dash/tests/Plot/Chart/BubbleTest.php.

**Verify**: full dash rc0 W1; delta per ruling outcome (solid: +1..2; ring: shape tests rewritten, count +1..3); goldens EMPTY (bubble ∈ SKIPPED — the two stale bubble.golden files on disk are never compared; do not touch).

**Declared-output-change flag**: possible default-output change (ring option) — GATED on Rulings Q4.

### B10 (Lane B disjoint) sugar-dash: examples clone-assignment + golden re-records [BL-8]

**Why**: examples/donut.php:12, examples/bubble.php:9 AND examples/chart.php:12 all call `$component->setSize(60, 15);` and discard the immutable clone — the goldens therefore render at the ctor default (donut = 20x20 body), and GoldenSnapshotTest's setSize patch (:147-151) is inert against a discarded return.

**Design**: assign the returned clone in examples/donut.php (`$component = $component->setSize(60, 15);`) and examples/bubble.php (:9) — per Q3 include examples/chart.php:12 too (recommended); re-record affected goldens via the reflection replay of GoldenSnapshotTest::runExample regenerate branch (documented working route; phpunit --regenerate BROKEN on 10.5.64 — do NOT use it; alternative `cd sugar-dash && php tools/generate-goldens.php --dimensions <dim>` ⚠ intake-verify: tool lives at sugar-dash/tools/generate-goldens.php, not repo-root tools/; header cosmetic diff acceptable since comparison strips above `---`); harness patches setSize args to canvas dims (GoldenSnapshotTest.php:147-151) so goldens become full-canvas donut (size=min(80,24)=24 rows / min(120,40)=40 rows) — read every re-recorded golden as content in the report; do NOT create donut-wireframe goldens (2 missing-golden skips are the accepted deterministic baseline unless a ruling says otherwise; verified: examples/donut-wireframe.php exists, no donut-wireframe.golden in either dim, skip via GoldenSnapshotTest.php:246); bubble has no LIVE golden (∈ SKIPPED; stale on-disk files untouched).

**Write-set ceiling**: sugar-dash/examples/donut.php, sugar-dash/examples/bubble.php, sugar-dash/examples/chart.php (if Q3 includes), sugar-dash/tests/golden/80x24/donut.golden, sugar-dash/tests/golden/120x40/donut.golden, sugar-dash/tests/golden/80x24/chart.golden + sugar-dash/tests/golden/120x40/chart.golden (only if chart included).

**Verify**: full dash rc0 W1 3Sk (Skipped count must STAY 3 — if it moves, explain exactly why); zero test count delta expected (goldens are data); porcelain shows ONLY the declared golden files changed.

**Declared-output-change flag**: DECLARED OUTPUT CHANGE (donut goldens ×2, chart goldens ×2 if included) — GATED on Rulings Q3.

### B11 (Lane B, precedes B12 — shared file) sugar-dash: bufferFromOutput multibyte fix

**Why**: Chart.php:791 guards with a BYTE probe (`isset($line[$col])`) but slices a CODEPOINT (`mb_substr($line,$col,1)`) — on any line containing multibyte glyphs the column index and byte offset diverge: wrong char mid-run, empty-string cells past the byte length. Live via public render() (:155) diff path at :195/:203.

**Design**: Chart.php:791: replace `isset($line[$col]) ? mb_substr($line,$col,1) : ' '` with a codepoint-consistent guard — `$len = mb_strlen($line); $char = $col < $len ? mb_substr($line, $col, 1) : ' ';` (hoist $len per line); MINIMAL fix only — ANSI-in-buffer and the first-frame path stay as-is (park w/ quotes in ## Parked); add tests: a frame line containing multibyte glyphs (█) where a diff past the multibyte run must slice correct codepoints (drive Chart public render() multi-frame diff path per ChartTest patterns, or test bufferFromOutput via reflection if render-path pinning is too indirect — justify choice).

**Write-set ceiling**: sugar-dash/src/Plot/Chart/Chart.php (bufferFromOutput only), sugar-dash/tests/Plot/Chart/ChartTest.php; CONDITIONAL additions: the two chart.golden files ONLY IF the fix verifiably moves them (measure first; if moved, the step must re-record + show content — and that re-record belongs to THIS step's declaration, not B10's).

**Verify**: full dash rc0 W1, +1..3 tests; report explicitly whether chart.golden bytes moved.

**Declared-output-change flag**: bug-class output change in diff path — GATED on Rulings Q3.

### B12 (Lane B, AFTER B11) sugar-dash: dead-tables sweep [GR-12 sanctioned exception — removals ONLY per Rulings Q5]

**Why**: seven dead glyphs tables/knobs mislead readers into thinking they control output; GR-12 normally forbids removal but this sweep IS the sanctioned exception, per-table dispositioned by Q5.

**Design** per recommended Q5 dispositions (Q5 final wording BINDING): (a) ProgressRing: DELETE CHARS_FULL/CHARS_EMPTY consts (:28-29; values are combining-underline garbage, renderer legitimately uses ●/○ literals :105/:113) — re-census zero reads before removing; (b) Plot: DELETE MARKER_DOT const (:31), the write-only $marker property (only write :75, never read), withMarker() (:72-76; public API removal — needs explicit Q5 approval), + update the 3 PlotTest refs (:112 assertion, :131/:293 builder calls) and examples/plot-braille.php:20 (remove ->withMarker call — knob was inert so braille output unchanged → chart/plot goldens must NOT move; 'plot-braille' golden check: if the example has goldens, verify byte-identical); (c) Chart: DELETE BAR_CHARS :90 (literal █ at :254 stays); (d) GaugeCircle: WIRE — replace hand-inlined literals with existing const reads where the const VALUE matches the literal: NEEDLE (:178 '❮') and CENTER (:183 '◆') are byte-identical by construction; ⚠ intake-verify: the tick sites (:155 inline `'┬'/'│'`, :164 inline `'┴'/'│'`) do NOT match TICK_SMALL='·'/TICK_LARGE='┼' — wiring them would CHANGE output, which the byte-identity acceptance criterion forbids, so TICK_SMALL/TICK_LARGE fall to DELETE (or Q5 explicitly rules otherwise); ARC_CHARS (:29, gauge draws ●/○ dots not box arcs — literals at :133/:135/:137 stay) and dead method calculateArcPosition (:79, census 0 reads) also DELETE; (e) Sunburst: DELETE ARC_CHARS (:94-100; octant box-drawing path :401-411 never uses it). Re-census every symbol with /usr/bin/grep at build time BEFORE each removal (tree moved; brief numbers are guidance).

**Write-set ceiling**: sugar-dash/src/Plot/Chart/ProgressRing.php, sugar-dash/src/Plot/Plot.php, sugar-dash/src/Plot/Chart/Chart.php, sugar-dash/src/Plot/Chart/GaugeCircle.php, sugar-dash/src/Components/Tree/Sunburst.php, sugar-dash/tests/Plot/PlotTest.php (+ any test file asserting a removed symbol — census tests too), sugar-dash/examples/plot-braille.php.

**Verify**: full dash rc0 W1; test delta: -1..0 (dropped MARKER_DOT assertion count reported exactly; if tests deleted, list them + reason); ALL goldens porcelain EMPTY (wire byte-identical, knob inert) — ANY golden movement = STOP-and-report.

**Declared-output-change flag**: NO output change (byte-identity is the acceptance); public API removals pre-ruled by Q5 only.

### B13 (Lane B LAST) sugar-dash: W1 legitimize + flip failOnWarning [BL-3 dash half, GATED on Rulings Q7]

**Why**: dash suite carries a standing Warning: 1 — GenericModuleTest::testFailedProcOpenFailsGracefully (tests/Modules/Generic/GenericModuleTest.php:104) trips the PHP-level `proc_open(): posix_spawn() failed` warning emitted around src/Modules/Generic/GenericModule.php:98 (proc_open call). Until it is silenced legitimately, failOnWarning cannot be flipped (W1 becomes a hard red gate).

**Design**: (1) silence the standing warning LEGITIMATELY at its source test: recommended instrument: `#[\PHPUnit\Framework\Attributes\WithoutErrorHandler]` on that test method + explanatory doc-comment (attribute makes PHPUnit not capture PHP-emitted warnings as test output issues) — VERIFY empirically the suite reports Warnings: 0 after; if the attribute does not silence it, STOP — do NOT hack src (ceiling is tests); fall back to the Q7 alternative (park dash flip). (2) add failOnWarning="true" to sugar-dash/phpunit.xml (sugar-crush:6 formatting precedent).

**Write-set ceiling**: sugar-dash/tests/Modules/Generic/GenericModuleTest.php, sugar-dash/phpunit.xml. If reproducibility shows src/Modules/Generic/GenericModule.php must change → OUT OF CEILING, STOP-and-report, do not edit.

**Verify**: full dash MUST end `rc0` with `Warnings: 0` AND 5894/9389/3Sk unchanged; run before-flip (W1 present, rc0) and after-flip (W0, rc0) both logged verbatim. Delta 0 tests.

**Declared-output-change flag**: config/test-only.

## Lanes & ordering

Lane A (charts): B1 → B2 → B3 → B4 (serial; B4 last).
Lane B (dash): B5 → B6 → B7 STRICTLY SERIAL (Donut.php). Disjoint-with-A-eligible: B8 (RenderBar), B9 (Bubble), B10 (examples+goldens), B11 → B12 (SHARED FILE Chart.php — strictly serial pair, B11 first), B13 LAST in the round (flip converts standing W1 into a hard gate). Commits serialized by orchestrator. Max 2 simultaneous builds, file-disjoint.
Suggested interleave: A runs B1..B4; B runs B5,B6,B7 serially with B8/B9/B10/B11→B12 filling between Donut steps whenever file-disjoint.

## Parked (→ v4)

- GaugeCircle rounder-gauge upgrade (S4 aspect math + S5 quadrant sampling + Bresenham needle). Evidence: forward-sweep 270° renderer GaugeCircle.php:112-140, no aspect knob, needle single-point :170-180; would need new ctor axis + full threading + sampling rewrite — its own plan-step class of work, not a backlog polish item.
- Sunburst center-label byte-index hazard, Sunburst.php:292-293 (`$this->centerLabel[$labelIndex]` same multibyte hazard class as B11) — separate lib component, not chart-glyph scope.
- bufferFromOutput ANSI-in-buffer: render() emits SGR-colored strings into bufferFromOutput (Chart.php:195/203); escape sequences land as single "cells" — minimal B11 deliberately does not touch; needs a proper styled-cell ingest design.
- T::detect (candy-core/src/I18n/T.php:225-234) is a language detector, NOT a terminal-charset capability check (verified: reads LC_ALL/LC_MESSAGES/LANG only); Unicode-vs-ASCII auto-detect would need new API in candy-core (GR-11 reverse-deps) — parked; B3 ships opt-in instead.
- BL-10 — voided: protocol lesson enforced in chart_prompt.md v3 (never log verdict before artifact; inputs pre-exported), no code work.
- donut-wireframe missing goldens (2 deterministic skips) — accepted baseline; recording them requires a declared step, none this round unless Q-bundle adds one.

## B-board

| Step | BL | Lane | Build | Review loop | Commit | SHA |
|---|---|---|---|---|---|---|
| B1 alias-completeness | BL-4 | A | ✅ | ✅ | ✅ | 980001aa4 |
| B2 MarkLine rendering | MarkLine | A | ✅ | ✅ | ✅ | 9065a2784 |
| B3 Unicode connectors | connectors | A | ✅ | ✅ | ✅ | b1705726f |
| B4 charts failOnWarning | BL-3a | A | ⬜ | ⬜ | ⬜ | — |
| B5 withAspect guard | BL-5 | B(D) | ✅ | ✅ | ✅ | ea5b7d595 |
| B6 center text wiring | BL-1 | B(D) | ✅ | ✅ | ✅ | 5a5d9fe98 |
| B7 angle-math dedupe | BL-9 | B(D) | ✅ | ✅ | ✅ | f5e3fb9ae |
| B8 RenderBar ramp | BL-2 | B | ✅ | ✅ | ✅ | 4c8c16cc2 |
| B9 Bubble semantics | BL-6/7 | B | ⬜ | ⬜ | ⬜ | — |
| B10 examples+goldens | BL-8 | B | ⬜ | ⬜ | ⬜ | — |
| B11 bufferFromOutput mb | Chart.php | B | ⬜ | ⬜ | ⬜ | — |
| B12 dead-tables sweep | dead tables | B | ⬜ | ⬜ | ⬜ | — |
| B13 W1 legitimize + dash flip | BL-3b | B | ⬜ | ⬜ | ⬜ | — |

(Legend: B(D) = Donut-serial. Only commit agents tick this board.)
