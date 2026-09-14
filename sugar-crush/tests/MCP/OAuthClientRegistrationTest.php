<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
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

    public function testGetValidAuthWithExpiredEntryAndEmptyRefreshTokenThrowsWithoutRegistrationUrl(): void
    {
        $ocr = new OAuthClientRegistration(null, $this->authFilePath);

        $entry = new AuthEntry(
            clientId: 'client-123',
            clientSecret: 'secret-abc',
            registrationAccessToken: 'reg-token-xyz',
            accessToken: 'expired-access-token',
            refreshToken: '', // empty — causes deadlock
            expiresAt: time() - 100,
            scopes: ['read'],
        );

        $ocr->saveAuth('https://example.com/mcp', $entry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired with no refresh token');

        $ocr->getValidAuth('https://example.com/mcp', 'https://example.com/token');
    }

    public function testGetValidAuthWithExpiredEntryAndEmptyRefreshTokenTriggersReRegistration(): void
    {
        // First response: PUT to registration endpoint (update)
        $updateResponse = new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'client_id' => 'new-client-id',
            'client_secret' => 'new-client-secret',
            'registration_access_token' => 'new-reg-token',
        ]));

        // Second response: token fetch
        $tokenResponse = new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 7200,
        ]));

        $mock = new MockHandler([$updateResponse, $tokenResponse]);
        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);

        $ocr = new OAuthClientRegistration($httpClient, $this->authFilePath);

        $entry = new AuthEntry(
            clientId: 'old-client-123',
            clientSecret: 'old-secret-abc',
            registrationAccessToken: 'old-reg-token-xyz',
            accessToken: 'expired-access-token',
            refreshToken: '', // empty — triggers re-registration
            expiresAt: time() - 100,
            scopes: ['read'],
        );

        $ocr->saveAuth('https://example.com/mcp', $entry);

        $result = $ocr->getValidAuth(
            'https://example.com/mcp',
            'https://example.com/token',
            'https://example.com/register',
        );

        $this->assertNotNull($result);
        $this->assertSame('new-client-id', $result->clientId);
        $this->assertSame('new-access-token', $result->accessToken);
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
    }
}
