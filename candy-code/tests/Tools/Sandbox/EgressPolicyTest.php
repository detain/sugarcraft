<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Sandbox;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Sandbox\EgressPolicy;

/**
 * C-TOOL-4.f: the egress policy parses `sandboxAllowedDomains`-shaped
 * entries and vets CONNECT/absolute-form targets with exactly the grant
 * half of {@see \SugarCraft\Code\Permissions\FetchTarget::matchesDomain}
 * (no subdomain widening) plus a resolve-ONCE address sweep — an allowed
 * name that answers with any blocked address is refused, and the vetted
 * literal (never the name) is what the proxy dials, closing the DNS
 * rebinding window.
 *
 * The resolver and blocklist are injected so no test touches real DNS.
 */
final class EgressPolicyTest extends TestCase
{
    /** TEST-NET-3 documentation range — a safe fake "public" answer. */
    private const ADDRESS = '203.0.113.7';

    public function testDefaultPortsCoverTheWebPair(): void
    {
        self::assertSame([80, 443], EgressPolicy::DEFAULT_PORTS);
    }

    public function testExactGrantMatchesTheBareHostOnWebPorts(): void
    {
        $policy = $this->policy(['github.com']);
        self::assertSame(self::ADDRESS, $policy->vet('github.com', 443));
        self::assertSame(self::ADDRESS, $policy->vet('github.com', 80));
    }

    public function testExactGrantDoesNotWidenToSubdomains(): void
    {
        // The grant side is matchesDomain(pattern, includeSubdomains:false):
        // "github.com" is exact; subdomains need their own "*.github.com"
        // entry, mirroring the WebFetch allow-list law verbatim.
        $policy = $this->policy(['github.com']);
        self::assertNull($policy->vet('api.github.com', 443));
    }

    public function testWildcardGrantMatchesSubdomainsOnly(): void
    {
        $glob = chr(42) . '.github.com';
        $policy = $this->policy([$glob]);
        self::assertSame(self::ADDRESS, $policy->vet('api.github.com', 443));
        self::assertSame(self::ADDRESS, $policy->vet('x.y.github.com', 443));
        // The bare apex is NOT covered by the subdomain glob.
        self::assertNull($policy->vet('github.com', 443));
    }

    public function testPortsNarrowAnEntryToTheSpelledPortOnly(): void
    {
        $policy = $this->policy(['hooks.internal:8443']);
        self::assertSame(self::ADDRESS, $policy->vet('hooks.internal', 8443));
        self::assertNull($policy->vet('hooks.internal', 443));
        self::assertNull($policy->vet('hooks.internal', 80));
    }

    public function testOtherPortsRefusedWithoutAnExplicitGrant(): void
    {
        $policy = $this->policy(['hooks.internal']);
        self::assertSame(self::ADDRESS, $policy->vet('hooks.internal', 443));
        self::assertNull($policy->vet('hooks.internal', 8080));
        self::assertNull($policy->vet('hooks.internal', 22));
    }

    public function testOutOfRangePortMakesTheWholeEntryInert(): void
    {
        $policy = $this->policy(['hooks.internal:99999']);
        self::assertNull($policy->vet('hooks.internal', 443));
        self::assertNull($policy->vet('hooks.internal', 80));
    }

    public function testNonNumericPortSuffixNeverGuessesDefaults(): void
    {
        // A colon tail that is not all digits keeps the WHOLE string as the
        // host glob (which matches nothing real) instead of silently
        // widening to 80/443 or mis-splitting an IPv6-ish entry.
        $policy = $this->policy(['hooks.internal:80x']);
        self::assertNull($policy->vet('hooks.internal', 80));
        self::assertNull($policy->vet('hooks.internal:80x', 80));
    }

    public function testBlankSlashAndBracketedEntriesGrantNothing(): void
    {
        $bracket = chr(91) . 'bad' . chr(93) . '.internal';
        $policy = $this->policy(['   ', 'evil/internal', $bracket, '']);
        self::assertNull($policy->vet('evil.internal', 443));
        self::assertNull($policy->vet('anything.else', 80));
    }

    public function testBracketedLiteralHostsAreUngrantable(): void
    {
        // FetchTarget renders bracketed hosts ungrantable, so an IPv6
        // literal can never slip through the name policy.
        $policy = $this->policy(['v6.internal', chr(91) . '::1' . chr(93)]);
        self::assertNull($policy->vet('[::1]', 443));
        self::assertSame(self::ADDRESS, $policy->vet('v6.internal', 443));
    }

    public function testHostNormalisationReusesTheFetchTargetLadder(): void
    {
        $policy = $this->policy(['github.com']);
        self::assertSame(self::ADDRESS, $policy->vet('GitHub.COM', 443));
        self::assertSame(self::ADDRESS, $policy->vet('github.com.', 443));
    }

    public function testAnyBlockedAddressAmongTheAnswersRefusesTheHost(): void
    {
        // An allowed name resolving to 127.0.0.1 (or the metadata address)
        // is still refused: the blocklist sweeps EVERY answer, not just the
        // first.
        $policy = new EgressPolicy(
            ['dual.internal'],
            static fn (string $host): array => [self::ADDRESS, '127.0.0.1'],
            static fn (string $address): bool => $address === '127.0.0.1',
        );
        self::assertNull($policy->vet('dual.internal', 443));
    }

    public function testEmptyAnswerListRefusesTheHost(): void
    {
        $policy = new EgressPolicy(
            ['gone.internal'],
            static fn (string $host): array => [],
            static fn (string $address): bool => false,
        );
        self::assertNull($policy->vet('gone.internal', 443));
    }

    public function testTheVettedFirstAddressIsTheDialTarget(): void
    {
        $policy = new EgressPolicy(
            ['multi.internal'],
            static fn (string $host): array => [self::ADDRESS, '203.0.113.8'],
            static fn (string $address): bool => false,
        );
        self::assertSame(self::ADDRESS, $policy->vet('multi.internal', 443));
    }

    public function testNamesResolveExactlyOncePerInstance(): void
    {
        $calls = 0;
        $policy = new EgressPolicy(
            ['once.internal', 'two.internal'],
            static function (string $host) use (&$calls): array {
                $calls++;
                return [self::ADDRESS];
            },
            static fn (string $address): bool => false,
        );
        self::assertSame(self::ADDRESS, $policy->vet('once.internal', 443));
        // Second vet on the same host, different port, mixed case: memo.
        self::assertSame(self::ADDRESS, $policy->vet('Once.INTERNAL', 80));
        self::assertSame(1, $calls);
        self::assertSame(self::ADDRESS, $policy->vet('two.internal', 443));
        self::assertSame(2, $calls);
    }

    public function testDeniedHostsNeverReachTheResolver(): void
    {
        $calls = 0;
        $policy = new EgressPolicy(
            ['github.com'],
            static function (string $host) use (&$calls): array {
                $calls++;
                return [self::ADDRESS];
            },
            static fn (string $address): bool => false,
        );
        self::assertNull($policy->vet('evil.example', 443));
        self::assertFalse($policy->granted('evil.example', 443));
        self::assertSame(0, $calls, 'refusal must be grant-side only; DNS is wasted work on denied hosts');
    }

    public function testGrantedAnswersWithoutConsultingDns(): void
    {
        $calls = 0;
        $policy = new EgressPolicy(
            ['github.com'],
            static function (string $host) use (&$calls): array {
                $calls++;
                return [self::ADDRESS];
            },
            static fn (string $address): bool => false,
        );
        self::assertTrue($policy->granted('github.com', 443));
        self::assertTrue($policy->granted('github.com', 80));
        self::assertFalse($policy->granted('github.com', 65536));
        self::assertFalse($policy->granted('', 443));
        self::assertSame(0, $calls);
    }

    /**
     * @param list<string> $entries
     */
    private function policy(array $entries): EgressPolicy
    {
        return new EgressPolicy(
            $entries,
            static fn (string $host): array => [self::ADDRESS],
            static fn (string $address): bool => false,
        );
    }
}
