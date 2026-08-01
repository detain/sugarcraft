<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\SshConfigParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SshConfigParser private method branches.
 * Covers applyKeyword() match arms and expandPath() branches.
 */
final class SshConfigParserBranchesTest extends TestCase
{
    private function parse(string $raw): array
    {
        return (new SshConfigParser())->parse($raw);
    }

    // -----------------------------------------------------------------
    // applyKeyword() match arms
    // -----------------------------------------------------------------

    public function testApplyKeywordHostname(): void
    {
        // 'hostname' keyword sets hostname
        $raw = <<<CONF
Host example
    HostName example.com
CONF;
        $endpoints = $this->parse($raw);
        $this->assertSame('example.com', $endpoints[0]->host);
    }

    public function testApplyKeywordUser(): void
    {
        // 'user' keyword sets user
        $raw = <<<CONF
Host example
    HostName example.com
    User webuser
CONF;
        $endpoints = $this->parse($raw);
        $this->assertSame('webuser', $endpoints[0]->user);
    }

    public function testApplyKeywordPort(): void
    {
        // 'port' keyword sets port
        $raw = <<<CONF
Host example
    HostName example.com
    Port 2222
CONF;
        $endpoints = $this->parse($raw);
        $this->assertSame(2222, $endpoints[0]->port);
    }

    public function testApplyKeywordIdentityFile(): void
    {
        // 'identityfile' keyword appends to identityFiles list
        $raw = <<<CONF
Host example
    HostName example.com
    IdentityFile /etc/ssh/key1
    IdentityFile /etc/ssh/key2
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(2, $endpoints[0]->identityFiles);
        $this->assertSame('/etc/ssh/key1', $endpoints[0]->identityFiles[0]);
        $this->assertSame('/etc/ssh/key2', $endpoints[0]->identityFiles[1]);
    }

    public function testApplyKeywordProxyJump(): void
    {
        // 'proxyjump' keyword sets proxyJump
        $raw = <<<CONF
Host internal
    HostName internal.local
    ProxyJump bastion.example.com
CONF;
        $endpoints = $this->parse($raw);
        $this->assertSame('bastion.example.com', $endpoints[0]->proxyJump);
    }

    public function testApplyKeywordUnknownKeywordIgnored(): void
    {
        // Unknown keywords are ignored (no crash)
        $raw = <<<CONF
Host example
    HostName example.com
    UnknownKeyword somevalue
    ForwardAgent yes
CONF;
        $endpoints = $this->parse($raw);
        // Should parse successfully, ignoring ForwardAgent
        $this->assertCount(1, $endpoints);
        $this->assertSame('example.com', $endpoints[0]->host);
    }

    // -----------------------------------------------------------------
    // expandPath() branches
    // -----------------------------------------------------------------

    public function testExpandPathPlainPathUnchanged(): void
    {
        // Plain path (no tilde) is returned unchanged
        $raw = <<<CONF
Host example
    HostName example.com
    IdentityFile /etc/ssh/system_key
CONF;
        $endpoints = $this->parse($raw);
        $this->assertSame('/etc/ssh/system_key', $endpoints[0]->identityFiles[0]);
    }

    public function testExpandPathTildeSlash(): void
    {
        // ~/path expands to $HOME/path
        $raw = <<<CONF
Host example
    HostName example.com
    IdentityFile ~/ssh/key
CONF;
        $endpoints = $this->parse($raw);
        $expectedHome = getenv('HOME') ?? '/root';
        $this->assertSame($expectedHome . '/ssh/key', $endpoints[0]->identityFiles[0]);
    }

    public function testExpandPathTildeUserUnknown(): void
    {
        // ~unknownuser/path when user doesn't exist returns literal path
        $raw = <<<CONF
Host example
    HostName example.com
    IdentityFile ~nonexistentuser/.ssh/key
CONF;
        $endpoints = $this->parse($raw);
        // If posix_getpwnam fails, path is returned unchanged
        $this->assertSame('~nonexistentuser/.ssh/key', $endpoints[0]->identityFiles[0]);
    }

    public function testExpandPathTildeOnly(): void
    {
        // Just ~ (no slash) should not be expanded to home
        $raw = <<<CONF
Host example
    HostName example.com
    IdentityFile ~otheruser
CONF;
        $endpoints = $this->parse($raw);
        // ~otheruser without slash returns unchanged
        $this->assertSame('~otheruser', $endpoints[0]->identityFiles[0]);
    }

    public function testExpandPathTildeEmpty(): void
    {
        // Edge case: just ~ with nothing after (should not occur in practice)
        $raw = <<<CONF
Host example
    HostName example.com
    IdentityFile ~
CONF;
        $endpoints = $this->parse($raw);
        // Literal ~ returned
        $this->assertSame('~', $endpoints[0]->identityFiles[0]);
    }

    // -----------------------------------------------------------------
    // storeHostBlock() branches
    // -----------------------------------------------------------------

    public function testStoreHostBlockHostStarSetsGlobalOnly(): void
    {
        // Host * only sets global options, no endpoint created
        $raw = <<<CONF
Host *
    User globaluser
    Port 22022
CONF;
        $endpoints = $this->parse($raw);
        $this->assertSame([], $endpoints);
    }

    public function testStoreHostBlockHostStarAmongPatterns(): void
    {
        // Host * web — web should get global options
        $raw = <<<CONF
Host *
    User globaluser

Host web
    HostName web.example.com
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(1, $endpoints);
        $this->assertSame('globaluser', $endpoints[0]->user);
    }

    // -----------------------------------------------------------------
    // buildEndpoints() branches
    // -----------------------------------------------------------------

    public function testBuildEndpointsFiltersEmptyHost(): void
    {
        // Host with no HostName and pattern that results in empty host
        // should be filtered out
        $raw = <<<CONF
Host
CONF;
        $endpoints = $this->parse($raw);
        $this->assertSame([], $endpoints);
    }

    // -----------------------------------------------------------------
    // makeEndpoint() branches
    // -----------------------------------------------------------------

    public function testMakeEndpointUsesPatternWhenNoHostname(): void
    {
        // Host pattern becomes both name and host when no HostName
        $raw = <<<CONF
Host myserver
CONF;
        $endpoints = $this->parse($raw);
        $this->assertSame('myserver', $endpoints[0]->name);
        $this->assertSame('myserver', $endpoints[0]->host);
    }

    public function testMakeEndpointDefaultPort(): void
    {
        // Port defaults to 22
        $raw = <<<CONF
Host example
    HostName example.com
CONF;
        $endpoints = $this->parse($raw);
        $this->assertSame(22, $endpoints[0]->port);
    }

    public function testMakeEndpointNoUser(): void
    {
        // No User keyword means user is null
        $raw = <<<CONF
Host example
    HostName example.com
CONF;
        $endpoints = $this->parse($raw);
        $this->assertNull($endpoints[0]->user);
    }

    // -----------------------------------------------------------------
    // Case-insensitivity
    // -----------------------------------------------------------------

    public function testHostKeywordCaseInsensitive(): void
    {
        $raw = <<<CONF
HOST example
    HOSTNAME example.com
    USER testuser
    PORT 2222
CONF;
        $endpoints = $this->parse($raw);
        $this->assertSame('example', $endpoints[0]->name);
        $this->assertSame('example.com', $endpoints[0]->host);
        $this->assertSame('testuser', $endpoints[0]->user);
        $this->assertSame(2222, $endpoints[0]->port);
    }

    public function testOptionKeywordCaseInsensitive(): void
    {
        $raw = <<<CONF
Host example
    HOSTNAME example.com
    user webuser
    IdentityFile /tmp/key
CONF;
        $endpoints = $this->parse($raw);
        $this->assertSame('example.com', $endpoints[0]->host);
        $this->assertSame('webuser', $endpoints[0]->user);
    }

    // -----------------------------------------------------------------
    // Multi-pattern edge cases
    // -----------------------------------------------------------------

    public function testMultiPatternHostWithEmptyPattern(): void
    {
        // "Host a  b" (multiple spaces) should still work
        $raw = <<<CONF
Host a b
    HostName shared.example.com
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(2, $endpoints);
    }

    public function testKeywordValueWithExtraWhitespace(): void
    {
        $raw = <<<CONF
Host example
    HostName   example.com
    User    spaces
CONF;
        $endpoints = $this->parse($raw);
        $this->assertSame('example.com', $endpoints[0]->host);
        $this->assertSame('spaces', $endpoints[0]->user);
    }
}
