# W35 FIX-2 — ReadPathCensus roster mint for MediaMethods (C-TUI-6.n weld left-off)

## Pre-run red (master 0fbd954563547a56ef3f82c0d91069098ab1c031)
`vendor/bin/phpunit -c phpunit.xml --filter ReadPathCensusTest` →
FAILURES! Tests: 19, Assertions: 781, Failures: 1.
Hunk: MediaMethods.php|file_get_contents expected 1 / actual 2;
MediaMethods.php|fopen expected 2 / actual 3.

## Re-derivation (not guessed)
src/Protocol/Methods/MediaMethods.php live sinks: fopen :456 (fetch rb),
:705 (writeTake xb, C-TUI-6.n), :796 (writeLedger temp xb);
file_get_contents :658 (transcribeStatus, C-TUI-6.n), :826 (readLedger).
Both new sinks are server-minted paths under the session media root
(jobs/.<jobId>.audio.<ext>; jobs/audio-<jobId>.json, 16-hex pattern-checked) → SELF_LOCATED,
mirroring the adjacent ledger-row reasoning.

## Rows added (tests/Support/ReadPathCensusTest.php ONLY)
- fopen row += writeTake() SELF_LOCATED verdict
- file_get_contents row += transcribeStatus() SELF_LOCATED verdict

## Post-commit gate (761d1090e)
`--filter ReadPathCensusTest` → OK, but there were issues!
Tests: 19, Assertions: 785, PHPUnit Deprecations: 2.
(the 2 deprecations pre-existed at base; assertions 781→785 = the 4 new
verdict legs). `php -l` → No syntax errors detected.

## Commit proof
SHA 761d1090e87b35ce59d873e896c2760a2c71e57a (cat-file -t = commit)
git show --stat: 1 file changed (+7), ReadPathCensusTest.php only.
%ae grep-c detain == 1; %ce grep-c detain == 1; Co-Authored grep-c == 0.
HookWebhooks* paths never staged (explicit-path `commit -o -- <file>`).
NO push.
