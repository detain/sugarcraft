<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundSession;
use SugarCraft\Code\Background\BackgroundStopOutcome;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\Background\ScheduleSpawner;
use SugarCraft\Code\Tests\Support\SupervisorPump;

/**
 * P4-12 CC-1 row f through the real launch path: a due row fired by
 * {@see ScheduleRegistry::fireDue()} with the production
 * {@see ScheduleSpawner::closure()} spawns a real daemon through the
 * supervisor's loop, the verdict arrives when that daemon authenticates, and
 * the session it names stops through the supervisor's loop too. A stop of an
 * unrelated session while a fire is still pending leaves the fire alone.
 */
final class ScheduleFireThroughSupervisorTest extends TestCase
{
    use StoppableDaemonFixtureTrait;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['proc_open', 'pcntl_fork', 'posix_setsid', 'stream_socket_server'] as $fn) {
            if (!\function_exists($fn)) {
                self::markTestSkipped("{$fn}() unavailable");
            }
        }
        $this->setUpDaemonFixture();
        $this->root = (string) \realpath($this->fixtureHome);
        \mkdir($this->root . '/.candy-code', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->tearDownDaemonFixture();
        parent::tearDown();
    }

    public function testAFiredRowSpawnsADaemonThroughTheLoopAndItsSessionStopsThroughIt(): void
    {
        \putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; exec sleep 30');
        $supervisor = new BackgroundSupervisor();
        $registry = $this->registry();
        $registry->add(new Schedule(name: 'nightly', everySeconds: 3_600, prompt: 'run the sweep', nextDue: \time() - 1, root: $this->root));

        $verdicts = null;
        $registry->fireDue(\time(), ScheduleSpawner::closure($supervisor, null, $this->root))
            ->then(static function (array $settled) use (&$verdicts): void {
                $verdicts = $settled;
            });
        self::assertNull($verdicts, 'the launch answers when its daemon authenticates, not inside the fire');

        self::assertTrue(SupervisorPump::until($supervisor, static function () use (&$verdicts): bool {
            return $verdicts !== null;
        }), 'the loop never settled the launch');
        self::assertSame('fired', $verdicts[0]['action'] ?? null, (string) \json_encode($verdicts));
        $id = (string) $verdicts[0]['sessionId'];
        $this->fixtureDaemons[] = $this->daemonOf($supervisor, $id);
        self::assertSame($id, $registry->busySession('nightly'));
        self::assertSame(['schedule', 'schedule:nightly'], $supervisor->session($id)?->tags, 'the fired run is tagged like every scheduled run');

        $outcome = $this->stop($supervisor, $id);

        self::assertSame(BackgroundStopOutcome::StoppedViaIpc, $outcome);
        self::assertSame(\SugarCraft\Code\Background\BackgroundSessionStatus::Stopped, $supervisor->getSession($id)?->status);
    }

    public function testAStopOfAnotherSessionWhileAFireIsPendingLeavesTheFireAlone(): void
    {
        \putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; exec sleep 30');
        $supervisor = new BackgroundSupervisor();
        $running = $this->spawnSleepingDaemon($supervisor, 30)['session'];

        $registry = $this->registry();
        $registry->add(new Schedule(name: 'pending', everySeconds: 3_600, prompt: 'run the sweep', nextDue: \time() - 1, root: $this->root));
        $verdicts = null;
        $registry->fireDue(\time(), ScheduleSpawner::closure($supervisor, null, $this->root))
            ->then(static function (array $settled) use (&$verdicts): void {
                $verdicts = $settled;
            });
        self::assertNull($verdicts, 'the fire is still waiting on its daemon');

        // The stop alone, not the whole tick: tick() also advances spawns, and
        // the fire's daemon authenticating while the unrelated daemon is still
        // exiting would settle the fire through the SPAWN half, which says
        // nothing about the stop and made this arm order-dependent under load.
        $outcome = $this->stopWithoutAdvancingSpawns($supervisor, $running->id);

        self::assertSame(BackgroundStopOutcome::StoppedViaIpc, $outcome, 'the stop of the running session is driven to its outcome');
        self::assertNull($verdicts, 'the stop does not settle the pending fire');

        self::assertTrue(SupervisorPump::until($supervisor, static function () use (&$verdicts): bool {
            return $verdicts !== null;
        }), 'the pending fire never settled');
        self::assertSame('fired', $verdicts[0]['action'] ?? null, (string) \json_encode($verdicts));
        $id = (string) $verdicts[0]['sessionId'];
        $this->fixtureDaemons[] = $this->daemonOf($supervisor, $id);
        self::assertSame(\SugarCraft\Code\Background\BackgroundSessionStatus::Running, $supervisor->session($id)?->status);
        self::assertSame(BackgroundStopOutcome::StoppedViaIpc, $this->stop($supervisor, $id));
    }

    private function registry(): ScheduleRegistry
    {
        return ScheduleRegistry::atPath($this->root . '/.candy-code/schedules.json', $this->root);
    }

    /** Drives {@see BackgroundSupervisor::stopSessionAsync()} to its outcome through the loop. */
    private function stop(BackgroundSupervisor $supervisor, string $id): BackgroundStopOutcome
    {
        $outcome = null;
        $supervisor->stopSessionAsync($id)->then(static function (BackgroundStopOutcome $settled) use (&$outcome): void {
            $outcome = $settled;
        });
        self::assertTrue(SupervisorPump::until($supervisor, static function () use (&$outcome): bool {
            return $outcome !== null;
        }), 'the stop was never driven to its outcome');

        return $outcome;
    }

    /**
     * Drives {@see BackgroundSupervisor::stopSessionAsync()} to its outcome
     * through {@see BackgroundSupervisor::advanceStops()} only — the stop's
     * own state machine, the same one the synchronous stopSession() pumps —
     * so no pending spawn's handshake is accepted while the stop runs. A
     * queued handshake is still accepted on the next tick() even past its
     * spawn deadline, because advanceSpawns() reads a ready handshake before
     * it judges the deadline.
     */
    private function stopWithoutAdvancingSpawns(BackgroundSupervisor $supervisor, string $id): BackgroundStopOutcome
    {
        $outcome = null;
        $supervisor->stopSessionAsync($id)->then(static function (BackgroundStopOutcome $settled) use (&$outcome): void {
            $outcome = $settled;
        });
        $deadline = \microtime(true) + 20.0;
        while ($outcome === null && \microtime(true) < $deadline) {
            $supervisor->advanceStops();
            if ($outcome === null) {
                SupervisorPump::pace();
            }
        }
        self::assertNotNull($outcome, 'the stop was never driven to its outcome');

        return $outcome;
    }

    /** @return array{pid: int, startTime: int|null} */
    private function daemonOf(BackgroundSupervisor $supervisor, string $id): array
    {
        $ipc = $this->ipcOf($supervisor, $id);

        return ['pid' => $ipc['pid'], 'startTime' => $ipc['startTime'] ?? null];
    }
}
