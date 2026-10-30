# C-SEC-7.b FIX-1 (review round 1, 2026-10-30)

MAJOR-1 LANDED (preferred branch, not report-amend): `testSafeModeRefusesTheForeignSkillBandAndKeepsNativeManifests`
— real `SkillManager(loadAll)` over fixture trees (fresh SkillLoader+SkillRegistry per walk). Project foreign leg
`.claude/skills/sec7b-proj-foreign`, user foreign leg `$HOME/.claude/skills/sec7b-user-foreign` (HomeDirectory::owned()
under the file's HomeSandboxTrait home), native project manifest `.candy-code/skills/sec7b-native`. Unarmed control
first (all three load — vacuity), then armed: both foreign legs assertNull, native assertNotNull. The refused-keeps
asymmetry is now behaviorally pinned, the design core.
MINOR-1 LANDED: `testTheRestrictedDropRunsAfterTheProjectTierReport` — user-config `trustedProjectSettings` +
project `disabledTools:["Grep"]`, `useProjectRootForSettings(realpath)`; expected row rebuilt from
`Lang::t('cli.launch.tool_removal')` + LEAVING consts, asserted via assertContains on `Bootstrap::launchNotices()`
BYTE-EXACT, unarmed AND armed. Mutation flip: move the `if (self::restricted())` drop ABOVE the report call →
report diffs project-reduced set against an already-restricted-filtered kept → row becomes
"disabled 3 of the 5 tools ... — Grep, Task, Bash — leaving: Read, Edit" ≠ expected 1-of-5 row → testTheRestrictedDropRunsAfterTheProjectTierReport
FAILS (proven live: 1T/6A 1F; both halves of the order are now pinned: this AFTER-half + the pre-existing before-empty-warn).
SkillManager neuter mutation (four `$safe ? [] :` legs unconditional) → 1F on the armed assertNull legs (proven live).
NIT-1: const RESTRICTED_TOOL_NAMES + its docblock hoisted above filterToolSet()'s docblock (Bootstrap.php, pure move,
php -l clean, diff 26/26). NIT-2: StderrEmitterCensusTest :241-243 sentence wrapped (comment-only).
Counts: RestrictedSafeModeFlagsTest 14 methods / 84 assertions (was 12/72; base had 2 pre-existing PHPUnit
deprecations, unchanged). REPORT.md carries a dated FIX-1 correction block (claim amended, history kept).

GATES (cwd=candy-code, plain pipe, env -u LINES -u COLUMNS):
- RestrictedSafeModeFlagsTest: 14T/84A OK
- SkillManager|ForeignSkillDiscovery|SkillLoader|AgentsTreeAndLegacyOpencode: 150T/551A OK
- BootstrapTranscriptSeamCallSiteCensus|Routin...apCensus: 48T/824A OK
- StderrEmitterCensus: 95T/22549A OK
- LaunchFlags|ArgvParser|SessionRelaunch: 323T/1205A OK
- DocFigureProseDrift|GlobDialect|ReadmeRosterDrift|EnvRosterDrift|ChildWallClock: 163T/57507A OK
- DuplicatedTestHelperDrift: 1F PRE-EXISTING at 60e99cc20 with this round's edits stashed (HEAVY_CHARS identical pair
  Chat/ResumeCostGuardTest.php vs Host/ResumeCostEstimateTest.php — foreign C-CTX-era files, zero in this lane's diff;
  master standing red, NOT lane-gained: no sec7b helper name appears). New helpers uniquely named
  (writeSkillFixture/walkSkillRegistry) — grep-clean tree-wide.
Restores: Bootstrap.php/SkillManager.php md5-verified after each mutation. Backups: /tmp/opencode/cc-cmp/sec7b-fix1/.
LESSON RE-EARNED (lane-ab precedent): the pre-mutation backup was taken BEFORE the NIT-1 edit, so its restore silently
REVERTED NIT-1; caught via grep of const position, re-applied, backup refreshed, mutation re-proved against final file.
