# C-WEB-3.c — Live workflow run status (`workflow.status`) — BUILT

Branch `cc/C-WEB-3.c`, base `2392ed1d2`, commit `a617e2746` (author/committer detain@interserver.net verified separately, zero Co-Authored trailers, plain -m).

## Shipped
- `src-web/stores/agents/workflows.ts`: `status(workflowId)` returns `MethodResult<'workflow.status'>['status'] | null` (null on failure; poll failures deliberately do not set `error` — event-driven `loadRuns` re-derives the row). Store docblock extended.
- `src-web/components/panels/WorkflowPanel.vue`: `STATUS_POLL_MS = 2000`, `TERMINAL_STATUSES = {completed, failed, cancelled}`; `polled` computed = newest open running row carrying a `workflowId` (tool-source rows without one stay unpolled — brief's by-design risk); `watch(polled, …, {immediate})` arms/clears the interval; terminal status → `stopPolling()` + `loadRuns(sessionId)`; `stopPolling()` in the sessionId watch; `onBeforeUnmount(stopPolling)` covers close/unmount. Poll machinery declared before the immediate sessionId watch (TDZ hazard avoided).
- NEW `src-web/components/panels/__tests__/workflowStatus.spec.ts` — 4 fake-timer pins: polls only while a row is running (settled-row baseline arms nothing; turn.completed→running row arms, 1999/1 boundary, cadence continues on non-terminal), stops on terminal and reloads runs (workflow.runs send count +1, no further status), stops on unmount, unpolled running tool row without workflowId.
- No schema edits: typed wrapper for `workflow.status` already in `protocol/generated.ts`. No dormantRoster change: that spec does not exist at this base (C-WEB-3.g pending). No README row in brief. No dist, no suite-figure.json, no durations.tsv.

## DUR (vitest side, for the gate roster)
- NEW spec file: `candy-code-web/src-web/components/panels/__tests__/workflowStatus.spec.ts` (vitest; no phpunit `*Test.php` minted).

## Gates (worktree candy-code-web, npm ci log `npm-0`)
- `npx vitest run --no-file-parallelism workflowStatus.spec.ts panels.spec.ts stores.spec.ts` → Test Files 3 passed, Tests 24 passed (run twice, incl. post type-fix).
- `npx vue-tsc -b --force` → exactly the 6 foreign TS2554 (statusBar.spec ×2, reducer.spec ×4); zero errors from lane files.

## Round-2 fixes (commit 3ce8946f5, pins only — zero production touch)
- MINOR (filter leg): new pin `skips the newest idless row and polls the older running one` — fixture `openPanel([RUNNING, TOOL_LIVE])`: wire rows are oldest-first (WorkflowMethods.php:159), reversed the NEWEST row is the idless running tool row, so `polled` must carry past it to wf1; asserts exactly one `workflow.status` send with params `{workflowId:'wf1'}`. (Review's literal `[TOOL_LIVE, RUNNING]` order would make wf1 the newest row and not discriminate — corrected to the wire-order equivalent of the stated intent.) Mutation M1 (`find(r => r.running)` drop-id): reddens exactly this one test; restored via cp, md5 712016ae… verified against pristine.
- NIT (direct stopPolling): new pin `stops polling on a session change whose rows keep the same id polled` — pre-loads s2's rows via `void useWorkflowsStore().loadRuns('s2')` with the SAME RUNNING row before `setProps({sessionId:'s2'})`, so `polled` never changes value and its watch stays silent: only the sessionId watch's stopPolling can clear the interval; asserts no further status sends across 6 s. Mutation M2 (remove `stopPolling()` from the :44 watch, immediate kept): reddens exactly this one test; restored, md5 verified.
- Gates: `npx vitest run --no-file-parallelism workflowStatus.spec.ts panels.spec.ts stores.spec.ts` → 3 files / 26 tests (24+2) green; brief's substring form `workflowStatus panels stores` → 24 files / 197 tests green. WorkflowPanel.vue byte-identical to a617e2746 (git status shows only the spec). No dist, no schema, no server touch.
