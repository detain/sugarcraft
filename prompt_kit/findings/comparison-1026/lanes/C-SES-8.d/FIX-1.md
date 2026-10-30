# C-SES-8.d — `/insights` — FIX-1 (round 1)

Fix commit: 1c5b346284dcd11a90720a961d46aa1f27ecdda7 on cc/C-SES-8.d (base 38f097f4b). Author %ae=%ce=detain verified separately, zero Co-Authored-By, object cat-file-confirmed.

## MINOR (step-local) — read-only row unpinned — FIXED
`candy-code/tests/Commands/InsightsCommandTest.php` +10: `testInsightsIsAReadOnlySafeCommand()` mirroring `HooksCommandTest::testHooksIsAReadOnlySafeCommand` — reflection read of `Chat::READ_ONLY_COMMANDS` + `assertContains('insights', …)`. New method → +1T; live-enumeration/suite-figure re-pin rides the WELD (per review), lane touched no figure file.

Mutation proof (cp backup, md5-verified restore 5fbdebfd…): deleting the `'insights',` row (Chat.php:10733) reddens EXACTLY the new pin (1F, "Failed asserting that an array contains 'insights'"; other 17 Insights tests green). Restore green: Insights|Hooks|Generate = 49T/209A.

## NIT-2 (prose) — APPLIED after collision check
One-clause addition to the `READ_ONLY_COMMANDS` docblock (Chat.php, beside the `/generate` sentence): `/insights` is in the way `/export` is — reads the stores, writes only its 0600 shelf file, never the session under lock (mirrors the design ruling + InsightsHostCommand.php:94 AtomicFileWriter::write(…,0600) reality). Safety: the docblock region is byte-identical between lane base 410f5ac58 and origin/master (md5 d6a63b53… on both, grep-verified) and no guard pins its prose (constant referenced only by the Hooks/Generate/Insights reflection tests); [chat] is serialized to this step, which owns the file.

## MAJOR — CONFIRMED WELD-CARRY, untouched (verified on disk)
Architectural fact: DocFigureProseDriftTest:713-718 derives the pinned count LIVE from `CommandRegistry::slashCommands()`; the doc reads "dispatch arms for 63" = 62-at-my-base + insights. Master 07e49e872 already carries 1405-output-style flipped to 63; merged pick needs 64. Fixing it on my branch would break MY green guard. `git diff 38f097f4b..HEAD` = zero numerals, ARCHITECTURE.md untouched. Welder duties restated: (1) ARCHITECTURE.md → 64 at pick; (2) README `commands:roster:*` paragraph both-sides re-wrap → union must keep `/output-style` + `/insights`; (3) COMMANDS.md lands at disjoint offsets (:388/:398), re-run generator check post-resolution.

## NIT-1 — skipped by ruling (weld-carry root; ARCHITECTURE.md stays out of the fix).

## Gates (cwd=candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml, PtyRun expect-miss case excluded; no durations.tsv/suite-figure.json/dist touches)
- Insights: 18T/97A OK (+1T)
- HooksCommandTest|GenerateCommandTest: 31T/112A OK
- (DocFigureProseDrift|CommandDocsDrift|ReadmeRosterDrift|SlashDispatch|PaletteSupersetDispatch|MenuBarLabelDerivation|BuiltInCommands|RegistryTranslation): 165T/6545A OK
- php -l both files clean. 2 PHPUnit deprecations pre-existing at base (reviewer-noted).
