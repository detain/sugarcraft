# C-SEC-5.b — REVIEW-2 (round 2, amended tip)

**VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR.** Round-1 MAJOR-1 (DuplicatedTestHelperDrift 2F) is REMOVED at 622fce834; every fixer claim reproduced on disk.

## Fix diff (`git diff 563b1c0c5 622fce834`)
Exactly 2 files, 9+/8−, all test-internal:
- `ContextTrustTargetsTest.php`: `declareMcpServers`→`declareLedgerServer` (def :202 + all 3 call sites :71/:111/:142), `trustRow`→`trustRowFor` (def :181 + sole call site :114). No stale callers — 15T/114A green proves completeness.
- `ContextTrustPromptTest.php`: adds `use ...\Support\WireClient` (:13), shortens the two FQN param spellings. Declaration-only cosmetic; runtime-identical.
- Result: the surviving `sources` pair is same-signature in both files (`WireClient $client, string $sessionId`); the drift pairs are broken. Zero behavior/src/web/doc change in the amend range.

## Gates (re-run by reviewer, cwd=worktree candy-code, plain pipe, -u LINES -u COLUMNS)
- `--filter DuplicatedTestHelperDrift` → `Tests: 14, Assertions: 194` OK (was 14T/190A/2F at round-1 tip). Claim matched EXACT.
- `--filter ContextTrust|ContextSourcesTest|GatewayTrust` → `Tests: 26, Assertions: 254` OK (= round-1 15T/114A + 11T/140A, family unchanged).
- 2 PHPUnit deprecations in every run = pre-existing base shape (also in REVIEW-1).
- Vitest NOT re-run — amend range touches zero `.vue`/`src-web` files (diff confirms); round-1 vitest 29/29 stands.

## Laws & scope
- `git status` clean. Amended commit author+committer `detain@interserver.net` (separate greps), zero Co-Authored trailers.
- `master...HEAD` file set = the same 18 files as round-1 REVIEW (no scope drift). Protocol trio, dist/, suite-figure.json, durations.tsv still untouched (2-file amend diff).
- Round-1 ACCEPTED items (protocol-restore procedure, security doors via `grantProjectTrust`/`SettingsWriter::grantTrust`, en-only lang, injection-refusal record) ride the unchanged part of the commit — re-confirmed by the empty amend-range outside the two test files.

## Residuals
Weld duties unchanged from REVIEW-1: trio regeneration, durations row for `ContextTrustTargetsTest.php`, staleness-pair/standing base reds not lane-attributed.

---

## Weld-readiness re-check 2026-10-28 (pick of 622fce834 onto master d1562f021)

- Lane base = `b985fbe51` (merge-base master↔cc/C-SEC-5.b, disk read). Master advanced since (C-TOOL-3.b/7.d/7.e, C-SES-9.b/1.b, C-PRV-5.d/5.c, C-MCP-2.a, C-TUI-1.a welds + durations rows), tip `d1562f021`.
- File-overlap probe `git diff --name-only b985fbe51..master -- <all 18 lane files>` → **EMPTY**. The 5.c/1.a hunks (Chat/Providers/ComposerVim) and MCP-2.a share zero paths with the lane; the lane touches NO lang file (18-set contains none), so TUI-1.a lang churn cannot collide either.
- CONFLICT-RISK: **none** — every lane file's blob at master equals the pick's preimage, so 3-way application is trivially clean. (merge-tree shell-sim denied by reviewer permission map; the empty pathspec diff is the equivalent proof.)
- No re-review needed; R2 CLEAN stands at d1562f021.
