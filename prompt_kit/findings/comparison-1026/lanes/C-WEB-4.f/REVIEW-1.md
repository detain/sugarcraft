# C-WEB-4.f — REVIEW round 1 (reviewer: step lane cc/C-WEB-4.f @ 8d39a7ca0, parent 978ff2889)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 2 NIT

## Verified on disk
- Branch/log/diff: single commit 8d39a7ca0, author+committer `Joe Huss <detain@interserver.net>` (both greps), 0 Co-Authored lines. 6 files +223/−1, exactly the brief's create+modify minus the three generated files (restore law). SessionSidebar 1-line, declared.
- `SessionSummaryBranchTest`: 4T/8A green (measured). `tests/Protocol` dir: 131T/1200A with exactly 1 failure = `ProtocolSchemaDriftTest::testTheSchemaFileAndTheServerDocTablesAreCurrent` (stale committed schema.json vs live Definitions — expected shape, see law below). `ServerProtocolDocumentationDriftTest` green 6T/59A.
- vitest serial `branchBadge.spec.ts`: 5/5 pass.
- Worktree `git status --porcelain` clean; trio git-clean at HEAD.

## KEY JUDGMENT — trio NOT committed in lane is LAWFUL, not a finding
`ProtocolSchemaDriftTest` compares the COMMITTED artifacts vs LIVE generation (testTheSchemaFileAndTheServerDocTablesAreCurrent: assertSame($schema->json(), file_get_contents(SCHEMA_FILE)) + same for SERVER.md tables) → restored trio = RED at tip, by construction. But plan §1.8 (prompt_kit/findings/comparison-1026/plan-head.md:76) is explicit: protocol lanes run `gen-protocol-schema.php --write` + `npm run gen:protocol` locally, then RESTORE the three files before committing; "ProtocolSchemaDriftTest, ServerProtocolDocumentationDriftTest, generated.spec.ts and dormantRoster.spec.ts are judged on the welded tree; reviewers do not file their red in a lane worktree." The C-SES-7.e trio-commit-riding-the-lane pick (61c984cec) predates this text correction (STATE.md:134). No finding; welder rider applies (below). Note: SERVER.md contains no SessionSummary-field tables (grep 'snippet'/'SessionSummary' empty) → regen may leave SERVER.md byte-clean; schema.json + generated.ts definitely change.

## Core judgments
1. Branch source truth: `$row['git_branch'] ?? null` in ServerContext::summary() (lane :366 hunk) — writer `SessionStore::createSession` :297 param, INSERT :300, blankToNull; fork inherits :353; import restores :821. NO git/proc call on the summary path (grep git_branch in src shows only these writers + MCP tool ops + read sites). Store never UPDATEs git_branch → value is fixed at session creation.
2. Null/absent door: StatusBar `v-if="summary?.branch"`, sidebar `v-if="summary.branch"`; specs pin explicit-null AND key-absent on BOTH surfaces (5 tests; always-render mutation → the two null/undefined StatusBar + null-sidebar pins redden, matching the claimed 3).
3. Escaping: Vue text interpolation + :title attribute binding only, zero v-html → escaped. Git refname rules exclude control chars; blankToNull kills empty. Import path could forge a hostile string; Vue still escapes. No PHP spec pin for a weird-branch name — thin but defensible (NIT-1).
4. Tooltip honesty: both surfaces title "Branch at session start: …"; store proves write-once semantics (1). 
5. Single source: both components read `summary.branch` off the same wire field; StatusHostCommand (TUI /status, pre-existing) reads the same row column.

## MINOR (step-local)
- M1 — builder's gate claim "vue-tsc exactly the standing 20 errors, zero in step files" is FALSE for the committed tip: `npm run typecheck` in the lane worktree = 32 errors; 6 NEW step-file errors (StatusBar.vue:175 ×3, SessionSidebar.vue:310 ×3 — "Property 'branch' does not exist on type 'SessionSummary'", double-counted across the dual tsconfig build) caused by the law-mandated stale restored generated.ts. NOT a code defect — committing generated.ts in-lane would violate §1.8, and the trio regen at weld clears all 6; vite build does not typecheck so dist is unaffected. Claim-truth fix only: state figures as "20 at tip-minus-lane / 32 as-committed (6 unique step-file TS2339, trio-lag per §1.8) / expect back to 20 post-weld-regen".

## NIT (out-of-scope observations)
- NIT-1: no PHP test pins an exotic branch string (quotes/emoji) through schema validation; Schema::string() has no charset constraint and the untrusted-via-import surface is thin. Vue escaping covers the rendered promise.
- NIT-2: brief cites SessionStore.php:271,311,776; live lines 290-305/340/805 — plain cite drift.

## Base-carried reds (accepted)
AttachPagingTest ×2 (RemoteSessionHost instance) + WorkspaceHostTest gateway string leg reproduced at tip exactly as claimed (3F); lane diff touches only Protocol/web components — no path to those asserts; consistent with batch-8-era foreign carry, welder re-judges on merged tree.

## Welder carries (rider on merged tree)
1. Protocol trio regen: `php scripts/gen-protocol-schema.php --write` + `(cd candy-code-web && npm run gen:protocol)` (+ SERVER.md may land byte-clean); then ProtocolSchemaDriftTest + generated.spec.ts + dormantRoster.spec.ts green, typecheck back to the standing 20.
2. dist rebuild (`npm run build`) same rider.
3. DUR row: SessionSummaryBranchTest.php (4T) into scripts/parallel-tests-durations.tsv.
4. Suite figure +4T re-pin. §4 cite 8d39a7ca0.
