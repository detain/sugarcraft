<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\AuthEntry;
use SugarCraft\Crush\MCP\OAuthClientRegistration;

// AuthEntry is defined in the same file as OAuthClientRegistration
require_once __DIR__ . '/../../src/MCP/OAuthClientRegistration.php';

/**
 * E701: the authorization-code leg of the login flow — the POST that trades
 * a code + PKCE verifier for tokens — and the store-side consequence of its
 * OPTIONAL refresh_token (the buffer-window guard fix).
 *
 * Hermetic by construction: every wire answer is a Guzzle MockHandler
 * response and the auth file lives under a temp dir; nothing here opens a
 * socket, reads $HOME, or sleeps.
 *
 * @see OAuthClientRegistration::exchangeAuthorizationCode()
 */
final class OAuthAuthorizationCodeExchangeTest extends TestCase
{
    private string $tempDir;
    private string $authFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/oauth_code_test_' . uniqid((string) getmypid(), true);
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

    /**
     * @param list<Response> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function registration(array $responses, array &$history = []): OAuthClientRegistration
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new OAuthClientRegistration(new Client(['handler' => $stack]), $this->authFilePath);
    }

    /**
     * @return array<string, string>
     */
    private function formOf(array $history, int $index): array
    {
        $body = (string) $history[$index]['request']->getBody();
        self::assertSame(
            'application/x-www-form-urlencoded',
            $history[$index]['request']->getHeaderLine('Content-Type'),
        );
        parse_str($body, $form);

        /** @var array<string, string> $form */
        return $form;
    }

    public function testTheExchangePostsTheAuthorizationCodeForm(): void
    {
        $history = [];
        $oauth = $this->registration([new Response(200, [], json_encode(['access_token' => 'tok-1', 'expires_in' => 3600]))], $history);

        $oauth->exchangeAuthorizationCode(
            'https://auth.invalid/token',
            'cid-9',
            'sec-9',
            'code-abc',
            'http://127.0.0.1:43210/callback',
            'verifier-xyz',
        );

        $form = $this->formOf($history, 0);
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertSame('code-abc', $form['code']);
        self::assertSame('http://127.0.0.1:43210/callback', $form['redirect_uri'], 'the redirect must repeat byte-for-byte what the authorize URL carried');
        self::assertSame('cid-9', $form['client_id']);
        self::assertSame('verifier-xyz', $form['code_verifier'], 'a missing or wrong verifier is the whole point of PKCE');
        self::assertSame('sec-9', $form['client_secret']);
    }

    public function testAnEmptyClientSecretIsNotPostedAtAll(): void
    {
        $history = [];
        $oauth = $this->registration([new Response(200, [], json_encode(['access_token' => 'tok-1', 'expires_in' => 60]))], $history);

        $oauth->exchangeAuthorizationCode('https://auth.invalid/token', 'cid-9', '', 'code', 'http://127.0.0.1:1/c', 'v');

        self::assertArrayNotHasKey('client_secret', $this->formOf($history, 0), 'public clients carry no secret; posting an empty one reads as a secret to some servers');
    }

    public function testTheExchangeParsesTokensExpiryAndScope(): void
    {
        $oauth = $this->registration([new Response(200, [], json_encode([
            'access_token' => 'at-77',
            'refresh_token' => 'rt-88',
            'expires_in' => '7200',
            'scope' => 'read write projects',
        ]))]);

        $token = $oauth->exchangeAuthorizationCode('https://auth.invalid/token', 'cid', 'sec', 'code', 'http://127.0.0.1:1/c', 'v');

        self::assertSame('at-77', $token['accessToken']);
        self::assertSame('rt-88', $token['refreshToken']);
        self::assertSame(7200, $token['expiresIn'], 'a string expires_in off the wire must arrive typed');
        self::assertSame(['read', 'write', 'projects'], $token['scopes']);
    }

    public function testAnOmittedRefreshTokenAndScopeArriveAsEmptyValues(): void
    {
        $oauth = $this->registration([new Response(200, [], json_encode([
            'access_token' => 'at-only',
            'expires_in' => 60,
        ]))]);

        $token = $oauth->exchangeAuthorizationCode('https://auth.invalid/token', 'cid', '', 'code', 'http://127.0.0.1:1/c', 'v');

        self::assertSame('', $token['refreshToken'], 'an omitted refresh_token is the honest empty string, not a null to paper over later');
        self::assertSame([], $token['scopes']);
    }

    public function testAMissingAccessTokenOrExpiryThrows(): void
    {
        foreach (['{"access_token":"a"}', '{"expires_in":10}', '[]'] as $body) {
            $oauth = $this->registration([new Response(200, [], $body)]);

            try {
                $oauth->exchangeAuthorizationCode('https://auth.invalid/token', 'cid', '', 'code', 'http://127.0.0.1:1/c', 'v');
                self::fail('an entry that cannot say WHEN it dies must not be minted from body: ' . $body);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('access_token or expires_in', $e->getMessage());
            }
        }
    }

    public function testTheAuthCodeEntryShapeIsTheNineKeyRoster(): void
    {
        $entry = new AuthEntry(
            clientId: 'cid',
            clientSecret: '',
            registrationAccessToken: 'reg',
            accessToken: 'at',
            refreshToken: '',
            expiresAt: time() + 60,
            scopes: ['read'],
            tokenUrl: 'https://auth.invalid/token',
            registrationUrl: 'https://auth.invalid/register',
        );

        self::assertSame(
            ['clientId', 'clientSecret', 'registrationAccessToken', 'accessToken', 'refreshToken', 'expiresAt', 'scopes', 'tokenUrl', 'registrationUrl'],
            array_keys($entry->toArray()),
            'the on-disk shape is shared by `add` and `login`; a tenth key here means AuthEntry grew a field the legacy readers never saw',
        );
    }

    // =========================================================================
    // E701 DEFECT FIX — the buffer window must not refresh a refresh-less entry
    // =========================================================================

    public function testTheBufferWindowServesARefreshlessEntryAsIsAndTouchesNoWire(): void
    {
        $history = [];
        $oauth = $this->registration([], $history);

        $entry = new AuthEntry(
            clientId: 'cid',
            clientSecret: 'sec',
            registrationAccessToken: 'reg',
            accessToken: 'at-live',
            refreshToken: '',
            expiresAt: time() + 60,
            scopes: [],
            tokenUrl: 'https://auth.invalid/token',
            registrationUrl: 'https://auth.invalid/register',
        );
        $oauth->saveAuth('https://mcp.invalid/api', $entry);
        $oauth->clearCache();

        $served = $oauth->getValidAuth('https://mcp.invalid/api', 'https://auth.invalid/token');

        self::assertNotNull($served);
        self::assertSame('at-live', $served->accessToken);
        self::assertSame([], $history, 'a 60-seconds-from-expiry entry with NO refresh token must be served as-is — the defect this guard exists for was a doomed wire call on every request inside the window');
    }

    public function testTheBufferWindowStillRefreshesAnEntryThatHasARefreshToken(): void
    {
        $history = [];
        $oauth = $this->registration([new Response(200, [], json_encode([
            'access_token' => 'at-rotated',
            'refresh_token' => 'rt-rotated',
            'expires_in' => 3600,
        ]))], $history);

        $entry = new AuthEntry(
            clientId: 'cid',
            clientSecret: 'sec',
            registrationAccessToken: 'reg',
            accessToken: 'at-old',
            refreshToken: 'rt-old',
            expiresAt: time() + 60,
            scopes: [],
            tokenUrl: 'https://auth.invalid/token',
            registrationUrl: '',
        );
        $oauth->saveAuth('https://mcp.invalid/api', $entry);
        $oauth->clearCache();

        $served = $oauth->getValidAuth('https://mcp.invalid/api', 'https://auth.invalid/token');

        self::assertNotNull($served);
        self::assertSame('at-rotated', $served->accessToken, 'the fix narrows the refresh call, it does not remove it');
        self::assertCount(1, $history);
        $form = $this->formOf($history, 0);
        self::assertSame('refresh_token', $form['grant_type']);
        self::assertSame('rt-old', $form['refresh_token']);
    }

    public function testAnExpiredRefreshlessEntryWithoutARegistrationUrlStillThrowsHonestly(): void
    {
        $oauth = $this->registration([]);

        $entry = new AuthEntry(
            clientId: 'cid',
            clientSecret: '',
            registrationAccessToken: 'reg',
            accessToken: 'at-dead',
            refreshToken: '',
            expiresAt: time() - 1,
            scopes: [],
            tokenUrl: 'https://auth.invalid/token',
            registrationUrl: '',
        );
        $oauth->saveAuth('https://mcp.invalid/api', $entry);
        $oauth->clearCache();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired with no refresh token');

        $oauth->getValidAuth('https://mcp.invalid/api', 'https://auth.invalid/token');
    }
}
