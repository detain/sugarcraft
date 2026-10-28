# BRIEF — C-WEB-7.b: Web client nits: `@` debounce, cross-tab history clear + palette action, re-announced questions
wave ? | phase normal | area web7 | item C-WEB-7 (sources: WEB-5-P3 (client half), WEB-6-P1, WEB-6-P2, WEB-7-P1) | kind fix · MINOR · effort M
deps: C-SEC-4.b
serialized: [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/stores/promptHistory.ts] [hot:candy-code-web/src-web/components/panels/CommandPalette.vue]
ruling: none
source/design: 

## Design block (verbatim fields)
### item
C-WEB-7 (sources: WEB-5-P3 (client half), WEB-6-P1, WEB-6-P2, WEB-7-P1)
### approach
Client-only, no protocol.
- **WEB-5-P3, debounce.**
- Root cause: `Composer.vue:86-101` watches `mentionPartial` with no debounce, so every keystroke after `@` is a synchronous `fs.search` on the shared serve loop.
- Fix: a leading + trailing debounce of 120 ms (`MENTION_SEARCH_DEBOUNCE_MS`).
- A partial that arrives more than 120 ms after the last search runs at once, so the first `@` still opens instantly.
- Partials inside the window collapse to one trailing search carrying the newest partial.
- A `null` partial cancels the pending timer and resets the window.
- The `fileSeq` latch stays, and the timer is cleared in `onBeforeUnmount`.
- The existing specs, which type one partial per step, keep passing unchanged.
- **WEB-6-P1, cross-tab resurrection.**
- Root cause: `Composer.vue:147` loads the ring into memory once. `pushPromptHistory()` (`stores/promptHistory.ts:30-36`) writes `[text, ...inMemory]`. `clearPromptHistory()` (:44-46) removes only the key, so another open tab's next send rewrites the whole pre-sign-out ring.
- Fix in `promptHistory.ts`: a new `onPromptHistoryCleared(listener): () => void`.
- It lazily attaches one `window` `storage` listener. That fires on `key === 'candy-code-web:promptHistory' && newValue === null`, or on `key === null` (`localStorage.clear()`).
- `clearPromptHistory()` also notifies in-tab listeners, because the `storage` event never fires in the tab that made the change.
- `Composer.vue` subscribes at setup and unsubscribes on unmount. A clear resets `history` to `[]`, `historyIndex` to -1 and `draftBackup` to `''`.
- Correct the docblocks that say "the in-memory copy callers hold is untouched".
- **WEB-6-P2, no clear UI.**
- Root cause: `candy-code-web/README.md:146-147` advertises `clearPromptHistory()` "any time without signing out", but it has no UI. Its only caller is `protocol/auth.ts:144`.
- Fix: a palette `Actions` entry "Clear prompt history" (hint "this browser"), next to the theme entry in `CommandPalette.vue` `entries` (:41-46). It asks `window.confirm` and then calls `clearPromptHistory()`.
- Reword the README disclosure to name the palette action and to say that a sign-out clears the ring in every open tab.
- **WEB-7-P1, silent repeats.**
- Root cause: `lib/questions.ts:44-52` assigns the composed sentence to a ref. Two identical asks yield the same string, so `App.vue:107`'s live region never mutates and a screen reader stays silent.
- Fix: set the ref to `''`, then set the sentence on `nextTick`.
- **WEB-7-P1, trust prompts.**
- Root cause: trust prompts (`kind:"trust"`) are filtered out before `approvals.onNew` (`stores/approvals.ts:124-125`), so they are never announced.
- Fix: `useQuestionAnnouncements()` also `watch`es the contextSources store's open trust prompt by `askId`, using the `pendingTrust` that C-SEC-4.b introduces. It announces "`<session>` — project trust prompt for `<root>` is waiting in the Context panel".
- It only reads the store; `stores/contextSources.ts` is not edited.
### files
['candy-code-web/README.md', 'candy-code-web/src-web/components/Composer.vue', 'candy-code-web/src-web/components/__tests__/composerFilesImages.spec.ts', 'candy-code-web/src-web/components/__tests__/composerHistory.spec.ts', 'candy-code-web/src-web/components/panels/CommandPalette.vue', 'candy-code-web/src-web/components/panels/__tests__/paletteClearHistory.spec.ts', 'candy-code-web/src-web/lib/__tests__/questions.spec.ts', 'candy-code-web/src-web/lib/questions.ts', 'candy-code-web/src-web/stores/promptHistory.ts']
### files.create
`candy-code-web/src-web/components/panels/__tests__/paletteClearHistory.spec.ts`
### files.modify
`candy-code-web/src-web/components/Composer.vue` (`mentionPartial` watcher + debounce timer, history-cleared subscription, unmount cleanup), `candy-code-web/src-web/stores/promptHistory.ts` (`onPromptHistoryCleared`, `clearPromptHistory` in-tab notify, docblocks), `candy-code-web/src-web/components/panels/CommandPalette.vue` (`entries` Actions row), `candy-code-web/src-web/lib/questions.ts` (`useQuestionAnnouncements`), `candy-code-web/README.md` (prompt-history disclosure, :143-147)
### tests

- Modify `candy-code-web/src-web/components/__tests__/composerFilesImages.spec.ts`. Add a fake-timer `describe`:
- five partials typed inside 120 ms send exactly 2 searches: the first and the last;
- the popup shows the last answer;
- a stale answer that arrives after a newer partial is dropped (latch);
- a `null` partial cancels the trailing search.
- Modify `candy-code-web/src-web/components/__tests__/composerHistory.spec.ts`:
- a `StorageEvent` (`key: 'candy-code-web:promptHistory'`, `newValue: null`) empties the mounted ring, so the next send persists only `[new]` and Ctrl+Up recalls only it;
- a same-tab `clearPromptHistory()` does the same;
- an unrelated key's event leaves the ring untouched.
- Create `candy-code-web/src-web/components/panels/__tests__/paletteClearHistory.spec.ts`:
- the palette lists "Clear prompt history";
- confirm → key removed;
- cancel → key kept.
- Modify `candy-code-web/src-web/lib/__tests__/questions.spec.ts`:
- two identical consecutive asks produce two DOM text mutations of `question-announcement`;
- a trust prompt adopted by the store is announced once.
### docs/guards
`candy-code-web/README.md` (soft; no test pins it); `npm run typecheck`, `npm run lint`, vitest; dist rebuilt at weld
### risks

- Composer.vue is also edited by C-WEB-4.a, C-WEB-4.e, C-CTX-2.d and steps from agt, mcp and tui. `promptHistory.ts` is edited by C-WEB-4.a (`searchPromptHistory`), and `CommandPalette.vue` by C-WEB-4.b (keymap binding at :143).
- If C-SEC-4.b names its field something other than `pendingTrust`, read that name. The trust half is a read-only `watch`.
