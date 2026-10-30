# REVIEW-2 — C-SEC-7.a (round 2)

Lane: cc/C-SEC-7.a @ 5a9e4ab41 (cat-file-verified tip; base 410f5ac58; commits 4306ec4b2 + cf482cf8f + 5a9e4ab41, author detain, no trailers). Fix commit touches ONLY docs/SETTINGS.md (1 line) + lane evidence files — zero src/test change (git diff cf482cf8f...HEAD --stat -- candy-code/tests candy-code/src empty), so REVIEW-1's verified substance carries.
Reviewer: read-only; only this file written.

VERDICT: FINDINGS — MAJOR-1 CLEARED; 1 new MINOR (out-of-scope doc truth), 2 weld notes.

## 1. MAJOR-1 genuinely cleared (re-run at tip, cwd=candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml)

- `--filter SettingsSchemaDocDriftTest` → `Tests: 19, Assertions: 188 ... OK` (2 global-bootstrap deprecations only — REVIEW-1 §5 class).
- docs/SETTINGS.md:1228 row now `` `default` (TUI, `-p`); `bypass-permissions` (daemon) `` — byte-identical to PermissionSettings.php:50 defaultText; gen-settings-doc output matches the committed line.
- `--filter 'BootstrapPermissionGate|ConsolePermissionApproverWiring|DoctorBypassNoticeProbe|SettingsSchema|SettingsSourcesLaunchDefaults|HeadlessPermissionPromptUnattendedAsk|LaunchNoticeOwnPeriod'` → `Tests: 159, Assertions: 2969, OK` — REVIEW-1's 1F cleared, figures EXACT-match builder.
- `--filter 'HeadlessDefaultPermissionModeTest|BypassLaunchBannerTest'` → `16T/35A OK`; `StderrEmitterCensus|BootstrapTranscriptSeamCallSiteCensus|BootstrapLaunchNoticeRouting|Integration\McpToolWiringTest` → `172T/22871A OK`.
- `ReadmeSuiteFigureDriftTest` → `4T/26A/2F` — the standing staleness pair, §7 closeout duty, NOT a step finding.

## 2. -p asking / daemon bypass: reachable and non-vacuous (code re-read at tip)

- Sole -p route: NonInteractive.php:373/:377 → consoleBackend() :2150 resolves `permissionGate(consoleSurface: PERMISSION_SURFACE_HEADLESS)` and passes it explicitly into backend()/backendFor() (:2153-2154); HEADLESS built-in default = PermissionMode::Default (Bootstrap.php:7229-7233). Daemon: BackgroundSessionRunner passes gate=null → Bootstrap.php:3828 `$gate ??= self::permissionGate()` → BACKGROUND surface → BypassPermissions. Doctor shows all three surfaces (Subcommands.php:546-551). Notice gated on `announceBypassMode && fromBuiltInDefault && !interactive && surface===HEADLESS` (:7255-7258) — distinct from the bypass notice; both emit lines visible in the live test output.
- Mutation-reason holds (pin structure unchanged by the fix; REVIEW-1 §4): flip HEADLESS const → mode+notice+consoleBackend legs red; strip the consoleSurface arg → reachability leg red (test drives the REAL consoleBackend and reflects permissionApprover closure getThis()=HeadlessPermissionPrompt, gate mode Default — HeadlessDefaultPermissionModeTest.php:233); delete-old-const pin asserts DEFAULT_PERMISSION_MODE is gone and the split holds.

## 3. Inherited reds confirmed NOT lane-introduced (round-2 diligence)

- `BootstrapLaunchFormatConstantsTest::testEveryDocPageQuotingAPromotedFormatIsOnTheReadersRoster` → 1F (roster says PROJECT_TIER_TOOL_REMOVAL_FORMAT quoted by README+SETTINGS.md; sweep attributes [README,SETTINGS] to _LEAVING). Reproduced IDENTICAL at tip with base 410f5ac58's docs/SETTINGS.md restored, and the lane's only SETTINGS.md touch is the permissionMode row — foreign drift from the 410f5ac58..a6c45be43 range (C-CTX-4.e/C-SES-7.b doc edits; that range never touches the test class). Fixer's tree was clean after my probe (git status porcelain empty; verified restored).
- `PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog` → 1F: base PERMISSIONS.md roster paragraph lacks `Schedule`/`SkillWorkshop`/`skills_search` rows (lane diff touches only the §Four-places intro) — REVIEW-1's pre-existing attribution confirmed, foreign-inherited, closeout duty.

## 4. Findings

### MINOR-1 (out-of-scope fix-now candidate) — docs/ENVIRONMENT.md:46 still teaches the pre-reversal default
The CANDY_CODE_PERMISSION_MODE row says, twice, `` `default` in the TUI and `bypass-permissions` for `-p` and background sessions `` and "-p and the background-session daemon start in `bypass-permissions`" — exactly the wrong fact this step reverses, the same class as cleared MAJOR-1. The lane owned README.md + PERMISSIONS.md + SETTINGS.md and updated all three; ENVIRONMENT.md was outside its brief file-list (so: step-local finding, severity MINOR because no guard reddens on it — I checked: no test pins this row's prose; EnvRosterDriftTest owns key rosters, not this sentence).
Cure: edit docs/ENVIRONMENT.md:46 — default column → `` `default` in the TUI and `-p`, `bypass-permissions` for spawned daemons ``; prose leg → mirror README:2632 wording (TUI and `-p` start in `default`, `-p` asks on stderr at a terminal and refuses unattended with a hint naming `--permission-mode`; only a locally spawned daemon starts in `bypass-permissions`). Re-run `--filter 'EnvRosterDrift|DocFigureProseDrift|SettingsSchema'` green.

## 5. Weld notes (not findings)

- The main-tree REPORT.md copy predates the fixer's corrections; FIX-1.md already tells the welder to take the branch's REPORT.md + FIX-1.md pair. Do.
- §7 duties riding this weld as before: suite-figure/README staleness pair (2F standing), durations.tsv row for HeadlessDefaultPermissionModeTest (10 tests per corrected +10 carry), and the two inherited doc-guard reds in §3 above belong to their foreign authors/closeout, not to C-SEC-7.a.
