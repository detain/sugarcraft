# C-TOOL-4.a REVIEW-1 (reviewer: cc-cmp step reviewer, 2026-10-28)

VERDICT: CLEAN

## Disk-verified claims
- Branch cc/C-TOOL-4.a, ONE commit c28d0a473 on base 63f2de5d9 (git log master..HEAD). %ae/%ce = detain@interserver.net both; zero Co-Authored/Co-Developed in %B. Porcelain clean. 8 files +477/−10 == the BRIEF's declared set exactly (git diff --name-only). No suite-figure.json / durations.tsv / dist in the diff (verified against base file bytes + diff list).
- lang/: only en.php ships in the repo today → en-only additions cannot break LangParity (gate green, §6.3-h last-landing rule untouched).

## Extraction honesty (point 2)
- Base Bash.php:566-570 closure == HEAD sandboxRefusal() body, line-by-line (git show 63f2de5d9 compared). execute() now delegates; refusal text unchanged on every refusal path. `proc_open` appears in the diff ONLY in a comment and in the structural absence-pin. runCaptured args (probe, null, 4096, 10.0) verbatim → no new bound minted; probe() memoizes per (binary,network) — "one spawn, not two" docblock claim true.

## appArmorRestrictsUserns (point 3)
- Bounded 16-byte read (named arg `length:` valid), trim==='1'. Fail-safe: unreadable → null = UNKNOWN, never "no"; true only on explicit '1'. Class docblock + remedy-arm comment both state it; pinned three-way (assertTrue/False/Null) + const-path pin.

## Row placement / seams / deviation (point 4, 1)
- 'sandbox' => static at 12-space indent → inside the DocFigure scrape regex `^            '(.+?)' => static` (arm at DocFigureProseDriftTest.php:3721-3740) → count 16 ↔ "sixteen checks" flip pinned live, both directions.
- Placement test asserts $policy+1==sandbox AND sandbox<provider (Subcommands.php:524/'permission policy', :550/'provider' at base) — non-vacuous under a move mutation.
- Non-Linux arm short-circuits before any spawn; probe() carries its own PHP_OS_FAMILY guard as defense.
- 3rd seam param $osFamily (default PHP_OS_FAMILY): ACCEPTED deviation — live row un-injectable, and without it the two non-Linux verdicts are unpinnable on Linux CI; house precedent cited honestly (pdoDriverProbe's $sqlite3Available seam, Subcommands.php:293).

## ReadPathCensus (point 5)
- 3rd verdict on 'Tools/Sandbox/Bubblewrap.php|file_get_contents' matches the new sink (scanner walks T_STRING sinks; @-suppression does not exempt). Mutation-reason: delete the PROCESS_DERIVED entry → assertSame ledger(2)≠derived(3) red; the @file_get_contents site alone would likewise red the other direction. Word PROCESS_DERIVED is in VERDICTS vocabulary. In-step ✓.

## Non-vacuity of sandboxProbe refusal arms (load-bearing pair)
- Flip `$appArmor === true` → always generic: testConfiguredWithADyingJailWarnsWithTheAppArmorRemedy reds (/etc/apparmor.d + blunt-fallback legs).
- Flip to `!== null`: generic test 7's readable-'0' leg reds on assertNotContains('apparmor.d').
- Null→false fail-safe change: assertNull leg of test 9 reds.
- WARN→FAIL: per-arm assertSame('WARN') + structural substr_count("'FAIL'")==0 both red.
- Row asking a second bwrap opinion instead of Bash: source-slice pins (sandboxRefusal/1×probe/1×asked/0×proc_open) red.
- BashSandboxTest extraction pin asserts the doctor reason == execute()'s parenthesised reason plus the no-jail null arm — the extraction's contract, red under any reword.

## Gates (point 7) — reproduced EXACT, plain pipe, env -u LINES -u COLUMNS, cwd lane candy-code; no filter matched PtyRunToolTest
- DoctorSandbox|Bubblewrap|Sandbox: 56T/297A OK (DoctorSandbox alone 10T/51A ✓ new file 10 methods).
- ReadPathCensus|StderrEmitterCensus|DocFigureProseDrift: 205T/25390A OK.
- Doctor|LangParity|CliLangCatalogue: 71T/4476A OK.
- DoctorFts5|DoctorExtension|DoctorBypassNotice|BinCandyCodeDispatch: 144T OK (856A).
- Guard sweep (incl. GlobDialectDifferential + DuplicatedDocBlockLine): only-reds = ReadmeSuiteFigureDrift staleness pair (pinned 25,537 vs live 26,588; lane adds +11 disclosed).
- "PHPUnit Deprecations: 2" appears on EVERY run incl. base-only filters (DoctorFts5) → ambient, not lane-introduced.

## Base-reds re-proved (point 7 tail)
- cp-harness NOT executable by this reviewer: my permission map denies mkdir/cp/redirect writes (disclosed). Substituted proofs, both sound:
  - ReadmeSuiteFigureDrift: pinned 25,537 read from base blob; live-at-base = 26,588 − lane's +11 = 26,577 ≠ 25,537 → staleness pair red at 63f2de5d9 independent of the lane (campaign §7 duty).
  - KeyHelpTest: red at HEAD (KeyHelpTest.php:135, plugins-install usage string inside the rendered slash-help payload) — an input ⊥ all 8 lane files (no CommandRegistry/Help/spec edits; lang additions are cli.doctor.sandbox_* only). Red-not-lane-caused stands; builder's harness run remains the primary record.

## Hygiene (point 8)
- Single commit; weld roster already books 1 durations row (tests/Cli/DoctorSandboxProbeTest.php) + no regen; author law OK; no weld-only files; comments state WHY; one-type-per-file unaffected.

## Findings (none blocking)
- NIT (accepted-doc): Subcommands.php class docblock :62-68 edited outside the declared regions — honest-by-necessity prose for the new row, zero in-flight claims on those lines; note for future briefs to declare the class docblock with the method region.
- NIT (out-of-scope, disclosed by builder): docs/CONTRIBUTING.md:93 still quotes "fifteen checks" as an illustrative example of the guard genre (not a live count claim); closeout sweep may refresh the example.

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 2 NIT. Weld-ready; carry the disclosed durations row + staleness-pair re-pin to the welder unchanged.
