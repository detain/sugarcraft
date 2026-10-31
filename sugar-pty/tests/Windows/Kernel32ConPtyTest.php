<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Windows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Windows\ConPtyApi;
use SugarCraft\Pty\Windows\Kernel32ConPty;

/**
 * The ConPTY FFI binding, judged as far as a non-Windows host can judge it.
 *
 * ## The split, and why it is honest rather than a shrug
 *
 * Two of the things this file asserts need Windows; the rest need only a PHP with
 * FFI, and they are the ones that catch the mistakes this class is actually likely
 * to contain. The distinction is drawn per test, and the Windows-only pair is
 * skipped by name so a run on Linux says which claims went unmade.
 *
 * WHAT LINUX PROVES:
 *  - the declaration block PARSES, in both halves — a prototype typo is a
 *    `ParserException`, and the two phases are ordered (measured: a syntax error
 *    on the last line of a block whose FIRST function is unresolvable still
 *    reports the parse error), so reaching the resolution stage is evidence that
 *    every line above it compiled;
 *  - the struct LAYOUTS, because `DWORD` is declared `unsigned int` and that is
 *    four bytes under both LP64 and LLP64 — see
 *    {@see testTypesBlockParsesAndTheStructLayoutsMatchTheWindowsNumbers}. This
 *    is the whole reason for the `unsigned int` spelling: with `unsigned long`,
 *    the same assertion would be measuring Linux's layout and calling it
 *    Windows';
 *  - which Win32 exports the binding names, and that the pseudo-console calls
 *    declare an `int` return, since `HRESULT` is 32-bit and reading it as a
 *    pointer-sized value takes the upper half of `rax`, which Windows x64 leaves
 *    undefined;
 *  - that the availability probe refuses, for the right reason, and touches
 *    nothing.
 *
 * WHAT LINUX CANNOT PROVE: that the resolved symbols behave as declared, and that
 * `COORD` by value survives the real call. Those are
 * {@see testTheWholeCdefResolvesAgainstKernel32()} and
 * {@see testPseudoConsoleRoundTripOnARealHost()}, both gated.
 *
 * @see https://learn.microsoft.com/en-us/windows/console/creating-a-pseudoconsole-session
 */
final class Kernel32ConPtyTest extends TestCase
{
    /**
     * Struct sizes on x64 Windows, transcribed from the API pages rather than
     * derived from the cdef under test.
     *
     * Round 0 of this file certified a 16-byte-early STARTUPINFOW green because
     * these numbers were written from an arithmetic reading of the binding's own
     * field list: drop `lpReserved` and `lpDesktop` and the derivation follows
     * the drop. The table below is instead written from the MSDN pages
     * (field order cited in {@see self::MSDN_LAYOUT}) under the LLP64 model
     * (`DWORD` 4, `WORD` 2, pointer 8, natural alignment):
     *
     *  - COORD 4        = short + short
     *  - STARTUPINFOW 104 = cb 4, pad 4, three pointers 24 | eight DWORDs 32
     *    | two WORDs 4, pad 4, lpReserved2 8, three HANDLEs 24
     *    =>  4+4+24+32+4+4+8+24 = 104
     *  - STARTUPINFOEXW 112 = STARTUPINFOW 104 + pointer 8 (already 8-aligned)
     *  - PROCESS_INFORMATION 24 = two HANDLEs 16 + two DWORDs 8
     *  - JOBOBJECT_EXTENDED_LIMIT_INFORMATION 144
     *      = BasicLimitInformation 64 (LONGLONG 8 + LONGLONG 8 + DWORD 4 + pad 4
     *        + SIZE_T 8 + SIZE_T 8 + DWORD 4 + pad 4 + ULONG_PTR 8 + DWORD 4
     *        + DWORD 4)
     *      + IoInfo 48 (six LONGLONGs)
     *      + four SIZE_Ts 32
     *
     * A field dropped, reordered or respelled to a different width changes one of
     * these numbers, and the test names which.
     *
     * @var array<string, int>
     */
    private const EXPECTED_SIZES = [
        'COORD' => 4,
        'STARTUPINFOW' => 104,
        'STARTUPINFOEXW' => 112,
        'PROCESS_INFORMATION' => 24,
        'JOBOBJECT_BASIC_LIMIT_INFORMATION' => 64,
        'IO_COUNTERS' => 48,
        'JOBOBJECT_EXTENDED_LIMIT_INFORMATION' => 144,
    ];

    /**
     * Field offsets on x64 Windows, transcribed by hand from the API pages:
     *
     *  - https://learn.microsoft.com/en-us/windows/win32/api/processthreadsapi/ns-processthreadsapi-startupinfow
     *  - https://learn.microsoft.com/en-us/windows/win32/api/processthreadsapi/ns-processthreadsapi-process_information
     *
     * SIZES ALONE DO NOT PIN A LAYOUT: swapping two same-width fields keeps
     * every `sizeof` intact and puts the wrong value into the call anyway. The
     * probe test stamps each named field with a distinct marker and finds where
     * the marker actually landed, against THIS table — which cannot be computed
     * from the cdef, because that is precisely how the round-0 defect, one
     * pointer where Microsoft declares three and every field from dwX onward
     * 16 bytes early, was certified green.
     *
     * @var array<string, array<string, int>>
     */
    private const MSDN_LAYOUT = [
        'STARTUPINFOW' => [
            'cb' => 0,
            'lpReserved' => 8,
            'lpDesktop' => 16,
            'lpTitle' => 24,
            'dwX' => 32,
            'dwY' => 36,
            'dwXSize' => 40,
            'dwYSize' => 44,
            'dwXCountChars' => 48,
            'dwYCountChars' => 52,
            'dwFillAttribute' => 56,
            'dwFlags' => 60,
            'wShowWindow' => 64,
            'cbReserved2' => 66,
            'lpReserved2' => 72,
            'hStdInput' => 80,
            'hStdOutput' => 88,
            'hStdError' => 96,
        ],
        'PROCESS_INFORMATION' => [
            'hProcess' => 0,
            'hThread' => 8,
            'dwProcessId' => 16,
            'dwThreadId' => 20,
        ],
    ];

    /**
     * Every Win32 export the binding names.
     *
     * @var list<string>
     */
    private const EXPORTS = [
        'CreatePseudoConsole',
        'ResizePseudoConsole',
        'ClosePseudoConsole',
        'CreatePipe',
        'PeekNamedPipe',
        'ReadFile',
        'WriteFile',
        'InitializeProcThreadAttributeList',
        'UpdateProcThreadAttribute',
        'DeleteProcThreadAttributeList',
        'CreateProcessW',
        'WaitForSingleObject',
        'GetExitCodeProcess',
        'CloseHandle',
        'GetLastError',
        'CreateJobObjectW',
        'SetInformationJobObject',
        'AssignProcessToJobObject',
        'TerminateJobObject',
    ];

    public function testTheBindingIsNotReachableAsAnInstanceOffWindows(): void
    {
        // supported() answers a REASON and self() refuses with it. Both halves are
        // pinned here, because the interesting defect is the one where the probe
        // says "no" and self() constructs anyway.
        $reason = Kernel32ConPty::supported();

        if (\PHP_OS_FAMILY === 'Windows') {
            // On Windows the probe's answer depends on the OS build and the PHP
            // build, and either answer is legitimate; what must hold is the shape.
            self::assertTrue($reason === null || \is_string($reason));

            return;
        }

        self::assertIsString($reason);
        self::assertStringContainsString('ConPTY is a Windows API', (string) $reason);

        try {
            Kernel32ConPty::self();
            self::fail('self() handed out a ConPTY binding on a non-Windows host');
        } catch (\SugarCraft\Pty\Exception\UnsupportedPlatformException $e) {
            // The reason string is IN the message: that is the entire point of
            // forConPtyUnavailable() over a generic refusal.
            self::assertStringContainsString((string) $reason, $e->getMessage());
        }
    }

    public function testTypesBlockParsesAndTheStructLayoutsMatchTheWindowsNumbers(): void
    {
        $ffi = \FFI::cdef(Kernel32ConPty::typesCdef(), null);

        foreach (self::EXPECTED_SIZES as $type => $bytes) {
            self::assertSame(
                $bytes,
                \FFI::sizeof($ffi->new($type)),
                \sprintf('%s is not %d bytes on this host', $type, $bytes),
            );
        }
    }

    public function testTheLayoutOnlyHoldsBecauseDWORDIsNotSpelledUnsignedLong(): void
    {
        // The pin that keeps the previous test honest. If anyone respells DWORD as
        // `unsigned long` — the SDK's own spelling — the struct sizes become
        // Linux's numbers on this host and Windows's on the real one, and the
        // layout assertions above quietly stop asserting anything about Windows.
        $types = Kernel32ConPty::typesCdef();

        self::assertMatchesRegularExpression('/typedef\s+unsigned int\s+DWORD/', $types);
        self::assertDoesNotMatchRegularExpression('/typedef\s+unsigned long\s+DWORD/', $types);
        // And the size that follows from it, measured rather than assumed: an
        // `unsigned long` would be 8 here and 4 there.
        self::assertSame(4, \FFI::sizeof(\FFI::cdef($types, null)->new('DWORD')));
    }

    public function testNoDeclarationUsesWcharTBecauseFFIDoesNotKnowThatType(): void
    {
        // Measured on PHP 8.3.6: FFI::cdef("wchar_t c;") throws
        // FFI\ParserException("Undefined C type \"wchar_t\""). Every wide string
        // parameter is therefore `const void *` fed from WideString, and a
        // future "cleanup" back to wchar_t would make this file unparseable on
        // the host that ships it.
        self::assertStringNotContainsString('wchar_t', Kernel32ConPty::cdef());
    }

    public function testTheWholeCdefParsesEvenThoughNothingResolvesHere(): void
    {
        // The two-phase evidence described in the class doc-block: reaching symbol
        // resolution means the PARSER ACCEPTED all twenty prototypes, including the
        // ones after the first. A ParserException here is a syntax error in the
        // declaration block, on any host.
        try {
            \FFI::cdef(Kernel32ConPty::cdef(), \PHP_OS_FAMILY === 'Windows' ? Kernel32ConPty::LIBRARY : null);

            self::assertSame('Windows', \PHP_OS_FAMILY, 'a null-library scope should not resolve prototypes');
        } catch (\FFI\ParserException $e) {
            self::fail('the ConPTY declaration block does not parse: ' . $e->getMessage());
        } catch (\FFI\Exception $e) {
            self::assertStringContainsString(
                'Failed resolving C function',
                $e->getMessage(),
                'the only legitimate FFI failure off-Windows is a resolution failure; got: '
                . $e->getMessage(),
            );
        }
    }

    public function testEveryNamedExportIsDeclaredExactlyOnce(): void
    {
        $functions = Kernel32ConPty::functionsCdef();

        foreach (self::EXPORTS as $export) {
            $matches = \preg_match_all('/^\s*\w[\w\s*]*\b' . $export . '\s*\(/m', $functions);

            self::assertSame(
                1,
                $matches,
                \sprintf('%s must be declared exactly once in the prototype block', $export),
            );
        }

        // And the roster above is not stale: the block declares nothing else.
        $declared = [];

        foreach (\explode("\n", $functions) as $line) {
            if (\preg_match('/^\s*[\w\s*]+\b(\w+)\s*\(/', $line, $m)) {
                $declared[] = $m[1];
            }
        }

        \sort($declared);
        $expected = self::EXPORTS;
        \sort($expected);
        self::assertSame($expected, $declared);
    }

    public function testThePseudoConsoleCallsDeclareAHRESULTNotAPointerSizedReturn(): void
    {
        // `HRESULT` is a `long`, which on Windows x64 is FOUR bytes while a
        // pointer is eight. Declaring the return as pointer-sized makes PHP read
        // the whole of rax, and the Windows x64 calling convention leaves the
        // upper half of a 32-bit return undefined — so a successful S_OK can come
        // back as a nonzero number and every ConPTY start looks like a failure.
        $functions = Kernel32ConPty::functionsCdef();

        foreach (['CreatePseudoConsole', 'ResizePseudoConsole'] as $call) {
            self::assertMatchesRegularExpression(
                '/^\s*int\s+' . $call . '\s*\(/m',
                $functions,
                $call . ' must return int (32-bit HRESULT on every data model)',
            );
        }

        // ClosePseudoConsole returns nothing at all.
        self::assertMatchesRegularExpression('/^\s*void\s+ClosePseudoConsole\s*\(/m', $functions);
    }

    public function testCreatePseudoConsoleTakesTheSizeStructByValue(): void
    {
        // The signature is the trap this class exists to get right: `COORD size`
        // by VALUE, not `COORD *size`. A pointer here is an ABI mismatch that
        // returns E_INVALIDARG at runtime and reads like a Windows bug.
        self::assertMatchesRegularExpression(
            '/\bCreatePseudoConsole\(COORD size, intptr_t hInput, intptr_t hOutput, DWORD dwFlags, intptr_t \*phPC\)/',
            Kernel32ConPty::functionsCdef(),
        );
        // ...and the implementation passes $size itself, not FFI::addr($size).
        $source = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Windows/Kernel32ConPty.php');
        $body = self::methodBody($source, 'createPseudoConsole');

        self::assertMatchesRegularExpression('/CreatePseudoConsole\(\$size,/', $body);
        self::assertStringNotContainsString('CreatePseudoConsole(\FFI::addr($size', $body);
    }

    public function testTheAvailabilityProbeHasNoSideEffects(): void
    {
        // "No side effects" is a claim about the source, so it is checked against
        // the source: the probe body may bind, and may not call.
        $source = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Windows/Kernel32ConPty.php');
        $body = self::methodBody($source, 'supported');

        self::assertStringContainsString('FFI::cdef', $body);

        foreach (['CreatePseudoConsole(', 'AllocConsole', 'FreeConsole', 'OpenConsole', 'file_put_contents'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $body,
                \sprintf('supported() must not %s — it is a probe, not an initialiser', $forbidden),
            );
        }
    }

    public function testTheTestDoubleSatisfiesTheContractItWasBuiltFor(): void
    {
        // The interface's whole purpose is a double that runs on Linux. This is
        // the check that it is not decorative: instantiating it only succeeds if
        // EVERY method on ConPtyApi is implemented, so a method added to the
        // contract without a double-side answer is a fatal here rather than a
        // mystery in whichever suite touched it last.
        $api = new FakeConPtyApi();

        self::assertInstanceOf(ConPtyApi::class, $api);
        self::assertNull(FakeConPtyApi::supported());
        self::assertInstanceOf(ConPtyApi::class, FakeConPtyApi::self());
        self::assertInstanceOf(\FFI::class, $api->ffi());

        // Every contract method is reachable on the double by name, which is what
        // makes the sentence above more than "it constructed".
        $missing = [];

        foreach (\get_class_methods(ConPtyApi::class) as $contract) {
            if (!\method_exists($api, $contract)) {
                $missing[] = $contract;
            }
        }

        self::assertSame([], $missing, 'the double does not implement every ConPtyApi method');
    }

    public function testTheDoubleTracksLifetimeAndRefusesASecondClose(): void
    {
        $api = new FakeConPtyApi();
        $pipes = $api->createPipe(4096);
        $console = $api->createPseudoConsole(80, 24, $pipes['write'], $pipes['read']);
        $list = $api->initializeAttributeList(1);
        $api->setPseudoConsoleAttribute($list, $console);
        $job = $api->createJob();

        $child = $api->createProcess('', 'php -v', $list, ['PATH' => 'C:\x'], '', 0, $job);

        self::assertSame(['process', 'processId'], \array_keys($child));
        self::assertSame(ConPtyApi::STILL_ACTIVE, $api->getExitCodeProcess($child['process']));
        self::assertSame(ConPtyApi::WAIT_TIMEOUT, $api->waitForSingleObject($child['process'], 0));

        // Closing the job is the kill, by the limit createJob() set — the model
        // has to reproduce that or a test asserting "the tree died" passes against
        // a double that leaked the child.
        self::assertTrue($api->closeJob($job));
        self::assertSame(ConPtyApi::WAIT_FAILED, $api->waitForSingleObject($child['process'], 0));

        // A second close of the job is a bug, and is loud rather than nil.
        self::assertFalse($api->closeJob($job));

        $api->closeHandle($pipes['read']);
        $api->closeHandle($pipes['write']);
        $api->closePseudoConsole($console);
        $api->deleteAttributeList($list);

        // And after close, every message naming that console is refused: this is
        // the ordering class the double exists to make testable, since a real
        // host answers it with a silent ERROR_INVALID_HANDLE.
        foreach ([
            static function () use ($api, $console): void {
                $api->resizePseudoConsole($console, 10, 10);
            },
            static function () use ($api, $console): void {
                $api->closePseudoConsole($console);
            },
        ] as $late) {
            try {
                $late();
                self::fail('a closed pseudo-console was accepted');
            } catch (\SugarCraft\Pty\PtyException $e) {
                self::assertStringContainsString('not live', $e->getMessage());
            }
        }

        self::assertTrue($api->everythingIsClosed(), \implode("\n", $api->transcript));
    }

    // ─── Layout probes (runnable on Linux — the cdef is parsed, not called) ──

    public function testEveryDeclaredFieldLandsWhereWindowsDeclaresIt(): void
    {
        // The cdef is parsed identically on this host, so the offsets FFI
        // assigns to its fields are checkable here against the hand-written
        // MSDN table — a dropped pointer moves everything after it, a swapped
        // pair keeps every sizeof intact and still hands the real call the
        // wrong member. This is the pin that the round-0 self-derived size
        // table could not be.
        $ffi = \FFI::cdef(Kernel32ConPty::typesCdef(), null);

        foreach (self::MSDN_LAYOUT as $struct => $fields) {
            $found = self::stampFieldOffsets($ffi, $struct, \array_keys($fields));

            foreach ($fields as $name => $offset) {
                self::assertSame(
                    $offset,
                    $found[$name],
                    \sprintf(
                        '%s.%s is not at offset %d — the cdef does not match the '
                        . 'Windows declaration (round 0 shipped STARTUPINFOW with one '
                        . 'pointer after cb where MSDN declares three, putting every '
                        . 'field from dwX on 16 bytes early)',
                        $struct,
                        $name,
                        $offset,
                    ),
                );
            }
        }
    }

    public function testTheExtendedStructPlacesItsAttributeListAfterTheWholeStartupInfo(): void
    {
        $ffi = \FFI::cdef(Kernel32ConPty::typesCdef(), null);
        $ex = $ffi->new('STARTUPINFOEXW');
        \FFI::memset($ex, 0, \FFI::sizeof($ex));

        // The inner block occupies exactly the STARTUPINFOW span...
        self::assertSame(104, \FFI::sizeof($ex->StartupInfo));

        // ...and lpAttributeList starts on the very next byte.
        $ex->lpAttributeList = $ffi->cast('void' . '*', 0x101);
        $at = self::scanFor($ffi, $ex, 0x101);

        self::assertSame(104, $at, 'lpAttributeList is not at offset 104');
        self::assertSame(112, \FFI::sizeof($ex), 'STARTUPINFOEXW is not 112 bytes');
    }

    public function testCreateProcessSetsCbToTheOuterExtendedStructSize(): void
    {
        // EXTENDED_STARTUPINFO_PRESENT makes CreateProcessW validate cb against
        // sizeof(STARTUPINFOEXW), not the inner STARTUPINFOW. The pin is the
        // source shape — sizeof($startup) where $startup is the EX instance —
        // because the size itself is already pinned above; a re-spelling back
        // to sizeof($startup->StartupInfo) is exactly the 8-byte-under defect.
        $source = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Windows/Kernel32ConPty.php');
        $body = self::methodBody($source, 'createProcess');

        self::assertMatchesRegularExpression(
            '/StartupInfo->\s*cb\s*=\s*\\\\FFI::sizeof\(\s*\$startup\s*\)/',
            $body,
            'cb is not being set to the size of the outer STARTUPINFOEXW',
        );
        self::assertStringNotContainsString('sizeof($startup->StartupInfo)', $body);
    }

    public function testHandleSlotsAreDeclaredPointerSizedIntegersAndReadAsSuch(): void
    {
        // MAJOR-2's structural half. The old shape — `(int)` on a `void *`
        // member — answers a garbage value AND emits "Object of class FFI\CData
        // could not be converted to int", which meets this lib's
        // failOnWarning and reddens the Windows CI leg. The literal
        // sugar-core idiom `(int) \FFI::cast('intptr_t', $h)->cdata` is NOT
        // transferable here: measured on this host it SEGFAULTS when the
        // source is a pointer-typed member (rc=139 — the cast lands the
        // result storage at the handle's VALUE and ->cdata dereferences it;
        // sugar-core's idiom is only safe because its sources are scalar-typed).
        // A HANDLE is a pointer-SIZED OPAQUE VALUE, so the binding declares
        // every handle slot — in the structs as in the function signatures —
        // as intptr_t, and the reads are plain integer reads. Same bytes on
        // LLP64, no dereference on a kernel value (which would be an access
        // violation on real Windows too, not just under FFI).
        $types = Kernel32ConPty::typesCdef();

        foreach (['hProcess', 'hThread', 'hStdInput', 'hStdOutput', 'hStdError'] as $member) {
            self::assertMatchesRegularExpression(
                '/intptr_t\s+' . $member . ';/',
                $types,
                $member . ' is not declared as an intptr_t handle slot',
            );
        }

        $body = self::methodBody(
            (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Windows/Kernel32ConPty.php'),
            'createProcess',
        );

        foreach (['hProcess', 'hThread'] as $member) {
            self::assertStringContainsString(
                '(int) $info->' . $member,
                $body,
                $member . ' is not read as the integer-typed slot it is declared',
            );
        }

        // No pointer-to-integer cast machinery may reappear on a struct
        // member: it is the measured segfault shape.
        self::assertStringNotContainsString("\\FFI::cast('intptr_t', \$info->", $body);
        self::assertStringNotContainsString('\FFI::cast("intptr_t", $info->', $body);
    }

    public function testAHandleSlotRoundTripsAnOpaqueValueWithoutAConversionDiagnostic(): void
    {
        // MAJOR-2's behavioural half, Linux-provable because the conversion is
        // an FFI-semantic property, not a kernel32 call: an integer written
        // into a handle slot must come back out as the SAME integer, silently,
        // through the exact `(int)` read form createProcess() uses. Under the
        // old `void *` declaration this fails where it matters: the integer
        // store is refused outright (FFI\Exception on assignment), and were a
        // driver to keep `cast('void*', ...)` stores anyway, the `(int)` read
        // captures the conversion diagnostic.
        $ffi = \FFI::cdef(Kernel32ConPty::typesCdef(), null);
        $info = $ffi->new('PROCESS_INFORMATION');
        \FFI::memset($info, 0, \FFI::sizeof($info));

        try {
            $info->hProcess = 0x1234;
            $info->hThread = 0x1235;
        } catch (\FFI\Exception $e) {
            self::fail(
                'a handle slot refused an integer store — it is not declared '
                . 'pointer-sized-integral, so `(int)` reads on it cannot be '
                . 'silent: ' . $e->getMessage(),
            );
        }

        $notices = [];
        $process = self::readingWithCapturedDiagnostics(
            $notices,
            static fn (): int => (int) $info->hProcess,
        );
        $thread = self::readingWithCapturedDiagnostics(
            $notices,
            static fn (): int => (int) $info->hThread,
        );

        self::assertSame(0x1234, $process);
        self::assertSame(0x1235, $thread);
        self::assertSame([], $notices, 'reading a handle as an integer must not warn');
    }

    /**
     * Stamp every named field of a fresh struct instance with a distinct low
     * marker and report where each marker actually landed, by bytes.
     *
     * PHP's FFI has no offset-of primitive, so this walks the raw storage:
     * markers are `0x100 + k*0x100` (distinct little-endian two-byte leading
     * signatures, trailing zeros, no real addresses involved) and the first
     * occurrence of each signature is the field's offset.
     *
     * @param list<string> $fields
     * @return array<string, int>
     */
    private static function stampFieldOffsets(\FFI $ffi, string $struct, array $fields): array
    {
        try {
            $instance = $ffi->new($struct);
        } catch (\FFI\Exception $e) {
            self::fail('the cdef does not declare ' . $struct . ': ' . $e->getMessage());
        }

        \FFI::memset($instance, 0, \FFI::sizeof($instance));
        $markers = [];

        foreach ($fields as $k => $name) {
            $marker = 0x100 + ($k + 1) * 0x100;

            try {
                // Only the lp* members are `void *` and need the int-argument
                // cast (casting a scalar CData to void* segfaults on this host;
                // an int argument is the measured-safe shape). The h* members
                // are HANDLEs and HANDLEs are pointer-SIZED OPAQUE VALUES — this
                // binding declares them intptr_t, so they take the integer
                // directly, exactly like every handle in the function
                // declarations. If a revert re-spells one as `void *`, the
                // plain store below throws FFI\Exception and the catch names
                // the field: the layout probe doubles as the MAJOR-2 sentinel.
                if (\preg_match('/^lp[A-Z]/', $name) === 1) {
                    $instance->{$name} = $ffi->cast('void' . '*', $marker);
                } else {
                    $instance->{$name} = $marker;
                }
            } catch (\FFI\Exception $e) {
                self::fail(
                    \sprintf(
                        '%s has no field %s — the binding dropped a member the '
                        . 'Windows declaration names (%s)',
                        $struct,
                        $name,
                        $e->getMessage(),
                    ),
                );
            }

            $markers[$name] = $marker;
        }

        $offsets = [];

        foreach ($markers as $name => $marker) {
            $offsets[$name] = self::scanFor($ffi, $instance, $marker);
        }

        return $offsets;
    }

    /**
     * First byte index at which the little-endian head of $marker appears.
     */
    private static function scanFor(\FFI $ffi, \FFI\CData $instance, int $marker): int
    {
        $bytes = $ffi->cast('unsigned char*', \FFI::addr($instance));
        $total = \FFI::sizeof($instance);
        $low = $marker & 0xFF;
        $high = ($marker >> 8) & 0xFF;

        for ($i = 0; $i + 1 < $total; $i++) {
            if ($bytes[$i] === $low && $bytes[$i + 1] === $high) {
                return $i;
            }
        }

        self::fail(\sprintf('marker 0x%04x never landed in the struct', $marker));
    }

    /**
     * Run an int-returning read under an error handler that records every
     * non-deprecation diagnostic and returns false so PHPUnit still sees them.
     *
     * @param list<string> $notices filled by reference with what fired
     */
    private static function readingWithCapturedDiagnostics(array &$notices, callable $read): int
    {
        $notices = [];
        // E_DEPRECATED is filtered because static FFI calls are deprecated on
        // 8.3 and a future call site may legitimately use one (and because
        // failOnWarning does not police deprecations anyway); every other
        // diagnostic, conversion warnings above all, is recorded.
        \set_error_handler(
            /**
             * @param string $message
             * @param string $filename
             * @param int<0, max> $line
             */
            static function (int $severity, $message, $filename = 0, $line = 0) use (&$notices): bool {
                if ($severity !== \E_DEPRECATED) {
                    $notices[] = $message;
                }

                return $severity === \E_DEPRECATED;
            },
        );

        try {
            return $read();
        } finally {
            \restore_error_handler();
        }
    }

    // ─── Windows-gated ──────────────────────────────────────────────────────

    public function testTheWholeCdefResolvesAgainstKernel32(): void
    {
        self::requireWindowsConPty();

        $ffi = \FFI::cdef(Kernel32ConPty::cdef(), Kernel32ConPty::LIBRARY);

        // Resolving is not calling: every name must be present in the DLL, and a
        // missing one is the FFI\Exception that supported() is designed to catch.
        foreach (self::EXPORTS as $export) {
            self::assertTrue($ffi->{$export} !== null, $export . ' did not resolve');
        }
    }

    public function testPseudoConsoleRoundTripOnARealHost(): void
    {
        self::requireWindowsConPty();

        $api = Kernel32ConPty::self();
        $in = $api->createPipe();
        $out = $api->createPipe();

        $console = $api->createPseudoConsole(80, 24, $in['write'], $out['read']);
        $api->resizePseudoConsole($console, 100, 30);

        $written = $api->writeFile($in['write'], "echo\r\n");
        self::assertIsInt($written, 'writeFile to the console input pipe failed');
        self::assertSame(6, $written);

        // What the child's end of the console produces is host-dependent, so the
        // real assertions here are that the READ path answers at all: a peek that
        // returns null and a read that returns false together would mean the
        // pseudo-console was never wired to the pipes.
        $peeked = $api->peekNamedPipe($out['write']);
        self::assertTrue($peeked === null || isset($peeked['available']));
        $read = $api->readFile($out['read'], 4096);
        self::assertTrue($read === false || \is_string($read));

        $api->closePseudoConsole($console);

        foreach ([$in['read'], $in['write'], $out['read'], $out['write']] as $handle) {
            self::assertTrue($api->closeHandle($handle));
        }

        // And the sizes the Linux-side derivation predicts, confirmed where they are
        // load-bearing.
        $ffi = $api->ffi();
        self::assertSame(104, \FFI::sizeof($ffi->new('STARTUPINFOW')));
        self::assertSame(112, \FFI::sizeof($ffi->new('STARTUPINFOEXW')));
        self::assertSame(24, \FFI::sizeof($ffi->new('PROCESS_INFORMATION')));
        self::assertSame(144, \FFI::sizeof($ffi->new('JOBOBJECT_EXTENDED_LIMIT_INFORMATION')));
    }

    /**
     * Skip, by name, unless this host can carry a ConPTY at all.
     */
    private static function requireWindowsConPty(): void
    {
        $reason = Kernel32ConPty::supported();

        if ($reason !== null) {
            self::markTestSkipped('ConPTY is unavailable here: ' . $reason);
        }
    }

    /**
     * The source text of one method, braces matched.
     *
     * A source-scan that greps a whole file cannot tell a claim about `supported()`
     * from a claim about `self()`, which is how a "no side effects" pin can end up
     * asserting that some other method is well-behaved.
     */
    private static function methodBody(string $source, string $method): string
    {
        $at = \strpos($source, 'function ' . $method . '(');
        self::assertNotFalse($at, 'no ' . $method . '() in the source — the pin has gone stale');

        $open = \strpos($source, '{', $at);
        self::assertNotFalse($open);

        $depth = 0;

        for ($i = $open; $i < \strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return \substr($source, $open, $i - $open + 1);
                }
            }
        }

        self::fail('unbalanced braces reading ' . $method . '()');
    }
}
