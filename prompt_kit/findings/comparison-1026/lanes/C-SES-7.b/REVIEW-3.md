# C-SES-7.b REVIEW-3 (round-3, fresh reviewer — verification of the F-1 cure + full-lane re-check)

VERDICT: CLEAN — 0 CRITICAL, 0 MAJOR, 0 step-local MINOR, 0 new NIT. Round-2 findings all closed or correctly dispositioned to weld. Lane ready to weld.

## Disk anchors
- worktree /home/sites/cc-cmp-C-SES-7.b, branch cc/C-SES-7.b, tip d46c6ecbfa6dfe10deaa5c1ba224221fc889bbe4 (cat-file: commit), tree clean (`git status --porcelain` = 0 lines); master 07e49e872.
- Chain 4 commits: 864c90c59 (src: Subcommands arm + ParsedArgs) → a36d8f367 (lang/README/test + action-list flip) → d4c60d561 (census flips) → d46c6ecbf (r2 fixes: F-1 usage string + F-3 docblock alignment).
- Diff vs master: 8 files, 489 insertions(+), 12 deletions(-). FIX-3's "no new commit" claim CONFIRMED: d46c6ecbf *is* the round-2 cure commit; a further commit would be empty.

## (1) F-1 — VERIFIED RESOLVED at d46c6ecbf
- `git show d46c6ecbf -- candy-code/lang/en.php`: exactly the :1104 hunk — `cli.session.usage` gains `import <path> [--format <fmt>]` after `show <target>`, matching SUBCOMMAND_ACTIONS['session'] verb order (['list','show','import','rename',...], Subcommands.php:4434).
- Single-source check: `sessionUsage()` (Subcommands.php:1466-1468) is a bare `return Lang::t('cli.session.usage');`; grep over src/ + bin/ for "Usage: candy-code session" finds NO second hardcoded menu; the README Subcommands block (2-line import row) is guarded by its own read. All three session usage doors (:1431/:1437/:1970) render the fixed key.
- FIX-3's live smoke (HOME=$(mktemp -d) php bin/candy-code session → rc=2, import verb printed in position) consistent with this reviewer's static proof; bare-`php` invocations are permission-denied in this reviewer session, so the smoke rides FIX-3's record, backed by the green usage-door pins.

## (2) F-2 — confirmed correct WELD disposition (NOT reopened)
- ParsedArgs edit is 5 lines confined to `SUBCOMMAND_FLAGS['session']` (+--format/+--name value flags + why-comment), the verb's own region; ArgvParser consumes only that table, so the edit is a structural necessity for the brief's declared grammar (revert = unparseable verb).
- §2 lists `Cli/ParsedArgs.php` as a hub file (up to 2 lanes, disjoint declared regions); W24 roster census shows zero co-tenant claims (nearest claimant C-SES-7.c sits W27, different region). Cure = dated plan §4 erratum at weld, as FIX-2 carry item 3 records. Correctly dispositioned; no code change owed.

## (3) Full-lane re-check
- Traceability/scope: every block-named file landed (Subcommands, lang/en.php, README, both test files); the one undeclared file is ParsedArgs (F-2, above). No edit outside declared regions: no Chat.php resume/switch arms (C-SES-7.d), no incognito arms (C-SES-7.c), no Bootstrap sessionStore region (C-SES-8.g), no protocol/generated files (weld-regenerated per §2).
- Completeness: no stubs/TODOs; sessionImport is the full arm (operand/extra-operand/format doors → failUsage exit 2; ledger-key `realpath ?: path` + shared refuseLiveLockedSession BEFORE the import, --force parity; ImportRefusedException caught as type → 'candy-code: ' stderr + typed import-refused JSON + EXIT_FAILURE; text+JSON emits + resume hint).
- Correctness: hand-traced happy path (dispatch → match 'import' → doors pass → importFile → text emit, provider prefix `import:` + notice row asserted at test :93-96) and edge (byte-identical reimport → ledger hit → lock ask skipped (no lock) → 0 appended same id, test :136-147; live-locked delta → EXIT_CONFIG + pid in message + transcript unmoved; --force proceeds, test :209-234). No fabricated success paths: digest asserted vs hash_file, rows asserted via store reads, not echo.
- Conventions: strict_types first; final class; Lang::t for all six user strings (en.php is candy-code's only locale file — no multi-locale duty); comments say why; the census prose (35→36, six→seven, THIRTY-SIX, 41→44) re-derives green.
- Vacuity: mutation-reason stands from r2 (each arm has a falsifiable leg; the scoped-flag test pins parser-accept + both reject legs). OBSERVATION (not a finding): no guard pins the CONTENT of cli.session.usage — the same drift could recur. r2's cure spec explicitly waived a new pin; if the fleet ever wants one, an arm asserting the string enumerates SUBCOMMAND_ACTIONS['session'] is the shape. Out-of-scope note for closeout, zero weight here.
- In-step guard law §1.8: action-list flip rode a36d8f367 (same chain), census flips d4c60d561, usage-string d46c6ecbf — weld picks the range atomically; per-commit redness inside a lane chain is batch norm.
- Rulings §6: none touches C-SES-7.b. No timers (E646 clean), no proc_open/fork (child-lifetime roster unmoved), no network, no weld-only files in diff (suite-figure.json/durations.tsv/dist absent from stat).

## Gates (targeted, cwd=candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml; PtyRun flake excluded by filter scope)
- `--filter 'SessionImport|SessionSubcommands'` → OK (deprecations 2 = baseline). **Tests: 48, Assertions: 313** — byte-exact vs builder/REVIEW-2/FIX-2/FIX-3.
- `--filter 'StderrEmitterCensus|BinCandyCodeAutoloadGuard|CliLangCatalogue|TreeWideGuardRoster|LangParity|DocFigureProseDrift|SessionImportSubcommandTest|SessionSubcommandsTest'` → OK. **Tests: 283, Assertions: 34116** — byte-exact vs all prior rounds.
- `--filter 'ReadmeRosterDrift|GlobDialect'` → OK. **Tests: 24, Assertions: 447** — byte-exact vs FIX-2/FIX-3 (usage string carries spaces → no GlobDialect admission).
- `--filter 'ReadmeSuiteFigureDrift'` → Tests: 4, Failures: 2 = the STANDING staleness pair (pinned 25,537 vs live 28,460 — fleet-wide §7 duty; lane's share is the +11T/1 file). Not a lane defect.

## Weld carries (welder brief, unchanged from FIX-2 items 1-4)
1. `scripts/parallel-tests-durations.tsv`: row for `candy-code/tests/Cli/SessionImportSubcommandTest.php` (11T new file — unlisted = runs zero shards).
2. `suite-figure.json` + README headline re-pin: +11T / +1 test file from this lane, on top of the fleet staleness gap; d46c6ecbf adds +0T/+0A.
3. Plan §4 C-SES-7.b dated erratum (F-2): add `candy-code/src/Cli/ParsedArgs.php (SUBCOMMAND_FLAGS['session'])` to declared files.
4. History note (F-4): d4c60d561's subject restates a36d8f367's content though it carries only the two census files; picks ride as-is — record alongside the §4 row.
Cherry-pick the full range 864c90c59..d46c6ecbf in order; prove absorption (git cherry '-' + content check), then prune worktree/branch per §1.3.
