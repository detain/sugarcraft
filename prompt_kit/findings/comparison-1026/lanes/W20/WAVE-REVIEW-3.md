# WAVE 20 — merged-tree review, round 3
Reviewer: cc-cmp wave-reviewer, 2026-10-29. Reviewed tip: 86f80fef8 (cure 5ea654501 + post-cure weld 72a6ad46b + batch-15 re-sync verified inside). Read-only except this file (UNTRACKED per law).

## VERDICT: CLEAN (0C/0M)

## 1. r1+r2 MAJOR re-derivation at tip — ALL CLOSED
Cure commit 5ea654501 verified: cat-file OK, %ae/%ce detain both, zero trailers, message carries the lead's provenance note (dead fixer batch, content-verified, "W20 r3 to confirm"). Diff = exactly the 6 r2-described files.
- MAJOR-1 (MainWorktreeTest swallow-catch): capture-then-assert-after-try at both sites (:131-147, :176-192); house pattern honored.
- MAJOR-2 (CacheReusingSummaryBackendTest HOME): adopts HomeSandboxTrait, useHomeSandbox/restoreHomeSandbox; trait snapshots $_SERVER+getenv BEFORE writing either (HomeSandboxTrait.php:51-64). OneSidedHomeSandboxTest roster row adds foreign Context/InstructionWalkPastRootTest.php (roster, not edit — per r1 instruction).
- MAJOR-3 (InstructionDiffTracker $seen): capture() inits $seen=[] (src/Context/InstructionDiffTracker.php:242-245) + InstructionDiffTurnContextTest zero-doc pin (constructs, asserts recheck()=='' ).
- MAJOR-4 (MemoryPromptWiringTest): the 4 $steps rows (withAgentInstructions/withAppendedSystemPrompt/withServiceTier/withHostPermissionMode) landed, citing b84ed2118/b5c81dff2 drift provenance.
GATE (plain pipe, cwd=candy-code, env -u LINES -u COLUMNS):
- --filter 'MainWorktree|SwallowingCatch|CacheReusing|OneSidedHomeSandbox|InstructionDiff|MemoryPromptWiring': Tests: 88, Assertions: 5720, Failures: 1 — the ONLY red is SwallowingCatchSrcCensusTest:137 'src/Tools/BuiltIn/SkillTool.php::execute', the adjudicated FOREIGN standing row (C-EXT-2.c 8ad2de199; r1 §4 + r2 §1 both re-quoted). Zero lane-attributable reds.
- r1's 5E victim family --filter 'TurnContext|EnvironmentBlock|SystemPromptWiring|CompactionFallback': OK 156T/899A.

## 2. Whole-wave cross-check at 86f80fef8 — PASS
- §4 marks: brief's "12 steps" is a miscount — plan §4 W20 = 10 #### steps (12 = the minted TEST FILES). All 10 ✅ marks present; d3d4b54f4/e80d84b40/8754885cb/f227b2b56 direct ancestors; the other 6 are lane-branch SHAs (house convention, NIT-5 carry) whose weld-mapped picks (3b6c2ca12,61c984cec,237224a7a,42939645a,603d2f167,aa8bdbeea,53b8e0fbc,972e0960b,a42d85304,93e301ef2,0992a65b3,ae8baac43) are ALL ancestors of HEAD.
- Author law: 25-commit wave set (picks+marks+weld-cures+cure) — 0 non-detain, 0 Co-Authored.
- Cross-lane: guard battery ReadmeRosterDrift|DocFigureProseDrift|EnvRosterDrift|KeyBindingDrift|TrustKey|ConfigWriteProducer|StderrEmitterCensus|DuplicatedTestHelperDrift|GlobDialect|TreeWideGuardRoster|LangParity = OK 523T/76032A at tip. en.php top-level dup-key scan EMPTY.
- Generated surfaces: gen-protocol-schema --check rc0; gen-command-docs/gen-tool-docs/gen-settings-doc --check rc0 (all "up to date"). Protocol/src touched post-r1 only by 5d3b47f05 (C-SES-7.f, W25) whose trio regen rode its weld — schema check proves coherence. candy-code-web: ZERO commits touch src-web or dist since e91eb1d47 → r1's vitest 27/27 + dist-by-rebuild verification carries (npm not runnable per brief).
- durations.tsv: all 12 W20-minted rows present (r1 §6 set re-grepped, counts match). Cure added methods only — no new files, no new rows owed.
- Post-cure interference: 72a6ad46b touches NO W20 surface (file-list grep empty).
- Hygiene: no cc/<W20-id> branch or worktree remains; live list = 15 in-flight lanes + weld-15/16 + EXT-5c-pristine/SEC-6.b-base harnesses (all lead/foreign).
- Laws: E646 — cure adds no timers; src edit is a 4-line init. Dormant wiring + fork/child unchanged since r2 (no W20-file diffs besides the cure).

## 3. Carries (pre-adjudicated, NOT findings)
- COMPARISON.md cells (GIT-08/PRM-14/session-import/SKL-N2/webhook) still unflipped — closeout doc-truth collection, r2 §3 (last touch cf53773d1).
- NIT-1..4 plan §4 row overclaims + NIT-5 mark convention — r1 §7, lead annotates at closeout.
- SkillTool src-census roster row — C-EXT-2.c (W6) closeout roster, standing identity.
- run-state wave_loops.20 still reads round 2/findings — lead re-syncs on this CLEAN verdict.
