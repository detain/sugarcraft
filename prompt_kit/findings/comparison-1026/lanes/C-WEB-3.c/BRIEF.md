### C-WEB-3.c — Live workflow run status (`workflow.status`)
- item: C-WEB-3 (sources: k3-dorm#3 (`workflow.status`), WEB-1-P1)
- kind: dormant-wire · S
- approach:
  - **Gap.** `workflow.status` (`WorkflowMethods.php:59`) has no caller. A run row's status comes from the transcript-derived `workflow.runs`, so it goes stale while a run is live.
  - **Fix.** `stores/agents/workflows.ts` gains `status(workflowId)`. For an open running row with a `workflowId`, `WorkflowPanel.vue` polls it every 2 s.
  - **Polling lifetime.** The client interval is cleared on close, unmount or session change. When the status turns terminal, `loadRuns()` reloads the row to pick up its report.
- files.create: `candy-code-web/src-web/components/panels/__tests__/workflowStatus.spec.ts`
- files.modify: `candy-code-web/src-web/stores/agents/workflows.ts`, `candy-code-web/src-web/components/panels/WorkflowPanel.vue`
- tests: create `candy-code-web/src-web/components/panels/__tests__/workflowStatus.spec.ts` (fake timers): polling runs only while a row is running, stops on terminal and reloads runs, and stops on unmount.
- docs/guards: vitest; dist rebuild.
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: A `tool`-source run without a `workflowId` stays unpolled. That is by design.

