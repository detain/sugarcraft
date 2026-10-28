<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Web\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\BuiltIn\WebFetch;
use SugarCraft\Code\Tools\Web\Search\BraveBackend;
use SugarCraft\Code\Tools\Web\Search\ExaBackend;
use SugarCraft\Code\Tools\Web\Search\SearchBackends;
use SugarCraft\Code\Tools\Web\Search\TavilyBackend;

/**
 * The hosted backends' request shapes, pinned byte-exactly: URL, method,
 * header list, body, timeout, and the dial address the guard settled on.
 * These hosts are constants, so the wire is entirely derivable — a change
 * here is a change of service contract, never an accident. The other law
 * under test is that a key value travels ONLY in its header and never in
 * any refusal or error surface.
 *
 * No socket opens anywhere: the transport seam is a recorder.
 */
final class HostedBackendShapesTest extends TestCase
{
    private const BRAVE_KEY = 'secret-brave-key';

    private const TAVILY_KEY = 'secret-tavily-key';

    private const EXA_KEY = 'secret-exa-key';

    private const PUBLIC_IP = '95.101.0.1';

    /** @param array<string, mixed>|null $seen */
    private function recorder(?array &$seen, string|false $body = '{}', array $headers = ['HTTP/1.1 200 OK']): callable
    {
        return static function (array $request, string $dial) use (&$seen, $body, $headers): array {
            $seen = $request + ['dial' => $dial];

            return [$body, $headers];
        };
    }

    /** @return \Closure(string): list<string> */
    private function publicDns(): callable
    {
        return fn (string $host): array => [self::PUBLIC_IP];
    }

    public static function hostedProvider(): array
    {
        return [
            'brave' => [BraveBackend::class, 'brave', self::BRAVE_KEY, 'BRAVE_SEARCH_API_KEY', 'api.search.brave.com'],
            'tavily' => [TavilyBackend::class, 'tavily', self::TAVILY_KEY, 'TAVILY_API_KEY', 'api.tavily.com'],
            'exa' => [ExaBackend::class, 'exa', self::EXA_KEY, 'EXA_API_KEY', 'api.exa.ai'],
        ];
    }

    public function testBraveRequestShapeIsByteExact(): void
    {
        $seen = [];
        $backend = new BraveBackend(30, self::BRAVE_KEY, $this->recorder($seen), $this->publicDns(), static fn (string $a): bool => false);

        $response = $backend->search('php test', 2, 'day', 7);

        self::assertNull($response->error, (string) $response->error);
        self::assertSame('https://api.search.brave.com/res/v1/web/search?q=php+test&count=7&safesearch=strict&freshness=pd', $seen['url']);
        self::assertSame('GET', $seen['method']);
        self::assertSame(['Accept: application/json', 'X-Subscription-Token: ' . self::BRAVE_KEY], $seen['headers']);
        self::assertNull($seen['body']);
        self::assertSame(30, $seen['timeout']);
        self::assertSame(self::PUBLIC_IP, $seen['dial'], 'the dial authority is the address the guard settled on');
    }

    public function testBraveClampsCountAndMapsSafesearchAndPassesUnknownFreshness(): void
    {
        $seen = [];
        $backend = new BraveBackend(30, self::BRAVE_KEY, $this->recorder($seen), $this->publicDns(), static fn (string $a): bool => false);

        $backend->search('q', 0, 'fortnight', 0);
        self::assertStringContainsString('count=1&safesearch=off&freshness=fortnight', $seen['url']);

        $backend->search('q', 1, null, 100);
        self::assertStringContainsString('count=20&safesearch=moderate', $seen['url']);
        self::assertStringNotContainsString('freshness', $seen['url']);
    }

    public function testTavilyRequestShapeIsByteExact(): void
    {
        $seen = [];
        $backend = new TavilyBackend(30, self::TAVILY_KEY, $this->recorder($seen), $this->publicDns(), static fn (string $a): bool => false);

        $response = $backend->search('php', null, null, 5);

        self::assertNull($response->error);
        self::assertSame('https://api.tavily.com/search', $seen['url']);
        self::assertSame('POST', $seen['method']);
        self::assertSame([
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . self::TAVILY_KEY,
        ], $seen['headers']);
        self::assertSame('{"query":"php","max_results":5,"include_answer":true}', $seen['body']);
    }

    public function testTavilyMapsKnownTimeRangesToDaysAndDropsUnknown(): void
    {
        $seen = [];
        $backend = new TavilyBackend(30, self::TAVILY_KEY, $this->recorder($seen), $this->publicDns(), static fn (string $a): bool => false);

        foreach ([['day', 1], ['month', 30], ['year', 365]] as [$range, $days]) {
            $backend->search('php', 2, $range, 5);
            self::assertSame('{"query":"php","max_results":5,"include_answer":true,"days":' . $days . '}', $seen['body'], $range);
        }

        $backend->search('php', 2, 'fortnight', 5);
        self::assertStringNotContainsString('days', $seen['body'], 'an unmapped range is dropped, not guessed');
        self::assertStringNotContainsString('safesearch', $seen['body'], 'tavily carries no safesearch field');
    }

    public function testExaRequestShapeIsByteExactAndIgnoresBothFilters(): void
    {
        $seen = [];
        $backend = new ExaBackend(30, self::EXA_KEY, $this->recorder($seen), $this->publicDns(), static fn (string $a): bool => false);

        $backend->search('php', 2, 'day', 5);

        self::assertSame('https://api.exa.ai/search', $seen['url']);
        self::assertSame('POST', $seen['method']);
        self::assertSame([
            'Content-Type: application/json',
            'Accept: application/json',
            'x-api-key: ' . self::EXA_KEY,
        ], $seen['headers']);
        self::assertSame('{"query":"php","numResults":5}', $seen['body']);
    }

    #[DataProvider('hostedProvider')]
    public function testAMissingKeyIsRefusedByNameAndTheRefusalCarriesNoKeyValue(string $class, string $name, string $sentinel, string $env, string $host): void
    {
        $backend = new $class(30, '', static function (array $r, string $d): array {
            self::fail("the unconfigured $r[url] must never be dialled");
        });

        self::assertFalse($backend->isConfigured());
        $refusal = $backend->configurationRefusal();
        self::assertStringContainsString($env, $refusal);
        self::assertStringContainsString($name, $refusal);

        $response = $backend->search('php', null, null, 5);
        self::assertSame($refusal, $response->error);

        // Hostile pin: no key value that any sibling backend might read
        // appears in any refusal, so an unset-key message can never leak a
        // value the operator exported before launching.
        foreach ([self::BRAVE_KEY, self::TAVILY_KEY, self::EXA_KEY] as $other) {
            self::assertStringNotContainsString($other, $refusal);
        }
    }

    public function testTheEnvironmentIsReadOnlyWhenNoArgumentWasGivenAndNeverAnEmptyString(): void
    {
        putenv('BRAVE_SEARCH_API_KEY=' . self::BRAVE_KEY);
        try {
            $seen = [];
            $backend = new BraveBackend(30, null, $this->recorder($seen), $this->publicDns(), static fn (string $a): bool => false);
            self::assertTrue($backend->isConfigured());
            $backend->search('php', null, null, 5);
            self::assertSame('X-Subscription-Token: ' . self::BRAVE_KEY, $seen['headers'][1]);
        } finally {
            putenv('BRAVE_SEARCH_API_KEY');
        }

        putenv('BRAVE_SEARCH_API_KEY=');
        try {
            $empty = new BraveBackend(30);
            self::assertFalse($empty->isConfigured(), 'an empty environment value is not a key');
        } finally {
            putenv('BRAVE_SEARCH_API_KEY');
        }
    }

    public function testErrorSurfacesNeverEchoTheKeyValue(): void
    {
        foreach ([
            ['HTTP/1.1 401 Unauthorized'],
            ['HTTP/1.1 403 Forbidden'],
            ['HTTP/1.1 302 Found', 'Location: http://elsewhere.example/x'],
            ['HTTP/1.1 503 Service Unavailable'],
        ] as $headers) {
            $backend = new BraveBackend(30, self::BRAVE_KEY, $this->recorder($seen, '{}', $headers), $this->publicDns(), static fn (string $a): bool => false);
            $response = $backend->search('php', null, null, 5);
            self::assertNotNull($response->error);
            self::assertStringNotContainsString(self::BRAVE_KEY, $response->error);
        }
    }

    #[DataProvider('hostedProvider')]
    public function testAFixedHostThatResolvesPrivateIsRefusedBeforeAnyDial(string $class, string $name, string $sentinel, string $env, string $host): void
    {
        $backend = new $class(
            30,
            $sentinel,
            static function (array $r, string $d): array {
                self::fail("the blocklist let $r[url] through");
            },
            static fn (string $h): array => [$h === $host ? '127.0.0.1' : '95.101.0.1'],
            static fn (string $a): bool => WebFetch::addressIsBlocked($a),
        );

        $response = $backend->search('php', null, null, 5);
        self::assertSame('Error: search service cannot resolve to a private or link-local address', $response->error);
    }

    public function testAnUnresolvableServiceHostAndANonIpAnswerAreBothRefused(): void
    {
        $quiet = static function (array $r, string $d): array {
            self::fail('nothing may be dialled after a refused lookup');
        };
        $blocked = static fn (string $a): bool => false;

        $none = new BraveBackend(30, self::BRAVE_KEY, $quiet, static fn (string $h): array => [], $blocked);
        self::assertSame('Error: could not resolve search service host: api.search.brave.com', $none->search('php', null, null, 5)->error);

        $junk = new BraveBackend(30, self::BRAVE_KEY, $quiet, static fn (string $h): array => ['not-an-ip'], $blocked);
        self::assertSame('Error: could not resolve search service host: api.search.brave.com', $junk->search('php', null, null, 5)->error);
    }

    public function testHitMappingFollowsEachServiceDocumentedShape(): void
    {
        $brave = new BraveBackend(30, self::BRAVE_KEY, $this->recorder($seen, json_encode([
            'web' => ['results' => [
                ['title' => 'B1', 'url' => 'https://b/1', 'description' => 'first'],
                'scalar-row',
            ]],
        ])), $this->publicDns(), static fn (string $a): bool => false);
        $r = $brave->search('php', null, null, 5);
        self::assertSame(2, $r->total);
        self::assertSame(['B1', 'https://b/1', 'first'], [$r->hits[0]->title, $r->hits[0]->url, $r->hits[0]->content]);
        self::assertSame(['Untitled', 'No URL', ''], [$r->hits[1]->title, $r->hits[1]->url, $r->hits[1]->content], 'a foreign-shaped row degrades to the defaults');

        $tavily = new TavilyBackend(30, self::TAVILY_KEY, $this->recorder($seen, json_encode([
            'results' => [['title' => 'T1', 'url' => 'https://t/1', 'content' => 'body']],
            'answer' => 'because',
        ])), $this->publicDns(), static fn (string $a): bool => false);
        $r = $tavily->search('php', null, null, 5);
        self::assertSame(['T1', 'https://t/1', 'body'], [$r->hits[0]->title, $r->hits[0]->url, $r->hits[0]->content]);
        self::assertSame(['Direct answers:', '  • because'], $r->leading);

        $exa = new ExaBackend(30, self::EXA_KEY, $this->recorder($seen, json_encode([
            'results' => [['title' => 'E1', 'url' => 'https://e/1', 'text' => 'passage']],
        ])), $this->publicDns(), static fn (string $a): bool => false);
        $r = $exa->search('php', null, null, 5);
        self::assertSame(['E1', 'https://e/1', 'passage'], [$r->hits[0]->title, $r->hits[0]->url, $r->hits[0]->content]);
    }

    public function testAForeignShapeCarriesNoResultsAndNeverFakesOrFatals(): void
    {
        foreach (['brave' => new BraveBackend(30, self::BRAVE_KEY, $this->recorder($seen, '{"nope":{"inner":3}}'), $this->publicDns(), static fn (string $a): bool => false),
            'tavily' => new TavilyBackend(30, self::TAVILY_KEY, $this->recorder($seen, '{"results":"a string"}'), $this->publicDns(), static fn (string $a): bool => false),
            'exa' => new ExaBackend(30, self::EXA_KEY, $this->recorder($seen, '{"results":null}'), $this->publicDns(), static fn (string $a): bool => false)] as $backend) {
            $r = $backend->search('php', null, null, 5);
            self::assertNull($r->error);
            self::assertSame(0, $r->total);
            self::assertSame([], $r->hits);
        }
    }

    public function testInvalidJsonAndNonObjectBodiesRefuseWithTheSharedWording(): void
    {
        $backend = new ExaBackend(30, self::EXA_KEY, $this->recorder($seen, '{not json'), $this->publicDns(), static fn (string $a): bool => false);
        self::assertStringStartsWith('Error: search response was not valid JSON: ', $backend->search('php', null, null, 5)->error);

        $scalar = new ExaBackend(30, self::EXA_KEY, $this->recorder($seen, '7'), $this->publicDns(), static fn (string $a): bool => false);
        self::assertSame('Error: search response was not a JSON object', $scalar->search('php', null, null, 5)->error);
    }

    public function testTheSharedStatusReadClassifiesEveryFailureShape(): void
    {
        $svc = 'brave search';
        self::assertSame("Error: failed to connect to the $svc service. Check network connectivity.", SearchBackends::hostedFailure(false, [], $svc));
        self::assertSame('Error: search response exceeds maximum size limit', SearchBackends::hostedFailure(str_repeat('a', SearchBackends::MAX_RESPONSE_SIZE + 1), ['HTTP/1.1 200 OK'], $svc));
        self::assertSame("Error: HTTP 302 — the $svc service answered with a redirect to http://evil.example/x. Redirects are not followed.", SearchBackends::hostedFailure('{}', ['HTTP/1.1 302 Found', 'Location: http://evil.example/x'], $svc));
        self::assertSame("Error: HTTP 401 — the $svc service refused the API key. Check the key the `webSearchBackend` in use reads from the environment.", SearchBackends::hostedFailure('{}', ['HTTP/1.1 401 Unauthorized'], $svc));
        self::assertSame("Error: HTTP 400 — bad request to the $svc service. Check the query and configuration.", SearchBackends::hostedFailure('{}', ['HTTP/1.1 400 Bad Request'], $svc));
        self::assertSame("Error: HTTP 500 — the $svc service errored. Try again later.", SearchBackends::hostedFailure('{}', ['HTTP/1.1 500 Internal Server Error'], $svc));
        self::assertNull(SearchBackends::hostedFailure('{}', ['HTTP/1.1 200 OK'], $svc));
    }

    public function testALocationTooLongIsClippedAndControlBytesAreStripped(): void
    {
        $long = 'http://evil.example/' . str_repeat('x', 300);
        $suffix = SearchBackends::locationSuffix(['HTTP/1.1 302 Found', 'Location: ' . $long . "\x07"]);
        self::assertStringStartsWith(' to http://evil.example/', $suffix);
        self::assertStringEndsWith('...', $suffix);
        // ' to ' + the 256-byte body bound + '...' — the same framing law
        // the self-hosted path has always obeyed.
        self::assertLessThanOrEqual(5 + 256 + 3, strlen($suffix));
        self::assertStringNotContainsString("\x07", $suffix);

        self::assertSame('', SearchBackends::locationSuffix(['HTTP/1.1 302 Found', 'Location:   ']));
        self::assertSame('', SearchBackends::locationSuffix(['HTTP/1.1 302 Found']));
    }
}
