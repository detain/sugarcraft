# REPORT — C-WEB-7.b: Web client nits (`@` debounce, cross-tab history clear + palette action, re-announced questions)

VERDICT: DONE. Commit `06364f419` on `cc/C-WEB-7.b` (worktree /home/sites/cc-cmp-C-WEB-7.b, base master 581ac6ede). Tree clean, single commit, never pushed. Author/committer `Joe Huss <detain@interserver.net>` (%ae and %ce greps separately 1/1), zero Co-Authored trailers.

## Shipped (9 files, +465/−20)
- **WEB-5-P3** `Composer.vue`: `mentionPartial` watcher is now a 120 ms leading+trailing debounce (`MENTION_SEARCH_DEBOUNCE_MS`); late partials lead at once, windowed partials collapse to one trailing search with the newest partial, `null` cancels the timer and resets the window, `fileSeq` latch kept, timer cleared in `onBeforeUnmount`.
- **WEB-6-P1** `stores/promptHistory.ts`: `onPromptHistoryCleared(listener)` lazily attaches one `window` `storage` listener (`candy-code-web:promptHistory` with `newValue === null`, or `key === null` for a clear-all); `clearPromptHistory()` notifies in-tab listeners (the storage event never fires there); "in-memory copy untouched" docblocks truth-flipped. `Composer.vue` subscribes at setup (resets `history`/`historyIndex=-1`/`draftBackup=''`), unsubscribes on unmount.
- **WEB-6-P2** `CommandPalette.vue`: `Actions` entry "Clear prompt history" (hint "this browser") after the theme entry; `window.confirm` then `clearPromptHistory()`. `README.md` prompt-history disclosure (:149-156) reworded: names the palette action, says sign-out clears the ring in every open tab — matches shipped behavior (docs-are-pinned law; no test pins the README row).
- **WEB-7-P1** `lib/questions.ts`: `speak()` sets `''` then the sentence on `nextTick` (identical repeats mutate the live region); trust half watches the C-SEC-4.b `contextSources.pendingTrust` **by `askId`** (field name confirmed) read-only, gated `status === 'asked'` with an announced-once latch: "`<session>` — project trust prompt for `<root>` is waiting in the Context panel". `stores/contextSources.ts` untouched.

## Tests (vitest serial --no-file-parallelism; 88 passed / 10 files targeted)
Per spec file (final green): promptHistory.spec 8; composerFilesImages.spec 11 (4 new debounce: 5 partials→2 searches `['a','abcde']` + popup shows last; stale-answer latch; null-cancel + window re-lead; unmount kills pending); composerHistory.spec 8 (4 new: cross-tab StorageEvent; same-tab `clearPromptHistory()`; key-null clear-all event; unrelated-key no-op — each asserts the send persists only the new prompt and recall sees only it); paletteClearHistory.spec 3 NEW (lists entry; confirm→key removed; cancel→key kept); questions.spec 6 (2 new: identical repeat → two sentence writes via sync-watch census; trust prompt announced once incl. same-askId re-delivery). Adjacent regressions green: auth.spec 7, components.spec, panels.spec, app.spec, topbarGenerate.spec.
Mutations 6/6 discriminating, cp+md5 restore verified: M1 debounce-neuter→3F; M2 key-null-branch-drop→1F; M3 in-tab-notify-drop→1F; M4 direct-assign→1F; M5 trust-announce-drop→1F; M6 entry-line-drop→3F.

## Gates
- vitest targeted set: 10 files 88/88 green (twice, post-restore).
- `npm run typecheck`: my files clean; 6 residual errors PRE-EXISTING at base in statusBar.spec.ts(×2)/reducer.spec.ts(×4) — identical in the untouched main tree, files outside my ownership; NOT mine, weld inherits them.
- `npm run lint`: single error PRE-EXISTING at base in `src-web/stores/__tests__/contextTrustRouting.spec.ts:27` (landed by C-SEC-4.b lane, reproduces in main tree); my 9 files add zero lint errors.

## Deviations / notes
- Palette tests drive the entry via `mousedown` (the entry's real pick handler) rather than Enter-on-input, to be order-independent.
- happy-dom's `window` has no `confirm`; the spec stubs it by direct assignment (spyOn refuses undefined).
- The identical-repeat pin observes the announcement ref with a `flush:'sync'` watch — each write is one DOM text mutation of the single-bound live region (App.vue:110).
- dist NOT rebuilt (weld duty); suite-figure.json / durations.tsv untouched; no protocol/PHP files touched.
