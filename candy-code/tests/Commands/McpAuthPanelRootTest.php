<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\McpAuthCommand;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\MCP\McpAuthStore;
use SugarCraft\Code\MCP\OAuthClientRegistration;

/**
 * ISS-092: `/mcp` answered for the CURRENT DIRECTORY while the same command
 * line carries a session root.
 *
 * `listWithProjectPanel()` called {@see \SugarCraft\Code\Cli\Bootstrap::mcpServerInventory()},
 * {@see \SugarCraft\Code\Cli\Bootstrap::mcpLivenessSnapshot()} and
 * {@see \SugarCraft\Code\Cli\Bootstrap::mcpConfigChangedSinceLaunch()} with NO
 * argument, so each resolved the project root from `getcwd()`. The CLI sibling
 * (`candy-code mcp list`) passes `$args->root` into the very same three —
 * so `candy-code --root /repo mcp auth list` and `/mcp` inside a TUI opened at
 * `/repo` could print DIFFERENT panels, and a session rooted elsewhere read a
 * stranger directory's `.mcp.json` as if it were the project's.
 *
 * The panel path line is the observable: it prints the resolved `.mcp.json`
 * path verbatim, so rooting the context at a directory that carries a file
 * must name THAT file even when the process cwd carries a different one.
 */
final class McpAuthPanelRootTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            @unlink($dir . '/.mcp.json');
            @rmdir($dir);
        }
        $this->dirs = [];

        parent::tearDown();
    }

    public function testThePanelReadsTheSessionRootNotTheWorkingDirectory(): void
    {
        $root = $this->rootWithMcpFile('rooted');
        $context = CommandContext::new(root: $root);
        self::assertSame($root, $context->projectRoot(), 'fixture: the context must carry the root');

        $output = $this->captureList($context);

        self::assertStringContainsString($root . '/.mcp.json', $output,
            'the panel named something other than the session root\'s .mcp.json — '
            . 'an un-rooted mcpServerInventory() call is back');
    }

    /**
     * The pair the defect made possible: cwd and root naming DIFFERENT files.
     * A process whose cwd happens to equal the root could never tell the two
     * implementations apart, which is how the un-rooted calls survived.
     */
    public function testTheRootWinsWhenTheWorkingDirectoryHasItsOwnFile(): void
    {
        $root = $this->rootWithMcpFile('rooted');
        $cwd = $this->rootWithMcpFile('ambient');

        $previous = getcwd();
        chdir($cwd);

        try {
            $output = $this->captureList(CommandContext::new(root: $root));
        } finally {
            chdir((string) $previous);
        }

        self::assertStringContainsString($root . '/.mcp.json', $output);
        self::assertStringNotContainsString($cwd . '/.mcp.json', $output,
            'the ambient directory leaked into a rooted panel');
    }

    /** A directory that declares one (untrusted → read, never run). */
    private function rootWithMcpFile(string $tag): string
    {
        $dir = sys_get_temp_dir() . '/mcp_panel_root_' . $tag . '_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->dirs[] = $dir;
        file_put_contents($dir . '/.mcp.json', (string) json_encode(['mcpServers' => ['x' => ['type' => 'git']]]));

        return $dir;
    }

    private function captureList(CommandContext $context): string
    {
        // An EMPTY credentials store, pointed at a private temp path so the
        // table half never reads (or writes) the real home store. The subject
        // is the panel half above it.
        $authPath = sys_get_temp_dir() . '/mcp_panel_root_auth_' . getmypid() . '_' . bin2hex(random_bytes(6)) . '.json';
        $store = new McpAuthStore(new OAuthClientRegistration(authFilePath: $authPath));
        $command = new McpAuthCommand($store);

        ob_start();
        $command->execute($context, ['list']);
        $output = (string) ob_get_clean();

        @unlink($authPath);
        @unlink($authPath . '.lock');

        return $output;
    }
}
