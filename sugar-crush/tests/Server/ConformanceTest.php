<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Protocol\Dispatcher;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\EventType;
use SugarCraft\Crush\Server\Auth\AuthContext;
use SugarCraft\Crush\Server\Auth\TokenStore;
use SugarCraft\Crush\Server\Http\StaticFiles;
use SugarCraft\Crush\Server\Server;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tests\Server\Support\WireClient;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Roadmap O-3b: the `sugarcrush.v1` protocol end to end over a real
 * {@see \SugarCraft\Crush\Server\Ws\Connection} — the handshake and its
 * refusals, the JSON-RPC error surface, a turn from `session.send` through
 * its streamed and durable events, queueing, steering and cancelling,
 * idempotent retries, the permission-mode admission rule, the concurrent-turn
 * cap, and the settings and command surfaces' refusals.
 */
final class ConformanceTest extends TestCase
{
    private ProtocolFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testHelloAnswersTheProtocolItsFeaturesAndItsLimits(): void
    {
        $hello = WireClient::open($this->fixture->dispatcher)->hello();

        self::assertSame(Dispatcher::PROTOCOL, $hello['protocol']);
        self::assertSame('test', $hello['server']['version']);
        self::assertSame('c1', $hello['server']['connectionId']);
        self::assertContains('session.send', $hello['features']['methods']);
        self::assertContains('permission.respond', $hello['features']['methods']);
        self::assertSame(EventType::all(), $hello['features']['events']);
        self::assertSame(16_777_216, $hello['limits']['maxBufferedBytes']);
        self::assertSame(1_048_576, $hello['limits']['maxClientFrameBytes']);
        self::assertSame(['read', 'write', 'approve', 'admin'], $hello['principal']['scopes']);
        self::assertSame('default', $hello['defaults']['permissionMode'], 'server sessions ask by default');
    }

    public function testTheDispatcherAnswersOverARealSocket(): void
    {
        $token = \str_repeat('ab', 32);
        $server = Server::new(
            ServerConfig::new('/nonexistent')->withPort(0),
            AuthContext::new(TokenStore::new('/nonexistent')->withOverride($token)),
            StaticFiles::new(null),
            $this->fixture->dispatcher,
            Loop::get(),
        );
        $server->start();
        try {
            $socket = $this->fixture->await((new WsConnector(Loop::get()))(
                'ws://127.0.0.1:' . $server->port() . '/ws',
                ['sugarcrush.v1'],
                ['Authorization' => 'Bearer ' . $token, 'Origin' => 'http://127.0.0.1:' . $server->port()],
            ));
            self::assertInstanceOf(WebSocket::class, $socket, $socket instanceof \Throwable ? $socket->getMessage() : '');

            $reply = new Deferred();
            $socket->on('message', static fn (MessageInterface $message) => $reply->resolve((string) $message));
            $socket->send('{"jsonrpc":"2.0","id":1,"method":"server.hello","params":{"minProtocol":1,"maxProtocol":1}}');
            $hello = \json_decode((string) $this->fixture->await($reply->promise()), true);
            $socket->close();

            self::assertSame(1, $hello['id']);
            self::assertSame(1, $hello['result']['protocol']);
            self::assertSame('bearer', $hello['result']['principal']['via']);
        } finally {
            $server->stop();
        }
    }

    public function testARequestBeforeHelloIsRefusedAndTheSocketClosed(): void
    {
        $client = WireClient::open($this->fixture->dispatcher);

        $reply = $client->request('session.list');

        self::assertSame(ErrorCode::NotInitialized->value, $reply['error']['code']);
        self::assertSame('not_initialized', $reply['error']['data']['kind']);
        self::assertSame([Dispatcher::CLOSE_NOT_INITIALIZED], $client->closeCodes());
        self::assertFalse($client->connection->isOpen());
    }

    public function testAnOversizedFrameBeforeHelloClosesTheSocket(): void
    {
        $client = WireClient::open($this->fixture->dispatcher);

        $client->raw(\str_repeat(' ', Dispatcher::MAX_PRE_HELLO_BYTES + 1));

        self::assertSame([1009], $client->closeCodes());
    }

    public function testAProtocolRangeWithoutVersionOneIsRefused(): void
    {
        $client = WireClient::open($this->fixture->dispatcher);

        $reply = $client->request('server.hello', ['minProtocol' => 2, 'maxProtocol' => 3]);

        self::assertSame('unsupported_protocol', $reply['error']['data']['kind']);
        self::assertSame(1, $reply['error']['data']['protocol']);
        self::assertSame('already_initialized', $this->helloTwice()['error']['data']['kind']);
    }

    public function testTheJsonRpcErrorSurface(): void
    {
        $client = $this->fixture->client();

        $client->raw('not json');
        $client->raw('[1,2]');
        $client->raw('{"jsonrpc":"2.0","id":7,"method":"no.suchMethod"}');
        $client->raw('{"jsonrpc":"2.0","id":8,"method":"session.get","params":{}}');
        $client->raw('{"jsonrpc":"2.0","method":"server.health"}');

        $received = $client->received();
        $codes = \array_map(static fn (array $m): int => $m['error']['code'] ?? 0, \array_slice($received, -4));
        self::assertSame([-32700, -32600, -32601, -32602], $codes, 'a notification is never answered');
        self::assertSame(7, $client->response(7)['id'] ?? null, 'an integer id is echoed as an integer');
        self::assertStringNotContainsString('session.get', (string) \json_encode($client->response(8)), 'nothing the client sent is echoed');
    }

    public function testATurnRunsFromSendThroughItsStreamedAndDurableEvents(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $subscribed = $client->call('session.subscribe', ['sessionId' => $sessionId]);
        self::assertTrue($subscribed['reset']);
        $this->fixture->run();

        $sent = $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'list the files']);
        self::assertSame('started', $sent['admitted']);
        self::assertMatchesRegularExpression('/^t_[0-9a-f]{16}$/', $sent['turnId']);

        $backend = $this->fixture->backend;
        $backend->token('Hel');
        $backend->token('lo');
        $backend->emit(new ToolStarted('c1', 'Bash', ['command' => 'ls']));
        $backend->emit(new ToolFinished('c1', 'Bash', new EngineToolResult('c1', 'a.txt')));
        $this->fixture->run(0.08);
        $backend->settle(Message::assistant('Hello'));
        $this->fixture->run();

        $deltas = $client->events('assistant.delta');
        self::assertSame(['Hel', 'lo'], \array_column(\array_column($deltas, 'data'), 'text'));
        self::assertSame([0, 3], \array_column(\array_column($deltas, 'data'), 'offset'), 'offsets let a client spot a dropped delta');
        self::assertArrayNotHasKey('seq', $deltas[0], 'an ephemeral event carries no seq');

        $durable = \array_values(\array_filter($client->events(), static fn (array $e): bool => $e['durable']));
        $types = \array_column($durable, 'type');
        foreach (['message.created', 'turn.started', 'tool.started', 'tool.finished', 'assistant.completed', 'turn.completed'] as $type) {
            self::assertContains($type, $types);
        }
        $seqs = \array_column($durable, 'seq');
        self::assertSame($seqs, \range($seqs[0], $seqs[0] + \count($seqs) - 1), 'durable events arrive gap-free and in order');
        $statuses = \array_column(\array_column($client->events('session.status'), 'data'), 'status');
        self::assertSame(['busy', 'idle'], $statuses);
        self::assertSame('end_turn', $client->events('turn.completed')[0]['data']['stopReason']);
    }

    public function testQueueSteerAndDequeueWhileATurnRuns(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();
        $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'first']);

        $queued = $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'second']);
        self::assertSame('queued', $queued['admitted']);
        self::assertSame(1, $queued['queuePosition']);
        $third = $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'third', 'delivery' => 'queue']);

        $steered = $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'look at tests too', 'delivery' => 'steer']);
        self::assertSame('steered', $steered['admitted']);
        self::assertNotEmpty($steered['steerId']);

        $queue = $client->call('session.queue', ['sessionId' => $sessionId])['items'];
        self::assertSame(['second', 'third', 'look at tests too'], \array_column($queue, 'text'));

        self::assertTrue($client->call('session.dequeue', ['sessionId' => $sessionId, 'queueId' => $third['queueId']])['dequeued']);
        self::assertSame('queue_entry_not_found', $client->request('session.dequeue', ['sessionId' => $sessionId, 'queueId' => 'q99'])['error']['data']['kind']);

        self::assertCount(3, $client->events('turn.queued'));
        self::assertSame($steered['steerId'], $client->events('turn.steered')[0]['data']['steerId']);
        $dequeued = $client->events('turn.dequeued');
        self::assertSame([$third['queueId']], \array_column(\array_column($dequeued, 'data'), 'queueId'));
        self::assertSame('removed', $dequeued[0]['data']['reason']);

        $this->fixture->backend->settle(Message::assistant('done'));
        $this->fixture->run();
        self::assertSame(['first', 'second'], $this->fixture->backend->sent, 'the queue goes out when the turn settles');
        self::assertContains($queued['queueId'], \array_column(\array_column($client->events('turn.dequeued'), 'data'), 'queueId'));
    }

    public function testAnIdempotencyKeyReturnsTheOriginalAnswer(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);

        $first = $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'once only', 'idempotencyKey' => 'k-1']);
        $retry = $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'once only', 'idempotencyKey' => 'k-1']);

        self::assertSame($first, $retry);
        self::assertSame(['once only'], $this->fixture->backend->sent);
        self::assertSame([], $this->fixture->hub->get($sessionId)?->queued(), 'the retry was not queued either');
    }

    public function testBypassModesAreRefusedOverTheWireUnlessTheServerAllowsThem(): void
    {
        $client = $this->fixture->client();

        $refused = $client->request('session.create', ['permissionMode' => 'bypass-permissions']);
        self::assertSame(ErrorCode::PermissionModeRefused->value, $refused['error']['code']);
        self::assertSame('permission_mode_refused', $refused['error']['data']['kind']);

        $sessionId = $this->fixture->session($client, ['permissionMode' => 'accept-edits']);
        self::assertSame(PermissionMode::AcceptEdits, $this->fixture->hub->get($sessionId)?->permissionMode());
        self::assertSame('dont-ask', $client->request('session.setMode', ['sessionId' => $sessionId, 'permissionMode' => 'dont-ask'])['error']['data']['permissionMode']);
        self::assertSame('plan', $client->call('session.setMode', ['sessionId' => $sessionId, 'permissionMode' => 'plan'])['permissionMode']);

        $open = ProtocolFixture::new(ServerConfig::new('/nonexistent')->withAllowBypass(true));
        try {
            $other = $open->client();
            $bypass = $open->session($other, ['permissionMode' => 'bypass-permissions']);
            self::assertSame(PermissionMode::BypassPermissions, $open->hub->get($bypass)?->permissionMode());
        } finally {
            $open->tearDown();
        }
    }

    public function testANewSessionRunsInTheServersModeNotTheWorkspaces(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);

        self::assertSame(PermissionMode::Default, $this->fixture->hub->get($sessionId)?->permissionMode());
        self::assertSame('default', $client->call('session.get', ['sessionId' => $sessionId])['permissionMode']);
    }

    public function testCancelEndsTheTurnAsCancelled(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();
        $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'long job']);

        self::assertFalse($client->call('session.cancel', ['sessionId' => $sessionId, 'turnId' => 't_0000000000000000'])['cancelled'], 'another turn id cancels nothing');
        self::assertTrue($client->call('session.cancel', ['sessionId' => $sessionId])['cancelled']);
        $this->fixture->run();

        self::assertSame('cancelled', $client->events('turn.completed')[0]['data']['stopReason']);
        self::assertFalse($this->fixture->hub->get($sessionId)?->isBusy());
    }

    public function testSoftCancelAsksTheTurnToStopAtItsNextStep(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'long job']);

        self::assertTrue($client->call('session.cancel', ['sessionId' => $sessionId, 'mode' => 'soft'])['cancelled']);
        self::assertTrue($this->fixture->backend->cancellation()?->isSoftCancelled());
        self::assertTrue($this->fixture->hub->get($sessionId)?->isBusy(), 'a soft cancel lets the turn settle on its own');
    }

    public function testTheConcurrentTurnCapRefusesOneMoreTurnRetryably(): void
    {
        $capped = ProtocolFixture::new(ServerConfig::new('/nonexistent')->withMaxConcurrentTurns(1));
        try {
            $client = $capped->client();
            $one = $capped->session($client);
            $two = $capped->session($client);
            $client->call('session.send', ['sessionId' => $one, 'text' => 'busy']);

            $refused = $client->request('session.send', ['sessionId' => $two, 'text' => 'me too']);
            self::assertSame(ErrorCode::Busy->value, $refused['error']['code']);
            self::assertSame('too_many_turns', $refused['error']['data']['kind']);
            self::assertTrue($refused['error']['data']['retryable']);
            self::assertSame('queued', $client->call('session.send', ['sessionId' => $one, 'text' => 'after'])['admitted'], 'the running session still queues');
        } finally {
            $capped->tearDown();
        }
    }

    public function testADrainingServerAdmitsNoNewTurnOrSession(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->fixture->context->beginDraining();

        self::assertSame('draining', $client->request('session.send', ['sessionId' => $sessionId, 'text' => 'x'])['error']['data']['kind']);
        self::assertSame('draining', $client->request('session.create')['error']['data']['kind']);
        self::assertTrue($client->call('server.health')['draining']);
    }

    public function testSessionLifecycleEventsReachEveryClient(): void
    {
        $a = $this->fixture->client('a');
        $b = $this->fixture->client('b');

        $sessionId = $this->fixture->session($a, ['name' => 'first']);
        $a->call('session.rename', ['sessionId' => $sessionId, 'name' => 'renamed']);
        $listed = $b->call('session.list')['items'];
        $a->call('session.delete', ['sessionId' => $sessionId]);

        self::assertSame(['session.created', 'session.updated', 'session.deleted'], \array_values(\array_filter(
            $b->eventTypes(),
            static fn (string $type): bool => \str_starts_with($type, 'session.'),
        )));
        self::assertSame('renamed', $listed[0]['name']);
        self::assertNull($b->events('session.created')[0]['sessionId'], 'server-scope events name no session');
        self::assertSame('session_not_found', $b->request('session.get', ['sessionId' => $sessionId])['error']['data']['kind']);
    }

    public function testBuiltInsWithAHostBodyRunOnTheServerAndTheRestAreClientSide(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);

        $commands = \array_column($client->call('command.list')['items'], null, 'name');
        self::assertSame('server', $commands['clear']['runsIn'] ?? null, 'Host\\Commands\\ClearCommand runs headless');
        self::assertSame('client', $commands['theme']['runsIn'] ?? null, 'a screen-only command');

        $cleared = $client->call('command.exec', ['sessionId' => $sessionId, 'name' => '/clear']);
        self::assertSame(['clear-transcript'], $cleared['effects']);
        self::assertIsArray($cleared['rows']);

        $reply = $client->request('command.exec', ['sessionId' => $sessionId, 'name' => '/theme']);
        self::assertSame(ErrorCode::UnsupportedInServer->value, $reply['error']['code']);
        self::assertSame('ui_only', $reply['error']['data']['kind']);
        self::assertSame('command_not_found', $client->request('command.exec', ['sessionId' => $sessionId, 'name' => 'nope'])['error']['data']['kind']);
    }

    public function testSettingsAreMaskedAndOnlyAllowlistedKeysAreWritable(): void
    {
        $client = $this->fixture->client();

        $schema = \array_column($client->call('settings.schema')['items'], null, 'key');
        self::assertFalse($schema['statusLine']['writableRemotely'] ?? true, 'a key that runs a command is never written remotely');
        self::assertFalse($schema['permissionMode']['writableRemotely'] ?? true);
        self::assertFalse($schema['server.allowBypass']['writableRemotely'] ?? true);
        self::assertFalse($schema['theme']['writableRemotely'] ?? true, '/theme owns the key; the writer refuses it too');

        $refused = $client->request('settings.set', ['key' => 'statusLine', 'value' => 'rm -rf ~']);
        self::assertSame('not_writable_remotely', $refused['error']['data']['kind']);

        $set = $client->call('settings.set', ['key' => 'parallelToolDeadlineSeconds', 'value' => 40]);
        self::assertSame('parallelToolDeadlineSeconds', $set['key']);
        self::assertSame(40, $client->call('settings.get', ['scope' => 'user'])['values']['parallelToolDeadlineSeconds']);
        self::assertSame('setting_refused', $client->request('settings.set', ['key' => 'parallelToolDeadlineSeconds', 'value' => 'many'])['error']['data']['kind']);

        foreach ($schema as $key => $row) {
            if ($row['sensitive']) {
                self::assertFalse($row['writableRemotely'], $key . ' holds a secret');
                self::assertArrayNotHasKey('default', $row, $key . ' leaks no default');
            }
        }
    }

    public function testHealthAndInfo(): void
    {
        $client = $this->fixture->client();
        $this->fixture->session($client);

        $health = $client->call('server.health');
        self::assertTrue($health['ok']);
        self::assertSame(1, $health['sessionsOpen']);
        self::assertSame(0, $health['turnsRunning']);

        $info = $client->call('server.info');
        self::assertSame(['default', 'accept-edits', 'plan', 'auto'], $info['permissionModes'], 'only the modes this server admits');
        self::assertContains('clear', $info['commands']);
    }

    public function testTodoGetAnswersTheSessionsSavedList(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        self::assertSame(['items' => []], $client->call('todo.get', ['sessionId' => $sessionId]), 'no list kept yet');

        $other = $this->fixture->session($client);
        $transcripts = $this->fixture->hub->workspace()->service(\SugarCraft\Crush\Host\TranscriptStore::class);
        self::assertInstanceOf(\SugarCraft\Crush\Host\TranscriptStore::class, $transcripts);
        $transcripts->saveTodos($other, \SugarCraft\Crush\Todo\TodoList::fromToolArguments(['todos' => [
            ['content' => 'write the parser', 'status' => 'in_progress'],
            ['content' => 'test it', 'status' => 'pending'],
        ]]));

        self::assertSame(
            ['items' => [['content' => 'write the parser', 'status' => 'in_progress'], ['content' => 'test it', 'status' => 'pending']]],
            $client->call('todo.get', ['sessionId' => $other]),
        );
    }

    public function testATodoCallIsBroadcastAsTodoUpdatedAndTodoGetFollowsIt(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();
        $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'plan it']);

        $list = \SugarCraft\Crush\Todo\TodoList::fromToolArguments(['todos' => [
            ['content' => 'write the parser', 'status' => 'in_progress'],
            ['content' => 'test it', 'status' => 'pending'],
        ]]);
        $this->fixture->backend->emit(new ToolStarted('td1', 'Todo', ['todos' => $list->toArray()]));
        $this->fixture->backend->emit(new ToolFinished('td1', 'Todo', new EngineToolResult('td1', \SugarCraft\Crush\Tools\BuiltIn\Todo::UPDATED . "\n\n" . $list->render())));
        $this->fixture->run(0.08);
        $this->fixture->backend->settle(Message::assistant('planned'));
        $this->fixture->run();

        $events = $client->events('todo.updated');
        self::assertCount(1, $events);
        self::assertTrue($events[0]['durable']);
        self::assertIsInt($events[0]['seq']);
        self::assertSame(['toolCallId' => 'td1', 'items' => $list->toArray()], $events[0]['data']);
        self::assertSame(['items' => $list->toArray()], $client->call('todo.get', ['sessionId' => $sessionId]));
    }

    public function testRequestsPastTheBurstAreRateLimited(): void
    {
        $client = $this->fixture->client();
        $client->clear();

        for ($i = 0; $i < 220; $i++) {
            $client->raw('{"jsonrpc":"2.0","id":' . $i . ',"method":"server.health"}');
        }

        $limited = \array_filter($client->received(), static fn (array $m): bool => ($m['error']['data']['kind'] ?? null) === 'rate_limited');
        self::assertNotEmpty($limited);
        self::assertLessThan(220, \count($client->received()) - \count($limited) + 1);
    }

    /** @return array<string, mixed> */
    private function helloTwice(): array
    {
        $client = WireClient::open($this->fixture->dispatcher, 'twice');
        $client->hello();

        return $client->request('server.hello', ['minProtocol' => 1, 'maxProtocol' => 1]);
    }
}
