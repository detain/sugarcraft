# Step 1.1 — Merge candy-mouse into candy-mouse (evidence brief) <!-- @rename-keep historical provenance -->

Base HEAD: 70712b220. Plan: plan_rename.md Step 1.1 (authoritative). NEVER push; direct commits to master.

## Spec digest (plan wins over orchestrator brief where they differ)
- ns: `SugarCraft\Mouse\Zones` → `SugarCraft\Mouse\Zones` (subdir `candy-mouse/src/Zones/`, Msg/ rides along). <!-- @rename-keep historical provenance -->
- Tests → `candy-mouse/tests/Zones/`, ns `SugarCraft\Mouse\Tests\Zones` (plan step 3 — YES, plan says so). <!-- @rename-keep historical provenance -->
- Autoload: candy-mouse psr-4 `SugarCraft\Mouse\`→src/ + `SugarCraft\Mouse\Tests\`→tests/ ALREADY resolve <!-- @rename-keep historical provenance -->
  Zones/ and Tests/Zones/ — NO autoload entries needed (brief guessed "additional entry"; plan shape needs none).
- renames.json entry kind=merge, status→`renamed` at the end (step 17); residue check `--old=candy-mouse` exit 0 (step 16). <!-- @rename-keep historical provenance -->
- Commit msg (step DoD): `candy-mouse: absorb candy-zone under SugarCraft\Mouse\Zones` <!-- @rename-keep plan-quoted commit message, historical slug -->
- Archive of sugarcraft/candy-mouse remote = Phase 10 — NOT here. No push (Part A rule 6 deferred to operator per task law). <!-- @rename-keep historical provenance -->

## Tool-interaction finding (ordering deviation, disclosed)
Plan lists step 3 (manual ns edits) BEFORE step 4 (tool). Empirically the tool's preflight
namespace-collision scan (runs under `--no-move` because movedState=false) aborts (exit 2) once any tracked
src/tests file literally contains `SugarCraft\Mouse\Zones\` — which step-3 edits create. Resolution: run the
tool FIRST, immediately after the git mv steps (only the moved FILES exist, namespaces still old → preflight
clean). R1 then performs the mechanical half of step 3 repo-wide (incl. moved files). Manual residue of step 3:
`SugarCraft\Mouse\Zones\Tests` → `SugarCraft\Mouse\Tests\Zones` in the 9 moved test files (+ MouseZone alias
prose check — candy-mouse/src/Zone.php already imports `SugarCraft\Mouse\Zone as MouseZone`, safe under R1). <!-- @rename-keep historical provenance -->

## Baselines (prompt_kit/briefs/rename-baseline.md)
- candy-mouse 204T/500A/0F; candy-mouse 139T/416A/0F → merged expectation 343T/916A. <!-- @rename-keep historical provenance -->
- (fill in consumer baselines below)

## Execution log
- [ ] git mv src/tests
- [ ] rename-lib --no-move (dry-run inspected)
- [ ] manual test-ns + prose fixes
- [ ] composers union/dedupe, root lock
- [ ] examples/.vhs/README/CALIBER_LEARNINGS
- [ ] candy-mouse dir rm, vhs.yml, codecov.yml, bootstrap-org-repos.sh <!-- @rename-keep historical provenance -->
- [ ] docs _data/lib/icons/MATCHUPS/PROJECT_NAMES/README/index + gen-docs check
- [ ] findings/research/repo_map git mv
- [ ] suites green vs baseline
- [ ] residue + guards
- [ ] renames.json status=renamed
- [ ] commit + tracker row

## Execution log (final)
- [x] git mv src→candy-mouse/src/Zones (+Msg/), tests→candy-mouse/tests/Zones (intermediate commit 38ca10548) <!-- @rename-keep historical provenance -->
- [x] rename-lib --old=candy-mouse --no-move (dry-run inspected; ran: 145 files, R1=118 R2=52 R3=360 R4=18 R5=4 R6=1) <!-- @rename-keep historical provenance -->
- [x] manual fixes: 9 moved test files ns `...Zones\Tests`→`SugarCraft\Mouse\Tests\Zones` (plan-mandated); candy-mouse/src/Lang.php R6 clobber NAMESPACE 'zones' restored to 'mouse' (candy-mouse keeps its own lang domain; moved Zones code ships no Lang.php); duplicated-comment prose in sugar-crush FeatWiringReachabilityTest (2 sites) reworded <!-- @rename-keep historical provenance -->
- [x] composers: candy-mouse require-dev += sugarcraft/candy-sprinkles, description = plan verbatim; root require+repositories duplicates deleted; sugar-bits/sugar-gallery deps now candy-mouse; composer update 'sugarcraft/*' --no-install → lock diff sugarcraft-only <!-- @rename-keep historical provenance -->
- [x] examples/zones-*.php + .vhs/zones-*.{tape,gif} git-mv'd, tape Output/Type/Render lines rewritten; README fold `## Zones (bubblezone)`; CALIBER_LEARNINGS `## From candy-zone` section (+2 historical `candyzone:` wire tokens restored, tagged) <!-- @rename-keep section headings quote the historical slug -->
- [x] old lib dir git rm'd; docs/_data, docs/lib page, icons removed; vhs.yml rewritten-to-candy-mouse KEPT (2x); codecov.yml dup flag+component blocks removed; bootstrap-org-repos.sh clean <!-- @rename-keep historical provenance -->
- [x] docs fold: candy-mouse.body.html Zones section + demos grid fixed (pre-existing card referenced a never-existing buttons.gif); candy-mouse.json tagline/detailMeta; MATCHUPS rows merged; root README row; PROJECT_NAMES had no row to delete; findings/research/repo_map git mv + underscore refs hand-fixed; gen-docs x2 + --check OK (61 pages) <!-- @rename-keep historical provenance -->
- [x] suites: candy-mouse 343T/916A (exact 204+139/500+416) | sugar-bits 570/1246 | sugar-gallery 264/1162 | sugar-crumbs 144/260 | sugar-veil 258/561 | candy-mines 180/820/1F W4 = exact baseline row | sugar-crush: see shard note below <!-- @rename-keep -->
- [x] residue: check-rename-residue --old=candy-mouse exit 0; (candy-zone entry now status=renamed) <!-- @rename-keep flag argument names the historical slug -->
- [x] renames.json entry status pending→renamed
- [ ] guards battery + K=4 sharded crush run (in progress)
- [ ] commit + tracker row

## Late corrections found during verification (post initial suite pass)
- **Regression caught:** sugar-bits/composer.json:41 + sugar-gallery/composer.json:33 STILL required the old package (the `--no-move` run had NOT rewritten these per-lib require lines despite earlier note). Fixed to `sugarcraft/candy-mouse` (verified valid JSON, no dup). Suites re-run after fix. <!-- @rename-keep historical provenance -->
- **History-file rewrites reverted + @rename-keep tagged:** the `--no-move` sweep clobbered historical prose in prompt_kit/CONTEXT.md, plan_crush_media.md, php74_branch.md into self-contradicting `candy-mouse/candy-mouse` / `candy-mouse REQUIRES candy-mouse`. Reverted all three to HEAD, then tagged the genuine historical old-slug lines with @rename-keep (12/9/2 lines) so residue stays 0 while history reads true.
- **Comment-prose accuracy fixes (crush):** FeatWiringReachabilityTest (2 'candy-mouse/candy-mouse' sites), SettingsTabStrip self-contrast sentence rewritten to name Zones Manager vs Mark/Scan layer. FormTabs/PaneDragController docblock token swaps kept (now accurate: candy-mouse). All 4 crush files comment/docblock-only — zero executable-line changes. <!-- @rename-keep historical provenance -->
- **.claude/skills + ISSUE_TEMPLATE + CLAUDE.md + use_libs.txt:** old-slug → candy-mouse rewrites are accurate (paths updated to tests/Zones/ where referenced). Single one-to-one swaps, no dupes. <!-- @rename-keep historical provenance -->
- **Guards so far (all rc=0):** path-repos --no-lib-path-repos / (full) / --unused; one-type-per-file (61 libs, 0 problems); child-lifetimes (8 accounted, 0 problems); gen-docs --check (61 pages); gen-ansi-readme --check (3595 pieces); tools/tests phpunit 418T/1397A (count rose vs 0.1-era 390 because the renamed entry is now enumerated by per-entry providers).

## Wire-token collateral (R4) — found & cured late
The sweep's R4 (compact) rule rewrote the LEGACY APC wire string `candyzone:S/E:` into hyphenated `candy-mouse:S:` in files whose counterpart fixtures kept the underscore-prefaced form (R4-invisible), creating real breaks: candy-core/tests/Util/ParserTest.php (input :115 vs expected :118 mismatch) and sugar-spark/src/Inspector.php str_starts_with literal vs all spark fixtures. Resolutions: ParserTest lines restored to the historical literal + tagged; sugar-spark FULLY reverted to HEAD then @rename-keep-tagged (Inspector recognises a legacy on-wire token that no current emitter produces — reverting avoids a silent protocol change outside this step's scope). Docs describing the old protocol (docs/repo_map/sugarcraft_candy-mouse-zones.md, update_…, docs/research/libraries/candy-mouse-zones-research.md, docs/plans/leftover/updates.md historical step rows) restored to the old tokens + tagged. Behaviour-neutral fixture rewrites KEPT (candy-mouse/tests/MarkTest.php validIdProvider 'zone prefix', sugar-bits TabsTest not-contains string). <!-- @rename-keep historical record -->
- **Suite re-runs after this round (all exact/ green):** candy-ansi-inspect 278T/1412A rc0 (=baseline row); sugar-bits 570/1246 rc0; sugar-gallery 264/1162 rc0; candy-core 1401T/28953A/25S rc0 — NOTE baseline file records 1383/28917/25S; PROVEN pre-existing drift: `--list-tests` at HEAD vs working tree byte-identical (1404 entries both), so this step adds zero candy-core tests; HEAD-era figure in rename-baseline.md simply predates other landed plan steps. <!-- @rename-keep historical provenance -->
- residue --old=<slug> rc=0 again after tagging; php -l clean on all touched php.

## Trio reds RESOLVED - root cause: live git-status in <turn-context> (NOT the rename)
EngineBackend prepends a <turn-context> row embedding LIVE `git status` of the project
repo. Pre-commit working tree carries ~197 modified paths (~21KB context row) which
inflates token estimates past the tiny contextWindow(1000) fixtures in
StepPressureCheckTest / EngineBackendTest::testMaxStepsGuardsAgainstRunawayToolLoops /
TaskGrantMemoHookOriginTest -> compaction/wrap-up paths fire -> 3 deterministic reds.
PROVEN: same 3 files green at base worktree (clean HEAD status, and green with my file
content applied there); fake HOME / .mcp.json / project memory all ruled out by probe;
probe dump showed the 21091-byte turn-context UserMessage listing the dirty status.
Campaign floors ran on committed-clean trees - consistent. FIX: commit the step, then
re-run trio + K=4 baseline on the clean tree. This is the same family as the
UiOnlyWireFilterTest "wire embeds a LIVE git-state snapshot" lesson.
