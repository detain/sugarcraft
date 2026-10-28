# C-WEB-4.c — Transcript toggles: show thinking, expand all tool cards — DONE

- Lane: cc/C-WEB-4.c @ /home/sites/cc-cmp-C-WEB-4.c, base master eed6630d3
- Commit: 22ddc7c65a039807744f0f0fe7f0ec24a5da673d ("C-WEB-4.c: Transcript toggles — show thinking, expand all tool cards")
- Author law: %ae grep -c detain == 1, %ce grep -c detain == 1 (separate greps), zero Co-Authored-By trailers. Tree clean after commit. NOT pushed.

## What shipped (client-only, per brief)

- **Store** `src-web/stores/transcriptView.ts` (new): pinia setup-store holding `showThinking` and `expandTools`, initialized from `readJson('transcriptView.<key>', false) === true` (strict — corrupt/absent falls back false) and persisted via `watch(...)` → `writeJson(...)` through `stores/storage.ts`. Deliberately separate from `stores/settings.ts` (C-WEB-4.b collision avoidance). Exposes `toggleThinking()` / `toggleExpandTools()`.
- **Toolbar** `Transcript.vue`: sticky two-button toolbar inside the scroller (`data-testid="transcript-toggles"`, buttons `toggle-thinking` "Show thinking" / `toggle-tools` "Expand tool cards"), `aria-pressed` mirrors store state.
- **Thinking** `ReasoningFold.vue`: `:open="live || view.showThinking"` — live folds unaffected, settled folds follow the toggle.
- **Tool cards** `ToolCard.vue`: `open` initializes to `settledOpenByDefault || view.expandTools` where `settledOpenByDefault = status==='error' || status==='denied'`; a watcher on `view.expandTools` re-applies while `toggledByViewer` is false; the head click sets `toggledByViewer` then flips — so failures/denials always open and a hand-toggled card keeps its state across flips.
- **Docs** `candy-code-web/README.md`: one sentence appended to the Transcript bullet (soft per step).
- **dist**: rebuilt via `npm run build` in the worktree; dist changes ride this commit per the step's docs/guards line (§2 welder-default overridden by the brief).

## Tests

- New `src-web/components/__tests__/transcriptToggles.spec.ts` pins the three required things: (1) each toggle flips every fold / every card (error/denied open regardless of expandTools); (2) a manually toggled card keeps its state across both flip directions; (3) state persists across remount — click toggles, unmount, fresh pinia, fresh components (incl. Transcript) read localStorage and open.
- In-step forced change: `components.spec.ts` gained a top-level `beforeEach(setActivePinia(createPinia()))` — its ToolCard/Transcript mounts had no pinia and my components now use the store (file previously only armed pinia inside the StatusBar describe). No assertions changed.
- Gates (final tree, serial always): targeted `npx vitest run --no-file-parallelism <5 files>` → Test Files 5 passed (5), Tests 54 passed (54), rc=0. Full `npx vitest run --no-file-parallelism` → Test Files 76 passed (76), Tests 593 passed (593), rc=0 (~46s).

## vue-tsc / base red disclosed

- `npx vue-tsc --build --force` → rc=2, 40 error lines. NONE in any file this lane touched (families: stores/agents/shells.ts, stores/contextSources.ts, components/panels/ProcessesSection.vue, dormantRoster.spec.ts, reducer.spec.ts, statusBar.spec.ts, contextPanel.spec.ts, contextTrustRouting.spec.ts).
- PROVEN base-red: cp-backed my 6 lane files to /tmp/opencode/CWEB4c-mine/, restored the pristine tree, re-ran → rc=2, 40 errors; `diff` of the 'error TS' lines base-vs-tip identical. Logs: /tmp/opencode/vue-tsc-CWEB4c-{base,tip}.txt. This is the §1.8 generated-protocol-chain desync (shells.*/session.contexts src changes not reflected in generated.ts) to be judged on the welded tree — not this lane's to fix.

## Harness finding (pre-existing, not my change)

happy-dom + @tanstack/vue-virtual: ANY re-render/nextTick after a Transcript mount makes `getVirtualItems()` return [] — rows vanish. Confirmed with a throwaway debug spec (deleted). Existing components.spec.ts Transcript tests survive by asserting synchronously. Consequence for the spec design: post-flush open-state assertions mount ReasoningFold/ToolCard DIRECTLY; Transcript is asserted via the toolbar (outside the virtualizer) `aria-pressed` + persisted-state remount, with `items: []` for toolbar/persistence legs.

## Deviations disclosed

1. `components.spec.ts` pinia `beforeEach` — §1.8 in-step forced change (mount surfaces now consume the store).
2. Spec-level choices forced by the virtualiser finding above (direct-component mounts instead of whole-Transcript row assertions).
3. README bullet taken (soft docs requirement satisfied).
4. dist rebuild committed with src (step's docs/guards exception).

## Welder notes

- New vitest spec file `transcriptToggles.spec.ts` — if the web-side durations/roster machinery needs a row, that's closeout duty (no candy-code phpunit files added; nothing owed to suite-figure.json / parallel-tests-durations.tsv).
- Hot-region serialized tag [ToolCard.vue]: this lane welds alone; base eed6630d3 carried no competing edit at cut time.
