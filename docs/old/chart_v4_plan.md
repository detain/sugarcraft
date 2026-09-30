# Chart V4 Plan — parked-items round (chart_v4_plan.md)

> v4 round. Companion: chart_backlog_plan.md (v3 round, COMPLETE), chart_plan.md (v2), chart_worklog.md (ledger), chart_prompt.md (protocol v3). HEAD at intake: f2c7b9822 (tree CLEAN). **RULINGS RECEIVED 2026-09-03T14:45Z — build spawns UNLOCKED; see ## Rulings Q1..Q6 (authoritative for C1..C9).** Every implementer brief goes BY PATH: "read chart_v4_plan.md step C<n> + Ground rules". v3 machinery UNCHANGED: direct master, NEVER push, one commit per step, write-sets are ceilings, test-count arithmetic declared. Ground rules carry over from chart_plan.md §§1-12 (style, flags-default-off, two golden systems, GR-12 with B12 as the sanctioned sweep exception).
>
> **Floors REMASURED fresh @f2c7b9822** — charts: 568 tests / 1400 assertions / 0Sk rc0 under failOnWarning="true" (B4 gate; /tmp/v4-base-charts.txt). dash: 5912 / 9565 / 3Sk / 0W rc0 under failOnWarning="true" (B13 gate; /tmp/v4-dash-floor.txt) — W1 FULLY LEGITIMIZED by B13: the mid-progress "PHP Warning: proc_open()…" string (log line 84) is the #[WithoutErrorHandler]-opted GenericModuleTest child warning, cosmetic stderr, NOT a PHPUnit "Warnings:" line (grep-c "Warnings:" = 0). The 3 skips: donut-wireframe missing-golden ×2 (C7 retires them) + DashboardLiveTest cassette (untouchable this round).
>
> Recon sources (all @f2c7b9822, dated 2026-09-03): /tmp/v4-reconA.txt (Sunburst PROOF, bufferFromOutput SGR/clip/@return, T::detect language-only, dash NO i18n), /tmp/v4-reconB.txt (Bubble bin fingerprint 910a658b…, GaugeCircle null-arcColor + doubly-inert setSize + port scope table, plot-braille LATENT discards), /tmp/v4-reconC.txt (donut-wireframe mechanics, replay recipe, suite floors), plus charts recon (MarkLine stale docblock, drawConnector dead-branch census 35990 pairs / 0 collisions, goldens = 3 Sparkline fixtures only).

## Rulings

> RULINGS RECEIVED 2026-09-03T14:45Z — build spawns unlocked; authoritative for C1..C9. Evidence refs retained (reconA/reconB/reconC + charts recon, all @f2c7b9822).

**Q1/R1 — Bubble bins (C5): COLLAPSE.** bins 7..9 + 10 → single 7..10 entry (zero output change — bin3 ≡ bin4 fingerprinted sha1 910a658b4a64a7b73d50807a91dd55d0540daf84, reconB); class header :17 'circles' → Unicode-shape truth; drawBubbleOnGrid/getCircleChar identifiers STAY (rename parked (d)). Binds C5.

**Q2/R2 — drawConnector (C2): REORDER GUARD.** `x2 <= x1` → `x2 < x1` so the documented vertical branch is genuinely reachable (LineChart.php :792-794 vs :796-804); public output change PROVABLY ZERO (equal-x unreachable — 35,990 adjacent pairs / 0 collisions census, charts recon); reflection-pinned direct tests both modes (ASCII `'|'` / Unicode `'│'`). Binds C2.

**Q3/R3 — GaugeCircle (C8): C8a RING-BUG ONLY.** null-arcColor branch must honor ratio (● filled / ○ remainder; today full solid ring, ○ unreachable — reconB :107-113); example path non-null ⇒ zero golden churn (builder re-derives); C8b setSize-geometry → PARKED-v5 with reconB scope note; C8c aspect+quadrant port → PARKED-v5. Binds C8.

**Q4/R4 — bufferFromOutput (C4): SGR-STRIP + @return TRUTH NOW; row-clip geometry → PARKED-v5** (B11 pins clipped grid; redesign separate — Chart.php:190/:198 clip evidence stands in reconA for v5). Binds C4.

**Q5/R5 — bug-class default-output changes: APPROVED ALL** (Sunburst multibyte/legend C3, colored re-render deltas C4) — every step MUST pin ASCII/default-path byte-identity by test; reviewers re-verify. Binds C3/C4.

**Q6/R6 — charset capability: BUILD T::charset() IN CANDY-CORE** (human override — NOT the recommended park). Additive public API; does NOT change any glyph behavior — locale auto-detection stays VOIDED-PARK (byte-identity collision evidence stands; T::detect() :225-234 language-only, suffix stripped by normalize, LineChart.php:273 docblock admission). Binds NEW step C9.

## Steps

Baselines for ALL steps: charts@f2c7b9822: 568/1400/0Sk rc0 (failOnWarning live); dash@f2c7b9822: 5912/9565/3Sk/0W rc0 (failOnWarning live). Golden work = TARGETED reflection replay ONLY (/tmp/b10a-replay.php recipe — reflection-invoke GoldenSnapshotTest::runExample + mirror the regenerate-branch header "PHP (test)\n# dimensions…\n# example…\n---\n", atomic tmp+rename); blanket tools/generate-goldens.php FORBIDDEN (:215 globs ALL examples). AliasCompletenessTest census pin = 125 EXACTLY — untouched by any v4 step (no new public withers/aliases anywhere in C1..C8; C9's candy-core `T::charset()` is additive core API, outside the charts alias census).

### C1 (Lane A) sugar-charts: MarkLine docblock truth

**Why**: src/MarkLine.php class docblock (:7-16) still describes a pre-B2 world. THREE stale phrases: (1) :9-10 "computes a dashed or solid line" — omits the dotted style (STYLE_DOTTED :22, drawn `┄` U+2504); (2) :12-13 "Rendering integration with chart classes (horizontal annotation overlay) **is not yet wired**" — wiring is LIVE since B2 (9065a2784): withMarkLines :254, short alias markLines :329, render call :570-571, drawMarkLines :612 (glyph map ─ U+2500 / ╌ U+254C / ┄ U+2504 + right-anchored mb-safe label paint); (3) :14-15 "the value can be retrieved and used programmatically via `MarkLine::at()`…" — implies programmatic-only consumption; LineChart now consumes it.

**Design**: rewrite ONLY the docblock prose :7-16 to state the truth: reference-line VO (min/max/average/at), solid/dashed/dotted horizontal overlay, consumed by LineChart via `withMarkLines()`/`markLines()` (cite the methods); keep the "Mirrors ntcharts' `MarkLine` concept" attribution line per house convention. Zero code, zero signature changes.

**Write-set ceiling**: sugar-charts/src/MarkLine.php [SYMBOLS: class docblock :7-16 ONLY].

**Verify**: full charts suite UNCHANGED 568/1400/0Sk rc0 (docs cannot move tests; failOnWarning live); absence proof `/usr/bin/grep -rn 'not yet wired' /home/sites/sugarcraft/sugar-charts/src` → 0 hits; delta +0/+0.

**Declared-output-change flag**: NO (docblock-only).

### C2 (Lane A) sugar-charts: drawConnector guard reorder — make the documented vertical branch live [R2]

**Why**: LineChart::drawConnector (private static :790) carries a vertical branch (:796-804: `if ($x2 === $x1) { $vertical = $unicode ? '│' : '|'; … }`) that is mathematically UNREACHABLE — guard `if ($x2 <= $x1) { return; }` (:792-794) swallows every equal-x call first. Docblock :785-789 advertises the `| - \ /` / `│ ─ ╱ ╲` sets; Waveline::connectorRune (:191-199) already ships the live-`│` sibling mapping. Charts recon: 35,990 adjacent sample pairs across fixtures, 0 x-collisions ⇒ the public path can never present x2===x1 today (reorder is provably zero-output).

**Design** (reorder variant; re-derive per R2): guard `$x2 <= $x1` → `$x2 < $x1` so x2===x1 falls THROUGH to the existing vertical branch (both arms — ASCII `'|'` and Unicode `'│'` — already mapped at :798). New tests: reflection-invoke the private static directly and pin the vertical paint for x1===x2 in BOTH modes (ASCII column of `'|'`, unicodeConnectors column of `'│'`; y1>y2 and y1<y2 step direction). House precedent for reflection reach-ins: v3 B5 reflection accessor test; S1 getCircleChar mirrored-table pin (BubbleTest). Public-path equal-x unreachable ⇒ existing 568 tests byte-unchanged (provable by the full-suite rc0 + fixtures-EMPTY gate). Census 125 untouched (private static — no with*/alias added).

**Write-set ceiling**: sugar-charts/src/LineChart/LineChart.php [SYMBOLS: drawConnector guard :792-794], sugar-charts/tests/LineChart/LineChartTest.php ONLY.

**Verify**: full charts rc0 (failOnWarning live!); delta +1..2 tests vs 568; assertions +2..+4; charts fixtures porcelain EMPTY — the ONLY golden files in sugar-charts are the 3 Sparkline fixtures (tests/fixtures/sparkline-basic.golden, sparkline-styled.golden, sparkline-minmax.golden — connector-independent); zero edits to existing tests.

**Declared-output-change flag**: NO (dead-branch activation only; no reachable path observes it).

### C3 (Lane B) sugar-dash: Sunburst encoding integrity [R5(i)+R5(ii)]

**Why**: reconA PROOF — center-label slice is BYTE-based: `strlen($this->centerLabel)` bound (:281) + `$this->centerLabel[$labelIndex]` byte offset (:282), window = first 3 BYTES for centerDiameter≤5. CASE 3 ('X日本語テスト', 'X' shifts the window off a codepoint boundary): full render mb_check_encoding = FALSE — mid-codepoint garbage on the center row. CASE 1 (pure CJK label): 8 of 9 chars lost. CASE 2 (ASCII control): 'ABC' of 'ABCDEFGHIJKLMNOP' — byte==codepoint, current behavior correct (byte-identity anchor). Legend: width via mb_strlen (:345) but NO truncation — over-long entry silently DROPPED whole (:355 guard; CASE 1 lost the 59-char ASCII entry); padding str_pad (:363) counts BYTES incl. embedded SGR → right-border desync (CASE 1 row 7 at col ~25 in a 60-wide box). Truncation window moved :292-296 → :276-296 post-B12. No goldens exist for Sunburst (repo-wide find census), no example (grep examples/ 0 hits), SunburstTest has ZERO multibyte coverage (23 methods).

**Design**: (a) center-label: `mb_str_split($this->centerLabel)` ONCE before the row loop; index the CODEPOINT array; preserve the exact width math (window/offset arithmetic identical, just in codepoints) — ASCII 'ABC'-of-16 control stays byte-identical (CASE 2 pinned as regression test); straddling-offset case becomes valid UTF-8 by construction. (b) legend entries: over-long entry TRUNCATES to fit (mb window + ellipsis-free hard cut per current ASCII box style — builder mirrors the center-label width budget at :345) instead of the :355 silent whole-entry DROP. (c) legend padding: width math on VISIBLE codepoints; embedded SGR must not count (strip-or-account before str_pad); add a caveat doc-comment: double-width CJK columns are a DOCUMENTED LIMITATION — no wcwidth machinery exists repo-wide (do NOT invent one; see ## Parked (c)).

**Write-set ceiling**: sugar-dash/src/Components/Tree/Sunburst.php [SYMBOLS: center-label window :276-296, legend builder :342-358, padding :363], sugar-dash/tests/Components/Tree/SunburstTest.php ONLY.

**Verify**: full dash rc0 (failOnWarning live); delta +4..6 tests (CJK center renders valid UTF-8 + full-glyph count == window width; straddling-offset 'X日本語' case; over-long legend TRUNCATED-not-DROPPED; ASCII 'ABC' byte-identity pin; optional padding-with-SGR pin); goldens: NONE exist for Sunburst → porcelain shows only the 2 ceiling files; dash Skipped stays 3 (3Sk → 3Sk; C7 owns the reduction).

**Declared-output-change flag**: YES-multibyte-only / NO-ASCII — invalid-UTF8 and dropped-legend behaviors CHANGE for malformed-input classes (R5 pre-approved class; ASCII byte-paths pinned unchanged by new tests).

### C4 (Lane B) sugar-dash: Chart.php bufferFromOutput ANSI-strip + @return truth [R4/R5(iii)]

**Why**: reconA — SGR REACHES THE BUFFER on the default path: Chart::new() (:109-125) sets color/gridColor/labelColor non-null; renderBarChart embeds truecolor at :247/:264/:280/:294 (renderLineChart :339/:363/:398 same shape); bufferFromOutput (:786, callers :190/:198) mb_str_split's the raw string → ESC,'[','3','8',';'…'m' become individual styleless cells → any SGR count/length change on a row shifts every later rune ⇒ phantom whole-row diffs on re-render. Tests deliberately dodge it: ChartTest :758 parity test runs via plainBarChart (:703-717, ALL colors null). Exactly 2 provably-wrong @return: :213 renderBarChart and :305 renderLineChart claim `list<string>` but signatures :215/:307 return string (generateGridLines :409, getInnerSize :462 verified CORRECT). Row-clip: :792/:793 drop the axis+labels rows (chartHeight+2 emitted, height clipped) and width clip at :794 — geometry per R4.

**Design**: (1) in bufferFromOutput, strip SGR before mb_str_split using the house regex `'/\x1b\[[0-9;]*m/'` (canonical occurrence: BubbleTest.php:474 strippedRenderLines) — one preg_replace on $output (or per-line) at the top of the function; cells then contain display runes only. (2) fix the 2 lying @return tags (:213, :305 → string). (3) extend the double-render parity test (ChartTest :758 pattern) to a COLORED `Chart::new` — B11 left this deliberately via plainBarChart — pinning: stored diff-buffer grid == fresh-render grid (SGR-free cells, no phantom-row diff); keep :835 ASCII + :856 multibyte-boundary pins green. (4) row-clip leg: per R4 outcome — if deferred, the existing ChartTest :798-799 doc-comment already admits it; do not silently fix. Full-frame stdout bytes: UNCHANGED — bufferFromOutput is diff-internal only (callers :190/:198 are the stored-state/diff path; B10b ledger proof: "bufferFromOutput NOT on golden stdout path").

**Write-set ceiling**: sugar-dash/src/Plot/Chart/Chart.php [SYMBOLS: bufferFromOutput :786-801 strip-insert, @return :213, @return :305], sugar-dash/tests/Plot/Chart/ChartTest.php ONLY.

**Verify**: full dash rc0; delta +2..3 tests (colored double-render parity, SGR-free-cell pin, optional no-phantom-row census); goldens porcelain EMPTY; Skipped UNCHANGED 3 (C7 owns golden adds); B11 regression pins :835/:856 stay green byte-identical.

**Declared-output-change flag**: internal diff-state change (re-render delta stream for COLORED charts shrinks/corrects — bug-class, R5-pre-approved; declare in commit body; stdout frame bytes + all goldens byte-frozen).

### C5 (Lane B) sugar-dash: Bubble bin collapse + header truth [R1]

**Why**: reconB fingerprint — mapSize returns bins 1..4 (`max(1, intval(1 + $ratio * 3))`, :470-486) but plotBubble dispatches ≤1 / ==2 / else (:368-387): bins 3 AND 4 both render the identical r=2 5x5 box (raw 7,8,9,10 all sha1 910a658b4a64a7b73d50807a91dd55d0540daf84; distinct shape classes = 3, not 4; NO test pins bin4 separately from bin3 — size-10 tests ARE the r=2 box tests). Class header :17 "Displays data points as circles" is STALE — contradicts the S1-amended :368-370 ("a plus and a solid box … never true circles, and never ASCII").

**Design** (collapse variant; if R1 rules DIFFERENTIATE this step is redesigned + re-flagged): (a) mapSize collapses 7..10 into ONE effective bin — contract: bins 1..3 (e.g. cap the intval result, `max(1, min(3, intval(1 + $ratio * 3)))` — builder verifies dispatch equivalence: bin4 currently falls in the same `else` r=2 arm, so output is byte-identical by construction); mapSize docblock :470-477 updated to the 3-bin truth. (b) class header :17 → Unicode-shape truth (single cell ●, r=1 five-cell plus, 5x5 rounded box with ◜◝◟◞ quadrant arcs). (c) remaining stale 'circle' PROSE census outside the S1-amended trio (:366-370, :390-400, :428-433): :415 inline comment "which circle character" → shape-character wording; :47 const comment half-amended (keep or tighten). IDENTIFIERS STAY: drawBubbleOnGrid (:402), getCircleChar (:434), CIRCLE_CHARS (:47) — renaming public/private API is out of scope (declared here; ## Parked (d)).

**Write-set ceiling**: sugar-dash/src/Plot/Chart/Bubble.php, sugar-dash/tests/Plot/Chart/BubbleTest.php ONLY.

**Verify**: full dash rc0; delta +0/+0 (OPTIONAL ±1: bin-table introspection pin — mapSize codomain {1,2,3} via reflection — builder's call, self-declared); existing 5912 byte-unchanged incl. all B9-era shape pins (:482/:500/:526/:549 green); goldens porcelain EMPTY (bubble ∈ SKIPPED — on-disk bubble.golden ×2 stale-inert, DO NOT touch — B10a precedent).

**Declared-output-change flag**: NO (collapse variant — byte-identical; DIFFERENTIATE outcome would re-flag YES — gated on R1).

### C6′ (rescoped @2026-09-03T16:10Z after c6-build plan-drift STOP) sugar-dash: plot-braille setSize-assign + plot-braille goldens ×2 targeted re-record [BL-8, Q5-extend]

**Why**: as originally planned — examples/plot-braille.php :21 `$dotPlot->setSize(35, 14);` and :27 `$braillePlot->setSize(35, 14);` DISCARD the immutable clones (BL-8 class; reconB ITEM 3) — PLUS measured drift: c6-build PROVED the discarding call MASKED the golden harness patchExample resize (GoldenSnapshotTest :146-151, limit=1 — `->setSize(35, 14)` rewritten to canvas dims only once the return is ASSIGNED): post-edit targeted run FAILURES 2/2 (`/tmp/c6-targeted-post.txt`); the goldens captured the 35x14 discarded-args output (bodies byte-identical CROSS-DIM AND == raw example output — the old pair's sameness was the mask's fingerprint, not proof of inertia). plot-braille is NOT in SKIPPED (original parenthetical wrong; no key :45-76). Assign-only ships red goldens = unsatisfiable Verify ⇒ re-record is mandatory-in-step. Orchestrator Q5-extends (2026-09-03, same precedent B10a/B10b/Q4): bug-class default-output change APPROVED with ASCII/default byte-identity pins — here the re-recorded goldens ARE the new default; raw example surface UNCHANGED (c6-build cmp /tmp/c6-pre.txt /tmp/c6-run.txt byte-identical, rc0).

**Design**: two assigns `$dotPlot = $dotPlot->setSize(35, 14);` / `$braillePlot = $braillePlot->setSize(35, 14);` (BL-8 house discipline, examples/donut-wireframe.php:71 precedent) + targeted reflection-replay re-record (B10a recipe /tmp/b10a-replay.php pattern — NEVER blanket tools/generate-goldens.php; headers cosmetic above `---`) for tests/golden/80x24/plot-braille.golden + 120x40/plot-braille.golden; NEW bodies genuinely dimensioned (expect DIFFERENT cross-dim — the smoke-proof the old pair's sameness was the mask). Out-of-scope note for the record: MarkerDot-vs-MarkerBraille contrast claims (header :37 + docblock :8-12) voided by B12's dead-table sweep — STAY Parked (## Parked), do not touch.

**Write-set ceiling**: sugar-dash/examples/plot-braille.php [SYMBOLS: :21, :27] + sugar-dash/tests/golden/80x24/plot-braille.golden + sugar-dash/tests/golden/120x40/plot-braille.golden (was: example-only — widened by this amendment).

**Verify**: cwd sugar-dash — `php examples/plot-braille.php` rc0 AND stdout cmp-identical to /tmp/c6-pre.txt capture (raw-surface pin); `--filter plot-braille` targeted rc0 on NEW goldens; FULL floor at spawn time (see ## C-board live — currently 5921/9607 pending C2/C4/C5 commits; committer attributes siblings) band +0 tests; goldens porcelain EXACTLY the 2 plot-braille files, zero other-golden churn; census pin UNTOUCHED.

**Declared-output-change flag**: YES — plot-braille.golden ×2 first TRUE-dimensioned render (BL-8 family, Q5-extended).

### C7 (Lane B) sugar-dash: record donut-wireframe goldens ×2 [targeted replay ONLY]

**Why**: examples/donut-wireframe.php exists, is deterministic (fixed 3-category data, no clock), and — unlike the B10-era offenders — CORRECTLY assigns the setSize clone (`$component = $component->setSize(60, 15);` :71, BL-8 discipline, docblock cites it) so GoldenSnapshotTest's patchExample (limit=1) rewrites its SOLE setSize to canvas dims and the requested geometry actually lands. It is NOT in SKIPPED; the two golden files are ABSENT → the missing-golden branch (testExampleMatchesGolden :241-247 markTestSkipped) produces exactly 2 of the suite's 3 standing skips (reconC: GoldenSnapshotTest alone 250/258/2Sk; full-suite skip list verbatim). This is the sanctioned "declared step" v3 Parked §6 said was required.

**Design**: B10a recipe (/tmp/b10a-replay.php survives — reflection-invoke GoldenSnapshotTest::runExample('donut-wireframe', 80, 24) and (120, 40), mirror the regenerate branch header verbatim `PHP (test)\n# dimensions: {dim} (WxH)\n# example: donut-wireframe\n---\n{rtrim body}`, atomic tmp+rename): FIRST to a scratch dir, sha1 double-run (determinism proof), diff-inspect bodies as content (wireframe rim runes + radial dividers + hub, ANSI-aware), THEN write to tests/golden/{80x24,120x40}/donut-wireframe.golden. NEVER tools/generate-goldens.php (blanket :215 glob — FORBIDDEN). Donut.php is NOT edited (execution only; v4 never touches Donut source).

**Write-set ceiling**: sugar-dash/tests/golden/80x24/donut-wireframe.golden + sugar-dash/tests/golden/120x40/donut-wireframe.golden ONLY (2 NEW data files).

**Verify**: GoldenSnapshotTest filter → Tests 250, Assertions ≥258, Skipped 2→0 (exact post-delta recorded live); FULL dash: Tests 5912 UNCHANGED, Skipped 3→1 (survivor = DashboardLiveTest cassette skip, untouched), Assertions +2..+4 (the 2 datasets gain their compare asserts — measure exact), rc0 under failOnWarning; porcelain under tests/golden = EXACTLY the 2 new files, zero other-golden churn; double-run sha1-identical proof in report.

**Declared-output-change flag**: data-only ADD (no code, no existing fixture moves).

### C8a (Lane B LAST) sugar-dash: GaugeCircle ring-bug fix [R3 RECEIVED → C8a ONLY; variants b/c PARKED-v5] [prerequisite: re-derive all quotes at build time]

> **Q3/R3 received 2026-09-03T14:45Z:** build the C8a bullet below ONLY. C8b (setSize-geometry) and C8c (aspect+quadrant port) → PARKED-v5 (## Parked (f)/(g)); menu text retained as record.

**Why**: three stacked defects, one component. (1) NEW (reconB): null-arcColor renders ratio-BLIND — arc branch verbatim (:107-113): `if ($isFilled && $this->arcColor !== null) { …'●'; } elseif ($this->arcColor !== null) { …'○'; } else { …'●'; }` — the `else` arm writes '●' unconditionally ⇒ a bare `new GaugeCircle(r: 0.3)` (no arcColor) paints a FULL solid ● ring; the ○ remainder is unreachable without color; only the ::new() factory masks it. (2) NEW: setSize is DOUBLY inert — :65-71 clones+stores width/sizerHeight but render() NEVER READS either field (geometry = ctor radius: diameter 2r+1, getInnerSize :202-206); examples/gaugeCircle.php:12 `$component->setSize(60, 15);` discards the return AND the target is inert anyway — 80x24 vs 120x40 gaugeCircle.golden bodies byte-identical below the header. (3) PARKED since v2 S4: zero aspect handling (grep -i aspect → 0 hits): x=cx+round(cos·r) :100, y=cy-round(sin·r) :101 in a 2:1-cell terminal ⇒ the ring is an ellipse. Goldens gaugeCircle ×2 are LIVE (NOT in SKIPPED — reconB array census) ⇒ they WILL drift under variants (b)/(c). Prerequisite re-derivation REQUIRED at build time (recon mtimes 2026-09-03; plan-intake example check re-verified today: examples/gaugeCircle.php uses `GaugeCircle::new(80)` — factory ctor :48-59 sets arcColor #874BFD / needleColor #FF6B6B / labelColor #FFFFFF, ratio 80 clamps to 1.0 ⇒ non-null color path, all-● fill, null branch unreachable from the example).

**Design — variant menu (R3 picks; C8a is the floor inside b/c):**

- **C8a ring-bug-only**: `else` arm becomes `$grid[$y][$x] = $isFilled ? '●' : '○';` (null color keeps the same shape contract as colored). Goldens: ZERO churn PROVEN by intake evidence above (factory is non-null; ratio 1.0 all-filled regardless) — builder still scratch-replays gaugeCircle ×2 + cmp before commit as belt-and-braces. Also re-derive the :167 arcColor site (grid→string color pass reads '●'/'○' — unchanged). Test plan: +1..2 (bare-ctor ratio=0.3 → both ● and ○ present, counts ∝ ratio; ratio=1.0 → all ●; ::new() path byte-identical). Ceiling: src/Plot/Chart/GaugeCircle.php + tests/Plot/Chart/GaugeCircleTest.php. Flag: bug-class, NO golden/example churn (declare in commit).
- **C8b + setSize-geometry**: render honors width/sizerHeight (radius derived from min(w, h−labelRow) — design decision documented in-code; doubly-inert setter becomes LIVE ⇒ examples/gaugeCircle.php:12 must ALSO be assigned (`$component = $component->setSize(60, 15);`) or the example stays at 13x14 while the mechanism lies). Blast radius per reconB scope table: ctor/getInnerSize :202-206 contract, setSize :65-71, render preamble :76-83. Goldens gaugeCircle ×2 MUST re-record (targeted reflection replay; 60x15 geometry now reaches output; 80x24 vs 120x40 bodies finally DIFFER — canvas-proportionate, B10a precedent pattern). Test plan: +2..4 (size→radius mapping, clamps, getInnerSize parity). Ceiling: GaugeCircle.php, GaugeCircleTest.php, examples/gaugeCircle.php, tests/golden/{80x24,120x40}/gaugeCircle.golden. Flag: YES — default output change for setSize users + goldens ×2 (declare).
- **C8c full aspect+quadrant port (Donut parity)**: port scope table VERBATIM from reconB (g): reference Donut.php :34 DEFAULT_ASPECT=2.0, :44-50 RIM_SAMPLES (quadrant sub-cell offsets + coverage bits), :57+ QUADRANT_RUNES bitmask→block table, :141/:253-256 aspect threading, :285-323 render ($dy·aspect), :383-443 smoothRimCell supersample, :458-490 segmentAt, :938+ withAspect (B5 strict-throw contract mirror); GaugeCircle touch sites: :29-30 consts KEEP, ctor :33-41 + ?float $aspect, new() :48-58 carry, render preamble :76-83, ARC LOOP :96-115 (:100-101 aspect-corrected ring test), TICK LOOPS :118-142 (both radius offsets), NEEDLE :145-154 (angle→cell), grid→string :159-186 (quadrant runes replace ●/○ rims), getInnerSize :202-206 (width/height asymmetric once aspect lands), EVERY wither :208+ threads the new ctor param, header :15 prose lands honest. Donut.php is READ-ONLY reference — never edited (v4 rule). Goldens gaugeCircle ×2 MUST re-record. Test plan: +4..8 (aspect default 2.0 vs current geometry delta, quadrant-rune rim table, needle aspect correction, withAspect strict-throw mirror of B5). Ceiling: GaugeCircle.php, GaugeCircleTest.php, (+ examples/gaugeCircle.php + goldens ×2 if b bundled). Flag: YES — biggest blast radius (declare; oracle-style before/after body census in report).

**Verify (common)**: full dash rc0 (failOnWarning live); variant-specific test deltas above; any golden movement enumerated file-by-file with targeted-replay proof (blanket regen FORBIDDEN); census pin UNTOUCHED.

**Declared-output-change flag**: variant-dependent — (a) bug-class null-path only, NO goldens; (b)/(c) YES (gaugeCircle.golden ×2 + setSize-path output), gated on R3. *(Receipt: only (a) builds; (b)/(c) PARKED-v5 per Q3.)*

### C9 (Lane A) candy-core: I18n T::charset() capability [v4 addition per Q6]

**Why**: `T::detect()` (candy-core/src/I18n/T.php:225-234) is language-only — the encoding suffix is stripped by normalize (`fr_FR.UTF-8` → `fr`), so no charset information survives the lookup; the org stance of opt-in Unicode flags exists BECAUSE charset capability was unavailable (sugar-charts/src/LineChart/LineChart.php:273 docblock admits it). Additive API unblocks future capability checks; zero behavior change today.

**Design**: `public static T::charset(): ?string` — same env chain LC_ALL→LC_MESSAGES→LANG (`$_SERVER ?? getenv`), parse the ENCODING part after '.' in the RAW value (e.g. `fr_FR.UTF-8` → `UTF-8`); `C`/`POSIX`/empty/unset → null; NO setlocale() side effects; docblock cites WHY + mirrors detect() chain structure (do NOT refactor detect() itself). Builder MUST re-derive normalize()/parse conventions live + match existing I18n test file naming (I18n test dir located: **candy-core/tests/I18n/**, file `TTest.php` present — confirm exact filename at build time and report it in the build row).

**Write-set ceiling**: candy-core/src/I18n/T.php [SYMBOLS: new `charset()` ONLY; detect()/normalize() UNTOUCHED] + candy-core I18n test file ONLY.

**Verify**: candy-core full suite rc0 (FLOOR UNKNOWN — pre-flight measures @f2c7b9822 BEFORE build spawn → /tmp/v4-base-core.txt; expected +4..7 tests via null/'C'/suffix/precedence/$_SERVER-vs-getenv cases), then REVERSE-DEP RUNS per GR-11: sugar-charts + sugar-dash full suites rc0 before commit (both floors: charts 568/1400/0 rc0; dash 5912/9565/3Sk/0W rc0). Flag: ADDITIVE public API, NO output change.

**Census**: `/usr/bin/grep -rn 'charset(' candy-core/src` — new hits = declaration + tests only; sugar-charts/sugar-dash ZERO consumers (declare explicitly — this is API surface, not wired behavior).

## C-board

| Step | Item | Lane | Build | Review loop | Commit | SHA |
|---|---|---|---|---|---|---|
| C1 MarkLine docblock truth | MarkLine | A | ✅ | ✅ | ✅ | e67a8bd89 |
| C2 drawConnector guard reorder [R2] | drawConnector | A | ✅ | ✅ | ✅ | c8b40f667 |
| C3 Sunburst encoding integrity [R5] | Sunburst | B | ✅ | ✅ | ✅ | 47e740a9f |
| C4 bufferFromOutput SGR-strip + @return [R4] | bufferFromOutput | B | ✅ | ✅ | ✅ | 5daa9c893 |
| C5 Bubble bin collapse + header [R1] | Bubble bins | B | ✅ | ✅ | ✅ | b4c22e815 |
| C6′ plot-braille assign + goldens re-record | BL-8 residue | B | ✅ | ✅ | ✅ | 56883cacc |
| C7 donut-wireframe goldens ×2 | missing goldens | B | ✅ | ✅ | ✅ | 9dd7ba25a |
| C8a GaugeCircle ring-bug [Q3 — b/c PARKED-v5] | GaugeCircle | B | ✅ | ✅ | ✅ | 0831738ad |
| C9 candy-core T::charset | v4-Q6 | A | ✅ | ✅ | ✅ | 15a9900ed |

(Only commit agents tick this board.)

## Lanes & ordering

**Lane A (charts + core)**: C1 → C9 → C2 strictly serial (per Q6 order; C9 is the candy-core step riding this lane between the two charts steps — its GR-11 reverse-dep charts suite run lands right after C1's charts touch, and C2's charts closeout stays clean). C1 is docs-only in src/MarkLine.php, C9 in candy-core src+I18n test only, C2 in src/LineChart/LineChart.php — all file-disjoint but keep serial for one-commit-per-step cadence. Charts floor 568/1400 rc0 under failOnWarning gates both charts steps; candy-core floor is measured pre-C9 (/tmp/v4-base-core.txt). **C9 triggers GR-11 reverse-dep suite runs (sugar-charts AND sugar-dash full suites rc0) at closeout before its commit.**

**Lane B (dash)**: C3, C4, C5, C6, C7 are mutually FILE-DISJOINT (C3 Sunburst.php+SunburstTest; C4 Chart.php+ChartTest; C5 Bubble.php+BubbleTest; C6 examples/plot-braille.php; C7 tests/golden/*/donut-wireframe.golden — C4∩C7 = ∅ by construction). Any subset may run concurrently WITHIN the lane only if the global cap allows. **C8a LAST in the lane** (Q3 trimmed C8 to ring-bug-only — variants b/c PARKED-v5; smallest blast radius now, but keep it serial after C3..C7 so its scratch-replay evidence isn't polluted by sibling dirty lanes).

**Cap**: max 2 concurrent builds at ANY time — one per lane. Commits serialized by the orchestrator; NEVER push; direct master. **Donut.php is NOT touched by v4 at all** (C7 replays donut-wireframe renders via reflection; no Donut source edit exists in any step — C9 touches only candy-core/src/I18n/T.php + its I18n test file).

## Parked / VOIDED

- **(a) T::detect charset capability — SUPERSEDED-BUILT by C9 per Q6** (human override of the recommended park: additive `T::charset(): ?string` lands in candy-core/src/I18n/T.php; detect()/normalize() UNTOUCHED, zero behavior change today — API surface only, GR-11 reverse-dep runs at C9 closeout). Locale auto-**DETECTION** (env-gated glyph selection) remains VOIDED-PARK unchanged — see (b); byte-identity collision evidence stands.
- **(b) Locale/env auto-detection of connector glyphs — VOIDED-PARK.** charts recon: environment-dependent output would violate B3's byte-identity pins (ASCII default byte-identical is the acceptance contract across LineChartTest + the 3 Sparkline fixtures' family); CI/locale drift becomes nondeterministic goldens. Opt-in only.
- **(c) Sunburst double-width (CJK column-advance) handling — PARKED.** No wcwidth/display-width machinery exists anywhere in the repo (reconA: legend padding byte-desync noted; C3 documents the limitation in-code rather than inventing width tables).
- **(d) Bubble identifier renames drawBubbleOnGrid / getCircleChar / CIRCLE_CHARS — PARKED.** Public/private API churn out of scope; C5 fixes the PROSE only (S1 already amended the canonical docblocks; :17/:415 land now).
- **(e) bufferFromOutput diff row-clip geometry — PARKED-v5 (Q4 received).** SGR-strip + @return truth build NOW in C4; the clip leg stays out (axis/labels rows outside the diff buffer — stale axis/labels rows on incremental frames documented, ChartTest :798-799 doc-comment admits the clip "by design"; B11 pins the clipped grid — redesign is a separate diff-geometry evidence pass).
- **(f) GaugeCircle setSize-geometry (C8b) — PARKED-v5 (Q3 received).** reconB scope intact: render never reads width/sizerHeight (setter doubly inert); examples/gaugeCircle.php:12 discards the clone — latent. Revive with the reconB blast-radius table (ctor/getInnerSize :202-206, setSize :65-71, render preamble :76-83; goldens gaugeCircle ×2 would re-record).
- **(g) GaugeCircle aspect+quadrant port (C8c) — PARKED-v5 (Q3 received).** Donut-parity port scope table reconB (g) retained verbatim in the C8a section menu for v5 revival (~6 render sites + ctor/new/withers threading + 2 new consts + gaugeCircle.golden ×2 re-record).
- plot-braille.php header :37 + docblock :8-12 "MarkerDot vs MarkerBraille" contrast is stale since B12 deleted the MARKER_DOT chain — prose-only, PARKED (no example-doc rewrite authorized this round).
- examples/gaugeCircle.php:6-8 unused Layout\Grid imports — inert; ride along ONLY inside a selected C8 variant's example touch, else PARKED.
