<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\StdioMcpServer;

/**
 * callTool()'s `$onWait` seam: a deadline-less call stays deadline-less, but
 * the caller hears about the wait while it lasts — at least once per
 * READ_POLL_SECONDS — so an embedder whose watchdog measures silence can keep
 * a long tool call alive without this library inventing a timeout for it.
 */
final class CallToolWaitBeatTest extends TestCase
{
    /** @var list<StdioMcpServer> */
    private array $live = [];

    protected function tearDown(): void
    {
        foreach ($this->live as $server) {
            $server->stop();
        }

        $this->live = [];
    }

    private function slowServer(): StdioMcpServer
    {
        $server = new StdioMcpServer('slow', PHP_BINARY, [__DIR__ . '/Fixtures/slow_server.php'], startTimeoutSeconds: 10.0);
        $this->live[] = $server;
        $server->start();

        return $server;
    }

    public function testASlowCallBeatsWhileItWaitsAndStillAnswers(): void
    {
        $server = $this->slowServer();
        $beats = [];

        $result = $server->callTool('sleep', ['seconds' => 2.5], static function () use (&$beats): void {
            $beats[] = microtime(true);
        });

        self::assertSame('slept 2.5', $result['content'][0]['text'] ?? null);
        self::assertNotEmpty($beats, 'a waiting call must beat');

        // Never more than one poll slice of silence between beats.
        $gaps = [];
        for ($i = 1, $n = count($beats); $i < $n; $i++) {
            $gaps[] = $beats[$i] - $beats[$i - 1];
        }
        self::assertNotEmpty($gaps);
        self::assertLessThan(1.6, max($gaps), 'the beat went silent for longer than a poll slice');
        self::assertGreaterThan(1.5, end($beats) - $beats[0], 'the beats must span the wait, not bunch at its start');
    }

    public function testTheBeatIsScopedToTheCallThatPassedIt(): void
    {
        $server = $this->slowServer();
        $beats = 0;

        $server->callTool('sleep', ['seconds' => 0.2], static function () use (&$beats): void {
            $beats++;
        });
        $afterFirst = $beats;

        $result = $server->callTool('sleep', ['seconds' => 0.2]);

        self::assertSame('slept 0.2', $result['content'][0]['text'] ?? null);
        self::assertSame($afterFirst, $beats, 'a later call without a beat must not fire the earlier caller\'s closure');
    }

    public function testACallWithoutABeatBehavesExactlyAsBefore(): void
    {
        $result = $this->slowServer()->callTool('sleep', ['seconds' => 0.1]);

        self::assertSame('slept 0.1', $result['content'][0]['text'] ?? null);
    }
}
