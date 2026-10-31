# FIX-1 — C-TUI-5.b review fixes (round 1)

Base tip at start: 0cf00ad14 (picks a754697de + 0cf00ad14, untouched).

## MINOR-1 — DONE, commit af26dd781
`candy-code/tests/Support/ReadPathCensusTest.php:530-535` — the
`Config/ProviderKeyStore.php|file_get_contents` merge-read rationale said the
login read was "credited on the Cli/Subcommands.php row"; those login rows were
deleted from the roster when loginStoreKey delegated its persist half to
`ProviderKeyStore::store()`. Reworded to credit these rows themselves (the
read now lives in store()). Test-file prose only: no new row, no roster-count
movement — re-ran `--filter ReadPathCensusTest` after the edit: 19T/727A green
(exactly the reviewer's figure, so the count genuinely did not move).
Author %ae/%ce both detain, zero trailers, porcelain clean after commit.

## MINOR-2 — DONE, commit cf1c1e00f
`candy-code/src/Cli/Subcommands.php:3880-3886` (displayImportRules) — the
widening_note printed `count($report['widenings'])`, which counts every
widening-shaped verb INCLUDING the ones vetoed by a matching deny; the note
tells the user how many permissions the write WIDENS, so on a mixed import it
overstated the landed allow rows. Cure: `$landedAllows` loop over
`$report['rules']` counting `action === 'allow'` (vetoed allows are dropped
from `rules` by the veto, so this is the exact landed set). Guard condition
`rules !== [] && widenings !== []` unchanged; no new emitters/sprintfs, so
StderrEmitterCensus/BootstrapLaunchFormat rosters unmoved.

`candy-code/tests/Cli/ImportSubcommandTest.php:303` — new pin
`testTheWideningNoteCountsLandedAllowsNotTheVetoedOnes` (inserted in the
EXISTING file, no new test file, no durations.tsv row): doc has allow
`Bash(git:*)` + allow `Bash(npm:*)` + deny `Bash(npm:*)`, so exactly one allow
lands and one is vetoed. Asserts the table shows allow/deny/vetoed rows, the
note says "1 of these are allow rules that WIDEN", the old "2 of these are
allow rules" wording is ABSENT, "Wrote 2 new rule(s)" and the pre-existing
theme survive.
RED-BEFORE (pin run against unfixed src): Failed asserting output contains
"1 of these..." — output carried "2 of these are allow rules that WIDEN"
(1T/5A/1F). GREEN-AFTER: ImportSubcommandTest 15T/70A OK (+1T/+9A on the
reviewer's 14T/61A, expected from the new pin); ClaudeSettingsImportTest
19T/57A OK (untouched). Author %ae/%ce detain 1/1, zero trailers.

## NIT — DONE, commit 32f62fe2c
`candy-code/tests/Integration/BinCandyCodeDispatchTest.php` — the new `'import claude'`
roster row re-spaced (4 spaces → 3 before `=>`) to sit in the neighbours'
column ('completion bash' = 17+1 ⇒ col 18; 'import claude' = 15+3). php -l
clean. The 'import with no source'/'import unknown source' rows already match
their neighbours' style — left alone.

## FINAL GATE — per-class at tip 32f62fe2c (exact-class filters, per §1 methodology)
- BinCandyCodeDispatchTest 137T/870A/1F — the sole F is NAMED and is exactly the
  foreign §7 rider: testEveryVerbTheParserAcceptsIsOfferedByAllThreeCompletions
  "--worktree is accepted by the parser but offered by no completion" (BinCandyCodeDispatchTest.php:1893).
  Identical to the reviewer's 137T/870A/1F; the NIT re-spacing moved no counts.
- ReadmeRosterDriftTest 10T/78A OK · DocFigureProseDriftTest 92T/4184A OK ·
  CliLangCatalogueTest 8T/220A OK · StderrEmitterCensusTest 95T/22806A OK ·
  ReadPathCensusTest 19T/727A OK · GlobDialectDifferentialTest 13T/366A OK (filter form;
  file-path form gives 9T/12A — reviewer matched the filter figure). All EXACT vs review.
- ImportSubcommandTest 15T/70A OK (+1T/+9A = the new MINOR-2 pin) ·
  ClaudeSettingsImportTest 19T/57A OK.
- HelpTest EXACT-CLASS (tests/Cli/HelpTest.php file run): 105T/3964A/1F — sole F named
  testEveryRosterVariableIsDocumentedOnTheHelpScreenOrExcludedByName[CANDY_CODE_SERVICE_TIER],
  the foreign §7 rider. (The earlier substring `--filter HelpTest` swept KeyHelpTest siblings,
  168T/6F: SERVICE_TIER×1 + KeyHelpTest×5 — those are the known foreign KeyHelpTest rider
  family, zero overlap with this lane's 4 touched files; kept out of the figures per §1.)
- Import filter 45T/213A 0F swept SessionImportSubcommandTest (+11T/+86A) on top of the pair —
  all three import classes green.
- RED SET at tip = exactly the two foreign §7 families named by the review; nothing of mine red.
- MINOR-1 cure authority note: REVIEW-1.md:25,:40 says the ProviderKeyStore row rationale
  must reword to "the read now lives in store() itself" — af26dd781 does exactly that
  (ReadPathCensusTest.php:531-532). The +1 ClaudeSettingsImport.php:326 CALLER_SUPPLIED row
  was already ruled correct by the reviewer and was not part of the cure.
