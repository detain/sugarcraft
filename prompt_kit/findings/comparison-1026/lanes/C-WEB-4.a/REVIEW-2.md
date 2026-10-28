# C-WEB-4.a REVIEW-2 (round 2 — cure review)

Lane: cc/C-WEB-4.a @ bf6331eb03cf459ba0357230feeef76402bb31c3 (amended from 0aa82564b; ONE commit, parent 2a6cd3c1f byte-exact). Reviewer worktree /home/sites/cc-cmp-C-WEB-4.a, npm ci done, porcelain clean.

## VERDICT: CLEAN 0C/0M

r1's single MINOR is genuinely cured at root cause; every cure claim verified on disk and by re-run. Two queued NITs remain (below); neither blocks weld.

## 1. Phantom-first — PASS
- `git cat-file -t bf6331eb0` = commit; `master..HEAD` = 1; porcelain empty.
- `%ae` grep = 1, `%ce` grep = 1 (separate greps), commit body grep for co-authored|caliber = 0.
- `git diff --name-status 2a6cd3c1f bf6331eb0` = exactly the 8 declared files (HistorySearch.vue + composerDropAndChips.spec.ts created; Composer.vue, paste.ts, promptHistory.ts, paste.spec.ts, promptHistory.spec.ts, README.md modified). Nothing outside the brief's file set.
- `git show --name-only | grep dist/|generated.ts|schema.json|SERVER.md` rc=1 — generated/weld-only files ABSENT (regen-then-restore honored).

## 2. Cure tokens in committed blobs — PASS
- Composer.vue blob: `@drop.prevent` count 0; `@drop="onDrop"` count 1; `@dragover.prevent` kept 1.
- onDrop body: `if (!event.dataTransfer?.types?.includes("Files")) return;` is the FIRST statement, `event.preventDefault()` second, the only await after — exactly the r1 cure prescription. Docblock truth-flipped ("Only a Files drag is ours to claim… falls through unpreventended").
- spec blob: both-polarity leg "the component claims only Files drops: defaultPrevented is true for files, false for a text drag" present; text fall-through fixture now carries `types: ['text/plain']`, Files fixtures `types: ['Files']`; corrected title "(a text/plain drag is the browser's)" now matches shipped behavior.
- FIX-1 cites the legs at ":115-127"/":55"; committed blob has them at :73-83/:66 — report line-cite drift only, substance exact (red-before quote's :82:39 matches the committed line precisely).

## 3. Gates re-run (reviewer, serial, cwd candy-code-web, vitest 5.0.5)
- `vitest run --no-file-parallelism …composerDropAndChips.spec.ts` → "Test Files 1 passed (1) / Tests 12 passed (12)".
- Full touched set (7 files: composerDropAndChips, composerDictation, composerFilesImages, composerHistory, lib/paste, stores/promptHistory, paletteClearHistory): "Test Files 7 passed (7) / Tests 66 passed (66)", zero unhandled-rejection output — FIX-1's 66/66 claim reproduced EXACTLY.
- Typecheck: bare `vue-tsc` invocation forms are permission-denied to this reviewer; ran the repo script `npm run typecheck` (= `vue-tsc --build`). Output: only the standing baseline family — contextSources.ts(153/164), statusBar.spec(200/209), contextPanel.spec(×8), contextTrustRouting.spec(150), reducer.spec(×4), with the contextSources pair echoed twice by the composite build. ZERO errors name any of the lane's 8 files; since the diff proves those 5 error files are untouched by the lane, no error there can be lane-new. Builder's "0 new" claim: PASS by file-set proof.

## 4. Red-before (mutation) — re-reasoned, deterministic
Reverting the template to `@drop.prevent="onDrop"` makes Vue cancel EVERY drop before the handler runs; the textDrop leg (spec :82, `expect(…toBe(false)`) then reds with exactly FIX-1's "expected true to be false"; the fileDrop leg still passes, the trigger()-based legs never read defaultPrevented — one leg reds, mutation discriminates. (Reviewer is read-only here; r1 established re-reasoning as the accepted mode, and this mutation's semantics are Vue-modifier-deterministic.)

## 5. Semantics sweep — PASS
- Single preventDefault on the Files path; text path returns before cancelling → native text-drag-into-textarea restored (the r1 defect removed at its root, not papered).
- Null dataTransfer → guard is falsy-safe, no cancel, no throw.
- Paste-chip law parity re-measured at tip: PASTE_CHIP_LINES=10 / PASTE_CHIP_BYTES=2048 (paste.ts :96/:99) == Chat::PASTE_COLLAPSE_LINES=10 / PASTE_COLLAPSE_BYTES=2048 (Chat.php:442/:444 at base); collapse predicate either-bound strict-> as r1 verified.
- Chip prune-on-delete and unknown-chip-survives legs intact (spec :190, :201), watch-loop carry verified.

## 6. NITs (queued, non-blocking — carried forward)
1. r1-NIT1 NOT fixed: no spec leg for the empty-seed Esc edge (draft "" → Ctrl+G → Esc). Behavior is safe by construction (openHistorySearch seeds `searchSeed = props.modelValue`, Esc emits the same-value restore; a "" draft is unchanged), and the non-empty seed is pinned at :111/:123 — but the degenerate pin r1 proposed is still worth 2 lines at closeout. FIX-1 did not claim it; recorded as expectation-management in this round's brief only.
2. FIX-1/REPORT line-cites for the new spec legs drifted (:115-127/:55 vs committed :73-83/:66). Cosmetic; the red-before trace :82:39 is byte-true.

## 7. Foreign / §1.8 / project laws
- No suite-figure/durations/generated-file touches; no proc_open/fork sites; no PHP surface → the staleness-pair and MouseWiringTest ctxmeter families are not exercised by any filter of this lane (web-only diff, re-derived by file set — not attributable to, nor affected by, this lane).
- E646: no timers added (HistorySearch.vue remains setTimeout-free; mentionTimer pre-existing untouched).
- §6.3-f web-i18n scope: plain-English client strings conform (chrome/UI, no server lang keys touched).
- dist law: lane never commits dist/ (absent from diff); weld rebuilds per §2.

Lane is ready for the weld pick.

VERDICT: CLEAN 0C/0M
