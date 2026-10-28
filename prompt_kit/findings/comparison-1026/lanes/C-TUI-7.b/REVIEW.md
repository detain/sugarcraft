# C-TUI-7.b review (round 1) — reviewer cc-cmp step-reviewer

VERDICT: CLEAN (2 NITs, non-blocking)

Lane: /home/sites/cc-cmp-C-TUI-7.b, branch cc/C-TUI-7.b, ONE commit 29d37b8ed atop base 581ac6ede. Tree clean. BRIEF.md absent in worktree; spec read from plan_comparison.md §4 (C-TUI-7.b block) + prompt_kit/findings/comparison-1026/design-tui.md §"C-TUI-7.b" + plan §1/§2/§6.

## Diff audit
- 7 files: src/Support/MacAltNotice.php (new, 187L, one type, final, strict_types), Bootstrap.php (only the 3 declared methods macAltNotice/terminalSpeaksKittyKeyboard/announceMacAltFallback + their docblocks + one `use` import line for the delegatee — no other region touched), docs/ENVIRONMENT.md (TERM row amended + new KITTY_WINDOW_ID/GHOSTTY_RESOURCES_DIR/WEZTERM_PANE row), EnvRosterDriftTest +3 roster names, ReadPathCensusTest +1 OWNED_HOME row (single file_get_contents in readStamp), MacAltNoticeTest re-pointed (+seam table, +ordering pin), MacAltNoticeStampTest new (5T).
- lang/en.php untouched — matches design ("only if the row wording changes"); row wording did not change. Claim verified byte-true.
- Forbidden files: no suite-figure.json, durations.tsv, candy-code-web/dist/ in diff. New test file named in claims for the gate durations row. Author %ae/%ce both detain@interserver.net; zero Co-Authored trailers.

## Overlap vs master picks
27757dd94 (PRV-5.c) = Chat/Providers; d88f83e87 (TUI-1.a) = HandlesKeys/ComposerVim/lang settings.vimMode row. Zero overlap with this lane's files. `git log 581ac6ede..master` on Bootstrap.php/ENVIRONMENT.md/EnvRosterDriftTest: empty. Weld-safe.

## Correctness
- tmux-awareness: carrier loop over TMUX_TRANSPARENT_ENV (any non-empty ⇒ capable) on top of TERM/TERM_PROGRAM spelling match. Hand-traced: terminalSpeaksKittyKeyboard(['TERM'=>'screen-256color','KITTY_WINDOW_ID'=>'7']) = true (old code: false — discriminating); ['TERM'=>'screen','TERM_PROGRAM'=>'tmux'] = false (row still belongs there). Seam polarity consistent with getenv() ?: '' shape.
- once-per-version: last-announced EQUALITY compare (not ordering). Design says "once per candy-code version"; unknown/dev-<sha> stamps are unorderable; version revert re-announces once — explicitly pinned (StampTest:54-70). Judged acceptable per design.
- Eligibility-before-stamp: Bootstrap guard `$row === null` returns before showsOnThisLaunch(); pinned by source-order test (strpos ladder ineligible < stamp < record). Stamp burn-after-arm holds in production: call site Bootstrap:1868-1873 runs after RuntimeNoticeSink::reset(); arm(); NonInteractive never reaches it (documented in method docblock).
- Fail-soft: no owned home / unread / blocked-directory stamp ⇒ show again; silence requires demonstrable match (tested). Write is best-effort @file_put_contents after mkdir 0700 idempotent guard; unwritable cache only re-shows. PathJail: default path built solely from HomeDirectory::owned() (ReadPathCensus OWNED_HOME row in-step); production default pinned shape-only via reflection.
- No timers (E646 n/a), no fork site (no roster owed).

## Gates re-run (plain-pipe, -u LINES -u COLUMNS, cwd=worktree candy-code)
- `--filter 'MacAltNotice'`: "Tests: 12, Assertions: 56, OK" (12th = PathJail root-resolution provider row auto-derived for the new file; builder claimed 11T/50A — see NIT-2).
- EnvRosterDrift: 37T/37715A green. ReadPathCensus: 19T/620A green (census+roster 56T as briefed).
- Doc/lang guard set `DocFigureProseDrift|LangParity|ReadmeRosterDrift|EnvRosterDrift|TrustKeyDocumentationDrift`: 157T green.
- Broader `Lang|DocFigure|Environment` sweep showed 2 reds NOT attributable to this lane: ChatHostLangCatalogueTest (key chat.effort.launch_unshaped consumed only from src/Cli/LaunchContext.php, outside the guard's scan set — already at base 581ac6ede) and ChatTest::testTheEnvironmentSwitchTurnsSuggestionsOff — reproduced RED on main tree master tip 132c12c27 (standing base red). "PHPUnit Deprecations: 2" is tree-wide ambient noise (appears in unrelated filters).

## Findings
- NIT (out-of-scope observation): MacAltNotice.php:13 cites "plan_comparison 6.15" — the live plan's §6 stops at 6.3; the cite is copied from the pre-existing MacAltNoticeTest header convention, so consistent, but both are dangling. Fix at closeout docs sweep, not this lane.
- NIT (step-local, report-accuracy only): builder's "11T/50A" pair measures 12T/56A at tip once the new file's provider row counts in; no code change needed — weld/gate should read the measured figure.

No CRITICAL/MAJOR/MINOR. Lane is weldable.
