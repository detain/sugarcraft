# C-WEB-3.b — Files panel + read-only file viewer — LANDED

Commit `75f8f4a9d` on `cc/C-WEB-3.b` (base `86f43fae2`), author/committer detain×1/×1, zero Co-Authored trailers, tree clean, NOT pushed. dist NOT built/committed (welder duty).

## Files
- create `src-web/components/panels/FilesPanel.vue` — search box over `fs.search` through `session.searchFiles()` (limit 50; empty query = project front; stale-answer token guard); row click opens the shared viewer.
- create `src-web/components/FileViewer.vue` — `files.read {path, offset, limit:32768}`; plain-text rows with line numbers in `<pre>` per line, NEVER v-html; `binary:true` → size notice only (load-more suppressed — the server flags a binary read truncated by the byte cap, so the pin covers that shape); `truncated` → load-more at `offset + UTF-8 byteLength(content)` (TextEncoder — wire reads bytes, not chars), chunks appended and re-split so line numbers stay true across a mid-line boundary; refuses surface in-viewer; path change resets.
- modify `stores/agents/panels.ts` — PANELS + PANEL_LABELS gain `files` (between changes and context); `dormantRoster.spec.ts` does not exist yet in this tree (C-WEB-3.g) — nothing to remove; no spec pins PANELS length/order.
- modify `panels/SidePanels.vue` — Files tab (auto from PANELS) + `<FilesPanel v-else-if="active==='files' && sessionId">` (context-pattern gate; files belongs to the workspace but rides the session store accessor).
- modify `panels/ChangesPanel.vue` — per-row `view` button (`changed-view-<path>`) opens the same FileViewer.
- modify `candy-code-web/README.md` — ten→eleven ×2 + Files/Changes bullet prose (in-step, soft per brief).

## Wire shapes verified (generated.ts:593/:637 + FilesMethods.php::read) — both LIVE, no seam to report.

## Tests
- `npx vitest run --no-file-parallelism` filesPanel + changesPanel + panels + stores.spec → **Test Files 4 passed (4), Tests 39 passed (39)** (filesPanel.spec.ts 9 new; changesPanel +1).
- Gates: `npx vue-tsc --build --force` → 6 errors, all TS2554 in foreign `statusBar.spec.ts`×2 + `reducer.spec.ts`×4 — base-identical per brief. Plain `vue-tsc --noEmit` on the app config: exit 0, zero errors incl. new files.
- eslint on all 8 touched files: clean.

## NEW test files (gate needs durations/vitest roster? web lane — vitest discovers, none owed)
- `src-web/components/panels/__tests__/filesPanel.spec.ts`

## Notes
- Stored `panels.active='files'` on an older bundle → existing PANELS.includes fallback → 'agents' (brief risk row, handled by shipped code unchanged).
- Rejected: pass `data-testid` onto the FileViewer component tag — fallthrough would overwrite the root's `file-viewer` testid.

## Round-2 fixes (commit c0715ebc0, branch cc/C-WEB-3.b)

All five findings taken (4 MINOR + 1 NIT):

- **M1 (vacuous stale-answer guard)** — pinned: new filesPanel.spec test answers the FIRST
  `fs.search` frame out-of-order via `wire.socket().answer(..., sent[0]!.id)` after a second
  keystroke; stale list must never land. Mutation (guard neutered at FilesPanel.vue:34) →
  exactly that test red.
- **M2 (unpinned sessionId mount gate)** — pinned: mount SidePanels with `sessionId: null`,
  open the files tab → no `[data-testid="files-panel"]`, zero `fs.search` frames sent.
  Mutation (`&& sessionId` dropped at SidePanels.vue:103) → exactly that test red.
- **M3 (no stale-path discipline in FileViewer)** — fixed + pinned: FileViewer gains the same
  newest-wins `seq` token (guards at :50/:58, `loading` release at :61); two-click test opens
  a.ts then b.ts, answers the a.ts frame late → header stays src/b.ts, A-CONTENT never
  appears; then answering b.ts renders. Mutation (FileViewer.vue:50 neutered) → red.
- **M4 (load-more advance arithmetic)** — fixed: `shownBytes = offset + (truncated ?
  READ_LIMIT_BYTES : 0)` raw-byte advance (a truncated FilesMethods read consumed exactly the
  limit; sanitized content length under-reads invalid UTF-8). TextEncoder/lastChunkBytes
  removed. Pin: size-40_000 first chunk `'line1\nli'` truncated → hint 'showing 32768 of
  40000 bytes', load-more requests `{offset:32_768, limit:32_768}`; old content-length
  arithmetic (mutation reproduced it) → red.
- **NIT** — second-chunk fixture offset flipped 7 → 8 per the FilesMethods.php:106 echo
  contract (folded into the rewritten load-more test above).

FilesPanel.vue/SidePanels.vue guards needed no code change (pins were the gap). Gate:
`npx vitest run --no-file-parallelism` filesPanel+changesPanel+panels+stores.spec = 4 files /
42 tests (39 + 3 new) green. `vue-tsc --build` = the 6 foreign TS2554 only (two transient
TS2532s from my own `[0].id` indexing cured with `!` after the length pins). No dist commit.
Author/committer detain verified (1+1 separate greps), zero Co-Authored trailers.
