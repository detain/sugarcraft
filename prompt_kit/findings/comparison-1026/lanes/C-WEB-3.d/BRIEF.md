### C-WEB-3.d — Close a non-primary root's host (`workspace.close`)
- item: C-WEB-3 (sources: k3-dorm#3 (`workspace.close`), WEB-1-P1)
- kind: dormant-wire · S
- approach:
  - **Gap.** `workspace.close {root, force?}` (`WorkspaceMethods.php:37`) has no caller. The gateway's idle recycle is today's only release.
  - **Menu item.** Rows whose `summary.root` is not the server root (`SessionSidebar.vue elsewhere()`, :38-41) get a row-menu item "Close `<root>/` host".
  - **Confirm flow.** It asks for confirmation first. If the server refuses because a turn is running, a second confirm sends `force: true`. Afterwards, `stores/sessions.ts` drops that root's rows and reloads.
- files.create: `candy-code-web/src-web/components/__tests__/sidebarCloseRoot.spec.ts`
- files.modify: `candy-code-web/src-web/stores/sessions.ts` (`closeRoot`), `candy-code-web/src-web/components/SessionSidebar.vue` (row menu)
- tests: create `candy-code-web/src-web/components/__tests__/sidebarCloseRoot.spec.ts`. It pins:
  - the item appears only on non-primary rows;
  - the refused → force confirm path;
  - the rows reload.
- docs/guards: `candy-code-web/README.md` sessions-sidebar bullet (soft); vitest; dist rebuild.
- serialized: [hot:candy-code-web/src-web/components/SessionSidebar.vue] [hot:candy-code-web/src-web/stores/sessions.ts]
- deps: none
- phase: normal
- ruling: none
- risks: C-WEB-7 (another lane) edits the same row menu (Export over 4 MiB). Serialize the two.

