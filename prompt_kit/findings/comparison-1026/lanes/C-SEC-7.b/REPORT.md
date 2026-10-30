# C-SEC-7.b — `--restricted` and `--safe-mode`

RESULT: COMPLETE. Two commits on `cc/C-SEC-7.b`:
- commit 1 (code side): `f0db09a45` — 12 files, +410/-14.
- commit 2 (docs, tests, seam rosters): tip of `cc/C-SEC-7.b` (this file cannot name its own hash — welder stamps it; `git log -1 cc/C-SEC-7.b`).

Cut base was master `484f313a9`; master moved under the lane while it ran (observed `b82aa73c6`, `e3a52e590`, `aa5ddf638` at commit time — welder live). Welder picks the FULL range `f0db09a45..<tip>`.

Lane dir had NO BRIEF.md (empty) — worked from `design-sec.md:497` block alone. Disclosed.

## Semantics landed
- `--restricted`: `Bootstrap::filterToolSet()` drops `Bootstrap::RESTRICTED_TOOL_NAMES = [...ShellSubjectTools::NAMES, 'Task', 'Workflow', 'ShellStop', 'mcp_call']` (NAMES = Bash, PtyRun) — AFTER `reportProjectTierToolRemovals()` (so the drop is never mis-attributed to a project config file) and BEFORE the empty-set warn (a fully-nulled launch still says `cli.warn.no_tools_left`). File tools stay under existing WritePathScope confinement; `/permissions` cannot re-add the dropped tools (launch latch, not a mode).
- `--safe-mode`: ONE funnel gate at `Bootstrap::trustedProjectRoots()` answers `[]` — every `trusted*` predicate (hooks via `trustedRootsForThisProcess()`, `projectMcpIsTrusted()`, public `projectCommandShellIsTrusted()`, settings roots, quarantine snapshot) then answers untrusted by its own absent-root law; `bundles()` returns `[]` as its FIRST statement (before the memo, so it can neither be frozen out by an earlier read nor seed one); `foreignAgentPresets()` skips `discover()`; `SkillManager::loadAll()` ternarizes the four foreign legs (native manifests keep); `CommandLoader` skips the `ForeignCommandDiscovery` walk (native + bundle-fed tiers keep — and bundles are already empty).
- Parse: bare `--restricted`/`--safe-mode` arms; `=`-form families `1/true/yes` and `0/false/no` (case-insensitive — the falsy side lets a wrapper outvote an alias); anything else (`on`, `always`, empty) lands in `$unknownFlags` VERBATIM -> bin's `unrecognized option(s)` usage error, exit 2. Never a guessed verdict.
- Each flag prints exactly one launch notice (`appLaunch.notice.restricted` / `.safe_mode`) through the existing `warnPermissionConfigInTranscript()` both-channel funnel at registration; disarming records nothing.
- Both survive relaunch: `SessionRelaunch::KEPT_SWITCHES` (new const beside `KEPT_FLAGS`, keeping its "each takes one value" doc law true) carries the raw token — bare or `=false` — verbatim, never consuming the following argv word.

## Family rosters (all moved in-step, §1.8)
- ParsedArgs `+bool $restricted +bool $safeMode` (ctor+from; copyWith rides get_object_vars); LaunchContext two latches + use*/accessors; Bootstrap delegates `useRestricted/useSafeMode/restricted()/safeMode()`; bin/candy-code registers both at the launch seam after `useIncognito`.
- Notice-cap census family: `LAUNCH_NOTICE_BOUNDED_SOURCES` +`useRestricted=>1,useSafeMode=>1` (sum 49->51), `LAUNCH_NOTICE_LIMIT` 50->52, `BootstrapLaunchNoticeCapCensusTest` literal 49->51; `docs/SETTINGS.md` generated `notices.transcriptLimit` row regenerated via `gen-settings-doc --write`, `--check` up to date.
- Seam-call-site census family (my +2 `warnPermissionConfigInTranscript(` sites, scan 33->35): `TRANSCRIPT_SEAM_CALL_SITES` 33->35; 10 prose sites bumped in the SAME commit (`src/Chat.php:9424`, Bootstrap `:1724`/`:5675`/`:~9133`, `BootstrapLaunchNoticeRoutingTest` :18/:22/:25/:228, `tests/Integration/McpToolWiringTest.php:1675`, `docs/SETTINGS.md:983`); `numberWords()` += thirty-four/thirty-five.
- StderrEmitterCensus channel-5 `src/Cli/Bootstrap.php` 45->47 with dated `+2 in C-SEC-7.b` comment; class-docblock FORTY-FIVE->FORTY-SEVEN; NUMBER_WORDS += forty-six/forty-seven; chain sentence extended.
- `lang/en.php`: `cli.completion.option.restricted` + `.safe_mode` SORTED into their slots; two `appLaunch.notice.*` keys appended after `project_narrowing_rules` (insertion-order family); two `cli.help.screen` flag blocks.
- `Subcommands::OPTIONS` +2 rows after `--incognito` (completion generators derive; bash/zsh/fish all offer both — pinned).
- `README.md` flags-example 2 lines + 2 explainer paragraphs; `docs/PERMISSIONS.md` new section `## Launch narrowing that cannot be switched`.

## Tests
- NEW `candy-code/tests/Cli/RestrictedSafeModeFlagsTest.php` — **13 methods / 80 assertions** (WELDER: needs a durations.tsv row): parse bare/space/= families, nonsense-verbatim unknownFlags, RESTRICTED roster mirror (derived by reference from `ShellSubjectTools::NAMES`), filterToolSet drop-keeps-Read/Edit + empty-warn ORDER pin, notice once/dedup/separation/no-disarm-row, trust control-vs-refusal (4 predicates incl. reflected privates), bundles refused-with-no-memo-seed, foreign skills band refused native kept (real SkillManager walk over fixture trees), foreign call-site + bin-register source scans (shape-disclosed below).
- `tests/Cli/ArgvParserTest.php` +1 method; `tests/Support/SessionRelaunchTest.php` +1 method (switch carry). Total new methods +15 (13+1+1); LaunchFlagsTest/StderrEmitter/SeamCensus add assertions inside existing methods (0 new).
- Help screen pins added to `tests/Cli/LaunchFlagsTest.php` (regex + content + per-shell completion needles, fish `-l restricted`/`-l safe-mode`).

## Gates (as-run, targeted plain-pipe, cwd candy-code)
- Restricted+SessionRelaunch+CapCensus: 21T/626A green (pre-13th-method); final 7-class sweep: **77T/983A OK** (2 pre-existing deprecations).
- Restricted|SkillManagerTest|ForeignSkillDiscoveryTest: 86T/617A OK; Restricted alone 13T/80A.
- ArgvParserTest 142T/437A; LaunchFlagsTest 106T/514A; SeamCensus|RoutingTest 44T/282A; Stderr|Drain|Bypass 107T/22624A.
- DocFigureProseDriftTest full class post-bump: 92T/4153A **OK** (dispatch arms unchanged — no slash command added).
- Guards batch (ReadmeRoster|SettingsSchema|EnvRoster|TrustKey|ConfigWriteProducer|KeyBinding|CliLangCatalogue|PermissionsIntro): 304T/42652A 2F — BOTH standing (serve-tls `:196`; PermissionsIntro Schedule roster `:59`); re-ran identical after every bump.
- HelpTest: 6F all on the standing-red roster (SERVICE_TIER + 5 KeyHelp); the live parser->help scrape leg GREEN with my flags.
- Consumers batch 325T/1890A 3F — all three reproduced IDENTICALLY at base `484f313a9` in a throwaway worktree: `ProjectTierRefusalInventoryTest` x2 (dot-path enumeration + 62-vs-57 census) and `ForeignAgentPresetWiringTest:472` (permissionMode roster). FOR WELDER STANDING ROSTER (not on the brief list).

## Mutation proofs (cp + `md5sum -c` restore; never git-checkout)
M1 neuter restricted gate -> exactly the 2 tool-drop pins red; M2 neuter bundles guard -> exactly the bundles pin red; M3 neuter trustedProjectRoots guard -> exactly the trust-refusal pin red (control stays green); M4 delete the restricted notice record -> exactly the notice pin red. All restored clean, suite re-green.

## Disclosures / deviations
- No BRIEF.md (empty lane dir); design block alone.
- Out-of-design-list files, mandated wiring: `bin/candy-code` (register seam — the C-SES-7.c incognito precedent), `src/Cli/LaunchContext.php` (the established static shelf), `src/Skills/SkillManager.php` + `src/Commands/CommandLoader.php` (the design's own risk line: "threads one flag through ForeignSkillDiscovery/ForeignCommandDiscovery callers"), `src/Chat.php` + 2 test files + `docs/SETTINGS.md` (§1.8 same-commit prose-roster law).
- `src/Cli/Help.php` ZERO edits: it renders the one `cli.help.screen` Lang entry (design's file-list mechanism lives in lang). `tests/Cli/HelpTest.php` ZERO edits: its live parser-scrape guard absorbs the flags and passed. Both disclosed as design-list deviations.
- Design asked per-predicate trust refusals; shipped as ONE funnel gate at `trustedProjectRoots()` — every predicate answers untrusted through its own absent-root path (behavior-identical, one switch cannot be forgotten by a fifth predicate).
- Design said "both `=` and space forms" for bool switches: implemented as bare-arms + strict `=` verdict families with falsy side; nonsense values fail loud (exit 2), never silently default.
- The foreign skills/commands/bundle refusal is pinned behaviorally at the SkillManager level; CommandLoader/presets/bin carry shape-scans (full multi-vendor tree fixtures would cost more than the regression they catch — disclosed, and their bundle-fed inputs are themselves pinned empty).
- In-lane regression found+fixed honestly: commit-1's 13-line comment restructure of `loadAll` pushed `registerFromManifest(` past DocFigure's 3000-char body window (arm `testSkillsPageTierCountsMatchTheWalksTheyCite` reddened); re-cut as per-leg ternaries with a 4-line comment. ~105-char window margin remains — future prose inside `loadAll` before the native loop will redden that arm.
- Collisions: `docs/SETTINGS.md` regen clean (single generated line + one hand-prose word, `--check` up to date), README/en.php/PERMISSIONS.md — NO foreign hunks encountered this era. Two-commit pick range for the welder; suite-figure.json/durations.tsv/protocol artifacts untouched per law.
