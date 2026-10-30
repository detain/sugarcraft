### C-CTX-4.a — Live date and knowledge-cutoff note
- item: C-CTX-4 (sources: GAP-PRM-14)
- kind: gap · effort S
- approach:
  - The `<env>` date is frozen at session start, and there is no cutoff note.
  - `TurnContextBlock` gains `withCurrentDate()`. It renders "Current date: YYYY-MM-DD" only when the date differs from the `<env>` date, so the prefix never moves, and `Runtime::turnContext()` (:5712) passes today's date.
  - `EnvironmentBlock::capture()` adds "Knowledge cutoff: …" from a per-family table in `ModelMetadata`, only when the family is known. This line is part of the static per-session prefix.
- files.create: `candy-code/tests/Context/TurnContextDateTest.php`
- files.modify: `candy-code/src/Context/TurnContextBlock.php`, `candy-code/src/Runtime.php` (`turnContext()`), `candy-code/src/Context/EnvironmentBlock.php` (`capture()`), `candy-code/src/Providers/ModelMetadata.php` (cutoff table)
- tests: TurnContextDateTest (same day → no row; next day → one row); modify `candy-code/tests/Context/EnvironmentBlockTest.php` (cutoff for a known family, absent for an unknown one); modify `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php` (a date change does not move the prefix)
- docs/guards: `candy-code/docs/PROMPT_ENGINEERING.md`
- serialized: [runtime:turnContext] [docs:PROMPT_ENGINEERING.md] [hot:candy-code/src/Context/EnvironmentBlock.php]
- deps: none
- phase: normal
- ruling: none
- risks: a hand-kept cutoff table goes stale. Render nothing when unsure.

