# P5 gate audit, cluster A (library API/surface gates)

Audited 2026-11-13 against the live tree (HEAD 552189296 plus the dirty tree) and `git log`. Source: `reviews/P5-GATE-PACKET-2.md`, `plan_review.queue.tsv`, `reviews/annotations/<slug>.md`, `reviews/OWNER-GATES.md`.
Standing owner rule applied: build or fix, do not document broken or missing things as ok. Naming rulings take rename-with-deprecated-alias.

Read-only audit. Targeted phpunit was run only where `vendor/` exists. Nothing was composer-installed, edited or staged except this file.

## 0. Headline

The gate packet is stale. It was compiled without a shell and it describes several rows as "NO-DEFAULT / open" when the build arm already landed.

| item | packet said | live state | verdict |
|---|---|---|---|
| CH-57, CH-58 | no default | untouched (no `Candle`, no `withLegendIndicator`) | BUILD, rename and alias |
| CH-32 | parked DD | untouched | BUILD, split 32a/b/c |
| CH-56 | rides GIF law #38 | untouched; AGENTS.md:52 still says "Don't commit GIFs" | BUILD, amend root AGENTS.md (fleet lock) |
| CB-15 | no default | LANDED (`b60ad9cda`): refuse arm, pinned in `CellTest.php:201-218` | DONE; residual tidy only |
| CB-20 | no default | LANDED (`9c95153a3`, owner ruling 7a): accessors canonical, props `@deprecated` | DONE; consumer adoption S |
| CB-29 | no default | LANDED (`d62b16ef7`): `testEraseRunWithActivePenDocumentsWireShape` | DONE |
| SO-21 | no default | untouched; Position.php still `(int) floor(...)`, no negative-odd pin | BUILD, switch to truncation (upstream parity) and pin |
| SF-12 | no default | untouched (`FocusTrap.php` does not exist) | BUILD `FocusTrap` |
| CP-14 | ops check | LANDED (`cb11ea4c6`). Dirty files are a LIVE prose pass, not half-edited code | DONE; leave the foreign WIP alone |
| CP-22 | MUST-ACT | lane leg landed (`a15a657ca` divergence pin). SSOT, bridge and deprecation not built | BUILD, soft-delegate plan (section 10) |
| FUZZY-24 | "rationale ships (live)" | FALSE. CALIBER_LEARNINGS.md:79-81 still carries the stale "stays until treelist migrates" blocker | BUILD, delete both banned aliases |
| W-09 | privatize default | LANDED (`fb47083df`): Table `with*` plus `set*` `@deprecated`, ctors `@deprecated`. Privatize NOT done | BUILD the privatize leg |
| W-35 | docs default | LANDED docs leg (`30fdb1e66`). Behaviour unification not built | BUILD unification |
| W-42 | ALT-arm | LANDED (`13244cb9c`): `focus()`/`blur()`, `$focused = true` default | BUILD the flip to family parity (default false) |
| W-29 | MUST-ACT | disposition docs LANDED (`fe245e2ee`), MATCHUPS.md:44. Nothing ported | BUILD Button and Rule/Ruler |
| ST-13 / G21 | footgun box live | README footgun LANDED (README.md:394). Auto-enable not built | BUILD implicit enable (section 12) |
| FBX-06 | rename leg | sizing collapse landed (`withSize` canonical). `set*` rename NOT done; `withContent` name collision | BUILD |
| FBX-21 | implement vs rename | docblock-truth landed only. `Viewport` still not a `Core\Model` | BUILD, implement `Model` |
| FBX-43 | frame shape | untouched; plain path returns the ragged `inner->view()` | BUILD, unify |
| SG-S26 | fund? | untouched; `CardSize.php` does not exist | BUILD (additive VO) |
| dp-21 | MUST-ACT | LANDED (`fda98e243`) | DONE |
| dp-22 | MUST-ACT | LANDED (`2f25786c6`) | DONE |
| dp-26 | MUST-ACT | LANDED (`9c8e89e30`, refuse arm, symmetric across modes) | DONE |
| OG-1 / datepicker naming | open | datepicker DONE (`1db669261`, dp-02): methods declared camelCase, Pascal call sites still resolve. sugar-table untouched (24 Pascal declarations in `Table.php`) | BUILD the sugar-table rename |

Test state, run 2026-11-13:
- cellbuffer 492T green, focus 121T green, overlay 444T green, charts 1375T/3S green.
- colorprofile 799T/2S green, widgets 711T green, fuzzy 1006T green, treelist 306T green.
- datepicker 519T green, style 1152T green, sugar-table 661T green.
- **sugar-flexbox: 1 red of 516.** `TableRendererTest::testColorOnlyCursorChangeIsVisibleInTheDelta` (`tests/TableRendererTest.php:364`). An unchanged frame emits a stray `ESC[1;1H`. This is the known "home-CUP foreign-red pin vs 216b32c12" (packet "candy-code flexbox pin"). It is real and live, not historical. Own it as FBX-R1 (section 13).

## 1. sugar-charts

### CH-57: two public `Bar` classes (`BarChart\Bar` vs `OHLC\Bar`)
(a) Untouched. `src/OHLC/Bar.php` still declares `final class Bar`. `Candle` appears only in docblocks. No in-monorepo consumer of `OHLC\Bar` outside sugar-charts. `sugar-dashboard` has its own `OHLC`/`OHLCPoint` and is unrelated.
(b) Name ambiguity persists. All prereqs have landed: CH-10, CH-49 and CH-48.
(c) Decision: option (a). Rename to `Candle` and keep `OHLC\Bar` as a `class_alias` for one minor.
(d) Plan:
- Rename the file and class `src/OHLC/Bar.php` to `src/OHLC/Candle.php`. Keep the ctor and the `Finite::assert` guards byte-identical.
- Add the alias via the house alias seat (see `AliasParityTest`/`AliasCompletenessTest` for the pattern). Use `class_alias(Candle::class, Bar::class)` loaded from an autoload-safe place. The one-type-per-file gate `tools/check-one-type-per-file.php` forbids a second declaration, so put the alias in `src/OHLC/Bar.php` as a file that declares no class, only the alias, if the checker permits it. Otherwise register it from `src/Support/aliases.php` via composer `autoload.files`. Verify with `php tools/check-one-type-per-file.php`.
- Update `OHLCChart.php` signatures and docblock examples (`:36-37`), `examples/ohlc.php`, README and body.html rows, `tests/OHLC/BarTest.php` (rename to `CandleTest`, plus one alias-resolves-to-canonical-FQN test), and the `OHLCChartTest` fixtures.
- OHLC goldens only change if they name the class. Check `GoldenRenderTest`.
- Docs regeneration: `docs/_data/sugar-charts.body.html` mirror, then `php tools/gen-docs.php`. Never hand-edit `docs/lib`.
(e) Cross-lib callers: none. Risk: low. Effort: S/M.
Conflicts: `OHLCChart.php` with CH-58. README, body.html and goldens with CH-32.

### CH-58: `withLegendStyle` sets the indicator rune
(a) Untouched. Five seats declare `withLegendStyle(?string)`: `Chart.php:256`, `BarChart.php:351`, `Scatter.php:165`, `LineChart.php:504` and `OHLCChart.php:216`. Short aliases `legendStyle(?string)` sit at `Chart.php:397`, `BarChart.php:414` and `Scatter.php:219`. The `ChartExtras` mirror reads `chartExtrasGetLegendIndicatorChar`. No external consumers (grep clean across the monorepo).
(c) Decision: option (a). Add `withLegendIndicator(?string)` as the canonical, keep `withLegendStyle` as an `@deprecated` forwarder (REMOVED-IN 2.0.0). Rename the short alias to `legendIndicator()` and make it dual-form (zero args reads, argument sets), following the CH-43 precedent (`Chart::animationProgress`, `GetAliasWindowTest` with 22 tests). Keep `legendStyle()` as a deprecated delegate. The riders (034 body-glyph setters) stay out of scope.
(d) Plan:
- Edit the five classes above plus the `ChartExtras` trait.
- Update `ChartTest`, `BarChartTest`, `ScatterTest`, `OHLCChartTest`, `ChartNullClearTest` and `RuneDoorGuardTest`.
- Extend the `AliasParityTest`/`AliasCompletenessTest` roster (CH-48 reflection auto-covers the new pair; the expected-alias table at `AliasCompletenessTest.php:122-152` needs a row).
- Add `GetAliasWindowTest` rows with `@deprecated` marker pins for the old pair.
- README setter rows and the body.html mirror.
(e) Risk: low-medium, because 5 classes carry duplicated code. Effort: M.
Conflicts: Chart.php, BarChart.php, Scatter.php and LineChart.php with CH-32; OHLCChart.php with CH-57.

### CH-32: overdraw and collision policy
(a) Untouched. `Graph.php:149-175` still last-write-wins for x labels. The y-label gutter and `Scatter` shared-cell behaviours are unchanged. Prereqs CH-03, CH-09 and CH-22 landed.
(c) BUILD as three legs, as the annotation suggests:
- 32a: duplicate or overlapping x-label columns. Skip the later label deterministically with a lang-keyed disclosure, or merge.
- 32b: y-label gutter clamp so labels never stamp the axis at col 0.
- 32c: `Scatter` shared-cell density policy. Prefer a documented merge glyph, chosen once.
(d) Tests: a policy-table test asserting each surface on a fixture. Re-bake the affected goldens and disclose that. README policy table (one table, one law) and the site mirror.
Effort: L (240 min, split 3 ways). Conflicts: Graph.php, Canvas.php, Scatter.php, Chart.php and goldens, so it is the last chart step.

### CH-56: fleet GIF law and .vhs quoting
(a) `AGENTS.md:52` still reads "Don't commit GIFs", while 291 `.vhs/*.gif` files are tracked (the packet says 296). `sugar-charts/.vhs` carries 8.
(c) BUILD the amendment. This is the same root-file ruling as fleet item #38 and is owned by whoever executes it. Proposed wording: "site-referenced GIFs are committed artifacts; new demos stay render-on-CI". Also amend "quote ALL values" to "quote string values; numeric sizing args fleet-tolerated". No tape edits in sugar-charts.
Effort: S. Lock: root `AGENTS.md`. It conflicts with every conventions-sweep agent, so run it alone. It also frees spring SPR-36, mouse SM-S19, toast CONV-07 and the rest of #38.

## 2. sugar-cellbuffer: CB-15, CB-20, CB-29 are DONE

- CB-15: `Cell` ctor refuses width < 0 and refuses width > 2 unless `Width::of($rune) === $width` (`src/Cell.php:60-77`). Pinned at `CellTest.php:201-218`. This is the refuse arm. It is consistent with the owner's fail-fast preference. Nothing to build.
- CB-20: accessors are canonical, the readonly props carry `@deprecated`, byte-compatible. Nothing to build in the lib.
- CB-29: `encodeEraseRun` has the precondition docblock (`DiffEncoder.php:201-212`) and the pin `testEraseRunWithActivePenDocumentsWireShape` (`DiffEncoderTest.php:564`). The "no implicit reset" arm is correct. Nothing to build.

Follow-up for CB-20 (S, optional): the monorepo still reads the deprecated props. `sugar-charts/src/Canvas/Canvas.php:423-458` (six reads), `sugar-toast/src/Toast.php:1643,1664` and `sugar-dashboard/src/Foundation/Buffer.php:232-233` (the last may be a different Cell type, verify). Migrating them to `->rune()`, `->style()`, `->link()` and `->width()` removes IDE deprecation noise. Behaviour is unchanged. It conflicts with CH-32 on `Canvas.php`, so schedule it after CH-32.

## 3. sugar-overlay: SO-21
(a) Untouched. `Position.php:44-70` computes both centre arms as `(int) \floor(($bg - $fg) / 2)`. Floor gives -1 where upstream Go truncates to 0 for negative odd differences. No negative-odd pin exists. Consumers: `candy-code/src/Renderer.php:8871` and `:8986` call `Position::CENTER->xOffset(...)`. `candy-code/src/Tui/Renderer.php:1218-1505` uses the `OverlayPosition` enum.
(b) The packet's "document dated divergence, keep floor" is the document arm. The owner rule prefers the fix arm.
(c) Decision: fix. Use `intdiv($bg - $fg, 2)`, which truncates toward zero and matches upstream bubbletea-overlay. Floor is only wrong when the overlay is wider or taller than the backdrop. In the monorepo that is a rare edge, which `candy-code` Renderer.php:8871 and :8986 can hit on narrow terminals.
(d) Plan:
- Edit `Position.php` in both arms (this also drops the redundant cast).
- Add `PositionTest` pins `xOffset(11, 10)` and `yOffset` for the negative-odd case: -1 becomes 0. Also add even-negative and positive-odd rows.
- Docblock note: unclamped baseX is the SO-11 contract.
- Check the candy-code `RendererTest` goldens for narrow-width overlays (run `candy-code` targeted tests; a 1-cell shift is possible).
- Update the README divergence line that SO-34 owns (`Mirrors rmhubbert/bubbletea-overlay`).
Risk: low. Effort: S.

## 4. sugar-focus: SF-12 `FocusTrap`
(a) Untouched. `src/` holds only `FocusRing.php`. The README "Modals and focus traps" section (`:176-209`) still teaches the hand-rolled enable and disable ritual and says a first-class primitive is a "deliberate omission".
(c) BUILD the annotation's shape: `FocusRing::trap(): FocusTrap`, `FocusTrap::guarding(string ...$ids): self`, `::ring(): FocusRing`, `::release(): FocusRing` with no argument. The trap holds its own saved id and its background list. Immutable VO, private ctor. If the owner prefers `release(FocusRing)`, that is a one-line change. Ship no-arg per the packet recommendation.
(d) Plan:
- New `src/FocusTrap.php`: `private __construct(FocusRing $ring, ?string $saved, list<string> $background)`.
- `FocusRing::trap()` captures `current()` and `enabledIds()` BEFORE disabling, then returns the trap.
- `release()` order, encoded once: re-enable background, unregister dialog ids, then restore via the SF-07 law: `isEnabled($saved) ? focus($saved) : focusFirst()`. The SF-10 `isParked()` covers a parked-at-open ring.
- New `tests/FocusTrapTest.php`, at least 8 tests. Cover: lifecycle parity versus the hand-assembled README sequence, `release()` on four saved-state arms (normal, parked-at-open, empty-ring-open, saved-disabled-midway), and `assertCacheConsistent` on both legs.
- Rewrite the README Modals section to the two-call ritual (at most 6 executable lines) and retire the "deliberate omission" sentence. Add a CALIBER pattern row.
- No monorepo consumers to adopt yet. `candy-code` modal stacks are a possible later adopter (not verified).
Risk: low-medium (new public class). Effort: M. No conflicts outside sugar-focus.

## 5. sugar-colorprofile: CP-14 and CP-22

### CP-14: foreign-WIP check, verdict LIVE, not dead
- The code landed in `cb11ea4c6`: explicit `?LoopInterface`, `start()` guarded, reaping reclaim. CP-13 and CP-42 landed after it in the same file.
- Dirty at audit start: `README.md`, `src/AsyncProbe.php`, `src/NearestColor.php`, plus (as of 13:25) `examples/detect.php` and `examples/standard-colors.php`.
- mtimes were 13:23, two minutes before this check, and the dirty set is growing. This is the active CP-34 "README prose surgery" session. The `AsyncProbe` diff is comment-only (TERM to SIGTERM wording) and the `NearestColor` diff is docblock wording. There is no half-finished logic.
- Owner rule "finish it": there is nothing to finish in code. The prose pass belongs to its session. DO NOT TOUCH, do not revert. The suite is green (799T, 1,679,187A, 2S).
- Schedule: no fix agent. Any new colorprofile step must wait for that session to commit.

### CP-22: parallel `Color` and `ColorProfile` in sugar-core
(a) The lane leg landed (`a15a657ca`): `tests/CoreForkDivergencePinTest.php` plus a README sentence. The README states that sugar-style and candy-top resolve through the Core fork. The forks differ: `NO_COLOR` gives core `Ascii` (repaints the black/white pair) versus chain `Ascii` (colorless, keeps decoration) after CP-05. `FORCE_COLOR=1` gives core TrueColor versus chain Ansi.
(b) Not built: SSOT consolidation, bridge helpers, sugar-core deprecation.
(c) Decision: colorprofile is the SSOT (packet REC). Build it without breaking the dependency graph.
**Constraint found:** `sugar-colorprofile` requires `sugar-core` (Lang and Clamp). `sugar-core` therefore cannot hard-require colorprofile (cycle). The Core enum is used widely: 206 src files import `Util\ColorProfile` (165 in sugar-dashboard), and about 25 call `ColorProfile::detect(`.
(d) Plan, three phases:
1. sugar-colorprofile (S): add `ColorProfile::fromCore(CoreColorProfile)` and `->toCore()`, with a case-by-case map including `NoTTY` to `NoTty`. Tests: round-trip on all 5 cases. README row.
2. sugar-core (M): put a `suggest` entry for `sugarcraft/sugar-colorprofile` in sugar-core's composer.json. In `Util\ColorProfile::detect()`, when `class_exists(\SugarCraft\ColorProfile\Probe::class)`, delegate through `Probe::colorProfile()->toCore()` so there is ONE ladder. Otherwise fall back to the local ladder, documented as a legacy fallback. Mark the enum `@deprecated` in docs for the next minor. Update the sugar-core detect tests for both legs.
3. sugar-colorprofile pin: flip `CoreForkDivergencePinTest` from "documents divergence" to "parity when installed". Update the README divergence table.
Risks:
- Medium-high. The `NO_COLOR` semantic changes for sugar-style, candy-top and every `detect()` consumer.
- Rendered bytes may move in goldens (sugar-style, candy-code, candy-top, scriptui).
- Run the closure of affected libs after phase 2: `php scripts/affected-libs.php`.
- The path-repo injection means sugar-core's CI must tolerate colorprofile being absent. The `class_exists` guard handles that.
Effort: L.
Conflicts: sugar-colorprofile README and `tests/*` collide with the live CP-34 session, so wait for it to commit. sugar-core `Util/ColorProfile.php` is read by many lanes (high lock risk).

## 6. sugar-fuzzy: FUZZY-24
(a) The packet's "rationale record ships (live)" is false. `CALIBER_LEARNINGS.md:79-81` still says `default()` "stays until sugar-treelist's FuzzyMatch migrates", but `sugar-treelist/src/FuzzyMatch.php` already resolves via `canonical()`. The `ScoringProfile::default()` and `FuzzyMatcherFactory::create()` docblocks say the deprecated spelling is kept for `SugarCraft\TreeList\FuzzyMatch`.
Census of remaining callers: only deprecation pins.
- `sugar-fuzzy/tests/ScoringProfileTest.php:124-125`.
- `sugar-fuzzy/tests/FuzzyMatcherFactoryTest.php:113,116,129`.
- `sugar-treelist/tests/AliasResolutionTest.php:60-65`.
(c) Decision: delete both banned factory spellings now. The packet's "defer one minor" is the document arm, and the repo is pre-1.0. The in-tree caller census is zero beyond pins, so the delete is safe.
(d) Plan:
- Delete `ScoringProfile::default()` and `FuzzyMatcherFactory::create()`.
- Rewrite the `FuzzyMatcherFactoryTest` named-argument test to use `named('smith-waterman', scoring: ...)`.
- Remove the `ScoringProfileTest` alias leg.
- Delete the treelist `testDeprecatedDefaultFactoryStillResolvesThroughAlias`. Keep its second test (the source-sweep guard) and update its docblock.
- Rewrite `CALIBER_LEARNINGS.md:79-81` to record the deletion and the date.
- README and body.html mention check: `grep -rn "default()\|FuzzyMatcherFactory::create"`.
Risk: low (external split-repo consumers may break, which is acceptable pre-1.0). Effort: S.
Conflicts: cross-lib. sugar-fuzzy plus `sugar-treelist/tests/AliasResolutionTest.php`, so do both in one commit or sequence them.

## 7. sugar-widgets: W-09, W-35, W-42, W-29

### W-09: vocabulary BC wave
(a) Landed in `fb47083df`. Table has `with*` canonicals and `set*` as `@deprecated` forwards (`Table.php:132-500`). The Progress, Help and Tabs ctors carry `@deprecated` docblocks (`Tabs.php:64`, `Help.php:42`).
(b) The "privatize ctors" leg is not done.
(c) Decision: build it. Make the Progress, Help and Tabs ctors private, with `::new()` as the sole door. The packet says there are no in-repo `new Progress(` call sites, so it is cheap.
(d) Plan: first grep the monorepo for `new Progress(`, `new Help(` and `new Tabs(` including tests and examples (sugar-dashboard and candy-* may use them). Flip the visibility. Update tests that construct directly. README rows. Table `set*` removal is left for 2.0.
Risk: low-medium (external BC, pre-1.0). Effort: S/M.
Conflicts: `Help.php`, `Progress.php` and `Tabs.php` with W-35, W-42 and W-29-adjacent edits.

### W-35: one law for "0" dimensions
(a) Docs leg landed (`30fdb1e66`). Behaviour is still inconsistent: Table height 0 paints zero rows, Tree height 0 paints all, TextEdit is unlimited, and Tabs defaults width 80 versus Table 0=auto.
(c) Decision: build the single law. 0 means auto/unbounded everywhere, and negative still throws.
(d) Plan:
- Make Table height 0 mean auto/unbounded (all rows).
- Align the Tabs default (the width 80 versus 0 contrast).
- Keep Tree and TextEdit as they are.
- Add a pin per surface, update the README table (component x 0-meaning), and run goldens (Table height-0 rows will move; disclose).
Risk: medium (behaviour change in Table). Effort: M.

### W-42: Paginator focus
(a) Landed. `Paginator` has `focus()`/`blur()` and the key cases are gated (`Paginator.php:47,75-115`). The default is `focused = true` (BC).
(c) Decision: finish the family parity by flipping the default to `false`, as the finding prescribes. Alternative: keep true and document. Prefer the flip because Table, Tabs and Tree gate on focus.
(d) Plan: flip the default. Check every in-repo Paginator host for a missing `focus()` call: grep `Paginator::new` across candy-* and sugar-* (candy-sql, candy-files and sugar-dashboard are candidates), and add `focus()` where needed. Update the pins, add a "default is blurred" test, and update the README note.
Risk: medium (hosts silently stop paging). Effort: S/M.

### W-29: Button and Ruler
(a) Dispositions landed (`fe245e2ee`, MATCHUPS.md:44) as "ruled out". Nothing was ported.
**Fact check:** upstream charmbracelet/bubbles has no `Button` or `Ruler` component. The packet's "bubbles Button/Ruler" claim comes from the research file's ecosystem sample (`sugar-widgets-research.md:557`, the tview `AddButton`). These would be SugarCraft originals, not ports. Cite "Antecedent:" lines, not `Mirrors charmbracelet/...`.
(c) Decision: build `Button` and `Rule` in sugar-widgets. `Rule` is the clearer name for a horizontal divider (`Ruler` is a measuring tool); keep `Ruler` as a doc-level alias only if the owner insists on the original name.
(d) Plan:
- `src/Button/Button.php`: immutable, `::new(string $label)`, `withFocused()`, `withDisabled()`, `withStyles()`, `view()`, and a `sugar-mouse` zone for clicks. sugar-mouse is already a dependency of sugar-widgets. Fires an activation Msg on Enter, Space or click. Lang keys for any announcement text, across 16 locales.
- `src/Rule/Rule.php`: horizontal and vertical, optional title, rune and Style, width-aware via `Width::`, `NO_COLOR` safe.
- Tests: golden bytes, the update tuple, mouse zone hits. Add both to `DocumentedApiTest`.
- Update README Components table. Replace the MATCHUPS.md:44 "ruled out" prose for Button and Ruler with "SugarCraft original" rows. Update `docs/_data/sugar-widgets.*` and run gen-docs.
Risk: low (additive). Effort: M/L.
Conflicts: `docs/MATCHUPS.md` is a repo-root shared file, and `sugar-widgets/README.md` collides with the W-09, W-35 and W-42 doc edits.

## 8. sugar-style: ST-13 and G21
(a) The footgun box landed (README.md:394, "Inert on its own", pinned by `DocumentationFidelityTest::testUnderlineStyleIsInertWithoutUnderlineAndTheReadmeSaysSo`, `:314`). Code: `Style.php:2030-2057` emits `4:N` only when `underline` is also set.
(c) Decision: build the auto-enable. The "prior owner ruling" covers the wire form (emit `4:N` only, with no plain-4 companion). Implicit enable does not change the wire form, so it is not in conflict. State this in the commit and in CALIBER.
(d) Plan:
- `underlineStyle(UnderlineStyle $s)` also sets `underline` to true when `$s !== None`, and registers `underline` in `propsAdded`.
- An explicit later `underline(false)` still wins. `None` leaves `underline` alone.
- Check `inherit()` and `merge()` (`Style.php:1173,1256,1336`) for the new `propsAdded` entry.
- Flip the two inert pins: `DocumentationFidelityTest.php:315` and `UnderlineStyleTest.php:63`. Rewrite the README footgun box and the `UnderlineStyle` docblock (`:14-24`). Update the README sentence at `:394`.
- Run the style golden suites and the `StyleSgrCacheTest` row at `:106`.
Risk: medium (a rendered-bytes change for callers who set a style without `underline`; no monorepo callers set `underlineStyle` outside tests). Effort: S/M.
Conflicts: `Style.php` and the sugar-style README. No other cluster A item touches them.

## 9. sugar-flexbox: FBX-06, FBX-21, FBX-43
All three touch `src/Viewport.php`, so run them serially: FBX-06, then FBX-21, then FBX-43.

### FBX-06
(a) Partly landed. `withSize` is the one sizing spelling, `setWidth`/`setHeight` are deprecated forwarders, the docblocks are done. The gated leg is not done: `setContent`, `setYOffset` and `setXOffset` remain `set*` (`Viewport.php:500,559,567`).
(c) BUILD the rename with deprecated forwarders: `withYOffset`, `withXOffset`, `withContent`.
**Collision found:** `withContent` is currently a PRIVATE static BC seat (`Viewport.php:~83`), reached through `__callStatic`. `__call` throws `BadMethodCallException` on instance use (`ViewportDelegatorTest.php:167-178`). A public instance `withContent` cannot coexist with the static one. Resolution: retire the static BC seat, plus the `__callStatic` hook and the instance-misuse guard, since `fromContent()` has been the factory since FBX-22. The instance `withContent(string)` becomes the setter. Static `Viewport::withContent($c, $w, $h)` callers get an engine Error, so update `AccessorDeprecationTest.php:78` and the guard test. Keep `setContent` as a deprecated forwarder.
Add forwarder pins using the `AccessorDeprecationTest` roster pattern, README and CALIBER ruling-3b amendment (`CALIBER_LEARNINGS.md:74`).
The inner `sugar-forms` Viewport still has `set*`. That is a follow-up for sugar-forms (and sugar-widgets alias). Do NOT touch it in this item.
Effort: M. Risk: medium (BC of the static `withContent`).

### FBX-21
(a) Docblock-truth only (`Viewport.php:205-215`). `instanceof Model` is false.
(c) Decision: implement `SugarCraft\Core\Model`. The inner `sugar-forms` Viewport already implements `Model` (including `subscriptions()`), so add `subscriptions(): ?Subscriptions { return $this->inner->subscriptions(); }` and `implements Model`. Return type of `view()` stays `string` (compatible with `string|View`).
Tests: `assertInstanceOf(Model::class, ...)` and a `subscriptions()` forwarding test. Rewrite the docblock and the README line at `:284` ("not a Core\Model").
Risk: low. Effort: S. Depends on FBX-06 (same file).

### FBX-43
(a) Untouched. With no stickies and no scrollbar, `view()` returns `$this->inner->view()`, which is ragged (variable-width rows). The sticky path and the scrollbar-plain path pad every row to `width()`.
(c) Decision: unify. Pad and truncate the plain path to exactly `width()` cells per row, through the same clamp-and-pad machinery the sticky and scrollbar paths use. Disclose the golden move.
(d) Plan: route the plain path through that machinery. Add a "every row is exactly width() cells" pin for the plain, sticky and scrollbar paths. Grep for hosts that composite plain `Viewport::view()` output: `candy-code`, `candy-sql`, `sugar-dashboard` (rendered-byte goldens could move). README line near `:70-85`.
Risk: medium (golden shift for non-sticky users). Effort: M.

## 10. sugar-gallery: SG-S26 `CardSize`
(a) Untouched; no `CardSize.php`. There are no external consumers (grep clean). Prereqs SG-S01 and SG-S03 are in history. Today the size vocabulary is four loose ints: `PosterGrid::new(int $cardWidth, int $posterHeight, int $hSpacing = 2, int $vSpacing = 1)` (`:79`), `withCardSize(...)` (`:123`), `Rail::render(int $railWidth, bool $focused, int $cardWidth, int $posterHeight, int $spacing = 2)` (`:253`) and `perRow(...)` (`:240`), and `PosterCard::render(bool $focused, int $width, int $posterHeight)` (`:411`).
(c) BUILD the additive VO, as the annotation sketches. Packet REC ("no, keep the divergence") is the document arm.
(d) Plan:
- `src/CardSize.php`: `final readonly class CardSize`, `::new(int $width, int $height, int $hSpacing = 2, int $vSpacing = 1)`, clamps at ingress (width at least 4, height at least 1, spacings at least 0, the same single law the ints use today), bare accessors.
- Accept `int|CardSize` additively in `PosterGrid::new` and `withCardSize`, `Rail::render` and `Rail::perRow`, and `PosterCard::render` (current int signatures stay byte-identical).
- Regression pins: the int path is byte-identical to before, and the VO path is golden-equal to the int path.
- README paragraph ("one size vocabulary"). Update `docs/_data/sugar-gallery.body.html` and `docs/_data/sugar-gallery.json`, then `php tools/gen-docs.php`. Do this BEFORE the SG-S28 copydeck per the annotation.
Risk: low. Effort: M. Conflicts: `PosterGrid.php`, `Rail.php`, `PosterCard.php`, README and body.html.

## 11. sugar-datepicker and the naming ruling (OG-1, shared with sugar-table)
- dp-21 DONE (`fda98e243`: dated status-correction block at `docs/research/libraries/sugar-datepicker-research.md:47`).
- dp-22 DONE (`2f25786c6`: README "Accessibility and terminal fallbacks").
- dp-26 DONE (`9c8e89e30`: blank-cell Enter refuses in both modes).
- Naming: the datepicker rename LANDED (`1db669261`, dp-02). The 27 methods are declared camelCase and the PascalCase spellings still resolve because PHP method names are case-insensitive, so no real alias twin is possible. `tests/ApiAliasTest.php` pins the zero-Pascal-declaration law. The README has the deprecation table. `CALIBER_LEARNINGS.md:73-95` has the status stamp. That is the rename-with-deprecated-alias arm.
- Residual (S): the datepicker/dashboard `DatePicker` adopt-or-diverge ruling (dp-05, finding 005 external half). The in-target pointer README landed (`5abc4c5f8`). The reciprocal `sugar-dashboard/README.md` pointer is a foreign-lib edit; `sugar-dashboard/README.md` is currently dirty with another session's work, so do not touch it.

### OG-1 for sugar-table (ST-02): BUILD the rename, same pattern
(a) Untouched. `src/Table.php` declares 24 PascalCase public methods (`SelectNext`, `SelectPrevious`, `SelectPage`, `NextPage`, `PreviousPage`, `SortBy`, `ClearSort`, `Filter`, `ClearFilter`, `ClearAllFilters`, `ClearSearch`, `Columns`, `Rows`, `CurrentRow`, `CurrentRowData`, `SelectedRow`, `TotalRows`, `TotalPages`, `SelectedIndex`, `CurrentPage`, `PageSize`, `PageFooter`, `RowsFooter`, `View`). A case-insensitive scan found no existing camelCase twin, so no redeclare conflict.
(c) Decision: use the exact datepicker mechanism. Flip the declarations to camelCase. The Pascal spellings then keep resolving as call sites. Add `tests/ApiAliasTest.php` (zero-Pascal-declaration law, byte parity per spelling), a README deprecation table, and a CALIBER exemption retirement note. Update the docblock at `Table.php:~1252` ("Naming alias").
(d) Cross-lib: the only consumer is `candy-sql` (`sugarcraft/sugar-table`). Call sites keep working. Optionally flip them to camelCase in a follow-up. Many sugar-table steps wait on this: ST-03, ST-19, ST-20, ST-24, ST-27, ST-29, ST-36/37/38.
Risk: low, since the mechanism is proven. Effort: M. It is the hard prerequisite for the table board, so run it first on sugar-table.

## 12. Scheduling: file conflicts and ordering

Independent lanes (can run concurrently):
- L1 sugar-charts, strictly serial: CH-58, then CH-57, then CH-32. CH-56 is a separate root-lock lane. The CB-20 consumer adoption in `Canvas.php` goes after CH-32.
- L2 sugar-overlay SO-21 (check candy-code `RendererTest` afterwards).
- L3 sugar-focus SF-12.
- L4 sugar-fuzzy FUZZY-24 plus `sugar-treelist/tests/AliasResolutionTest.php` (one agent, one commit).
- L5 sugar-widgets, serial: W-09 privatize, then W-35, then W-42 flip, then W-29 Button/Rule. Last, because it adds files and shares README and MATCHUPS.
- L6 sugar-style ST-13/G21.
- L7 sugar-flexbox, serial: FBX-06, FBX-21, FBX-43. Include FBX-R1 (the red test) first or alongside.
- L8 sugar-gallery SG-S26.
- L9 sugar-table OG-1 rename (also owns the datepicker-side tidy).
- L10 sugar-colorprofile and sugar-core CP-22: gated behind the live CP-34 session committing. Phase 2 edits sugar-core, so schedule it alone.

Root-lock singletons (run alone, never concurrent with other conventions work): CH-56 / fleet GIF law (`AGENTS.md`), W-29 MATCHUPS edits, any `docs/_data` + `tools/gen-docs.php` regeneration (charts, gallery and widgets all regenerate; serialize those).

Do-not-touch:
- sugar-colorprofile `README.md`, `AsyncProbe.php`, `NearestColor.php` and `examples/*.php` (live CP-34 session).
- `sugar-dashboard/README.md` and `sugar-dashboard/src/Foundation/Segment.php` (dirty from another session).
- `candy-top/*` dirty files and `candy-git-server/*`.

## 13. Other issues noticed

1. FBX-R1: `sugar-flexbox TableRendererTest::testColorOnlyCursorChangeIsVisibleInTheDelta` is red (516T/1F). The renderer emits a stray home-CUP `ESC[1;1H` on an unchanged frame. The packet calls this historical ("stays UNBENT, no action"); it is live. Fix the renderer (silent on an unchanged frame) or correct the pin, per the owner's fix-it rule. Effort: S/M.
2. Packet GIF count is off: 291 tracked `.vhs` GIFs versus the packet's 296. Recount before the AGENTS.md amendment wording cites a number.
3. `sugar-focus` README promises no `FocusTrap` and `CALIBER_LEARNINGS.md:79-81` (fuzzy) states a satisfied blocker as live. Both are stale doc claims the builds above retire.
4. W-29 premise error: upstream bubbles has no Button or Ruler (see section 7). MATCHUPS.md:44 currently says "Unported bubbles components, each RULED". Correct the wording when it is edited.
5. `sugar-datepicker` `dp-05` reciprocal pointer in `sugar-dashboard/README.md` is still owed (foreign file, dirty).
6. Several queue rows carry stale gate columns (`CH-32`, `CH-56`, `W-*` show 'none', and `dp-21/22` show 'none'). Update `plan_review.queue.tsv` and `reviews/OWNER-GATES.md` once the builds land, so the packet stops re-listing landed rows as MUST-ACT. Landed rows to strike from the next packet: CB-15, CB-20, CB-29, CP-14, dp-21, dp-22, dp-26, ST-13 (footgun leg).

## 14. Effort summary

| item | effort | risk | lane |
|---|---|---|---|
| CH-58 | M | low-med | L1 (1st) |
| CH-57 | S/M | low | L1 (2nd) |
| CH-32 | L | med | L1 (3rd) |
| CH-56 / GIF law | S | low | root singleton |
| CB-20 consumer adoption | S | low | after CH-32 |
| SO-21 | S | low | L2 |
| SF-12 | M | low-med | L3 |
| FUZZY-24 | S | low | L4 |
| W-09 privatize | S/M | low-med | L5 |
| W-35 | M | med | L5 |
| W-42 flip | S/M | med | L5 |
| W-29 Button + Rule | M/L | low | L5 (last) |
| ST-13/G21 | S/M | med | L6 |
| FBX-R1 | S/M | low | L7 |
| FBX-06 | M | med | L7 |
| FBX-21 | S | low | L7 |
| FBX-43 | M | med | L7 |
| SG-S26 | M | low | L8 |
| sugar-table OG-1 | M | low | L9 |
| CP-22 | L | med-high | L10 (blocked on CP-34 session) |
