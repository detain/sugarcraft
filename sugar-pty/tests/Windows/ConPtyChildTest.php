<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Windows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Windows\ConPtyApi;
use SugarCraft\Pty\Windows\ConPtyChild;
use SugarCraft\Pty\Windows\ConPtySystem;

/**
 * The child adapter's lifetime: wait cannot forge an exit code, kill collapses
 * every signal into one job termination carrying the 128+N convention POSIX
 * callers already map, and an abandoned child is still contained — the job
 * handle closes at teardown, and kill-on-close takes the tree.
 */
final class ConPtyChildTest extends TestCase
{
    private FakeConPtyApi $api;

    protected function setUp(): void
    {
        $this->api = new FakeConPtyApi();
    }

    /**
     * Spawn through the real adapter chain and report the child plus the two
     * handle numbers the transcript says the binding issued for it.
     *
     * @return array{0: ConPtyChild, 1: int, 2: int} child, process handle, job id
     */
    private function spawnOne(): array
    {
        $pair = (new ConPtySystem($this->api))->open(80, 24);
        $child = $pair->slave()->spawn(['cmd', '/c', 'echo hi']);

        self::assertInstanceOf(ConPtyChild::class, $child);

        $assigned = $this->api->callsMatching('assignProcessToJob');
        self::assertCount(1, $assigned);
        self::assertSame(1, \preg_match('/assignProcessToJob\((\d+),(\d+)\)/', $assigned[0], $ids));

        return [$child, (int) $ids[2], (int) $ids[1]];
    }

    public function testPidIsTheProcessIdTheBindingReported(): void
    {
        [$child, $process] = $this->spawnOne();

        // The fake answers processId = 4000 + handle; the adapter must forward
        // the id, never the handle.
        self::assertSame(4000 + $process, $child->pid());
    }

    public function testRunningChildIsNotExitedAndHasNoCodeYet(): void
    {
        [$child, $process] = $this->spawnOne();

        self::assertFalse($child->exited());
        self::assertNull($child->exitCode());
        // Both probes above are zero-timeout polls; the child was never
        // settled, and no exit-code read may have happened.
        self::assertNotEmpty($this->api->callsMatching('waitForSingleObject(' . $process . ',0)'));
        self::assertSame([], $this->api->callsMatching('getExitCodeProcess'));
    }

    public function testSettledChildIsCachedAfterOneProbe(): void
    {
        [$child, $process] = $this->spawnOne();
        $this->api->setExitCode($process, 3);

        self::assertTrue($child->exited());
        self::assertSame(3, $child->exitCode());

        $this->api->transcript = [];
        self::assertTrue($child->exited());
        self::assertSame(3, $child->exitCode());
        self::assertSame([], $this->api->transcript, 'the settled state must not re-poll the host');
    }

    public function testWaitReturnsTheRealCodeAndReleasesBothHandles(): void
    {
        [$child, $process, $job] = $this->spawnOne();
        $this->api->setExitCode($process, 7);

        self::assertSame(7, $child->wait());

        $waitAt = $this->indexOfTranscript('waitForSingleObject(' . $process . ',' . ConPtyApi::INFINITE . ')');
        $readAt = $this->indexOfTranscript('getExitCodeProcess(' . $process . ')');
        $closeAt = $this->indexOfTranscript('closeHandle(' . $process . ')');
        $jobAt = $this->indexOfTranscript('closeJob(' . $job . ')');

        // The exit code must be read BEFORE the job closes — the fake mirrors
        // kill-on-close by restamping terminated processes, so reading after
        // release would report the job's teardown code, not the child's.
        self::assertLessThan($readAt, $waitAt);
        self::assertLessThan($closeAt, $readAt);
        self::assertLessThan($jobAt, $closeAt);

        // A second wait is pure cache: the transcript must not move at all.
        $before = $this->api->transcript;
        self::assertSame(7, $child->wait());
        self::assertSame($before, $this->api->transcript);
    }

    public function testWaitRefusesToForgeSuccessWhenTheWaitMisbehaves(): void
    {
        // A real INFINITE wait cannot answer WAIT_TIMEOUT. The double can, and
        // that shape is exactly what the guard exists for: without it the
        // adapter would fall through to getExitCodeProcess and report
        // STILL_ACTIVE (259) as if it were the child's exit code — a silent
        // lie. Deleting the guard reddens this pin.
        [$child] = $this->spawnOne();

        $this->expectException(PtyException::class);
        $this->expectExceptionMessageMatches('/WAIT_TIMEOUT/');

        $child->wait();
    }

    public function testKillCollapsesEverySignalToTheJobWithTheTwosComplementCode(): void
    {
        [$child, , $job] = $this->spawnOne();
        $child->kill(Child::SIGKILL);

        self::assertSame(['terminateJob(' . $job . ',137)'], $this->api->callsMatching('terminateJob'));

        // Fresh double: the second child's transcript must be readable without
        // the first spawn's records mixed in.
        $this->api = new FakeConPtyApi();

        [$secondChild, , $secondJob] = $this->spawnOne();
        $secondChild->kill(Child::SIGINT);

        self::assertSame(
            ['terminateJob(' . $secondJob . ',130)'],
            $this->api->callsMatching('terminateJob(' . $secondJob . ','),
        );
    }

    public function testKillAfterSettlementIsAnIdleNoOp(): void
    {
        [$child, $process] = $this->spawnOne();
        $this->api->setExitCode($process, 0);
        $child->exitCode(); // settle the cache the same way wait() would

        $before = \count($this->api->callsMatching('terminateJob'));
        $child->kill(Child::SIGTERM);

        self::assertCount($before, $this->api->callsMatching('terminateJob'));
    }

    public function testAbandonedChildIsStillContainedAtTeardown(): void
    {
        [$child, $process, $job] = $this->spawnOne();

        unset($child);
        \gc_collect_cycles();

        // No wait, no kill — the destructor must still close the process and
        // the job, because the job is what keeps the tree from outliving us.
        self::assertNotEmpty($this->api->callsMatching('closeHandle(' . $process . ')'));
        self::assertNotEmpty($this->api->callsMatching('closeJob(' . $job . ')'));
        self::assertSame(ConPtyApi::WAIT_FAILED, $this->api->waitForSingleObject($process, 0));
    }

    public function testSettledChildAtTeardownChangesNothing(): void
    {
        [$child, $process, $job] = $this->spawnOne();
        $this->api->setExitCode($process, 0);
        $child->wait();

        $before = \count($this->api->callsMatching('closeJob(' . $job . ')'));

        unset($child);
        \gc_collect_cycles();

        self::assertCount($before, $this->api->callsMatching('closeJob(' . $job . ')'), 'released once by wait() must suffice');
    }

    private function indexOfTranscript(string $exact): int
    {
        $position = \array_search($exact, $this->api->transcript, true);

        self::assertIsInt($position, 'transcript never carried: ' . $exact);

        return $position;
    }
}
