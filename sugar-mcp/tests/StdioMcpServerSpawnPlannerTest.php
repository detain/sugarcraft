<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\StdioMcpServer;

/**
 * The embedder seams: a spawn planner owning the [command, env] pair handed
 * to proc_open, and a replaceable clientInfo for the initialize handshake.
 *
 * Every pin here drives a REAL child (tests/Fixtures/handshake_mirror.php
 * mirrors the initialize params and the MCP_PROBE_ENV variable back through a
 * tool call), because a seam verified only by reflection over the parent is a
 * seam that could silently ignore what it "injected".
 */
final class StdioMcpServerSpawnPlannerTest extends TestCase
{
    /** @var list<StdioMcpServer> */
    private array $live = [];

    protected function tearDown(): void
    {
        foreach ($this->live as $server) {
            $server->stop();
        }

        $this->live = [];
    }

    private static function mirror(): string
    {
        return __DIR__ . '/Fixtures/handshake_mirror.php';
    }

    /** @param array<string,mixed> $overrides */
    private function mirrorCall(StdioMcpServer $server): array
    {
        $result = $server->callTool('mirror', []);
        $decoded = json_decode((string) ($result['content'][0]['text'] ?? ''), true);

        $this->assertIsArray($decoded, 'the mirror tool did not answer with its JSON payload');

        return $decoded;
    }

    private function spawnWith(?\Closure $planner, array $clientInfo = []): StdioMcpServer
    {
        $server = $clientInfo === []
            ? new StdioMcpServer('probe', PHP_BINARY, [self::mirror()], spawnPlanner: $planner)
            : new StdioMcpServer('probe', PHP_BINARY, [self::mirror()], spawnPlanner: $planner, clientInfo: $clientInfo);
        $this->live[] = $server;
        $server->start();

        return $server;
    }

    public function testTheDefaultHandshakeSendsTheLibraryIdentity(): void
    {
        $params = $this->mirrorCall($this->spawnWith(null))['initializeParams'];

        $this->assertSame(
            ['name' => 'sugar-mcp', 'version' => '0.1.0'],
            $params['clientInfo'] ?? null,
            'the un-injected handshake stopped carrying the library default identity',
        );
    }

    public function testACustomClientInfoRidesTheHandshakeVerbatim(): void
    {
        $params = $this->spawnWith(null, ['name' => 'embedder-x', 'version' => '9.9'])
            ->callTool('mirror', []);
        $decoded = json_decode((string) $params['content'][0]['text'], true);

        $this->assertSame(['name' => 'embedder-x', 'version' => '9.9'], $decoded['initializeParams']['clientInfo'] ?? null);
    }

    public function testThePlannerReceivesNameArgvAndEnv(): void
    {
        $received = null;
        $planner = static function (string $name, array $argv, array $env) use (&$received): array {
            $received = ['name' => $name, 'argv' => $argv, 'env' => $env];

            return [$argv, null];
        };

        $this->spawnWith($planner);

        $this->assertSame('probe', $received['name']);
        $this->assertSame([PHP_BINARY, self::mirror()], $received['argv']);
        $this->assertSame([], $received['env'], 'the planner must see the overrides, not the merged ambient copy');
    }

    public function testThePlannersCommandAndEnvAreUsedVerbatim(): void
    {
        // The planner rewrites the spawn to `/usr/bin/env MCP_PROBE_ENV=… php
        // mirror.php` AND reports env=null: if the returned command rode
        // proc_open verbatim the child sees the variable /usr/bin/env set,
        // and if the default merge sneaked back in the null would be lost —
        // both failure shapes are visible in the mirror's answer.
        $planner = static fn (string $name, array $argv, array $env): array => [
            ['/usr/bin/env', 'MCP_PROBE_ENV=planted-by-planner', ...$argv],
            null,
        ];

        $mirror = $this->mirrorCall($this->spawnWith($planner));

        $this->assertSame('planted-by-planner', $mirror['probeEnv'] ?? null);
        $this->assertSame(['name' => 'sugar-mcp', 'version' => '0.1.0'], $mirror['initializeParams']['clientInfo'] ?? null);
    }

    public function testThePlannerCanSupplyAFullEnvironmentArray(): void
    {
        $planner = static fn (string $name, array $argv, array $env): array => [
            $argv,
            ['MCP_PROBE_ENV' => 'planted-by-env-array', 'PATH' => getenv('PATH')],
        ];

        $mirror = $this->mirrorCall($this->spawnWith($planner));

        $this->assertSame('planted-by-env-array', $mirror['probeEnv'] ?? null, 'an array env from the planner did not reach the child');
    }

    public function testAThrowingPlannerRefusesTheSpawnBeforeAnyChildExists(): void
    {
        $planner = static function (): never {
            throw new \RuntimeException('pre-check refused');
        };

        $server = new StdioMcpServer('probe', PHP_BINARY, [self::mirror()], spawnPlanner: $planner);

        $refused = null;
        try {
            $server->start();
        } catch (\RuntimeException $failure) {
            $refused = $failure->getMessage();
        }

        $this->assertSame('pre-check refused', $refused, 'a throwing planner did not propagate its reason');
        $this->assertFalse($server->isUp(), 'the refused server reports a child anyway');
        $server->stop(); // idempotent on the never-spawned path
    }

    public function testAMalformedSpawnPlanIsANamedProgrammerError(): void
    {
        foreach ([
            'not an array' => static fn (): string => 'php -v',
            'one element' => static fn (): array => [[PHP_BINARY]],
            'three elements' => static fn (): array => [[PHP_BINARY, self::mirror()], null, 'extra'],
            'scalar command' => static fn (): array => [5, null],
            'scalar env' => static fn (): array => [[PHP_BINARY, self::mirror()], 'nope'],
        ] as $label => $planner) {
            $server = new StdioMcpServer('probe', PHP_BINARY, [self::mirror()], spawnPlanner: $planner);

            $raised = null;
            try {
                $server->start();
            } catch (\InvalidArgumentException $failure) {
                $raised = $failure->getMessage();
            } catch (\Throwable $failure) {
                $raised = 'WRONG CLASS: ' . get_class($failure);
            } finally {
                $server->stop();
            }

            $this->assertNotNull($raised, "{$label}: a malformed plan reached proc_open instead of failing fast");
            $this->assertStringContainsString('spawnPlanner', (string) $raised, "{$label}: the error did not name the seam at fault");
        }
    }

    public function testClientInfoIsParsedAtTheBoundary(): void
    {
        foreach ([
            'empty array' => [],
            'empty name' => ['name' => '', 'version' => '1.0'],
            'missing version' => ['name' => 'x'],
            'int name' => ['name' => 7, 'version' => '1.0'],
        ] as $label => $clientInfo) {
            $raised = null;
            try {
                new StdioMcpServer('probe', PHP_BINARY, [], clientInfo: $clientInfo);
            } catch (\InvalidArgumentException $failure) {
                $raised = $failure->getMessage();
            }

            $this->assertNotNull($raised, "{$label}: a malformed clientInfo was accepted at construction");
            $this->assertStringContainsString('clientInfo', (string) $raised);
        }
    }
}
