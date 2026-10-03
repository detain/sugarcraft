<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\MCP\ClaudeCodeMcpServer;
use SugarCraft\Mcp\ExchangeLock;

/**
 * Audit MCP-3: {@see ClaudeCodeMcpClient} waited for every response with a
 * counted poll — 100 attempts, 10 ms apart, about one second in all — so any
 * `claude-mcp` tool that worked longer (Bash, Grep, Task) threw
 * "No response received for request N" with its answer still on the way, and a
 * server slower than a second to boot failed `tools/list` and was skipped. The
 * handshake sent `initialize` as a NOTIFICATION, with server-side capability
 * fields, and never sent `notifications/initialized`.
 *
 * Hermetic: the server is PHP_BINARY running {@see FIXTURE}, which logs every
 * line it receives, refuses an id-less `initialize` the way a strict server
 * must (a notification cannot be answered) unless told to be `lenient`, and can
 * be told to boot slowly, list slowly, work slowly, stay silent, refuse, or exit
 * mid-call.
 */
final class ClaudeCodeMcpClientDeadlineTest extends TestCase
{
    /** Longer than the old ~1s poll by a clear margin, short enough for the suite. */
    private const SLOW_CALL_MS = 2000;

    /** Each start-time leg on its own outlasts the old poll. */
    private const SLOW_BOOT_MS = 1200;
    private const SLOW_LIST_MS = 1200;

    /**
     * Arguments: log file, boot delay ms, first-tools/list delay ms, mode.
     *
     * STDIN IS READ ONE BYTE AT A TIME, UNBUFFERED, so the `shape` mode's
     * look-ahead is honest: with PHP's read buffer a second line could already
     * sit in user space where `stream_select()` cannot see it, and a client
     * that announced `notifications/initialized` before the answer would pass.
     */
    private const FIXTURE = <<<'PHP'
        <?php
        [, $log, $bootMs, $listMs, $mode] = $argv + [5 => ''];
        $note = static function (string $what) use ($log): void {
            file_put_contents($log, $what . "\n", FILE_APPEND);
        };
        $send = static function (array $message): void {
            fwrite(STDOUT, json_encode($message) . "\n");
            fflush(STDOUT);
        };
        $nextLine = static function (): ?string {
            $line = '';
            while (true) {
                $byte = fread(STDIN, 1);
                if ($byte === false || $byte === '') {
                    if (feof(STDIN)) {
                        return $line === '' ? null : $line;
                    }
                    continue;
                }
                if ($byte === "\n") {
                    return $line;
                }
                $line .= $byte;
            }
        };
        stream_set_read_buffer(STDIN, 0);
        $note('pid ' . getmypid());
        usleep((int) $bootMs * 1000);
        $initialized = false;
        $lists = 0;
        while (($line = $nextLine()) !== null) {
            $note('recv ' . $line);
            $msg = json_decode($line, true);
            if (!is_array($msg)) {
                continue;
            }
            $method = $msg['method'] ?? null;
            $hasId = array_key_exists('id', $msg);
            if ($method === 'initialize') {
                if (!$hasId) {
                    // `lenient` is the TS SDK's posture, which never enforced
                    // initialization: it lets the slow-call rows fail for the
                    // reason they are about, not for the handshake.
                    if ($mode === 'lenient') {
                        $initialized = true;
                        continue;
                    }
                    $note('REFUSED initialize without an id');
                    continue;
                }
                if ($mode === 'silent') {
                    continue;
                }
                if ($mode === 'refuse') {
                    $send(['jsonrpc' => '2.0', 'id' => $msg['id'], 'error' => ['code' => -32600, 'message' => 'go away']]);
                    continue;
                }
                if ($mode === 'shape') {
                    // A client that does not wait for this answer has already
                    // written its next line by the time the delay is over.
                    usleep(300000);
                    $r = [STDIN];
                    $w = $e = [];
                    if (stream_select($r, $w, $e, 0, 0) > 0) {
                        $note('EARLY a line arrived before the initialize result');
                    }
                }
                $send(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => new stdClass()],
                    'serverInfo' => ['name' => 'deadline-fixture', 'version' => '0'],
                ]]);
                $initialized = true;
                $note('sent initialize result');
                continue;
            }
            if (!$initialized) {
                $note('EARLY ' . (string) $method . ' before initialize completed');
                continue;
            }
            if (!$hasId) {
                continue;
            }
            if ($method === 'tools/list') {
                if ($mode === 'listrefuse') {
                    $send(['jsonrpc' => '2.0', 'id' => $msg['id'], 'error' => ['code' => -32601, 'message' => 'tools are session-gated']]);
                    continue;
                }
                $lists++;
                if ($lists === 1) {
                    usleep((int) $listMs * 1000);
                }
                $send(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => ['tools' => [
                    ['name' => $lists === 1 ? 'first' : 'again', 'description' => 'd', 'inputSchema' => ['type' => 'object']],
                ]]]);
            } elseif ($method === 'tools/call') {
                $name = (string) ($msg['params']['name'] ?? '');
                if ($name === 'exit') {
                    exit(0);
                }
                if ($name === 'orphan-chatty' || $name === 'orphan-deaf') {
                    // The direct child dies while a helper it forked keeps the
                    // inherited pipes open: stdout never reaches EOF, stdin is
                    // never read, and the chatty one logs to stderr faster than
                    // any read poll.
                    $helper = pcntl_fork();
                    if ($helper === 0) {
                        $until = microtime(true) + 20.0;
                        while (microtime(true) < $until) {
                            if ($name === 'orphan-chatty') {
                                fwrite(STDERR, "helper still logging\n");
                            }
                            usleep(20000);
                        }
                        exit(0);
                    }
                    $note('helper ' . $helper);
                    exit(0);
                }
                if ($name === 'slow') {
                    usleep((int) ($msg['params']['arguments']['ms'] ?? 0) * 1000);
                }
                $send(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => ['content' => [
                    ['type' => 'text', 'text' => 'done:' . $name],
                ]]]);
            }
        }
        PHP;

    private string $workDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/cc_deadline_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0o700, true);
        file_put_contents($this->script(), self::FIXTURE);
    }

    protected function tearDown(): void
    {
        // A helper the fixture forked outlives its parent by design and is in
        // no process group disconnect() still signals once the leader is gone.
        if (\function_exists('posix_kill')) {
            foreach (preg_grep('/^helper \d+$/', $this->log()) ?: [] as $line) {
                posix_kill((int) substr($line, 7), 9);
            }
        }

        foreach (['server.php', 'wire.log'] as $file) {
            @unlink($this->workDir . '/' . $file);
        }
        @rmdir($this->workDir);

        parent::tearDown();
    }

    /**
     * THE HEADLINE. A tool call that works for twice the old budget returns its
     * answer, through the same adapter the bridge calls.
     */
    public function testAToolCallThatOutlastsTheOldPollReturnsItsResult(): void
    {
        $server = $this->grantedServer(0, 0, 'lenient');
        $server->start();

        try {
            $started = hrtime(true);
            $result = $server->callTool('slow', ['ms' => self::SLOW_CALL_MS]);
            $elapsed = (hrtime(true) - $started) / 1e9;

            self::assertSame('done:slow', $result['content'][0]['text'] ?? null, json_encode($result) ?: '');
            self::assertGreaterThanOrEqual(
                (self::SLOW_CALL_MS / 1000) - 0.1,
                $elapsed,
                'the call returned before the fixture finished its work, so this row did not measure a long call',
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * A server that takes longer than the old poll to boot, and longer again to
     * list its tools, still starts with its tools cached — it used to fail
     * `tools/list` and be skipped by the launch without a word.
     */
    public function testASlowBootingServerStillStartsAndListsItsTools(): void
    {
        $server = $this->grantedServer(self::SLOW_BOOT_MS, self::SLOW_LIST_MS, 'lenient');

        try {
            $server->start();

            self::assertTrue($server->isUp());
            $tools = $server->listTools();
            self::assertCount(1, $tools);
            self::assertSame('first', $tools[0]->name);
        } finally {
            $server->stop();
        }
    }

    /**
     * THE HANDSHAKE SHAPE, as the server saw it: `initialize` is a request with
     * an id and the spec's params (capabilities an OBJECT, clientInfo named),
     * the client waited for its result, and `notifications/initialized` — an
     * id-less notification — came next, before any other request.
     */
    public function testInitializeIsARequestAnsweredBeforeTheInitializedNotification(): void
    {
        $client = $this->client('shape');

        try {
            $handshake = $client->connect();
            self::assertCount(1, $handshake, 'connect() hands back the initialize result it waited for');
            self::assertSame('deadline-fixture', $handshake[0]->result['serverInfo']['name'] ?? null);

            $client->listTools();
        } finally {
            $client->disconnect();
        }

        $log = $this->log();
        self::assertSame([], array_values(preg_grep('/^(EARLY|REFUSED)/', $log) ?: []), implode("\n", $log));

        $received = $this->received($log);
        self::assertGreaterThanOrEqual(3, \count($received), implode("\n", $log));

        [$initialize, $initialized, $list] = $received;

        self::assertSame('initialize', $initialize->method ?? null);
        self::assertTrue(property_exists($initialize, 'id'), 'initialize went out without an id, so nothing could answer it');
        self::assertIsString($initialize->id);
        self::assertSame('2024-11-05', $initialize->params->protocolVersion ?? null);
        self::assertInstanceOf(
            \stdClass::class,
            $initialize->params->capabilities ?? null,
            'capabilities must reach the wire as a JSON object',
        );
        self::assertSame([], (array) $initialize->params->capabilities, 'the client claims no capabilities it does not implement');
        self::assertSame('sugar-crush', $initialize->params->clientInfo->name ?? null);

        self::assertSame('notifications/initialized', $initialized->method ?? null);
        self::assertFalse(property_exists($initialized, 'id'), 'notifications/initialized is a notification');

        self::assertSame('tools/list', $list->method ?? null);
        self::assertNotSame($initialize->id, $list->id ?? null);

        $resultAt = array_search('sent initialize result', $log, true);
        $notifiedAt = null;
        foreach ($log as $index => $entry) {
            if (str_starts_with($entry, 'recv ') && str_contains($entry, 'notifications\/initialized')) {
                $notifiedAt = $index;
                break;
            }
        }
        self::assertIsInt($resultAt);
        self::assertIsInt($notifiedAt, implode("\n", $log));
        self::assertGreaterThan($resultAt, $notifiedAt, implode("\n", $log));
    }

    /**
     * The handshake budget still ends a request the server never answers — and
     * the LATE reply to that request, when it does come, is not handed to the
     * next call. The next call is a tool call, which has no deadline at all, so
     * there is no race between the two replies for the clock to decide.
     */
    public function testALateReplyToAnAbandonedRequestIsNotTakenForTheNextOne(): void
    {
        $client = $this->client('', listMs: 900, handshakeSeconds: 0.5);

        try {
            $client->connect();

            $caught = null;
            try {
                $client->listTools();
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            self::assertNotNull($caught, 'a tools/list answered after the budget must fail on the budget');
            self::assertStringContainsString('No response received for tools/list request', $caught->getMessage());
            self::assertStringContainsString('budget', $caught->getMessage());

            $reply = $client->callTool('fast');
            self::assertSame(
                'done:fast',
                $reply->result['content'][0]['text'] ?? null,
                'the call was answered with the abandoned request\'s late reply: ' . json_encode($reply->result),
            );
        } finally {
            $client->disconnect();
        }
    }

    /** A server that never answers `initialize` fails `connect()` on the budget, and is reaped. */
    public function testAnUnansweredHandshakeFailsOnTheBudgetAndReapsTheChild(): void
    {
        $client = $this->client('silent', handshakeSeconds: 0.5);

        $caught = null;
        $started = hrtime(true);
        try {
            $client->connect();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertNotNull($caught, 'a server that never answered initialize must not be connected');
        self::assertStringContainsString('No response received for initialize request', $caught->getMessage());
        self::assertGreaterThanOrEqual(0.45, $elapsed, 'connect() gave up before its budget');
        self::assertLessThan(5.0, $elapsed, 'connect() outlived its budget by far');
        self::assertFalse($client->isConnected());
        $this->assertTheFixtureIsGone();
    }

    /** An error answer to `initialize` is a refusal, not a start. */
    public function testAnInitializeErrorIsARefusalAndReapsTheChild(): void
    {
        $client = $this->client('refuse');

        $caught = null;
        try {
            $client->connect();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'a server that refused initialize must not be connected');
        self::assertStringContainsString('refused initialize', $caught->getMessage());
        self::assertStringContainsString('go away', $caught->getMessage());
        self::assertFalse($client->isConnected());
        $this->assertTheFixtureIsGone();
    }

    /**
     * No deadline is not "wait forever": a server that dies mid-call ends the
     * wait at its EOF, at once, rather than after any budget.
     */
    public function testAServerThatExitsMidCallFailsTheCallAtOnce(): void
    {
        $client = $this->client('');

        try {
            $client->connect();

            $caught = null;
            $started = hrtime(true);
            try {
                $client->callTool('exit');
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            $elapsed = (hrtime(true) - $started) / 1e9;

            self::assertNotNull($caught, 'a call whose server exited must fail');
            self::assertStringContainsString('closed its stdout', $caught->getMessage());
            self::assertLessThan(3.0, $elapsed, 'the dead server was waited on rather than noticed');
        } finally {
            $client->disconnect();
        }
    }

    /**
     * The start-time `tools/list` is gated like `initialize`: an ERROR answer
     * (a session- or capability-gated server answers -32601 here) is not "up,
     * 0 tools" — that shape looks connected, exposes nothing, and throws the
     * server's own diagnosis away. Same gate as sugar-mcp StdioMcpServer::start().
     */
    public function testAToolsListErrorIsAStartFailureAndReapsTheChild(): void
    {
        $server = $this->grantedServer(0, 0, 'listrefuse');

        $caught = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $caught = $e;
        } finally {
            $up = $server->isUp();
            $server->stop();
        }

        self::assertNotNull($caught, 'a server that refused tools/list started as "up, 0 tools"');
        self::assertStringContainsString('tools/list refused (-32601)', $caught->getMessage());
        self::assertStringContainsString('tools are session-gated', $caught->getMessage());
        self::assertFalse($up, 'the refused server was left running');
        $this->assertTheFixtureIsGone();
    }

    /**
     * A tool call has no deadline, so liveness is its only bound — and pipe EOF
     * is not liveness: a helper the server forked keeps stdout open after the
     * server is gone. With that helper logging to stderr more often than one
     * read poll, the select never went idle, the idle-only liveness check never
     * ran, and the call waited out the helper (20s here, forever in general).
     */
    public function testADeadServerIsNoticedWhileAForkedHelperKeepsStderrBusy(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('the fixture forks its helper with ext-pcntl');
        }

        $client = $this->client('');

        try {
            $client->connect();

            $caught = null;
            $started = hrtime(true);
            try {
                $client->callTool('orphan-chatty');
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            $elapsed = (hrtime(true) - $started) / 1e9;

            self::assertNotNull($caught, 'a call whose server died must fail');
            self::assertStringContainsString('the server exited', $caught->getMessage());
            self::assertLessThan(5.0, $elapsed, 'the dead server was waited on behind its chatty helper');
            self::assertStringContainsString('helper still logging', $client->stderrTail(), 'the helper never logged, so this row did not measure a busy stderr');
        } finally {
            $client->disconnect();
        }
    }

    /**
     * The write side of the same law: a request larger than the pipe buffer to
     * a server that is gone, while a helper holds its stdin without reading,
     * fails on the server's death rather than on the 15s write-idle bound.
     */
    public function testAWriteToADeadServerFailsOnLivenessNotOnTheIdleBound(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('the fixture forks its helper with ext-pcntl');
        }

        $client = $this->client('');

        try {
            $client->connect();

            $died = null;
            try {
                $client->callTool('orphan-deaf');
            } catch (\RuntimeException $e) {
                $died = $e;
            }
            self::assertNotNull($died, 'a call whose server died must fail');
            self::assertStringContainsString('the server exited', $died->getMessage());

            $caught = null;
            $started = hrtime(true);
            try {
                $client->callTool('big', ['blob' => str_repeat('x', 200000)]);
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            $elapsed = (hrtime(true) - $started) / 1e9;

            self::assertNotNull($caught, 'a write the dead server never read must fail');
            self::assertStringContainsString('Failed to write to MCP process stdin', $caught->getMessage());
            self::assertLessThan(5.0, $elapsed, 'the write waited out its idle bound instead of noticing the dead server');
        } finally {
            $client->disconnect();
        }
    }

    /**
     * A FORKED caller cannot ask proc_get_status() (the server is not its
     * child), and signal 0 answers "alive" for an unreaped ZOMBIE — which is
     * what a server that died while the owner is busy elsewhere stays. The
     * probe therefore reads /proc: Z/X is dead, and a start time that no
     * longer matches the one recorded at connect() is a reused pid.
     */
    public function testAForkedCallerSeesAZombieOrAReusedPidAsDead(): void
    {
        if (!\function_exists('posix_kill') || !is_dir('/proc/self')) {
            self::markTestSkipped('needs ext-posix and procfs');
        }

        $client = $this->client('');
        $owner = new \ReflectionProperty(ClaudeCodeMcpClient::class, 'ownerPid');
        $realOwner = 0;

        try {
            $client->connect();
            $realOwner = $owner->getValue($client);
            $serverPid = (new \ReflectionProperty(ClaudeCodeMcpClient::class, 'serverPid'))->getValue($client);
            $ticks = new \ReflectionProperty(ClaudeCodeMcpClient::class, 'serverStartTicks');
            $realTicks = $ticks->getValue($client);
            self::assertGreaterThan(0, $realTicks, 'connect() did not record the server\'s start time');

            // An owner pid that is not ours is exactly what a pcntl_fork()ed caller sees.
            $owner->setValue($client, $realOwner + 1);
            self::assertTrue($client->isUp(), 'a live server read as dead from a forked caller');

            $ticks->setValue($client, $realTicks + 1);
            self::assertFalse($client->isUp(), 'a pid now held by a process started at another time read as the server');
            $ticks->setValue($client, $realTicks);

            posix_kill($serverPid, 9);
            $until = microtime(true) + 5.0;
            while (microtime(true) < $until && !str_contains((string) @file_get_contents("/proc/{$serverPid}/stat"), ') Z ')) {
                usleep(5000);
            }
            self::assertStringContainsString(') Z ', (string) @file_get_contents("/proc/{$serverPid}/stat"), 'the killed server never became a zombie');

            self::assertFalse($client->isUp(), 'an unreaped dead server read as alive from a forked caller');
        } finally {
            if ($realOwner !== 0) {
                $owner->setValue($client, $realOwner);
            }
            $client->disconnect();
        }
    }

    /**
     * ExchangeLock::markPhase() now reports a write that did not land (a full
     * or read-only temp filesystem). An exchange that cannot record its W
     * marker runs unprotected — a holder killed mid-line would leave the next
     * one trusting a stream it half-wrote — so it is a FAILED exchange, the
     * same rule sugar-mcp StdioMcpServer::exchange() applies, and nothing goes
     * on the wire.
     */
    public function testAPhaseMarkerThatCannotBeRecordedFailsTheExchange(): void
    {
        $client = $this->client('');

        try {
            $client->connect();

            $lock = (new \ReflectionProperty(ClaudeCodeMcpClient::class, 'lock'))->getValue($client);
            self::assertInstanceOf(ExchangeLock::class, $lock);

            // A read-only handle on the real lock file: flock still works,
            // every state write fails.
            $readOnly = fopen($lock->path, 'r');
            self::assertIsResource($readOnly);
            (new \ReflectionProperty(ExchangeLock::class, 'handle'))->setValue($lock, $readOnly);
            (new \ReflectionProperty(ExchangeLock::class, 'handlePid'))->setValue($lock, (int) getmypid());

            $caught = null;
            try {
                $client->callTool('unrecorded');
            } catch (\RuntimeException $e) {
                $caught = $e;
            }

            self::assertNotNull($caught, 'an exchange ran with a W marker it could not record');
            self::assertStringContainsString('could not be recorded', $caught->getMessage());
            self::assertStringNotContainsString('unrecorded', implode("\n", $this->log()), 'the request went out unprotected');

            // A writable handle again: the refused exchange cost nothing else.
            $lock->close();
            $reply = $client->callTool('fast');
            self::assertSame('done:fast', $reply->result['content'][0]['text'] ?? null);
        } finally {
            $client->disconnect();
        }
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function script(): string
    {
        return $this->workDir . '/server.php';
    }

    private function logFile(): string
    {
        return $this->workDir . '/wire.log';
    }

    private function client(string $mode, int $bootMs = 0, int $listMs = 0, ?float $handshakeSeconds = null): ClaudeCodeMcpClient
    {
        $args = [$this->script(), $this->logFile(), (string) $bootMs, (string) $listMs, $mode];

        // The budget is named only when a row needs a short one, so every
        // other row constructs the client exactly as the adapter does.
        return $handshakeSeconds === null
            ? new ClaudeCodeMcpClient(command: PHP_BINARY, args: $args)
            : new ClaudeCodeMcpClient(command: PHP_BINARY, args: $args, handshakeTimeoutSeconds: $handshakeSeconds);
    }

    private function grantedServer(int $bootMs, int $listMs, string $mode): ClaudeCodeMcpServer
    {
        return ClaudeCodeMcpServer::fromGrant('deadline', ['type' => ClaudeCodeMcpServer::TYPE], [
            'binary' => PHP_BINARY,
            'args' => [$this->script(), $this->logFile(), (string) $bootMs, (string) $listMs, $mode],
            'env' => null,
        ]);
    }

    /** @return list<string> */
    private function log(): array
    {
        $raw = @file_get_contents($this->logFile());

        return $raw === false ? [] : array_values(array_filter(explode("\n", $raw), static fn (string $l): bool => $l !== ''));
    }

    /**
     * Every line the fixture received, decoded WITHOUT assoc so `{}` and `[]`
     * stay distinct.
     *
     * @param list<string> $log
     * @return list<\stdClass>
     */
    private function received(array $log): array
    {
        $messages = [];
        foreach ($log as $entry) {
            if (str_starts_with($entry, 'recv ')) {
                $decoded = json_decode(substr($entry, 5));
                if ($decoded instanceof \stdClass) {
                    $messages[] = $decoded;
                }
            }
        }

        return $messages;
    }

    private function assertTheFixtureIsGone(): void
    {
        if (!\function_exists('posix_kill')) {
            return;
        }

        $pidLine = array_values(preg_grep('/^pid \d+$/', $this->log()) ?: [])[0] ?? '';
        self::assertNotSame('', $pidLine, 'the fixture never reported its pid');
        $pid = (int) substr($pidLine, 4);

        // The failure path reaps through disconnect(), which waits for the
        // child, so there is no zombie to tell apart from a live process.
        self::assertFalse(posix_kill($pid, 0), "the fixture (pid {$pid}) outlived the failed connect()");
    }
}
