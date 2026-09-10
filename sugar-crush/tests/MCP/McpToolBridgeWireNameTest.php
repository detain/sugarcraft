<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\McpToolBridge;

/**
 * E665 (E42(b) surfacing): the wire-name spelling a permission rule must carry
 * is now public API on the producer, so the `crush mcp` listing and
 * docs/SETTINGS.md explain the SAME function the router applies — these tests
 * pin that function's contract: escape table, hyphen kept, injectivity, and the
 * prefix composition {@see McpToolBridge::name()} itself uses.
 */
final class McpToolBridgeWireNameTest extends TestCase
{
    public function testBytesOutsideTheKeptSetBecomeUnderscorePlusUppercaseHex(): void
    {
        self::assertSame('github_2Ecom_2Ffoo', McpToolBridge::sanitize('github.com/foo'));
        self::assertSame('my_20server', McpToolBridge::sanitize('my server'));
        self::assertSame('_5Fleading', McpToolBridge::sanitize('_leading'));
    }

    public function testTheHyphenIsKeptBecauseRealServerKeysAreFullOfIt(): void
    {
        self::assertSame('aws-kb-retrieval', McpToolBridge::sanitize('aws-kb-retrieval'));
    }

    public function testAnIdentitySpelledKeyIsReturnedUnchanged(): void
    {
        // The exact condition the `crush mcp` listing uses to SKIP printing a
        // mapping line: plain keys add no noise.
        self::assertSame('git', McpToolBridge::sanitize('git'));
    }

    public function testTheEscapedUnderscoreKeepsTheSeparatorUnimitable(): void
    {
        // E42(a)'s collision: `a__b` + `c` vs `a` + `b__c` composed one name for
        // two tools when `__` was legal inside a segment. The escape means no
        // sanitised segment can contain `__` at all.
        self::assertStringNotContainsString('__', McpToolBridge::sanitize('a__b'));
        self::assertStringNotContainsString('__', McpToolBridge::sanitize('b__c'));
        self::assertNotSame(McpToolBridge::sanitize('a__b'), McpToolBridge::sanitize('a_b__c'));
    }

    public function testDistinctRawKeysSanitiseDistinctly(): void
    {
        $keys = ['a.b', 'a_b', 'a-b', 'ab'];
        $seen = [];
        foreach ($keys as $key) {
            $wire = McpToolBridge::sanitize($key);
            self::assertArrayNotHasKey($wire, $seen, "two distinct keys sanitise to {$wire}");
            $seen[$wire] = $key;
        }
    }

    public function testWireServerPrefixCarriesTheSeparatorOnBothSides(): void
    {
        self::assertSame('mcp__git__', McpToolBridge::wireServerPrefix('git'));
        self::assertSame(
            'mcp__github_2Ecom_2Ffoo__',
            McpToolBridge::wireServerPrefix('github.com/foo'),
        );
    }

    public function testWireServerPrefixIsTheLiteralLeadingPartOfTheComposedName(): void
    {
        // The listing prints `prefix<tool>` and claims a rule written that way
        // matches; that claim holds only if the prefix is exactly what name()
        // puts before the tool segment.
        $server = 'github.com/foo';
        $tool = 'list issues';
        $name = McpToolBridge::NAME_PREFIX
            . McpToolBridge::sanitize($server)
            . '__'
            . McpToolBridge::sanitize($tool);
        self::assertStringStartsWith(McpToolBridge::wireServerPrefix($server), $name);
    }
}
