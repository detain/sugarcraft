# C-TUI-6.c — Round-1 fix

FIXER=round-1 date=2026-10-31 background=true-confirmed
LANE=/home/sites/cc-cmp-C-TUI-6.c branch=cc/C-TUI-6.c base=c785f3ef8
REVIEW-1.md re-stat'd at fix time: 8,912 B @ 2026-10-31 12:12:07 (verdict C=0 M=0 MINOR=1 NIT=2, WELD-ELIGIBLE YES). REVIEW-1.md and REPORT.md left BYTE-UNTOUCHED per assignment.

## Commit
- dc4fcdc227eee00a5526653c6900552514f7bbae "C-TUI-6.c: review fixes (round 1)" — cat-file -t = commit (same shell), 2 files +7/−4, docblock-only. Picks on lane: 4 (3 reviewed + this). Porcelain 0. %ae/%ce separate count greps = 4/4 detain@interserver.net; Co-Authored-By count 0.

## NIT-1 fixed
sugar-pty/src/PtySystemFactory.php class docblock: removed trailing space after 'platform-resolution layer.' and the stray truly-empty line before ' */' (verified bytes via cat -A: was `layer. ` + `$` + ` */`; now `layer.` + ` */`).

## NIT-2 fixed
sugar-pty/src/Windows/ConPtySlavePty.php spawn @param $env docblock: was "null inherits the parent env; a map REPLACES it wholesale (same law as the POSIX side)". Grounded in the actual code path: the adapter passes $env ?? [] (ConPtySlavePty.php:105) and Kernel32ConPty.php:644 `if ($environment !== [])` skips EnvBlock::encode + CREATE_UNICODE_ENVIRONMENT for the empty map → CreateProcessW gets a NULL env block = INHERIT. New wording: "null or an empty map inherits the parent env ... only a non-empty map REPLACES it wholesale", and the "same law as the POSIX side" claim is REPLACED by the truthful contrast: on the POSIX side (Spawn.php:47-49, proc_open 5th-arg law) any non-null array replaces, so an empty map there means an EMPTY environment — the two sides genuinely diverge on the empty-map case.

## MINOR-1 — CORRECTED §7 FACT SET (WELDER: MINT FROM THIS BLOCK, NOT FROM REPORT.md)
The builder REPORT.md per-file table claims ConPtyMasterPtyTest "15T". That figure is WRONG. Authoritative live measurement (r1 executed + this fixer re-confirms arithmetic):
- **ConPtyMasterPtyTest = 14 tests / 27 assertions (14T/27A)** — r1's file-run figure; corroborated independently by the full-suite arithmetic: base 777 + 40 Windows-file legs (16+14+9+1) + 1 InterfacesTest leg + 2 factory/exception net = 820 = r1's measured FULL-suite count. A 15th MasterPty leg would force 821.
- Welder: mint 14T/27A into any §7/durations-policy ledger AND DISCLOSE both figures (builder claim 15T superseded by live 14T/27A) in the weld record.
- Full corrected new-test-file set for the §7 Windows-row policy (8 files total riding this family: 6.b's 4 are that rider's own; THIS lane's 4 new test files, live counts):
  tests/Windows/ConPtySystemTest.php 16T/60A · tests/Windows/ConPtyMasterPtyTest.php **14T/27A** · tests/Windows/ConPtyChildTest.php 9T/58A · tests/Windows/ConPtyLiveTest.php 1T/1S (skip-by-design on Linux; ceilings 15.0s per-op).
- durations.tsv: N/A at lane level (candy-code-only file; zero sugar-pty rows exist — GATE decides the sugar-pty/tests/Windows row policy per the 6.b §7 rider, now covering 8 files).
- r1 authority pair (lib-local): FULL sugar-pty suite 820T/4015A/0F/0E/20S exit OK (53.9s). No candy-code suite-figure implication — lane touches zero candy-code src/test paths.

## Gates re-run post-fix (cwd=/home/sites/cc-cmp-C-TUI-6.c/sugar-pty, env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml, plain pipe) — ALL UNCHANGED vs r1 (docblock-only proven)
- Tests: 109, Assertions: 843, Skipped: 3. (--filter 'Windows')
- OK (53 tests, 166 assertions) (--filter 'InterfacesTest::')
- OK (33 tests, 80 assertions) (--filter '(PtySystemFactoryTest|UnsupportedPlatformExceptionTest)::')
- NOTE (gate-shape finding, no repo impact): the brief's anchor form '(PtySystemFactory|UnsupportedPlatform)::' matches ZERO tests (class names end ...Test, so :: cannot follow the stem) — the classic namespaced-stem zero-hit trap; corrected anchored form above ran 33T/80A non-zero.
- php -l both edited files: No syntax errors. Residue grep (if (false|&& false||| true) both files: 0 hits. GlobDialect: new prose carries no glob-shaped literals (no wildcards).

## Seams carried (report-only, unchanged by this fix)
- README 'Library layout' section (:560-566) still pre-dates ConPTY — r1 adjudicated report-only → lead ledger.
- ci.yml YAML re-parse + gen-docs --check remain UNCONFIRMED-BY-EXEC in this harness (interpreters denied); r1's static arguments stand.
