<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\StdioMcpServer;

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

    /**
     * Pin flipped 2026-11-04 (fix wave, review m16): the shipped default
     * identity now resolves the package version from Composer metadata
     * instead of a hand-typed literal, so the expectation derives from the
     * same source (and lands on the fallback in a bare checkout).
     *
     * @return array{name:string,version:string}
     */
    private static function defaultIdentityExpected(): array
    {
        $version = '0.1.0';
        if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('sugarcraft/sugar-mcp-client')) {
            try {
                $pretty = \Composer\InstalledVersions::getPrettyVersion('sugarcraft/sugar-mcp-client');
            } catch (\OutOfBoundsException) {
                $pretty = null;
            }
            if (is_string($pretty) && $pretty !== '') {
                $version = $pretty;
            }
        }

        return ['name' => 'sugar-mcp-client', 'version' => $version];
    }

    public function testTheDefaultHandshakeSendsTheLibraryIdentity(): void
    {
        $params = $this->mirrorCall($this->spawnWith(null))['initializeParams'];

        $this->assertSame(
            self::defaultIdentityExpected(),
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
        $this->assertSame(self::defaultIdentityExpected(), $mirror['initializeParams']['clientInfo'] ?? null);
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

    // =========================================================================
    // Declarative env knobs (S-16): inherited → envRemove → env, curatedEnv exclusive
    // =========================================================================

    /** @param array<string,mixed> $ctorArgs */
    private function spawnPlain(array $ctorArgs): StdioMcpServer
    {
        $server = new StdioMcpServer(...['name' => 'probe', 'command' => PHP_BINARY, 'args' => [self::mirror()]] + $ctorArgs);
        $this->live[] = $server;
        $server->start();

        return $server;
    }

    public function testEnvRemoveDropsAnAmbientNameWithoutAnyClosure(): void
    {
        putenv('MCP_TEST_SECRET=hunter2');
        try {
            $present = $this->mirrorCall($this->spawnPlain([]));
            $this->assertTrue($present['secretPresent'] ?? null, 'the fixture must see the ambient secret before removal');

            $scrubbed = $this->mirrorCall($this->spawnPlain(['envRemove' => ['MCP_TEST_SECRET']]));
            $this->assertFalse($scrubbed['secretPresent'] ?? null, 'one ctor argument removes the name the closure used to cost');
        } finally {
            putenv('MCP_TEST_SECRET');
        }
    }

    public function testEnvRemoveStripsBeforeTheEnvOverlayApplies(): void
    {
        putenv('MCP_PROBE_ENV=ambient');
        try {
            // The chain order is observable: remove runs first, env lands last.
            $mirror = $this->mirrorCall($this->spawnPlain([
                'envRemove' => ['MCP_PROBE_ENV'],
                'env' => ['MCP_PROBE_ENV' => 'rearmed'],
            ]));
            $this->assertSame('rearmed', $mirror['probeEnv'] ?? null, 'remove must run before the overlay, not after');
        } finally {
            putenv('MCP_PROBE_ENV');
        }
    }

    public function testCuratedEnvReplacesTheInheritedEnvironmentEntirely(): void
    {
        putenv('MCP_TEST_SECRET=hunter2');
        try {
            $mirror = $this->mirrorCall($this->spawnPlain([
                'curatedEnv' => ['MCP_PROBE_ENV' => 'curated', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
            ]));
            $this->assertSame('curated', $mirror['probeEnv'] ?? null);
            $this->assertFalse($mirror['secretPresent'] ?? null, 'curatedEnv means NOTHING inherited rides along');
        } finally {
            putenv('MCP_TEST_SECRET');
        }
    }

    public static function malformedEnvKnobProvider(): array
    {
        return [
            'keyed envRemove list' => [['envRemove' => ['X' => 'Y']], 'must be a list'],
            'non-string envRemove entry' => [['envRemove' => [7]], 'non-empty variable name'],
            'empty envRemove name' => [['envRemove' => ['']], 'non-empty variable name'],
            'duplicate envRemove name' => [['envRemove' => ['A', 'A']], 'twice'],
            'curatedEnv with a non-string value' => [['curatedEnv' => ['P' => 5]], 'string values'],
            'curatedEnv alongside env' => [['curatedEnv' => ['P' => 'v'], 'env' => ['Q' => 'w']], 'unread'],
            'curatedEnv alongside envRemove' => [['curatedEnv' => ['P' => 'v'], 'envRemove' => ['Q']], 'unread'],
        ];
    }

    /** @param array<string,mixed> $ctorArgs */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedEnvKnobProvider')]
    public function testTheKnobGrammarRefusesAtConstruction(array $ctorArgs, string $needle): void
    {
        $thrown = null;
        try {
            new StdioMcpServer('probe', PHP_BINARY, [self::mirror()], ...$ctorArgs);
        } catch (\InvalidArgumentException $e) {
            $thrown = $e->getMessage();
        }

        $this->assertIsString($thrown, 'the malformed knob must refuse before any child exists');
        $this->assertStringContainsString($needle, $thrown);
    }

    public function testAPlannerCombinedWithADeclarativeKnobRefusesInsteadOfBeingIgnored(): void
    {
        foreach ([['envRemove' => ['X']], ['curatedEnv' => ['P' => 'v']]] as $i => $knob) {
            $thrown = null;
            try {
                new StdioMcpServer(...['name' => 'probe', 'command' => PHP_BINARY, 'args' => [self::mirror()], 'spawnPlanner' => static fn (): array => [[PHP_BINARY], null]] + $knob);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e->getMessage();
            }

            $this->assertIsString($thrown, "knob #{$i} beside a planner must refuse, not silently no-op");
            $this->assertStringContainsString('owns the child environment', $thrown);
        }
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
            // A shell string makes the server /bin/sh's grandchild, which
            // stop() signals past — the orphan the class docblock measured.
            'shell-string command' => static fn (): array => [escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::mirror()), null],
            'empty argv' => static fn (): array => [[], null],
            'non-string argv part' => static fn (): array => [[PHP_BINARY, 5], null],
            'keyed argv' => static fn (): array => [['bin' => PHP_BINARY, 'script' => self::mirror()], null],
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
