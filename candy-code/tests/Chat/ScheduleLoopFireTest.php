<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\App\BackgroundTickMsg;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Sessions\BackgroundSession;
use SugarCraft\Code\Sessions\BackgroundSessionStatus;
use SugarCraft\Code\Sessions\BackgroundSupervisor;
use SugarCraft\Code\Sessions\Schedule;
use SugarCraft\Code\Sessions\ScheduleRegistry;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * AUT-1: the schedule clock runs INSIDE the pumps (plan §3 AUT-1 — the whole
 * point is that no separate daemon or timer exists). Driven through the real
 * `update()` entry with the {@see BackgroundTickMsg} the live poll sends:
 *
 * - an idle chat with a due `/loop` row starts it as a turn (the queue door is
 *   the same one settled announcements ride);
 * - a busy chat lets the prompt WAIT in the queue while the stamp advances
 *   (due-COUNTS, never timeouts — E646);
 * - a persisted row whose previous daemon is still up is skipped-BUT-ADVANCED
 *   and never spawns a second time; once the daemon settles, the busy marker
 *   retires;
 * - the pump's tick subscription arms from the roster alone, with no daemons.
 *
 * Nothing here may reach `spawnSession()` for real (it would fork): every
 * persisted-row case parks the row behind a busy marker first.
 */
final class ScheduleLoopFireTest extends TestCase
{
    /** Chat's background-poll subscription id (test-side spelling, ReadOnlySessionTest::LOCK_RETRY precedent). */
    private const BACKGROUND_POLL = 'code.background-poll';

    private string $session;

    private string $root;

    protected function setUp(): void
    {
        $this->session = 'sched-loop-' . bin2hex(random_bytes(4));
        $this->root = sys_get_temp_dir() . '/sc_sched_loop_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    public function testADueLoopRowStartsATurnOnAnIdleChat(): void
    {
        $registry = ScheduleRegistry::inMemory();
        $registry->add(new Schedule(
            name: 'loop-1',
            everySeconds: 60,
            prompt: 'check the build',
            nextDue: time() - 5,
            persisted: false,
        ));
        $chat = new Chat(backend: self::engine($this->session, $this->root), scheduleRegistry: $registry);

        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        self::assertNotNull($cmd, 'the released prompt comes back with its Cmd, or it is never sent');
        self::assertTrue($next->inFlight, 'an idle chat starts the loop prompt at once');
        self::assertSame([], $next->queuedPrompts());
        $row = $next->history[array_key_last($next->history)];
        self::assertSame(Role::User, $row->role);
        self::assertSame('check the build', $row->content, 'the prompt rides the queue verbatim');
        self::assertContains(
            Lang::t('chat.schedule.loop_queued', ['name' => 'loop-1']),
            array_map(static fn (Message $m): string => $m->content, $next->history),
        );
        self::assertGreaterThan(time(), $registry->get('loop-1')?->nextDue ?? 0, 'the stamp advanced one slot');
    }

    public function testABusyChatQueuesTheLoopPromptAndStillAdvances(): void
    {
        $registry = ScheduleRegistry::inMemory();
        $registry->add(new Schedule(
            name: 'loop-1',
            everySeconds: 60,
            prompt: 'check the build',
            nextDue: time() - 5,
            persisted: false,
        ));
        $chat = new Chat(
            inputBuf: 'the first thing',
            backend: self::engine($this->session, $this->root),
            scheduleRegistry: $registry,
        );
        [$busy] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        self::assertTrue($busy->inFlight, 'fixture: a turn is running');

        [$next] = $busy->update(new BackgroundTickMsg());

        self::assertSame(['check the build'], $next->queuedPrompts(), 'the prompt waits for the running turn');
        self::assertTrue($next->inFlight);
        self::assertGreaterThan(time(), $registry->get('loop-1')?->nextDue ?? 0, 'waiting does not stack slots (E646)');
    }

    public function testAPersistedRowBehindABusyDaemonSkipsAdvancesAndNeverForks(): void
    {
        $dir = $this->root . '/reg';
        mkdir($dir, 0o700, true);
        $registry = ScheduleRegistry::atPath($dir . '/schedules.json');
        $registry->add(new Schedule(
            name: 'nightly',
            everySeconds: 60,
            prompt: 'do the nightly thing',
            nextDue: time() - 60,
        ));
        $registry->noteFired('nightly', 'bg1');
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession(self::backgroundSession());
        $chat = new Chat(
            backend: self::engine($this->session, $this->root),
            backgroundSupervisor: $supervisor,
            scheduleRegistry: $registry,
        );

        [$next] = $chat->update(new BackgroundTickMsg());

        $contents = array_map(static fn (Message $m): string => $m->content, $next->history);
        self::assertNotContains(
            Lang::t('chat.schedule.fired', ['name' => 'nightly']),
            $contents,
            'the busy row never fired',
        );
        self::assertSame(['bg1'], array_keys($supervisor->getActiveSessions()), 'no second daemon was spawned');
        self::assertGreaterThan(time(), $registry->get('nightly')?->nextDue ?? 0, 'the skip still advanced the slot');

        // The settled flip on the NEXT tick retires the busy marker only.
        $supervisor->addSession(self::backgroundSession()->withStatus(BackgroundSessionStatus::Completed));
        $registry->noteFired('nightly', 'bg1');
        [$after] = $next->update(new BackgroundTickMsg());

        self::assertNotSame($next, $after, 'the completed daemon is announced');
        self::assertNull($registry->busySession('nightly'), 'and the busy marker retires with it');
        self::assertSame([], $supervisor->getActiveSessions(), 'nothing new was running - no fork happened');
    }

    public function testTheRosterAloneArmsThePumpSubscription(): void
    {
        $enabled = ScheduleRegistry::inMemory();
        $enabled->add(new Schedule(name: 'loop-1', everySeconds: 60, prompt: 'p', nextDue: time() + 60, persisted: false));
        $chat = new Chat(backend: self::engine(null), scheduleRegistry: $enabled);
        self::assertTrue(
            $chat->subscriptions()?->has(self::BACKGROUND_POLL) ?? false,
            'enabled rows keep the clock polling even with no daemons',
        );

        $quiet = ScheduleRegistry::inMemory();
        $idle = new Chat(backend: self::engine(null), scheduleRegistry: $quiet);
        self::assertFalse(
            $idle->subscriptions()?->has(self::BACKGROUND_POLL) ?? false,
            'an empty roster arms nothing',
        );
    }

    // =====================================================================
    // Fixtures — backgroundSession()/engine()/remove() per MainMailboxWakeTest
    // =====================================================================

    private static function engine(?string $session, ?string $root = null): EngineBackend
    {
        $engine = EngineBackend::new(new ScriptedProvider([]), 'm')->withoutHooks()->withSessionId($session);

        return $root === null ? $engine : $engine->withRoot($root);
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
