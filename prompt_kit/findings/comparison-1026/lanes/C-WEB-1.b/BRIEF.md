### C-WEB-1.b — Status-bar model select never shows an unapplied pick
- item: C-WEB-1 (sources: WEB-2-P1 client half)
- kind: fix · MAJOR · S
- approach:
  - **Root cause.** `pickModel()` (`candy-code-web/src-web/components/StatusBar.vue:52-55`) emits `/model` and leaves the `<select>` DOM showing the pick. The bound `:value` (the summary) did not change, so Vue never repaints. A refused or still-pending switch therefore displays a model the session is not using.
  - **Fix.** Right after the emit, `pickModel()` writes `modelOptionValue(currentProvider)` back into the element. The select then repaints only from the summary, which C-WEB-1.a now updates.
  - **Independence.** The step does not need C-WEB-1.a; it is most useful after it.
- files.create: none
- files.modify: `candy-code-web/src-web/components/StatusBar.vue` (`pickModel`)
- tests: modify `candy-code-web/src-web/components/__tests__/statusBar.spec.ts`. It pins three things:
  - after picking B, the DOM value is the summary's A;
  - a summary prop change to B repaints;
  - picking A afterwards emits `/model A …`.
- docs/guards: vitest; dist rebuild (see Notes).
- serialized: [hot:candy-code-web/src-web/components/StatusBar.vue]
- deps: none
- phase: normal
- ruling: none
- risks: A test that drives `change` with a synthetic event must set `target.value` first, or the reset is invisible to it.

