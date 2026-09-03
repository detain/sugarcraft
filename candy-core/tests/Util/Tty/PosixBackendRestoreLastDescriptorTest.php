<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Posix\PosixPtySystem;

/**
 * {@see \SugarCraft\Core\Util\Tty\PosixBackend::restoreLast()} snapshots
 * descriptor 0, which is what its own comment always said it did.
 *
 * ## The defect this file exists for
 *
 * The body read `TermiosFactory::open((int) STDIN)->current()` under a
 * comment reading "save current state from STDIN". An `(int)` cast of a PHP
 * stream yields its RESOURCE ID, not its descriptor. MEASURED, PHP 8.3.6, in
 * a fresh CLI process, three takes, identical every time: `(int) STDIN` is 1,
 * `(int) STDOUT` is 2, `(int) STDERR` is 3 — over descriptors 0, 1 and 2. So
 * the rescue snapshot was taken from descriptor 1, STDOUT, and the code and
 * the comment disagreed with the comment being the one that was right.
 *
 * In an ordinary terminal 0 and 1 name the same device, so the wrong number
 * still produced a usable snapshot. That is exactly what kept it invisible,
 * and it is also why this guard cannot be written against the ambient
 * descriptors of a test run — it has to build a process in which the two
 * descriptors are different things.
 *
 * ## The fixture
 *
 * A child is spawned twice through the same probe, with its descriptors
 * arranged two ways: a pty SLAVE handle opened here and handed over by
 * `proc_open()`, the master of its pair kept open for the child's lifetime.
 *
 * WHY THE SLAVE AND NOT THE `/dev/ptmx` MASTER the first draft used: the
 * probe's whole vocabulary is `isatty()` and `tcgetattr()`, and on Darwin a
 * master is a tty to `isatty()` but NOT a termios object to `tcgetattr()`
 * (ENOTTY -- the line discipline lives on the slave); MEASURED on CI
 * (macos-14, PHP 8.3), both tests here failed with "no termios snapshot
 * could be taken from descriptor N" while the identical arrangement was
 * green on Linux. The slave is the real terminal on every POSIX host, so one
 * arrangement now serves both.
 *
 *   tty-on-0   descriptor 0 is the terminal, descriptor 1 is a pipe
 *              -> a snapshot must be taken
 *   tty-on-1   descriptor 0 is a pipe, descriptor 1 is the terminal
 *              -> no snapshot must be taken
 *
 * `tty-on-1` is the sharp one: it is the arrangement where the WRONG
 * descriptor is the readable one. A body asking about descriptor 1 comes
 * back with a snapshot there; the fixed body comes back with none. `tty-on-0`
 * is its control, because "no snapshot" is also what a deleted method body
 * produces, and an expectation of null that a gutted body satisfies is not
 * evidence of anything.
 */
final class PosixBackendRestoreLastDescriptorTest extends TestCase
{
    private const PROBE = __DIR__ . '/restore_last_descriptor_probe.php';

    /** @var list<string> */
    private array $artifacts = [];

    /**
     * The masters of the pty pairs the fixture hands its slaves to children.
     * Closed in tearDown -- AFTER the slave handles are gone -- on every path
     * out of a test, including a failing assertion, because a leaked master
     * keeps the kernel-side reference alive across the whole suite.
     *
     * @var list<MasterPty>
     */
    private array $masters = [];

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('PosixBackend is POSIX-only.');
        }
        if (\DIRECTORY_SEPARATOR !== '/' || !is_readable('/dev/ptmx') || !is_writable('/dev/ptmx')) {
            self::markTestSkipped('/dev/ptmx is not available; a pty pair cannot be allocated to hand the child');
        }
        if (!\extension_loaded('ffi')) {
            self::markTestSkipped('ext-ffi is required for a termios snapshot.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->masters as $master) {
            $master->close();
        }
        $this->masters = [];

        foreach ($this->artifacts as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->artifacts = [];
    }

    /**
     * THE GUARD. Descriptor 1 being readable is not enough to produce a
     * snapshot; descriptor 0 has to be.
     */
    public function testNoSnapshotIsTakenWhenOnlyDescriptorOneIsATerminal(): void
    {
        $result = $this->runProbe('tty-on-1');

        // The arrangement, asserted rather than assumed. If descriptor 0
        // turned out to be a terminal too, the two readings agree and this
        // fixture proves nothing.
        self::assertFalse($result['isatty_0'], 'descriptor 0 was a terminal; this fixture cannot discriminate');
        self::assertTrue($result['isatty_1'], 'descriptor 1 was not a terminal; this fixture cannot discriminate');
        self::assertFalse($result['tcgetattr_0'], 'a termios snapshot could be taken from descriptor 0');
        self::assertTrue($result['tcgetattr_1'], 'no termios snapshot could be taken from descriptor 1');

        self::assertFalse($result['snapshot_before'], 'the child started with a snapshot already set');
        self::assertFalse(
            $result['snapshot_after'],
            'restoreLast() came back with a snapshot in a process whose descriptor 0 is a pipe, '
                . 'so it read some other descriptor -- descriptor 1 is the terminal here',
        );
    }

    /**
     * The control that stops the assertion above being satisfied by a gutted
     * body: with the terminal on descriptor 0, a snapshot MUST appear.
     */
    public function testASnapshotIsTakenWhenDescriptorZeroIsATerminal(): void
    {
        $result = $this->runProbe('tty-on-0');

        self::assertTrue($result['isatty_0'], 'descriptor 0 was not a terminal; this control cannot discriminate');
        self::assertFalse($result['isatty_1'], 'descriptor 1 was a terminal; this control cannot discriminate');
        self::assertTrue($result['tcgetattr_0'], 'no termios snapshot could be taken from descriptor 0');
        self::assertFalse($result['tcgetattr_1'], 'a termios snapshot could be taken from descriptor 1');

        self::assertFalse($result['snapshot_before'], 'the child started with a snapshot already set');
        self::assertTrue(
            $result['snapshot_after'],
            'restoreLast() took no snapshot in a process whose descriptor 0 IS a terminal',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runProbe(string $mode): array
    {
        self::assertFileExists(self::PROBE);

        // A pty pair, and the handle handed to the child is the SLAVE. See
        // the fixture section of the class doc-block for why the master was
        // the wrong device to use here on Darwin.
        $pair = (new PosixPtySystem())->open();
        $this->masters[] = $pair->master();

        $slavePath = $pair->slave()->path();
        $terminal  = fopen($slavePath, 'r+b');
        self::assertIsResource($terminal, $slavePath . ' is the pty slave but did not open');
        self::assertTrue(stream_isatty($terminal), $slavePath . ' is not a tty here; the fixture cannot discriminate');

        $resultFile = tempnam(sys_get_temp_dir(), 'sc_core_r53a_restorelast_' . $mode . '_');
        self::assertIsString($resultFile);
        $this->artifacts[] = $resultFile;

        $spec = $mode === 'tty-on-0'
            ? [0 => $terminal, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]
            : [0 => ['pipe', 'r'], 1 => $terminal, 2 => ['pipe', 'w']];

        $process = proc_open([\PHP_BINARY, self::PROBE, $mode, $resultFile], $spec, $pipes);
        self::assertIsResource($process, 'could not start the probe child');

        // The child never reads its stdin in `tty-on-1` mode; closing our
        // end straight away stops it blocking if that ever changes.
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        $stderr = (string) stream_get_contents($pipes[2]);
        if (isset($pipes[1])) {
            fclose($pipes[1]);
        }
        fclose($pipes[2]);
        $exit = proc_close($process);
        fclose($terminal);

        self::assertSame(0, $exit, "the probe child did not finish.\nstderr: " . $stderr);

        $raw = file_get_contents($resultFile);
        self::assertIsString($raw);
        self::assertNotSame('', $raw, 'the probe child wrote no results; stderr: ' . $stderr);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'the probe child wrote something that is not JSON: ' . $raw);
        self::assertSame('PROBE-RAN', $decoded['control'] ?? null, 'the probe body did not run to the end');

        return $decoded;
    }
}
