<?php

/**
 * C-AGT-1.b: a served `/loop` actually fires.
 *
 * Before this step the loop row registered over serve answered "registered"
 * and never ran: {@see ScheduleRegistry::fireDue()} skips the ephemeral half
 * (the daemon is none of a `/loop` row's business) and the only pump of that
 * half lived inside Chat — which a served session has no instance of. The
 * fix is a per-session key plus a per-session clock: {@see LoopCommand}
 * stamps the owning session id onto the row, {@see SessionHost::tick()}
 * fires only the rows carrying ITS id, and
 * {@see ServerContext::pump()} calls tick() for every open host at most once
 * per second, riding the pump's own clock (no timer is minted anywhere; the
 * TUI pump in Chat is byte-unchanged and still fires every in-process row).
 *
 * Pins, one per promise the step makes:
 * - a served `/loop` fires within one tick past due, notice row and turn;
 * - a busy session queues the due prompt instead of cutting in;
 * - release() drops the session's rows (the loop dies with its session);
 * - another session's row — stamped or unstamped — never fires here;
 * - ServerContext::pump() advances even an IDLE host, never twice a second.
 */

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\LoopCommand;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Role;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use PHPUnit\Framework\TestCase;

final class SessionHostLoopFireTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $project = '';

    private EnhancedSessionStore $store;

    private ScheduleRegistry $registry;

    protected function setUp(): void
    {
        $this->sandbox = \sys_get_temp_dir() . '/crush-loop-fire-' . \bin2hex(\random_bytes(6));
        $this->project = $this->sandbox . '/project';
        \mkdir($this->project, 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $this->store->createSession('s', 'p', 'm');
        $this->store->createSession('t', 'p', 'm');
        $this->registry = ScheduleRegistry::inMemory();
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox));
        }
    }

    // ── pins ───────────────────────────────────────────────────────────

    public function testAServedLoopFiresWithinOneTickPastDue(): void
    {
        $host = $this->host('s');
        $this->registerLoopOn($host, 's', '/loop 10m write tests');
        $dueAt = time() - 1;
        $this->registry->noteNextDue('loop-1', $dueAt);

        self::assertSame(1, $host->tick($dueAt + 1), 'one due row, one fire');

        $noticeRows = \array_values(\array_filter(
            $host->history(),
            static fn (Message $row): bool => \str_contains($row->content, "Loop 'loop-1' is due"),
        ));
        self::assertCount(1, $noticeRows, 'the fire leaves a notice row, as the TUI pump does');
        self::assertStringContainsString('queues for this session', $noticeRows[0]->content);

        $this->drain($host);
        $firedTurn = \array_values(\array_filter(
            $host->history(),
            static fn (Message $row): bool => $row->content === 'write tests',
        ));
        self::assertCount(1, $firedTurn, 'the prompt became a real turn of THIS session');

        $rearmed = $this->registry->get('loop-1');
        self::assertNotNull($rearmed, 'the row survives its own firing');
        self::assertGreaterThan($dueAt + 1, $rearmed->nextDue, 'the fire re-armed the cadence');
        self::assertSame(0, $host->tick(time()), 'not due again yet — the second tick fires nothing');
    }

    public function testADueLoopOnABusySessionQueuesBehindTheRunningTurn(): void
    {
        $backend = self::scripted();
        $host = $this->host('s', $backend);
        $this->registerLoopOn($host, 's', '/loop 10m write tests');
        $dueAt = time() - 1;
        $this->registry->noteNextDue('loop-1', $dueAt);

        $host->submit('a human turn first');
        self::assertTrue($host->isBusy(), 'the fixture is only meaningful while a turn runs');

        self::assertSame(1, $host->tick($dueAt + 1), 'a due row still advances while busy');
        self::assertSame(['write tests'], $host->queued(), 'the prompt waits behind the turn, not inside it');
        $ranNow = \array_values(\array_filter(
            $host->history(),
            static fn (Message $row): bool => $row->content === 'write tests',
        ));
        self::assertCount(0, $ranNow, 'it did not cut into the running turn');

        // The promise of a Followup: it goes out with the running turn's settle.
        $backend->settle(Message::assistant('done'));
        $this->drain($host);
        $went = \array_values(\array_filter(
            $host->history(),
            static fn (Message $row): bool => $row->content === 'write tests',
        ));
        self::assertCount(1, $went, 'the queued loop prompt ran once the human turn settled');
        self::assertSame([], $host->queued(), 'and left the queue behind');
    }

    public function testReleaseDropsTheSessionsLoopRows(): void
    {
        $host = $this->host('s');
        $this->registerLoopOn($host, 's', '/loop 10m write tests');
        $this->registry->noteNextDue('loop-1', time() - 1);

        $host->release();

        self::assertNull($this->registry->get('loop-1'), 'the loop died with its session');
        self::assertSame(0, $host->tick(time()), 'nothing left to fire');
    }

    public function testAnotherSessionsRowNeverFiresHere(): void
    {
        $host = $this->host('s');
        $overdue = time() - 5;
        // A tab-owning row (stamped to someone else) and a legacy row (the TUI
        // half stamps its own id too, but a hand-built or pre-step row carries
        // null): neither belongs to host 's'.
        $this->registry->add(new Schedule(
            name: 'loop-1',
            everySeconds: 600,
            prompt: 'tab two work',
            nextDue: $overdue,
            persisted: false,
            sessionId: 't',
        ));
        $this->registry->add(new Schedule(
            name: 'loop-2',
            everySeconds: 600,
            prompt: 'unowned work',
            nextDue: $overdue,
            persisted: false,
        ));

        self::assertSame(0, $host->tick(time()));
        self::assertSame([], $host->queued(), 'no stray prompt was admitted');
        self::assertSame($overdue, $this->registry->get('loop-1')?->nextDue, "tab two's clock is untouched");
        self::assertSame($overdue, $this->registry->get('loop-2')?->nextDue, 'an unstamped row is the TUI pump alone');
    }

    public function testTheServerPumpTicksIdleHostsAndNeverTwiceASecond(): void
    {
        $hub = SessionHub::new($this->workspace());
        $host = $hub->open('s');
        $this->registerLoopOn($host, 's', '/loop 10m write tests');

        $now = time() - 1;
        $context = ServerContext::new(
            $hub,
            ServerConfig::new($this->sandbox)->withRoot($this->project),
            'test',
            Loop::get(),
            static function () use (&$now): float {
                return (float) $now;
            },
        );

        $this->registry->noteNextDue('loop-1', $now);
        $context->pump();
        $afterFirst = $this->registry->get('loop-1')?->nextDue;
        self::assertGreaterThan($now, (int) $afterFirst, 'the pump fired the due loop of an idle host');

        // Rewind the cadence INSIDE the same wall-second: the rate gate, not
        // the due math, must be what keeps this unfired.
        $this->registry->noteNextDue('loop-1', $now);
        $context->pump();
        self::assertSame($now, $this->registry->get('loop-1')?->nextDue, 'at most one loop advance per second');

        $now += 2;
        $context->pump();
        self::assertGreaterThan($now, (int) $this->registry->get('loop-1')?->nextDue, 'the next second advances again');

        $this->drain($host);
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function workspace(?Backend $backend = null): WorkspaceContext
    {
        return WorkspaceContext::new(
            root: $this->project,
            sessionStore: $this->store,
            backend: $backend ?? new EchoBackend(),
        )->withService(ScheduleRegistry::class, $this->registry);
    }

    private function host(string $sessionId, ?Backend $backend = null): SessionHost
    {
        return SessionHost::new($sessionId, $this->workspace($backend), history: [
            Message::user('hello'),
            Message::assistant('hi'),
        ]);
    }

    /**
     * Register through the same seams the host uses: its commandContext hands
     * LoopCommand its own session id and the workspace's registry service, so
     * the row lands stamped exactly as a served `/loop` lands it in
     * production.
     */
    private function registerLoopOn(SessionHost $host, string $sessionId, string $text): void
    {
        $context = CommandContext::new(
            sessionId: $sessionId,
            root: $this->project,
            workspace: $this->workspace(),
            scheduleRegistry: $this->registry,
        );
        (new LoopCommand())->run($context, $text);
        self::assertNotNull($this->registry->get('loop-1'), 'the /loop itself registered its row');
    }

    /**
     * A backend whose turn settles when the test says so.
     */
    private static function scripted(): object
    {
        return new class () implements Backend {
            /** @var list<Message> the newest user row of each dispatch */
            public array $sent = [];

            public ?\Closure $emit = null;

            private ?Deferred $deferred = null;

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                for ($i = \count($history) - 1; $i >= 0; $i--) {
                    if ($history[$i]->role === Role::User) {
                        $this->sent[] = $history[$i];
                        break;
                    }
                }
                $this->deferred = new Deferred();
                $this->emit = static fn (object $event) => $onEvent === null ? null : $onEvent($event);

                return $this->deferred->promise();
            }

            public function settle(Message $reply): void
            {
                $deferred = $this->deferred;
                $this->deferred = null;
                $deferred?->resolve($reply);
            }
        };
    }

    private function drain(SessionHost $host): void
    {
        if (!$host->isBusy()) {
            return;
        }

        $loop = Loop::get();
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($host, $loop): void {
            if (!$host->isBusy()) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(15.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);
    }
}
