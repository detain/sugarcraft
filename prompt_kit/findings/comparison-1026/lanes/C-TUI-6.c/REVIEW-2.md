VERDICT: CLEAN — C=0 M=0 MINOR=0 NIT=0 · WELD-ELIGIBLE YES (with MINOR-1 carry-condition below — it binds the WELD, not the lane)
STEP=C-TUI-6.c ROLE=r2-closure reviewer=r2 date=2026-10-31 background=true-confirmed
LANE=/home/sites/cc-cmp-C-TUI-6.c branch=cc/C-TUI-6.c base=c785f3ef8 tip=dc4fcdc22 picks=4 porcelain=0 (my reads)

## Q1 Fix-pick scope honesty — PASS
- `git show dc4fcdc22 --stat`: EXACTLY 2 files (sugar-pty/src/PtySystemFactory.php, sugar-pty/src/Windows/ConPtySlavePty.php) at +7/−4. Both hunks are 100% inside docblocks (context lines prove zero code, zero test edits, no fabricated pins).
- NIT-1 confirmed trimmed: `cat -A` at the factory docblock tail shows `layer.$` then ` */$` — no trailing space, no stray blank line.
- NIT-2 confirmed TRUE: new wording "null or an empty map inherits … only a non-empty map REPLACES" matches the executed code path — adapter ConPtySlavePty.php:105 passes `$env ?? []`; Kernel32ConPty.php:644 `if ($environment !== [])` skips EnvBlock::encode + CREATE_UNICODE_ENVIRONMENT → NULL lpEnvironment = inherit. POSIX divergence grounded: src/Spawn.php:48-51 says non-null array REPLACES entirely (proc_open 5th-arg law) → empty map = empty env. Disclosed-as-divergence, NOT conflated (old "same law as the POSIX side" claim removed).

## Q2 MINOR-1 adjudication — RULING: TOLERABLE, no in-lane REPORT edit owed
- REPORT.md re-stat'd: 7,504 B @ 12:04:33 — byte-untouched by the fixer, as punted. The stale "15T" lives at REPORT.md:23.
- I INDEPENDENTLY re-measured the number of record: `--filter ConPtyMasterPtyTest::` → **OK (14 tests, 27 assertions)**; FIX-1's full-suite arithmetic (16+14+9+1; a 15th leg forces 821 vs the measured 820) corroborates. **14T/27A is the §7/weld figure of record; builder 15T is superseded.**
- Rationale: REPORT.md is the builder's historical artifact (fixer assignment forbade touching it); FIX-1 carries a dedicated "WELDER: MINT FROM THIS BLOCK, NOT FROM REPORT.md" fact set. CONDITION ON THE WELD: the §4/weld mark MUST take 14T/27A and MUST disclose the 15T supersession — a silent 15T anywhere from the weld onward is the finding, at the welder's door.

## Q3 Zero-drift regression — PASS, all numbers byte-held (cwd=sugar-pty, env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml, plain pipe)
- MY Tests: lines — `--filter 'Windows'` → "Tests: 109, Assertions: 843, Skipped: 3." (= r1 EXACT) · `--filter 'InterfacesTest::'` → "OK (53 tests, 166 assertions)" · `--filter '(PtySystemFactoryTest|UnsupportedPlatformExceptionTest)::'` → "OK (33 tests, 80 assertions)" · per-file ConPtySystemTest → "OK (16 tests, 60 assertions)" · EnvRosterTest → "OK (14 tests, 345 assertions)". All NON-ZERO, full-`Test`-suffix anchored stems.
- Docblock-drift mine-swept: grepped the removed phrases ("REPLACES it wholesale", "platform-resolution layer") across candy-code/tests + sugar-pty — hits ONLY in the two edited src files → no doc-figure/roster guard needles the prose edit could break. EnvRosterTest re-run green (README untouched by fix).

## Q4 Collateral + law inventory — PASS
- Cumulative base..tip = 22 files; every cumulative path already appears in the first-3-picks name lists (fix added ZERO new files). dc4fcdc22 name-list contains no ci.yml/_data/MATCHUPS hunks (2 files only).
- C-TUI-6.b substrate (ConPtyApi/Kernel32ConPty/CommandLine/EnvBlock/WideString/FakeConPtyApi): grep over all four picks' file lists → ZERO hits → still untouched.
- Residue greps on the fix blob (`if (false`, `&& false`, `|| true`): 0. New markTestSkipped: `git log -S 'markTestSkipped'` across the range names ONLY pick 1 (ConPtyLiveTest, disclosed skip-by-design pair :29/:35 env-gated non-Windows + PHP<8.4/FFI — Windows-only, cannot mask Linux reds; the 1S shape rode my 109/3 and 16/60 runs).
- `php -l` both touched files: "No syntax errors detected" ×2.
- cdef-intptr_t law held BY CONSTRUCTION post-edit: ConPtySystemTest::testAdaptersReferenceNoFfiSurfaceOfTheirOwn lives in the UNTOUCHED test file (name-list proof above) and re-ran green inside my 16T/60A file run — the new prose tokens (Kernel32ConPty, CREATE_UNICODE_ENVIRONMENT, proc_open) sit in T_DOC_COMMENT, which the guard skips.

## Q5 Weld-readiness + §7 LIST (NEVER fix)
- §7 Windows-row policy for the 4 lane test files (GATE note, not durations.tsv rows — candy-code-only file, zero sugar-pty rows exist): ConPtySystemTest 16T/60A · ConPtyMasterPtyTest **14T/27A (of record per Q2)** · ConPtyChildTest 9T/58A · ConPtyLiveTest 1T/1S skip-by-design off-Windows.
- Seams unchanged, report-only: README "Library layout" still pre-ConPTY (lead ledger); ci.yml YAML re-parse + gen-docs --check remain UNVERIFIED-BY-EXEC in this harness (interpreters denied; r1 static arguments stand and nothing in the fix touches them).
- No candy-code gates run from this lane (lib-local scope; accepted-foreign roster + PtyRunToolTest::testExpectMiss exclusion noted, not exercised). Pairing/BashSandbox green expectation not exercised — no candy-code run.
- Weld facts to carry: 4 picks, 22 files, cumulative +1721/−57 (r1's 3-pick +1718/−57 + the +3/−1 docblock net). No root-surface deltas beyond r1's list (fix touched none).
