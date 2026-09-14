<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\McpAuthCommand;
use SugarCraft\Crush\MCP\McpAuthStore;
use SugarCraft\Crush\MCP\OAuthClientRegistration;

// OAuthClientRegistration carries AuthEntry in the same file (project law).
require_once __DIR__ . '/../../src/MCP/OAuthClientRegistration.php';

/**
 * E701: the in-chat `/mcp auth login` arm GUIDES and never runs. The loopback
 * flow needs the terminal for itself — printing a URL, waiting on a browser,
 * answering a local callback — and the TUI owns that terminal, so the chat
 * arm must reduce to "here is the shell command" and return immediately.
 *
 * These pins assert the door stays closed: no socket is bound, no HTTP is
 * asked for, nothing is stored. The MockHandler is deliberately EMPTY — any
 * wire call the arm dared to make would surface as a "queue is empty" error,
 * which is exactly the guarantee.
 *
 * @see \SugarCraft\Crush\MCP\OAuthLoopbackFlow (where the flow actually lives)
 */
final class McpAuthLoginGuidanceTest extends TestCase
{
    private string $tempDir;
    private string $authFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/mcp_guidance_test_' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0700, true);
        $this->authFilePath = $this->tempDir . '/auth.json';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tempDir)) {
            foreach ((array) glob($this->tempDir . '/*') as $file) {
                unlink((string) $file);
            }
            rmdir($this->tempDir);
        }
    }

    public function testLoginPrintsTheShellCommandAndReturnsZero(): void
    {
        ob_start();
        $rc = (new McpAuthCommand($this->store()))->execute(new Chat([]), ['login']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $rc, $output);
        self::assertStringContainsString('sugarcrush mcp auth login <server>', $output, 'the guidance names the exact shell form');
        self::assertStringContainsString('not a chat turn', $output, 'and says why in one breath');
        self::assertStringNotContainsString('http://127.0.0.1', $output, 'no flow ran — no loopback URL appears');
    }

    public function testLoginBindsNoSocketAndAsksForNoWireAndStoresNothing(): void
    {
        // parseMcpArgs strips the `auth` noun before execute() ever sees it,
        // so every chat routing — `/mcp login`, `/mcp auth login` — arrives
        // as exactly ['login']. The store is built on an EMPTY MockHandler:
        // any wire call the arm dared to make would throw "queue is empty"
        // before the assertion below is ever reached.
        ob_start();
        $rc = (new McpAuthCommand($this->store()))->execute(new Chat([]), ['login']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $rc);
        self::assertStringContainsString('sugarcrush mcp auth login', $output);
        self::assertFileDoesNotExist($this->authFilePath, 'guidance stores nothing');
    }

    public function testUnknownSubCommandStillRefusesAndListsTheRoster(): void
    {
        ob_start();
        $rc = (new McpAuthCommand($this->store()))->execute(new Chat([]), ['bogus']);
        $output = (string) ob_get_clean();

        self::assertSame(1, $rc, $output);
        self::assertStringContainsString("Unknown sub-command 'bogus'", $output);
        self::assertStringContainsString('Use: list, add, remove, login', $output, 'the roster the error offers is the roster the match arms have');
    }

    private function store(): McpAuthStore
    {
        return new McpAuthStore(
            new OAuthClientRegistration(
                new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
                $this->authFilePath,
            ),
        );
    }
}
