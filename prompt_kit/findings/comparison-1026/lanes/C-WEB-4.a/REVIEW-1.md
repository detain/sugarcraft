# C-WEB-4.a REVIEW-1 (round 1)

Lane: cc/C-WEB-4.a @ 0aa82564b89babfcbe86b53e30b7423c21701808 (single commit on base 2a6cd3c1f; master moved to 6875a1d90 with TUI-2.a/b sugar-lib welds only — zero path overlap with candy-code-web, weld pick is clean).

## Traceability / scope
- Commit = exactly the 8 declared files (BRIEF files.create/modify + the two modified specs + README bullet). No stray files. dist/, docs/protocol/schema.json, src-web/api/generated.ts, SERVER.md ABSENT (git show --name-only grep, rc=1). porcelain clean, %ae/%ce detain@interserver.net (author shown in git show), one commit master..HEAD — lead verification reproduced.
- [hot:Composer.vue] honored within the commit; base contains no lane-foreign WIP in the file.

## Completeness
- No stubs/TODOs. All claimed functions exist in committed blobs: paste.ts pasteNeedsCollapse/pasteChip/expandPasteChips/PASTE_CHIP_* (:102/:107/:116), promptHistory.ts searchPromptHistory (:52), HistorySearch.vue 163L, Composer.vue onPaste/onDrop/insertAtCaret/openHistorySearch/Ctrl+G arm (:198/:225/:440-452), template drop target (:519-520).
- Server parity law CHECKED: Chat.php:442 PASTE_COLLAPSE_LINES=10 and :444 PASTE_COLLAPSE_BYTES=2048 == web PASTE_CHIP_LINES=10/PASTE_CHIP_BYTES=2048; collapse predicate mirrors Chat::pasteNeedsCollapse (:16196-16199) — either bound, strict >, lines = substr_count+1 == split('\n').length, TextEncoder byte count == strlen for UTF-8. Unknown-chip-survives law mirrors Chat::expandPasteChips (:16177-16187). Empty-ring gate mirrors Chat::buildHistorySearch (:18240-18248, null on []). Chip SHAPE ([Pasted #n +X lines] vs server `[Pasted text #N +L lines]`) is the surface's own by the brief's explicit words and the paste.ts comment — not a parity violation.

## Production reachability
- Ctrl+G: composer-input textarea keydown → onKeydown arm → openHistorySearch → searchOpen → <HistorySearch v-if> — spec presses the REAL keydown path (press() triggers on [data-testid="composer-input"] with ctrlKey), overlay/Enter/Esc all exercised through the component, not direct function calls. Wired.
- Drop: @dragover.prevent/@drop on composer-root → readPastedImages(event.dataTransfer) — same reader, same MAX_PASTED_IMAGES strip (4, per-call cap + addImages total gate), same typeless-notice announcement; busy/command refusals ride the unchanged blockedReason send path. Rejection paths NOT weakened: drop-vs-paste parity is structural (identical code), spec lines 34-53 pin count-strip; there is no byte-size gate web-side on paste either (server refuses at send, pre-existing).

## Gates re-run by reviewer (lane worktree)
- vitest serial: 7 Composer-related spec files (composerDropAndChips, composerFilesImages, composerHistory, composerDictation, components, paste, promptHistory) → "Test Files 7 passed (7) / Tests 87 passed (87)", zero unhandled-rejection blocks. Builder's "65/65" was a narrower file set; the superset is green (report-accuracy nit below).
- vue-tsc --noEmit: exit 0, zero errors → zero NEW error names regardless of the 19-vs-0 baseline question. PASS.
- Red-before: re-reasoned, not re-run (read-only duty). composerDropAndChips (11T) needs HistorySearch.vue + drop/Ctrl+G/chip arms; paste.spec new describe (3T) needs the three new exports; promptHistory.spec new describe (3T) needs searchPromptHistory — every new spec is source-less at base, all would fail. Non-vacuity confirmed.
- Watch-prune mutation reasoning: deleting the prune loop reddens 'deleting the placeholder drops the chip' (spec :167-176: re-typed chip would expand to LONG payload instead of surviving literal). Load-bearing leg pinned.

## Conventions / laws
- Web TS surface: declare/PSR/final laws are PHP-side; style matches surrounding src-web (2/4-space per file family, comment-why headers). §6.3-f: HistorySearch labels/hint, placeholder text, README strings are plain client-side English — consistent with the entire existing web surface; the ruling governs SERVER lang keys, so conforming as written. i18n LAST-flip at C-TUI-6.h unaffected.
- E646/timers: HistorySearch.vue has zero setTimeout/setInterval; Composer's mentionTimer is pre-existing, untouched. No loop residue.
- §1.8: no doc rows/drift guards/figures moved (web README bullet is not server-guard-scanned; no suite-figure/durations touched). No proc_open sites. No weld-only files committed.

## FINDING 1 (MINOR, step-local) — native text-drop into the composer is silently swallowed
File: candy-code-web/src-web/components/Composer.vue:520 (`@drop.prevent="onDrop"`) with spec title composerDropAndChips.spec.ts:55 ("a text/plain drag is the browser's").
The BRIEF prescribed `@dragover.prevent`/`@drop` — preventDefault only on dragover. The lane added `.prevent` on drop, so EVERY drop over the composer (including the previously-native drag of selected text into the textarea) has its default action cancelled: onDrop finds no images, returns, and the text vanishes. The test passes because it only asserts "no image chips appear", never that the browser handles it — the title states the opposite of shipped behavior (jsdom hides it).
Cure: change `@drop.prevent="onDrop"` to `@drop="onDrop"`, and at the TOP of onDrop (before any await) do:
`if (!event.dataTransfer?.types?.includes('Files')) return; event.preventDefault();`
then keep the existing async body. Synchronous preventDefault is required (ineffective after await). Add a spec leg: a Files-type drop prevents default (defaultPrevented true) and a text/plain drop does not.

## NITs (queued, non-blocking)
1. No pin for the empty-seed Esc edge (draft "" → Ctrl+G → Esc). Behavior is correct by construction (same-value emit, watcher doesn't fire), but a 2-line spec leg would pin the verbatim-restore law's degenerate case.
2. Builder REPORT gate count "65/65 touched-7-files" vs reviewer re-run 87/87 over the 7 Composer-family specs — file-set labeling in the report, not the code; ask the lane to cite the exact vitest invocation it ran.

## Ruling on judged items
- getData duck-typing deviation: accepted (fixtures without getData fall through; production clipboardData always has it; refusals not weakened).
- README bullet: honest — every documented control (drop, Ctrl+G, chips, thresholds phrasing) exists on the wired path; Ctrl+↑/walk/Stop/Esc pre-existing claims verified untouched.
- Esc restores verbatim: overlay never writes the draft (spec :88 counts emissions==0 between open and Esc), Enter fills without submit — TUI laws pinned.
- Neighbor 1-unhandled cure: lane runs zero unhandled; nothing to cure here.
