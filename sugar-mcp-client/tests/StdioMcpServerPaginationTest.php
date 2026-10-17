<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\StdioMcpServer;

/**
 * The tools/list cursor walk (ISS-093(b)): a server that paginates used to
 * have its FIRST page cached as the entire roster — tools silently missing
 * from every later dispatch. start() now follows nextCursor to the end,
 * refuses a lying (repeating) cursor, refuses a roster known to be cut at
 * the page ceiling, and gates a mid-walk failure as fatally as a front one.
 *
 * Harness law (see StdioMcpServerFailureGatesTest): never assert inside a
 * `catch (\RuntimeException)` — capture, leave the try, assert after.
 */
final class StdioMcpServerPaginationTest extends TestCase
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

    /** @param list<string> $args */
    private function spawn(string $fixture, array $args = []): StdioMcpServer
    {
        $server = new StdioMcpServer(
            'probe',
            PHP_BINARY,
            [__DIR__ . '/Fixtures/' . $fixture, ...$args],
        );
        $this->live[] = $server;

        return $server;
    }

    private static function startFailure(StdioMcpServer $server): ?string
    {
        try {
            $server->start();
        } catch (\RuntimeException $failure) {
            return $failure->getMessage();
        }

        return null;
    }

    public function testTheCursorWalkCachesTheWholeRosterNotJustTheFirstPage(): void
    {
        $server = $this->spawn('paging_tools_server.php');

        $message = self::startFailure($server);
        self::assertNull($message, 'a two-page roster must start clean');

        $names = array_map(
            static fn ($tool): string => $tool->name,
            $server->listTools(),
        );
        self::assertSame(
            ['alpha', 'beta', 'tool_page_2'],
            $names,
            'ISS-093(b): the cursor was followed — the second page is in the cache',
        );
    }

    public function testARepeatedCursorIsRefusedRatherThanLooped(): void
    {
        $server = $this->spawn('paging_tools_server.php', ['--repeat-cursor']);

        $message = self::startFailure($server);

        self::assertNotNull($message, 'the walk looped a lying cursor instead of refusing it');
        self::assertStringContainsString('tools/list repeated cursor samecursor at page 3', $message);
        self::assertStringContainsString('refusing to walk a pagination loop', $message);
    }

    public function testAFreshCursorForeverIsRefusedAtThePageCeiling(): void
    {
        $server = $this->spawn('paging_tools_server.php', ['--endless']);

        $message = self::startFailure($server);

        self::assertNotNull($message, 'the walk walked a truncated roster into the cache');
        self::assertStringContainsString('still had a nextCursor after 32 pages', $message);
        self::assertStringContainsString('refusing to cache a truncated roster', $message);
    }

    public function testAMidWalkFailureGatesLikeAFrontOneAndNamesItsPage(): void
    {
        $server = $this->spawn('paging_tools_server.php', ['--page2-error']);

        $message = self::startFailure($server);

        self::assertNotNull($message, 'an error reply on page 2 was cached past as a whole roster');
        self::assertStringContainsString('Failed to start MCP server: probe', $message);
        self::assertStringContainsString('paging is not a service we offer', $message, 'the server\'s own diagnosis survived');
        self::assertStringContainsString('(page 2)', $message, 'the refusal says which leg broke');
        self::assertStringContainsString('paging-fixture: booted', $message, 'the stderr tail rode the mid-walk refusal too');
    }
}
