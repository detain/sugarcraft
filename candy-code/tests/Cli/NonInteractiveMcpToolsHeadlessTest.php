<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\MCP\AnswersServerRequests;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpServer;
use SugarCraft\Code\MCP\McpServerRequests;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Permissions\ApprovalVerdict;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Tools\BuiltIn\McpCallTool;
use SugarCraft\Code\Tools\BuiltIn\Todo;
use SugarCraft\Code\Tools\McpToolBridge;

/**
 * C-MCP-3.b, the headless half: a `-p` run asks nobody anything, and that now
 * includes a stdio server's `elicitation/create`.
 *
 * {@see NonInteractive::withoutInteractiveUser()} closes every
 * {@see McpToolBridge} and {@see McpCallTool} beside the `AskUser`/`PlanExit`
 * pair it already closed: a closed MCP tool installs no per-call answer desk
 * for its elicitations, so the request lands on the spawn-time default desk —
 * the one the client builds with no approver, which answers `cancel` by
 * construction (C-MCP-3.a). A later `withPermissionApprover()` must NOT undo
 * the closure: the `-p` decision is made once at launch and sticks, mirroring
 * the {@see \SugarCraft\Code\Tools\BuiltIn\AskUserTool} rule the same funnel
 * enforces.
 */
final class NonInteractiveMcpToolsHeadlessTest extends TestCase
{
    public function testTheClosedBridgeCancelsTheServersElicitationWithoutAsking(): void
    {
        $asked = 0;
        $grant = static function () use (&$asked): ApprovalVerdict {
            $asked++;

            return ApprovalVerdict::rejectedByUser('anything at all');
        };
        $backend = EngineBackend::new(new EchoProvider(), 'headless-mcp')
            ->withTools(['mcp__srv__ping' => new McpToolBridge($this->clientFacingElicitation(), $this->pingDescriptor())]);

        $closed = NonInteractive::withoutInteractiveUser($backend);
        self::assertNotSame($backend, $closed, 'an MCP bridge is a closeable tool now');

        // The closure is sticky: even re-binding an approver afterwards (the
        // shape a memoized or late-configured grant would give) must not put
        // the server's question to anyone in a `-p` run.
        $tool = $closed->tools()['mcp__srv__ping'];
        self::assertInstanceOf(McpToolBridge::class, $tool);
        $result = $tool->withPermissionApprover($grant)->execute(['id' => 'h1']);

        self::assertFalse($result->isError());
        self::assertStringContainsString('"action":"cancel"', $result->content());
        self::assertSame(0, $asked, 'a closed -p bridge put a question to an approver');
    }

    public function testTheClosedDispatcherCancelsTheServersElicitationWithoutAsking(): void
    {
        $asked = 0;
        $grant = static function () use (&$asked): ApprovalVerdict {
            $asked++;

            return ApprovalVerdict::rejectedByUser('fast');
        };
        $shape = McpToolShape::derive($this->clientFacingElicitation(), [$this->pingDescriptor()], ['srv' => []], 1);
        $backend = EngineBackend::new(new EchoProvider(), 'headless-mcp')
            ->withTools(['mcp_call' => new McpCallTool($shape)]);

        $closed = NonInteractive::withoutInteractiveUser($backend);
        $tool = $closed->tools()['mcp_call'];
        self::assertInstanceOf(McpCallTool::class, $tool);
        $result = $tool->withPermissionApprover($grant)
            ->execute(['id' => 'h2', 'server' => 'srv', 'tool' => 'ping', 'arguments' => []]);

        self::assertFalse($result->isError());
        self::assertStringContainsString('"action":"cancel"', $result->content());
        self::assertSame(0, $asked, 'a closed -p dispatcher put a question to an approver');
    }

    public function testAnUnclosedBridgeStillPutsTheElicitationToItsApprover(): void
    {
        // The control leg: closing is what cancels, not the mere presence of
        // MCP tools. Without withoutInteractiveUser the bound approver answers.
        $asked = 0;
        $bridge = (new McpToolBridge($this->clientFacingElicitation(), $this->pingDescriptor()))
            ->withPermissionApprover(static function () use (&$asked): ApprovalVerdict {
                $asked++;

                return ApprovalVerdict::rejectedByUser('/tmp/repo/x.txt');
            });

        $result = $bridge->execute(['id' => 'u1']);

        self::assertSame(1, $asked);
        self::assertStringContainsString('"action":"accept"', $result->content());
        self::assertStringContainsString('x.txt', $result->content());
    }

    public function testBackendsWithoutCloseableToolsAreReturnedUntouched(): void
    {
        // Only the two call-capable MCP spellings close — plain neighbours
        // keep the identical backend (Todo proves "no MCP call tool, no rebuild").
        $plain = EngineBackend::new(new EchoProvider(), 'echo')->withTools(['todo' => new Todo()]);
        self::assertSame($plain, NonInteractive::withoutInteractiveUser($plain), 'nothing to close, nothing rebuilt');
    }

    // =========================================================================
    // Harness — a fake stdio-shaped server that answers its own elicitation
    // through whatever desk arrives, the way StdioMcpServer does at spawn time.
    // =========================================================================

    private function pingDescriptor(): McpTool
    {
        return new McpTool(name: 'ping', description: 'ask me', inputSchema: [], serverName: 'srv');
    }

    /**
     * A real McpClient whose only server drives one string-field elicitation:
     * through the per-call desk when one is handed (the interactive route),
     * through a fresh spawn-shaped desk (approver null → cancel) when none is
     * (the closed `-p` route).
     */
    private function clientFacingElicitation(): McpClient
    {
        $server = new class ($this->pingDescriptor()) implements McpServer, AnswersServerRequests {
            public function __construct(private readonly McpTool $tool) {}

            public function start(): void
            {
            }

            public function stop(): void
            {
            }

            public function listTools(): array
            {
                return [$this->tool];
            }

            public function callTool(string $toolName, array $args): array
            {
                // The spawn-time default desk shape: no approver, so the
                // server's question answers cancel — the production -p route.
                return $this->putTheQuestion(new McpServerRequests('', null, null));
            }

            public function callToolAnsweringRequests(string $toolName, array $args, ?\Closure $onWait = null, ?McpServerRequests $serverRequests = null): array
            {
                \PHPUnit\Framework\Assert::assertNotNull($serverRequests, 'a desk-bound call must arrive with its per-call desk');

                return $this->putTheQuestion($serverRequests);
            }

            private function putTheQuestion(McpServerRequests $desk): array
            {
                $reply = ($desk->handler())('elicitation/create', [
                    'requestedSchema' => ['type' => 'object', 'properties' => ['note' => ['type' => 'string', 'description' => 'headless note']], 'required' => ['note']],
                ], false);

                return ['content' => [['type' => 'text', 'text' => json_encode($reply['result'] ?? $reply['error'], JSON_THROW_ON_ERROR)]]];
            }
        };
        $client = new McpClient('/nonexistent/mcp-headless/.mcp.json', unrestricted: true);
        $servers = new \ReflectionProperty($client, 'servers');
        $servers->setAccessible(true);
        $servers->setValue($client, ['srv' => $server]);

        return $client;
    }
}
