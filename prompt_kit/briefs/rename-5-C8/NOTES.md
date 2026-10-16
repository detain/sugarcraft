# rename-5-C8 NOTES — sugar-dash → sugar-dashboard <!-- @rename-keep -->

Lane: C8 (batch-C line 27, ns Dash→Dashboard, type=lib, bins {}). Branch `rename/c8` off weld `4d472bc62`, worktree `/home/sites/sugarcraft-c8`. NEVER push master; push branch only. No R8/R9. <!-- @rename-keep -->

## Entry facts (verified)

- tools/renames.json line 27: kind=rename, sugar-dash → sugar-dashboard, lib, ns Dash→Dashboard, bins {} , no extra_literals/protect. Derived: pascal SugarDash→SugarDashboard, compact sugardash, env prefixes SUGAR_DASH_/SUGARDASH_ (zero sites → honest none, no LegacyEnv shim). R6 Lang domain N/A (lib has NO src/Lang.php and no lang/ dir). <!-- @rename-keep -->
- Baseline suite (rename-baseline.md:73): 7483T/14859A/2F/0E/0S rc=1 — the 2F pair is PRE-EXISTING (WeatherModuleTest::testCacheRoundTripThroughAtomicFileLeavesNoTemp + PersistenceTest::testSaveLeavesNoLeftoverTempFiles, AtomicJsonFile temp-residue family, catalog #2). Keep same shape, do NOT fix/chase. <!-- @rename-keep -->
- Consumer manifests requiring sugarcraft/sugar-dash: candy-sql, candy-top, sugar-charts (+ root composer.json). sugar-crush has NO require + zero PHP ns refs (only docblock/test-label prose ×4 files) but is gated K=8 sharded per brief. Prose/comment-only libs: bits/toast/buffer/sprinkles/focus/gallery/stickers(core-tests)/core — auto-rewrite, no suites owed beyond gates. <!-- @rename-keep -->
- Baselines (rename-baseline.md): candy-top 2110T/107777A/0F; sugar-charts 654T/1551A/0F; candy-sql ABSENT from baseline table (DISCLOSED: measure pre-rename at base for comparison). sugar-crush figure 22564T/423652A pinned (C4 sharded pass shape: 22564T/423738A +86A wobble CONSERVATION PASS, 0E/0F/2S). <!-- @rename-keep -->
- sugar-dash has tests/bootstrap.php LoopPin (memory law) + 288 committed goldens + ~153 .vhs tapes (lib-relative paths, R4.10 no-op). <!-- @rename-keep -->

## Substring-judgment map (the Dash hazard)

- Tool boundary rules verified in source: R1 `SugarCraft\Dash(?![A-Za-z0-9_])` (existing `SugarCraft\Dashboard`/`Dashboard*` words safe); R2 `(?<![A-Za-z0-9_])SugarDash(?![a-z0-9])` (SugarDashboard safe, folded class tails OK); R3 slug at boundaries with content lookbehind `(?<![A-Za-z0-9_])` (`_sugar-dash` glued pointers NOT rewritten in content — manual sweep; pathContext relaxes for real path segments); R5 env needs `(?=[A-Z0-9])` after SUGAR_DASH_. <!-- @rename-keep -->
- NO rule matches bare `dash`/`Dash`/`DASH_` — survivors by design (eye-verify in R1 diff): candy-pty/tests/Integration/DashTest.php `DASH_PATH='/usr/bin/dash'` (Almquist shell!), candy-shine `'wavy_dash'` emoji, sugar-crush `cli.argv.hint.prompt_dash` key+lang, `mysql_workbench_dash.md` cite in candy-sql, "em-dash"/generic dash prose, and the tree's many EXISTING generic `Dashboard` words (candy-sql Admin/Dashboard/*, sugar-crush Dashboard pages, codetime DashboardTest...) — new ns `SugarCraft\Dashboard` pre-existence: ZERO tree-wide (clean). sugar-dash/src has no `class Dashboard` → no FQN collision introduced. <!-- @rename-keep -->
- lib description/keywords ALREADY say "Dashboard TUI library"/"dashboard" — post-rename reads naturally; keep upstream names in keywords (bubble-grid etc. live in description; keywords have no upstream slug — leave).

## Real data paths (hazard verdict)

- `WeatherModule::cachePath()` → `$HOME/.cache/sugar-dash/weather.json` (tool rewrites to sugar-dashboard; code comment states stale/missing cache is acceptable → zero data loss, no migrate). <!-- @rename-keep -->
- `Plugin\Discovery::defaultDirectory()` → `$XDG_CONFIG_HOME|~/.config/sugar-dash/plugins` (tool rewrites; lib-scope static resolver — a side-effectful fs-migration inside a component lib has no bin/entry hook point and would violate resolver purity; plan R4.4 scopes LegacyPaths to apps). VERDICT: no LegacyPaths wiring; disclose old-dir drop-ins need manual move → rename-followups.md entry. <!-- @rename-keep -->

## Glued/capital sweeps (R4-manual set, pre-planned)

- Glued `_sugar-dash` content pointers ×13: findings/findings_resume_plan.md ×6, findings/plan.md :154, docs/repo_map/update_sugarcraft_sugar-{charts,stickers} ×3, update_sugarcraft_sugar-dash.md ×3 (self file, path auto-renames, content pointers manual). Rewrite (targets exist under new names post-R3). <!-- @rename-keep -->
- Capital-hyphen `Sugar-dash`: ZERO (grep verified). <!-- @rename-keep -->
- ansi/sugar-dash/*.ansi wordmark bytes carry `SUGAR-DASH` art → content-excluded; ansi.json keys + paths auto (R3 + `<old><digits>` path rule) → regenerate ansi README; art pixels → rename-followups.md (no redraw). <!-- @rename-keep -->

## Tag-prep set (history → @rename-keep)

CONTEXT.md untagged token lines, rename-baseline.md :73+:104, crush_code_{hardening_backlog,worklog,backlog_triage,filemap}, findings/{improvements-2026-05,monorepo-audit-2026-05-27,resume_prompt}, leftover/updates.md, php74_branch.md, steps/J1_COMPOSER_REPOS.md, sibling lane NOTES (rename-1.3/2.5/3-A1/4-B2/4-B6/4-B7/4-B11/4-B12/remediation). JUDGED-REWRITE (living): docs/plans/dash*.md, leftover phase-0*/step-* incl phase-03-sugar-dash dir, leftover_updates_later.md, repo_map*/research/dev/index/MATCHUPS/README/CLAUDE/PROJECT_NAMES/use_libs.txt/vcr_use.md, btop oracles + candy-top campaign docs (pointers to renamed files), all consumer prose. <!-- @rename-keep -->

## Steps

- (pending) tag-prep commit:
- (pending) R1 dry-run:
- (pending) R2 remote rename:
- (pending) R3 tool run:
- (pending) R4 manual:
- (pending) R5 wiring:
- (pending) R6 gates:
- (pending) R7 commit+push:
