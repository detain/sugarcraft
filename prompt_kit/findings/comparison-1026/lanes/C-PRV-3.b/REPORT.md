# C-PRV-3.b — `/fast` command (TUI and served) — DONE

Tip: `3d49d2488` on `cc/C-PRV-3.b` (base 86f80fef8), single commit, `%ae`/`%ce` both detain@interserver.net (separate greps = 1), zero Co-Authored trailers, never pushed. Mechanism note: a content-verified snapshot commit `482644c14` appeared at 01:06 covering the 9 design-block files (identical to my final tree); I folded the 10th file (guard row, below) in via `--amend` — final tip is one clean commit.

## Files (git show --stat 3d49d2488)
- candy-code/builtin-commands/0360-fast.php +27 (new spec, slot 0360 was empty; Parsed handler + host binding)
- candy-code/src/Host/Commands/FastHostCommand.php +138 (new; served overlay ONLY — never writes settings, per 0300-model doctrine; effect rides CommandEffect::setModel(provider, model, tieredBackend) which SessionHost stores verbatim)
- candy-code/src/Chat.php +153 (handleFastCommand + fastRow + persistFast between persistEffort and /help; READ_ONLY_COMMANDS += 'fast' + one docblock sentence — co-tenant regions untouched)
- candy-code/tests/Chat/FastCommandTest.php +361 (new; 18 tests — ERRATUM 2026-10-29 fix-1: was '16 tests'; live method count 18)
- candy-code/tests/Host/Commands/FastHostCommandTest.php +212 (new; 7 tests — ERRATUM 2026-10-29 fix-1: was '9 tests'; live method count 7)
- candy-code/lang/en.php +16 (cmd.fast.description/hint + 14 host.fast.* rows; en-only per lang/ contents — C-TUI-6.h locale wave not landed)
- candy-code/README.md (gen roster region via tools/gen-command-docs.php --write)
- candy-code/docs/COMMANDS.md +1 (gen table row)
- candy-code/docs/ARCHITECTURE.md 61→62 slash-command count (guard-forced carry: DocFigureProseDriftTest:715 pins it live)
- candy-code/tests/Commands/MenuBarLabelDerivationTest.php +1 (my 'fast' => 'Read or set the fast' row — hand table must move with any new slash-only command)

## Semantics landed
`/fast [on|off]`: bare reports (no-backend / ON {provider,model} / OFF-saved / OFF-unset ± pair eligibility); `on` arms live EngineBackend overlay + user-tier `serviceTier='fast'` save ONLY when the carried pair shapes a premium lane, else refuses out loud with nothing landing (no half-door); `off` never refuses (standard lane is universal) and persists the affirmative `'default'` (no delete-arm). Unshaped-pair refusal names `{model}` for a shaper off-roster, `{provider}` for a no-lane provider; FallbackProvider wrapper refused naming `fallback(name)` — mirrors LaunchContext::withResolvedServiceTier.

## Gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe)
- php -l: all 10 files clean
- target classes: OK (25 tests, 143 assertions)
- --filter 'Fast|Commands|SlashDispatch': Tests: 1637, Assertions: 18633, Failures: 2 — BOTH foreign-base (below)
- CommandDocsDrift|DocFigureProseDrift|ConfigWriteProducerDocumentationDrift: OK 113 tests
- DuplicatedTestHelperDrift|GlobDialectDifferential|ChildWallClockBudget|OneSidedHomeSandbox: OK 43 tests (drive/replyOf/engineOf are byte-identical licensed twins of EffortCommandTest)
- ReadmeRosterDrift slash roster + RegistryTranslation + BuiltInCommands (handler auto-verify): green (35-test set, only foreign MenuBar red)
- gen-command-docs --check stable (6 tests)

## Foreign-base reds (pre-date this lane; proven at 86f80fef8)
1. CommandFailureTranscriptTest::testChatNeverWritesToStdoutDirectly — token scan of `git show 86f80fef8:candy-code/src/Chat.php` yields the lone offender `line 13676: echo` (named-arg `echo: true` in cyclePrimaryAgent, C-AGT-4.b region — co-tenant, not touched).
2. MenuBarLabelDerivationTest::testEverySlashOnlyRowReadsAsThisTable — base DERIVED_LABELS lacks rows for instructions+commit whose spec files exist at base (2405/2850) and derive; owning lanes owed the table rows. My row landed; theirs left for their owners.

## Carries for the welder/gate (DUR)
- durations.tsv: 2 NEW candy-code test files need rows — tests/Chat/FastCommandTest.php, tests/Host/Commands/FastHostCommandTest.php (unlisted files run zero shards).
- suite-figure/README re-pin: my commit adds +25T/+143A onto an already-stale pinned pair (guard read 25,537 pinned vs 28,093 live inclusive of my delta) — closeout re-pin cadence applies; lane forbade touching suite-figure.json.
- docs: COMMANDS.md/README roster regenerated; ARCHITECTURE count moved 62.

## Seams / notes
- ConfigWriteProducerDocumentationDriftTest checked: walks $onConfigChange provider/model doors only, not SettingsWriter->write sites — no row owed (green).
- Served refusal/apply rows reuse the same host.fast.* keys as the TUI (byte-identical wording across surfaces); host note says 'this session only; changing the saved setting stays the terminal's' — the settings-write split is stated in every served reply.
- LaunchContext:2152 still discards its refusal reason at launch; `/fast` bare now speaks the pair's eligibility ahead of time (the design's spoken-ness seam).
- No Protocol/dist/generated-protocol touch. No /fast palette action declared (sibling /effort precedent).

## Independent verification (repair lane cc-cmp-C-PRV-3.b-repair, 2026-10-30 01:2x)
Every claim above re-derived from disk: tip stable at 3d49d2488 across two reads (tree clean); author law 0 Co-Authored trailers; required gate filter `FastCommand|FastHostCommand|SubcommandsEffort|Effort|ReadmeRosterDrift|CommandCatalogue` on the FINAL tree = OK, 182 tests / 728 assertions (2 deprecations = foreign AccessorDeprecationTest providers); base `git show 86f80fef8:candy-code/src/Chat.php` line 13676 confirmed as the foreign `echo: true` named-arg. ARCHITECTURE 61->62 verified earlier against DocFigureProseDriftTest:715 live registry count. Repair lane ACCEPTS the landed commit as its own judged fix.
