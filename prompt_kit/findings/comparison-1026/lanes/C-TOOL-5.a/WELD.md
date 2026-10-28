# C-TOOL-5.a — WELD (batch-3 lane 6, conditional — gate met)

Gate check at weld time (per brief step 6): run-state `inflight['C-TOOL-5.a'].stage == 'clean'` verified live on disk (tick was 128-batch3-stop-C-WEB-4.d then, note reads "CLEAN r1 0C/0M/0m (ses_e7c09e6eaffen) ... Welder b3 step-6 gate UNLOCKED via stage==clean. NITs no-carry"). `lanes/C-TOOL-5.a/REVIEW-1.md` was ABSENT on disk — the r1 verdict is carried in the lead's run-state note + REPORT.md twin-writer disclosure; weld proceeded on that explicit unlock.

## Pick
- Lane tip: `bdf851899` (single commit, parent `878047472`; branch tip re-verified == bdf851899, worktree clean — no twin follow-on commits landed).
- Master pick: **ca69cb333** — zero conflicts, README.md + lang/en.php auto-merged (master had moved +24/+53 lines on those files since lane base; keep-both shape by auto-merge, php -l lang/en.php clean).
- patch-id pick == lane: d698f96508b3c6ccf727cce5f456d25ade41152b (PATCHID_SAME). `%ae|%ce` = detain@interserver.net both.

## Gates (MERGED tree, plain pipe, cwd candy-code, -c phpunit.xml, env -u LINES -u COLUMNS)
- Pair ReadBeforeWriteTest|BomPreservationTest: **OK 20T/91A** (EXACT vs builder; junit /tmp/opencode/cc-cmp-tool5a-pair.xml).
- Quartet EditTest|WriteTest|ApplyPatchTest|ToolCapSettingsTest: **OK 206T/695A** — zero regressions; superset of builder's 88T/430A because master merged additional *EditTest/*WriteTest-named files since 878047472 (filter widening, not lane growth).
- Drift 4 (SettingsSchemaDocDrift|TrustKeyDocumentationDrift|ReadmeSettingsTierClaim|ReadmeRosterDrift): **OK 47T/845A** EXACT.
- DocFigureProseDriftTest: **OK 92T/4059A** (builder 92T/4047A; +12A merged-tree doc content, same 92T).
- Builder five-guard (DuplicatedTestHelperDrift|GlobDialect|DuplicatedDocBlockLine|TreeWideGuardRoster|SymbolCitationDrift): **OK 58T/21546A** — builder's 1F base-inherited pair (CacheTtlIdleFold vs ModelMetadata) is GREEN at merged tree: pre-pick base-prove at 81c47175f already showed DuplicatedTestHelperDrift 14T/197A OK. Cured between lane build and weld; REPORT.md "Seam → gate/welder" item is CLOSED.
- `php tools/gen-settings-doc.php --check`: rc=0 "settings docs: up to date" on merged tree (LayeredSettings LAYERED_KEYS line rides the pick).
- 2 PHPUnit Deprecations on every run = standing AccessorDeprecationTest noise.

## Scripts
Commit 86f95e191: durations x2 — `candy-code/tests/Tools/BuiltIn/ReadBeforeWriteTest.php 0.053 10` (0.053 band after RuntimeToolResultUtf8Test), `candy-code/tests/Tools/Edit/BomPreservationTest.php 0.074 10` (0.074 band after Support/ToolOutputSpillTest). tsv 1555→1557; asc-violations 4==4 (both pre and post, recomputed with numeric compare).

## Carry
- suite-figure re-pin +20T live-enum (staleness pair standing until closeout re-pin).
- README.md/settings doc rows landed IN the pick (drift guards green) — no doc-row carry.

## Proofs + cleanup
- `git cherry master cc/C-TOOL-5.a` → `-` (single commit); PATCHID_SAME above.
- Pruned: worktree /home/sites/cc-cmp-C-TOOL-5.a + branch cc/C-TOOL-5.a after proofs.
- run-state: inflight.pop(C-TOOL-5.a) (load fresh — lead at tick 150-registered/174-welded at weld time), welded += C-TOOL-5.a, weld_notes entry, tick 128-batch3-weld-C-TOOL-5.a.

Weld chain: ca69cb333 → 86f95e191 (scripts) → weld: C-TOOL-5.a (this commit). §4 line 2190 cites ca69cb333.
