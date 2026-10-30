# REVIEW-1 — C-SEC-7.a (rv-sec-1 reversal: `-p` starts asking; /bg daemons keep bypass)

Lane: cc/C-SEC-7.a @ cf482cf8f (base 410f5ac58, commits 4306ec4b2 + cf482cf8f, both author detain, no trailers).
Reviewer: read-only, round 1. No file edited except this report.

VERDICT: FINDINGS — 1 MAJOR / 1 MINOR / 1 NIT. The substance of the reversal is genuine,
production-reachable, correctly pinned, and honestly disclosed everywhere EXCEPT one docs
guard the lane's own scope stretch broke.

## What was verified genuine (reproduce, not trust — all disk-confirmed)

1. **Production reachability.** bin/candy-code:547 → NonInteractive::run(null gate) → consoleBackend()
   (src/Cli/NonInteractive.php:2148-2156) resolves `Bootstrap::permissionGate(consoleSurface: PERMISSION_SURFACE_HEADLESS)`
   → HEADLESS built-in default = PermissionMode::Default. The only -p route. Daemon route untouched:
   BackgroundSessionRunner::backend() :1052/:1074 passes gate=null → `??= self::permissionGate()` no-arg →
   BACKGROUND surface → BypassPermissions. Not a flag theater: the new test's consoleBackend leg drives the
   REAL path and reflects permissionApprover closure getThis() = HeadlessPermissionPrompt, gate mode Default.
2. **Precedence intact.** flag/env/config walk unchanged; built-in default only applies when $mode===null
   ($fromBuiltInDefault captured before ??=); env=plan still wins (pinned); headless_default notice gated on
   announceBypassMode && fromBuiltInDefault && !interactive && surface===HEADLESS (once-per-store pinned,
   silence on probe/daemon/explicit-mode paths each pinned separately).
3. **Census trio honest + load-bearing.** My live grep: warnPermissionConfigInTranscript( in Bootstrap = 33
   = TRANSCRIPT_SEAM_CALL_SITES 33; StderrEmitter Bootstrap roster 45 = live site count; all ten PROSE_SITES
   sentences (Bootstrap x3, Chat x1, NoticeRouting x4, McpToolWiring x1, SETTINGS.md x1) flipped to
   thirty-three/twenty-two-family in-step; guards green at tip.
4. **Pins not vacuous (mutation-reason).** Flip HEADLESS const → mode+notice+consoleBackend legs red; strip the
   consoleSurface arg at the consoleBackend call → reachability pin red; remove the notice block → announce legs red;
   delete DEFAULT_PERMISSION_MODE pin asserts the const is gone AND the split holds.
5. **Four disclosed pre-existing reds CONFIRMED pre-existing:** SkillWorkshop denial literal + file exist at
   410f5ac58; PROJECT_TIER_TOOL_REMOVAL_FORMAT byte-identical base↔tip and lane diff has zero PROJECT_TIER
   mentions; the six orphan chat.* catalogue keys exist at base; base PERMISSIONS.md has none of the Schedule/
   SkillWorkshop/skills_search rows the guard demands. The 2 "PHPUnit Deprecations" are global bootstrap noise
   (reproduce on untouched GlobDialectDifferentialTest).
6. **Locale duty:** lang/ is en.php-only in candy-code; both new keys + 3-slot mode_split re-key are complete.
   E646: no timers/timeouts added. No proc_open/fork sites. Protocol trio untouched.

## Gate reproduction (cwd=candy-code worktree, env -u LINES -u COLUMNS, -c phpunit.xml, plain pipe)

- HeadlessDefaultPermissionModeTest|BypassLaunchBannerTest → 16T/35A OK (matches builder EXACT; new file = 10T).
- BootstrapPermissionGate|ConsolePermissionApproverWiring|DoctorBypassNoticeProbe|SettingsSchema*|
  SettingsSourcesLaunchDefaults|HeadlessPermissionPromptUnattendedAsk|LaunchNoticeOwnPeriod → 159T/2969A **1 FAILURE**
  (builder claimed this family OK — see MAJOR-1; their filter evidently missed SettingsSchemaDocDriftTest).
- LaunchFlags|BackgroundSessionRunner → 148T/682A OK. · BootstrapToolAndPermissionSettings|Subcommands|Doctor → 182T/2186A OK.
- StderrEmitterCensus|TranscriptSeamCensus|BootstrapLaunchNoticeRouting|McpToolWiring → 150T/22737A OK.
- ChatHostLangCatalogue|BootstrapLaunchFormatConstants|PermissionsIntroDrift|DenialPrefixRoster → 4F, all attributed pre-existing.

## Findings

### MAJOR-1 — lane's PermissionSettings.php defaultText change was never regenerated into docs/SETTINGS.md
`candy-code/docs/SETTINGS.md:1228` (generated permissionMode table row) still reads
`` `default` (TUI); `bypass-permissions` (`-p`, daemon) `` while the schema source
(Definitions/PermissionSettings.php:50) now says `` `default` (TUI, `-p`); `bypass-permissions` (daemon) ``.
→ `SettingsSchemaDocDriftTest::testTheGeneratedDocsAreUpToDate` RED at tip (reproduced: 19T/188A/1F,
"settings docs are stale"). Base was byte-consistent (row == base defaultText) so this is LANE-INTRODUCED,
not on the builder's pre-existing list, and the stale row also teaches the exact wrong fact this step reverses.
Cure: `php tools/gen-settings-doc.php --write` in candy-code and commit the regenerated docs/SETTINGS.md
(amend into cf482cf8f or a follow-up); re-run SettingsSchemaDocDriftTest green.

### MINOR-1 (step-local) — weld-carry arithmetic wrong in REPORT.md:87
"lane added **+16** test methods net of renames" contradicts its own parenthetical (renames are count-neutral).
Diff census: +13 added / −3 removed test functions = net **+10** (10 new-file methods + 3 renamed-in-place pairs).
Cure: fix the carry line to +10 (closeout re-derives the live figure at weld regardless, and the durations.tsv
row should count 10 tests — REPORT.md:86 already says 10, so only :87 misleads).

### NIT-1 — REPORT.md:74 deprecation attribution
Claims the 2 deprecations "reproduce from BypassLaunchBannerTest's 6-test-alone run / inherited shape";
they actually appear on runs of classes the lane never touched (global bootstrap deprecation). Cosmetic;
no action needed beyond not repeating the class-attribution claim in closeout docs.

## Scope note
Subcommands.php + PermissionSettings.php edits are a disclosed stretch beyond the brief file-list but match the
design block's Doctor three-surface requirement; SETTINGS.md numeral edit is census-mandated (§1.8 in-step law)
— neither is a scope violation. MAJOR-1 is the ONLY red the lane introduces.

## Re-review bar
Fix MAJOR-1 (regenerated SETTINGS.md, DocDrift green). MINOR-1 is a one-line report edit the welder may absorb.
Then targeted re-run: `(HeadlessDefault|BypassLaunchBanner|SettingsSchema)` → expect DocDrift green, figures
consistent; nothing else needs re-verification.
