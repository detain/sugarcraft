# rename-5-C4 NOTES — sugar-veil → sugar-overlay <!-- @rename-keep -->

Lane: C4 (batch-C line 24, ns Veil→Overlay, type=lib, bins {}). Branch `rename/c4` off weld `4d472bc62`, worktree `/home/sites/sugarcraft-c4`. NEVER pushed master; push branch only. No R8/R9.

## Procedure log

- R0 baseline: `sugar-veil` suite at base EXACT **258T/561A/0F/0E/0S/0W** (rename-baseline.md:89; line tagged @rename-keep at tag-prep). refresh-deps --mode=linked 10/10. <!-- @rename-keep -->
- Tag-prep commit: 26 lines / 15 files tagged `@rename-keep historical provenance` (CONTEXT.md:981+1288, rename-baseline.md:89, crush-report impact ×4, crush_code worklog:741+6286 / concurrency:210+237 / RESUME:458, leftover/updates.md:592+595, php74_branch.md:420+499, monorepo-audit ×4, improvements-2026-05 ×3, resume_prompt ×2, crush-dep-surface.txt:80). JUDGED-REWRITE (left untagged, living docs): AUDIT_UPSTREAM_PROMPT.md:165, leftover_updates_later.md:300 heading, plan_crush_media.md ×2, step-36/37-veil-*.md content lines, repo_map_step/update* inventories. <!-- @rename-keep -->
- R1 dry-run: 83 content files, 10 path renames, R1=84 R2=21 R3=207. Log /tmp/opencode/c4-dryrun.txt. <!-- @rename-keep -->
- R2 `scripts/rename-remote.sh sugar-veil sugar-overlay` rc=0: sugarcraft/sugar-overlay exists, description updated (port of rmhubbert/bubbletea-overlay). <!-- @rename-keep -->
- R3 `php tools/rename-lib.php --old=sugar-veil`: DONE, exact counts, dir moved `sugar-veil/` → `sugar-overlay/`. <!-- @rename-keep -->
- R4 manual set: README Formerly + Namespace-change notices; glued pointer sweeps `findings/plan_sugar-veil.md` ×3 (findings_resume_plan.md) + ×1 (findings/plan.md:178) + `sugarcraft_sugar-veil.md` ×2 (own repo_map update file); capital-hyphen `Sugar-veil` ×3 in research doc; `prompt_kit/tools/crush-dep-surface.php` value 'Veil'→'Overlay'. <!-- @rename-keep -->
- R5: root `composer update sugarcraft/sugar-overlay --no-install` — lock diff pure sugarcraft (sugar-veil entry removed; sugar-overlay dev-master 427ae34; sugar-crush ref ambient drift d61ae27→e2be432). gen-docs 78 files, `--check` rc=0 (61 pages + 17 redirect stubs + index). gen-ansi-readme `--check` rc=0. refresh-deps --mode=linked --libs=sugar-overlay,sugar-crush rc=0. <!-- @rename-keep -->

## Deviations / findings

1. **Tool auto-renamed the `docs/repo_map/sugarcraft_sugar-veil.md` pair and `findings/plan_sugar-veil.md`** — path-segment rewrite is NOT blocked by the `_` lookbehind (the blindspot applies to content matches, not path segments as the B12 memo assumed). The B12 "lane-mv plan_<slug>.md" deviation law was unnecessary this lane; only GLUED POINTER TEXT inside files needed the manual sweep above.
2. **`sugar-veil` had zero env reads and zero data paths** (SUGAR_VEIL_*/SUGARVEIL_/VEIL_* grep = 0 tree-wide incl. consumers) → no LegacyEnv shim, honest none. <!-- @rename-keep -->
3. `*.ansi` wordmark art files + `.vhs/*.gif` keep stale veil-era bytes by exclusion policy (CI re-renders GIFs) → followups list. <!-- @rename-keep -->
4. **Pre-existing suite fragility discovered (NOT rename-caused):** `TaskGrantMemoHookOriginTest::testAGateGrantDoesNotAnswerALaterUserHookAsk` fails in ANY dirty worktree whose unstaged/staged diff is large. The scripted provider answers by call index; `EnvironmentBlock` embeds the working-tree diff (~16 KiB cap) one step after a write tool runs; against the test's `contextWindow(): 1000` that trips `StepOverBudget` → the EmergencyPrune/StepSummarizer relief path issues an EXTRA provider call → index skew → the 3rd scripted step is consumed by the summarizer. Green on clean trees (proven: byte-identical system prompt at base vs mine except the same-length cwd; 170-untracked-file and long-subject mutations at base stayed green; 90-file `git add -N` mutation stayed green because diff sections require real content diffs). Gate was therefore run on the COMMITTED tip (this commit), not the dirty pre-commit tree. Consider a follow-up hardening brief: pin `env.gitDiffAfterWrites=false` (or a clean-diff fixture) inside this test.
5. crush `DocFigureProseDriftTest` regex needle `sugar-veil|` → `sugar-overlay|` auto-R3; redundant against the following `sugar-[a-z-]+` alternative but harmless; doc-side strings rewrite together → green. <!-- @rename-keep -->

## Gates

- sugar-overlay suite: **258T/561A/0F/0E/0S/0W** EXACT vs baseline (timeout 600 plain pipe, cwd=sugar-overlay). <!-- @rename-keep -->
- residue `check-rename-residue.php --old=sugar-veil`: 0 findings rc=0 (pre-sweep AND re-run staged). <!-- @rename-keep -->
- sugar-crush sharded (scripts/parallel-tests.sh K=8) on committed tip + tools/tests + path-repos + child-lifetimes + one-type-per-file: recorded in the follow-up NOTES update commit below.
