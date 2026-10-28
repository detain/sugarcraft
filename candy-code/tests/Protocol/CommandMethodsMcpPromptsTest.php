<?php

/**
 * C-MCP-2.b (GAP-MCP-07/GAP-CMD-09): `command.list` and `command.exec` speak
 * the session host's MERGED command map, so the MCP prompt rows a served
 * session can already run are listed (`source: "mcp"`) and admitted — and a
 * listing never promises a row exec would refuse.
 *
 * Before the fix both methods read the workspace loader alone: a prompt the
 * host resolved was invisible to the listing (a web `/` popup could never
 * show it) and `command.exec` answered `command_not_found` for it. The rows
 * here ride the real dispatcher over a hub-built host — the memoised-client
 * route `Cli\Serve` itself takes — so the whole list-then-exec path is the
 * unit under test, not a hand-built host's shortcut.
 */

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Cli\McpDecision;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpPromptProvider;
use SugarCraft\Code\MCP\McpResourceProvider;
use SugarCraft\Code\MCP\McpServer;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\Message;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Role;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\McpLaunchEnabledTrait;

final class CommandMethodsMcpPromptsTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    private const SERVED_FETCH_MARK = 'CMCP2B SERVED FETCH';

    private string $sandbox = '';

    private string $project = '';

    private EnhancedSessionStore $store;

    /** Memo key/identity of the seeded client, cleared in tearDown. */
    private string $seededKey = '';

    protected function setUp(): void
    {
        $this->armMcpLaunchEnabled();
        $this->sandbox = \sys_get_temp_dir() . '/crush-cmdp-mcp-' . \bin2hex(\random_bytes(6));
        $this->project = $this->sandbox . '/project';
        \mkdir($this->project . '/.candy-code/commands', 0o700, true);
        \mkdir($this->sandbox . '/home/.candy-code', 0o700, true);
        // A plain project row, and a disk file claiming the MCP prompt's name
        // to prove the merge precedence is visible in the listing's source.
        \file_put_contents($this->project . '/.candy-code/commands/notes.md', 'Note the diff.');
        \file_put_contents($this->project . '/.candy-code/commands/mcp__srv__review.md', 'DISK SHADOW BODY');
        $this->useHomeSandbox($this->sandbox . '/home');
        // The admission leg's fork child re-derives the client from disk
        // (LaunchContext buckets are pid-keyed), so this root must really be
        // a launchable one — the operator's own grant shape, planted before
        // any decision is taken.
        \file_put_contents($this->sandbox . '/home/.candy-code/config.json', (string) \json_encode([
            'trustedProjectMcp' => [$this->project],
        ]));
        $this->plantServedPromptConfig();
        $this->store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $this->store->createSession('s', 'p', 'm');

        $client = $this->promptSurfaceClient();
        $this->seededKey = McpDecision::mcpConfigMemoKey(Bootstrap::mcpConfigDecision($this->project));
        LaunchContext::rememberMcpClient(\getmypid() ?: 0, $this->seededKey, $client);
    }

    protected function tearDown(): void
    {
        if ($this->seededKey !== '') {
            LaunchContext::rememberMcpClient(\getmypid() ?: 0, $this->seededKey, null);
        }
        $this->restoreHomeSandbox();
        $this->restoreMcpLaunchEnabled();
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox));
        }
    }

    public function testTheServedMcpPromptRowIsListedWithMcpSource(): void
    {
        $rows = $this->listedCommandRows();

        self::assertSame('mcp', $rows['mcp__srv__summarise']['source'] ?? null, 'the prompt the host can run is listed');
        self::assertSame('server', $rows['mcp__srv__summarise']['runsIn'] ?? null, 'it runs by admission on the server');
    }

    public function testADiskFileKeepsTheSourceOfANameItShadows(): void
    {
        $rows = $this->listedCommandRows();

        self::assertSame('file', $rows['mcp__srv__review']['source'] ?? null, 'disk wins the map, and the row says so');
    }

    public function testAPlainProjectFileStillListsAsFile(): void
    {
        $rows = $this->listedCommandRows();

        self::assertSame('file', $rows['notes']['source'] ?? null);
    }

    public function testADeniedServerContributesNoCommandRows(): void
    {
        $rows = $this->listedCommandRows();

        foreach (\array_keys($rows) as $name) {
            self::assertStringStartsNotWith('mcp__blocked__', (string) $name, 'the deny gate runs before the listing');
        }
    }

    public function testCommandExecAdmitsTheServedMcpPromptRow(): void
    {
        // The admission's fork-leg re-derives the client from disk in the
        // child (LaunchContext buckets are pid-keyed), and setUp planted
        // exactly that: a granted project .mcp.json over a stdio prompt
        // fixture, alongside the seeded roster the parent lists from.
        [$client, $hub, $dispatcher] = $this->openPromptWire();

        $response = (array) $client->call('command.exec', [
            'sessionId' => 's',
            'name' => 'mcp__srv__summarise',
            'args' => 'deployment',
        ]);

        self::assertContains($response['admitted'] ?? null, ['pending', 'started'], 'the row is admitted, not refused');

        $host = $hub->get('s');
        self::assertInstanceOf(SessionHost::class, $host, 'the admission ran through the hub host');
        $this->drainCommandHost($host);
        $sent = \array_values(\array_filter(
            $host->history(),
            static fn (Message $m): bool => $m->role === Role::User && \str_contains($m->content, self::SERVED_FETCH_MARK),
        ));
        self::assertCount(1, $sent, 'the fetched prompt body reached the transcript');
        self::assertStringContainsString('deployment', $sent[0]->content, 'the positional argument mapped through');

        $host->release();
        $this->closePromptWire($hub, $dispatcher);
    }

    public function testCommandExecOfAnUnknownNameStillAnswersCommandNotFound(): void
    {
        [$client, $hub, $dispatcher] = $this->openPromptWire();

        $response = $client->request('command.exec', ['sessionId' => 's', 'name' => 'no-row-called-this']);

        self::assertSame('command_not_found', $response['error']['data']['kind'] ?? null);
        $this->closePromptWire($hub, $dispatcher);
    }

    /**
     * C-MCP-2.b round 2: the serve path's exec choke IS the host map — the
     * deny gate applies upstream in McpPromptCommands::specs(), so a denied
     * server's prompt row is never listable, never lookupable, and therefore
     * never admitted or materialised. This is the sibling of
     * testADeniedServerContributesNoCommandRows() on the EXEC side: should
     * anyone ever wire exec to fetch prompts off the server directly instead
     * of through `customCommands()`, the blocked server's throwing
     * prompt() would fire here and the error kind would stop being
     * `command_not_found`.
     */
    public function testCommandExecRefusesADeniedServersPromptRow(): void
    {
        [$client, $hub, $dispatcher] = $this->openPromptWire();

        $response = $client->request('command.exec', ['sessionId' => 's', 'name' => 'mcp__blocked__review']);

        self::assertSame('command_not_found', $response['error']['data']['kind'] ?? null);
        self::assertFalse($hub->get('s')?->isBusy() ?? false, 'the refused row never reached admission');
        $this->closePromptWire($hub, $dispatcher);
    }

    // ── harness ────────────────────────────────────────────────────────

    /**
     * A real McpClient over two seeded provider servers — the
     * SessionHostMcpSurfaceTest idiom — with one server denied so the gate
     * is exercised on the listing side too.
     */
    private function promptSurfaceClient(): McpClient
    {
        $srv = new class implements McpServer, McpPromptProvider, McpResourceProvider {
            public function start(): void {}
            public function stop(): void {}

            /** @return list<McpTool> */
            public function listTools(): array
            {
                return [];
            }

            /** @return array<mixed> */
            public function callTool(string $toolName, array $args): array
            {
                return [];
            }

            /** @return list<array<mixed>> */
            public function listPrompts(): array
            {
                return [
                    ['name' => 'review', 'description' => 'review the target', 'arguments' => [['name' => 'target', 'required' => true]]],
                    ['name' => 'summarise', 'description' => 'summarise the branch', 'arguments' => [['name' => 'branch']]],
                ];
            }

            /** @return array<mixed> */
            public function prompt(string $name, array $arguments = []): array
            {
                return ['messages' => [[
                    'role' => 'user',
                    'content' => ['type' => 'text', 'text' => 'CMCP2B SERVED FETCH ' . \implode(' ', $arguments)],
                ]]];
            }

            /** @return list<array<mixed>> */
            public function listResources(): array
            {
                return [['uri' => 'file://log', 'name' => 'log']];
            }

            /** @return array<mixed> */
            public function readResource(string $uri): array
            {
                return ['contents' => [['uri' => $uri, 'text' => 'CMCP2B BODY']]];
            }
        };
        $blocked = new class implements McpServer, McpPromptProvider, McpResourceProvider {
            public function start(): void {}
            public function stop(): void {}

            /** @return list<McpTool> */
            public function listTools(): array
            {
                return [];
            }

            /** @return array<mixed> */
            public function callTool(string $toolName, array $args): array
            {
                return [];
            }

            /** @return list<array<mixed>> */
            public function listPrompts(): array
            {
                return [['name' => 'review', 'description' => 'denied server prompt']];
            }

            /** @return array<mixed> */
            public function prompt(string $name, array $arguments = []): array
            {
                throw new \RuntimeException('the denied server never answers');
            }

            /** @return list<array<mixed>> */
            public function listResources(): array
            {
                return [['uri' => 'file://forbidden', 'name' => 'forbidden']];
            }

            /** @return array<mixed> */
            public function readResource(string $uri): array
            {
                throw new \RuntimeException('the denied server never reads');
            }
        };

        $client = new McpClient($this->sandbox . '/absent-' . \bin2hex(\random_bytes(4)) . '.json', unrestricted: true);
        (new \ReflectionProperty(McpClient::class, 'servers'))->setValue($client, ['srv' => $srv, 'blocked' => $blocked]);
        $client->setDenyPatterns(['blocked' => 'deny']);

        return $client;
    }

    /**
     * A launchable project config + stdio prompt server under the fixture
     * root, so the admission child's disk re-derivation reaches a real
     * prompt body. The script answers initialize/tools-list/prompts-list/
     * prompts-get and exits on stdin EOF — the StdioMcpServerTest fixture
     * discipline (bounded by its own read loop, no bare sleep).
     */
    private function plantServedPromptConfig(): void
    {
        $script = $this->sandbox . '/served-prompt-server.php';
        \file_put_contents($script, <<<'PHP'
            <?php
            $deadline = microtime(true) + 30.0;
            while (($line = fgets(STDIN)) !== false) {
                if (microtime(true) >= $deadline) {
                    exit(0);
                }
                $msg = json_decode($line, true);
                if (!is_array($msg) || !isset($msg['id'])) {
                    continue;
                }
                $result = match ((string) ($msg['method'] ?? '')) {
                    'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()],
                    'tools/list' => ['tools' => []],
                    'prompts/list' => ['prompts' => [[
                        'name' => 'summarise',
                        'description' => 'summarise the branch',
                        'arguments' => [['name' => 'branch']],
                    ]]],
                    'prompts/get' => ['description' => 'summarise the branch', 'messages' => [[
                        'role' => 'user',
                        'content' => ['type' => 'text', 'text' => 'CMCP2B SERVED FETCH '
                            . ($msg['params']['arguments']['branch'] ?? '?')],
                    ]]],
                    default => ['content' => [['type' => 'text', 'text' => 'ok']]],
                };
                echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
                flush();
            }
            PHP);
        \file_put_contents($this->project . '/.mcp.json', (string) \json_encode([
            'mcpServers' => ['srv' => [
                'type' => 'stdio',
                'command' => \PHP_BINARY,
                'args' => [$script],
                // Explicit empty env — the launch's credential-notice shape
                // for a server that needs none (StdioMcpServerTest idiom).
                'env' => new \stdClass(),
            ]],
        ]));
    }

    /**
     * `command.list` over the real dispatcher for a hub-built host — the
     * shape `Cli\Serve` opens, whose default MCP provider finds the client
     * seeded in setUp — keyed by command name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function listedCommandRows(): array
    {
        [$client, $hub, $dispatcher] = $this->openPromptWire();

        $items = (array) $client->call('command.list', ['sessionId' => 's'])['items'];

        $this->closePromptWire($hub, $dispatcher);

        return \array_column($items, null, 'name');
    }

    /** @return array{0: WireClient, 1: SessionHub, 2: Dispatcher} */
    private function openPromptWire(): array
    {
        $workspace = WorkspaceContext::new(
            root: $this->project,
            sessionStore: $this->store,
            backend: new EchoBackend(),
            commandLoader: new CommandLoader(),
        );
        $hub = SessionHub::new($workspace);
        $context = ServerContext::new($hub, ServerConfig::new($this->sandbox)->withRoot($this->project), 'cmcp2b-cmd', Loop::get());
        $dispatcher = Dispatcher::new($context);
        $client = WireClient::open($dispatcher, 'cmcp2b-cmd-client');
        $client->hello();

        return [$client, $hub, $dispatcher];
    }

    private function closePromptWire(SessionHub $hub, Dispatcher $dispatcher): void
    {
        $dispatcher->stop('test over');
        $hub->closeAll();
        $settle = Loop::addTimer(0.001, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($settle);
    }

    /** Pump the shared loop while the admitted turn is in flight — bounded. */
    private function drainCommandHost(?SessionHost $host): void
    {
        if ($host === null || !$host->isBusy()) {
            return;
        }

        $loop = Loop::get();
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($host, $loop): void {
            if (!$host->isBusy()) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(15.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);

        self::assertFalse($host->isBusy(), 'the admitted turn settled');
    }
}
