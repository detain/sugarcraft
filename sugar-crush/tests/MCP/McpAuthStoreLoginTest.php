<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpAuthStore;
use SugarCraft\Crush\MCP\OAuthClientRegistration;
use SugarCraft\Crush\MCP\OAuthLoopbackFlow;
use SugarCraft\Crush\MCP\OAuthPkce;

// OAuthClientRegistration carries AuthEntry in the same file (project law).
require_once __DIR__ . '/../../src/MCP/OAuthClientRegistration.php';

/**
 * E701: the whole `login()` end to end — discovery, loopback bind, dynamic
 * registration carrying the bound redirect URI, the printed authorize URL,
 * the callback verdict, the PKCE exchange, and the on-disk entry — against a
 * Guzzle mock and an in-process browser hook. Zero network egress, zero
 * $HOME writes (the store is pinned to a temp file), no threads.
 *
 * The keystone assertion is the challenge binding: the code_challenge in the
 * printed URL must be S256 of the code_verifier that reached the token form.
 * If the flow ever minted one verifier for the redirect and another for the
 * exchange, strict servers reject the code — and this test alone proves the
 * two halves are the same pair.
 *
 * @see OAuthLoopbackFlow::login()
 */
final class McpAuthStoreLoginTest extends TestCase
{
    private const SERVER = 'https://mcp.example.test/server';
    private const REGISTRATION_URL = 'https://auth.example.test/register';
    private const TOKEN_URL = 'https://auth.example.test/token';
    private const AUTHORIZE_URL = 'https://auth.example.test/authorize';

    private string $tempDir;
    private string $authFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/login_test_' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0700, true);
        $this->authFilePath = $this->tempDir . '/auth.json';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tempDir)) {
            foreach ((array) glob($this->tempDir . '/*') as $file) {
                unlink((string) $file);
            }
            rmdir($this->tempDir);
        }
    }

    public function testLoginRunsTheWholeFlowAndPersistsTheNineKeyEntry(): void
    {
        $history = [];
        $flow = $this->flow([$this->registrationResponse(), $this->exchangeResponse()], $history, $discovered);

        $browser = $this->browserReturning('code-canary-777', $requests);

        ob_start();
        $rc = $flow->login(self::SERVER, null, null, 5.0, $browser);
        $output = (string) ob_get_clean();

        self::assertSame(0, $rc, $output);
        self::assertIsString($discovered);
        self::assertStringEndsWith('/.well-known/oauth-authorization-server', $discovered, 'discovery rides the RFC 8414 well-known path');

        // The printed surface: the URL + the result lines, and NOTHING secret.
        self::assertStringContainsString('✓ Signed in `' . self::SERVER . '`', $output);
        self::assertStringContainsString('Client ID: `cid-login`', $output);
        self::assertStringNotContainsString('at-login-value', $output, 'the access token never touches the terminal');
        self::assertStringNotContainsString('rt-login-value', $output, 'nor the refresh token');
        self::assertStringNotContainsString('secret-cid-login', $output, 'nor the client secret');
        self::assertStringNotContainsString('code-canary-777', $output, 'nor the authorization code');

        // Wire leg 1 — registration advertised exactly the bound loopback URI.
        $registerBody = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertIsArray($registerBody);
        $redirectUri = (string) $this->authorizeParams($output)['redirect_uri'];
        self::assertSame([$redirectUri], $registerBody['client_metadata']['redirect_uris'], 'the registered redirect URI is byte-identical to the authorize URL (metadata rides the RFC 7591 client_metadata envelope)');

        // Wire leg 2 — the exchange posted the code, the verifier, and nothing else secret-shaped.
        parse_str((string) $history[1]['request']->getBody(), $exchangeForm);
        self::assertSame('authorization_code', $exchangeForm['grant_type']);
        self::assertSame('code-canary-777', $exchangeForm['code']);
        self::assertSame($redirectUri, $exchangeForm['redirect_uri']);
        self::assertSame('cid-login', $exchangeForm['client_id']);
        self::assertSame('secret-cid-login', $exchangeForm['client_secret']);

        // The keystone: printed challenge == S256(exchanged verifier).
        $params = $this->authorizeParams($output);
        self::assertSame(
            OAuthPkce::challengeFor((string) $exchangeForm['code_verifier']),
            $params['code_challenge'],
            'the URL and the form carry the two halves of ONE PKCE pair',
        );
        self::assertSame('code', $params['response_type']);
        self::assertSame(OAuthPkce::METHOD, $params['code_challenge_method']);

        // On-disk entry — same nine-key shape `add` writes, response scopes, endpoint carries.
        $persisted = (new OAuthClientRegistration(new Client(), $this->authFilePath))->loadAuth();
        self::assertArrayHasKey(self::SERVER, $persisted);
        $entry = $persisted[self::SERVER];
        self::assertSame('cid-login', $entry->clientId);
        self::assertSame('secret-cid-login', $entry->clientSecret);
        self::assertSame('reg-login-token', $entry->registrationAccessToken);
        self::assertSame('at-login-value', $entry->accessToken);
        self::assertSame('rt-login-value', $entry->refreshToken);
        self::assertGreaterThanOrEqual(time() + 3600 - 5, $entry->expiresAt);
        self::assertLessThanOrEqual(time() + 3600, $entry->expiresAt);
        self::assertSame(['read', 'write'], $entry->scopes, 'scopes come FROM THE RESPONSE');
        self::assertSame(self::TOKEN_URL, $entry->tokenUrl);
        self::assertSame(self::REGISTRATION_URL, $entry->registrationUrl);

        $this->closeAll($requests);
    }

    public function testAMismatchedCallbackStoresNothingAndStaysReRunnable(): void
    {
        $history = [];
        $flow = $this->flow([$this->registrationResponse()], $history, $_discovered);

        // The "browser" returns a state the flow never asked for.
        $browser = $this->browserReturning('code-canary-777', $requests, stateOverride: 'attacker-state');

        ob_start();
        $rc = $flow->login(self::SERVER, null, null, 5.0, $browser);
        $output = (string) ob_get_clean();

        self::assertSame(1, $rc);
        self::assertStringContainsString('✗ Login failed', $output);
        self::assertStringContainsString('state did not match', $output);
        self::assertStringContainsString('Nothing was stored', $output);
        self::assertStringNotContainsString('code-canary-777', $output, 'even the rejection names no request value');
        self::assertFileDoesNotExist($this->authFilePath, 'a half-done login leaves no auth file behind');

        $this->closeAll($requests);

        // Re-runnable: the next login binds a fresh listener and completes.
        $retryHistory = [];
        $retry = $this->flow([$this->registrationResponse(), $this->exchangeResponse()], $retryHistory, $_again);
        $retryBrowser = $this->browserReturning('code-canary-888', $moreRequests);

        ob_start();
        $rc2 = $retry->login(self::SERVER, null, null, 5.0, $retryBrowser);
        $output2 = (string) ob_get_clean();

        self::assertSame(0, $rc2, $output2);
        self::assertStringContainsString('✓ Signed in', $output2);

        $this->closeAll($moreRequests);
    }

    public function testAnUndiscoverableServerFailsWithTwoLinesAndNoWireCalls(): void
    {
        $history = [];
        $flow = $this->flow([], $history, $_url, metadata: []);

        ob_start();
        $rc = $flow->login(self::SERVER, null, null, 5.0);
        $output = (string) ob_get_clean();

        self::assertSame(1, $rc);
        self::assertStringContainsString('could not be discovered', $output);
        self::assertSame([], $history, 'nothing was asked of the network when discovery is empty');
    }

    public function testAPositionalTokenUrlOverridesTheDiscoveredOne(): void
    {
        $history = [];
        $flow = $this->flow([$this->registrationResponse(), $this->exchangeResponse()], $history, $_url);

        $browser = $this->browserReturning('code-canary-999', $requests);

        ob_start();
        $rc = $flow->login(self::SERVER, 'https://alt-token.example.test/token', null, 5.0, $browser);
        $output = (string) ob_get_clean();

        self::assertSame(0, $rc, $output);
        self::assertStringContainsString('https://alt-token.example.test/token', (string) $history[1]['request']->getUri());
        $persisted = (new OAuthClientRegistration(new Client(), $this->authFilePath))->loadAuth();
        self::assertSame('https://alt-token.example.test/token', $persisted[self::SERVER]->tokenUrl, 'the override is what gets carried');

        $this->closeAll($requests);
    }

    // =========================================================================
    // Harness
    // =========================================================================

    /**
     * @param list<Response>                    $responses
     * @param list<array<string, mixed>>        $history   Guzzle history container, by reference
     * @param array<string, mixed>              $metadata
     */
    private function flow(array $responses, array &$history, ?string &$discoveredUrl, array $metadata = [
        'registration_endpoint' => self::REGISTRATION_URL,
        'token_endpoint' => self::TOKEN_URL,
        'authorization_endpoint' => self::AUTHORIZE_URL,
    ]): OAuthLoopbackFlow {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $store = new McpAuthStore(new OAuthClientRegistration(new Client(['handler' => $stack]), $this->authFilePath));

        return new OAuthLoopbackFlow(
            $store->oauth(),
            static function (string $url) use ($metadata, &$discoveredUrl): array {
                $discoveredUrl = $url;

                return $metadata;
            },
        );
    }

    private function registrationResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'client_id' => 'cid-login',
            'client_secret' => 'secret-cid-login',
            'registration_access_token' => 'reg-login-token',
        ], JSON_THROW_ON_ERROR));
    }

    private function exchangeResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'at-login-value',
            'refresh_token' => 'rt-login-value',
            // A STRING on the wire — real servers do this; the exchange must cast.
            'expires_in' => '3600',
            'scope' => 'read write',
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The in-process browser: parse the printed authorize URL, connect to the
     * loopback port it names, and deliver the callback with the flow's own
     * state. The socket stays open (backed into $requests) so the test can
     * read the static answer after login returns.
     *
     * @param list<resource>|null $requests
     * @return callable(string): void
     */
    private function browserReturning(string $code, ?array &$requests, ?string $stateOverride = null): callable
    {
        $requests = [];

        return function (string $url) use ($code, &$requests, $stateOverride): void {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
            $redirect = (string) $params['redirect_uri'];
            $port = (int) parse_url($redirect, PHP_URL_PORT);
            $path = (string) parse_url($redirect, PHP_URL_PATH);
            $state = $stateOverride ?? (string) $params['state'];

            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
            self::assertNotFalse($socket, "browser hook could not reach {$redirect}: {$errstr}");
            $requests[] = $socket;

            self::assertNotFalse(fwrite($socket, "GET {$path}?code={$code}&state={$state} HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n"));
        };
    }

    /**
     * @param list<resource> $requests
     */
    private function closeAll(array $requests): void
    {
        foreach ($requests as $socket) {
            if (\is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function authorizeParams(string $output): array
    {
        self::assertSame(1, preg_match('#^\s+(https?://\S+authorize\S*)$#m', $output, $m), 'the flow prints the authorize URL on its own line');
        parse_str((string) parse_url($m[1], PHP_URL_QUERY), $params);
        self::assertIsArray($params);

        /** @var array<string, string> $params */
        return $params;
    }
}
