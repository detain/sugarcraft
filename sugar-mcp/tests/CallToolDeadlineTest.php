<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\StdioMcpServer;

/**
 * callTool()'s OPT-IN deadline. Unbounded stays the default (E646); a
 * positive `$timeoutSeconds` abandons the wait, tells the server with
 * `notifications/cancelled`, and leaves the connection usable — the late
 * reply is skipped by the strict id match.
 */
final class CallToolDeadlineTest extends TestCase
{
    /** @var list<StdioMcpServer> */
    private array $live = [];

    private ?string $log = null;

    protected function tearDown(): void
    {
        foreach ($this->live as $server) {
            $server->stop();
        }

        $this->live = [];

        if ($this->log !== null) {
            @unlink($this->log);
        }
    }

    private function slowServer(): StdioMcpServer
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'mcp-deadline-');
        $server = new StdioMcpServer('slow', PHP_BINARY, [__DIR__ . '/Fixtures/slow_server.php', $this->log], startTimeoutSeconds: 10.0);
        $this->live[] = $server;
        $server->start();

        return $server;
    }

    public function testAnExpiredCallIsAbandonedCancelledAndTheConnectionStaysUsable(): void
    {
        $server = $this->slowServer();

        $started = microtime(true);
        $result = $server->callTool('sleep', ['seconds' => 2.0], null, 0.5);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(1.8, $elapsed, 'the call must end at its deadline, not when the server answers');
        self::assertStringContainsString('timed out after 0.5s', (string) ($result['error'] ?? ''));

        // The next call queues behind the sleeping server, then must get ITS
        // answer — not the abandoned call's late "slept 2".
        $next = $server->callTool('sleep', ['seconds' => 0.1]);
        self::assertSame('slept 0.1', $next['content'][0]['text'] ?? null);

        $call = null;
        $cancel = null;
        foreach (file((string) $this->log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $message = json_decode($line, true);
            if (($message['method'] ?? null) === 'tools/call' && $call === null) {
                $call = $message;
            }
            if (($message['method'] ?? null) === 'notifications/cancelled') {
                $cancel = $message;
            }
        }

        self::assertNotNull($call, 'fixture: the call reached the server');
        self::assertNotNull($cancel, 'the server was never told the call was abandoned');
        self::assertArrayNotHasKey('id', $cancel, 'a cancellation is a notification');
        self::assertSame($call['id'], $cancel['params']['requestId'] ?? null, 'the notice must name the abandoned request');
    }

    public function testACallThatBeatsItsDeadlineAnswersNormally(): void
    {
        $result = $this->slowServer()->callTool('sleep', ['seconds' => 0.2], null, 5.0);

        self::assertSame('slept 0.2', $result['content'][0]['text'] ?? null);
    }

    /** @return iterable<string, array{?float}> */
    public static function unbounded(): iterable
    {
        yield 'null' => [null];
        yield 'zero' => [0.0];
        yield 'negative' => [-3.0];
        yield 'infinite' => [INF];
        yield 'NaN' => [NAN];
    }

    /** @dataProvider unbounded */
    public function testAnythingButAPositiveFiniteNumberLeavesTheCallUnbounded(?float $timeout): void
    {
        $result = $this->slowServer()->callTool('sleep', ['seconds' => 0.3], null, $timeout);

        self::assertSame('slept 0.3', $result['content'][0]['text'] ?? null);
    }

    public function testTheDeadlineAndTheWaitBeatCompose(): void
    {
        $beats = 0;
        $result = $this->slowServer()->callTool('sleep', ['seconds' => 3.0], static function () use (&$beats): void {
            $beats++;
        }, 1.2);

        self::assertArrayHasKey('error', $result);
        self::assertGreaterThan(0, $beats);
    }
}
