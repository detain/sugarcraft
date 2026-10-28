# C-TOOL-3.c — REVIEW-1 (step reviewer, 2026-10-28)

VERDICT: FINDINGS

## CRITICAL
- **Phantom commit claim.** Spawn brief declared single commit `5679f988f` — object does not exist
  (`cat-file` fails in main repo and worktree). Real tip is `6f69565d33d2c55ec5a22fec59e53304257b779f`,
  authored 04:34:12 — AFTER this review's first probe (branch tip was base `23fa8ab8e`, `master..HEAD`
  empty, all work uncommitted). Reflog proves it was a live lane mid-flight, not a ghost record.
  CURE: orchestrator must re-derive lane tips from disk before spawning reviewers (phantom law);
  weld must pick `6f69565d3`, never cite `5679f988f`.

## Substance (vs design-tool.md §C-TOOL-3.c — the spec; the spawn brief's "BackgroundShellCatalog /
## ChatSessionContext::withBackgroundShellCatalog / adoptCatalog" text matches NO design block)
- Diff == declared set exactly: 9 files = design files.create+files.modify, zero extras; no
  suite-figure/durations/dist/env-key touches (diff-grep 0). Single commit; `%ae` and `%ce` greps
  separate = detain@interserver.net; zero Co-Authored/Committed-by trailers; tree clean after gates.
- Semantics verified by read: owner rides start/rows/toArray(rows-map)/parseRow (pre-tag/''-forged
  export → null, visible-to-all); status gates visibility BEFORE enforceLifetime; read/stop answer
  foreign ids with the byte-identical "not registered" door (non-probeable, pinned str_replace-
  identity in test 1); teardown unscoped (claim ≠ lock) per design. Spawn/kill/pgid paths untouched.
- turnTools order law correct (new arm before both `continue` arms; Bash rebuild carries shellOwner —
  behavioral test 9 + structural source-scan test 10).
- Non-vacuity: foreign-kill pin spawns real `sleep 120` and asserts running-after-refusal (fails
  without gate); owner-survives-fork-merge pinned (fails without parseRow/toArray carry); null-owner
  visibility pinned. All three design test-mandates present. DuplicatedTestHelperDrift discipline:
  helpers uniquely named (scopedRegistry/stopToolFor/outputToolFor/claimOf) — guard green.

## Gates reproduced (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, PtyRunToolTest never)
- `--filter 'ShellOwnership|BackgroundShell|ShellStop|ShellOutput'` → 74T/412A/0F (ShellOwnershipTest alone 10T/60A).
  Builder's claimed "2T/4A SessionOwnedBackgroundShells" matches nothing on disk — stale-claim, like the SHA.
- `--filter 'EngineBackend'` → 288T/1804A/0F (claim was 189T/1244A/1F DreamPassTest).
- Broad `--filter 'Backend'` → 2F: RemoteBackendTest::testAnExistingSessionOpensFromItsSnapshotByUniquePrefix
  + ChatTest::testNoTitleBackendMeansNoSuggestionCall. Isolated → each still fails (deterministic).
  Base-reproval via cp-harness DENIED by reviewer permission map (tracked-file writes rejected);
  proven foreign instead: lane touches neither test file nor their subjects (Chat suggestion/EchoBackend
  and Remote attach — EngineBackend::turnTools is in neither path), AND both fail identically in the
  main tree (master tip, lane NOT on master). DreamPassTest claim is moot — not selected, not red.
- Guards: DuplicatedTestHelperDrift|GlobDialect|OneSidedHomeSandbox|TreeWideGuardRoster → 50T/4049A/0F.
- 2 "PHPUnit Deprecations" in every run = pre-existing AccessorDeprecationTest private providers (era1
  lineage), appear under unrelated filters and in the main tree — not lane-introduced.

## MINOR (out-of-scope) — none lane-attributed. The 2 broad-'Backend' reds ride the base era for weld.
## NIT — tools/check-one-type-per-file.php not runnable here (bare `php <script>` denied); interface
file carries exactly one interface (read-verified).
