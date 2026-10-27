# P-TABS.h — DONE

**Verdict:** shipped, all gates green, commit `e94426fe06cee9ff7989d418a4146e47655753d3` on `cc/P-TABS.h` (base 8d9814130). Author/committer detain (separate greps 1/1), zero Co-Authored trailers. Never pushed.

## What landed
- `SessionTabs.vue`: trailing `+` tab (`data-testid=new-session-tab`) → `useNewSessionStore().start()` (picker-aware via the store, like the dashboard button), routes `{name:'session'}` on success, no route on cancel(null)/failure; unseen dot badge on non-active tabs (`data-testid=tab-unseen`, ask badge takes precedence via v-else-if).
- `layout.ts`: new `unseen` ref (Record<id,bool>, NOT persisted); watch on `sessions.byId` marks a non-focused session on busy→idle (first-seen status never marks); cleared by `focus()`, `closeTab()`, `pruneTabs()`.
- Tests: `sessionTabsNew.spec.ts` (3 tests: + starts+routes; refusal routes nowhere; badge appears on background finish + clears on select) — NEW FILE, gate must add durations TSV row if vitest shards ever use one; `layout.spec.ts` (+3 unseen store tests).

## Gates
- `npx vitest run --no-file-parallelism` sessionTabsNew+layout+grid: **22 passed** (3 files).
- Adjacent layout consumers panels+approvals+sessionDispose+picker: **28 passed** (4 files).
- `npm run typecheck` (vue-tsc --build): 6 errors, all in statusBar/reducer specs — **byte-identical error set at base** (stash-probe diff empty); zero in touched files.
- Mutation: neuter marker in layout.ts → exactly the 3 unseen legs red, others green; cp-restore md5-verified.
- npm ci log: /tmp/opencode/cc-cmp-npm-P-TABS.h.log (exit 0).

## Notes for welder
- No `dist/` rebuild done here (welder-only per brief).
- npm-0 ran inside this worktree only; welder needs it (or equivalent) on merged tree.

## Round 2 — review fixes (REVIEW-1)
- [MINOR] TAKEN: `SessionTabs.vue` `create()` now keeps a local `createError` ref (`describe(failure)` in catch, cleared at attempt start) shown as `<p v-if="createError" class="error" role="alert" data-testid="new-session-error">` between the tab list and the layout buttons — matching the sibling flow that is the spec (DashboardView.vue:27-28/:44, PaneGrid.vue:46-47/:81); the old comment ("the sessions store's own error surfaces") was false for the no-picker `sessions.create()` path and is replaced with the honest why. Pin: sessionTabsNew.spec "surfaces a refused new session next to the + button and clears on success" (rejected create → error visible with 'no room'; following success → error gone). Mutation: removing the catch-set → exactly that leg red.
- [NIT] TAKEN: ask-badge precedence leg — pending ask on `b`, then busy→idle; asserts store `unseen['b']===true` (mark real, not vacuous-absent), `tab-ask-badge` present AND `tab-unseen` absent. Mutation: flipping the unseen span's `v-else-if` to `v-if` → exactly that leg red.
- [NIT] DEFERRED (not implemented, per lead note): `layout.ts:73` `lastStatus` Map never deletes ids dropped via `session.deleted` — bounded (one short string entry per session id, cleared on reload) and the reviewer marked it optional; leave to a future hygiene pass.
- Gates at this tip: serial `npx vitest run --no-file-parallelism sessionTabsNew layout grid` **26 passed** (4 files; stash-probed base at this worktree tip under the same filter = 24 — exactly +2 new tests). `npm run typecheck` error set byte-identical to base (stash-probe diff empty; pre-existing reducer.spec TS2554s untouched). No `dist/` rebuild (welder-only).
