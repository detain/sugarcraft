VERDICT: FINDINGS

Step 2119422aa (base ebf05989e, single commit, tree clean). One step-local MINOR blocks weld; everything else verified.

## Checklist results

1. SCOPE — PASS. `git diff master...HEAD --stat` = exactly the 5 declared files (InstructionDiffTracker.php +117, InstructionFileLoader.php +49, ImportResolver.php +30, tests/Context/InstructionDiffTurnContextTest.php +87, docs/MEMORY.md +12). Zero SessionHost/TurnSettleWork/MCP/Providers touches. No commit in ebf05989e..master (main tip e2e05598d) touches any of the 5 — cherry-pick is collision-free.

2. MEMORY.md DISJOINT vs C-CTX-2.c — PASS (weld-safe, no sequencing note). Ours: pure append of `### Instruction diff` at :900, between `### Containment` (:880) and `## On-disk layout summary` (:912). C-CTX-2.c (ec5d4c2a5) edits only prose lines at :630 (Auto-memory) and :735 (Dream pass). >150 lines apart, no shared context lines → three-way merge cannot collide.

3. MECHANISM — PASS on behavior. capture(): roots freeze in pass 1 (registering `$byReal`), children in pass 2, stat-only — body/sha null, never read, never hashed; `$shown` = realpath (absolute, stat-safe); blocked/deferred imports excluded at the single splice-point append; grandchildren collected transitively (append precedes recursion). recheck(): unchanged child = one `@stat`, renders nothing; the statOnly branch sits after the moved check (resolution-move → existing 'moved' Read line) and before the size gate (content-move → 'no-baseline' → "the file has no diffable frozen baseline. Read <path>", one pointer line); no file_get_contents on any child path anywhere in the diff — "freeze, no re-splice" is true in code, not just comments. Dedup cannot steal a root's body/sha entry (pass 1 completes first) — claim verified.

4. DOCBLOCK HONESTY — PASS with the MINOR below. Old DECLARED LIMITS blindness bullet ("child-only edit is invisible… would mean re-expanding per turn") removed because the code now falsifies it; new prose (cap, one-stat-per-child, pointer-only, revert-cannot-cancel, past-cap invisible) matches traced behavior.

5. PINS NON-VACUOUS — PASS. Mutation-reasoned: dropping pass 2 reddens test (a) (contains-string) and (b)'s forward-touch leg; removing the cap breaks (c)'s `substr_count === MAX_TRACKED_CHILDREN` (33 changed children → 33 lines); including child-32 breaks the absent-overflow assertion. (c) also forces the loader to actually inline all 33 children (a gate truncation would drop the count below 32 and redden). Same-size byte-swap + restored mtime renders '' — pins the stat gate excludes hashing.

6. GUARDS / LITERALS — PASS. DocFigure+MemoryDocDrift 99T/3988A OK at tip; the new MEMORY.md section breaks no heading/symbol census. No glob-shaped literals (no wildcard chars in any new fixture string) and no denial vocabulary; GlobDialect green. No new env/tool/command/binding → no roster owed. Note (informational, precedent-conform): the new section carries no DocFigure arm of its own; existing MEMORY.md arms stay green.

7. DUR — PASS. Modify-only; zero new *Test.php; suite-figure.json/durations.tsv untouched.

8. FOREIGN REDS — CONFIRMED foreign. ContainedPathInventoryTest 2F at tip names ONLY Memory/AgentMemory.php, Skills/SkillLoader.php 3→4, Host/Commands/ContextHostCommand.php, Host/TranscriptProjector.php, Tools/PathJail 5→6 — no lane file; the lane diff adds zero counted gate sites. Out-of-scope note: my five-guard list also included TreeWideGuardRosterTest (beyond the builder's five-family) — 2 extra reds naming tests/Context/MemoryRecallBlockTest.php, a file committed at base 16266186d and untouched here (lane diff grep: zero RecursiveDirectoryIterator/scandir/glob/opendir). Foreign-inherited, closeout/gate-owner duty (needs a WALKS_A_DIRECTORY_THE_TEST_MADE row), NOT lane-attributable.

9. AUTHOR LAW — PASS. `%ae`=detain (grep 1), `%ce`=detain (grep 1), names Joe Huss/Joe Huss, Co-Authored count 0, one commit ahead of base.

## Gates re-run (plain pipe, cwd worktree candy-code, env -u LINES -u COLUMNS, linked vendor)

- `--filter 'InstructionDiffTurnContext'`: **Tests: 13, Assertions: 54, OK** (matches builder). Broader `--filter 'InstructionDiff'`: 14T/60A OK (sibling class included).
- `--filter 'Context'`: **1881T/8736A, 0F OK** (namespace-wide superset of the builder's 196T/1407A exact-class set; all green; ContainedPathInventory lives under tests/Support so not matched).
- `--filter 'DocFigureProseDrift|MemoryDocumentationDrift'`: **Tests: 99, Assertions: 3988, OK**.
- Five-guard + TreeWideGuardRoster: **59T/15177A, 4F** = staleness pair (headline + artifact vs live enumeration, expected) + the 2 foreign TreeWideGuardRoster reds (item 8). Builder's own five-guard list (no TreeWide) reported 133T/15759A pair-only — consistent.
- `PHPUnit Deprecations: 2` present in every run incl. base — pre-existing, disclosed.

## Findings

- [MINOR step-local] candy-code/src/Context/InstructionDiffTracker.php:129-135 (MAX_TRACKED_CHILDREN docblock) + :63 — the per-root budget is decremented for EVERY considered child, including stat-failed/''/realpath-dedup-skipped ones that never freeze and cost nothing on the per-step sweep. With a shared-import graph (root 2's first k children already tracked under root 1), root 2 freezes fewer than 32 new children while the docblock promises "bounds the child half of the stat sweep at this many per document." Fix: one docblock sentence — "the cap counts children CONSIDERED per root in expansion order; children already tracked via another document (realpath dedup) or unreadable consume the budget without being frozen, so a later root may track fewer new children" (behavior may stay; REPORT.md already discloses the semantics).
