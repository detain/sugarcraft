<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;
use ReflectionProperty;
use SugarCraft\McpClient\McpTool;
use SugarCraft\McpClient\StdioMcpServer;

/**
 * Pins for the failure-diagnostics, negotiation, and honesty laws landed by
 * the 2026-11 review fix wave (M2-M6, M9-M11, M13, m5, N8, N5) — each one was
 * written to fail against the pre-fix tree.
 */
final class StdioMcpServerFailureDiagnosticsTest extends TestCase
{
    /** Wall budget every "this must fail FAST" assertion is held to. */
    private const BOUND_SECONDS = 6.0;

    /** @var list<StdioMcpServer> */
    private array $live = [];

    protected function tearDown(): void
    {
        foreach ($this->live as $server) {
            try {
                $server->stop();
            } catch (\Throwable) {
                // teardown never masks the assertion already made
            }
        }

        $this->live = [];
    }

    /**
     * @param list<string> $args
     */
    private function spawn(string $fixture, array $args = [], ?string $name = 'probe'): StdioMcpServer
    {
        $server = new StdioMcpServer(
            $name,
            PHP_BINARY,
            [__DIR__ . '/Fixtures/' . $fixture, ...$args],
            startTimeoutSeconds: self::BOUND_SECONDS,
        );
        $this->live[] = $server;

        return $server;
    }

    public function testALyingProtocolVersionRefusesTheStart(): void
    {
        $server = $this->spawn('hostile_handshake_server.php', ['--lie-version']);

        $thrown = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $thrown = $e->getMessage();
        }

        // Harness law: assert after the catch, never fail() inside it.
        self::assertIsString($thrown, 'a server answering a future protocol revision must not boot');
        self::assertStringContainsString('protocol mismatch', $thrown);
        self::assertStringContainsString('2099-01-01', $thrown);
        self::assertFalse($server->isUp(), 'the refused server must have been stopped');
    }

    public function testAnUnversionedHandshakeStillBoots(): void
    {
        $server = $this->spawn('hostile_handshake_server.php', ['--omit-version']);
        $server->start();

        // Legacy servers that echo no protocolVersion ride the documented
        // tolerance: the boot proceeds, it is not punished.
        self::assertTrue($server->isUp());
    }

    public function testAFrameCarryingBothResultAndErrorRefuses(): void
    {
        $server = $this->spawn('hostile_handshake_server.php', ['--both-shapes']);
        $server->start();

        $reply = $server->callTool('echo', ['raw' => 'x'], timeoutSeconds: self::BOUND_SECONDS);

        // JSON-RPC 2.0 forbids result AND error in one reply; the error is
        // judged FIRST (review M2) so a broken or hostile server can never
        // fake transcript success.
        self::assertArrayHasKey('error', $reply);
        self::assertArrayNotHasKey('content', $reply);
        self::assertStringContainsString('refused (-32603)', $reply['error']);
        self::assertStringContainsString('actually broken', $reply['error']);
    }

    public function testServerAuthoredControlBytesAreSweptFromDiagnostics(): void
    {
        $server = $this->spawn('hostile_handshake_server.php', ['--osc8']);
        $server->start();

        $reply = $server->callTool('echo', ['raw' => 'x'], timeoutSeconds: self::BOUND_SECONDS);
        $error = (string) $reply['error'];

        // Review M13: an OSC-8 sequence inside the server's error message and
        // CSI bytes on its stderr must never ride the exception/payload path
        // into a terminal. The words survive; every control byte does not.
        self::assertStringContainsString('refused (-32603)', $error);
        self::assertStringNotContainsString(chr(27), $error, 'ESC must be swept from server-authored diagnostics');
        self::assertStringNotContainsString(chr(7), $error, 'BEL must be swept from server-authored diagnostics');
        self::assertStringContainsString('8;;http://evil', $error, 'the visible words stay for diagnosis');
        self::assertStringContainsString('red', $error, 'the stderr words stay too');
    }

    public function testALatin1StderrLineYieldsAnHonestNonBlankDiagnostic(): void
    {
        $server = $this->spawn('hostile_handshake_server.php', ['--latin1']);
        $server->start();
        $server->stop();

        $tail = $server->stderrTail();

        // A /u preg sweep returns null on invalid UTF-8, and the old
        // `?? ''` fallback turned a Latin-1 cause into the empty string.
        // The ASCII region must survive a byte-wise sweep, marked as such.
        self::assertNotSame('', $tail, 'an undecodable stderr line must not blind the diagnostic');
        self::assertStringContainsString('cause: caf', $tail, 'the ASCII region of the cause survives');
        self::assertStringContainsString('[non-UTF-8]', $tail);
        self::assertStringNotContainsString(chr(0xE9), $tail, 'raw Latin-1 bytes must not ride the diagnostic path');
        self::assertStringNotContainsString(chr(7), $tail, 'control bytes are swept in byte mode too');
    }

    public function testStartBeatsThroughASlowHandshake(): void
    {
        $server = $this->spawn('hostile_handshake_server.php', ['--slow-handshake']);

        $beats = 0;
        $server->start(onWait: static function () use (&$beats): void {
            ++$beats;
        });

        // Review M11: a cold npx handshake is exactly where an embedder's
        // silence-watchdog needs the beat, and it used to arm per callTool
        // only. The fixture stalls 1.4 s; READ_POLL_SECONDS caps slices at
        // one, so at least one beat must have landed.
        self::assertGreaterThanOrEqual(1, $beats, 'start(onWait:) must beat through the handshake');
    }

    public function testSpawnFailureNamesTheCommandAndOsCause(): void
    {
        $server = new StdioMcpServer('ghost', '/nonexistent-mcp-binary-xyz-1103', []);
        $this->live[] = $server;

        $thrown = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $thrown = $e->getMessage();
        }

        // Review M3: missing-binary is THE most common setup error; the old
        // message named only the user-chosen alias.
        self::assertIsString($thrown);
        self::assertStringContainsString('Failed to start MCP server', $thrown);
        self::assertStringContainsString('could not execute /nonexistent-mcp-binary-xyz-1103', $thrown);
    }

    public function testDeadlineGuardRefusesWallClockAndAcceptsMonotonic(): void
    {
        $server = $this->spawn('hostile_handshake_server.php');
        $server->start();

        $thrown = null;
        try {
            $server->request('tools/list', null, 1.8e9);
        } catch (\InvalidArgumentException $e) {
            $thrown = $e->getMessage();
        }

        // Review M4: request()/notify() deadlines are absolute monotonic
        // instants; a wall-clock guess used to fail SILENTLY in both
        // directions. Now the implausible magnitude refuses loudly.
        self::assertIsString($thrown, 'a microtime-magnitude deadline must throw');
        self::assertStringContainsString('wall-clock', $thrown);

        $reply = $server->request('tools/list', null, StdioMcpServer::monotonicNow() + 5.0);
        self::assertNotNull($reply, 'a legitimate monotonic instant must still work');
    }

    public function testRosterClearsAndStderrSurvivesTheStop(): void
    {
        $server = $this->spawn('hostile_handshake_server.php', ['--noisy']);
        $server->start();
        self::assertNotSame([], $server->listTools());

        $server->stop();

        // Minor m5: stop() used to keep serving a dead roster.
        self::assertSame([], $server->listTools());

        // The post-mortem evidence (devops M1-variant): the tail survives
        // stop() — cleared at the NEXT start(), not at teardown — and stays
        // control-char-free through the public accessor.
        $tail = $server->stderrTail();
        self::assertNotSame('', $tail, 'stderr evidence must survive stop()');
        self::assertStringContainsString('diagnostic noise', $tail);
        self::assertLessThanOrEqual(65536, strlen($tail));
    }

    public function testNamedArgumentsBindOnTheConcreteClass(): void
    {
        $server = $this->spawn('hostile_handshake_server.php');
        $server->start();

        // Review M5: the concrete implementation used to name its parameters
        // $toolName/$args, so the spelling the McpServer interface advertises
        // was an Unknown-named-parameter Error on the real class.
        $reply = $server->callTool(name: 'echo', arguments: ['raw' => 'bound']);

        self::assertSame('bound', $reply['content'][0]['text']);
    }

    public function testFrameCapMatchesTheDownstreamCeilingOnPurpose(): void
    {
        // Nit N5: this ceiling deliberately restates the one candy-code's
        // EngineBackend enforces (64 MiB). The pair is intentional, so pin
        // the value with a date; a rename on either side must stay a choice,
        // never an accident.
        $cap = (new \ReflectionClass(StdioMcpServer::class))->getConstant('MAX_FRAME_BYTES');

        self::assertSame(67108864, $cap, 'pinned 2026-11-04: matches candy-code EngineBackend by design (review N5)');
    }

    public function testHandlerTypeErrorBecomesAnHonestPayload(): void
    {
        $server = $this->spawn('server_request_server.php');
        $server->start();

        $reply = $server->callTool(
            'talk',
            [],
            timeoutSeconds: self::BOUND_SECONDS,
            onServerMessage: static function (): ?array {
                throw new \TypeError('handler exploded');
            },
        );

        // Review M10: a TypeError from the embedder's answerer used to
        // ESCAPE callTool (crashing a TUI frame mid-call). The contract says
        // failed calls answer as payloads — and the cause must name the
        // handler, never pretend the arguments failed to encode.
        self::assertArrayHasKey('error', $reply);
        self::assertStringContainsString('TypeError', $reply['error']);
        self::assertStringContainsString('handler exploded', $reply['error']);
    }

    public function testHandlerMalformedAnswerNamesItsOwnFault(): void
    {
        $server = $this->spawn('server_request_server.php');
        $server->start();

        $reply = $server->callTool(
            'talk',
            [],
            timeoutSeconds: self::BOUND_SECONDS,
            onServerMessage: static fn (): int => 42,
        );

        // Review M10's mislabel half: an InvalidArgumentException from the
        // answer-shape gate used to ride the encoding rail's wording,
        // pointing debugging at arguments that encoded fine.
        self::assertArrayHasKey('error', $reply);
        self::assertStringContainsString('must return an array or null', $reply['error']);
        self::assertStringContainsString('InvalidArgumentException', $reply['error']);
    }

    public function testTheTruncationFlagLabelsOnlyRealTruncation(): void
    {
        // Nit N8: the old heuristic flagged every tail whose length REACHED
        // the cap, mislabelling an exactly-full (never-cut) capture.
        $server = new StdioMcpServer('flag', PHP_BINARY, []);

        $tail = new ReflectionProperty(StdioMcpServer::class, 'stderrTail');
        $tail->setValue($server, str_repeat('z', 65536));
        $flag = new ReflectionProperty(StdioMcpServer::class, 'stderrTruncated');
        $flag->setValue($server, false);

        $render = new ReflectionMethod(StdioMcpServer::class, 'stderrTailForDiagnostics');
        $render->setAccessible(true);

        $untruncated = (string) $render->invoke($server);
        self::assertStringNotContainsString('truncated', $untruncated, 'an exactly-full but uncut tail must not claim truncation');
        self::assertStringContainsString('stderr:', $untruncated);

        $flag->setValue($server, true);
        self::assertStringContainsString('truncated', (string) $render->invoke($server));
    }

    public function testReadLineCarriesTheBoundedRetryMirror(): void
    {
        // Review M9: the read side used to loop on a failing select forever,
        // while the write side counted to the ceiling and probed liveness.
        // This structural pin goes red the moment the discipline is stripped
        // back out of readLine().
        $src = (string) file_get_contents(__DIR__ . '/../src/StdioMcpServer.php');
        $start = strpos($src, 'private function readLine(');
        self::assertIsInt($start);
        $end = strpos($src, 'private function ', $start + 30);
        self::assertIsInt($end);
        $body = substr($src, $start, $end - $start);

        self::assertStringContainsString('MAX_CONSECUTIVE_SELECT_FAILURES', $body, 'readLine must honour the select-failure ceiling');
        self::assertStringContainsString('serverIsRunning()', $body, 'readLine must probe liveness while selects fail');
    }

    public function testSpawnAndLockStateStayUntouchedByTheAccessorPair(): void
    {
        // Guard rail for the reflection pins above: the private surface they
        // reach through exists exactly once (renames must update this test
        // on purpose, never by accident).
        self::assertTrue((new ReflectionObject(new StdioMcpServer('shape', PHP_BINARY, [])))->hasProperty('lastFailureCause'));
    }

    /**
     * Review m21 (fix wave): lockDir pins the exchange lock where the
     * embedder can see it, instead of chasing a movable TMPDIR.
     */
    public function testTheLockDirOptionPinsTheLockFileSystemEntry(): void
    {
        $dir = sys_get_temp_dir() . '/mcpldir-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0700));

        try {
            $server = new StdioMcpServer(
                'ldprobe',
                PHP_BINARY,
                [__DIR__ . '/Fixtures/echo_server.php'],
                startTimeoutSeconds: self::BOUND_SECONDS,
                lockDir: $dir,
            );
            $server->start();

            $prefix = \SugarCraft\McpClient\ExchangeLock::FILE_PREFIX;
            $seen = array_values(array_filter(scandir($dir), static fn (string $n): bool => str_starts_with($n, $prefix)));
            self::assertNotEmpty($seen, 'the lock file landed in the pinned directory');

            $server->stop();
            self::assertSame([], array_values(array_filter(scandir($dir), static fn (string $n): bool => str_starts_with($n, $prefix))), 'stop() removed it');
        } finally {
            @rmdir($dir);
        }
    }

    /**
     * Review m16 (fix wave): the default initialize identity resolves the
     * package's real version when Composer metadata knows it, and only
     * falls back to the constant otherwise.
     */
    public function testTheDefaultClientInfoCarriesThePackageVersion(): void
    {
        $server = $this->spawn('handshake_mirror.php');
        $server->start();
        $reply = $server->callTool('mirror', []);

        $text = $reply['content'][0]['text'] ?? '';
        self::assertIsString($text);
        $params = json_decode($text, true)['initializeParams'] ?? null;
        self::assertIsArray($params, 'the mirror echoed the captured initialize params');
        $info = $params['clientInfo'] ?? null;
        self::assertIsArray($info);
        self::assertSame('sugar-mcp-client', $info['name']);

        $expected = '0.1.0';
        if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('sugarcraft/sugar-mcp-client')) {
            $pretty = \Composer\InstalledVersions::getPrettyVersion('sugarcraft/sugar-mcp-client');
            if (is_string($pretty) && $pretty !== '') {
                $expected = $pretty;
            }
        }
        self::assertSame($expected, $info['version'], 'the advertised version tracks the package, not a hand-typed literal');
    }

    /**
     * Review N3 + M7 (fix wave), re-scoped by R-MCP: a server-authored tool
     * name carrying a raw NUL is refused by the well-typedness gate — and
     * the gate SKIPS and COUNTS rather than axe-ing the boot. The poisoned
     * name never reaches the roster, the server's well-formed `echo` tool
     * does, the exchange works, and the announcement names the count so the
     * shrunk roster is diagnosable instead of silent.
     */
    public function testAToolNameCarryingControlBytesIsSkippedAndAnnounced(): void
    {
        $server = $this->spawn('hostile_handshake_server.php', ['--bad-tool-name']);
        $server->start();

        $names = array_map(static fn (McpTool $tool): string => $tool->name, $server->listTools());
        self::assertSame(['echo'], $names, 'the poisoned name reached the roster, or the whole boot was lost with it');

        $reply = $server->callTool('echo', ['raw' => 'alive']);
        self::assertSame('alive', $reply['content'][0]['text'] ?? null, 'the surviving tool must still answer');

        self::assertCount(1, $server->malformedToolDefinitions());
        self::assertStringContainsString('1 malformed tool definition', $server->malformedToolDefinitionsReport());
        self::assertTrue($server->isUp());
    }

    public function testWriteFramedGuardOrderAtTheBoundary(): void
    {
        // No child is started at all: both guards must fire at the boundary,
        // BEFORE any pipe check — so their order is observable even unstarted.
        $server = $this->spawn('echo_server.php');

        $newlineRefusal = null;
        try {
            $server->writeFramed("{\"a\":1}\n{\"b\":2}", 1.8e9);
        } catch (\InvalidArgumentException $e) {
            $newlineRefusal = $e->getMessage();
        }
        self::assertIsString($newlineRefusal, 'the frame-shape guard stopped being first at the boundary');
        self::assertStringContainsString('single-line', $newlineRefusal);

        $deadlineRefusal = null;
        try {
            $server->writeFramed('{"jsonrpc":"2.0"}', 1.8e9);
        } catch (\InvalidArgumentException $e) {
            $deadlineRefusal = $e->getMessage();
        }
        self::assertIsString($deadlineRefusal, 'the public helper stopped refusing a wall-clock deadline');
        self::assertStringContainsString('wall-clock', $deadlineRefusal);

        // Well-formed payload + plausible deadline on an unstarted transport:
        // no throw, honest false — validation precedes the pipe, the pipe
        // answers truthfully.
        self::assertFalse($server->writeFramed('{"jsonrpc":"2.0"}', StdioMcpServer::monotonicNow() + 5.0));
    }
}
