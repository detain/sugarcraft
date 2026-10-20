<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Tty\Kernel32;
use SugarCraft\Core\Util\Tty\Kernel32Interface;
use SugarCraft\Core\Util\Tty\WindowsConsole;

/**
 * A-10: WindowsConsole output-side VT negotiation, pinned through the
 * scripted FakeKernel32 exactly where the survey prescribed the seam
 * ("VT flag OR'd in, prior mode restored" — no contortion: the class was
 * built against Kernel32Interface for precisely this double).
 *
 * The real-FFI leg cannot run off Windows (kernel32.dll will not load);
 * it is house-skipped (Kernel32Test's RequiresWindows shape) so the CI
 * report names the gap honestly instead of silently lacking it.
 *
 * Mirror: chafa/chafa-stream-writer.c win32_stream_writer_init (:83-105)
 * and win32_stream_writer_deinit (:107-111).
 */
final class WindowsConsoleTest extends TestCase
{
    private const VT = Kernel32Interface::ENABLE_VIRTUAL_TERMINAL_PROCESSING;

    /** Ordinary conhost default: ENABLE_PROCESSED_OUTPUT | ENABLE_WRAP_AT_EOL_OUTPUT. */
    private const LEGACY_MODE = 0x0003;

    // ─── Negotiation semantics (fake-driven, run everywhere) ────────────────

    public function testEnableOrsVirtualTerminalProcessingIntoThePriorMode(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(self::LEGACY_MODE);

        $console = new WindowsConsole($fake);

        $this->assertTrue($console->enable());
        $this->assertSame(
            [[$fake->stdOut(), self::LEGACY_MODE | self::VT]],
            $fake->getSetConsoleModeCalls(),
            'enable() must OR the VT bit into the live mode, never replace the mask wholesale.',
        );
        $this->assertSame(self::LEGACY_MODE, $console->savedMode());
        $this->assertTrue($console->vtEnabled());
    }

    public function testRestoreWritesThePriorModeBack(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(self::LEGACY_MODE);

        $console = new WindowsConsole($fake);
        $console->enable();

        $this->assertTrue($console->restore());
        $this->assertSame(
            [[$fake->stdOut(), self::LEGACY_MODE | self::VT], [$fake->stdOut(), self::LEGACY_MODE]],
            $fake->getSetConsoleModeCalls(),
        );
        $this->assertFalse($console->vtEnabled());
        $this->assertNull($console->savedMode());
    }

    public function testSecondEnableIsANoOperationWithoutDoubleSave(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(self::LEGACY_MODE);

        $console = new WindowsConsole($fake);
        $this->assertTrue($console->enable());
        $this->assertTrue($console->enable());

        $this->assertCount(
            1,
            $fake->getSetConsoleModeCalls(),
            'A re-enable must not re-save: capturing the already-VT mode as the "prior" generation would arm restore() to leave VT on forever.',
        );
        $this->assertSame(self::LEGACY_MODE, $console->savedMode());

        $console->restore();
        $calls = $fake->getSetConsoleModeCalls();
        $this->assertSame(
            self::LEGACY_MODE,
            $calls[count($calls) - 1][1],
            'The single saved generation must be the original pre-enable mode.',
        );
    }

    public function testEnableOnAnAlreadyVtCapableConsoleChangesNothing(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(self::LEGACY_MODE | self::VT);

        $console = new WindowsConsole($fake);

        $this->assertTrue($console->enable());
        $this->assertSame([], $fake->getSetConsoleModeCalls());
        $this->assertNull($console->savedMode());
        $this->assertTrue($console->vtEnabled());

        $this->assertTrue($console->restore());
        $this->assertSame([], $fake->getSetConsoleModeCalls());
        $this->assertFalse($console->vtEnabled());
    }

    public function testEnableFailsLoudlyWhenTheHandleIsNotAConsole(): void
    {
        $fake = new FakeKernel32();
        // Nothing seeded: getConsoleMode() answers false — redirected output.

        $console = new WindowsConsole($fake);

        $this->assertFalse($console->enable());
        $this->assertFalse($console->vtEnabled());
        $this->assertNull($console->savedMode());
        $this->assertSame([], $fake->getSetConsoleModeCalls());
    }

    public function testRefusedEnableLeavesNoSavedGeneration(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(self::LEGACY_MODE);
        $fake->setSetConsoleModeFails();

        $console = new WindowsConsole($fake);

        $this->assertFalse($console->enable());
        $this->assertNull($console->savedMode());
        $this->assertFalse($console->vtEnabled());
        $this->assertCount(1, $fake->getSetConsoleModeCalls());

        // Retry after the refusal clears re-negotiates cleanly.
        $fake->setSetConsoleModeFails(false);
        $this->assertTrue($console->enable());
        $this->assertSame(self::LEGACY_MODE, $console->savedMode());
    }

    public function testFailedRestoreKeepsTheSavedModeForRetry(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(self::LEGACY_MODE);

        $console = new WindowsConsole($fake);
        $console->enable();

        $fake->setSetConsoleModeFails();
        $this->assertFalse($console->restore());
        $this->assertSame(self::LEGACY_MODE, $console->savedMode());
        $this->assertTrue($console->vtEnabled());

        $fake->setSetConsoleModeFails(false);
        $this->assertTrue($console->restore());
        $this->assertNull($console->savedMode());
        $this->assertSame(
            self::LEGACY_MODE,
            $fake->getSetConsoleModeCalls()[2][1],
            'The retried restore must write the original generation, not a re-read.',
        );
    }

    public function testRestoreWithoutEnableIsANoOperation(): void
    {
        $fake = new FakeKernel32();

        $console = new WindowsConsole($fake);

        $this->assertTrue($console->restore());
        $this->assertSame([], $fake->getSetConsoleModeCalls());
    }

    public function testReEnableAfterRestoreNegotiatesFromTheLiveModeAgain(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(self::LEGACY_MODE);

        $console = new WindowsConsole($fake);
        $console->enable();
        $console->restore();
        $this->assertTrue($console->enable());

        $this->assertSame(
            [self::LEGACY_MODE | self::VT, self::LEGACY_MODE, self::LEGACY_MODE | self::VT],
            array_map(static fn(array $call): int => $call[1], $fake->getSetConsoleModeCalls()),
        );
    }

    public function testExplicitHandleIsNegotiatedVerbatim(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdin(0x0001);

        $console = new WindowsConsole($fake, $fake->stdIn());

        $this->assertTrue($console->enable());
        $this->assertSame(
            [[$fake->stdIn(), 0x0001 | self::VT]],
            $fake->getSetConsoleModeCalls(),
        );
    }

    // ─── Structure and degradation contract (reflection, run everywhere) ─────

    public function testTheNegotiationSurfaceIsTypedAsAdvertised(): void
    {
        $class = new \ReflectionClass(WindowsConsole::class);

        $this->assertTrue($class->isFinal());

        $ctorParams = $class->getConstructor()->getParameters();
        $this->assertCount(2, $ctorParams);
        $this->assertTrue($ctorParams[0]->getType()->allowsNull());
        $this->assertSame(Kernel32Interface::class, $ctorParams[0]->getType()->getName());
        $this->assertTrue($ctorParams[1]->getType()->allowsNull());
        $this->assertSame('int', $ctorParams[1]->getType()->getName());

        $this->assertSame('bool', $class->getMethod('enable')->getReturnType()->getName());
        $this->assertSame('bool', $class->getMethod('restore')->getReturnType()->getName());
        $this->assertSame('bool', $class->getMethod('vtEnabled')->getReturnType()->getName());
        $this->assertTrue($class->getMethod('savedMode')->getReturnType()->allowsNull());
        $this->assertTrue($class->getMethod('isSupported')->isStatic());
    }

    public function testConstructingTheRealBindingsOffWindowsFailsLoudly(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Native Windows has the real bindings by construction; this pins the Linux fail-loud shape.');
        }

        $this->assertFalse(WindowsConsole::isSupported());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('isSupported');

        new WindowsConsole();
    }

    public function testNoLinuxNegotiationPathLoadsTheKernel32FfiRuntime(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('The static cdef slot legitimately carries the loaded runtime there.');
        }

        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(self::LEGACY_MODE);
        $console = new WindowsConsole($fake);
        $console->enable();
        $console->restore();

        $ffi = (new \ReflectionClass(Kernel32::class))->getStaticPropertyValue('ffi');
        $this->assertNull(
            $ffi,
            'WindowsConsole must degrade through isSupported()/injection without ever touching FFI::cdef on Linux paths.',
        );
    }

    // ─── Real-Windows leg (house-skipped off Windows) ────────────────────────

    public function testRealConsoleNegotiationRoundTripRequiresWindows(): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Requires native Windows kernel32.dll - enable()/restore() drive the real console API.');
        }

        $console = new WindowsConsole();
        $armed   = $console->enable();

        $this->assertIsBool($armed);
        $this->assertSame($armed, $console->vtEnabled());
        $this->assertTrue($console->restore());
    }
}
