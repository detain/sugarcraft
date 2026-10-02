<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\McpAuthCommand;
use SugarCraft\Crush\MCP\AuthEntry;
use SugarCraft\Crush\MCP\McpAuthStore;
use SugarCraft\Crush\MCP\OAuthClientRegistration;

// AuthEntry is defined in the same file as OAuthClientRegistration
require_once __DIR__ . '/../../src/MCP/OAuthClientRegistration.php';

/**
 * @see OAuthClientRegistration
 * @see AuthEntry
 */
final class OAuthClientRegistrationTest extends TestCase
{
    private string $tempDir;
    private string $authFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/oauth_test_' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0700, true);
        $this->authFilePath = $this->tempDir . '/auth.json';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
    }

    // =========================================================================
    // AuthEntry Tests
    // =========================================================================

    public function testAuthEntryRoundTrip(): void
    {
        $entry = new AuthEntry(
            clientId: 'client-123',
            clientSecret: 'secret-abc',
            registrationAccessToken: 'reg-token-xyz',
            accessToken: 'access-token-456',
            refreshToken: 'refresh-token-789',
            expiresAt: time() + 3600,
            scopes: ['read', 'write'],
        );

        $array = $entry->toArray();
        $restored = AuthEntry::fromArray($array);

        $this->assertSame($entry->clientId, $restored->clientId);
        $this->assertSame($entry->clientSecret, $restored->clientSecret);
        $this->assertSame($entry->registrationAccessToken, $restored->registrationAccessToken);
        $this->assertSame($entry->accessToken, $restored->accessToken);
        $this->assertSame($entry->refreshToken, $restored->refreshToken);
        $this->assertSame($entry->expiresAt, $restored->expiresAt);
        $this->assertSame($entry->scopes, $restored->scopes);
    }

    public function testAuthEntryFromArrayWithMissingFields(): void
    {
        $entry = AuthEntry::fromArray(['clientId' => 'cid']);

        $this->assertSame('cid', $entry->clientId);
        $this->assertSame('', $entry->clientSecret);
        $this->assertSame('', $entry->registrationAccessToken);
        $this->assertSame('', $entry->accessToken);
        $this->assertSame('', $entry->refreshToken);
        $this->assertNull($entry->expiresAt);
        $this->assertSame([], $entry->scopes);
        // E695 keys may be ABSENT ENTIRELY on a pre-E695 auth.json — fromArray
        // defaults both to '' (r77-rv-la MINOR-2: the on-disk legacy shape was
        // only proven by a reviewer's temp probe; this pins it in the suite).
        $this->assertSame('', $entry->tokenUrl);
        $this->assertSame('', $entry->registrationUrl);
    }

    public function testAuthEntryIsExpired(): void
    {
        $expired = new AuthEntry(
            clientId: 'c',
            clientSecret: '',
            registrationAccessToken: '',
            accessToken: '',
            refreshToken: '',
            expiresAt: time() - 10,
            scopes: [],
        );

        $valid = new AuthEntry(
            clientId: 'c',
            clientSecret: '',
            registrationAccessToken: '',
            accessToken: '',
            refreshToken: '',
            expiresAt: time() + 3600,
            scopes: [],
        );

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($valid->isExpired());
    }

    public function testAuthEntryExpiresWithin(): void
    {
        $entry = new AuthEntry(
            clientId: 'c',
            clientSecret: '',
            registrationAccessToken: '',
            accessToken: '',
            refreshToken: '',
            expiresAt: time() + 60,
            scopes: [],
        );

        $this->assertTrue($entry->expiresWithin(120));
        $this->assertFalse($entry->expiresWithin(30));
    }

    // =========================================================================
    // loadAuth / saveAuth Tests
    // =========================================================================

    public function testLoadAuthReturnsEmptyArrayWhenFileDoesNotExist(): void
    {
        $ocr = new OAuthClientRegistration(null, $this->authFilePath . '/nonexistent.json');
        $this->assertSame([], $ocr->loadAuth());
    }

    public function testLoadAuthReturnsEmptyArrayWhenFileContainsInvalidJson(): void
    {
        file_put_contents($this->authFilePath, 'not valid json');
        $ocr = new OAuthClientRegistration(null, $this->authFilePath);
        $this->assertSame([], $ocr->loadAuth());
    }

    public function testSaveAndLoadAuthRoundTrip(): void
    {
        $ocr = new OAuthClientRegistration(null, $this->authFilePath);

        $entry = new AuthEntry(
            clientId: 'client-123',
            clientSecret: 'secret-abc',
            registrationAccessToken: 'reg-token-xyz',
            accessToken: 'access-token-456',
            refreshToken: 'refresh-token-789',
            expiresAt: time() + 3600,
            scopes: ['read'],
        );

        $ocr->saveAuth('https://example.com/mcp', $entry);

        $loaded = $ocr->loadAuth();
        $this->assertArrayHasKey('https://example.com/mcp', $loaded);
        $this->assertSame('client-123', $loaded['https://example.com/mcp']->clientId);
        $this->assertSame('access-token-456', $loaded['https://example.com/mcp']->accessToken);
        $this->assertSame(['read'], $loaded['https://example.com/mcp']->scopes);
    }

    public function testAuthFilePermissionsAre0600(): void
    {
        $ocr = new OAuthClientRegistration(null, $this->authFilePath);

        $entry = new AuthEntry(
            clientId: 'client-123',
            clientSecret: 'secret-abc',
            registrationAccessToken: 'reg-token-xyz',
            accessToken: 'access-token-456',
            refreshToken: 'refresh-token-789',
            expiresAt: time() + 3600,
            scopes: [],
        );

        $ocr->saveAuth('https://example.com/mcp', $entry);

        $perms = fileperms($this->authFilePath) & 0777;
        $this->assertSame(0600, $perms, 'Auth file must have 0600 (owner-only) permissions');
    }

    public function testDeleteAuthRemovesEntry(): void
    {
        $ocr = new OAuthClientRegistration(null, $this->authFilePath);

        $entry = new AuthEntry(
            clientId: 'c',
            clientSecret: '',
            registrationAccessToken: '',
            accessToken: '',
            refreshToken: '',
            expiresAt: time() + 3600,
            scopes: [],
        );

        $ocr->saveAuth('https://example.com/mcp', $entry);
        $ocr->deleteAuth('https://example.com/mcp');

        $this->assertSame([], $ocr->loadAuth());
    }

    // =========================================================================
    // getValidAuth Tests
    // =========================================================================

    public function testGetValidAuthReturnsNullWhenNoEntryExists(): void
    {
        $ocr = new OAuthClientRegistration(null, $this->authFilePath);
        $result = $ocr->getValidAuth('https://example.com/mcp', 'https://example.com/token');
        $this->assertNull($result);
    }

    public function testGetValidAuthReturnsEntryWhenNotExpired(): void
    {
        $ocr = new OAuthClientRegistration(null, $this->authFilePath);

        $entry = new AuthEntry(
            clientId: 'client-123',
            clientSecret: 'secret-abc',
            registrationAccessToken: 'reg-token-xyz',
            accessToken: 'access-token-456',
            refreshToken: 'refresh-token-789',
            expiresAt: time() + 3600,
            scopes: ['read'],
        );

        $ocr->saveAuth('https://example.com/mcp', $entry);

        $result = $ocr->getValidAuth('https://example.com/mcp', 'https://example.com/token');
        $this->assertNotNull($result);
        $this->assertSame('client-123', $result->clientId);
        $this->assertSame('access-token-456', $result->accessToken);
    }

    public function testGetValidAuthRefreshesExpiredToken(): void
    {
        $mockResponse = new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 7200,
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);

        $ocr = new OAuthClientRegistration($httpClient, $this->authFilePath);

        $entry = new AuthEntry(
            clientId: 'client-123',
            clientSecret: 'secret-abc',
            registrationAccessToken: 'reg-token-xyz',
            accessToken: 'old-access-token',
            refreshToken: 'refresh-token-789',
            expiresAt: time() - 100, // expired
            scopes: ['read'],
        );

        $ocr->saveAuth('https://example.com/mcp', $entry);

        $result = $ocr->getValidAuth('https://example.com/mcp', 'https://example.com/token');

        $this->assertNotNull($result);
        $this->assertSame('new-access-token', $result->accessToken);
        $this->assertSame('new-refresh-token', $result->refreshToken);
        $this->assertGreaterThan(time(), $result->expiresAt);
    }

    public function testGetValidAuthRefreshesWithinBufferWindow(): void
    {
        $mockResponse = new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 7200,
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);

        $ocr = new OAuthClientRegistration($httpClient, $this->authFilePath);

        // Expires within the 300-second buffer window
        $entry = new AuthEntry(
            clientId: 'client-123',
            clientSecret: 'secret-abc',
            registrationAccessToken: 'reg-token-xyz',
            accessToken: 'old-access-token',
            refreshToken: 'refresh-token-789',
            expiresAt: time() + 100, // within buffer, will trigger refresh
            scopes: ['read'],
        );

        $ocr->saveAuth('https://example.com/mcp', $entry);

        $result = $ocr->getValidAuth('https://example.com/mcp', 'https://example.com/token');

        $this->assertNotNull($result);
        $this->assertSame('new-access-token', $result->accessToken);
    }

    public function testGetValidAuthWithExpiredEntryAndEmptyRefreshTokenThrows(): void
    {
        $ocr = new OAuthClientRegistration(null, $this->authFilePath);

        $entry = new AuthEntry(
            clientId: 'client-123',
            clientSecret: 'secret-abc',
            registrationAccessToken: 'reg-token-xyz',
            accessToken: 'expired-access-token',
            refreshToken: '', // empty — cannot be renewed without the user
            expiresAt: time() - 100,
            scopes: ['read'],
        );

        $ocr->saveAuth('https://example.com/mcp', $entry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has expired and the server issued no refresh token');

        $ocr->getValidAuth('https://example.com/mcp', 'https://example.com/token');
    }

    // =========================================================================
    // MCP-8: an expired refresh-less login asks for re-login; nothing is
    // persisted before a token exchange succeeds
    // =========================================================================

    /**
     * The audit repro. The old arm "re-registered" (a body-less PUT to the
     * registration endpoint), SAVED an entry whose access token was the new
     * client id with no expiry, and only then tried a client_credentials
     * grant. The server refused that grant, and the never-expiring
     * `Bearer cid2` stayed on disk for good. Now: the stored entry is
     * byte-for-byte what it was, and the error names the command to run.
     */
    public function testExpiredRefreshlessLoginLeavesTheStoreUntouchedAndAsksForReLogin(): void
    {
        $history = [];
        $ocr = new OAuthClientRegistration($this->clientRecording([
            new Response(200, [], (string) json_encode(['client_id' => 'cid2', 'registration_access_token' => 'rat2'])),
            new Response(400, [], (string) json_encode(['error' => 'unauthorized_client'])),
        ], $history), $this->authFilePath);
        $ocr->saveAuth('https://z/mcp', $this->storeEntry('Z1', time() - 10));
        $before = (string) file_get_contents($this->authFilePath);

        $caught = null;
        try {
            // validAuthFor() is the per-request path; it used to hand the
            // stored registrationUrl in, which is what armed the old arm.
            $ocr->validAuthFor('https://z/mcp');
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'an expired login with no refresh token must not be served');
        $this->assertStringContainsString('sugarcrush mcp auth login https://z/mcp', $caught->getMessage());
        $this->assertSame($before, (string) file_get_contents($this->authFilePath), 'the stored entry must be left exactly as it was');

        $stored = (new OAuthClientRegistration(null, $this->authFilePath))->loadAuth()['https://z/mcp'];
        $this->assertSame('Z1', $stored->accessToken, 'the client id must never be stored as a bearer token');
        $this->assertNotNull($stored->expiresAt);
        $this->assertTrue($stored->isExpired(), 'the entry is still the honest expired one, not a never-expiring replacement');
    }

    public function testExpiredRefreshlessLoginSendsNoRequestAtAll(): void
    {
        $history = [];
        $ocr = new OAuthClientRegistration($this->clientRecording([
            new Response(200, [], (string) json_encode(['client_id' => 'cid2', 'registration_access_token' => 'rat2'])),
            new Response(200, [], (string) json_encode(['access_token' => 'guessed', 'expires_in' => 3600])),
        ], $history), $this->authFilePath);
        $ocr->saveAuth('https://z/mcp', $this->storeEntry('Z1', time() - 10));

        foreach ([
            fn () => $ocr->getValidAuth('https://z/mcp', 'https://as/token'),
            fn () => $ocr->validAuthFor('https://z/mcp'),
        ] as $call) {
            $caught = null;
            try {
                $call();
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            $this->assertNotNull($caught, 'an expired refresh-less entry must throw');
            $this->assertStringContainsString('mcp auth login', $caught->getMessage());
        }

        $this->assertSame([], $history, 'no grant is guessed: neither a registration update nor a client_credentials token request may be sent');
    }

    public function testAFailedRefreshLeavesTheStoredEntryUntouched(): void
    {
        $ocr = new OAuthClientRegistration($this->clientAnswering([
            new Response(400, [], (string) json_encode(['error' => 'invalid_grant'])),
        ]), $this->authFilePath);
        $ocr->saveAuth('https://z/mcp', $this->storeEntry('Z1', time() - 10, 'rZ1'));
        $before = (string) file_get_contents($this->authFilePath);

        // The throw is recorded and asserted AFTER the try: a fail() inside it
        // would be a RuntimeException-shaped AssertionFailedError this catch eats.
        $threw = false;
        try {
            $ocr->getValidAuth('https://z/mcp', 'https://as/token');
        } catch (\RuntimeException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a refused refresh must surface');

        $this->assertSame($before, (string) file_get_contents($this->authFilePath));
    }

    // =========================================================================
    // MCP-8: expires_in is optional (RFC 6749 §5.1) — unknown expiry, not a throw
    // =========================================================================

    public function testFetchTokenAcceptsAResponseWithoutExpiresIn(): void
    {
        $ocr = new OAuthClientRegistration($this->clientAnswering([
            new Response(200, [], (string) json_encode(['access_token' => 'cc-token'])),
        ]), $this->authFilePath);

        $token = $ocr->fetchToken('https://as/token', 'cid', 'sec');

        $this->assertSame('cc-token', $token['accessToken']);
        $this->assertNull($token['expiresIn'], 'an omitted expires_in is an unknown lifetime');
        $this->assertSame('', $token['refreshToken']);
    }

    public function testARefreshWithoutExpiresInIsStoredWithUnknownExpiry(): void
    {
        $ocr = new OAuthClientRegistration($this->clientAnswering([
            new Response(200, [], (string) json_encode(['access_token' => 'rotated', 'refresh_token' => 'rt2'])),
        ]), $this->authFilePath);
        $ocr->saveAuth('https://z/mcp', $this->storeEntry('Z1', time() - 10, 'rt1'));

        $served = $ocr->getValidAuth('https://z/mcp', 'https://as/token');

        $this->assertSame('rotated', $served?->accessToken);
        $this->assertNull($served->expiresAt, 'time() + null would have stored "expired now"');
        $this->assertNull($this->onDisk()['https://z/mcp']['expiresAt']);
        $this->assertSame('rt2', $this->onDisk()['https://z/mcp']['refreshToken']);
    }

    public function testTokenResponsesStillRequireAnAccessTokenAndANumericExpiry(): void
    {
        foreach (['{"expires_in":10}', '{"access_token":""}', '{"access_token":"a","expires_in":"soon"}'] as $body) {
            $ocr = new OAuthClientRegistration($this->clientAnswering([new Response(200, [], $body)]), $this->authFilePath);

            $caught = null;
            try {
                $ocr->fetchToken('https://as/token', 'cid', '');
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            $this->assertNotNull($caught, 'must reject: ' . $body);
            $this->assertStringContainsString('Token response', $caught->getMessage());
        }
    }

    public function testExpiresAtForKeepsAnUnknownExpiryUnknown(): void
    {
        $this->assertNull(OAuthClientRegistration::expiresAtFor(null));
        $this->assertSame(1060, OAuthClientRegistration::expiresAtFor(60, 1000));
    }

    public function testAddCommandStoresATokenWithoutExpiresInWithUnknownExpiry(): void
    {
        $serverUrl = 'https://add-noexp.example.com/mcp';
        $store = new McpAuthStore(new OAuthClientRegistration($this->clientAnswering([
            new Response(200, [], (string) json_encode(['client_id' => 'cid-n', 'registration_access_token' => 'reg-n'])),
            new Response(200, [], (string) json_encode(['access_token' => 'at-n'])),
        ]), $this->authFilePath));

        ob_start();
        $rc = (new McpAuthCommand($store))->execute(new Chat([]), ['add', $serverUrl, 'https://add-noexp.example.com/register', 'https://add-noexp.example.com/token']);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $rc, $output);
        $this->assertSame('at-n', $this->onDisk()[$serverUrl]['accessToken']);
        $this->assertNull($this->onDisk()[$serverUrl]['expiresAt']);
    }

    // =========================================================================
    // MCP-8: RFC 7592 registration updates go to registration_client_uri
    // =========================================================================

    public function testRegisterClientCapturesTheRegistrationClientUri(): void
    {
        $ocr = new OAuthClientRegistration($this->clientAnswering([
            new Response(201, [], (string) json_encode([
                'client_id' => 'cid',
                'registration_access_token' => 'rat',
                'registration_client_uri' => 'https://as/register/cid',
            ])),
            new Response(201, [], (string) json_encode(['client_id' => 'cid', 'registration_access_token' => 'rat'])),
        ]), $this->authFilePath);

        $this->assertSame('https://as/register/cid', $ocr->registerClient('https://as/register', 'n')['registrationClientUri']);
        $this->assertSame('', $ocr->registerClient('https://as/register', 'n')['registrationClientUri'], 'a server without RFC 7592 management yields no URI');
    }

    // =========================================================================
    // MCP-10: RFC 7591 §3.1 — the metadata is the request body, not an envelope
    // =========================================================================

    public function testRegisterClientSendsTheMetadataAtTheTopLevelOfTheBody(): void
    {
        $history = [];
        // A spec-following registration endpoint: it reads redirect_uris from
        // the top level and refuses the request when it finds none there.
        $endpoint = static function (\Psr\Http\Message\RequestInterface $request): Response {
            $body = json_decode((string) $request->getBody(), true);
            if (!\is_array($body) || !\is_array($body['redirect_uris'] ?? null)) {
                return new Response(400, [], (string) json_encode(['error' => 'invalid_redirect_uri']));
            }

            return new Response(201, [], (string) json_encode(['client_id' => 'cid-7591']));
        };
        $ocr = new OAuthClientRegistration($this->clientRecording([$endpoint], $history), $this->authFilePath);

        $registered = $ocr->registerClient('https://as/register', 'sugar-crush/as', ['read', 'write'], ['http://127.0.0.1:4567/callback']);

        $this->assertSame('cid-7591', $registered['clientId']);
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(['http://127.0.0.1:4567/callback'], $body['redirect_uris']);
        $this->assertSame('sugar-crush/as', $body['client_name']);
        $this->assertSame('read write', $body['scope']);
        $this->assertSame(['authorization_code', 'client_credentials'], $body['grant_types']);
        $this->assertArrayNotHasKey('client_metadata', $body, 'no envelope: RFC 7591 metadata members are the body itself');
    }

    public function testRegisterClientAcceptsAResponseWithoutRfc7592ManagementFields(): void
    {
        // RFC 7591 §3.2.1 requires only client_id; the registration access
        // token belongs to RFC 7592, which a server may not implement.
        $ocr = new OAuthClientRegistration($this->clientAnswering([
            new Response(201, [], (string) json_encode(['client_id' => 'cid-public'])),
        ]), $this->authFilePath);

        $this->assertSame(
            ['clientId' => 'cid-public', 'clientSecret' => '', 'registrationAccessToken' => '', 'registrationClientUri' => ''],
            $ocr->registerClient('https://as/register', 'n'),
        );
    }

    public function testRegisterClientStillReadsTheFullResponseShape(): void
    {
        $ocr = new OAuthClientRegistration($this->clientAnswering([
            new Response(201, [], (string) json_encode([
                'client_id' => 'cid-full',
                'client_secret' => 'sec-full',
                'registration_access_token' => 'rat-full',
                'registration_client_uri' => 'https://as/register/cid-full',
            ])),
        ]), $this->authFilePath);

        $this->assertSame(
            ['clientId' => 'cid-full', 'clientSecret' => 'sec-full', 'registrationAccessToken' => 'rat-full', 'registrationClientUri' => 'https://as/register/cid-full'],
            $ocr->registerClient('https://as/register', 'n'),
        );
    }

    public function testRegisterClientRefusesAResponseWithoutClientId(): void
    {
        $ocr = new OAuthClientRegistration($this->clientAnswering([
            new Response(201, [], (string) json_encode(['registration_access_token' => 'rat'])),
        ]), $this->authFilePath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('client_id');
        $ocr->registerClient('https://as/register', 'n');
    }

    public function testUpdateRegistrationPutsTheFullMetadataToTheClientUri(): void
    {
        $history = [];
        $ocr = new OAuthClientRegistration($this->clientRecording([
            new Response(200, [], (string) json_encode([
                'client_id' => 'cid',
                'client_secret' => 'sec-rotated',
                'registration_access_token' => 'rat-rotated',
                'registration_client_uri' => 'https://as/register/cid',
                'client_name' => 'renamed',
            ])),
        ], $history), $this->authFilePath);
        $ocr->saveAuth('https://z/mcp', $this->managedEntry());

        $updated = $ocr->updateRegistration('https://z/mcp', ['client_name' => 'renamed', 'redirect_uris' => ['http://127.0.0.1:1/callback']]);

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('https://as/register/cid', (string) $request->getUri(), 'RFC 7592 updates go to the per-client URI, not the registration endpoint');
        $this->assertSame('Bearer rat', $request->getHeaderLine('Authorization'));
        $this->assertSame(
            ['client_name' => 'renamed', 'redirect_uris' => ['http://127.0.0.1:1/callback'], 'client_id' => 'cid', 'client_secret' => 'sec'],
            json_decode((string) $request->getBody(), true),
            'the body carries the complete metadata plus the client credentials',
        );

        $this->assertSame('sec-rotated', $updated->clientSecret);
        $this->assertSame('rat-rotated', $updated->registrationAccessToken);
        $this->assertSame('live-token', $updated->accessToken, 'an update never touches the tokens');
        $this->assertSame('rat-rotated', $this->onDisk()['https://z/mcp']['registrationAccessToken']);
        $this->assertSame('live-token', $this->onDisk()['https://z/mcp']['accessToken']);
    }

    public function testUpdateRegistrationWithoutAClientUriSendsNothing(): void
    {
        $history = [];
        $ocr = new OAuthClientRegistration($this->clientRecording([], $history), $this->authFilePath);
        $ocr->saveAuth('https://z/mcp', $this->storeEntry('Z1', time() + 3600));

        $caught = null;
        try {
            $ocr->updateRegistration('https://z/mcp', ['client_name' => 'x']);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'no registration_client_uri means no RFC 7592 update');
        $this->assertStringContainsString('registration_client_uri', $caught->getMessage());

        $this->assertSame([], $history);
    }

    public function testUpdateRegistrationNamingAnotherClientStoresNothing(): void
    {
        $ocr = new OAuthClientRegistration($this->clientAnswering([
            new Response(200, [], (string) json_encode(['client_id' => 'someone-else', 'registration_access_token' => 'x'])),
        ]), $this->authFilePath);
        $ocr->saveAuth('https://z/mcp', $this->managedEntry());
        $before = (string) file_get_contents($this->authFilePath);

        // The throw is recorded and asserted AFTER the try: a fail() inside it
        // would be a RuntimeException-shaped AssertionFailedError this catch eats.
        $threw = false;
        try {
            $ocr->updateRegistration('https://z/mcp', ['client_name' => 'x']);
        } catch (\RuntimeException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a reply for another client id must be refused');

        $this->assertSame($before, (string) file_get_contents($this->authFilePath));
    }

    public function testALegacyRowWithoutRegistrationClientUriStillLoads(): void
    {
        file_put_contents($this->authFilePath, (string) json_encode(['https://old/mcp' => [
            'clientId' => 'c', 'clientSecret' => '', 'registrationAccessToken' => 'r', 'accessToken' => 'a',
            'refreshToken' => '', 'expiresAt' => null, 'scopes' => [], 'tokenUrl' => '', 'registrationUrl' => '',
        ]]));

        $entry = (new OAuthClientRegistration(null, $this->authFilePath))->loadAuth()['https://old/mcp'];

        $this->assertSame('', $entry->registrationClientUri);
        $this->assertSame('a', $entry->accessToken);
    }

    // =========================================================================
    // clearCache Tests
    // =========================================================================

    public function testClearCacheForcesReloadFromDisk(): void
    {
        $ocr = new OAuthClientRegistration(null, $this->authFilePath);

        $entry = new AuthEntry(
            clientId: 'c',
            clientSecret: '',
            registrationAccessToken: '',
            accessToken: '',
            refreshToken: '',
            expiresAt: time() + 3600,
            scopes: [],
        );

        $ocr->saveAuth('https://example.com/mcp', $entry);
        $ocr->clearCache();

        $data = $ocr->loadAuth();
        $this->assertArrayHasKey('https://example.com/mcp', $data);
    }

    // =========================================================================
    // E695: validAuthFor + persisted endpoints
    // =========================================================================

    public function testValidAuthForReturnsNullForUnknownServer(): void
    {
        $ocr = new OAuthClientRegistration(new Client(), $this->authFilePath);
        $this->assertNull($ocr->validAuthFor('https://never-registered.example.com/mcp'));
    }

    public function testValidAuthForServesFreshEntryWithoutTouchingTheNetwork(): void
    {
        // Empty MockHandler queue: ANY token-endpoint request trips
        // \OutOfBoundsException — the absence-of-network proof.
        $ocr = new OAuthClientRegistration(
            new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
            $this->authFilePath,
        );
        $ocr->saveAuth('https://fresh.example.com/mcp', new AuthEntry(
            clientId: 'c1',
            clientSecret: 's1',
            registrationAccessToken: 'r1',
            accessToken: 'fresh-access',
            refreshToken: 'rt1',
            expiresAt: time() + 3600,
            tokenUrl: 'https://fresh.example.com/token',
            registrationUrl: 'https://fresh.example.com/register',
        ));

        $entry = $ocr->validAuthFor('https://fresh.example.com/mcp');

        $this->assertNotNull($entry);
        $this->assertSame('fresh-access', $entry->accessToken);
    }

    public function testValidAuthForRefreshesExpiredEntryUsingPersistedEndpoints(): void
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 3600,
        ]))]);
        $ocr = new OAuthClientRegistration(
            new Client(['handler' => HandlerStack::create($mock)]),
            $this->authFilePath,
        );
        $ocr->saveAuth('https://rot.example.com/mcp', new AuthEntry(
            clientId: 'c2',
            clientSecret: 's2',
            registrationAccessToken: 'r2',
            accessToken: 'stale-access',
            refreshToken: 'old-refresh',
            expiresAt: time() - 10,
            tokenUrl: 'https://rot.example.com/token',
            registrationUrl: 'https://rot.example.com/register',
        ));

        $entry = $ocr->validAuthFor('https://rot.example.com/mcp');

        $this->assertSame('rotated-access', $entry->accessToken);
        $this->assertSame('https://rot.example.com/token', (string) $mock->getLastRequest()->getUri());

        $reloaded = (new OAuthClientRegistration(new Client(), $this->authFilePath))->loadAuth();
        $this->assertSame('rotated-access', $reloaded['https://rot.example.com/mcp']->accessToken);
        $this->assertSame('https://rot.example.com/token', $reloaded['https://rot.example.com/mcp']->tokenUrl);
    }

    public function testValidAuthForServesLegacyRowWithoutEndpointsAsIs(): void
    {
        $ocr = new OAuthClientRegistration(
            new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
            $this->authFilePath,
        );
        $ocr->saveAuth('https://legacy.example.com/mcp', new AuthEntry(
            clientId: 'c3',
            clientSecret: '',
            registrationAccessToken: 'r3',
            accessToken: 'legacy-access',
            refreshToken: '',
            expiresAt: time() - 10,
        ));

        $entry = $ocr->validAuthFor('https://legacy.example.com/mcp');

        $this->assertSame('legacy-access', $entry->accessToken);
    }

    public function testAuthEntryPersistsEndpointsThroughSaveAndLoad(): void
    {
        $ocr = new OAuthClientRegistration(new Client(), $this->authFilePath);
        $ocr->saveAuth('https://roundtrip.example.com/mcp', new AuthEntry(
            clientId: 'c4',
            clientSecret: 's4',
            registrationAccessToken: 'r4',
            accessToken: 'a4',
            refreshToken: 'rt4',
            expiresAt: null,
            tokenUrl: 'https://roundtrip.example.com/token',
            registrationUrl: 'https://roundtrip.example.com/reg',
        ));

        $reloaded = (new OAuthClientRegistration(new Client(), $this->authFilePath))->loadAuth();

        $this->assertSame('https://roundtrip.example.com/token', $reloaded['https://roundtrip.example.com/mcp']->tokenUrl);
        $this->assertSame('https://roundtrip.example.com/reg', $reloaded['https://roundtrip.example.com/mcp']->registrationUrl);
    }

    /**
     * r77-rv-la MINOR-1: `mcp auth add` must persist the endpoint URLs on the
     * stored entry. Before this pin the add-arm's `tokenUrl:`/`registrationUrl:`
     * lines could be deleted and every MCP test stayed green (mutation M3b),
     * shipping freshly-added entries that E695 can attach but can never
     * refresh — dead the moment the access token expires.
     *
     * Drives the REAL command: both endpoints arrive as explicit argv so the
     * well-known discovery leg is skipped and the MockHandler answers exactly
     * the register + token posts; the read-back uses a SEPARATE
     * OAuthClientRegistration so the assertion proves the on-disk entry, not
     * the store's in-memory cache.
     */
    public function testAddCommandPersistsRefreshEndpoints(): void
    {
        $serverUrl = 'https://add-arm.example.com/mcp';
        $registrationUrl = 'https://add-arm.example.com/register';
        $tokenUrl = 'https://add-arm.example.com/token';

        $httpClient = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'client_id' => 'cid-add',
                'client_secret' => 'secret-add',
                'registration_access_token' => 'reg-add',
                'registration_client_uri' => 'https://add-arm.example.com/register/cid-add',
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'at-add',
                'refresh_token' => 'rt-add',
                'expires_in' => 3600,
            ])),
        ]))]);

        $store = new McpAuthStore(new OAuthClientRegistration($httpClient, $this->authFilePath));

        ob_start();
        $rc = (new McpAuthCommand($store))->execute(new Chat([]), ['add', $serverUrl, $registrationUrl, $tokenUrl]);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $rc, $output);

        $persisted = (new OAuthClientRegistration(new Client(), $this->authFilePath))->loadAuth();

        $this->assertArrayHasKey($serverUrl, $persisted);
        $this->assertSame($tokenUrl, $persisted[$serverUrl]->tokenUrl);
        $this->assertSame($registrationUrl, $persisted[$serverUrl]->registrationUrl);
        $this->assertSame('at-add', $persisted[$serverUrl]->accessToken);
        $this->assertSame('https://add-arm.example.com/register/cid-add', $persisted[$serverUrl]->registrationClientUri, 'the RFC 7592 management URI is carried onto the row');
    }

    // =========================================================================
    // MCP-7: the store is shared between processes — locked merge, atomic write
    // =========================================================================

    /**
     * The audit repro. A long-lived TUI has the store memoized with only Y; a
     * second process (`sugarcrush mcp auth login x`) adds X; the TUI then
     * refreshes Y. The refresh used to write back the TUI's cached map plus
     * the new Y, erasing X.
     */
    public function testStaleCacheRefreshKeepsAnotherProcessesLogin(): void
    {
        $tui = new OAuthClientRegistration($this->clientAnswering([$this->tokenResponse('Y2', 'rY2')]), $this->authFilePath);
        $tui->saveAuth('https://y/mcp', $this->storeEntry('Y1', time() + 30, 'rY1'));

        $cli = new OAuthClientRegistration(null, $this->authFilePath);
        $cli->saveAuth('https://x/mcp', $this->storeEntry('X1', time() + 3600));

        $refreshed = $tui->getValidAuth('https://y/mcp', 'https://as/token');
        $this->assertSame('Y2', $refreshed?->accessToken);

        $this->assertSame(['https://y/mcp', 'https://x/mcp'], array_keys($this->onDisk()));
        $this->assertSame('X1', $this->onDisk()['https://x/mcp']['accessToken']);
        $this->assertSame('Y2', $this->onDisk()['https://y/mcp']['accessToken']);
    }

    /**
     * The race the merge has to win on its own, independently of the read-side
     * memo check: the other process's login lands WHILE the TUI's refresh
     * request is on the wire — after the TUI read the entry, before it writes.
     * Driven through validAuthFor(), the per-request path HttpMcpServer uses.
     */
    public function testLoginLandingDuringTheRefreshRoundTripSurvives(): void
    {
        $seed = new OAuthClientRegistration(null, $this->authFilePath);
        $seed->saveAuth('https://y/mcp', $this->storeEntry('Y1', time() + 30, 'rY1'));

        $tui = new OAuthClientRegistration($this->clientAnswering([
            function (): Response {
                (new OAuthClientRegistration(null, $this->authFilePath))
                    ->saveAuth('https://x/mcp', $this->storeEntry('X1', time() + 3600));

                return $this->tokenResponse('Y2', 'rY2');
            },
        ]), $this->authFilePath);

        $this->assertSame('Y2', $tui->validAuthFor('https://y/mcp')?->accessToken);

        $this->assertSame('X1', $this->onDisk()['https://x/mcp']['accessToken'] ?? null, 'the concurrent login was erased by the refresh write');
        $this->assertSame('Y2', $this->onDisk()['https://y/mcp']['accessToken']);

        // The memo is the merged disk state, not "the TUI's map plus its change".
        $memo = $tui->loadAuth();
        $this->assertSame(['https://y/mcp', 'https://x/mcp'], array_keys($memo));
    }

    public function testDeleteKeepsRowsAnotherProcessWrote(): void
    {
        $tui = new OAuthClientRegistration(null, $this->authFilePath);
        $tui->saveAuth('https://y/mcp', $this->storeEntry('Y1', time() + 3600));
        $tui->loadAuth();

        (new OAuthClientRegistration(null, $this->authFilePath))
            ->saveAuth('https://x/mcp', $this->storeEntry('X1', time() + 3600));

        (new McpAuthStore($tui))->removeServer('https://y/mcp');

        $this->assertSame(['https://x/mcp'], array_keys($this->onDisk()));
    }

    public function testLoadAuthSeesAWriteAnotherProcessPublished(): void
    {
        $tui = new OAuthClientRegistration(null, $this->authFilePath);
        $tui->saveAuth('https://y/mcp', $this->storeEntry('Y1', time() + 3600));
        $this->assertSame(['https://y/mcp'], array_keys($tui->loadAuth()));

        (new OAuthClientRegistration(null, $this->authFilePath))
            ->saveAuth('https://x/mcp', $this->storeEntry('X1', time() + 3600));

        $this->assertSame(['https://y/mcp', 'https://x/mcp'], array_keys($tui->loadAuth()));
    }

    /**
     * Untouched rows are carried through raw, so a field this build does not
     * know (written by a newer one) survives this build's write.
     */
    public function testSaveCarriesOtherRowsThroughVerbatim(): void
    {
        file_put_contents($this->authFilePath, (string) json_encode([
            'https://x/mcp' => ['clientId' => 'cx', 'accessToken' => 'X1', 'futureField' => 'kept'],
        ]));

        (new OAuthClientRegistration(null, $this->authFilePath))
            ->saveAuth('https://y/mcp', $this->storeEntry('Y1', time() + 3600));

        $this->assertSame(
            ['clientId' => 'cx', 'accessToken' => 'X1', 'futureField' => 'kept'],
            $this->onDisk()['https://x/mcp'],
        );
    }

    /**
     * A file that is not a JSON object used to become the merge base `[]`, so
     * the next save silently replaced every credential in it. The write now
     * refuses and leaves the bytes for the user to repair; the read stays
     * forgiving.
     */
    public function testSaveRefusesToOverwriteAnUnparseableFile(): void
    {
        file_put_contents($this->authFilePath, '{"https://x/mcp": {"accessToken": "X1"');
        $ocr = new OAuthClientRegistration(null, $this->authFilePath);

        $this->assertSame([], $ocr->loadAuth());

        $caught = null;
        try {
            $ocr->saveAuth('https://y/mcp', $this->storeEntry('Y1', time() + 3600));
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'a save over an unparseable auth file must refuse');
        $this->assertStringContainsString($this->authFilePath, $caught->getMessage());

        $this->assertSame('{"https://x/mcp": {"accessToken": "X1"', file_get_contents($this->authFilePath));
    }

    public function testDeleteOfAnAbsentServerWritesNothing(): void
    {
        (new OAuthClientRegistration(null, $this->authFilePath))->deleteAuth('https://never/mcp');

        $this->assertFileDoesNotExist($this->authFilePath);
    }

    /**
     * The published file is 0600 even under a permissive umask and even when
     * it replaces a looser pre-existing file — the mode rides the temp inode
     * through rename() instead of being chmod'ed after the bytes land. The lock
     * sidecar is owner-only too.
     */
    public function testWritesPublish0600UnderAPermissiveUmask(): void
    {
        file_put_contents($this->authFilePath, '{}');
        chmod($this->authFilePath, 0644);

        $umask = umask(0);
        try {
            (new OAuthClientRegistration(null, $this->authFilePath))
                ->saveAuth('https://y/mcp', $this->storeEntry('Y1', time() + 3600));
        } finally {
            umask($umask);
        }

        clearstatcache();
        $this->assertSame(0600, fileperms($this->authFilePath) & 0777);
        $this->assertSame(0600, fileperms($this->authFilePath . '.lock') & 0777);
    }

    private function storeEntry(string $token, ?int $expiresAt, string $refreshToken = ''): AuthEntry
    {
        return new AuthEntry('cid', '', 'rat', $token, $refreshToken, $expiresAt, [], 'https://as/token', 'https://as/register');
    }

    private function tokenResponse(string $accessToken, string $refreshToken): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => 3600,
        ]));
    }

    private function managedEntry(): AuthEntry
    {
        return new AuthEntry(
            clientId: 'cid',
            clientSecret: 'sec',
            registrationAccessToken: 'rat',
            accessToken: 'live-token',
            refreshToken: 'rt',
            expiresAt: time() + 3600,
            tokenUrl: 'https://as/token',
            registrationUrl: 'https://as/register',
            registrationClientUri: 'https://as/register/cid',
        );
    }

    /**
     * @param list<Response|callable> $queue
     * @param array<int, array<string, mixed>> $history
     */
    private function clientRecording(array $queue, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    /**
     * @param list<Response|callable> $queue
     */
    private function clientAnswering(array $queue): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($queue))]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function onDisk(): array
    {
        return json_decode((string) file_get_contents($this->authFilePath), true, 512, JSON_THROW_ON_ERROR);
    }
}
