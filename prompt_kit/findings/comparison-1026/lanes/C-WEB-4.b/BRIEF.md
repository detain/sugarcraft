### C-WEB-4.b — `?` help overlay + web keymap
- item: C-WEB-4 (sources: GAP-UI-28, GAP-UI-25)
- kind: gap · M
- approach: Client-only.
  - **Help overlay.** `?` on an empty composer, or outside any input, opens `HelpOverlay`. It lists the web shortcuts plus `command.list` rows with `runsIn` markers. The global capture-phase handler lives in `App.vue`, so `Composer.vue` stays untouched.
  - **Keymap module.** `lib/keymap.ts` holds the rebindable web shortcuts: palette (today hard-coded Ctrl/⌘+K at `CommandPalette.vue:143`), help, and the tab chord (folding `lib/tabChord.ts`'s stored chord in as one entry, with the old storage key migrated).
  - **Storage and editor.** The keymap persists per viewer through `stores/storage.ts`. A small editor sits in SettingsView's client section.
- files.create: `candy-code-web/src-web/components/HelpOverlay.vue`, `candy-code-web/src-web/lib/keymap.ts`, `candy-code-web/src-web/components/__tests__/helpOverlay.spec.ts`, `candy-code-web/src-web/lib/__tests__/keymap.spec.ts`
- files.modify: `candy-code-web/src-web/App.vue` (mount + global `?`), `candy-code-web/src-web/components/panels/CommandPalette.vue` (binding from keymap), `candy-code-web/src-web/lib/tabChord.ts` (read/write through keymap), `candy-code-web/src-web/components/settings/SettingsView.vue` (keymap editor), `candy-code-web/src-web/lib/__tests__/tabChord.spec.ts` (migration)
- tests:
  - Create `candy-code-web/src-web/components/__tests__/helpOverlay.spec.ts`. It pins: `?` on an empty composer opens it; `?` mid-draft types normally; it lists command rows.
  - Create `candy-code-web/src-web/lib/__tests__/keymap.spec.ts`. It pins: defaults; rebinding persists; the old tab-chord key migrates; conflicts are refused.
- docs/guards: `candy-code-web/README.md` (soft); vitest; dist rebuild.
- serialized: [hot:candy-code-web/src-web/App.vue] [hot:candy-code-web/src-web/components/settings/SettingsView.vue]
- deps: none
- phase: normal
- ruling: none
- risks: P-SESSION-TABS / P-CTX-BREAKDOWN may also mount chrome in App.vue. Serialize on the hot tag.

