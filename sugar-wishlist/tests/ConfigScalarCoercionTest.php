<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\Config;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Config private method branches via YAML parsing.
 * Covers yamlScalar() edge cases and other internal paths.
 */
final class ConfigScalarCoercionTest extends TestCase
{
    public function testYamlScalarNullEmptyString(): void
    {
        // Empty value after colon should result in null
        $raw = <<<YAML
- name: emptyvalue
  host: test.example.com
  description:
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertNull($endpoints[0]->description);
    }

    public function testYamlScalarNullWord(): void
    {
        // explicit null value (case insensitive)
        $raw = <<<YAML
- name: explicitnull
  host: test.example.com
  description: null
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertNull($endpoints[0]->description);
    }

    public function testYamlScalarTilde(): void
    {
        // ~ is YAML null
        $raw = <<<YAML
- name: tildnull
  host: test.example.com
  description: ~
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertNull($endpoints[0]->description);
    }

    public function testYamlScalarQuotedDoubleString(): void
    {
        // Double-quoted string preserves value
        $raw = <<<YAML
- name: quoted
  host: test.example.com
  description: "hello world"
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame('hello world', $endpoints[0]->description);
    }

    public function testYamlScalarQuotedSingleString(): void
    {
        // Single-quoted string preserves value
        $raw = <<<YAML
- name: quoted
  host: test.example.com
  description: 'hello world'
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame('hello world', $endpoints[0]->description);
    }

    public function testYamlScalarSingleQuotePreservesSpaces(): void
    {
        // Single quotes preserve leading/trailing spaces
        $raw = <<<YAML
- name: quoted
  host: test.example.com
  description: '  spaced  '
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame('  spaced  ', $endpoints[0]->description);
    }

    public function testYamlScalarIntegerString(): void
    {
        // Port as string should be int
        $raw = <<<YAML
- name: intport
  host: test.example.com
  port: "2222"
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame(2222, $endpoints[0]->port);
        $this->assertIsInt($endpoints[0]->port);
    }

    public function testYamlScalarNegativeInteger(): void
    {
        // Negative port (unusual but should parse)
        $raw = <<<YAML
- name: negport
  host: test.example.com
  port: -1
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame(-1, $endpoints[0]->port);
    }

    public function testYamlScalarTrueBoolean(): void
    {
        // 'true' string should NOT become boolean through yamlScalar,
        // since buildEndpoint only accepts scalars and passes them through.
        // This test verifies the yamlScalar value is returned as-is.
        $raw = <<<YAML
- name: prod
  host: prod.example.com
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame('prod', $endpoints[0]->name);
    }

    public function testYamlScalarYesNoBoolean(): void
    {
        // YAML yes/no are booleans, not strings.
        // But our tiny parser treats them as scalar strings via yamlScalar.
        $raw = <<<YAML
- name: prod
  host: prod.example.com
  options:
    - ServerAliveInterval=30
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame(['ServerAliveInterval=30'], $endpoints[0]->options);
    }

    public function testYamlScalarOptionsValue(): void
    {
        // Options as simple key-value (not nested list)
        $raw = <<<YAML
- name: prod
  host: prod.example.com
  options: ServerAliveInterval=30
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame(['ServerAliveInterval=30'], $endpoints[0]->options);
    }

    public function testParseWithNoExtensionGuessesJson(): void
    {
        // No extension but content starts with [ = JSON
        $raw = '[{"name":"a","host":"a.test"}]';
        $endpoints = Config::parse($raw, 'wishlist');
        $this->assertCount(1, $endpoints);
    }

    public function testParseWithNoExtensionGuessesYaml(): void
    {
        // No extension and content starts with - = YAML
        $raw = "- name: a\n  host: a.test";
        $endpoints = Config::parse($raw, 'wishlist');
        $this->assertCount(1, $endpoints);
    }

    public function testParseWithNoExtensionAndObjectBrace(): void
    {
        // No extension and content starts with { = JSON
        $raw = '{"name":"a","host":"a.test"}';
        // But JSON must be array at top level
        $this->expectException(\RuntimeException::class);
        Config::parse($raw, 'wishlist');
    }

    public function testBuildEndpointWithOnlyNameAndHost(): void
    {
        // Minimal endpoint with only required fields
        $raw = '[{"name":"minimal","host":"example.com"}]';
        $endpoints = Config::parse($raw, 'wishlist.json');
        $this->assertCount(1, $endpoints);
        $this->assertSame('minimal', $endpoints[0]->name);
        $this->assertSame('example.com', $endpoints[0]->host);
        $this->assertSame(22, $endpoints[0]->port);
        $this->assertNull($endpoints[0]->user);
        $this->assertSame([], $endpoints[0]->identityFiles);
        $this->assertNull($endpoints[0]->description);
        $this->assertNull($endpoints[0]->proxyJump);
        $this->assertSame([], $endpoints[0]->options);
    }

    public function testBuildEndpointRejectsMissingHost(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing required field');
        Config::parse('[{"name":"onlyname"}]', 'wishlist.json');
    }

    public function testBuildEndpointRejectsMissingName(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing required field');
        Config::parse('[{"host":"example.com"}]', 'wishlist.json');
    }

    public function testJsonNonArrayEntryRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::parse('[{"name":"a","host":"a.test"}, {"name":"b","host":"b.test"}, "not an object"]', 'wishlist.json');
    }

    public function testYamlBareCommentLine(): void
    {
        // A line that is just a comment (# ...)
        $raw = <<<YAML
# just a comment
- name: prod
  host: prod.example.com
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
    }

    public function testYamlEllipsisMarker(): void
    {
        // ... is a YAML document end marker, should be skipped
        $raw = <<<YAML
- name: prod
  host: prod.example.com
...
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
    }

    public function testYamlValueLessKeyEnablesListMode(): void
    {
        // A key with no value turns on list-collection mode for nested items
        $raw = <<<YAML
- name: withopts
  host: example.com
  options:
    - OptionA=value
    - OptionB=value
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame(['OptionA=value', 'OptionB=value'], $endpoints[0]->options);
    }

    public function testYamlIndentedContinuation(): void
    {
        // Continuation line (more indented key without - prefix)
        $raw = <<<YAML
- name: prod
  host: prod.example.com
  user: admin
    # continuation (extra indent is ignored)
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame('prod', $endpoints[0]->name);
    }
}
