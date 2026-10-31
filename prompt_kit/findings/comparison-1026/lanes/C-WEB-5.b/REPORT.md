# C-WEB-5.b — `serve url --qr` pairing QR — STATUS: DONE (step complete at 357ebe05e, one commit)

Lane: cc/C-WEB-5.b @ /home/sites/cc-cmp-C-WEB-5.b, base master 288b81f11. Sole writer confirmed (prior twin stopped by owner).

## Commits
- 357ebe05e2c38a7bdba5cd2844568f4b2e291057 "C-WEB-5.b: serve url --qr pairing QR" (cat-file -t = commit, same shell; %ae grep -c detain =1; %ce separate grep =1; zero Co-Authored trailers; tree clean after commit).

## Files (exactly the design block's set — git diff --name-only 288b81f11..HEAD)
- create candy-code/src/Server/Pairing/QrEncoder.php — byte-mode ECC-M versions 1–10 in-tree QR encoder (213-byte cap; GF(256) RS, block interleave, all 8 masks + ISO penalty selection, format/version bits). Public: encode(payload, ?forcedMask), versionFor, capacity, formatBits, versionBits, dataCodewords.
- create candy-code/src/Server/Pairing/QrTerminal.php — half-block (▀▄█ + space) two-rows-per-two-module-lines terminal renderer, 4-module quiet zone.
- create candy-code/tests/Server/Pairing/QrEncoderTest.php — NEW test file (needs durations.tsv row at gate): 13 reference vectors (B1–B10 exact-capacity + U1–U3 sign-in-URL shapes) from segno 1.6.6 stored as byte-packed hex (payloads hex2bin'd — GlobDialect-safe), all 8 forced-mask grids, formatBits m0–7, versionBits v7–10, >213-byte refusal, versionFor walk, terminal goldens. 25T/877A.
- modify candy-code/src/Cli/Serve.php — ACTION_FLAGS['url']=['--qr']; url() refuses --qr + --output-format json (usage error, before locate()); after each printed URL renders its QR to stdout; cleartext guard: non-loopback http:// URL prints warning naming HOST ONLY (never the fragment code) above its QR; over-length URL warns per-URL and continues; new private qrCleartextHost() (parse_url, IPv6-bracket-trim, ServerConfig::LOOPBACK_HOSTS + 127. prefix, https exempt).
- modify candy-code/src/Cli/ParsedArgs.php — '--qr' => false in SUBCOMMAND_FLAGS['serve'] (alphabetical, between --port and --rotate).
- modify candy-code/lang/en.php — cli.serve.url.qr_json, cli.serve.qr.cleartext ({host}), cli.serve.qr.too_long; help screen 'serve url [--qr]' action line + '      --qr' flag row (desc column 25, house); usage string updated.
- modify candy-code/docs/SERVER.md — serve url code-block line, exit-table row, JSON-note paragraph (stale design cites :134/:188 re-derived by symbol to live :136/:190).
- modify candy-code/tests/Cli/ServeArgsTest.php — +2T: url --qr parses / value-form refusal / --qr refused on token, logs, bare, and with json (EXIT_CONFIG); red-before mutation-proven (neuter qr_json refusal → exactly 1F '1 is identical to 2'); + testTheQrCleartextWarningNamesTheHostAndNeverTheOneTimeCode — per-process uniqid sentinel token, asserts warning contains host, NOT sentinel, NOT '#code=' (IPv6 leg was itself red-before → bracket-trim fix → green).

## Zero new dependencies (R13)
git diff --name-only 288b81f11..HEAD | grep -cE 'composer.json|package.json' = 0. No candy-code-web touch at all. segno 1.6.6 + qrcode 8.2 live ONLY in /home/my/.local pip user-site as build-time fixture generators; encoder + rendering are pure in-tree PHP. (qrcode 8.2 fixtures were ABANDONED — its get_matrix carries a 4-module border; segno is the pinned reference.)

## Deviations disclosed
1. Help.php untouched: the serve help text lives in lang/en.php cli.help.screen (design-era cite Help.php stale) — flag row landed there; ServeArgsTest::testEveryServeFlagIsOnTheHelpScreen passes.
2. Padding convention: byte-align adds a full 0x00 filler codeword even when the stream is already aligned (segno 1.6.6 reference-tool behavior, pinned by the committed fixtures); ISO 7.4.10 would omit it for aligned streams. Result stays a fully valid RS codeword sequence and scanners read the count field, never the filler.
3. No new env var/settings → EnvRosterDriftTest untouched/green; no proc_open; no new file reads (ReadPathCensus green); protocol surface unmoved (CLI-only; ServerProtocolDocumentationDriftTest green).

## Gates (cwd=candy-code, env -u LINES -u COLUMNS, -c phpunit.xml, anchored filters)
- ServeArgsTest|QrEncoderTest combined: 69T/1107A green (2 PHPUnit Deprecations = pre-existing baseline).
- Guard battery (LangParity|StderrEmitterCensus|ReadPathCensus|ServerProtocolDocumentationDrift|GlobDialectDifferential|DuplicatedTestHelperDrift|TreeWideGuardRoster|EnvRosterDrift): 208T/72270A, 4F — ALL 4 BASE-PROVEN FOREIGN (files byte-identical to 288b81f11, none mine): DuplicatedTestHelperDriftTest titleBackend drift (Chat/AutoCommitTurn vs AwayRecap vs BtwCommand) + 'drift T0' (Host/ResumeCostEstimate vs Tui/AwayTracker consts) + TreeWideGuardRoster glob in Cli/LoginSubcommandTest (present at base 117c948bb era) x2 legs. NOT cured (not mine).
- suite-figure/README staleness pair: expected — new test file +25T (gate re-pin duty).
- Foreign-red baseline restated: AttachPagingTest 2F, HelpTest[CANDY_CODE_SERVICE_TIER], BinCandyCodeDispatchTest[--worktree], ApplySettingsTest keepWarm, KeyHelpTest 5F+1R + the 4 foreign guard reds above.
- HelpTest anchored (SugarCraft\Code\Tests\Cli\HelpTest::): 105T/3979A, exactly 1F = baseline foreign CANDY_CODE_SERVICE_TIER — zero new reds from the help-screen edits (the earlier long unanchored run was KeyHelpTest's known slow sweep).

## New test files for durations.tsv (gate/welder)
- candy-code/tests/Server/Pairing/QrEncoderTest.php
