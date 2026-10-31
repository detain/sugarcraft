# FIX-1 — C-TOOL-4.b (round-1 fixer)

STATE: started. lane tip at entry: 1c9f29e32 (rev-list 288b81f11..HEAD = 3, porcelain clean — matches brief).
VERDICT file quoted from REVIEW-1.md (19011 B, lead-stat-verified): C=0 M=1 MINOR=2 NIT=3.

FINDINGS IN SCOPE (only these three):
- MAJOR-1: vacuous pin BashSandboxTest::testTheWiredJailsAreTheFactorysChoiceForThisOs (:396) — cure with OS-independent structural door slices (both fromCatalog bodies: contain SandboxFactory::fromSetting( , zero Bubblewrap::fromSetting( ).
- MINOR-1: refusal parenthetical hard-codes the Ubuntu/AppArmor story (Bash.php:618, PtyRun.php:431) — cure by moving the remedy onto the backend (new SandboxBackend::refusalRemedy(), Bubblewrap keeps the byte-identical Ubuntu string, Seatbelt answers the macOS story); Linux refusal text stays byte-identical.
- MINOR-2: doctor early arm `=== 'Windows'` sends BSD/Solaris down the Linux probe — restore `!in_array($osFamily, ['Linux','Darwin'], true)` + pin BSD and Solaris legs.

CARRIED UNTOUCHED (per brief): NIT-1 (Seatbelt `//` root concat), NIT-2 (Bubblewrap probe gate order vs interface sentence).

— progress will be appended below incrementally —

## LANDED (fixer round 1, 2026-10-31)
Commit c70b20db5 on cc/C-TOOL-4.b (git cat-file -t HEAD = commit; author/committer %ae/%ce grep-count 1 each; zero Co-Authored; 8 files, +152/-13; tree porcelain 0; rev-list 288b81f11..HEAD = 4).

- MAJOR-1: tests/Tools/BashSandboxTest.php + testBothCatalogDoorsAskTheFactoryNotAConcreteBackend — source-slice of each fromCatalog body: contains SandboxFactory::fromSetting(, zero Bubblewrap::fromSetting( (same door-slice idiom as DoctorSandboxProbeTest). Legacy runtime test kept untouched.
- MINOR-1: SandboxBackend gains refusalRemedy(); Bubblewrap returns the byte-identical Ubuntu sentence; Seatbelt a macOS-honest sentence; Bash.php:618-625 and PtyRun.php:429-436 interpolate it. Pins: testNeitherRefusalSentenceHardCodesALinuxRemedy (1 refusal sentence / 1 refusalRemedy( call / 0 AppArmor per file) + testEachBackendRemedyRidesTheRefusalItBelongsTo (bubblewrap byte-stability + seatbelt-no-Ubuntu behaviorals). No new lang keys (raw tool-result prose; PERMISSIONS.md quotes no sentence).
- MINOR-2: DoctorChecks.php sandboxProbe arm restored to !in_array(osFamily,[Linux,Darwin],true); docblock + Windows-test comment truth-flipped; new pin testFamiliesWithoutEitherJailReportTheVocabularyLineToo (BSD+Solaris, off OK-line + configured WARN-line, jail-starts absence).

## MUTATION PROOFS (cp+md5 restore, never git checkout; scratch /tmp/opencode/C-TOOL-4.b-fix1)
- M1 both doors -> Bubblewrap::fromSetting: structural test RED; legacy testTheWiredJailsAreTheFactorysChoiceForThisOs GREEN under same mutation (vacuity proven). 2T/1F.
- M2 Bash refusalRemedy arg -> hard-coded Ubuntu string: structural-census RED, behavioral seatbelt leg RED (output literally says "they can fix seatbelt (on Ubuntu...AppArmor)"), legacy bubblewrap refusal pin GREEN. 3T/2F.
- M3 arm -> === 'Windows': BSD row falls through to the REAL bwrap probe, detail 'bashSandbox is off; bwrap at /usr/bin/bwrap...uid map' != vocabulary line, RED. Windows pin GREEN. 2T/1F.
All restored, md5sum -c OK, porcelain 0.

## GATES at tip (all post-fix, plain pipe, cwd=candy-code)
- '\Sandbox\|BashSandboxTest|DoctorSandboxProbeTest|SettingsSchemaDocDriftTest|SettingsSchemaTest': 121T/3122A 0F (reviewer 117T/3090A +4T/+32A = exactly my 4 new methods; 2 ambient deprecations as before).
- SeatbeltTest 14T/104A · SandboxFactoryTest 7T/27A · ReadPathCensusTest 19T/727A · PtyRunToolTest minus testExpectMissIsLoudAndTheScriptStillContinues: 12T/93A — all EXACT vs reviewer.
- Guard filter (ReadmeSuiteFigure|DocFigure|GlobDialect|ChildWallClock|DuplicatedDocBlockLine|SymbolCitation|StderrEmitterCensus|SuiteSkipRoster): 256T/59106A/3F = ONLY the foreign staleness pair (25,537-vs-29,150) + GlobDialect (310,245-vs-311,360, corpus UNMOVED — no glob-shaped literals added).
- DuplicatedTestHelperDrift|TreeWideGuardRoster|SuiteSkipRoster: 59T/4216A/4F = the two licensed foreign pairs, byte-identical to reviewer.
- ReadmeSuiteFigureDrift live-enum: my methods added to EXISTING files; guard figures moved by my edits ZERO (25,537/29,150 unchanged vs reviewer run).

## FILES (all under candy-code/): src/Tools/Sandbox/{SandboxBackend,Bubblewrap,Seatbelt}.php · src/Tools/BuiltIn/{Bash,PtyRun}.php · src/Cli/DoctorChecks.php · tests/Tools/BashSandboxTest.php · tests/Cli/DoctorSandboxProbeTest.php
## NEW TEST FILES for §7/durations: none (methods live in existing files).
## NOT TOUCHED: NIT-1, NIT-2, PathGlob.php, forbidden set, foreign red families, lang files, docs (no prose promise contradicted). No proc_open/fork added (seatbelt behavioral reaches refusal via probe() OS arm before any spawn; bubblewrap behavioral reuses existing shim spawn site).

---

## ROUND 2 (fixer, 2026-10-31) — commit 6af96f63a on cc/C-TOOL-4.b

Grounding: tip was c70b20db5, rev-list 288b81f11..HEAD = 4, porcelain 0 — matched lead brief. REVIEW-2.md verdict C=0 M=1 MINOR=0 NIT=2 accepted as written; r2 confirmation that r1 MAJOR is genuinely cured noted.

MAJOR-2 CURED (guard row): tests/TreeWideGuardRosterTest.php — added exactly ONE WALKS_A_DIRECTORY_THE_TEST_MADE licence row, key 'Tools/BashSandboxTest.php' => ['RecursiveDirectoryIterator($this->tmpDir,\FilesystemIterator::SKIP_DOTS)'], alphabetical in the Tools/ block, with adjudication comment (tearDown sweep of the bash_sandbox_* tmp tree its own setUp created under $this->tmpDir; walker predates this lane — it was surfaced when r1 fix pick c70b20db5 added dirname(__DIR__,2) anchors at BashSandboxTest.php:436/:461, pulling the file into the "names the package root" census scope). Row shape read from the guard's own derivation first (key = test-relative path; value = flattened walker expression; removal-half arm testEveryLicensedResidueRowStillMatchesALiveWalkSite keeps the row live because consultedResidue is recorded before the array_diff). NO DECLARED row added — DECLARED assertCount(4) semantics untouched. NOT a dead row: pre-cure it appears as the red leg, post-cure the leg is gone and the removal-half arm stays green (proves expression matches a live walk site).

NIT-1 TAKEN: Seatbelt.php:301 — rtrim($root,'/') join (was '$root . "/"' yielding '//' when root='/'), comment mirrors the Bubblewrap.php:191 twin. No existing pin covered root-'/' (checked SeatbeltTest roots).

NIT-2 TAKEN: Bubblewrap.php probe() — OS gate moved before resolvedBinary(), matching Seatbelt probe OS-first precedent and the SandboxBackend interface sentence. Cascade checked IN-STEP and clean: DoctorSandboxProbeTest Windows pins (:217 + :234 vocabulary arm) are built from injected osFamily gated in DoctorChecks::sandboxProbe (:520) BEFORE any probe call — no message pin flips (Windows doctor text is emitted by the gate, not by probe order); SandboxFactoryTest (:41) pins class only; docs/PERMISSIONS.md and ToolSettings grepped for message fragments — zero hits; no lang keys touched; Linux behavior byte-identical (both orders refuse on Linux). Doctor row unchanged.

GATES (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS plain pipe; PtyRunToolTest expect-miss case never entered these filters):
- --filter 'TreeWideGuardRoster': BEFORE 17T/3848A/2F (each method red on 2 legs: foreign Cli/LoginSubcommandTest.php glob leg + MINE Tools/BashSandboxTest.php RecursiveDirectoryIterator leg). AFTER 17T/3849A/2F — BashSandboxTest legs GONE from BOTH methods; remaining leg in both = Cli/LoginSubcommandTest.php => glob($this->dir.'/*') ONLY. That leg is FOREIGN: it is cured on master by e78d5dc14 which is not in this lane base 288b81f11; per lead directive it is EXPECTED red here and NOT mine to cure. Weld re-runs green on top.
- --filter 'DuplicatedTestHelperDriftTest|TreeWideGuardRoster': BEFORE 31T/4050A/4F, AFTER 31T/4051A/4F (+1A = removal-half loop iterating the new row). The 4 named reds: DuplicatedTestHelperDriftTest::testNoCopiedTestHelperHasDriftedUnrecorded (titleBackend trio AutoCommitTurnTest/AwayRecapTest/BtwCommandTest), ::testNoDuplicatedConstIsUnrecordedInEitherPolity (T0 1_700_000_000 vs 1_800_000_000.0 pair), plus the two TreeWideGuardRoster methods above — ALL foreign/master-cure domain per lead brief, none touched.
- Sandbox family ALL GREEN, figures match r2 exactly: SeatbeltTest 14T/104A, SandboxFactoryTest 7T/27A, BashSandboxTest 23T/109A, DoctorSandboxProbeTest 12T/72A, ReadPathCensusTest 19T/727A.
- php -l clean on all three changed files.

Files touched (3, +20/-4): candy-code/tests/TreeWideGuardRosterTest.php, candy-code/src/Tools/Sandbox/Seatbelt.php, candy-code/src/Tools/Sandbox/Bubblewrap.php. New *Test.php files: NONE (no durations/suite-figure duties triggered). Forbidden-set untouched: Renderer.php, Tui/Renderer.php, Chat.php, JailPaths, PathGlob.php (GlobDialect corpus un-moved — no glob-shaped literals added), suite-figure.json, durations.tsv, lang keys (zero added), plan_comparison.md.

Commit 6af96f63a335336eb5370c98a7cd9c3f3a516535 (cat-file -t = commit, same shell), %ae grep-count 1, %ce grep-count 1 (separate greps), Co-Authored-By grep-count 0, porcelain 0 after. Filter-trap disclosed: anchored ^.*\Class$ phpunit filter form matched ZERO tests on this tree — bare class-name filters used and reported.
