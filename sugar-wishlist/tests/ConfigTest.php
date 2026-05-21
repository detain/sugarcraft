<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testParseJsonRoundTrip(): void
    {
        $raw = <<<JSON
        [
          {"name": "production", "host": "prod.example.com", "port": 2222, "user": "deploy"},
          {"name": "staging", "host": "stage.example.com"}
        ]
        JSON;
        $endpoints = Config::parse($raw, 'wishlist.json');
        $this->assertCount(2, $endpoints);
        $this->assertSame('production',         $endpoints[0]->name);
        $this->assertSame('prod.example.com',   $endpoints[0]->host);
        $this->assertSame(2222,                 $endpoints[0]->port);
        $this->assertSame('deploy',             $endpoints[0]->user);
        $this->assertSame(22,                   $endpoints[1]->port);
    }

    public function testParseYamlFlatList(): void
    {
        $raw = <<<YAML
        - name: production
          host: prod.example.com
          port: 2222
          user: deploy

        - name: staging
          host: stage.example.com
        YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(2, $endpoints);
        $this->assertSame('production',         $endpoints[0]->name);
        $this->assertSame('prod.example.com',   $endpoints[0]->host);
        $this->assertSame(2222,                 $endpoints[0]->port);
        $this->assertSame('deploy',             $endpoints[0]->user);
        $this->assertSame('staging',            $endpoints[1]->name);
        $this->assertSame(22,                   $endpoints[1]->port);
    }

    public function testYamlComments(): void
    {
        $raw = <<<YAML
        # top-level comment
        - name: prod        # inline comment
          host: prod.test
        YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame('prod',      $endpoints[0]->name);
        $this->assertSame('prod.test', $endpoints[0]->host);
    }

    public function testYamlScalarTypeCoercion(): void
    {
        $raw = <<<YAML
        - name: a
          host: a.test
          port: 9000
        YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertSame(9000, $endpoints[0]->port);
        $this->assertIsInt($endpoints[0]->port);
    }

    public function testYamlIdentityFileSnakeAndCamel(): void
    {
        $raw = <<<YAML
        - name: a
          host: a.test
          identity_file: /tmp/key1
        - name: b
          host: b.test
          identityFile: /tmp/key2
        YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertSame(['/tmp/key1'], $endpoints[0]->identityFiles);
        $this->assertSame(['/tmp/key2'], $endpoints[1]->identityFiles);
    }

    public function testYamlNestedOptionsList(): void
    {
        // Nested string lists under a value-less key are the only
        // shape of nested mapping the wishlist schema needs — the
        // sample-wishlist.yml exercises this for `options:` so the
        // jumpbox entry can carry through `ProxyJump=...` etc.
        $raw = <<<YAML
        - name: jumpbox
          host: bastion.example.com
          options:
            - ServerAliveInterval=30
            - ProxyJump=gw.example.com
        YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame(
            ['ServerAliveInterval=30', 'ProxyJump=gw.example.com'],
            $endpoints[0]->options,
        );
    }

    public function testYamlSampleConfigParses(): void
    {
        // End-to-end: the bundled sample-wishlist.yml — which the
        // VHS picker recording uses — must parse cleanly.
        $raw = file_get_contents(__DIR__ . '/../examples/sample-wishlist.yml');
        $this->assertNotFalse($raw);
        $endpoints = Config::parse($raw, 'sample-wishlist.yml');
        $this->assertCount(4, $endpoints);
        $this->assertSame('production', $endpoints[0]->name);
        $this->assertSame('jumpbox',    $endpoints[3]->name);
        $this->assertNotEmpty($endpoints[3]->options);
    }

    public function testRejectsTopLevelObjectInJson(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::parse('{"oops": true}', 'wishlist.json');
    }

    public function testRejectsMissingRequiredField(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing required field');
        Config::parse('[{"name": "incomplete"}]', 'wishlist.json');
    }

    public function testLoadFromFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wishlist') . '.json';
        file_put_contents($tmp, '[{"name":"a","host":"a.test"}]');
        try {
            $endpoints = Config::load($tmp);
            $this->assertCount(1, $endpoints);
        } finally {
            unlink($tmp);
        }
    }

    public function testLoadMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::load('/nonexistent/path/wishlist.json');
    }
}
