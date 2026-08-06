<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\AuthEntry;
use SugarCraft\Crush\MCP\McpAuthStore;
use SugarCraft\Crush\MCP\OAuthClientRegistration;
use SugarCraft\Crush\MCP\ServerAuthStatus;

/**
 * @see McpAuthStore
 */
final class McpAuthStoreTest extends TestCase
{
    private string $tempDir;
    private string $authFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/mcp_auth_store_test_' . uniqid();
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

    private function createOAuthClient(): OAuthClientRegistration
    {
        return new OAuthClientRegistration(null, $this->authFilePath);
    }

    // =========================================================================
    // McpAuthStore::listServers() Tests
    // =========================================================================

    public function testListServersReturnsEmptyArrayWhenNoServersRegistered(): void
    {
        $store = new McpAuthStore($this->createOAuthClient());
        $this->assertSame([], $store->listServers());
    }

    public function testListServersReturnsAllRegisteredServers(): void
    {
        $oauth = $this->createOAuthClient();

        $entry1 = new AuthEntry(
            clientId: 'client-1',
            clientSecret: 'secret-1',
            registrationAccessToken: 'reg-token-1',
            accessToken: 'access-token-1',
            refreshToken: 'refresh-token-1',
            expiresAt: time() + 3600,
            scopes: ['read', 'write'],
        );

        $entry2 = new AuthEntry(
            clientId: 'client-2',
            clientSecret: 'secret-2',
            registrationAccessToken: 'reg-token-2',
            accessToken: 'access-token-2',
            refreshToken: 'refresh-token-2',
            expiresAt: time() + 7200,
            scopes: ['read'],
        );

        $oauth->saveAuth('https://server-a.example.com/mcp', $entry1);
        $oauth->saveAuth('https://server-b.example.com/mcp', $entry2);

        $store = new McpAuthStore($oauth);
        $servers = $store->listServers();

        $this->assertCount(2, $servers);
        $this->assertArrayHasKey('https://server-a.example.com/mcp', $servers);
        $this->assertArrayHasKey('https://server-b.example.com/mcp', $servers);
        $this->assertSame(['read', 'write'], $servers['https://server-a.example.com/mcp']->scopes);
        $this->assertSame(['read'], $servers['https://server-b.example.com/mcp']->scopes);
    }

    // =========================================================================
    // McpAuthStore::hasServer() Tests
    // =========================================================================

    public function testHasServerReturnsFalseForNonexistentServer(): void
    {
        $store = new McpAuthStore($this->createOAuthClient());
        $this->assertFalse($store->hasServer('https://nonexistent.example.com'));
    }

    public function testHasServerReturnsTrueForRegisteredServer(): void
    {
        $oauth = $this->createOAuthClient();

        $entry = new AuthEntry(
            clientId: 'client-1',
            clientSecret: 'secret-1',
            registrationAccessToken: 'reg-token-1',
            accessToken: 'access-token-1',
            refreshToken: 'refresh-token-1',
            expiresAt: time() + 3600,
            scopes: [],
        );

        $oauth->saveAuth('https://registered.example.com/mcp', $entry);

        $store = new McpAuthStore($oauth);
        $this->assertTrue($store->hasServer('https://registered.example.com/mcp'));
    }

    // =========================================================================
    // McpAuthStore::getServerStatus() Tests
    // =========================================================================

    public function testGetServerStatusReturnsNullForNonexistentServer(): void
    {
        $store = new McpAuthStore($this->createOAuthClient());
        $this->assertNull($store->getServerStatus('https://nonexistent.example.com'));
    }

    public function testGetServerStatusReturnsCorrectStatusForActiveServer(): void
    {
        $oauth = $this->createOAuthClient();
        $expiresAt = time() + 3600;

        $entry = new AuthEntry(
            clientId: 'client-1',
            clientSecret: 'secret-1',
            registrationAccessToken: 'reg-token-1',
            accessToken: 'access-token-1',
            refreshToken: 'refresh-token-1',
            expiresAt: $expiresAt,
            scopes: ['read', 'write'],
        );

        $oauth->saveAuth('https://active.example.com/mcp', $entry);

        $store = new McpAuthStore($oauth);
        $status = $store->getServerStatus('https://active.example.com/mcp');

        $this->assertNotNull($status);
        $this->assertSame('https://active.example.com/mcp', $status->serverUrl);
        $this->assertTrue($status->hasCredentials);
        $this->assertFalse($status->isExpired);
        $this->assertSame($expiresAt, $status->expiresAt);
        $this->assertSame(['read', 'write'], $status->scopes);
        $this->assertSame('active', $status->statusLabel());
    }

    public function testGetServerStatusReturnsExpiredStatusForExpiredToken(): void
    {
        $oauth = $this->createOAuthClient();
        $expiresAt = time() - 60; // already expired

        $entry = new AuthEntry(
            clientId: 'client-1',
            clientSecret: 'secret-1',
            registrationAccessToken: 'reg-token-1',
            accessToken: 'access-token-1',
            refreshToken: 'refresh-token-1',
            expiresAt: $expiresAt,
            scopes: [],
        );

        $oauth->saveAuth('https://expired.example.com/mcp', $entry);

        $store = new McpAuthStore($oauth);
        $status = $store->getServerStatus('https://expired.example.com/mcp');

        $this->assertNotNull($status);
        $this->assertTrue($status->isExpired);
        $this->assertSame('expired', $status->statusLabel());
    }

    public function testGetServerStatusReturnsExpiringSoonForTokensNearingExpiry(): void
    {
        $oauth = $this->createOAuthClient();
        // Within 5 minutes of expiry
        $expiresAt = time() + 120;

        $entry = new AuthEntry(
            clientId: 'client-1',
            clientSecret: 'secret-1',
            registrationAccessToken: 'reg-token-1',
            accessToken: 'access-token-1',
            refreshToken: 'refresh-token-1',
            expiresAt: $expiresAt,
            scopes: [],
        );

        $oauth->saveAuth('https://expiring.example.com/mcp', $entry);

        $store = new McpAuthStore($oauth);
        $status = $store->getServerStatus('https://expiring.example.com/mcp');

        $this->assertNotNull($status);
        $this->assertFalse($status->isExpired);
        $this->assertSame('expiring soon', $status->statusLabel());
    }

    // =========================================================================
    // McpAuthStore::removeServer() Tests
    // =========================================================================

    public function testRemoveServerDeletesStoredCredentials(): void
    {
        $oauth = $this->createOAuthClient();

        $entry = new AuthEntry(
            clientId: 'client-1',
            clientSecret: 'secret-1',
            registrationAccessToken: 'reg-token-1',
            accessToken: 'access-token-1',
            refreshToken: 'refresh-token-1',
            expiresAt: time() + 3600,
            scopes: [],
        );

        $oauth->saveAuth('https://to-remove.example.com/mcp', $entry);
        $store = new McpAuthStore($oauth);

        $this->assertTrue($store->hasServer('https://to-remove.example.com/mcp'));

        $store->removeServer('https://to-remove.example.com/mcp');

        $this->assertFalse($store->hasServer('https://to-remove.example.com/mcp'));
        $this->assertNull($store->getServerStatus('https://to-remove.example.com/mcp'));
    }

    public function testRemoveServerDoesNotAffectOtherServers(): void
    {
        $oauth = $this->createOAuthClient();

        $entry1 = new AuthEntry(
            clientId: 'client-1',
            clientSecret: 'secret-1',
            registrationAccessToken: 'reg-token-1',
            accessToken: 'access-token-1',
            refreshToken: 'refresh-token-1',
            expiresAt: time() + 3600,
            scopes: [],
        );

        $entry2 = new AuthEntry(
            clientId: 'client-2',
            clientSecret: 'secret-2',
            registrationAccessToken: 'reg-token-2',
            accessToken: 'access-token-2',
            refreshToken: 'refresh-token-2',
            expiresAt: time() + 3600,
            scopes: [],
        );

        $oauth->saveAuth('https://server-a.example.com/mcp', $entry1);
        $oauth->saveAuth('https://server-b.example.com/mcp', $entry2);

        $store = new McpAuthStore($oauth);
        $store->removeServer('https://server-a.example.com/mcp');

        $servers = $store->listServers();
        $this->assertCount(1, $servers);
        $this->assertArrayHasKey('https://server-b.example.com/mcp', $servers);
        $this->assertArrayNotHasKey('https://server-a.example.com/mcp', $servers);
    }

    public function testRemoveServerOnNonexistentServerDoesNotThrow(): void
    {
        $store = new McpAuthStore($this->createOAuthClient());
        $store->removeServer('https://nonexistent.example.com/mcp');
        $this->assertTrue(true); // No exception thrown
    }

    // =========================================================================
    // McpAuthStore::oauth() Tests
    // =========================================================================

    public function testOauthReturnsUnderlyingOAuthClient(): void
    {
        $oauth = $this->createOAuthClient();
        $store = new McpAuthStore($oauth);
        $this->assertSame($oauth, $store->oauth());
    }

    // =========================================================================
    // McpAuthStore::create() Tests
    // =========================================================================

    public function testCreateInstantiatesWithDefaultOAuthClient(): void
    {
        $store = McpAuthStore::create();
        $this->assertInstanceOf(McpAuthStore::class, $store);
        $this->assertInstanceOf(OAuthClientRegistration::class, $store->oauth());
    }

    // =========================================================================
    // ServerAuthStatus Tests
    // =========================================================================

    public function testServerAuthStatusStatusLabelActive(): void
    {
        $status = new ServerAuthStatus(
            serverUrl: 'https://example.com',
            hasCredentials: true,
            isExpired: false,
            expiresAt: time() + 3600,
            scopes: [],
        );

        $this->assertSame('active', $status->statusLabel());
    }

    public function testServerAuthStatusStatusLabelExpired(): void
    {
        $status = new ServerAuthStatus(
            serverUrl: 'https://example.com',
            hasCredentials: true,
            isExpired: true,
            expiresAt: time() - 60,
            scopes: [],
        );

        $this->assertSame('expired', $status->statusLabel());
    }

    public function testServerAuthStatusStatusLabelNoCredentials(): void
    {
        $status = new ServerAuthStatus(
            serverUrl: 'https://example.com',
            hasCredentials: false,
            isExpired: false,
            expiresAt: null,
            scopes: [],
        );

        $this->assertSame('no credentials', $status->statusLabel());
    }

    public function testServerAuthStatusToArray(): void
    {
        $expiresAt = time() + 3600;
        $status = new ServerAuthStatus(
            serverUrl: 'https://example.com',
            hasCredentials: true,
            isExpired: false,
            expiresAt: $expiresAt,
            scopes: ['read'],
        );

        $arr = $status->toArray();
        $this->assertSame('https://example.com', $arr['serverUrl']);
        $this->assertTrue($arr['hasCredentials']);
        $this->assertFalse($arr['isExpired']);
        $this->assertSame($expiresAt, $arr['expiresAt']);
        $this->assertSame(['read'], $arr['scopes']);
    }
}
