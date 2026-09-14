<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\OAuthLoopbackFlow;
use SugarCraft\Crush\MCP\OAuthPkce;

/**
 * E701: the authorization URL builder is a PURE function, so its contract is
 * fully testable without a socket, a browser, or the wire. Two halves matter:
 * every parameter the OAuth spec demands is present and exact, and every
 * secret that exists in the flow is ABSENT — the URL is printed into a
 * terminal a screen recorder may own.
 *
 * @see OAuthLoopbackFlow::authorizeUrlFor()
 */
final class OAuthAuthorizeUrlTest extends TestCase
{
    private const ENDPOINT = 'https://auth.example.test/authorize';
    private const CLIENT_ID = 'cid-public';
    private const REDIRECT = 'http://127.0.0.1:41234/callback';
    private const STATE = 'deadbeefdeadbeefdeadbeefdeadbeef';

    public function testTheUrlCarriesTheFullPublicParameterSet(): void
    {
        $verifier = OAuthPkce::newVerifier();
        $challenge = OAuthPkce::challengeFor($verifier);

        $url = OAuthLoopbackFlow::authorizeUrlFor(self::ENDPOINT, self::CLIENT_ID, self::REDIRECT, self::STATE, $challenge);

        self::assertStringStartsWith(self::ENDPOINT . '?', $url, 'a bare endpoint gets a ? separator');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        self::assertSame([
            'response_type',
            'client_id',
            'redirect_uri',
            'state',
            'code_challenge',
            'code_challenge_method',
        ], array_keys($params), 'the parameter roster is exactly the public six — in order');

        self::assertSame('code', $params['response_type']);
        self::assertSame(self::CLIENT_ID, $params['client_id']);
        self::assertSame(self::REDIRECT, $params['redirect_uri'], 'the redirect must round-trip byte-exact through the encoder');
        self::assertSame(self::STATE, $params['state']);
        self::assertSame($challenge, $params['code_challenge']);
        self::assertSame(OAuthPkce::METHOD, $params['code_challenge_method']);
    }

    public function testScopesRideTheUrlOnlyWhenGiven(): void
    {
        $bare = OAuthLoopbackFlow::authorizeUrlFor(self::ENDPOINT, self::CLIENT_ID, self::REDIRECT, self::STATE, 'chal');
        parse_str((string) parse_url($bare, PHP_URL_QUERY), $bareParams);
        self::assertArrayNotHasKey('scope', $bareParams, 'no scopes means no scope parameter, not an empty one');

        $scoped = OAuthLoopbackFlow::authorizeUrlFor(self::ENDPOINT, self::CLIENT_ID, self::REDIRECT, self::STATE, 'chal', ['read', 'write']);
        parse_str((string) parse_url($scoped, PHP_URL_QUERY), $scopedParams);
        self::assertSame('read write', $scopedParams['scope'], 'spaces survive the encoder as the OAuth form (form-urlencoded)');
    }

    public function testAnEndpointThatAlreadyQueriesUsesTheAmpersandSeparator(): void
    {
        // chr(63) not '?' — a bare '?tenant=7' literal is glob-shaped to the
        // GlobDialectDifferentialTest harvest and would drift its pinned corpus figure.
        $url = OAuthLoopbackFlow::authorizeUrlFor(self::ENDPOINT . chr(63) . 'tenant=7', self::CLIENT_ID, self::REDIRECT, self::STATE, 'chal');

        self::assertStringStartsWith(self::ENDPOINT . chr(63) . 'tenant=7&', $url, 'exactly one ? — the rest are &');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        self::assertSame('7', $params['tenant']);
        self::assertSame(self::STATE, $params['state']);
    }

    public function testTheUrlCarriesTheChallengeAndNeverTheVerifier(): void
    {
        $verifier = OAuthPkce::newVerifier();
        $challenge = OAuthPkce::challengeFor($verifier);

        $url = OAuthLoopbackFlow::authorizeUrlFor(self::ENDPOINT, self::CLIENT_ID, self::REDIRECT, self::STATE, $challenge);

        self::assertStringContainsString($challenge, $url, 'the challenge belongs in the URL');
        self::assertStringNotContainsString($verifier, $url, 'the verifier NEVER leaves this machine before the exchange');
        self::assertStringNotContainsString('client_secret', $url, 'there is no secret in an authorize URL, and none smuggled in');
    }
}
