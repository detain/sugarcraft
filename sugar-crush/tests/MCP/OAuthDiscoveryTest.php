<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\OAuthDiscovery;

/**
 * Audit MCP-6: the RFC 9728 / RFC 8414 discovery order shared by
 * `mcp auth add` and `mcp auth login`. The fetcher is a table, so no test
 * here touches the network.
 *
 * @see OAuthDiscovery
 */
final class OAuthDiscoveryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function authorizationServerCandidateCases(): iterable
    {
        yield 'path is inserted after the origin, then origin-only' => [
            'https://mcp.notion.example/mcp',
            [
                'https://mcp.notion.example/.well-known/oauth-authorization-server/mcp',
                'https://mcp.notion.example/.well-known/oauth-authorization-server',
            ],
        ];
        yield 'origin-only URL yields one candidate' => [
            'https://h',
            ['https://h/.well-known/oauth-authorization-server'],
        ];
        yield 'a bare slash path is no path' => [
            'https://h/',
            ['https://h/.well-known/oauth-authorization-server'],
        ];
        yield 'trailing slash dropped, query and fragment dropped' => [
            'https://h/a/b/?x=1#frag',
            [
                'https://h/.well-known/oauth-authorization-server/a/b',
                'https://h/.well-known/oauth-authorization-server',
            ],
        ];
        yield 'port is part of the origin, user-info is not' => [
            'http://user:pw@127.0.0.1:8123/mcp',
            [
                'http://127.0.0.1:8123/.well-known/oauth-authorization-server/mcp',
                'http://127.0.0.1:8123/.well-known/oauth-authorization-server',
            ],
        ];
        yield 'scheme is case-normalised' => [
            'HTTPS://h/mcp',
            [
                'https://h/.well-known/oauth-authorization-server/mcp',
                'https://h/.well-known/oauth-authorization-server',
            ],
        ];
        yield 'not an absolute http(s) URL yields nothing' => ['mcp-server', []];
        yield 'a non-http scheme yields nothing' => ['file:///etc/passwd', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('authorizationServerCandidateCases')]
    public function testAuthorizationServerCandidates(string $url, array $expected): void
    {
        self::assertSame($expected, OAuthDiscovery::authorizationServerCandidates($url));
    }

    public function testProtectedResourceCandidatesInsertTheRfc9728Suffix(): void
    {
        self::assertSame(
            [
                'https://h/.well-known/oauth-protected-resource/mcp',
                'https://h/.well-known/oauth-protected-resource',
            ],
            OAuthDiscovery::protectedResourceCandidates('https://h/mcp/'),
        );
    }

    public function testTheFullOrderWhenNothingAnswersIsBoundedAndDeduplicated(): void
    {
        [$discovery, $asked] = $this->discoveryOver([]);

        self::assertSame([], $discovery->discover('https://h/mcp'));
        self::assertSame([
            'https://h/.well-known/oauth-protected-resource/mcp',
            'https://h/.well-known/oauth-protected-resource',
            'https://h/.well-known/oauth-authorization-server/mcp',
            'https://h/.well-known/oauth-authorization-server',
        ], $asked->urls);
    }

    public function testTheOriginOnlyAuthorizationServerDocumentIsFoundForAPathBearingUrl(): void
    {
        $metadata = ['token_endpoint' => 'https://h/token', 'registration_endpoint' => 'https://h/register'];
        [$discovery] = $this->discoveryOver(['https://h/.well-known/oauth-authorization-server' => $metadata]);

        self::assertSame($metadata, $discovery->discover('https://h/mcp'));
    }

    public function testAProtectedResourceIssuerIsResolvedAndAnIssuerSharingTheOriginIsNotFetchedTwice(): void
    {
        $metadata = ['authorization_endpoint' => 'https://h/authorize', 'token_endpoint' => 'https://h/token'];
        [$discovery, $asked] = $this->discoveryOver([
            'https://h/.well-known/oauth-protected-resource' => ['authorization_servers' => ['https://h']],
        ]);

        self::assertSame([], $discovery->discover('https://h/mcp'));
        self::assertSame([
            'https://h/.well-known/oauth-protected-resource/mcp',
            'https://h/.well-known/oauth-protected-resource',
            'https://h/.well-known/oauth-authorization-server',
            'https://h/.well-known/oauth-authorization-server/mcp',
        ], $asked->urls, 'the issuer leg tried the origin form; the fallback leg skipped it rather than asking again');

        [$discovery] = $this->discoveryOver([
            'https://h/.well-known/oauth-protected-resource' => ['authorization_servers' => ['https://h']],
            'https://h/.well-known/oauth-authorization-server' => $metadata,
        ]);
        self::assertSame($metadata, $discovery->discover('https://h/mcp'));
    }

    public function testAnUnresolvableIssuerFallsBackToTheServersOwnMetadata(): void
    {
        $metadata = ['token_endpoint' => 'https://h/token'];
        [$discovery, $asked] = $this->discoveryOver([
            'https://h/.well-known/oauth-protected-resource/mcp' => ['authorization_servers' => ['https://gone.example.test']],
            'https://h/.well-known/oauth-authorization-server' => $metadata,
        ]);

        self::assertSame($metadata, $discovery->discover('https://h/mcp'));
        self::assertSame([
            'https://h/.well-known/oauth-protected-resource/mcp',
            'https://gone.example.test/.well-known/oauth-authorization-server',
            'https://h/.well-known/oauth-authorization-server/mcp',
            'https://h/.well-known/oauth-authorization-server',
        ], $asked->urls);
    }

    /**
     * A 401/404 that still carries a JSON object (e.g. `{"error": ...}`) is
     * an error body, not metadata — discovery keeps looking.
     */
    public function testAJsonErrorBodyOrAListIsNotMetadata(): void
    {
        $metadata = ['registration_endpoint' => 'https://h/register'];
        [$discovery] = $this->discoveryOver([
            'https://h/.well-known/oauth-protected-resource/mcp' => ['error' => 'invalid_token'],
            'https://h/.well-known/oauth-protected-resource' => ['authorization_servers' => []],
            'https://h/.well-known/oauth-authorization-server/mcp' => ['error' => 'invalid_token', 'error_description' => 'nope'],
            'https://h/.well-known/oauth-authorization-server' => $metadata,
        ]);

        self::assertSame($metadata, $discovery->discover('https://h/mcp'));

        [$listOnly] = $this->discoveryOver(['https://h/.well-known/oauth-authorization-server' => ['https://h/token']]);
        self::assertSame([], $listOnly->discover('https://h'));
    }

    public function testANonUrlServerMakesNoRequest(): void
    {
        [$discovery, $asked] = $this->discoveryOver([]);

        self::assertSame([], $discovery->discover('not a url'));
        self::assertSame([], $asked->urls);
    }

    /**
     * @param array<string, array<mixed>> $table
     * @return array{OAuthDiscovery, \stdClass}
     */
    private function discoveryOver(array $table): array
    {
        $asked = new \stdClass();
        $asked->urls = [];

        $discovery = new OAuthDiscovery(static function (string $url) use ($table, $asked): array {
            $asked->urls[] = $url;
            if (!isset($table[$url])) {
                throw new \RuntimeException("HTTP 404 from {$url}");
            }

            return $table[$url];
        });

        return [$discovery, $asked];
    }
}
