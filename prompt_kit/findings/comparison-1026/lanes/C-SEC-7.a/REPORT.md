# C-SEC-7.a — `-p` starts in `default` mode (asks, denies unattended)

STATUS: IN PROGRESS (background=true acknowledged)

## Production + docs half LANDED — commit 4306ec4b2 on cc/C-SEC-7.a (worktree /home/sites/cc-cmp-C-SEC-7.a, base 410f5ac58)

Design (per plan §6.1 "rv-sec-1 reversal: `-p` defaults to Manual/deny like
Claude Code; locally spawned `/bg` daemons keep bypass"):

- `Bootstrap::permissionGate()` gains `string $consoleSurface = self::PERMISSION_SURFACE_BACKGROUND`.
  Plain default surface = BACKGROUND, so the daemon (BackgroundSessionRunner →
  Bootstrap::backend()/LaunchContext::backendFor() with gate=null) keeps
  bypass WITHOUT touching either file.
- Const split: `DEFAULT_PERMISSION_MODE` DELETED; added public
  `PERMISSION_SURFACE_HEADLESS`/`PERMISSION_SURFACE_BACKGROUND` and private
  `HEADLESS_DEFAULT_PERMISSION_MODE = Default`,
  `BACKGROUND_DEFAULT_PERMISSION_MODE = BypassPermissions`;
  `INTERACTIVE_DEFAULT_PERMISSION_MODE` unchanged.
- `NonInteractive::consoleBackend()` (the only `-p` backend route; daemon never
  calls it) resolves the gate with the HEADLESS surface and passes it through.
- New built-in-default notice `appLaunch.notice.headless_default` fires once
  when `-p` lands on `default` from the built-in (not from an explicit source,
  not under announceBypassMode:false probes). The rv-sec-1 bypass notice stays
  for every real bypass resolution (explicit choice or daemon default).
- `HeadlessPermissionPrompt::refusal()` appends `cli.headless.permission_hint`
  (names the fail-closed new default + the one-flag fix).
- SCOPE STRETCH (both files literally restated the old default; doc-truth moves
  in the same commit): `src/Cli/Subcommands.php` doctor 'permission policy'
  row now resolves three surfaces and `cli.doctor.mode_split` gained a
  {background} slot; `src/Config/Settings/Definitions/PermissionSettings.php`
  defaultText → '`default` (TUI, `-p`); `bypass-permissions` (daemon)'
  (schema scalar default stays bypass-permissions — it pins the daemon/plain
  fallback, per SettingsSchemaTest shape).
- README.md mode table + prose (:1552 table rows, :1861 flag prose, :2538
  modes bullet, :2630 unattended bullet) and docs/PERMISSIONS.md precedence
  item 4 flipped.

Probe-verified at tip: plain= bypass-permissions, headless surface= default,
TUI= default (fresh-HOME php -r run).

## Remaining: flip existing pins in-step (BypassLaunchBannerTest,
BootstrapPermissionGateTest, ConsolePermissionApproverWiringTest, LaunchFlagsTest
comment, SettingsSchemaTest, BootstrapToolAndPermissionSettingsTest docblock
cite), new tests/Cli/HeadlessDefaultPermissionModeTest.php, targeted gates.

---

## MILESTONE 2 — DONE (gates green, both commits landed) 2026-10-29

### Commits on cc/C-SEC-7.a (both cat-file-verified 'commit'; %ae/%ce detain=1, no Co-Authored)
- 4306ec4b2 `C-SEC-7.a: -p starts in the asking default mode (rv-sec-1 reversal)`
  (8 files: src/Cli/Bootstrap.php, src/Cli/NonInteractive.php, src/Cli/HeadlessPermissionPrompt.php,
  src/Cli/Subcommands.php, src/Config/Settings/Definitions/PermissionSettings.php, lang/en.php,
  README.md, docs/PERMISSIONS.md)
- cf482cf8f `C-SEC-7.a: pins for the headless default split; census rosters and prose sentences
  move in-step` (14 files: new tests/Cli/HeadlessDefaultPermissionModeTest.php + the six existing
  pin files flipped + the full transcript-seam census family moved in-step + src prose sentences)

### Shape shipped
permissionGate() gains `string $consoleSurface = self::PERMISSION_SURFACE_BACKGROUND`; public
consts PERMISSION_SURFACE_HEADLESS/'headless' + .../BACKGROUND/'background'; DEFAULT_PERMISSION_MODE
DELETED, split into HEADLESS_DEFAULT_PERMISSION_MODE=Default (`-p`) and
BACKGROUND_DEFAULT_PERMISSION_MODE=BypassPermissions (daemon). NonInteractive::consoleBackend
resolves the headless surface explicitly → `-p` asks; every gate-null builder path (daemon via
BackgroundSessionRunner, LaunchContext echo tier) lands on the plain surface = bypass, so NEITHER
BackgroundSessionRunner.php NOR LaunchContext.php needed an edit. New notice
appLaunch.notice.headless_default announces the -p default once at launch (own-period guard
respected; doctor probes stay silent via announceBypassMode:false). Unattended refusal gains
cli.headless.permission_hint naming the one-flag fix. Doctor 'permission policy' row now derives
three surfaces. Headed by plan §6.1: rv-sec-1 reversal confirmed; bypass notice stays for
EXPLICIT bypass.

### Targeted gates (cwd=candy-code, env -u LINES -u COLUMNS plain pipe)
- HeadlessDefaultPermissionModeTest|BypassLaunchBannerTest: Tests: 16, Assertions: 35, OK (2 PHPUnit deprecations are global bootstrap noise — they reproduce on untouched classes the lane never edited, per REVIEW-1 §5; none originate in the new file).
- BootstrapPermissionGate|ConsolePermissionApproverWiring|DoctorBypassNoticeProbe|SettingsSchema|SettingsSourcesLaunchDefaults|HeadlessPermissionPromptUnattendedAsk|LaunchNoticeOwnPeriod: Tests: 139, Assertions: 2775, OK.
- LaunchFlags|BackgroundSessionRunner: Tests: 142, Assertions: 630, OK (daemon-bypass pins stay green untouched — the §6.1 contract).
- BootstrapToolAndPermissionSettings|Subcommands|Doctor*: Tests: 72, Assertions: 286, OK.
- Census trio in-step: StderrEmitterCensus roster 44→45 + FORTY-FOUR→FORTY-FIVE prose + C-SEC-7.a comment; Bootstrap::TRANSCRIPT_SEAM_CALL_SITES 32→33; all ten PROSE_SITES sentences flipped (src/Cli/Bootstrap.php x3, src/Chat.php x1, BootstrapLaunchNoticeRoutingTest x4, McpToolWiringTest x1, docs/SETTINGS.md x1); NUMBER_WORDS +thirty-three. Re-run StderrEmitterCensus|TranscriptSeamCensus|NoticeRouting|McpToolWiring: Tests: 172, Assertions: 22871, OK.
- Guard battery (15 classes + new file): Tests: 362, Assertions: 90126, Failures: 4 — ALL FOUR ATTRIBUTED PRE-EXISTING:
  1. ChatHostLangCatalogue — listed standing red in brief.
  2. BootstrapLaunchFormatConstantsTest PAGE_QUOTES — missing PROJECT_TIER_TOOL_REMOVAL_FORMAT entry; proven base-time: the FORMAT const is byte-identical at 410f5ac58 vs HEAD, README/SETTINGS quote lines identical (git show counts), and the filter still fails with BASE README+PERMISSIONS swapped in. Some earlier lane moved a quoted sentence without moving PAGE_QUOTES.
  3. PermissionsIntroDriftTest — listed standing red (failure diff is a tool-position roster map, no lane files).
  4. DenialPrefixRosterTest 'src/Tools/BuiltIn/SkillWorkshop.php => SkillWorkshop refused:' — the literal EXISTS at 410f5ac58 (git cat-file count 1) with no roster row; neither SkillWorkshop.php nor the roster test is in this lane's diff (0 files).

### WELD CARRIES
- scripts/parallel-tests-durations.tsv: add row for candy-code/tests/Cli/HeadlessDefaultPermissionModeTest.php (10 tests, ~9s class incl HOME sandbox; lane never edits the file).
- suite-figure.json + README staleness pair re-pin at closeout (lane added +10 test methods net of renames — renames are count-neutral: diff census +13 added / -3 removed test functions = 10 new-file methods + 3 renamed-in-place pairs; matches the durations carry's 10 tests).
- Protocol trio untouched by this lane (no DTO/protocol edits); nothing to regen-restore.

### Scope stretches (disclosed, all in service of doc-truth-moves-in-same-commit + census law)
- src/Cli/Subcommands.php doctor 'permission policy' row (brief didn't list it; its two-surface text would have become a lie).
- src/Config/Settings/Definitions/PermissionSettings.php defaultText + D5 comment (restates the old default; SettingsSchemaTest pins the string).
- src/Chat.php, src/Cli/Bootstrap.php prose sentences beyond permissionGate region, tests/Cli/{StderrEmitterCensus,BootstrapTranscriptSeamCallSiteCensus,BootstrapLaunchNoticeRouting,McpToolWiring} + docs/SETTINGS.md — pure census-in-step numeral moves caused by the one new seam call site.
- NOT touched: BackgroundSessionRunner.php, LaunchContext.php (plain surface keeps daemon bypass by construction).

### Notes for reviewer
- lang keys added under own prefixes only: cli.headless.permission_hint, appLaunch.notice.headless_default; cli.doctor.mode_split value re-keyed to three slots (tui/headless/background) — readers: Subcommands row only (derived; no other consumer found by grep).
- E646 honored: no timers anywhere in the step. No new proc_open/fork sites → no child-lifetime roster row needed. GlobDialect: new test avoids glob-shaped literals (no wildcards; 'candy-code/' path strings carry no *).
- Behavior risk flagged in brief (CI scripts relying on -p editing unprompted now fail closed) is the REVERSAL's point; mitigation shipped: launch-time headless_default notice + refusal hint both name --permission-mode.
