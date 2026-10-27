# C-WEB-3.c — REVIEW-1 (round 1; static, shell denied in reviewer sandbox; lead persisted verbatim @1411@)

VERDICT: FINDINGS (1 MINOR, 1 NIT; no CRITICAL/MAJOR)

Verified clean:
1. **Scope** — worktree changes match the 3 declared files; harness.ts byte-identical, MethodSchemas.php:528 + generated.ts `workflow.status` untouched (params `{workflowId}` / result `{workflowId,status}`); no dist, no server PHP, no schema regen. Reflog = ONE commit 2392ed1d2→a617e2746 "C-WEB-3.c: workflow status poll rides the open panel", identity Joe Huss <detain@interserver.net>, no Co-Authored trailer in the message. (`%ce`/tree-listing proof owed to welder: `git show --stat`, `%ae`/`%ce` greps.)
2. **Wire honesty** — `status()` reads exactly the generated `MethodResult<'workflow.status'>['status']`; TERMINAL_STATUSES {completed,failed,cancelled} == server `WorkflowStatus::isTerminal()` (WorkflowStatus.php:28-31). Null-on-failure without error spam documented (workflows.ts:52-64) and does not multiply: ticks stay on the fixed 2 s interval; no timeout invented (E646-clean; same shape as AgentView.vue:48-58 precedent).
3. **Lifecycle** — arm only for newest open running row with an id (:53,62-65); stop on terminal + `loadRuns` exactly once (:57-59, no loop since `polled` unchanged ⇒ watch silent); sessionId watch stop (:44); onBeforeUnmount (:68); machinery `pollTimer`/`stopPolling` (:34-39) declared before the immediate watch (:41) and `polled`/`pollStatus` (:53-60) before their immediate watch (:62) — no TDZ.
4. **E646/gates/DUR** — poll cadence is UI, RPC bounded as elsewhere; REPORT §DUR lists the vitest spec, no phpunit row; dormantRoster correctly absent at this base (C-WEB-3.g pending).

- MINOR (step-local): `src-web/components/panels/__tests__/workflowStatus.spec.ts:98-102` — the `run.workflowId` filter leg in `polled` is unpinned: the drop-id mutant (`find(r => r.running)`) behaves identically on every fixture (idless newest ⇒ `?.workflowId ?? null` ⇒ no arm). Fix: openPanel([TOOL_LIVE, RUNNING]) and assert one `workflow.status` send with params `{workflowId:'wf1'}` (real code skips the idless newest row to the older running one).
- NIT (step-local): session-change `stopPolling` (WorkflowPanel.vue:44) has no direct test — only incidental via `watch(polled)` re-arm; code present and ordered correctly.

Builder-claimed gate lines (3 files/24 tests; vue-tsc only the 6 foreign TS2554) not independently re-runnable here — re-run at weld.

(Lead note: fixer r2 dispatched for the MINOR (+ NIT direct test if cheap); weld duties after r2: picks a617e2746(+fix), dist rebuild commit-if-changed, vitest serial re-run, vue-tsc foreign-6 only, §4 unstaged, evidence lanes files + run-state; no phpunit DUR.)
