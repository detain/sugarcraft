<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\PublicUrlGuard;

/**
 * The SSRF rule itself, in its own home.
 *
 * The two range-list cases here ARE the cases `ToolSecurityTest` used to carry
 * as `testWebFetchRefusesEveryNonPublicRangeAsAResolverAnswer` and
 * `testWebFetchStillAdmitsPublicUnicastBesideTheNewRanges` — same address
 * lists, moved with the rule when C-CTX-4.d extracted it. `ToolSecurityTest`
 * keeps its end-to-end WebFetch cases on purpose: they drive the real tool and
 * therefore pin that WebFetch's delegation to this guard still holds.
 *
 * The `fetch()` cases pin the instruction-source arm: every refusal the guard
 * owes plus the two tightenings (https-only, no URL credentials) that belong to
 * a channel with no permission modal behind it. No case here opens a socket —
 * the resolver and transfer seams are the whole point of the shape.
 */
final class PublicUrlGuardTest extends TestCase
{
    // ------------------------------------------------------------------
    // The range list (audit F-W1, 2026-10-01), moved with the rule.
    // ------------------------------------------------------------------

    public function testTheGuardRefusesEveryNonPublicRangeAsAResolverAnswer(): void
    {
        $nonPublic = [
            '100.64.0.1',              // CGNAT / Tailscale, low edge
            '100.100.100.200',         // Alibaba Cloud ECS metadata
            '100.127.255.254',         // CGNAT, high edge
            '192.0.0.170',             // IETF protocol assignments (NAT64 discovery)
            '198.18.0.1',              // benchmarking
            '198.19.255.254',          // benchmarking, high edge
            '224.0.0.1',               // multicast
            '239.255.255.250',         // multicast (SSDP)
            '240.0.0.1',               // reserved
            '255.255.255.255',         // limited broadcast
            '::',                      // unspecified v6
            '::ffff:100.100.100.200',  // v4-mapped spelling of the new v4 range
            '64:ff9b::7f00:1',         // NAT64 → 127.0.0.1
            '64:ff9b::a9fe:a9fe',      // NAT64 → 169.254.169.254
            '64:ff9b::a00:1',          // NAT64 → 10.0.0.1
            '64:ff9b::5db8:d822',      // NAT64 → public: fail-closed by prefix
            '64:ff9b:1::1',            // local-use NAT64
            '2002:7f00:1::',           // 6to4 → 127.0.0.1
            '2002:a9fe:a9fe::1',       // 6to4 → 169.254.169.254
            '2001:0:4136:e378::1',     // Teredo
            'fec0::1',                 // deprecated site-local
            'ff02::1',                 // multicast, link scope
            'ff05::1:3',               // multicast, site scope
        ];

        foreach ($nonPublic as $address) {
            $this->assertTrue(PublicUrlGuard::addressIsBlocked($address), "$address must be on the list");

            [$refusal, $dial] = PublicUrlGuard::guardedDialAddress(
                'target.test',
                false,
                static fn (string $host): array => [$address],
            );
            $this->assertIsString($refusal, "$address must be refused as a resolver answer");
            $this->assertStringContainsString('private/link-local', (string) $refusal, $address);
            $this->assertNull($dial, "$address must never become a dial address");
        }
    }

    public function testTheGuardStillAdmitsPublicUnicastBesideTheRanges(): void
    {
        // Edges of every blocked v4 range, plus ordinary v6 unicast — including
        // a 2001: address outside the Teredo /32 — must keep their "allowed"
        // verdict, or the widened list has eaten real sites.
        $public = [
            '93.184.216.34',
            '8.8.8.8',
            '100.63.255.255',
            '100.128.0.0',
            '192.0.1.1',
            '198.17.255.255',
            '198.20.0.0',
            '223.255.255.255',
            '2606:2800:220:1:248:1893:25c8:1946',
            '2001:4860:4860::8888',
            '2003::1',
        ];

        foreach ($public as $address) {
            $this->assertFalse(PublicUrlGuard::addressIsBlocked($address), "$address is public unicast");

            [$refusal, $dial] = PublicUrlGuard::guardedDialAddress(
                'target.test',
                false,
                static fn (string $host): array => [$address],
            );
            $this->assertNull($refusal, "$address must be admitted");
            $this->assertSame($address, $dial);
        }
    }

    // ------------------------------------------------------------------
    // The fetch arm: instruction-source tightenings and hop re-decisions.
    // ------------------------------------------------------------------

    public function testFetchRefusesAnyNonHttpsSchemeBeforeResolvingAnything(): void
    {
        $dialed = [];
        $transfer = $this->recordingTransfer($dialed, '# hi', 200);

        foreach (['http://example.test/x.md', 'ftp://example.test/x.md', 'file:///etc/passwd'] as $url) {
            $result = PublicUrlGuard::fetch($url, 1024, transfer: $transfer);

            $this->assertFalse($result['ok'], $url);
            $this->assertStringContainsString('must start with https://', (string) $result['error'], $url);
        }

        $this->assertSame([], $dialed, 'a refused scheme must never reach the transport');
    }

    public function testFetchRefusesUrlCredentialsWithoutNamingThemOut(): void
    {
        $result = PublicUrlGuard::fetch('https://user:pass@example.test/x.md', 1024);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('credentials', (string) $result['error']);
    }

    public function testFetchRefusesBlockedHostnamesWithoutResolvingThem(): void
    {
        $resolved = [];
        $result = PublicUrlGuard::fetch(
            'https://localhost:8443/instructions.md',
            1024,
            resolveAddresses: function (string $host) use (&$resolved): array {
                $resolved[] = $host;

                return ['93.184.216.34'];
            },
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('localhost', (string) $result['error']);
        $this->assertSame([], $resolved, 'the name list is consulted before the resolver');
    }

    public function testFetchRefusesALiteralPrivateTargetThroughTheProductionGuard(): void
    {
        // Unseamed blocklist, seamed transfer: the metadata literal must die
        // at the verdict, never at the socket.
        $dialed = [];
        $result = PublicUrlGuard::fetch(
            'https://100.100.100.200/latest/meta-data/',
            1024,
            transfer: $this->recordingTransfer($dialed, 'SECRET', 200),
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('private/link-local', (string) $result['error']);
        $this->assertSame([], $dialed);
    }

    public function testFetchRefusesANameWhoseAnswersMixPublicAndPrivate(): void
    {
        $result = PublicUrlGuard::fetch(
            'https://poisoned.test/x.md',
            1024,
            resolveAddresses: static fn (string $host): array => ['93.184.216.34', '10.0.0.1'],
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('private/link-local', (string) $result['error']);
    }

    public function testFetchAdmitsAPublicNameAndReturnsTheBodyVerbatim(): void
    {
        $dialed = [];
        $result = PublicUrlGuard::fetch(
            'https://example.test/x.md',
            1024,
            resolveAddresses: static fn (string $host): array => ['93.184.216.34'],
            transfer: $this->recordingTransfer($dialed, "# house rules\n", 200),
        );

        $this->assertTrue($result['ok']);
        $this->assertSame("# house rules\n", $result['body']);
        $this->assertCount(1, $dialed);
    }

    public function testFetchFollowsAnAbsoluteHttpsLocationAndReGuardsTheHop(): void
    {
        $resolved = [];
        $dialed = [];
        $transfer = function (string $scheme, string $host, array $parsed, string $address, int $budget, int $bound) use (&$dialed): ?array {
            $dialed[] = "$host@" . $address;
            if ($host === 'first.test') {
                return ['', ['HTTP/1.1 302 Redirect', 'Location: https://second.test/x.md'], true];
            }

            return ['ok body', ['HTTP/1.1 200 OK'], true];
        };

        $result = PublicUrlGuard::fetch(
            'https://first.test/x.md',
            1024,
            resolveAddresses: function (string $host) use (&$resolved): array {
                $resolved[] = $host;

                return [$host === 'first.test' ? '93.184.216.34' : '8.8.8.8'];
            },
            transfer: $transfer,
        );

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame('ok body', $result['body']);
        $this->assertSame(['first.test', 'second.test'], $resolved, 'the hop target must be resolved too');
        $this->assertCount(2, $dialed);
    }

    public function testFetchRefusesARedirectIntoThePrivateRangeWithTheRedirectWording(): void
    {
        $dialed = [];
        $result = PublicUrlGuard::fetch(
            'https://first.test/x.md',
            1024,
            resolveAddresses: $this->fakeDns(['first.test' => ['93.184.216.34']]),
            transfer: static function (string $scheme, string $host, array $parsed, string $address, int $budget, int $bound) use (&$dialed): ?array {
                $dialed[] = $host;

                return ['', ['HTTP/1.1 302 Redirect', 'Location: https://100.100.100.200/latest/meta-data/'], true];
            },
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('redirect to private/link-local', (string) $result['error']);
        $this->assertCount(1, $dialed, 'the refused hop must never be dialed');
    }

    public function testFetchRefusesRelativeAndPlainHttpLocationsRatherThanDialingThem(): void
    {
        // Reference resolution lives in WebFetch with its RFC 3986 suite; the
        // instruction channel refuses a relative target instead of keeping a
        // second copy of the resolver. An absolute http: target is refused by
        // the hop scheme rule — both never reach the transport.
        $dialed = [];
        $relative = PublicUrlGuard::fetch(
            'https://first.test/x.md',
            1024,
            resolveAddresses: $this->fakeDns(['first.test' => ['93.184.216.34']]),
            transfer: $this->alwaysRedirects($dialed, '/moved.md'),
        );

        $this->assertFalse($relative['ok']);
        $this->assertStringContainsString('relative reference is not followed', (string) $relative['error']);

        $plainHttp = PublicUrlGuard::fetch(
            'https://first.test/x.md',
            1024,
            resolveAddresses: $this->fakeDns(['first.test' => ['93.184.216.34']]),
            transfer: $this->alwaysRedirects($dialed, 'http://elsewhere.test/x.md'),
        );

        $this->assertFalse($plainHttp['ok']);
        $this->assertStringContainsString('non-https scheme', (string) $plainHttp['error']);
        $this->assertCount(2, $dialed, 'one hop each — the refused Location is never dialed');
    }

    public function testFetchRefusesA3xxWithoutAUsableLocation(): void
    {
        $result = PublicUrlGuard::fetch(
            'https://example.test/x.md',
            1024,
            resolveAddresses: static fn (string $host): array => ['93.184.216.34'],
            transfer: static fn (string $scheme, string $host, array $parsed, string $address, int $budget, int $bound): ?array
                => ['loop', ['HTTP/1.1 302 Redirect'], true],
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no usable Location', (string) $result['error']);
    }

    public function testFetchRefusesErrorStatusesAndNeverReturnsTheirBody(): void
    {
        $result = PublicUrlGuard::fetch(
            'https://example.test/x.md',
            1024,
            resolveAddresses: static fn (string $host): array => ['93.184.216.34'],
            transfer: static fn (string $scheme, string $host, array $parsed, string $address, int $budget, int $bound): ?array
                => ['<!html an error page that must not become instructions', ['HTTP/1.1 404 Error'], true],
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('HTTP 404', (string) $result['error']);
        $this->assertNull($result['body']);
    }

    public function testFetchCapsTheDocumentAtTheGivenByteBound(): void
    {
        $oversize = str_repeat('x', 1025);
        $result = PublicUrlGuard::fetch(
            'https://example.test/x.md',
            1024,
            resolveAddresses: static fn (string $host): array => ['93.184.216.34'],
            transfer: static fn (string $scheme, string $host, array $parsed, string $address, int $budget, int $bound): ?array
                => [$oversize, ['HTTP/1.1 200 OK'], true],
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('1024 byte instruction-source cap', (string) $result['error']);

        // The abandoned-mid-read case (size unknown): same verdict.
        $running = PublicUrlGuard::fetch(
            'https://example.test/x.md',
            1024,
            resolveAddresses: static fn (string $host): array => ['93.184.216.34'],
            transfer: static fn (string $scheme, string $host, array $parsed, string $address, int $budget, int $bound): ?array
                => [str_repeat('x', $bound), ['HTTP/1.1 200 OK'], false],
        );

        $this->assertFalse($running['ok']);
        $this->assertStringContainsString('exceeds', (string) $running['error']);
    }

    public function testFetchBoundsTheRedirectChain(): void
    {
        $hops = 0;
        $result = PublicUrlGuard::fetch(
            'https://example.test/x.md',
            1024,
            resolveAddresses: static fn (string $host): array => ['93.184.216.34'],
            transfer: static function (string $scheme, string $host, array $parsed, string $address, int $budget, int $bound) use (&$hops): ?array {
                $hops++;

                return ['', ['HTTP/1.1 302 Redirect', 'Location: https://example.test/next'], true];
            },
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('too many', (string) $result['error']);
        $this->assertSame(PublicUrlGuard::MAX_REDIRECTS + 1, $hops);
    }

    public function testTheInstructionBudgetIsAPerReadTransportBoundNotAKillTimer(): void
    {
        // E646: the banned shape is a blanket wall-clock kill around a call.
        // This is the stream-context `timeout` option WebFetch has always set
        // — one small number, pinned here because the brief priced the fetch
        // at fifteen seconds and the constant is the only place it lives.
        $this->assertSame(15, PublicUrlGuard::FETCH_BUDGET_SECONDS);

        $source = (string) file_get_contents((new \ReflectionClass(PublicUrlGuard::class))->getFileName());
        $pin = strpos($source, 'private static function transferPinned');
        $this->assertIsInt($pin, 'the slice below is the structural pin — it needs the method');
        $transfer = substr($source, $pin);
        $transfer = substr($transfer, 0, (int) strpos($transfer, "\n    }\n"));

        // The validated literal, never the name, becomes the dial authority,
        // and the pinned context still carries Host + SNI + max_redirects=0.
        $this->assertMatchesRegularExpression('/\$authority = .*str_contains\(\$address/', $transfer);
        $this->assertStringContainsString('@fopen("$scheme://$authority$origin"', $transfer);
        $this->assertStringContainsString("'max_redirects' => 0", $transfer);
        $this->assertStringContainsString("'peer_name' => \$host", $transfer);
        $this->assertMatchesRegularExpression("/'timeout' => \\\$budgetSeconds/", $transfer);
        $this->assertStringNotContainsString('pcntl_alarm', $source);
        $this->assertStringNotContainsString('set_time_limit', $source);
    }

    /**
     * Byte-identical to ToolSecurityTest's helper of the same name — the
     * drift guard's accepted shape. It honours IP literals (as the production
     * resolver does) so a redirect INTO a literal is judged on the literal.
     *
     * @param array<string, list<string>> $map name => IP literals
     */
    private function fakeDns(array $map): callable
    {
        return static function (string $host) use ($map): array {
            if (isset($map[$host])) {
                return $map[$host];
            }

            return filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : [];
        };
    }

    /**
     * @param list<string> $dialed
     */
    private function alwaysRedirects(array &$dialed, string $location): callable
    {
        return static function (string $scheme, string $host, array $parsed, string $address, int $budget, int $bound) use (&$dialed, $location): ?array {
            $dialed[] = $host;

            return ['', ['HTTP/1.1 302 Redirect', "Location: $location"], true];
        };
    }

    /**
     * @param list<string> $dialed
     *
     * @return callable(string, string, array<string,mixed>, string, int, int): ?array{0: string, 1: list<string>, 2: bool}
     */
    private function recordingTransfer(array &$dialed, string $body, int $status): callable
    {
        return function (string $scheme, string $host, array $parsed, string $address, int $budget, int $bound) use (&$dialed, $body, $status): ?array {
            $dialed[] = $host;

            return [
                $body,
                ["HTTP/1.1 " . ($status === 200 ? '200 OK' : "$status Status")],
                true,
            ];
        };
    }
}
