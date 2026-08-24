<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Tty\PosixBackend;
use SugarCraft\Pty\Posix\PosixPtySystem;

/**
 * {@see PosixBackend::restoreLast()} really does take a terminal back to the
 * state it snapshotted.
 *
 * ## WHAT THIS FILE USED TO ASSERT, AND WHY IT CHANGED
 *
 * WHAT IT SAID: two tests, described as verifying that `restoreLast()` uses a
 * Termios snapshot rather than an `stty -g` shell-out.
 *
 *   - `testRestoreLastRoundTripsViaPtyMaster()` opened a pty, applied raw mode
 *     to the MASTER, called `restoreLast()`, and then asserted that
 *     `isAtty()` was unchanged. `isAtty()` is a property of the device; it is
 *     true before and after anything. `restoreLast()` never touched that
 *     master at all -- it reads descriptor 0. The test passed identically
 *     before and after the round in which that descriptor was moved from 1 to
 *     0, which is the plainest possible demonstration that it did not observe
 *     the thing it was named for.
 *   - `testRestoreLastNoOpWithoutTtyStdin()` ended `assertTrue(true)`. Its
 *     comment claimed it verified that no shell-out was attempted; nothing in
 *     it could observe a shell-out, or anything else. It passed against a
 *     deleted method body.
 *
 * WHAT IS TRUE NOW: which DESCRIPTOR the snapshot comes from is pinned next
 * door by {@see PosixBackendRestoreLastDescriptorTest}, with descriptors 0 and
 * 1 arranged both ways round a terminal. What no test in this tree observed
 * was the ROUND TRIP -- that the second call re-applies what the first one
 * saved. This file is that, and nothing else.
 *
 * WHY THE FILE STILL EARNS ITS PLACE rather than being deleted: its subject
 * was never covered elsewhere, only its two bodies were empty. The sibling
 * file answers "which descriptor"; this one answers "and then what".
 *
 * ## What is NOT claimed any more
 *
 * The old "no `stty -g` shell-out is attempted" is gone, in both tests,
 * because nothing here can see a shell-out and writing that sentence over a
 * test that cannot is how the previous pair came to be trusted. What IS
 * observable is the effect, and the effect is what is asserted.
 */
final class PosixBackendRestoreLastTest extends TestCase
{
    private const PROBE = __DIR__ . '/restore_last_round_trip_probe.php';

    /** @var list<string> */
    private array $artifacts = [];

    /** @var list<\SugarCraft\Pty\Contract\MasterPty> */
    private array $masters = [];

    /** @var list<resource> */
    private array $handles = [];

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('PosixBackend is POSIX-only.');
        }
        if (!is_readable('/dev/ptmx') || !is_writable('/dev/ptmx')) {
            self::markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!\extension_loaded('ffi')) {
            self::markTestSkipped('ext-ffi is required for a termios snapshot.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->handles as $handle) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
        $this->handles = [];

        foreach ($this->masters as $master) {
            $master->close();
        }
        $this->masters = [];

        // Exact-path deletes only; /tmp is shared with sibling lanes' suites.
        foreach ($this->artifacts as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->artifacts = [];
    }

    /**
     * THE ROUND TRIP. Snapshot a cooked terminal, make it raw through
     * candy-pty directly, and the second `restoreLast()` must put it back.
     *
     * The middle step deliberately does NOT go through `PosixBackend`: if the
     * same code both made the change and undid it, a pair of no-ops would
     * satisfy the assertion. `state_after_raw` is the control that says the
     * terminal really moved, and it is asserted before the restore is.
     */
    public function testTheSecondCallReAppliesWhatTheFirstOneSnapshotted(): void
    {
        $result = $this->runProbe('round-trip', withTerminalOnDescriptorZero: true);

        self::assertTrue($result['isatty_0'], 'descriptor 0 was not the terminal; the probe read something else');
        self::assertFalse($result['state_initial'], 'setup: the fixture terminal was already raw');
        self::assertTrue($result['snapshot_after_first'], 'the first call took no snapshot to restore');

        // The control: the terminal genuinely moved between the two calls.
        self::assertTrue($result['state_after_raw'], 'the terminal never became raw, so the restore proves nothing');

        self::assertFalse(
            $result['state_after_restore'],
            'the second restoreLast() did not put the terminal back; the snapshot was taken and '
                . 'never applied',
        );
        self::assertFalse(
            $result['snapshot_after_second'],
            'the snapshot survived being applied, so a third call would re-apply a stale one',
        );
    }

    /**
     * With no terminal on descriptor 0, BOTH calls are the first call.
     *
     * The sibling file already pins that no snapshot is taken there. What it
     * does not cover is the SECOND call: with `$rescueSnapshot` still null,
     * the method must take the snapshot branch again rather than reaching the
     * apply branch with nothing in it. A body that swapped the two conditions
     * would still pass a single-call test.
     *
     * The round-trip test above is this one's known positive -- "no snapshot"
     * is also what a deleted method body produces, and the two run against
     * the same probe.
     */
    public function testTwoCallsWithoutATerminalOnDescriptorZeroTakeNoSnapshotAndDoNotThrow(): void
    {
        $result = $this->runProbe('no-tty-twice', withTerminalOnDescriptorZero: false);

        self::assertFalse($result['isatty_0'], 'descriptor 0 was a terminal; this fixture cannot discriminate');
        self::assertFalse($result['snapshot_after_first'], 'a snapshot was taken from a pipe');
        self::assertFalse($result['snapshot_after_second'], 'the second call produced a snapshot from a pipe');
    }

    /**
     * @return array<string, mixed>
     */
    private function runProbe(string $mode, bool $withTerminalOnDescriptorZero): array
    {
        self::assertFileExists(self::PROBE);

        $pair      = (new PosixPtySystem())->open();
        $this->masters[] = $pair->master();
        $slavePath = $pair->slave()->path();

        $slave = fopen($slavePath, 'r+');
        if ($slave === false) {
            self::markTestSkipped('could not open the pty slave path ' . $slavePath);
        }
        $this->handles[] = $slave;

        $resultFile = tempnam(sys_get_temp_dir(), 'sc_core_r54a_roundtrip_' . $mode . '_');
        self::assertIsString($resultFile);
        $this->artifacts[] = $resultFile;

        $spec = $withTerminalOnDescriptorZero
            ? [0 => $slave, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]
            : [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open(
            [\PHP_BINARY, self::PROBE, $mode, $slavePath, $resultFile],
            $spec,
            $pipes,
        );
        self::assertIsResource($process, 'could not start the probe child');

        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        $stderr = (string) stream_get_contents($pipes[2]);
        if (isset($pipes[1])) {
            fclose($pipes[1]);
        }
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, "the probe child did not finish.\nstderr: " . $stderr);

        $raw = file_get_contents($resultFile);
        self::assertIsString($raw);
        self::assertNotSame('', $raw, 'the probe child wrote no results; stderr: ' . $stderr);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'the probe child wrote something that is not JSON: ' . $raw);
        self::assertSame('PROBE-RAN', $decoded['control'] ?? null, 'the probe body did not run to the end');

        // THE CHILD'S INSTRUMENT, not just its output. Every raw/not-raw row
        // the child reports is a claim about what its flag matcher did NOT
        // see, and a matcher that matched nothing would answer "not raw"
        // forever -- which the restore assertion would then pass for free.
        // The child computes this from a synthetic reading carrying the
        // ECHO-lookalike trap, so it is a fact about the matcher rather than
        // about the host's stty. See SttyReading for what the trap is and for
        // the mutation that proved the previous substring form asserted
        // nothing.
        self::assertTrue(
            $decoded['matcher_discriminates'] ?? null,
            'the probe child\'s raw-mode matcher cannot tell a cleared ECHO from the '
                . 'ECHO-prefixed flags that look like one, so none of its state rows are evidence',
        );

        return $decoded;
    }
}
