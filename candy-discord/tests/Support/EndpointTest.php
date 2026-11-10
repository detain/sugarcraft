<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Support;

use CandyCode\Bundles\Discord\Support\Endpoint;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * §7.1 transport law: overrides are honored only when they keep the wire
 * honest — https/wss anywhere, plain http only for an opt-in loopback proxy.
 */
final class EndpointTest extends TestCase
{
    public function testEmptyOverridesFallBackToTheDiscordDefaults(): void
    {
        self::assertSame(Endpoint::DEFAULT_API, Endpoint::apiBase(''));
        self::assertSame(Endpoint::DEFAULT_GATEWAY, Endpoint::gatewayUrl(''));
    }

    public function testEncryptedOverridesAreAcceptedForAnyHost(): void
    {
        self::assertSame(
            'https://proxy.example.net/api',
            Endpoint::apiBase('https://proxy.example.net/api'),
        );
        self::assertSame(
            'wss://gateway.example.net/',
            Endpoint::gatewayUrl('wss://gateway.example.net/'),
        );
    }

    public function testPlainHttpToARemoteHostIsRefused(): void
    {
        $this->expectRefusal('http://evil.example/api', 'must be https or wss');
    }

    public function testPlainHttpLoopbackIsRefusedWithoutTheOptIn(): void
    {
        $this->expectRefusal('http://127.0.0.1:8080', 'channels.discord.transport.insecureLoopbackProxy');
    }

    public function testPlainHttpLoopbackIsAcceptedWithTheOptIn(): void
    {
        self::assertSame(
            'http://localhost:8080/api',
            Endpoint::apiBase('http://localhost:8080/api', true),
        );
        self::assertSame(
            'ws://[::1]:9000/',
            Endpoint::gatewayUrl('ws://[::1]:9000/', true),
        );
    }

    public function testGarbageOverridesAreRefusedLoudly(): void
    {
        $this->expectRefusal('not a url', 'not an absolute https/wss URL');
        $this->expectRefusal('localhost:8080', 'not an absolute https/wss URL');
        $this->expectRefusal('ftp://discord.example/', 'not an absolute https/wss URL');
    }

    private function expectRefusal(string $override, string $needle): void
    {
        try {
            Endpoint::apiBase($override, true);
            self::fail('expected a refusal for ' . $override);
        } catch (RuntimeException $error) {
            self::assertStringContainsString($needle, $error->getMessage());
        }
    }
}
