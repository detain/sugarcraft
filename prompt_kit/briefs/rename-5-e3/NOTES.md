# rename-5-e3 NOTES — candy-testing → sugar-testing <!-- @rename-keep -->

Lane: e3 (batch-E line 50, ns Testing→Testing UNCHANGED, type=lib, bins {}). Branch `rename/e3` off base `6709f44bd`, worktree `/home/sites/sugarcraft-e3`. NEVER write master; push branch rename/e3 only. No R2 remote, no R8/R9 (brief law — serial/weld phase owns remotes). <!-- @rename-keep -->

## Entry facts (verified)

- tools/renames.json line 50: kind=rename, candy-testing → sugar-testing, lib, ns unchanged, bins {}, no extra_literals/protect, status pending. Derived: pascal CandyTesting→SugarTesting (R2), slug (R3), compact candytesting (R4 — ZERO sites), env CANDY_TESTING_/CANDYTESTING_ (ZERO sites → honest none, no LegacyEnv; lib has no bin). R6 Lang domain NAMESPACE='testing' == lower(ns) — no-op. <!-- @rename-keep -->
- Ns `SugarCraft\Testing` SURVIVES byte-identical by design: R1 skipped (ns_old==ns_new). LoopPin/ProgramSimulator/ScriptedInput/Assertions public API untouched. All `SugarCraft\Testing\...` FQNs in every consumer tests/bootstrap.php stay. <!-- @rename-keep -->
- BLAST RADIUS: 23 external libs require-dev sugarcraft/candy-testing (24 composer.json incl self): async flappy gifview mdview minesweeper pty tetris top vcr vt wish + animation charts cli datepicker forms image markdown spring table toast video widgets. Tool R3 rewrites all require-dev lines. Prose sites: READMEs, CALIBER_LEARNINGS ×7, tests/bootstrap.php comments (candy-top, candy-wish), docblocks (candy-core/src/Program.php ×5, ProgramTest, sugar-image/src/KittyOptions.php), ci.yml comment, codecov.yml, tools/check-child-lifetimes.php + check-path-repos.php + scripts/refresh-deps.php comments. <!-- @rename-keep -->
- Baseline (rename-baseline.md:58 record 267T/5417A/0F/0E/2S — RE-MEASURE at base per lane law). <!-- @rename-keep -->
- Pre-existence census: `sugar-testing`/`SugarTesting`/`SUGAR_TESTING_` ZERO tree-wide outside plan+renames.json target refs — clean. Generic English word `testing` NEVER bare-sed (keyword arrays, Lang keys, test-namespace words — only full-token rules touch it). <!-- @rename-keep -->

## Self-lock (never tag — path rename targets)

candy-testing/** (all), findings/candy-testing.md, findings/plan_candy-testing.md, docs/_data/candy-testing.{json,body.html}, docs/lib/candy-testing.html, media/icons/candy-testing.png. Formerly notice added POST-move to sugar-testing/README.md. No .vhs dir, no ansi/candy-testing, no docs/repo_map/ or research file for this slug (verified).

## Tag-prep set (history → @rename-keep, per-line)

- prompt_kit/briefs/rename-baseline.md:58; docs/plans/crush_code_worklog.md ×6; crush_code_backlog_triage_reconciliations.md:28; crush_code_hardening_backlog.md ×9 untagged; php74_branch.md ×4; findings/resume_prompt.md ×3 (line 16 pre-tagged); prompt_kit/findings/crush-report/impact/{foundations,server-web,sessions-agentview}.md ×1 each. <!-- @rename-keep -->
- JUDGED-REWRITE (living): docs/repo_map_step_*.md + update*/supervisor/role_tester (sibling C/D precedent — sugar-dashboard/sugar-image already live there, incl branch-name prose trade), AGENTS/CLAUDE/README/PROJECT_NAMES/MATCHUPS, .claude/** .cursor/**, all */README.md + CALIBER, index.html/_data/lib html, codecov/ci.yml, code+test docblocks, findings/{plan.md,findings_resume_plan.md,plan_candy-{buffer,vt}.md,plan_sugar-cli.md,sugar-{animation,charts}.md,resume_prompt table? no—tagged above}, plan_crush_media.md (C8/D1 precedent). docs/old/** excluded. composer.lock excluded (weld-domain lock work per WELD-D law).

## Glued/manual sweeps (R4-manual set)

- Glued `plan_candy-testing.md` content pointers ×6 (findings_resume_plan.md ×5, plan.md :186) — invisible to R3 `_`-lookbehind; rewrite post-R3 (target findings/plan_sugar-testing.md exists). <!-- @rename-keep -->
- Cap-Hyphen `Candy-Testing`: ZERO. Aliases `as *Testing*`: ZERO. .vhs post-\n tokens: no .vhs. ansi/sugarcraft/*.ansi wordmark art ×3 carry the slug in CONTENT — C.3-excluded → rename-followups.md. <!-- @rename-keep -->
- Suffixed repo_map/research names: none for this slug (verified).

## Steps

- (pending) tag-prep commit:
- (pending) R1 dry-run:
- (pending) R3 tool run:
- (pending) R4 manual:
- (pending) R5 wiring:
- (pending) R6 gates:
- (pending) R7 commit+push branch:
