# C-WEB-4.a: Composer: drag-drop images, Ctrl+G history search, paste chips
wave 15 · area web · src design-web.md · item C-WEB-4 (sources: GAP-UI-12, GAP-UI-09, GAP-UI-11)
kind: gap · M · phase normal · deps none · serialized [hot:candy-code-web/src-web/components/Composer.vue]
ruling: none
risks: C-WEB-7 (another lane: `fs.search` debounce, history storage event) edits Composer.vue and promptHistory.ts. Serialize the two.
## approach
Client-only, three features:
- **Drag-drop.** `@dragover.prevent`/`@drop` on the composer feeds `readPastedImages(event.dataTransfer)`. A DataTransfer already satisfies `ClipboardFiles` (`lib/paste.ts`). Drops use the same `MAX_PASTED_IMAGES` strip and busy/command refusals.
- **History search.** Ctrl+G (browsers reserve Ctrl+R) opens a `HistorySearch` overlay. It filters the persisted ring by containment, newest first, with the TUI HistorySearch semantics: Enter fills the draft, Esc restores it.
- **Paste chips.** A text paste over the TUI's chip threshold becomes a `[Pasted #n +X lines]` placeholder backed by a chip map. Submit expands it through `expandPasteChips()`. Deleting the placeholder drops the chip. History stores the expanded text.
## files.create
`candy-code-web/src-web/components/HistorySearch.vue`, `candy-code-web/src-web/components/__tests__/composerDropAndChips.spec.ts`
## files.modify
`candy-code-web/src-web/components/Composer.vue` (drop target, Ctrl+G, paste branch, submit expansion, placeholder hint), `candy-code-web/src-web/lib/paste.ts` (`pasteChip`, `expandPasteChips`), `candy-code-web/src-web/stores/promptHistory.ts` (`searchPromptHistory`)
## tests

- Create `candy-code-web/src-web/components/__tests__/composerDropAndChips.spec.ts`. It pins:
- a drop adds image chips;
- Ctrl+G filters, Enter fills, Esc restores;
- a long paste becomes a chip and the sent text is expanded.
- Modify `candy-code-web/src-web/lib/__tests__/paste.spec.ts` and `candy-code-web/src-web/stores/__tests__/promptHistory.spec.ts`.
## docs/guards
`candy-code-web/README.md` Composer bullet (soft); vitest; dist rebuild.
