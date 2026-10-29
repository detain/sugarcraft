<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use ReflectionMethod;
use RuntimeException;
use SugarCraft\Code\Cli\Attach;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Host\Commands\HandoffHostCommand;
use SugarCraft\Code\Host\Commands\HandoffSeededMsg;
use SugarCraft\Code\Host\RemoteSessionHost;
use SugarCraft\Code\Message;
use SugarCraft\Code\Protocol\Methods\SessionMethods;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\Import\RemoteSessionPush;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-SES-7.g: `/handoff --remote <url>` teleports the transcript.
 *
 * The arm validates URL, scheme, import bound and token BEFORE any socket
 * opens (plain `ws://` off-loopback is refused until serve speaks TLS), then
 * dials through the same {@see Attach} discipline `attach` uses and lands
 * the session on the target via `session.import` — the C-SES-7.e server
 * machinery, unmodified. Against a live loopback serve the teleport must
 * produce a real remote session and the `candy-code attach` line naming it;
 * a refused token must come back as a clear failure row and nothing local
 * may switch. The whole band runs over the ProtocolFixture's loopback
 * server, so the dial, the import and the verification reads are all
 * genuine wire traffic.
 */
final class HandoffRemotePushTest extends TestCase
{
    use HomeSandboxTrait;

    private const ATTACH_TOKEN = 'abababababababababababababababababababababababababababababababab';

    private const OTHER_TOKEN = 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    private ProtocolFixture $fixture;

    private Server $server;

    private string $sandbox = '';

    /** @var list<RemoteSessionHost> */
    private array $hosts = [];

    /** @var list<\React\Socket\ServerInterface> accept-then-silent stubs dialed here */
    private array $silentSockets = [];

    /** @var list<ConnectionInterface> connections those stubs accepted */
    private array $silentConnections = [];

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/ccmp-csess7g-' . bin2hex(random_bytes(6));
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
        foreach ($this->silentConnections as $connection) {
            $connection->close();
        }
        foreach ($this->silentSockets as $socket) {
            $socket->close();
        }
        RemoteSessionHost::useForLaunch(null);
        $this->server->stop();
        $this->fixture->tearDown();
        putenv('CANDY_CODE_SERVER_DIR');
        putenv('CANDY_CODE_SERVER_TOKEN');
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    public function testATeleportLandsOnTheServeAndPrintsTheAttachLine(): void
    {
        putenv('CANDY_CODE_SERVER_TOKEN=' . self::ATTACH_TOKEN);
        $before = $this->listedIds();
        $result = $this->runCommand('/handoff --remote http://127.0.0.1:' . $this->server->port());

        self::assertCount(1, $result->effects, 'the teleport is the one effect');
        $effect = $result->effects[0];
        $landed = $this->fixture->await(($effect->run())());

        self::assertInstanceOf(HandoffSeededMsg::class, $landed);
        self::assertNull($landed->sessionId, 'the local window opens no session');
        self::assertNull($landed->error, 'the dial settled');
        self::assertNotNull($landed->remoteUrl, 'the remote arm marker rides the landing');

        // The row reports the attach line for the NEW id, on this URL.
        $row = $effect->describe()($landed);
        self::assertInstanceOf(Message::class, $row);
        self::assertSame($landed->seed, $row->content);
        self::assertStringContainsString('candy-code attach ', $row->content);
        self::assertStringContainsString('--url http://127.0.0.1:' . $this->server->port(), $row->content);
        self::assertFalse($row->error, 'a settled teleport is not a failure row');
        preg_match('/attach (\S+) --url/', $row->content, $m);
        self::assertNotSame([], $m, 'the attach line carries the new id');
        self::assertNotSame($before, $this->listedIds(), 'the session landed on the serve');
        self::assertContains($m[1], $this->listedIds(), 'the imported session answers session.list');

        // And the transcript rode the wire with it: the export of the new
        // session reads back the rows the local history held.
        $export = $this->callFromVerifier('session.export', ['sessionId' => $m[1], 'format' => 'json']);
        self::assertStringContainsString('ship the login fix', json_encode($export, JSON_THROW_ON_ERROR));
    }

    public function testARefusedTokenSurfacesAClearFailureRow(): void
    {
        putenv('CANDY_CODE_SERVER_TOKEN=' . self::OTHER_TOKEN);
        $before = $this->listedIds();
        $result = $this->runCommand('/handoff --remote http://127.0.0.1:' . $this->server->port());

        self::assertCount(1, $result->effects);
        $effect = $result->effects[0];
        $landed = $this->fixture->await(($effect->run())());

        self::assertInstanceOf(HandoffSeededMsg::class, $landed);
        self::assertNull($landed->sessionId);
        self::assertNotNull($landed->error, 'the upgrade refusal must be named, not swallowed');

        $row = $effect->describe()($landed);
        self::assertInstanceOf(Message::class, $row);
        self::assertStringContainsString('Teleport failed', $row->content);
        self::assertTrue($row->error, 'a refused teleport is a failure row');
        self::assertSame($before, $this->listedIds(), 'the refused attempt imported nothing');
    }

    public function testAMissingTokenIsRefusedBeforeTheDial(): void
    {
        putenv('CANDY_CODE_SERVER_TOKEN');
        putenv('CANDY_CODE_SERVER_DIR=' . $this->sandbox . '/no-such-state');
        $result = $this->runCommand('/handoff --remote http://127.0.0.1:' . $this->server->port());

        self::assertSame([], $result->effects, 'nothing dials without a token');
        self::assertStringContainsString('token', $this->replyText($result));
    }

    public function testPlainWsToANonLoopbackHostIsRefusedBeforeTheDial(): void
    {
        putenv('CANDY_CODE_SERVER_TOKEN=' . self::ATTACH_TOKEN);
        $result = $this->runCommand('/handoff --remote ws://example.com');

        self::assertSame([], $result->effects, 'the transcript never rides an unencrypted stranger wire');
        self::assertStringContainsString('wss://', $this->replyText($result));
    }

    public function testAWssTargetPassesTheLoopbackLawAndKeepsTheEffect(): void
    {
        putenv('CANDY_CODE_SERVER_TOKEN=' . self::ATTACH_TOKEN);
        // The thunk is deliberately NOT run: validation accepts wss:// and
        // queues the dial; the loopback cases prove the dial itself.
        $result = $this->runCommand('/handoff --remote wss://host.example');

        self::assertCount(1, $result->effects);
        self::assertStringContainsString('host.example', $this->replyText($result));
    }

    public function testANonUrlOperandIsRefusedAndABareFocusStillTakesTheLocalArm(): void
    {
        putenv('CANDY_CODE_SERVER_TOKEN=' . self::ATTACH_TOKEN);
        $bad = $this->runCommand('/handoff --remote note-to-self');
        self::assertSame([], $bad->effects, 'words that are not an address never dial');
        self::assertStringContainsString('attach', $this->replyText($bad));

        // With --remote gone the focus arm is untouched: it keeps its
        // summary reply, not the teleport wording.
        $local = $this->runCommand('/handoff the auth tests');
        self::assertStringNotContainsString('Teleporting', $this->replyText($local));
    }

    public function testTheUsageErrorRejectsMissingAndExtraOperands(): void
    {
        putenv('CANDY_CODE_SERVER_TOKEN=' . self::ATTACH_TOKEN);
        foreach (['/handoff --remote', '/handoff --remote http://127.0.0.1:1 extra'] as $text) {
            $result = $this->runCommand($text);
            self::assertSame([], $result->effects, $text . ' never dials');
            self::assertStringContainsString('Usage', $this->replyText($result));
        }
    }

    /**
     * The token resolver follows attach exactly: env wins, else the state
     * directory's token file — read, shape-checked, never minted.
     */
    public function testTheTokenResolverReadsEnvThenTheStateFileAndMintsNothing(): void
    {
        putenv('CANDY_CODE_SERVER_TOKEN');
        putenv('CANDY_CODE_SERVER_DIR');
        self::assertNull(RemoteSessionPush::resolveToken(), 'no env, no readable state dir: no token');

        $dir = $this->sandbox . '/state';
        mkdir($dir, 0700, true);
        putenv('CANDY_CODE_SERVER_DIR=' . $dir);
        self::assertNull(RemoteSessionPush::resolveToken(), 'a dir without a token file yields nothing');

        file_put_contents($dir . '/' . TokenStore::FILE, self::OTHER_TOKEN . "\n");
        chmod($dir . '/' . TokenStore::FILE, 0600);
        self::assertSame(self::OTHER_TOKEN, RemoteSessionPush::resolveToken(), 'the file token is read, trimmed');

        file_put_contents($dir . '/' . TokenStore::FILE, 'not-a-token');
        self::assertNull(RemoteSessionPush::resolveToken(), 'a malformed file is refused, never minted over');

        putenv('CANDY_CODE_SERVER_TOKEN=' . self::ATTACH_TOKEN);
        self::assertSame(self::ATTACH_TOKEN, RemoteSessionPush::resolveToken(), 'the env override wins, as in attach');
    }

    #[DataProvider('dialAllowedProvider')]
    public function testDialAllowedAcceptsLoopbackWsAndAnyWss(string $wsUrl, bool $expected): void
    {
        self::assertSame($expected, RemoteSessionPush::dialAllowed($wsUrl));
    }

    /** @return list<array{string, bool}> */
    public static function dialAllowedProvider(): array
    {
        return [
            ['ws://127.0.0.1:7420/ws', true],
            ['ws://127.1.2.3/ws', true],
            ['ws://localhost:9/ws', true],
            ['ws://[::1]:9/ws', true],
            ['wss://host.example/ws', true],
            ['ws://example.com/ws', false],
            ['ws://10.0.0.5:9/ws', false],
            ['ws://128.0.0.1/ws', false],
            // C-SES-7.g r1 MAJOR: the 127. leg is OCTETS only — a DNS name
            // that merely starts like an address is a stranger's resolver.
            ['ws://127.attacker.example/ws', false],
            ['ws://127.0.0.1.attacker.example/ws', false],
        ];
    }

    public function testTheImportBoundIsEnforcedBeforeTheDial(): void
    {
        self::assertTrue(
            RemoteSessionPush::fitsWithinImportBound(str_repeat('x', 1024)),
            'an ordinary transcript fits',
        );
        self::assertFalse(
            RemoteSessionPush::fitsWithinImportBound(str_repeat('x', SessionMethods::IMPORT_MAX_CONTENT_BYTES + 1)),
            'a body over the server bound is refused client-side too',
        );
    }

    /**
     * C-SES-7.g r1 (MINOR-1): a target that completes the websocket upgrade
     * and then stays silent must REJECT inside the answer window, not hold
     * the effect and the socket forever. Production arms
     * {@see Attach::CONNECT_TIMEOUT_SECONDS}; the bound parameter exists so
     * this pin can arm a short window. Neuter the timer and this promise
     * stays pending: the await's own 5 s bound answers with a DIFFERENT
     * message and the containment assert below goes red.
     */
    public function testASilentTargetIsRejectedWithinTheAnswerWindow(): void
    {
        $socket = new \React\Socket\Server('127.0.0.1:0', Loop::get());
        $this->silentSockets[] = $socket;
        $socket->on('connection', function (ConnectionInterface $connection): void {
            $this->silentConnections[] = $connection;
            $buffer = '';
            $answered = false;
            $connection->on('data', function (string $chunk) use (&$buffer, &$answered, $connection): void {
                if ($answered) {
                    return;
                }
                $buffer .= $chunk;
                if (strpos($buffer, "\r\n\r\n") === false) {
                    return;
                }
                if (preg_match('/Sec-WebSocket-Key: (.*)\r\n/i', $buffer, $m) !== 1) {
                    return;
                }
                $answered = true;
                $accept = base64_encode(sha1(trim($m[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                $connection->write(
                    "HTTP/1.1 101 Switching Protocols\r\n"
                    . "Upgrade: websocket\r\n"
                    . "Connection: Upgrade\r\n"
                    . 'Sec-WebSocket-Accept: ' . $accept . "\r\n"
                    . 'Sec-WebSocket-Protocol: ' . ServerConfig::SUBPROTOCOL . "\r\n"
                    . "\r\n",
                );
                // ...and then silence: server.hello is never answered.
            });
        });

        $wsUrl = RemoteSessionPush::websocketUrl(str_replace('tcp://', 'http://', (string) $socket->getAddress()));
        self::assertNotNull($wsUrl);

        $settled = $this->fixture->await(
            RemoteSessionPush::push($wsUrl, self::ATTACH_TOKEN, '{}', null, 0.25),
        );

        self::assertInstanceOf(RuntimeException::class, $settled);
        self::assertStringContainsString(
            'answered nothing within',
            $settled->getMessage(),
            'the push answer window names the refusal — not the test await bound',
        );
    }

    /**
     * C-SES-7.g r1 (MINOR-1, second leg): the window is one-shot — both
     * settle arms cancel it the moment the chain answers, so a settled
     * teleport leaves no pending timer holding the loop. A late,
     * uncancelled fire is promise-invisible by construction (the $settled
     * guard keeps the deferred honest), so loop hygiene is pinned on the
     * method body: one cancel in each arm, the success arm before resolve,
     * and (weld-minor rider) the connect-success arm guards on $settled
     * before adopting a late-dial host.
     */
    public function testTheAnswerWindowIsCancelledByBothSettleArms(): void
    {
        $source = (string) file_get_contents((new ReflectionMethod(RemoteSessionPush::class, 'push'))->getFileName());
        $start = strpos($source, 'public static function push(');
        self::assertIsInt($start);
        $end = strpos($source, "\n    }\n", (int) $start);
        self::assertIsInt($end);
        $body = substr($source, (int) $start, (int) $end - (int) $start);

        self::assertSame(2, substr_count($body, '$loop->cancelTimer($timer);'), 'the resolve arm and the reject arm each cancel the window');
        self::assertLessThan((int) strpos($body, '$deferred->resolve('), (int) strpos($body, '$loop->cancelTimer($timer);'), 'the success arm cancels before it resolves');

        // Weld-minor rider (REVIEW-2 finding 1): the connect-success arm must
        // carry the $settled guard BEFORE it adopts the late host, so a dial
        // finishing after the window rejected closes and stays rejected.
        $connect = strpos($body, 'Attach::connect(');
        self::assertIsInt($connect);
        $settleArms = strpos($body, 'static function (string $id)', (int) $connect);
        self::assertIsInt($settleArms);
        $inner = substr($body, (int) $connect, (int) $settleArms - (int) $connect);
        self::assertGreaterThanOrEqual(2, substr_count($inner, '$settled'), 'the inner arm both carries &$settled and guards on it');
        self::assertStringContainsString('if ($settled)', $inner, 'the guard is present');
        $guard = (int) strpos($inner, 'if ($settled)');
        self::assertGreaterThan(0, $guard);
        self::assertLessThan((int) strpos($inner, '$opened = $host;'), $guard, 'the guard runs before the late host is adopted');
        self::assertStringContainsString('the teleport answer window expired before the dial completed', $inner, 'the late-dial rejection names the window, per REVIEW-2');
    }

    /** @return list<string> */
    private function listedIds(): array
    {
        $listed = $this->callFromVerifier('session.list', ['limit' => 200]);

        return array_map(static fn (array $s): string => (string) $s['id'], $listed['items']);
    }

    private function runCommand(string $text): CommandResult
    {
        return (new HandoffHostCommand())->run($this->context(), $text);
    }

    private function context(): CommandContext
    {
        $store = new EnhancedSessionStore($this->sandbox . '/push-sessions.db');
        if ($store->getSession('parent') === null) {
            $store->createSession('parent', 'candy-code', 'unknown', name: 'login work');
        }

        return CommandContext::new(
            history: [
                Message::user('ship the login fix'),
                Message::assistant('patched Auth.php and pinned the regression test.'),
            ],
            sessionId: 'parent',
            sessionStore: $store,
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callFromVerifier(string $method, array $params): array
    {
        $wsUrl = Attach::websocketUrl('http://127.0.0.1:' . $this->server->port());
        self::assertNotNull($wsUrl);
        $host = $this->fixture->await(Attach::connect($wsUrl, self::ATTACH_TOKEN));
        $this->hosts[] = $host;
        $this->fixture->await($host->hello());

        return $this->fixture->await($host->call($method, $params));
    }

    private function replyText(CommandResult $result): string
    {
        return implode("\n", array_map(static fn (Message $m): string => $m->content, $result->rows));
    }
}
