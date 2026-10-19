<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\McpLaunchEnabledTrait;
use SugarCraft\Code\Tui\McpPanel;

/**
 * MCP-3 in the `/mcp` panel: the user tier gets its OWN row, governed by
 * the user verdict — never the project's — and a pre-MCP-3 inventory
 * renders byte-identically.
 *
 * The E191 contract continues here: every row asserted from the live path
 * comes from the SAME {@see Bootstrap::mcpServerInventory()} the panel
 * consumes in production. The hand-built inventories are the two shapes the
 * live path cannot produce inside one process: the pre-MCP-3 four-key
 * contract (byte-stability across the widening), and a verdict pair the gate
 * no longer emits together (trusted user under an untrusted project comes
 * from the launch OR-gate, but the panel must prove it reads the pair
 * independently — `userStatus` governs the user row, full stop).
 */
final class McpPanelUserScopeTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    private string $tmpDir;
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->armMcpLaunchEnabled();

        $this->tmpDir = sys_get_temp_dir() . '/sc_mcp_panel_user_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);
        $this->home = $this->tmpDir . '/home';
        $this->useHomeSandbox($this->home);
        mkdir($this->home . '/.candy-code', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->restoreMcpLaunchEnabled();
        $this->purgeTree($this->tmpDir);

        parent::tearDown();
    }

    public function testATrustedUserTierPrintsItsOwnCountRow(): void
    {
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        $this->writeProjectServers($root, ['ledger' => ['type' => 'git']]);
        $userPath = $this->writeUserServers(['mail' => ['type' => 'git'], 'notes' => ['type' => 'git']]);

        $out = McpPanel::render(Bootstrap::mcpServerInventory($root), 200);

        self::assertStringContainsString('User scope: 2 servers (' . $userPath . ')', $out);
        self::assertStringContainsString('MCP Project Config', $out, 'the user row is an addition, not a replacement — the project section keeps its header');
    }

    public function testAnAbsentUserTierPrintsNoUserRow(): void
    {
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        $this->writeProjectServers($root, ['ledger' => ['type' => 'git']]);

        $out = McpPanel::render(Bootstrap::mcpServerInventory($root));

        self::assertStringNotContainsString('User scope', $out, 'an optional file that is not there must not cost a row');
    }

    public function testTheCountRowExcludesNamesTheProjectOwns(): void
    {
        // The count promises what the launch will start from the user file:
        // a shadowed row would make the panel lie by one.
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        $this->writeProjectServers($root, ['dup' => ['type' => 'git']]);
        $this->writeUserServers(['dup' => ['type' => 'git'], 'solo' => ['type' => 'git']]);

        $out = McpPanel::render(Bootstrap::mcpServerInventory($root));

        self::assertStringContainsString('User scope: 1 servers (', $out);
    }

    public function testAPreMcp3InventoryRendersByteIdentically(): void
    {
        // The four-key contract: an inventory assembled before MCP-3 (no
        // user keys at all) and the same inventory carrying the keys in
        // their cold shapes must produce the SAME pixels — the widening may
        // not repaint a reader that never asked for it.
        $base = [
            'status' => Bootstrap::MCP_TRUSTED,
            'path' => $this->tmpDir . '/project/.mcp.json',
            'servers' => [['name' => 'ledger', 'type' => 'git', 'detail' => '(this project)']],
            'error' => null,
        ];
        $widened = $base + [
            'userStatus' => Bootstrap::MCP_ABSENT,
            'userPath' => '',
            'userServers' => [],
            'userSkipped' => [],
            'userError' => null,
        ];

        self::assertSame(McpPanel::render($base), McpPanel::render($widened));
        self::assertStringNotContainsString('User scope', McpPanel::render($base));
    }

    public function testTheUserRowIsGovernedByTheUserVerdictNotTheProject(): void
    {
        // The OR-gate makes this pair reachable in production: an untrusted
        // project beside a trusted user file starts the user tier. The panel
        // must show the user row even while the project section says
        // "not trusted" — the two verdicts are read independently.
        $out = McpPanel::render([
            'status' => Bootstrap::MCP_UNTRUSTED,
            'path' => $this->tmpDir . '/project/.mcp.json',
            'servers' => [],
            'error' => null,
            'userStatus' => Bootstrap::MCP_TRUSTED,
            'userPath' => $this->home . '/.candy-code/mcp.json',
            'userServers' => [['name' => 'mail', 'type' => 'git', 'detail' => '(this project)']],
            'userSkipped' => [],
            'userError' => null,
        ], 200);

        self::assertStringContainsString('User scope: 1 servers (' . $this->home . '/.candy-code/mcp.json)', $out);
    }

    public function testABrokenUserFileCostsItsOwnRowAndNothingElse(): void
    {
        $out = McpPanel::render([
            'status' => Bootstrap::MCP_TRUSTED,
            'path' => $this->tmpDir . '/project/.mcp.json',
            'servers' => [['name' => 'ledger', 'type' => 'git', 'detail' => '(this project)']],
            'error' => null,
            'userStatus' => Bootstrap::MCP_TRUSTED,
            'userPath' => $this->home . '/.candy-code/mcp.json',
            'userServers' => [],
            'userSkipped' => [],
            'userError' => 'is not valid JSON (Syntax error)',
        ]);

        self::assertStringContainsString('User scope: 0 servers (', $out);
        self::assertStringContainsString('Config is not valid JSON (Syntax error).', $out);
        self::assertStringContainsString('ledger', $out, 'the user file is broken; the project rows stand on their own');
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

    private function grantProjectTrust(string $root): void
    {
        $canonical = realpath($root);
        self::assertIsString($canonical);
        file_put_contents($this->home . '/.candy-code/config.json', json_encode([
            'trustedProjectMcp' => [$canonical],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return string the user file's path */
    private function writeUserServers(array $servers): string
    {
        $path = $this->home . '/.candy-code/' . Bootstrap::MCP_USER_CONFIG_FILENAME;
        file_put_contents($path, json_encode(['mcpServers' => $servers], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @param array<string, array<string, mixed>> $servers */
    private function writeProjectServers(string $root, array $servers): void
    {
        file_put_contents(
            $root . '/' . Bootstrap::MCP_CONFIG_FILENAME,
            json_encode(['mcpServers' => $servers], JSON_THROW_ON_ERROR),
        );
    }

    private function purgeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
