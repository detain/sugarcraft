# C-TUI-6.c — sugar-pty ConPTY Contract adapters + factory + WINDOWS_LIBS

STEP=C-TUI-6.c LANE=/home/sites/cc-cmp-C-TUI-6.c branch cc/C-TUI-6.c
Base cut 29929c305; ff-merged to c785f3ef8 (C-TUI-6.j + C-TOOL-6.c weld lands, zero sugar-pty overlap) BEFORE first pick.

## Picks
- S1 5aade73ed `C-TUI-6.c: sugar-pty ConPTY Contract adapters over ConPtyApi` — 10 files +1551: src/Windows/{ConPtySystem,ConPtyPair,ConPtyMasterPty,ConPtySlavePty,ConPtyChild}.php + tests/Windows/{ConPtySystemTest,ConPtyMasterPtyTest,ConPtyChildTest,ConPtyLiveTest}.php + tests/Contract/InterfacesTest.php adapters-implement-contracts leg. %ae/%ce detain 1/1, zero trailers.
- S2 d919da7ea `C-TUI-6.c: wire ConPtySystem into PtySystemFactory, conpty backend, README/CALIBER truth` — 6 files +152/−48: src/PtySystemFactory.php (VALID_BACKENDS gains 'conpty'; new() conpty arm gates PHP_OS_FAMILY BEFORE any Kernel32ConPty touch — Windows→new ConPtySystem(), else throw forConPtyUnavailable(Kernel32ConPty::supported() ?? fallback); forPlatform 'Windows'=>new ConPtySystem(); docblock dependency-free + deferred-FFI-at-open law), src/Exception/UnsupportedPlatformException.php (class docblock v2-sidecar wording dropped; forPosixOnly message now names the two shipped backend families and keeps the github.com tracker pointer; :72 tail flipped to 'SUGARCRAFT_PTY_BACKEND=conpty selects it once those conditions hold.' — the flip 6.b's landed mark authorized), tests/PtySystemFactoryTest.php (testWindowsResolvesTheConPtySystem = Linux-runnable instanceof pin, fails-before by construction; Extends/Actionable legs re-routed to forPlatform('Unknown') pinning Unknown+ConPTY+http, stale 'v2' pin dropped; 'conpty' joined the unrecognised-roster pin; testBackendConptyResolvesTheConPtySystemOnWindows dual-shape — Windows instanceof / non-Windows manual-catch pins 'ConPTY','Windows API','SUGARCRAFT_PTY_BACKEND', NOT 'github.com'; testFactorySourceKeepsFfiBehindTheAvailabilityProbe via php_strip_whitespace: contains 'Kernel32ConPty::supported()', NOT 'Kernel32ConPty::self()'/'new Kernel32ConPty'/'FFI::cdef'/'$system->open()'), tests/Exception/UnsupportedPlatformExceptionTest.php (forPosixOnly legs drive forgery-proof 'Haiku' platform; V2Reference leg → testForPosixOnlyStatesTheTwoShippedBackendFamilies; forDeferredBackend('conpty')→('future-ffi') since conpty is no longer deferred; github.com / platform / ConPtyUnavailable-trio / catch legs kept), sugar-pty/README.md 7 region flips (Status, quickstart call-site + inline comment, env table 'conpty' row with probe-reason note, gaps sentence, Windows matrix cell, Backend-selection table conpty row + auto-row wording, Known limitations Windows ConPTY caveats incl pumps-stay-POSIX-only and 8.3-cell scoping), sugar-pty/CALIBER_LEARNINGS.md 3 entries ([pattern:conpty-adapter-zero-ffi], [pattern:dependency-free-construction-refusal-at-open], [pattern:conpty-job-teardown] incl FakeConPtyApi-final⇒createMock note). %ae/%ce 1/1, zero trailers.
- S3 7610e1b07 `C-TUI-6.c: add sugar-pty to WINDOWS_LIBS + Windows CI scoping + MATCHUPS/site rows` — 6 files +15/−9: scripts/affected-libs.php WINDOWS_LIBS append 'sugar-pty'; .github/workflows/ci.yml windows-test 'Run tests' step gains elseif (Test-Path ${{ matrix.lib }}\tests\Windows) { php ...\tests\Windows\ } branch + lead comment naming the sugar-pty scoping rationale; docs/MATCHUPS.md row flip 'Windows ConPTY landed (Windows\ConPtySystem over FFI primitives: Windows 10 1809+ / PHP 8.4+; no ctty/signals/fd — POSIX keeps those.)' + (Linux + macOS + Windows) status cell + L104 future-row comment → landed; docs/_data/sugar-pty.json description extension (one line beyond the design-named region — disclosed); docs/_data/sugar-pty.body.html limitations bullet → Windows ConPTY caveats; docs/lib/sugar-pty.html regenerated via php tools/gen-docs.php (diff exactly 2 lines, deterministic). %ae/%ce 1/1, zero trailers.

## 6.b reuse (substrate extended, never duplicated)
- ConPtyApi interface + Kernel32ConPty intptr_t TYPES_CDEF substrate: REUSED as-is, zero edits (adapters talk only to the ConPtyApi seam).
- CommandLine::fromArgv REUSED for the CreateProcessW command line; EnvBlock consumed via api->createProcess(environment:) which 6.b already encodes.
- FakeConPtyApi (6.b test support) REUSED as the injection harness for all adapter pins; failure injection via createMock (FakeConPtyApi is final).
- intptr_t law held BY CONSTRUCTION: adapters declare no cdef/FFI at all — pinned by static scan test (ConPtySystemTest::testAdaptersReferenceNoFfiSurfaceOfTheirOwn, token_get_all over the 5 src files, zero ffi/cdef code tokens).
- Linux open() refusal routes through 6.b Kernel32ConPty::supported() gate (osFamily FIRST, zero FFI touch) — pinned by testOpenRefusesOnThisHostNamingTheReason.

## Gates (targeted only, lib-local cwd=sugar-pty, env -u LINES -u COLUMNS plain pipe)
- filter 'Windows': Tests: 109, Assertions: 843, Skipped: 3 — OK (6.b baseline was 68T/693A/2S; +41T = 40 adapter/factory files +1 S2 Windows-resolves leg matching the substring filter; +1 skip = ConPtyLiveTest).
- filter 'InterfacesTest': Tests: 53, Assertions: 166 — OK.
- filter 'PtySystemFactoryTest|UnsupportedPlatformExceptionTest': Tests: 33, Assertions: 80 — OK.
- filter 'EnvRosterTest': Tests: 14, Assertions: 345 — OK (README env-table guard rode the S2 flip in-step).
Per-file live counts: ConPtySystemTest 16T/60A, ConPtyMasterPtyTest 15T, ConPtyChildTest 9T, ConPtyLiveTest 1T (skipped off-Windows).
Repo-root tools (from lane root): php tools/gen-docs.php regen ok (115 pages); gen-docs.php --check rc0; tools/check-docs-assets.php rc0 'in sync'; tools/check-path-repos.php --no-lib-path-repos rc0 (62 libs); php -l clean on every touched PHP file.
ci.yml YAML VERIFIED (not UNVERIFIED): php -r yaml_parse_file rc0, jobs=13, windows-test 'Run tests' step string contains 'tests\Windows'.

## UNCONFIRMED-from-Linux legs
- ConPtyLiveTest (real cmd /c echo spawn/resize/wait) — SKIPPED here by design; the Windows 8.4 CI cell executes it. Labelled UNCONFIRMED until that run.
- scripts/affected-libs.php stdout probe head printed empty in first 300 bytes (output-shape guess); the WINDOWS_LIBS constant edit is trivial and ci build-matrix consumes it — disclosed, not chased.

## Durations manifest note
New sugar-pty test files: scripts/parallel-tests-durations.tsv carries only candy-code paths, so sugar-pty/tests/Windows rows are NOT implied by the manifest rule for this lib (lib-local phpunit discovers tests/ from phpunit.xml). Gate decides.

## Deviations + seams
- docs/_data/sugar-pty.json description line edited one file beyond the design's named body.html region (kept site/json truthful pair for gen-docs --check). Disclosed.
- ConPtySlavePty::spawn widens cols/rows to ?int=null defaults (PosixSlavePty precedent; interface note allows it) so a no-arg spawn keeps the current console geometry instead of forcing 80x24.
- SEAM REPORTED NOT CURED: sugar-pty/README.md 'Library layout' section still says Contract is 'Upcoming POSIX implementation' and carries no src/Windows row — outside the five design-named README regions.

## Laws
Author Joe Huss <detain@interserver.net> on all three picks (%ae count=1, %ce count=1, Co-Authored-By count=0 each, verified same-shell). Staged-hunk residue grep 'if (false|&& false|| true' no matches before each commit. No full suite run anywhere; no pushes; forbidden files untouched; plan_comparison.md untouched.
