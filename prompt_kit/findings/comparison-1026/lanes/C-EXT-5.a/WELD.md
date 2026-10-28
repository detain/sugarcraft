# WELD — C-EXT-5.a (PaletteCatalog core: action + prefill rows)

Welded 2026-10-28 by welder (sole master writer). Repo /home/sites/sugarcraft, master. NEVER pushed.

## Pick

- Lane: branch `cc/C-EXT-5.a` tip `d37514fe828a812343053713a6ed1bcf761cc789` on base `e4a705244` (1 commit; 4 NEW files, +361: `candy-code/src/Palette/{PaletteCatalog,PaletteEntry,PaletteEntryKind}.php`, `candy-code/tests/Palette/PaletteCatalogTest.php`).
- Cherry-picked onto master (at `57f7d7ae1`) with ZERO conflicts → **`179ac2e3f7e7ccb11e25d12b103503ef78c64297`** — `C-EXT-5.a: PaletteCatalog core (action + prefill rows)`.
- Absorption proof: `git patch-id --stable` IDENTICAL both sides — `668cf9e7bca6d9ef6230b9a786d6ca81710ca117` for `179ac2e3f` and `d37514fe8`. `git cherry master cc/C-EXT-5.a` → `-` (see ceremony below).
- Author law on the pick: `%ae`/`%ce` separate greps both `detain@interserver.net`; zero trailers.

## Review

`REVIEW-1.md` verdict: **CLEAN** (0C/0M). Phantom-first PASS (tip is a commit, 1 ahead of master, porcelain clean, author/trailers verified). Scope exactly the 4 declared files; no weld-only files committed; design conformance and pins verified. Catalog is pure/added-only — dispatch wiring belongs to C-EXT-5.b.

## Merged-tree gates (plain pipe, `env -u LINES -u COLUMNS`, cwd `candy-code`)

- `vendor/bin/phpunit -c phpunit.xml --filter PaletteCatalogTest` → `OK, but there were issues! Tests: 8, Assertions: 401, PHPUnit Deprecations: 2.` — EXACT vs lane/brief expectation 8T/401A. (Deprecations: 2 = pre-existing config deprecations present on every filter run at this era.)
- `vendor/bin/phpunit -c phpunit.xml --filter 'GlobDialect|DuplicatedTestHelperDrift|TreeWideGuardRoster'` → **45T/4090A**, 0F — vs lane-reported 44T/4087A: **disclosed wobble +1T/+3A**. Dissection: GlobDialect 14T/369A and DuplicatedTestHelperDrift 14T/194A are unchanged by the pick (probed with and without `tests/Palette/PaletteCatalogTest.php`); TreeWideGuardRoster 17T gains +2A from the new file (3525→3527) and no +T. The +1T/+3A remainder is not attributable to the pick's fileset on the merged tree — all three guards green, zero failures; recorded per program law (quote as-run figures; not red).
- ReadmeSuiteFigureDrift staleness pair = standing (live ~27k vs pinned 25,537), not this pick.

## Riders

- **Durations row LANDED separately**: commit `8c1b44b08` — `candy-code/tests/Palette/PaletteCatalogTest.php\t0.028\t8` (junit per-testcase time sum; 8 testcases; third column = test count per P-TABS.f `0.031/14` precedent). Inserted at tsv line 900, desc-invariant (4 pre-existing tail violations untouched, zero added — verified before/after count 4==4). tsv 1526→1527, path set-equality +1 exactly.
- **No regen riders**: zero `src/Protocol/`/schema/generated.ts/web surface in the pick; PHP doc gens untouched (no new command/tool/settings surface — the catalog is UNWIRED).

## Carries

- **Intentionally unwired**: `PaletteCatalog::rows()` has no production caller yet — wiring lands with C-EXT-5.b (later band, per plan §4 Files-claim "modify: none"). Do not treat as dormant-code defect.
- **+8T closeout figure carry**: suite-figure.json/README re-pin at program closeout must account for PaletteCatalogTest (8T/401A) — plus the durations row already landed.

## Ceremony

- `weld: C-EXT-5.a` commit = §4 ✅ mark (full pick hash) + `lanes/C-EXT-5.a/{BRIEF,REPORT,REVIEW-1,WELD}.md` + run-state.json (inflight key removed, welded +1, tip → pick hash `179ac2e3f…`).
- `git cherry master cc/C-EXT-5.a` proof recorded here, then worktree `/home/sites/cc-cmp-C-EXT-5.a` + branch `cc/C-EXT-5.a` pruned.
