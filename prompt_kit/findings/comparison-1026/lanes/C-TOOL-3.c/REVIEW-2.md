# C-TOOL-3.c — REVIEW-2 (fresh full-lane review at real tip 6f69565d3)

Reviewer: cc-cmp step reviewer, 2026-10-28. Base 23fa8ab8e, tip 6f69565d3 (single commit, 9 files +572/−14). Supersedes REVIEW-1.md (which reviewed the stale re-delivery 5679f988f).

## VERDICT: CLEAN

0 CRITICAL / 0 MAJOR / 0 step-local MINOR. 2 NIT (below).

## Verified

1. **Traceability/scope.** `git cat-file` confirms 6f69565d3 (commit). `diff --stat 23fa8ab8e 6f69565d3` = exactly the 9 files declared in BRIEF.md (create: AcceptsShellOwner.php, ShellOwnershipTest.php; modify: registry, Bash, ShellOutput, ShellStop, EngineBackend, BackgroundShellRegistryTest, ShellStopTest). Porcelain 0. %ae grep -c detain@interserver.net = 1; %ce separate grep = 1; zero Co-Authored/Co-Developed in %B.
2. **Phantom-check.** `git show 6f69565d3:candy-code/src/Tools/AcceptsShellOwner.php` exists (interface, one type, strict_types, final-free by design). Bash/ShellOutput/ShellStop each `implements … AcceptsShellOwner …` with `withShellOwner()` in the blob; registry blob carries `owner` in start(), row shape, status/read/stop owner legs, `ownedBy()`, `rowVisible()`, parseRow leniency — all present on disk.
3. **Non-vacuity (red-before).** Reproduced independently: `git checkout 23fa8ab8e -- <5 src files>` (no stash; index+worktree), ran `--filter 'BackgroundShellRegistryTest|ShellStopTest|ShellOutputTest|ShellOwnershipTest'` → **59T, Errors: 6, Failures: 6** — matches builder's 6E+6F claim. Restored via `git checkout HEAD --` → porcelain 0, diff-vs-HEAD empty.
4. **Semantics.** Foreign-id answers byte-identical to unknown-id: `status` → null both; `read` → same `'no background shell '.$id.' is registered by this launch'` door (test asserts id-normalized string equality); `stop` → `assertSame` whole-array equality with the unknown-id shape. parseRow lenient: missing/non-string/empty owner ⇒ null (untagged, visible-to-all) — pre-tag export test pins it. Teardown stays ownerless: `teardown()` calls `stop($id)` with no owner (null caller sees everything, BackgroundShellRegistry.php:773-780); sole external caller LaunchContext::stopBackgroundShells() untouched (not in diff, src/Cli/LaunchContext.php:913). EngineBackend turnTools binds `$this->sessionId` at **EngineBackend.php:3290**, immediately after `$limits->applyTo($tool)` (:3282) and before both continue-arms (MutatesContextLedger :3291, AcceptsToolProgress :3299) — Bash's progress-arm rebuild carries the claim (order law pinned behaviorally + structurally in the test's file-slice strpos ordering pin). `sessionId` is `?string` default null (EngineBackend.php:631) → unbound launches bind null = exact pre-tag world. Production consumers: only ShellOutput/ShellStop pass owner args today (grep confirms no ownerless registry readers remain in serve surfaces).
5. **Emitters/timers/forks.** Diff +lines: zero error_log/fwrite/pcntl_alarm/proc_open; single `usleep(25_000)` in a bounded 160-iteration test poll. StderrEmitterCensus green in gate run.
6. **Gates reproduced (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, PtyRunToolTest never run):**
   - `ShellOwnershipTest` → OK Tests: 10, Assertions: 60 (exact).
   - 4-class shell family → OK 59T/350A (exact builder claim; naive 'Background|Bash|…' union DOES catch PtyRun* — builder and reviewer both used explicit class lists).
   - `BashBackgroundTest|BashToolProgressTest|ShellTailRingTest` → OK 20/77 (exact).
   - turnTools consumers (6-class union) → OK 67/417 (exact).
   - five-guard ∪ SymbolCitation|StderrEmitterCensus → OK 232/42078, 0 failures (DuplicatedTestHelperDrift green ⇒ new helpers compliant). Constant "PHPUnit Deprecations: 2" across every filter set ⇒ process-level, not lane files.
7. **Base-inherited foreign reds (not lane-attributable).** ProjectTierRefusalInventoryTest 2F names only Memory/AgentMemory.php + Support/MacAltNotice.php dot-paths (W11 picks); lane diff adds zero dot-path string literals (grep +lines = 0). ReadmeSuiteFigureDrift pair: pinned 25,537 vs live 26,556 — a 1,019T wave gap, lane contributes ≤10T → weld re-pin. ChatHostLangCatalogueTest 1F: lane adds no lang keys or Chat calls.
8. **Conventions.** strict_types first lines; PSR-12; final classes; with*-rebuild immutability (Bash via `new self(...array_replace(get_object_vars…))` house pattern, ShellOutput/ShellStop explicit total rebuilds); bare accessors; one type per file (tools/check-one-type-per-file.php denied by reviewer shell map — substituted: grep of all 9 files = single top-level type each, plus builder rc0 claim on 62 libs/3198 files). Comments say WHY (docblocks carry the design rationale).
9. **Laws.** E646: no blanket timeout anywhere in diff. No weld-only files committed (no suite-figure.json, no durations.tsv, no dist/). Seam is WIRED (turnTools binds it; consumers thread it). Census rosters: none needed flipping (zero new emitters/sprintfs/fork sites) — verified by green StderrEmitterCensus + zero-diff on those files.

## Weld roster (record)
- NEW `candy-code/tests/Tools/ShellOwnershipTest.php` → 10T → durations.tsv row at weld + suite-figure/README re-pin carries it.

## NITs (no action required)
- N1 `candy-code/tests/Tools/ShellStopTest.php:132` — new test's `teardown()` is inline, not try/finally; an assertion failure mid-test would strand the `sleep 120` child ~2 min. Matches the file's existing no-spawn convention elsewhere; the ShellOwnershipTest file itself uses try/finally throughout.
- N2 merge-law edge (documented-conservative, not a defect): a child export that omits `owner` can overwrite a locally-tagged row when it wins `rowWins`, widening that row to visible-to-all. This is exactly parseRow's stated "absence of a tag is never a claim" law and is pinned; fail-open toward visibility is the design choice, recorded here for the 3.d/3.e readers.
