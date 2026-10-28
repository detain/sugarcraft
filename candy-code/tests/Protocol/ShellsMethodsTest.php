<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\ScriptedTurnBackend;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * C-TOOL-3.e: the `shells.*` method family over the wire.
 *
 * `shells.list`, `shells.output` and `shells.stop` expose the launch's
 * background shell table to a protocol client with the SAME ownership law
 * C-TOOL-3.c gave `/shells` (C-TOOL-3.d): every verb filters by the named
 * session, a foreign id answers exactly like an unknown one, and stop is the
 * registry's own idempotent kill ladder. The rows carry no pid and no
 * private paths — what the web's Processes section needs, nothing more.
 *
 * Built the way {@see ProtocolFixture} builds its server, one service added:
 * the workspace carries a {@see BackgroundShellRegistry} pointed at a temp
 * tree (the {@see SessionSummaryModelOverlayTest} inline-construction
 * precedent), so the methods resolve the table through the same service
 * chain the Shell tool uses. The stop ladder runs against a fast sleeper
 * seam, never the wall-clock grace.
 */
final class ShellsMethodsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir = '';

    private BackgroundShellRegistry $registry;

    private SessionHub $hub;

    private ServerContext $context;

    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = \sys_get_temp_dir() . '/crush-shells-methods-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir . '/project', 0o700, true);
        $this->useHomeSandbox($this->dir . '/home');

        $this->registry = BackgroundShellRegistry::forSession(
            'shells-methods-test',
            $this->dir . '/shells',
            static function (float $seconds): void {
                \usleep(2000);
            },
        );
        $this->serve(withRegistry: true);
    }

    protected function tearDown(): void
    {
        $this->registry->teardown();
        $this->dispatcher->stop('test over');
        $this->hub->closeAll();
        $this->restoreHomeSandbox();
        ProtocolFixture::removeTree($this->dir);

        parent::tearDown();
    }

    public function testTheThreeMethodsAreAdvertisedWithTheScopesTheDesignNames(): void
    {
        $registry = Dispatcher::methods();
        foreach (['shells.list', 'shells.output'] as $name) {
            $spec = $registry->get($name);
            self::assertNotNull($spec, $name . ' is on the roster');
            self::assertSame(Scope::Read, $spec->scope, $name . ' is a read-scope method');
            self::assertFalse($spec->sideEffects, $name . ' claims no side effect');
        }
        $stop = $registry->get('shells.stop');
        self::assertNotNull($stop);
        self::assertSame(Scope::Write, $stop->scope, 'stop runs a kill ladder, so it needs write like bg.spawn');
        self::assertTrue($stop->sideEffects);
    }

    public function testListCarriesThisSessionsShellsPlusTheUntaggedOnesAndNoPrivatePaths(): void
    {
        $mine = $this->spawn('printf mine', 'mine', 's1');
        $theirs = $this->spawn('printf theirs', 'theirs', 's2');
        $untagged = $this->spawn('printf bare', 'untagged', null);

        $items = $this->client()->call('shells.list', ['sessionId' => 's1'])['items'];
        $labels = \array_map(static fn (array $row): string => (string) $row['label'], $items);
        self::assertSame(['mine', 'untagged'], $labels, 'own rows plus the untagged ones, never another session\'s');
        self::assertContains($mine, \array_column($items, 'shellId'));
        self::assertContains($untagged, \array_column($items, 'shellId'));
        self::assertNotContains($theirs, \array_column($items, 'shellId'));

        foreach ($items as $row) {
            self::assertSame(
                ['shellId', 'label', 'state', 'exitCode', 'exitNote', 'expired', 'ageSeconds'],
                \array_keys($row),
                'the row is exactly what the web table needs — no pid, no stdout/stderr/exit paths',
            );
        }

        $asTheirs = $this->client()->call('shells.list', ['sessionId' => 's2'])['items'];
        self::assertSame(['theirs', 'untagged'], \array_map(static fn (array $row): string => (string) $row['label'], $asTheirs));
    }

    public function testAForeignIdAnswersExactlyLikeAnUnknownOneAndLeavesTheShellAlone(): void
    {
        $theirs = $this->spawn('sleep 30', 'theirs', 's2');

        $foreign = $this->client()->request('shells.output', ['sessionId' => 's1', 'shellId' => $theirs]);
        $unknown = $this->client()->request('shells.output', ['sessionId' => 's1', 'shellId' => 'sh-00000000']);
        self::assertSame('shell_not_found', $foreign['error']['data']['kind']);
        self::assertSame($unknown['error']['data']['kind'], $foreign['error']['data']['kind']);
        self::assertSame(
            'no background shell ' . $theirs . ' is registered by this launch',
            $foreign['error']['message'],
            'the registry\'s own missing-id sentence, undifferentiated',
        );

        $stopped = $this->client()->call('shells.stop', ['sessionId' => 's1', 'shellId' => $theirs]);
        self::assertSame(
            ['shellId' => $theirs, 'found' => false, 'wasRunning' => false, 'confirmed' => false, 'grouped' => false, 'exitCode' => null, 'expired' => false],
            $stopped,
            'a foreign stop answers like a missing one, all-false',
        );

        $row = $this->registry->status($theirs, 's2');
        self::assertNotNull($row);
        self::assertSame('running', $row['state'], 'the stranger\'s call killed nothing');
    }

    public function testOutputReadsBothStreamsAndThreadsTheTwoPartCursor(): void
    {
        $id = $this->spawn('printf "a1\na2\n"; printf "e1\n" >&2', 'pair', 's1');
        self::settle($id);

        $first = $this->client()->call('shells.output', ['sessionId' => 's1', 'shellId' => $id]);
        self::assertSame("a1\na2\n", $first['stdout']);
        self::assertSame("e1\n", $first['stderr']);
        self::assertSame(0, $first['exitCode']);
        self::assertSame('finished', $first['state']);
        self::assertSame(0, $first['skippedBytes']);
        self::assertSame(9, $first['bytesAvailable']);
        self::assertSame('6.3', $first['cursor']);

        $drained = $this->client()->call('shells.output', ['sessionId' => 's1', 'shellId' => $id, 'cursor' => $first['cursor']]);
        self::assertSame('', $drained['stdout'], 'the answered cursor is the position just past the last byte of EACH stream');
        self::assertSame('', $drained['stderr']);
        self::assertSame('6.3', $drained['cursor']);

        $rewound = $this->client()->call('shells.output', ['sessionId' => 's1', 'shellId' => $id, 'cursor' => '0.0']);
        self::assertSame("a1\na2\n", $rewound['stdout'], 'the token form is accepted and re-reads from the pair it names');
    }

    public function testStopIsTheRegistrysIdempotentLadder(): void
    {
        $nap = $this->spawn('sleep 30', 'nap', 's1');

        $first = $this->client()->call('shells.stop', ['sessionId' => 's1', 'shellId' => $nap]);
        self::assertTrue($first['found']);
        self::assertTrue($first['wasRunning']);
        self::assertTrue($first['confirmed'], 'the ladder confirmed the group death');
        self::assertFalse($first['expired'], 'a stop is not the lifetime wall');

        $again = $this->client()->call('shells.stop', ['sessionId' => 's1', 'shellId' => $nap]);
        self::assertTrue($again['found']);
        self::assertFalse($again['wasRunning'], 'the second stop of a settled shell is a no-op success, not an error');

        $done = $this->spawn('printf out', 'settled', 's1');
        self::settle($done);
        $settled = $this->client()->call('shells.stop', ['sessionId' => 's1', 'shellId' => $done]);
        self::assertTrue($settled['found']);
        self::assertFalse($settled['wasRunning']);
        self::assertSame(0, $settled['exitCode']);
    }

    public function testOutputAndStopRefuseALaunchWithoutTheTableAndListAnswersEmpty(): void
    {
        $this->dispatcher->stop('replaced');
        $this->hub->closeAll();
        $this->serve(withRegistry: false);
        $client = $this->client();

        self::assertSame(['items' => []], $client->call('shells.list', ['sessionId' => 's1']), 'no table is an empty page, like bg.list');

        foreach (['shells.output' => ['sessionId' => 's1', 'shellId' => 'sh-00000000'], 'shells.stop' => ['sessionId' => 's1', 'shellId' => 'sh-00000000']] as $method => $params) {
            $refused = $client->request($method, $params);
            self::assertSame('shells_unavailable', $refused['error']['data']['kind'], $method . ' says so loudly');
        }
    }

    public function testBadCursorsAndBadSessionIdsAreRefusedNamingTheField(): void
    {
        $id = $this->spawn('printf out', 'fine', 's1');
        $client = $this->client();

        $letters = $client->request('shells.output', ['sessionId' => 's1', 'shellId' => $id, 'cursor' => 'abc']);
        self::assertSame('invalid_params', $letters['error']['data']['kind']);
        self::assertStringContainsString('cursor', $letters['error']['message']);

        $negative = $client->request('shells.output', ['sessionId' => 's1', 'shellId' => $id, 'cursor' => -5]);
        self::assertSame('invalid_params', $negative['error']['data']['kind']);
        self::assertStringContainsString('cursor', $negative['error']['message']);

        $noSession = $client->request('shells.list', []);
        self::assertSame('invalid_params', $noSession['error']['data']['kind'], 'the owner key is a required, validated session id');
    }

    // --- harness -----------------------------------------------------------

    private function serve(bool $withRegistry): void
    {
        $store = new EnhancedSessionStore($this->dir . '/session.db');
        $workspace = WorkspaceContext::new(
            root: $this->dir . '/project',
            sessionStore: $store,
            backend: new ScriptedTurnBackend(),
            backendFactory: static fn (string $provider): Backend => EngineBackend::new(new ScriptedProvider([]), 'stale-' . $provider),
        );
        if ($withRegistry) {
            $workspace = $workspace->withService(BackgroundShellRegistry::class, $this->registry);
        }

        $config = ServerConfig::new($this->dir);
        $this->hub = SessionHub::new($workspace, $config->maxOpenSessions);
        $this->context = ServerContext::new($this->hub, $config->withRoot($this->dir . '/project'), 'test', Loop::get());
        $this->dispatcher = Dispatcher::new($this->context);
    }

    private function client(): WireClient
    {
        $client = WireClient::open($this->dispatcher, 'c1');
        $client->hello();

        return $client;
    }

    /** @param string|null $owner the session tag C-TOOL-3.c puts on the row */
    private function spawn(string $command, string $label, ?string $owner): string
    {
        $started = $this->registry->start($command, $label, [], $owner);
        self::assertTrue($started['ok'], (string) ($started['error'] ?? 'spawn failed'));

        return (string) $started['shell']['id'];
    }

    /** Pump until the shell settles (or the bound fires — then the test says so). */
    private function settle(string $id): void
    {
        $deadline = \microtime(true) + 5.0;
        while (\microtime(true) < $deadline) {
            $row = $this->registry->status($id, 's1');
            if ($row !== null && (string) $row['state'] !== 'running') {
                return;
            }
            \usleep(20000);
        }

        self::fail('shell ' . $id . ' never settled');
    }
}
