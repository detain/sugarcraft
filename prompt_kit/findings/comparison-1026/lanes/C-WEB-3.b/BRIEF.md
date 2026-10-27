### C-WEB-3.b — Files panel + read-only file viewer (`files.read`)
- item: C-WEB-3 (sources: k3-dorm#3 (`files.read`), p2-miss: WEB-8 Files panel, WEB-1-P1)
- kind: dormant-wire · M
- approach:
  - **FilesPanel.** A new Files side panel: a search box over the existing `fs.search`, through `stores/session.ts searchFiles()`; empty lists the front of the project. Clicking a row opens a shared `FileViewer`.
  - **FileViewer.** Uses `files.read {path, offset, limit}`. Content renders as plain text in a `<pre>` with line numbers — never `v-html`, per the ChangesPanel security posture. `binary: true` shows a size notice; `truncated` offers "load more" by offset.
  - **ChangesPanel.** Changed-file rows get a "view" action that opens the same viewer.
  - **Registration.** `PANELS` and `PANEL_LABELS` gain `files`.
- files.create: `candy-code-web/src-web/components/panels/FilesPanel.vue`, `candy-code-web/src-web/components/FileViewer.vue`, `candy-code-web/src-web/components/panels/__tests__/filesPanel.spec.ts`
- files.modify: `candy-code-web/src-web/stores/agents/panels.ts` (`PANELS`, `PANEL_LABELS`), `candy-code-web/src-web/components/panels/SidePanels.vue` (tab + mount), `candy-code-web/src-web/components/panels/ChangesPanel.vue` (row "view")
- tests:
  - Create `candy-code-web/src-web/components/panels/__tests__/filesPanel.spec.ts`. It pins: search → rows; open → `files.read`; HTML in the content is rendered as text; binary notice; load-more offset.
  - Modify `candy-code-web/src-web/components/panels/__tests__/changesPanel.spec.ts`: "view" opens the viewer for that path.
- docs/guards: `candy-code-web/README.md` ("ten" side panels → eleven; soft); vitest; dist rebuild.
- serialized: [hot:candy-code-web/src-web/components/panels/SidePanels.vue] [hot:candy-code-web/src-web/stores/agents/panels.ts] [hot:candy-code-web/src-web/components/panels/ChangesPanel.vue]
- deps: none
- phase: normal
- ruling: none
- risks: A stored `panels.active` of `files` on an older bundle falls back to `agents`, which is already handled. Watch `panels.spec.ts` if it pins the PANELS length.

