<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\RemoteBackend;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\Attach;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Events\PermissionAsked;
use SugarCraft\Crush\Events\PermissionResolved;
use SugarCraft\Crush\Events\StepStarted;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Host\RemoteSessionHost;
use SugarCraft\Crush\Host\SessionEvent;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Server\Auth\AuthContext;
use SugarCraft\Crush\Server\Auth\TokenStore;
use SugarCraft\Crush\Server\DiscoveryFile;
use SugarCraft\Crush\Server\Http\StaticFiles;
use SugarCraft\Crush\Server\Server;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Server\StateDir;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Roadmap O-8a: the TUI as a client of a running `sugarcrush serve`.
 *
 * End to end over a real socket — {@see Attach::connect()}'s HTTP upgrade to
 * the server's `sugarcrush.v1` WebSocket, {@see RemoteSessionHost}'s
 * JSON-RPC, and a {@see RemoteBackend} turn projected back onto the callbacks
 * a Chat gets from a local engine turn: streamed text, tool rows, the step
 * frame, a permission question answered here or by another client first,
 * cancelling, and the one-shot completions an attachment refuses. Also the
 * CLI verb's refusals and the launch seams (`openSession`, the read-only
 * notice's attach offer).
 */
final class RemoteBackendTest extends TestCase
{
    private const ATTACH_TOKEN = 'abababababababababababababababababababababababababababababababab';

    private ProtocolFixture $fixture;

    private Server $server;

    /** @var list<RemoteSessionHost> */
    private array $hosts = [];

    protected function setUp(): void
    {
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
        \putenv('SUGARCRUSH_SERVER_DIR');
        \putenv('SUGARCRUSH_SERVER_TOKEN');
    }

    public function testAttachConnectsSaysHelloAndOpensANewSession(): void
    {
        $host = $this->attached();

        self::assertTrue($host->isOpen());
        self::assertSame($this->fixture->root, $host->serverRoot());
        self::assertSame('bearer', $host->helloResult()['principal']['via'] ?? null);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $host->sessionId());
        self::assertSame([], $host->history());
        self::assertTrue($this->fixture->hub->isOpen((string) $host->sessionId()), 'subscribing opened the session on the server');
    }

    public function testAWrongTokenIsRefusedAtTheUpgrade(): void
    {
        $result = $this->await(Attach::connect($this->wsUrl(), \str_repeat('cd', 32)));

        self::assertInstanceOf(\RuntimeException::class, $result);
        self::assertStringContainsString('401', $result->getMessage());
    }

    public function testATurnRunsOnTheServerAndStreamsBackAsEngineEvents(): void
    {
        $host = $this->attached();
        $backend = RemoteBackend::new($host);
        $tokens = [];
        $events = [];
        $steps = [];

        $reply = $backend->completeInteractive(
            [Message::user('list the files')],
            static function (string $t) use (&$tokens): void {
                $tokens[] = $t;
            },
            null,
            static function (object $e) use (&$events): void {
                $events[] = $e;
            },
            null,
            static function (object $s) use (&$steps): void {
                $steps[] = $s;
            },
        );
        self::assertTrue($this->until(fn (): bool => $this->fixture->backend->sent !== []), 'the server never ran the prompt');
        self::assertSame(['list the files'], $this->fixture->backend->sent);

        $server = $this->fixture->backend;
        $session = $this->fixture->hub->get((string) $host->sessionId());
        self::assertNotNull($session);
        $server->token('Hel');
        $server->token('lo');
        $session->announce(SessionEvent::new(SessionEvent::TURN_STEP, (new StepStarted(1, 8))->toArray(), $session->sessionId(), $session->turnId()));
        $server->emit(new ToolStarted('c1', 'Bash', ['command' => 'ls']));
        $server->emit(new ToolFinished('c1', 'Bash', new EngineToolResult('c1', 'a.txt')));
        self::assertTrue($this->until(static function () use (&$events, &$tokens, &$steps): bool {
            return \count($events) === 2 && \count($tokens) === 2 && $steps !== [];
        }), 'the live events never arrived');
        $server->settle(Message::assistant('Hello'));

        $message = $this->await($reply);

        self::assertInstanceOf(Message::class, $message, $message instanceof \Throwable ? $message->getMessage() : '');
        self::assertSame('Hello', $message->content);
        self::assertSame(['Hel', 'lo'], $tokens);
        self::assertInstanceOf(ToolStarted::class, $events[0]);
        self::assertSame(['command' => 'ls'], $events[0]->arguments);
        self::assertInstanceOf(ToolFinished::class, $events[1]);
        self::assertSame('a.txt', $events[1]->result->content());
        self::assertFalse($events[1]->result->isError());
        self::assertCount(1, $steps);
        self::assertInstanceOf(StepStarted::class, $steps[0]);
        self::assertSame(8, $steps[0]->maxSteps);
    }

    public function testAQuestionAnsweredHereReachesTheServersTurn(): void
    {
        $host = $this->attached();
        $asked = [];
        $resolved = [];
        $reply = RemoteBackend::new($host)->completeInteractive([Message::user('clean up')], null, null, static function (object $e) use (&$asked, &$resolved): void {
            if ($e instanceof PermissionAsked) {
                $asked[] = $e;
            } elseif ($e instanceof PermissionResolved) {
                $resolved[] = $e;
            }
        });
        self::assertTrue($this->until(fn (): bool => $this->fixture->backend->isRunning()));

        $serverAsk = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'rm build.log']);
        self::assertTrue($this->until(static function () use (&$asked): bool {
            return $asked !== [];
        }), 'the question never came up here');

        self::assertCount(1, $asked);
        self::assertSame($serverAsk->askId, $asked[0]->ask->askId);
        self::assertSame(['command' => 'rm build.log'], $asked[0]->ask->arguments);
        self::assertTrue($asked[0]->ask->offers(PermissionReply::Always));

        $asked[0]->ask->reply(PermissionReply::Once);
        self::assertTrue($this->until(fn (): bool => $this->fixture->backend->settled !== []), 'the answer never reached the server');
        $this->fixture->run(0.05);

        self::assertCount(1, $this->fixture->backend->settled, 'the server\'s turn heard exactly one answer');
        self::assertSame(PermissionReply::Once, $this->fixture->backend->settled[0]->reply);
        self::assertSame(PermissionReply::Once, $resolved[0]->reply ?? null, 'the modal is told its question is settled');

        $this->fixture->backend->settle(Message::assistant('done'));
        self::assertSame('done', $this->await($reply)->content ?? null);
    }

    public function testAQuestionAnsweredByAnotherClientFirstComesDownHere(): void
    {
        $host = $this->attached();
        $sessionId = (string) $host->sessionId();
        $asked = [];
        $resolved = [];
        RemoteBackend::new($host)->completeInteractive([Message::user('clean up')], null, null, static function (object $e) use (&$asked, &$resolved): void {
            if ($e instanceof PermissionAsked) {
                $asked[] = $e;
            } elseif ($e instanceof PermissionResolved) {
                $resolved[] = $e;
            }
        })->then(null, static function (): void {
            // Still running when the test ends; the teardown's close rejects it.
        });
        self::assertTrue($this->until(fn (): bool => $this->fixture->backend->isRunning()));
        $serverAsk = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'rm -r build']);
        self::assertTrue($this->until(static function () use (&$asked): bool {
            return $asked !== [];
        }), 'the question never came up here');
        self::assertCount(1, $asked);

        $browser = $this->fixture->client('browser');
        $browser->call('permission.respond', ['sessionId' => $sessionId, 'askId' => $serverAsk->askId, 'reply' => 'reject', 'note' => 'not now']);
        self::assertTrue($this->until(static function () use (&$resolved): bool {
            return $resolved !== [];
        }), 'the other client\'s answer never came down here');
        $this->fixture->run(0.05);

        self::assertTrue($asked[0]->ask->isSettled(), 'the local question is settled by the other client\'s answer');
        self::assertCount(1, $resolved);
        self::assertSame(PermissionReply::Reject, $resolved[0]->reply);
        self::assertSame('not now', $resolved[0]->note);
        self::assertCount(1, $this->fixture->backend->settled, 'nothing was answered twice');
    }

    public function testEscapeEscapeCancelsTheServersTurn(): void
    {
        $host = $this->attached();
        $token = new CancellationToken();
        $reply = RemoteBackend::new($host)->completeInteractive([Message::user('long job')], null, $token);
        self::assertTrue($this->until(fn (): bool => $this->fixture->backend->isRunning()));

        $token->cancel();
        $result = $this->await($reply);
        self::assertTrue($this->until(fn (): bool => $this->fixture->backend->cancellation()?->isCancelled() === true), 'the server\'s turn was never cancelled');

        self::assertInstanceOf(\RuntimeException::class, $result);
        self::assertSame('cancelled', $result->getMessage());
        self::assertTrue($this->fixture->backend->cancellation()?->isCancelled(), 'the server\'s turn was cancelled');
        self::assertFalse($this->fixture->hub->get((string) $host->sessionId())?->isBusy());
    }

    public function testOneShotCompletionsAreRefusedOnAnAttachment(): void
    {
        $backend = RemoteBackend::new($this->attached());

        $result = $this->await($backend->completeAsync([Message::user('title this')]));

        self::assertInstanceOf(\RuntimeException::class, $result);
        self::assertSame(RemoteBackend::ONE_SHOT_REFUSAL, $result->getMessage());
        $this->expectExceptionMessage(RemoteBackend::ONE_SHOT_REFUSAL);
        $backend->complete([Message::user('summarise')]);
    }

    public function testAnExistingSessionOpensFromItsSnapshotByUniquePrefix(): void
    {
        $first = $this->attached();
        $id = (string) $first->sessionId();
        $reply = RemoteBackend::new($first)->completeInteractive([Message::user('remember the plan')]);
        self::assertTrue($this->until(fn (): bool => $this->fixture->backend->isRunning()));
        $this->fixture->backend->settle(Message::assistant('noted'));
        self::assertSame('noted', $this->await($reply)->content ?? null);
        $first->close();
        $this->fixture->run(0.05);

        $second = $this->attached(\substr($id, 0, 6));

        self::assertSame($id, $second->sessionId());
        $contents = \array_map(static fn (Message $m): string => $m->content, $second->history());
        self::assertContains('remember the plan', $contents);
        self::assertContains('noted', $contents);
    }

    public function testATargetIsAnIdANameOrOneUniquePrefix(): void
    {
        $rows = [['id' => 'abc123', 'name' => 'alpha'], ['id' => 'abd456', 'name' => 'beta'], ['id' => 'ffff', 'name' => 'abc123x']];

        self::assertSame('abd456', RemoteSessionHost::resolveTarget($rows, 'abd456')['id']);
        self::assertSame('abc123', RemoteSessionHost::resolveTarget($rows, 'alpha')['id']);
        self::assertSame('abd456', RemoteSessionHost::resolveTarget($rows, 'abd')['id']);

        try {
            RemoteSessionHost::resolveTarget($rows, 'ab');
            self::fail('an ambiguous prefix resolved');
        } catch (RpcError $e) {
            self::assertSame('ambiguous', $e->kind);
            self::assertStringContainsString('abc123, abd456', $e->getMessage());
        }

        $this->expectException(RpcError::class);
        RemoteSessionHost::resolveTarget($rows, 'zz');
    }

    public function testBootstrapOpensTheAttachedSessionWithoutTouchingTheLocalStore(): void
    {
        $host = $this->attached();
        RemoteSessionHost::useForLaunch($host);
        $before = \count($this->fixture->store->listSessions(100));

        $opened = Bootstrap::openSession($this->fixture->store);

        self::assertSame($host->sessionId(), $opened['id']);
        self::assertSame([], $opened['history']);
        self::assertFalse($opened['picker']);
        self::assertCount($before, $this->fixture->store->listSessions(100), 'no local row was created');
        self::assertTrue(RemoteSessionHost::isAttachedTo($host->sessionId()));
        self::assertFalse(RemoteSessionHost::isAttachedTo('someone-else'));
    }

    public function testTheReadOnlyNoticeOffersAttachWhenTheServerHoldsTheSession(): void
    {
        $state = StateDir::open($this->fixture->dir . '/state');
        DiscoveryFile::forThisProcess('test', 'http://127.0.0.1:7420', '127.0.0.1', 7420, $this->fixture->root, false, null)->write($state);
        \putenv('SUGARCRUSH_SERVER_DIR=' . $state->path);

        $offer = Attach::lockHolderOffer('5e55', (int) \getmypid());

        self::assertSame('The sugarcrush server at http://127.0.0.1:7420 has it open: `sugarcrush attach 5e55` drives it from this terminal.', $offer);
        self::assertNull(Attach::lockHolderOffer('5e55', (int) \getmypid() + 1), 'another TUI holding the lock gets no offer');
        self::assertNull(Attach::lockHolderOffer('5e55', null));
    }

    public function testTheVerbRefusesUsageErrorsAndSaysWhenNoServerRuns(): void
    {
        self::assertSame(NonInteractive::EXIT_CONFIG, $this->runVerb(['attach', 'a', 'b']));
        self::assertSame(NonInteractive::EXIT_CONFIG, $this->runVerb(['attach', '--url', 'ftp://x']));
        self::assertSame(NonInteractive::EXIT_CONFIG, $this->runVerb(['--output-format', 'json', 'attach']));
        self::assertSame(['--url' => 'http://h:1'], ArgvParser::parse(['sugarcrush', 'attach', '--url', 'http://h:1'])->subcommandFlags);

        \putenv('SUGARCRUSH_SERVER_DIR=' . $this->fixture->dir . '/no-server');
        self::assertSame(NonInteractive::EXIT_FAILURE, $this->runVerb(['attach']));
    }

    public function testAddressesBecomeTheWebSocketUrl(): void
    {
        self::assertSame('ws://127.0.0.1:7420/ws', Attach::websocketUrl('http://127.0.0.1:7420'));
        self::assertSame('ws://127.0.0.1:7420/ws', Attach::websocketUrl('http://127.0.0.1:7420/#code=abc'));
        self::assertSame('wss://agent.example.com/ws', Attach::websocketUrl('https://agent.example.com/'));
        self::assertSame('ws://[::1]:9/ws', Attach::websocketUrl('ws://[::1]:9/ws'));
        self::assertNull(Attach::websocketUrl('ftp://x'));
        self::assertNull(Attach::websocketUrl('not a url'));
    }

    // ── helpers ──────────────────────────────────────────────────────────

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
        // Let the subscription go live before the test acts on it.
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

    /** @param list<string> $argv */
    private function runVerb(array $argv): int
    {
        $args = ArgvParser::parse(['sugarcrush', ...$argv]);
        \ob_start();
        try {
            return Attach::run($args);
        } finally {
            \ob_end_clean();
        }
    }
}
