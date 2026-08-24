<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Program;

/**
 * ONE guard for the whole closed-descriptor-0 family.
 *
 * candy-core and candy-mosaic were written when descriptor 0 was always a
 * live terminal. It is not any more: `sugar-crush`'s test bootstrap closes
 * the standard input constant on every non-tty run, and a daemon, a git
 * hook, or a shell invocation that detaches its own input reaches the same
 * state in production. The through-line and the per-member measurements
 * live on {@see \SugarCraft\Core\Util\TtyDetect}'s class doc-block; this
 * file is the test that keeps them true.
 *
 * It is deliberately one guard over the family rather than four guards over
 * four symptoms. The members do not share an interface — one is a static
 * predicate, one is an environment probe, one is a private method on a
 * 1500-line runtime class — but they share the exact precondition that
 * breaks them, and a precondition is a much better thing to test once than
 * four times.
 *
 * ## Why a child process
 *
 * A suite cannot close its own standard input without taking the rest of
 * the run with it. So the closing happens in
 * `tests/Util/closed_descriptor_zero_probe.php`, which closes descriptors
 * 0, 1 and 2, runs every member, and writes what happened to a file.
 *
 * ## Why the controls are not optional
 *
 * Every family row here asserts that something did NOT throw, and "did not
 * throw" is also what a run reports when nothing ran at all. Two rows in
 * the probe exist to close that hole, and they are asserted before any
 * family row is:
 *
 *   - `control_ok` returns a marker. A missing or wrong marker means the
 *     probes did not execute and nothing below is evidence.
 *   - `control_unguarded_isatty` is the exact unguarded shape the family
 *     fixes, called on purpose. It MUST throw in the closed child and MUST
 *     NOT throw in the live one. That pair proves the harness can see a
 *     throw, that it reports rather than swallows it, and that descriptor 0
 *     in the closed child really is closed.
 *
 * MEASURED, PHP 8.3.6, in the closed child: `TypeError: stream_isatty():
 * supplied resource is not a valid stream resource`. The `@` operator does
 * not suppress it, because it is thrown rather than raised.
 */
final class ClosedDescriptorZeroFamilyTest extends TestCase
{
    private const PROBE = __DIR__ . '/closed_descriptor_zero_probe.php';

    /** @var list<string> */
    private array $artifacts = [];

    protected function tearDown(): void
    {
        // Exact paths only, and only paths this test created.
        foreach ($this->artifacts as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->artifacts = [];
    }

    public function testEveryMemberOfTheFamilyAnswersRatherThanThrowsWithDescriptorZeroClosed(): void
    {
        $result = $this->runProbe('closed');

        self::assertFalse(
            $result['_state']['stdin_is_resource'],
            'the child did not actually close its descriptor 0, so it tested nothing',
        );

        // Controls first: without these the rows below are not evidence.
        self::assertSame(['ok' => 'PROBES-RAN'], $result['control_ok']);
        self::assertArrayHasKey(
            'throw',
            $result['control_unguarded_isatty'],
            'the unguarded shape did NOT throw with descriptor 0 closed. Either the child is not in '
                . 'the state it reported, or this whole file has stopped discriminating — every '
                . 'assertion below would pass on a tree with all the guards removed.',
        );
        self::assertStringContainsString(
            'TypeError',
            $result['control_unguarded_isatty']['throw'],
            'the unguarded shape threw something other than the measured TypeError',
        );

        // The family. Each of these is the same call the control makes,
        // reached through the guard that is supposed to make it safe.
        self::assertSame(['ok' => false], $result['tty_detect_is_atty']);
        self::assertSame(['ok' => false], $result['tty_detect_is_atty_null']);
        self::assertSame(['ok' => false], $result['env_detect_is_console_stdin']);

        // proc_open() throws the same way on a dead descriptor-array entry,
        // and runExec()'s try/catch would turn that into exit -1 with an
        // error rather than an exception — so these rows assert the exit
        // code and a null error, not merely the absence of a throw.
        self::assertSame(['ok' => [7, null]], $result['program_run_exec_captured']);
        self::assertSame(['ok' => [9, null]], $result['program_run_exec_passthrough']);
    }

    /**
     * The other polarity of the control.
     *
     * With descriptors 0, 1 and 2 left alone, the unguarded shape must
     * answer instead of throwing. That is what makes the throw in the test
     * above attributable to the closing rather than to the harness, the
     * autoloader, or anything else in the child.
     */
    public function testTheUnguardedShapeAnswersNormallyWhenDescriptorZeroIsLive(): void
    {
        $result = $this->runProbe('live');

        self::assertTrue($result['_state']['stdin_is_resource']);
        self::assertSame(['ok' => 'PROBES-RAN'], $result['control_ok']);
        self::assertArrayHasKey(
            'ok',
            $result['control_unguarded_isatty'],
            'the unguarded shape threw with a LIVE descriptor 0, so the closed-child throw cannot be '
                . 'attributed to the closing',
        );

        // And the family answers identically either way, which is the whole
        // point of guarding it.
        self::assertSame(['ok' => false], $result['tty_detect_is_atty']);
        self::assertSame(['ok' => false], $result['env_detect_is_console_stdin']);
        self::assertSame(['ok' => [7, null]], $result['program_run_exec_captured']);
        self::assertSame(['ok' => [9, null]], $result['program_run_exec_passthrough']);
    }

    /**
     * The child-output arm, one level down from the integration probe.
     *
     * `runExec()` resolves three descriptor slots through
     * `Program::childDescriptor()`. Two of them are driven end-to-end by the
     * probe; the output slot cannot be, because reaching its last resort
     * needs `$this->output` dead and a Program with a dead output throws out
     * of terminal teardown long before proc_open() (measured — the reason is
     * recorded at the probe's own passthrough case). So the resolution
     * itself is asserted directly here, including the arm the probe cannot
     * reach.
     */
    public function testChildDescriptorPrefersLiveHandlesAndFallsThroughToADeviceSpec(): void
    {
        $resolve = new \ReflectionMethod(Program::class, 'childDescriptor');

        $live  = fopen('php://memory', 'r+b');
        $other = fopen('php://memory', 'r+b');
        $dead  = fopen('php://memory', 'r+b');
        self::assertIsResource($live);
        self::assertIsResource($other);
        self::assertIsResource($dead);
        fclose($dead);

        $spec = ['file', '/dev/null', 'w'];

        self::assertSame(
            $live,
            $resolve->invoke(null, $live, $other, $spec),
            'a live preferred handle must win',
        );
        self::assertSame(
            $other,
            $resolve->invoke(null, $dead, $other, $spec),
            'a dead preferred handle must fall through to the constant',
        );
        self::assertSame(
            $other,
            $resolve->invoke(null, null, $other, $spec),
            'a null preferred handle must fall through to the constant',
        );
        self::assertSame(
            $spec,
            $resolve->invoke(null, $dead, $dead, $spec),
            'with both handles dead the resolution must reach the device spec — falling back to a '
                . 'closed constant is E340, the guard whose fallback was the thing it guarded against',
        );
        self::assertSame(
            $spec,
            $resolve->invoke(null, null, $dead, $spec),
            'the error slot passes a literal null as its preferred handle',
        );

        fclose($live);
        fclose($other);
    }

    /**
     * THE POSITIVE POLARITY, and the reason the two tests above are evidence.
     *
     * Every family row in the closed child expects `false`, and `false` is
     * also what a probe returns when its call has been replaced by a
     * constant, or when the symbol under it has been gutted. An assertion of
     * `false` is therefore not, by itself, proof that anything ran — this is
     * the fixture-with-a-dead-instrument shape, one level down from the
     * controls.
     *
     * So the same child is run once more with a REAL terminal device on its
     * descriptor 0, opened here as `/dev/ptmx` and handed over by proc_open.
     * Now every one of those rows must answer `true`. A constant cannot
     * satisfy both this test and the two above, and neither can a gutted
     * `isAtty()`.
     */
    public function testTheSameFamilyRowsAnswerTrueWhenDescriptorZeroIsARealTerminal(): void
    {
        if (\DIRECTORY_SEPARATOR !== '/' || !is_readable('/dev/ptmx')) {
            self::markTestSkipped('/dev/ptmx is not available; no terminal device to hand the child');
        }

        $ptmx = fopen('/dev/ptmx', 'r+b');
        self::assertIsResource($ptmx);

        try {
            self::assertTrue(stream_isatty($ptmx), '/dev/ptmx is not a tty here; the control cannot discriminate');
            $result = $this->runProbe('tty', $ptmx);
        } finally {
            if (\is_resource($ptmx)) {
                fclose($ptmx);
            }
        }

        self::assertTrue($result['_state']['stdin_is_resource']);
        self::assertSame(['ok' => 'PROBES-RAN'], $result['control_ok']);

        // The unguarded shape answers true rather than throwing, which is the
        // third distinct answer it has given across the three modes.
        self::assertSame(['ok' => true], $result['control_unguarded_isatty']);

        // And the guarded members agree with it. These are the rows that were
        // false in both other modes.
        self::assertSame(['ok' => true], $result['tty_detect_is_atty']);
        self::assertSame(['ok' => true], $result['env_detect_is_console_stdin']);

        // null is still not a stream, whatever descriptor 0 is.
        self::assertSame(['ok' => false], $result['tty_detect_is_atty_null']);
    }

    /**
     * @param resource|null $stdin descriptor 0 for the child; null means /dev/null
     * @return array<string, mixed>
     */
    private function runProbe(string $mode, $stdin = null): array
    {
        self::assertFileExists(self::PROBE);

        $resultFile = tempnam(sys_get_temp_dir(), 'sc_core_fd0_' . $mode . '_');
        self::assertIsString($resultFile);
        $this->artifacts[] = $resultFile;
        $this->artifacts[] = $resultFile . '.sink';

        $process = proc_open(
            [\PHP_BINARY, self::PROBE, $mode, $resultFile],
            [0 => $stdin ?? ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'could not start the probe child');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(
            0,
            $exit,
            "the probe child did not start or did not finish.\nstdout: " . $stdout . "\nstderr: " . $stderr,
        );

        $raw = file_get_contents($resultFile);
        self::assertIsString($raw);
        self::assertNotSame('', $raw, 'the probe child wrote no results');

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'the probe child wrote something that is not JSON: ' . $raw);

        return $decoded;
    }
}
