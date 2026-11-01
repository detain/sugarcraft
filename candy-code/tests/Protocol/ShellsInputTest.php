<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\Client;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\Schema\MethodSchemas;
use SugarCraft\Code\Protocol\Methods\ShellsMethods;
use SugarCraft\Code\Protocol\Request;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Ws\Outbox;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tools\BuiltIn\PtyRun;
use SugarCraft\Code\Tools\BuiltIn\ShellSend;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\ScriptedTurnBackend;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * C-TOOL-6.l: the `shells.input` wire door — a POLLING, line-oriented typing
 * leg onto the interactive background shells C-TOOL-6.j/6.k welded.
 *
 * This is deliberately NOT a terminal: there is no PTY-over-WebSocket stream,
 * and nothing here makes the browser an emulator. The pins therefore check
 * exactly the shape the design names — the composed keystroke (literal text
 * first, then at most one named key token), the refusal classes, the human
 * stamp, and the single audit line per call that carries the byte COUNT and
 * never the bytes.
 *
 * The live legs run whenever the host has the pty substrate — and they do NOT
 * skip without it: the no-skip doctrine of {@see \SugarCraft\Code\Tests\Sessions\BackgroundShellInteractiveTest}
 * is the suite canary's law, and a substrate regression must turn these pins
 * red, not silently thin the roster. The relay-attach window is retried the
 * same 6.k way: readiness through {@see ShellSend::relayReady()}, and the two
 * window answers alone earn a retry.
 *
 * Harness: {@see ShellsMethodsTest}'s serve/client/spawn shape renamed to the
 * c6l- prefix (the drift discipline: distinct names, not a byte-twin the
 * census would accuse), plus a log sink the ServerContext closure collects
 * into, so the audit line is EXEC'd rather than reasoned about.
 */
final class ShellsInputTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir = '';

    private BackgroundShellRegistry $registry;

    private SessionHub $hub;

    private ServerContext $context;

    private Dispatcher $dispatcher;

    /** @var list<string> every line the sink received */
    private array $sink = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = \sys_get_temp_dir() . '/crush-shells-input-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir . '/project', 0o700, true);
        $this->useHomeSandbox($this->dir . '/home');

        $this->registry = BackgroundShellRegistry::forSession(
            'shells-input-test',
            $this->dir . '/shells',
            static function (float $seconds): void {
                \usleep(2000);
            },
        );
        $this->c6lServe(withRegistry: true);
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

    // --- the advertised border ----------------------------------------------

    public function testTheInputBorderIsAdvertisedAsWriteScopeWithItsSchemaByteConsistent(): void
    {
        $spec = Dispatcher::methods()->get('shells.input');
        self::assertNotNull($spec, 'shells.input is on the roster');
        self::assertSame(Scope::Write, $spec->scope, 'typing is a write door; the scope IS the gate');
        self::assertTrue($spec->sideEffects, 'a keystroke changes the world');
        self::assertStringContainsString('not a PTY stream', $spec->description, 'the design sentence survives in the advertised description');
        self::assertStringContainsString('terminal emulator', $spec->description);

        $schema = MethodSchemas::params('shells.input');
        self::assertNotNull($schema, 'the register-arm name and the schema key are the SAME string');
        $node = $schema->toArray();
        self::assertSame(['sessionId', 'shellId'], $node['required']);
        self::assertSame(ShellsMethods::MAX_TEXT_BYTES, $node['properties']['text']['maxLength'] ?? null, 'the schema cap and the method cap are one number');
        self::assertSame(ShellsMethods::INPUT_KEYS, $node['properties']['key']['enum'] ?? null, 'the enum, the composition table and the method agree in ORDER');
        foreach (ShellsMethods::INPUT_KEYS as $key) {
            self::assertArrayHasKey($key, PtyRun::KEY_TOKENS, $key . ' rides the PtyRun grammar, never a private spelling');
        }
    }

    /** @return array<string, array{string, string}> */
    public static function everyNamedKeyComposesTextThenTheTokenProvider(): array
    {
        $rows = [];
        foreach (ShellsMethods::INPUT_KEYS as $key) {
            $rows[$key] = [$key, PtyRun::KEY_TOKENS[$key]];
        }

        return $rows;
    }

    #[DataProvider('everyNamedKeyComposesTextThenTheTokenProvider')]
    public function testEveryNamedKeyComposesTextThenTheToken(string $key, string $token): void
    {
        self::assertSame('ab' . $token, ShellsMethods::typeBytes('ab', $key), 'text first, then the key — line completion rides this order');
        self::assertSame($token, ShellsMethods::typeBytes(null, $key));
    }

    public function testCompositionWithoutAKeyIsLiteralAndDepthRefusesForeignSpellings(): void
    {
        self::assertSame('plain', ShellsMethods::typeBytes('plain', null));
        self::assertSame("hello\r", ShellsMethods::typeBytes('hello', 'Enter'), 'the design example: a line and Enter in one call');

        foreach (['Home', 'PageUp', 'C-a', 'enter'] as $spelling) {
            try {
                ShellsMethods::typeBytes(null, $spelling);
                self::fail($spelling . ' is outside the input grammar');
            } catch (RpcError $error) {
                self::assertSame('shell_input_key', $error->kind, $spelling . ' answers the depth leg with its own kind');
            }
        }
    }

    // --- refusal classes, on the wire ----------------------------------------

    public function testTypingIntoAnUnknownOrForeignShellAnswersTheRegistrysMissingIdSentence(): void
    {
        $theirs = $this->c6lSpawn('sleep 30', 'theirs', 's2');
        $client = $this->c6lClient();

        foreach (['sh-00000000', $theirs] as $shellId) {
            $refused = $client->request('shells.input', ['sessionId' => 's1', 'shellId' => $shellId, 'text' => 'hi']);
            self::assertSame('shell_not_found', $refused['error']['data']['kind']);
            self::assertSame(ErrorCode::NotFound->value, $refused['error']['code']);
            self::assertSame('no background shell ' . $shellId . ' is tracked by this launch', $refused['error']['message'], 'unknown and foreign are one sentence');
        }
    }

    public function testTypingIntoAPlainRunningShellAnswersShellNotInteractive(): void
    {
        $id = $this->c6lSpawn('sleep 30', 'plain', 's1');

        $refused = $this->c6lInput(['sessionId' => 's1', 'shellId' => $id, 'text' => 'ls', 'key' => 'Enter']);
        self::assertSame('shell_not_interactive', $refused['error']['data']['kind']);
        self::assertSame(ErrorCode::Conflict->value, $refused['error']['code']);
        self::assertStringContainsString('not an interactive (pty) shell', $refused['error']['message']);
    }

    public function testEndOfFileKeySettlesTheShellAndAFollowingSendAnswersShellSettled(): void
    {
        $id = $this->c6lStartCat();

        $bye = $this->c6lInput(['sessionId' => 's1', 'shellId' => $id, 'key' => 'C-d']);
        self::assertSame(true, $bye['result']['accepted'] ?? null, 'the EOF press itself is accepted');

        $this->c6lAwaitSettled($id);
        $after = $this->c6lInput(['sessionId' => 's1', 'shellId' => $id, 'text' => 'still there?']);
        self::assertSame('shell_settled', $after['error']['data']['kind']);
        self::assertStringContainsString('has already finished', $after['error']['message']);
    }

    public function testControlKeyPressesAreDeliveredAsTheirTokensAndAnsweredHonestly(): void
    {
        // The door's contract is the BYTE: C-c sends \x03, C-z sends \x1a, the
        // same tokens PtyRun's grammar names. Whether the kernel's line
        // discipline turns \x03 into a SIGINT for the child is the discipline's
        // decision, not this door's — measured here, the interrupted `cat`
        // keeps running (the press lands in the line buffer). So the answer
        // says exactly what happened: the press went through. No claim about
        // signals the server never sent.
        $interrupted = $this->c6lStartCat();
        $sigint = $this->c6lInput(['sessionId' => 's1', 'shellId' => $interrupted, 'key' => 'C-c']);
        self::assertSame(true, $sigint['result']['accepted'] ?? null, 'the press is delivered; the ladder reaps whatever survives at teardown');

        $suspended = $this->c6lStartCat();
        $sleepKey = $this->c6lInput(['sessionId' => 's1', 'shellId' => $suspended, 'key' => 'C-z']);
        self::assertSame(true, $sleepKey['result']['accepted'] ?? null);
    }

    public function testOversizeTextIsRefusedByTheSchemaBeforeTheHandlerAndACrossingCompositionAtTheCap(): void
    {
        $tooLong = $this->c6lInput(['sessionId' => 's1', 'shellId' => 'sh-00000000', 'text' => \str_repeat('x', ShellsMethods::MAX_TEXT_BYTES + 1)]);
        self::assertSame('invalid_params', $tooLong['error']['data']['kind'], 'the schema door names the path, never the value');
        self::assertStringContainsString('text', $tooLong['error']['message']);

        $cat = $this->c6lStartCat();
        $crossing = $this->c6lInput(['sessionId' => 's1', 'shellId' => $cat, 'text' => \str_repeat('y', ShellsMethods::MAX_TEXT_BYTES), 'key' => 'Enter']);
        self::assertSame('text_too_long', $crossing['error']['data']['kind'], '4096 of text plus the Enter token crosses the registry cap and answers the same kind');
        self::assertSame('a send is capped at ' . ShellsMethods::MAX_TEXT_BYTES . ' bytes; this one carried ' . (ShellsMethods::MAX_TEXT_BYTES + 1), $crossing['error']['message']);
    }

    public function testForeignSpellingsNulBytesAndAnEmptyCallAreRefusedWithTheirKinds(): void
    {
        $id = $this->c6lSpawn('sleep 30', 'plain', 's1');
        $client = $this->c6lClient();

        $badKey = $client->request('shells.input', ['sessionId' => 's1', 'shellId' => $id, 'key' => 'Home']);
        self::assertSame('invalid_params', $badKey['error']['data']['kind'], 'the schema enum is the enforcement point for key');
        self::assertStringContainsString('key', $badKey['error']['message']);

        $nul = $client->request('shells.input', ['sessionId' => 's1', 'shellId' => $id, 'text' => "a\x00b", 'key' => 'Enter']);
        self::assertSame('shell_input_nul', $nul['error']['data']['kind']);
        self::assertStringContainsString('NUL', $nul['error']['message']);

        $empty = $client->request('shells.input', ['sessionId' => 's1', 'shellId' => $id]);
        self::assertSame('shell_input_empty', $empty['error']['data']['kind']);
        self::assertStringContainsString('text or key', $empty['error']['message']);
    }

    // --- the scope door, EXEC'd at the dispatcher's own choke ----------------

    public function testAClientWithoutWriteScopeIsRefusedAtTheRequireScopeChokeTheDispatcherRuns(): void
    {
        $wire = WireClient::open($this->dispatcher, 'c6l-ro');
        $wire->hello();
        // The dispatcher hands every wire connection the owner roster, so a
        // read-only client is not constructible ON the socket in this era;
        // the refusal below is the exact call the dispatcher makes at its
        // :348 choke, EXEC'd against a read-scoped Client (disclosed in the
        // lane REPORT).
        $limited = new Client($wire->connection, Outbox::for($wire->connection), [Scope::Read->value], static fn (): float => 0.0);
        $call = new CallContext($limited, new Request(1, true, 'shells.input', []), $this->context, $this->dispatcher->registry());

        try {
            $call->requireScope($this->scopeOf('shells.input'));
            self::fail('a read-scoped client must not stand at the input door');
        } catch (RpcError $error) {
            self::assertSame(ErrorCode::Forbidden, $error->errorCode);
            self::assertSame('forbidden', $error->kind);
            self::assertSame('this needs the write scope', $error->getMessage());
        }
    }

    private function scopeOf(string $method): Scope
    {
        $spec = Dispatcher::methods()->get($method);
        self::assertNotNull($spec);

        return $spec->scope;
    }

    // --- the audit line -------------------------------------------------------

    public function testEveryInputCallLeavesExactlyOneAuditLineWithCountsAndNeverTheBytes(): void
    {
        $marker = 'mk' . \bin2hex(\random_bytes(6));
        $client = $this->c6lClient();

        $unknown = $client->request('shells.input', ['sessionId' => 's1', 'shellId' => 'sh-00000000', 'text' => $marker, 'key' => 'Enter']);
        self::assertSame('shell_not_found', $unknown['error']['data']['kind']);
        $lines = $this->c6lAuditLines();
        self::assertCount(1, $lines, 'a refused send still logs its attempt');
        self::assertMatchesRegularExpression(
            '/^shells\.input: session=s1 shell=sh-00000000 client=c6l-c1 principal=bearer bytes=' . \strlen($marker . "\r") . '$/',
            $lines[0],
        );

        $client->request('shells.input', ['sessionId' => 's1', 'shellId' => 'sh-00000000']);
        self::assertCount(2, $this->c6lAuditLines(), 'an empty call logs zero bytes, not silence');

        $acceptedCat = $this->c6lStartCat();
        $accepted = $client->request('shells.input', ['sessionId' => 's1', 'shellId' => $acceptedCat, 'text' => $marker, 'key' => 'Enter']);
        self::assertSame(true, $accepted['result']['accepted'] ?? null);
        $lines = $this->c6lAuditLines();
        self::assertCount(3, $lines, 'one line per call — never two, never none');

        foreach ($lines as $line) {
            self::assertStringNotContainsString($marker, $line, 'the shape of the keystroke reaches the log; the keystroke never does');
        }
    }

    public function testTheUnusableTableRefusesLoudlyAndStillLogsOneLineWithEmptyFields(): void
    {
        $this->dispatcher->stop('replaced');
        $this->hub->closeAll();
        $this->c6lServe(withRegistry: false);

        $refused = $this->c6lClient()->request('shells.input', ['sessionId' => 's1', 'shellId' => 'sh-00000000', 'text' => 'x']);
        self::assertSame('shells_unavailable', $refused['error']['data']['kind']);
        self::assertSame(ErrorCode::UnsupportedInServer->value, $refused['error']['code']);

        $lines = $this->c6lAuditLines();
        self::assertCount(1, $lines, 'the finally arm audits even the table-less refusal');
        self::assertStringStartsWith('shells.input: session= shell= client=c6l-c1 principal=bearer bytes=0', $lines[0]);
    }

    public function testALaunchWithoutALogSinkStaysSilentWithoutThrowing(): void
    {
        $config = ServerConfig::new($this->dir)->withRoot($this->dir . '/project');
        $silent = ServerContext::new($this->hub, $config, 'test', Loop::get());
        $silent->logLine('nobody is listening');
        self::assertTrue(true, 'reaching the next line IS the pin: the null sink is a no-op, not a crash');
    }

    // --- live typing legs -----------------------------------------------------

    public function testTextPlusEnterTypesALineIntoTheShellAndTheAnsweredCursorThreadsTheNextRead(): void
    {
        $id = $this->c6lStartCat();
        $marker = 'ping' . \bin2hex(\random_bytes(4));

        $sent = $this->c6lClient()->call('shells.input', ['sessionId' => 's1', 'shellId' => $id, 'text' => $marker, 'key' => 'Enter']);
        self::assertSame(true, $sent['accepted']);
        self::assertIsInt($sent['cursor']);

        $deadline = \microtime(true) + 6.0;
        $stdout = '';
        do {
            $window = $this->c6lClient()->call('shells.output', ['sessionId' => 's1', 'shellId' => $id, 'cursor' => $sent['cursor']]);
            $stdout = (string) $window['stdout'];
            if (\str_contains($stdout, $marker)) {
                break;
            }
            \usleep(20000);
        } while (\microtime(true) < $deadline);
        self::assertStringContainsString($marker, $stdout, 'the printed line lands past the cursor a send answers — the polling contract threads through it');

        $row = $this->registry->status($id, 's1');
        self::assertSame(1, $row['humanInputs'], 'the row counts the human press, the 6.j stamp');
    }

    /** @return array<string, array{string, string}> */
    public static function echoedKeysArriveVerbatimThroughTheLineDriverProvider(): array
    {
        $rows = [];
        foreach (['Esc', 'Tab', 'Up', 'Down', 'Left', 'Right'] as $key) {
            $rows[$key] = [$key, PtyRun::KEY_TOKENS[$key]];
        }

        return $rows;
    }

    #[DataProvider('echoedKeysArriveVerbatimThroughTheLineDriverProvider')]
    public function testEchoedKeysArriveVerbatimThroughTheLineDriver(string $key, string $token): void
    {
        $id = $this->c6lStartCat();
        $sent = $this->c6lInput(['sessionId' => 's1', 'shellId' => $id, 'text' => 'q', 'key' => $key]);
        self::assertSame(true, $sent['result']['accepted'] ?? null, 'the wire accepts the press');

        // The relay's line discipline buffers silently (echo off, line mode
        // on): the bytes ride the line buffer until Enter completes it, and
        // the shell's own print is what comes back. That is exactly the
        // polling contract this door promises — order survives the round trip.
        $line = $this->c6lInput(['sessionId' => 's1', 'shellId' => $id, 'key' => 'Enter']);
        self::assertSame(true, $line['result']['accepted'] ?? null);

        $needle = 'q' . $token;
        $stdout = $this->c6lAwaitStdout($id, (int) ($sent['result']['cursor'] ?? 0), $needle);
        self::assertStringContainsString($needle, $stdout, $key . ' lands byte-exact, after the text, in the printed line');
    }

    public function testModelWarmSendsDoNotGrowTheHumanCounterAndOneWirePressDoes(): void
    {
        $id = $this->c6lStartCat();
        $warm = $this->registry->send($id, 'x', 'model', 's1');
        self::assertSame(true, $warm['ok'], 'the tool-side send still works beside this door');

        $press = $this->c6lInput(['sessionId' => 's1', 'shellId' => $id, 'text' => 'z', 'key' => 'Enter']);
        self::assertSame(true, $press['result']['accepted'] ?? null);

        $row = $this->registry->status($id, 's1');
        self::assertSame(1, $row['humanInputs'], 'the browser stamp counts browsers, not models');
    }

    // --- harness ---------------------------------------------------------------

    private function c6lServe(bool $withRegistry): void
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
        $sink = function (string $line): void {
            $this->sink[] = $line;
        };
        $this->context = ServerContext::new($this->hub, $config->withRoot($this->dir . '/project'), 'test', Loop::get(), null, $sink);
        $this->dispatcher = Dispatcher::new($this->context);
    }

    private function c6lClient(): WireClient
    {
        $client = WireClient::open($this->dispatcher, 'c6l-c1');
        $client->hello();

        return $client;
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function c6lInput(array $params): array
    {
        $deadline = \microtime(true) + 4.0;
        do {
            $response = $this->c6lClient()->request('shells.input', $params);
            if (($response['error']['data']['kind'] ?? '') !== 'shell_relay_unready') {
                return $response;
            }
            \usleep(25000);
        } while (\microtime(true) < $deadline);

        return $response;
    }

    /** @param string|null $owner the session tag C-TOOL-3.c puts on the row */
    private function c6lSpawn(string $command, string $label, ?string $owner): string
    {
        $started = $this->registry->start($command, $label, [], $owner);
        self::assertTrue($started['ok'], (string) ($started['error'] ?? 'spawn failed'));

        return (string) $started['shell']['id'];
    }

    private function c6lStartCat(): string
    {
        $started = $this->registry->startInteractive('cat', 'typing', 80, 24, [], 's1');
        self::assertTrue($started['ok'], (string) ($started['error'] ?? 'interactive spawn failed'));
        $id = (string) $started['shell']['id'];
        self::assertTrue(ShellSend::relayReady((array) $this->registry->status($id, 's1')), 'the relay attached: this host carries the pty substrate');

        return $id;
    }

    /** @param string|null $why the settle the leg is waiting for, for the failure text */
    private function c6lAwaitSettled(string $id, ?string $why = null): void
    {
        $deadline = \microtime(true) + 6.0;
        while (\microtime(true) < $deadline) {
            $row = $this->registry->status($id, 's1');
            if ($row !== null && (string) $row['state'] !== 'running') {
                return;
            }
            \usleep(20000);
        }

        self::fail(($why ?? 'shell') . ' never settled: ' . $id);
    }

    private function c6lAwaitStdout(string $id, int $cursor, string $needle, float $seconds = 6.0): string
    {
        $deadline = \microtime(true) + $seconds;
        do {
            $read = $this->registry->read($id, (string) $cursor, null, 's1');
            $stdout = (string) ($read['stdout'] ?? '');
            if (\str_contains($stdout, $needle)) {
                return $stdout;
            }
            \usleep(20000);
        } while (\microtime(true) < $deadline);

        self::fail('the shell output never carried the typed bytes past cursor ' . $cursor);
    }

    /** @return list<string> the audit lines only — never logError traffic */
    private function c6lAuditLines(): array
    {
        return \array_values(\array_filter(
            $this->sink,
            static fn (string $line): bool => \str_starts_with($line, 'shells.input: '),
        ));
    }
}
