<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ForkedChild;

/**
 * {@see ReapsForkedChildrenTrait}, exercised against real forked processes.
 *
 * The two claims that matter pull in opposite directions and are therefore
 * both tested: the reaper MUST kill a survivor it recorded, and it MUST NOT
 * touch a live process it did not. The second is not paranoia - three lanes
 * run this suite concurrently against copies of the same tree, and a reaper
 * that widened from "pids I forked" to anything resembling a process listing
 * would kill somebody else's run.
 *
 * Every child here leaves through {@see ForkedChild::exitNow()} (SIGKILL to
 * self, no PHP shutdown sequence) so that no child of this file can run
 * PHPUnit's shutdown handlers a second time - the convention
 * {@see ForkedChildExitConventionTest} pins for the whole suite.
 */
final class ReapsForkedChildrenTraitTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private string $dir;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid') || !\function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are required to fork real children.');
        }

        $this->dir = sys_get_temp_dir() . '/sc_reaper_trait_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * `posix_kill($pid, 0)` on a pid we have already reaped answers false
     * (ESRCH); on a live one, or an unreaped zombie, it answers true. Every
     * liveness question below is asked that way, within milliseconds of the
     * event, so pid reuse is not a live concern.
     */
    private function stillThere(int $pid): bool
    {
        return @posix_kill($pid, 0);
    }

    private function forkSleeper(): int
    {
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed - cannot exercise this path');

        if ($pid === 0) {
            sleep(30);
            ForkedChild::exitNow(0);
        }

        return $pid;
    }

    public function testTheReaperSigkillsATrackedChildThatIsStillRunning(): void
    {
        $pid = $this->trackForkedChild($this->forkSleeper());

        $this->assertTrue($this->stillThere($pid), 'setup: the sleeper must be alive before the reap');

        $started = microtime(true);
        $killed = $this->reapTrackedForkedChildren(graceSeconds: 0.05);
        $elapsed = microtime(true) - $started;

        $this->assertSame([$pid], $killed, 'the reaper must report the pid it had to kill');
        $this->assertFalse($this->stillThere($pid), 'the tracked survivor is still running after the reap');

        // THE SIGNAL, not merely the wait. Without it the reaper's final
        // blocking pcntl_waitpid() would still leave `killed` and `stillThere`
        // reading exactly like a kill - it would just take the sleeper's full
        // 30 seconds to say so.
        $this->assertLessThan(
            5.0,
            $elapsed,
            'the reaper waited the survivor out instead of signalling it',
        );
    }

    /**
     * THE SAFETY CLAIM. An untracked live process is somebody else's - a
     * sibling lane's `phpunit`, the developer's editor, init. The reaper is
     * driven entirely by `pcntl_fork()` return values it was handed, so it
     * cannot see this one.
     */
    public function testTheReaperLeavesAProcessItDidNotRecordAlone(): void
    {
        $tracked = $this->trackForkedChild($this->forkSleeper());
        $untracked = $this->forkSleeper();

        // try/finally, because this is the one test in the file its own
        // subject matter can bite: the sleeper is deliberately INVISIBLE to
        // the reaper - that is the property under test - so tearDown() cannot
        // clean it up. A failed assertion below would leave a 30s orphan.
        try {
            $killed = $this->reapTrackedForkedChildren(graceSeconds: 0.05);

            $this->assertSame([$tracked], $killed);
            $this->assertTrue(
                $this->stillThere($untracked),
                'the reaper killed a live process that was never recorded in its ledger',
            );
        } finally {
            posix_kill($untracked, SIGKILL);
            $status = 0;
            pcntl_waitpid($untracked, $status);
        }
    }

    /**
     * THE SECOND LINE OF DEFENCE, which nothing exercised.
     *
     * {@see ReapsForkedChildrenTrait::forkTracked()} empties the ledger in the
     * child, so for every child forked through the trait the reaper returns
     * early on an empty ledger and the owner-pid re-check is never reached.
     * Every existing test took that route, and deleting
     * `|| $this->trackedForkedChildrenOwner !== $self` from the reaper's guard
     * clause therefore left the whole suite green (measured: mutation R5
     * SURVIVED).
     *
     * A second line of defence exists precisely for when the first does not
     * hold. This is that case: a RAW `pcntl_fork()` inside a class using the
     * trait, whose child inherits a POPULATED ledger of its own SIBLINGS. With
     * the owner check gone and `graceSeconds: 0.0` - so the bounded polling
     * pass, which would otherwise filter the siblings out on ECHILD, does not
     * run a single iteration - the child SIGKILLs a process it never created.
     */
    public function testAChildForkedOutsideTheTraitCannotReapTheLedgerItInherited(): void
    {
        $sibling = $this->trackForkedChild($this->forkSleeper());
        $report = $this->dir . '/owner.json';

        // RAW, deliberately: forkTracked() would empty the inherited ledger
        // and this test would exercise the first line of defence again.
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed - cannot exercise this path');

        if ($pid === 0) {
            @file_put_contents($report, (string) json_encode([
                'ledger' => array_keys($this->trackedForkedChildren),
                'killed' => $this->reapTrackedForkedChildren(graceSeconds: 0.0),
            ]));
            ForkedChild::exitNow(0);
        }

        $status = 0;
        pcntl_waitpid($pid, $status);

        $observed = json_decode((string) @file_get_contents($report), true);
        $this->assertIsArray($observed, 'the child reported nothing at all');
        $this->assertSame(
            [$sibling],
            $observed['ledger'],
            'setup is void unless the child really did inherit a POPULATED ledger',
        );
        $this->assertSame([], $observed['killed'], 'the child reaped a ledger that was not its own');
        $this->assertTrue(
            $this->stillThere($sibling),
            'a child running tearDown() SIGKILLed its own sibling',
        );
    }

    /**
     * A child inherits a full copy of the parent's object graph, ledger
     * included - and every pid in it is one of the child's SIBLINGS, so a
     * child that ran the reaper would kill processes it never created.
     * {@see ReapsForkedChildrenTrait::forkTracked()} empties the inherited
     * copy in the child for exactly that reason.
     */
    public function testAChildForkedThroughTheTraitInheritsAnEmptyLedger(): void
    {
        $sibling = $this->trackForkedChild($this->forkSleeper());
        $report = $this->dir . '/inherited.json';

        $pid = $this->forkTracked();
        $this->assertNotSame(-1, $pid, 'fork failed - cannot exercise this path');

        if ($pid === 0) {
            @file_put_contents($report, (string) json_encode([
                'ledger' => array_keys($this->trackedForkedChildren),
                'owner' => $this->trackedForkedChildrenOwner,
                'killed' => $this->reapTrackedForkedChildren(graceSeconds: 0.0),
            ]));
            ForkedChild::exitNow(0);
        }

        $status = 0;
        pcntl_waitpid($pid, $status);
        $this->forgetForkedChild($pid);

        $observed = json_decode((string) @file_get_contents($report), true);
        $this->assertIsArray($observed, 'the child reported nothing at all');
        $this->assertSame([], $observed['ledger'], 'the child inherited its siblings as if they were its own children');
        $this->assertSame(0, $observed['owner']);
        $this->assertSame([], $observed['killed']);

        $this->assertTrue(
            $this->stillThere($sibling),
            'the forked child reaped its own sibling',
        );
    }

    /**
     * The ordinary path: a test that already waited on its child pays nothing
     * for tracking it. `pcntl_waitpid()` answers -1/ECHILD for an
     * already-reaped pid, which the reaper treats as done rather than as a
     * survivor to signal.
     */
    public function testAnAlreadyReapedChildIsNotReportedAsKilled(): void
    {
        $pid = $this->forkTracked();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            ForkedChild::exitNow(0);
        }

        $status = 0;
        pcntl_waitpid($pid, $status);

        $this->assertSame([], $this->reapTrackedForkedChildren(graceSeconds: 0.05));
    }

    public function testAnEmptyLedgerReapsNothing(): void
    {
        $this->assertSame([], $this->reapTrackedForkedChildren());
    }

    /**
     * A child that exits on its own during the grace period is waited for,
     * not signalled - so `killed` stays empty and no zombie is left behind.
     */
    public function testAChildThatExitsWithinTheGracePeriodIsWaitedForRatherThanKilled(): void
    {
        $pid = $this->forkTracked();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            usleep(30_000);
            ForkedChild::exitNow(0);
        }

        $this->assertSame([], $this->reapTrackedForkedChildren(graceSeconds: 3.0));
        $this->assertFalse($this->stillThere($pid), 'the exited child was left as a zombie');
    }
}
