# C-WEB-3.a — Memory History & Undo — CLOSED

- Commit: 0077bb9b0 on cc/C-WEB-3.a (base 86f43fae2), tree clean. Author/committer detain verified separately, zero Co-Authored trailers.
- Files: `candy-code-web/src-web/stores/agents/memory.ts` (+log/undo/revisions/historyLoading, typed off `MethodResult`), `src-web/components/panels/MemoryPanel.vue` (History tab, plain-text span rows, confirm-gated undo behind write scope, notes-list reload + history re-log after undo; loads on tab open, not mount — per §risk), README side-panel bullet, NEW `src-web/components/panels/__tests__/memoryHistory.spec.ts`.
- Server surface: `memory.log`/`memory.undo` already typed in protocol/generated.ts (read/write scopes; undo is IDEMPOTENT_METHODS) — no schema edits, no protocol regen.
- Tests: vitest --no-file-parallelism memoryHistory + stores.spec + panels.spec = 3 files / 23 tests passed. Pin 2 destructures the auto-attached `idempotencyKey` (house pattern from generatePanel.spec.ts) and pins its shape.
- vue-tsc --build: exactly the 6 TS2554 foreign pre-existing errors in statusBar/reducer specs (the set the brief names) — zero errors in lane files; not re-run at base (set matches the described fingerprint).
- Security: history rows render via plain interpolation only, no v-html.
- Seam for weld: base has NO `src-web/__tests__/dormantRoster.spec.ts` (C-WEB-3.g unlanded). If that lane lands memory.log/memory.undo in the dormant set, this step's callers remove those rows (§1.8).
- DUR note: no new phpunit test files (web lane). dist not rebuilt/committed (welder duty). No suite-figure/durations touches.
- Evidence: /tmp/opencode is transient; this file is the record (main-tree lanes dir).

## Round-2 fix (2026-10-27)

Finding: [MINOR step-local] MemoryPanel.vue:55 confirm text overstated blast radius
(memory.undo restores the HOME store only; schema generated.ts:797-799, store docblock memory.ts:105).

Fix: commit bf24c197d on cc/C-WEB-3.a — one line, "Every note is replaced by that
commit's state." -> "The home store's notes are replaced by that commit's state."
Reviewer's suggested wording, applied verbatim.

Spec check: memoryHistory.spec.ts:65 pins only confirmSpy call count
(toHaveBeenCalledTimes(1)), not the message text — no pin flip needed (grep for
"Every note" in src-web tests: zero hits outside the component line itself).

Gate: npx vitest run --no-file-parallelism memoryHistory.spec.ts -> 1 file/3 tests
green; sibling trio (memoryHistory+panels+stores) -> 3 files/23 tests green, prior
shape matched. phpunit N/A (no PHP touched). Author law verified: %ae and %ce
separately = detain@interserver.net, zero Co-Authored trailers. Tree clean; not pushed.
