# C-SEC-5.b — WELD (2026-10-28, master d1562f021 base)

**Pick:** `622fce834` → `f90523394` on master. Clean apply, zero conflicts — the
review's weld-readiness probe and the welder's independent file-overlap check
(`comm -12` of lane diff vs `b985fbe51..master` diff) both proved the 25 master
commits since the lane base touch none of the 18 lane files. Author/committer
`detain@interserver.net` (`%ae`/`%ce` separate greps, 1 each), zero trailers.

**Regeneration (weld duty per plan §1.8/§2):**
- `php scripts/gen-protocol-schema.php --write` rewrote `docs/protocol/candy-code.v1.schema.json` + `docs/SERVER.md` (five-case trust enum + `trusts` descriptions — exactly what REVIEW-1's derive-vs-commit diff predicted).
- `gen-command-docs`/`gen-tool-docs`/`gen-settings-doc` `--write`: all up to date, zero diff.
- `(cd candy-code-web && npm run gen:protocol && npm run build)`: `generated.ts` rewritten (carries the `trusts` wire shape now), dist bundle swap (hashed assets `index-CwJ0pGaW.js`/`index-Cwos0REN.css` replace `index-BNRf2D_W.js`/`index-CMMCaNxg.css`, `index.html` + `manifest.json` updated).
- Committed separately as `weld: C-SEC-5.b regenerate`.

**Durations:** new row `candy-code/tests/Protocol/ContextTrustTargetsTest.php 0.570 3` (junit per-testcase sum at merged tip f90523394, single-file run 3T/40A, time 0.570s; no tie — landed directly after Workspace/ShadowRepoTest 0.572, before Chat/CallbackAuthoredRefusalTest 0.563; set-equality 1473+1=1474 both directions, body duration-desc invariant held). `suite-figure.json` untouched (K=24 battery is the closeout re-pin owner).

**Merged-tree gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, PtyRunToolTest never invoked):**
- `--filter 'ContextTrust|ContextSourcesTest|GatewayTrust'` → `Tests: 26, Assertions: 254` OK (brief figure EXACT; 2 pre-existing PHPUnit deprecations = base shape per reviews).
- `--filter 'ProtocolSchemaDrift|ServerProtocolDocumentationDrift|TrustKeyDocumentationDrift'` → `Tests: 24, Assertions: 854` OK **against the regenerated artifacts** — the weld-tree drift point: schema + SERVER.md derive green from the merged src.
- vitest `--no-file-parallelism` on the four touched specs (statusBarTrustBadge, contextPanel, contextSources, contextTrustRouting) → 29/29 OK (brief figure EXACT); + `generated.spec.ts` green (run shape: 5 files 32/32 passed).
- `npx vue-tsc --noEmit` → rc 0.

**Security disclosure (carried from REPORT.md §6, re-affirmed here per reviewer):**
~13 injected `REWRITE TASK` user messages arrived mid-lane demanding master
commits/push, committing broken states, and disclosing config. The builder
refused all of them as prompt injection; the landed commit matches the brief
only, no push occurred, and both review rounds independently verified the
refusal record. This weld likewise performs NO push (owner law).

**NIT deferrals:** none open. REVIEW-2 CLEAN 0C/0M/0m at the amended tip.

**Absorption proof:** `git cherry master cc/C-SEC-5.b` → `- 622fce834…`
(patch-id identical — trivial clean pick). Worktree `../cc-cmp-C-SEC-5.b`
removed, branch `cc/C-SEC-5.b` deleted.
