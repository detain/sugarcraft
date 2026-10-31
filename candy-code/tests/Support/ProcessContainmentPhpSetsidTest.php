<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\ProcessContainment;

/**
 * The PHP helper detach arm of {@see ProcessContainment::detachedSpawnPrefix()}
 * (C-TOOL-4.h / SHL macOS). Stock macOS ships no `setsid(1)`, so the second
 * candidate — `php -r <stub> --`, which posix_setsid()s and then pcntl_exec()s
 * the real command through /usr/bin/env — is how a Darwin host still gets a
 * real process group. These pins force that arm on this Linux box (where the
 * native setsid would otherwise win) and prove its two claims hold: the
 * recorded pid IS the command (group + session leader, source of the exit
 * status), and a candidate that fails either claim is dropped.
 */
final class ProcessContainmentPhpSetsidTest extends TestCase
{
    protected function tearDown(): void
    {
        // Never leak a forced candidate list into the next test — the memo is
        // class-scope and one wrong answer poisons every later spawn claim.
        ProcessContainment::setDetachedSpawnCandidatesForTesting(null);
        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private static function helperArm(): array
    {
        return [\PHP_BINARY, '-r', ProcessContainment::PHP_SETSID_STUB, '--'];
    }

    public function testTheHelperArmPassesTheProbeAsTheOnlyCandidate(): void
    {
        ProcessContainment::setDetachedSpawnCandidatesForTesting([self::helperArm()]);

        // The probe's own verdict: on this box the helper arm forwards exit 7
        // AND leads its group, so it is accepted whole.
        self::assertSame(self::helperArm(), ProcessContainment::detachedSpawnPrefix());
        self::assertTrue(ProcessContainment::groupsSpawns());
        self::assertSame(\PHP_BINARY, ProcessContainment::detachedSpawnBinary());
    }

    public function testTheHelperArmChildIsItsOwnGroupLeaderAndForwardsSeven(): void
    {
        // Behaviour, not just the internal probe: spawn `/bin/sh -c 'exit 7'`
        // through the helper prefix directly and read the group leadership off
        // the live pid before reaping. This is the whole point of the exec
        // replacing the PHP process — the pid the registry records is the
        // leader `terminate()` will signal.
        $spec = [...self::helperArm(), '/bin/sh', '-c', 'exit 7'];

        [$status, $leader] = $this->spawnAndInspect($spec);

        self::assertTrue($leader, 'the helper-arm child must lead its own group');
        self::assertSame(7, $status, 'exit 7 must ride through env + sh back to the parent');
    }

    public function testTheHelperArmMissingBinaryIsOneTwoSeven(): void
    {
        // pcntl_exec("/usr/bin/env", ['no-such-bin']) execs env, which prints
        // its own "No such file" and exits 127 — the exec-failure floor the
        // design names. Still detached (it got a pid, led a group), but the
        // exit code carries the refusal, not a swallowed 0. Full production
        // arm shape including the '--' — and the helper DRAINS before
        // closing, which is what makes this 127 rather than a corrupted 125.
        $missing = 'definitely-not-a-binary-cc-tool-4h-' . \uniqid('', true);
        $spec = [...self::helperArm(), $missing, 'x'];

        [$status, $leader] = $this->spawnAndInspect($spec);

        self::assertSame(127, $status);
        self::assertTrue($leader, 'the child detaches before the exec, even when the exec fails');
    }

    public function testTheStringFormWrapsThroughBinShC(): void
    {
        ProcessContainment::setDetachedSpawnCandidatesForTesting([self::helperArm()]);

        $spec = ProcessContainment::spawnSpec('exit 7');
        self::assertIsArray($spec);
        self::assertSame([...self::helperArm(), '/bin/sh', '-c', 'exit 7'], $spec);

        [$status, $leader] = $this->spawnAndInspect($spec);
        self::assertSame(7, $status);
        self::assertTrue($leader);
    }

    public function testTheArrayFormWrapsThroughDirectExecUnderTheHelperArm(): void
    {
        ProcessContainment::setDetachedSpawnCandidatesForTesting([self::helperArm()]);

        $spec = ProcessContainment::spawnSpec(['/bin/sh', '-c', 'exit 7']);
        self::assertIsArray($spec);
        self::assertSame([...self::helperArm(), '/bin/sh', '-c', 'exit 7'], $spec);
    }

    public function testACliWithoutPcntlDropsTheHelperArmToTheBareCommand(): void
    {
        if (!\extension_loaded('pcntl')) {
            self::markTestSkipped('this pin disables pcntl_exec, so it needs pcntl present to disable.');
        }

        // A CLI with pcntl_exec disabled: the stub reaches posix_setsid(), then
        // fatals on the undefined pcntl_exec() — exit 255, not 7 — so the probe
        // rejects the arm and NO detach is offered. This is the exact fallback
        // the design promises on a helper-less host: today's bare command.
        $crippled = [\PHP_BINARY, '-d', 'disable_functions=pcntl_exec', '-r', ProcessContainment::PHP_SETSID_STUB, '--'];
        ProcessContainment::setDetachedSpawnCandidatesForTesting([$crippled]);

        self::assertSame([], ProcessContainment::detachedSpawnPrefix());
        self::assertSame('', ProcessContainment::detachedSpawnBinary());
        self::assertFalse(ProcessContainment::groupsSpawns());
        self::assertSame(['/bin/true'], ProcessContainment::spawnSpec(['/bin/true']));
        self::assertSame('true', ProcessContainment::spawnSpec('true'));
    }

    public function testARealSetsidIsStillPreferredWhenPresent(): void
    {
        $setsid = ProcessContainment::locateOnPath('setsid');
        if ($setsid === '') {
            self::markTestSkipped('this pin needs a native setsid present to prefer over the helper.');
        }

        ProcessContainment::setDetachedSpawnCandidatesForTesting([[$setsid, '-w', '--'], self::helperArm()]);

        // Preference is order, not luck: the native wrapper is probed first and
        // the cheap arm wins both claims, so the helper never displaces it.
        self::assertSame([$setsid, '-w', '--'], ProcessContainment::detachedSpawnPrefix());
    }

    public function testTheProbeRefusesAnExitForwarderThatLeadsNothing(): void
    {
        // The leadership leg's whole purpose: `/bin/sh -c 'exit 7'` forwards
        // the exit code perfectly while the child stays in the PARENT's
        // group. Accepted on the exit leg alone, every wrapped spawn would
        // claim a group that terminate() could then kill nobody in. Costs
        // this pin the 2 s deadline once — the bounded-reject path, itself
        // worth exercising.
        ProcessContainment::setDetachedSpawnCandidatesForTesting([['/bin/sh', '-c', 'exit 7']]);

        self::assertSame([], ProcessContainment::detachedSpawnPrefix());
        self::assertSame('', ProcessContainment::detachedSpawnBinary());
        self::assertFalse(ProcessContainment::groupsSpawns());
    }

    public function testDetachedSpawnBinaryIsAlwaysThePrefixHead(): void
    {
        // The invariant every `!== ''` caller relies on: the binary accessor and
        // the prefix agree, for the empty and non-empty case alike.
        ProcessContainment::setDetachedSpawnCandidatesForTesting([]);
        self::assertSame([], ProcessContainment::detachedSpawnPrefix());
        self::assertSame('', ProcessContainment::detachedSpawnBinary());
        self::assertFalse(ProcessContainment::groupsSpawns());

        ProcessContainment::setDetachedSpawnCandidatesForTesting([self::helperArm()]);
        self::assertSame(ProcessContainment::detachedSpawnPrefix()[0], ProcessContainment::detachedSpawnBinary());
        self::assertTrue(ProcessContainment::groupsSpawns());
    }

    /**
     * Launch one argv spec, drain its pipes (an unread close can corrupt the
     * reported status via a child dying on SIGPIPE mid-error-write — the
     * production probe documents the 125-vs-127 measurement), close, and
     * read (exit status, was the child its own group leader). The pid is
     * sampled EXACTLY ONCE before any reap, mirroring the production
     * probe's discipline: posix_getpgid answers for a reaped-pending child
     * only until proc_close takes it.
     *
     * @param list<string> $spec
     * @return array{0:int,1:bool}
     */
    private function spawnAndInspect(array $spec): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = @\proc_open($spec, $descriptors, $pipes);
        self::assertIsResource($proc, 'the helper-arm spec must spawn at all');

        $pid = \proc_get_status($proc)['pid'];
        \stream_get_contents($pipes[1]);
        \stream_get_contents($pipes[2]);
        \fclose($pipes[0]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        $leader = false;
        if (\function_exists('posix_getpgid')) {
            $deadline = \hrtime(true) + 2_000_000_000;
            while (true) {
                if (@\posix_getpgid($pid) === $pid) {
                    $leader = true;
                    break;
                }
                if (\hrtime(true) >= $deadline) {
                    break;
                }
                \usleep(1000);
            }
        }

        $status = \proc_close($proc);

        return [$status, $leader];
    }
}
