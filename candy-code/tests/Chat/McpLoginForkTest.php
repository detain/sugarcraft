<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\App\McpLoginProgressMsg;
use SugarCraft\Code\Message;
use SugarCraft\Code\Support\McpLoginFork;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\SourceFileWalkTrait;

/**
 * Roadmap MCP-5: in-TUI `/mcp login <server>` forks the CLI's loopback
 * login as a background child. The fork owns the pipes; Chat owns only the
 * transcript beats. The child is stubbed through the argv seam so the whole
 * announce/poll/exit chain runs without OAuth.
 */
final class McpLoginForkTest extends TestCase
{
    use HomeSandboxTrait;
    use SourceFileWalkTrait;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-mcplogin-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        mkdir($this->sandbox . '/tmp', 0700, true);
    }

    protected function tearDown(): void
    {
        McpLoginFork::releaseAll();
        $this->restoreHomeSandbox();
        self::removeTree($this->sandbox);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }

    /**
     * A child that prints an authorization URL, pauses briefly, exits clean.
     *
     */
    private function stubChild(string $body, int $holdMicros = 0): void
    {
        McpLoginFork::$argvOverride = static fn (string $server, array $rest): array => [
            \PHP_BINARY, '-r', $body . ($holdMicros > 0 ? 'usleep(' . $holdMicros . ');' : ''),
        ];
    }

    /**
     * @param callable(McpLoginProgressMsg): bool $matches
     */
    private function pollFor(string $server, callable $matches, int $tries = 100): McpLoginProgressMsg
    {
        for ($i = 0; $i < $tries; ++$i) {
            $msg = McpLoginFork::poll($server);
            if ($msg instanceof McpLoginProgressMsg && $matches($msg)) {
                return $msg;
            }
            self::assertNotSame(McpLoginProgressMsg::PHASE_GONE, $msg?->phase, 'slot vanished mid-poll');
            usleep(50_000);
        }
        self::fail('no matching beat within ' . ($tries * 50) . 'ms');
    }

    public function testArmThenUrlThenDoneThenGoneOverAStubbedChild(): void
    {
        $this->stubChild('echo "  Open this URL to authorize:\n\n    http://127.0.0.1:41239/cb/code-abc\n";');

        [$notice, $armed] = McpLoginFork::arm('w4cstub', [], $this->sandbox . '/tmp');
        self::assertTrue($armed, $notice);
        self::assertStringContainsString('w4cstub', $notice);
        self::assertTrue(McpLoginFork::isArmed('w4cstub'));

        $url = $this->pollFor('w4cstub', static fn (McpLoginProgressMsg $m): bool => $m->phase === McpLoginProgressMsg::PHASE_URL);
        self::assertSame('http://127.0.0.1:41239/cb/code-abc', $url->text);

        $done = $this->pollFor('w4cstub', static fn (McpLoginProgressMsg $m): bool => $m->phase === McpLoginProgressMsg::PHASE_DONE);
        self::assertSame(0, $done->exitCode);
        self::assertStringContainsString('http://127.0.0.1:41239', $done->text, 'the tail carries what the child printed');
        self::assertFalse(McpLoginFork::isArmed('w4cstub'), 'the slot is released on exit');

        $gone = McpLoginFork::poll('w4cstub');
        self::assertInstanceOf(McpLoginProgressMsg::class, $gone);
        self::assertSame(McpLoginProgressMsg::PHASE_GONE, $gone?->phase);
    }

    /**
     * C-MCP-6.a, the exact OAuthLoopbackFlow.php print shape (the sentence
     * line backticks the SERVER url, then a blank line, then the authorize
     * URL alone on an indented line — :158-160). The old scheme announced the
     * WRONG url (first regex hit was the backticked server sentence) and
     * could not announce a >1500-byte authorize URL at all (the tail window
     * had already scrolled its line start past). The scan buffer holds the
     * output un-truncated up to the announce and matches only a
     * URL-consisting line.
     */
    public function testTheAuthorizeUrlIsAnnouncedIntactPastTheServerUrlLine(): void
    {
        $serverUrl = 'https://mcp.example.test/sse';
        // The '?' rides via chr(): a bare one here makes this literal
        // glob-shaped and moves the GlobDialect corpus census (the harvest
        // admits each shape into BOTH corpora) — house GlobDialect law.
        $authorize = 'https://auth.example.test/authorize' . chr(63) . 'client_id=' . str_repeat('a', 1600)
            . '&scope=all&state=' . str_repeat('b', 200)
            . '&code_challenge=' . str_repeat('c', 200)
            . '&redirect_uri=http%3A%2F%2F127.0.0.1%3A41239%2Fcb';
        $this->stubChild(
            'echo "  Open this URL in a browser to authorize `' . $serverUrl . '`:\n";'
            . ' echo "\n";'
            . ' echo "    ' . $authorize . '\n";',
        );

        [$notice, $armed] = McpLoginFork::arm('w4clong', []);
        self::assertTrue($armed, $notice);

        $url = $this->pollFor('w4clong', static fn (McpLoginProgressMsg $m): bool => $m->phase === McpLoginProgressMsg::PHASE_URL);
        self::assertSame($authorize, $url->text, 'the announce is the authorize URL itself, intact');
        self::assertStringNotContainsString('mcp.example.test', $url->text, 'the backticked server URL is never the announcement');
        self::assertSame(41239, McpLoginFork::redirectPort('w4clong'), 'the redirect_uri port rides the announce for C-MCP-4.c');

        $done = $this->pollFor('w4clong', static fn (McpLoginProgressMsg $m): bool => $m->phase === McpLoginProgressMsg::PHASE_DONE);
        self::assertSame(0, $done->exitCode);
        self::assertSame(41239, McpLoginFork::redirectPort('w4clong'), 'the port outlives the slot — the correlation leg reads it after done');
    }

    public function testUrlIsAnnouncedOnceNotOnEveryBeat(): void
    {
        // C-MCP-6.a in-step: the stub URL now rides a line of its own with
        // leading whitespace — the ONLY shape a real OAuthLoopbackFlow child
        // prints, and the shape the announce now matches (a bare URL at
        // column 0 is not that sentence layout).
        $this->stubChild('echo "  http://127.0.0.1:1/x\n"; usleep(200000);');

        [$notice, $armed] = McpLoginFork::arm('w4conce', []);
        self::assertTrue($armed, $notice);

        $sawUrl = 0;
        for ($i = 0; $i < 60; ++$i) {
            $msg = McpLoginFork::poll('w4conce');
            if ($msg instanceof McpLoginProgressMsg && $msg->phase === McpLoginProgressMsg::PHASE_URL) {
                ++$sawUrl;
            }
            if ($msg instanceof McpLoginProgressMsg && $msg->phase === McpLoginProgressMsg::PHASE_DONE) {
                break;
            }
            usleep(20_000);
        }
        self::assertSame(1, $sawUrl, 'the URL beat fires exactly once per slot');
    }

    public function testASecondLoginForTheSameServerIsRefusedWhileTheFirstLives(): void
    {
        $this->stubChild('usleep(1500000);');

        [, $armed] = McpLoginFork::arm('w4cbusy', []);
        self::assertTrue($armed);

        [$second, $armedAgain] = McpLoginFork::arm('w4cbusy', []);
        self::assertFalse($armedAgain, 'a second slot must not open');
        self::assertStringContainsString('already running', $second);

        // A different server is independent.
        [, $otherArmed] = McpLoginFork::arm('w4cother', []);
        self::assertTrue($otherArmed);
    }

    public function testFailingExitCodeRidesTheDoneBeat(): void
    {
        $this->stubChild('fwrite(STDERR, "✗ Login failed: nope\n"); exit(3);');

        [, $armed] = McpLoginFork::arm('w4cfail', []);
        self::assertTrue($armed);

        $done = $this->pollFor('w4cfail', static fn (McpLoginProgressMsg $m): bool => $m->phase === McpLoginProgressMsg::PHASE_DONE);
        self::assertSame(3, $done->exitCode);
        self::assertStringContainsString('Login failed', $done->text, 'stderr drains into the tail too');
    }

    public function testSlashMcpLoginWithAServerArmsThroughChat(): void
    {
        $this->stubChild('usleep(1500000);');
        $chat = (new Chat(inputBuf: '/mcp login srvA', backend: new EchoBackend()))->withSize(100, 30);
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter));

        self::assertNotNull($cmd, 'the poll Cmd must ride the return channel');
        self::assertTrue(McpLoginFork::isArmed('srvA'));
        $rows = \array_slice($next->history, -2);
        self::assertSame('/mcp login srvA', $rows[0]->content, 'the draft echoes as a UI-only user row');
        self::assertStringContainsString('srvA', $rows[1]->content);
    }

    public function testBeatsThroughChatAppendRowsAndStopOnDone(): void
    {
        $chat = (new Chat(inputBuf: '/mcp login srvB', backend: new EchoBackend()))->withSize(100, 30);
        $waiting = $chat->update(new McpLoginProgressMsg('srvB', McpLoginProgressMsg::PHASE_WAITING));
        self::assertNotNull($waiting[1], 'a waiting beat re-arms silently');
        self::assertCount(\count($chat->history), $waiting[0]->history, 'waiting paints nothing');

        $url = $waiting[0]->update(new McpLoginProgressMsg('srvB', McpLoginProgressMsg::PHASE_URL, 'http://127.0.0.1:9/z'));
        self::assertNotNull($url[1], 'the URL beat re-arms to keep watching');
        $row = $url[0]->history[\count($url[0]->history) - 1];
        self::assertStringContainsString('http://127.0.0.1:9/z', $row->content);

        $done = $url[0]->update(new McpLoginProgressMsg('srvB', McpLoginProgressMsg::PHASE_DONE, '', 0));
        self::assertNull($done[1], 'done ends the chain');
        $closed = $done[0]->history[\count($done[0]->history) - 1];
        self::assertStringContainsString('NEXT launch', $closed->content, 'the honesty sentence rides the close row');
    }

    public function testFailedBeatNamesTheCodeAndGoneStopsSilently(): void
    {
        $chat = (new Chat(inputBuf: '/mcp login srvC', backend: new EchoBackend()))->withSize(100, 30);
        $failed = $chat->update(new McpLoginProgressMsg('srvC', McpLoginProgressMsg::PHASE_DONE, 'tail', 7));
        self::assertNull($failed[1]);
        $row = $failed[0]->history[\count($failed[0]->history) - 1];
        self::assertStringContainsString('7', $row->content);

        $before = \count($chat->history);
        $gone = $chat->update(new McpLoginProgressMsg('srvC', McpLoginProgressMsg::PHASE_GONE));
        self::assertNull($gone[1]);
        self::assertCount($before, $gone[0]->history, 'a gone beat paints nothing');
    }

    public function testBareLoginStillRoutesToTheHostGuidance(): void
    {
        $chat = (new Chat(inputBuf: '/mcp login', backend: new EchoBackend()))->withSize(100, 30);
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter));

        self::assertFalse(McpLoginFork::isArmed('login'), 'no server named — nothing forks');
        self::assertNull($cmd, 'the guide is a terminal answer, not a poll chain');
        $joined = \implode("\n", \array_map(static fn (Message $m): string => $m->content, $next->history));
        self::assertStringContainsString('candy-code mcp auth login <server>', $joined, 'the bare form still routes to the host guidance rows');
    }

    /**
     * f3 (plan-verify-1026b, MCP-5 follow-through): the pre-fix docblock
     * called releaseAll "Test and process-teardown hygiene" while nothing
     * outside this file's own test tearDown had ever called it — a claim
     * with no caller behind it. The docblock now states the truth: the
     * method is test hygiene only, and the real hygiene for a login child
     * that outlives the TUI is the detached finish plus OAuthLoopbackFlow's
     * 300-second self-exit, collision-free because the child's loopback
     * bind is to port 0. These legs pin the sentence shape AND the two
     * source facts it leans on, so either moving reddens the doc-sync.
     */
    public function testReleaseAllDocblockDeclaresTestOnlyHygieneAndItsFactsHold(): void
    {
        $package = \dirname(__DIR__, 2);
        $fork = file_get_contents($package . '/src/Support/McpLoginFork.php');
        $oauth = file_get_contents($package . '/src/MCP/OAuthLoopbackFlow.php');
        self::assertIsString($fork, 'the fork class source must be readable for this pin');
        self::assertIsString($oauth, 'the OAuth flow source must be readable for this pin');

        $flatFork = (string) preg_replace('/\s+/', ' ', $fork);
        self::assertSame(1, substr_count($flatFork, 'TEST HYGIENE ONLY'), 'the docblock names the method test-only');
        self::assertSame(0, substr_count($flatFork, 'Test and process-teardown hygiene'), 'the pre-fix lie must not return');

        self::assertSame(1, substr_count($oauth, "'tcp://127.0.0.1:0'"), 'the loopback bind is to port 0, so a survivor cannot block the next login bind');
        self::assertSame(1, substr_count($oauth, 'DEFAULT_TIMEOUT_SECONDS = 300.0'), 'the self-exit bound that reaps a survivor is still 300 s');
    }

    /**
     * Census half of the same claim: releaseAll has exactly one production
     * non-caller — none. A future lane that WIRES a call site flips this
     * red on purpose: quitting would then abort in-flight logins, which is
     * a product decision that must re-derive the docblock in the same
     * commit, not an edit that slips past silently.
     */
    public function testNoProductionCodeCallsReleaseAll(): void
    {
        $callers = [];
        foreach (self::everySourceFileIn(\dirname(__DIR__, 2), ['src', 'bin']) as $relative => $path) {
            if ($relative === 'src/Support/McpLoginFork.php') {
                continue; // the declaration itself; call sites live elsewhere
            }
            $text = file_get_contents($path);
            self::assertIsString($text, $relative . ' must be readable for this census');
            if (str_contains($text, 'releaseAll')) {
                $callers[] = $relative;
            }
        }

        self::assertSame([], $callers, 'releaseAll() has no production caller — wiring one re-opens the quit-aborts-login decision and the docblock together');
    }

    /**
     * C-MCP-4.c: the paste-back legs. The browser leg of a forked login
     * happens on the user's machine; when `candy-code serve` runs the login
     * on another host, the redirect cannot cross that boundary, so the user
     * pastes the callback URL back and the validated loopback GET below
     * feeds it to the still-waiting child. These pins drive a REAL parent
     * process listener standing in for the child's one, so the whole
     * validate-then-dial chain is exercised over a live socket.
     */

    /** @return array{0: mixed, 1: int} [listening resource, port] */
    private function loopbackListener(): array
    {
        $errno = 0;
        $errstr = '';
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server, 'the paste-back pins need a real loopback listener');
        $name = (string) \stream_socket_get_name($server, false);
        $port = (int) \substr($name, (int) \strrpos($name, ':') + 1);
        self::assertGreaterThan(0, $port, 'the listener answered on a real bound port');
        \stream_set_blocking($server, false);

        return [$server, $port];
    }

    /**
     * Arm a stub child whose announce binds redirect_uri to $port, and wait
     * for the URL beat so the slot is live AND its port is known.
     */
    private function armedAwaitingCallback(int $port, string $server): void
    {
        // The '?' rides via chr(): a bare one makes the literal glob-shaped
        // and moves the GlobDialect corpus census — house GlobDialect law.
        $authorize = 'https://auth.example.test/authorize' . chr(63) . 'client_id=abc&state=s1'
            . '&redirect_uri=' . rawurlencode('http://127.0.0.1:' . $port . '/cb');
        $this->stubChild('echo "    ' . $authorize . '\n";', 3_000_000);

        [$notice, $armed] = McpLoginFork::arm($server, [], $this->sandbox . '/tmp');
        self::assertTrue($armed, $notice);
        $this->pollFor($server, static fn (McpLoginProgressMsg $m): bool => $m->phase === McpLoginProgressMsg::PHASE_URL);
        self::assertSame($port, McpLoginFork::redirectPort($server), 'the announce taught the slot its callback port');
    }

    public function testThePastedCallbackIsDeliveredToTheWaitingChildsListener(): void
    {
        [$listener, $port] = $this->loopbackListener();
        $this->armedAwaitingCallback($port, 'w4cpaste');

        [$notice, $armed] = McpLoginFork::arm('w4cpaste', ['--code', 'http://127.0.0.1:' . $port . '/cb' . chr(63) . 'code=live-code-1&state=s1']);
        self::assertFalse($armed, 'the delivery leg never spawns a second child');
        self::assertStringContainsString('delivered to the waiting login', $notice);

        $conn = @\stream_socket_accept($listener, 3.0);
        self::assertIsResource($conn, 'the validated loopback address was dialed');
        \stream_set_timeout($conn, 1);
        $request = (string) \fread($conn, 2048);
        self::assertStringStartsWith('GET /cb' . chr(63) . 'code=live-code-1&state=s1 HTTP/1.0', $request, 'the code and state ride through untouched for the child to verify');
        self::assertStringContainsString('Host: 127.0.0.1:' . $port, $request);
        fclose($conn);
        fclose($listener);
    }

    public function testPasteBackRefusalsNameTheirReasonAndNeverDial(): void
    {
        [$listener, $port] = $this->loopbackListener();
        $this->armedAwaitingCallback($port, 'w4cref');

        $canary = 'canary-code-value';
        $cases = [
            'scheme' => ['https://127.0.0.1:' . $port . '/cb', 'scheme'],
            'host' => ['http://evil.example.test:' . $port . '/cb', 'loopback'],
            'port' => ['http://127.0.0.1:' . ($port + 1) . '/cb', 'port'],
            'noport' => ['http://localhost/cb', 'port'],
        ];
        foreach ($cases as $label => [$url, $word]) {
            [$notice, $armed] = McpLoginFork::arm('w4cref', ['--code', $url . chr(63) . 'code=' . $canary]);
            self::assertFalse($armed, $label);
            self::assertStringStartsWith('! Could not deliver', $notice, $label);
            self::assertStringContainsString($word, $notice, $label);
            self::assertStringNotContainsString($canary, $notice, 'a refusal never echoes the URL — it carries a live code');
        }

        [$shape, $shapeArmed] = McpLoginFork::arm('w4cref', ['--code']);
        self::assertFalse($shapeArmed);
        self::assertStringContainsString('exactly one URL', $shape, 'a bare --code is the shape arm');
        [$extra] = McpLoginFork::arm('w4cref', ['--code', 'http://127.0.0.1:' . $port . '/cb', 'extra']);
        self::assertStringContainsString('exactly one URL', $extra, 'two URLs are not one callback');

        [$noslot, $noslotArmed] = McpLoginFork::arm('w4cnoslot', ['--code', 'http://127.0.0.1:' . $port . '/cb']);
        self::assertFalse($noslotArmed);
        self::assertStringContainsString('waiting on this host', $noslot, 'a callback for a login nobody armed is refused, never dialed');

        $reads = [$listener];
        $writes = null;
        $excepts = null;
        $ready = @\stream_select($reads, $writes, $excepts, 0);
        self::assertLessThan(1, (int) $ready, 'every refusal above left the listener untouched — the leg is not an SSRF carrier');
        fclose($listener);
    }

    public function testCodeIsJudgedBeforeTheBusyRefusal(): void
    {
        [$listener, $port] = $this->loopbackListener();
        $this->armedAwaitingCallback($port, 'w4corder');

        [$notice, $armed] = McpLoginFork::arm('w4corder', ['--code']);
        self::assertFalse($armed);
        self::assertStringNotContainsString('already running', $notice, 'a paste-back is a delivery, not a second spawn — the busy arm must not swallow it');
        self::assertStringContainsString('exactly one URL', $notice);
        fclose($listener);
    }

    public function testPasteBackToADeadListenerRefusesWithoutHanging(): void
    {
        // Bind-then-free FIRST: a child spawned while the listener is open
        // inherits its fd across the exec (no CLOEXEC on the test's raw
        // socket), so the port would stay reachable through the child even
        // after the parent closed it. The spawn-hygiene seam is recorded in
        // this lane's report; here the port must genuinely be dead when the
        // child announces it.
        $errno = 0;
        $errstr = '';
        $probe = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($probe, 'a free loopback port is bindable for the probe');
        $name = (string) \stream_socket_get_name($probe, false);
        $port = (int) \substr($name, (int) \strrpos($name, ':') + 1);
        fclose($probe);

        $authorize = 'https://auth.example.test/authorize' . chr(63) . 'client_id=abc&state=s1'
            . '&redirect_uri=' . rawurlencode('http://127.0.0.1:' . $port . '/cb');
        $this->stubChild('echo "    ' . $authorize . '\n";', 3_000_000);
        [, $armed] = McpLoginFork::arm('w4cdead', [], $this->sandbox . '/tmp');
        self::assertTrue($armed);
        $this->pollFor('w4cdead', static fn (McpLoginProgressMsg $m): bool => $m->phase === McpLoginProgressMsg::PHASE_URL);

        $start = microtime(true);
        [$notice, $armed] = McpLoginFork::arm('w4cdead', ['--code', 'http://127.0.0.1:' . $port . '/cb']);
        self::assertFalse($armed);
        self::assertStringContainsString('did not answer', $notice);
        self::assertLessThan(3.0, microtime(true) - $start, 'the bounded socket deadline answers fast (E646: a connect bound, never an LLM-call timeout)');
    }

    public function testTheDialTargetIsAValidatedLoopbackLiteralNotAPastedHost(): void
    {
        $fork = file_get_contents(\dirname(__DIR__, 2) . '/src/Support/McpLoginFork.php');
        self::assertIsString($fork);
        self::assertSame(1, substr_count($fork, "'tcp://127.0.0.1:'"), 'the only dial string in the fork is the validated loopback literal');
        self::assertSame(1, substr_count($fork, 'stream_socket_client'), 'and exactly one place dials');
    }
}
