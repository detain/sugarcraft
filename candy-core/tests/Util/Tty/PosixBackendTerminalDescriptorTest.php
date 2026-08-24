<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Tty\PosixBackend;
use SugarCraft\Pty\SizeIoctl;

/**
 * {@see PosixBackend::openTerminalDescriptor()} answers with a GENUINE file
 * descriptor, never with a number derived from a PHP stream.
 *
 * ## The defect this file exists for
 *
 * `PosixBackend::size()`'s third arm used to reach the controlling terminal
 * like this:
 *
 *     $tty   = self::openTty();      // freshly fopen()s /dev/tty
 *     $ttyFd = (int) $tty[0];        // <- the RESOURCE ID, not a descriptor
 *     $result = SizeIoctl::query($ttyFd);
 *
 * An `(int)` cast of a PHP stream yields its resource id. The rest of that
 * family got away with the same cast because descriptors 0, 1 and 2 all name
 * the same device in an ordinary terminal, so asking the wrong number still
 * returned the right answer. This arm could not: it opens a FRESH handle, and
 * a fresh handle's resource id can never equal its own descriptor once the low
 * numbers are taken.
 *
 * MEASURED, PHP 8.3.6, under a real pty, three takes, identical each time: the
 * handle's resource id was 5 while the descriptor behind it was 4, and the two
 * give OPPOSITE answers — `posix_isatty(5)` false, `posix_isatty(4)` true.
 * `SizeIoctl::query()` opens with exactly that `posix_isatty()` check and
 * throws when it fails, so the arm threw on every invocation it ever had and
 * silently fell through to the `stty` shell-out below it. It had never once
 * returned an answer. This was not a latent defect.
 *
 * ## Why these tests need no controlling terminal
 *
 * A test host — and CI in particular — usually has none, and on such a host
 * every implementation of this helper answers null for /dev/tty, correct and
 * broken alike. That is why the production helper takes the device path: a
 * test can hand it `/dev/ptmx`, which is a terminal device that opens without
 * a controlling terminal.
 *
 * MEASURED on this box, PHP 8.3.6, in a plain (non-tty) CLI process:
 * `open("/dev/tty", O_RDONLY)` returns -1, while `open("/dev/ptmx", O_RDONLY)`
 * returns descriptor 3 with `posix_isatty(3) === true`. So the POSITIVE half
 * of the contract — "the number that comes back is a descriptor that names a
 * terminal" — is assertable everywhere, and it is the half that dies when the
 * cast comes back.
 */
final class PosixBackendTerminalDescriptorTest extends TestCase
{
    private const TERMINAL_DEVICE = '/dev/ptmx';

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('PosixBackend is POSIX-only.');
        }
    }

    /**
     * THE GUARD. A descriptor comes back, not a resource id — asserted in the
     * one arrangement where the two numbers disagree.
     *
     * Both numbers are derived here rather than assumed: the descriptor from
     * the helper, and the resource id from a `fopen()` of the SAME device,
     * which is precisely the shape the defect used. The pre-assertions state
     * the arrangement that makes the fixture discriminating, so a run in which
     * the fixture degenerated cannot pass as a run in which the code was right.
     */
    public function testTheNumberItAnswersIsADescriptorAndNotAStreamResourceId(): void
    {
        $this->requireTerminalDevice();

        $fd = PosixBackend::openTerminalDescriptor(self::TERMINAL_DEVICE);
        self::assertNotNull($fd, self::TERMINAL_DEVICE . ' is openable but the helper answered null');

        $handle = fopen(self::TERMINAL_DEVICE, 'r+b');
        self::assertIsResource($handle, self::TERMINAL_DEVICE . ' is openable but fopen() failed');
        $resourceId = (int) $handle;

        try {
            // The arrangement. If these ever stop holding, the assertion
            // below proves nothing and must be read as inconclusive, not as
            // a pass.
            self::assertNotSame(
                $resourceId,
                $fd,
                'the resource id and the descriptor coincide here, so this fixture proves nothing',
            );
            self::assertFalse(
                posix_isatty($resourceId),
                'descriptor ' . $resourceId . ' (the resource id) is itself a tty here, '
                    . 'so this fixture cannot tell the two readings apart',
            );

            // The claim.
            self::assertTrue(
                posix_isatty($fd),
                'openTerminalDescriptor() answered ' . $fd . ', which is not a terminal descriptor; '
                    . 'a stream resource id for this device would be ' . $resourceId,
            );

            // And the consequence that the production arm actually depends
            // on: the number is one SizeIoctl::query() will accept. This is
            // the positive component — an assertion that the helper "does not
            // return a resource id" would also pass against a helper that
            // returned null forever.
            $size = SizeIoctl::query($fd);
            self::assertArrayHasKey('rows', $size);
            self::assertArrayHasKey('cols', $size);
        } finally {
            fclose($handle);
            PosixBackend::closeTerminalDescriptor($fd);
        }
    }

    /**
     * The known-positive control's mirror image: the resource-id reading the
     * defect used is not merely different, it is REJECTED by the very sink
     * the production arm calls.
     *
     * Without this, the test above could be satisfied by a tree in which the
     * cast happened to be harmless. It is here so the file states, in the
     * same run, both that the fix works and that the thing it replaced does
     * not.
     */
    public function testTheResourceIdReadingIsRejectedByTheSinkTheProductionArmCalls(): void
    {
        $this->requireTerminalDevice();

        $handle = fopen(self::TERMINAL_DEVICE, 'r+b');
        self::assertIsResource($handle);
        $resourceId = (int) $handle;

        try {
            self::assertTrue(
                stream_isatty($handle),
                self::TERMINAL_DEVICE . ' did not report as a tty; the fixture cannot discriminate',
            );
            self::assertFalse(
                posix_isatty($resourceId),
                'descriptor ' . $resourceId . ' is a tty, so this fixture proves nothing',
            );

            $this->expectException(\RuntimeException::class);
            SizeIoctl::query($resourceId);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Null is answered exactly when the device cannot be opened — cross-checked
     * against an independent opener rather than asserted on its own.
     *
     * `/dev/tty` is the interesting case because whether it opens is a property
     * of the process (does it have a controlling terminal?) and not of the
     * filesystem: MEASURED, PHP 8.3.6, `is_readable('/dev/tty')` is true in a
     * process where the open fails with ENXIO, so a permissions check is not a
     * substitute for trying.
     */
    public function testItAnswersNullExactlyWhenTheDeviceCannotBeOpened(): void
    {
        foreach (['/dev/tty', self::TERMINAL_DEVICE, '/dev/null', '/nonexistent/r49a'] as $device) {
            $handle = @fopen($device, 'rb');
            $openable = \is_resource($handle);
            if ($openable) {
                fclose($handle);
            }

            $fd = PosixBackend::openTerminalDescriptor($device);
            if ($fd !== null) {
                PosixBackend::closeTerminalDescriptor($fd);
            }

            self::assertSame(
                $openable,
                $fd !== null,
                $device . ': fopen ' . ($openable ? 'succeeded' : 'failed')
                    . ' but openTerminalDescriptor() answered ' . var_export($fd, true),
            );
        }
    }

    /**
     * The descriptors are handed back.
     *
     * `size()` runs on every SIGWINCH, so a descriptor leaked per call would
     * exhaust the process's table during a drag-resize. This is the guard for
     * the `finally` in that arm, which is the part of the fix easiest to lose
     * in a later edit.
     */
    public function testRepeatedOpenAndCloseLeaksNoDescriptors(): void
    {
        $this->requireTerminalDevice();

        $before = $this->openDescriptors();

        for ($i = 0; $i < 25; $i++) {
            $fd = PosixBackend::openTerminalDescriptor(self::TERMINAL_DEVICE);
            self::assertNotNull($fd, 'cycle ' . $i . ' failed to open');
            PosixBackend::closeTerminalDescriptor($fd);
        }

        $after = $this->openDescriptors();

        self::assertSame(
            $before,
            $after,
            'descriptors leaked across 25 open/close cycles: '
                . implode(',', array_diff($after, $before)) . ' left open',
        );
    }

    /**
     * The production arm's own device, when this host happens to have one.
     *
     * Deliberately NOT a skip when there is no controlling terminal: the
     * negative is asserted instead, so the test still says something on a
     * host where the interesting case is unavailable.
     */
    public function testTheControllingTerminalArmAsksAboutARealDescriptor(): void
    {
        $handle = @fopen('/dev/tty', 'rb');
        $hasControllingTerminal = \is_resource($handle);
        if ($hasControllingTerminal) {
            fclose($handle);
        }

        $fd = PosixBackend::openTerminalDescriptor('/dev/tty');

        if (!$hasControllingTerminal) {
            self::assertNull($fd, 'no controlling terminal, yet a descriptor came back');

            return;
        }

        self::assertNotNull($fd, 'a controlling terminal exists but no descriptor came back');
        try {
            self::assertTrue(posix_isatty($fd), '/dev/tty descriptor ' . $fd . ' is not a tty');
        } finally {
            PosixBackend::closeTerminalDescriptor($fd);
        }
    }

    private function requireTerminalDevice(): void
    {
        if (!is_readable(self::TERMINAL_DEVICE) || !is_writable(self::TERMINAL_DEVICE)) {
            self::markTestSkipped(self::TERMINAL_DEVICE . ' is not available; no terminal device to open');
        }
        if (!\extension_loaded('ffi')) {
            self::markTestSkipped('ext-ffi is required to open a descriptor through libc');
        }
    }

    /** @return list<int> the process's currently open descriptors, sorted */
    private function openDescriptors(): array
    {
        $fds = [];
        foreach ((array) @scandir('/proc/self/fd') as $entry) {
            if (\is_string($entry) && ctype_digit($entry)) {
                $fds[] = (int) $entry;
            }
        }
        sort($fds);

        return $fds;
    }
}
