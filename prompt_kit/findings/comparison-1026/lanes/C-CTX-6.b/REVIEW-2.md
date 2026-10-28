# C-CTX-6.b REVIEW-2 (723418d56, base 4b771634d) — CLEAN (0C/0M/0min)

PHANTOM-FIX: amended blob carries substr_count×4 (cat-file on HEAD blob); r1→r2 diff = tests-only +57 lines in the one declared test file; single commit on declared base, message kept, %ae/%ce separately detain, zero trailers; porcelain clean after gates.

r1-MINOR cured exactly as scoped: substr_count===1 leg on testTwoDocumentsOverTheBudgetWithholdTheOlderOne; NEW testTheWithholdNoticeIsComposedOncePerTurnAcrossRows (3 over-half rows → 2 withheld, both names, 'Documents withheld'×1 AND 're-attach a withheld'×1); NEW testTwoWithheldDocumentsOnOneRowShareOneSentence (3 docs 1 row → a.pdf+b.pdf withheld, c.pdf rides, one sentence).

Non-vacuity re-reasoned (edit/cp denied in reviewer map; composition read directly — single post-walk Lang::t block): per-row emission → across-rows leg doubles (2F-legs, names still present — only that test reddens; single-doc tests stay green). Per-doc emission → multi-withhold reddens (across-rows can't see this shape: one doc/row). Suppressed notice → substr_count 0≠1 red. Oldest-first selection flip → name legs invert red. Doubled budget → assertCount(1) red. Hand-checked arithmetic: overHalfBytes cost 15,728,648 > 15,728,632 remaining after newest → exactly 2-of-3 withheld; the multi test truly exercises the budget edge, not a coincidence.

Lane re-check: positive-count-only page refusal (strictly >100; /Pages lookahead-excluded; unknown passes); once-per-turn budget notice joined after capability notice; capability probe exactly-1 (documentQueries pin); PDF leg byte-untouched (additive-only diff); MEDIA.md table matches constants; en-only key legal (lang/ ships en.php alone).

Gates reproduced EXACT (cwd candy-code, plain pipe, -u LINES -u COLUMNS): DocumentAggregateBudget 11T/44A; pair FileMentions|DocumentAggregateBudget 28T/105A; trio +EngineBackend 316T/1908A; 2 pre-existing deprecations only. ChatHostLangCatalogue 1F = chat.effort.launch_unshaped — key in base en.php, asked only by src/Cli/LaunchContext.php (outside asked-set), zero hits in lane diff: foreign pre-existing, disclosed true.

Ship-ready. Weld owes (unchanged from r1): durations.tsv row for tests/Backend/DocumentAggregateBudgetTest.php + suite-figure re-pin (+14T/+50A revised delta).
