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
}
