<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\AuthEntry;
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
}
