# FIX-2 — C-SEC-7.a (round 3): MINOR-1, docs/ENVIRONMENT.md:46 permission-mode row

REVIEW-2 cleared MAJOR-1; this commit cures the one surviving MINOR — the
CANDY_CODE_PERMISSION_MODE row still taught the pre-reversal default TWICE.

## Before (both legs, verbatim)
- Default column: `` else `default` in the TUI and `bypass-permissions` for `-p` and background sessions ``
- Prose: `` `-p` and the background-session daemon start in `bypass-permissions`, because their console approver asks on stderr at a terminal and *refuses* without one, so an asking default would refuse an unattended run's first edit. ``

## After
- Default column: `` else `default` in the TUI and `-p`, `bypass-permissions` for a locally spawned daemon ``
- Prose: TUI and `-p` start in `default`, which asks (TUI -> y/n/a modal, engine path included; `-p` -> console approver asks on stderr at a terminal and refuses unattended, naming `--permission-mode`); only a locally spawned background-session daemon starts in `bypass-permissions` (no terminal for the life of the run). Mirrors README.md:2632 and the SETTINGS.md:1228 registered default `` `default` (TUI, `-p`); `bypass-permissions` (daemon) `` == PermissionSettings.php:50 defaultText.

## Guard evidence (cwd=candy-code, env -u LINES -u COLUMNS, plain pipe)
- `vendor/bin/phpunit -c phpunit.xml --filter 'EnvRosterDrift|DocFigureProseDrift|SettingsSchema'`
  -> Tests: 195, Assertions: 46906, OK (2 PHPUnit deprecations = pre-existing
  global-bootstrap pair, REVIEW-2 §1 class). EnvRosterDrift stayed green: the
  row's KEY is unchanged, only prose moved.
- Pre-edit check: no test pins this row's sentence (DocFigure ENVIRONMENT arms
  are the streaming table AH + the retention row; grep for the old phrases in
  tests/ hits only src/test comments about the mode, not this doc line). No
  guard updated in this commit — none needed.
- Line 45 (SEARCH_ENDPOINT) keeps its past-tense "unprompted under the default
  `bypass-permissions`" — it narrates the pre-reversal era; outside this finding.

## Scope
Diff: candy-code/docs/ENVIRONMENT.md (1 insertion, 1 deletion) + this file.
No src/test change; suite-figure/durations/protocol trio/dist untouched.
