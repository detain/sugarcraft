# C-SEC-5.c — DONE (dormant-wire, effort S)

Commit: 13818260ebfc97ed6fa9a080360faa135f4023a8 on cc/C-SEC-5.c (never pushed) — detain %ae/%ce, zero trailers.
Files: NEW candy-code/src/Tui/TrustDialog.php, NEW candy-code/tests/Tui/TrustDialogTest.php; en.php +13 tui.trust.* keys only (WELDER CORRECTION at weld: `git show --numstat` = +13 and the diff adds exactly 13 keys; the earlier +15 was wrong) (inserted before the end-tui/palette marker; census-safe: every key quoted-literal in src/Tui, TuiLangTest+LangParityTest green).

Shape: final immutable model over list<TrustTarget> + root (+settingsKeyCount for the Settings row); y/n answer the highlighted row in census order, a grants every remaining (skips keep their answer), Esc dismisses and voids all answers; choices(): list<array{target,granted}> is the whole output — zero writes, zero I/O. view(int $cols, int $rows, Theme): boxed string, content-fit width clamped [14..min(cols,72)], every line Width::truncate'd + padded; cols<6 falls back to plain copy lines (invariant outranks chrome). Key hints row + frozen-roster line 'Takes effect next launch' pinned.

Gates (plain pipe, env -u LINES -u COLUMNS, cwd candy-code):
- --filter TrustDialogTest: 12T/15706A OK (heavy A = width sweep, by design). --filter TrustDialog also drags 1 foreign row (name-match, green): BinCandyCodeWiringTest dataset "Tui/TrustDialog.php" (WELDER CORRECTION per REVIEW-1 NIT — the file-scan guard auto-adopting the new src file; not AccessorDeprecationTest).
- --filter Tui: 1627T/74072A, 1F = SettingsEditorTest::testSearchingTypesFiltersAcrossCategoriesAndEscClearsBeforeClosing — BASE-PROVEN foreign (git stash -u, same red at base; 'git' category leak, not this lane).
- five-guard: 133T, only-reds = staleness pair (25,537 pinned vs 26,191 live = prior-era drift +12 from this lane).
- TuiLangTest|LangParityTest: 16T green. No suite-figure.json/durations/dist touched.
- 2x "PHPUnit Deprecations" = pre-existing AccessorDeprecationTest private-provider warnings fired at suite assembly under ANY filter (reproduced on foreign TuiLangTest run).

DUR: candy-code/tests/Tui/TrustDialogTest.php row owed to scripts/parallel-tests-durations.tsv by the gate.

Seams/notes:
1. Brief path drift: TrustTarget/ProjectTrustCensus live at src/Config/Trust/ (not src/Security/); shapes read from there.
2. Concatenated Lang keys are census-invisible (TuiLangTest walks quoted T_CONSTANT_ENCAPSED) — keys spelled as exhaustive match literals; 5.a no-drift idiom.
3. No KeyBindingRegistry entry (modal-local per brief); drift guard untouched.
4. 5.d wiring: open(root, ProjectTrustCensus::pending(...) result, settingsKeyCount); consume choices() at isComplete(), treat isDismissed() as no-op.
