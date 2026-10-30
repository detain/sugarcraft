# C-SEC-7.a — FIX-5 (round 6 review cures)

Base tip: 71dd5cf67baa7d2e12ef5ec21c6ddd4c996ffe72

## Findings cured (4 present-tense comment/docblock survivors, one 2-word cure each)

1. candy-code/src/Background/BackgroundSessionRunner.php:290 — "the documented headless default" → "the documented background (`bypass-permissions`) default". Comment-only; behaviour at :228/:7231-7232 untouched.
2. candy-code/tests/Cli/BypassLaunchBannerTest.php:14 — appositive "the shipped headless default" → "the then-shipped headless default"; :21-25 self-correcting paragraph preserved byte-exact.
3. candy-code/tests/Cli/BootstrapLaunchNoticeRoutingTest.php:1024 — "under the headless bypass default" → "under the OLD headless bypass default". Comment-only.
4. candy-code/tests/Cli/CommandOverrideLaunchNoticeTest.php:91 — identical clause, identical cure. File outside lane-declared list; r5 BackgroundSessionRunner precedent (reviewer-accepted undeclared comment cure) covers it; diff is the one comment line.

## Scan

TBD — wrap-aware, both-word-order, covers tests/**/*.php + src prose.

## Gates

TBD.
## Scan (round-7, wrap-aware, both-orders, tests+src+docs+lang coverage)
- Method: /tmp/opencode/sec7a-r6/scan.py — joins consecutive PHP comment runs and md
  paragraph blocks before matching (wrap-aware); patterns headless-default (both
  orders), bypass..-p/headless/non-interactive and mirror, fallback-to-headless,
  daemon-falls-back; lang/en.php strings+comments scanned; COMPARISON.md excluded
  (program-owned). SURVIVOR = present-tense predicate after past/negation-qualifier
  and per-site TRUE classification.
- POST-FIX survivors: TOTAL 0 (post6.txt).
- NON-VACUITY: same scan vs PRE-fix tree (HEAD=71dd5cf67, my edits uncommitted at
  materialize time via git archive) → SURVIVORS 13 flagging all four r6 sites
  verbatim (BSR.php:288 "falls back to the documented headless default";
  BypassLaunchBannerTest.php:13 "the shipped headless default";
  BootstrapLaunchNoticeRoutingTest.php:1021 & CommandOverrideLaunchNoticeTest.php:90
  "under the headless bypass default") (pre6.txt).
- The scan additionally surfaced THREE present-tense siblings of the same lie,
  cured this round comment-only under the r5/r6 undeclared-file precedent:
  BackgroundSupervisor.php:233/:239/:600 ("daemon's own documented headless
  resolution"; "-p/daemon bypass-documented default"), SettingsResolver.php:118-121
  ("-p and the daemon keep bypass-permissions"), PermissionGateHookTest.php:90-103
  ("BypassPermissions is the default a launch gets"; "console paths (-p, the daemon").
- Dispositioned-not-touched (ALLOW in scan): ArgvParser.php:1291-1305 docblock
  (brief: reviewer-confirmed untouched), docs/HOOKS.md:146, docs/PERMISSIONS.md:23/:75,
  docs/TROUBLESHOOTING.md:264, en.php:2306, Bootstrap.php:6304 (counterfactual),
  + TRUE-MODEL/past test docblocks — each with reason in scan.py ALLOW.
- FINDING (report-only, NOT touched): candy-code/COMPARISON.md:447 still teaches
  "headless default is bypass-permissions"; :449 "falls back to the headless default";
  program-owned matrix, for closeout.
## Gates (targeted, cwd candy-code, plain pipe env -u LINES -u COLUMNS)
- SettingsSchemaDocDriftTest|EnvRosterDriftTest|HeadlessDefaultPermissionModeTest|BypassLaunchBannerTest|BootstrapPermissionGateTest = 137T/40408A OK (== reviewer claim)
- LangParity|DocFigureProseDriftTest = 99T/7347A OK (== reviewer claim)
- BootstrapLaunchNoticeRoutingTest|CommandOverrideLaunchNoticeTest|PermissionGateHookTest|BackgroundSupervisor* = 101T/517A OK
- php -l clean on all 7 edited files (comment/docblock-only; class still parses).
- PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues excluded (not in filters).
