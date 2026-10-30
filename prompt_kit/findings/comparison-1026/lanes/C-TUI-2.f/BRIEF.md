# C-TUI-2.f — Web split + highlighted DiffView (W26, gap, effort S)

Design block (authoritative, from design-tui.md §C-TUI-2.f):
- item: C-TUI-2 (sources: GAP-UI-05, GAP-UI-04)
- approach: Client-only. `lib/diff.ts` pairs removed and added runs into side-by-side rows (the C-TUI-2.b rule). `DiffView.vue` adds a split mode: `auto` splits at >= 1100 px, and a client pref `diffStyle` lives in `stores/settings.ts`. Diff bodies are highlighted by file extension through `lib/highlight.ts`, which gains a `languageForPath` twin.
- files.create: none
- files.modify: candy-code-web/src-web/components/DiffView.vue; src-web/lib/diff.ts; src-web/lib/highlight.ts; src-web/stores/settings.ts
- tests: create src-web/lib/__tests__/diffSplit.spec.ts and src-web/components/__tests__/diffView.spec.ts; modify src-web/lib/__tests__/highlight.spec.ts
- docs/guards: vitest --no-file-parallelism; the dist/ rebuild at weld
- serialized: [hot:candy-code-web/src-web/components/DiffView.vue] [hot:candy-code-web/src-web/stores/settings.ts]
- deps: C-SEC-2 (its server-computed approval diff also renders through DiffView/lib/diff) — C-SEC-2.a + C-SEC-2.b are BOTH welded on master.
- risks: collides with C-SEC-2 on DiffView if they run in one wave.

Worktree: /home/sites/cc-cmp-C-TUI-2.f (branch cc/C-TUI-2.f, cut off master dc9eea92d).
candy-code/vendor AND candy-code-web/node_modules are ALREADY SEEDED (composer is broken
host-wide — never run composer; vitest 5.0.5 verified working).

Obligations: this is CLIENT-ONLY — no PHP, no protocol schema change, so NO trio/dist work in
lane (the dist rebuild is a WELD duty; never commit candy-code-web/dist). `diffStyle` is a
localStorage client pref exactly like the existing settings.ts prefs (it is NOT a server setting
key — if you think a settings-schema/registry row is needed, that is a STOP-REPORT question).
Honour the C-SEC-2 rule that lib/diff.ts already implements (read the landed code first and
REUSE its pairing, do not fork a second pairer). A `languageForPath` twin must not change
existing highlight behaviour for callers that pass an explicit language.
