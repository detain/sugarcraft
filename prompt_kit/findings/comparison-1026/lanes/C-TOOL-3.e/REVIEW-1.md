# C-TOOL-3.e — REVIEW-1 (fresh r1, web+protocol lane)

VERDICT: APPROVE — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 1 NIT.

Lane `cc/C-TOOL-3.e` @ eef374f61 (base cf0cfd0be, parent disk-verified). Reviewer worktree
/home/sites/cc-cmp-C-TOOL-3.e; read-only; review lands here per §1.6 (the instructed bare
`lanes/C-TOOL-3.e/REVIEW-1.md` root path does not exist — this prompt_kit home is its
equivalent, disclosed).

## Scope audit — CLEAN
- `git diff master...HEAD`: EXACTLY the 9 claimed files, +994 (numstat sum verified).
  The spawn-brief's "21 numstat" does not reproduce: two-dot `master..HEAD` shows 12
  (9 lane files + 3 reverse-hunk files from the lead's two W16 master commits landing
  AFTER this base). No 21-file shape exists on disk; scope judged against the real 9.
- Every file is in the brief's declared create/modify set; every declared file except
  `gen-protocol.mjs` landed. The generator needed no edit — it derives the surfaces
  dynamically (regen on the dirty tree produces shells.* correctly). Design-listing
  artifact only, not a finding.
- Generated surfaces (schema.json, SERVER.md, generated.ts) dirty-UNCOMMITTED = regen
  law satisfied; welder regenerates once on the fused tree (plan §4 W17). dist/ untouched.
- SidePanels.vue and stores/agents/panels.ts zero-diff (Processes section lives inside
  BackgroundPanel.vue only, as specced). Dispatcher/Definitions/MethodSchemas edits are
  additive hub rows inside their declared regions. Author/committer detain both greps,
  zero trailers.

## Semantics — CONFIRMED
- Scope-gating enforced AT THE DOOR: Dispatcher.php:342 `$call->requireScope($spec->scope)`
  runs before any handler; shells.stop declared Scope::Write + sideEffects=true; test 1
  pins the declarations (read-only reviewer probe + -32003 path).
- Owner = shape-validated sessionId param used directly as the C-TOOL-3.c
  BackgroundShellRegistry owner key; NO `$call->host()` open anywhere (no session-open
  side effect) — matches the merged C-TOOL-3.c contract (row tag bound server-side via
  AcceptsShellOwner::withShellOwner(host sessionId), EngineBackend:3321).
- Foreign == unknown identical-answer: same sentence + kind shell_not_found on output,
  all-false found:false stop on a foreign id; stranger provably kills nothing (state
  still running after). Pinned.
- Stop idempotent: 2nd call found:true, wasRunning:false, settled exitCode 0. Pinned.
  Web client auto-threads idempotencyKey for side-effect methods (client.ts:164 +
  generated side-effects list carries shells.stop) → replay-safe across reconnects.
- Rows leak nothing: exact-key census ['shellId','label','state','exitCode','exitNote',
  'expired','ageSeconds'] — no pid, no output paths; output/stop replies pid-free too.
- Cursor: '0.0'/'6.3' pair tokens accepted+threaded (fresh→drain→rewind walk pinned);
  'abc' refused naming cursor at the handler; -5 refused at the schema integer(0) door.
- No new proc_open/fork sites in the diff (spawns live in the pre-registry, rostered by
  C-TOOL-3.c); child-lifetime roster untouched = correct. ShellsMethodsTest spawns real
  `sh -c` children, bounded (2ms sleeper seam + registry->teardown(), 1.5s wall) — §1.10
  honored; §1.9 clean (no glob-shaped/refused literals); §1.5: new test files reported
  in the lane REPORT for the closeout durations row; suite-figure/durations untouched.

## Gates — reproduced EXACT on the dirty-regen tree
- `ShellsMethodsTest`: OK (7 tests, 58 assertions) 1.5s.
- `tests/Protocol` dir: OK (120 tests, 1163 assertions); drift pair filter 12T/341A green.
- vitest `--no-file-parallelism`: processesSection.spec.ts 7/7 (+ dormantRoster 3,
  generated 3, backgroundSchedules+panels 16/16) — serial, all green.
- vue-tsc: NOT reproducible by this reviewer — permission map rejects `npx vue-tsc` /
  `npm run typecheck`. Builder claims clean; vitest compile + resolved generated
  imports are circumstantial support. Disclosed unverified, not counted as a finding.

## Findings
- MINOR (step-local): ProcessesSection.vue template Stop handler calls the async
  `shells.stop()` (store rethrows on rejection) with no catch — a rejected shells.stop
  (shells_unavailable, or write-scope lost after reconnect) fails silently and plants an
  unhandled rejection; the BackgroundPanel precedent wraps such acts in `act(() => …)`
  which surfaces the error. Fix: route the click through the same `act()` pattern (or
  try/catch into `shells.error`).
- NIT: ShellsMethods::output validates the cursor BEFORE the owner/unknown lookup, so a
  bad cursor on a foreign shellId answers invalid_params where an unknown id answers
  shell_not_found. The shellId was caller-supplied either way — no existence oracle —
  but ordering the not-found check first would make the identical-answer property
  uniform across all inputs.
