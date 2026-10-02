<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * Pins the "My MCP tools are missing" advice in docs/TROUBLESHOOTING.md to what
 * {@see \SugarCraft\Crush\MCP\McpClient::startServers()} actually does (audit
 * DOC-1 item 1).
 *
 * The page said an unknown `type` made startup ordering-dependent — servers
 * listed after the bad entry "were never reached" — and told the operator to
 * move it. That was true once. startServers() now attempts every entry,
 * collects each build failure and throws ONE report after the loop, which
 * tests/MCP/McpClientServerIsolationTest.php proves behaviourally. Advice to
 * reorder the file sends the operator after a cause that no longer exists, so
 * the stale claim must not come back.
 *
 * docs/MCP.md made the same claim under its server-type table (audit DOC-2),
 * so the page contradicted its own transport census, which already said an
 * unknown entry costs only its own server. Both pages are pinned here.
 */
final class McpStartupOrderingDocDriftTest extends TestCase
{
    public function testTroubleshootingNoLongerCallsMcpStartupOrderingDependent(): void
    {
        $section = self::mcpToolsMissingSection();

        foreach (['ordering-dependent', 'never reached', 'Move or fix'] as $stale) {
            self::assertStringNotContainsString(
                $stale,
                $section,
                "docs/TROUBLESHOOTING.md still says \"{$stale}\" about MCP startup, but "
                . 'McpClient::startServers() attempts every entry and reports the failures once, after the loop',
            );
        }
    }

    public function testTroubleshootingStatesThatEveryEntryIsAttempted(): void
    {
        $section = self::mcpToolsMissingSection();

        self::assertStringContainsString('could not be fully started', $section);
        self::assertStringContainsString(
            'every entry is attempted',
            $section,
            'the advice must say that every entry is attempted, whatever its position in the file',
        );
    }

    public function testMcpPageNoLongerCallsUnknownTypeOrderingDependent(): void
    {
        $section = self::mcpTypeTableSection();

        foreach (['ordering-dependent', 'never reached', 'already up'] as $stale) {
            self::assertStringNotContainsString(
                $stale,
                $section,
                "docs/MCP.md still says \"{$stale}\" about an unknown MCP server type, but "
                . 'McpClient::startServers() attempts every entry and reports the failures once, after the loop',
            );
        }
    }

    public function testMcpPageStatesThatEveryEntryIsAttempted(): void
    {
        $section = self::mcpTypeTableSection();

        self::assertStringContainsString('attempts every entry', $section);
        self::assertStringContainsString('costs only its own server', $section);
        self::assertStringContainsString('Bootstrap::mcpClient()', $section);
    }

    /**
     * docs/MCP.md from its server-type table up to the "### Foreign spellings
     * that are read" heading, whitespace-folded like the Troubleshooting slice.
     */
    private static function mcpTypeTableSection(): string
    {
        $page = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MCP.md');
        $start = strpos($page, 'Four types, and they are');
        self::assertNotFalse($start, 'docs/MCP.md lost its server-type table');

        $end = strpos($page, '### Foreign spellings', $start);
        self::assertNotFalse($end, 'docs/MCP.md lost its "Foreign spellings" heading');

        return (string) preg_replace('/\s+/', ' ', substr($page, $start, $end - $start));
    }

    /**
     * The "## My MCP tools are missing" section, up to the next "---" rule,
     * with whitespace runs folded so a re-wrap of the prose does not trip it.
     */
    private static function mcpToolsMissingSection(): string
    {
        $page = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/TROUBLESHOOTING.md');
        $start = strpos($page, '## My MCP tools are missing');
        self::assertNotFalse($start, 'docs/TROUBLESHOOTING.md lost its "My MCP tools are missing" section');

        $end = strpos($page, "\n---", $start);

        $section = $end === false ? substr($page, $start) : substr($page, $start, $end - $start);

        return (string) preg_replace('/\s+/', ' ', $section);
    }
}
