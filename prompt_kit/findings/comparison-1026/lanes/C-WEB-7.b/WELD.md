# C-WEB-7.b — WELD (2026-10-28, master base 4b771634d)

**Pick:** `f9d81da62` → `c69f190b1` on master (clean onto `f19c5cdb1`; brief
hash matched disk — no erratum). Single-commit lane; **zero conflicts**.
Patch-id IDENTICAL both sides (`1daca999d67f42767d7209f23d17d3e3a3f00ea3`).
9 files +469/−20, all under `candy-code-web/` — disjoint from lane-1
(C-TUI-7.b) candy-code paths. Author/committer `detain@interserver.net`
(`%ae`/`%ce` separate greps, 1 each), zero trailers.

**Weld history (round 1 → round 2):** the prior weld run SKIP-CONFLICTed at
old tip `06364f419` (`SKIP-CONFLICT.md` — `pendingTrust` vs `pendingTrusts`
collision with C-SEC-5.b's `f90523394`). The fixer rebased/adapted onto
`4b771634d` (`FIX-1.md`): `pendingTrusts[]` watch + `announcedTrustAskIds`
Set latch, re-announce sentence byte-unchanged; `REVIEW-2.md` CLEAN 0C/0M/0min.
This weld is that round-2 branch.

**Regeneration (weld duty per plan §2 — dist ride, precedent `d93e9b0b6`):**
`npm run build` at `c69f190b1` swapped the hashed dist bundles
(`index-CwJ0pGaW.js`/`index-Cwos0REN.css` → `index-mLZ5ITt6.js`/`index-DeW6LKiW.css`)
+ `index.html` + `manifest.json`; `build-info.json` byte-identical
(deterministic build). Committed separately as `6cfcbb643` `weld: C-WEB-7.b
regenerate`. No protocol leg: the pick touches zero `src/Protocol/` /
schema / `generated.ts` paths → `gen-protocol-schema` + `npm run gen:protocol`
skipped by scope. PHP-side doc generators no-op (all "up to date" rc0 as of
the C-TUI-7.b weld on this tree — `candy-code-web/README.md` is not a
gen-command/tool/settings-doc surface).

**Merged-tree gates (cwd candy-code-web, plain pipe):**
- `npx vitest run --no-file-parallelism` on the 4 touched specs
  (`composerFilesImages` / `composerHistory` / `paletteClearHistory` /
  `questions`) → `Test Files 4 passed (4)` / `Tests 28 passed (28)` —
  reviewer-exact (REVIEW-2 figure reproduced on master, not just the branch).
- `npm run typecheck` (vue-tsc) → exactly **19 errors**, breakdown
  `contextPanel.spec.ts` 8, `src-web/stores/contextSources.ts` 4,
  `reducer.spec.ts` 4, `statusBar.spec.ts` 2, `contextTrustRouting.spec.ts` 1
  = master's standing set; the lane adds **zero**.

**Durations:** NO phpunit row owed — zero new `*Test.php` files.
`paletteClearHistory.spec.ts` is vitest-side (no durations.tsv coupling; the
vitest roster leg is P-TABS.h carry, not this weld's).

**Carries (recorded, NOT fixed here):**
- **NIT2 (brief):** `src-web/stores/promptHistory.ts` hardcodes its
  `STORAGE_KEY` literal instead of importing the `storage.ts` PREFIX — closeout
  sweep candidate (cross-tab clear works today; drift hazard if the prefix moves).
- **REVIEW-2 cosmetic NIT (report-only):** `lib/questions.ts` trust-test
  docblock says "the open trust prompt" (singular, now plural-capable after the
  rebase) and the second-door two-row leg is unpinned.
- Round-1 skip record stays in `SKIP-CONFLICT.md` as history.

**Absorption proof:** `git cherry master cc/C-WEB-7.b` → `-` (see welder
session record); re-apply content check diff-empty. Then
`git worktree remove ../cc-cmp-C-WEB-7.b` + `git branch -D cc/C-WEB-7.b`.

**Chain:** `f19c5cdb1` (C-TUI-7.b weld) → `c69f190b1` (pick) →
`6cfcbb643` (regen) → `<this commit>` (`weld: C-WEB-7.b`: §4 mark +
`lanes/C-WEB-7.b/` review files + run-state welded 114).
