# Phase 4 Batch B — entry 1: sugar-stash → candy-git (2026-10-15) <!-- @rename-keep -->

Procedure R chain from base `6bac4090d` (== origin at start; tree clean except foreign `oc.7z`, left in place — preflight passed).

## R1 preflight + dry-run
- `rename-lib.php --old=sugar-stash --dry-run`: 121 content files, 12 paths, R1=149 R2=42 R3=350 R6=1. Clean sanity; no PROTECT rows (no `sugar-stash-*` siblings), no tool defect. <!-- @rename-keep -->
- Tag-prep commit `315a40c4a` (A2 pattern): baseline row in `rename-baseline.md` + 9 historical CONTEXT.md lines tagged `@rename-keep`. Re-dry-run delta exact: 119 files, R3 350→337 (−13 = 12+1), R1/R2/R6 unchanged.

## R2 remote
- `scripts/rename-remote.sh sugar-stash candy-git`: GitHub rename verified (old URL 301s, API resolves sugarcraft/candy-git, split HEAD 00a369998 at rename time). Description kept truthful ("Terminal Git client — port of jesseduffield/lazygit..."). <!-- @rename-keep -->

## R3 run
- 119 files, 13 paths (bin nested rename included). R1=149 R2=42 R3=337 R6=1, exit 0.
- Lint: all 53 lib PHP + bin + 5 sibling PHP files `php -l` clean. JSON valid (root + lib composer.json).
- Untracked lib files (vendor/, composer.lock, .phpunit.cache) rode the physical dir move cleanly — `candy-git/vendor/bin/phpunit` present; no re-install needed.

## R4 followups (manual, all landed pre-R7)
- README: `> Formerly published as sugarcraft/sugar-stash` + ns-change line, both `@rename-keep` (A3 format).
- `src/Lang.php` docblock `'stash'` → `'git'` (R6 flipped the const mechanically, prose stale — A3 lesson).
- `README.md:83` + `CALIBER_LEARNINGS.md:61` domain literals `'stash'` → `'git'`.
- **A1 underscore blindspot recurred**: `src/InteractiveRebase.php:155` cited `findings/plan_sugar-stash.md` — R3 lookbehind blocks `_sugar-stash`. Swept whole tree for `_sugar-stash`: 4 files fixed (findings_resume_plan.md ×4 sites, plan.md, resume_prompt.md, docs/repo_map/update_sugarcraft_candy-git.md source-cites ×4) via `sed s/_sugar-stash/_candy-git/g`. ZERO remaining.
- PROJECT_NAMES.md rationale row rewritten (CandyFlappy precedent wording — old "stash = the git verb. clever." retired with sugar- family placement).
- Env vars: ZERO reads (grep getenv/SUGAR_STASH_/SUGARSTASH_/STASH_ tree-wide = none) → honest record, no LegacyEnv shim.
- User-data paths: ZERO (no .config/.local/share/.cache/HOME/XDG usage in src/bin) → no LegacyPaths shim.
- MATCHUPS + index.html section move (R4.8 plan example): **MOOT** — sugar-stash row (MATCHUPS:94) and tile (index.html:813) already sit under Apps/`id="apps"`; the lib was always typed app. No moves made. <!-- @rename-keep -->
- `docs/js/search-index.json` IS auto-rewritten by the tool (R2:1 R3:2) — not hand-maintained as feared; no generator exists, client-side search.js just fetches it.
- Bare `SugarCraft\Git` collision: none tree-wide outside the lib itself (candy-serve's `SugarCraft\Serve\Git\` sub-ns is a distinct PSR-4 prefix — verified). Forward note: `docs/repo_map/update_sugarcraft_candy-wish.md` contains 3 aspirational mentions of a FUTURE `candy-git` leaf library (git middleware concept) — pre-existing prose, now name-clashing with this app; left as historical record.
- `sugar-crush` src docblocks used loose path-style `sugar-stash\DiffViewer` → now `candy-git\DiffViewer` (kept — same loose style as before, prose reference not a FQN). <!-- @rename-keep -->

## R5 wiring
- Root composer.json require+repositories renamed by tool; `composer update sugarcraft/candy-git --no-install` → lock churn = content-hash + ONE relocated package block (62/62 lines = delete-old-position + insert-sorted-position; sugarcraft-only, A3 shape).
- `refresh-deps --mode=linked --libs=candy-git`: linked=1 published=0, guard rc0, OK.
- Consumers requiring sugarcraft/candy-git: root only (zero libs depend on it).
- `gen-docs` + `--check`: 66 files (40 libs/21 apps unchanged tallies), green. Redirect stub for old page minted AFTER renames.json flip (A3 pattern; re-run inside R7).
- `gen-ansi-readme --check`: agree across 3955 pieces. Wordmark art recorded in rename-followups.md.
- vhs.yml: both hand-lists (`all=()` ~:150 + matrix `lib:` :225) auto-rewritten by tool R3 (verified in diff). stage.tape now drives `./bin/candy-git`.

## R6 verification
- Residue `check-rename-residue.php --old=sugar-stash`: **0 findings** (run post `git add -A` per A1 ls-files law). <!-- @rename-keep -->
- candy-git suite: **326T/4638A OK exit0 — EXACT baseline** (delta 0/0; title-bar brand rewire is consistent, RendererTest goldens self-derive).
- sugar-diff (only consumer-side test file touched, 2 string literals): **70T/225A OK EXACT**.
- sugar-crush (3 files touched, 1-line docblock/string literals each): K=8 sharded, tests **22,564 EXACT** / skipped 2 EXACT / assertions 423,737 (+85A wobble within known bounds); 1F+1E both PRE-EXISTING, proven: (a) `ChildStderrCaptureTest` deterministic red on `Media/ReelDependencyTest.php:49 shell_exec` — all scanner/scanned inputs byte-identical to base (known "Reel injection-blind" CI defect family); (b) `StepPressureCheckTest` co-shard flake — GREEN isolated (3.3s, known co-shard family per GrepInstructionWiring precedent). `VhsTapeContractTest` (the one touched crush test) GREEN isolated 125T.
- tools/tests: **426T/1484A EXACT floor** (no docs-dataset wobble this entry).
- `check-path-repos --no-lib-path-repos`: rc0, 61 libs scanned.
- Diff eye-review: 127 files +582/−579; opened >8 files across src/README/composer/vhs/MATCHUPS/index/bin/tapes/docblocks. All coherent.

## Deviations / notes
- Harness self-inflicted (recorded so later lanes skip it): `cd <lib> && ( watchdog ) &` backgrounds the whole `&&` chain (cd lost); campaign law form uses `;` separator. And `pkill -f parallel-tests` from a bash-tool call self-matches the wrapper's own command line → orphan run + no output; script-internal `--timeout` is the reliable guard for sharded crush runs.
- Pre-existing crush shard-3 reds mean PARALLEL-RC=1 on every future K=8 run at this era until the Reel family is adopted — conservation figures (tests/skipped) remain the discriminating gate.
