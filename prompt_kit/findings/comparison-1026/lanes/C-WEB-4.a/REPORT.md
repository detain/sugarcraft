# C-WEB-4.a REPORT — DONE

Verdict: shipped, one commit `0aa82564b` on `cc/C-WEB-4.a` (base 2a6cd3c1f), tree clean, never pushed.

## Files (8)
- create `src-web/components/HistorySearch.vue` — Ctrl+G overlay: ring filtered via `searchPromptHistory` newest-first, ↑/↓ wrap, repeat Ctrl+G cycles next match, Enter fills (never sends), no-match Enter consumes only; CommandPalette dialog/backdrop idiom.
- create `src-web/components/__tests__/composerDropAndChips.spec.ts` — 11 tests: drop→image chips + MAX strip + non-image fall-through; Ctrl+G filter/Enter-fill/Esc-restore + empty-ring no-open; chip paste/expand-at-submit/prune-on-delete/two-chip numbering.
- modify `Composer.vue` — drop target (`@dragover.prevent`/`@drop` over `readPastedImages(event.dataTransfer)`, shared `addImages` strip with paste, refusals ride existing `blockedReason`); paste text-collapse branch (`getData` duck-check keeps image-only fixtures falling through); `textChips` map pruned by the modelValue watch; submit sends `expandPasteChips(text, textChips)` and rings the expanded text; Ctrl+G arm; placeholder hint updated.
- modify `lib/paste.ts` — `PASTE_CHIP_LINES`/`PASTE_CHIP_BYTES` (mirror Chat::PASTE_COLLAPSE_*), `pasteNeedsCollapse`, `pasteChip` → `[Pasted #n +X lines]`, `expandPasteChips` (unknown chips literal, TUI law).
- modify `stores/promptHistory.ts` — `searchPromptHistory` (case-insensitive containment, order kept, empty query = ring).
- modify `paste.spec.ts` (+3 tests), `promptHistory.spec.ts` (+3), `README.md` Composer bullet.

## Gates
- vitest serial touched set (7 files incl. composer neighbors + paletteClearHistory): 65/65 passed, 0 unhandled (fixed one found: getData-on-missing-fixture → duck-check).
  - Exact invocation (round 1, cwd `candy-code-web`): `env -u LINES -u COLUMNS npx --no-install vitest run src-web/components/__tests__/composerDropAndChips.spec.ts src-web/components/__tests__/composerDictation.spec.ts src-web/components/__tests__/composerFilesImages.spec.ts src-web/components/__tests__/composerHistory.spec.ts src-web/lib/__tests__/paste.spec.ts src-web/stores/__tests__/promptHistory.spec.ts src-web/components/panels/__tests__/paletteClearHistory.spec.ts --no-file-parallelism` → 7 files / 65 tests. The reviewer's "87/7-files" was a different 7-file combination; this lane's authoritative touched set measures 65 (round 1) / 66 (round 2, + the defaultPrevented leg).
  - Round 2 (review-1 fix, drop types-guard): same invocation → 7 files / 66 tests, 0 unhandled.
- vue-tsc --noEmit: 0 errors (baseline ≤19, no new).
- Non-vacuity: new specs against base sources = 14 failed / 20 passed (fails list recorded: drop/Ctrl+G/chip tests red on base via missing handlers + "X is not a function"); tip restored md5-verified, re-run 65 green.

## Deviations
- i18n: web has no Lang machinery (Composer strings are plain English) — matched idiom, no new keys.
- Esc-restore emits the seed verbatim (TUI rule) even though the overlay never wrote the draft — makes the pin real.
- No protocol surfaces touched; no generated files, dist, forbidden files in the commit.
