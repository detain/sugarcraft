<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\StdioMcpServer;

/**
 * The split identity knobs: clientName:/clientVersion: author the
 * initialize-handshake identity piecewise, so renaming yourself no longer
 * requires replacing the whole map — and the arm you omit rides the same
 * runtime resolution the untouched default uses. The full-replace clientInfo:
 * door stays first-class (deprecate-not-remove), and the two doors refuse to
 * share one wire value.
 */
final class StdioMcpServerClientIdentityTest extends TestCase
{
    private ?StdioMcpServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    /** @param array<string,mixed> $ctorArgs */
    private function spawn(array $ctorArgs = []): StdioMcpServer
    {
        $this->server = new StdioMcpServer(...[
            'name' => 'identity',
            'command' => PHP_BINARY,
            'args' => [__DIR__ . '/Fixtures/handshake_mirror.php'],
            'startTimeoutSeconds' => 10.0,
        ] + $ctorArgs);

        return $this->server;
    }

    /**
     * The version an untouched default advertises, computed the way the
     * library computes it: installed-copy resolution with the literal
     * fallback for the linked monorepo checkout.
     */
    private function resolvedVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)
            && \Composer\InstalledVersions::isInstalled('sugarcraft/sugar-mcp-client')) {
            try {
                $pretty = \Composer\InstalledVersions::getPrettyVersion('sugarcraft/sugar-mcp-client');
            } catch (\OutOfBoundsException) {
                $pretty = null;
            }

            if (is_string($pretty) && $pretty !== '') {
                return $pretty;
            }
        }

        return '0.1.0';
    }

    /** @return array<string,mixed> the clientInfo the server saw on the wire */
    private function advertisedClientInfo(StdioMcpServer $server): array
    {
        $server->start();
        $reply = $server->callTool('mirror', []);
        $text = $reply['content'][0]['text'] ?? '';
        self::assertIsString($text);
        $params = json_decode($text, true)['initializeParams'] ?? null;
        self::assertIsArray($params, 'the mirror echoed the captured initialize params');
        $info = $params['clientInfo'] ?? null;
        self::assertIsArray($info);

        return $info;
    }

    public function testClientNameAloneRidesTheResolvedVersionArm(): void
    {
        $info = $this->advertisedClientInfo($this->spawn(['clientName' => 'acme-agent']));

        self::assertSame('acme-agent', $info['name'], 'the authored name lands on the wire');
        self::assertSame(
            $this->resolvedVersion(),
            $info['version'],
            'the omitted version arm is whatever the untouched default would announce — the author never typed one',
        );
    }

    public function testClientVersionAloneKeepsThePackageName(): void
    {
        $info = $this->advertisedClientInfo($this->spawn(['clientVersion' => '7.2.0-beta']));

        self::assertSame('sugar-mcp-client', $info['name'], 'the omitted name arm stays the package name');
        self::assertSame('7.2.0-beta', $info['version']);
    }

    public function testBothArmsAuthorTheIdentityWithoutRewrite(): void
    {
        $info = $this->advertisedClientInfo($this->spawn([
            'clientName' => 'acme',
            'clientVersion' => '1.2.3',
        ]));

        self::assertSame(['name' => 'acme', 'version' => '1.2.3'], $info);
    }

    public function testTheFullReplaceDoorStillShipsWhatItIsGiven(): void
    {
        $info = $this->advertisedClientInfo($this->spawn([
            'clientInfo' => ['name' => 'legacy', 'version' => '0.0.9'],
        ]));

        self::assertSame(['name' => 'legacy', 'version' => '0.0.9'], $info, 'clientInfo: stays a first-class door');
    }

    public function testTheTwoDoorsRefuseToShareOneWireValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/mutually exclusive/');

        $this->spawn(['clientInfo' => ['name' => 'a', 'version' => 'b'], 'clientName' => 'c']);
    }

    public function testABlankSplitArmIsRefusedAtConstruction(): void
    {
        foreach (['clientName', 'clientVersion'] as $knob) {
            try {
                $this->spawn([$knob => '']);
                self::fail("{$knob}: accepted the empty string");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('empty string', $e->getMessage());
            }
        }
    }
}
