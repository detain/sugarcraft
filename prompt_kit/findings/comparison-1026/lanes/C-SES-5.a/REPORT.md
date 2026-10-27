# C-SES-5.a — DONE, committed 6b4474a5b on cc/C-SES-5.a (base cf12e96fb), never pushed

Verdict: all five DST-3 legs shipped in run()/rollback()/httpClient()/new fetchOk()/paged release-selection/composerRefusal()/class docblock; targeted gates green; new tests proven red-against-base (4F+1E pre-fix, src restored md5-verified).

- Anchors: every BRIEF :NNN cite FOUND as cited — zero drift.
- DST-3-P2 exec bit: fileperms&0777 captured before swap; chmod .old+temp pre-rename; rollback re-asserts mode. Pinned for 0755/0700 both directions, Windows-skipped (house idiom).
- DST-3-P4 HTTP status: one fetchOk() judges >=400 on sidecar/phar/signature legs → download_failed naming 'HTTP <code>', sink swept; swap legs live-phar-untouched pins green.
- DST-3-P3 stalls: CURLOPT_LOW_SPEED_LIMIT=1024/TIME=60 under \defined() guards, nested under Guzzle 'curl' (top-level ints are ignored by 7.12 CurlFactory — measured); no total clock (E646 arrayNotHasKey('timeout') pin); TIME BOUNDS docblock false claim truth-flipped.
- DST-3-P1: register_shutdown_function forgets $temp (structural pin: hook sits between mint and rename).
- DST-3-P5: Link: rel="next" walk capped at 5 pages (both follow + cap legs pinned via request-log); composerRefusal branches on InstalledVersions root name — new lang key cli.selfupdate.composer_mode_root ('git pull && composer install').

Gates (plain pipe, cwd candy-code, -u LINES -u COLUMNS):
- --filter SelfUpdate: OK 37 tests / 677 assertions (2 PHPUnit deprecations base-existing).
- five-guard: 133T/15334A, ONLY the allowed ReadmeSuiteFigureDrift staleness pair red (25,537 pinned vs 25,613 live; this lane contributes +5 methods, remainder is merged-lane drift for the weld re-pin).
- adjacency: LangParity|StderrEmitterCensus 102T/22583A green. No new *Test.php files; no durations rows owed.

Deviations: download_failed reused with reason 'HTTP <code>' (no new variant key); composerRefusal gained optional injectable seam param (backward-compat, test-pinned both shapes); chmod best-effort @.

Seams for weld: suite-figure/README re-pin carries this lane's +5T (pair already red at base-era drift). No out-of-scope files touched; no README/doc rows owed beyond the owned class docblock (DocFigure green).

## Fixes (round 2)
MINOR sl cured: SelfUpdateTest.php:1323-1324 curl-less else-branch now asserts self::assertArrayNotHasKey('curl', $config, ...) both legs (string-key polarity exact — production sets $options['curl'] only under \defined, SelfUpdate.php:975). NIT @chmod declined per lead, src untouched.
Gates: --filter SelfUpdate 37T/677A green; five-guard 133T ONLY staleness pair (25,613 vs 25,537). Commit eb8ce97a9 (%ae/%ce detain, zero trailers, tree clean).
