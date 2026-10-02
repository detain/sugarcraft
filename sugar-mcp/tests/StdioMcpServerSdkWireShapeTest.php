<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\McpMessage;
use SugarCraft\Mcp\StdioMcpServer;

/**
 * Audit MCP-1: the stdio client could not talk to the official TypeScript or
 * Python SDK servers. PHP's empty `[]` reached the wire as a JSON array where
 * the schema wants an object (`capabilities`, `params`, `arguments`), the SDKs
 * answered `initialize` with an error the client took for success, tools/list
 * got no reply until the start budget expired, and the `initialized`
 * notification carried the wrong method name.
 *
 * The strict_sdk_server fixture refuses array-shaped objects the way the SDKs
 * were measured to, so every leg here is a real child-process round trip.
 *
 * Harness law inherited from the round-trip suite: never fail() inside a
 * `catch (\RuntimeException)` — capture, exit the try, assert after.
 */
final class StdioMcpServerSdkWireShapeTest extends TestCase
{
    /** Handshake budget for the fixture; the pre-fix tools/list hang burns it whole. */
    private const BUDGET_SECONDS = 3.0;

    /** @var list<StdioMcpServer> */
    private array $live = [];

    private string $notificationLog = '';

    protected function setUp(): void
    {
        $this->notificationLog = (string) tempnam(sys_get_temp_dir(), 'mcp-notify-');
    }

    protected function tearDown(): void
    {
        foreach ($this->live as $server) {
            $server->stop();
        }

        $this->live = [];

        if ($this->notificationLog !== '' && is_file($this->notificationLog)) {
            unlink($this->notificationLog);
        }
    }

    /** @param list<string> $extra */
    private function spawn(array $extra = []): StdioMcpServer
    {
        $server = new StdioMcpServer(
            'strict',
            PHP_BINARY,
            [__DIR__ . '/Fixtures/strict_sdk_server.php', $this->notificationLog, ...$extra],
            startTimeoutSeconds: self::BUDGET_SECONDS,
        );
        $this->live[] = $server;

        return $server;
    }

    /**
     * Pids of still-running children of this process whose cmdline contains
     * $needle (zombies excluded: proc_close reaps them).
     *
     * @return list<int>
     */
    private static function liveChildrenRunning(string $needle): array
    {
        $pids = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $line = @file_get_contents($file);
            if ($line === false || !str_contains($line, $needle)) {
                continue;
            }

            $pid = (int) basename(\dirname($file));
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            if ($stat === false) {
                continue;
            }

            $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
            if ((int) ($fields[1] ?? 0) === getmypid() && ($fields[0] ?? '') !== 'Z') {
                $pids[] = $pid;
            }
        }

        return $pids;
    }

    public function testEmptyParamsGoOnTheWireAsAnObject(): void
    {
        $json = McpMessage::request('1', 'tools/list', [])->toJson();

        self::assertStringContainsString('"params":{}', $json);
        self::assertStringNotContainsString('"params":[]', $json);
        self::assertStringContainsString('"params":{}', McpMessage::notification('x', [])->toJson());
    }

    public function testNonEmptyAndNestedObjectParamsKeepTheirShape(): void
    {
        $json = McpMessage::request('2', 'call', ['name' => 't', 'arguments' => new \stdClass()])->toJson();

        self::assertSame('{"jsonrpc":"2.0","id":"2","method":"call","params":{"name":"t","arguments":{}}}', $json);
        self::assertStringNotContainsString('params', McpMessage::request('3', 'tools/list', null)->toJson());
    }

    public function testAStrictSdkServerStartsListsToolsAndAnswersANoArgumentCall(): void
    {
        $server = $this->spawn();

        $startedAt = microtime(true);
        $server->start();
        $elapsed = microtime(true) - $startedAt;

        // Before the fix tools/list got no reply and start() burned the whole
        // budget; a compliant handshake with a local child is near-instant.
        self::assertLessThan(self::BUDGET_SECONDS - 0.5, $elapsed, 'start() waited out the budget: tools/list went unanswered');
        self::assertTrue($server->isUp());

        $tools = $server->listTools();
        self::assertCount(1, $tools, 'an initialize the SDK refused yields no tools');
        self::assertSame('get-tiny-image', $tools[0]->name);

        self::assertSame(
            ['content' => [['type' => 'text', 'text' => 'tiny']]],
            $server->callTool('get-tiny-image', []),
            'an argument-less call must send "arguments":{} — the SDK rejects []',
        );
    }

    public function testTheInitializedNotificationUsesTheSpecMethodName(): void
    {
        $server = $this->spawn();
        $server->start();
        // tools/list is answered after the notification was read, so the log
        // line is on disk by the time start() returns.
        $server->stop();

        self::assertSame(
            ['notifications/initialized'],
            file($this->notificationLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
    }

    public function testAnInitializeErrorReplyFailsTheStartAndLeavesNoChild(): void
    {
        $server = $this->spawn(['--refuse-init']);

        $caught = null;
        $startedAt = microtime(true);

        try {
            $server->start();
        } catch (\RuntimeException $thrown) {
            $caught = $thrown;
        }

        $elapsed = microtime(true) - $startedAt;

        self::assertNotNull($caught, 'an initialize error reply was accepted as a successful start');
        $message = $caught->getMessage();
        self::assertStringContainsString('Failed to start MCP server: strict', $message);
        self::assertStringContainsString('-32602', $message);
        self::assertStringContainsString('Unsupported protocol version: fixture refusal', $message);
        // The fixture writes stderr before its reply, so the tail is captured.
        self::assertStringContainsString('strict-sdk: refusing this session', $message);
        self::assertLessThan(self::BUDGET_SECONDS - 0.5, $elapsed, 'the refusal must fail fast, not wait out tools/list');

        // No explicit stop() here: start() itself must have reaped the child.
        self::assertFalse($server->isUp(), 'a refused start must leave no child behind');
        self::assertSame([], self::liveChildrenRunning('strict_sdk_server.php'));
        self::assertSame([], file($this->notificationLog, FILE_SKIP_EMPTY_LINES), 'a refused session must not be told it was initialized');
    }
}
