# C-TUI-2.g FIX-1 — round 1 (REVIEW-1 0C/0M/3m, all three minors cured)

New tip: **0d66f3720312ac9d4e1f7ea8333b1db0d234bd2a** (cat-file-verified commit object) on `cc/C-TUI-2.g`, stacked on the lane pick 98f6b0f4a, base 7646af980. No rebase — master moved, the lane branch was not touched by anything this cure needs. Files: `candy-code/src/Protocol/Methods/FilesMethods.php` +9/−1, `candy-code/tests/Protocol/FilesDiffCheckpointTest.php` +75/−1 (commit 0d66f3720, author/committer detain verified by separate greps, zero Co-Authored trailers; evidence dir stays untracked per lane convention).

## MINOR-1 (security) — CURED, mutation-proven ×2
`FilesMethods.php` `checkpoint_diff_failed` (was :113): the refusal now throws the FIXED literal `'the checkpoint diff is unavailable'` — the embedded `CheckpointDiff` reason (which can carry `… is gone: <gitDir>` / `project directory <path> does not exist`) never touches the wire. The cause is NOT swallowed: `$call->server->logError(new \RuntimeException($diff), 'files.diff checkpoint diff failed')` writes it to the server log channel — exactly the ISS-057 convention the `bg.spawn` refusal set (`$call->server->logError(...)` before a fixed-literal `RpcError::of`).
- **Path-absence pin:** `testADiffThatCannotBeTakenRefusesByNameAndKeepsServerPathsInTheLog` asserts `json_encode($response)` contains no fixture root AND `error.message` is byte-for-byte the literal. Log-channel legs: the collected sink line (fixture now passes a `log:` closure into `ServerContext::new`) contains the prefix AND the vanished gitDir.
- **M1** re-added the concatenation (kept logError) → exactly 1F (the new pin). **M2** deleted the logError line → exactly 1F (same pin, log leg). Both restores md5-identical to the pre-mutation copy (3262bbc0…).

## MINOR-2 (vacuous coverage) — CURED, mutation-proven ×2
Two new pins close the unpinned-refusal gap (the other three refusals stay pinned as-is):
- `testARequestAgainstAStorelessServerRefusesNoStoreByItsOwnName` — a second Dispatcher over a `sessionStore: null` `WorkspaceContext` (reached before any session lookup; dispatcher+hub torn down in `finally` before assertions). **M3** neutered the `$store === null` guard → exactly 1F (this pin), 8 others green.
- The diff-failure pin above drives `checkpoint_diff_failed` honestly: capture via `turnCheckpoint`, then `attachWorkspaceRef` rewrites the stored shape with `gitDir = <tmp>/gone/.git` — status/ref/sha untouched, so `isCaptured` (REF_PREFIX + 40–64 hex) still passes and the string only escapes inside `prepare()`. **M4** deleted the whole `is_string($diff)` block → exactly 1F (this pin). Restore md5-identical; tree re-verified against HEAD (diff shows only the FIX-1 hunks).
- Test file figure now **9T/64A** green (was 7T/49A).

## MINOR-3 (claim truth) — CURED
`REPORT.md` corrected in place: the old `npx vue-tsc --noEmit → 0 errors` claim was VACUOUS (solution tsconfig is `files: []`, checks nothing). Honest project-scoped measurement reproduced at tip 98f6b0f4a: `node_modules/.bin/vue-tsc --noEmit -p tsconfig.app.json` → **7 errors**, mapped and regen-verified:
| vue-tsc error (pre-regen tip) | schema row that supplies the type | cleared by regen? |
|---|---|---|
| ChangesPanel.vue:52 (col 29) — `{sessionId}` not in stale params `{ref?: string}` | `files.diff` params `sessionId` ($sid-ref) | YES |
| ChangesPanel.vue:52 (col 78) — `{sessionId, checkpoint}` params | `files.diff` params `checkpoint` (integer, min 1) | YES |
| ChangesPanel.vue:75 — `diff.value.checkpoints` absent from stale result | `files.diff` result `checkpoints` (required, one of the 5) | YES |
| ChangesPanel.vue:76 (×2) — same `.checkpoints` legs (clamp + compare) | same result row | YES |
| contextSources.ts:176, :187 — trust-ask `sessionId` shape | FOREIGN, unrelated to files.diff | remains (2 errors) |

Verified EMPIRICALLY: ran the regen pair in-lane (`php scripts/gen-protocol-schema.php --write` + `node scripts/gen-protocol.mjs`), re-ran project-scoped vue-tsc → the 5 ChangesPanel errors are exactly the ones that vanish, only the 2 foreign contextSources errors remain; both regenerated files then RESTORED (`git status` clean, trio/generated.ts NOT committed — lane rule; welder regenerates once). No step-local defect: all 5 are regen-supplied types, nothing left to cure.

## Gates — reproduced the reviewer's numbers (cwd candy-code unless noted)
- `--filter FilesDiffCheckpointTest`: **9T/64A green** (pre-cure baseline here 7T/49A +2 pins; 2 PHPUnit deprecations = harness-wide, reviewer saw them on every filter).
- `--filter ReadmeRosterDrift|EnvRosterDrift|TrustKeyDocumentationDrift`: **59T/40837A green** — byte-exact reviewer match.
- `--filter DocFigureProseDrift|ReadmeJsonErrorContractDrift`: **111T/4F** — all 4 inside ReadmeJsonErrorContractDriftTest (rostered standing reds, untouched).
- `--filter ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest` on the COMMITTED tree: **13T/361A, 1F = ProtocolSchemaDriftTest:39**, its diff exactly the files.diff entries — the §1.8 restored-trio state BY DESIGN, regen at weld flips it green.
- vitest (`--no-file-parallelism`, local bin): changesPanel.spec 16 + generated.spec 3 → **19 passed**. (PtyRunToolTest exclusion never in scope of these filters.)
- `php -l` clean both files. GlobDialect: new test literals carry no wildcards.

## WELD CARRIES (unchanged from REVIEW-1 §4, restated so they cannot be missed)
1. **Regen is a PAIR:** `php scripts/gen-protocol-schema.php --write` AND `npm run gen:protocol` in the weld commit. The committed drift is exactly `files.diff`; the old trio is self-consistent, so regenerating ONE without the other BREAKS `generated.spec.ts`.
2. On the regenerated tree run: **dist rebuild**, vitest `--no-file-parallelism` (changesPanel 16 + generated.spec 3 + protocol family), `ProtocolSchemaDrift` green, AND the **project-scoped** `node_modules/.bin/vue-tsc --noEmit -p tsconfig.app.json` — expect ≤2 foreign contextSources errors (document what remains; the solution-level check is vacuous).
3. **durations.tsv row OWED** for `candy-code/tests/Protocol/FilesDiffCheckpointTest.php` — ~11.5 s (live ServerContext + real git; multi-second).
4. **§7 suite-figure/README carry:** the reviewer's +7T/+49A figure counted the PRE-fix test file. FIX-1 extends that same file → at the new tip the live delta is **+9T/+64A** (assertions may wobble ±2 with provider rows; re-derive on the green weld run, never mid-run).
5. `docs/SERVER.md` owes NOTHING (ServerProtocolDocumentationDriftTest green pre-regen; no [docs:SERVER.md] row).
6. **`REVIEW-1.md` is main-tree-only** (`/home/sites/sugarcraft/prompt_kit/.../lanes/C-TUI-2.g/REVIEW-1.md`, 6084 B) — copy it (and this FIX-1.md + updated REPORT.md) onto the branch in the weld.
