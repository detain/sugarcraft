<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\StdioMcpServer;

/**
 * The cwd argument (C-MCP-1): the child's start directory is the embedder's
 * decision, and null keeps the historic inherited spawn byte-identical.
 * Real children only — getcwd() inside the fixture is the ground truth of
 * where proc_open put the process, not a mock's memory of an argument, and
 * the directories carry this pid plus a random nonce so a child that had
 * NOT been moved could never produce their name by accident.
 *
 * Harness law inherited from the product suite: never fail() inside a
 * `catch (\RuntimeException)` — PHPUnit's AssertionFailedError IS a
 * RuntimeException, and an assertion swallowed by that catch silently greens
 * the test. Capture, exit the try, assert after.
 */
final class StdioMcpServerCwdTest extends TestCase
{
    /** Upper bound any bounded give-up must complete under. */
    private const BOUND_SECONDS = 6.0;

    /** @var list<StdioMcpServer> servers still up when the test ends */
    private array $live = [];

    /** @var list<string> temp dirs created by this test, empty at exit */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->live as $server) {
            $server->stop();
        }

        $this->live = [];

        foreach ($this->dirs as $dir) {
            @rmdir($dir);
        }

        $this->dirs = [];
    }

    private static function cwdFixture(): string
    {
        return __DIR__ . '/Fixtures/cwd_server.php';
    }

    /** A fresh mode-0700 temp directory, removed in tearDown. */
    private function makeDir(): string
    {
        $dir = sys_get_temp_dir() . '/mcp-cwd-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700) || !is_dir($dir)) {
            $this->fail('could not create the spawn-directory fixture root');
        }

        $this->dirs[] = $dir;

        return $dir;
    }

    /** What the live child says its own working directory is. */
    private function where(StdioMcpServer $server): string
    {
        $result = $server->callTool('where', []);

        return (string) ($result['content'][0]['text'] ?? '');
    }

    public function testNullCwdKeepsTheHistoricInheritedDirectory(): void
    {
        $parent = (string) getcwd();
        $server = new StdioMcpServer(
            'inherit',
            PHP_BINARY,
            [self::cwdFixture()],
            startTimeoutSeconds: self::BOUND_SECONDS,
        );
        $this->live[] = $server;
        $server->start();

        self::assertSame($parent, $this->where($server));
    }

    public function testExplicitCwdStartsTheChildSomewhereElse(): void
    {
        $dir = $this->makeDir();
        $parent = (string) getcwd();

        $server = new StdioMcpServer(
            'moved',
            PHP_BINARY,
            [self::cwdFixture()],
            startTimeoutSeconds: self::BOUND_SECONDS,
            cwd: $dir,
        );
        $this->live[] = $server;
        $server->start();

        self::assertSame($dir, $this->where($server));
        self::assertSame($parent, (string) getcwd(), 'the move is the child\'s, never the parent\'s');
    }

    public function testSpawnPlannerAndCwdAreIndependentAxes(): void
    {
        // The product passes BOTH: a containment planner decides the argv and
        // env (candy-code wraps and scrubs through exactly this seam), and
        // C-MCP-1 decides the directory. A planner that returns a pair must
        // not silently drop the cwd on the way through.
        $dir = $this->makeDir();
        $planner = static fn (string $name, array $argv, array $env): array => [$argv, null];

        $server = new StdioMcpServer(
            'planned',
            PHP_BINARY,
            [self::cwdFixture()],
            startTimeoutSeconds: self::BOUND_SECONDS,
            spawnPlanner: $planner,
            cwd: $dir,
        );
        $this->live[] = $server;
        $server->start();

        self::assertSame($dir, $this->where($server));
    }

    public function testUnreachableChildStillFailsLoudlyWithCwdSet(): void
    {
        // A cwd never buys a hang: the child exits before the handshake and
        // the bounded wait answers with the same failure the plain spawn has.
        $server = new StdioMcpServer(
            'dead',
            PHP_BINARY,
            [self::cwdFixture(), '--die'],
            startTimeoutSeconds: self::BOUND_SECONDS,
            cwd: $this->makeDir(),
        );
        $this->live[] = $server;

        $caught = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage();
        }

        self::assertNotNull($caught, 'a child that dies before the handshake must throw');
        self::assertStringContainsString('Failed to start MCP server', (string) $caught);
    }
}
