# C-SEC-7.d — DONE (tip 59fa0d4ec on cc/C-SEC-7.d, never pushed)
Model-judged review for writes + chosen reviewer model landed: PermissionGate::autoReviewsUnflagged() is scope-driven
(autoReviewScope shell|writes|all, default shell byte-identical; writes adds Edit/Write/ApplyPatch/WebFetch reviewed
with their capped AskDiffPreview pending diff; unknown/non-string fails closed to shell). autoReviewModel
(provider/model, user-tier) builds its own tool-less EngineBackend via providerConfigFor+ProviderFactory with
thinkingBudget off; empty=title path byte-identical; not-string/bad-shape/unusable each warn once and leave the
reviewer OFF (no paid call on a guess). ExecReviewer.request/review gained trailing ?projectRoot; shell rendering
byte-identical (pinned). Both keys RiskClass::Spend, layered, no env/cli (env-split stays 22-of-133).
Files: src/Permissions/{PermissionGate,ExecReviewer}.php, src/Cli/Bootstrap.php (permissionGate region only),
src/Config/Settings/Definitions/PermissionSettings.php, src/Config/LayeredSettings.php (generated), lang/en.php (+7 keys),
README/docs/{SETTINGS,PERMISSIONS}.md (generated regen + hand truth-flips: seventy-seven enumeration names both new keys),
tests/Cli/StderrEmitterCensusTest.php (Bootstrap 40→43, once 8→11, prose FORTY-THREE in-step),
tests/Config/LayeredSettingsTest.php (user-tier roster), tests/Permissions/{UnflaggedShellReviewTest,ExecReviewerTest}.php.
NEW test: candy-code/tests/Permissions/AutoReviewScopeTest.php (gate: add durations row).
Red-before: 10 reds on HEAD source (scopes unseen, reviewer() null, warns unreported, no diff:/path: renders);
UnflaggedShellReviewTest twin green pre- and post- (default-scope byte law).
Gates (plain pipe, cwd candy-code): AutoReviewScope+UnflaggedShell+ExecReviewer 43T/135A OK; Permissions+launch-format+
GlobDialect|Duplicated*|SymbolCitation family 1863T/24106A 1F; TrustKey|DocFigure|ReadmeRoster|SettingsSchema|CliLang
140T OK; LayeredSettings|TrustKey 111T OK; StderrEmitterCensus 95T OK; gen-settings-doc --check rc0.
INHERITED base-red (proven red at HEAD docs, foreign roster drift, not this lane's region): PermissionsIntroDriftTest::
testThePatternToolRosterIsTheWholeCatalog (PERMISSIONS.md pattern-tool roster vs ToolCatalog missing Schedule/SendMessage/...).
Deviations: both existing test files gained HomeSandboxTrait sandboxes (gate now reads UiSettings — hermeticity required);
22 = env-override count (not project-tier); project-tier list carries 2 stale entries not in LAYERED (base state, guards
agree); README :1311 stays 'seventy-seven' because the live user-only diff count is unchanged by +2 layered/+0 project.
