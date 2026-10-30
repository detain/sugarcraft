# FIX-1 — C-SEC-7.a review round 1 (MAJOR-1 / MINOR-1 / NIT-1)

Branch cc/C-SEC-7.a. Prior tip cf482cf8f. All three findings applied.

## MAJOR-1 — regenerated docs/SETTINGS.md (the lane-introduced DocDrift red)
- Reproduced the red at cf482cf8f exactly as REVIEW-1 states: `--filter SettingsSchemaDocDriftTest`
  → Tests: 19, Assertions: 188, Failures: 1 ("settings docs are stale"), the diff being the
  permissionMode row at docs/SETTINGS.md:1228 still teaching `bypass-permissions` for `-p`.
- Cure as prescribed: `php tools/gen-settings-doc.php --write` in candy-code.
  Resulting diff: exactly ONE line —
  `` `default` (TUI); `bypass-permissions` (`-p`, daemon) `` → `` `default` (TUI, `-p`); `bypass-permissions` (daemon) ``,
  byte-matching the schema source src/Config/Settings/Definitions/PermissionSettings.php:50.
- Green proof at new tip: `SettingsSchemaDocDriftTest` → Tests: 19, Assertions: 188, Failures: 0
  (only the 2 global-bootstrap PHPUnit deprecations, REVIEW-1 §5 class).

## MINOR-1 — REPORT.md weld-carry numeral
- ":87" "+16 test methods net of renames" corrected to "+10 ... diff census +13/-3 = 10 new-file
  methods + 3 renamed-in-place pairs; matches the durations carry's 10 tests".

## NIT-1 — REPORT.md deprecation attribution
- ":74" class-attribution to BypassLaunchBannerTest replaced with the honest attribution:
  global bootstrap noise reproducing on untouched classes (per REVIEW-1 §5); none in the new file.

## Evidence note
The corrected REPORT.md is committed HERE on the lane branch (worktree copy is canonical);
the main-tree untracked copy predates these two report fixes — welder should take this branch's
REPORT.md + FIX-1.md pair.

## Re-run gates (cwd=candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml)
- SettingsSchemaDocDriftTest → 19T/188A OK.
- HeadlessDefaultPermissionModeTest|BypassLaunchBannerTest → 16T/35A OK.
- BootstrapPermissionGate|ConsolePermissionApproverWiring|DoctorBypassNoticeProbe|SettingsSchema|
  SettingsSourcesLaunchDefaults|HeadlessPermissionPromptUnattendedAsk|LaunchNoticeOwnPeriod
  → 159T/2969A OK, 0 failures (REVIEW-1's 1F cleared).
- ReadmeSuiteFigureDriftTest → 2F (the standing staleness pair, closeout re-pin duty — ONLY red,
  untouched per commit law).
