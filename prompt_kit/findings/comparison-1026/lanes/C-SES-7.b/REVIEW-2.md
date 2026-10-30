# C-SES-7.b REVIEW-2 (round-2, fresh full review of chain 864c90c59..d4c60d561)

VERDICT: FINDINGS — 0 CRITICAL, 0 MAJOR, 2 MINOR, 2 NIT. Wave-concurrency category letter (ParsedArgs) investigated and downgraded with evidence in F-2.

## Disk anchors
- worktree /home/sites/cc-cmp-C-SES-7.b, branch cc/C-SES-7.b, tip d4c60d561, tree clean; master = 07e49e872; merge-base 574e69f1d; `git cherry` 3/3 '+' (unabsorbed, expected pre-weld).
- Chain: 864c90c59 (src only: ParsedArgs +5, Subcommands +124/-1), a36d8f367 (README +2, lang +6, SessionImportSubcommandTest +324, SessionSubcommandsTest 10), d4c60d561 (StderrEmitterCensusTest 16, BinCandyCodeAutoloadGuardTest 12).

## Stat paste
```
 candy-code/README.md                               |   2 +
 candy-code/lang/en.php                             |   6 +
 candy-code/src/Cli/ParsedArgs.php                  |   5 +
 candy-code/src/Cli/Subcommands.php                 | 124 +++++++-
 .../tests/Cli/SessionImportSubcommandTest.php      | 324 +++++++++++++++++++++
 candy-code/tests/Cli/SessionSubcommandsTest.php    |  10 +-
 candy-code/tests/Cli/StderrEmitterCensusTest.php   |  16 +-
 .../Integration/BinCandyCodeAutoloadGuardTest.php  |  12 +-
 8 files changed, 488 insertions(+), 11 deletions(-)
```

## Gates (targeted, cwd=candy-code, plain pipe, env -u LINES -u COLUMNS, vendor/bin/phpunit -c phpunit.xml)
- `--filter 'SessionImport|SessionSubcommands'` → `OK (deprecations 2). Tests: 48, Assertions: 313` (builder claim 48T/313A reproduced exactly).
- `--filter 'StderrEmitterCensus|BinCandyCodeAutoloadGuard|CliLangCatalogue|TreeWideGuardRoster|LangParity|DocFigureProseDrift|SessionImportSubcommandTest|SessionSubcommandsTest'` → `Tests: 283, Assertions: 34116` OK — builder claim 283T reproduced exactly.
- `--filter 'ReadmeRosterDrift'` → 10T/78A OK. `--filter 'GlobDialect'` → 14T/369A OK (new test ships no glob-shaped literals; corpus figure unmoved).
- `--filter 'ReadmeSuiteFigureDrift'` → 2F = the standing staleness pair (pinned 25,537 vs live 28,460; pair already red fleet-wide at batch-18o era, lane adds exactly +11T = the 11 new methods). Expected-red per §7; carries disclosed and correct: DUR row for tests/Cli/SessionImportSubcommandTest.php + suite-figure/README re-pin owed at closeout. Neither forbidden file is in the diff (verified by stat).

## r1 cure-spec conformance (all met)
- Import arm `sessionImport()` present and complete (no stubs/TODOs); match arm + SUBCOMMAND_ACTIONS['session'] gains 'import' after 'show'.
- Flag scoping: SESSION_ACTION_FLAGS['import'] = ['--format','--name','--force']; parser admission via ParsedArgs::SUBCOMMAND_FLAGS['session'] (+--format/+--name as value flags); per-action door enforced — test `testTheImportersScopedValueFlagsParseOnlyWhereTheyBelong` pins both the accept and the two reject legs (show --format, import --limit). ArgvParser:822 reads ONLY that table, so the ParsedArgs edit is structurally unavoidable for the brief's grammar.
- --force lock-gate parity: calls the shared `refuseLiveLockedSession($rows[0], $store, $args)` (:1947, identical channel as :1668/:1701/:1738 siblings), BEFORE the import (delta-under-live-window = the ISS-079 silent-undo the gate exists to stop); ledger identity key `realpath ?: path` mirrors the importer's own law (SessionImporter::importFile). Pinned behaviorally: pid in message, transcript untouched after refusal, --force proceeds with 1 appended.
- lang: 6 `cli.session.import.*` keys, all 6 used by the arm, placeholders matched.
- README: 2-line session-import row in the Subcommands block, verb order matches SUBCOMMAND_ACTIONS; --force omission follows the house pattern (delete/pin rows omit it too).
- Exit codes: usage doors (missing operand / unknown --format / extra operand) → failUsage → EXIT_CONFIG=2; ImportRefusedException (unreadable/shape/bounds) → typed `import-refused` JSON + stderr 'candy-code: ' line → EXIT_FAILURE=1; lock refusal → EXIT_CONFIG (sibling parity, matches failUsage channel). Bounds-refusal note: the CLI pin exercises the shape sub-channel; tooLarge/tooManyItems ride the same typed catch (class docblock discloses; importer-side bounds pinned by 7.a's SessionImporterTest). Accepted — channel is type-keyed, nothing branchable to mutate.
- §1.8 in-step: SessionSubcommandsTest action-list flip + both census rosters ride the same lane chain (program precedent: weld picks the range atomically; per-commit redness inside a lane chain is the batch-18 norm, e.g. C-SEC-8.f).
- No scope creep into co-tenant regions: diff touches no Chat.php resume/switch arms (.d), no incognito arms (.c, W27), no Bootstrap permissionGate region (C-SEC-7.a), no Serve/--tls (C-WEB-5.a). E646: zero timers/timeouts; no proc_open/fork; no network.

## API-faithfulness vs C-SES-7.a on master
- `SessionImporter::new()->importFile(EnhancedSessionStore, string $path, ?ImportFormat, ?string $name): ImportedTranscript` — call matches signature; 'auto' special-cased to null = the importer's detect() default; ImportFormat has no 'auto' case (correct). JSON result keys (imported/format/items/appended/digest/name) map 1:1 to ImportedTranscript's readonly fields. Ledger `atPath($store->path())->lookup()` shape `{session_id,items,digest}` used correctly.

## Tree-coherence (takeover + twin stacking)
- No double-application: exactly 1 `function sessionImport`, 1 `cli.session.import.bad_format` key, 1 `'--format' => true` in ParsedArgs; each commit's content matches its place in the chain; final net diff == per-commit sum.

## Mutation-reason (load-bearing pins)
happy-text (drop echo/regex → red), JSON digest (remap digest → red), --name (drop $name passthrough → show-by-name red), reimport-0-appended (change ledger key law → new-id red), delta-append (break append → counts red), usage doors (delete lang keys → raw-key string red; flip exit convention → rc red), import-refused-exit-1 (catch → EXIT_CONFIG → rc red), live-lock (move gate after import → transcript-count red; drop --force passthrough → forced rc red), parser-scope (revert ParsedArgs lines → unknownFlags red; neuter SESSION_ACTION_FLAGS door → message red). Census trio: re-derive live — planted/removed emitter reddens exactly one roster.

## Findings
- F-1 MINOR (step-local) — `lang/en.php:1104` `cli.session.usage` (the general session menu echoed by every session usage door, incl. `candy-code session` alone) still lists only the eight old verbs; the brief declared the sessionUsage() change region and it was left unlanded. Cure: add `import <path> [--format <fmt>]` into that one string; verified no guard pins its current text (DocFigure's 11-row pin is Help.php screen rows; tests assert door messages only), so re-run the cited 283T filter after.
- F-2 MINOR (step-local) — `src/Cli/ParsedArgs.php` is not in the brief's files.modify (category-1 letter: MAJOR). Downgraded with evidence: edit is 5 lines confined to SUBCOMMAND_FLAGS['session'] (the verb's own region), disclosed in the lane REPORT, structurally required for the declared grammar (ArgvParser consumes only that table), and a W24 roster census (10 lanes' plan rows + design-ses.md) shows zero concurrent lane declaring ParsedArgs; nearest ParsedArgs claimant C-SES-7.c sits in W27 on a different region. Cure at weld: dated erratum on plan §4 C-SES-7.b row adding `candy-code/src/Cli/ParsedArgs.php (SUBCOMMAND_FLAGS['session'])` to declared files. Reverting the edit is not a viable cure (ships an unparseable verb).
- F-3 NIT — `tests/Cli/StderrEmitterCensusTest.php:60-62`: the three added docblock continuation lines start `* ` instead of the aligned ` * `. Cure: prefix a space (cosmetic; may ride any future touch).
- F-4 NIT — commit subject d4c60d561 restates a36d8f367's content ("lang keys + README row + test") though it carries only the census flips. History note only; picks ride as-is.

## Verdict
Chain is the real, coherent, API-faithful landing r1 demanded; gates reproduce the builder's claims exactly (48T/313A, 283T, Glob/ReadmeRoster green; staleness pair standing-red as disclosed). Two step-local minors + weld erratum; nothing blocks weld.
