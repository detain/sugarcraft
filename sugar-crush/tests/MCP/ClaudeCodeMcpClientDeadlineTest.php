<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\MCP\ClaudeCodeMcpServer;

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
