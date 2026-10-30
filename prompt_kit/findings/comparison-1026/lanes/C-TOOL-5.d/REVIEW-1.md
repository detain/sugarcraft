VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 step-local MINOR / 2 NIT) — against tip 8cee26a2d (2-commit lane)

## Chronology / anti-phantom
- RETRACTION: the earlier completion notification claiming "VERDICT CLEAN + report commit e6670158a" is WITHDRAWN — `git cat-file -t e6670158a` = "Not a valid object name" (re-proven this session). The reviewer commits nothing; reports are files only.
- r1 review began at tip 8bf866168 (1 commit). Mid-review, at 12:02:12, commit 8cee26a2d "C-TOOL-5.d: review fix round 1 — unknown-flag exit-2 pin" landed (+42 lines, LspSubcommandTest only, %ae=%ce detain, 0 trailers). This report's verdict is against the current disk tip; porcelain clean (tracked), only builder-untracked evidence dirs remain.
- An r1 read of the pre-fix blob briefly produced a MINOR ("no `lsp ... --flag` pin"). It is RETIRED: superseded by the lane's own fix round, verified below. First read had been against the immutable 12-method blob (grep -c 'public function test' = 12 at 8bf866168 — builder's 12T/108A was true AT THAT COMMIT).

## Scope vs design (design-tool.md ### C-TOOL-5.d, read from disk)
- 8bf866168: 9 files +639/-28 = exactly the design file set (create LspSubcommandTest; modify ParsedArgs, Subcommands, lang/en.php, README, ARCHITECTURE, MCP.md, HelpTest; DocFigureProseDriftTest per the design's guard duty). No weld-only files (no suite-figure.json/durations.tsv/dist in name-only); zero new getenv/putenv in the diff (blob greps: ParsedArgs diff 0, test file 0) → EnvRosterDrift law: 37T/40476A OK standalone. php -l clean on all 6 touched PHP files.

## (a) Production reachability — PASS
- `lsp` in ParsedArgs::SUBCOMMANDS (13 verbs, blob-listed); ArgvParser :258 in_array opens the verb; Subcommands::dispatch() match arm 'lsp' with `default => failUsage` (no fatal possible); bin/candy-code :535 `exit(Subcommands::dispatch($args))`, unknownFlags door at :392 precedes it. Completion: SUBCOMMAND_DESCRIPTIONS + SUBCOMMAND_ACTIONS both gained 'lsp' (~:4742/:4762).
- JSON arm: --output-format is global (matched before the verb-flag lookup), exercised through REAL ArgvParser in tests. Live end-to-end proven in-suite: testAnUnknownFlagOnListIsAUsageErrorFromTheBin spawns `bin/candy-code lsp list --all` (exec+escapeshellarg, drains then judges proc_close — race-safe) and pins EXIT_CONFIG + 'unrecognized option --all' on stderr, empty stdout.

## (b) LspInstaller integration + mirror — PASS
- install consumes PUBLIC API only: LspInstaller::install(string,?Client,?string,?callable,?string,?string): array{ok,message,path} (:126), installRoot() (:84), installedBinaryPath() (:98); LspReleases::installableLanguages/npmEntry/binaryEntry + public RUST_ANALYZER_RELEASE_TAG. No download logic reimplemented.
- Deviation-2 mirror: lspHostTarget vs installer private derivation (:144-145 strcasecmp PHP_OS_FAMILY Darwin→darwin else linux; normaliseArch :394: amd64/x86_64/x64→x86_64; arm64/aarch64→aarch64; else lower) — PROVEN token-identical by inspection of both landed blobs; installer exposes no public host-target accessor → mirror legitimate. Drift hazard pinned fail-closed: testTheListTargetMirrorCarriesTheInstallersOwnDerivationTokens compares BOTH sources over all 8 comparison tokens.

## (c) No-network seam — PASS
- setLspInstallSeamForTesting/resetLspInstallSeamForTesting class-choke; real installer never called in-suite (door order: usage doors answer before installer; hermeticity note in testInstallWithoutLanguageRefuses; tearDown resets seam; HomeSandboxTrait private home; proc_open bin-child inherits sandboxed HOME and stops at the unknown-flag door).

## (d) Guard-edit honesty — PASS (arithmetic, not silencing)
- DocFigureProseDriftTest (19/18): ARCH arm keeps the word↔live-count tie (assertSame count($names) vs $wordNumbers[$m[1]]; a reverted 'twelve' reddens since the constant derives 13); AZ-arm flips twelve→thirteen, "exactly thirteen", nine→ten (13 − attach − acp − serve = 10, verified against live roster), thirteenth→fourteenth (array_merge count 14), help-row foreach gains 'lsp install'/'lsp list', leaf-row regex 11 unchanged because it never counted lsp rows (foreach covers them). No arm relaxed.
- COMMANDS.md: NOT needed — CommandDocsDriftTest 6T/11A OK on the branch with zero COMMANDS.md edit (no /lsp slash command exists; design's files list never included it). ReadmeRosterDriftTest 10T/78A OK standalone.

## (e) Gates at tip (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe)
- LspSubcommandTest: Tests 13, Assertions 118, OK.
- Filter DocFigureProseDrift|HelpTest|LspSubcommand: 273T/104767A/6F — 6F = 5×KeyHelpTest + HelpTest CANDY_CODE_SERVICE_TIER, both standing-red roster families.
- Lang/roster family filter (ReadmeRosterDrift|CliLangCatalogue|ArgvParser|SessionSubcommands|ModelsCatalog*): 247T/1155A/1F = CliLangCatalogue sortedness — FOREIGN, master-blob proven: master:candy-code/lang/en.php has cli.serve.tls.missing@1105 BEFORE mismatch@1106 (mis-sort predates the lane; run-state: carried by C-WEB-5.a, base-proven by SES-7.b welder). Lane's inserted cli.lsp.* keys sort correctly (login<lsp<mcp; the only diff lines in the failure are serve.tls).
- BinCandyCodeDispatch: 132T/841A/1F = '--worktree accepted, not offered' — FOREIGN, master-blob proven: master ArgvParser carries '--worktree' (count 1) while master Subcommands completion maps carry zero 'worktree' mentions (C-AGT-6.c leftover). lsp legs PASS inside it.
- EnvRosterDrift 37T/40476A OK; CommandDocsDrift 6T/11A OK; HelpTest standalone red = SERVICE_TIER data set only (standing).
- "PHPUnit Deprecations: 2" appears on every filter including files the lane never touches (CommandDocsDrift) — tree-common, environmental.

## (f) Vacuity — live mutation EXECUTION DENIED by the reviewer permission map (cp/mkdir/edit/php-script-invocation/git-all-mutations rejected; disclosed, not hidden). Discrimination rests instead on verbatim assertion inspection + structural derivation:
1. Drop 'lsp' from SUBCOMMANDS → dispatch match falls to default failUsage (rc 2 ≠ pinned EXIT_OK, received-array pins red) AND DocFigure assertCount(13) derives from the same constant → red. Mechanically certain.
2. Break the mcp-list law (any format-dependent verdict): testInstallFailureExitsOneInBothFormats asserts $textRc===$rc===EXIT_FAILURE plus the JSON error envelope; testAnUnknownFlagOnList pins the usage door across parse AND bin. Red either way.
3. Unknown-flag-after-verb: was the r1 gap; the fix commit IS the pin (and passes at tip), with the nonsense case `lsp list --all` refusing rather than printing a list (:123/:124/:147-150).

## NITs (non-blocking)
1. candy-code/src/Cli/ParsedArgs.php 'lsp'=>[] docblock phrasing ("the answer a reader of `lsp list --all` needs") reads as behavior-causation; absent-entry ≡ empty via the :883 `?? null` (models/doctor precedent blob-verified). Wording only; design demanded the entry and the fix round pinned the claim.
2. Subcommands::lsp text list prints pinned column `%-40s`; a future package@version longer than 40 chars overflows the layout. Cosmetic.

## WELD NOTE
- Pick range 9fb981c5c..8cee26a2d — 2 commits, exclusive-start correct (9fb981c5c is 8bf866168's own parent). Expect drift (master ~10 ahead).
- DUR MINT candy-code/tests/Cli/LspSubcommandTest.php: 13 cases, JUNIT TESTCASE-SUM median-of-3.
- §7 carry: +13T/+118A (not the 12T/108A of the builder REPORT — that was true pre-fix-round; figure at tip is 13/118).
- Post-pick on merged master: LspSubcommandTest 13T/118A, DocFigure 92T, HelpTest (+SERVICE_TIER standing), ReadmeRosterDrift, CommandDocsDrift (no COMMANDS.md edit needed), EnvRosterDrift; standing foreign set (serve.tls 1F, --worktree 1F, KeyHelpTest ×5) unchanged — do not chase.
- Collect prompt_kit/.../C-TOOL-5.d/{REPORT.md,REVIEW-1.md} onto the branch pre-prune (builder/welder duty; reviewer does not commit).
