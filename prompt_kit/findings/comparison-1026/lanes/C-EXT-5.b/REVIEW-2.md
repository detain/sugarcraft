# C-EXT-5.b — REVIEW-2 (round 2, fresh reviewer)

VERDICT: CLEAN

Lane: cc/C-EXT-5.b @ 7237dafc0 (fix) atop 9d0750cd8 (build), base e306bf4fc. Worktree clean. Author detain (%ae/%ce), zero Co-Authored.

## Fix commit scope (git show 7237dafc0)
- Single file, candy-code/src/Chat.php, 21+/6−; every +/- line is docblock comment (mechanically verified: no non-`*` changed line). No behavior, no test, no other file.
- Cumulative lane diff = 9 files +556/−19 = REVIEW-1's 9d0750cd8 (+535/−13) + this docblock exactly. No scope creep.

## Docblock truth (read against the code)
- "leaves the draft alone — the PREFILL half … is the standing exception … filling a blank draft with the bare command (or keeping a non-blank one … with a notice)": TRUE — Chat.php:17729 applyPalettePrefill (blank → `cmd + ' '`; non-blank → palette.prefill_draft_kept notice, draft kept).
- "Mid-turn an ACTION label is refused exactly as Enter on that palette row is": TRUE — runPaletteAction's inFlight match (:10933-10947) and runSelectedPaletteActionWhileInFlight (:17838-17862) carry identical arms (Exit/Settings/MediaForm/deck-NewSession/CloseTab/default-refuse).
- "A PREFILL label is the one place the two mid-turn routes diverge … this public arm allows it … the interactive arm has no prefill case and blanket-refuses": TRUE — prefill arm sits before the inFlight gate (:10917-10922); the Enter mid-turn match has no palettePrefillEntry call (null → default → refuseInFlightAction), matching its own "BLANKET" docblock. Seam (a) owner-ruling-pending wording is honest.
- "its only caller, App::runRegistryCommand() … never carries a prefill label": TRUE — sole production call site src/App/App.php:4811 (grep of src/ + bin/); it passes $spec->label() (action/prose labels), prefill labels being the '/name' shape gated at :17698. Idle route claim (Enter/click → runSelectedPaletteAction → runRootPaletteAction prefill arm) verified at :7255/:17803/:18161-18173.
- Sentence omits the `new` slashVisible exception (:4809), but the substantive claim (action labels only) survives it; NIT, non-blocking.

## Gates (reviewer-run, cwd=candy-code, plain pipe, env -u LINES -u COLUMNS)
- php -l src/Chat.php: clean.
- --filter 'Palette': 229T / 4175A, 0F (2 pre-existing deprecations) — builder and REVIEW-1 figures reproduced exactly.

## Weld-carry notes (unchanged, re-confirmed)
- durations.tsv row owed: tests/Chat/PalettePrefillRowsTest.php (6T counted on disk) — file still absent from the branch diff set (lane correctly never touched it).
- suite-figure.json + README re-pin (inherited staleness pair) unchanged; seams (b)/(c) still owner-visible; MINOR-2 (r1) out-of-scope seam deliberately untouched; NIT COMMANDS.md:441 optional.

MINOR-1 resolved truthfully; zero critical/major/step-local-minor findings. Weldable.
