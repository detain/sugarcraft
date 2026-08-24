<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;

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
 * arranged two ways, following the idiom
 * {@see \SugarCraft\Core\Tests\Util\ClosedDescriptorZeroFamilyTest} uses: a
 * `/dev/ptmx` handle opened here and handed over by `proc_open()`.
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

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('PosixBackend is POSIX-only.');
        }
        if (\DIRECTORY_SEPARATOR !== '/' || !is_readable('/dev/ptmx')) {
            self::markTestSkipped('/dev/ptmx is not available; no terminal device to hand the child');
        }
        if (!\extension_loaded('ffi')) {
            self::markTestSkipped('ext-ffi is required for a termios snapshot.');
        }
    }

    protected function tearDown(): void
    {
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

        $ptmx = fopen('/dev/ptmx', 'r+b');
        self::assertIsResource($ptmx, '/dev/ptmx is readable but did not open');
        self::assertTrue(stream_isatty($ptmx), '/dev/ptmx is not a tty here; the fixture cannot discriminate');

        $resultFile = tempnam(sys_get_temp_dir(), 'sc_core_r49a_restorelast_' . $mode . '_');
        self::assertIsString($resultFile);
        $this->artifacts[] = $resultFile;

        $spec = $mode === 'tty-on-0'
            ? [0 => $ptmx, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]
            : [0 => ['pipe', 'r'], 1 => $ptmx, 2 => ['pipe', 'w']];

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
        fclose($ptmx);

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
