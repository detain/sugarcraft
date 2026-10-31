# C-TUI-6.b — REVIEW-1 (round 1, fresh reviewer, read-only)

VERDICT: FINDINGS
C=0 M=2 MINOR=4 NIT=2

Lane `/home/sites/cc-cmp-C-TUI-6.b`, branch `cc/C-TUI-6.b`, base `288b81f11`, one pick
`705e099fa`, porcelain 0. Real diff-stat (re-derived): **12 files, +3567/−0** (the dispatch
brief's "10 files +1229/−3" is wrong vs disk; the 12-path LIST matches the design block).

## Findings (detail in the per-question sections)

- **MAJOR-1 — `STARTUPINFOW` in the cdef is 16 bytes smaller than Windows'.**
  `sugar-pty/src/Windows/Kernel32ConPty.php:85-102` declares, after `cb`, a single pointer
  (`lpTitle`) where Microsoft's `STARTUPINFOW` has THREE (`lpReserved`, `lpDesktop`, `lpTitle`).
  Every field from `dwX` onward therefore sits 16 bytes early: measured here
  `sizeof(STARTUPINFOW)=88`, `sizeof(STARTUPINFOEXW)=96`, while the published x64 layout is
  **104 / 112** (4+4pad+8·3 pointers, 8·DWORD=32, 2·WORD=4, 4pad, 8·5 pointers… = 104; EX = +8).
  Combined with `Kernel32ConPty.php:608` `$startup->StartupInfo->cb = FFI::sizeof($startup->StartupInfo)`
  → `cb` = 88. Two consequences on the real host: CreateProcessW validates `cb` against the
  header size (and, with `EXTENDED_STARTUPINFO_PRESENT`, MSDN requires `cb = sizeof(STARTUPINFOEXW)`
  = 112, not the inner struct's size — that is a second, independent error at the same line), and
  `dwFlags`/`wShowWindow` written at :612-613 land in the wrong bytes, so the
  "SW_HIDE stops a console window" claim at :610-611 is void.
  **Fix:** declare `lpReserved` and `lpDesktop` before `lpTitle`; set
  `cb = FFI::sizeof($startup)` (the EX struct); re-derive the layout table in the test.
- **MAJOR-2 — pointer-to-int: `(int) $info->hProcess` is not a handle.**
  `sugar-pty/src/Windows/Kernel32ConPty.php:652-653`
  (`$process = (int) $info->hProcess; $thread = (int) $info->hThread;`). `hProcess`/`hThread` are
  `void *` struct members; a pointer-typed `FFI\CData` does not convert with `(int)`. Measured on
  this host through the lane's own FFI: value **1**, plus PHP warning
  `Object of class FFI\CData could not be converted to int` (reproduced in
  `/tmp/opencode/cc-cmp-C-TUI-6b/AbiProbe3Test.php`, `--display-warnings` output quoted below).
  So on Windows `createProcess()` returns `process=1`, `closeHandle(1)` at :660 closes a bogus
  handle, and `waitForSingleObject`/`getExitCodeProcess`/`terminateJobObject` would name the wrong
  object; `assignProcessToJob` (:663) fails loudly, and the `closeHandle()` guard at :693-697
  ("0 and -1 are what 'no handle' arrives as from the paths above") rests on the same false premise.
  The house idiom already exists two libraries over: `sugar-core/src/Util/Tty/Kernel32.php:152`
  and `:371` use `(int) \FFI::cast('intptr_t', $h)->cdata`.
  **Fix:** `$process = (int) \FFI::cast('intptr_t', $info->hProcess)->cdata;` (same for `$thread`).
  Note also that sugar-pty's `phpunit.xml` carries `failOnWarning="true"`, so once 6.c puts the
  Windows leg in CI this line reddens the run on the warning alone — the defect cannot survive
  contact with a real host silently, which is why this is MAJOR and not CRITICAL (the namespace is
  unreachable by design until 6.c).

## 1. REACHABILITY — as designed, LOUD, no fallback

Nothing on a live path constructs the new backend. Tree-wide grep for
`Pty\Windows|Kernel32ConPty|ConPtyApi|EnvBlock|CommandLine` outside `src/Windows/` +
`tests/Windows/` returns exactly one hit, a docblock `@see` in
`sugar-pty/src/Exception/UnsupportedPlatformException.php:58`. The selector
`sugar-pty/src/PtySystemFactory.php:87-92` still maps
`Linux|Darwin|BSD|Solaris → PosixPtySystem` and `default → throw
UnsupportedPlatformException::forPosixOnly($platform)`, i.e. Windows stays a loud refusal —
pinned pre-existing by `sugar-pty/tests/PtySystemFactoryTest.php:65-96`
(`testWindowsThrowsUnsupportedPlatformException`, message names the platform).
That matches the design block: 6.b is primitives only, and `C-TUI-6.c` owns the factory change,
`WINDOWS_LIBS` and the candy-code wiring. `ConPtyApi.php:40-44` states the same boundary plainly
("this namespace is unreachable from PtySystemFactory on any host").
On this host `Kernel32ConPty::supported()` returns a reason string and `self()` refuses with
`UnsupportedPlatformException` carrying it — both halves pinned by
`Kernel32ConPtyTest.php:111-137`. No silent fallback, no swallowed exception. **No finding.**

## 2. ABI — cdef strings read myself

- `DWORD`/`UINT` = `typedef unsigned int` (never `unsigned long`) ✓, and the pin is real, not
  decorative: `Kernel32ConPtyTest.php:152+` regex-pins the typedef, asserts the file does NOT
  contain `unsigned long`, AND measures `sizeof(DOWN)==4`. Because of that spelling the
  Linux-LP64 measurement is legitimate evidence for Windows-LLP64: probe through the lane's FFI
  gave `DWORD 4 · COORD 4 · PROCESS_INFORMATION 24 ·
  JOBOBJECT_BASIC_LIMIT_INFORMATION 64 · IO_COUNTERS 48 · JOBOBJECT_EXTENDED_LIMIT_INFORMATION 144`
  — all equal to the Windows x64 header values. The failure at MAJOR-1 is fidelity to the header
  (two dropped fields), not an LP64/LLP64 lie.
- `wchar_t` appears nowhere in the cdef (grep over `TYPES_CDEF`/`FUNCTIONS_CDEF`/`PROBE_CDEF`) ✓,
  pinned by `testNoDeclarationUsesWcharTBecauseFFIDoesNotKnowThatType`; wide data is carried as
  `unsigned char` byte buffers + `void *`/`const void *` params (probe: `FFI::addr($array)` in a
  `void *` slot works — `strlen` of both the addr-form and the direct form returned the expected
  length).
- `HRESULT` typed `int` ✓ and `COORD` by value ✓, each with its own signature-regex pin;
  `CreatePseudoConsole(COORD size, intptr_t, intptr_t, DWORD, intptr_t *phPC)` and
  `ResizePseudoConsole(intptr_t, COORD)` match Microsoft exactly. All HANDLE/HPCON params are
  `intptr_t`; out-params are `DWORD *`/`SIZE_T *`/`intptr_t *`; `CreateJobObjectW` returns
  `intptr_t` (so its `(int)` read is a scalar read, fine);
  `UpdateProcThreadAttribute`'s `DWORD_PTR Attribute` typed `SIZE_T` (same width) ✓;
  `WaitForSingleObject` returns `DWORD`, and `ConPtyApi.php:100-118` spells `WAIT_FAILED`
  `0xFFFFFFFF` as the unsigned value that read produces (docblock says so) ✓;
  `JOB_OBJECT_EXTENDED_LIMIT_INFORMATION = 9`, `JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE = 0x2000`,
  `STILL_ACTIVE = 259`, `WAIT_TIMEOUT = 0x102` all correct; the 19-export roster is checked
  bidirectionally against the prototype block.
- **arm64-Darwin lesson (fixed-arg `ioctl(int,unsigned long,void*)`): does NOT recur.** There is
  no variadic/fixed-arg mismatch anywhere in this cdef — every pointer argument is declared as a
  pointer type at its own position, and the only by-value struct is `COORD`, which Microsoft also
  passes by value. The two defects found here are in the adjacent family (pointer→int on a struct
  member read, MAJOR-2, and struct field fidelity, MAJOR-1), not the calling-convention shape.
- Split types/functions cdef so the types parse with a null lib: I reproduced the underlying fact —
  `FFI::cdef('void f(void);', null)` throws `Failed resolving C function` here, so the split is
  load-bearing, not cosmetic.

## 3. Injection / security posture

`CommandLine::escape()` (`src/Windows/CommandLine.php:96-141`) implements the MSVCRT rules
correctly: NUL refused with the reason; unquoted pass-through only when nothing in
`NEEDS_QUOTES = [' ',"\t","\n","\v","\r",'"']` is present; backslash runs doubled only where they
abut a quote (`str_repeat('\\', $backslashes*2) . '\\"'`), left literal elsewhere; the trailing run
doubled before the closing quote. `toArgv()` is an **independent parser written from the parser
side** (src :160-240, the odd/even backslash-run + quote-state rules), which is what makes the
pins behavioral rather than an echo of `escape()`.
Can an argument escape its quoting? No for `"` and for whitespace/newline/tab/`\v`/`\r`:
`tests/Windows/CommandLineTest.php:104-120` carries hand-written expectations (`a"b` →
`"a\"b"`, `a\"b` → `"a\\\\\"b"`, `a b\` → `"a b\\\\"`, `say "hi"` → `"say \\"hi\\""`) and **each row
asserts both directions** (:129-130, `toArgv($expectedLine) === [$argument]`), and
`testRoundTripCorpus` (:133-156) round-trips 9 corpus rows over `a"b"c`, `d\`, `e=f`, trailing
backslashes, bare `"`/`\`/`\\`/`\"`, newline and tab. The CRT quirks that porters get wrong are
pinned in both polarities: adjacent quoted runs merge (`:192`) vs a space between them separates
(`:195`), an odd backslash run leaks a literal quote without changing delimiter state (`:198-204`),
and an empty argument becomes an explicit `""` that does not shift the next token (`:60-74`).
- **The `&`/`|`/`^`/`%` decision:** such an argument passes through **UNQUOTED** (src :104-109 and
  the `NEEDS_QUOTES` list). That is right for `CreateProcessW` itself — no shell is invoked, so
  `cmd /c` metacharacters in an argv element are data to the loader; the design block and
  `CommandLine.php:15-27` say exactly this, and the docblock reason (quoting where it is not needed
  makes the trailing-backslash doubling matter when it need not) is sound. Adversarial example, for
  the record: `fromArgv(['php','x','&','del','/q'])` → `php x & del /q`. Harmless to
  CreateProcessW; **not** harmless if a later step sets `applicationName` to `cmd.exe` and this
  element rides as `/c`'s operand, because cmd re-tokenises the tail. No test pins either half of
  that boundary → MINOR-2 below (pin the decision, and say the cmd.exe caveat in the docblock,
  before the step that spawns a Windows shell).
- Nothing unsanitised is placed unquoted: `createProcess()` wide-buffers the finished line
  (:603), the program name, and cwd, and `EnvBlock::encode()` is called at the sole env site
  (:625-629) so the UTF-16 block and `CREATE_UNICODE_ENVIRONMENT` can never disagree.
- **`EnvBlock`** (`src/Windows/EnvBlock.php`): inheritance intent is right — the block is passed
  only when a caller supplies a non-empty map (:625), otherwise `lpEnvironment` is NULL and the
  child inherits the parent environment wholesale; `fromProcess()` (:131-142) uses argument-less
  `getenv()` (with a `variables_order` rationale for not trusting `$_ENV`) so a caller can forward
  the real parent block. Forged-key defence: `=` inside a name, leading `=`, empty name, NUL in
  either half, and non-string values are all refused, each with its own test
  (`EnvBlockTest.php:115-176`, plus `testANameWithAZeroLowByteCharacterDoesNotFakeATerminator`
  :263 — the character whose UTF-16 unit's low byte is 0 cannot forge a separator, which is the
  cleverest attack on a byte-joined block and it is pinned). Sorting is case-insensitive on the
  UTF-16 bytes of `mb_strtoupper(name)` with a whole-entry tie-break, and order is
  input-independent (:83-113) — that is what Windows needs for its binary-search dedupe; the
  double-NUL block terminator and the empty-map-still-terminated case are pinned (:40,:52).
  Case-colliding names both survive in a defined order and `hasCaseCollisions()` is offered rather
  than enforced (:103 + API) — a deliberate, disclosed choice, not a hole.

## 4. Gates — MY numbers

cwd=`/home/sites/cc-cmp-C-TUI-6.b/sugar-pty`, `env -u LINES -u COLUMNS vendor/bin/phpunit -c
phpunit.xml`, plain pipe, anchored exact-class filters.

- Builder's group `(CommandLineTest|EnvBlockTest|WideStringTest|Kernel32ConPtyTest|
  UnsupportedPlatformExceptionTest|EnvRosterTest)` → **88T / 1014A / 0F / 2S, OK (exit 0)**.
  Builder claimed 88T/1010A/0F/2S: tests, skips and failures AGREE; the assertion figure is 4 high
  and lives entirely in `EnvBlockTest` (mine **499A**, claimed 495) → NIT-1.
- Per file: `CommandLineTest` **OK (19 tests, 48 assertions)** ✓claim · `EnvBlockTest` **OK (16, 499)**
  (claim 495) · `WideStringTest` **OK (11, 33)** ✓ · `Kernel32ConPtyTest` **13T, 65A, Skipped 2** ✓ ·
  `UnsupportedPlatformExceptionTest` **OK (15, 42)** ✓ · `EnvRosterTest` **OK (14, 327)** ·
  `PtySystemFactoryTest` **OK (16, 27)** ✓.
- The 2 skips ARE the Windows-gated pair, skipped by name via `requireWindowsConPty()`
  (`Kernel32ConPtyTest.php:419`): `--display-skipped` shows both carry
  "ConPTY is unavailable here: this host is Linux, and ConPTY is a Windows API". Neither is a
  silent failure and neither is new.
- Full lib suite (allowed: it is this lane's own lib): **771T / 3789A / 0F / 0E / 19S, exit 0**,
  54.6s. Conservation arithmetic agrees with the builder: the new files add 19+16+11+13+4 = 63
  tests, and 771 − 63 = **708** = the sugar-pty base figure at this era.
- Tools guards, run from the lane: `--filter "OneTypePerFile|BareCitationDrift|ChildLifetimes"`
  over `tools/tests` → **OK (26 tests, 62 assertions)**.

## 5. Vacuity / mutation reasoning

- `supported()` + `self()` refusal: delete the gate in `self()` and `Kernel32ConPtyTest.php:129-136`
  reddens twice over — it fails on the success path AND would surface `\FFI\Exception` instead of
  the expected `UnsupportedPlatformException` (unexpected exception type = red). Non-vacuous.
- `forConPtyUnavailable`: the reason interpolation is load-bearing under
  `testForConPtyUnavailableReasonsDistinguishTheFourCauses` (:95-119) — it asserts 4 unique
  messages AND cross-excludes each other cause, so a message that dropped the reason, or
  genericised it, reddens; `…PointsAtTheHostNotTheIssueTracker` (:124-132) is a real absence arm
  (`assertStringNotContainsString('github.com', …)`), and the four-cause list is written out
  literally rather than derived.
- Layout pins: `testTheLayoutOnlyHoldsBecauseDWORDIsNotSpelledUnsignedLong` genuinely discriminates
  respelling `DWORD` (the sizeof leg moves). But **MINOR-1**: the headline layout test
  (`testTypesBlockParsesAndTheStructLayoutsMatchTheWindowsNumbers`, :139-150) compares the cdef
  against `EXPECTED_SIZES` whose arithmetic was derived from that same cdef — its own docblock at
  :50-59 says "cb 4, pad 4, lpTitle 8" and skips straight past the two pointers that come first.
  It proves the code is itself, exactly the failure mode its comment claims to avoid, and it is how
  MAJOR-1 got certified. A second leg, `:410-411`, hardcodes 88/96 and would need the same flip.
- `testCreatePseudoConsoleTakesTheSizeStructByValue` and
  `testTheAvailabilityProbeHasNoSideEffects` read the implementation source through a
  brace-matched `methodBody()` helper with `assertNotFalse` staleness arms — they would catch a
  `FFI::addr($size)` reintroduction and a probe that starts allocating consoles. Good genre.
- **FakeConPtyApi does not stand in for the FFI layer.** It implements the whole `ConPtyApi`
  interface (`testTheTestDoubleSatisfiesTheContractItWasBuiltFor` forces every interface method to
  exist, so the double cannot silently fall behind) and its lifecycle test is strict where it
  matters (`closeJob` true-then-false, `WAIT_FAILED` after job close, `PtyException` 'not live' on
  late resize/close, `everythingIsClosed()`). But precisely because it replaces the interface, the
  real `Kernel32ConPty` FFI bodies — `:596-666` wide buffers, `cb`, `lpAttributeList`, the handle
  cast — are executed only by the two Windows-gated tests that cannot run here. So the double is
  not over-permissive; it is *orthogonal*, and both MAJORs hide in the strip it cannot reach. That
  is the honest boundary the design block drew ("layouts only proven on a real Windows host"); my
  point is that MAJOR-2 was provable right here and was not.

## 6. In-step guards

- `DuplicatedTestHelperDriftTest` (lives at `candy-code/tests/Support/`): its corpus is candy-code's
  own `tests/` (walk resolves from `dirname(__DIR__, 2)`; `grep` for any other package's root finds
  none), so it cannot see `sugar-pty/tests/Windows`. Ran it anyway: **14T / 202A / 2F** and both
  failures name foreign candy-code offenders only — `titleBackend: Chat/AutoCommitTurnTest.php vs
  Chat/AwayRecapTest.php vs Chat/BtwCommandTest.php` (:502) and `drift T0:
  Host/ResumeCostEstimateTest.php vs Tui/AwayTrackerTest.php` (:2839) — the known foreign pair,
  un-moved, not this lane's. Manual census instead, across the five new test files +
  `FakeConPtyApi`: helpers/consts are `entries()` (EnvBlockTest), `expect()` (WideStringTest),
  `explicitWindowsShapes()` (CommandLineTest), `EXPECTED_SIZES`/`EXPORTS`/
  `requireWindowsConPty()`/`methodBody()` (Kernel32ConPtyTest), `guard()`/`guardLive()`/`issue()`
  (FakeConPtyApi) — **no shared name, no byte-identical private const, no one-token divergence**
  (grep over the rest of `sugar-pty/tests` for those names: zero hits).
- `tools/check-one-type-per-file.php` → green via `OneTypePerFileToolTest` in the 26T run.
- `check-child-lifetimes`: zero `proc_open`/`popen`/`shell_exec`/`exec(`/`passthru`/`system(`/
  `pcntl_fork` anywhere in the 12 new/modified files (grep, exit 1 = no matches) → nothing new for
  the roster; `ChildLifetimesToolTest` green in the same run.
- `EnvRosterTest` (this lib's own README↔`getenv` census, every name not just a prefix): **14T
  green inside the 88T group**. The only env read in the new src is argument-less `\getenv()` at
  `EnvBlock.php:142` — no new named variable, claim confirmed.
- `BareCitationDriftTest`: `tools/tests/BareCitationDriftTest.php:99` shows
  `ROOTS = ['/tools', '/sugar-pty']` and resolves the repo root from `dirname(__DIR__, 2)`
  (cwd-independent), so the green run genuinely covered the new sugar-pty files → the builder's
  "caught a real offender in my own CommandLineTest docblock and fixed it in-step" is consistent
  with disk: `CommandLineTest.php` now cites `{@see testTheWholeCdefResolvesAgainstKernel32()}`
  etc. only in forms that resolve, and `Kernel32ConPtyTest.php:39-43` names its own gated pair.
- `DescriptorSinkArgumentCensusTest` (lives at `sugar-core/tests/Util/Tty/`): **could NOT be
  executed in this lane** — sugar-core has no seeded vendor here and the test needs its own
  `SugarCraft\Core\Tests\…\DescriptorSinkScanner` class (my run through sugar-pty's autoload failed
  with `Class …DescriptorSinkScanner not found`; honest disclosure, not a green I didn't earn).
  Answered from the guard's own text instead: its vocabulary is
  `FUNCTION_SINKS = ['posix_isatty','posix_ttyname','fcntl']` +
  `STATIC_SINKS = ['TermiosFactory::open','SizeIoctl::query']` (plus `dup2/dup3/fchdir` discussed
  at `DescriptorSinkScanner.php:177`), walked over every `*/src` in the monorepo. grep for that
  entire vocabulary across `sugar-pty/src/Windows`: **zero hits**. So no roster row is owed, and the
  builder's "no descriptor sink added" claim is TRUE as the guard is written.
  → MINOR-3 (out-of-scope): Win32 `HANDLE` sinks (`ReadFile`/`WriteFile`/`CloseHandle`) are a
  descriptor-sink class this census does not model at all; when 6.c makes the Windows leg live, the
  close-on-every-path obligation for handles wants either a widening of this census or a sibling
  named in `sugar-pty` itself (a published lib cannot depend on `tools/`).

## 7. Scope + foreign reds + the i18n question

- Scope: the pick's 12 paths are exactly the design block's declared set — 5 `src/Windows/*`
  create, `src/Exception/UnsupportedPlatformException.php` modify (+`forConPtyUnavailable(string
  $reason): self`, and the diff is +30/−0, so no pre-existing method was touched), 4 new
  `tests/Windows/*Test.php` + `tests/Windows/FakeConPtyApi.php` (support double, correctly not a
  `*Test` file) + `tests/Exception/UnsupportedPlatformExceptionTest.php` modify with exactly 4 new
  methods (:78,:95,:124,:136). No edit outside those files, no hub file involved.
- Weld-only/forbidden files: `git diff --name-only 288b81f11 705e099fa --
  candy-code/tests/Config/Support/suite-figure.json scripts/parallel-tests-durations.tsv
  candy-code-web/dist` → **empty**. Untouched ✓. For §7 instead, the four new test files are
  `sugar-pty/tests/Windows/{CommandLineTest,EnvBlockTest,WideStringTest,Kernel32ConPtyTest}.php`
  (+ `tests/Exception/UnsupportedPlatformExceptionTest.php` modified); sugar-pty is not a
  candy-code shard lib, so no `durations.tsv` row and no suite-figure movement is owed by this lane.
- Foreign reds adjudicated, none cured, none counted here: `DuplicatedTestHelperDriftTest` 2F =
  exactly the known set (named above, §6). No NEW red family appeared in anything I ran: sugar-pty
  full suite 0F/0E, tools guards 26T OK, factory/exception groups OK.
  `ReadmeSuiteFigureDrift`/`ApplySettingsTest`/`KeyHelpTest`/`HelpTest`/`BinCandyCodeDispatchTest`/
  `AttachPagingTest`/`TreeWideGuardRoster` were not in the filters I ran (this lane touches zero
  candy-code paths), so I neither re-litigated nor cleared them.
- **i18n — ruling, not a hedge:** the raw English in the new files is the **accepted lib
  convention**, not a step-local MINOR. Evidence: the file being modified already spells its two
  existing factories in raw English (`UnsupportedPlatformException.php:40-45`
  `'SUGARCRAFT_PTY_BACKEND=%s is not implemented in v1…'`), the whole `src/Exception/` directory
  contains **zero** `Lang::t(` calls, and sibling POSIX-path diagnostics do the same
  (`src/ControllingTerminal.php:45` `'ControllingTerminal is POSIX-only.'`).
  `src/Lang.php` in this lib carries the handful of strings that reach a *display* surface
  (`Spawn.php` 5 sites, `Pty.php` 1, `Posix/PosixProcess.php` 2). These new messages are
  developer/operations-facing exception diagnostics — the `Lang::t()` law in the design block is
  about `candy-code` user-facing strings, which is 6.d's territory. Do not flip this lane.

## Minor/NIT ledger

- MINOR-1 (step-local): the layout proof is self-referential — re-derive `EXPECTED_SIZES`
  (`Kernel32ConPtyTest.php:74-82`) from the header's full field list, name each field in the
  arithmetic (including `lpReserved`/`lpDesktop`), and fix the hardcoded 88/96 at :410-411. Add a
  `cb == FFI::sizeof(STARTUPINFOEXW)` pin in the same commit as MAJOR-1/2 so the Windows leg has
  one assertion that cannot be satisfied by the cdef agreeing with itself.
- MINOR-2 (step-local): pin the `&`/`|`/`^` pass-through decision with a test row in
  `explicitWindowsShapes()` (e.g. `'a&b' => 'a&b'` plus its `toArgv` round-trip) and state the
  cmd.exe caveat in `CommandLine.php:15-27`; the reason 6.d's shell spawn matters is untested today.
- MINOR-3 (out-of-scope, closeout): Win32 HANDLE sinks unmodelled by
  `DescriptorSinkArgumentCensusTest` — see §6.
- MINOR-4 (out-of-scope, §7 rider): `forConPtyUnavailable()`'s trailing sentence "it is not
  selected by `SUGARCRAFT_PTY_BACKEND` yet" is TRUE now and is pinned by
  `UnsupportedPlatformExceptionTest.php:124-132` (`assertStringContainsString('SUGARCRAFT_PTY_BACKEND', …)`).
  Whoever lands 6.c must flip the sentence and that pin in-step, or the wiring will be
  contradicted by its own green test.
- NIT-1 (step-local): report figure drift — `EnvBlockTest` is 499 assertions, not 495 (group total
  1014, not 1010).
- NIT-2 (dispatch-side, recorded for the orchestrator): the brief's "10 files +1229/−3" vs disk's
  "12 files +3567/−0".

## Evidence for MAJOR-2 (verbatim, from the lane's own FFI on PHP 8.3.6/Linux)

```
int-cast-nonnull=1 ffi-cast=0 addr-of-target=130040027959528
1 test triggered 1 PHP warning:
1) /tmp/opencode/cc-cmp-C-TUI-6b/AbiProbe3Test.php:35
Object of class FFI\CData could not be converted to int
```
