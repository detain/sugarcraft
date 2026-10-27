<?php

/**
 * C-CTX-2.a: a served runtime notice reaches the transcript.
 *
 * Before this step, serve armed the process-wide runtime-notice sink in
 * {@see \SugarCraft\Code\Cli\Bootstrap::workspace()} and never drained it:
 * the only drain in the tree lived in {@see Chat::pumpRuntimeNotices()}, and
 * a served session has no Chat instance. What a turn's forked child recorded
 * — the count-only `💭 Recalled` row, a parser warning off the wire — went
 * into an inbox nobody read and vanished. The fix is per-session: each
 * {@see SessionHost} owns a sink, armed exactly when the workspace's is, and
 * every fork it starts runs under {@see RuntimeNoticeSink::using()}; the
 * drain fires on the sink's one-shot loop wake, at {@see SessionHost::pump()}
 * and at settle — no timer is minted anywhere (E646).
 *
 * Pins, one per promise the step makes:
 * - a notice recorded inside a served turn lands as its own uiOnly row and
 *   as the durable message.created whose kind is `notice`;
 * - the row belongs to the session whose host ran the turn, never a
 *   neighbouring one;
 * - a host over a workspace nobody armed stays unarmed (tests and embedders
 *   keep dropping, as before);
 * - the wake re-drains after every drain, the one that found nothing
 *   included — the renewal Chat pins in RuntimeNoticeSinkDeliveryTest;
 * - release() closes the inbox: disarmed, transport gone, watcher cancelled;
 * - the drain path is wake-driven: no timer call appears in its source.
 */

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Diagnostics\NoticeSink;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use PHPUnit\Framework\TestCase;

final class ServedRuntimeNoticeDrainTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $project = '';

    private EnhancedSessionStore $store;

    /** @var list<SessionHost> hosts to close, watchers and fds included */
    private array $hosts = [];

    protected function setUp(): void
    {
        $this->sandbox = \sys_get_temp_dir() . '/crush-ctx2a-' . \bin2hex(\random_bytes(6));
        $this->project = $this->sandbox . '/project';
        \mkdir($this->project, 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $this->store->createSession('s', 'p', 'm');
        $this->store->createSession('t', 'p', 'm');
    }

    protected function tearDown(): void
    {
        foreach ($this->hosts as $host) {
            $host->release();
        }
        $this->hosts = [];
        // The process sink this test armed (Bootstrap::workspace() does the
        // same on serve) must not carry watchers or fds into the next test.
        RuntimeNoticeSink::reset();
        $this->restoreHomeSandbox();
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox));
        }
    }

    // ── pins ───────────────────────────────────────────────────────────

    public function testANoticeRecordedInsideAServedTurnLandsAsItsOwnRow(): void
    {
        RuntimeNoticeSink::arm();
        $sentinel = 'ctx2a-' . \bin2hex(\random_bytes(6));
        $backend = self::recording($sentinel);
        $host = $this->host('s', $backend);

        $host->submit('go');
        self::assertTrue($host->isBusy(), 'the turn is in flight while the notice is recorded');
        $backend->settle(Message::assistant('done'));

        $rows = $this->noticeRows($host, $sentinel);
        self::assertCount(1, $rows, 'the settle-time drain lands the turn\'s notice as one row');
        self::assertSame(Role::System, $rows[0]->role, 'the row is a notice: system role');
        self::assertTrue($rows[0]->uiOnly, 'uiOnly — saved and shown, never back to the model');

        $announced = $this->noticeEvents($host, 's', $sentinel);
        self::assertCount(1, $announced, 'the row is announced as a durable message.created');
        self::assertSame('notice', $announced[0]['payload']['kind'], 'kind notice, the shape the web reducer paints');
    }

    public function testANoticeStaysWithTheSessionThatRecordedIt(): void
    {
        RuntimeNoticeSink::arm();
        $mine = 'ctx2a-mine-' . \bin2hex(\random_bytes(6));
        $theirs = 'ctx2a-theirs-' . \bin2hex(\random_bytes(6));
        $hostS = $this->host('s', self::recording($mine));
        $hostT = $this->host('t', self::recording($theirs));

        $hostS->submit('work s');
        $hostT->submit('work t');
        $hostS->pump();
        $hostT->pump();

        self::assertCount(1, $this->noticeRows($hostS, $mine), 'the session that ran the turn keeps its row');
        self::assertCount(0, $this->noticeRows($hostS, $theirs), "another session's notice cannot cross in");
        self::assertCount(1, $this->noticeRows($hostT, $theirs), 'and the neighbour keeps its own');
        self::assertCount(0, $this->noticeRows($hostT, $mine), 'in either direction');
    }

    public function testAHostOverAnUnarmedWorkspaceStaysUnarmed(): void
    {
        // The reset extension leaves the process sink unarmed, the shape of
        // every test and embedder that never ran Bootstrap::workspace().
        $sentinel = 'ctx2a-' . \bin2hex(\random_bytes(6));
        $backend = self::recording($sentinel);
        $host = $this->host('s', $backend);

        $host->submit('go');
        $backend->settle(Message::assistant('done'));

        self::assertCount(0, $this->noticeRows($host, $sentinel), 'an inbox nobody armed keeps dropping');
        $sink = self::sinkOf($host);
        self::assertFalse($sink->isArmed(), 'the host never invents an arm the launch declined');
        self::assertFalse($sink->hasTransport());
        self::assertFalse($sink->isNotificationArmed(), 'no watcher on a sink that cannot have one');
    }

    public function testTheWakeRedrainsAfterEveryDrainIncludingOneThatFoundNothing(): void
    {
        RuntimeNoticeSink::arm();
        $host = $this->host('s', new EchoBackend());
        $host->submit('go');
        self::assertFalse($host->isBusy(), 'echo settles inside the dispatch window');

        $sink = self::sinkOf($host);
        self::assertTrue($sink->hasTransport(), 'the armed workspace gave this host a real inbox');
        self::assertTrue($sink->isNotificationArmed(), 'the wake is listening from the host\'s first breath');

        $first = 'ctx2a-early-' . \bin2hex(\random_bytes(6));
        self::assertTrue($sink->record($first), 'this process wrote its own inbox');
        $this->spin();
        self::assertCount(1, $this->noticeRows($host, $first), 'the wake drained it — no pump call was needed');

        // A drain that found nothing must renew too: the read of the last
        // datagram is exactly the moment to start listening for the next.
        $host->pump();
        $second = 'ctx2a-late-' . \bin2hex(\random_bytes(6));
        $sink->record($second);
        $this->spin();
        self::assertCount(1, $this->noticeRows($host, $second), 'and the next notice still wakes the drain');
    }

    public function testReleaseClosesTheInboxWithTheSession(): void
    {
        RuntimeNoticeSink::arm();
        $host = $this->host('s', new EchoBackend());
        $host->submit('go');
        $sink = self::sinkOf($host);
        self::assertTrue($sink->isArmed(), 'the fixture: this host owns an armed inbox');

        $host->release();

        self::assertFalse($sink->isArmed(), 'the session\'s inbox dies with the session');
        self::assertFalse($sink->hasTransport(), 'both socket fds are gone');
        self::assertFalse($sink->isNotificationArmed(), 'no watcher is left over a closed stream');
    }

    public function testTheServedDrainIsWakeDrivenNotTimerDriven(): void
    {
        // E646's shape: the rate lives with the wake, never in a minted
        // timer. Scan the drain's own source, not a behaviour the fixtures
        // would share with every other loop user.
        foreach (['pumpRuntimeNotices', 'armNoticeWake'] as $method) {
            $reflection = new \ReflectionMethod(SessionHost::class, $method);
            $lines = \array_slice(
                \file($reflection->getFileName()),
                $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1,
            );
            $source = \implode('', $lines);
            self::assertStringNotContainsString('addTimer', $source, $method . ' must not mint a timer');
            self::assertStringNotContainsString('addPeriodicTimer', $source, $method . ' must not mint a cadence');
        }
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function workspace(Backend $backend): WorkspaceContext
    {
        return WorkspaceContext::new(
            root: $this->project,
            sessionStore: $this->store,
            backend: $backend,
        );
    }

    private function host(string $sessionId, Backend $backend): SessionHost
    {
        $host = SessionHost::new($sessionId, $this->workspace($backend), history: [
            Message::user('hello'),
            Message::assistant('hi'),
        ]);
        $this->hosts[] = $host;

        return $host;
    }

    /**
     * A backend that records through the sink its dispatch is routed to —
     * the parent-side shape of what EngineBackend's forked child does after
     * enterForkedChild() pins it. The turn settles when the test says so.
     */
    private static function recording(string $notice): object
    {
        return new class ($notice) implements Backend {
            private ?Deferred $deferred = null;

            public function __construct(private readonly string $toRecord)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                if ($this->toRecord !== '') {
                    RuntimeNoticeSink::current()->record($this->toRecord);
                }
                $this->deferred = new Deferred();

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

    /** @return list<Message> */
    private function noticeRows(SessionHost $host, string $needle): array
    {
        return \array_values(\array_filter(
            $host->history(),
            static fn (Message $row): bool => $row->role === Role::System && \str_contains($row->content, $needle),
        ));
    }

    /** @return list<array{seq:int,ts:int,type:string,payload:array<string,mixed>}> */
    private function noticeEvents(SessionHost $host, string $sessionId, string $needle): array
    {
        $events = $host->events();
        self::assertNotNull($events, 'the fixture store keeps a durable event log');

        return \array_values(\array_filter(
            $events->since($sessionId),
            static fn (array $event): bool => $event['type'] === 'message.created'
                && ($event['payload']['kind'] ?? null) === 'notice'
                && \str_contains((string) ($event['payload']['content'] ?? ''), $needle),
        ));
    }

    /** Let the loop service its read watchers for a bounded beat. */
    private function spin(float $seconds = 0.05): void
    {
        $loop = Loop::get();
        $loop->addTimer($seconds, static fn () => $loop->stop());
        $loop->run();
    }

    private static function sinkOf(SessionHost $host): NoticeSink
    {
        $property = new \ReflectionProperty(SessionHost::class, 'notices');

        return $property->getValue($host);
    }
}
