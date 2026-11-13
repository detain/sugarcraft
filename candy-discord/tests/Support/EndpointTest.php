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
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function resumeTrustTable(): array
    {
        return [
            'canonical default' => ['wss://gateway.discord.gg', Endpoint::DEFAULT_GATEWAY, true],
            'v10 subdomain handback' => ['wss://canary-usea.gateway.discord.gg', Endpoint::DEFAULT_GATEWAY, true],
            'deep subdomain' => ['wss://a.b.gateway.discord.gg', Endpoint::DEFAULT_GATEWAY, true],
            'foreign host refused' => ['wss://evil.example.com', Endpoint::DEFAULT_GATEWAY, false],
            'suffix without dot boundary refused' => ['wss://gateway.discord.gg.evil.com', Endpoint::DEFAULT_GATEWAY, false],
            'lookalike hyphen host refused' => ['wss://evil-gateway.discord.gg', Endpoint::DEFAULT_GATEWAY, false],
            'plaintext downgrade refused' => ['ws://gateway.discord.gg', Endpoint::DEFAULT_GATEWAY, false],
            'http refused' => ['http://gateway.discord.gg', Endpoint::DEFAULT_GATEWAY, false],
            'no scheme refused' => ['gateway.discord.gg', Endpoint::DEFAULT_GATEWAY, false],
            'empty refused' => ['', Endpoint::DEFAULT_GATEWAY, false],
            'loopback mirror self-handback trusted' => ['ws://127.0.0.1:9229', 'ws://127.0.0.1:9229', true],
            'loopback handback to other port refused' => ['ws://127.0.0.1:9999', 'ws://127.0.0.1:9229', false],
            'loopback ws to remote wss refused' => ['ws://127.0.0.1:9229', 'wss://gateway.discord.gg', false],
            'encrypted mirror may hand back wss' => ['wss://proxy.example', 'wss://proxy.example', true],
        ];
    }

    /**
     * @dataProvider resumeTrustTable
     */
    public function testResumeUrlTrustFollowsTheDialedAddress(string $candidate, string $dialed, bool $trusted): void
    {
        self::assertSame($trusted, Endpoint::resumeUrlTrusted($candidate, $dialed), $candidate);
    }

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
        $this->expectRefusal('ftp://discord.example/', 'must be https or wss');
    }

    private function expectRefusal(string $override, string $needle, bool $optIn = false): void
    {
        try {
            Endpoint::apiBase($override, $optIn);
            self::fail('expected a refusal for ' . $override);
        } catch (RuntimeException $error) {
            self::assertStringContainsString($needle, $error->getMessage());
        }
    }
}
