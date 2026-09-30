<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\McpRouter;
use SugarCraft\Mcp\McpServer;
use SugarCraft\Mcp\McpTool;

/**
 * Lifted from sugar-crush/tests/MCP/McpRouterTest with the one substitution
 * the port requires: where the product passes an AgentPreset, this library
 * receives the already-extracted allow-list array. All ordering, glob and
 * fail-loud semantics are pinned unchanged.
 */
final class McpRouterTest extends TestCase
{
    /** @param list<string> $toolNames */
    private static function server(string $name, array $toolNames): McpServer
    {
        return new class ($name, $toolNames) implements McpServer {
            /** @param list<string> $toolNames */
            public function __construct(
                private readonly string $serverName,
                private readonly array $toolNames,
            ) {
            }

            public function start(): void
            {
            }

            public function stop(): void
            {
            }

            public function listTools(): array
            {
                return array_map(
                    fn (string $tool): McpTool => new McpTool($tool, '', [], $this->serverName),
                    $this->toolNames,
                );
            }

            /** @return array<string,mixed> */
            public function callTool(string $name, array $arguments): array
            {
                return [];
            }
        };
    }

    /** @param array<string,McpServer> $servers @param list<string> $allowList */
    private static function toolNames(array $servers, array $allowList = [], array $deny = []): array
    {
        $names = [];
        foreach ((new McpRouter($servers, $deny))->resolveAllowedTools($allowList) as $tool) {
            $names[] = $tool->name;
        }

        return $names;
    }

    public function testAnEmptyAllowListSeesEveryServer(): void
    {
        $servers = ['alpha' => self::server('alpha', ['a1']), 'beta' => self::server('beta', ['b1'])];

        self::assertSame(['a1', 'b1'], self::toolNames($servers, []));
    }

    public function testTheAllowListRestrictsPerServer(): void
    {
        $servers = ['alpha' => self::server('alpha', ['a1']), 'beta' => self::server('beta', ['b1'])];

        self::assertSame(['b1'], self::toolNames($servers, ['beta']));
    }

    public function testGlobEntriesFnmatch(): void
    {
        $servers = [
            'trusted-docs' => self::server('trusted-docs', ['d1']),
            'trusted-code' => self::server('trusted-code', ['c1']),
            'rogue' => self::server('rogue', ['r1']),
        ];

        self::assertSame(['d1', 'c1'], self::toolNames($servers, ['trusted-*']));
    }

    public function testAnAllowListNamingUnknownServersYieldsNothing(): void
    {
        $servers = ['alpha' => self::server('alpha', ['a1'])];

        self::assertSame([], self::toolNames($servers, ['nope']));
    }

    public function testDenyPatternsBlockMatchingServers(): void
    {
        $servers = ['dangerous' => self::server('dangerous', ['x']), 'safe' => self::server('safe', ['y'])];

        self::assertSame(['y'], self::toolNames($servers, [], ['dangerous' => ['action' => 'deny']]));
    }

    public function testDenyBeatsAnExplicitAllow(): void
    {
        // Ordering law: a preset cannot out-vote an operator deny rule.
        $servers = ['dangerous' => self::server('dangerous', ['x']), 'safe' => self::server('safe', ['y'])];

        self::assertSame(
            ['y'],
            self::toolNames($servers, ['dangerous', 'safe'], ['dangerous' => ['action' => 'deny']]),
        );
    }

    public function testMultipleDenyPatternsCompose(): void
    {
        $servers = [
            'a' => self::server('a', ['ta']),
            'b' => self::server('b', ['tb']),
            'c' => self::server('c', ['tc']),
        ];

        self::assertSame(
            ['tc'],
            self::toolNames($servers, [], ['a' => ['action' => 'deny'], 'b' => ['action' => 'deny']]),
        );
    }

    public function testANonMatchingGlobLeavesServersAlone(): void
    {
        $servers = ['safe' => self::server('safe', ['y'])];

        self::assertSame(['y'], self::toolNames($servers, [], ['other-*' => ['action' => 'deny']]));
    }

    public function testPatternSpecsWhoseActionIsNotDenyAreIgnored(): void
    {
        $servers = ['alpha' => self::server('alpha', ['a1'])];

        self::assertSame(['a1'], self::toolNames($servers, [], ['alpha' => ['action' => 'warn']]));
    }

    public function testResolveAllowedServersKeepsKeys(): void
    {
        $servers = [
            'docs-search' => self::server('docs-search', ['d']),
            'trusted_docs' => self::server('trusted_docs', ['t']),
        ];
        $router = new McpRouter($servers, ['docs-*' => ['action' => 'deny']]);

        self::assertSame(['trusted_docs'], array_keys($router->resolveAllowedServers([])));

        $routerNoDeny = new McpRouter($servers);
        self::assertSame(['docs-search', 'trusted_docs'], array_keys($routerNoDeny->resolveAllowedServers([])));
        self::assertSame(['docs-search'], array_keys($routerNoDeny->resolveAllowedServers(['docs-search'])));
    }

    public function testEmptyRegistryResolvesToEmpty(): void
    {
        $router = new McpRouter([]);

        self::assertSame([], $router->resolveAllowedTools(['anything']));
        self::assertSame([], $router->resolveAllowedServers([]));
    }

    public function testServersWithoutToolsContributeNothingButSurviveResolution(): void
    {
        $servers = ['empty' => self::server('empty', []), 'full' => self::server('full', ['f'])];

        self::assertSame(['f'], self::toolNames($servers));
        self::assertCount(2, (new McpRouter($servers))->resolveAllowedServers([]));
    }

    public function testServerAllowedStaticSemantics(): void
    {
        self::assertTrue(McpRouter::serverAllowed('anything', []), 'empty list is allow-all');
        self::assertTrue(McpRouter::serverAllowed('exact', ['exact']));
        self::assertFalse(McpRouter::serverAllowed('exact', ['other']));
        self::assertTrue(McpRouter::serverAllowed('fs-prod', ['fs-*']));
        self::assertFalse(McpRouter::serverAllowed('other', ['fs-*']));
    }

    public function testTheThrowingShapeDirectly(): void
    {
        foreach ([[''], [null], [42], [[]]] as $bad) {
            try {
                McpRouter::serverAllowed('svc', $bad);
                self::fail('allow-list entry ' . json_encode($bad[0]) . ' must throw');
            } catch (\RuntimeException $thrown) {
                self::assertStringContainsString('non-empty string', $thrown->getMessage());
            }
        }
    }

    public function testTheCrushConfigSpellingOfStringDenySpecsBlocks(): void
    {
        // sugar-crush feeds deny rules as pattern => action STRING (its
        // McpRouter docblock and McpClient::setDenyPatterns(array<string,
        // string>)). Reading that spelling as a spec array turned every
        // incoming deny rule inert — a silently WIDENED boundary, the exact
        // failure this class throws for on the allow side. It must deny.
        $servers = [
            'fs-read' => self::server('fs-read', ['r']),
            'fs-write' => self::server('fs-write', ['w']),
            'other' => self::server('other', ['o']),
        ];

        self::assertSame(['o'], self::toolNames($servers, [], ['fs-*' => 'deny']));
        // Both spellings compose in one map, and the deny still out-votes an
        // explicit allow.
        self::assertSame(
            ['o'],
            self::toolNames($servers, ['fs-read', 'other'], ['fs-read' => 'deny', 'fs-*' => ['action' => 'deny']]),
        );
    }

    public function testANonDenyStringActionIsIgnored(): void
    {
        // 'warn'-style entries ride the same map; only 'deny' acts.
        $servers = ['alpha' => self::server('alpha', ['a1'])];

        self::assertSame(['a1'], self::toolNames($servers, [], ['alpha' => 'warn']));
    }

    public function testAGarbageDenySpecIsRefusedAtConstruction(): void
    {
        // Fail-fast law: an unrecognised spec shape must halt at the boundary,
        // not filter as if absent at resolution time.
        $servers = ['x' => self::server('x', ['t'])];

        foreach ([42, null, ['action' => 42], new \stdClass()] as $bad) {
            $caught = null;
            try {
                new McpRouter($servers, ['x' => $bad]);
            } catch (\RuntimeException $thrown) {
                $caught = $thrown;
            }

            self::assertNotNull($caught, 'deny spec ' . get_debug_type($bad) . ' must be refused');
            self::assertStringContainsString('deny pattern "x"', $caught->getMessage());
        }
    }
}
