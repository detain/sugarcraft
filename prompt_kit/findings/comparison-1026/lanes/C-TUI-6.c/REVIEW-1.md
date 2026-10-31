VERDICT: FINDINGS — C=0 M=0 MINOR=1 NIT=2 · WELD-ELIGIBLE YES (live §7 figures below supersede the report's off-by-one)
STEP=C-TUI-6.c LANE=/home/sites/cc-cmp-C-TUI-6.c reviewer=r1 date=2026-10-31 background=true-confirmed

## Scope verified on disk
- branch cc/C-TUI-6.c; base c785f3ef8; exactly 3 picks at tip 7610e1b07:
  5aade73ed (10 files: src/Windows/{ConPtySystem,ConPtyPair,ConPtyMasterPty,ConPtySlavePty,ConPtyChild}.php + tests/Windows/{ConPtySystem,ConPtyMasterPty,ConPtyChild,ConPtyLive}Test.php + tests/Contract/InterfacesTest.php) · d919da7ea (PtySystemFactory.php, Exception/UnsupportedPlatformException.php + PtySystemFactoryTest/UnsupportedPlatformExceptionTest — both PRE-EXISTING at base, modified — README, CALIBER; 6 files +152/−48) · 7610e1b07 (ci.yml, MATCHUPS, _data/sugar-pty.{json,body.html}, docs/lib regen, affected-libs.php; 6 files +15/−9). Cumulative 22 files +1718/−57.
- Author law: %ae/%ce separate greps = detain 3/3; Co-Authored-By 0. Builder REPORT stat'd 7,504 B, read fully. Design block design-tui.md ~:764 read; every named file landed as declared; no undeclared paths.

## Q1 FFI intptr_t law — PASS
- Adapters declare zero cdef/FFI: token hits are docblock prose only (ConPtySystem.php:26, ConPtyMasterPty.php:186).
- Guard ConPtySystemTest::testAdaptersReferenceNoFfiSurfaceOfTheirOwn (:333-368): token_get_all over the 5 src files, skips ONLY T_COMMENT/T_DOC_COMMENT/T_CONSTANT_ENCAPSED_STRING, concatenates remaining token text, asserts preg_match('/ffi/i',$code)===0 AND no 'cdef' substring. Mutation-reasoned: any future FFI::/cdef code token (T_STRING/T_NAME_QUALIFIED) lands in the concatenated text → red. NOT a zero-match vacuity — it is a substring scan of ALL executable tokens, and it ran green inside my 16T file run. Executed mutation rejected (read-only law); label: reasoning-static, guard structurally sound.
- Substrate keeps the intptr_t law (6.b welded shape, untouched here).

## Q2 Factory gating (Linux safety) — PASS
- PtySystemFactory::new() 'conpty': PHP_OS_FAMILY==='Windows' → new ConPtySystem() (dep-free ctor); else Kernel32ConPty::supported() (PHP_OS_FAMILY checked FIRST, :280-311, pre-FFI) reason → UnsupportedPlatformException::forConPtyUnavailable. Factory never calls self()/new Kernel32ConPty/open() — pinned by testFactorySourceKeepsFfiBehindTheAvailabilityProbe (php_strip_whitespace, 1 positive + 4 negative needles).
- ConPtySystem::open() (:68-95) is the refusal choke (Kernel32ConPty::self() throws). testOpenRefusesOnThisPosixHostNamingTheReason asserts ConPTY/'Windows API'/SUGARCRAFT_PTY_BACKEND present, github.com absent → Linux-safety proven by execution here.
- ConPtyLiveTest: skip guard :28-32 (non-Windows), second guard PHP<80400||!ffi, uniqid probe, LIVE_CEILING_SECONDS=15.0 per-op bounds — skip env-gated-and-honest; ran SKIPPED=1 in every shape. Nothing Windows-only EXECUTES here except pure adapter construction on FakeConPtyApi doubles.
- UnsupportedPlatformException.php:72 tail flip is AUTHORIZED VERBATIM by the 6.b §4 weld mark (plan_comparison.md:3574 MINOR-4: "sibling C-TUI-6.c flips it in-step (W35, also owns PtySystemFactory wiring)"). In-step, same commit as the factory. ✓

## Q3 Contract completeness — PASS
- InterfacesTest gained testConPtyAdaptersImplementTheContracts: 5-entry map (ConPtySystem=>PtySystem, ConPtyPair=>PtyPair, ConPtyMasterPty=>MasterPty, ConPtySlavePty=>SlavePty, ConPtyChild=>Child) is_subclass_of loop + capabilities() key-order pin ['conpty','controllingTerminal','signals','fd']. Mutation-reason: dropping an 'implements' reds the loop; the ConPtySystemTest assertInstanceOf legs + factory instanceof pins are the second net.
- Master/Child polarity pins are strong: null-on-timeout vs ''-on-EOF (:55-74), 128+signal exit codes (SIGKILL→137), exit-code-read-BEFORE-closeJob ordering via transcript indices, INFINITE-wait→WAIT_TIMEOUT→throw anti-forge, destructor containment.
- Non-zero sanity (anchored class name in output, no namespaced zero-hit): `--filter InterfacesTest` → "OK (53 tests, 166 assertions)" EXACT builder claim.

## Q4 CI wiring — PASS (two exec legs UNCONFIRMED-BY-EXEC, interpreters denied)
- ci.yml windows-test gains elseif (Test-Path <lib>\tests\Windows) → runs tests\Windows\ only, after sugar-core's Tty branch, before full-suite else. ONLY sugar-pty among WINDOWS_LIBS carries tests/Windows (ls -d proof) → no sibling hijack. Builder's '8 occurrences' wording = affected-libs.php WINDOWS_LIBS internals; the landing entry 'sugar-pty' verified; ci.yml has zero textual WINDOWS_LIBS (dynamic matrix via build-matrix — AGENTS.md-sanctioned).
- YAML re-parse: UNVERIFIED-BY-EXEC (php/python interpreters denied in this lane); static read of the run block well-formed. scripts/affected-libs.php diff static-coherent (design :30 cite honored).
- MATCHUPS 'Windows ConPTY planned'→'landed' + platforms '+ Windows'; _data body.html limitations bullet; docs/lib/sugar-pty.html diff carries exactly the json-description line + body.html limitations line → the _data/sugar-pty.json edit (disclosed +1 file) is WITHIN gen-docs regen-derivation: input pair json+body.html → generated page. JUDGED: not scope-creep. gen-docs --check re-run UNCONFIRMED-BY-EXEC (php denied); docs/lib byte-consistency argument above is the static substitute.

## Q5 Reuse-not-duplicate — PASS
- 6.b substrate (ConPtyApi/Kernel32ConPty/CommandLine/EnvBlock/WideString + tests/Windows/{FakeConPtyApi,Kernel32ConPty,CommandLine,EnvBlock,WideString}Test) present at base, ABSENT from all 3 picks + cumulative diff → ZERO-DIFF CONFIRMED by name.
- Reuse cites: CommandLine::fromArgv at ConPtySlavePty.php:103; EnvBlock via api->createProcess(environment: $env ?? []) — Kernel32ConPty:642 skips encode+CREATE_UNICODE flag on empty array, matching ConPtyApi docblock 'an empty array inherits this process's block' → coherent.
- spawn cols/rows ?int=null widening mirrors PosixSlavePty precedent — coherent, disclosed.
- README 'Library layout' (:560-566, 'two layers', no src/Windows row, stale 'Upcoming POSIX implementation' column header context) genuinely outside the seven flipped README regions → report-only adjudication ACCEPTED for the lead ledger.

## Q6 Hygiene + lib-local gates — EXECUTED in this lane (cwd=sugar-pty, env -u LINES -u COLUMNS vendor/bin/phpunit, plain pipe; NEVER candy-code suite)
- Tests: 109, Assertions: 843, Skipped: 3 (--filter 'Windows') — EXACT.
- Tests: 820, Assertions: 4015, Skipped: 20 (FULL sugar-pty suite, 53.9s) — base 777/3834/19S + 40 Windows-file tests + 1 InterfacesTest leg + 2 factory/exception net legs = 820 EXACT; skips 19+1 Live=20 EXACT. Posix legs + contract family regression-free.
- Tests: 53, Assertions: 166 (InterfacesTest) · 33/80 (factory pair) · 14/345 (EnvRosterTest) — ALL EXACT builder claims.
- Per-file: ConPtySystemTest 16T/60A ✓ · ConPtyMasterPtyTest **14T/27A — builder claimed 15T (MINOR-1)** · ConPtyChildTest 9T/58A + ConPtyLiveTest 1T skip ✓ (pair 10T/59A/1S).
- one-type-per-file ✓ (5 new src files, one final class each, strict_types line 3); residue greps 0; GlobDialect: uniqid probe, no glob-shaped literals; README/CALIBER rode d919da7ea in-step with factory (3 new CALIBER patterns name real pins); durations.tsv N/A — lib-local (candy-code-only file; §7 GATE decides the Windows-row policy inherited from the 6.b rider: 4 files 6.b + 4 files 6.c = ConPtySystem 16T/60A, ConPtyMasterPty 14T/27A, ConPtyChild 9T/58A, ConPtyLive 1T/1S).

## Findings
- MINOR-1 (step-local, REPORT accuracy): builder REPORT per-file table says ConPtyMasterPtyTest "15T"; live file run = 14T/27A, and the full-suite 820 arithmetic independently proves 14 (a 15th leg would make 821). §7/ledger mint must carry 14T/27A. No code change; fix = ledger wording at weld.
- NIT-1: PtySystemFactory.php:39-41 docblock ends with a stray blank line + trailing space inside the comment block ('layer.\n\n */'). Cosmetic PSR-12.
- NIT-2: ConPtySlavePty spawn docblock says the env 'map REPLACES' the parent block; for the EMPTY map it inherits (Kernel32ConPty:642). Prose precision only.

## Weld note for the lead
- Touches root surfaces: .github/workflows/ci.yml, scripts/affected-libs.php, docs/MATCHUPS.md, docs/_data/sugar-pty.*, docs/lib/sugar-pty.html (regen ride — welder commits as landed; no protocol trio, no candy-code-web/dist, zero candy-code src/test paths in range → no en.php/lang union, no suite-figure/durations implication). Matchups/_data are MATCHUPS-union candidates if a sibling weld moved them since c785f3ef8 — re-derive on merged tree.
- Battery: WINDOWS_LIBS now includes sugar-pty → the windows-test leg runs tests\Windows on Windows runners; Linux legs unaffected (verified by the 820T full-suite here).
- README 'Library layout' staleness → lead ledger (report-only, adjudicated accurate).
