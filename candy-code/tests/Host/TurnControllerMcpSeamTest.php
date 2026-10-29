<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\TurnController;
use SugarCraft\Code\MCP\McpClient;

/**
 * MCP-1 (plan_comparison §3): the Chat submit region threads this session's
 * MCP client into {@see TurnController::userTurnMessage()} as a lazy
 * provider, and the controller turns it into the ContextMentions reader.
 * Both polarities of the seam are pinned here — a route that wires the
 * provider and a route that does not must give DIFFERENT, each-true
 * sentences: the first names the wire's own refusal, the second admits it
 * has no client at all (which is exactly what the headless run and the
 * background-session announcement say today, unanswered).
 */
final class TurnControllerMcpSeamTest extends TestCase
{
    private string $seamRoot;

    protected function setUp(): void
    {
        $this->seamRoot = (string) realpath(sys_get_temp_dir()) . '/sc_mcpseam_' . bin2hex(random_bytes(4));
        mkdir($this->seamRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->seamRoot);
        parent::tearDown();
    }

    public function testAProviderReaderReachesTheWireAndTheWiresRefusalBecomesTheNotice(): void
    {
        $client = new McpClient($this->seamRoot . '/no-config.json', unrestricted: true);

        $turns = TurnController::new();
        [, $notes] = $turns->userTurnMessage(
            'triage @mcp:srv:file://log now',
            true,
            $this->seamRoot,
            null,
            null,
            null,
            static fn (): McpClient => $client,
        );

        self::assertCount(1, $notes);
        self::assertStringContainsString('@mcp:srv:file://log was not attached: Unknown MCP server: srv', $notes[0]->content);
    }

    public function testTheSixArgumentRouteSaysNoClientIsOpenRatherThanSilentlyDropping(): void
    {
        $turns = TurnController::new();
        [, $notes] = $turns->userTurnMessage('triage @mcp:srv:file://log now', true, $this->seamRoot, null, null, null);

        self::assertCount(1, $notes);
        // The reader is absent wholesale on this route (headless, background
        // announcement): the keyword leg's own sentence, before any provider.
        self::assertStringContainsString('no MCP client is open in this run.', $notes[0]->content);
    }

    public function testAProviderThatAnswersNullSaysNoClientIsOpenForThisProject(): void
    {
        $turns = TurnController::new();
        [, $notes] = $turns->userTurnMessage(
            'triage @mcp:srv:file://log now',
            true,
            $this->seamRoot,
            null,
            null,
            null,
            static fn (): ?McpClient => null,
        );

        self::assertCount(1, $notes);
        // A provider exists and resolved "no trusted MCP config this project"
        // — a different fact from a run with no wiring at all, and it says so.
        self::assertStringContainsString('no MCP client is open for this project', $notes[0]->content);
    }

    public function testTheProviderIsConsultedOnlyWhenAMentionActuallyAsks(): void
    {
        $calls = 0;
        $turns = TurnController::new();
        $turns->userTurnMessage(
            'plain prose, no mentions',
            true,
            $this->seamRoot,
            null,
            null,
            null,
            static function () use (&$calls): ?McpClient {
                $calls++;

                return null;
            },
        );

        self::assertSame(0, $calls, 'the provider is lazy — building the reader must not resolve the client');
    }

    /**
     * C-MCP-2.a: both hosts feed the controller's one MCP reader-arming site.
     *
     * Chat hands `mcpClientProvider()` positionally and SessionHost hands its
     * injected provider by name; if either call stops passing it, the served
     * and interactive surfaces silently lose `@mcp:` parity again while the
     * controller tests below stay green — which is exactly the drift this
     * structural pin exists to catch.
     */
    public function testBothHostsFeedTheOneControllerArmingSite(): void
    {
        $chat = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Chat.php');
        $host = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Host/SessionHost.php');

        $chatCall = substr($chat, (int) strpos($chat, '$this->turnController()->userTurnMessage('), 400);
        self::assertStringContainsString('$this->mcpClientProvider(),', $chatCall, 'Chat must keep feeding the controller provider');

        $hostCall = substr($host, (int) strpos($host, '$turns->userTurnMessage('), 400);
        self::assertStringContainsString('mcpProvider: $this->mcpProvider,', $hostCall, 'the served host must keep feeding its injected provider');

        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Host/TurnController.php');
        self::assertSame(1, substr_count($controller, '->withMcpReader('), 'the controller arms exactly one reader for every host');
    }

    /**
     * GAP-AGT-20 (C-AGT-4.d): the roster thread, pinned beside the MCP thread
     * it shares a call window with — same drift class, same shape of answer.
     *
     * Chat hands `agentRoster: $manager` and SessionHost::admitPrompt hands
     * `agentRoster: $agentManager`; if either call stops passing it, the TUI
     * and the served surface silently degrade every `@agent:` send to a
     * "no agent roster is open" notice right beside popups that still offer
     * the rows — the exact popup-vs-scanner agreement the submit docblock
     * promises — while every behavioural test stays green, because the
     * controller itself still answers correctly for the two headless routes
     * that feed it directly. The window is widened to 800 chars past the mcp
     * pin's 400 so a new argument inserted before the roster line cannot
     * silently push it out of the slice.
     */
    public function testBothHostsFeedTheOneControllerRosterArmingSite(): void
    {
        $chat = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Chat.php');
        $host = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Host/SessionHost.php');

        $chatCall = substr($chat, (int) strpos($chat, '$this->turnController()->userTurnMessage('), 800);
        self::assertStringContainsString('agentRoster: $manager', $chatCall, 'Chat must keep feeding the controller the agent roster');

        $hostCall = substr($host, (int) strpos($host, '$turns->userTurnMessage('), 800);
        self::assertStringContainsString('agentRoster: $agentManager', $hostCall, 'the served host must keep feeding its own roster');

        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Host/TurnController.php');
        self::assertSame(1, substr_count($controller, '->withAgentRoster('), 'the controller arms exactly one roster for every host');
    }
}
