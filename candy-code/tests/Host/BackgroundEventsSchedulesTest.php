<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Host\BackgroundEvents;
use SugarCraft\Code\Background\BackgroundSession;
use SugarCraft\Code\Background\BackgroundSessionStatus;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleRegistry;

/**
 * AUT-1 serve half: the clock rides the EXISTING `BackgroundEvents::poll()`
 * pump — evaluated BEFORE its idle early-return, so a serve with nothing
 * watched still fires its rows. No new event type exists: a fired daemon
 * enters the same status diff and announces as an ordinary `bg.started`.
 *
 * Fork-safety contract identical to the Chat-side test: every due persisted
 * row here is parked behind a busy marker, so `spawnSession()` is never
 * reached for real.
 *
 * C-AGT-1: the serve host is rooted in `$this->root` and every persisted row
 * carries that same root, because a registry without its host binding fires
 * nothing — the pinned shape below would otherwise be a green vacuity.
 */
final class BackgroundEventsSchedulesTest extends TestCase
{
    private string $root;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sc_bg_sched_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
        $this->events = [];
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    public function testAnIdleServeStillEvaluatesTheClockBeforeItsEarlyReturn(): void
    {
        $registry = ScheduleRegistry::atPath($this->root . '/schedules.json', $this->root);
        $registry->add(new Schedule(name: 'nightly', everySeconds: 60, prompt: 'p', nextDue: time() - 60, root: $this->root));
        $registry->persist();
        // Park the row behind a marker whose daemon does not exist: fireDue
        // skips-but-advances (the busy rule), the marker retires this poll, and
        // the ADVANCED stamp landing on disk proves the pump ran at all —
        // without it poll() would have returned at the reported-empty guard.
        $registry->noteFired('nightly', 'gone');
        $events = $this->host($registry, new BackgroundSupervisor());

        self::assertSame(0, $events->poll(), 'nothing to report: the idle guard still holds');

        $onDisk = json_decode((string) file_get_contents($this->root . '/schedules.json'), true);
        self::assertIsArray($onDisk);
        self::assertGreaterThan(time(), (int) $onDisk['schedules'][0]['nextDue'], 'the idle pump still runs the clock');
        self::assertNull($registry->busySession('nightly'), 'a marker whose daemon vanished retires');
        self::assertSame([], $this->scheduleEvents(), 'no schedule-specific event was minted');
    }

    public function testABusyPersistedRowSkipsAndNeverForks(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession(self::backgroundSession());
        $registry = ScheduleRegistry::atPath($this->root . '/schedules.json', $this->root);
        $registry->add(new Schedule(name: 'nightly', everySeconds: 60, prompt: 'p', nextDue: time() - 60, root: $this->root));
        $registry->persist();
        $registry->noteFired('nightly', 'bg1');
        $events = $this->host($registry, $supervisor);

        self::assertGreaterThan(0, $events->poll(), 'the running daemon is still reported');
        self::assertSame(['bg1'], array_keys($supervisor->getActiveSessions()), 'no schedule daemon appeared');

        $supervisor->addSession(self::backgroundSession()->withStatus(BackgroundSessionStatus::Completed));
        $events->poll();
        self::assertNull($registry->busySession('nightly'), 'the settled daemon frees the row');
        self::assertSame([], $supervisor->getActiveSessions(), 'only the settled fixture remains');
        self::assertSame([], array_map(
            static fn (array $e): string => $e[0],
            array_filter($this->events, static fn (array $e): bool => str_contains($e[0], 'schedule')),
        ), 'the fired half of the roster never leaks a new event type');
    }

    public function testAHandWrittenRowLandsOnTheNextPoll(): void
    {
        $registry = ScheduleRegistry::atPath($this->root . '/schedules.json', $this->root);
        $events = $this->host($registry, new BackgroundSupervisor());
        $events->poll();
        self::assertNull($registry->get('outsider'), 'fixture: not seen before it existed');

        // A human writes the file while serve idles (future stamp: never
        // spawned, so nothing can fork).
        $author = ScheduleRegistry::atPath($this->root . '/schedules.json', $this->root);
        $author->add(new Schedule(name: 'outsider', everySeconds: 3600, prompt: 'later', nextDue: time() + 3600, root: $this->root));
        $author->persist();

        $events->poll();
        $row = $registry->get('outsider');
        self::assertNotNull($row, 'reloadIfChanged inside the pump adopts the hand edit');
        self::assertSame('later', $row->prompt);
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private function host(ScheduleRegistry $registry, BackgroundSupervisor $supervisor): BackgroundEvents
    {
        return BackgroundEvents::new(
            $supervisor,
            $this->root,
            function (string $type, array $data): void {
                $this->events[] = [$type, $data];
            },
            $registry,
        );
    }

    /** @return list<string> every emitted event type naming a schedule */
    private function scheduleEvents(): array
    {
        return array_values(array_filter(
            array_map(static fn (array $e): string => $e[0], $this->events),
            static fn (string $type): bool => str_contains($type, 'schedule'),
        ));
    }

    private static function backgroundSession(): BackgroundSession
    {
        return (new BackgroundSession(
            id: 'bg1',
            name: 'job bg1',
            agent: new Agent(
                name: 'bg-agent',
                description: 'Background agent',
                prompt: '',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'do the background work',
            workingDirectory: '/tmp',
            createdAt: new \DateTimeImmutable(),
        ))->withStatus(BackgroundSessionStatus::Running);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}
