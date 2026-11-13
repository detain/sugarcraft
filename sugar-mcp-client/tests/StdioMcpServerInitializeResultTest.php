<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\StdioMcpServer;

/**
 * The kept InitializeResult: what the handshake used to judge and discard is
 * now the connection's identity (serverInfo) and its gate (capabilities),
 * readable after start() AND after stop() like the rest of the post-mortem
 * evidence — with a deliberately tolerant store: a server that omits a
 * spec-required member boots (the handshake already proved it can negotiate)
 * and initializeResultDefects() names what is missing.
 */
final class StdioMcpServerInitializeResultTest extends TestCase
{
    private ?StdioMcpServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function spawn(bool $bare = false): StdioMcpServer
    {
        $this->server = new StdioMcpServer(
            'init-result',
            PHP_BINARY,
            [__DIR__ . '/Fixtures/handshake_mirror.php'],
            startTimeoutSeconds: 10.0,
            env: $bare ? ['MIRROR_BARE_INITIALIZE' => '1'] : [],
        );

        return $this->server;
    }

    public function testTheHandshakeKeepsTheAdvertisedIdentityAndCapabilities(): void
    {
        $server = $this->spawn();
        $server->start();

        self::assertSame(
            ['name' => 'fixture-mirror', 'version' => '0.0.1'],
            $server->serverInfo(),
            'serverInfo is handed out exactly as the server authored it — untrusted text, sanitizing is law for the consumer',
        );
        $caps = $server->serverCapabilities();
        self::assertIsArray($caps);
        self::assertSame(['tools', 'resources', 'prompts'], array_keys($caps));
        self::assertSame([], $server->initializeResultDefects(), 'a whole handshake reports no defects');
    }

    public function testTheKeptResultSurvivesStop(): void
    {
        $server = $this->spawn();
        $server->start();
        $server->stop();

        self::assertSame(
            'fixture-mirror',
            $server->serverInfo()['name'] ?? null,
            'which server was this stays answerable after teardown — same post-mortem law as stderrTail',
        );
        self::assertIsArray($server->serverCapabilities());
    }

    public function testCapabilityPresenceGatesProviderTraffic(): void
    {
        $server = $this->spawn();
        $server->start();

        $advertisesResources = ($server->serverCapabilities()['resources'] ?? null) !== null;
        self::assertTrue($advertisesResources, 'the mirror advertises resources, so the gate opens');

        $result = $server->listResources();
        self::assertArrayNotHasKey('error', $result, 'gating on the kept capabilities before trying resources/* is the law this test demos');
    }

    public function testABareInitializeBootsTolerantlyAndNamesBothDefects(): void
    {
        $server = $this->spawn(bare: true);
        $server->start(); // tolerant store: the handshake answered, so the server boots

        self::assertNull($server->serverInfo());
        self::assertNull($server->serverCapabilities());

        $defects = $server->initializeResultDefects();
        self::assertCount(2, $defects);
        self::assertStringContainsString('serverInfo', $defects[0]);
        self::assertStringContainsString('capabilities', $defects[1]);
    }

    public function testNoKeptResultBeforeTheFirstStart(): void
    {
        $server = $this->spawn();

        self::assertNull($server->serverInfo(), 'unstarted means no identity, not an error');
        self::assertNull($server->serverCapabilities());
        self::assertSame([], $server->initializeResultDefects(), 'no handshake means no omissions to report');
    }
}
