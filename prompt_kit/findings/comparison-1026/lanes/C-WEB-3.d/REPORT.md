# C-WEB-3.d — Close a non-primary root's host (`workspace.close`) — DONE

- Branch `cc/C-WEB-3.d`, base `e36d86d84`, one commit: `5f0253544` (`%ae` and `%ce` both detain@interserver.net, zero trailers). Tree clean.
- Landed: `SessionSidebar.vue` gains a `closeHost` row-menu item "Close `<root>/` host" gated by `elsewhere()` (non-primary rows only) — first `window.confirm`, `turn_running` refusal (Gateway.php:468, `kind==='turn_running'`) triggers a second confirm that retries with `force: true`. `stores/sessions.ts` gains `closeRoot(root, force)` — sends `workspace.close {root, force?}`, drops that root's rows, awaits `load()` (refusal throws before the drop, so a live host keeps its rows). README sessions-sidebar bullet extended (soft doc).
- RPC params cross-checked against `src-web/protocol/generated.ts` (`workspace.close {root, force?, idempotencyKey?}`); `workspace.close` is in IDEMPOTENT_METHODS so the client stamps the key — param assertions use `toMatchObject`. No schema edits.
- Menu item is an additive `<li>` after Delete — no reshuffle (C-WEB-7 collision risk honored).

## NEW spec (vitest-side roster)
- `candy-code-web/src-web/components/__tests__/sidebarCloseRoot.spec.ts` — 4 tests. Gate/vitest rosters that enumerate spec files must pick this up (durations TSV / suite-figure untouched by the lane per law).

## Gates (run in the lane worktree, npm ci done — `npm-0`)
- `npx vitest run --no-file-parallelism sidebarCloseRoot` → 4 passed.
- Regression set (sidebarCloseRoot + sidebarActions + sidebar + sessionsRowActions + picker + panels) → **6 files, 34 passed**.
- `npx vue-tsc -b --force` → exactly the 6 foreign TS2554s (statusBar.spec ×2, reducer.spec ×4); zero in lane files.
- Mutations 3/3 discriminating, cp-restored: neuter force retry → 1F; always-show item → 1F; drop-before-reload removed → 2F.
- No dist commit; no generated-file, suite-figure or durations touches.
