<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Support;

use CandyCode\Bundles\MsTeams\Support\Endpoint;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * §8.1/§8.2 transport law: overrides are honored only when they keep the
 * wire honest — https anywhere, plain http only for an opt-in loopback proxy.
 */
final class EndpointTest extends TestCase
{
    public function testEmptyOverridesFallBackToTheMicrosoftDefaults(): void
    {
        self::assertSame(Endpoint::DEFAULT_AUTH, Endpoint::authBase(''));
        self::assertSame(Endpoint::DEFAULT_JWKS, Endpoint::jwksUrl(''));
    }

    public function testAnEncryptedOverrideIsAcceptedForAnyHost(): void
    {
        self::assertSame(
            'https://proxy.example.net/login',
            Endpoint::authBase('https://proxy.example.net/login'),
        );
        self::assertSame(
            'https://mirror.example.net/keys',
            Endpoint::jwksUrl('https://mirror.example.net/keys'),
        );
    }

    public function testPlainHttpToARemoteHostIsRefused(): void
    {
        $this->expectRefusal('http://evil.example/login', 'must be https');
    }

    public function testPlainHttpLoopbackIsRefusedWithoutTheOptIn(): void
    {
        $this->expectRefusal('http://127.0.0.1:8080', 'channels.msteams.transport.insecureLoopbackProxy');
    }

    public function testPlainHttpLoopbackIsAcceptedWithTheOptIn(): void
    {
        self::assertSame(
            'http://localhost:8080/login',
            Endpoint::authBase('http://localhost:8080/login', true),
        );
        self::assertSame(
            'http://[::1]:9000/keys',
            Endpoint::jwksUrl('http://[::1]:9000/keys', true),
        );
    }

    public function testGarbageOverridesAreRefusedLoudly(): void
    {
        $this->expectRefusal('not a url', 'not an absolute https URL');
        $this->expectRefusal('localhost:8080', 'not an absolute https URL');
        $this->expectRefusal('ftp://login.example/', 'must be https');
    }

    private function expectRefusal(string $override, string $needle, bool $optIn = false): void
    {
        try {
            Endpoint::authBase($override, $optIn);
            self::fail('expected a refusal for ' . $override);
        } catch (RuntimeException $error) {
            self::assertStringContainsString($needle, $error->getMessage());
        }
    }
}
