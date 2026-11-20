<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleRegistry;

/**
 * P4-12 CC-1 row f: {@see ScheduleRegistry::fireDue()} answers with a promise,
 * because a launch answers when its daemon authenticates, a later loop turn.
 * These pin the in-flight rules that have to hold across that gap: a launch
 * that is still pending keeps its row busy and its lock held, a stop of the
 * row's marker during the launch does not let the row fire twice, a refused
 * launch is an error verdict that frees the row for its next slot, and a
 * launch that settles at once is decided inside the call.
 */
final class ScheduleRegistryAsyncFireTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sched_async_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/.candy-code', 0o700, true);
        $this->path = $this->dir . '/.candy-code/schedules.json';
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }

    public function testALaunchThatAnswersLaterFillsItsVerdictWhenItSettles(): void
    {
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->add(new Schedule(name: 'beat', everySeconds: 60, prompt: 'p', nextDue: 100, root: $this->dir));
        $launch = new Deferred();

        $verdicts = null;
        $registry->fireDue(100, static fn (): PromiseInterface => $launch->promise())
            ->then(static function (array $settled) use (&$verdicts): void {
                $verdicts = $settled;
            });

        $this->assertNull($verdicts, 'a launch that waits on its daemon must not answer inside the call');
        $this->assertNull($registry->busySession('beat'), 'nothing is remembered until the daemon has a session');
        $this->assertGreaterThan(100, $registry->get('beat')->nextDue, 'the clock moved before the launch went out');

        $launch->resolve('sess_late');

        $this->assertSame([['name' => 'beat', 'action' => 'fired', 'sessionId' => 'sess_late']], $verdicts);
        $this->assertSame('sess_late', $registry->busySession('beat'));
        $this->assertSame('fired', $registry->lastOutcome('beat')['action'] ?? null, 'the settled verdict is the recorded outcome');
    }

    public function testAPendingLaunchKeepsTheRowBusyAndNeverDoublesIt(): void
    {
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->add(new Schedule(name: 'slow', everySeconds: 60, prompt: 'p', nextDue: 100, root: $this->dir));
        $launch = new Deferred();
        $spawned = 0;
        $spawn = static function () use (&$spawned, $launch): PromiseInterface {
            ++$spawned;

            return $launch->promise();
        };

        $registry->fireDue(100, $spawn);
        $second = self::settled($registry->fireDue(10_000, $spawn));

        $this->assertSame(1, $spawned, 'a row whose launch is still pending is never launched again');
        $this->assertSame([['name' => 'slow', 'action' => 'skipped-busy']], $second);
        $this->assertGreaterThan(10_000, $registry->get('slow')->nextDue, 'the busy slot still moves the clock');

        $launch->resolve('sess_one');
    }

    public function testAStopOfTheMarkerDuringALaunchCannotLetTheRowFireTwice(): void
    {
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->add(new Schedule(name: 'stopped', everySeconds: 60, prompt: 'p', nextDue: 100, root: $this->dir));
        $launch = new Deferred();
        $spawned = 0;
        $spawn = static function () use (&$spawned, $launch): PromiseInterface {
            ++$spawned;

            return $launch->promise();
        };

        $registry->fireDue(100, $spawn);
        // The pump clears a marker once its session settles; no session exists yet,
        // so a stop of the marker here must not open the row to a second launch.
        $registry->clearFired('stopped');
        $again = self::settled($registry->fireDue(10_000, $spawn));

        $this->assertSame(1, $spawned, 'clearing the marker mid-launch must not free the row');
        $this->assertSame('skipped-busy', $again[0]['action']);

        $launch->resolve('sess_first');
        $this->assertSame('sess_first', $registry->busySession('stopped'));
    }

    public function testARefusedLaunchIsAnErrorVerdictAndFreesTheRowForItsNextSlot(): void
    {
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->add(new Schedule(name: 'refused', everySeconds: 60, prompt: 'p', nextDue: 100, root: $this->dir));
        $first = new Deferred();

        $verdicts = null;
        $registry->fireDue(100, static fn (): PromiseInterface => $first->promise())
            ->then(static function (array $settled) use (&$verdicts): void {
                $verdicts = $settled;
            });
        $first->reject(new \RuntimeException('the daemon never authenticated'));

        $this->assertSame('error', $verdicts[0]['action']);
        $this->assertSame('the daemon never authenticated', $verdicts[0]['error']);
        $this->assertSame('error', $registry->lastOutcome('refused')['action'] ?? null);
        $this->assertNull($registry->busySession('refused'), 'a refused launch remembers no session');

        $spawned = 0;
        $retry = self::settled($registry->fireDue(10_000, static function () use (&$spawned): string {
            ++$spawned;

            return 'sess_retry';
        }));

        $this->assertSame(1, $spawned, 'the next slot may launch again, and only once');
        $this->assertSame('fired', $retry[0]['action']);
    }

    public function testRowsAnswerInDueOrderWhenOneLaunchIsPendingAndOneSettlesAtOnce(): void
    {
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->add(new Schedule(name: 'a-slow', everySeconds: 60, prompt: 'p', nextDue: 100, root: $this->dir));
        $registry->add(new Schedule(name: 'b-quick', everySeconds: 60, prompt: 'p', nextDue: 100, root: $this->dir));
        $slow = new Deferred();

        $verdicts = null;
        $registry->fireDue(100, static function (Schedule $row) use ($slow): PromiseInterface|string {
            return $row->name === 'a-slow' ? $slow->promise() : 'sess_quick';
        })->then(static function (array $settled) use (&$verdicts): void {
            $verdicts = $settled;
        });

        $this->assertNull($verdicts, 'the call answers once every launch it started has settled');

        $slow->resolve('sess_slow');

        $this->assertSame(['a-slow', 'b-quick'], array_column($verdicts, 'name'));
        $this->assertSame(['fired', 'fired'], array_column($verdicts, 'action'));
        $this->assertSame('sess_quick', $registry->busySession('b-quick'));
    }

    /** @return list<array<string, mixed>> the verdicts of a fireDue whose launches all settled at once */
    private static function settled(PromiseInterface $promise): array
    {
        $verdicts = null;
        $promise->then(static function (array $settled) use (&$verdicts): void {
            $verdicts = $settled;
        });
        self::assertIsArray($verdicts, 'the fire must answer at once');

        return $verdicts;
    }
}
