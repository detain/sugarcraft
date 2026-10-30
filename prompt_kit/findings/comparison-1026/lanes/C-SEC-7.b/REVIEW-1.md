VERDICT: FINDINGS — 0C/1M/1m (2 nits)

Lane: cc/C-SEC-7.b @ 60e99cc20 (base 484f313a9, 2 commits f0db09a45 + 60e99cc20). Reviewed from landed blobs; lane worktree read-only, never edited.

## P1 semantics — VERIFIED (code), one coverage gap (see MAJOR-1)
- `--restricted`: `RESTRICTED_TOOL_NAMES = [...ShellSubjectTools::NAMES('Bash','PtyRun'), 'Task','Workflow','ShellStop','mcp_call']` — all six names match the real `name()` values (TaskTool/WorkflowTool/ShellStop::NAME/McpCallTool::NAME greps, landed blobs). Drop inside `filterToolSet()` AFTER `reportProjectTierToolRemovals()`, BEFORE the empty-warn — order verified on blob (:9630-9666); `cli.warn.no_tools_left` reachable through a fully-nulled set; file tools stay.
- `--safe-mode`: all four trusted predicates (`trustedProjectHookRoots`→funnel, `projectMcpIsTrusted`, `projectCommandShellIsTrusted`, `projectSettingsTrusted`) read roots through the ONE `trustedProjectRoots()` gate returning `[]` first-statement (blob :7095-7114); quarantine filter rides `[]`; `bundles()` gate precedes memo (blob :2627-2637); `foreignAgentPresets()` skips `discover()`; `SkillManager::loadAll` ternarizes exactly the four foreign legs, `$native = $this->loader->manifestTiers(...)` unconditional; `CommandLoader` refuses only the `ForeignCommandDiscovery` walk (native+user+project tiers keep). Funnel-deviation sound (behavior-identical, one switch cannot be forgotten).
- Native-kept asymmetry: code-correct, but NOT behaviorally pinned anywhere (MAJOR-1).

## P2 parse/notice/relaunch — VERIFIED
- bare + `=` families both directions (`switchValue()` 1/true/yes vs 0/false/no, case-insens. — falsy outvotes alias); nonsense (`on`,`always`,empty) → whole token verbatim `$unknownFlags` → bin :392 `failUsage` (exit code = NonInteractive::failUsage's `EXIT_CONFIG` path, usage error names it). Parse arms placed between `--incognito` (:797) and `--worktree` (:846) — no earlier-prefix capture (checked full starts_with/=== list).
- One notice per flag via `warnPermissionConfigInTranscript` at `useRestricted/useSafeMode` registration; disarming records nothing; dedup-by-message pinned. `SessionRelaunch::KEPT_SWITCHES` carries the raw token (`$arg === $switch || starts_with($switch.'=')`), never consumes the next word; doc-law of KEPT_FLAGS ("each takes one value") preserved. Deviation (c) SOUND.

## P3 census flips — ALL RE-DERIVED GREEN BY THE LIVE GUARDS
- seam census 33→35 (TRANSCRIPT_SEAM_CALL_SITES + 10 prose sites + numberWords thirty-four/five) — BootstrapTranscriptSeamCallSiteCensus+Routing+CapCensus: **48T/824A OK**.
- StderrEmitterCensus channel-5 45→47: **95T/22549A OK**.
- cap roster LIMIT 50→52 + sum 49→51 + CapCensus literal: green in the 48T run above.

## P4 lang/help/settings
- en.php: `cli.completion.option.restricted` sorts prompt<restricted<resume (t<u) ✓; `.safe_mode` resume<safe_mode<root ✓; appLaunch.notice pair rides the insertion-order family ✓.
- Deviation (a) SOUND: `--help` prints both flags — text lives in `cli.help.screen` (Help.php renders it); `LaunchFlagsTest` live-scrape regex+content+per-shell completion needles pin it — **Run: RestrictedSafeModeFlagsTest 12T/72A OK**; ArgvParserTest|SessionRelaunch 148T/455A OK; ReadmeRosterDrift 10T OK; DocFigureProseDriftTest 92T/4153A OK; LaunchFlags|SkillManager|ForeignSkillDiscovery 174T/774A OK; BuiltInCommands 10T/565A OK; guard quintet 297T 1F = `CliLangCatalogueTest` sorted-run red on `cli.serve.tls.mismatch/missing` — **base-reproduced** (master aa5ddf638 en.php:1084-85 order; lane diff touches zero serve.tls keys) = standing serve-tls red, not step-local.
- `gen-settings-doc --check` NOT runnable from the reviewer map (php rejected) — static proof instead: the SETTINGS.md row default is generated from `Bootstrap::LAUNCH_NOTICE_LIMIT` by reference (`ContextSettings.php:265` passes the const itself); landed row reads 52 == landed const; guard families green. Builder's regen claim plausible; disclose.

## P5 scope bytes
- trio/dist/suite-figure/durations bytes: NOT in diff ✓. Collision scan 484f313a9..aa5ddf638: only README.md (commands:roster region :622-640) + en.php (:1356,:2192) moved on master vs lane hunks (README :1861+, en :210/:586/:3698) — zero overlap → full-range cherry-pick expected CONFLICT-FREE. Out-of-list files (bin, LaunchContext, SkillManager, CommandLoader, Chat, +tests) = mandated flag-threading + §1.8 prose-roster law, not creep. Author law: both commits `%ae=%ce=detain@interserver.net`, zero trailers ✓.

## FINDINGS
- MAJOR-1 (report truth + coverage): REPORT.md claims "13 methods / 80 assertions" and a behavioral "foreign skills band refused native kept (real SkillManager walk over fixture trees)" test. Landed `tests/Cli/RestrictedSafeModeFlagsTest.php` has **12** test methods (grep + live run: `Tests: 12, Assertions: 72`); the SkillManager-walk test does NOT exist; `grep -rln safeMode tests/` shows NO behavioral pin for the foreign-band refusal or the native-kept asymmetry anywhere (only the `assertStringContainsString('Bootstrap::safeMode()')` scans). Fix: land the promised SkillManager behavioral test (foreign trees refused, native manifest kept — that asymmetry IS the design), or amend the report to the landed truth and disclose scan-only coverage. Durations row must be minted from the landed file: currently 12T.
- MINOR-1 (step-local): the "AFTER the project-tier report" half of the filterToolSet order is comment-asserted but unpinned — only the before-empty-warn half has a test (`testTheDropIsOrderedBeforeTheEmptySetWarning`); moving the drop above `reportProjectTierToolRemovals()` stays green on any config without project-tier removals. Fold an order pin into the MAJOR-1 fix.
- NIT-1: `RESTRICTED_TOOL_NAMES` const sits BETWEEN `filterToolSet()`'s docblock (ends `@return list<Tool>`) and the method (Bootstrap.php:9594-9623) — orphaned docblock association; move the const above the filterToolSet docblock.
- NIT-2 (out-of-scope): StderrEmitterCensus docblock chain-sentence line :241-243 now runs one long sentence ("…and C-SEC-7.b two more still: … each), each producing a") — cosmetic wrap.

Mutation set M1-M4 mutation-reasoned sound (each target present in landed file; M1→drop+order pins, M2→bundles pin (with its unarmed control twin, vacuity-proof), M3→refusal pin (control twin), M4→notice pin). No reviewer-side mutation run (read-only map; php script exec rejected).

WELD CARRIES: DUR row RestrictedSafeModeFlagsTest **12T** (median from 3 per-file junit runs at weld); §7 figure carry = +14 methods (12+ArgvParser 1+SessionRelaunch 1), report's "+15" is a §7 note correction; cherry-pick `f0db09a45..60e99cc20` expected zero-conflict vs aa5ddf638 (overlap scan above); gen-settings-doc regen at weld should print the identical 52 row (const-by-reference) — no extra delta expected.
