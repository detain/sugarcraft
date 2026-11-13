<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\StdioMcpServer;

/**
 * The resources/prompts convenience quartet: the request→check-error→
 * extract-result walk embedders used to re-implement per transport, tested
 * round trip against the handshake mirror — plus the one refusal door and
 * the shared array-or-error failure vocabulary.
 */
final class StdioMcpServerProviderVerbsTest extends TestCase
{
    private ?StdioMcpServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function spawn(string $fixture): StdioMcpServer
    {
        $this->server = new StdioMcpServer(
            'verbs-fixture',
            PHP_BINARY,
            [__DIR__ . '/Fixtures/' . $fixture],
            startTimeoutSeconds: 10.0,
        );
        $this->server->start();

        return $this->server;
    }

    public function testListResourcesReturnsTheAdvertisedRoster(): void
    {
        $result = $this->spawn('handshake_mirror.php')->listResources();

        self::assertArrayNotHasKey('error', $result);
        self::assertSame('file:///notes/today.md', $result['resources'][0]['uri'] ?? null);
    }

    public function testReadResourceEchoesTheRequestedUriBack(): void
    {
        $result = $this->spawn('handshake_mirror.php')->readResource('file:///notes/probe.md');

        self::assertArrayNotHasKey('error', $result);
        self::assertSame('file:///notes/probe.md', $result['contents'][0]['uri'] ?? null);
        self::assertSame('body of the resource', $result['contents'][0]['text'] ?? null);
    }

    public function testListPromptsReturnsTheAdvertisedTemplates(): void
    {
        $result = $this->spawn('handshake_mirror.php')->listPrompts();

        self::assertArrayNotHasKey('error', $result);
        self::assertSame('recap', $result['prompts'][0]['name'] ?? null);
    }

    public function testGetPromptCarriesItsArgumentsThroughTheWire(): void
    {
        $result = $this->spawn('handshake_mirror.php')->getPrompt('recap', ['lang' => 'de']);

        self::assertArrayNotHasKey('error', $result);
        self::assertSame(
            'recap language: de',
            $result['messages'][0]['content']['text'] ?? null,
            'the argument map must arrive whole on the server side',
        );
    }

    public function testAnArgumentlessPromptStillEncodesAnEmptyObjectNotAList(): void
    {
        // PHP [] would JSON-encode as a list and every conforming server
        // answers -32602; the door must repair it to stdClass first.
        $result = $this->spawn('handshake_mirror.php')->getPrompt('recap');

        self::assertArrayNotHasKey('error', $result);
        self::assertSame('recap language: ?', $result['messages'][0]['content']['text'] ?? null);
    }

    public function testAPositionalPromptArgumentListRefusesAtTheClientDoor(): void
    {
        $result = $this->spawn('handshake_mirror.php')->getPrompt('recap', ['de']);

        self::assertSame(
            ['error' => 'MCP prompts/get failed: arguments must be a JSON object, got a list.'],
            $result,
        );
    }

    public function testAMissingProviderMethodRidesTheSharedErrorVocabulary(): void
    {
        // fork_server knows tools/* only: the JSON-RPC method-not-found
        // error must land as callTool's array-or-error shape, never null.
        $result = $this->spawn('fork_server.php')->listResources();

        self::assertIsString($result['error'] ?? null, 'the failure must be named');
        self::assertStringContainsString('resources/list', $result['error']);
        self::assertStringContainsString('Method not found', $result['error'], 'the server-authored code and message name themselves');
    }
}
