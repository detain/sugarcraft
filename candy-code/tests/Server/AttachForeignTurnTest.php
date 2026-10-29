<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Code\App\RemoteFeedPumpMsg;
use SugarCraft\Code\Backend\RemoteBackend;
use SugarCraft\Code\Cli\Attach;
use SugarCraft\Code\Host\RemoteSessionHost;
use SugarCraft\Code\Message;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Chat;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-SES-7.f: an attached window follows ANOTHER client's turns live.
 *
 * {@see RemoteBackend}'s persistent host listener keeps an inbox of every
 * turn it did not start, keyed by `turnId` — deltas buffer under their own
 * turn and never touch the window's own projection — and the inbox folds
 * into history whole when a foreign `turn.completed` lands, one pump of
 * {@see RemoteFeedPumpMsg} later. Before this step a foreign turn showed up
 * only when the window re-attached; here nothing closes, nothing reopens.
 */
final class AttachForeignTurnTest extends TestCase
{
    use HomeSandboxTrait;

    private const ATTACH_TOKEN = 'abababababababababababababababababababababababababababababababab';

    private ProtocolFixture $fixture;

    private Server $server;

    private string $sandbox = '';

    /** @var list<RemoteSessionHost> */
    private array $hosts = [];

    protected function setUp(): void
    {
        $this->sandbox = \sys_get_temp_dir() . '/ccmp-csess7f-f-' . \bin2hex(\random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->fixture = ProtocolFixture::new();
        $this->server = Server::new(
            ServerConfig::new($this->fixture->dir)->withPort(0),
            AuthContext::new(TokenStore::new($this->fixture->dir)->withOverride(self::ATTACH_TOKEN)),
            StaticFiles::new(null),
            $this->fixture->dispatcher,
            Loop::get(),
        );
        $this->server->start();
    }

    protected function tearDown(): void
    {
        foreach ($this->hosts as $host) {
            $host->close();
        }
        RemoteSessionHost::useForLaunch(null);
        $this->server->stop();
        $this->fixture->tearDown();
        $this->restoreHomeSandbox();
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox));
        }
    }

    public function testASecondClientsTurnAppearsWithoutReAttaching(): void
    {
        $hostA = $this->attached();
        [$chatA, $backendA] = $this->window($hostA);
        $attachedId = (string) $hostA->sessionId();

        // A window turn first: it makes the session LISTABLE on the server
        // (a fresh create has no store rows yet), which is what a second
        // client attaches against — and it doubles as the proof that the
        // window's OWN turn never enters its foreign inbox.
        $seed = $backendA->completeInteractive([Message::user('seed from this window')]);
        self::assertTrue(
            $this->until(fn (): bool => $this->fixture->backend->sent !== []),
            'the seed turn never reached the server',
        );
        $this->fixture->backend->settle(Message::assistant('seeded'));
        $this->await($seed);

        // A second client sends a real prompt onto the SAME session (a
        // live attachment's session id is not in the list a fresh open()
        // target resolves against, so the foreign actor here is the plain
        // protocol client — the server broadcasts its turn to every
        // subscriber, window A included). Host A never closes.
        $foreign = $this->fixture->client('other-window');
        $foreign->call('session.send', [
            'sessionId' => $attachedId,
            'text' => 'from the other window',
        ]);
        self::assertTrue(
            $this->until(fn (): bool => count($this->fixture->backend->sent) >= 2),
            'the server never picked the second client up',
        );
        self::assertTrue(
            $this->until(fn (): bool => $this->fixture->backend->isRunning()),
            'the foreign turn never started',
        );
        $this->fixture->backend->token('Hello ');
        // Mid-stream the window must still show NOTHING of the foreign turn:
        // the fold law is completion-gated (turn.completed), not per-delta.
        $this->fixture->run(0.05);
        [$midPump] = $chatA->update(new RemoteFeedPumpMsg());
        self::assertCount(
            2,
            $midPump->history,
            'a foreign turn still streaming must not fold row-by-row into the window',
        );
        $this->fixture->backend->token('there');
        $this->fixture->backend->settle(Message::assistant('Hello there'));

        self::assertTrue(
            $this->until(static fn (): bool => $backendA->remoteFeedPending()),
            'the foreign turn never completed in window A\'s inbox',
        );
        self::assertTrue($hostA->isOpen(), 'window A never re-attached');

        [$folded] = $chatA->update(new RemoteFeedPumpMsg());
        $rows = \array_map(
            static fn (Message $m): array => [$m->role->value, $m->content],
            array_slice($folded->history, 2),
        );
        self::assertSame([
            ['user', 'from the other window'],
            ['assistant', 'Hello there'],
        ], $rows, 'the prompt and the full reply fold as one pair, at completion');

        self::assertFalse($backendA->remoteFeedPending(), 'the inbox is drained');
        [$again] = $folded->update(new RemoteFeedPumpMsg());
        self::assertSame(
            array_map(static fn (Message $m): string => $m->content, $folded->history),
            array_map(static fn (Message $m): string => $m->content, $again->history),
            'pumping twice must not fold the same foreign turn twice',
        );
    }

    public function testForeignDeltasNeverInterleaveWithTheWindowsOwnTurn(): void
    {
        $hostA = $this->attached();
        [$chatA, $backendA] = $this->window($hostA);
        $sessionId = (string) $hostA->sessionId();

        // The window's own turn goes live on the server…
        $ownTokens = [];
        $own = $backendA->completeInteractive(
            [Message::user('mine')],
            static function (string $t) use (&$ownTokens): void {
                $ownTokens[] = $t;
            },
            null,
            null,
            null,
            null,
        );
        self::assertTrue(
            $this->until(fn (): bool => $this->fixture->backend->isRunning()),
            'the window turn never started',
        );

        // …and a foreign prompt lands WHILE it runs: the server queues it
        // (turns serialize), so its frames overlap this window's turn only
        // in the feed's inbox sense — the exact race the turnId keying
        // governs. Its delta bytes must never join the own projection, and
        // the own reply must never fold back as foreign.
        $foreign = $this->fixture->client('other-window');
        $this->fixture->backend->token('M');
        // Queue a foreign prompt while this window's turn still runs: the
        // law under test is that a foreign turn — which the server serializes
        // behind the own one — interleaves NOTHING into this window's live
        // projection; it arrives only as a folded pair at its completion.
        $foreign->call('session.send', [
            'sessionId' => $sessionId,
            'text' => 'injected prompt',
        ]);
        $this->fixture->backend->settle(Message::assistant('Mine'));
        $reply = $this->await($own);
        self::assertSame('Mine', $reply->content ?? null, 'the own reply still settles whole');
        foreach ($ownTokens as $frame) {
            self::assertStringNotContainsString('XY', $frame, 'a queued foreign turn interleaves no bytes into the own stream');
        }

        // Now the queued foreign turn runs; stream it too.
        self::assertTrue(
            $this->until(fn (): bool => count($this->fixture->backend->sent) >= 2),
            'the queued foreign turn never started',
        );
        $this->fixture->backend->token('XY');
        $this->fixture->backend->settle(Message::assistant('XY'));

        self::assertTrue(
            $this->until(static fn (): bool => $backendA->remoteFeedPending()),
            'the foreign turn never completed in the inbox',
        );
        [$folded] = $chatA->update(new RemoteFeedPumpMsg());
        $rows = array_map(
            static fn (Message $m): array => [$m->role->value, $m->content],
            $folded->history,
        );
        self::assertSame(
            [['user', 'hello'], ['assistant', 'hi'], ['user', 'injected prompt'], ['assistant', 'XY']],
            $rows,
            'the foreign pair lands whole; the window\'s own turn streamed, never folded',
        );

        // And the own reply must not come back through the feed either:
        // pumping again changes nothing.
        self::assertFalse($backendA->remoteFeedPending());
        [$again] = $folded->update(new RemoteFeedPumpMsg());
        self::assertSame(
            array_map(static fn (Message $m): string => $m->content, $folded->history),
            array_map(static fn (Message $m): string => $m->content, $again->history),
        );
    }

    public function testSubscriptionsDeclareTheFeedPumpWhileAttachedAndIdleTicksSayNothing(): void
    {
        $hostA = $this->attached();
        [$chatA, $backendA] = $this->window($hostA);

        $subs = $chatA->subscriptions();
        self::assertInstanceOf(Subscriptions::class, $subs);
        self::assertTrue($subs->has('remote.feed'), 'an attached window always carries the feed pump');

        $tick = null;
        foreach ($subs->all() as $sub) {
            if ($sub->id === 'remote.feed') {
                $tick = $sub;
            }
        }
        self::assertNotNull($tick);

        // Idle: the factory answers null, so an attached-but-quiet window
        // repaints nothing on the tick.
        self::assertNull(($tick->produce)(), 'an empty inbox must not wake the model');

        // Pending: the exec queue alone is enough to arm a pump.
        $backendA->queueRemoteExec(['rows' => [], 'effects' => []]);
        $msg = ($tick->produce)();
        self::assertInstanceOf(RemoteFeedPumpMsg::class, $msg);

        // And a window that never attached declares no such tick.
        $plain = (new Chat(history: [], backend: new \SugarCraft\Code\Backend\EchoBackend()))->withSize(100, 30);
        $plainSubs = $plain->subscriptions();
        self::assertTrue($plainSubs === null || !$plainSubs->has('remote.feed'));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0: Chat, 1: RemoteBackend} */
    private function window(RemoteSessionHost $host, string $draft = ''): array
    {
        $backend = RemoteBackend::new($host);
        $chat = (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: $backend,
        ))->withSize(100, 30);

        return [$chat, $backend];
    }

    private function wsUrl(): string
    {
        return 'ws://127.0.0.1:' . $this->server->port() . '/ws';
    }

    private function attached(?string $target = null): RemoteSessionHost
    {
        $host = $this->await(Attach::connect($this->wsUrl(), self::ATTACH_TOKEN)->then(
            static fn (RemoteSessionHost $host): PromiseInterface => $host->hello()->then(static fn (): PromiseInterface => $host->open($target)),
        ));
        self::assertInstanceOf(RemoteSessionHost::class, $host, $host instanceof \Throwable ? $host->getMessage() : 'no answer');
        $this->hosts[] = $host;
        $this->fixture->run(0.05);

        return $host;
    }

    /** Run the loop until $condition holds (or five seconds pass); whether it did. */
    private function until(\Closure $condition, float $timeout = 5.0): bool
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

    private function await(PromiseInterface $promise): mixed
    {
        return $this->fixture->await($promise);
    }
}
