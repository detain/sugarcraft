# C-TUI-6.b — sugar-pty ConPTY FFI primitives

## Status: IMPLEMENTED, gates green on Linux; Windows-gated pair skipped by name

Lane `cc/C-TUI-6.b` @ `/home/sites/cc-cmp-C-TUI-6.b`, base master `288b81f11`.

## What landed

`files.create` (all five, exactly as the design block lists them):
- `sugar-pty/src/Windows/ConPtyApi.php` — the contract. Pseudo-console trio, pipe
  pair + `PeekNamedPipe`/`ReadFile`/`WriteFile`, the `*ProcThreadAttributeList`
  trio + `CreateProcessW`, wait/exit-code/`CloseHandle`, and the job-object quartet
  (`CreateJobObjectW` + `SetInformationJobObject` with `KILL_ON_JOB_CLOSE` fused
  into `createJob()`, `AssignProcessToJobObject`, `TerminateJobObject`,
  `closeJob`). Instance methods, handles as plain `int`, `supported()` as a
  four-question no-side-effect probe. Mirrors sugar-core's
  `Kernel32`/`Kernel32Interface` precedent.
- `sugar-pty/src/Windows/Kernel32ConPty.php` — the cdef + implementation.
- `sugar-pty/src/Windows/CommandLine.php` — `fromArgv()`/`escape()` by the MSVCRT
  rules, plus `toArgv()` written from the `CommandLineToArgvW` side so the
  round-trip corpus is evidence about the contract and not about itself.
- `sugar-pty/src/Windows/EnvBlock.php` — UTF-16LE, case-folded-sorted,
  double-NUL-terminated block; refuses `=`/NUL in names and NUL in values;
  `fromProcess()` snapshot that drops the per-drive `=C:` entries.
- `sugar-pty/src/Windows/WideString.php` — UTF-8 <-> UTF-16LE, `codeUnits()`
  (surrogate pairs = 2, which is what `lstrlenW` counts and `mb_strlen` does not).

`files.modify`: `sugar-pty/src/Exception/UnsupportedPlatformException.php` —
`forConPtyUnavailable(string $reason)` added; `forPosixOnly()` and
`forDeferredBackend()` untouched.

Tests: `tests/Windows/{CommandLineTest,EnvBlockTest,WideStringTest,Kernel32ConPtyTest}.php`
created, `tests/Windows/FakeConPtyApi.php` created (support double, no `Test`
suffix, `@internal`), `tests/Exception/UnsupportedPlatformExceptionTest.php`
modified (+4 methods).

## Design deviations, all deliberate and each pinned

1. **`DWORD` is declared `unsigned int`, not `unsigned long`.** PHP's FFI parser
   uses the HOST data model: `unsigned long` is 8 bytes on this Linux box (LP64)
   and 4 on Windows x64 (LLP64). Spelling `DWORD` as `unsigned long` makes every
   struct in the file lay out differently on the host that tests it than on the
   host that runs it. With `unsigned int` the layout is identical on both, and the
   struct-size assertions in `Kernel32ConPtyTest` are then real evidence about
   Windows. `testTheLayoutOnlyHoldsBecauseDWORDIsNotSpelledUnsignedLong` pins the
   spelling so the claim cannot rot. (sugar-core's `Kernel32` uses
   `unsigned long` and is therefore untestable off-Windows by construction —
   reported below, not copied forward.)
2. **No `wchar_t` anywhere.** Measured: `FFI::cdef("wchar_t c;")` throws
   `ParserException("Undefined C type \"wchar_t\"")` on PHP 8.3.6, so sugar-core's
   console cdef could not parse here at all. Wide parameters are `const void *`
   fed from `WideString::terminate()`. Pinned by
   `testNoDeclarationUsesWcharTBecauseFFIDoesNotKnowThatType`.
3. **`CreatePseudoConsole`/`ResizePseudoConsole` return `int`, not `intptr_t`.**
   `HRESULT` is 32-bit; declaring a pointer-sized return reads the undefined upper
   half of `rax` on Windows x64, which turns `S_OK` into a nonzero number. Pinned
   by `testThePseudoConsoleCallsDeclareAHRESULTNotAPointerSizedReturn`, and
   `COORD size` by value is pinned separately.
4. **`createProcess()` takes an `array<string,string>` environment rather than a
   pre-encoded block**, and sets `CREATE_UNICODE_ENVIRONMENT` itself, so the bytes
   and the flag can never disagree. It also adds
   `EXTENDED_STARTUPINFO_PRESENT` itself (without it `CreateProcessW` ignores the
   attribute list and the child starts with no console — a hang, not an error).
5. **`createProcess()` closes the primary thread handle** and returns
   `array{process,processId}` only: a returned-but-unmentioned thread handle is a
   leak per session.
6. **`Kernel32ConPty::unsafe()`** exists beside `self()` so a test can assert about
   the binding on a host where `supported()` says no.

## What Linux proves / does not (the honest half)

PROVES, in `Kernel32ConPtyTest`, on this box:
- the types block parses and `COORD`=4, `STARTUPINFOW`=88, `STARTUPINFOEXW`=96,
  `PROCESS_INFORMATION`=24, `JOBOBJECT_BASIC_LIMIT_INFORMATION`=64,
  `IO_COUNTERS`=48, `JOBOBJECT_EXTENDED_LIMIT_INFORMATION`=144 — sizes whose
  arithmetic is derived field-by-field in the test's own doc-block;
- the FULL cdef parses: reaching symbol resolution is parse evidence, and the
  phase order is measured (a syntax error on the LAST line of a block whose first
  function is unresolvable still reports `ParserException`), so a prototype typo
  anywhere in the twenty declarations reddens
  `testTheWholeCdefParsesEvenThoughNothingResolvesHere`;
- all nineteen exports are declared exactly once and the roster is bidirectional;
- `supported()` refuses with a reason and `self()` refuses loudly with that reason
  embedded; and `supported()` is side-effect-free by a matched-brace source scan of
  its own body only.

DOES NOT prove (skipped by name until C-TUI-6.c puts sugar-pty in the Windows CI
pool): that the symbols behave as declared on a real host, and that `COORD` by
value survives the actual call. `testTheWholeCdefResolvesAgainstKernel32` and
`testPseudoConsoleRoundTripOnARealHost` are gated on `Kernel32ConPty::supported()`
and skip as "ConPTY is unavailable here: this host is Linux, and ConPTY is a
Windows API".

## Gates run (targeted, exact-class)

- `sugar-pty`: `--filter '(CommandLineTest|EnvBlockTest|WideStringTest|Kernel32ConPtyTest|UnsupportedPlatformExceptionTest|EnvRosterTest)'`
  -> **88 tests, 1010 assertions, 0 failures, 2 skipped** (the Windows pair).
- `sugar-pty/tests/Windows/*` per file: CommandLineTest **19T/48A**,
  EnvBlockTest **16T/495A**, WideStringTest **11T/33A**, Kernel32ConPtyTest
  **13T/65A + 2S** (the Windows pair); UnsupportedPlatformExceptionTest
  **15T/42A** (11T at base, +4 methods).
- `tools/check-one-type-per-file.php` -> **62 libs, 3320 psr-4 files, 0 problems**.
- `tools/check-child-lifetimes.php` -> **61 libs, 33 proc_open sites, 9 findings,
  9 accounted, 0 problems** — unchanged: no `proc_open` added, as the design block
  predicted (`CreateProcessW` is not a PHP spawn; the job object is the
  containment).
- `tools/tests/BareCitationDriftTest.php` -> **12T/15A green**. It caught one real
  offender during the lane: my `CommandLineTest` doc-block cited
  `testEmptyArgumentSurvivesAsAnEmptyArgument()`, a method that did not exist
  (the shipped name is `testEmptyArgumentDoesNotShiftTheArgumentsAfterIt`). Fixed
  in-step; the guard is what found it.
- sugar-core `DescriptorSinkArgumentCensusTest` -> 20T/58A, **1 failure, foreign**.
  `sugar-core/tests/` has no `vendor/` in this worktree, so it was run through
  `candy-code/vendor/bin/phpunit` with a lane bootstrap
  (`.../lanes/C-TUI-6.b/census-bootstrap.php`). The two rows it names are
  `candy-code/src/App/App.php::->close($key)` and
  `candy-code/src/Protocol/Methods/SessionMethods.php::->close($imported->sessionId)`
  — neither file is in this lane's diff (`git diff --name-only 288b81f11..HEAD` is
  sugar-pty only), and **no `sugar-pty/src/Windows/**` row appears**, which is the
  half that matters here: this step adds no descriptor sink. See §7 note below.

## Guards NOT moved, because nothing they police moved

No new env var is read by name (`EnvRosterTest` green, `fromProcess()`'s
argumentless `getenv()` is already in `NAMES_NOTHING`); no README/API figure
changed; no `Lang::t()` key added (sugar-pty's exception factories are raw
`sprintf` literals by file-local precedent — see the i18n note below); no roster,
census or word-number in any doc page moved.

## Tip and final verification (disk-checked)

- tip `705e099fa` on `cc/C-TUI-6.b`, `git rev-list --count 288b81f11..HEAD` = **1**
  (one cohesive commit; the intermediate "part 1" commit was amended away),
  `git cat-file -t 705e099fa` = `commit`, 12 files / 3567 insertions,
  `%ae` and `%ce` each grep to `detain` separately, zero `Co-Authored` lines in the
  message, `git status --porcelain` empty.
- Re-run at the tip: lane filter **88 tests / 1010 assertions / 0 failures / 2
  skipped**; `PtySystemFactoryTest` **16T/27A green** (the exception file is the
  factory's own throw site, so that pairing is the regression check for
  `forPosixOnly()`/`forDeferredBackend()` surviving the edit).
- The worktree's `prompt_kit/findings/comparison-1026/lanes/C-TUI-6.b/` scratch
  (a pre-test smoke probe) was deleted before the commit; this report and
  `census-bootstrap.php` live in the main checkout's evidence directory, which the
  weld commits.

## For §7 / the lead (not cured here — outside this step's files)

1. **Foreign red, deterministic:** the sugar-core descriptor census fails at base
   on two `->close(...)` sites in `candy-code` (`App/App.php`,
   `Protocol/Methods/SessionMethods.php`). The census matches method sinks
   receiver-blind, so a `SessionStore::close(string $id)`-shaped method is accused
   beside libc's `close(int $fd)`. Either those two names need a judgement row or
   the matcher needs a parameter-shape veto. Not this lane's file; numbers above.
2. **Finding, sugar-core (not touched):** `SugarCraft\Core\Util\Tty\Kernel32`'s
   cdef spells `DWORD` as `unsigned long` and declares `const wchar_t *`
   parameters. Both are unparseable/incorrect on any non-Windows host
   (measured: `wchar_t` -> `ParserException`), so that binding's layout is
   untestable-by-construction and its `GetConsoleScreenBufferInfo(void *lpInfo)`
   carries the console-buffer struct by `void *`. sugar-pty's new binding takes
   the other spelling deliberately; if a lane ever folds them, the `unsigned int`
   and `const void *` choices are the ones to keep.
3. **i18n ruling to confirm:** the project law says user strings go through
   `Lang::t()`. sugar-pty HAS `src/Lang.php` + `lang/en.php`, but all three
   existing factories in `UnsupportedPlatformException` are raw literals, and
   `lang/en.php` is not in this step's `files.modify`. `forConPtyUnavailable()`
   therefore follows the file's own precedent. One-line change if the lead wants
   the key (`conpty.unavailable`) instead.
4. **§7 durations:** new `*Test.php` files needing TSV rows —
   `sugar-pty/tests/Windows/CommandLineTest.php`,
   `sugar-pty/tests/Windows/EnvBlockTest.php`,
   `sugar-pty/tests/Windows/WideStringTest.php`,
   `sugar-pty/tests/Windows/Kernel32ConPtyTest.php`.
   `sugar-pty/tests/Windows/FakeConPtyApi.php` is NOT a test (no `Test` suffix) and
   needs no row. sugar-pty's own suite figures are lib-local; candy-code's
   `suite-figure.json` is unaffected (zero candy-code files in this diff).
5. **The arm64-Darwin `ioctl` landmine does not apply to this step** — no libc
   call and no `ioctl` is added here; the ConPTY resize path is
   `ResizePseudoConsole` over a struct by value, and `COORD`-by-value is pinned by
   `testCreatePseudoConsoleTakesTheSizeStructByValue`. The FFI-availability gate
   (`supported()`'s four questions) is what C-TUI-6.c's factory will call.
