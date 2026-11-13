<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\McpMessage;
use SugarCraft\McpClient\McpRouter;
use SugarCraft\McpClient\McpServer;
use SugarCraft\McpClient\McpTool;

/**
 * Lifted from candy-code/tests/MCP/McpRouterTest with the one substitution
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

            public function request(string $method, ?array $params = null, ?float $deadline = null): ?McpMessage
            {
                // The router filters rosters; it never proxies raw RPC. A
                // leg that calls these is a behavior change, not a typo.
                throw new \BadMethodCallException('raw request() is not part of routing');
            }

            public function notify(string $method, ?array $params = null, ?float $deadline = null): bool
            {
                throw new \BadMethodCallException('raw notify() is not part of routing');
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
                // The fail-loud WHY rides the message itself (fold of
                // the candy-code wording): the refusal is a policy, not a
                // parse error, and the operator reading the log gets the
                // argument for it.
                self::assertStringContainsString(
                    'a server allowlist is refused rather than read as a rule that matches nothing.',
                    $thrown->getMessage(),
                );
            }
        }
    }

    /**
     * The deny law, exposed as a public static — folded from the candy-code
     * original. Only the exact action
     * string "deny" acts; patterns are `fnmatch` against RAW config keys.
     */
    public function testServerDeniedStaticSemantics(): void
    {
        self::assertTrue(McpRouter::serverDenied('untrusted_x', ['untrusted_*' => 'deny']));
        self::assertTrue(McpRouter::serverDenied('scratch', ['scratch' => 'deny']));
        self::assertFalse(McpRouter::serverDenied('good', ['untrusted_*' => 'deny']));
        self::assertFalse(McpRouter::serverDenied('untrusted_x', ['untrusted_*' => 'allow']));
        self::assertFalse(McpRouter::serverDenied('untrusted_x', []));
    }

    public function testServerDeniedRefusesShapesThatAreNotPatternStrings(): void
    {
        // Pin flipped 2026-11-04 (fix wave, review N1): a config
        // {"64":"deny"} DECODES to an int key, and refusing to read it made
        // the deny rule for a numerically-named server silently inert — a
        // silent WIDENING, exactly the boundary move this class refuses on
        // the allow side. The numeric key is now read at its string
        // spelling; an empty pattern still never matches.
        self::assertTrue(McpRouter::serverDenied('64', [64 => 'deny']), 'a numeric key is its server\'s deny rule');
        self::assertFalse(McpRouter::serverDenied('', ['' => 'deny']), 'an empty pattern is not a rule');

        // The instance filter delegates to the same law: the routed view
        // now honours the numeric deny rule (review N1).
        $servers = ['64' => self::server('64', ['t'])];
        self::assertSame([], self::toolNames($servers, [], [64 => 'deny']));
    }

    public function testTheCrushConfigSpellingOfStringDenySpecsBlocks(): void
    {
        // candy-code feeds deny rules as pattern => action STRING (its
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

        // Measured: an array spec with NO usable string action is the
        // same silent-widening failure as garbage — it reads as an inert rule.
        // The constructor docblock always promised "Any other shape throws".
        foreach ([42, null, ['action' => 42], new \stdClass(), [], ['action' => null]] as $bad) {
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

    /**
     * Review m12 (fix wave): the deny side fnmatches EVERY pattern, the
     * allow side globs only `*` entries and otherwise compares exactly.
     * The asymmetry is deliberate (denial errs broad, admission errs
     * strict) and is now pinned rather than contradicted by prose.
     */
    public function testTheTwoGlobDialectsAreDeliberate(): void
    {
        self::assertTrue(McpRouter::serverDenied('qq', ['q?' => 'deny']), 'the deny side fnmatches');
        self::assertFalse(McpRouter::serverAllowed('qq', ['q?']), 'the allow side matches `q?` literally');
        self::assertTrue(McpRouter::serverAllowed('qq', ['q*']), 'the allow side globs star entries');
    }

    /**
     * Review N1 (fix wave): PHP normalises the numeric-looking key "0" to
     * an int; what the fix guarantees is that a numeric server name stays
     * REACHABLE — never skipped by a TypeError or a silent miss.
     *
     * Review C-1 (fix wave MC-1) re-where the boundary: the map spelling
     * {"0":"deny"} decodes to the PHP list [0 => 'deny'], and the list law
     * reads that as the PATTERN "deny" (the collision is documented on
     * normalizeDenyActions()). Digit-named servers are addressed through the
     * list notation ['0'], whose entries carry the literal name.
     */
    public function testNumericServerNamesAreAddressable(): void
    {
        $servers = ['0' => self::server('0', ['zero'])];

        // PHP cannot carry a decimal-integer key as a string; the view
        // honestly returns the int normalisation (now documented in the
        // @return), and what the fix guarantees is that the NAME stays
        // reachable — never skipped by a TypeError or a silent miss.
        self::assertSame([0], array_keys((new McpRouter($servers))->resolveAllowedServers()));
        self::assertSame(['zero'], self::toolNames($servers, ['0']), 'an allow-list entry "0" admits server 0');
        self::assertSame([], self::toolNames($servers, [], ['0']), 'the list rule ["0"] reaches server 0');
        // The documented collision: the same decoded bytes as a one-entry
        // map read as the pattern "deny", not as a rule for server "0".
        self::assertSame(['zero'], self::toolNames($servers, [], ['0' => 'deny']), 'a list-shaped map is the pattern side, per the collision law');
        self::assertTrue(McpRouter::serverDenied('deny', ['0' => 'deny']), 'and it denies a server literally named "deny"');
    }

    /**
     * Review C-1 (fix wave MC-1): the README documents deny patterns as a
     * plain list of globs — `['scratch', 'untrusted_*']`. That notation used
     * to fail OPEN: the list's numeric keys made the VALUES act as actions
     * ('scratch' ≠ 'deny'), so every listed rule read as inert and the
     * boundary silently widened — the exact failure this class throws for
     * elsewhere. A plain list now denies every entry it names, on both doors.
     */
    public function testAPlainListOfDenyPatternsDeniesEveryEntry(): void
    {
        $servers = [
            'fs' => self::server('fs', ['f1']),
            'scratch' => self::server('scratch', ['s1']),
            'untrusted_x' => self::server('untrusted_x', ['u1']),
            'good' => self::server('good', ['g1']),
        ];

        self::assertSame(
            ['f1', 'g1'],
            self::toolNames($servers, [], ['scratch', 'untrusted_*']),
            'the list denies exact names and globs alike',
        );

        // The public static — the door downstream gates read — honours the
        // same notation.
        self::assertTrue(McpRouter::serverDenied('scratch', ['scratch']));
        self::assertTrue(McpRouter::serverDenied('untrusted_x', ['untrusted_*']));
        self::assertFalse(McpRouter::serverDenied('good', ['scratch', 'untrusted_*']));

        // The map spelling is byte-unchanged (its pins live in the sibling
        // tests; this shows the two notations deny the same server).
        self::assertSame(
            self::toolNames($servers, [], ['scratch', 'untrusted_*']),
            self::toolNames($servers, [], ['scratch' => 'deny', 'untrusted_*' => 'deny']),
        );
    }

    public function testADenyListEntryThatIsNotAStringIsRefused(): void
    {
        $servers = ['x' => self::server('x', ['t'])];

        // Fail-fast law at both doors: a list entry that cannot be a pattern
        // throws, mirroring the map side's garbage-spec refusal.
        foreach ([[42], [null], ['ok', 7]] as $index => $badList) {
            $caught = null;
            try {
                new McpRouter($servers, $badList);
            } catch (\RuntimeException $thrown) {
                $caught = $thrown;
            }

            self::assertNotNull($caught, 'deny list ' . json_encode($badList) . ' (case ' . $index . ') must be refused');
            self::assertStringContainsString('deny pattern list entry', $caught->getMessage());

            $staticCaught = null;
            try {
                McpRouter::serverDenied('x', $badList);
            } catch (\RuntimeException $thrown) {
                $staticCaught = $thrown;
            }

            self::assertNotNull($staticCaught, 'the static refuses the same shape');
            self::assertStringContainsString('deny pattern list entry', $staticCaught->getMessage());
        }
    }
}
