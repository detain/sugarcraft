<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Cli\Attach;
use SugarCraft\Code\Host\RemoteSessionHost;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * ISS-114: an attachment that loses its socket used to fail the running turn
 * ("lost the server") and reject everything after. Now a drop is a pause:
 * the host redials with backoff and re-helloes with `resume` from its
 * durable cursor, the server replays what the dark missed, and the session
 * keeps streaming here. A deliberate close, a session the server can no
 * longer name at all, and an exhausted ladder stay final.
 */
final class AttachResumeTest extends TestCase
{
    private const LADDER_TOKEN = 'abababababababababababababababababababababababababababababababab';

    /** The real ladder waits 0.25…4 s between tries; tests do not wait that long. */
    private const FAST_AFTER = 0.02;

    private ProtocolFixture $fixture;

    /** @var list<Server> */
    private array $servers = [];

    /** @var list<RemoteSessionHost> */
    private array $hosts = [];

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        foreach ($this->hosts as $host) {
            $host->close();
        }
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->hosts = [];
        $this->servers = [];
        $this->fixture->tearDown();
    }

    public function testARunningTurnSurvivesADroppedSocket(): void
    {
        $server = $this->server();
        $host = $this->attached($server);
        $received = [];
        $host->onEvent(static function (array $envelope) use (&$received): void {
            $received[] = $envelope;
        });

        $host->submit('carry on');
        self::assertTrue($this->pumpUntil(fn (): bool => $this->fixture->backend->isRunning()), 'the turn started on the server');
        $before = $host->lastSeq();

        // The end dies; the turn does not. The server (the fixture's hub and
        // backend, not the socket) finishes the turn while nobody listens.
        $port = $server->port();
        $server->stop();
        self::assertTrue($this->pumpUntil(static fn (): bool => !$host->isOpen()), 'the drop is felt at once');
        $this->fixture->backend->settle(Message::assistant('carried on'));
        self::assertTrue($this->pumpUntil(fn (): bool => !$this->fixture->backend->isRunning()));

        // A second server takes the same port: the ladder's next redial lands,
        // resumes from the cursor, and the event logged in the dark replays.
        $this->server($port);
        self::assertTrue($this->pumpUntil(function () use (&$received): bool {
            return $this->countType($received, SessionEvent::TURN_COMPLETED) >= 1;
        }), 'the resumed socket replays the turn completed in the dark');
        self::assertTrue($host->isOpen(), 'the socket came back');
        self::assertGreaterThan($before, $host->lastSeq(), 'the cursor followed the replay');

        $completed = $this->ofType($received, SessionEvent::TURN_COMPLETED);
        self::assertCount(1, $completed, 'the replayed turn completes exactly once');
        self::assertTrue($completed[0]['durable'] ?? false, 'a replayed event is a durable event');

        // And the session keeps streaming live here: a second turn completes.
        $host->submit('and again');
        self::assertTrue($this->pumpUntil(fn (): bool => $this->fixture->backend->isRunning()));
        $this->fixture->backend->settle(Message::assistant('again done'));
        self::assertTrue($this->pumpUntil(function () use (&$received): bool {
            return $this->countType($received, SessionEvent::TURN_COMPLETED) === 2;
        }), 'events after the resume flow live');
    }

    public function testACursorTheTrimmedLogOutgrewResumesFromASnapshot(): void
    {
        // proto#4: the RESET arm of resume(). The replay test above pins a
        // cursor the log still covers; this pins the other answer — when the
        // log has forgotten everything past the cursor (a tiny retain trims
        // as the session turns in the dark), the server sends a fresh
        // snapshot from the newest seq, and the client must adopt it: the
        // transcript reappears, no stale event replays, and the next turn
        // still streams live through the reset cursor.
        $this->fixture->tearDown();
        $this->fixture = ProtocolFixture::new(retain: 1);

        $server = $this->server();
        $host = $this->attached($server);
        $sessionId = (string) $host->sessionId();
        $received = [];
        $host->onEvent(static function (array $envelope) use (&$received): void {
            $received[] = $envelope;
        });

        // The socket dies while the session keeps turning: a second client
        // drives a full turn through the dispatcher, and the (one-event) log
        // commits every seq in the dark, trimming the attach's cursor out of
        // replay range.
        $port = $server->port();
        $server->stop();
        self::assertTrue($this->pumpUntil(static fn (): bool => !$host->isOpen()), 'the drop is felt at once');

        $wire = $this->fixture->client('wire');
        $wire->call('session.send', [
            'sessionId' => $sessionId,
            'text' => 'turn in the dark',
            'delivery' => 'queue',
            'idempotencyKey' => 'gap-minor-dark-turn',
        ]);
        self::assertTrue($this->pumpUntil(fn (): bool => $this->fixture->backend->isRunning()), 'the dark turn started');
        $this->fixture->backend->settle(Message::assistant('carried on'));
        self::assertTrue($this->pumpUntil(fn (): bool => !$this->fixture->backend->isRunning()), 'the dark turn committed its events');

        // The redial hellos with a cursor the trimmed log cannot answer from;
        // only the snapshot path can bring the transcript back.
        $this->server($port);
        $carried = static fn (): bool => \count(\array_filter(
            $host->history(),
            static fn (Message $m): bool => $m->content === 'carried on' && $m->role === Role::Assistant,
        )) > 0;
        self::assertTrue($this->pumpUntil($carried), 'the reset snapshot carried the transcript through the drop');
        self::assertTrue($host->isOpen(), 'the socket came back');
        self::assertGreaterThan(0, $host->lastSeq(), 'the reset cursor sits at the server log, not the stale one');
        self::assertSame([], $this->ofType($received, SessionEvent::TURN_COMPLETED), 'nothing older than the snapshot replays as an event');

        // And the cursor is sane going forward: the next turn completes here, live, exactly once.
        $host->submit('and again');
        self::assertTrue($this->pumpUntil(fn (): bool => $this->fixture->backend->isRunning()));
        $this->fixture->backend->settle(Message::assistant('again done'));
        self::assertTrue($this->pumpUntil(function () use (&$received): bool {
            return $this->countType($received, SessionEvent::TURN_COMPLETED) === 1;
        }), 'events after the reset flow live');
    }

    public function testACloseDuringTheBackoffIsFinal(): void
    {
        // A queue scheduler instead of timers: the test decides exactly when
        // (and whether) the next attempt runs.
        $queue = [];
        $server = $this->server();
        $host = $this->attached($server, static function (float $seconds, \Closure $run) use (&$queue): void {
            $queue[] = $run;
        });
        $closes = [];
        $host->onClose(static function (string $reason) use (&$closes): void {
            $closes[] = $reason;
        });

        $port = $server->port();
        $server->stop();
        $this->fixture->run(0.05);
        self::assertFalse($host->isOpen(), 'the drop started the ladder — one attempt is queued, nobody is listening yet');
        self::assertCount(1, $queue);

        $host->close('user walked away');
        $this->server($port);
        foreach ($queue as $run) {
            $run();
        }
        $this->fixture->run(0.1);

        self::assertSame(['user walked away'], $closes, 'a deliberate close ends the ladder, verdict first and only');
        self::assertFalse($host->isOpen());
        self::assertSame('user walked away', $host->closeReason());
    }

    public function testTheLadderEndsWhenTheServerIsGoneForGood(): void
    {
        $server = $this->server();
        $host = $this->attached($server);
        $closes = [];
        $host->onClose(static function (string $reason) use (&$closes): void {
            $closes[] = $reason;
        });

        $server->stop();
        self::assertTrue($this->pumpUntil(function () use (&$closes): bool {
            return $closes !== [];
        }), 'after the bound of refused redials the loss is final');

        self::assertFalse($host->isOpen());
        self::assertStringContainsString('gave up', $closes[0]);
        self::assertSame($closes, [$host->closeReason()], 'one verdict, and it is the one onClose heard');
    }

    public function testAResumeTheServerRefusesIsFinal(): void
    {
        $server = $this->server();
        $host = $this->attached($server);
        $sessionId = (string) $host->sessionId();
        $closes = [];
        $host->onClose(static function (string $reason) use (&$closes): void {
            $closes[] = $reason;
        });

        $port = $server->port();
        $server->stop();
        // Both ends have to notice the close before the session can go away.
        self::assertTrue($this->pumpUntil(static fn (): bool => !$host->isOpen()));

        // Somebody deletes the session in the dark: no redial resurrects it, so
        // the resume answer is a verdict, not a retry signal.
        $this->fixture->client('wire')->call('session.delete', ['sessionId' => $sessionId]);
        $this->server($port);

        self::assertTrue($this->pumpUntil(function () use (&$closes): bool {
            return $closes !== [];
        }), 'a refused resume does not loop — it ends the attachment');
        self::assertStringContainsString('will not resume', $closes[0]);
        self::assertStringContainsString('session_not_found', $closes[0]);
        self::assertFalse($host->isOpen());
    }

    /** @param list<array<string, mixed>> $received */
    private function countType(array $received, string $type): int
    {
        return \count($this->ofType($received, $type));
    }

    /** @param list<array<string, mixed>> $received @return list<array<string, mixed>> */
    private function ofType(array $received, string $type): array
    {
        return \array_values(\array_filter($received, static fn (array $e): bool => ($e['type'] ?? null) === $type));
    }

    private function server(?int $port = null): Server
    {
        $server = Server::new(
            ServerConfig::new($this->fixture->dir)->withPort($port ?? 0),
            AuthContext::new(TokenStore::new($this->fixture->dir)->withOverride(self::LADDER_TOKEN)),
            StaticFiles::new(null),
            $this->fixture->dispatcher,
            Loop::get(),
        );
        $server->start();
        $this->servers[] = $server;

        return $server;
    }

    private function attached(Server $server, ?\Closure $after = null): RemoteSessionHost
    {
        $after ??= static function (float $seconds, \Closure $run): void {
            Loop::addTimer(self::FAST_AFTER, static function () use ($run): void {
                $run();
            });
        };
        $host = $this->fixture->await(Attach::connect(
            'ws://127.0.0.1:' . $server->port() . '/ws',
            self::LADDER_TOKEN,
            null,
            $after,
        )->then(static fn (RemoteSessionHost $host): PromiseInterface => $host->hello()->then(static fn (): PromiseInterface => $host->open())));
        self::assertInstanceOf(RemoteSessionHost::class, $host, $host instanceof \Throwable ? $host->getMessage() : 'no answer');
        $this->hosts[] = $host;
        $this->fixture->run(0.05);

        return $host;
    }

    private function pumpUntil(\Closure $condition, float $timeout = 10.0): bool
    {
        $deadline = \microtime(true) + $timeout;
        while (!$condition()) {
            if (\microtime(true) >= $deadline) {
                return false;
            }
            $this->fixture->run(0.01);
        }

        return true;
    }
}
