<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;

/**
 * Host/IP SSRF deny-list coverage for ImageSource::fromUrl().
 *
 * #1325 hardened the scheme only; a same-scheme (or direct) http request to a
 * private / cloud-metadata IP was still reachable. These tests prove the
 * host/IP deny-list rejects such targets BEFORE any fetch, resolves ALL of a
 * host's addresses to defeat DNS-rebinding (via an injected resolver, so no
 * real network is touched), and honours the opt-in allow-list seam.
 *
 * @covers \SugarCraft\Mosaic\ImageSource
 */
final class ImageSourceSsrfIpTest extends TestCase
{
    protected function tearDown(): void
    {
        // Never leak the test resolver into another test.
        ImageSource::overrideHostResolver(null);
        parent::tearDown();
    }

    // ---- literal-IP deny-list (network-free) ----------------------------

    public function testBlocksDirectRequestToMetadataIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');

        ImageSource::fromUrl('http://169.254.169.254/latest/meta-data/');
    }

    public function testBlocksDirectRequestToLoopback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');

        ImageSource::fromUrl('http://127.0.0.1:9/x');
    }

    public function testBlocksDirectRequestToRfc1918(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');

        ImageSource::fromUrl('http://10.0.0.5/x');
    }

    public function testBlocksDirectRequestToLinkLocalRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');

        // Any 169.254/16 address, not just the metadata IP.
        ImageSource::fromUrl('http://169.254.10.20/x');
    }

    public function testBlocksIpv6Loopback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');

        ImageSource::fromUrl('http://[::1]:9/x');
    }

    // ---- allow-list bypass ----------------------------------------------

    public function testAllowListBypassesGuardForLoopback(): void
    {
        // Allow-listing loopback lets the request past the SSRF guard; it then
        // fails at the transport layer (nothing is listening on port 1) with a
        // DIFFERENT error — proving the guard was bypassed, not that it fired.
        try {
            ImageSource::fromUrl('http://127.0.0.1:1/x', allowedHosts: ['127.0.0.1']);
            $this->fail('Expected a transport failure once the guard is bypassed');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('blocked private/reserved', $e->getMessage());
            $this->assertStringContainsString('Failed to fetch image from URL', $e->getMessage());
        }
    }

    // ---- DNS-rebinding (injected resolver, network-free) ----------------

    public function testBlocksDnsRebindingToMetadataIp(): void
    {
        // A public-looking host that resolves to the metadata IP must be
        // rejected before any fetch — the DNS-rebinding case that a literal
        // scheme/host string check would miss.
        ImageSource::overrideHostResolver(
            static fn (string $h): array => $h === 'rebind.invalid' ? ['169.254.169.254'] : [],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');

        ImageSource::fromUrl('http://rebind.invalid/latest/meta-data/');
    }

    public function testBlocksWhenAnyResolvedAddressIsPrivate(): void
    {
        // Resolving ALL addresses matters: one public + one private must still
        // be rejected (an attacker can add a private A record).
        ImageSource::overrideHostResolver(
            static fn (string $h): array => $h === 'mixed.invalid' ? ['93.184.216.34', '10.0.0.1'] : [],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');

        ImageSource::fromUrl('http://mixed.invalid/x');
    }

    public function testBlocksIpv4MappedIpv6MetadataAddress(): void
    {
        // ::ffff:169.254.169.254 must be unwrapped and rejected too.
        ImageSource::overrideHostResolver(
            static fn (string $h): array => ['::ffff:169.254.169.254'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');

        ImageSource::fromUrl('http://mapped.invalid/x');
    }

    public function testUnresolvableHostFailsClosed(): void
    {
        // A host that resolves to nothing is rejected rather than fetched — it
        // could rebind to an internal address between check and fetch.
        ImageSource::overrideHostResolver(static fn (string $h): array => []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#could not be resolved#');

        ImageSource::fromUrl('http://nowhere.invalid/x');
    }

    public function testAllowListWinsOverPrivateResolution(): void
    {
        // The allow-list is an explicit trust decision: it bypasses the guard
        // even when the host resolves to a private address. The request then
        // fails at transport (the name does not really resolve) — not on the
        // SSRF guard.
        ImageSource::overrideHostResolver(static fn (string $h): array => ['127.0.0.1']);

        try {
            ImageSource::fromUrl('http://internal.invalid:1/x', allowedHosts: ['internal.invalid']);
            $this->fail('Expected a transport failure once the guard is bypassed');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('blocked private/reserved', $e->getMessage());
        }
    }

    public function testPublicHostResolutionIsNotBlocked(): void
    {
        // A host resolving only to public addresses passes the guard and then
        // fails at transport (the name does not really resolve) — confirming
        // the guard does not over-block legitimate public targets.
        ImageSource::overrideHostResolver(static fn (string $h): array => ['93.184.216.34']);

        try {
            ImageSource::fromUrl('http://public.invalid:1/x');
            $this->fail('Expected a transport failure past the guard');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('blocked private/reserved', $e->getMessage());
            $this->assertStringContainsString('Failed to fetch image from URL', $e->getMessage());
        }
    }
}
