# C-SEC-6.b — project-tier deny/ask-only rules — LANDED

- Branch `cc/C-SEC-6.b`, commit `cbe7d05df` (single commit, 18 files, +812/−49), base `e91eb1d47`, tree clean, never pushed.
- %ae and %ce each grep == 1 `detain@interserver.net`; zero Co-Authored trailers; no caliber run.

## What landed
- NEW `candy-code/src/Permissions/ProjectNarrowingRules.php` — partition filter: deny/ask kept (order preserved), allow entries reported as dropped.
- `Bootstrap::permissionGate()` tail merges `[...projectNarrowingRules(), ...$userRules]` — prepend, so first-match-wins makes a project deny/ask answer before any user allow. NEW private `projectNarrowingRules()`: reads ONLY `<root>/.candy-code/settings.json` (LayeredSettings::SHARED_PATH via the trusted+contained projectFiles walk) when `projectSettingsTrusted()`; untrusted → fully silent no-op; corrupt shared file under trust → `PermissionConfigException` (fail-closed, `unusableSettingsFileReason` now public; the strict `readPolicyFile` ownership clause deliberately not applied to a checked-out file — docblock says so); parses through the same `Bootstrap::permissionRules` strict parser; ONE launch notice (`appLaunch.notice.project_narrowing_rules`, names file + loaded count + dropped-allow count) when anything was kept or dropped; signatures recorded for the tier column + `resetProjectNarrowingRulesForTesting()`.
- `/permissions` report rows gain a tier column (`host.permissions.tier.project|user`); header byte-unchanged.
- LayeredSettings reconcile (declared region): class docblock + PROJECT_TIER_KEYS paragraph truth-flipped; `keyAuditRows` allows `permissionRules` for the shared file only (schema value-check applies); local sibling still wrong-tiers; `cli.settings.wrong_tier` wording generalized so it no longer claims user-tier-exclusivity for all keys — launch never says both.
- `permissionMode` still read from NO project file at any trust level (pinned).
- Caps/censuses in-step: LAUNCH_NOTICE_LIMIT 49→50, roster +`projectNarrowingRules`=>1 (sum 49), cap-census pin 48→49, StderrEmitterCensus Bootstrap 43→44, TRANSCRIPT_SEAM_CALL_SITES 31→32 + all ten PROSE_SITES sentences (src/Chat.php, RoutingTest×4, McpToolWiringTest, SETTINGS.md — outside-files touch required by the seam-census law, disclosed) + census word map.
- Docs in-step: README :1296 paragraph rewritten + modes paragraph sentence; PERMISSIONS.md step-list line + new contract paragraph + remember:project sentence; PermissionSavedRulesMethodsTest docblock truth-flip. Old-law test `testATrustedProjectCannotContributeAPermissionRule` → `testATrustedProjectCanNarrowButNeverWiden` (project deny reaches gate; project allow absent from gate rules()).

## Tests (NEW — gate owes durations rows)
- `candy-code/tests/Permissions/ProjectNarrowingRulesTest.php` (5T)
- `candy-code/tests/Cli/BootstrapProjectNarrowingRulesTest.php` (8T)
Gates (targeted, cwd=candy-code, plain pipe, -c phpunit.xml): seam/cap family 146T/23065A OK; Permissions band 2281T/12964A (2 foreign base-reds below); DocFigureProseDrift 92T OK; TrustKey/LangParity/CliLangCatalogue/Glob/DuplicatedTestHelper/Notices/Slash bands OK; final combined 266T/27702A OK. 'PHPUnit Deprecations: 2' present in every run incl minimal filters = config-level, pre-existing.

## Mutations (all cp/md5-restored EXACT)
- M1 partition neutered → 6 reds (both new files, exactly the allow-drop/order legs).
- M2 merge order swapped → exactly 1 red (`testAProjectDenyAnswersBeforeAUserAllowOnTheSamePattern`).
- M3 trust check removed → exactly 2 reds (untrusted-rules silent + untrusted-corrupt silent).
- M4 notice call removed → exactly 2 reds (notice-once + allow-only-drop-report).

## Foreign base-reds at e91eb1d47 (proven not mine; gate agent should note)
- ProjectTierRefusalInventoryTest both arms (added dot-paths live in Bundles/Marketplace*, FormatAutoTable, LspInstaller, MacAltNotice, ScheduleTool, FirstRunWizard + base Bootstrap docblocks — git-proven at base).
- LayeredSettingsTest::testTheUserTierOnlyKeys... (hardcoded 77-list vs derived 80; consts byte-untouched by this lane; foreign `webSearchBackend` key).
- SettingsWriterCensusTest producer roster (foreign ModelChoice/RenderModeHostCommand/SkillsHostCommand writers).
- PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog (roster paragraph byte-identical to base; doc missing foreign Schedule/SkillWorkshop/skills_search names).
- OneSidedHomeSandboxTest (foreign CacheReusingSummaryBackendTest/InstructionWalkPastRootTest rows), SwallowingCatchCensusTest (MainWorktreeTest:137/:178), ForeignAgentPresetWiringTest permission_mode-reader census (SchedulesCommand.php), MenuBarLabelDerivationTest + KeyHelpTest + RuntimeNoticeSinkDeliveryTest stacked-comment (all foreign-file subjects).

## Deviations disclosed
- Extra files touched beyond files.modify (all census-law-forced, in-wave-serialized hubs free per orchestrator): src/Chat.php (one word-number), tests/Cli/BootstrapLaunchNoticeRoutingTest.php, tests/Integration/McpToolWiringTest.php, tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php, tests/Protocol/PermissionSavedRulesMethodsTest.php, tests/Cli/BootstrapToolAndPermissionSettingsTest.php (old-law flip), docs/SETTINGS.md (one word-number outside generated regions), src/Config/LayeredSettings.php (brief risk-line mandate).
- `LAUNCH_NOTICE_LIMIT` 49→50 flows to SETTINGS.md `notices.transcriptLimit` default display inside the generated region — weld regeneration expected (lane never regenerated/committed generated regions).
- suite-figure.json / durations.tsv / dist untouched per law.
