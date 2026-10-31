# C-TUI-6.b — round-1 fix report (fixer, 2026-10-30/31)

Review: `REVIEW-1.md` (23,485 B) — `VERDICT: FINDINGS C=0 M=2 MINOR=4 NIT=2`.
Branch `cc/C-TUI-6.b`, base `288b81f11`. Builder pick `705e099fa` UNAMENDED.
Round-1 commits: `dd8b4fec7` (MAJOR-1 src + MAJOR-2 first attempt),
`8ee0d173e` (MAJOR-2 final shape, src), `7953a45d1` (MINOR-1/MINOR-2 +
MAJOR-2 pins, tests + CommandLine docblock). Tip `7953a45d1`, tree clean,
`git status --porcelain` empty. All three: author/committer
Joe Huss <detain@interserver.net> (`%ae`/`%ce` separate `grep -c detain`
= 1/1 each), zero Co-Authored-By.

## MAJOR-1 — STARTUPINFOW cdef dropped two pointer fields; cb under-sized
`src/Windows/Kernel32ConPty.php`: added `void *lpReserved; void *lpDesktop;`
before `lpTitle` (three pointers after `cb`, per
https://learn.microsoft.com/en-us/windows/win32/api/processthreadsapi/ns-processthreadsapi-startupinfow);
`cb` line is now `$startup->StartupInfo->cb = \FFI::sizeof($startup);` — the
outer STARTUPINFOEXW size that `EXTENDED_STARTUPINFO_PRESENT` requires.
Machine-checked after fix: sizeof STARTUPINFOW=104, STARTUPINFOEXW=112
(pre-fix measured 88/96, matching the reviewer).

## MAJOR-2 — `(int)` on FFI void* members; DEVIATION from the brief's literal idiom (measured evidence)
`(int)` on a `void *` member answers a garbage value + emits
`Object of class FFI\CData could not be converted to int` (breaks
failOnWarning on the Windows leg). The review prescribed sugar-core's
`(int) \FFI::cast('intptr_t', $h)->cdata` verbatim — implemented in
`dd8b4fec7`, then MEASURED UNSAFE HERE: crash-isolated matrix
(/tmp/opencode/cc-cmp-C-TUI-6b/idiom-matrix.php) shows the cast SEGFAULTS
(rc=139) when the source CData is a pointer-typed struct MEMBER — FFI
lands the result storage at the pointer's VALUE and `->cdata`
dereferences it (same mechanism as the known `cast('void*', scalar
CData)` crash). sugar-core's spelling is safe only because its source is
a scalar-typed (intptr_t) function RETURN. A HANDLE is a pointer-SIZED
OPAQUE VALUE — dereferencing it is garbage/AV on real Windows too, not
just under FFI.
FINAL SHAPE (commit `8ee0d173e`): the five handle struct slots
(hProcess, hThread, hStdInput, hStdOutput, hStdError) are declared
`intptr_t` — identical LLP64 8-byte slot, consistent with how this
file's every FUNCTION declaration already models handles — and the reads
are plain `(int) $info->hProcess;`. Zero cast machinery, zero
deprecations, layout pins unaffected (hStdInput@80, hThread@8, totals
104/112/24 re-verified). lp* members stay `void *`. This is the
review's INTENT (no int-cast of a pointer CData) via the one conversion
shape that is provably silent; disclosed here rather than obeying the
letter into a segfault.

## MINOR-1 — the self-proving layout test, cured with an independent source
`tests/Windows/Kernel32ConPtyTest.php` no longer certifies the cdef from
cdef-derived numbers:
- `EXPECTED_SIZES` rewritten as hand-transcribed Windows totals (104/112,
  per-field arithmetic shown, MSDN cited) — the gated round-trip legs
  flipped 88/96 → 104/112.
- `MSDN_LAYOUT`: per-field OFFSET table transcribed from the API pages
  (cb@0 lpReserved@8 lpDesktop@16 lpTitle@24 dwX@32 … hStdInput@80
  hStdOutput@88 hStdError@96; PROCESS_INFORMATION hProcess@0 hThread@8
  dwProcessId@16 dwThreadId@20; lpAttributeList@104).
- `testEveryDeclaredFieldLandsWhereWindowsDeclaresIt`: PHP FFI has no
  offset-of, so each field is stamped with a distinct low marker in a
  fresh memset instance and the first little-endian 2-byte occurrence of
  its signature must equal the transcribed offset. Missing fields fail
  BY NAME.
- `testTheExtendedStructPlacesItsAttributeListAfterTheWholeStartupInfo`
  + `testCreateProcessSetsCbToTheOuterExtendedStructSize` (structural
  regex on createProcess: `cb = \FFI::sizeof($startup)`, forbids
  `sizeof($startup->StartupInfo)`).

### Discrimination proofs (all restores md5-verified; never git-checkout on dirty)
- OLD BUILDER FILE (`git show 705e099fa:…` swapped in, new tests run):
  **6 reds** incl. `STARTUPINFOW has no field lpReserved — the binding
  dropped a member the Windows declaration names` and
  `a handle slot refused an integer store — … assigning to type 'void*'
  from PHP 'int'` — i.e. MINOR-1's new pin FAILS the broken cdef by name:
  the exact certification path MAJOR-1 slipped.
- R2 (cb reverted to inner sizeof): exactly the cb pin RED (1F).
- R4 (only the five handle decls flipped back to void*, rest fixed):
  exactly 3 reds — layout probe (names hStdInput), structural
  declaration pin, behavior round trip. (3F)
- Restored src: file green (18T, 108A, 2S gated pair).

## MINOR-2 — unquoted `& | ^` pinned + cmd.exe caveat
`CommandLineTest::explicitWindowsShapes` gained row
`shell metacharacters are inert data` (a|b^c&d%e round-trips to one
token, no quoting injected). Caveat placed in CODE — the
`CommandLine.php` class docblock "Why cmd.exe metacharacters are NOT
quoted" section — because the lib README has no such section and the
class docblock is what a consumer of `toCommandLine()` actually reads:
CreateProcessW never starts a shell so quoting would inject literal
quotes; the inertness is CONDITIONAL — a later cmd.exe-spawning step
must escape or re-arm this pin.

## NIT-1 correction carried
EnvBlockTest is 16T/**499A** (builder's 495A was wrong); builder-group
re-measure at fixer tip: **94T/1055A/0F/2S** (baseline 88T/1014A; +6
methods, the 2 Windows-gated legs skip BY NAME and stay skipped).

## Gates at tip 7953a45d1 (committed tree)
FULL sugar-pty suite, cwd=sugar-pty `-c phpunit.xml`, plain pipe:
**777T/3830A/0F/0E/19S exit 0** (~54 s; baseline 771/3789/19S).
Root guards from the lane: check-child-lifetimes rc=0,
check-one-type-per-file rc=0, BareCitationDriftTest 12T/15A OK.
Zero new proc_open; no new *Test.php FILES (§7 durations: none owed —
all additions are methods/rows in the two existing files).

## Not touched (per lead)
MINOR-3: DescriptorSinkArgumentCensus stays a §7 merged-tree gate
re-verify (lane sugar-core vendor absent; vocabulary = zero src/Windows
hits ⇒ no row owed). MINOR-4: the
`not selected by SUGARCRAFT_PTY_BACKEND yet` sentence pinned at
UnsupportedPlatformExceptionTest:124-132 left byte-identical — sibling
C-TUI-6.c flips it in-step. No i18n change (raw English ruled the lib
convention in src/Exception/**). No PtySystemFactory wiring (6.c).
Foreign dirt: `census-bootstrap.php` (457 B) in this findings dir is
the reviewer's MINOR-3 scratch — LEFT in place, not lane content,
not deleted; REPORT.md/REVIEW-1.md untouched. Forbidden set (plan_
comparison.md, suite-figure.json, durations.tsv, dist, protocol trio,
run-state, .opencode/* …) untouched; no composer/refresh-deps/caliber.
