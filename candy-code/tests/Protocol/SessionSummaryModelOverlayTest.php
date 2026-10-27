<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Host\EventLog;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\TranscriptStore;
use SugarCraft\Code\Host\TurnController;
use SugarCraft\Code\Host\TurnRunner;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\Schema\EventSchemas;
use SugarCraft\Code\Protocol\Schema\ProtocolSchema;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\ScriptedTurnBackend;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * C-WEB-1.a: the session summary the server Serves must carry the model a
 * served `/model` actually switched to — the row it reads was never
 * rewritten (W7a-2 keeps the provider axis unpersisted on both surfaces),
 * so before this the web's sidebar and picker kept naming the launch's pair
 * while a different model answered. And the change must be ANNOUNCED: a
 * switch carries no status transition, so the feed's status lane never
 * broadcast it, and only a later reload or the 15 s `server.tick` corrected
 * the picture. A served `/rename` had the same silence through the command
 * lane (the `session.rename` RPC always announced; the `/rename` command
 * never did).
 *
 * The pin pair: `summaryOf` names the newest switch, and the open host's
 * hook fires EXACTLY ONE server-scope `session.updated` per changing
 * command; a closed host reverts to the row (the override is session-lifetime
 * state, dead with its host), and the re-opened session binds the hook again
 * — a recycled host never goes silent.
 *
 * Built the way {@see ProtocolFixture} builds its server, with one addition:
 * a probe `backendFactory`, so `/model` builds a real engine backend without
 * the launch factory's key and network doors. The scripted turn backend lets
 * the typed path prove it never dispatched a turn.
 */
final class SessionSummaryModelOverlayTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;

    private string $dir = '';

    private string $root = '';

    private EnhancedSessionStore $store;

    private ScriptedTurnBackend $backend;

    private SessionHub $hub;

    private ServerContext $context;

    private Dispatcher $dispatcher;

    /** @var string|false */
    private string|false $originalCustomKey = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = \sys_get_temp_dir() . '/crush-summary-overlay-' . \bin2hex(\random_bytes(6));
        $this->root = $this->dir . '/project';
        \mkdir($this->root, 0o700, true);
        $this->useHomeSandbox($this->dir . '/home');
        $this->clearBackendSelectionEnv();
        Bootstrap::useConfigPath(null);
        // `openai` rides the roster the persist door consults; the probe
        // factory answers the build, so no HTTP client is ever constructed.
        $this->originalCustomKey = \getenv('CUSTOM_API_KEY');
        putenv('CUSTOM_API_KEY=test-key');

        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
        $this->backend = new ScriptedTurnBackend();

        $transcripts = TranscriptStore::new($this->store, null, EventLog::new($this->store, EventLog::DEFAULT_RETAIN));
        $workspace = WorkspaceContext::new(
            root: $this->root,
            sessionStore: $this->store,
            backend: $this->backend,
            backendFactory: static fn (string $provider): Backend => EngineBackend::new(new ScriptedProvider([]), 'stale-' . $provider),
        )
            ->withService(TurnRunner::class, TurnRunner::new())
            ->withService(TurnController::class, TurnController::new())
            ->withService(TranscriptStore::class, $transcripts)
            ->withService(EventLog::class, $transcripts->events() ?? EventLog::new($this->store))
            ->withService(SettingsWriter::class, SettingsWriter::new(
                $this->dir . '/config.json',
                function (array $set, array $unset): void {
                    $path = $this->dir . '/config.json';
                    $current = \is_file($path) ? (array) \json_decode((string) \file_get_contents($path), true) : [];
                    $next = SettingsWriter::patched($current, $set, $unset);
                    \file_put_contents($path, (string) \json_encode($next === [] ? new \stdClass() : $next));
                },
            ));

        $config = ServerConfig::new($this->dir);
        $this->hub = SessionHub::new($workspace, $config->maxOpenSessions);
        $this->context = ServerContext::new($this->hub, $config->withRoot($this->root), 'test', Loop::get());
        $this->dispatcher = Dispatcher::new($this->context);
    }

    protected function tearDown(): void
    {
        $this->dispatcher->stop('test over');
        $this->hub->closeAll();
        $this->tickLoop(0.001);

        $this->originalCustomKey === false
            ? putenv('CUSTOM_API_KEY')
            : putenv('CUSTOM_API_KEY=' . $this->originalCustomKey);
        Bootstrap::useConfigPath(null);
        $this->restoreBackendSelectionEnv();
        $this->restoreHomeSandbox();
        ProtocolFixture::removeTree($this->dir);

        parent::tearDown();
    }

    public function testACommandExecModelSwitchIsServedInTheSummaryAndAnnouncedOnce(): void
    {
        $client = $this->client();
        $this->openHost($client);
        $client->clear();

        $client->call('command.exec', ['sessionId' => 's', 'name' => 'model', 'args' => 'openai gpt-x']);

        $summary = $this->context->summaryOf('s');
        self::assertSame('openai', $summary['provider'], 'the served summary names the switched-to provider, not the launch row');
        self::assertSame('gpt-x', $summary['model'], 'and the switched-to model');
        self::assertTrue($summary['open']);

        $updates = $this->updatesFor($client);
        self::assertCount(1, $updates, 'the switch changes no status, so the only announcement is the summary hook');
        self::assertSame('openai', $updates[0]['data']['provider']);
        self::assertSame('gpt-x', $updates[0]['data']['model']);
        self::assertSame([], self::schemaErrors('session.updated', $updates[0]['data']));

        // The store row was never rewritten — the override is host-lifetime
        // state read at serve time, and the durable ledger keeps its pair.
        self::assertSame('p', $this->store->session('s')['provider']);
        self::assertSame('m', $this->store->session('s')['model']);
    }

    public function testATypedModelSwitchAnnouncesTheSameWithoutATurn(): void
    {
        $client = $this->client();
        $this->openHost($client);
        $client->clear();

        $client->call('session.send', ['sessionId' => 's', 'text' => '/model openai typed-model']);

        $summary = $this->context->summaryOf('s');
        self::assertSame('openai', $summary['provider']);
        self::assertSame('typed-model', $summary['model']);

        $updates = $this->updatesFor($client);
        self::assertCount(1, $updates, 'the command lane behind session.send rides the same one announcement');
        self::assertSame('typed-model', $updates[0]['data']['model']);

        self::assertSame([], $this->backend->sent, 'the leading `/word` was routed as a command; no prompt reached a backend');
    }

    public function testAServedRenameAnnouncesTheNewName(): void
    {
        $client = $this->client();
        $this->openHost($client);
        $client->clear();

        $client->call('command.exec', ['sessionId' => 's', 'name' => 'rename', 'args' => 'By Hand']);

        $summary = $this->context->summaryOf('s');
        self::assertSame('By Hand', $summary['name'], 'the store row carries the new title the command wrote');

        $updates = $this->updatesFor($client);
        self::assertCount(1, $updates, 'before C-WEB-1.a the RenameSession effect was a bare break — the rename changed nothing the web heard');
        self::assertSame('By Hand', $updates[0]['data']['name']);
        self::assertSame([], self::schemaErrors('session.updated', $updates[0]['data']));
    }

    public function testTheOverrideDiesWithTheHostAndAReopenedSessionAnnouncesAgain(): void
    {
        $client = $this->client();
        $this->openHost($client);
        $client->clear();

        $client->call('command.exec', ['sessionId' => 's', 'name' => 'model', 'args' => 'openai gpt-x']);
        self::assertCount(1, $this->updatesFor($client));

        $client->call('session.close', ['sessionId' => 's']);
        $summary = $this->context->summaryOf('s');
        self::assertFalse($summary['open']);
        self::assertSame('closed', $summary['status']);
        self::assertSame('p', $summary['provider'], 'the row is the whole truth again: the override died with its host');
        self::assertSame('m', $summary['model']);

        // Re-open and switch: the feed re-attaches over a fresh host object,
        // and the hook must be bound on it — a recycled host never goes silent.
        $client->call('command.exec', ['sessionId' => 's', 'name' => 'model', 'args' => 'openai gpt-next']);
        $summary = $this->context->summaryOf('s');
        self::assertSame('gpt-next', $summary['model']);

        $updates = $this->updatesFor($client);
        self::assertCount(2, $updates, 'one per switch, and the reopened host still speaks');
        self::assertSame('gpt-next', $updates[1]['data']['model']);
    }

    // ── harness ────────────────────────────────────────────────────────

    /** @return WireClient a client that has said hello */
    private function client(): WireClient
    {
        $client = WireClient::open($this->dispatcher, 'c1');
        $client->hello();

        return $client;
    }

    /**
     * Open the pre-created session's host through the real RPC door. `session.get`
     * is the read that opens without changing anything the pins measure.
     */
    private function openHost(WireClient $client): void
    {
        $client->call('session.get', ['sessionId' => 's']);
    }

    /** @return list<array<string, mixed>> this session's `session.updated` events, in order */
    private function updatesFor(WireClient $client): array
    {
        return \array_values(\array_filter(
            $client->events('session.updated'),
            static fn (array $event): bool => ($event['data']['id'] ?? null) === 's',
        ));
    }

    /** Run the loop for $seconds, the fixture's shape exactly. */
    private function tickLoop(float $seconds = 0.02): void
    {
        $timer = Loop::addTimer($seconds, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function schemaErrors(string $type, array $data): array
    {
        $schema = EventSchemas::data($type);
        self::assertNotNull($schema, $type . ' has a schema');

        return ProtocolSchema::errors($data, $schema);
    }
}
