<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\BuiltIn\WebSearch;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

final class WebSearchToolTest extends TestCase
{
    /**
     * A WebSearch whose one network call is answered from a fixture.
     *
     * Three tests here used to drive {@see WebSearch::execute()} all the way
     * to the configured SearXNG endpoint, so their verdict was that host's
     * uptime rather than the tool's behaviour — CI went red on
     * `testQueryLengthBoundaryAt1000Chars` the first day the box did not
     * answer, and the other two paid a 30s connect timeout each on every day
     * it did. Stubbing the transport closure leaves every other step
     * (validation, the SSRF host checks, status mapping, JSON decode,
     * formatting) on the real code path.
     *
     * @param list<string> $headers raw response header lines; empty means
     *                              "no status line", which is what the tool
     *                              sees for any non-HTTP wrapper
     */
    private function stubbedSearch(string|false $body, array $headers = [], string $endpoint = 'http://example.com/search'): WebSearch
    {
        // An explicit public endpoint AND a fake resolver on purpose: the SSRF
        // guard resolves the host before the (stubbed) transport, so the real
        // resolver would put a DNS lookup back into a test that is supposed
        // to touch no network at all. The answer is example.com's own public
        // address, so the production blocklist still runs and admits it.
        return new WebSearch(
            $endpoint,
            resolveAddresses: static fn (string $host): array => filter_var($host, FILTER_VALIDATE_IP) !== false
                ? [$host]
                : ['93.184.215.14'],
            transport: static fn (array $request, string $dial): array => [$body, $headers],
        );
    }

    public function testImplementsToolInterface(): void
    {
        $tool = new WebSearch();
        $this->assertInstanceOf(Tool::class, $tool);
    }

    public function testNameIsWebSearch(): void
    {
        $tool = new WebSearch();
        $this->assertSame('WebSearch', $tool->name());
    }

    public function testDescriptionIsSet(): void
    {
        $tool = new WebSearch();
        $this->assertNotEmpty($tool->description());
    }

    public function testInputSchemaHasQueryRequired(): void
    {
        $schema = (new WebSearch())->inputSchema();
        $this->assertContains('query', $schema['required']);
        $this->assertContains('description', $schema['required']);
    }

    public function testInputSchemaHasOptionalSafesearchAndTimeRange(): void
    {
        $schema = (new WebSearch())->inputSchema();
        $this->assertArrayHasKey('safesearch', $schema['properties']);
        $this->assertArrayHasKey('time_range', $schema['properties']);
        $this->assertNotContains('safesearch', $schema['required']);
        $this->assertNotContains('time_range', $schema['required']);
    }

    public function testReturnsErrorForEmptyQuery(): void
    {
        $tool = new WebSearch();
        $result = $tool->execute(['query' => '', 'description' => 'test']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('empty', $result->content());
    }

    public function testReturnsErrorWhenNoQueryKey(): void
    {
        $tool = new WebSearch();
        $result = $tool->execute(['description' => 'test']);
        $this->assertTrue($result->isError());
    }

    public function testUsesConfiguredEndpoint(): void
    {
        $customEndpoint = 'http://localhost:9999/custom-search';
        $tool = new WebSearch($customEndpoint);
        // We can't easily test the actual HTTP call without mocking,
        // but we can verify the tool accepts a custom endpoint
        $this->assertSame('WebSearch', $tool->name());
    }

    /**
     * Audit F-W3(b): there is no default endpoint. This used to be
     * `testDefaultEndpointIsSet`, and the default it took for granted was
     * `http://skynet2.interserver.net:8080/search` — a private host reached
     * over cleartext http, so every model-composed query left unencrypted for
     * a third party. With nothing configured the tool still constructs (the
     * launcher builds it unconditionally) but refuses every call, loudly, and
     * never reaches the network.
     */
    public function testWithNoEndpointConfiguredEverySearchFailsLoudlyWithoutFetching(): void
    {
        $saved = getenv('CANDY_CODE_SEARCH_ENDPOINT');
        putenv('CANDY_CODE_SEARCH_ENDPOINT');

        try {
            foreach ([null, '', '   '] as $endpoint) {
                $fetches = 0;
                $tool = new WebSearch(
                    $endpoint,
                    transport: static function (array $request, string $dial) use (&$fetches): array {
                        ++$fetches;

                        return [false, []];
                    },
                );

                $result = $tool->execute(['query' => 'php 8.3', 'description' => 'test', 'id' => 'c1']);

                $label = var_export($endpoint, true);
                $this->assertTrue($result->isError(), "endpoint {$label}: an unconfigured search must be an error");
                $this->assertStringContainsString('CANDY_CODE_SEARCH_ENDPOINT', $result->content());
                $this->assertStringContainsString('no built-in default', $result->content());
                $this->assertStringNotContainsString('skynet2', $result->content());
                $this->assertSame('c1', $result->toolCallId());
                $this->assertSame(0, $fetches, "endpoint {$label}: nothing may be dialled without an endpoint");
            }
        } finally {
            putenv($saved === false ? 'CANDY_CODE_SEARCH_ENDPOINT' : 'CANDY_CODE_SEARCH_ENDPOINT=' . $saved);
        }
    }

    /**
     * The environment variable is the configuration channel, and the
     * constructor argument still wins over it.
     */
    public function testTheEnvironmentVariableConfiguresTheEndpointAndTheArgumentBeatsIt(): void
    {
        $saved = getenv('CANDY_CODE_SEARCH_ENDPOINT');
        putenv('CANDY_CODE_SEARCH_ENDPOINT=https://from-env.example/search');

        try {
            $seen = static function (?string $endpoint): string {
                $url = '';
                $tool = new WebSearch(
                    $endpoint,
                    resolveAddresses: static fn (string $host): array => ['93.184.215.14'],
                    transport: static function (array $request, string $dial) use (&$url): array {
                        $url = $request['url'];

                        return [json_encode(['query' => 'q', 'results' => []]), ['HTTP/1.1 200 OK']];
                    },
                );
                $tool->execute(['query' => 'q', 'description' => 'test']);

                return $url;
            };

            $this->assertStringStartsWith('https://from-env.example/search?', $seen(null));
            $this->assertStringStartsWith('https://from-arg.example/search?', $seen('https://from-arg.example/search'));
        } finally {
            putenv($saved === false ? 'CANDY_CODE_SEARCH_ENDPOINT' : 'CANDY_CODE_SEARCH_ENDPOINT=' . $saved);
        }
    }

    /**
     * The assembled digest for one canned SearXNG payload, driven all the way
     * through execute() with only the HTTP call stubbed. This replaced six
     * tests that reached into the old private `formatResults()` by reflection;
     * the formatting now begins where a backend's pieces end, so the honest
     * way to pin it is through the tool itself.
     *
     * @param array<mixed> $data
     */
    private function digestOf(array $data, int $maxResults = 10): string
    {
        $tool = new WebSearch(
            'http://example.com/search',
            maxResults: $maxResults,
            resolveAddresses: static fn (string $host): array => ['93.184.215.14'],
            transport: static fn (array $request, string $dial): array => [json_encode($data), ['HTTP/1.1 200 OK']],
        );
        $result = $tool->execute(['query' => 'test', 'description' => 'test']);
        $this->assertFalse($result->isError(), $result->content());

        return $result->content();
    }

    public function testFormatResultsHandlesEmptyData(): void
    {
        $this->assertSame('No results found for the query.', $this->digestOf([]));
    }

    public function testFormatResultsHandlesFullData(): void
    {
        $data = [
            'query' => 'test query',
            'results' => [
                ['title' => 'Result 1', 'url' => 'https://example.com/1', 'content' => 'Content here'],
                ['title' => 'Result 2', 'url' => 'https://example.com/2', 'content' => 'More content'],
            ],
            'answers' => [['answer' => 'Direct answer']],
            'suggestions' => ['suggestion1', 'suggestion2'],
            'corrections' => ['correction1'],
            'infoboxes' => [['title' => 'Info Box', 'infobox' => 'Info content']],
            'unresponsive_engines' => ['engine1'],
        ];

        $result = $this->digestOf($data);

        $this->assertStringContainsString('Search query: test query', $result);
        $this->assertStringContainsString('Direct answers:', $result);
        $this->assertStringContainsString('Result 1', $result);
        $this->assertStringContainsString('Suggestions:', $result);
        $this->assertStringContainsString('Corrections:', $result);
        $this->assertStringContainsString('Info boxes:', $result);
        $this->assertStringContainsString('unavailable', $result);
    }

    public function testFormatResultsTruncatesLongContent(): void
    {
        $longContent = str_repeat('a', 300);
        $data = [
            'results' => [
                ['title' => 'Long Content', 'url' => 'https://example.com', 'content' => $longContent],
            ],
        ];

        $result = $this->digestOf($data);
        $this->assertStringContainsString('...', $result);
        $this->assertStringNotContainsString(str_repeat('a', 250), $result);
    }

    public function testFormatResultsLimitsTo10Results(): void
    {
        $results = [];
        for ($i = 1; $i <= 15; $i++) {
            $results[] = ['title' => "Result $i", 'url' => "https://example.com/$i", 'content' => 'content'];
        }

        $result = $this->digestOf(['results' => $results]);
        $this->assertStringContainsString('... and 5 more results', $result);
        $this->assertStringNotContainsString('Result 11', $result);
    }

    public function testReturnsErrorForQueryExceedingMaxLength(): void
    {
        $tool = new WebSearch();
        $longQuery = str_repeat('a', 2001);
        $result = $tool->execute(['query' => $longQuery, 'description' => 'test']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('exceeds maximum length', $result->content());
    }

    public function testQueryLengthBoundaryAt1000Chars(): void
    {
        $tool = $this->stubbedSearch(json_encode(['query' => 'aaa', 'results' => []]));
        $result = $tool->execute(['query' => str_repeat('a', 1000), 'description' => 'test']);
        // 1000 chars should pass (limit is 2000)
        $this->assertFalse($result->isError());
    }

    public function testQueryLengthBoundaryAt2001Chars(): void
    {
        $tool = new WebSearch();
        $result = $tool->execute(['query' => str_repeat('a', 2001), 'description' => 'test']);
        // 2001 chars should fail
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('exceeds maximum length', $result->content());
    }

    public function testBlocks169_254_169_254MetadataEndpoint(): void
    {
        // This is the AWS/Azure/GCP metadata endpoint — should be blocked
        $tool = new WebSearch('http://169.254.169.254/latest/meta-data');
        $result = $tool->execute(['query' => 'test', 'description' => 'test']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('private', $result->content());
    }

    public function testHandlesRedirectResponse(): void
    {
        // A 3xx is refused by name (audit F-W3), not left to fail JSON
        // decoding: the refusal says what happened and where it pointed, so
        // the user can configure the final URL, and it is never a "result"
        // built from the redirect page.
        $tool = $this->stubbedSearch(
            '<html><body>Redirecting to <a href="http://example.com">example</a></body></html>',
            ['HTTP/1.1 302 Found', 'Location: http://example.com'],
            'http://example.com/search',
        );
        $result = $tool->execute(['query' => 'test', 'description' => 'test']);
        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('HTTP 302', $result->content());
        $this->assertStringContainsString('redirect to http://example.com', $result->content());
        $this->assertStringContainsString('not followed', $result->content());
    }

    public function testHandlesNonStringQuery(): void
    {
        $tool = $this->stubbedSearch(json_encode(['query' => '12345', 'results' => []]));
        // Integer query gets cast to string
        $result = $tool->execute(['query' => 12345, 'description' => 'test']);
        // Should be processed as string "12345" without error
        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertFalse($result->isError());
        // If query was empty after coercion, would be error
    }

    public function testFormatResultsHandlesEmptyResults(): void
    {
        $result = $this->digestOf(['query' => 'test', 'results' => []]);
        $this->assertStringContainsString('Search query: test', $result);
    }

    public function testFormatResultsHandlesAllNullFields(): void
    {
        $data = [
            'query' => null,
            'results' => null,
            'answers' => null,
            'suggestions' => null,
            'corrections' => null,
            'infoboxes' => null,
            'unresponsive_engines' => null,
        ];
        $this->assertSame('No results found for the query.', $this->digestOf($data));
    }

    public function testRejectsLocalhostEndpoint(): void
    {
        $tool = new WebSearch('http://localhost:8080/search');
        $result = $tool->execute(['query' => 'test', 'description' => 'test']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('localhost', $result->content());
    }

    public function testRejectsPrivateIPEndpoint(): void
    {
        $tool = new WebSearch('http://127.0.0.1:8080/search');
        $result = $tool->execute(['query' => 'test', 'description' => 'test']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('localhost', $result->content());
    }

    public function testRejects10PrivateNetworkEndpoint(): void
    {
        $tool = new WebSearch('http://10.0.0.1:8080/search');
        $result = $tool->execute(['query' => 'test', 'description' => 'test']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('private', $result->content());
    }

    public function testConfigurableTimeout(): void
    {
        $tool = new WebSearch('http://example.com/search', timeout: 60);
        $this->assertInstanceOf(WebSearch::class, $tool);
    }

    public function testConfigurableMaxResults(): void
    {
        $tool = new WebSearch('http://example.com/search', maxResults: 5);
        $this->assertInstanceOf(WebSearch::class, $tool);
    }

    public function testFormatResultsUsesConfigurableMaxResults(): void
    {
        $results = [];
        for ($i = 1; $i <= 5; $i++) {
            $results[] = ['title' => "Result $i", 'url' => "https://example.com/$i", 'content' => 'content'];
        }
        $result = $this->digestOf(['results' => $results], maxResults: 3);

        // With maxResults=3, only 3 should appear
        $this->assertStringContainsString('Result 1', $result);
        $this->assertStringContainsString('Result 2', $result);
        $this->assertStringContainsString('Result 3', $result);
        $this->assertStringNotContainsString('Result 4', $result);
        $this->assertStringContainsString('... and 2 more results', $result);
    }
}
