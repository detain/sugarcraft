<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tui\McpPanel;

/**
 * E689: the `/mcp` panel renders the LIVE inventory, nothing else.
 *
 * The E191 contract, applied: every row this suite asserts comes from calling
 * the SAME {@see Bootstrap::mcpServerInventory()} the panel consumes in
 * production, over a fixture `.mcp.json` whose server names are RANDOM per
 * run. A panel that hard-coded a roster would fail the headline row on its
 * first randomised name; a discovery path that drifted from the CLI's would
 * fail the row-count equality, because both readers go through
 * `mcpConfigDecision()`.
 *
 * The trust gate is exercised with the real grant file (a HOME sandbox plus
 * `<home>/.sugar-crush/config.json` naming the fixture root under
 * `trustedProjectMcp`) rather than mocked, so "the panel tells an untrusted
 * operator the truth" is a fact about the shipped gate, not about a stub.
 */
final class McpPanelTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/sc_mcp_panel_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
        $this->restoreHomeSandbox();

        parent::tearDown();
    }

    /**
     * ABSENT project: the panel says so plainly and invents no server rows.
     */
    public function testAbsentProjectRendersNoneWithoutInventingRows(): void
    {
        $root = $this->makeRoot();
        $inventory = Bootstrap::mcpServerInventory($root);
        self::assertSame(Bootstrap::MCP_ABSENT, $inventory['status']);

        $out = McpPanel::render($inventory);

        self::assertStringContainsString('MCP Project Config', $out);
        self::assertStringContainsString('no .mcp.json', $out);
        self::assertStringNotContainsString('   - ', $out, 'an absent project has no servers to list');
    }

    /**
     * THE HEADLINE (E191): for a TRUSTED root, the panel's rows are exactly
     * the live inventory's rows — every randomised name, every type as
     * written, every detail — no more, no less.
     */
    public function testTrustedPanelRowsAreExactlyTheLiveInventoryRows(): void
    {
        $root = $this->makeRoot();
        $stdio = 'zephyr-' . bin2hex(random_bytes(4));
        $http = 'quill-' . bin2hex(random_bytes(4));
        $git = 'onyx-' . bin2hex(random_bytes(4));
        $weird = 'wren-' . bin2hex(random_bytes(4));

        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => [
                $stdio => ['command' => '/usr/bin/' . $stdio . '-runner', 'args' => ['--quiet', 'serve']],
                $http => ['type' => 'http', 'url' => 'https://example.invalid/' . $http],
                $git => ['type' => 'git', 'path' => 'tools/' . $git],
                $weird => ['type' => 'sarcophagus', 'command' => 'nope'],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->trustRoot($root);

        $inventory = Bootstrap::mcpServerInventory($root);
        self::assertSame(Bootstrap::MCP_TRUSTED, $inventory['status'], 'fixture must reach the trusted tier');
        self::assertCount(4, $inventory['servers']);

        $out = McpPanel::render($inventory, 140);

        self::assertStringContainsString('trusted', $out);
        self::assertStringContainsString('Servers (4):', $out);
        foreach ($inventory['servers'] as $server) {
            self::assertStringContainsString(
                $server['name'],
                $out,
                'the panel dropped a server the live inventory returned',
            );
            self::assertStringContainsString('[' . $server['type'] . ']', $out);
            if ($server['detail'] !== '') {
                self::assertStringContainsString($server['detail'], $out);
            }
        }
        // Unknown types pass through as written — the string IS the diagnosis.
        self::assertStringContainsString('[sarcophagus]', $out);
        self::assertSame(4, substr_count($out, "\n   - "), 'row count must equal the inventory count');
    }

    /**
     * UNTRUSTED root: the panel reports present-but-untrusted and does NOT
     * leak the names the file declares — the same refusal the launch applies,
     * because both read one decision path.
     */
    public function testUntrustedRootHidesTheRosterTheFileDeclares(): void
    {
        $root = $this->makeRoot();
        $secret = 'falcon-' . bin2hex(random_bytes(4));
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => [$secret => ['command' => 'everthing']],
        ], JSON_THROW_ON_ERROR));

        $inventory = Bootstrap::mcpServerInventory($root);
        self::assertSame(Bootstrap::MCP_UNTRUSTED, $inventory['status']);

        $out = McpPanel::render($inventory);

        self::assertStringContainsString('NOT TRUSTED', $out);
        self::assertStringContainsString('not listed', $out);
        self::assertStringNotContainsString($secret, $out, 'the panel must not enumerate what the gate refuses to run');
    }

    /**
     * A declared-but-undecodable config surfaces the inventory's own error
     * sentence — the panel adds no diagnosis of its own.
     */
    public function testMalformedConfigSurfacesTheInventoryErrorSentence(): void
    {
        $root = $this->makeRoot();
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, '{not json at all');
        $this->trustRoot($root);

        $inventory = Bootstrap::mcpServerInventory($root);
        self::assertSame(Bootstrap::MCP_TRUSTED, $inventory['status']);
        self::assertIsString($inventory['error']);

        $out = McpPanel::render($inventory);

        self::assertStringContainsString($inventory['error'], $out);
        self::assertStringContainsString('not valid JSON', $out);
    }

    // -------------------------------------------------------------------------
    // Fixture plumbing
    // -------------------------------------------------------------------------

    private function makeRoot(): string
    {
        $root = $this->tmpDir . '/proj-' . bin2hex(random_bytes(4));
        mkdir($root, 0o755, true);

        return $root;
    }

    /**
     * Grant MCP trust for $root the shipped way: the operator's
     * `<home>/.sugar-crush/config.json` under `trustedProjectMcp`.
     */
    private function trustRoot(string $root): void
    {
        $home = $this->tmpDir . '/home-' . bin2hex(random_bytes(4));
        $this->useHomeSandbox($home);

        $canonical = realpath($root);
        self::assertIsString($canonical);

        mkdir($home . '/.sugar-crush', 0o700, true);
        file_put_contents($home . '/.sugar-crush/config.json', json_encode([
            'trustedProjectMcp' => [$canonical],
        ], JSON_THROW_ON_ERROR));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
