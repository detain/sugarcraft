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

    /**
     * The clip is DISPLAY-WIDTH safe (review item 2): a CJK+emoji payload
     * whose byte length vastly exceeds the pane is forced through the
     * width-60 clip. The old `substr()` byte-cut would split a codepoint
     * (invalid UTF-8 out), and a naive `mb_substr()` would overrun the cell
     * grid by the number of wide glyphs. Three invariants: valid encoding
     * throughout, every row inside the pane, and the ellipsis demonstrably
     * fired — a clip that silently never ran would pass the first two.
     */
    public function testWideUtf8RowsClipByDisplayWidthWithoutSplittingCodepoints(): void
    {
        $root = $this->makeRoot();
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => [
                '対話サーバー-' . bin2hex(random_bytes(3)) => [
                    'command' => '/srv/言語サーバー/' . str_repeat('あいう', 30) . '/bin/run 🧪🧪🧪',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->trustRoot($root);

        $inventory = Bootstrap::mcpServerInventory($root);
        self::assertSame(Bootstrap::MCP_TRUSTED, $inventory['status']);

        $width = 60;
        $out = McpPanel::render($inventory, $width);

        self::assertTrue(
            mb_check_encoding($out, 'UTF-8'),
            'the row clip split a codepoint — clipping must go through Width, not substr',
        );
        $clipped = 0;
        foreach (explode("\n", $out) as $row) {
            self::assertLessThanOrEqual(
                $width,
                \SugarCraft\Core\Util\Width::string($row),
                'a row overflows the pane in DISPLAY cells: ' . $row,
            );
            if (str_contains($row, '…')) {
                $clipped++;
            }
        }
        self::assertGreaterThan(
            0,
            $clipped,
            'the CJK payload demands a clip at width 60; no ellipsis anywhere means the clip never ran',
        );
    }

    // -------------------------------------------------------------------------
    // E698: the liveness block
    // -------------------------------------------------------------------------

    /**
     * THE BYTE-STABILITY LAW: every existing pin in this file renders the
     * cold process. An EMPTY liveness map must be byte-identical to no map at
     * all — m2 mutates this by adding a single blank row, and this test is
     * what reddens.
     */
    public function testAnEmptyLivenessMapRendersByteIdenticalToTheColdPanel(): void
    {
        $root = $this->makeRoot();
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => [
                'sable-' . bin2hex(random_bytes(3)) => ['command' => '/usr/bin/thing'],
                'boreal-' . bin2hex(random_bytes(3)) => ['type' => 'http', 'url' => 'https://example.invalid/x'],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->trustRoot($root);

        $inventory = Bootstrap::mcpServerInventory($root);
        self::assertSame(Bootstrap::MCP_TRUSTED, $inventory['status']);

        self::assertSame(
            McpPanel::render($inventory, 100),
            McpPanel::render($inventory, 100, []),
            'a cold process (empty started-map) must not gain a single byte',
        );
    }

    /**
     * The suffix vocabulary on the rows plus the summary line — up, exited,
     * ready, ready (in-process), and ` · not up` for the declared-but-absent
     * row that gives {@see \SugarCraft\Crush\MCP\McpClient::startServer()}'s
     * silent skip a voice. Counts are derived here the same way the panel
     * derives them (intersection of map keys and declared names).
     */
    public function testLivenessSuffixesAndSummaryRideTheDeclaredRows(): void
    {
        $root = $this->makeRoot();
        $up = 'aurel-' . bin2hex(random_bytes(3));
        $ready = 'brume-' . bin2hex(random_bytes(3));
        $dead = 'cern-' . bin2hex(random_bytes(3));
        $missing = 'dune-' . bin2hex(random_bytes(3));
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => [
                $up => ['command' => '/usr/bin/' . $up],
                $ready => ['type' => 'http', 'url' => 'https://example.invalid/' . $ready],
                $dead => ['type' => 'git'],
                $missing => ['command' => '/usr/bin/' . $missing],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->trustRoot($root);

        $inventory = Bootstrap::mcpServerInventory($root);
        $liveness = [
            $up => ['transport' => 'stdio', 'up' => true, 'tools' => 5],
            $ready => ['transport' => 'http', 'up' => true, 'tools' => 12],
            $dead => ['transport' => 'git', 'up' => false, 'tools' => 3],
        ];

        $out = McpPanel::render($inventory, 160, $liveness);

        self::assertStringContainsString($up . ' [stdio] /usr/bin/' . $up . ' · up 5 tools', $out);
        self::assertStringContainsString(' · ready 12 tools', $out);
        self::assertStringContainsString(' · exited 3 tools', $out);
        self::assertStringContainsString($missing . ' [stdio] /usr/bin/' . $missing . ' · not up', $out);
        self::assertStringNotContainsString($missing . ' [stdio] /usr/bin/' . $missing . "\n", $out,
            'the not-up row must carry its suffix — an unsuffixed row would render the silent skip invisible again');
        self::assertStringContainsString(
            "  Live in this process: 3 of 4 declared (other sessions' servers are not visible here)",
            $out,
        );
        self::assertSame(4, substr_count($out, "\n   - "), 'liveness must never change the declared row count');
    }

    /**
     * The git label is deliberately longer than the http one — "(in-process)"
     * is the distinction the operator needs when two rows both say ready.
     */
    public function testGitTransportReadsReadyInProcess(): void
    {
        $root = $this->makeRoot();
        $name = 'gitside-' . bin2hex(random_bytes(3));
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => [$name => ['type' => 'git']],
        ], JSON_THROW_ON_ERROR));
        $this->trustRoot($root);

        $inventory = Bootstrap::mcpServerInventory($root);
        $out = McpPanel::render($inventory, 160, [
            $name => ['transport' => 'git', 'up' => true, 'tools' => 42],
        ]);

        self::assertStringContainsString(' · ready (in-process) 42 tools', $out);
    }

    /**
     * The mirror gap: this process started a server the CURRENT inventory no
     * longer declares — config edited or deleted after the launch that froze
     * it. Its tools stay attributable.
     */
    public function testStartedButNoLongerDeclaredGetsItsOwnRow(): void
    {
        $root = $this->makeRoot();
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => ['kept-' . bin2hex(random_bytes(3)) => ['command' => '/bin/ok']],
        ], JSON_THROW_ON_ERROR));
        $this->trustRoot($root);

        $inventory = Bootstrap::mcpServerInventory($root);
        $orphan = 'drifted-' . bin2hex(random_bytes(3));
        $out = McpPanel::render($inventory, 160, [
            (string) ($inventory['servers'][0]['name']) => ['transport' => 'stdio', 'up' => true, 'tools' => 1],
            $orphan => ['transport' => 'stdio', 'up' => true, 'tools' => 9],
        ]);

        self::assertStringContainsString('  Started but no longer declared: ' . $orphan . ' (9 tools)', $out);
        self::assertStringContainsString('  Live in this process: 1 of 1 declared', $out,
            'the summary counts DECLARED rows only — the orphan lives in its own section');
    }

    /**
     * THE LEAK GATE HOLDS UNDER LIVENESS: untrusted status suppresses the
     * entire block even when a non-empty map arrives — a caller cannot talk
     * the panel into printing, through "what this process started", the same
     * roster the discovery gate withholds. This is the m3 mutation target.
     */
    public function testUntrustedStatusSuppressesTheWholeLivenessBlock(): void
    {
        $root = $this->makeRoot();
        $secret = 'wraith-' . bin2hex(random_bytes(4));
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => [$secret => ['command' => 'everthing']],
        ], JSON_THROW_ON_ERROR));
        // Deliberate: NO trustRoot().

        $inventory = Bootstrap::mcpServerInventory($root);
        self::assertSame(Bootstrap::MCP_UNTRUSTED, $inventory['status']);

        $out = McpPanel::render($inventory, 120, [
            $secret => ['transport' => 'stdio', 'up' => true, 'tools' => 4],
        ]);

        self::assertStringNotContainsString($secret, $out);
        self::assertStringNotContainsString('Live in this process', $out);
        self::assertStringNotContainsString(' · ', $out);
    }

    /**
     * The unknown-transport fallback: `up: null` must read as unknown, not as
     * a defaulted dead-or-alive claim.
     */
    public function testUnknownTransportReadsStateUnknown(): void
    {
        $root = $this->makeRoot();
        $name = 'strange-' . bin2hex(random_bytes(3));
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => [$name => ['type' => 'sarcophagus']],
        ], JSON_THROW_ON_ERROR));
        $this->trustRoot($root);

        $inventory = Bootstrap::mcpServerInventory($root);
        $out = McpPanel::render($inventory, 160, [
            $name => ['transport' => 'other', 'up' => null, 'tools' => 0],
        ]);

        self::assertStringContainsString(' · state unknown', $out);
        self::assertStringNotContainsString(' · exited', $out);
    }

    /**
     * A suffixed CJK row at width 60: the clip must still hold the pane in
     * DISPLAY cells and must keep the suffix end intact (middle-truncate
     * preserves both ends — the tools word is proof the tail survived).
     */
    public function testWideCjkRowWithLivenessSuffixStillClipsToThePane(): void
    {
        $root = $this->makeRoot();
        $name = '応答サーバー-' . bin2hex(random_bytes(3));
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => [
                $name => ['command' => '/srv/' . str_repeat('語', 40) . '/bin/run'],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->trustRoot($root);

        $inventory = Bootstrap::mcpServerInventory($root);
        $out = McpPanel::render($inventory, 60, [
            $name => ['transport' => 'stdio', 'up' => true, 'tools' => 7],
        ]);

        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
        $rows = explode("\n", $out);
        $sawSuffixTail = false;
        foreach ($rows as $row) {
            self::assertLessThanOrEqual(60, \SugarCraft\Core\Util\Width::string($row), 'row overflow: ' . $row);
            if (str_contains($row, ' · up 7 tools')) {
                $sawSuffixTail = true;
            }
        }
        self::assertTrue($sawSuffixTail, 'the suffix tail must survive a middle clip — the whole point of the placement');
        self::assertStringContainsString('…', $out, 'the clip demonstrably fired on this payload');
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
