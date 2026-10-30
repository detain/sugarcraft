# W29 wave-loop FIX-f2 — VERDICT: DONE (survivor-roster row is the cure, NOT palette-visibility)

Fixer: cc-cmp-fixer, master-direct, base tip `c013d930d` (my own W29 MAJOR-1/MINOR-1 cure commit). Target file byte-clean at start (`git status --porcelain -- candy-code/tests/Chat/PaletteSupersetDispatchTest.php` = empty).

Origin: this finding arrived as a builder supplement, not from `WAVE-REVIEW-2.md` — that review is verdict CLEAN but its guard filters (`StderrEmitterCensus|ReadPathCensus`, `KeyBindingDrift|KeyBindingRegistry|ReadmeRosterDrift|CommandDocsDrift|DuplicatedTestHelperDrift`, the two family sets) never ran `PaletteSupersetDispatchTest`, so r2 could not have seen this red. Recorded for the lead: the class is a gap in the reviewer's filter roster.

## The red (LIVE before fix)

`git show master:candy-code/tests/Chat/PaletteSupersetDispatchTest.php | grep -c output-style` = **0**, while `candy-code/builtin-commands/1405-output-style.php` exists (landed by C-CTX-4.e `f7d312948`, an ancestor of master) with `paletteAction` addressed only in a comment.

Red proof (cwd=candy-code, plain pipe, env -u LINES -u COLUMNS): `--filter 'testThePaletteIsTheSupersetAndTheSurvivorsAreExactlyTheJudgedSet' tests/Chat/PaletteSupersetDispatchTest.php` → **Tests: 1, Assertions: 1, Failures: 1**. The live survivor census is `…2 status, 3 output-style, 4 pane, 5 copy…` against a roster lacking the key (i.e. shifted by one from index 3 onward) — exactly the key the supplement named, nothing else.

Path correction to the pointer: the spec lives at `candy-code/builtin-commands/1405-output-style.php` (not `src/Commands/builtin-commands/`).

## Judgement — which cure is correct

The arm's own law: the census is built from `CommandRegistry::all()` where `$spec->paletteAction === null && !$spec->isFileBased()`, and `assertSame` states "VIS-4 promoted every row with a meaningful bare form; **this set may only change with a per-row judgement recorded in the spec file**". `SLASH_ONLY_SURVIVORS` is a bidirectional roster of rows judged to stay WITHOUT a palette ACTION.

The spec file records precisely such a judgement (lines 15-17): "No paletteAction: the palette rows are for listings a mouse can browse (0350-effort.php precedent); a style switch names a file, which is a typed act. See OutputStyleHostCommand." So the DESIGN is already correct and deliberate; only the roster row was omitted at the weld. Therefore the cure is the roster row. Minting a `PaletteAction` would:

1. contradict that recorded per-row judgement (the arm exists to police exactly this);
2. require editing `PaletteAction.php`, which is NOT in C-CTX-4.e's declared files — the lane's own REPORT disclosed the omission as intentional, "precedented by 0350-effort.php";
3. be unnecessary for discoverability: since C-EXT-5.b every survivor still reaches the root palette as its `/<name>` PREFILL row, policed by `testTheRootPaletteListsEverySlashVisibleRow` (prefills ≥ count(SLASH_ONLY_SURVIVORS)).

Precedent family in the same roster — each landed after VIS-4's census and took a row rather than a minted action: `effort`/`fast` (0350 spec judgement), `copy` (C-TUI-3.a/1910), `insights` (C-SES-8.d/2215), `instructions` (C-CTX-3.a/2405), `commit` (C-AGT-6.a/2850), `pr` (C-AGT-6.d/2860). Plan §6 carries no ruling demanding a `/output-style` palette action.

## The fix

`candy-code/tests/Chat/PaletteSupersetDispatchTest.php` +10 lines: an attribution comment (names C-CTX-4.e/weld-18q, the spec-file judgement, the W29 wave-review finding-2 origin, why NOT a minted PaletteAction, and the C-EXT-5.b prefill coverage) plus the roster entry, inserted between `'status'` and `'pane'` to match registry order (0350-effort, 1340-status, 1400-rules — which HAS `ListRules` so it is not a survivor — 1405-output-style, 1500-pane, 1910-copy):

`'output-style' => 'bare /output-style lists the styles; selecting one names a file — the /effort sibling judgement',`

## Gates (all cwd=candy-code, plain pipe, env -u LINES -u COLUMNS)

- `tests/Chat/PaletteSupersetDispatchTest.php` → **OK (7 tests, 272 assertions)** (was 1F pre-fix).
- `--filter 'ReadmeRosterDriftTest|CommandDocsDriftTest|DocFigureProseDriftTest|DuplicatedTestHelperDriftTest|KeyBindingDriftTest' tests/` → **OK 360T/6428A** (2 pre-existing PHPUnit Deprecations, same shape as W29 r1).
- `PaletteSupersetDispatchTest` + `PaletteThemePickerTest` → **OK 12T/321A**.
- `PalettePrefillRowsTest` + `PaletteFullQueryMatchTest` + `PaletteNewSessionCommandTest` + `tests/Palette/PaletteActionTest` + `tests/Palette/PaletteCatalogTest` → **OK 26T/980A** (the prefill/visibility legs the judgement leans on).
- `--filter 'MenuBarLabelDerivationTest' tests/` → **11T/194A OK** — the sibling red disclosed beside this one at weld-19a is ALREADY GREEN on master (cured elsewhere); no action.
- Harness note: a broad `--filter 'Palette' tests/` TIMES OUT at 120s (it sweeps slow PTY tests) — run palette files individually.

Containment: no other file references `SLASH_ONLY_SURVIVORS` (grep exit 1); no survivor-count word-number claim exists in the class docblock (grep verified), so no prose was falsified. No new `*Test.php` ⇒ no `durations.tsv` row owed; `suite-figure.json` untouched (its staleness pair remains a §7 closeout duty, already recorded by WAVE-REVIEW-2.md).

## Commit

`1b5192f42` — W29 wave-review: finding 2 PaletteSupersetDispatchTest output-style survivor row (1 file, +10). Landed-blob proof: `git show 1b5192f42:candy-code/tests/Chat/PaletteSupersetDispatchTest.php | grep -c output-style` = **2** (was 0). Author/committer verified via separate %ae/%ce greps; zero Co-Authored/Signed-off trailers; caliber `no-hook` so no refresh run. This evidence file rides a rider commit (precedent: c9cee706c/51bdf5d13 `evid`). No push (owner law).
