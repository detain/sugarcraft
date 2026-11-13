<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;

/**
 * Host/IP SSRF deny-list coverage for ImageSource::fromUrl().
 *
 * #1325 hardened the scheme only; a same-scheme (or direct) http request to a
 * private / cloud-metadata IP was still reachable. These tests prove the
 * host/IP deny-list rejects such targets BEFORE any fetch, resolves ALL of a
 * host's addresses to defeat DNS-rebinding (via an injected resolver, so no
 * real network is touched), and honours the opt-in allow-list seam.
 *
 * @covers \SugarCraft\Image\ImageSource
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

    // ---- embedded-address translators (finding 102) -----------------------

    /**
     * @return array<string, array{string, bool}>
     */
    public static function embeddedAddressMatrix(): array
    {
        return [
            // NAT64 well-known 64:ff9b::/96 — a private v4 is refused.
            'nat64 loopback' => ['64:ff9b::7f00:1', true],
            'nat64 rfc1918' => ['64:ff9b::10.0.0.1', true],
            'nat64 metadata' => ['64:ff9b::169.254.169.254', true],
            'nat64 public' => ['64:ff9b::8.8.8.8', false],
            // 6to4 2002::/16 — the embedded v4 lives right after 2002.
            '6to4 loopback' => ['2002:7f00:1::', true],
            '6to4 rfc1918' => ['2002:0a00:0001::', true],
            '6to4 link-local' => ['2002:a9fe:a9fe::', true],
            '6to4 public' => ['2002:0808:0808::', false],
            // Teredo 2001::/32 — BOTH spellings of the tail, and a REAL
            // server/customer mid-word (all-zero middles are the naive
            // text form only).
            'teredo naive loopback' => ['2001::7f00:1', true],
            'teredo rfc4364 loopback' => ['2001:0:1111:2222::a0fe:fffe', true],
            'teredo rfc4364 public' => ['2001::d7f6:f7f7', false],
            'teredo naive public' => ['2001::808:808', false],
            // The family the guard already knew about.
            'mapped loopback' => ['::ffff:127.0.0.1', true],
            'mapped public' => ['::ffff:8.8.8.8', false],
            // Semantics the finding must NOT disturb.
            'unspecified' => ['::', true],
            'v6 loopback' => ['::1', true],
            'v6 link-local' => ['fe80::1', true],
            'v6 ula' => ['fc00::1', true],
            'v6 public google dns' => ['2001:4860:4860::8888', false],
            'v4 this-network' => ['0.0.0.0', true],
            'v4 metadata' => ['169.254.169.254', true],
            'v4 cgnat' => ['100.64.0.1', true],
            'v4 public' => ['93.184.216.34', false],
            '6to4 all-zero embed' => ['2002::', true],
            'nat64 all-zero embed' => ['64:ff9b::', true],
            'teredo all-zero embed' => ['2001::', true],
        ];
    }

    /**
     * @param array<string, array{string, bool}> $matrix
     */
    public static function assertEmbeddedAddressMatrix(array $matrix): void
    {
        $gate = new \ReflectionMethod(ImageSource::class, 'isPrivateOrReservedIp');
        $gate->setAccessible(true);

        foreach ($matrix as $label => [$ip, $blocked]) {
            self::assertSame(
                $blocked,
                (bool) $gate->invoke(null, $ip),
                "{$ip} ({$label}) verdict drifted",
            );
        }
    }

    public function testTheEmbeddedAddressBatteryIsOrderFreeAcrossEveryFamily(): void
    {
        // One gate call must reach the same verdict whether the private v4
        // arrives as a bare quad or smuggled through ::ffff:, NAT64, 6to4
        // or either Teredo spelling — and the unrelated v6 ranges keep
        // their pre-finding semantics untouched.
        self::assertEmbeddedAddressMatrix(self::embeddedAddressMatrix());
    }

    public function testEmbeddedPrivateAddressesAreRefusedEndToEnd(): void
    {
        foreach (self::embeddedAddressMatrix() as [$ip, $blocked]) {
            if (!$blocked) {
                continue;
            }

            try {
                ImageSource::fromUrl("http://[{$ip}]:9/x");
                $this->fail("{$ip} must be refused by the guard");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('blocked private/reserved address', $e->getMessage());
            }
        }
    }

    public function testEmbeddedPublicAddressesStillReachTransport(): void
    {
        foreach (self::embeddedAddressMatrix() as [$ip, $blocked]) {
            if ($blocked) {
                continue;
            }

            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                continue; // v4 accepts are the pre-existing battery's job
            }

            try {
                ImageSource::fromUrl("http://[{$ip}]:9/x");
                $this->fail("{$ip} should not have completed");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringNotContainsString(
                    'blocked private/reserved address',
                    $e->getMessage(),
                    "{$ip} over-blocked",
                );
            } catch (\Throwable) {
                // Transport failure — the guard let it through, as intended.
                $this->addToAssertionCount(1);
            }
        }
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
